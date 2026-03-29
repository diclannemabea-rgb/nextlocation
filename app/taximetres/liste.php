<?php
/**
 * FlotteCar — Liste des taximantres (v2)
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
$thisMonth = date('Y-m');

// ── POST ACTIONS ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'ajouter_taximetre') {
        $vehiculeId      = (int)($_POST['vehicule_id'] ?? 0);
        $nom             = trim($_POST['nom'] ?? '');
        $prenom          = trim($_POST['prenom'] ?? '');
        $telephone       = trim($_POST['telephone'] ?? '');
        $numeroCni       = trim($_POST['numero_cni'] ?? '');
        $tarifJournalier = (float)($_POST['tarif_journalier'] ?? 0);
        $dateDebut       = trim($_POST['date_debut'] ?? '');
        $cautionVersee   = (float)($_POST['caution_versee'] ?? 0);
        $notes           = trim($_POST['notes'] ?? '');

        if (!$nom || !$telephone || !$tarifJournalier || !$dateDebut || !$vehiculeId) {
            setFlash(FLASH_ERROR, 'Champs obligatoires manquants.');
        } else {
            $chk = $db->prepare("SELECT id FROM vehicules WHERE id = ? AND tenant_id = ?");
            $chk->execute([$vehiculeId, $tenantId]);
            if (!$chk->fetch()) {
                setFlash(FLASH_ERROR, 'Véhicule introuvable.');
            } else {
                $ins = $db->prepare("INSERT INTO taximetres (tenant_id, vehicule_id, nom, prenom, telephone, numero_cni, tarif_journalier, date_debut, caution_versee, notes) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $ins->execute([$tenantId, $vehiculeId, $nom, $prenom, $telephone, $numeroCni, $tarifJournalier, $dateDebut, $cautionVersee, $notes]);
                setFlash(FLASH_SUCCESS, 'Taximantre associé avec succès.');
            }
        }
        redirect(BASE_URL . 'app/taximetres/liste.php');
    }

    if ($action === 'suspendre') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE taximetres SET statut='suspendu' WHERE id=? AND tenant_id=?")->execute([$id, $tenantId]);
        setFlash(FLASH_SUCCESS, 'Taximantre suspendu.');
        redirect(BASE_URL . 'app/taximetres/liste.php');
    }

    if ($action === 'activer') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE taximetres SET statut='actif' WHERE id=? AND tenant_id=?")->execute([$id, $tenantId]);
        setFlash(FLASH_SUCCESS, 'Taximantre activé.');
        redirect(BASE_URL . 'app/taximetres/liste.php');
    }
}

// ── FILTRES ────────────────────────────────────────────────────────────────────
$filterStatut = $_GET['statut'] ?? '';
$q            = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$per          = ITEMS_PER_PAGE;

$where  = "WHERE t.tenant_id = ?";
$params = [$tenantId];
if ($q) { $where .= " AND (t.nom LIKE ? OR t.prenom LIKE ? OR t.telephone LIKE ? OR v.immatriculation LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($filterStatut) { $where .= " AND t.statut = ?"; $params[] = $filterStatut; }

$cntStmt = $db->prepare("SELECT COUNT(*) FROM taximetres t JOIN vehicules v ON v.id=t.vehicule_id $where");
$cntStmt->execute($params);
$total  = (int)$cntStmt->fetchColumn();
$offset = ($page - 1) * $per;

// Main query — debt computed in PHP after fetch (ORDER BY done in PHP too)
$stmt = $db->prepare("
    SELECT
        t.id, t.nom, t.prenom, t.telephone, t.numero_cni,
        t.tarif_journalier, t.date_debut, t.statut, t.caution_versee,
        v.id             AS vehicule_id,
        v.nom            AS veh_nom,
        v.immatriculation,
        v.kilometrage_actuel,
        GREATEST(0, DATEDIFF(CURDATE(), t.date_debut) + 1)                           AS periode_jours,
        COALESCE(SUM(pt.statut_jour IN ('jour_off','panne','accident','maladie')), 0) AS jours_off,
        COALESCE(SUM(CASE WHEN pt.statut_jour='paye' THEN pt.montant ELSE 0 END), 0) AS total_percu,
        COALESCE(SUM(CASE WHEN pt.statut_jour='paye' THEN 1 ELSE 0 END), 0)          AS jours_payes,
        (SELECT p2.statut_jour FROM paiements_taxi p2
            WHERE p2.taximetre_id = t.id AND p2.date_paiement = CURDATE() LIMIT 1)   AS statut_auj,
        (SELECT MAX(p2.date_paiement) FROM paiements_taxi p2
            WHERE p2.taximetre_id = t.id AND p2.statut_jour = 'paye')                AS dernier_paiement
    FROM taximetres t
    JOIN vehicules v ON v.id = t.vehicule_id AND v.tenant_id = t.tenant_id
    LEFT JOIN paiements_taxi pt ON pt.taximetre_id = t.id AND pt.tenant_id = t.tenant_id
    $where
    GROUP BY t.id
    ORDER BY t.statut ASC, t.nom ASC
    LIMIT $per OFFSET $offset
");
$stmt->execute($params);
$taximetres = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Compute debt for each
foreach ($taximetres as &$t) {
    $facturables = max(0, (int)$t['periode_jours'] - (int)$t['jours_off']);
    $t['dette']        = max(0, $facturables * (float)$t['tarif_journalier'] - (float)$t['total_percu']);
    $t['jours_facturables'] = $facturables;
}
unset($t);

// Sort by dette desc for actifs
usort($taximetres, function($a, $b) {
    if ($a['statut'] !== $b['statut']) return $a['statut'] === 'actif' ? -1 : 1;
    return $b['dette'] <=> $a['dette'];
});

// ── KPI GLOBAUX ────────────────────────────────────────────────────────────────
$kpiStmt = $db->prepare("
    SELECT
        COUNT(*)                                             AS total,
        SUM(t.statut = 'actif')                              AS nb_actifs,
        SUM(t.statut = 'suspendu')                           AS nb_suspendus,
        COALESCE(SUM(CASE WHEN pt.statut_jour='paye'
            AND DATE_FORMAT(pt.date_paiement,'%Y-%m') = ?
            THEN pt.montant ELSE 0 END), 0)                  AS recu_mois
    FROM taximetres t
    LEFT JOIN paiements_taxi pt ON pt.taximetre_id = t.id AND pt.tenant_id = t.tenant_id
    WHERE t.tenant_id = ?
    GROUP BY t.tenant_id
");
$kpiStmt->execute([$thisMonth, $tenantId]);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'nb_actifs' => 0, 'nb_suspendus' => 0, 'recu_mois' => 0];

// Total dette globale
$totalDette = 0.0;
$nbAvecDette = 0;
$dStmt = $db->prepare("
    SELECT t.tarif_journalier, t.date_debut,
           GREATEST(0, DATEDIFF(CURDATE(), t.date_debut)+1) AS periode,
           COALESCE(SUM(pt.statut_jour IN ('jour_off','panne','accident','maladie')),0) AS jours_off,
           COALESCE(SUM(CASE WHEN pt.statut_jour='paye' THEN pt.montant ELSE 0 END),0) AS total_percu
    FROM taximetres t
    LEFT JOIN paiements_taxi pt ON pt.taximetre_id=t.id AND pt.tenant_id=t.tenant_id
    WHERE t.tenant_id=? AND t.statut='actif'
    GROUP BY t.id
");
$dStmt->execute([$tenantId]);
foreach ($dStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $f = max(0, (int)$row['periode'] - (int)$row['jours_off']);
    $d = max(0, $f * (float)$row['tarif_journalier'] - (float)$row['total_percu']);
    $totalDette += $d;
    if ($d > 0) $nbAvecDette++;
}

// Nb non saisis aujourd'hui
$nbNonSaisAuj = $db->prepare("
    SELECT COUNT(*) FROM taximetres t
    WHERE t.tenant_id=? AND t.statut='actif'
    AND NOT EXISTS (SELECT 1 FROM paiements_taxi p WHERE p.taximetre_id=t.id AND p.date_paiement=CURDATE())
");
$nbNonSaisAuj->execute([$tenantId]);
$nbNonSaisAuj = (int)$nbNonSaisAuj->fetchColumn();

// Vehicules dispo pour le formulaire
$stVehs = $db->prepare("
    SELECT v.id, v.nom, v.immatriculation FROM vehicules v
    WHERE v.tenant_id = ? AND v.type_vehicule = 'taxi'
    AND v.id NOT IN (SELECT vehicule_id FROM taximetres WHERE tenant_id=? AND statut='actif')
    ORDER BY v.nom ASC
");
$stVehs->execute([$tenantId, $tenantId]);
$vehiculesDispos = $stVehs->fetchAll(PDO::FETCH_ASSOC);

$pageTitle  = 'Taximantres';
$activePage = 'taximetres';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
/* ── Taximetres liste ──────────────────── */
@keyframes txfade { from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:none} }

