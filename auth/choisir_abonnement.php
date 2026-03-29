<?php
/**
 * FlotteCar — Choix d'abonnement après inscription
 */
define('BASE_PATH', dirname(__DIR__));
session_start();
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';

if (!isLoggedIn() || !isset($_SESSION['nouveau_compte'])) {
    redirect(BASE_URL . 'auth/login.php');
}

$nc = $_SESSION['nouveau_compte'];

define('ADMIN_WA', '2250142518590');

$loginUrl = BASE_URL . 'auth/login.php';

$msg = "Bonjour FlotteCar ! Je souhaite activer mon compte.\n\n"
     . "🏢 *Entreprise :* " . $nc['nom_entreprise'] . "\n"
     . "👤 *Responsable :* " . $nc['prenom'] . " " . $nc['nom'] . "\n"
     . "📧 *Email :* " . $nc['email'] . "\n"
     . "🔑 *Mot de passe :* " . ($nc['password'] ?? '(défini à l\'inscription)') . "\n"
     . "🔗 *Lien de connexion :* " . $loginUrl . "\n"
     . "🆔 *ID compte :* #" . $nc['tenant_id'] . "\n\n";

$msgMensuel = $msg . "💳 *Plan choisi :* Mensuel — *20 000 FCFA* · Merci 🙏";
$msgAnnuel  = $msg . "💳 *Plan choisi :* Annuel — *150 000 FCFA* · Merci 🙏";

$waUrlMensuel = 'https://wa.me/' . ADMIN_WA . '?text=' . rawurlencode($msgMensuel);
$waUrlAnnuel  = 'https://wa.me/' . ADMIN_WA . '?text=' . rawurlencode($msgAnnuel);

