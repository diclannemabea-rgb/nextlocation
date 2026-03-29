<?php
/**
 * FlotteCar — Modifier un véhicule
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
require_once BASE_PATH . '/includes/TraccarAPI.php';
requireTenantAuth();

$db       = (new Database())->getConnection();
$tenantId = getTenantId();

$vehiculeId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$vehiculeId) redirect(BASE_URL . 'app/vehicules/liste.php');

$stV = $db->prepare("SELECT * FROM vehicules WHERE id = ? AND tenant_id = ?");
$stV->execute([$vehiculeId, $tenantId]);
$veh = $stV->fetch(PDO::FETCH_ASSOC);
if (!$veh) {
    setFlash(FLASH_ERROR, 'Véhicule introuvable.');
    redirect(BASE_URL . 'app/vehicules/liste.php');
}

$erreurs = [];
$d       = $veh; // données affichées (pré-remplies)

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();

    $d = [
        // Identité
        'nom'                     => trim($_POST['nom']                     ?? ''),
        'immatriculation'         => strtoupper(trim($_POST['immatriculation'] ?? '')),
        'marque'                  => trim($_POST['marque']                  ?? ''),
        'modele'                  => trim($_POST['modele']                  ?? ''),
        'annee'                   => $_POST['annee']  ?: null,
        'couleur'                 => trim($_POST['couleur']                 ?? ''),
        'type_carburant'          => trim($_POST['type_carburant']          ?? ''),
        'carburant_type'          => trim($_POST['type_carburant']          ?? ''), // alias
        'places'                  => max(1, (int)($_POST['places']          ?? 5)),
        'type_vehicule'           => trim($_POST['type_vehicule']           ?? 'location'),
        'numero_chassis'          => trim($_POST['numero_chassis']          ?? ''),
        'puissance_cv'            => $_POST['puissance_cv'] ?: null,
        'date_mise_en_service'    => $_POST['date_mise_en_service']    ?: null,
        'date_expiration_assurance' => $_POST['date_expiration_assurance'] ?: null,
        'date_expiration_vignette'  => $_POST['date_expiration_vignette']  ?: null,
        // Finance
        'capital_investi'         => cleanNumber($_POST['capital_investi']         ?? '0'),
        'prix_location_jour'      => cleanNumber($_POST['prix_location_jour']      ?? '0'),
        'km_initial_compteur'     => (int)($_POST['km_initial_compteur']    ?? 0),
        'recettes_initiales'      => cleanNumber($_POST['recettes_initiales']      ?? '0'),
        'depenses_initiales'      => cleanNumber($_POST['depenses_initiales']      ?? '0'),
        'kilometrage_actuel'      => (int)($_POST['kilometrage_actuel']     ?? 0),
        'prochaine_vidange_km'    => $_POST['prochaine_vidange_km'] ?: null,
        // Statut
        'statut'                  => trim($_POST['statut']                  ?? 'disponible'),
        // GPS
        'imei'                    => trim($_POST['imei']                    ?? ''),
        'modele_boitier'          => trim($_POST['modele_boitier']          ?? ''),
        'notes'                   => trim($_POST['notes']                   ?? ''),
        // conservés
        'photo'                   => $veh['photo'],
        'traccar_device_id'       => $veh['traccar_device_id'],
    ];

    if (!$d['nom'])            $erreurs[] = 'Le nom est obligatoire.';
    if (!$d['immatriculation'])$erreurs[] = "L'immatriculation est obligatoire.";

    if (empty($erreurs)) {
        $ck = $db->prepare("SELECT id FROM vehicules WHERE immatriculation=? AND tenant_id=? AND id!=?");
        $ck->execute([$d['immatriculation'], $tenantId, $vehiculeId]);
        if ($ck->fetch()) $erreurs[] = "Immatriculation « {$d['immatriculation']} » déjà utilisée.";
    }

    if (empty($erreurs)) {
        // Photo
        if (!empty($_FILES['photo']['name'])) {
            $np = uploadFile($_FILES['photo'], UPLOAD_LOGOS, ALLOWED_IMAGES);
            if (!$np) {
                $erreurs[] = 'Format photo invalide.';
            } else {
                if ($veh['photo'] && file_exists(UPLOAD_LOGOS . $veh['photo'])) @unlink(UPLOAD_LOGOS . $veh['photo']);
                $d['photo'] = $np;
            }
        }
        if (!empty($_POST['supprimer_photo']) && $veh['photo']) {
            if (file_exists(UPLOAD_LOGOS . $veh['photo'])) @unlink(UPLOAD_LOGOS . $veh['photo']);
            $d['photo'] = null;
        }
    }

    if (empty($erreurs)) {
        // GPS Traccar
        $traccarId = $veh['traccar_device_id'];
        if (hasGpsModule()) {
            $oldImei = $veh['imei'] ?? '';
            $newImei = $d['imei'];
            if ($newImei && $newImei !== $oldImei) {
                try {
                    $t = new TraccarAPI();
                    if ($t->isAvailable()) {
                        if ($traccarId) {
                            $t->updateDevice($traccarId, ['name' => $d['nom'].' ('.$d['immatriculation'].')', 'uniqueId' => $newImei]);
                        } else {
                            $dev = $t->createDevice($d['nom'].' ('.$d['immatriculation'].')', $newImei);
                            if ($dev && !empty($dev['id'])) $traccarId = (int)$dev['id'];
                        }
                    }
                } catch (Throwable $e) { error_log('Traccar: '.$e->getMessage()); }
            } elseif (!$newImei && $traccarId) {
                $traccarId = null;
            }
        }

        $db->prepare("
            UPDATE vehicules SET
                nom=?, immatriculation=?, marque=?, modele=?, annee=?,
                couleur=?, type_carburant=?, carburant_type=?, places=?,
                type_vehicule=?, numero_chassis=?, puissance_cv=?,
                date_mise_en_service=?, date_expiration_assurance=?, date_expiration_vignette=?,
                photo=?,
                capital_investi=?, prix_location_jour=?,
                km_initial_compteur=?, recettes_initiales=?, depenses_initiales=?,
                kilometrage_actuel=?, prochaine_vidange_km=?,
                imei=?, modele_boitier=?, traccar_device_id=?,
                statut=?, notes=?, updated_at=NOW()
            WHERE id=? AND tenant_id=?
        ")->execute([
            $d['nom'], $d['immatriculation'], $d['marque'] ?: null, $d['modele'] ?: null, $d['annee'],
            $d['couleur'] ?: null, $d['type_carburant'] ?: null, $d['type_carburant'] ?: null, $d['places'],
            $d['type_vehicule'], $d['numero_chassis'] ?: null, $d['puissance_cv'] ?: null,
            $d['date_mise_en_service'], $d['date_expiration_assurance'], $d['date_expiration_vignette'],
            $d['photo'],
            $d['capital_investi'] ?: null, $d['prix_location_jour'] ?: null,
            $d['km_initial_compteur'] ?: 0, $d['recettes_initiales'] ?: 0, $d['depenses_initiales'] ?: 0,
            $d['kilometrage_actuel'] ?: 0, $d['prochaine_vidange_km'] ?: null,
            $d['imei'] ?: null, $d['modele_boitier'] ?: null, $traccarId,
            $d['statut'], $d['notes'] ?: null,
            $vehiculeId, $tenantId,
        ]);

        logActivite($db, 'update', 'vehicules', "Modif véhicule #$vehiculeId {$d['nom']}");
        setFlash(FLASH_SUCCESS, "Véhicule « {$d['nom']} » modifié avec succès.");
        redirect(BASE_URL . 'app/vehicules/detail.php?id=' . $vehiculeId);
    }
}

$pageTitle  = 'Modifier – ' . $veh['nom'];
$activePage = 'vehicules';
require_once BASE_PATH . '/includes/header.php';
?>

<style>
.mod-page { max-width: 960px; }
.mod-section {
    background:#fff; border:1px solid #e2e8f0; border-radius:8px;
    margin-bottom:12px; overflow:hidden;
}
.mod-head {
    display:flex; align-items:center; gap:8px;
    padding:9px 14px; background:#f8fafc; border-bottom:1px solid #e2e8f0;
    font-size:.78rem; font-weight:700; color:#475569;
    text-transform:uppercase; letter-spacing:.06em;
}
.mod-head i { color:#0d9488; }
.mod-body { padding:14px; }
.mod-row { display:grid; gap:10px; margin-bottom:10px; align-items:end; }
.mod-row:last-child { margin-bottom:0; }
.mod-row.c2 { grid-template-columns:1fr 1fr; }
.mod-row.c3 { grid-template-columns:1fr 1fr 1fr; }
.mod-row.c4 { grid-template-columns:1fr 1fr 1fr 1fr; }
.mod-row.c13 { grid-template-columns:1fr 3fr; }
.mod-row.c32 { grid-template-columns:3fr 2fr; }
.mf label { display:block; font-size:.72rem; font-weight:600; color:#64748b; margin-bottom:3px; }
.mf label .req { color:#ef4444; }
.mf .fc {
    width:100%; height:32px; padding:0 9px;
    border:1px solid #d1d5db; border-radius:6px;
    font-size:.825rem; color:#0f172a; background:#fff;
    outline:none; box-sizing:border-box;
    transition:border-color .15s;
}
.mf textarea.fc { height:auto; padding:7px 9px; }
.mf .fc:focus { border-color:#0d9488; box-shadow:0 0 0 3px rgba(26,86,219,.08); }
.mf .fc-ro { background:#f8fafc; color:#64748b; cursor:default; }
.type-cards { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; }
.type-card {
    border:2px solid #e2e8f0; border-radius:8px; padding:10px 8px;
    text-align:center; cursor:pointer; transition:all .15s; user-select:none;
}
.type-card:hover { border-color:#0d9488; background:#f0f7ff; }
.type-card input[type=radio] { display:none; }
.type-card input[type=radio]:checked + .tc-inner { opacity:1; }
.type-card.selected { border-color:#0d9488; background:#eff6ff; }
.tc-inner { display:flex; flex-direction:column; align-items:center; gap:4px; }
.tc-icon { font-size:1.2rem; }
.tc-lbl { font-size:.72rem; font-weight:700; color:#0f172a; }
.tc-sub { font-size:.65rem; color:#94a3b8; }
.doc-row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; }
.doc-item { background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px; }
.doc-item .dl { font-size:.68rem; color:#94a3b8; text-transform:uppercase; letter-spacing:.06em; margin-bottom:4px; }
</style>

<div class="mod-page">

<div class="page-header" style="margin-bottom:14px">
    <div>
        <a href="<?= BASE_URL ?>app/vehicules/detail.php?id=<?= $vehiculeId ?>" class="btn btn-secondary btn-sm" style="margin-bottom:5px">
            <i class="fas fa-arrow-left"></i> Retour fiche
        </a>
        <h1 class="page-title" style="margin-bottom:2px"><i class="fas fa-pen"></i> Modifier : <?= sanitize($veh['nom']) ?></h1>
        <p class="page-subtitle" style="margin:0"><?= sanitize($veh['immatriculation']) ?></p>
    </div>
    <button form="fMod" type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
</div>

<?php if (!empty($erreurs)): ?>
<div class="alert alert-error" style="margin-bottom:12px">
    <i class="fas fa-times-circle"></i>
    <?= implode(' · ', array_map('sanitize', $erreurs)) ?>
</div>
<?php endif ?>

<form method="POST" enctype="multipart/form-data" id="fMod">
<?= csrfField() ?>
<input type="hidden" name="id" value="<?= $vehiculeId ?>">

<!-- ══ TYPE VÉHICULE ══════════════════════════════════════════════════════ -->
<div class="mod-section">
    <div class="mod-head"><i class="fas fa-tag"></i> Type de véhicule</div>
    <div class="mod-body">
        <div class="type-cards">
            <?php
            $types = [
                'location'   => ['fas fa-key',      'Location',   'À louer à des clients', '#0d9488'],
                'taxi'       => ['fas fa-taxi',      'VTC / Taxi', 'Versements journaliers','#d97706'],
                'entreprise' => ['fas fa-building',  'Entreprise', 'Flotte interne GPS',    '#059669'],
                'personnel'  => ['fas fa-user',      'Personnel',  'Véhicule de direction', '#7c3aed'],
            ];
            foreach ($types as $val => [$icon, $lbl, $sub, $col]):
                $sel = ($d['type_vehicule'] ?? 'location') === $val;
            ?>
            <label class="type-card <?= $sel ? 'selected':'' ?>" onclick="setType('<?= $val ?>')" id="tc_<?= $val ?>">
                <input type="radio" name="type_vehicule" value="<?= $val ?>" <?= $sel ? 'checked':'' ?>>
                <div class="tc-inner">
                    <div class="tc-icon"><i class="<?= $icon ?>" style="color:<?= $col ?>"></i></div>
                    <div class="tc-lbl"><?= $lbl ?></div>
                    <div class="tc-sub"><?= $sub ?></div>
                </div>
            </label>
            <?php endforeach ?>
        </div>
    </div>
</div>

<!-- ══ IDENTITÉ ══════════════════════════════════════════════════════════ -->
<div class="mod-section">
    <div class="mod-head"><i class="fas fa-car"></i> Identification</div>
    <div class="mod-body">
        <div class="mod-row c32">
            <div class="mf">
                <label>Nom / Code interne <span class="req">*</span></label>
                <input type="text" name="nom" class="fc" value="<?= sanitize($d['nom']??'') ?>" required>
            </div>
            <div class="mf">
                <label>Immatriculation <span class="req">*</span></label>
                <input type="text" name="immatriculation" class="fc" style="text-transform:uppercase"
                       value="<?= sanitize($d['immatriculation']??'') ?>" required>
            </div>
        </div>
        <div class="mod-row c4">
            <div class="mf">
                <label>Marque</label>
                <input type="text" name="marque" class="fc" value="<?= sanitize($d['marque']??'') ?>">
            </div>
            <div class="mf">
                <label>Modèle</label>
                <input type="text" name="modele" class="fc" value="<?= sanitize($d['modele']??'') ?>">
            </div>
            <div class="mf">
                <label>Année</label>
                <select name="annee" class="fc">
                    <option value="">—</option>
                    <?php for($y=date('Y');$y>=1990;$y--): ?>
                    <option value="<?= $y ?>" <?= ($d['annee']??'') == $y ? 'selected':'' ?>><?= $y ?></option>
                    <?php endfor ?>
                </select>
            </div>
            <div class="mf">
                <label>Couleur</label>
                <input type="text" name="couleur" class="fc" value="<?= sanitize($d['couleur']??'') ?>" placeholder="Ex: Blanc">
            </div>
        </div>
        <div class="mod-row c4">
            <div class="mf">
                <label>Carburant</label>
                <select name="type_carburant" class="fc">
                    <option value="">—</option>
                    <?php foreach (['essence'=>'Essence','diesel'=>'Diesel','electrique'=>'Électrique','hybride'=>'Hybride'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= (($d['type_carburant']??$d['carburant_type']??'') === $v) ? 'selected':'' ?>><?= $l ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="mf">
                <label>Places</label>
                <input type="number" name="places" class="fc" min="1" max="50" value="<?= (int)($d['places']??5) ?>">
            </div>
            <div class="mf">
                <label>Puissance (cv)</label>
                <input type="number" name="puissance_cv" class="fc" min="0" value="<?= $d['puissance_cv']??'' ?>" placeholder="Ex: 90">
            </div>
            <div class="mf">
                <label>N° Chassis</label>
                <input type="text" name="numero_chassis" class="fc" value="<?= sanitize($d['numero_chassis']??'') ?>" placeholder="VIN…">
            </div>
        </div>
        <div class="mod-row c3">
            <div class="mf">
                <label>Mise en service</label>
                <input type="date" name="date_mise_en_service" class="fc" value="<?= $d['date_mise_en_service']??'' ?>">
            </div>
            <div class="mf">
                <label>Statut actuel</label>
                <select name="statut" class="fc">
                    <option value="disponible"   <?= ($d['statut']??'') === 'disponible'   ? 'selected':'' ?>>Disponible</option>
                    <option value="loue"         <?= ($d['statut']??'') === 'loue'         ? 'selected':'' ?>>Loué / Assigné</option>
                    <option value="maintenance"  <?= ($d['statut']??'') === 'maintenance'  ? 'selected':'' ?>>En maintenance</option>
                    <option value="indisponible" <?= ($d['statut']??'') === 'indisponible' ? 'selected':'' ?>>Indisponible</option>
                </select>
            </div>
            <div class="mf">
                <label>Photo</label>
                <input type="file" name="photo" class="fc" accept=".jpg,.jpeg,.png,.gif,.webp" style="padding:4px 9px;height:auto">
                <?php if (!empty($d['photo'])): ?>
                <div style="display:flex;align-items:center;gap:8px;margin-top:6px">
                    <img src="<?= BASE_URL ?>uploads/logos/<?= sanitize($d['photo']) ?>"
                         style="width:50px;height:38px;object-fit:cover;border-radius:4px;border:1px solid #e2e8f0">
                    <label style="display:flex;align-items:center;gap:5px;font-size:.75rem;cursor:pointer;color:#ef4444">
                        <input type="checkbox" name="supprimer_photo" value="1"> Supprimer
                    </label>
                </div>
                <?php endif ?>
            </div>
        </div>
    </div>
</div>

<!-- ══ DOCUMENTS ══════════════════════════════════════════════════════════ -->
<div class="mod-section">
    <div class="mod-head"><i class="fas fa-file-shield"></i> Documents & Expiration</div>
    <div class="mod-body">
        <div class="doc-row">
            <div class="doc-item">
                <?php
                $expA = $d['date_expiration_assurance']??'';
                $colA = !$expA ? '#94a3b8' : ($expA < date('Y-m-d') ? '#ef4444' : ($expA <= date('Y-m-d', strtotime('+30 days')) ? '#f59e0b' : '#10b981'));
                ?>
                <div class="dl"><i class="fas fa-shield-halved" style="color:<?= $colA ?>"></i> Assurance</div>
                <div class="mf">
                    <label>Expiration</label>
                    <input type="date" name="date_expiration_assurance" class="fc" value="<?= $expA ?>">
                </div>
            </div>
            <div class="doc-item">
                <?php
                $expV = $d['date_expiration_vignette']??'';
                $colV = !$expV ? '#94a3b8' : ($expV < date('Y-m-d') ? '#ef4444' : ($expV <= date('Y-m-d', strtotime('+30 days')) ? '#f59e0b' : '#10b981'));
                ?>
                <div class="dl"><i class="fas fa-file-alt" style="color:<?= $colV ?>"></i> Vignette</div>
                <div class="mf">
                    <label>Expiration</label>
                    <input type="date" name="date_expiration_vignette" class="fc" value="<?= $expV ?>">
                </div>
            </div>
            <div class="doc-item">
                <div class="dl"><i class="fas fa-oil-can" style="color:#f59e0b"></i> Prochaine vidange</div>
                <div class="mf">
                    <label>À (km)</label>
                    <input type="number" name="prochaine_vidange_km" class="fc" min="0"
                           value="<?= $d['prochaine_vidange_km']??'' ?>" placeholder="Ex: 85000">
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ FINANCIER ══════════════════════════════════════════════════════════ -->
<div class="mod-section">
    <div class="mod-head"><i class="fas fa-coins"></i> Données financières & Kilométrage</div>
    <div class="mod-body">
        <div class="mod-row c4">
            <div class="mf">
                <label>Capital investi (FCFA)</label>
                <input type="number" name="capital_investi" class="fc" min="0" step="10000"
                       value="<?= (int)($d['capital_investi']??0) ?>">
            </div>
            <div class="mf" id="sect_prix_jour" style="display:<?= in_array($d['type_vehicule']??'location',['location','taxi']) ? 'block':'none' ?>">
                <label>Prix location / jour (FCFA)</label>
                <input type="number" name="prix_location_jour" class="fc" min="0" step="500"
                       value="<?= (int)($d['prix_location_jour']??0) ?>">
            </div>
            <div class="mf">
                <label>Km actuel</label>
                <input type="number" name="kilometrage_actuel" class="fc" min="0"
                       value="<?= (int)($d['kilometrage_actuel']??0) ?>">
            </div>
            <div class="mf">
                <label>Km initial compteur</label>
                <input type="number" name="km_initial_compteur" class="fc" min="0"
                       value="<?= (int)($d['km_initial_compteur']??0) ?>">
            </div>
        </div>
        <div class="mod-row c2">
            <div class="mf">
                <label>Recettes initiales (FCFA) <span style="font-weight:400;color:#94a3b8">— avant entrée dans FlotteCar</span></label>
                <input type="number" name="recettes_initiales" class="fc" min="0" step="1000"
                       value="<?= (int)($d['recettes_initiales']??0) ?>">
            </div>
            <div class="mf">
                <label>Dépenses initiales (FCFA) <span style="font-weight:400;color:#94a3b8">— avant entrée dans FlotteCar</span></label>
                <input type="number" name="depenses_initiales" class="fc" min="0" step="1000"
                       value="<?= (int)($d['depenses_initiales']??0) ?>">
            </div>
        </div>
    </div>
</div>

<!-- ══ GPS ════════════════════════════════════════════════════════════════ -->
<?php if (hasGpsModule()): ?>
<div class="mod-section">
    <div class="mod-head">
        <i class="fas fa-satellite-dish"></i> Boîtier GPS
        <?php if (!empty($d['traccar_device_id'])): ?>
        <span class="badge bg-success" style="font-size:.65rem;margin-left:6px">Device #<?= (int)$d['traccar_device_id'] ?></span>
        <?php endif ?>
    </div>
    <div class="mod-body">
        <div class="mod-row c2">
            <div class="mf">
                <label>IMEI du boîtier</label>
                <input type="text" name="imei" class="fc" maxlength="20"
                       value="<?= sanitize($d['imei']??'') ?>" placeholder="Ex: 123456789012345">
                <div style="font-size:.7rem;color:#94a3b8;margin-top:3px">Modifier l'IMEI met à jour le device dans Traccar automatiquement.</div>
            </div>
            <div class="mf">
                <label>Modèle du boîtier</label>
                <input type="text" name="modele_boitier" class="fc"
                       value="<?= sanitize($d['modele_boitier']??'') ?>" placeholder="Ex: FMB920, TK103…">
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<input type="hidden" name="imei" value="<?= sanitize($d['imei']??'') ?>">
<input type="hidden" name="modele_boitier" value="<?= sanitize($d['modele_boitier']??'') ?>">
<?php endif ?>

<!-- ══ NOTES ═════════════════════════════════════════════════════════════ -->
<div class="mod-section">
    <div class="mod-head"><i class="fas fa-sticky-note"></i> Notes</div>
    <div class="mod-body">
        <div class="mf">
            <textarea name="notes" class="fc" rows="3"
                      placeholder="Observations, caractéristiques particulières…"><?= sanitize($d['notes']??'') ?></textarea>
        </div>
    </div>
</div>

<!-- Actions -->
<div style="display:flex;justify-content:flex-end;gap:10px;padding-bottom:24px">
    <a href="<?= BASE_URL ?>app/vehicules/detail.php?id=<?= $vehiculeId ?>" class="btn btn-secondary">
        <i class="fas fa-times"></i> Annuler
    </a>
    <button type="submit" class="btn btn-primary" style="min-width:180px">
        <i class="fas fa-save"></i> Enregistrer les modifications
    </button>
</div>

</form>
</div><!-- /mod-page -->

<script>
function setType(val) {
    document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
    const card = document.getElementById('tc_' + val);
    if (card) {
        card.classList.add('selected');
        card.querySelector('input[type=radio]').checked = true;
    }
    // Afficher prix/jour seulement pour location et taxi
    const showPrix = val === 'location' || val === 'taxi';
    document.getElementById('sect_prix_jour').style.display = showPrix ? 'block' : 'none';
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
