<?php
/**
 * Migration V3 — FlotteCar
 * Exécuter : php migrations/migrate_v3.php
 */
$pdo = new PDO('mysql:host=localhost;dbname=traccargps;charset=utf8mb4', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function hasColumn(PDO $pdo, string $table, string $col): bool {
    $r = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
    return $r->rowCount() > 0;
}
function hasIndex(PDO $pdo, string $table, string $idx): bool {
    $r = $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name='$idx'");
    return $r->rowCount() > 0;
}

// paiements.reservation_id
if (!hasColumn($pdo, 'paiements', 'reservation_id')) {
    $pdo->exec("ALTER TABLE paiements ADD COLUMN reservation_id INT DEFAULT NULL AFTER location_id");
    echo "✓ paiements.reservation_id ajouté\n";
} else { echo "→ paiements.reservation_id existe déjà\n"; }

// paiements.location_id nullable
$pdo->exec("ALTER TABLE paiements MODIFY COLUMN location_id INT DEFAULT NULL");
echo "✓ paiements.location_id rendu nullable\n";

// paiements.source
if (!hasColumn($pdo, 'paiements', 'source')) {
    $pdo->exec("ALTER TABLE paiements ADD COLUMN source ENUM('location','reservation') DEFAULT 'location' AFTER reservation_id");
    echo "✓ paiements.source ajouté\n";
} else { echo "→ paiements.source existe déjà\n"; }

// index reservation_id
if (!hasIndex($pdo, 'paiements', 'idx_rsv_id')) {
    $pdo->exec("ALTER TABLE paiements ADD INDEX idx_rsv_id (reservation_id)");
    echo "✓ index idx_rsv_id ajouté\n";
} else { echo "→ index idx_rsv_id existe déjà\n"; }

// reservations.location_id
if (!hasColumn($pdo, 'reservations', 'location_id')) {
    $pdo->exec("ALTER TABLE reservations ADD COLUMN location_id INT DEFAULT NULL AFTER statut");
    echo "✓ reservations.location_id ajouté\n";
} else { echo "→ reservations.location_id existe déjà\n"; }

echo "\nMigration V3 OK.\n";
