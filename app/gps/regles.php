<?php
/**
 * FlotteCar — Règles GPS globales & par véhicule
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

// ── Définition des 10 types de règles ────────────────────────────────────────
$typesRegles = [
    'horaire' => [
        'icon' => 'fa-clock', 'color' => '#0d9488',
        'label' => 'Plage horaire autorisée',
        'desc'  => 'Alerte si mouvement hors des heures autorisées',
        'champs' => [
            ['name'=>'heure_debut','label'=>'Heure début','type'=>'time','default'=>'05:00'],
            ['name'=>'heure_fin',  'label'=>'Heure fin',  'type'=>'time','default'=>'23:00'],
            ['name'=>'jours',      'label'=>'Jours actifs','type'=>'checkboxes',
             'options'=>['lun'=>'Lun','mar'=>'Mar','mer'=>'Mer','jeu'=>'Jeu','ven'=>'Ven','sam'=>'Sam','dim'=>'Dim'],
             'default'=>['lun','mar','mer','jeu','ven','sam']],
        ],
    ],
    'vitesse' => [
        'icon' => 'fa-tachometer-alt', 'color' => '#dc2626',
        'label' => 'Vitesse maximale',
        'desc'  => 'Alerte si dépassement de la vitesse limite',
        'champs' => [
            ['name'=>'vitesse_max','label'=>'Vitesse max (km/h)','type'=>'number','default'=>100,'min'=>30,'max'=>200],
        ],
    ],
    'vidange' => [
        'icon' => 'fa-oil-can', 'color' => '#d97706',
        'label' => 'Alerte vidange (kilométrage)',
        'desc'  => 'Notification maintenance tous les X km',
        'champs' => [
            ['name'=>'intervalle_km','label'=>'Tous les X km','type'=>'number','default'=>5000,'min'=>500,'max'=>50000],
        ],
    ],
    'assurance' => [
        'icon' => 'fa-shield-alt', 'color' => '#7c3aed',
        'label' => 'Alerte assurance / vignette',
        'desc'  => 'Notification avant expiration des documents',
        'champs' => [
            ['name'=>'jours_avant','label'=>'Alerter X jours avant expiration','type'=>'number','default'=>30,'min'=>7,'max'=>90],
        ],
    ],
    'coupure_gps' => [
        'icon' => 'fa-satellite-dish', 'color' => '#ef4444',
        'label' => 'Coupure alimentation GPS',
        'desc'  => 'Alerte immédiate si le boîtier GPS est débranché',
        'champs' => [
            ['name'=>'actif','label'=>'Activer la détection','type'=>'toggle','default'=>1],
        ],
    ],
    'immobilisation' => [
        'icon' => 'fa-parking', 'color' => '#0891b2',
        'label' => 'Immobilisation longue (heures de travail)',
        'desc'  => 'Alerte si le véhicule ne bouge pas pendant X heures en journée',
        'champs' => [
            ['name'=>'duree_min','label'=>'Durée sans mouvement (heures)','type'=>'number','default'=>3,'min'=>1,'max'=>12],
            ['name'=>'heure_debut','label'=>'Début journée de travail','type'=>'time','default'=>'06:00'],
            ['name'=>'heure_fin',  'label'=>'Fin journée de travail',  'type'=>'time','default'=>'22:00'],
        ],
    ],
    'km_jour' => [
        'icon' => 'fa-road', 'color' => '#059669',
        'label' => 'Kilométrage journalier maximum',
        'desc'  => 'Alerte si le véhicule dépasse X km dans la journée',
        'champs' => [
            ['name'=>'km_max_jour','label'=>'Km maximum par jour','type'=>'number','default'=>300,'min'=>50,'max'=>1000],
        ],
    ],
    'geofence' => [
        'icon' => 'fa-map-marked-alt', 'color' => '#0369a1',
        'label' => 'Zone géographique autorisée',
        'desc'  => 'Alerte si le véhicule sort de la zone définie',
        'champs' => [
            ['name'=>'zone_nom','label'=>'Nom de la zone','type'=>'text','default'=>'Abidjan'],
            ['name'=>'lat',     'label'=>'Latitude centre', 'type'=>'text','default'=>'5.3484'],
            ['name'=>'lng',     'label'=>'Longitude centre','type'=>'text','default'=>'-4.0120'],
            ['name'=>'rayon_km','label'=>'Rayon (km)',      'type'=>'number','default'=>50,'min'=>1,'max'=>500],
        ],
    ],
    'ralenti' => [
        'icon' => 'fa-gas-pump', 'color' => '#ea580c',
        'label' => 'Arrêt moteur prolongé (ralenti)',
        'desc'  => 'Alerte si moteur allumé mais véhicule immobile depuis X minutes',
        'champs' => [
            ['name'=>'duree_min','label'=>'Durée ralenti max (minutes)','type'=>'number','default'=>15,'min'=>5,'max'=>60],
        ],
    ],
    'trajets_jour' => [
        'icon' => 'fa-route', 'color' => '#9333ea',
        'label' => 'Nombre de trajets journaliers max',
        'desc'  => 'Alerte si le véhicule effectue trop de trajets dans la journée',
        'champs' => [
            ['name'=>'nb_max','label'=>'Nombre maximum de trajets/jour','type'=>'number','default'=>20,'min'=>1,'max'=>100],
        ],
    ],
];

// ── POST — Sauvegarder une règle ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action    = $_POST['action'] ?? '';
    $regleId   = (int)($_POST['regle_id'] ?? 0);
    $vehiculeId = ($_POST['vehicule_id'] ?? '') === '' ? null : (int)$_POST['vehicule_id'];

    if ($action === 'save_regle') {
        $type   = $_POST['type_regle'] ?? '';
        $actif  = (int)($_POST['actif_regle'] ?? 1);
        if (!isset($typesRegles[$type])) {
            setFlash(FLASH_ERROR, 'Type de règle invalide.');
            redirect(BASE_URL . 'app/gps/regles.php');
        }

        // Construire les params JSON depuis les champs du formulaire
        $params = [];
        foreach ($typesRegles[$type]['champs'] as $champ) {
            $key = $champ['name'];
            if ($champ['type'] === 'checkboxes') {
                $params[$key] = $_POST[$key] ?? [];
            } else {
                $params[$key] = $_POST[$key] ?? $champ['default'];
            }
        }

        $libelle = $typesRegles[$type]['label'];
        if ($vehiculeId) {
            // Vérif appartenance
            $chk = $db->prepare("SELECT id, nom FROM vehicules WHERE id=? AND tenant_id=?");
            $chk->execute([$vehiculeId, $tenantId]);
            $veh = $chk->fetch();
            if (!$veh) { setFlash(FLASH_ERROR, 'Véhicule introuvable.'); redirect(BASE_URL . 'app/gps/regles.php'); }
            $libelle .= ' — ' . $veh['nom'];
        }

        // Action automatique
        $actionAuto = $_POST['action_auto'] ?? 'notification_only';
        if (!in_array($actionAuto, ['notification_only', 'couper_moteur', 'couper_moteur_et_notifier'])) {
            $actionAuto = 'notification_only';
        }

        if ($regleId) {
            // Mise à jour
            $chkR = $db->prepare("SELECT id FROM regles_gps WHERE id=? AND tenant_id=?");
            $chkR->execute([$regleId, $tenantId]);
            if (!$chkR->fetch()) { setFlash(FLASH_ERROR, 'Règle introuvable.'); redirect(BASE_URL . 'app/gps/regles.php'); }
            $db->prepare("UPDATE regles_gps SET type_regle=?, libelle=?, params=?, actif=?, vehicule_id=?, action_auto=? WHERE id=? AND tenant_id=?")
               ->execute([$type, $libelle, json_encode($params), $actif, $vehiculeId, $actionAuto, $regleId, $tenantId]);
            setFlash(FLASH_SUCCESS, 'Règle mise à jour.');
        } else {
            // Création
            $db->prepare("INSERT INTO regles_gps (tenant_id, vehicule_id, type_regle, libelle, params, actif, action_auto) VALUES (?,?,?,?,?,?,?)")
               ->execute([$tenantId, $vehiculeId, $type, $libelle, json_encode($params), $actif, $actionAuto]);
            setFlash(FLASH_SUCCESS, 'Règle créée avec succès.');
        }
    }

    if ($action === 'toggle_regle') {
        $db->prepare("UPDATE regles_gps SET actif = 1-actif WHERE id=? AND tenant_id=?")
           ->execute([$regleId, $tenantId]);
        setFlash(FLASH_SUCCESS, 'Règle mise à jour.');
    }

    if ($action === 'delete_regle') {
        $db->prepare("DELETE FROM regles_gps WHERE id=? AND tenant_id=?")->execute([$regleId, $tenantId]);
        setFlash(FLASH_SUCCESS, 'Règle supprimée.');
    }

    redirect(BASE_URL . 'app/gps/regles.php');
}

// ── Chargement des règles ─────────────────────────────────────────────────────
$reglesGlobales = $db->prepare("SELECT * FROM regles_gps WHERE tenant_id=? AND vehicule_id IS NULL ORDER BY type_regle");
$reglesGlobales->execute([$tenantId]);
$reglesGlobales = $reglesGlobales->fetchAll(PDO::FETCH_ASSOC);

$reglesVehicule = $db->prepare("
    SELECT r.*, v.nom as veh_nom, v.immatriculation
    FROM regles_gps r
    JOIN vehicules v ON v.id = r.vehicule_id
    WHERE r.tenant_id=? AND r.vehicule_id IS NOT NULL
    ORDER BY v.nom, r.type_regle
");
$reglesVehicule->execute([$tenantId]);
$reglesVehicule = $reglesVehicule->fetchAll(PDO::FETCH_ASSOC);

// Véhicules pour le select
$vehStmt = $db->prepare("SELECT id, nom, immatriculation FROM vehicules WHERE tenant_id=? AND traccar_device_id IS NOT NULL ORDER BY nom");
$vehStmt->execute([$tenantId]);
$vehicules = $vehStmt->fetchAll(PDO::FETCH_ASSOC);

// Stats alertes non lues
$nbAlertes = (int)$db->prepare("SELECT COUNT(*) FROM alertes_regles WHERE tenant_id=? AND lu=0")->execute([$tenantId]) ?
    $db->query("SELECT COUNT(*) FROM alertes_regles WHERE tenant_id=$tenantId AND lu=0")->fetchColumn() : 0;

$pageTitle  = 'Règles GPS';
$activePage = 'gps_regles';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
.regle-card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px 16px; margin-bottom:10px; display:flex; align-items:center; gap:14px; transition:box-shadow .15s; }
.regle-card:hover { box-shadow:0 2px 12px rgba(0,0,0,.07); }
.regle-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:1rem; }
.regle-info { flex:1; min-width:0; }
.regle-title { font-weight:700; font-size:.85rem; color:#0f172a; }
.regle-params { font-size:.72rem; color:#64748b; margin-top:2px; }
.toggle-switch { position:relative; display:inline-block; width:36px; height:20px; flex-shrink:0; }
.toggle-switch input { opacity:0; width:0; height:0; }
.toggle-slider { position:absolute; cursor:pointer; inset:0; background:#cbd5e1; border-radius:20px; transition:.2s; }
.toggle-slider:before { content:''; position:absolute; width:14px; height:14px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.2s; }
input:checked + .toggle-slider { background:#22c55e; }
input:checked + .toggle-slider:before { transform:translateX(16px); }
.type-badge { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:99px; font-size:.7rem; font-weight:700; }
.section-title { font-size:.72rem; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; font-weight:700; margin:18px 0 8px; }
.form-group-inline { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }

@media(max-width:768px){
    .page-header{flex-direction:column;align-items:flex-start!important;gap:10px}
    .page-header>div:last-child{width:100%;display:flex;flex-direction:column;gap:6px}
    .page-header>div:last-child .btn{width:100%;text-align:center}
    .regle-card{flex-wrap:wrap;gap:10px;padding:12px}
    .regle-info{min-width:0;flex:1 1 100%;order:2}
    .type-badge{order:3;margin-right:auto}
    .form-group-inline{flex-direction:column;align-items:stretch}
    .modal{max-width:95vw!important;margin:10px}
}
</style>

<div class="page-header" style="margin-bottom:14px">
    <div>
        <h1 class="page-title"><i class="fas fa-sliders-h" style="color:#0d9488"></i> Règles GPS</h1>
        <p class="page-subtitle" style="margin:0">
            <?= count($reglesGlobales) ?> règle(s) globale(s) · <?= count($reglesVehicule) ?> règle(s) spécifiques
        </p>
    </div>
    <div style="display:flex;gap:8px">
        <?php if ($nbAlertes > 0): ?>
        <a href="<?= BASE_URL ?>app/gps/alertes.php" class="btn btn-danger btn-sm">
            <i class="fas fa-bell"></i> <?= $nbAlertes ?> alerte(s) non lue(s)
        </a>
        <?php endif ?>
        <button class="btn btn-primary btn-sm" onclick="openModal('modal-add-regle')">
            <i class="fas fa-plus"></i> Nouvelle règle
        </button>
    </div>
</div>

<?= renderFlashes() ?>

<!-- Explication -->
<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:.82rem;color:#0369a1">
    <i class="fas fa-info-circle"></i>
    <strong>Règles globales</strong> s'appliquent à <strong>tous les véhicules</strong> du parc.
    Les <strong>règles par véhicule</strong> s'appliquent uniquement au véhicule concerné et <strong>remplacent</strong> la règle globale du même type.
    Les alertes déclenchées sont visibles dans <a href="<?= BASE_URL ?>app/gps/alertes.php" style="color:#0d9488">GPS → Alertes</a>.
</div>

<?php if (empty($reglesGlobales) && empty($reglesVehicule)): ?>
<div style="text-align:center;padding:3rem;color:#94a3b8">
    <i class="fas fa-sliders-h" style="font-size:2.5rem;display:block;margin-bottom:12px;opacity:.3"></i>
    <div style="font-size:1rem;font-weight:600;margin-bottom:6px">Aucune règle configurée</div>
    <div style="font-size:.82rem;margin-bottom:16px">Créez votre première règle pour commencer la surveillance automatique</div>
    <button class="btn btn-primary" onclick="openModal('modal-add-regle')">
        <i class="fas fa-plus"></i> Créer une règle
    </button>
</div>
<?php else: ?>

<!-- Règles globales -->
<?php if (!empty($reglesGlobales)): ?>
<div class="section-title"><i class="fas fa-globe"></i> Règles globales (tous les véhicules)</div>
<?php foreach ($reglesGlobales as $r):
    $def = $typesRegles[$r['type_regle']] ?? null;
    $params = json_decode($r['params'], true) ?: [];
    $paramStr = implode(' · ', array_map(fn($k,$v) => is_array($v) ? implode(',',$v) : "$k: $v", array_keys($params), $params));
?>
<div class="regle-card" style="border-left:3px solid <?= $def ? $def['color'] : '#94a3b8' ?>">
    <div class="regle-icon" style="background:<?= $def ? $def['color'] : '#94a3b8' ?>22">
        <i class="fas <?= $def ? $def['icon'] : 'fa-cog' ?>" style="color:<?= $def ? $def['color'] : '#94a3b8' ?>"></i>
    </div>
    <div class="regle-info">
        <div class="regle-title"><?= sanitize($r['libelle']) ?></div>
        <div class="regle-params"><?= sanitize($paramStr) ?></div>
    </div>
    <span class="type-badge" style="background:<?= $def ? $def['color'] : '#94a3b8' ?>22;color:<?= $def ? $def['color'] : '#64748b' ?>">
        <i class="fas fa-globe" style="font-size:.6rem"></i> Global
    </span>
    <?php if (($r['action_auto'] ?? 'notification_only') !== 'notification_only'): ?>
    <span class="type-badge" style="background:#fee2e2;color:#dc2626">
        <i class="fas fa-power-off" style="font-size:.6rem"></i>
        <?= ($r['action_auto'] === 'couper_moteur') ? 'Auto-coupure' : 'Coupure + notif' ?>
    </span>
    <?php endif ?>
    <!-- Toggle actif/inactif -->
    <form method="POST" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="toggle_regle">
        <input type="hidden" name="regle_id" value="<?= $r['id'] ?>">
        <label class="toggle-switch" title="<?= $r['actif'] ? 'Désactiver' : 'Activer' ?>">
            <input type="checkbox" <?= $r['actif'] ? 'checked' : '' ?> onchange="this.form.submit()">
            <span class="toggle-slider"></span>
        </label>
    </form>
    <button class="btn btn-ghost btn-sm" style="padding:4px 8px;color:#0d9488"
            onclick="editRegle(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)"
            title="Modifier">
        <i class="fas fa-pen"></i>
    </button>
    <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer cette règle ?')">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_regle">
        <input type="hidden" name="regle_id" value="<?= $r['id'] ?>">
        <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px;color:#ef4444" title="Supprimer">
            <i class="fas fa-trash"></i>
        </button>
    </form>
</div>
<?php endforeach ?>
<?php endif ?>

<!-- Règles par véhicule -->
<?php if (!empty($reglesVehicule)): ?>
<div class="section-title" style="margin-top:20px"><i class="fas fa-car"></i> Règles spécifiques par véhicule</div>
<?php foreach ($reglesVehicule as $r):
    $def = $typesRegles[$r['type_regle']] ?? null;
    $params = json_decode($r['params'], true) ?: [];
    $paramStr = implode(' · ', array_map(fn($k,$v) => is_array($v) ? implode(',',$v) : "$k: $v", array_keys($params), $params));
?>
<div class="regle-card" style="border-left:3px solid <?= $def ? $def['color'] : '#94a3b8' ?>">
    <div class="regle-icon" style="background:<?= $def ? $def['color'] : '#94a3b8' ?>22">
        <i class="fas <?= $def ? $def['icon'] : 'fa-cog' ?>" style="color:<?= $def ? $def['color'] : '#94a3b8' ?>"></i>
    </div>
    <div class="regle-info">
        <div class="regle-title"><?= sanitize($r['libelle']) ?></div>
        <div class="regle-params"><?= sanitize($paramStr) ?></div>
    </div>
    <span class="type-badge" style="background:#f1f5f9;color:#475569">
        <i class="fas fa-car" style="font-size:.6rem"></i>
        <?= sanitize($r['veh_nom']) ?> · <?= sanitize($r['immatriculation']) ?>
    </span>
    <?php if (($r['action_auto'] ?? 'notification_only') !== 'notification_only'): ?>
    <span class="type-badge" style="background:#fee2e2;color:#dc2626">
        <i class="fas fa-power-off" style="font-size:.6rem"></i>
        <?= ($r['action_auto'] === 'couper_moteur') ? 'Auto-coupure' : 'Coupure + notif' ?>
    </span>
    <?php endif ?>
    <form method="POST" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="toggle_regle">
        <input type="hidden" name="regle_id" value="<?= $r['id'] ?>">
        <label class="toggle-switch">
            <input type="checkbox" <?= $r['actif'] ? 'checked' : '' ?> onchange="this.form.submit()">
            <span class="toggle-slider"></span>
        </label>
    </form>
    <button class="btn btn-ghost btn-sm" style="padding:4px 8px;color:#0d9488"
            onclick="editRegle(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)">
        <i class="fas fa-pen"></i>
    </button>
    <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer cette règle ?')">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_regle">
        <input type="hidden" name="regle_id" value="<?= $r['id'] ?>">
        <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px;color:#ef4444">
            <i class="fas fa-trash"></i>
        </button>
    </form>
</div>
<?php endforeach ?>
<?php endif ?>

<?php endif ?>

<!-- ══ MODAL CRÉER / MODIFIER RÈGLE ══════════════════════════════════════════ -->
<div id="modal-add-regle" class="modal-overlay">
    <div class="modal" style="max-width:520px;max-height:90vh;overflow-y:auto">
        <div class="modal-header">
            <h3 id="modal-regle-title">
                <i class="fas fa-sliders-h" style="color:#0d9488;margin-right:6px"></i>
                Nouvelle règle GPS
            </h3>
            <button class="modal-close" onclick="closeModal('modal-add-regle')">&times;</button>
        </div>
        <form method="POST" style="padding:20px" id="form-regle">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_regle">
            <input type="hidden" name="regle_id" id="regle-id" value="0">

            <!-- Type de règle -->
            <div class="form-group">
                <label class="form-label">Type de règle <span style="color:#ef4444">*</span></label>
                <select name="type_regle" id="select-type-regle" class="form-control" onchange="updateFormChamps(this.value)" required>
                    <option value="">-- Choisir un type --</option>
                    <?php foreach ($typesRegles as $key => $def): ?>
                    <option value="<?= $key ?>">
                        <?= $def['label'] ?>
                    </option>
                    <?php endforeach ?>
                </select>
                <div id="type-desc" class="form-hint" style="margin-top:5px"></div>
            </div>

            <!-- Portée : globale ou véhicule spécifique -->
            <div class="form-group">
                <label class="form-label">Appliquer à</label>
                <select name="vehicule_id" id="select-vehicule" class="form-control">
                    <option value="">🌐 Tous les véhicules (global)</option>
                    <?php foreach ($vehicules as $v): ?>
                    <option value="<?= $v['id'] ?>"><?= sanitize($v['nom']) ?> · <?= sanitize($v['immatriculation']) ?></option>
                    <?php endforeach ?>
                </select>
                <?php if (empty($vehicules)): ?>
                <div class="form-hint" style="color:#d97706"><i class="fas fa-exclamation-triangle"></i> Aucun véhicule GPS configuré — les règles par véhicule ne sont pas disponibles.</div>
                <?php endif ?>
            </div>

            <!-- Champs dynamiques selon le type -->
            <div id="champs-dynamiques"></div>

            <!-- Action automatique -->
            <div class="form-group">
                <label class="form-label" style="font-size:.85rem">
                    <i class="fas fa-bolt" style="color:#d97706"></i> Action automatique
                </label>
                <select name="action_auto" id="select-action-auto" class="form-control">
                    <option value="notification_only">Notification uniquement (alerte dans le tableau)</option>
                    <option value="couper_moteur">Couper le moteur automatiquement</option>
                    <option value="couper_moteur_et_notifier">Couper le moteur + notification</option>
                </select>
                <div class="form-hint" id="action-auto-hint" style="margin-top:5px;display:none">
                    <i class="fas fa-exclamation-triangle" style="color:#dc2626"></i>
                    <strong style="color:#dc2626">Attention :</strong> le moteur sera coupé automatiquement dès que la règle est violée. Assurez-vous que le boîtier GPS supporte cette commande.
                </div>
            </div>

            <!-- Actif -->
            <div class="form-group" style="display:flex;align-items:center;gap:10px">
                <label class="toggle-switch">
                    <input type="checkbox" name="actif_regle" value="1" id="toggle-actif" checked>
                    <span class="toggle-slider"></span>
                </label>
                <span style="font-size:.85rem;font-weight:600">Règle active</span>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-add-regle')">Annuler</button>
                <button type="submit" class="btn btn-primary" id="btn-save-regle">
                    <i class="fas fa-check"></i> Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Définition des champs par type (côté JS pour formulaire dynamique)
const typesRegles = <?= json_encode($typesRegles) ?>;

function updateFormChamps(type) {
    const container = document.getElementById('champs-dynamiques');
    const descEl    = document.getElementById('type-desc');
    container.innerHTML = '';
    if (!type || !typesRegles[type]) { descEl.textContent = ''; return; }

    const def = typesRegles[type];
    descEl.innerHTML = '<i class="fas fa-info-circle" style="color:#14b8a6"></i> ' + def.desc;

    def.champs.forEach(champ => {
        const div = document.createElement('div');
        div.className = 'form-group';
        let html = `<label class="form-label" style="font-size:.85rem">${champ.label}</label>`;

        if (champ.type === 'number') {
            html += `<input type="number" name="${champ.name}" class="form-control" value="${champ.default}"
                     min="${champ.min||0}" max="${champ.max||9999}" required>`;
        } else if (champ.type === 'time') {
            html += `<input type="time" name="${champ.name}" class="form-control" value="${champ.default}" required>`;
        } else if (champ.type === 'text') {
            html += `<input type="text" name="${champ.name}" class="form-control" value="${champ.default}">`;
        } else if (champ.type === 'toggle') {
            html += `<label class="toggle-switch"><input type="checkbox" name="${champ.name}" value="1" checked><span class="toggle-slider"></span></label>`;
        } else if (champ.type === 'checkboxes') {
            html += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px">';
            Object.entries(champ.options).forEach(([val, lbl]) => {
                const checked = (champ.default||[]).includes(val) ? 'checked' : '';
                html += `<label style="display:flex;align-items:center;gap:4px;font-size:.82rem;cursor:pointer">
                    <input type="checkbox" name="${champ.name}[]" value="${val}" ${checked}> ${lbl}
                </label>`;
            });
            html += '</div>';
        }
        div.innerHTML = html;
        container.appendChild(div);
    });
}

function editRegle(r) {
    document.getElementById('regle-id').value = r.id;
    document.getElementById('modal-regle-title').innerHTML =
        '<i class="fas fa-pen" style="color:#0d9488;margin-right:6px"></i> Modifier la règle';
    document.getElementById('select-type-regle').value = r.type_regle;
    updateFormChamps(r.type_regle);

    // Remplir les valeurs
    const params = r.params ? (typeof r.params === 'string' ? JSON.parse(r.params) : r.params) : {};
    setTimeout(() => {
        Object.entries(params).forEach(([key, val]) => {
            if (Array.isArray(val)) {
                document.querySelectorAll(`[name="${key}[]"]`).forEach(cb => {
                    cb.checked = val.includes(cb.value);
                });
            } else {
                const el = document.querySelector(`[name="${key}"]`);
                if (el) el.value = val;
            }
        });
        if (r.vehicule_id) document.getElementById('select-vehicule').value = r.vehicule_id;
        document.getElementById('toggle-actif').checked = r.actif == 1;
        document.getElementById('select-action-auto').value = r.action_auto || 'notification_only';
        updateActionHint();
    }, 50);

    openModal('modal-add-regle');
}

// Afficher l'avertissement quand coupure moteur sélectionnée
document.getElementById('select-action-auto').addEventListener('change', updateActionHint);
function updateActionHint() {
    const sel = document.getElementById('select-action-auto');
    const hint = document.getElementById('action-auto-hint');
    hint.style.display = (sel.value !== 'notification_only') ? 'block' : 'none';
}

// Reset modal à la fermeture
document.getElementById('modal-add-regle').addEventListener('click', function(e) {
    if (e.target === this) {
        document.getElementById('regle-id').value = '0';
        document.getElementById('modal-regle-title').innerHTML =
            '<i class="fas fa-sliders-h" style="color:#0d9488;margin-right:6px"></i> Nouvelle règle GPS';
        document.getElementById('select-type-regle').value = '';
        document.getElementById('champs-dynamiques').innerHTML = '';
        document.getElementById('select-vehicule').value = '';
    }
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
