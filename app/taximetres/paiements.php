<?php
/**
 * FlotteCar — Saisie journalière des paiements taximantres (v2)
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
require_once BASE_PATH . '/models/TaximetreModel.php';
requireTenantAuth();

$db       = (new Database())->getConnection();
$tenantId = getTenantId();
$model    = new TaximetreModel($db);

// Date sélectionnée
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

// ── POST ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $dateSaisie = $_POST['date'] ?? $date;
    $lignes     = $_POST['lignes'] ?? [];
    $nbSaisis   = 0;

    foreach ($lignes as $taximetreId => $ligne) {
        $taximetreId = (int)$taximetreId;
        $statutJour  = $ligne['statut_jour']  ?? 'non_paye';
        $montant     = $statutJour === 'paye' ? (float)($ligne['montant'] ?? 0) : 0;
        $mode        = $ligne['mode_paiement'] ?? 'espece';
        $kmDebut     = ($ligne['km_debut'] ?? '') !== '' ? (int)$ligne['km_debut'] : null;
        $kmFin       = ($ligne['km_fin']   ?? '') !== '' ? (int)$ligne['km_fin']   : null;
        $notes       = trim($ligne['notes'] ?? '');

        $model->saisirJour($taximetreId, $tenantId, $dateSaisie, $statutJour, $montant, $mode, $kmDebut, $kmFin, $notes);
        $nbSaisis++;
    }

    setFlash(FLASH_SUCCESS, "$nbSaisis saisie" . ($nbSaisis > 1 ? 's' : '') . " enregistrée" . ($nbSaisis > 1 ? 's' : '') . " pour le " . formatDate($dateSaisie) . '.');
    logActivite($db, 'saisie_paiements_taxi', 'taximetres', "Saisie $nbSaisis paiements pour $dateSaisie");
    // ── Push notifications taxi ────────────────────────────────────────────────
    // Récupérer les données saisies pour analyse
    $stmtJour = $db->prepare("
        SELECT pt.statut_jour, pt.montant, t.nom, t.prenom, v.immatriculation
        FROM paiements_taxi pt
        JOIN taximetres t ON t.id = pt.taximetre_id
        LEFT JOIN vehicules v ON v.id = t.vehicule_id
        WHERE pt.tenant_id = ? AND pt.date_paiement = ?
    ");
    $stmtJour->execute([$tenantId, $dateSaisie]);
    $jourData    = $stmtJour->fetchAll(PDO::FETCH_ASSOC);
    $totalJour   = array_sum(array_column(array_filter($jourData, fn($r) => $r['statut_jour'] === 'paye'), 'montant'));
    $nbPayesJ    = count(array_filter($jourData, fn($r) => $r['statut_jour'] === 'paye'));
    $nonPayesLst = array_filter($jourData, fn($r) => $r['statut_jour'] === 'non_paye');
    // Résumé du jour
    if ($nbSaisis > 0) {
        pushNotif($db, $tenantId, 'taxi',
            "🚕 Saisie taxi du " . formatDate($dateSaisie),
            "$nbPayesJ payé(s) · " . formatMoney($totalJour) . " perçu" . (count($nonPayesLst) > 0 ? ' · ' . count($nonPayesLst) . ' non payé(s)' : ''),
            BASE_URL . "app/taximetres/paiements.php?date=$dateSaisie"
        );
    }
    // Alertes individuelles pour les non-payés
    foreach ($nonPayesLst as $np) {
        pushNotif($db, $tenantId, 'alerte',
            "⚠️ Impayé taxi — " . sanitize($np['nom'] . ' ' . ($np['prenom'] ?? '')),
            "N'a pas versé pour le " . formatDate($dateSaisie) . ($np['immatriculation'] ? ' · ' . $np['immatriculation'] : ''),
            BASE_URL . "app/taximetres/paiements.php?date=$dateSaisie"
        );
    }
    redirect(BASE_URL . 'app/taximetres/paiements.php?date=' . $dateSaisie);
}

// ── DONNÉES ────────────────────────────────────────────────────────────────────
$taximetres = array_filter($model->getAll($tenantId), fn($t) => $t['statut'] === 'actif');

// Paiements déjà saisis pour cette date
$stmtP = $db->prepare('SELECT taximetre_id, statut_jour, montant, mode_paiement, km_debut, km_fin, notes FROM paiements_taxi WHERE tenant_id = ? AND date_paiement = ?');
$stmtP->execute([$tenantId, $date]);
$paieMap = [];
foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $p) $paieMap[$p['taximetre_id']] = $p;

// Navigation
$dateObj  = new DateTime($date);
$datePrev = (clone $dateObj)->modify('-1 day')->format('Y-m-d');
$dateNext = (clone $dateObj)->modify('+1 day')->format('Y-m-d');
$isToday  = ($date === date('Y-m-d'));
$isFuture = ($date > date('Y-m-d'));

// Stats du jour
$nbPayes    = count(array_filter($paieMap, fn($p) => $p['statut_jour'] === 'paye'));
$nbNonPayes = count(array_filter($paieMap, fn($p) => $p['statut_jour'] === 'non_paye'));
$nbOff      = count(array_filter($paieMap, fn($p) => in_array($p['statut_jour'], ['jour_off','panne','accident'])));
$nbNonSaisi = count($taximetres) - count($paieMap);
$totalPercu = array_sum(array_column(array_filter($paieMap, fn($p) => $p['statut_jour'] === 'paye'), 'montant'));

$pageTitle  = 'Saisie paiements — ' . formatDate($date);
$activePage = 'taximetres';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
/* ── Saisie paiements taxi ──────────────────────────────── */
.sp-kpi-grid {
    display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin-bottom:14px;
}
.sp-kpi {
    background:#fff; border:1px solid #e2e8f0; border-radius:12px;
    padding:10px 12px; display:flex; align-items:center; gap:10px;
}
.sp-kpi-icon {
    width:36px; height:36px; border-radius:10px; display:flex;
    align-items:center; justify-content:center; font-size:.9rem; flex-shrink:0;
}
.sp-kpi-val { font-size:1.3rem; font-weight:800; line-height:1; }
.sp-kpi-lbl { font-size:.63rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin-top:2px; }

