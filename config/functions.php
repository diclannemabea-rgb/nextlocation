<?php
/**
 * Fonctions utilitaires FlotteCar
 */

// -------------------------------------------------------
// SESSION / AUTH
// -------------------------------------------------------

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function isSuperAdmin(): bool {
    return isset($_SESSION['role']) && $_SESSION['role'] === ROLE_SUPER_ADMIN;
}

function isTenantAdmin(): bool {
    return isset($_SESSION['role']) && $_SESSION['role'] === ROLE_TENANT_ADMIN;
}

function getUserId(): ?int {
    return $_SESSION['user_id'] ?? null;
}

function getTenantId(): ?int {
    return $_SESSION['tenant_id'] ?? null;
}

function getUserName(): string {
    return trim(($_SESSION['user_prenom'] ?? '') . ' ' . ($_SESSION['user_nom'] ?? '')) ?: 'Utilisateur';
}

function getTenantNom(): string {
    return $_SESSION['tenant_nom'] ?? 'Mon Entreprise';
}

function getTenantPlan(): string {
    return $_SESSION['tenant_plan'] ?? PLAN_STARTER;
}

function getTenantTypeUsage(): string {
    return $_SESSION['tenant_type_usage'] ?? 'les_deux';
}

function hasLocationModule(): bool {
    $t = getTenantTypeUsage();
    return $t === 'location' || $t === 'les_deux';
}

function hasGpsModule(): bool {
    $t = getTenantTypeUsage();
    return $t === 'controle' || $t === 'les_deux';
}

function isPlanPro(): bool {
    return true; // Plan unique — toutes les fonctionnalités incluses
}

function isPlanEnterprise(): bool {
    return true; // Plan unique — toutes les fonctionnalités incluses
}

// -------------------------------------------------------
// FLASH MESSAGES
// -------------------------------------------------------

function setFlash(string $type, string $msg): void {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function renderFlashes(): string {
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    $html = '';
    foreach ($flashes as $f) {
        $icon = match($f['type']) {
            'success' => 'check-circle',
            'error'   => 'times-circle',
            'warning' => 'exclamation-triangle',
            default   => 'info-circle',
        };
        $html .= '<div class="alert alert-' . htmlspecialchars($f['type']) . '">'
               . '<i class="fas fa-' . $icon . '"></i> '
               . htmlspecialchars($f['msg'])
               . '<button class="alert-close" onclick="this.parentElement.remove()">&times;</button>'
               . '</div>';
    }
    return $html;
}

// -------------------------------------------------------
// FORMATAGE
// -------------------------------------------------------

function formatMoney(float $amount): string {
    return number_format($amount, 0, ',', ' ') . ' ' . DEVISE;
}

function formatDate(?string $date, string $format = DATE_FORMAT): string {
    if (!$date) return '-';
    try { return (new DateTime($date))->format($format); }
    catch (Exception $e) { return $date; }
}

function formatDatetime(?string $dt): string {
    return formatDate($dt, DATETIME_FORMAT);
}

function sanitize(mixed $val): string {
    return htmlspecialchars(trim((string)$val), ENT_QUOTES, 'UTF-8');
}

function cleanNumber(mixed $val): float {
    return (float) str_replace([' ', ','], ['', '.'], (string)$val);
}

function truncate(string $str, int $len = 50): string {
    return mb_strlen($str) > $len ? mb_substr($str, 0, $len) . '…' : $str;
}

function calculateDays(string $debut, string $fin): int {
    try {
        $d1 = new DateTime($debut);
        $d2 = new DateTime($fin);
        return max(1, (int)$d1->diff($d2)->days);
    } catch (Exception $e) { return 1; }
}

// -------------------------------------------------------
// SÉCURITÉ CSRF
// -------------------------------------------------------

function generateCSRF(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRF(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . generateCSRF() . '">';
}

function requireCSRF(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            die('Token CSRF invalide. Rechargez la page.');
        }
    }
}

// -------------------------------------------------------
// UPLOADS
// -------------------------------------------------------

function uploadFile(array $file, string $destDir, array $allowedExt = []): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > MAX_FILE_SIZE) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($allowedExt && !in_array($ext, $allowedExt)) return null;
    $filename = uniqid('', true) . '.' . $ext;
    $dest = rtrim($destDir, '/') . '/' . $filename;
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $dest)) return null;
    return $filename;
}

