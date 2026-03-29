<?php
/**
 * Export SQL complet de la base traccargps
 * Accès : http://localhost/traccargps/export_prod.php
 */
define('BASE_PATH', __DIR__);
require_once 'config/database.php';
require_once 'config/constants.php';

$db = (new Database())->getConnection();
$db->exec("SET NAMES utf8mb4");

$dbName = 'traccargps';

// Récupérer toutes les tables
$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

$output  = "-- FlotteCar — Export SQL complet\n";
$output .= "-- Généré le : " . date('Y-m-d H:i:s') . "\n";
$output .= "-- Base de données : $dbName\n\n";
$output .= "SET FOREIGN_KEY_CHECKS = 0;\n";
$output .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
$output .= "SET NAMES utf8mb4;\n\n";

foreach ($tables as $table) {
    // Structure CREATE TABLE
    $createRow = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
    $create    = $createRow[1];

    $output .= "-- ----------------------------\n";
    $output .= "-- Table: $table\n";
    $output .= "-- ----------------------------\n";
    $output .= "DROP TABLE IF EXISTS `$table`;\n";
    $output .= $create . ";\n\n";

    // Données
    $rows = $db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rows)) {
        $cols   = '`' . implode('`, `', array_keys($rows[0])) . '`';
        $output .= "INSERT INTO `$table` ($cols) VALUES\n";

        $vals = [];
        foreach ($rows as $row) {
            $escaped = array_map(function($v) use ($db) {
                if ($v === null) return 'NULL';
                return $db->quote($v);
            }, array_values($row));
            $vals[] = '(' . implode(', ', $escaped) . ')';
        }
        $output .= implode(",\n", $vals) . ";\n\n";
    }
}

$output .= "SET FOREIGN_KEY_CHECKS = 1;\n";

// Écrire le fichier
$filename = 'export_prod_' . date('Ymd_His') . '.sql';
file_put_contents(__DIR__ . '/' . $filename, $output);

echo "<h2>Export terminé</h2>";
echo "<p>Fichier : <strong>$filename</strong> (" . round(strlen($output)/1024, 1) . " Ko)</p>";
echo "<p><a href='$filename' download>⬇ Télécharger $filename</a></p>";
echo "<p>Tables exportées : " . count($tables) . "</p>";
echo "<ul>";
foreach ($tables as $t) {
    $n = $db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo "<li>$t — $n lignes</li>";
}
echo "</ul>";