.tx-kpi-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin-bottom:14px; }
.tx-kpi {
    background:#fff; border:1px solid #e2e8f0; border-radius:12px;
    padding:12px 14px; display:flex; align-items:center; gap:10px;
}
.tx-kpi-ico {
    width:38px; height:38px; border-radius:10px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:.88rem;
}
.tx-kpi-val { font-size:1.1rem; font-weight:800; color:#0f172a; line-height:1.1; }
.tx-kpi-lbl { font-size:.67rem; color:#94a3b8; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }

.tx-row { cursor:pointer; transition:background .1s; animation:txfade .2s ease both; }
.tx-row:hover td { background:#f8fafc; }
.tx-row.has-debt:hover td { background:#fff8f8; }
.tx-row td { vertical-align:middle; padding:10px 12px; border-bottom:1px solid #f1f5f9; font-size:.82rem; }
.tx-head th { padding:8px 12px; font-size:.67rem; text-transform:uppercase; letter-spacing:.07em; color:#94a3b8; background:#f8fafc; border-bottom:2px solid #e2e8f0; white-space:nowrap; font-weight:700; }
.av-tx { width:36px;height:36px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.82rem;font-weight:700;flex-shrink:0; }
.debt-chip { display:inline-flex;align-items:center;gap:4px;padding:3px 9px;background:#fee2e2;color:#dc2626;border-radius:6px;font-size:.78rem;font-weight:700; }
.ok-chip { display:inline-flex;align-items:center;gap:4px;padding:3px 9px;background:#d1fae5;color:#059669;border-radius:6px;font-size:.78rem;font-weight:700; }
.day-badge { display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:4px;font-size:.68rem;font-weight:600; }
.tx-act { display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:7px;font-size:.72rem;text-decoration:none;transition:.12s;border:none;cursor:pointer;background:#f1f5f9;color:#475569; }
.tx-act:hover { background:#e2e8f0; }

/* Mobile */
.tx-table-wrap { display:block; }
.tx-cards { display:none; flex-direction:column; gap:10px; }
@media(max-width:768px) {
    .tx-kpi-grid { grid-template-columns:repeat(2,1fr); }
    .tx-kpi-grid .tx-kpi:last-child { grid-column:span 2; }
    .tx-table-wrap { display:none !important; }
    .tx-cards { display:flex !important; }
}
</style>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px">
    <div>
        <h1 style="font-size:1.3rem;font-weight:800;color:#0f172a;margin:0">
            <i class="fas fa-taxi" style="color:#d97706;font-size:1.1rem;margin-right:6px"></i>Taximantres
        </h1>
        <p style="font-size:.78rem;color:#94a3b8;margin:3px 0 0">
            <strong style="color:#0f172a"><?= (int)$kpi['nb_actifs'] ?></strong> actifs ·
            <strong style="color:#0f172a"><?= $total ?></strong> enregistrés
        </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>app/taximetres/paiements.php" class="btn btn-success btn-sm">
            <i class="fas fa-coins"></i> Saisir paiements
        </a>
        <button class="btn btn-primary btn-sm" onclick="openModal('modal-add-taxi')">
            <i class="fas fa-plus"></i> Associer
        </button>
    </div>
</div>

<?= renderFlashes() ?>

<!-- KPIs -->
<div class="tx-kpi-grid">
    <div class="tx-kpi">
        <div class="tx-kpi-ico" style="background:#fef3c7"><i class="fas fa-taxi" style="color:#d97706"></i></div>
        <div>
            <div class="tx-kpi-val"><?= (int)$kpi['nb_actifs'] ?></div>
            <div class="tx-kpi-lbl">Actifs</div>
        </div>
    </div>
    <div class="tx-kpi" style="border-color:<?= $totalDette > 0 ? '#fecaca' : '#e2e8f0' ?>">
        <div class="tx-kpi-ico" style="background:<?= $totalDette > 0 ? '#fff1f2' : '#f0fdf4' ?>">
            <i class="fas fa-exclamation-circle" style="color:<?= $totalDette > 0 ? '#ef4444' : '#10b981' ?>"></i>
        </div>
        <div>
            <div class="tx-kpi-val" style="font-size:.88rem;color:<?= $totalDette > 0 ? '#ef4444' : '#10b981' ?>"><?= formatMoney($totalDette) ?></div>
            <div class="tx-kpi-lbl">Dette flotte</div>
        </div>
    </div>
    <div class="tx-kpi">
        <div class="tx-kpi-ico" style="background:#f0fdf4"><i class="fas fa-check-circle" style="color:#10b981"></i></div>
        <div>
            <div class="tx-kpi-val" style="font-size:.88rem"><?= formatMoney((float)$kpi['recu_mois']) ?></div>
            <div class="tx-kpi-lbl">Encaissé <?= date('M') ?></div>
        </div>
    </div>
    <div class="tx-kpi" style="border-color:<?= $nbNonSaisAuj > 0 ? '#fde68a' : '#e2e8f0' ?>">
        <div class="tx-kpi-ico" style="background:<?= $nbNonSaisAuj > 0 ? '#fef3c7' : '#f0fdf4' ?>">
            <i class="fas fa-question-circle" style="color:<?= $nbNonSaisAuj > 0 ? '#d97706' : '#10b981' ?>"></i>
        </div>
        <div>
            <div class="tx-kpi-val" style="color:<?= $nbNonSaisAuj > 0 ? '#d97706' : '#10b981' ?>"><?= $nbNonSaisAuj ?></div>
            <div class="tx-kpi-lbl">Non saisis auj.</div>
        </div>
    </div>
    <div class="tx-kpi">
        <div class="tx-kpi-ico" style="background:#f8fafc"><i class="fas fa-pause-circle" style="color:#94a3b8"></i></div>
        <div>
            <div class="tx-kpi-val" style="color:#94a3b8"><?= (int)$kpi['nb_suspendus'] ?></div>
            <div class="tx-kpi-lbl">Suspendus</div>
        </div>
    </div>
</div>

<!-- Alerte non-saisis -->
<?php if ($nbNonSaisAuj > 0): ?>
<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:10px 16px;margin-bottom:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <i class="fas fa-exclamation-triangle" style="color:#d97706;font-size:1rem;flex-shrink:0"></i>
    <div style="flex:1;font-size:.82rem;min-width:160px">
        <strong style="color:#92400e"><?= $nbNonSaisAuj ?> taximantre<?= $nbNonSaisAuj > 1 ? 's' : '' ?> sans saisie aujourd'hui</strong>
    </div>
    <a href="<?= BASE_URL ?>app/taximetres/paiements.php" class="btn btn-warning btn-sm">
        <i class="fas fa-coins"></i> Saisir maintenant
    </a>
</div>
<?php endif ?>

<!-- Filtres -->
<div class="card" style="margin-bottom:12px">
    <div style="padding:10px 14px">
        <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <div style="position:relative;flex:1;min-width:160px">
                <i class="fas fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.75rem"></i>
                <input type="text" name="q" class="form-control" placeholder="Nom, téléphone, immat…" value="<?= sanitize($q) ?>" style="padding-left:32px;height:32px;font-size:.8rem">
            </div>
            <select name="statut" class="form-control" style="width:130px;height:32px;font-size:.8rem">
                <option value="">Tous statuts</option>
                <option value="actif"    <?= $filterStatut === 'actif'    ? 'selected' : '' ?>>Actif</option>
                <option value="suspendu" <?= $filterStatut === 'suspendu' ? 'selected' : '' ?>>Suspendu</option>
            </select>
            <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i> Filtrer</button>
            <?php if ($q || $filterStatut): ?>
            <a href="<?= BASE_URL ?>app/taximetres/liste.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a>
            <?php endif ?>
            <span style="margin-left:auto;font-size:.75rem;color:#94a3b8"><?= $total ?> résultat<?= $total > 1 ? 's' : '' ?></span>
        </form>
    </div>
</div>

<!-- TABLE (desktop) -->
<div class="card tx-table-wrap">
    <?php if (empty($taximetres)): ?>
    <div style="text-align:center;padding:3rem;color:#94a3b8">
        <i class="fas fa-taxi" style="font-size:2.5rem;display:block;margin-bottom:10px;opacity:.4"></i>
        <p style="font-size:.88rem;font-weight:600;color:#64748b">Aucun taximantre trouvé.</p>
        <button onclick="openModal('modal-add-taxi')" class="btn btn-primary btn-sm" style="margin-top:8px">
            <i class="fas fa-plus"></i> Associer un taximantre
        </button>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
        <table class="table">
            <thead class="tx-head">
                <tr>
                    <th>Taximantre</th>
                    <th>Véhicule</th>
                    <th style="text-align:center">Aujourd'hui</th>
                    <th style="text-align:right">Tarif/j</th>
                    <th style="text-align:right">Période</th>
                    <th style="text-align:right">Encaissé</th>
                    <th style="text-align:right">Dette</th>
                    <th style="text-align:center">Dernier paiement</th>
                    <th style="text-align:center;width:80px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($taximetres as $ti => $t):
                $dette    = (float)$t['dette'];
                $isActif  = $t['statut'] === 'actif';
                $initials = mb_strtoupper(mb_substr($t['nom'], 0, 1) . mb_substr($t['prenom'] ?? '', 0, 1));
                $aujStatut = $t['statut_auj'];
                $aujBadge  = match($aujStatut) {
                    'paye'     => ['#d1fae5','#059669','fa-check','Payé'],
                    'non_paye' => ['#fee2e2','#dc2626','fa-times','Non payé'],
                    'jour_off' => ['#f1f5f9','#64748b','fa-moon','Off'],
                    'panne'    => ['#fef3c7','#d97706','fa-wrench','Panne'],
                    'accident' => ['#fee2e2','#9f1239','fa-car-crash','Accident'],
                    default    => ['#f1f5f9','#94a3b8','fa-question','Non saisi'],
                };
                $dpDays = $t['dernier_paiement'] ? (int)ceil((strtotime($today) - strtotime($t['dernier_paiement'])) / 86400) : null;
            ?>
            <tr class="tx-row <?= ($isActif && $dette > 0) ? 'has-debt' : '' ?>" style="animation-delay:<?= $ti*.025 ?>s"
                onclick="window.location='<?= BASE_URL ?>app/taximetres/detail.php?id=<?= $t['id'] ?>'">
                <td>
                    <div style="display:flex;align-items:center;gap:9px">
                        <div class="av-tx" style="background:<?= $isActif ? '#fef3c7' : '#f1f5f9' ?>;color:<?= $isActif ? '#d97706' : '#94a3b8' ?>"><?= $initials ?></div>
                        <div>
                            <div style="font-weight:700;color:#0f172a"><?= sanitize($t['nom'] . ' ' . ($t['prenom'] ?? '')) ?></div>
                            <?php if ($t['telephone']): ?>
                            <a href="tel:<?= sanitize($t['telephone']) ?>" onclick="event.stopPropagation()" style="color:#94a3b8;text-decoration:none;font-size:.7rem">
                                <i class="fas fa-phone" style="font-size:.63rem"></i> <?= sanitize($t['telephone']) ?>
                            </a>
                            <?php endif ?>
                        </div>
                        <?php if (!$isActif): ?><span style="background:#f1f5f9;color:#94a3b8;padding:1px 7px;border-radius:99px;font-size:.63rem;font-weight:700">Suspendu</span><?php endif ?>
                    </div>
                </td>
                <td>
                    <div style="font-weight:600;font-size:.8rem"><?= sanitize($t['veh_nom']) ?></div>
                    <div style="font-size:.7rem;color:#94a3b8"><?= sanitize($t['immatriculation']) ?></div>
                </td>
                <td style="text-align:center" onclick="event.stopPropagation()">
                    <?php [$abg,$afg,$aic,$alb] = $aujBadge; ?>
                    <span class="day-badge" style="background:<?= $abg ?>;color:<?= $afg ?>"><i class="fas <?= $aic ?>"></i> <?= $alb ?></span>
                    <?php if ($aujStatut === null && $isActif): ?>
                    <br><a href="<?= BASE_URL ?>app/taximetres/paiements.php" onclick="event.stopPropagation()" style="font-size:.65rem;color:#d97706;display:inline-block;margin-top:2px">Saisir →</a>
                    <?php endif ?>
                </td>
                <td style="text-align:right;font-weight:700"><?= formatMoney($t['tarif_journalier']) ?></td>
                <td style="text-align:right;font-size:.78rem">
                    <div><?= (int)$t['jours_facturables'] ?>j fact.</div>
                    <div style="font-size:.68rem;color:#94a3b8"><?= (int)$t['jours_off'] ?>j off</div>
                </td>
                <td style="text-align:right;color:#059669;font-weight:700"><?= formatMoney($t['total_percu']) ?></td>
                <td style="text-align:right">
                    <?php if ($dette > 0): ?>
                    <div class="debt-chip"><i class="fas fa-arrow-down"></i> <?= formatMoney($dette) ?></div>
                    <?php else: ?>
                    <div class="ok-chip"><i class="fas fa-check"></i> À jour</div>
                    <?php endif ?>
                </td>
                <td style="text-align:center;font-size:.75rem" onclick="event.stopPropagation()">
                    <?php if ($t['dernier_paiement']): ?>
                    <div style="font-weight:600"><?= formatDate($t['dernier_paiement']) ?></div>
                    <div style="font-size:.68rem;color:<?= ($dpDays??0) > 3 ? '#ef4444' : '#94a3b8' ?>"><?= $dpDays === 0 ? 'Aujourd\'hui' : "il y a {$dpDays}j" ?></div>
                    <?php else: ?><span style="color:#94a3b8">Aucun</span><?php endif ?>
                </td>
                <td style="text-align:center" onclick="event.stopPropagation()">
                    <div style="display:flex;gap:3px;justify-content:center">
                        <a href="<?= BASE_URL ?>app/taximetres/detail.php?id=<?= $t['id'] ?>" class="tx-act" title="Voir fiche"><i class="fas fa-eye" style="font-size:.72rem"></i></a>
                        <?php if ($isActif): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Suspendre ce taximantre ?')">
                            <?= csrfField() ?><input type="hidden" name="action" value="suspendre"><input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button type="submit" class="tx-act" title="Suspendre" style="color:#f59e0b"><i class="fas fa-pause" style="font-size:.72rem"></i></button>
                        </form>
                        <?php else: ?>
                        <form method="POST" style="display:inline">
                            <?= csrfField() ?><input type="hidden" name="action" value="activer"><input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button type="submit" class="tx-act" title="Réactiver" style="color:#10b981"><i class="fas fa-play" style="font-size:.72rem"></i></button>
                        </form>
                        <?php endif ?>
                    </div>
                </td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?= renderPagination($total, $page, $per, BASE_URL . 'app/taximetres/liste.php?' . http_build_query(array_filter(['q' => $q, 'statut' => $filterStatut], 'strlen'))) ?>
    <?php endif ?>
</div>

<!-- CARDS (mobile) -->
<div class="tx-cards">
<?php if (empty($taximetres)): ?>
<div style="text-align:center;padding:30px;color:#94a3b8;font-size:.88rem">Aucun taximantre trouvé</div>
<?php else: ?>
<?php foreach ($taximetres as $t):
    $dette   = (float)$t['dette'];
    $isActif = $t['statut'] === 'actif';
    $initials = mb_strtoupper(mb_substr($t['nom'], 0, 1) . mb_substr($t['prenom'] ?? '', 0, 1));
    $aujStatut = $t['statut_auj'];
    $aujColors = match($aujStatut) {
        'paye'     => ['#d1fae5','#059669'],
        'non_paye' => ['#fee2e2','#dc2626'],
        'jour_off' => ['#f1f5f9','#64748b'],
        'panne'    => ['#fef3c7','#d97706'],
        default    => ['#f8fafc','#94a3b8'],
    };
    $aujLabel = match($aujStatut) { 'paye'=>'Payé', 'non_paye'=>'Non payé', 'jour_off'=>'Off', 'panne'=>'Panne', 'accident'=>'Accident', default=>'Non saisi' };
?>
<a href="<?= BASE_URL ?>app/taximetres/detail.php?id=<?= $t['id'] ?>"
   style="display:block;background:#fff;border:1px solid <?= ($dette > 0 && $isActif) ? '#fecaca' : '#e2e8f0' ?>;border-radius:13px;padding:12px 14px;text-decoration:none;color:inherit">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <div class="av-tx" style="background:<?= $isActif ? '#fef3c7' : '#f1f5f9' ?>;color:<?= $isActif ? '#d97706' : '#94a3b8' ?>;width:40px;height:40px;flex-shrink:0"><?= $initials ?></div>
        <div style="flex:1;min-width:0">
            <div style="font-weight:700;color:#0f172a;font-size:.9rem"><?= sanitize($t['nom'].' '.($t['prenom']??'')) ?></div>
            <div style="font-size:.75rem;color:#64748b"><?= sanitize($t['veh_nom']) ?> · <?= sanitize($t['immatriculation']) ?></div>
        </div>
        <span style="display:inline-flex;align-items:center;padding:3px 9px;border-radius:6px;font-size:.7rem;font-weight:700;background:<?= $aujColors[0] ?>;color:<?= $aujColors[1] ?>;flex-shrink:0"><?= $aujLabel ?></span>
    </div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span style="font-size:.75rem;color:#64748b"><?= formatMoney($t['tarif_journalier']) ?>/j</span>
        <span style="font-size:.75rem;color:#10b981;font-weight:700"><i class="fas fa-check-circle"></i> <?= formatMoney($t['total_percu']) ?></span>
        <?php if ($dette > 0 && $isActif): ?>
        <span class="debt-chip" style="font-size:.72rem"><i class="fas fa-exclamation-circle"></i> <?= formatMoney($dette) ?></span>
        <?php else: ?>
        <span class="ok-chip" style="font-size:.72rem"><i class="fas fa-check"></i> À jour</span>
        <?php endif ?>
    </div>
</a>
<?php endforeach ?>
<div style="padding:6px 2px"><?= renderPagination($total, $page, $per, BASE_URL . 'app/taximetres/liste.php?' . http_build_query(array_filter(['q' => $q, 'statut' => $filterStatut], 'strlen'))) ?></div>
<?php endif ?>
</div>

<!-- ══ MODAL AJOUTER TAXIMANTRE ════════════════════════════════════════════════ -->
<div id="modal-add-taxi" class="modal-overlay">
    <div class="modal" style="max-width:560px">
        <div class="modal-header">
            <h3><i class="fas fa-taxi" style="color:#d97706;margin-right:6px"></i> Associer un taximantre</h3>
            <button class="modal-close" onclick="closeModal('modal-add-taxi')">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="ajouter_taximetre">
            <div class="form-row cols-2">
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Véhicule taxi *</label>
                    <select name="vehicule_id" class="form-control" required>
                        <option value="">— Sélectionner —</option>
                        <?php foreach ($vehiculesDispos as $v): ?>
                        <option value="<?= $v['id'] ?>"><?= sanitize($v['nom']) ?> (<?= sanitize($v['immatriculation']) ?>)</option>
                        <?php endforeach ?>
                        <?php if (empty($vehiculesDispos)): ?>
                        <option disabled>Aucun véhicule taxi disponible</option>
                        <?php endif ?>
                    </select>
                    <?php if (empty($vehiculesDispos)): ?>
                    <div class="form-hint" style="color:#d97706"><i class="fas fa-info-circle"></i> Ajoutez d'abord un véhicule de type "Taxi".</div>
                    <?php endif ?>
                </div>
                <div class="form-group">
                    <label class="form-label">Nom *</label>
                    <input type="text" name="nom" class="form-control" required placeholder="Nom de famille">
                </div>
                <div class="form-group">
                    <label class="form-label">Prénom</label>
                    <input type="text" name="prenom" class="form-control" placeholder="Prénom">
                </div>
                <div class="form-group">
                    <label class="form-label">Téléphone *</label>
                    <input type="text" name="telephone" class="form-control" required placeholder="07XXXXXXXX">
                </div>
                <div class="form-group">
                    <label class="form-label">N° CNI</label>
                    <input type="text" name="numero_cni" class="form-control" placeholder="Numéro CNI">
                </div>
                <div class="form-group">
                    <label class="form-label">Tarif journalier (FCFA) *</label>
                    <input type="number" name="tarif_journalier" class="form-control" required min="0" step="500" placeholder="Ex: 5000">
                </div>
                <div class="form-group">
                    <label class="form-label">Date de début *</label>
                    <input type="date" name="date_debut" class="form-control" required value="<?= $today ?>">
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Caution versée (FCFA)</label>
                    <input type="number" name="caution_versee" class="form-control" min="0" step="500" placeholder="0">
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-add-taxi')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-link"></i> Associer</button>
            </div>
        </form>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
