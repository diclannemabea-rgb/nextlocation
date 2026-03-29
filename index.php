<?php
/**
 * FlotteCar - Point d'entrée principal
 * Redirige vers le dashboard ou la page de connexion selon l'état de la session
 */

// Définir le chemin de base
define('BASE_PATH', __DIR__);

// Démarrer la session
session_start();

// Inclure les fichiers de configuration dans l'ordre correct
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';

// Vérifier si l'utilisateur est connecté
if (isLoggedIn()) {
    // Super admin → dashboard admin
    if (isSuperAdmin()) {
        redirect(BASE_URL . 'admin/dashboard.php');
    }
    // Vérifier si l'abonnement est actif
    if (!empty($_SESSION['abonnement_expire'])) {
        redirect(BASE_URL . 'auth/abonnement_expire.php');
    }
    // Rediriger vers le dashboard tenant
    redirect(BASE_URL . 'app/dashboard.php');
} else {
    // Rediriger vers la page de connexion
    redirect(BASE_URL . 'auth/login.php');
}
