<?php
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireTenantAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(BASE_URL . 'app/clients/liste.php'); }
requireCSRF();
$database = new Database();
$db = $database->getConnection();
$tenantId = getTenantId();
$id = (int)($_POST['id'] ?? 0);

$stmt = $db->prepare("SELECT id, nom FROM clients WHERE id = ? AND tenant_id = ?");
$stmt->execute([$id, $tenantId]);
$client = $stmt->fetch();
if (!$client) { setFlash(FLASH_ERROR, 'Client introuvable.'); redirect(BASE_URL . 'app/clients/liste.php'); }

// Vérifier pas de location en cours
$stmt = $db->prepare("SELECT COUNT(*) FROM locations WHERE client_id = ? AND statut = 'en_cours' AND tenant_id = ?");
$stmt->execute([$id, $tenantId]);
if ($stmt->fetchColumn() > 0) {
    setFlash(FLASH_ERROR, 'Impossible de supprimer : ce client a une location en cours.');
    redirect(BASE_URL . 'app/clients/liste.php');
}

$db->prepare("DELETE FROM clients WHERE id = ? AND tenant_id = ?")->execute([$id, $tenantId]);
logActivite($db, 'supprimer_client', 'clients', 'Client supprimé : ' . $client['nom']);
setFlash(FLASH_SUCCESS, 'Client supprimé.');
redirect(BASE_URL . 'app/clients/liste.php');
