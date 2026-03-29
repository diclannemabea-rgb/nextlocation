<?php
/**
 * Diagnostic Traccar v2 — session cookie + email
 */
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/constants.php';

header('Content-Type: text/plain; charset=utf-8');

$url  = TRACCAR_URL;
$user = TRACCAR_USER;  // doit être l'EMAIL complet Traccar
$pass = TRACCAR_PASS;
$cookieFile = sys_get_temp_dir() . '/traccar_diag_cookie.txt';
@unlink($cookieFile); // reset cookie

echo "=== DIAGNOSTIC TRACCAR v2 ===\n";
echo "URL  : $url\n";
echo "User : $user\n\n";

// ── Test 1: POST /session (login) ───────────────────────────────────────────
echo "--- TEST 1: POST /session (login avec email+password) ---\n";
$ch = curl_init($url . '/session');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query(['email' => $user, 'password' => $pass]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_COOKIEJAR      => $cookieFile,
    CURLOPT_COOKIEFILE     => $cookieFile,
    CURLOPT_HEADER         => true,  // afficher les headers pour voir le cookie
]);
$r    = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);
echo "HTTP: $code\n";
if ($err) echo "ERREUR cURL: $err\n";

// Séparer header et body
$headerSize = strpos($r, "\r\n\r\n");
$headers    = substr($r, 0, $headerSize);
$body       = substr($r, $headerSize + 4);

// Afficher le cookie Set-Cookie si présent
if (preg_match('/Set-Cookie:\s*(.+)/i', $headers, $m)) {
    echo "Cookie reçu: " . trim($m[1]) . "\n";
} else {
    echo "⚠ Aucun cookie Set-Cookie dans la réponse\n";
}
echo "Body: " . substr($body, 0, 300) . "\n";

$session = json_decode($body, true);
if (!empty($session['id'])) {
    echo "\n✅ LOGIN OK — User ID: " . $session['id'] . " | Email: " . ($session['email'] ?? '?') . "\n\n";
} else {
    echo "\n❌ LOGIN ÉCHOUÉ\n";
    echo "=> Vérifiez que TRACCAR_USER est bien votre EMAIL Traccar (ex: admin@flottecar.ci)\n";
    echo "=> Email actuel configuré: $user\n\n";

    // Essai avec email "admin" au cas où
    echo "--- TEST 1b: Essai avec email 'admin@traccar.org' ---\n";
    $cookieFile2 = sys_get_temp_dir() . '/traccar_diag_cookie2.txt';
    $ch = curl_init($url . '/session');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['email' => 'admin', 'password' => $pass]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_COOKIEJAR => $cookieFile2, CURLOPT_COOKIEFILE => $cookieFile2,
    ]);
    $r2 = curl_exec($ch); $code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    echo "HTTP avec 'admin': $code2 — " . ($code2 === 200 ? '✅ FONCTIONNE' : '❌ Échoué') . "\n\n";
}

// ── Test 2: GET /devices avec le cookie ─────────────────────────────────────
echo "--- TEST 2: GET /devices (avec cookie de session) ---\n";
if ($code === 200) {
    $ch = curl_init($url . '/devices');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_COOKIEJAR      => $cookieFile,
    ]);
    $r3 = curl_exec($ch); $code3 = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    echo "HTTP: $code3\n";
    $devices = json_decode($r3, true);
    if (is_array($devices)) {
        echo "Devices trouvés: " . count($devices) . "\n";
        foreach ($devices as $d) {
            echo "  - ID:" . $d['id'] . " | uniqueId:" . $d['uniqueId'] . " | nom:" . $d['name'] . "\n";
        }
        echo "\n✅ GET /devices FONCTIONNE\n";
    } else {
        echo "Réponse: " . substr($r3, 0, 300) . "\n❌ Erreur\n";
    }
} else {
    echo "Skipped (login échoué)\n";
}

// ── Test 3: POST /devices (créer device test) ────────────────────────────────
echo "\n--- TEST 3: POST /devices (créer device test) ---\n";
if ($code === 200) {
    $ch = curl_init($url . '/devices');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => json_encode(['name' => 'TEST_DIAG', 'uniqueId' => '999000111222333']),
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_COOKIEFILE     => $cookieFile, CURLOPT_COOKIEJAR => $cookieFile,
    ]);
    $r4 = curl_exec($ch); $code4 = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    echo "HTTP: $code4\n";
    $created = json_decode($r4, true);
    if (!empty($created['id'])) {
        echo "✅ Device créé ID: " . $created['id'] . "\n";
        // Supprimer
        $ch = curl_init($url . '/devices/' . $created['id']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5,
            CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_CUSTOMREQUEST=>'DELETE',
            CURLOPT_COOKIEFILE=>$cookieFile, CURLOPT_COOKIEJAR=>$cookieFile]);
        curl_exec($ch); curl_close($ch);
        echo "=> Device test supprimé.\n\n✅✅ TOUT FONCTIONNE — Synchro va marcher !\n";
    } else {
        echo "Réponse: " . substr($r4, 0, 400) . "\n❌ Création échouée\n";
    }
}
