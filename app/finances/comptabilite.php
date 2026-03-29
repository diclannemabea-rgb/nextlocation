<?php
/**
 * FlotteCar — Comptabilité & Caisse unifiée
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireTenantAuth();

$db       = (new Database())->getConnection();
$tenantId = getTenantId();
$userId   = getUserId();
$isAdmin  = isTenantAdmin();

require_once BASE_PATH . '/models/ComptabiliteModel.php';
$model = new ComptabiliteModel($db);

// ── Période par défaut : mois en cours ──────────────────────────
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-t');
$sens = $_GET['sens'] ?? '';
$type = $_GET['type'] ?? '';

// Raccourcis période
if (isset($_GET['period'])) {
    switch ($_GET['period']) {
        case 'today':     $from = $to = date('Y-m-d'); break;
        case 'mois':      $from = date('Y-m-01'); $to = date('Y-m-t'); break;
        case 'mois_prec': $from = date('Y-m-01', strtotime('first day of last month'));
                          $to   = date('Y-m-t',  strtotime('last day of last month')); break;
        case '3mois':     $from = date('Y-m-d', strtotime('-3 months')); $to = date('Y-m-d'); break;
        case '6mois':     $from = date('Y-m-d', strtotime('-6 months')); $to = date('Y-m-d'); break;
        case 'annee':     $from = date('Y-01-01'); $to = date('Y-12-31'); break;
    }
}

// ── ACTIONS POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();

    // Initialiser / ajuster caisse (admin seulement)
    if (isset($_POST['action']) && $_POST['action'] === 'init_caisse' && $isAdmin) {
        $model->saveCaisseConfig(
            $tenantId,
            (float)cleanNumber($_POST['solde_initial'] ?? 0),
            $_POST['date_init'] ?? date('Y-m-d'),
            sanitize($_POST['notes'] ?? ''),
            $userId
        );
        setFlash(FLASH_SUCCESS, 'Caisse initialisée avec succès.');
        redirect(BASE_URL . 'app/finances/comptabilite.php');
    }

    // Nouvelle dépense entreprise
    if (isset($_POST['action']) && $_POST['action'] === 'add_depense') {
        if (!$_POST['libelle'] || !$_POST['montant'] || !$_POST['date_depense']) {
            setFlash(FLASH_ERROR, 'Veuillez remplir tous les champs obligatoires.');
        } else {
            $model->createDepense($tenantId, [
                'categorie'    => $_POST['categorie'] ?? 'autre',
                'libelle'      => sanitize($_POST['libelle']),
                'montant'      => (float)cleanNumber($_POST['montant']),
                'date_depense' => $_POST['date_depense'],
                'notes'        => sanitize($_POST['notes'] ?? ''),
            ], $userId);
            setFlash(FLASH_SUCCESS, 'Dépense enregistrée.');
        }
        redirect(BASE_URL . 'app/finances/comptabilite.php?from=' . $from . '&to=' . $to);
    }

    // Supprimer dépense
    if (isset($_POST['action']) && $_POST['action'] === 'del_depense' && $isAdmin) {
        $model->deleteDepense((int)$_POST['id'], $tenantId);
        setFlash(FLASH_SUCCESS, 'Dépense supprimée.');
        redirect(BASE_URL . 'app/finances/comptabilite.php?from=' . $from . '&to=' . $to);
    }
}

// ── Données ─────────────────────────────────────────────────────
$caisse      = $model->getSoldeCaisse($tenantId);
$caisseConf  = $model->getCaisseConfig($tenantId);

// KPIs période
$recLoc  = $model->getTotalLocations($tenantId, $from, $to);
$recTaxi = $model->getTotalTaxi($tenantId, $from, $to);
$depVeh  = $model->getTotalChargesVehicules($tenantId, $from, $to);
$depMnt  = $model->getTotalMaintenances($tenantId, $from, $to);
$depEnt  = $model->getTotalDepensesEntreprise($tenantId, $from, $to);

$totalRec = $recLoc + $recTaxi;
$totalDep = $depVeh + $depMnt + $depEnt;
$resultat = $totalRec - $totalDep;

// Journal
$journal  = $model->getJournal($tenantId, $from, $to, $sens, $type);

// Graphique 6 mois
$graphData = $model->getGraphiqueMensuel($tenantId, 6);
$maxGraph  = max(1, max(array_map(fn($m) => max($m['recettes'], $m['depenses']), $graphData)));

// ── Catégories dépenses entreprise ──────────────────────────────
$catLabels = [
    'salaire'       => ['label' => 'Salaires',       'icon' => 'fa-user-tie',          'color' => '#6366f1'],
    'loyer'         => ['label' => 'Loyer',           'icon' => 'fa-building',          'color' => '#f59e0b'],
    'investissement'=> ['label' => 'Investissement',  'icon' => 'fa-arrow-trend-up',    'color' => '#10b981'],
    'marketing'     => ['label' => 'Marketing',       'icon' => 'fa-bullhorn',          'color' => '#8b5cf6'],
    'fournitures'   => ['label' => 'Fournitures',     'icon' => 'fa-box',               'color' => '#06b6d4'],
    'impots'        => ['label' => 'Impôts/Taxes',    'icon' => 'fa-landmark',          'color' => '#ef4444'],
    'autre'         => ['label' => 'Autres',          'icon' => 'fa-circle-dot',        'color' => '#64748b'],
];

$typeLabels = [
    'location'    => ['label'=>'Location',    'icon'=>'fa-calendar-check','color'=>'#0d9488','bg'=>'#dbeafe'],
    'taxi'        => ['label'=>'Taxi',        'icon'=>'fa-taxi',          'color'=>'#d97706','bg'=>'#fef3c7'],
    'charge'      => ['label'=>'Charge veh.', 'icon'=>'fa-gas-pump',      'color'=>'#ef4444','bg'=>'#fee2e2'],
    'maintenance' => ['label'=>'Maintenance', 'icon'=>'fa-wrench',        'color'=>'#dc2626','bg'=>'#fee2e2'],
    'depense'     => ['label'=>'Dép. entrep.','icon'=>'fa-briefcase',     'color'=>'#7c3aed','bg'=>'#ede9fe'],
];

$pageTitle  = 'Comptabilité';
$activePage = 'comptabilite';
require_once BASE_PATH . '/includes/header.php';
?>

<style>
/* ── Comptabilité — mobile responsive ───────────────────────── */
.cpt-header-actions { display:flex;gap:8px;align-items:center;flex-wrap:wrap; }

