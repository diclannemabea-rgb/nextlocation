<?php
/**
 * FlotteCar — Détail d'une location
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';

requireTenantAuth();

if (!hasLocationModule()) {
    setFlash('error', 'Accès non autorisé.');
    redirect(BASE_URL . 'app/dashboard.php');
}

$db       = (new Database())->getConnection();
$tenantId = getTenantId();

$locationId = (int)($_GET['id'] ?? 0);
if (!$locationId) {
    setFlash('error', 'ID invalide.');
    redirect(BASE_URL . 'app/locations/liste.php');
}

// ── Charger la location ──────────────────────────────────
function loadLoc(PDO $db, int $id, int $tid): ?array {
    $s = $db->prepare("
        SELECT l.*,
               v.nom AS veh_nom, v.immatriculation, v.marque, v.modele, v.couleur,
               c.nom AS client_nom, c.prenom AS client_prenom,
               c.telephone AS client_tel, c.email AS client_email,
               c.numero_piece AS client_cin, c.type_piece AS client_type_piece,
               CONCAT(COALESCE(ch.nom,''),' ',COALESCE(ch.prenom,'')) AS chauffeur_nom,
               ch.telephone AS chauffeur_tel,
               co.nom AS commercial_nom
        FROM locations l
        JOIN vehicules v ON v.id = l.vehicule_id
        JOIN clients   c ON c.id = l.client_id
        LEFT JOIN chauffeurs  ch ON ch.id = l.chauffeur_id
        LEFT JOIN commerciaux co ON co.id = l.commercial_id
        WHERE l.id = ? AND l.tenant_id = ?
    ");
    $s->execute([$id, $tid]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

$loc = loadLoc($db, $locationId, $tenantId);
if (!$loc) {
    setFlash('error', 'Location introuvable.');
    redirect(BASE_URL . 'app/locations/liste.php');
}

// ── Recalculer avance depuis paiements réels (corrige données corrompues) ──
$sSum = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM paiements WHERE location_id = ? AND tenant_id = ?");
$sSum->execute([$locationId, $tenantId]);
$totalPayeReel = (float)$sSum->fetchColumn();

if (abs($totalPayeReel - (float)$loc['avance']) > 0.01) {
    $montantFinal   = (float)$loc['montant_final'];
    $resteReel      = max(0, $montantFinal - $totalPayeReel);
    $statutPayReel  = $totalPayeReel >= $montantFinal ? 'solde' : ($totalPayeReel > 0 ? 'avance' : 'non_paye');
    $db->prepare("UPDATE locations SET avance=?, reste_a_payer=?, statut_paiement=? WHERE id=? AND tenant_id=?")
       ->execute([$totalPayeReel, $resteReel, $statutPayReel, $locationId, $tenantId]);
    $loc = loadLoc($db, $locationId, $tenantId);
}

// ── Traitement POST : paiement / prolongation ────────────
$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    // --- Enregistrer paiement ---
    if ($action === 'paiement') {
        $montantPay = (float)str_replace([' ',','],['','.'], $_POST['montant_paiement'] ?? '0');
        $modePay    = $_POST['mode_paiement'] ?? 'espece';
        $refPay     = trim($_POST['reference']      ?? '');
        $notesPay   = trim($_POST['notes_paiement'] ?? '');

        if ($montantPay <= 0) {
            $erreur = 'Le montant doit être > 0.';
        } elseif ($loc['statut_paiement'] === 'solde') {
            $erreur = 'Cette location est déjà soldée.';
        } else {
            $db->prepare("INSERT INTO paiements (tenant_id,location_id,montant,mode_paiement,reference,notes,created_at) VALUES (?,?,?,?,?,?,NOW())")
               ->execute([$tenantId, $locationId, $montantPay, $modePay, $refPay, $notesPay]);

            $sSum->execute([$locationId, $tenantId]);
            $total  = (float)$sSum->fetchColumn();
            $mFinal = (float)$loc['montant_final'];
            $reste  = max(0, $mFinal - $total);
            $sp     = $total >= $mFinal ? 'solde' : ($total > 0 ? 'avance' : 'non_paye');
            $db->prepare("UPDATE locations SET avance=?,reste_a_payer=?,statut_paiement=? WHERE id=? AND tenant_id=?")
               ->execute([$total, $reste, $sp, $locationId, $tenantId]);

            logActivite($db, 'PAYMENT', 'locations', formatMoney($montantPay) . " encaissé — location #$locationId");
            // ── Push notification ──────────────────────────────────────────
            $notifTitre = $sp === 'solde'
                ? "✅ Location #$locationId soldée !"
                : "💰 Paiement encaissé — Location #$locationId";
            $notifCorps = formatMoney($montantPay) . " reçu" . ($notesPay ? " ($notesPay)" : '') . ($sp === 'solde' ? ' · Compte soldé' : ' · Reste ' . formatMoney($reste));
            pushNotif($db, $tenantId, 'paiement', $notifTitre, $notifCorps, BASE_URL . "app/locations/detail.php?id=$locationId");
            setFlash('success', 'Paiement de ' . formatMoney($montantPay) . ' enregistré.');
            redirect(BASE_URL . 'app/locations/detail.php?id=' . $locationId);
        }
    }

    // --- Prolonger ---
    if ($action === 'prolonger') {
        $nouvFin  = trim($_POST['nouvelle_date_fin'] ?? '');
        $notesPro = trim($_POST['notes_prolongation'] ?? '');
        if (!$nouvFin || $nouvFin <= $loc['date_debut']) {
            $erreur = 'Date de fin invalide.';
        } elseif ($loc['statut'] !== 'en_cours') {
            $erreur = 'Seules les locations en cours peuvent être prolongées.';
        } else {
            $nbJours     = calculateDays($loc['date_debut'], $nouvFin);
            $nouvTotal   = $nbJours * (float)$loc['prix_par_jour'];
            $nouvFinal   = max(0, $nouvTotal - (float)$loc['remise']);
            $nouvReste   = max(0, $nouvFinal - (float)$loc['avance']);
            $nouvSP      = (float)$loc['avance'] >= $nouvFinal ? 'solde' : ((float)$loc['avance'] > 0 ? 'avance' : 'non_paye');

            $db->prepare("UPDATE locations SET date_fin=?,nombre_jours=?,montant_total=?,montant_final=?,reste_a_payer=?,statut_paiement=?,notes=CONCAT(COALESCE(notes,''),' | Prolongé: ',?) WHERE id=? AND tenant_id=?")
               ->execute([$nouvFin, $nbJours, $nouvTotal, $nouvFinal, $nouvReste, $nouvSP, $notesPro, $locationId, $tenantId]);

            logActivite($db, 'UPDATE', 'locations', "Prolongation jusqu'au $nouvFin — location #$locationId");
            setFlash('success', 'Location prolongée jusqu\'au ' . formatDate($nouvFin) . '.');
            redirect(BASE_URL . 'app/locations/detail.php?id=' . $locationId);
        }
    }
}

// Recharger après modifications
$loc = loadLoc($db, $locationId, $tenantId);

// ── Paiements ────────────────────────────────────────────
$stmtPay = $db->prepare("SELECT * FROM paiements WHERE location_id=? AND tenant_id=? ORDER BY created_at ASC");
$stmtPay->execute([$locationId, $tenantId]);
$paiements = $stmtPay->fetchAll(PDO::FETCH_ASSOC);

$enRetard = $loc['statut'] === 'en_cours' && strtotime($loc['date_fin']) < strtotime('today');

$pageTitle  = 'Location #' . $locationId;
$activePage = 'locations';
require_once BASE_PATH . '/includes/header.php';

$carburantL = ['vide'=>'Vide','quart'=>'1/4','moitie'=>'1/2','trois_quarts'=>'3/4','plein'=>'Plein'];
$modeL      = ['espece'=>'Espèces','mobile_money'=>'Mobile Money','virement'=>'Virement','cheque'=>'Chèque','carte'=>'Carte'];
$canalL     = ['direct'=>'Direct','facebook'=>'Facebook','instagram'=>'Instagram','whatsapp'=>'WhatsApp','site_web'=>'Site web','recommandation'=>'Recommandation','autre'=>'Autre'];
$pieceL     = ['cni'=>'CNI','passeport'=>'Passeport','permis'=>'Permis','carte_sejour'=>'Séjour','autre'=>'Autre'];
?>
<style>
.loc-grid{display:grid;grid-template-columns:1fr 1fr;gap:.85rem;}
@media(max-width:620px){.loc-grid{grid-template-columns:1fr;}}
.loc-panel{background:#fff;border:1px solid #e2e8f0;border-radius:8px;}
.loc-panel-head{padding:9px 14px;font-size:12px;font-weight:700;color:#374151;
    background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:6px;text-transform:uppercase;letter-spacing:.04em;}
.loc-panel-body{padding:10px 14px;}
.dl{display:grid;grid-template-columns:auto 1fr;gap:2px 10px;font-size:12.5px;}
.dl dt{color:#9ca3af;white-space:nowrap;padding:3px 0;}
.dl dd{font-weight:500;padding:3px 0;text-align:right;}
.kpi-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:.6rem;margin-bottom:.85rem;}
@media(max-width:580px){.kpi-strip{grid-template-columns:repeat(2,1fr);}}
.kpi-box{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;text-align:center;}
.kpi-box .v{font-size:1.05rem;font-weight:700;line-height:1.2;}
.kpi-box .l{font-size:10px;color:#6b7280;margin-top:1px;}
.pay-row{display:grid;grid-template-columns:auto 1fr auto auto auto;gap:6px 12px;
    align-items:center;padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:12.5px;}
.pay-row:last-child{border-bottom:none;}
.badge-sm{padding:2px 7px;border-radius:4px;font-size:11px;font-weight:600;}
.bg-ok{background:#dcfce7;color:#15803d;}
.bg-part{background:#fef3c7;color:#92400e;}
.bg-no{background:#fee2e2;color:#b91c1c;}
.retard-bar{background:#fef3c7;border:1px solid #fbbf24;border-radius:6px;
    padding:7px 12px;margin-bottom:.7rem;font-size:12px;color:#92400e;
    display:flex;align-items:center;gap:6px;}
.form-inline-grid{display:grid;grid-template-columns:1fr 1fr;gap:.6rem;}
@media(max-width:480px){.form-inline-grid{grid-template-columns:1fr;}}
</style>

<div class="page-header" style="padding-bottom:.5rem;">
    <div class="page-header-left">
        <a href="<?= BASE_URL ?>app/locations/liste.php" class="btn btn-ghost btn-sm" style="margin-right:6px;"><i class="fas fa-arrow-left"></i></a>
        <div>
            <h2 class="page-title" style="font-size:1.1rem;">
                <i class="fas fa-file-contract"></i> Location #<?= $locationId ?>
                <span style="font-weight:400;font-size:.85em;color:#6b7280;">— <?= sanitize($loc['immatriculation']) ?> <?= sanitize($loc['marque'].' '.$loc['modele']) ?></span>
            </h2>
            <p class="page-subtitle" style="margin:0;">
                <?= badgeLocation($loc['statut']) ?> <?= badgePaiement($loc['statut_paiement']) ?>
                <?php if($enRetard):?><span class="badge bg-danger" style="font-size:10px;">RETARD</span><?php endif;?>
            </p>
        </div>
    </div>
    <div style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:flex-start;">
        <?php if($loc['statut']==='en_cours'):?>
            <a href="<?= BASE_URL ?>app/locations/terminer.php?id=<?= $locationId ?>" class="btn btn-warning btn-sm"><i class="fas fa-flag-checkered"></i> Terminer</a>
        <?php endif;?>
        <a href="<?= BASE_URL ?>app/locations/contrat_pdf.php?id=<?= $locationId ?>" class="btn btn-outline-primary btn-sm" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
        <a href="<?= BASE_URL ?>app/locations/contrat.php?id=<?= $locationId ?>" target="_blank" class="btn btn-primary btn-sm"><i class="fas fa-file-word"></i> Télécharger contrat</a>
        <button onclick="window.print()" class="btn btn-ghost btn-sm"><i class="fas fa-print"></i></button>
    </div>
</div>

<?= renderFlashes() ?>

<?php if($enRetard):?>
<div class="retard-bar"><i class="fas fa-clock"></i>
    Retour prévu le <?= formatDate($loc['date_fin']) ?> — <strong><?= (int)floor((time()-strtotime($loc['date_fin']))/86400) ?> jour(s) de retard</strong>
</div>
<?php endif;?>

<!-- KPIs -->
<div class="kpi-strip">
    <div class="kpi-box">
        <div class="v" style="color:#0d9488;"><?= formatMoney((float)$loc['montant_final']) ?></div>
        <div class="l">Montant final</div>
    </div>
    <div class="kpi-box">
        <div class="v" style="color:#16a34a;"><?= formatMoney((float)$loc['avance']) ?></div>
        <div class="l">Total encaissé (<?= count($paiements) ?> paiement<?= count($paiements)>1?'s':'' ?>)</div>
    </div>
    <div class="kpi-box">
        <div class="v" style="color:<?= (float)$loc['reste_a_payer']>0?'#dc2626':'#16a34a';?>"><?= formatMoney((float)$loc['reste_a_payer']) ?></div>
        <div class="l">Reste à payer</div>
    </div>
    <div class="kpi-box">
        <div class="v"><?= (int)$loc['nombre_jours'] ?> j</div>
        <div class="l"><?= formatDate($loc['date_debut']) ?> → <?= formatDate($loc['date_fin']) ?></div>
    </div>
</div>

<!-- Grille infos -->
<div class="loc-grid" style="margin-bottom:.85rem;">

    <!-- Véhicule & Contrat -->
    <div class="loc-panel">
        <div class="loc-panel-head"><i class="fas fa-car" style="color:#0d9488;"></i> Contrat</div>
        <div class="loc-panel-body">
            <dl class="dl">
                <dt>Véhicule</dt><dd><?= sanitize($loc['immatriculation'].' — '.$loc['marque'].' '.$loc['modele']) ?></dd>
                <?php if($loc['couleur']):?><dt>Couleur</dt><dd><?= sanitize($loc['couleur']) ?></dd><?php endif;?>
                <dt>Période</dt><dd><?= formatDate($loc['date_debut']) ?> → <?= formatDate($loc['date_fin']) ?></dd>
                <dt>Durée</dt><dd><strong><?= (int)$loc['nombre_jours'] ?> jours</strong></dd>
                <dt>Prix/j</dt><dd><?= formatMoney((float)$loc['prix_par_jour']) ?></dd>
                <?php if((float)$loc['remise']>0):?><dt>Remise</dt><dd style="color:#dc2626;">− <?= formatMoney((float)$loc['remise']) ?></dd><?php endif;?>
                <dt>Total</dt><dd style="color:#0d9488;font-weight:700;"><?= formatMoney((float)$loc['montant_final']) ?></dd>
                <?php if($loc['km_depart']):?><dt>Km départ</dt><dd><?= number_format((int)$loc['km_depart'],0,',',' ') ?> km</dd><?php endif;?>
                <?php if($loc['statut']==='terminee'&&$loc['km_retour']):?>
                    <dt>Km retour</dt><dd><?= number_format((int)$loc['km_retour'],0,',',' ') ?> km</dd>
                    <dt>Parcourus</dt><dd><?= number_format((int)$loc['km_retour']-(int)$loc['km_depart'],0,',',' ') ?> km</dd>
                <?php endif;?>
                <?php if($loc['carburant_depart']):?><dt>Carburant ↑</dt><dd><?= $carburantL[$loc['carburant_depart']]??$loc['carburant_depart'] ?></dd><?php endif;?>
                <?php if($loc['statut']==='terminee'&&$loc['carburant_retour']):?><dt>Carburant ↓</dt><dd><?= $carburantL[$loc['carburant_retour']]??$loc['carburant_retour'] ?></dd><?php endif;?>
                <?php if($loc['lieu_destination']):?><dt>Destination</dt><dd><?= sanitize($loc['lieu_destination']) ?></dd><?php endif;?>
                <?php if($loc['canal_acquisition']):?><dt>Canal</dt><dd><?= $canalL[$loc['canal_acquisition']]??$loc['canal_acquisition'] ?></dd><?php endif;?>
                <?php if($loc['notes']):?><dt style="grid-column:1/-1;color:#9ca3af;padding-top:4px;">Notes : <?= nl2br(sanitize($loc['notes'])) ?></dt><?php endif;?>
            </dl>
        </div>
    </div>

    <!-- Client -->
    <div class="loc-panel">
        <div class="loc-panel-head"><i class="fas fa-user" style="color:#16a34a;"></i> Client</div>
        <div class="loc-panel-body">
            <dl class="dl">
                <dt>Nom</dt><dd><strong><?= sanitize($loc['client_nom'].' '.($loc['client_prenom']??'')) ?></strong></dd>
                <?php if($loc['client_tel']):?><dt>Tél</dt><dd><a href="tel:<?= sanitize($loc['client_tel']) ?>"><?= sanitize($loc['client_tel']) ?></a></dd><?php endif;?>
                <?php if($loc['client_email']):?><dt>Email</dt><dd><?= sanitize($loc['client_email']) ?></dd><?php endif;?>
                <?php if($loc['client_cin']):?>
                    <dt>Pièce</dt><dd><?= ($pieceL[$loc['client_type_piece']??'']??'') ?> <?= sanitize($loc['client_cin']) ?></dd>
                <?php endif;?>
                <?php if(trim($loc['chauffeur_nom']??'')):?>
                    <dt>Chauffeur</dt><dd><?= sanitize(trim($loc['chauffeur_nom'])) ?></dd>
                    <?php if($loc['chauffeur_tel']):?><dt>Tél chauf.</dt><dd><?= sanitize($loc['chauffeur_tel']) ?></dd><?php endif;?>
                <?php endif;?>
                <?php if($loc['commercial_nom']):?><dt>Commercial</dt><dd><?= sanitize($loc['commercial_nom']) ?></dd><?php endif;?>
                <!-- Paiement -->
                <dt style="padding-top:8px;border-top:1px solid #f3f4f6;margin-top:6px;">Caution</dt><dd style="padding-top:8px;border-top:1px solid #f3f4f6;margin-top:6px;"><?= formatMoney((float)$loc['caution']) ?></dd>
                <dt>Encaissé</dt><dd style="color:#16a34a;font-weight:700;"><?= formatMoney((float)$loc['avance']) ?></dd>
                <dt>Reste</dt><dd style="color:<?= (float)$loc['reste_a_payer']>0?'#dc2626':'#16a34a';?>;font-weight:700;"><?= formatMoney((float)$loc['reste_a_payer']) ?></dd>
                <dt>Statut</dt><dd><?= badgePaiement($loc['statut_paiement']) ?></dd>
                <?php if($loc['statut']==='terminee'&&isset($loc['statut_caution'])):?>
                    <dt>Caution</dt><dd><?= sanitize($loc['statut_caution']) ?></dd>
                <?php endif;?>
            </dl>
        </div>
    </div>
</div>

<!-- Historique paiements -->
<div class="loc-panel" style="margin-bottom:.85rem;">
    <div class="loc-panel-head" style="justify-content:space-between;">
        <span><i class="fas fa-receipt" style="color:#7c3aed;"></i> Paiements reçus</span>
        <span style="font-size:11px;font-weight:400;color:#6b7280;"><?= count($paiements) ?> paiement<?= count($paiements)>1?'s':'' ?> — Total : <?= formatMoney((float)$loc['avance']) ?></span>
    </div>
    <div class="loc-panel-body" style="padding:8px 14px;">
        <?php if(empty($paiements)):?>
            <p style="text-align:center;color:#9ca3af;font-size:12px;padding:12px 0;margin:0;">Aucun paiement enregistré</p>
        <?php else:?>
            <?php $cumul=0; foreach($paiements as $p): $cumul+=(float)$p['montant'];?>
            <div class="pay-row">
                <span style="color:#9ca3af;font-size:11px;white-space:nowrap;"><?= formatDatetime($p['created_at']) ?></span>
                <span style="color:#6b7280;font-size:12px;"><?= $modeL[$p['mode_paiement']]??sanitize($p['mode_paiement']??'—') ?><?= $p['reference']?' · '.sanitize($p['reference']):'' ?><?= $p['notes']?' — <em>'.sanitize($p['notes']).'</em>':'' ?></span>
                <span style="font-weight:700;color:#16a34a;white-space:nowrap;"><?= formatMoney((float)$p['montant']) ?></span>
                <span style="color:#9ca3af;font-size:11px;white-space:nowrap;">Cumul : <?= formatMoney($cumul) ?></span>
                <span style="font-size:11px;"></span>
            </div>
            <?php endforeach;?>
        <?php endif;?>
    </div>
</div>

<!-- Actions : Paiement + Prolongation -->
<?php if($loc['statut']==='en_cours' || $loc['statut_paiement']!=='solde'):?>
<div class="loc-grid">

    <?php if($loc['statut_paiement']!=='solde' && $loc['statut']!=='annulee'):?>
    <div class="loc-panel" style="border-color:#16a34a;">
        <div class="loc-panel-head" style="background:#f0fdf4;color:#15803d;"><i class="fas fa-plus-circle"></i> Enregistrer un paiement</div>
        <div class="loc-panel-body">
            <?php if($erreur):?><div style="background:#fee2e2;color:#b91c1c;border-radius:6px;padding:7px 10px;font-size:12px;margin-bottom:.6rem;"><?= sanitize($erreur) ?></div><?php endif;?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="paiement">
                <div class="form-inline-grid" style="margin-bottom:.5rem;">
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" style="font-size:12px;">Montant (FCFA) *</label>
                        <input type="number" name="montant_paiement" class="form-control"
                               min="1" step="1" value="<?= (float)$loc['reste_a_payer'] ?>" required>
                        <span style="font-size:11px;color:#6b7280;">Reste : <?= formatMoney((float)$loc['reste_a_payer']) ?></span>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" style="font-size:12px;">Mode</label>
                        <select name="mode_paiement" class="form-control">
                            <option value="espece">Espèces</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="virement">Virement</option>
                            <option value="cheque">Chèque</option>
                            <option value="carte">Carte</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" style="font-size:12px;">Référence</label>
                        <input type="text" name="reference" class="form-control" placeholder="N° reçu…">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" style="font-size:12px;">Note</label>
                        <input type="text" name="notes_paiement" class="form-control" placeholder="Observation…">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="width:100%;"><i class="fas fa-save"></i> Enregistrer le paiement</button>
            </form>
        </div>
    </div>
    <?php endif;?>

    <?php if($loc['statut']==='en_cours'):?>
    <div class="loc-panel" style="border-color:#f59e0b;">
        <div class="loc-panel-head" style="background:#fffbeb;color:#92400e;"><i class="fas fa-calendar-plus"></i> Prolonger la location</div>
        <div class="loc-panel-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="prolonger">
                <div class="form-group" style="margin-bottom:.5rem;">
                    <label class="form-label" style="font-size:12px;">Nouvelle date de fin *</label>
                    <input type="date" name="nouvelle_date_fin" class="form-control"
                           min="<?= $loc['date_fin'] ?>" value="<?= $loc['date_fin'] ?>" required>
                    <span style="font-size:11px;color:#6b7280;">Date actuelle : <?= formatDate($loc['date_fin']) ?></span>
                </div>
                <div class="form-group" style="margin-bottom:.5rem;">
                    <label class="form-label" style="font-size:12px;">Note de prolongation</label>
                    <input type="text" name="notes_prolongation" class="form-control" placeholder="Raison de la prolongation…">
                </div>
                <button type="submit" class="btn btn-warning btn-sm" style="width:100%;" onclick="return confirm('Prolonger cette location ?')">
                    <i class="fas fa-calendar-plus"></i> Prolonger
                </button>
            </form>
        </div>
    </div>
    <?php endif;?>

</div>
<?php endif;?>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
