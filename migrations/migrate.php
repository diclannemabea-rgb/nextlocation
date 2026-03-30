<?php
/**
 * SCRIPT DE MIGRATION - FlotteCar SaaS
 * Usage CLI : php migrate.php
 *
 * ATTENTION : Ce script SUPPRIME toutes les tables existantes
 * et recrée le schéma complet de FlotteCar.
 */

// Couleurs terminal
define('RED',    "\033[0;31m");
define('GREEN',  "\033[0;32m");
define('YELLOW', "\033[0;33m");
define('BLUE',   "\033[0;34m");
define('CYAN',   "\033[0;36m");
define('RESET',  "\033[0m");
define('BOLD',   "\033[1m");

function log_info($msg)    { echo CYAN  . "[INFO]  " . RESET . $msg . "\n"; }
function log_ok($msg)      { echo GREEN . "[OK]    " . RESET . $msg . "\n"; }
function log_warn($msg)    { echo YELLOW. "[WARN]  " . RESET . $msg . "\n"; }
function log_error($msg)   { echo RED   . "[ERROR] " . RESET . $msg . "\n"; }
function log_step($msg)    { echo BOLD  . BLUE . "\n==> " . $msg . RESET . "\n"; }

// -------------------------------------------------------
// CONNEXION
// -------------------------------------------------------
$host   = 'localhost';
$dbname = 'traccargps';
$user   = 'root';
$pass   = '';

log_step("FlotteCar - Migration de base de données");
log_info("Connexion à MySQL...");

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    log_ok("Connexion MySQL établie.");
} catch (PDOException $e) {
    log_error("Impossible de se connecter à MySQL : " . $e->getMessage());
    exit(1);
}

// -------------------------------------------------------
// CRÉER/SÉLECTIONNER LA BASE
// -------------------------------------------------------
log_step("Préparation de la base de données '$dbname'");
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$dbname`");
log_ok("Base de données '$dbname' sélectionnée.");

// -------------------------------------------------------
// SUPPRIMER TOUTES LES TABLES EXISTANTES
// -------------------------------------------------------
log_step("Suppression de toutes les tables existantes");

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
if (empty($tables)) {
    log_info("Aucune table existante à supprimer.");
} else {
    foreach ($tables as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `$table`");
        log_ok("Table '$table' supprimée.");
    }
}

// Supprimer les vues aussi
$views = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($views as $view) {
    $pdo->exec("DROP VIEW IF EXISTS `$view`");
    log_ok("Vue '$view' supprimée.");
}

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// -------------------------------------------------------
// CRÉATION DES TABLES
// -------------------------------------------------------
log_step("Création du schéma FlotteCar");

$migrations = [];

// ============================================================
// 1. TENANTS (entreprises clientes de la plateforme)
// ============================================================
$migrations['tenants'] = "
CREATE TABLE `tenants` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `nom_entreprise`  VARCHAR(150) NOT NULL,
    `email`           VARCHAR(100) NOT NULL UNIQUE,
    `telephone`       VARCHAR(25) DEFAULT NULL,
    `adresse`         TEXT DEFAULT NULL,
    `logo`            VARCHAR(255) DEFAULT NULL,
    `type_usage`      ENUM('location','controle','les_deux') NOT NULL DEFAULT 'les_deux',
    `plan`            VARCHAR(20) NOT NULL DEFAULT 'starter',
    `actif`           TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// ============================================================
// 2. USERS (super_admin + utilisateurs des tenants)
// ============================================================
$migrations['users'] = "
CREATE TABLE `users` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`         INT DEFAULT NULL,
    `nom`               VARCHAR(100) NOT NULL,
    `prenom`            VARCHAR(100) DEFAULT NULL,
    `email`             VARCHAR(100) NOT NULL UNIQUE,
    `password`          VARCHAR(255) NOT NULL,
    `role`              ENUM('super_admin','tenant_admin','tenant_user') NOT NULL DEFAULT 'tenant_admin',
    `permissions`       JSON DEFAULT NULL COMMENT 'Permissions granulaires pour tenant_user',
    `statut`            ENUM('actif','inactif') NOT NULL DEFAULT 'actif',
    `derniere_connexion` TIMESTAMP NULL DEFAULT NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// ============================================================
