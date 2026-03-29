<?php
define('BASE_PATH', dirname(__DIR__));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';

requireGuest();

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Erreur de securite. Rechargez la page.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) {
            $error = 'Email et mot de passe requis.';
        } else {
            $db     = (new Database())->getConnection();
            $result = checkCredentials($db, $email, $password);

            if (!$result) {
                $error = 'Email ou mot de passe incorrect.';
            } else {
                $user   = $result['user'];
                $tenant = $result['tenant'] ?? null;

                loginUser($user, $tenant);

                if ($user['role'] === ROLE_SUPER_ADMIN) {
                    redirect(BASE_URL . 'admin/dashboard.php');
                }

                if ($tenant && (int)$tenant['actif'] === 0) {
                    redirect(BASE_URL . 'auth/compte_inactif.php');
                }

                if ($tenant) {
                    $abonActif = checkAbonnement($db, $tenant['id']);
                    if (!$abonActif) {
                        $_SESSION['abonnement_expire'] = true;
                        redirect(BASE_URL . 'auth/abonnement_expire.php');
                    }
                }

                redirect(BASE_URL . 'app/dashboard.php');
            }
        }
    }
}

$csrfToken = generateCSRF();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion — FlotteCar</title>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/img/icon-192.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            background: #f8fafc;
        }
        .login-wrap {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }
        /* Colonne gauche - branding */
        .login-brand {
            flex: 1;
            background: #0f172a;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 60px 48px;
            color: #fff;
        }
        .brand-name {
            font-size: 2rem;
            font-weight: 800;
            color: #fff;
            margin-bottom: 12px;
        }
        .brand-sub {
            font-size: 1rem;
            color: rgba(255,255,255,0.6);
            line-height: 1.6;
            margin-bottom: 48px;
        }
        .brand-features {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .brand-feature {
            font-size: .85rem;
            color: rgba(255,255,255,0.4);
            line-height: 1.4;
        }
        .brand-feature::before {
            content: '—  ';
        }
        /* Colonne droite - formulaire */
        .login-form-wrap {
            flex: 1;
            background: #fff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 60px 48px;
            max-width: 520px;
        }
        .login-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }
        .login-sub {
            color: #64748b;
            font-size: 13px;
            margin-bottom: 32px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 6px;
        }
        .form-group input {
            width: 100%;
            height: 44px;
            padding: 0 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            color: #1e293b;
            background: #fff;
            outline: none;
            transition: border-color 0.2s;
        }
        .form-group input:focus {
            border-color: #0d9488;
            box-shadow: 0 0 0 3px rgba(13,148,136,0.10);
        }
        .form-group input::placeholder {
            color: #94a3b8;
        }
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 13px;
            margin-bottom: 20px;
        }
        .btn-login {
            width: 100%;
            height: 44px;
            background: #0d9488;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 4px;
        }
        .btn-login:hover { background: #0f766e; }
        .btn-login:active { transform: scale(0.99); }
        .login-footer {
            margin-top: 24px;
            text-align: center;
            font-size: 13px;
            color: #64748b;
        }
        .login-footer a {
            color: #0d9488;
            font-weight: 600;
            text-decoration: none;
        }
        .login-footer a:hover { text-decoration: underline; }
        @media (max-width: 768px) {
            .login-brand { display: none; }
            .login-wrap { justify-content: center; }
            .login-form-wrap {
                max-width: 100%;
                padding: 40px 24px;
            }
        }
    </style>
</head>
<body>

<div class="login-wrap">
    <!-- Branding gauche -->
    <div class="login-brand">
        <div class="brand-name">FlotteCar</div>
        <div class="brand-sub">Gerez votre flotte en toute simplicite</div>
        <div class="brand-features">
            <div class="brand-feature">Suivi GPS temps reel</div>
            <div class="brand-feature">Gestion locations</div>
            <div class="brand-feature">Comptabilite unifiee</div>
            <div class="brand-feature">Application mobile PWA</div>
        </div>
    </div>

    <!-- Formulaire droit -->
    <div class="login-form-wrap">
        <div class="login-title">Connexion</div>
        <p class="login-sub">Accedez a votre espace de gestion</p>

        <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

            <div class="form-group">
                <label for="email">Adresse email</label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($email) ?>"
                       placeholder="votre@email.com"
                       autocomplete="email" required>
            </div>

            <div class="form-group">
                <label for="password">Mot de passe</label>
                <input type="password" id="password" name="password"
                       placeholder="Votre mot de passe"
                       autocomplete="current-password" required>
            </div>

            <button type="submit" class="btn-login">Se connecter</button>
        </form>

        <div class="login-footer">
            Pas encore de compte ? <a href="<?= BASE_URL ?>auth/register.php">Creer un compte</a>
        </div>
    </div>
</div>

</body>
</html>
