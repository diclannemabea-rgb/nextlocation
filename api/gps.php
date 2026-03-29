<?php
/**
 * API GPS - Endpoints JSON pour Leaflet et les pages GPS
 */
define('BASE_PATH', dirname(__DIR__));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
require_once BASE_PATH . '/includes/TraccarAPI.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !getTenantId()) {
    jsonResponse(['error' => 'Non autorisé'], 401);
}

$db = (new Database())->getConnection();
$tenantId = getTenantId();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// -------------------------------------------------------
// GET: positions - Toutes les positions GPS du tenant
// -------------------------------------------------------
if ($action === 'positions' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("SELECT id, nom, immatriculation, traccar_device_id, statut FROM vehicules WHERE tenant_id = ? AND traccar_device_id IS NOT NULL");
    $stmt->execute([$tenantId]);
    $vehicules = $stmt->fetchAll();

    if (empty($vehicules)) { jsonResponse([]); }

    $traccar = new TraccarAPI();
    if (!$traccar->isAvailable()) {
        // Traccar non disponible: retourner véhicules sans position
        $result = array_map(fn($v) => [
            'id' => $v['id'], 'nom' => $v['nom'], 'immatriculation' => $v['immatriculation'],
            'lat' => null, 'lng' => null, 'vitesse' => 0, 'moteur' => false,
            'horodatage' => null, 'traccar_online' => false
        ], $vehicules);
        jsonResponse($result);
    }

    $result = [];
    foreach ($vehicules as $v) {
        $pos = $traccar->getPosition((int)$v['traccar_device_id']);
        $result[] = [
            'id'            => $v['id'],
            'nom'           => $v['nom'],
            'immatriculation'=> $v['immatriculation'],
            'lat'           => $pos['latitude'] ?? null,
            'lng'           => $pos['longitude'] ?? null,
            'vitesse'       => round($pos['speed'] ?? 0),
            'moteur'        => !empty($pos['attributes']['ignition']),
            'horodatage'    => $pos['deviceTime'] ?? null,
            'traccar_online' => $pos !== null,
        ];
    }
    jsonResponse($result);
}

