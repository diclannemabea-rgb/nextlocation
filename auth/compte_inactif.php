<?php
/**
 * FlotteCar — Compte en attente d'activation
 */
define('BASE_PATH', dirname(__DIR__));
session_start();
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';

if (!isLoggedIn()) redirect(BASE_URL . 'auth/login.php');
if (!isset($_SESSION['tenant_actif']) || (int)$_SESSION['tenant_actif'] === 1) {
    redirect(BASE_URL . 'app/dashboard.php');
}

$nomEntreprise = $_SESSION['tenant_nom']  ?? '';
$email         = $_SESSION['user_email']  ?? '';
$prenom        = $_SESSION['user_prenom'] ?? '';
$nom           = $_SESSION['user_nom']    ?? '';
$tenantId      = $_SESSION['tenant_id']   ?? '';

define('ADMIN_WA', '2250142518590');
$loginUrl = BASE_URL . 'auth/login.php';

$base = "Bonjour FlotteCar ! Je veux activer mon compte.\n\n"
      . "🏢 *Entreprise :* " . $nomEntreprise . "\n"
      . "👤 *Responsable :* " . $prenom . " " . $nom . "\n"
      . "📧 *Email :* " . $email . "\n"
      . "🔗 *Lien :* " . $loginUrl . "\n"
      . "🆔 *ID :* #" . $tenantId . "\n\n";

$waUrlMensuel = 'https://wa.me/' . ADMIN_WA . '?text=' . rawurlencode($base . "💳 *Plan choisi :* Mensuel — *20 000 FCFA* · Merci 🙏");
$waUrlAnnuel  = 'https://wa.me/' . ADMIN_WA . '?text=' . rawurlencode($base . "💳 *Plan choisi :* Annuel — *150 000 FCFA* · Merci 🙏");
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activation requise | FlotteCar</title>
    <meta name="theme-color" content="#0d9488">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/img/icon-192.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: 'Inter', system-ui, sans-serif;
        background: #fff;
        min-height: 100vh;
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        padding: 40px 16px;
        color: #0f172a;
    }

    .logo { display:flex; align-items:center; gap:10px; margin-bottom:36px; }
    .logo-icon {
        width:42px; height:42px; border-radius:12px;
        background:linear-gradient(135deg,#0d9488,#14b8a6);
        display:flex; align-items:center; justify-content:center;
        color:#fff; font-size:1.1rem;
    }
    .logo-name { font-size:1.25rem; font-weight:800; color:#0f172a; }

    .hero { text-align:center; margin-bottom:36px; }
    .hero-tag {
        display:inline-flex; align-items:center; gap:6px;
        background:#fff7ed; border:1px solid #fed7aa;
        color:#c2410c; font-size:.75rem; font-weight:700;
        padding:4px 12px; border-radius:99px; margin-bottom:14px;
    }
    .hero h1 { font-size:1.9rem; font-weight:900; color:#0f172a; line-height:1.15; margin-bottom:10px; }
    .hero p { color:#64748b; font-size:.92rem; line-height:1.65; }

    .pricing-grid {
        display:grid; grid-template-columns:1fr 1fr; gap:20px;
        width:100%; max-width:640px; margin-bottom:24px;
    }
    @media(max-width:520px) { .pricing-grid { grid-template-columns:1fr; } }

    .card {
        border-radius:20px; border:2px solid #e2e8f0;
        overflow:hidden; display:flex; flex-direction:column;
        position:relative; text-decoration:none;
        transition:transform .2s, box-shadow .2s;
    }
    .card:hover { transform:translateY(-4px); box-shadow:0 20px 48px rgba(0,0,0,.10); }
    .card.best { border-color:#7c3aed; }

    .badge-best {
        position:absolute; top:14px; right:14px;
        background:linear-gradient(135deg,#7c3aed,#a855f7);
        color:#fff; font-size:.65rem; font-weight:800;
        padding:3px 10px; border-radius:99px;
        text-transform:uppercase; letter-spacing:.05em;
    }

    .card-body { padding:26px 22px 18px; flex:1; }
    .plan-name { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; margin-bottom:10px; }
    .price { font-size:2rem; font-weight:900; color:#0f172a; line-height:1; }
    .price-currency { font-size:.95rem; font-weight:600; color:#64748b; }
    .price-period { font-size:.78rem; color:#94a3b8; margin-top:4px; }
    .savings {
        display:inline-block; margin-top:10px;
        background:#f0fdf4; border:1px solid #bbf7d0;
        color:#16a34a; font-size:.72rem; font-weight:700;
        padding:3px 10px; border-radius:99px;
    }

    .card-cta {
        display:block; text-align:center; padding:14px 20px;
        font-weight:800; font-size:.88rem; text-decoration:none;
        color:#fff; transition:opacity .15s;
    }
    .card-cta:hover { opacity:.88; }
    .card-cta i { margin-right:6px; }
    .card-cta.blue   { background:linear-gradient(135deg,#0d9488,#14b8a6); }
    .card-cta.purple { background:linear-gradient(135deg,#7c3aed,#a855f7); }

    .hint { text-align:center; color:#94a3b8; font-size:.78rem; }
    .hint a { color:#64748b; }
    </style>
</head>
<body>

<div class="logo">
    <div class="logo-icon"><i class="fas fa-car-side"></i></div>
    <span class="logo-name">FlotteCar</span>
</div>

<div class="hero">
    <div class="hero-tag"><i class="fas fa-clock"></i> En attente d'activation</div>
    <h1>Choisissez votre forfait</h1>
    <p>Bonjour <strong><?= htmlspecialchars($prenom) ?></strong> — votre compte <strong><?= htmlspecialchars($nomEntreprise) ?></strong> est créé.<br>
    Contactez-nous sur WhatsApp pour régler et activer votre accès.</p>
</div>

<div class="pricing-grid">

    <a href="<?= $waUrlMensuel ?>" target="_blank" class="card">
        <div class="card-body">
            <div class="plan-name">Mensuel</div>
            <div class="price">20 000 <span class="price-currency">FCFA</span></div>
            <div class="price-period">par mois · sans engagement</div>
        </div>
        <span class="card-cta blue"><i class="fab fa-whatsapp"></i> Choisir ce plan</span>
    </a>

    <a href="<?= $waUrlAnnuel ?>" target="_blank" class="card best">
        <div class="badge-best">Meilleure offre</div>
        <div class="card-body">
            <div class="plan-name">Annuel</div>
            <div class="price">150 000 <span class="price-currency">FCFA</span></div>
            <div class="price-period">par an · paiement unique</div>
            <div class="savings">Économie de 90 000 FCFA</div>
        </div>
        <span class="card-cta purple"><i class="fab fa-whatsapp"></i> Choisir ce plan</span>
    </a>

</div>

<p class="hint">
    <a href="<?= BASE_URL ?>auth/logout.php">Se déconnecter</a>
</p>

</body>
</html>
