<?php
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireTenantAdmin();
$database = new Database();
$db = $database->getConnection();
$tenantId = getTenantId();

// Charger infos tenant et user
$stmt = $db->prepare("SELECT * FROM tenants WHERE id = ?");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([getUserId()]);
$user = $stmt->fetch();

// Abonnement actif
$stmt = $db->prepare("SELECT * FROM abonnements WHERE tenant_id = ? AND statut = 'actif' ORDER BY date_fin DESC LIMIT 1");
$stmt->execute([$tenantId]);
$abonnement = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'infos_entreprise') {
        $nom = trim($_POST['nom_entreprise'] ?? '');
        if (!$nom) { setFlash(FLASH_ERROR, 'Le nom est requis.'); redirect(BASE_URL . 'app/parametres/index.php'); }

        $logo = $tenant['logo'];
        if (!empty($_FILES['logo']['name'])) {
            $newLogo = uploadFile($_FILES['logo'], UPLOAD_LOGOS, ALLOWED_IMAGES);
            if ($newLogo) $logo = $newLogo;
        }

        $db->prepare("UPDATE tenants SET nom_entreprise=?, email=?, telephone=?, adresse=?, logo=? WHERE id=?")
           ->execute([$nom, trim($_POST['email']??''), trim($_POST['telephone']??''), trim($_POST['adresse']??''), $logo, $tenantId]);
        $_SESSION['tenant_nom'] = $nom;
        setFlash(FLASH_SUCCESS, 'Informations mises à jour.');
    }

    if ($action === 'mon_compte') {
        $nom = trim($_POST['nom'] ?? '');
        if (!$nom) { setFlash(FLASH_ERROR, 'Le nom est requis.'); redirect(BASE_URL . 'app/parametres/index.php'); }
        $db->prepare("UPDATE users SET nom=?, prenom=?, email=? WHERE id=? AND tenant_id=?")
           ->execute([$nom, trim($_POST['prenom']??''), trim($_POST['email']??''), getUserId(), $tenantId]);
        $_SESSION['user_nom'] = $nom;
        setFlash(FLASH_SUCCESS, 'Profil mis à jour.');
    }

    if ($action === 'changer_mdp') {
        $ancien = $_POST['ancien_mdp'] ?? '';
        $nouveau = $_POST['nouveau_mdp'] ?? '';
        $confirm = $_POST['confirm_mdp'] ?? '';
        if (!password_verify($ancien, $user['password'])) { setFlash(FLASH_ERROR, 'Ancien mot de passe incorrect.'); redirect(BASE_URL . 'app/parametres/index.php'); }
        if (strlen($nouveau) < 8) { setFlash(FLASH_ERROR, 'Le nouveau mot de passe doit avoir au moins 8 caractères.'); redirect(BASE_URL . 'app/parametres/index.php'); }
        if ($nouveau !== $confirm) { setFlash(FLASH_ERROR, 'Les mots de passe ne correspondent pas.'); redirect(BASE_URL . 'app/parametres/index.php'); }
        $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($nouveau, PASSWORD_BCRYPT), getUserId()]);
        setFlash(FLASH_SUCCESS, 'Mot de passe modifié avec succès.');
    }

    if ($action === 'contrat_params') {
        $fields = [
            'contrat_loueur_nom', 'contrat_loueur_rccm', 'contrat_loueur_adresse',
            'contrat_loueur_representant', 'contrat_loueur_tel', 'contrat_loueur_banque',
            'contrat_loueur_om', 'contrat_loueur_wave', 'contrat_loueur_ville',
            'contrat_penalite_retard', 'contrat_frais_carburant',
        ];
        foreach ($fields as $cle) {
            $val = trim($_POST[$cle] ?? '');
            $chk = $db->prepare("SELECT id FROM parametres WHERE tenant_id = ? AND cle = ?");
            $chk->execute([$tenantId, $cle]);
            if ($chk->fetch()) {
                $db->prepare("UPDATE parametres SET valeur = ? WHERE tenant_id = ? AND cle = ?")
                   ->execute([$val, $tenantId, $cle]);
            } else {
                $db->prepare("INSERT INTO parametres (tenant_id, cle, valeur) VALUES (?, ?, ?)")
                   ->execute([$tenantId, $cle, $val]);
            }
        }
        setFlash(FLASH_SUCCESS, 'Paramètres du contrat enregistrés.');
        redirect(BASE_URL . 'app/parametres/index.php?tab=contrat');
    }

    if ($action === 'supprimer_donnees') {
        $modules = $_POST['modules'] ?? [];
        $confirm = trim($_POST['confirm_suppression'] ?? '');
        if ($confirm !== 'SUPPRIMER') {
            setFlash(FLASH_ERROR, 'Confirmation incorrecte. Tapez exactement SUPPRIMER.');
            redirect(BASE_URL . 'app/parametres/index.php?tab=donnees');
        }
        $supprime = [];
        $map = [
            'locations'       => ['paiements','locations','reservations'],
            'clients'         => ['clients'],
            'taximetres'      => ['paiements_taxi','contraventions_taxi','taximetres'],
            'chauffeurs'      => ['chauffeurs'],
            'charges'         => ['charges'],
            'maintenances'    => ['maintenances'],
            'commerciaux'     => ['commerciaux'],
            'gps'             => ['alertes','positions_gps','logs_gps_commandes','evenements_gps'],
            'depenses'        => ['depenses_entreprise'],
            'notifs'          => ['notifs_push'],
            'logs'            => ['logs_activites'],
        ];
        foreach ($modules as $mod) {
            if (!isset($map[$mod])) continue;
            foreach ($map[$mod] as $table) {
                try {
                    $db->prepare("DELETE FROM `$table` WHERE tenant_id=?")->execute([$tenantId]);
                    $supprime[] = $table;
                } catch (Exception $e) {}
            }
        }
        if (in_array('tout', $modules)) {
            $allTables = ['paiements','locations','reservations','clients','paiements_taxi','contraventions_taxi',
                'taximetres','chauffeurs','charges','maintenances','commerciaux','alertes','positions_gps',
                'logs_gps_commandes','evenements_gps','depenses_entreprise','notifs_push','logs_activites',
                'caisse_config','push_subscriptions','regles_gps','alertes_regles','zones'];
            foreach ($allTables as $table) {
                try { $db->prepare("DELETE FROM `$table` WHERE tenant_id=?")->execute([$tenantId]); } catch(Exception $e) {}
            }
            try { $db->prepare("UPDATE vehicules SET kilometrage_actuel=0, statut='disponible' WHERE tenant_id=?")->execute([$tenantId]); } catch(Exception $e) {}
            logActivite($db, 'supprimer_toutes_donnees', 'parametres', 'Suppression totale des données du tenant');
            setFlash(FLASH_SUCCESS, '✅ Toutes les données ont été supprimées. Seuls vos véhicules et utilisateurs sont conservés.');
        } else {
            logActivite($db, 'supprimer_donnees', 'parametres', 'Suppression: '.implode(', ', $supprime));
            setFlash(FLASH_SUCCESS, '✅ Données supprimées : '.implode(', ', $supprime).'.');
        }
        redirect(BASE_URL . 'app/parametres/index.php?tab=donnees');
    }

    if ($action === 'wave_config') {
        $fields = ['wave_api_key','wave_webhook_secret','wave_merchant_number','wave_active'];
        foreach ($fields as $cle) {
            $val = trim($_POST[$cle] ?? '');
            $chk = $db->prepare("SELECT id FROM parametres WHERE tenant_id=? AND cle=?");
            $chk->execute([$tenantId, $cle]);
            if ($chk->fetch()) {
                $db->prepare("UPDATE parametres SET valeur=? WHERE tenant_id=? AND cle=?")->execute([$val, $tenantId, $cle]);
            } else {
                $db->prepare("INSERT INTO parametres (tenant_id,cle,valeur) VALUES (?,?,?)")->execute([$tenantId, $cle, $val]);
            }
        }
        setFlash(FLASH_SUCCESS, 'Configuration Wave enregistrée.');
        redirect(BASE_URL . 'app/parametres/index.php?tab=wave');
    }

    redirect(BASE_URL . 'app/parametres/index.php');
}

