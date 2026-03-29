<?php
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireTenantAuth();
if (!hasGpsModule()) { setFlash(FLASH_WARNING, 'Module GPS non disponible.'); redirect(BASE_URL . 'app/dashboard.php'); }

$db       = (new Database())->getConnection();
$tenantId = getTenantId();

$stmt = $db->prepare("SELECT id, nom, immatriculation, marque, modele, statut, traccar_device_id, modele_boitier FROM vehicules WHERE tenant_id=? ORDER BY nom");
$stmt->execute([$tenantId]);
$vehicules    = $stmt->fetchAll(PDO::FETCH_ASSOC);
$vehiculesGps = array_values(array_filter($vehicules, fn($v) => $v['traccar_device_id']));

$focusId   = (int)($_GET['vehicule'] ?? 0);
$nbAlertes = (int)$db->query("SELECT COUNT(*) FROM alertes_regles WHERE tenant_id=$tenantId AND lu=0 AND type_alerte!='ralenti_debut'")->fetchColumn();
$csrfToken = generateCSRF();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>FlotteCar — Carte GPS</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
html,body { height:100%; overflow:hidden; font-family:'Inter',-apple-system,sans-serif; background:#0f172a; }

/* ── Layout principal ── */
.gps-shell { display:flex; height:100vh; position:relative; }

/* ── Sidebar gauche ── */
.gps-panel {
    width:300px; flex-shrink:0;
    background:rgba(15,23,42,.98);
    backdrop-filter:blur(20px);
    border-right:1px solid rgba(255,255,255,.07);
    display:flex; flex-direction:column;
    z-index:1000; transition:transform .3s ease;
    overflow:hidden;
}
.gps-panel.collapsed { transform:translateX(-300px); }

.panel-head {
    padding:14px 16px 10px;
    border-bottom:1px solid rgba(255,255,255,.07);
    display:flex; align-items:center; gap:8px;
}
.panel-logo { font-size:.88rem; font-weight:800; color:#fff; letter-spacing:-.02em; flex:1; }
.panel-logo span { color:#14b8a6; }
.panel-badge { border-radius:99px; padding:2px 8px; font-size:.65rem; font-weight:700; text-decoration:none; }
.panel-badge.info { background:#14b8a6; color:#fff; }
.panel-badge.success { background:#22c55e; color:#fff; }
.panel-badge.danger { background:#ef4444; color:#fff; animation:pulse-badge .8s infinite; }
@keyframes pulse-badge { 0%,100%{opacity:1} 50%{opacity:.6} }

.panel-search { padding:8px 12px; border-bottom:1px solid rgba(255,255,255,.05); position:relative; }
.panel-search input {
    width:100%; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1);
    border-radius:8px; padding:7px 10px 7px 32px; color:#fff; font-size:.82rem; outline:none;
}
.panel-search input::placeholder { color:rgba(255,255,255,.3); }
.panel-search i { position:absolute; left:22px; top:50%; transform:translateY(-50%); color:rgba(255,255,255,.3); font-size:.75rem; }

.panel-kpis { display:grid; grid-template-columns:1fr 1fr 1fr; gap:5px; padding:8px 10px; border-bottom:1px solid rgba(255,255,255,.05); }
.kpi { background:rgba(255,255,255,.04); border-radius:8px; padding:7px 4px; text-align:center; }
.kpi-val { font-size:1.2rem; font-weight:800; color:#fff; line-height:1; }
.kpi-val.green { color:#22c55e; } .kpi-val.amber { color:#f59e0b; } .kpi-val.blue { color:#14b8a6; }
.kpi-lbl { font-size:.56rem; text-transform:uppercase; letter-spacing:.06em; color:rgba(255,255,255,.35); margin-top:2px; }

.veh-list { flex:1; overflow-y:auto; padding:6px 0; }
.veh-list::-webkit-scrollbar { width:3px; }
.veh-list::-webkit-scrollbar-thumb { background:rgba(255,255,255,.12); border-radius:2px; }

.veh-item {
    display:flex; align-items:center; gap:9px;
    padding:9px 12px; cursor:pointer;
    border-left:3px solid transparent;
    transition:all .15s; margin:0 6px 2px; border-radius:8px;
}
.veh-item:hover { background:rgba(255,255,255,.06); }
.veh-item.active { background:rgba(59,130,246,.15); border-left-color:#14b8a6; }
.veh-item.offline { opacity:.5; }

.veh-dot { width:9px; height:9px; border-radius:50%; flex-shrink:0; }
.veh-dot.online  { background:#22c55e; box-shadow:0 0 0 3px rgba(34,197,94,.25); animation:pulse-dot 2s infinite; }
.veh-dot.offline { background:#475569; }
.veh-dot.moving  { background:#14b8a6; box-shadow:0 0 0 3px rgba(59,130,246,.25); animation:pulse-dot 1s infinite; }
@keyframes pulse-dot { 0%,100%{box-shadow:0 0 0 3px rgba(34,197,94,.25)} 50%{box-shadow:0 0 0 6px rgba(34,197,94,.05)} }

.veh-info { flex:1; min-width:0; }
.veh-name  { font-size:.8rem; font-weight:700; color:#f1f5f9; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.veh-immat { font-size:.67rem; color:rgba(255,255,255,.35); }
.veh-speed { font-size:.76rem; font-weight:700; color:#64748b; flex-shrink:0; }
.veh-speed.moving { color:#14b8a6; }

.panel-actions { padding:8px 10px; border-top:1px solid rgba(255,255,255,.07); display:flex; gap:5px; flex-wrap:wrap; }
.panel-btn {
    flex:1; min-width:60px; padding:7px 4px; border-radius:8px; border:none; cursor:pointer;
    font-size:.67rem; font-weight:700; display:flex; flex-direction:column; align-items:center; gap:3px;
    transition:all .15s; text-decoration:none;
}
.panel-btn i { font-size:.9rem; }
.panel-btn.blue  { background:rgba(59,130,246,.2);  color:#14b8a6; }
.panel-btn.green { background:rgba(34,197,94,.2);  color:#22c55e; }
.panel-btn.amber { background:rgba(245,158,11,.2); color:#f59e0b; }
.panel-btn.red   { background:rgba(239,68,68,.2);   color:#ef4444; }
.panel-btn:hover { filter:brightness(1.3); transform:translateY(-1px); }

/* ── Carte ── */
#map { width:100%; height:100vh; z-index:1; }

/* ── Topbar flottante ── */
.map-topbar {
    position:absolute; top:12px; left:50%; transform:translateX(-50%);
    background:rgba(15,23,42,.92); backdrop-filter:blur(16px);
    border:1px solid rgba(255,255,255,.1); border-radius:12px;
    padding:7px 14px; display:flex; align-items:center; gap:10px;
    z-index:1001; white-space:nowrap; box-shadow:0 4px 24px rgba(0,0,0,.4);
}
.map-topbar-title { font-size:.8rem; font-weight:700; color:#fff; }
.live-dot { width:7px; height:7px; border-radius:50%; background:#22c55e; animation:pulse-dot 1.5s infinite; }
.countdown-ring { font-size:.7rem; color:rgba(255,255,255,.45); }

.btn-toggle-panel {
    position:absolute; top:12px; left:12px; z-index:1002;
    background:rgba(15,23,42,.92); border:1px solid rgba(255,255,255,.1);
    border-radius:10px; width:38px; height:38px;
    display:none; align-items:center; justify-content:center;
    cursor:pointer; color:#fff; font-size:.95rem;
    box-shadow:0 4px 12px rgba(0,0,0,.3);
}

.map-fab {
    position:absolute; bottom:24px; right:16px; z-index:1001;
    display:flex; flex-direction:column; gap:8px; align-items:flex-end;
}
.fab-btn {
    width:46px; height:46px; border-radius:50%; border:none; cursor:pointer;
    display:flex; align-items:center; justify-content:center; font-size:1.05rem;
    box-shadow:0 4px 16px rgba(0,0,0,.4); transition:all .2s;
}
.fab-btn:hover { transform:scale(1.1); }
.fab-main   { background:#14b8a6; color:#fff; width:52px; height:52px; font-size:1.2rem; }
.fab-center { background:#0f172a; color:#94a3b8; }
.fab-layers { background:#0f172a; color:#94a3b8; }

/* ── Layers popup ── */
.layers-popup {
    position:absolute; bottom:86px; right:64px; z-index:1002;
    background:rgba(15,23,42,.97); border:1px solid rgba(255,255,255,.1);
    border-radius:12px; padding:10px; display:none; flex-direction:column; gap:4px;
    box-shadow:0 8px 32px rgba(0,0,0,.4);
}
.layers-popup.open { display:flex; }
.layer-btn {
    display:flex; align-items:center; gap:8px; padding:7px 12px;
    border-radius:8px; cursor:pointer; color:#94a3b8; font-size:.76rem; font-weight:600;
    transition:all .15s; border:none; background:transparent;
}
.layer-btn:hover,.layer-btn.active { background:rgba(59,130,246,.2); color:#14b8a6; }

/* ── Marker custom ── */
.car-marker-icon {
    background:linear-gradient(135deg,#0d9488,#14b8a6);
    color:#fff; border-radius:50% 50% 50% 0;
    width:42px; height:42px; display:flex; align-items:center; justify-content:center;
    font-size:.8rem; border:3px solid #fff;
    box-shadow:0 4px 16px rgba(26,86,219,.5);
    transform:rotate(-45deg);
}
.car-marker-icon span { transform:rotate(45deg); display:block; font-size:.6rem; line-height:1.1; text-align:center; }
.car-marker-icon.moving { background:linear-gradient(135deg,#059669,#22c55e); box-shadow:0 4px 16px rgba(34,197,94,.5); }
.car-marker-icon.offline { background:linear-gradient(135deg,#334155,#475569); box-shadow:none; }
.car-marker-label {
    position:absolute; bottom:-20px; left:50%; transform:translateX(-50%);
    background:rgba(15,23,42,.9); color:#fff; font-size:.6rem; font-weight:700;
    padding:2px 6px; border-radius:4px; white-space:nowrap; pointer-events:none;
    border:1px solid rgba(255,255,255,.15);
}
.car-marker-pulse {
    position:absolute; inset:-8px; border-radius:50%;
    background:rgba(59,130,246,.2); animation:pulse-ring 2s infinite; pointer-events:none;
}
@keyframes pulse-ring { 0%{transform:scale(1);opacity:.8} 100%{transform:scale(1.6);opacity:0} }

/* ── Popup Leaflet ── */
.leaflet-popup-content-wrapper {
    background:rgba(13,20,38,.98) !important; border:1px solid rgba(255,255,255,.12) !important;
    border-radius:14px !important; box-shadow:0 8px 32px rgba(0,0,0,.6) !important; padding:0 !important;
    color:#f1f5f9 !important;
}
.leaflet-popup-tip { background:rgba(13,20,38,.98) !important; }
.leaflet-popup-content { margin:0 !important; min-width:260px; }

.popup-wrap { display:flex; flex-direction:column; }
.popup-head { padding:14px 16px 10px; border-bottom:1px solid rgba(255,255,255,.08); }
.popup-title { font-weight:800; font-size:.97rem; color:#fff; margin-bottom:1px; }
.popup-immat { font-size:.7rem; color:rgba(255,255,255,.4); }

.popup-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:0; border-bottom:1px solid rgba(255,255,255,.07); }
.popup-stat { padding:10px 6px; text-align:center; border-right:1px solid rgba(255,255,255,.06); }
.popup-stat:last-child { border-right:none; }
.popup-stat-val { font-size:1rem; font-weight:800; color:#fff; line-height:1; }
.popup-stat-lbl { font-size:.56rem; text-transform:uppercase; letter-spacing:.06em; color:rgba(255,255,255,.35); margin-top:2px; }

.popup-addr { padding:8px 14px; font-size:.7rem; color:rgba(255,255,255,.45); border-bottom:1px solid rgba(255,255,255,.07); display:flex; align-items:center; gap:6px; min-height:34px; }
.popup-addr i { color:#14b8a6; flex-shrink:0; }

/* Tabs */
.popup-tabs { display:flex; border-bottom:1px solid rgba(255,255,255,.07); }
.popup-tab {
    flex:1; padding:8px 4px; border:none; background:transparent; cursor:pointer;
    font-size:.68rem; font-weight:700; color:rgba(255,255,255,.4);
    border-bottom:2px solid transparent; transition:all .15s;
    display:flex; align-items:center; justify-content:center; gap:4px;
}
.popup-tab.active { color:#14b8a6; border-bottom-color:#14b8a6; }
.popup-tab:hover { color:#94a3b8; }

.popup-tab-body { display:none; padding:12px; max-height:200px; overflow-y:auto; }
.popup-tab-body.active { display:block; }
.popup-tab-body::-webkit-scrollbar { width:3px; }
.popup-tab-body::-webkit-scrollbar-thumb { background:rgba(255,255,255,.1); border-radius:2px; }

/* Actions tab */
.action-grid { display:grid; grid-template-columns:1fr 1fr; gap:6px; }
.action-btn {
    padding:10px 8px; border-radius:10px; border:none; cursor:pointer;
    font-size:.71rem; font-weight:700; display:flex; flex-direction:column;
    align-items:center; gap:4px; transition:all .15s; text-decoration:none;
}
.action-btn i { font-size:1.1rem; }
.action-btn:hover { filter:brightness(1.2); transform:translateY(-1px); }
.action-btn.red    { background:rgba(239,68,68,.2);   color:#ef4444; }
.action-btn.green  { background:rgba(34,197,94,.2);  color:#22c55e; }
.action-btn.blue   { background:rgba(59,130,246,.2);  color:#14b8a6; }
.action-btn.amber  { background:rgba(245,158,11,.2); color:#f59e0b; }
.action-btn.purple { background:rgba(139,92,246,.2); color:#a78bfa; }
.action-btn.cyan   { background:rgba(6,182,212,.2);  color:#22d3ee; }
.action-btn.disabled { opacity:.4; cursor:not-allowed; }

/* Trips tab */
.trip-item { padding:8px 0; border-bottom:1px solid rgba(255,255,255,.05); }
.trip-item:last-child { border-bottom:none; }
.trip-route { font-size:.72rem; font-weight:600; color:#f1f5f9; margin-bottom:3px; }
.trip-meta  { font-size:.64rem; color:rgba(255,255,255,.4); display:flex; gap:10px; }

/* Events tab */
.event-item { display:flex; gap:8px; padding:6px 0; border-bottom:1px solid rgba(255,255,255,.05); }
.event-item:last-child { border-bottom:none; }
.event-dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; margin-top:4px; }
.event-type { font-size:.7rem; font-weight:700; color:#f1f5f9; }
.event-time { font-size:.63rem; color:rgba(255,255,255,.4); }

/* Commands tab */
.cmd-grid { display:grid; grid-template-columns:1fr 1fr; gap:6px; }
.cmd-btn {
    padding:8px 6px; border-radius:8px; border:none; cursor:pointer;
    font-size:.67rem; font-weight:700; display:flex; align-items:center; gap:5px;
    transition:all .15s; background:rgba(255,255,255,.06); color:#94a3b8;
}
.cmd-btn:hover { background:rgba(255,255,255,.1); color:#fff; }
.cmd-btn i { font-size:.85rem; width:14px; text-align:center; }

/* Toast notification */
.gps-toast {
    position:fixed; bottom:80px; left:50%; transform:translateX(-50%) translateY(20px);
    background:rgba(15,23,42,.97); border:1px solid rgba(255,255,255,.15);
    border-radius:12px; padding:12px 20px; z-index:9999;
    display:flex; align-items:center; gap:10px;
    font-size:.82rem; font-weight:600; color:#fff;
    box-shadow:0 8px 24px rgba(0,0,0,.5);
    opacity:0; transition:all .3s; pointer-events:none;
}
.gps-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
.gps-toast.success .toast-icon { color:#22c55e; }
.gps-toast.error   .toast-icon { color:#ef4444; }
.gps-toast.info    .toast-icon { color:#14b8a6; }
.gps-toast.warning .toast-icon { color:#f59e0b; }

/* Loading spinner */
.spinner { display:inline-block; width:14px; height:14px; border:2px solid rgba(255,255,255,.2); border-top-color:currentColor; border-radius:50%; animation:spin .6s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
@keyframes spin2 { to { transform:rotate(360deg); } }

/* Mobile — bottom sheet */
@media(max-width:768px) {
    /* Shell: map on top, panel fixed at bottom */
    .gps-shell { flex-direction:column; height:100vh; position:relative; }

    /* Map fills screen, but bottom-sheet overlaps it */
    #map { width:100%; height:100vh !important; position:absolute; inset:0; z-index:1; }

    /* Panel fixed to bottom */
    .gps-panel {
        position:fixed; bottom:0; left:0; right:0; top:auto;
        width:100%; height:260px;
        flex-direction:column;
        border-right:none;
        border-top:1px solid rgba(255,255,255,.12);
        border-radius:20px 20px 0 0;
        z-index:1000;
        transform:none !important;
        box-shadow:0 -8px 32px rgba(0,0,0,.6);
        overflow:hidden;
    }
    .gps-panel.collapsed { transform:translateY(calc(100% - 46px)) !important; }

    /* Handle bar at top of panel */
    .panel-head {
        padding:8px 14px 8px;
        border-bottom:1px solid rgba(255,255,255,.07);
        flex-shrink:0;
    }
    .panel-head::before {
        content:'';
        display:block;
        width:36px; height:4px;
        background:rgba(255,255,255,.2);
        border-radius:2px;
        margin:0 auto 8px;
    }

    /* KPIs visible, compact */
    .panel-kpis { display:grid; grid-template-columns:1fr 1fr 1fr; flex-shrink:0; }

    /* Search hidden on mobile to save space */
    .panel-search { display:none; }

    /* Vehicle list: horizontal scroll strip */
    .veh-list {
        display:flex; flex-direction:row;
        overflow-x:auto; overflow-y:hidden;
        flex:1; padding:6px 8px;
        gap:6px;
        -webkit-overflow-scrolling:touch;
    }
    .veh-list::-webkit-scrollbar { display:none; }
    .veh-item {
        min-width:140px; max-width:140px;
        flex-direction:column; align-items:flex-start;
        padding:8px 10px; border-radius:10px;
        border-left:none; border-top:2px solid transparent;
        background:rgba(255,255,255,.04);
        flex-shrink:0;
    }
    .veh-item.active { border-top-color:#14b8a6; background:rgba(59,130,246,.15); }
    .veh-name { font-size:.75rem; }
    .veh-immat { font-size:.62rem; }

    /* Actions row */
    .panel-actions {
        padding:6px 10px 10px;
        flex-shrink:0;
        gap:4px;
    }
    .panel-btn { padding:6px 4px; font-size:.62rem; }

    /* Toggle button — shown as pull handle */
    .btn-toggle-panel {
        display:flex;
        top:auto; left:50%; bottom:262px;
        transform:translateX(-50%);
        width:44px; height:22px; border-radius:11px 11px 0 0;
        z-index:1001;
    }

    /* Map topbar: above the panel */
    .map-topbar { left:50%; transform:translateX(-50%); top:10px; }

    /* FAB: above the panel */
    .map-fab { bottom:270px; }

    /* Layers popup */
    .layers-popup { bottom:calc(260px + 54px); right:64px; }

    /* Toast: above panel */
    .gps-toast { bottom:270px; }
}
</style>
</head>
<body>
<div class="gps-shell">

<!-- ═══ PANEL GAUCHE ════════════════════════════════════════════════════════ -->
<div class="gps-panel" id="gps-panel">
    <div class="panel-head">
        <div class="panel-logo">Flotte<span>Car</span> GPS</div>
        <span class="panel-badge info" id="badge-online">0 en ligne</span>
        <?php if ($nbAlertes > 0): ?>
        <a href="<?= BASE_URL ?>app/gps/alertes.php" class="panel-badge danger"><i class="fas fa-bell"></i> <?= $nbAlertes ?></a>
        <?php endif ?>
    </div>

    <div class="panel-search">
        <i class="fas fa-search"></i>
        <input type="text" id="search-veh" placeholder="Rechercher un véhicule…">
    </div>

    <div class="panel-kpis">
        <div class="kpi">
            <div class="kpi-val blue" id="kpi-total"><?= count($vehiculesGps) ?></div>
            <div class="kpi-lbl">Total GPS</div>
        </div>
        <div class="kpi">
            <div class="kpi-val green" id="kpi-online">0</div>
            <div class="kpi-lbl">En ligne</div>
        </div>
        <div class="kpi">
            <div class="kpi-val amber" id="kpi-moving">0</div>
            <div class="kpi-lbl">Mouvement</div>
        </div>
    </div>

    <div class="veh-list" id="veh-list">
        <?php if (empty($vehiculesGps)): ?>
        <div style="text-align:center;padding:28px 14px;color:rgba(255,255,255,.3)">
            <i class="fas fa-satellite-dish" style="font-size:1.8rem;display:block;margin-bottom:8px"></i>
            <div style="font-size:.8rem">Aucun boîtier GPS configuré</div>
            <a href="<?= BASE_URL ?>app/gps/appareils.php" style="color:#14b8a6;font-size:.72rem;display:block;margin-top:6px">Configurer les boîtiers →</a>
        </div>
        <?php else: ?>
        <?php foreach ($vehiculesGps as $v): ?>
        <div class="veh-item offline" id="veh-item-<?= $v['id'] ?>" data-id="<?= $v['id'] ?>"
             data-name="<?= strtolower(sanitize($v['nom'])) ?> <?= strtolower(sanitize($v['immatriculation'])) ?>"
             onclick="focusVehicule(<?= $v['id'] ?>)">
            <div class="veh-dot offline" id="dot-<?= $v['id'] ?>"></div>
            <div class="veh-info">
                <div class="veh-name"><?= sanitize($v['nom']) ?></div>
                <div class="veh-immat"><?= sanitize($v['immatriculation']) ?><?php if($v['marque']): ?> · <?= sanitize($v['marque']) ?><?php endif ?></div>
            </div>
            <div class="veh-speed" id="spd-<?= $v['id'] ?>">--</div>
        </div>
        <?php endforeach ?>
        <?php endif ?>
    </div>

    <div class="panel-actions">
        <button class="panel-btn blue" onclick="centerAll()"><i class="fas fa-compress-arrows-alt"></i><span>Centrer</span></button>
        <a href="<?= BASE_URL ?>app/gps/alertes.php" class="panel-btn <?= $nbAlertes > 0 ? 'red' : 'amber' ?>">
            <i class="fas fa-bell"></i><span>Alertes<?= $nbAlertes > 0 ? " ($nbAlertes)" : '' ?></span>
        </a>
        <a href="<?= BASE_URL ?>app/gps/regles.php" class="panel-btn green"><i class="fas fa-sliders-h"></i><span>Règles</span></a>
        <a href="<?= BASE_URL ?>app/gps/appareils.php" class="panel-btn blue"><i class="fas fa-satellite-dish"></i><span>Boîtiers</span></a>
        <a href="<?= BASE_URL ?>app/dashboard.php" class="panel-btn amber"><i class="fas fa-home"></i><span>Accueil</span></a>
    </div>
</div>

<!-- ═══ CARTE ════════════════════════════════════════════════════════════════ -->
<div style="flex:1;position:relative">
    <div class="map-topbar">
        <div class="live-dot"></div>
        <div class="map-topbar-title">Suivi temps réel</div>
        <div style="width:1px;height:12px;background:rgba(255,255,255,.1)"></div>
        <div class="countdown-ring"><i class="fas fa-sync-alt" id="sync-icon"></i> <span id="countdown">15</span>s</div>
        <div style="width:1px;height:12px;background:rgba(255,255,255,.1)"></div>
        <div style="font-size:.7rem;color:rgba(255,255,255,.45)" id="last-update">--</div>
    </div>

    <button class="btn-toggle-panel" onclick="togglePanel()"><i class="fas fa-bars"></i></button>
    <div id="map"></div>

    <div class="map-fab">
        <button class="fab-btn fab-layers" onclick="toggleLayers()" title="Fond de carte">
            <i class="fas fa-layer-group"></i>
        </button>
        <button class="fab-btn fab-center" onclick="centerAll()" title="Centrer tous">
            <i class="fas fa-compress-arrows-alt"></i>
        </button>
        <button class="fab-btn fab-main" onclick="fetchPositions(true)" title="Actualiser">
            <i class="fas fa-sync-alt"></i>
        </button>
    </div>

    <div class="layers-popup" id="layers-popup">
        <button class="layer-btn active" id="layer-osm" onclick="switchLayer('osm')"><i class="fas fa-map"></i> OpenStreetMap</button>
        <button class="layer-btn" id="layer-sat" onclick="switchLayer('sat')"><i class="fas fa-satellite"></i> Satellite</button>
        <button class="layer-btn" id="layer-dark" onclick="switchLayer('dark')"><i class="fas fa-moon"></i> Dark</button>
    </div>
</div>
</div>

<!-- ═══ TOAST ════════════════════════════════════════════════════════════════ -->
<div class="gps-toast" id="gps-toast">
    <i class="toast-icon fas fa-check-circle"></i>
    <span id="toast-msg">Message</span>
</div>

<!-- ═══ PANEL HORS LIGNE ══════════════════════════════════════════════════════ -->
<div id="offline-panel" style="
    display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%);
    background:rgba(13,20,38,.98); border:1px solid rgba(255,255,255,.12);
    border-radius:16px; padding:0; z-index:9998; min-width:300px; max-width:360px;
    box-shadow:0 16px 48px rgba(0,0,0,.7);
">
    <div style="padding:16px 18px 12px; border-bottom:1px solid rgba(255,255,255,.08); display:flex; align-items:center; gap:10px;">
        <div style="flex:1">
            <div id="op-title" style="font-weight:800;font-size:.97rem;color:#fff"></div>
            <div id="op-immat" style="font-size:.7rem;color:rgba(255,255,255,.4);margin-top:1px"></div>
        </div>
        <button onclick="closeOfflinePanel()" style="background:rgba(255,255,255,.08);border:none;color:#94a3b8;width:30px;height:30px;border-radius:8px;cursor:pointer;font-size:.9rem">✕</button>
    </div>
    <div style="padding:14px 16px; border-bottom:1px solid rgba(255,255,255,.07);">
        <div style="display:flex;align-items:center;gap:10px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);border-radius:10px;padding:10px 12px;">
            <div style="width:10px;height:10px;border-radius:50%;background:#ef4444;flex-shrink:0"></div>
            <div>
                <div style="font-size:.78rem;font-weight:700;color:#ef4444">Véhicule hors ligne</div>
                <div id="op-last" style="font-size:.67rem;color:rgba(255,255,255,.35);margin-top:1px">Dernier signal inconnu</div>
            </div>
        </div>
    </div>
    <div style="padding:14px 16px;">
        <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,.3);margin-bottom:10px">Actions disponibles</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;" id="op-actions"></div>
    </div>
</div>
<div id="offline-overlay" onclick="closeOfflinePanel()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9997"></div>

<!-- Scripts -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const VEHICULES   = <?= json_encode($vehiculesGps, JSON_UNESCAPED_UNICODE) ?>;
const API         = '<?= BASE_URL ?>api/gps.php';
const BASE        = '<?= BASE_URL ?>';
const FOCUS_ID    = <?= $focusId ?: 'null' ?>;
const CSRF_TOKEN  = '<?= $csrfToken ?>';

// ── Init carte ──────────────────────────────────────────────────────────────
const map = L.map('map', { zoomControl:false, attributionControl:false }).setView([5.3600, -4.0083], 12);
L.control.zoom({ position:'topright' }).addTo(map);
L.control.attribution({ prefix:'© OSM' }).addTo(map);

const tileLayers = {
    osm:  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom:19 }),
    sat:  L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom:19 }),
    dark: L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxZoom:19 }),
};
tileLayers.osm.addTo(map);
let currentLayer = 'osm';

function switchLayer(name) {
    Object.values(tileLayers).forEach(l => map.removeLayer(l));
    tileLayers[name].addTo(map);
    currentLayer = name;
    document.querySelectorAll('.layer-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('layer-' + name)?.classList.add('active');
    toggleLayers();
}
function toggleLayers() { document.getElementById('layers-popup').classList.toggle('open'); }

// ── Markers & data ──────────────────────────────────────────────────────────
const markers    = {};
const markerData = {};
const trails     = {};
const liveData   = {}; // positions courantes par vehicule_id

function makeMarkerIcon(veh, speed, isOnline) {
    const isMoving = isOnline && speed > 2;
    const cls  = !isOnline ? 'offline' : isMoving ? 'moving' : '';
    const label = (veh.immatriculation || '').replace(/\s/g,'').slice(-7);
    const pulse = isMoving ? '<div class="car-marker-pulse"></div>' : '';
    const html  = `<div style="position:relative">
        ${pulse}
        <div class="car-marker-icon ${cls}">
            <span><i class="fas fa-car"></i></span>
        </div>
        <div class="car-marker-label">${label}</div>
    </div>`;
    return L.divIcon({ html, className:'', iconSize:[42,64], iconAnchor:[21,42] });
}

// ── Popup avec tabs ──────────────────────────────────────────────────────────
function makePopupHtml(v, pos) {
    const speed    = pos ? Math.round((pos.vitesse||0)) : 0;
    const isOnline = !!(pos?.lat);
    const moteur   = pos?.moteur;
    const timeStr  = pos?.horodatage ? new Date(pos.horodatage).toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'}) : '--';
    const distKm   = pos?.distance ? (pos.distance/1000).toFixed(1) : '--';

    return `<div class="popup-wrap">
        <div class="popup-head">
            <div class="popup-title"><i class="fas fa-car" style="color:#14b8a6;margin-right:5px"></i>${v.nom}</div>
            <div class="popup-immat">${v.immatriculation}${v.marque?' · '+v.marque:''}</div>
        </div>
        <div class="popup-stats">
            <div class="popup-stat">
                <div class="popup-stat-val" style="color:${speed>0?'#14b8a6':'#94a3b8'}">${speed}</div>
                <div class="popup-stat-lbl">km/h</div>
            </div>
            <div class="popup-stat">
                <div class="popup-stat-val" style="color:${moteur?'#22c55e':'#ef4444'}"><i class="fas fa-power-off"></i></div>
                <div class="popup-stat-lbl">${moteur?'Moteur ON':'Moteur OFF'}</div>
            </div>
            <div class="popup-stat">
                <div class="popup-stat-val" style="color:${isOnline?'#22c55e':'#64748b'};font-size:.72rem">${isOnline?'EN LIGNE':'HORS LIGNE'}</div>
                <div class="popup-stat-lbl">Statut</div>
            </div>
            <div class="popup-stat">
                <div class="popup-stat-val" style="font-size:.72rem;color:#94a3b8">${timeStr}</div>
                <div class="popup-stat-lbl">Signal</div>
            </div>
        </div>
        <div class="popup-addr" id="addr-${v.id}">
            <i class="fas fa-map-marker-alt"></i>
            <span>${isOnline ? '<span class="spinner"></span> Chargement...' : 'Hors ligne'}</span>
        </div>

        <div class="popup-tabs">
            <button class="popup-tab active" onclick="switchTab('actions',${v.id},this)"><i class="fas fa-bolt"></i> Actions</button>
            <button class="popup-tab" onclick="switchTab('trips',${v.id},this)"><i class="fas fa-route"></i> Trajets</button>
            <button class="popup-tab" onclick="switchTab('events',${v.id},this)"><i class="fas fa-list"></i> Événements</button>
            <button class="popup-tab" onclick="switchTab('cmds',${v.id},this)"><i class="fas fa-terminal"></i> Cmds</button>
        </div>

        <!-- TAB: Actions rapides -->
        <div class="popup-tab-body active" id="tab-actions-${v.id}">
            <div class="action-grid">
                <button class="action-btn red" onclick="engineCmd(${v.id},'stop')">
                    <i class="fas fa-power-off"></i> Couper moteur
                </button>
                <button class="action-btn green" onclick="engineCmd(${v.id},'start')">
                    <i class="fas fa-play-circle"></i> Démarrer
                </button>
                <button class="action-btn blue" onclick="refreshPos(${v.id})">
                    <i class="fas fa-crosshairs"></i> Localiser
                </button>
                <button class="action-btn amber" onclick="showRoute(${v.id})">
                    <i class="fas fa-draw-polygon"></i> Trace route
                </button>
                <button class="action-btn purple" onclick="loadTrips(${v.id})">
                    <i class="fas fa-route"></i> Trajets aujourd'hui
                </button>
                <a href="${BASE}app/vehicules/detail.php?id=${v.id}" class="action-btn cyan">
                    <i class="fas fa-file-alt"></i> Fiche véhicule
                </a>
                <button class="action-btn blue" onclick="copyCoords(${v.id})">
                    <i class="fas fa-copy"></i> Copier coords
                </button>
                <button class="action-btn amber" onclick="openInMaps(${v.id})">
                    <i class="fas fa-external-link-alt"></i> Google Maps
                </button>
            </div>
        </div>

        <!-- TAB: Trajets -->
        <div class="popup-tab-body" id="tab-trips-${v.id}">
            <div id="trips-content-${v.id}" style="color:rgba(255,255,255,.4);font-size:.75rem;text-align:center;padding:16px">
                <button onclick="loadTrips(${v.id})" style="background:rgba(59,130,246,.2);color:#14b8a6;border:none;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:.75rem">
                    <i class="fas fa-sync-alt"></i> Charger les trajets
                </button>
            </div>
        </div>

        <!-- TAB: Événements -->
        <div class="popup-tab-body" id="tab-events-${v.id}">
            <div id="events-content-${v.id}" style="color:rgba(255,255,255,.4);font-size:.75rem;text-align:center;padding:16px">
                <button onclick="loadEvents(${v.id})" style="background:rgba(59,130,246,.2);color:#14b8a6;border:none;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:.75rem">
                    <i class="fas fa-sync-alt"></i> Charger les événements
                </button>
            </div>
        </div>

        <!-- TAB: Commandes avancées -->
        <div class="popup-tab-body" id="tab-cmds-${v.id}">
            <div class="cmd-grid">
                <button class="cmd-btn" onclick="sendCmd(${v.id},'positionSingle')"><i class="fas fa-map-pin"></i> Position immédiate</button>
                <button class="cmd-btn" onclick="sendCmd(${v.id},'positionPeriodic')"><i class="fas fa-clock"></i> Reporting périodique</button>
                <button class="cmd-btn" onclick="sendCmd(${v.id},'positionStop')"><i class="fas fa-stop-circle"></i> Arrêter reporting</button>
                <button class="cmd-btn" onclick="sendCmd(${v.id},'alarmArm')"><i class="fas fa-lock"></i> Armer alarme</button>
                <button class="cmd-btn" onclick="sendCmd(${v.id},'alarmDisarm')"><i class="fas fa-lock-open"></i> Désarmer alarme</button>
                <button class="cmd-btn" onclick="sendCmd(${v.id},'requestPhoto')"><i class="fas fa-camera"></i> Prendre photo</button>
                <button class="cmd-btn" onclick="sendCmd(${v.id},'silentMessage')"><i class="fas fa-sms"></i> Message silencieux</button>
            </div>
        </div>
    </div>`;
}

// ── Switch onglets popup ─────────────────────────────────────────────────────
function switchTab(tab, vehId, btn) {
    const popup = btn.closest('.popup-wrap');
    popup.querySelectorAll('.popup-tab').forEach(t => t.classList.remove('active'));
    popup.querySelectorAll('.popup-tab-body').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + tab + '-' + vehId)?.classList.add('active');
}

// ── Adresse reverse geocode ───────────────────────────────────────────────────
async function loadAddress(vehId) {
    const d = liveData[vehId];
    if (!d || !d.lat) return;
    try {
        const r   = await fetch(`${API}?action=position&vehicule_id=${vehId}&t=${Date.now()}`);
        const pos = await r.json();
        const el  = document.getElementById('addr-' + vehId);
        if (el && pos.adresse) el.innerHTML = `<i class="fas fa-map-marker-alt" style="color:#14b8a6;flex-shrink:0"></i><span style="line-height:1.3">${pos.adresse}</span>`;
    } catch(e) {}
}

// ── Charger trajets du jour ───────────────────────────────────────────────────
async function loadTrips(vehId) {
    const el = document.getElementById('trips-content-' + vehId);
    if (!el) return;
    el.innerHTML = '<div style="text-align:center;padding:12px"><span class="spinner" style="color:#14b8a6"></span></div>';
    // Switch to trips tab
    const tabBtn = document.querySelector(`[onclick="switchTab('trips',${vehId},this)"]`);
    if (tabBtn) switchTab('trips', vehId, tabBtn);
    try {
        const today = new Date().toISOString().slice(0,10);
        const r     = await fetch(`${API}?action=trips&vehicule_id=${vehId}&from=${today}&to=${today}&t=${Date.now()}`);
        const trips = await r.json();
        if (!trips.length) {
            el.innerHTML = '<div style="text-align:center;color:rgba(255,255,255,.3);font-size:.73rem;padding:12px">Aucun trajet aujourd\'hui</div>';
            return;
        }
        el.innerHTML = trips.map(t => {
            const dist = t.distance ? (t.distance/1000).toFixed(1) + ' km' : '--';
            const dur  = t.duration ? Math.round(t.duration/60000) + ' min' : '--';
            const spd  = t.averageSpeed ? Math.round(t.averageSpeed*1.852) + ' km/h moy' : '';
            const from = t.startTime ? new Date(t.startTime).toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'}) : '--';
            const to   = t.endTime   ? new Date(t.endTime).toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'})   : 'En cours';
            return `<div class="trip-item">
                <div class="trip-route"><i class="fas fa-route" style="color:#14b8a6;margin-right:5px"></i>${from} → ${to}</div>
                <div class="trip-meta">
                    <span><i class="fas fa-road"></i> ${dist}</span>
                    <span><i class="fas fa-clock"></i> ${dur}</span>
                    ${spd ? `<span><i class="fas fa-tachometer-alt"></i> ${spd}</span>` : ''}
                </div>
            </div>`;
        }).join('');
    } catch(e) {
        el.innerHTML = '<div style="text-align:center;color:#ef4444;font-size:.73rem;padding:12px">Erreur de chargement</div>';
    }
}

// ── Charger événements du jour ─────────────────────────────────────────────────
async function loadEvents(vehId) {
    const el = document.getElementById('events-content-' + vehId);
    if (!el) return;
    el.innerHTML = '<div style="text-align:center;padding:12px"><span class="spinner" style="color:#14b8a6"></span></div>';
    const tabBtn = document.querySelector(`[onclick="switchTab('events',${vehId},this)"]`);
    if (tabBtn) switchTab('events', vehId, tabBtn);
    try {
        const today = new Date().toISOString().slice(0,10);
        const r     = await fetch(`${API}?action=events&vehicule_id=${vehId}&from=${today}&to=${today}&t=${Date.now()}`);
        const evts  = await r.json();
        if (!Array.isArray(evts) || !evts.length) {
            el.innerHTML = '<div style="text-align:center;color:rgba(255,255,255,.3);font-size:.73rem;padding:12px">Aucun événement aujourd\'hui</div>';
            return;
        }
        const typeMap = {
            deviceOnline:'En ligne','deviceOffline':'Hors ligne',
            geofenceEnter:'Entrée zone',geofenceExit:'Sortie zone',
            alarm:'Alarme',ignitionOn:'Démarrage',ignitionOff:'Arrêt moteur',
            overspeed:'Vitesse excessive',hardBraking:'Freinage brusque',
            hardAcceleration:'Accélération brusque',
        };
        const colorMap = {
            alarm:'#ef4444',overspeed:'#f59e0b',
            geofenceExit:'#f59e0b',geofenceEnter:'#22c55e',
            ignitionOn:'#22c55e',ignitionOff:'#64748b',
        };
        el.innerHTML = evts.slice(0,20).map(e => {
            const label = typeMap[e.type] || e.type;
            const color = colorMap[e.type] || '#14b8a6';
            const time  = e.eventTime ? new Date(e.eventTime).toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'}) : '--';
            return `<div class="event-item">
                <div class="event-dot" style="background:${color}"></div>
                <div>
                    <div class="event-type">${label}</div>
                    <div class="event-time">${time}</div>
                </div>
            </div>`;
        }).join('');
    } catch(e) {
        el.innerHTML = '<div style="text-align:center;color:#ef4444;font-size:.73rem;padding:12px">Erreur de chargement</div>';
    }
}

// ── Commandes moteur (engine stop/start) ────────────────────────────────────
async function engineCmd(vehId, cmd) {
    const labels  = { stop:'Couper le moteur', start:'Remettre en marche' };
    const actions = { stop:'stop_engine',       start:'start_engine' };
    if (!confirm(labels[cmd] + ' de ce véhicule ?')) return;
    showToast('info','<span class="spinner"></span> Commande en cours…');
    try {
        const fd = new FormData();
        fd.append('action', actions[cmd]);
        fd.append('vehicule_id', vehId);
        fd.append('csrf_token', CSRF_TOKEN);
        const r   = await fetch(API, { method:'POST', body:fd });
        const res = await r.json();
        if (res.success) {
            showToast('success', cmd === 'stop' ? '🔴 Moteur coupé avec succès' : '🟢 Véhicule remis en marche');
            setTimeout(() => fetchPositions(), 3000);
        } else {
            showToast('error', res.error || 'Échec de la commande');
        }
    } catch(e) {
        showToast('error', 'Erreur réseau');
    }
}

// ── Commandes personnalisées ─────────────────────────────────────────────────
const cmdLabels = {
    positionSingle:'Demande position immédiate',
    positionPeriodic:'Reporting périodique activé',
    positionStop:'Reporting arrêté',
    alarmArm:'Alarme armée',
    alarmDisarm:'Alarme désarmée',
    requestPhoto:'Photo demandée',
    silentMessage:'Message envoyé',
};

async function sendCmd(vehId, cmdType) {
    showToast('info', '<span class="spinner"></span> Envoi commande…');
    try {
        const fd = new FormData();
        fd.append('action', 'send_command');
        fd.append('vehicule_id', vehId);
        fd.append('cmd_type', cmdType);
        fd.append('csrf_token', CSRF_TOKEN);
        const r   = await fetch(API, { method:'POST', body:fd });
        const res = await r.json();
        if (res.success) showToast('success', cmdLabels[cmdType] || 'Commande envoyée');
        else showToast('error', res.error || 'Échec');
    } catch(e) {
        showToast('error', 'Erreur réseau');
    }
}

// ── Rafraîchir position + adresse ───────────────────────────────────────────
async function refreshPos(vehId) {
    showToast('info', '<span class="spinner"></span> Localisation en cours…');
    await fetchPositions(true);
    const d = liveData[vehId];
    if (d?.lat) {
        map.setView([d.lat, d.lng], 16, { animate:true });
        if (markers[vehId]) markers[vehId].openPopup();
        await loadAddress(vehId);
        showToast('success', 'Position mise à jour');
    } else {
        showToast('warning', 'Véhicule hors ligne');
    }
}

// ── Tracer la route ────────────────────────────────────────────────────────────
let routeLayer = null;
async function showRoute(vehId) {
    showToast('info', '<span class="spinner"></span> Chargement tracé…');
    if (routeLayer) { map.removeLayer(routeLayer); routeLayer = null; }
    try {
        const today = new Date().toISOString().slice(0,10);
        const r = await fetch(`${API}?action=route_history&vehicule_id=${vehId}&from=${today}+00:00:00&to=${today}+23:59:59&t=${Date.now()}`);
        const pts = await r.json();
        if (pts.length > 1) {
            routeLayer = L.polyline(pts.map(p => [p.lat, p.lng]), {
                color:'#14b8a6', weight:4, opacity:.8, dashArray:'8,4'
            }).addTo(map);
            map.fitBounds(routeLayer.getBounds(), { padding:[30,30] });
            showToast('success', pts.length + ' points de tracé chargés');
        } else {
            showToast('warning', 'Pas assez de points pour tracer la route');
        }
    } catch(e) {
        showToast('error', 'Impossible de charger le tracé');
    }
}

// ── Copier coordonnées ────────────────────────────────────────────────────────
function copyCoords(vehId) {
    const d = liveData[vehId];
    if (!d?.lat) { showToast('warning', 'Position non disponible'); return; }
    const txt = `${d.lat.toFixed(6)}, ${d.lng.toFixed(6)}`;
    navigator.clipboard?.writeText(txt).then(() => showToast('success', 'Coordonnées copiées: ' + txt))
        .catch(() => showToast('info', txt));
}

// ── Ouvrir dans Google Maps ────────────────────────────────────────────────────
function openInMaps(vehId) {
    const d = liveData[vehId];
    if (!d?.lat) { showToast('warning', 'Position non disponible'); return; }
    window.open(`https://maps.google.com/?q=${d.lat},${d.lng}`, '_blank');
}

// ── Fetch positions ───────────────────────────────────────────────────────────
let countdownVal = 15;
let isUpdating   = false;

async function fetchPositions(manual = false) {
    if (isUpdating) return;
    isUpdating = true;
    const icon = document.getElementById('sync-icon');
    icon.style.animation = 'spin2 .6s linear infinite';
    try {
        const r    = await fetch(API + '?action=positions&t=' + Date.now());
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const data = await r.json();
        updateMap(data);
        const now = new Date();
        document.getElementById('last-update').textContent = 'MàJ ' + now.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
        if (manual) countdownVal = 15;
    } catch(e) {
        console.warn('GPS fetch:', e);
    } finally {
        isUpdating = false;
        icon.style.animation = '';
    }
}

function updateMap(data) {
    let online = 0, moving = 0;
    data.forEach(pos => {
        const veh      = VEHICULES.find(v => v.id == pos.id);
        if (!veh) return;
        const isOnline = !!(pos.lat && pos.lng);
        const speed    = Math.round(pos.vitesse || 0);
        const isMoving = isOnline && speed > 2;
        if (isOnline) online++;
        if (isMoving) moving++;

        // Store live data
        liveData[pos.id] = pos;

        // Sidebar
        const item = document.getElementById('veh-item-' + pos.id);
        const dot  = document.getElementById('dot-'      + pos.id);
        const spd  = document.getElementById('spd-'      + pos.id);
        if (item) {
            item.classList.toggle('offline', !isOnline);
            item.classList.toggle('online',  isOnline && !isMoving);
        }
        if (dot)  dot.className = 'veh-dot ' + (!isOnline ? 'offline' : isMoving ? 'moving' : 'online');
        if (spd) {
            spd.textContent = isOnline ? speed + ' km/h' : '--';
            spd.className   = 'veh-speed' + (isMoving ? ' moving' : '');
        }

        if (!isOnline) {
            if (markers[pos.id]) { map.removeLayer(markers[pos.id]); delete markers[pos.id]; }
            return;
        }

        const icon  = makeMarkerIcon(veh, speed, isOnline);
        const popup = makePopupHtml(veh, pos);

        if (markers[pos.id]) {
            const curr = markers[pos.id].getLatLng();
            if (Math.abs(curr.lat - pos.lat) > 0.00001 || Math.abs(curr.lng - pos.lng) > 0.00001) {
                markers[pos.id].setLatLng([pos.lat, pos.lng]);
                if (!trails[pos.id]) trails[pos.id] = [];
                trails[pos.id].push([pos.lat, pos.lng]);
                if (trails[pos.id].length > 40) trails[pos.id].shift();
                if (markers['trail_' + pos.id]) map.removeLayer(markers['trail_' + pos.id]);
                if (trails[pos.id].length > 1) {
                    markers['trail_' + pos.id] = L.polyline(trails[pos.id], {
                        color: isMoving ? '#14b8a6' : '#22c55e', weight:3, opacity:.45, dashArray:'5,4'
                    }).addTo(map);
                }
            }
            markers[pos.id].setIcon(icon);
            if (!markers[pos.id].isPopupOpen()) {
                markers[pos.id].getPopup().setContent(popup);
            }
        } else {
            markers[pos.id] = L.marker([pos.lat, pos.lng], { icon })
                .addTo(map)
                .bindPopup(popup, { maxWidth:320, minWidth:280 })
                .on('popupopen', () => {
                    setTimeout(() => loadAddress(pos.id), 200);
                });
        }
        markerData[pos.id] = { lat: pos.lat, lng: pos.lng };
    });

    document.getElementById('kpi-online').textContent  = online;
    document.getElementById('kpi-moving').textContent  = moving;
    document.getElementById('badge-online').textContent = online + ' en ligne';
    document.getElementById('badge-online').className  = 'panel-badge ' + (online > 0 ? 'success' : 'info');

    if (FOCUS_ID && markerData[FOCUS_ID] && !window._focused) {
        window._focused = true;
        map.setView([markerData[FOCUS_ID].lat, markerData[FOCUS_ID].lng], 16);
        if (markers[FOCUS_ID]) {
            markers[FOCUS_ID].openPopup();
            setTimeout(() => loadAddress(FOCUS_ID), 300);
        }
    }
}

// ── Focus véhicule ────────────────────────────────────────────────────────────
function focusVehicule(id) {
    document.querySelectorAll('.veh-item').forEach(el => el.classList.remove('active'));
    document.getElementById('veh-item-' + id)?.classList.add('active');

    if (markerData[id]) {
        // Véhicule EN LIGNE → centrer + popup normal
        map.setView([markerData[id].lat, markerData[id].lng], 16, { animate:true });
        if (markers[id]) {
            markers[id].openPopup();
            setTimeout(() => loadAddress(id), 300);
        }
        if (window.innerWidth <= 768) document.getElementById('gps-panel').classList.add('collapsed');
    } else {
        // Véhicule HORS LIGNE → panneau dédié
        showOfflinePanel(id);
    }
}

function showOfflinePanel(id) {
    const veh  = VEHICULES.find(v => v.id == id);
    if (!veh) return;
    const last = liveData[id]?.horodatage;
    const lastStr = last ? 'Dernier signal : ' + new Date(last).toLocaleString('fr-FR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}) : 'Aucun signal enregistré';

    document.getElementById('op-title').textContent = veh.nom;
    document.getElementById('op-immat').textContent  = veh.immatriculation + (veh.marque ? ' · ' + veh.marque : '');
    document.getElementById('op-last').textContent   = lastStr;

    document.getElementById('op-actions').innerHTML = `
        <a href="${BASE}app/vehicules/detail.php?id=${id}" style="padding:10px 8px;border-radius:10px;background:rgba(59,130,246,.15);color:#14b8a6;text-decoration:none;font-size:.72rem;font-weight:700;display:flex;flex-direction:column;align-items:center;gap:4px;text-align:center;">
            <i class="fas fa-file-alt" style="font-size:1.1rem"></i>Fiche véhicule
        </a>
        <button onclick="closeOfflinePanel();loadTripsOffline(${id})" style="padding:10px 8px;border-radius:10px;background:rgba(139,92,246,.15);color:#a78bfa;border:none;cursor:pointer;font-size:.72rem;font-weight:700;display:flex;flex-direction:column;align-items:center;gap:4px;width:100%">
            <i class="fas fa-route" style="font-size:1.1rem"></i>Trajets du jour
        </button>
        <button onclick="closeOfflinePanel();loadEventsOffline(${id})" style="padding:10px 8px;border-radius:10px;background:rgba(245,158,11,.15);color:#f59e0b;border:none;cursor:pointer;font-size:.72rem;font-weight:700;display:flex;flex-direction:column;align-items:center;gap:4px;width:100%">
            <i class="fas fa-list" style="font-size:1.1rem"></i>Événements
        </button>
        <a href="${BASE}app/gps/historique.php?vehicule=${id}" style="padding:10px 8px;border-radius:10px;background:rgba(34,197,94,.15);color:#22c55e;text-decoration:none;font-size:.72rem;font-weight:700;display:flex;flex-direction:column;align-items:center;gap:4px;text-align:center;">
            <i class="fas fa-history" style="font-size:1.1rem"></i>Historique GPS
        </a>
    `;

    document.getElementById('offline-panel').style.display   = 'block';
    document.getElementById('offline-overlay').style.display = 'block';
}

function closeOfflinePanel() {
    document.getElementById('offline-panel').style.display   = 'none';
    document.getElementById('offline-overlay').style.display = 'none';
}

// Trajets et événements pour véhicule hors ligne (depuis l'historique)
async function loadTripsOffline(vehId) {
    showToast('info', '<span class="spinner"></span> Chargement des trajets…');
    try {
        const today = new Date().toISOString().slice(0,10);
        const r     = await fetch(`${API}?action=trips&vehicule_id=${vehId}&from=${today}&to=${today}&t=${Date.now()}`);
        const trips = await r.json();
        if (!trips.length) { showToast('warning', 'Aucun trajet enregistré aujourd\'hui'); return; }
        let html = `<div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(13,20,38,.98);border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:0;z-index:9999;min-width:300px;max-width:380px;box-shadow:0 16px 48px rgba(0,0,0,.7)">
            <div style="padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:space-between">
                <span style="font-weight:800;color:#fff;font-size:.9rem"><i class="fas fa-route" style="color:#14b8a6;margin-right:6px"></i>Trajets du jour</span>
                <button onclick="this.closest('div[style*=fixed]').remove();document.getElementById('offline-overlay2')?.remove()" style="background:rgba(255,255,255,.08);border:none;color:#94a3b8;width:28px;height:28px;border-radius:6px;cursor:pointer">✕</button>
            </div>
            <div style="padding:12px 16px;max-height:300px;overflow-y:auto">`;
        trips.forEach(t => {
            const dist = t.distance ? (t.distance/1000).toFixed(1)+' km' : '--';
            const dur  = t.duration ? Math.round(t.duration/60000)+' min' : '--';
            const from = t.startTime ? new Date(t.startTime).toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'}) : '--';
            const to   = t.endTime   ? new Date(t.endTime).toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'})   : 'En cours';
            html += `<div style="padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05)">
                <div style="font-size:.75rem;font-weight:600;color:#f1f5f9"><i class="fas fa-route" style="color:#14b8a6;margin-right:4px"></i>${from} → ${to}</div>
                <div style="font-size:.65rem;color:rgba(255,255,255,.4);margin-top:3px;display:flex;gap:10px"><span><i class="fas fa-road"></i> ${dist}</span><span><i class="fas fa-clock"></i> ${dur}</span></div>
            </div>`;
        });
        html += `</div></div><div id="offline-overlay2" onclick="this.previousSibling.remove();this.remove()" style="position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:9998"></div>`;
        document.body.insertAdjacentHTML('beforeend', html);
        showToast('success', trips.length + ' trajet(s) trouvé(s)');
    } catch(e) { showToast('error', 'Erreur de chargement'); }
}

async function loadEventsOffline(vehId) {
    showToast('info', '<span class="spinner"></span> Chargement des événements…');
    try {
        const today = new Date().toISOString().slice(0,10);
        const r     = await fetch(`${API}?action=events&vehicule_id=${vehId}&from=${today}&to=${today}&t=${Date.now()}`);
        const evts  = await r.json();
        if (!Array.isArray(evts) || !evts.length) { showToast('warning', 'Aucun événement aujourd\'hui'); return; }
        const typeMap  = {deviceOnline:'En ligne',deviceOffline:'Hors ligne',geofenceEnter:'Entrée zone',geofenceExit:'Sortie zone',alarm:'Alarme',ignitionOn:'Démarrage',ignitionOff:'Arrêt moteur',overspeed:'Vitesse excessive'};
        const colorMap = {alarm:'#ef4444',overspeed:'#f59e0b',geofenceExit:'#f59e0b',geofenceEnter:'#22c55e',ignitionOn:'#22c55e',ignitionOff:'#64748b',deviceOffline:'#ef4444'};
        let html = `<div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(13,20,38,.98);border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:0;z-index:9999;min-width:300px;max-width:380px;box-shadow:0 16px 48px rgba(0,0,0,.7)">
            <div style="padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:space-between">
                <span style="font-weight:800;color:#fff;font-size:.9rem"><i class="fas fa-list" style="color:#f59e0b;margin-right:6px"></i>Événements du jour</span>
                <button onclick="this.closest('div[style*=fixed]').remove();document.getElementById('offline-overlay2')?.remove()" style="background:rgba(255,255,255,.08);border:none;color:#94a3b8;width:28px;height:28px;border-radius:6px;cursor:pointer">✕</button>
            </div>
            <div style="padding:12px 16px;max-height:300px;overflow-y:auto">`;
        evts.slice(0,20).forEach(e => {
            const label = typeMap[e.type] || e.type;
            const color = colorMap[e.type] || '#14b8a6';
            const time  = e.eventTime ? new Date(e.eventTime).toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'}) : '--';
            html += `<div style="display:flex;gap:8px;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.05)">
                <div style="width:7px;height:7px;border-radius:50%;background:${color};flex-shrink:0;margin-top:4px"></div>
                <div><div style="font-size:.73rem;font-weight:700;color:#f1f5f9">${label}</div><div style="font-size:.64rem;color:rgba(255,255,255,.4)">${time}</div></div>
            </div>`;
        });
        html += `</div></div><div id="offline-overlay2" onclick="this.previousSibling.remove();this.remove()" style="position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:9998"></div>`;
        document.body.insertAdjacentHTML('beforeend', html);
        showToast('success', evts.length + ' événement(s) trouvé(s)');
    } catch(e) { showToast('error', 'Erreur de chargement'); }
}

function centerAll() {
    const pts = Object.values(markerData);
    if (!pts.length) return;
    if (pts.length === 1) map.setView([pts[0].lat, pts[0].lng], 14, { animate:true });
    else map.fitBounds(L.latLngBounds(pts.map(p => [p.lat, p.lng])), { padding:[40,40], animate:true });
}

function togglePanel() { document.getElementById('gps-panel').classList.toggle('collapsed'); }

// ── Recherche ────────────────────────────────────────────────────────────────
document.getElementById('search-veh').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.veh-item').forEach(el => {
        el.style.display = el.dataset.name.includes(q) ? '' : 'none';
    });
});

// ── Toast ─────────────────────────────────────────────────────────────────────
let toastTimer = null;
function showToast(type, msg) {
    const toast = document.getElementById('gps-toast');
    const icons = { success:'fas fa-check-circle', error:'fas fa-times-circle', info:'fas fa-info-circle', warning:'fas fa-exclamation-triangle' };
    toast.className = 'gps-toast ' + type;
    toast.querySelector('.toast-icon').className = 'toast-icon ' + (icons[type] || icons.info);
    document.getElementById('toast-msg').innerHTML = msg;
    toast.classList.add('show');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 3500);
}

// ── Countdown ─────────────────────────────────────────────────────────────────
fetchPositions();
setInterval(() => {
    countdownVal--;
    const el = document.getElementById('countdown');
    if (el) el.textContent = countdownVal;
    if (countdownVal <= 0) { countdownVal = 15; fetchPositions(); }
}, 1000);
</script>
</body>
</html>
<?php /* Pas de footer standard — page plein écran */ ?>
