<?php
/**
 * FlotteCar - Migration V2
 * Ajout des tables et colonnes manquantes SANS effacer les données existantes.
 * Usage CLI : php migrations/migrate_v2.php
 */

define('RED',    "\033[0;31m");
define('GREEN',  "\033[0;32m");
define('YELLOW', "\033[0;33m");
define('BLUE',   "\033[0;34m");
define('CYAN',   "\033[0;36m");
define('RESET',  "\033[0m");
define('BOLD',   "\033[1m");

function log_ok($m)    { echo GREEN  . "[OK]    " . RESET . $m . "\n"; }
function log_skip($m)  { echo YELLOW . "[SKIP]  " . RESET . $m . "\n"; }
function log_error($m) { echo RED    . "[ERROR] " . RESET . $m . "\n"; }
function log_step($m)  { echo BOLD   . BLUE . "\n==> " . $m . RESET . "\n"; }

$host = 'localhost'; $dbname = 'traccargps'; $user = 'root'; $pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    log_ok("Connexion MySQL OK.");
} catch (PDOException $e) {
    log_error("Connexion impossible: " . $e->getMessage()); exit(1);
}

// Helper: add column only if it doesn't exist
function addColumn(PDO $pdo, string $table, string $col, string $def): void {
    $exists = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->rowCount();
    if ($exists) { log_skip("$table.$col existe déjà."); return; }
    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
    log_ok("$table.$col ajoutée.");
}

// Helper: create table only if not exists
function createTable(PDO $pdo, string $table, string $sql): void {
    $exists = $pdo->query("SHOW TABLES LIKE '$table'")->rowCount();
    if ($exists) { log_skip("Table '$table' existe déjà."); return; }
    $pdo->exec($sql);
    log_ok("Table '$table' créée.");
}

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

// ============================================================
// ALTER TABLE vehicules — données financières initiales
// ============================================================
log_step("vehicules — colonnes financières et type");
addColumn($pdo, 'vehicules', 'km_initial_compteur',  "INT DEFAULT 0 COMMENT 'Km au compteur lors de l enregistrement'");
addColumn($pdo, 'vehicules', 'recettes_initiales',   "DECIMAL(12,2) DEFAULT 0 COMMENT 'Recettes déjà perçues avant onboarding'");
addColumn($pdo, 'vehicules', 'depenses_initiales',   "DECIMAL(12,2) DEFAULT 0 COMMENT 'Dépenses déjà faites avant onboarding'");
addColumn($pdo, 'vehicules', 'type_vehicule',        "ENUM('location','taxi','entreprise','personnel') DEFAULT 'location' COMMENT 'Usage du véhicule'");
addColumn($pdo, 'vehicules', 'carburant_type',       "ENUM('essence','diesel','electrique','hybride') DEFAULT 'essence'");
addColumn($pdo, 'vehicules', 'puissance_cv',         "SMALLINT DEFAULT NULL");
addColumn($pdo, 'vehicules', 'date_mise_en_service', "DATE DEFAULT NULL");
addColumn($pdo, 'vehicules', 'date_expiration_assurance', "DATE DEFAULT NULL");
addColumn($pdo, 'vehicules', 'date_expiration_vignette',  "DATE DEFAULT NULL");
addColumn($pdo, 'vehicules', 'numero_chassis',       "VARCHAR(50) DEFAULT NULL");

// ============================================================
// ALTER TABLE clients — pièces d'identité et carte grise
// ============================================================
log_step("clients — documents");
addColumn($pdo, 'clients', 'type_piece',        "ENUM('cni','passeport','permis','carte_sejour','autre') DEFAULT 'cni' COMMENT 'Type de pièce d identité'");
addColumn($pdo, 'clients', 'photo_piece',       "VARCHAR(255) DEFAULT NULL COMMENT 'Photo pièce d identité'");
addColumn($pdo, 'clients', 'numero_carte_grise',"VARCHAR(60) DEFAULT NULL");
addColumn($pdo, 'clients', 'photo_carte_grise', "VARCHAR(255) DEFAULT NULL");
addColumn($pdo, 'clients', 'profession',        "VARCHAR(100) DEFAULT NULL");
addColumn($pdo, 'clients', 'date_naissance',    "DATE DEFAULT NULL");

