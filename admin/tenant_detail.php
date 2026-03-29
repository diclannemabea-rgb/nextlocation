<?php
/**
 * FlotteCar — Détail d'un tenant (Super Admin)
 */
define('BASE_PATH', dirname(__DIR__));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireSuperAdmin();

$db  = (new Database())->getConnection();
$tid = (int)($_GET['id'] ?? 0);
if (!$tid) redirect(BASE_URL . 'admin/tenants.php');

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $act = $_POST['action'] ?? '';

    if ($act === 'activer_compte') {
        $forfait = $_POST['forfait'] ?? 'mensuel';
        $duree   = $forfait === 'annuel' ? 365 : 30;
        $prix    = $forfait === 'annuel' ? 150000 : 20000;
        $db->prepare("UPDATE tenants SET actif=1, plan=?, updated_at=NOW() WHERE id=?")->execute([$forfait, $tid]);
        $db->prepare("UPDATE abonnements SET statut='expire' WHERE tenant_id=? AND statut='actif'")->execute([$tid]);
        $db->prepare("INSERT INTO abonnements (tenant_id,plan,prix,date_debut,date_fin,statut,created_at) VALUES (?,?,?,CURDATE(),DATE_ADD(CURDATE(),INTERVAL ? DAY),'actif',NOW())")->execute([$tid,$forfait,$prix,$duree]);
        try { $db->prepare("INSERT INTO mouvements_abo (tenant_id,type,montant,description,created_by) VALUES (?,?,?,?,?)")->execute([$tid,'renouvellement',$prix,"Activation forfait $forfait",getUserId()]); } catch(\Throwable $e){}
        setFlash(FLASH_SUCCESS, 'Compte activé — forfait ' . $forfait . '.');
        redirect(BASE_URL . 'admin/tenant_detail.php?id=' . $tid);
    }

    if ($act === 'prolonger_abo') {
        $forfait = $_POST['forfait'] ?? 'mensuel';
        $duree   = $forfait === 'annuel' ? 365 : 30;
        $prix    = $forfait === 'annuel' ? 150000 : 20000;
        $existId = $db->prepare("SELECT id FROM abonnements WHERE tenant_id=? AND statut='actif' LIMIT 1");
        $existId->execute([$tid]);
        $existId = $existId->fetchColumn();
        if ($existId) {
            $db->prepare("UPDATE abonnements SET date_fin=DATE_ADD(date_fin,INTERVAL ? DAY),plan=?,updated_at=NOW() WHERE id=?")->execute([$duree,$forfait,$existId]);
        } else {
            $db->prepare("INSERT INTO abonnements (tenant_id,plan,prix,date_debut,date_fin,statut,created_at) VALUES (?,?,?,CURDATE(),DATE_ADD(CURDATE(),INTERVAL ? DAY),'actif',NOW())")->execute([$tid,$forfait,$prix,$duree]);
        }
        $db->prepare("UPDATE tenants SET plan=? WHERE id=?")->execute([$forfait, $tid]);
        try { $db->prepare("INSERT INTO mouvements_abo (tenant_id,type,montant,description,created_by) VALUES (?,?,?,?,?)")->execute([$tid,'renouvellement',$prix,"Renouvellement forfait $forfait",getUserId()]); } catch(\Throwable $e){}
        setFlash(FLASH_SUCCESS, 'Abonnement renouvelé — forfait ' . $forfait . '.');
        redirect(BASE_URL . 'admin/tenant_detail.php?id=' . $tid);
    }

    if ($act === 'paiement') {
        $montant = (float)($_POST['montant'] ?? 0);
        $mode    = $_POST['mode'] ?? 'mobile_money';
        $ref     = trim($_POST['reference'] ?? '');
        $desc    = trim($_POST['description'] ?? 'Paiement');
        try { $db->prepare("INSERT INTO mouvements_abo (tenant_id,type,montant,description,mode_paiement,reference,created_by) VALUES (?,?,?,?,?,?,?)")->execute([$tid,'paiement',$montant,$desc,$mode,$ref,getUserId()]); } catch(\Throwable $e){}
        setFlash(FLASH_SUCCESS, 'Paiement de ' . number_format($montant,0,',',' ') . ' FCFA enregistré.');
        redirect(BASE_URL . 'admin/tenant_detail.php?id=' . $tid);
    }

    if ($act === 'toggle_actif') {
        $cur = (int)$db->query("SELECT actif FROM tenants WHERE id=$tid")->fetchColumn();
        $db->prepare("UPDATE tenants SET actif=?, updated_at=NOW() WHERE id=?")->execute([$cur ? 0 : 1, $tid]);
        setFlash(FLASH_SUCCESS, $cur ? 'Compte suspendu.' : 'Compte réactivé.');
        redirect(BASE_URL . 'admin/tenant_detail.php?id=' . $tid);
    }

    if ($act === 'reset_password') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid) {
            $newPass = bin2hex(random_bytes(5)); // 10 chars hex
            $db->prepare("UPDATE users SET password=?, password_plain=? WHERE id=? AND tenant_id=?")->execute([password_hash($newPass, PASSWORD_DEFAULT), $newPass, $uid, $tid]);
            setFlash(FLASH_SUCCESS, 'Nouveau mot de passe : <strong>' . htmlspecialchars($newPass) . '</strong>');
        }
        redirect(BASE_URL . 'admin/tenant_detail.php?id=' . $tid);
    }

    if ($act === 'edit_tenant') {
        $nom  = trim($_POST['nom_entreprise'] ?? '');
        $tel  = trim($_POST['telephone'] ?? '');
        $type = $_POST['type_usage'] ?? 'les_deux';
        if ($nom) {
            $db->prepare("UPDATE tenants SET nom_entreprise=?,telephone=?,type_usage=?,updated_at=NOW() WHERE id=?")->execute([$nom,$tel,$type,$tid]);
            setFlash(FLASH_SUCCESS, 'Informations mises à jour.');
        }
        redirect(BASE_URL . 'admin/tenant_detail.php?id=' . $tid);
    }

    // ── SUPPRESSION EN CASCADE ────────────────────────────────────────────────
    if ($act === 'supprimer_tenant') {
        $confirm = trim($_POST['confirm_nom'] ?? '');
        $tenantNomCheck = $db->query("SELECT nom_entreprise FROM tenants WHERE id=$tid")->fetchColumn();
        if ($confirm !== $tenantNomCheck) {
            setFlash(FLASH_ERROR, 'Nom de confirmation incorrect. Suppression annulée.');
            redirect(BASE_URL . 'admin/tenant_detail.php?id=' . $tid);
        }

        try {
            $db->beginTransaction();
            // Supprimer dans l'ordre (dépendances en premier)
            foreach ([
                'paiements_taxi'    => 'taximetre_id IN (SELECT id FROM taximetres WHERE tenant_id=?)',
                'taximetres'        => 'tenant_id=?',
                'reservations'      => 'tenant_id=?',
                'commerciaux'       => 'tenant_id=?',
                'logs_activites'    => 'tenant_id=?',
                'logs_gps_commandes'=> 'tenant_id=?',
                'positions_gps'     => 'tenant_id=?',
                'alertes'           => 'tenant_id=?',
                'zones'             => 'tenant_id=?',
                'evenements_gps'    => 'tenant_id=?',
                'maintenances'      => 'tenant_id=?',
                'charges'           => 'tenant_id=?',
                'paiements'         => 'tenant_id=?',
                'locations'         => 'tenant_id=?',
                'clients'           => 'tenant_id=?',
                'chauffeurs'        => 'tenant_id=?',
                'vehicules'         => 'tenant_id=?',
                'abonnements'       => 'tenant_id=?',
                'mouvements_abo'    => 'tenant_id=?',
                'notifs_push'       => 'tenant_id=?',
                'push_subscriptions'=> 'tenant_id=?',
                'users'             => 'tenant_id=?',
                'tenants'           => 'id=?',
            ] as $table => $cond) {
                // Pour paiements_taxi, la condition utilise une sous-requête, pas $tid directement comme tenant
                if ($table === 'paiements_taxi') {
                    try {
                        // Get taximetres ids first
                        $taxIds = $db->query("SELECT id FROM taximetres WHERE tenant_id=$tid")->fetchAll(PDO::FETCH_COLUMN);
                        if ($taxIds) {
                            $placeholders = implode(',', array_fill(0, count($taxIds), '?'));
                            $db->prepare("DELETE FROM paiements_taxi WHERE taximetre_id IN ($placeholders)")->execute($taxIds);
                        }
                    } catch(\Throwable $e) {}
                } else {
                    try {
                        $db->prepare("DELETE FROM $table WHERE $cond")->execute([$tid]);
                    } catch(\Throwable $e) {}
                }
            }
            $db->commit();
            setFlash(FLASH_SUCCESS, 'Entreprise "' . htmlspecialchars($tenantNomCheck) . '" supprimée définitivement.');
            redirect(BASE_URL . 'admin/tenants.php');
        } catch(\Throwable $e) {
            $db->rollBack();
            setFlash(FLASH_ERROR, 'Erreur lors de la suppression : ' . $e->getMessage());
            redirect(BASE_URL . 'admin/tenant_detail.php?id=' . $tid);
        }
    }
}