// Charger paramètres contrat
$contratParams = [];
$rcp = $db->prepare("SELECT cle, valeur FROM parametres WHERE tenant_id = ? AND cle LIKE 'contrat_%'");
$rcp->execute([$tenantId]);
foreach ($rcp->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $contratParams[$row['cle']] = $row['valeur'];
}
function cp(array $p, string $k, string $d = ''): string {
    return htmlspecialchars($p[$k] ?? $d, ENT_QUOTES, 'UTF-8');
}

// Charger paramètres Wave
$waveParams = [];
$rw = $db->prepare("SELECT cle, valeur FROM parametres WHERE tenant_id=? AND cle LIKE 'wave_%'");
$rw->execute([$tenantId]);
foreach ($rw->fetchAll() as $row) $waveParams[$row['cle']] = $row['valeur'];
function wp(array $p, string $k, string $d=''): string { return htmlspecialchars($p[$k] ?? $d, ENT_QUOTES, 'UTF-8'); }

// Nb jours restants abonnement
$joursRestants = 0;
$joursTotaux = 30;
if ($abonnement) {
    $joursRestants = max(0, (int)(new DateTime())->diff(new DateTime($abonnement['date_fin']))->days);
    $joursTotaux = max(1, (int)(new DateTime($abonnement['date_debut']))->diff(new DateTime($abonnement['date_fin']))->days);
}
$pct = min(100, round($joursRestants / $joursTotaux * 100));