// ============================================================
// ALTER TABLE chauffeurs — véhicule assigné et type
// ============================================================
log_step("chauffeurs — vehicule_id et type");
addColumn($pdo, 'chauffeurs', 'vehicule_id',    "INT DEFAULT NULL COMMENT 'Véhicule assigné'");
addColumn($pdo, 'chauffeurs', 'type_chauffeur', "ENUM('location','taxi','entreprise') DEFAULT 'location'");
addColumn($pdo, 'chauffeurs', 'date_naissance', "DATE DEFAULT NULL");
addColumn($pdo, 'chauffeurs', 'numero_cni',     "VARCHAR(60) DEFAULT NULL");

// ============================================================
// ALTER TABLE locations — champs métier complets
// ============================================================
log_step("locations — champs métier");
addColumn($pdo, 'locations', 'type_location',     "ENUM('standard','avec_chauffeur','longue_duree') DEFAULT 'standard'");
addColumn($pdo, 'locations', 'lieu_destination',  "VARCHAR(255) DEFAULT NULL");
addColumn($pdo, 'locations', 'avec_chauffeur',    "TINYINT(1) DEFAULT 0");
addColumn($pdo, 'locations', 'commercial_id',     "INT DEFAULT NULL COMMENT 'Commercial ayant apporté le client'");
addColumn($pdo, 'locations', 'date_paiement',     "DATE DEFAULT NULL");
addColumn($pdo, 'locations', 'mode_paiement',     "ENUM('especes','mobile_money','virement','cheque','carte') DEFAULT 'especes'");
addColumn($pdo, 'locations', 'statut_reservation',"ENUM('reservation','confirmee','annulee') DEFAULT NULL COMMENT 'NULL = location directe'");

// ============================================================
// ALTER TABLE maintenances — lien avec Traccar km
// ============================================================
log_step("maintenances — colonnes avancées");
addColumn($pdo, 'maintenances', 'km_fait',          "INT DEFAULT NULL COMMENT 'Km réel lors de l intervention'");
addColumn($pdo, 'maintenances', 'technicien',       "VARCHAR(100) DEFAULT NULL");
addColumn($pdo, 'maintenances', 'facture',          "VARCHAR(255) DEFAULT NULL COMMENT 'Photo/fichier facture'");
addColumn($pdo, 'maintenances', 'updated_at',       "TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP");
addColumn($pdo, 'maintenances', 'alerte_envoyee',   "TINYINT(1) DEFAULT 0 COMMENT '1 si alerte GPS déclenchée'");

