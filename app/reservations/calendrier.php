<?php
/**
 * FlotteCar — Calendrier réservations & disponibilités
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireTenantAuth();

if (!hasLocationModule()) {
    setFlash(FLASH_ERROR, 'Module Locations requis.');
    redirect(BASE_URL . 'app/dashboard.php');
}

$db       = (new Database())->getConnection();
$tenantId = getTenantId();

// ── Période ───────────────────────────────────────────────────────────────────
$mois = $_GET['mois'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mois)) $mois = date('Y-m');

$debutCal      = $mois . '-01';
$finCal        = date('Y-m-t', strtotime($debutCal));
$nbJoursMois   = (int)date('t', strtotime($debutCal));
$moisPrev      = date('Y-m', strtotime($debutCal . ' -1 month'));
$moisNext      = date('Y-m', strtotime($debutCal . ' +1 month'));
$moisNoms      = [1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',
                  7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'];
$moisLabel     = $moisNoms[(int)date('m', strtotime($debutCal))] . ' ' . date('Y', strtotime($debutCal));

$todayYmd      = date('Y-m-d');
$isMoisCourant = (date('Y-m') === $mois);
$todayDay      = $isMoisCourant ? (int)date('j') : 1;  // premier jour affiché

// Jours à afficher : à partir d'aujourd'hui pour le mois courant, sinon tout
$premierJour   = $isMoisCourant ? $todayDay : 1;

// Construire la liste des jours affichés avec métadonnées
$jours = [];
$joursAbbr = ['Di','Lu','Ma','Me','Je','Ve','Sa'];
for ($j = $premierJour; $j <= $nbJoursMois; $j++) {
    $ts  = mktime(0,0,0, (int)date('m', strtotime($debutCal)), $j, (int)date('Y', strtotime($debutCal)));
    $dow = (int)date('w', $ts);
    $jours[$j] = ['dow' => $dow, 'abbr' => $joursAbbr[$dow], 'ts' => $ts, 'ymd' => date('Y-m-d', $ts)];
}

// ── Véhicules ─────────────────────────────────────────────────────────────────
$stVehs = $db->prepare("SELECT id, nom, immatriculation, statut, prix_location_jour
    FROM vehicules WHERE tenant_id=? AND type_vehicule='location' ORDER BY nom");
$stVehs->execute([$tenantId]);
$vehicules = $stVehs->fetchAll(PDO::FETCH_ASSOC);

// ── Events ────────────────────────────────────────────────────────────────────
$debutAff = $mois . '-' . sprintf('%02d', $premierJour);
$stEv = $db->prepare("
    SELECT 'location' AS ev_type, l.id, l.vehicule_id, l.statut,
           l.date_debut, l.date_fin, l.montant_final,
           c.nom AS client_nom, c.prenom AS client_prenom
    FROM locations l JOIN clients c ON c.id=l.client_id
    WHERE l.tenant_id=? AND l.statut='en_cours'
      AND l.date_fin >= ? AND l.date_debut <= ?
    UNION ALL
    SELECT 'reservation' AS ev_type, r.id, r.vehicule_id, r.statut,
           r.date_debut, r.date_fin, r.montant_final,
           c.nom AS client_nom, c.prenom AS client_prenom
    FROM reservations r JOIN clients c ON c.id=r.client_id
    WHERE r.tenant_id=? AND r.statut IN('en_attente','confirmee')
      AND r.date_fin >= ? AND r.date_debut <= ?
");
$stEv->execute([$tenantId, $debutAff, $finCal, $tenantId, $debutAff, $finCal]);
$events = $stEv->fetchAll(PDO::FETCH_ASSOC);

// Matrice [vehicule_id][jour] = event
$calData = [];
foreach ($events as $ev) {
    $vId  = (int)$ev['vehicule_id'];
    $from = max($premierJour, (int)date('j', max(strtotime($ev['date_debut']), strtotime($debutAff))));
    $to   = min($nbJoursMois, (int)date('j', min(strtotime($ev['date_fin']),   strtotime($finCal))));
    for ($d = $from; $d <= $to; $d++) {
        if (!isset($calData[$vId][$d])) $calData[$vId][$d] = $ev;
    }
}

// Réservations actives (liste)
$stResa = $db->prepare("
    SELECT r.*, v.nom AS veh_nom, v.immatriculation,
           c.nom AS client_nom, c.prenom AS client_prenom, c.telephone
    FROM reservations r
    JOIN vehicules v ON v.id=r.vehicule_id
    JOIN clients   c ON c.id=r.client_id
    WHERE r.tenant_id=? AND r.statut NOT IN('annulee','convertie')
      AND r.date_fin >= ? ORDER BY r.date_debut ASC
");
$stResa->execute([$tenantId, $todayYmd]);
$reservations = $stResa->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stStats = $db->prepare("SELECT
    SUM(statut='en_cours') AS loc_actives
    FROM locations WHERE tenant_id=?");
$stStats->execute([$tenantId]);
$stats = $stStats->fetch(PDO::FETCH_ASSOC);

$stRsv = $db->prepare("SELECT SUM(statut='en_attente') AS en_attente, SUM(statut='confirmee') AS confirmees
    FROM reservations WHERE tenant_id=? AND date_fin>=CURDATE()");
$stRsv->execute([$tenantId]);
$statsRsv = $stRsv->fetch(PDO::FETCH_ASSOC);

$pageTitle  = 'Calendrier';
$activePage = 'reservations';
require_once BASE_PATH . '/includes/header.php';
?>

<style>
/* ══ Wrapper ══ */
.cal-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }

