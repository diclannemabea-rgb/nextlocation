<?php
/**
 * FlotteCar - Supprimer un véhicule
 * POST uniquement, avec vérification CSRF et intégrité des données
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireTenantAuth();

// Méthode POST uniquement
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . 'app/vehicules/liste.php');
}

requireCSRF();

$database = new Database();
$db       = $database->getConnection();
$tenantId = getTenantId();

$vehiculeId = (int)($_POST['id'] ?? 0);
if (!$vehiculeId) {
    setFlash(FLASH_ERROR, 'Identifiant de véhicule invalide.');
    redirect(BASE_URL . 'app/vehicules/liste.php');
}

// Vérifier que le véhicule appartient au tenant
$stmtV = $db->prepare("SELECT * FROM vehicules WHERE id = ? AND tenant_id = ?");
$stmtV->execute([$vehiculeId, $tenantId]);
$vehicule = $stmtV->fetch();

if (!$vehicule) {
    setFlash(FLASH_ERROR, 'Véhicule introuvable ou accès refusé.');
    redirect(BASE_URL . 'app/vehicules/liste.php');
}

// Vérifier qu'il n'y a pas de location en cours liée à ce véhicule
$stmtLoc = $db->prepare("
    SELECT COUNT(*) FROM locations
    WHERE vehicule_id = ? AND tenant_id = ? AND statut = 'en_cours'
");
$stmtLoc->execute([$vehiculeId, $tenantId]);
if ((int)$stmtLoc->fetchColumn() > 0) {
    setFlash(FLASH_ERROR, "Impossible de supprimer le véhicule « {$vehicule['nom']} » : une location est en cours.");
    redirect(BASE_URL . 'app/vehicules/liste.php');
}

// Supprimer la photo si elle existe
if (!empty($vehicule['photo']) && file_exists(UPLOAD_LOGOS . $vehicule['photo'])) {
    @unlink(UPLOAD_LOGOS . $vehicule['photo']);
}

// Supprimer le véhicule
$stmtDel = $db->prepare("DELETE FROM vehicules WHERE id = ? AND tenant_id = ?");
$stmtDel->execute([$vehiculeId, $tenantId]);

logActivite($db, 'delete', 'vehicules', "Suppression véhicule #{$vehiculeId} - {$vehicule['nom']} ({$vehicule['immatriculation']})");
setFlash(FLASH_SUCCESS, "Véhicule « {$vehicule['nom']} » supprimé avec succès.");
redirect(BASE_URL . 'app/vehicules/liste.php');
