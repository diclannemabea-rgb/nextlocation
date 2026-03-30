<?php
/**
 * FlotteCar — Dashboard Super Admin
 */
define('BASE_PATH', dirname(__DIR__));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';

requireSuperAdmin();
$db = (new Database())->getConnection();

// ── POST HANDLER ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $act = $_POST['action'] ?? '';
    $tid = (int)($_POST['tenant_id'] ?? 0);

    if ($act === 'activer_compte' && $tid) {
        try {
            $forfait = $_POST['forfait'] ?? 'mensuel';
            $duree   = $forfait === 'annuel' ? 365 : 30;
            $prix    = $forfait === 'annuel' ? 150000 : 20000;
            $db->prepare("UPDATE tenants SET actif=1 WHERE id=?")->execute([$tid]);
            $db->prepare("UPDATE abonnements SET statut='expire' WHERE tenant_id=? AND statut='actif'")->execute([$tid]);
            $db->prepare("INSERT INTO abonnements (tenant_id,plan,prix,date_debut,date_fin,statut,created_at) VALUES (?,?,?,CURDATE(),DATE_ADD(CURDATE(),INTERVAL ? DAY),'actif',NOW())")->execute([$tid, 'starter', $prix, $duree]);
            try { $db->prepare("INSERT INTO mouvements_abo (tenant_id,type,montant,description,created_by) VALUES (?,?,?,?,?)")->execute([$tid, 'renouvellement', $prix, "Activation — forfait $forfait", getUserId()]); } catch(\Throwable $e){}
            setFlash(FLASH_SUCCESS, 'Compte activé — forfait ' . $forfait . '.');
        } catch(\Throwable $e) {
            error_log("ACTIVATION ERROR tenant $tid: " . $e->getMessage());
            setFlash(FLASH_ERROR, 'Erreur activation: ' . $e->getMessage());
        }
    }

    if ($act === 'prolonger_abo' && $tid) {
        try {
            $forfait = $_POST['forfait_prolonger'] ?? 'mensuel';
            $duree   = $forfait === 'annuel' ? 365 : 30;
            $prix    = $forfait === 'annuel' ? 150000 : 20000;
            $existStmt = $db->prepare("SELECT id, date_fin FROM abonnements WHERE tenant_id=? AND statut='actif' LIMIT 1");
            $existStmt->execute([$tid]);
            $existAbo = $existStmt->fetch(PDO::FETCH_ASSOC);
            if ($existAbo) {
                $db->prepare("UPDATE abonnements SET date_fin=DATE_ADD(date_fin,INTERVAL ? DAY) WHERE id=?")->execute([$duree, $existAbo['id']]);
            } else {
                $db->prepare("INSERT INTO abonnements (tenant_id,plan,prix,date_debut,date_fin,statut,created_at) VALUES (?,?,?,CURDATE(),DATE_ADD(CURDATE(),INTERVAL ? DAY),'actif',NOW())")->execute([$tid, 'starter', $prix, $duree]);
            }
            // plan stays as-is in tenants (enum: starter/pro/enterprise)
            try { $db->prepare("INSERT INTO mouvements_abo (tenant_id,type,montant,description,created_by) VALUES (?,?,?,?,?)")->execute([$tid, 'renouvellement', $prix, "Renouvellement forfait $forfait", getUserId()]); } catch(\Throwable $e){}
            setFlash(FLASH_SUCCESS, 'Abonnement renouvelé — forfait ' . $forfait . '.');
        } catch(\Throwable $e) {
            error_log("PROLONGATION ERROR tenant $tid: " . $e->getMessage());
            setFlash(FLASH_ERROR, 'Erreur renouvellement: ' . $e->getMessage());
        }
    }

    if ($act === 'ajouter_paiement' && $tid) {
        $montant = (float)($_POST['montant'] ?? 0);
        $mode    = $_POST['mode_paiement'] ?? 'mobile_money';
        $ref     = trim($_POST['reference'] ?? '');
        $desc    = trim($_POST['description'] ?? 'Paiement');
        try { $db->prepare("INSERT INTO mouvements_abo (tenant_id,type,montant,description,mode_paiement,reference,created_by) VALUES (?,?,?,?,?,?,?)")->execute([$tid, 'paiement', $montant, $desc, $mode, $ref, getUserId()]); } catch(\Throwable $e){}
        setFlash(FLASH_SUCCESS, 'Paiement de ' . number_format($montant,0,',',' ') . ' FCFA enregistré.');
    }

    if ($act === 'toggle_actif' && $tid) {
        $cur = (int)$db->query("SELECT actif FROM tenants WHERE id=$tid")->fetchColumn();
        $db->prepare("UPDATE tenants SET actif=? WHERE id=?")->execute([$cur ? 0 : 1, $tid]);
        setFlash(FLASH_SUCCESS, $cur ? 'Compte suspendu.' : 'Compte réactivé.');
    }

    redirect(BASE_URL . 'admin/dashboard.php');
}

