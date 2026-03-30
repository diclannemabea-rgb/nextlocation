<?php
/**
 * FlotteCar — Gestion boîtiers GPS (v4 — full actions)
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

$traccar   = new TraccarAPI();
$traccarOk = $traccar->isAvailable();

// ── Véhicules ────────────────────────────────────────────────────────────────
$vStmt = $db->prepare("
    SELECT id, nom, immatriculation, marque, modele, statut,
           imei, modele_boitier, traccar_device_id
    FROM vehicules
    WHERE tenant_id = ?
    ORDER BY (traccar_device_id IS NOT NULL) DESC, nom ASC
");
$vStmt->execute([$tenantId]);
$vehicules = $vStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Positions temps réel ──────────────────────────────────────────────────────
$traccarPositions = [];
$traccarDevices   = [];
if ($traccarOk) {
    // Charger tous les devices pour lookup par IMEI
    try { $traccarDevices = $traccar->getDevices(); } catch (Throwable $e) {}
    $ids = array_filter(array_column($vehicules, 'traccar_device_id'));
    if ($ids) {
        try {
            $positions = $traccar->get('/positions?' . http_build_query(['deviceId' => implode(',', $ids)]));
            if (is_array($positions)) {
                foreach ($positions as $p) $traccarPositions[$p['deviceId']] = $p;
            }
        } catch (Throwable $e) {}
    }
}

// Index devices Traccar par uniqueId (IMEI) pour lookup
$devicesByImei = [];
foreach ($traccarDevices as $d) {
    if (!empty($d['uniqueId'])) $devicesByImei[$d['uniqueId']] = $d;
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';
    $vehId  = (int)($_POST['vehicule_id'] ?? 0);

    // ── Synchroniser tous les IMEI sans traccar_device_id ───────────────────
    if ($action === 'sync_all') {
        if (!$traccarOk) {
            setFlash(FLASH_ERROR, 'Traccar est hors ligne. Impossible de synchroniser.');
            redirect(BASE_URL . 'app/gps/appareils.php');
        }
        $synced = 0; $errors = 0;
        $toSync = $db->prepare("SELECT * FROM vehicules WHERE tenant_id=? AND imei IS NOT NULL AND (traccar_device_id IS NULL OR traccar_device_id=0)");
        $toSync->execute([$tenantId]);
        $rows = $toSync->fetchAll(PDO::FETCH_ASSOC);
        $allDevices = $traccar->getDevices();
        $byImei = [];
        foreach ($allDevices as $d) { if (!empty($d['uniqueId'])) $byImei[$d['uniqueId']] = $d; }

        foreach ($rows as $row) {
            $imei = $row['imei'];
            $did  = null;
            if (isset($byImei[$imei])) {
                $did = (int)$byImei[$imei]['id'];
            } else {
                $name = $row['nom'] . ' (' . $row['immatriculation'] . ')';
                try {
                    $device = $traccar->createDevice($name, $imei);
                    if ($device && !empty($device['id'])) $did = (int)$device['id'];
                } catch (Throwable $e) {}
            }
            if ($did) {
                $db->prepare("UPDATE vehicules SET traccar_device_id=? WHERE id=? AND tenant_id=?")
                   ->execute([$did, $row['id'], $tenantId]);
                $synced++;
            } else {
                $errors++;
            }
        }
        setFlash($errors > 0 ? FLASH_WARNING : FLASH_SUCCESS,
            "$synced véhicule(s) synchronisé(s) avec Traccar." . ($errors > 0 ? " $errors échoué(s) — vérifiez la connexion Traccar." : ''));
        redirect(BASE_URL . 'app/gps/appareils.php');
    }

    // Vérification appartenance tenant (pour toutes les autres actions)
    $chk = $db->prepare("SELECT * FROM vehicules WHERE id=? AND tenant_id=?");
    $chk->execute([$vehId, $tenantId]);
    $veh = $chk->fetch();
    if (!$veh) {
        setFlash(FLASH_ERROR, 'Véhicule introuvable.');
        redirect(BASE_URL . 'app/gps/appareils.php');
    }

    // ── Associer / modifier un boîtier ──────────────────────────────────────
    if ($action === 'associer') {
        $imei   = preg_replace('/\D/', '', trim($_POST['imei'] ?? ''));
        $modele = trim($_POST['modele_boitier'] ?? '');

        if (strlen($imei) < 10) {
            setFlash(FLASH_ERROR, 'IMEI invalide (minimum 10 chiffres).');
            redirect(BASE_URL . 'app/gps/appareils.php');
        }

        // Doublon dans notre DB ?
        $dup = $db->prepare("SELECT id, nom FROM vehicules WHERE imei=? AND tenant_id=? AND id!=?");
        $dup->execute([$imei, $tenantId, $vehId]);
        if ($dup2 = $dup->fetch()) {
            setFlash(FLASH_ERROR, "L'IMEI $imei est déjà utilisé par «&nbsp;" . sanitize($dup2['nom']) . "&nbsp;».");
            redirect(BASE_URL . 'app/gps/appareils.php');
        }

        $traccarDeviceId = $veh['traccar_device_id'] ? (int)$veh['traccar_device_id'] : null;
        $msg = '';

        if ($traccarOk) {
            try {
                $name = $veh['nom'] . ' (' . $veh['immatriculation'] . ')';

                if ($traccarDeviceId) {
                    // Mise à jour device existant
                    $traccar->updateDevice($traccarDeviceId, ['name' => $name, 'uniqueId' => $imei]);
                    $msg = ' Device Traccar mis à jour (ID #' . $traccarDeviceId . ').';
                } else {
                    // 1. Chercher d'abord si ce device existe déjà dans Traccar par IMEI
                    $allDevices = $traccar->getDevices();
                    $existingDevice = null;
                    foreach ($allDevices as $d) {
                        if (($d['uniqueId'] ?? '') === $imei) {
                            $existingDevice = $d;
                            break;
                        }
                    }

                    if ($existingDevice) {
                        $traccarDeviceId = (int)$existingDevice['id'];
                        $msg = ' Device Traccar existant récupéré (ID #' . $traccarDeviceId . ').';
                    } else {
                        // 2. Créer un nouveau device
                        $device = $traccar->createDevice($name, $imei);
                        if ($device && !empty($device['id'])) {
                            $traccarDeviceId = (int)$device['id'];
                            $msg = ' Créé sur serveur GPS (ID #' . $traccarDeviceId . ').';
                        } else {
                            $msg = ' ⚠ IMEI enregistré — device Traccar non créé (vérifiez la connexion).';
                        }
                    }
                }
            } catch (Throwable $e) {
                $msg = ' (Traccar indisponible — IMEI sauvegardé, synchronisation différée.)';
            }
        } else {
            $msg = ' Traccar hors ligne — IMEI sauvegardé.';
        }

        $db->prepare("UPDATE vehicules SET imei=?, modele_boitier=?, traccar_device_id=? WHERE id=? AND tenant_id=?")
           ->execute([$imei, $modele ?: null, $traccarDeviceId, $vehId, $tenantId]);
        setFlash(FLASH_SUCCESS, 'Boîtier GPS associé à «&nbsp;' . sanitize($veh['nom']) . '&nbsp;».' . $msg);
    }

    // ── Dissocier ────────────────────────────────────────────────────────────
    if ($action === 'dissocier') {
        if ($veh['traccar_device_id'] && $traccarOk) {
            try { $traccar->deleteDevice((int)$veh['traccar_device_id']); } catch (Throwable $e) {}
        }
        $db->prepare("UPDATE vehicules SET imei=NULL, modele_boitier=NULL, traccar_device_id=NULL WHERE id=? AND tenant_id=?")
           ->execute([$vehId, $tenantId]);
        setFlash(FLASH_SUCCESS, 'Boîtier GPS dissocié.');
    }

    // ── Couper le moteur ─────────────────────────────────────────────────────
    if ($action === 'stop_engine') {
        if ($veh['traccar_device_id'] && $traccarOk) {
            $ok = $traccar->stopEngine((int)$veh['traccar_device_id']);
            setFlash($ok ? FLASH_SUCCESS : FLASH_ERROR,
                $ok ? 'Commande de coupure moteur envoyée.' : 'Échec de la commande (vérifiez que le boîtier supporte cette fonction).');
        } else {
            setFlash(FLASH_ERROR, 'Véhicule non configuré GPS ou Traccar hors ligne.');
        }
    }

    // ── Réactiver le moteur ──────────────────────────────────────────────────
    if ($action === 'start_engine') {
        if ($veh['traccar_device_id'] && $traccarOk) {
            $ok = $traccar->resumeEngine((int)$veh['traccar_device_id']);
            setFlash($ok ? FLASH_SUCCESS : FLASH_ERROR,
                $ok ? 'Commande de remise en marche envoyée.' : 'Échec de la commande.');
        } else {
            setFlash(FLASH_ERROR, 'Véhicule non configuré GPS ou Traccar hors ligne.');
        }
    }

    redirect(BASE_URL . 'app/gps/appareils.php');
}

// ── Compteurs ─────────────────────────────────────────────────────────────────
$nbConfigures = count(array_filter($vehicules, fn($v) => !empty($v['traccar_device_id'])));
$nbImeiSansId = count(array_filter($vehicules, fn($v) => !empty($v['imei']) && empty($v['traccar_device_id'])));
$nbOnline = 0;
foreach ($vehicules as $v) {
    if (!empty($v['traccar_device_id'])) {
        $p = $traccarPositions[$v['traccar_device_id']] ?? null;
        if ($p && isset($p['fixTime']) && (time() - strtotime($p['fixTime'])) < 300) $nbOnline++;
    }
}

$pageTitle  = 'Boîtiers GPS';
$activePage = 'gps_appareils';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
/* ── GPS Appareils — mobile first ──────────────────────────── */
.online-dot { width:8px;height:8px;border-radius:50%;display:inline-block;flex-shrink:0; }
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }
.blink { animation:blink 1.5s infinite; }
.proto-code { display:inline-block;padding:2px 7px;border-radius:4px;font-size:.7rem;font-weight:700;background:#e0e7ff;color:#3730a3;font-family:monospace; }
.port-code { display:inline-block;padding:2px 9px;border-radius:4px;font-size:.75rem;font-weight:800;background:#fef3c7;color:#92400e;font-family:monospace; }
.action-btn { display:inline-flex;align-items:center;gap:4px;padding:4px 9px;border-radius:6px;font-size:.72rem;font-weight:600;border:none;cursor:pointer;text-decoration:none; }
.speed-badge { display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:99px;font-size:.73rem;font-weight:700; }

/* Table desktop */
.gps-row td { vertical-align:middle; padding:11px 13px; border-bottom:1px solid #f1f5f9; font-size:.83rem; }
.gps-row:hover td { background:#f8fafc; }
.gps-head th { padding:8px 13px; font-size:.65rem; text-transform:uppercase; letter-spacing:.07em; color:#94a3b8; background:#f8fafc; border-bottom:2px solid #e2e8f0; white-space:nowrap; }

/* Véhicule cards (mobile) */
.veh-cards { display:none; }
.veh-card {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
}
.veh-card:last-child { border-bottom: none; }
.veh-card-top { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
.veh-card-actions { display:flex; gap:6px; flex-wrap:wrap; margin-top:10px; }
.veh-card-meta { display:flex; flex-wrap:wrap; gap:6px; font-size:.75rem; color:#64748b; }
.veh-card-meta span { display:flex; align-items:center; gap:4px; }

/* KPI grid responsive */
.gps-kpis { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px; }

/* Guide steps */
.guide-steps { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; padding:16px; border-bottom:1px solid #f1f5f9; }

/* Boitier cards in guide */
.boitier-cards { display:none; }
.boitier-card {
    padding: 10px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #fff;
    margin-bottom: 8px;
}
.boitier-card-title { font-weight:700; font-size:.82rem; color:#0f172a; margin-bottom:6px; }
.boitier-card-info { display:flex; flex-wrap:wrap; gap:8px; font-size:.76rem; }
.boitier-table-wrap { display:block; overflow-x:auto; }

/* SMS commands */
.sms-commands { display:grid; gap:10px; }
.sms-cmd-label { font-size:.75rem; font-weight:600; color:#475569; margin-bottom:4px; display:flex; align-items:center; gap:6px; }
.sms-cmd-box {
    position:relative; background:#0f172a; color:#a5f3fc; font-family:monospace; font-size:.82rem;
    padding:10px 14px; border-radius:8px; cursor:pointer; word-break:break-all; line-height:1.5;
    transition: background .15s;
}
.sms-cmd-box:hover { background:#1e293b; }
.sms-copy-hint {
    position:absolute; right:8px; top:50%; transform:translateY(-50%);
    font-size:.68rem; color:#94a3b8; font-family:inherit;
}
.sms-cmd-box.copied { background:#064e3b !important; }
.sms-cmd-box.copied .sms-copy-hint { color:#34d399; }

/* Brand tabs */
.brand-tab { transition: all .15s; }
.brand-tab:hover { color:#0d9488 !important; }

@media (max-width:640px) {
    /* Table → cards */
    .gps-table-wrap { display:none !important; }
    .veh-cards { display:block; }

    /* KPIs: 2x2 */
    .gps-kpis { grid-template-columns:1fr 1fr; gap:8px; }

    /* Guide steps: 2x2 */
    .guide-steps { grid-template-columns:1fr 1fr; gap:10px; padding:12px; }

    /* Boitier table → cards */
    .boitier-table-wrap { display:none; }
    .boitier-cards { display:block; }

    /* Page header stack */
    .gps-page-header { flex-direction:column !important; align-items:flex-start !important; gap:10px !important; }
    .gps-page-header > div:last-child { width:100%; }
    .gps-page-header .btn { flex:1; justify-content:center; }
}

@media (max-width:420px) {
    .guide-steps { grid-template-columns:1fr; }
}
</style>

<div class="page-header gps-page-header" style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center">
    <div>
        <h1 class="page-title"><i class="fas fa-satellite-dish" style="color:#0d9488"></i> Boîtiers GPS</h1>
        <p class="page-subtitle" style="margin:0">
            <?= $nbConfigures ?>/<?= count($vehicules) ?> véhicules configurés
            <?php if ($nbOnline > 0): ?> · <span style="color:#059669;font-weight:600"><?= $nbOnline ?> en ligne</span><?php endif ?>
        </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($nbImeiSansId > 0 && $traccarOk): ?>
        <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sync_all">
            <input type="hidden" name="vehicule_id" value="0">
            <button type="submit" class="btn btn-warning btn-sm" title="Synchroniser les IMEI en attente avec Traccar">
                <i class="fas fa-sync-alt"></i> Synchro Traccar (<?= $nbImeiSansId ?>)
            </button>
        </form>
        <?php endif ?>
        <?php if (hasGpsModule()): ?>
        <a href="<?= BASE_URL ?>app/gps/carte.php" class="btn btn-primary btn-sm">
            <i class="fas fa-map-location-dot"></i> Carte temps réel
        </a>
        <?php endif ?>
    </div>
</div>

<?= renderFlashes() ?>

<!-- Statut Traccar -->
<div style="background:<?= $traccarOk ? '#f0fdf4' : '#fef2f2' ?>;border:1px solid <?= $traccarOk ? '#bbf7d0' : '#fecaca' ?>;border-radius:8px;padding:10px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <span class="online-dot <?= $traccarOk ? 'blink' : '' ?>" style="background:<?= $traccarOk ? '#22c55e' : '#ef4444' ?>"></span>
    <strong style="font-size:.83rem;color:<?= $traccarOk ? '#15803d' : '#991b1b' ?>">
        Serveur Traccar : <?= $traccarOk ? 'En ligne ✓' : 'Hors ligne ✗' ?>
    </strong>
    <code style="font-size:.75rem;background:rgba(0,0,0,.07);padding:2px 7px;border-radius:4px;color:#475569"><?= TRACCAR_URL ?></code>
    <?php if ($traccarOk): ?>
    <?php $traccarBase = str_replace('/api', '', TRACCAR_URL); ?>
    <a href="<?= $traccarBase ?>" target="_blank" class="btn btn-ghost btn-sm" style="margin-left:auto;font-size:.72rem">
        <i class="fas fa-external-link-alt"></i> Ouvrir Traccar
    </a>
    <?php else: ?>
    <span style="margin-left:auto;font-size:.75rem;color:#b91c1c">Les IMEI sont sauvegardés localement — synchro auto au retour</span>
    <?php endif ?>
</div>

<?php if ($nbImeiSansId > 0 && $traccarOk): ?>
<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px 16px;margin-bottom:14px;display:flex;align-items:center;gap:10px">
    <i class="fas fa-exclamation-triangle" style="color:#d97706"></i>
    <div style="font-size:.82rem;flex:1">
        <strong><?= $nbImeiSansId ?> véhicule(s)</strong> ont un IMEI enregistré mais ne sont pas encore liés à Traccar.
        Cliquez <strong>Synchro Traccar</strong> pour les lier automatiquement.
    </div>
</div>
<?php endif ?>

<?php if (!hasGpsModule()): ?>
<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:16px 20px;display:flex;gap:12px;align-items:center">
    <i class="fas fa-lock" style="color:#d97706;font-size:1.5rem"></i>
    <div><strong>Module GPS requis</strong><br><span style="font-size:.82rem">Plans Pro et Enterprise.</span></div>
    <a href="<?= BASE_URL ?>app/parametres/index.php" class="btn btn-warning btn-sm" style="margin-left:auto">Upgrader</a>
</div>
<?php else: ?>

<!-- KPIs -->
<div class="gps-kpis">
    <?php foreach ([
        ['Véhicules', count($vehicules), '#0d9488', '#0d9488'],
        ['GPS configurés', $nbConfigures, '#22c55e', '#15803d'],
        ['En ligne maintenant', $nbOnline, $nbOnline > 0 ? '#22c55e' : '#94a3b8', $nbOnline > 0 ? '#15803d' : '#94a3b8'],
        ['Sans GPS', count($vehicules) - $nbConfigures, '#f59e0b', '#d97706'],
    ] as [$label, $val, $borderColor, $textColor]): ?>
    <div style="background:#fff;border:1px solid #e2e8f0;border-top:3px solid <?= $borderColor ?>;border-radius:8px;padding:11px 14px">
        <div style="font-size:.62rem;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8"><?= $label ?></div>
        <div style="font-size:1.5rem;font-weight:700;color:<?= $textColor ?>"><?= $val ?></div>
    </div>
    <?php endforeach ?>
</div>

<!-- Table véhicules -->
<div class="card" style="margin-bottom:16px">
    <div class="card-header" style="padding:10px 14px;display:flex;align-items:center;justify-content:space-between">
        <h3 class="card-title" style="margin:0;font-size:.88rem">
            <i class="fas fa-car"></i> Véhicules & boîtiers GPS
        </h3>
        <span style="font-size:.73rem;color:#94a3b8" id="last-refresh">
            <i class="fas fa-sync-alt fa-spin" id="refresh-icon" style="display:none"></i>
            Actualisé à <?= date('H:i:s') ?>
        </span>
    </div>

    <?php if (empty($vehicules)): ?>
    <div style="text-align:center;padding:2.5rem;color:#94a3b8">
        <i class="fas fa-car" style="font-size:2rem;display:block;margin-bottom:8px"></i>
        Aucun véhicule. <a href="<?= BASE_URL ?>app/vehicules/ajouter.php">Ajouter un véhicule →</a>
    </div>
    <?php else: ?>
    <div class="gps-table-wrap table-responsive">
        <table class="table" style="margin:0">
            <thead class="gps-head">
                <tr>
                    <th>Véhicule</th>
                    <th style="text-align:center">Statut GPS</th>
                    <th>IMEI</th>
                    <th>Modèle boîtier</th>
                    <th style="text-align:right">Vitesse</th>
                    <th style="text-align:center">Dernier signal</th>
                    <th style="text-align:center;min-width:220px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($vehicules as $v):
                $hasGps   = !empty($v['traccar_device_id']);
                $hasImei  = !empty($v['imei']);
                $pos      = $hasGps ? ($traccarPositions[$v['traccar_device_id']] ?? null) : null;
                $isOnline = $pos && isset($pos['fixTime']) && (time() - strtotime($pos['fixTime'])) < 300;
                $speedKnots = $pos ? ($pos['speed'] ?? 0) : 0;
                $speed    = round($speedKnots * 1.852); // nœuds → km/h
                $lastFix  = $pos ? ($pos['fixTime'] ?? null) : null;
                $ago      = $lastFix ? (time() - strtotime($lastFix)) : null;
                $lat      = $pos ? ($pos['latitude'] ?? null) : null;
                $lng      = $pos ? ($pos['longitude'] ?? null) : null;
            ?>
            <tr class="gps-row" data-veh-id="<?= $v['id'] ?>" data-traccar-id="<?= (int)$v['traccar_device_id'] ?>">
                <!-- Véhicule -->
                <td>
                    <div style="display:flex;align-items:center;gap:9px">
                        <div style="width:32px;height:32px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:<?= $hasGps ? 'linear-gradient(135deg,#0d9488,#14b8a6)' : '#f1f5f9' ?>">
                            <i class="fas fa-satellite-dish" style="font-size:.75rem;color:<?= $hasGps ? '#fff' : '#94a3b8' ?>"></i>
                        </div>
                        <div>
                            <div style="font-weight:700;color:#0f172a"><?= sanitize($v['nom']) ?></div>
                            <div style="font-size:.7rem;color:#94a3b8"><?= sanitize($v['immatriculation']) ?><?php if ($v['marque']): ?> · <?= sanitize($v['marque'].' '.($v['modele']??'')) ?><?php endif ?></div>
                        </div>
                    </div>
                </td>
                <!-- Statut -->
                <td style="text-align:center">
                    <?php if ($hasGps): ?>
                    <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:99px;font-size:.72rem;font-weight:700;background:<?= $isOnline ? '#d1fae5' : '#f1f5f9' ?>;color:<?= $isOnline ? '#059669' : '#64748b' ?>">
                        <span class="online-dot <?= $isOnline ? 'blink' : '' ?>" style="background:<?= $isOnline ? '#22c55e' : '#94a3b8' ?>;width:6px;height:6px"></span>
                        <?= $isOnline ? 'En ligne' : 'Hors ligne' ?>
                    </span>
                    <?php elseif ($hasImei): ?>
                    <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;font-size:.72rem;font-weight:700;background:#fef3c7;color:#d97706">
                        <i class="fas fa-clock" style="font-size:.6rem"></i> En attente
                    </span>
                    <?php else: ?>
                    <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;font-size:.72rem;font-weight:700;background:#fef3c7;color:#d97706">
                        <i class="fas fa-exclamation-circle" style="font-size:.6rem"></i> Non configuré
                    </span>
                    <?php endif ?>
                </td>
                <!-- IMEI -->
                <td>
                    <?php if ($v['imei']): ?>
                    <code style="font-size:.75rem;background:#f1f5f9;padding:2px 7px;border-radius:4px"><?= sanitize($v['imei']) ?></code>
                    <?php if ($v['traccar_device_id']): ?>
                    <div style="font-size:.65rem;color:#94a3b8;margin-top:2px">ID Traccar #<?= (int)$v['traccar_device_id'] ?></div>
                    <?php endif ?>
                    <?php else: ?><span style="color:#cbd5e1">—</span><?php endif ?>
                </td>
                <!-- Modèle -->
                <td style="font-size:.8rem;color:#475569"><?= $v['modele_boitier'] ? sanitize($v['modele_boitier']) : '<span style="color:#cbd5e1">—</span>' ?></td>
                <!-- Vitesse -->
                <td style="text-align:right">
                    <?php if ($isOnline): ?>
                    <span class="speed-badge" style="background:<?= $speed > 80 ? '#fee2e2' : ($speed > 0 ? '#dbeafe' : '#f1f5f9') ?>;color:<?= $speed > 80 ? '#dc2626' : ($speed > 0 ? '#0d9488' : '#64748b') ?>">
                        <?= $speed ?> <span style="font-size:.65rem;font-weight:400">km/h</span>
                    </span>
                    <?php else: ?><span style="color:#cbd5e1">—</span><?php endif ?>
                </td>
                <!-- Dernier signal -->
                <td style="text-align:center;font-size:.75rem">
                    <?php if ($ago !== null): ?>
                    <div style="font-weight:600;color:<?= $ago < 300 ? '#059669' : ($ago < 3600 ? '#d97706' : '#94a3b8') ?>">
                        <?php
                        if ($ago < 60)        echo 'À l\'instant';
                        elseif ($ago < 3600)  echo (int)($ago/60) . ' min';
                        elseif ($ago < 86400) echo (int)($ago/3600) . 'h ' . (int)(($ago%3600)/60) . 'min';
                        else                  echo date('d/m H:i', strtotime($lastFix));
                        ?>
                    </div>
                    <?php if ($lat && $lng): ?>
                    <div style="font-size:.68rem;color:#94a3b8"><?= round($lat,4) ?>, <?= round($lng,4) ?></div>
                    <?php endif ?>
                    <?php else: ?><span style="color:#94a3b8">Jamais</span><?php endif ?>
                </td>
                <!-- Actions -->
                <td>
                    <div style="display:flex;gap:4px;justify-content:center;align-items:center;flex-wrap:wrap">
                        <?php if ($hasGps): ?>
                            <?php if ($isOnline && $lat && $lng): ?>
                            <a href="<?= BASE_URL ?>app/gps/carte.php?vehicule=<?= $v['id'] ?>"
                               class="action-btn" style="background:#dbeafe;color:#0d9488" title="Voir sur la carte">
                                <i class="fas fa-map-pin"></i> Carte
                            </a>
                            <?php endif ?>
                            <a href="<?= BASE_URL ?>app/gps/regles.php?vehicule=<?= $v['id'] ?>"
                               class="action-btn" style="background:#f0fdf4;color:#059669" title="Règles GPS de ce véhicule">
                                <i class="fas fa-sliders-h"></i> Règles
                            </a>
                            <!-- Couper/démarrer moteur -->
                            <button class="action-btn btn-engine-stop"
                                    data-id="<?= $v['id'] ?>"
                                    data-nom="<?= htmlspecialchars($v['nom'], ENT_QUOTES) ?>"
                                    style="background:#fee2e2;color:#dc2626" title="Couper le moteur à distance">
                                <i class="fas fa-power-off"></i> Couper
                            </button>
                            <button class="action-btn btn-engine-start"
                                    data-id="<?= $v['id'] ?>"
                                    data-nom="<?= htmlspecialchars($v['nom'], ENT_QUOTES) ?>"
                                    style="background:#d1fae5;color:#059669" title="Remettre en marche">
                                <i class="fas fa-play"></i>
                            </button>
                            <!-- Modifier -->
                            <button class="action-btn btn-gps-edit"
                                    data-id="<?= $v['id'] ?>"
                                    data-nom="<?= htmlspecialchars($v['nom'], ENT_QUOTES) ?>"
                                    data-immat="<?= htmlspecialchars($v['immatriculation'], ENT_QUOTES) ?>"
                                    data-imei="<?= htmlspecialchars($v['imei'] ?? '', ENT_QUOTES) ?>"
                                    data-modele="<?= htmlspecialchars($v['modele_boitier'] ?? '', ENT_QUOTES) ?>"
                                    style="background:#f1f5f9;color:#475569" title="Modifier le boîtier">
                                <i class="fas fa-pen"></i>
                            </button>
                            <!-- Dissocier -->
                            <button class="action-btn btn-dissocier"
                                    data-id="<?= $v['id'] ?>"
                                    data-nom="<?= htmlspecialchars($v['nom'], ENT_QUOTES) ?>"
                                    style="background:#fef2f2;color:#ef4444" title="Dissocier le boîtier">
                                <i class="fas fa-unlink"></i>
                            </button>
                        <?php else: ?>
                            <button class="action-btn btn-gps-edit"
                                    data-id="<?= $v['id'] ?>"
                                    data-nom="<?= htmlspecialchars($v['nom'], ENT_QUOTES) ?>"
                                    data-immat="<?= htmlspecialchars($v['immatriculation'], ENT_QUOTES) ?>"
                                    data-imei="<?= htmlspecialchars($v['imei'] ?? '', ENT_QUOTES) ?>"
                                    data-modele="<?= htmlspecialchars($v['modele_boitier'] ?? '', ENT_QUOTES) ?>"
                                    style="background:#0d9488;color:#fff;padding:5px 14px">
                                <i class="fas fa-plus"></i> Associer GPS
                            </button>
                        <?php endif ?>
                    </div>
                </td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <!-- ── Vue CARTES (mobile) ───────────────────────────────────── -->
    <div class="veh-cards">
        <?php foreach ($vehicules as $v):
            $hasGps   = !empty($v['traccar_device_id']);
            $hasImei  = !empty($v['imei']);
            $pos      = $hasGps ? ($traccarPositions[$v['traccar_device_id']] ?? null) : null;
            $isOnline = $pos && isset($pos['fixTime']) && (time() - strtotime($pos['fixTime'])) < 300;
            $speed    = $pos ? round(($pos['speed'] ?? 0) * 1.852) : 0;
            $lastFix  = $pos ? ($pos['fixTime'] ?? null) : null;
            $ago      = $lastFix ? (time() - strtotime($lastFix)) : null;
            $lat      = $pos ? ($pos['latitude'] ?? null) : null;
            $lng      = $pos ? ($pos['longitude'] ?? null) : null;
        ?>
        <div class="veh-card">
            <div class="veh-card-top">
                <div style="width:38px;height:38px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:<?= $hasGps ? 'linear-gradient(135deg,#0d9488,#14b8a6)' : '#f1f5f9' ?>">
                    <i class="fas fa-satellite-dish" style="font-size:.8rem;color:<?= $hasGps ? '#fff' : '#94a3b8' ?>"></i>
                </div>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:700;font-size:.9rem;color:#0f172a"><?= sanitize($v['nom']) ?></div>
                    <div style="font-size:.72rem;color:#94a3b8"><?= sanitize($v['immatriculation']) ?><?php if ($v['marque']): ?> · <?= sanitize($v['marque'].' '.($v['modele']??'')) ?><?php endif ?></div>
                </div>
                <!-- Statut badge -->
                <?php if ($hasGps): ?>
                <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;font-size:.7rem;font-weight:700;background:<?= $isOnline ? '#d1fae5' : '#f1f5f9' ?>;color:<?= $isOnline ? '#059669' : '#64748b' ?>;white-space:nowrap">
                    <span class="online-dot <?= $isOnline ? 'blink' : '' ?>" style="background:<?= $isOnline ? '#22c55e' : '#94a3b8' ?>;width:6px;height:6px"></span>
                    <?= $isOnline ? 'En ligne' : 'Hors ligne' ?>
                </span>
                <?php elseif ($hasImei): ?>
                <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;font-size:.7rem;font-weight:700;background:#fef3c7;color:#d97706">
                    <i class="fas fa-clock" style="font-size:.6rem"></i> En attente
                </span>
                <?php else: ?>
                <span style="font-size:.7rem;color:#94a3b8">Non configuré</span>
                <?php endif ?>
            </div>

            <!-- Méta : IMEI, vitesse, dernier signal -->
            <div class="veh-card-meta">
                <?php if ($v['imei']): ?>
                <span><i class="fas fa-barcode"></i> <code style="font-size:.72rem"><?= sanitize($v['imei']) ?></code></span>
                <?php endif ?>
                <?php if ($isOnline && $speed >= 0): ?>
                <span style="background:<?= $speed > 80 ? '#fee2e2' : '#dbeafe' ?>;color:<?= $speed > 80 ? '#dc2626' : '#0d9488' ?>;padding:2px 7px;border-radius:99px;font-weight:700">
                    <?= $speed ?> km/h
                </span>
                <?php endif ?>
                <?php if ($ago !== null): ?>
                <span style="color:<?= $ago < 300 ? '#059669' : ($ago < 3600 ? '#d97706' : '#94a3b8') ?>">
                    <i class="fas fa-clock"></i>
                    <?php
                    if ($ago < 60)        echo "À l'instant";
                    elseif ($ago < 3600)  echo (int)($ago/60).'min';
                    elseif ($ago < 86400) echo (int)($ago/3600).'h';
                    else                  echo date('d/m H:i', strtotime($lastFix));
                    ?>
                </span>
                <?php endif ?>
                <?php if ($v['modele_boitier']): ?>
                <span><i class="fas fa-microchip"></i> <?= sanitize($v['modele_boitier']) ?></span>
                <?php endif ?>
            </div>

            <!-- Actions -->
            <div class="veh-card-actions">
                <?php if ($hasGps): ?>
                    <?php if ($isOnline && $lat && $lng): ?>
                    <a href="<?= BASE_URL ?>app/gps/carte.php?vehicule=<?= $v['id'] ?>" class="action-btn" style="background:#dbeafe;color:#0d9488">
                        <i class="fas fa-map-pin"></i> Carte
                    </a>
                    <?php endif ?>
                    <button class="action-btn btn-engine-stop" data-id="<?= $v['id'] ?>" data-nom="<?= htmlspecialchars($v['nom'], ENT_QUOTES) ?>" style="background:#fee2e2;color:#dc2626">
                        <i class="fas fa-power-off"></i> Couper
                    </button>
                    <button class="action-btn btn-engine-start" data-id="<?= $v['id'] ?>" data-nom="<?= htmlspecialchars($v['nom'], ENT_QUOTES) ?>" style="background:#d1fae5;color:#059669">
                        <i class="fas fa-play"></i> Démarrer
                    </button>
                    <button class="action-btn btn-gps-edit"
                            data-id="<?= $v['id'] ?>" data-nom="<?= htmlspecialchars($v['nom'], ENT_QUOTES) ?>"
                            data-immat="<?= htmlspecialchars($v['immatriculation'], ENT_QUOTES) ?>"
                            data-imei="<?= htmlspecialchars($v['imei'] ?? '', ENT_QUOTES) ?>"
                            data-modele="<?= htmlspecialchars($v['modele_boitier'] ?? '', ENT_QUOTES) ?>"
                            style="background:#f1f5f9;color:#475569">
                        <i class="fas fa-pen"></i> Modifier
                    </button>
                    <button class="action-btn btn-dissocier" data-id="<?= $v['id'] ?>" data-nom="<?= htmlspecialchars($v['nom'], ENT_QUOTES) ?>" style="background:#fef2f2;color:#ef4444">
                        <i class="fas fa-unlink"></i>
                    </button>
                <?php else: ?>
                    <button class="action-btn btn-gps-edit"
                            data-id="<?= $v['id'] ?>" data-nom="<?= htmlspecialchars($v['nom'], ENT_QUOTES) ?>"
                            data-immat="<?= htmlspecialchars($v['immatriculation'], ENT_QUOTES) ?>"
                            data-imei="<?= htmlspecialchars($v['imei'] ?? '', ENT_QUOTES) ?>"
                            data-modele="<?= htmlspecialchars($v['modele_boitier'] ?? '', ENT_QUOTES) ?>"
                            style="background:#0d9488;color:#fff;padding:7px 16px;font-size:.8rem">
                        <i class="fas fa-plus"></i> Associer GPS
                    </button>
                <?php endif ?>
            </div>
        </div>
        <?php endforeach ?>
    </div>

    <?php endif ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- GUIDE COMPLET DE CONFIGURATION GPS                                      -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<?php
$host = parse_url(TRACCAR_URL, PHP_URL_HOST) ?: 'localhost';
// Résoudre l'IP du serveur pour les commandes SMS (les boîtiers ne gèrent pas les domaines)
$serverIP = $host;
if (!filter_var($host, FILTER_VALIDATE_IP)) {
    $resolved = @gethostbyname($host);
    if ($resolved && $resolved !== $host) $serverIP = $resolved;
}
?>

<!-- Card 1 : Étapes rapides -->
<div class="card" style="margin-bottom:16px">
    <div class="card-header" style="padding:12px 16px;cursor:pointer;user-select:none"
         onclick="toggleGuide(this)">
        <h3 class="card-title" style="margin:0;font-size:.88rem;display:flex;align-items:center;gap:8px">
            <i class="fas fa-rocket" style="color:#0d9488"></i>
            Guide rapide : Mettre un boîtier GPS en ligne
            <i class="fas fa-chevron-down guide-chev" style="margin-left:auto;font-size:.75rem;color:#94a3b8;transition:transform .25s"></i>
        </h3>
    </div>
    <div style="display:none">
        <!-- Étapes -->
        <div class="guide-steps">
            <?php foreach ([
                ['1','fa-sim-card', '#0d9488','Insérez la carte SIM','Puce avec forfait data actif (2G suffit). Opérateurs CI : Moov, MTN, Orange.'],
                ['2','fa-plug',    '#d97706','Branchez le boîtier','Connectez au câblage 12V du véhicule. LED allumée = alimentation OK.'],
                ['3','fa-sms',     '#7c3aed','Envoyez les SMS de config','Depuis votre téléphone, envoyez les SMS au numéro de la SIM du boîtier (voir ci-dessous).'],
                ['4','fa-check-circle','#059669','Associez dans FlotteCar','Cliquez "Associer GPS", entrez l\'IMEI. Le boîtier apparaît en ligne sous 2-5 min.'],
            ] as [$n, $ic, $c, $t, $d]): ?>
            <div style="display:flex;gap:10px;align-items:flex-start">
                <div style="width:32px;height:32px;border-radius:50%;background:<?= $c ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.82rem;font-weight:700;flex-shrink:0;box-shadow:0 2px 8px <?= $c ?>55"><?= $n ?></div>
                <div>
                    <div style="font-weight:700;font-size:.82rem;margin-bottom:3px;color:#0f172a">
                        <i class="fas <?= $ic ?>" style="color:<?= $c ?>"></i> <?= $t ?>
                    </div>
                    <div style="font-size:.73rem;color:#64748b;line-height:1.5"><?= $d ?></div>
                </div>
            </div>
            <?php endforeach ?>
        </div>

        <div style="padding:12px 16px;background:#f0fdf4;border-top:1px solid #dcfce7">
            <div style="font-size:.78rem;color:#15803d;display:flex;align-items:center;gap:8px">
                <i class="fas fa-info-circle"></i>
                <strong>L'IMEI</strong> est un numéro à 15 chiffres imprimé sous le boîtier ou sur l'emballage. Pas besoin de mot de passe FlotteCar pour le client !
            </div>
        </div>
    </div>
</div>

<!-- Card 2 : Commandes SMS par marque -->
<div class="card" style="margin-bottom:16px">
    <div class="card-header" style="padding:12px 16px;cursor:pointer;user-select:none"
         onclick="toggleGuide(this)">
        <h3 class="card-title" style="margin:0;font-size:.88rem;display:flex;align-items:center;gap:8px">
            <i class="fas fa-sms" style="color:#7c3aed"></i>
            Commandes SMS par marque de boîtier
            <span style="font-size:.7rem;font-weight:400;color:#94a3b8;margin-left:4px">(cliquez pour ouvrir)</span>
            <i class="fas fa-chevron-down guide-chev" style="margin-left:auto;font-size:.75rem;color:#94a3b8;transition:transform .25s"></i>
        </h3>
    </div>
    <div style="display:none">
        <div style="padding:12px 16px;background:#fef9c3;border-bottom:1px solid #fde68a;font-size:.78rem;color:#92400e;display:flex;align-items:center;gap:8px">
            <i class="fas fa-key"></i>
            <span><strong>Mot de passe par défaut :</strong> La plupart des boîtiers utilisent <code style="background:#fff;padding:2px 8px;border-radius:4px;font-weight:800;font-size:.85rem">123456</code> comme mot de passe usine. Si ça ne marche pas, essayez <code style="background:#fff;padding:2px 8px;border-radius:4px">000000</code> ou consultez la notice.</span>
        </div>

        <!-- Onglets marques -->
        <div style="padding:0 16px;border-bottom:1px solid #e2e8f0;display:flex;gap:0;overflow-x:auto" id="brand-tabs">
            <?php
            $brands = [
                ['gt06',      'Concox GT06',    'fa-microchip'],
                ['tk103',     'Coban TK103',    'fa-microchip'],
                ['sinotrack', 'Sinotrack',      'fa-microchip'],
                ['teltonika', 'Teltonika',      'fa-microchip'],
                ['other',     'Autres marques', 'fa-ellipsis-h'],
            ];
            foreach ($brands as $i => [$key, $label, $icon]):
            ?>
            <button class="brand-tab <?= $i === 0 ? 'active' : '' ?>"
                    onclick="showBrand('<?= $key ?>')"
                    style="padding:10px 14px;font-size:.75rem;font-weight:600;border:none;background:none;color:<?= $i === 0 ? '#0d9488' : '#64748b' ?>;border-bottom:2px solid <?= $i === 0 ? '#0d9488' : 'transparent' ?>;cursor:pointer;white-space:nowrap">
                <i class="fas <?= $icon ?>" style="margin-right:3px"></i> <?= $label ?>
            </button>
            <?php endforeach ?>
        </div>

        <!-- ── Concox GT06 / GT06N / JMT ─────────────────────────── -->
        <div class="brand-panel" id="brand-gt06" style="padding:16px">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
                <div style="width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#0d9488,#14b8a6);display:flex;align-items:center;justify-content:center">
                    <i class="fas fa-microchip" style="color:#fff;font-size:1rem"></i>
                </div>
                <div>
                    <div style="font-weight:700;font-size:.9rem;color:#0f172a">Concox GT06 / GT06N / JMT / JM-VL01</div>
                    <div style="font-size:.72rem;color:#94a3b8">Protocole GT06 · Port <span class="port-code">5023</span> · Mot de passe usine : <code>123456</code></div>
                </div>
            </div>

            <div style="font-size:.78rem;color:#475569;margin-bottom:10px">
                <strong>Envoyez ces SMS</strong> au numéro de la SIM insérée dans le boîtier :
            </div>

            <div class="sms-commands">
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-1" style="color:#0d9488"></i> Configurer le serveur</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        SERVER,1,<?= $serverIP ?>,5023,0#
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-2" style="color:#0d9488"></i> Configurer l'APN (Moov CI)</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        APN,moov.ci#
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-2" style="color:#d97706"></i> APN alternatif (MTN CI)</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        APN,mtn.ci#
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-3" style="color:#0d9488"></i> Activer le GPS (si désactivé)</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        GPSON,1#
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-clock" style="color:#64748b"></i> Intervalle de suivi (10 sec)</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        TIMER,10#
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-question-circle" style="color:#64748b"></i> Vérifier les paramètres</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        PARAM#
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Coban TK103 / GPS103 / TK915 ──────────────────────── -->
        <div class="brand-panel" id="brand-tk103" style="padding:16px;display:none">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
                <div style="width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#d97706,#f59e0b);display:flex;align-items:center;justify-content:center">
                    <i class="fas fa-microchip" style="color:#fff;font-size:1rem"></i>
                </div>
                <div>
                    <div style="font-weight:700;font-size:.9rem;color:#0f172a">Coban TK103 / GPS103 / TK915</div>
                    <div style="font-size:.72rem;color:#94a3b8">Protocole Coban · Port <span class="port-code">5027</span> · Mot de passe usine : <code>123456</code></div>
                </div>
            </div>

            <div style="font-size:.78rem;color:#475569;margin-bottom:10px">
                <strong>Envoyez ces SMS</strong> au numéro de la SIM du boîtier :
            </div>

            <div class="sms-commands">
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-1" style="color:#d97706"></i> Configurer l'IP et le port</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        adminip123456 <?= $serverIP ?> 5027
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-2" style="color:#d97706"></i> Configurer l'APN (Moov CI)</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        apn123456 moov.ci
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-2" style="color:#f59e0b"></i> APN alternatif (MTN CI)</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        apn123456 mtn.ci
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-3" style="color:#d97706"></i> Activer le suivi continu</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        fix010s***n123456
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                    <div style="font-size:.68rem;color:#94a3b8;margin-top:3px">Envoi toutes les 10 sec. Changer <code>010s</code> pour ajuster (ex: <code>030s</code> = 30 sec)</div>
                </div>
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-question-circle" style="color:#64748b"></i> Vérifier les paramètres</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        check123456
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Sinotrack ST-901 / ST-902 / ST-906 ────────────────── -->
        <div class="brand-panel" id="brand-sinotrack" style="padding:16px;display:none">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
                <div style="width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#7c3aed,#a78bfa);display:flex;align-items:center;justify-content:center">
                    <i class="fas fa-microchip" style="color:#fff;font-size:1rem"></i>
                </div>
                <div>
                    <div style="font-weight:700;font-size:.9rem;color:#0f172a">Sinotrack ST-901 / ST-902 / ST-906</div>
                    <div style="font-size:.72rem;color:#94a3b8">Protocole H02 · Port <span class="port-code">5013</span> · Mot de passe usine : <code>123456</code></div>
                </div>
            </div>

            <div style="font-size:.78rem;color:#475569;margin-bottom:10px">
                <strong>Envoyez ces SMS</strong> au numéro de la SIM du boîtier :
            </div>

            <div class="sms-commands">
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-1" style="color:#7c3aed"></i> Configurer le serveur</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        804 <?= $serverIP ?> 5013
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-2" style="color:#7c3aed"></i> Configurer l'APN (Moov CI)</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        803 moov.ci
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-2" style="color:#a78bfa"></i> APN alternatif (MTN CI)</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        803 mtn.ci
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-3" style="color:#7c3aed"></i> Intervalle de suivi (10 sec)</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        805 10
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-question-circle" style="color:#64748b"></i> Vérifier les paramètres</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        802
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-undo" style="color:#ef4444"></i> Reset usine (si problème)</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        FACTORY
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Teltonika FMB920 / FMB130 ─────────────────────────── -->
        <div class="brand-panel" id="brand-teltonika" style="padding:16px;display:none">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
                <div style="width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#0369a1,#38bdf8);display:flex;align-items:center;justify-content:center">
                    <i class="fas fa-microchip" style="color:#fff;font-size:1rem"></i>
                </div>
                <div>
                    <div style="font-weight:700;font-size:.9rem;color:#0f172a">Teltonika FMB920 / FMB130 / FMB140</div>
                    <div style="font-size:.72rem;color:#94a3b8">Protocole Teltonika · Port <span class="port-code">5027</span> · Pas de mot de passe SMS</div>
                </div>
            </div>

            <div style="background:#e0f2fe;border:1px solid #bae6fd;border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:.78rem;color:#0369a1">
                <i class="fas fa-info-circle"></i>
                <strong>Teltonika se configure par SMS</strong> (pas besoin d'application ni de câble USB).
            </div>

            <div class="sms-commands">
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-1" style="color:#0369a1"></i> Configurer le serveur + port</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        <space>setparam 2001:<?= $serverIP ?>;2002:5027
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                    <div style="font-size:.68rem;color:#94a3b8;margin-top:3px">Le SMS doit commencer par un espace</div>
                </div>
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-2" style="color:#0369a1"></i> Configurer l'APN (Moov CI)</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        <space>setparam 2000:moov.ci
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-2" style="color:#38bdf8"></i> APN alternatif (MTN CI)</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        <space>setparam 2000:mtn.ci
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
                <div class="sms-cmd">
                    <div class="sms-cmd-label"><i class="fas fa-question-circle" style="color:#64748b"></i> Vérifier les paramètres</div>
                    <div class="sms-cmd-box" onclick="copySMS(this)">
                        <space>getparam 2000;2001;2002
                        <span class="sms-copy-hint"><i class="fas fa-copy"></i> Copier</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Autres marques ────────────────────────────────────── -->
        <div class="brand-panel" id="brand-other" style="padding:16px;display:none">
            <div style="font-weight:700;font-size:.85rem;color:#0f172a;margin-bottom:12px">
                <i class="fas fa-list" style="color:#64748b"></i> Tableau récapitulatif tous boîtiers
            </div>

            <div class="boitier-table-wrap">
                <table class="table" style="font-size:.78rem;margin:0">
                    <thead>
                        <tr style="background:#f8fafc">
                            <th style="padding:7px 12px">Marque</th>
                            <th style="padding:7px 12px">Port</th>
                            <th style="padding:7px 12px">MDP usine</th>
                            <th style="padding:7px 12px">SMS serveur</th>
                            <th style="padding:7px 12px">SMS APN</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="padding:6px 12px;font-weight:600">Queclink GV55/GV300</td>
                            <td style="padding:6px 12px"><span class="port-code">5093</span></td>
                            <td style="padding:6px 12px"><code>Queclink</code></td>
                            <td style="padding:6px 12px"><code style="font-size:.7rem">AT+GTBSI=Queclink,<?= $serverIP ?>,5093,,,,0001$</code></td>
                            <td style="padding:6px 12px"><code style="font-size:.7rem">AT+GTQSS=Queclink,0,moov.ci,,,,,,,0001$</code></td>
                        </tr>
                        <tr>
                            <td style="padding:6px 12px;font-weight:600">Jointech JT701/JT709</td>
                            <td style="padding:6px 12px"><span class="port-code">5100</span></td>
                            <td style="padding:6px 12px"><code>123456</code></td>
                            <td style="padding:6px 12px" colspan="2"><span style="color:#94a3b8">Configuration via logiciel PC (Jointech Tool)</span></td>
                        </tr>
                        <tr>
                            <td style="padding:6px 12px;font-weight:600">Ruptela FM-Eco4</td>
                            <td style="padding:6px 12px"><span class="port-code">5046</span></td>
                            <td style="padding:6px 12px"><code>—</code></td>
                            <td style="padding:6px 12px" colspan="2"><span style="color:#94a3b8">Configuration via Ruptela Device Center (USB)</span></td>
                        </tr>
                        <tr>
                            <td style="padding:6px 12px;font-weight:600">Suntech ST300/ST310</td>
                            <td style="padding:6px 12px"><span class="port-code">5011</span></td>
                            <td style="padding:6px 12px"><code>0000</code></td>
                            <td style="padding:6px 12px"><code style="font-size:.7rem">SA200NTW;0000;02;<?= $serverIP ?>;5011</code></td>
                            <td style="padding:6px 12px"><code style="font-size:.7rem">SA200GTF;0000;moov.ci</code></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile cards -->
            <div class="boitier-cards">
                <?php foreach ([
                    ['Queclink GV55/GV300', '5093', 'Queclink', "AT+GTBSI=Queclink,{$serverIP},5093,,,,0001\$"],
                    ['Jointech JT701/JT709','5100', '123456',   'Config via logiciel PC'],
                    ['Ruptela FM-Eco4',     '5046', '—',        'Config via USB'],
                    ['Suntech ST300/ST310', '5011', '0000',     "SA200NTW;0000;02;{$serverIP};5011"],
                ] as [$m, $port, $mdp, $cmd]): ?>
                <div class="boitier-card">
                    <div class="boitier-card-title"><i class="fas fa-microchip" style="color:#64748b"></i> <?= $m ?></div>
                    <div class="boitier-card-info">
                        <span>Port : <span class="port-code"><?= $port ?></span></span>
                        <span>MDP : <code><?= $mdp ?></code></span>
                        <span style="font-size:.7rem;color:#64748b;word-break:break-all"><code><?= $cmd ?></code></span>
                    </div>
                </div>
                <?php endforeach ?>
            </div>
        </div>
    </div>
</div>

<!-- Card 3 : Diagnostic "Pourquoi mon boîtier est hors ligne ?" -->
<div class="card" style="margin-bottom:16px">
    <div class="card-header" style="padding:12px 16px;cursor:pointer;user-select:none"
         onclick="toggleGuide(this)">
        <h3 class="card-title" style="margin:0;font-size:.88rem;display:flex;align-items:center;gap:8px">
            <i class="fas fa-exclamation-triangle" style="color:#d97706"></i>
            Diagnostic : Mon boîtier est "Hors ligne" ?
            <i class="fas fa-chevron-down guide-chev" style="margin-left:auto;font-size:.75rem;color:#94a3b8;transition:transform .25s"></i>
        </h3>
    </div>
    <div style="display:none;padding:16px">
        <div style="display:grid;gap:12px">
            <?php foreach ([
                ['fa-sim-card',  '#ef4444', 'Pas de carte SIM ou SIM sans data',
                 'Insérez une SIM avec un forfait data actif (même 2G/GPRS suffit). Vérifiez que la SIM a du crédit.'],
                ['fa-wifi',      '#d97706', 'APN non configuré ou incorrect',
                 'Le boîtier a besoin de l\'APN de l\'opérateur pour se connecter à internet. Envoyez le SMS APN correspondant (voir ci-dessus). Moov CI = <code>moov.ci</code>, MTN CI = <code>mtn.ci</code>, Orange CI = <code>orange.ci</code>'],
                ['fa-server',    '#7c3aed', 'IP/Port serveur non configuré',
                 "Le boîtier doit savoir où envoyer les données. Envoyez le SMS de configuration serveur avec l'IP <code>{$serverIP}</code> et le port correspondant à votre modèle."],
                ['fa-plug',      '#0369a1', 'Boîtier pas alimenté (LED éteinte)',
                 'Vérifiez le branchement 12V. Le fil rouge = +12V batterie, fil noir = masse. La LED doit clignoter.'],
                ['fa-satellite',  '#059669', 'Pas de signal GPS (intérieur/garage)',
                 'Le boîtier doit voir le ciel pour capter les satellites GPS. Sortez le véhicule dehors et attendez 2-5 min.'],
                ['fa-key',       '#92400e', 'Mot de passe du boîtier changé',
                 'Si quelqu\'un a changé le mot de passe, les commandes SMS avec <code>123456</code> ne marchent plus. Faites un reset usine (voir notice du boîtier).'],
            ] as [$icon, $color, $title, $desc]): ?>
            <div style="display:flex;gap:12px;align-items:flex-start;padding:12px;border-radius:8px;background:#f8fafc;border-left:3px solid <?= $color ?>">
                <i class="fas <?= $icon ?>" style="color:<?= $color ?>;font-size:1rem;margin-top:2px;flex-shrink:0"></i>
                <div>
                    <div style="font-weight:700;font-size:.82rem;color:#0f172a;margin-bottom:3px"><?= $title ?></div>
                    <div style="font-size:.75rem;color:#64748b;line-height:1.6"><?= $desc ?></div>
                </div>
            </div>
            <?php endforeach ?>
        </div>

        <div style="margin-top:14px;padding:12px 16px;background:#f0f9ff;border-radius:8px;border-left:3px solid #38bdf8">
            <div style="font-size:.82rem;font-weight:700;color:#0369a1;margin-bottom:6px">
                <i class="fas fa-lightbulb"></i> Checklist rapide
            </div>
            <div style="font-size:.78rem;color:#0369a1;line-height:1.8">
                1. SIM avec crédit data insérée ? <br>
                2. Boîtier alimenté (LED allumée) ? <br>
                3. SMS APN envoyé ? <br>
                4. SMS serveur (IP + port) envoyé ? <br>
                5. IMEI saisi dans FlotteCar ? <br>
                6. Véhicule dehors (signal GPS) ? <br>
                <strong>Si tout est OK, le boîtier apparaît en ligne sous 2-5 minutes.</strong>
            </div>
        </div>
    </div>
</div>

<?php endif ?>

<!-- Modal Associer / Modifier -->
<div id="modal-gps" class="modal-overlay">
    <div class="modal" style="max-width:460px">
        <div class="modal-header">
            <h3 id="modal-gps-title">
                <i class="fas fa-satellite-dish" style="color:#0d9488;margin-right:6px"></i>
                Associer un boîtier GPS
            </h3>
            <button class="modal-close" onclick="closeModal('modal-gps')">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="associer">
            <input type="hidden" name="vehicule_id" id="gps-veh-id">

            <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 14px;margin-bottom:18px;display:flex;align-items:center;gap:10px">
                <i class="fas fa-car" style="color:#0d9488;font-size:1rem;flex-shrink:0"></i>
                <div>
                    <div id="modal-gps-nom" style="font-weight:700;font-size:.88rem;color:#0f172a"></div>
                    <div id="modal-gps-immat" style="font-size:.73rem;color:#64748b"></div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" style="font-size:.85rem">
                    <i class="fas fa-barcode" style="color:#0d9488"></i>
                    IMEI du boîtier GPS <span style="color:#ef4444">*</span>
                </label>
                <input type="text" name="imei" id="gps-imei" class="form-control"
                       placeholder="Ex: 864071234567890"
                       maxlength="20" inputmode="numeric"
                       style="font-size:1rem;font-family:monospace;letter-spacing:.05em;height:44px"
                       required>
                <div class="form-hint" style="margin-top:5px">
                    <i class="fas fa-info-circle" style="color:#14b8a6"></i>
                    15 chiffres sous le boîtier ou commande <code>*#06#</code> sur le SIM.
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" style="font-size:.85rem">
                    <i class="fas fa-microchip" style="color:#64748b"></i>
                    Modèle du boîtier <span style="color:#94a3b8;font-weight:400">(optionnel)</span>
                </label>
                <input type="text" name="modele_boitier" id="gps-modele" class="form-control"
                       placeholder="Ex: GT06N · FMB920 · TK103 · ST-901…">
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-gps')">Annuler</button>
                <button type="submit" class="btn btn-primary" style="min-width:140px">
                    <i class="fas fa-satellite-dish"></i> Enregistrer & Connecter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Couper moteur -->
<div id="modal-engine" class="modal-overlay">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <h3 id="modal-engine-title" style="color:#dc2626">
                <i class="fas fa-power-off" style="margin-right:6px"></i>
                Couper le moteur
            </h3>
            <button class="modal-close" onclick="closeModal('modal-engine')">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="engine-action" value="stop_engine">
            <input type="hidden" name="vehicule_id" id="engine-veh-id">
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 16px;margin-bottom:18px">
                <div style="font-size:.88rem;color:#991b1b">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Attention !</strong> Cette commande coupe le moteur de
                    <strong id="engine-nom"></strong> à distance.
                </div>
                <div style="font-size:.78rem;color:#b91c1c;margin-top:8px">
                    Assurez-vous que le véhicule est à l'arrêt ou dans un endroit sûr.
                    Cette action nécessite que le boîtier supporte la commande moteur.
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-engine')">Annuler</button>
                <button type="submit" class="btn btn-danger" id="engine-btn">
                    <i class="fas fa-power-off"></i> Couper le moteur
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Formulaire dissocier -->
<form method="POST" id="form-dissocier" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="dissocier">
    <input type="hidden" name="vehicule_id" id="dissocier-veh-id">
</form>

<script>
// ── Délégation d'événements ──────────────────────────────────────────────────
document.addEventListener('click', function(e) {

    // Associer / Modifier GPS
    const btnEdit = e.target.closest('.btn-gps-edit');
    if (btnEdit) {
        document.getElementById('gps-veh-id').value        = btnEdit.dataset.id;
        document.getElementById('modal-gps-nom').textContent   = btnEdit.dataset.nom;
        document.getElementById('modal-gps-immat').textContent = btnEdit.dataset.immat;
        document.getElementById('gps-imei').value           = btnEdit.dataset.imei || '';
        document.getElementById('gps-modele').value         = btnEdit.dataset.modele || '';
        document.getElementById('modal-gps-title').innerHTML =
            '<i class="fas fa-satellite-dish" style="color:#0d9488;margin-right:6px"></i>' +
            (btnEdit.dataset.imei ? 'Modifier le boîtier GPS' : 'Associer un boîtier GPS');
        openModal('modal-gps');
        setTimeout(() => document.getElementById('gps-imei').focus(), 150);
        return;
    }

    // Couper le moteur
    const btnStop = e.target.closest('.btn-engine-stop');
    if (btnStop) {
        document.getElementById('engine-veh-id').value = btnStop.dataset.id;
        document.getElementById('engine-nom').textContent = btnStop.dataset.nom;
        document.getElementById('engine-action').value = 'stop_engine';
        document.getElementById('modal-engine-title').innerHTML =
            '<i class="fas fa-power-off" style="margin-right:6px"></i> Couper le moteur';
        document.getElementById('engine-btn').innerHTML =
            '<i class="fas fa-power-off"></i> Couper le moteur';
        document.getElementById('engine-btn').className = 'btn btn-danger';
        openModal('modal-engine');
        return;
    }

    // Démarrer le moteur
    const btnStart = e.target.closest('.btn-engine-start');
    if (btnStart) {
        document.getElementById('engine-veh-id').value = btnStart.dataset.id;
        document.getElementById('engine-nom').textContent = btnStart.dataset.nom;
        document.getElementById('engine-action').value = 'start_engine';
        document.getElementById('modal-engine-title').innerHTML =
            '<i class="fas fa-play" style="margin-right:6px;color:#059669"></i> Réactiver le moteur';
        document.getElementById('engine-btn').innerHTML =
            '<i class="fas fa-play"></i> Réactiver le moteur';
        document.getElementById('engine-btn').className = 'btn btn-success';
        openModal('modal-engine');
        return;
    }

    // Dissocier
    const btnDiss = e.target.closest('.btn-dissocier');
    if (btnDiss) {
        if (confirm('Dissocier le boîtier GPS de «' + btnDiss.dataset.nom + '» ?')) {
            document.getElementById('dissocier-veh-id').value = btnDiss.dataset.id;
            document.getElementById('form-dissocier').submit();
        }
        return;
    }
});

// ── Auto-refresh des positions toutes les 60s ────────────────────────────────
let refreshTimer;
function scheduleRefresh() {
    refreshTimer = setTimeout(() => {
        document.getElementById('refresh-icon').style.display = 'inline-block';
        window.location.reload();
    }, 60000);
}
scheduleRefresh();

// ── Guide : toggle accordéons ────────────────────────────────────────────────
function toggleGuide(header) {
    const body = header.nextElementSibling;
    const chev = header.querySelector('.guide-chev');
    const open = body.style.display === 'none';
    body.style.display = open ? 'block' : 'none';
    if (chev) chev.style.transform = open ? 'rotate(180deg)' : 'rotate(0deg)';
}

// ── Onglets marques ──────────────────────────────────────────────────────────
function showBrand(key) {
    document.querySelectorAll('.brand-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.brand-tab').forEach(t => {
        t.classList.remove('active');
        t.style.color = '#64748b';
        t.style.borderBottomColor = 'transparent';
    });
    const panel = document.getElementById('brand-' + key);
    if (panel) panel.style.display = 'block';
    const tab = event.target.closest('.brand-tab');
    if (tab) {
        tab.classList.add('active');
        tab.style.color = '#0d9488';
        tab.style.borderBottomColor = '#0d9488';
    }
}

// ── Copier SMS ───────────────────────────────────────────────────────────────
function copySMS(el) {
    // Get text content minus the copy hint
    const hint = el.querySelector('.sms-copy-hint');
    const text = el.textContent.replace(hint?.textContent || '', '').trim();
    navigator.clipboard.writeText(text).then(() => {
        el.classList.add('copied');
        hint.innerHTML = '<i class="fas fa-check"></i> Copié !';
        setTimeout(() => {
            el.classList.remove('copied');
            hint.innerHTML = '<i class="fas fa-copy"></i> Copier';
        }, 2000);
    });
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
