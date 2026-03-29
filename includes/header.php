<?php
/**
 * FlotteCar - Header commun
 * Variables attendues depuis la page incluante:
 *   $pageTitle  (string) — Titre de la page
 *   $activePage (string) — Identifiant pour la nav active
 *   $db         (PDO)    — Connexion disponible depuis toutes les pages /app/
 */

$pageTitle  = $pageTitle  ?? 'FlotteCar';
$activePage = $activePage ?? '';

$userName     = getUserName();
$userEmail    = $_SESSION['user_email']  ?? '';
$userInitials = strtoupper(
    substr($_SESSION['user_prenom'] ?? 'U', 0, 1) .
    substr($_SESSION['user_nom']    ?? '',  0, 1)
);
$tenantNom  = getTenantNom();
$tenantPlan = getTenantPlan();

if (!function_exists('navActive')) {
    function navActive(string $page, string $current): string {
        return $page === $current ? ' active' : '';
    }
}

// -------------------------------------------------------
// Données dynamiques badges (alertes, impayés)
// -------------------------------------------------------
$nbAlertesGps  = 0;
$nbAlerteMaint = 0;
$nbImpayesTaxi = 0;
$hasTaxiVehs   = false;
$typeUsage     = getTenantTypeUsage();
$totalNotifs   = 0;

