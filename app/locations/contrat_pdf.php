<?php
/**
 * FlotteCar — Contrat de location PDF (4 pages A4)
 * Utilise dompdf
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

require_once BASE_PATH . '/dompdf/autoload.inc.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$db       = (new Database())->getConnection();
$tenantId = getTenantId();

$locationId = (int)($_GET['id'] ?? 0);
if (!$locationId) die('Identifiant invalide.');

// ── Données ────────────────────────────────────────────────────────────────────
$stmtLoc = $db->prepare("
    SELECT l.*,
           v.nom AS veh_nom, v.immatriculation, v.marque, v.modele,
           v.couleur, v.carburant_type, v.annee, v.numero_chassis,
           c.nom AS client_nom, c.prenom AS client_prenom,
           c.telephone AS client_tel, c.email AS client_email,
           c.adresse AS client_adresse, c.numero_piece AS client_cin,
           c.type_piece AS client_type_piece,
           TRIM(CONCAT(ch.nom, ' ', COALESCE(ch.prenom,''))) AS chauffeur_nom,
           ch.telephone AS chauffeur_tel, ch.numero_permis AS chauffeur_permis,
           com.nom AS commercial_nom,
           t.nom_entreprise AS tenant_nom, t.telephone AS tenant_tel,
           t.email AS tenant_email, t.adresse AS tenant_adresse,
           t.logo AS tenant_logo
    FROM   locations l
    JOIN   vehicules   v   ON v.id  = l.vehicule_id
    JOIN   clients     c   ON c.id  = l.client_id
    LEFT JOIN chauffeurs ch  ON ch.id = l.chauffeur_id
    LEFT JOIN commerciaux com ON com.id = l.commercial_id
    JOIN   tenants     t   ON t.id  = l.tenant_id
    WHERE  l.id = ? AND l.tenant_id = ?
");
$stmtLoc->execute([$locationId, $tenantId]);
$loc = $stmtLoc->fetch(PDO::FETCH_ASSOC);
if (!$loc) die('Location introuvable.');

// Paiements (pas de colonne statut)
$stmtPay = $db->prepare("
    SELECT * FROM paiements
    WHERE location_id = ? AND tenant_id = ?
    ORDER BY created_at ASC
");
$stmtPay->execute([$locationId, $tenantId]);
$paiements = $stmtPay->fetchAll(PDO::FETCH_ASSOC);
$totalPaye = array_sum(array_column($paiements, 'montant'));

// Logo tenant
$logoBase64 = '';
$logoPath = BASE_PATH . '/uploads/logos/' . ($loc['tenant_logo'] ?? '');
if ($loc['tenant_logo'] && file_exists($logoPath)) {
    $ext  = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
    $mime = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : 'image/png';
    $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoPath));
}

// Helpers
$c  = '#0d9488'; // couleur principale
$cL = '#dbeafe'; // bleu clair
$g  = '#10b981'; // vert
$gL = '#d1fae5'; // vert clair
$r  = '#ef4444'; // rouge
$rL = '#fee2e2'; // rouge clair
$y  = '#f59e0b'; // jaune
$yL = '#fef3c7'; // jaune clair

$e = fn($s) => htmlspecialchars($s ?? '', ENT_QUOTES);
$m = fn($v) => number_format((float)$v, 0, ',', ' ') . ' FCFA';
$d = fn($s) => $s ? date('d/m/Y', strtotime($s)) : '—';
$dt= fn($s) => $s ? date('d/m/Y H:i', strtotime($s)) : '—';

$numContrat = 'CTR-' . str_pad($locationId, 6, '0', STR_PAD_LEFT);
$dateEdition= date('d/m/Y à H:i');
$statutLabel= ['en_cours'=>'EN COURS','terminee'=>'TERMINÉE','annulee'=>'ANNULÉE'];
$statutCoul = ['en_cours'=>[$cL,$c],'terminee'=>[$gL,$g],'annulee'=>[$rL,$r]];
[$sbg,$scol] = $statutCoul[$loc['statut']] ?? [$cL,$c];
$payLabel   = ['solde'=>'SOLDÉ','avance'=>'AVANCE VERSÉE','non_paye'=>'NON PAYÉ'];
[$pbg,$pcol]= match($loc['statut_paiement']) {
    'solde'    => [$gL,$g],
    'avance'   => [$yL,$y],
    default    => [$rL,$r],
};

// ── HTML PDF ──────────────────────────────────────────────────────────────────
ob_start(); ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
/* ── Reset ────────────────────────────────────────────────────── */
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:"DejaVu Sans",Arial,sans-serif; font-size:9.5px; color:#0f172a; line-height:1.55; }

