<?php
/**
 * FlotteCar — Commerciaux (Liste + Ajout/Édition modal)
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
require_once BASE_PATH . '/models/CommercialModel.php';

requireTenantAuth();

if (!hasLocationModule()) {
    setFlash('error', 'Module Locations requis.');
    redirect(BASE_URL . 'app/dashboard.php');
}

$db       = (new Database())->getConnection();
$tenantId = getTenantId();
$model    = new CommercialModel($db);

// -------------------------------------------------------
// ACTIONS POST
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'ajouter' || $action === 'modifier') {
        $nom        = trim($_POST['nom']        ?? '');
        $prenom     = trim($_POST['prenom']     ?? '');
        $telephone  = trim($_POST['telephone']  ?? '');
        $email      = trim($_POST['email']      ?? '');
        $commPct    = (float) ($_POST['commission_pct'] ?? 0);
        $notes      = trim($_POST['notes']      ?? '');

        if ($nom === '') {
            setFlash('error', 'Le nom est obligatoire.');
        } elseif ($action === 'ajouter') {
            $model->create($tenantId, compact('nom', 'prenom', 'telephone', 'email', 'notes') + ['commission_pct' => $commPct]);
            setFlash('success', "Commercial $nom ajouté.");
            logActivite($db, 'create', 'commerciaux', "Ajout commercial : $nom");
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            $model->update($id, $tenantId, compact('nom', 'prenom', 'telephone', 'email', 'notes') + ['commission_pct' => $commPct]);
            setFlash('success', "Commercial mis à jour.");
            logActivite($db, 'update', 'commerciaux', "Modification commercial ID $id");
        }
        redirect(BASE_URL . 'app/commerciaux/index.php');
    }

    if ($action === 'supprimer') {
        $id = (int) ($_POST['id'] ?? 0);
        $model->delete($id, $tenantId);
        setFlash('success', 'Commercial supprimé.');
        redirect(BASE_URL . 'app/commerciaux/index.php');
    }
}

// -------------------------------------------------------
// DONNÉES
// -------------------------------------------------------
$commerciaux = $model->getAll($tenantId);

// Stats par commercial (nb locations + commissions)
$statsMap = [];
foreach ($commerciaux as $c) {
    $statsMap[$c['id']] = $model->getStats($c['id'], $tenantId);
}

$pageTitle   = 'Commerciaux';
$activePage  = 'commerciaux';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Commerciaux</h1>
        <p class="page-subtitle">Gérez vos apporteurs d'affaires et suivez leurs commissions</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('modal-add')">
        <i class="fas fa-plus"></i> Nouveau commercial
    </button>
</div>

<?= renderFlashes() ?>

<?php if (empty($commerciaux)): ?>
    <div class="card" style="text-align:center;padding:60px 20px">
        <i class="fas fa-user-tie" style="font-size:3rem;color:var(--text-muted);margin-bottom:16px;display:block"></i>
        <h3 style="margin:0 0 8px">Aucun commercial</h3>
        <p style="color:var(--text-muted);margin:0 0 20px">Ajoutez vos commerciaux pour suivre leurs commissions.</p>
        <button class="btn btn-primary" onclick="openModal('modal-add')">
            <i class="fas fa-plus"></i> Ajouter un commercial
        </button>
    </div>
<?php else: ?>

<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:24px">
    <div class="stat-card primary">
        <div class="stat-icon"><i class="fas fa-user-tie"></i></div>
        <div class="stat-value"><?= count($commerciaux) ?></div>
        <div class="stat-label">Commerciaux actifs</div>
    </div>
    <?php
    $totalLocs = array_sum(array_column($statsMap, 'nb_locations'));
    $totalComm = array_sum(array_column($statsMap, 'commission_earned'));
    ?>
    <div class="stat-card success">
        <div class="stat-icon"><i class="fas fa-handshake"></i></div>
        <div class="stat-value"><?= $totalLocs ?></div>
        <div class="stat-label">Locations apportées</div>
    </div>
    <div class="stat-card info">
        <div class="stat-icon"><i class="fas fa-coins"></i></div>
        <div class="stat-value"><?= formatMoney($totalComm) ?></div>
        <div class="stat-label">Commissions dues</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-list"></i> Liste des commerciaux</h3>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Téléphone</th>
                    <th>Email</th>
                    <th>Commission %</th>
                    <th>Locations</th>
                    <th>CA apporté</th>
                    <th>Commissions</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($commerciaux as $c):
                $stats = $statsMap[$c['id']] ?? ['nb_locations'=>0,'ca_apporte'=>0,'total_commissions'=>0];
            ?>
                <tr>
                    <td>
                        <strong><?= sanitize($c['nom']) ?></strong>
                        <?php if ($c['prenom']): ?>
                            <br><small class="text-muted"><?= sanitize($c['prenom']) ?></small>
                        <?php endif ?>
                    </td>
                    <td><?= sanitize($c['telephone'] ?? '—') ?></td>
                    <td><?= sanitize($c['email'] ?? '—') ?></td>
                    <td><?= $c['commission_pct'] ?>%</td>
                    <td><span class="badge bg-primary"><?= $stats['nb_locations'] ?></span></td>
                    <td><?= formatMoney($stats['chiffre_affaires'] ?? 0) ?></td>
                    <td><strong><?= formatMoney($stats['commission_earned'] ?? 0) ?></strong></td>
                    <td>
                        <span class="badge <?= $c['statut'] === 'actif' ? 'bg-success' : 'bg-secondary' ?>">
                            <?= ucfirst($c['statut']) ?>
                        </span>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>app/commerciaux/detail.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-ghost" title="Voir fiche"><i class="fas fa-eye"></i></a>
                        <button class="btn btn-sm btn-ghost"
                            onclick="editCommercial(<?= htmlspecialchars(json_encode($c)) ?>)"
                            title="Modifier">
                            <i class="fas fa-edit"></i>
                        </button>
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('Supprimer ce commercial ?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="supprimer">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button class="btn btn-sm" style="background:#fff1f2;color:#ef4444;border:none" title="Supprimer"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif ?>

<!-- MODAL AJOUT -->
<div id="modal-add" class="modal-overlay">
    <div class="modal" style="max-width:520px">
        <div class="modal-header">
            <h3><i class="fas fa-user-tie"></i> Nouveau commercial</h3>
            <button class="modal-close" onclick="closeModal('modal-add')">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="ajouter">
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Nom *</label>
                    <input type="text" name="nom" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Prénom</label>
                    <input type="text" name="prenom" class="form-control">
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Téléphone</label>
                    <input type="tel" name="telephone" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Commission (%)</label>
                <input type="number" name="commission_pct" class="form-control" value="0" min="0" max="100" step="0.5">
                <span class="form-hint">Pourcentage du montant final de la location</span>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-add')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL ÉDITION -->
<div id="modal-edit" class="modal-overlay">
    <div class="modal" style="max-width:520px">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Modifier commercial</h3>
            <button class="modal-close" onclick="closeModal('modal-edit')">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="modifier">
            <input type="hidden" name="id" id="edit-id">
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Nom *</label>
                    <input type="text" name="nom" id="edit-nom" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Prénom</label>
                    <input type="text" name="prenom" id="edit-prenom" class="form-control">
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Téléphone</label>
                    <input type="tel" name="telephone" id="edit-telephone" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" id="edit-email" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Commission (%)</label>
                <input type="number" name="commission_pct" id="edit-commission" class="form-control" min="0" max="100" step="0.5">
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" id="edit-notes" class="form-control" rows="2"></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<script>
function editCommercial(c) {
    document.getElementById('edit-id').value        = c.id;
    document.getElementById('edit-nom').value       = c.nom || '';
    document.getElementById('edit-prenom').value    = c.prenom || '';
    document.getElementById('edit-telephone').value = c.telephone || '';
    document.getElementById('edit-email').value     = c.email || '';
    document.getElementById('edit-commission').value= c.commission_pct || 0;
    document.getElementById('edit-notes').value     = c.notes || '';
    openModal('modal-edit');
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