// 3. ABONNEMENTS
// ============================================================
$migrations['abonnements'] = "
CREATE TABLE `abonnements` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`   INT NOT NULL,
    `plan`        ENUM('starter','pro','enterprise') NOT NULL DEFAULT 'starter',
    `prix`        DECIMAL(10,2) NOT NULL DEFAULT 0,
    `date_debut`  DATE NOT NULL,
    `date_fin`    DATE NOT NULL,
    `statut`      ENUM('actif','expire','suspendu') NOT NULL DEFAULT 'actif',
    `notes`       TEXT DEFAULT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// ============================================================
// 4. VEHICULES
// ============================================================
$migrations['vehicules'] = "
CREATE TABLE `vehicules` (
    `id`                    INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`             INT NOT NULL,
    `nom`                   VARCHAR(100) NOT NULL COMMENT 'Nom interne / plaque',
    `immatriculation`       VARCHAR(30) NOT NULL,
    `marque`                VARCHAR(60) DEFAULT NULL,
    `modele`                VARCHAR(60) DEFAULT NULL,
    `annee`                 YEAR DEFAULT NULL,
    `couleur`               VARCHAR(40) DEFAULT NULL,
    `type_carburant`        ENUM('essence','diesel','electrique','hybride') DEFAULT 'essence',
    `places`                TINYINT DEFAULT 5,
    `prix_location_jour`    DECIMAL(10,2) DEFAULT 0 COMMENT 'Prix en FCFA/jour pour module location',
    `capital_investi`       DECIMAL(12,2) DEFAULT 0,
    `kilometrage_actuel`    INT DEFAULT 0,
    `prochaine_vidange_km`  INT DEFAULT NULL,
    `traccar_device_id`     INT DEFAULT NULL COMMENT 'ID du device dans Traccar',
    `imei`                  VARCHAR(30) DEFAULT NULL COMMENT 'IMEI du boîtier GPS',
    `modele_boitier`        VARCHAR(60) DEFAULT NULL,
    `statut`                ENUM('disponible','loue','maintenance','indisponible') NOT NULL DEFAULT 'disponible',
    `photo`                 VARCHAR(255) DEFAULT NULL,
    `notes`                 TEXT DEFAULT NULL,
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// ============================================================
// 5. CLIENTS
// ============================================================
$migrations['clients'] = "
CREATE TABLE `clients` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`         INT NOT NULL,
    `nom`               VARCHAR(150) NOT NULL,
    `prenom`            VARCHAR(100) DEFAULT NULL,
    `telephone`         VARCHAR(25) DEFAULT NULL,
    `email`             VARCHAR(100) DEFAULT NULL,
    `adresse`           TEXT DEFAULT NULL,
    `piece_identite`    VARCHAR(30) DEFAULT NULL COMMENT 'CNI, Passeport...',
    `numero_piece`      VARCHAR(60) DEFAULT NULL,
    `notes`             TEXT DEFAULT NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// ============================================================
// 6. CHAUFFEURS
// ============================================================
$migrations['chauffeurs'] = "
CREATE TABLE `chauffeurs` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`         INT NOT NULL,
    `nom`               VARCHAR(100) NOT NULL,
    `prenom`            VARCHAR(100) DEFAULT NULL,
    `telephone`         VARCHAR(25) DEFAULT NULL,
    `email`             VARCHAR(100) DEFAULT NULL,
    `numero_permis`     VARCHAR(50) DEFAULT NULL,
    `date_permis`       DATE DEFAULT NULL,
    `numero_cni`        VARCHAR(50) DEFAULT NULL,
    `date_naissance`    DATE DEFAULT NULL,
    `adresse`           TEXT DEFAULT NULL,
    `statut`            ENUM('actif','inactif') NOT NULL DEFAULT 'actif',
    `photo`             VARCHAR(255) DEFAULT NULL,
    `type_chauffeur`    VARCHAR(30) DEFAULT 'location',
    `vehicule_id`       INT DEFAULT NULL,
    `notes`             TEXT DEFAULT NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// ============================================================
