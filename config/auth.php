<?php
if (!defined('BASE_URL') && defined('BASE_PATH')) {
    require_once BASE_PATH . '/config/constants.php';
}

function requireAuth(): void {
    if (!isLoggedIn()) {
        setFlash(FLASH_WARNING, 'Veuillez vous connecter pour accéder à cette page.');
        redirect(BASE_URL . 'auth/login.php');
    }
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset(); session_destroy();
        redirect(BASE_URL . 'auth/login.php?timeout=1');
    }
    $_SESSION['last_activity'] = time();
}

function requireTenantAuth(): void {
    requireAuth();
    if (isSuperAdmin()) redirect(BASE_URL . 'admin/dashboard.php');
    $tid = getTenantId();
    if (!$tid) redirect(BASE_URL . 'auth/login.php');
    try {
        $db = (new Database())->getConnection();
        $stmt = $db->prepare("SELECT actif, plan FROM tenants WHERE id = ? LIMIT 1");
        $stmt->execute([$tid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { session_unset(); session_destroy(); redirect(BASE_URL . 'auth/login.php?deleted=1'); }
        $_SESSION['tenant_actif'] = (int)$row['actif'];
        $_SESSION['tenant_plan']  = $row['plan'];
    } catch (\Throwable $e) {}
    if ((int)($_SESSION['tenant_actif'] ?? 1) === 0) redirect(BASE_URL . 'auth/compte_inactif.php');
    if (!empty($_SESSION['abonnement_expire'])) redirect(BASE_URL . 'auth/abonnement_expire.php');
}

function requireSuperAdmin(): void {
    requireAuth();
    if (!isSuperAdmin()) { http_response_code(403); die('Accès refusé.'); }
}

function requireTenantAdmin(): void {
    requireTenantAuth();
    if (!isTenantAdmin()) {
        setFlash(FLASH_ERROR, 'Droits insuffisants.');
        redirect(BASE_URL . 'app/dashboard.php');
    }
}

function requireGuest(): void {
    if (isLoggedIn()) {
        if (isSuperAdmin()) redirect(BASE_URL . 'admin/dashboard.php');
        if (isset($_SESSION['tenant_actif']) && (int)$_SESSION['tenant_actif'] === 0) {
            redirect(BASE_URL . 'auth/compte_inactif.php');
        }
        redirect(BASE_URL . 'app/dashboard.php');
    }
}

function loginUser(array $user, ?array $tenant = null): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['user_id']       = $user['id'];
    $_SESSION['user_nom']      = $user['nom'];
    $_SESSION['user_prenom']   = $user['prenom'] ?? '';
    $_SESSION['user_email']    = $user['email'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['last_activity'] = time();
    if ($tenant) {
        $_SESSION['tenant_id']         = $tenant['id'];
        $_SESSION['tenant_nom']        = $tenant['nom_entreprise'];
        $_SESSION['tenant_plan']       = $tenant['plan'];
        $_SESSION['tenant_type_usage'] = $tenant['type_usage'];
        $_SESSION['tenant_actif']      = (int)($tenant['actif'] ?? 1);
        $_SESSION['abonnement_expire'] = false;
    }
}

function logoutUser(): void {
    session_unset(); session_destroy();
    redirect(BASE_URL . 'auth/login.php');
}

function checkCredentials(PDO $db, string $email, string $password): array|false {
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND statut = 'actif'");
    $stmt->execute([trim($email)]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password'])) return false;
    $tenant = null;
    if ($user['tenant_id']) {
        $stmt = $db->prepare("SELECT * FROM tenants WHERE id = ?");
        $stmt->execute([$user['tenant_id']]);
        $tenant = $stmt->fetch();
        if (!$tenant) return false;
    }
    $db->prepare("UPDATE users SET derniere_connexion = NOW() WHERE id = ?")->execute([$user['id']]);
    return ['user' => $user, 'tenant' => $tenant];
}

function checkAbonnement(PDO $db, int $tenantId): bool {
    $stmt = $db->prepare("
        SELECT id FROM abonnements
        WHERE tenant_id = ? AND statut = 'actif' AND date_fin >= CURDATE()
        LIMIT 1
    ");
    $stmt->execute([$tenantId]);
    return (bool)$stmt->fetch();
}