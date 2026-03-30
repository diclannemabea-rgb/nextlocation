<?php
/**
 * Constantes FlotteCar SaaS
 */

// Chemins
define('UPLOAD_PATH',     BASE_PATH . '/uploads/');
// Détection automatique du protocole (http en local, https en production)
if (!defined('BASE_URL')) {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('BASE_URL', $proto . '://' . $host . '/');
}

// Application
define('APP_NAME',        'FlotteCar');
define('APP_VERSION',     '2.0.0');
define('APP_DESCRIPTION', 'Plateforme SaaS de gestion de flotte & tracking GPS');
define('DEVISE',          'FCFA');

// Traccar GPS
define('TRACCAR_URL',     'http://localhost:8082/api');
define('TRACCAR_USER',    'mabeawilfried@gmail.com');
define('TRACCAR_PASS',    '07459376ab@');

// Session
define('SESSION_TIMEOUT', 2592000); // 30 jours
define('SESSION_NAME',    'FLOTTECAR_SESSION');

// Pagination
define('ITEMS_PER_PAGE',  20);

// Upload
define('MAX_FILE_SIZE',   10485760); // 10MB
define('ALLOWED_IMAGES',  ['jpg','jpeg','png','gif','webp']);
define('ALLOWED_DOCS',    ['pdf','doc','docx','xls','xlsx']);

// Chemins upload
define('UPLOAD_CONTRATS',   UPLOAD_PATH . 'contrats/');
define('UPLOAD_LOGOS',      UPLOAD_PATH . 'logos/');
define('UPLOAD_DOCUMENTS',  UPLOAD_PATH . 'documents/');

// Rôles
define('ROLE_SUPER_ADMIN',  'super_admin');
define('ROLE_TENANT_ADMIN', 'tenant_admin');
define('ROLE_TENANT_USER',  'tenant_user');

// Plans
define('PLAN_STARTER',    'starter');
define('PLAN_PRO',        'pro');
define('PLAN_ENTERPRISE', 'enterprise');

// Prix plans (FCFA) — Migration vers plan unique en cours
// define('PRIX_STARTER',    5000);   // OBSOLÈTE
// define('PRIX_PRO',        15000);  // OBSOLÈTE
// define('PRIX_ENTERPRISE', 35000);  // OBSOLÈTE
define('PRIX_MENSUEL',   20000);
define('PRIX_ANNUEL',    150000);

// Limites véhicules par plan
define('LIMIT_STARTER',   3);
define('LIMIT_PRO',       10);
define('LIMIT_ENTERPRISE', 9999);

// Statuts
define('STATUT_ACTIF',   'actif');
define('STATUT_INACTIF', 'inactif');

// Statuts véhicules
define('VEH_DISPONIBLE',   'disponible');
define('VEH_LOUE',         'loue');
define('VEH_MAINTENANCE',  'maintenance');
define('VEH_INDISPONIBLE', 'indisponible');

// Statuts location
define('LOC_EN_COURS',  'en_cours');
define('LOC_TERMINEE',  'terminee');
define('LOC_ANNULEE',   'annulee');

// Paiements
define('PAIE_NON_PAYE', 'non_paye');
define('PAIE_AVANCE',   'avance');
define('PAIE_SOLDE',    'solde');

// Flash
define('FLASH_SUCCESS', 'success');
define('FLASH_ERROR',   'error');
define('FLASH_WARNING', 'warning');
define('FLASH_INFO',    'info');

// Clé CRON
define('CRON_KEY', 'flottecar_maint_2026');

// Formats date
define('DATE_FORMAT',      'd/m/Y');
define('DATETIME_FORMAT',  'd/m/Y H:i');
define('DATE_SQL',         'Y-m-d');

// Timezone
date_default_timezone_set('Africa/Abidjan');

// Erreurs (désactiver en prod)


ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