// ── DONNÉES ───────────────────────────────────────────────────────────────────
$statsTenants = $db->query("SELECT COUNT(*) total, SUM(actif=1) actifs, SUM(actif=0) inactifs FROM tenants")->fetch(PDO::FETCH_ASSOC);
$nbVehicules  = (int)$db->query("SELECT COUNT(*) FROM vehicules")->fetchColumn();
$revenusMois = 0; $revenusAnnee = 0;
try {
    $revenusMois  = (float)$db->query("SELECT COALESCE(SUM(montant),0) FROM mouvements_abo WHERE type='paiement' AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
    $revenusAnnee = (float)$db->query("SELECT COALESCE(SUM(montant),0) FROM mouvements_abo WHERE type='paiement' AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
} catch(\Throwable $e) {}
$nbExpirant7  = (int)$db->query("SELECT COUNT(*) FROM abonnements WHERE statut='actif' AND date_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)")->fetchColumn();
$nouveauxMois = (int)$db->query("SELECT COUNT(*) FROM tenants WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();

// Comptes en attente
$comptesPendants = $db->query("
    SELECT t.*, u.nom user_nom, u.prenom user_prenom, u.email user_email
    FROM tenants t
    LEFT JOIN users u ON u.tenant_id=t.id AND u.role='tenant_admin'
    WHERE t.actif=0
    ORDER BY t.created_at DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);
$nbPendants = count($comptesPendants);

// Dernières inscriptions (actifs)
$derniersTenants = $db->query("
    SELECT t.*, u.email user_email,
           (SELECT COUNT(*) FROM vehicules v WHERE v.tenant_id=t.id) nb_vehicules,
           a.plan abo_plan, a.date_fin abo_fin
    FROM tenants t
    LEFT JOIN users u ON u.tenant_id=t.id AND u.role='tenant_admin'
    LEFT JOIN abonnements a ON a.tenant_id=t.id AND a.statut='actif'
    WHERE t.actif=1
    ORDER BY t.created_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Abonnements expirant dans 30j
$aboExpirants = $db->query("
    SELECT t.id, t.nom_entreprise, t.telephone, a.plan, a.date_fin,
           DATEDIFF(a.date_fin,CURDATE()) jours_restants
    FROM abonnements a
    JOIN tenants t ON t.id=a.tenant_id
    WHERE a.statut='actif' AND a.date_fin <= DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND t.actif=1
    ORDER BY a.date_fin ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Derniers paiements
$derniersPaiements = [];
try {
    $derniersPaiements = $db->query("SELECT m.*, t.nom_entreprise FROM mouvements_abo m JOIN tenants t ON t.id=m.tenant_id WHERE m.montant>0 ORDER BY m.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch(\Throwable $e) {}

$pageTitle  = 'Administration';
$activePage = 'admin_dashboard';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
.kpi-row { display:grid; grid-template-columns:repeat(6,1fr); gap:12px; margin-bottom:24px; }
@media(max-width:1100px){ .kpi-row{grid-template-columns:repeat(3,1fr)} }
@media(max-width:600px)  { .kpi-row{grid-template-columns:1fr 1fr} }

.kpi { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 14px; text-align:center; border-top:3px solid var(--c,#e2e8f0); }
.kpi .v { font-size:1.6rem; font-weight:900; color:var(--c,#0f172a); line-height:1; }
.kpi .l { font-size:.65rem; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; font-weight:700; margin-top:6px; }

.admin-tabs { display:flex; gap:4px; margin-bottom:16px; flex-wrap:wrap; }
.admin-tabs button { padding:8px 18px; border:1px solid #e2e8f0; background:#fff; border-radius:8px; font-size:.8rem; font-weight:600; color:#64748b; cursor:pointer; transition:all .15s; }
.admin-tabs button.active { background:#0d9488; color:#fff; border-color:#0d9488; }

.tab-panel { display:none; }
.tab-panel.active { display:block; }

.at { width:100%; border-collapse:collapse; font-size:.8rem; }
.at thead th { font-size:.65rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; padding:10px 14px; background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align:left; }
.at tbody td { padding:11px 14px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.at tbody tr:last-child td { border-bottom:none; }
.at tbody tr:hover { background:#f8fafc; }

.tag { display:inline-block; padding:2px 9px; border-radius:99px; font-size:.68rem; font-weight:700; }
.tag-green  { background:#dcfce7; color:#16a34a; }
.tag-red    { background:#fee2e2; color:#dc2626; }
.tag-orange { background:#ffedd5; color:#c2410c; }
.tag-blue   { background:#dbeafe; color:#1d4ed8; }
.tag-purple { background:#f3e8ff; color:#7c3aed; }
.tag-gray   { background:#f1f5f9; color:#64748b; }

.btn-act { display:inline-flex; align-items:center; justify-content:center; gap:5px; padding:5px 12px; border-radius:7px; font-size:.75rem; font-weight:700; border:none; cursor:pointer; text-decoration:none; transition:all .12s; white-space:nowrap; }
.btn-act.green  { background:#dcfce7; color:#16a34a; } .btn-act.green:hover  { background:#16a34a; color:#fff; }
.btn-act.blue   { background:#dbeafe; color:#1d4ed8; } .btn-act.blue:hover   { background:#0d9488; color:#fff; }
.btn-act.orange { background:#ffedd5; color:#c2410c; } .btn-act.orange:hover { background:#ea580c; color:#fff; }
.btn-act.red    { background:#fee2e2; color:#dc2626; } .btn-act.red:hover    { background:#dc2626; color:#fff; }
.btn-act.gray   { background:#f1f5f9; color:#64748b; } .btn-act.gray:hover   { background:#64748b; color:#fff; }

.pending-badge { display:inline-flex; align-items:center; justify-content:center; background:#ef4444; color:#fff; border-radius:50%; width:18px; height:18px; font-size:.65rem; font-weight:800; margin-left:5px; }

.pwd-cell { font-family:monospace; font-size:.75rem; background:#f8fafc; padding:2px 7px; border-radius:5px; border:1px solid #e2e8f0; color:#475569; }

@media(max-width:768px) {
    .page-header { flex-direction:column; gap:10px; }
    .admin-tabs { gap:3px; }
    .admin-tabs button { padding:6px 10px; font-size:.72rem; }
    .at thead { display:none; }
    .at tbody tr { display:block; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:10px; padding:12px 14px; background:#fff; }
    .at tbody td { display:flex; justify-content:space-between; align-items:center; padding:4px 0; border-bottom:none; font-size:.8rem; }
    .at tbody td::before { content:attr(data-label); font-size:.68rem; font-weight:700; color:#94a3b8; text-transform:uppercase; }
    .at tbody tr:hover { background:#fff; }
}
</style>

<?= renderFlashes() ?>

<!-- KPIs -->
<div class="kpi-row">
    <div class="kpi" style="--c:#0d9488"><div class="v"><?= (int)$statsTenants['total'] ?></div><div class="l">Entreprises</div></div>
    <div class="kpi" style="--c:#16a34a"><div class="v"><?= (int)$statsTenants['actifs'] ?></div><div class="l">Actifs</div></div>
    <div class="kpi" style="--c:#ef4444"><div class="v"><?= $nbPendants ?></div><div class="l">En attente</div></div>
    <div class="kpi" style="--c:#f59e0b"><div class="v"><?= $nbExpirant7 ?></div><div class="l">Expirent 7j</div></div>
    <div class="kpi" style="--c:#0891b2"><div class="v"><?= number_format($revenusMois,0,',',' ') ?></div><div class="l">Revenus mois</div></div>
    <div class="kpi" style="--c:#7c3aed"><div class="v"><?= number_format($revenusAnnee,0,',',' ') ?></div><div class="l">Revenus année</div></div>
</div>

<!-- TABS -->
<div class="admin-tabs">
    <button class="active" onclick="switchTab('pending')">
        En attente<?php if ($nbPendants > 0): ?><span class="pending-badge"><?= $nbPendants ?></span><?php endif ?>
    </button>
    <button onclick="switchTab('tenants')">Inscriptions récentes</button>
    <button onclick="switchTab('expirants')">Expirants (<?= count($aboExpirants) ?>)</button>
    <button onclick="switchTab('paiements')">Paiements</button>
</div>

<!-- TAB: En attente -->
<div id="tab-pending" class="tab-panel active">
<div class="card" style="border-radius:14px;overflow:hidden">
<?php if (empty($comptesPendants)): ?>
<div style="text-align:center;padding:48px;color:#94a3b8">
    <div style="font-size:2rem;margin-bottom:8px">✓</div>
    <div style="font-weight:600">Aucun compte en attente</div>
</div>
<?php else: ?>
<div class="table-responsive">
<table class="at">
    <thead>
        <tr>
            <th>#</th>
            <th>Entreprise</th>
            <th>Responsable</th>
            <th>Email / Mot de passe</th>
            <th>Usage</th>
            <th>Inscrit le</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($comptesPendants as $t): ?>
    <tr>
        <td style="color:#cbd5e1;font-size:.72rem">#<?= $t['id'] ?></td>
        <td>
            <div style="font-weight:700;color:#0f172a"><?= sanitize($t['nom_entreprise']) ?></div>
            <?php if (!empty($t['telephone'])): ?>
            <div style="font-size:.72rem;color:#94a3b8"><?= sanitize($t['telephone']) ?></div>
            <?php endif ?>
        </td>
        <td>
            <div style="font-size:.82rem;color:#475569"><?= sanitize(trim(($t['user_prenom']??'').' '.($t['user_nom']??''))) ?></div>
        </td>
        <td>
            <div style="font-size:.78rem;color:#475569"><?= sanitize($t['user_email']??'') ?></div>
        </td>
        <td>
            <?php echo match($t['type_usage']??'les_deux') {
                'location' => '<span class="tag tag-blue">Location</span>',
                'controle' => '<span class="tag tag-purple">GPS</span>',
                default    => '<span class="tag tag-gray">Location + GPS</span>',
            }; ?>
        </td>
        <td style="font-size:.72rem;color:#94a3b8;white-space:nowrap"><?= formatDate($t['created_at']) ?></td>
        <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <a href="<?= BASE_URL ?>admin/tenant_detail.php?id=<?= $t['id'] ?>" class="btn-act blue">Détail</a>
                <button class="btn-act green" onclick="openModal('modal-activer-<?= $t['id'] ?>')">Activer</button>
                <button class="btn-act red" onclick="if(confirm('Supprimer ce compte ?')) document.getElementById('form-suppr-<?= $t['id'] ?>').submit()">Supprimer</button>
                <form id="form-suppr-<?= $t['id'] ?>" method="POST" style="display:none">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle_actif">
                    <input type="hidden" name="tenant_id" value="<?= $t['id'] ?>">
                </form>
            </div>

            <!-- Modal Activer -->
            <div id="modal-activer-<?= $t['id'] ?>" class="modal-overlay">
                <div class="modal-card" style="max-width:440px">
                    <div class="modal-header">
                        <h3>Activer — <?= sanitize($t['nom_entreprise']) ?></h3>
                        <button class="modal-close" onclick="closeModal('modal-activer-<?= $t['id'] ?>')">&times;</button>
                    </div>
                    <form method="POST" style="padding:20px">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="activer_compte">
                        <input type="hidden" name="tenant_id" value="<?= $t['id'] ?>">
                        <p style="color:#64748b;font-size:.85rem;margin-bottom:18px">Choisissez le forfait à activer pour <strong><?= sanitize($t['nom_entreprise']) ?></strong> :</p>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px">
                            <label style="cursor:pointer">
                                <input type="radio" name="forfait" value="mensuel" checked style="display:none" class="radio-forfait">
                                <div class="forfait-card" data-val="mensuel" style="border:2px solid #0d9488;border-radius:12px;padding:16px;text-align:center;background:#eff6ff;transition:all .15s">
                                    <div style="font-size:.7rem;font-weight:700;color:#0d9488;text-transform:uppercase;margin-bottom:6px">Mensuel</div>
                                    <div style="font-size:1.3rem;font-weight:900;color:#0f172a">20 000</div>
                                    <div style="font-size:.72rem;color:#64748b">FCFA / mois</div>
                                </div>
                            </label>
                            <label style="cursor:pointer">
                                <input type="radio" name="forfait" value="annuel" style="display:none" class="radio-forfait">
                                <div class="forfait-card" data-val="annuel" style="border:2px solid #e2e8f0;border-radius:12px;padding:16px;text-align:center;background:#fff;transition:all .15s">
                                    <div style="font-size:.7rem;font-weight:700;color:#7c3aed;text-transform:uppercase;margin-bottom:6px">Annuel</div>
                                    <div style="font-size:1.3rem;font-weight:900;color:#0f172a">150 000</div>
                                    <div style="font-size:.72rem;color:#64748b">FCFA / an</div>
                                    <div style="font-size:.68rem;font-weight:700;color:#16a34a;margin-top:4px">Économie 90 000</div>
                                </div>
                            </label>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('modal-activer-<?= $t['id'] ?>')">Annuler</button>
                            <button type="submit" class="btn btn-primary">Activer le compte</button>
                        </div>
                    </form>
                </div>
            </div>
        </td>
    </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div>
<?php endif ?>
</div>
</div>

<!-- TAB: Inscriptions récentes -->
<div id="tab-tenants" class="tab-panel">
<div class="card" style="border-radius:14px;overflow:hidden">
<div class="table-responsive">
<table class="at">
    <thead>
        <tr>
            <th>#</th>
            <th>Entreprise</th>
            <th>Email / Mot de passe</th>
            <th>Forfait</th>
            <th>Abonnement</th>
            <th>Véhicules</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($derniersTenants as $t): ?>
    <?php
        $jr = $t['abo_fin'] ? (int)((strtotime($t['abo_fin'])-time())/86400) : null;
    ?>
    <tr>
        <td style="color:#cbd5e1;font-size:.72rem">#<?= $t['id'] ?></td>
        <td>
            <div style="font-weight:700;color:#0f172a"><?= sanitize($t['nom_entreprise']) ?></div>
            <?php if (!empty($t['telephone'])): ?>
            <div style="font-size:.72rem;color:#94a3b8"><?= sanitize($t['telephone']) ?></div>
            <?php endif ?>
        </td>
        <td>
            <div style="font-size:.78rem;color:#475569"><?= sanitize($t['user_email']??'') ?></div>
        </td>
        <td>
            <?php $plan = $t['abo_plan'] ?? $t['plan'] ?? '—';
            if ($plan === 'mensuel') echo '<span class="tag tag-blue">Mensuel</span>';
            elseif ($plan === 'annuel') echo '<span class="tag tag-purple">Annuel</span>';
            else echo '<span class="tag tag-gray">' . sanitize($plan) . '</span>';
            ?>
        </td>
        <td>
            <?php if ($jr !== null):
                if ($jr <= 0) echo '<span class="tag tag-red">Expiré</span>';
                elseif ($jr <= 7) echo '<span class="tag tag-orange">'.$jr.' j restants</span>';
                else echo '<span class="tag tag-green">'.$jr.' j restants</span>';
            else: echo '<span style="color:#cbd5e1">—</span>';
            endif ?>
        </td>
        <td style="text-align:center;font-weight:700"><?= (int)$t['nb_vehicules'] ?></td>
        <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <a href="<?= BASE_URL ?>admin/tenant_detail.php?id=<?= $t['id'] ?>" class="btn-act blue">Détail</a>
                <button class="btn-act gray" onclick="openModal('modal-prolong-<?= $t['id'] ?>')">Renouveler</button>
                <button class="btn-act gray" onclick="openModal('modal-paie-<?= $t['id'] ?>')">Paiement</button>
                <button class="btn-act orange" onclick="if(confirm('Suspendre ce compte ?')) document.getElementById('form-tog-<?= $t['id'] ?>').submit()">Suspendre</button>
                <form id="form-tog-<?= $t['id'] ?>" method="POST" style="display:none">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle_actif">
                    <input type="hidden" name="tenant_id" value="<?= $t['id'] ?>">
                </form>
            </div>

            <!-- Modal Renouveler -->
            <div id="modal-prolong-<?= $t['id'] ?>" class="modal-overlay">
                <div class="modal-card" style="max-width:420px">
                    <div class="modal-header">
                        <h3>Renouveler — <?= sanitize($t['nom_entreprise']) ?></h3>
                        <button class="modal-close" onclick="closeModal('modal-prolong-<?= $t['id'] ?>')">&times;</button>
                    </div>
                    <form method="POST" style="padding:20px">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="prolonger_abo">
                        <input type="hidden" name="tenant_id" value="<?= $t['id'] ?>">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px">
                            <label style="cursor:pointer">
                                <input type="radio" name="forfait_prolonger" value="mensuel" checked style="display:none" class="radio-forfait">
                                <div class="forfait-card" data-val="mensuel" style="border:2px solid #0d9488;border-radius:12px;padding:14px;text-align:center;background:#eff6ff">
                                    <div style="font-size:.7rem;font-weight:700;color:#0d9488;text-transform:uppercase;margin-bottom:4px">Mensuel</div>
                                    <div style="font-size:1.2rem;font-weight:900;color:#0f172a">20 000 FCFA</div>
                                    <div style="font-size:.72rem;color:#64748b">+30 jours</div>
                                </div>
                            </label>
                            <label style="cursor:pointer">
                                <input type="radio" name="forfait_prolonger" value="annuel" style="display:none" class="radio-forfait">
                                <div class="forfait-card" data-val="annuel" style="border:2px solid #e2e8f0;border-radius:12px;padding:14px;text-align:center;background:#fff">
                                    <div style="font-size:.7rem;font-weight:700;color:#7c3aed;text-transform:uppercase;margin-bottom:4px">Annuel</div>
                                    <div style="font-size:1.2rem;font-weight:900;color:#0f172a">150 000 FCFA</div>
                                    <div style="font-size:.72rem;color:#64748b">+365 jours</div>
                                </div>
                            </label>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('modal-prolong-<?= $t['id'] ?>')">Annuler</button>
                            <button type="submit" class="btn btn-primary">Renouveler</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Paiement -->
            <div id="modal-paie-<?= $t['id'] ?>" class="modal-overlay">
                <div class="modal-card" style="max-width:420px">
                    <div class="modal-header">
                        <h3>Paiement — <?= sanitize($t['nom_entreprise']) ?></h3>
                        <button class="modal-close" onclick="closeModal('modal-paie-<?= $t['id'] ?>')">&times;</button>
                    </div>
                    <form method="POST" style="padding:20px">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="ajouter_paiement">
                        <input type="hidden" name="tenant_id" value="<?= $t['id'] ?>">
                        <div class="form-group">
                            <label class="form-label">Montant (FCFA)</label>
                            <input type="number" name="montant" class="form-control" placeholder="20000" min="0" required>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                            <div class="form-group">
                                <label class="form-label">Mode</label>
                                <select name="mode_paiement" class="form-control">
                                    <option value="mobile_money">Mobile Money</option>
                                    <option value="especes">Espèces</option>
                                    <option value="virement">Virement</option>
                                    <option value="wave">Wave</option>
                                    <option value="orange_money">Orange Money</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Référence</label>
                                <input type="text" name="reference" class="form-control" placeholder="TXN123">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Note</label>
                            <input type="text" name="description" class="form-control" placeholder="ex: Renouvellement mensuel mars">
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('modal-paie-<?= $t['id'] ?>')">Annuler</button>
                            <button type="submit" class="btn btn-primary">Enregistrer</button>
                        </div>
                    </form>
                </div>
            </div>
        </td>
    </tr>
    <?php endforeach ?>
    <?php if (empty($derniersTenants)): ?>
    <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8">Aucune inscription</td></tr>
    <?php endif ?>
    </tbody>
</table>
</div>
<div style="padding:10px 16px;border-top:1px solid #f1f5f9;text-align:right">
    <a href="<?= BASE_URL ?>admin/tenants.php" class="btn-act blue">Voir tous les comptes</a>
</div>
</div>
</div>

<!-- TAB: Expirants -->
<div id="tab-expirants" class="tab-panel">
<div class="card" style="border-radius:14px;overflow:hidden">
<?php if (empty($aboExpirants)): ?>
<div style="text-align:center;padding:48px;color:#94a3b8">
    <div style="font-weight:600">Aucun abonnement expirant dans 30 jours</div>
</div>
<?php else: ?>
<div class="table-responsive">
<table class="at">
    <thead>
        <tr><th>Entreprise</th><th>Téléphone</th><th>Forfait</th><th>Fin</th><th>Restant</th><th>Action</th></tr>
    </thead>
    <tbody>
    <?php foreach ($aboExpirants as $e): ?>
    <tr>
        <td style="font-weight:700;color:#0f172a"><?= sanitize($e['nom_entreprise']) ?></td>
        <td style="font-size:.78rem;color:#64748b"><?= sanitize($e['telephone']??'—') ?></td>
        <td>
            <?php if ($e['plan']==='mensuel') echo '<span class="tag tag-blue">Mensuel</span>';
            elseif ($e['plan']==='annuel') echo '<span class="tag tag-purple">Annuel</span>';
            else echo '<span class="tag tag-gray">'.sanitize($e['plan']).'</span>'; ?>
        </td>
        <td style="font-size:.8rem;color:#64748b"><?= formatDate($e['date_fin']) ?></td>
        <td>
            <?php $jr = (int)$e['jours_restants'];
            if ($jr <= 0) echo '<span class="tag tag-red">Expiré</span>';
            elseif ($jr <= 7) echo '<span class="tag tag-red">'.$jr.' j</span>';
            elseif ($jr <= 15) echo '<span class="tag tag-orange">'.$jr.' j</span>';
            else echo '<span class="tag tag-blue">'.$jr.' j</span>'; ?>
        </td>
        <td>
            <button class="btn-act blue" onclick="openModal('modal-exp-prolong-<?= $e['id'] ?>')">Renouveler</button>
            <div id="modal-exp-prolong-<?= $e['id'] ?>" class="modal-overlay">
                <div class="modal-card" style="max-width:420px">
                    <div class="modal-header">
                        <h3>Renouveler — <?= sanitize($e['nom_entreprise']) ?></h3>
                        <button class="modal-close" onclick="closeModal('modal-exp-prolong-<?= $e['id'] ?>')">&times;</button>
                    </div>
                    <form method="POST" style="padding:20px">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="prolonger_abo">
                        <input type="hidden" name="tenant_id" value="<?= $e['id'] ?>">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px">
                            <label style="cursor:pointer"><input type="radio" name="forfait_prolonger" value="mensuel" checked style="display:none" class="radio-forfait">
                                <div class="forfait-card" data-val="mensuel" style="border:2px solid #0d9488;border-radius:12px;padding:14px;text-align:center;background:#eff6ff">
                                    <div style="font-size:.7rem;font-weight:700;color:#0d9488;text-transform:uppercase;margin-bottom:4px">Mensuel</div>
                                    <div style="font-size:1.2rem;font-weight:900;color:#0f172a">20 000 FCFA</div>
                                    <div style="font-size:.72rem;color:#64748b">+30 jours</div>
                                </div>
                            </label>
                            <label style="cursor:pointer"><input type="radio" name="forfait_prolonger" value="annuel" style="display:none" class="radio-forfait">
                                <div class="forfait-card" data-val="annuel" style="border:2px solid #e2e8f0;border-radius:12px;padding:14px;text-align:center;background:#fff">
                                    <div style="font-size:.7rem;font-weight:700;color:#7c3aed;text-transform:uppercase;margin-bottom:4px">Annuel</div>
                                    <div style="font-size:1.2rem;font-weight:900;color:#0f172a">150 000 FCFA</div>
                                    <div style="font-size:.72rem;color:#64748b">+365 jours</div>
                                </div>
                            </label>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('modal-exp-prolong-<?= $e['id'] ?>')">Annuler</button>
                            <button type="submit" class="btn btn-primary">Renouveler</button>
                        </div>
                    </form>
                </div>
            </div>
        </td>
    </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div>
<?php endif ?>
</div>
</div>

<!-- TAB: Paiements -->
<div id="tab-paiements" class="tab-panel">
<div class="card" style="border-radius:14px;overflow:hidden">
<?php if (empty($derniersPaiements)): ?>
<div style="text-align:center;padding:48px;color:#94a3b8"><div style="font-weight:600">Aucun paiement enregistré</div></div>
<?php else: ?>
<div class="table-responsive">
<table class="at">
    <thead>
        <tr><th>Date</th><th>Entreprise</th><th>Montant</th><th>Mode</th><th>Référence</th><th>Note</th></tr>
    </thead>
    <tbody>
    <?php foreach ($derniersPaiements as $p): ?>
    <tr>
        <td style="font-size:.75rem;color:#94a3b8;white-space:nowrap"><?= formatDatetime($p['created_at']) ?></td>
        <td style="font-weight:600;color:#0f172a"><?= sanitize($p['nom_entreprise']) ?></td>
        <td><span class="tag tag-green"><?= number_format((float)$p['montant'],0,',',' ') ?> FCFA</span></td>
        <td style="font-size:.78rem;color:#64748b"><?= sanitize($p['mode_paiement']??'—') ?></td>
        <td style="font-size:.75rem;font-family:monospace;color:#64748b"><?= sanitize($p['reference']??'—') ?></td>
        <td style="font-size:.78rem;color:#94a3b8"><?= sanitize($p['description']??'') ?></td>
    </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div>
<?php endif ?>
</div>
</div>

<script>
function switchTab(name) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.admin-tabs button').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    event.currentTarget.classList.add('active');
}

// Forfait cards radio behavior
document.addEventListener('click', function(e) {
    const card = e.target.closest('.forfait-card');
    if (!card) return;
    const val = card.dataset.val;
    const container = card.closest('form') || card.closest('.modal-card');
    if (!container) return;
    container.querySelectorAll('.forfait-card').forEach(c => {
        const isSelected = c.dataset.val === val;
        if (c.dataset.val === 'mensuel') {
            c.style.borderColor = isSelected ? '#0d9488' : '#e2e8f0';
            c.style.background  = isSelected ? '#eff6ff' : '#fff';
        } else {
            c.style.borderColor = isSelected ? '#7c3aed' : '#e2e8f0';
            c.style.background  = isSelected ? '#f5f3ff' : '#fff';
        }
    });
    const radio = container.querySelector('input[type=radio][value="'+val+'"]');
    if (radio) radio.checked = true;
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
