<?php

declare(strict_types=1);

class TaximetreModel
{
    public function __construct(private PDO $db) {}

    // -------------------------------------------------------------------------
    // READ
    // -------------------------------------------------------------------------

    public function getAll(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*,
                    v.nom           AS vehicule_nom,
                    v.immatriculation,
                    v.marque,
                    v.modele,
                    v.kilometrage_actuel
             FROM   taximetres t
             JOIN   vehicules  v ON v.id = t.vehicule_id
             WHERE  t.tenant_id = ?
             ORDER  BY t.nom ASC'
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*,
                    v.nom           AS vehicule_nom,
                    v.immatriculation,
                    v.marque,
                    v.modele,
                    v.kilometrage_actuel
             FROM   taximetres t
             JOIN   vehicules  v ON v.id = t.vehicule_id
             WHERE  t.id = ? AND t.tenant_id = ?
             LIMIT  1'
        );
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getByVehicule(int $vehiculeId, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT t.*,
                    v.nom AS vehicule_nom, v.immatriculation
             FROM   taximetres t
             JOIN   vehicules  v ON v.id = t.vehicule_id
             WHERE  t.vehicule_id = ? AND t.tenant_id = ? AND t.statut = 'actif'
             LIMIT  1"
        );
        $stmt->execute([$vehiculeId, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getSolde(int $id, int $tenantId): array
    {
        $taximetre = $this->getById($id, $tenantId);
        if (!$taximetre) {
            return [
                'total_du'       => 0.0,
                'total_paye'     => 0.0,
                'solde_restant'  => 0.0,
                'jours_travailles' => 0,
                'jours_off'      => 0,
                'jours_payes'    => 0,
            ];
        }

        $dateDebut = $taximetre['date_debut'];
        $dateFin   = $taximetre['date_fin'] ?? date('Y-m-d');
        $tarif     = (float) $taximetre['tarif_journalier'];

        // Count working days (all days between start and today, excluding OFF days)
        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*)                                                       AS total_saisis,
                SUM(statut_jour IN ('paye','non_paye'))                        AS jours_travailles,
                SUM(statut_jour IN ('jour_off','panne','accident'))            AS jours_off,
                SUM(statut_jour = 'paye')                                     AS jours_payes,
                COALESCE(SUM(CASE WHEN statut_jour = 'paye' THEN montant ELSE 0 END), 0) AS total_paye
             FROM paiements_taxi
             WHERE taximetre_id = ? AND tenant_id = ?"
        );
        $stmt->execute([$id, $tenantId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        $joursTraVailles = (int) ($stats['jours_travailles'] ?? 0);
        $joursOff        = (int) ($stats['jours_off']        ?? 0);
        $joursPayes      = (int) ($stats['jours_payes']      ?? 0);
        $totalPaye       = (float) ($stats['total_paye']     ?? 0);

        // Total owed = tarif × working days recorded
        $totalDu     = $tarif * $joursTraVailles;
        $soldeRestant = max(0, $totalDu - $totalPaye);

        return [
            'total_du'         => $totalDu,
            'total_paye'       => $totalPaye,
            'solde_restant'    => $soldeRestant,
            'jours_travailles' => $joursTraVailles,
            'jours_off'        => $joursOff,
            'jours_payes'      => $joursPayes,
        ];
    }

    public function getHistorique(int $taximetreId, int $tenantId, int $limit = 30): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM paiements_taxi
             WHERE  taximetre_id = ? AND tenant_id = ?
             ORDER  BY date_paiement DESC
             LIMIT  ?'
        );
        $stmt->execute([$taximetreId, $tenantId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getJoursSaisis(int $taximetreId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT date_paiement, statut_jour
             FROM   paiements_taxi
             WHERE  taximetre_id = ? AND tenant_id = ?
             ORDER  BY date_paiement ASC'
        );
        $stmt->execute([$taximetreId, $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $map[$row['date_paiement']] = $row['statut_jour'];
        }
        return $map;
    }

    // -------------------------------------------------------------------------
    // WRITE
    // -------------------------------------------------------------------------

    public function create(int $tenantId, array $data): int
    {
        $token = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare(
            'INSERT INTO taximetres (
                tenant_id, vehicule_id, nom, prenom, telephone, numero_cni,
                photo, tarif_journalier, date_debut, caution_versee, notes, statut, token_acces
            ) VALUES (
                :tenant_id, :vehicule_id, :nom, :prenom, :telephone, :numero_cni,
                :photo, :tarif_journalier, :date_debut, :caution_versee, :notes, :statut, :token_acces
            )'
        );
        $stmt->execute([
            'tenant_id'       => $tenantId,
            'vehicule_id'     => (int) $data['vehicule_id'],
            'nom'             => $data['nom']              ?? '',
            'prenom'          => $data['prenom']           ?? null,
            'telephone'       => $data['telephone']        ?? null,
            'numero_cni'      => $data['numero_cni']       ?? null,
            'photo'           => $data['photo']            ?? null,
            'tarif_journalier'=> (float) ($data['tarif_journalier'] ?? 0),
            'date_debut'      => $data['date_debut'],
            'caution_versee'  => (float) ($data['caution_versee'] ?? 0),
            'notes'           => $data['notes']            ?? null,
            'statut'          => 'actif',
            'token_acces'     => $token,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $tenantId, array $data): bool
    {
        $allowed = [
            'nom', 'prenom', 'telephone', 'numero_cni', 'photo',
            'tarif_journalier', 'date_debut', 'date_fin', 'caution_versee', 'notes', 'statut',
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
            'UPDATE taximetres SET ' . implode(', ', $sets) .
            ' WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function suspend(int $id, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE taximetres SET statut = 'suspendu' WHERE id = ? AND tenant_id = ?"
        );
        $stmt->execute([$id, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    public function saisirJour(
        int     $taximetreId,
        int     $tenantId,
        string  $date,
        string  $statutJour,
        float   $montant   = 0,
        string  $mode      = 'especes',
        ?int    $kmDebut   = null,
        ?int    $kmFin     = null,
        string  $notes     = ''
    ): bool {
        // UNIQUE KEY on (taximetre_id, date_paiement) — use ON DUPLICATE KEY UPDATE
        $stmt = $this->db->prepare(
            'INSERT INTO paiements_taxi
                (tenant_id, taximetre_id, date_paiement, statut_jour, montant, mode_paiement, km_debut, km_fin, notes)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                statut_jour   = VALUES(statut_jour),
                montant       = VALUES(montant),
                mode_paiement = VALUES(mode_paiement),
                km_debut      = VALUES(km_debut),
                km_fin        = VALUES(km_fin),
                notes         = VALUES(notes)'
        );
        $stmt->execute([
            $tenantId,
            $taximetreId,
            $date,
            $statutJour,
            $montant,
            $mode,
            $kmDebut,
            $kmFin,
            $notes,
        ]);
        return $stmt->rowCount() > 0;
    }
}
