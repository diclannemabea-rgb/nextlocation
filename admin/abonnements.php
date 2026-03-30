<?php
/**
 * FlotteCar — Abonnements & Paiements (Super Admin)
 * Seulement 2 forfaits : Mensuel (20 000 FCFA) et Annuel (150 000 FCFA)
 */
define('BASE_PATH', dirname(__DIR__));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireSuperAdmin();

$db = (new Database())->getConnection();

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'activer') {
        $tid     = (int)$_POST['tenant_id'];
        $forfait = $_POST['forfait'] ?? 'mensuel';
        $duree   = $forfait === 'annuel' ? 365 : 30;
        $prix    = $forfait === 'annuel' ? 150000 : 20000;
        $db->prepare("UPDATE tenants SET actif=1, plan=?, updated_at=NOW() WHERE id=?")->execute([$forfait,$tid]);
        $db->prepare("UPDATE abonnements SET statut='expire' WHERE tenant_id=? AND statut='actif'")->execute([$tid]);
        $db->prepare("INSERT INTO abonnements (tenant_id,plan,prix,date_debut,date_fin,statut,created_at) VALUES (?,?,?,CURDATE(),DATE_ADD(CURDATE(),INTERVAL ? DAY),'actif',NOW())")->execute([$tid,$forfait,$prix,$duree]);
        try { $db->prepare("INSERT INTO mouvements_abo (tenant_id,type,montant,description,created_by) VALUES (?,?,?,?,?)")->execute([$tid,'renouvellement',$prix,"Activation forfait $forfait",getUserId()]); } catch(\Throwable $e){}
        setFlash(FLASH_SUCCESS, 'Compte activé — forfait ' . $forfait . '.');
    }

    if ($action === 'renouveler') {
        $aboId   = (int)$_POST['abo_id'];
        $tid     = (int)$_POST['tenant_id'];
        $forfait = $_POST['forfait'] ?? 'mensuel';
        $duree   = $forfait === 'annuel' ? 365 : 30;
        $prix    = $forfait === 'annuel' ? 150000 : 20000;
        if ($aboId) {
            $db->prepare("UPDATE abonnements SET date_fin=DATE_ADD(date_fin,INTERVAL ? DAY),plan=?,updated_at=NOW() WHERE id=?")->execute([$duree,$forfait,$aboId]);
        } else {
            $db->prepare("INSERT INTO abonnements (tenant_id,plan,prix,date_debut,date_fin,statut,created_at) VALUES (?,?,?,CURDATE(),DATE_ADD(CURDATE(),INTERVAL ? DAY),'actif',NOW())")->execute([$tid,$forfait,$prix,$duree]);
        }
        $db->prepare("UPDATE tenants SET plan=? WHERE id=?")->execute([$forfait,$tid]);
        try { $db->prepare("INSERT INTO mouvements_abo (tenant_id,type,montant,description,created_by) VALUES (?,?,?,?,?)")->execute([$tid,'renouvellement',$prix,"Renouvellement forfait $forfait",getUserId()]); } catch(\Throwable $e){}
        setFlash(FLASH_SUCCESS, 'Abonnement renouvelé — forfait ' . $forfait . '.');
    }

    if ($action === 'paiement') {
        $tid     = (int)$_POST['tenant_id'];
        $montant = (float)($_POST['montant'] ?? 0);
        $mode    = $_POST['mode'] ?? 'mobile_money';
        $ref     = trim($_POST['reference'] ?? '');
        $desc    = trim($_POST['description'] ?? 'Paiement');
        try { $db->prepare("INSERT INTO mouvements_abo (tenant_id,type,montant,description,mode_paiement,reference,created_by) VALUES (?,?,?,?,?,?,?)")->execute([$tid,'paiement',$montant,$desc,$mode,$ref,getUserId()]); } catch(\Throwable $e){}
        setFlash(FLASH_SUCCESS, 'Paiement de ' . number_format($montant,0,',',' ') . ' FCFA enregistré.');
    }

    if ($action === 'suspendre') {
        $aboId = (int)$_POST['abo_id'];
        $db->prepare("UPDATE abonnements SET statut='expire',updated_at=NOW() WHERE id=?")->execute([$aboId]);
        setFlash(FLASH_WARNING, 'Abonnement suspendu.');
    }

    redirect(BASE_URL . 'admin/abonnements.php?' . http_build_query(array_filter(['q'=>$_GET['q']??'','statut'=>$_GET['statut']??''])));
}

