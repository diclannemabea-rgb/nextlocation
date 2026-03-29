<?php
/**
 * FlotteCar - Gestion des utilisateurs du tenant
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireTenantAdmin();

$database = new Database();
$db       = $database->getConnection();
$tenantId = getTenantId();
$userId   = getUserId();

// ── Traitement POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    // ── Ajouter un utilisateur ───────────────────────────────────
    if ($action === 'ajouter') {
        $nom      = trim($_POST['nom']    ?? '');
        $prenom   = trim($_POST['prenom'] ?? '');
        $email    = trim($_POST['email']  ?? '');
        $password = $_POST['password']    ?? '';
        $role     = $_POST['role']        ?? ROLE_TENANT_USER;

        // Valider le rôle
        if (!in_array($role, [ROLE_TENANT_ADMIN, ROLE_TENANT_USER], true)) {
            $role = ROLE_TENANT_USER;
        }

        $errors = [];
        if (!$nom)                        $errors[] = 'Le nom est obligatoire.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'L\'adresse e-mail est invalide.';
        if (strlen($password) < 8)        $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';

        if (empty($errors)) {
            // Vérifier que l'email n'est pas déjà utilisé
            $stmtCheck = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmtCheck->execute([$email]);
            if ($stmtCheck->fetch()) {
                setFlash(FLASH_ERROR, 'Cette adresse e-mail est déjà utilisée.');
            } else {
                $db->prepare("
                    INSERT INTO users (tenant_id, nom, prenom, email, password, role, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ")->execute([$tenantId, $nom, $prenom, $email, password_hash($password, PASSWORD_BCRYPT), $role]);
                setFlash(FLASH_SUCCESS, 'Utilisateur ajouté avec succès.');
            }
        } else {
            setFlash(FLASH_ERROR, implode(' ', $errors));
        }

        redirect(BASE_URL . 'app/parametres/utilisateurs.php');
    }

    // ── Supprimer un utilisateur ─────────────────────────────────
    if ($action === 'supprimer') {
        $targetId = (int)($_POST['id'] ?? 0);

        if ($targetId === (int)$userId) {
            setFlash(FLASH_ERROR, 'Vous ne pouvez pas supprimer votre propre compte.');
        } elseif ($targetId > 0) {
            // Interdire la suppression du super_admin et s'assurer qu'il appartient au tenant
            $stmtDel = $db->prepare("
                DELETE FROM users
                WHERE id = ? AND tenant_id = ? AND role != 'super_admin' AND id != ?
            ");
            $stmtDel->execute([$targetId, $tenantId, (int)$userId]);
            if ($stmtDel->rowCount() > 0) {
                setFlash(FLASH_SUCCESS, 'Utilisateur supprimé.');
            } else {
                setFlash(FLASH_ERROR, 'Impossible de supprimer cet utilisateur.');
            }
        }

        redirect(BASE_URL . 'app/parametres/utilisateurs.php');
    }

    // ── Basculer le rôle ─────────────────────────────────────────
    if ($action === 'toggle_role') {
        $targetId = (int)($_POST['id'] ?? 0);

        if ($targetId === (int)$userId) {
            setFlash(FLASH_ERROR, 'Vous ne pouvez pas modifier votre propre rôle.');
        } elseif ($targetId > 0) {
            // Récupérer le rôle actuel
            $stmtRole = $db->prepare("SELECT role FROM users WHERE id = ? AND tenant_id = ?");
            $stmtRole->execute([$targetId, $tenantId]);
            $targetUser = $stmtRole->fetch();

            if (!$targetUser) {
                setFlash(FLASH_ERROR, 'Utilisateur introuvable.');
            } else {
                // Si on retire le rôle admin, s'assurer qu'il reste au moins un admin
                if ($targetUser['role'] === ROLE_TENANT_ADMIN) {
                    $stmtCountAdmins = $db->prepare("
                        SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = ?
                    ");
                    $stmtCountAdmins->execute([$tenantId, ROLE_TENANT_ADMIN]);
                    $nbAdmins = (int)$stmtCountAdmins->fetchColumn();
                    if ($nbAdmins <= 1) {
                        setFlash(FLASH_ERROR, 'Impossible de rétrograder le seul administrateur.');
                        redirect(BASE_URL . 'app/parametres/utilisateurs.php');
                    }
                }

                $newRole = $targetUser['role'] === ROLE_TENANT_ADMIN ? ROLE_TENANT_USER : ROLE_TENANT_ADMIN;
                $db->prepare("UPDATE users SET role = ? WHERE id = ? AND tenant_id = ? AND id != ?")
                   ->execute([$newRole, $targetId, $tenantId, (int)$userId]);
                $label = $newRole === ROLE_TENANT_ADMIN ? 'Administrateur' : 'Utilisateur';
                setFlash(FLASH_SUCCESS, "Rôle modifié : $label.");
            }
        }

        redirect(BASE_URL . 'app/parametres/utilisateurs.php');
    }
}

// ── Charger les utilisateurs ─────────────────────────────────────
$stmtUsers = $db->prepare("SELECT * FROM users WHERE tenant_id = ? ORDER BY created_at DESC");
$stmtUsers->execute([$tenantId]);
$utilisateurs = $stmtUsers->fetchAll();

// Nombre d'admins (pour désactiver le bouton toggle si dernier admin)
$nbAdmins = count(array_filter($utilisateurs, fn($u) => $u['role'] === ROLE_TENANT_ADMIN));

$pageTitle  = 'Utilisateurs';
$activePage = 'utilisateurs';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h2 class="page-title"><i class="fas fa-users"></i> Utilisateurs</h2>
        <p class="page-subtitle">Gérez les membres de votre espace FlotteCar</p>
    </div>
</div>

<?= renderFlashes() ?>

<div style="display:grid;grid-template-columns:1fr 2fr;gap:1.5rem;align-items:start;">

    <!-- ── Formulaire ajout ─────────────────────────────────── -->
    <div class="card">
        <div class="card-header">
            <h3 style="margin:0;"><i class="fas fa-user-plus"></i> Ajouter un utilisateur</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="<?= BASE_URL ?>app/parametres/utilisateurs.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="ajouter">

                <div class="form-group">
                    <label class="form-label">Nom <span style="color:var(--danger);">*</span></label>
                    <input type="text" name="nom" class="form-control" placeholder="Nom de famille" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Prénom</label>
                    <input type="text" name="prenom" class="form-control" placeholder="Prénom">
                </div>

                <div class="form-group">
                    <label class="form-label">Adresse e-mail <span style="color:var(--danger);">*</span></label>
                    <input type="email" name="email" class="form-control" placeholder="email@exemple.com" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Mot de passe <span style="color:var(--danger);">*</span></label>
                    <input type="password" name="password" class="form-control"
                           placeholder="Min. 8 caractères" minlength="8" required autocomplete="new-password">
                </div>

                <div class="form-group">
                    <label class="form-label">Rôle</label>
                    <select name="role" class="form-control">
                        <option value="<?= ROLE_TENANT_USER ?>">Utilisateur</option>
                        <option value="<?= ROLE_TENANT_ADMIN ?>">Administrateur</option>
                    </select>
                </div>

                <div class="form-actions" style="margin-top:1rem;">
                    <button type="submit" class="btn btn-primary" style="width:100%;">
                        <i class="fas fa-plus"></i> Ajouter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Liste des utilisateurs ───────────────────────────── -->
    <div class="card">
        <div class="card-header">
            <h3 style="margin:0;">
                <i class="fas fa-users"></i> Utilisateurs
                <span class="badge bg-info" style="margin-left:.5rem;"><?= count($utilisateurs) ?></span>
            </h3>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($utilisateurs)): ?>
                <div style="padding:2.5rem;text-align:center;">
                    <i class="fas fa-users" style="font-size:2.5rem;color:var(--gray-300);margin-bottom:.75rem;display:block;"></i>
                    <p style="color:var(--gray-500);margin:0;">Aucun utilisateur trouvé.</p>
                </div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Email</th>
                        <th>Rôle</th>
                        <th>Date ajout</th>
                        <th style="width:130px;text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($utilisateurs as $u):
                        $isCurrentUser = ((int)$u['id'] === (int)$userId);
                        $isOnlyAdmin   = ($u['role'] === ROLE_TENANT_ADMIN && $nbAdmins <= 1);
                    ?>
                    <tr <?= $isCurrentUser ? 'style="background:var(--gray-50);"' : '' ?>>
                        <td>
                            <div style="font-weight:600;">
                                <?= sanitize(trim($u['prenom'] . ' ' . $u['nom'])) ?>
                                <?php if ($isCurrentUser): ?>
                                    <span style="font-size:.75rem;color:var(--gray-400);font-weight:400;">(vous)</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="font-size:.9rem;color:var(--gray-600);"><?= sanitize($u['email']) ?></td>
                        <td>
                            <?php if ($u['role'] === ROLE_TENANT_ADMIN): ?>
                                <span class="badge bg-primary">Admin</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Utilisateur</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;font-size:.85rem;color:var(--gray-500);">
                            <?= formatDate($u['created_at']) ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if (!$isCurrentUser): ?>
                            <div style="display:flex;gap:.4rem;justify-content:center;">
                                <!-- Toggle rôle -->
                                <form method="POST" action="<?= BASE_URL ?>app/parametres/utilisateurs.php"
                                      style="display:inline;"
                                      <?= $isOnlyAdmin ? 'title="Seul administrateur — impossible de rétrograder"' : '' ?>>
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_role">
                                    <input type="hidden" name="id"     value="<?= (int)$u['id'] ?>">
                                    <button type="submit"
                                            class="btn btn-sm <?= $u['role'] === ROLE_TENANT_ADMIN ? 'btn-warning' : 'btn-primary' ?>"
                                            <?= $isOnlyAdmin ? 'disabled title="Seul administrateur"' : '' ?>
                                            title="<?= $u['role'] === ROLE_TENANT_ADMIN ? 'Rétrograder en Utilisateur' : 'Promouvoir en Admin' ?>">
                                        <i class="fas <?= $u['role'] === ROLE_TENANT_ADMIN ? 'fa-user-minus' : 'fa-user-shield' ?>"></i>
                                    </button>
                                </form>

                                <!-- Supprimer -->
                                <form method="POST" action="<?= BASE_URL ?>app/parametres/utilisateurs.php"
                                      style="display:inline;"
                                      data-confirm="Supprimer l'utilisateur <?= addslashes(sanitize(trim($u['prenom'] . ' ' . $u['nom']))) ?> ?">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="supprimer">
                                    <input type="hidden" name="id"     value="<?= (int)$u['id'] ?>">
                                    <button type="submit"
                                            class="btn btn-sm btn-danger"
                                            <?= $isOnlyAdmin ? 'disabled title="Seul administrateur"' : '' ?>
                                            title="Supprimer l'utilisateur">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                            <?php else: ?>
                                <span style="color:var(--gray-300);font-size:.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php
$extraJs = <<<'JS'
// Confirmation avant suppression via data-confirm
document.querySelectorAll('form[data-confirm]').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        var msg = form.getAttribute('data-confirm');
        if (!confirm(msg + '\nCette action est irréversible.')) {
            e.preventDefault();
        }
    });
});
JS;
require_once BASE_PATH . '/includes/footer.php';
?>
