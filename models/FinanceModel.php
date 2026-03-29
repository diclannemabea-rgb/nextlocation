<?php

declare(strict_types=1);

/**
 * FinanceModel — Rentabilité, charges, revenus, dashboard financier
 * Couvre les 3 profils: Location, Taxi, Entreprise
 */
class FinanceModel
{
    public function __construct(private PDO $db) {}

    // -------------------------------------------------------------------------
    // CHARGES
    // -------------------------------------------------------------------------

    public function getCharges(
        int    $tenantId,
        string $debut   = '',
        string $fin     = '',
        string $type    = '',
        int    $vehiculeId = 0
    ): array {
        $sql    = 'SELECT c.*, v.nom AS vehicule_nom, v.immatriculation
                   FROM   charges c
                   LEFT JOIN vehicules v ON v.id = c.vehicule_id
                   WHERE  c.tenant_id = ?';
        $params = [$tenantId];

        if ($debut !== '') { $sql .= ' AND c.date_charge >= ?'; $params[] = $debut; }
        if ($fin   !== '') { $sql .= ' AND c.date_charge <= ?'; $params[] = $fin;   }
        if ($type  !== '') { $sql .= ' AND c.type_charge = ?';  $params[] = $type;  }
        if ($vehiculeId > 0) { $sql .= ' AND c.vehicule_id = ?'; $params[] = $vehiculeId; }

        $sql .= ' ORDER BY c.date_charge DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createCharge(int $tenantId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO charges (tenant_id, vehicule_id, type_charge, montant, description, date_charge, fournisseur)
             VALUES (:tenant_id, :vehicule_id, :type_charge, :montant, :description, :date_charge, :fournisseur)'
        );
        $stmt->execute([
            'tenant_id'   => $tenantId,
            'vehicule_id' => $data['vehicule_id'] ?? null,
            'type_charge' => $data['type_charge'] ?? 'autre',
            'montant'     => (float) ($data['montant'] ?? 0),
            'description' => $data['description'] ?? null,
            'date_charge' => $data['date_charge'] ?? date('Y-m-d'),
            'fournisseur' => $data['fournisseur'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteCharge(int $id, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM charges WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([$id, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    // -------------------------------------------------------------------------
    // REVENUS / RENTABILITÉ
    // -------------------------------------------------------------------------

    /**
     * Revenus locations sur une période
     */
    public function getRevenusLocations(int $tenantId, string $debut = '', string $fin = ''): float
    {
        $sql    = "SELECT COALESCE(SUM(montant_final), 0) FROM locations
                   WHERE tenant_id = ? AND statut != 'annulee'";
        $params = [$tenantId];
        if ($debut !== '') { $sql .= ' AND date_debut >= ?'; $params[] = $debut; }
        if ($fin   !== '') { $sql .= ' AND date_debut <= ?'; $params[] = $fin;   }

        return (float) $this->db->prepare($sql)->execute($params) && ($r = $this->db->prepare($sql)) && $r->execute($params)
            ? (float) $r->fetchColumn()
            : 0.0;
    }

    /**
     * Revenus taxi (paiements reçus) sur une période
     */
    public function getRevenusTaxi(int $tenantId, string $debut = '', string $fin = ''): float
    {
        $sql    = "SELECT COALESCE(SUM(montant), 0) FROM paiements_taxi
                   WHERE tenant_id = ? AND statut_jour = 'paye'";
        $params = [$tenantId];
        if ($debut !== '') { $sql .= ' AND date_paiement >= ?'; $params[] = $debut; }
        if ($fin   !== '') { $sql .= ' AND date_paiement <= ?'; $params[] = $fin;   }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Total charges sur une période
     */
    public function getTotalCharges(int $tenantId, string $debut = '', string $fin = ''): float
    {
        $sql    = 'SELECT COALESCE(SUM(montant), 0) FROM charges WHERE tenant_id = ?';
        $params = [$tenantId];
        if ($debut !== '') { $sql .= ' AND date_charge >= ?'; $params[] = $debut; }
        if ($fin   !== '') { $sql .= ' AND date_charge <= ?'; $params[] = $fin;   }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Rentabilité par véhicule (pour tous les types)
     */
    public function getRentabiliteParVehicule(int $tenantId): array
    {
        // Revenus locations
        $stmtLoc = $this->db->prepare(
            "SELECT vehicule_id,
                    COALESCE(SUM(montant_final), 0) AS revenus_location,
                    COUNT(*) AS nb_locations
             FROM   locations
             WHERE  tenant_id = ? AND statut = 'terminee'
             GROUP  BY vehicule_id"
        );
        $stmtLoc->execute([$tenantId]);
        $revenusLoc = [];
        foreach ($stmtLoc->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $revenusLoc[$r['vehicule_id']] = $r;
        }

        // Revenus taxi
        $stmtTaxi = $this->db->prepare(
            "SELECT t.vehicule_id,
                    COALESCE(SUM(p.montant), 0) AS revenus_taxi,
                    COUNT(*) AS jours_payes
             FROM   paiements_taxi p
             JOIN   taximetres t ON t.id = p.taximetre_id
             WHERE  p.tenant_id = ? AND p.statut_jour = 'paye'
             GROUP  BY t.vehicule_id"
        );
        $stmtTaxi->execute([$tenantId]);
        $revenusTaxi = [];
        foreach ($stmtTaxi->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $revenusTaxi[$r['vehicule_id']] = $r;
        }

        // Charges par véhicule
        $stmtCharges = $this->db->prepare(
            'SELECT vehicule_id, COALESCE(SUM(montant), 0) AS total_charges
             FROM   charges
             WHERE  tenant_id = ? AND vehicule_id IS NOT NULL
             GROUP  BY vehicule_id'
        );
        $stmtCharges->execute([$tenantId]);
        $charges = [];
        foreach ($stmtCharges->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $charges[$r['vehicule_id']] = (float) $r['total_charges'];
        }

        // Maintenances par véhicule
        $stmtMaint = $this->db->prepare(
            "SELECT vehicule_id, COALESCE(SUM(cout), 0) AS total_maintenance
             FROM   maintenances
             WHERE  tenant_id = ? AND statut = 'termine'
             GROUP  BY vehicule_id"
        );
        $stmtMaint->execute([$tenantId]);
        $maintenances = [];
        foreach ($stmtMaint->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $maintenances[$r['vehicule_id']] = (float) $r['total_maintenance'];
        }

        // Tous les véhicules
        $stmtVehs = $this->db->prepare(
            'SELECT id, nom, immatriculation, marque, modele, type_vehicule,
                    capital_investi, km_initial_compteur, recettes_initiales, depenses_initiales
             FROM   vehicules
             WHERE  tenant_id = ?
             ORDER  BY nom ASC'
        );
        $stmtVehs->execute([$tenantId]);
        $vehicules = $stmtVehs->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($vehicules as $v) {
            $vid = $v['id'];

            // ── Revenus ──────────────────────────────────────────────────────
            // paiements réels encaissés (table paiements) = source de vérité
            $revLoc  = (float) ($revenusLoc[$vid]['revenus_location'] ?? 0);
            $revTaxi = (float) ($revenusTaxi[$vid]['revenus_taxi']    ?? 0);
            // recettes_initiales = avances réservations/locations qui se cumulent
            $revInit  = (float) ($v['recettes_initiales'] ?? 0);
            $totalRev = $revLoc + $revTaxi + $revInit;

            // ── Dépenses ─────────────────────────────────────────────────────
            // depenses_initiales = somme de TOUTES les charges et maintenances
            // déjà ajoutées via les pages (source de vérité, pas de double comptage)
            // Les colonnes $charges et $maintenances sont issues des mêmes tables
            // et sont déjà incluses dans depenses_initiales → on n'utilise QUE depenses_initiales
            $totalChrg = (float) ($v['depenses_initiales'] ?? 0);

            // Sécurité : si depenses_initiales est 0 mais qu'il y a des charges/maintenances
            // (données historiques avant la mise à jour), on utilise les tables
            if ($totalChrg === 0.0) {
                $totalChrg = ($charges[$vid] ?? 0) + ($maintenances[$vid] ?? 0);
            }

            $capital  = (float) ($v['capital_investi'] ?? 0);
            $benefice = $totalRev - $totalChrg - $capital;
            $roi      = $capital > 0 ? round(($benefice / $capital) * 100, 1) : 0;

            $result[] = array_merge($v, [
                'revenus_total'       => $totalRev,
                'charges_total'       => $totalChrg,
                'detail_charges'      => ($charges[$vid] ?? 0),
                'detail_maintenances' => ($maintenances[$vid] ?? 0),
                'capital_investi'     => $capital,
                'benefice_net'        => $benefice,
                'roi_pct'             => $roi,
                'nb_locations'        => (int) ($revenusLoc[$vid]['nb_locations'] ?? 0),
                'jours_taxi'          => (int) ($revenusTaxi[$vid]['jours_payes'] ?? 0),
            ]);
        }

        return $result;
    }

    /**
     * Résumé financier global (dashboard)
     */
    public function getResumeMensuel(int $tenantId, string $mois): array
    {
        $debut = $mois . '-01';
        $fin   = date('Y-m-t', strtotime($debut));

        $revLoc  = $this->getTotalRevenuLocations($tenantId, $debut, $fin);
        $revTaxi = $this->getRevenusTaxi($tenantId, $debut, $fin);
        $charges = $this->getTotalCharges($tenantId, $debut, $fin);

        return [
            'revenus_locations' => $revLoc,
            'revenus_taxi'      => $revTaxi,
            'total_revenus'     => $revLoc + $revTaxi,
            'total_charges'     => $charges,
            'benefice'          => ($revLoc + $revTaxi) - $charges,
            'mois'              => $mois,
        ];
    }

    /**
     * Revenus locations par mois (graphique 12 mois)
     */
    public function getRevenusParMois(int $tenantId, int $nbMois = 12): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE_FORMAT(date_debut, '%Y-%m') AS mois,
                    COALESCE(SUM(montant_final), 0)   AS revenus
             FROM   locations
             WHERE  tenant_id = ?
               AND  statut = 'terminee'
               AND  date_debut >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
             GROUP  BY mois
             ORDER  BY mois ASC"
        );
        $stmt->execute([$tenantId, $nbMois]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Helper interne (évite l'auto-call)
    private function getTotalRevenuLocations(int $tenantId, string $debut, string $fin): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(montant_final), 0) FROM locations
             WHERE tenant_id = ? AND statut = 'terminee'
               AND date_debut >= ? AND date_debut <= ?"
        );
        $stmt->execute([$tenantId, $debut, $fin]);
        return (float) $stmt->fetchColumn();
    }
}
