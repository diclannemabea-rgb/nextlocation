<?php
/**
 * FlotteCar — Sauvegarder / supprimer un abonnement WebPush
 * POST JSON { action: 'subscribe'|'unsubscribe', subscription: {...} }
 */
ob_start(); // Capturer tout output parasite (warnings, notices) avant le JSON
define('BASE_PATH', dirname(__DIR__));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';

ob_clean(); // Vider les éventuels warnings PHP avant d'écrire le JSON
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Non authentifié']);
    exit;
}

$db       = (new Database())->getConnection();
$userId   = getUserId();
$tenantId = isSuperAdmin() ? 0 : (int)getTenantId();

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

$action = $data['action'] ?? '';
$sub    = $data['subscription'] ?? null;

if ($action === 'subscribe' && $sub) {
    $endpoint = $sub['endpoint'] ?? '';
    $p256dh   = $sub['keys']['p256dh'] ?? '';
    $auth     = $sub['keys']['auth']   ?? '';

    if (!$endpoint || !$p256dh || !$auth) {
        echo json_encode(['ok' => false, 'error' => 'Données incomplètes']);
        exit;
    }

    // Vérifier si l'endpoint existe déjà
    $check = $db->prepare("SELECT id FROM push_subscriptions WHERE endpoint = ?");
    $check->execute([$endpoint]);
    $existing = $check->fetchColumn();

    if ($existing) {
        $stmt = $db->prepare("UPDATE push_subscriptions SET user_id=?, tenant_id=?, p256dh=?, auth=? WHERE endpoint=?");
        $stmt->execute([$userId, $tenantId, $p256dh, $auth, $endpoint]);
    } else {
        $stmt = $db->prepare("INSERT INTO push_subscriptions (user_id, tenant_id, endpoint, p256dh, auth, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$userId, $tenantId, $endpoint, $p256dh, $auth]);
    }
    echo json_encode(['ok' => true]);

} elseif ($action === 'unsubscribe' && $sub) {
    $endpoint = $sub['endpoint'] ?? '';
    if ($endpoint) {
        $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ?")
           ->execute([$endpoint, $userId]);
    }
    echo json_encode(['ok' => true]);

} else {
    echo json_encode(['ok' => false, 'error' => 'Action inconnue']);
}