// -------------------------------------------------------
// REDIRECT
// -------------------------------------------------------

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function jsonResponse(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------
// BADGES UI
// -------------------------------------------------------

function badgeVehicule(string $statut): string {
    return match($statut) {
        'disponible'   => '<span class="badge bg-success">Disponible</span>',
        'loue'         => '<span class="badge bg-warning">Loué</span>',
        'maintenance'  => '<span class="badge bg-info">Maintenance</span>',
        'indisponible' => '<span class="badge bg-danger">Indisponible</span>',
        default        => '<span class="badge bg-secondary">' . sanitize($statut) . '</span>',
    };
}

function badgeLocation(string $statut): string {
    return match($statut) {
        'en_cours' => '<span class="badge bg-primary">En cours</span>',
        'terminee' => '<span class="badge bg-success">Terminée</span>',
        'annulee'  => '<span class="badge bg-danger">Annulée</span>',
        default    => '<span class="badge bg-secondary">' . sanitize($statut) . '</span>',
    };
}

function badgePaiement(string $statut): string {
    return match($statut) {
        'solde'    => '<span class="badge bg-success">Soldé</span>',
        'avance'   => '<span class="badge bg-warning">Avance</span>',
        'non_paye' => '<span class="badge bg-danger">Non payé</span>',
        default    => '<span class="badge bg-secondary">' . sanitize($statut) . '</span>',
    };
}

function badgePlan(string $plan): string {
    return match($plan) {
        'mensuel'    => '<span class="badge bg-primary">Mensuel</span>',
        'annuel'     => '<span class="badge" style="background:#7c3aed;color:#fff">Annuel</span>',
        // Legacy
        'starter'    => '<span class="badge bg-secondary">Starter</span>',
        'pro'        => '<span class="badge bg-primary">Pro</span>',
        'enterprise' => '<span class="badge bg-warning text-dark">Enterprise</span>',
        default      => '<span class="badge bg-secondary">' . sanitize($plan) . '</span>',
    };
}

// -------------------------------------------------------
// LOGS
// -------------------------------------------------------

/**
 * Envoyer une notification push (stockée en DB, récupérée par polling JS)
 * $userId = null → pour tous les users du tenant
 */
function pushNotif(PDO $db, int $tenantId, string $type, string $titre, string $corps = '', string $url = '', ?int $userId = null): void {
    try {
        $db->prepare("INSERT INTO notifs_push (tenant_id, user_id, type, titre, corps, url)
            VALUES (?, ?, ?, ?, ?, ?)")->execute([$tenantId, $userId, $type, $titre, $corps, $url]);
    } catch (Exception $e) { /* silencieux */ }

    // Envoyer le vrai WebPush si disponible
    $pushFile = BASE_PATH . '/config/push.php';
    if (file_exists($pushFile)) {
        if (!function_exists('sendPushNotification')) require_once $pushFile;
        sendPushNotification($db, $userId ?? 0, $tenantId, $titre, $corps, $url ?: '/', 'flottecar-' . $type, $type);
    }
}

function logActivite(PDO $db, string $action, string $module = '', string $description = ''): void {
    try {
        $stmt = $db->prepare("
            INSERT INTO logs_activites (tenant_id, user_id, action, module, description, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([getTenantId(), getUserId(), $action, $module, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) { /* ne pas bloquer */ }
}

// -------------------------------------------------------
// VEHICULES - LIMITE PAR PLAN
// -------------------------------------------------------

function getVehiculeLimit(string $plan): int {
    // Plan unique — véhicules illimités pour tous
    return LIMIT_ENTERPRISE;
}

function canAddVehicule(PDO $db, int $tenantId, string $plan): bool {
    $limit = getVehiculeLimit($plan);
    if ($limit >= 9999) return true;
    $stmt = $db->prepare("SELECT COUNT(*) FROM vehicules WHERE tenant_id = ?");
    $stmt->execute([$tenantId]);
    return (int)$stmt->fetchColumn() < $limit;
}

// -------------------------------------------------------
// PAGINATION
// -------------------------------------------------------

function renderPagination(int $total, int $page, int $perPage, string $baseUrl): string {
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($totalPages <= 1) return '';
    $sep = str_contains($baseUrl, '?') ? '&' : '?';
    $html = '<div class="pagination">';
    $html .= '<span class="pagination-info">' . number_format($total, 0, ',', ' ') . ' résultat(s)</span><div class="pagination-links">';
    if ($page > 1) $html .= '<a href="' . $baseUrl . $sep . 'page=' . ($page - 1) . '" class="page-link">&#8249;</a>';
    for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++) {
        $active = $i === $page ? ' active' : '';
        $html .= '<a href="' . $baseUrl . $sep . 'page=' . $i . '" class="page-link' . $active . '">' . $i . '</a>';
    }
    if ($page < $totalPages) $html .= '<a href="' . $baseUrl . $sep . 'page=' . ($page + 1) . '" class="page-link">&#8250;</a>';
    $html .= '</div></div>';
    return $html;
}