// 7. LOCATIONS (contrats de location)
// ============================================================
$migrations['locations'] = "
CREATE TABLE `locations` (
    `id`                    INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`             INT NOT NULL,
    `vehicule_id`           INT NOT NULL,
    `client_id`             INT NOT NULL,
    `chauffeur_id`          INT DEFAULT NULL,
    `commercial`            VARCHAR(100) DEFAULT NULL,
    `date_debut`            DATE NOT NULL,
    `date_fin`              DATE NOT NULL,
    `nombre_jours`          INT NOT NULL DEFAULT 1,
    `prix_par_jour`         DECIMAL(10,2) NOT NULL,
    `montant_total`         DECIMAL(12,2) NOT NULL,
    `remise`                DECIMAL(10,2) DEFAULT 0,
    `montant_final`         DECIMAL(12,2) NOT NULL,
    `caution`               DECIMAL(10,2) DEFAULT 0,
    `statut_caution`        ENUM('retenue','rendue','partielle') DEFAULT 'retenue',
    `avance`                DECIMAL(12,2) DEFAULT 0,
    `reste_a_payer`         DECIMAL(12,2) DEFAULT 0,
    `statut_paiement`       ENUM('non_paye','avance','solde') NOT NULL DEFAULT 'non_paye',
    `statut`                ENUM('en_cours','terminee','annulee') NOT NULL DEFAULT 'en_cours',
    `km_depart`             INT DEFAULT NULL,
    `km_retour`             INT DEFAULT NULL,
    `carburant_depart`      VARCHAR(20) DEFAULT 'plein',
    `carburant_retour`      VARCHAR(20) DEFAULT NULL,
    `canal_acquisition`     ENUM('direct','facebook','instagram','whatsapp','site_web','recommandation','autre') DEFAULT 'direct',
    `contrat_pdf`           VARCHAR(255) DEFAULT NULL,
    `notes`                 TEXT DEFAULT NULL,
    `created_by`            INT DEFAULT NULL,
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`tenant_id`)    REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`vehicule_id`)  REFERENCES `vehicules`(`id`),
    FOREIGN KEY (`client_id`)    REFERENCES `clients`(`id`),
    FOREIGN KEY (`chauffeur_id`) REFERENCES `chauffeurs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// ============================================================
// 8. PAIEMENTS (liés aux locations)
// ============================================================
$migrations['paiements'] = "
CREATE TABLE `paiements` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`         INT NOT NULL,
    `location_id`       INT NOT NULL,
    `montant`           DECIMAL(12,2) NOT NULL,
    `mode_paiement`     ENUM('espece','mobile_money','virement','cheque','carte') NOT NULL DEFAULT 'espece',
    `reference`         VARCHAR(100) DEFAULT NULL,
    `notes`             TEXT DEFAULT NULL,
    `created_by`        INT DEFAULT NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`tenant_id`)   REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// ============================================================
// 9. CHARGES (dépenses par véhicule)
// ============================================================
$migrations['charges'] = "
CREATE TABLE `charges` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`     INT NOT NULL,
    `vehicule_id`   INT NOT NULL,
    `type`          ENUM('carburant','maintenance','assurance','vignette','reparation','nettoyage','amende','autre') NOT NULL DEFAULT 'autre',
    `libelle`       VARCHAR(255) NOT NULL,
    `montant`       DECIMAL(12,2) NOT NULL,
    `date_charge`   DATE NOT NULL,
    `piece_jointe`  VARCHAR(255) DEFAULT NULL,
    `notes`         TEXT DEFAULT NULL,
    `created_by`    INT DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`tenant_id`)   REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`vehicule_id`) REFERENCES `vehicules`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// ============================================================
