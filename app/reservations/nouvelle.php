<?php
/**
 * FlotteCar — Nouvelle réservation
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
require_once BASE_PATH . '/models/ReservationModel.php';
requireTenantAuth();

if (!hasLocationModule()) {
    setFlash(FLASH_ERROR, 'Module Locations requis.');
    redirect(BASE_URL . 'app/dashboard.php');
}

$db       = (new Database())->getConnection();
$tenantId = getTenantId();

// ── Données selects ──────────────────────────────────────
$stVehs = $db->prepare("
    SELECT id, nom, immatriculation, marque, modele, prix_location_jour, statut
    FROM vehicules
    WHERE tenant_id=? AND type_vehicule='location'
    ORDER BY nom
");
$stVehs->execute([$tenantId]);
$vehicules = $stVehs->fetchAll(PDO::FETCH_ASSOC);

// Véhicule pré-sélectionné
$preselVehicule = (int)($_GET['vehicule_id'] ?? 0);
$preselDateDebut = $_GET['date_debut'] ?? '';

// Clients JSON pour recherche live
$stCli = $db->prepare("SELECT id, nom, prenom, telephone, email FROM clients WHERE tenant_id=? ORDER BY nom");
$stCli->execute([$tenantId]);
$clientsJson = array_map(fn($c) => [
    'id'    => $c['id'],
    'nom'   => $c['nom'].' '.($c['prenom']??''),
    'tel'   => $c['telephone']??'',
    'email' => $c['email']??'',
], $stCli->fetchAll(PDO::FETCH_ASSOC));

// Chauffeurs location
$stChauf = $db->prepare("SELECT id, nom, prenom FROM chauffeurs WHERE tenant_id=? AND type_chauffeur='location' AND statut='actif' ORDER BY nom");
$stChauf->execute([$tenantId]);
$chauffeurs = $stChauf->fetchAll(PDO::FETCH_ASSOC);

// Commerciaux
$stComm = $db->prepare("SELECT id, nom, prenom FROM commerciaux WHERE tenant_id=? AND statut='actif' ORDER BY nom");
$stComm->execute([$tenantId]);
$commerciaux = $stComm->fetchAll(PDO::FETCH_ASSOC);

// ── POST ─────────────────────────────────────────────────
$erreurs = [];
$old     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $old = $_POST;

    $vehiculeId    = (int)($_POST['vehicule_id']    ?? 0);
    $clientId      = (int)($_POST['client_id']      ?? 0);
    $commercialId  = (int)($_POST['commercial_id']  ?? 0) ?: null;
    $chauffeurId   = (int)($_POST['chauffeur_id']   ?? 0) ?: null;
    $dateDebut     = trim($_POST['date_debut']       ?? '');
    $dateFin       = trim($_POST['date_fin']         ?? '');
    $prixParJour   = cleanNumber($_POST['prix_par_jour'] ?? '0');
    $remise        = cleanNumber($_POST['remise']        ?? '0');
    $avance        = cleanNumber($_POST['avance']        ?? '0');
    $caution       = cleanNumber($_POST['caution']       ?? '0');
    $lieuDest      = trim($_POST['lieu_destination'] ?? '');
    $avecChauffeur = isset($_POST['avec_chauffeur']) ? 1 : 0;
    $canalAcq      = trim($_POST['canal_acquisition']?? '');
    $notes         = trim($_POST['notes']            ?? '');

    if (!$vehiculeId) $erreurs[] = 'Sélectionnez un véhicule.';
    if (!$clientId)   $erreurs[] = 'Sélectionnez un client.';
    if (!$dateDebut)  $erreurs[] = 'Date de début obligatoire.';
    if (!$dateFin)    $erreurs[] = 'Date de fin obligatoire.';
    if ($dateFin && $dateDebut && $dateFin < $dateDebut) $erreurs[] = 'Date fin doit être après date début.';

    if (empty($erreurs)) {
        // Vérifier chevauchement réservations
        $chk1 = $db->prepare("SELECT COUNT(*) FROM reservations WHERE vehicule_id=? AND tenant_id=? AND statut NOT IN('annulee','convertie') AND date_debut<=? AND date_fin>=?");
        $chk1->execute([$vehiculeId, $tenantId, $dateFin, $dateDebut]);
        if ((int)$chk1->fetchColumn() > 0) $erreurs[] = 'Ce véhicule est déjà réservé sur cette période.';

        // Vérifier chevauchement locations
        $chk2 = $db->prepare("SELECT COUNT(*) FROM locations WHERE vehicule_id=? AND tenant_id=? AND statut='en_cours' AND date_debut<=? AND date_fin>=?");
        $chk2->execute([$vehiculeId, $tenantId, $dateFin, $dateDebut]);
        if ((int)$chk2->fetchColumn() > 0) $erreurs[] = 'Ce véhicule a une location active sur cette période.';
    }

    if (empty($erreurs)) {
        $nbJours    = calculateDays($dateDebut, $dateFin);
        $montantTot = $nbJours * $prixParJour;
        $montantFin = max(0, $montantTot - $remise);

        $rsvModel = new ReservationModel($db);
        $rsvId = $rsvModel->create($tenantId, [
            'vehicule_id'       => $vehiculeId,
            'client_id'         => $clientId,
            'chauffeur_id'      => $chauffeurId,
            'commercial_id'     => $commercialId,
            'date_debut'        => $dateDebut,
            'date_fin'          => $dateFin,
            'nombre_jours'      => $nbJours,
            'prix_par_jour'     => $prixParJour,
            'montant_total'     => $montantTot,
            'remise'            => $remise,
            'montant_final'     => $montantFin,
            'caution'           => $caution,
            'avance'            => $avance,
            'mode_paiement'     => $_POST['mode_paiement'] ?? 'espece',
            'lieu_destination'  => $lieuDest,
            'avec_chauffeur'    => $avecChauffeur,
            'canal_acquisition' => $canalAcq,
            'notes'             => $notes,
        ]);

        logActivite($db, 'CREATE', 'reservations', "Réservation #$rsvId créée: véhicule #$vehiculeId du $dateDebut au $dateFin" . ($avance > 0 ? " - Avance: $avance FCFA" : ''));
        setFlash(FLASH_SUCCESS, 'Réservation créée.' . ($avance > 0 ? ' Avance de ' . number_format($avance, 0, ',', ' ') . ' FCFA enregistrée.' : ''));
        redirect(BASE_URL . 'app/reservations/detail.php?id=' . $rsvId);
    }
}

$pageTitle  = 'Nouvelle réservation';
$activePage = 'reservations';
require_once BASE_PATH . '/includes/header.php';

// Prix pré-sélectionné
$prixPresel = 0;
foreach ($vehicules as $v) {
    if ($v['id'] === $preselVehicule) { $prixPresel = (float)$v['prix_location_jour']; break; }
}
?>

<style>
.rsv-section{background:#fff;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:.75rem;overflow:hidden;}
.rsv-section-head{padding:8px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;background:#f8fafc;border-bottom:1px solid #e2e8f0;}
.rsv-section-body{padding:14px;}
.rsv-grid-3{display:grid;grid-template-columns:3fr 1fr 1fr;gap:.6rem;}
.rsv-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:.6rem;}
.rsv-grid-4{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:.6rem;}
@media(max-width:640px){.rsv-grid-3,.rsv-grid-4{grid-template-columns:1fr 1fr;}.rsv-grid-2{grid-template-columns:1fr;}}
/* Autocomplete client */
.cli-wrap{position:relative;}
.cli-dropdown{position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #d1d5db;border-top:none;border-radius:0 0 6px 6px;max-height:200px;overflow-y:auto;z-index:100;display:none;}
.cli-item{padding:7px 10px;font-size:13px;cursor:pointer;border-bottom:1px solid #f3f4f6;}
.cli-item:hover{background:#eff6ff;}
.cli-chip{background:#eff6ff;border:1px solid #bfdbfe;border-radius:5px;padding:4px 10px;font-size:12px;display:inline-flex;align-items:center;gap:6px;margin-top:4px;}
.cli-chip button{background:none;border:none;cursor:pointer;color:#6b7280;font-size:14px;line-height:1;padding:0;}
/* Résumé financier */
.fin-row{display:flex;justify-content:space-between;padding:4px 0;font-size:13px;border-bottom:1px solid #f3f4f6;}
.fin-row:last-child{border:none;font-weight:700;font-size:14px;}
.fin-row span:last-child{font-weight:600;}
/* Statut dispo */
.badge-dispo{background:#dcfce7;color:#15803d;}
.badge-indispo{background:#fee2e2;color:#b91c1c;}
</style>

<div class="page-header" style="padding-bottom:.5rem;">
    <div class="page-header-left">
        <a href="<?= BASE_URL ?>app/reservations/calendrier.php" class="btn btn-ghost btn-sm" style="margin-right:6px;"><i class="fas fa-arrow-left"></i></a>
        <div>
            <h2 class="page-title"><i class="fas fa-calendar-plus"></i> Nouvelle réservation</h2>
            <p class="page-subtitle">Réservation = date future, confirmée puis convertie en location</p>
        </div>
    </div>
</div>

<?= renderFlashes() ?>

<?php if ($erreurs): ?>
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;margin-bottom:.75rem;font-size:13px;color:#b91c1c;">
    <strong><i class="fas fa-exclamation-triangle"></i> Erreurs :</strong>
    <ul style="margin:4px 0 0 16px;padding:0;"><?php foreach($erreurs as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="POST" style="max-width:920px;" id="rsv-form">
    <?= csrfField() ?>

    <!-- ① Véhicule & Trajet -->
    <div class="rsv-section">
        <div class="rsv-section-head">① Véhicule & Destination</div>
        <div class="rsv-section-body">
            <div class="rsv-grid-3">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Véhicule <span style="color:red;">*</span></label>
                    <select name="vehicule_id" id="vehicule_id" class="form-control" required onchange="onVehChange(this)">
                        <option value="">— Choisir un véhicule —</option>
                        <?php foreach($vehicules as $v):
                            $dispo = in_array($v['statut'], ['disponible','loue']);
                        ?>
                        <option value="<?= $v['id'] ?>"
                                data-prix="<?= (float)$v['prix_location_jour'] ?>"
                                data-statut="<?= $v['statut'] ?>"
                                <?= ($old['vehicule_id']??$preselVehicule)==$v['id']?'selected':'' ?>>
                            <?= sanitize($v['immatriculation'].' — '.$v['nom']) ?>
                            (<?= formatMoney((float)$v['prix_location_jour']) ?>/j)
                            <?= $v['statut']!='disponible' ? ' ['.$v['statut'].']' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="veh-statut" style="margin-top:3px;font-size:11px;"></div>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Prix/jour (FCFA)</label>
                    <input type="number" name="prix_par_jour" id="prix_par_jour" class="form-control"
                           value="<?= (float)($old['prix_par_jour'] ?? $prixPresel) ?>"
                           min="0" step="500" oninput="calcMontant()">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Lieu / Destination</label>
                    <input type="text" name="lieu_destination" class="form-control"
                           value="<?= sanitize($old['lieu_destination'] ?? '') ?>" placeholder="Ex: Abidjan centre">
                </div>
            </div>
        </div>
    </div>

    <!-- ② Période -->
    <div class="rsv-section">
        <div class="rsv-section-head">② Période</div>
        <div class="rsv-section-body">
            <div class="rsv-grid-3">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Date début <span style="color:red;">*</span></label>
                    <input type="date" name="date_debut" id="date_debut" class="form-control"
                           value="<?= sanitize($old['date_debut'] ?? $preselDateDebut) ?>" required onchange="calcMontant()">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Date fin <span style="color:red;">*</span></label>
                    <input type="date" name="date_fin" id="date_fin" class="form-control"
                           value="<?= sanitize($old['date_fin'] ?? '') ?>" required onchange="calcMontant()">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Durée calculée</label>
                    <div class="form-control" id="duree-display" style="background:#f8fafc;color:#0d9488;font-weight:600;">— jours</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ③ Client -->
    <div class="rsv-section">
        <div class="rsv-section-head">③ Client</div>
        <div class="rsv-section-body">
            <input type="hidden" name="client_id" id="client_id" value="<?= (int)($old['client_id'] ?? 0) ?>" required>
            <div class="cli-wrap">
                <input type="text" id="client_search" class="form-control" placeholder="Rechercher par nom, téléphone, email…"
                       autocomplete="off" style="max-width:480px;">
                <div class="cli-dropdown" id="cli-dropdown"></div>
            </div>
            <div id="cli-chip-wrap" style="margin-top:6px;"></div>
            <a href="<?= BASE_URL ?>app/clients/ajouter.php" target="_blank" class="btn btn-ghost btn-sm" style="margin-top:6px;">
                <i class="fas fa-user-plus"></i> Nouveau client
            </a>
        </div>
    </div>

    <!-- ④ Accompagnement -->
    <div class="rsv-section">
        <div class="rsv-section-head">④ Accompagnement</div>
        <div class="rsv-section-body">
            <div class="rsv-grid-3">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Chauffeur (optionnel)</label>
                    <select name="chauffeur_id" class="form-control">
                        <option value="">— Sans chauffeur —</option>
                        <?php foreach($chauffeurs as $ch): ?>
                            <option value="<?= $ch['id'] ?>" <?= ($old['chauffeur_id']??'')==$ch['id']?'selected':'' ?>>
                                <?= sanitize($ch['nom'].' '.($ch['prenom']??'')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Commercial</label>
                    <select name="commercial_id" class="form-control">
                        <option value="">— Aucun —</option>
                        <?php foreach($commerciaux as $co): ?>
                            <option value="<?= $co['id'] ?>" <?= ($old['commercial_id']??'')==$co['id']?'selected':'' ?>>
                                <?= sanitize($co['nom'].' '.($co['prenom']??'')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Canal d'acquisition</label>
                    <select name="canal_acquisition" class="form-control">
                        <option value="">— Choisir —</option>
                        <?php foreach(['direct'=>'Direct','facebook'=>'Facebook','instagram'=>'Instagram','whatsapp'=>'WhatsApp','site_web'=>'Site web','recommandation'=>'Recommandation','autre'=>'Autre'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= ($old['canal_acquisition']??'')===$v?'selected':'' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="margin-top:.5rem;">
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" name="avec_chauffeur" value="1" <?= isset($old['avec_chauffeur'])?'checked':'' ?>>
                    Avec chauffeur fourni
                </label>
            </div>
        </div>
    </div>

    <!-- ⑤ Paiement & Résumé -->
    <div class="rsv-section">
        <div class="rsv-section-head">⑤ Paiement</div>
        <div class="rsv-section-body">
            <div class="rsv-grid-4" style="margin-bottom:.75rem;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Remise (FCFA)</label>
                    <input type="number" name="remise" id="remise" class="form-control" min="0" step="500"
                           value="<?= (float)($old['remise'] ?? 0) ?>" oninput="calcMontant()">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Caution (FCFA)</label>
                    <input type="number" name="caution" id="caution" class="form-control" min="0" step="500"
                           value="<?= (float)($old['caution'] ?? 0) ?>">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Avance (FCFA)</label>
                    <input type="number" name="avance" id="avance" class="form-control" min="0" step="500"
                           value="<?= (float)($old['avance'] ?? 0) ?>" oninput="calcMontant()">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Mode</label>
                    <select name="mode_paiement" class="form-control">
                        <option value="espece">Espèces</option>
                        <option value="mobile_money">Mobile Money</option>
                        <option value="virement">Virement</option>
                        <option value="cheque">Chèque</option>
                    </select>
                </div>
            </div>
            <!-- Résumé financier -->
            <div id="fin-resume" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 14px;max-width:360px;display:none;">
                <div class="fin-row"><span>Jours</span><span id="r-jours">—</span></div>
                <div class="fin-row"><span>Montant brut</span><span id="r-brut">—</span></div>
                <div class="fin-row" id="r-remise-row" style="color:#dc2626;display:none;"><span>Remise</span><span id="r-remise">—</span></div>
                <div class="fin-row" style="color:#0d9488;"><span>Total final</span><span id="r-final">—</span></div>
                <div class="fin-row" style="color:#16a34a;"><span>Avance</span><span id="r-avance">—</span></div>
                <div class="fin-row" style="color:#dc2626;"><span>Reste à payer</span><span id="r-reste">—</span></div>
            </div>
        </div>
    </div>

    <!-- ⑥ Notes -->
    <div class="rsv-section">
        <div class="rsv-section-head">⑥ Notes</div>
        <div class="rsv-section-body">
            <textarea name="notes" class="form-control" rows="2" placeholder="Observations, demandes spéciales…"><?= sanitize($old['notes'] ?? '') ?></textarea>
        </div>
    </div>

    <div class="form-actions">
        <a href="<?= BASE_URL ?>app/reservations/calendrier.php" class="btn btn-ghost">Annuler</a>
        <button type="submit" class="btn btn-primary"><i class="fas fa-calendar-check"></i> Créer la réservation</button>
    </div>
</form>

<script>
const CLI = <?= json_encode($clientsJson, JSON_UNESCAPED_UNICODE) ?>;
const VEH_PRIX = {};
<?php foreach($vehicules as $v): ?>
VEH_PRIX[<?= $v['id'] ?>] = <?= (float)$v['prix_location_jour'] ?>;
<?php endforeach; ?>

// ── Véhicule change ───────────────────────────────────────
function onVehChange(sel) {
    const opt = sel.selectedOptions[0];
    if (!opt.value) { document.getElementById('veh-statut').textContent=''; return; }
    const prix = parseFloat(opt.dataset.prix) || 0;
    const statut = opt.dataset.statut || '';
    document.getElementById('prix_par_jour').value = prix;
    const el = document.getElementById('veh-statut');
    if (statut === 'disponible') {
        el.innerHTML = '<span style="color:#16a34a;"><i class="fas fa-check-circle"></i> Disponible</span>';
    } else {
        el.innerHTML = '<span style="color:#f59e0b;"><i class="fas fa-exclamation-circle"></i> Statut: '+statut+' — La réservation est possible pour une date future</span>';
    }
    calcMontant();
}

// ── Calcul montant ────────────────────────────────────────
function calcMontant() {
    const debut  = document.getElementById('date_debut').value;
    const fin    = document.getElementById('date_fin').value;
    const prix   = parseFloat(document.getElementById('prix_par_jour').value) || 0;
    const remise = parseFloat(document.getElementById('remise').value) || 0;
    const avance = parseFloat(document.getElementById('avance').value) || 0;
    const resume = document.getElementById('fin-resume');

    if (!debut || !fin || debut >= fin) { resume.style.display='none'; document.getElementById('duree-display').textContent='— jours'; return; }

    const jours = Math.max(1, Math.round((new Date(fin)-new Date(debut))/86400000));
    const brut  = jours * prix;
    const final = Math.max(0, brut - remise);
    const reste = Math.max(0, final - avance);

    document.getElementById('duree-display').textContent = jours + ' jour' + (jours>1?'s':'');
    document.getElementById('r-jours').textContent  = jours + ' j × ' + fmt(prix) + ' FCFA';
    document.getElementById('r-brut').textContent   = fmt(brut) + ' FCFA';
    document.getElementById('r-final').textContent  = fmt(final) + ' FCFA';
    document.getElementById('r-avance').textContent = fmt(avance) + ' FCFA';
    document.getElementById('r-reste').textContent  = fmt(reste) + ' FCFA';

    const remiseRow = document.getElementById('r-remise-row');
    if (remise > 0) { remiseRow.style.display='flex'; document.getElementById('r-remise').textContent='- '+fmt(remise)+' FCFA'; }
    else { remiseRow.style.display='none'; }

    resume.style.display = 'block';
}
function fmt(n) { return Math.round(n).toLocaleString('fr-FR'); }

// ── Recherche client ──────────────────────────────────────
const searchEl   = document.getElementById('client_search');
const dropdown   = document.getElementById('cli-dropdown');
const hiddenId   = document.getElementById('client_id');
const chipWrap   = document.getElementById('cli-chip-wrap');

// Pré-remplir si reprise POST
<?php if (!empty($old['client_id'])): ?>
(function(){
    const found = CLI.find(c => c.id == <?= (int)$old['client_id'] ?>);
    if (found) selectClient(found);
})();
<?php endif; ?>

searchEl.addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    if (q.length < 2) { dropdown.style.display='none'; return; }
    const res = CLI.filter(c => c.nom.toLowerCase().includes(q) || c.tel.includes(q) || c.email.toLowerCase().includes(q)).slice(0,8);
    if (!res.length) { dropdown.innerHTML='<div class="cli-item" style="color:#9ca3af;">Aucun résultat</div>'; dropdown.style.display='block'; return; }
    dropdown.innerHTML = res.map(c => `<div class="cli-item" onclick="selectClient(${JSON.stringify(c).replace(/"/g,'&quot;')})">
        <strong>${c.nom}</strong> <span style="color:#6b7280;">${c.tel}</span>
    </div>`).join('');
    dropdown.style.display = 'block';
});

function selectClient(c) {
    hiddenId.value = c.id;
    searchEl.value = '';
    dropdown.style.display = 'none';
    chipWrap.innerHTML = `<div class="cli-chip"><i class="fas fa-user" style="color:#0d9488;"></i> <strong>${c.nom}</strong> ${c.tel} <button type="button" onclick="clearClient()">×</button></div>`;
    hiddenId.required = false;
}
function clearClient() { hiddenId.value=''; chipWrap.innerHTML=''; hiddenId.required=true; }

document.addEventListener('click', function(e) {
    if (!e.target.closest('.cli-wrap')) dropdown.style.display='none';
});

// ── Init ──────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('vehicule_id');
    if (sel.value) onVehChange(sel);
    calcMontant();
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
