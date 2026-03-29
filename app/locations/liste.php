<?php
/**
 * FlotteCar — Liste des locations
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireTenantAuth();

if (!hasLocationModule()) {
    setFlash(FLASH_ERROR, 'Module Locations non disponible.');
    redirect(BASE_URL . 'app/dashboard.php');
}

$db       = (new Database())->getConnection();
$tenantId = getTenantId();

// ── Filtres ──────────────────────────────────────────────────────────────────
$fStatut  = trim($_GET['statut']      ?? '');
$fMois    = (int)($_GET['mois']       ?? 0);
$fAnnee   = (int)($_GET['annee']      ?? 0);
$fVeh     = (int)($_GET['vehicule_id']?? 0);
$fQ       = trim($_GET['q']           ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per      = ITEMS_PER_PAGE;
$offset   = ($page - 1) * $per;
$today    = date('Y-m-d');

$where  = ['l.tenant_id = ?'];
$params = [$tenantId];
if ($fStatut !== '') { $where[] = 'l.statut = ?';                   $params[] = $fStatut; }
if ($fMois   > 0)   { $where[] = 'MONTH(l.date_debut) = ?';        $params[] = $fMois; }
if ($fAnnee  > 0)   { $where[] = 'YEAR(l.date_debut) = ?';         $params[] = $fAnnee; }
if ($fVeh    > 0)   { $where[] = 'l.vehicule_id = ?';              $params[] = $fVeh; }
if ($fQ !== '')     {
    $where[]  = '(c.nom LIKE ? OR c.prenom LIKE ? OR v.immatriculation LIKE ? OR v.nom LIKE ?)';
    $like     = '%' . $fQ . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
$wSQL = 'WHERE ' . implode(' AND ', $where);

// Compteurs par statut (pour tabs) — sans filtre statut
$cntParams = [$tenantId];
$cntWhere  = 'WHERE l.tenant_id = ?';
if ($fMois  > 0) { $cntWhere .= ' AND MONTH(l.date_debut) = ?'; $cntParams[] = $fMois; }
if ($fAnnee > 0) { $cntWhere .= ' AND YEAR(l.date_debut) = ?';  $cntParams[] = $fAnnee; }
if ($fVeh   > 0) { $cntWhere .= ' AND l.vehicule_id = ?';       $cntParams[] = $fVeh; }
$cnts = $db->prepare("SELECT statut, COUNT(*) n FROM locations l $cntWhere GROUP BY statut");
$cnts->execute($cntParams);
$cntByStatut = ['all' => 0];
foreach ($cnts->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cntByStatut[$r['statut']] = (int)$r['n'];
    $cntByStatut['all'] += (int)$r['n'];
}

// Total paginé
$stTotal = $db->prepare("SELECT COUNT(*) FROM locations l JOIN vehicules v ON v.id=l.vehicule_id JOIN clients c ON c.id=l.client_id $wSQL");
$stTotal->execute($params);
$total = (int)$stTotal->fetchColumn();

// Locations
$locations = $db->prepare("
    SELECT l.*,
           v.nom AS veh_nom, v.immatriculation,
           c.nom AS cli_nom, c.prenom AS cli_prenom, c.telephone AS cli_tel,
           TRIM(CONCAT(ch.nom,' ',COALESCE(ch.prenom,''))) AS chauffeur_nom
    FROM locations l
    JOIN vehicules   v  ON v.id  = l.vehicule_id
    JOIN clients     c  ON c.id  = l.client_id
    LEFT JOIN chauffeurs ch ON ch.id = l.chauffeur_id
    $wSQL
    ORDER BY l.created_at DESC
    LIMIT $per OFFSET $offset
");
$locations->execute($params);
$locations = $locations->fetchAll(PDO::FETCH_ASSOC);

// Totaux (sur toute la sélection, pas seulement la page)
$stTot = $db->prepare("
    SELECT COALESCE(SUM(l.montant_final),0) total_revenus,
           COALESCE(SUM(l.reste_a_payer),0) total_reste,
           COALESCE(SUM(l.avance),0) total_avance
    FROM locations l JOIN vehicules v ON v.id=l.vehicule_id JOIN clients c ON c.id=l.client_id $wSQL
");
$stTot->execute($params);
$totaux = $stTot->fetch(PDO::FETCH_ASSOC);

// KPIs globaux (pas filtrés)
$kpi = $db->prepare("SELECT COUNT(*) total, SUM(statut='en_cours') en_cours, SUM(statut='terminee') terminees, COALESCE(SUM(IF(statut='terminee',montant_final,0)),0) revenus, COALESCE(SUM(reste_a_payer),0) a_encaisser FROM locations WHERE tenant_id=?");
$kpi->execute([$tenantId]);
$kpi = $kpi->fetch(PDO::FETCH_ASSOC);

// Véhicules pour filtre
$stVehs = $db->prepare("SELECT id,nom,immatriculation FROM vehicules WHERE tenant_id=? ORDER BY nom");
$stVehs->execute([$tenantId]);
$vehicules = $stVehs->fetchAll(PDO::FETCH_ASSOC);

$baseUrl    = BASE_URL . 'app/locations/liste.php?' . http_build_query(array_filter(['statut'=>$fStatut,'mois'=>$fMois?:null,'annee'=>$fAnnee?:null,'vehicule_id'=>$fVeh?:null,'q'=>$fQ]));
$pageTitle  = 'Locations';
$activePage = 'locations';
require_once BASE_PATH . '/includes/header.php';
?>

<style>
/* ── Locations liste ────────────────────── */
@keyframes lfadeup { from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:none} }

