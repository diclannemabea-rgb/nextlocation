<?php
/**
 * FlotteCar - Liste des véhicules — vue ultra-compacte pro
 * Données: capital, recettes, dépenses, ROI, km, vidange, alertes documents
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
$plan     = getTenantPlan();

$page    = max(1, (int)($_GET['page']   ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;
$statut  = $_GET['statut']  ?? '';
$typeVeh = $_GET['type']    ?? '';
$q       = trim($_GET['q']  ?? '');
$today   = date('Y-m-d');
$in30    = date('Y-m-d', strtotime('+30 days'));

$where  = 'WHERE v.tenant_id = ?';
$params = [$tenantId];
if ($statut) { $where .= ' AND v.statut = ?';        $params[] = $statut; }
if ($typeVeh){ $where .= ' AND v.type_vehicule = ?';  $params[] = $typeVeh; }
if ($q)      { $where .= ' AND (v.nom LIKE ? OR v.immatriculation LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }

// Compter
$cnt = $db->prepare("SELECT COUNT(*) FROM vehicules v $where");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();

// Requête principale avec agrégats financiers
$stmt = $db->prepare("
    SELECT
        v.*,
        /* RECETTES: paiements locations + paiements taxi (pas recettes_initiales pour éviter double comptage) */
        COALESCE((SELECT SUM(p.montant) FROM paiements p
                    JOIN locations l ON l.id = p.location_id
                    WHERE l.vehicule_id = v.id AND p.tenant_id = v.tenant_id), 0)
        + COALESCE((SELECT SUM(p.montant) FROM paiements_taxi p
                    JOIN taximetres t ON t.id = p.taximetre_id
                    WHERE t.vehicule_id = v.id AND p.statut_jour = 'paye'), 0)
        AS total_recettes,

        /* DÉPENSES: table charges uniquement (inclut déjà les maintenances terminées, pas depenses_initiales pour éviter double comptage) */
        COALESCE((SELECT SUM(c.montant) FROM charges c WHERE c.vehicule_id = v.id AND c.tenant_id = v.tenant_id), 0)
        AS total_depenses,

        /* DERNIÈRE MAINTENANCE TERMINÉE */
        (SELECT m2.date_prevue FROM maintenances m2
         WHERE m2.vehicule_id = v.id AND m2.statut = 'termine'
         ORDER BY m2.date_prevue DESC LIMIT 1) AS derniere_maintenance,

        /* MAINTENANCE URGENTE (km seuil atteint ou date dépassée) */
        (SELECT COUNT(*) FROM maintenances m3
         WHERE m3.vehicule_id = v.id AND m3.statut = 'planifie'
           AND (m3.date_prevue <= CURDATE()
                OR (m3.km_prevu IS NOT NULL AND v.kilometrage_actuel >= m3.km_prevu - 500))
        ) AS nb_alertes_maint,

        /* LOCATION EN COURS */
        (SELECT COUNT(*) FROM locations l2
         WHERE l2.vehicule_id = v.id AND l2.statut = 'en_cours') AS loc_en_cours,

        /* TAXIMANTRE ACTIF */
        (SELECT tx.nom FROM taximetres tx WHERE tx.vehicule_id = v.id AND tx.statut = 'actif' LIMIT 1) AS taximantre_nom

    FROM vehicules v
    $where
    ORDER BY v.nom ASC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$perPage, $offset]));
$vehicules = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nbActuel    = $total;
$nbLimite    = getVehiculeLimit($plan);
$peutAjouter = canAddVehicule($db, $tenantId, $plan);

$baseUrl = BASE_URL . 'app/vehicules/liste.php?' . http_build_query(array_filter(['statut'=>$statut,'type'=>$typeVeh,'q'=>$q]));

$pageTitle  = 'Véhicules';
$activePage = 'vehicules';
require_once BASE_PATH . '/includes/header.php';