/* Caisse card */
.caisse-card {
    background: linear-gradient(135deg,#0d9488 0%,#1e40af 100%);
    border-radius: 16px; padding: 22px 20px; color: #fff;
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 16px; margin-bottom: 20px;
}
.caisse-solde { font-size: 2rem; font-weight: 800; letter-spacing: -1px; }
.caisse-totaux { display:flex; gap:20px; }
@media (max-width:500px) {
    .caisse-solde { font-size: 1.6rem; }
    .caisse-totaux { gap: 14px; }
    .caisse-totaux > div { font-size: .85rem; }
}

/* Filtres responsive */
.cpt-filters { display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end; }
.cpt-filters > div { flex:1;min-width:120px; }
.cpt-shortcuts { display:flex;gap:6px;margin-top:10px;flex-wrap:wrap;align-items:center; }

/* Journal : table sur desktop, cartes sur mobile */
.journal-table { display:block; }
.journal-cards { display:none; }

@media (max-width:640px) {
    .journal-table { display:none; }
    .journal-cards { display:block; }
    .cpt-header-actions .btn span { display:none; } /* hide text on very small */
}

/* Journal card item */
.jcard {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.jcard:last-child { border-bottom: none; }
.jcard-top { display:flex; align-items:center; justify-content:space-between; gap:8px; }
.jcard-amount { font-size: 1rem; font-weight: 700; white-space:nowrap; }
.jcard-meta { font-size:.76rem; color:var(--text-muted); display:flex; gap:10px; flex-wrap:wrap; }
.jcard-libelle { font-size:.85rem; font-weight:500; }

/* Page header mobile */
@media (max-width:480px) {
    .cpt-page-header { flex-direction:column; align-items:flex-start !important; gap:10px !important; }
}
</style>

<div class="page-header cpt-page-header" style="margin-bottom:0">
    <div>
        <h1 class="page-title"><i class="fas fa-calculator" style="color:#0d9488"></i> Comptabilité</h1>
        <p class="page-subtitle">Caisse unifiée — Toutes entrées et sorties d'argent</p>
    </div>
    <div class="cpt-header-actions">
        <?php if ($isAdmin): ?>
        <button class="btn btn-outline-primary btn-sm" onclick="openModal('modal-caisse')">
            <i class="fas fa-vault"></i> <span>Initialiser caisse</span>
        </button>
        <?php endif; ?>
        <button class="btn btn-primary btn-sm" onclick="openModal('modal-depense')">
            <i class="fas fa-plus"></i> <span>Nouvelle dépense</span>
        </button>
    </div>
</div>

<?= renderFlashes() ?>

<!-- ═══════════════════════════════════════════════
     SOLDE CAISSE — Carte principale
═══════════════════════════════════════════════════ -->
<div style="padding:0 0 0">
    <div class="caisse-card">
        <div>
            <div style="font-size:.82rem;opacity:.8;margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em"><i class="fas fa-vault"></i> Solde de caisse actuel</div>
            <div class="caisse-solde">
                <?= formatMoney($caisse['solde_actuel']) ?>
            </div>
            <?php if ($caisseConf['date_init']): ?>
            <div style="font-size:.8rem;opacity:.7;margin-top:6px">
                Depuis le <?= formatDate($caisseConf['date_init']) ?> · Solde initial : <?= formatMoney($caisse['solde_initial']) ?>
            </div>
            <?php else: ?>
            <div style="font-size:.8rem;opacity:.7;margin-top:6px">⚠️ Caisse non initialisée — cliquez "Initialiser caisse"</div>
            <?php endif; ?>
        </div>
        <div class="caisse-totaux">
            <div style="text-align:center">
                <div style="font-size:.72rem;opacity:.7;margin-bottom:2px">Recettes</div>
                <div style="font-size:1.15rem;font-weight:700;color:#86efac">+<?= formatMoney($caisse['recettes']) ?></div>
            </div>
            <div style="text-align:center">
                <div style="font-size:.72rem;opacity:.7;margin-bottom:2px">Dépenses</div>
                <div style="font-size:1.15rem;font-weight:700;color:#fca5a5">-<?= formatMoney($caisse['depenses']) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════
     FILTRES
═══════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:16px 20px">
        <form method="GET" class="cpt-filters">
            <div>
                <label class="form-label" style="margin-bottom:4px">Date début</label>
                <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
            </div>
            <div>
                <label class="form-label" style="margin-bottom:4px">Date fin</label>
                <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
            </div>
            <div>
                <label class="form-label" style="margin-bottom:4px">Sens</label>
                <select name="sens" class="form-control">
                    <option value="">Tout</option>
                    <option value="entree" <?= $sens==='entree'?'selected':'' ?>>Entrées</option>
                    <option value="sortie" <?= $sens==='sortie'?'selected':'' ?>>Sorties</option>
                </select>
            </div>
            <div>
                <label class="form-label" style="margin-bottom:4px">Type</label>
                <select name="type" class="form-control">
                    <option value="">Tous</option>
                    <option value="location"    <?= $type==='location'?'selected':'' ?>>Locations</option>
                    <option value="taxi"        <?= $type==='taxi'?'selected':'' ?>>Taxi</option>
                    <option value="charge"      <?= $type==='charge'?'selected':'' ?>>Charges</option>
                    <option value="maintenance" <?= $type==='maintenance'?'selected':'' ?>>Maintenances</option>
                    <option value="depense"     <?= $type==='depense'?'selected':'' ?>>Dép. entreprise</option>
                </select>
            </div>
            <div style="display:flex;gap:8px;align-self:flex-end">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrer</button>
                <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-t') ?>" class="btn btn-secondary">Reset</a>
            </div>
        </form>
        <!-- Raccourcis -->
        <div class="cpt-shortcuts">
            <span style="font-size:.8rem;color:var(--text-muted);align-self:center">Raccourcis :</span>
            <?php
            $shortcuts = [
                ['period'=>'today',    'label'=>'Aujourd\'hui'],
                ['period'=>'mois',     'label'=>'Ce mois'],
                ['period'=>'mois_prec','label'=>'Mois préc.'],
                ['period'=>'3mois',    'label'=>'3 mois'],
                ['period'=>'6mois',    'label'=>'6 mois'],
                ['period'=>'annee',    'label'=>'Cette année'],
            ];
            foreach ($shortcuts as $sc):
            ?>
            <a href="?period=<?= $sc['period'] ?>&sens=<?= $sens ?>&type=<?= $type ?>"
               style="font-size:.8rem;padding:4px 12px;border-radius:99px;background:var(--bg-secondary,#f1f5f9);color:var(--text);text-decoration:none;border:1px solid var(--border)">
                <?= $sc['label'] ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════
     KPIs PÉRIODE
═══════════════════════════════════════════════════ -->
<div class="stats-grid" style="margin-bottom:20px;grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">

    <div class="stat-card success">
        <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
        <div class="stat-body">
            <div class="stat-label">Total recettes</div>
            <div class="stat-value"><?= formatMoney($totalRec) ?></div>
            <div style="font-size:.75rem;color:#6ee7b7;margin-top:2px">
                Loc: <?= formatMoney($recLoc) ?> · Taxi: <?= formatMoney($recTaxi) ?>
            </div>
        </div>
    </div>

    <div class="stat-card primary">
        <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
        <div class="stat-body">
            <div class="stat-label">Recettes locations</div>
            <div class="stat-value"><?= formatMoney($recLoc) ?></div>
        </div>
    </div>

    <div class="stat-card warning">
        <div class="stat-icon"><i class="fas fa-taxi"></i></div>
        <div class="stat-body">
            <div class="stat-label">Recettes taxi</div>
            <div class="stat-value"><?= formatMoney($recTaxi) ?></div>
        </div>
    </div>

    <div class="stat-card danger">
        <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
        <div class="stat-body">
            <div class="stat-label">Total dépenses</div>
            <div class="stat-value"><?= formatMoney($totalDep) ?></div>
            <div style="font-size:.75rem;color:#fca5a5;margin-top:2px">
                Veh: <?= formatMoney($depVeh+$depMnt) ?> · Ent: <?= formatMoney($depEnt) ?>
            </div>
        </div>
    </div>

    <div class="stat-card <?= $resultat >= 0 ? 'success' : 'danger' ?>">
        <div class="stat-icon"><i class="fas fa-scale-balanced"></i></div>
        <div class="stat-body">
            <div class="stat-label">Résultat période</div>
            <div class="stat-value"><?= ($resultat>=0?'+':'') . formatMoney($resultat) ?></div>
        </div>
    </div>

</div>

<!-- ═══════════════════════════════════════════════
     GRAPHIQUE 6 MOIS
═══════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:20px">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-chart-bar"></i> Évolution 6 derniers mois</h3>
        <div style="display:flex;gap:16px;font-size:.8rem">
            <span><span style="display:inline-block;width:12px;height:12px;background:#0d9488;border-radius:3px;margin-right:4px"></span>Recettes</span>
            <span><span style="display:inline-block;width:12px;height:12px;background:#ef4444;border-radius:3px;margin-right:4px"></span>Dépenses</span>
        </div>
    </div>
    <div class="card-body">
        <div style="display:flex;align-items:flex-end;gap:12px;height:180px;padding:0 8px">
            <?php foreach ($graphData as $m):
                $hRec = $m['recettes'] > 0 ? round(($m['recettes'] / $maxGraph) * 160) : 2;
                $hDep = $m['depenses'] > 0 ? round(($m['depenses'] / $maxGraph) * 160) : 2;
                $isCurrent = (substr($m['from'],0,7) === date('Y-m'));
            ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px">
                <div style="display:flex;align-items:flex-end;gap:3px;height:160px">
                    <div title="Recettes: <?= formatMoney($m['recettes']) ?>"
                         style="width:20px;height:<?= $hRec ?>px;background:<?= $isCurrent?'#0d9488':'#93c5fd' ?>;border-radius:4px 4px 0 0;transition:.3s;cursor:pointer"
                         onmouseenter="this.style.opacity='.8'" onmouseleave="this.style.opacity='1'"></div>
                    <div title="Dépenses: <?= formatMoney($m['depenses']) ?>"
                         style="width:20px;height:<?= $hDep ?>px;background:<?= $isCurrent?'#ef4444':'#fca5a5' ?>;border-radius:4px 4px 0 0;transition:.3s;cursor:pointer"
                         onmouseenter="this.style.opacity='.8'" onmouseleave="this.style.opacity='1'"></div>
                </div>
                <div style="font-size:.7rem;color:var(--text-muted);text-align:center;font-weight:<?= $isCurrent?'700':'400' ?>">
                    <?= $m['label'] ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════
     JOURNAL DES OPÉRATIONS
═══════════════════════════════════════════════════ -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-list"></i> Journal des opérations
            <span style="font-size:.8rem;font-weight:400;color:var(--text-muted);margin-left:8px"><?= count($journal) ?> opération(s)</span>
        </h3>
        <a href="?from=<?= $from ?>&to=<?= $to ?>&sens=<?= $sens ?>&type=<?= $type ?>&export=csv"
           class="btn btn-outline-primary btn-sm"><i class="fas fa-download"></i> Export CSV</a>
    </div>
    <div class="card-body" style="padding:0">
        <?php if (empty($journal)): ?>
        <div style="text-align:center;padding:48px;color:var(--text-muted)">
            <i class="fas fa-inbox" style="font-size:2rem;margin-bottom:8px;display:block"></i>
            Aucune opération sur cette période
        </div>
        <?php else: ?>

        <?php
        // Pré-calculer pour éviter la répétition
        $journalRows = [];
        foreach ($journal as $row) {
            $journalRows[] = [
                'row'      => $row,
                'tl'       => $typeLabels[$row['type_op']] ?? ['label'=>$row['type_op'],'icon'=>'fa-circle','color'=>'#64748b','bg'=>'#f1f5f9'],
                'isEntree' => $row['sens'] === 'entree',
                'libelle'  => htmlspecialchars($row['libelle'] ?? '', ENT_QUOTES, 'UTF-8'),
                'tiers'    => htmlspecialchars($row['tiers'] ?? '', ENT_QUOTES, 'UTF-8'),
                'immat'    => htmlspecialchars($row['immatriculation'] ?? '', ENT_QUOTES, 'UTF-8'),
                'ref'      => htmlspecialchars($row['reference'] ?? '', ENT_QUOTES, 'UTF-8'),
                'mode'     => htmlspecialchars(ucfirst(str_replace('_',' ',$row['mode_paiement'] ?? '')), ENT_QUOTES, 'UTF-8'),
            ];
        }
        ?>

        <!-- Vue TABLE (desktop) -->
        <div class="journal-table table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Libellé</th>
                        <th>Véhicule / Tiers</th>
                        <th style="text-align:right">Montant</th>
                        <th>Mode</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($journalRows as $jr): $row=$jr['row']; $tl=$jr['tl']; $isEntree=$jr['isEntree']; ?>
                    <tr>
                        <td style="color:var(--text-muted);font-size:.85rem;white-space:nowrap">
                            <?= formatDate($row['date_op']) ?>
                        </td>
                        <td>
                            <span style="display:inline-flex;align-items:center;gap:6px;font-size:.8rem;padding:3px 10px;border-radius:99px;background:<?= $tl['bg'] ?>;color:<?= $tl['color'] ?>;font-weight:600">
                                <i class="fas <?= $tl['icon'] ?>" style="font-size:.7rem"></i><?= $tl['label'] ?>
                            </span>
                        </td>
                        <td>
                            <span style="font-weight:500"><?= $jr['libelle'] ?></span>
                            <?php if ($jr['ref']): ?>
                            <br><span style="font-size:.75rem;color:var(--text-muted)">Réf: <?= $jr['ref'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.85rem;color:var(--text-muted)">
                            <?php if ($jr['immat']): ?>
                            <span style="font-weight:600;color:var(--text)"><?= $jr['immat'] ?></span><br>
                            <?php endif; ?>
                            <?= $jr['tiers'] ?>
                        </td>
                        <td style="text-align:right;font-weight:700;font-size:1rem;white-space:nowrap;color:<?= $isEntree?'#16a34a':'#dc2626' ?>">
                            <?= $isEntree ? '+' : '-' ?><?= formatMoney($row['montant']) ?>
                        </td>
                        <td style="font-size:.8rem;color:var(--text-muted)"><?= $jr['mode'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:var(--bg-secondary,#f8fafc);font-weight:700">
                        <td colspan="4" style="text-align:right;padding:12px 16px">Total période :</td>
                        <td style="text-align:right;padding:12px 16px;color:<?= $resultat>=0?'#16a34a':'#dc2626' ?>">
                            <?= ($resultat>=0?'+':'') . formatMoney($resultat) ?>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Vue CARTES (mobile) -->
        <div class="journal-cards">
            <?php foreach ($journalRows as $jr): $row=$jr['row']; $tl=$jr['tl']; $isEntree=$jr['isEntree']; ?>
            <div class="jcard">
                <div class="jcard-top">
                    <span style="display:inline-flex;align-items:center;gap:5px;font-size:.75rem;padding:3px 9px;border-radius:99px;background:<?= $tl['bg'] ?>;color:<?= $tl['color'] ?>;font-weight:600">
                        <i class="fas <?= $tl['icon'] ?>" style="font-size:.65rem"></i><?= $tl['label'] ?>
                    </span>
                    <span class="jcard-amount" style="color:<?= $isEntree?'#16a34a':'#dc2626' ?>">
                        <?= $isEntree?'+':'-' ?><?= formatMoney($row['montant']) ?>
                    </span>
                </div>
                <div class="jcard-libelle"><?= $jr['libelle'] ?: '—' ?></div>
                <div class="jcard-meta">
                    <span><i class="fas fa-calendar" style="margin-right:3px"></i><?= formatDate($row['date_op']) ?></span>
                    <?php if ($jr['immat']): ?>
                    <span><i class="fas fa-car" style="margin-right:3px"></i><?= $jr['immat'] ?></span>
                    <?php endif; ?>
                    <?php if ($jr['tiers']): ?>
                    <span><i class="fas fa-user" style="margin-right:3px"></i><?= $jr['tiers'] ?></span>
                    <?php endif; ?>
                    <?php if ($jr['mode']): ?>
                    <span><i class="fas fa-credit-card" style="margin-right:3px"></i><?= $jr['mode'] ?></span>
                    <?php endif; ?>
                    <?php if ($jr['ref']): ?>
                    <span>Réf: <?= $jr['ref'] ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <!-- Total en bas -->
            <div style="padding:12px 14px;background:#f8fafc;font-weight:700;display:flex;justify-content:space-between;font-size:.9rem">
                <span>Total période</span>
                <span style="color:<?= $resultat>=0?'#16a34a':'#dc2626' ?>"><?= ($resultat>=0?'+':'').formatMoney($resultat) ?></span>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════
     MODAL — Initialiser caisse (admin)
═══════════════════════════════════════════════════ -->
<?php if ($isAdmin): ?>
<div id="modal-caisse" class="modal-overlay">
    <div class="modal-card" style="max-width:460px">
        <div class="modal-header">
            <h3><i class="fas fa-vault"></i> Initialiser / Ajuster la caisse</h3>
            <button class="modal-close" onclick="closeModal('modal-caisse')">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="init_caisse">

            <div class="form-group">
                <label class="form-label">Solde initial (FCFA) <span style="color:#ef4444">*</span></label>
                <input type="number" name="solde_initial" class="form-control"
                       value="<?= htmlspecialchars($caisseConf['solde_initial'] ?? '0') ?>"
                       step="1" min="0" required>
                <div class="form-hint">Montant en caisse au moment de la date de départ</div>
            </div>

            <div class="form-group">
                <label class="form-label">Date de départ du comptage <span style="color:#ef4444">*</span></label>
                <input type="date" name="date_init" class="form-control"
                       value="<?= htmlspecialchars($caisseConf['date_init'] ?? date('Y-m-d')) ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($caisseConf['notes'] ?? '') ?></textarea>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-caisse')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════
     MODAL — Nouvelle dépense entreprise
═══════════════════════════════════════════════════ -->
<div id="modal-depense" class="modal-overlay">
    <div class="modal-card" style="max-width:500px">
        <div class="modal-header">
            <h3><i class="fas fa-briefcase"></i> Nouvelle dépense entreprise</h3>
            <button class="modal-close" onclick="closeModal('modal-depense')">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_depense">

            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Catégorie</label>
                    <select name="categorie" class="form-control">
                        <?php foreach ($catLabels as $k => $cl): ?>
                        <option value="<?= $k ?>"><?= $cl['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Date <span style="color:#ef4444">*</span></label>
                    <input type="date" name="date_depense" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Libellé <span style="color:#ef4444">*</span></label>
                <input type="text" name="libelle" class="form-control" placeholder="Ex: Salaire gardien mars" required>
            </div>

            <div class="form-group">
                <label class="form-label">Montant (FCFA) <span style="color:#ef4444">*</span></label>
                <input type="number" name="montant" class="form-control" step="1" min="1" required>
            </div>

            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Détails optionnels..."></textarea>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-depense')">Annuler</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-plus"></i> Enregistrer la dépense</button>
            </div>
        </form>
    </div>
</div>

<?php
// ── Export CSV ────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="comptabilite_' . $from . '_' . $to . '.csv"');
    $f = fopen('php://output', 'w');
    fputs($f, "\xEF\xBB\xBF"); // BOM UTF-8
    fputcsv($f, ['Date', 'Type', 'Libellé', 'Véhicule', 'Tiers', 'Sens', 'Montant FCFA', 'Mode paiement'], ';');
    foreach ($journal as $r) {
        fputcsv($f, [
            $r['date_op'], $r['type_op'], $r['libelle'],
            $r['immatriculation'], $r['tiers'],
            $r['sens'] === 'entree' ? 'Entrée' : 'Sortie',
            $r['montant'], $r['mode_paiement']
        ], ';');
    }
    fclose($f);
    exit;
}
?>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
