<?php
/**
 * FlotteCar — Moteur de vérification des règles GPS
 * CRON : toutes les 10 minutes
 * CLI  : php api/check_regles.php
 * HTTP : GET api/check_regles.php?key=flottecar_maint_2026
 */
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/includes/TraccarAPI.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    session_start();
    $key = $_GET['key'] ?? '';
    if ($key !== CRON_KEY) { http_response_code(403); die('Forbidden'); }
}

$db  = (new Database())->getConnection();
$log = [];
$t0  = microtime(true);

function clog(string $msg) { global $log; $log[] = date('H:i:s') . ' ' . $msg; echo $msg . "\n"; }

clog("=== check_regles.php démarré ===");

// ── Traccar disponible ? ──────────────────────────────────────────────────────
$traccar   = new TraccarAPI();
$traccarOk = $traccar->isAvailable();
clog("Traccar : " . ($traccarOk ? "EN LIGNE" : "HORS LIGNE"));

// ── Charger toutes les règles actives (globales + par véhicule) ───────────────
// Jointure pour récupérer le véhicule (tenant_id, traccar_device_id, km, dates)
$regleStmt = $db->query("
    SELECT r.*,
           v.id          AS v_id,
           v.nom         AS v_nom,
           v.immatriculation,
           v.tenant_id   AS v_tenant,
           v.traccar_device_id,
           v.date_expiration_assurance,
           v.date_expiration_vignette,
           v.prochaine_vidange_km
    FROM regles_gps r
    JOIN vehicules v ON (
        (r.vehicule_id IS NULL AND v.tenant_id = r.tenant_id)
        OR (r.vehicule_id = v.id)
    )
    WHERE r.actif = 1
      AND v.statut != 'indisponible'
    ORDER BY r.vehicule_id DESC, r.type_regle
");
$toutes = $regleStmt->fetchAll(PDO::FETCH_ASSOC);

// Dédupliquer : règle spécifique > règle globale (même type + même véhicule)
$reglesParVeh = [];
foreach ($toutes as $r) {
    $vid  = $r['v_id'];
    $type = $r['type_regle'];
    // règle spécifique (vehicule_id non null) prend priorité sur globale
    if (!isset($reglesParVeh[$vid][$type]) || $r['vehicule_id'] !== null) {
        $reglesParVeh[$vid][$type] = $r;
    }
}

clog("Règles à vérifier : " . count($toutes) . " (après déduplique : véhicules × types)");

// ── Charger les positions Traccar (batch) ─────────────────────────────────────
$positions = [];
if ($traccarOk) {
    $deviceIds = array_unique(array_filter(array_map(
        fn($r) => $r['traccar_device_id'],
        $toutes
    )));
    if ($deviceIds) {
        try {
            $pos = $traccar->getPositions($deviceIds);
            $positions = $pos; // indexé par deviceId
        } catch (Throwable $e) {
            clog("ERREUR positions Traccar: " . $e->getMessage());
        }
    }
}

// ── Helper : insérer une alerte (avec cooldown anti-spam) ────────────────────
function insertAlerte(PDO $db, int $tenantId, int $vehiculeId, ?int $regleId,
                      string $type, string $message, string $valeur = '', int $cooldownMin = 60): bool
{
    // Vérifier si une alerte du même type existe déjà dans la fenêtre cooldown
    $check = $db->prepare("
        SELECT id FROM alertes_regles
        WHERE tenant_id=? AND vehicule_id=? AND type_alerte=?
          AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
        LIMIT 1
    ");
    $check->execute([$tenantId, $vehiculeId, $type, $cooldownMin]);
    if ($check->fetch()) return false; // déjà alerté récemment

    $db->prepare("
        INSERT INTO alertes_regles (tenant_id, vehicule_id, regle_id, type_alerte, message, valeur_declencheur)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$tenantId, $vehiculeId, $regleId, $type, $message, $valeur]);
    return true;
}

// ── Haversine distance (km) ───────────────────────────────────────────────────
function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371;
    $dLat = deg2rad($lat2-$lat1); $dLng = deg2rad($lng2-$lng1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLng/2)**2;
    return round($R * 2 * atan2(sqrt($a), sqrt(1-$a)), 3);
}

