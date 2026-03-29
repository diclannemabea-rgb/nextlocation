<?php
/**
 * FlotteCar - Statistiques plateforme (Super Admin)
 */

define('BASE_PATH', dirname(__DIR__));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';

requireSuperAdmin();

$database = new Database();
$db = $database->getConnection();

// -------------------------------------------------------
// REQUÊTES STATISTIQUES
// -------------------------------------------------------

// KPIs principaux
$totalTenants    = (int)$db->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
$tenantsActifs   = (int)$db->query("SELECT COUNT(*) FROM tenants WHERE actif=1")->fetchColumn();
$totalVehicules  = (int)$db->query("SELECT COUNT(*) FROM vehicules")->fetchColumn();
$totalLocations  = (int)$db->query("SELECT COUNT(*) FROM locations")->fetchColumn();
$revenusAbo      = (float)$db->query("SELECT COALESCE(SUM(prix),0) FROM abonnements WHERE statut='actif'")->fetchColumn();

// Répartition par plan
$planDist = $db->query("SELECT plan, COUNT(*) nb FROM tenants GROUP BY plan")->fetchAll(PDO::FETCH_KEY_PAIR);

// Répartition par type d'usage
$typeDist = $db->query("SELECT type_usage, COUNT(*) nb FROM tenants GROUP BY type_usage")->fetchAll(PDO::FETCH_KEY_PAIR);

