<?php
/**
 * FlotteCar — Liste unifiée : Chauffeurs + Taximantres VTC
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
$today    = date('Y-m-d');
$in30     = date('Y-m-d', strtotime('+30 days'));

// ── Filtres ────────────────────────────────────────────────────────────────────
$q      = trim($_GET['q']    ?? '');
$type   = $_GET['type']      ?? '';   // '' | 'vtc' | 'location' | 'entreprise'
$sFilter= $_GET['statut']    ?? '';   // '' | 'actif' | 'suspendu' | 'inactif'
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = ITEMS_PER_PAGE;

// ═══════════════════════════════════════════════════════════════════════════════
// 1. VTC — depuis taximetres (source principale pour les VTC)
// ═══════════════════════════════════════════════════════════════════════════════
$vtcWhere  = "WHERE t.tenant_id = ?";
$vtcParams = [$tenantId];
if ($q) {
    $vtcWhere .= " AND (t.nom LIKE ? OR t.prenom LIKE ? OR t.telephone LIKE ? OR v.immatriculation LIKE ?)";
    $vtcParams[] = "%$q%"; $vtcParams[] = "%$q%"; $vtcParams[] = "%$q%"; $vtcParams[] = "%$q%";
}
if ($sFilter === 'actif')    { $vtcWhere .= " AND t.statut = 'actif'"; }
elseif ($sFilter === 'suspendu') { $vtcWhere .= " AND t.statut = 'suspendu'"; }
elseif ($sFilter === 'inactif') { $vtcWhere .= " AND t.statut = 'suspendu'"; } // alias

$vtcStmt = $db->prepare("
    SELECT
        t.id, t.nom, t.prenom, t.telephone, t.tarif_journalier,
        t.date_debut, t.statut, t.caution_versee,
        v.id              AS vehicule_id,
        v.nom             AS veh_nom,
        v.immatriculation,
        v.kilometrage_actuel,
        GREATEST(0, DATEDIFF(CURDATE(), t.date_debut) + 1)                           AS periode_jours,
        COALESCE(SUM(pt.statut_jour IN ('jour_off','panne','accident','maladie')), 0) AS jours_off,
        COALESCE(SUM(CASE WHEN pt.statut_jour='paye' THEN pt.montant ELSE 0 END), 0) AS total_percu,
        (SELECT p2.statut_jour FROM paiements_taxi p2
            WHERE p2.taximetre_id=t.id AND p2.date_paiement=CURDATE() LIMIT 1)       AS statut_auj,
        (SELECT MAX(p2.date_paiement) FROM paiements_taxi p2
            WHERE p2.taximetre_id=t.id AND p2.statut_jour='paye')                    AS dernier_paiement
    FROM taximetres t
    JOIN vehicules v ON v.id = t.vehicule_id AND v.tenant_id = t.tenant_id
    LEFT JOIN paiements_taxi pt ON pt.taximetre_id = t.id AND pt.tenant_id = t.tenant_id
    $vtcWhere
    GROUP BY t.id
    ORDER BY t.statut ASC, t.nom ASC
");
$vtcStmt->execute($vtcParams);
$vtcAll = $vtcStmt->fetchAll(PDO::FETCH_ASSOC);

// Calcul dette
foreach ($vtcAll as &$vtc) {
    $f = max(0, (int)$vtc['periode_jours'] - (int)$vtc['jours_off']);
    $vtc['dette']             = max(0, $f * (float)$vtc['tarif_journalier'] - (float)$vtc['total_percu']);
    $vtc['jours_facturables'] = $f;
}
unset($vtc);

// Tri : actifs en premier, puis par dette décroissante
usort($vtcAll, function($a, $b) {
    if ($a['statut'] !== $b['statut']) return $a['statut'] === 'actif' ? -1 : 1;
    return $b['dette'] <=> $a['dette'];
});

// ═══════════════════════════════════════════════════════════════════════════════
// 2. Chauffeurs location / entreprise (table chauffeurs)
// ═══════════════════════════════════════════════════════════════════════════════
$chWhere  = "WHERE ch.tenant_id = ? AND ch.type_chauffeur != 'taxi'";
$chParams = [$tenantId];
if ($q) {
    $chWhere .= " AND (ch.nom LIKE ? OR ch.prenom LIKE ? OR ch.telephone LIKE ?)";
    $chParams[] = "%$q%"; $chParams[] = "%$q%"; $chParams[] = "%$q%";
}
if ($sFilter === 'actif')   { $chWhere .= " AND ch.statut = 'actif'"; }
elseif ($sFilter === 'inactif' || $sFilter === 'suspendu') { $chWhere .= " AND ch.statut = 'inactif'"; }
if ($type && $type !== 'vtc') { $chWhere .= " AND ch.type_chauffeur = ?"; $chParams[] = $type; }

$chStmt = $db->prepare("
    SELECT
        ch.id, ch.nom, ch.prenom, ch.telephone, ch.email,
        ch.type_chauffeur, ch.statut, ch.vehicule_id,
        ch.numero_permis, ch.date_naissance, ch.date_permis,
        v.nom             AS veh_nom,
        v.immatriculation AS veh_immat,
        v.statut          AS veh_statut,
        v.kilometrage_actuel,
        v.prochaine_vidange_km,
        v.date_expiration_assurance,
        v.date_expiration_vignette,
        COALESCE((SELECT COUNT(*) FROM locations l WHERE l.chauffeur_id=ch.id AND l.tenant_id=ch.tenant_id), 0) AS nb_missions,
        COALESCE((SELECT COUNT(*) FROM locations l WHERE l.chauffeur_id=ch.id AND l.tenant_id=ch.tenant_id AND l.statut='en_cours'), 0) AS missions_en_cours
    FROM chauffeurs ch
    LEFT JOIN vehicules v ON v.id = ch.vehicule_id AND v.tenant_id = ch.tenant_id
    $chWhere
    ORDER BY CASE WHEN ch.statut='actif' THEN 0 ELSE 1 END, ch.type_chauffeur ASC, ch.nom ASC
");
$chStmt->execute($chParams);
$chAll = $chStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Compteurs pour tabs ────────────────────────────────────────────────────────
$nbVtcActifs    = count(array_filter($vtcAll, fn($v) => $v['statut'] === 'actif'));
$nbVtcAll       = count($vtcAll);
$nbLocAll       = count(array_filter($chAll, fn($c) => $c['type_chauffeur'] === 'location'));
$nbEntAll       = count(array_filter($chAll, fn($c) => $c['type_chauffeur'] === 'entreprise'));
$totalDette     = array_sum(array_column(array_filter($vtcAll, fn($v) => $v['statut'] === 'actif'), 'dette'));
$totalRecus     = array_sum(array_column(array_filter($vtcAll, fn($v) => $v['statut'] === 'actif'), 'total_percu'));

// Selon le filtre, préparer l'affichage
$showVtc  = ($type === '' || $type === 'vtc');
$showCh   = ($type === '' || $type === 'location' || $type === 'entreprise');
if ($type === 'vtc') $showCh = false;
if ($type === 'location' || $type === 'entreprise') $showVtc = false;

$pageTitle  = 'Chauffeurs & VTC';
$activePage = 'chauffeurs';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
/* ── Layout ── */
.ch-section-title { font-size:.72rem; text-transform:uppercase; letter-spacing:.08em; font-weight:700; padding:8px 14px; border-bottom:2px solid; margin:0; display:flex; align-items:center; gap:8px; }
/* ── VTC rows ── */
.vtc-row { cursor:pointer; transition:background .1s; }
.vtc-row:hover td { background:#fffbeb; }
.vtc-row.has-debt:hover td { background:#fff5f5; }
.vtc-row td { vertical-align:middle; padding:10px 12px; border-bottom:1px solid #f1f5f9; font-size:.82rem; }
.vtc-head th { padding:8px 12px; font-size:.65rem; text-transform:uppercase; letter-spacing:.07em; color:#92400e; background:#fffbeb; border-bottom:2px solid #fde68a; white-space:nowrap; }
/* ── Chauffeur rows ── */
.ch-row { cursor:default; }
.ch-row:hover td { background:#f8fafc; }
.ch-row td { vertical-align:middle; padding:9px 12px; border-bottom:1px solid #f1f5f9; font-size:.82rem; }
.ch-head th { padding:8px 12px; font-size:.65rem; text-transform:uppercase; letter-spacing:.07em; color:#94a3b8; background:#f8fafc; border-bottom:2px solid #e2e8f0; white-space:nowrap; }
/* ── Chips ── */
.av { width:34px;height:34px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;flex-shrink:0; }
.debt-chip { display:inline-flex;align-items:center;gap:3px;padding:3px 8px;background:#fee2e2;color:#dc2626;border-radius:6px;font-size:.76rem;font-weight:700; }
.ok-chip { display:inline-flex;align-items:center;gap:3px;padding:3px 8px;background:#d1fae5;color:#059669;border-radius:6px;font-size:.76rem;font-weight:700; }
.day-chip { display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:4px;font-size:.68rem;font-weight:600; }
.veh-pill { display:inline-flex;align-items:center;gap:4px;padding:2px 8px;background:#f1f5f9;border-radius:4px;font-size:.73rem;color:#475569;text-decoration:none; }
.veh-pill:hover { background:#e2e8f0; }
.ialert { display:inline-flex;align-items:center;gap:3px;padding:2px 6px;border-radius:4px;font-size:.66rem;font-weight:700; }
/* ── KPI ── */
.kpi5 { display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px; }
.kpi-c { background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:11px 14px; }
.kpi-c .kv { font-size:1.05rem;font-weight:700;margin-top:2px; }
.kpi-c .kl { font-size:.61rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em; }

@media(max-width:768px) {
    .kpi5 { grid-template-columns:repeat(2,1fr) !important; }
    .ch-table-wrap { display:none !important; }
    .ch-mobile-cards { display:flex !important; }
    .kpi5 .kpi-c:last-child { grid-column:span 2; }
}
.ch-table-wrap { display:block; }
.ch-mobile-cards { display:none; flex-direction:column; gap:10px; }
</style>

<div class="page-header" style="margin-bottom:13px">
    <div>
        <h1 class="page-title"><i class="fas fa-id-card"></i> Chauffeurs & Taximantres</h1>
        <p class="page-subtitle" style="margin:0"><?= $nbVtcActifs ?> VTC actifs · <?= count($chAll) ?> chauffeurs (location/entreprise)</p>
    </div>
    <div style="display:flex;gap:8px">
        <a href="<?= BASE_URL ?>app/taximetres/liste.php" class="btn btn-secondary btn-sm">
            <i class="fas fa-taxi"></i> Gérer VTC
        </a>
        <a href="<?= BASE_URL ?>app/chauffeurs/ajouter.php" class="btn btn-primary btn-sm">
            <i class="fas fa-plus"></i> Nouveau chauffeur
        </a>
    </div>
</div>

<?= renderFlashes() ?>

<!-- KPIs -->
<div class="kpi5">
    <div class="kpi-c" style="border-top:3px solid #d97706">
        <div class="kl"><i class="fas fa-taxi"></i> VTC actifs</div>
        <div class="kv" style="color:#d97706"><?= $nbVtcActifs ?></div>
        <div style="font-size:.68rem;color:#94a3b8"><?= count($vtcAll) ?> enregistrés</div>
    </div>
    <div class="kpi-c" style="border-top:3px solid <?= $totalDette > 0 ? '#ef4444' : '#10b981' ?>">
        <div class="kl"><i class="fas fa-exclamation-circle"></i> Dette VTC totale</div>
        <div class="kv" style="color:<?= $totalDette > 0 ? '#ef4444' : '#10b981' ?>"><?= $totalDette > 0 ? formatMoney($totalDette) : '✓ À jour' ?></div>
        <div style="font-size:.68rem;color:#94a3b8">cumul impayés</div>
    </div>
    <div class="kpi-c" style="border-top:3px solid #059669">
        <div class="kl"><i class="fas fa-coins"></i> Total encaissé VTC</div>
        <div class="kv" style="color:#059669"><?= formatMoney($totalRecus) ?></div>
        <div style="font-size:.68rem;color:#94a3b8">depuis début</div>
    </div>
    <div class="kpi-c" style="border-top:3px solid #0d9488">
        <div class="kl"><i class="fas fa-key"></i> Location</div>
        <div class="kv" style="color:#0d9488"><?= $nbLocAll ?></div>
        <div style="font-size:.68rem;color:#94a3b8">chauffeurs</div>
    </div>
    <div class="kpi-c" style="border-top:3px solid #059669">
        <div class="kl"><i class="fas fa-building"></i> Entreprise</div>
        <div class="kv" style="color:#059669"><?= $nbEntAll ?></div>
        <div style="font-size:.68rem;color:#94a3b8">chauffeurs internes</div>
    </div>
</div>

<!-- Filtres -->
<div class="card" style="margin-bottom:12px">
    <div style="padding:10px 14px;border-bottom:1px solid #e2e8f0">
        <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <?php if ($type): ?><input type="hidden" name="type" value="<?= sanitize($type) ?>"><?php endif ?>
            <input type="text" name="q" class="form-control" placeholder="Nom, téléphone, immatriculation…"
                   value="<?= sanitize($q) ?>" style="width:240px;height:32px;font-size:.8rem">
            <select name="statut" class="form-control" style="width:130px;height:32px;font-size:.8rem">
                <option value="">Tous statuts</option>
                <option value="actif"    <?= $sFilter === 'actif'    ? 'selected' : '' ?>>Actif</option>
                <option value="inactif"  <?= $sFilter === 'inactif'  ? 'selected' : '' ?>>Inactif / Suspendu</option>
            </select>
            <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i> Chercher</button>
            <?php if ($q || $sFilter): ?>
            <a href="<?= BASE_URL ?>app/chauffeurs/liste.php<?= $type ? '?type='.$type : '' ?>" class="btn btn-ghost btn-sm">
                <i class="fas fa-times"></i> Effacer
            </a>
            <?php endif ?>
        </form>
    </div>
    <!-- Tabs type -->
    <div style="padding:8px 14px;display:flex;gap:6px;flex-wrap:wrap">
        <?php
        $tabs = [
            ''           => ['Tous',          $nbVtcAll + count($chAll), '#64748b'],
            'vtc'        => ['🚕 VTC / Taxi',  $nbVtcAll,                '#d97706'],
            'location'   => ['🔑 Location',    $nbLocAll,                '#0d9488'],
            'entreprise' => ['🏢 Entreprise',  $nbEntAll,                '#059669'],
        ];
        foreach ($tabs as $val => [$lbl, $cnt, $c]):
            $isActive = ($type === $val);
            $url = '?' . http_build_query(array_filter(['q' => $q, 'statut' => $sFilter, 'type' => $val, 'page' => 1], 'strlen'));
        ?>
        <a href="<?= $url ?>"
           style="padding:5px 14px;border-radius:99px;font-size:.78rem;font-weight:600;text-decoration:none;transition:.15s;
                  background:<?= $isActive ? $c : '#f1f5f9' ?>;color:<?= $isActive ? '#fff' : '#64748b' ?>">
            <?= $lbl ?> <span style="opacity:.75;font-size:.7rem">(<?= $cnt ?>)</span>
        </a>
        <?php endforeach ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- SECTION VTC                                                               -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<?php if ($showVtc): ?>
<div class="card ch-table-wrap" style="margin-bottom:14px;border-top:3px solid #d97706;overflow:hidden">
    <div class="ch-section-title" style="color:#92400e;border-color:#fde68a;background:#fffbeb">
        <i class="fas fa-taxi" style="color:#d97706"></i>
        Taximantres VTC
        <span style="background:#d97706;color:#fff;padding:1px 8px;border-radius:99px;font-size:.65rem"><?= count($vtcAll) ?></span>
        <?php if ($totalDette > 0): ?>
        <span style="background:#fee2e2;color:#dc2626;padding:2px 10px;border-radius:99px;font-size:.65rem;font-weight:700;margin-left:4px">
            <i class="fas fa-exclamation-circle"></i> Dette totale : <?= formatMoney($totalDette) ?>
        </span>
        <?php endif ?>
        <a href="<?= BASE_URL ?>app/taximetres/paiements.php" class="btn btn-warning btn-sm" style="margin-left:auto;font-size:.72rem">
            <i class="fas fa-coins"></i> Saisir paiements
        </a>
    </div>

    <?php if (empty($vtcAll)): ?>
    <div style="text-align:center;padding:2rem;color:#94a3b8;font-size:.85rem">
        <i class="fas fa-taxi" style="font-size:1.8rem;display:block;margin-bottom:8px;color:#fde68a"></i>
        Aucun taximantre enregistré.
        <br><a href="<?= BASE_URL ?>app/taximetres/liste.php" class="btn btn-secondary btn-sm" style="margin-top:8px">Associer un taximantre</a>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table" style="margin:0">
            <thead class="vtc-head">
                <tr>
                    <th>Taximantre</th>
                    <th>Véhicule</th>
                    <th style="text-align:center">Aujourd'hui</th>
                    <th style="text-align:right">Tarif/j</th>
                    <th style="text-align:right;color:#dc2626">Dette</th>
                    <th style="text-align:right;color:#059669">Total encaissé</th>
                    <th style="text-align:right">Période</th>
                    <th style="text-align:center">Dernier paiement</th>
                    <th style="text-align:center;width:80px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($vtcAll as $vtc):
                $dette   = (float)$vtc['dette'];
                $isActif = $vtc['statut'] === 'actif';
                $init    = mb_strtoupper(mb_substr($vtc['nom'], 0, 1) . mb_substr($vtc['prenom'] ?? '', 0, 1));
                $dpDays  = $vtc['dernier_paiement'] ? (int)ceil((strtotime($today) - strtotime($vtc['dernier_paiement'])) / 86400) : null;
                $aujSt   = $vtc['statut_auj'];
                $aujBadge = match($aujSt) {
                    'paye'    => ['#d1fae5','#059669','fa-check-circle','Payé'],
                    'non_paye'=> ['#fee2e2','#dc2626','fa-times-circle','Non payé'],
                    'jour_off'=> ['#f1f5f9','#64748b','fa-moon','Off'],
                    'panne'   => ['#fef3c7','#d97706','fa-wrench','Panne'],
                    'accident'=> ['#fee2e2','#9f1239','fa-car-crash','Accident'],
                    default   => ['#fef3c7','#92400e','fa-clock','Non saisi'],
                };
            ?>
            <tr class="vtc-row <?= ($dette > 0 && $isActif) ? 'has-debt' : '' ?>"
                onclick="window.location='<?= BASE_URL ?>app/taximetres/detail.php?id=<?= $vtc['id'] ?>'">

                <!-- Nom -->
                <td>
                    <div style="display:flex;align-items:center;gap:9px">
                        <div class="av" style="background:<?= $isActif ? '#fef3c7' : '#f1f5f9' ?>;color:<?= $isActif ? '#d97706' : '#94a3b8' ?>;position:relative">
                            <?= $init ?>
                            <?php if ($dette > 0 && $isActif): ?>
                            <span style="position:absolute;top:-3px;right:-3px;width:9px;height:9px;border-radius:50%;background:#ef4444;border:2px solid #fff"></span>
                            <?php endif ?>
                        </div>
                        <div>
                            <div style="font-weight:700;color:#0f172a"><?= sanitize($vtc['nom'] . ' ' . ($vtc['prenom'] ?? '')) ?></div>
                            <?php if ($vtc['telephone']): ?>
                            <div style="font-size:.7rem;color:#94a3b8">
                                <a href="tel:<?= sanitize($vtc['telephone']) ?>" onclick="event.stopPropagation()" style="color:#94a3b8;text-decoration:none">
                                    <i class="fas fa-phone" style="font-size:.6rem"></i> <?= sanitize($vtc['telephone']) ?>
                                </a>
                            </div>
                            <?php endif ?>
                        </div>
                        <?php if (!$isActif): ?>
                        <span style="background:#f1f5f9;color:#94a3b8;padding:1px 6px;border-radius:99px;font-size:.62rem;font-weight:700">Suspendu</span>
                        <?php endif ?>
                    </div>
                </td>

                <!-- Véhicule -->
                <td>
                    <div style="font-weight:600;font-size:.8rem"><?= sanitize($vtc['veh_nom']) ?></div>
                    <div style="font-size:.7rem;color:#94a3b8"><?= sanitize($vtc['immatriculation']) ?></div>
                </td>

                <!-- Aujourd'hui -->
                <td style="text-align:center" onclick="event.stopPropagation()">
                    <?php [$abg,$afg,$aic,$alb] = $aujBadge; ?>
                    <span class="day-chip" style="background:<?= $abg ?>;color:<?= $afg ?>">
                        <i class="fas <?= $aic ?>"></i> <?= $alb ?>
                    </span>
                    <?php if ($aujSt === null && $isActif): ?>
                    <br><a href="<?= BASE_URL ?>app/taximetres/paiements.php" onclick="event.stopPropagation()"
                           style="font-size:.63rem;color:#d97706;margin-top:1px;display:inline-block">Saisir →</a>
                    <?php endif ?>
                </td>

                <!-- Tarif -->
                <td style="text-align:right;font-weight:700;white-space:nowrap"><?= formatMoney($vtc['tarif_journalier']) ?></td>

                <!-- Dette -->
                <td style="text-align:right">
                    <?php if ($dette > 0): ?>
                    <div class="debt-chip"><i class="fas fa-arrow-down"></i> <?= formatMoney($dette) ?></div>
                    <?php else: ?>
                    <div class="ok-chip"><i class="fas fa-check"></i> À jour</div>
                    <?php endif ?>
                </td>

                <!-- Total encaissé -->
                <td style="text-align:right;font-weight:700;color:#059669;white-space:nowrap"><?= formatMoney($vtc['total_percu']) ?></td>

                <!-- Période -->
                <td style="text-align:right;font-size:.75rem">
                    <div style="font-weight:600"><?= (int)$vtc['jours_facturables'] ?>j fact.</div>
                    <div style="color:#94a3b8;font-size:.68rem"><?= (int)$vtc['periode_jours'] ?>j total · <?= (int)$vtc['jours_off'] ?>j off</div>
                </td>

                <!-- Dernier paiement -->
                <td style="text-align:center;font-size:.75rem">
                    <?php if ($vtc['dernier_paiement']): ?>
                    <div style="font-weight:600"><?= formatDate($vtc['dernier_paiement']) ?></div>
                    <div style="font-size:.68rem;color:<?= $dpDays > 3 ? '#ef4444' : '#94a3b8' ?>">
                        <?= $dpDays === 0 ? 'Auj.' : "Il y a {$dpDays}j" ?>
                    </div>
                    <?php else: ?>
                    <span style="color:#94a3b8;font-size:.72rem">—</span>
                    <?php endif ?>
                </td>

                <!-- Actions -->
                <td style="text-align:center" onclick="event.stopPropagation()">
                    <a href="<?= BASE_URL ?>app/taximetres/detail.php?id=<?= $vtc['id'] ?>"
                       class="btn btn-sm" title="Fiche VTC"
                       style="background:#fef3c7;color:#d97706;border:1px solid #fde68a;width:28px;height:28px;padding:0;display:inline-flex;align-items:center;justify-content:center">
                        <i class="fas fa-taxi" style="font-size:.72rem"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php endif ?>
</div>
<?php endif ?>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- SECTION CHAUFFEURS (location / entreprise)                                -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<?php if ($showCh): ?>
<div class="card ch-table-wrap" style="overflow:hidden">
    <div class="ch-section-title" style="color:#0f172a;border-color:#e2e8f0;background:#f8fafc">
        <i class="fas fa-id-card" style="color:#64748b"></i>
        Chauffeurs (Location / Entreprise)
        <span style="background:#64748b;color:#fff;padding:1px 8px;border-radius:99px;font-size:.65rem"><?= count($chAll) ?></span>
        <a href="<?= BASE_URL ?>app/chauffeurs/ajouter.php" class="btn btn-primary btn-sm" style="margin-left:auto;font-size:.72rem">
            <i class="fas fa-plus"></i> Ajouter
        </a>
    </div>

    <?php if (empty($chAll)): ?>
    <div style="text-align:center;padding:2rem;color:#94a3b8;font-size:.85rem">
        <i class="fas fa-id-card" style="font-size:1.8rem;display:block;margin-bottom:8px"></i>
        Aucun chauffeur trouvé.
        <?php if ($q || $sFilter): ?><br><a href="<?= BASE_URL ?>app/chauffeurs/liste.php" style="font-size:.8rem">Effacer les filtres</a><?php endif ?>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table" style="margin:0">
            <thead class="ch-head">
                <tr>
                    <th>Chauffeur</th>
                    <th>Type</th>
                    <th>Contact</th>
                    <th>Véhicule</th>
                    <th>Permis</th>
                    <th>Activité</th>
                    <th style="text-align:center;width:110px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($chAll as $ch):
                $bgMap = ['location' => '#dbeafe', 'entreprise' => '#d1fae5'];
                $fgMap = ['location' => '#0d9488', 'entreprise' => '#059669'];
                $bg = $bgMap[$ch['type_chauffeur'] ?? ''] ?? '#f1f5f9';
                $fg = $fgMap[$ch['type_chauffeur'] ?? ''] ?? '#94a3b8';

                $alertes = [];
                if ($ch['date_expiration_assurance'] && $ch['date_expiration_assurance'] <= $in30)
                    $alertes[] = ['exp' => $ch['date_expiration_assurance'] < $today, 'label' => 'Assurance'];
                if ($ch['date_expiration_vignette'] && $ch['date_expiration_vignette'] <= $in30)
                    $alertes[] = ['exp' => $ch['date_expiration_vignette'] < $today, 'label' => 'Vignette'];
                if ($ch['prochaine_vidange_km'] && (int)$ch['kilometrage_actuel'] >= (int)$ch['prochaine_vidange_km'] - 500)
                    $alertes[] = ['exp' => false, 'label' => 'Vidange'];
            ?>
            <tr class="ch-row" style="<?= $ch['statut'] === 'inactif' ? 'opacity:.55' : '' ?>">
                <!-- Identité -->
                <td>
                    <div style="display:flex;align-items:center;gap:8px">
                        <div class="av" style="background:<?= $bg ?>;color:<?= $fg ?>">
                            <?= mb_strtoupper(mb_substr($ch['nom'], 0, 1) . mb_substr($ch['prenom'] ?? '', 0, 1)) ?>
                        </div>
                        <div>
                            <a href="<?= BASE_URL ?>app/chauffeurs/modifier.php?id=<?= $ch['id'] ?>"
                               style="font-weight:700;color:#0f172a;text-decoration:none">
                                <?= sanitize($ch['nom'] . ' ' . ($ch['prenom'] ?? '')) ?>
                            </a>
                            <?php if ($ch['date_naissance']): ?>
                            <br><span style="font-size:.68rem;color:#94a3b8"><?= formatDate($ch['date_naissance']) ?></span>
                            <?php endif ?>
                        </div>
                    </div>
                </td>
                <!-- Type -->
                <td>
                    <?php $tmap = ['location' => ['#dbeafe','#0d9488','fa-key','Location'], 'entreprise' => ['#d1fae5','#059669','fa-building','Interne']]; ?>
                    <?php if (isset($tmap[$ch['type_chauffeur']])): [$tbg,$tfg,$tic,$tlb] = $tmap[$ch['type_chauffeur']]; ?>
                    <span style="background:<?= $tbg ?>;color:<?= $tfg ?>;padding:2px 9px;border-radius:99px;font-size:.68rem;font-weight:700;display:inline-flex;align-items:center;gap:4px">
                        <i class="fas <?= $tic ?>"></i> <?= $tlb ?>
                    </span>
                    <?php endif ?>
                    <?php if ($ch['statut'] === 'inactif'): ?>
                    <br><span style="font-size:.65rem;color:#94a3b8">Inactif</span>
                    <?php endif ?>
                </td>
                <!-- Contact -->
                <td style="font-size:.77rem">
                    <?php if ($ch['telephone']): ?>
                    <a href="tel:<?= sanitize($ch['telephone']) ?>" style="color:#0f172a;text-decoration:none">
                        <i class="fas fa-phone" style="color:#94a3b8;font-size:.68rem"></i> <?= sanitize($ch['telephone']) ?>
                    </a>
                    <?php endif ?>
                    <?php if ($ch['email']): ?><br><span style="font-size:.7rem;color:#94a3b8"><?= sanitize($ch['email']) ?></span><?php endif ?>
                    <?php if (!$ch['telephone'] && !$ch['email']): ?><span style="color:#cbd5e1">—</span><?php endif ?>
                </td>
                <!-- Véhicule -->
                <td>
                    <?php if ($ch['veh_nom']): ?>
                    <a href="<?= BASE_URL ?>app/vehicules/detail.php?id=<?= $ch['vehicule_id'] ?>" class="veh-pill">
                        <i class="fas fa-car" style="color:#0d9488;font-size:.68rem"></i>
                        <?= sanitize($ch['veh_nom']) ?> · <?= sanitize($ch['veh_immat']) ?>
                    </a>
                    <?php if (!empty($alertes)): ?>
                    <div style="margin-top:3px;display:flex;flex-wrap:wrap;gap:2px">
                        <?php foreach ($alertes as $al): ?>
                        <span class="ialert" style="background:<?= $al['exp'] ? '#fee2e2' : '#fef3c7' ?>;color:<?= $al['exp'] ? '#dc2626' : '#d97706' ?>">
                            <i class="fas fa-triangle-exclamation"></i> <?= $al['label'] ?>
                        </span>
                        <?php endforeach ?>
                    </div>
                    <?php endif ?>
                    <?php else: ?>
                    <span style="color:#f59e0b;font-size:.75rem"><i class="fas fa-circle-exclamation"></i> Non assigné</span>
                    <?php endif ?>
                </td>
                <!-- Permis -->
                <td style="font-size:.75rem">
                    <?php if ($ch['numero_permis']): ?>
                    <span style="font-family:monospace"><?= sanitize($ch['numero_permis']) ?></span>
                    <?php if ($ch['date_permis']): ?><br><span style="color:#94a3b8;font-size:.68rem"><?= formatDate($ch['date_permis']) ?></span><?php endif ?>
                    <?php else: ?>
                    <span style="color:#fbbf24;font-size:.72rem"><i class="fas fa-triangle-exclamation"></i> Manquant</span>
                    <?php endif ?>
                </td>
                <!-- Activité -->
                <td style="font-size:.76rem">
                    <?php if ((int)$ch['missions_en_cours'] > 0): ?>
                    <span style="background:#dbeafe;color:#0d9488;padding:2px 7px;border-radius:99px;font-size:.7rem;font-weight:700">
                        <i class="fas fa-car"></i> <?= $ch['missions_en_cours'] ?> en cours
                    </span>
                    <?php else: ?>
                    <span style="color:#94a3b8"><?= (int)$ch['nb_missions'] ?> mission<?= $ch['nb_missions'] > 1 ? 's' : '' ?> total</span>
                    <?php endif ?>
                </td>
                <!-- Actions -->
                <td>
                    <div style="display:flex;gap:3px;justify-content:center">
                        <a href="<?= BASE_URL ?>app/chauffeurs/modifier.php?id=<?= $ch['id'] ?>"
                           class="btn btn-sm btn-ghost" title="Modifier"
                           style="width:28px;height:28px;padding:0;display:inline-flex;align-items:center;justify-content:center">
                            <i class="fas fa-pen" style="font-size:.72rem"></i>
                        </a>
                        <?php if ($ch['vehicule_id']): ?>
                        <a href="<?= BASE_URL ?>app/vehicules/detail.php?id=<?= $ch['vehicule_id'] ?>"
                           class="btn btn-sm btn-ghost" title="Véhicule"
                           style="width:28px;height:28px;padding:0;display:inline-flex;align-items:center;justify-content:center">
                            <i class="fas fa-car" style="font-size:.72rem"></i>
                        </a>
                        <?php endif ?>
                        <?php if ((int)$ch['nb_missions'] > 0): ?>
                        <a href="<?= BASE_URL ?>app/locations/liste.php?chauffeur_id=<?= $ch['id'] ?>"
                           class="btn btn-sm btn-ghost" title="Missions"
                           style="width:28px;height:28px;padding:0;display:inline-flex;align-items:center;justify-content:center">
                            <i class="fas fa-list-check" style="font-size:.72rem"></i>
                        </a>
                        <?php endif ?>
                        <form method="POST" action="<?= BASE_URL ?>app/chauffeurs/supprimer.php" style="display:inline"
                              onsubmit="return confirm('Supprimer <?= htmlspecialchars(addslashes($ch['nom']), ENT_QUOTES) ?> ?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= $ch['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-ghost" title="Supprimer"
                                    style="width:28px;height:28px;padding:0;display:inline-flex;align-items:center;justify-content:center;color:#ef4444">
                                <i class="fas fa-trash" style="font-size:.72rem"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php endif ?>
</div>
<?php endif ?>

<!-- MOBILE CARDS -->
<div class="ch-mobile-cards">
    <?php if ($showVtc && !empty($vtcAll)): ?>
    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:10px 12px;display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:.78rem;font-weight:700;color:#92400e"><i class="fas fa-taxi"></i> Taximantres VTC (<?= count($vtcAll) ?>)</span>
        <?php if ($totalDette > 0): ?>
        <span style="font-size:.72rem;color:#dc2626;font-weight:700">Dette: <?= formatMoney($totalDette) ?></span>
        <?php endif ?>
    </div>
    <?php foreach ($vtcAll as $vtc):
        $dette   = (float)$vtc['dette'];
        $isActif = $vtc['statut'] === 'actif';
        $init    = mb_strtoupper(mb_substr($vtc['nom'], 0, 1) . mb_substr($vtc['prenom'] ?? '', 0, 1));
    ?>
    <a href="<?= BASE_URL ?>app/taximetres/detail.php?id=<?= $vtc['id'] ?>"
       style="display:flex;align-items:center;gap:10px;background:#fff;border:1px solid <?= ($dette > 0 && $isActif) ? '#fecaca' : '#e2e8f0' ?>;border-radius:11px;padding:11px 13px;text-decoration:none;color:inherit">
        <div class="av" style="background:<?= $isActif ? '#fef3c7' : '#f1f5f9' ?>;color:<?= $isActif ? '#d97706' : '#94a3b8' ?>;flex-shrink:0">
            <?= $init ?>
        </div>
        <div style="flex:1;min-width:0">
            <div style="font-weight:700;color:#0f172a;font-size:.88rem"><?= sanitize($vtc['nom'].' '.($vtc['prenom']??'')) ?></div>
            <?php if ($vtc['immatriculation']): ?>
            <div style="font-size:.75rem;color:#64748b"><?= sanitize($vtc['immatriculation']) ?></div>
            <?php endif ?>
        </div>
        <div style="text-align:right;flex-shrink:0">
            <?php if ($dette > 0 && $isActif): ?>
            <div style="font-size:.82rem;font-weight:700;color:#dc2626"><?= formatMoney($dette) ?></div>
            <div style="font-size:.68rem;color:#94a3b8">dette</div>
            <?php else: ?>
            <div style="font-size:.82rem;font-weight:700;color:#10b981"><?= formatMoney((float)$vtc['total_percu']) ?></div>
            <div style="font-size:.68rem;color:#94a3b8">encaissé</div>
            <?php endif ?>
        </div>
    </a>
    <?php endforeach ?>
    <?php endif ?>

    <?php if ($showCh && !empty($chAll)): ?>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;margin-top:4px">
        <span style="font-size:.78rem;font-weight:700;color:#475569"><i class="fas fa-id-card"></i> Chauffeurs (<?= count($chAll) ?>)</span>
    </div>
    <?php foreach ($chAll as $ch):
        $init2 = mb_strtoupper(mb_substr($ch['nom'], 0, 1) . mb_substr($ch['prenom'] ?? '', 0, 1));
        $isActif2 = $ch['statut'] === 'actif';
    ?>
    <div style="display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #e2e8f0;border-radius:11px;padding:11px 13px">
        <div class="av" style="background:<?= $isActif2 ? '#eff6ff' : '#f1f5f9' ?>;color:<?= $isActif2 ? '#0d9488' : '#94a3b8' ?>;flex-shrink:0">
            <?= $init2 ?>
        </div>
        <div style="flex:1;min-width:0">
            <div style="font-weight:700;color:#0f172a;font-size:.88rem"><?= sanitize($ch['nom'].(!empty($ch['prenom']) ? ' '.$ch['prenom'] : '')) ?></div>
            <div style="font-size:.75rem;color:#64748b"><?= sanitize($ch['telephone'] ?? '') ?></div>
        </div>
        <div style="display:flex;gap:3px;flex-shrink:0">
            <a href="<?= BASE_URL ?>app/chauffeurs/modifier.php?id=<?= $ch['id'] ?>"
               style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:7px;background:#eff6ff;color:#0d9488;text-decoration:none;font-size:.72rem">
                <i class="fas fa-pen"></i>
            </a>
        </div>
    </div>
    <?php endforeach ?>
    <?php endif ?>

    <?php if (empty($vtcAll) && empty($chAll)): ?>
    <div style="text-align:center;padding:30px;color:#94a3b8;font-size:.88rem">Aucun résultat</div>
    <?php endif ?>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
