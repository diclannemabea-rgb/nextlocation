<?php
/**
 * FlotteCar — Modifier un chauffeur
 * Gère aussi le toggle_statut rapide depuis la liste
 */

define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';

requireTenantAuth();

$database = new Database();
$db       = $database->getConnection();
$tenantId = getTenantId();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    setFlash(FLASH_ERROR, 'Identifiant invalide.');
    redirect(BASE_URL . 'app/chauffeurs/liste.php');
}

$stmt = $db->prepare("SELECT * FROM chauffeurs WHERE id = ? AND tenant_id = ?");
$stmt->execute([$id, $tenantId]);
$ch = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$ch) {
    setFlash(FLASH_ERROR, 'Chauffeur introuvable.');
    redirect(BASE_URL . 'app/chauffeurs/liste.php');
}

// ── Véhicules disponibles (non déjà assignés, sauf celui du chauffeur) ──
$stVehs = $db->prepare("
    SELECT id, nom, immatriculation FROM vehicules
    WHERE tenant_id = ?
      AND (
        id NOT IN (SELECT vehicule_id FROM chauffeurs WHERE tenant_id = ? AND vehicule_id IS NOT NULL AND id != ?)
      )
    ORDER BY nom
");
$stVehs->execute([$tenantId, $tenantId, $id]);
$vehicules = $stVehs->fetchAll(PDO::FETCH_ASSOC);

// ── POST ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();

    // ── Toggle statut rapide (depuis liste) ──────────────
    if (isset($_POST['toggle_statut'])) {
        $newStatut = $_POST['toggle_statut'] === 'actif' ? 'actif' : 'inactif';
        $db->prepare("UPDATE chauffeurs SET statut = ? WHERE id = ? AND tenant_id = ?")
           ->execute([$newStatut, $id, $tenantId]);
        setFlash(FLASH_SUCCESS, 'Statut mis à jour.');
        redirect(BASE_URL . 'app/chauffeurs/liste.php');
    }

    // ── Formulaire complet ───────────────────────────────
    $nom = trim($_POST['nom'] ?? '');
    if (!$nom) {
        setFlash(FLASH_ERROR, 'Le nom est requis.');
        redirect(BASE_URL . 'app/chauffeurs/modifier.php?id=' . $id);
    }

    $photo = $ch['photo'];
    if (!empty($_FILES['photo']['name'])) {
        $newPhoto = uploadFile($_FILES['photo'], UPLOAD_LOGOS, ALLOWED_IMAGES);
        if ($newPhoto) $photo = $newPhoto;
    }

    $vehiculeId  = (int)($_POST['vehicule_id'] ?? 0) ?: null;
    $typeChauffeur = trim($_POST['type_chauffeur'] ?? 'interne');

    $db->prepare("
        UPDATE chauffeurs SET
            nom = ?, prenom = ?, telephone = ?, email = ?,
            numero_permis = ?, date_permis = ?,
            numero_cni = ?, date_naissance = ?,
            adresse = ?, statut = ?, photo = ?,
            type_chauffeur = ?, vehicule_id = ?,
            notes = ?
        WHERE id = ? AND tenant_id = ?
    ")->execute([
        $nom,
        trim($_POST['prenom']        ?? ''),
        trim($_POST['telephone']     ?? ''),
        trim($_POST['email']         ?? ''),
        trim($_POST['numero_permis'] ?? ''),
        $_POST['date_permis']        ?: null,
        trim($_POST['numero_cni']    ?? ''),
        $_POST['date_naissance']     ?: null,
        trim($_POST['adresse']       ?? ''),
        $_POST['statut']             ?? 'actif',
        $photo,
        $typeChauffeur,
        $vehiculeId,
        trim($_POST['notes']         ?? ''),
        $id, $tenantId,
    ]);

    // Si un véhicule assigné, mettre à jour le statut
    if ($vehiculeId) {
        // Désassigner de l'ancien chauffeur s'il y en avait un autre
        $db->prepare("UPDATE chauffeurs SET vehicule_id = NULL WHERE vehicule_id = ? AND id != ? AND tenant_id = ?")
           ->execute([$vehiculeId, $id, $tenantId]);
    }

    logActivite($db, 'UPDATE', 'chauffeurs', "Modification chauffeur #$id — $nom");
    setFlash(FLASH_SUCCESS, 'Chauffeur modifié avec succès.');
    redirect(BASE_URL . 'app/chauffeurs/liste.php');
}

$pageTitle  = 'Modifier chauffeur — ' . sanitize($ch['nom']);
$activePage = 'chauffeurs';
require_once BASE_PATH . '/includes/header.php';
?>