// ============================================================
// NOUVELLE TABLE : commerciaux
// ============================================================
log_step("Création table commerciaux");
createTable($pdo, 'commerciaux', "
CREATE TABLE `commerciaux` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`     INT NOT NULL,
    `nom`           VARCHAR(150) NOT NULL,
    `prenom`        VARCHAR(100) DEFAULT NULL,
    `telephone`     VARCHAR(25) DEFAULT NULL,
    `email`         VARCHAR(100) DEFAULT NULL,
    `commission_pct` DECIMAL(5,2) DEFAULT 0 COMMENT 'Commission en %',
    `statut`        ENUM('actif','inactif') DEFAULT 'actif',
    `notes`         TEXT DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ============================================================
// NOUVELLE TABLE : reservations
// ============================================================
log_step("Création table reservations");
createTable($pdo, 'reservations', "
CREATE TABLE `reservations` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`         INT NOT NULL,
    `vehicule_id`       INT NOT NULL,
    `client_id`         INT NOT NULL,
    `chauffeur_id`      INT DEFAULT NULL,
    `commercial_id`     INT DEFAULT NULL,
    `date_debut`        DATETIME NOT NULL,
    `date_fin`          DATETIME NOT NULL,
    `nombre_jours`      INT NOT NULL DEFAULT 1,
    `prix_par_jour`     DECIMAL(10,2) NOT NULL DEFAULT 0,
    `montant_total`     DECIMAL(12,2) NOT NULL DEFAULT 0,
    `remise`            DECIMAL(10,2) DEFAULT 0,
    `montant_final`     DECIMAL(12,2) NOT NULL DEFAULT 0,
    `caution`           DECIMAL(10,2) DEFAULT 0,
    `avance`            DECIMAL(12,2) DEFAULT 0,
    `lieu_destination`  VARCHAR(255) DEFAULT NULL,
    `avec_chauffeur`    TINYINT(1) DEFAULT 0,
    `canal_acquisition` ENUM('direct','facebook','instagram','whatsapp','site_web','recommandation','autre') DEFAULT 'direct',
    `statut`            ENUM('en_attente','confirmee','convertie','annulee') NOT NULL DEFAULT 'en_attente',
    `location_id`       INT DEFAULT NULL COMMENT 'ID location créée depuis cette réservation',
    `notes`             TEXT DEFAULT NULL,
    `created_by`        INT DEFAULT NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`tenant_id`)    REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`vehicule_id`)  REFERENCES `vehicules`(`id`),
    FOREIGN KEY (`client_id`)    REFERENCES `clients`(`id`),
    FOREIGN KEY (`chauffeur_id`) REFERENCES `chauffeurs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ============================================================
// NOUVELLE TABLE : taximetres
// ============================================================
log_step("Création table taximetres");
createTable($pdo, 'taximetres', "
CREATE TABLE `taximetres` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`         INT NOT NULL,
    `vehicule_id`       INT NOT NULL,
    `nom`               VARCHAR(150) NOT NULL,
    `prenom`            VARCHAR(100) DEFAULT NULL,
    `telephone`         VARCHAR(25) DEFAULT NULL,
    `numero_cni`        VARCHAR(60) DEFAULT NULL,
    `photo`             VARCHAR(255) DEFAULT NULL,
    `tarif_journalier`  DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Montant dû par jour en FCFA',
    `date_debut`        DATE NOT NULL,
    `date_fin`          DATE DEFAULT NULL COMMENT 'NULL = en cours',
    `caution_versee`    DECIMAL(12,2) DEFAULT 0,
    `statut`            ENUM('actif','inactif','suspendu') NOT NULL DEFAULT 'actif',
    `notes`             TEXT DEFAULT NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`tenant_id`)   REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`vehicule_id`) REFERENCES `vehicules`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ============================================================
// NOUVELLE TABLE : paiements_taxi
// ============================================================
log_step("Création table paiements_taxi");
createTable($pdo, 'paiements_taxi', "
CREATE TABLE `paiements_taxi` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`         INT NOT NULL,
    `taximetre_id`      INT NOT NULL,
    `date_paiement`     DATE NOT NULL,
    `montant`           DECIMAL(10,2) NOT NULL DEFAULT 0,
    `statut_jour`       ENUM('paye','non_paye','jour_off','panne','accident') NOT NULL DEFAULT 'non_paye',
    `km_debut`          INT DEFAULT NULL,
    `km_fin`            INT DEFAULT NULL,
    `mode_paiement`     ENUM('especes','mobile_money','virement') DEFAULT 'especes',
    `notes`             TEXT DEFAULT NULL,
    `created_by`        INT DEFAULT NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`tenant_id`)    REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`taximetre_id`) REFERENCES `taximetres`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ============================================================
// NOUVELLE TABLE : evenements_gps (alertes déclenchées)
// ============================================================
log_step("Création table evenements_gps");
createTable($pdo, 'evenements_gps', "
CREATE TABLE `evenements_gps` (
    `id`            BIGINT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`     INT NOT NULL,
    `vehicule_id`   INT NOT NULL,
    `alerte_id`     INT DEFAULT NULL,
    `type`          VARCHAR(60) NOT NULL COMMENT 'vitesse, zone, vidange, etc.',
    `message`       TEXT DEFAULT NULL,
    `latitude`      DECIMAL(10,8) DEFAULT NULL,
    `longitude`     DECIMAL(11,8) DEFAULT NULL,
    `vitesse`       DECIMAL(6,2) DEFAULT NULL,
    `lu`            TINYINT(1) DEFAULT 0,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_lu (`tenant_id`, `lu`),
    FOREIGN KEY (`tenant_id`)   REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`vehicule_id`) REFERENCES `vehicules`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

log_step("Migration V2 terminée !");
echo GREEN . BOLD . "\n  Toutes les tables et colonnes ont été mises à jour.\n" . RESET;
echo "  Les données existantes ont été préservées.\n\n";
