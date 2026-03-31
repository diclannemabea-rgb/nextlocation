<?php
/**
 * FlotteCar — Fiche Taximantre / Chauffeur VTC
 * Gestion complète : présence, paiements, dettes, fonds contravention, véhicule
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireTenantAuth();

$db       = (new Database())->getConnection();
$tenantId = getTenantId();
$today    = date('Y-m-d');

$taxiId = (int)($_GET['id'] ?? 0);
if (!$taxiId) { redirect(BASE_URL . 'app/taximetres/liste.php'); }

// ── Charger le taximantre ──────────────────────────────────────────────────────
$sTaxi = $db->prepare("
    SELECT t.*, v.nom veh_nom, v.immatriculation, v.kilometrage_actuel,
           v.statut veh_statut, v.id vehicule_id,
           v.marque, v.modele, v.couleur, v.annee,
           v.capital_investi, v.recettes_initiales, v.depenses_initiales
    FROM taximetres t
    JOIN vehicules v ON v.id = t.vehicule_id
    WHERE t.id = ? AND t.tenant_id = ?");
$sTaxi->execute([$taxiId, $tenantId]);
$taxi = $sTaxi->fetch(PDO::FETCH_ASSOC);
if (!$taxi) { setFlash(FLASH_ERROR, 'Taximantre introuvable.'); redirect(BASE_URL . 'app/taximetres/liste.php'); }

$tarif = (float)$taxi['tarif_journalier'];

// ── Export Excel (CSV multi-feuilles simulé via séparateurs) ──────────────────
if (($_GET['action'] ?? '') === 'export_excel') {
    $nomChauffeur = trim($taxi['nom'].' '.($taxi['prenom'] ?? ''));

    // Données paiements
    $sExp = $db->prepare("SELECT date_paiement, statut_jour, montant, montant_du, montant_fonds,
        mode_paiement, km_debut, km_fin, notes, created_at
        FROM paiements_taxi WHERE taximetre_id=? AND tenant_id=? ORDER BY date_paiement ASC");
    $sExp->execute([$taxiId, $tenantId]);
    $allPaiements = $sExp->fetchAll(PDO::FETCH_ASSOC);

    // Données contraventions
    $sContraExp = $db->prepare("SELECT date_contr, description, montant, statut, created_at
        FROM contraventions_taxi WHERE taximetre_id=? AND tenant_id=? AND statut!='efface' ORDER BY date_contr ASC");
    $sContraExp->execute([$taxiId, $tenantId]);
    $allContras = $sContraExp->fetchAll(PDO::FETCH_ASSOC);

    // Données véhicule — charges + maintenances
    $vid = $taxi['vehicule_id'];
    $sChargesVeh = $db->prepare("SELECT date_charge, type, libelle, montant, notes FROM charges WHERE vehicule_id=? AND tenant_id=? ORDER BY date_charge ASC");
    $sChargesVeh->execute([$vid, $tenantId]);
    $chargesVeh = $sChargesVeh->fetchAll(PDO::FETCH_ASSOC);

    $sMaintsVeh = $db->prepare("SELECT date_prevue, type, statut, cout, technicien, notes FROM maintenances WHERE vehicule_id=? AND tenant_id=? ORDER BY date_prevue ASC");
    $sMaintsVeh->execute([$vid, $tenantId]);
    $maintsVeh = $sMaintsVeh->fetchAll(PDO::FETCH_ASSOC);

    // Recettes taxi sur ce véhicule
    $sRecVeh = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM paiements_taxi pt JOIN taximetres t ON t.id=pt.taximetre_id WHERE t.vehicule_id=? AND pt.tenant_id=?");
    $sRecVeh->execute([$vid, $tenantId]);
    $recettesVeh = (float)$sRecVeh->fetchColumn();

    // Dépenses véhicule
    $sDepVeh = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM charges WHERE vehicule_id=? AND tenant_id=?");
    $sDepVeh->execute([$vid, $tenantId]);
    $depensesVeh = (float)$sDepVeh->fetchColumn();
    $sDepMaint = $db->prepare("SELECT COALESCE(SUM(cout),0) FROM maintenances WHERE vehicule_id=? AND tenant_id=? AND statut='termine'");
    $sDepMaint->execute([$vid, $tenantId]);
    $depensesVeh += (float)$sDepMaint->fetchColumn();

    $capitalVeh = (float)($taxi['capital_investi'] ?? 0);

    // Jours d'exploitation
    $sJoursExpl = $db->prepare("SELECT MIN(date_paiement) first_day, MAX(date_paiement) last_day, COUNT(DISTINCT date_paiement) nb_jours FROM paiements_taxi pt JOIN taximetres t ON t.id=pt.taximetre_id WHERE t.vehicule_id=? AND pt.tenant_id=?");
    $sJoursExpl->execute([$vid, $tenantId]);
    $exploData = $sJoursExpl->fetch(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fiche_'.preg_replace('/\s+/','_',$nomChauffeur).'_'.date('Y-m-d').'.csv"');
    $f = fopen('php://output','w');
    fprintf($f, chr(0xEF).chr(0xBB).chr(0xBF));
    $libStatut = ['paye'=>'Payé','non_paye'=>'Non payé','jour_off'=>'Jour off','panne'=>'Panne','accident'=>'Accident','maladie'=>'Maladie'];
    $libContra = ['regle_fonds'=>'Réglé (fonds)','regle_versement'=>'Réglé (versement)','en_attente'=>'En attente (dette)'];

    // ═══ FEUILLE 1 : RÉSUMÉ GLOBAL ═══
    fputcsv($f, ['=== RÉSUMÉ GLOBAL — '.$nomChauffeur.' ==='], ';');
    fputcsv($f, [], ';');
    fputcsv($f, ['Chauffeur', $nomChauffeur], ';');
    fputcsv($f, ['Téléphone', $taxi['telephone'] ?? '—'], ';');
    fputcsv($f, ['Véhicule', $taxi['veh_nom'].' — '.$taxi['immatriculation']], ';');
    fputcsv($f, ['Date début', formatDate($taxi['date_debut'])], ';');
    fputcsv($f, ['Tarif journalier', $tarif.' FCFA'], ';');
    fputcsv($f, [], ';');

    // Calculs résumé
    $totPercu = 0; $totDu = 0; $totFonds = 0; $jTrav = 0; $jPayes = 0; $jOff = 0;
    foreach ($allPaiements as $p) {
        if (in_array($p['statut_jour'],['paye','non_paye'])) { $jTrav++; $totDu += $tarif; }
        if ($p['statut_jour'] === 'paye') { $jPayes++; $totPercu += (float)$p['montant']; }
        else { $totPercu += (float)$p['montant']; }
        if (in_array($p['statut_jour'],['jour_off','panne','accident','maladie'])) $jOff++;
        $totFonds += (float)$p['montant_fonds'];
    }
    $totContra = 0; $nbContra = 0; $totContraDette = 0;
    foreach ($allContras as $c) {
        $totContra += (float)$c['montant']; $nbContra++;
        if ($c['statut'] === 'en_attente') $totContraDette += (float)$c['montant'];
    }

    fputcsv($f, ['Jours travaillés', $jTrav], ';');
    fputcsv($f, ['Jours payés', $jPayes], ';');
    fputcsv($f, ['Jours off/panne/accident', $jOff], ';');
    fputcsv($f, ['Total dû', $totDu.' FCFA'], ';');
    fputcsv($f, ['Total perçu', $totPercu.' FCFA'], ';');
    fputcsv($f, ['Dette versements', max(0,$totDu-$totPercu).' FCFA'], ';');
    fputcsv($f, [], ';');
    fputcsv($f, ['Fonds contravention cotisé', $totFonds.' FCFA'], ';');
    fputcsv($f, ['Total contraventions', $totContra.' FCFA ('.$nbContra.' contravention(s))'], ';');
    fputcsv($f, ['Solde fonds contravention', ($totFonds - $totContra + $totContraDette).' FCFA'], ';');
    fputcsv($f, ['Dette contraventions', $totContraDette.' FCFA'], ';');
    fputcsv($f, [], ';');
    fputcsv($f, [], ';');

    // ═══ FEUILLE 2 : MOUVEMENTS DÉTAILLÉS ═══
    fputcsv($f, ['=== MOUVEMENTS DÉTAILLÉS ==='], ';');
    fputcsv($f, [], ';');
    fputcsv($f, ['--- Paiements journaliers ---'], ';');
    fputcsv($f, ['Date','Statut','Montant perçu','Montant dû','Fonds contrav.','Mode paiement','Km début','Km fin','Notes'], ';');
    foreach ($allPaiements as $r) {
        fputcsv($f, [
            date('d/m/Y', strtotime($r['date_paiement'])),
            $libStatut[$r['statut_jour']] ?? $r['statut_jour'],
            $r['montant'], $r['montant_du'], $r['montant_fonds'],
            $r['mode_paiement'], $r['km_debut'] ?? '', $r['km_fin'] ?? '', $r['notes']
        ], ';');
    }
    fputcsv($f, [], ';');
    fputcsv($f, ['--- Contraventions ---'], ';');
    fputcsv($f, ['Date','Description','Montant','Statut'], ';');
    foreach ($allContras as $c) {
        fputcsv($f, [
            date('d/m/Y', strtotime($c['date_contr'])),
            $c['description'], $c['montant'],
            $libContra[$c['statut']] ?? $c['statut']
        ], ';');
    }
    fputcsv($f, [], ';');
    fputcsv($f, [], ';');

    // ═══ FEUILLE 3 : ANALYSE VÉHICULE ═══
    fputcsv($f, ['=== ANALYSE FINANCIÈRE VÉHICULE — '.$taxi['veh_nom'].' ('.$taxi['immatriculation'].') ==='], ';');
    fputcsv($f, [], ';');
    fputcsv($f, ['Capital investi', $capitalVeh.' FCFA'], ';');
    fputcsv($f, ['Recettes cumulées', ($recettesVeh + (float)($taxi['recettes_initiales']??0)).' FCFA'], ';');
    fputcsv($f, ['Dépenses cumulées', ($depensesVeh + (float)($taxi['depenses_initiales']??0)).' FCFA'], ';');
    $profit = ($recettesVeh + (float)($taxi['recettes_initiales']??0)) - ($depensesVeh + (float)($taxi['depenses_initiales']??0));
    fputcsv($f, ['Profit net', $profit.' FCFA'], ';');
    $roi = $capitalVeh > 0 ? round($profit / $capitalVeh * 100, 1) : 0;
    fputcsv($f, ['ROI', $roi.'%'], ';');
    fputcsv($f, ['Jours exploitation', $exploData['nb_jours'] ?? 0], ';');
    fputcsv($f, ['Période', ($exploData['first_day'] ? formatDate($exploData['first_day']).' → '.formatDate($exploData['last_day']) : '—')], ';');
    fputcsv($f, [], ';');
    fputcsv($f, ['--- Charges véhicule ---'], ';');
    fputcsv($f, ['Date','Type','Libellé','Montant','Notes'], ';');
    foreach ($chargesVeh as $ch) {
        fputcsv($f, [date('d/m/Y',strtotime($ch['date_charge'])), $ch['type'], $ch['libelle'], $ch['montant'], $ch['notes']], ';');
    }
    fputcsv($f, [], ';');
    fputcsv($f, ['--- Maintenances véhicule ---'], ';');
    fputcsv($f, ['Date prévue','Type','Statut','Coût','Technicien','Notes'], ';');
    foreach ($maintsVeh as $mt) {
        fputcsv($f, [date('d/m/Y',strtotime($mt['date_prevue'])), $mt['type'], $mt['statut'], $mt['cout'], $mt['technicien'], $mt['notes']], ';');
    }

    fclose($f); exit;
}

// ── Export CSV simple (rétrocompat) ──────────────────────────────────────────
if (($_GET['action'] ?? '') === 'export_csv') {
    $sExp = $db->prepare("SELECT date_paiement, statut_jour, montant, montant_du, montant_fonds,
        mode_paiement, km_debut, km_fin, notes, created_at
        FROM paiements_taxi WHERE taximetre_id=? AND tenant_id=? ORDER BY date_paiement DESC");
    $sExp->execute([$taxiId, $tenantId]);
    $rows = $sExp->fetchAll(PDO::FETCH_ASSOC);
    $nom  = $taxi['nom'].' '.($taxi['prenom'] ?? '');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="taximantre_'.preg_replace('/\s+/','_',$nom).'_'.date('Y-m-d').'.csv"');
    $f = fopen('php://output','w');
    fprintf($f, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($f, ['Date','Statut','Montant perçu','Montant dû','Fonds contrav.','Mode paiement','Km début','Km fin','Notes','Enregistré le'], ';');
    $libStatut = ['paye'=>'Payé','non_paye'=>'Non payé','jour_off'=>'Jour off','panne'=>'Panne','accident'=>'Accident'];
    foreach ($rows as $r) {
        fputcsv($f, [
            date('d/m/Y', strtotime($r['date_paiement'])),
            $libStatut[$r['statut_jour']] ?? $r['statut_jour'],
            $r['montant'], $r['montant_du'], $r['montant_fonds'],
            $r['mode_paiement'], $r['km_debut'] ?? '', $r['km_fin'] ?? '',
            $r['notes'], date('d/m/Y H:i', strtotime($r['created_at']))
        ], ';');
    }
    fclose($f); exit;
}

// ── POST ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';

    // ── Saisir / corriger un jour ──────────────────────────────────────────────
    if ($action === 'saisir_jour') {
        $date         = $_POST['date_paiement'] ?? $today;
        $statut       = $_POST['statut_jour']   ?? 'non_paye';
        $montant      = (float)($_POST['montant']       ?? 0);
        $montantFonds = (float)($_POST['montant_fonds'] ?? 0);
        $mode         = $_POST['mode_paiement'] ?? 'espece';
        $kmDebut      = !empty($_POST['km_debut']) ? (int)$_POST['km_debut'] : null;
        $kmFin        = !empty($_POST['km_fin'])   ? (int)$_POST['km_fin']   : null;
        $notes        = trim($_POST['notes'] ?? '');

        // montant_du = tarif uniquement pour les jours travaillés
        $montantDu = in_array($statut, ['paye','non_paye']) ? $tarif : 0;
        // Si payé, montant perçu ne peut pas dépasser montant dû
        if ($statut === 'paye') $montant = min($montant, $tarif);
        if ($statut !== 'paye') $montant = 0;

        $db->prepare("INSERT INTO paiements_taxi
            (tenant_id, taximetre_id, date_paiement, statut_jour, montant, montant_du, montant_fonds, mode_paiement, km_debut, km_fin, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
            statut_jour=VALUES(statut_jour), montant=VALUES(montant), montant_du=VALUES(montant_du),
            montant_fonds=VALUES(montant_fonds), mode_paiement=VALUES(mode_paiement),
            km_debut=VALUES(km_debut), km_fin=VALUES(km_fin), notes=VALUES(notes)")
        ->execute([$tenantId, $taxiId, $date, $statut, $montant, $montantDu, $montantFonds, $mode, $kmDebut, $kmFin, $notes]);

        // Mettre à jour km véhicule si km_fin renseigné
        if ($kmFin) {
            $db->prepare("UPDATE vehicules SET kilometrage_actuel=? WHERE id=? AND tenant_id=? AND kilometrage_actuel < ?")
               ->execute([$kmFin, $taxi['vehicule_id'], $tenantId, $kmFin]);
        }

        // ── Auto-apurement des dettes contravention si fonds cotisés ce jour ──
        if ($montantFonds > 0) {
            // Recalcul du nouveau solde fonds après insertion
            $sFonds2 = $db->prepare("SELECT COALESCE(SUM(montant_fonds),0) FROM paiements_taxi WHERE taximetre_id=? AND tenant_id=?");
            $sFonds2->execute([$taxiId, $tenantId]);
            $fondsTotNow = (float)$sFonds2->fetchColumn();
            $sUsed2 = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM contraventions_taxi WHERE taximetre_id=? AND tenant_id=? AND statut='regle_fonds'");
            $sUsed2->execute([$taxiId, $tenantId]);
            $soldeDisponible = max(0, $fondsTotNow - (float)$sUsed2->fetchColumn());

            // Apurer les dettes en_attente (plus ancienne en premier)
            if ($soldeDisponible > 0) {
                $dettesEnAttente = $db->prepare("SELECT id, montant FROM contraventions_taxi WHERE taximetre_id=? AND tenant_id=? AND statut='en_attente' ORDER BY date_contr ASC");
                $dettesEnAttente->execute([$taxiId, $tenantId]);
                foreach ($dettesEnAttente->fetchAll(PDO::FETCH_ASSOC) as $dette) {
                    if ($soldeDisponible <= 0) break;
                    if ($soldeDisponible >= (float)$dette['montant']) {
                        // Solde suffisant pour couvrir cette dette entièrement
                        $db->prepare("UPDATE contraventions_taxi SET statut='regle_versement' WHERE id=? AND tenant_id=?")
                           ->execute([$dette['id'], $tenantId]);
                        $soldeDisponible -= (float)$dette['montant'];
                    }
                    // Si solde insuffisant pour couvrir la dette entière, on arrête (pas de split)
                }
            }
        }

        logActivite($db, 'update', 'taximetres', "Saisie jour $date — $statut — taximantre #{$taxiId}");
        // Push notif
        $tNom = trim(($taxi['nom'] ?? '') . ' ' . ($taxi['prenom'] ?? ''));
        if ($statut === 'paye') {
            pushNotif($db, $tenantId, 'taxi', "Paiement taxi — $tNom", formatMoney($montant)." perçu le ".formatDate($date), BASE_URL."app/taximetres/detail.php?id=$taxiId");
        } elseif ($statut === 'non_paye') {
            pushNotif($db, $tenantId, 'alerte', "Impayé taxi — $tNom", "N'a pas versé pour le ".formatDate($date), BASE_URL."app/taximetres/detail.php?id=$taxiId");
        }
        setFlash(FLASH_SUCCESS, 'Journée enregistrée.');
        redirect(BASE_URL . "app/taximetres/detail.php?id=$taxiId&mois=" . substr($date, 0, 7));
    }

    // ── Changer de véhicule ────────────────────────────────────────────────────
    if ($action === 'changer_vehicule') {
        $newVeh    = (int)($_POST['new_vehicule_id'] ?? 0);
        $motif     = trim($_POST['motif'] ?? '');
        if ($newVeh) {
            $ancienNote = "Véhicule changé le ".date('d/m/Y')." (ancien: {$taxi['veh_nom']} / {$taxi['immatriculation']})".($motif?" — $motif":'');
            $db->prepare("UPDATE taximetres SET vehicule_id=?, notes=CONCAT(COALESCE(notes,''), '\n', ?) WHERE id=? AND tenant_id=?")
               ->execute([$newVeh, $ancienNote, $taxiId, $tenantId]);
            logActivite($db, 'update', 'taximetres', "Changement véhicule taximantre #{$taxiId}");
            setFlash(FLASH_SUCCESS, 'Véhicule mis à jour.');
        }
        redirect(BASE_URL . "app/taximetres/detail.php?id=$taxiId");
    }

    // ── Ajouter une contravention ──────────────────────────────────────────────
    if ($action === 'ajouter_contravention') {
        $montantContra = (float)($_POST['montant_contravention'] ?? 0);
        $descContra    = trim($_POST['description_contravention'] ?? 'Contravention');
        $dateContra    = $_POST['date_contravention'] ?? $today;
        if ($montantContra > 0) {
            // Calcul solde fonds disponible
            $sFonds = $db->prepare("SELECT COALESCE(SUM(montant_fonds),0) FROM paiements_taxi WHERE taximetre_id=? AND tenant_id=?");
            $sFonds->execute([$taxiId, $tenantId]);
            $fondsTot = (float)$sFonds->fetchColumn();
            $sUsed    = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM contraventions_taxi WHERE taximetre_id=? AND tenant_id=? AND statut='regle_fonds'");
            $sUsed->execute([$taxiId, $tenantId]);
            $fondsBalance = max(0, $fondsTot - (float)$sUsed->fetchColumn());

            if ($fondsBalance >= $montantContra) {
                // Fonds suffisants : tout est couvert
                $db->prepare("INSERT INTO contraventions_taxi (tenant_id, taximetre_id, date_contr, montant, description, statut) VALUES (?,?,?,?,?,?)")
                   ->execute([$tenantId, $taxiId, $dateContra, $montantContra, $descContra, 'regle_fonds']);
                setFlash(FLASH_SUCCESS, 'Contravention de '.formatMoney($montantContra).' imputée sur les fonds.');
            } elseif ($fondsBalance > 0) {
                // Fonds partiels : on consomme ce qui reste + le reste devient dette
                $detteRestante = $montantContra - $fondsBalance;
                $db->prepare("INSERT INTO contraventions_taxi (tenant_id, taximetre_id, date_contr, montant, description, statut) VALUES (?,?,?,?,?,?)")
                   ->execute([$tenantId, $taxiId, $dateContra, $fondsBalance, $descContra.' (couverture fonds)', 'regle_fonds']);
                $db->prepare("INSERT INTO contraventions_taxi (tenant_id, taximetre_id, date_contr, montant, description, statut) VALUES (?,?,?,?,?,?)")
                   ->execute([$tenantId, $taxiId, $dateContra, $detteRestante, $descContra.' (solde dette)', 'en_attente']);
                setFlash(FLASH_WARNING, formatMoney($fondsBalance).' couverts par les fonds. Reste '.formatMoney($detteRestante).' en dette.');
            } else {
                // Aucun fonds : tout est une dette
                $db->prepare("INSERT INTO contraventions_taxi (tenant_id, taximetre_id, date_contr, montant, description, statut) VALUES (?,?,?,?,?,?)")
                   ->execute([$tenantId, $taxiId, $dateContra, $montantContra, $descContra, 'en_attente']);
                setFlash(FLASH_ERROR, 'Fonds vides — '.formatMoney($montantContra).' ajouté à la dette du chauffeur.');
            }
            logActivite($db, 'create', 'contraventions_taxi', "Contravention {$montantContra} FCFA — taximantre #{$taxiId}");
        }
        redirect(BASE_URL . "app/taximetres/detail.php?id=$taxiId");
    }

    // ── Régler une contravention en_attente par versement direct ──────────────
    if ($action === 'regler_contravention') {
        $contraId = (int)($_POST['contra_id'] ?? 0);
        if ($contraId) {
            $db->prepare("UPDATE contraventions_taxi SET statut='regle_versement' WHERE id=? AND tenant_id=? AND taximetre_id=? AND statut='en_attente'")
               ->execute([$contraId, $tenantId, $taxiId]);
            logActivite($db, 'update', 'contraventions_taxi', "Contravention #{$contraId} marquée réglée — taximantre #{$taxiId}");
            setFlash(FLASH_SUCCESS, 'Contravention marquée comme réglée.');
        }
        redirect(BASE_URL . "app/taximetres/detail.php?id=$taxiId");
    }

    // ── Modifier le profil ─────────────────────────────────────────────────────
    if ($action === 'modifier_profil') {
        $jourReposVal = isset($_POST['jour_repos']) && $_POST['jour_repos'] !== '' ? (int)$_POST['jour_repos'] : null;
        $db->prepare("UPDATE taximetres SET nom=?, prenom=?, telephone=?, tarif_journalier=?, caution_versee=?, jour_repos=?, notes=? WHERE id=? AND tenant_id=?")
           ->execute([
               trim($_POST['nom'] ?? $taxi['nom']),
               trim($_POST['prenom'] ?? ''),
               trim($_POST['telephone'] ?? ''),
               (float)($_POST['tarif_journalier'] ?? $tarif),
               (float)($_POST['caution_versee'] ?? 0),
               $jourReposVal,
               trim($_POST['notes'] ?? ''),
               $taxiId, $tenantId
           ]);
        logActivite($db, 'update', 'taximetres', "Modification profil taximantre #{$taxiId}");
        setFlash(FLASH_SUCCESS, 'Profil mis à jour.');
        redirect(BASE_URL . "app/taximetres/detail.php?id=$taxiId");
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// DONNÉES
// ═══════════════════════════════════════════════════════════════════════════════

// ── Mois affiché dans le calendrier ───────────────────────────────────────────
$moisCal = $_GET['mois'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $moisCal)) $moisCal = date('Y-m');
$moisPrev = date('Y-m', strtotime("$moisCal-01 -1 month"));
$moisNext = date('Y-m', strtotime("$moisCal-01 +1 month"));
$premierJour = date('N', strtotime("$moisCal-01")); // 1=Lun, 7=Dim
$nbJoursMois = (int)date('t', strtotime("$moisCal-01"));
$moisNoms  = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$moisLabel = $moisNoms[(int)date('m', strtotime("$moisCal-01"))] . ' ' . date('Y', strtotime("$moisCal-01"));

// ── Tous les jours saisis du mois ─────────────────────────────────────────────
$sJoursMois = $db->prepare("SELECT date_paiement, statut_jour, montant, montant_du, montant_fonds, km_debut, km_fin, mode_paiement, notes
    FROM paiements_taxi WHERE taximetre_id=? AND tenant_id=? AND DATE_FORMAT(date_paiement,'%Y-%m')=?");
$sJoursMois->execute([$taxiId, $tenantId, $moisCal]);
$joursMoisMap = [];
foreach ($sJoursMois->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $joursMoisMap[$r['date_paiement']] = $r;
}

// ── Solde global ──────────────────────────────────────────────────────────────
$sSolde = $db->prepare("SELECT
    COALESCE(SUM(CASE WHEN statut_jour IN('paye','non_paye') THEN 1 ELSE 0 END),0) jours_travailles,
    COALESCE(SUM(CASE WHEN statut_jour='paye' THEN 1 ELSE 0 END),0) jours_payes,
    COALESCE(SUM(CASE WHEN statut_jour IN('jour_off','panne','accident','maladie') THEN 1 ELSE 0 END),0) jours_off,
    COALESCE(SUM(montant),0) total_percu,
    COALESCE(SUM(montant_fonds),0) total_fonds,
    COALESCE(SUM(CASE WHEN statut_jour='non_paye' THEN 1 ELSE 0 END),0) jours_impaye,
    COALESCE(SUM(CASE WHEN statut_jour='panne' THEN 1 ELSE 0 END),0) jours_panne,
    COALESCE(SUM(CASE WHEN statut_jour='accident' THEN 1 ELSE 0 END),0) jours_accident,
    COALESCE(SUM(CASE WHEN statut_jour='maladie' THEN 1 ELSE 0 END),0) jours_maladie,
    COUNT(*) total_saisis
    FROM paiements_taxi WHERE taximetre_id=? AND tenant_id=?");
$sSolde->execute([$taxiId, $tenantId]);
$solde = $sSolde->fetch(PDO::FETCH_ASSOC);

$dateDebut      = $taxi['date_debut'];
$dateFin        = $taxi['date_fin'] ?? $today;
$limiteCalc     = min($dateFin, $today);

// ── Jour de repos hebdomadaire (ex: dimanche) — automatiquement exclu ──────────
// jour_repos : 0=Lun, 1=Mar, ..., 5=Sam, 6=Dim (date('N') : 1=Lun...7=Dim)
$jourReposConfig = ($taxi['jour_repos'] !== null) ? (int)$taxi['jour_repos'] : null;
$jomsNoms7       = ['Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi','Dimanche'];
$jourReposNom    = $jourReposConfig !== null ? $jomsNoms7[$jourReposConfig] : null;

// Compter les jours de repos automatiques dans la période (non enregistrés)
$joursReposPeriodeAuto = 0;
if ($jourReposConfig !== null) {
    $jourReposN = $jourReposConfig + 1; // date('N') : 1=Lun...7=Dim
    if ($jourReposN == 8) $jourReposN = 7; // sécurité (dimanche = 7 en N)
    $dCur = strtotime($dateDebut);
    $dLim = strtotime($limiteCalc);
    while ($dCur <= $dLim) {
        $dow = (int)date('N', $dCur); // 1=Lun...7=Dim
        if ($dow === $jourReposN) $joursReposPeriodeAuto++;
        $dCur += 86400;
    }
}
// Parmi ces jours de repos, certains ont peut-être déjà été enregistrés manuellement
// (ex: travail un dimanche exceptionnel) — ne pas les compter 2x
$sReposEnreg = $db->prepare("SELECT COUNT(*) FROM paiements_taxi
    WHERE taximetre_id=? AND tenant_id=? AND DAYOFWEEK(date_paiement)=?");
// DAYOFWEEK: 1=Dim,2=Lun,...,7=Sam → convertir jour_repos (0=Lun...6=Dim)
$dowMySql = $jourReposConfig !== null ? ($jourReposConfig === 6 ? 1 : $jourReposConfig + 2) : null;
$joursReposDejaEnreg = 0;
if ($dowMySql !== null) {
    $sReposEnreg->execute([$taxiId, $tenantId, $dowMySql]);
    $joursReposDejaEnreg = (int)$sReposEnreg->fetchColumn();
}
$joursReposAuto = max(0, $joursReposPeriodeAuto - $joursReposDejaEnreg);

// ── Calcul de la dette ─────────────────────────────────────────────────────────
$nbJoursPeriode = max(0, (int)((strtotime($limiteCalc) - strtotime($dateDebut)) / 86400) + 1);
$joursOff       = (int)$solde['jours_off'];   // explicitement marqués off/panne/accident/maladie
$joursNonSaisis = max(0, $nbJoursPeriode - (int)$solde['total_saisis'] - $joursReposAuto);
// jours travaillés = période - repos_auto - explicitement_off - (non saisis = dettes implicites)
// En réalité : jours_travailles = nbJoursPeriode - joursReposAuto - joursOff
$joursTravaill  = max(0, $nbJoursPeriode - $joursReposAuto - $joursOff);
$totalDu        = $joursTravaill * $tarif;
$totalPercu     = (float)$solde['total_percu'];
$dette          = max(0, $totalDu - $totalPercu);
$detteExplicite = (int)$solde['jours_impaye'] * $tarif;
$detteNonSaisis = $joursNonSaisis * $tarif;

// ── Fonds contravention ───────────────────────────────────────────────────────
$fondsTotal = (float)$solde['total_fonds']; // Total cotisé
$sContraAll = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM contraventions_taxi WHERE taximetre_id=? AND tenant_id=? AND statut != 'efface'");
$sContraAll->execute([$taxiId, $tenantId]);
$contraTotalMontant = (float)$sContraAll->fetchColumn(); // Total contraventions
$sContraUsed = $db->prepare("SELECT COALESCE(SUM(montant),0) FROM contraventions_taxi WHERE taximetre_id=? AND tenant_id=? AND statut='regle_fonds'");
$sContraUsed->execute([$taxiId, $tenantId]);
$fondsUtilise = (float)$sContraUsed->fetchColumn();
$fondsBalance = $fondsTotal - $fondsUtilise; // Peut être négatif si dette contravention

// Contraventions de ce taximantre (toutes, pas limite 10)
$sContras = $db->prepare("SELECT id, description as libelle, montant, date_contr as date_charge, statut, notes FROM contraventions_taxi
    WHERE taximetre_id=? AND tenant_id=? AND statut != 'efface' ORDER BY date_contr DESC");
$sContras->execute([$taxiId, $tenantId]);
$contraventions = $sContras->fetchAll(PDO::FETCH_ASSOC);
$nbContraventions = count($contraventions);

// Contravention dette = somme des contraventions en_attente (fonds insuffisant)
$detteContra = 0;
foreach ($contraventions as $c) {
    if ($c['statut'] === 'en_attente') $detteContra += (float)$c['montant'];
}
$detteTotal = $dette + $detteContra;
$fondsSolde = $fondsBalance - $detteContra; // Solde réel : positif = argent dispo, négatif = dette

// ── Retard : vérifie si hier ou aujourd'hui non saisi ────────────────────────
$hier     = date('Y-m-d', strtotime('-1 day'));
$sYest    = $db->prepare("SELECT statut_jour FROM paiements_taxi WHERE taximetre_id=? AND tenant_id=? AND date_paiement=?");
$sYest->execute([$taxiId, $tenantId, $hier]);
$statutHier = $sYest->fetchColumn();
$sToday   = $db->prepare("SELECT statut_jour FROM paiements_taxi WHERE taximetre_id=? AND tenant_id=? AND date_paiement=?");
$sToday->execute([$taxiId, $tenantId, $today]);
$statutAujourd = $sToday->fetchColumn();
$retardHier    = ($taxi['statut'] === 'actif' && $hier >= $dateDebut && !$statutHier);
$retardAujourd = ($taxi['statut'] === 'actif' && !$statutAujourd);

// ── Historique avec filtres ──────────────────────────────────────────────────
$filtreDebut = $_GET['hd'] ?? '';
$filtreFin   = $_GET['hf'] ?? '';
$histSQL     = "SELECT *, 'paiement' as type_mouv FROM paiements_taxi WHERE taximetre_id=? AND tenant_id=?";
$histParams  = [$taxiId, $tenantId];
if ($filtreDebut) { $histSQL .= " AND date_paiement >= ?"; $histParams[] = $filtreDebut; }
if ($filtreFin)   { $histSQL .= " AND date_paiement <= ?"; $histParams[] = $filtreFin; }
$histSQL .= " ORDER BY date_paiement DESC";
if (!$filtreDebut && !$filtreFin) $histSQL .= " LIMIT 30";
$sHist = $db->prepare($histSQL);
$sHist->execute($histParams);
$historique = $sHist->fetchAll(PDO::FETCH_ASSOC);

// Contraventions pour la même période (pour affichage fusionné)
$contraHistSQL = "SELECT id, date_contr as date_paiement, description, montant, statut, 'contravention' as type_mouv FROM contraventions_taxi WHERE taximetre_id=? AND tenant_id=? AND statut!='efface'";
$contraHistParams = [$taxiId, $tenantId];
if ($filtreDebut) { $contraHistSQL .= " AND date_contr >= ?"; $contraHistParams[] = $filtreDebut; }
if ($filtreFin)   { $contraHistSQL .= " AND date_contr <= ?"; $contraHistParams[] = $filtreFin; }
$contraHistSQL .= " ORDER BY date_contr DESC";
$sContraHist = $db->prepare($contraHistSQL);
$sContraHist->execute($contraHistParams);
$contraHistorique = $sContraHist->fetchAll(PDO::FETCH_ASSOC);

// ── Résumé mensuel (6 derniers mois) ─────────────────────────────────────────
$sMens = $db->prepare("SELECT DATE_FORMAT(date_paiement,'%Y-%m') m,
    COALESCE(SUM(CASE WHEN statut_jour IN('paye','non_paye') THEN 1 ELSE 0 END),0) jours_trav,
    COALESCE(SUM(CASE WHEN statut_jour='paye' THEN 1 ELSE 0 END),0) jours_payes,
    COALESCE(SUM(CASE WHEN statut_jour IN('jour_off','panne','accident') THEN 1 ELSE 0 END),0) jours_off,
    COALESCE(SUM(montant),0) percu,
    COALESCE(SUM(montant_fonds),0) fonds
    FROM paiements_taxi WHERE taximetre_id=? AND tenant_id=?
    AND date_paiement >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY m ORDER BY m DESC");
$sMens->execute([$taxiId, $tenantId]);
$resumeMensuel = $sMens->fetchAll(PDO::FETCH_ASSOC);

// ── Véhicules disponibles pour changement (tous types sauf déjà assigné) ──────
$sVehs = $db->prepare("SELECT v.id, v.nom, v.immatriculation, v.statut, v.marque, v.modele, v.type_vehicule
    FROM vehicules v WHERE v.tenant_id=? AND v.id != ?
    AND v.id NOT IN (SELECT vehicule_id FROM taximetres WHERE tenant_id=? AND statut='actif' AND id!=?)
    ORDER BY v.statut='disponible' DESC, v.nom ASC");
$sVehs->execute([$tenantId, $taxi['vehicule_id'], $tenantId, $taxiId]);
$veiculesDispos = $sVehs->fetchAll(PDO::FETCH_ASSOC);

// ── Taux de présence (30 derniers jours) ─────────────────────────────────────
$sTaux = $db->prepare("SELECT COUNT(*) total, SUM(CASE WHEN statut_jour IN('paye','non_paye') THEN 1 ELSE 0 END) travailles
    FROM paiements_taxi WHERE taximetre_id=? AND tenant_id=? AND date_paiement >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$sTaux->execute([$taxiId, $tenantId]);
$tauxData = $sTaux->fetch(PDO::FETCH_ASSOC);
$tauxPresence = $tauxData['total'] > 0 ? round($tauxData['travailles'] / $tauxData['total'] * 100) : 0;

// ═══════════════════════════════════════════════════════════════════════════════
// HTML
// ═══════════════════════════════════════════════════════════════════════════════
$pageTitle  = 'Fiche — ' . $taxi['nom'] . ' ' . ($taxi['prenom'] ?? '');
$activePage = 'taximetres';
require_once BASE_PATH . '/includes/header.php';
?>
<style>
/* ── Alertes (compact) ── */
.alerte-bar{border-radius:8px;padding:8px 14px;display:flex;align-items:center;gap:10px;margin-bottom:8px;font-size:.8rem}
.alerte-bar.rouge{background:#fef2f2;border:1px solid #fecaca}
.alerte-bar.orange{background:#fffbeb;border:1px solid #fde68a}
.alerte-bar.bleue{background:#eff6ff;border:1px solid #bfdbfe}
.alerte-bar .al-icon{font-size:1rem;flex-shrink:0}
.alerte-bar .al-title{font-weight:700;font-size:.8rem}
.alerte-bar .al-sub{font-size:.72rem;color:#64748b}

/* ── KPI cards ── */
.kpi-vtc{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;position:relative;overflow:hidden}
.kpi-vtc .kv{font-size:1.25rem;font-weight:800;margin:4px 0 2px}
.kpi-vtc .kl{font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#64748b}
.kpi-vtc .ks{font-size:.72rem;color:#94a3b8}
.kpi-vtc .ki{position:absolute;right:12px;top:10px;font-size:1.8rem;opacity:.07}
.kpi-vtc .kbar{height:4px;background:#e2e8f0;border-radius:2px;margin-top:6px;overflow:hidden}
.kpi-vtc .kbar-fill{height:4px;border-radius:2px}

/* ── Calendrier ── */
.cal-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden}
.cal-nav{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #e2e8f0;background:#f8fafc}
.cal-nav h3{font-size:.95rem;font-weight:700;color:#0f172a;margin:0}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:#e2e8f0}
.cal-head{background:#f8fafc;text-align:center;padding:7px 4px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8}
.cal-day{background:#fff;min-height:72px;padding:5px;cursor:pointer;transition:all .15s;position:relative}
.cal-day:hover{background:#f0f7ff;z-index:1}
.cal-day.vide{background:#fafafa;cursor:default}
.cal-day.futur{opacity:.45;cursor:not-allowed}
.cal-day.avant_debut{background:#fafafa;opacity:.3;cursor:default}
.cal-day.today{outline:2px solid #0d9488;outline-offset:-2px}
.cal-num{font-size:.78rem;font-weight:700;color:#334155;margin-bottom:3px}
.cal-statut{font-size:.62rem;font-weight:600;border-radius:4px;padding:2px 5px;display:inline-block;width:100%;text-align:center;margin-top:2px}
.cal-montant{font-size:.6rem;color:#64748b;text-align:center;margin-top:1px}
.s-paye     {background:#dcfce7;color:#166534}
.s-non_paye {background:#fee2e2;color:#991b1b}
.s-jour_off {background:#f1f5f9;color:#475569}
.s-panne    {background:#fef3c7;color:#92400e}
.s-accident {background:#fce7f3;color:#9d174d}
.s-non_saisi{background:#fff;border:1.5px dashed #d1d5db;color:#94a3b8}
.cal-legend{display:flex;gap:10px;flex-wrap:wrap;padding:10px 16px;border-top:1px solid #e2e8f0;background:#fafafa}
.leg-item{display:flex;align-items:center;gap:5px;font-size:.68rem;color:#64748b}
.leg-dot{width:10px;height:10px;border-radius:3px;flex-shrink:0}

/* ── Fonds contravention ── */
.fonds-card{background:linear-gradient(135deg,#0d9488 0%,#1e40af 100%);color:#fff;border-radius:10px;padding:16px 20px}
.fonds-card .fv{font-size:1.5rem;font-weight:800;margin:4px 0}
.fonds-card .fl{font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;opacity:.8}
.fonds-card .fs{font-size:.75rem;opacity:.75;margin-top:2px}

/* ── Table historique ── */
.hist-table td,.hist-table th{padding:8px 10px;font-size:.78rem}
.hist-table th{font-size:.66rem;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;font-weight:600;border-bottom:2px solid #e2e8f0}
.hist-table tr:hover td{background:#f8fafc}
.hist-table tr.row-paye td{border-left:3px solid #10b981}
.hist-table tr.row-non_paye td{border-left:3px solid #ef4444}
.hist-table tr.row-off td{border-left:3px solid #94a3b8}

/* ── Grille responsive ── */
.g4{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
.g3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:14px}
.g2{display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-bottom:14px}
.g2r{display:grid;grid-template-columns:1fr 2fr;gap:14px;margin-bottom:14px}
@media(max-width:900px){.g4{grid-template-columns:1fr 1fr}.g3,.g2,.g2r{grid-template-columns:1fr}}

/* ── Badge statut véhicule ── */
.veh-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:99px;font-size:.68rem;font-weight:700}
.s-maladie  {background:#fdf4ff;color:#7e22ce}
.cal-day.repos-auto{background:#f8fafc;cursor:pointer}
.cal-day.repos-auto:hover{background:#f1f5f9}
</style>

<!-- ── Header page ── -->
<div class="page-header" style="margin-bottom:14px">
    <div style="display:flex;align-items:center;gap:14px">
        <div style="width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,#0d9488,#14b8a6);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.3rem;font-weight:800;flex-shrink:0">
            <?= strtoupper(substr($taxi['nom'],0,1)) ?>
        </div>
        <div>
            <h1 class="page-title" style="margin:0;font-size:1.2rem"><?= sanitize($taxi['nom'].' '.($taxi['prenom']??'')) ?></h1>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:3px">
                <span style="font-size:.78rem;color:#64748b"><i class="fas fa-car" style="color:#0d9488"></i> <?= sanitize($taxi['veh_nom']) ?> — <?= sanitize($taxi['immatriculation']) ?></span>
                <span style="font-size:.78rem;color:#64748b"><i class="fas fa-phone" style="color:#10b981"></i> <?= sanitize($taxi['telephone'] ?? '—') ?></span>
                <span class="veh-badge <?= $taxi['statut']==='actif' ? 'bg-success' : 'bg-secondary' ?>"><?= ucfirst($taxi['statut']) ?></span>
                <?php if ($jourReposNom): ?>
                <span class="veh-badge" style="background:#f1f5f9;color:#475569"><i class="fas fa-moon" style="font-size:.6rem"></i> Repos : <?= $jourReposNom ?>s</span>
                <?php endif ?>
                <?php if ($taxi['veh_statut'] === 'maintenance'): ?>
                <span class="veh-badge" style="background:#fef3c7;color:#92400e"><i class="fas fa-wrench"></i> Véhicule en maintenance</span>
                <?php endif ?>
            </div>
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="?id=<?= $taxiId ?>&action=export_excel" class="btn btn-success btn-sm"><i class="fas fa-file-excel"></i> Export complet</a>
        <button onclick="openModal('modal-profil')" class="btn btn-ghost btn-sm"><i class="fas fa-edit"></i> Modifier</button>
        <button onclick="openModal('modal-vehicule')" class="btn btn-outline-primary btn-sm"><i class="fas fa-exchange-alt"></i> Changer de véhicule</button>
        <a href="<?= BASE_URL ?>app/taximetres/liste.php" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i></a>
    </div>
</div>

<?= renderFlashes() ?>

<!-- ════════════════════════════════════════════════════════════════
     ALERTES
════════════════════════════════════════════════════════════════ -->

<?php if ($taxi['veh_statut'] === 'maintenance'): ?>
<div class="alerte-bar orange">
    <div class="al-icon" style="color:#d97706"><i class="fas fa-wrench"></i></div>
    <div style="flex:1">
        <div class="al-title" style="color:#92400e">Véhicule en maintenance<?php if ($detteTotal > 0): ?> · Dette : <strong style="color:#dc2626"><?= formatMoney($detteTotal) ?></strong><?php endif ?></div>
        <div class="al-sub">Marquez les jours comme Panne ou assignez un autre véhicule.</div>
    </div>
    <?php if (!empty($veiculesDispos)): ?>
    <button onclick="openModal('modal-vehicule')" class="btn btn-sm btn-primary"><i class="fas fa-exchange-alt"></i> Changer</button>
    <?php endif ?>
</div>
<?php endif ?>

<?php if ($detteTotal > 0): ?>
<div class="alerte-bar rouge">
    <div class="al-icon" style="color:#ef4444"><i class="fas fa-exclamation-circle"></i></div>
    <div style="flex:1">
        <div class="al-title" style="color:#991b1b">Dette : <?= formatMoney($detteTotal) ?></div>
        <div class="al-sub">
            <?php $parts = []; ?>
            <?php if ($detteNonSaisis > 0) $parts[] = $joursNonSaisis.'j non saisis = '.formatMoney($detteNonSaisis); ?>
            <?php if ($detteExplicite > 0) $parts[] = (int)$solde['jours_impaye'].'j impayés = '.formatMoney($detteExplicite); ?>
            <?php if ($detteContra > 0) $parts[] = 'Contrav. = '.formatMoney($detteContra); ?>
            <?= implode(' · ', $parts) ?>
        </div>
    </div>
    <button class="btn btn-sm" style="background:#fff;color:#dc2626;border:1px solid #fca5a5" onclick="openModal('modal-saisir')">Encaisser</button>
</div>
<?php endif ?>

<?php if ($retardHier || $retardAujourd): ?>
<div class="alerte-bar orange">
    <div class="al-icon" style="color:#d97706"><i class="fas fa-clock"></i></div>
    <div style="flex:1">
        <div class="al-title" style="color:#92400e">Journée(s) non saisie(s)</div>
        <div class="al-sub">
            <?= $retardAujourd ? "Aujourd'hui (".date('d/m').') ' : '' ?><?= ($retardHier && $retardAujourd) ? '· ' : '' ?><?= $retardHier ? "Hier (".date('d/m', strtotime('-1 day')).')' : '' ?>
        </div>
    </div>
    <button class="btn btn-sm btn-primary" onclick="document.getElementById('date_paiement_m').value='<?= $retardAujourd ? $today : $hier ?>'; openModal('modal-saisir')">Saisir</button>
</div>
<?php endif ?>

<?php if ($joursNonSaisis > 0 && !$retardHier && !$retardAujourd): ?>
<div class="alerte-bar bleue">
    <div class="al-icon" style="color:#3b82f6"><i class="fas fa-info-circle"></i></div>
    <div>
        <div class="al-title" style="color:#1e40af"><?= $joursNonSaisis ?> jour(s) non renseigné(s) comptés dans la dette</div>
        <div class="al-sub">Depuis <?= formatDate($dateDebut) ?>. Vérifiez si ce sont des jours off.</div>
    </div>
</div>
<?php endif ?>

<!-- ════════════════════════════════════════════════════════════════
     KPIs
════════════════════════════════════════════════════════════════ -->
<div class="g4">
    <!-- KPI 1 : Dette -->
    <div class="kpi-vtc" style="border-left:3px solid #ef4444">
        <div class="ki"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="kl">Dette chauffeur</div>
        <div class="kv" style="color:<?= $detteTotal > 0 ? '#ef4444' : '#10b981' ?>"><?= formatMoney($detteTotal) ?></div>
        <div class="ks">
            Tarif : <?= formatMoney($tarif) ?>/j
            <?php if ($joursNonSaisis > 0): ?> · <?= $joursNonSaisis ?>j non saisis<?php endif ?>
        </div>
        <div class="kbar"><div class="kbar-fill" style="width:<?= $totalDu > 0 ? min(100,round($totalPercu/$totalDu*100)) : 0 ?>%;background:<?= $detteTotal > 0 ? '#ef4444' : '#10b981' ?>"></div></div>
    </div>
    <!-- KPI 2 : Versements -->
    <div class="kpi-vtc" style="border-left:3px solid #10b981">
        <div class="ki"><i class="fas fa-money-bill-wave"></i></div>
        <div class="kl">Total versé</div>
        <div class="kv" style="color:#10b981"><?= formatMoney($totalPercu) ?></div>
        <div class="ks">
            Dû : <?= formatMoney($totalDu) ?> · <?= (int)$solde['jours_payes'] ?> payés / <?= $joursTravaill ?> travaillés
        </div>
        <div class="kbar"><div class="kbar-fill" style="width:<?= $totalDu > 0 ? min(100,round($totalPercu/$totalDu*100)) : 100 ?>%;background:#10b981"></div></div>
    </div>
    <!-- KPI 3 : Fonds contravention -->
    <div class="kpi-vtc" style="border-left:3px solid <?= $fondsSolde >= 0 ? '#0d9488' : '#ef4444' ?>">
        <div class="ki"><i class="fas fa-shield-alt"></i></div>
        <div class="kl">Solde fonds contravention</div>
        <div class="kv" style="color:<?= $fondsSolde > 0 ? '#0d9488' : ($fondsSolde < 0 ? '#ef4444' : '#94a3b8') ?>"><?= $fondsSolde >= 0 ? formatMoney($fondsSolde) : '-'.formatMoney(abs($fondsSolde)) ?></div>
        <div class="ks">
            Cotisé : <?= formatMoney($fondsTotal) ?> · <?= $nbContraventions ?> contrav. (<?= formatMoney($contraTotalMontant) ?>)
        </div>
        <div class="kbar"><div class="kbar-fill" style="width:<?= $fondsTotal > 0 ? min(100,max(0,round(max(0,$fondsSolde)/$fondsTotal*100))) : 0 ?>%;background:<?= $fondsSolde >= 0 ? '#0d9488' : '#ef4444' ?>"></div></div>
    </div>
    <!-- KPI 4 : Activité -->
    <div class="kpi-vtc" style="border-left:3px solid #8b5cf6">
        <div class="ki"><i class="fas fa-calendar-check"></i></div>
        <div class="kl">Présence 30j</div>
        <div class="kv" style="color:#8b5cf6"><?= $tauxPresence ?>%</div>
        <div class="ks">
            <?= $nbJoursPeriode ?> j. total · <?= $joursOff ?> off · <?= $joursNonSaisis ?> non saisis
        </div>
        <div class="kbar"><div class="kbar-fill" style="width:<?= $tauxPresence ?>%;background:<?= $tauxPresence >= 80 ? '#10b981' : ($tauxPresence >= 50 ? '#f59e0b' : '#ef4444') ?>"></div></div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     CALENDRIER + ACTIONS RAPIDES
════════════════════════════════════════════════════════════════ -->
<div class="g2">
    <!-- Calendrier mensuel -->
    <div class="cal-wrap">
        <div class="cal-nav">
            <a href="?id=<?= $taxiId ?>&mois=<?= $moisPrev ?>" class="btn btn-ghost btn-sm"><i class="fas fa-chevron-left"></i></a>
            <h3><?= htmlspecialchars($moisLabel) ?></h3>
            <a href="?id=<?= $taxiId ?>&mois=<?= $moisNext ?>" class="btn btn-ghost btn-sm"><i class="fas fa-chevron-right"></i></a>
        </div>
        <div class="cal-grid">
            <?php
            $jours = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
            foreach ($jours as $j): ?>
            <div class="cal-head"><?= $j ?></div>
            <?php endforeach ?>

            <?php
            // Cellules vides avant le 1er du mois
            for ($v = 1; $v < $premierJour; $v++): ?>
            <div class="cal-day vide"></div>
            <?php endfor;

            $libStatut = ['paye'=>'Payé','non_paye'=>'Non payé','jour_off'=>'Jour off','panne'=>'Panne','accident'=>'Accident','maladie'=>'Maladie'];
            $iconStatut= ['paye'=>'✓','non_paye'=>'✗','jour_off'=>'—','panne'=>'P','accident'=>'A','maladie'=>'M'];
            // Déterminer le numéro ISO du jour de repos (1=Lun...7=Dim)
            $reposN = $jourReposConfig !== null ? $jourReposConfig + 1 : null;

            for ($d = 1; $d <= $nbJoursMois; $d++):
                $dateStr   = "$moisCal-".sprintf('%02d',$d);
                $isToday   = ($dateStr === $today);
                $isFutur   = ($dateStr > $today);
                $avantDeb  = ($dateStr < $dateDebut);
                $jouData   = $joursMoisMap[$dateStr] ?? null;
                $statJ     = $jouData['statut_jour'] ?? null;
                $dowIso    = (int)date('N', strtotime($dateStr)); // 1=Lun...7=Dim
                $isReposAuto = ($reposN !== null && $dowIso === $reposN && !$statJ && !$isFutur && !$avantDeb);
                $dayClass  = $isToday ? ' today' : '';
                if ($isFutur)    $dayClass .= ' futur';
                if ($avantDeb)   $dayClass .= ' avant_debut';
                if ($isReposAuto)$dayClass .= ' repos-auto';
                $clickable = !$isFutur && !$avantDeb;
            ?>
            <div class="cal-day<?= $dayClass ?>" <?= $clickable ? "onclick=\"ouvrirSaisir('$dateStr')" . ($jouData ? ", ".htmlspecialchars(json_encode($jouData)) : ', null') . "\"" : '' ?>>
                <div class="cal-num" style="<?= $isToday ? 'color:#0d9488;font-size:.88rem' : ($isReposAuto ? 'color:#94a3b8' : '') ?>"><?= $d ?><?= $isToday ? ' ●' : '' ?></div>
                <?php if ($statJ): ?>
                <div class="cal-statut s-<?= $statJ ?>"><?= ($iconStatut[$statJ]??'') ?> <?= $libStatut[$statJ] ?></div>
                <?php if ($statJ === 'paye'): ?>
                <div class="cal-montant" style="color:#166534;font-weight:700">+<?= number_format((float)$jouData['montant'],0,',',' ') ?> F</div>
                <?php elseif ($statJ === 'non_paye'): ?>
                <div class="cal-montant" style="color:#991b1b">-<?= number_format($tarif,0,',',' ') ?> F</div>
                <?php elseif (in_array($statJ,['jour_off','panne','accident','maladie'])): ?>
                <div class="cal-montant" style="color:#94a3b8;font-size:.58rem">non compté</div>
                <?php endif ?>
                <?php elseif ($isReposAuto): ?>
                <div class="cal-statut" style="background:#f1f5f9;color:#94a3b8;font-size:.6rem">Repos</div>
                <?php elseif (!$isFutur && !$avantDeb): ?>
                <div class="cal-statut" style="background:#fee2e2;color:#991b1b;font-size:.58rem;border:1px dashed #fca5a5">Non saisi</div>
                <div class="cal-montant" style="color:#dc2626;font-weight:700">-<?= number_format($tarif,0,',',' ') ?> F</div>
                <?php endif ?>
            </div>
            <?php endfor ?>

            <?php
            // Cellules vides après le dernier jour
            $dernierJour = date('N', strtotime("$moisCal-$nbJoursMois"));
            for ($v = $dernierJour + 1; $v <= 7; $v++): ?>
            <div class="cal-day vide"></div>
            <?php endfor ?>
        </div>
        <div class="cal-legend">
            <div class="leg-item"><div class="leg-dot s-paye"></div> Payé</div>
            <div class="leg-item"><div class="leg-dot s-non_paye"></div> Non payé</div>
            <div class="leg-item"><div class="leg-dot s-jour_off"></div> Jour off</div>
            <div class="leg-item"><div class="leg-dot s-panne"></div> Panne</div>
            <div class="leg-item"><div class="leg-dot s-accident"></div> Accident</div>
            <div class="leg-item"><div class="leg-dot s-maladie"></div> Maladie</div>
            <div class="leg-item"><div class="leg-dot" style="background:#f1f5f9;border:1px solid #e2e8f0"></div> Repos auto</div>
            <div class="leg-item"><div class="leg-dot" style="background:#fee2e2;border:1px dashed #fca5a5"></div> ⚠ Non saisi = dette !</div>
            <div style="margin-left:auto">
                <button class="btn btn-primary btn-sm" onclick="openModal('modal-saisir')"><i class="fas fa-plus"></i> Saisir un jour</button>
            </div>
        </div>
        <div style="padding:8px 16px;background:#fffbeb;border-top:1px solid #fde68a;font-size:.72rem;color:#92400e">
            <i class="fas fa-info-circle"></i>
            <strong>Règle :</strong> Tout jour non marqué comme "Jour off / Panne / Accident" est considéré comme un jour <strong>travaillé dû</strong>.
            Cliquez sur un jour pour le renseigner ou le corriger.
        </div>
    </div>

    <!-- Panneau latéral : Fonds + Actions rapides + Résumé -->
    <div style="display:flex;flex-direction:column;gap:12px">

        <!-- Fonds contravention -->
        <div class="fonds-card" style="<?= $fondsSolde < 0 ? 'background:linear-gradient(135deg,#dc2626 0%,#991b1b 100%)' : '' ?>">
            <div class="fl"><i class="fas fa-shield-alt"></i> Solde fonds contravention</div>
            <div class="fv"><?= $fondsSolde >= 0 ? formatMoney($fondsSolde) : '-'.formatMoney(abs($fondsSolde)) ?></div>
            <div class="fs">
                Cotisé : <?= formatMoney($fondsTotal) ?> · Contraventions : <?= formatMoney($contraTotalMontant) ?> (<?= $nbContraventions ?>)
                <?php if ($detteContra > 0): ?><br>Dette contrav. : <?= formatMoney($detteContra) ?><?php endif ?>
            </div>
            <div style="margin-top:10px;display:flex;gap:8px">
                <button onclick="openModal('modal-saisir')" style="background:rgba(255,255,255,.2);border:none;color:#fff;padding:5px 12px;border-radius:6px;font-size:.75rem;cursor:pointer;font-weight:600">
                    <i class="fas fa-plus-circle"></i> Cotiser ce jour
                </button>
                <button onclick="openModal('modal-contravention')" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.4);color:#fff;padding:5px 12px;border-radius:6px;font-size:.75rem;cursor:pointer;font-weight:600">
                    <i class="fas fa-gavel"></i> Contravention
                </button>
            </div>
        </div>

        <!-- Infos véhicule assigné -->
        <div class="card" style="margin:0;overflow:hidden">
            <div style="background:<?= $taxi['veh_statut']==='maintenance' ? '#fef3c7' : '#f0f9ff' ?>;padding:10px 14px;border-bottom:1px solid #e2e8f0">
                <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;font-weight:700;color:#64748b;margin-bottom:4px">
                    <i class="fas fa-car"></i> Véhicule actuellement assigné
                </div>
                <div style="font-size:1rem;font-weight:800;color:#0f172a"><?= sanitize($taxi['veh_nom']) ?></div>
                <div style="font-size:.78rem;color:#64748b"><?= sanitize($taxi['immatriculation']) ?> &nbsp;·&nbsp; <?= sanitize(trim(($taxi['marque']??'').' '.($taxi['modele']??''))) ?></div>
                <div style="margin-top:6px;display:flex;align-items:center;gap:8px">
                    <span class="veh-badge <?= $taxi['veh_statut']==='loue' ? 'bg-primary' : ($taxi['veh_statut']==='maintenance' ? '' : 'bg-success') ?>"
                        style="<?= $taxi['veh_statut']==='maintenance' ? 'background:#f59e0b;color:#fff' : '' ?>">
                        <?php if ($taxi['veh_statut']==='maintenance'): ?><i class="fas fa-wrench"></i><?php endif ?>
                        <?= ucfirst($taxi['veh_statut']) ?>
                    </span>
                    <span style="font-size:.72rem;color:#64748b"><i class="fas fa-road"></i> <?= number_format((int)$taxi['kilometrage_actuel'],0,',',' ') ?> km</span>
                </div>
            </div>
            <?php if ($taxi['veh_statut'] === 'maintenance'): ?>
            <div style="padding:8px 14px;background:#fff7ed;border-bottom:1px solid #fed7aa;font-size:.75rem;color:#92400e">
                <i class="fas fa-exclamation-triangle"></i>
                Ce véhicule est en <strong>maintenance</strong>. Le chauffeur ne peut pas travailler dessus.
                Assignez-lui un autre véhicule disponible ou marquez ses journées comme "Panne".
            </div>
            <?php endif ?>
            <div style="padding:12px 14px">
                <button onclick="openModal('modal-vehicule')" class="btn btn-primary" style="width:100%;justify-content:center">
                    <i class="fas fa-exchange-alt"></i>
                    <?= $taxi['veh_statut']==='maintenance' ? 'Assigner un autre véhicule' : 'Changer de véhicule' ?>
                </button>
                <?php if (!empty($veiculesDispos)): ?>
                <div style="font-size:.7rem;color:#10b981;text-align:center;margin-top:6px">
                    <i class="fas fa-check-circle"></i> <?= count($veiculesDispos) ?> véhicule(s) disponible(s)
                </div>
                <?php else: ?>
                <div style="font-size:.7rem;color:#94a3b8;text-align:center;margin-top:6px">
                    <i class="fas fa-info-circle"></i> Aucun autre véhicule disponible
                </div>
                <?php endif ?>
            </div>
        </div>

        <!-- Résumé mensuel -->
        <div class="card" style="margin:0">
            <div class="card-header" style="padding:10px 14px">
                <h3 class="card-title" style="font-size:.82rem"><i class="fas fa-chart-bar"></i> Résumé 6 mois</h3>
            </div>
            <table style="width:100%;font-size:.73rem;border-collapse:collapse">
                <thead>
                    <tr style="background:#f8fafc">
                        <th style="padding:5px 10px;color:#94a3b8;font-weight:600;text-transform:uppercase;font-size:.63rem;text-align:left">Mois</th>
                        <th style="padding:5px;text-align:center;color:#94a3b8;font-size:.63rem">Jours</th>
                        <th style="padding:5px;text-align:right;color:#94a3b8;font-size:.63rem">Dû</th>
                        <th style="padding:5px;text-align:right;color:#94a3b8;font-size:.63rem">Perçu</th>
                        <th style="padding:5px;text-align:right;color:#94a3b8;font-size:.63rem">Solde</th>
                        <th style="padding:5px 10px;text-align:right;color:#94a3b8;font-size:.63rem">Fonds</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($resumeMensuel as $rm):
                    $du   = $rm['jours_trav'] * $tarif;
                    $soldeM = (float)$rm['percu'] - $du;
                ?>
                <tr style="border-bottom:1px solid #f1f5f9">
                    <td style="padding:5px 10px;font-weight:600;color:#0f172a"><?= date('M Y', strtotime($rm['m'].'-01')) ?></td>
                    <td style="padding:5px;text-align:center;color:#64748b"><?= $rm['jours_payes'] ?>/<?= $rm['jours_trav'] ?></td>
                    <td style="padding:5px;text-align:right;color:#64748b"><?= number_format($du,0,',',' ') ?></td>
                    <td style="padding:5px;text-align:right;color:#10b981;font-weight:600"><?= number_format((float)$rm['percu'],0,',',' ') ?></td>
                    <td style="padding:5px;text-align:right;font-weight:700;color:<?= $soldeM >= 0 ? '#10b981' : '#ef4444' ?>"><?= ($soldeM >= 0 ? '+' : '') . number_format($soldeM,0,',',' ') ?></td>
                    <td style="padding:5px 10px;text-align:right;color:#0d9488"><?= (float)$rm['fonds'] > 0 ? number_format((float)$rm['fonds'],0,',',' ') : '—' ?></td>
                </tr>
                <?php endforeach ?>
                <?php if (empty($resumeMensuel)): ?>
                <tr><td colspan="6" style="padding:12px;text-align:center;color:#94a3b8">Aucune donnée</td></tr>
                <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     CONTRAVENTIONS
════════════════════════════════════════════════════════════════ -->
<?php if (!empty($contraventions)): ?>
<div class="card" style="margin-bottom:14px">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-gavel" style="color:#8b5cf6"></i> Contraventions</h3>
        <div style="display:flex;align-items:center;gap:10px">
            <span style="font-size:.78rem;color:#64748b">Fonds restant : <strong style="color:#0d9488"><?= formatMoney($fondsBalance) ?></strong></span>
            <button onclick="openModal('modal-contravention')" class="btn btn-sm btn-outline-primary"><i class="fas fa-plus"></i> Ajouter</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table hist-table">
            <thead><tr><th>Date</th><th>Description</th><th style="text-align:right">Montant</th><th>Source</th></tr></thead>
            <tbody>
            <?php foreach ($contraventions as $cv): ?>
            <tr>
                <td><?= formatDate($cv['date_charge']) ?></td>
                <td><?= sanitize($cv['libelle']) ?></td>
                <td style="text-align:right;font-weight:700;color:#8b5cf6"><?= formatMoney((float)$cv['montant']) ?></td>
                <td>
                    <?php if ($cv['statut'] === 'regle_fonds'): ?>
                    <span class="badge bg-primary" style="font-size:.68rem"><i class="fas fa-shield-alt"></i> Fonds</span>
                    <?php elseif ($cv['statut'] === 'regle_versement'): ?>
                    <span class="badge bg-success" style="font-size:.68rem"><i class="fas fa-check"></i> Versement</span>
                    <?php else: ?>
                    <span class="badge bg-danger" style="font-size:.68rem"><i class="fas fa-exclamation-circle"></i> Dette</span>
                    <form method="POST" style="display:inline;margin-left:4px">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="regler_contravention">
                        <input type="hidden" name="contra_id" value="<?= $cv['id'] ?>">
                        <button type="submit" style="background:#10b981;color:#fff;border:none;border-radius:4px;padding:2px 7px;font-size:.65rem;cursor:pointer" onclick="return confirm('Marquer cette contravention comme réglée par versement direct ?')">
                            ✓ Réglé
                        </button>
                    </form>
                    <?php endif ?>
                </td>
            </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif ?>

<!-- ════════════════════════════════════════════════════════════════
     HISTORIQUE COMPLET — MOUVEMENTS FUSIONNÉS
════════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:14px">
    <div class="card-header" style="flex-wrap:wrap;gap:8px">
        <h3 class="card-title"><i class="fas fa-history"></i> Historique des mouvements <?php if (!$filtreDebut && !$filtreFin): ?><span style="font-weight:400;font-size:.78rem;color:#94a3b8">(30 derniers)</span><?php endif ?></h3>
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
            <form method="GET" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                <input type="hidden" name="id" value="<?= $taxiId ?>">
                <input type="hidden" name="mois" value="<?= $moisCal ?>">
                <input type="date" name="hd" class="form-control" style="width:130px;font-size:.75rem;padding:5px 8px" value="<?= sanitize($filtreDebut) ?>" placeholder="Début">
                <input type="date" name="hf" class="form-control" style="width:130px;font-size:.75rem;padding:5px 8px" value="<?= sanitize($filtreFin) ?>" placeholder="Fin">
                <button type="submit" class="btn btn-sm btn-primary">Filtrer</button>
                <?php if ($filtreDebut || $filtreFin): ?>
                <a href="?id=<?= $taxiId ?>&mois=<?= $moisCal ?>" class="btn btn-sm btn-secondary">Reset</a>
                <?php endif ?>
            </form>
            <a href="?id=<?= $taxiId ?>&action=export_excel" class="btn btn-sm btn-success"><i class="fas fa-file-excel"></i> Export Excel</a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table hist-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Statut / Description</th>
                    <th style="text-align:right">Dû</th>
                    <th style="text-align:right">Perçu</th>
                    <th style="text-align:right">Solde</th>
                    <th style="text-align:right">Fonds</th>
                    <th>Notes</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $libStatut2 = ['paye'=>'Payé','non_paye'=>'Non payé','jour_off'=>'Jour off','panne'=>'Panne','accident'=>'Accident','maladie'=>'Maladie'];
            $rowClass   = ['paye'=>'row-paye','non_paye'=>'row-non_paye','jour_off'=>'row-off','panne'=>'row-off','accident'=>'row-non_paye','maladie'=>'row-off'];

            // Fusionner paiements + contraventions et trier par date desc
            $mouvements = [];
            foreach ($historique as $h) { $h['sort_date'] = $h['date_paiement']; $h['type_mouv'] = 'paiement'; $mouvements[] = $h; }
            foreach ($contraHistorique as $c) { $c['sort_date'] = $c['date_paiement']; $c['type_mouv'] = 'contravention'; $mouvements[] = $c; }
            usort($mouvements, function($a,$b){ return strcmp($b['sort_date'], $a['sort_date']); });

            foreach ($mouvements as $mv):
                if ($mv['type_mouv'] === 'paiement'):
                    $h = $mv;
                    $du      = in_array($h['statut_jour'],['paye','non_paye']) ? $tarif : 0;
                    $soldeJ  = (float)$h['montant'] - $du;
                    $cls     = $rowClass[$h['statut_jour']] ?? '';
            ?>
            <tr class="<?= $cls ?>">
                <td style="font-weight:600;white-space:nowrap"><?= formatDate($h['date_paiement']) ?></td>
                <td><span style="font-size:.65rem;font-weight:600;color:#64748b;text-transform:uppercase">Journée</span></td>
                <td>
                    <span class="cal-statut s-<?= $h['statut_jour'] ?>" style="width:auto;padding:2px 8px;font-size:.67rem">
                        <?= $libStatut2[$h['statut_jour']] ?? $h['statut_jour'] ?>
                    </span>
                </td>
                <td style="text-align:right;color:#64748b"><?= $du > 0 ? formatMoney($du) : '—' ?></td>
                <td style="text-align:right;color:#10b981;font-weight:<?= (float)$h['montant'] > 0 ? '700' : '400' ?>">
                    <?= (float)$h['montant'] > 0 ? formatMoney((float)$h['montant']) : '—' ?>
                </td>
                <td style="text-align:right;font-weight:700;color:<?= $soldeJ >= 0 ? '#10b981' : '#ef4444' ?>">
                    <?= $du > 0 ? (($soldeJ >= 0 ? '+' : '').formatMoney($soldeJ)) : '—' ?>
                </td>
                <td style="text-align:right;color:#0d9488"><?= (float)($h['montant_fonds']??0) > 0 ? '+'.formatMoney((float)$h['montant_fonds']) : '—' ?></td>
                <td style="font-size:.72rem;color:#94a3b8;max-width:120px;overflow:hidden;text-overflow:ellipsis">
                    <?= $h['km_debut'] ? number_format((int)$h['km_debut'],0,',',' ').'→'.number_format((int)$h['km_fin'],0,',',' ').' · ' : '' ?><?= sanitize($h['notes'] ?? '') ?>
                </td>
                <td>
                    <button class="btn btn-sm btn-ghost" onclick="ouvrirSaisir('<?= $h['date_paiement'] ?>',<?= htmlspecialchars(json_encode($h)) ?>)" title="Corriger">
                        <i class="fas fa-edit"></i>
                    </button>
                </td>
            </tr>
            <?php else:
                    $c = $mv;
                    $libContraStatut = ['regle_fonds'=>'Fonds','regle_versement'=>'Versement','en_attente'=>'Dette'];
                    $colorContra     = ['regle_fonds'=>'#0d9488','regle_versement'=>'#10b981','en_attente'=>'#ef4444'];
                    $bgContra        = ['regle_fonds'=>'#f0fdfa','regle_versement'=>'#f0fdf4','en_attente'=>'#fef2f2'];
            ?>
            <tr style="background:<?= $bgContra[$c['statut']] ?? '#fff' ?>">
                <td style="font-weight:600;white-space:nowrap"><?= formatDate($c['date_paiement']) ?></td>
                <td><span style="font-size:.65rem;font-weight:700;color:#8b5cf6;text-transform:uppercase"><i class="fas fa-gavel"></i> Contrav.</span></td>
                <td style="font-size:.78rem"><?= sanitize($c['description'] ?? '') ?></td>
                <td style="text-align:right">—</td>
                <td style="text-align:right">—</td>
                <td style="text-align:right;font-weight:700;color:<?= $colorContra[$c['statut']] ?? '#64748b' ?>">-<?= formatMoney((float)$c['montant']) ?></td>
                <td style="text-align:right">
                    <span style="font-size:.65rem;font-weight:700;padding:2px 6px;border-radius:4px;background:<?= $bgContra[$c['statut']] ?? '#f1f5f9' ?>;color:<?= $colorContra[$c['statut']] ?? '#64748b' ?>">
                        <?= $libContraStatut[$c['statut']] ?? $c['statut'] ?>
                    </span>
                </td>
                <td style="font-size:.72rem;color:#94a3b8"><?= sanitize($c['notes'] ?? '') ?></td>
                <td>
                    <?php if ($c['statut'] === 'en_attente'): ?>
                    <form method="POST" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="regler_contravention">
                        <input type="hidden" name="contra_id" value="<?= $c['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-ghost" style="color:#10b981" onclick="return confirm('Marquer réglée ?')" title="Marquer réglée">
                            <i class="fas fa-check"></i>
                        </button>
                    </form>
                    <?php endif ?>
                </td>
            </tr>
            <?php endif; endforeach ?>
            <?php if (empty($mouvements)): ?>
            <tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:30px">Aucun mouvement<?= ($filtreDebut || $filtreFin) ? ' pour cette période' : '' ?></td></tr>
            <?php endif ?>
            </tbody>
            <tfoot style="background:#f8fafc;font-size:.78rem;font-weight:700">
                <tr>
                    <td colspan="3">TOTAL GLOBAL</td>
                    <td style="text-align:right;color:#64748b"><?= formatMoney($totalDu) ?></td>
                    <td style="text-align:right;color:#10b981"><?= formatMoney($totalPercu) ?></td>
                    <td style="text-align:right;color:<?= $detteTotal > 0 ? '#ef4444' : '#10b981' ?>"><?= $detteTotal > 0 ? '-'.formatMoney($detteTotal) : '+'.formatMoney($totalPercu - $totalDu) ?></td>
                    <td style="text-align:right;color:#0d9488"><?= formatMoney($fondsTotal) ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     MODALS
════════════════════════════════════════════════════════════════ -->

<!-- MODAL : Saisir / Corriger un jour -->
<div id="modal-saisir" class="modal-overlay">
    <div class="modal" style="max-width:520px">
        <div class="modal-header">
            <h3><i class="fas fa-calendar-day"></i> <span id="modal-saisir-title">Saisir une journée</span></h3>
            <button class="modal-close" onclick="closeModal('modal-saisir')">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="saisir_jour">

            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Date *</label>
                    <input type="date" name="date_paiement" id="date_paiement_m" class="form-control" value="<?= $today ?>" max="<?= $today ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Statut *</label>
                    <select name="statut_jour" id="statut_jour_m" class="form-control" onchange="toggleSaisirFields(this.value)">
                        <optgroup label="— Jours travaillés (comptent dans la dette) —">
                            <option value="paye">✓ Payé — versement reçu</option>
                            <option value="non_paye">✗ Non payé — dette enregistrée</option>
                        </optgroup>
                        <optgroup label="— Jours exclus de la dette —">
                            <option value="jour_off">○ Jour off — congé / repos exceptionnel</option>
                            <option value="maladie">🤒 Maladie — arrêt maladie</option>
                            <option value="panne">⚙ Panne véhicule — immobilisation</option>
                            <option value="accident">⚡ Accident</option>
                        </optgroup>
                    </select>
                    <span class="form-hint" id="hint-statut" style="color:#10b981;font-size:.72rem">Ce jour sera comptabilisé comme payé</span>
                </div>
            </div>

            <div id="bloc-paiement">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Montant versé</label>
                        <input type="number" name="montant" id="montant_m" class="form-control" value="<?= $tarif ?>" min="0" max="<?= $tarif ?>" step="1">
                        <span class="form-hint">Tarif journalier : <?= formatMoney($tarif) ?></span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mode de paiement</label>
                        <select name="mode_paiement" class="form-control">
                            <option value="especes">Espèces</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="virement">Virement</option>
                            <option value="cheque">Chèque</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Cotisation fonds contravention</label>
                    <input type="number" name="montant_fonds" id="montant_fonds_m" class="form-control" value="0" min="0" step="1">
                    <span class="form-hint">Solde fonds actuel : <strong style="color:#0d9488"><?= formatMoney($fondsBalance) ?></strong></span>
                </div>
            </div>

            <div id="bloc-absence" style="display:none">
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:12px;font-size:.82rem;color:#64748b">
                    <i class="fas fa-info-circle" style="color:#14b8a6"></i>
                    Cette journée <strong>ne sera pas comptabilisée</strong> dans la dette du chauffeur.
                </div>
            </div>

            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Km début</label>
                    <input type="number" name="km_debut" id="km_debut_m" class="form-control" placeholder="Ex: 125000">
                </div>
                <div class="form-group">
                    <label class="form-label">Km fin</label>
                    <input type="number" name="km_fin" id="km_fin_m" class="form-control" placeholder="Ex: 125480">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" id="notes_m" class="form-control" placeholder="Observations...">
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-saisir')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL : Contravention -->
<div id="modal-contravention" class="modal-overlay">
    <div class="modal" style="max-width:460px">
        <div class="modal-header">
            <h3><i class="fas fa-gavel"></i> Enregistrer une contravention</h3>
            <button class="modal-close" onclick="closeModal('modal-contravention')">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="ajouter_contravention">
            <div style="background:<?= $fondsBalance > 0 ? '#eff6ff' : '#fff1f2' ?>;border:1px solid <?= $fondsBalance > 0 ? '#93c5fd' : '#fca5a5' ?>;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.82rem">
                <strong>Fonds disponible : <?= formatMoney($fondsBalance) ?></strong>
                <?php if ($fondsBalance > 0): ?>
                <br><span style="color:#0d9488;font-size:.75rem">La contravention sera déduite automatiquement du fonds.</span>
                <?php else: ?>
                <br><span style="color:#dc2626;font-size:.75rem">⚠ Fonds insuffisant — la contravention sera ajoutée à la dette du chauffeur.</span>
                <?php endif ?>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Date *</label>
                    <input type="date" name="date_contravention" class="form-control" value="<?= $today ?>" max="<?= $today ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Montant (FCFA) *</label>
                    <input type="number" name="montant_contravention" class="form-control" placeholder="Ex: 15000" min="1" step="1" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Description *</label>
                <input type="text" name="description_contravention" class="form-control" placeholder="Ex: Excès de vitesse RN1..." required>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-contravention')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL : Changer véhicule -->
<div id="modal-vehicule" class="modal-overlay">
    <div class="modal" style="max-width:520px">
        <div class="modal-header">
            <h3><i class="fas fa-exchange-alt"></i> Assigner un véhicule au chauffeur</h3>
            <button class="modal-close" onclick="closeModal('modal-vehicule')">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="changer_vehicule">

            <!-- Véhicule actuel -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:16px">
                <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;font-weight:600;margin-bottom:4px">Véhicule actuel</div>
                <div style="font-weight:700;color:#0f172a"><?= sanitize($taxi['veh_nom']) ?> <span style="color:#64748b;font-weight:400">— <?= sanitize($taxi['immatriculation']) ?></span></div>
                <span class="veh-badge <?= $taxi['veh_statut']==='maintenance'?'':'bg-'.($taxi['veh_statut']==='disponible'?'success':'primary') ?>"
                    style="margin-top:4px;<?= $taxi['veh_statut']==='maintenance'?'background:#f59e0b;color:#fff':'' ?>">
                    <?= ucfirst($taxi['veh_statut']) ?>
                </span>
            </div>

            <!-- Nouveau véhicule -->
            <div class="form-group">
                <label class="form-label" style="font-weight:700">Choisir le nouveau véhicule *</label>
                <?php if (!empty($veiculesDispos)): ?>
                <!-- Affichage en cartes cliquables -->
                <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:8px" id="veh-cards">
                    <?php foreach ($veiculesDispos as $vd):
                        $couleurStatut = $vd['statut']==='disponible' ? '#10b981' : ($vd['statut']==='maintenance'?'#f59e0b':'#14b8a6');
                        $bgStatut = $vd['statut']==='disponible' ? '#f0fdf4' : ($vd['statut']==='maintenance'?'#fffbeb':'#eff6ff');
                    ?>
                    <label style="display:flex;align-items:center;gap:12px;padding:10px 14px;border:2px solid #e2e8f0;border-radius:8px;cursor:pointer;background:#fff;transition:all .15s"
                        onmouseover="this.style.borderColor='#0d9488'" onmouseout="if(!this.querySelector('input').checked)this.style.borderColor='#e2e8f0'"
                        onclick="this.style.borderColor='#0d9488';this.style.background='#eff6ff'">
                        <input type="radio" name="new_vehicule_id" value="<?= $vd['id'] ?>" required style="accent-color:#0d9488">
                        <div style="flex:1">
                            <div style="font-weight:700;color:#0f172a"><?= sanitize($vd['nom']) ?></div>
                            <div style="font-size:.75rem;color:#64748b"><?= sanitize($vd['immatriculation']) ?> &nbsp;·&nbsp; <?= sanitize(trim(($vd['marque']??'').' '.($vd['modele']??''))) ?></div>
                        </div>
                        <span style="font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:99px;background:<?= $bgStatut ?>;color:<?= $couleurStatut ?>">
                            <?= ucfirst($vd['statut']) ?>
                        </span>
                    </label>
                    <?php endforeach ?>
                </div>
                <?php else: ?>
                <div style="background:#f8fafc;border:1px dashed #d1d5db;border-radius:8px;padding:20px;text-align:center;color:#94a3b8;font-size:.82rem">
                    <i class="fas fa-car" style="font-size:1.5rem;display:block;margin-bottom:8px"></i>
                    Aucun autre véhicule disponible dans la flotte.<br>
                    <a href="<?= BASE_URL ?>app/vehicules/liste.php" style="color:#0d9488;font-size:.78rem">Voir la liste des véhicules →</a>
                </div>
                <?php endif ?>
            </div>

            <div class="form-group">
                <label class="form-label">Raison du changement <span style="color:#94a3b8;font-weight:400">(optionnel)</span></label>
                <input type="text" name="motif" class="form-control" placeholder="Ex: Véhicule principal en maintenance, prêt temporaire, affectation définitive...">
            </div>

            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;font-size:.78rem;color:#1e40af;margin-bottom:16px">
                <i class="fas fa-info-circle"></i>
                Le changement sera enregistré dans les notes du chauffeur avec la date et l'ancien véhicule pour traçabilité.
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-vehicule')">Annuler</button>
                <button type="submit" class="btn btn-primary" <?= empty($veiculesDispos) ? 'disabled' : '' ?>>
                    <i class="fas fa-check"></i> Confirmer l'assignation
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL : Modifier profil -->
<div id="modal-profil" class="modal-overlay">
    <div class="modal" style="max-width:500px">
        <div class="modal-header">
            <h3><i class="fas fa-user-edit"></i> Modifier le profil</h3>
            <button class="modal-close" onclick="closeModal('modal-profil')">&times;</button>
        </div>
        <form method="POST" style="padding:20px">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="modifier_profil">
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Nom *</label>
                    <input type="text" name="nom" class="form-control" value="<?= sanitize($taxi['nom']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Prénom</label>
                    <input type="text" name="prenom" class="form-control" value="<?= sanitize($taxi['prenom'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Téléphone</label>
                    <input type="tel" name="telephone" class="form-control" value="<?= sanitize($taxi['telephone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Tarif journalier (FCFA) *</label>
                    <input type="number" name="tarif_journalier" class="form-control" value="<?= $tarif ?>" step="1" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Caution versée (FCFA)</label>
                <input type="number" name="caution_versee" class="form-control" value="<?= $taxi['caution_versee'] ?>" step="1">
            </div>
            <div class="form-group">
                <label class="form-label">🛌 Jour de repos hebdomadaire <span style="color:#94a3b8;font-weight:400">(exclu automatiquement de la dette)</span></label>
                <select name="jour_repos" class="form-control">
                    <option value="">— Aucun repos fixe (saisir manuellement) —</option>
                    <?php
                    $jrNoms = ['Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi','Dimanche'];
                    foreach ($jrNoms as $idx => $jn):
                    ?>
                    <option value="<?= $idx ?>" <?= ($taxi['jour_repos'] !== null && (int)$taxi['jour_repos'] === $idx) ? 'selected' : '' ?>>
                        <?= $jn ?> <?= $idx === 6 ? '(Dimanche — recommandé)' : '' ?>
                    </option>
                    <?php endforeach ?>
                </select>
                <span class="form-hint">Ex : si le chauffeur ne travaille jamais le dimanche, les dimanches seront automatiquement exclus de sa dette.</span>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= sanitize($taxi['notes'] ?? '') ?></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-profil')">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<script>
var TARIF = <?= $tarif ?>;

// Ouvrir modal saisie depuis le calendrier ou l'historique
function ouvrirSaisir(date, data) {
    var m = document.getElementById;
    document.getElementById('date_paiement_m').value = date;
    document.getElementById('modal-saisir-title').textContent = data ? 'Corriger le ' + formatDateFr(date) : 'Saisir le ' + formatDateFr(date);
    if (data) {
        document.getElementById('statut_jour_m').value = data.statut_jour || 'paye';
        document.getElementById('montant_m').value = data.montant || TARIF;
        document.getElementById('montant_fonds_m').value = data.montant_fonds || 0;
        document.getElementById('km_debut_m').value = data.km_debut || '';
        document.getElementById('km_fin_m').value = data.km_fin || '';
        document.getElementById('notes_m').value = data.notes || '';
    } else {
        document.getElementById('statut_jour_m').value = 'paye';
        document.getElementById('montant_m').value = TARIF;
        document.getElementById('montant_fonds_m').value = 0;
        document.getElementById('km_debut_m').value = '';
        document.getElementById('km_fin_m').value = '';
        document.getElementById('notes_m').value = '';
    }
    toggleSaisirFields(document.getElementById('statut_jour_m').value);
    openModal('modal-saisir');
}

function formatDateFr(d) {
    var p = d.split('-');
    return p[2]+'/'+p[1]+'/'+p[0];
}

// Afficher/masquer les champs selon le statut
function toggleSaisirFields(statut) {
    var isTravail = (statut === 'paye' || statut === 'non_paye');
    var isPaye    = (statut === 'paye');
    var isOff     = ['jour_off','maladie','panne','accident'].indexOf(statut) !== -1;
    document.getElementById('bloc-paiement').style.display = isTravail ? '' : 'none';
    document.getElementById('bloc-absence').style.display  = isTravail ? 'none' : '';
    if (statut === 'non_paye') {
        document.getElementById('montant_m').value = 0;
        document.getElementById('montant_m').readOnly = true;
    } else {
        document.getElementById('montant_m').readOnly = false;
        if (isPaye) document.getElementById('montant_m').value = TARIF;
    }
    var hints = {
        'paye'     : ['✓ Ce jour sera comptabilisé et versement enregistré','color:#10b981'],
        'non_paye' : ['✗ Ce jour sera comptabilisé mais non payé → dette augmente','color:#ef4444'],
        'jour_off' : ['○ Congé / repos — exclu de la dette','color:#64748b'],
        'maladie'  : ['🤒 Maladie — exclu de la dette','color:#7e22ce'],
        'panne'    : ['⚙ Panne véhicule — exclu de la dette','color:#f59e0b'],
        'accident' : ['⚡ Accident — exclu de la dette','color:#ef4444']
    };
    var h = hints[statut];
    if (h) {
        document.getElementById('hint-statut').textContent  = h[0];
        document.getElementById('hint-statut').style.cssText = h[1]+';font-size:.72rem';
    }
}

// Init
toggleSaisirFields(document.getElementById('statut_jour_m').value);
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
