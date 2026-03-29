<?php
/**
 * FlotteCar — Modifier un client
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

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setFlash(FLASH_ERROR, 'Client introuvable.');
    redirect(BASE_URL . 'app/clients/liste.php');
}

$stmt = $db->prepare("SELECT * FROM clients WHERE id = ? AND tenant_id = ?");
$stmt->execute([$id, $tenantId]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) {
    setFlash(FLASH_ERROR, 'Client introuvable.');
    redirect(BASE_URL . 'app/clients/liste.php');
}

// ── POST ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();

    $nom = trim($_POST['nom'] ?? '');
    if (!$nom) {
        setFlash(FLASH_ERROR, 'Le nom est requis.');
        redirect(BASE_URL . 'app/clients/modifier.php?id=' . $id);
    }

    // Uploads
    $photoPiece = $client['photo_piece'];
    if (!empty($_FILES['photo_piece']['name'])) {
        $up = uploadFile($_FILES['photo_piece'], UPLOAD_DOCUMENTS, ALLOWED_IMAGES);
        if ($up) $photoPiece = $up;
    }

    $photoCarteGrise = $client['photo_carte_grise'];
    if (!empty($_FILES['photo_carte_grise']['name'])) {
        $up = uploadFile($_FILES['photo_carte_grise'], UPLOAD_DOCUMENTS, ALLOWED_IMAGES);
        if ($up) $photoCarteGrise = $up;
    }

    $typePiece = trim($_POST['type_piece'] ?? '');

    $db->prepare("
        UPDATE clients SET
            nom = ?, prenom = ?, telephone = ?, email = ?,
            adresse = ?, profession = ?, date_naissance = ?,
            piece_identite = ?, numero_piece = ?,
            type_piece = ?, photo_piece = ?,
            numero_carte_grise = ?, photo_carte_grise = ?,
            notes = ?
        WHERE id = ? AND tenant_id = ?
    ")->execute([
        $nom,
        trim($_POST['prenom']            ?? ''),
        trim($_POST['telephone']         ?? ''),
        trim($_POST['email']             ?? ''),
        trim($_POST['adresse']           ?? ''),
        trim($_POST['profession']        ?? ''),
        $_POST['date_naissance']         ?: null,
        $typePiece,                              // aussi stocker dans l'ancien champ
        trim($_POST['numero_piece']      ?? ''),
        $typePiece,
        $photoPiece,
        trim($_POST['numero_carte_grise']?? ''),
        $photoCarteGrise,
        trim($_POST['notes']             ?? ''),
        $id, $tenantId,
    ]);

    logActivite($db, 'UPDATE', 'clients', "Modification client #$id — $nom");
    setFlash(FLASH_SUCCESS, 'Client modifié avec succès.');
    redirect(BASE_URL . 'app/clients/liste.php');
}

// Dernières locations
$histStmt = $db->prepare("
    SELECT l.*, v.nom AS veh_nom, v.immatriculation
    FROM locations l
    JOIN vehicules v ON v.id = l.vehicule_id
    WHERE l.client_id = ? AND l.tenant_id = ?
    ORDER BY l.created_at DESC LIMIT 5
");
$histStmt->execute([$id, $tenantId]);
$historique = $histStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle  = 'Modifier client — ' . sanitize($client['nom']);
$activePage = 'clients';
require_once BASE_PATH . '/includes/header.php';

$typePieceLabels = [
    'cni'=>'CNI','passeport'=>'Passeport','permis'=>'Permis','carte_sejour'=>'Carte de séjour','autre'=>'Autre'
];
?>

<div class="page-header">
    <div class="page-header-left">
        <a href="<?= BASE_URL ?>app/clients/liste.php" class="btn btn-ghost btn-sm" style="margin-right:8px;">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div>
            <h2 class="page-title"><i class="fas fa-user-edit"></i> Modifier un client</h2>
            <p class="page-subtitle"><?= sanitize($client['nom'] . ' ' . ($client['prenom'] ?? '')) ?></p>
        </div>
    </div>
</div>

<?= renderFlashes() ?>

<form method="POST" enctype="multipart/form-data" style="max-width:860px;">
    <?= csrfField() ?>

    <!-- ① Informations personnelles -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header">① Informations personnelles</div>
        <div class="card-body">
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Nom <span style="color:red;">*</span></label>
                    <input type="text" name="nom" class="form-control" value="<?= sanitize($client['nom']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Prénom</label>
                    <input type="text" name="prenom" class="form-control" value="<?= sanitize($client['prenom'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Téléphone</label>
                    <input type="tel" name="telephone" class="form-control" value="<?= sanitize($client['telephone'] ?? '') ?>" placeholder="Ex: 07XXXXXXXX">
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= sanitize($client['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Profession</label>
                    <input type="text" name="profession" class="form-control" value="<?= sanitize($client['profession'] ?? '') ?>" placeholder="Ex: Ingénieur, Commerçant…">
                </div>
                <div class="form-group">
                    <label class="form-label">Date de naissance</label>
                    <input type="date" name="date_naissance" class="form-control" value="<?= $client['date_naissance'] ?? '' ?>">
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Adresse</label>
                    <textarea name="adresse" class="form-control" rows="2" placeholder="Adresse complète"><?= sanitize($client['adresse'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- ② Pièce d'identité -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header">② Pièce d'identité</div>
        <div class="card-body">
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Type de pièce</label>
                    <select name="type_piece" class="form-control">
                        <option value="">— Choisir —</option>
                        <?php foreach ($typePieceLabels as $val => $label): ?>
                            <option value="<?= $val ?>" <?= ($client['type_piece'] ?? $client['piece_identite'] ?? '') === $val ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Numéro de pièce</label>
                    <input type="text" name="numero_piece" class="form-control"
                           value="<?= sanitize($client['numero_piece'] ?? '') ?>"
                           placeholder="Numéro de la pièce">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">
                    Photo pièce d'identité
                    <?php if ($client['photo_piece']): ?>
                        <span style="color:#16a34a;font-size:11px;">✓ déjà uploadée</span>
                        <a href="<?= BASE_URL ?>uploads/documents/<?= sanitize($client['photo_piece']) ?>" target="_blank" style="font-size:11px;color:#0d9488;margin-left:4px;">Voir</a>
                    <?php endif; ?>
                </label>
                <input type="file" name="photo_piece" class="form-control" accept="image/*,.pdf">
                <span class="form-hint">Format : JPG, PNG, PDF — Max 5 Mo</span>
            </div>
        </div>
    </div>

    <!-- ③ Carte grise -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header">③ Carte grise (optionnel)</div>
        <div class="card-body">
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">N° carte grise</label>
                    <input type="text" name="numero_carte_grise" class="form-control"
                           value="<?= sanitize($client['numero_carte_grise'] ?? '') ?>"
                           placeholder="Numéro carte grise">
                </div>
                <div class="form-group">
                    <label class="form-label">
                        Photo carte grise
                        <?php if ($client['photo_carte_grise']): ?>
                            <span style="color:#16a34a;font-size:11px;">✓ déjà uploadée</span>
                            <a href="<?= BASE_URL ?>uploads/documents/<?= sanitize($client['photo_carte_grise']) ?>" target="_blank" style="font-size:11px;color:#0d9488;margin-left:4px;">Voir</a>
                        <?php endif; ?>
                    </label>
                    <input type="file" name="photo_carte_grise" class="form-control" accept="image/*,.pdf">
                </div>
            </div>
        </div>
    </div>

    <!-- ④ Notes -->
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header">④ Notes</div>
        <div class="card-body">
            <div class="form-group">
                <textarea name="notes" class="form-control" rows="3" placeholder="Observations, remarques…"><?= sanitize($client['notes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="<?= BASE_URL ?>app/clients/liste.php" class="btn btn-ghost">Annuler</a>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
    </div>
</form>

<!-- Historique locations -->
<?php if ($historique): ?>
<div class="card" style="max-width:860px;margin-top:1.5rem;">
    <div class="card-header">Dernières locations</div>
    <div style="overflow-x:auto;">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>Véhicule</th>
                    <th>Début</th>
                    <th>Fin</th>
                    <th style="text-align:right;">Montant</th>
                    <th>Statut</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($historique as $l): ?>
                <tr>
                    <td>
                        <strong><?= sanitize($l['immatriculation']) ?></strong><br>
                        <small style="color:#6b7280;"><?= sanitize($l['veh_nom']) ?></small>
                    </td>
                    <td><?= formatDate($l['date_debut']) ?></td>
                    <td><?= formatDate($l['date_fin']) ?></td>
                    <td style="text-align:right;font-weight:600;"><?= formatMoney((float)$l['montant_final']) ?></td>
                    <td><?= badgeLocation($l['statut']) ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>app/locations/detail.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-ghost" title="Voir">
                            <i class="fas fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
