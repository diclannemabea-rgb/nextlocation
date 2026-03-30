<?php
/**
 * FlotteCar — Rentabilité & Analyses Complètes
 * Locations · Taxi · Contraventions · Dépenses Entreprise · KPIs · Export Excel
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
require_once BASE_PATH . '/models/FinanceModel.php';
requireTenantAuth();

$db       = (new Database())->getConnection();
$tenantId = getTenantId();
$finance  = new FinanceModel($db);

// ── Filtres ───────────────────────────────────────────────────────────────────
$filtreVid  = (int)($_GET['vehicule_id'] ?? 0);
$filtreMois = trim($_GET['mois'] ?? '');           // ex: 2026-03
$filtreFrom = trim($_GET['from'] ?? '');
$filtreTo   = trim($_GET['to']   ?? '');

// Calcul plage date
if ($filtreMois) {
    $filtreFrom = $filtreMois . '-01';
    $filtreTo   = date('Y-m-t', strtotime($filtreFrom));
} elseif (!$filtreFrom) {
    $filtreFrom = date('Y-01-01');  // par défaut : cette année
    $filtreTo   = date('Y-12-31');
}
if (!$filtreTo) $filtreTo = date('Y-m-d');

// ── Export Excel ──────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    require_once BASE_PATH . '/vendor/autoload.php';
    exportExcel($db, $tenantId, $filtreFrom, $filtreTo, $filtreVid);
    exit;
}

// ── Véhicules pour filtre ─────────────────────────────────────────────────────
$stmtVehs = $db->prepare("SELECT id, nom, immatriculation, type_vehicule, kilometrage_actuel,
    km_initial_compteur, capital_investi, date_expiration_assurance, date_expiration_vignette
    FROM vehicules WHERE tenant_id=? ORDER BY nom");
$stmtVehs->execute([$tenantId]);
$vehiculesFiltres = $stmtVehs->fetchAll(PDO::FETCH_ASSOC);
$nbVehicules = count($vehiculesFiltres);

// ── Helper: WHERE date ────────────────────────────────────────────────────────
function dateWhere(string $col): string {
    return " AND $col BETWEEN :from AND :to";
}
function bindDate(PDOStatement $s, string $from, string $to): void {
    $s->bindValue(':from', $from);
    $s->bindValue(':to',   $to);
}

// ── Recettes locations (paiements) ───────────────────────────────────────────
$sqlRL = "SELECT COALESCE(SUM(p.montant),0) FROM paiements p
          JOIN locations l ON l.id=p.location_id
          WHERE p.tenant_id=:t" . dateWhere('p.created_at');
if ($filtreVid) $sqlRL .= " AND l.vehicule_id=" . $filtreVid;
$sRL = $db->prepare($sqlRL);
$sRL->bindValue(':t', $tenantId);
bindDate($sRL, $filtreFrom, $filtreTo);
$sRL->execute();
$recLoc = (float)$sRL->fetchColumn();

// ── Recettes taxi (paiements_taxi) ───────────────────────────────────────────
$sqlRT = "SELECT COALESCE(SUM(pt.montant),0) FROM paiements_taxi pt
          JOIN taximetres tx ON tx.id=pt.taximetre_id
          WHERE pt.tenant_id=:t AND pt.statut_jour='paye'" . dateWhere('pt.date_paiement');
if ($filtreVid) $sqlRT .= " AND tx.vehicule_id=" . $filtreVid;
$sRT = $db->prepare($sqlRT);
$sRT->bindValue(':t', $tenantId);
bindDate($sRT, $filtreFrom, $filtreTo);
$sRT->execute();
$recTaxi = (float)$sRT->fetchColumn();

// ── Dépenses véhicules (charges) ─────────────────────────────────────────────
$sqlDV = "SELECT COALESCE(SUM(montant),0) FROM charges WHERE tenant_id=:t" . dateWhere('date_charge');
if ($filtreVid) $sqlDV .= " AND vehicule_id=" . $filtreVid;
$sDV = $db->prepare($sqlDV);
$sDV->bindValue(':t', $tenantId);
bindDate($sDV, $filtreFrom, $filtreTo);
$sDV->execute();
$depVeh = (float)$sDV->fetchColumn();

// ── Dépenses maintenances ─────────────────────────────────────────────────────
$sqlDM = "SELECT COALESCE(SUM(cout),0) FROM maintenances WHERE tenant_id=:t AND statut='termine'" . dateWhere('date_prevue');
if ($filtreVid) $sqlDM .= " AND vehicule_id=" . $filtreVid;
$sDM = $db->prepare($sqlDM);
$sDM->bindValue(':t', $tenantId);
bindDate($sDM, $filtreFrom, $filtreTo);
$sDM->execute();
$depMaint = (float)$sDM->fetchColumn();

// ── Contraventions taxi ───────────────────────────────────────────────────────
$depContr = 0;
try {
    $sqlCT = "SELECT COALESCE(SUM(ct.montant),0) FROM contraventions_taxi ct
              JOIN taximetres tx ON tx.id=ct.taximetre_id
              WHERE ct.tenant_id=:t" . dateWhere('ct.date_contr');
    if ($filtreVid) $sqlCT .= " AND tx.vehicule_id=" . $filtreVid;
    $sCT = $db->prepare($sqlCT);
    $sCT->bindValue(':t', $tenantId);
    bindDate($sCT, $filtreFrom, $filtreTo);
    $sCT->execute();
    $depContr = (float)$sCT->fetchColumn();
} catch (Throwable $e) {}

// ── Dépenses entreprise ───────────────────────────────────────────────────────
$depEntreprise = 0;
try {
    $sqlDE = "SELECT COALESCE(SUM(montant),0) FROM depenses_entreprise WHERE tenant_id=:t" . dateWhere('date_depense');
    $sDE = $db->prepare($sqlDE);
    $sDE->bindValue(':t', $tenantId);
    bindDate($sDE, $filtreFrom, $filtreTo);
    $sDE->execute();
    $depEntreprise = (float)$sDE->fetchColumn();
} catch (Throwable $e) {}

// ── KPIs agrégés ──────────────────────────────────────────────────────────────
$totalRec = $recLoc + $recTaxi;
$totalDep = $depVeh + $depMaint + $depContr + $depEntreprise;
$benefice = $totalRec - $totalDep;
$marge    = $totalRec > 0 ? round($benefice / $totalRec * 100, 1) : 0;

// Capital investi (tous véhicules)
$stmtCap = $db->prepare("SELECT COALESCE(SUM(capital_investi),0) FROM vehicules WHERE tenant_id=?");
$stmtCap->execute([$tenantId]);
$totalCapital = (float)$stmtCap->fetchColumn();
$roi = $totalCapital > 0 ? round($benefice / $totalCapital * 100, 1) : 0;

// ── ROI par véhicule ──────────────────────────────────────────────────────────
$vehiculesRoi = $finance->getRentabiliteParVehicule($tenantId);

// ── Évolution mensuelle 12 mois ───────────────────────────────────────────────
// Recettes locations par mois
$sCAM = $db->prepare("SELECT DATE_FORMAT(p.created_at,'%Y-%m') m, COALESCE(SUM(p.montant),0) v
    FROM paiements p JOIN locations l ON l.id=p.location_id
    WHERE p.tenant_id=? AND p.created_at >= DATE_SUB(CURDATE(),INTERVAL 12 MONTH)
    " . ($filtreVid ? "AND l.vehicule_id=$filtreVid" : "") . " GROUP BY m");
$sCAM->execute([$tenantId]);
$caLocMois = [];
foreach ($sCAM->fetchAll(PDO::FETCH_ASSOC) as $r) $caLocMois[$r['m']] = (float)$r['v'];

// Recettes taxi par mois
$sTAM = $db->prepare("SELECT DATE_FORMAT(pt.date_paiement,'%Y-%m') m, COALESCE(SUM(pt.montant),0) v
    FROM paiements_taxi pt JOIN taximetres tx ON tx.id=pt.taximetre_id
    WHERE pt.tenant_id=? AND pt.statut_jour='paye' AND pt.date_paiement >= DATE_SUB(CURDATE(),INTERVAL 12 MONTH)
    " . ($filtreVid ? "AND tx.vehicule_id=$filtreVid" : "") . " GROUP BY m");
$sTAM->execute([$tenantId]);
$caTaxiMois = [];
foreach ($sTAM->fetchAll(PDO::FETCH_ASSOC) as $r) $caTaxiMois[$r['m']] = (float)$r['v'];

// Charges par mois
$sCHM = $db->prepare("SELECT DATE_FORMAT(date_charge,'%Y-%m') m, COALESCE(SUM(montant),0) v
    FROM charges WHERE tenant_id=? AND date_charge >= DATE_SUB(CURDATE(),INTERVAL 12 MONTH)
    " . ($filtreVid ? "AND vehicule_id=$filtreVid" : "") . " GROUP BY m");
$sCHM->execute([$tenantId]);
$chMois = [];
foreach ($sCHM->fetchAll(PDO::FETCH_ASSOC) as $r) $chMois[$r['m']] = (float)$r['v'];

// Dépenses entreprise par mois
$deMois = [];
try {
    $sDEM = $db->prepare("SELECT DATE_FORMAT(date_depense,'%Y-%m') m, COALESCE(SUM(montant),0) v
        FROM depenses_entreprise WHERE tenant_id=? AND date_depense >= DATE_SUB(CURDATE(),INTERVAL 12 MONTH) GROUP BY m");
    $sDEM->execute([$tenantId]);
    foreach ($sDEM->fetchAll(PDO::FETCH_ASSOC) as $r) $deMois[$r['m']] = (float)$r['v'];
} catch (Throwable $e) {}

// Tableau 12 mois
$perfMensuelle = [];
$moisLabels = $moisRecArr = $moisDepArr = $moisBenArr = [];
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $ca = ($caLocMois[$m] ?? 0) + ($caTaxiMois[$m] ?? 0);
    $ch = ($chMois[$m] ?? 0) + ($deMois[$m] ?? 0);
    $joursM = cal_days_in_month(CAL_GREGORIAN, (int)date('m', strtotime("$m-01")), (int)date('Y', strtotime("$m-01")));
    $perfMensuelle[] = [
        'mois'  => date('M Y', strtotime("$m-01")),
        'ca'    => $ca,
        'ch'    => $ch,
        'benef' => $ca - $ch,
        'marge' => $ca > 0 ? round(($ca - $ch) / $ca * 100, 1) : 0,
    ];
    $moisLabels[]  = date('M y', strtotime("$m-01"));
    $moisRecArr[]  = round($ca);
    $moisDepArr[]  = round($ch);
    $moisBenArr[]  = round($ca - $ch);
}

// ── Charges par type ──────────────────────────────────────────────────────────
$sCTY = $db->prepare("SELECT type, COALESCE(SUM(montant),0) total, COUNT(*) nb
    FROM charges WHERE tenant_id=? AND date_charge BETWEEN ? AND ?
    " . ($filtreVid ? "AND vehicule_id=$filtreVid" : "") . " GROUP BY type ORDER BY total DESC");
$sCTY->execute([$tenantId, $filtreFrom, $filtreTo]);
$chargesType = $sCTY->fetchAll(PDO::FETCH_ASSOC);

// Dépenses entreprise par catégorie
$depEntCateg = [];
try {
    $sDEC = $db->prepare("SELECT categorie, COALESCE(SUM(montant),0) total, COUNT(*) nb
        FROM depenses_entreprise WHERE tenant_id=? AND date_depense BETWEEN ? AND ?
        GROUP BY categorie ORDER BY total DESC");
    $sDEC->execute([$tenantId, $filtreFrom, $filtreTo]);
    $depEntCateg = $sDEC->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ── Contraventions détail ─────────────────────────────────────────────────────
$contraventions = [];
try {
    $sContrDet = $db->prepare("SELECT ct.*, tx.nom t_nom, tx.prenom t_prenom, v.nom veh_nom, v.immatriculation
        FROM contraventions_taxi ct
        JOIN taximetres tx ON tx.id=ct.taximetre_id
        JOIN vehicules v ON v.id=tx.vehicule_id
        WHERE ct.tenant_id=? AND ct.date_contr BETWEEN ? AND ?
        " . ($filtreVid ? "AND v.id=$filtreVid" : "") . "
        ORDER BY ct.date_contr DESC LIMIT 20");
    $sContrDet->execute([$tenantId, $filtreFrom, $filtreTo]);
    $contraventions = $sContrDet->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ── Top clients ───────────────────────────────────────────────────────────────
$sTopC = $db->prepare("SELECT c.nom, c.prenom, c.telephone, COUNT(l.id) nb_locs,
    COALESCE(SUM(l.montant_final),0) ca_total, COALESCE(SUM(l.reste_a_payer),0) reste
    FROM locations l JOIN clients c ON c.id=l.client_id
    WHERE l.tenant_id=? AND l.statut IN('en_cours','terminee')
    " . ($filtreVid ? "AND l.vehicule_id=$filtreVid" : "") . "
    GROUP BY c.id ORDER BY ca_total DESC LIMIT 8");
$sTopC->execute([$tenantId]);
$topClients = $sTopC->fetchAll(PDO::FETCH_ASSOC);

// ── Commissions ───────────────────────────────────────────────────────────────
$sComm = $db->prepare("SELECT co.nom, co.prenom, co.commission_pct, COUNT(l.id) nb_locs,
    COALESCE(SUM(l.montant_final),0) ca,
    COALESCE(SUM(l.montant_final*co.commission_pct/100),0) comm_due
    FROM commerciaux co JOIN locations l ON l.commercial_id=co.id
    WHERE co.tenant_id=? AND l.statut IN('en_cours','terminee')
    GROUP BY co.id HAVING comm_due > 0 ORDER BY comm_due DESC");
$sComm->execute([$tenantId]);
$commissionsComm = $sComm->fetchAll(PDO::FETCH_ASSOC);
$totalCommDue = array_sum(array_column($commissionsComm, 'comm_due'));

// ── Créances clients ──────────────────────────────────────────────────────────
$sCreances = $db->prepare("
    SELECT l.id, l.reste_a_payer, l.montant_final, l.date_fin, l.statut_paiement,
           DATEDIFF(CURDATE(), l.date_fin) retard_jours,
           c.nom client_nom, c.prenom client_prenom, c.telephone client_tel, v.nom veh_nom
    FROM locations l JOIN clients c ON c.id=l.client_id JOIN vehicules v ON v.id=l.vehicule_id
    WHERE l.tenant_id=? AND l.reste_a_payer>0 AND l.statut!='annulee'
    ORDER BY l.reste_a_payer DESC LIMIT 15");
$sCreances->execute([$tenantId]);
$creances = $sCreances->fetchAll(PDO::FETCH_ASSOC);
$totalCreances = array_sum(array_column($creances, 'reste_a_payer'));

// ── Dettes taximantres ────────────────────────────────────────────────────────
$dettesChauf = []; $totalDettes = 0; $totalContrDues = 0;
try {
    $sDettes = $db->prepare("
        SELECT tx.id, tx.nom, tx.prenom, v.nom veh_nom,
               COALESCE(SUM(CASE WHEN pt.statut_jour='non_paye' THEN pt.montant ELSE 0 END),0) dette,
               COUNT(CASE WHEN pt.statut_jour='non_paye' THEN 1 END) nb_jours_impaye,
               COALESCE(SUM(ct.montant),0) contraventions_total
        FROM taximetres tx
        LEFT JOIN paiements_taxi pt ON pt.taximetre_id=tx.id AND pt.tenant_id=tx.tenant_id
        LEFT JOIN vehicules v ON v.id=tx.vehicule_id
        LEFT JOIN contraventions_taxi ct ON ct.taximetre_id=tx.id AND ct.statut='impayee'
        WHERE tx.tenant_id=?
        GROUP BY tx.id HAVING (dette > 0 OR contraventions_total > 0)
        ORDER BY dette DESC");
    $sDettes->execute([$tenantId]);
    $dettesChauf = $sDettes->fetchAll(PDO::FETCH_ASSOC);
    $totalDettes = array_sum(array_column($dettesChauf, 'dette'));
    $totalContrDues = array_sum(array_column($dettesChauf, 'contraventions_total'));
} catch (Throwable $e) {}

// ── Maintenances planifiées ───────────────────────────────────────────────────
$sMaintPlan = $db->prepare("
    SELECT m.type, m.cout, m.km_prevu, m.date_prevue, m.technicien,
           v.nom veh_nom, v.immatriculation, v.kilometrage_actuel
    FROM maintenances m JOIN vehicules v ON v.id=m.vehicule_id
    WHERE m.tenant_id=? AND m.statut IN('planifie','en_cours')
    ORDER BY m.date_prevue ASC LIMIT 10");
$sMaintPlan->execute([$tenantId]);
$maintPlanifiees = $sMaintPlan->fetchAll(PDO::FETCH_ASSOC);
$totalCoutMaint  = array_sum(array_column($maintPlanifiees, 'cout'));

// ── Alertes documents ─────────────────────────────────────────────────────────
$sAlDocs = $db->prepare("SELECT nom, immatriculation, date_expiration_assurance, date_expiration_vignette
    FROM vehicules WHERE tenant_id=?
    AND (date_expiration_assurance <= DATE_ADD(CURDATE(),INTERVAL 30 DAY)
      OR date_expiration_vignette  <= DATE_ADD(CURDATE(),INTERVAL 30 DAY))
    ORDER BY LEAST(COALESCE(date_expiration_assurance,'9999-12-31'),COALESCE(date_expiration_vignette,'9999-12-31'))");
$sAlDocs->execute([$tenantId]);
$alertesDocs = $sAlDocs->fetchAll(PDO::FETCH_ASSOC);

// ── Taux occupation 30 jours ──────────────────────────────────────────────────
$sOcc = $db->prepare("SELECT vehicule_id, COALESCE(SUM(nombre_jours),0) jours
    FROM locations WHERE tenant_id=? AND statut IN('en_cours','terminee')
    AND date_debut >= DATE_SUB(CURDATE(),INTERVAL 30 DAY) GROUP BY vehicule_id");
$sOcc->execute([$tenantId]);
$occupMap = [];
foreach ($sOcc->fetchAll(PDO::FETCH_ASSOC) as $r) $occupMap[$r['vehicule_id']] = min(30,(int)$r['jours']);
$tauxOccGlobal = $nbVehicules > 0 ? round(array_sum($occupMap) / (max(1,$nbVehicules)*30) * 100) : 0;

// ── Stats stats locations ──────────────────────────────────────────────────────
$sStatL = $db->prepare("SELECT COUNT(*) nb, COALESCE(AVG(nombre_jours),0) duree_moy,
    COALESCE(AVG(prix_par_jour),0) prix_moy, COALESCE(AVG(montant_final),0) montant_moy
    FROM locations WHERE tenant_id=? AND statut IN('en_cours','terminee')");
$sStatL->execute([$tenantId]);
$statLoc = $sStatL->fetch(PDO::FETCH_ASSOC);

// ── Dépenses entreprise liste ─────────────────────────────────────────────────
$depEntListe = [];
try {
    $sDepEnt = $db->prepare("SELECT de.*, u.nom created_nom FROM depenses_entreprise de
        LEFT JOIN users u ON u.id=de.created_by
        WHERE de.tenant_id=? AND de.date_depense BETWEEN ? AND ?
        ORDER BY de.date_depense DESC LIMIT 20");
    $sDepEnt->execute([$tenantId, $filtreFrom, $filtreTo]);
    $depEntListe = $sDepEnt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ── Caisse config ─────────────────────────────────────────────────────────────
$sCaisse = $db->prepare("SELECT * FROM caisse_config WHERE tenant_id=?");
$sCaisse->execute([$tenantId]);
$caisse = $sCaisse->fetch(PDO::FETCH_ASSOC);
$soldeInitial = $caisse ? (float)$caisse['solde_initial'] : 0;
$soldeCaisse  = $soldeInitial + $totalRec - $totalDep;

$pageTitle  = 'Rentabilité & Analyses';
$activePage = 'finances';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
.rnt-grid5{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px}
.rnt-grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
.rnt-grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}
.rnt-grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
@media(max-width:1100px){.rnt-grid5{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){.rnt-grid5,.rnt-grid4,.rnt-grid3{grid-template-columns:1fr 1fr}.rnt-grid2{grid-template-columns:1fr}}
@media(max-width:600px){.rnt-grid5,.rnt-grid4,.rnt-grid3,.rnt-grid2{grid-template-columns:1fr}}

.kpi{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;position:relative;overflow:hidden}
.kpi .ki{position:absolute;right:10px;top:10px;font-size:1.8rem;opacity:.07}
.kpi .kl{font-size:.66rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;font-weight:700}
.kpi .kv{font-size:1.2rem;font-weight:800;margin:4px 0 2px;color:#0f172a}
.kpi .ks{font-size:.7rem;color:#94a3b8}
.kpi.primary{border-left:3px solid #0d9488}.kpi.success{border-left:3px solid #16a34a}
.kpi.danger{border-left:3px solid #dc2626}.kpi.warning{border-left:3px solid #d97706}
.kpi.info{border-left:3px solid #0891b2}.kpi.purple{border-left:3px solid #7c3aed}

.sec-title{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin:18px 0 8px;display:flex;align-items:center;gap:6px}
.sec-title::after{content:'';flex:1;height:1px;background:#e2e8f0}

.filter-bar{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.filter-bar label{font-size:.72rem;color:#64748b;font-weight:600}
.filter-bar input,.filter-bar select{border:1px solid #e2e8f0;border-radius:6px;padding:5px 9px;font-size:.78rem;background:#f8fafc}
.shortcut-btn{padding:4px 10px;border-radius:5px;font-size:.72rem;font-weight:600;border:1px solid #e2e8f0;background:#f8fafc;cursor:pointer;color:#475569;transition:all .2s}
.shortcut-btn:hover,.shortcut-btn.active{background:#0d9488;color:#fff;border-color:#0d9488}

.tbl-compact{font-size:.78rem;width:100%;border-collapse:collapse}
.tbl-compact th{font-size:.66rem;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;font-weight:700;padding:8px 10px;text-align:left;border-bottom:2px solid #e2e8f0}
.tbl-compact td{padding:7px 10px;border-bottom:1px solid #f1f5f9;color:#374151}
.tbl-compact tr:last-child td{border-bottom:none}
.tbl-compact tbody tr:hover{background:#f8fafc}

.chart-bar-wrap{display:flex;align-items:flex-end;gap:4px;height:140px;padding:0 4px}
.chart-bar-group{display:flex;gap:2px;align-items:flex-end;flex:1}
.chart-bar{border-radius:3px 3px 0 0;min-width:8px;transition:opacity .2s;cursor:pointer;position:relative}
.chart-bar:hover{opacity:.8}
.chart-bar-label{font-size:.55rem;color:#94a3b8;text-align:center;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chart-x-labels{display:flex;gap:4px;padding:0 4px}
.chart-x-labels span{flex:1;font-size:.55rem;color:#94a3b8;text-align:center}

.badge-sm{display:inline-block;padding:2px 7px;border-radius:99px;font-size:.65rem;font-weight:700}
.badge-sm.green{background:#dcfce7;color:#16a34a}
.badge-sm.red{background:#fee2e2;color:#dc2626}
.badge-sm.yellow{background:#fef9c3;color:#b45309}
.badge-sm.blue{background:#dbeafe;color:#0d9488}

.prog-bar{height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;margin-top:4px}
.prog-fill{height:6px;border-radius:3px}

.albox{border-radius:8px;padding:10px 14px;display:flex;gap:10px;align-items:flex-start;margin-bottom:6px}
.albox.warn{background:#fff7ed;border:1px solid #fed7aa}
.albox.err{background:#fff1f2;border:1px solid #fecdd3}
.albox.info{background:#eff6ff;border:1px solid #bfdbfe}
.albox .alt{font-weight:700;font-size:.82rem}
.albox .als{font-size:.75rem;margin-top:2px;color:#64748b}

.tab-nav{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:16px;overflow-x:auto}
.tab-btn{padding:9px 16px;font-size:.78rem;font-weight:600;color:#64748b;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;transition:all .2s}
.tab-btn.active{color:#0d9488;border-bottom-color:#0d9488}
.tab-panel{display:none}.tab-panel.active{display:block}

@media(max-width:768px){
    .page-header{flex-direction:column;align-items:flex-start!important;gap:10px}
    .filter-bar{flex-direction:column;align-items:stretch}
    .filter-bar input,.filter-bar select{width:100%}
    .tbl-compact thead{display:none}
    .tbl-compact tr{display:block;border:1px solid #e2e8f0;border-radius:8px;padding:10px;margin-bottom:10px;background:#fff}
    .tbl-compact td{display:flex;justify-content:space-between;padding:4px 0;border:none}
    .tbl-compact td::before{content:attr(data-label);font-weight:600;color:#64748b;font-size:.72rem}
    .tab-nav{overflow-x:auto}
    .tab-btn{padding:8px 12px;font-size:.72rem}
    .chart-bar-wrap{height:100px}
    .albox{flex-direction:column}
}
</style>

<div class="page-header" style="margin-bottom:14px">
    <div>
        <h1 style="font-size:1.2rem;font-weight:800;margin:0">📊 Rentabilité & Analyses</h1>
        <p class="page-subtitle">Tableau de bord financier complet</p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'excel'])) ?>"
           class="btn btn-success btn-sm">⬇ Export Excel</a>
        <button onclick="window.print()" class="btn btn-secondary btn-sm">🖨 Imprimer</button>
    </div>
</div>

<?= renderFlashes() ?>

<!-- ── FILTRES ─────────────────────────────────────────────────────────────── -->
<form method="GET" class="filter-bar">
    <div>
        <label>Véhicule</label><br>
        <select name="vehicule_id" onchange="this.form.submit()">
            <option value="0">Tous les véhicules</option>
            <?php foreach ($vehiculesFiltres as $v): ?>
            <option value="<?= $v['id'] ?>" <?= $filtreVid==$v['id']?'selected':'' ?>>
                <?= sanitize($v['nom']) ?> — <?= sanitize($v['immatriculation']) ?>
            </option>
            <?php endforeach ?>
        </select>
    </div>
    <div>
        <label>Du</label><br>
        <input type="date" name="from" value="<?= htmlspecialchars($filtreFrom) ?>">
    </div>
    <div>
        <label>Au</label><br>
        <input type="date" name="to"   value="<?= htmlspecialchars($filtreTo) ?>">
    </div>
    <div>
        <label>Mois</label><br>
        <input type="month" name="mois" value="<?= htmlspecialchars($filtreMois) ?>">
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Filtrer</button>
    <a href="?" class="btn btn-secondary btn-sm">Reset</a>
    <div style="display:flex;gap:4px;flex-wrap:wrap">
        <?php
        $shortcuts = [
            'Ce mois'    => ['from'=>date('Y-m-01'), 'to'=>date('Y-m-t')],
            'Mois préc.' => ['from'=>date('Y-m-01',strtotime('first day of last month')), 'to'=>date('Y-m-t',strtotime('last day of last month'))],
            '3 mois'     => ['from'=>date('Y-m-01',strtotime('-2 months')), 'to'=>date('Y-m-t')],
            '6 mois'     => ['from'=>date('Y-m-01',strtotime('-5 months')), 'to'=>date('Y-m-t')],
            'Cette année'=> ['from'=>date('Y-01-01'), 'to'=>date('Y-12-31')],
        ];
        foreach ($shortcuts as $label => $dates):
            $url = '?' . http_build_query(array_merge(['vehicule_id'=>$filtreVid], $dates));
            $active = ($filtreFrom===$dates['from'] && $filtreTo===$dates['to']) ? 'active' : '';
        ?>
        <a href="<?= $url ?>" class="shortcut-btn <?= $active ?>"><?= $label ?></a>
        <?php endforeach ?>
    </div>
</form>

<!-- ── KPIs PRINCIPAUX ────────────────────────────────────────────────────── -->
<div class="sec-title">💰 Vue d'ensemble — <?= date('d/m/Y',strtotime($filtreFrom)) ?> → <?= date('d/m/Y',strtotime($filtreTo)) ?></div>
<div class="rnt-grid5">
    <div class="kpi primary">
        <span class="ki">💵</span>
        <div class="kl">Recettes locations</div>
        <div class="kv"><?= formatMoney($recLoc) ?></div>
        <div class="ks">Paiements encaissés</div>
    </div>
    <div class="kpi success">
        <span class="ki">🚖</span>
        <div class="kl">Recettes taxi</div>
        <div class="kv"><?= formatMoney($recTaxi) ?></div>
        <div class="ks">Versements chauffeurs</div>
    </div>
    <div class="kpi danger">
        <span class="ki">📉</span>
        <div class="kl">Total dépenses</div>
        <div class="kv"><?= formatMoney($totalDep) ?></div>
        <div class="ks">Véh. + Maint. + Entrep.</div>
    </div>
    <div class="kpi <?= $benefice >= 0 ? 'success' : 'danger' ?>">
        <span class="ki">📊</span>
        <div class="kl">Bénéfice net</div>
        <div class="kv"><?= formatMoney($benefice) ?></div>
        <div class="ks">Marge <?= $marge ?>% · ROI <?= $roi ?>%</div>
    </div>
    <div class="kpi <?= $soldeCaisse >= 0 ? 'info' : 'danger' ?>">
        <span class="ki">🏦</span>
        <div class="kl">Solde caisse</div>
        <div class="kv"><?= formatMoney($soldeCaisse) ?></div>
        <div class="ks">Init. <?= formatMoney($soldeInitial) ?></div>
    </div>
</div>

<!-- KPIs secondaires -->
<div class="rnt-grid4" style="margin-bottom:16px">
    <div class="kpi warning">
        <span class="ki">⚠️</span>
        <div class="kl">Créances clients</div>
        <div class="kv text-danger"><?= formatMoney($totalCreances) ?></div>
        <div class="ks"><?= count($creances) ?> locations impayées</div>
    </div>
    <div class="kpi danger">
        <span class="ki">🚗</span>
        <div class="kl">Dettes taximantres</div>
        <div class="kv text-danger"><?= formatMoney($totalDettes) ?></div>
        <div class="ks"><?= count($dettesChauf) ?> chauffeurs en retard</div>
    </div>
    <div class="kpi warning">
        <span class="ki">🚔</span>
        <div class="kl">Contraventions dues</div>
        <div class="kv text-danger"><?= formatMoney($depContr) ?></div>
        <div class="ks"><?= count($contraventions) ?> contraventions période</div>
    </div>
    <div class="kpi purple">
        <span class="ki">🔧</span>
        <div class="kl">Maintenances à venir</div>
        <div class="kv"><?= formatMoney($totalCoutMaint) ?></div>
        <div class="ks"><?= count($maintPlanifiees) ?> planifiées</div>
    </div>
</div>

<!-- ── GRAPHIQUE 12 MOIS ───────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:14px">
    <div class="card-header">
        <span class="card-title">📈 Évolution 12 mois (Recettes vs Dépenses)</span>
    </div>
    <div class="card-body" style="padding:14px">
        <?php
        $maxVal = max(1, max(array_merge($moisRecArr, $moisDepArr)));
        ?>
        <div class="chart-bar-wrap">
            <?php foreach ($perfMensuelle as $i => $pm): ?>
            <?php $hR = round(($pm['ca']/$maxVal)*130); $hD = round(($pm['ch']/$maxVal)*130); ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center">
                <div style="display:flex;gap:2px;align-items:flex-end;height:130px">
                    <div class="chart-bar" style="height:<?= max(2,$hR) ?>px;width:10px;background:#0d9488"
                         title="Recettes: <?= formatMoney($pm['ca']) ?>"></div>
                    <div class="chart-bar" style="height:<?= max(2,$hD) ?>px;width:10px;background:#dc2626"
                         title="Dépenses: <?= formatMoney($pm['ch']) ?>"></div>
                </div>
                <div style="font-size:.5rem;color:#94a3b8;text-align:center;margin-top:3px"><?= $pm['mois'] ?></div>
            </div>
            <?php endforeach ?>
        </div>
        <div style="display:flex;gap:16px;margin-top:8px;font-size:.72rem;color:#64748b">
            <span><span style="display:inline-block;width:10px;height:10px;background:#0d9488;border-radius:2px;margin-right:4px"></span>Recettes</span>
            <span><span style="display:inline-block;width:10px;height:10px;background:#dc2626;border-radius:2px;margin-right:4px"></span>Dépenses</span>
        </div>
    </div>
</div>

<!-- ── ONGLETS ANALYSES ───────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-body" style="padding:14px 14px 0">
        <div class="tab-nav">
            <button class="tab-btn active" onclick="switchTab('perf')">📅 Performance mensuelle</button>
            <button class="tab-btn" onclick="switchTab('charges')">💸 Dépenses détail</button>
            <button class="tab-btn" onclick="switchTab('creances')">📋 Créances & Dettes</button>
            <button class="tab-btn" onclick="switchTab('contrav')">🚔 Contraventions</button>
            <button class="tab-btn" onclick="switchTab('veh')">🚗 Par véhicule (ROI)</button>
            <button class="tab-btn" onclick="switchTab('clients')">👤 Top clients</button>
            <button class="tab-btn" onclick="switchTab('alertes')">🔔 Alertes</button>
        </div>
    </div>

    <!-- TAB: Performance mensuelle -->
    <div id="tab-perf" class="tab-panel active" style="padding:0 14px 14px">
        <div class="table-responsive">
        <table class="tbl-compact">
            <thead><tr>
                <th>Mois</th><th>Recettes</th><th>Dépenses</th>
                <th>Bénéfice</th><th>Marge</th>
            </tr></thead>
            <tbody>
            <?php $totC=['ca'=>0,'ch'=>0,'benef'=>0];
            foreach ($perfMensuelle as $pm): $totC['ca']+=$pm['ca'];$totC['ch']+=$pm['ch'];$totC['benef']+=$pm['benef']; ?>
            <tr>
                <td><strong><?= $pm['mois'] ?></strong></td>
                <td style="color:#16a34a;font-weight:600"><?= formatMoney($pm['ca']) ?></td>
                <td style="color:#dc2626"><?= formatMoney($pm['ch']) ?></td>
                <td style="font-weight:700;color:<?= $pm['benef']>=0?'#16a34a':'#dc2626' ?>"><?= formatMoney($pm['benef']) ?></td>
                <td><span class="badge-sm <?= $pm['marge']>=0?'green':'red' ?>"><?= $pm['marge'] ?>%</span></td>
            </tr>
            <?php endforeach ?>
            </tbody>
            <tfoot><tr style="background:#f8fafc;font-weight:700">
                <td>TOTAL</td>
                <td style="color:#16a34a"><?= formatMoney($totC['ca']) ?></td>
                <td style="color:#dc2626"><?= formatMoney($totC['ch']) ?></td>
                <td style="color:<?= $totC['benef']>=0?'#16a34a':'#dc2626' ?>"><?= formatMoney($totC['benef']) ?></td>
                <td>—</td>
            </tr></tfoot>
        </table>
        </div>
    </div>

    <!-- TAB: Charges détail -->
    <div id="tab-charges" class="tab-panel" style="padding:0 14px 14px">
        <div class="rnt-grid2">
            <div>
                <div class="sec-title">Charges véhicules par type</div>
                <?php if ($chargesType): foreach ($chargesType as $ct):
                    $pct = $depVeh>0 ? round($ct['total']/$depVeh*100) : 0; ?>
                <div style="margin-bottom:8px">
                    <div style="display:flex;justify-content:space-between;font-size:.76rem">
                        <span><?= sanitize(ucfirst($ct['type'])) ?> (<?= $ct['nb'] ?>)</span>
                        <strong><?= formatMoney($ct['total']) ?> — <?= $pct ?>%</strong>
                    </div>
                    <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct ?>%;background:#0d9488"></div></div>
                </div>
                <?php endforeach; else: echo '<p style="color:#94a3b8;font-size:.78rem">Aucune charge sur la période</p>'; endif ?>
                <div style="font-size:.75rem;color:#64748b;margin-top:6px">
                    Maintenances réalisées : <strong><?= formatMoney($depMaint) ?></strong>
                </div>
            </div>
            <div>
                <div class="sec-title">Dépenses entreprise par catégorie</div>
                <?php if ($depEntCateg): foreach ($depEntCateg as $dc):
                    $pct = $depEntreprise>0 ? round($dc['total']/$depEntreprise*100) : 0; ?>
                <div style="margin-bottom:8px">
                    <div style="display:flex;justify-content:space-between;font-size:.76rem">
                        <span><?= sanitize(ucfirst($dc['categorie'])) ?> (<?= $dc['nb'] ?>)</span>
                        <strong><?= formatMoney($dc['total']) ?> — <?= $pct ?>%</strong>
                    </div>
                    <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct ?>%;background:#7c3aed"></div></div>
                </div>
                <?php endforeach; else: echo '<p style="color:#94a3b8;font-size:.78rem">Aucune dépense entreprise sur la période</p>'; endif ?>
            </div>
        </div>

        <!-- Liste dépenses entreprise -->
        <?php if ($depEntListe): ?>
        <div class="sec-title">Détail dépenses entreprise (période)</div>
        <table class="tbl-compact">
            <thead><tr><th>Date</th><th>Catégorie</th><th>Libellé</th><th>Montant</th><th>Par</th></tr></thead>
            <tbody>
            <?php foreach ($depEntListe as $de): ?>
            <tr>
                <td><?= formatDate($de['date_depense']) ?></td>
                <td><span class="badge-sm blue"><?= sanitize($de['categorie']) ?></span></td>
                <td><?= sanitize($de['libelle']) ?></td>
                <td style="font-weight:600;color:#dc2626"><?= formatMoney($de['montant']) ?></td>
                <td style="color:#94a3b8;font-size:.7rem"><?= sanitize($de['created_nom'] ?? '—') ?></td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        <?php endif ?>
    </div>

    <!-- TAB: Créances & Dettes -->
    <div id="tab-creances" class="tab-panel" style="padding:0 14px 14px">
        <div class="rnt-grid2">
            <div>
                <div class="sec-title">Créances clients — Total : <strong style="color:#dc2626"><?= formatMoney($totalCreances) ?></strong></div>
                <?php if ($creances): ?>
                <table class="tbl-compact">
                    <thead><tr><th>Client</th><th>Véhicule</th><th>Reste à payer</th><th>Retard</th></tr></thead>
                    <tbody>
                    <?php foreach ($creances as $c): ?>
                    <tr>
                        <td><?= sanitize($c['client_nom'].' '.$c['client_prenom']) ?><br>
                            <small style="color:#94a3b8"><?= sanitize($c['client_tel']) ?></small></td>
                        <td><?= sanitize($c['veh_nom']) ?></td>
                        <td style="font-weight:700;color:#dc2626"><?= formatMoney($c['reste_a_payer']) ?></td>
                        <td><?php $j=(int)$c['retard_jours'];
                            echo $j>0?"<span class='badge-sm red'>{$j}j</span>":"<span class='badge-sm green'>En cours</span>" ?></td>
                    </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
                <?php else: echo '<p style="color:#94a3b8;font-size:.78rem">✅ Aucune créance</p>'; endif ?>
            </div>
            <div>
                <div class="sec-title">Dettes taximantres — Total : <strong style="color:#dc2626"><?= formatMoney($totalDettes + $totalContrDues) ?></strong></div>
                <?php if ($dettesChauf): ?>
                <table class="tbl-compact">
                    <thead><tr><th>Chauffeur</th><th>Véhicule</th><th>Jours impayés</th><th>Dette</th><th>Contrav.</th></tr></thead>
                    <tbody>
                    <?php foreach ($dettesChauf as $d): ?>
                    <tr>
                        <td><?= sanitize($d['nom'].' '.$d['prenom']) ?></td>
                        <td><?= sanitize($d['veh_nom'] ?? '—') ?></td>
                        <td><span class="badge-sm red"><?= $d['nb_jours_impaye'] ?> j</span></td>
                        <td style="font-weight:700;color:#dc2626"><?= formatMoney($d['dette']) ?></td>
                        <td style="color:#d97706;font-weight:600"><?= $d['contraventions_total']>0 ? formatMoney($d['contraventions_total']) : '—' ?></td>
                    </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
                <?php else: echo '<p style="color:#94a3b8;font-size:.78rem">✅ Aucune dette</p>'; endif ?>

                <?php if ($commissionsComm): ?>
                <div class="sec-title" style="margin-top:14px">Commissions commerciaux — Total : <strong><?= formatMoney($totalCommDue) ?></strong></div>
                <table class="tbl-compact">
                    <thead><tr><th>Commercial</th><th>Locations</th><th>Commission</th></tr></thead>
                    <tbody>
                    <?php foreach ($commissionsComm as $co): ?>
                    <tr>
                        <td><?= sanitize($co['nom'].' '.$co['prenom']) ?> (<?= $co['commission_pct'] ?>%)</td>
                        <td><?= $co['nb_locs'] ?></td>
                        <td style="font-weight:700"><?= formatMoney($co['comm_due']) ?></td>
                    </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
                <?php endif ?>
            </div>
        </div>
    </div>

    <!-- TAB: Contraventions -->
    <div id="tab-contrav" class="tab-panel" style="padding:0 14px 14px">
        <div style="display:flex;gap:10px;margin-bottom:12px">
            <div class="kpi danger" style="flex:1"><div class="kl">Total contraventions (période)</div><div class="kv"><?= formatMoney($depContr) ?></div></div>
            <div class="kpi warning" style="flex:1"><div class="kl">Non réglées (toutes)</div><div class="kv"><?= formatMoney($totalContrDues) ?></div></div>
            <div class="kpi info" style="flex:1"><div class="kl">Nombre contraventions</div><div class="kv"><?= count($contraventions) ?></div></div>
        </div>
        <?php if ($contraventions): ?>
        <table class="tbl-compact">
            <thead><tr><th>Date</th><th>Chauffeur</th><th>Véhicule</th><th>Montant</th><th>Description</th><th>Statut</th></tr></thead>
            <tbody>
            <?php foreach ($contraventions as $ct): ?>
            <tr>
                <td><?= formatDate($ct['date_contr']) ?></td>
                <td><?= sanitize($ct['t_nom'].' '.$ct['t_prenom']) ?></td>
                <td><?= sanitize($ct['veh_nom']) ?> <small style="color:#94a3b8"><?= sanitize($ct['immatriculation']) ?></small></td>
                <td style="font-weight:700;color:#dc2626"><?= formatMoney($ct['montant']) ?></td>
                <td><?= sanitize($ct['description'] ?? '—') ?></td>
                <td><span class="badge-sm <?= $ct['statut']==='payee'?'green':'red' ?>"><?= $ct['statut'] ?></span></td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        <?php else: echo '<p style="color:#94a3b8;font-size:.78rem">Aucune contravention sur la période</p>'; endif ?>
    </div>

    <!-- TAB: Par véhicule (ROI) -->
    <div id="tab-veh" class="tab-panel" style="padding:0 14px 14px">
        <table class="tbl-compact">
            <thead><tr>
                <th>Véhicule</th><th>Type</th><th>Capital</th>
                <th>Recettes</th><th>Dépenses</th><th>Bénéfice</th><th>ROI</th><th>Taux occ.</th>
            </tr></thead>
            <tbody>
            <?php foreach ($vehiculesRoi as $v):
                $roiV = $v['capital_investi']>0 ? round(($v['revenus_total']-$v['charges_total'])/$v['capital_investi']*100,1) : 0;
                $benV = $v['revenus_total'] - $v['charges_total'];
                $occV = $occupMap[$v['id']] ?? 0;
                $tauxV= round($occV/30*100);
            ?>
            <tr>
                <td><strong><?= sanitize($v['nom']) ?></strong><br>
                    <small style="color:#94a3b8"><?= sanitize($v['immatriculation']) ?></small></td>
                <td><span class="badge-sm blue"><?= $v['type_vehicule'] ?></span></td>
                <td><?= formatMoney($v['capital_investi']) ?></td>
                <td style="color:#16a34a;font-weight:600"><?= formatMoney($v['revenus_total']) ?></td>
                <td style="color:#dc2626"><?= formatMoney($v['charges_total']) ?></td>
                <td style="font-weight:700;color:<?= $benV>=0?'#16a34a':'#dc2626' ?>"><?= formatMoney($benV) ?></td>
                <td><span class="badge-sm <?= $roiV>=0?'green':'red' ?>"><?= $roiV ?>%</span></td>
                <td>
                    <div><?= $tauxV ?>%</div>
                    <div class="prog-bar"><div class="prog-fill" style="width:<?= $tauxV ?>%;background:#0d9488"></div></div>
                </td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <!-- TAB: Top clients -->
    <div id="tab-clients" class="tab-panel" style="padding:0 14px 14px">
        <?php if ($topClients): ?>
        <table class="tbl-compact">
            <thead><tr><th>Client</th><th>Téléphone</th><th>Locations</th><th>CA Total</th><th>Reste dû</th></tr></thead>
            <tbody>
            <?php foreach ($topClients as $i=>$c): ?>
            <tr>
                <td><span style="font-size:.7rem;color:#94a3b8;margin-right:6px">#<?= $i+1 ?></span><?= sanitize($c['nom'].' '.$c['prenom']) ?></td>
                <td style="color:#64748b"><?= sanitize($c['telephone']) ?></td>
                <td><?= $c['nb_locs'] ?></td>
                <td style="font-weight:700"><?= formatMoney($c['ca_total']) ?></td>
                <td style="color:<?= $c['reste']>0?'#dc2626':'#16a34a' ?>;font-weight:600"><?= formatMoney($c['reste']) ?></td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        <?php else: echo '<p style="color:#94a3b8">Aucune location enregistrée</p>'; endif ?>
    </div>

    <!-- TAB: Alertes -->
    <div id="tab-alertes" class="tab-panel" style="padding:0 14px 14px">
        <?php if ($alertesDocs): ?>
        <div class="sec-title">🔴 Documents expirants dans 30 jours</div>
        <?php foreach ($alertesDocs as $al):
            $da = $al['date_expiration_assurance'];
            $dv = $al['date_expiration_vignette'];
            $joursA = $da ? (int)((strtotime($da)-time())/86400) : null;
            $joursV = $dv ? (int)((strtotime($dv)-time())/86400) : null;
        ?>
        <div class="albox <?= (($joursA!==null&&$joursA<0)||($joursV!==null&&$joursV<0)) ? 'err' : 'warn' ?>">
            <span>⚠️</span>
            <div>
                <div class="alt"><?= sanitize($al['nom']) ?> — <?= sanitize($al['immatriculation']) ?></div>
                <?php if ($joursA!==null): ?>
                <div class="als">Assurance : <?= $da ?> (<?= $joursA>=0?"dans $joursA j":"Expirée depuis ".abs($joursA)." j" ?>)</div>
                <?php endif ?>
                <?php if ($joursV!==null): ?>
                <div class="als">Vignette : <?= $dv ?> (<?= $joursV>=0?"dans $joursV j":"Expirée depuis ".abs($joursV)." j" ?>)</div>
                <?php endif ?>
            </div>
        </div>
        <?php endforeach ?>
        <?php else: ?>
        <div class="albox info"><span>✅</span><div class="alt">Tous les documents sont valides (30 prochains jours)</div></div>
        <?php endif ?>

        <?php if ($maintPlanifiees): ?>
        <div class="sec-title" style="margin-top:14px">🔧 Maintenances planifiées</div>
        <table class="tbl-compact">
            <thead><tr><th>Véhicule</th><th>Type</th><th>Date prévue</th><th>Km prévu</th><th>Km actuel</th><th>Coût</th><th>Technicien</th></tr></thead>
            <tbody>
            <?php foreach ($maintPlanifiees as $m):
                $urgent = $m['km_prevu'] && $m['kilometrage_actuel'] && ($m['km_prevu'] - $m['kilometrage_actuel']) < 500;
            ?>
            <tr style="<?= $urgent ? 'background:#fff1f2' : '' ?>">
                <td><?= sanitize($m['veh_nom']) ?> <small style="color:#94a3b8"><?= sanitize($m['immatriculation']) ?></small></td>
                <td><?= sanitize($m['type']) ?></td>
                <td><?= $m['date_prevue'] ? formatDate($m['date_prevue']) : '—' ?></td>
                <td><?= $m['km_prevu'] ? number_format($m['km_prevu']).' km' : '—' ?></td>
                <td><?= $m['kilometrage_actuel'] ? number_format($m['kilometrage_actuel']).' km' : '—' ?></td>
                <td><?= $m['cout'] ? formatMoney($m['cout']) : '—' ?></td>
                <td><?= sanitize($m['technicien'] ?? '—') ?></td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        <?php endif ?>
    </div>
</div>

<script>
function switchTab(name) {
    document.querySelectorAll('.tab-btn').forEach((b,i) => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    event.currentTarget.classList.add('active');
    document.getElementById('tab-'+name).classList.add('active');
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

<?php
// ══════════════════════════════════════════════════════════════════════════════
// EXPORT EXCEL — 6 FEUILLES
// ══════════════════════════════════════════════════════════════════════════════
function exportExcel(PDO $db, int $tenantId, string $from, string $to, int $filtreVid): void {
    $sp = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sp->getProperties()->setTitle('FlotteCar — Rapport Financier')
        ->setCreator('FlotteCar SaaS');

    $FILL_SOLID  = \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID;
    $H_CENTER    = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER;
    $BORDER_THIN = \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN;
    $headerStyle = [
        'font'      => ['bold'=>true,'color'=>['rgb'=>'FFFFFF'],'size'=>10],
        'fill'      => ['fillType'=>$FILL_SOLID,'startColor'=>['rgb'=>'1A56DB']],
        'alignment' => ['horizontal'=>$H_CENTER],
        'borders'   => ['allBorders'=>['borderStyle'=>$BORDER_THIN,'color'=>['rgb'=>'AAAAAA']]],
    ];
    $moneyFmt = '#,##0 "FCFA"';
    $dateFmt  = 'DD/MM/YYYY';

    // ── Feuille 1 : Résumé ────────────────────────────────────────────────────
    $ws1 = $sp->getActiveSheet()->setTitle('Résumé');
    $ws1->setCellValue('A1', 'RAPPORT FINANCIER — FlotteCar');
    $ws1->mergeCells('A1:D1');
    $ws1->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('1A56DB');
    $ws1->setCellValue('A2', "Période : $from → $to");
    $ws1->mergeCells('A2:D2');

    $rows = [
        ['Indicateur','Valeur','',''],
        ['Recettes Locations', null, '', ''],
        ['Recettes Taxi', null, '', ''],
        ['Total Recettes', null, '', ''],
        ['Charges Véhicules', null, '', ''],
        ['Maintenances', null, '', ''],
        ['Contraventions', null, '', ''],
        ['Dépenses Entreprise', null, '', ''],
        ['Total Dépenses', null, '', ''],
        ['Bénéfice Net', null, '', ''],
    ];

    // Recalcul pour export
    $recLoc = recQuery($db, "SELECT COALESCE(SUM(p.montant),0) FROM paiements p JOIN locations l ON l.id=p.location_id WHERE p.tenant_id=? AND p.created_at BETWEEN ? AND ?", [$tenantId,$from,$to]);
    $recTaxi= recQuery($db, "SELECT COALESCE(SUM(pt.montant),0) FROM paiements_taxi pt JOIN taximetres tx ON tx.id=pt.taximetre_id WHERE pt.tenant_id=? AND pt.statut_jour='paye' AND pt.date_paiement BETWEEN ? AND ?", [$tenantId,$from,$to]);
    $depVeh = recQuery($db, "SELECT COALESCE(SUM(montant),0) FROM charges WHERE tenant_id=? AND date_charge BETWEEN ? AND ?", [$tenantId,$from,$to]);
    $depMnt = recQuery($db, "SELECT COALESCE(SUM(cout),0) FROM maintenances WHERE tenant_id=? AND statut='termine' AND date_prevue BETWEEN ? AND ?", [$tenantId,$from,$to]);
    try { $depCt  = recQuery($db, "SELECT COALESCE(SUM(ct.montant),0) FROM contraventions_taxi ct JOIN taximetres tx ON tx.id=ct.taximetre_id WHERE ct.tenant_id=? AND ct.date_contr BETWEEN ? AND ?", [$tenantId,$from,$to]); } catch (Throwable $e) { $depCt = 0; }
    try { $depEnt = recQuery($db, "SELECT COALESCE(SUM(montant),0) FROM depenses_entreprise WHERE tenant_id=? AND date_depense BETWEEN ? AND ?", [$tenantId,$from,$to]); } catch (Throwable $e) { $depEnt = 0; }
    $totRec = $recLoc+$recTaxi;
    $totDep = $depVeh+$depMnt+$depCt+$depEnt;

    $data = [
        ['Recettes Locations', $recLoc],['Recettes Taxi', $recTaxi],
        ['Total Recettes', $totRec],['Charges Véhicules', $depVeh],
        ['Maintenances', $depMnt],['Contraventions', $depCt],
        ['Dépenses Entreprise', $depEnt],['Total Dépenses', $totDep],
        ['Bénéfice Net', $totRec-$totDep],
    ];
    $ws1->setCellValue('A4','Indicateur'); $ws1->setCellValue('B4','Montant (FCFA)');
    $ws1->getStyle('A4:B4')->applyFromArray($headerStyle);
    foreach ($data as $i=>$d) {
        $ws1->setCellValue('A'.($i+5), $d[0]);
        $ws1->setCellValue('B'.($i+5), $d[1]);
        $ws1->getStyle('B'.($i+5))->getNumberFormat()->setFormatCode($moneyFmt);
    }
    foreach (range('A','B') as $col) $ws1->getColumnDimension($col)->setAutoSize(true);

    // Helper: écrire une ligne à partir d'un tableau de valeurs (col 1-based, row)
    $writeRow = function(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, int $row, array $vals) {
        foreach ($vals as $i => $v) {
            $ws->setCellValue([$i+1, $row], $v ?? '');
        }
    };
    // Helper: autosize colonnes A→lettre($n)
    $autosize = function(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, int $n) {
        for ($c = 1; $c <= $n; $c++) {
            $ws->getColumnDimension(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c)
            )->setAutoSize(true);
        }
    };

    // ── Feuille 2 : Recettes Locations ───────────────────────────────────────
    $ws2 = $sp->createSheet()->setTitle('Recettes Locations');
    $writeRow($ws2, 1, ['ID','Date','Client','Véhicule','Montant','Mode','Référence']);
    $ws2->getStyle('A1:G1')->applyFromArray($headerStyle);
    $sL = $db->prepare("SELECT p.id, p.created_at, CONCAT(c.nom,' ',c.prenom) client, v.nom veh,
        p.montant, p.mode_paiement, p.reference
        FROM paiements p JOIN locations l ON l.id=p.location_id
        JOIN clients c ON c.id=l.client_id JOIN vehicules v ON v.id=l.vehicule_id
        WHERE p.tenant_id=? AND p.created_at BETWEEN ? AND ?
        ORDER BY p.created_at DESC");
    $sL->execute([$tenantId,$from,$to]);
    $row=2;
    foreach ($sL->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $writeRow($ws2, $row, [$r['id'], date('d/m/Y',strtotime($r['created_at'])), $r['client'], $r['veh'], (float)$r['montant'], $r['mode_paiement'], $r['reference']]);
        $ws2->getStyle('E'.$row)->getNumberFormat()->setFormatCode($moneyFmt);
        $row++;
    }
    $autosize($ws2, 7);

    // ── Feuille 3 : Recettes Taxi ────────────────────────────────────────────
    $ws3 = $sp->createSheet()->setTitle('Recettes Taxi');
    $writeRow($ws3, 1, ['Date','Chauffeur','Véhicule','Montant','Mode','Statut','Notes']);
    $ws3->getStyle('A1:G1')->applyFromArray($headerStyle);
    $sT = $db->prepare("SELECT pt.date_paiement, CONCAT(tx.nom,' ',tx.prenom) chauffeur,
        v.nom veh, pt.montant, pt.mode_paiement, pt.statut_jour, pt.notes
        FROM paiements_taxi pt JOIN taximetres tx ON tx.id=pt.taximetre_id
        JOIN vehicules v ON v.id=tx.vehicule_id
        WHERE pt.tenant_id=? AND pt.date_paiement BETWEEN ? AND ?
        ORDER BY pt.date_paiement DESC");
    $sT->execute([$tenantId,$from,$to]);
    $row=2;
    foreach ($sT->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $writeRow($ws3, $row, [date('d/m/Y',strtotime($r['date_paiement'])), $r['chauffeur'], $r['veh'], (float)$r['montant'], $r['mode_paiement'], $r['statut_jour'], $r['notes']]);
        $ws3->getStyle('D'.$row)->getNumberFormat()->setFormatCode($moneyFmt);
        $row++;
    }
    $autosize($ws3, 7);

    // ── Feuille 4 : Dépenses ─────────────────────────────────────────────────
    $ws4 = $sp->createSheet()->setTitle('Dépenses');
    $writeRow($ws4, 1, ['Date','Type','Libellé','Véhicule','Montant','Notes']);
    $ws4->getStyle('A1:F1')->applyFromArray($headerStyle);
    $sDep = $db->prepare("SELECT c.date_charge, c.type, c.libelle, v.nom veh, c.montant, c.notes
        FROM charges c JOIN vehicules v ON v.id=c.vehicule_id
        WHERE c.tenant_id=? AND c.date_charge BETWEEN ? AND ?
        ORDER BY c.date_charge DESC");
    $sDep->execute([$tenantId,$from,$to]);
    $row=2;
    foreach ($sDep->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $writeRow($ws4, $row, [date('d/m/Y',strtotime($r['date_charge'])), $r['type'], $r['libelle'], $r['veh'], (float)$r['montant'], $r['notes']]);
        $ws4->getStyle('E'.$row)->getNumberFormat()->setFormatCode($moneyFmt);
        $row++;
    }
    try {
        $sDE2 = $db->prepare("SELECT date_depense, categorie, libelle, montant, notes FROM depenses_entreprise WHERE tenant_id=? AND date_depense BETWEEN ? AND ?");
        $sDE2->execute([$tenantId,$from,$to]);
        foreach ($sDE2->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $writeRow($ws4, $row, [date('d/m/Y',strtotime($r['date_depense'])), 'entreprise/'.$r['categorie'], $r['libelle'], 'Entreprise', (float)$r['montant'], $r['notes']]);
            $ws4->getStyle('E'.$row)->getNumberFormat()->setFormatCode($moneyFmt);
            $row++;
        }
    } catch (Throwable $e) {}
    $autosize($ws4, 6);

    // ── Feuille 5 : Contraventions ────────────────────────────────────────────
    $ws5 = $sp->createSheet()->setTitle('Contraventions');
    $writeRow($ws5, 1, ['Date','Chauffeur','Véhicule','Montant','Description','Statut']);
    $ws5->getStyle('A1:F1')->applyFromArray($headerStyle);
    try {
    $sCv = $db->prepare("SELECT ct.date_contr, CONCAT(tx.nom,' ',tx.prenom) chauffeur,
        v.nom veh, ct.montant, ct.description, ct.statut
        FROM contraventions_taxi ct JOIN taximetres tx ON tx.id=ct.taximetre_id
        JOIN vehicules v ON v.id=tx.vehicule_id
        WHERE ct.tenant_id=? AND ct.date_contr BETWEEN ? AND ?");
    $sCv->execute([$tenantId,$from,$to]);
    $row=2;
    foreach ($sCv->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $writeRow($ws5, $row, [date('d/m/Y',strtotime($r['date_contr'])), $r['chauffeur'], $r['veh'], (float)$r['montant'], $r['description'], $r['statut']]);
        $ws5->getStyle('D'.$row)->getNumberFormat()->setFormatCode($moneyFmt);
        $row++;
    }
    } catch (Throwable $e) { /* table contraventions_taxi peut ne pas exister */ }
    $autosize($ws5, 6);

    // ── Feuille 6 : Par véhicule (ROI) ───────────────────────────────────────
    $ws6 = $sp->createSheet()->setTitle('ROI par véhicule');
    $writeRow($ws6, 1, ['Véhicule','Immatriculation','Type','Capital','Recettes','Dépenses','Bénéfice','ROI %']);
    $ws6->getStyle('A1:H1')->applyFromArray($headerStyle);
    $sROI = $db->prepare("SELECT v.nom, v.immatriculation, v.type_vehicule, v.capital_investi,
        COALESCE((SELECT SUM(p2.montant) FROM paiements p2 JOIN locations l2 ON l2.id=p2.location_id WHERE l2.vehicule_id=v.id AND p2.tenant_id=v.tenant_id),0)
        + COALESCE((SELECT SUM(pt2.montant) FROM paiements_taxi pt2 JOIN taximetres tx2 ON tx2.id=pt2.taximetre_id WHERE tx2.vehicule_id=v.id AND pt2.statut_jour='paye' AND pt2.tenant_id=v.tenant_id),0) revenus,
        COALESCE((SELECT SUM(c2.montant) FROM charges c2 WHERE c2.vehicule_id=v.id AND c2.tenant_id=v.tenant_id),0) charges
        FROM vehicules v WHERE v.tenant_id=? ORDER BY v.nom");
    $sROI->execute([$tenantId]);
    $row=2;
    foreach ($sROI->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $benef = $r['revenus'] - $r['charges'];
        $roi   = $r['capital_investi']>0 ? round($benef/$r['capital_investi']*100,1) : 0;
        $writeRow($ws6, $row, [$r['nom'], $r['immatriculation'], $r['type_vehicule'], (float)$r['capital_investi'], (float)$r['revenus'], (float)$r['charges'], $benef, $roi]);
        foreach (['D','E','F','G'] as $col)
            $ws6->getStyle($col.$row)->getNumberFormat()->setFormatCode($moneyFmt);
        $ws6->getStyle('H'.$row)->getNumberFormat()->setFormatCode('0.0"%"');
        $row++;
    }
    $autosize($ws6, 8);

    // ── Output ────────────────────────────────────────────────────────────────
    $sp->setActiveSheetIndex(0);
    $fname = 'FlotteCar_Rapport_'.date('Y-m-d').'.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$fname.'"');
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp))->save('php://output');
}

function recQuery(PDO $db, string $sql, array $p): float {
    $s = $db->prepare($sql); $s->execute($p); return (float)$s->fetchColumn();
}
