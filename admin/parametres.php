<?php
/**
 * FlotteCar — Paramètres plateforme (Super Admin)
 */
define('BASE_PATH', dirname(__DIR__));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireSuperAdmin();

$db = (new Database())->getConnection();

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $fields = [
            'app_nom'           => trim($_POST['app_nom']           ?? 'FlotteCar'),
            'telephone_support' => trim($_POST['telephone_support']  ?? ''),
            'email_support'     => trim($_POST['email_support']      ?? ''),
            'site_web'          => trim($_POST['site_web']           ?? ''),
            'adresse'           => trim($_POST['adresse']            ?? ''),
            'whatsapp_admin'    => trim($_POST['whatsapp_admin']     ?? ''),
        ];
        foreach ($fields as $cle => $valeur) {
            $db->prepare("INSERT INTO parametres (cle,valeur) VALUES (?,?) ON DUPLICATE KEY UPDATE valeur=?")->execute([$cle,$valeur,$valeur]);
        }
        setFlash(FLASH_SUCCESS, 'Paramètres enregistrés.');
        redirect(BASE_URL . 'admin/parametres.php');
    }
}

// ── CHARGEMENT ────────────────────────────────────────────────────────────────
$cfg = [];
try {
    foreach ($db->query("SELECT cle,valeur FROM parametres")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cfg[$r['cle']] = $r['valeur'];
    }
} catch(\Throwable $e) {}

$defaults = [
    'app_nom'           => 'FlotteCar',
    'telephone_support' => '',
    'email_support'     => 'admin@flottecar.ci',
    'site_web'          => '',
    'adresse'           => 'Abidjan, Côte d\'Ivoire',
    'whatsapp_admin'    => '2250142518590',
];
foreach ($defaults as $k => $v) { if (!isset($cfg[$k])) $cfg[$k] = $v; }

// Infos système
$mysqlVer = 'N/A';
try { $mysqlVer = $db->query("SELECT VERSION()")->fetchColumn(); } catch(\Throwable $e){}

// Stats globales rapides
$nbTenants  = (int)$db->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
$nbActifs   = (int)$db->query("SELECT COUNT(*) FROM tenants WHERE actif=1")->fetchColumn();
$nbUsers    = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$nbVehicules= (int)$db->query("SELECT COUNT(*) FROM vehicules")->fetchColumn();
$totalRevenu= (float)$db->query("SELECT COALESCE(SUM(montant),0) FROM mouvements_abo WHERE type='paiement'")->fetchColumn();