/* Day nav */
.sp-daynav {
    display:flex; align-items:center; gap:10px; background:#fff;
    border:1px solid #e2e8f0; border-radius:12px; padding:10px 14px;
    margin-bottom:14px;
}
.sp-daynav-center { flex:1; text-align:center; }
.sp-date-input {
    font-size:.95rem; font-weight:700; border:none; background:transparent;
    text-align:center; cursor:pointer; color:#0f172a; outline:none; width:auto;
}
.sp-today-badge {
    display:inline-block; background:#dbeafe; color:#1d4ed8;
    padding:2px 8px; border-radius:20px; font-size:.67rem; font-weight:700;
    vertical-align:middle; margin-left:6px;
}
.sp-nav-btn {
    display:inline-flex; align-items:center; justify-content:center;
    width:32px; height:32px; border-radius:9px; background:#f1f5f9;
    color:#475569; border:none; cursor:pointer; font-size:.8rem;
    text-decoration:none; transition:background .15s;
}
.sp-nav-btn:hover { background:#e2e8f0; }
.sp-nav-btn.disabled { opacity:.3; pointer-events:none; }

/* Quick buttons */
.qbtn {
    padding:4px 10px; border-radius:7px; font-size:.72rem; font-weight:700;
    border:1px solid transparent; cursor:pointer; transition:.15s;
    display:inline-flex; align-items:center; gap:4px;
}

/* Table */
.sp-table-wrap { display:block; }
.sp-cards { display:none; flex-direction:column; gap:10px; }

/* Table rows */
.saisie-row { transition:background .12s; }
.saisie-row:hover { background:#fafbfc; }
.saisie-row td { padding:8px 10px; vertical-align:middle; font-size:.82rem; border-bottom:1px solid #f1f5f9; }
.saisie-row.is-paye { background:rgba(16,185,129,.04); }
.saisie-row.is-off { background:#f8fafc; opacity:.72; }
.saisie-row.is-panne { background:rgba(245,158,11,.04); }
.saisie-row.is-non-saisi td:first-child { border-left:3px solid #f59e0b; }
.statut-sel { height:32px; font-size:.79rem; padding:0 6px; }
.montant-inp { height:32px; font-size:.82rem; font-weight:700; width:108px; }
.mode-sel { height:32px; font-size:.79rem; padding:0 6px; width:125px; }
.km-inp { height:32px; font-size:.78rem; width:76px; }

/* Mobile cards */
.sp-mcard {
    background:#fff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden;
}
.sp-mcard.mc-paye { border-left:4px solid #10b981; }
.sp-mcard.mc-non-paye { border-left:4px solid #ef4444; }
.sp-mcard.mc-off { border-left:4px solid #94a3b8; opacity:.8; }
.sp-mcard.mc-panne { border-left:4px solid #f59e0b; }
.sp-mcard.mc-accident { border-left:4px solid #7c3aed; }
.sp-mcard.mc-non-saisi { border-left:4px solid #f59e0b; }
.sp-mc-top { display:flex; align-items:center; gap:10px; padding:10px 12px; border-bottom:1px solid #f1f5f9; }
.sp-mc-avatar {
    width:36px; height:36px; border-radius:50%; background:#fef3c7; color:#d97706;
    display:flex; align-items:center; justify-content:center;
    font-size:.8rem; font-weight:800; flex-shrink:0;
}
.sp-mc-name { font-size:.85rem; font-weight:700; color:#0f172a; }
.sp-mc-sub { font-size:.7rem; color:#94a3b8; margin-top:1px; }
.sp-mc-tarif { margin-left:auto; font-size:.8rem; font-weight:700; color:#0d9488; white-space:nowrap; }
.sp-mc-body { padding:10px 12px; display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.sp-mc-field label { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; display:block; margin-bottom:3px; }
.sp-mc-statut { grid-column:1/-1; }
.sp-mc-km { display:grid; grid-template-columns:1fr 1fr; gap:8px; grid-column:1/-1; margin-top:2px; }
.sp-mc-notes { grid-column:1/-1; margin-top:2px; }

@media(max-width:768px) {
    .sp-kpi-grid { grid-template-columns:repeat(2,1fr); }
    .sp-kpi-grid .sp-kpi:last-child { grid-column:span 2; }
    .sp-table-wrap { display:none !important; }
    .sp-cards { display:flex !important; }
    .sp-daynav-quick { display:none !important; }
}
</style>

<!-- Header -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap">
    <a href="<?= BASE_URL ?>app/taximetres/liste.php" style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:9px;background:#f1f5f9;color:#475569;text-decoration:none;font-size:.8rem;flex-shrink:0">
        <i class="fas fa-arrow-left"></i>
    </a>
    <div>
        <h1 style="font-size:1.15rem;font-weight:800;color:#0f172a;margin:0"><i class="fas fa-coins" style="color:#d97706;margin-right:6px"></i>Saisie paiements taxi</h1>
        <p style="font-size:.73rem;color:#94a3b8;margin:2px 0 0"><?= formatDate($date) ?><?= $isToday ? ' · <strong style="color:#0d9488">Aujourd\'hui</strong>' : '' ?></p>
    </div>
</div>

<?= renderFlashes() ?>

<!-- KPIs du jour -->
<div class="sp-kpi-grid">
    <div class="sp-kpi">
        <div class="sp-kpi-icon" style="background:#d1fae5"><i class="fas fa-check-circle" style="color:#059669"></i></div>
        <div><div class="sp-kpi-val" style="color:#059669"><?= $nbPayes ?></div><div class="sp-kpi-lbl">Payés</div></div>
    </div>
    <div class="sp-kpi">
        <div class="sp-kpi-icon" style="background:#fee2e2"><i class="fas fa-times-circle" style="color:#dc2626"></i></div>
        <div><div class="sp-kpi-val" style="color:#dc2626"><?= $nbNonPayes ?></div><div class="sp-kpi-lbl">Non payés</div></div>
    </div>
    <div class="sp-kpi">
        <div class="sp-kpi-icon" style="background:#f1f5f9"><i class="fas fa-moon" style="color:#64748b"></i></div>
        <div><div class="sp-kpi-val" style="color:#64748b"><?= $nbOff ?></div><div class="sp-kpi-lbl">Off/Panne</div></div>
    </div>
    <div class="sp-kpi">
        <div class="sp-kpi-icon" style="background:#fef3c7"><i class="fas fa-hourglass-half" style="color:#d97706"></i></div>
        <div><div class="sp-kpi-val" style="color:#d97706"><?= $nbNonSaisi ?></div><div class="sp-kpi-lbl">Non saisis</div></div>
    </div>
    <div class="sp-kpi">
        <div class="sp-kpi-icon" style="background:#dbeafe"><i class="fas fa-coins" style="color:#1d4ed8"></i></div>
        <div><div class="sp-kpi-val" style="color:#1d4ed8;font-size:1rem"><?= formatMoney($totalPercu) ?></div><div class="sp-kpi-lbl">Perçu</div></div>
    </div>
</div>

<!-- Navigation date -->
<div class="sp-daynav">
    <a href="?date=<?= $datePrev ?>" class="sp-nav-btn" title="Jour précédent"><i class="fas fa-chevron-left"></i></a>
    <div class="sp-daynav-center">
        <input type="date" class="sp-date-input" value="<?= $date ?>" onchange="window.location='?date='+this.value">
        <?php if ($isToday): ?><span class="sp-today-badge">Aujourd'hui</span><?php endif ?>
    </div>
    <a href="?date=<?= $dateNext ?>" class="sp-nav-btn <?= $dateNext > date('Y-m-d') ? 'disabled' : '' ?>" title="Jour suivant"><i class="fas fa-chevron-right"></i></a>
</div>

<?php if (empty($taximetres)): ?>
<div class="card" style="text-align:center;padding:40px">
    <i class="fas fa-taxi" style="font-size:2.5rem;color:#94a3b8;display:block;margin-bottom:10px"></i>
    <p style="color:#64748b">Aucun taximantre actif.</p>
    <a href="<?= BASE_URL ?>app/taximetres/liste.php" class="btn btn-primary btn-sm">Gérer les taximantres</a>
</div>
<?php else: ?>
<form method="POST" id="paie-form">
    <?= csrfField() ?>
    <input type="hidden" name="date" value="<?= sanitize($date) ?>">

    <!-- ── DESKTOP TABLE ── -->
    <div class="sp-table-wrap card">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #e2e8f0;flex-wrap:wrap;gap:8px">
            <span style="font-size:.85rem;font-weight:700;color:#0f172a">
                <i class="fas fa-list-check" style="color:#0d9488;margin-right:4px"></i>
                <?= count($taximetres) ?> taximantre<?= count($taximetres) > 1 ? 's' : '' ?> actif<?= count($taximetres) > 1 ? 's' : '' ?>
            </span>
            <div class="sp-daynav-quick" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                <span style="font-size:.7rem;color:#94a3b8">Tout :</span>
                <button type="button" class="qbtn" onclick="setAll('paye')" style="background:#d1fae5;color:#059669;border-color:#a7f3d0"><i class="fas fa-check"></i> Payé</button>
                <button type="button" class="qbtn" onclick="setAll('non_paye')" style="background:#fee2e2;color:#dc2626;border-color:#fca5a5"><i class="fas fa-times"></i> Non payé</button>
                <button type="button" class="qbtn" onclick="setAll('jour_off')" style="background:#f1f5f9;color:#64748b;border-color:#cbd5e1"><i class="fas fa-moon"></i> Off</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table" style="font-size:.81rem">
                <thead>
                    <tr style="background:#f8fafc">
                        <th style="padding:7px 10px;font-size:.63rem;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;white-space:nowrap">Taximantre</th>
                        <th style="padding:7px 10px;font-size:.63rem;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8">Véhicule</th>
                        <th style="padding:7px 10px;font-size:.63rem;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;text-align:right">Tarif/j</th>
                        <th style="padding:7px 10px;font-size:.63rem;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;min-width:140px">Statut *</th>
                        <th style="padding:7px 10px;font-size:.63rem;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;min-width:115px">Montant</th>
                        <th style="padding:7px 10px;font-size:.63rem;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;min-width:125px">Mode</th>
                        <th style="padding:7px 10px;font-size:.63rem;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;text-align:right">Km déb.</th>
                        <th style="padding:7px 10px;font-size:.63rem;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;text-align:right">Km fin</th>
                        <th style="padding:7px 10px;font-size:.63rem;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8">Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($taximetres as $t):
                    $tid    = $t['id'];
                    $paie   = $paieMap[$tid] ?? null;
                    $statut = $paie['statut_jour'] ?? '';
                    $isSaisi = $paie !== null;
                    $savedMode = $paie['mode_paiement'] ?? 'espece';

                    $rowClass = '';
                    if ($statut === 'paye')     $rowClass = 'is-paye';
                    elseif (in_array($statut, ['jour_off','accident'])) $rowClass = 'is-off';
                    elseif ($statut === 'panne') $rowClass = 'is-panne';
                    elseif (!$isSaisi)           $rowClass = 'is-non-saisi';
                ?>
                <tr class="saisie-row desk-row <?= $rowClass ?>" data-tarif="<?= (float)$t['tarif_journalier'] ?>" data-tid="<?= $tid ?>">
                    <td>
                        <div style="display:flex;align-items:center;gap:8px">
                            <div style="width:30px;height:30px;border-radius:50%;background:#fef3c7;color:#d97706;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;flex-shrink:0">
                                <?= mb_strtoupper(mb_substr($t['nom'],0,1).mb_substr($t['prenom']??'',0,1)) ?>
                            </div>
                            <div>
                                <div style="font-weight:700;font-size:.81rem"><?= sanitize($t['nom'].' '.($t['prenom']??'')) ?></div>
                                <?php if ($t['telephone']): ?><div style="font-size:.66rem;color:#94a3b8"><?= sanitize($t['telephone']) ?></div><?php endif ?>
                            </div>
                            <?php if (!$isSaisi): ?>
                            <span style="background:#fef3c7;color:#d97706;padding:1px 5px;border-radius:4px;font-size:.6rem;font-weight:700">Attente</span>
                            <?php elseif ($statut === 'paye'): ?>
                            <i class="fas fa-check-circle" style="color:#10b981;font-size:.8rem"></i>
                            <?php endif ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight:600;font-size:.78rem"><?= sanitize($t['vehicule_nom'] ?? $t['veh_nom'] ?? '—') ?></div>
                        <div style="font-size:.66rem;color:#94a3b8"><?= sanitize($t['immatriculation'] ?? '') ?></div>
                    </td>
                    <td style="text-align:right;font-weight:700"><?= formatMoney((float)$t['tarif_journalier']) ?></td>
                    <td>
                        <select name="lignes[<?= $tid ?>][statut_jour]" class="form-control statut-sel desk-statut-select" data-tid="<?= $tid ?>" onchange="onStatutChange(this,<?= $tid ?>,'desk')">
                            <option value="" <?= $statut==='' ? 'selected':'' ?> disabled>— Choisir —</option>
                            <option value="paye"      <?= $statut==='paye'      ? 'selected':'' ?>>✅ Payé</option>
                            <option value="non_paye"  <?= $statut==='non_paye'  ? 'selected':'' ?>>❌ Non payé</option>
                            <option value="jour_off"  <?= $statut==='jour_off'  ? 'selected':'' ?>>🌙 Jour off</option>
                            <option value="panne"     <?= $statut==='panne'     ? 'selected':'' ?>>🔧 Panne</option>
                            <option value="accident"  <?= $statut==='accident'  ? 'selected':'' ?>>🚨 Accident</option>
                        </select>
                    </td>
                    <td>
                        <input type="number" name="lignes[<?= $tid ?>][montant]" id="montant-<?= $tid ?>"
                               class="form-control montant-inp"
                               value="<?= $statut==='paye' ? (float)($paie['montant']??$t['tarif_journalier']) : '' ?>"
                               min="0" step="500" placeholder="<?= (int)$t['tarif_journalier'] ?>"
                               <?= $statut!=='paye' ? 'disabled':'' ?>>
                    </td>
                    <td>
                        <select name="lignes[<?= $tid ?>][mode_paiement]" id="mode-<?= $tid ?>"
                                class="form-control mode-sel" <?= $statut!=='paye' ? 'disabled':'' ?>>
                            <option value="especes"      <?= $savedMode==='especes'      ? 'selected':'' ?>>💵 Espèces</option>
                            <option value="mobile_money" <?= $savedMode==='mobile_money' ? 'selected':'' ?>>📱 Mobile Money</option>
                            <option value="virement"     <?= $savedMode==='virement'     ? 'selected':'' ?>>🏦 Virement</option>
                        </select>
                    </td>
                    <td><input type="number" name="lignes[<?= $tid ?>][km_debut]" class="form-control km-inp" value="<?= sanitize((string)($paie['km_debut']??'')) ?>" placeholder="km" min="0" style="text-align:right"></td>
                    <td><input type="number" name="lignes[<?= $tid ?>][km_fin]" class="form-control km-inp" value="<?= sanitize((string)($paie['km_fin']??'')) ?>" placeholder="km" min="0" style="text-align:right"></td>
                    <td><input type="text" name="lignes[<?= $tid ?>][notes]" class="form-control" value="<?= sanitize($paie['notes']??'') ?>" placeholder="Notes…" style="font-size:.77rem;height:32px;min-width:90px"></td>
                </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <div style="padding:12px 14px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
            <span style="font-size:.77rem;color:#94a3b8"><i class="fas fa-lightbulb"></i> Utilisez "Tout :" pour pré-remplir, puis ajustez ligne par ligne.</span>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer tous</button>
        </div>
    </div>

    <!-- ── MOBILE CARDS ── -->
    <div class="sp-cards">
        <!-- Quick actions on mobile -->
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:4px">
            <span style="font-size:.72rem;color:#94a3b8;align-self:center">Tout :</span>
            <button type="button" class="qbtn" onclick="setAll('paye')" style="background:#d1fae5;color:#059669;border-color:#a7f3d0"><i class="fas fa-check"></i> Payé</button>
            <button type="button" class="qbtn" onclick="setAll('non_paye')" style="background:#fee2e2;color:#dc2626;border-color:#fca5a5"><i class="fas fa-times"></i> Non payé</button>
            <button type="button" class="qbtn" onclick="setAll('jour_off')" style="background:#f1f5f9;color:#64748b;border-color:#cbd5e1"><i class="fas fa-moon"></i> Off</button>
        </div>

        <?php foreach ($taximetres as $t):
            $tid    = $t['id'];
            $paie   = $paieMap[$tid] ?? null;
            $statut = $paie['statut_jour'] ?? '';
            $isSaisi = $paie !== null;
            $savedMode = $paie['mode_paiement'] ?? 'espece';

            $mcClass = '';
            if ($statut === 'paye')     $mcClass = 'mc-paye';
            elseif ($statut === 'non_paye') $mcClass = 'mc-non-paye';
            elseif ($statut === 'jour_off') $mcClass = 'mc-off';
            elseif ($statut === 'panne')    $mcClass = 'mc-panne';
            elseif ($statut === 'accident') $mcClass = 'mc-accident';
            elseif (!$isSaisi)              $mcClass = 'mc-non-saisi';
        ?>
        <div class="sp-mcard <?= $mcClass ?>" id="mcard-<?= $tid ?>" data-tarif="<?= (float)$t['tarif_journalier'] ?>" data-tid="<?= $tid ?>">
            <div class="sp-mc-top">
                <div class="sp-mc-avatar"><?= mb_strtoupper(mb_substr($t['nom'],0,1).mb_substr($t['prenom']??'',0,1)) ?></div>
                <div>
                    <div class="sp-mc-name"><?= sanitize($t['nom'].' '.($t['prenom']??'')) ?></div>
                    <div class="sp-mc-sub"><?= sanitize($t['vehicule_nom']??$t['veh_nom']??'') ?><?= $t['immatriculation'] ? ' · '.sanitize($t['immatriculation']) : '' ?></div>
                </div>
                <div class="sp-mc-tarif"><?= formatMoney((float)$t['tarif_journalier']) ?>/j</div>
            </div>
            <div class="sp-mc-body">
                <!-- Statut -->
                <div class="sp-mc-field sp-mc-statut">
                    <label>Statut du jour</label>
                    <select name="lignes[<?= $tid ?>][statut_jour]" class="form-control mob-statut-select" data-tid="<?= $tid ?>" onchange="onStatutChange(this,<?= $tid ?>,'mob')" style="height:38px;font-size:.82rem">
                        <option value="" <?= $statut==='' ? 'selected':'' ?> disabled>— Choisir le statut —</option>
                        <option value="paye"      <?= $statut==='paye'      ? 'selected':'' ?>>✅ Payé</option>
                        <option value="non_paye"  <?= $statut==='non_paye'  ? 'selected':'' ?>>❌ Non payé</option>
                        <option value="jour_off"  <?= $statut==='jour_off'  ? 'selected':'' ?>>🌙 Jour off</option>
                        <option value="panne"     <?= $statut==='panne'     ? 'selected':'' ?>>🔧 Panne</option>
                        <option value="accident"  <?= $statut==='accident'  ? 'selected':'' ?>>🚨 Accident</option>
                    </select>
                </div>
                <!-- Montant + Mode -->
                <div id="mob-pay-<?= $tid ?>" style="<?= $statut!=='paye' ? 'display:none;':'' ?>grid-column:1/-1;display:<?= $statut==='paye'?'grid':'none' ?>;grid-template-columns:1fr 1fr;gap:8px">
                    <div class="sp-mc-field">
                        <label>Montant reçu</label>
                        <input type="number" name="lignes[<?= $tid ?>][montant]" id="mob-montant-<?= $tid ?>"
                               class="form-control" value="<?= $statut==='paye' ? (float)($paie['montant']??$t['tarif_journalier']) : '' ?>"
                               min="0" step="500" placeholder="<?= (int)$t['tarif_journalier'] ?>"
                               style="height:36px;font-size:.85rem;font-weight:700"
                               <?= $statut!=='paye' ? 'disabled':'' ?>>
                    </div>
                    <div class="sp-mc-field">
                        <label>Mode</label>
                        <select name="lignes[<?= $tid ?>][mode_paiement]" id="mob-mode-<?= $tid ?>"
                                class="form-control" style="height:36px;font-size:.82rem"
                                <?= $statut!=='paye' ? 'disabled':'' ?>>
                            <option value="especes"      <?= $savedMode==='especes'      ? 'selected':'' ?>>💵 Espèces</option>
                            <option value="mobile_money" <?= $savedMode==='mobile_money' ? 'selected':'' ?>>📱 Mobile Money</option>
                            <option value="virement"     <?= $savedMode==='virement'     ? 'selected':'' ?>>🏦 Virement</option>
                        </select>
                    </div>
                </div>
                <!-- Km + Notes -->
                <div class="sp-mc-km">
                    <div class="sp-mc-field">
                        <label>Km début</label>
                        <input type="number" name="lignes[<?= $tid ?>][km_debut]" class="form-control"
                               value="<?= sanitize((string)($paie['km_debut']??'')) ?>" placeholder="km" min="0"
                               style="height:34px;font-size:.8rem">
                    </div>
                    <div class="sp-mc-field">
                        <label>Km fin</label>
                        <input type="number" name="lignes[<?= $tid ?>][km_fin]" class="form-control"
                               value="<?= sanitize((string)($paie['km_fin']??'')) ?>" placeholder="km" min="0"
                               style="height:34px;font-size:.8rem">
                    </div>
                </div>
                <div class="sp-mc-notes sp-mc-field">
                    <label>Notes</label>
                    <input type="text" name="lignes[<?= $tid ?>][notes]" class="form-control"
                           value="<?= sanitize($paie['notes']??'') ?>" placeholder="Observations…"
                           style="height:34px;font-size:.8rem">
                </div>
            </div>
        </div>
        <?php endforeach ?>

        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:4px">
            <i class="fas fa-save"></i> Enregistrer tous les paiements
        </button>
    </div>
</form>
<?php endif ?>

<script>
function onStatutChange(select, tid, ctx) {
    const isPaye = select.value === 'paye';
    const tarif  = parseFloat(select.closest('[data-tarif]').dataset.tarif) || 0;

    if (ctx === 'desk') {
        const row  = select.closest('tr');
        const m    = document.getElementById('montant-' + tid);
        const mode = document.getElementById('mode-' + tid);
        m.disabled = mode.disabled = !isPaye;
        if (isPaye && !m.value) m.value = tarif;
        if (!isPaye) m.value = '';
        const cls = isPaye ? 'is-paye' : select.value==='panne' ? 'is-panne' : select.value==='non_paye' ? '' : 'is-off';
        row.className = row.className.replace(/is-\w+/g,'') + (cls ? ' '+cls : '');
    } else {
        // Mobile card
        const card   = select.closest('.sp-mcard');
        const wrap   = document.getElementById('mob-pay-' + tid);
        const m      = document.getElementById('mob-montant-' + tid);
        const mode   = document.getElementById('mob-mode-' + tid);
        wrap.style.display = isPaye ? 'grid' : 'none';
        m.disabled = mode.disabled = !isPaye;
        if (isPaye && !m.value) m.value = tarif;
        if (!isPaye) m.value = '';
        // Update card border class
        card.classList.remove('mc-paye','mc-non-paye','mc-off','mc-panne','mc-accident','mc-non-saisi');
        const mcMap = {paye:'mc-paye',non_paye:'mc-non-paye',jour_off:'mc-off',panne:'mc-panne',accident:'mc-accident'};
        if (mcMap[select.value]) card.classList.add(mcMap[select.value]);
    }
}

function setAll(statut) {
    // Desk
    document.querySelectorAll('.desk-statut-select').forEach(sel => {
        sel.value = statut; onStatutChange(sel, sel.dataset.tid, 'desk');
    });
    // Mobile
    document.querySelectorAll('.mob-statut-select').forEach(sel => {
        sel.value = statut; onStatutChange(sel, sel.dataset.tid, 'mob');
    });
}

// Before submit: disable inputs in hidden view to avoid duplicate POST values
document.getElementById('paie-form').addEventListener('submit', function() {
    const isMobile = window.innerWidth <= 768;
    // Disable table inputs on mobile, mobile card inputs on desktop
    document.querySelectorAll('.sp-table-wrap input, .sp-table-wrap select').forEach(el => {
        if (isMobile) el.disabled = true;
    });
    document.querySelectorAll('.sp-cards input, .sp-cards select').forEach(el => {
        if (!isMobile) el.disabled = true;
    });
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
