<?php

declare(strict_types=1);

class CommercialModel
{
    public function __construct(private PDO $db) {}

    // -------------------------------------------------------------------------
    // READ
    // -------------------------------------------------------------------------

    public function getAll(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM commerciaux WHERE tenant_id = ? ORDER BY nom ASC'
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActifs(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM commerciaux WHERE tenant_id = ? AND statut = 'actif' ORDER BY nom ASC"
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM commerciaux WHERE id = ? AND tenant_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getStats(int $id, int $tenantId): array
    {
        $commercial = $this->getById($id, $tenantId);
        if (!$commercial) {
            return [
                'nb_locations'     => 0,
                'chiffre_affaires' => 0.0,
                'commission_earned'=> 0.0,
            ];
        }

        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*)                            AS nb_locations,
                COALESCE(SUM(montant_final), 0)     AS chiffre_affaires
             FROM   locations
             WHERE  commercial_id = ? AND tenant_id = ? AND statut != 'annulee'"
        );
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $chiffreAffaires  = (float) ($row['chiffre_affaires'] ?? 0);
        $commissionPct    = (float) ($commercial['commission_pct'] ?? 0);
        $commissionEarned = $chiffreAffaires * $commissionPct / 100;

        return [
            'nb_locations'      => (int) ($row['nb_locations'] ?? 0),
            'chiffre_affaires'  => $chiffreAffaires,
            'commission_earned' => round($commissionEarned, 2),
        ];
    }

    // -------------------------------------------------------------------------
    // WRITE
    // -------------------------------------------------------------------------

    public function create(int $tenantId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO commerciaux (tenant_id, nom, prenom, telephone, email, commission_pct, notes)
             VALUES (:tenant_id, :nom, :prenom, :telephone, :email, :commission_pct, :notes)'
        );
        $stmt->execute([
            'tenant_id'      => $tenantId,
            'nom'            => $data['nom']            ?? '',
            'prenom'         => $data['prenom']         ?? null,
            'telephone'      => $data['telephone']      ?? null,
            'email'          => $data['email']          ?? null,
            'commission_pct' => (float) ($data['commission_pct'] ?? 0),
            'notes'          => $data['notes']          ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $tenantId, array $data): bool
    {
        $allowed = ['nom', 'prenom', 'telephone', 'email', 'commission_pct', 'statut', 'notes'];

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
            'UPDATE commerciaux SET ' . implode(', ', $sets) .
            ' WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM commerciaux WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([$id, $tenantId]);
        return $stmt->rowCount() > 0;
    }
}
