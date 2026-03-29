<?php
/**
 * FlotteCar — Nouvelle location
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireTenantAuth();

if (!hasLocationModule()) {
    setFlash(FLASH_ERROR, 'Module Locations non disponible.');
    redirect(BASE_URL . 'app/dashboard.php');
}

$db       = (new Database())->getConnection();
$tenantId = getTenantId();

// ── Données selects ──────────────────────────────────────────────────────────
$stVeh = $db->prepare("
    SELECT id, nom, immatriculation, marque, modele, prix_location_jour, kilometrage_actuel, carburant_type
    FROM vehicules
    WHERE tenant_id = ? AND statut = 'disponible' AND type_vehicule = 'location'
    ORDER BY nom
");
$stVeh->execute([$tenantId]);
$vehs = $stVeh->fetchAll(PDO::FETCH_ASSOC);

$stCli = $db->prepare("SELECT id, nom, prenom, telephone, email FROM clients WHERE tenant_id = ? ORDER BY nom");
$stCli->execute([$tenantId]);
$clients = $stCli->fetchAll(PDO::FETCH_ASSOC);

$stCh = $db->prepare("
    SELECT id, nom, prenom FROM chauffeurs
    WHERE tenant_id = ? AND statut = 'actif' AND type_chauffeur = 'location'
    ORDER BY nom
");
$stCh->execute([$tenantId]);
$chauffeurs = $stCh->fetchAll(PDO::FETCH_ASSOC);

$stCom = $db->prepare("SELECT id, nom, prenom FROM commerciaux WHERE tenant_id = ? AND statut = 'actif' ORDER BY nom");
$stCom->execute([$tenantId]);
$commerciaux = $stCom->fetchAll(PDO::FETCH_ASSOC);

// ── POST ────────────────────────────────────────────────────────────────────
$erreurs = [];
$old     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $old = $_POST;

    $vehiculeId      = (int)($_POST['vehicule_id']    ?? 0);
    $clientId        = (int)($_POST['client_id']       ?? 0);
    $chauffeurId     = (int)($_POST['chauffeur_id']    ?? 0) ?: null;
    $commercialId    = (int)($_POST['commercial_id']   ?? 0) ?: null;
    $avecChauffeur   = isset($_POST['avec_chauffeur'])  ? 1 : 0;
    $lieuDest        = trim($_POST['lieu_destination'] ?? '');
    $dateDebut       = trim($_POST['date_debut']       ?? '');
    $dateFin         = trim($_POST['date_fin']         ?? '');
    $prixParJour     = cleanNumber($_POST['prix_par_jour'] ?? '0');
    $remise          = cleanNumber($_POST['remise']        ?? '0');
    $caution         = cleanNumber($_POST['caution']       ?? '0');
    $avance          = cleanNumber($_POST['avance']        ?? '0');
    $kmDepart        = (int)($_POST['km_depart']       ?? 0);
    $carburantDep    = trim($_POST['carburant_depart'] ?? '');
    $canalAcq        = trim($_POST['canal_acquisition']?? '');
    $modePaiement    = trim($_POST['mode_paiement']    ?? 'espece');
    $notes           = trim($_POST['notes']            ?? '');

    if (!$vehiculeId)      $erreurs[] = 'Sélectionnez un véhicule.';
    if (!$clientId)        $erreurs[] = 'Sélectionnez un client.';
    if (!$dateDebut)       $erreurs[] = 'Date de début obligatoire.';
    if (!$dateFin)         $erreurs[] = 'Date de fin obligatoire.';
    if ($prixParJour <= 0) $erreurs[] = 'Prix par jour invalide.';
    if ($dateFin && $dateDebut && $dateFin < $dateDebut) $erreurs[] = 'La date de fin doit être après le début.';

    if (empty($erreurs)) {
        $ck = $db->prepare("SELECT id FROM vehicules WHERE id=? AND tenant_id=? AND statut='disponible'");
        $ck->execute([$vehiculeId, $tenantId]);
        if (!$ck->fetch()) $erreurs[] = 'Véhicule indisponible.';
        $ck2 = $db->prepare("SELECT id FROM clients WHERE id=? AND tenant_id=?");
        $ck2->execute([$clientId, $tenantId]);
        if (!$ck2->fetch()) $erreurs[] = 'Client invalide.';
    }

    if (empty($erreurs)) {
        $nbj    = max(1, calculateDays($dateDebut, $dateFin));
        $tot    = $nbj * $prixParJour;
        $fin_   = max(0, $tot - $remise);
        $reste  = max(0, $fin_ - $avance);
        $sp     = $avance >= $fin_ ? PAIE_SOLDE : ($avance > 0 ? PAIE_AVANCE : PAIE_NON_PAYE);
        if ($avance >= $fin_) $reste = 0;

        try {
            $db->beginTransaction();
            $ins = $db->prepare("
                INSERT INTO locations
                    (tenant_id, vehicule_id, client_id, chauffeur_id, commercial_id,
                     type_location, avec_chauffeur, lieu_destination,
                     date_debut, date_fin, nombre_jours,
                     prix_par_jour, montant_total, remise, montant_final,
                     caution, avance, reste_a_payer, statut_paiement, `statut`,
                     km_depart, carburant_depart, canal_acquisition, mode_paiement, notes, created_at)
                VALUES (?,?,?,?,?, ?,?,?, ?,?,?, ?,?,?,?, ?,?,?,?,?, ?,?,?,?,?,NOW())
            ");
            $ins->execute([
                $tenantId,$vehiculeId,$clientId,$chauffeurId,$commercialId,
                $avecChauffeur ? 'avec_chauffeur' : 'standard',$avecChauffeur,$lieuDest ?: null,
                $dateDebut,$dateFin,$nbj,
                $prixParJour,$tot,$remise,$fin_,
                $caution,$avance,$reste,$sp,LOC_EN_COURS,
                $kmDepart,$carburantDep ?: null,$canalAcq ?: null,$modePaiement,$notes ?: null,
            ]);
            $lid = (int)$db->lastInsertId();
            $db->prepare("UPDATE vehicules SET statut='loue' WHERE id=? AND tenant_id=?")->execute([$vehiculeId,$tenantId]);
            if ($avance > 0) {
                // mode_paiement enum: espece|mobile_money|virement|cheque|carte
                $mpPay = $modePaiement === 'especes' ? 'espece' : $modePaiement;
                $db->prepare("INSERT INTO paiements (tenant_id,location_id,montant,mode_paiement,notes,created_at) VALUES (?,?,?,?,'Avance initiale',NOW())")
                   ->execute([$tenantId,$lid,$avance,$mpPay]);
            }
            $db->commit();
            logActivite($db,'create','locations',"Location #$lid créée");
            // ── Push notification ──────────────────────────────────────────
            $vehInfo = '';
            foreach ($vehs as $_v) { if ($_v['id'] === $vehiculeId) { $vehInfo = $_v['nom'] . ' ' . $_v['immatriculation']; break; } }
            $clientInfo = '';
            foreach ($clients as $_c) { if ($_c['id'] === $clientId) { $clientInfo = $_c['nom'] . ' ' . $_c['prenom']; break; } }
            pushNotif($db, $tenantId, 'location',
                "📋 Nouvelle location #$lid",
                "$vehInfo loué à $clientInfo — " . calculateDays($dateDebut, $dateFin) . " jour(s) — " . formatMoney($fin_),
                BASE_URL . "app/locations/detail.php?id=$lid"
            );
            setFlash(FLASH_SUCCESS,"Location #$lid créée avec succès.");
            redirect(BASE_URL . 'app/locations/detail.php?id=' . $lid);
        } catch (Exception $e) {
            $db->rollBack();
            $erreurs[] = 'Erreur : ' . $e->getMessage();
        }
    }
}

// JSON pour JS
$vehJson = [];
foreach ($vehs as $v)
    $vehJson[$v['id']] = ['prix'=>(float)$v['prix_location_jour'],'km'=>(int)$v['kilometrage_actuel'],'carb'=>$v['carburant_type']??''];

$cliJson = [];
foreach ($clients as $c)
    $cliJson[] = ['id'=>$c['id'],'nom'=>$c['nom'],'prenom'=>$c['prenom']??'','tel'=>$c['telephone']??'','email'=>$c['email']??''];

$pageTitle  = 'Nouvelle location';
$activePage = 'locations';
require_once BASE_PATH . '/includes/header.php';
?>

<style>
/* ── Compact form layout ────────────────────────────────────── */
.loc-page { max-width: 960px; }
.loc-section {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    margin-bottom: 12px;
    overflow: hidden;
}
.loc-section-head {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 14px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    font-size: .78rem;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: .06em;
}
.loc-section-head .num {
    width: 20px; height: 20px;
    background: #0d9488; color: #fff;
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: .68rem; font-weight: 800; flex-shrink: 0;
}
.loc-body { padding: 12px 14px; }
.loc-row {
    display: grid;
    gap: 10px;
    align-items: end;
    margin-bottom: 10px;
}
.loc-row:last-child { margin-bottom: 0; }
.loc-row.c2 { grid-template-columns: 1fr 1fr; }
.loc-row.c3 { grid-template-columns: 1fr 1fr 1fr; }
.loc-row.c4 { grid-template-columns: 1fr 1fr 1fr 1fr; }
.loc-row.c23 { grid-template-columns: 2fr 1fr 1fr; }
.loc-row.c32 { grid-template-columns: 3fr 1fr 1fr 1fr; }
.loc-row.c1  { grid-template-columns: 1fr; }
.lf label {
    display: block;
    font-size: .72rem;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 3px;
    white-space: nowrap;
}
.lf label .req { color: #ef4444; }
.lf .fc {
    width: 100%;
    height: 32px;
    padding: 0 9px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: .825rem;
    color: #0f172a;
    background: #fff;
    outline: none;
    transition: border-color .15s;
    box-sizing: border-box;
}
.lf textarea.fc { height: auto; padding: 7px 9px; }
.lf .fc:focus { border-color: #0d9488; box-shadow: 0 0 0 3px rgba(26,86,219,.08); }
.lf .fc.ro { background: #f8fafc; cursor: default; }
.lf .fc.primary-val { color: #0d9488; font-weight: 700; }

/* Client search */
.client-search-wrap { position: relative; }
.client-search-input { padding-right: 32px !important; }
.client-clear {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer; color: #94a3b8;
    font-size: .8rem; display: none;
}
.client-dropdown {
    position: absolute; top: 100%; left: 0; right: 0;
    background: #fff; border: 1px solid #d1d5db; border-top: none;
    border-radius: 0 0 6px 6px; max-height: 220px; overflow-y: auto;
    z-index: 200; display: none;
    box-shadow: 0 4px 12px rgba(0,0,0,.08);
}
.client-item {
    padding: 7px 10px; cursor: pointer; font-size: .82rem; border-bottom: 1px solid #f1f5f9;
    display: flex; justify-content: space-between; align-items: center;
}
.client-item:last-child { border-bottom: none; }
.client-item:hover { background: #f0f7ff; }
.client-item .ci-name { font-weight: 600; color: #0f172a; }
.client-item .ci-meta { font-size: .74rem; color: #94a3b8; }
.client-selected {
    display: none; align-items: center; gap: 8px;
    background: #f0f7ff; border: 1px solid #bfdbfe; border-radius: 6px;
    padding: 5px 10px; font-size: .82rem; margin-top: 4px;
}
.client-selected .cs-name { font-weight: 600; color: #0d9488; flex: 1; }
.client-selected .cs-tel  { color: #64748b; font-size: .75rem; }
.client-selected .cs-chg  { background: none; border: none; cursor: pointer; color: #94a3b8; font-size: .8rem; }

/* Résumé financier */
.resume-fin {
    display: grid; grid-template-columns: repeat(4,1fr); gap: 8px;
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;
    padding: 10px 12px; margin-top: 10px;
}
.rf-item { text-align: center; }
.rf-item .rf-l { font-size: .66rem; color: #94a3b8; text-transform: uppercase; letter-spacing: .05em; }
.rf-item .rf-v { font-size: .92rem; font-weight: 700; margin-top: 2px; }

/* Checkbox toggle */
.toggle-check {
    display: flex; align-items: center; gap: 7px;
    cursor: pointer; font-size: .82rem; font-weight: 500; color: #475569;
    padding: 5px 0;
}
.toggle-check input[type=checkbox] { width: 14px; height: 14px; cursor: pointer; }
</style>

<div class="loc-page">

<div class="page-header" style="margin-bottom:14px">
    <div>
        <a href="<?= BASE_URL ?>app/locations/liste.php" class="btn btn-secondary btn-sm" style="margin-bottom:5px">
            <i class="fas fa-arrow-left"></i> Locations
        </a>
        <h1 class="page-title" style="margin-bottom:2px">Nouvelle location</h1>
        <p class="page-subtitle" style="margin:0">Créer un contrat de location véhicule</p>
    </div>
</div>

<?php if (!empty($erreurs)): ?>
<div class="alert alert-error" style="margin-bottom:12px">
    <i class="fas fa-times-circle"></i>
    <?= implode(' · ', array_map('sanitize', $erreurs)) ?>
</div>
<?php endif ?>

<form method="POST" id="fLoc" autocomplete="off">
<?= csrfField() ?>

<!-- ══════════════════════════════════════════════════════════════════════
     SECTION 1 — VÉHICULE
════════════════════════════════════════════════════════════════════════ -->
<div class="loc-section">
    <div class="loc-section-head"><span class="num">1</span><i class="fas fa-car"></i> Véhicule & Trajet</div>
    <div class="loc-body">
        <div class="loc-row c32">
            <div class="lf">
                <label>Véhicule disponible <span class="req">*</span></label>
                <select name="vehicule_id" id="vehicule_id" class="fc" required>
                    <option value="">— Sélectionner un véhicule —</option>
                    <?php foreach ($vehs as $v): ?>
                    <option value="<?= $v['id'] ?>" <?= ($old['vehicule_id']??'') == $v['id'] ? 'selected':'' ?>>
                        <?= sanitize($v['nom'].' – '.$v['immatriculation'].' ('.$v['marque'].' '.$v['modele'].')') ?>
                        &nbsp;·&nbsp;<?= formatMoney((float)$v['prix_location_jour']) ?>/j
                    </option>
                    <?php endforeach ?>
                </select>
                <?php if (empty($vehs)): ?>
                <div style="font-size:.73rem;color:#f59e0b;margin-top:4px"><i class="fas fa-exclamation-triangle"></i> Aucun véhicule de location disponible.</div>
                <?php endif ?>
            </div>
            <div class="lf">
                <label>Prix / jour (FCFA) <span class="req">*</span></label>
                <input type="number" name="prix_par_jour" id="prix_par_jour" class="fc" step="500" min="0" value="<?= (int)($old['prix_par_jour']??0) ?>" required>
            </div>
            <div class="lf">
                <label>Km départ</label>
                <input type="number" name="km_depart" id="km_depart" class="fc" min="0" value="<?= (int)($old['km_depart']??0) ?>">
            </div>
            <div class="lf">
                <label>Carburant départ</label>
                <select name="carburant_depart" id="carburant_depart" class="fc">
                    <option value="">—</option>
                    <?php foreach (['vide'=>'Vide','quart'=>'1/4','moitie'=>'1/2','trois_quarts'=>'3/4','plein'=>'Plein'] as $val=>$lbl): ?>
                    <option value="<?= $val ?>" <?= ($old['carburant_depart']??'') === $val ? 'selected':'' ?>><?= $lbl ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </div>
        <div class="loc-row c2">
            <div class="lf">
                <label>Lieu de destination</label>
                <input type="text" name="lieu_destination" class="fc" placeholder="Ex: Yamoussoukro, Bouaké…" value="<?= sanitize($old['lieu_destination']??'') ?>">
            </div>
            <div class="lf">
                <label>Canal d'acquisition</label>
                <select name="canal_acquisition" class="fc">
                    <option value="">—</option>
                    <?php foreach (['direct'=>'Direct','facebook'=>'Facebook','instagram'=>'Instagram','whatsapp'=>'WhatsApp','site_web'=>'Site web','recommandation'=>'Recommandation','autre'=>'Via commercial / Autre'] as $val=>$lbl): ?>
                    <option value="<?= $val ?>" <?= ($old['canal_acquisition']??'') === $val ? 'selected':'' ?>><?= $lbl ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     SECTION 2 — PÉRIODE
════════════════════════════════════════════════════════════════════════ -->
<div class="loc-section">
    <div class="loc-section-head"><span class="num">2</span><i class="fas fa-calendar-alt"></i> Période de location</div>
    <div class="loc-body">
        <div class="loc-row c3">
            <div class="lf">
                <label>Date de début <span class="req">*</span></label>
                <input type="date" name="date_debut" id="date_debut" class="fc" value="<?= $old['date_debut']??date('Y-m-d') ?>" required>
            </div>
            <div class="lf">
                <label>Date de fin <span class="req">*</span></label>
                <input type="date" name="date_fin" id="date_fin" class="fc" value="<?= $old['date_fin']??'' ?>" required>
            </div>
            <div class="lf">
                <label>Durée calculée</label>
                <input type="text" id="nb_jours" class="fc ro primary-val" value="—" readonly>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     SECTION 3 — CLIENT
════════════════════════════════════════════════════════════════════════ -->
<div class="loc-section">
    <div class="loc-section-head" style="justify-content:space-between">
        <span style="display:flex;align-items:center;gap:8px"><span class="num">3</span><i class="fas fa-user"></i> Client</span>
        <a href="<?= BASE_URL ?>app/clients/ajouter.php" target="_blank" class="btn btn-ghost btn-sm" style="font-size:.72rem;padding:3px 8px">
            <i class="fas fa-user-plus"></i> Nouveau client
        </a>
    </div>
    <div class="loc-body">
        <input type="hidden" name="client_id" id="client_id" value="<?= (int)($old['client_id']??0) ?>">
        <div class="lf">
            <label>Recherche rapide <span class="req">*</span></label>
            <div class="client-search-wrap">
                <input type="text" id="client_search" class="fc client-search-input"
                       placeholder="Taper nom, prénom ou téléphone…" autocomplete="off">
                <button type="button" class="client-clear" id="client_clear" onclick="clearClient()">
                    <i class="fas fa-times"></i>
                </button>
                <div class="client-dropdown" id="client_dropdown"></div>
            </div>
            <div class="client-selected" id="client_selected">
                <i class="fas fa-check-circle" style="color:#10b981"></i>
                <span class="cs-name" id="cs_name"></span>
                <span class="cs-tel"  id="cs_tel"></span>
                <button type="button" class="cs-chg" onclick="clearClient()" title="Changer"><i class="fas fa-pencil"></i></button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     SECTION 4 — ACCOMPAGNEMENT
════════════════════════════════════════════════════════════════════════ -->
<div class="loc-section">
    <div class="loc-section-head"><span class="num">4</span><i class="fas fa-id-badge"></i> Accompagnement & Commercial</div>
    <div class="loc-body">
        <div class="loc-row c3" style="align-items:start">
            <div class="lf">
                <label>Avec chauffeur ?</label>
                <label class="toggle-check" style="height:32px">
                    <input type="checkbox" name="avec_chauffeur" id="avec_chauffeur" value="1"
                           <?= !empty($old['avec_chauffeur']) ? 'checked':'' ?>
                           onchange="toggleCh(this.checked)">
                    Location avec chauffeur fourni
                </label>
            </div>
            <div class="lf" id="sect_ch" style="display:<?= !empty($old['avec_chauffeur']) ? 'block':'none' ?>">
                <label>Chauffeur de location</label>
                <select name="chauffeur_id" class="fc">
                    <option value="">— Sélectionner —</option>
                    <?php foreach ($chauffeurs as $ch): ?>
                    <option value="<?= $ch['id'] ?>" <?= ($old['chauffeur_id']??'') == $ch['id'] ? 'selected':'' ?>>
                        <?= sanitize($ch['nom'].' '.($ch['prenom']??'')) ?>
                    </option>
                    <?php endforeach ?>
                    <?php if (empty($chauffeurs)): ?>
                    <option disabled>Aucun chauffeur de location actif</option>
                    <?php endif ?>
                </select>
            </div>
            <div class="lf">
                <label>Commercial apporteur</label>
                <select name="commercial_id" class="fc">
                    <option value="">— Aucun —</option>
                    <?php foreach ($commerciaux as $com): ?>
                    <option value="<?= $com['id'] ?>" <?= ($old['commercial_id']??'') == $com['id'] ? 'selected':'' ?>>
                        <?= sanitize($com['nom'].' '.($com['prenom']??'')) ?>
                    </option>
                    <?php endforeach ?>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     SECTION 5 — PAIEMENT
════════════════════════════════════════════════════════════════════════ -->
<div class="loc-section">
    <div class="loc-section-head"><span class="num">5</span><i class="fas fa-money-bill-wave"></i> Paiement</div>
    <div class="loc-body">
        <div class="loc-row c4">
            <div class="lf">
                <label>Remise (FCFA)</label>
                <input type="number" name="remise" id="remise" class="fc" min="0" step="500" value="<?= (int)($old['remise']??0) ?>">
            </div>
            <div class="lf">
                <label>Caution (FCFA)</label>
                <input type="number" name="caution" class="fc" min="0" step="1000" value="<?= (int)($old['caution']??0) ?>">
            </div>
            <div class="lf">
                <label>Avance perçue (FCFA)</label>
                <input type="number" name="avance" id="avance" class="fc" min="0" step="1000" value="<?= (int)($old['avance']??0) ?>">
            </div>
            <div class="lf">
                <label>Mode de paiement</label>
                <select name="mode_paiement" class="fc">
                    <?php foreach (['espece'=>'Espèces','mobile_money'=>'Mobile Money','virement'=>'Virement','cheque'=>'Chèque','carte'=>'Carte'] as $val=>$lbl): ?>
                    <option value="<?= $val ?>" <?= ($old['mode_paiement']??'especes') === $val ? 'selected':'' ?>><?= $lbl ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </div>

        <!-- RÉSUMÉ — après les inputs -->
        <div class="resume-fin" id="resume_fin">
            <div class="rf-item">
                <div class="rf-l">Jours × Prix</div>
                <div class="rf-v" id="rf_base" style="color:#0f172a">—</div>
            </div>
            <div class="rf-item">
                <div class="rf-l">Remise</div>
                <div class="rf-v" id="rf_remise" style="color:#f59e0b">— FCFA</div>
            </div>
            <div class="rf-item">
                <div class="rf-l">Montant final</div>
                <div class="rf-v" id="rf_final" style="color:#0d9488">— FCFA</div>
            </div>
            <div class="rf-item">
                <div class="rf-l">Reste à payer</div>
                <div class="rf-v" id="rf_reste">— FCFA</div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     NOTES
════════════════════════════════════════════════════════════════════════ -->
<div class="loc-section">
    <div class="loc-section-head"><span class="num" style="background:#94a3b8">6</span><i class="fas fa-sticky-note"></i> Notes (optionnel)</div>
    <div class="loc-body">
        <div class="lf">
            <textarea name="notes" class="fc" rows="2" placeholder="Observations, conditions particulières…"><?= sanitize($old['notes']??'') ?></textarea>
        </div>
    </div>
</div>

<!-- Actions -->
<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:4px;padding-bottom:24px">
    <a href="<?= BASE_URL ?>app/locations/liste.php" class="btn btn-secondary"><i class="fas fa-times"></i> Annuler</a>
    <button type="submit" class="btn btn-primary" style="min-width:160px"><i class="fas fa-file-contract"></i> Créer la location</button>
</div>

</form>
</div><!-- /loc-page -->

<script>
// ── Données JSON ─────────────────────────────────────────────
const VEH  = <?= json_encode($vehJson,  JSON_UNESCAPED_UNICODE) ?>;
const CLI  = <?= json_encode($cliJson,  JSON_UNESCAPED_UNICODE) ?>;

// ── Calcul montants ──────────────────────────────────────────
function fmt(n) { return Math.round(n).toLocaleString('fr-FR') + ' FCFA'; }

function recalc() {
    const debut  = document.getElementById('date_debut').value;
    const fin    = document.getElementById('date_fin').value;
    const prix   = parseFloat(document.getElementById('prix_par_jour').value) || 0;
    const remise = parseFloat(document.getElementById('remise').value) || 0;
    const avance = parseFloat(document.getElementById('avance').value) || 0;

    let jours = 0;
    if (debut && fin && fin >= debut)
        jours = Math.max(1, Math.round((new Date(fin) - new Date(debut)) / 86400000));

    const total  = jours * prix;
    const final_ = Math.max(0, total - remise);
    const reste  = Math.max(0, final_ - avance);

    document.getElementById('nb_jours').value = jours > 0 ? jours + ' jour' + (jours > 1 ? 's':'') : '—';
    document.getElementById('rf_base').textContent   = jours > 0 ? jours + ' j × ' + fmt(prix) : '—';
    document.getElementById('rf_remise').textContent = fmt(remise);
    document.getElementById('rf_final').textContent  = fmt(final_);
    const rEl = document.getElementById('rf_reste');
    rEl.textContent  = fmt(reste);
    rEl.style.color  = reste > 0 ? '#ef4444' : '#10b981';
}

document.getElementById('vehicule_id').addEventListener('change', function() {
    const d = VEH[this.value];
    if (d) {
        document.getElementById('prix_par_jour').value  = d.prix;
        document.getElementById('km_depart').value      = d.km;
        const cs = document.getElementById('carburant_depart');
        if (d.carb) cs.value = d.carb;
    }
    recalc();
});
['date_debut','date_fin','prix_par_jour','remise','avance'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', recalc);
});

function toggleCh(v) { document.getElementById('sect_ch').style.display = v ? 'block':'none'; }

// ── Client search ────────────────────────────────────────────
const searchEl    = document.getElementById('client_search');
const dropEl      = document.getElementById('client_dropdown');
const selectedEl  = document.getElementById('client_selected');
const clearBtn    = document.getElementById('client_clear');
const hiddenId    = document.getElementById('client_id');

// Pré-remplir si retour POST avec client_id
(function() {
    const pid = parseInt(hiddenId.value);
    if (pid) {
        const c = CLI.find(x => x.id === pid);
        if (c) showSelected(c);
    }
})();

searchEl.addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    clearBtn.style.display = q ? 'block':'none';
    if (!q) { dropEl.style.display = 'none'; return; }
    const res = CLI.filter(c =>
        (c.nom + ' ' + c.prenom).toLowerCase().includes(q) ||
        c.tel.toLowerCase().includes(q) ||
        c.email.toLowerCase().includes(q)
    ).slice(0, 8);
    if (!res.length) {
        dropEl.innerHTML = '<div class="client-item"><span class="ci-meta">Aucun résultat — <a href="<?= BASE_URL ?>app/clients/ajouter.php" target="_blank" style="color:#0d9488">Créer ce client</a></span></div>';
    } else {
        dropEl.innerHTML = res.map(c => `
            <div class="client-item" onclick="selectClient(${c.id},'${esc(c.nom)} ${esc(c.prenom)}','${esc(c.tel)}')">
                <div>
                    <span class="ci-name">${esc(c.nom)} ${esc(c.prenom)}</span>
                    ${c.tel ? `<span class="ci-meta"> · ${esc(c.tel)}</span>`:''}
                </div>
                ${c.email ? `<span class="ci-meta">${esc(c.email)}</span>`:''}
            </div>`).join('');
    }
    dropEl.style.display = 'block';
});

function esc(s) { return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function selectClient(id, nom, tel) {
    hiddenId.value = id;
    showSelected({id, nom:'', prenom:'', tel, email:''}, nom);
    dropEl.style.display = 'none';
    searchEl.value = '';
    clearBtn.style.display = 'none';
}

function showSelected(c, label) {
    const name = label || (c.nom + (c.prenom ? ' '+c.prenom:''));
    document.getElementById('cs_name').textContent = name;
    document.getElementById('cs_tel').textContent  = c.tel || '';
    selectedEl.style.display  = 'flex';
    searchEl.style.display    = 'none';
    clearBtn.style.display    = 'none';
}

function clearClient() {
    hiddenId.value = '';
    selectedEl.style.display  = 'none';
    searchEl.style.display    = 'block';
    searchEl.value = '';
    searchEl.focus();
    dropEl.style.display = 'none';
    clearBtn.style.display = 'none';
}

// Fermer dropdown si clic ailleurs
document.addEventListener('click', e => {
    if (!e.target.closest('.client-search-wrap')) dropEl.style.display = 'none';
});

recalc();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
