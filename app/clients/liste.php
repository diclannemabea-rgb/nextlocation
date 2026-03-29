<?php
/**
 * FlotteCar - Liste des clients
 * Tableau paginé avec recherche et statistiques par client
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

// Paramètres de pagination et recherche
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = ITEMS_PER_PAGE;
$offset  = ($page - 1) * $perPage;
$q       = trim($_GET['q'] ?? '');

// Construction des filtres
$where  = 'WHERE c.tenant_id = ?';
$params = [$tenantId];

if ($q !== '') {
    $where   .= ' AND (c.nom LIKE ? OR c.telephone LIKE ? OR c.email LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

// Total pour la pagination
$stmtCount = $db->prepare("SELECT COUNT(*) FROM clients c $where");
$stmtCount->execute($params);
$total = (int)$stmtCount->fetchColumn();

// Requête principale avec stats
$paramsPagine = array_merge($params, [$perPage, $offset]);
$stmt = $db->prepare("
    SELECT c.*,
        COUNT(l.id)                        AS nb_locations,
        COALESCE(SUM(l.montant_final), 0)  AS montant_total
    FROM clients c
    LEFT JOIN locations l ON l.client_id = c.id AND l.tenant_id = c.tenant_id AND l.statut = 'terminee'
    $where
    GROUP BY c.id
    ORDER BY c.nom ASC
    LIMIT ? OFFSET ?
");
$stmt->execute($paramsPagine);
$clients = $stmt->fetchAll();

$baseUrl = BASE_URL . 'app/clients/liste.php?' . http_build_query(array_filter(['q' => $q]));

$pageTitle  = 'Clients';
$activePage = 'clients';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
/* ── Clients liste — design pro ───────────────────────────── */
@keyframes cfadeup { from{opacity:0;transform:translateY(5px)} to{opacity:1;transform:none} }

