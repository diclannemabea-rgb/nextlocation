<?php

declare(strict_types=1);

class LocationModel
{
    public function __construct(private PDO $db) {}

    // -------------------------------------------------------------------------
    // CALCUL MONTANT
    // -------------------------------------------------------------------------

    public function calcMontant(float $prixJour, int $nbJours, float $remise = 0): array
    {
        $montantTotal = $prixJour * $nbJours;
        $montantFinal = $montantTotal - $remise;
        $tauxRemise   = $montantTotal > 0 ? round(($remise / $montantTotal) * 100, 2) : 0.0;

        return [
            'montant_total' => $montantTotal,
            'montant_final' => $montantFinal,
            'taux_remise'   => $tauxRemise,
        ];
    }

    // -------------------------------------------------------------------------
    // READ
    // -------------------------------------------------------------------------

    public function getById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT l.*,
                    v.nom            AS veh_nom,
                    v.immatriculation,
                    v.marque,
                    v.modele,
                    c.nom            AS client_nom,
                    c.prenom         AS client_prenom,
                    c.telephone      AS client_telephone,
                    ch.nom           AS chauffeur_nom,
                    ch.prenom        AS chauffeur_prenom,
                    co.nom           AS commercial_nom,
                    co.prenom        AS commercial_prenom
             FROM   locations l
             JOIN   vehicules v  ON v.id  = l.vehicule_id
             JOIN   clients   c  ON c.id  = l.client_id
             LEFT JOIN chauffeurs  ch ON ch.id = l.chauffeur_id
             LEFT JOIN commerciaux co ON co.id = l.commercial_id
             WHERE  l.id = ? AND l.tenant_id = ?
             LIMIT  1'
        );
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getAll(int $tenantId, array $filters = []): array
    {
        $sql = 'SELECT l.*,
                       v.nom            AS veh_nom,
                       v.immatriculation,
                       c.nom            AS client_nom,
                       c.prenom         AS client_prenom,
                       c.telephone      AS client_telephone
                FROM   locations l
                JOIN   vehicules v ON v.id = l.vehicule_id
                JOIN   clients   c ON c.id = l.client_id
                WHERE  l.tenant_id = ?';
        $params = [$tenantId];

        if (!empty($filters['statut'])) {
            $sql      .= ' AND l.statut = ?';
            $params[]  = $filters['statut'];
        }
        if (!empty($filters['vehicule_id'])) {
            $sql      .= ' AND l.vehicule_id = ?';
            $params[]  = (int) $filters['vehicule_id'];
        }
        if (!empty($filters['client_id'])) {
            $sql      .= ' AND l.client_id = ?';
            $params[]  = (int) $filters['client_id'];
        }
        if (!empty($filters['statut_paiement'])) {
            $sql      .= ' AND l.statut_paiement = ?';
            $params[]  = $filters['statut_paiement'];
        }
        if (!empty($filters['date_from'])) {
            $sql      .= ' AND l.date_debut >= ?';
            $params[]  = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql      .= ' AND l.date_fin <= ?';
            $params[]  = $filters['date_to'];
        }

        $sql .= ' ORDER BY l.created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getEnCours(int $tenantId, int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT l.*,
                    v.nom AS veh_nom, v.immatriculation,
                    c.nom AS client_nom, c.prenom AS client_prenom
             FROM   locations l
             JOIN   vehicules v ON v.id = l.vehicule_id
             JOIN   clients   c ON c.id = l.client_id
             WHERE  l.tenant_id = ? AND l.statut = \'en_cours\'
             ORDER  BY l.date_fin ASC
             LIMIT  ?'
        );
        $stmt->execute([$tenantId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRevenusTotal(int $tenantId, ?int $mois = null, ?int $annee = null): float
    {
        $sql    = "SELECT COALESCE(SUM(montant_final), 0)
                   FROM   locations
                   WHERE  tenant_id = ? AND statut = 'terminee'";
        $params = [$tenantId];

        if ($annee !== null) {
            $sql      .= ' AND YEAR(date_debut) = ?';
            $params[]  = $annee;
        }
        if ($mois !== null) {
            $sql      .= ' AND MONTH(date_debut) = ?';
            $params[]  = $mois;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    }

    public function isVehiculeDisponible(
        int    $vehiculeId,
        int    $tenantId,
        string $dateDebut,
        string $dateFin,
        ?int   $excludeId = null
    ): bool {
        $sql = "SELECT COUNT(*)
                FROM   locations
                WHERE  tenant_id  = ?
                  AND  vehicule_id = ?
                  AND  statut      = 'en_cours'
                  AND  date_debut  < ?
                  AND  date_fin    > ?";
        $params = [$tenantId, $vehiculeId, $dateFin, $dateDebut];

        if ($excludeId !== null) {
            $sql      .= ' AND id != ?';
            $params[]  = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() === 0;
    }

    // -------------------------------------------------------------------------
    // WRITE
    // -------------------------------------------------------------------------

    public function create(int $tenantId, array $data): int
    {
        // Availability check
        if (!$this->isVehiculeDisponible(
            (int) $data['vehicule_id'],
            $tenantId,
            $data['date_debut'],
            $data['date_fin']
        )) {
            throw new \RuntimeException('Ce véhicule est déjà loué sur cette période.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO locations (
                tenant_id, vehicule_id, client_id, chauffeur_id, commercial_id,
                date_debut, date_fin, nombre_jours, prix_par_jour,
                montant_total, remise, montant_final, caution, avance,
                reste_a_payer, statut_paiement, lieu_destination, avec_chauffeur,
                canal_acquisition, mode_paiement, type_location,
                km_depart, carburant_depart, notes, statut
            ) VALUES (
                :tenant_id, :vehicule_id, :client_id, :chauffeur_id, :commercial_id,
                :date_debut, :date_fin, :nombre_jours, :prix_par_jour,
                :montant_total, :remise, :montant_final, :caution, :avance,
                :reste_a_payer, :statut_paiement, :lieu_destination, :avec_chauffeur,
                :canal_acquisition, :mode_paiement, :type_location,
                :km_depart, :carburant_depart, :notes, :statut
            )'
        );

        $avance       = (float) ($data['avance']       ?? 0);
        $montantFinal = (float) ($data['montant_final'] ?? 0);
        $resteAPayer  = $montantFinal - $avance;

        $statutPaiement = 'non_paye';
        if ($avance >= $montantFinal) {
            $statutPaiement = 'solde';
        } elseif ($avance > 0) {
            $statutPaiement = 'avance';
        }

        $stmt->execute([
            'tenant_id'         => $tenantId,
            'vehicule_id'       => (int) $data['vehicule_id'],
            'client_id'         => (int) $data['client_id'],
            'chauffeur_id'      => $data['chauffeur_id']      ?? null,
            'commercial_id'     => $data['commercial_id']     ?? null,
            'date_debut'        => $data['date_debut'],
            'date_fin'          => $data['date_fin'],
            'nombre_jours'      => (int) ($data['nombre_jours'] ?? 1),
            'prix_par_jour'     => (float) ($data['prix_par_jour'] ?? 0),
            'montant_total'     => (float) ($data['montant_total'] ?? 0),
            'remise'            => (float) ($data['remise'] ?? 0),
            'montant_final'     => $montantFinal,
            'caution'           => (float) ($data['caution'] ?? 0),
            'avance'            => $avance,
            'reste_a_payer'     => $resteAPayer,
            'statut_paiement'   => $data['statut_paiement']   ?? $statutPaiement,
            'lieu_destination'  => $data['lieu_destination']  ?? null,
            'avec_chauffeur'    => (int) ($data['avec_chauffeur'] ?? 0),
            'canal_acquisition' => $data['canal_acquisition'] ?? 'direct',
            'mode_paiement'     => $data['mode_paiement']     ?? 'especes',
            'type_location'     => $data['type_location']     ?? 'standard',
            'km_depart'         => $data['km_depart']         ?? null,
            'carburant_depart'  => $data['carburant_depart']  ?? 'plein',
            'notes'             => $data['notes']             ?? null,
            'statut'            => 'en_cours',
        ]);

        $locationId = (int) $this->db->lastInsertId();

        // Enregistrer l'avance initiale dans paiements — sauf si elle vient d'une réservation
        // (dans ce cas les paiements ont déjà été migrés par ReservationModel::convertirEnLocation)
        if ($avance > 0 && empty($data['_skip_paiement_initial'])) {
            $this->db->prepare(
                'INSERT INTO paiements (tenant_id, location_id, montant, mode_paiement, notes, source)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $tenantId,
                $locationId,
                $avance,
                $data['mode_paiement'] ?? 'especes',
                'Avance initiale',
                'location',
            ]);
            // Ajouter aux recettes du véhicule
            $this->db->prepare(
                'UPDATE vehicules SET recettes_initiales = COALESCE(recettes_initiales, 0) + ?
                 WHERE id = ? AND tenant_id = ?'
            )->execute([$avance, $data['vehicule_id'], $tenantId]);
        }

        // Mark vehicle as rented
        $upd = $this->db->prepare(
            "UPDATE vehicules SET statut = 'loue' WHERE id = ? AND tenant_id = ?"
        );
        $upd->execute([$data['vehicule_id'], $tenantId]);

        return $locationId;
    }

    public function terminer(int $id, int $tenantId, array $data): bool
    {
        $location = $this->getById($id, $tenantId);
        if (!$location) {
            return false;
        }

        // Settle remaining payment if provided
        $paiementSolde = (float) ($data['paiement_solde'] ?? 0);
        if ($paiementSolde > 0) {
            $this->ajouterPaiement(
                $id,
                $tenantId,
                $paiementSolde,
                $data['mode_paiement'] ?? 'especes',
                'Solde à la restitution'
            );
        }

        $stmt = $this->db->prepare(
            "UPDATE locations
             SET    statut          = 'terminee',
                    km_retour       = :km_retour,
                    carburant_retour = :carburant_retour,
                    statut_caution  = :statut_caution,
                    notes           = CONCAT(COALESCE(notes,''), :notes_append)
             WHERE  id = ? AND tenant_id = ?"
        );
        $stmt->execute([
            'km_retour'        => $data['km_retour']        ?? null,
            'carburant_retour' => $data['carburant_retour'] ?? null,
            'statut_caution'   => $data['statut_caution']   ?? 'rendue',
            'notes_append'     => (!empty($data['notes']) ? "\n" . $data['notes'] : ''),
            $id,
            $tenantId,
        ]);

        // Free the vehicle
        $upd = $this->db->prepare(
            "UPDATE vehicules SET statut = 'disponible' WHERE id = ? AND tenant_id = ?"
        );
        $upd->execute([$location['vehicule_id'], $tenantId]);

        // Update vehicle mileage if provided
        if (!empty($data['km_retour'])) {
            $km = $this->db->prepare(
                'UPDATE vehicules SET kilometrage_actuel = ?
                 WHERE  id = ? AND tenant_id = ? AND kilometrage_actuel < ?'
            );
            $km->execute([
                (int) $data['km_retour'],
                $location['vehicule_id'],
                $tenantId,
                (int) $data['km_retour'],
            ]);
        }

        return true;
    }

    public function ajouterPaiement(
        int    $locationId,
        int    $tenantId,
        float  $montant,
        string $mode,
        string $notes = ''
    ): bool {
        // Récupérer vehicule_id pour mettre à jour les recettes
        $locRow = $this->db->prepare('SELECT vehicule_id FROM locations WHERE id = ? AND tenant_id = ?');
        $locRow->execute([$locationId, $tenantId]);
        $vehiculeId = (int) ($locRow->fetchColumn() ?: 0);

        // Insérer le paiement
        $this->db->prepare(
            'INSERT INTO paiements (tenant_id, location_id, montant, mode_paiement, notes, source)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$tenantId, $locationId, $montant, $mode, $notes, 'location']);

        // Ajouter aux recettes du véhicule
        if ($vehiculeId > 0) {
            $this->db->prepare(
                'UPDATE vehicules SET recettes_initiales = COALESCE(recettes_initiales, 0) + ?
                 WHERE id = ? AND tenant_id = ?'
            )->execute([$montant, $vehiculeId, $tenantId]);
        }

        // Recalculer le solde de la location
        $this->recalcPaiements($locationId, $tenantId);

        return true;
    }

    /**
     * Recalcule reste_a_payer et statut_paiement d'une location
     * à partir du total des paiements enregistrés.
     */
    public function recalcPaiements(int $locationId, int $tenantId): void
    {
        $totStmt = $this->db->prepare(
            'SELECT COALESCE(SUM(montant), 0) FROM paiements
             WHERE  location_id = ? AND tenant_id = ?'
        );
        $totStmt->execute([$locationId, $tenantId]);
        $totalPaye = (float) $totStmt->fetchColumn();

        $locStmt = $this->db->prepare(
            'SELECT montant_final FROM locations WHERE id = ? AND tenant_id = ?'
        );
        $locStmt->execute([$locationId, $tenantId]);
        $montantFinal = (float) $locStmt->fetchColumn();

        $resteAPayer = max(0, $montantFinal - $totalPaye);

        $statutPaiement = 'non_paye';
        if ($resteAPayer <= 0) {
            $statutPaiement = 'solde';
        } elseif ($totalPaye > 0) {
            $statutPaiement = 'avance';
        }

        $this->db->prepare(
            'UPDATE locations SET reste_a_payer = ?, statut_paiement = ?
             WHERE  id = ? AND tenant_id = ?'
        )->execute([$resteAPayer, $statutPaiement, $locationId, $tenantId]);
    }
}