// ── DONNÉES ───────────────────────────────────────────────────────────────────
$tRow = $db->prepare("SELECT * FROM tenants WHERE id=?");
$tRow->execute([$tid]);
$t = $tRow->fetch(PDO::FETCH_ASSOC);
if (!$t) redirect(BASE_URL . 'admin/tenants.php');

$uRow = $db->prepare("SELECT * FROM users WHERE tenant_id=? AND role='tenant_admin' LIMIT 1");
$uRow->execute([$tid]);
$u = $uRow->fetch(PDO::FETCH_ASSOC);

$aRow = $db->prepare("SELECT * FROM abonnements WHERE tenant_id=? AND statut='actif' ORDER BY date_fin DESC LIMIT 1");
$aRow->execute([$tid]);
$ab = $aRow->fetch(PDO::FETCH_ASSOC);
$jr = $ab ? (int)((strtotime($ab['date_fin'])-time())/86400) : null;

$sv = $db->prepare("SELECT COUNT(*) total, SUM(statut='disponible') dispo, SUM(statut='loue') loues, SUM(statut='maintenance') maint FROM vehicules WHERE tenant_id=?");
$sv->execute([$tid]);
$sv = $sv->fetch(PDO::FETCH_ASSOC);

$sl = $db->prepare("SELECT COUNT(*) total, SUM(statut='en_cours') en_cours FROM locations WHERE tenant_id=?");
$sl->execute([$tid]);
$sl = $sl->fetch(PDO::FETCH_ASSOC);

