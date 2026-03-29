<?php
/**
 * FlotteCar - Mon profil utilisateur
 */

define('BASE_PATH', dirname(__DIR__));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';

requireTenantAuth();

$database = new Database();
$db = $database->getConnection();

$userId = getUserId();

// -------------------------------------------------------
// TRAITEMENT POST
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();

    $action = $_POST['action'] ?? '';

    if ($action === 'profil') {
        $nom    = trim($_POST['nom']    ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $email  = trim($_POST['email']  ?? '');

        if (!$nom || !$email) {
            setFlash(FLASH_ERROR, 'Le nom et l\'email sont obligatoires.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash(FLASH_ERROR, 'Adresse email invalide.');
        } else {
            $stmt = $db->prepare("UPDATE users SET nom=?, prenom=?, email=? WHERE id=?");
            $stmt->execute([$nom, $prenom, $email, $userId]);
            $_SESSION['user_nom']    = $nom;
            $_SESSION['user_prenom'] = $prenom;
            $_SESSION['user_email']  = $email;
            setFlash(FLASH_SUCCESS, 'Profil mis à jour avec succès.');
        }
    } elseif ($action === 'mdp') {
        $ancienMdp  = $_POST['ancien_mdp']   ?? '';
        $nouveauMdp = $_POST['nouveau_mdp']  ?? '';
        $confirmMdp = $_POST['confirm_mdp']  ?? '';

        $stmtUser = $db->prepare("SELECT password FROM users WHERE id=?");
        $stmtUser->execute([$userId]);
        $currentHash = $stmtUser->fetchColumn();

        if (!password_verify($ancienMdp, $currentHash)) {
            setFlash(FLASH_ERROR, 'Ancien mot de passe incorrect.');
        } elseif (strlen($nouveauMdp) < 8) {
            setFlash(FLASH_ERROR, 'Le nouveau mot de passe doit contenir au moins 8 caractères.');
        } elseif ($nouveauMdp !== $confirmMdp) {
            setFlash(FLASH_ERROR, 'Les nouveaux mots de passe ne correspondent pas.');
        } else {
            $stmt = $db->prepare("UPDATE users SET password=? WHERE id=?");
            $stmt->execute([password_hash($nouveauMdp, PASSWORD_DEFAULT), $userId]);
            setFlash(FLASH_SUCCESS, 'Mot de passe modifié avec succès.');
        }
    }

    redirect(BASE_URL . 'app/profil.php');
}

// -------------------------------------------------------
// CHARGEMENT UTILISATEUR
// -------------------------------------------------------
$stmtUser = $db->prepare("SELECT * FROM users WHERE id=?");
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

$userInitials = strtoupper(
    substr($user['prenom'] ?? 'U', 0, 1) .
    substr($user['nom']    ?? '',  0, 1)
);

// -------------------------------------------------------
// MISE EN PAGE
// -------------------------------------------------------
$activePage = 'profil';
$pageTitle  = 'Mon profil';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="main-content">
    <div class="container">

        <?= renderFlashes() ?>

        <div class="page-header">
            <div>
                <h1 class="page-title">Mon profil</h1>
                <p class="page-subtitle">Gérez vos informations personnelles et votre sécurité</p>
            </div>
        </div>

        <!-- Avatar -->
        <div style="text-align:center;margin-bottom:24px">
            <div style="width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,#0d9488,#14b8a6);display:flex;align-items:center;justify-content:center;color:#fff;font-size:2rem;font-weight:700;margin:0 auto 12px">
                <?= htmlspecialchars($userInitials) ?>
            </div>
            <div style="font-weight:600;font-size:1.05rem"><?= sanitize(getUserName()) ?></div>
            <div style="color:#6b7280;font-size:.875rem"><?= sanitize($user['email']) ?></div>
            <span class="badge bg-<?= $user['role'] === ROLE_TENANT_ADMIN ? 'primary' : 'secondary' ?>" style="margin-top:6px">
                <?= $user['role'] === ROLE_TENANT_ADMIN ? 'Administrateur' : 'Utilisateur' ?>
            </span>
        </div>

        <div class="grid-2col">

            <!-- Informations personnelles -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-user" style="color:#0d9488;margin-right:8px"></i>Informations personnelles</h3>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="profil">

                        <div class="form-group">
                            <label class="form-label">Nom <span style="color:#ef4444">*</span></label>
                            <input type="text" name="nom" class="form-control"
                                   value="<?= sanitize($user['nom']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Prénom</label>
                            <input type="text" name="prenom" class="form-control"
                                   value="<?= sanitize($user['prenom'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email <span style="color:#ef4444">*</span></label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= sanitize($user['email']) ?>" required>
                        </div>

                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label">Rôle</label>
                            <input type="text" class="form-control" disabled
                                   value="<?= $user['role'] === ROLE_TENANT_ADMIN ? 'Administrateur' : 'Utilisateur' ?>">
                        </div>

                        <div class="form-actions" style="margin-top:20px">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Enregistrer
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Sécurité -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-lock" style="color:#f59e0b;margin-right:8px"></i>Sécurité</h3>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="mdp">

                        <div class="form-group">
                            <label class="form-label">Ancien mot de passe</label>
                            <input type="password" name="ancien_mdp" class="form-control"
                                   placeholder="••••••••" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Nouveau mot de passe <small style="color:#6b7280">(min. 8 caractères)</small></label>
                            <input type="password" name="nouveau_mdp" class="form-control"
                                   placeholder="••••••••" minlength="8" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirmer le mot de passe</label>
                            <input type="password" name="confirm_mdp" class="form-control"
                                   placeholder="••••••••" minlength="8" required>
                        </div>

                        <div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:12px;margin-bottom:16px;font-size:.85rem;color:#92400e">
                            <i class="fas fa-exclamation-triangle"></i>
                            Choisissez un mot de passe fort d'au moins 8 caractères combinant lettres, chiffres et symboles.
                        </div>

                        <div class="form-actions" style="margin-top:4px">
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-key"></i> Changer le mot de passe
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div><!-- /grid-2col -->

    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
