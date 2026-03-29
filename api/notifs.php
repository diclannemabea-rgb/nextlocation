<?php
/**
 * FlotteCar — API Notifications Push
 * GET  ?action=pending    → notifications non lues (panel + toasts)
 * POST action=mark_read   → marquer comme lu
 * POST action=set_asked   → marquer "permission demandée" en session
 * POST action=delete      → supprimer une notif
 */
define('BASE_PATH', dirname(__DIR__));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }

$db       = (new Database())->getConnection();
$tenantId = getTenantId();
$userId   = getUserId();
$action   = $_GET['action'] ?? $_POST['action'] ?? 'pending';

header('Content-Type: application/json');

// ── GET pending ───────────────────────────────────────────────────────────────
if ($action === 'pending') {
    $notifs = [];

    if (isSuperAdmin()) {
        // Super admin : TOUTES les notifs non lues (tous tenants confondus)
        $s = $db->prepare("SELECT id, type, titre, corps, url, envoye, created_at
            FROM notifs_push WHERE lu=0
            ORDER BY created_at DESC LIMIT 30");
        $s->execute();
        $notifs = $s->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($tenantId) {
        $s = $db->prepare("SELECT id, type, titre, corps, url, envoye, created_at
            FROM notifs_push
            WHERE tenant_id=? AND (user_id IS NULL OR user_id=?) AND lu=0
            ORDER BY created_at DESC LIMIT 30");
        $s->execute([$tenantId, $userId]);
        $notifs = $s->fetchAll(PDO::FETCH_ASSOC);
    }

    // Identifier les nouvelles (envoye=0 → à afficher en toast)
    // et marquer UNIQUEMENT celles-là comme envoye=1
    $newIds = [];
    foreach ($notifs as &$n) {
        $n['is_new'] = ((int)$n['envoye'] === 0);
        if ($n['is_new']) $newIds[] = (int)$n['id'];
        unset($n['envoye']); // ne pas exposer ce champ au client
    }
    unset($n);

    if ($newIds) {
        $in = implode(',', $newIds);
        $db->exec("UPDATE notifs_push SET envoye=1 WHERE id IN($in)");
    }

    echo json_encode(['notifs' => $notifs, 'count' => count($notifs)]);
    exit;
}

// ── POST set_asked — marquer permission demandée en session ────────────────────
if ($action === 'set_asked') {
    $_SESSION['notif_perm_asked'] = true;
    echo json_encode(['ok' => true]);
    exit;
}

// ── POST mark_read ────────────────────────────────────────────────────────────
if ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Support URLSearchParams ET JSON
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        $json = json_decode(file_get_contents('php://input'), true);
        $id   = (int)($json['id'] ?? 0);
    }
    if ($id) {
        if (isSuperAdmin()) {
            $db->prepare("UPDATE notifs_push SET lu=1 WHERE id=?")->execute([$id]);
        } else {
            $db->prepare("UPDATE notifs_push SET lu=1 WHERE id=? AND tenant_id=?")->execute([$id, $tenantId]);
        }
    } else {
        // Tout marquer lu
        if (isSuperAdmin()) {
            $db->prepare("UPDATE notifs_push SET lu=1")->execute(); // tous tenants
        } else {
            $db->prepare("UPDATE notifs_push SET lu=1 WHERE tenant_id=? AND (user_id IS NULL OR user_id=?)")->execute([$tenantId, $userId]);
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── POST delete ───────────────────────────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        if (isSuperAdmin()) {
            $db->prepare("DELETE FROM notifs_push WHERE id=?")->execute([$id]);
        } elseif ($tenantId) {
            $db->prepare("DELETE FROM notifs_push WHERE id=? AND tenant_id=?")->execute([$id, $tenantId]);
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['error' => 'Action inconnue']);
