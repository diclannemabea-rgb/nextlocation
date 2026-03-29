<?php
/**
 * FlotteCar - Gestion des utilisateurs plateforme (Super Admin)
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
// TRAITEMENT POST
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();

    $action = $_POST['action'] ?? '';
    $uid    = (int)($_POST['user_id'] ?? 0);

    if ($uid > 0) {
        if ($action === 'reset_password') {
            // Générer un mot de passe aléatoire
            $newPass = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#'), 0, 12);
            $stmt = $db->prepare("UPDATE users SET password=?, password_plain=? WHERE id=?");
            $stmt->execute([password_hash($newPass, PASSWORD_DEFAULT), $newPass, $uid]);
            setFlash(FLASH_SUCCESS, 'Mot de passe réinitialisé. Nouveau mot de passe : <strong>' . htmlspecialchars($newPass) . '</strong>');

        } elseif ($action === 'supprimer') {
            $stmt = $db->prepare("DELETE FROM users WHERE id=? AND role != 'super_admin'");
            $stmt->execute([$uid]);
            if ($stmt->rowCount()) {
                setFlash(FLASH_SUCCESS, 'Utilisateur supprimé.');
            } else {
                setFlash(FLASH_ERROR, 'Impossible de supprimer cet utilisateur.');
            }
        }
    }

    redirect(BASE_URL . 'admin/utilisateurs.php?' . http_build_query(array_filter([
        'q'         => $_GET['q']         ?? '',
        'role'      => $_GET['role']      ?? '',
        'tenant_id' => $_GET['tenant_id'] ?? '',
    ])));
}

// -------------------------------------------------------
// FILTRES
// -------------------------------------------------------
$q              = trim($_GET['q']         ?? '');
$roleFilter     = $_GET['role']           ?? '';
$tenantIdFilter = (int)($_GET['tenant_id'] ?? 0);

// -------------------------------------------------------
// REQUÊTE UTILISATEURS
// -------------------------------------------------------
$where  = ['1=1'];
$params = [];

if ($q !== '') {
    $where[]  = "(u.nom LIKE ? OR u.prenom LIKE ? OR u.email LIKE ?)";
    $like = '%' . $q . '%';
    $params = array_merge($params, [$like, $like, $like]);
}
if ($roleFilter !== '') {
    $where[]  = "u.role = ?";
    $params[] = $roleFilter;
}
if ($tenantIdFilter > 0) {
    $where[]  = "u.tenant_id = ?";
    $params[] = $tenantIdFilter;
}

$whereSQL = implode(' AND ', $where);

$stmtUsers = $db->prepare("
    SELECT u.*, t.nom_entreprise
    FROM users u
    LEFT JOIN tenants t ON t.id = u.tenant_id
    WHERE $whereSQL
    ORDER BY u.created_at DESC
    LIMIT 100
");
$stmtUsers->execute($params);
$users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

$stmtCount = $db->prepare("SELECT COUNT(*) FROM users u LEFT JOIN tenants t ON t.id = u.tenant_id WHERE $whereSQL");
$stmtCount->execute($params);
$totalUsers = (int)$stmtCount->fetchColumn();

// Liste des tenants pour le filtre
$tenants = $db->query("SELECT id, nom_entreprise FROM tenants ORDER BY nom_entreprise ASC")->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------
// MISE EN PAGE
// -------------------------------------------------------
$activePage = 'admin_users';
$pageTitle  = 'Utilisateurs';
require_once BASE_PATH . '/includes/header.php';
?>

<style>
@media(max-width:768px) {
    .page-header { flex-direction:column; gap:10px; }
    .filter-form > div[style*="display:flex"] { flex-direction:column !important; }
    .filter-form > div > div { min-width:0 !important; width:100% !important; }
    .table thead { display:none; }
    .table tbody tr { display:block; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:10px; padding:12px; background:#fff; }
    .table tbody td { display:flex; justify-content:space-between; align-items:center; padding:4px 0; border-bottom:none; }
}
</style>
<div class="main-content">
    <div class="container">

        <?= renderFlashes() ?>

        <div class="page-header">
            <div style="display:flex;align-items:center;gap:12px">
                <h1 class="page-title">Utilisateurs</h1>
                <span class="badge bg-secondary" style="font-size:.9rem"><?= $totalUsers ?></span>
            </div>
        </div>

        <!-- Filtres -->
        <div class="card" style="margin-bottom:20px">
            <div class="card-body">
                <form method="GET" class="filter-form">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
                        <div style="flex:1;min-width:200px">
                            <label class="form-label">Rechercher</label>
                            <input type="text" name="q" class="form-control"
                                   placeholder="Nom, prénom, email…"
                                   value="<?= sanitize($q) ?>">
                        </div>
                        <div style="min-width:160px">
                            <label class="form-label">Rôle</label>
                            <select name="role" class="form-control">
                                <option value="">Tous les rôles</option>
                                <option value="super_admin"  <?= $roleFilter === 'super_admin'  ? 'selected' : '' ?>>Super Admin</option>
                                <option value="tenant_admin" <?= $roleFilter === 'tenant_admin' ? 'selected' : '' ?>>Admin Tenant</option>
                                <option value="tenant_user"  <?= $roleFilter === 'tenant_user'  ? 'selected' : '' ?>>Utilisateur</option>
                            </select>
                        </div>
                        <div style="min-width:200px">
                            <label class="form-label">Tenant</label>
                            <select name="tenant_id" class="form-control">
                                <option value="">Tous les tenants</option>
                                <?php foreach ($tenants as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= $tenantIdFilter === (int)$t['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($t['nom_entreprise']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Filtrer
                            </button>
                            <a href="<?= BASE_URL ?>admin/utilisateurs.php" class="btn btn-ghost">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tableau -->
        <div class="card">
            <div class="card-body" style="padding:0">
                <?php if (empty($users)): ?>
                    <div style="text-align:center;padding:48px;color:#9ca3af">
                        <i class="fas fa-users fa-3x" style="margin-bottom:12px;display:block"></i>
                        <p>Aucun utilisateur trouvé</p>
                    </div>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Email / Mot de passe</th>
                            <th>Rôle</th>
                            <th>Entreprise</th>
                            <th>Inscription</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <div style="font-weight:500"><?= sanitize(trim(($u['prenom'] ?? '') . ' ' . $u['nom'])) ?></div>
                            </td>
                            <td>
                                <div style="color:#6b7280;font-size:.875rem"><?= sanitize($u['email']) ?></div>
                                <?php if (!empty($u['password_plain'])): ?>
                                <div style="margin-top:3px"><span style="font-family:monospace;font-size:.75rem;background:#f8fafc;padding:2px 7px;border-radius:5px;border:1px solid #e2e8f0;color:#475569"><?= sanitize($u['password_plain']) ?></span></div>
                                <?php endif ?>
                            </td>
                            <td>
                                <?php
                                echo match($u['role']) {
                                    'super_admin'  => '<span class="badge bg-danger">Super Admin</span>',
                                    'tenant_admin' => '<span class="badge bg-primary">Admin</span>',
                                    'tenant_user'  => '<span class="badge bg-secondary">Utilisateur</span>',
                                    default        => '<span class="badge bg-secondary">' . sanitize($u['role']) . '</span>',
                                };
                                ?>
                            </td>
                            <td>
                                <?php if ($u['role'] === 'super_admin'): ?>
                                    <span style="color:#6b7280;font-style:italic;font-size:.875rem">Super Admin</span>
                                <?php elseif ($u['nom_entreprise']): ?>
                                    <span style="font-size:.875rem"><?= sanitize($u['nom_entreprise']) ?></span>
                                <?php else: ?>
                                    <span style="color:#9ca3af;font-size:.875rem">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:.875rem;color:#6b7280">
                                <?= $u['created_at'] ? date('d/m/Y', strtotime($u['created_at'])) : '—' ?>
                            </td>
                            <td>
                                <?php if ($u['role'] !== 'super_admin'): ?>
                                <form method="POST" style="display:inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-warning"
                                            onclick="return confirm('Réinitialiser le mot de passe de cet utilisateur ?')"
                                            title="Réinitialiser mot de passe">
                                        <i class="fas fa-key"></i>
                                    </button>
                                </form>
                                <form method="POST" style="display:inline;margin-left:4px">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="supprimer">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                            data-confirm="Supprimer définitivement cet utilisateur ?"
                                            onclick="return confirm('Supprimer définitivement cet utilisateur ?')"
                                            title="Supprimer">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php else: ?>
                                    <span style="color:#9ca3af;font-size:.8rem">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($totalUsers > 50): ?>
                <div style="padding:12px 16px;color:#6b7280;font-size:.85rem;border-top:1px solid #f3f4f6">
                    <i class="fas fa-info-circle"></i> Affichage des 50 premiers résultats sur <?= $totalUsers ?>. Affinez votre recherche pour voir plus.
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
