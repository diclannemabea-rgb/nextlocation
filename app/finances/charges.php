<?php
/**
 * FlotteCar — Charges & Maintenances
 * Gestion unifiée : dépenses opérationnelles + maintenances programmées (km + périodiques)
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireTenantAuth();

$db       = (new Database())->getConnection();
$tenantId = getTenantId();
$today    = date('Y-m-d');
$in30     = date('Y-m-d', strtotime('+30 days'));

// ── Types ──────────────────────────────────────────────────────────────────────
$typesCharges = [
    'carburant'   => ['label'=>'Carburant',    'icon'=>'fa-gas-pump',             'color'=>'#d97706','bg'=>'#fef3c7'],
    'assurance'   => ['label'=>'Assurance',    'icon'=>'fa-shield-halved',        'color'=>'#0d9488','bg'=>'#dbeafe', 'doc'=>true],
    'vignette'    => ['label'=>'Vignette',     'icon'=>'fa-stamp',                'color'=>'#7c3aed','bg'=>'#ede9fe', 'doc'=>true],
    'reparation'  => ['label'=>'Réparation',   'icon'=>'fa-screwdriver-wrench',   'color'=>'#ef4444','bg'=>'#fee2e2'],
    'maintenance' => ['label'=>'Maintenance',  'icon'=>'fa-wrench',               'color'=>'#dc2626','bg'=>'#fee2e2'],
    'nettoyage'   => ['label'=>'Nettoyage',    'icon'=>'fa-spray-can-sparkles',   'color'=>'#0891b2','bg'=>'#cffafe'],
    'amende'      => ['label'=>'Amende',       'icon'=>'fa-triangle-exclamation', 'color'=>'#9f1239','bg'=>'#ffe4e6'],
    'autre'       => ['label'=>'Autre',        'icon'=>'fa-circle-dot',           'color'=>'#64748b','bg'=>'#f1f5f9'],
];
// Types accessibles en ajout manuel (maintenance = auto-généré depuis onglet maintenances)
$typesChargesManuel = array_diff_key($typesCharges, ['maintenance'=>null]);

$typesMaint = [
    'vidange'      => ['label'=>'Vidange',          'icon'=>'fa-oil-can',          'km'=>true],
    'revision'     => ['label'=>'Révision générale','icon'=>'fa-car-side',         'km'=>true],
    'pneus'        => ['label'=>'Pneus',            'icon'=>'fa-circle-dot',       'km'=>true],
    'freins'       => ['label'=>'Freins',           'icon'=>'fa-drum',             'km'=>true],
    'courroie'     => ['label'=>'Courroie',         'icon'=>'fa-gear',             'km'=>true],
    'climatisation'=> ['label'=>'Climatisation',    'icon'=>'fa-snowflake',        'km'=>false],
    'carrosserie'  => ['label'=>'Carrosserie',      'icon'=>'fa-car-burst',        'km'=>false],
    'electrique'   => ['label'=>'Électrique',       'icon'=>'fa-bolt',             'km'=>false],
    'visite_tech'  => ['label'=>'Visite technique', 'icon'=>'fa-clipboard-check',  'km'=>false],
    'autre'        => ['label'=>'Autre',            'icon'=>'fa-wrench',           'km'=>false],
];

// ── POST ───────────────────────────────────────────────────────────────────────
$filtreVehicule = (int)($_GET['vehicule_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action   = $_POST['action'] ?? '';
    $postVid  = (int)($_POST['vehicule_id_filter'] ?? 0);
    $redirBase = BASE_URL . 'app/finances/charges.php';
    $redir     = $redirBase . ($postVid ? "?vehicule_id=$postVid" : '?');
    // Helper pour ajouter un paramètre à la redirection
    $redirTab = fn(string $tab) => $redirBase . ($postVid ? "?vehicule_id=$postVid&tab=$tab" : "?tab=$tab");

    // ── Ajouter charge
    if ($action === 'ajouter_charge') {
        $vId   = (int)($_POST['vehicule_id'] ?? 0);
        $type  = isset($typesChargesManuel[$_POST['type']??'']) ? $_POST['type'] : 'autre';
        $lib   = trim($_POST['libelle'] ?? '');
        $mont  = (float)str_replace([' ',','],['',' '.'.'], $_POST['montant'] ?? '0');
        $mont  = (float)str_replace([' ', ','], ['', '.'], $_POST['montant'] ?? '0');
        $date  = $_POST['date_charge'] ?? $today;
        $notes = trim($_POST['notes'] ?? '');
        $expiry= trim($_POST['date_expiration'] ?? '');

        if (!$vId || !$lib || $mont <= 0) {
            setFlash(FLASH_ERROR, 'Véhicule, libellé et montant sont requis.');
        } else {
            $pj = null;
            if (!empty($_FILES['piece_jointe']['name'])) {
                $pj = uploadFile($_FILES['piece_jointe'], BASE_PATH . '/uploads/charges/', ['pdf','jpg','jpeg','png']);
            }
            $db->prepare("INSERT INTO charges (tenant_id,vehicule_id,type,libelle,montant,date_charge,notes,piece_jointe) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$tenantId, $vId, $type, $lib, $mont, $date, $notes ?: null, $pj]);

            // Dépenses calculées dynamiquement via SUM(charges) — plus de cumul dans depenses_initiales

            // Mise à jour date expiration document
            if ($expiry && in_array($type, ['assurance','vignette'])) {
                $col = $type === 'assurance' ? 'date_expiration_assurance' : 'date_expiration_vignette';
                $db->prepare("UPDATE vehicules SET $col=? WHERE id=? AND tenant_id=?")->execute([$expiry, $vId, $tenantId]);
            }
            logActivite($db, 'CREATE', 'charges', "Charge $type: $lib — $mont FCFA → dépenses véhicule #$vId mises à jour");
            setFlash(FLASH_SUCCESS, 'Charge enregistrée. Dépenses du véhicule mises à jour.');
        }
        redirect($redir);
    }

    // ── Supprimer charge
    if ($action === 'supprimer_charge') {
        $id = (int)($_POST['id'] ?? 0);
        $s = $db->prepare("SELECT vehicule_id, montant, piece_jointe FROM charges WHERE id=? AND tenant_id=?");
        $s->execute([$id, $tenantId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($row['piece_jointe']) @unlink(BASE_PATH . '/uploads/charges/' . $row['piece_jointe']);
            $db->prepare("DELETE FROM charges WHERE id=? AND tenant_id=?")->execute([$id, $tenantId]);
            // ── Retrancher des dépenses du véhicule ────────────────────────
            if ($row['vehicule_id'] && $row['montant'] > 0) {
                $db->prepare("UPDATE vehicules SET depenses_initiales = GREATEST(0, COALESCE(depenses_initiales,0) - ?) WHERE id=? AND tenant_id=?")
                   ->execute([$row['montant'], $row['vehicule_id'], $tenantId]);
            }
        }
        setFlash(FLASH_SUCCESS, 'Charge supprimée. Dépenses du véhicule recalculées.');
        redirect($redir);
    }

    // ── Ajouter maintenance
    if ($action === 'ajouter_maintenance') {
        $vId     = (int)($_POST['vehicule_id'] ?? 0);
        $type    = $_POST['type_maint'] ?? 'vidange';
        $desc    = trim($_POST['description'] ?? '');
        $kmPrevu = !empty($_POST['km_prevu']) ? (int)$_POST['km_prevu'] : null;
        $datePrev= !empty($_POST['date_prevue']) ? $_POST['date_prevue'] : null;
        $cout    = !empty($_POST['cout_estime']) ? (float)$_POST['cout_estime'] : null;
        $tech    = trim($_POST['technicien'] ?? '');
        $modeDirect = ($_POST['mode_maint'] ?? '') === 'direct';

        if (!$vId) {
            setFlash(FLASH_ERROR, 'Véhicule requis.');
        } elseif (!$modeDirect && !$kmPrevu && !$datePrev) {
            setFlash(FLASH_ERROR, 'Véhicule et au moins un déclencheur (km ou date) sont requis.');
        } else {
            if ($modeDirect) {
                // Mode "Effectuer maintenant" — insérer directement comme fait
                $kmFait  = !empty($_POST['km_fait_direct']) ? (int)$_POST['km_fait_direct'] : null;
                $coutReel= !empty($_POST['cout_direct'])    ? (float)$_POST['cout_direct']  : $cout;
                $lbl     = ($typesMaint[$type]['label'] ?? ucfirst($type)) . ($desc ? " — $desc" : '');

                $db->prepare("INSERT INTO maintenances (tenant_id,vehicule_id,type,notes,km_fait,cout,technicien,statut,date_prevue) VALUES (?,?,?,?,?,?,?,'fait',CURDATE())")
                   ->execute([$tenantId, $vId, $type, $desc ?: null, $kmFait, $coutReel, $tech ?: null]);

                if ($coutReel && $coutReel > 0) {
                    $db->prepare("INSERT INTO charges (tenant_id,vehicule_id,type,libelle,montant,date_charge) VALUES (?,?,'maintenance',?,?,CURDATE())")
                       ->execute([$tenantId, $vId, $lbl, $coutReel]);
                }
                if ($kmFait) {
                    $db->prepare("UPDATE vehicules SET kilometrage_actuel=? WHERE id=? AND tenant_id=? AND kilometrage_actuel < ?")
                       ->execute([$kmFait, $vId, $tenantId, $kmFait]);
                }
                logActivite($db, 'CREATE', 'maintenances', "Maintenance $type effectuée directement — véhicule #$vId");
                setFlash(FLASH_SUCCESS, 'Maintenance enregistrée comme effectuée.');
            } else {
                $db->prepare("INSERT INTO maintenances (tenant_id,vehicule_id,type,notes,km_prevu,date_prevue,cout,technicien,statut) VALUES (?,?,?,?,?,?,?,?,'planifie')")
                   ->execute([$tenantId, $vId, $type, $desc ?: null, $kmPrevu, $datePrev, $cout, $tech ?: null]);
                if ($kmPrevu && in_array($type, ['vidange','revision'])) {
                    $db->prepare("UPDATE vehicules SET prochaine_vidange_km=? WHERE id=? AND tenant_id=? AND (prochaine_vidange_km IS NULL OR prochaine_vidange_km < ?)")
                       ->execute([$kmPrevu, $vId, $tenantId, $kmPrevu]);
                }
                logActivite($db, 'CREATE', 'maintenances', "Maintenance $type planifiée — véhicule #$vId");
                setFlash(FLASH_SUCCESS, 'Maintenance planifiée.');
            }
        }
        redirect($redirTab('maintenances'));
    }

    // ── Terminer maintenance
    if ($action === 'terminer_maintenance') {
        $id       = (int)($_POST['id'] ?? 0);
        $kmFait   = !empty($_POST['km_fait'])   ? (int)$_POST['km_fait']   : null;
        $coutReel = !empty($_POST['cout_reel'])  ? (float)$_POST['cout_reel'] : null;
        $notes    = trim($_POST['notes_term'] ?? '');
        $planNext = !empty($_POST['planifier_prochain']);
        $kmInter  = !empty($_POST['km_intervalle']) ? (int)$_POST['km_intervalle'] : null;

        $m = $db->prepare("SELECT * FROM maintenances WHERE id=? AND tenant_id=?");
        $m->execute([$id, $tenantId]); $maint = $m->fetch(PDO::FETCH_ASSOC);

        if ($maint) {
            $db->prepare("UPDATE maintenances SET statut='fait', km_fait=?, cout=COALESCE(?,cout), notes=CONCAT(COALESCE(notes,''), ?) WHERE id=? AND tenant_id=?")
               ->execute([$kmFait, $coutReel, $notes ? "\n↳ $notes" : '', $id, $tenantId]);

            // Enregistrer dans charges si coût renseigné + impacter dépenses véhicule
            if ($coutReel && $coutReel > 0) {
                $lbl = ($typesMaint[$maint['type']]['label'] ?? ucfirst($maint['type'])) . ($notes ? " — $notes" : '');
                $db->prepare("INSERT INTO charges (tenant_id,vehicule_id,type,libelle,montant,date_charge) VALUES (?,?,'maintenance',?,?,CURDATE())")
                   ->execute([$tenantId, $maint['vehicule_id'], $lbl, $coutReel]);
            }
            // Mettre à jour km véhicule si km_fait fourni
            if ($kmFait) {
                $db->prepare("UPDATE vehicules SET kilometrage_actuel=? WHERE id=? AND tenant_id=? AND kilometrage_actuel < ?")
                   ->execute([$kmFait, $maint['vehicule_id'], $tenantId, $kmFait]);
            }
            // Planifier la prochaine
            if ($planNext && $kmInter && $kmFait) {
                $nextKm = $kmFait + $kmInter;
                $db->prepare("INSERT INTO maintenances (tenant_id,vehicule_id,type,notes,km_prevu,statut) VALUES (?,?,?,?,?,'planifie')")
                   ->execute([$tenantId, $maint['vehicule_id'], $maint['type'], 'Auto-planifiée (intervalle '.$kmInter.' km)', $nextKm]);
                $db->prepare("UPDATE vehicules SET prochaine_vidange_km=? WHERE id=? AND tenant_id=?")
                   ->execute([$nextKm, $maint['vehicule_id'], $tenantId]);
            }
            logActivite($db, 'UPDATE', 'maintenances', "Maintenance #{$id} terminée");
            setFlash(FLASH_SUCCESS, 'Maintenance marquée comme terminée.');
        }
        redirect($redirTab('maintenances'));
    }

    // ── Supprimer maintenance
    if ($action === 'supprimer_maintenance') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM maintenances WHERE id=? AND tenant_id=?")->execute([$id, $tenantId]);
        setFlash(FLASH_SUCCESS, 'Maintenance supprimée.');
        redirect($redirTab('maintenances'));
    }
}

// ── DONNÉES ───────────────────────────────────────────────────────────────────
$tab         = in_array($_GET['tab']??'',['charges','maintenances','alertes']) ? $_GET['tab'] : 'charges';
$filtreType  = $_GET['type']  ?? '';
$filtreMois  = (int)($_GET['mois']  ?? 0);
$filtreAnnee = (int)($_GET['annee'] ?? (int)date('Y'));
$filtreQ     = trim($_GET['q'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 25;
$offset      = ($page - 1) * $perPage;

// Véhicule sélectionné
$vehicule = null;
if ($filtreVehicule) {
    $s = $db->prepare("SELECT * FROM vehicules WHERE id=? AND tenant_id=?");
    $s->execute([$filtreVehicule, $tenantId]);
    $vehicule = $s->fetch(PDO::FETCH_ASSOC);
    if (!$vehicule) $filtreVehicule = 0;
}

// Liste véhicules pour selects
$stmtV = $db->prepare("SELECT id, nom, immatriculation, kilometrage_actuel FROM vehicules WHERE tenant_id=? ORDER BY nom");
$stmtV->execute([$tenantId]);
$vehicules = $stmtV->fetchAll(PDO::FETCH_ASSOC);

// Charges (paginées)
$wh = 'WHERE ch.tenant_id=?'; $pr = [$tenantId];
if ($filtreVehicule) { $wh.=' AND ch.vehicule_id=?'; $pr[]=$filtreVehicule; }
if ($filtreType && isset($typesCharges[$filtreType])) { $wh.=' AND ch.type=?'; $pr[]=$filtreType; }
if ($filtreMois)  { $wh.=' AND MONTH(ch.date_charge)=?'; $pr[]=$filtreMois; }
if ($filtreAnnee) { $wh.=' AND YEAR(ch.date_charge)=?';  $pr[]=$filtreAnnee; }
if ($filtreQ)     { $wh.=' AND ch.libelle LIKE ?';        $pr[]="%$filtreQ%"; }

$cntCh = $db->prepare("SELECT COUNT(*) FROM charges ch $wh");
$cntCh->execute($pr); $totalCharges = (int)$cntCh->fetchColumn();
$stmtCh = $db->prepare("SELECT ch.*, v.nom veh_nom, v.immatriculation FROM charges ch JOIN vehicules v ON v.id=ch.vehicule_id $wh ORDER BY ch.date_charge DESC LIMIT ? OFFSET ?");
$stmtCh->execute(array_merge($pr, [$perPage, $offset]));
$charges = $stmtCh->fetchAll(PDO::FETCH_ASSOC);

// Stats charges (année en cours)
$stS = $db->prepare("SELECT type, COALESCE(SUM(montant),0) t FROM charges WHERE tenant_id=? AND YEAR(date_charge)=?" . ($filtreVehicule?" AND vehicule_id=?":"") . " GROUP BY type");
$stS->execute($filtreVehicule ? [$tenantId,$filtreAnnee,$filtreVehicule] : [$tenantId,$filtreAnnee]);
$statsType = $stS->fetchAll(PDO::FETCH_KEY_PAIR);
$totAnnee  = array_sum($statsType);
$stM2 = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM charges WHERE tenant_id=? AND MONTH(date_charge)=MONTH(CURDATE()) AND YEAR(date_charge)=YEAR(CURDATE())" . ($filtreVehicule?" AND vehicule_id=?":""));
$stM2->execute($filtreVehicule ? [$tenantId,$filtreVehicule] : [$tenantId]);
$totMois = (float)$stM2->fetchColumn();

// Maintenances
$whM = "WHERE m.tenant_id=? AND m.statut != 'fait'"; $prM = [$tenantId];
if ($filtreVehicule) { $whM.=' AND m.vehicule_id=?'; $prM[]=$filtreVehicule; }
$stmtM = $db->prepare("SELECT m.*, v.nom veh_nom, v.immatriculation, v.kilometrage_actuel FROM maintenances m JOIN vehicules v ON v.id=m.vehicule_id $whM ORDER BY FIELD(m.statut,'planifie','en_retard'), m.km_prevu IS NULL, m.km_prevu ASC, m.date_prevue ASC");
$stmtM->execute($prM);
$maintenances = $stmtM->fetchAll(PDO::FETCH_ASSOC);
$totCoutMaint = array_sum(array_column(array_filter($maintenances, fn($x)=>$x['statut']==='fait'), 'cout'));

// Alertes : documents + vidanges
$sqlAl = "SELECT id, nom, immatriculation, kilometrage_actuel, prochaine_vidange_km, date_expiration_assurance, date_expiration_vignette
          FROM vehicules WHERE tenant_id=?
          AND ((date_expiration_assurance IS NOT NULL AND date_expiration_assurance <= ?)
            OR (date_expiration_vignette  IS NOT NULL AND date_expiration_vignette  <= ?)
            OR (prochaine_vidange_km IS NOT NULL AND kilometrage_actuel >= prochaine_vidange_km - 1000))"
          . ($filtreVehicule?" AND id=?":"");
$stAl = $db->prepare($sqlAl);
$stAl->execute($filtreVehicule ? [$tenantId,$in30,$in30,$filtreVehicule] : [$tenantId,$in30,$in30]);
$vehAlertes = $stAl->fetchAll(PDO::FETCH_ASSOC);
$maintUrgentes = array_filter($maintenances, fn($m) =>
    $m['statut']==='planifie' && (
        ($m['date_prevue'] && $m['date_prevue'] < $today)
        || ($m['km_prevu'] && (int)$m['kilometrage_actuel'] >= (int)$m['km_prevu'])
    )
);
$nbAlertes = count($vehAlertes) + count($maintUrgentes);

// Années dispo
$stAn = $db->prepare("SELECT DISTINCT YEAR(date_charge) y FROM charges WHERE tenant_id=?" . ($filtreVehicule?" AND vehicule_id=?":"") . " ORDER BY y DESC");
$stAn->execute($filtreVehicule ? [$tenantId,$filtreVehicule] : [$tenantId]);
$annees = array_column($stAn->fetchAll(),'y');
if (!in_array((int)date('Y'),$annees)) $annees[] = (int)date('Y');
rsort($annees);

$baseUrl = BASE_URL . 'app/finances/charges.php?' . http_build_query(array_filter(['vehicule_id'=>$filtreVehicule?:'','type'=>$filtreType,'mois'=>$filtreMois?:'','annee'=>$filtreAnnee,'q'=>$filtreQ,'tab'=>$tab], 'strlen'));
$moisFr = ['','Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'];

$pageTitle  = 'Charges & Maintenances';
$activePage = 'finances';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
.cm-kpi{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}
.cm-kpi-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;position:relative;overflow:hidden}
.cm-kpi-card .ck-val{font-size:1.15rem;font-weight:700;margin-top:2px}
.cm-kpi-card .ck-lbl{font-size:.68rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em}
.cm-kpi-card .ck-sub{font-size:.72rem;color:#94a3b8;margin-top:3px}
.cm-kpi-card .ck-ico{position:absolute;right:12px;top:12px;font-size:1.4rem;opacity:.12}
.veh-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;margin-bottom:14px;display:grid;grid-template-columns:auto 1fr auto;gap:16px;align-items:center}
.veh-card .vc-badge{padding:3px 8px;border-radius:6px;font-size:.7rem;font-weight:700}
.veh-card .vc-doc{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:6px;font-size:.72rem;font-weight:600;margin-right:6px}
.veh-card .vc-doc.ok{background:#d1fae5;color:#059669}
.veh-card .vc-doc.warn{background:#fef3c7;color:#b45309}
.veh-card .vc-doc.expire{background:#fee2e2;color:#ef4444}
.type-chip{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:99px;font-size:.7rem;font-weight:600}
.tab-nav{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:0}
.tab-nav a{padding:9px 18px;font-size:.82rem;font-weight:600;color:#64748b;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:.15s}
.tab-nav a.active{color:#0d9488;border-bottom-color:#0d9488}
.tab-nav a:hover{color:#0f172a}
.tab-nav .badge-alert{background:#ef4444;color:#fff;border-radius:99px;font-size:.65rem;padding:1px 6px;margin-left:4px;font-weight:700}
.filter-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;padding:12px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0}
.filter-row .form-group{margin:0}
.filter-row .form-control{height:32px;padding:0 8px;font-size:.8rem}
.filter-row select.form-control{padding:0 6px}
.al-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;display:flex;align-items:flex-start;gap:12px;margin-bottom:8px}
.al-card.urgent{border-left:3px solid #ef4444}
.al-card.warn{border-left:3px solid #f59e0b}
.al-ico{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.km-bar{height:6px;background:#e2e8f0;border-radius:3px;margin-top:4px;overflow:hidden}
.km-bar-fill{height:6px;border-radius:3px;transition:width .4s}

@media(max-width:768px){
    .page-header{flex-direction:column;align-items:flex-start!important;gap:10px}
    .cm-kpi{grid-template-columns:1fr 1fr}
    .veh-card{grid-template-columns:1fr!important;gap:8px}
    .filter-row{flex-direction:column;align-items:stretch!important}
    .filter-row .form-group{width:100%}
    .filter-row .form-control{width:100%}
    .tab-nav{overflow-x:auto}
    .tab-nav a{padding:9px 12px;font-size:.75rem;white-space:nowrap}
    .al-card{flex-direction:column}
    .table thead{display:none}
    .table tr{display:block;border:1px solid #e2e8f0;border-radius:8px;padding:10px;margin-bottom:10px}
    .table td{display:flex;justify-content:space-between;padding:4px 0;border:none}
    .table td::before{content:attr(data-label);font-weight:600;color:#64748b;font-size:.75rem}
    .modal-card{max-width:95vw!important;margin:10px}
    .form-row.cols-2,.form-row.cols-3{grid-template-columns:1fr!important}
}
</style>

<div class="page-header" style="margin-bottom:12px">
    <div>
        <h1 class="page-title" style="margin-bottom:2px"><i class="fas fa-receipt"></i> Charges & Maintenances</h1>
        <?php if ($vehicule): ?>
        <p class="page-subtitle" style="margin:0">
            <a href="<?= BASE_URL ?>app/finances/charges.php" style="color:#94a3b8;font-size:.8rem"><i class="fas fa-arrow-left"></i> Toute la flotte</a>
            &nbsp;/&nbsp; <?= sanitize($vehicule['nom']) ?> <span style="color:#94a3b8"><?= sanitize($vehicule['immatriculation']) ?></span>
        </p>
        <?php else: ?>
        <p class="page-subtitle" style="margin:0">Toutes les dépenses & maintenances de votre flotte</p>
        <?php endif ?>
    </div>
    <div style="display:flex;gap:8px">
        <button class="btn btn-outline-primary btn-sm" onclick="openModal('modal-maint')">
            <i class="fas fa-wrench"></i> + Maintenance
        </button>
        <button class="btn btn-primary btn-sm" onclick="openModal('modal-charge')">
            <i class="fas fa-plus"></i> + Charge
        </button>
    </div>
</div>

<?= renderFlashes() ?>

<?php if ($nbAlertes > 0): ?>
<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;font-size:.82rem">
    <i class="fas fa-triangle-exclamation" style="color:#f59e0b;font-size:1.1rem"></i>
    <strong style="color:#92400e"><?= $nbAlertes ?> alerte<?= $nbAlertes>1?'s':'' ?> à traiter</strong>
    <span style="color:#78350f">— Documents expirés/expirants ou vidanges dues.</span>
    <a href="?<?= http_build_query(array_filter(['vehicule_id'=>$filtreVehicule?:'','tab'=>'alertes'],'strlen')) ?>" style="margin-left:auto;color:#0d9488;font-weight:600">Voir les alertes →</a>
</div>
<?php endif ?>

<?php if ($vehicule): ?>
<!-- ── Carte véhicule ──────────────────────────────────────────────────────── -->
<?php
$kmActuel  = (int)$vehicule['kilometrage_actuel'];
$kmVidange = (int)($vehicule['prochaine_vidange_km'] ?? 0);
$kmRestant = $kmVidange > 0 ? $kmVidange - $kmActuel : null;
$pctVidange= ($kmVidange > 0 && $kmActuel > 0) ? min(100, round($kmActuel / $kmVidange * 100)) : 0;
$docAssur  = $vehicule['date_expiration_assurance'] ?? null;
$docVign   = $vehicule['date_expiration_vignette']  ?? null;
function docClass(string $d, string $today, string $in30): string {
    if ($d < $today) return 'expire'; if ($d <= $in30) return 'warn'; return 'ok';
}
function docLabel(string $d, string $today, string $in30): string {
    if ($d < $today) return 'Expirée';
    $j = (int)ceil((strtotime($d)-strtotime($today))/86400);
    return $j <= 30 ? "Expire dans {$j}j" : 'Valide';
}
?>
<div class="veh-card">
    <div style="text-align:center">
        <div style="width:46px;height:46px;background:#f1f5f9;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:#64748b">
            <i class="fas fa-car"></i>
        </div>
        <div style="font-size:.65rem;color:#94a3b8;margin-top:4px">ID #<?= $vehicule['id'] ?></div>
    </div>
    <div>
        <div style="font-weight:700;font-size:.95rem"><?= sanitize($vehicule['nom']) ?></div>
        <div style="font-size:.75rem;color:#64748b"><?= sanitize($vehicule['immatriculation']) ?> &nbsp;·&nbsp; <?= sanitize($vehicule['marque']??'') ?> <?= sanitize($vehicule['modele']??'') ?></div>
        <div style="margin-top:8px">
            <?php if ($docAssur): ?>
            <span class="vc-doc <?= docClass($docAssur,$today,$in30) ?>">
                <i class="fas fa-shield-halved"></i> Assurance — <?= docLabel($docAssur,$today,$in30) ?> (<?= formatDate($docAssur) ?>)
            </span>
            <?php endif ?>
            <?php if ($docVign): ?>
            <span class="vc-doc <?= docClass($docVign,$today,$in30) ?>">
                <i class="fas fa-stamp"></i> Vignette — <?= docLabel($docVign,$today,$in30) ?> (<?= formatDate($docVign) ?>)
            </span>
            <?php endif ?>
            <?php if (!$docAssur && !$docVign): ?>
            <span style="font-size:.75rem;color:#94a3b8"><i class="fas fa-circle-info"></i> Aucune date d'assurance/vignette enregistrée</span>
            <?php endif ?>
        </div>
    </div>
    <div style="text-align:right;min-width:160px">
        <div style="font-size:.7rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em">Kilométrage</div>
        <div style="font-size:1.1rem;font-weight:700"><?= number_format($kmActuel,0,',',' ') ?> km</div>
        <?php if ($kmVidange > 0): ?>
        <div style="font-size:.72rem;color:<?= $kmRestant !== null && $kmRestant <= 500 ? '#ef4444' : ($kmRestant !== null && $kmRestant <= 2000 ? '#f59e0b' : '#64748b') ?>;margin-top:2px">
            <?php if ($kmRestant !== null && $kmRestant <= 0): ?>
            <i class="fas fa-exclamation-triangle"></i> Vidange dépassée de <?= number_format(abs($kmRestant),0,',',' ') ?> km
            <?php else: ?>
            Prochaine vidange dans <?= number_format($kmRestant,0,',',' ') ?> km (à <?= number_format($kmVidange,0,',',' ') ?> km)
            <?php endif ?>
        </div>
        <div class="km-bar" style="margin-top:4px">
            <div class="km-bar-fill" style="width:<?= $pctVidange ?>%;background:<?= $pctVidange >= 95 ? '#ef4444' : ($pctVidange >= 80 ? '#f59e0b' : '#10b981') ?>"></div>
        </div>
        <?php else: ?>
        <div style="font-size:.72rem;color:#94a3b8;margin-top:2px">Pas de vidange planifiée</div>
        <?php endif ?>
    </div>
</div>
<?php endif ?>

<!-- ── KPIs ─────────────────────────────────────────────────────────────────── -->
<div class="cm-kpi">
    <div class="cm-kpi-card" style="border-top:3px solid #ef4444">
        <div class="ck-lbl">Dépenses ce mois</div>
        <div class="ck-val" style="color:#ef4444"><?= formatMoney($totMois) ?></div>
        <div class="ck-sub"><?= $moisFr[(int)date('m')] ?> <?= date('Y') ?></div>
        <i class="fas fa-calendar-day ck-ico"></i>
    </div>
    <div class="cm-kpi-card" style="border-top:3px solid #dc2626">
        <div class="ck-lbl">Total <?= $filtreAnnee ?></div>
        <div class="ck-val" style="color:#dc2626"><?= formatMoney($totAnnee) ?></div>
        <div class="ck-sub"><?= $totalCharges ?> charge<?= $totalCharges>1?'s':'' ?> enregistrée<?= $totalCharges>1?'s':'' ?></div>
        <i class="fas fa-chart-bar ck-ico"></i>
    </div>
    <div class="cm-kpi-card" style="border-top:3px solid #f59e0b">
        <div class="ck-lbl">Maintenances (coût fait)</div>
        <div class="ck-val" style="color:#b45309"><?= formatMoney($totCoutMaint) ?></div>
        <div class="ck-sub"><?= count($maintenances) ?> enregistrée<?= count($maintenances)>1?'s':'' ?> · <?= count(array_filter($maintenances,fn($x)=>$x['statut']==='planifie')) ?> en attente</div>
        <i class="fas fa-wrench ck-ico"></i>
    </div>
    <div class="cm-kpi-card" style="border-top:3px solid <?= $nbAlertes > 0 ? '#ef4444' : '#10b981' ?>">
        <div class="ck-lbl">Alertes actives</div>
        <div class="ck-val" style="color:<?= $nbAlertes > 0 ? '#ef4444' : '#10b981' ?>"><?= $nbAlertes ?: '✓' ?><?= $nbAlertes > 0 ? ' alerte'.($nbAlertes>1?'s':'') : ' Tout est OK' ?></div>
        <div class="ck-sub"><?= count($vehAlertes) ?> doc · <?= count($maintUrgentes) ?> vidange/maint.</div>
        <i class="fas fa-bell ck-ico"></i>
    </div>
</div>

<!-- Mini répartition par type (si données) -->
<?php if ($totAnnee > 0): ?>
<div class="card" style="margin-bottom:12px">
    <div class="card-body" style="padding:12px 16px">
        <div style="font-size:.7rem;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">Répartition des charges <?= $filtreAnnee ?></div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
        <?php foreach ($statsType as $typ => $montTyp): if ($montTyp <= 0) continue;
            $tc = $typesCharges[$typ] ?? ['label'=>$typ,'color'=>'#64748b','bg'=>'#f1f5f9','icon'=>'fa-tag'];
            $pct = round($montTyp / $totAnnee * 100);
        ?>
        <div style="background:<?= $tc['bg'] ?>;border-radius:8px;padding:6px 12px;min-width:100px">
            <div style="font-size:.68rem;color:<?= $tc['color'] ?>;font-weight:700"><i class="fas <?= $tc['icon'] ?>"></i> <?= $tc['label'] ?></div>
            <div style="font-size:.85rem;font-weight:700;color:<?= $tc['color'] ?>"><?= formatMoney((float)$montTyp) ?></div>
            <div style="font-size:.65rem;color:#64748b"><?= $pct ?>% du total</div>
        </div>
        <?php endforeach ?>
        </div>
    </div>
</div>
<?php endif ?>

<!-- ── Filtre + tabs ──────────────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:0;border-radius:10px 10px 0 0;border-bottom:none">
    <form method="GET" class="filter-row">
        <input type="hidden" name="tab" value="<?= sanitize($tab) ?>">
        <?php if (!$filtreVehicule): ?>
        <div class="form-group" style="min-width:180px">
            <select name="vehicule_id" class="form-control" onchange="this.form.submit()">
                <option value="">Tous les véhicules</option>
                <?php foreach ($vehicules as $v): ?>
                <option value="<?= $v['id'] ?>" <?= $filtreVehicule==$v['id']?'selected':'' ?>><?= sanitize($v['nom']) ?> — <?= sanitize($v['immatriculation']) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <?php else: ?>
        <input type="hidden" name="vehicule_id" value="<?= $filtreVehicule ?>">
        <?php endif ?>
        <div class="form-group">
            <select name="type" class="form-control">
                <option value="">Tous types</option>
                <?php foreach ($typesCharges as $k=>$t): ?>
                <option value="<?= $k ?>" <?= $filtreType===$k?'selected':'' ?>><?= $t['label'] ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="form-group">
            <select name="mois" class="form-control">
                <option value="">Tous mois</option>
                <?php for ($m=1;$m<=12;$m++): ?>
                <option value="<?= $m ?>" <?= $filtreMois===$m?'selected':'' ?>><?= $moisFr[$m] ?></option>
                <?php endfor ?>
            </select>
        </div>
        <div class="form-group">
            <select name="annee" class="form-control">
                <?php foreach ($annees as $a): ?>
                <option value="<?= $a ?>" <?= $filtreAnnee===$a?'selected':'' ?>><?= $a ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="form-group" style="flex:1;min-width:140px">
            <input type="text" name="q" class="form-control" placeholder="Rechercher libellé…" value="<?= sanitize($filtreQ) ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i></button>
        <a href="<?= BASE_URL ?>app/finances/charges.php<?= $filtreVehicule?"?vehicule_id=$filtreVehicule":'' ?>" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a>
    </form>
    <div class="tab-nav" style="padding:0 16px">
        <?php
        $tabs = [
            'charges'      => ['Charges opérationnelles', $totalCharges],
            'maintenances' => ['Maintenances', count($maintenances)],
            'alertes'      => ['Alertes', $nbAlertes],
        ];
        foreach ($tabs as $k=>[$lbl,$cnt]):
            $href = '?' . http_build_query(array_filter(['vehicule_id'=>$filtreVehicule?:'','type'=>$filtreType,'mois'=>$filtreMois?:'','annee'=>$filtreAnnee,'q'=>$filtreQ,'tab'=>$k],'strlen'));
        ?>
        <a href="<?= $href ?>" class="<?= $tab===$k?'active':'' ?>">
            <?= $lbl ?>
            <?php if ($k==='alertes' && $cnt > 0): ?>
            <span class="badge-alert"><?= $cnt ?></span>
            <?php elseif ($cnt > 0): ?>
            <span style="background:#f1f5f9;color:#64748b;border-radius:99px;font-size:.65rem;padding:1px 6px;margin-left:4px"><?= $cnt ?></span>
            <?php endif ?>
        </a>
        <?php endforeach ?>
    </div>
</div>

<div class="card" style="border-radius:0 0 10px 10px">

<?php if ($tab === 'charges'): ?>
<!-- ══ TAB CHARGES ══════════════════════════════════════════════════════════════ -->
<div class="table-responsive">
    <table class="table" style="font-size:.82rem">
        <thead>
            <tr>
                <th>Date</th>
                <?php if (!$filtreVehicule): ?><th>Véhicule</th><?php endif ?>
                <th>Type</th>
                <th>Libellé</th>
                <th style="text-align:right">Montant</th>
                <th>Notes</th>
                <th style="width:60px"></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($charges)): ?>
        <tr><td colspan="7" style="text-align:center;padding:2.5rem;color:#94a3b8">
            <i class="fas fa-receipt" style="font-size:1.8rem;display:block;margin-bottom:8px"></i>
            Aucune charge enregistrée<?= $filtreType||$filtreQ||$filtreMois?' pour ce filtre.':' pour l\'instant.' ?>
        </td></tr>
        <?php else: ?>
        <?php foreach ($charges as $ch):
            $tc = $typesCharges[$ch['type']] ?? ['label'=>$ch['type'],'color'=>'#64748b','bg'=>'#f1f5f9','icon'=>'fa-tag'];
        ?>
        <tr>
            <td style="white-space:nowrap;color:#94a3b8"><?= formatDate($ch['date_charge']) ?></td>
            <?php if (!$filtreVehicule): ?>
            <td>
                <a href="?vehicule_id=<?= $ch['vehicule_id'] ?>" style="font-weight:600;color:#0f172a;text-decoration:none"><?= sanitize($ch['veh_nom']) ?></a>
                <br><span style="font-size:.72rem;color:#94a3b8"><?= sanitize($ch['immatriculation']) ?></span>
            </td>
            <?php endif ?>
            <td>
                <span class="type-chip" style="background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>">
                    <i class="fas <?= $tc['icon'] ?>"></i> <?= $tc['label'] ?>
                </span>
            </td>
            <td style="font-weight:500"><?= sanitize($ch['libelle']) ?></td>
            <td style="text-align:right;font-weight:700;color:#ef4444"><?= formatMoney((float)$ch['montant']) ?></td>
            <td style="font-size:.75rem;color:#94a3b8;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= sanitize(mb_substr($ch['notes'] ?? '', 0, 60)) ?>
                <?php if (!empty($ch['piece_jointe'])): ?>
                <a href="<?= BASE_URL ?>uploads/charges/<?= sanitize($ch['piece_jointe']) ?>" target="_blank" title="Voir PJ" style="color:#0d9488;margin-left:4px"><i class="fas fa-paperclip"></i></a>
                <?php endif ?>
            </td>
            <td>
                <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer cette charge ?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="supprimer_charge">
                    <input type="hidden" name="id" value="<?= $ch['id'] ?>">
                    <input type="hidden" name="vehicule_id_filter" value="<?= $filtreVehicule ?>">
                    <button class="btn btn-sm" style="background:#fff1f2;color:#ef4444;border:none;width:28px;height:28px;border-radius:6px;cursor:pointer" title="Supprimer"><i class="fas fa-trash"></i></button>
                </form>
            </td>
        </tr>
        <?php endforeach ?>
        <?php endif ?>
        </tbody>
        <?php if (!empty($charges)): $totListe = array_sum(array_column($charges,'montant')); ?>
        <tfoot style="background:#f8fafc;font-size:.78rem;font-weight:700">
            <tr>
                <td colspan="<?= $filtreVehicule?4:5 ?>" style="color:#64748b"><?= $totalCharges ?> charge<?= $totalCharges>1?'s':'' ?> — page <?= $page ?></td>
                <td style="text-align:right;color:#ef4444"><?= formatMoney($totListe) ?></td>
                <td></td>
            </tr>
        </tfoot>
        <?php endif ?>
    </table>
</div>
<div style="padding:10px 16px"><?= renderPagination($totalCharges, $page, $perPage, $baseUrl) ?></div>

<?php elseif ($tab === 'maintenances'): ?>
<!-- ══ TAB MAINTENANCES ════════════════════════════════════════════════════════ -->
<div class="table-responsive">
    <table class="table" style="font-size:.82rem">
        <thead>
            <tr>
                <?php if (!$filtreVehicule): ?><th>Véhicule</th><?php endif ?>
                <th>Type</th>
                <th>Déclencheur</th>
                <th>Km restant</th>
                <th>Technicien</th>
                <th style="text-align:right">Coût estim.</th>
                <th>Statut</th>
                <th style="width:100px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($maintenances)): ?>
        <tr><td colspan="8" style="text-align:center;padding:2.5rem;color:#94a3b8">
            <i class="fas fa-wrench" style="font-size:1.8rem;display:block;margin-bottom:8px"></i>
            Aucune maintenance enregistrée.
        </td></tr>
        <?php else: ?>
        <?php foreach ($maintenances as $m):
            $tm = $typesMaint[$m['type']] ?? ['label'=>ucfirst($m['type']),'icon'=>'fa-wrench','km'=>false];
            $km = (int)$m['kilometrage_actuel'];
            $kmR = $m['km_prevu'] ? ((int)$m['km_prevu'] - $km) : null;
            $isUrgent = $m['statut']==='planifie' && (
                ($m['date_prevue'] && $m['date_prevue'] < $today)
                || ($m['km_prevu'] && $km >= (int)$m['km_prevu'])
            );
            $statutColor = match($m['statut']) {
                'fait'      => ['bg'=>'#d1fae5','c'=>'#059669','label'=>'Fait'],
                'en_retard' => ['bg'=>'#fee2e2','c'=>'#ef4444','label'=>'En retard'],
                default     => $isUrgent ? ['bg'=>'#fee2e2','c'=>'#ef4444','label'=>'Urgent'] : ['bg'=>'#fef3c7','c'=>'#d97706','label'=>'Planifié'],
            };
        ?>
        <tr style="<?= $isUrgent && $m['statut']==='planifie' ? 'background:#fff5f5' : '' ?>">
            <?php if (!$filtreVehicule): ?>
            <td>
                <a href="?vehicule_id=<?= $m['vehicule_id'] ?>&tab=maintenances" style="font-weight:600;color:#0f172a;text-decoration:none"><?= sanitize($m['veh_nom']) ?></a>
                <br><span style="font-size:.72rem;color:#94a3b8"><?= sanitize($m['immatriculation']) ?></span>
            </td>
            <?php endif ?>
            <td>
                <span class="type-chip" style="background:#f1f5f9;color:#475569">
                    <i class="fas <?= $tm['icon'] ?>"></i> <?= $tm['label'] ?>
                </span>
                <?php if ($m['notes']): ?><br><span style="font-size:.72rem;color:#94a3b8"><?= sanitize(mb_substr($m['notes'],0,40)) ?></span><?php endif ?>
            </td>
            <td style="font-size:.78rem">
                <?php if ($m['km_prevu']): ?>
                <span style="color:#0d9488"><i class="fas fa-tachometer-alt"></i> À <?= number_format((int)$m['km_prevu'],0,',',' ') ?> km</span>
                <?php endif ?>
                <?php if ($m['date_prevue']): ?>
                <span style="color:#7c3aed;<?= $m['km_prevu']?'margin-left:6px':'' ?>"><i class="fas fa-calendar"></i> <?= formatDate($m['date_prevue']) ?></span>
                <?php endif ?>
                <?php if (!$m['km_prevu'] && !$m['date_prevue']): ?><span style="color:#94a3b8">—</span><?php endif ?>
            </td>
            <td>
                <?php if ($kmR !== null): ?>
                <span style="font-weight:700;color:<?= $kmR<=0?'#ef4444':($kmR<=1000?'#f59e0b':'#10b981') ?>">
                    <?= $kmR<=0 ? '⚠ '.number_format(abs($kmR),0,',',' ').' km dépassé' : number_format($kmR,0,',',' ').' km' ?>
                </span>
                <div class="km-bar" style="width:80px">
                    <?php $pct = $m['km_prevu'] > 0 ? min(100, round($km/$m['km_prevu']*100)) : 0 ?>
                    <div class="km-bar-fill" style="width:<?= $pct ?>%;background:<?= $pct>=100?'#ef4444':($pct>=85?'#f59e0b':'#10b981') ?>"></div>
                </div>
                <?php elseif ($m['km_fait']): ?>
                <span style="color:#059669;font-size:.75rem">Fait à <?= number_format((int)$m['km_fait'],0,',',' ') ?> km</span>
                <?php else: ?>
                <span style="color:#94a3b8">—</span>
                <?php endif ?>
            </td>
            <td style="font-size:.78rem;color:#64748b"><?= sanitize($m['technicien'] ?? '—') ?></td>
            <td style="text-align:right;font-weight:600;color:#64748b">
                <?= $m['cout'] > 0 ? formatMoney((float)$m['cout']) : '<span style="color:#94a3b8">—</span>' ?>
            </td>
            <td>
                <span style="background:<?= $statutColor['bg'] ?>;color:<?= $statutColor['c'] ?>;padding:2px 8px;border-radius:99px;font-size:.7rem;font-weight:700">
                    <?= $statutColor['label'] ?>
                </span>
            </td>
            <td style="display:flex;gap:4px;align-items:center">
                <?php if ($m['statut'] === 'planifie' || $m['statut'] === 'en_retard'): ?>
                <button class="btn btn-sm" style="background:#d1fae5;color:#059669;border:none;padding:3px 7px;border-radius:5px;cursor:pointer;font-size:.72rem"
                        onclick="ouvrirTerminer(<?= $m['id'] ?>, '<?= addslashes($tm['label']) ?>', <?= $m['km_prevu']?:(int)$km ?>, <?= (int)$m['km_prevu'] > 0 ? 1 : 0 ?>)">
                    <i class="fas fa-check"></i> Terminer
                </button>
                <?php endif ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer cette maintenance ?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="supprimer_maintenance">
                    <input type="hidden" name="id" value="<?= $m['id'] ?>">
                    <input type="hidden" name="vehicule_id_filter" value="<?= $filtreVehicule ?>">
                    <button class="btn btn-sm" style="background:#fff1f2;color:#ef4444;border:none;width:26px;height:26px;border-radius:5px;cursor:pointer"><i class="fas fa-trash"></i></button>
                </form>
            </td>
        </tr>
        <?php endforeach ?>
        <?php endif ?>
        </tbody>
    </table>
</div>

<?php elseif ($tab === 'alertes'): ?>
<!-- ══ TAB ALERTES ════════════════════════════════════════════════════════════= -->
<div style="padding:16px">
    <?php if ($nbAlertes === 0): ?>
    <div style="text-align:center;padding:2.5rem;color:#94a3b8">
        <i class="fas fa-check-circle" style="font-size:2.5rem;color:#10b981;display:block;margin-bottom:10px"></i>
        <strong style="color:#10b981">Tout est à jour !</strong><br>
        <span style="font-size:.82rem">Aucun document expiré ni maintenance dépassée.</span>
    </div>
    <?php else: ?>

    <?php if (!empty($vehAlertes)): ?>
    <h4 style="font-size:.78rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.07em;margin:0 0 10px">Documents &amp; Vidanges véhicule</h4>
    <?php foreach ($vehAlertes as $va):
        $kmA = (int)$va['kilometrage_actuel'];
        $kvR = $va['prochaine_vidange_km'] ? ((int)$va['prochaine_vidange_km'] - $kmA) : null;
        $assExpire = $va['date_expiration_assurance'] && $va['date_expiration_assurance'] <= $in30;
        $vigExpire = $va['date_expiration_vignette']  && $va['date_expiration_vignette']  <= $in30;
        $vidDue    = $kvR !== null && $kvR <= 1000;
        $isUrgentAl= ($va['date_expiration_assurance'] && $va['date_expiration_assurance'] < $today)
                  || ($va['date_expiration_vignette']  && $va['date_expiration_vignette']  < $today)
                  || ($kvR !== null && $kvR <= 0);
    ?>
    <div class="al-card <?= $isUrgentAl?'urgent':'warn' ?>">
        <div class="al-ico" style="background:<?= $isUrgentAl?'#fee2e2':'#fef3c7' ?>;color:<?= $isUrgentAl?'#ef4444':'#d97706' ?>">
            <i class="fas fa-car"></i>
        </div>
        <div style="flex:1">
            <div style="font-weight:700;font-size:.88rem">
                <a href="?vehicule_id=<?= $va['id'] ?>&tab=alertes" style="color:#0f172a;text-decoration:none"><?= sanitize($va['nom']) ?></a>
                <span style="color:#94a3b8;font-size:.75rem;font-weight:400;margin-left:6px"><?= sanitize($va['immatriculation']) ?></span>
            </div>
            <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap">
                <?php if ($assExpire): ?>
                <span class="vc-doc <?= $va['date_expiration_assurance'] < $today?'expire':'warn' ?>">
                    <i class="fas fa-shield-halved"></i>
                    Assurance <?= $va['date_expiration_assurance'] < $today ? 'expirée' : 'expire' ?> le <?= formatDate($va['date_expiration_assurance']) ?>
                </span>
                <?php endif ?>
                <?php if ($vigExpire): ?>
                <span class="vc-doc <?= $va['date_expiration_vignette'] < $today?'expire':'warn' ?>">
                    <i class="fas fa-stamp"></i>
                    Vignette <?= $va['date_expiration_vignette'] < $today ? 'expirée' : 'expire' ?> le <?= formatDate($va['date_expiration_vignette']) ?>
                </span>
                <?php endif ?>
                <?php if ($vidDue): ?>
                <span class="vc-doc <?= $kvR <= 0?'expire':'warn' ?>">
                    <i class="fas fa-oil-can"></i>
                    Vidange <?= $kvR <= 0 ? 'dépassée de '.number_format(abs($kvR),0,',',' ').' km' : 'dans '.number_format($kvR,0,',',' ').' km' ?>
                </span>
                <?php endif ?>
            </div>
        </div>
        <a href="<?= BASE_URL ?>app/vehicules/detail.php?id=<?= $va['id'] ?>" class="btn btn-ghost btn-sm" title="Fiche véhicule"><i class="fas fa-eye"></i></a>
    </div>
    <?php endforeach ?>
    <?php endif ?>

    <?php if (!empty($maintUrgentes)): ?>
    <h4 style="font-size:.78rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.07em;margin:14px 0 10px">Maintenances urgentes</h4>
    <?php foreach ($maintUrgentes as $mu):
        $tm = $typesMaint[$mu['type']] ?? ['label'=>ucfirst($mu['type']),'icon'=>'fa-wrench'];
        $kmU = (int)$mu['kilometrage_actuel'];
        $kmRU = $mu['km_prevu'] ? ((int)$mu['km_prevu'] - $kmU) : null;
    ?>
    <div class="al-card urgent">
        <div class="al-ico" style="background:#fee2e2;color:#ef4444"><i class="fas <?= $tm['icon'] ?>"></i></div>
        <div style="flex:1">
            <div style="font-weight:700;font-size:.88rem"><?= $tm['label'] ?> — <?= sanitize($mu['veh_nom']) ?> <span style="color:#94a3b8;font-weight:400;font-size:.75rem"><?= sanitize($mu['immatriculation']) ?></span></div>
            <div style="font-size:.78rem;color:#64748b;margin-top:3px">
                <?php if ($kmRU !== null): ?>
                Km dépassé de <strong style="color:#ef4444"><?= number_format(abs(min($kmRU,0)),0,',',' ') ?> km</strong> — Actuel: <?= number_format($kmU,0,',',' ') ?> km / Prévu: <?= number_format((int)$mu['km_prevu'],0,',',' ') ?> km
                <?php endif ?>
                <?php if ($mu['date_prevue'] && $mu['date_prevue'] < $today): ?>
                · Prévue le <?= formatDate($mu['date_prevue']) ?>
                <?php endif ?>
                <?php if ($mu['notes']): ?><br><span style="color:#94a3b8"><?= sanitize(mb_substr($mu['notes'],0,80)) ?></span><?php endif ?>
            </div>
        </div>
        <button class="btn btn-sm" style="background:#d1fae5;color:#059669;border:none;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:.78rem"
                onclick="ouvrirTerminer(<?= $mu['id'] ?>, '<?= addslashes($tm['label']) ?>', <?= (int)$mu['km_prevu'] ?: (int)$kmU ?>, <?= (int)$mu['km_prevu'] > 0 ? 1 : 0 ?>)">
            <i class="fas fa-check"></i> Terminer
        </button>
    </div>
    <?php endforeach ?>
    <?php endif ?>

    <?php endif ?>
</div>
<?php endif ?>
</div><!-- /card -->

<!-- ═══ MODALS ═══════════════════════════════════════════════════════════════ -->

<!-- Modal + Charge -->
<div id="modal-charge" class="modal-overlay">
    <div class="modal" style="max-width:540px">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Nouvelle charge</h3>
            <button class="modal-close" onclick="closeModal('modal-charge')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="ajouter_charge">
            <input type="hidden" name="vehicule_id_filter" value="<?= $filtreVehicule ?>">
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Véhicule <span style="color:red">*</span></label>
                    <select name="vehicule_id" class="form-control" required>
                        <option value="">— Sélectionner —</option>
                        <?php foreach ($vehicules as $v): ?>
                        <option value="<?= $v['id'] ?>" <?= $filtreVehicule==$v['id']?'selected':'' ?>><?= sanitize($v['nom']) ?> — <?= sanitize($v['immatriculation']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Type <span style="color:red">*</span></label>
                    <select name="type" id="ch-type" class="form-control" onchange="onTypeChange(this.value)">
                        <?php foreach ($typesChargesManuel as $k=>$t): ?>
                        <option value="<?= $k ?>" <?= $filtreType===$k?'selected':'' ?>><?= $t['label'] ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Date <span style="color:red">*</span></label>
                    <input type="date" name="date_charge" class="form-control" value="<?= $today ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Montant (FCFA) <span style="color:red">*</span></label>
                    <input type="number" name="montant" class="form-control" min="1" step="1" placeholder="0" required>
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Libellé <span style="color:red">*</span></label>
                    <input type="text" name="libelle" class="form-control" placeholder="Ex: Plein Total — 40L, Prime assurance annuelle…" required>
                </div>
                <!-- Champ date expiration (assurance / vignette) -->
                <div class="form-group" id="ch-expiry-wrap" style="grid-column:1/-1;display:none">
                    <label class="form-label" id="ch-expiry-lbl">Nouvelle date d'expiration du document</label>
                    <input type="date" name="date_expiration" id="ch-expiry" class="form-control">
                    <span class="form-hint">Met à jour automatiquement la fiche véhicule.</span>
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Informations complémentaires…"></textarea>
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Pièce jointe (PDF, JPG, PNG)</label>
                    <input type="file" name="piece_jointe" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modal-charge')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal + Maintenance -->
<div id="modal-maint" class="modal-overlay">
    <div class="modal" style="max-width:560px">
        <div class="modal-header">
            <h3 id="maint-modal-title"><i class="fas fa-wrench"></i> Maintenance</h3>
            <button class="modal-close" onclick="closeModal('modal-maint')">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="ajouter_maintenance">
            <input type="hidden" name="mode_maint" id="mt-mode" value="planifier">
            <input type="hidden" name="vehicule_id_filter" value="<?= $filtreVehicule ?>">

            <!-- Toggle Planifier / Direct -->
            <div style="display:flex;gap:0;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin-bottom:16px">
                <button type="button" id="btn-mode-plan" onclick="setMaintMode('planifier')"
                        style="flex:1;padding:8px;border:none;cursor:pointer;font-size:.82rem;font-weight:600;background:#0d9488;color:#fff;transition:.15s">
                    <i class="fas fa-calendar-plus"></i> Planifier pour plus tard
                </button>
                <button type="button" id="btn-mode-direct" onclick="setMaintMode('direct')"
                        style="flex:1;padding:8px;border:none;cursor:pointer;font-size:.82rem;font-weight:600;background:#f8fafc;color:#64748b;transition:.15s">
                    <i class="fas fa-check-circle"></i> Effectuer maintenant
                </button>
            </div>

            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Véhicule <span style="color:red">*</span></label>
                    <select name="vehicule_id" id="mt-veh-select" class="form-control" required onchange="onMaintVehChange(this)">
                        <option value="">— Sélectionner —</option>
                        <?php foreach ($vehicules as $v): ?>
                        <option value="<?= $v['id'] ?>" data-km="<?= (int)$v['kilometrage_actuel'] ?>" <?= $filtreVehicule==$v['id']?'selected':'' ?>>
                            <?= sanitize($v['nom']) ?> — <?= sanitize($v['immatriculation']) ?>
                        </option>
                        <?php endforeach ?>
                    </select>
                    <div id="mt-veh-km" style="margin-top:5px;font-size:.78rem;color:#0d9488;display:none">
                        <i class="fas fa-tachometer-alt"></i> Km actuel : <strong id="mt-veh-km-val">—</strong>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Type <span style="color:red">*</span></label>
                    <select name="type_maint" id="mt-type" class="form-control" onchange="onMaintTypeChange(this.value)">
                        <?php foreach ($typesMaint as $k=>$t): ?>
                        <option value="<?= $k ?>"><?= $t['label'] ?> <?= $t['km']?'(km)':'' ?></option>
                        <?php endforeach ?>
                    </select>
                </div>

                <!-- Champs MODE PLANIFIER -->
                <div class="form-group" id="mt-km-wrap">
                    <label class="form-label"><i class="fas fa-tachometer-alt" style="color:#0d9488"></i> Km seuil déclencheur</label>
                    <input type="number" name="km_prevu" id="mt-km" class="form-control" min="1" placeholder="Ex: 85000">
                    <span class="form-hint">Urgent quand le véhicule atteindra ce km.</span>
                </div>
                <div class="form-group" id="mt-date-wrap">
                    <label class="form-label"><i class="fas fa-calendar" style="color:#7c3aed"></i> Date limite (optionnel)</label>
                    <input type="date" name="date_prevue" class="form-control">
                </div>
                <div class="form-group" id="mt-cout-plan-wrap">
                    <label class="form-label">Coût estimé (FCFA)</label>
                    <input type="number" name="cout_estime" class="form-control" min="0" step="1" placeholder="0">
                </div>

                <!-- Champs MODE DIRECT -->
                <div id="mt-direct-km" class="form-group" style="display:none">
                    <label class="form-label"><i class="fas fa-tachometer-alt" style="color:#0d9488"></i> Km au compteur maintenant</label>
                    <input type="number" name="km_fait_direct" id="mt-km-direct" class="form-control" min="0" placeholder="Km actuel">
                </div>
                <div id="mt-direct-cout" class="form-group" style="display:none">
                    <label class="form-label"><i class="fas fa-money-bill" style="color:#059669"></i> Coût réel (FCFA)</label>
                    <input type="number" name="cout_direct" class="form-control" min="0" step="1" placeholder="0">
                    <span class="form-hint">Ajouté aux charges automatiquement.</span>
                </div>

                <!-- Champs communs -->
                <div class="form-group">
                    <label class="form-label">Technicien / Garage</label>
                    <input type="text" name="technicien" class="form-control" placeholder="Ex: Garage Diallo">
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Description / Notes</label>
                    <textarea name="description" class="form-control" rows="2" placeholder="Détails, références pièces…"></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modal-maint')">Annuler</button>
                <button type="submit" id="mt-submit-btn" class="btn btn-primary"><i class="fas fa-calendar-plus"></i> Planifier</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Terminer maintenance -->
<div id="modal-terminer" class="modal-overlay">
    <div class="modal" style="max-width:460px">
        <div class="modal-header">
            <h3 id="mt-term-title"><i class="fas fa-check-circle"></i> Terminer la maintenance</h3>
            <button class="modal-close" onclick="closeModal('modal-terminer')">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="terminer_maintenance">
            <input type="hidden" name="id" id="mt-term-id">
            <input type="hidden" name="vehicule_id_filter" value="<?= $filtreVehicule ?>">
            <div class="form-row cols-2">
                <div class="form-group" id="mt-term-km-wrap">
                    <label class="form-label">Km au compteur (maintenant)</label>
                    <input type="number" name="km_fait" id="mt-term-km" class="form-control" min="0" placeholder="Km actuel">
                </div>
                <div class="form-group">
                    <label class="form-label">Coût réel (FCFA)</label>
                    <input type="number" name="cout_reel" class="form-control" min="0" step="1" placeholder="0">
                    <span class="form-hint">Sera ajouté aux charges automatiquement.</span>
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Observations</label>
                    <textarea name="notes_term" class="form-control" rows="2" placeholder="Pièces remplacées, remarques…"></textarea>
                </div>
                <div class="form-group" style="grid-column:1/-1" id="mt-term-plan-wrap">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.85rem">
                        <input type="checkbox" name="planifier_prochain" id="mt-term-plan-chk" onchange="togglePlanNext(this.checked)"> Planifier automatiquement la prochaine
                    </label>
                    <div id="mt-term-plan-fields" style="display:none;margin-top:8px;padding:10px;background:#f8fafc;border-radius:6px">
                        <label class="form-label">Intervalle (km)</label>
                        <input type="number" name="km_intervalle" id="mt-term-interval" class="form-control" min="1000" step="1" placeholder="Ex: 5000 (tous les 5 000 km)">
                        <span class="form-hint">Une nouvelle maintenance sera créée à km_actuel + intervalle.</span>
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modal-terminer')">Annuler</button>
                <button type="submit" class="btn btn-primary" style="background:#059669"><i class="fas fa-check"></i> Confirmer</button>
            </div>
        </form>
    </div>
</div>

<?php
$extraJs = <<<'JS'
function onTypeChange(v) {
    var wrap = document.getElementById('ch-expiry-wrap');
    var lbl  = document.getElementById('ch-expiry-lbl');
    if (v === 'assurance') {
        wrap.style.display = ''; lbl.textContent = "Nouvelle date d'expiration de l'assurance";
    } else if (v === 'vignette') {
        wrap.style.display = ''; lbl.textContent = "Nouvelle date d'expiration de la vignette";
    } else {
        wrap.style.display = 'none';
    }
}
var maintKmTypes = ['vidange','revision','pneus','freins','courroie'];
function onMaintTypeChange(v) {
    var wrap = document.getElementById('mt-km-wrap');
    if (wrap) wrap.style.display = maintKmTypes.includes(v) ? '' : 'none';
}
function onMaintVehChange(sel) {
    var opt = sel.options[sel.selectedIndex];
    var km  = opt ? parseInt(opt.dataset.km || '0') : 0;
    var disp = document.getElementById('mt-veh-km');
    var val  = document.getElementById('mt-veh-km-val');
    var kmd  = document.getElementById('mt-km-direct');
    if (km > 0) {
        val.textContent = km.toLocaleString('fr-FR') + ' km';
        disp.style.display = '';
        if (kmd) kmd.placeholder = km;
    } else {
        disp.style.display = 'none';
    }
}
function setMaintMode(mode) {
    document.getElementById('mt-mode').value = mode;
    var isPlan = mode === 'planifier';
    document.getElementById('btn-mode-plan').style.background   = isPlan ? '#0d9488' : '#f8fafc';
    document.getElementById('btn-mode-plan').style.color        = isPlan ? '#fff'    : '#64748b';
    document.getElementById('btn-mode-direct').style.background = isPlan ? '#f8fafc' : '#059669';
    document.getElementById('btn-mode-direct').style.color      = isPlan ? '#64748b' : '#fff';
    // plan fields
    ['mt-km-wrap','mt-date-wrap','mt-cout-plan-wrap'].forEach(function(id){
        var el = document.getElementById(id);
        if (el) el.style.display = isPlan ? '' : 'none';
    });
    // direct fields
    ['mt-direct-km','mt-direct-cout'].forEach(function(id){
        var el = document.getElementById(id);
        if (el) el.style.display = isPlan ? 'none' : '';
    });
    var btn = document.getElementById('mt-submit-btn');
    if (isPlan) {
        btn.innerHTML = '<i class="fas fa-calendar-plus"></i> Planifier';
    } else {
        btn.innerHTML = '<i class="fas fa-check-circle"></i> Enregistrer comme effectuée';
        // Pre-fill km from selected vehicle
        var sel = document.getElementById('mt-veh-select');
        var kmd = document.getElementById('mt-km-direct');
        if (kmd && !kmd.value && sel) {
            var opt = sel.options[sel.selectedIndex];
            if (opt && opt.dataset.km) kmd.value = opt.dataset.km;
        }
    }
    document.getElementById('maint-modal-title').innerHTML = isPlan
        ? '<i class="fas fa-calendar-plus"></i> Planifier une maintenance'
        : '<i class="fas fa-check-circle" style="color:#059669"></i> Enregistrer une maintenance effectuée';
}
function ouvrirTerminer(id, label, kmSuggest, isKm) {
    document.getElementById('mt-term-id').value = id;
    document.getElementById('mt-term-title').innerHTML = '<i class="fas fa-check-circle"></i> Terminer — ' + label;
    document.getElementById('mt-term-km').value = kmSuggest || '';
    document.getElementById('mt-term-km-wrap').style.display = isKm ? '' : 'none';
    document.getElementById('mt-term-plan-wrap').style.display = isKm ? '' : 'none';
    document.getElementById('mt-term-plan-chk').checked = false;
    document.getElementById('mt-term-plan-fields').style.display = 'none';
    openModal('modal-terminer');
}
function togglePlanNext(checked) {
    document.getElementById('mt-term-plan-fields').style.display = checked ? '' : 'none';
}
// Init km display if vehicle pre-selected + init type if pre-selected from URL
document.addEventListener('DOMContentLoaded', function() {
    var sel = document.getElementById('mt-veh-select');
    if (sel && sel.value) onMaintVehChange(sel);
    var chType = document.getElementById('ch-type');
    if (chType && chType.value) onTypeChange(chType.value);
});
JS;
require_once BASE_PATH . '/includes/footer.php';
?>
