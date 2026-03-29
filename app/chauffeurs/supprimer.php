<?php
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireTenantAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . 'app/chauffeurs/liste.php');
requireCSRF();
$database = new Database();
$db = $database->getConnection();
$tenantId = getTenantId();
$id = (int)($_POST['id'] ?? 0);

$stmt = $db->prepare("SELECT id, nom FROM chauffeurs WHERE id = ? AND tenant_id = ?");
$stmt->execute([$id, $tenantId]);
$ch = $stmt->fetch();
if (!$ch) { setFlash(FLASH_ERROR, 'Chauffeur introuvable.'); redirect(BASE_URL . 'app/chauffeurs/liste.php'); }

$stmt = $db->prepare("SELECT COUNT(*) FROM locations WHERE chauffeur_id = ? AND statut = 'en_cours' AND tenant_id = ?");
$stmt->execute([$id, $tenantId]);
if ($stmt->fetchColumn() > 0) {
    setFlash(FLASH_ERROR, 'Ce chauffeur a une mission en cours, impossible de supprimer.');
    redirect(BASE_URL . 'app/chauffeurs/liste.php');
}
// Détacher des locations passées
$db->prepare("UPDATE locations SET chauffeur_id = NULL WHERE chauffeur_id = ? AND tenant_id = ?")->execute([$id, $tenantId]);
$db->prepare("DELETE FROM chauffeurs WHERE id = ? AND tenant_id = ?")->execute([$id, $tenantId]);
setFlash(FLASH_SUCCESS, 'Chauffeur supprimé.');
redirect(BASE_URL . 'app/chauffeurs/liste.php');
