<?php
/**
 * FlotteCar - Ajouter un véhicule (3 profils: location, taxi, entreprise)
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
$plan     = getTenantPlan();

if (!canAddVehicule($db, $tenantId, $plan)) {
    setFlash(FLASH_WARNING, 'Limite de véhicules atteinte pour votre plan.');
    redirect(BASE_URL . 'app/vehicules/liste.php');
}

$erreurs = [];
$d       = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();

    $d = [
        'nom'                       => trim($_POST['nom']                   ?? ''),
        'immatriculation'           => strtoupper(trim($_POST['immatriculation'] ?? '')),
        'marque'                    => trim($_POST['marque']                ?? ''),
        'modele'                    => trim($_POST['modele']                ?? ''),
        'annee'                     => (int)($_POST['annee']                ?? 0),
        'couleur'                   => trim($_POST['couleur']               ?? ''),
        'type_carburant'            => trim($_POST['type_carburant']        ?? 'essence'),
        'places'                    => max(1, (int)($_POST['places']        ?? 5)),
        'numero_chassis'            => strtoupper(trim($_POST['numero_chassis'] ?? '')),
        'puissance_cv'              => ($_POST['puissance_cv'] ?? '') !== '' ? (int)$_POST['puissance_cv'] : null,
        'date_mise_en_service'      => $_POST['date_mise_en_service']       ?? null ?: null,
        'statut'                    => trim($_POST['statut']                ?? 'disponible'),
        'notes'                     => trim($_POST['notes']                 ?? ''),
        'type_vehicule'             => trim($_POST['type_vehicule']         ?? 'location'),
        'capital_investi'           => cleanNumber($_POST['capital_investi']     ?? 0),
        'km_initial_compteur'       => (int)($_POST['km_initial_compteur']       ?? 0),
        'recettes_initiales'        => cleanNumber($_POST['recettes_initiales']  ?? 0),
        'depenses_initiales'        => cleanNumber($_POST['depenses_initiales']  ?? 0),
        'prix_location_jour'        => cleanNumber($_POST['prix_location_jour']  ?? 0),
        'prochaine_vidange_km'      => ($_POST['prochaine_vidange_km'] ?? '') !== '' ? (int)$_POST['prochaine_vidange_km'] : null,
        'date_expiration_assurance' => $_POST['date_expiration_assurance']  ?? null ?: null,
        'date_expiration_vignette'  => $_POST['date_expiration_vignette']   ?? null ?: null,
        'imei'                      => trim($_POST['imei']                  ?? ''),
        'modele_boitier'            => trim($_POST['modele_boitier']        ?? ''),
    ];

    if (empty($d['nom']))             $erreurs[] = 'Le nom / code interne est obligatoire.';
    if (empty($d['immatriculation'])) $erreurs[] = "L'immatriculation est obligatoire.";

    if (empty($erreurs)) {
        $chk = $db->prepare("SELECT id FROM vehicules WHERE immatriculation = ? AND tenant_id = ?");
        $chk->execute([$d['immatriculation'], $tenantId]);
        if ($chk->fetch()) $erreurs[] = "L'immatriculation « {$d['immatriculation']} » existe déjà.";
    }

    if (empty($erreurs)) {
        $photoNom = null;
        if (!empty($_FILES['photo']['name'])) {
            $photoNom = uploadFile($_FILES['photo'], UPLOAD_LOGOS, ALLOWED_IMAGES);
            if (!$photoNom) $erreurs[] = 'Format photo invalide.';
        }
    }

    if (empty($erreurs)) {
        $traccarDeviceId = null;
        if (!empty($d['imei']) && hasGpsModule()) {
            try {
                $traccar = new TraccarAPI();
                if ($traccar->isAvailable()) {
                    $device = $traccar->createDevice($d['nom'] . ' (' . $d['immatriculation'] . ')', $d['imei']);
                    if ($device && !empty($device['id'])) $traccarDeviceId = (int)$device['id'];
                }
            } catch (Throwable $e) { error_log('Traccar: ' . $e->getMessage()); }
        }

        $stmt = $db->prepare("
            INSERT INTO vehicules (
                tenant_id, nom, immatriculation, marque, modele, annee, couleur,
                type_carburant, places, photo, numero_chassis, puissance_cv, date_mise_en_service,
                type_vehicule, capital_investi, km_initial_compteur, kilometrage_actuel,
                recettes_initiales, depenses_initiales, prix_location_jour, prochaine_vidange_km,
                date_expiration_assurance, date_expiration_vignette,
                imei, modele_boitier, traccar_device_id, statut, notes, created_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ");
        $stmt->execute([
            $tenantId, $d['nom'], $d['immatriculation'], $d['marque'], $d['modele'],
            $d['annee'] ?: null, $d['couleur'], $d['type_carburant'], $d['places'],
            $photoNom ?? null, $d['numero_chassis'] ?: null, $d['puissance_cv'],
            $d['date_mise_en_service'], $d['type_vehicule'],
            $d['capital_investi'] ?: null, $d['km_initial_compteur'], $d['km_initial_compteur'],
            $d['recettes_initiales'] ?: 0, $d['depenses_initiales'] ?: 0,
            $d['prix_location_jour'] ?: null, $d['prochaine_vidange_km'],
            $d['date_expiration_assurance'], $d['date_expiration_vignette'],
            $d['imei'] ?: null, $d['modele_boitier'] ?: null,
            $traccarDeviceId, $d['statut'], $d['notes'],
        ]);

        $vid   = (int)$db->lastInsertId();
        $label = "{$d['nom']} {$d['immatriculation']}";
        $urlVeh = BASE_URL . 'app/vehicules/detail.php?id=' . $vid;

        // ── Alertes assurance / vignette immédiates ──────────────────────
        $today = date('Y-m-d');
        $in15  = date('Y-m-d', strtotime('+15 days'));

        if (!empty($d['date_expiration_assurance'])) {
            $exp = $d['date_expiration_assurance'];
            if ($exp < $today) {
                pushNotif($db, $tenantId, 'alerte',
                    "🚨 Assurance expirée — $label",
                    "Expirée depuis le " . date('d/m/Y', strtotime($exp)),
                    $urlVeh);
            } elseif ($exp <= $in15) {
                $days = (int)ceil((strtotime($exp) - strtotime($today)) / 86400);
                pushNotif($db, $tenantId, 'alerte',
                    "⚠️ Assurance expire dans {$days}j — $label",
                    "Date limite : " . date('d/m/Y', strtotime($exp)),
                    $urlVeh);
            }
        }

        if (!empty($d['date_expiration_vignette'])) {
            $exp = $d['date_expiration_vignette'];
            if ($exp < $today) {
                pushNotif($db, $tenantId, 'alerte',
                    "🚨 Vignette expirée — $label",
                    "Expirée depuis le " . date('d/m/Y', strtotime($exp)),
                    $urlVeh);
            } elseif ($exp <= $in15) {
                $days = (int)ceil((strtotime($exp) - strtotime($today)) / 86400);
                pushNotif($db, $tenantId, 'alerte',
                    "⚠️ Vignette expire dans {$days}j — $label",
                    "Date limite : " . date('d/m/Y', strtotime($exp)),
                    $urlVeh);
            }
        }
        // ─────────────────────────────────────────────────────────────────

        logActivite($db, 'create', 'vehicules', "Ajout véhicule #{$vid} {$d['nom']}");
        $msg = "Véhicule « {$d['nom']} » ajouté avec succès.";
        if ($traccarDeviceId) $msg .= " GPS enregistré dans Traccar.";
        setFlash(FLASH_SUCCESS, $msg);
        redirect(BASE_URL . 'app/vehicules/liste.php');
    }
}

$annees     = range((int)date('Y'), 1990);
$pageTitle  = 'Ajouter un véhicule';
$activePage = 'vehicules';
require_once BASE_PATH . '/includes/header.php';
?>

<style>
/* ── Page spécifique ajouter véhicule ── */
.veh-type-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}
.veh-type-card {
    border: 2px solid var(--border);
    border-radius: 12px;
    padding: 18px 14px;
    cursor: pointer;
    display: block;
    transition: border-color .2s, box-shadow .2s;
    background: var(--card-bg);
    text-align: center;
}
.veh-type-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.08); }
.veh-type-card .type-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 10px;
    font-size: 1.4rem;
}
.veh-type-card .type-label { font-weight: 700; font-size: .95rem; margin-bottom: 4px; }
.veh-type-card .type-desc  { font-size: .78rem; color: var(--text-muted); line-height: 1.4; }