// -------------------------------------------------------
// GET: position - Position d'un véhicule spécifique
// -------------------------------------------------------
if ($action === 'position' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $vehiculeId = (int)($_GET['vehicule_id'] ?? 0);
    $stmt = $db->prepare("SELECT traccar_device_id FROM vehicules WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$vehiculeId, $tenantId]);
    $veh = $stmt->fetch();
    if (!$veh || !$veh['traccar_device_id']) jsonResponse(['error' => 'GPS non configuré'], 404);

    $traccar = new TraccarAPI();
    if (!$traccar->isAvailable()) jsonResponse(['error' => 'Traccar non disponible'], 503);

    $pos = $traccar->getPosition((int)$veh['traccar_device_id']);
    if (!$pos) jsonResponse(['error' => 'Position non disponible'], 404);

    jsonResponse([
        'lat'      => $pos['latitude'],
        'lng'      => $pos['longitude'],
        'vitesse'  => round($pos['speed'] ?? 0),
        'moteur'   => !empty($pos['attributes']['ignition']),
        'horodatage'=> $pos['deviceTime'] ?? null,
        'adresse'  => $traccar->reverseGeocode((float)$pos['latitude'], (float)$pos['longitude']),
    ]);
}

// -------------------------------------------------------
// GET: trips - Historique des trajets
// -------------------------------------------------------
if ($action === 'trips' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $vehiculeId = (int)($_GET['vehicule_id'] ?? 0);
    $from = $_GET['from'] ?? date('Y-m-d');
    $to   = $_GET['to']   ?? date('Y-m-d');

    $stmt = $db->prepare("SELECT traccar_device_id FROM vehicules WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$vehiculeId, $tenantId]);
    $veh = $stmt->fetch();
    if (!$veh || !$veh['traccar_device_id']) jsonResponse(['error' => 'GPS non configuré'], 404);

    $traccar = new TraccarAPI();
    if (!$traccar->isAvailable()) jsonResponse(['error' => 'Traccar non disponible'], 503);

    $fromISO = date('c', strtotime($from . ' 00:00:00'));
    $toISO   = date('c', strtotime($to   . ' 23:59:59'));
    $trips = $traccar->getTrips((int)$veh['traccar_device_id'], $fromISO, $toISO);
    jsonResponse($trips ?: []);
}

// -------------------------------------------------------
// POST: stop_engine - Couper moteur
// -------------------------------------------------------
if ($action === 'stop_engine' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isPlanPro()) jsonResponse(['success' => false, 'error' => 'Plan Pro ou Enterprise requis pour cette fonctionnalité.'], 403);
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) jsonResponse(['error' => 'CSRF invalide'], 403);

    $vehiculeId = (int)($_POST['vehicule_id'] ?? 0);
    $stmt = $db->prepare("SELECT traccar_device_id, nom FROM vehicules WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$vehiculeId, $tenantId]);
    $veh = $stmt->fetch();
    if (!$veh || !$veh['traccar_device_id']) jsonResponse(['success' => false, 'error' => 'Véhicule introuvable'], 404);

    $traccar = new TraccarAPI();
    if (!$traccar->isAvailable()) jsonResponse(['success' => false, 'error' => 'Traccar non disponible'], 503);

    $ok = $traccar->stopEngine((int)$veh['traccar_device_id']);
    // Logger la commande
    $db->prepare("INSERT INTO logs_gps_commandes (tenant_id, vehicule_id, user_id, commande, resultat) VALUES (?,?,?,'engineStop',?)")
       ->execute([$tenantId, $vehiculeId, getUserId(), $ok ? 'succès' : 'échec']);

    logActivite($db, 'stop_engine', 'gps', 'Coupure moteur véhicule : ' . $veh['nom']);
    if ($ok) {
        pushNotif($db, $tenantId, 'gps', '🔴 Moteur coupé — ' . $veh['nom'], 'Commande de coupure moteur envoyée avec succès', BASE_URL . 'app/gps/carte.php');
    }
    jsonResponse(['success' => $ok]);
}

// -------------------------------------------------------
// POST: start_engine - Démarrer moteur
// -------------------------------------------------------
if ($action === 'start_engine' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isPlanPro()) jsonResponse(['success' => false, 'error' => 'Plan Pro ou Enterprise requis.'], 403);
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) jsonResponse(['error' => 'CSRF invalide'], 403);

    $vehiculeId = (int)($_POST['vehicule_id'] ?? 0);
    $stmt = $db->prepare("SELECT traccar_device_id, nom FROM vehicules WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$vehiculeId, $tenantId]);
    $veh = $stmt->fetch();
    if (!$veh || !$veh['traccar_device_id']) jsonResponse(['success' => false, 'error' => 'Véhicule introuvable'], 404);

    $traccar = new TraccarAPI();
    if (!$traccar->isAvailable()) jsonResponse(['success' => false, 'error' => 'Traccar non disponible'], 503);

    $ok = $traccar->resumeEngine((int)$veh['traccar_device_id']);
    $db->prepare("INSERT INTO logs_gps_commandes (tenant_id, vehicule_id, user_id, commande, resultat) VALUES (?,?,?,'engineResume',?)")
       ->execute([$tenantId, $vehiculeId, getUserId(), $ok ? 'succès' : 'échec']);
    logActivite($db, 'start_engine', 'gps', 'Démarrage moteur véhicule ID: ' . $vehiculeId);
    if ($ok) {
        pushNotif($db, $tenantId, 'gps', '🟢 Moteur démarré — ' . $veh['nom'], 'Commande de démarrage moteur envoyée avec succès', BASE_URL . 'app/gps/carte.php');
    }
    jsonResponse(['success' => $ok]);
}

