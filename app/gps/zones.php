<?php
/**
 * FlotteCar — Geofencing : gestion des zones géographiques
 * CRUD zones circulaires + carte Leaflet
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

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'ajouter') {
        $nom   = trim($_POST['nom'] ?? '');
        $lat   = (float)($_POST['latitude'] ?? 0);
        $lng   = (float)($_POST['longitude'] ?? 0);
        $rayon = max(50, (int)($_POST['rayon'] ?? 500));
        $couleur = $_POST['couleur'] ?? '#0d9488';
        if ($nom && $lat && $lng) {
            $db->prepare("INSERT INTO zones (tenant_id, nom, latitude_centre, longitude_centre, rayon_metres, couleur, actif) VALUES (?,?,?,?,?,?,1)")
               ->execute([$tenantId, $nom, $lat, $lng, $rayon, $couleur]);
            setFlash(FLASH_SUCCESS, "Zone « $nom » créée.");
            logActivite($db, 'creer_zone', 'gps', "Zone: $nom (rayon: {$rayon}m)");
        } else {
            setFlash(FLASH_ERROR, 'Nom et coordonnées requis.');
        }
    }

    if ($action === 'modifier') {
        $zid   = (int)($_POST['zone_id'] ?? 0);
        $nom   = trim($_POST['nom'] ?? '');
        $lat   = (float)($_POST['latitude'] ?? 0);
        $lng   = (float)($_POST['longitude'] ?? 0);
        $rayon = max(50, (int)($_POST['rayon'] ?? 500));
        $couleur = $_POST['couleur'] ?? '#0d9488';
        if ($zid && $nom) {
            $db->prepare("UPDATE zones SET nom=?, latitude_centre=?, longitude_centre=?, rayon_metres=?, couleur=? WHERE id=? AND tenant_id=?")
               ->execute([$nom, $lat, $lng, $rayon, $couleur, $zid, $tenantId]);
            setFlash(FLASH_SUCCESS, "Zone mise à jour.");
        }
    }

    if ($action === 'toggle') {
        $zid = (int)($_POST['zone_id'] ?? 0);
        $db->prepare("UPDATE zones SET actif = NOT actif WHERE id=? AND tenant_id=?")->execute([$zid, $tenantId]);
        setFlash(FLASH_SUCCESS, 'Statut mis à jour.');
    }

    if ($action === 'supprimer') {
        $zid = (int)($_POST['zone_id'] ?? 0);
        $db->prepare("DELETE FROM zones WHERE id=? AND tenant_id=?")->execute([$zid, $tenantId]);
        setFlash(FLASH_SUCCESS, 'Zone supprimée.');
    }

    redirect(BASE_URL . 'app/gps/zones.php');
}

// ── Charger zones ────────────────────────────────────────────────────────────
$zones = $db->prepare("SELECT * FROM zones WHERE tenant_id=? ORDER BY nom ASC");
$zones->execute([$tenantId]);
$zones = $zones->fetchAll(PDO::FETCH_ASSOC);

$pageTitle  = 'Geofencing — Zones';
$activePage = 'gps_zones';
require_once BASE_PATH . '/includes/header.php';
?>

<style>
.zone-layout { display:grid; grid-template-columns:1fr 380px; gap:14px; min-height:500px; }
@media(max-width:900px) { .zone-layout { grid-template-columns:1fr; } }

#zone-map { width:100%; height:100%; min-height:450px; border-radius:12px; border:1px solid #e2e8f0; }

.zone-panel { display:flex; flex-direction:column; gap:10px; }
.zone-card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; display:flex; align-items:center; gap:10px; transition:all .15s; cursor:pointer; }
.zone-card:hover { border-color:#0d9488; box-shadow:0 2px 8px rgba(13,148,136,.12); }
.zone-dot { width:14px; height:14px; border-radius:50%; flex-shrink:0; }
.zone-info { flex:1; min-width:0; }
.zone-name { font-weight:700; font-size:.84rem; color:#0f172a; }
.zone-meta { font-size:.7rem; color:#94a3b8; margin-top:2px; }
.zone-actions { display:flex; gap:4px; }
.zone-inactive { opacity:.5; }

.color-dots { display:flex; gap:6px; margin-top:6px; }
.color-dot { width:24px; height:24px; border-radius:50%; border:2px solid transparent; cursor:pointer; transition:border-color .15s; }
.color-dot.selected, .color-dot:hover { border-color:#0f172a; }

@media(max-width:768px) {
    .page-header { flex-direction:column; gap:10px; }
    #zone-map { min-height:300px; }
}
</style>

<div class="page-header" style="margin-bottom:14px">
    <div>
        <h1 class="page-title">Geofencing</h1>
        <p class="page-subtitle"><?= count($zones) ?> zone(s) configurée(s)</p>
    </div>
    <button class="btn btn-primary btn-sm" onclick="openModal('modal-add-zone')">
        + Nouvelle zone
    </button>
</div>

<?= renderFlashes() ?>

<div class="zone-layout">
    <!-- Carte -->
    <div>
        <div id="zone-map"></div>
    </div>

    <!-- Liste zones -->
    <div class="zone-panel">
        <?php if (empty($zones)): ?>
        <div style="text-align:center;padding:40px;color:#94a3b8;font-size:.82rem">
            Aucune zone. Cliquez sur « Nouvelle zone » ou cliquez sur la carte pour en créer une.
        </div>
        <?php endif; ?>
        <?php foreach ($zones as $z): ?>
        <div class="zone-card <?= $z['actif'] ? '' : 'zone-inactive' ?>" onclick="flyToZone(<?= $z['latitude_centre'] ?>,<?= $z['longitude_centre'] ?>,<?= $z['rayon_metres'] ?>)">
            <div class="zone-dot" style="background:<?= htmlspecialchars($z['couleur']) ?>"></div>
            <div class="zone-info">
                <div class="zone-name"><?= sanitize($z['nom']) ?></div>
                <div class="zone-meta">Rayon: <?= number_format($z['rayon_metres']) ?>m · <?= $z['actif'] ? 'Actif' : 'Inactif' ?></div>
            </div>
            <div class="zone-actions" onclick="event.stopPropagation()">
                <form method="POST" style="display:inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="zone_id" value="<?= $z['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px" title="<?= $z['actif'] ? 'Désactiver' : 'Activer' ?>">
                        <i class="fas fa-<?= $z['actif'] ? 'pause' : 'play' ?>" style="color:<?= $z['actif'] ? '#f59e0b' : '#16a34a' ?>"></i>
                    </button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer cette zone ?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="supprimer">
                    <input type="hidden" name="zone_id" value="<?= $z['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px">
                        <i class="fas fa-trash" style="color:#ef4444"></i>
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal Ajouter Zone -->
<div id="modal-add-zone" class="modal-overlay" style="display:none">
    <div class="modal-card" style="max-width:440px">
        <div class="modal-header">
            <h3>Nouvelle zone</h3>
            <button class="modal-close" onclick="closeModal('modal-add-zone')">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="ajouter">
            <div class="form-group">
                <label class="form-label">Nom de la zone</label>
                <input type="text" name="nom" class="form-control" placeholder="Ex: Garage, Domicile, Zone interdite" required>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div class="form-group">
                    <label class="form-label">Latitude</label>
                    <input type="number" name="latitude" id="add-lat" class="form-control" step="any" placeholder="5.3364" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Longitude</label>
                    <input type="number" name="longitude" id="add-lng" class="form-control" step="any" placeholder="-4.0267" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Rayon (mètres)</label>
                <input type="number" name="rayon" class="form-control" value="500" min="50" max="50000">
            </div>
            <div class="form-group">
                <label class="form-label">Couleur</label>
                <input type="hidden" name="couleur" id="add-color" value="#0d9488">
                <div class="color-dots">
                    <?php foreach (['#0d9488','#dc2626','#f59e0b','#7c3aed','#2563eb','#16a34a','#ea580c','#0891b2'] as $c): ?>
                    <div class="color-dot <?= $c==='#0d9488'?'selected':'' ?>" style="background:<?= $c ?>" onclick="document.getElementById('add-color').value='<?= $c ?>';document.querySelectorAll('#modal-add-zone .color-dot').forEach(d=>d.classList.remove('selected'));this.classList.add('selected')"></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <p style="font-size:.75rem;color:#64748b;margin-bottom:14px">Vous pouvez aussi cliquer sur la carte pour définir les coordonnées.</p>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-add-zone')">Annuler</button>
                <button type="submit" class="btn btn-primary">Créer la zone</button>
            </div>
        </form>
    </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const map = L.map('zone-map').setView([5.3364, -4.0267], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap', maxZoom: 19
}).addTo(map);

// Dessiner les zones existantes
const zones = <?= json_encode(array_map(fn($z) => [
    'id' => $z['id'], 'nom' => $z['nom'],
    'lat' => (float)$z['latitude_centre'], 'lng' => (float)$z['longitude_centre'],
    'rayon' => (int)$z['rayon_metres'], 'couleur' => $z['couleur'], 'actif' => (bool)$z['actif']
], $zones)) ?>;

const circles = [];
zones.forEach(z => {
    const circle = L.circle([z.lat, z.lng], {
        radius: z.rayon, color: z.couleur, fillColor: z.couleur,
        fillOpacity: z.actif ? 0.15 : 0.05, weight: 2,
        dashArray: z.actif ? null : '5,5'
    }).addTo(map);
    circle.bindPopup(`<strong>${z.nom}</strong><br>Rayon: ${z.rayon}m<br>${z.actif ? '🟢 Actif' : '⏸ Inactif'}`);
    circles.push(circle);
});

// Fit bounds
if (circles.length > 0) {
    const group = L.featureGroup(circles);
    map.fitBounds(group.getBounds().pad(0.1));
}

// Clic sur carte → remplir coordonnées
map.on('click', function(e) {
    document.getElementById('add-lat').value = e.latlng.lat.toFixed(6);
    document.getElementById('add-lng').value = e.latlng.lng.toFixed(6);
    openModal('modal-add-zone');
});

function flyToZone(lat, lng, rayon) {
    map.flyTo([lat, lng], rayon < 300 ? 16 : (rayon < 1000 ? 14 : 12));
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