// ── DONNÉES ───────────────────────────────────────────────────────────────────
$search  = trim($_GET['q']     ?? '');
$statut  = $_GET['statut'] ?? '';
$perPage = 20;
$curPage = max(1,(int)($_GET['page']??1));
$offset  = ($curPage-1)*$perPage;

$where = ['1=1']; $params = [];
if ($search) { $where[] = '(t.nom_entreprise LIKE ? OR t.email LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($statut === 'actif')   { $where[] = "a.statut='actif' AND a.date_fin >= CURDATE()"; }
if ($statut === 'expirant'){ $where[] = "a.statut='actif' AND a.date_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)"; }
if ($statut === 'expire')  { $where[] = "(a.statut='expire' OR a.date_fin < CURDATE())"; }
if ($statut === 'aucun')   { $where[] = 'a.id IS NULL'; }
$whereSQL = implode(' AND ', $where);

$cs = $db->prepare("SELECT COUNT(DISTINCT t.id) FROM tenants t LEFT JOIN abonnements a ON a.tenant_id=t.id AND a.statut='actif' WHERE $whereSQL");
$cs->execute($params);
$total = (int)$cs->fetchColumn();
$totalPages = (int)ceil($total/$perPage);

$stmt = $db->prepare("
    SELECT t.id, t.nom_entreprise, t.telephone, t.actif, t.plan,
           u.email,
           a.id abo_id, a.plan abo_plan, a.prix abo_prix, a.date_debut, a.date_fin, a.statut abo_statut,
           0 total_paye,
           DATEDIFF(a.date_fin,CURDATE()) jours_restants
    FROM tenants t
    LEFT JOIN users u ON u.tenant_id=t.id AND u.role='tenant_admin'
    LEFT JOIN abonnements a ON a.tenant_id=t.id AND a.statut='actif'
    WHERE $whereSQL
    ORDER BY
        CASE WHEN t.actif=0 THEN 0 ELSE 1 END,
        COALESCE(a.date_fin,'9999-12-31') ASC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$abos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// KPIs
$kpi = $db->query("
    SELECT
        COUNT(DISTINCT t.id) total,
        SUM(t.actif=0) en_attente,
        (SELECT COUNT(*) FROM abonnements WHERE statut='actif' AND date_fin>=CURDATE()) actifs,
        (SELECT COUNT(*) FROM abonnements WHERE statut='actif' AND date_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)) expirant,
        0 revenus_mois,
        0 revenus_annee
    FROM tenants t
")->fetch(PDO::FETCH_ASSOC);
try {
    $rev = $db->query("SELECT COALESCE(SUM(CASE WHEN MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE()) THEN montant ELSE 0 END),0) revenus_mois, COALESCE(SUM(CASE WHEN YEAR(created_at)=YEAR(CURDATE()) THEN montant ELSE 0 END),0) revenus_annee FROM mouvements_abo WHERE type='paiement'")->fetch(PDO::FETCH_ASSOC);
    $kpi['revenus_mois'] = $rev['revenus_mois']; $kpi['revenus_annee'] = $rev['revenus_annee'];
} catch(\Throwable $e) {}

function aboPagUrl(int $p): string { $q=$_GET;$q['page']=$p;return '?'.http_build_query($q); }

$pageTitle  = 'Abonnements';
$activePage = 'admin_abonnements';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
.kpi-row { display:grid; grid-template-columns:repeat(6,1fr); gap:12px; margin-bottom:20px; }
@media(max-width:1100px){ .kpi-row{grid-template-columns:repeat(3,1fr)} }
@media(max-width:600px)  { .kpi-row{grid-template-columns:1fr 1fr} }
.kpi { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 14px; text-align:center; border-top:3px solid var(--c,#e2e8f0); }
.kpi .v { font-size:1.5rem; font-weight:900; color:var(--c,#0f172a); line-height:1; }
.kpi .l { font-size:.65rem; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; font-weight:700; margin-top:6px; }

.pricing-banner { background:linear-gradient(135deg,#0f172a,#0f172a); border-radius:14px; padding:20px 28px; margin-bottom:20px; display:flex; gap:20px; flex-wrap:wrap; align-items:center; justify-content:center; }
.pf-card { background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.12); border-radius:12px; padding:16px 24px; text-align:center; }
.pf-card .pf-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; margin-bottom:6px; }
.pf-card .pf-price { font-size:1.6rem; font-weight:900; color:#fff; line-height:1; }
.pf-card .pf-period { font-size:.75rem; color:rgba(255,255,255,.6); margin-top:3px; }
.pf-card.mensuel .pf-label { color:#60a5fa; }
.pf-card.annuel  .pf-label { color:#a78bfa; }
.pf-sep { color:rgba(255,255,255,.3); font-size:1.2rem; align-self:center; }

.fbar { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:12px 16px; margin-bottom:16px; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
.fbar .form-control { font-size:.8rem; padding:7px 10px; }

.at { width:100%; border-collapse:collapse; font-size:.8rem; }
.at thead th { font-size:.65rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; padding:10px 14px; background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align:left; }
.at tbody td { padding:11px 14px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.at tbody tr:last-child td { border-bottom:none; }
.at tbody tr:hover { background:#f8fafc; }
.tr-pending { background:#fffbeb !important; }
.tr-expired { background:#fef2f2 !important; }

.tag { display:inline-block; padding:2px 9px; border-radius:99px; font-size:.68rem; font-weight:700; }
.tag-green  { background:#dcfce7; color:#16a34a; }
.tag-red    { background:#fee2e2; color:#dc2626; }
.tag-orange { background:#ffedd5; color:#c2410c; }
.tag-blue   { background:#dbeafe; color:#1d4ed8; }
.tag-purple { background:#f3e8ff; color:#7c3aed; }
.tag-gray   { background:#f1f5f9; color:#64748b; }

.btn-act { display:inline-flex; align-items:center; justify-content:center; padding:4px 11px; border-radius:7px; font-size:.74rem; font-weight:700; border:none; cursor:pointer; text-decoration:none; transition:all .12s; white-space:nowrap; }
.btn-act.green  { background:#dcfce7; color:#16a34a; } .btn-act.green:hover  { background:#16a34a; color:#fff; }
.btn-act.blue   { background:#dbeafe; color:#1d4ed8; } .btn-act.blue:hover   { background:#0d9488; color:#fff; }
.btn-act.orange { background:#ffedd5; color:#c2410c; } .btn-act.orange:hover { background:#ea580c; color:#fff; }
.btn-act.gray   { background:#f1f5f9; color:#64748b; } .btn-act.gray:hover   { background:#64748b; color:#fff; }
.btn-act.red    { background:#fee2e2; color:#dc2626; } .btn-act.red:hover    { background:#dc2626; color:#fff; }

.pwd-cell { font-family:monospace; font-size:.75rem; background:#f8fafc; padding:2px 7px; border-radius:5px; border:1px solid #e2e8f0; color:#475569; }
.pag { display:flex; gap:4px; justify-content:center; flex-wrap:wrap; margin-top:16px; padding:12px; }

@media(max-width:768px) {
    .pricing-banner { flex-direction:column; padding:16px; }
    .pf-sep { display:none; }
    .fbar { flex-direction:column; }
    .fbar > div, .fbar .form-control { width:100% !important; min-width:0 !important; }
    .at thead { display:none; }
    .at tbody tr { display:block; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:10px; padding:12px 14px; background:#fff; }
    .at tbody td { display:flex; justify-content:space-between; align-items:center; padding:4px 0; border-bottom:none; font-size:.8rem; }
    .at tbody td::before { content:attr(data-label); font-size:.68rem; font-weight:700; color:#94a3b8; text-transform:uppercase; }
    .at tbody tr:hover { background:#fff; }
    .modal-card { max-width:95vw !important; margin:10px; }
}
</style>

<?= renderFlashes() ?>

<!-- Banner forfaits -->
<div class="pricing-banner">
    <div class="pf-card mensuel">
        <div class="pf-label">Mensuel</div>
        <div class="pf-price">20 000 <span style="font-size:.9rem;font-weight:600;color:rgba(255,255,255,.7)">FCFA</span></div>
        <div class="pf-period">30 jours · sans engagement</div>
    </div>
    <div class="pf-sep">·</div>
    <div class="pf-card annuel">
        <div class="pf-label">Annuel</div>
        <div class="pf-price">150 000 <span style="font-size:.9rem;font-weight:600;color:rgba(255,255,255,.7)">FCFA</span></div>
        <div class="pf-period">365 jours · économie 90 000 FCFA</div>
    </div>
</div>

<!-- KPIs -->
<div class="kpi-row">
    <div class="kpi" style="--c:#0d9488"><div class="v"><?= (int)$kpi['total'] ?></div><div class="l">Total</div></div>
    <div class="kpi" style="--c:#f59e0b"><div class="v"><?= (int)$kpi['en_attente'] ?></div><div class="l">En attente</div></div>
    <div class="kpi" style="--c:#16a34a"><div class="v"><?= (int)$kpi['actifs'] ?></div><div class="l">Abonnés</div></div>
    <div class="kpi" style="--c:#ef4444"><div class="v"><?= (int)$kpi['expirant'] ?></div><div class="l">Expirent 30j</div></div>
    <div class="kpi" style="--c:#0891b2"><div class="v" style="font-size:1rem"><?= number_format((float)$kpi['revenus_mois'],0,',',' ') ?></div><div class="l">Revenus mois</div></div>
    <div class="kpi" style="--c:#7c3aed"><div class="v" style="font-size:1rem"><?= number_format((float)$kpi['revenus_annee'],0,',',' ') ?></div><div class="l">Revenus année</div></div>
</div>

<!-- FILTRE -->
<div class="fbar">
    <form method="GET" style="display:contents">
        <div style="flex:1;min-width:200px">
            <label class="form-label" style="font-size:.72rem">Recherche</label>
            <input type="text" name="q" class="form-control" placeholder="Entreprise ou email…" value="<?= sanitize($search) ?>">
        </div>
        <div style="min-width:160px">
            <label class="form-label" style="font-size:.72rem">Statut</label>
            <select name="statut" class="form-control">
                <option value="">Tous</option>
                <option value="actif"    <?= $statut==='actif'?'selected':'' ?>>Actif</option>
                <option value="expirant" <?= $statut==='expirant'?'selected':'' ?>>Expire bientôt</option>
                <option value="expire"   <?= $statut==='expire'?'selected':'' ?>>Expiré</option>
                <option value="aucun"    <?= $statut==='aucun'?'selected':'' ?>>Sans abonnement</option>
            </select>
        </div>
        <div style="display:flex;gap:6px;align-items:flex-end">
            <button type="submit" class="btn btn-primary btn-sm">Filtrer</button>
            <a href="?" class="btn btn-secondary btn-sm">Reset</a>
        </div>
    </form>
</div>

<!-- TABLE -->
<div class="card" style="border-radius:14px;overflow:hidden">
<div class="table-responsive">
<table class="at">
    <thead>
        <tr>
            <th>Entreprise</th>
            <th>Email / Mot de passe</th>
            <th>Forfait</th>
            <th>Période</th>
            <th>Restant</th>
            <th>Total perçu</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($abos as $a):
        $jr = !empty($a['date_fin']) ? (int)((strtotime($a['date_fin'])-time())/86400) : null;
        $rowClass = '';
        if (!(int)$a['actif'])  $rowClass = 'tr-pending';
        elseif ($jr !== null && $jr <= 0) $rowClass = 'tr-expired';
    ?>
    <tr class="<?= $rowClass ?>">
        <td>
            <div style="font-weight:700;color:#0f172a"><?= sanitize($a['nom_entreprise']) ?></div>
            <?php if (!empty($a['telephone'])): ?><div style="font-size:.72rem;color:#94a3b8"><?= sanitize($a['telephone']) ?></div><?php endif ?>
            <?php if (!(int)$a['actif']): ?><span class="tag tag-orange" style="margin-top:4px;display:inline-block">En attente</span><?php endif ?>
        </td>
        <td>
            <div style="font-size:.78rem;color:#475569"><?= sanitize($a['email']??'') ?></div>
        </td>
        <td>
            <?php $ap = $a['abo_plan'] ?? '';
            if ($ap==='mensuel') echo '<span class="tag tag-blue">Mensuel</span>';
            elseif ($ap==='annuel') echo '<span class="tag tag-purple">Annuel</span>';
            elseif ($ap) echo '<span class="tag tag-gray">'.sanitize($ap).'</span>';
            else echo '<span class="tag tag-gray">Aucun</span>'; ?>
        </td>
        <td style="font-size:.75rem;color:#64748b">
            <?php if ($a['date_debut'] && $a['date_fin']): ?>
            <?= formatDate($a['date_debut']) ?> → <?= formatDate($a['date_fin']) ?>
            <?php else: echo '—'; endif ?>
        </td>
        <td>
            <?php if ($jr !== null):
                if ($jr <= 0)   echo '<span class="tag tag-red">Expiré</span>';
                elseif ($jr<=7) echo '<span class="tag tag-red">'.$jr.' j</span>';
                elseif ($jr<=30) echo '<span class="tag tag-orange">'.$jr.' j</span>';
                else echo '<span class="tag tag-green">'.$jr.' j</span>';
            else: echo '<span style="color:#cbd5e1">—</span>';
            endif ?>
        </td>
        <td>
            <?php if ($a['total_paye'] > 0): ?>
            <span style="font-weight:700;color:#16a34a"><?= number_format((float)$a['total_paye'],0,',',' ') ?> F</span>
            <?php else: ?>
            <span style="color:#cbd5e1">0</span>
            <?php endif ?>
        </td>
        <td>
            <div style="display:flex;gap:5px;flex-wrap:wrap">
                <a href="<?= BASE_URL ?>admin/tenant_detail.php?id=<?= $a['id'] ?>" class="btn-act blue">Détail</a>
                <?php if (!(int)$a['actif']): ?>
                <button class="btn-act green" onclick="openModal('modal-act-<?= $a['id'] ?>')">Activer</button>
                <?php else: ?>
                <button class="btn-act gray" onclick="openModal('modal-renew-<?= $a['id'] ?>')">Renouveler</button>
                <?php endif ?>
                <button class="btn-act gray" onclick="openModal('modal-paie-<?= $a['id'] ?>')">Paiement</button>
                <?php if ($a['abo_id'] && $a['abo_statut']==='actif'): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Suspendre cet abonnement ?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="suspendre">
                    <input type="hidden" name="abo_id" value="<?= (int)$a['abo_id'] ?>">
                    <button class="btn-act red">Suspendre</button>
                </form>
                <?php endif ?>
            </div>

            <!-- Modal Activer -->
            <div id="modal-act-<?= $a['id'] ?>" class="modal-overlay">
                <div class="modal-card" style="max-width:420px">
                    <div class="modal-header">
                        <h3>Activer — <?= sanitize($a['nom_entreprise']) ?></h3>
                        <button class="modal-close" onclick="closeModal('modal-act-<?= $a['id'] ?>')">&times;</button>
                    </div>
                    <form method="POST" style="padding:20px">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="activer">
                        <input type="hidden" name="tenant_id" value="<?= $a['id'] ?>">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px">
                            <label style="cursor:pointer"><input type="radio" name="forfait" value="mensuel" checked style="display:none" class="radio-forfait">
                                <div class="forfait-card" data-val="mensuel" style="border:2px solid #0d9488;border-radius:12px;padding:14px;text-align:center;background:#eff6ff">
                                    <div style="font-size:.7rem;font-weight:700;color:#0d9488;text-transform:uppercase;margin-bottom:4px">Mensuel</div>
                                    <div style="font-size:1.2rem;font-weight:900;color:#0f172a">20 000 FCFA</div>
                                    <div style="font-size:.72rem;color:#64748b">30 jours</div>
                                </div>
                            </label>
                            <label style="cursor:pointer"><input type="radio" name="forfait" value="annuel" style="display:none" class="radio-forfait">
                                <div class="forfait-card" data-val="annuel" style="border:2px solid #e2e8f0;border-radius:12px;padding:14px;text-align:center;background:#fff">
                                    <div style="font-size:.7rem;font-weight:700;color:#7c3aed;text-transform:uppercase;margin-bottom:4px">Annuel</div>
                                    <div style="font-size:1.2rem;font-weight:900;color:#0f172a">150 000 FCFA</div>
                                    <div style="font-size:.72rem;color:#16a34a;font-weight:700">Économie 90 000 F</div>
                                </div>
                            </label>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('modal-act-<?= $a['id'] ?>')">Annuler</button>
                            <button type="submit" class="btn btn-primary">Activer</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Renouveler -->
            <div id="modal-renew-<?= $a['id'] ?>" class="modal-overlay">
                <div class="modal-card" style="max-width:420px">
                    <div class="modal-header">
                        <h3>Renouveler — <?= sanitize($a['nom_entreprise']) ?></h3>
                        <button class="modal-close" onclick="closeModal('modal-renew-<?= $a['id'] ?>')">&times;</button>
                    </div>
                    <form method="POST" style="padding:20px">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="renouveler">
                        <input type="hidden" name="abo_id" value="<?= (int)($a['abo_id']??0) ?>">
                        <input type="hidden" name="tenant_id" value="<?= $a['id'] ?>">
                        <p style="font-size:.82rem;color:#64748b;margin-bottom:14px">
                            Abonnement actuel : fin le <strong><?= $a['abo_fin'] ? formatDate($a['abo_fin']) : '—' ?></strong>
                        </p>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px">
                            <label style="cursor:pointer"><input type="radio" name="forfait" value="mensuel" checked style="display:none" class="radio-forfait">
                                <div class="forfait-card" data-val="mensuel" style="border:2px solid #0d9488;border-radius:12px;padding:14px;text-align:center;background:#eff6ff">
                                    <div style="font-size:.7rem;font-weight:700;color:#0d9488;text-transform:uppercase;margin-bottom:4px">Mensuel</div>
                                    <div style="font-size:1.2rem;font-weight:900;color:#0f172a">20 000 FCFA</div>
                                    <div style="font-size:.72rem;color:#64748b">+30 jours</div>
                                </div>
                            </label>
                            <label style="cursor:pointer"><input type="radio" name="forfait" value="annuel" style="display:none" class="radio-forfait">
                                <div class="forfait-card" data-val="annuel" style="border:2px solid #e2e8f0;border-radius:12px;padding:14px;text-align:center;background:#fff">
                                    <div style="font-size:.7rem;font-weight:700;color:#7c3aed;text-transform:uppercase;margin-bottom:4px">Annuel</div>
                                    <div style="font-size:1.2rem;font-weight:900;color:#0f172a">150 000 FCFA</div>
                                    <div style="font-size:.72rem;color:#64748b">+365 jours</div>
                                </div>
                            </label>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('modal-renew-<?= $a['id'] ?>')">Annuler</button>
                            <button type="submit" class="btn btn-primary">Renouveler</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Paiement -->
            <div id="modal-paie-<?= $a['id'] ?>" class="modal-overlay">
                <div class="modal-card" style="max-width:400px">
                    <div class="modal-header">
                        <h3>Paiement — <?= sanitize($a['nom_entreprise']) ?></h3>
                        <button class="modal-close" onclick="closeModal('modal-paie-<?= $a['id'] ?>')">&times;</button>
                    </div>
                    <form method="POST" style="padding:20px">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="paiement">
                        <input type="hidden" name="tenant_id" value="<?= $a['id'] ?>">
                        <div class="form-group">
                            <label class="form-label">Montant (FCFA)</label>
                            <input type="number" name="montant" class="form-control" placeholder="20000" min="0" required>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                            <div class="form-group">
                                <label class="form-label">Mode</label>
                                <select name="mode" class="form-control">
                                    <option value="mobile_money">Mobile Money</option>
                                    <option value="wave">Wave</option>
                                    <option value="orange_money">Orange Money</option>
                                    <option value="especes">Espèces</option>
                                    <option value="virement">Virement</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Référence</label>
                                <input type="text" name="reference" class="form-control" placeholder="TXN…">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Note</label>
                            <input type="text" name="description" class="form-control" placeholder="ex: Mensuel mars 2026">
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('modal-paie-<?= $a['id'] ?>')">Annuler</button>
                            <button type="submit" class="btn btn-primary">Enregistrer</button>
                        </div>
                    </form>
                </div>
            </div>
        </td>
    </tr>
    <?php endforeach ?>
    <?php if (empty($abos)): ?>
    <tr><td colspan="7" style="text-align:center;padding:48px;color:#94a3b8">
        <div style="font-weight:600">Aucun résultat</div>
    </td></tr>
    <?php endif ?>
    </tbody>
</table>
</div>

<?php if ($totalPages > 1): ?>
<div class="pag">
    <?php if ($curPage>1): ?>
        <a href="<?= aboPagUrl(1) ?>" class="btn btn-sm btn-secondary">«</a>
        <a href="<?= aboPagUrl($curPage-1) ?>" class="btn btn-sm btn-secondary">‹</a>
    <?php endif ?>
    <?php for($pg=max(1,$curPage-2);$pg<=min($totalPages,$curPage+2);$pg++): ?>
        <a href="<?= aboPagUrl($pg) ?>" class="btn btn-sm <?= $pg===$curPage?'btn-primary':'btn-secondary' ?>"><?= $pg ?></a>
    <?php endfor ?>
    <?php if ($curPage<$totalPages): ?>
        <a href="<?= aboPagUrl($curPage+1) ?>" class="btn btn-sm btn-secondary">›</a>
        <a href="<?= aboPagUrl($totalPages) ?>" class="btn btn-sm btn-secondary">»</a>
    <?php endif ?>
    <span style="font-size:.75rem;color:#94a3b8;align-self:center;margin-left:6px">Page <?= $curPage ?>/<?= $totalPages ?></span>
</div>
<?php endif ?>
</div>

<script>
document.addEventListener('click', function(e) {
    const card = e.target.closest('.forfait-card');
    if (!card) return;
    const val = card.dataset.val;
    const form = card.closest('form');
    if (!form) return;
    form.querySelectorAll('.forfait-card').forEach(c => {
        const sel = c.dataset.val === val;
        if (c.dataset.val === 'mensuel') { c.style.borderColor = sel?'#0d9488':'#e2e8f0'; c.style.background = sel?'#eff6ff':'#fff'; }
        else { c.style.borderColor = sel?'#7c3aed':'#e2e8f0'; c.style.background = sel?'#f5f3ff':'#fff'; }
    });
    const r = form.querySelector('input[type=radio][value="'+val+'"]');
    if (r) r.checked = true;
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
