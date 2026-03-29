<?php
/**
 * FlotteCar - Classe TraccarAPI
 * Interface complète avec le serveur de tracking Traccar via son API REST
 *
 * @version 2.0.0
 */

class TraccarAPI
{
    /** @var string URL de base de l'API Traccar */
    private string $baseUrl;

    /** @var string Email de connexion Traccar */
    private string $user;

    /** @var string Mot de passe Traccar */
    private string $pass;

    /** @var string Fichier cookie pour les sessions cURL */
    private string $cookieFile;

    /** @var bool Indique si la connexion est établie */
    private bool $connected = false;

    /** @var int Timeout cURL en secondes */
    private int $timeout = 5;

    // ----------------------------------------------------------
    public function __construct()
    {
        $this->baseUrl    = defined('TRACCAR_URL')  ? TRACCAR_URL  : 'http://localhost:8082/api';
        $this->user       = defined('TRACCAR_USER') ? TRACCAR_USER : 'mabea';
        $this->pass       = defined('TRACCAR_PASS') ? TRACCAR_PASS : '07459376ab@';
        $this->cookieFile = sys_get_temp_dir() . '/traccar_session_' . md5($this->baseUrl . $this->user) . '.txt';
    }

    // ----------------------------------------------------------
    // DISPONIBILITÉ
    // ----------------------------------------------------------

    /**
     * Vérifie si le serveur Traccar est accessible
     */
    public function isAvailable(): bool
    {
        try {
            $ch = $this->createCurl('/server', false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            $result = curl_exec($ch);
            $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $result !== false && $code < 500;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ----------------------------------------------------------
    // CONNEXION (session cookie)
    // ----------------------------------------------------------

    /**
     * Établit la session avec Traccar via POST /session
     */
    private function connect(): void
    {
        if ($this->connected) return;

        $ch = curl_init($this->baseUrl . '/session');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'email'    => $this->user,
                'password' => $this->pass,
            ]),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new RuntimeException('Traccar: échec connexion (HTTP ' . $httpCode . ')');
        }

        $this->connected = true;
    }

    // ----------------------------------------------------------
    // MÉTHODES HTTP PRIVÉES
    // ----------------------------------------------------------

