<?php
define('BASE_PATH', dirname(__DIR__));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';

if (!isLoggedIn() || !getTenantId()) jsonResponse(['error' => 'Non autorisé'], 401);

$db = (new Database())->getConnection();
$tenantId = getTenantId();
$action = $_GET['action'] ?? 'liste';

if ($action === 'liste') {
    $statut = $_GET['statut'] ?? '';
    $where = "WHERE tenant_id = ?";
    $params = [$tenantId];
    if ($statut) { $where .= " AND statut = ?"; $params[] = $statut; }
    $stmt = $db->prepare("SELECT id, nom, immatriculation, marque, modele, statut, prix_location_jour, traccar_device_id FROM vehicules $where ORDER BY nom ASC");
    $stmt->execute($params);
    jsonResponse($stmt->fetchAll());
}

if ($action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM vehicules WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id, $tenantId]);
    $v = $stmt->fetch();
    if (!$v) jsonResponse(['error' => 'Introuvable'], 404);
    jsonResponse($v);
}

jsonResponse(['error' => 'Action inconnue'], 400);
