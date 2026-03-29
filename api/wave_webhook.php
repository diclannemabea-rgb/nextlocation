<?php
/**
 * FlotteCar — Wave Business Webhook
 * URL à configurer dans Wave Business Dashboard:
 * https://ton-domaine.com/traccargps/api/wave_webhook.php
 */
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/models/WaveApiModel.php';

// Ne pas exiger d'auth session — c'est un webhook externe
header('Content-Type: application/json');

// Lire le payload brut
$rawPayload = file_get_contents('php://input');
$signature  = $_SERVER['HTTP_WAVE_SIGNATURE'] ?? '';
$data       = json_decode($rawPayload, true);

if (!$data || !isset($data['type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

// Log brut pour debug
error_log("[WaveWebhook] Event: " . ($data['type'] ?? '?') . " | " . substr($rawPayload, 0, 200));

// On ne traite que les événements de paiement complété
if ($data['type'] !== 'checkout.session.completed') {
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'type' => $data['type']]);
    exit;
}

$session    = $data['data'] ?? [];
$montant    = (float)($session['amount'] ?? 0);
$payeurTel  = $session['client_phone_number'] ?? ($session['sender_phone_number'] ?? '');
$waveRef    = $session['id'] ?? ($session['client_reference'] ?? '');
$currency   = $session['currency'] ?? 'XOF';

if (!$montant || !$payeurTel) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing amount or phone']);
    exit;
}

// Trouver le tenant correspondant via le numéro marchand
// Le client_reference peut contenir "tenant_{tenantId}_taxi_{taximetreId}"
$tenantId    = null;
$taximetreId = null;

// Parse client_reference si format structuré
if (preg_match('/^tenant_(\d+)_taxi_(\d+)$/', $waveRef, $m)) {
    $tenantId    = (int)$m[1];
    $taximetreId = (int)$m[2];
}

$db = (new Database())->getConnection();

// Si pas de référence structurée, chercher par numéro de téléphone dans tous les tenants actifs
if (!$tenantId) {
    // Chercher dans tous les tenants ayant Wave configuré
    $r = $db->prepare("SELECT DISTINCT tenant_id FROM parametres WHERE cle='wave_active' AND valeur='1'");
    $r->execute();
    $tenants = $r->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tenants as $tid) {
        $wave = new WaveApiModel($db, (int)$tid);

        // Vérifier la signature
        if ($signature && !$wave->verifyWebhookSignature($rawPayload, $signature)) {
            continue; // Mauvais secret pour ce tenant
        }

        $taximantre = $wave->findTaximetreByPhone($payeurTel);
        if ($taximantre) {
            $tenantId    = (int)$tid;
            $taximetreId = (int)$taximantre['id'];
            break;
        }
    }
}

if (!$tenantId || !$taximetreId) {
    http_response_code(200);
    echo json_encode(['status' => 'not_matched', 'phone' => $payeurTel]);
    exit;
}

$wave = new WaveApiModel($db, $tenantId);

// Vérifier la signature si on l'a
if ($signature && !$wave->verifyWebhookSignature($rawPayload, $signature)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// Vérifier doublon (idempotence) — si cette ref Wave a déjà été traitée
$r = $db->prepare("SELECT id FROM logs_activites WHERE tenant_id=? AND action='paiement_wave' AND description LIKE ?");
$r->execute([$tenantId, '%' . $waveRef . '%']);
if ($r->fetch()) {
    http_response_code(200);
    echo json_encode(['status' => 'already_processed', 'wave_ref' => $waveRef]);
    exit;
}

// Imputer le paiement
$result = $wave->imputerPaiement($taximetreId, $montant, 'wave', $waveRef);

// Logger
$wave->logTransaction($taximetreId, $waveRef, $montant, $result);

http_response_code(200);
echo json_encode([
    'status'        => 'success',
    'taximetre_id'  => $taximetreId,
    'montant'       => $montant,
    'jours_payes'   => $result['jours_payes'],
    'solde_restant' => $result['solde_restant'],
]);
exit;
