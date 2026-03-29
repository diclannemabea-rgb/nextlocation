<?php
/**
 * FlotteCar — Abonnement expiré
 * Affiche le dashboard en lecture seule avec une bannière bloquante.
 */
define('BASE_PATH', dirname(__DIR__));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';

if (!isLoggedIn()) redirect(BASE_URL . 'auth/login.php');
if (isSuperAdmin() || empty($_SESSION['abonnement_expire'])) redirect(BASE_URL . 'app/dashboard.php');

$tenantNom  = getTenantNom();
$userPrenom = $_SESSION['user_prenom'] ?? '';
$userNom    = $_SESSION['user_nom']    ?? '';
$userEmail  = $_SESSION['user_email']  ?? '';
$tenantId   = getTenantId();
$telephone  = '';

try {
    $db    = (new Database())->getConnection();
    $row   = $db->prepare("SELECT telephone FROM tenants WHERE id=?");
    $row->execute([$tenantId]);
    $telephone = $row->fetchColumn() ?: '';
} catch (Exception $e) {}

define('ADMIN_WA', '2250142518590');

// Message WhatsApp pré-rempli
$msgMensuel = "Bonjour FlotteCar ! Mon abonnement a expiré et je souhaite le renouveler.\n\n"
    . "🏢 *Entreprise :* $tenantNom\n"
    . "👤 *Contact :* $userPrenom $userNom\n"
    . "📧 *Email :* $userEmail\n"
    . ($telephone ? "📞 *Tél :* $telephone\n" : "")
    . "🆔 *ID :* #$tenantId\n\n"
    . "💳 *Renouvellement choisi :* Mensuel — *20 000 FCFA/mois*\n\nMerci 🙏";

$msgAnnuel = "Bonjour FlotteCar ! Mon abonnement a expiré et je souhaite le renouveler.\n\n"
    . "🏢 *Entreprise :* $tenantNom\n"
    . "👤 *Contact :* $userPrenom $userNom\n"
    . "📧 *Email :* $userEmail\n"
    . ($telephone ? "📞 *Tél :* $telephone\n" : "")
    . "🆔 *ID :* #$tenantId\n\n"
    . "💳 *Renouvellement choisi :* Annuel — *150 000 FCFA/an* (économie 90 000 FCFA)\n\nMerci 🙏";

$waUrlMensuel = 'https://wa.me/' . ADMIN_WA . '?text=' . rawurlencode($msgMensuel);
$waUrlAnnuel  = 'https://wa.me/' . ADMIN_WA . '?text=' . rawurlencode($msgAnnuel);

$pageTitle  = 'Abonnement expiré';
$activePage = 'dashboard';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
/* ── Overlay bloquant ── */
.expire-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.72);
    backdrop-filter: blur(4px);
    z-index: 9000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.expire-modal {
    background: #fff;
    border-radius: 20px;
    padding: 40px 36px;
    max-width: 560px;
    width: 100%;
    text-align: center;
    box-shadow: 0 24px 80px rgba(0,0,0,.35);
    animation: popIn .25s cubic-bezier(.34,1.56,.64,1);
    position: relative;
}

@keyframes popIn {
    from { transform: scale(.85); opacity: 0; }
    to   { transform: scale(1);   opacity: 1; }
}

.exp-icon {
    width: 72px; height: 72px; border-radius: 20px;
    background: linear-gradient(135deg, #ef4444, #f87171);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.8rem; margin: 0 auto 20px;
    box-shadow: 0 8px 24px rgba(239,68,68,.3);
}

.exp-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: #fee2e2; border: 1px solid #fca5a5;
    color: #dc2626; font-size: .72rem; font-weight: 700;
    padding: 4px 12px; border-radius: 99px; margin-bottom: 14px;
}

.exp-title {
    font-size: 1.4rem; font-weight: 900; color: #1e293b;
    margin-bottom: 8px;
}

.exp-sub {
    color: #64748b; font-size: .88rem; line-height: 1.6;
    margin-bottom: 28px;
}

/* Pricing 2 cards */
.exp-pricing {
    display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
    margin-bottom: 20px;
}
@media(max-width:480px) { .exp-pricing { grid-template-columns: 1fr; } }

