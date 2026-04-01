<?php
/**
 * FlotteCar - Page d'inscription tenant
 * Creation d'un compte entreprise en 3 etapes
 */

define('BASE_PATH', dirname(__DIR__));
session_start();

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';

requireGuest();

$errors  = [];
$success = false;

$formData = [
    'nom_entreprise' => '',
    'email'          => '',
    'telephone'      => '',
    'prenom'         => '',
    'nom'            => '',
    'type_usage'     => 'les_deux',
];

// ============================================================
// TRAITEMENT DU FORMULAIRE POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de securite invalide. Rechargez la page.';
    } else {
        $formData = [
            'nom_entreprise' => trim($_POST['nom_entreprise'] ?? ''),
            'email'          => strtolower(trim($_POST['email'] ?? '')),
            'telephone'      => trim($_POST['telephone'] ?? ''),
            'prenom'         => trim($_POST['prenom'] ?? ''),
            'nom'            => trim($_POST['nom'] ?? ''),
            'type_usage'     => $_POST['type_usage'] ?? 'les_deux',
        ];
        $password        = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        if (empty($formData['nom_entreprise'])) {
            $errors[] = 'Le nom de l\'entreprise est requis.';
        }
        if (empty($formData['email'])) {
            $errors[] = 'L\'adresse email est requise.';
        } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'L\'adresse email est invalide.';
        }
        if (empty($formData['prenom'])) {
            $errors[] = 'Votre prenom est requis.';
        }
        if (empty($formData['nom'])) {
            $errors[] = 'Votre nom est requis.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Le mot de passe doit contenir au moins 8 caracteres.';
        }
        if ($password !== $passwordConfirm) {
            $errors[] = 'Les mots de passe ne correspondent pas.';
        }
        if (!in_array($formData['type_usage'], ['location', 'controle', 'les_deux'])) {
            $errors[] = 'Type d\'usage invalide.';
        }

        if (empty($errors)) {
            $db = (new Database())->getConnection();

            if (!$db) {
                $errors[] = 'Erreur de connexion a la base de donnees.';
            } else {
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$formData['email']]);
                if ($stmt->fetch()) {
                    $errors[] = 'Cette adresse email est deja utilisee.';
                } else {
                    try {
                        $db->beginTransaction();

                        $stmt = $db->prepare("
                            INSERT INTO tenants (nom_entreprise, email, telephone, type_usage, plan, actif, created_at)
                            VALUES (?, ?, ?, ?, 'mensuel', 0, NOW())
                        ");
                        $stmt->execute([
                            $formData['nom_entreprise'],
                            $formData['email'],
                            $formData['telephone'],
                            $formData['type_usage'],
                        ]);
                        $tenantId = (int)$db->lastInsertId();

                        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $db->prepare("
                            INSERT INTO users (tenant_id, nom, prenom, email, password, password_plain, role, statut, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, 'tenant_admin', 'actif', NOW())
                        ");
                        $stmt->execute([
                            $tenantId,
                            $formData['nom'],
                            $formData['prenom'],
                            $formData['email'],
                            $passwordHash,
                            $password,
                        ]);

                        $db->commit();

                        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
                        $stmt->execute([$formData['email']]);
                        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

                        try {
                            $typeUsageLabel = match($formData['type_usage']) {
                                'location' => 'Location vehicules',
                                'controle' => 'GPS / Controle flotte',
                                default    => 'Location + GPS',
                            };
                            $db->prepare("INSERT INTO notifs_push (tenant_id, user_id, type, titre, corps, url, lu, envoye, created_at)
                                VALUES (0, NULL, 'inscription', ?, ?, ?, 0, 0, NOW())")
                               ->execute([
                                   'Nouvelle inscription : ' . $formData['nom_entreprise'],
                                   $formData['prenom'] . ' ' . $formData['nom'] . ' - ' . $formData['email'] . ' - ' . ($formData['telephone'] ?: 'N/A') . ' - ' . $typeUsageLabel,
                                   BASE_URL . 'admin/tenants.php',
                               ]);
                        } catch (Exception $e) { /* silencieux */ }

                        $tenantRow = [
                            'id'             => $tenantId,
                            'nom_entreprise' => $formData['nom_entreprise'],
                            'plan'           => PLAN_STARTER,
                            'type_usage'     => $formData['type_usage'],
                            'actif'          => 0,
                        ];
                        loginUser($userRow, $tenantRow);
                        $_SESSION['nouveau_compte'] = [
                            'nom_entreprise' => $formData['nom_entreprise'],
                            'email'          => $formData['email'],
                            'telephone'      => $formData['telephone'],
                            'prenom'         => $formData['prenom'],
                            'nom'            => $formData['nom'],
                            'type_usage'     => $formData['type_usage'],
                            'tenant_id'      => $tenantId,
                            'password'       => $password,
                        ];

                        redirect(BASE_URL . 'auth/choisir_abonnement.php');

                    } catch (Exception $e) {
                        $db->rollBack();
                        error_log('Register error: ' . $e->getMessage());
                        $errors[] = 'Erreur creation compte: ' . $e->getMessage();
                    }
                }
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
    <title>Creer un compte | FlotteCar</title>
    <meta name="theme-color" content="#0d9488">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/img/icon-192.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .register-card {
            width: 100%;
            max-width: 520px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.07);
            padding: 40px 36px;
        }
        .register-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
            text-align: center;
        }
        .register-sub {
            font-size: 13px;
            color: #64748b;
            text-align: center;
            margin-bottom: 28px;
        }

        /* Alert errors */
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 13px;
            margin-bottom: 20px;
        }

        /* Step indicator */
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 32px;
            gap: 0;
        }
        .step-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .step-dot {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 2px solid #e2e8f0;
            background: #fff;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            transition: all 0.2s;
            position: relative;
            z-index: 1;
        }
        .step-dot.active {
            background: #0d9488;
            border-color: #0d9488;
            color: #fff;
        }
        .step-dot.done {
            background: #059669;
            border-color: #059669;
            color: #fff;
        }
        .step-label {
            font-size: 11px;
            font-weight: 500;
            color: #94a3b8;
            margin-top: 4px;
        }
        .step-dot.active + .step-label,
        .step-dot.done + .step-label { color: #374151; }
        .step-connector {
            width: 48px;
            height: 2px;
            background: #e2e8f0;
            margin: 0 6px;
            position: relative;
            top: -8px;
            transition: background 0.2s;
        }
        .step-connector.done { background: #059669; }

        /* Form */
        .form-step { display: none; }
        .form-step.active { display: block; }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 6px;
        }
        .form-group label .req { color: #dc2626; }
        .form-group input,
        .form-group select {
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
        .form-group input:focus,
        .form-group select:focus {
            border-color: #0d9488;
            box-shadow: 0 0 0 3px rgba(13,148,136,0.10);
        }
        .form-group input::placeholder { color: #94a3b8; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        /* Usage cards (step 3) */
        .usage-cards {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 8px;
        }
        .usage-card input[type="radio"] { display: none; }
        .usage-card-label {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            transition: border-color 0.2s;
        }
        .usage-card input:checked + .usage-card-label {
            border-color: #0d9488;
            background: rgba(13,148,136,0.04);
        }
        .usage-card-title { font-size: 14px; font-weight: 600; color: #1e293b; }
        .usage-card-desc { font-size: 12px; color: #64748b; margin-top: 2px; }
        .usage-card-check {
            margin-left: auto;
            color: #e2e8f0;
            font-size: 16px;
        }
        .usage-card input:checked + .usage-card-label .usage-card-check {
            color: #0d9488;
        }

        /* Abonnement cards (step 3) */
        .plan-cards {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 24px;
            margin-bottom: 8px;
        }
        .plan-card {
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 20px 16px;
            cursor: pointer;
            position: relative;
            transition: border-color 0.2s;
        }
        .plan-card.recommended {
            border-color: #0d9488;
        }
        .plan-card input[type="radio"] { display: none; }
        .plan-card.selected {
            border-color: #0d9488;
            background: rgba(13,148,136,0.03);
        }
        .plan-badge {
            position: absolute;
            top: -10px;
            right: 12px;
            background: #0d9488;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 10px;
            border-radius: 10px;
        }
        .plan-name {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }
        .plan-price {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
        }
        .plan-price span {
            font-size: 13px;
            font-weight: 400;
            color: #64748b;
        }
        .plan-features {
            margin-top: 14px;
            list-style: none;
            padding: 0;
        }
        .plan-features li {
            font-size: 12px;
            color: #64748b;
            padding: 3px 0;
        }
        .plan-features li::before {
            content: '- ';
        }

        /* Nav buttons */
        .step-nav {
            display: flex;
            gap: 10px;
            margin-top: 24px;
        }
        .step-nav .btn {
            flex: 1;
            height: 44px;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: background 0.2s;
        }
        .btn-ghost {
            background: transparent;
            color: #64748b;
            border: 1.5px solid #e2e8f0 !important;
        }
        .btn-ghost:hover { background: #f8fafc; }
        .btn-teal {
            background: #0d9488;
            color: #fff;
        }
        .btn-teal:hover { background: #0f766e; }
        .btn-orange {
            background: #f97316;
            color: #fff;
        }
        .btn-orange:hover { background: #ea580c; }

        /* Password strength */
        .password-strength {
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .strength-bar {
            flex: 1;
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            overflow: hidden;
        }
        .strength-fill {
            height: 100%;
            width: 0;
            border-radius: 2px;
            transition: width 0.3s, background 0.3s;
        }
        .strength-text {
            font-size: 11px;
            color: #94a3b8;
            white-space: nowrap;
        }

        /* Footer */
        .register-footer {
            margin-top: 24px;
            text-align: center;
            font-size: 13px;
            color: #64748b;
        }
        .register-footer a {
            color: #0d9488;
            font-weight: 600;
            text-decoration: none;
        }
        .register-footer a:hover { text-decoration: underline; }

        /* Toast */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #dc2626;
            color: #fff;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            z-index: 9999;
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.3s;
        }
        .toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        @media (max-width: 520px) {
            .register-card { padding: 28px 20px; }
            .form-row { grid-template-columns: 1fr; }
            .plan-cards { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="register-card">
    <div class="register-title">Creer un compte</div>
    <p class="register-sub">Configurez votre espace de gestion en quelques etapes</p>

    <?php if (!empty($errors)): ?>
    <div class="alert-error">
        <?php foreach ($errors as $err): ?>
        <div><?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Step indicator -->
    <div class="step-indicator" id="step-indicator">
        <div class="step-wrapper">
            <div class="step-dot active" id="dot-1">1</div>
            <div class="step-label">Entreprise</div>
        </div>
        <div class="step-connector" id="conn-1"></div>
        <div class="step-wrapper">
            <div class="step-dot" id="dot-2">2</div>
            <div class="step-label">Compte</div>
        </div>
        <div class="step-connector" id="conn-2"></div>
        <div class="step-wrapper">
            <div class="step-dot" id="dot-3">3</div>
            <div class="step-label">Abonnement</div>
        </div>
    </div>

    <!-- Formulaire multi-etapes -->
    <form method="POST" action="" id="register-form">
        <?= csrfField() ?>

        <!-- ETAPE 1: Entreprise -->
        <div class="form-step active" id="step-1">
            <div class="form-group">
                <label for="nom_entreprise">Nom de l'entreprise <span class="req">*</span></label>
                <input type="text" id="nom_entreprise" name="nom_entreprise"
                       value="<?= htmlspecialchars($formData['nom_entreprise']) ?>"
                       placeholder="Mon Entreprise SARL"
                       autocomplete="organization" required>
            </div>

            <div class="form-group">
                <label for="reg_email">Adresse email <span class="req">*</span></label>
                <input type="email" id="reg_email" name="email"
                       value="<?= htmlspecialchars($formData['email']) ?>"
                       placeholder="contact@monentreprise.com"
                       autocomplete="email" required>
            </div>

            <div class="form-group">
                <label for="telephone">Telephone</label>
                <input type="tel" id="telephone" name="telephone"
                       value="<?= htmlspecialchars($formData['telephone']) ?>"
                       placeholder="+225 07 XX XX XX XX"
                       autocomplete="tel">
            </div>

            <div class="form-group">
                <label>Comment allez-vous utiliser FlotteCar ? <span class="req">*</span></label>
                <div class="usage-cards">
                    <div class="usage-card">
                        <input type="radio" name="type_usage" value="location" id="usage_location"
                               <?= $formData['type_usage'] === 'location' ? 'checked' : '' ?>>
                        <label class="usage-card-label" for="usage_location">
                            <div>
                                <div class="usage-card-title">Location de vehicules</div>
                                <div class="usage-card-desc">Contrats, clients, paiements, rapports</div>
                            </div>
                            <span class="usage-card-check">&#10003;</span>
                        </label>
                    </div>
                    <div class="usage-card">
                        <input type="radio" name="type_usage" value="controle" id="usage_gps"
                               <?= $formData['type_usage'] === 'controle' ? 'checked' : '' ?>>
                        <label class="usage-card-label" for="usage_gps">
                            <div>
                                <div class="usage-card-title">Controle de flotte GPS</div>
                                <div class="usage-card-desc">Tracking temps reel, alertes, historique</div>
                            </div>
                            <span class="usage-card-check">&#10003;</span>
                        </label>
                    </div>
                    <div class="usage-card">
                        <input type="radio" name="type_usage" value="les_deux" id="usage_both"
                               <?= $formData['type_usage'] === 'les_deux' ? 'checked' : '' ?>>
                        <label class="usage-card-label" for="usage_both">
                            <div>
                                <div class="usage-card-title">Les deux</div>
                                <div class="usage-card-desc">Location + GPS - Solution complete</div>
                            </div>
                            <span class="usage-card-check">&#10003;</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="step-nav">
                <button type="button" class="btn btn-teal" onclick="goToStep(2)">Suivant</button>
            </div>
        </div>

        <!-- ETAPE 2: Compte -->
        <div class="form-step" id="step-2">
            <div class="form-row">
                <div class="form-group">
                    <label for="prenom">Prenom <span class="req">*</span></label>
                    <input type="text" id="prenom" name="prenom"
                           value="<?= htmlspecialchars($formData['prenom']) ?>"
                           placeholder="Jean"
                           autocomplete="given-name" required>
                </div>
                <div class="form-group">
                    <label for="nom">Nom <span class="req">*</span></label>
                    <input type="text" id="nom" name="nom"
                           value="<?= htmlspecialchars($formData['nom']) ?>"
                           placeholder="Kouassi"
                           autocomplete="family-name" required>
                </div>
            </div>

            <div class="form-group">
                <label for="reg_password">Mot de passe <span class="req">*</span></label>
                <input type="password" id="reg_password" name="password"
                       placeholder="Minimum 8 caracteres"
                       autocomplete="new-password" required>
                <div class="password-strength">
                    <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
                    <div class="strength-text" id="strength-text"></div>
                </div>
            </div>

            <div class="form-group">
                <label for="password_confirm">Confirmer le mot de passe <span class="req">*</span></label>
                <input type="password" id="password_confirm" name="password_confirm"
                       placeholder="Repetez le mot de passe"
                       autocomplete="new-password" required>
            </div>

            <div class="step-nav">
                <button type="button" class="btn btn-ghost" onclick="goToStep(1)">Precedent</button>
                <button type="button" class="btn btn-teal" onclick="goToStep(3)">Suivant</button>
            </div>
        </div>

        <!-- ETAPE 3: Abonnement -->
        <div class="form-step" id="step-3">
            <p style="font-size:14px;font-weight:600;color:#0f172a;margin-bottom:4px;">Choisissez votre abonnement</p>
            <p style="font-size:12px;color:#64748b;margin-bottom:16px;">Vous pouvez changer a tout moment</p>

            <div class="plan-cards">
                <div class="plan-card" id="plan-mensuel" onclick="selectPlan('mensuel')">
                    <input type="radio" name="plan_choisi" value="mensuel" checked>
                    <div class="plan-name">Mensuel</div>
                    <div class="plan-price">15 000 <span>FCFA/mois</span></div>
                    <ul class="plan-features">
                        <li>Jusqu'a 10 vehicules</li>
                        <li>Suivi GPS temps reel</li>
                        <li>Gestion locations</li>
                        <li>Support email</li>
                    </ul>
                </div>
                <div class="plan-card recommended" id="plan-annuel" onclick="selectPlan('annuel')">
                    <div class="plan-badge">Economisez 37%</div>
                    <input type="radio" name="plan_choisi" value="annuel">
                    <div class="plan-name">Annuel</div>
                    <div class="plan-price">120 000 <span>FCFA/an</span></div>
                    <ul class="plan-features">
                        <li>Jusqu'a 10 vehicules</li>
                        <li>Suivi GPS temps reel</li>
                        <li>Gestion locations</li>
                        <li>Support prioritaire</li>
                    </ul>
                </div>
            </div>

            <div class="step-nav">
                <button type="button" class="btn btn-ghost" onclick="goToStep(2)">Precedent</button>
                <button type="submit" class="btn btn-orange">Creer mon compte</button>
            </div>
        </div>

    </form>

    <div class="register-footer">
        Deja un compte ? <a href="<?= BASE_URL ?>auth/login.php">Se connecter</a>
    </div>
</div>

<!-- Toast container -->
<div class="toast" id="toast"></div>

<script>
let currentStep = 1;

function goToStep(step) {
    if (step > currentStep && !validateStep(currentStep)) return;

    document.getElementById('step-' + currentStep)?.classList.remove('active');

    const prevDot = document.getElementById('dot-' + currentStep);
    if (prevDot) {
        prevDot.classList.remove('active');
        if (step > currentStep) {
            prevDot.classList.add('done');
            prevDot.innerHTML = '&#10003;';
        } else {
            prevDot.classList.remove('done');
            prevDot.textContent = currentStep;
        }
    }

    if (step > currentStep) {
        document.getElementById('conn-' + currentStep)?.classList.add('done');
    } else {
        document.getElementById('conn-' + step)?.classList.remove('done');
    }

    currentStep = step;
    document.getElementById('step-' + step)?.classList.add('active');
    const newDot = document.getElementById('dot-' + step);
    if (newDot) {
        newDot.classList.add('active');
        newDot.classList.remove('done');
        newDot.textContent = step;
    }

    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function validateStep(step) {
    if (step === 1) {
        const nom   = document.getElementById('nom_entreprise');
        const email = document.getElementById('reg_email');
        if (!nom?.value.trim()) {
            nom?.focus();
            showToast('Le nom de l\'entreprise est requis.');
            return false;
        }
        if (!email?.value.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
            email?.focus();
            showToast('Veuillez saisir une adresse email valide.');
            return false;
        }
    }
    if (step === 2) {
        const prenom = document.getElementById('prenom');
        const nom    = document.getElementById('nom');
        const pass   = document.getElementById('reg_password');
        const conf   = document.getElementById('password_confirm');
        if (!prenom?.value.trim()) { prenom?.focus(); showToast('Votre prenom est requis.'); return false; }
        if (!nom?.value.trim()) { nom?.focus(); showToast('Votre nom est requis.'); return false; }
        if (!pass?.value || pass.value.length < 8) { pass?.focus(); showToast('Le mot de passe doit contenir au moins 8 caracteres.'); return false; }
        if (pass.value !== conf?.value) { conf?.focus(); showToast('Les mots de passe ne correspondent pas.'); return false; }
    }
    return true;
}

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3500);
}

// Plan selection
function selectPlan(plan) {
    document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('selected'));
    const card = document.getElementById('plan-' + plan);
    card.classList.add('selected');
    card.querySelector('input[type="radio"]').checked = true;
}
// Init
selectPlan('mensuel');

// Password strength
const pwInput = document.getElementById('reg_password');
if (pwInput) {
    pwInput.addEventListener('input', function() {
        const val = this.value;
        let score = 0;
        if (val.length >= 8) score++;
        if (val.length >= 12) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;

        const fill = document.getElementById('strength-fill');
        const text = document.getElementById('strength-text');
        const pct = Math.min(100, score * 20);
        const colors = ['#dc2626','#f97316','#eab308','#22c55e','#059669'];
        const labels = ['Faible','Faible','Moyen','Fort','Tres fort'];
        const idx = Math.min(score, 4);

        fill.style.width = pct + '%';
        fill.style.background = val ? colors[idx] : '#e2e8f0';
        text.textContent = val ? labels[idx] : '';
        text.style.color = val ? colors[idx] : '#94a3b8';
    });
}
</script>

</body>
</html>
