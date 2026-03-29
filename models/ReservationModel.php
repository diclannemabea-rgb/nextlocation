<?php

declare(strict_types=1);

class ReservationModel
{
    public function __construct(private PDO $db) {}

    // -------------------------------------------------------------------------
    // READ
    // -------------------------------------------------------------------------

    public function getAll(int $tenantId, array $filters = []): array
    {
        $sql = 'SELECT r.*,
                       v.nom           AS vehicule_nom,
                       v.immatriculation,
                       c.nom           AS client_nom,
                       c.prenom        AS client_prenom,
                       c.telephone     AS client_telephone
                FROM   reservations r
                JOIN   vehicules v ON v.id = r.vehicule_id
                JOIN   clients   c ON c.id = r.client_id
                WHERE  r.tenant_id = ?';
        $params = [$tenantId];

        if (!empty($filters['statut'])) {
            $sql      .= ' AND r.statut = ?';
            $params[]  = $filters['statut'];
        }
        if (!empty($filters['vehicule_id'])) {
            $sql      .= ' AND r.vehicule_id = ?';
            $params[]  = (int) $filters['vehicule_id'];
        }
        if (!empty($filters['client_id'])) {
            $sql      .= ' AND r.client_id = ?';
            $params[]  = (int) $filters['client_id'];
        }
        if (!empty($filters['date_from'])) {
            $sql      .= ' AND r.date_debut >= ?';
            $params[]  = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql      .= ' AND r.date_fin <= ?';
            $params[]  = $filters['date_to'];
        }

        $sql .= ' ORDER BY r.date_debut ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*,
                    v.nom          AS vehicule_nom,
                    v.immatriculation,
                    v.marque,
                    v.modele,
                    v.prix_location_jour,
                    c.nom          AS client_nom,
                    c.prenom       AS client_prenom,
                    c.telephone    AS client_telephone,
                    c.email        AS client_email,
                    ch.nom         AS chauffeur_nom,
                    ch.prenom      AS chauffeur_prenom,
                    co.nom         AS commercial_nom,
                    co.prenom      AS commercial_prenom
             FROM   reservations r
             JOIN   vehicules    v  ON v.id  = r.vehicule_id
             JOIN   clients      c  ON c.id  = r.client_id
             LEFT JOIN chauffeurs  ch ON ch.id = r.chauffeur_id
             LEFT JOIN commerciaux co ON co.id = r.commercial_id
             WHERE  r.id = ? AND r.tenant_id = ?
             LIMIT  1'
        );
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Paiements liés à une réservation */
    public function getPaiements(int $reservationId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM paiements
             WHERE  reservation_id = ? AND tenant_id = ?
             ORDER  BY created_at ASC'
        );
        $stmt->execute([$reservationId, $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Total encaissé sur une réservation */
    public function getTotalPaye(int $reservationId, int $tenantId): float
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(montant), 0) FROM paiements
             WHERE  reservation_id = ? AND tenant_id = ?'
        );
        $stmt->execute([$reservationId, $tenantId]);
        return (float) $stmt->fetchColumn();
    }

    public function checkDisponibilite(
        int    $vehiculeId,
        int    $tenantId,
        string $dateDebut,
        string $dateFin,
        ?int   $excludeId = null
    ): bool {
        $sql = "SELECT COUNT(*)
                FROM   reservations
                WHERE  tenant_id   = ?
                  AND  vehicule_id = ?
                  AND  statut      NOT IN ('annulee', 'convertie')
                  AND  date_debut  < ?
                  AND  date_fin    > ?";
        $params = [$tenantId, $vehiculeId, $dateFin, $dateDebut];

        if ($excludeId !== null) {
            $sql      .= ' AND id != ?';
            $params[]  = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        if ((int) $stmt->fetchColumn() > 0) {
            return false;
        }

        $sql2 = "SELECT COUNT(*)
                 FROM   locations
                 WHERE  tenant_id   = ?
                   AND  vehicule_id = ?
                   AND  statut      = 'en_cours'
                   AND  date_debut  < ?
                   AND  date_fin    > ?";
        $stmt2 = $this->db->prepare($sql2);
        $stmt2->execute([$tenantId, $vehiculeId, $dateFin, $dateDebut]);
        return (int) $stmt2->fetchColumn() === 0;
    }

    public function getCalendrier(int $tenantId, string $from, string $to): array
    {
        $events = [];

        $stmt = $this->db->prepare(
            "SELECT r.id,
                    CONCAT(c.nom, ' - ', v.nom) AS title,
                    v.nom   AS vehicule_nom,
                    c.nom   AS client_nom,
                    r.date_debut,
                    r.date_fin,
                    r.statut,
                    CASE r.statut
                        WHEN 'en_attente'  THEN '#f59e0b'
                        WHEN 'confirmee'   THEN '#3b82f6'
                        WHEN 'convertie'   THEN '#10b981'
                        WHEN 'annulee'     THEN '#ef4444'
                        ELSE '#6b7280'
                    END AS color,
                    'reservation' AS source_type
             FROM   reservations r
             JOIN   vehicules    v ON v.id = r.vehicule_id
             JOIN   clients      c ON c.id = r.client_id
             WHERE  r.tenant_id = ?
               AND  r.date_debut < ?
               AND  r.date_fin   > ?
               AND  r.statut NOT IN ('annulee', 'convertie')"
        );
        $stmt->execute([$tenantId, $to, $from]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt2 = $this->db->prepare(
            "SELECT l.id,
                    CONCAT(c.nom, ' - ', v.nom) AS title,
                    v.nom  AS vehicule_nom,
                    c.nom  AS client_nom,
                    l.date_debut,
                    l.date_fin,
                    l.statut,
                    '#10b981' AS color,
                    'location' AS source_type
             FROM   locations l
             JOIN   vehicules v ON v.id = l.vehicule_id
             JOIN   clients   c ON c.id = l.client_id
             WHERE  l.tenant_id = ?
               AND  l.date_debut < ?
               AND  l.date_fin   > ?
               AND  l.statut = 'en_cours'"
        );
        $stmt2->execute([$tenantId, $to, $from]);

        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $loc) {
            $events[] = $loc;
        }

        usort($events, fn($a, $b) => strcmp($a['date_debut'], $b['date_debut']));

        return $events;
    }

    // -------------------------------------------------------------------------
    // WRITE
    // -------------------------------------------------------------------------

    /**
     * Crée la réservation et enregistre l'avance dans paiements si > 0.
     * Met à jour les recettes du véhicule en temps réel.
     */
    public function create(int $tenantId, array $data): int
    {
        $avance       = (float) ($data['avance']        ?? 0);
        $montantFinal = (float) ($data['montant_final'] ?? 0);
        $statutPay    = 'non_paye';
        if ($avance >= $montantFinal && $montantFinal > 0) {
            $statutPay = 'solde';
        } elseif ($avance > 0) {
            $statutPay = 'avance';
        }

        $stmt = $this->db->prepare(
            'INSERT INTO reservations (
                tenant_id, vehicule_id, client_id, chauffeur_id, commercial_id,
                date_debut, date_fin, nombre_jours, prix_par_jour,
                montant_total, remise, montant_final, caution, avance,
                lieu_destination, avec_chauffeur, canal_acquisition, notes, statut
            ) VALUES (
                :tenant_id, :vehicule_id, :client_id, :chauffeur_id, :commercial_id,
                :date_debut, :date_fin, :nombre_jours, :prix_par_jour,
                :montant_total, :remise, :montant_final, :caution, :avance,
                :lieu_destination, :avec_chauffeur, :canal_acquisition, :notes, :statut
            )'
        );
        $stmt->execute([
            'tenant_id'         => $tenantId,
            'vehicule_id'       => (int) $data['vehicule_id'],
            'client_id'         => (int) $data['client_id'],
            'chauffeur_id'      => $data['chauffeur_id']      ?? null,
            'commercial_id'     => $data['commercial_id']     ?? null,
            'date_debut'        => $data['date_debut'],
            'date_fin'          => $data['date_fin'],
            'nombre_jours'      => (int) ($data['nombre_jours']   ?? 1),
            'prix_par_jour'     => (float) ($data['prix_par_jour'] ?? 0),
            'montant_total'     => (float) ($data['montant_total'] ?? 0),
            'remise'            => (float) ($data['remise']        ?? 0),
            'montant_final'     => $montantFinal,
            'caution'           => (float) ($data['caution']       ?? 0),
            'avance'            => $avance,
            'lieu_destination'  => $data['lieu_destination']  ?? null,
            'avec_chauffeur'    => (int) ($data['avec_chauffeur']  ?? 0),
            'canal_acquisition' => $data['canal_acquisition']  ?? 'direct',
            'notes'             => $data['notes']              ?? null,
            'statut'            => 'en_attente',
        ]);

        $reservationId = (int) $this->db->lastInsertId();

        // Enregistrer l'avance dans paiements
        if ($avance > 0) {
            $this->_insertPaiement($tenantId, $reservationId, $avance, $data['mode_paiement'] ?? 'especes', 'Avance réservation');
            // Ajouter aux recettes du véhicule
            $this->_addRecettesVehicule((int) $data['vehicule_id'], $tenantId, $avance);
        }

        return $reservationId;
    }

    /**
     * Ajoute un paiement sur une réservation (avant conversion en location).
     * Met à jour le champ avance de la réservation et les recettes du véhicule.
     */
    public function ajouterPaiement(int $id, int $tenantId, float $montant, string $mode, string $notes = ''): bool
    {
        $rsv = $this->getById($id, $tenantId);
        if (!$rsv) return false;

        $this->_insertPaiement($tenantId, $id, $montant, $mode, $notes);
        $this->_addRecettesVehicule((int) $rsv['vehicule_id'], $tenantId, $montant);

        // Mettre à jour avance dans reservations
        $totalPaye = $this->getTotalPaye($id, $tenantId);
        $this->db->prepare(
            'UPDATE reservations SET avance = ? WHERE id = ? AND tenant_id = ?'
        )->execute([$totalPaye, $id, $tenantId]);

        return true;
    }

    public function update(int $id, int $tenantId, array $data): bool
    {
        $allowed = [
            'vehicule_id', 'client_id', 'chauffeur_id', 'commercial_id',
            'date_debut', 'date_fin', 'nombre_jours', 'prix_par_jour',
            'montant_total', 'remise', 'montant_final', 'caution', 'avance',
            'lieu_destination', 'avec_chauffeur', 'canal_acquisition', 'notes',
        ];

        $sets   = [];
        $params = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[]   = "`$col` = ?";
                $params[] = $data[$col];
            }
        }

        if (empty($sets)) return false;

        $params[] = $id;
        $params[] = $tenantId;

        $stmt = $this->db->prepare(
            'UPDATE reservations SET ' . implode(', ', $sets) .
            ' WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Annule la réservation.
     * Cascade : supprime les paiements liés et retranche des recettes du véhicule.
     */
    public function annuler(int $id, int $tenantId, string $motif = ''): bool
    {
        $rsv = $this->getById($id, $tenantId);
        if (!$rsv) return false;

        // Calculer total des avances déjà encaissées
        $totalPaye = $this->getTotalPaye($id, $tenantId);

        // Supprimer les paiements de cette réservation
        $this->db->prepare(
            'DELETE FROM paiements WHERE reservation_id = ? AND tenant_id = ?'
        )->execute([$id, $tenantId]);

        // Retrancher des recettes du véhicule
        if ($totalPaye > 0) {
            $this->_addRecettesVehicule((int) $rsv['vehicule_id'], $tenantId, -$totalPaye);
        }

        // Marquer la réservation annulée
        $noteAnnul = $motif ? "\nAnnulation : $motif" : '';
        $stmt = $this->db->prepare(
            "UPDATE reservations
             SET    statut = 'annulee',
                    notes  = CONCAT(COALESCE(notes,''), ?)
             WHERE  id = ? AND tenant_id = ?"
        );
        $stmt->execute([$noteAnnul, $id, $tenantId]);

        return true;
    }

    public function confirmer(int $id, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE reservations SET statut = 'confirmee' WHERE id = ? AND tenant_id = ?"
        );
        $stmt->execute([$id, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Convertit la réservation en location.
     * Les paiements de la réservation migrent vers la location (location_id mis à jour).
     * La location est créée sans ré-enregistrer l'avance (déjà dans paiements).
     */
    public function convertirEnLocation(int $id, int $tenantId, array $extra = []): int
    {
        $reservation = $this->getById($id, $tenantId);
        if (!$reservation) {
            throw new \RuntimeException('Réservation introuvable.');
        }
        if ($reservation['statut'] === 'convertie') {
            throw new \RuntimeException('Cette réservation a déjà été convertie.');
        }

        $avanceDejaPayee = $this->getTotalPaye($id, $tenantId);

        // Créer la location — on passe avance=0 car déjà dans paiements
        require_once dirname(__DIR__) . '/models/LocationModel.php';
        $locationModel = new LocationModel($this->db);

        $montantFinal = (float) $reservation['montant_final'];
        $resteAPayer  = max(0, $montantFinal - $avanceDejaPayee);

        // Paiement du reste si fourni lors de la conversion
        $paiementReste = (float) ($extra['paiement_reste'] ?? 0);
        $modePaiement  = $extra['mode_paiement'] ?? 'especes';

        $locationData = [
            'vehicule_id'       => $reservation['vehicule_id'],
            'client_id'         => $reservation['client_id'],
            'chauffeur_id'      => $reservation['chauffeur_id'],
            'commercial_id'     => $reservation['commercial_id'],
            'date_debut'        => $reservation['date_debut'],
            'date_fin'          => $reservation['date_fin'],
            'nombre_jours'      => $reservation['nombre_jours'],
            'prix_par_jour'     => $reservation['prix_par_jour'],
            'montant_total'     => $reservation['montant_total'],
            'remise'            => $reservation['remise'],
            'montant_final'     => $montantFinal,
            'caution'           => $reservation['caution'],
            'avance'            => $avanceDejaPayee + $paiementReste,   // total réel encaissé
            'lieu_destination'  => $reservation['lieu_destination'],
            'avec_chauffeur'    => $reservation['avec_chauffeur'],
            'canal_acquisition' => $reservation['canal_acquisition'],
            'notes'             => $reservation['notes'],
            'mode_paiement'     => $modePaiement,
            'type_location'     => $extra['type_location'] ?? 'standard',
            'km_depart'         => $extra['km_depart'] ?? null,
            'carburant_depart'  => $extra['carburant_depart'] ?? 'plein',
            '_skip_paiement_initial' => true, // flag pour éviter double-enregistrement
        ];

        $locationId = $locationModel->create($tenantId, $locationData);

        // Migrer les paiements de la réservation vers la location
        $this->db->prepare(
            'UPDATE paiements SET location_id = ?, source = \'location\', reservation_id = NULL
             WHERE reservation_id = ? AND tenant_id = ?'
        )->execute([$locationId, $id, $tenantId]);

        // Si le client paye un complément lors de la conversion, l'enregistrer dans paiements
        if ($paiementReste > 0) {
            $this->db->prepare(
                'INSERT INTO paiements (tenant_id, location_id, montant, mode_paiement, notes, source)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$tenantId, $locationId, $paiementReste, $modePaiement, 'Complément à la remise du véhicule', 'location']);

            // Recettes véhicule
            $this->_addRecettesVehicule((int) $reservation['vehicule_id'], $tenantId, $paiementReste);

            // Recalculer reste_a_payer sur la location
            $locationModel->recalcPaiements($locationId, $tenantId);
        }

        // Marquer réservation convertie
        $this->db->prepare(
            "UPDATE reservations SET statut = 'convertie', location_id = ? WHERE id = ? AND tenant_id = ?"
        )->execute([$locationId, $id, $tenantId]);

        return $locationId;
    }

    // -------------------------------------------------------------------------
    // HELPERS PRIVÉS
    // -------------------------------------------------------------------------

    private function _insertPaiement(int $tenantId, int $reservationId, float $montant, string $mode, string $notes): void
    {
        $this->db->prepare(
            'INSERT INTO paiements (tenant_id, reservation_id, montant, mode_paiement, notes, source)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$tenantId, $reservationId, $montant, $mode, $notes, 'reservation']);
    }

    private function _addRecettesVehicule(int $vehiculeId, int $tenantId, float $montant): void
    {
        $this->db->prepare(
            'UPDATE vehicules SET recettes_initiales = COALESCE(recettes_initiales, 0) + ?
             WHERE id = ? AND tenant_id = ?'
        )->execute([$montant, $vehiculeId, $tenantId]);
    }
}
