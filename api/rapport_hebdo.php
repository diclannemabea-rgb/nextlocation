<?php
/**
 * FlotteCar — Rapport Hebdomadaire Automatique (CRON)
 *
 * Génère un résumé hebdomadaire par tenant et le stocke en DB (notifs_push).
 * Peut aussi envoyer par email si configuré.
 *
 * Usage CRON (chaque lundi à 8h) :
 *   php c:/wamp64_2/www/traccargps/api/rapport_hebdo.php
 *
 * Usage HTTP :
 *   GET http://localhost/traccargps/api/rapport_hebdo.php?key=flottecar_maint_2026
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';

// Sécurité: vérifier clé CRON si accès HTTP
if (php_sapi_name() !== 'cli') {
    if (($_GET['key'] ?? '') !== CRON_KEY) {
        http_response_code(403);
        die(json_encode(['error' => 'Clé CRON invalide']));
    }
}

$db = (new Database())->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$debut = date('Y-m-d', strtotime('last monday'));
$fin   = date('Y-m-d', strtotime('last sunday'));
$now   = date('Y-m-d H:i:s');

// Tous les tenants actifs
$tenants = $db->query("SELECT id, nom_entreprise, email FROM tenants WHERE actif=1")->fetchAll(PDO::FETCH_ASSOC);

$rapports = [];

foreach ($tenants as $t) {
    $tid = $t['id'];
    $rapport = ['tenant' => $t['nom_entreprise'], 'stats' => []];

    // Véhicules
    $veh = $db->prepare("SELECT COUNT(*) total,
        SUM(statut='disponible') dispo, SUM(statut='loue') loues, SUM(statut='maintenance') maint
        FROM vehicules WHERE tenant_id=?");
    $veh->execute([$tid]);
    $rapport['vehicules'] = $veh->fetch(PDO::FETCH_ASSOC);

    // Locations cette semaine
    $loc = $db->prepare("SELECT COUNT(*) nb, COALESCE(SUM(montant_total),0) ca
        FROM locations WHERE tenant_id=? AND date_debut BETWEEN ? AND ?");
    $loc->execute([$tid, $debut, $fin]);
    $rapport['locations'] = $loc->fetch(PDO::FETCH_ASSOC);

    // Paiements taxi cette semaine
    $taxi = $db->prepare("SELECT
        COUNT(CASE WHEN pt.statut_jour='paye' THEN 1 END) payes,
        COUNT(CASE WHEN pt.statut_jour='non_paye' THEN 1 END) impayes,
        COALESCE(SUM(pt.montant_paye),0) total_paye
        FROM paiements_taxi pt
        JOIN taximetres tx ON tx.id=pt.taximetre_id
        WHERE tx.tenant_id=? AND pt.date_paiement BETWEEN ? AND ?");
    $taxi->execute([$tid, $debut, $fin]);
    $rapport['taxi'] = $taxi->fetch(PDO::FETCH_ASSOC);

    // Charges cette semaine
    $ch = $db->prepare("SELECT COALESCE(SUM(montant),0) total FROM charges WHERE tenant_id=? AND date_charge BETWEEN ? AND ?");
    $ch->execute([$tid, $debut, $fin]);
    $rapport['charges'] = (float)$ch->fetchColumn();

    // Maintenances urgentes
    $maint = $db->prepare("SELECT COUNT(*) FROM maintenances WHERE tenant_id=? AND statut='planifie'");
    $maint->execute([$tid]);
    $rapport['maintenances_urgentes'] = (int)$maint->fetchColumn();

    // Alertes GPS non lues
    $alertes = 0;
    try {
        $al = $db->prepare("SELECT COUNT(*) FROM alertes_regles WHERE tenant_id=? AND lu=0");
        $al->execute([$tid]);
        $alertes = (int)$al->fetchColumn();
    } catch (Exception $e) {}
    $rapport['alertes_non_lues'] = $alertes;

    // Construire le résumé texte
    $v = $rapport['vehicules'];
    $l = $rapport['locations'];
    $tx = $rapport['taxi'];

    $resume = "📊 Rapport Hebdo — Semaine du " . date('d/m', strtotime($debut)) . " au " . date('d/m', strtotime($fin)) . "\n\n";
    $resume .= "🚗 Véhicules : " . (int)$v['total'] . " (dispo: " . (int)$v['dispo'] . ", loués: " . (int)$v['loues'] . ", maint: " . (int)$v['maint'] . ")\n";
    $resume .= "📋 Locations : " . (int)$l['nb'] . " · CA: " . number_format((float)$l['ca'],0,',',' ') . " FCFA\n";
    $resume .= "🚕 Taxi : " . (int)$tx['payes'] . " jours payés, " . (int)$tx['impayes'] . " impayés · " . number_format((float)$tx['total_paye'],0,',',' ') . " FCFA perçus\n";
    $resume .= "💸 Charges : " . number_format($rapport['charges'],0,',',' ') . " FCFA\n";

    if ($rapport['maintenances_urgentes'] > 0) {
        $resume .= "🔧 " . $rapport['maintenances_urgentes'] . " maintenance(s) en attente\n";
    }
    if ($rapport['alertes_non_lues'] > 0) {
        $resume .= "🔔 " . $rapport['alertes_non_lues'] . " alerte(s) GPS non lue(s)\n";
    }

    // Stocker en notification push
    try {
        $db->prepare("INSERT INTO notifs_push (tenant_id, user_id, type, titre, corps, url) VALUES (?, NULL, 'rapport', 'Rapport Hebdomadaire', ?, ?)")
           ->execute([$tid, $resume, 'app/rapports/']);
    } catch (Exception $e) {}

    $rapport['resume'] = $resume;
    $rapports[] = $rapport;
}

// Sortie
$result = [
    'status'   => 'ok',
    'date'     => $now,
    'periode'  => "$debut → $fin",
    'tenants'  => count($rapports),
    'rapports' => $rapports,
];

if (php_sapi_name() === 'cli') {
    echo "=== RAPPORT HEBDO FLOTTECAR ===\n";
    echo "Période : $debut → $fin\n";
    echo count($rapports) . " tenant(s) traité(s)\n\n";
    foreach ($rapports as $r) {
        echo "--- " . $r['tenant'] . " ---\n";
        echo $r['resume'] . "\n";
    }
} else {
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