$pageTitle = 'Paramètres';
$activePage = 'parametres';
require_once BASE_PATH . '/includes/header.php';
?>

<style>
/* ── Paramètres page — responsive mobile-first ─────────────────────────── */
.param-wrap {
    max-width: 720px;
}

/* Tab navigation — scrollable horizontal pills */
.param-tabs {
    display: flex;
    gap: 4px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    padding-bottom: 12px;
    margin-bottom: 16px;
}
.param-tabs::-webkit-scrollbar { display: none; }

.param-tab-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 8px 14px;
    border: 1.5px solid var(--border);
    border-radius: 10px;
    background: #fff;
    color: var(--text-muted);
    font-size: .72rem;
    font-weight: 600;
    white-space: nowrap;
    cursor: pointer;
    transition: all .15s;
    flex-shrink: 0;
}
.param-tab-btn i { font-size: .95rem; }
.param-tab-btn.active {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
}
.param-tab-btn.danger { border-color: #fecaca; color: #dc2626; }
.param-tab-btn.danger.active { background: #dc2626; border-color: #dc2626; color: #fff; }

/* Section card */
.param-section {
    background: #fff;
    border-radius: 12px;
    border: 1px solid var(--border);
    overflow: hidden;
    margin-bottom: 14px;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.param-section-header {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.param-section-header h3 {
    font-size: .88rem;
    font-weight: 700;
    margin: 0;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
}
.param-section-body { padding: 16px; }

/* 2-col form grid — collapses to 1 on mobile */
.param-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
@media (max-width: 560px) {
    .param-grid-2 { grid-template-columns: 1fr; gap: 0; }
    .param-tab-btn { padding: 8px 10px; font-size: .68rem; }
    .param-section-body { padding: 12px; }
}

/* Wave log cards on mobile */
.wave-log-item {
    padding: 10px 12px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: .8rem;
}
.wave-log-item:last-child { border-bottom: none; }

/* Danger zone module checkboxes */
.mod-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-bottom: 16px;
}
@media (max-width: 480px) {
    .mod-grid { grid-template-columns: 1fr; }
}
.mod-label {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    cursor: pointer;
    font-size: .82rem;
    transition: border-color .15s;
}
.mod-label:has(input:checked) { border-color: var(--primary); background: #eff6ff; }
.mod-label.disabled { opacity: .4; pointer-events: none; }
.mod-count { margin-left: auto; color: #94a3b8; font-size: .72rem; font-weight: 600; }

/* Feature list */
.feature-item {
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
    font-size: .84rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.feature-item:last-child { border-bottom: none; }

/* Info box */
.info-box {
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 16px;
    font-size: .82rem;
}
.info-box.blue { background: #f0f9ff; border: 1px solid #bae6fd; color: #0c4a6e; }
.info-box.yellow { background: #fefce8; border: 1px solid #fde68a; color: #78350f; }
.info-box.red { background: #fff1f2; border: 1px solid #fecdd3; color: #7f1d1d; }

/* Toggle switch */
.toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
    background: #f8fafc;
    border-radius: 8px;
    margin-bottom: 14px;
    gap: 12px;
}
.toggle-label { font-weight: 600; font-size: .86rem; }
.toggle-hint { font-size: .72rem; color: #64748b; margin-top: 2px; }
.toggle-switch {
    position: relative;
    display: inline-block;
    width: 46px;
    height: 26px;
    flex-shrink: 0;
    cursor: pointer;
}
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-track {
    position: absolute;
    inset: 0;
    border-radius: 26px;
    transition: background .3s;
}
.toggle-thumb {
    position: absolute;
    height: 20px;
    width: 20px;
    background: #fff;
    border-radius: 50%;
    top: 3px;
    transition: left .3s;
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
}

/* Abonnement badge */
.abo-plan-row {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 20px;
    padding: 16px;
    background: #f8fafc;
    border-radius: 10px;
}
.abo-plan-emoji { font-size: 2.4rem; flex-shrink: 0; }
.abo-plan-price { font-size: 1.3rem; font-weight: 700; margin-top: 4px; color: var(--text); }

/* Webhook url block */
.webhook-block {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.webhook-code {
    font-size: .78rem;
    background: #fff;
    border: 1px solid #fde68a;
    padding: 7px 10px;
    border-radius: 6px;
    flex: 1;
    word-break: break-all;
    min-width: 0;
    font-family: monospace;
}
</style>

<div class="page-header">
    <h1><i class="fas fa-cog"></i> Paramètres</h1>
</div>
<?= renderFlashes() ?>

<div class="param-wrap">

<!-- ── Tabs ─────────────────────────────────────────────────────────────── -->
<div class="param-tabs">
    <button class="param-tab-btn active" data-tab="entreprise">
        <i class="fas fa-building"></i> Entreprise
    </button>
    <button class="param-tab-btn" data-tab="compte">
        <i class="fas fa-user"></i> Mon compte
    </button>
    <button class="param-tab-btn" data-tab="abonnement">
        <i class="fas fa-credit-card"></i> Abonnement
    </button>
    <button class="param-tab-btn" data-tab="contrat">
        <i class="fas fa-file-contract"></i> Contrat
    </button>
    <button class="param-tab-btn" data-tab="wave">
        <i class="fas fa-mobile-alt"></i> Wave
    </button>
    <button class="param-tab-btn danger" data-tab="donnees">
        <i class="fas fa-trash-alt"></i> Données
    </button>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     TAB: Entreprise
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-entreprise">
<div class="param-section">
    <div class="param-section-header">
        <h3><i class="fas fa-building" style="color:var(--primary)"></i> Informations de l'entreprise</h3>
    </div>
    <div class="param-section-body">
    <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?><input type="hidden" name="action" value="infos_entreprise">
        <div class="form-group">
            <label class="form-label">Nom de l'entreprise <span class="required">*</span></label>
            <input type="text" name="nom_entreprise" class="form-control" value="<?= sanitize($tenant['nom_entreprise']) ?>" required>
        </div>
        <div class="param-grid-2">
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= sanitize($tenant['email']) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Téléphone</label>
                <input type="tel" name="telephone" class="form-control" value="<?= sanitize($tenant['telephone'] ?? '') ?>">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Adresse</label>
            <textarea name="adresse" class="form-control" rows="2"><?= sanitize($tenant['adresse'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label class="form-label">Logo <?= $tenant['logo'] ? '<span style="color:#22c55e;font-size:.75rem">(défini)</span>' : '' ?></label>
            <?php if ($tenant['logo']): ?>
            <img src="<?= BASE_URL ?>uploads/logos/<?= sanitize($tenant['logo']) ?>" style="height:52px;margin-bottom:8px;display:block;border-radius:6px;border:1px solid var(--border)">
            <?php endif; ?>
            <input type="file" name="logo" class="form-control" accept="image/*">
        </div>
        <div class="form-group">
            <label class="form-label">Type d'usage</label>
            <input type="text" class="form-control" value="<?= match($tenant['type_usage']) { 'location'=>'Location de véhicules', 'controle'=>'Contrôle de flotte GPS', default=>'Location + GPS' } ?>" disabled style="background:#f8fafc;color:#64748b">
            <small class="form-hint">Modifiable uniquement par le support FlotteCar.</small>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
        </div>
    </form>
    </div>
</div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     TAB: Mon compte
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-compte" style="display:none">

<div class="param-section">
    <div class="param-section-header">
        <h3><i class="fas fa-user-circle" style="color:var(--primary)"></i> Mon profil</h3>
    </div>
    <div class="param-section-body">
    <form method="POST">
        <?= csrfField() ?><input type="hidden" name="action" value="mon_compte">
        <div class="param-grid-2">
            <div class="form-group">
                <label class="form-label">Nom <span class="required">*</span></label>
                <input type="text" name="nom" class="form-control" value="<?= sanitize($user['nom']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Prénom</label>
                <input type="text" name="prenom" class="form-control" value="<?= sanitize($user['prenom'] ?? '') ?>">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= sanitize($user['email']) ?>">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Mettre à jour</button>
        </div>
    </form>
    </div>
</div>

<div class="param-section">
    <div class="param-section-header">
        <h3><i class="fas fa-key" style="color:#eab308"></i> Changer le mot de passe</h3>
    </div>
    <div class="param-section-body">
    <form method="POST">
        <?= csrfField() ?><input type="hidden" name="action" value="changer_mdp">
        <div class="form-group">
            <label class="form-label">Ancien mot de passe</label>
            <input type="password" name="ancien_mdp" class="form-control" required>
        </div>
        <div class="form-group">
            <label class="form-label">Nouveau mot de passe <span class="form-hint">(min. 8 caractères)</span></label>
            <input type="password" name="nouveau_mdp" class="form-control" minlength="8" required>
        </div>
        <div class="form-group">
            <label class="form-label">Confirmer le nouveau mot de passe</label>
            <input type="password" name="confirm_mdp" class="form-control" required>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-warning"><i class="fas fa-key"></i> Changer le mot de passe</button>
        </div>
    </form>
    </div>
</div>

</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     TAB: Abonnement
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-abonnement" style="display:none">
<div class="param-section">
    <div class="param-section-header">
        <h3><i class="fas fa-credit-card" style="color:var(--primary)"></i> Mon abonnement</h3>
    </div>
    <div class="param-section-body">
        <?php
        $forfaitActuel = $abonnement['plan'] ?? $tenant['plan'] ?? 'mensuel';
        $isMensuel = $forfaitActuel === 'mensuel';
        ?>
        <div class="abo-plan-row">
            <div class="abo-plan-emoji"><?= $isMensuel ? '📅' : '🎯' ?></div>
            <div>
                <div><span class="badge bg-primary"><?= $isMensuel ? 'Forfait Mensuel' : 'Forfait Annuel' ?></span></div>
                <div class="abo-plan-price"><?= $isMensuel ? '20 000' : '150 000' ?> FCFA<?= $isMensuel ? '/mois' : '/an' ?></div>
            </div>
        </div>

        <?php if ($abonnement): ?>
        <div style="margin-bottom:20px">
            <div style="display:flex;justify-content:space-between;font-size:.78rem;color:#64748b;margin-bottom:6px">
                <span>Début : <?= formatDate($abonnement['date_debut']) ?></span>
                <span>Fin : <?= formatDate($abonnement['date_fin']) ?></span>
            </div>
            <div style="background:var(--border);border-radius:8px;height:10px;overflow:hidden">
                <div style="width:<?= $pct ?>%;background:<?= $joursRestants < 7 ? '#ef4444' : ($joursRestants < 30 ? '#eab308' : '#22c55e') ?>;height:10px;border-radius:8px"></div>
            </div>
            <div style="text-align:right;font-size:.78rem;margin-top:4px;color:<?= $joursRestants < 7 ? '#ef4444' : 'var(--text-muted)' ?>;font-weight:600">
                <?= $joursRestants ?> jour(s) restant(s)
            </div>
        </div>
        <?php endif; ?>

        <div style="border-top:1px solid var(--border);padding-top:16px;margin-bottom:20px">
            <div style="font-size:.78rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">Fonctionnalités incluses</div>
            <?php
            $features = [
                'Gestion de flotte complète',
                'Véhicules illimités',
                'Suivi GPS temps réel',
                'Alertes & Coupure moteur',
                'Locations & Contrats PDF',
                'Finances & Rentabilité',
                'Rapports PDF & Export',
                'Taximètres & Versements',
                'Paiement Wave automatique',
                'Support WhatsApp',
            ];
            foreach ($features as $f):
            ?>
            <div class="feature-item">
                <i class="fas fa-check-circle" style="color:#22c55e;flex-shrink:0"></i><?= $f ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
            <div style="border:2px solid <?= $isMensuel ? 'var(--primary)' : 'var(--border)' ?>;border-radius:10px;padding:14px;text-align:center;background:<?= $isMensuel ? '#f0fdfa' : '#fff' ?>">
                <div style="font-size:.68rem;font-weight:700;color:var(--primary);text-transform:uppercase;margin-bottom:4px">Mensuel</div>
                <div style="font-size:1.1rem;font-weight:900">20 000 FCFA</div>
                <div style="font-size:.72rem;color:#64748b">sans engagement</div>
            </div>
            <div style="border:2px solid <?= !$isMensuel ? '#7c3aed' : 'var(--border)' ?>;border-radius:10px;padding:14px;text-align:center;background:<?= !$isMensuel ? '#faf5ff' : '#fff' ?>">
                <div style="font-size:.68rem;font-weight:700;color:#7c3aed;text-transform:uppercase;margin-bottom:4px">Annuel</div>
                <div style="font-size:1.1rem;font-weight:900">150 000 FCFA</div>
                <div style="font-size:.72rem;color:#16a34a;font-weight:600">Économie 90 000 F</div>
            </div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="https://wa.me/2250142518590?text=<?= rawurlencode('Bonjour FlotteCar ! Je souhaite renouveler mon abonnement. Entreprise: ' . getTenantNom() . ' · ID: #' . $tenantId) ?>" target="_blank" class="btn btn-primary">
                <i class="fab fa-whatsapp"></i> Renouveler via WhatsApp
            </a>
        </div>
    </div>
</div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     TAB: Contrat
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-contrat" style="display:none">
<div class="param-section">
    <div class="param-section-header">
        <h3><i class="fas fa-file-contract" style="color:var(--primary)"></i> Paramètres du contrat de location</h3>
    </div>
    <div class="param-section-body">
    <p style="font-size:.8rem;color:#64748b;margin-bottom:14px">Ces informations apparaîtront automatiquement dans le contrat de location téléchargeable.</p>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="contrat_params">

        <div class="param-grid-2">
            <div class="form-group">
                <label class="form-label">Nom de l'entreprise (Loueur)</label>
                <input type="text" name="contrat_loueur_nom" class="form-control" value="<?= cp($contratParams, 'contrat_loueur_nom', getTenantNom()) ?>" placeholder="Ex: Société XYZ SARL">
            </div>
            <div class="form-group">
                <label class="form-label">N° RCCM</label>
                <input type="text" name="contrat_loueur_rccm" class="form-control" value="<?= cp($contratParams, 'contrat_loueur_rccm') ?>" placeholder="CI-ABJ-03-2024-B12">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Adresse du siège social</label>
            <input type="text" name="contrat_loueur_adresse" class="form-control" value="<?= cp($contratParams, 'contrat_loueur_adresse') ?>" placeholder="Ex: Cocody Riviera, Abidjan">
        </div>

        <div class="param-grid-2">
            <div class="form-group">
                <label class="form-label">Représentant légal</label>
                <input type="text" name="contrat_loueur_representant" class="form-control" value="<?= cp($contratParams, 'contrat_loueur_representant') ?>" placeholder="Ex: M. Jean KOUAME">
            </div>
            <div class="form-group">
                <label class="form-label">Téléphone</label>
                <input type="text" name="contrat_loueur_tel" class="form-control" value="<?= cp($contratParams, 'contrat_loueur_tel') ?>" placeholder="07 57 41 7002">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Coordonnées bancaires (virement)</label>
            <input type="text" name="contrat_loueur_banque" class="form-control" value="<?= cp($contratParams, 'contrat_loueur_banque') ?>" placeholder="CI092 01007 002894940009 51">
        </div>

        <div class="param-grid-2">
            <div class="form-group">
                <label class="form-label">Numéro Orange Money</label>
                <input type="text" name="contrat_loueur_om" class="form-control" value="<?= cp($contratParams, 'contrat_loueur_om') ?>" placeholder="+225 07 57 41 7002">
            </div>
            <div class="form-group">
                <label class="form-label">Numéro Wave</label>
                <input type="text" name="contrat_loueur_wave" class="form-control" value="<?= cp($contratParams, 'contrat_loueur_wave') ?>" placeholder="+225 05 46 65 5652">
            </div>
        </div>

        <div class="param-grid-2">
            <div class="form-group">
                <label class="form-label">Ville (Tribunal de …)</label>
                <input type="text" name="contrat_loueur_ville" class="form-control" value="<?= cp($contratParams, 'contrat_loueur_ville', 'Abidjan') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Pénalité retard (FCFA/heure)</label>
                <input type="number" name="contrat_penalite_retard" class="form-control" value="<?= cp($contratParams, 'contrat_penalite_retard', '15000') ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Frais carburant manquant (FCFA)</label>
            <input type="number" name="contrat_frais_carburant" class="form-control" value="<?= cp($contratParams, 'contrat_frais_carburant', '10000') ?>">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
        </div>
    </form>
    </div>
</div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     TAB: Wave Business
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-wave" style="display:none">
<div class="param-section">
    <div class="param-section-header">
        <h3><i class="fas fa-mobile-alt" style="color:#1fb7e4"></i> Wave Business</h3>
        <span class="badge <?= !empty($waveParams['wave_active']) && $waveParams['wave_active']==='1' ? 'bg-success' : 'bg-secondary' ?>">
            <?= !empty($waveParams['wave_active']) && $waveParams['wave_active']==='1' ? 'Actif' : 'Inactif' ?>
        </span>
    </div>
    <div class="param-section-body">
        <div class="info-box blue" style="margin-bottom:14px">
            <div style="font-weight:700;color:#0369a1;margin-bottom:6px"><i class="fas fa-info-circle"></i> Comment ça fonctionne ?</div>
            <ol style="margin:0;padding-left:18px;line-height:1.8">
                <li>Vos chauffeurs paient sur votre numéro Wave Business</li>
                <li>Wave notifie FlotteCar via le webhook automatiquement</li>
                <li>FlotteCar identifie le chauffeur par son numéro</li>
                <li>Les jours impayés sont soldés (du plus ancien au plus récent)</li>
            </ol>
        </div>

        <div class="info-box yellow">
            <div style="font-weight:700;margin-bottom:8px"><i class="fas fa-link"></i> URL Webhook — à configurer dans Wave Business</div>
            <div class="webhook-block">
                <code class="webhook-code"><?= BASE_URL ?>api/wave_webhook.php</code>
                <button type="button" onclick="navigator.clipboard.writeText('<?= BASE_URL ?>api/wave_webhook.php');this.textContent='Copié ✓';setTimeout(()=>this.textContent='Copier',2000)" class="btn btn-warning btn-sm" style="flex-shrink:0">Copier</button>
            </div>
            <div style="font-size:.72rem;margin-top:6px">➜ Wave Business Dashboard → Settings → Webhooks</div>
        </div>

        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="wave_config">

            <div class="toggle-row">
                <div>
                    <div class="toggle-label">Activer l'intégration Wave</div>
                    <div class="toggle-hint">Les paiements Wave seront traités automatiquement</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="wave_active" value="1" id="wave-toggle"
                        <?= wp($waveParams,'wave_active')==='1' ? 'checked' : '' ?>
                        onchange="document.getElementById('wave-fields').style.display=this.checked?'block':'none';document.getElementById('wave-track').style.background=this.checked?'#10b981':'#cbd5e1';document.getElementById('wave-thumb').style.left=this.checked?'25px':'3px'">
                    <span class="toggle-track" id="wave-track" style="background:<?= wp($waveParams,'wave_active')==='1' ? '#10b981' : '#cbd5e1' ?>"></span>
                    <span class="toggle-thumb" id="wave-thumb" style="left:<?= wp($waveParams,'wave_active')==='1' ? '25' : '3' ?>px"></span>
                </label>
            </div>

            <div id="wave-fields" style="display:<?= wp($waveParams,'wave_active')==='1' ? 'block' : 'none' ?>">
                <div class="form-group">
                    <label class="form-label">API Key (clé secrète Wave Business)</label>
                    <input type="password" name="wave_api_key" class="form-control" value="<?= wp($waveParams,'wave_api_key') ?>" placeholder="wave_sn_prod_..." autocomplete="new-password">
                    <div class="form-hint">Wave Business Dashboard → API → Secret Key</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Webhook Secret</label>
                    <input type="password" name="wave_webhook_secret" class="form-control" value="<?= wp($waveParams,'wave_webhook_secret') ?>" placeholder="whsec_..." autocomplete="new-password">
                    <div class="form-hint">Wave Business Dashboard → Settings → Webhooks → Signing Secret</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Numéro Wave Business (marchand)</label>
                    <input type="text" name="wave_merchant_number" class="form-control" value="<?= wp($waveParams,'wave_merchant_number') ?>" placeholder="+225 07 57 41 7002">
                    <div class="form-hint">Le numéro sur lequel vos chauffeurs envoient l'argent</div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>

        <?php if (!empty($waveParams['wave_active']) && $waveParams['wave_active']==='1'): ?>
        <?php
        $rLogs = $db->prepare("SELECT description, created_at FROM logs_activites WHERE tenant_id=? AND action='paiement_wave' ORDER BY created_at DESC LIMIT 10");
        $rLogs->execute([$tenantId]);
        $wLogs = $rLogs->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div style="margin-top:20px;border-top:1px solid var(--border);padding-top:16px">
            <div style="font-size:.8rem;font-weight:700;color:#0f172a;margin-bottom:10px">
                <i class="fas fa-history" style="color:#1fb7e4;margin-right:5px"></i> Derniers paiements Wave reçus
            </div>
            <?php if (empty($wLogs)): ?>
            <div style="text-align:center;color:#94a3b8;font-size:.78rem;padding:20px">Aucun paiement Wave reçu pour le moment</div>
            <?php else: ?>
            <div style="border:1px solid var(--border);border-radius:8px;overflow:hidden">
                <?php foreach ($wLogs as $log):
                    $detail = json_decode($log['description'], true) ?? [];
                ?>
                <div class="wave-log-item">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
                        <span style="color:#10b981;font-weight:700">
                            <?= isset($detail['montant_recu']) ? number_format($detail['montant_recu'],0,'','  ') . ' FCFA' : '—' ?>
                        </span>
                        <span style="color:#64748b;font-size:.72rem"><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?></span>
                    </div>
                    <div style="color:#64748b;font-size:.75rem">
                        <?= (int)($detail['jours_payes']??0) ?> jour(s) soldé(s)
                        <?php if (!empty($detail['wave_ref'])): ?>
                        · Réf: <span style="font-family:monospace"><?= sanitize(substr($detail['wave_ref'],0,20)) ?></span>
                        <?php endif ?>
                    </div>
                </div>
                <?php endforeach ?>
            </div>
            <?php endif ?>
        </div>
        <?php endif ?>
    </div>
</div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     TAB: Données
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="tab-content" id="tab-donnees" style="display:none">
<?php
function countTable(PDO $db, string $table, int $tid): int {
    try { $s=$db->prepare("SELECT COUNT(*) FROM `$table` WHERE tenant_id=?"); $s->execute([$tid]); return (int)$s->fetchColumn(); }
    catch(Exception $e) { return 0; }
}
$stats = [
    'locations'    => countTable($db,'locations',$tenantId) + countTable($db,'paiements',$tenantId) + countTable($db,'reservations',$tenantId),
    'clients'      => countTable($db,'clients',$tenantId),
    'taximetres'   => countTable($db,'taximetres',$tenantId) + countTable($db,'paiements_taxi',$tenantId),
    'chauffeurs'   => countTable($db,'chauffeurs',$tenantId),
    'charges'      => countTable($db,'charges',$tenantId),
    'maintenances' => countTable($db,'maintenances',$tenantId),
    'commerciaux'  => countTable($db,'commerciaux',$tenantId),
    'gps'          => countTable($db,'positions_gps',$tenantId) + countTable($db,'alertes',$tenantId),
    'depenses'     => countTable($db,'depenses_entreprise',$tenantId),
    'logs'         => countTable($db,'logs_activites',$tenantId),
    'notifs'       => countTable($db,'notifs_push',$tenantId),
];
$labels = [
    'locations'=>'Locations & Paiements','clients'=>'Clients','taximetres'=>'Taximantres & Versements',
    'chauffeurs'=>'Chauffeurs','charges'=>'Charges véhicules','maintenances'=>'Maintenances',
    'commerciaux'=>'Commerciaux','gps'=>'Données GPS','depenses'=>'Dépenses entreprise',
    'logs'=>'Logs activités','notifs'=>'Notifications',
];
?>

<div class="info-box red" style="display:flex;gap:12px;align-items:flex-start">
    <span style="font-size:1.3rem;flex-shrink:0">⚠️</span>
    <div>
        <div style="font-weight:700;color:#dc2626;margin-bottom:4px">Zone dangereuse — Suppression irréversible</div>
        <div style="color:#9f1239;font-size:.8rem">Les données supprimées ne peuvent pas être récupérées. Vos véhicules et utilisateurs sont toujours conservés.</div>
    </div>
</div>

<!-- Volume de données -->
<div class="param-section">
    <div class="param-section-header">
        <h3><i class="fas fa-chart-bar" style="color:var(--primary)"></i> Volume de données actuel</h3>
    </div>
    <div style="padding: 0">
        <?php foreach ($stats as $key => $count): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 16px;border-bottom:1px solid #f1f5f9;font-size:.82rem">
            <span><?= $labels[$key] ?></span>
            <span style="font-weight:<?= $count>0?'700':'400' ?>;color:<?= $count>0?'#dc2626':'#94a3b8' ?>;font-size:.8rem">
                <?= number_format($count) ?> enreg.
            </span>
        </div>
        <?php endforeach ?>
    </div>
</div>

<!-- Suppression sélective -->
<div class="param-section">
    <div class="param-section-header">
        <h3><i class="fas fa-trash-alt" style="color:#dc2626"></i> Suppression sélective</h3>
    </div>
    <div class="param-section-body">
    <form method="POST" onsubmit="return confirmDelete(this)">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="supprimer_donnees">
        <div class="mod-grid">
        <?php foreach ($labels as $key => $label): ?>
        <label class="mod-label <?= $stats[$key]===0 ? 'disabled' : '' ?>">
            <input type="checkbox" name="modules[]" value="<?= $key ?>" style="width:15px;height:15px;flex-shrink:0">
            <span style="flex:1"><?= $label ?></span>
            <span class="mod-count"><?= number_format($stats[$key]) ?></span>
        </label>
        <?php endforeach ?>
        </div>
        <div class="form-group">
            <label class="form-label">Tapez <strong>SUPPRIMER</strong> pour confirmer</label>
            <input type="text" name="confirm_suppression" class="form-control" placeholder="SUPPRIMER" autocomplete="off">
        </div>
        <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Supprimer les modules sélectionnés</button>
    </form>
    </div>
</div>

<!-- Réinitialisation complète -->
<div class="param-section" style="border:2px solid #dc2626">
    <div class="param-section-header" style="background:#fff1f2">
        <h3><i class="fas fa-bomb" style="color:#dc2626"></i> Réinitialisation complète</h3>
    </div>
    <div class="param-section-body">
    <p style="font-size:.82rem;color:#64748b;margin-bottom:14px">Supprime <strong>toutes</strong> les données opérationnelles. Seuls vos véhicules et utilisateurs sont conservés.</p>
    <form method="POST" onsubmit="return confirmDelete(this, true)">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="supprimer_donnees">
        <input type="hidden" name="modules[]" value="tout">
        <div class="form-group">
            <label class="form-label">Tapez <strong>SUPPRIMER</strong> pour confirmer</label>
            <input type="text" name="confirm_suppression" class="form-control" placeholder="SUPPRIMER" autocomplete="off">
        </div>
        <button type="submit" class="btn btn-danger">💣 Tout réinitialiser</button>
    </form>
    </div>
</div>

<script>
function confirmDelete(form, tout=false) {
    const val = form.querySelector('[name="confirm_suppression"]').value;
    if (val !== 'SUPPRIMER') { alert('Tapez exactement SUPPRIMER pour confirmer.'); return false; }
    const msg = tout
        ? '⚠️ ATTENTION ! Action irréversible.\n\nToutes vos données opérationnelles seront supprimées définitivement.\n\nÊtes-vous absolument certain ?'
        : '⚠️ Les modules sélectionnés seront supprimés définitivement.\n\nContinuer ?';
    return confirm(msg);
}
</script>
</div>

</div><!-- /param-wrap -->

<script>
// Tab switching
document.querySelectorAll('.param-tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.param-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
        this.classList.add('active');
        document.getElementById('tab-' + this.dataset.tab).style.display = 'block';
    });
});

// Auto-activation via ?tab= dans l'URL
(function() {
    const tab = new URLSearchParams(window.location.search).get('tab');
    if (tab) {
        const btn = document.querySelector('.param-tab-btn[data-tab="' + tab + '"]');
        if (btn) btn.click();
    }
})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