/* ── En-tête & Pied de page ───────────────────────────────────── */
.page-header {
    position:fixed; top:0; left:0; right:0; height:70px;
    background:<?= $c ?>; color:#fff;
    padding:0 24px;
    display:table; width:100%;
}
.page-header td { vertical-align:middle; }
.ph-left  { width:50%; }
.ph-right { width:50%; text-align:right; font-size:8.5px; }
.ph-logo  { max-height:40px; max-width:130px; }
.ph-company { font-size:8px; opacity:.88; line-height:1.4; margin-top:2px; }

.page-footer {
    position:fixed; bottom:0; left:0; right:0; height:22px;
    border-top:2px solid <?= $c ?>; background:#f8fafc;
    font-size:7.5px; color:#94a3b8; text-align:center;
    padding-top:4px;
}

.content { margin-top:82px; margin-bottom:32px; padding:0 24px; }

/* ── Sections ────────────────────────────────────────────────── */
.section { margin-bottom:14px; }
.section-title {
    background:<?= $c ?>; color:#fff;
    padding:5px 10px; font-size:8.5px; font-weight:bold;
    letter-spacing:.06em; text-transform:uppercase;
    border-radius:3px 3px 0 0;
}
.section-body { border:1px solid #e2e8f0; border-top:none; border-radius:0 0 4px 4px; padding:10px; }

/* ── Tables info ─────────────────────────────────────────────── */
table { border-collapse:collapse; }
table.info { width:100%; }
table.info td { padding:4px 8px; border:1px solid #e2e8f0; vertical-align:top; font-size:9px; }
table.info td.lbl { background:#f8fafc; font-weight:bold; width:40%; color:#64748b; }
table.info td.val { color:#0f172a; }
table.info tr:last-child td { border-bottom:1px solid #e2e8f0; }

/* ── Layout 2 colonnes ────────────────────────────────────────── */
table.cols2 { width:100%; border-collapse:separate; border-spacing:10px 0; }
table.cols2 td { vertical-align:top; width:50%; }

/* ── Badges ─────────────────────────────────────────────────── */
.badge { display:inline-block; padding:2px 8px; border-radius:3px; font-size:8px; font-weight:bold; }

/* ── Montant final ───────────────────────────────────────────── */
.montant-box {
    background:<?= $cL ?>; border:2px solid <?= $c ?>;
    border-radius:5px; padding:10px 14px; margin:10px 0;
    display:table; width:100%;
}
.montant-box td { vertical-align:middle; }
.montant-lbl { font-size:9px; color:<?= $c ?>; font-weight:bold; text-transform:uppercase; }
.montant-val { font-size:16px; font-weight:bold; color:<?= $c ?>; text-align:right; }

/* ── Tableau paiements ────────────────────────────────────────── */
table.pays { width:100%; }
table.pays th { background:<?= $c ?>; color:#fff; padding:5px 8px; font-size:8.5px; text-align:left; }
table.pays td { padding:4px 8px; border-bottom:1px solid #e2e8f0; font-size:9px; }
table.pays tr:nth-child(even) td { background:#f8fafc; }
table.pays tfoot td { background:<?= $cL ?>; font-weight:bold; border-top:2px solid <?= $c ?>; }

/* ── Couverture (page 1) ────────────────────────────────────── */
.cover-box {
    background:<?= $cL ?>; border:2px solid <?= $c ?>;
    border-radius:6px; padding:18px 22px; margin-bottom:16px;
    text-align:center;
}
.cover-num { font-size:20px; font-weight:bold; color:<?= $c ?>; }
.cover-sub { font-size:9.5px; color:#64748b; margin-top:4px; }

/* ── Conditions générales ───────────────────────────────────── */
.cg { font-size:8px; color:#475569; line-height:1.6; }
.cg p { margin-bottom:6px; }
.cg strong { color:#0f172a; }

/* ── Signatures ─────────────────────────────────────────────── */
table.sigs { width:100%; margin-top:20px; }
table.sigs td { width:46%; padding:10px 12px; border:1px solid #e2e8f0; border-radius:4px; font-size:8.5px; text-align:center; }
table.sigs td.spacer { width:8%; border:none; }
.sig-zone { height:55px; border-bottom:1px solid #94a3b8; margin:8px 0 4px; }

/* ── Page break ─────────────────────────────────────────────── */
.pb { page-break-before:always; }
</style>
</head>
<body>

<!-- En-tête fixe toutes pages -->
<div class="page-header">
    <table style="width:100%;height:70px">
        <tr>
            <td class="ph-left">
                <?php if ($logoBase64): ?>
                <img src="<?= $logoBase64 ?>" class="ph-logo" alt="Logo">
                <?php else: ?>
                <span style="font-size:16px;font-weight:bold">🚗 <?= $e($loc['tenant_nom']) ?></span>
                <?php endif ?>
                <div class="ph-company">
                    <?= $e($loc['tenant_nom']) ?><br>
                    <?= $e($loc['tenant_tel'] ?? '') ?> <?= $loc['tenant_email'] ? '· ' . $e($loc['tenant_email']) : '' ?>
                    <?php if ($loc['tenant_adresse']): ?><br><?= $e($loc['tenant_adresse']) ?><?php endif ?>
                </div>
            </td>
            <td class="ph-right">
                <span style="font-size:11px;font-weight:bold"><?= $numContrat ?></span><br>
                Établi le <?= $dateEdition ?><br>
                <span class="badge" style="background:<?= $sbg ?>;color:<?= $scol ?>;margin-top:3px">
                    <?= $statutLabel[$loc['statut']] ?? strtoupper($loc['statut']) ?>
                </span>
            </td>
        </tr>
    </table>
</div>

<!-- Pied de page fixe -->
<div class="page-footer">
    <?= $e($loc['tenant_nom']) ?> — Contrat N° <?= $numContrat ?> — Document généré le <?= $dateEdition ?> par FlotteCar
</div>

<!-- ════════════════════════════════════════════════════════════
     PAGE 1 — COUVERTURE + PARTIES
     ════════════════════════════════════════════════════════════ -->
<div class="content">

    <!-- Titre -->
    <div class="cover-box" style="margin-top:4px">
        <div style="font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:6px">Document officiel</div>
        <div class="cover-num">CONTRAT DE LOCATION DE VÉHICULE</div>
        <div class="cover-sub">N° <?= $numContrat ?> &nbsp;|&nbsp; Établi le <?= $dateEdition ?></div>
        <div style="margin-top:10px;display:inline-block">
            <span class="badge" style="background:<?= $sbg ?>;color:<?= $scol ?>;font-size:9px;padding:3px 10px">
                Statut : <?= $statutLabel[$loc['statut']] ?? strtoupper($loc['statut']) ?>
            </span>
            &nbsp;
            <span class="badge" style="background:<?= $pbg ?>;color:<?= $pcol ?>;font-size:9px;padding:3px 10px">
                Paiement : <?= $payLabel[$loc['statut_paiement']] ?? strtoupper($loc['statut_paiement']) ?>
            </span>
        </div>
    </div>

    <!-- Loueur + Locataire côte à côte -->
    <table class="cols2"><tr>

        <!-- Loueur -->
        <td>
            <div class="section">
                <div class="section-title">🏢 LE LOUEUR (BAILLEUR)</div>
                <div class="section-body">
                    <table class="info">
                        <tr><td class="lbl">Société</td><td class="val"><strong><?= $e($loc['tenant_nom']) ?></strong></td></tr>
                        <?php if ($loc['tenant_tel']): ?>
                        <tr><td class="lbl">Téléphone</td><td class="val"><?= $e($loc['tenant_tel']) ?></td></tr>
                        <?php endif ?>
                        <?php if ($loc['tenant_email']): ?>
                        <tr><td class="lbl">Email</td><td class="val"><?= $e($loc['tenant_email']) ?></td></tr>
                        <?php endif ?>
                        <?php if ($loc['tenant_adresse']): ?>
                        <tr><td class="lbl">Adresse</td><td class="val"><?= $e($loc['tenant_adresse']) ?></td></tr>
                        <?php endif ?>
                    </table>
                </div>
            </div>
        </td>

        <!-- Locataire -->
        <td>
            <div class="section">
                <div class="section-title">👤 LE LOCATAIRE</div>
                <div class="section-body">
                    <table class="info">
                        <tr><td class="lbl">Nom complet</td><td class="val"><strong><?= $e($loc['client_nom'] . ' ' . ($loc['client_prenom'] ?? '')) ?></strong></td></tr>
                        <?php if ($loc['client_tel']): ?>
                        <tr><td class="lbl">Téléphone</td><td class="val"><?= $e($loc['client_tel']) ?></td></tr>
                        <?php endif ?>
                        <?php if ($loc['client_email']): ?>
                        <tr><td class="lbl">Email</td><td class="val"><?= $e($loc['client_email']) ?></td></tr>
                        <?php endif ?>
                        <?php if ($loc['client_adresse']): ?>
                        <tr><td class="lbl">Adresse</td><td class="val"><?= $e($loc['client_adresse']) ?></td></tr>
                        <?php endif ?>
                        <?php if ($loc['client_cin']): ?>
                        <tr><td class="lbl"><?= $e($loc['client_type_piece'] ?? 'Pièce') ?></td><td class="val"><?= $e($loc['client_cin']) ?></td></tr>
                        <?php endif ?>
                    </table>
                </div>
            </div>
        </td>

    </tr></table>

    <!-- Chauffeur si applicable -->
    <?php if ($loc['chauffeur_nom'] && trim($loc['chauffeur_nom'])): ?>
    <div class="section">
        <div class="section-title">🏎️ CHAUFFEUR DÉSIGNÉ</div>
        <div class="section-body">
            <table class="info" style="width:50%">
                <tr><td class="lbl">Nom</td><td class="val"><strong><?= $e(trim($loc['chauffeur_nom'])) ?></strong></td></tr>
                <?php if ($loc['chauffeur_tel']): ?>
                <tr><td class="lbl">Téléphone</td><td class="val"><?= $e($loc['chauffeur_tel']) ?></td></tr>
                <?php endif ?>
                <?php if ($loc['chauffeur_permis']): ?>
                <tr><td class="lbl">N° Permis</td><td class="val"><?= $e($loc['chauffeur_permis']) ?></td></tr>
                <?php endif ?>
            </table>
        </div>
    </div>
    <?php endif ?>

    <!-- Déclaration de consentement -->
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:5px;padding:12px 14px;font-size:8.5px;color:#475569;margin-top:8px">
        <strong style="color:#0f172a">DÉCLARATION DES PARTIES :</strong>
        Les parties susmentionnées ont convenu de conclure le présent contrat de location de véhicule aux conditions ci-après définies.
        Le loueur certifie que le véhicule est en bon état de marche et conforme à la réglementation en vigueur.
        Le locataire reconnaît avoir pris connaissance et accepté l'intégralité des clauses du présent contrat.
    </div>

</div><!-- /content page 1 -->


<!-- ════════════════════════════════════════════════════════════
     PAGE 2 — VÉHICULE + DÉTAILS CONTRAT
     ════════════════════════════════════════════════════════════ -->
<div class="pb"></div>
<div class="content">

    <div style="font-size:11px;font-weight:bold;color:<?= $c ?>;border-bottom:2px solid <?= $c ?>;padding-bottom:4px;margin-bottom:14px">
        DÉTAILS DU VÉHICULE ET DE LA LOCATION
    </div>

    <!-- Véhicule + Période côte à côte -->
    <table class="cols2"><tr>

        <td>
            <div class="section">
                <div class="section-title">🚗 VÉHICULE LOUÉ</div>
                <div class="section-body">
                    <table class="info">
                        <tr><td class="lbl">Désignation</td><td class="val"><strong><?= $e($loc['veh_nom']) ?></strong></td></tr>
                        <tr><td class="lbl">Immatriculation</td><td class="val"><strong><?= $e($loc['immatriculation']) ?></strong></td></tr>
                        <?php if ($loc['marque']): ?>
                        <tr><td class="lbl">Marque / Modèle</td><td class="val"><?= $e($loc['marque'] . ' ' . ($loc['modele'] ?? '')) ?></td></tr>
                        <?php endif ?>
                        <?php if ($loc['annee']): ?>
                        <tr><td class="lbl">Année</td><td class="val"><?= $e($loc['annee']) ?></td></tr>
                        <?php endif ?>
                        <?php if ($loc['couleur']): ?>
                        <tr><td class="lbl">Couleur</td><td class="val"><?= $e($loc['couleur']) ?></td></tr>
                        <?php endif ?>
                        <?php if ($loc['carburant_type']): ?>
                        <tr><td class="lbl">Carburant</td><td class="val"><?= $e(ucfirst($loc['carburant_type'])) ?></td></tr>
                        <?php endif ?>
                        <?php if ($loc['numero_chassis']): ?>
                        <tr><td class="lbl">N° Châssis</td><td class="val" style="font-size:8px"><?= $e($loc['numero_chassis']) ?></td></tr>
                        <?php endif ?>
                    </table>
                </div>
            </div>
        </td>

        <td>
            <div class="section">
                <div class="section-title">📅 PÉRIODE DE LOCATION</div>
                <div class="section-body">
                    <table class="info">
                        <tr><td class="lbl">Date de départ</td><td class="val"><strong><?= $d($loc['date_debut']) ?></strong></td></tr>
                        <tr><td class="lbl">Date de retour</td><td class="val"><strong><?= $d($loc['date_fin']) ?></strong></td></tr>
                        <tr><td class="lbl">Durée</td><td class="val"><strong><?= (int)$loc['nombre_jours'] ?> jour<?= (int)$loc['nombre_jours'] > 1 ? 's' : '' ?></strong></td></tr>
                        <?php if ($loc['lieu_destination']): ?>
                        <tr><td class="lbl">Destination</td><td class="val"><?= $e($loc['lieu_destination']) ?></td></tr>
                        <?php endif ?>
                        <?php if ($loc['type_location']): ?>
                        <tr><td class="lbl">Type location</td><td class="val"><?= $e(str_replace('_',' ',ucfirst($loc['type_location']))) ?></td></tr>
                        <?php endif ?>
                    </table>
                </div>
            </div>

            <div class="section">
                <div class="section-title">⛽ KILOMÉTRAGE & CARBURANT</div>
                <div class="section-body">
                    <table class="info">
                        <tr><td class="lbl">Km au départ</td><td class="val"><strong><?= number_format((int)$loc['km_depart'], 0, ',', ' ') ?> km</strong></td></tr>
                        <?php if ($loc['carburant_depart']): ?>
                        <tr><td class="lbl">Jauge départ</td><td class="val"><?= $e($loc['carburant_depart']) ?></td></tr>
                        <?php endif ?>
                        <?php if ($loc['statut'] === 'terminee'): ?>
                        <tr><td class="lbl">Km au retour</td><td class="val"><strong><?= number_format((int)$loc['km_retour'], 0, ',', ' ') ?> km</strong></td></tr>
                        <?php $kmp = (int)$loc['km_retour'] - (int)$loc['km_depart']; ?>
                        <tr><td class="lbl">Km parcourus</td><td class="val" style="color:<?= $c ?>;font-weight:bold"><?= number_format($kmp, 0, ',', ' ') ?> km</td></tr>
                        <?php if ($loc['carburant_retour']): ?>
                        <tr><td class="lbl">Jauge retour</td><td class="val"><?= $e($loc['carburant_retour']) ?></td></tr>
                        <?php endif ?>
                        <?php else: ?>
                        <tr><td class="lbl">Km retour</td><td class="val" style="color:#94a3b8">À compléter</td></tr>
                        <?php endif ?>
                    </table>
                </div>
            </div>
        </td>

    </tr></table>

    <!-- État du véhicule (retour) -->
    <?php if ($loc['statut'] === 'terminee' && $loc['notes']): ?>
    <div class="section">
        <div class="section-title">📋 ÉTAT DU VÉHICULE AU RETOUR</div>
        <div class="section-body" style="font-size:9px">
            <?= nl2br($e($loc['notes'])) ?>
        </div>
    </div>
    <?php else: ?>
    <div class="section">
        <div class="section-title">📋 ÉTAT DU VÉHICULE AU DÉPART</div>
        <div class="section-body">
            <table class="info">
                <tr>
                    <td class="lbl">Observations départ</td>
                    <td class="val" style="min-height:30px"><?= $loc['notes'] ? nl2br($e($loc['notes'])) : '<span style="color:#94a3b8">Aucune observation particulière</span>' ?></td>
                </tr>
                <tr>
                    <td class="lbl">État au retour</td>
                    <td class="val" style="min-height:40px;height:40px">&nbsp;</td>
                </tr>
            </table>
        </div>
    </div>
    <?php endif ?>

</div><!-- /content page 2 -->


<!-- ════════════════════════════════════════════════════════════
     PAGE 3 — FINANCIER + PAIEMENTS
     ════════════════════════════════════════════════════════════ -->
<div class="pb"></div>
<div class="content">

    <div style="font-size:11px;font-weight:bold;color:<?= $c ?>;border-bottom:2px solid <?= $c ?>;padding-bottom:4px;margin-bottom:14px">
        CONDITIONS FINANCIÈRES ET PAIEMENTS
    </div>

    <!-- Calcul location -->
    <div class="section">
        <div class="section-title">💰 DÉTAIL DU MONTANT</div>
        <div class="section-body">
            <table class="info" style="width:60%">
                <tr><td class="lbl">Prix par jour</td><td class="val"><?= $m($loc['prix_par_jour']) ?></td></tr>
                <tr><td class="lbl">Nombre de jours</td><td class="val"><?= (int)$loc['nombre_jours'] ?> jour<?= (int)$loc['nombre_jours'] > 1 ? 's' : '' ?></td></tr>
                <tr><td class="lbl">Montant brut</td><td class="val"><?= $m($loc['montant_total']) ?></td></tr>
                <?php if ((float)$loc['remise'] > 0): ?>
                <tr><td class="lbl">Remise accordée</td><td class="val" style="color:<?= $g ?>">- <?= $m($loc['remise']) ?></td></tr>
                <?php endif ?>
            </table>
            <div class="montant-box" style="margin-top:10px">
                <table style="width:100%"><tr>
                    <td class="montant-lbl">💎 Montant total à payer (TTC)</td>
                    <td class="montant-val"><?= $m($loc['montant_final']) ?></td>
                </tr></table>
            </div>
        </div>
    </div>

    <!-- Caution + paiement côte à côte -->
    <table class="cols2"><tr>

        <td>
            <div class="section">
                <div class="section-title">🔐 CAUTION</div>
                <div class="section-body">
                    <table class="info">
                        <tr><td class="lbl">Montant caution</td><td class="val" style="font-weight:bold;color:<?= $y ?>"><?= $m($loc['caution']) ?></td></tr>
                        <?php if ($loc['statut'] === 'terminee'): ?>
                        <tr>
                            <td class="lbl">Statut caution</td>
                            <td class="val">
                                <?php
                                $cautionLabel = ['rendue'=>['✅ Rendue',$gL,$g],'retenue'=>['❌ Retenue',$rL,$r],'partielle'=>['⚠️ Partielle',$yL,$y]];
                                [$cl,$cbg,$cco] = $cautionLabel[$loc['statut_caution'] ?? 'rendue'] ?? ['—','#f8fafc','#64748b'];
                                ?>
                                <span class="badge" style="background:<?= $cbg ?>;color:<?= $cco ?>"><?= $cl ?></span>
                            </td>
                        </tr>
                        <?php else: ?>
                        <tr><td class="lbl">Statut</td><td class="val"><span class="badge" style="background:<?= $yL ?>;color:<?= $y ?>">En attente retour</span></td></tr>
                        <?php endif ?>
                    </table>
                    <div style="font-size:7.5px;color:#94a3b8;margin-top:6px">
                        La caution sera restituée dans les 48h après vérification de l'état du véhicule.
                    </div>
                </div>
            </div>
        </td>

        <td>
            <div class="section">
                <div class="section-title">📊 RÉCAPITULATIF PAIEMENT</div>
                <div class="section-body">
                    <table class="info">
                        <tr><td class="lbl">Total dû</td><td class="val" style="font-weight:bold"><?= $m($loc['montant_final']) ?></td></tr>
                        <tr><td class="lbl">Total encaissé</td><td class="val" style="color:<?= $g ?>;font-weight:bold"><?= $m($totalPaye) ?></td></tr>
                        <tr>
                            <td class="lbl">Reste à payer</td>
                            <td class="val" style="font-weight:bold;color:<?= (float)$loc['reste_a_payer'] > 0 ? $r : $g ?>">
                                <?= (float)$loc['reste_a_payer'] <= 0 ? '✅ Soldé' : $m($loc['reste_a_payer']) ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="lbl">Statut paiement</td>
                            <td class="val">
                                <span class="badge" style="background:<?= $pbg ?>;color:<?= $pcol ?>">
                                    <?= $payLabel[$loc['statut_paiement']] ?? strtoupper($loc['statut_paiement']) ?>
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </td>

    </tr></table>

    <!-- Historique des paiements -->
    <div class="section">
        <div class="section-title">💳 HISTORIQUE DES PAIEMENTS REÇUS</div>
        <div class="section-body">
        <?php if (empty($paiements)): ?>
            <div style="text-align:center;padding:10px;color:#94a3b8;font-size:8.5px">
                Aucun paiement enregistré.
            </div>
        <?php else: ?>
            <table class="pays">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Montant</th>
                        <th>Mode de paiement</th>
                        <th>Référence</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($paiements as $i => $p): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= $dt($p['created_at']) ?></td>
                    <td style="font-weight:bold;color:<?= $g ?>"><?= $m($p['montant']) ?></td>
                    <td><?= $e(ucfirst(str_replace('_',' ',$p['mode_paiement'] ?? '—'))) ?></td>
                    <td style="font-size:8px;color:#64748b"><?= $e($p['reference'] ?? '—') ?></td>
                    <td style="font-size:8px;color:#64748b"><?= $e(mb_substr($p['notes'] ?? '', 0, 30)) ?></td>
                </tr>
                <?php endforeach ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" style="text-align:right;font-size:8.5px">Total encaissé :</td>
                        <td style="color:<?= $g ?>;font-size:10px"><?= $m($totalPaye) ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        <?php endif ?>
        </div>
    </div>

    <?php if ($loc['commercial_nom']): ?>
    <div style="font-size:8px;color:#94a3b8;text-align:right;margin-top:4px">
        Apporté par : <?= $e($loc['commercial_nom']) ?><?= $loc['canal_acquisition'] ? ' · Canal : ' . $e($loc['canal_acquisition']) : '' ?>
    </div>
    <?php endif ?>

</div><!-- /content page 3 -->


<!-- ════════════════════════════════════════════════════════════
     PAGE 4 — CONDITIONS GÉNÉRALES + SIGNATURES
     ════════════════════════════════════════════════════════════ -->
<div class="pb"></div>
<div class="content">

    <div style="font-size:11px;font-weight:bold;color:<?= $c ?>;border-bottom:2px solid <?= $c ?>;padding-bottom:4px;margin-bottom:14px">
        CONDITIONS GÉNÉRALES DE LOCATION ET SIGNATURES
    </div>

    <div class="section">
        <div class="section-title">📜 CONDITIONS GÉNÉRALES DE LOCATION</div>
        <div class="section-body">
            <div class="cg">
                <p><strong>Art. 1 — OBJET DU CONTRAT</strong><br>
                Le présent contrat a pour objet la mise à disposition à titre onéreux, par le loueur au locataire, du véhicule désigné en page 2. La location prend effet à compter de la date et heure de départ mentionnées au contrat.</p>

                <p><strong>Art. 2 — UTILISATION DU VÉHICULE</strong><br>
                Le véhicule est loué exclusivement pour un usage personnel ou professionnel légal sur le territoire national. Toute sortie du territoire national doit faire l'objet d'une autorisation écrite préalable du loueur. Il est strictement interdit de sous-louer le véhicule ou de le confier à un tiers non mentionné au présent contrat.</p>

                <p><strong>Art. 3 — CARBURANT</strong><br>
                Le locataire s'engage à restituer le véhicule avec le même niveau de carburant qu'à la prise en charge, tel que mentionné au contrat. À défaut, le coût du carburant manquant sera facturé au tarif en vigueur, majoré de 15% de frais de service.</p>

                <p><strong>Art. 4 — RESPONSABILITÉ ET DOMMAGES</strong><br>
                Le locataire est entièrement responsable de tout dommage causé au véhicule pendant la durée de la location, y compris les dommages causés par des tiers. En cas d'accident, le locataire doit immédiatement en informer le loueur et établir un constat amiable. La caution versée pourra être retenue partiellement ou totalement en cas de dommage constaté.</p>

                <p><strong>Art. 5 — RESTITUTION DU VÉHICULE</strong><br>
                Le véhicule doit être restitué à la date, heure et lieu convenus. Tout dépassement de la durée de location convenue sera facturé au tarif journalier en vigueur, majoré de 20%, sauf accord écrit préalable du loueur. Le véhicule doit être restitué dans l'état général dans lequel il a été remis.</p>

                <p><strong>Art. 6 — ASSURANCE</strong><br>
                Le véhicule bénéficie d'une couverture d'assurance tous risques. En cas de sinistre, le locataire est responsable du montant de la franchise contractuelle. Tout manquement aux obligations du présent contrat pourrait entraîner la perte du bénéfice de la couverture d'assurance.</p>

                <p><strong>Art. 7 — CAUTION</strong><br>
                La caution mentionnée au contrat est versée à la signature et sera restituée dans un délai maximum de 48 heures ouvrées après la restitution du véhicule, sous réserve qu'aucun dommage n'ait été constaté et que la location soit intégralement réglée.</p>

                <p><strong>Art. 8 — LITIGES</strong><br>
                En cas de litige résultant de l'application du présent contrat, les parties s'efforceront de trouver une solution amiable. À défaut, le litige sera soumis aux tribunaux compétents du ressort du siège social du loueur, qui seront seuls compétents.</p>

                <p><strong>Art. 9 — ACCEPTATION</strong><br>
                La signature du présent contrat vaut acceptation sans réserve de l'intégralité des clauses et conditions ci-dessus par le locataire. Le locataire déclare avoir pris connaissance de l'état du véhicule et l'accepter tel quel au moment de la prise en charge.</p>
            </div>
        </div>
    </div>

    <!-- Signatures -->
    <div class="section" style="margin-top:16px">
        <div class="section-title">✍️ SIGNATURES DES PARTIES</div>
        <div class="section-body">
            <div style="font-size:8px;color:#64748b;margin-bottom:12px;text-align:center">
                Fait à __________________________________________ , le <?= date('d/m/Y') ?>
                &nbsp;&nbsp;·&nbsp;&nbsp; En deux (2) exemplaires originaux, dont un remis à chaque partie.
            </div>
            <table class="sigs">
                <tr>
                    <td>
                        <div style="font-size:9px;font-weight:bold;color:<?= $c ?>;text-align:center;margin-bottom:6px">
                            LE LOCATAIRE
                        </div>
                        <div style="font-size:8px;color:#64748b;text-align:center;margin-bottom:4px">
                            <?= $e($loc['client_nom'] . ' ' . ($loc['client_prenom'] ?? '')) ?>
                        </div>
                        <div class="sig-zone"></div>
                        <div style="font-size:7.5px;color:#94a3b8;text-align:center">Signature précédée de la mention<br>« Lu et approuvé — Bon pour accord »</div>
                    </td>
                    <td class="spacer"></td>
                    <td>
                        <div style="font-size:9px;font-weight:bold;color:<?= $c ?>;text-align:center;margin-bottom:6px">
                            LE REPRÉSENTANT DU LOUEUR
                        </div>
                        <div style="font-size:8px;color:#64748b;text-align:center;margin-bottom:4px">
                            <?= $e($loc['tenant_nom']) ?>
                        </div>
                        <div class="sig-zone"></div>
                        <div style="font-size:7.5px;color:#94a3b8;text-align:center">Signature et cachet officiel de la société</div>
                    </td>
                </tr>
            </table>

            <?php if ($loc['statut'] === 'terminee'): ?>
            <div style="margin-top:20px;padding:10px 14px;background:<?= $gL ?>;border:1px solid #a7f3d0;border-radius:4px;font-size:8px;color:<?= $g ?>">
                <strong>✅ RESTITUTION CONFIRMÉE</strong> — Le véhicule a été restitué le <?= $d($loc['updated_at'] ?? null) ?>.
                <?php if ($loc['statut_caution']): ?>
                Caution : <strong><?= $e(['rendue'=>'Rendue intégralement','retenue'=>'Retenue','partielle'=>'Rendue partiellement'][$loc['statut_caution']] ?? ucfirst($loc['statut_caution'])) ?></strong>.
                <?php endif ?>
            </div>
            <?php endif ?>
        </div>
    </div>

</div><!-- /content page 4 -->

</body>
</html>
<?php
$html = ob_get_clean();

// ── Génération PDF ─────────────────────────────────────────────────────────────
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('chroot', BASE_PATH);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'contrat_' . $numContrat . '_' . date('Ymd') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;