// Helpers locaux
function roiColor(float $roi): string {
    if ($roi >= 20)  return '#10b981';
    if ($roi >= 0)   return '#f59e0b';
    return '#ef4444';
}
function vidangeAlert(array $v): string {
    $km = (int)$v['kilometrage_actuel'];
    $next = $v['prochaine_vidange_km'];
    if (!$next) return '';
    $diff = (int)$next - $km;
    if ($diff <= 0)    return 'danger';
    if ($diff <= 1000) return 'warning';
    return '';
}
?>
<style>
/* Table ultra-compacte style tableur pro */
.veh-table { width:100%; border-collapse:collapse; font-size:.78rem; }
.veh-table thead th {
    background:#f8fafc; color:#64748b; font-weight:600; font-size:.7rem;
    text-transform:uppercase; letter-spacing:.05em;
    padding:7px 10px; border-bottom:2px solid #e2e8f0;
    white-space:nowrap; position:sticky; top:0; z-index:1;
}
.veh-table tbody tr {
    border-bottom:1px solid #f1f5f9;
    transition:background .1s;
}
.veh-table tbody tr:hover { background:#f8fafc; }
.veh-table td { padding:6px 10px; vertical-align:middle; white-space:nowrap; }
.veh-table td.wrap { white-space:normal; }

/* Badges type */
.type-badge {
    display:inline-flex;align-items:center;gap:4px;
    padding:2px 7px;border-radius:99px;font-size:.67rem;font-weight:600;
}
.type-location   { background:#eff6ff; color:#0d9488; }
.type-taxi       { background:#fffbeb; color:#d97706; }
.type-entreprise { background:#f0fdf4; color:#059669; }

/* Statut dot */
.dot { width:7px;height:7px;border-radius:50%;display:inline-block;flex-shrink:0 }
.dot-disponible  { background:#10b981; }
.dot-loue        { background:#0d9488; }
.dot-maintenance { background:#f59e0b; }
.dot-indisponible{ background:#ef4444; }

/* Cellule financière */
.fin-cell { text-align:right; }
.fin-pos  { color:#10b981; font-weight:600; }
.fin-neg  { color:#ef4444; font-weight:600; }
.fin-muted{ color:#94a3b8; }

/* Alertes inline */
.alert-icon { font-size:.75rem; }

/* GPS dot */
.gps-on  { color:#10b981; }
.gps-off { color:#cbd5e1; }

/* Filtre rapide tabs */
.filter-tabs { display:flex;gap:4px;flex-wrap:wrap; }
.filter-tab {
    padding:4px 12px;border-radius:99px;font-size:.75rem;font-weight:500;
    border:1px solid var(--border);color:var(--text-muted);
    text-decoration:none;cursor:pointer;transition:.15s;background:#fff;
}
.filter-tab:hover,.filter-tab.active { background:var(--primary);color:#fff;border-color:var(--primary); }
.filter-tab.tab-dispo   { border-color:#10b981;color:#10b981; }
.filter-tab.tab-dispo.active, .filter-tab.tab-dispo:hover { background:#10b981;color:#fff; }
.filter-tab.tab-loue    { border-color:#0d9488;color:#0d9488; }
.filter-tab.tab-loue.active { background:#0d9488;color:#fff; }
.filter-tab.tab-maint   { border-color:#f59e0b;color:#d97706; }
.filter-tab.tab-maint.active { background:#f59e0b;color:#fff; }

/* Actions compactes */
.act-btn {
    display:inline-flex;align-items:center;justify-content:center;
    width:26px;height:26px;border-radius:5px;border:none;cursor:pointer;
    font-size:.75rem;text-decoration:none;transition:.15s;
}
.act-view  { background:#f1f5f9;color:#475569; }
.act-view:hover  { background:#e2e8f0; }
.act-edit  { background:#eff6ff;color:#0d9488; }
.act-edit:hover  { background:#dbeafe; }
.act-gps   { background:#f0fdf4;color:#059669; }
.act-gps:hover  { background:#dcfce7; }
.act-del   { background:#fff1f2;color:#ef4444; }
.act-del:hover   { background:#fee2e2; }

/* Mobile */
.veh-table-wrap { display:block; }
.veh-mobile-cards { display:none; }
@media(max-width:768px) {
    .veh-table-wrap { display:none !important; }
    .veh-mobile-cards { display:flex !important; flex-direction:column; gap:10px; }
}
</style>

<div class="page-header" style="margin-bottom:12px">
    <div>
        <h1 class="page-title" style="margin-bottom:2px">Véhicules</h1>
        <p class="page-subtitle" style="margin:0">
            <strong><?= $nbActuel ?></strong> / <?= $nbLimite >= 9999 ? '∞' : $nbLimite ?> &nbsp;<?= badgePlan($plan) ?>
        </p>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
        <?php if ($peutAjouter): ?>
        <a href="<?= BASE_URL ?>app/vehicules/ajouter.php" class="btn btn-primary btn-sm">
            <i class="fas fa-plus"></i> Ajouter
        </a>
        <?php else: ?>
        <a href="<?= BASE_URL ?>app/parametres/index.php#abonnement" class="btn btn-warning btn-sm">
            <i class="fas fa-lock"></i> Limite atteinte — Upgrader
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filtres compacts -->
<div style="background:#fff;border:1px solid var(--border);border-radius:8px;padding:10px 14px;margin-bottom:12px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <form method="GET" style="display:contents">
        <div style="position:relative;flex:1;min-width:180px">
            <i class="fas fa-search" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.8rem"></i>
            <input type="text" name="q" value="<?= sanitize($q) ?>" placeholder="Nom, immatriculation..."
                   class="form-control" style="padding-left:28px;height:32px;font-size:.82rem">
        </div>

        <div class="filter-tabs">
            <a href="<?= BASE_URL ?>app/vehicules/liste.php?<?= http_build_query(array_filter(['q'=>$q,'type'=>$typeVeh])) ?>"
               class="filter-tab <?= !$statut ? 'active' : '' ?>">Tous</a>
            <?php
            $sTabs = ['disponible'=>['tab-dispo','Disponibles'],'loue'=>['tab-loue','Loués'],
                      'maintenance'=>['tab-maint','Maintenance'],'indisponible'=>['','Indispo']];
            foreach ($sTabs as $sv => [$cls, $sl]):
            ?>
            <a href="<?= BASE_URL ?>app/vehicules/liste.php?<?= http_build_query(array_filter(['q'=>$q,'statut'=>$sv,'type'=>$typeVeh])) ?>"
               class="filter-tab <?= $cls ?> <?= $statut===$sv?'active':'' ?>"><?= $sl ?></a>
            <?php endforeach ?>
        </div>

        <div class="filter-tabs">
            <?php
            $tTabs = ['location'=>['#0d9488','Location'],'taxi'=>['#d97706','Taxi'],'entreprise'=>['#059669','Entreprise']];
            foreach ($tTabs as $tv => [$tc, $tl]):
            ?>
            <a href="<?= BASE_URL ?>app/vehicules/liste.php?<?= http_build_query(array_filter(['q'=>$q,'statut'=>$statut,'type'=>($typeVeh===$tv?'':$tv)])) ?>"
               class="filter-tab <?= $typeVeh===$tv?'active':'' ?>"
               style="<?= $typeVeh===$tv ? "background:{$tc};border-color:{$tc};color:#fff" : "border-color:{$tc};color:{$tc}" ?>">
                <?= $tl ?>
            </a>
            <?php endforeach ?>
        </div>

        <?php if ($q || $statut || $typeVeh): ?>
        <a href="<?= BASE_URL ?>app/vehicules/liste.php" class="filter-tab" style="border-color:#ef4444;color:#ef4444">
            <i class="fas fa-times"></i>
        </a>
        <?php endif ?>
    </form>
</div>

<!-- TABLEAU (desktop) -->
<div class="card veh-table-wrap" style="overflow:hidden">
<?php if (empty($vehicules)): ?>
<div style="text-align:center;padding:48px 20px">
    <i class="fas fa-car" style="font-size:2.5rem;color:var(--text-muted);display:block;margin-bottom:10px"></i>
    <p style="color:var(--text-muted);margin:0 0 14px">Aucun véhicule<?= ($q||$statut||$typeVeh) ? ' correspondant à vos filtres' : '' ?></p>
    <?php if ($peutAjouter && !$q && !$statut && !$typeVeh): ?>
    <a href="<?= BASE_URL ?>app/vehicules/ajouter.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Ajouter un véhicule</a>
    <?php endif ?>
</div>
<?php else: ?>
<div style="overflow-x:auto">
<table class="veh-table">
<thead>
<tr>
    <th style="width:36px"></th>
    <th>Véhicule</th>
    <th>Type</th>
    <th>Statut</th>
    <th class="fin-cell">Km</th>
    <?php if (hasGpsModule()): ?><th style="text-align:center">GPS</th><?php endif ?>
    <th class="fin-cell">Capital</th>
    <th class="fin-cell">Recettes</th>
    <th class="fin-cell">Dépenses</th>
    <th class="fin-cell">Bénéfice</th>
    <th class="fin-cell">ROI</th>
    <th>Vidange</th>
    <th style="width:90px">Actions</th>
</tr>
</thead>
<tbody>
<?php foreach ($vehicules as $v):
    $capital    = (float)($v['capital_investi']    ?? 0);
    $recettes   = (float)($v['total_recettes']    ?? 0);
    $depenses   = (float)($v['total_depenses']    ?? 0);
    $recInit    = (float)($v['recettes_initiales'] ?? 0);
    $depInit    = (float)($v['depenses_initiales'] ?? 0);
    $recCumul   = $recettes;
    $depCumul   = $depenses;
    $recettes   = $recInit + $recCumul;
    $depenses   = $depInit + $depCumul;
    $benefice   = $recettes - $depenses - $capital;
    $roi        = $capital > 0 ? ($benefice / $capital * 100) : 0;
    $km        = (int)($v['kilometrage_actuel'] ?? 0);
    $kmNext    = $v['prochaine_vidange_km'] ? (int)$v['prochaine_vidange_km'] : null;
    $kmRestant = $kmNext ? ($kmNext - $km) : null;
    $vidAlerte = vidangeAlert($v);

    // Alertes documents
    $assExp = $v['date_expiration_assurance'] ?? null;
    $vigExp = $v['date_expiration_vignette']  ?? null;
    $assAlerte = $assExp && $assExp <= $in30;
    $vigAlerte = $vigExp && $vigExp <= $in30;
    $assExpire = $assExp && $assExp < $today;
    $vigExpire = $vigExp && $vigExp < $today;

    $nbMaintUrgent = (int)($v['nb_alertes_maint'] ?? 0);
?>
<tr>
    <!-- Photo -->
    <td>
        <?php if ($v['photo']): ?>
            <img src="<?= BASE_URL ?>uploads/logos/<?= sanitize($v['photo']) ?>"
                 style="width:32px;height:28px;object-fit:cover;border-radius:4px;border:1px solid #e2e8f0">
        <?php else: ?>
            <div style="width:32px;height:28px;background:#f1f5f9;border-radius:4px;display:flex;align-items:center;justify-content:center">
                <i class="fas fa-car" style="font-size:.65rem;color:#94a3b8"></i>
            </div>
        <?php endif ?>
    </td>

    <!-- Véhicule -->
    <td>
        <div style="font-weight:600;color:var(--text)"><?= sanitize($v['nom']) ?></div>
        <div style="font-size:.7rem;color:#64748b;font-family:monospace;letter-spacing:.03em"><?= sanitize($v['immatriculation']) ?></div>
        <?php if ($v['marque'] || $v['modele']): ?>
        <div style="font-size:.68rem;color:#94a3b8"><?= sanitize(trim(($v['marque']??'').' '.($v['modele']??''))) ?></div>
        <?php endif ?>
    </td>

    <!-- Type -->
    <td>
        <?php $typeConf = ['location'=>['type-location','fa-key','Location'],'taxi'=>['type-taxi','fa-taxi','Taxi'],'entreprise'=>['type-entreprise','fa-building','Entreprise']]; $tc = $typeConf[$v['type_vehicule']] ?? ['','fa-car',$v['type_vehicule']]; ?>
        <span class="type-badge <?= $tc[0] ?>"><i class="fas <?= $tc[1] ?>"></i><?= $tc[2] ?></span>
        <?php if ($v['taximantre_nom']): ?>
        <div style="font-size:.67rem;color:#d97706;margin-top:2px"><i class="fas fa-user"></i> <?= sanitize($v['taximantre_nom']) ?></div>
        <?php endif ?>
        <?php if ($v['loc_en_cours']): ?>
        <div style="font-size:.67rem;color:#0d9488;margin-top:2px"><i class="fas fa-calendar-check"></i> En location</div>
        <?php endif ?>
    </td>

    <!-- Statut -->
    <td>
        <?php
        $sdot = ['disponible'=>'dot-disponible','loue'=>'dot-loue','maintenance'=>'dot-maintenance','indisponible'=>'dot-indisponible'];
        $slabel = ['disponible'=>'Disponible','loue'=>'Loué','maintenance'=>'Maintenance','indisponible'=>'Indispo'];
        $scol = ['disponible'=>'#10b981','loue'=>'#0d9488','maintenance'=>'#f59e0b','indisponible'=>'#ef4444'];
        $sc = $v['statut'];
        ?>
        <div style="display:flex;align-items:center;gap:5px">
            <span class="dot <?= $sdot[$sc] ?? '' ?>"></span>
            <span style="font-size:.75rem;color:<?= $scol[$sc] ?? 'var(--text)' ?>;font-weight:500"><?= $slabel[$sc] ?? $sc ?></span>
        </div>
    </td>

    <!-- Km -->
    <td class="fin-cell">
        <span style="font-size:.8rem"><?= $km > 0 ? number_format($km, 0, ',', ' ') . ' km' : '—' ?></span>
    </td>

    <!-- GPS -->
    <?php if (hasGpsModule()): ?>
    <td style="text-align:center">
        <?php if ($v['traccar_device_id']): ?>
            <a href="<?= BASE_URL ?>app/gps/carte.php?device=<?= $v['traccar_device_id'] ?>"
               title="GPS actif — voir sur carte" style="color:#10b981">
                <i class="fas fa-satellite-dish"></i>
            </a>
        <?php else: ?>
            <i class="fas fa-satellite-dish gps-off" title="GPS non configuré"></i>
        <?php endif ?>
    </td>
    <?php endif ?>

    <!-- Capital -->
    <td class="fin-cell">
        <?php if ($capital > 0): ?>
            <span style="font-size:.78rem;color:#64748b"><?= formatMoney($capital) ?></span>
        <?php else: ?>
            <span class="fin-muted">—</span>
        <?php endif ?>
    </td>

    <!-- Recettes -->
    <td class="fin-cell" title="Initial: <?= formatMoney($recInit) ?> | Transactions: <?= formatMoney($recCumul) ?> | Total: <?= formatMoney($recettes) ?>">
        <span style="font-size:.78rem" class="<?= $recettes > 0 ? 'fin-pos' : 'fin-muted' ?>">
            <?= $recettes > 0 ? formatMoney($recettes) : '—' ?>
        </span>
        <?php if ($recInit > 0 && $recCumul > 0): ?>
        <br><span style="font-size:.6rem;color:#94a3b8"><?= formatMoney($recInit) ?> init + <?= formatMoney($recCumul) ?></span>
        <?php elseif ($recInit > 0): ?>
        <br><span style="font-size:.6rem;color:#94a3b8"><?= formatMoney($recInit) ?> init</span>
        <?php endif ?>
    </td>

    <!-- Dépenses -->
    <td class="fin-cell" title="Initial: <?= formatMoney($depInit) ?> | Transactions: <?= formatMoney($depCumul) ?> | Total: <?= formatMoney($depenses) ?>">
        <span style="font-size:.78rem" class="<?= $depenses > 0 ? 'fin-neg' : 'fin-muted' ?>">
            <?= $depenses > 0 ? formatMoney($depenses) : '—' ?>
        </span>
        <?php if ($depInit > 0 && $depCumul > 0): ?>
        <br><span style="font-size:.6rem;color:#94a3b8"><?= formatMoney($depInit) ?> init + <?= formatMoney($depCumul) ?></span>
        <?php elseif ($depInit > 0): ?>
        <br><span style="font-size:.6rem;color:#94a3b8"><?= formatMoney($depInit) ?> init</span>
        <?php endif ?>
    </td>

    <!-- Bénéfice -->
    <td class="fin-cell">
        <?php if ($capital > 0 || $recettes > 0): ?>
            <span style="font-size:.78rem;font-weight:700;color:<?= $benefice >= 0 ? '#10b981' : '#ef4444' ?>">
                <?= ($benefice >= 0 ? '+' : '') . formatMoney($benefice) ?>
            </span>
        <?php else: ?>
            <span class="fin-muted">—</span>
        <?php endif ?>
    </td>

    <!-- ROI -->
    <td class="fin-cell">
        <?php if ($capital > 0): ?>
            <span style="font-size:.8rem;font-weight:700;color:<?= roiColor($roi) ?>">
                <?= ($roi >= 0 ? '+' : '') . number_format($roi, 1) ?>%
            </span>
        <?php else: ?>
            <span class="fin-muted">—</span>
        <?php endif ?>
    </td>

    <!-- Vidange -->
    <td>
        <?php if ($kmNext): ?>
            <?php if ($vidAlerte === 'danger'): ?>
                <span style="color:#ef4444;font-size:.75rem;font-weight:600">
                    <i class="fas fa-exclamation-triangle" style="color:#ef4444"></i>
                    <?= number_format(abs($kmRestant),0,',',' ') ?> km dépassé
                </span>
            <?php elseif ($vidAlerte === 'warning'): ?>
                <span style="color:#f59e0b;font-size:.75rem;font-weight:600">
                    <i class="fas fa-wrench" style="color:#f59e0b"></i>
                    <?= number_format($kmRestant,0,',',' ') ?> km
                </span>
            <?php else: ?>
                <span style="font-size:.75rem;color:#64748b">
                    <i class="fas fa-wrench" style="color:#94a3b8"></i>
                    <?= number_format($kmRestant,0,',',' ') ?> km
                </span>
            <?php endif ?>
        <?php else: ?>
            <span class="fin-muted">—</span>
        <?php endif ?>
    </td>

    <!-- Actions + alertes -->
    <td>
        <div style="display:flex;align-items:center;gap:3px;flex-wrap:wrap">
            <a href="<?= BASE_URL ?>app/vehicules/detail.php?id=<?= $v['id'] ?>"
               class="act-btn act-view" title="Voir fiche">
                <i class="fas fa-eye"></i>
            </a>
            <a href="<?= BASE_URL ?>app/vehicules/modifier.php?id=<?= $v['id'] ?>"
               class="act-btn act-edit" title="Modifier">
                <i class="fas fa-edit"></i>
            </a>
            <?php if ($v['traccar_device_id'] && hasGpsModule()): ?>
            <a href="<?= BASE_URL ?>app/gps/carte.php?device=<?= $v['traccar_device_id'] ?>"
               class="act-btn act-gps" title="Voir sur carte GPS">
                <i class="fas fa-map-location-dot"></i>
            </a>
            <?php endif ?>
            <button class="act-btn act-del" title="Supprimer"
                    onclick="delVeh(<?= $v['id'] ?>,'<?= addslashes(sanitize($v['nom'])) ?>')">
                <i class="fas fa-trash"></i>
            </button>

            <!-- Alertes icônes contextuelles -->
            <?php if ($assExpire): ?>
                <span title="Assurance expirée le <?= formatDate($assExp) ?>" style="font-size:.7rem;color:#ef4444" class="alert-icon">
                    <i class="fas fa-shield-halved"></i>
                </span>
            <?php elseif ($assAlerte): ?>
                <span title="Assurance expire le <?= formatDate($assExp) ?>" style="font-size:.7rem;color:#f59e0b" class="alert-icon">
                    <i class="fas fa-shield-halved"></i>
                </span>
            <?php endif ?>

            <?php if ($vigExpire): ?>
                <span title="Vignette expirée le <?= formatDate($vigExp) ?>" style="font-size:.7rem;color:#ef4444" class="alert-icon">
                    <i class="fas fa-file-alt"></i>
                </span>
            <?php elseif ($vigAlerte): ?>
                <span title="Vignette expire le <?= formatDate($vigExp) ?>" style="font-size:.7rem;color:#f59e0b" class="alert-icon">
                    <i class="fas fa-file-alt"></i>
                </span>
            <?php endif ?>

            <?php if ($nbMaintUrgent > 0): ?>
                <span title="<?= $nbMaintUrgent ?> maintenance(s) urgente(s)" style="font-size:.7rem;color:#ef4444" class="alert-icon">
                    <i class="fas fa-wrench"></i>
                </span>
            <?php endif ?>
        </div>
    </td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>

<!-- LÉGENDE + TOTAUX -->
<?php
$totCap  = array_sum(array_column($vehicules, 'capital_investi'));
$totRec  = array_sum(array_column($vehicules, 'total_recettes'));
$totDep  = array_sum(array_column($vehicules, 'total_depenses'));
$totBen  = $totRec - $totDep - $totCap;
$roiGlob = $totCap > 0 ? ($totBen / $totCap * 100) : 0;
?>
<div style="background:#f8fafc;border-top:2px solid #e2e8f0;padding:8px 12px;display:flex;align-items:center;gap:20px;font-size:.75rem;flex-wrap:wrap">
    <!-- Légende statuts -->
    <div style="display:flex;gap:10px;align-items:center">
        <span style="color:#64748b;font-weight:600">Statut:</span>
        <?php foreach (['disponible'=>'#10b981','loue'=>'#0d9488','maintenance'=>'#f59e0b','indisponible'=>'#ef4444'] as $s=>$c): ?>
        <span style="display:flex;align-items:center;gap:3px">
            <span class="dot" style="background:<?= $c ?>"></span>
            <span style="color:#64748b"><?= ucfirst($s) ?></span>
        </span>
        <?php endforeach ?>
    </div>
    <div style="height:14px;width:1px;background:#e2e8f0"></div>
    <!-- Légende alertes -->
    <div style="display:flex;gap:10px;align-items:center">
        <span style="color:#64748b;font-weight:600">Alertes:</span>
        <span style="color:#ef4444"><i class="fas fa-shield-halved"></i> Assurance</span>
        <span style="color:#f59e0b"><i class="fas fa-file-alt"></i> Vignette</span>
        <span style="color:#ef4444"><i class="fas fa-wrench"></i> Maintenance</span>
    </div>
    <div style="flex:1"></div>
    <!-- Totaux flotte -->
    <div style="display:flex;gap:14px;align-items:center;font-size:.73rem">
        <span style="color:#64748b">Flotte · <?= count($vehicules) ?> véh.</span>
        <span>Capital: <strong style="color:#64748b"><?= formatMoney($totCap) ?></strong></span>
        <span>Recettes: <strong class="fin-pos"><?= formatMoney($totRec) ?></strong></span>
        <span>Dépenses: <strong class="fin-neg"><?= formatMoney($totDep) ?></strong></span>
        <span>Bénéfice: <strong style="color:<?= $totBen >= 0 ? '#10b981' : '#ef4444' ?>;font-weight:700"><?= ($totBen>=0?'+':'').formatMoney($totBen) ?></strong></span>
        <span>ROI global: <strong style="color:<?= roiColor($roiGlob) ?>;font-weight:700"><?= ($roiGlob>=0?'+':'').number_format($roiGlob,1) ?>%</strong></span>
    </div>
</div>

<!-- Pagination -->
<?php if ($total > $perPage): ?>
<div style="padding:10px 14px;border-top:1px solid var(--border)">
    <?= renderPagination($total, $page, $perPage, $baseUrl) ?>
</div>
<?php endif ?>
<?php endif ?>
</div>

<!-- CARDS (mobile) -->
<div class="veh-mobile-cards">
<?php if (empty($vehicules)): ?>
<div style="text-align:center;padding:30px;color:#94a3b8;font-size:.88rem">Aucun véhicule</div>
<?php else: ?>
<?php
$sdotM = ['disponible'=>'#10b981','loue'=>'#0d9488','maintenance'=>'#f59e0b','indisponible'=>'#ef4444'];
$slabelM = ['disponible'=>'Disponible','loue'=>'Loué','maintenance'=>'Maintenance','indisponible'=>'Indispo'];
foreach ($vehicules as $v):
    $capital  = (float)($v['capital_investi'] ?? 0);
    $recettes = (float)($v['total_recettes'] ?? 0);
    $depenses = (float)($v['total_depenses'] ?? 0);
    $benefice = $recettes - $depenses - $capital;
    $km = (int)($v['kilometrage_actuel'] ?? 0);
    $statColor = $sdotM[$v['statut']] ?? '#94a3b8';
?>
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px">
    <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:8px">
        <?php if ($v['photo']): ?>
        <img src="<?= BASE_URL ?>uploads/logos/<?= sanitize($v['photo']) ?>"
             style="width:40px;height:36px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0;flex-shrink:0">
        <?php else: ?>
        <div style="width:40px;height:36px;background:#f1f5f9;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-car" style="color:#94a3b8;font-size:.75rem"></i>
        </div>
        <?php endif ?>
        <div style="flex:1;min-width:0">
            <div style="font-weight:700;color:#0f172a;font-size:.9rem"><?= sanitize($v['nom']) ?></div>
            <div style="font-size:.75rem;color:#64748b;font-family:monospace"><?= sanitize($v['immatriculation']) ?></div>
        </div>
        <div style="display:flex;align-items:center;gap:5px">
            <span class="dot" style="background:<?= $statColor ?>"></span>
            <span style="font-size:.72rem;color:<?= $statColor ?>;font-weight:600"><?= $slabelM[$v['statut']] ?? $v['statut'] ?></span>
        </div>
    </div>
    <div style="display:flex;gap:8px;font-size:.75rem;color:#64748b;margin-bottom:8px">
        <?php if ($km > 0): ?><span><i class="fas fa-road" style="font-size:.65rem"></i> <?= number_format($km,0,',',' ') ?> km</span><?php endif ?>
        <?php if ($benefice != 0): ?>
        <span style="margin-left:auto;font-weight:700;color:<?= $benefice >= 0 ? '#10b981' : '#ef4444' ?>">
            <?= ($benefice >= 0 ? '+' : '') . formatMoney($benefice) ?>
        </span>
        <?php endif ?>
    </div>
    <div style="display:flex;gap:4px">
        <a href="<?= BASE_URL ?>app/vehicules/detail.php?id=<?= $v['id'] ?>" class="act-btn act-view" title="Voir"><i class="fas fa-eye"></i></a>
        <a href="<?= BASE_URL ?>app/vehicules/modifier.php?id=<?= $v['id'] ?>" class="act-btn act-edit" title="Modifier"><i class="fas fa-edit"></i></a>
        <?php if ($v['traccar_device_id'] && hasGpsModule()): ?>
        <a href="<?= BASE_URL ?>app/gps/carte.php?device=<?= $v['traccar_device_id'] ?>" class="act-btn act-gps" title="GPS"><i class="fas fa-map-location-dot"></i></a>
        <?php endif ?>
        <button class="act-btn act-del" onclick="delVeh(<?= $v['id'] ?>,'<?= addslashes(sanitize($v['nom'])) ?>')" title="Supprimer"><i class="fas fa-trash"></i></button>
    </div>
</div>
<?php endforeach ?>
<?php if ($total > $perPage): ?>
<div style="padding:4px 2px"><?= renderPagination($total, $page, $perPage, $baseUrl) ?></div>
<?php endif ?>
<?php endif ?>
</div>

<!-- Form suppression -->
<form id="form-del" method="POST" action="<?= BASE_URL ?>app/vehicules/supprimer.php" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="id" id="del-id">
</form>

<script>
function delVeh(id, nom) {
    if (confirm('Supprimer le véhicule "' + nom + '" ?\nCette action est irréversible.')) {
        document.getElementById('del-id').value = id;
        document.getElementById('form-del').submit();
    }
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
