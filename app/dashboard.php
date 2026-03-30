<?php
/**
 * FlotteCar - Dashboard principal (v3 — Teal/Orange redesign)
 */
define('BASE_PATH', dirname(__DIR__));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
require_once BASE_PATH . '/includes/TraccarAPI.php';
requireTenantAuth();
$db = (new Database())->getConnection();
$tenantId = getTenantId();

$today     = date('Y-m-d');
$thisMonth = date('Y-m');

// ─── POST: Paiement rapide ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCSRF();
    if ($_POST['action'] === 'payer_dette_taxi') {
        $taxId   = (int)($_POST['taximetre_id'] ?? 0);
        $montant = (float)cleanNumber($_POST['montant'] ?? 0);
        $mode    = $_POST['mode_paiement'] ?? 'especes';
        $date    = $_POST['date_paiement'] ?? $today;
        if ($taxId && $montant > 0) {
            $chk = $db->prepare("SELECT id FROM taximetres WHERE id=? AND tenant_id=?");
            $chk->execute([$taxId, $tenantId]);
            if ($chk->fetch()) {
                $ins = $db->prepare(
                    "INSERT INTO paiements_taxi (tenant_id, taximetre_id, date_paiement, statut_jour, montant, mode_paiement, notes)
                     VALUES (?, ?, ?, 'paye', ?, ?, 'Paiement rapide depuis dashboard')
                     ON DUPLICATE KEY UPDATE statut_jour='paye', montant=VALUES(montant), mode_paiement=VALUES(mode_paiement)"
                );
                $ins->execute([$tenantId, $taxId, $date, $montant, $mode]);
                $tInfo = $db->prepare("SELECT t.nom, t.prenom FROM taximetres t WHERE t.id=? AND t.tenant_id=?");
                $tInfo->execute([$taxId, $tenantId]);
                $tRow = $tInfo->fetch(PDO::FETCH_ASSOC);
                $tNom = $tRow ? trim(($tRow['nom'] ?? '').' '.($tRow['prenom'] ?? '')) : "Taximantre #$taxId";
                pushNotif($db, $tenantId, 'taxi', "Paiement taxi - $tNom", formatMoney($montant)." percu le ".formatDate($date), BASE_URL."app/taximetres/paiements.php?date=$date");
                setFlash(FLASH_SUCCESS, 'Paiement enregistre.');
            }
        }
        redirect(BASE_URL . 'app/dashboard.php');
    }
    if ($_POST['action'] === 'payer_location') {
        require_once BASE_PATH . '/models/LocationModel.php';
        $locId   = (int)($_POST['location_id'] ?? 0);
        $montant = (float)cleanNumber($_POST['montant'] ?? 0);
        $mode    = $_POST['mode_paiement'] ?? 'especes';
        if ($locId && $montant > 0) {
            $locModel = new LocationModel($db);
            $loc = $db->prepare("SELECT id FROM locations WHERE id=? AND tenant_id=?");
            $loc->execute([$locId, $tenantId]);
            if ($loc->fetch()) {
                $locModel->ajouterPaiement($locId, $tenantId, $montant, $mode);
                pushNotif($db, $tenantId, 'paiement', "Paiement location #$locId", formatMoney($montant)." encaisse", BASE_URL."app/locations/detail.php?id=$locId");
                setFlash(FLASH_SUCCESS, 'Paiement location enregistre.');
            }
        }
        redirect(BASE_URL . 'app/dashboard.php');
    }
}

// ─── 1. Stats vehicules ───────────────────────────────────────────────────────
$vStmt = $db->prepare("SELECT COUNT(*) AS total, SUM(statut='disponible') AS nb_disponible, SUM(statut='loue') AS nb_loue, SUM(statut='maintenance') AS nb_maintenance, SUM(type_vehicule='taxi') AS nb_taxi, SUM(type_vehicule='location') AS nb_location, SUM(type_vehicule='entreprise') AS nb_entreprise FROM vehicules WHERE tenant_id=?");
$vStmt->execute([$tenantId]); $vstats = $vStmt->fetch(PDO::FETCH_ASSOC);

$locStmt = $db->prepare("SELECT COUNT(*) FROM locations WHERE tenant_id=? AND statut='en_cours'");
$locStmt->execute([$tenantId]); $nbLocEnCours = (int)$locStmt->fetchColumn();

$retardStmt = $db->prepare("SELECT COUNT(*) FROM locations WHERE tenant_id=? AND statut='en_cours' AND date_fin<?");
$retardStmt->execute([$tenantId,$today]); $nbLocRetard = (int)$retardStmt->fetchColumn();

$revStmt = $db->prepare("SELECT COALESCE(SUM(p.montant),0) FROM paiements p INNER JOIN locations l ON l.id=p.location_id WHERE l.tenant_id=? AND DATE_FORMAT(p.created_at,'%Y-%m')=?");
$revStmt->execute([$tenantId,$thisMonth]); $revenusMois = (float)$revStmt->fetchColumn();

$taxRevStmt = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM paiements_taxi WHERE tenant_id=? AND statut_jour='paye' AND DATE_FORMAT(date_paiement,'%Y-%m')=?");
$taxRevStmt->execute([$tenantId,$thisMonth]); $revenusTaxiMois = (float)$taxRevStmt->fetchColumn();

$aRecevStmt = $db->prepare("SELECT COALESCE(SUM(reste_a_payer),0) FROM locations WHERE tenant_id=? AND statut_paiement!='solde' AND statut!='annulee'");
$aRecevStmt->execute([$tenantId]); $totalARecevoir = (float)$aRecevStmt->fetchColumn();

$chargesStmt = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM charges WHERE tenant_id=? AND DATE_FORMAT(date_charge,'%Y-%m')=?");
$chargesStmt->execute([$tenantId,$thisMonth]); $chargesMois = (float)$chargesStmt->fetchColumn();

$totalDetteTaxiStmt = $db->prepare("SELECT t.id, t.tarif_journalier, t.date_debut, GREATEST(0,DATEDIFF(CURDATE(),t.date_debut)+1) AS periode, COALESCE(SUM(CASE WHEN pt.statut_jour IN('jour_off','panne','accident','maladie') THEN 1 ELSE 0 END),0) AS jours_off, COALESCE(SUM(CASE WHEN pt.statut_jour='paye' THEN pt.montant ELSE 0 END),0) AS total_percu FROM taximetres t LEFT JOIN paiements_taxi pt ON pt.taximetre_id=t.id AND pt.tenant_id=t.tenant_id WHERE t.tenant_id=? AND t.statut='actif' GROUP BY t.id");
$totalDetteTaxiStmt->execute([$tenantId]);
$totalDetteTaxiGlobale = 0.0;
foreach ($totalDetteTaxiStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $facturables = max(0,(int)$row['periode']-(int)$row['jours_off']);
    $totalDetteTaxiGlobale += max(0,$facturables*(float)$row['tarif_journalier']-(float)$row['total_percu']);
}

$hasTaxi = (int)($vstats['nb_taxi']??0) > 0;
$taxiDettes = [];
if ($hasTaxi) {
    $tdStmt = $db->prepare("SELECT t.id, t.nom, t.prenom, t.telephone, t.tarif_journalier, t.date_debut, t.jour_repos, v.immatriculation, v.nom AS vnom, v.id AS vehicule_id, GREATEST(0,DATEDIFF(CURDATE(),t.date_debut)+1) AS total_jours_periode, COALESCE(SUM(CASE WHEN pt.statut_jour IN('jour_off','panne','accident','maladie') THEN 1 ELSE 0 END),0) AS jours_off, COALESCE(SUM(CASE WHEN pt.statut_jour='paye' THEN pt.montant ELSE 0 END),0) AS total_percu FROM taximetres t INNER JOIN vehicules v ON v.id=t.vehicule_id LEFT JOIN paiements_taxi pt ON pt.taximetre_id=t.id AND pt.tenant_id=t.tenant_id WHERE t.tenant_id=? AND t.statut='actif' GROUP BY t.id ORDER BY t.nom ASC");
    $tdStmt->execute([$tenantId]);
    foreach ($tdStmt->fetchAll(PDO::FETCH_ASSOC) as $tx) {
        $joursFacturables = max(0,(int)$tx['total_jours_periode']-(int)$tx['jours_off']);
        $dette = max(0,$joursFacturables*(float)$tx['tarif_journalier']-(float)$tx['total_percu']);
        $tx['dette'] = $dette; $tx['jours_facturables'] = $joursFacturables;
        if ($dette > 0) $taxiDettes[] = $tx;
    }
    usort($taxiDettes, fn($a,$b) => $b['dette'] <=> $a['dette']);
}

