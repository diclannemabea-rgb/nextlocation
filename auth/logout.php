<?php
/**
 * FlotteCar - Déconnexion
 * Détruit la session et redirige vers la page de connexion
 */

define('BASE_PATH', dirname(__DIR__));
session_start();

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';

// Déconnecter l'utilisateur
logoutUser();

// logoutUser() redirige déjà, mais par sécurité:
redirect(BASE_URL . 'auth/login.php');
