<?php
/**
 * FlotteCar - Journaux d'activité (Super Admin)
 */

define('BASE_PATH', dirname(__DIR__));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';

requireSuperAdmin();

$database = new Database();
$db = $database->getConnection();

// -------------------------------------------------------
// FILTRES
// -------------------------------------------------------
$tenantFilter = (int)($_GET['tenant_id'] ?? 0);
$actionFilter = trim($_GET['action']    ?? '');
$dateFilter   = trim($_GET['date']      ?? '');

// -------------------------------------------------------
// REQUÊTE LOGS
// -------------------------------------------------------
$where  = ['1=1'];
$params = [];

if ($tenantFilter > 0) {
    $where[]  = "l.tenant_id = ?";
    $params[] = $tenantFilter;
}
if ($actionFilter !== '') {
    $where[]  = "l.action LIKE ?";
    $params[] = '%' . $actionFilter . '%';
}
if ($dateFilter !== '') {
    $where[]  = "DATE(l.created_at) = ?";
    $params[] = $dateFilter;
}

$whereSQL = implode(' AND ', $where);

$stmtLogs = $db->prepare("
    SELECT l.*, u.nom as user_nom, u.email as user_email, t.nom_entreprise
    FROM logs_activites l
    LEFT JOIN users u ON u.id = l.user_id
    LEFT JOIN tenants t ON t.id = l.tenant_id
    WHERE $whereSQL
    ORDER BY l.created_at DESC
    LIMIT 100
");
$stmtLogs->execute($params);
$logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

// Liste tenants pour filtre
$tenants = $db->query("SELECT id, nom_entreprise FROM tenants ORDER BY nom_entreprise ASC")->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------
// MISE EN PAGE
// -------------------------------------------------------
$activePage = 'admin_logs';
$pageTitle  = "Journaux d'activité";
require_once BASE_PATH . '/includes/header.php';
?>

<style>
@media(max-width:768px) {
    .page-header { flex-direction:column; gap:10px; }
    .filter-form div[style*="display:flex"] { flex-direction:column !important; }
    .filter-form div > div { min-width:0 !important; width:100% !important; }
    .table { min-width:0 !important; }
    .table thead { display:none; }
    .table tbody tr { display:block; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:10px; padding:12px; background:#fff; }
    .table tbody td { display:flex; justify-content:space-between; align-items:center; padding:4px 0; border-bottom:none; font-size:.78rem !important; }
}
</style>
<div class="main-content">
    <div class="container">

        <?= renderFlashes() ?>

        <div class="page-header">
            <div>
                <h1 class="page-title">Journaux d'activité</h1>
                <p class="page-subtitle">100 derniers enregistrements</p>
            </div>
        </div>

        <!-- Filtres -->
        <div class="card" style="margin-bottom:20px">
            <div class="card-body">
                <form method="GET" class="filter-form">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
                        <div style="min-width:200px">
                            <label class="form-label">Tenant</label>
                            <select name="tenant_id" class="form-control">
                                <option value="">Tous les tenants</option>
                                <?php foreach ($tenants as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= $tenantFilter === (int)$t['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($t['nom_entreprise']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex:1;min-width:180px">
                            <label class="form-label">Action</label>
                            <input type="text" name="action" class="form-control"
                                   placeholder="Ex: connexion, création…"
                                   value="<?= sanitize($actionFilter) ?>">
                        </div>
                        <div style="min-width:160px">
                            <label class="form-label">Date</label>
                            <input type="date" name="date" class="form-control"
                                   value="<?= sanitize($dateFilter) ?>">
                        </div>
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Filtrer
                            </button>
                            <a href="<?= BASE_URL ?>admin/logs.php" class="btn btn-ghost">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tableau des logs -->
        <div class="card">
            <div class="card-body" style="padding:0">
                <?php if (empty($logs)): ?>
                    <div style="text-align:center;padding:48px;color:#9ca3af">
                        <i class="fas fa-clipboard-list fa-3x" style="margin-bottom:12px;display:block"></i>
                        <p>Aucun journal trouvé pour ces critères</p>
                        <?php if ($actionFilter || $dateFilter || $tenantFilter): ?>
                        <a href="<?= BASE_URL ?>admin/logs.php" class="btn btn-ghost btn-sm" style="margin-top:8px">
                            <i class="fas fa-times"></i> Effacer les filtres
                        </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="table" style="min-width:900px">
                        <thead>
                            <tr>
                                <th style="white-space:nowrap">Date / Heure</th>
                                <th>Utilisateur</th>
                                <th>Tenant</th>
                                <th>Action</th>
                                <th>Entité</th>
                                <th>ID</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $l): ?>
                            <tr>
                                <td style="white-space:nowrap;font-size:.8rem;color:#6b7280">
                                    <?= $l['created_at'] ? date('d/m/Y H:i', strtotime($l['created_at'])) : '—' ?>
                                </td>
                                <td>
                                    <?php if ($l['user_nom']): ?>
                                        <div style="font-size:.875rem;font-weight:500"><?= sanitize($l['user_nom']) ?></div>
                                        <div style="font-size:.75rem;color:#9ca3af"><?= sanitize($l['user_email'] ?? '') ?></div>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;font-size:.85rem">Système</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:.875rem">
                                    <?= $l['nom_entreprise'] ? sanitize($l['nom_entreprise']) : '<span style="color:#9ca3af">—</span>' ?>
                                </td>
                                <td>
                                    <span class="badge bg-info" style="font-size:.75rem"><?= sanitize($l['action'] ?? '—') ?></span>
                                </td>
                                <td style="font-size:.875rem;color:#374151">
                                    <?= sanitize($l['module'] ?? ($l['entite'] ?? '—')) ?>
                                </td>
                                <td style="font-size:.875rem;color:#6b7280">
                                    <?= $l['entite_id'] ?? ($l['description'] ? '<span title="' . sanitize($l['description']) . '">…</span>' : '—') ?>
                                </td>
                                <td style="font-size:.8rem;color:#9ca3af;font-family:monospace">
                                    <?= sanitize($l['ip_address'] ?? '—') ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="padding:10px 16px;color:#6b7280;font-size:.8rem;border-top:1px solid #f3f4f6;text-align:right">
                    <i class="fas fa-info-circle"></i> <?= count($logs) ?> entrée<?= count($logs) > 1 ? 's' : '' ?> affichée<?= count($logs) > 1 ? 's' : '' ?> (maximum 100)
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
