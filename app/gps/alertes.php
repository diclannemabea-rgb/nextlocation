<?php
/**
 * FlotteCar — Alertes GPS
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
    $action   = $_POST['action'] ?? '';
    $alerteId = (int)($_POST['alerte_id'] ?? 0);

    if ($action === 'marquer_lu') {
        $db->prepare("UPDATE alertes_regles SET lu=1 WHERE id=? AND tenant_id=?")
           ->execute([$alerteId, $tenantId]);
    }
    if ($action === 'marquer_tout_lu') {
        $db->prepare("UPDATE alertes_regles SET lu=1 WHERE tenant_id=? AND lu=0")
           ->execute([$tenantId]);
        setFlash(FLASH_SUCCESS, 'Toutes les alertes marquées comme lues.');
    }
    if ($action === 'supprimer') {
        $db->prepare("DELETE FROM alertes_regles WHERE id=? AND tenant_id=?")->execute([$alerteId, $tenantId]);
    }
    if ($action === 'purger_lues') {
        $db->prepare("DELETE FROM alertes_regles WHERE tenant_id=? AND lu=1")->execute([$tenantId]);
        setFlash(FLASH_SUCCESS, 'Alertes lues supprimées.');
    }
    redirect(BASE_URL . 'app/gps/alertes.php' . ($_GET ? '?' . http_build_query($_GET) : ''));
}

// ── Filtres ───────────────────────────────────────────────────────────────────
$filtreLu      = $_GET['lu']      ?? 'non_lu';  // non_lu | lu | tous
$filtreType    = $_GET['type']    ?? '';
$filtreVeh     = (int)($_GET['vehicule'] ?? 0);
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 30;
$offset        = ($page - 1) * $perPage;

// ── Icônes et couleurs par type ───────────────────────────────────────────────
$typesMeta = [
    'horaire'          => ['icon'=>'fa-clock',           'color'=>'#0d9488', 'label'=>'Horaire'],
    'vitesse'          => ['icon'=>'fa-tachometer-alt',  'color'=>'#dc2626', 'label'=>'Vitesse'],
    'vidange'          => ['icon'=>'fa-oil-can',         'color'=>'#d97706', 'label'=>'Vidange'],
    'assurance_assurance'=>['icon'=>'fa-shield-alt',     'color'=>'#7c3aed', 'label'=>'Assurance'],
    'assurance_vignette' =>['icon'=>'fa-shield-alt',     'color'=>'#7c3aed', 'label'=>'Vignette'],
    'coupure_gps'      => ['icon'=>'fa-satellite-dish',  'color'=>'#ef4444', 'label'=>'Coupure GPS'],
    'immobilisation'   => ['icon'=>'fa-parking',         'color'=>'#0891b2', 'label'=>'Immobilisation'],
    'km_jour'          => ['icon'=>'fa-road',            'color'=>'#059669', 'label'=>'Km/jour'],
    'geofence'         => ['icon'=>'fa-map-marked-alt',  'color'=>'#0369a1', 'label'=>'Zone'],
    'ralenti'          => ['icon'=>'fa-gas-pump',        'color'=>'#ea580c', 'label'=>'Ralenti'],
    'trajets_jour'     => ['icon'=>'fa-route',           'color'=>'#9333ea', 'label'=>'Trajets/jour'],
];

// ── Requête ───────────────────────────────────────────────────────────────────
$where  = "WHERE a.tenant_id = $tenantId AND a.type_alerte != 'ralenti_debut'";
$params = [];

if ($filtreLu === 'non_lu')   { $where .= " AND a.lu = 0"; }
elseif ($filtreLu === 'lu')   { $where .= " AND a.lu = 1"; }

if ($filtreType)              { $where .= " AND a.type_alerte = ?"; $params[] = $filtreType; }
if ($filtreVeh)               { $where .= " AND a.vehicule_id = ?"; $params[] = $filtreVeh; }

$countStmt = $db->prepare("SELECT COUNT(*) FROM alertes_regles a $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$alertes = $db->prepare("
    SELECT a.*, v.nom AS veh_nom, v.immatriculation
    FROM alertes_regles a
    LEFT JOIN vehicules v ON v.id = a.vehicule_id
    $where
    ORDER BY a.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$alertes->execute($params);
$alertes = $alertes->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stats = $db->prepare("
    SELECT type_alerte, COUNT(*) as nb, SUM(lu=0) as non_lues
    FROM alertes_regles
    WHERE tenant_id=? AND type_alerte != 'ralenti_debut'
    AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY type_alerte
    ORDER BY nb DESC
");
$stats->execute([$tenantId]);
$statsTypes = $stats->fetchAll(PDO::FETCH_ASSOC);

$nbNonLues = (int)$db->prepare("SELECT COUNT(*) FROM alertes_regles WHERE tenant_id=? AND lu=0 AND type_alerte!='ralenti_debut'")->execute([$tenantId]) ?
    $db->query("SELECT COUNT(*) FROM alertes_regles WHERE tenant_id=$tenantId AND lu=0 AND type_alerte!='ralenti_debut'")->fetchColumn() : 0;

// Véhicules pour filtre
$vehicules = $db->prepare("SELECT DISTINCT v.id, v.nom, v.immatriculation FROM alertes_regles a JOIN vehicules v ON v.id=a.vehicule_id WHERE a.tenant_id=? ORDER BY v.nom");
$vehicules->execute([$tenantId]);
$vehicules = $vehicules->fetchAll(PDO::FETCH_ASSOC);

$pageTitle  = 'Alertes GPS';
$activePage = 'gps_alertes';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
.alerte-row { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; margin-bottom:8px; display:flex; align-items:flex-start; gap:12px; transition:opacity .2s; }
.alerte-row.is-lu { opacity:.6; background:#f8fafc; }
.alerte-icon { width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:.85rem; }
.alerte-body { flex:1; min-width:0; }
.alerte-msg { font-size:.84rem; color:#0f172a; line-height:1.4; }
.alerte-meta { font-size:.7rem; color:#94a3b8; margin-top:3px; display:flex; gap:10px; flex-wrap:wrap; }
.alerte-actions { display:flex; gap:4px; flex-shrink:0; }
.stat-pill { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border-radius:99px; font-size:.75rem; font-weight:700; cursor:pointer; text-decoration:none; border:2px solid transparent; }
.stat-pill:hover { opacity:.8; }
.stat-pill.active { border-color:currentColor; }

@media(max-width:768px) {
    .page-header { flex-direction:column; gap:10px; }
    .page-header > div:last-child { width:100%; }
    .page-header .btn { width:100%; justify-content:center; }
    .alerte-row { flex-wrap:wrap; padding:10px 12px; gap:8px; }
    .alerte-actions { width:100%; justify-content:flex-end; border-top:1px solid #f1f5f9; padding-top:8px; margin-top:2px; }
    .alerte-meta { font-size:.65rem; }
    .stat-pill { padding:4px 8px; font-size:.68rem; }
    .filter-form form { flex-direction:column; }
    .filter-form .form-control { width:100% !important; }
}
</style>

<div class="page-header" style="margin-bottom:14px">
    <div>
        <h1 class="page-title"><i class="fas fa-bell" style="color:<?= $nbNonLues > 0 ? '#dc2626' : '#0d9488' ?>"></i> Alertes GPS</h1>
        <p class="page-subtitle" style="margin:0">
            <?php if ($nbNonLues > 0): ?>
            <span style="color:#dc2626;font-weight:700"><?= $nbNonLues ?> alerte(s) non lue(s)</span>
            <?php else: ?>
            Aucune alerte non lue
            <?php endif ?>
            · <?= $total ?> résultat(s) avec les filtres actuels
        </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($nbNonLues > 0): ?>
        <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="marquer_tout_lu">
            <button type="submit" class="btn btn-secondary btn-sm">
                <i class="fas fa-check-double"></i> Tout marquer lu
            </button>
        </form>
        <?php endif ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer toutes les alertes lues ?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="purger_lues">
            <button type="submit" class="btn btn-ghost btn-sm" style="color:#ef4444">
                <i class="fas fa-trash"></i> Purger les lues
            </button>
        </form>
        <a href="<?= BASE_URL ?>app/gps/regles.php" class="btn btn-primary btn-sm">
            <i class="fas fa-sliders-h"></i> Gérer les règles
        </a>
    </div>
</div>

<?= renderFlashes() ?>

<!-- Stats par type (7 derniers jours) -->
<?php if (!empty($statsTypes)): ?>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px">
    <a href="?lu=<?= $filtreLu ?>"
       class="stat-pill <?= !$filtreType ? 'active' : '' ?>"
       style="background:#f1f5f9;color:#475569">
        <i class="fas fa-list" style="font-size:.65rem"></i> Tous
    </a>
    <?php foreach ($statsTypes as $s):
        $meta = $typesMeta[$s['type_alerte']] ?? ['icon'=>'fa-bell','color'=>'#94a3b8','label'=>$s['type_alerte']];
    ?>
    <a href="?lu=<?= $filtreLu ?>&type=<?= urlencode($s['type_alerte']) ?>"
       class="stat-pill <?= $filtreType === $s['type_alerte'] ? 'active' : '' ?>"
       style="background:<?= $meta['color'] ?>18;color:<?= $meta['color'] ?>">
        <i class="fas <?= $meta['icon'] ?>" style="font-size:.65rem"></i>
        <?= $meta['label'] ?>
        <strong><?= $s['nb'] ?></strong>
        <?php if ($s['non_lues'] > 0): ?>
        <span style="background:<?= $meta['color'] ?>;color:#fff;border-radius:99px;padding:1px 5px;font-size:.65rem"><?= $s['non_lues'] ?></span>
        <?php endif ?>
    </a>
    <?php endforeach ?>
</div>
<?php endif ?>

<!-- Filtres -->
<div class="filter-form" style="margin-bottom:14px">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <select name="lu" class="form-control" style="width:auto" onchange="this.form.submit()">
            <option value="non_lu" <?= $filtreLu==='non_lu'?'selected':'' ?>>Non lues</option>
            <option value="lu"     <?= $filtreLu==='lu'    ?'selected':'' ?>>Lues</option>
            <option value="tous"   <?= $filtreLu==='tous'  ?'selected':'' ?>>Toutes</option>
        </select>
        <?php if (!empty($vehicules)): ?>
        <select name="vehicule" class="form-control" style="width:auto" onchange="this.form.submit()">
            <option value="">Tous les véhicules</option>
            <?php foreach ($vehicules as $v): ?>
            <option value="<?= $v['id'] ?>" <?= $filtreVeh==$v['id']?'selected':'' ?>>
                <?= sanitize($v['nom']) ?> · <?= sanitize($v['immatriculation']) ?>
            </option>
            <?php endforeach ?>
        </select>
        <?php endif ?>
        <?php if ($filtreType): ?>
        <input type="hidden" name="type" value="<?= htmlspecialchars($filtreType) ?>">
        <?php endif ?>
    </form>
</div>

<!-- Liste alertes -->
<?php if (empty($alertes)): ?>
<div style="text-align:center;padding:3rem;color:#94a3b8">
    <i class="fas fa-bell-slash" style="font-size:2.5rem;display:block;margin-bottom:12px;opacity:.3"></i>
    <div style="font-size:.9rem">Aucune alerte <?= $filtreLu==='non_lu' ? 'non lue' : '' ?> pour le moment</div>
    <?php if ($filtreLu === 'non_lu'): ?>
    <div style="font-size:.78rem;margin-top:6px">Le moteur de règles vérifie automatiquement les règles configurées</div>
    <div style="margin-top:12px">
        <a href="<?= BASE_URL ?>api/check_regles.php?key=<?= CRON_KEY ?>" target="_blank" class="btn btn-primary btn-sm">
            <i class="fas fa-play"></i> Lancer la vérification maintenant
        </a>
    </div>
    <?php endif ?>
</div>
<?php else: ?>

<?php foreach ($alertes as $a):
    $meta = $typesMeta[$a['type_alerte']] ?? ['icon'=>'fa-bell','color'=>'#94a3b8','label'=>$a['type_alerte']];
    $ago  = time() - strtotime($a['created_at']);
    if ($ago < 3600)      $agoStr = (int)($ago/60)  . ' min';
    elseif ($ago < 86400) $agoStr = (int)($ago/3600) . 'h';
    else                  $agoStr = date('d/m à H:i', strtotime($a['created_at']));
?>
<div class="alerte-row <?= $a['lu'] ? 'is-lu' : '' ?>" id="alerte-<?= $a['id'] ?>">
    <?php if (!$a['lu']): ?>
    <div style="width:6px;height:6px;border-radius:50%;background:<?= $meta['color'] ?>;flex-shrink:0;margin-top:8px"></div>
    <?php endif ?>
    <div class="alerte-icon" style="background:<?= $meta['color'] ?>18">
        <i class="fas <?= $meta['icon'] ?>" style="color:<?= $meta['color'] ?>"></i>
    </div>
    <div class="alerte-body">
        <div class="alerte-msg"><?= sanitize($a['message']) ?></div>
        <div class="alerte-meta">
            <span><i class="fas fa-car" style="font-size:.6rem"></i> <?= sanitize($a['veh_nom'] ?? '?') ?> · <?= sanitize($a['immatriculation'] ?? '') ?></span>
            <span><i class="fas fa-clock" style="font-size:.6rem"></i> <?= $agoStr ?></span>
            <?php if ($a['valeur_declencheur']): ?>
            <span style="background:<?= $meta['color'] ?>18;color:<?= $meta['color'] ?>;padding:1px 7px;border-radius:4px;font-weight:700">
                <?= sanitize($a['valeur_declencheur']) ?>
            </span>
            <?php endif ?>
            <span class="type-badge" style="background:#f1f5f9;color:#64748b;padding:1px 6px;border-radius:4px">
                <?= $meta['label'] ?>
            </span>
        </div>
    </div>
    <div class="alerte-actions">
        <?php if (!$a['lu']): ?>
        <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="marquer_lu">
            <input type="hidden" name="alerte_id" value="<?= $a['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px;color:#059669" title="Marquer comme lu">
                <i class="fas fa-check"></i>
            </button>
        </form>
        <?php endif ?>
        <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="supprimer">
            <input type="hidden" name="alerte_id" value="<?= $a['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px;color:#ef4444" title="Supprimer" onclick="return confirm('Supprimer cette alerte ?')">
                <i class="fas fa-times"></i>
            </button>
        </form>
    </div>
</div>
<?php endforeach ?>

<?= renderPagination($total, $page, $perPage, BASE_URL . 'app/gps/alertes.php?' . http_build_query(array_filter(['lu'=>$filtreLu,'type'=>$filtreType,'vehicule'=>$filtreVeh?:null]))) ?>

<?php endif ?>

<!-- Bouton lancer vérification manuelle -->
<div style="margin-top:20px;text-align:center;padding:14px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0">
    <div style="font-size:.78rem;color:#94a3b8;margin-bottom:8px">
        <i class="fas fa-info-circle"></i>
        Le moteur de règles se déclenche automatiquement toutes les 10 minutes via CRON.
    </div>
    <a href="<?= BASE_URL ?>api/check_regles.php?key=<?= CRON_KEY ?>" target="_blank" class="btn btn-secondary btn-sm">
        <i class="fas fa-sync-alt"></i> Lancer la vérification maintenant
    </a>
    <a href="<?= BASE_URL ?>app/gps/regles.php" class="btn btn-primary btn-sm" style="margin-left:6px">
        <i class="fas fa-sliders-h"></i> Configurer les règles
    </a>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
