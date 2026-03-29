<?php

declare(strict_types=1);

class VehiculeModel
{
    public function __construct(private PDO $db) {}

    // -------------------------------------------------------------------------
    // READ
    // -------------------------------------------------------------------------

    public function getById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM vehicules WHERE id = ? AND tenant_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getAll(
        int    $tenantId,
        string $statut        = '',
        string $q             = '',
        string $type_vehicule = ''
    ): array {
        $sql    = 'SELECT * FROM vehicules WHERE tenant_id = ?';
        $params = [$tenantId];

        if ($statut !== '') {
            $sql      .= ' AND statut = ?';
            $params[]  = $statut;
        }
        if ($type_vehicule !== '') {
            $sql      .= ' AND type_vehicule = ?';
            $params[]  = $type_vehicule;
        }
        if ($q !== '') {
            $sql      .= ' AND (nom LIKE ? OR immatriculation LIKE ? OR marque LIKE ? OR modele LIKE ?)';
            $like      = '%' . $q . '%';
            $params[]  = $like;
            $params[]  = $like;
            $params[]  = $like;
            $params[]  = $like;
        }

        $sql .= ' ORDER BY nom ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function count(int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM vehicules WHERE tenant_id = ?'
        );
        $stmt->execute([$tenantId]);
        return (int) $stmt->fetchColumn();
    }

    public function getTaxiVehicules(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM vehicules WHERE tenant_id = ? AND type_vehicule = 'taxi' ORDER BY nom ASC"
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMaintenanceUrgente(int $tenantId, int $kmSeuil = 1000): array
    {
        $stmt = $this->db->prepare(
            'SELECT *,
                    (prochaine_vidange_km - kilometrage_actuel) AS km_restants
             FROM   vehicules
             WHERE  tenant_id = ?
               AND  prochaine_vidange_km IS NOT NULL
               AND  (prochaine_vidange_km - kilometrage_actuel) <= ?
             ORDER  BY km_restants ASC'
        );
        $stmt->execute([$tenantId, $kmSeuil]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStats(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*)                                        AS total,
                SUM(statut = 'disponible')                      AS disponibles,
                SUM(statut = 'loue')                            AS loues,
                SUM(statut = 'maintenance')                     AS maintenance,
                SUM(statut = 'indisponible')                    AS indisponibles
             FROM vehicules
             WHERE tenant_id = ?"
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'total'         => (int) ($row['total']         ?? 0),
            'disponibles'   => (int) ($row['disponibles']   ?? 0),
            'loues'         => (int) ($row['loues']         ?? 0),
            'maintenance'   => (int) ($row['maintenance']   ?? 0),
            'indisponibles' => (int) ($row['indisponibles'] ?? 0),
        ];
    }

    // -------------------------------------------------------------------------
    // WRITE
    // -------------------------------------------------------------------------

    public function create(int $tenantId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO vehicules (
                tenant_id, nom, immatriculation, marque, modele, annee, couleur,
                type_carburant, places, prix_location_jour, capital_investi,
                km_initial_compteur, recettes_initiales, depenses_initiales,
                kilometrage_actuel, prochaine_vidange_km, type_vehicule,
                imei, modele_boitier, traccar_device_id, statut, notes,
                date_mise_en_service, date_expiration_assurance,
                date_expiration_vignette, numero_chassis, puissance_cv, photo
            ) VALUES (
                :tenant_id, :nom, :immatriculation, :marque, :modele, :annee, :couleur,
                :type_carburant, :places, :prix_location_jour, :capital_investi,
                :km_initial_compteur, :recettes_initiales, :depenses_initiales,
                :kilometrage_actuel, :prochaine_vidange_km, :type_vehicule,
                :imei, :modele_boitier, :traccar_device_id, :statut, :notes,
                :date_mise_en_service, :date_expiration_assurance,
                :date_expiration_vignette, :numero_chassis, :puissance_cv, :photo
            )'
        );

        $stmt->execute([
            'tenant_id'                  => $tenantId,
            'nom'                        => $data['nom']                       ?? '',
            'immatriculation'            => $data['immatriculation']            ?? '',
            'marque'                     => $data['marque']                    ?? null,
            'modele'                     => $data['modele']                    ?? null,
            'annee'                      => $data['annee']                     ?? null,
            'couleur'                    => $data['couleur']                   ?? null,
            'type_carburant'             => $data['type_carburant']            ?? 'essence',
            'places'                     => $data['places']                    ?? 5,
            'prix_location_jour'         => $data['prix_location_jour']        ?? 0,
            'capital_investi'            => $data['capital_investi']           ?? 0,
            'km_initial_compteur'        => $data['km_initial_compteur']       ?? 0,
            'recettes_initiales'         => $data['recettes_initiales']        ?? 0,
            'depenses_initiales'         => $data['depenses_initiales']        ?? 0,
            'kilometrage_actuel'         => $data['kilometrage_actuel']        ?? 0,
            'prochaine_vidange_km'       => $data['prochaine_vidange_km']      ?? null,
            'type_vehicule'              => $data['type_vehicule']             ?? 'location',
            'imei'                       => $data['imei']                      ?? null,
            'modele_boitier'             => $data['modele_boitier']            ?? null,
            'traccar_device_id'          => $data['traccar_device_id']         ?? null,
            'statut'                     => $data['statut']                    ?? 'disponible',
            'notes'                      => $data['notes']                     ?? null,
            'date_mise_en_service'       => $data['date_mise_en_service']      ?? null,
            'date_expiration_assurance'  => $data['date_expiration_assurance'] ?? null,
            'date_expiration_vignette'   => $data['date_expiration_vignette']  ?? null,
            'numero_chassis'             => $data['numero_chassis']            ?? null,
            'puissance_cv'               => $data['puissance_cv']              ?? null,
            'photo'                      => $data['photo']                     ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $tenantId, array $data): bool
    {
        $allowed = [
            'nom', 'immatriculation', 'marque', 'modele', 'annee', 'couleur',
            'type_carburant', 'places', 'prix_location_jour', 'capital_investi',
            'km_initial_compteur', 'recettes_initiales', 'depenses_initiales',
            'kilometrage_actuel', 'prochaine_vidange_km', 'type_vehicule',
            'imei', 'modele_boitier', 'traccar_device_id', 'statut', 'notes',
            'date_mise_en_service', 'date_expiration_assurance',
            'date_expiration_vignette', 'numero_chassis', 'puissance_cv', 'photo',
        ];

        $sets   = [];
        $params = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[]       = "`$col` = ?";
                $params[]     = $data[$col];
            }
        }

        if (empty($sets)) {
            return false;
        }

        $params[] = $id;
        $params[] = $tenantId;

        $stmt = $this->db->prepare(
            'UPDATE vehicules SET ' . implode(', ', $sets) .
            ' WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id, int $tenantId): bool
    {
        // Mark as unavailable first, then delete
        $this->updateStatut($id, $tenantId, 'indisponible');

        $stmt = $this->db->prepare(
            'DELETE FROM vehicules WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([$id, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    public function updateStatut(int $id, int $tenantId, string $statut): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE vehicules SET statut = ? WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([$statut, $id, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    public function updateKm(int $id, int $tenantId, int $km): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE vehicules SET kilometrage_actuel = ? WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([$km, $id, $tenantId]);
        return $stmt->rowCount() > 0;
    }
}
