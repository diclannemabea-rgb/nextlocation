<?php
/**
 * FlotteCar - Ajouter un client
 * Pièce d'identité + photo pièce + carte grise + photo carte grise
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

$erreurs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();

    $nom             = trim($_POST['nom']               ?? '');
    $prenom          = trim($_POST['prenom']            ?? '');
    $telephone       = trim($_POST['telephone']         ?? '');
    $email           = trim($_POST['email']             ?? '');
    $adresse         = trim($_POST['adresse']           ?? '');
    $profession      = trim($_POST['profession']        ?? '');
    $dateNaiss       = $_POST['date_naissance']         ?? null ?: null;
    $typePiece       = trim($_POST['type_piece']        ?? '');
    $numeroPiece     = trim($_POST['numero_piece']      ?? '');
    $numCarteGrise   = trim($_POST['numero_carte_grise']?? '');
    $notes           = trim($_POST['notes']             ?? '');

    if (!$nom) $erreurs[] = 'Le nom du client est obligatoire.';

    if (empty($erreurs) && $telephone) {
        $chk = $db->prepare("SELECT id FROM clients WHERE telephone = ? AND tenant_id = ?");
        $chk->execute([$telephone, $tenantId]);
        if ($chk->fetch()) $erreurs[] = "Le téléphone « $telephone » est déjà utilisé par un autre client.";
    }

    if (empty($erreurs)) {
        // Upload photo pièce
        $photoPiece = null;
        if (!empty($_FILES['photo_piece']['name'])) {
            $photoPiece = uploadFile($_FILES['photo_piece'], UPLOAD_DOCUMENTS, ALLOWED_IMAGES);
            if (!$photoPiece) $erreurs[] = 'Format photo pièce invalide.';
        }

        // Upload photo carte grise
        $photoCarteGrise = null;
        if (!empty($_FILES['photo_carte_grise']['name'])) {
            $photoCarteGrise = uploadFile($_FILES['photo_carte_grise'], UPLOAD_DOCUMENTS, ALLOWED_IMAGES);
            if (!$photoCarteGrise) $erreurs[] = 'Format photo carte grise invalide.';
        }
    }

    if (empty($erreurs)) {
        $stmt = $db->prepare("
            INSERT INTO clients (
                tenant_id, nom, prenom, telephone, email, adresse, profession, date_naissance,
                type_piece, numero_piece, photo_piece,
                numero_carte_grise, photo_carte_grise,
                notes, created_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ");
        $stmt->execute([
            $tenantId, $nom, $prenom ?: null, $telephone ?: null, $email ?: null,
            $adresse ?: null, $profession ?: null, $dateNaiss,
            $typePiece ?: null, $numeroPiece ?: null, $photoPiece,
            $numCarteGrise ?: null, $photoCarteGrise,
            $notes ?: null,
        ]);

        $clientId = (int)$db->lastInsertId();
        logActivite($db, 'create', 'clients', "Ajout client #{$clientId} $nom");
        setFlash(FLASH_SUCCESS, "Client « $nom » ajouté avec succès.");

        // Retour vers location si on vient d'une redirection
        $redirect = $_POST['redirect'] ?? '';
        redirect($redirect ?: BASE_URL . 'app/clients/liste.php');
    }
}

$pageTitle  = 'Ajouter un client';
$activePage = 'clients';
require_once BASE_PATH . '/includes/header.php';
?>

<style>
/* ── Ajouter client ─────────────────────── */
.ac-section-label {
    display:flex; align-items:center; gap:8px;
    font-size:.67rem; font-weight:800; text-transform:uppercase;
    letter-spacing:.08em; color:#94a3b8; margin:0 0 10px;
}
.ac-section-label span {
    display:inline-flex; align-items:center; justify-content:center;
    width:20px; height:20px; border-radius:6px;
    background:#0d9488; color:#fff; font-size:.65rem; font-weight:800; flex-shrink:0;
}
.ac-section-label hr { flex:1; border:none; border-top:1px solid #e2e8f0; margin:0; }

.ac-upload-zone {
    border:2px dashed #e2e8f0; border-radius:10px;
    padding:14px; text-align:center; cursor:pointer;
    transition:border-color .15s, background .15s; background:#fafbfc;
    position:relative;
}
.ac-upload-zone:hover { border-color:#93c5fd; background:#eff6ff; }
.ac-upload-zone input[type=file] {
    position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%;
}
.ac-upload-zone .ac-up-icon { font-size:1.3rem; color:#93c5fd; margin-bottom:4px; }
.ac-upload-zone .ac-up-lbl  { font-size:.73rem; color:#64748b; font-weight:600; }
.ac-upload-zone .ac-up-hint { font-size:.67rem; color:#94a3b8; margin-top:2px; }

@media(max-width:640px) {
    .ac-grid { grid-template-columns:1fr !important; }
}
</style>

<!-- Header -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap">
    <a href="<?= BASE_URL ?>app/clients/liste.php" style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:9px;background:#f1f5f9;color:#475569;text-decoration:none;font-size:.8rem;flex-shrink:0">
        <i class="fas fa-arrow-left"></i>
    </a>
    <div>
        <h1 style="font-size:1.2rem;font-weight:800;color:#0f172a;margin:0">Nouveau client</h1>
        <p style="font-size:.75rem;color:#94a3b8;margin:2px 0 0">Identité · Pièce d'identité · Carte grise</p>
    </div>
</div>

<?php if (!empty($erreurs)): ?>
<div style="background:#fff5f5;border:1px solid #fecaca;border-radius:10px;padding:12px 14px;margin-bottom:14px;display:flex;gap:10px;align-items:flex-start">
    <i class="fas fa-times-circle" style="color:#ef4444;margin-top:2px;flex-shrink:0"></i>
    <ul style="margin:0;padding:0 0 0 16px;font-size:.82rem;color:#dc2626">
        <?php foreach ($erreurs as $e): ?><li><?= sanitize($e) ?></li><?php endforeach ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
<?= csrfField() ?>
<input type="hidden" name="redirect" value="<?= sanitize($_GET['redirect'] ?? '') ?>">

<div class="ac-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

<!-- ── COL GAUCHE ── -->
<div>
    <div class="card" style="overflow:hidden">
        <div style="padding:14px 16px 0">
            <div class="ac-section-label"><span>1</span> Identité personnelle <hr></div>
        </div>
        <div class="card-body" style="padding-top:8px">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div class="form-group" style="margin:0">
                    <label class="form-label">Nom <span style="color:#ef4444">*</span></label>
                    <input type="text" name="nom" class="form-control" required placeholder="Nom de famille" value="<?= sanitize($_POST['nom'] ?? '') ?>">
                </div>
                <div class="form-group" style="margin:0">
                    <label class="form-label">Prénom(s)</label>
                    <input type="text" name="prenom" class="form-control" placeholder="Prénom" value="<?= sanitize($_POST['prenom'] ?? '') ?>">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">
                <div class="form-group" style="margin:0">
                    <label class="form-label"><i class="fas fa-phone" style="color:#10b981;font-size:.7rem"></i> Téléphone</label>
                    <input type="tel" name="telephone" class="form-control" placeholder="+225 07 00 00 00" value="<?= sanitize($_POST['telephone'] ?? '') ?>">
                </div>
                <div class="form-group" style="margin:0">
                    <label class="form-label"><i class="fas fa-envelope" style="color:#6366f1;font-size:.7rem"></i> Email</label>
                    <input type="email" name="email" class="form-control" placeholder="email@exemple.ci" value="<?= sanitize($_POST['email'] ?? '') ?>">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">
                <div class="form-group" style="margin:0">
                    <label class="form-label">Date de naissance</label>
                    <input type="date" name="date_naissance" class="form-control" value="<?= sanitize($_POST['date_naissance'] ?? '') ?>">
                </div>
                <div class="form-group" style="margin:0">
                    <label class="form-label">Profession</label>
                    <input type="text" name="profession" class="form-control" placeholder="Ex: Commerçant" value="<?= sanitize($_POST['profession'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group" style="margin-top:10px;margin-bottom:0">
                <label class="form-label"><i class="fas fa-map-marker-alt" style="color:#f59e0b;font-size:.7rem"></i> Adresse</label>
                <textarea name="adresse" class="form-control" rows="2" placeholder="Quartier, commune, ville…"><?= sanitize($_POST['adresse'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
</div>

<!-- ── COL DROITE ── -->
<div style="display:flex;flex-direction:column;gap:14px">

    <!-- Pièce d'identité -->
    <div class="card" style="overflow:hidden">
        <div style="padding:14px 16px 0">
            <div class="ac-section-label"><span>2</span> Pièce d'identité <hr></div>
        </div>
        <div class="card-body" style="padding-top:8px">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div class="form-group" style="margin:0">
                    <label class="form-label">Type de pièce</label>
                    <select name="type_piece" class="form-control">
                        <option value="">-- Sélectionner --</option>
                        <?php foreach (['CNI'=>'CNI','Passeport'=>'Passeport','Permis'=>'Permis de conduire','Titre_sejour'=>'Titre de séjour','Autre'=>'Autre'] as $v=>$l): ?>
                        <option value="<?= $v ?>" <?= ($_POST['type_piece']??'')===$v?'selected':'' ?>><?= $l ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0">
                    <label class="form-label">N° de la pièce</label>
                    <input type="text" name="numero_piece" class="form-control" placeholder="CI0098765432" value="<?= sanitize($_POST['numero_piece'] ?? '') ?>">
                </div>
            </div>
            <div style="margin-top:10px">
                <label class="form-label" style="display:block;margin-bottom:6px">Photo de la pièce</label>
                <div class="ac-upload-zone">
                    <input type="file" name="photo_piece" accept=".jpg,.jpeg,.png,.gif,.webp">
                    <div class="ac-up-icon"><i class="fas fa-id-card"></i></div>
                    <div class="ac-up-lbl">Cliquer pour uploader</div>
                    <div class="ac-up-hint">JPG, PNG, WEBP · Recto</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Carte grise -->
    <div class="card" style="overflow:hidden">
        <div style="padding:14px 16px 0">
            <div class="ac-section-label"><span>3</span> Véhicule personnel <hr></div>
        </div>
        <div class="card-body" style="padding-top:8px">
            <div class="form-group" style="margin-bottom:10px">
                <label class="form-label">N° carte grise</label>
                <input type="text" name="numero_carte_grise" class="form-control" placeholder="CI-AB-1234" value="<?= sanitize($_POST['numero_carte_grise'] ?? '') ?>">
            </div>
            <div>
                <label class="form-label" style="display:block;margin-bottom:6px">Photo carte grise</label>
                <div class="ac-upload-zone">
                    <input type="file" name="photo_carte_grise" accept=".jpg,.jpeg,.png,.gif,.webp">
                    <div class="ac-up-icon"><i class="fas fa-car"></i></div>
                    <div class="ac-up-lbl">Cliquer pour uploader</div>
                    <div class="ac-up-hint">Photo du document</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Notes -->
    <div class="card" style="overflow:hidden">
        <div style="padding:14px 16px 0">
            <div class="ac-section-label"><span>4</span> Notes <hr></div>
        </div>
        <div class="card-body" style="padding-top:8px">
            <textarea name="notes" class="form-control" rows="3" placeholder="Observations, informations particulières…"><?= sanitize($_POST['notes'] ?? '') ?></textarea>
        </div>
    </div>

</div>
</div>

<!-- Actions -->
<div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap">
    <a href="<?= BASE_URL ?>app/clients/liste.php" class="btn btn-secondary"><i class="fas fa-times"></i> Annuler</a>
    <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Enregistrer le client</button>
</div>
</form>

<script>
// Afficher le nom du fichier sélectionné dans les zones d'upload
document.querySelectorAll('.ac-upload-zone input[type=file]').forEach(function(inp) {
    inp.addEventListener('change', function() {
        var lbl = this.closest('.ac-upload-zone').querySelector('.ac-up-lbl');
        if (this.files && this.files[0]) {
            lbl.textContent = this.files[0].name;
            lbl.style.color = '#0d9488';
        }
    });
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
