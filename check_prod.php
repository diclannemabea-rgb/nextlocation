<?php
define('BASE_PATH', __DIR__);
require_once 'config/database.php';
require_once 'config/constants.php';
$db = (new Database())->getConnection();

echo "=== VEHICULES ===\n";
$rows = $db->query('SELECT id, nom, immatriculation, type_vehicule, statut FROM vehicules')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "  #{$r['id']} {$r['nom']} ({$r['immatriculation']}) type={$r['type_vehicule']} statut={$r['statut']}\n";
}

echo "\n=== notifs_push (toutes) ===\n";
$rows = $db->query('SELECT id, tenant_id, type, titre, lu, envoye FROM notifs_push ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "  #{$r['id']} [t{$r['tenant_id']}] {$r['type']} | {$r['titre']} | lu={$r['lu']} envoye={$r['envoye']}\n";
}

echo "\n=== notifs_push.type column ===\n";
$col = $db->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='traccargps' AND TABLE_NAME='notifs_push' AND COLUMN_NAME='type'")->fetchColumn();
echo "  type: $col\n";

echo "\n=== FICHIERS MODIFIES (date) ===\n";
$files = [
    'assets/js/app.js',
    'api/notifs.php',
    'includes/header.php',
    'auth/register.php',
    'app/taximetres/paiements.php',
    'app/locations/detail.php',
    'app/locations/nouvelle.php',
    'app/locations/terminer.php',
    'app/maintenances/index.php',
    'api/gps.php',
    'api/maintenance_check.php',
    'app/vehicules/ajouter.php',
    'config/functions.php',
    'config/auth.php',
    'sw.js',
];
foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    $exists = file_exists($path);
    echo "  " . ($exists ? '✓' : '✗') . " $f" . ($exists ? " — " . date('Y-m-d H:i', filemtime($path)) : " MANQUANT") . "\n";
}

echo "\n=== TABLES (nombre de lignes) ===\n";
$tables = ['tenants','users','vehicules','clients','chauffeurs','locations','taximetres','paiements_taxi','maintenances','notifs_push','reservations','commerciaux'];
foreach ($tables as $t) {
    $n = $db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    echo "  $t: $n lignes\n";
}
