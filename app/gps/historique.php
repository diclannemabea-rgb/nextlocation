<?php
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireTenantAuth();
if (!hasGpsModule()) { setFlash(FLASH_WARNING, 'Module GPS non disponible.'); redirect(BASE_URL . 'app/dashboard.php'); }

$database = new Database();
$db = $database->getConnection();
$tenantId = getTenantId();

$stmt = $db->prepare("SELECT id, nom, immatriculation FROM vehicules WHERE tenant_id = ? AND traccar_device_id IS NOT NULL ORDER BY nom ASC");
$stmt->execute([$tenantId]);
$vehicules = $stmt->fetchAll();

$selectedId = (int)($_GET['vehicule_id'] ?? 0);
$from = $_GET['from'] ?? date('Y-m-d');
$to   = $_GET['to']   ?? date('Y-m-d');

$pageTitle = 'Historique GPS';
$activePage = 'gps_historique';
$extraCss = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>';
require_once BASE_PATH . '/includes/header.php';
?>
<div class="page-header">
    <h1><i class="fas fa-route"></i> Historique des trajets</h1>
</div>
<style>
@media(max-width:768px){
    .page-header{flex-direction:column;align-items:flex-start!important;gap:10px}
    .filter-form{flex-direction:column!important;align-items:stretch!important}
    .filter-form .form-group{flex:none!important;width:100%}
    .filter-form .btn{width:100%}
    .hist-layout{flex-direction:column!important}
    .hist-layout>div{width:100%!important}
    #map{height:300px!important}
}
</style>
<?= renderFlashes() ?>

<div class="card" style="margin-bottom:20px">
    <div class="card-body">
    <form method="GET" class="filter-form" style="align-items:flex-end;gap:16px">
        <div class="form-group" style="margin:0;flex:1">
            <label class="form-label">Véhicule</label>
            <select name="vehicule_id" class="form-control" required>
                <option value="">-- Sélectionner un véhicule --</option>
                <?php foreach($vehicules as $v): ?>
                <option value="<?= $v['id'] ?>" <?= $selectedId==$v['id']?'selected':'' ?>><?= sanitize($v['nom']) ?> - <?= sanitize($v['immatriculation']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label class="form-label">Date début</label>
            <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
        </div>
        <div class="form-group" style="margin:0">
            <label class="form-label">Date fin</label>
            <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Afficher</button>
    </form>
    </div>
</div>

<?php if (empty($vehicules)): ?>
<div class="card"><div class="card-body" style="text-align:center;padding:40px">
    <p class="text-muted">Aucun véhicule GPS configuré.</p>
    <a href="<?= BASE_URL ?>app/vehicules/liste.php" class="btn btn-primary">Gérer les véhicules</a>
</div></div>
<?php else: ?>
<div class="hist-layout" style="display:flex;gap:16px">
    <div style="flex:1">
        <div id="map" style="height:450px;border-radius:var(--radius)"></div>
    </div>
    <div style="width:320px">
        <div class="card" style="height:450px;overflow-y:auto">
            <div class="card-header"><h3><i class="fas fa-list"></i> Trajets</h3></div>
            <div id="trips-list" style="padding:0">
                <?php if (!$selectedId): ?>
                <div style="padding:20px;text-align:center;color:var(--text-muted)">Sélectionnez un véhicule et une période.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const map = L.map('map').setView([5.3600, -4.0083], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);

let polyline = null;
let startMarker = null, endMarker = null;

<?php if ($selectedId): ?>
fetch('<?= BASE_URL ?>api/gps.php?action=trips&vehicule_id=<?= $selectedId ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>')
    .then(r => r.json())
    .then(trips => {
        const listEl = document.getElementById('trips-list');
        if (!trips || !trips.length) {
            listEl.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-muted)">Aucun trajet sur cette période.</div>';
            return;
        }
        listEl.innerHTML = '';
        trips.forEach((t, i) => {
            const div = document.createElement('div');
            div.style = 'padding:12px 16px;border-bottom:1px solid var(--border);cursor:pointer';
            div.innerHTML = `<strong>Trajet #${i+1}</strong><br>
                <small>Départ: ${t.startTime ? new Date(t.startTime).toLocaleString('fr-FR') : '--'}</small><br>
                <small>Arrivée: ${t.endTime ? new Date(t.endTime).toLocaleString('fr-FR') : '--'}</small><br>
                <small>Distance: ${t.distance ? (t.distance/1000).toFixed(1) + ' km' : '--'} | Max: ${t.maxSpeed ? Math.round(t.maxSpeed) + ' km/h' : '--'}</small>`;
            listEl.appendChild(div);
        });
    })
    .catch(() => {
        document.getElementById('trips-list').innerHTML = '<div style="padding:20px;color:#ef4444">Erreur de chargement (Traccar non disponible).</div>';
    });
<?php endif; ?>
</script>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