// 10. MAINTENANCES PLANIFIÉES
// ============================================================
$migrations['maintenances'] = "
CREATE TABLE `maintenances` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`     INT NOT NULL,
    `vehicule_id`   INT NOT NULL,
    `type`          VARCHAR(100) NOT NULL COMMENT 'Vidange, Révision, Pneus...',
    `km_prevu`      INT DEFAULT NULL,
    `date_prevue`   DATE DEFAULT NULL,
    `statut`        ENUM('planifie','en_cours','termine','en_retard','fait') NOT NULL DEFAULT 'planifie',
    `cout`          DECIMAL(10,2) DEFAULT 0,
    `notes`         TEXT DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`tenant_id`)   REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`vehicule_id`) REFERENCES `vehicules`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// ============================================================
// 11. POSITIONS GPS (historique local optionnel)
// ============================================================
$migrations['positions_gps'] = "
CREATE TABLE `positions_gps` (
    `id`            BIGINT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`     INT NOT NULL,
    `vehicule_id`   INT NOT NULL,
    `latitude`      DECIMAL(10,8) NOT NULL,
    `longitude`     DECIMAL(11,8) NOT NULL,
    `vitesse`       DECIMAL(6,2) DEFAULT 0,
    `cap`           DECIMAL(6,2) DEFAULT 0,
    `altitude`      DECIMAL(8,2) DEFAULT 0,
    `moteur`        TINYINT(1) DEFAULT 0,
    `horodatage`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vehicule_time (`vehicule_id`, `horodatage`),
    FOREIGN KEY (`tenant_id`)   REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`vehicule_id`) REFERENCES `vehicules`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// ============================================================
// 12. ALERTES GPS
// ============================================================
$migrations['alertes'] = "
CREATE TABLE `alertes` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`     INT NOT NULL,
    `vehicule_id`   INT NOT NULL,
    `type`          ENUM('vitesse','zone','horaire','batterie','mouvement','coupure') NOT NULL,
    `valeur_seuil`  VARCHAR(100) DEFAULT NULL COMMENT 'Ex: 120 pour vitesse max',
    `actif`         TINYINT(1) DEFAULT 1,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`tenant_id`)   REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`vehicule_id`) REFERENCES `vehicules`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// ============================================================
// 13. ZONES GÉOGRAPHIQUES (geofencing)
// ============================================================
$migrations['zones'] = "
CREATE TABLE `zones` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`         INT NOT NULL,
    `nom`               VARCHAR(100) NOT NULL,
    `latitude_centre`   DECIMAL(10,8) NOT NULL,
    `longitude_centre`  DECIMAL(11,8) NOT NULL,
    `rayon_metres`      INT NOT NULL DEFAULT 500,
    `actif`             TINYINT(1) DEFAULT 1,
    `couleur`           VARCHAR(10) DEFAULT '#3b82f6',
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// ============================================================
// 14. LOGS COMMANDES GPS (audit coupures moteur etc.)
// ============================================================
$migrations['logs_gps_commandes'] = "
CREATE TABLE `logs_gps_commandes` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`     INT NOT NULL,
    `vehicule_id`   INT NOT NULL,
    `user_id`       INT NOT NULL,
    `commande`      ENUM('engineStop','engineResume','autre') NOT NULL,
    `resultat`      TEXT DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`tenant_id`)   REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`vehicule_id`) REFERENCES `vehicules`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// ============================================================
// 15. LOGS ACTIVITÉS (audit général)
// ============================================================
$migrations['logs_activites'] = "
CREATE TABLE `logs_activites` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`     INT DEFAULT NULL,
    `user_id`       INT DEFAULT NULL,
    `action`        VARCHAR(100) NOT NULL,
    `module`        VARCHAR(60) DEFAULT NULL,
    `description`   TEXT DEFAULT NULL,
    `ip_address`    VARCHAR(45) DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// ============================================================
// 16. PARAMETRES (configuration par tenant)
// ============================================================
$migrations['parametres'] = "
CREATE TABLE `parametres` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`     INT DEFAULT NULL COMMENT 'NULL = paramètres globaux plateforme',
    `cle`           VARCHAR(100) NOT NULL,
    `valeur`        TEXT DEFAULT NULL,
    `description`   VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY uk_tenant_cle (`tenant_id`, `cle`),
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

$migrations['notifs_push'] = "
CREATE TABLE IF NOT EXISTS `notifs_push` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`  INT NOT NULL DEFAULT 0,
    `user_id`    INT DEFAULT NULL,
    `type`       VARCHAR(50) DEFAULT 'info',
    `titre`      VARCHAR(255) NOT NULL,
    `corps`      TEXT,
    `url`        VARCHAR(500),
    `lu`         TINYINT(1) DEFAULT 0,
    `envoye`     TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_lu (tenant_id, lu),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