unset($_SESSION['nouveau_compte']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choisir votre forfait | FlotteCar</title>
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
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 40px 16px;
        color: #0f172a;
    }

    .logo {
        display: flex; align-items: center; gap: 10px;
        margin-bottom: 40px;
    }
    .logo-icon {
        width: 42px; height: 42px; border-radius: 12px;
        background: linear-gradient(135deg, #0d9488, #14b8a6);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.1rem;
    }
    .logo-name { font-size: 1.25rem; font-weight: 800; color: #0f172a; }

    .hero {
        text-align: center;
        margin-bottom: 40px;
    }
    .hero-tag {
        display: inline-flex; align-items: center; gap: 6px;
        background: #f0fdf4; border: 1px solid #bbf7d0;
        color: #16a34a; font-size: .75rem; font-weight: 700;
        padding: 4px 12px; border-radius: 99px; margin-bottom: 16px;
    }
    .hero h1 {
        font-size: 2rem; font-weight: 900; color: #0f172a;
        line-height: 1.15; margin-bottom: 10px;
    }
    .hero p {
        color: #64748b; font-size: .95rem; line-height: 1.6;
    }

    .pricing-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        width: 100%;
        max-width: 680px;
        margin-bottom: 28px;
    }
    @media(max-width: 560px) { .pricing-grid { grid-template-columns: 1fr; } }

    .card {
        border-radius: 20px;
        border: 2px solid #e2e8f0;
        overflow: hidden;
        transition: transform .2s, box-shadow .2s;
        text-decoration: none;
        display: flex;
        flex-direction: column;
        position: relative;
    }
    .card:hover {
        transform: translateY(-4px);
        box-shadow: 0 20px 48px rgba(0,0,0,.10);
    }
    .card.best {
        border-color: #7c3aed;
    }

    .badge-best {
        position: absolute; top: 14px; right: 14px;
        background: linear-gradient(135deg, #7c3aed, #a855f7);
        color: #fff; font-size: .65rem; font-weight: 800;
        padding: 3px 10px; border-radius: 99px;
        text-transform: uppercase; letter-spacing: .05em;
    }

    .card-body {
        padding: 28px 24px 20px;
        flex: 1;
    }
    .plan-name {
        font-size: .72rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: .08em; color: #94a3b8; margin-bottom: 12px;
    }
    .price {
        font-size: 2.2rem; font-weight: 900; color: #0f172a; line-height: 1;
    }
    .price-currency { font-size: 1rem; font-weight: 600; color: #64748b; }
    .price-period { font-size: .8rem; color: #94a3b8; margin-top: 4px; }
    .savings {
        display: inline-block; margin-top: 10px;
        background: #f0fdf4; border: 1px solid #bbf7d0;
        color: #16a34a; font-size: .72rem; font-weight: 700;
        padding: 3px 10px; border-radius: 99px;
    }

    .card-cta {
        display: block; text-align: center;
        padding: 14px 20px;
        font-weight: 800; font-size: .88rem;
        text-decoration: none; transition: opacity .15s;
        color: #fff;
    }
    .card-cta:hover { opacity: .88; }
    .card-cta.blue { background: linear-gradient(135deg, #0d9488, #14b8a6); }
    .card-cta.purple { background: linear-gradient(135deg, #7c3aed, #a855f7); }
    .card-cta i { margin-right: 6px; }

    .hint {
        text-align: center; color: #94a3b8; font-size: .78rem; line-height: 1.6;
        max-width: 480px;
    }
    .hint a { color: #64748b; }

    /* Overlay confirmation */
    #sent-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(255,255,255,.95);
        z-index: 9999; align-items: center; justify-content: center;
        flex-direction: column; text-align: center; padding: 24px;
    }
    .sent-card {
        background: #fff; border-radius: 20px; padding: 48px 36px;
        max-width: 400px; border: 1px solid #e2e8f0;
        box-shadow: 0 24px 64px rgba(0,0,0,.08);
    }
    .sent-icon { font-size: 3rem; margin-bottom: 16px; color: #16a34a; }
    .sent-card h2 { font-size: 1.3rem; font-weight: 900; color: #0f172a; margin-bottom: 10px; }
    .sent-card p { color: #64748b; font-size: .88rem; line-height: 1.65; margin-bottom: 28px; }
    .sent-card a {
        display: inline-block;
        background: linear-gradient(135deg, #0d9488, #14b8a6);
        color: #fff; padding: 13px 32px; border-radius: 12px;
        text-decoration: none; font-weight: 700; font-size: .9rem;
    }
    </style>
</head>
<body>


<div class="hero">
    <div class="hero-tag"><i class="fas fa-check-circle"></i> Compte créé avec succès</div>
    <h1>Choisissez votre forfait</h1>
    <p>Cliquez sur un plan pour nous contacter sur WhatsApp<br>et activer votre compte en moins d'1 heure.</p>
</div>

<div class="pricing-grid">

    <!-- Mensuel -->
    <a href="<?= $waUrlMensuel ?>" target="_blank" class="card" onclick="showSent()">
        <div class="card-body">
            <div class="plan-name">Mensuel</div>
            <div class="price">20 000 <span class="price-currency">FCFA</span></div>
            <div class="price-period">par mois · sans engagement</div>
        </div>
        <span class="card-cta blue"><i class="fab fa-whatsapp"></i> Choisir ce plan</span>
    </a>

    <!-- Annuel -->
    <a href="<?= $waUrlAnnuel ?>" target="_blank" class="card best" onclick="showSent()">
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
    Déjà payé ? <a href="<?= BASE_URL ?>auth/login.php">Retour à la connexion</a>
</p>

<!-- Overlay après clic -->
<div id="sent-overlay">
    <div class="sent-card">
        <div class="sent-icon"><i class="fas fa-circle-check"></i></div>
        <h2>Demande envoyée !</h2>
        <p>Votre message WhatsApp est prêt. Envoyez-le et nous activons votre compte rapidement.</p>
        <a href="<?= BASE_URL ?>auth/login.php">Aller à la connexion</a>
    </div>
</div>

<script>
function showSent() {
    setTimeout(() => {
        const ov = document.getElementById('sent-overlay');
        ov.style.display = 'flex';
    }, 1200);
}
</script>
</body>
</html>