$pageTitle  = 'Paramètres';
$activePage = 'admin_params';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
.section { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:22px; margin-bottom:18px; }
.section h3 { font-size:.9rem; font-weight:800; color:#0f172a; margin:0 0 16px; padding-bottom:10px; border-bottom:1px solid #f1f5f9; }

.sysrow { display:flex; justify-content:space-between; align-items:center; padding:10px 14px; background:#f8fafc; border-radius:8px; margin-bottom:6px; }
.sysrow:last-child { margin-bottom:0; }
.sysrow .sk { font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; font-weight:700; }
.sysrow .sv { font-size:.85rem; font-weight:700; color:#0f172a; font-family:monospace; }

.krow4 { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-bottom:20px; }
@media(max-width:900px){ .krow4{grid-template-columns:repeat(3,1fr)} }
@media(max-width:500px){ .krow4{grid-template-columns:1fr 1fr} }
.kc { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 14px; text-align:center; border-top:3px solid var(--c,#e2e8f0); }
.kc .v { font-size:1.4rem; font-weight:900; color:var(--c,#0f172a); line-height:1; }
.kc .l { font-size:.63rem; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; font-weight:700; margin-top:5px; }

.forfait-banner { background:linear-gradient(135deg,#0f172a,#0f172a); border-radius:14px; padding:22px 28px; margin-bottom:18px; display:flex; gap:16px; align-items:center; justify-content:center; flex-wrap:wrap; }
.fb-card { background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.12); border-radius:12px; padding:18px 28px; text-align:center; }
.fb-card .fl { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; margin-bottom:6px; }
.fb-card .fp { font-size:1.5rem; font-weight:900; color:#fff; line-height:1; }
.fb-card .fd { font-size:.72rem; color:rgba(255,255,255,.55); margin-top:3px; }
.fb-card.mensuel .fl { color:#60a5fa; }
.fb-card.annuel  .fl { color:#a78bfa; }
.fb-sep { color:rgba(255,255,255,.25); font-size:1.5rem; }

@media(max-width:768px) {
    .forfait-banner { flex-direction:column; padding:16px; }
    .fb-sep { display:none; }
    .section > div[style*="grid-template-columns:1fr 1fr"] { grid-template-columns:1fr !important; }
    .sysrow { flex-direction:column; gap:4px; text-align:center; }
}
</style>

<?= renderFlashes() ?>

<!-- STATS RAPIDES -->
<div class="krow4">
    <div class="kc" style="--c:#0d9488"><div class="v"><?= $nbTenants ?></div><div class="l">Entreprises</div></div>
    <div class="kc" style="--c:#16a34a"><div class="v"><?= $nbActifs ?></div><div class="l">Actives</div></div>
    <div class="kc" style="--c:#0891b2"><div class="v"><?= $nbUsers ?></div><div class="l">Utilisateurs</div></div>
    <div class="kc" style="--c:#f59e0b"><div class="v"><?= $nbVehicules ?></div><div class="l">Véhicules</div></div>
    <div class="kc" style="--c:#7c3aed"><div class="v" style="font-size:1rem"><?= number_format($totalRevenu,0,',',' ') ?></div><div class="l">Revenus (F)</div></div>
</div>

<!-- FORFAITS -->
<div class="forfait-banner">
    <div class="fb-card mensuel">
        <div class="fl">Mensuel</div>
        <div class="fp">20 000 <span style="font-size:.9rem;font-weight:500;color:rgba(255,255,255,.6)">FCFA</span></div>
        <div class="fd">30 jours · sans engagement</div>
    </div>
    <div class="fb-sep">·</div>
    <div class="fb-card annuel">
        <div class="fl">Annuel</div>
        <div class="fp">150 000 <span style="font-size:.9rem;font-weight:500;color:rgba(255,255,255,.6)">FCFA</span></div>
        <div class="fd">365 jours · économie 90 000 FCFA</div>
    </div>
</div>

<!-- PARAMÈTRES PLATEFORME -->
<form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <div class="section">
        <h3>Paramètres plateforme</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
            <div class="form-group">
                <label class="form-label">Nom de l'application</label>
                <input type="text" name="app_nom" class="form-control" value="<?= sanitize($cfg['app_nom']) ?>" placeholder="FlotteCar">
            </div>
            <div class="form-group">
                <label class="form-label">WhatsApp admin</label>
                <input type="text" name="whatsapp_admin" class="form-control" value="<?= sanitize($cfg['whatsapp_admin']) ?>" placeholder="2250142518590">
            </div>
            <div class="form-group">
                <label class="form-label">Téléphone support</label>
                <input type="text" name="telephone_support" class="form-control" value="<?= sanitize($cfg['telephone_support']) ?>" placeholder="+225 XX XX XX XX XX">
            </div>
            <div class="form-group">
                <label class="form-label">Email support</label>
                <input type="email" name="email_support" class="form-control" value="<?= sanitize($cfg['email_support']) ?>" placeholder="admin@flottecar.ci">
            </div>
            <div class="form-group">
                <label class="form-label">Site web</label>
                <input type="text" name="site_web" class="form-control" value="<?= sanitize($cfg['site_web']) ?>" placeholder="https://flottecar.ci">
            </div>
            <div class="form-group">
                <label class="form-label">Adresse</label>
                <input type="text" name="adresse" class="form-control" value="<?= sanitize($cfg['adresse']) ?>" placeholder="Abidjan, Côte d'Ivoire">
            </div>
        </div>
        <div style="margin-top:4px">
            <button type="submit" class="btn btn-primary">Enregistrer</button>
        </div>
    </div>
</form>

<!-- INFOS SYSTÈME -->
<div class="section">
    <h3>Informations système</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <div class="sysrow"><span class="sk">Version PHP</span><span class="sv"><?= PHP_VERSION ?></span></div>
        <div class="sysrow"><span class="sk">Version MySQL</span><span class="sv"><?= sanitize($mysqlVer) ?></span></div>
        <div class="sysrow"><span class="sk">Version app</span><span class="sv">v<?= defined('APP_VERSION') ? sanitize(APP_VERSION) : '2.1' ?></span></div>
        <div class="sysrow"><span class="sk">Timezone</span><span class="sv"><?= date_default_timezone_get() ?></span></div>
        <div class="sysrow"><span class="sk">Serveur</span><span class="sv" style="font-size:.75rem"><?= sanitize($_SERVER['SERVER_SOFTWARE'] ?? 'N/A') ?></span></div>
        <div class="sysrow"><span class="sk">BASE_URL</span><span class="sv" style="font-size:.72rem"><?= sanitize(BASE_URL) ?></span></div>
    </div>
</div>

<!-- ACTIONS RAPIDES -->
<div class="section">
    <h3>Actions rapides</h3>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>admin/dashboard.php" class="btn btn-primary btn-sm">Dashboard</a>
        <a href="<?= BASE_URL ?>admin/tenants.php" class="btn btn-secondary btn-sm">Entreprises</a>
        <a href="<?= BASE_URL ?>admin/abonnements.php" class="btn btn-secondary btn-sm">Abonnements</a>
        <a href="<?= BASE_URL ?>admin/utilisateurs.php" class="btn btn-secondary btn-sm">Utilisateurs</a>
        <a href="<?= BASE_URL ?>admin/logs.php" class="btn btn-secondary btn-sm">Logs</a>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
