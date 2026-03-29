<?php
/**
 * FlotteCar - Ajouter un chauffeur
 *
 * RÈGLES MÉTIER:
 *  - location   : mis à disposition lors d'une location client (pas de véhicule permanent)
 *  - entreprise : véhicule permanent assigné — surveillance seulement, PAS de versement
 *  - vtc        : véhicule permanent assigné + versement journalier (crée aussi un taximètre)
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

// Véhicules pour assignation (entreprise + VTC)
$stmtVehs = $db->prepare(
    "SELECT id, nom, immatriculation, type_vehicule FROM vehicules
     WHERE tenant_id = ? AND statut != 'indisponible'
     ORDER BY nom ASC"
);
$stmtVehs->execute([$tenantId]);
$vehicules = $stmtVehs->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();

    $nom          = trim($_POST['nom']          ?? '');
    $prenom       = trim($_POST['prenom']       ?? '');
    $telephone    = trim($_POST['telephone']    ?? '');
    $email        = trim($_POST['email']        ?? '');
    $numeroCni    = trim($_POST['numero_cni']   ?? '');
    $numeroPermis = trim($_POST['numero_permis']?? '');
    $datePermis   = $_POST['date_permis']       ?? null ?: null;
    $dateNaiss    = $_POST['date_naissance']    ?? null ?: null;
    $adresse      = trim($_POST['adresse']      ?? '');
    $type         = trim($_POST['type_chauffeur'] ?? 'location');
    $vehiculeId   = (int)($_POST['vehicule_id']  ?? 0) ?: null;
    $tarifJour    = cleanNumber($_POST['tarif_journalier'] ?? 0);
    $cautionVTC   = cleanNumber($_POST['caution_versee']   ?? 0);
    $dateDebut    = $_POST['date_debut_vtc']    ?? date('Y-m-d') ?: date('Y-m-d');
    $statut       = $_POST['statut']            ?? 'actif';
    $notes        = trim($_POST['notes']        ?? '');

    if (!$nom) { setFlash(FLASH_ERROR, 'Le nom est obligatoire.'); redirect(BASE_URL . 'app/chauffeurs/ajouter.php'); }
    if (in_array($type, ['vtc','entreprise']) && !$vehiculeId) {
        setFlash(FLASH_ERROR, 'Vous devez assigner un véhicule pour un chauffeur ' . ($type === 'vtc' ? 'VTC' : 'interne') . '.');
        redirect(BASE_URL . 'app/chauffeurs/ajouter.php');
    }
    if ($type === 'vtc' && $tarifJour <= 0) {
        setFlash(FLASH_ERROR, 'Le tarif journalier est obligatoire pour un chauffeur VTC.');
        redirect(BASE_URL . 'app/chauffeurs/ajouter.php');
    }

    $photo = null;
    if (!empty($_FILES['photo']['name'])) {
        $photo = uploadFile($_FILES['photo'], UPLOAD_LOGOS, ALLOWED_IMAGES);
        if (!$photo) { setFlash(FLASH_ERROR, 'Format photo invalide.'); redirect(BASE_URL . 'app/chauffeurs/ajouter.php'); }
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            INSERT INTO chauffeurs (
                tenant_id, nom, prenom, telephone, email,
                numero_cni, numero_permis, date_permis, date_naissance,
                adresse, type_chauffeur, vehicule_id, statut, photo, notes, created_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ");
        $stmt->execute([
            $tenantId, $nom, $prenom ?: null, $telephone ?: null, $email ?: null,
            $numeroCni ?: null, $numeroPermis ?: null, $datePermis, $dateNaiss,
            $adresse ?: null, $type, $vehiculeId, $statut, $photo, $notes ?: null,
        ]);
        $chaufId = (int)$db->lastInsertId();

        // VTC → créer aussi un taximètre pour le suivi des versements
        if ($type === 'vtc' && $vehiculeId) {
            $db->prepare("
                INSERT INTO taximetres (
                    tenant_id, vehicule_id, nom, prenom, telephone, numero_cni,
                    tarif_journalier, date_debut, caution_versee, statut
                ) VALUES (?,?,?,?,?,?,?,?,?,'actif')
            ")->execute([
                $tenantId, $vehiculeId, $nom, $prenom ?: null, $telephone ?: null,
                $numeroCni ?: null, $tarifJour, $dateDebut, $cautionVTC,
            ]);
        }

        // Entreprise ou VTC → marquer le véhicule comme "loué/assigné"
        if ($vehiculeId) {
            $db->prepare("UPDATE vehicules SET statut = 'loue' WHERE id = ? AND tenant_id = ?")->execute([$vehiculeId, $tenantId]);
        }

        $db->commit();
        logActivite($db, 'create', 'chauffeurs', "Ajout chauffeur $nom ($type)");
        setFlash(FLASH_SUCCESS, "Chauffeur « $nom » ajouté." . ($type === 'vtc' ? ' Fiche taximètre créée automatiquement.' : ''));
        redirect(BASE_URL . 'app/chauffeurs/liste.php');
    } catch (Throwable $e) {
        $db->rollBack();
        setFlash(FLASH_ERROR, 'Erreur lors de l\'enregistrement : ' . $e->getMessage());
    }
}

$pageTitle  = 'Ajouter un chauffeur';
$activePage = 'chauffeurs';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
.chauf-type-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
.chauf-type-card { border:2px solid var(--border); border-radius:8px; padding:12px; cursor:pointer; display:block; transition:.15s; }
.chauf-form-layout { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.chauf-actions { margin-top:16px; display:flex; gap:10px; justify-content:flex-end; }
@media(max-width:768px) {
    .chauf-type-grid { grid-template-columns:1fr; }
    .chauf-type-card { display:flex; align-items:center; gap:12px; padding:10px 14px; }
    .chauf-type-card i { font-size:1.2rem !important; display:inline !important; margin:0 !important; }
    .chauf-type-card .chauf-card-text { display:flex; flex-direction:column; }
    .chauf-form-layout { grid-template-columns:1fr; }
    .chauf-actions { position:sticky; bottom:0; background:#fff; padding:12px 16px; margin:0 -16px; border-top:1px solid var(--border); justify-content:stretch; z-index:10; }
    .chauf-actions .btn { flex:1; }
}
</style>

<div class="page-header">
    <div>
        <a href="<?= BASE_URL ?>app/chauffeurs/liste.php" class="btn btn-ghost btn-sm">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <h1 class="page-title">Ajouter un chauffeur</h1>
    </div>
</div>

<?= renderFlashes() ?>

<form method="POST" enctype="multipart/form-data">
<?= csrfField() ?>

<!-- TYPE DE CHAUFFEUR -->
<div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-tags"></i> Type de chauffeur</h3></div>
    <div class="card-body" style="padding:14px">
        <div class="chauf-type-grid">
            <?php
            $roles = [
                'location'   => ['icon'=>'fa-key',      'color'=>'#0d9488', 'label'=>'Conducteur location',
                                  'desc'=>'Disponible pour les contrats de location client'],
                'entreprise' => ['icon'=>'fa-building', 'color'=>'#10b981', 'label'=>'Chauffeur interne',
                                  'desc'=>'Véhicule permanent assigné — suivi GPS uniquement'],
                'vtc'        => ['icon'=>'fa-taxi',     'color'=>'#f59e0b', 'label'=>'Chauffeur VTC',
                                  'desc'=>'Véhicule permanent + versement journalier obligatoire'],
            ];
            foreach ($roles as $val => $r): ?>
            <label id="role-card-<?= $val ?>" class="chauf-type-card"
                   style="border-color:<?= $val==='location'?$r['color']:'var(--border)' ?>">
                <input type="radio" name="type_chauffeur" value="<?= $val ?>" style="display:none"
                       <?= $val==='location'?'checked':'' ?> onchange="selectRole('<?= $val ?>')">
                <i class="fas <?= $r['icon'] ?>" style="font-size:1.2rem;color:<?= $r['color'] ?>;display:block;margin-bottom:6px;flex-shrink:0"></i>
                <div class="chauf-card-text">
                    <strong style="display:block;font-size:.85rem;margin-bottom:3px"><?= $r['label'] ?></strong>
                    <small style="color:var(--text-muted);font-size:.75rem;line-height:1.3"><?= $r['desc'] ?></small>
                </div>
            </label>
            <?php endforeach ?>
        </div>
    </div>
</div>

<div class="chauf-form-layout">

<!-- COL GAUCHE -->
<div>
    <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-user"></i> Informations</h3></div>
        <div class="card-body">
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Nom *</label>
                    <input type="text" name="nom" class="form-control" required placeholder="Nom de famille">
                </div>
                <div class="form-group">
                    <label class="form-label">Prénom(s)</label>
                    <input type="text" name="prenom" class="form-control" placeholder="Prénom">
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Téléphone</label>
                    <input type="tel" name="telephone" class="form-control" placeholder="+225 07 00 00 00 00">
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="email@exemple.com">
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Date de naissance</label>
                    <input type="date" name="date_naissance" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Statut</label>
                    <select name="statut" class="form-control">
                        <option value="actif">Actif</option>
                        <option value="inactif">Inactif</option>
                    </select>
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">N° CNI</label>
                    <input type="text" name="numero_cni" class="form-control" placeholder="CI0098765432">
                </div>
                <div class="form-group">
                    <label class="form-label">N° Permis</label>
                    <input type="text" name="numero_permis" class="form-control" placeholder="Numéro permis">
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Date délivrance permis</label>
                    <input type="date" name="date_permis" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Photo</label>
                    <input type="file" name="photo" class="form-control" accept="image/*">
                </div>
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Expérience, remarques..."></textarea>
            </div>
        </div>
    </div>
</div>

<!-- COL DROITE -->
<div>

    <!-- Assignation véhicule (entreprise + VTC) -->
    <div class="card" id="section-vehicule" style="display:none;margin-bottom:16px">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-car"></i> Véhicule assigné</h3></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Véhicule *</label>
                <select name="vehicule_id" class="form-control">
                    <option value="">-- Sélectionner un véhicule --</option>
                    <?php foreach ($vehicules as $v): ?>
                    <option value="<?= $v['id'] ?>"><?= sanitize($v['nom']) ?> — <?= sanitize($v['immatriculation']) ?></option>
                    <?php endforeach ?>
                </select>
                <span class="form-hint" id="vehicule-hint-entreprise" style="display:none">Ce véhicule sera marqué "assigné" — suivi GPS activé</span>
                <span class="form-hint" id="vehicule-hint-vtc" style="display:none">Ce véhicule sera assigné en permanence au chauffeur VTC</span>
            </div>
        </div>
    </div>

    <!-- Paramètres VTC uniquement -->
    <div class="card" id="section-vtc" style="display:none;margin-bottom:16px">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-hand-holding-dollar"></i> Versements VTC</h3>
        </div>
        <div class="card-body">
            <div class="alert alert-info" style="margin-bottom:12px;font-size:.82rem">
                <i class="fas fa-info-circle"></i>
                Une fiche taximètre sera créée automatiquement pour suivre ses versements journaliers.
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Tarif journalier (FCFA) *</label>
                    <input type="number" name="tarif_journalier" class="form-control" min="0" step="500"
                           placeholder="Ex: 8000" value="0">
                    <span class="form-hint">Montant dû chaque jour de travail</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Caution versée (FCFA)</label>
                    <input type="number" name="caution_versee" class="form-control" min="0" step="1000"
                           placeholder="0" value="0">
                </div>
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Date de début</label>
                <input type="date" name="date_debut_vtc" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
        </div>
    </div>

    <!-- Info mode location -->
    <div class="card" id="section-location-info" style="margin-bottom:16px">
        <div class="card-body" style="padding:16px">
            <div style="display:flex;align-items:flex-start;gap:12px">
                <i class="fas fa-info-circle" style="color:#0d9488;font-size:1.1rem;margin-top:2px;flex-shrink:0"></i>
                <div style="font-size:.85rem;color:var(--text-muted)">
                    <strong style="color:var(--text);display:block;margin-bottom:4px">Conducteur location</strong>
                    Ce chauffeur sera disponible dans la liste "Avec chauffeur" lors de la création d'une location.
                    Pas de véhicule permanent — il est affecté à la demande.
                </div>
            </div>
        </div>
    </div>

    <!-- Info mode entreprise (masqué par défaut) -->
    <div class="card" id="section-entreprise-info" style="display:none;margin-bottom:16px">
        <div class="card-body" style="padding:16px">
            <div style="display:flex;align-items:flex-start;gap:12px">
                <i class="fas fa-info-circle" style="color:#10b981;font-size:1.1rem;margin-top:2px;flex-shrink:0"></i>
                <div style="font-size:.85rem;color:var(--text-muted)">
                    <strong style="color:var(--text);display:block;margin-bottom:4px">Chauffeur interne</strong>
                    Le véhicule lui est affecté en permanence. Vous pouvez suivre ses trajets via GPS,
                    son kilométrage et planifier les maintenances. Aucun versement journalier.
                </div>
            </div>
        </div>
    </div>

</div>
</div>

<div class="chauf-actions">
    <a href="<?= BASE_URL ?>app/chauffeurs/liste.php" class="btn btn-secondary">
        <i class="fas fa-times"></i> Annuler
    </a>
    <button type="submit" class="btn btn-primary">
        <i class="fas fa-save"></i> Enregistrer
    </button>
</div>
</form>

<script>
var roleColors = {'location':'#0d9488','entreprise':'#10b981','vtc':'#f59e0b'};

function selectRole(val) {
    Object.keys(roleColors).forEach(function(r) {
        document.getElementById('role-card-'+r).style.borderColor = (r===val) ? roleColors[r] : 'var(--border)';
    });

    var secVeh    = document.getElementById('section-vehicule');
    var secVtc    = document.getElementById('section-vtc');
    var secLocInf = document.getElementById('section-location-info');
    var secEntInf = document.getElementById('section-entreprise-info');
    var hintEnt   = document.getElementById('vehicule-hint-entreprise');
    var hintVtc   = document.getElementById('vehicule-hint-vtc');

    // Réinitialiser tout
    secVeh.style.display    = 'none';
    secVtc.style.display    = 'none';
    secLocInf.style.display = 'none';
    secEntInf.style.display = 'none';
    hintEnt.style.display   = 'none';
    hintVtc.style.display   = 'none';

    if (val === 'location') {
        secLocInf.style.display = '';
    } else if (val === 'entreprise') {
        secVeh.style.display    = '';
        secEntInf.style.display = '';
        hintEnt.style.display   = '';
    } else if (val === 'vtc') {
        secVeh.style.display = '';
        secVtc.style.display = '';
        hintVtc.style.display = '';
    }
}

// Init
selectRole('location');
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
