<?php

declare(strict_types=1);

class MaintenanceModel
{
    public function __construct(private PDO $db) {}

    // -------------------------------------------------------------------------
    // READ
    // -------------------------------------------------------------------------

    public function getById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*, v.nom AS vehicule_nom, v.immatriculation, v.kilometrage_actuel
             FROM   maintenances m
             JOIN   vehicules v ON v.id = m.vehicule_id
             WHERE  m.id = ? AND m.tenant_id = ?
             LIMIT  1'
        );
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getAll(
        int    $tenantId,
        string $statut    = '',
        string $type      = '',
        int    $vehiculeId = 0
    ): array {
        $sql    = 'SELECT m.*, v.nom AS vehicule_nom, v.immatriculation, v.kilometrage_actuel
                   FROM   maintenances m
                   JOIN   vehicules v ON v.id = m.vehicule_id
                   WHERE  m.tenant_id = ?';
        $params = [$tenantId];

        if ($statut !== '') {
            $sql     .= ' AND m.statut = ?';
            $params[] = $statut;
        }
        if ($type !== '') {
            $sql     .= ' AND m.type = ?';
            $params[] = $type;
        }
        if ($vehiculeId > 0) {
            $sql     .= ' AND m.vehicule_id = ?';
            $params[] = $vehiculeId;
        }

        $sql .= ' ORDER BY m.date_prevue ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Maintenances dont la date ou le km est dépassé (alertes urgentes)
     */
    public function getUrgentes(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*, v.nom AS vehicule_nom, v.immatriculation, v.kilometrage_actuel,
                    (m.km_prevu - v.kilometrage_actuel) AS km_restants
             FROM   maintenances m
             JOIN   vehicules v ON v.id = m.vehicule_id
             WHERE  m.tenant_id = ?
               AND  m.statut = 'planifie'
               AND  (
                     m.date_prevue <= CURDATE()
                     OR (m.km_prevu IS NOT NULL AND v.kilometrage_actuel >= m.km_prevu - 500)
               )
             ORDER  BY m.date_prevue ASC"
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Prochaines maintenances à venir (30 jours ou 2000 km)
     */
    public function getAVenir(int $tenantId, int $joursHorizon = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*, v.nom AS vehicule_nom, v.immatriculation, v.kilometrage_actuel,
                    (m.km_prevu - v.kilometrage_actuel) AS km_restants
             FROM   maintenances m
             JOIN   vehicules v ON v.id = m.vehicule_id
             WHERE  m.tenant_id = ?
               AND  m.statut = 'planifie'
               AND  m.date_prevue BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER  BY m.date_prevue ASC"
        );
        $stmt->execute([$tenantId, $joursHorizon]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Maintenances d'un véhicule (historique)
     */
    public function getByVehicule(int $vehiculeId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM maintenances
             WHERE  vehicule_id = ? AND tenant_id = ?
             ORDER  BY date_prevue DESC'
        );
        $stmt->execute([$vehiculeId, $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Stats globales pour dashboard
     */
    public function getStats(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*)                          AS total,
                SUM(statut = 'planifie')          AS planifiees,
                SUM(statut = 'en_cours')          AS en_cours,
                SUM(statut = 'termine')           AS terminees,
                COALESCE(SUM(CASE WHEN statut = 'termine' THEN cout ELSE 0 END), 0) AS cout_total
             FROM maintenances
             WHERE tenant_id = ?"
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    // -------------------------------------------------------------------------
    // WRITE
    // -------------------------------------------------------------------------

    public function create(int $tenantId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO maintenances (
                tenant_id, vehicule_id, type, km_prevu, date_prevue,
                statut, cout, technicien, notes
            ) VALUES (
                :tenant_id, :vehicule_id, :type, :km_prevu, :date_prevue,
                :statut, :cout, :technicien, :notes
            )'
        );
        $stmt->execute([
            'tenant_id'   => $tenantId,
            'vehicule_id' => (int) $data['vehicule_id'],
            'type'        => $data['type']       ?? 'vidange',
            'km_prevu'    => $data['km_prevu']   ?? null,
            'date_prevue' => $data['date_prevue'] ?? null,
            'statut'      => $data['statut']     ?? 'planifie',
            'cout'        => $data['cout']        ?? 0,
            'technicien'  => $data['technicien'] ?? null,
            'notes'       => $data['notes']      ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $tenantId, array $data): bool
    {
        $allowed = [
            'type', 'km_prevu', 'date_prevue', 'statut', 'cout',
            'km_fait', 'technicien', 'facture', 'notes',
        ];

        $sets   = [];
        $params = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[]   = "`$col` = ?";
                $params[] = $data[$col];
            }
        }

        if (empty($sets)) {
            return false;
        }

        $params[] = $id;
        $params[] = $tenantId;

        $stmt = $this->db->prepare(
            'UPDATE maintenances SET ' . implode(', ', $sets) .
            ' WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Marquer une maintenance comme terminée et mettre à jour le km suivant
     */
    public function terminer(int $id, int $tenantId, int $kmFait, ?float $cout, ?string $technicien, ?string $facture): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE maintenances
             SET statut = 'termine', km_fait = ?, cout = COALESCE(?, cout),
                 technicien = COALESCE(?, technicien), facture = COALESCE(?, facture)
             WHERE id = ? AND tenant_id = ?"
        );
        $stmt->execute([$kmFait, $cout, $technicien, $facture, $id, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Planifier automatiquement la prochaine vidange après la maintenance terminée
     */
    public function planifierProchaineVidange(int $vehiculeId, int $tenantId, int $kmActuel, int $intervalleKm = 5000): int
    {
        $data = [
            'vehicule_id' => $vehiculeId,
            'type'        => 'vidange',
            'km_prevu'    => $kmActuel + $intervalleKm,
            'date_prevue' => null,
            'statut'      => 'planifie',
        ];
        return $this->create($tenantId, $data);
    }

    public function delete(int $id, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM maintenances WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([$id, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    // -------------------------------------------------------------------------
    // GPS ALERTES COORDINATION
    // -------------------------------------------------------------------------

    /**
     * Vérifie si le km GPS dépasse le seuil d'une maintenance planifiée et génère
     * un événement GPS d'alerte si ce n'est pas déjà fait.
     */
    public function checkAlerteGps(int $vehiculeId, int $tenantId, int $kmActuel): array
    {
        $alertesGenerees = [];

        $stmt = $this->db->prepare(
            "SELECT * FROM maintenances
             WHERE vehicule_id = ? AND tenant_id = ?
               AND statut = 'planifie'
               AND km_prevu IS NOT NULL
               AND km_prevu <= ?
               AND (alerte_envoyee IS NULL OR alerte_envoyee = 0)"
        );
        $stmt->execute([$vehiculeId, $tenantId, $kmActuel]);
        $maintenances = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($maintenances as $m) {
            // Marquer l'alerte comme envoyée
            $this->db->prepare(
                'UPDATE maintenances SET alerte_envoyee = 1 WHERE id = ?'
            )->execute([$m['id']]);

            // Insérer dans evenements_gps
            $this->db->prepare(
                'INSERT INTO evenements_gps (tenant_id, vehicule_id, type, message, created_at)
                 VALUES (?, ?, ?, ?, NOW())'
            )->execute([
                $tenantId,
                $vehiculeId,
                'maintenance',
                "Maintenance \"{$m['type']}\" à effectuer — seuil {$m['km_prevu']} km atteint (actuel: {$kmActuel} km)",
            ]);

            $alertesGenerees[] = $m;
        }

        return $alertesGenerees;
    }
}