// Croissance mensuelle (6 derniers mois)
$croissance = $db->query("
    SELECT DATE_FORMAT(created_at,'%Y-%m') as mois, COUNT(*) nb
    FROM tenants
    GROUP BY mois
    ORDER BY mois DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// Top 10 tenants par véhicules
$topTenants = $db->query("
    SELECT t.nom_entreprise, COUNT(v.id) as nb_vehicules
    FROM tenants t
    LEFT JOIN vehicules v ON v.tenant_id = t.id
    GROUP BY t.id
    ORDER BY nb_vehicules DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Helpers pour les barres de progression
$maxPlan = max(array_values($planDist) ?: [1]);
$maxType = max(array_values($typeDist) ?: [1]);
$maxVeh  = max(array_column($topTenants, 'nb_vehicules') ?: [1]);

// -------------------------------------------------------
// MISE EN PAGE
// -------------------------------------------------------
$activePage = 'admin_stats';
$pageTitle  = 'Statistiques plateforme';
require_once BASE_PATH . '/includes/header.php';
?>

<style>
@media(max-width:768px) {
    .page-header { flex-direction:column; gap:10px; }
    .grid-2col { grid-template-columns:1fr !important; }
    .table thead { display:none; }
    .table tbody tr { display:block; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:10px; padding:12px; background:#fff; }
    .table tbody td { display:flex; justify-content:space-between; align-items:center; padding:4px 0; border-bottom:none; }
}
</style>
<div class="main-content">
    <div class="container">

        <?= renderFlashes() ?>

        <div class="page-header">
            <div>
                <h1 class="page-title">Statistiques plateforme</h1>
                <p class="page-subtitle">Vue d'ensemble de l'activité FlotteCar</p>
            </div>
        </div>

        <!-- KPI stat-cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-card-icon" style="background:linear-gradient(135deg,#0d9488,#14b8a6)">
                    <i class="fas fa-building"></i>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value"><?= $tenantsActifs ?> / <?= $totalTenants ?></div>
                    <div class="stat-card-label">Tenants actifs</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon" style="background:linear-gradient(135deg,#059669,#10b981)">
                    <i class="fas fa-car"></i>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value"><?= number_format($totalVehicules, 0, ',', ' ') ?></div>
                    <div class="stat-card-label">Total véhicules</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon" style="background:linear-gradient(135deg,#d97706,#f59e0b)">
                    <i class="fas fa-key"></i>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value"><?= number_format($totalLocations, 0, ',', ' ') ?></div>
                    <div class="stat-card-label">Total locations</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon" style="background:linear-gradient(135deg,#7c3aed,#8b5cf6)">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value"><?= formatMoney($revenusAbo) ?></div>
                    <div class="stat-card-label">Revenus abonnements</div>
                </div>
            </div>
        </div>

        <!-- Répartitions -->
        <div class="grid-2col" style="margin-top:24px">

            <!-- Répartition par plan -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-layer-group" style="color:#0d9488;margin-right:8px"></i>Répartition par plan</h3>
                </div>
                <div class="card-body">
                    <?php
                    $plans = [
                        'starter'    => ['label' => 'Starter',    'color' => '#6b7280'],
                        'pro'        => ['label' => 'Pro',        'color' => '#0d9488'],
                        'enterprise' => ['label' => 'Enterprise', 'color' => '#f59e0b'],
                    ];
                    foreach ($plans as $key => $info):
                        $nb  = $planDist[$key] ?? 0;
                        $pct = $maxPlan > 0 ? round($nb / $maxPlan * 100) : 0;
                    ?>
                    <div style="margin-bottom:16px">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                            <span style="font-weight:500;font-size:.9rem"><?= $info['label'] ?></span>
                            <span style="font-weight:700;font-size:.95rem;color:<?= $info['color'] ?>"><?= $nb ?> tenant<?= $nb > 1 ? 's' : '' ?></span>
                        </div>
                        <div style="background:#f3f4f6;border-radius:4px;height:8px;overflow:hidden">
                            <div style="height:8px;border-radius:4px;background:<?= $info['color'] ?>;width:<?= $pct ?>%;transition:width .4s"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Répartition par type d'usage -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-chart-pie" style="color:#059669;margin-right:8px"></i>Répartition par type d'usage</h3>
                </div>
                <div class="card-body">
                    <?php
                    $types = [
                        'location'  => ['label' => 'Location uniquement', 'badge' => 'bg-primary'],
                        'controle'  => ['label' => 'Contrôle GPS uniquement', 'badge' => 'bg-info'],
                        'les_deux'  => ['label' => 'Location + GPS', 'badge' => 'bg-success'],
                    ];
                    foreach ($types as $key => $info):
                        $nb  = $typeDist[$key] ?? 0;
                        $pct = $totalTenants > 0 ? round($nb / $totalTenants * 100) : 0;
                    ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid #f3f4f6">
                        <div style="display:flex;align-items:center;gap:10px">
                            <span class="badge <?= $info['badge'] ?>"><?= $pct ?>%</span>
                            <span style="font-size:.9rem"><?= $info['label'] ?></span>
                        </div>
                        <span style="font-weight:700;font-size:1.1rem"><?= $nb ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>

        <!-- Top 10 tenants -->
        <div class="card" style="margin-top:24px">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-trophy" style="color:#f59e0b;margin-right:8px"></i>Top 10 tenants (véhicules)</h3>
            </div>
            <div class="card-body" style="padding:0">
                <?php if (empty($topTenants)): ?>
                    <div style="text-align:center;padding:32px;color:#9ca3af">
                        <i class="fas fa-inbox fa-2x" style="margin-bottom:8px;display:block"></i>
                        Aucun tenant enregistré
                    </div>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width:48px">#</th>
                            <th>Entreprise</th>
                            <th>Véhicules</th>
                            <th style="width:200px">Proportion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topTenants as $i => $t): ?>
                        <tr>
                            <td>
                                <?php if ($i === 0): ?>
                                    <span style="color:#f59e0b;font-weight:700">🥇</span>
                                <?php elseif ($i === 1): ?>
                                    <span style="color:#9ca3af;font-weight:700">🥈</span>
                                <?php elseif ($i === 2): ?>
                                    <span style="color:#b45309;font-weight:700">🥉</span>
                                <?php else: ?>
                                    <span style="color:#6b7280"><?= $i + 1 ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:500"><?= sanitize($t['nom_entreprise']) ?></td>
                            <td><strong><?= $t['nb_vehicules'] ?></strong></td>
                            <td>
                                <div style="background:#f3f4f6;border-radius:4px;height:8px;overflow:hidden">
                                    <div style="height:8px;border-radius:4px;background:#0d9488;width:<?= $maxVeh > 0 ? round($t['nb_vehicules'] / $maxVeh * 100) : 0 ?>%"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Croissance mensuelle -->
        <div class="card" style="margin-top:24px">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-line" style="color:#7c3aed;margin-right:8px"></i>Croissance mensuelle</h3>
                <small style="color:#6b7280">6 derniers mois</small>
            </div>
            <div class="card-body" style="padding:0">
                <?php if (empty($croissance)): ?>
                    <div style="text-align:center;padding:32px;color:#9ca3af">
                        <i class="fas fa-inbox fa-2x" style="margin-bottom:8px;display:block"></i>
                        Aucune donnée
                    </div>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Mois</th>
                            <th>Nouveaux tenants</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($croissance as $row):
                            [$yr, $mo] = explode('-', $row['mois']);
                            $moisLabel = date('F Y', mktime(0, 0, 0, (int)$mo, 1, (int)$yr));
                        ?>
                        <tr>
                            <td><?= ucfirst($moisLabel) ?></td>
                            <td>
                                <span class="badge bg-primary"><?= $row['nb'] ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
