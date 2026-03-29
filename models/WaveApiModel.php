<?php
/**
 * FlotteCar — Wave Business API Model
 * Doc: https://docs.wave.com/business/
 * Auth: Bearer token (API Key)
 */
class WaveApiModel {
    private PDO $db;
    private int $tenantId;
    private array $config = [];

    const BASE_URL = 'https://api.wave.com/v1/';
    const CURRENCY = 'XOF';

    public function __construct(PDO $db, int $tenantId) {
        $this->db = $db;
        $this->tenantId = $tenantId;
        $this->loadConfig();
    }

    private function loadConfig(): void {
        $r = $this->db->prepare("SELECT cle, valeur FROM parametres WHERE tenant_id=? AND cle LIKE 'wave_%'");
        $r->execute([$this->tenantId]);
        foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row)
            $this->config[$row['cle']] = $row['valeur'];
    }

    public function isConfigured(): bool {
        return !empty($this->config['wave_api_key']) && !empty($this->config['wave_active']) && $this->config['wave_active'] === '1';
    }

    public function getApiKey(): string { return $this->config['wave_api_key'] ?? ''; }
    public function getWebhookSecret(): string { return $this->config['wave_webhook_secret'] ?? ''; }
    public function getMerchantNumber(): string { return $this->config['wave_merchant_number'] ?? ''; }

    /**
     * Créer un lien de paiement Wave Checkout
     * @return array|null ['checkout_status','wave_launch_url','id','client_reference'] ou null si erreur
     */
    public function createCheckoutLink(float $amount, string $clientReference, string $successUrl = '', string $errorUrl = ''): ?array {
        if (!$this->isConfigured()) return null;

        $payload = [
            'amount'           => (int)$amount,
            'currency'         => self::CURRENCY,
            'client_reference' => $clientReference,
            'success_url'      => $successUrl ?: BASE_URL . 'app/taximetres/wave_success.php',
            'error_url'        => $errorUrl   ?: BASE_URL . 'app/taximetres/wave_error.php',
        ];

        return $this->request('POST', 'checkout/sessions', $payload);
    }

    /**
     * Récupérer le statut d'une transaction
     */
    public function getTransaction(string $checkoutSessionId): ?array {
        return $this->request('GET', 'checkout/sessions/' . urlencode($checkoutSessionId));
    }

    /**
     * Vérifier la signature d'un webhook Wave
     * Wave envoie: header "Wave-Signature: t=...,v1=..."
     */
    public function verifyWebhookSignature(string $rawPayload, string $signatureHeader): bool {
        $secret = $this->getWebhookSecret();
        if (!$secret) return false;

        // Parse "t=1234567890,v1=abc123..."
        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$k, $v] = explode('=', $part, 2);
            $parts[$k] = $v;
        }
        if (empty($parts['t']) || empty($parts['v1'])) return false;

        $expectedSig = hash_hmac('sha256', $parts['t'] . '.' . $rawPayload, $secret);
        return hash_equals($expectedSig, $parts['v1']);
    }

    /**
     * Identifier un taximantre par son numéro de téléphone
     * Normalise les formats: +2250777698775, 0777698775, 00225777698775
     */
    public function findTaximetreByPhone(string $phone): ?array {
        // Normaliser: garder les 9 derniers chiffres
        $phone = preg_replace('/\D/', '', $phone);
        $phone = substr($phone, -9); // 9 derniers chiffres

        $r = $this->db->prepare("
            SELECT tx.*, v.immatriculation
            FROM taximetres tx
            JOIN vehicules v ON v.id = tx.vehicule_id
            WHERE tx.tenant_id = ? AND tx.statut = 'actif'
            AND RIGHT(REGEXP_REPLACE(telephone, '[^0-9]', ''), 9) = ?
        ");
        $r->execute([$this->tenantId, $phone]);
        return $r->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Imputer un montant reçu aux jours non payés (du plus ancien au plus récent)
     * @return array ['jours_payes' => int, 'solde_restant' => float, 'details' => []]
     */
    public function imputerPaiement(int $taximetreId, float $montantRecu, string $modeWave = 'wave', string $waveRef = ''): array {
        // Récupérer les jours non payés, du plus ancien au plus récent
        $r = $this->db->prepare("
            SELECT * FROM paiements_taxi
            WHERE taximetre_id = ? AND tenant_id = ? AND statut_jour = 'non_paye'
            ORDER BY date_paiement ASC
        ");
        $r->execute([$taximetreId, $this->tenantId]);
        $joursNonPaies = $r->fetchAll(PDO::FETCH_ASSOC);

        // Si aucun jour en dette, chercher le taximetre pour son tarif
        if (empty($joursNonPaies)) {
            $rt = $this->db->prepare("SELECT * FROM taximetres WHERE id=? AND tenant_id=?");
            $rt->execute([$taximetreId, $this->tenantId]);
            $tx = $rt->fetch(PDO::FETCH_ASSOC);
            return ['jours_payes' => 0, 'solde_restant' => $montantRecu, 'details' => [], 'taximetre' => $tx];
        }

        $solde = $montantRecu;
        $joursPaies = 0;
        $details = [];

        foreach ($joursNonPaies as $jour) {
            if ($solde <= 0) break;

            $montantDu = (float)($jour['montant_du'] > 0 ? $jour['montant_du'] : $jour['montant']);
            if ($montantDu <= 0) {
                // Récupérer le tarif du taximetre
                $rt = $this->db->prepare("SELECT tarif_journalier FROM taximetres WHERE id=?");
                $rt->execute([$taximetreId]);
                $montantDu = (float)($rt->fetchColumn() ?: 0);
            }

            if ($solde >= $montantDu && $montantDu > 0) {
                // Payer ce jour entièrement
                $this->db->prepare("
                    INSERT INTO paiements_taxi (tenant_id, taximetre_id, date_paiement, montant, statut_jour, mode_paiement, notes, created_at)
                    VALUES (?,?,?,?,'paye',?,?,NOW())
                    ON DUPLICATE KEY UPDATE montant=VALUES(montant), statut_jour='paye', mode_paiement=VALUES(mode_paiement), notes=VALUES(notes)
                ")->execute([
                    $this->tenantId, $taximetreId, $jour['date_paiement'],
                    $montantDu, $modeWave,
                    'Paiement Wave auto — Réf: ' . $waveRef
                ]);
                $solde -= $montantDu;
                $joursPaies++;
                $details[] = ['date' => $jour['date_paiement'], 'montant' => $montantDu, 'statut' => 'paye'];
            } else {
                // Paiement partiel — marquer le jour avec le montant reçu mais ne pas clore
                break;
            }
        }

        return ['jours_payes' => $joursPaies, 'solde_restant' => $solde, 'details' => $details];
    }

    /**
     * Requête HTTP vers l'API Wave
     */
    private function request(string $method, string $endpoint, array $data = []): ?array {
        $url = self::BASE_URL . $endpoint;
        $headers = [
            'Authorization: Bearer ' . $this->getApiKey(),
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || !$response) {
            error_log("[WaveAPI] cURL error: $error");
            return null;
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            error_log("[WaveAPI] HTTP $httpCode: $response");
            return null;
        }

        return $decoded;
    }

    /**
     * Sauvegarder une transaction Wave dans les logs
     */
    public function logTransaction(int $taximetreId, string $waveRef, float $montant, array $imputationResult): void {
        $detail = json_encode([
            'wave_ref'     => $waveRef,
            'montant_recu' => $montant,
            'jours_payes'  => $imputationResult['jours_payes'],
            'solde_restant'=> $imputationResult['solde_restant'],
            'details'      => $imputationResult['details'] ?? [],
        ]);
        $this->db->prepare("INSERT INTO logs_activites (tenant_id, user_id, action, module, description, created_at) VALUES (?,0,'paiement_wave','taximetres',?,NOW())")
            ->execute([$this->tenantId, $detail]);
    }
}