    /**
     * Crée un handle cURL configuré
     */
    private function createCurl(string $endpoint, bool $withCookies = true): \CurlHandle
    {
        $ch = curl_init($this->baseUrl . $endpoint);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ];
        if ($withCookies) {
            $opts[CURLOPT_COOKIEFILE] = $this->cookieFile;
            $opts[CURLOPT_COOKIEJAR]  = $this->cookieFile;
        }
        curl_setopt_array($ch, $opts);
        return $ch;
    }

    /**
     * Requête GET
     */
    public function get(string $endpoint): mixed
    {
        $this->connect();
        $ch       = $this->createCurl($endpoint);
        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $code < 200 || $code >= 300) return null;
        return json_decode($response, true);
    }

    /**
     * Requête POST
     */
    private function post(string $endpoint, array $data): mixed
    {
        $this->connect();
        $ch = $this->createCurl($endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $code < 200 || $code >= 300) return null;
        return json_decode($response, true);
    }

    /**
     * Requête PUT
     */
    private function put(string $endpoint, array $data): mixed
    {
        $this->connect();
        $ch = $this->createCurl($endpoint);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $code < 200 || $code >= 300) return null;
        return json_decode($response, true);
    }

    /**
     * Requête DELETE
     */
    private function delete(string $endpoint): mixed
    {
        $this->connect();
        $ch = $this->createCurl($endpoint);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code >= 200 && $code < 300;
    }

    // ----------------------------------------------------------
    // DEVICES (véhicules/appareils GPS)
    // ----------------------------------------------------------

    /**
     * Récupère tous les appareils GPS
     * @return array Liste des devices Traccar
     */
    public function getDevices(): array
    {
        try {
            $result = $this->get('/devices');
            return is_array($result) ? $result : [];
        } catch (Throwable $e) {
            error_log('TraccarAPI::getDevices - ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Récupère un appareil par son ID
     */
    public function getDevice(int $id): ?array
    {
        try {
            $result = $this->get('/devices?id=' . $id);
            if (is_array($result) && !empty($result)) {
                return $result[0];
            }
            return null;
        } catch (Throwable $e) {
            error_log('TraccarAPI::getDevice - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Crée un nouvel appareil GPS dans Traccar
     * @param string $name      Nom de l'appareil
     * @param string $uniqueId  Identifiant unique (IMEI, etc.)
     */
    public function createDevice(string $name, string $uniqueId): ?array
    {
        try {
            return $this->post('/devices', [
                'name'     => $name,
                'uniqueId' => $uniqueId,
            ]);
        } catch (Throwable $e) {
            error_log('TraccarAPI::createDevice - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Met à jour un appareil GPS
     * @param int   $id   ID Traccar du device
     * @param array $data Données à mettre à jour
     */
    public function updateDevice(int $id, array $data): ?array
    {
        try {
            $data['id'] = $id;
            return $this->put('/devices/' . $id, $data);
        } catch (Throwable $e) {
            error_log('TraccarAPI::updateDevice - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Supprime un appareil GPS
     */
    public function deleteDevice(int $id): bool
    {
        try {
            return (bool)$this->delete('/devices/' . $id);
        } catch (Throwable $e) {
            error_log('TraccarAPI::deleteDevice - ' . $e->getMessage());
            return false;
        }
    }

    // ----------------------------------------------------------
    // POSITIONS GPS
    // ----------------------------------------------------------

    /**
     * Récupère la dernière position connue d'un device
     */
    public function getPosition(int $deviceId): ?array
    {
        try {
            $result = $this->get('/positions?deviceId=' . $deviceId . '&limit=1');
            if (is_array($result) && !empty($result)) {
                return $result[0];
            }
            return null;
        } catch (Throwable $e) {
            error_log('TraccarAPI::getPosition - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Récupère les dernières positions de plusieurs devices
     * @param array $deviceIds Liste d'IDs de devices
     * @return array Tableau indexé par deviceId
     */
    public function getPositions(array $deviceIds): array
    {
        if (empty($deviceIds)) return [];
        try {
            $params = implode('&', array_map(fn($id) => 'deviceId=' . (int)$id, $deviceIds));
            $result = $this->get('/positions?' . $params);
            if (!is_array($result)) return [];

            // Indexer par deviceId (garder le plus récent)
            $positions = [];
            foreach ($result as $pos) {
                $did = $pos['deviceId'] ?? null;
                if ($did) $positions[$did] = $pos;
            }
            return $positions;
        } catch (Throwable $e) {
            error_log('TraccarAPI::getPositions - ' . $e->getMessage());
            return [];
        }
    }

    // ----------------------------------------------------------
    // RAPPORTS
    // ----------------------------------------------------------

    /**
     * Récupère les trajets d'un véhicule sur une période
     * @param int    $deviceId ID du device Traccar
     * @param string $from     Date/heure ISO 8601 (ex: 2024-01-01T00:00:00.000Z)
     * @param string $to       Date/heure ISO 8601
     */
    public function getTrips(int $deviceId, string $from, string $to): array
    {
        try {
            $params = http_build_query([
                'deviceId' => $deviceId,
                'from'     => $from,
                'to'       => $to,
            ]);
            $result = $this->get('/reports/trips?' . $params);
            return is_array($result) ? $result : [];
        } catch (Throwable $e) {
            error_log('TraccarAPI::getTrips - ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Récupère les événements GPS d'un véhicule
     * @param int         $deviceId
     * @param string|null $from     Optionnel - Date ISO 8601
     * @param string|null $to       Optionnel - Date ISO 8601
     */
    public function getEvents(int $deviceId, ?string $from = null, ?string $to = null): array
    {
        try {
            $query = ['deviceId' => $deviceId];
            if ($from) $query['from'] = $from;
            if ($to)   $query['to']   = $to;

            $result = $this->get('/reports/events?' . http_build_query($query));
            return is_array($result) ? $result : [];
        } catch (Throwable $e) {
            error_log('TraccarAPI::getEvents - ' . $e->getMessage());
            return [];
        }
    }

    // ----------------------------------------------------------
    // COMMANDES (contrôle moteur)
    // ----------------------------------------------------------

    /**
     * Coupe le moteur d'un véhicule à distance
     */
    public function stopEngine(int $deviceId): bool
    {
        try {
            $result = $this->post('/commands/send', [
                'deviceId' => $deviceId,
                'type'     => 'engineStop',
            ]);
            return $result !== null;
        } catch (Throwable $e) {
            error_log('TraccarAPI::stopEngine - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Envoie une commande personnalisée à un device
     */
    public function sendCommand(array $payload): mixed
    {
        try {
            return $this->post('/commands/send', $payload);
        } catch (Throwable $e) {
            error_log('TraccarAPI::sendCommand - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Réactive le moteur d'un véhicule à distance
     */
    public function resumeEngine(int $deviceId): bool
    {
        try {
            $result = $this->post('/commands/send', [
                'deviceId' => $deviceId,
                'type'     => 'engineResume',
            ]);
            return $result !== null;
        } catch (Throwable $e) {
            error_log('TraccarAPI::resumeEngine - ' . $e->getMessage());
            return false;
        }
    }

    // ----------------------------------------------------------
    // GÉOCODAGE INVERSE (OpenStreetMap Nominatim - gratuit)
    // ----------------------------------------------------------

    /**
     * Convertit des coordonnées GPS en adresse lisible
     * Utilise l'API Nominatim d'OpenStreetMap (gratuit, sans clé)
     *
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @return string Adresse formatée ou chaîne vide si erreur
     */
    public function reverseGeocode(float $lat, float $lng): string
    {
        // Cache simple en session
        $cacheKey = 'geocode_' . round($lat, 4) . '_' . round($lng, 4);
        if (isset($_SESSION[$cacheKey])) {
            return $_SESSION[$cacheKey];
        }

        $url = sprintf(
            'https://nominatim.openstreetmap.org/reverse?format=json&lat=%f&lon=%f&zoom=16&addressdetails=1',
            $lat, $lng
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_USERAGENT      => 'FlotteCar/2.0 (contact@flottecar.ci)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Accept-Language: fr'],
        ]);

        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $code !== 200) {
            return sprintf('%.5f, %.5f', $lat, $lng);
        }

        $data = json_decode($response, true);
        $address = $data['display_name'] ?? '';

        if (empty($address)) {
            return sprintf('%.5f, %.5f', $lat, $lng);
        }

        // Simplifier l'adresse (garder les 3 premiers éléments)
        $parts  = explode(', ', $address);
        $result = implode(', ', array_slice($parts, 0, 3));

        // Mettre en cache (1h max, on utilise la session)
        $_SESSION[$cacheKey] = $result;

        return $result;
    }

    // ----------------------------------------------------------
    // UTILITAIRES
    // ----------------------------------------------------------

    /**
     * Convertit une vitesse de km/h vers nœuds (format Traccar)
     */
    public static function knotsToKmh(float $knots): float
    {
        return round($knots * 1.852, 1);
    }

    /**
     * Formate une date ISO 8601 pour l'API Traccar
     */
    public static function toTraccarDate(\DateTime $dt): string
    {
        return $dt->format('Y-m-d\TH:i:s.000\Z');
    }

    /**
     * Calcule la distance entre deux points GPS (formule Haversine, en km)
     */
    public static function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R   = 6371; // Rayon de la Terre en km
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a   = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c   = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return round($R * $c, 3);
    }

    /**
     * Réinitialise la session Traccar (supprime le cookie)
     */
    public function resetSession(): void
    {
        $this->connected = false;
        if (file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }
}