.cli-search-bar {
    display:flex; gap:10px; align-items:center;
    background:#fff; border:1px solid #e2e8f0; border-radius:10px;
    padding:10px 14px; margin-bottom:14px; flex-wrap:wrap;
}
.cli-search-bar input {
    flex:1; min-width:200px; height:36px;
    border:1px solid #e2e8f0; border-radius:8px;
    padding:0 12px 0 36px; font-size:.84rem; outline:none;
    background:#f8fafc; transition:border-color .15s;
}
.cli-search-bar input:focus { border-color:#0d9488; background:#fff; }

.cli-av {
    width:36px; height:36px; border-radius:10px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    font-size:.78rem; font-weight:800; color:#fff;
}

.cli-table { width:100%; border-collapse:collapse; }
.cli-table thead th {
    padding:8px 12px; font-size:.67rem; text-transform:uppercase;
    letter-spacing:.07em; color:#64748b; font-weight:700;
    background:#f8fafc; border-bottom:2px solid #e2e8f0; white-space:nowrap;
}
.cli-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; animation:cfadeup .25s ease both; }
.cli-table tbody tr:hover { background:#f8faff; }
.cli-table td { padding:10px 12px; vertical-align:middle; font-size:.82rem; }

.cli-loc-badge {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:22px; height:22px; padding:0 6px;
    border-radius:99px; font-size:.68rem; font-weight:700;
    background:#dbeafe; color:#1d4ed8;
}
.cli-loc-badge.zero { background:#f1f5f9; color:#94a3b8; }

.cli-act {
    display:inline-flex; align-items:center; justify-content:center;
    width:28px; height:28px; border-radius:7px; border:none;
    cursor:pointer; font-size:.72rem; text-decoration:none; transition:.15s;
}
.cli-act.edit  { background:#eff6ff; color:#0d9488; }
.cli-act.edit:hover  { background:#dbeafe; }
.cli-act.del   { background:#fff1f2; color:#ef4444; }
.cli-act.del:hover   { background:#fee2e2; }

/* Mobile cards */
@media(max-width:768px) {
    .cli-table-wrap { display:none !important; }
    .cli-cards { display:grid !important; grid-template-columns:1fr; gap:10px; }
    .cli-search-bar { flex-direction:column; gap:8px; }
    .cli-search-bar input { min-width:100%; }
}
@media(min-width:769px) {
    .cli-cards { display:none !important; }
    .cli-table-wrap { display:block; }
}
</style>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px">
    <div>
        <h1 style="font-size:1.3rem;font-weight:800;color:#0f172a;margin:0">Clients</h1>
        <p style="font-size:.8rem;color:#94a3b8;margin:3px 0 0">
            <strong style="color:#0f172a"><?= $total ?></strong> client<?= $total>1?'s':'' ?> enregistré<?= $total>1?'s':'' ?>
        </p>
    </div>
    <a href="<?= BASE_URL ?>app/clients/ajouter.php" class="btn btn-primary btn-sm">
        <i class="fas fa-user-plus"></i> Nouveau client
    </a>
</div>

<?= renderFlashes() ?>

<!-- Barre de recherche -->
<form method="GET" class="cli-search-bar">
    <div style="position:relative;flex:1;min-width:200px">
        <i class="fas fa-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.78rem"></i>
        <input type="text" name="q" value="<?= sanitize($q) ?>" placeholder="Nom, téléphone, email…">
    </div>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Chercher</button>
    <?php if ($q): ?>
    <a href="<?= BASE_URL ?>app/clients/liste.php" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i> Effacer</a>
    <?php endif; ?>
</form>

<!-- TABLE (desktop) -->
<div class="card cli-table-wrap" style="overflow:hidden">
<?php if (empty($clients)): ?>
<div style="padding:40px;text-align:center;color:#94a3b8">
    <i class="fas fa-users" style="font-size:2.5rem;display:block;margin-bottom:12px;opacity:.4"></i>
    <div style="font-size:.9rem;font-weight:600;color:#64748b"><?= $q ? 'Aucun résultat pour "'.$q.'"' : 'Aucun client enregistré' ?></div>
    <?php if (!$q): ?>
    <a href="<?= BASE_URL ?>app/clients/ajouter.php" class="btn btn-primary btn-sm" style="margin-top:12px"><i class="fas fa-user-plus"></i> Ajouter le premier client</a>
    <?php endif; ?>
</div>
<?php else: ?>
<div style="overflow-x:auto">
<table class="cli-table">
<thead>
<tr>
    <th style="width:40px"></th>
    <th>Client</th>
    <th>Contact</th>
    <th>Pièce d'identité</th>
    <th style="text-align:center">Locations</th>
    <th style="text-align:right">Total généré</th>
    <th>Depuis</th>
    <th style="text-align:center;width:80px">Actions</th>
</tr>
</thead>
<tbody>
<?php
$avColors = ['#6366f1','#0ea5e9','#10b981','#f59e0b','#8b5cf6','#ec4899','#06b6d4','#84cc16'];
foreach ($clients as $ci => $c):
    $initials = strtoupper(substr($c['nom'],0,1) . (isset($c['prenom'][0]) ? $c['prenom'][0] : substr($c['nom'],1,1)));
    $avColor  = $avColors[$ci % count($avColors)];
    $hasLoc   = (int)$c['nb_locations'] > 0;
?>
<tr style="animation-delay:<?= $ci * .03 ?>s">
    <td style="padding:8px 6px 8px 12px">
        <div class="cli-av" style="background:<?= $avColor ?>"><?= $initials ?></div>
    </td>
    <td>
        <div style="font-weight:700;color:#0f172a"><?= sanitize($c['nom']) ?><?= !empty($c['prenom']) ? ' '.sanitize($c['prenom']) : '' ?></div>
        <?php if (!empty($c['profession'])): ?>
        <div style="font-size:.7rem;color:#94a3b8"><?= sanitize($c['profession']) ?></div>
        <?php endif; ?>
    </td>
    <td>
        <?php if (!empty($c['telephone'])): ?>
        <a href="tel:<?= sanitize($c['telephone']) ?>" style="color:#0f172a;text-decoration:none;font-size:.8rem;display:flex;align-items:center;gap:5px">
            <i class="fas fa-phone" style="color:#10b981;font-size:.65rem"></i><?= sanitize($c['telephone']) ?>
        </a>
        <?php endif; ?>
        <?php if (!empty($c['email'])): ?>
        <a href="mailto:<?= sanitize($c['email']) ?>" style="color:#94a3b8;text-decoration:none;font-size:.72rem;display:block;margin-top:2px">
            <?= sanitize($c['email']) ?>
        </a>
        <?php endif; ?>
        <?php if (empty($c['telephone']) && empty($c['email'])): ?>
        <span style="color:#cbd5e1;font-size:.8rem">—</span>
        <?php endif; ?>
    </td>
    <td>
        <?php if (!empty($c['type_piece'])): ?>
        <span style="background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:6px;font-size:.68rem;font-weight:700"><?= sanitize($c['type_piece']) ?></span>
        <?php if (!empty($c['numero_piece'])): ?>
        <div style="font-size:.68rem;color:#94a3b8;font-family:monospace;margin-top:2px"><?= sanitize($c['numero_piece']) ?></div>
        <?php endif; ?>
        <?php else: ?>
        <span style="color:#e2e8f0;font-size:.8rem">—</span>
        <?php endif; ?>
    </td>
    <td style="text-align:center">
        <span class="cli-loc-badge <?= !$hasLoc?'zero':'' ?>"><?= (int)$c['nb_locations'] ?></span>
    </td>
    <td style="text-align:right">
        <?php if ((float)$c['montant_total'] > 0): ?>
        <span style="font-weight:700;color:#10b981;font-size:.82rem"><?= formatMoney((float)$c['montant_total']) ?></span>
        <?php else: ?>
        <span style="color:#e2e8f0">—</span>
        <?php endif; ?>
    </td>
    <td style="color:#94a3b8;font-size:.73rem;white-space:nowrap"><?= formatDate($c['created_at']) ?></td>
    <td>
        <div style="display:flex;gap:4px;justify-content:center">
            <a href="<?= BASE_URL ?>app/clients/modifier.php?id=<?= (int)$c['id'] ?>" class="cli-act edit" title="Modifier">
                <i class="fas fa-pen"></i>
            </a>
            <button class="cli-act del" title="Supprimer" onclick="confirmerSuppression(<?= (int)$c['id'] ?>,'<?= addslashes(sanitize($c['nom'])) ?>')">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<div style="padding:10px 14px;border-top:1px solid #f1f5f9">
    <?= renderPagination($total, $page, $perPage, $baseUrl) ?>
</div>
<?php endif; ?>
</div>

<!-- CARDS (mobile) -->
<div class="cli-cards" style="display:none">
<?php if (empty($clients)): ?>
<div style="text-align:center;padding:40px 16px;color:#94a3b8">
    <i class="fas fa-users" style="font-size:2rem;display:block;margin-bottom:10px;opacity:.4"></i>
    <div style="font-size:.88rem"><?= $q ? 'Aucun résultat' : 'Aucun client' ?></div>
</div>
<?php else: ?>
<?php foreach ($clients as $ci => $c):
    $initials = strtoupper(substr($c['nom'],0,1) . (isset($c['prenom'][0]) ? $c['prenom'][0] : substr($c['nom'],1,1)));
    $avColor  = $avColors[$ci % count($avColors)];
?>
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;display:flex;gap:12px;align-items:flex-start">
    <div class="cli-av" style="background:<?= $avColor ?>;width:42px;height:42px;font-size:.88rem"><?= $initials ?></div>
    <div style="flex:1;min-width:0">
        <div style="font-weight:700;color:#0f172a;font-size:.92rem"><?= sanitize($c['nom']) ?><?= !empty($c['prenom']) ? ' '.sanitize($c['prenom']) : '' ?></div>
        <?php if (!empty($c['telephone'])): ?>
        <a href="tel:<?= sanitize($c['telephone']) ?>" style="color:#64748b;font-size:.8rem;text-decoration:none;display:flex;align-items:center;gap:4px;margin-top:2px">
            <i class="fas fa-phone" style="color:#10b981;font-size:.65rem"></i><?= sanitize($c['telephone']) ?>
        </a>
        <?php endif; ?>
        <div style="display:flex;align-items:center;gap:8px;margin-top:7px">
            <span class="cli-loc-badge <?= !((int)$c['nb_locations'])?'zero':'' ?>"><?= (int)$c['nb_locations'] ?> loc.</span>
            <?php if ((float)$c['montant_total'] > 0): ?>
            <span style="font-size:.75rem;font-weight:700;color:#10b981"><?= formatMoney((float)$c['montant_total']) ?></span>
            <?php endif; ?>
            <div style="margin-left:auto;display:flex;gap:4px">
                <a href="<?= BASE_URL ?>app/clients/modifier.php?id=<?= (int)$c['id'] ?>" class="cli-act edit"><i class="fas fa-pen"></i></a>
                <button class="cli-act del" onclick="confirmerSuppression(<?= (int)$c['id'] ?>,'<?= addslashes(sanitize($c['nom'])) ?>')"><i class="fas fa-trash"></i></button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
<div style="padding:8px 4px"><?= renderPagination($total, $page, $perPage, $baseUrl) ?></div>
<?php endif; ?>
</div>

<form id="form-supprimer" method="POST" action="<?= BASE_URL ?>app/clients/supprimer.php" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="id" id="supprimer-id">
</form>

<?php
$extraJs = <<<'JS'
function confirmerSuppression(id, nom) {
    if (confirm('Supprimer le client "' + nom + '" ?\nCette action est irréversible.')) {
        document.getElementById('supprimer-id').value = id;
        document.getElementById('form-supprimer').submit();
    }
}
JS;
require_once BASE_PATH . '/includes/footer.php';
?>