.two-col-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-top: 20px;
}

.form-actions-sticky {
    position: sticky;
    bottom: 0;
    background: var(--bg);
    border-top: 1px solid var(--border);
    padding: 14px 0;
    margin-top: 24px;
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    z-index: 10;
}

@media (max-width: 768px) {
    .veh-type-grid {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    .veh-type-card {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px 16px;
        text-align: left;
    }
    .veh-type-card .type-icon {
        width: 42px; height: 42px;
        flex-shrink: 0;
        margin: 0;
        font-size: 1.2rem;
    }
    .veh-type-card .type-label { margin-bottom: 2px; }

    .two-col-layout {
        grid-template-columns: 1fr !important;
        gap: 0;
    }

    .form-actions-sticky {
        flex-direction: column-reverse;
        padding: 12px;
        margin: 0 -10px;
    }
    .form-actions-sticky .btn { width: 100%; justify-content: center; }
}
</style>

<div class="page-header">
    <div>
        <a href="<?= BASE_URL ?>app/vehicules/liste.php" class="btn btn-secondary btn-sm" style="margin-bottom:6px">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <h1 class="page-title">Ajouter un véhicule</h1>
        <p class="page-subtitle">Renseignez les informations et le mode d'utilisation</p>
    </div>
</div>

<?php renderFlashes(); ?>
<?php if (!empty($erreurs)): ?>
<div class="alert alert-error" style="margin-bottom:16px">
    <i class="fas fa-times-circle"></i>
    <ul style="margin:.4rem 0 0 1rem;padding:0"><?php foreach ($erreurs as $e): ?><li><?= sanitize($e) ?></li><?php endforeach ?></ul>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
<?= csrfField() ?>

<!-- TYPE D'UTILISATION -->
<div class="card" style="margin-bottom:16px">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-tags"></i> Type d'utilisation</h3>
    </div>
    <div class="card-body">
        <?php
        $types = [
            'location'   => ['icon'=>'fa-key',      'label'=>'Location',    'desc'=>'Loué à des clients avec contrat', 'color'=>'#0d9488', 'bg'=>'#eff6ff'],
            'taxi'       => ['icon'=>'fa-taxi',     'label'=>'VTC / Taxi',  'desc'=>'Confié à un taximantre — versement journalier', 'color'=>'#f59e0b', 'bg'=>'#fffbeb'],
            'entreprise' => ['icon'=>'fa-building', 'label'=>'Entreprise',  'desc'=>'Affecté à un chauffeur interne', 'color'=>'#10b981', 'bg'=>'#ecfdf5'],
        ];
        $sel = $d['type_vehicule'] ?? 'location';
        ?>
        <div class="veh-type-grid">
            <?php foreach ($types as $val => $t): ?>
            <label class="veh-type-card" id="type-card-<?= $val ?>"
                   style="border-color:<?= $sel === $val ? $t['color'] : 'var(--border)' ?>;<?= $sel === $val ? "box-shadow:0 0 0 3px {$t['color']}22" : '' ?>">
                <input type="radio" name="type_vehicule" value="<?= $val ?>" style="display:none"
                       <?= $sel === $val ? 'checked' : '' ?> onchange="selectType('<?= $val ?>')">
                <div class="type-icon" style="background:<?= $t['bg'] ?>;color:<?= $t['color'] ?>">
                    <i class="fas <?= $t['icon'] ?>"></i>
                </div>
                <div>
                    <div class="type-label" style="color:<?= $sel === $val ? $t['color'] : 'var(--text)' ?>"><?= $t['label'] ?></div>
                    <div class="type-desc"><?= $t['desc'] ?></div>
                </div>
            </label>
            <?php endforeach ?>
        </div>
    </div>
</div>

<div class="two-col-layout">

<!-- COL GAUCHE -->
<div>
    <!-- Identification -->
    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-car"></i> Identification</h3></div>
        <div class="card-body">
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Nom / Code interne <span class="required">*</span></label>
                    <input type="text" name="nom" class="form-control" required
                           value="<?= sanitize($d['nom'] ?? '') ?>" placeholder="Ex: Corolla 01">
                </div>
                <div class="form-group">
                    <label class="form-label">Immatriculation <span class="required">*</span></label>
                    <input type="text" name="immatriculation" class="form-control" required
                           value="<?= sanitize($d['immatriculation'] ?? '') ?>"
                           placeholder="AB-1234-CD" style="text-transform:uppercase">
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Marque</label>
                    <input type="text" name="marque" class="form-control"
                           value="<?= sanitize($d['marque'] ?? '') ?>" placeholder="Toyota">
                </div>
                <div class="form-group">
                    <label class="form-label">Modèle</label>
                    <input type="text" name="modele" class="form-control"
                           value="<?= sanitize($d['modele'] ?? '') ?>" placeholder="Corolla">
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Année</label>
                    <select name="annee" class="form-control">
                        <option value="">--</option>
                        <?php foreach ($annees as $a): ?>
                        <option value="<?= $a ?>" <?= ($d['annee'] ?? 0) == $a ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Couleur</label>
                    <input type="text" name="couleur" class="form-control"
                           value="<?= sanitize($d['couleur'] ?? '') ?>" placeholder="Blanc perle">
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Carburant</label>
                    <select name="type_carburant" class="form-control">
                        <?php foreach (['essence'=>'Essence','diesel'=>'Diesel','electrique'=>'Électrique','hybride'=>'Hybride'] as $v=>$l): ?>
                        <option value="<?= $v ?>" <?= ($d['type_carburant'] ?? 'essence') === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Places</label>
                    <input type="number" name="places" class="form-control"
                           min="1" max="50" value="<?= (int)($d['places'] ?? 5) ?>">
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">N° Châssis (VIN)</label>
                    <input type="text" name="numero_chassis" class="form-control"
                           value="<?= sanitize($d['numero_chassis'] ?? '') ?>"
                           placeholder="17 caractères" style="text-transform:uppercase">
                </div>
                <div class="form-group">
                    <label class="form-label">Puissance (CV)</label>
                    <input type="number" name="puissance_cv" class="form-control"
                           min="1" value="<?= $d['puissance_cv'] ?? '' ?>" placeholder="90">
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Mise en service</label>
                    <input type="date" name="date_mise_en_service" class="form-control"
                           value="<?= $d['date_mise_en_service'] ?? '' ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Statut initial</label>
                    <select name="statut" class="form-control">
                        <option value="disponible"   <?= ($d['statut'] ?? 'disponible') === 'disponible'   ? 'selected' : '' ?>>Disponible</option>
                        <option value="maintenance"  <?= ($d['statut'] ?? '') === 'maintenance'  ? 'selected' : '' ?>>En maintenance</option>
                        <option value="indisponible" <?= ($d['statut'] ?? '') === 'indisponible' ? 'selected' : '' ?>>Indisponible</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Photo</label>
                <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
                <span class="form-hint">JPG, PNG, GIF, WEBP — max 10 Mo</span>
            </div>
        </div>
    </div>

    <!-- Documents -->
    <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-file-shield"></i> Documents</h3></div>
        <div class="card-body">
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Expiration assurance</label>
                    <input type="date" name="date_expiration_assurance" id="inp_assurance"
                           class="form-control" value="<?= $d['date_expiration_assurance'] ?? '' ?>"
                           onchange="checkDocDate(this,'warn_assurance')">
                    <div id="warn_assurance" style="display:none;margin-top:6px;padding:8px 10px;border-radius:8px;font-size:.82rem"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Expiration vignette</label>
                    <input type="date" name="date_expiration_vignette" id="inp_vignette"
                           class="form-control" value="<?= $d['date_expiration_vignette'] ?? '' ?>"
                           onchange="checkDocDate(this,'warn_vignette')">
                    <div id="warn_vignette" style="display:none;margin-top:6px;padding:8px 10px;border-radius:8px;font-size:.82rem"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- COL DROITE -->
<div>
    <!-- Finance -->
    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-coins"></i> Données financières initiales</h3></div>
        <div class="card-body">
            <div class="alert alert-info" style="margin-bottom:12px;font-size:.82rem">
                <i class="fas fa-info-circle"></i> Ces données servent au calcul de rentabilité dès le premier jour.
            </div>
            <div class="form-group">
                <label class="form-label">Capital investi (FCFA)</label>
                <input type="number" name="capital_investi" class="form-control"
                       min="0" value="<?= (int)($d['capital_investi'] ?? 0) ?>" placeholder="0">
                <span class="form-hint">Prix d'achat + frais d'immatriculation</span>
            </div>
            <div class="form-group">
                <label class="form-label">Km compteur initial</label>
                <input type="number" name="km_initial_compteur" class="form-control"
                       min="0" value="<?= (int)($d['km_initial_compteur'] ?? 0) ?>">
                <span class="form-hint">Kilométrage affiché lors de l'entrée en flotte</span>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Recettes initiales (FCFA)</label>
                    <input type="number" name="recettes_initiales" class="form-control"
                           min="0" value="<?= (int)($d['recettes_initiales'] ?? 0) ?>">
                    <span class="form-hint">Si déjà en exploitation</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Dépenses initiales (FCFA)</label>
                    <input type="number" name="depenses_initiales" class="form-control"
                           min="0" value="<?= (int)($d['depenses_initiales'] ?? 0) ?>">
                </div>
            </div>
            <div id="section-prix-jour">
                <div class="form-group">
                    <label class="form-label">Prix location / jour (FCFA)</label>
                    <input type="number" name="prix_location_jour" class="form-control"
                           min="0" value="<?= (int)($d['prix_location_jour'] ?? 0) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Maintenance -->
    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-wrench"></i> Maintenance</h3></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Prochaine vidange à (km)</label>
                <input type="number" name="prochaine_vidange_km" class="form-control" min="0"
                       value="<?= isset($d['prochaine_vidange_km']) ? (int)$d['prochaine_vidange_km'] : '' ?>"
                       placeholder="Ex: 15000">
                <span class="form-hint">Alerte automatique via GPS quand ce seuil est atteint</span>
            </div>
        </div>
    </div>

    <!-- GPS -->
    <?php if (hasGpsModule()): ?>
    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-satellite-dish"></i> Boîtier GPS</h3></div>
        <div class="card-body">
            <div class="alert alert-info" style="margin-bottom:12px;font-size:.82rem">
                <i class="fas fa-info-circle"></i> Le boîtier sera créé automatiquement dans Traccar.
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">IMEI</label>
                    <input type="text" name="imei" class="form-control"
                           value="<?= sanitize($d['imei'] ?? '') ?>" placeholder="123456789012345" maxlength="20">
                </div>
                <div class="form-group">
                    <label class="form-label">Modèle boîtier</label>
                    <input type="text" name="modele_boitier" class="form-control"
                           value="<?= sanitize($d['modele_boitier'] ?? '') ?>" placeholder="Teltonika FMB920">
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <input type="hidden" name="imei" value=""><input type="hidden" name="modele_boitier" value="">
    <?php endif; ?>

    <!-- Notes -->
    <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-sticky-note"></i> Notes</h3></div>
        <div class="card-body">
            <textarea name="notes" class="form-control" rows="3"
                      placeholder="Remarques, historique..."><?= sanitize($d['notes'] ?? '') ?></textarea>
        </div>
    </div>
</div>
</div>

<!-- Actions sticky en bas -->
<div class="form-actions-sticky">
    <a href="<?= BASE_URL ?>app/vehicules/liste.php" class="btn btn-secondary">
        <i class="fas fa-times"></i> Annuler
    </a>
    <button type="submit" class="btn btn-primary">
        <i class="fas fa-save"></i> Enregistrer le véhicule
    </button>
</div>

</form>

<script>
var _typeColors = {location:'#0d9488', taxi:'#f59e0b', entreprise:'#10b981'};

function selectType(val) {
    Object.keys(_typeColors).forEach(function(t) {
        var card = document.getElementById('type-card-'+t);
        if (!card) return;
        var active = (t === val);
        card.style.borderColor = active ? _typeColors[t] : 'var(--border)';
        card.style.boxShadow   = active ? '0 0 0 3px '+_typeColors[t]+'22' : 'none';
        card.querySelector('.type-label').style.color = active ? _typeColors[t] : 'var(--text)';
    });
    var p = document.getElementById('section-prix-jour');
    if (p) p.style.display = (val === 'location') ? '' : 'none';
}
selectType(document.querySelector('input[name=type_vehicule]:checked')?.value || 'location');

// Avertissement visuel sur les dates
function checkDocDate(input, warnId) {
    var warn = document.getElementById(warnId);
    if (!warn || !input.value) { if(warn) warn.style.display='none'; return; }
    var today = new Date(); today.setHours(0,0,0,0);
    var in15  = new Date(today); in15.setDate(in15.getDate()+15);
    var exp   = new Date(input.value);
    var label = warnId.includes('assurance') ? 'assurance' : 'vignette';
    var days  = Math.ceil((exp - today) / 86400000);
    if (exp < today) {
        warn.style.display = 'block';
        warn.style.background = '#fef2f2';
        warn.style.color = '#b91c1c';
        warn.style.border = '1px solid #fca5a5';
        warn.innerHTML = '🚨 <strong>Expirée !</strong> Une alerte sera envoyée à l\'enregistrement.';
    } else if (exp <= in15) {
        warn.style.display = 'block';
        warn.style.background = '#fffbeb';
        warn.style.color = '#92400e';
        warn.style.border = '1px solid #fcd34d';
        warn.innerHTML = '⚠️ Expire dans <strong>'+days+' jour(s)</strong>. Une alerte sera envoyée.';
    } else {
        warn.style.display = 'none';
    }
}

// Vérifier au chargement si valeurs déjà remplies (erreur de validation)
document.addEventListener('DOMContentLoaded', function() {
    var a = document.getElementById('inp_assurance');
    var v = document.getElementById('inp_vignette');
    if (a && a.value) checkDocDate(a, 'warn_assurance');
    if (v && v.value) checkDocDate(v, 'warn_vignette');
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
