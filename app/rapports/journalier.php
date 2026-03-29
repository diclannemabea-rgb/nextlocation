<?php
/**
 * FlotteCar - Rapport journalier
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireTenantAuth();

$database = new Database();
$db       = $database->getConnection();
$tenantId = getTenantId();

// ── Filtre date ──────────────────────────────────────────────────
$date = $_GET['date'] ?? date('Y-m-d');
// Sécurité : s'assurer que c'est une date valide
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

// ── Locations démarrées aujourd'hui ─────────────────────────────
$locsDemarrees = [];
$locsTerminees = [];
$revenuJour    = 0;
$paiementsJour = 0;

if (hasLocationModule()) {
    $stmtDem = $db->prepare("
        SELECT l.*, v.nom AS veh_nom, c.nom AS client_nom
        FROM locations l
        JOIN vehicules v ON v.id = l.vehicule_id
        JOIN clients c   ON c.id = l.client_id
        WHERE l.tenant_id = ? AND DATE(l.date_debut) = ?
        ORDER BY l.created_at DESC
    ");
    $stmtDem->execute([$tenantId, $date]);
    $locsDemarrees = $stmtDem->fetchAll();

    // ── Locations terminées aujourd'hui ─────────────────────────
    $stmtTer = $db->prepare("
        SELECT l.*, v.nom AS veh_nom, c.nom AS client_nom
        FROM locations l
        JOIN vehicules v ON v.id = l.vehicule_id
        JOIN clients c   ON c.id = l.client_id
        WHERE l.tenant_id = ? AND l.statut = 'terminee' AND DATE(l.date_fin) = ?
        ORDER BY l.created_at DESC
    ");
    $stmtTer->execute([$tenantId, $date]);
    $locsTerminees = $stmtTer->fetchAll();

    // ── Revenus du jour ──────────────────────────────────────────
    $stmtRev = $db->prepare("
        SELECT COALESCE(SUM(montant_final), 0)
        FROM locations
        WHERE tenant_id = ? AND statut = 'terminee' AND DATE(date_fin) = ?
    ");
    $stmtRev->execute([$tenantId, $date]);
    $revenuJour = (float)$stmtRev->fetchColumn();

    // ── Paiements reçus aujourd'hui ──────────────────────────────
    $stmtPai = $db->prepare("
        SELECT COALESCE(SUM(montant), 0)
        FROM paiements
        WHERE tenant_id = ? AND DATE(created_at) = ?
    ");
    $stmtPai->execute([$tenantId, $date]);
    $paiementsJour = (float)$stmtPai->fetchColumn();
}

// ── Maintenances effectuées aujourd'hui ─────────────────────────
$stmtMaint = $db->prepare("
    SELECT m.*, v.nom AS veh_nom
    FROM maintenances m
    JOIN vehicules v ON v.id = m.vehicule_id
    WHERE m.tenant_id = ? AND m.statut = 'fait' AND DATE(m.updated_at) = ?
");
$stmtMaint->execute([$tenantId, $date]);
$maintenances = $stmtMaint->fetchAll();

// ── Charges ajoutées aujourd'hui ────────────────────────────────
$stmtChg = $db->prepare("
    SELECT c.*, v.nom AS veh_nom
    FROM charges c
    LEFT JOIN vehicules v ON v.id = c.vehicule_id
    WHERE c.tenant_id = ? AND DATE(c.date_charge) = ?
    ORDER BY c.id DESC
");
$stmtChg->execute([$tenantId, $date]);
$charges = $stmtChg->fetchAll();

// ── Statut de la flotte ──────────────────────────────────────────
$stmtFlotte = $db->prepare("
    SELECT statut, COUNT(*) AS nb
    FROM vehicules
    WHERE tenant_id = ?
    GROUP BY statut
");
$stmtFlotte->execute([$tenantId]);
$flotteRaw = $stmtFlotte->fetchAll();
$flotte = [];
foreach ($flotteRaw as $row) {
    $flotte[$row['statut']] = (int)$row['nb'];
}
$totalVehicules = array_sum($flotte);

// ── Helpers locaux ───────────────────────────────────────────────
$badgePaiement = function(string $statut): string {
    return match($statut) {
        'solde'    => '<span class="badge bg-success">Soldé</span>',
        'avance'   => '<span class="badge bg-warning">Avance</span>',
        default    => '<span class="badge bg-danger">Non payé</span>',
    };
};

$badgeStatutLoc = function(string $statut): string {
    return match($statut) {
        'en_cours'  => '<span class="badge bg-primary">En cours</span>',
        'terminee'  => '<span class="badge bg-success">Terminée</span>',
        'annulee'   => '<span class="badge bg-secondary">Annulée</span>',
        default     => '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>',
    };
};

$pageTitle  = 'Rapport journalier';
$activePage = 'rapport_jour';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h2 class="page-title"><i class="fas fa-calendar-day"></i> Rapport journalier</h2>
        <p class="page-subtitle"><?= date('d/m/Y', strtotime($date)) ?></p>
    </div>
    <div class="page-header-right">
        <form method="GET" style="display:flex;gap:.5rem;align-items:center;">
            <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($date) ?>"
                   max="<?= date('Y-m-d') ?>" style="width:180px;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i> Afficher
            </button>
            <?php if ($date !== date('Y-m-d')): ?>
            <a href="<?= BASE_URL ?>app/rapports/journalier.php" class="btn btn-secondary">
                <i class="fas fa-calendar-check"></i> Aujourd'hui
            </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?= renderFlashes() ?>

<!-- ── KPI cards ─────────────────────────────────────────────── -->
<div class="stats-grid" style="margin-bottom:1.5rem;">
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--primary);"><i class="fas fa-play-circle"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= count($locsDemarrees) ?></div>
            <div class="stat-label">Locations démarrées</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--success);"><i class="fas fa-flag-checkered"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= count($locsTerminees) ?></div>
            <div class="stat-label">Locations terminées</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--info);"><i class="fas fa-coins"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= formatMoney($revenuJour) ?></div>
            <div class="stat-label">Revenus du jour</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--warning);"><i class="fas fa-hand-holding-usd"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= formatMoney($paiementsJour) ?></div>
            <div class="stat-label">Paiements reçus</div>
        </div>
    </div>
</div>

<?php if (hasLocationModule()): ?>

<!-- ── Locations démarrées ──────────────────────────────────── -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3 style="margin:0;"><i class="fas fa-play-circle" style="color:var(--primary);"></i>
            Locations démarrées aujourd'hui
            <span class="badge bg-primary" style="margin-left:.5rem;"><?= count($locsDemarrees) ?></span>
        </h3>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($locsDemarrees)): ?>
            <div style="padding:2.5rem;text-align:center;">
                <i class="fas fa-inbox" style="font-size:2.5rem;color:var(--gray-300);margin-bottom:.75rem;display:block;"></i>
                <p style="color:var(--gray-500);margin:0;">Aucune location démarrée ce jour.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Véhicule</th>
                    <th>Client</th>
                    <th>Date début</th>
                    <th>Date fin prévue</th>
                    <th style="text-align:right;">Montant</th>
                    <th>Statut paiement</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($locsDemarrees as $loc): ?>
                <tr>
                    <td style="font-weight:600;"><?= sanitize($loc['veh_nom']) ?></td>
                    <td><?= sanitize($loc['client_nom']) ?></td>
                    <td style="white-space:nowrap;"><?= formatDate($loc['date_debut']) ?></td>
                    <td style="white-space:nowrap;"><?= $loc['date_fin'] ? formatDate($loc['date_fin']) : '<span style="color:var(--gray-400);">—</span>' ?></td>
                    <td style="text-align:right;font-weight:600;"><?= formatMoney((float)($loc['montant_final'] ?? 0)) ?></td>
                    <td><?= $badgePaiement($loc['statut_paiement'] ?? 'non_paye') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Locations terminées ──────────────────────────────────── -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3 style="margin:0;"><i class="fas fa-flag-checkered" style="color:var(--success);"></i>
            Locations terminées aujourd'hui
            <span class="badge bg-success" style="margin-left:.5rem;"><?= count($locsTerminees) ?></span>
        </h3>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($locsTerminees)): ?>
            <div style="padding:2.5rem;text-align:center;">
                <i class="fas fa-inbox" style="font-size:2.5rem;color:var(--gray-300);margin-bottom:.75rem;display:block;"></i>
                <p style="color:var(--gray-500);margin:0;">Aucune location terminée ce jour.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Véhicule</th>
                    <th>Client</th>
                    <th>Date début</th>
                    <th>Date fin</th>
                    <th style="text-align:right;">Montant</th>
                    <th>Statut paiement</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($locsTerminees as $loc): ?>
                <tr>
                    <td style="font-weight:600;"><?= sanitize($loc['veh_nom']) ?></td>
                    <td><?= sanitize($loc['client_nom']) ?></td>
                    <td style="white-space:nowrap;"><?= formatDate($loc['date_debut']) ?></td>
                    <td style="white-space:nowrap;"><?= formatDate($loc['date_fin']) ?></td>
                    <td style="text-align:right;font-weight:600;color:var(--success);"><?= formatMoney((float)($loc['montant_final'] ?? 0)) ?></td>
                    <td><?= $badgePaiement($loc['statut_paiement'] ?? 'non_paye') ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>app/locations/detail.php?id=<?= (int)$loc['id'] ?>"
                           class="btn btn-sm btn-secondary" title="Voir le détail">
                            <i class="fas fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; // hasLocationModule ?>

<!-- ── Charges du jour ───────────────────────────────────────── -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3 style="margin:0;"><i class="fas fa-receipt" style="color:var(--danger);"></i>
            Charges du jour
            <span class="badge bg-danger" style="margin-left:.5rem;"><?= count($charges) ?></span>
        </h3>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($charges)): ?>
            <div style="padding:2.5rem;text-align:center;">
                <i class="fas fa-receipt" style="font-size:2.5rem;color:var(--gray-300);margin-bottom:.75rem;display:block;"></i>
                <p style="color:var(--gray-500);margin:0;">Aucune charge enregistrée ce jour.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Véhicule</th>
                    <th>Libellé</th>
                    <th style="text-align:right;">Montant</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $typesChargeLabels = [
                    'carburant'   => ['label' => 'Carburant',   'color' => 'warning'],
                    'maintenance' => ['label' => 'Maintenance', 'color' => 'danger'],
                    'reparation'  => ['label' => 'Réparation',  'color' => 'danger'],
                    'assurance'   => ['label' => 'Assurance',   'color' => 'primary'],
                    'vignette'    => ['label' => 'Vignette',    'color' => 'info'],
                    'nettoyage'   => ['label' => 'Nettoyage',   'color' => 'info'],
                    'amende'      => ['label' => 'Amende',      'color' => 'secondary'],
                    'autre'       => ['label' => 'Autre',       'color' => 'secondary'],
                ];
                foreach ($charges as $ch):
                    $tc = $typesChargeLabels[$ch['type']] ?? ['label' => $ch['type'], 'color' => 'secondary'];
                ?>
                <tr>
                    <td><span class="badge bg-<?= $tc['color'] ?>"><?= sanitize($tc['label']) ?></span></td>
                    <td><?= sanitize($ch['veh_nom'] ?? '—') ?></td>
                    <td><?= sanitize($ch['libelle'] ?? '') ?></td>
                    <td style="text-align:right;font-weight:600;color:var(--danger);"><?= formatMoney((float)$ch['montant']) ?></td>
                    <td style="font-size:.85rem;color:var(--gray-600);"><?= sanitize($ch['notes'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" style="font-weight:700;text-align:right;">Total charges :</td>
                    <td style="text-align:right;font-weight:700;color:var(--danger);">
                        <?= formatMoney(array_sum(array_column($charges, 'montant'))) ?>
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Statut de la flotte ───────────────────────────────────── -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3 style="margin:0;"><i class="fas fa-car" style="color:var(--info);"></i>
            Statut de la flotte
            <?php if ($totalVehicules > 0): ?>
            <span class="badge bg-info" style="margin-left:.5rem;"><?= $totalVehicules ?> véhicule<?= $totalVehicules > 1 ? 's' : '' ?></span>
            <?php endif; ?>
        </h3>
    </div>
    <div class="card-body">
        <?php if ($totalVehicules === 0): ?>
            <div style="text-align:center;padding:1.5rem;">
                <i class="fas fa-car" style="font-size:2.5rem;color:var(--gray-300);margin-bottom:.75rem;display:block;"></i>
                <p style="color:var(--gray-500);margin:0;">Aucun véhicule enregistré.</p>
            </div>
        <?php else: ?>
        <div style="display:flex;flex-wrap:wrap;gap:1rem;">
            <?php
            $flotteConfig = [
                'disponible'   => ['label' => 'Disponible',   'color' => '#22c55e', 'icon' => 'fa-check-circle'],
                'loue'         => ['label' => 'Loué',         'color' => '#14b8a6', 'icon' => 'fa-key'],
                'maintenance'  => ['label' => 'Maintenance',  'color' => '#f59e0b', 'icon' => 'fa-wrench'],
                'indisponible' => ['label' => 'Indisponible', 'color' => '#ef4444', 'icon' => 'fa-ban'],
            ];
            foreach ($flotteConfig as $statut => $cfg):
                $nb = $flotte[$statut] ?? 0;
                if ($nb === 0) continue;
            ?>
            <div style="display:flex;align-items:center;gap:.75rem;background:var(--gray-50);border:1px solid var(--border);border-radius:8px;padding:.75rem 1.25rem;min-width:160px;">
                <div style="width:42px;height:42px;border-radius:50%;background:<?= $cfg['color'] ?>;display:flex;align-items:center;justify-content:center;">
                    <i class="fas <?= $cfg['icon'] ?>" style="color:#fff;font-size:1.1rem;"></i>
                </div>
                <div>
                    <div style="font-size:1.5rem;font-weight:700;line-height:1;"><?= $nb ?></div>
                    <div style="font-size:.8rem;color:var(--gray-500);"><?= $cfg['label'] ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($maintenances)): ?>
<!-- ── Maintenances effectuées ───────────────────────────────── -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3 style="margin:0;"><i class="fas fa-wrench" style="color:var(--warning);"></i>
            Maintenances effectuées aujourd'hui
            <span class="badge bg-warning" style="margin-left:.5rem;"><?= count($maintenances) ?></span>
        </h3>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Véhicule</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th style="text-align:right;">Coût</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($maintenances as $m): ?>
                <tr>
                    <td style="font-weight:600;"><?= sanitize($m['veh_nom']) ?></td>
                    <td><?= sanitize($m['type'] ?? '—') ?></td>
                    <td style="font-size:.9rem;"><?= sanitize($m['description'] ?? '') ?></td>
                    <td style="text-align:right;"><?= isset($m['cout']) ? formatMoney((float)$m['cout']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