$vehListStmt = $db->prepare("SELECT v.id, v.nom, v.immatriculation, v.marque, v.modele, v.statut, v.type_vehicule, v.date_expiration_assurance, v.date_expiration_vignette, v.kilometrage_actuel, CONCAT(COALESCE(c.prenom,''),' ',COALESCE(c.nom,'')) AS client_nom, l.id AS location_id, l.date_fin AS loc_date_fin, l.reste_a_payer AS loc_reste, CONCAT(COALESCE(tx.prenom,''),' ',COALESCE(tx.nom,'')) AS taxi_nom, tx.id AS taximetre_id FROM vehicules v LEFT JOIN locations l ON l.vehicule_id=v.id AND l.tenant_id=v.tenant_id AND l.statut='en_cours' LEFT JOIN clients c ON c.id=l.client_id LEFT JOIN taximetres tx ON tx.vehicule_id=v.id AND tx.tenant_id=v.tenant_id AND tx.statut='actif' WHERE v.tenant_id=? ORDER BY FIELD(v.statut,'maintenance','loue','disponible','indisponible'), v.nom ASC");
$vehListStmt->execute([$tenantId]); $vehiculesList = $vehListStmt->fetchAll(PDO::FETCH_ASSOC);

$maintStmt = $db->prepare("SELECT m.id, m.type, m.date_prevue, m.km_prevu, v.nom AS vnom, v.immatriculation, v.kilometrage_actuel FROM maintenances m INNER JOIN vehicules v ON v.id=m.vehicule_id WHERE m.tenant_id=? AND m.statut='planifie' AND (m.date_prevue<=CURDATE() OR (m.km_prevu IS NOT NULL AND m.km_prevu<=v.kilometrage_actuel+500)) ORDER BY m.date_prevue ASC LIMIT 5");
$maintStmt->execute([$tenantId]); $maintUrgentes = $maintStmt->fetchAll(PDO::FETCH_ASSOC);

$docStmt = $db->prepare("SELECT id,nom,immatriculation,date_expiration_assurance,date_expiration_vignette FROM vehicules WHERE tenant_id=? AND ((date_expiration_assurance IS NOT NULL AND date_expiration_assurance <= DATE_ADD(CURDATE(),INTERVAL 30 DAY)) OR (date_expiration_vignette IS NOT NULL AND date_expiration_vignette <= DATE_ADD(CURDATE(),INTERVAL 30 DAY))) ORDER BY LEAST(COALESCE(date_expiration_assurance,'9999-12-31'),COALESCE(date_expiration_vignette,'9999-12-31')) ASC LIMIT 10");
$docStmt->execute([$tenantId]); $docsExp = $docStmt->fetchAll(PDO::FETCH_ASSOC);

$locListStmt = $db->prepare("SELECT l.id, l.date_debut, l.date_fin, l.montant_final, l.reste_a_payer, l.statut_paiement, l.nombre_jours, v.immatriculation, v.nom AS vnom, CONCAT(c.prenom,' ',c.nom) AS client_nom FROM locations l INNER JOIN vehicules v ON v.id=l.vehicule_id INNER JOIN clients c ON c.id=l.client_id WHERE l.tenant_id=? AND l.statut='en_cours' ORDER BY l.date_fin ASC");
$locListStmt->execute([$tenantId]); $locationsEnCours = $locListStmt->fetchAll(PDO::FETCH_ASSOC);

$resStmt = $db->prepare("SELECT r.id, r.date_debut, r.date_fin, r.montant_final, r.statut, v.immatriculation, v.nom AS vnom, CONCAT(c.prenom,' ',c.nom) AS client_nom FROM reservations r INNER JOIN vehicules v ON v.id=r.vehicule_id INNER JOIN clients c ON c.id=r.client_id WHERE r.tenant_id=? AND r.statut IN('en_attente','confirmee') AND r.date_debut>=? ORDER BY r.date_debut ASC");
$resStmt->execute([$tenantId,$today]); $reservationsAVenir = $resStmt->fetchAll(PDO::FETCH_ASSOC);

$vtcStmt = $db->prepare("
    SELECT t.id, t.nom, t.prenom, t.telephone, t.tarif_journalier, t.date_debut,
           v.immatriculation, v.nom AS vnom, pt.statut_jour,
           GREATEST(0, DATEDIFF(CURDATE(), t.date_debut) + 1) AS total_jours_periode,
           COALESCE(SUM(CASE WHEN pt2.statut_jour IN('jour_off','panne','accident','maladie') THEN 1 ELSE 0 END), 0) AS jours_off,
           COALESCE(SUM(CASE WHEN pt2.statut_jour = 'paye' THEN pt2.montant ELSE 0 END), 0) AS total_percu
    FROM taximetres t
    INNER JOIN vehicules v ON v.id = t.vehicule_id
    LEFT JOIN paiements_taxi pt ON pt.taximetre_id = t.id AND pt.date_paiement = ?
    LEFT JOIN paiements_taxi pt2 ON pt2.taximetre_id = t.id AND pt2.tenant_id = t.tenant_id
    WHERE t.tenant_id = ? AND t.statut = 'actif'
    GROUP BY t.id
    HAVING (pt.statut_jour IS NULL OR pt.statut_jour NOT IN('paye','jour_off','maladie'))
    ORDER BY total_percu ASC
");
$vtcStmt->execute([$today, $tenantId]);
$vtcNonPayes = [];
foreach ($vtcStmt->fetchAll(PDO::FETCH_ASSOC) as $vtcRow) {
    $joursFacturables = max(0, (int)$vtcRow['total_jours_periode'] - (int)$vtcRow['jours_off']);
    $vtcRow['dette_reelle'] = max(0, $joursFacturables * (float)$vtcRow['tarif_journalier'] - (float)$vtcRow['total_percu']);
    $vtcRow['jours_facturables'] = $joursFacturables;
    $vtcNonPayes[] = $vtcRow;
}

$totalAlertes = count($maintUrgentes) + count($docsExp);

// ─── Comptabilite : solde caisse + depenses entreprise recentes ──────────────
require_once BASE_PATH . '/models/ComptabiliteModel.php';
$compta = new ComptabiliteModel($db);
$caisseData    = $compta->getSoldeCaisse($tenantId);
$soldeCaisse   = $caisseData['solde_actuel'];
$recMois       = $compta->getTotalLocations($tenantId, date('Y-m-01'), date('Y-m-d'))
               + $compta->getTotalTaxi($tenantId, date('Y-m-01'), date('Y-m-d'));
$depMois       = $compta->getTotalChargesVehicules($tenantId, date('Y-m-01'), date('Y-m-d'))
               + $compta->getTotalMaintenances($tenantId, date('Y-m-01'), date('Y-m-d'))
               + $compta->getTotalDepensesEntreprise($tenantId, date('Y-m-01'), date('Y-m-d'));
$resultatMois  = $recMois - $depMois;
$depEntStmt    = $db->prepare("SELECT * FROM depenses_entreprise WHERE tenant_id=? ORDER BY date_depense DESC LIMIT 6");
$depEntStmt->execute([$tenantId]);
$depentreprises = $depEntStmt->fetchAll(PDO::FETCH_ASSOC);

// Prenom utilisateur
$userPrenom = explode(' ', getUserName() ?? 'Utilisateur')[0];

$activePage = 'dashboard';
$pageTitle  = 'Dashboard';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
/* ── Dashboard v7 — Pro Clean Design ───────────────────────────────────── */
@keyframes dbFadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:none} }
@keyframes dbPulse  { 0%,100%{opacity:1} 50%{opacity:.4} }

