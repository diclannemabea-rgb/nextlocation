<?php
// =============================================================================
// FlotteCar — Configuration Web Push (VAPID)
// =============================================================================

// Clés VAPID (générées une seule fois — ne jamais changer en production)
define('PUSH_VAPID_PUBLIC',  'BPjWDw1_T0PMC2GkJ_oR_sgj7NIT3SZKg7aiQwoDp867K2BfKsljCsLJKUhnJMV4GC4wnqoVwpUdpRLOR7Kb9Dk');
define('PUSH_VAPID_PRIVATE', 'uywvAok_gV4hUB19Wbj_OOzcRUwTaKMqYkcq-2l1IL8');
define('PUSH_VAPID_SUBJECT', 'mailto:contact@tikoodelivery.ci');

/**
 * Envoie une notification push à tous les appareils d'un utilisateur
 *
 * @param PDO    $db
 * @param int    $userId   — ID dans `users` (0 = tous les admins du tenant)
 * @param int    $tenantId — 0 = super admin uniquement
 * @param string $title
 * @param string $body
 * @param string $url      — URL relative (ex: '/app/dashboard.php')
 * @param string $tag      — tag pour regrouper les notifs
 * @param string $type     — alerte|paiement_taxi|location|gps|maintenance|info
 */
function sendPushNotification(PDO $db, int $userId, int $tenantId, string $title, string $body, string $url = '/', string $tag = 'flottecar', string $type = 'info'): void
{
    $vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!file_exists($vendorAutoload)) return;

    try {
        // Corriger OpenSSL sur WAMP Windows
        if (PHP_OS_FAMILY === 'Windows' && !getenv('OPENSSL_CONF')) {
            $cnf = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
            if (file_exists($cnf)) putenv('OPENSSL_CONF=' . realpath($cnf));
        }

        require_once $vendorAutoload;

        // Récupérer les subscriptions
        if ($userId > 0) {
            $stmt = $db->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ? AND tenant_id = ?");
            $stmt->execute([$userId, $tenantId]);
        } elseif ($tenantId > 0) {
            // Tous les utilisateurs du tenant
            $stmt = $db->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE tenant_id = ?");
            $stmt->execute([$tenantId]);
        } else {
            // Super admins uniquement
            $stmt = $db->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE tenant_id = 0");
            $stmt->execute();
        }
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($subs)) return;

        // Compter les notifs non lues pour le badge
        try {
            if ($userId > 0) {
                $bStmt = $db->prepare("SELECT COUNT(*) FROM notifs_push WHERE (user_id = ? OR user_id IS NULL) AND tenant_id = ? AND lu = 0");
                $bStmt->execute([$userId, $tenantId]);
            } else {
                $bStmt = $db->query("SELECT COUNT(*) FROM notifs_push WHERE lu = 0");
            }
            $badgeCount = (int)$bStmt->fetchColumn() + 1;
        } catch (\Throwable $e) {
            $badgeCount = 1;
        }

        $baseUrl = defined('BASE_URL') ? BASE_URL : '/';
        $fullUrl = strpos($url, 'http') === 0 ? $url : rtrim($baseUrl, '/') . '/' . ltrim($url, '/');

        $payload = json_encode([
            'title'  => $title,
            'body'   => $body,
            'url'    => $fullUrl,
            'tag'    => $tag,
            'type'   => $type,
            'badge'  => $badgeCount,
        ]);

        $webPush = new \Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject'    => PUSH_VAPID_SUBJECT,
                'publicKey'  => PUSH_VAPID_PUBLIC,
                'privateKey' => PUSH_VAPID_PRIVATE,
            ],
        ]);

        foreach ($subs as $sub) {
            $subscription = \Minishlink\WebPush\Subscription::create([
                'endpoint' => $sub['endpoint'],
                'keys'     => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth']],
            ]);
            $webPush->queueNotification($subscription, $payload);
        }

        // Envoyer et nettoyer les abonnements expirés
        foreach ($webPush->flush() as $report) {
            if (!$report->isSuccess()) {
                $code = $report->getResponse() ? $report->getResponse()->getStatusCode() : 0;
                if ($code === 410) {
                    $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")
                       ->execute([$report->getEndpoint()]);
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('[FlotteCar Push] ' . $e->getMessage());
    }
}

/**
 * Insérer une notif en base ET envoyer le push
 */
function createAndPush(PDO $db, int $tenantId, ?int $userId, string $type, string $titre, string $corps, string $url = '/'): void
{
    try {
        $stmt = $db->prepare("INSERT INTO notifs_push (tenant_id, user_id, type, titre, corps, url, lu, envoye, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, 0, NOW())");
        $stmt->execute([$tenantId, $userId, $type, $titre, $corps, $url]);
    } catch (\Throwable $e) {
        error_log('[FlotteCar createAndPush] DB: ' . $e->getMessage());
    }

    // Envoyer push en temps réel
    sendPushNotification($db, $userId ?? 0, $tenantId, $titre, $corps, $url, 'flottecar-' . $type, $type);
}