// Exécuter toutes les migrations
foreach ($migrations as $table => $sql) {
    try {
        $pdo->exec($sql);
        log_ok("Table '$table' créée.");
    } catch (PDOException $e) {
        log_error("Erreur table '$table' : " . $e->getMessage());
        exit(1);
    }
}

// -------------------------------------------------------
// DONNÉES INITIALES
// -------------------------------------------------------
log_step("Insertion des données initiales");

// Super admin (platform)
$hashedPassword = password_hash('FlotteCar@2026', PASSWORD_BCRYPT);

$pdo->exec("
    INSERT INTO `users` (`tenant_id`, `nom`, `prenom`, `email`, `password`, `role`, `statut`)
    VALUES (NULL, 'Super Admin', 'FlotteCar', 'admin@flottecar.ci', '$hashedPassword', 'super_admin', 'actif')
");
log_ok("Super admin créé : admin@flottecar.ci / FlotteCar@2026");

// Tenant de démonstration
$pdo->exec("
    INSERT INTO `tenants` (`nom_entreprise`, `email`, `telephone`, `type_usage`, `plan`, `actif`)
    VALUES ('FlotteCar Demo', 'demo@flottecar.ci', '+225 07 00 00 00 00', 'les_deux', 'enterprise', 1)
");
$tenantId = $pdo->lastInsertId();
log_ok("Tenant démo créé (ID=$tenantId)");

// Abonnement demo (1 an)
$pdo->exec("
    INSERT INTO `abonnements` (`tenant_id`, `plan`, `prix`, `date_debut`, `date_fin`, `statut`)
    VALUES ($tenantId, 'enterprise', 35000, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 365 DAY), 'actif')
");
log_ok("Abonnement enterprise 1 an créé pour le tenant démo.");

// Admin du tenant demo
$hashedDemo = password_hash('Demo@2026', PASSWORD_BCRYPT);
$pdo->exec("
    INSERT INTO `users` (`tenant_id`, `nom`, `prenom`, `email`, `password`, `role`, `statut`)
    VALUES ($tenantId, 'Administrateur', 'Demo', 'admin@demo.ci', '$hashedDemo', 'tenant_admin', 'actif')
");
log_ok("Admin demo créé : admin@demo.ci / Demo@2026");

// Paramètres globaux
$params = [
    ['TRACCAR_URL',  'http://localhost:8082/api', 'URL API Traccar'],
    ['TRACCAR_USER', 'admin', 'Login Traccar'],
    ['TRACCAR_PASS', 'admin', 'Mot de passe Traccar'],
    ['APP_NAME',     'FlotteCar', 'Nom de la plateforme'],
];
foreach ($params as [$cle, $valeur, $desc]) {
    $stmt = $pdo->prepare("INSERT INTO `parametres` (`tenant_id`, `cle`, `valeur`, `description`) VALUES (NULL, ?, ?, ?)");
    $stmt->execute([$cle, $valeur, $desc]);
}
log_ok("Paramètres globaux insérés.");

// -------------------------------------------------------
// RÉSUMÉ
// -------------------------------------------------------
log_step("Migration terminée avec succès !");
echo "\n";
echo BOLD . GREEN . "  FlotteCar est prêt !\n" . RESET;
echo "\n";
echo "  Super Admin    : " . CYAN . "admin@flottecar.ci" . RESET . " / " . YELLOW . "FlotteCar@2026" . RESET . "\n";
echo "  Admin Demo     : " . CYAN . "admin@demo.ci" . RESET . " / " . YELLOW . "Demo@2026" . RESET . "\n";
echo "  URL locale     : " . CYAN . "http://localhost/traccargps/" . RESET . "\n";
echo "\n";
echo "  Tables créées  : " . count($migrations) . "\n";
echo "\n";