$nbAlertes = 0;
$now = new DateTime('now', new DateTimeZone('Africa/Abidjan'));
$heureNow = (int)$now->format('H') * 60 + (int)$now->format('i'); // minutes depuis minuit
$jourNow  = strtolower(['dim','lun','mar','mer','jeu','ven','sam'][$now->format('w')]);

// ── Boucle principale ─────────────────────────────────────────────────────────
foreach ($reglesParVeh as $vehiculeId => $regles) {
    foreach ($regles as $type => $r) {
        $tenantId   = (int)$r['v_tenant'];
        $regleId    = (int)$r['id'];
        $nom        = $r['v_nom'] . ' (' . $r['immatriculation'] . ')';
        $deviceId   = (int)$r['traccar_device_id'];
        $params     = json_decode($r['params'], true) ?: [];
        $pos        = $positions[$deviceId] ?? null;
        $vitesse    = $pos ? round(($pos['speed'] ?? 0) * 1.852) : 0; // nœuds → km/h
        $lastFix    = $pos ? strtotime($pos['fixTime'] ?? '') : null;
        $minutesSansSignal = $lastFix ? (int)((time() - $lastFix) / 60) : 9999;

        switch ($type) {

            // ── 1. Plage horaire ──────────────────────────────────────────────
            case 'horaire':
                if (!$pos || !$lastFix) break;
                $debut = explode(':', $params['heure_debut'] ?? '05:00');
                $fin   = explode(':', $params['heure_fin']   ?? '23:00');
                $mDebut = (int)$debut[0]*60 + (int)$debut[1];
                $mFin   = (int)$fin[0]*60   + (int)$fin[1];
                $jours  = $params['jours'] ?? ['lun','mar','mer','jeu','ven','sam'];

                // Signal reçu récemment (< 15 min) ET hors plage
                if ($minutesSansSignal < 15 && $vitesse > 1) {
                    $horsPlage = ($heureNow < $mDebut || $heureNow > $mFin);
                    $horsJour  = !in_array($jourNow, $jours);
                    if ($horsPlage || $horsJour) {
                        $msg = "🚨 $nom : mouvement détecté hors des heures autorisées ({$now->format('H:i')} — autorisé {$params['heure_debut']} à {$params['heure_fin']})";
                        if (insertAlerte($db, $tenantId, $vehiculeId, $regleId, 'horaire', $msg, $now->format('H:i'), 120)) {
                            clog("ALERTE horaire — $nom"); $nbAlertes++;
                        }
                    }
                }
                break;

            // ── 2. Vitesse maximale ───────────────────────────────────────────
            case 'vitesse':
                if (!$pos || !$lastFix || $minutesSansSignal > 5) break;
                $vMax = (int)($params['vitesse_max'] ?? 100);
                if ($vitesse > $vMax) {
                    $msg = "⚡ $nom : vitesse excessive — {$vitesse} km/h (limite : {$vMax} km/h)";
                    if (insertAlerte($db, $tenantId, $vehiculeId, $regleId, 'vitesse', $msg, "{$vitesse}km/h", 30)) {
                        clog("ALERTE vitesse — $nom : {$vitesse}km/h"); $nbAlertes++;
                    }
                }
                break;

            // ── 3. Alerte vidange ─────────────────────────────────────────────
            case 'vidange':
                if (!$pos) break;
                $kmActuel = isset($pos['attributes']['totalDistance'])
                    ? round($pos['attributes']['totalDistance'] / 1000) : 0;
                $kmProchaine = (int)($r['prochaine_vidange_km'] ?? 0);
                if ($kmProchaine > 0 && $kmActuel > 0 && $kmActuel >= ($kmProchaine - 500)) {
                    $restant = $kmProchaine - $kmActuel;
                    $msg = $restant <= 0
                        ? "🔧 $nom : vidange dépassée ! (compteur : {$kmActuel} km, prévu : {$kmProchaine} km)"
                        : "🔧 $nom : vidange dans {$restant} km (compteur : {$kmActuel} km)";
                    if (insertAlerte($db, $tenantId, $vehiculeId, $regleId, 'vidange', $msg, "{$kmActuel}km", 1440)) {
                        clog("ALERTE vidange — $nom"); $nbAlertes++;
                    }
                }
                break;

            // ── 4. Assurance / vignette ───────────────────────────────────────
            case 'assurance':
                $joursAvant = (int)($params['jours_avant'] ?? 30);
                $limite = new DateTime("+{$joursAvant} days");
                foreach (['date_expiration_assurance' => 'assurance', 'date_expiration_vignette' => 'vignette'] as $col => $label) {
                    if (empty($r[$col])) continue;
                    $expiration = new DateTime($r[$col]);
                    if ($expiration <= $limite) {
                        $jRestants = (int)$now->diff($expiration)->days;
                        $msg = $jRestants <= 0
                            ? "📋 $nom : {$label} EXPIRÉE depuis " . abs($jRestants) . " jour(s) !"
                            : "📋 $nom : {$label} expire dans {$jRestants} jour(s) ({$expiration->format('d/m/Y')})";
                        if (insertAlerte($db, $tenantId, $vehiculeId, $regleId, "assurance_{$label}", $msg, $expiration->format('d/m/Y'), 1440)) {
                            clog("ALERTE {$label} — $nom"); $nbAlertes++;
                        }
                    }
                }
                break;

            // ── 5. Coupure GPS ────────────────────────────────────────────────
            case 'coupure_gps':
                if (!$deviceId) break;
                // Hors ligne depuis > 30 min pendant les heures de travail (6h-22h)
                if ($heureNow >= 360 && $heureNow <= 1320 && $minutesSansSignal > 30 && $minutesSansSignal < 120) {
                    $msg = "📡 $nom : perte de signal GPS depuis {$minutesSansSignal} minutes";
                    if (insertAlerte($db, $tenantId, $vehiculeId, $regleId, 'coupure_gps', $msg, "{$minutesSansSignal}min", 90)) {
                        clog("ALERTE coupure GPS — $nom"); $nbAlertes++;
                    }
                }
                break;

            // ── 6. Immobilisation longue ──────────────────────────────────────
            case 'immobilisation':
                if (!$pos || !$lastFix) break;
                $dureeH = (int)($params['duree_min'] ?? 3);
                $debut  = explode(':', $params['heure_debut'] ?? '06:00');
                $fin    = explode(':', $params['heure_fin']   ?? '22:00');
                $mDebut = (int)$debut[0]*60 + (int)$debut[1];
                $mFin   = (int)$fin[0]*60   + (int)$fin[1];

                if ($heureNow >= $mDebut && $heureNow <= $mFin) {
                    $heuresSansSignal = $minutesSansSignal / 60;
                    if ($vitesse == 0 && $heuresSansSignal >= $dureeH) {
                        $msg = "🅿 $nom : immobile depuis " . round($heuresSansSignal, 1) . "h (règle : max {$dureeH}h pendant les heures de travail)";
                        if (insertAlerte($db, $tenantId, $vehiculeId, $regleId, 'immobilisation', $msg, round($heuresSansSignal,1).'h', 120)) {
                            clog("ALERTE immobilisation — $nom"); $nbAlertes++;
                        }
                    }
                }
                break;

            // ── 7. Kilométrage journalier max ─────────────────────────────────
            case 'km_jour':
                if (!$deviceId || !$traccarOk) break;
                $kmMax = (int)($params['km_max_jour'] ?? 300);
                try {
                    $from  = (new DateTime('today', new DateTimeZone('Africa/Abidjan')))->format('Y-m-d\T00:00:00.000\Z');
                    $to    = (new DateTime('now',   new DateTimeZone('Africa/Abidjan')))->format('Y-m-d\TH:i:s.000\Z');
                    $trips = $traccar->getTrips($deviceId, $from, $to);
                    $kmJour = 0;
                    foreach ($trips as $trip) {
                        $kmJour += ($trip['distance'] ?? 0) / 1000;
                    }
                    $kmJour = round($kmJour);
                    if ($kmJour > $kmMax) {
                        $msg = "🛣 $nom : {$kmJour} km aujourd'hui (limite : {$kmMax} km/jour)";
                        if (insertAlerte($db, $tenantId, $vehiculeId, $regleId, 'km_jour', $msg, "{$kmJour}km", 240)) {
                            clog("ALERTE km/jour — $nom : {$kmJour}km"); $nbAlertes++;
                        }
                    }
                } catch (Throwable $e) {}
                break;

            // ── 8. Zone géographique (geofence) ───────────────────────────────
            case 'geofence':
                if (!$pos || !$lastFix || $minutesSansSignal > 15) break;
                $lat    = (float)($pos['latitude']  ?? 0);
                $lng    = (float)($pos['longitude'] ?? 0);
                $cLat   = (float)($params['lat']      ?? 5.3484);
                $cLng   = (float)($params['lng']      ?? -4.0120);
                $rayon  = (float)($params['rayon_km'] ?? 50);
                $zoneNom = $params['zone_nom'] ?? 'Zone autorisée';
                $dist   = haversine($lat, $lng, $cLat, $cLng);
                if ($dist > $rayon) {
                    $msg = "🗺 $nom : hors zone «{$zoneNom}» — à {$dist} km du centre (rayon autorisé : {$rayon} km)";
                    if (insertAlerte($db, $tenantId, $vehiculeId, $regleId, 'geofence', $msg, "{$dist}km", 60)) {
                        clog("ALERTE geofence — $nom : {$dist}km hors zone"); $nbAlertes++;
                    }
                }
                break;

            // ── 9. Ralenti (moteur ON + vitesse=0) ───────────────────────────
            case 'ralenti':
                if (!$pos || !$lastFix || $minutesSansSignal > 20) break;
                $dureeMax = (int)($params['duree_min'] ?? 15);
                // Vérifie via l'ignition attribute de Traccar
                $ignition = $pos['attributes']['ignition'] ?? null;
                if ($ignition === true && $vitesse == 0) {
                    // Vérifier depuis combien de temps (besoin d'un état précédent)
                    $check = $db->prepare("
                        SELECT created_at FROM alertes_regles
                        WHERE vehicule_id=? AND type_alerte='ralenti_debut' AND created_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)
                        ORDER BY created_at DESC LIMIT 1
                    ");
                    $check->execute([$vehiculeId]);
                    $debutRalenti = $check->fetchColumn();

                    if (!$debutRalenti) {
                        // Premier enregistrement du début de ralenti
                        $db->prepare("INSERT INTO alertes_regles (tenant_id,vehicule_id,regle_id,type_alerte,message,valeur_declencheur,lu)
                            VALUES (?,?,?,'ralenti_debut','Début ralenti détecté','',1)")->execute([$tenantId,$vehiculeId,$regleId]);
                    } else {
                        $minutesRalenti = (int)((time() - strtotime($debutRalenti)) / 60);
                        if ($minutesRalenti >= $dureeMax) {
                            $msg = "⛽ $nom : moteur en ralenti depuis {$minutesRalenti} minutes (limite : {$dureeMax} min)";
                            if (insertAlerte($db, $tenantId, $vehiculeId, $regleId, 'ralenti', $msg, "{$minutesRalenti}min", 60)) {
                                clog("ALERTE ralenti — $nom : {$minutesRalenti}min"); $nbAlertes++;
                            }
                        }
                    }
                }
                break;

            // ── 10. Nombre de trajets / jour ──────────────────────────────────
            case 'trajets_jour':
                if (!$deviceId || !$traccarOk) break;
                $nbMax = (int)($params['nb_max'] ?? 20);
                try {
                    $from  = (new DateTime('today', new DateTimeZone('Africa/Abidjan')))->format('Y-m-d\T00:00:00.000\Z');
                    $to    = (new DateTime('now',   new DateTimeZone('Africa/Abidjan')))->format('Y-m-d\TH:i:s.000\Z');
                    $trips = $traccar->getTrips($deviceId, $from, $to);
                    $nb    = count($trips);
                    if ($nb > $nbMax) {
                        $msg = "🔢 $nom : {$nb} trajets effectués aujourd'hui (limite : {$nbMax})";
                        if (insertAlerte($db, $tenantId, $vehiculeId, $regleId, 'trajets_jour', $msg, "{$nb} trajets", 180)) {
                            clog("ALERTE trajets/jour — $nom : {$nb}"); $nbAlertes++;
                        }
                    }
                } catch (Throwable $e) {}
                break;
        }
    }
}

$duree = round((microtime(true) - $t0) * 1000);
clog("=== Terminé en {$duree}ms — {$nbAlertes} alerte(s) générée(s) ===");

// Réponse HTTP JSON si appelé via URL
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'alertes' => $nbAlertes, 'duree_ms' => $duree, 'log' => $log]);
    exit;
}