.loc-kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:14px; }
.loc-kpi {
    background:#fff; border:1px solid #e2e8f0; border-radius:12px;
    padding:12px 14px; display:flex; align-items:center; gap:10px;
}
.loc-kpi-ico {
    width:38px; height:38px; border-radius:10px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:.88rem;
}
.loc-kpi-val { font-size:1.1rem; font-weight:800; color:#0f172a; line-height:1.1; }
.loc-kpi-lbl { font-size:.67rem; color:#94a3b8; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }

.loc-tabs { display:flex; gap:4px; margin-bottom:10px; flex-wrap:wrap; }
.loc-tab {
    display:inline-flex; align-items:center; gap:5px;
    padding:5px 12px; border-radius:8px; font-size:.78rem; font-weight:600;
    text-decoration:none; border:1px solid #e2e8f0; background:#fff; color:#475569;
    transition:all .12s;
}
.loc-tab:hover { background:#f1f5f9; }
.loc-tab.active { background:#0d9488; color:#fff; border-color:#0d9488; }
.loc-tab.active.orange { background:#f59e0b; border-color:#f59e0b; }
.loc-tab.active.green  { background:#10b981; border-color:#10b981; }
.loc-tab.active.red    { background:#ef4444; border-color:#ef4444; }
.loc-tab-cnt { font-size:.7rem; opacity:.75; }

.loc-table{width:100%;border-collapse:collapse;font-size:.8rem}
.loc-table th{padding:7px 10px;font-size:.66rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;background:#f8fafc;border-bottom:2px solid #e2e8f0;white-space:nowrap;font-weight:700}
.loc-table td{padding:7px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.loc-table tbody tr{animation:lfadeup .22s ease both;transition:background .1s}
.loc-table tbody tr:hover{background:#f8faff}
.loc-table tbody tr.retard{background:#fff8f8}
.retard-tag{display:inline-block;padding:1px 5px;background:#fee2e2;color:#ef4444;border-radius:3px;font-size:.63rem;font-weight:700;margin-left:4px}

.loc-act{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:7px;font-size:.72rem;text-decoration:none;transition:.12s;border:none;cursor:pointer}
.loc-act.view{background:#f0f9ff;color:#0ea5e9}.loc-act.view:hover{background:#e0f2fe}
.loc-act.end{background:#fefce8;color:#ca8a04}.loc-act.end:hover{background:#fef9c3}
.loc-act.pdf{background:#f8fafc;color:#64748b}.loc-act.pdf:hover{background:#f1f5f9}

/* Mobile cards */
.loc-cards { display:none; flex-direction:column; gap:10px; }
@media(max-width:768px) {
    .loc-table-wrap { display:none !important; }
    .loc-cards { display:flex !important; }
    .loc-kpi-grid { grid-template-columns:repeat(2,1fr); }
    .loc-kpi-val { font-size:.95rem; }
}
</style>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px">
    <div>
        <h1 style="font-size:1.3rem;font-weight:800;color:#0f172a;margin:0"><i class="fas fa-file-contract" style="color:#0d9488;font-size:1.1rem;margin-right:6px"></i>Locations</h1>
        <p style="font-size:.78rem;color:#94a3b8;margin:3px 0 0">
            <strong style="color:#0f172a"><?= number_format((int)$kpi['total'],0,',',' ') ?></strong> contrats ·
            <strong style="color:#f59e0b"><?= (int)$kpi['en_cours'] ?></strong> en cours
        </p>
    </div>
    <a href="<?= BASE_URL ?>app/locations/nouvelle.php" class="btn btn-primary btn-sm">
        <i class="fas fa-plus"></i> Nouvelle location
    </a>
</div>

<?= renderFlashes() ?>

<!-- KPIs -->
<div class="loc-kpi-grid">
    <div class="loc-kpi">
        <div class="loc-kpi-ico" style="background:#eff6ff">
            <i class="fas fa-file-contract" style="color:#0d9488"></i>
        </div>
        <div>
            <div class="loc-kpi-val"><?= (int)$kpi['total'] ?></div>
            <div class="loc-kpi-lbl">Total</div>
        </div>
    </div>
    <div class="loc-kpi">
        <div class="loc-kpi-ico" style="background:#fefce8">
            <i class="fas fa-car-side" style="color:#ca8a04"></i>
        </div>
        <div>
            <div class="loc-kpi-val"><?= (int)$kpi['en_cours'] ?></div>
            <div class="loc-kpi-lbl">En cours</div>
        </div>
    </div>
    <div class="loc-kpi">
        <div class="loc-kpi-ico" style="background:#f0fdf4">
            <i class="fas fa-check-circle" style="color:#10b981"></i>
        </div>
        <div>
            <div class="loc-kpi-val" style="font-size:.88rem"><?= formatMoney((float)$kpi['revenus']) ?></div>
            <div class="loc-kpi-lbl">Encaissé</div>
        </div>
    </div>
    <div class="loc-kpi">
        <div class="loc-kpi-ico" style="background:#fff1f2">
            <i class="fas fa-hourglass-half" style="color:#ef4444"></i>
        </div>
        <div>
            <div class="loc-kpi-val" style="font-size:.88rem"><?= formatMoney((float)$kpi['a_encaisser']) ?></div>
            <div class="loc-kpi-lbl">À encaisser</div>
        </div>
    </div>
</div>

<!-- Filtres -->
<div class="card" style="margin-bottom:10px">
    <div class="card-body" style="padding:10px 14px">
        <form method="GET" class="filter-form" style="flex-wrap:wrap;gap:8px">
            <div style="position:relative">
                <i class="fas fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.75rem;pointer-events:none"></i>
                <input type="text" name="q" class="form-control" placeholder="Client, immat…"
                       value="<?= sanitize($fQ) ?>" style="padding-left:32px;width:180px">
            </div>
            <select name="vehicule_id" class="form-control" style="width:160px">
                <option value="">Tous véhicules</option>
                <?php foreach ($vehicules as $v): ?>
                <option value="<?= $v['id'] ?>" <?= $fVeh==$v['id']?'selected':'' ?>><?= sanitize($v['immatriculation'].' – '.$v['nom']) ?></option>
                <?php endforeach ?>
            </select>
            <select name="mois" class="form-control" style="width:88px">
                <option value="">Mois</option>
                <?php $ml=['','Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
                for($m=1;$m<=12;$m++): ?>
                <option value="<?= $m ?>" <?= $fMois==$m?'selected':'' ?>><?= $ml[$m] ?></option>
                <?php endfor ?>
            </select>
            <select name="annee" class="form-control" style="width:78px">
                <option value="">Année</option>
                <?php for($y=date('Y');$y>=date('Y')-4;$y--): ?>
                <option value="<?= $y ?>" <?= $fAnnee==$y?'selected':'' ?>><?= $y ?></option>
                <?php endfor ?>
            </select>
            <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i> Filtrer</button>
            <?php if ($fQ||$fMois||$fAnnee||$fVeh||$fStatut): ?>
            <a href="<?= BASE_URL ?>app/locations/liste.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a>
            <?php endif ?>
        </form>
    </div>
</div>

<!-- Tabs statut -->
<div class="loc-tabs">
    <?php
    $tabs = [
        ''         => ['Toutes',    $cntByStatut['all']           ?? 0, ''],
        'en_cours' => ['En cours',  $cntByStatut['en_cours']      ?? 0, 'orange'],
        'terminee' => ['Terminées', $cntByStatut['terminee']      ?? 0, 'green'],
        'annulee'  => ['Annulées',  $cntByStatut['annulee']       ?? 0, 'red'],
    ];
    foreach ($tabs as $val => [$lbl, $cnt, $col]):
        $active = $fStatut === $val;
        $url = BASE_URL . 'app/locations/liste.php?' . http_build_query(array_filter(['statut'=>$val,'mois'=>$fMois?:null,'annee'=>$fAnnee?:null,'vehicule_id'=>$fVeh?:null,'q'=>$fQ]));
    ?>
    <a href="<?= $url ?>" class="loc-tab <?= $active ? 'active '.$col : '' ?>">
        <?= $lbl ?> <span class="loc-tab-cnt"><?= $cnt ?></span>
    </a>
    <?php endforeach ?>
</div>

<!-- TABLE (desktop) -->
<div class="card loc-table-wrap" style="overflow:hidden">
<?php if (empty($locations)): ?>
<div style="padding:40px;text-align:center;color:#94a3b8">
    <i class="fas fa-file-contract" style="font-size:2.2rem;display:block;margin-bottom:10px;opacity:.4"></i>
    <div style="font-size:.88rem;font-weight:600;color:#64748b">Aucune location trouvée</div>
</div>
<?php else: ?>
<div style="overflow-x:auto">
<table class="loc-table">
    <thead>
        <tr>
            <th>#</th>
            <th>Véhicule</th>
            <th>Client</th>
            <th>Période</th>
            <th style="text-align:center">Jours</th>
            <th style="text-align:right">Montant</th>
            <th style="text-align:right">Reste</th>
            <th>Paiement</th>
            <th>Statut</th>
            <th style="text-align:center;width:86px">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($locations as $li => $loc):
        $retard = ($loc['statut'] === 'en_cours' && $loc['date_fin'] < $today);
    ?>
    <tr class="<?= $retard ? 'retard' : '' ?>" style="animation-delay:<?= $li*.025 ?>s">
        <td style="color:#94a3b8;font-size:.72rem">#<?= $loc['id'] ?></td>
        <td>
            <div style="font-weight:700;color:#0f172a;font-size:.82rem"><?= sanitize($loc['immatriculation']) ?></div>
            <div style="color:#94a3b8;font-size:.72rem"><?= sanitize($loc['veh_nom']) ?></div>
        </td>
        <td>
            <div style="font-weight:600;font-size:.82rem"><?= sanitize($loc['cli_nom'].($loc['cli_prenom'] ? ' '.$loc['cli_prenom'] : '')) ?></div>
            <?php if ($loc['cli_tel']): ?><div style="color:#94a3b8;font-size:.72rem"><i class="fas fa-phone" style="font-size:.63rem;color:#10b981"></i> <?= sanitize($loc['cli_tel']) ?></div><?php endif ?>
            <?php if ($loc['chauffeur_nom']): ?><div style="color:#64748b;font-size:.7rem"><i class="fas fa-id-badge" style="font-size:.63rem"></i> <?= sanitize($loc['chauffeur_nom']) ?></div><?php endif ?>
        </td>
        <td style="white-space:nowrap;font-size:.8rem">
            <?= formatDate($loc['date_debut']) ?> <span style="color:#cbd5e1">→</span> <?= formatDate($loc['date_fin']) ?>
            <?= $retard ? '<span class="retard-tag">RETARD</span>' : '' ?>
        </td>
        <td style="text-align:center;font-weight:700;font-size:.82rem"><?= (int)$loc['nombre_jours'] ?>j</td>
        <td style="text-align:right;font-weight:700;white-space:nowrap;color:#0f172a"><?= formatMoney((float)$loc['montant_final']) ?></td>
        <td style="text-align:right;white-space:nowrap">
            <?php if ((float)$loc['reste_a_payer'] > 0): ?>
            <span style="color:#ef4444;font-weight:700;font-size:.82rem"><?= formatMoney((float)$loc['reste_a_payer']) ?></span>
            <?php else: ?>
            <span style="color:#10b981;font-size:.82rem"><i class="fas fa-check-circle"></i></span>
            <?php endif ?>
        </td>
        <td><?= badgePaiement($loc['statut_paiement']) ?></td>
        <td><?= badgeLocation($loc['statut']) ?></td>
        <td>
            <div style="display:flex;gap:3px;justify-content:center">
                <a href="<?= BASE_URL ?>app/locations/detail.php?id=<?= $loc['id'] ?>" class="loc-act view" title="Détail"><i class="fas fa-eye"></i></a>
                <?php if ($loc['statut'] === 'en_cours'): ?>
                <a href="<?= BASE_URL ?>app/locations/terminer.php?id=<?= $loc['id'] ?>" class="loc-act end" title="Clôturer"><i class="fas fa-flag-checkered"></i></a>
                <?php endif ?>
                <a href="<?= BASE_URL ?>app/locations/contrat_pdf.php?id=<?= $loc['id'] ?>" class="loc-act pdf" title="PDF" target="_blank"><i class="fas fa-file-pdf"></i></a>
            </div>
        </td>
    </tr>
    <?php endforeach ?>
    </tbody>
    <tfoot style="background:#f8fafc;font-weight:700;font-size:.77rem">
        <tr>
            <td colspan="5" style="color:#64748b;padding:7px 10px"><?= $total ?> location<?= $total>1?'s':'' ?> · page <?= $page ?></td>
            <td style="text-align:right;padding:7px 10px;color:#10b981"><?= formatMoney((float)$totaux['total_revenus']) ?></td>
            <td style="text-align:right;padding:7px 10px;color:#ef4444"><?= formatMoney((float)$totaux['total_reste']) ?></td>
            <td colspan="3"></td>
        </tr>
    </tfoot>
</table>
</div>
<div style="padding:10px 14px;border-top:1px solid #f1f5f9"><?= renderPagination($total, $page, $per, $baseUrl) ?></div>
<?php endif ?>
</div>

<!-- CARDS (mobile) -->
<div class="loc-cards">
<?php if (empty($locations)): ?>
<div style="text-align:center;padding:30px;color:#94a3b8;font-size:.88rem">Aucune location trouvée</div>
<?php else: ?>
<?php foreach ($locations as $loc):
    $retard = ($loc['statut'] === 'en_cours' && $loc['date_fin'] < $today);
?>
<div style="background:#fff;border:1px solid <?= $retard?'#fecaca':'#e2e8f0' ?>;border-radius:12px;padding:12px 14px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
        <div>
            <div style="font-weight:700;color:#0f172a;font-size:.9rem"><?= sanitize($loc['immatriculation']) ?> <span style="color:#94a3b8;font-size:.75rem;font-weight:400"><?= sanitize($loc['veh_nom']) ?></span></div>
            <div style="font-size:.82rem;color:#475569;margin-top:2px"><?= sanitize($loc['cli_nom'].($loc['cli_prenom'] ? ' '.$loc['cli_prenom'] : '')) ?></div>
        </div>
        <div style="display:flex;gap:3px">
            <?= badgeLocation($loc['statut']) ?>
            <?= $retard ? '<span class="retard-tag">RETARD</span>' : '' ?>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;font-size:.78rem;color:#64748b;margin-bottom:8px">
        <i class="fas fa-calendar" style="color:#94a3b8;font-size:.65rem"></i>
        <?= formatDate($loc['date_debut']) ?> → <?= formatDate($loc['date_fin']) ?>
        <span style="margin-left:4px;font-weight:600;color:#0f172a"><?= (int)$loc['nombre_jours'] ?>j</span>
    </div>
    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
        <span style="font-weight:800;color:#0f172a;font-size:.88rem"><?= formatMoney((float)$loc['montant_final']) ?></span>
        <?php if ((float)$loc['reste_a_payer'] > 0): ?>
        <span style="font-size:.75rem;color:#ef4444;font-weight:600">reste <?= formatMoney((float)$loc['reste_a_payer']) ?></span>
        <?php else: ?>
        <span style="font-size:.75rem;color:#10b981"><i class="fas fa-check-circle"></i> soldé</span>
        <?php endif ?>
        <div style="margin-left:auto;display:flex;gap:3px">
            <a href="<?= BASE_URL ?>app/locations/detail.php?id=<?= $loc['id'] ?>" class="loc-act view"><i class="fas fa-eye"></i></a>
            <?php if ($loc['statut'] === 'en_cours'): ?>
            <a href="<?= BASE_URL ?>app/locations/terminer.php?id=<?= $loc['id'] ?>" class="loc-act end"><i class="fas fa-flag-checkered"></i></a>
            <?php endif ?>
            <a href="<?= BASE_URL ?>app/locations/contrat_pdf.php?id=<?= $loc['id'] ?>" class="loc-act pdf" target="_blank"><i class="fas fa-file-pdf"></i></a>
        </div>
    </div>
</div>
<?php endforeach ?>
<div style="padding:4px 2px"><?= renderPagination($total, $page, $per, $baseUrl) ?></div>
<?php endif ?>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
