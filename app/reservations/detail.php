<?php
/**
 * FlotteCar — Détail réservation
 * Actions : Confirmer · Ajouter paiement · Convertir en location · Annuler
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
require_once BASE_PATH . '/models/ReservationModel.php';
require_once BASE_PATH . '/models/LocationModel.php';
requireTenantAuth();

if (!hasLocationModule()) {
    setFlash(FLASH_ERROR, 'Module Locations requis.');
    redirect(BASE_URL . 'app/dashboard.php');
}

$db       = (new Database())->getConnection();
$tenantId = getTenantId();
$model    = new ReservationModel($db);
$locModel = new LocationModel($db);

$id  = (int)($_GET['id'] ?? 0);
$rsv = $model->getById($id, $tenantId);
if (!$rsv) {
    setFlash(FLASH_ERROR, 'Réservation introuvable.');
    redirect(BASE_URL . 'app/reservations/calendrier.php');
}

// ─── ACTIONS POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    // ── Confirmer ──────────────────────────────────────────────────────────────
    if ($action === 'confirmer' && $rsv['statut'] === 'en_attente') {
        $model->confirmer($id, $tenantId);
        logActivite($db, 'UPDATE', 'reservations', "Réservation #$id confirmée");
        setFlash(FLASH_SUCCESS, 'Réservation confirmée.');
        redirect(BASE_URL . 'app/reservations/detail.php?id=' . $id);
    }

    // ── Ajouter paiement sur réservation ─────────────────────────────────────
    if ($action === 'paiement' && in_array($rsv['statut'], ['en_attente', 'confirmee'])) {
        $montant = cleanNumber($_POST['montant'] ?? '0');
        $mode    = $_POST['mode_paiement'] ?? 'espece';
        $notes   = trim($_POST['notes_paiement'] ?? '');

        if ($montant <= 0) {
            setFlash(FLASH_ERROR, 'Montant invalide.');
        } else {
            $model->ajouterPaiement($id, $tenantId, (float)$montant, $mode, $notes ?: 'Paiement réservation');
            logActivite($db, 'UPDATE', 'reservations', "Paiement " . number_format($montant, 0, ',', ' ') . " FCFA sur réservation #$id");
            setFlash(FLASH_SUCCESS, 'Paiement de ' . formatMoney((float)$montant) . ' enregistré.');
        }
        redirect(BASE_URL . 'app/reservations/detail.php?id=' . $id);
    }

    // ── Convertir en location ──────────────────────────────────────────────────
    if ($action === 'convertir' && in_array($rsv['statut'], ['en_attente', 'confirmee'])) {
        $paiementReste = cleanNumber($_POST['paiement_reste'] ?? '0');
        $mode          = $_POST['mode_paiement_conv'] ?? 'espece';
        $typeLocation  = $_POST['type_location'] ?? 'standard';
        $kmDepart      = (int)($_POST['km_depart'] ?? 0) ?: null;

        try {
            $locationId = $model->convertirEnLocation($id, $tenantId, [
                'paiement_reste'   => (float) $paiementReste,
                'mode_paiement'    => $mode,
                'type_location'    => $typeLocation,
                'km_depart'        => $kmDepart,
                'carburant_depart' => $_POST['carburant_depart'] ?? 'plein',
            ]);
            logActivite($db, 'CREATE', 'locations', "Réservation #$id convertie → Location #$locationId");
            setFlash(FLASH_SUCCESS, 'Réservation convertie en location. La location #' . $locationId . ' est maintenant active.');
            redirect(BASE_URL . 'app/locations/detail.php?id=' . $locationId);
        } catch (\Exception $e) {
            setFlash(FLASH_ERROR, $e->getMessage());
            redirect(BASE_URL . 'app/reservations/detail.php?id=' . $id);
        }
    }

    // ── Annuler ────────────────────────────────────────────────────────────────
    if ($action === 'annuler' && !in_array($rsv['statut'], ['annulee', 'convertie'])) {
        $motif     = trim($_POST['motif_annulation'] ?? '');
        $totalPaye = $model->getTotalPaye($id, $tenantId);
        $model->annuler($id, $tenantId, $motif);
        logActivite($db, 'UPDATE', 'reservations', "Réservation #$id annulée" . ($motif ? " — $motif" : ''));
        $msg = 'Réservation annulée.';
        if ($totalPaye > 0) {
            $msg .= ' ' . formatMoney($totalPaye) . ' retranchés des recettes du véhicule.';
        }
        setFlash(FLASH_WARNING, $msg);
        redirect(BASE_URL . 'app/reservations/detail.php?id=' . $id);
    }

    redirect(BASE_URL . 'app/reservations/detail.php?id=' . $id);
}

// ─── DONNÉES ──────────────────────────────────────────────────────────────────
$rsv       = $model->getById($id, $tenantId); // re-fetch après éventuels POST
$paiements = $model->getPaiements($id, $tenantId);
$totalPaye = $model->getTotalPaye($id, $tenantId);
$resteRsv  = max(0, (float)$rsv['montant_final'] - $totalPaye);

$peutAgir    = !in_array($rsv['statut'], ['annulee', 'convertie']);
$peutPayer   = in_array($rsv['statut'], ['en_attente', 'confirmee']);
$peutConvert = in_array($rsv['statut'], ['en_attente', 'confirmee']);

$pageTitle  = 'Réservation #' . $id;
$activePage = 'reservations';
require_once BASE_PATH . '/includes/header.php';

$badgeStatut = match($rsv['statut']) {
    'en_attente' => '<span class="badge bg-warning">En attente</span>',
    'confirmee'  => '<span class="badge bg-info">Confirmée</span>',
    'convertie'  => '<span class="badge bg-success">Convertie en location</span>',
    'annulee'    => '<span class="badge bg-danger">Annulée</span>',
    default      => '<span class="badge">' . sanitize($rsv['statut']) . '</span>',
};
?>

<div class="page-header">
    <div>
        <a href="<?= BASE_URL ?>app/reservations/calendrier.php" class="btn btn-ghost btn-sm" style="margin-bottom:4px">
            <i class="fas fa-arrow-left"></i> Calendrier
        </a>
        <h1 class="page-title"><i class="fas fa-calendar-alt"></i> Réservation #<?= $id ?> &nbsp;<?= $badgeStatut ?></h1>
        <p class="page-subtitle">
            <?= sanitize($rsv['client_nom'] . ' ' . ($rsv['client_prenom'] ?? '')) ?>
            &nbsp;·&nbsp; <?= sanitize($rsv['vehicule_nom']) ?> (<?= sanitize($rsv['immatriculation']) ?>)
        </p>
    </div>
    <?php if ($peutAgir): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($rsv['statut'] === 'en_attente'): ?>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="confirmer">
            <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Confirmer</button>
        </form>
        <?php endif ?>
        <?php if ($peutPayer): ?>
        <button class="btn btn-success" onclick="openModal('modal-paiement')">
            <i class="fas fa-plus"></i> Ajouter paiement
        </button>
        <?php endif ?>
        <?php if ($peutConvert): ?>
        <button class="btn btn-primary" style="background:#7c3aed;border-color:#7c3aed" onclick="openModal('modal-convertir')">
            <i class="fas fa-exchange-alt"></i> Convertir en location
        </button>
        <?php endif ?>
        <button class="btn btn-danger" onclick="openModal('modal-annuler')">
            <i class="fas fa-times"></i> Annuler
        </button>
    </div>
    <?php endif ?>
    <?php if ($rsv['statut'] === 'convertie' && !empty($rsv['location_id'])): ?>
    <a href="<?= BASE_URL ?>app/locations/detail.php?id=<?= $rsv['location_id'] ?>" class="btn btn-success">
        <i class="fas fa-external-link-alt"></i> Voir la location #<?= $rsv['location_id'] ?>
    </a>
    <?php endif ?>
</div>

<?= renderFlashes() ?>

<!-- ── Résumé financier ───────────────────────────────────────────────────── -->
<div class="stats-grid" style="margin-bottom:16px">
    <div class="stat-card primary">
        <div class="stat-icon"><i class="fas fa-file-invoice-dollar"></i></div>
        <div class="stat-info">
            <div class="stat-value" style="font-size:1rem"><?= formatMoney((float)$rsv['montant_final']) ?></div>
            <div class="stat-label">Montant total dû</div>
        </div>
    </div>
    <div class="stat-card success">
        <div class="stat-icon"><i class="fas fa-hand-holding-usd"></i></div>
        <div class="stat-info">
            <div class="stat-value" style="font-size:1rem"><?= formatMoney($totalPaye) ?></div>
            <div class="stat-label">Encaissé</div>
        </div>
    </div>
    <div class="stat-card <?= $resteRsv > 0 ? 'danger' : 'success' ?>">
        <div class="stat-icon"><i class="fas fa-<?= $resteRsv > 0 ? 'exclamation-circle' : 'check-circle' ?>"></i></div>
        <div class="stat-info">
            <div class="stat-value" style="font-size:1rem"><?= formatMoney($resteRsv) ?></div>
            <div class="stat-label">Reste à payer</div>
        </div>
    </div>
    <div class="stat-card info">
        <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
        <div class="stat-info">
            <div class="stat-value"><?= (int)$rsv['nombre_jours'] ?> j</div>
            <div class="stat-label"><?= formatDate($rsv['date_debut']) ?> → <?= formatDate($rsv['date_fin']) ?></div>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
    <!-- Infos réservation -->
    <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-info-circle"></i> Détails</h3></div>
        <div class="card-body" style="padding:0">
            <table class="table" style="font-size:.875rem">
                <tr><td style="color:#64748b;width:40%">Véhicule</td><td><strong><?= sanitize($rsv['vehicule_nom']) ?></strong> — <?= sanitize($rsv['immatriculation']) ?></td></tr>
                <tr><td style="color:#64748b">Client</td><td><?= sanitize($rsv['client_nom'] . ' ' . ($rsv['client_prenom'] ?? '')) ?> <?php if ($rsv['client_telephone']): ?><br><span style="font-size:.8rem;color:#64748b"><?= sanitize($rsv['client_telephone']) ?></span><?php endif ?></td></tr>
                <tr><td style="color:#64748b">Période</td><td><?= formatDate($rsv['date_debut']) ?> → <?= formatDate($rsv['date_fin']) ?> <span style="color:#0d9488">(<?= $rsv['nombre_jours'] ?> j)</span></td></tr>
                <tr><td style="color:#64748b">Prix/jour</td><td><?= formatMoney((float)$rsv['prix_par_jour']) ?></td></tr>
                <?php if ($rsv['remise'] > 0): ?>
                <tr><td style="color:#64748b">Remise</td><td style="color:#dc2626">- <?= formatMoney((float)$rsv['remise']) ?></td></tr>
                <?php endif ?>
                <?php if ($rsv['caution'] > 0): ?>
                <tr><td style="color:#64748b">Caution</td><td><?= formatMoney((float)$rsv['caution']) ?></td></tr>
                <?php endif ?>
                <?php if ($rsv['lieu_destination']): ?>
                <tr><td style="color:#64748b">Destination</td><td><?= sanitize($rsv['lieu_destination']) ?></td></tr>
                <?php endif ?>
                <?php if ($rsv['avec_chauffeur']): ?>
                <tr><td style="color:#64748b">Avec chauffeur</td><td><span class="badge bg-info">Oui</span></td></tr>
                <?php endif ?>
                <?php if ($rsv['chauffeur_nom']): ?>
                <tr><td style="color:#64748b">Chauffeur</td><td><?= sanitize($rsv['chauffeur_nom'] . ' ' . ($rsv['chauffeur_prenom'] ?? '')) ?></td></tr>
                <?php endif ?>
                <?php if ($rsv['commercial_nom']): ?>
                <tr><td style="color:#64748b">Commercial</td><td><?= sanitize($rsv['commercial_nom'] . ' ' . ($rsv['commercial_prenom'] ?? '')) ?></td></tr>
                <?php endif ?>
                <?php if ($rsv['canal_acquisition']): ?>
                <tr><td style="color:#64748b">Canal</td><td><?= sanitize(ucfirst(str_replace('_', ' ', $rsv['canal_acquisition']))) ?></td></tr>
                <?php endif ?>
                <?php if ($rsv['notes']): ?>
                <tr><td style="color:#64748b;vertical-align:top">Notes</td><td style="font-size:.8rem;color:#475569"><?= nl2br(sanitize($rsv['notes'])) ?></td></tr>
                <?php endif ?>
            </table>
        </div>
    </div>

    <!-- Historique paiements -->
    <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
            <h3 class="card-title"><i class="fas fa-receipt"></i> Paiements</h3>
            <?php if ($peutPayer): ?>
            <button class="btn btn-sm btn-success" onclick="openModal('modal-paiement')">
                <i class="fas fa-plus"></i> Ajouter
            </button>
            <?php endif ?>
        </div>
        <div class="card-body" style="padding:0">
            <?php if (empty($paiements)): ?>
            <div style="text-align:center;padding:24px;color:#94a3b8;font-size:.875rem">
                <i class="fas fa-receipt" style="font-size:1.5rem;display:block;margin-bottom:6px"></i>
                Aucun paiement enregistré
            </div>
            <?php else: ?>
            <table class="table" style="font-size:.85rem">
                <thead><tr><th>Date</th><th>Montant</th><th>Mode</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ($paiements as $p): ?>
                <tr>
                    <td><?= formatDatetime($p['created_at']) ?></td>
                    <td><strong style="color:#16a34a"><?= formatMoney((float)$p['montant']) ?></strong></td>
                    <td><span class="badge bg-secondary" style="font-size:.72rem"><?= sanitize(ucfirst(str_replace('_', ' ', $p['mode_paiement']))) ?></span></td>
                    <td style="color:#64748b;font-size:.8rem"><?= sanitize($p['notes'] ?? '') ?></td>
                </tr>
                <?php endforeach ?>
                </tbody>
                <tfoot>
                <tr style="background:#f8fafc">
                    <td><strong>Total encaissé</strong></td>
                    <td colspan="3"><strong style="color:#16a34a"><?= formatMoney($totalPaye) ?></strong>
                        <?php if ($resteRsv > 0): ?>
                        &nbsp;·&nbsp; <span style="color:#dc2626">Reste : <?= formatMoney($resteRsv) ?></span>
                        <?php else: ?>
                        &nbsp;·&nbsp; <span style="color:#16a34a"><i class="fas fa-check"></i> Soldé</span>
                        <?php endif ?>
                    </td>
                </tr>
                </tfoot>
            </table>
            <?php endif ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL — Ajouter paiement
═══════════════════════════════════════════════════════════════════════════ -->
<div id="modal-paiement" class="modal-overlay">
    <div class="modal" style="max-width:420px">
        <div class="modal-header" style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
            <h3 style="margin:0;font-size:1rem"><i class="fas fa-plus" style="color:#16a34a"></i> Ajouter un paiement</h3>
            <button onclick="closeModal('modal-paiement')" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#64748b">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="paiement">
            <?php if ($resteRsv > 0): ?>
            <div style="background:#f0fff4;border:1px solid #bbf7d0;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:.875rem">
                <i class="fas fa-info-circle" style="color:#16a34a"></i>
                Reste à payer : <strong style="color:#15803d"><?= formatMoney($resteRsv) ?></strong>
            </div>
            <?php endif ?>
            <div class="form-group">
                <label class="form-label">Montant (FCFA) *</label>
                <input type="number" name="montant" class="form-control" min="0" step="500"
                       value="<?= $resteRsv > 0 ? (int)$resteRsv : '' ?>" required autofocus>
            </div>
            <div class="form-group">
                <label class="form-label">Mode de paiement</label>
                <select name="mode_paiement" class="form-control">
                    <option value="espece">Espèces</option>
                    <option value="mobile_money">Mobile Money</option>
                    <option value="virement">Virement</option>
                    <option value="cheque">Chèque</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <input type="text" name="notes_paiement" class="form-control" placeholder="Ex: 2ème versement, solde...">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Enregistrer</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('modal-paiement')">Annuler</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL — Convertir en location
═══════════════════════════════════════════════════════════════════════════ -->
<div id="modal-convertir" class="modal-overlay">
    <div class="modal" style="max-width:520px">
        <div class="modal-header" style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
            <h3 style="margin:0;font-size:1rem"><i class="fas fa-exchange-alt" style="color:#7c3aed"></i> Convertir en location active</h3>
            <button onclick="closeModal('modal-convertir')" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#64748b">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="convertir">

            <div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:6px;padding:12px 14px;margin-bottom:16px;font-size:.875rem">
                <strong style="color:#6d28d9"><i class="fas fa-info-circle"></i> Déjà encaissé : <?= formatMoney($totalPaye) ?></strong>
                <?php if ($resteRsv > 0): ?>
                <br><span style="color:#7c3aed">Reste à régler : <?= formatMoney($resteRsv) ?></span>
                <?php else: ?>
                <br><span style="color:#16a34a"><i class="fas fa-check"></i> Entièrement soldé</span>
                <?php endif ?>
            </div>

            <?php if ($resteRsv > 0): ?>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Paiement du reste (FCFA)</label>
                    <input type="number" name="paiement_reste" class="form-control" min="0" step="500"
                           value="<?= (int)$resteRsv ?>" placeholder="0">
                    <span class="form-hint">Laisser 0 si pas de paiement maintenant</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Mode paiement</label>
                    <select name="mode_paiement_conv" class="form-control">
                        <option value="espece">Espèces</option>
                        <option value="mobile_money">Mobile Money</option>
                        <option value="virement">Virement</option>
                        <option value="cheque">Chèque</option>
                    </select>
                </div>
            </div>
            <?php else: ?>
            <input type="hidden" name="paiement_reste" value="0">
            <?php endif ?>

            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Type de location</label>
                    <select name="type_location" class="form-control">
                        <option value="standard">Standard</option>
                        <option value="avec_chauffeur">Avec chauffeur</option>
                        <option value="longue_duree">Longue durée</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Km départ (compteur)</label>
                    <input type="number" name="km_depart" class="form-control" min="0" placeholder="Optionnel">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Carburant au départ</label>
                <select name="carburant_depart" class="form-control">
                    <option value="plein">Plein</option>
                    <option value="3/4">3/4</option>
                    <option value="1/2">1/2</option>
                    <option value="1/4">1/4</option>
                    <option value="vide">Presque vide</option>
                </select>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" style="background:#7c3aed;border-color:#7c3aed">
                    <i class="fas fa-exchange-alt"></i> Confirmer la conversion
                </button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('modal-convertir')">Annuler</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL — Annuler réservation
═══════════════════════════════════════════════════════════════════════════ -->
<div id="modal-annuler" class="modal-overlay">
    <div class="modal" style="max-width:420px">
        <div class="modal-header" style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
            <h3 style="margin:0;font-size:1rem"><i class="fas fa-times-circle" style="color:#dc2626"></i> Annuler la réservation</h3>
            <button onclick="closeModal('modal-annuler')" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#64748b">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="annuler">

            <?php if ($totalPaye > 0): ?>
            <div style="background:#fff5f5;border:1px solid #fca5a5;border-radius:6px;padding:12px 14px;margin-bottom:14px;font-size:.875rem">
                <strong style="color:#dc2626"><i class="fas fa-exclamation-triangle"></i> Attention !</strong><br>
                <span style="color:#7f1d1d">
                    <?= formatMoney($totalPaye) ?> déjà encaissés seront <strong>retranchés des recettes</strong> du véhicule.
                    Pensez à rembourser le client si nécessaire.
                </span>
            </div>
            <?php endif ?>

            <div class="form-group">
                <label class="form-label">Motif d'annulation</label>
                <textarea name="motif_annulation" class="form-control" rows="2" placeholder="Ex: Client annule, véhicule indisponible..."></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> Confirmer l'annulation</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('modal-annuler')">Retour</button>
            </div>
        </form>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
