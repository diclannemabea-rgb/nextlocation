<?php
/**
 * FlotteCar — Vue Chauffeur Mobile
 * Page simplifiée accessible par le chauffeur via lien unique (token).
 * Affiche : véhicule assigné, solde impayé, historique paiements récents.
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';

$db = (new Database())->getConnection();

// Accès via token unique ?token=xxx ou via session tenant
$token = trim($_GET['token'] ?? '');
$chauffeur = null;
$tenantId = null;

if ($token) {
    // Accès par token (lien partagé au chauffeur)
    $stmt = $db->prepare("
        SELECT t.*, v.nom AS veh_nom, v.immatriculation, v.marque, v.modele, v.photo,
               ten.nom_entreprise
        FROM taximetres t
        LEFT JOIN vehicules v ON v.id = t.vehicule_id
        LEFT JOIN tenants ten ON ten.id = t.tenant_id
        WHERE t.token_acces = ? AND t.statut = 'actif'
    ");
    $stmt->execute([$token]);
    $chauffeur = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$chauffeur) {
        http_response_code(404);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Lien invalide</title></head><body style="font-family:system-ui;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f8fafc"><div style="text-align:center"><div style="font-size:3rem;margin-bottom:12px">🔒</div><h2 style="color:#0f172a">Lien invalide ou expiré</h2><p style="color:#64748b">Contactez votre gestionnaire pour obtenir un nouveau lien.</p></div></body></html>';
        exit;
    }
    $tenantId = $chauffeur['tenant_id'];
} else {
    // Accès via session tenant (admin regarde la vue chauffeur)
    require_once BASE_PATH . '/config/auth.php';
    requireTenantAuth();
    $tenantId = getTenantId();
    $chId = (int)($_GET['id'] ?? 0);
    if (!$chId) { redirect(BASE_URL . 'app/chauffeurs/liste.php'); }
    $stmt = $db->prepare("
        SELECT t.*, v.nom AS veh_nom, v.immatriculation, v.marque, v.modele, v.photo,
               ten.nom_entreprise
        FROM taximetres t
        LEFT JOIN vehicules v ON v.id = t.vehicule_id
        LEFT JOIN tenants ten ON ten.id = t.tenant_id
        WHERE t.id = ? AND t.tenant_id = ?
    ");
    $stmt->execute([$chId, $tenantId]);
    $chauffeur = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$chauffeur) { redirect(BASE_URL . 'app/chauffeurs/liste.php'); }
}

$chId = $chauffeur['id'];

// Solde impayé
$soldeStmt = $db->prepare("
    SELECT
        COUNT(CASE WHEN statut_jour='non_paye' THEN 1 END) AS jours_impayes,
        COALESCE(SUM(CASE WHEN statut_jour='non_paye' THEN montant_du ELSE 0 END), 0) AS total_impaye,
        COALESCE(SUM(CASE WHEN statut_jour='paye' THEN montant_paye ELSE 0 END), 0) AS total_paye,
        COUNT(*) AS total_jours
    FROM paiements_taxi
    WHERE taximetre_id = ?
");
$soldeStmt->execute([$chId]);
$solde = $soldeStmt->fetch(PDO::FETCH_ASSOC);

// 15 derniers jours
$histStmt = $db->prepare("
    SELECT date_paiement, statut_jour, montant_du, montant_paye
    FROM paiements_taxi
    WHERE taximetre_id = ?
    ORDER BY date_paiement DESC
    LIMIT 15
");
$histStmt->execute([$chId]);
$historique = $histStmt->fetchAll(PDO::FETCH_ASSOC);

// Mois en cours stats
$moisStmt = $db->prepare("
    SELECT
        COUNT(CASE WHEN statut_jour='paye' THEN 1 END) AS jours_payes,
        COUNT(CASE WHEN statut_jour='non_paye' THEN 1 END) AS jours_impayes,
        COUNT(CASE WHEN statut_jour='jour_off' THEN 1 END) AS jours_off,
        COUNT(CASE WHEN statut_jour IN ('panne','accident') THEN 1 END) AS jours_panne,
        COALESCE(SUM(montant_paye),0) AS total_verse
    FROM paiements_taxi
    WHERE taximetre_id = ?
      AND MONTH(date_paiement) = MONTH(CURDATE())
      AND YEAR(date_paiement) = YEAR(CURDATE())
");
$moisStmt->execute([$chId]);
$mois = $moisStmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
    <title>Mon Espace — <?= htmlspecialchars($chauffeur['prenom'] . ' ' . $chauffeur['nom']) ?></title>
    <link rel="manifest" href="<?= defined('BASE_URL') ? BASE_URL : '/traccargps/' ?>manifest.json">
    <meta name="theme-color" content="#0d9488">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:system-ui,-apple-system,sans-serif; background:#f1f5f9; color:#0f172a; min-height:100vh; }

        .top-bar { background:linear-gradient(135deg,#0d9488,#0f766e); color:#fff; padding:16px 20px; }
        .top-bar .entreprise { font-size:.7rem; opacity:.7; text-transform:uppercase; letter-spacing:.08em; }
        .top-bar .nom { font-size:1.15rem; font-weight:800; margin-top:2px; }
        .top-bar .vehicule { font-size:.78rem; opacity:.85; margin-top:4px; }

        .kpi-bar { display:grid; grid-template-columns:1fr 1fr 1fr; background:#fff; border-bottom:1px solid #e2e8f0; }
        .kpi-item { text-align:center; padding:14px 8px; }
        .kpi-item + .kpi-item { border-left:1px solid #f1f5f9; }
        .kpi-val { font-size:1.3rem; font-weight:900; line-height:1; }
        .kpi-label { font-size:.6rem; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; font-weight:700; margin-top:4px; }

        .section { background:#fff; margin:10px; border-radius:12px; border:1px solid #e2e8f0; overflow:hidden; }
        .section-header { padding:12px 16px; font-size:.78rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; border-bottom:1px solid #f1f5f9; }

        .mois-grid { display:grid; grid-template-columns:repeat(4,1fr); }
        .mois-cell { text-align:center; padding:12px 6px; }
        .mois-cell + .mois-cell { border-left:1px solid #f1f5f9; }
        .mois-cell .mv { font-size:1.1rem; font-weight:800; }
        .mois-cell .ml { font-size:.58rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; margin-top:3px; }

        .day-row { display:flex; align-items:center; padding:10px 16px; border-bottom:1px solid #f8fafc; gap:10px; }
        .day-row:last-child { border-bottom:none; }
        .day-date { font-size:.78rem; color:#64748b; min-width:70px; }
        .day-badge { display:inline-block; padding:2px 10px; border-radius:99px; font-size:.7rem; font-weight:700; }
        .day-badge.paye { background:#dcfce7; color:#16a34a; }
        .day-badge.non_paye { background:#fee2e2; color:#dc2626; }
        .day-badge.jour_off { background:#f1f5f9; color:#64748b; }
        .day-badge.panne, .day-badge.accident { background:#ffedd5; color:#c2410c; }
        .day-amount { margin-left:auto; font-size:.82rem; font-weight:700; }

        .alert-bar { background:#fef2f2; border:1px solid #fecaca; border-radius:10px; margin:10px; padding:12px 16px; display:flex; align-items:center; gap:10px; }
        .alert-bar.ok { background:#f0fdf4; border-color:#bbf7d0; }
        .alert-icon { font-size:1.4rem; flex-shrink:0; }

        .footer-info { text-align:center; padding:20px; color:#94a3b8; font-size:.72rem; }
    </style>
</head>
<body>

<div class="top-bar">
    <div class="entreprise"><?= htmlspecialchars($chauffeur['nom_entreprise'] ?? 'FlotteCar') ?></div>
    <div class="nom"><?= htmlspecialchars($chauffeur['prenom'] . ' ' . $chauffeur['nom']) ?></div>
    <?php if ($chauffeur['veh_nom']): ?>
    <div class="vehicule"><?= htmlspecialchars($chauffeur['veh_nom']) ?> · <?= htmlspecialchars($chauffeur['immatriculation'] ?? '') ?></div>
    <?php endif; ?>
</div>

<!-- Solde global -->
<?php if ((int)$solde['jours_impayes'] > 0): ?>
<div class="alert-bar">
    <div class="alert-icon">⚠️</div>
    <div>
        <div style="font-weight:700;color:#dc2626;font-size:.88rem"><?= number_format($solde['total_impaye'],0,',',' ') ?> FCFA impayé</div>
        <div style="font-size:.75rem;color:#9f1239"><?= $solde['jours_impayes'] ?> jour(s) non soldé(s)</div>
    </div>
</div>
<?php else: ?>
<div class="alert-bar ok">
    <div class="alert-icon">✅</div>
    <div>
        <div style="font-weight:700;color:#16a34a;font-size:.88rem">Aucun impayé</div>
        <div style="font-size:.75rem;color:#15803d">Tous les versements sont à jour</div>
    </div>
</div>
<?php endif; ?>

<!-- KPIs -->
<div class="kpi-bar">
    <div class="kpi-item">
        <div class="kpi-val" style="color:#16a34a"><?= number_format($solde['total_paye'],0,',',' ') ?></div>
        <div class="kpi-label">Total versé (F)</div>
    </div>
    <div class="kpi-item">
        <div class="kpi-val" style="color:#dc2626"><?= number_format($solde['total_impaye'],0,',',' ') ?></div>
        <div class="kpi-label">Total dû (F)</div>
    </div>
    <div class="kpi-item">
        <div class="kpi-val" style="color:#0d9488"><?= (int)$solde['total_jours'] ?></div>
        <div class="kpi-label">Jours total</div>
    </div>
</div>

<!-- Mois en cours -->
<div class="section">
    <div class="section-header">Ce mois</div>
    <div class="mois-grid">
        <div class="mois-cell">
            <div class="mv" style="color:#16a34a"><?= (int)$mois['jours_payes'] ?></div>
            <div class="ml">Payés</div>
        </div>
        <div class="mois-cell">
            <div class="mv" style="color:#dc2626"><?= (int)$mois['jours_impayes'] ?></div>
            <div class="ml">Impayés</div>
        </div>
        <div class="mois-cell">
            <div class="mv" style="color:#64748b"><?= (int)$mois['jours_off'] + (int)$mois['jours_panne'] ?></div>
            <div class="ml">Off/Panne</div>
        </div>
        <div class="mois-cell">
            <div class="mv" style="color:#0d9488"><?= number_format($mois['total_verse'],0,',',' ') ?></div>
            <div class="ml">Versé (F)</div>
        </div>
    </div>
</div>

<!-- Historique -->
<div class="section">
    <div class="section-header">15 derniers jours</div>
    <?php if (empty($historique)): ?>
    <div style="text-align:center;padding:30px;color:#94a3b8;font-size:.82rem">Aucun historique</div>
    <?php else: ?>
    <?php foreach ($historique as $h):
        $statusClass = $h['statut_jour'];
        $statusLabel = match($h['statut_jour']) {
            'paye' => 'Payé', 'non_paye' => 'Impayé', 'jour_off' => 'Off',
            'panne' => 'Panne', 'accident' => 'Accident', default => $h['statut_jour']
        };
    ?>
    <div class="day-row">
        <span class="day-date"><?= date('d/m', strtotime($h['date_paiement'])) ?></span>
        <span class="day-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
        <?php if ($h['statut_jour'] === 'paye' && $h['montant_paye'] > 0): ?>
        <span class="day-amount" style="color:#16a34a">+<?= number_format($h['montant_paye'],0,',',' ') ?></span>
        <?php elseif ($h['statut_jour'] === 'non_paye' && $h['montant_du'] > 0): ?>
        <span class="day-amount" style="color:#dc2626">-<?= number_format($h['montant_du'],0,',',' ') ?></span>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="footer-info">
    <?= htmlspecialchars($chauffeur['nom_entreprise'] ?? 'FlotteCar') ?> · Powered by FlotteCar<br>
    Dernière mise à jour : <?= date('d/m/Y H:i') ?>
</div>

</body>
</html>