/* ══ Table ══ */
.cal-table {
    border-collapse: separate;
    border-spacing: 0;
    font-size: 12px;
    min-width: 100%;
}

/* ══ Colonne véhicule (sticky) ══ */
.col-veh {
    position: sticky; left: 0; z-index: 20;
    width: 110px; min-width: 110px; max-width: 110px;
}
.th-veh {
    background: #0f172a; color: #cbd5e1;
    padding: 8px 10px; font-size: 10px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .04em;
    border-right: 2px solid #334155;
}
.td-veh {
    background: #f8fafc;
    padding: 6px 8px;
    border-right: 2px solid #e2e8f0;
    border-bottom: 1px solid #e2e8f0;
    vertical-align: middle;
}
.veh-nom   { font-weight: 700; font-size: 11px; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 95px; }
.veh-immat { font-size: 9px; color: #94a3b8; }
.veh-prix  { font-size: 9px; color: #0d9488; font-weight: 600; margin-top: 1px; }

/* ══ En-têtes jours ══ */
.th-day {
    min-width: 44px; width: 44px;
    padding: 4px 2px; text-align: center;
    border: 1px solid #e2e8f0;
    border-top: none;
    cursor: default;
    vertical-align: middle;
}
.th-day-num  { font-size: 13px; font-weight: 800; line-height: 1.1; color: #0f172a; }
.th-day-abbr { font-size: 8px; text-transform: uppercase; color: #94a3b8; letter-spacing: .05em; }

.th-day.today    { background: #0d9488 !important; border-color: #0d9488; }
.th-day.today .th-day-num  { color: #fff; }
.th-day.today .th-day-abbr { color: #bfdbfe; }
.th-day.weekend  { background: #fefce8; }
.th-day.weekend .th-day-num  { color: #92400e; }

/* ══ Cellules jours ══ */
.cal-cell {
    min-width: 44px; width: 44px; height: 42px;
    padding: 0; text-align: center; vertical-align: middle;
    border: 1px solid #e2e8f0;
    cursor: pointer;
    position: relative;
    transition: background .12s, transform .08s;
}
.cal-cell:active { transform: scale(.96); }

/* Libre */
.cal-cell.libre            { background: #ffffff; }
.cal-cell.libre:hover      { background: #eff6ff; }
.cal-cell.libre.weekend    { background: #fffbeb; }
.cal-cell.libre.weekend:hover { background: #fef9c3; }
.cal-cell.today-col        { box-shadow: inset 0 0 0 2px #0d9488; }

/* Indicateur disponible */
.cal-cell.libre::after {
    content: '';
    display: block;
    width: 6px; height: 6px;
    border-radius: 50%;
    background: #bbf7d0;
    margin: auto;
    transition: transform .12s;
}
.cal-cell.libre:hover::after { transform: scale(1.5); background: #4ade80; }
.cal-cell.today-col.libre::after { background: #93c5fd; }

/* ══ Événements (fond plein animé) ══ */
.ev-block {
    position: absolute; inset: 2px;
    border-radius: 5px;
    display: flex; align-items: center; justify-content: center;
    font-size: 9px; font-weight: 700;
    overflow: hidden;
    letter-spacing: .02em;
    transition: filter .12s, transform .1s;
}
.cal-cell:hover .ev-block { filter: brightness(.93); transform: scale(.97); }

/* Location en cours — bleu plein */
.ev-location {
    background: linear-gradient(135deg, #0d9488 0%, #14b8a6 100%);
    color: #fff;
    box-shadow: 0 1px 4px rgba(26,86,219,.35);
}
/* Location en retard — rouge */
.ev-retard {
    background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
    color: #fff;
    box-shadow: 0 1px 4px rgba(220,38,38,.35);
    animation: pulse-red 1.8s ease-in-out infinite;
}
@keyframes pulse-red {
    0%, 100% { box-shadow: 0 1px 4px rgba(220,38,38,.35); }
    50%       { box-shadow: 0 2px 10px rgba(220,38,38,.6); }
}
/* Réservation confirmée — vert */
.ev-confirmee {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    color: #fff;
    box-shadow: 0 1px 4px rgba(5,150,105,.35);
}
/* Réservation en attente — orange rayé */
.ev-attente {
    background: repeating-linear-gradient(
        45deg,
        #f97316,
        #f97316 5px,
        #fb923c 5px,
        #fb923c 10px
    );
    color: #fff;
    box-shadow: 0 1px 4px rgba(249,115,22,.35);
    animation: shimmer 2.5s linear infinite;
}
@keyframes shimmer {
    0%   { background-position: 0 0; }
    100% { background-position: 20px 20px; }
}

.ev-initiales {
    background: rgba(0,0,0,.15);
    border-radius: 3px;
    padding: 1px 4px;
    font-size: 9px;
    line-height: 1.3;
    text-align: center;
}

/* ══ Légende ══ */
.leg { display: flex; align-items: center; gap: 5px; font-size: .78rem; color: #475569; }
.leg-box { width: 14px; height: 14px; border-radius: 3px; flex-shrink: 0; }

/* ══ Popup ══ */
.pop-overlay {
    position: fixed; inset: 0;
    background: rgba(15,23,42,.5);
    backdrop-filter: blur(3px);
    z-index: 600;
    display: none; align-items: center; justify-content: center;
}
.pop-overlay.open { display: flex; }
.pop-card {
    background: #fff; border-radius: 14px;
    width: 320px; max-width: 95vw;
    box-shadow: 0 30px 80px rgba(0,0,0,.3);
    overflow: hidden;
    animation: pop-in .15s ease;
}
@keyframes pop-in {
    from { transform: scale(.92); opacity: 0; }
    to   { transform: scale(1);   opacity: 1; }
}
.pop-head {
    padding: 14px 16px;
    display: flex; align-items: flex-start; justify-content: space-between;
    border-bottom: 1px solid #f1f5f9;
}
.pop-close { background: none; border: none; font-size: 1.3rem; cursor: pointer; color: #94a3b8; line-height: 1; padding: 0; }
.pop-body  { padding: 12px 14px; }
.pop-action {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border: 1.5px solid #e2e8f0; border-radius: 10px;
    margin-bottom: 8px; cursor: pointer; text-decoration: none;
    color: #0f172a; font-size: .875rem; background: #f8fafc;
    transition: all .15s;
}
.pop-action:last-child { margin-bottom: 0; }
.pop-action:hover { background: #eff6ff; border-color: #93c5fd; color: #0d9488; }
.pop-icon {
    width: 36px; height: 36px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; flex-shrink: 0;
}
.pop-hint {
    font-size: .72rem; color: #64748b; margin-top: 2px;
}
.pop-occupied-badge {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 10px; border-radius: 8px;
    margin-bottom: 10px; font-size: .8rem; font-weight: 600;
}
</style>

<div class="page-header" style="padding-bottom:.5rem">
    <div>
        <h1 class="page-title"><i class="fas fa-calendar-check"></i> Calendrier</h1>
        <p class="page-subtitle">Disponibilités & occupations — cliquez sur une case pour agir</p>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>app/locations/nouvelle.php" class="btn btn-primary btn-sm">
            <i class="fas fa-key"></i> Nouvelle location
        </a>
        <a href="<?= BASE_URL ?>app/reservations/nouvelle.php" class="btn btn-secondary btn-sm">
            <i class="fas fa-calendar-plus"></i> Réserver
        </a>
    </div>
</div>

<?= renderFlashes() ?>

<!-- Stats -->
<div class="stats-grid" style="margin-bottom:12px">
    <div class="stat-card primary">
        <div class="stat-icon"><i class="fas fa-car"></i></div>
        <div class="stat-info">
            <div class="stat-value"><?= (int)($stats['loc_actives'] ?? 0) ?></div>
            <div class="stat-label">Locations actives</div>
        </div>
    </div>
    <div class="stat-card warning">
        <div class="stat-icon"><i class="fas fa-clock"></i></div>
        <div class="stat-info">
            <div class="stat-value"><?= (int)($statsRsv['en_attente'] ?? 0) ?></div>
            <div class="stat-label">En attente</div>
        </div>
    </div>
    <div class="stat-card success">
        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
        <div class="stat-info">
            <div class="stat-value"><?= (int)($statsRsv['confirmees'] ?? 0) ?></div>
            <div class="stat-label">Confirmées</div>
        </div>
    </div>
    <div class="stat-card info">
        <div class="stat-icon"><i class="fas fa-car-side"></i></div>
        <div class="stat-info">
            <div class="stat-value"><?= count($vehicules) ?></div>
            <div class="stat-label">Véhicules</div>
        </div>
    </div>
</div>

<!-- Nav mois + légende -->
<div class="card" style="margin-bottom:12px">
    <div class="card-body" style="padding:10px 14px">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <a href="?mois=<?= urlencode($moisPrev) ?>" class="btn btn-ghost btn-sm"><i class="fas fa-chevron-left"></i></a>
            <strong style="font-size:1rem;color:#0f172a;min-width:130px;text-align:center"><?= htmlspecialchars($moisLabel) ?></strong>
            <a href="?mois=<?= urlencode($moisNext) ?>" class="btn btn-ghost btn-sm"><i class="fas fa-chevron-right"></i></a>
            <?php if ($mois !== date('Y-m')): ?>
            <a href="?mois=<?= urlencode(date('Y-m')) ?>" class="btn btn-sm btn-secondary" style="font-size:.8rem">
                <i class="fas fa-dot-circle"></i> Aujourd'hui
            </a>
            <?php endif ?>
            <div style="display:flex;gap:10px;margin-left:auto;flex-wrap:wrap;align-items:center">
                <div class="leg"><div class="leg-box" style="background:linear-gradient(135deg,#0d9488,#14b8a6)"></div> Location</div>
                <div class="leg"><div class="leg-box" style="background:linear-gradient(135deg,#059669,#10b981)"></div> Confirmée</div>
                <div class="leg"><div class="leg-box" style="background:#f97316"></div> En attente</div>
                <div class="leg"><div class="leg-box" style="background:linear-gradient(135deg,#dc2626,#ef4444)"></div> Retard</div>
                <div class="leg"><div class="leg-box" style="background:#bbf7d0;border:1px solid #d1fae5"></div> Libre</div>
            </div>
        </div>
    </div>
</div>

<!-- Grille calendrier -->
<div class="card" style="margin-bottom:14px">
    <?php if (empty($vehicules)): ?>
    <div style="padding:2rem;text-align:center;color:#94a3b8">
        <i class="fas fa-car" style="font-size:2rem;display:block;margin-bottom:8px"></i>
        Aucun véhicule de type "Location"
    </div>
    <?php else: ?>
    <div class="cal-scroll">
        <table class="cal-table">
            <!-- En-tête -->
            <thead>
                <tr>
                    <th class="col-veh th-veh">Véhicules</th>
                    <?php foreach ($jours as $j => $meta):
                        $isToday = ($j === $todayDay && $isMoisCourant);
                        $isWE    = in_array($meta['dow'], [0, 6]);
                        $cls     = 'th-day';
                        if ($isToday) $cls .= ' today';
                        elseif ($isWE) $cls .= ' weekend';
                    ?>
                    <th class="<?= $cls ?>" id="<?= $isToday ? 'th-today' : '' ?>">
                        <div class="th-day-num"><?= $j ?></div>
                        <div class="th-day-abbr"><?= $meta['abbr'] ?></div>
                    </th>
                    <?php endforeach ?>
                </tr>
            </thead>
            <!-- Corps -->
            <tbody>
            <?php foreach ($vehicules as $veh): ?>
            <tr>
                <td class="col-veh td-veh">
                    <div class="veh-nom" title="<?= sanitize($veh['nom']) ?>"><?= sanitize($veh['nom']) ?></div>
                    <div class="veh-immat"><?= sanitize($veh['immatriculation']) ?></div>
                    <div class="veh-prix"><?= formatMoney((float)$veh['prix_location_jour']) ?>/j</div>
                </td>
                <?php foreach ($jours as $j => $meta):
                    $ev      = $calData[$veh['id']][$j] ?? null;
                    $isToday = ($j === $todayDay && $isMoisCourant);
                    $isWE    = in_array($meta['dow'], [0, 6]);

                    // Classes cellule
                    $cls = 'cal-cell';
                    $cls .= $ev ? ' occupied' : ' libre';
                    if ($isToday) $cls .= ' today-col';
                    if ($isWE && !$ev) $cls .= ' weekend';

                    // Infos event
                    if ($ev) {
                        $enRetard = $ev['ev_type'] === 'location' && $ev['date_fin'] < $todayYmd;
                        if ($enRetard)                              $evCls = 'ev-retard';
                        elseif ($ev['ev_type'] === 'location')      $evCls = 'ev-location';
                        elseif ($ev['statut'] === 'confirmee')      $evCls = 'ev-confirmee';
                        else                                        $evCls = 'ev-attente';

                        $initiales = strtoupper(mb_substr($ev['client_nom'], 0, 1))
                                   . strtoupper(mb_substr($ev['client_prenom'] ?? '', 0, 1));
                        $tooltip = sanitize($ev['client_nom'] . ' ' . ($ev['client_prenom'] ?? ''))
                                 . ' · ' . formatDate($ev['date_debut']) . '→' . formatDate($ev['date_fin']);
                    }
                ?>
                <td class="<?= $cls ?>"
                    onclick="cellClick(
                        <?= $veh['id'] ?>,
                        '<?= addslashes(sanitize($veh['nom'])) ?>',
                        '<?= addslashes(sanitize($veh['immatriculation'])) ?>',
                        '<?= $meta['ymd'] ?>',
                        <?= $ev ? "'" . $ev['ev_type'] . "'" : 'null' ?>,
                        <?= $ev ? $ev['id'] : 'null' ?>,
                        <?= $isToday ? 'true' : 'false' ?>,
                        <?= (float)$veh['prix_location_jour'] ?>
                    )"
                    title="<?= $ev ? htmlspecialchars($tooltip) : sanitize($veh['immatriculation']) . ' — disponible' ?>">
                    <?php if ($ev): ?>
                    <div class="ev-block <?= $evCls ?>">
                        <span class="ev-initiales"><?= htmlspecialchars($initiales) ?></span>
                    </div>
                    <?php endif ?>
                </td>
                <?php endforeach ?>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php endif ?>
</div>

<!-- Liste réservations -->
<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <h3 class="card-title"><i class="fas fa-list"></i> Réservations à venir</h3>
        <span class="badge bg-primary"><?= count($reservations) ?></span>
    </div>
    <?php if (empty($reservations)): ?>
    <div style="padding:2rem;text-align:center;color:#94a3b8;font-size:.875rem">
        <i class="fas fa-calendar" style="font-size:1.5rem;display:block;margin-bottom:6px"></i>
        Aucune réservation active
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table" style="font-size:.85rem">
            <thead>
                <tr>
                    <th>Véhicule</th><th>Client</th><th>Période</th>
                    <th>Jours</th><th>Montant</th><th>Avance</th><th>Statut</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($reservations as $r):
                $stPay = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM paiements WHERE reservation_id=? AND tenant_id=?");
                $stPay->execute([$r['id'], $tenantId]);
                $totalPaye = (float)$stPay->fetchColumn();
                $reste     = max(0, (float)$r['montant_final'] - $totalPaye);
                $jRestants = (int)ceil((strtotime($r['date_debut']) - time()) / 86400);
            ?>
            <tr>
                <td>
                    <strong><?= sanitize($r['immatriculation']) ?></strong><br>
                    <span style="font-size:.78rem;color:#64748b"><?= sanitize($r['veh_nom']) ?></span>
                </td>
                <td>
                    <?= sanitize($r['client_nom'] . ' ' . ($r['client_prenom'] ?? '')) ?>
                    <?php if ($r['telephone']): ?>
                    <br><span style="font-size:.78rem;color:#64748b"><?= sanitize($r['telephone']) ?></span>
                    <?php endif ?>
                </td>
                <td style="white-space:nowrap">
                    <?= formatDate($r['date_debut']) ?> → <?= formatDate($r['date_fin']) ?>
                    <br>
                    <?php if ($jRestants > 0): ?>
                    <span style="font-size:.75rem;color:#0d9488">dans <?= $jRestants ?> j</span>
                    <?php elseif ($jRestants === 0): ?>
                    <span style="font-size:.75rem;color:#dc2626;font-weight:700">Aujourd'hui !</span>
                    <?php else: ?>
                    <span style="font-size:.75rem;color:#f59e0b"><?= abs($jRestants) ?> j passés</span>
                    <?php endif ?>
                </td>
                <td style="text-align:center"><?= (int)$r['nombre_jours'] ?></td>
                <td><strong><?= formatMoney((float)$r['montant_final']) ?></strong></td>
                <td>
                    <?php if ($totalPaye > 0): ?>
                    <span style="color:#16a34a;font-weight:600"><?= formatMoney($totalPaye) ?></span>
                    <?php if ($reste > 0): ?><br><span style="font-size:.75rem;color:#dc2626">reste <?= formatMoney($reste) ?></span><?php endif ?>
                    <?php else: ?><span style="color:#94a3b8">—</span><?php endif ?>
                </td>
                <td>
                    <?= match($r['statut']) {
                        'en_attente' => '<span class="badge bg-warning">En attente</span>',
                        'confirmee'  => '<span class="badge bg-info">Confirmée</span>',
                        default      => '<span class="badge bg-secondary">' . sanitize($r['statut']) . '</span>',
                    } ?>
                </td>
                <td>
                    <a href="<?= BASE_URL ?>app/reservations/detail.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-ghost" title="Détail">
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

<!-- ══ MODAL POPUP ══════════════════════════════════════════════════════════ -->
<div id="pop-overlay" class="pop-overlay" onclick="if(event.target===this)closePop()">
    <div class="pop-card">
        <div class="pop-head">
            <div>
                <div style="font-weight:700;font-size:.95rem" id="pop-title">—</div>
                <div style="font-size:.78rem;color:#64748b;margin-top:1px" id="pop-sub"></div>
            </div>
            <button class="pop-close" onclick="closePop()">&times;</button>
        </div>
        <div class="pop-body" id="pop-body"></div>
    </div>
</div>

<script>
const BASE = '<?= BASE_URL ?>';
const moisFr = ['','Jan','Fév','Mar','Avr','Mai','Juin','Juil','Aoû','Sep','Oct','Nov','Déc'];

function fmtDate(ymd) {
    const p = ymd.split('-');
    return parseInt(p[2]) + ' ' + moisFr[parseInt(p[1])] + ' ' + p[0];
}
function fmtMoney(n) {
    return Math.round(n).toLocaleString('fr-FR') + ' FCFA';
}

// ── Auto-scroll vers aujourd'hui ──────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    const th = document.getElementById('th-today');
    const wr = document.querySelector('.cal-scroll');
    if (th && wr) {
        wr.scrollLeft = Math.max(0, th.offsetLeft - 150);
    }
});

// ── Popup ─────────────────────────────────────────────────────────────────
function cellClick(vehId, vehNom, immat, dateYmd, evType, evId, isToday, prixJour) {
    const title = document.getElementById('pop-title');
    const sub   = document.getElementById('pop-sub');
    const body  = document.getElementById('pop-body');

    title.textContent = immat;
    sub.textContent   = fmtDate(dateYmd) + (isToday ? ' — Aujourd\'hui' : '');

    if (evType !== null) {
        /* ── OCCUPÉ → détail seulement, pas de réservation possible ── */
        const url    = evType === 'location'
                     ? BASE + 'app/locations/detail.php?id=' + evId
                     : BASE + 'app/reservations/detail.php?id=' + evId;
        const label  = evType === 'location' ? 'Location #' + evId : 'Réservation #' + evId;
        const color  = evType === 'location' ? '#0d9488' : '#059669';
        const bgIcon = evType === 'location' ? '#dbeafe' : '#d1fae5';
        const icon   = evType === 'location' ? 'fa-key' : 'fa-calendar-check';
        const badgeBg= evType === 'location' ? 'background:#dbeafe;color:#1e40af' : 'background:#d1fae5;color:#065f46';

        body.innerHTML = `
            <div class="pop-occupied-badge" style="${badgeBg}">
                <i class="fas fa-lock" style="font-size:.9rem"></i>
                Ce véhicule est <strong>occupé</strong> — ${label}
            </div>
            <a class="pop-action" href="${url}">
                <div class="pop-icon" style="background:${bgIcon};color:${color}">
                    <i class="fas ${icon}"></i>
                </div>
                <div>
                    <div style="font-weight:700">Voir le détail</div>
                    <div class="pop-hint">${label} — ${fmtDate(dateYmd)}</div>
                </div>
            </a>`;

    } else if (isToday) {
        /* ── AUJOURD'HUI libre → location directe seulement ── */
        body.innerHTML = `
            <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:8px;padding:8px 10px;margin-bottom:10px;font-size:.8rem;color:#1e40af;font-weight:600">
                <i class="fas fa-circle" style="font-size:.5rem;vertical-align:middle"></i>
                Disponible — démarrage immédiat possible
            </div>
            <a class="pop-action" href="${BASE}app/locations/nouvelle.php?vehicule_id=${vehId}&date_debut=${dateYmd}">
                <div class="pop-icon" style="background:#dbeafe;color:#0d9488">
                    <i class="fas fa-key"></i>
                </div>
                <div>
                    <div style="font-weight:700">Location directe</div>
                    <div class="pop-hint">Démarre aujourd'hui · ${fmtMoney(prixJour)}/jour</div>
                </div>
            </a>
            <div style="text-align:center;margin-top:8px;padding-top:8px;border-top:1px solid #f1f5f9;font-size:.75rem;color:#94a3b8">
                <i class="fas fa-info-circle"></i> Les réservations sont pour des dates futures uniquement
            </div>`;

    } else {
        /* ── Date future libre → location OU réservation ── */
        body.innerHTML = `
            <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:8px;padding:8px 10px;margin-bottom:10px;font-size:.8rem;color:#166534;font-weight:600">
                <i class="fas fa-check-circle"></i> Véhicule disponible ce jour
            </div>
            <a class="pop-action" href="${BASE}app/locations/nouvelle.php?vehicule_id=${vehId}&date_debut=${dateYmd}">
                <div class="pop-icon" style="background:#dbeafe;color:#0d9488">
                    <i class="fas fa-key"></i>
                </div>
                <div>
                    <div style="font-weight:700">Location directe</div>
                    <div class="pop-hint">Démarre le ${fmtDate(dateYmd)} · ${fmtMoney(prixJour)}/j</div>
                </div>
            </a>
            <a class="pop-action" href="${BASE}app/reservations/nouvelle.php?vehicule_id=${vehId}&date_debut=${dateYmd}">
                <div class="pop-icon" style="background:#ede9fe;color:#7c3aed">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div>
                    <div style="font-weight:700">Réservation</div>
                    <div class="pop-hint">Bloquer la date, confirmer plus tard</div>
                </div>
            </a>`;
    }

    document.getElementById('pop-overlay').classList.add('open');
}

function closePop() {
    document.getElementById('pop-overlay').classList.remove('open');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closePop(); });
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
