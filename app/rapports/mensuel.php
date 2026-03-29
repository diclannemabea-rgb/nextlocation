<?php
/**
 * FlotteCar - Rapport mensuel détaillé avec graphiques Chart.js
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

// ── Période ──────────────────────────────────────────────────────
$moisCourant   = (int)date('m');
$anneeCourante = (int)date('Y');
$mois  = max(1, min(12, (int)($_GET['mois']  ?? $moisCourant)));
$annee = max(2020,       (int)($_GET['annee'] ?? $anneeCourante));

$nomsMois = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];

// Navigation période
$datePeriode = new DateTime("$annee-$mois-01");
$datePrev    = (clone $datePeriode)->modify('-1 month');
$dateNext    = (clone $datePeriode)->modify('+1 month');

// ── Revenus jour par jour (paiements réels reçus) ───────────────
$stmtJours = $db->prepare("
    SELECT DAY(created_at) AS jour,
           COALESCE(SUM(montant), 0) AS revenus,
           COUNT(*) AS nb_locations
    FROM paiements
    WHERE tenant_id = ?
      AND MONTH(created_at) = ? AND YEAR(created_at) = ?
    GROUP BY DAY(created_at)
    ORDER BY jour
");
$stmtJours->execute([$tenantId, $mois, $annee]);
$parJourRaw = $stmtJours->fetchAll(PDO::FETCH_ASSOC);
$parJour = [];
foreach ($parJourRaw as $row) { $parJour[(int)$row['jour']] = $row; }

// Tableau jour → revenus pour graphique
$nbJoursMois = (int)(new DateTime("$annee-$mois-01"))->format('t');
$joursLabels  = [];
$joursRevenus = [];
for ($j = 1; $j <= $nbJoursMois; $j++) {
    $joursLabels[]  = $j;
    $joursRevenus[] = (float)($parJour[$j]['revenus'] ?? 0);
}

// ── Répartition par type de charge ──────────────────────────────
$stmtChgType = $db->prepare("
    SELECT type, COALESCE(SUM(montant), 0) AS total
    FROM charges
    WHERE tenant_id = ? AND MONTH(date_charge) = ? AND YEAR(date_charge) = ?
    GROUP BY type
");
$stmtChgType->execute([$tenantId, $mois, $annee]);
$chargesParType = $stmtChgType->fetchAll();

// ── Stats globales ───────────────────────────────────────────────
$stmtGlobal = $db->prepare("
    SELECT
        COUNT(*) AS total_locations,
        SUM(CASE WHEN statut='terminee' THEN 1 ELSE 0 END) AS terminées,
        SUM(CASE WHEN statut='en_cours' THEN 1 ELSE 0 END) AS en_cours,
        SUM(CASE WHEN statut='annulee'  THEN 1 ELSE 0 END) AS annulees,
        COALESCE(SUM(avance), 0) AS total_encaisse,
        COALESCE(SUM(nombre_jours), 0) AS total_jours
    FROM locations
    WHERE tenant_id = ? AND MONTH(date_debut) = ? AND YEAR(date_debut) = ?
");
$stmtGlobal->execute([$tenantId, $mois, $annee]);
$global = $stmtGlobal->fetch();

// Total charges
$stmtTotChg = $db->prepare("
    SELECT COALESCE(SUM(montant), 0) AS total
    FROM charges
    WHERE tenant_id = ? AND MONTH(date_charge) = ? AND YEAR(date_charge) = ?
");
$stmtTotChg->execute([$tenantId, $mois, $annee]);
$totalCharges = (float)$stmtTotChg->fetchColumn();
$beneficeNet  = (float)$global['total_encaisse'] - $totalCharges;

// ── Top véhicules ────────────────────────────────────────────────
$stmtTopVeh = $db->prepare("
    SELECT v.nom, v.immatriculation,
           COALESCE(SUM(l.avance), 0) AS revenus,
           COUNT(l.id) AS nb_loc,
           COALESCE(SUM(l.nombre_jours), 0) AS jours_loues
    FROM locations l
    JOIN vehicules v ON v.id = l.vehicule_id
    WHERE l.tenant_id = ?
      AND MONTH(l.date_debut) = ? AND YEAR(l.date_debut) = ?
    GROUP BY v.id
    ORDER BY revenus DESC
    LIMIT 5
");
$stmtTopVeh->execute([$tenantId, $mois, $annee]);
$topVeh = $stmtTopVeh->fetchAll();

// ── Véhicules inactifs ce mois ───────────────────────────────────
$stmtInactifs = $db->prepare("
    SELECT v.nom, v.immatriculation
    FROM vehicules v
    WHERE v.tenant_id = ?
      AND v.id NOT IN (
          SELECT DISTINCT vehicule_id FROM locations
          WHERE tenant_id = ? AND MONTH(date_debut) = ? AND YEAR(date_debut) = ?
      )
    ORDER BY v.nom
");
$stmtInactifs->execute([$tenantId, $tenantId, $mois, $annee]);
$inactifs = $stmtInactifs->fetchAll();

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmtExp = $db->prepare("
        SELECT v.nom AS vehicule, v.immatriculation,
               CONCAT(cl.prenom, ' ', cl.nom) AS client,
               l.date_debut, l.date_fin, l.nombre_jours, l.montant_final, l.statut
        FROM locations l
        JOIN vehicules v ON v.id = l.vehicule_id
        LEFT JOIN clients cl ON cl.id = l.client_id
        WHERE l.tenant_id = ? AND MONTH(l.date_debut) = ? AND YEAR(l.date_debut) = ?
        ORDER BY l.date_debut DESC
    ");
    $stmtExp->execute([$tenantId, $mois, $annee]);
    $expRows = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rapport_mensuel_' . $annee . '_' . str_pad($mois,2,'0',STR_PAD_LEFT) . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Véhicule','Immatriculation','Client','Début','Fin','Jours','Montant','Statut'], ';');
    foreach ($expRows as $row) {
        fputcsv($out, [
            $row['vehicule'], $row['immatriculation'], $row['client'],
            formatDate($row['date_debut']), formatDate($row['date_fin']),
            $row['nombre_jours'], $row['montant_final'], $row['statut']
        ], ';');
    }
    fclose($out);
    exit;
}

$pageTitle  = 'Rapport Mensuel';
$activePage = 'rapports';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h2 class="page-title"><i class="fas fa-chart-line"></i> Rapport Mensuel</h2>
        <p class="page-subtitle"><?= $nomsMois[$mois] ?> <?= $annee ?></p>
    </div>
    <div class="page-header-right" style="display:flex;gap:.5rem;align-items:center;">
        <a href="?mois=<?= $datePrev->format('n') ?>&annee=<?= $datePrev->format('Y') ?>" class="btn btn-secondary">
            <i class="fas fa-chevron-left"></i>
        </a>
        <form method="GET" style="display:flex;gap:.5rem;">
            <select name="mois" class="form-control" onchange="this.form.submit()">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m === $mois ? 'selected' : '' ?>><?= $nomsMois[$m] ?></option>
                <?php endfor; ?>
            </select>
            <select name="annee" class="form-control" onchange="this.form.submit()">
                <?php for ($a = $anneeCourante; $a >= 2020; $a--): ?>
                    <option value="<?= $a ?>" <?= $a === $annee ? 'selected' : '' ?>><?= $a ?></option>
                <?php endfor; ?>
            </select>
        </form>
        <?php if ($dateNext <= new DateTime()): ?>
        <a href="?mois=<?= $dateNext->format('n') ?>&annee=<?= $dateNext->format('Y') ?>" class="btn btn-secondary">
            <i class="fas fa-chevron-right"></i>
        </a>
        <?php endif; ?>
        <a href="?mois=<?= $mois ?>&annee=<?= $annee ?>&export=csv" class="btn btn-success">
            <i class="fas fa-file-csv"></i> CSV
        </a>
        <a href="<?= BASE_URL ?>app/rapports/index.php" class="btn btn-secondary">
            <i class="fas fa-calendar-day"></i> Journalier
        </a>
    </div>
</div>

<?= renderFlashes() ?>

<!-- Stats globales -->
<div class="stats-grid" style="margin-bottom:1.5rem;">
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--success);"><i class="fas fa-coins"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= formatMoney((float)$global['total_encaisse']) ?></div>
            <div class="stat-label">Revenus encaissés</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--danger);"><i class="fas fa-receipt"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= formatMoney($totalCharges) ?></div>
            <div class="stat-label">Charges totales</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:<?= $beneficeNet >= 0 ? 'var(--primary)' : 'var(--danger)' ?>;"><i class="fas fa-balance-scale"></i></div>
        <div class="stat-content">
            <div class="stat-value" style="color:<?= $beneficeNet >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                <?= ($beneficeNet >= 0 ? '+' : '') . formatMoney($beneficeNet) ?>
            </div>
            <div class="stat-label">Bénéfice net</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--info);"><i class="fas fa-file-contract"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= (int)$global['total_locations'] ?></div>
            <div class="stat-label"><?= (int)$global['terminées'] ?> terminées · <?= (int)$global['en_cours'] ?> en cours</div>
        </div>
    </div>
</div>

<!-- Graphique revenus par jour -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header"><h3 style="margin:0;">Revenus jour par jour — <?= $nomsMois[$mois] ?> <?= $annee ?></h3></div>
    <div class="card-body"><canvas id="chartJours" height="80"></canvas></div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">
    <!-- Top véhicules -->
    <div class="card">
        <div class="card-header"><h3 style="margin:0;"><i class="fas fa-trophy" style="color:var(--warning);"></i> Top Véhicules</h3></div>
        <div class="card-body">
            <?php if (empty($topVeh)): ?>
                <p style="color:var(--gray-500);text-align:center;">Aucune donnée.</p>
            <?php else: ?>
            <table class="table">
                <thead><tr><th>#</th><th>Véhicule</th><th style="text-align:right;">Revenus</th><th style="text-align:center;">Jours</th><th style="text-align:center;">Locations</th></tr></thead>
                <tbody>
                    <?php foreach ($topVeh as $i => $tv): ?>
                    <tr>
                        <td><span class="badge bg-<?= ['warning','secondary','info','primary','success'][$i] ?? 'secondary' ?>"><?= $i+1 ?></span></td>
                        <td>
                            <div style="font-weight:600;"><?= sanitize($tv['nom']) ?></div>
                            <div style="font-size:.8rem;color:var(--gray-500);font-family:monospace;"><?= sanitize($tv['immatriculation']) ?></div>
                        </td>
                        <td style="text-align:right;font-weight:700;color:var(--success);"><?= formatMoney((float)$tv['revenus']) ?></td>
                        <td style="text-align:center;"><?= (int)$tv['jours_loues'] ?></td>
                        <td style="text-align:center;"><?= (int)$tv['nb_loc'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Répartition charges -->
    <div class="card">
        <div class="card-header"><h3 style="margin:0;">Répartition charges</h3></div>
        <div class="card-body">
            <?php if (empty($chargesParType)): ?>
                <p style="color:var(--gray-500);text-align:center;">Aucune charge.</p>
            <?php else: ?>
            <canvas id="chartCharges" height="160"></canvas>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Véhicules inactifs -->
<?php if (!empty($inactifs)): ?>
<div class="card">
    <div class="card-header">
        <h3 style="margin:0;"><i class="fas fa-exclamation-triangle" style="color:var(--warning);"></i>
            Véhicules sans activité ce mois (<?= count($inactifs) ?>)
        </h3>
    </div>
    <div class="card-body">
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
            <?php foreach ($inactifs as $vi): ?>
                <span class="badge bg-secondary" style="padding:.5rem .75rem;font-size:.85rem;">
                    <?= sanitize($vi['nom']) ?> — <?= sanitize($vi['immatriculation']) ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// Données JSON pour Chart.js
$joursLabelsJson  = json_encode($joursLabels);
$joursRevenusJson = json_encode($joursRevenus);
$chgLabels = json_encode(array_column($chargesParType, 'type'));
$chgValues = json_encode(array_map(fn($c) => round($c['total'], 0), $chargesParType));

$extraJs = <<<JS
(function() {
    function initCharts() {
        // Graphique revenus par jour
        var ctxJ = document.getElementById('chartJours');
        if (ctxJ) {
            new Chart(ctxJ, {
                type: 'bar',
                data: {
                    labels: $joursLabelsJson,
                    datasets: [{
                        label: 'Revenus',
                        data: $joursRevenusJson,
                        backgroundColor: 'rgba(34,197,94,.6)',
                        borderColor: 'rgba(34,197,94,1)',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { callback: function(v) { return new Intl.NumberFormat('fr-FR').format(v); } }
                        }
                    }
                }
            });
        }
        // Graphique charges par type (donut)
        var ctxC = document.getElementById('chartCharges');
        if (ctxC) {
            new Chart(ctxC, {
                type: 'doughnut',
                data: {
                    labels: $chgLabels,
                    datasets: [{
                        data: $chgValues,
                        backgroundColor: ['#f59e0b','#ef4444','#14b8a6','#8b5cf6','#06b6d4','#6b7280','#dc2626']
                    }]
                },
                options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
            });
        }
    }
    if (typeof Chart !== 'undefined') {
        initCharts();
    } else {
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js';
        s.onload = initCharts;
        document.head.appendChild(s);
    }
})();
JS;
require_once BASE_PATH . '/includes/footer.php';
?>
