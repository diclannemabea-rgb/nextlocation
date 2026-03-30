<?php
/**
 * FlotteCar — Fiche complète véhicule
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

$vehiculeId = (int)($_GET['id'] ?? 0);
if (!$vehiculeId) redirect(BASE_URL . 'app/vehicules/liste.php');

$v = $db->prepare("SELECT * FROM vehicules WHERE id = ? AND tenant_id = ?");
$v->execute([$vehiculeId, $tenantId]);
$vehicule = $v->fetch(PDO::FETCH_ASSOC);
if (!$vehicule) {
    setFlash(FLASH_ERROR, 'Véhicule introuvable.');
    redirect(BASE_URL . 'app/vehicules/liste.php');
}

// ─── HANDLE POST ACTIONS ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $act = $_POST['act'] ?? '';

    // Ajouter règle GPS
    if ($act === 'add_regle') {
        $typeRegle = trim($_POST['type_regle'] ?? '');
        $libelle   = trim($_POST['libelle'] ?? '');
        $params    = [];
        switch ($typeRegle) {
            case 'vitesse':       $params = ['seuil_kmh'  => (int)($_POST['seuil_kmh'] ?? 80)]; break;
            case 'horaire':       $params = ['debut' => trim($_POST['debut'] ?? '06:00'), 'fin' => trim($_POST['fin'] ?? '20:00')]; break;
            case 'km_jour':       $params = ['seuil_km'   => (int)($_POST['seuil_km'] ?? 200)]; break;
            case 'ralenti':       $params = ['seuil_min'  => (int)($_POST['seuil_min'] ?? 10)]; break;
            case 'immobilisation':$params = ['seuil_heures' => (int)($_POST['seuil_heures'] ?? 24)]; break;
            case 'geofence':      $params = ['zone' => trim($_POST['zone'] ?? '')]; break;
            default:              $params = [];
        }
        if ($typeRegle && $libelle) {
            $db->prepare("INSERT INTO regles_gps (tenant_id, vehicule_id, type_regle, libelle, params, actif) VALUES (?,?,?,?,?,1)")
               ->execute([$tenantId, $vehiculeId, $typeRegle, $libelle, json_encode($params)]);
            setFlash(FLASH_SUCCESS, 'Règle GPS ajoutée.');
        }
        redirect(BASE_URL . "app/vehicules/detail.php?id=$vehiculeId&tab=regles");
    }

    // Supprimer règle GPS
    if ($act === 'del_regle') {
        $regleId = (int)($_POST['regle_id'] ?? 0);
        $db->prepare("DELETE FROM regles_gps WHERE id=? AND vehicule_id=? AND tenant_id=?")
           ->execute([$regleId, $vehiculeId, $tenantId]);
        setFlash(FLASH_SUCCESS, 'Règle supprimée.');
        redirect(BASE_URL . "app/vehicules/detail.php?id=$vehiculeId&tab=regles");
    }

    // Toggle règle actif/inactif
    if ($act === 'toggle_regle') {
        $regleId = (int)($_POST['regle_id'] ?? 0);
        $db->prepare("UPDATE regles_gps SET actif = NOT actif WHERE id=? AND vehicule_id=? AND tenant_id=?")
           ->execute([$regleId, $vehiculeId, $tenantId]);
        redirect(BASE_URL . "app/vehicules/detail.php?id=$vehiculeId&tab=regles");
    }
}

// ─── EXPORT CSV ───────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $expFrom = $_GET['from'] ?? date('Y-m-01');
    $expTo   = $_GET['to']   ?? date('Y-m-d');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="analyse_' . $vehiculeId . '_' . $expFrom . '_' . $expTo . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
    fputcsv($out, ['Date', 'Type', 'Libellé', 'Recette', 'Dépense'], ';');

    // Locations (paiements)
    $sq = $db->prepare("SELECT p.created_at, 'Location' as type, CONCAT('Location #',l.id,' - ',COALESCE(c.nom,'?'),' ',COALESCE(c.prenom,'')) as lib, p.montant as rec, 0 as dep
        FROM paiements p JOIN locations l ON l.id=p.location_id LEFT JOIN clients c ON c.id=l.client_id
        WHERE l.vehicule_id=? AND p.tenant_id=? AND DATE(p.created_at) BETWEEN ? AND ?");
    $sq->execute([$vehiculeId, $tenantId, $expFrom, $expTo]);
    foreach ($sq->fetchAll() as $row) fputcsv($out, [substr($row['created_at'],0,10),$row['type'],$row['lib'],number_format($row['rec'],0,'.',','),'0'], ';');

    // Charges
    $sq = $db->prepare("SELECT date_charge, 'Charge' as type, COALESCE(libelle,description,'—') as lib, 0, montant FROM charges WHERE vehicule_id=? AND tenant_id=? AND date_charge BETWEEN ? AND ?");
    $sq->execute([$vehiculeId, $tenantId, $expFrom, $expTo]);
    foreach ($sq->fetchAll() as $row) fputcsv($out, [$row['date_charge'],$row['type'],$row['lib'],'0',number_format($row['montant'],0,'.',',')], ';');

    // Maintenances déjà incluses dans charges (type='maintenance') — pas de double export

    fclose($out);
    exit;
}

// ─── STATS FINANCIÈRES ────────────────────────────────────────────────────────
$r = $db->prepare("SELECT COALESCE(SUM(p.montant),0) FROM paiements p JOIN locations l ON l.id=p.location_id WHERE l.vehicule_id=? AND p.tenant_id=?");
$r->execute([$vehiculeId, $tenantId]); $revenusLoc = (float)$r->fetchColumn();

$r = $db->prepare("SELECT COALESCE(SUM(pt.montant),0) FROM paiements_taxi pt JOIN taximetres tx ON tx.id=pt.taximetre_id WHERE tx.vehicule_id=? AND pt.tenant_id=? AND pt.statut_jour='paye'");
$r->execute([$vehiculeId, $tenantId]); $revenusTaxi = (float)$r->fetchColumn();

$r = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM charges WHERE vehicule_id=? AND tenant_id=?");
$r->execute([$vehiculeId, $tenantId]); $detailCharges = (float)$r->fetchColumn();

// maintenances terminées sont déjà dans charges — pas de double comptage
$detailMaint = 0;

$capital    = (float)($vehicule['capital_investi'] ?? 0);
$recInitial = (float)($vehicule['recettes_initiales'] ?? 0);
$depInitial = (float)($vehicule['depenses_initiales'] ?? 0);
$totalRec   = $recInitial + $revenusLoc + $revenusTaxi;
$totalDep   = $depInitial + $detailCharges;
$benefice   = $totalRec - $totalDep;
$roi        = $capital > 0 ? round(($benefice - $capital) / $capital * 100, 1) : 0;

$r = $db->prepare("SELECT COALESCE(SUM(LEAST(nombre_jours,30)),0) FROM locations WHERE vehicule_id=? AND tenant_id=? AND statut IN('en_cours','terminee') AND date_debut >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)");
$r->execute([$vehiculeId, $tenantId]); $joursLoues = min(30, (int)$r->fetchColumn());
$tauxOcc = round($joursLoues / 30 * 100, 0);

$r = $db->prepare("SELECT COUNT(*) FROM locations WHERE vehicule_id=? AND tenant_id=?");
$r->execute([$vehiculeId, $tenantId]); $nbLocTotal = (int)$r->fetchColumn();

// ─── STATS MOIS EN COURS ──────────────────────────────────────────────────────
$moisDebut = date('Y-m-01');
$moisFin   = date('Y-m-t');
$r = $db->prepare("SELECT COALESCE(SUM(p.montant),0) FROM paiements p JOIN locations l ON l.id=p.location_id WHERE l.vehicule_id=? AND p.tenant_id=? AND DATE(p.created_at) BETWEEN ? AND ?");
$r->execute([$vehiculeId, $tenantId, $moisDebut, $moisFin]); $moisRec = (float)$r->fetchColumn();
// Recettes taxi mois en cours
if ($vehicule['type_vehicule'] === 'taxi') {
    $r = $db->prepare("SELECT COALESCE(SUM(pt.montant),0) FROM paiements_taxi pt JOIN taximetres tx ON tx.id=pt.taximetre_id WHERE tx.vehicule_id=? AND pt.tenant_id=? AND pt.statut_jour='paye' AND pt.date_paiement BETWEEN ? AND ?");
    $r->execute([$vehiculeId, $tenantId, $moisDebut, $moisFin]); $moisRec += (float)$r->fetchColumn();
}

$r = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM charges WHERE vehicule_id=? AND tenant_id=? AND date_charge BETWEEN ? AND ?");
$r->execute([$vehiculeId, $tenantId, $moisDebut, $moisFin]); $moisDep = (float)$r->fetchColumn();

// maintenances terminées déjà dans charges — pas de double comptage
$moisBen = $moisRec - $moisDep;

// ─── ANALYSE FINANCIÈRE PAR PÉRIODE ──────────────────────────────────────────
// 12 derniers mois pour le graphique
$r = $db->prepare("
    SELECT DATE_FORMAT(p.created_at,'%Y-%m') as mois,
           COALESCE(SUM(p.montant),0) as recettes
    FROM paiements p JOIN locations l ON l.id=p.location_id
    WHERE l.vehicule_id=? AND p.tenant_id=? AND p.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY mois ORDER BY mois
");
$r->execute([$vehiculeId, $tenantId]); $recParMois = $r->fetchAll(PDO::FETCH_ASSOC);

$r = $db->prepare("
    SELECT DATE_FORMAT(date_charge,'%Y-%m') as mois,
           COALESCE(SUM(montant),0) as charges
    FROM charges WHERE vehicule_id=? AND tenant_id=? AND date_charge >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY mois ORDER BY mois
");
$r->execute([$vehiculeId, $tenantId]); $depParMois = $r->fetchAll(PDO::FETCH_ASSOC);

// Fusionner par mois (12 derniers mois)
$allMois = [];
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $allMois[$m] = ['mois' => $m, 'recettes' => 0, 'charges' => 0];
}
foreach ($recParMois as $row) if (isset($allMois[$row['mois']])) $allMois[$row['mois']]['recettes'] = (float)$row['recettes'];
foreach ($depParMois as $row) if (isset($allMois[$row['mois']])) $allMois[$row['mois']]['charges']  = (float)$row['charges'];
// Ajouter recettes taxi au graphique 12 mois
if ($vehicule['type_vehicule'] === 'taxi') {
    $r = $db->prepare("SELECT DATE_FORMAT(pt.date_paiement,'%Y-%m') as mois, COALESCE(SUM(pt.montant),0) as recettes FROM paiements_taxi pt JOIN taximetres tx ON tx.id=pt.taximetre_id WHERE tx.vehicule_id=? AND pt.tenant_id=? AND pt.statut_jour='paye' AND pt.date_paiement >= DATE_SUB(NOW(), INTERVAL 12 MONTH) GROUP BY mois ORDER BY mois");
    $r->execute([$vehiculeId, $tenantId]);
    foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row)
        if (isset($allMois[$row['mois']])) $allMois[$row['mois']]['recettes'] += (float)$row['recettes'];
}
$allMois = array_values($allMois);

// ─── HISTORIQUE ──────────────────────────────────────────────────────────────
$tab      = $_GET['tab'] ?? 'locations';
$filterAn = (int)($_GET['annee'] ?? 0);

$locWhere = "WHERE l.vehicule_id=? AND l.tenant_id=?"; $locParams = [$vehiculeId, $tenantId];
if ($filterAn) { $locWhere .= " AND YEAR(l.date_debut)=?"; $locParams[] = $filterAn; }
$stmtLoc = $db->prepare("SELECT l.*, c.nom as client_nom, c.prenom as client_prenom, c.telephone as client_tel FROM locations l LEFT JOIN clients c ON c.id=l.client_id $locWhere ORDER BY l.date_debut DESC");
$stmtLoc->execute($locParams);
$locations = $stmtLoc->fetchAll(PDO::FETCH_ASSOC);

$chWhere = "WHERE vehicule_id=? AND tenant_id=?"; $chParams = [$vehiculeId, $tenantId];
if ($filterAn) { $chWhere .= " AND YEAR(date_charge)=?"; $chParams[] = $filterAn; }
$stmtCh = $db->prepare("SELECT * FROM charges $chWhere ORDER BY date_charge DESC");
$stmtCh->execute($chParams); $historiqueCharges = $stmtCh->fetchAll(PDO::FETCH_ASSOC);

$stmtM = $db->prepare("SELECT * FROM maintenances WHERE vehicule_id=? AND tenant_id=? ORDER BY FIELD(statut,'en_retard','planifie','fait','termine'), date_prevue DESC");
$stmtM->execute([$vehiculeId, $tenantId]); $historiqueMaints = $stmtM->fetchAll(PDO::FETCH_ASSOC);

// TAXI
$taximetreActif = null; $paiementsTaxi = [];
if ($vehicule['type_vehicule'] === 'taxi') {
    $r = $db->prepare("SELECT * FROM taximetres WHERE vehicule_id=? AND tenant_id=? ORDER BY created_at DESC LIMIT 1");
    $r->execute([$vehiculeId, $tenantId]); $taximetreActif = $r->fetch(PDO::FETCH_ASSOC);
    if ($taximetreActif) {
        $r = $db->prepare("SELECT * FROM paiements_taxi WHERE taximetre_id=? AND tenant_id=? ORDER BY date_paiement DESC LIMIT 60");
        $r->execute([$taximetreActif['id'], $tenantId]); $paiementsTaxi = $r->fetchAll(PDO::FETCH_ASSOC);
    }
}

// RÈGLES GPS pour ce véhicule
$stmtRegles = $db->prepare("SELECT * FROM regles_gps WHERE tenant_id=? AND vehicule_id=? ORDER BY created_at DESC");
$stmtRegles->execute([$tenantId, $vehiculeId]);
$reglesVehicule = $stmtRegles->fetchAll(PDO::FETCH_ASSOC);

// Alertes récentes liées à ce véhicule
$stmtAlertes = $db->prepare("SELECT * FROM alertes_regles WHERE vehicule_id=? AND tenant_id=? ORDER BY created_at DESC LIMIT 10");
$stmtAlertes->execute([$vehiculeId, $tenantId]);
$alertesVehicule = $stmtAlertes->fetchAll(PDO::FETCH_ASSOC);

$annees = array_column($db->prepare("SELECT DISTINCT YEAR(date_debut) y FROM locations WHERE vehicule_id=? AND tenant_id=? ORDER BY y DESC")->execute([$vehiculeId, $tenantId]) ? $db->prepare("SELECT DISTINCT YEAR(date_debut) y FROM locations WHERE vehicule_id=? AND tenant_id=? ORDER BY y DESC")->execute([$vehiculeId, $tenantId]) ? [] : [] : [], 'y');
// Reload annees properly
$r2 = $db->prepare("SELECT DISTINCT YEAR(date_debut) y FROM locations WHERE vehicule_id=? AND tenant_id=? ORDER BY y DESC");
$r2->execute([$vehiculeId, $tenantId]); $annees = array_column($r2->fetchAll(PDO::FETCH_ASSOC), 'y');

// GPS
$aGps     = !empty($vehicule['traccar_device_id']) && hasGpsModule();
$deviceId = (int)($vehicule['traccar_device_id'] ?? 0);

// Alertes docs
$today = date('Y-m-d');
$in30  = date('Y-m-d', strtotime('+30 days'));
$alertAssurance = '';
if (!empty($vehicule['date_expiration_assurance'])) {
    if ($vehicule['date_expiration_assurance'] < $today) $alertAssurance = 'expired';
    elseif ($vehicule['date_expiration_assurance'] <= $in30) $alertAssurance = 'expiring';
}
$alertVignette = '';
if (!empty($vehicule['date_expiration_vignette'])) {
    if ($vehicule['date_expiration_vignette'] < $today) $alertVignette = 'expired';
    elseif ($vehicule['date_expiration_vignette'] <= $in30) $alertVignette = 'expiring';
}

$pageTitle  = $vehicule['nom'];
$activePage = 'vehicules';

// GPS JS
$extraJs = '';
if ($aGps) {
    $vehNomJs = addslashes($vehicule['nom']);
    $csrfVal  = generateCSRF();
    $extraJs = <<<JS
var map = null, marker = null;
function initMap() {
    if (map) return;
    map = L.map('carte-gps').setView([5.3599,-4.0083],12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19}).addTo(map);
}
function chargerPosition() {
    fetch('<?= BASE_URL ?>api/gps.php?action=position&vehicule_id={$vehiculeId}&t='+Date.now())
    .then(r=>r.json()).then(data=>{
        if(data.lat && data.lng){
            var lat=data.lat, lng=data.lng;
            var vitesse=Math.round(data.vitesse||0);
            var moteur=data.moteur?'ON':'OFF';
            document.getElementById('gps-vitesse').textContent=vitesse+' km/h';
            var mel=document.getElementById('gps-moteur');
            mel.textContent='Moteur '+moteur; mel.style.color=moteur==='ON'?'#10b981':'#ef4444';
            var time=data.horodatage?new Date(data.horodatage).toLocaleString('fr-FR'):'--';
            document.getElementById('gps-maj').textContent=time;
            if(data.adresse) document.getElementById('gps-addr').textContent=data.adresse;
            document.getElementById('gps-status').textContent='EN LIGNE';
            document.getElementById('gps-status').style.color='#10b981';
            document.getElementById('gps-actions').style.display='flex';
            initMap();
            if(marker){marker.setLatLng([lat,lng]);}
            else{marker=L.marker([lat,lng]).addTo(map).bindPopup('<strong>{$vehNomJs}</strong><br>'+vitesse+' km/h — Moteur '+moteur);}
            map.setView([lat,lng],14);
            marker.getPopup().setContent('<strong>{$vehNomJs}</strong><br>'+vitesse+' km/h — Moteur '+moteur);
        } else {
            document.getElementById('gps-status').textContent='HORS LIGNE';
            document.getElementById('gps-status').style.color='#ef4444';
            document.getElementById('gps-actions').style.display='none';
        }
    }).catch(()=>{});
}
function actionMoteur(action){
    if(!confirm('Confirmer cette action sur le moteur ?')) return;
    var btn=event.target; btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> En cours…';
    fetch('<?= BASE_URL ?>api/gps.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action='+action+'&vehicule_id={$vehiculeId}&csrf_token={$csrfVal}'})
    .then(r=>r.json()).then(d=>{
        btn.disabled=false; btn.innerHTML=action==='stop_engine'?'<i class="fas fa-stop-circle"></i> Couper':'<i class="fas fa-play-circle"></i> Démarrer';
        alert(d.success?'✅ Commande envoyée avec succès':'❌ Erreur: '+(d.error||'Commande non supportée par ce boîtier'));
        if(d.success) setTimeout(chargerPosition,3000);
    }).catch(()=>{ btn.disabled=false; alert('Erreur réseau'); });
}
chargerPosition(); setInterval(chargerPosition,30000);
JS;
}

require_once BASE_PATH . '/includes/header.php';

function badgeType(string $t): string {
    return match($t) {
        'location'   => '<span class="badge" style="background:#dbeafe;color:#0d9488">Location</span>',
        'taxi'       => '<span class="badge" style="background:#fef3c7;color:#d97706">VTC/Taxi</span>',
        'entreprise' => '<span class="badge" style="background:#d1fae5;color:#059669">Entreprise</span>',
        default      => '<span class="badge bg-secondary">' . sanitize($t) . '</span>',
    };
}

$typeReglesLabels = [
    'vitesse'        => ['Vitesse excessive',     'fas fa-tachometer-alt', '#ef4444'],
    'horaire'        => ['Horaire de circulation', 'fas fa-clock',          '#14b8a6'],
    'km_jour'        => ['Km/jour max',            'fas fa-road',           '#8b5cf6'],
    'ralenti'        => ['Ralenti prolongé',       'fas fa-hourglass-half', '#f59e0b'],
    'immobilisation' => ['Immobilisation longue',  'fas fa-parking',        '#64748b'],
    'geofence'       => ['Zone géographique',      'fas fa-draw-polygon',   '#059669'],
];
?>

<style>
/* ── Barre KPIs compacte ── */
.kpi-strip { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin-bottom:16px; }
.kpi-strip-card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; display:flex; align-items:center; gap:10px; }
.kpi-strip-icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
.kpi-strip-info .kv { font-size:1.05rem; font-weight:800; color:#0f172a; line-height:1; }
.kpi-strip-info .kl { font-size:.65rem; color:#94a3b8; text-transform:uppercase; letter-spacing:.05em; margin-top:2px; }

/* ── Deux colonnes ── */
.detail-grid { display:grid; grid-template-columns:1fr 280px; gap:16px; }
@media(max-width:900px) { .detail-grid { grid-template-columns:1fr; } .kpi-strip { grid-template-columns:repeat(3,1fr); } }
@media(max-width:580px) { .kpi-strip { grid-template-columns:1fr 1fr; } }

/* ── Info véhicule ── */
.info-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px 16px; }
.info-item .il { font-size:.67rem; color:#94a3b8; text-transform:uppercase; letter-spacing:.05em; }
.info-item .iv { font-size:.855rem; font-weight:600; color:#0f172a; margin-top:1px; }

/* ── Tabs historique ── */
.hist-tabs { display:flex; gap:2px; border-bottom:2px solid #e2e8f0; }
.hist-tab { padding:7px 14px; font-size:.8rem; font-weight:600; cursor:pointer; text-decoration:none; color:#64748b; border-bottom:2px solid transparent; margin-bottom:-2px; white-space:nowrap; }
.hist-tab.active { color:#0d9488; border-bottom-color:#0d9488; }

/* ── GPS compact ── */
.gps-mini-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; margin-bottom:10px; }
.gps-mini-stat { background:#f8fafc; border-radius:8px; padding:9px 10px; text-align:center; }
.gps-mini-stat .gv { font-size:.95rem; font-weight:800; color:#0f172a; }
.gps-mini-stat .gl { font-size:.6rem; color:#94a3b8; text-transform:uppercase; letter-spacing:.05em; margin-top:2px; }

/* ── Doc alerts ── */
.doc-alert { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:4px; font-size:.72rem; font-weight:600; }
.doc-alert.expired  { background:#fee2e2; color:#ef4444; }
.doc-alert.expiring { background:#fef3c7; color:#d97706; }
.doc-alert.ok       { background:#d1fae5; color:#059669; }

/* ── Règles GPS ── */
.regle-item { display:flex; align-items:center; gap:10px; padding:10px 14px; border-bottom:1px solid #f1f5f9; }
.regle-item:last-child { border-bottom:none; }
.regle-icon { width:34px; height:34px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:.85rem; flex-shrink:0; }
.regle-info { flex:1; min-width:0; }
.regle-label { font-size:.82rem; font-weight:700; color:#0f172a; }
.regle-params { font-size:.7rem; color:#64748b; margin-top:1px; }
.regle-actions { display:flex; gap:4px; }

/* ── Alerte item ── */
.alerte-item { display:flex; gap:8px; padding:8px 14px; border-bottom:1px solid #f1f5f9; align-items:flex-start; }
.alerte-item:last-child { border-bottom:none; }
.alerte-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; margin-top:5px; }

/* ── Sidebar cards ── */
.side-row { display:flex; justify-content:space-between; align-items:center; padding:6px 10px; border-radius:6px; font-size:.78rem; }

/* ── Mobile responsive ──────────────────────────────────────────────────── */
@media(max-width:960px) {
  .detail-grid { grid-template-columns:1fr; }
  .kpi-strip { grid-template-columns:repeat(2,1fr); gap:8px; }
}
@media(max-width:640px) {
  .page-header { flex-direction:column !important; align-items:flex-start !important; gap:8px !important; }
  .page-header > div:last-child { width:100%; display:flex; flex-wrap:wrap; gap:6px; }
  .page-header > div:last-child .btn { flex:1; min-width:0; text-align:center; font-size:.72rem; padding:6px 8px; white-space:nowrap; }
  .page-title { font-size:1.1rem !important; display:flex; align-items:center; flex-wrap:wrap; gap:4px; }

  .kpi-strip { grid-template-columns:1fr 1fr !important; gap:8px !important; margin-bottom:10px !important; }
  .kpi-strip-card { padding:10px 12px !important; gap:8px !important; border-radius:10px !important; }
  .kpi-strip-icon { width:32px !important; height:32px !important; font-size:.85rem !important; border-radius:8px !important; }
  .kpi-strip-info .kv { font-size:.88rem !important; }
  .kpi-strip-info .kl { font-size:.58rem !important; }

  .detail-grid { grid-template-columns:1fr !important; gap:12px !important; }

  .info-grid { grid-template-columns:1fr 1fr !important; gap:6px 12px !important; }
  .card-body > div[style*="grid-template-columns:140px"] {
    grid-template-columns:1fr !important;
  }
  .card-body > div[style*="grid-template-columns:140px"] img,
  .card-body > div[style*="grid-template-columns:140px"] > div:first-child {
    max-height:100px !important; width:100% !important;
  }

  .gps-mini-stats { grid-template-columns:1fr 1fr !important; gap:6px !important; }
  .gps-mini-stat { padding:8px !important; }
  .gps-mini-stat .gv { font-size:.82rem !important; }
  #carte-gps { height:180px !important; }

  .hist-tabs { overflow-x:auto !important; -webkit-overflow-scrolling:touch; gap:0 !important; padding-bottom:4px; }
  .hist-tab { padding:6px 10px !important; font-size:.72rem !important; white-space:nowrap !important; }

  .table { font-size:.72rem !important; }
  .table th, .table td { padding:6px 8px !important; }
  .table-responsive { overflow-x:auto; -webkit-overflow-scrolling:touch; }

  /* Analyse financière mobile */
  div[style*="grid-template-columns:repeat(3,1fr)"] { grid-template-columns:1fr !important; gap:8px !important; }
  div[style*="display:flex"][style*="align-items:flex-end"][style*="height:120px"] { height:80px !important; }
  #fin-form { flex-wrap:wrap !important; }
  #fin-form input[type="date"] { width:120px !important; font-size:.75rem !important; }
  div[style*="display:flex"][style*="gap:4px"][style*="flex-wrap:wrap"] a { padding:3px 8px !important; font-size:.65rem !important; }
  div[style*="Période d'analyse"] { flex-direction:column !important; }
  div[style*="flex:1;min-width:200px"] { min-width:0 !important; width:100% !important; }
  div[style*="flex:1;min-width:200px"] > div[style*="display:flex"] { flex-direction:column !important; gap:6px !important; }

  /* Documents section mobile */
  div[style*="display:flex"][style*="gap:10px"][style*="margin-top:12px"][style*="flex-wrap:wrap"] {
    flex-direction:column !important; gap:8px !important;
  }

  /* Règles GPS mobile */
  .regle-item { flex-wrap:wrap; gap:8px; padding:10px 12px; }
  .regle-actions { width:100%; justify-content:flex-end; }

  /* Sidebar cards mobile */
  .side-row { font-size:.73rem !important; padding:5px 8px !important; }

  /* Alertes GPS mobile */
  .alerte-item { padding:8px 12px; }

  /* GPS actions mobile */
  #gps-actions { flex-wrap:wrap !important; }
  #gps-actions .btn { flex:1; font-size:.72rem; }
}

@media print { .sidebar,.topbar,.btn,.actions-panel { display:none!important } .main-content { margin:0!important; padding:0!important } }
</style>

<!-- ─── PAGE HEADER ─────────────────────────────────────────────────────────── -->
<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:14px">
    <div>
        <a href="<?= BASE_URL ?>app/vehicules/liste.php" class="btn btn-secondary btn-sm" style="margin-bottom:5px">
            <i class="fas fa-arrow-left"></i> Liste
        </a>
        <h1 class="page-title" style="margin-bottom:3px">
            <?= sanitize($vehicule['nom']) ?>
            &nbsp;<?= badgeVehicule($vehicule['statut']) ?>
            &nbsp;<?= badgeType($vehicule['type_vehicule'] ?? 'location') ?>
        </h1>
        <p class="page-subtitle">
            <?= sanitize($vehicule['immatriculation']) ?>
            <?php if ($vehicule['marque']): ?>&nbsp;·&nbsp;<?= sanitize($vehicule['marque'] . ' ' . ($vehicule['modele'] ?? '')) ?><?php endif ?>
            <?php if ($vehicule['annee']): ?>&nbsp;·&nbsp;<?= (int)$vehicule['annee'] ?><?php endif ?>
        </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($aGps): ?>
        <a href="<?= BASE_URL ?>app/gps/carte.php?vehicule=<?= $vehiculeId ?>" class="btn btn-secondary btn-sm"><i class="fas fa-map-marked-alt"></i> Carte GPS</a>
        <?php endif ?>
        <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i> Imprimer</button>
        <a href="<?= BASE_URL ?>app/vehicules/modifier.php?id=<?= $vehiculeId ?>" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> Modifier</a>
        <?php if (hasLocationModule() && $vehicule['statut'] === 'disponible'): ?>
        <a href="<?= BASE_URL ?>app/locations/nouvelle.php?vehicule_id=<?= $vehiculeId ?>" class="btn btn-success btn-sm"><i class="fas fa-file-contract"></i> Louer</a>
        <?php endif ?>
    </div>
</div>

<?= renderFlashes() ?>

<?php if ($alertAssurance || $alertVignette): ?>
<div class="alert alert-<?= ($alertAssurance === 'expired' || $alertVignette === 'expired') ? 'error' : 'warning' ?>" style="margin-bottom:12px">
    <i class="fas fa-exclamation-triangle"></i>
    <?php if ($alertAssurance === 'expired'):  ?><strong>Assurance expirée</strong> (<?= formatDate($vehicule['date_expiration_assurance']) ?>)&nbsp; <?php endif ?>
    <?php if ($alertAssurance === 'expiring'): ?><strong>Assurance expire bientôt</strong> (<?= formatDate($vehicule['date_expiration_assurance']) ?>)&nbsp; <?php endif ?>
    <?php if ($alertVignette  === 'expired'):  ?><strong>Vignette expirée</strong> (<?= formatDate($vehicule['date_expiration_vignette']) ?>)<?php endif ?>
    <?php if ($alertVignette  === 'expiring'): ?><strong>Vignette expire bientôt</strong> (<?= formatDate($vehicule['date_expiration_vignette']) ?>)<?php endif ?>
</div>
<?php endif ?>

<!-- ─── KPI STRIP ─────────────────────────────────────────────────────────────── -->
<div class="kpi-strip" style="grid-template-columns:repeat(4,1fr);margin-bottom:8px">
    <div class="kpi-strip-card" style="border-left:3px solid #0d9488">
        <div class="kpi-strip-icon" style="background:#dbeafe;color:#0d9488"><i class="fas fa-piggy-bank"></i></div>
        <div class="kpi-strip-info">
            <div class="kv"><?= formatMoney($capital) ?></div>
            <div class="kl">Capital investi</div>
        </div>
    </div>
    <div class="kpi-strip-card" style="border-left:3px solid #10b981">
        <div class="kpi-strip-icon" style="background:#d1fae5;color:#059669"><i class="fas fa-coins"></i></div>
        <div class="kpi-strip-info">
            <div class="kv" style="color:#059669"><?= formatMoney($totalRec) ?></div>
            <div class="kl">Recettes totales</div>
        </div>
    </div>
    <div class="kpi-strip-card" style="border-left:3px solid #ef4444">
        <div class="kpi-strip-icon" style="background:#fee2e2;color:#ef4444"><i class="fas fa-receipt"></i></div>
        <div class="kpi-strip-info">
            <div class="kv" style="color:#ef4444"><?= formatMoney($totalDep) ?></div>
            <div class="kl">Dépenses totales</div>
        </div>
    </div>
    <div class="kpi-strip-card" style="border-left:3px solid <?= $benefice >= 0 ? '#10b981' : '#ef4444' ?>">
        <div class="kpi-strip-icon" style="background:<?= $benefice >= 0 ? '#d1fae5' : '#fee2e2' ?>;color:<?= $benefice >= 0 ? '#059669' : '#ef4444' ?>"><i class="fas fa-balance-scale"></i></div>
        <div class="kpi-strip-info">
            <div class="kv" style="color:<?= $benefice >= 0 ? '#059669' : '#ef4444' ?>"><?= formatMoney($benefice) ?></div>
            <div class="kl">Bénéfice net · ROI <?= $roi ?>%</div>
        </div>
    </div>
</div>
<!-- KPI mois en cours -->
<div class="kpi-strip" style="grid-template-columns:repeat(4,1fr);margin-bottom:14px">
    <div class="kpi-strip-card" style="border-left:3px solid #8b5cf6;background:linear-gradient(135deg,#faf5ff,#fff)">
        <div class="kpi-strip-icon" style="background:#ede9fe;color:#7c3aed"><i class="fas fa-calendar-alt"></i></div>
        <div class="kpi-strip-info">
            <div class="kv" style="color:#7c3aed;font-size:.9rem"><?= date('F Y') ?></div>
            <div class="kl">Mois en cours</div>
        </div>
    </div>
    <div class="kpi-strip-card" style="border-left:3px solid #10b981;background:linear-gradient(135deg,#f0fdf4,#fff)">
        <div class="kpi-strip-icon" style="background:#dcfce7;color:#16a34a"><i class="fas fa-arrow-up"></i></div>
        <div class="kpi-strip-info">
            <div class="kv" style="color:#16a34a"><?= formatMoney($moisRec) ?></div>
            <div class="kl">Recettes du mois</div>
        </div>
    </div>
    <div class="kpi-strip-card" style="border-left:3px solid #f97316;background:linear-gradient(135deg,#fff7ed,#fff)">
        <div class="kpi-strip-icon" style="background:#ffedd5;color:#ea580c"><i class="fas fa-arrow-down"></i></div>
        <div class="kpi-strip-info">
            <div class="kv" style="color:#ea580c"><?= formatMoney($moisDep) ?></div>
            <div class="kl">Charges du mois</div>
        </div>
    </div>
    <div class="kpi-strip-card" style="border-left:3px solid <?= $moisBen >= 0 ? '#10b981' : '#ef4444' ?>;background:linear-gradient(135deg,<?= $moisBen >= 0 ? '#f0fdf4' : '#fff5f5' ?>,#fff)">
        <div class="kpi-strip-icon" style="background:<?= $moisBen >= 0 ? '#dcfce7' : '#fee2e2' ?>;color:<?= $moisBen >= 0 ? '#16a34a' : '#ef4444' ?>"><i class="fas fa-chart-line"></i></div>
        <div class="kpi-strip-info">
            <div class="kv" style="color:<?= $moisBen >= 0 ? '#16a34a' : '#ef4444' ?>"><?= formatMoney($moisBen) ?></div>
            <div class="kl">Résultat du mois · <?= $tauxOcc ?>% occ.</div>
        </div>
    </div>
</div>

<!-- ─── GRILLE PRINCIPALE ──────────────────────────────────────────────────── -->
<div class="detail-grid">

<!-- ═══ COLONNE PRINCIPALE ════════════════════════════════════════════════════ -->
<div>

<!-- ── Infos véhicule ────────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:14px">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-info-circle"></i> Informations</h3>
        <a href="<?= BASE_URL ?>app/vehicules/modifier.php?id=<?= $vehiculeId ?>" class="btn btn-ghost btn-sm"><i class="fas fa-edit"></i> Modifier</a>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:140px 1fr;gap:14px;align-items:start">
            <?php if (!empty($vehicule['photo'])): ?>
            <img src="<?= BASE_URL ?>uploads/logos/<?= sanitize($vehicule['photo']) ?>"
                 style="width:100%;border-radius:8px;border:1px solid #e2e8f0;object-fit:cover;max-height:120px" alt="">
            <?php else: ?>
            <div style="height:110px;background:#f8fafc;border-radius:8px;display:flex;align-items:center;justify-content:center;border:2px dashed #e2e8f0">
                <i class="fas fa-car" style="font-size:2rem;color:#cbd5e1"></i>
            </div>
            <?php endif ?>
            <div class="info-grid">
                <div class="info-item"><div class="il">Immatriculation</div><div class="iv"><?= sanitize($vehicule['immatriculation']) ?></div></div>
                <div class="info-item"><div class="il">Marque / Modèle</div><div class="iv"><?= sanitize(trim(($vehicule['marque'] ?? '—') . ' ' . ($vehicule['modele'] ?? ''))) ?></div></div>
                <div class="info-item"><div class="il">Année</div><div class="iv"><?= $vehicule['annee'] ?: '—' ?></div></div>
                <div class="info-item"><div class="il">Couleur</div><div class="iv"><?= sanitize($vehicule['couleur'] ?? '—') ?></div></div>
                <div class="info-item"><div class="il">Carburant</div><div class="iv"><?= sanitize(ucfirst($vehicule['carburant_type'] ?? $vehicule['type_carburant'] ?? '—')) ?></div></div>
                <div class="info-item"><div class="il">Km actuel</div><div class="iv"><?= number_format((int)$vehicule['kilometrage_actuel'], 0, ',', ' ') ?> km</div></div>
                <div class="info-item"><div class="il">Prix / jour</div><div class="iv" style="color:#0d9488"><?= $vehicule['prix_location_jour'] ? formatMoney((float)$vehicule['prix_location_jour']) : '—' ?></div></div>
                <div class="info-item"><div class="il">Chassis</div><div class="iv" style="font-size:.76rem"><?= sanitize($vehicule['numero_chassis'] ?? '—') ?></div></div>
                <div class="info-item"><div class="il">Ajouté le</div><div class="iv"><?= formatDate($vehicule['created_at']) ?></div></div>
            </div>
        </div>

        <!-- Documents -->
        <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;padding-top:10px;border-top:1px solid #e2e8f0">
            <div>
                <div style="font-size:.67rem;color:#94a3b8;text-transform:uppercase;margin-bottom:3px">Assurance</div>
                <?php if ($vehicule['date_expiration_assurance']): ?>
                <span class="doc-alert <?= $alertAssurance ?: 'ok' ?>"><i class="fas fa-shield-alt"></i> <?= formatDate($vehicule['date_expiration_assurance']) ?></span>
                <?php else: ?><span style="font-size:.8rem;color:#94a3b8">Non renseigné</span><?php endif ?>
            </div>
            <div>
                <div style="font-size:.67rem;color:#94a3b8;text-transform:uppercase;margin-bottom:3px">Vignette</div>
                <?php if ($vehicule['date_expiration_vignette']): ?>
                <span class="doc-alert <?= $alertVignette ?: 'ok' ?>"><i class="fas fa-certificate"></i> <?= formatDate($vehicule['date_expiration_vignette']) ?></span>
                <?php else: ?><span style="font-size:.8rem;color:#94a3b8">Non renseigné</span><?php endif ?>
            </div>
            <?php if ($vehicule['prochaine_vidange_km']): ?>
            <div>
                <div style="font-size:.67rem;color:#94a3b8;text-transform:uppercase;margin-bottom:3px">Prochaine vidange</div>
                <?php $kmRest = (int)$vehicule['prochaine_vidange_km'] - (int)$vehicule['kilometrage_actuel']; ?>
                <span class="doc-alert <?= $kmRest <= 0 ? 'expired' : ($kmRest <= 1000 ? 'expiring' : 'ok') ?>">
                    <i class="fas fa-oil-can"></i>
                    <?= number_format((int)$vehicule['prochaine_vidange_km'], 0, ',', ' ') ?> km
                    <?= $kmRest <= 0 ? '(dépassé)' : '(+' . number_format($kmRest,0,',',' ') . ' km)' ?>
                </span>
            </div>
            <?php endif ?>
        </div>

        <?php if ($vehicule['notes']): ?>
        <div style="margin-top:10px;padding:8px 12px;background:#f8fafc;border-left:3px solid #0d9488;border-radius:4px;font-size:.83rem">
            <?= nl2br(sanitize($vehicule['notes'])) ?>
        </div>
        <?php endif ?>
    </div>
</div>

<!-- ── GPS temps réel ─────────────────────────────────────────────────────── -->
<?php if (hasGpsModule()): ?>
<div class="card" style="margin-bottom:14px">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-satellite-dish"></i> GPS temps réel
            <?= $aGps ? '<span class="badge bg-success" style="font-size:.65rem;margin-left:6px"><i class="fas fa-circle" style="font-size:.45rem"></i> Actif</span>' : '' ?>
        </h3>
        <?php if ($aGps): ?>
        <div style="display:flex;gap:6px">
            <a href="<?= BASE_URL ?>app/gps/carte.php?vehicule=<?= $vehiculeId ?>" class="btn btn-ghost btn-sm"><i class="fas fa-map"></i> Carte</a>
            <a href="<?= BASE_URL ?>app/gps/historique.php?vehicule_id=<?= $vehiculeId ?>" class="btn btn-ghost btn-sm"><i class="fas fa-route"></i> Trajets</a>
        </div>
        <?php endif ?>
    </div>
    <div class="card-body" style="padding:12px 14px">
    <?php if ($aGps): ?>
        <div class="gps-mini-stats">
            <div class="gps-mini-stat">
                <div class="gv" id="gps-vitesse">—</div>
                <div class="gl">Vitesse</div>
            </div>
            <div class="gps-mini-stat">
                <div class="gv" id="gps-moteur" style="font-size:.78rem">—</div>
                <div class="gl">Moteur</div>
            </div>
            <div class="gps-mini-stat">
                <div class="gv" id="gps-status" style="font-size:.72rem;color:#94a3b8">—</div>
                <div class="gl">Statut</div>
            </div>
            <div class="gps-mini-stat">
                <div class="gv" id="gps-maj" style="font-size:.65rem;color:#94a3b8">—</div>
                <div class="gl">Dernier signal</div>
            </div>
        </div>
        <div id="gps-addr" style="font-size:.72rem;color:#64748b;margin-bottom:8px;padding:6px 10px;background:#f8fafc;border-radius:6px"><i class="fas fa-map-marker-alt" style="color:#0d9488;margin-right:4px"></i>Chargement de la position…</div>
        <div id="carte-gps" style="height:220px;border-radius:8px;border:1px solid #e2e8f0"></div>
        <?php if (isPlanPro()): ?>
        <div id="gps-actions" style="display:none;gap:8px;margin-top:10px">
            <button class="btn btn-danger btn-sm" onclick="actionMoteur('stop_engine')"><i class="fas fa-stop-circle"></i> Couper moteur</button>
            <button class="btn btn-success btn-sm" onclick="actionMoteur('start_engine')"><i class="fas fa-play-circle"></i> Démarrer moteur</button>
        </div>
        <?php endif ?>
    <?php else: ?>
        <div class="alert alert-info" style="margin:0">
            <i class="fas fa-info-circle"></i> Boîtier GPS non configuré.
            <a href="<?= BASE_URL ?>app/vehicules/modifier.php?id=<?= $vehiculeId ?>">Configurer l'IMEI →</a>
        </div>
    <?php endif ?>
    </div>
</div>
<?php endif ?>

<!-- ── Historique tabulé ─────────────────────────────────────────────────── -->
<div class="card" id="historique">
    <div class="card-body" style="padding:10px 14px 0">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:0">
            <div class="hist-tabs" style="overflow-x:auto">
                <?php
                $tabs = ['locations' => ['Locations', 'fas fa-file-contract', count($locations)],
                         'charges'   => ['Charges',   'fas fa-receipt',       count($historiqueCharges)],
                         'maintenances' => ['Maintenances','fas fa-wrench',    count($historiqueMaints)]];
                if ($vehicule['type_vehicule'] === 'taxi')
                    $tabs['taxi'] = ['Paiements taxi','fas fa-money-bill-wave', count($paiementsTaxi)];
                $tabs['finances'] = ['Analyse financière','fas fa-chart-bar', ''];
                if (hasGpsModule())
                    $tabs['regles'] = ['Règles GPS','fas fa-sliders-h', count($reglesVehicule)];
                foreach ($tabs as $key => [$lbl, $icon, $cnt]):
                    $url = BASE_URL . 'app/vehicules/detail.php?' . http_build_query(['id' => $vehiculeId, 'tab' => $key, 'annee' => $filterAn ?: '']) . '#historique';
                ?>
                <a href="<?= $url ?>" class="hist-tab <?= $tab === $key ? 'active' : '' ?>">
                    <i class="<?= $icon ?>"></i> <?= $lbl ?>
                    <?php if ($cnt > 0): ?><span style="opacity:.55;font-size:.73rem;margin-left:2px">(<?= $cnt ?>)</span><?php endif ?>
                </a>
                <?php endforeach ?>
            </div>
            <?php if (!empty($annees) && in_array($tab, ['locations','charges'])): ?>
            <form method="GET" style="display:flex;gap:6px">
                <input type="hidden" name="id" value="<?= $vehiculeId ?>">
                <input type="hidden" name="tab" value="<?= sanitize($tab) ?>">
                <select name="annee" class="form-control" style="width:100px;height:30px;padding:0 8px;font-size:.78rem" onchange="this.form.submit()">
                    <option value="">Toutes années</option>
                    <?php foreach ($annees as $a): ?>
                    <option value="<?= $a ?>" <?= $filterAn == $a ? 'selected' : '' ?>><?= $a ?></option>
                    <?php endforeach ?>
                </select>
            </form>
            <?php endif ?>
        </div>
    </div>

    <!-- TAB: Locations -->
    <?php if ($tab === 'locations'): ?>
    <div class="table-responsive">
        <table class="table" style="font-size:.82rem">
            <thead><tr><th>#</th><th>Client</th><th>Début</th><th>Fin</th><th>J</th><th>Montant</th><th>Reste</th><th>Paiement</th><th>Statut</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($locations)): ?>
            <tr><td colspan="10" class="empty-state"><i class="fas fa-calendar-times"></i><br>Aucune location</td></tr>
            <?php else: ?>
            <?php foreach ($locations as $loc):
                $en_retard = $loc['statut'] === 'en_cours' && $loc['date_fin'] < $today; ?>
            <tr style="<?= $en_retard ? 'background:#fff5f5' : '' ?>">
                <td style="color:#94a3b8">#<?= $loc['id'] ?></td>
                <td><strong><?= sanitize($loc['client_nom'] . ' ' . ($loc['client_prenom'] ?? '')) ?></strong>
                    <?php if ($loc['client_tel']): ?><br><span style="color:#94a3b8;font-size:.73rem"><?= sanitize($loc['client_tel']) ?></span><?php endif ?></td>
                <td><?= formatDate($loc['date_debut']) ?></td>
                <td><?= formatDate($loc['date_fin']) ?><?= $en_retard ? ' <span style="color:#ef4444;font-size:.7rem">RETARD</span>' : '' ?></td>
                <td><?= (int)$loc['nombre_jours'] ?>j</td>
                <td><?= formatMoney((float)$loc['montant_final']) ?></td>
                <td style="color:<?= $loc['reste_a_payer'] > 0 ? '#ef4444' : '#10b981' ?>"><?= $loc['reste_a_payer'] > 0 ? formatMoney((float)$loc['reste_a_payer']) : '<i class="fas fa-check"></i>' ?></td>
                <td><?= badgePaiement($loc['statut_paiement']) ?></td>
                <td><?= badgeLocation($loc['statut']) ?></td>
                <td><a href="<?= BASE_URL ?>app/locations/detail.php?id=<?= $loc['id'] ?>" class="btn btn-sm btn-ghost"><i class="fas fa-eye"></i></a></td>
            </tr>
            <?php endforeach ?>
            <?php endif ?>
            </tbody>
            <?php if (!empty($locations)): ?>
            <tfoot style="background:#f8fafc;font-weight:600;font-size:.78rem">
                <tr>
                    <td colspan="5" style="color:#64748b"><?= count($locations) ?> location(s)</td>
                    <td><?= formatMoney(array_sum(array_column($locations, 'montant_final'))) ?></td>
                    <td style="color:#ef4444"><?= formatMoney(array_sum(array_column($locations, 'reste_a_payer'))) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
            <?php endif ?>
        </table>
    </div>

    <!-- TAB: Charges -->
    <?php elseif ($tab === 'charges'): ?>
    <div class="table-responsive">
        <table class="table" style="font-size:.82rem">
            <thead><tr><th>Date</th><th>Libellé</th><th>Catégorie</th><th>Montant</th><th>Notes</th></tr></thead>
            <tbody>
            <?php if (empty($historiqueCharges)): ?>
            <tr><td colspan="5" class="empty-state"><i class="fas fa-receipt"></i><br>Aucune charge</td></tr>
            <?php else: ?>
            <?php foreach ($historiqueCharges as $ch): ?>
            <tr>
                <td><?= formatDate($ch['date_charge']) ?></td>
                <td><strong><?= sanitize($ch['libelle'] ?? $ch['description'] ?? '—') ?></strong></td>
                <td><?= sanitize($ch['categorie'] ?? $ch['type'] ?? '—') ?></td>
                <td style="color:#ef4444;font-weight:600"><?= formatMoney((float)$ch['montant']) ?></td>
                <td style="color:#94a3b8;font-size:.73rem"><?= sanitize(mb_substr($ch['notes'] ?? '', 0, 50)) ?></td>
            </tr>
            <?php endforeach ?>
            <?php endif ?>
            </tbody>
            <?php if (!empty($historiqueCharges)): ?>
            <tfoot style="background:#f8fafc;font-weight:600;font-size:.78rem">
                <tr><td colspan="3" style="color:#64748b"><?= count($historiqueCharges) ?> charge(s)</td>
                <td style="color:#ef4444"><?= formatMoney(array_sum(array_column($historiqueCharges, 'montant'))) ?></td><td></td></tr>
            </tfoot>
            <?php endif ?>
        </table>
    </div>

    <!-- TAB: Maintenances -->
    <?php elseif ($tab === 'maintenances'): ?>
    <div style="padding:10px 14px;border-bottom:1px solid #e2e8f0">
        <a href="<?= BASE_URL ?>app/maintenances/index.php?vehicule_id=<?= $vehiculeId ?>" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Planifier maintenance</a>
    </div>
    <div class="table-responsive">
        <table class="table" style="font-size:.82rem">
            <thead><tr><th>Type</th><th>Km prévu / fait</th><th>Date prévue</th><th>Technicien</th><th>Coût</th><th>Statut</th></tr></thead>
            <tbody>
            <?php if (empty($historiqueMaints)): ?>
            <tr><td colspan="6" class="empty-state"><i class="fas fa-tools"></i><br>Aucune maintenance</td></tr>
            <?php else: ?>
            <?php foreach ($historiqueMaints as $m): ?>
            <tr>
                <td><strong><?= sanitize(ucfirst($m['type'])) ?></strong></td>
                <td><?= $m['km_prevu'] ? number_format((int)$m['km_prevu'],0,',',' ') . ' km' : '—' ?>
                    <?php if ($m['km_fait']): ?><br><span style="color:#10b981;font-size:.73rem"><?= number_format((int)$m['km_fait'],0,',',' ') ?> km (fait)</span><?php endif ?></td>
                <td><?= $m['date_prevue'] ? formatDate($m['date_prevue']) : '—' ?></td>
                <td><?= sanitize($m['technicien'] ?? '—') ?></td>
                <td><?= $m['cout'] > 0 ? formatMoney((float)$m['cout']) : '—' ?></td>
                <td><?= match($m['statut']) {
                    'planifie'  => '<span class="badge bg-info">Planifié</span>',
                    'en_retard' => '<span class="badge bg-danger">En retard</span>',
                    'fait'      => '<span class="badge bg-success">Fait</span>',
                    'termine'   => '<span class="badge bg-secondary">Terminé</span>',
                    default     => sanitize($m['statut'])
                } ?></td>
            </tr>
            <?php endforeach ?>
            <?php endif ?>
            </tbody>
        </table>
    </div>

    <!-- TAB: Paiements taxi -->
    <?php elseif ($tab === 'taxi' && $vehicule['type_vehicule'] === 'taxi'): ?>
    <?php if ($taximetreActif): ?>
    <div style="padding:8px 14px;background:#fef9ec;border-bottom:1px solid #fef3c7;font-size:.82rem">
        <strong>Taximantre actif :</strong> <?= sanitize($taximetreActif['nom'] . ' ' . ($taximetreActif['prenom'] ?? '')) ?>
        &nbsp;·&nbsp; Tarif : <?= formatMoney((float)$taximetreActif['tarif_journalier']) ?>/jour
        &nbsp;·&nbsp; <a href="<?= BASE_URL ?>app/taximetres/paiements.php" class="btn btn-warning btn-sm"><i class="fas fa-money-bill-wave"></i> Saisir paiements</a>
    </div>
    <?php endif ?>
    <div class="table-responsive">
        <table class="table" style="font-size:.82rem">
            <thead><tr><th>Date</th><th>Statut</th><th>Km début</th><th>Km fin</th><th>Montant</th><th>Mode</th></tr></thead>
            <tbody>
            <?php if (empty($paiementsTaxi)): ?>
            <tr><td colspan="6" class="empty-state"><i class="fas fa-money-bill-wave"></i><br>Aucun paiement</td></tr>
            <?php else: ?>
            <?php $iconSt = ['paye'=>['✓','#10b981'],'non_paye'=>['✗','#ef4444'],'jour_off'=>['—','#64748b'],'panne'=>['⚠','#f59e0b'],'accident'=>['!','#dc2626']];
            foreach ($paiementsTaxi as $pt): [$ico,$col] = $iconSt[$pt['statut_jour']] ?? ['?','#94a3b8']; ?>
            <tr>
                <td><?= formatDate($pt['date_paiement']) ?></td>
                <td><span style="color:<?= $col ?>;font-weight:600"><?= $ico ?> <?= sanitize(str_replace('_',' ',ucfirst($pt['statut_jour']))) ?></span></td>
                <td><?= $pt['km_debut'] ? number_format((int)$pt['km_debut'],0,',',' ') : '—' ?></td>
                <td><?= $pt['km_fin']   ? number_format((int)$pt['km_fin'],0,',',' ')   : '—' ?></td>
                <td style="font-weight:600;color:<?= $pt['montant'] > 0 ? '#10b981' : '#94a3b8' ?>"><?= $pt['montant'] > 0 ? formatMoney((float)$pt['montant']) : '—' ?></td>
                <td><?= sanitize($pt['mode_paiement'] ?? '—') ?></td>
            </tr>
            <?php endforeach ?>
            <?php endif ?>
            </tbody>
        </table>
    </div>

    <!-- TAB: Analyse financière ══════════════════════════════════════════════════ -->
    <?php elseif ($tab === 'finances'):
        // Filtre période
        $finFrom = $_GET['from'] ?? date('Y-m-01');
        $finTo   = $_GET['to']   ?? date('Y-m-t');
        // Recettes période
        $r = $db->prepare("SELECT COALESCE(SUM(p.montant),0) FROM paiements p JOIN locations l ON l.id=p.location_id WHERE l.vehicule_id=? AND p.tenant_id=? AND DATE(p.created_at) BETWEEN ? AND ?");
        $r->execute([$vehiculeId, $tenantId, $finFrom, $finTo]); $finRec = (float)$r->fetchColumn();
        // Recettes taxi période
        if ($vehicule['type_vehicule'] === 'taxi') {
            $r = $db->prepare("SELECT COALESCE(SUM(pt.montant),0) FROM paiements_taxi pt JOIN taximetres tx ON tx.id=pt.taximetre_id WHERE tx.vehicule_id=? AND pt.tenant_id=? AND pt.statut_jour='paye' AND pt.date_paiement BETWEEN ? AND ?");
            $r->execute([$vehiculeId, $tenantId, $finFrom, $finTo]); $finRec += (float)$r->fetchColumn();
        }
        // Charges période
        $r = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM charges WHERE vehicule_id=? AND tenant_id=? AND date_charge BETWEEN ? AND ?");
        $r->execute([$vehiculeId, $tenantId, $finFrom, $finTo]); $finDep = (float)$r->fetchColumn();
        // maintenances terminées déjà dans charges — pas de double comptage
        $finMaint = 0;
        $finDepTotal = $finDep;
        $finBen      = $finRec - $finDepTotal;
        // Détail charges par catégorie
        $r = $db->prepare("SELECT COALESCE(type,'Autre') as cat, SUM(montant) as total FROM charges WHERE vehicule_id=? AND tenant_id=? AND date_charge BETWEEN ? AND ? GROUP BY cat ORDER BY total DESC");
        $r->execute([$vehiculeId, $tenantId, $finFrom, $finTo]); $chargesParCat = $r->fetchAll(PDO::FETCH_ASSOC);
        // Locations de la période
        $r = $db->prepare("SELECT l.id, c.nom, c.prenom, l.date_debut, l.date_fin, l.nombre_jours, p.montant, l.statut_paiement FROM locations l LEFT JOIN clients c ON c.id=l.client_id LEFT JOIN paiements p ON p.location_id=l.id WHERE l.vehicule_id=? AND l.tenant_id=? AND DATE(COALESCE(p.created_at,l.date_debut)) BETWEEN ? AND ? ORDER BY l.date_debut DESC");
        $r->execute([$vehiculeId, $tenantId, $finFrom, $finTo]); $finLocs = $r->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div style="padding:14px 16px">
        <!-- Filtre période + export -->
        <div style="display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;margin-bottom:16px;padding:12px 14px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0">
            <div style="flex:1;min-width:200px">
                <label style="font-size:.7rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:4px">Période d'analyse</label>
                <div style="display:flex;gap:8px;align-items:center">
                    <form method="GET" id="fin-form" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                        <input type="hidden" name="id" value="<?= $vehiculeId ?>">
                        <input type="hidden" name="tab" value="finances">
                        <input type="date" name="from" value="<?= $finFrom ?>" class="form-control" style="width:140px;height:32px;font-size:.82rem" onchange="this.form.submit()">
                        <span style="font-size:.8rem;color:#94a3b8">→</span>
                        <input type="date" name="to" value="<?= $finTo ?>" class="form-control" style="width:140px;height:32px;font-size:.82rem" onchange="this.form.submit()">
                    </form>
                    <!-- Raccourcis -->
                    <div style="display:flex;gap:4px;flex-wrap:wrap">
                        <?php
                        $shortcuts = [
                            ['Ce mois', date('Y-m-01'), date('Y-m-t')],
                            ['Mois préc.', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
                            ['3 mois', date('Y-m-01', strtotime('-2 months')), date('Y-m-t')],
                            ['6 mois', date('Y-m-01', strtotime('-5 months')), date('Y-m-t')],
                            ['Cette année', date('Y-01-01'), date('Y-12-31')],
                        ];
                        foreach ($shortcuts as [$lbl, $f, $t]):
                            $active = $finFrom === $f && $finTo === $t;
                        ?>
                        <a href="?id=<?= $vehiculeId ?>&tab=finances&from=<?= $f ?>&to=<?= $t ?>#historique"
                           style="padding:4px 10px;border-radius:6px;font-size:.7rem;font-weight:600;text-decoration:none;background:<?= $active ? '#0d9488' : '#e2e8f0' ?>;color:<?= $active ? '#fff' : '#475569' ?>">
                            <?= $lbl ?>
                        </a>
                        <?php endforeach ?>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:6px">
                <a href="?id=<?= $vehiculeId ?>&export=csv&from=<?= $finFrom ?>&to=<?= $finTo ?>" class="btn btn-secondary btn-sm">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
                <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i> Imprimer</button>
            </div>
        </div>

        <!-- KPI résumé période -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px">
            <div style="background:#d1fae5;border-radius:10px;padding:14px 16px;border-left:4px solid #10b981">
                <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:#065f46;font-weight:700">Recettes période</div>
                <div style="font-size:1.5rem;font-weight:800;color:#059669;margin-top:4px"><?= formatMoney($finRec) ?></div>
            </div>
            <div style="background:#fee2e2;border-radius:10px;padding:14px 16px;border-left:4px solid #ef4444">
                <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:#7f1d1d;font-weight:700">Charges période</div>
                <div style="font-size:1.5rem;font-weight:800;color:#ef4444;margin-top:4px"><?= formatMoney($finDepTotal) ?></div>
                <?php if ($finMaint > 0): ?><div style="font-size:.68rem;color:#94a3b8;margin-top:2px">dont <?= formatMoney($finMaint) ?> maintenances</div><?php endif ?>
            </div>
            <div style="background:<?= $finBen >= 0 ? '#dbeafe' : '#fef9c3' ?>;border-radius:10px;padding:14px 16px;border-left:4px solid <?= $finBen >= 0 ? '#0d9488' : '#d97706' ?>">
                <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:<?= $finBen >= 0 ? '#1e3a5f' : '#713f12' ?>;font-weight:700">Résultat net</div>
                <div style="font-size:1.5rem;font-weight:800;color:<?= $finBen >= 0 ? '#0d9488' : '#d97706' ?>;margin-top:4px"><?= formatMoney($finBen) ?></div>
            </div>
        </div>

        <!-- Graphique barres 12 mois -->
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:16px">
            <div style="font-size:.78rem;font-weight:700;color:#0f172a;margin-bottom:12px"><i class="fas fa-chart-bar" style="color:#0d9488;margin-right:5px"></i> Évolution sur 12 mois</div>
            <?php
            $maxVal = max(1, max(array_map(fn($m) => max($m['recettes'], $m['charges']), $allMois)));
            ?>
            <div style="display:flex;align-items:flex-end;gap:4px;height:120px;border-bottom:2px solid #e2e8f0;padding-bottom:4px">
                <?php foreach ($allMois as $m):
                    $hRec = round($m['recettes'] / $maxVal * 100);
                    $hDep = round($m['charges']  / $maxVal * 100);
                    $label = date('M', mktime(0,0,0,(int)substr($m['mois'],5),1));
                    $isCurrentMonth = $m['mois'] === date('Y-m');
                ?>
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;height:100%">
                    <div style="flex:1;display:flex;align-items:flex-end;gap:1px;width:100%">
                        <div style="flex:1;background:<?= $isCurrentMonth ? '#0d9488' : '#bfdbfe' ?>;border-radius:3px 3px 0 0;height:<?= $hRec ?>%;min-height:<?= $m['recettes'] > 0 ? '2' : '0' ?>px;transition:height .3s" title="Recettes <?= $m['mois'] ?>: <?= formatMoney($m['recettes']) ?>"></div>
                        <div style="flex:1;background:<?= $isCurrentMonth ? '#ef4444' : '#fecaca' ?>;border-radius:3px 3px 0 0;height:<?= $hDep ?>%;min-height:<?= $m['charges'] > 0 ? '2' : '0' ?>px;transition:height .3s" title="Charges <?= $m['mois'] ?>: <?= formatMoney($m['charges']) ?>"></div>
                    </div>
                    <div style="font-size:.55rem;color:#94a3b8;writing-mode:vertical-lr;transform:rotate(180deg);height:18px"><?= $label ?></div>
                </div>
                <?php endforeach ?>
            </div>
            <div style="display:flex;gap:12px;margin-top:8px">
                <span style="font-size:.7rem;display:flex;align-items:center;gap:4px"><span style="width:12px;height:8px;background:#bfdbfe;display:inline-block;border-radius:2px"></span> Recettes</span>
                <span style="font-size:.7rem;display:flex;align-items:center;gap:4px"><span style="width:12px;height:8px;background:#fecaca;display:inline-block;border-radius:2px"></span> Charges</span>
                <span style="font-size:.7rem;display:flex;align-items:center;gap:4px"><span style="width:12px;height:8px;background:#0d9488;display:inline-block;border-radius:2px"></span> Mois en cours</span>
            </div>
        </div>

        <!-- Répartition charges par catégorie -->
        <?php if (!empty($chargesParCat)): ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px">
                <div style="font-size:.78rem;font-weight:700;color:#0f172a;margin-bottom:10px"><i class="fas fa-pie-chart" style="color:#ef4444;margin-right:5px"></i> Charges par catégorie</div>
                <?php
                $totalCh = array_sum(array_column($chargesParCat, 'total'));
                $colors  = ['#ef4444','#f97316','#f59e0b','#84cc16','#06b6d4','#8b5cf6','#ec4899','#94a3b8'];
                foreach ($chargesParCat as $i => $cat):
                    $pct = $totalCh > 0 ? round($cat['total'] / $totalCh * 100) : 0;
                    $col = $colors[$i % count($colors)];
                ?>
                <div style="margin-bottom:8px">
                    <div style="display:flex;justify-content:space-between;margin-bottom:3px">
                        <span style="font-size:.75rem;color:#475569;font-weight:600"><?= sanitize(ucfirst($cat['cat'])) ?></span>
                        <span style="font-size:.75rem;font-weight:700;color:#ef4444"><?= formatMoney($cat['total']) ?> <span style="color:#94a3b8;font-weight:400">(<?= $pct ?>%)</span></span>
                    </div>
                    <div style="height:6px;background:#f1f5f9;border-radius:3px">
                        <div style="height:6px;background:<?= $col ?>;border-radius:3px;width:<?= $pct ?>%"></div>
                    </div>
                </div>
                <?php endforeach ?>
            </div>

            <!-- Locations de la période -->
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;overflow:hidden">
                <div style="font-size:.78rem;font-weight:700;color:#0f172a;margin-bottom:10px"><i class="fas fa-file-contract" style="color:#10b981;margin-right:5px"></i> Encaissements <?= formatDate($finFrom) ?> → <?= formatDate($finTo) ?></div>
                <?php if (empty($finLocs)): ?>
                <div style="text-align:center;color:#94a3b8;font-size:.78rem;padding:20px">Aucun encaissement sur la période</div>
                <?php else: ?>
                <div style="max-height:180px;overflow-y:auto">
                <?php foreach ($finLocs as $fl): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid #f1f5f9;font-size:.76rem">
                    <div>
                        <div style="font-weight:600;color:#0f172a"><?= sanitize($fl['nom'] . ' ' . ($fl['prenom'] ?? '')) ?></div>
                        <div style="color:#94a3b8;font-size:.68rem"><?= formatDate($fl['date_debut']) ?> · <?= (int)$fl['nombre_jours'] ?>j</div>
                    </div>
                    <span style="font-weight:700;color:#059669"><?= formatMoney((float)$fl['montant']) ?></span>
                </div>
                <?php endforeach ?>
                </div>
                <?php endif ?>
            </div>
        </div>
        <?php endif ?>

        <!-- Table mensuelle 12 mois -->
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden">
            <div style="padding:10px 14px;border-bottom:1px solid #e2e8f0;font-size:.78rem;font-weight:700;color:#0f172a">
                <i class="fas fa-table" style="color:#0d9488;margin-right:5px"></i> Tableau mensuel (12 mois glissants)
            </div>
            <table style="width:100%;font-size:.78rem;border-collapse:collapse">
                <thead>
                    <tr style="background:#f8fafc">
                        <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;border-bottom:1px solid #e2e8f0">Mois</th>
                        <th style="padding:8px 12px;text-align:right;color:#059669;font-weight:700;border-bottom:1px solid #e2e8f0">Recettes</th>
                        <th style="padding:8px 12px;text-align:right;color:#ef4444;font-weight:700;border-bottom:1px solid #e2e8f0">Charges</th>
                        <th style="padding:8px 12px;text-align:right;font-weight:700;border-bottom:1px solid #e2e8f0">Résultat</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $totTabRec = $totTabDep = 0;
                foreach (array_reverse($allMois) as $m):
                    $ben = $m['recettes'] - $m['charges'];
                    $totTabRec += $m['recettes'];
                    $totTabDep += $m['charges'];
                    $isCurr = $m['mois'] === date('Y-m');
                ?>
                <tr style="<?= $isCurr ? 'background:#eff6ff' : '' ?>border-bottom:1px solid #f8fafc">
                    <td style="padding:7px 12px;font-weight:<?= $isCurr ? '700' : '400' ?>;color:<?= $isCurr ? '#0d9488' : '#475569' ?>">
                        <?= date('F Y', mktime(0,0,0,(int)substr($m['mois'],5),1)) ?>
                        <?= $isCurr ? '<span style="font-size:.65rem;color:#0d9488;margin-left:4px">← en cours</span>' : '' ?>
                    </td>
                    <td style="padding:7px 12px;text-align:right;color:#059669;font-weight:600"><?= $m['recettes'] > 0 ? formatMoney($m['recettes']) : '<span style="color:#cbd5e1">—</span>' ?></td>
                    <td style="padding:7px 12px;text-align:right;color:#ef4444"><?= $m['charges'] > 0 ? formatMoney($m['charges']) : '<span style="color:#cbd5e1">—</span>' ?></td>
                    <td style="padding:7px 12px;text-align:right;font-weight:700;color:<?= $ben >= 0 ? '#0d9488' : '#ef4444' ?>"><?= ($m['recettes'] > 0 || $m['charges'] > 0) ? formatMoney($ben) : '<span style="color:#cbd5e1">—</span>' ?></td>
                </tr>
                <?php endforeach ?>
                </tbody>
                <tfoot style="background:#f8fafc;font-weight:700">
                    <tr>
                        <td style="padding:8px 12px;font-size:.78rem;color:#0f172a">Total 12 mois</td>
                        <td style="padding:8px 12px;text-align:right;color:#059669"><?= formatMoney($totTabRec) ?></td>
                        <td style="padding:8px 12px;text-align:right;color:#ef4444"><?= formatMoney($totTabDep) ?></td>
                        <td style="padding:8px 12px;text-align:right;color:<?= $totTabRec - $totTabDep >= 0 ? '#0d9488' : '#ef4444' ?>"><?= formatMoney($totTabRec - $totTabDep) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- TAB: Règles GPS ════════════════════════════════════════════════════════ -->
    <?php elseif ($tab === 'regles' && hasGpsModule()): ?>
    <div style="padding:12px 14px">

        <!-- Alertes récentes -->
        <?php if (!empty($alertesVehicule)): ?>
        <div style="margin-bottom:14px">
            <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:6px;font-weight:700">
                <i class="fas fa-bell" style="color:#f59e0b"></i> Alertes récentes
            </div>
            <div style="background:#fffbeb;border:1px solid #fef3c7;border-radius:8px;overflow:hidden">
            <?php
            $alColMap = ['vitesse'=>'#ef4444','horaire'=>'#14b8a6','assurance'=>'#ef4444','vignette'=>'#ef4444','vidange'=>'#f59e0b','km_jour'=>'#8b5cf6','ralenti_debut'=>'#64748b','immobilisation'=>'#64748b','coupure_gps'=>'#ef4444'];
            foreach (array_slice($alertesVehicule, 0, 5) as $al):
                $col = $alColMap[$al['type_alerte']] ?? '#94a3b8'; ?>
            <div class="alerte-item">
                <div class="alerte-dot" style="background:<?= $col ?>"></div>
                <div style="flex:1;min-width:0">
                    <div style="font-size:.76rem;font-weight:600;color:#0f172a"><?= sanitize($al['message']) ?></div>
                    <div style="font-size:.65rem;color:#94a3b8"><?= formatDatetime($al['created_at']) ?></div>
                </div>
                <?= $al['lu'] ? '' : '<span style="background:#ef4444;color:#fff;font-size:.6rem;padding:1px 6px;border-radius:99px;font-weight:700">NON LU</span>' ?>
            </div>
            <?php endforeach ?>
            </div>
            <a href="<?= BASE_URL ?>app/gps/alertes.php?vehicule_id=<?= $vehiculeId ?>" class="btn btn-ghost btn-sm" style="margin-top:6px"><i class="fas fa-list"></i> Voir toutes les alertes</a>
        </div>
        <?php endif ?>

        <!-- Liste des règles de ce véhicule -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;font-weight:700">
                <i class="fas fa-sliders-h" style="color:#0d9488"></i> Règles spécifiques à ce véhicule
                <span style="font-size:.7rem;color:#94a3b8;font-weight:400"> — s'ajoutent aux règles globales</span>
            </div>
            <button onclick="document.getElementById('form-add-regle').style.display = document.getElementById('form-add-regle').style.display==='none'?'block':'none'"
                class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Ajouter une règle</button>
        </div>

        <!-- Formulaire ajout règle (caché par défaut) -->
        <div id="form-add-regle" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:12px">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="act" value="add_regle">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
                    <div>
                        <label class="form-label" style="font-size:.78rem">Type de règle</label>
                        <select name="type_regle" id="type-regle-sel" class="form-control" onchange="updateRegleParams(this.value)" required>
                            <option value="">Choisir…</option>
                            <?php foreach ($typeReglesLabels as $k => [$lbl]): ?>
                            <option value="<?= $k ?>"><?= $lbl ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" style="font-size:.78rem">Libellé personnalisé</label>
                        <input type="text" name="libelle" class="form-control" placeholder="Ex: Vitesse max autoroute" required>
                    </div>
                </div>
                <!-- Paramètres dynamiques -->
                <div id="regle-params-vitesse"        style="display:none;margin-bottom:10px"><label class="form-label" style="font-size:.78rem">Seuil vitesse (km/h)</label><input type="number" name="seuil_kmh" class="form-control" value="90" min="30" max="200"></div>
                <div id="regle-params-horaire"         style="display:none;margin-bottom:10px;display:none"><div style="display:grid;grid-template-columns:1fr 1fr;gap:10px"><div><label class="form-label" style="font-size:.78rem">Heure début autorisée</label><input type="time" name="debut" class="form-control" value="06:00"></div><div><label class="form-label" style="font-size:.78rem">Heure fin autorisée</label><input type="time" name="fin" class="form-control" value="20:00"></div></div></div>
                <div id="regle-params-km_jour"         style="display:none;margin-bottom:10px"><label class="form-label" style="font-size:.78rem">Seuil km/jour</label><input type="number" name="seuil_km" class="form-control" value="250" min="50"></div>
                <div id="regle-params-ralenti"         style="display:none;margin-bottom:10px"><label class="form-label" style="font-size:.78rem">Durée ralenti max (minutes)</label><input type="number" name="seuil_min" class="form-control" value="10" min="1"></div>
                <div id="regle-params-immobilisation"  style="display:none;margin-bottom:10px"><label class="form-label" style="font-size:.78rem">Durée immobilisation max (heures)</label><input type="number" name="seuil_heures" class="form-control" value="24" min="1"></div>
                <div id="regle-params-geofence"        style="display:none;margin-bottom:10px"><label class="form-label" style="font-size:.78rem">Zone (nom de la zone Traccar)</label><input type="text" name="zone" class="form-control" placeholder="Abidjan centre"></div>
                <div class="form-actions" style="margin-top:4px">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('form-add-regle').style.display='none'">Annuler</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Enregistrer la règle</button>
                </div>
            </form>
        </div>

        <!-- Liste règles -->
        <?php if (empty($reglesVehicule)): ?>
        <div style="text-align:center;padding:24px;color:#94a3b8;border:2px dashed #e2e8f0;border-radius:10px">
            <i class="fas fa-sliders-h" style="font-size:1.8rem;display:block;margin-bottom:8px;opacity:.4"></i>
            <div style="font-size:.82rem">Aucune règle spécifique à ce véhicule</div>
            <div style="font-size:.72rem;margin-top:4px">Les règles globales du tenant s'appliquent toujours. <a href="<?= BASE_URL ?>app/gps/regles.php">Voir règles globales →</a></div>
        </div>
        <?php else: ?>
        <div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden">
        <?php foreach ($reglesVehicule as $rg):
            [$rlbl, $ricon, $rcol] = $typeReglesLabels[$rg['type_regle']] ?? ['Règle', 'fas fa-circle', '#94a3b8'];
            $params = json_decode($rg['params'], true) ?? [];
            $paramsStr = match($rg['type_regle']) {
                'vitesse'        => 'Seuil : ' . ($params['seuil_kmh'] ?? '?') . ' km/h',
                'horaire'        => 'Autorisé : ' . ($params['debut'] ?? '?') . ' → ' . ($params['fin'] ?? '?'),
                'km_jour'        => 'Max : ' . ($params['seuil_km'] ?? '?') . ' km/jour',
                'ralenti'        => 'Max : ' . ($params['seuil_min'] ?? '?') . ' min ralenti',
                'immobilisation' => 'Max : ' . ($params['seuil_heures'] ?? '?') . 'h sans mouvement',
                'geofence'       => 'Zone : ' . ($params['zone'] ?? '?'),
                default          => ''
            };
        ?>
        <div class="regle-item">
            <div class="regle-icon" style="background:<?= $rcol ?>1a;color:<?= $rcol ?>">
                <i class="<?= $ricon ?>"></i>
            </div>
            <div class="regle-info">
                <div class="regle-label"><?= sanitize($rg['libelle']) ?></div>
                <div class="regle-params"><?= $paramsStr ?> · <span style="color:#94a3b8;font-size:.65rem">Ajoutée <?= formatDate($rg['created_at']) ?></span></div>
            </div>
            <?= $rg['actif'] ? '<span class="badge bg-success" style="font-size:.65rem">Actif</span>' : '<span class="badge bg-secondary" style="font-size:.65rem">Inactif</span>' ?>
            <div class="regle-actions">
                <form method="POST" style="display:inline">
                    <?= csrfField() ?><input type="hidden" name="act" value="toggle_regle"><input type="hidden" name="regle_id" value="<?= $rg['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" title="<?= $rg['actif'] ? 'Désactiver' : 'Activer' ?>">
                        <i class="fas fa-<?= $rg['actif'] ? 'pause' : 'play' ?>"></i>
                    </button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer cette règle ?')">
                    <?= csrfField() ?><input type="hidden" name="act" value="del_regle"><input type="hidden" name="regle_id" value="<?= $rg['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" style="color:#ef4444"><i class="fas fa-trash"></i></button>
                </form>
            </div>
        </div>
        <?php endforeach ?>
        </div>
        <?php endif ?>

        <div style="margin-top:10px;padding:10px 12px;background:#eff6ff;border-radius:8px;font-size:.75rem;color:#0d9488">
            <i class="fas fa-info-circle"></i>
            <strong>Règles globales :</strong> Les règles définies dans <a href="<?= BASE_URL ?>app/gps/regles.php">GPS → Règles</a> s'appliquent automatiquement à tous les véhicules.
            Les règles ici s'appliquent <strong>uniquement à ce véhicule</strong> et ont priorité sur les règles globales du même type.
        </div>
    </div>
    <?php endif ?>
</div><!-- /card historique -->

</div><!-- /col principale -->

<!-- ═══ SIDEBAR ═══════════════════════════════════════════════════════════════ -->
<div class="actions-panel">

    <!-- Actions rapides -->
    <div class="card" style="margin-bottom:12px">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-bolt"></i> Actions rapides</h3></div>
        <div class="card-body" style="padding:8px;display:flex;flex-direction:column;gap:5px">
            <a href="<?= BASE_URL ?>app/vehicules/modifier.php?id=<?= $vehiculeId ?>" class="btn btn-primary btn-sm" style="text-align:left">
                <i class="fas fa-edit" style="width:16px"></i> Modifier
            </a>
            <?php if (hasLocationModule() && $vehicule['statut'] === 'disponible'): ?>
            <a href="<?= BASE_URL ?>app/locations/nouvelle.php?vehicule_id=<?= $vehiculeId ?>" class="btn btn-success btn-sm" style="text-align:left">
                <i class="fas fa-file-contract" style="width:16px"></i> Créer une location
            </a>
            <?php endif ?>
            <?php if (hasLocationModule()): ?>
            <a href="<?= BASE_URL ?>app/reservations/nouvelle.php?vehicule_id=<?= $vehiculeId ?>" class="btn btn-secondary btn-sm" style="text-align:left">
                <i class="fas fa-calendar-plus" style="width:16px"></i> Réserver
            </a>
            <?php endif ?>
            <a href="<?= BASE_URL ?>app/maintenances/index.php?vehicule_id=<?= $vehiculeId ?>" class="btn btn-secondary btn-sm" style="text-align:left">
                <i class="fas fa-wrench" style="width:16px"></i> Maintenances
            </a>
            <a href="<?= BASE_URL ?>app/finances/charges.php?vehicule_id=<?= $vehiculeId ?>" class="btn btn-secondary btn-sm" style="text-align:left">
                <i class="fas fa-plus-circle" style="width:16px"></i> Ajouter une charge
            </a>
            <?php if ($aGps): ?>
            <a href="<?= BASE_URL ?>app/gps/carte.php?vehicule=<?= $vehiculeId ?>" class="btn btn-secondary btn-sm" style="text-align:left">
                <i class="fas fa-map-marked-alt" style="width:16px"></i> Voir sur la carte
            </a>
            <a href="<?= BASE_URL ?>app/gps/historique.php?vehicule_id=<?= $vehiculeId ?>" class="btn btn-secondary btn-sm" style="text-align:left">
                <i class="fas fa-route" style="width:16px"></i> Historique GPS
            </a>
            <?php endif ?>
            <button onclick="window.print()" class="btn btn-secondary btn-sm" style="text-align:left">
                <i class="fas fa-print" style="width:16px"></i> Imprimer
            </button>
            <hr style="margin:3px 0;border-color:#e2e8f0">
            <button onclick="if(confirm('Supprimer définitivement ce véhicule ?'))document.getElementById('f-supp').submit()" class="btn btn-danger btn-sm" style="text-align:left">
                <i class="fas fa-trash" style="width:16px"></i> Supprimer
            </button>
        </div>
    </div>

    <!-- Bilan résumé -->
    <div class="card" style="margin-bottom:12px">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-chart-bar"></i> Bilan</h3></div>
        <div class="card-body" style="padding:10px 12px;display:flex;flex-direction:column;gap:4px">
            <div class="side-row" style="background:#f8fafc"><span style="color:#64748b">Nb locations</span><strong><?= $nbLocTotal ?></strong></div>
            <div class="side-row" style="background:#d1fae5"><span style="color:#065f46">Recettes</span><strong style="color:#059669"><?= formatMoney($totalRec) ?></strong></div>
            <div class="side-row" style="background:#fee2e2"><span style="color:#7f1d1d">Dépenses</span><strong style="color:#ef4444"><?= formatMoney($totalDep) ?></strong></div>
            <div class="side-row" style="background:<?= $benefice >= 0 ? '#dbeafe' : '#fef9c3' ?>">
                <span>Bénéfice</span>
                <strong style="color:<?= $benefice >= 0 ? '#0d9488' : '#d97706' ?>"><?= formatMoney($benefice) ?></strong>
            </div>
            <div style="padding:6px 10px;background:#f8fafc;border-radius:6px">
                <div style="display:flex;justify-content:space-between;margin-bottom:3px">
                    <span style="font-size:.73rem;color:#64748b">Occupation 30j</span>
                    <strong style="font-size:.75rem"><?= $tauxOcc ?>%</strong>
                </div>
                <div style="height:5px;background:#e2e8f0;border-radius:3px">
                    <div style="height:5px;background:#0d9488;border-radius:3px;width:<?= $tauxOcc ?>%"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Maintenances urgentes -->
    <?php $urgentes = array_filter($historiqueMaints, fn($m) => in_array($m['statut'], ['planifie','en_retard'])); ?>
    <?php if (!empty($urgentes)): ?>
    <div class="card" style="margin-bottom:12px">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-exclamation-triangle" style="color:#f59e0b"></i> Maintenance</h3></div>
        <div class="card-body" style="padding:8px 12px">
            <?php foreach (array_slice($urgentes, 0, 3) as $m): ?>
            <div style="padding:6px 0;border-bottom:1px solid #e2e8f0;font-size:.78rem">
                <span style="font-weight:600"><?= sanitize(ucfirst($m['type'])) ?></span>
                <span class="badge <?= $m['statut'] === 'en_retard' ? 'bg-danger' : 'bg-info' ?>" style="font-size:.62rem;float:right"><?= $m['statut'] === 'en_retard' ? 'Retard' : 'Planifié' ?></span>
                <?php if ($m['km_prevu']): ?><br><span style="color:#94a3b8"><?= number_format((int)$m['km_prevu'],0,',',' ') ?> km</span><?php endif ?>
                <?php if ($m['date_prevue']): ?><br><span style="color:#94a3b8"><?= formatDate($m['date_prevue']) ?></span><?php endif ?>
            </div>
            <?php endforeach ?>
            <a href="<?= BASE_URL ?>app/maintenances/index.php?vehicule_id=<?= $vehiculeId ?>" class="btn btn-ghost btn-sm" style="margin-top:5px;width:100%;text-align:center">Voir toutes</a>
        </div>
    </div>
    <?php endif ?>

    <!-- Règles GPS résumé -->
    <?php if (hasGpsModule() && !empty($reglesVehicule)): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-sliders-h" style="color:#0d9488"></i> Règles GPS</h3>
            <a href="<?= BASE_URL ?>app/vehicules/detail.php?id=<?= $vehiculeId ?>&tab=regles" class="btn btn-ghost btn-sm">Gérer</a>
        </div>
        <div class="card-body" style="padding:6px 10px">
            <?php foreach (array_slice($reglesVehicule, 0, 4) as $rg):
                [$rlbl, $ricon, $rcol] = $typeReglesLabels[$rg['type_regle']] ?? ['Règle','fas fa-circle','#94a3b8']; ?>
            <div style="display:flex;align-items:center;gap:8px;padding:5px 2px;border-bottom:1px solid #f1f5f9">
                <i class="<?= $ricon ?>" style="color:<?= $rcol ?>;width:14px;text-align:center;font-size:.8rem"></i>
                <span style="font-size:.75rem;flex:1;color:#475569"><?= sanitize($rg['libelle']) ?></span>
                <?= $rg['actif'] ? '<span style="width:7px;height:7px;border-radius:50%;background:#22c55e;flex-shrink:0"></span>' : '<span style="width:7px;height:7px;border-radius:50%;background:#cbd5e1;flex-shrink:0"></span>' ?>
            </div>
            <?php endforeach ?>
            <?php if (count($reglesVehicule) > 4): ?>
            <div style="font-size:.72rem;color:#94a3b8;text-align:center;padding:5px">+ <?= count($reglesVehicule) - 4 ?> autre(s)</div>
            <?php endif ?>
        </div>
    </div>
    <?php endif ?>

</div><!-- /sidebar -->
</div><!-- /detail-grid -->

<form id="f-supp" method="POST" action="<?= BASE_URL ?>app/vehicules/supprimer.php" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= $vehiculeId ?>">
</form>

<script>
function updateRegleParams(type) {
    const all = ['vitesse','horaire','km_jour','ralenti','immobilisation','geofence'];
    all.forEach(t => {
        const el = document.getElementById('regle-params-'+t);
        if (el) el.style.display = (t === type) ? 'block' : 'none';
    });
}

// Auto-scroll vers les onglets si tab dans l'URL (évite le scroll-to-top après rechargement)
(function(){
    var params = new URLSearchParams(window.location.search);
    if (params.get('tab') && location.hash !== '#historique') {
        var el = document.getElementById('historique');
        if (el) { setTimeout(function(){ el.scrollIntoView({behavior:'instant',block:'start'}); }, 50); }
    }
})();

// Le formulaire date finance doit rediriger avec #historique
(function(){
    var form = document.getElementById('fin-form');
    if (form) {
        form.addEventListener('submit', function(e){
            e.preventDefault();
            var params = new URLSearchParams(new FormData(form));
            window.location.href = '?' + params.toString() + '#historique';
        });
    }
})();
</script>

<?php
$extraJs .= "\nfunction confirmerSuppression(){if(confirm('Supprimer ?'))document.getElementById('f-supp').submit();}";
require_once BASE_PATH . '/includes/footer.php';
?>
