<?php
$p = new PDO('mysql:host=localhost;dbname=traccargps', 'root', '');
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$p->exec("
CREATE TABLE IF NOT EXISTS regles_gps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  vehicule_id INT NULL COMMENT 'NULL = regle globale tenant',
  type_regle VARCHAR(50) NOT NULL COMMENT 'horaire|vitesse|vidange|assurance|coupure_gps|immobilisation|km_jour|geofence|ralenti|trajets_jour',
  libelle VARCHAR(100) NOT NULL,
  params JSON NOT NULL,
  actif TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id),
  INDEX idx_vehicule (vehicule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "Table regles_gps creee.\n";

$p->exec("
CREATE TABLE IF NOT EXISTS alertes_regles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  vehicule_id INT NOT NULL,
  regle_id INT NULL,
  type_alerte VARCHAR(50) NOT NULL,
  message TEXT NOT NULL,
  valeur_declencheur VARCHAR(100) NULL,
  lu TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant_lu (tenant_id, lu),
  INDEX idx_vehicule (vehicule_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "Table alertes_regles creee.\n";

// V2 — Ajout colonnes action automatique
try {
    $p->exec("ALTER TABLE regles_gps ADD COLUMN action_auto VARCHAR(30) NOT NULL DEFAULT 'notification_only' AFTER params");
    echo "Colonne regles_gps.action_auto ajoutee.\n";
} catch (Throwable $e) {
    echo "regles_gps.action_auto: " . $e->getMessage() . "\n";
}

try {
    $p->exec("ALTER TABLE alertes_regles ADD COLUMN action_executee VARCHAR(50) DEFAULT NULL AFTER valeur_declencheur");
    echo "Colonne alertes_regles.action_executee ajoutee.\n";
} catch (Throwable $e) {
    echo "alertes_regles.action_executee: " . $e->getMessage() . "\n";
}

echo "Migration OK.\n";
