<?php
/**
 * FlotteCar — Maintenances
 * Liste unifiée : maintenances programmées + alertes documents (assurance/vignette)
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
require_once BASE_PATH . '/models/MaintenanceModel.php';
requireTenantAuth();

$db       = (new Database())->getConnection();
$tenantId = getTenantId();
$today    = date('Y-m-d');
$model    = new MaintenanceModel($db);

// Auto-passer en retard
$db->prepare("UPDATE maintenances SET statut='en_retard' WHERE tenant_id=? AND statut='planifie' AND date_prevue IS NOT NULL AND date_prevue < CURDATE()")
   ->execute([$tenantId]);

// ─── TRAITEMENT POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'ajouter') {
        $vehId = (int)($_POST['vehicule_id'] ?? 0);
        $type  = trim($_POST['type'] ?? '');
        $chk = $db->prepare("SELECT id FROM vehicules WHERE id=? AND tenant_id=?");
        $chk->execute([$vehId, $tenantId]);
        if ($chk->fetch() && $type) {
            $model->create($tenantId, [
                'vehicule_id' => $vehId,
                'type'        => $type,
                'km_prevu'    => $_POST['km_prevu']   ?: null,
                'date_prevue' => $_POST['date_prevue'] ?: null,
                'cout'        => cleanNumber($_POST['cout'] ?? '0'),
                'technicien'  => trim($_POST['technicien'] ?? '') ?: null,
                'notes'       => trim($_POST['notes'] ?? '') ?: null,
            ]);
            logActivite($db, 'create', 'maintenances', "Maintenance $type planifiée");
            // Push notif
            $vehN = ''; foreach ($vehicules as $_v) { if ($_v['id'] === $vehId) { $vehN = $_v['nom'] . ' ' . $_v['immatriculation']; break; } }
            pushNotif($db, $tenantId, 'maintenance', "🔧 Maintenance planifiée — $vehN", ucfirst($type) . ($_POST['date_prevue'] ? ' prévue le ' . formatDate($_POST['date_prevue']) : '') . ($_POST['km_prevu'] ? ' à ' . number_format((int)$_POST['km_prevu'], 0, ',', ' ') . ' km' : ''), BASE_URL . 'app/maintenances/index.php');
            setFlash(FLASH_SUCCESS, 'Maintenance planifiée.');
        } else {
            setFlash(FLASH_ERROR, 'Données invalides.');
        }
    }

    if ($action === 'terminer') {
        $id          = (int)($_POST['id'] ?? 0);
        $kmFait      = (int)($_POST['km_fait'] ?? 0);
        $cout        = cleanNumber($_POST['cout_reel'] ?? '0') ?: null;
        $technicien  = trim($_POST['technicien'] ?? '') ?: null;
        $planVidange = isset($_POST['planifier_prochaine']) ? 1 : 0;
        $facture     = null;
        if (!empty($_FILES['facture']['name'])) {
            $facture = uploadFile($_FILES['facture'], UPLOAD_DOCUMENTS, ['jpg','jpeg','png','pdf']);
        }
        $maint = $model->getById($id, $tenantId);
        if ($maint) {
            $model->terminer($id, $tenantId, $kmFait, $cout, $technicien, $facture);
            if ($kmFait > 0) {
                $db->prepare("UPDATE vehicules SET kilometrage_actuel = GREATEST(kilometrage_actuel, ?) WHERE id = ? AND tenant_id = ?")
                   ->execute([$kmFait, $maint['vehicule_id'], $tenantId]);
            }
            if ($cout && $cout > 0) {
                $libMaint = ucfirst($maint['type']) . ' — maintenance #' . $id;
                $db->prepare("INSERT INTO charges (tenant_id,vehicule_id,type,libelle,montant,date_charge) VALUES (?,?,'maintenance',?,?,CURDATE())")
                   ->execute([$tenantId, $maint['vehicule_id'], $libMaint, $cout]);
                // Dépenses calculées dynamiquement via SUM(charges) — plus de cumul dans depenses_initiales
            }
            if ($planVidange && strtolower($maint['type']) === 'vidange') {
                $model->planifierProchaineVidange($maint['vehicule_id'], $tenantId, max($kmFait, (int)$maint['km_prevu']));
                setFlash(FLASH_SUCCESS, 'Maintenance terminée. Prochaine vidange planifiée dans 5 000 km.');
            } else {
                setFlash(FLASH_SUCCESS, 'Maintenance terminée' . ($cout > 0 ? ' — ' . formatMoney($cout) . ' ajoutés aux dépenses.' : '.'));
            }
            logActivite($db, 'update', 'maintenances', "Maintenance #$id terminée — km: $kmFait");
            // Push notif
            pushNotif($db, $tenantId, 'maintenance', "✅ Maintenance terminée — " . sanitize($maint['vehicule_nom'] ?? '#'.$id), ucfirst($maint['type']) . ($kmFait ? ' à ' . number_format($kmFait, 0, ',', ' ') . ' km' : '') . ($cout > 0 ? ' · ' . formatMoney($cout) : ''), BASE_URL . 'app/maintenances/index.php');
        }
    }

    if ($action === 'supprimer') {
        $model->delete((int)($_POST['id'] ?? 0), $tenantId);
        setFlash(FLASH_SUCCESS, 'Maintenance supprimée.');
    }

    redirect(BASE_URL . 'app/maintenances/index.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

// ─── FILTRES & DONNÉES ────────────────────────────────────────────────────────
$tab          = in_array($_GET['tab'] ?? '', ['planifie','en_retard','termine','alertes']) ? $_GET['tab'] : 'actif';
$filterVeh    = (int)($_GET['vehicule_id'] ?? 0);
$filterType   = $_GET['type'] ?? '';

// Véhicules pour selects
$stmtV = $db->prepare("SELECT id, nom, immatriculation, kilometrage_actuel FROM vehicules WHERE tenant_id=? AND statut != 'supprime' ORDER BY nom");
$stmtV->execute([$tenantId]);
$vehicules = $stmtV->fetchAll(PDO::FETCH_ASSOC);

// Toutes les maintenances actives
$whM = "WHERE m.tenant_id=? AND m.statut != 'termine'";
$prM = [$tenantId];
if ($filterVeh)  { $whM .= ' AND m.vehicule_id=?'; $prM[] = $filterVeh; }
if ($filterType) { $whM .= ' AND m.type=?';         $prM[] = $filterType; }
$stmtM = $db->prepare("SELECT m.*, v.nom vehicule_nom, v.immatriculation, v.kilometrage_actuel
    FROM maintenances m JOIN vehicules v ON v.id=m.vehicule_id
    $whM ORDER BY FIELD(m.statut,'en_retard','planifie'), m.date_prevue ASC, m.km_prevu ASC");
$stmtM->execute($prM);
$maintsActives = $stmtM->fetchAll(PDO::FETCH_ASSOC);

// Maintenances terminées (filtrées)
$whT = "WHERE m.tenant_id=? AND m.statut='termine'";
$prT = [$tenantId];
if ($filterVeh)  { $whT .= ' AND m.vehicule_id=?'; $prT[] = $filterVeh; }
if ($filterType) { $whT .= ' AND m.type=?';         $prT[] = $filterType; }
$stmtT = $db->prepare("SELECT m.*, v.nom vehicule_nom, v.immatriculation, v.kilometrage_actuel
    FROM maintenances m JOIN vehicules v ON v.id=m.vehicule_id
    $whT ORDER BY m.updated_at DESC LIMIT 30");
$stmtT->execute($prT);
$maintsTerminees = $stmtT->fetchAll(PDO::FETCH_ASSOC);

// Alertes docs (assurance/vignette)
$in30 = date('Y-m-d', strtotime('+30 days'));
$whD = "WHERE tenant_id=? AND ((date_expiration_assurance IS NOT NULL AND date_expiration_assurance <= ?) OR (date_expiration_vignette IS NOT NULL AND date_expiration_vignette <= ?))";
$prD = [$tenantId, $in30, $in30];
if ($filterVeh) { $whD .= ' AND id=?'; $prD[] = $filterVeh; }
$stmtD = $db->prepare("SELECT id, nom, immatriculation, date_expiration_assurance, date_expiration_vignette FROM vehicules $whD ORDER BY LEAST(COALESCE(date_expiration_assurance,'9999-12-31'),COALESCE(date_expiration_vignette,'9999-12-31')) ASC");
$stmtD->execute($prD);
$docAlertes = $stmtD->fetchAll(PDO::FETCH_ASSOC);

// Compteurs pour les tabs
$nbRetard  = count(array_filter($maintsActives, fn($m) => $m['statut'] === 'en_retard'));
$nbPlanif  = count(array_filter($maintsActives, fn($m) => $m['statut'] === 'planifie'));
$nbDocs    = count($docAlertes);
$nbActif   = count($maintsActives);
$nbTermine = count($maintsTerminees);
$nbAlertes = $nbRetard + $nbDocs;

// Compteurs globaux pour KPIs (sans filtre véhicule)
$kpiCounts = $db->prepare("SELECT
    SUM(statut='planifie') nb_planif,
    SUM(statut='en_retard') nb_retard,
    SUM(statut='termine') nb_termine,
    COUNT(*) nb_total
    FROM maintenances WHERE tenant_id=?");
$kpiCounts->execute([$tenantId]);
$kpi = $kpiCounts->fetch(PDO::FETCH_ASSOC);

$pageTitle  = 'Maintenances';
$activePage = 'maintenances';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
/* ── KPIs ── */
.maint-kpi{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}
.maint-kpi-card{background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:13px 16px;position:relative;overflow:hidden;cursor:default}
.maint-kpi-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--kc,#0d9488);border-radius:3px 0 0 3px}
.maint-kpi-card .mk-val{font-size:1.3rem;font-weight:800;color:#0f172a;margin-bottom:2px}
.maint-kpi-card .mk-lbl{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#64748b}
.maint-kpi-card .mk-ico{position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:1.5rem;opacity:.1}

/* ── Tabs ── */
.maint-tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:0;overflow-x:auto;scrollbar-width:none}
.maint-tabs a{padding:9px 16px;font-size:.82rem;font-weight:600;color:#64748b;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;transition:.15s;display:flex;align-items:center;gap:6px}
.maint-tabs a.active{color:#0d9488;border-bottom-color:#0d9488}
.maint-tabs a:hover{color:#0f172a}
.maint-tab-badge{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;border-radius:99px;font-size:.65rem;font-weight:700;padding:0 5px}
.maint-tab-badge.red{background:#fee2e2;color:#dc2626}
.maint-tab-badge.amber{background:#fef3c7;color:#b45309}
.maint-tab-badge.blue{background:#dbeafe;color:#1d4ed8}
.maint-tab-badge.gray{background:#f1f5f9;color:#64748b}

/* ── Items de liste ── */
.maint-list{display:flex;flex-direction:column;gap:0}
.maint-item{display:flex;align-items:center;gap:14px;padding:13px 16px;border-bottom:1px solid #f8fafc;transition:background .12s;position:relative}
.maint-item:last-child{border-bottom:none}
.maint-item:hover{background:#fafbfc}
.maint-item.urgent{background:#fff8f8}
.maint-item.urgent:hover{background:#fff2f2}
.maint-item.doc-alerte{background:#fffbeb}
.maint-item.doc-alerte:hover{background:#fef9e0}

/* Icône type */
.maint-ico{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}

/* Infos */
.maint-veh{font-size:.88rem;font-weight:700;color:#0f172a}
.maint-type{font-size:.82rem;font-weight:600;color:#475569}
.maint-sub{font-size:.75rem;color:#94a3b8;margin-top:2px;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.maint-sub span{display:flex;align-items:center;gap:3px}

/* Statut badge */
.maint-statut{flex-shrink:0;font-size:.7rem;font-weight:700;padding:3px 9px;border-radius:99px}
.maint-statut.retard{background:#fee2e2;color:#dc2626}
.maint-statut.planifie{background:#dbeafe;color:#1d4ed8}
.maint-statut.bientot{background:#fef3c7;color:#b45309}
.maint-statut.expire{background:#fee2e2;color:#dc2626}
.maint-statut.ok{background:#d1fae5;color:#059669}

/* Actions */
.maint-actions{display:flex;gap:4px;flex-shrink:0}

/* Barre de km */
.km-bar{height:4px;background:#e2e8f0;border-radius:2px;margin-top:4px;overflow:hidden;width:100%;max-width:120px}
.km-bar-fill{height:4px;border-radius:2px}

/* Filtre */
.maint-filter{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:10px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0}
.maint-filter .form-control{height:32px;padding:0 8px;font-size:.8rem;min-width:0}

/* Vide */
.maint-empty{text-align:center;padding:48px 20px;color:#94a3b8}
.maint-empty i{font-size:2.5rem;display:block;margin-bottom:12px;opacity:.4}

/* Responsive */
@media(max-width:768px){
    .maint-kpi{grid-template-columns:repeat(2,1fr)}
    .maint-item{flex-wrap:wrap;gap:8px;padding:12px}
    .maint-ico{width:32px;height:32px;font-size:.75rem}
    .maint-actions{width:100%;justify-content:flex-end;padding-top:6px;border-top:1px solid #f1f5f9}
    .maint-filter{gap:6px}
    .maint-filter .form-control{font-size:.78rem;height:30px}
    .km-bar{max-width:80px}
}
@media(max-width:480px){
    .maint-kpi{grid-template-columns:repeat(2,1fr);gap:8px}
    .maint-kpi-card{padding:10px 12px}
    .maint-kpi-card .mk-val{font-size:1.1rem}
}
</style>

<div class="page-header" style="margin-bottom:12px">
    <div>
        <h1 class="page-title"><i class="fas fa-tools"></i> Maintenances</h1>
        <p class="page-subtitle" style="margin:0">Planification et suivi des entretiens de flotte</p>
    </div>
    <button class="btn btn-primary btn-sm" onclick="openModal('modal-add')">
        <i class="fas fa-plus"></i> Planifier
    </button>
</div>

<?= renderFlashes() ?>

<!-- ── KPIs ──────────────────────────────────────────────────────────────────── -->
<div class="maint-kpi">
    <div class="maint-kpi-card" style="--kc:#ef4444">
        <div class="mk-val"><?= (int)$kpi['nb_retard'] ?></div>
        <div class="mk-lbl">En retard</div>
        <i class="fas fa-exclamation-circle mk-ico" style="color:#ef4444"></i>
    </div>
    <div class="maint-kpi-card" style="--kc:#f59e0b">
        <div class="mk-val"><?= (int)$kpi['nb_planif'] ?></div>
        <div class="mk-lbl">Planifiées</div>
        <i class="fas fa-clock mk-ico" style="color:#f59e0b"></i>
    </div>
    <div class="maint-kpi-card" style="--kc:#10b981">
        <div class="mk-val"><?= (int)$kpi['nb_termine'] ?></div>
        <div class="mk-lbl">Terminées</div>
        <i class="fas fa-check-circle mk-ico" style="color:#10b981"></i>
    </div>
    <div class="maint-kpi-card" style="--kc:#dc2626">
        <div class="mk-val"><?= $nbDocs ?></div>
        <div class="mk-lbl">Alertes docs</div>
        <i class="fas fa-id-card mk-ico" style="color:#dc2626"></i>
    </div>
</div>

<!-- ── Carte principale ──────────────────────────────────────────────────────── -->
<div class="card" style="overflow:hidden">

    <!-- Tabs -->
    <div class="maint-tabs">
        <?php
        $tabUrl = fn($t) => BASE_URL . 'app/maintenances/index.php?' . http_build_query(array_filter(['tab'=>$t,'vehicule_id'=>$filterVeh?:'','type'=>$filterType],'strlen'));
        ?>
        <a href="<?= $tabUrl('actif') ?>" class="<?= $tab==='actif'?'active':'' ?>">
            Actives
            <?php if ($nbActif>0): ?><span class="maint-tab-badge <?= $nbRetard>0?'red':'blue' ?>"><?= $nbActif ?></span><?php endif ?>
        </a>
        <a href="<?= $tabUrl('termine') ?>" class="<?= $tab==='termine'?'active':'' ?>">
            Terminées
            <?php if ($nbTermine>0): ?><span class="maint-tab-badge gray"><?= $nbTermine ?></span><?php endif ?>
        </a>
        <a href="<?= $tabUrl('alertes') ?>" class="<?= $tab==='alertes'?'active':'' ?>">
            Alertes docs
            <?php if ($nbDocs>0): ?><span class="maint-tab-badge red"><?= $nbDocs ?></span><?php endif ?>
        </a>
    </div>

    <!-- Filtres -->
    <div class="maint-filter">
        <form method="GET" style="display:contents">
            <input type="hidden" name="tab" value="<?= sanitize($tab) ?>">
            <select name="vehicule_id" class="form-control" onchange="this.form.submit()">
                <option value="">Tous les véhicules</option>
                <?php foreach ($vehicules as $v): ?>
                <option value="<?= $v['id'] ?>" <?= $filterVeh==$v['id']?'selected':'' ?>>
                    <?= sanitize($v['nom']) ?> — <?= sanitize($v['immatriculation']) ?>
                </option>
                <?php endforeach ?>
            </select>
            <?php if ($tab !== 'alertes'): ?>
            <select name="type" class="form-control" onchange="this.form.submit()">
                <option value="">Tous types</option>
                <?php foreach (['vidange'=>'Vidange','revision'=>'Révision','pneus'=>'Pneus','freins'=>'Freins','batterie'=>'Batterie','autre'=>'Autre'] as $val=>$lbl): ?>
                <option value="<?= $val ?>" <?= $filterType===$val?'selected':'' ?>><?= $lbl ?></option>
                <?php endforeach ?>
            </select>
            <?php endif ?>
            <?php if ($filterVeh || $filterType): ?>
            <a href="<?= BASE_URL ?>app/maintenances/index.php?tab=<?= $tab ?>" class="btn btn-ghost btn-sm">
                <i class="fas fa-times"></i>
            </a>
            <?php endif ?>
        </form>
    </div>

    <?php
    $typeIcons = [
        'vidange'   => ['icon'=>'fa-oil-can',              'color'=>'#d97706','bg'=>'#fef3c7'],
        'revision'  => ['icon'=>'fa-screwdriver-wrench',   'color'=>'#7c3aed','bg'=>'#ede9fe'],
        'pneus'     => ['icon'=>'fa-circle',               'color'=>'#0891b2','bg'=>'#cffafe'],
        'freins'    => ['icon'=>'fa-circle-stop',          'color'=>'#dc2626','bg'=>'#fee2e2'],
        'batterie'  => ['icon'=>'fa-battery-half',         'color'=>'#059669','bg'=>'#d1fae5'],
        'autre'     => ['icon'=>'fa-wrench',               'color'=>'#64748b','bg'=>'#f1f5f9'],
    ];
    ?>

    <!-- ── TAB : Actives ──────────────────────────────────────────────────── -->
    <?php if ($tab === 'actif'): ?>
    <div class="maint-list">
    <?php if (empty($maintsActives)): ?>
        <div class="maint-empty">
            <i class="fas fa-tools"></i>
            Aucune maintenance active
            <div style="margin-top:12px">
                <button class="btn btn-primary btn-sm" onclick="openModal('modal-add')">
                    <i class="fas fa-plus"></i> Planifier la première
                </button>
            </div>
        </div>
    <?php else: ?>
    <?php foreach ($maintsActives as $m):
        $isRetard = $m['statut'] === 'en_retard';
        $kmActuel = (int)$m['kilometrage_actuel'];
        $kmPrevu  = (int)$m['km_prevu'];
        $restants = $kmPrevu > 0 ? ($kmPrevu - $kmActuel) : null;
        $pct = ($kmPrevu > 0 && $kmActuel > 0) ? min(100, round($kmActuel / $kmPrevu * 100)) : 0;
        $typeKey  = strtolower($m['type']);
        $ti = $typeIcons[$typeKey] ?? $typeIcons['autre'];
    ?>
    <div class="maint-item <?= $isRetard ? 'urgent' : '' ?>">
        <!-- Icône type -->
        <div class="maint-ico" style="background:<?= $ti['bg'] ?>;color:<?= $ti['color'] ?>">
            <i class="fas <?= $ti['icon'] ?>"></i>
        </div>
        <!-- Infos -->
        <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span class="maint-veh"><?= sanitize($m['vehicule_nom']) ?></span>
                <span style="color:#94a3b8;font-size:.78rem"><?= sanitize($m['immatriculation']) ?></span>
                <span class="maint-statut <?= $isRetard ? 'retard' : 'planifie' ?>">
                    <?= $isRetard ? 'En retard' : 'Planifié' ?>
                </span>
            </div>
            <div class="maint-type"><?= sanitize(ucfirst($m['type'])) ?>
                <?php if ($m['technicien']): ?>
                <span style="font-weight:400;color:#94a3b8"> · <?= sanitize($m['technicien']) ?></span>
                <?php endif ?>
            </div>
            <div class="maint-sub">
                <?php if ($kmPrevu > 0): ?>
                <span><i class="fas fa-gauge"></i>
                    <?= number_format($kmActuel,0,',',' ') ?> / <?= number_format($kmPrevu,0,',',' ') ?> km
                    <?php if ($restants !== null): ?>
                    <span style="color:<?= $restants<=0?'#ef4444':($restants<=500?'#f59e0b':'#94a3b8') ?>;font-size:.7rem">
                        (<?= $restants>0 ? '+'.number_format($restants,0,',',' ').' restants' : number_format(abs($restants),0,',',' ').' dépassé' ?>)
                    </span>
                    <?php endif ?>
                </span>
                <?php if ($kmPrevu > 0): ?>
                <div class="km-bar"><div class="km-bar-fill" style="width:<?= $pct ?>%;background:<?= $pct>=100?'#ef4444':($pct>=85?'#f59e0b':'#14b8a6') ?>"></div></div>
                <?php endif ?>
                <?php endif ?>
                <?php if ($m['date_prevue']): ?>
                <span><i class="fas fa-calendar"></i> <?= formatDate($m['date_prevue']) ?></span>
                <?php endif ?>
                <?php if ($m['cout'] > 0): ?>
                <span><i class="fas fa-tag"></i> <?= formatMoney((float)$m['cout']) ?></span>
                <?php endif ?>
            </div>
        </div>
        <!-- Actions -->
        <div class="maint-actions">
            <button class="btn btn-sm btn-success" title="Terminer"
                onclick="ouvrirTerminer(<?= $m['id'] ?>,'<?= addslashes(sanitize($m['vehicule_nom'])) ?>','<?= addslashes(sanitize($m['type'])) ?>',<?= $kmActuel ?>,<?= (float)($m['cout']??0) ?>,<?= strtolower($m['type'])==='vidange'?'true':'false' ?>)">
                <i class="fas fa-check"></i><span class="btn-txt"> Terminer</span>
            </button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer ?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="supprimer">
                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                <button type="submit" class="btn btn-sm btn-ghost" title="Supprimer"><i class="fas fa-trash" style="color:#ef4444"></i></button>
            </form>
        </div>
    </div>
    <?php endforeach ?>
    <?php endif ?>
    </div>

    <!-- ── TAB : Terminées ──────────────────────────────────────────────────── -->
    <?php elseif ($tab === 'termine'): ?>
    <div class="maint-list">
    <?php if (empty($maintsTerminees)): ?>
        <div class="maint-empty"><i class="fas fa-check-circle"></i>Aucune maintenance terminée</div>
    <?php else: ?>
    <?php foreach ($maintsTerminees as $m):
        $typeKey = strtolower($m['type']);
        $ti = $typeIcons[$typeKey] ?? ['icon'=>'fa-wrench','color'=>'#64748b','bg'=>'#f1f5f9'];
    ?>
    <div class="maint-item">
        <div class="maint-ico" style="background:<?= $ti['bg'] ?>;color:<?= $ti['color'] ?>">
            <i class="fas <?= $ti['icon'] ?>"></i>
        </div>
        <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span class="maint-veh"><?= sanitize($m['vehicule_nom']) ?></span>
                <span style="color:#94a3b8;font-size:.78rem"><?= sanitize($m['immatriculation']) ?></span>
                <span class="maint-statut ok">Terminé</span>
            </div>
            <div class="maint-type"><?= sanitize(ucfirst($m['type'])) ?>
                <?php if ($m['technicien']): ?><span style="font-weight:400;color:#94a3b8"> · <?= sanitize($m['technicien']) ?></span><?php endif ?>
            </div>
            <div class="maint-sub">
                <?php if ($m['km_fait']): ?><span><i class="fas fa-gauge"></i> <?= number_format((int)$m['km_fait'],0,',',' ') ?> km</span><?php endif ?>
                <?php if ($m['cout'] > 0): ?><span><i class="fas fa-tag"></i> <?= formatMoney((float)$m['cout']) ?></span><?php endif ?>
                <?php if ($m['date_prevue']): ?><span><i class="fas fa-calendar-check"></i> <?= formatDate($m['date_prevue']) ?></span><?php endif ?>
            </div>
        </div>
        <?php if (!empty($m['facture'])): ?>
        <a href="<?= BASE_URL ?>uploads/documents/<?= sanitize($m['facture']) ?>" target="_blank" class="btn btn-sm btn-ghost" title="Facture">
            <i class="fas fa-file-invoice"></i>
        </a>
        <?php endif ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer ?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="supprimer">
            <input type="hidden" name="id" value="<?= $m['id'] ?>">
            <button type="submit" class="btn btn-sm btn-ghost" title="Supprimer"><i class="fas fa-trash" style="color:#ef4444"></i></button>
        </form>
    </div>
    <?php endforeach ?>
    <?php endif ?>
    </div>

    <!-- ── TAB : Alertes docs ───────────────────────────────────────────────── -->
    <?php elseif ($tab === 'alertes'): ?>
    <div class="maint-list">
    <?php if (empty($docAlertes)): ?>
        <div class="maint-empty">
            <i class="fas fa-shield-check"></i>
            Tous les documents sont à jour
        </div>
    <?php else: ?>
    <?php foreach ($docAlertes as $doc):
        $aExp = $doc['date_expiration_assurance'];
        $vExp = $doc['date_expiration_vignette'];
        $aExpired = $aExp && $aExp < $today;
        $vExpired = $vExp && $vExp < $today;
        $aSoon = $aExp && !$aExpired;
        $vSoon = $vExp && !$vExpired;
        $isExpired = $aExpired || $vExpired;
        $details = [];
        if ($aExpired) $details[] = ['icon'=>'fa-shield-halved','color'=>'#dc2626','txt'=>'Assurance expirée le '.formatDate($aExp)];
        elseif ($aSoon) { $d=(int)round((strtotime($aExp)-strtotime($today))/86400); $details[]=['icon'=>'fa-shield-halved','color'=>'#b45309','txt'=>"Assurance dans {$d}j (".formatDate($aExp).')']; }
        if ($vExpired) $details[] = ['icon'=>'fa-stamp','color'=>'#dc2626','txt'=>'Vignette expirée le '.formatDate($vExp)];
        elseif ($vSoon) { $d=(int)round((strtotime($vExp)-strtotime($today))/86400); $details[]=['icon'=>'fa-stamp','color'=>'#b45309','txt'=>"Vignette dans {$d}j (".formatDate($vExp).')']; }
    ?>
    <div class="maint-item doc-alerte" style="<?= $isExpired?'background:#fff8f8':'' ?>">
        <div class="maint-ico" style="background:<?= $isExpired?'#fee2e2':'#fef3c7' ?>;color:<?= $isExpired?'#dc2626':'#b45309' ?>">
            <i class="fas fa-id-card"></i>
        </div>
        <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span class="maint-veh"><?= sanitize($doc['nom']) ?></span>
                <span style="color:#94a3b8;font-size:.78rem"><?= sanitize($doc['immatriculation']) ?></span>
                <span class="maint-statut <?= $isExpired?'expire':'bientot' ?>"><?= $isExpired?'Expiré':'Bientôt' ?></span>
            </div>
            <div class="maint-sub" style="margin-top:5px;gap:10px">
                <?php foreach ($details as $dt): ?>
                <span style="color:<?= $dt['color'] ?>"><i class="fas <?= $dt['icon'] ?>"></i> <?= $dt['txt'] ?></span>
                <?php endforeach ?>
            </div>
        </div>
        <div class="maint-actions">
            <a href="<?= BASE_URL ?>app/vehicules/detail.php?id=<?= $doc['id'] ?>" class="btn btn-sm btn-ghost" title="Voir le véhicule">
                <i class="fas fa-eye"></i><span class="btn-txt"> Voir</span>
            </a>
        </div>
    </div>
    <?php endforeach ?>
    <?php endif ?>
    </div>
    <?php endif ?>

</div><!-- /card -->

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL — Planifier maintenance
═══════════════════════════════════════════════════════════════════════════ -->
<div id="modal-add" class="modal-overlay">
    <div class="modal" style="max-width:560px">
        <div class="modal-header" style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
            <h3 style="margin:0;font-size:1rem"><i class="fas fa-plus" style="color:#0d9488"></i> Planifier une maintenance</h3>
            <button class="modal-close" onclick="closeModal('modal-add')" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#64748b">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="ajouter">
            <div class="form-group">
                <label class="form-label">Véhicule *</label>
                <select name="vehicule_id" class="form-control" required>
                    <option value="">— Sélectionner —</option>
                    <?php foreach ($vehicules as $v): ?>
                    <option value="<?= $v['id'] ?>" <?= $filterVeh==$v['id']?'selected':'' ?>>
                        <?= sanitize($v['nom']) ?> — <?= sanitize($v['immatriculation']) ?> (<?= number_format((int)$v['kilometrage_actuel'],0,',',' ') ?> km)
                    </option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Type d'entretien *</label>
                    <input type="text" name="type" class="form-control" list="types-maint" placeholder="Ex: Vidange..." required>
                    <datalist id="types-maint">
                        <?php foreach (['Vidange','Révision','Pneus','Freins','Batterie','Filtre à air','Courroie de distribution','Climatisation','Carrosserie'] as $t): ?>
                        <option value="<?= $t ?>">
                        <?php endforeach ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label class="form-label">Technicien / Garage</label>
                    <input type="text" name="technicien" class="form-control" placeholder="Nom du technicien">
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Km prévu</label>
                    <input type="number" name="km_prevu" class="form-control" min="0" placeholder="Ex: 85000">
                </div>
                <div class="form-group">
                    <label class="form-label">Date prévue</label>
                    <input type="date" name="date_prevue" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Coût estimé (FCFA)</label>
                <input type="number" name="cout" class="form-control" min="0" step="500" placeholder="0">
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Pièces, remarques..."></textarea>
            </div>
            <div class="form-actions" style="margin-top:16px">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Planifier</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('modal-add')">Annuler</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL — Terminer maintenance
═══════════════════════════════════════════════════════════════════════════ -->
<div id="modal-terminer" class="modal-overlay">
    <div class="modal" style="max-width:500px">
        <div class="modal-header" style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
            <h3 style="margin:0;font-size:1rem"><i class="fas fa-check-circle" style="color:#10b981"></i> Terminer la maintenance</h3>
            <button class="modal-close" onclick="closeModal('modal-terminer')" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#64748b">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="terminer">
            <input type="hidden" name="id" id="term_id">
            <div id="term_info" style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:.875rem;color:#0c4a6e"></div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Km à l'entretien *</label>
                    <input type="number" name="km_fait" id="term_km" class="form-control" min="0" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Coût réel (FCFA)</label>
                    <input type="number" name="cout_reel" id="term_cout" class="form-control" min="0" step="500" placeholder="0">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Technicien / Garage</label>
                <input type="text" name="technicien" id="term_technicien" class="form-control" placeholder="Nom">
            </div>
            <div class="form-group">
                <label class="form-label">Facture (photo ou PDF)</label>
                <input type="file" name="facture" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
            </div>
            <div id="sect_prochaine_vidange" style="display:none;background:#f0fff4;border:1px solid #bbf7d0;border-radius:6px;padding:10px 14px;margin-bottom:12px">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.875rem">
                    <input type="checkbox" name="planifier_prochaine" value="1" checked>
                    <span>Planifier la prochaine vidange dans <strong>5 000 km</strong></span>
                </label>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Confirmer</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('modal-terminer')">Annuler</button>
            </div>
        </form>
    </div>
</div>

<style>
/* cacher texte bouton sur mobile pour économiser l'espace */
@media(max-width:480px){.btn-txt{display:none}}
</style>
<script>
<?php
// Définir typeIcons en JS pour utilisation dans la modal
$typeIconsJs = [
    'vidange'=>['icon'=>'fa-oil-can','color'=>'#d97706'],
    'revision'=>['icon'=>'fa-screwdriver-wrench','color'=>'#7c3aed'],
    'pneus'=>['icon'=>'fa-circle','color'=>'#0891b2'],
    'freins'=>['icon'=>'fa-circle-stop','color'=>'#dc2626'],
    'batterie'=>['icon'=>'fa-battery-half','color'=>'#059669'],
    'autre'=>['icon'=>'fa-wrench','color'=>'#64748b'],
];
?>
function ouvrirTerminer(id, veh, type, kmActuel, cout, isVidange) {
    document.getElementById('term_id').value = id;
    document.getElementById('term_km').value = kmActuel;
    document.getElementById('term_cout').value = cout > 0 ? cout : '';
    document.getElementById('term_technicien').value = '';
    document.getElementById('term_info').innerHTML =
        '<i class="fas fa-car" style="margin-right:6px"></i>' +
        '<strong>' + veh + '</strong> — ' + type +
        ' &nbsp;·&nbsp; <span style="color:#0369a1">Km actuel : ' + kmActuel.toLocaleString('fr-FR') + ' km</span>';
    document.getElementById('sect_prochaine_vidange').style.display = isVidange ? 'block' : 'none';
    openModal('modal-terminer');
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