.main-content { background: #f8f9fb !important; }

/* ── Hero header ───────────────────────────────────────────────────────── */
.db-hero {
    background: linear-gradient(135deg, #0f766e 0%, #115e59 50%, #134e4a 100%);
    border-radius: 18px;
    padding: 28px 32px;
    margin-bottom: 24px;
    color: #fff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
    box-shadow: 0 4px 20px rgba(13,148,136,.2), 0 1px 3px rgba(0,0,0,.1);
    position: relative;
    overflow: hidden;
}
.db-hero::before {
    content:'';position:absolute;right:-40px;top:-40px;width:200px;height:200px;
    background:radial-gradient(circle,rgba(255,255,255,.06) 0%,transparent 70%);
    border-radius:50%;pointer-events:none;
}
.db-hero::after {
    content:'';position:absolute;left:30%;bottom:-30px;width:150px;height:150px;
    background:radial-gradient(circle,rgba(249,115,22,.08) 0%,transparent 70%);
    border-radius:50%;pointer-events:none;
}
.db-hero h1 {
    font-size: 1.4rem;
    font-weight: 700;
    margin: 0;
    color: #fff;
    letter-spacing: -.01em;
}
.db-hero .db-date {
    font-size: .82rem;
    color: rgba(255,255,255,.5);
    margin-top: 4px;
    font-weight: 400;
}
.db-hero .db-header-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
}
.db-alert-pill {
    font-size: .78rem;
    font-weight: 600;
    padding: 7px 14px;
    border-radius: 10px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 200ms ease;
    backdrop-filter: blur(8px);
}
.db-alert-pill:hover { transform: translateY(-1px); }
.db-alert-pill.danger {
    background: rgba(239,68,68,.18);
    color: #fecaca;
    border: 1px solid rgba(239,68,68,.25);
    animation: dbPulse 2.5s ease-in-out infinite;
}
.db-alert-pill.warning {
    background: rgba(245,158,11,.15);
    color: #fde68a;
    border: 1px solid rgba(245,158,11,.25);
}
.db-alert-pill.refresh {
    background: rgba(255,255,255,.08);
    color: rgba(255,255,255,.6);
    border: 1px solid rgba(255,255,255,.12);
}
.db-alert-pill.refresh:hover { background: rgba(255,255,255,.18); color:#fff; }

/* ── KPI Grid ──────────────────────────────────────────────────────────── */
.db-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
.db-kpi-card {
    display: block;
    background: #fff;
    border-radius: 16px;
    padding: 22px 24px;
    position: relative;
    overflow: hidden;
    text-decoration: none;
    color: inherit;
    transition: all 250ms cubic-bezier(.4,0,.2,1);
    animation: dbFadeUp .4s ease both;
    border: 1px solid #eef1f6;
    box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.db-kpi-card:hover {
    box-shadow: 0 8px 30px rgba(0,0,0,.07);
    transform: translateY(-3px);
    border-color: #d1d9e6;
    color: inherit;
}
.db-kpi-card .kpi-icon {
    width: 40px; height: 40px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; margin-bottom: 14px;
}
.db-kpi-card .kpi-value {
    font-size: 1.6rem;
    font-weight: 800;
    color: #1e293b;
    line-height: 1;
    margin-bottom: 4px;
    letter-spacing: -.02em;
}
.db-kpi-card .kpi-value.sm { font-size: 1.15rem; }
.db-kpi-card .kpi-label {
    font-size: .72rem;
    color: #94a3b8;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 4px;
}
.db-kpi-card .kpi-sub {
    font-size: .73rem;
    color: #94a3b8;
    margin-top: 8px;
    line-height: 1.4;
}

/* ── Section layout ──────────────────────────────────────────────────────── */
.db-layout { display:grid; grid-template-columns:1fr 320px; gap:18px; align-items:start; }
.db-col    { display:flex; flex-direction:column; gap:16px; }

/* Cards section */
.db-section {
    background: #fff;
    border-radius: 16px;
    border: 1px solid #eef1f6;
    box-shadow: 0 1px 3px rgba(0,0,0,.03);
    overflow: hidden;
}

/* Card header */
.db-card-head {
    display:flex; justify-content:space-between; align-items:center;
    padding: 16px 20px;
    border-bottom: 1px solid #f1f4f8;
    background: #fafbfd;
}
.db-card-head h3 {
    margin:0; font-size: .85rem; font-weight: 700; color: #1e293b;
    display: flex; align-items: center; gap: 8px;
}
.db-card-head .db-count {
    font-size: .78rem; font-weight: 600; color: #94a3b8;
}

/* List rows */
.db-row {
    display:flex; align-items:center; gap:10px; padding:12px 18px;
    border-bottom:1px solid #f5f7fa; text-decoration:none; color:inherit;
    transition: background 200ms ease;
}
.db-row:last-child { border-bottom:none; }
.db-row:hover { background:#f8fafb; }
.db-row-name { font-size:.82rem; font-weight:600; color:#1e293b; }
.db-row-sub  { font-size:.72rem; color:#94a3b8; margin-top:2px; }

/* Dot statut */
.db-dot { width:9px; height:9px; border-radius:50%; flex-shrink:0; }
.db-dot.green  { background:#22c55e; box-shadow:0 0 0 3px rgba(34,197,94,.12); }
.db-dot.blue   { background:#0d9488; box-shadow:0 0 0 3px rgba(13,148,136,.1); }
.db-dot.red    { background:#ef4444; box-shadow:0 0 0 3px rgba(239,68,68,.1); }
.db-dot.gray   { background:#cbd5e1; }

/* Avatar initiales */
.db-av { width:34px;height:34px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;color:#fff; }

/* Badges */
.db-pill { font-size:.67rem; font-weight:600; padding:3px 8px; border-radius:6px; white-space:nowrap; }
.db-pill.red    { background:#fef2f2; color:#dc2626; }
.db-pill.blue   { background:#f0fdfa; color:#0d9488; }
.db-pill.green  { background:#f0fdf4; color:#16a34a; }
.db-pill.amber  { background:#fffbeb; color:#b45309; }
.db-pill.purple { background:#f5f3ff; color:#7c3aed; }
.db-pill.gray   { background:#f1f5f9; color:#64748b; }
.db-pill.teal   { background:#f0fdfa; color:#0d9488; }

/* Actions rapides */
.db-actions { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.db-action {
    display:flex; align-items:center; gap:8px; padding:10px 12px;
    border:1px solid #eef1f6; border-radius:10px; text-decoration:none;
    color:#475569; font-size:.79rem; font-weight:500; transition:all 200ms ease;
    background:#fff;
}
.db-action:hover { border-color:#0d9488; background:#f0fdfa; color:#0d9488; }
.db-action i { width:16px; text-align:center; }

/* Barre flotte */
.fleet-bar { height:5px; border-radius:3px; transition:width .8s cubic-bezier(.4,0,.2,1); }

/* Separateur barre gauche retard */
.db-bar-left { width:3px; align-self:stretch; border-radius:2px; flex-shrink:0; }

/* ── Mobile cards (hidden on desktop) ──────────────────────────────────── */
.mobile-cards { display: none; }

/* ── Buttons ghost/outline for table actions ──────────────────────────── */
.db-btn-action {
    font-size: .72rem;
    padding: 4px 10px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #64748b;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 200ms ease;
    font-weight: 600;
}
.db-btn-action:hover { border-color: #0d9488; color: #0d9488; background:#f0fdfa; }
.db-btn-action.cta {
    border-color: #fed7aa;
    color: #ea580c;
    background: #fff7ed;
}
.db-btn-action.cta:hover { background: #f97316; color: #fff; border-color:#f97316; }
.db-btn-action.danger {
    border-color: #fecaca;
    color: #dc2626;
    background: #fef2f2;
}
.db-btn-action.danger:hover { background: #dc2626; color: #fff; }

/* ── Bilan cards ───────────────────────────────────────────────────────── */
.bilan-card {
    border-radius: 12px; padding: 16px; text-align: center;
}
.bilan-card .bilan-label { font-size:.73rem; font-weight:600; margin-bottom:6px; }
.bilan-card .bilan-val { font-size:1.2rem; font-weight:800; }

/* ── Responsive ────────────────────────────────────────────────────────── */
@media(max-width:1100px) {
  .db-layout   { grid-template-columns: 1fr; }
}
@media(max-width:960px) {
  .db-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
@media(max-width:640px) {
  .db-hero { padding:20px;border-radius:14px; }
  .db-hero h1 { font-size:1.15rem; }
  .db-hero .db-date { font-size:.75rem; }
  .db-hero .db-header-actions { width:100%; }
  .db-alert-pill { font-size:.72rem; padding:6px 10px; flex:1; justify-content:center; }
  .db-kpi-grid    { grid-template-columns: 1fr 1fr; gap:10px; }
  .db-kpi-card    { padding: 16px; border-radius:14px; }
  .db-kpi-card .kpi-value { font-size: 1.25rem; }
  .db-kpi-card .kpi-value.sm { font-size: 1rem; }
  .db-kpi-card .kpi-icon { width:34px;height:34px;margin-bottom:10px; }
  .db-row         { padding: 10px 14px; gap: 8px; }
  .db-card-head   { padding: 12px 14px; flex-wrap: wrap; gap: 6px; }
  .db-actions     { grid-template-columns: 1fr; }
  .db-row-grid    { grid-template-columns: 1fr !important; }
  .db-layout      { grid-template-columns: 1fr !important; }
  .mobile-cards   { display: flex !important; flex-direction: column; gap: 10px; padding: 12px; }
  .desktop-table  { display: none !important; }
  .db-section     { border-radius:14px; }
  .bilan-card .bilan-val { font-size:1rem; }
}
</style>

<!-- ═══════════════════════════ EN-TETE ═══════════════════════════ -->
<div class="db-hero">
    <div>
        <h1>Bonjour, <?= sanitize($userPrenom) ?></h1>
        <div class="db-date"><?php $fmt = new IntlDateFormatter('fr_FR', IntlDateFormatter::FULL, IntlDateFormatter::NONE); echo ucfirst($fmt->format(new DateTime())); ?> · <?= sanitize(getTenantNom()) ?></div>
    </div>
    <div class="db-header-actions">
        <?php if ($nbLocRetard > 0): ?>
        <a href="<?= BASE_URL ?>app/locations/liste.php?statut=retard" class="db-alert-pill danger">
            <?= $nbLocRetard ?> location(s) en retard
        </a>
        <?php endif; ?>
        <?php if ($totalAlertes > 0): ?>
        <a href="<?= BASE_URL ?>app/maintenances/index.php" class="db-alert-pill warning">
            <?= $totalAlertes ?> alerte(s)
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>app/dashboard.php" class="db-alert-pill refresh">
            <i class="fas fa-rotate-right" style="font-size:.7rem"></i> Actualiser
        </a>
    </div>
</div>

<?= renderFlashes() ?>

<!-- ═══════════════════════════ KPIs — 4 essentiels ══════════════════════════ -->
<div class="db-kpi-grid">

    <a href="<?= BASE_URL ?>app/vehicules/liste.php" class="db-kpi-card" style="animation-delay:.05s">
        <div class="kpi-icon" style="background:#f0fdfa;color:#0d9488"><i class="fas fa-car-side"></i></div>
        <div class="kpi-label">Vehicules</div>
        <div class="kpi-value"><?= (int)$vstats['total'] ?></div>
        <div class="kpi-sub">
            <span style="color:#16a34a;font-weight:600"><?= (int)$vstats['nb_disponible'] ?></span> dispo ·
            <span style="color:#0d9488;font-weight:600"><?= (int)$vstats['nb_loue'] ?></span> loues ·
            <span style="color:#ef4444;font-weight:600"><?= (int)$vstats['nb_maintenance'] ?></span> maint.
        </div>
    </a>

    <a href="<?= BASE_URL ?>app/finances/rentabilite.php" class="db-kpi-card" style="animation-delay:.1s">
        <div class="kpi-icon" style="background:#f0fdf4;color:#16a34a"><i class="fas fa-arrow-trend-up"></i></div>
        <div class="kpi-label">Recettes du mois</div>
        <div class="kpi-value sm"><?= formatMoney($recMois) ?></div>
        <div class="kpi-sub">Location: <?= formatMoney($revenusMois) ?><?= $hasTaxi ? ' · Taxi: '.formatMoney($revenusTaxiMois) : '' ?></div>
    </a>

    <a href="<?= BASE_URL ?>app/rapports/mensuel.php" class="db-kpi-card" style="animation-delay:.15s">
        <div class="kpi-icon" style="background:<?= $resultatMois>=0?'#f0fdf4':'#fef2f2' ?>;color:<?= $resultatMois>=0?'#16a34a':'#dc2626' ?>"><i class="fas fa-<?= $resultatMois>=0?'chart-line':'chart-line' ?>"></i></div>
        <div class="kpi-label">Resultat net</div>
        <div class="kpi-value sm" style="color:<?= $resultatMois>=0?'#16a34a':'#dc2626' ?>"><?= ($resultatMois>=0?'+':'').formatMoney($resultatMois) ?></div>
        <div class="kpi-sub">Depenses: <?= formatMoney($depMois) ?></div>
    </a>

    <a href="<?= $hasTaxi ? BASE_URL.'app/taximetres/liste.php' : BASE_URL.'app/locations/liste.php' ?>" class="db-kpi-card" style="animation-delay:.2s">
        <div class="kpi-icon" style="background:#fff7ed;color:#ea580c"><i class="fas fa-hand-holding-dollar"></i></div>
        <div class="kpi-label">A encaisser</div>
        <div class="kpi-value sm" style="color:#ea580c"><?= formatMoney($totalARecevoir + $totalDetteTaxiGlobale) ?></div>
        <div class="kpi-sub"><?php if ($totalARecevoir > 0): ?>Loc: <?= formatMoney($totalARecevoir) ?><?php endif; ?><?php if ($totalDetteTaxiGlobale > 0): ?><?= $totalARecevoir > 0 ? ' · ' : '' ?>Taxi: <?= formatMoney($totalDetteTaxiGlobale) ?><?php endif; ?><?php if ($totalARecevoir == 0 && $totalDetteTaxiGlobale == 0): ?>Tout est a jour<?php endif; ?></div>
    </a>

</div>

<!-- ═══════════════════════════ GRILLE ═══════════════════════════════ -->
<div class="db-layout">

<!-- Colonne gauche -->
<div class="db-col">

<!-- ROW 1 : Dettes chauffeurs | Vehicules -->
<div class="db-row-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">

<!-- Dettes chauffeurs -->
<div class="db-section">
    <div class="db-card-head">
        <h3>Dettes chauffeurs <?php if (!empty($taxiDettes)): ?><span class="db-count">(<?= count($taxiDettes) ?>)</span><?php endif; ?></h3>
        <div style="display:flex;gap:4px;align-items:center">
            <span style="font-size:.72rem;color:#94a3b8" id="taxi-page-info"></span>
            <button class="db-btn-action" onclick="taxiPage(-1)" id="taxi-prev" style="padding:2px 6px"><i class="fas fa-chevron-left" style="font-size:.55rem"></i></button>
            <button class="db-btn-action" onclick="taxiPage(1)"  id="taxi-next" style="padding:2px 6px"><i class="fas fa-chevron-right" style="font-size:.55rem"></i></button>
            <a href="<?= BASE_URL ?>app/taximetres/liste.php" class="db-btn-action" style="font-size:.72rem">Tous</a>
        </div>
    </div>
    <?php if (!$hasTaxi || empty($taxiDettes)): ?>
    <div style="padding:24px;text-align:center;color:#94a3b8;font-size:.83rem">Aucune dette en cours</div>
    <?php else: ?>
    <div id="taxi-list">
        <?php foreach ($taxiDettes as $idx => $tx):
            $ini = strtoupper(mb_substr($tx['prenom'] ?? '',0,1).mb_substr($tx['nom'] ?? '',0,1));
        ?>
        <div class="taxi-pag-item db-row" data-idx="<?= $idx ?>">
            <div class="db-av" style="background:#ef4444"><?= $ini ?></div>
            <div style="flex:1;min-width:0">
                <div class="db-row-name" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= sanitize(($tx['prenom'] ?? '').' '.($tx['nom'] ?? '')) ?></div>
                <div class="db-row-sub"><?= sanitize($tx['immatriculation'] ?? '') ?> / <?= (int)$tx['jours_facturables'] ?> j</div>
            </div>
            <span style="font-size:.88rem;font-weight:800;color:#ef4444;white-space:nowrap"><?= formatMoney($tx['dette']) ?></span>
            <button onclick="openPayTaxiModal(<?= $tx['id'] ?>,<?= htmlspecialchars(json_encode(($tx['prenom'] ?? '').' '.($tx['nom'] ?? '')), ENT_QUOTES, 'UTF-8') ?>,<?= (int)$tx['dette'] ?>,<?= (int)$tx['tarif_journalier'] ?>)"
                    class="db-btn-action cta">Payer</button>
            <a href="<?= BASE_URL ?>app/taximetres/detail.php?id=<?= $tx['id'] ?>" class="db-btn-action"><i class="fas fa-eye" style="font-size:.65rem"></i></a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Vehicules -->
<div class="db-section">
    <div class="db-card-head">
        <h3>Vehicules <span class="db-count">(<?= count($vehiculesList) ?>)</span></h3>
        <div style="display:flex;gap:4px;align-items:center">
            <span style="font-size:.72rem;color:#94a3b8" id="veh-page-info"></span>
            <button class="db-btn-action" onclick="vehPage(-1)" id="veh-prev" style="padding:2px 6px"><i class="fas fa-chevron-left" style="font-size:.55rem"></i></button>
            <button class="db-btn-action" onclick="vehPage(1)"  id="veh-next" style="padding:2px 6px"><i class="fas fa-chevron-right" style="font-size:.55rem"></i></button>
            <a href="<?= BASE_URL ?>app/vehicules/liste.php" class="db-btn-action" style="font-size:.72rem">Tous</a>
        </div>
    </div>
    <?php if (empty($vehiculesList)): ?>
    <div style="padding:24px;text-align:center;color:#94a3b8;font-size:.83rem">Aucun vehicule</div>
    <?php else: ?>
    <div id="veh-list">
        <?php foreach ($vehiculesList as $idx => $veh):
            [$dotCls,$tagBg,$tagColor,$tagTxt] = match($veh['statut']) {
                'disponible'  => ['green','#dcfce7','#15803d','Disponible'],
                'loue'        => ['blue','#ccfbf1','#0d9488','Loue'],
                'maintenance' => ['red','#fee2e2','#dc2626','Maintenance'],
                default       => ['gray','#f1f5f9','#64748b',ucfirst($veh['statut'] ?? '')],
            };
            $docAlert = (($veh['date_expiration_assurance'] ?? null) && $veh['date_expiration_assurance'] < date('Y-m-d',strtotime('+30 days')))
                     || (($veh['date_expiration_vignette'] ?? null)  && $veh['date_expiration_vignette']  < date('Y-m-d',strtotime('+30 days')));
            $chauffeur = trim(($veh['taxi_nom'] ?? '') ?: ($veh['client_nom'] ?? ''));
        ?>
        <a href="<?= BASE_URL ?>app/vehicules/detail.php?id=<?= $veh['id'] ?>" class="veh-pag-item db-row" data-idx="<?= $idx ?>">
            <div class="db-dot <?= $dotCls ?>"></div>
            <div style="flex:1;min-width:0">
                <div class="db-row-name" style="display:flex;align-items:center;gap:6px;overflow:hidden">
                    <?= sanitize($veh['immatriculation'] ?? '') ?>
                    <span style="font-size:.68rem;background:<?= $tagBg ?>;color:<?= $tagColor ?>;padding:1px 7px;border-radius:99px;font-weight:600"><?= $tagTxt ?></span>
                    <?php if ($docAlert): ?><span style="color:#f59e0b;font-size:.7rem;font-weight:700">!</span><?php endif; ?>
                </div>
                <div class="db-row-sub" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= sanitize(($veh['marque'] ?? '').' '.($veh['modele'] ?? '')) ?>
                    <?php if ($chauffeur): ?> / <strong style="color:#475569"><?= sanitize($chauffeur) ?></strong><?php endif; ?>
                </div>
            </div>
            <?php if ($veh['kilometrage_actuel'] ?? null): ?>
            <div style="font-size:.72rem;color:#94a3b8;text-align:right;flex-shrink:0"><?= number_format((int)$veh['kilometrage_actuel'],0,',',' ') ?> km</div>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

</div><!-- /ROW 1 -->

<!-- ROW 2 : Locations | Reservations -->
<div class="db-row-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">

<!-- Locations en cours -->
<div class="db-section">
    <div class="db-card-head">
        <h3>Locations en cours
            <?php if ($nbLocEnCours>0): ?><span class="db-count">(<?= $nbLocEnCours ?>)</span><?php endif; ?>
            <?php if ($nbLocRetard>0): ?><span class="db-pill red"><?= $nbLocRetard ?> retard</span><?php endif; ?>
        </h3>
        <div style="display:flex;gap:4px;align-items:center">
            <span style="font-size:.72rem;color:#94a3b8" id="loc-page-info"></span>
            <button class="db-btn-action" onclick="locPage(-1)" id="loc-prev" style="padding:2px 6px"><i class="fas fa-chevron-left" style="font-size:.55rem"></i></button>
            <button class="db-btn-action" onclick="locPage(1)"  id="loc-next" style="padding:2px 6px"><i class="fas fa-chevron-right" style="font-size:.55rem"></i></button>
            <a href="<?= BASE_URL ?>app/locations/liste.php" class="db-btn-action" style="font-size:.72rem">Toutes</a>
        </div>
    </div>
    <?php if (empty($locationsEnCours)): ?>
    <div style="padding:24px;text-align:center;color:#94a3b8;font-size:.83rem">Aucune location en cours</div>
    <?php else: ?>
    <div id="loc-list">
        <?php foreach ($locationsEnCours as $idx => $loc):
            $isRetard = ($loc['date_fin'] ?? '') < $today;
            $hasReste = (float)($loc['reste_a_payer'] ?? 0) > 0;
        ?>
        <div class="loc-pag-item db-row" data-idx="<?= $idx ?>">
            <div class="db-bar-left" style="background:<?= $isRetard?'#ef4444':'#0d9488' ?>"></div>
            <div style="flex:1;min-width:0">
                <div class="db-row-name" style="display:flex;align-items:center;gap:6px">
                    <?= sanitize($loc['immatriculation'] ?? '') ?>
                    <?php if ($isRetard): ?><span class="db-pill red">Retard</span><?php endif; ?>
                </div>
                <div class="db-row-sub" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= sanitize($loc['client_nom'] ?? '') ?> / Fin <?= formatDate($loc['date_fin'] ?? '') ?></div>
            </div>
            <div style="text-align:right;flex-shrink:0">
                <div style="font-size:.85rem;font-weight:700"><?= formatMoney($loc['montant_final'] ?? 0) ?></div>
                <?php if ($hasReste): ?><div style="font-size:.72rem;color:#ef4444;font-weight:600">Reste <?= formatMoney($loc['reste_a_payer'] ?? 0) ?></div><?php endif; ?>
            </div>
            <?php if ($hasReste): ?>
            <button onclick="openPayLocModal(<?= $loc['id'] ?>,<?= htmlspecialchars(json_encode($loc['client_nom'] ?? ''), ENT_QUOTES, 'UTF-8') ?>,<?= (int)($loc['reste_a_payer'] ?? 0) ?>)"
                    class="db-btn-action cta">Payer</button>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>app/locations/detail.php?id=<?= $loc['id'] ?>" class="db-btn-action"><i class="fas fa-eye" style="font-size:.65rem"></i></a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Reservations -->
<div class="db-section">
    <div class="db-card-head">
        <h3>Reservations a venir <?php if (!empty($reservationsAVenir)): ?><span class="db-count">(<?= count($reservationsAVenir) ?>)</span><?php endif; ?></h3>
        <div style="display:flex;gap:4px;align-items:center">
            <span style="font-size:.72rem;color:#94a3b8" id="res-page-info"></span>
            <button class="db-btn-action" onclick="resPage(-1)" id="res-prev" style="padding:2px 6px"><i class="fas fa-chevron-left" style="font-size:.55rem"></i></button>
            <button class="db-btn-action" onclick="resPage(1)"  id="res-next" style="padding:2px 6px"><i class="fas fa-chevron-right" style="font-size:.55rem"></i></button>
            <a href="<?= BASE_URL ?>app/reservations/calendrier.php" class="db-btn-action" style="font-size:.72rem">Voir</a>
        </div>
    </div>
    <?php if (empty($reservationsAVenir)): ?>
    <div style="padding:24px;text-align:center;color:#94a3b8;font-size:.83rem">Aucune reservation a venir</div>
    <?php else: ?>
    <div id="res-list">
        <?php foreach ($reservationsAVenir as $idx => $res):
            $conf = ($res['statut'] ?? '') === 'confirmee';
        ?>
        <a href="<?= BASE_URL ?>app/reservations/calendrier.php" class="res-pag-item db-row" data-idx="<?= $idx ?>">
            <div style="flex:1;min-width:0">
                <div class="db-row-name" style="display:flex;align-items:center;gap:6px">
                    <?= sanitize($res['immatriculation'] ?? '') ?>
                    <span class="db-pill <?= $conf?'green':'amber' ?>"><?= $conf?'Confirmee':'Attente' ?></span>
                </div>
                <div class="db-row-sub" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= sanitize($res['client_nom'] ?? '') ?></div>
                <div class="db-row-sub"><?= formatDate($res['date_debut'] ?? '') ?> - <?= formatDate($res['date_fin'] ?? '') ?></div>
            </div>
            <div style="font-size:.88rem;font-weight:700;white-space:nowrap;flex-shrink:0"><?= formatMoney($res['montant_final'] ?? 0) ?></div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

</div><!-- /ROW 2 -->

<!-- ROW 3 : Bilan mois | Depenses entreprise -->
<div class="db-row-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">

<!-- Bilan financier du mois -->
<div class="db-section">
    <div class="db-card-head">
        <h3>Bilan du mois</h3>
        <a href="<?= BASE_URL ?>app/finances/comptabilite.php" class="db-btn-action" style="font-size:.72rem">Details</a>
    </div>
    <div style="padding:16px 20px">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">
            <div class="bilan-card" style="background:#f0fdf4;">
                <div class="bilan-label" style="color:#16a34a"><i class="fas fa-arrow-up" style="font-size:.6rem;margin-right:3px"></i> Recettes</div>
                <div class="bilan-val" style="color:#16a34a"><?= formatMoney($recMois) ?></div>
            </div>
            <div class="bilan-card" style="background:#fef2f2;">
                <div class="bilan-label" style="color:#dc2626"><i class="fas fa-arrow-down" style="font-size:.6rem;margin-right:3px"></i> Depenses</div>
                <div class="bilan-val" style="color:#dc2626"><?= formatMoney($depMois) ?></div>
            </div>
        </div>
        <div style="background:<?= $resultatMois>=0?'linear-gradient(135deg,#f0fdf4,#ecfdf5)':'linear-gradient(135deg,#fef2f2,#fff1f2)' ?>;border-radius:12px;padding:14px 16px;display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <span style="font-size:.82rem;font-weight:700;color:<?= $resultatMois>=0?'#16a34a':'#dc2626' ?>"><i class="fas fa-<?= $resultatMois>=0?'check-circle':'exclamation-circle' ?>" style="margin-right:4px"></i>Resultat net</span>
            <span style="font-size:1.2rem;font-weight:800;color:<?= $resultatMois>=0?'#16a34a':'#dc2626' ?>"><?= ($resultatMois>=0?'+':'').formatMoney($resultatMois) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#f8f9fb;border-radius:10px;border:1px solid #eef1f6">
            <span style="font-size:.78rem;color:#64748b;font-weight:500"><i class="fas fa-wallet" style="margin-right:5px;color:#94a3b8"></i>Solde caisse</span>
            <span style="font-size:1rem;font-weight:700;color:<?= $soldeCaisse>=0?'#0d9488':'#dc2626' ?>"><?= formatMoney($soldeCaisse) ?></span>
        </div>
    </div>
</div>

<!-- Depenses entreprise -->
<div class="db-section">
    <div class="db-card-head">
        <h3>Depenses entreprise</h3>
        <a href="<?= BASE_URL ?>app/finances/comptabilite.php" class="db-btn-action" style="font-size:.72rem">Tout voir</a>
    </div>
    <?php
    $catColors = ['salaire'=>'#6366f1','loyer'=>'#f59e0b','investissement'=>'#10b981','marketing'=>'#8b5cf6','fournitures'=>'#06b6d4','impots'=>'#ef4444','autre'=>'#94a3b8'];
    if (empty($depentreprises)): ?>
    <div style="padding:24px;text-align:center;color:#94a3b8;font-size:.83rem">Aucune depense entreprise</div>
    <?php else: ?>
    <?php foreach ($depentreprises as $de):
        $col = $catColors[$de['categorie'] ?? ''] ?? '#94a3b8';
    ?>
    <div class="db-row">
        <div style="width:6px;height:6px;border-radius:50%;background:<?= $col ?>;flex-shrink:0"></div>
        <div style="flex:1;min-width:0">
            <div class="db-row-name" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($de['libelle'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="db-row-sub"><?= formatDate($de['date_depense'] ?? '') ?> / <?= ucfirst($de['categorie'] ?? '') ?></div>
        </div>
        <div style="font-size:.88rem;font-weight:700;color:#dc2626;white-space:nowrap"><?= formatMoney($de['montant'] ?? 0) ?></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    <div style="padding:10px 16px;border-top:1px solid #e2e8f0">
        <a href="<?= BASE_URL ?>app/finances/comptabilite.php" style="font-size:.78rem;font-weight:600;color:#0d9488;text-decoration:none">+ Ajouter une depense</a>
    </div>
</div>

</div><!-- /ROW 3 -->

</div><!-- /Colonne gauche -->

<!-- Sidebar droite -->
<div class="db-col">

<!-- Actions rapides -->
<div class="db-section">
    <div class="db-card-head"><h3>Actions rapides</h3></div>
    <div style="padding:14px">
        <div class="db-actions">
            <?php if (hasLocationModule()): ?>
            <a href="<?= BASE_URL ?>app/locations/nouvelle.php" class="db-action">
                <i class="fas fa-plus" style="color:#0d9488;font-size:.75rem"></i>Nouvelle location
            </a>
            <a href="<?= BASE_URL ?>app/reservations/calendrier.php" class="db-action">
                <i class="fas fa-calendar-plus" style="color:#0d9488;font-size:.75rem"></i>Reservation
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>app/clients/ajouter.php" class="db-action">
                <i class="fas fa-user-plus" style="color:#0d9488;font-size:.75rem"></i>Nouveau client
            </a>
            <a href="<?= BASE_URL ?>app/vehicules/ajouter.php" class="db-action">
                <i class="fas fa-car" style="color:#0d9488;font-size:.75rem"></i>Ajouter vehicule
            </a>
            <?php if ($hasTaxi): ?>
            <a href="<?= BASE_URL ?>app/taximetres/paiements.php" class="db-action">
                <i class="fas fa-coins" style="color:#f97316;font-size:.75rem"></i>Paiement taxi
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>app/finances/charges.php" class="db-action">
                <i class="fas fa-receipt" style="color:#64748b;font-size:.75rem"></i>Ajouter charge
            </a>
        </div>
        <?php if (hasGpsModule()): ?>
        <a href="<?= BASE_URL ?>app/gps/carte.php" style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:8px;padding:9px;border:1px solid #e2e8f0;border-radius:8px;text-decoration:none;color:#0d9488;font-size:.8rem;font-weight:600;background:#f0fdfa;transition:all 150ms ease" onmouseover="this.style.background='#ccfbf1'" onmouseout="this.style.background='#f0fdfa'">
            <i class="fas fa-map-location-dot"></i> Carte GPS temps reel
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Alertes -->
<div class="db-section" style="border-left:3px solid <?= $totalAlertes>0?'#ef4444':'#16a34a' ?>">
    <div class="db-card-head">
        <h3>Alertes <?php if ($totalAlertes>0): ?><span class="db-count" style="color:#dc2626">(<?= $totalAlertes ?>)</span><?php endif; ?></h3>
        <a href="<?= BASE_URL ?>app/maintenances/index.php" class="db-btn-action" style="font-size:.72rem">Voir tout</a>
    </div>
    <?php if (empty($maintUrgentes) && empty($docsExp)): ?>
    <div style="padding:20px;text-align:center;color:#94a3b8;font-size:.83rem">Aucune alerte active</div>
    <?php endif; ?>
    <?php foreach ($maintUrgentes as $m): ?>
    <div class="db-row">
        <div style="flex:1;min-width:0">
            <div class="db-row-name"><?= sanitize($m['vnom'] ?? '') ?> - <?= sanitize($m['type'] ?? '') ?></div>
            <div class="db-row-sub"><?= sanitize($m['immatriculation'] ?? '') ?><?= ($m['date_prevue'] ?? null) ? ' / '.formatDate($m['date_prevue']) : '' ?></div>
        </div>
        <span class="db-pill red">Urgent</span>
    </div>
    <?php endforeach; ?>
    <?php foreach ($docsExp as $doc):
        $todayStr = date('Y-m-d');
        $aExp = $doc['date_expiration_assurance'] ?? null;
        $vExp = $doc['date_expiration_vignette'] ?? null;
        $aExpired = $aExp && $aExp < $todayStr;
        $vExpired = $vExp && $vExp < $todayStr;
        $aSoon = $aExp && !$aExpired && $aExp <= date('Y-m-d',strtotime('+30 days'));
        $vSoon = $vExp && !$vExpired && $vExp <= date('Y-m-d',strtotime('+30 days'));
        $isExpired = $aExpired || $vExpired;
        $details = [];
        if ($aExpired) $details[] = 'Assurance expiree ('.formatDate($aExp).')';
        elseif ($aSoon) { $days=(int)round((strtotime($aExp)-strtotime($todayStr))/86400); $details[] = "Assurance dans {$days}j (".formatDate($aExp).')'; }
        if ($vExpired) $details[] = 'Vignette expiree ('.formatDate($vExp).')';
        elseif ($vSoon) { $days=(int)round((strtotime($vExp)-strtotime($todayStr))/86400); $details[] = "Vignette dans {$days}j (".formatDate($vExp).')'; }
    ?>
    <a href="<?= BASE_URL ?>app/vehicules/detail.php?id=<?= $doc['id'] ?>" class="db-row" style="text-decoration:none;color:inherit">
        <div style="flex:1;min-width:0">
            <div class="db-row-name" style="display:flex;align-items:center;gap:6px">
                <?= sanitize($doc['nom'] ?? '') ?> <span style="color:#94a3b8;font-weight:400">(<?= sanitize($doc['immatriculation'] ?? '') ?>)</span>
            </div>
            <div class="db-row-sub"><?= implode(' / ', $details) ?></div>
        </div>
        <span class="db-pill <?= $isExpired ? 'red' : 'amber' ?>"><?= $isExpired ? 'Expire' : 'Bientot' ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Repartition flotte -->
<div class="db-section">
    <div class="db-card-head"><h3>Repartition flotte</h3></div>
    <div style="padding:16px 20px">
        <?php
        $total = max(1,(int)$vstats['total']);
        $fleet = [
            ['Disponible',  (int)$vstats['nb_disponible'],  '#22c55e', 'fa-circle-check'],
            ['En location', (int)$vstats['nb_loue'],         '#0d9488', 'fa-key'],
            ['Maintenance', (int)$vstats['nb_maintenance'],  '#ef4444', 'fa-wrench'],
            ['Autres',      max(0,$total-(int)$vstats['nb_disponible']-(int)$vstats['nb_loue']-(int)$vstats['nb_maintenance']),'#94a3b8','fa-circle-minus'],
        ];
        foreach ($fleet as [$lbl,$val,$col,$ico]):
            $pct = round($val/$total*100);
        ?>
        <div style="margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:.78rem;margin-bottom:5px">
                <span style="color:#475569;display:flex;align-items:center;gap:6px"><i class="fas <?= $ico ?>" style="font-size:.6rem;color:<?= $col ?>;width:12px;text-align:center"></i><?= $lbl ?></span>
                <span style="font-weight:700;color:#1e293b"><?= $val ?> <span style="color:#94a3b8;font-weight:400">(<?= $pct ?>%)</span></span>
            </div>
            <div style="height:5px;background:#f1f5f9;border-radius:3px;overflow:hidden">
                <div class="fleet-bar" style="width:<?= $pct ?>%;background:<?= $col ?>"></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

</div><!-- /Sidebar droite -->
</div><!-- /GRILLE -->

<!-- ════ VTC NON PAYES AUJOURD'HUI ══════════════════════════════════════════ -->
<?php if ($hasTaxi && !empty($vtcNonPayes)): ?>
<div class="db-section" style="margin-top:20px;border-left:3px solid #ef4444">
    <div class="db-card-head">
        <h3>VTC non payes aujourd'hui <span class="db-count" style="color:#dc2626">(<?= count($vtcNonPayes) ?>)</span></h3>
        <a href="<?= BASE_URL ?>app/taximetres/paiements.php" class="db-btn-action cta">
            Saisir paiements
        </a>
    </div>
    <!-- Desktop table -->
    <div class="desktop-table" style="padding:0">
        <table class="table" style="margin:0;font-size:.8rem">
            <thead>
                <tr>
                    <th style="padding:8px 14px">Taximantre</th>
                    <th style="padding:8px 12px">Vehicule</th>
                    <th style="padding:8px 12px">Telephone</th>
                    <th style="padding:8px 12px;text-align:center">Statut</th>
                    <th style="padding:8px 12px;text-align:right">Jours</th>
                    <th style="padding:8px 12px;text-align:right">Cumul dette</th>
                    <th style="padding:8px 12px;text-align:center">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($vtcNonPayes as $vtc):
                $ini       = strtoupper(mb_substr($vtc['prenom'] ?? '',0,1).mb_substr($vtc['nom'] ?? '',0,1));
                $cumuDette = (float)$vtc['dette_reelle'];
                $sj        = $vtc['statut_jour'] ?? null;
            ?>
            <tr>
                <td style="padding:8px 14px">
                    <div style="display:flex;align-items:center;gap:8px">
                        <div class="db-av" style="background:#ef4444"><?= $ini ?></div>
                        <span style="font-weight:600"><?= sanitize(($vtc['prenom'] ?? '').' '.($vtc['nom'] ?? '')) ?></span>
                    </div>
                </td>
                <td style="padding:8px 12px">
                    <div style="font-weight:700"><?= sanitize($vtc['immatriculation'] ?? '') ?></div>
                    <div style="font-size:.67rem;color:#94a3b8"><?= sanitize($vtc['vnom'] ?? '') ?></div>
                </td>
                <td style="padding:8px 12px;color:#94a3b8"><?= sanitize($vtc['telephone'] ?? '-') ?></td>
                <td style="padding:8px 12px;text-align:center">
                    <?php
                    if ($sj===null)          echo '<span class="db-pill gray">Non saisi</span>';
                    elseif($sj==='non_paye') echo '<span class="db-pill red">Non paye</span>';
                    elseif($sj==='panne')    echo '<span class="db-pill amber">Panne</span>';
                    elseif($sj==='accident') echo '<span class="db-pill red">Accident</span>';
                    else                     echo '<span class="db-pill gray">'.sanitize($sj).'</span>';
                    ?>
                </td>
                <td style="padding:8px 12px;text-align:right;color:#ef4444;font-weight:700"><?= (int)($vtc['jours_facturables'] ?? 0) ?> j</td>
                <td style="padding:8px 12px;text-align:right;color:#ef4444;font-weight:800"><?= formatMoney($cumuDette) ?></td>
                <td style="padding:8px 12px;text-align:center">
                    <div style="display:flex;gap:5px;justify-content:center">
                        <button onclick="openPayModal('taxi',<?= $vtc['id'] ?>,<?= htmlspecialchars(json_encode(($vtc['prenom'] ?? '').' '.($vtc['nom'] ?? '')), ENT_QUOTES, 'UTF-8') ?>,<?= (int)$cumuDette ?>,<?= (int)$vtc['tarif_journalier'] ?>)"
                                class="db-btn-action cta">Payer</button>
                        <a href="<?= BASE_URL ?>app/taximetres/detail.php?id=<?= $vtc['id'] ?>" class="db-btn-action">
                            <i class="fas fa-eye" style="font-size:.65rem"></i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!-- Mobile cards -->
    <div class="mobile-cards">
    <?php foreach ($vtcNonPayes as $vtc):
        $ini       = strtoupper(mb_substr($vtc['prenom'] ?? '',0,1).mb_substr($vtc['nom'] ?? '',0,1));
        $cumuDette = (float)$vtc['dette_reelle'];
        $sj        = $vtc['statut_jour'] ?? null;
        $sjBadge   = match(true) {
            $sj===null          => '<span class="db-pill gray">Non saisi</span>',
            $sj==='non_paye'    => '<span class="db-pill red">Non paye</span>',
            $sj==='panne'       => '<span class="db-pill amber">Panne</span>',
            $sj==='accident'    => '<span class="db-pill red">Accident</span>',
            default             => '<span class="db-pill gray">'.sanitize($sj).'</span>',
        };
    ?>
    <div style="border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;background:#fff;display:flex;align-items:center;gap:10px">
        <div class="db-av" style="background:#ef4444"><?= $ini ?></div>
        <div style="flex:1;min-width:0">
            <div style="font-size:.88rem;font-weight:700;color:#0f172a"><?= sanitize(($vtc['prenom'] ?? '').' '.($vtc['nom'] ?? '')) ?></div>
            <div style="font-size:.72rem;color:#64748b;margin-top:2px"><?= sanitize($vtc['immatriculation'] ?? '') ?> / <?= sanitize($vtc['telephone'] ?? '-') ?></div>
            <div style="margin-top:4px"><?= $sjBadge ?><?php if ((int)($vtc['jours_facturables'] ?? 0)>0): ?> <span style="font-size:.68rem;color:#ef4444;font-weight:700"><?= (int)($vtc['jours_facturables'] ?? 0) ?> j</span><?php endif; ?></div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex-shrink:0">
            <div style="font-size:.95rem;font-weight:800;color:#ef4444"><?= $cumuDette > 0 ? formatMoney($cumuDette) : '-' ?></div>
            <div style="display:flex;gap:5px">
                <button onclick="openPayModal('taxi',<?= $vtc['id'] ?>,<?= htmlspecialchars(json_encode(($vtc['prenom'] ?? '').' '.($vtc['nom'] ?? '')), ENT_QUOTES, 'UTF-8') ?>,<?= (int)$cumuDette ?>,<?= (int)$vtc['tarif_journalier'] ?>)"
                        class="db-btn-action cta" style="padding:4px 10px">Payer</button>
                <a href="<?= BASE_URL ?>app/taximetres/detail.php?id=<?= $vtc['id'] ?>" class="db-btn-action" style="padding:4px 8px">
                    <i class="fas fa-eye" style="font-size:.65rem"></i>
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php elseif ($hasTaxi): ?>
<div class="db-section" style="border-left:3px solid #16a34a;margin-top:20px">
    <div style="padding:12px 16px;display:flex;align-items:center;gap:10px">
        <i class="fas fa-check-circle" style="color:#22c55e;font-size:1.1rem"></i>
        <div style="font-size:.85rem">
            <strong>Tous les taximantres ont ete payes aujourd'hui</strong><br>
            <a href="<?= BASE_URL ?>app/taximetres/liste.php" style="font-size:.78rem;color:#0d9488;text-decoration:none">Voir la liste</a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ════ MODAL PAIEMENT UNIFIE ══════════════════════════════════════════════ -->
<div id="modal-pay" class="modal-overlay">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <h3 id="pay-modal-title">Enregistrer un paiement</h3>
            <button class="modal-close" onclick="closeModal('modal-pay')">&times;</button>
        </div>
        <form method="POST" style="padding:20px" id="pay-form">
            <?= csrfField() ?>
            <input type="hidden" name="action"       id="pay-action">
            <input type="hidden" name="taximetre_id" id="pay-taxi-id">
            <input type="hidden" name="location_id"  id="pay-loc-id">
            <div style="background:#f8fafc;border-left:4px solid #0d9488;border-radius:0 8px 8px 0;padding:10px 14px;margin-bottom:16px;font-size:.82rem">
                <div style="font-weight:700;margin-bottom:2px" id="pay-nom"></div>
                <div style="color:#64748b">
                    <span id="pay-label-montant">Solde du :</span>
                    <strong id="pay-solde" style="color:#ef4444"></strong>
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Montant (FCFA) *</label>
                    <input type="number" name="montant" id="pay-montant" class="form-control" min="1" step="1" required placeholder="Ex: 15000" style="border-color:#e2e8f0" onfocus="this.style.borderColor='#0d9488'" onblur="this.style.borderColor='#e2e8f0'">
                </div>
                <div class="form-group" id="pay-date-group">
                    <label class="form-label">Date</label>
                    <input type="date" name="date_paiement" id="pay-date" class="form-control" value="<?= $today ?>" style="border-color:#e2e8f0" onfocus="this.style.borderColor='#0d9488'" onblur="this.style.borderColor='#e2e8f0'">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Mode de paiement</label>
                <select name="mode_paiement" class="form-control" style="border-color:#e2e8f0" onfocus="this.style.borderColor='#0d9488'" onblur="this.style.borderColor='#e2e8f0'">
                    <option value="especes">Especes</option>
                    <option value="mobile_money">Mobile Money</option>
                    <option value="virement">Virement</option>
                    <option value="cheque">Cheque</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-pay')">Annuler</button>
                <button type="submit" id="pay-submit-btn" style="background:#f97316;color:#fff;border:none;padding:8px 20px;border-radius:8px;font-weight:700;font-size:.85rem;cursor:pointer;transition:background 150ms ease" onmouseover="this.style.background='#ea580c'" onmouseout="this.style.background='#f97316'">
                    Enregistrer le paiement
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Pagination
function makePag(listId, itemSel, prevId, nextId, infoId, perPage) {
    const list = document.getElementById(listId);
    if (!list) return null;
    const items = list.querySelectorAll(itemSel);
    if (!items.length) return null;
    let pg = 0;
    const tp = Math.ceil(items.length / perPage);
    function render() {
        items.forEach((el,i) => el.style.display = (i>=pg*perPage && i<(pg+1)*perPage) ? '' : 'none');
        const info = document.getElementById(infoId);
        if (info) info.textContent = tp > 1 ? `${pg+1}/${tp}` : '';
        const prev = document.getElementById(prevId), next = document.getElementById(nextId);
        if (prev) prev.disabled = pg === 0;
        if (next) next.disabled = pg >= tp-1;
    }
    render();
    return { prev(){ if(pg>0){pg--;render();}}, next(){ if(pg<tp-1){pg++;render();}} };
}
const taxiP = makePag('taxi-list','.taxi-pag-item','taxi-prev','taxi-next','taxi-page-info',10);
const vehP  = makePag('veh-list', '.veh-pag-item', 'veh-prev', 'veh-next', 'veh-page-info',10);
const locP  = makePag('loc-list', '.loc-pag-item', 'loc-prev', 'loc-next', 'loc-page-info',10);
const resP  = makePag('res-list', '.res-pag-item', 'res-prev', 'res-next', 'res-page-info',10);
function taxiPage(d){ taxiP && (d<0 ? taxiP.prev() : taxiP.next()); }
function vehPage(d) { vehP  && (d<0 ? vehP.prev()  : vehP.next());  }
function locPage(d) { locP  && (d<0 ? locP.prev()  : locP.next());  }
function resPage(d) { resP  && (d<0 ? resP.prev()  : resP.next());  }

// Modal paiement unifie
function openPayModal(type, id, nom, montant, defaultMontant) {
    const fmt = v => new Intl.NumberFormat('fr-FR').format(Math.round(v)) + ' FCFA';
    document.getElementById('pay-nom').textContent    = nom;
    document.getElementById('pay-solde').textContent  = fmt(montant);
    document.getElementById('pay-montant').value      = Math.round(defaultMontant || montant);
    document.getElementById('pay-date').value         = '<?= $today ?>';
    if (type === 'taxi') {
        document.getElementById('pay-modal-title').textContent  = 'Paiement taximantre';
        document.getElementById('pay-label-montant').textContent = 'Dette estimee :';
        document.getElementById('pay-action').value             = 'payer_dette_taxi';
        document.getElementById('pay-taxi-id').value            = id;
        document.getElementById('pay-loc-id').value             = '';
        document.getElementById('pay-date-group').style.display = '';
        document.getElementById('pay-submit-btn').style.background = '#f97316';
    } else {
        document.getElementById('pay-modal-title').textContent  = 'Paiement location';
        document.getElementById('pay-label-montant').textContent = 'Reste a payer :';
        document.getElementById('pay-action').value             = 'payer_location';
        document.getElementById('pay-loc-id').value             = id;
        document.getElementById('pay-taxi-id').value            = '';
        document.getElementById('pay-date-group').style.display = 'none';
        document.getElementById('pay-submit-btn').style.background = '#f97316';
    }
    openModal('modal-pay');
}
function openPayTaxiModal(id, nom, dette, tarif) { openPayModal('taxi', id, nom, dette, tarif); }
function openPayLocModal(id, nom, reste)          { openPayModal('location', id, nom, reste, reste); }
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
