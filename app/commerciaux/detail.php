<?php
/**
 * FlotteCar — Commercial : fiche détail
 * GET ?id=X  |  POST (modifier)
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
require_once BASE_PATH . '/models/CommercialModel.php';

requireTenantAuth();

if (!hasLocationModule()) {
    setFlash(FLASH_ERROR, 'Module Locations requis.');
    redirect(BASE_URL . 'app/dashboard.php');
}

$db       = (new Database())->getConnection();
$tenantId = getTenantId();
$model    = new CommercialModel($db);

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    setFlash(FLASH_ERROR, 'Commercial introuvable.');
    redirect(BASE_URL . 'app/commerciaux/index.php');
}

// -------------------------------------------------------
// ACTION POST — modifier
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'modifier') {
        $postId = (int) ($_POST['id'] ?? 0);
        if ($postId !== $id) {
            setFlash(FLASH_ERROR, 'Requête invalide.');
            redirect(BASE_URL . "app/commerciaux/detail.php?id=$id");
        }

        $nom       = trim($_POST['nom']       ?? '');
        $prenom    = trim($_POST['prenom']    ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $email     = trim($_POST['email']     ?? '');
        $commPct   = (float) ($_POST['commission_pct'] ?? 0);
        $notes     = trim($_POST['notes']     ?? '');

        if ($nom === '') {
            setFlash(FLASH_ERROR, 'Le nom est obligatoire.');
        } else {
            $model->update($id, $tenantId, [
                'nom'            => $nom,
                'prenom'         => $prenom,
                'telephone'      => $telephone,
                'email'          => $email,
                'commission_pct' => $commPct,
                'notes'          => $notes,
            ]);
            setFlash(FLASH_SUCCESS, 'Commercial mis à jour.');
            logActivite($db, 'update', 'commerciaux', "Modification commercial ID $id");
        }
        redirect(BASE_URL . "app/commerciaux/detail.php?id=$id");
    }
}

// -------------------------------------------------------
// DONNÉES — commercial
// -------------------------------------------------------
$commercial = $model->getById($id, $tenantId);
if (!$commercial) {
    setFlash(FLASH_ERROR, 'Commercial introuvable.');
    redirect(BASE_URL . 'app/commerciaux/index.php');
}

// Stats globales
$stats = $model->getStats($id, $tenantId);

// Commission payée — vérifier si la colonne existe
$commissionPayee = 0.0;
try {
    $commPaidStmt = $db->prepare(
        "SELECT COALESCE(SUM(commission_payee), 0) FROM locations
         WHERE commercial_id = ? AND tenant_id = ? AND statut != 'annulee'"
    );
    $commPaidStmt->execute([$id, $tenantId]);
    $commissionPayee = (float) $commPaidStmt->fetchColumn();
} catch (Throwable $e) {
    $commissionPayee = 0.0;
}
$commissionRestante = max(0, $stats['commission_earned'] - $commissionPayee);

// Locations apportées
$locStmt = $db->prepare(
    "SELECT l.*, v.nom AS veh_nom, CONCAT(c.nom, ' ', COALESCE(c.prenom,'')) AS client_nom
     FROM locations l
     JOIN vehicules v ON v.id = l.vehicule_id
     JOIN clients   c ON c.id = l.client_id
     WHERE l.commercial_id = ? AND l.tenant_id = ?
     ORDER BY l.date_debut DESC"
);
$locStmt->execute([$id, $tenantId]);
$locations = $locStmt->fetchAll(PDO::FETCH_ASSOC);

// Chart data — CA par mois (12 derniers mois)
$chartStmt = $db->prepare(
    "SELECT DATE_FORMAT(date_debut, '%Y-%m') AS mois,
            SUM(montant_final)               AS revenus,
            COUNT(*)                         AS nb
     FROM locations
     WHERE commercial_id = ? AND tenant_id = ?
       AND date_debut >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
     GROUP BY mois
     ORDER BY mois ASC"
);
$chartStmt->execute([$id, $tenantId]);
$chartRows  = $chartStmt->fetchAll(PDO::FETCH_ASSOC);
$chartMois  = array_column($chartRows, 'mois');
$chartCA    = array_column($chartRows, 'revenus');

// -------------------------------------------------------
// PAGE
// -------------------------------------------------------
$nomComplet  = sanitize($commercial['nom']) . ($commercial['prenom'] ? ' ' . sanitize($commercial['prenom']) : '');
$pageTitle   = "Commercial — $nomComplet";
$activePage  = 'commerciaux';

// Extra JS : Chart.js CDN + init inline
ob_start();
?>
// Chart.js chargé via CDN ci-dessus
(function () {
    const labels = <?= json_encode($chartMois) ?>;
    const data   = <?= json_encode(array_map('floatval', $chartCA)) ?>;

    if (!labels.length) return;

    const ctx = document.getElementById('chartCA');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'CA apporté (FCFA)',
                data: data,
                backgroundColor: 'rgba(26,86,219,0.7)',
                borderColor: '#0d9488',
                borderWidth: 1,
                borderRadius: 4,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => new Intl.NumberFormat('fr-FR').format(ctx.parsed.y) + ' FCFA'
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: v => new Intl.NumberFormat('fr-FR').format(v)
                    }
                }
            }
        }
    });
})();
<?php
$extraJs = '<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
' . ob_get_clean() . '
</script>';

require_once BASE_PATH . '/includes/header.php';
?>

<!-- Back -->
<div style="margin-bottom:16px">
    <a href="<?= BASE_URL ?>app/commerciaux/index.php" class="btn btn-ghost">
        <i class="fas fa-arrow-left"></i> Retour à la liste
    </a>
</div>

<!-- Page header -->
<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="fas fa-user-tie"></i>
            <?= $nomComplet ?>
        </h1>
        <p class="page-subtitle">
            Apporteur d'affaires &mdash; commission <?= $commercial['commission_pct'] ?>%
            &nbsp;
            <span class="badge <?= $commercial['statut'] === 'actif' ? 'bg-success' : 'bg-secondary' ?>">
                <?= ucfirst(sanitize($commercial['statut'])) ?>
            </span>
        </p>
    </div>
    <button class="btn btn-primary" onclick="openModal('modal-edit')">
        <i class="fas fa-edit"></i> Modifier
    </button>
</div>

<?= renderFlashes() ?>

<!-- KPI -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px">
    <div class="stat-card primary">
        <div class="stat-icon"><i class="fas fa-handshake"></i></div>
        <div class="stat-value"><?= $stats['nb_locations'] ?></div>
        <div class="stat-label">Locations apportées</div>
    </div>
    <div class="stat-card success">
        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
        <div class="stat-value"><?= formatMoney($stats['chiffre_affaires']) ?></div>
        <div class="stat-label">CA total apporté</div>
    </div>
    <div class="stat-card warning">
        <div class="stat-icon"><i class="fas fa-coins"></i></div>
        <div class="stat-value"><?= formatMoney($stats['commission_earned']) ?></div>
        <div class="stat-label">Commission due</div>
    </div>
    <div class="stat-card <?= $commissionRestante > 0 ? 'danger' : 'info' ?>">
        <div class="stat-icon"><i class="fas fa-wallet"></i></div>
        <div class="stat-value"><?= formatMoney($commissionRestante) ?></div>
        <div class="stat-label">Commission restante</div>
    </div>
</div>

<!-- Layout 2 colonnes -->
<div class="grid-2col" style="gap:24px;align-items:start">

    <!-- COLONNE PRINCIPALE -->
    <div style="grid-column:1/3">

        <!-- Graphique CA mensuel -->
        <?php if (!empty($chartRows)): ?>
        <div class="card" style="margin-bottom:24px">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-bar"></i> CA apporté — 12 derniers mois</h3>
            </div>
            <div class="card-body" style="height:260px;padding:16px">
                <canvas id="chartCA"></canvas>
            </div>
        </div>
        <?php endif ?>

        <!-- Table locations -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-list"></i>
                    Locations apportées
                    <span class="badge bg-primary" style="margin-left:8px"><?= count($locations) ?></span>
                </h3>
            </div>
            <?php if (empty($locations)): ?>
                <div class="card-body" style="text-align:center;padding:40px;color:var(--text-muted)">
                    <i class="fas fa-inbox" style="font-size:2rem;display:block;margin-bottom:12px"></i>
                    Aucune location apportée pour l'instant.
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date début</th>
                            <th>Véhicule</th>
                            <th>Client</th>
                            <th>Jours</th>
                            <th>Montant</th>
                            <th>Commission</th>
                            <th>Paiement</th>
                            <th>Statut</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($locations as $loc):
                        $jours     = max(1, (int) calculateDays($loc['date_debut'], $loc['date_fin']));
                        $commMont  = round((float)$loc['montant_final'] * (float)$commercial['commission_pct'] / 100, 0);
                    ?>
                        <tr>
                            <td><?= formatDate($loc['date_debut']) ?></td>
                            <td><?= sanitize($loc['veh_nom']) ?></td>
                            <td><?= sanitize(trim($loc['client_nom'])) ?></td>
                            <td><?= $jours ?> j</td>
                            <td><?= formatMoney($loc['montant_final']) ?></td>
                            <td><strong><?= formatMoney($commMont) ?></strong></td>
                            <td><?= badgePaiement($loc['statut_paiement'] ?? '') ?></td>
                            <td><?= badgeLocation($loc['statut'] ?? '') ?></td>
                            <td>
                                <a href="<?= BASE_URL ?>app/locations/detail.php?id=<?= $loc['id'] ?>"
                                   class="btn btn-sm btn-ghost" title="Voir la location">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php endif ?>
        </div>

    </div><!-- /colonne principale -->

</div><!-- /grid -->

<!-- Panneau latéral info (affiché sous la grille sur grand écran via grid-2col) -->
<div class="card" style="margin-top:24px">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-id-card"></i> Informations</h3>
        <button class="btn btn-sm btn-outline-primary" onclick="openModal('modal-edit')">
            <i class="fas fa-edit"></i> Modifier
        </button>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px">
            <div>
                <p style="margin:0;color:var(--text-muted);font-size:.8rem;text-transform:uppercase;letter-spacing:.04em">Nom complet</p>
                <p style="margin:4px 0 0;font-weight:600"><?= $nomComplet ?></p>
            </div>
            <div>
                <p style="margin:0;color:var(--text-muted);font-size:.8rem;text-transform:uppercase;letter-spacing:.04em">Téléphone</p>
                <p style="margin:4px 0 0">
                    <?php if ($commercial['telephone']): ?>
                        <a href="tel:<?= sanitize($commercial['telephone']) ?>" style="color:inherit">
                            <?= sanitize($commercial['telephone']) ?>
                        </a>
                    <?php else: ?>
                        <span style="color:var(--text-muted)">—</span>
                    <?php endif ?>
                </p>
            </div>
            <div>
                <p style="margin:0;color:var(--text-muted);font-size:.8rem;text-transform:uppercase;letter-spacing:.04em">Email</p>
                <p style="margin:4px 0 0">
                    <?php if ($commercial['email']): ?>
                        <a href="mailto:<?= sanitize($commercial['email']) ?>" style="color:inherit">
                            <?= sanitize($commercial['email']) ?>
                        </a>
                    <?php else: ?>
                        <span style="color:var(--text-muted)">—</span>
                    <?php endif ?>
                </p>
            </div>
            <div>
                <p style="margin:0;color:var(--text-muted);font-size:.8rem;text-transform:uppercase;letter-spacing:.04em">Taux commission</p>
                <p style="margin:4px 0 0;font-weight:600"><?= $commercial['commission_pct'] ?>%</p>
            </div>
            <div>
                <p style="margin:0;color:var(--text-muted);font-size:.8rem;text-transform:uppercase;letter-spacing:.04em">Statut</p>
                <p style="margin:4px 0 0">
                    <span class="badge <?= $commercial['statut'] === 'actif' ? 'bg-success' : 'bg-secondary' ?>">
                        <?= ucfirst(sanitize($commercial['statut'])) ?>
                    </span>
                </p>
            </div>
        </div>

        <?php if (!empty($commercial['notes'])): ?>
        <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">
        <div>
            <p style="margin:0 0 6px;color:var(--text-muted);font-size:.8rem;text-transform:uppercase;letter-spacing:.04em">Notes</p>
            <p style="margin:0;white-space:pre-line"><?= sanitize($commercial['notes']) ?></p>
        </div>
        <?php endif ?>
    </div>
</div>

<!-- ============================================================
     MODAL ÉDITION
     ============================================================ -->
<div id="modal-edit" class="modal-overlay">
    <div class="modal" style="max-width:520px">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Modifier le commercial</h3>
            <button class="modal-close" onclick="closeModal('modal-edit')">&times;</button>
        </div>
        <form method="POST" action="<?= BASE_URL ?>app/commerciaux/detail.php?id=<?= $id ?>" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="modifier">
            <input type="hidden" name="id"     value="<?= $id ?>">

            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Nom *</label>
                    <input type="text" name="nom" class="form-control"
                           value="<?= sanitize($commercial['nom']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Prénom</label>
                    <input type="text" name="prenom" class="form-control"
                           value="<?= sanitize($commercial['prenom'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Téléphone</label>
                    <input type="tel" name="telephone" class="form-control"
                           value="<?= sanitize($commercial['telephone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= sanitize($commercial['email'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Commission (%)</label>
                <input type="number" name="commission_pct" class="form-control"
                       value="<?= $commercial['commission_pct'] ?>"
                       min="0" max="100" step="0.5">
                <span class="form-hint">Pourcentage du montant final de la location</span>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="3"><?= sanitize($commercial['notes'] ?? '') ?></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
