<?php
/**
 * FlotteCar — Gestion des entreprises clientes (Super Admin)
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
    $tid    = (int)($_POST['tenant_id'] ?? 0);

    if ($tid > 0) {
        if ($action === 'toggle_actif') {
            $cur = (int)$db->query("SELECT actif FROM tenants WHERE id=$tid")->fetchColumn();
            $db->prepare("UPDATE tenants SET actif=?, updated_at=NOW() WHERE id=?")->execute([$cur ? 0 : 1, $tid]);
            setFlash($cur ? FLASH_WARNING : FLASH_SUCCESS, $cur ? 'Compte suspendu.' : 'Compte réactivé.');
        }
        if ($action === 'edit_tenant') {
            $nom  = trim($_POST['nom_entreprise'] ?? '');
            $tel  = trim($_POST['telephone'] ?? '');
            $type = $_POST['type_usage'] ?? 'les_deux';
            if ($nom) {
                $db->prepare("UPDATE tenants SET nom_entreprise=?,telephone=?,type_usage=?,updated_at=NOW() WHERE id=?")->execute([$nom,$tel,$type,$tid]);
                setFlash(FLASH_SUCCESS, 'Entreprise mise à jour.');
            }
        }
        if ($action === 'activer_compte') {
            $forfait = $_POST['forfait'] ?? 'mensuel';
            $duree   = $forfait === 'annuel' ? 365 : 30;
            $prix    = $forfait === 'annuel' ? 150000 : 20000;
            $db->prepare("UPDATE tenants SET actif=1, plan=?, updated_at=NOW() WHERE id=?")->execute([$forfait, $tid]);
            $db->prepare("UPDATE abonnements SET statut='expire' WHERE tenant_id=? AND statut='actif'")->execute([$tid]);
            $db->prepare("INSERT INTO abonnements (tenant_id,plan,prix,date_debut,date_fin,statut,created_at) VALUES (?,?,?,CURDATE(),DATE_ADD(CURDATE(),INTERVAL ? DAY),'actif',NOW())")->execute([$tid,$forfait,$prix,$duree]);
            try { $db->prepare("INSERT INTO mouvements_abo (tenant_id,type,montant,description,created_by) VALUES (?,?,?,?,?)")->execute([$tid,'renouvellement',$prix,"Activation forfait $forfait",getUserId()]); } catch(\Throwable $e){}
            setFlash(FLASH_SUCCESS, 'Compte activé — forfait ' . $forfait . '.');
        }
        if ($action === 'prolonger_abo') {
            $forfait = $_POST['forfait_prolonger'] ?? 'mensuel';
            $duree   = $forfait === 'annuel' ? 365 : 30;
            $prix    = $forfait === 'annuel' ? 150000 : 20000;
            $exist = $db->prepare("SELECT id FROM abonnements WHERE tenant_id=? AND statut='actif' LIMIT 1");
            $exist->execute([$tid]);
            $existId = $exist->fetchColumn();
            if ($existId) {
                $db->prepare("UPDATE abonnements SET date_fin=DATE_ADD(date_fin,INTERVAL ? DAY),updated_at=NOW() WHERE id=?")->execute([$duree,$existId]);
            } else {
                $db->prepare("INSERT INTO abonnements (tenant_id,plan,prix,date_debut,date_fin,statut,created_at) VALUES (?,?,?,CURDATE(),DATE_ADD(CURDATE(),INTERVAL ? DAY),'actif',NOW())")->execute([$tid,$forfait,$prix,$duree]);
            }
            $db->prepare("UPDATE tenants SET plan=? WHERE id=?")->execute([$forfait,$tid]);
            try { $db->prepare("INSERT INTO mouvements_abo (tenant_id,type,montant,description,created_by) VALUES (?,?,?,?,?)")->execute([$tid,'renouvellement',$prix,"Renouvellement forfait $forfait",getUserId()]); } catch(\Throwable $e){}
            setFlash(FLASH_SUCCESS, 'Abonnement renouvelé — forfait ' . $forfait . '.');
        }
    }
    redirect(BASE_URL . 'admin/tenants.php?' . http_build_query(array_filter(['q'=>$_GET['q']??'','statut'=>$_GET['statut']??'','page'=>$_GET['page']??''])));
}

// ── EXPORT CSV ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $db->query("SELECT t.id,t.nom_entreprise,t.email,t.telephone,t.plan,t.type_usage,t.actif,t.created_at,(SELECT COUNT(*) FROM vehicules v WHERE v.tenant_id=t.id) nb_vehicules FROM tenants t ORDER BY t.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tenants_' . date('Ymd') . '.csv"');
    $out = fopen('php://output','w');
    fprintf($out,chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out,['ID','Entreprise','Email','Téléphone','Plan','Usage','Véhicules','Actif','Inscription'],';');
    foreach ($rows as $r) fputcsv($out,[$r['id'],$r['nom_entreprise'],$r['email'],$r['telephone']??'',$r['plan'],$r['type_usage'],$r['nb_vehicules'],$r['actif']?'Oui':'Non',$r['created_at']],';');
    fclose($out); exit;
}

// ── FILTRES & PAGINATION ──────────────────────────────────────────────────────
$filterStatut = $_GET['statut'] ?? '';
$search       = trim($_GET['q'] ?? '');
$perPage      = 20;
$currentPage  = max(1,(int)($_GET['page']??1));
$offset       = ($currentPage-1)*$perPage;

$where = []; $params = [];
if ($filterStatut === 'actif')    { $where[] = 't.actif=1'; }
if ($filterStatut === 'inactif')  { $where[] = 't.actif=0'; }
if ($search) { $where[] = '(t.nom_entreprise LIKE ? OR t.email LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }

$whereSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';

$cs = $db->prepare("SELECT COUNT(*) FROM tenants t $whereSQL");
$cs->execute($params);
$total = (int)$cs->fetchColumn();
$totalPages = (int)ceil($total/$perPage);

$stmtList = $db->prepare("
    SELECT t.*,
           u.email      user_email,
           (SELECT COUNT(*) FROM vehicules v WHERE v.tenant_id=t.id) nb_vehicules,
           a.plan abo_plan, a.date_fin abo_fin
    FROM tenants t
    LEFT JOIN users u ON u.tenant_id=t.id AND u.role='tenant_admin'
    LEFT JOIN abonnements a ON a.tenant_id=t.id AND a.statut='actif'
    $whereSQL ORDER BY t.created_at DESC LIMIT $perPage OFFSET $offset
");
$stmtList->execute($params);
$tenants = $stmtList->fetchAll(PDO::FETCH_ASSOC);

$kpi = $db->query("SELECT COUNT(*) total,SUM(actif=1) actifs,SUM(actif=0) inactifs,(SELECT COUNT(*) FROM abonnements WHERE date_fin<CURDATE() AND statut='actif') expires FROM tenants")->fetch(PDO::FETCH_ASSOC);

function tenPagUrl(int $p): string { $q=$_GET;$q['page']=$p;return '?'.http_build_query($q); }

$pageTitle  = 'Entreprises';
$activePage = 'admin_tenants';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
.kpi-row4 { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:20px; }
@media(max-width:700px){ .kpi-row4{grid-template-columns:1fr 1fr} }
.kpi { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 14px; text-align:center; border-top:3px solid var(--c,#e2e8f0); }
.kpi .v { font-size:1.6rem; font-weight:900; color:var(--c,#0f172a); line-height:1; }
.kpi .l { font-size:.65rem; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; font-weight:700; margin-top:6px; }

.fbar { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:12px 16px; margin-bottom:16px; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
.fbar .form-control { font-size:.8rem; padding:7px 10px; }

.at { width:100%; border-collapse:collapse; font-size:.8rem; }
.at thead th { font-size:.65rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; padding:10px 14px; background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align:left; }
.at tbody td { padding:11px 14px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.at tbody tr:last-child td { border-bottom:none; }
.at tbody tr:hover { background:#f8fafc; }
.tr-pending { background:#fffbeb !important; }
.tr-expired { background:#fef2f2 !important; }
.tr-sus     { background:#f8fafc !important; opacity:.75; }

.tag { display:inline-block; padding:2px 9px; border-radius:99px; font-size:.68rem; font-weight:700; }
.tag-green  { background:#dcfce7; color:#16a34a; }
.tag-red    { background:#fee2e2; color:#dc2626; }
.tag-orange { background:#ffedd5; color:#c2410c; }
.tag-blue   { background:#dbeafe; color:#1d4ed8; }
.tag-purple { background:#f3e8ff; color:#7c3aed; }
.tag-gray   { background:#f1f5f9; color:#64748b; }

.btn-act { display:inline-flex; align-items:center; justify-content:center; gap:4px; padding:4px 11px; border-radius:7px; font-size:.74rem; font-weight:700; border:none; cursor:pointer; text-decoration:none; transition:all .12s; white-space:nowrap; }
.btn-act.green  { background:#dcfce7; color:#16a34a; } .btn-act.green:hover  { background:#16a34a; color:#fff; }
.btn-act.blue   { background:#dbeafe; color:#1d4ed8; } .btn-act.blue:hover   { background:#0d9488; color:#fff; }
.btn-act.orange { background:#ffedd5; color:#c2410c; } .btn-act.orange:hover { background:#ea580c; color:#fff; }
.btn-act.red    { background:#fee2e2; color:#dc2626; } .btn-act.red:hover    { background:#dc2626; color:#fff; }
.btn-act.gray   { background:#f1f5f9; color:#64748b; } .btn-act.gray:hover   { background:#64748b; color:#fff; }

.pwd-cell { font-family:monospace; font-size:.75rem; background:#f8fafc; padding:2px 7px; border-radius:5px; border:1px solid #e2e8f0; color:#475569; }
.pag { display:flex; gap:4px; justify-content:center; flex-wrap:wrap; margin-top:16px; padding:12px; }

@media(max-width:768px){
    .page-header{flex-direction:column;align-items:flex-start!important;gap:8px}
    .fbar{flex-direction:column}
    .fbar .form-control{width:100%}
    .fbar>form>div,.fbar form>div{width:100%!important;min-width:0!important;flex:none!important}
    .fbar div[style*="display:flex"]{flex-direction:column;width:100%}
    .fbar .btn{width:100%}
    .kpi-row4{grid-template-columns:1fr 1fr}
    .at thead{display:none}
    .at tbody tr{display:block;border:1px solid #e2e8f0;border-radius:10px;padding:12px;margin-bottom:10px;background:#fff}
    .at tbody td{display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f1f5f9}
    .at tbody td:last-child{border-bottom:none}
    .at tbody td::before{content:attr(data-label);font-size:.68rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-right:8px}
    .pag{flex-direction:row}
}
</style>

<?= renderFlashes() ?>

<!-- KPIs -->
<div class="kpi-row4">
    <div class="kpi" style="--c:#0d9488"><div class="v"><?= (int)$kpi['total'] ?></div><div class="l">Total</div></div>
    <div class="kpi" style="--c:#16a34a"><div class="v"><?= (int)$kpi['actifs'] ?></div><div class="l">Actifs</div></div>
    <div class="kpi" style="--c:#f59e0b"><div class="v"><?= (int)$kpi['inactifs'] ?></div><div class="l">En attente</div></div>
    <div class="kpi" style="--c:#ef4444"><div class="v"><?= (int)$kpi['expires'] ?></div><div class="l">Expirés</div></div>
</div>

<!-- FILTRE -->
<div class="fbar">
    <form method="GET" style="display:contents">
        <div style="flex:1;min-width:200px">
            <label class="form-label" style="font-size:.72rem">Recherche</label>
            <input type="text" name="q" class="form-control" placeholder="Entreprise ou email…" value="<?= sanitize($search) ?>">
        </div>
        <div style="min-width:140px">
            <label class="form-label" style="font-size:.72rem">Statut</label>
            <select name="statut" class="form-control">
                <option value="">Tous</option>
                <option value="actif"   <?= $filterStatut==='actif'?'selected':'' ?>>Actif</option>
                <option value="inactif" <?= $filterStatut==='inactif'?'selected':'' ?>>En attente</option>
            </select>
        </div>
        <div style="display:flex;gap:6px;align-items:flex-end">
            <button type="submit" class="btn btn-primary btn-sm">Filtrer</button>
            <a href="?" class="btn btn-secondary btn-sm">Reset</a>
            <a href="?export=csv" class="btn btn-secondary btn-sm">CSV</a>
        </div>
    </form>
</div>

<!-- TABLE -->
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
            <th>Statut</th>
            <th>Inscription</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($tenants as $t):
        $jr = $t['abo_fin'] ? (int)((strtotime($t['abo_fin'])-time())/86400) : null;
        $rowClass = '';
        if (!(int)$t['actif']) $rowClass = 'tr-pending';
        elseif ($jr !== null && $jr <= 0) $rowClass = 'tr-expired';
        elseif ($jr !== null && $jr <= 7) $rowClass = 'tr-expired';
    ?>
    <tr class="<?= $rowClass ?>">
        <td style="color:#cbd5e1;font-size:.72rem">#<?= (int)$t['id'] ?></td>
        <td>
            <div style="font-weight:700;color:#0f172a"><?= sanitize($t['nom_entreprise']) ?></div>
            <?php if (!empty($t['telephone'])): ?><div style="font-size:.72rem;color:#94a3b8"><?= sanitize($t['telephone']) ?></div><?php endif ?>
        </td>
        <td>
            <div style="font-size:.78rem;color:#475569"><?= sanitize($t['user_email']??$t['email']??'') ?></div>
        </td>
        <td>
            <?php $p = $t['abo_plan']??$t['plan']??'';
            if ($p==='mensuel') echo '<span class="tag tag-blue">Mensuel</span>';
            elseif ($p==='annuel') echo '<span class="tag tag-purple">Annuel</span>';
            else echo '<span class="tag tag-gray">—</span>'; ?>
        </td>
        <td>
            <?php if ($jr !== null):
                if ($jr <= 0) echo '<span class="tag tag-red">Expiré</span>';
                elseif ($jr <= 7) echo '<span class="tag tag-red">'.$jr.' j</span>';
                elseif ($jr <= 30) echo '<span class="tag tag-orange">'.$jr.' j</span>';
                else echo '<span class="tag tag-green">'.$jr.' j</span>';
            else: echo '<span style="color:#cbd5e1">—</span>';
            endif ?>
        </td>
        <td style="text-align:center;font-weight:700"><?= (int)$t['nb_vehicules'] ?></td>
        <td>
            <?php if ((int)$t['actif']): ?>
                <span class="tag tag-green">Actif</span>
            <?php else: ?>
                <span class="tag tag-orange">En attente</span>
            <?php endif ?>
        </td>
        <td style="font-size:.72rem;color:#94a3b8;white-space:nowrap"><?= formatDate($t['created_at']) ?></td>
        <td>
            <div style="display:flex;gap:5px;flex-wrap:wrap">
                <a href="<?= BASE_URL ?>admin/tenant_detail.php?id=<?= $t['id'] ?>" class="btn-act blue">Détail</a>
                <?php if (!(int)$t['actif']): ?>
                <button class="btn-act green" onclick="openModal('modal-act-<?= $t['id'] ?>')">Activer</button>
                <?php else: ?>
                <button class="btn-act gray" onclick="openModal('modal-prolong-<?= $t['id'] ?>')">Renouveler</button>
                <?php endif ?>
                <button class="btn-act gray" onclick="openModal('modal-edit-<?= $t['id'] ?>')">Modifier</button>
                <form method="POST" style="display:inline" onsubmit="return confirm('Confirmer ?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle_actif">
                    <input type="hidden" name="tenant_id" value="<?= $t['id'] ?>">
                    <button class="btn-act <?= $t['actif'] ? 'orange' : 'green' ?>"><?= $t['actif'] ? 'Suspendre' : 'Réactiver' ?></button>
                </form>
            </div>

            <!-- Modal Activer -->
            <div id="modal-act-<?= $t['id'] ?>" class="modal-overlay">
                <div class="modal-card" style="max-width:420px">
                    <div class="modal-header">
                        <h3>Activer — <?= sanitize($t['nom_entreprise']) ?></h3>
                        <button class="modal-close" onclick="closeModal('modal-act-<?= $t['id'] ?>')">&times;</button>
                    </div>
                    <form method="POST" style="padding:20px">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="activer_compte">
                        <input type="hidden" name="tenant_id" value="<?= $t['id'] ?>">
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
                                    <div style="font-size:.72rem;color:#64748b">365 jours</div>
                                </div>
                            </label>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('modal-act-<?= $t['id'] ?>')">Annuler</button>
                            <button type="submit" class="btn btn-primary">Activer</button>
                        </div>
                    </form>
                </div>
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
                            <button type="button" class="btn btn-secondary" onclick="closeModal('modal-prolong-<?= $t['id'] ?>')">Annuler</button>
                            <button type="submit" class="btn btn-primary">Renouveler</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Modifier -->
            <div id="modal-edit-<?= $t['id'] ?>" class="modal-overlay">
                <div class="modal-card" style="max-width:460px">
                    <div class="modal-header">
                        <h3>Modifier — <?= sanitize($t['nom_entreprise']) ?></h3>
                        <button class="modal-close" onclick="closeModal('modal-edit-<?= $t['id'] ?>')">&times;</button>
                    </div>
                    <form method="POST" style="padding:20px">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="edit_tenant">
                        <input type="hidden" name="tenant_id" value="<?= $t['id'] ?>">
                        <div class="form-group">
                            <label class="form-label">Nom entreprise</label>
                            <input type="text" name="nom_entreprise" class="form-control" value="<?= sanitize($t['nom_entreprise']) ?>" required>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                            <div class="form-group">
                                <label class="form-label">Téléphone</label>
                                <input type="tel" name="telephone" class="form-control" value="<?= sanitize($t['telephone']??'') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Type d'usage</label>
                                <select name="type_usage" class="form-control">
                                    <option value="location" <?= ($t['type_usage']??'')==='location'?'selected':'' ?>>Location</option>
                                    <option value="controle" <?= ($t['type_usage']??'')==='controle'?'selected':'' ?>>GPS / Contrôle</option>
                                    <option value="les_deux" <?= ($t['type_usage']??'')==='les_deux'?'selected':'' ?>>Location + GPS</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit-<?= $t['id'] ?>')">Annuler</button>
                            <button type="submit" class="btn btn-primary">Enregistrer</button>
                        </div>
                    </form>
                </div>
            </div>
        </td>
    </tr>
    <?php endforeach ?>
    <?php if (empty($tenants)): ?>
    <tr><td colspan="9" style="text-align:center;padding:48px;color:#94a3b8">
        <div style="font-weight:600;margin-bottom:4px">Aucune entreprise trouvée</div>
        <div style="font-size:.78rem">Modifiez les filtres</div>
    </td></tr>
    <?php endif ?>
    </tbody>
</table>
</div>

<?php if ($totalPages > 1): ?>
<div class="pag">
    <?php if ($currentPage > 1): ?>
        <a href="<?= tenPagUrl(1) ?>" class="btn btn-sm btn-secondary">«</a>
        <a href="<?= tenPagUrl($currentPage-1) ?>" class="btn btn-sm btn-secondary">‹</a>
    <?php endif ?>
    <?php for ($pg=max(1,$currentPage-2);$pg<=min($totalPages,$currentPage+2);$pg++): ?>
        <a href="<?= tenPagUrl($pg) ?>" class="btn btn-sm <?= $pg===$currentPage?'btn-primary':'btn-secondary' ?>"><?= $pg ?></a>
    <?php endfor ?>
    <?php if ($currentPage < $totalPages): ?>
        <a href="<?= tenPagUrl($currentPage+1) ?>" class="btn btn-sm btn-secondary">›</a>
        <a href="<?= tenPagUrl($totalPages) ?>" class="btn btn-sm btn-secondary">»</a>
    <?php endif ?>
    <span style="font-size:.75rem;color:#94a3b8;align-self:center;margin-left:6px">Page <?= $currentPage ?>/<?= $totalPages ?> · <?= $total ?> résultats</span>
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