.exp-plan {
    border-radius: 14px; overflow: hidden;
    border: 2px solid #e2e8f0;
    transition: border-color .15s, transform .15s;
    text-decoration: none;
    display: block;
}
.exp-plan:hover { transform: translateY(-3px); }
.exp-plan.mensuel { border-color: #0d9488; }
.exp-plan.annuel  { border-color: #7c3aed; position: relative; }

.exp-plan-best {
    position: absolute; top: -1px; right: 12px;
    background: linear-gradient(90deg, #7c3aed, #a855f7);
    color: #fff; font-size: .62rem; font-weight: 800;
    padding: 2px 10px; border-radius: 0 0 8px 8px;
    text-transform: uppercase; letter-spacing: .04em;
}

.exp-plan-header {
    padding: 16px 16px 12px;
    text-align: center;
}
.exp-plan.mensuel .exp-plan-header { background: linear-gradient(135deg,#eff6ff,#dbeafe); }
.exp-plan.annuel  .exp-plan-header { background: linear-gradient(135deg,#f5f3ff,#ede9fe); }

.exp-plan-label { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 6px; }
.exp-plan.mensuel .exp-plan-label { color: #0d9488; }
.exp-plan.annuel  .exp-plan-label { color: #7c3aed; }

.exp-plan-price { font-size: 1.6rem; font-weight: 900; color: #1e293b; line-height: 1; }
.exp-plan-price span { font-size: .82rem; color: #64748b; font-weight: 500; }
.exp-plan-period { font-size: .72rem; color: #94a3b8; margin-top: 3px; }

.exp-plan-savings {
    font-size: .68rem; font-weight: 800; color: #16a34a;
    margin-top: 5px;
}

.exp-plan-cta {
    padding: 11px; font-size: .82rem; font-weight: 800;
    display: flex; align-items: center; justify-content: center; gap: 6px;
    color: #fff;
}
.exp-plan.mensuel .exp-plan-cta { background: #0d9488; }
.exp-plan.annuel  .exp-plan-cta { background: #7c3aed; }

.exp-logout {
    display: block; color: #94a3b8; font-size: .78rem;
    font-weight: 600; text-decoration: none; margin-top: 8px;
    transition: color .15s;
}
.exp-logout:hover { color: #64748b; }

/* Freeze le fond (dashboard inaccessible) */
.main-content-freeze {
    pointer-events: none;
    user-select: none;
    filter: blur(2px) grayscale(.5);
}
</style>

<!-- Overlay bloquant -->
<div class="expire-overlay">
    <div class="expire-modal">
       

        <div class="exp-badge"><i class="fas fa-lock"></i> Accès suspendu</div>

        <h1 class="exp-title">Votre abonnement a expiré</h1>
        <p class="exp-sub">
            Pour continuer à utiliser FlotteCar, renouvelez votre abonnement.<br>
            Cliquez sur le plan souhaité, envoyez le message WhatsApp<br>
            et nous réactivons votre accès dans l'heure.
        </p>

        <!-- 2 plans -->
        <div class="exp-pricing">

            <!-- Mensuel -->
            <a href="<?= $waUrlMensuel ?>" target="_blank" class="exp-plan mensuel">
                <div class="exp-plan-header">
                    <div class="exp-plan-label">📅 Mensuel</div>
                    <div class="exp-plan-price">20 000 <span>FCFA</span></div>
                    <div class="exp-plan-period">par mois · sans engagement</div>
                </div>
                <div class="exp-plan-cta">
                    <i class="fab fa-whatsapp"></i> Choisir
                </div>
            </a>

            <!-- Annuel -->
            <a href="<?= $waUrlAnnuel ?>" target="_blank" class="exp-plan annuel">
                <div class="exp-plan-best">Meilleur deal</div>
                <div class="exp-plan-header">
                    <div class="exp-plan-label">🎯 Annuel</div>
                    <div class="exp-plan-price">150 000 <span>FCFA</span></div>
                    <div class="exp-plan-period">par an · paiement unique</div>
                    <div class="exp-plan-savings">🎉 Économie de 90 000 FCFA</div>
                </div>
                <div class="exp-plan-cta">
                    <i class="fab fa-whatsapp"></i> Choisir
                </div>
            </a>

        </div>

        <a href="<?= BASE_URL ?>auth/logout.php" class="exp-logout">
            <i class="fas fa-sign-out-alt"></i> Se déconnecter
        </a>
    </div>
</div>

<!-- Dashboard en arrière-plan (flou, non cliquable) -->
<div class="main-content-freeze">
    <div class="page-header">
        <div>
            <h1 class="page-title"><i class="fas fa-gauge-high"></i> Tableau de bord</h1>
            <p class="page-subtitle"><?= sanitize($tenantNom) ?></p>
        </div>
    </div>
    <div class="stats-grid">
        <?php for ($i = 0; $i < 4; $i++): ?>
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#e2e8f0"></div>
            <div class="stat-card-body">
                <div class="stat-card-value" style="color:#cbd5e1">—</div>
                <div class="stat-card-label" style="color:#e2e8f0">Accès suspendu</div>
            </div>
        </div>
        <?php endfor ?>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
