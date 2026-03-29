<?php
/**
 * FlotteCar — Cron: Vérification km GPS vs seuils maintenance
 * Appeler toutes les heures via tâche planifiée:
 *   php c:/wamp64_2/www/traccargps/api/maintenance_check.php
 * Ou via HTTP (protégé par clé):
 *   GET /api/maintenance_check.php?key=SECRET_CRON_KEY
 *
 * Rôle:
 *  1. Récupère tous les véhicules avec boîtier GPS (traccar_device_id non nul)
 *  2. Lit le km actuel depuis Traccar API (mileage)
 *  3. Met à jour vehicules.kilometrage_actuel
 *  4. Appelle MaintenanceModel::checkAlerteGps() → insère evenements_gps si seuil atteint
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/includes/TraccarAPI.php';
require_once BASE_PATH . '/models/MaintenanceModel.php';

// Helper local : insère dans notifs_push (même signature que pushNotif() de functions.php)
function _insertNotif(PDO $db, int $tenantId, string $type, string $titre, string $corps = '', string $url = ''): void {
    try {
        $db->prepare("INSERT INTO notifs_push (tenant_id, user_id, type, titre, corps, url)
            VALUES (?, NULL, ?, ?, ?, ?)")->execute([$tenantId, $type, $titre, $corps, $url]);
    } catch (Throwable $e) {}
}

// Sécurité basique: clé secrète ou CLI uniquement
define('CRON_KEY', 'flottecar_maint_2026');

$isCli  = (php_sapi_name() === 'cli');
$isHttp = isset($_GET['key']) && $_GET['key'] === CRON_KEY;

if (!$isCli && !$isHttp) {
    http_response_code(403);
    die(json_encode(['error' => 'Accès refusé']));
}

$db    = (new Database())->getConnection();
$model = new MaintenanceModel($db);
$api   = new TraccarAPI();

$log     = [];
$log[]   = '[' . date('Y-m-d H:i:s') . '] Début vérification maintenance GPS';

if (!$api->isAvailable()) {
    $log[] = '⚠ Traccar non disponible — arrêt';
    echo implode(PHP_EOL, $log) . PHP_EOL;
    exit(0);
}

// Récupérer tous les véhicules avec GPS sur tous les tenants
$stmt = $db->query(
    "SELECT id, tenant_id, nom, immatriculation, traccar_device_id, kilometrage_actuel
     FROM vehicules
     WHERE traccar_device_id IS NOT NULL"
);
$vehicules = $stmt->fetchAll(PDO::FETCH_ASSOC);

$log[] = count($vehicules) . ' véhicule(s) GPS à vérifier';

$nbAlertes = 0;
foreach ($vehicules as $v) {
    $pos = $api->getPosition((int) $v['traccar_device_id']);
    if (!$pos) {
        continue;
    }

    // Mileage depuis Traccar (en mètres → km)
    $mileageM  = $pos['attributes']['totalDistance'] ?? 0;
    $kmTraccar = (int) round($mileageM / 1000);

    if ($kmTraccar <= 0) {
        // Fallback: utiliser le km actuel en base
        $kmTraccar = (int) $v['kilometrage_actuel'];
    }

    // Mettre à jour le km dans la base si supérieur au km actuel
    if ($kmTraccar > (int) $v['kilometrage_actuel']) {
        $db->prepare(
            'UPDATE vehicules SET kilometrage_actuel = ? WHERE id = ?'
        )->execute([$kmTraccar, $v['id']]);
    }

    // Vérifier les alertes maintenance
    $alertes = $model->checkAlerteGps((int) $v['id'], (int) $v['tenant_id'], $kmTraccar);

    if (!empty($alertes)) {
        foreach ($alertes as $a) {
            $log[] = "🔔 ALERTE [{$v['nom']} {$v['immatriculation']}] {$a['type']} — km seuil: {$a['km_prevu']}, km actuel: $kmTraccar";
            $nbAlertes++;
            _insertNotif(
                $db,
                (int) $v['tenant_id'],
                'maintenance',
                "🔧 Maintenance requise — {$v['nom']} {$v['immatriculation']}",
                ucfirst($a['type']) . " à effectuer (seuil {$a['km_prevu']} km atteint, actuel: {$kmTraccar} km)",
                BASE_URL . 'app/maintenances/index.php'
            );
        }
    }

    // ── Alertes GPS Traccar (vitesse, zones) ─────────────────────────────────
    $fromISO = date('c', strtotime('-1 hour'));
    $toISO   = date('c');
    try {
        $events = $api->getEvents((int)$v['traccar_device_id'], $fromISO, $toISO);
        foreach ($events as $ev) {
            $evType = $ev['type'] ?? '';
            if (in_array($evType, ['deviceOverspeed', 'geofenceEnter', 'geofenceExit', 'alarm'], true)) {
                // Dédoublonnage : 1 notif max par véhicule/type/heure
                $dedup = $db->prepare("SELECT id FROM notifs_push WHERE tenant_id=? AND type='gps' AND titre LIKE ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) LIMIT 1");
                $dedup->execute([(int)$v['tenant_id'], '%' . $v['immatriculation'] . '%']);
                if ($dedup->fetchColumn()) continue;

                $speed = isset($ev['attributes']['speed']) ? round((float)$ev['attributes']['speed'] * 1.852) : null; // nœuds → km/h
                if ($evType === 'deviceOverspeed') {
                    _insertNotif($db, (int)$v['tenant_id'], 'gps',
                        "🚨 Excès de vitesse — {$v['nom']} {$v['immatriculation']}",
                        "Vitesse détectée : " . ($speed ? "{$speed} km/h" : 'inconnue'),
                        BASE_URL . 'app/gps/carte.php'
                    );
                    $nbAlertes++;
                } elseif ($evType === 'geofenceEnter') {
                    _insertNotif($db, (int)$v['tenant_id'], 'gps',
                        "📍 Zone franchie — {$v['nom']} {$v['immatriculation']}",
                        "Entrée dans une zone géographique surveillée",
                        BASE_URL . 'app/gps/alertes.php'
                    );
                    $nbAlertes++;
                } elseif ($evType === 'geofenceExit') {
                    _insertNotif($db, (int)$v['tenant_id'], 'gps',
                        "📍 Sortie de zone — {$v['nom']} {$v['immatriculation']}",
                        "Sortie d'une zone géographique surveillée",
                        BASE_URL . 'app/gps/alertes.php'
                    );
                    $nbAlertes++;
                } elseif ($evType === 'alarm') {
                    _insertNotif($db, (int)$v['tenant_id'], 'gps',
                        "🆘 Alarme GPS — {$v['nom']} {$v['immatriculation']}",
                        "Alarme déclenchée sur le boîtier GPS",
                        BASE_URL . 'app/gps/alertes.php'
                    );
                    $nbAlertes++;
                }
            }
        }
    } catch (Throwable $e) {
        // Events Traccar optionnel — ne pas bloquer
    }
}

// ── Vérification assurance & vignette (tous tenants) ────────────────────────
$log[] = 'Vérification dates assurance/vignette...';
$today = date('Y-m-d');
$in15  = date('Y-m-d', strtotime('+15 days'));
$in30  = date('Y-m-d', strtotime('+30 days'));

$stmtDoc = $db->query(
    "SELECT id, tenant_id, nom, immatriculation,
            date_expiration_assurance, date_expiration_vignette
     FROM vehicules
     WHERE date_expiration_assurance IS NOT NULL
        OR date_expiration_vignette IS NOT NULL"
);
$vehs = $stmtDoc->fetchAll(PDO::FETCH_ASSOC);

foreach ($vehs as $v) {
    $tid = (int)$v['tenant_id'];
    $label = trim("{$v['nom']} {$v['immatriculation']}");
    $urlVeh = BASE_URL . 'app/vehicules/detail.php?id=' . $v['id'];

    // Assurance
    if (!empty($v['date_expiration_assurance'])) {
        $exp = $v['date_expiration_assurance'];
        if ($exp < $today) {
            // Vérifier si notif déjà envoyée aujourd'hui
            $exists = $db->prepare("SELECT id FROM notifs_push WHERE tenant_id=? AND type='alerte' AND titre LIKE ? AND DATE(created_at)=CURDATE() LIMIT 1");
            $exists->execute([$tid, "%assurance%{$v['immatriculation']}%"]);
            if (!$exists->fetchColumn()) {
                _insertNotif($db, $tid, 'alerte', "🚨 Assurance expirée — $label", "Expirée depuis le " . date('d/m/Y', strtotime($exp)), $urlVeh);
                $log[] = "⚠ Assurance EXPIRÉE: $label";
                $nbAlertes++;
            }
        } elseif ($exp <= $in15) {
            $exists = $db->prepare("SELECT id FROM notifs_push WHERE tenant_id=? AND type='alerte' AND titre LIKE ? AND DATE(created_at)=CURDATE() LIMIT 1");
            $exists->execute([$tid, "%assurance%{$v['immatriculation']}%"]);
            if (!$exists->fetchColumn()) {
                $days = (int)((strtotime($exp) - strtotime($today)) / 86400);
                _insertNotif($db, $tid, 'alerte', "⚠️ Assurance expire dans {$days}j — $label", "Date limite: " . date('d/m/Y', strtotime($exp)), $urlVeh);
                $log[] = "⚠ Assurance dans {$days}j: $label";
                $nbAlertes++;
            }
        }
    }

    // Vignette
    if (!empty($v['date_expiration_vignette'])) {
        $exp = $v['date_expiration_vignette'];
        if ($exp < $today) {
            $exists = $db->prepare("SELECT id FROM notifs_push WHERE tenant_id=? AND type='alerte' AND titre LIKE ? AND DATE(created_at)=CURDATE() LIMIT 1");
            $exists->execute([$tid, "%vignette%{$v['immatriculation']}%"]);
            if (!$exists->fetchColumn()) {
                _insertNotif($db, $tid, 'alerte', "🚨 Vignette expirée — $label", "Expirée depuis le " . date('d/m/Y', strtotime($exp)), $urlVeh);
                $log[] = "⚠ Vignette EXPIRÉE: $label";
                $nbAlertes++;
            }
        } elseif ($exp <= $in15) {
            $exists = $db->prepare("SELECT id FROM notifs_push WHERE tenant_id=? AND type='alerte' AND titre LIKE ? AND DATE(created_at)=CURDATE() LIMIT 1");
            $exists->execute([$tid, "%vignette%{$v['immatriculation']}%"]);
            if (!$exists->fetchColumn()) {
                $days = (int)((strtotime($exp) - strtotime($today)) / 86400);
                _insertNotif($db, $tid, 'alerte', "⚠️ Vignette expire dans {$days}j — $label", "Date limite: " . date('d/m/Y', strtotime($exp)), $urlVeh);
                $log[] = "⚠ Vignette dans {$days}j: $label";
                $nbAlertes++;
            }
        }
    }
}

$log[] = "✅ Terminé — $nbAlertes alerte(s) générée(s)";
$log[] = '[' . date('Y-m-d H:i:s') . '] Fin';

$output = implode(PHP_EOL, $log) . PHP_EOL;

if ($isCli) {
    echo $output;
} else {
    header('Content-Type: application/json');
    echo json_encode(['log' => $log, 'alertes' => $nbAlertes]);
}