$totalPaye = (float)$db->query("SELECT COALESCE(SUM(montant),0) FROM mouvements_abo WHERE tenant_id=$tid AND type='paiement'")->fetchColumn();

$histAbos = $db->prepare("SELECT * FROM abonnements WHERE tenant_id=? ORDER BY created_at DESC LIMIT 8");
$histAbos->execute([$tid]);
$histAbos = $histAbos->fetchAll(PDO::FETCH_ASSOC);

$histPaie = [];
try {
    $hp = $db->prepare("SELECT * FROM mouvements_abo WHERE tenant_id=? ORDER BY created_at DESC LIMIT 12");
    $hp->execute([$tid]);
    $histPaie = $hp->fetchAll(PDO::FETCH_ASSOC);
} catch(\Throwable $e){}

$vehList = $db->prepare("SELECT marque, modele, immatriculation, statut, type_vehicule, created_at FROM vehicules WHERE tenant_id=? ORDER BY created_at DESC LIMIT 15");
$vehList->execute([$tid]);
$vehList = $vehList->fetchAll(PDO::FETCH_ASSOC);

$pageTitle  = sanitize($t['nom_entreprise']) . ' — Détail';
$activePage = 'admin_tenants';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
.back-link { display:inline-flex; align-items:center; gap:6px; color:#64748b; font-size:.82rem; font-weight:600; text-decoration:none; margin-bottom:16px; }
.back-link:hover { color:#0d9488; }

.top-bar { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px 22px; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; }
.top-bar h1 { font-size:1.2rem; font-weight:900; color:#0f172a; margin:0 0 3px; }
.top-bar .meta { font-size:.78rem; color:#94a3b8; }

.detail-grid { display:grid; grid-template-columns:320px 1fr; gap:18px; }
@media(max-width:960px){ .detail-grid{grid-template-columns:1fr} }

.panel { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:20px; margin-bottom:18px; }
.panel h3 { font-size:.88rem; font-weight:800; color:#0f172a; margin:0 0 14px; padding-bottom:10px; border-bottom:1px solid #f1f5f9; }

.ir { display:flex; justify-content:space-between; align-items:flex-start; padding:8px 0; border-bottom:1px solid #f8fafc; }
.ir:last-child { border-bottom:none; }
.ir .lbl { font-size:.74rem; color:#94a3b8; font-weight:600; }
.ir .val { font-size:.82rem; color:#0f172a; font-weight:600; text-align:right; max-width:60%; }

.krow { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px; }
.kcard { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px; text-align:center; border-top:3px solid var(--c,#e2e8f0); }
.kcard .v { font-size:1.3rem; font-weight:900; color:var(--c,#0f172a); line-height:1; }
.kcard .l { font-size:.62rem; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; font-weight:700; margin-top:4px; }

.abo-box { background:linear-gradient(135deg,#0f172a,#0f172a); border-radius:12px; padding:18px; margin-bottom:14px; }
.abo-box .ab-label { font-size:.63rem; text-transform:uppercase; letter-spacing:.07em; color:rgba(255,255,255,.45); margin-bottom:5px; }
.abo-box .ab-plan { font-size:1.05rem; font-weight:800; color:#fff; margin-bottom:3px; }
.abo-box .ab-dates { font-size:.73rem; color:rgba(255,255,255,.55); }
.abo-box .ab-badge { display:inline-block; background:rgba(255,255,255,.1); border-radius:99px; padding:3px 12px; font-size:.75rem; font-weight:700; color:#fff; margin-top:8px; }

.at { width:100%; border-collapse:collapse; font-size:.79rem; }
.at thead th { font-size:.62rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; padding:8px 12px; background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align:left; }
.at tbody td { padding:9px 12px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.at tbody tr:last-child td { border-bottom:none; }

.tag { display:inline-block; padding:2px 8px; border-radius:99px; font-size:.67rem; font-weight:700; }
.tag-green  { background:#dcfce7; color:#16a34a; }
.tag-red    { background:#fee2e2; color:#dc2626; }
.tag-orange { background:#ffedd5; color:#c2410c; }
.tag-blue   { background:#dbeafe; color:#1d4ed8; }
.tag-purple { background:#f3e8ff; color:#7c3aed; }
.tag-gray   { background:#f1f5f9; color:#64748b; }

.btn-a { display:inline-flex; align-items:center; padding:5px 13px; border-radius:8px; font-size:.77rem; font-weight:700; border:none; cursor:pointer; text-decoration:none; transition:all .12s; white-space:nowrap; }
.btn-a.green  { background:#dcfce7; color:#16a34a; } .btn-a.green:hover  { background:#16a34a; color:#fff; }
.btn-a.blue   { background:#dbeafe; color:#1d4ed8; } .btn-a.blue:hover   { background:#0d9488; color:#fff; }
.btn-a.orange { background:#ffedd5; color:#c2410c; } .btn-a.orange:hover { background:#ea580c; color:#fff; }
.btn-a.gray   { background:#f1f5f9; color:#64748b; } .btn-a.gray:hover   { background:#64748b; color:#fff; }
.btn-a.red    { background:#fee2e2; color:#dc2626; } .btn-a.red:hover    { background:#dc2626; color:#fff; }

.pwd-cell { font-family:monospace; font-size:.77rem; background:#f8fafc; padding:2px 8px; border-radius:6px; border:1px solid #e2e8f0; color:#475569; }

/* Zone suppression danger */
.danger-zone { background:#fff5f5; border:1px solid #fca5a5; border-radius:14px; padding:20px; margin-top:24px; }
.danger-zone h3 { font-size:.88rem; font-weight:800; color:#dc2626; margin:0 0 8px; }
.danger-zone p { font-size:.8rem; color:#7f1d1d; margin-bottom:14px; line-height:1.6; }
</style>

<a href="<?= BASE_URL ?>admin/tenants.php" class="back-link">← Retour aux entreprises</a>

<?= renderFlashes() ?>

<!-- BARRE TOP -->
<div class="top-bar">
    <div>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:3px">
            <h1><?= sanitize($t['nom_entreprise']) ?></h1>
            <?php if ($t['actif']): ?><span class="tag tag-green">Actif</span><?php else: ?><span class="tag tag-orange">En attente</span><?php endif ?>
        </div>
        <div class="meta">ID #<?= $t['id'] ?> · Inscrit le <?= formatDate($t['created_at']) ?> · <?= match($t['type_usage']??'les_deux'){'location'=>'Location','controle'=>'GPS',default=>'Location + GPS'} ?></div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if (!$t['actif']): ?>
        <button class="btn-a green" onclick="openModal('m-activer')">Activer</button>
        <?php else: ?>
        <button class="btn-a blue" onclick="openModal('m-prolong')">Renouveler</button>
        <?php endif ?>
        <button class="btn-a gray" onclick="openModal('m-paie')">Paiement</button>
        <button class="btn-a gray" onclick="openModal('m-edit')">Modifier</button>
        <form method="POST" style="display:inline" onsubmit="return confirm('Confirmer ?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="toggle_actif">
            <button class="btn-a <?= $t['actif']?'orange':'green' ?>"><?= $t['actif']?'Suspendre':'Réactiver' ?></button>
        </form>
    </div>
</div>

<!-- GRILLE -->
<div class="detail-grid">

    <!-- COLONNE GAUCHE -->
    <div>

        <!-- Abonnement -->
        <div class="panel">
            <h3>Abonnement</h3>
            <?php if ($ab): ?>
            <div class="abo-box">
                <div class="ab-label">Forfait actif</div>
                <div class="ab-plan">
                    <?php if ($ab['plan']==='mensuel') echo 'Mensuel — 20 000 FCFA';
                    elseif ($ab['plan']==='annuel') echo 'Annuel — 150 000 FCFA';
                    else echo sanitize($ab['plan']); ?>
                </div>
                <div class="ab-dates"><?= formatDate($ab['date_debut']) ?> → <?= formatDate($ab['date_fin']) ?></div>
                <?php
                if ($jr <= 0) echo '<div class="ab-badge" style="background:rgba(239,68,68,.35)">Expiré</div>';
                elseif ($jr <= 7) echo '<div class="ab-badge" style="background:rgba(239,68,68,.2)">'.$jr.' jours restants</div>';
                elseif ($jr <= 30) echo '<div class="ab-badge" style="background:rgba(245,158,11,.2)">'.$jr.' jours restants</div>';
                else echo '<div class="ab-badge">'.$jr.' jours restants</div>';
                ?>
            </div>
            <?php else: ?>
            <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:14px;text-align:center;margin-bottom:12px">
                <div style="font-weight:700;color:#c2410c;font-size:.85rem">Aucun abonnement actif</div>
            </div>
            <?php endif ?>
            <div class="ir" style="border-top:1px solid #f1f5f9">
                <span class="lbl">Total perçu</span>
                <span class="val" style="color:#16a34a;font-size:.9rem"><?= number_format($totalPaye,0,',',' ') ?> FCFA</span>
            </div>
        </div>

        <!-- Infos -->
        <div class="panel">
            <h3>Informations</h3>
            <div class="ir"><span class="lbl">Téléphone</span><span class="val"><?= sanitize($t['telephone']??'—') ?></span></div>
            <div class="ir"><span class="lbl">Email</span><span class="val" style="font-size:.76rem"><?= sanitize($t['email']??'') ?></span></div>
            <div class="ir"><span class="lbl">Usage</span><span class="val"><?= match($t['type_usage']??'les_deux'){'location'=>'Location','controle'=>'GPS',default=>'Location + GPS'} ?></span></div>
            <div class="ir"><span class="lbl">Inscription</span><span class="val"><?= formatDate($t['created_at']) ?></span></div>
        </div>

        <!-- Responsable -->
        <?php if ($u): ?>
        <div class="panel">
            <h3>Responsable</h3>
            <div class="ir"><span class="lbl">Nom</span><span class="val"><?= sanitize(trim(($u['prenom']??'').' '.$u['nom'])) ?></span></div>
            <div class="ir">
                <span class="lbl">Email</span>
                <span class="val" style="font-size:.76rem"><?= sanitize($u['email']) ?></span>
            </div>
            <?php if (!empty($u['password_plain'])): ?>
            <div class="ir">
                <span class="lbl">Mot de passe</span>
                <span class="val"><span class="pwd-cell"><?= sanitize($u['password_plain']) ?></span></span>
            </div>
            <?php endif ?>
            <div class="ir">
                <span class="lbl">Dernière connexion</span>
                <span class="val"><?= $u['derniere_connexion'] ? formatDatetime($u['derniere_connexion']) : 'Jamais' ?></span>
            </div>
            <div class="ir">
                <span class="lbl">Statut</span>
                <span class="val"><span class="tag <?= $u['statut']==='actif'?'tag-green':'tag-red' ?>"><?= sanitize($u['statut']) ?></span></span>
            </div>
            <div style="margin-top:12px">
                <form method="POST" onsubmit="return confirm('Générer un nouveau mot de passe ?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <button class="btn-a orange" type="submit">Réinitialiser le mot de passe</button>
                </form>
            </div>
        </div>
        <?php endif ?>

        <!-- Danger zone -->
        <div class="danger-zone">
            <h3>Supprimer l'entreprise</h3>
            <p>Cette action supprime définitivement l'entreprise et <strong>toutes ses données</strong> : véhicules, clients, chauffeurs, locations, paiements, abonnements, utilisateurs. Irréversible.</p>
            <button class="btn-a red" onclick="openModal('m-suppr')">Supprimer définitivement</button>
        </div>

    </div>

    <!-- COLONNE DROITE -->
    <div>

        <!-- Stats flotte -->
        <div class="krow">
            <div class="kcard" style="--c:#0d9488"><div class="v"><?= (int)($sv['total']??0) ?></div><div class="l">Véhicules</div></div>
            <div class="kcard" style="--c:#16a34a"><div class="v"><?= (int)($sv['dispo']??0) ?></div><div class="l">Disponibles</div></div>
            <div class="kcard" style="--c:#f59e0b"><div class="v"><?= (int)($sv['loues']??0) ?></div><div class="l">Loués</div></div>
            <div class="kcard" style="--c:#ef4444"><div class="v"><?= (int)($sv['maint']??0) ?></div><div class="l">Maintenance</div></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px">
            <div class="kcard" style="--c:#0891b2"><div class="v"><?= (int)($sl['total']??0) ?></div><div class="l">Total locations</div></div>
            <div class="kcard" style="--c:#7c3aed"><div class="v"><?= (int)($sl['en_cours']??0) ?></div><div class="l">En cours</div></div>
        </div>

        <!-- Véhicules -->
        <?php if (!empty($vehList)): ?>
        <div class="panel">
            <h3>Véhicules (<?= count($vehList) ?>)</h3>
            <table class="at">
                <thead><tr><th>Véhicule</th><th>Immat.</th><th>Type</th><th>Statut</th><th>Ajouté</th></tr></thead>
                <tbody>
                <?php foreach ($vehList as $v): ?>
                <tr>
                    <td style="font-weight:600;color:#0f172a"><?= sanitize($v['marque'].' '.$v['modele']) ?></td>
                    <td style="font-family:monospace;font-size:.76rem"><?= sanitize($v['immatriculation']) ?></td>
                    <td><span class="tag tag-gray" style="font-size:.63rem"><?= sanitize($v['type_vehicule']??'') ?></span></td>
                    <td><?php echo match($v['statut']??''){
                        'disponible'  => '<span class="tag tag-green">Disponible</span>',
                        'loue'        => '<span class="tag tag-blue">Loué</span>',
                        'maintenance' => '<span class="tag tag-orange">Maintenance</span>',
                        default       => '<span class="tag tag-gray">'.sanitize($v['statut']??'—').'</span>',
                    }; ?></td>
                    <td style="font-size:.7rem;color:#94a3b8"><?= formatDate($v['created_at']) ?></td>
                </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php endif ?>

        <!-- Historique abonnements -->
        <?php if (!empty($histAbos)): ?>
        <div class="panel">
            <h3>Historique abonnements</h3>
            <table class="at">
                <thead><tr><th>Forfait</th><th>Prix</th><th>Début</th><th>Fin</th><th>Statut</th></tr></thead>
                <tbody>
                <?php foreach ($histAbos as $ha): ?>
                <tr>
                    <td><?php if ($ha['plan']==='mensuel') echo '<span class="tag tag-blue">Mensuel</span>';
                    elseif ($ha['plan']==='annuel') echo '<span class="tag tag-purple">Annuel</span>';
                    else echo '<span class="tag tag-gray">'.sanitize($ha['plan']).'</span>'; ?></td>
                    <td style="font-weight:600"><?= number_format((float)$ha['prix'],0,',',' ') ?> F</td>
                    <td style="font-size:.73rem;color:#64748b"><?= formatDate($ha['date_debut']) ?></td>
                    <td style="font-size:.73rem;color:#64748b"><?= formatDate($ha['date_fin']) ?></td>
                    <td><?php echo ($ha['statut']==='actif' && $ha['date_fin']>=date('Y-m-d'))
                        ? '<span class="tag tag-green">Actif</span>'
                        : '<span class="tag tag-gray">Expiré</span>'; ?></td>
                </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php endif ?>

        <!-- Paiements -->
        <?php if (!empty($histPaie)): ?>
        <div class="panel">
            <h3>Paiements (<?= count($histPaie) ?>)</h3>
            <table class="at">
                <thead><tr><th>Date</th><th>Type</th><th>Montant</th><th>Mode</th><th>Réf.</th></tr></thead>
                <tbody>
                <?php foreach ($histPaie as $p): ?>
                <tr>
                    <td style="font-size:.7rem;color:#94a3b8;white-space:nowrap"><?= formatDate($p['created_at']) ?></td>
                    <td><span class="tag <?= $p['type']==='paiement'?'tag-green':'tag-blue' ?>"><?= sanitize($p['type']) ?></span></td>
                    <td style="font-weight:700;color:#16a34a"><?= number_format((float)$p['montant'],0,',',' ') ?> F</td>
                    <td style="font-size:.73rem;color:#64748b"><?= sanitize($p['mode_paiement']??'—') ?></td>
                    <td style="font-size:.7rem;font-family:monospace;color:#94a3b8"><?= sanitize($p['reference']??'—') ?></td>
                </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php endif ?>
    </div>
</div>

<!-- ─────────────────────── MODALS ──────────────────────────────────────────── -->

<!-- Activer -->
<div id="m-activer" class="modal-overlay">
    <div class="modal-card" style="max-width:400px">
        <div class="modal-header"><h3>Activer — <?= sanitize($t['nom_entreprise']) ?></h3><button class="modal-close" onclick="closeModal('m-activer')">&times;</button></div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?><input type="hidden" name="action" value="activer_compte">
            <?php echo forfaitCards('forfait'); ?>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('m-activer')">Annuler</button>
                <button type="submit" class="btn btn-primary">Activer le compte</button>
            </div>
        </form>
    </div>
</div>

<!-- Renouveler -->
<div id="m-prolong" class="modal-overlay">
    <div class="modal-card" style="max-width:400px">
        <div class="modal-header"><h3>Renouveler — <?= sanitize($t['nom_entreprise']) ?></h3><button class="modal-close" onclick="closeModal('m-prolong')">&times;</button></div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?><input type="hidden" name="action" value="prolonger_abo">
            <?php if ($ab): ?><p style="font-size:.8rem;color:#64748b;margin-bottom:12px">Expire le <strong><?= formatDate($ab['date_fin']) ?></strong></p><?php endif ?>
            <?php echo forfaitCards('forfait'); ?>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('m-prolong')">Annuler</button>
                <button type="submit" class="btn btn-primary">Renouveler</button>
            </div>
        </form>
    </div>
</div>

<!-- Paiement -->
<div id="m-paie" class="modal-overlay">
    <div class="modal-card" style="max-width:400px">
        <div class="modal-header"><h3>Paiement — <?= sanitize($t['nom_entreprise']) ?></h3><button class="modal-close" onclick="closeModal('m-paie')">&times;</button></div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?><input type="hidden" name="action" value="paiement">
            <div class="form-group"><label class="form-label">Montant (FCFA)</label><input type="number" name="montant" class="form-control" placeholder="20000" min="0" required></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div class="form-group"><label class="form-label">Mode</label>
                    <select name="mode" class="form-control">
                        <option value="mobile_money">Mobile Money</option>
                        <option value="wave">Wave</option>
                        <option value="orange_money">Orange Money</option>
                        <option value="especes">Espèces</option>
                        <option value="virement">Virement</option>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">Référence</label><input type="text" name="reference" class="form-control" placeholder="TXN…"></div>
            </div>
            <div class="form-group"><label class="form-label">Note</label><input type="text" name="description" class="form-control" placeholder="Mensuel mars 2026"></div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('m-paie')">Annuler</button>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- Modifier -->
<div id="m-edit" class="modal-overlay">
    <div class="modal-card" style="max-width:400px">
        <div class="modal-header"><h3>Modifier — <?= sanitize($t['nom_entreprise']) ?></h3><button class="modal-close" onclick="closeModal('m-edit')">&times;</button></div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?><input type="hidden" name="action" value="edit_tenant">
            <div class="form-group"><label class="form-label">Nom entreprise</label><input type="text" name="nom_entreprise" class="form-control" value="<?= sanitize($t['nom_entreprise']) ?>" required></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div class="form-group"><label class="form-label">Téléphone</label><input type="tel" name="telephone" class="form-control" value="<?= sanitize($t['telephone']??'') ?>"></div>
                <div class="form-group"><label class="form-label">Usage</label>
                    <select name="type_usage" class="form-control">
                        <option value="location" <?= ($t['type_usage']??'')==='location'?'selected':'' ?>>Location</option>
                        <option value="controle" <?= ($t['type_usage']??'')==='controle'?'selected':'' ?>>GPS</option>
                        <option value="les_deux" <?= ($t['type_usage']??'')==='les_deux'?'selected':'' ?>>Location + GPS</option>
                    </select>
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('m-edit')">Annuler</button>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- Supprimer -->
<div id="m-suppr" class="modal-overlay">
    <div class="modal-card" style="max-width:440px">
        <div class="modal-header" style="background:#fef2f2;border-bottom-color:#fca5a5">
            <h3 style="color:#dc2626">Supprimer définitivement</h3>
            <button class="modal-close" onclick="closeModal('m-suppr')">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?><input type="hidden" name="action" value="supprimer_tenant">
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:14px;margin-bottom:16px">
                <div style="font-weight:700;color:#dc2626;margin-bottom:4px;font-size:.85rem">Cette action est irréversible</div>
                <div style="font-size:.78rem;color:#7f1d1d;line-height:1.6">Tous les véhicules, clients, chauffeurs, locations, contrats, paiements et abonnements de <strong><?= sanitize($t['nom_entreprise']) ?></strong> seront supprimés.</div>
            </div>
            <div class="form-group">
                <label class="form-label">Tapez le nom exact pour confirmer :</label>
                <div style="font-family:monospace;font-size:.85rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;margin-bottom:8px;color:#0f172a"><?= sanitize($t['nom_entreprise']) ?></div>
                <input type="text" name="confirm_nom" class="form-control" placeholder="Nom de l'entreprise…" required autocomplete="off">
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('m-suppr')">Annuler</button>
                <button type="submit" class="btn btn-danger" style="background:#dc2626;color:#fff;border:none">Supprimer définitivement</button>
            </div>
        </form>
    </div>
</div>

<?php
function forfaitCards(string $name): string {
    return '
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px">
        <label style="cursor:pointer"><input type="radio" name="'.$name.'" value="mensuel" checked style="display:none" class="radio-forfait">
            <div class="forfait-card" data-val="mensuel" style="border:2px solid #0d9488;border-radius:12px;padding:16px;text-align:center;background:#eff6ff">
                <div style="font-size:.68rem;font-weight:700;color:#0d9488;text-transform:uppercase;margin-bottom:6px">Mensuel</div>
                <div style="font-size:1.25rem;font-weight:900;color:#0f172a">20 000 FCFA</div>
                <div style="font-size:.7rem;color:#64748b;margin-top:3px">30 jours</div>
            </div>
        </label>
        <label style="cursor:pointer"><input type="radio" name="'.$name.'" value="annuel" style="display:none" class="radio-forfait">
            <div class="forfait-card" data-val="annuel" style="border:2px solid #e2e8f0;border-radius:12px;padding:16px;text-align:center;background:#fff">
                <div style="font-size:.68rem;font-weight:700;color:#7c3aed;text-transform:uppercase;margin-bottom:6px">Annuel</div>
                <div style="font-size:1.25rem;font-weight:900;color:#0f172a">150 000 FCFA</div>
                <div style="font-size:.7rem;color:#16a34a;font-weight:700;margin-top:3px">Économie 90 000 F</div>
            </div>
        </label>
    </div>';
}
?>

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
