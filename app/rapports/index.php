<?php
/**
 * FlotteCar - Rapports (journalier & mensuel)
 * Export CSV disponible
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

// ── Paramètres ───────────────────────────────────────────────────
$typeRapport = $_GET['type'] ?? 'journalier'; // journalier | mensuel | personnalise
$dateJour    = $_GET['date']  ?? date('Y-m-d');
$mois        = max(1, min(12, (int)($_GET['mois']  ?? date('m'))));
$annee       = max(2020,       (int)($_GET['annee'] ?? date('Y')));
$dateDebut   = $_GET['date_debut'] ?? date('Y-m-01');
$dateFin     = $_GET['date_fin']   ?? date('Y-m-d');

$nomsMois = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];

// ── Export CSV ───────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Requête selon type
    if ($typeRapport === 'journalier') {
        $stmt = $db->prepare("
            SELECT v.nom AS vehicule, v.immatriculation,
                   CONCAT(cl.prenom, ' ', cl.nom) AS client,
                   l.date_debut, l.date_fin, l.nombre_jours, l.montant_final, l.statut
            FROM locations l
            JOIN vehicules v  ON v.id = l.vehicule_id
            LEFT JOIN clients cl ON cl.id = l.client_id
            WHERE l.tenant_id = ? AND DATE(l.date_debut) = ?
            ORDER BY l.created_at DESC
        ");
        $stmt->execute([$tenantId, $dateJour]);
    } else {
        $stmt = $db->prepare("
            SELECT v.nom AS vehicule, v.immatriculation,
                   CONCAT(cl.prenom, ' ', cl.nom) AS client,
                   l.date_debut, l.date_fin, l.nombre_jours, l.montant_final, l.statut
            FROM locations l
            JOIN vehicules v  ON v.id = l.vehicule_id
            LEFT JOIN clients cl ON cl.id = l.client_id
            WHERE l.tenant_id = ? AND MONTH(l.date_debut) = ? AND YEAR(l.date_debut) = ?
            ORDER BY l.date_debut DESC
        ");
        $stmt->execute([$tenantId, $mois, $annee]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rapport_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
    fputcsv($output, ['Véhicule', 'Immatriculation', 'Client', 'Début', 'Fin', 'Jours', 'Montant', 'Statut'], ';');
    foreach ($rows as $r) {
        fputcsv($output, [
            $r['vehicule'], $r['immatriculation'], $r['client'],
            formatDate($r['date_debut']), formatDate($r['date_fin']),
            $r['nombre_jours'], $r['montant_final'], $r['statut']
        ], ';');
    }
    fclose($output);
    exit;
}

// ════════════════════════════════════════════════════════════════
// DONNÉES RAPPORT JOURNALIER
// ════════════════════════════════════════════════════════════════
if ($typeRapport === 'journalier') {

    // Locations du jour (nouvelles et terminées)
    $stmtLoc = $db->prepare("
        SELECT l.*, v.nom AS veh_nom, v.immatriculation,
               CONCAT(cl.prenom, ' ', cl.nom) AS client_nom
        FROM locations l
        JOIN vehicules v  ON v.id = l.vehicule_id
        LEFT JOIN clients cl ON cl.id = l.client_id
        WHERE l.tenant_id = ? AND (DATE(l.date_debut) = ? OR DATE(l.date_fin) = ?)
        ORDER BY l.created_at DESC
    ");
    $stmtLoc->execute([$tenantId, $dateJour, $dateJour]);
    $locationsJour = $stmtLoc->fetchAll();

    // Encaissements du jour (paiements reçus)
    $stmtEnc = $db->prepare("
        SELECT COALESCE(SUM(montant), 0) as total
        FROM paiements
        WHERE tenant_id = ? AND DATE(date_paiement) = ?
    ");
    try {
        $stmtEnc->execute([$tenantId, $dateJour]);
        $encaissementsJour = (float)$stmtEnc->fetchColumn();
    } catch (Exception $e) {
        $encaissementsJour = 0;
    }

    // Charges du jour
    $stmtChg = $db->prepare("
        SELECT COALESCE(SUM(montant), 0) as total
        FROM charges
        WHERE tenant_id = ? AND DATE(date_charge) = ?
    ");
    $stmtChg->execute([$tenantId, $dateJour]);
    $chargesJour = (float)$stmtChg->fetchColumn();

    $beneficeJour = $encaissementsJour - $chargesJour;
}

// ════════════════════════════════════════════════════════════════
// DONNÉES RAPPORT MENSUEL
// ════════════════════════════════════════════════════════════════
if ($typeRapport === 'mensuel') {

    // Évolution par semaine
    $stmtSem = $db->prepare("
        SELECT WEEK(date_debut, 1) AS semaine,
               COALESCE(SUM(montant_final), 0) AS revenus,
               COUNT(*) AS nb_locations
        FROM locations
        WHERE tenant_id = ? AND statut = 'terminee'
          AND MONTH(date_debut) = ? AND YEAR(date_debut) = ?
        GROUP BY WEEK(date_debut, 1)
        ORDER BY semaine
    ");
    $stmtSem->execute([$tenantId, $mois, $annee]);
    $parSemaine = $stmtSem->fetchAll();

    // Top 3 véhicules
    $stmtVeh = $db->prepare("
        SELECT v.nom, v.immatriculation,
               COALESCE(SUM(l.montant_final), 0) AS revenus,
               COUNT(l.id) AS nb_locations
        FROM locations l
        JOIN vehicules v ON v.id = l.vehicule_id
        WHERE l.tenant_id = ? AND l.statut = 'terminee'
          AND MONTH(l.date_debut) = ? AND YEAR(l.date_debut) = ?
        GROUP BY v.id
        ORDER BY revenus DESC
        LIMIT 3
    ");
    $stmtVeh->execute([$tenantId, $mois, $annee]);
    $topVehicules = $stmtVeh->fetchAll();

    // Top 3 clients
    $stmtCli = $db->prepare("
        SELECT CONCAT(cl.prenom, ' ', cl.nom) AS client_nom,
               COALESCE(SUM(l.montant_final), 0) AS total_depense,
               COUNT(l.id) AS nb_locations
        FROM locations l
        JOIN clients cl ON cl.id = l.client_id
        WHERE l.tenant_id = ? AND l.statut = 'terminee'
          AND MONTH(l.date_debut) = ? AND YEAR(l.date_debut) = ?
        GROUP BY cl.id
        ORDER BY total_depense DESC
        LIMIT 3
    ");
    $stmtCli->execute([$tenantId, $mois, $annee]);
    $topClients = $stmtCli->fetchAll();

    // Stats globales du mois
    $stmtGlobal = $db->prepare("
        SELECT
            COUNT(*) AS total_locations,
            SUM(CASE WHEN statut = 'terminee' THEN 1 ELSE 0 END) AS terminées,
            SUM(CASE WHEN statut = 'en_cours' THEN 1 ELSE 0 END) AS en_cours,
            COALESCE(SUM(CASE WHEN statut = 'terminee' THEN montant_final END), 0) AS total_encaisse,
            COALESCE(SUM(montant_final), 0) AS total_du
        FROM locations
        WHERE tenant_id = ? AND MONTH(date_debut) = ? AND YEAR(date_debut) = ?
    ");
    $stmtGlobal->execute([$tenantId, $mois, $annee]);
    $globalMois = $stmtGlobal->fetch();

    // Total charges du mois
    $stmtChgMois = $db->prepare("
        SELECT COALESCE(SUM(montant), 0) as total
        FROM charges
        WHERE tenant_id = ? AND MONTH(date_charge) = ? AND YEAR(date_charge) = ?
    ");
    $stmtChgMois->execute([$tenantId, $mois, $annee]);
    $chargesMois = (float)$stmtChgMois->fetchColumn();
}

$pageTitle  = 'Rapports';
$activePage = 'rapports';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h2 class="page-title"><i class="fas fa-file-alt"></i> Rapports</h2>
        <p class="page-subtitle">Analyse de vos activités</p>
    </div>
    <div class="page-header-right">
        <!-- Export CSV -->
        <a href="?type=<?= sanitize($typeRapport) ?>&mois=<?= $mois ?>&annee=<?= $annee ?>&date=<?= sanitize($dateJour) ?>&export=csv"
           class="btn btn-success">
            <i class="fas fa-file-csv"></i> Exporter CSV
        </a>
    </div>
</div>

<?= renderFlashes() ?>

<!-- Onglets type rapport -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-body" style="padding:1rem;">
        <form method="GET" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;">
            <div class="form-group" style="margin:0;">
                <label class="form-label">Type de rapport</label>
                <div style="display:flex;gap:.5rem;">
                    <a href="?type=journalier&date=<?= date('Y-m-d') ?>"
                       class="btn <?= $typeRapport === 'journalier' ? 'btn-primary' : 'btn-secondary' ?>">
                        <i class="fas fa-calendar-day"></i> Journalier
                    </a>
                    <a href="?type=mensuel&mois=<?= $mois ?>&annee=<?= $annee ?>"
                       class="btn <?= $typeRapport === 'mensuel' ? 'btn-primary' : 'btn-secondary' ?>">
                        <i class="fas fa-calendar-alt"></i> Mensuel
                    </a>
                </div>
            </div>

            <?php if ($typeRapport === 'journalier'): ?>
            <div class="form-group" style="margin:0;">
                <label class="form-label">Date</label>
                <input type="date" name="date" class="form-control" value="<?= sanitize($dateJour) ?>" onchange="this.form.submit()">
                <input type="hidden" name="type" value="journalier">
            </div>
            <?php elseif ($typeRapport === 'mensuel'): ?>
            <div class="form-group" style="margin:0;">
                <label class="form-label">Mois</label>
                <select name="mois" class="form-control" onchange="this.form.submit()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === $mois ? 'selected' : '' ?>><?= $nomsMois[$m] ?></option>
                    <?php endfor; ?>
                </select>
                <input type="hidden" name="type" value="mensuel">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label">Année</label>
                <select name="annee" class="form-control" onchange="this.form.submit()">
                    <?php for ($a = (int)date('Y'); $a >= 2020; $a--): ?>
                        <option value="<?= $a ?>" <?= $a === $annee ? 'selected' : '' ?>><?= $a ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ══ RAPPORT JOURNALIER ══════════════════════════════════════════ -->
<?php if ($typeRapport === 'journalier'): ?>

<h3 style="margin-bottom:1rem;">Rapport du <?= formatDate($dateJour) ?></h3>

<!-- Stats jour -->
<div class="stats-grid" style="margin-bottom:1.5rem;">
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--primary);"><i class="fas fa-file-contract"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= count($locationsJour) ?></div>
            <div class="stat-label">Locations du jour</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--success);"><i class="fas fa-cash-register"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= formatMoney($encaissementsJour) ?></div>
            <div class="stat-label">Encaissements</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--danger);"><i class="fas fa-receipt"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= formatMoney($chargesJour) ?></div>
            <div class="stat-label">Charges du jour</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:<?= $beneficeJour >= 0 ? 'var(--info)' : 'var(--danger)' ?>;"><i class="fas fa-balance-scale"></i></div>
        <div class="stat-content">
            <div class="stat-value" style="color:<?= $beneficeJour >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                <?= ($beneficeJour >= 0 ? '+' : '') . formatMoney($beneficeJour) ?>
            </div>
            <div class="stat-label">Bénéfice jour</div>
        </div>
    </div>
</div>

<!-- Tableau locations du jour -->
<div class="card">
    <div class="card-header"><h3 style="margin:0;">Locations du <?= formatDate($dateJour) ?></h3></div>
    <div class="table-responsive">
        <?php if (empty($locationsJour)): ?>
            <div style="padding:2rem;text-align:center;color:var(--gray-500);">
                <i class="fas fa-calendar-times" style="font-size:2rem;margin-bottom:.5rem;display:block;"></i>
                Aucune location ce jour.
            </div>
        <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Véhicule</th>
                    <th>Client</th>
                    <th>Début</th>
                    <th>Fin</th>
                    <th>Jours</th>
                    <th>Montant</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($locationsJour as $l): ?>
                <tr>
                    <td>
                        <div style="font-weight:600;"><?= sanitize($l['veh_nom']) ?></div>
                        <div style="font-size:.8rem;color:var(--gray-500);font-family:monospace;"><?= sanitize($l['immatriculation']) ?></div>
                    </td>
                    <td><?= sanitize($l['client_nom'] ?? '-') ?></td>
                    <td><?= formatDate($l['date_debut']) ?></td>
                    <td><?= formatDate($l['date_fin']) ?></td>
                    <td style="text-align:center;"><?= (int)$l['nombre_jours'] ?></td>
                    <td style="font-weight:600;"><?= formatMoney((float)$l['montant_final']) ?></td>
                    <td><?= badgeLocation($l['statut']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<!-- ══ RAPPORT MENSUEL ═════════════════════════════════════════════ -->
<?php if ($typeRapport === 'mensuel'): ?>

<h3 style="margin-bottom:1rem;">Rapport de <?= $nomsMois[$mois] ?> <?= $annee ?></h3>

<!-- Stats globales mois -->
<div class="stats-grid" style="margin-bottom:1.5rem;">
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--primary);"><i class="fas fa-file-contract"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= (int)($globalMois['total_locations'] ?? 0) ?></div>
            <div class="stat-label">Total locations</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--success);"><i class="fas fa-check-circle"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= (int)($globalMois['terminées'] ?? 0) ?></div>
            <div class="stat-label">Terminées</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--warning);"><i class="fas fa-spinner"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= (int)($globalMois['en_cours'] ?? 0) ?></div>
            <div class="stat-label">En cours</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--success);"><i class="fas fa-coins"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= formatMoney((float)($globalMois['total_encaisse'] ?? 0)) ?></div>
            <div class="stat-label">Total encaissé</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--info);"><i class="fas fa-file-invoice-dollar"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= formatMoney((float)($globalMois['total_du'] ?? 0)) ?></div>
            <div class="stat-label">Total dû</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:var(--danger);"><i class="fas fa-receipt"></i></div>
        <div class="stat-content">
            <div class="stat-value"><?= formatMoney($chargesMois) ?></div>
            <div class="stat-label">Charges du mois</div>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">
    <!-- Top 3 véhicules -->
    <div class="card">
        <div class="card-header"><h3 style="margin:0;"><i class="fas fa-trophy" style="color:var(--warning);"></i> Top 3 Véhicules</h3></div>
        <div class="card-body">
            <?php if (empty($topVehicules)): ?>
                <p style="color:var(--gray-500);text-align:center;">Aucune donnée.</p>
            <?php else: ?>
            <?php foreach ($topVehicules as $i => $tv): ?>
            <div style="display:flex;align-items:center;gap:1rem;padding:.75rem 0;<?= $i < count($topVehicules)-1 ? 'border-bottom:1px solid var(--gray-200);' : '' ?>">
                <div style="width:32px;height:32px;border-radius:50%;background:<?= ['var(--warning)','var(--gray-400)','#cd7f32'][$i] ?>;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;flex-shrink:0;">
                    <?= $i+1 ?>
                </div>
                <div style="flex:1;">
                    <div style="font-weight:600;"><?= sanitize($tv['nom']) ?></div>
                    <div style="font-size:.8rem;color:var(--gray-500);"><?= sanitize($tv['immatriculation']) ?> · <?= (int)$tv['nb_locations'] ?> location(s)</div>
                </div>
                <div style="font-weight:700;color:var(--success);"><?= formatMoney((float)$tv['revenus']) ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top 3 clients -->
    <div class="card">
        <div class="card-header"><h3 style="margin:0;"><i class="fas fa-users" style="color:var(--primary);"></i> Top 3 Clients</h3></div>
        <div class="card-body">
            <?php if (empty($topClients)): ?>
                <p style="color:var(--gray-500);text-align:center;">Aucune donnée.</p>
            <?php else: ?>
            <?php foreach ($topClients as $i => $tc): ?>
            <div style="display:flex;align-items:center;gap:1rem;padding:.75rem 0;<?= $i < count($topClients)-1 ? 'border-bottom:1px solid var(--gray-200);' : '' ?>">
                <div style="width:32px;height:32px;border-radius:50%;background:<?= ['var(--primary)','var(--info)','var(--secondary)'][$i] ?>;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;flex-shrink:0;">
                    <?= $i+1 ?>
                </div>
                <div style="flex:1;">
                    <div style="font-weight:600;"><?= sanitize($tc['client_nom']) ?></div>
                    <div style="font-size:.8rem;color:var(--gray-500);"><?= (int)$tc['nb_locations'] ?> location(s)</div>
                </div>
                <div style="font-weight:700;color:var(--success);"><?= formatMoney((float)$tc['total_depense']) ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Évolution par semaine -->
<div class="card">
    <div class="card-header"><h3 style="margin:0;">Évolution hebdomadaire — <?= $nomsMois[$mois] ?> <?= $annee ?></h3></div>
    <div class="card-body">
        <?php if (empty($parSemaine)): ?>
            <p style="color:var(--gray-500);text-align:center;">Aucune donnée pour ce mois.</p>
        <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Semaine</th>
                    <th style="text-align:right;">Nb locations</th>
                    <th style="text-align:right;">Revenus</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($parSemaine as $s): ?>
                <tr>
                    <td>Semaine <?= (int)$s['semaine'] ?></td>
                    <td style="text-align:right;"><?= (int)$s['nb_locations'] ?></td>
                    <td style="text-align:right;font-weight:600;color:var(--success);"><?= formatMoney((float)$s['revenus']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
