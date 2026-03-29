<?php
/**
 * ComptabiliteModel — Caisse unifiée FlotteCar
 * Agrège : paiements locations, paiements taxi, charges véhicules, maintenances, dépenses entreprise
 */
class ComptabiliteModel
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────────────────────
    // CAISSE CONFIG
    // ─────────────────────────────────────────────────────────────

    public function getCaisseConfig(int $tenantId): array
    {
        $s = $this->db->prepare("SELECT * FROM caisse_config WHERE tenant_id = ?");
        $s->execute([$tenantId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: ['solde_initial' => 0, 'date_init' => null, 'notes' => ''];
    }

    public function saveCaisseConfig(int $tenantId, float $solde, string $dateInit, string $notes, int $userId): void
    {
        $this->db->prepare("
            INSERT INTO caisse_config (tenant_id, solde_initial, date_init, notes, updated_by, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE solde_initial=VALUES(solde_initial), date_init=VALUES(date_init),
                notes=VALUES(notes), updated_by=VALUES(updated_by), updated_at=NOW()
        ")->execute([$tenantId, $solde, $dateInit, $notes, $userId]);
    }

    // ─────────────────────────────────────────────────────────────
    // DÉPENSES ENTREPRISE
    // ─────────────────────────────────────────────────────────────

    public function getDepenses(int $tenantId, string $from, string $to, string $cat = ''): array
    {
        $sql    = "SELECT * FROM depenses_entreprise WHERE tenant_id=? AND date_depense BETWEEN ? AND ?";
        $params = [$tenantId, $from, $to];
        if ($cat) { $sql .= " AND categorie=?"; $params[] = $cat; }
        $sql .= " ORDER BY date_depense DESC";
        $s = $this->db->prepare($sql);
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createDepense(int $tenantId, array $d, int $userId): int
    {
        $this->db->prepare("
            INSERT INTO depenses_entreprise (tenant_id,categorie,libelle,montant,date_depense,notes,created_by,created_at)
            VALUES (?,?,?,?,?,?,?,NOW())
        ")->execute([$tenantId, $d['categorie'], $d['libelle'], $d['montant'], $d['date_depense'], $d['notes'] ?? '', $userId]);
        return (int)$this->db->lastInsertId();
    }

    public function deleteDepense(int $id, int $tenantId): bool
    {
        $s = $this->db->prepare("DELETE FROM depenses_entreprise WHERE id=? AND tenant_id=?");
        $s->execute([$id, $tenantId]);
        return $s->rowCount() > 0;
    }

    // ─────────────────────────────────────────────────────────────
    // TOTAUX PÉRIODE
    // ─────────────────────────────────────────────────────────────

    /** Recettes locations (paiements) */
    public function getTotalLocations(int $tenantId, string $from, string $to): float
    {
        $s = $this->db->prepare("
            SELECT COALESCE(SUM(p.montant),0)
            FROM paiements p
            WHERE p.tenant_id=? AND DATE(p.created_at) BETWEEN ? AND ?
        ");
        $s->execute([$tenantId, $from, $to]);
        return (float)$s->fetchColumn();
    }

    /** Recettes taxi (paiements_taxi statut paye) */
    public function getTotalTaxi(int $tenantId, string $from, string $to): float
    {
        $s = $this->db->prepare("
            SELECT COALESCE(SUM(pt.montant),0)
            FROM paiements_taxi pt
            WHERE pt.tenant_id=? AND pt.statut_jour='paye' AND pt.date_paiement BETWEEN ? AND ?
        ");
        $s->execute([$tenantId, $from, $to]);
        return (float)$s->fetchColumn();
    }

    /** Charges véhicules */
    public function getTotalChargesVehicules(int $tenantId, string $from, string $to): float
    {
        $s = $this->db->prepare("
            SELECT COALESCE(SUM(montant),0)
            FROM charges
            WHERE tenant_id=? AND date_charge BETWEEN ? AND ?
        ");
        $s->execute([$tenantId, $from, $to]);
        return (float)$s->fetchColumn();
    }

    /** Maintenances terminées */
    public function getTotalMaintenances(int $tenantId, string $from, string $to): float
    {
        $s = $this->db->prepare("
            SELECT COALESCE(SUM(cout),0)
            FROM maintenances
            WHERE tenant_id=? AND statut='termine' AND DATE(updated_at) BETWEEN ? AND ?
        ");
        $s->execute([$tenantId, $from, $to]);
        return (float)$s->fetchColumn();
    }

    /** Dépenses entreprise */
    public function getTotalDepensesEntreprise(int $tenantId, string $from, string $to): float
    {
        $s = $this->db->prepare("
            SELECT COALESCE(SUM(montant),0)
            FROM depenses_entreprise
            WHERE tenant_id=? AND date_depense BETWEEN ? AND ?
        ");
        $s->execute([$tenantId, $from, $to]);
        return (float)$s->fetchColumn();
    }

    // ─────────────────────────────────────────────────────────────
    // FLUX UNIFIÉ (journal chronologique)
    // ─────────────────────────────────────────────────────────────

    public function getJournal(int $tenantId, string $from, string $to, string $sens = '', string $type = ''): array
    {
        $rows = [];

        // — Paiements locations
        if (!$sens || $sens === 'entree') {
            if (!$type || $type === 'location') {
                $s = $this->db->prepare("
                    SELECT p.id, DATE(p.created_at) as date_op,
                           CONCAT('Paiement location #', p.location_id) as libelle,
                           'location' as type_op,
                           p.montant, 'entree' as sens,
                           p.mode_paiement, p.reference,
                           CONCAT(c.prenom,' ',c.nom) as tiers,
                           v.immatriculation
                    FROM paiements p
                    LEFT JOIN locations l ON l.id=p.location_id
                    LEFT JOIN clients c ON c.id=l.client_id
                    LEFT JOIN vehicules v ON v.id=l.vehicule_id
                    WHERE p.tenant_id=? AND DATE(p.created_at) BETWEEN ? AND ?
                    ORDER BY p.created_at DESC
                ");
                $s->execute([$tenantId, $from, $to]);
                $rows = array_merge($rows, $s->fetchAll(PDO::FETCH_ASSOC));
            }
        }

        // — Paiements taxi
        if (!$sens || $sens === 'entree') {
            if (!$type || $type === 'taxi') {
                $s = $this->db->prepare("
                    SELECT pt.id, pt.date_paiement as date_op,
                           CONCAT('Versement taxi — ', tx.prenom,' ',tx.nom) as libelle,
                           'taxi' as type_op,
                           pt.montant, 'entree' as sens,
                           COALESCE(pt.mode_paiement,'especes') as mode_paiement, '' as reference,
                           CONCAT(tx.prenom,' ',tx.nom) as tiers,
                           v.immatriculation
                    FROM paiements_taxi pt
                    JOIN taximetres tx ON tx.id=pt.taximetre_id
                    JOIN vehicules v ON v.id=tx.vehicule_id
                    WHERE pt.tenant_id=? AND pt.statut_jour='paye' AND pt.date_paiement BETWEEN ? AND ?
                    ORDER BY pt.date_paiement DESC
                ");
                $s->execute([$tenantId, $from, $to]);
                $rows = array_merge($rows, $s->fetchAll(PDO::FETCH_ASSOC));
            }
        }

        // — Charges véhicules
        if (!$sens || $sens === 'sortie') {
            if (!$type || $type === 'charge') {
                $s = $this->db->prepare("
                    SELECT ch.id, ch.date_charge as date_op,
                           COALESCE(ch.libelle, ch.type) as libelle,
                           'charge' as type_op,
                           ch.montant, 'sortie' as sens,
                           '' as mode_paiement, '' as reference,
                           '' as tiers, v.immatriculation
                    FROM charges ch
                    LEFT JOIN vehicules v ON v.id=ch.vehicule_id
                    WHERE ch.tenant_id=? AND ch.date_charge BETWEEN ? AND ?
                    ORDER BY ch.date_charge DESC
                ");
                $s->execute([$tenantId, $from, $to]);
                $rows = array_merge($rows, $s->fetchAll(PDO::FETCH_ASSOC));
            }
        }

        // — Maintenances terminées
        if (!$sens || $sens === 'sortie') {
            if (!$type || $type === 'maintenance') {
                $s = $this->db->prepare("
                    SELECT m.id, DATE(m.updated_at) as date_op,
                           CONCAT('Maintenance — ', m.type) as libelle,
                           'maintenance' as type_op,
                           COALESCE(m.cout,0) as montant, 'sortie' as sens,
                           '' as mode_paiement, '' as reference,
                           COALESCE(m.technicien,'') as tiers, v.immatriculation
                    FROM maintenances m
                    LEFT JOIN vehicules v ON v.id=m.vehicule_id
                    WHERE m.tenant_id=? AND m.statut='termine' AND DATE(m.updated_at) BETWEEN ? AND ?
                      AND m.cout > 0
                    ORDER BY m.updated_at DESC
                ");
                $s->execute([$tenantId, $from, $to]);
                $rows = array_merge($rows, $s->fetchAll(PDO::FETCH_ASSOC));
            }
        }

        // — Dépenses entreprise
        if (!$sens || $sens === 'sortie') {
            if (!$type || $type === 'depense') {
                $s = $this->db->prepare("
                    SELECT d.id, d.date_depense as date_op,
                           d.libelle, 'depense' as type_op,
                           d.montant, 'sortie' as sens,
                           '' as mode_paiement, '' as reference,
                           '' as tiers, '' as immatriculation
                    FROM depenses_entreprise d
                    WHERE d.tenant_id=? AND d.date_depense BETWEEN ? AND ?
                    ORDER BY d.date_depense DESC
                ");
                $s->execute([$tenantId, $from, $to]);
                $rows = array_merge($rows, $s->fetchAll(PDO::FETCH_ASSOC));
            }
        }

        // Tri chronologique DESC
        usort($rows, fn($a, $b) => strcmp($b['date_op'], $a['date_op']));
        return $rows;
    }

    // ─────────────────────────────────────────────────────────────
    // GRAPHIQUE — 6 mois glissants
    // ─────────────────────────────────────────────────────────────

    public function getGraphiqueMensuel(int $tenantId, int $nbMois = 6): array
    {
        $mois = [];
        for ($i = $nbMois - 1; $i >= 0; $i--) {
            $d     = new DateTime("first day of -$i month");
            $label = $d->format('M Y');
            $from  = $d->format('Y-m-01');
            $to    = $d->format('Y-m-t');
            $rec   = $this->getTotalLocations($tenantId, $from, $to)
                   + $this->getTotalTaxi($tenantId, $from, $to);
            $dep   = $this->getTotalChargesVehicules($tenantId, $from, $to)
                   + $this->getTotalMaintenances($tenantId, $from, $to)
                   + $this->getTotalDepensesEntreprise($tenantId, $from, $to);
            $mois[] = ['label' => $label, 'recettes' => $rec, 'depenses' => $dep, 'from' => $from];
        }
        return $mois;
    }

    // ─────────────────────────────────────────────────────────────
    // SOLDE CAISSE TOTAL (depuis date init)
    // ─────────────────────────────────────────────────────────────

    public function getSoldeCaisse(int $tenantId): array
    {
        $cfg   = $this->getCaisseConfig($tenantId);
        $from  = $cfg['date_init'] ?? '2000-01-01';
        $to    = date('Y-m-d');

        $rec   = $this->getTotalLocations($tenantId, $from, $to)
               + $this->getTotalTaxi($tenantId, $from, $to);
        $dep   = $this->getTotalChargesVehicules($tenantId, $from, $to)
               + $this->getTotalMaintenances($tenantId, $from, $to)
               + $this->getTotalDepensesEntreprise($tenantId, $from, $to);

        $solde = (float)($cfg['solde_initial'] ?? 0) + $rec - $dep;
        return [
            'solde_initial' => (float)($cfg['solde_initial'] ?? 0),
            'recettes'      => $rec,
            'depenses'      => $dep,
            'solde_actuel'  => $solde,
            'date_init'     => $cfg['date_init'] ?? null,
        ];
    }
}