<style>
.type-cards { display:grid; grid-template-columns:repeat(3,1fr); gap:.75rem; margin-bottom:1rem; }
@media(max-width:500px){ .type-cards { grid-template-columns:1fr; } }
.type-card {
    border:2px solid #e5e7eb; border-radius:8px; padding:10px 14px;
    cursor:pointer; transition:all .15s; text-align:center; font-size:13px; user-select:none;
}
.type-card:hover { border-color:#0d9488; background:#eff6ff; }
.type-card.selected { border-color:#0d9488; background:#eff6ff; color:#0d9488; font-weight:600; }
.type-card i { display:block; font-size:1.4rem; margin-bottom:4px; }
</style>

<div class="page-header">
    <div class="page-header-left">
        <a href="<?= BASE_URL ?>app/chauffeurs/liste.php" class="btn btn-ghost btn-sm" style="margin-right:8px;">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div>
            <h2 class="page-title"><i class="fas fa-user-edit"></i> Modifier un chauffeur</h2>
            <p class="page-subtitle"><?= sanitize($ch['nom'] . ' ' . ($ch['prenom'] ?? '')) ?></p>
        </div>
    </div>
</div>

<?= renderFlashes() ?>

<form method="POST" enctype="multipart/form-data" style="max-width:860px;">
    <?= csrfField() ?>

    <!-- ① Type de chauffeur -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header">① Type de chauffeur</div>
        <div class="card-body">
            <div class="type-cards" id="type-cards">
                <?php
                $types = [
                    'location'  => ['fas fa-key',       'Location',   'Chauffeur pour locations client'],
                    'interne'   => ['fas fa-building',  'Interne',    'Employé entreprise'],
                    'vtc'       => ['fas fa-taxi',       'VTC / Taxi', 'Conducteur taxi ou VTC'],
                ];
                $currentType = $ch['type_chauffeur'] ?? 'interne';
                foreach ($types as $val => [$icon, $label, $desc]): ?>
                <div class="type-card <?= $currentType === $val ? 'selected' : '' ?>"
                     onclick="setType('<?= $val ?>')">
                    <i class="<?= $icon ?>"></i>
                    <strong><?= $label ?></strong>
                    <div style="font-size:11px;color:#6b7280;margin-top:2px;"><?= $desc ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="type_chauffeur" id="type_chauffeur" value="<?= sanitize($currentType) ?>">
        </div>
    </div>

    <!-- ② Identité -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header">② Identité</div>
        <div class="card-body">
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Nom <span style="color:red;">*</span></label>
                    <input type="text" name="nom" class="form-control" value="<?= sanitize($ch['nom']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Prénom</label>
                    <input type="text" name="prenom" class="form-control" value="<?= sanitize($ch['prenom'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Téléphone</label>
                    <input type="tel" name="telephone" class="form-control" value="<?= sanitize($ch['telephone'] ?? '') ?>" placeholder="Ex: 07XXXXXXXX">
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= sanitize($ch['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">N° CNI</label>
                    <input type="text" name="numero_cni" class="form-control" value="<?= sanitize($ch['numero_cni'] ?? '') ?>" placeholder="Numéro CNI">
                </div>
                <div class="form-group">
                    <label class="form-label">Date de naissance</label>
                    <input type="date" name="date_naissance" class="form-control" value="<?= $ch['date_naissance'] ?? '' ?>">
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Adresse</label>
                    <textarea name="adresse" class="form-control" rows="2" placeholder="Adresse complète"><?= sanitize($ch['adresse'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Photo <?= $ch['photo'] ? '<span style="color:#16a34a;font-size:11px;">✓ déjà uploadée</span>' : '' ?></label>
                    <input type="file" name="photo" class="form-control" accept="image/*">
                </div>
                <div class="form-group">
                    <label class="form-label">Statut</label>
                    <select name="statut" class="form-control">
                        <option value="actif"   <?= ($ch['statut'] ?? 'actif') === 'actif'   ? 'selected' : '' ?>>Actif</option>
                        <option value="inactif" <?= ($ch['statut'] ?? '') === 'inactif' ? 'selected' : '' ?>>Inactif</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- ③ Permis de conduire -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header">③ Permis de conduire</div>
        <div class="card-body">
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">N° Permis</label>
                    <input type="text" name="numero_permis" class="form-control" value="<?= sanitize($ch['numero_permis'] ?? '') ?>" placeholder="Numéro de permis">
                </div>
                <div class="form-group">
                    <label class="form-label">Date d'expiration permis</label>
                    <input type="date" name="date_permis" class="form-control" value="<?= $ch['date_permis'] ?? '' ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- ④ Véhicule assigné -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header">④ Véhicule assigné <span style="font-weight:400;font-size:12px;color:#6b7280;">(optionnel)</span></div>
        <div class="card-body">
            <div class="form-group" style="max-width:420px;">
                <label class="form-label">Véhicule</label>
                <select name="vehicule_id" class="form-control">
                    <option value="">— Aucun véhicule —</option>
                    <?php foreach ($vehicules as $v): ?>
                        <option value="<?= $v['id'] ?>" <?= (int)($ch['vehicule_id'] ?? 0) === $v['id'] ? 'selected' : '' ?>>
                            <?= sanitize($v['immatriculation'] . ' — ' . $v['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="form-hint">Pour les chauffeurs internes et VTC, assigner un véhicule permet le suivi.</span>
            </div>
        </div>
    </div>

    <!-- ⑤ Notes -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header">⑤ Notes</div>
        <div class="card-body">
            <div class="form-group">
                <textarea name="notes" class="form-control" rows="3" placeholder="Observations, remarques…"><?= sanitize($ch['notes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="<?= BASE_URL ?>app/chauffeurs/liste.php" class="btn btn-ghost">Annuler</a>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
    </div>
</form>

<script>
function setType(val) {
    document.getElementById('type_chauffeur').value = val;
    document.querySelectorAll('.type-card').forEach(function(c) { c.classList.remove('selected'); });
    document.querySelectorAll('.type-card').forEach(function(c, i) {
        const types = ['location','interne','vtc'];
        if (types[i] === val) c.classList.add('selected');
    });
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