// -------------------------------------------------------
// GET: events - Événements récents d'un véhicule
// -------------------------------------------------------
if ($action === 'events' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $vehiculeId = (int)($_GET['vehicule_id'] ?? 0);
    $from = $_GET['from'] ?? date('Y-m-d');
    $to   = $_GET['to']   ?? date('Y-m-d');

    $stmt = $db->prepare("SELECT traccar_device_id FROM vehicules WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$vehiculeId, $tenantId]);
    $veh = $stmt->fetch();
    if (!$veh || !$veh['traccar_device_id']) jsonResponse(['error' => 'GPS non configuré'], 404);

    $traccar = new TraccarAPI();
    if (!$traccar->isAvailable()) jsonResponse(['error' => 'Traccar non disponible'], 503);

    $fromISO = date('c', strtotime($from . ' 00:00:00'));
    $toISO   = date('c', strtotime($to   . ' 23:59:59'));
    $events  = $traccar->getEvents((int)$veh['traccar_device_id'], $fromISO, $toISO);
    jsonResponse($events ?: []);
}

// -------------------------------------------------------
// GET: route_history - Tracé de route sur période
// -------------------------------------------------------
if ($action === 'route_history' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $vehiculeId = (int)($_GET['vehicule_id'] ?? 0);
    $from = $_GET['from'] ?? date('Y-m-d') . ' 00:00:00';
    $to   = $_GET['to']   ?? date('Y-m-d') . ' 23:59:59';

    $stmt = $db->prepare("SELECT traccar_device_id FROM vehicules WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$vehiculeId, $tenantId]);
    $veh = $stmt->fetch();
    if (!$veh || !$veh['traccar_device_id']) jsonResponse(['error' => 'GPS non configuré'], 404);

    $traccar = new TraccarAPI();
    if (!$traccar->isAvailable()) jsonResponse(['error' => 'Traccar non disponible'], 503);

    $fromISO = date('c', strtotime($from));
    $toISO   = date('c', strtotime($to));
    $positions = $traccar->getPositions([(int)$veh['traccar_device_id']]);
    // If detailed history not available, return current positions
    $result = [];
    if ($positions) {
        foreach ($positions as $p) {
            if (isset($p['latitude'], $p['longitude'])) {
                $result[] = ['lat' => $p['latitude'], 'lng' => $p['longitude'], 'speed' => round($p['speed'] ?? 0), 'time' => $p['deviceTime'] ?? null];
            }
        }
    }
    jsonResponse($result);
}

// -------------------------------------------------------
// POST: send_command - Commande personnalisée
// -------------------------------------------------------
if ($action === 'send_command' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isPlanPro()) jsonResponse(['success' => false, 'error' => 'Plan Pro ou Enterprise requis.'], 403);
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) jsonResponse(['error' => 'CSRF invalide'], 403);

    $vehiculeId = (int)($_POST['vehicule_id'] ?? 0);
    $cmd        = trim($_POST['cmd_type'] ?? '');
    $allowed    = ['engineStop','engineResume','requestPhoto','silentMessage','positionSingle','positionPeriodic','positionStop','alarmArm','alarmDisarm'];
    if (!in_array($cmd, $allowed)) jsonResponse(['success' => false, 'error' => 'Commande non autorisée'], 400);

    $stmt = $db->prepare("SELECT traccar_device_id, nom FROM vehicules WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$vehiculeId, $tenantId]);
    $veh = $stmt->fetch();
    if (!$veh || !$veh['traccar_device_id']) jsonResponse(['success' => false, 'error' => 'Véhicule introuvable'], 404);

    $traccar = new TraccarAPI();
    if (!$traccar->isAvailable()) jsonResponse(['success' => false, 'error' => 'Traccar non disponible'], 503);

    $payload = ['deviceId' => (int)$veh['traccar_device_id'], 'type' => $cmd];
    $result  = $traccar->sendCommand($payload);
    $ok      = $result !== false;

    $db->prepare("INSERT INTO logs_gps_commandes (tenant_id, vehicule_id, user_id, commande, resultat) VALUES (?,?,?,?,?)")
       ->execute([$tenantId, $vehiculeId, getUserId(), $cmd, $ok ? 'succès' : 'échec']);
    logActivite($db, 'send_command', 'gps', "Commande $cmd → véhicule {$veh['nom']}");
    jsonResponse(['success' => $ok]);
}

jsonResponse(['error' => 'Action inconnue'], 400);
