<?php
/**
 * FlotteCar - Terminer une location (retour véhicule)
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireTenantAuth();

if (!hasLocationModule()) {
    setFlash(FLASH_ERROR, 'Accès non autorisé.');
    redirect(BASE_URL . 'app/dashboard.php');
}

$db       = (new Database())->getConnection();
$tenantId = getTenantId();

$locationId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$locationId) {
    setFlash(FLASH_ERROR, 'Identifiant invalide.');
    redirect(BASE_URL . 'app/locations/liste.php');
}

// ── Récupération location + véhicule + client ─────────────────────────────────
$stmt = $db->prepare("
    SELECT l.*,
           v.nom AS veh_nom, v.immatriculation, v.marque, v.modele, v.kilometrage_actuel,
           c.nom AS client_nom, c.prenom AS client_prenom, c.telephone AS client_tel
    FROM   locations l
    JOIN   vehicules v ON v.id = l.vehicule_id
    JOIN   clients   c ON c.id = l.client_id
    WHERE  l.id = ? AND l.tenant_id = ?
");
$stmt->execute([$locationId, $tenantId]);
$loc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$loc) {
    setFlash(FLASH_ERROR, 'Location introuvable.');
    redirect(BASE_URL . 'app/locations/liste.php');
}
if ($loc['statut'] !== 'en_cours') {
    setFlash(FLASH_WARNING, 'Cette location n\'est pas en cours.');
    redirect(BASE_URL . 'app/locations/detail.php?id=' . $locationId);
}

// Paiements déjà enregistrés
$stmtPay = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM paiements WHERE location_id=? AND tenant_id=?");
$stmtPay->execute([$locationId, $tenantId]);
$totalPaye = (float)$stmtPay->fetchColumn();
$resteApayer = max(0, (float)$loc['montant_final'] - $totalPaye);

// Jours de retard éventuels
$today     = date('Y-m-d');
$finPrevue = $loc['date_fin'];
$nbRetard  = 0;
if ($finPrevue < $today) {
    $nbRetard = (int)((strtotime($today) - strtotime($finPrevue)) / 86400);
}

// ── POST ──────────────────────────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();

    $kmRetour        = (int)($_POST['km_retour']       ?? 0);
    $carburantRetour = trim($_POST['carburant_retour'] ?? '');
    $statutCaution   = trim($_POST['statut_caution']   ?? 'rendue');
    $notesRetour     = trim($_POST['notes_retour']     ?? '');
    $paiementFinale  = (float)str_replace(',', '.', $_POST['paiement_finale'] ?? '0');
    $modePaie        = trim($_POST['mode_paiement']    ?? 'espece');

    if ($kmRetour > 0 && $kmRetour < (int)$loc['km_depart']) {
        $errors[] = 'Le km de retour (' . number_format($kmRetour, 0, ',', ' ') . ') ne peut pas être inférieur au km départ (' . number_format((int)$loc['km_depart'], 0, ',', ' ') . ').';
    }

    if (!$errors) {
        $db->beginTransaction();
        try {
            // 1. Terminer la location
            $db->prepare("
                UPDATE locations
                SET statut           = 'terminee',
                    km_retour        = ?,
                    carburant_retour = ?,
                    statut_caution   = ?,
                    notes            = CONCAT(COALESCE(notes,''), IF(? != '', CONCAT('\n[Retour] ', ?), '')),
                    updated_at       = NOW()
                WHERE id = ? AND tenant_id = ?
            ")->execute([$kmRetour ?: null, $carburantRetour, $statutCaution, $notesRetour, $notesRetour, $locationId, $tenantId]);

            // 2. Véhicule → disponible + km mise à jour
            $db->prepare("
                UPDATE vehicules SET statut = 'disponible', kilometrage_actuel = ?
                WHERE id = ? AND tenant_id = ? AND (? = 0 OR kilometrage_actuel < ?)
            ")->execute([$kmRetour ?: $loc['kilometrage_actuel'], $loc['vehicule_id'], $tenantId, $kmRetour, $kmRetour]);

            // 3. Paiement solde si renseigné
            if ($paiementFinale > 0) {
                $db->prepare("
                    INSERT INTO paiements (tenant_id, location_id, montant, mode_paiement, notes, created_at)
                    VALUES (?, ?, ?, ?, 'Paiement solde retour', NOW())
                ")->execute([$tenantId, $locationId, $paiementFinale, $modePaie]);
                // Recettes calculées dynamiquement via SUM(paiements) — plus de cumul dans recettes_initiales
                // Recalculer reste_a_payer et statut_paiement
                $stmtTotal = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM paiements WHERE location_id=? AND tenant_id=?");
                $stmtTotal->execute([$locationId, $tenantId]);
                $newTotal = (float)$stmtTotal->fetchColumn();
                $newReste = max(0, (float)$loc['montant_final'] - $newTotal);
                $newStat  = $newReste <= 0 ? 'solde' : ($newTotal > 0 ? 'avance' : 'non_paye');
                $db->prepare("UPDATE locations SET reste_a_payer=?, statut_paiement=? WHERE id=? AND tenant_id=?")
                   ->execute([$newReste, $newStat, $locationId, $tenantId]);
            }

            $db->commit();
            logActivite($db, 'UPDATE', 'locations', "Location #$locationId terminée. Km retour: {$kmRetour}");
            // ── Push notification ──────────────────────────────────────────
            $resteFinale = max(0, (float)$loc['montant_final'] - $totalPaye - $paiementFinale);
            $notifCorps  = sanitize($loc['veh_nom']) . ' — ' . sanitize($loc['client_nom'] . ' ' . $loc['client_prenom'])
                         . ($resteFinale > 0 ? ' · Reste dû : ' . formatMoney($resteFinale) : ' · Soldé');
            pushNotif($db, $tenantId, 'location', "🏁 Location #$locationId terminée", $notifCorps, BASE_URL . "app/locations/detail.php?id=$locationId");
            setFlash(FLASH_SUCCESS, 'Location terminée. Véhicule ' . sanitize($loc['veh_nom']) . ' remis disponible.');
            redirect(BASE_URL . 'app/locations/detail.php?id=' . $locationId);

        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    }
}

$pageTitle  = 'Retour véhicule — Location #' . $locationId;
$activePage = 'locations';
require_once BASE_PATH . '/includes/header.php';
?>

<style>
.ret-wrap{max-width:680px;margin:0 auto}
.ret-recap{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:20px}
.ret-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center}
.ret-kpi .rk-val{font-size:1.1rem;font-weight:700;margin-top:2px}
.ret-kpi .rk-lbl{font-size:.68rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em}
.ret-section{background:#fff;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:16px;overflow:hidden}
.ret-section-hd{padding:12px 18px;font-weight:700;font-size:.82rem;display:flex;align-items:center;gap:8px}
.ret-section-bd{padding:18px}
.ret-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.ret-alert-retard{background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:.85rem;color:#92400e}
.ret-alert-solde{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 16px;margin-bottom:14px;font-size:.82rem;color:#065f46}
</style>

<div class="ret-wrap">

    <div class="page-header" style="margin-bottom:20px">
        <a href="<?= BASE_URL ?>app/locations/detail.php?id=<?= $locationId ?>" class="btn btn-secondary btn-sm" style="margin-bottom:8px">
            <i class="fas fa-arrow-left"></i> Retour détail
        </a>
        <h1 class="page-title"><i class="fas fa-flag-checkered" style="color:#f59e0b"></i> Retour véhicule</h1>
        <p class="page-subtitle">
            <strong><?= sanitize($loc['veh_nom']) ?></strong> — <?= sanitize($loc['immatriculation']) ?>
            &nbsp;·&nbsp; <?= sanitize($loc['client_nom'] . ' ' . ($loc['client_prenom'] ?? '')) ?>
            &nbsp;·&nbsp; Location #<?= $locationId ?>
        </p>
    </div>

    <?= renderFlashes() ?>

    <?php if ($errors): ?>
    <div class="alert alert-error" style="margin-bottom:16px">
        <i class="fas fa-exclamation-circle"></i>
        <ul style="margin:4px 0 0 16px"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach ?></ul>
    </div>
    <?php endif ?>

    <?php if ($nbRetard > 0): ?>
    <div class="ret-alert-retard">
        <i class="fas fa-clock" style="font-size:1.2rem;color:#f59e0b"></i>
        <div>
            <strong>Retour tardif !</strong> Fin prévue le <?= formatDate($finPrevue) ?> — retard de <strong><?= $nbRetard ?> jour<?= $nbRetard > 1 ? 's' : '' ?></strong>.
            <?php if ($loc['prix_par_jour'] > 0): ?>
            Surcoût estimé : <strong style="color:#d97706"><?= formatMoney($nbRetard * (float)$loc['prix_par_jour']) ?></strong>
            <?php endif ?>
        </div>
    </div>
    <?php endif ?>

    <!-- KPIs récap -->
    <div class="ret-recap">
        <div class="ret-kpi">
            <div class="rk-lbl">Départ</div>
            <div class="rk-val" style="color:#0d9488"><?= formatDate($loc['date_debut']) ?></div>
        </div>
        <div class="ret-kpi">
            <div class="rk-lbl">Fin prévue</div>
            <div class="rk-val" style="color:<?= $nbRetard > 0 ? '#ef4444' : '#0f172a' ?>"><?= formatDate($loc['date_fin']) ?></div>
        </div>
        <div class="ret-kpi">
            <div class="rk-lbl">Montant total</div>
            <div class="rk-val" style="color:#0d9488"><?= formatMoney((float)$loc['montant_final']) ?></div>
        </div>
        <div class="ret-kpi">
            <div class="rk-lbl">Reste à payer</div>
            <div class="rk-val" style="color:<?= $resteApayer > 0 ? '#ef4444' : '#10b981' ?>">
                <?= $resteApayer > 0 ? formatMoney($resteApayer) : '<i class="fas fa-check"></i> Soldé' ?>
            </div>
        </div>
    </div>

    <?php if ($resteApayer <= 0): ?>
    <div class="ret-alert-solde">
        <i class="fas fa-check-circle" style="color:#10b981"></i>
        Location entièrement soldée. Aucun paiement requis au retour.
    </div>
    <?php endif ?>

    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="id" value="<?= $locationId ?>">

        <!-- Section kilométrage -->
        <div class="ret-section">
            <div class="ret-section-hd" style="background:#f0f4ff;border-bottom:1px solid #dbeafe">
                <i class="fas fa-tachometer-alt" style="color:#0d9488"></i>
                Kilométrage &amp; Carburant
            </div>
            <div class="ret-section-bd">
                <div class="ret-grid">
                    <div class="form-group">
                        <label class="form-label">Km départ (référence)</label>
                        <input type="text" class="form-control" value="<?= number_format((int)$loc['km_depart'], 0, ',', ' ') ?> km" readonly
                               style="background:#f8fafc;color:#64748b">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Km au retour</label>
                        <input type="number" name="km_retour" class="form-control"
                               min="<?= (int)$loc['km_depart'] ?>"
                               value="<?= (int)$loc['km_depart'] ?>"
                               placeholder="<?= (int)$loc['km_depart'] ?>">
                        <?php $kmParcourus = (int)$loc['km_depart']; ?>
                        <span class="form-hint">Km parcourus : <strong id="km-parcourus">0 km</strong></span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Jauge carburant départ</label>
                        <input type="text" class="form-control" value="<?= sanitize($loc['carburant_depart'] ?? 'Non renseigné') ?>" readonly
                               style="background:#f8fafc;color:#64748b">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Jauge carburant retour</label>
                        <select name="carburant_retour" class="form-control">
                            <option value="">— Non renseigné —</option>
                            <?php foreach (['vide'=>'Vide','1/4'=>'1/4','1/2'=>'Moitié (1/2)','3/4'=>'3/4','plein'=>'Plein'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= ($loc['carburant_depart'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section caution -->
        <div class="ret-section">
            <div class="ret-section-hd" style="background:#fef9ec;border-bottom:1px solid #fef3c7">
                <i class="fas fa-shield-halved" style="color:#d97706"></i>
                Caution
                <span style="margin-left:auto;font-size:.85rem;color:#d97706;font-weight:700"><?= formatMoney((float)$loc['caution']) ?></span>
            </div>
            <div class="ret-section-bd">
                <div class="ret-grid">
                    <div class="form-group">
                        <label class="form-label">Statut caution</label>
                        <select name="statut_caution" class="form-control">
                            <option value="rendue">✅ Rendue intégralement</option>
                            <option value="partielle">⚠️ Rendue partiellement</option>
                            <option value="retenue">❌ Retenue (dommages)</option>
                        </select>
                    </div>
                    <div class="form-group" style="align-self:end">
                        <div style="background:#fef3c7;border-radius:6px;padding:10px 14px;font-size:.8rem;color:#92400e">
                            <i class="fas fa-info-circle"></i>
                            Montant caution versé au départ. Notez l'état du véhicule ci-dessous en cas de retenue.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section paiement solde -->
        <?php if ($resteApayer > 0): ?>
        <div class="ret-section">
            <div class="ret-section-hd" style="background:#f0fdf4;border-bottom:1px solid #bbf7d0">
                <i class="fas fa-money-bill-wave" style="color:#10b981"></i>
                Paiement du solde
                <span style="margin-left:auto;font-size:.85rem;color:#ef4444;font-weight:700">Reste : <?= formatMoney($resteApayer) ?></span>
            </div>
            <div class="ret-section-bd">
                <div class="ret-grid">
                    <div class="form-group">
                        <label class="form-label">Montant encaissé maintenant (FCFA)</label>
                        <input type="number" name="paiement_finale" id="pmt-solde" class="form-control"
                               min="0" max="<?= $resteApayer ?>" step="1"
                               placeholder="0" value="<?= $resteApayer ?>">
                        <span class="form-hint">Laisser à 0 si paiement différé.</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mode de paiement</label>
                        <select name="mode_paiement" class="form-control">
                            <option value="especes">💵 Espèces</option>
                            <option value="mobile_money">📱 Mobile Money</option>
                            <option value="virement">🏦 Virement</option>
                            <option value="cheque">📝 Chèque</option>
                            <option value="carte">💳 Carte</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <input type="hidden" name="paiement_finale" value="0">
        <?php endif ?>

        <!-- Section état / notes -->
        <div class="ret-section">
            <div class="ret-section-hd" style="background:#f8fafc;border-bottom:1px solid #e2e8f0">
                <i class="fas fa-clipboard-list" style="color:#64748b"></i>
                État du véhicule &amp; Observations
            </div>
            <div class="ret-section-bd">
                <div class="form-group">
                    <label class="form-label">Notes de retour</label>
                    <textarea name="notes_retour" class="form-control" rows="3"
                              placeholder="État général du véhicule, dommages constatés, remarques sur le retour…"></textarea>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 0">
            <a href="<?= BASE_URL ?>app/locations/detail.php?id=<?= $locationId ?>" class="btn btn-secondary">
                <i class="fas fa-times"></i> Annuler
            </a>
            <button type="submit" class="btn btn-primary btn-lg"
                    onclick="return confirm('Confirmer le retour du véhicule ?\nLe statut passera à « Terminée » et le véhicule sera remis disponible.')">
                <i class="fas fa-flag-checkered"></i> Confirmer le retour
            </button>
        </div>

    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var kmDep  = <?= (int)$loc['km_depart'] ?>;
    var input  = document.querySelector('input[name="km_retour"]');
    var span   = document.getElementById('km-parcourus');
    function update() {
        var v = parseInt(input.value) || kmDep;
        var diff = Math.max(0, v - kmDep);
        span.textContent = diff.toLocaleString('fr-FR') + ' km';
        span.style.color = diff === 0 ? '#94a3b8' : '#0d9488';
    }
    if (input) { input.addEventListener('input', update); update(); }
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