if (!isSuperAdmin() && isset($db)) {
    $tid = getTenantId();

    // Événements GPS non lus
    try {
        $s = $db->prepare("SELECT COUNT(*) FROM evenements_gps WHERE tenant_id = ? AND lu = 0");
        $s->execute([$tid]);
        $nbAlertesGps = (int)$s->fetchColumn();
    } catch (Throwable $e) {}

    // Maintenances urgentes (date dépassée ou km seuil atteint)
    try {
        $s = $db->prepare(
            "SELECT COUNT(*) FROM maintenances m
             JOIN vehicules v ON v.id = m.vehicule_id
             WHERE m.tenant_id = ? AND m.statut = 'planifie'
               AND (m.date_prevue <= CURDATE()
                    OR (m.km_prevu IS NOT NULL AND v.kilometrage_actuel >= m.km_prevu))"
        );
        $s->execute([$tid]);
        $nbAlerteMaint = (int)$s->fetchColumn();
    } catch (Throwable $e) {}

    // Taxis — véhicules + impayés
    try {
        $s = $db->prepare("SELECT COUNT(*) FROM vehicules WHERE tenant_id = ? AND type_vehicule = 'taxi'");
        $s->execute([$tid]);
        $hasTaxiVehs = (int)$s->fetchColumn() > 0;
        if ($hasTaxiVehs) {
            $s2 = $db->prepare("SELECT COUNT(*) FROM paiements_taxi WHERE tenant_id = ? AND statut_jour = 'non_paye'");
            $s2->execute([$tid]);
            $nbImpayesTaxi = (int)$s2->fetchColumn();
        }
    } catch (Throwable $e) {}

    $totalNotifs = $nbAlertesGps + $nbAlerteMaint + $nbImpayesTaxi;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($pageTitle) ?> | FlotteCar</title>
    <meta name="description" content="FlotteCar - Plateforme SaaS de gestion de flotte et tracking GPS">
    <meta name="robots" content="noindex, nofollow">

    <!-- PWA -->
    <link rel="manifest" href="<?= BASE_URL ?>manifest.php">
    <meta name="theme-color" content="#0d9488">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <meta name="apple-mobile-web-app-title" content="FlotteCar">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>assets/img/icon-192.png">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/img/icon-192.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome local (fallback CDN) -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/fa/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/app.css">

    <style>
    /* ── Sidebar refonte v2 ── */
    .sidebar {
        background: #0f172a !important;
    }
    .sidebar-logo {
        padding: 20px 20px 16px;
        border-bottom: 1px solid rgba(255,255,255,0.06);
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    .sidebar-logo-name {
        font-size: 1.2rem;
        font-weight: 800;
        color: #fff;
        letter-spacing: -0.02em;
    }
    .sidebar-logo-sub {
        font-size: .72rem;
        color: rgba(255,255,255,0.5);
        font-weight: 400;
    }
    .sidebar-logo-icon { display: none !important; }
    .sidebar-logo-text { display: flex; flex-direction: column; }

    .sidebar-nav .nav-section-title {
        font-size: .6rem !important;
        text-transform: uppercase;
        letter-spacing: .1em;
        color: rgba(255,255,255,0.25) !important;
        padding: 20px 20px 6px !important;
        font-weight: 600;
        margin: 0;
    }
    .sidebar-nav .nav-link {
        padding: 10px 20px !important;
        font-size: .82rem !important;
        color: rgba(255,255,255,0.65) !important;
        transition: all 150ms ease !important;
        border-left: 3px solid transparent;
        display: flex;
        align-items: center;
        text-decoration: none;
        background: none !important;
        border-radius: 0 !important;
    }
    .sidebar-nav .nav-link:hover {
        background: rgba(255,255,255,0.06) !important;
        color: #fff !important;
    }
    .sidebar-nav .nav-link.active {
        border-left: 3px solid #0d9488 !important;
        color: #fff !important;
        font-weight: 600;
        background: rgba(13,148,136,0.08) !important;
    }
    .sidebar-nav .nav-link i {
        opacity: 0.5;
        width: 20px;
        text-align: center;
        margin-right: 10px;
        font-size: .8rem;
    }
    .sidebar-nav .nav-link.active i {
        opacity: 0.8;
    }

    /* Badges sidebar */
    .sidebar-badge {
        margin-left: auto;
        background: #0d9488;
        color: #fff;
        font-size: .65rem;
        padding: 1px 6px;
        border-radius: 10px;
        font-weight: 600;
        line-height: 1.4;
    }

    /* Plan section */
    .sidebar-plan {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 12px 20px;
        border-top: 1px solid rgba(255,255,255,0.06);
    }
    .sidebar-plan-label {
        font-size: .68rem;
        color: rgba(255,255,255,0.4);
    }
    .sidebar-plan-badge {
        font-size: .68rem;
        background: rgba(13,148,136,0.15);
        color: #0d9488;
        padding: 2px 8px;
        border-radius: 6px;
        font-weight: 600;
    }
    .sidebar-plan-icon { display: none !important; }
    .sidebar-plan-info { display: flex; flex-direction: column; }
    .sidebar-plan-name { display: none; }

    /* Logout */
    .sidebar-logout {
        color: rgba(255,255,255,0.4) !important;
        font-size: .78rem !important;
        padding: 10px 20px !important;
        transition: color 150ms ease;
    }
    .sidebar-logout:hover {
        color: #fff !important;
        background: none !important;
    }
    .sidebar-copyright {
        font-size: .65rem;
        color: rgba(255,255,255,0.2);
        padding: 6px 20px 16px;
    }

    /* ── Topbar refonte ── */
    .topbar {
        background: #fff !important;
        border-bottom: 1px solid #e2e8f0 !important;
        height: 56px !important;
        min-height: 56px !important;
        display: flex;
        align-items: center;
        padding: 0 16px 0 8px;
        box-shadow: none !important;
    }
    .topbar-toggle {
        background: none;
        border: none;
        padding: 8px 10px;
        cursor: pointer;
        font-size: 1.1rem;
        color: #64748b;
        border-radius: 8px;
        transition: background 150ms;
    }
    .topbar-toggle:hover { background: #f1f5f9; }
    .topbar-title {
        font-size: .95rem !important;
        font-weight: 600 !important;
        color: #0f172a !important;
        margin-left: 8px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .topbar-actions {
        margin-left: auto;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    /* Notification bell topbar */
    .topbar-notif-btn {
        position: relative;
        background: none;
        border: none;
        padding: 8px;
        cursor: pointer;
        border-radius: 8px;
        transition: background 150ms;
        color: #64748b;
        font-size: 1rem;
    }
    .topbar-notif-btn:hover { background: #f1f5f9; }
    .topbar-notif-dot {
        position: absolute;
        top: 6px;
        right: 6px;
        width: 8px;
        height: 8px;
        background: #0d9488;
        border-radius: 50%;
        border: 2px solid #fff;
    }

    /* User avatar circle */
    .topbar-avatar-circle {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: #0d9488;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .72rem;
        font-weight: 700;
        cursor: pointer;
        transition: opacity 150ms;
        flex-shrink: 0;
    }
    .topbar-avatar-circle:hover { opacity: 0.85; }

    /* Quick menu */
    .quick-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 16px;
        color: var(--text);
        text-decoration: none;
        font-size: .85rem;
        transition: background 150ms;
    }
    .quick-item:hover { background: #f8fafc; }
    .quick-item i { width: 16px; text-align: center; font-size: .8rem; }

    /* Notification panel */
    #notifPanel {
        border-radius: 12px !important;
    }

    /* Mobile overlay */
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 998;
    }
    .sidebar-overlay.active { display: block; }

    /* Hide user dropdown elements we don't need */
    .topbar-user-name,
    .topbar-user-chevron,
    .user-dropdown { display: none; }
    </style>
</head>
<script>
window.BASE_URL     = '<?= BASE_URL ?>';
window.NOTIF_ASKED  = <?= json_encode(!empty($_SESSION['notif_perm_asked'])) ?>;
window.IS_LOGGED_IN = true;
</script>
<body>

<div class="sidebar-overlay" id="sidebar-overlay"></div>

<!-- =================================================================
     SIDEBAR
     ================================================================= -->
<aside class="sidebar" id="sidebar">

    <div class="sidebar-logo">
        <div class="sidebar-logo-text">
            <span class="sidebar-logo-name">FlotteCar</span>
            <span class="sidebar-logo-sub"><?= isSuperAdmin() ? 'Super Admin' : htmlspecialchars($tenantNom) ?></span>
        </div>
    </div>

    <nav class="sidebar-nav">

    <?php if (isSuperAdmin()): ?>
    <!-- SUPER ADMIN -->
    <div class="nav-section">
        <div class="nav-section-title">Administration</div>
        <a href="<?= BASE_URL ?>admin/dashboard.php"    class="nav-link<?= navActive('admin_dashboard',   $activePage) ?>"><i class="fas fa-gauge-high"></i><span class="nav-link-text">Tableau de bord</span></a>
        <a href="<?= BASE_URL ?>admin/tenants.php"      class="nav-link<?= navActive('admin_tenants',     $activePage) ?>"><i class="fas fa-building"></i><span class="nav-link-text">Tenants</span></a>
        <a href="<?= BASE_URL ?>admin/abonnements.php"  class="nav-link<?= navActive('admin_abonnements', $activePage) ?>"><i class="fas fa-credit-card"></i><span class="nav-link-text">Abonnements</span></a>
        <a href="<?= BASE_URL ?>admin/statistiques.php" class="nav-link<?= navActive('admin_stats',       $activePage) ?>"><i class="fas fa-chart-line"></i><span class="nav-link-text">Statistiques</span></a>
        <a href="<?= BASE_URL ?>admin/utilisateurs.php" class="nav-link<?= navActive('admin_users',       $activePage) ?>"><i class="fas fa-users-gear"></i><span class="nav-link-text">Utilisateurs</span></a>
    </div>
    <div class="nav-section">
        <div class="nav-section-title">Systeme</div>
        <a href="<?= BASE_URL ?>admin/logs.php"         class="nav-link<?= navActive('admin_logs',        $activePage) ?>"><i class="fas fa-file-lines"></i><span class="nav-link-text">Journaux</span></a>
        <a href="<?= BASE_URL ?>admin/parametres.php"   class="nav-link<?= navActive('admin_params',      $activePage) ?>"><i class="fas fa-sliders"></i><span class="nav-link-text">Parametres</span></a>
    </div>

    <?php else: ?>
    <!-- TENANT -->

    <!-- Dashboard -->
    <div class="nav-section">
        <a href="<?= BASE_URL ?>app/dashboard.php" class="nav-link<?= navActive('dashboard', $activePage) ?>">
            <i class="fas fa-gauge-high"></i><span class="nav-link-text">Tableau de bord</span>
        </a>
    </div>

    <!-- PARC VÉHICULES — toujours -->
    <div class="nav-section">
        <div class="nav-section-title">Vehicules</div>

        <a href="<?= BASE_URL ?>app/vehicules/liste.php" class="nav-link<?= navActive('vehicules', $activePage) ?>">
            <i class="fas fa-car"></i><span class="nav-link-text">Vehicules</span>
        </a>

        <a href="<?= BASE_URL ?>app/chauffeurs/liste.php" class="nav-link<?= navActive('chauffeurs', $activePage) ?>">
            <i class="fas fa-id-card"></i><span class="nav-link-text">Chauffeurs</span>
        </a>

        <a href="<?= BASE_URL ?>app/maintenances/index.php" class="nav-link<?= navActive('maintenances', $activePage) ?>">
            <i class="fas fa-wrench"></i>
            <span class="nav-link-text">Maintenances</span>
            <?php if ($nbAlerteMaint > 0): ?>
                <span class="sidebar-badge"><?= $nbAlerteMaint ?></span>
            <?php endif; ?>
        </a>
    </div>

    <!-- LOCATION -->
    <?php if (hasLocationModule()): ?>
    <div class="nav-section">
        <div class="nav-section-title">Location</div>

        <a href="<?= BASE_URL ?>app/clients/liste.php" class="nav-link<?= navActive('clients', $activePage) ?>">
            <i class="fas fa-users"></i><span class="nav-link-text">Clients</span>
        </a>

        <a href="<?= BASE_URL ?>app/locations/liste.php" class="nav-link<?= navActive('locations', $activePage) ?>">
            <i class="fas fa-calendar-check"></i><span class="nav-link-text">Locations</span>
        </a>

        <a href="<?= BASE_URL ?>app/reservations/calendrier.php" class="nav-link<?= navActive('reservations', $activePage) ?>">
            <i class="fas fa-calendar-alt"></i><span class="nav-link-text">Reservations</span>
        </a>

        <a href="<?= BASE_URL ?>app/commerciaux/index.php" class="nav-link<?= navActive('commerciaux', $activePage) ?>">
            <i class="fas fa-user-tie"></i><span class="nav-link-text">Commerciaux</span>
        </a>
    </div>
    <?php endif; ?>

    <!-- VTC / TAXI — si véhicules taxi existent -->
    <?php if ($hasTaxiVehs): ?>
    <div class="nav-section">
        <div class="nav-section-title">VTC / Taxi</div>

        <a href="<?= BASE_URL ?>app/taximetres/liste.php" class="nav-link<?= navActive('taximetres', $activePage) ?>">
            <i class="fas fa-taxi"></i>
            <span class="nav-link-text">Taximantre</span>
            <?php if ($nbImpayesTaxi > 0): ?>
                <span class="sidebar-badge"><?= $nbImpayesTaxi ?></span>
            <?php endif; ?>
        </a>

        <a href="<?= BASE_URL ?>app/taximetres/paiements.php" class="nav-link<?= navActive('taxi_paiements', $activePage) ?>">
            <i class="fas fa-hand-holding-dollar"></i><span class="nav-link-text">Saisie du jour</span>
        </a>
    </div>
    <?php endif; ?>

    <!-- GPS -->
    <?php if (hasGpsModule()): ?>
    <div class="nav-section">
        <div class="nav-section-title">GPS</div>

        <a href="<?= BASE_URL ?>app/gps/carte.php" class="nav-link<?= navActive('gps_carte', $activePage) ?>">
            <i class="fas fa-map-location-dot"></i><span class="nav-link-text">Carte</span>
        </a>

        <a href="<?= BASE_URL ?>app/gps/historique.php" class="nav-link<?= navActive('gps_historique', $activePage) ?>">
            <i class="fas fa-route"></i><span class="nav-link-text">Historique</span>
        </a>

        <a href="<?= BASE_URL ?>app/gps/alertes.php" class="nav-link<?= navActive('gps_alertes', $activePage) ?>">
            <i class="fas fa-bell"></i>
            <span class="nav-link-text">Alertes</span>
            <?php if ($nbAlertesGps > 0): ?>
                <span class="sidebar-badge"><?= $nbAlertesGps ?></span>
            <?php endif; ?>
        </a>

        <a href="<?= BASE_URL ?>app/gps/appareils.php" class="nav-link<?= navActive('gps_appareils', $activePage) ?>">
            <i class="fas fa-satellite-dish"></i><span class="nav-link-text">Boitiers GPS</span>
        </a>

        <a href="<?= BASE_URL ?>app/gps/regles.php" class="nav-link<?= navActive('gps_regles', $activePage) ?>">
            <i class="fas fa-sliders-h"></i><span class="nav-link-text">Regles GPS</span>
        </a>

        <a href="<?= BASE_URL ?>app/gps/zones.php" class="nav-link<?= navActive('gps_zones', $activePage) ?>">
            <i class="fas fa-map-marked-alt"></i><span class="nav-link-text">Geofencing</span>
        </a>
    </div>
    <?php endif; ?>

    <!-- FINANCES -->
    <div class="nav-section">
        <div class="nav-section-title">Finances</div>
        <a href="<?= BASE_URL ?>app/finances/charges.php" class="nav-link<?= navActive('charges', $activePage) ?>">
            <i class="fas fa-file-invoice-dollar"></i><span class="nav-link-text">Charges</span>
        </a>
        <a href="<?= BASE_URL ?>app/finances/comptabilite.php" class="nav-link<?= navActive('comptabilite', $activePage) ?>">
            <i class="fas fa-calculator"></i><span class="nav-link-text">Comptabilite</span>
        </a>
        <a href="<?= BASE_URL ?>app/finances/rentabilite.php" class="nav-link<?= navActive('rentabilite', $activePage) ?>">
            <i class="fas fa-chart-pie"></i><span class="nav-link-text">Rentabilite</span>
        </a>
    </div>

    <!-- RAPPORTS -->
    <div class="nav-section">
        <div class="nav-section-title">Rapports</div>
        <a href="<?= BASE_URL ?>app/rapports/journalier.php" class="nav-link<?= navActive('rapport_jour', $activePage) ?>">
            <i class="fas fa-calendar-day"></i><span class="nav-link-text">Journalier</span>
        </a>
        <a href="<?= BASE_URL ?>app/rapports/mensuel.php" class="nav-link<?= navActive('rapport_mois', $activePage) ?>">
            <i class="fas fa-calendar-alt"></i><span class="nav-link-text">Mensuel</span>
        </a>
    </div>

    <!-- PARAMÈTRES -->
    <?php if (isTenantAdmin()): ?>
    <div class="nav-section">
        <div class="nav-section-title">Administration</div>
        <a href="<?= BASE_URL ?>app/parametres/index.php" class="nav-link<?= navActive('parametres', $activePage) ?>">
            <i class="fas fa-gear"></i><span class="nav-link-text">Parametres</span>
        </a>
        <a href="<?= BASE_URL ?>app/parametres/utilisateurs.php" class="nav-link<?= navActive('utilisateurs', $activePage) ?>">
            <i class="fas fa-users-gear"></i><span class="nav-link-text">Utilisateurs</span>
        </a>
    </div>
    <?php endif; ?>

    <?php endif; /* fin isSuperAdmin */ ?>

    </nav>

    <div class="sidebar-footer">
        <?php if (!isSuperAdmin()): ?>
        <div class="sidebar-plan">
            <div class="sidebar-plan-info">
                <div class="sidebar-plan-label">Plan actif</div>
            </div>
            <span class="sidebar-plan-badge"><?= $tenantPlan === 'annuel' ? 'Annuel' : 'Mensuel' ?></span>
        </div>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>auth/logout.php" class="sidebar-logout"
           data-confirm="Voulez-vous vous deconnecter ?">
            <i class="fas fa-right-from-bracket"></i>
            <span class="nav-link-text">Deconnexion</span>
        </a>
        <div class="sidebar-copyright">&copy; <?= date('Y') ?> FlotteCar</div>
    </div>

</aside>

<!-- =================================================================
     MAIN WRAPPER
     ================================================================= -->
<div class="main-wrapper" id="main-wrapper">

    <header class="topbar">
        <button class="topbar-toggle" id="sidebar-toggle" aria-label="Menu">
            <i class="fas fa-bars"></i>
        </button>

        <h1 class="topbar-title"><?= htmlspecialchars($pageTitle) ?></h1>

        <div class="topbar-actions">

            <?php if (!isSuperAdmin()): ?>
            <!-- Actions rapides -->
            <div style="position:relative" id="quick-wrap">
                <button class="topbar-notif-btn" title="Actions rapides" id="quick-btn"
                        onclick="document.getElementById('quick-menu').style.display=document.getElementById('quick-menu').style.display==='none'?'block':'none'">
                    <i class="fas fa-plus"></i>
                </button>
                <div id="quick-menu" style="display:none;position:absolute;right:0;top:calc(100% + 6px);background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);min-width:210px;z-index:9999;overflow:hidden">
                    <div style="padding:8px 14px 6px;font-size:.65rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.08em;border-bottom:1px solid #f1f5f9">Action rapide</div>
                    <a href="<?= BASE_URL ?>app/vehicules/ajouter.php" class="quick-item">
                        <i class="fas fa-car" style="color:#0d9488"></i> Nouveau vehicule
                    </a>
                    <a href="<?= BASE_URL ?>app/chauffeurs/ajouter.php" class="quick-item">
                        <i class="fas fa-id-card" style="color:#10b981"></i> Nouveau chauffeur
                    </a>
                    <?php if (hasLocationModule()): ?>
                    <a href="<?= BASE_URL ?>app/clients/ajouter.php" class="quick-item">
                        <i class="fas fa-user-plus" style="color:#8b5cf6"></i> Nouveau client
                    </a>
                    <a href="<?= BASE_URL ?>app/locations/nouvelle.php" class="quick-item">
                        <i class="fas fa-file-contract" style="color:#f59e0b"></i> Nouvelle location
                    </a>
                    <a href="<?= BASE_URL ?>app/reservations/calendrier.php" class="quick-item">
                        <i class="fas fa-calendar-plus" style="color:#06b6d4"></i> Reservation
                    </a>
                    <?php endif; ?>
                    <?php if ($hasTaxiVehs): ?>
                    <a href="<?= BASE_URL ?>app/taximetres/paiements.php" class="quick-item">
                        <i class="fas fa-hand-holding-dollar" style="color:#f97316"></i> Paiements taxi
                    </a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>app/maintenances/index.php?action=new" class="quick-item">
                        <i class="fas fa-wrench" style="color:#64748b"></i> Planifier maintenance
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Notifications -->
            <button class="topbar-notif-btn" id="notifBtn" onclick="toggleNotifPanel()">
                <i class="fas fa-bell"></i>
                <?php if ($totalNotifs > 0): ?>
                <span class="topbar-notif-dot"></span>
                <?php endif; ?>
            </button>
            <span id="notifBadge" style="display:none"></span>

            <!-- Panel notifications -->
            <div id="notifPanel" style="display:none;position:fixed;top:56px;right:12px;width:340px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.12);z-index:9999;max-height:480px;overflow:hidden;flex-direction:column">
              <div style="padding:12px 16px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center">
                <span style="font-size:.88rem;font-weight:700;color:#0f172a">Notifications</span>
                <button onclick="markAllRead()" style="background:none;border:none;cursor:pointer;color:#0d9488;font-size:.75rem;font-weight:600;padding:4px 8px;border-radius:6px;font-family:inherit"
                  onmouseover="this.style.background='#f0fdfa'" onmouseout="this.style.background='none'">
                  Tout lire
                </button>
              </div>
              <div id="notifList" style="overflow-y:auto;max-height:420px">
                <div style="padding:32px 16px;text-align:center;color:#94a3b8">
                  <div style="font-size:.85rem;font-weight:500">Chargement...</div>
                </div>
              </div>
            </div>

            <!-- User avatar -->
            <div class="topbar-avatar-circle" id="topbar-user" title="<?= htmlspecialchars($userName) ?>"
                 onclick="window.location.href='<?= BASE_URL ?>app/profil.php'">
                <?= htmlspecialchars($userInitials) ?>
            </div>

        </div>
    </header>

    <main class="main-content">
        <div class="alerts-container"><?= renderFlashes() ?></div>

<script>
// Ferme le menu rapide si on clique ailleurs
document.addEventListener('click', function(e) {
    var wrap = document.getElementById('quick-wrap');
    var menu = document.getElementById('quick-menu');
    if (wrap && menu && !wrap.contains(e.target)) {
        menu.style.display = 'none';
    }
});
</script>
