<?php
/**
 * FlotteCar — Génération contrat de location Word (.docx)
 * Utilise PhpWord TemplateProcessor sur template_contrat_dynamic.docx
 */
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireTenantAuth();
require_once BASE_PATH . '/vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;

$db       = (new Database())->getConnection();
$tenantId = getTenantId();

$locationId = (int)($_GET['id'] ?? 0);
if (!$locationId) {
    redirect(BASE_URL . 'app/locations/liste.php');
}

// ── Charger la location ───────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT l.*,
           v.nom AS veh_nom, v.immatriculation, v.marque, v.modele,
           v.couleur, v.kilometrage_actuel, v.carburant_type,
           c.nom AS client_nom, c.prenom AS client_prenom,
           c.telephone AS client_tel, c.email AS client_email,
           c.adresse AS client_adresse, c.type_piece, c.numero_piece,
           c.date_naissance,
           ch.nom AS ch_nom, ch.prenom AS ch_prenom,
           ch.telephone AS ch_tel, ch.email AS ch_email,
           ch.adresse AS ch_adresse, ch.numero_permis, ch.date_permis
    FROM locations l
    JOIN vehicules v  ON v.id  = l.vehicule_id
    JOIN clients   c  ON c.id  = l.client_id
    LEFT JOIN chauffeurs ch ON ch.id = l.chauffeur_id
    WHERE l.id = ? AND l.tenant_id = ?
");
$stmt->execute([$locationId, $tenantId]);
$loc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$loc) {
    setFlash(FLASH_ERROR, 'Location introuvable.');
    redirect(BASE_URL . 'app/locations/liste.php');
}

// ── Charger paramètres contrat ────────────────────────────────────────────
$contratParams = [];
$r = $db->prepare("SELECT cle, valeur FROM parametres WHERE tenant_id = ? AND cle LIKE 'contrat_%'");
$r->execute([$tenantId]);
foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $contratParams[$row['cle']] = $row['valeur'];
}

function param(array $p, string $key, string $default = ''): string {
    return $p[$key] ?? $default;
}

function clean($v, bool $dots = true): string {
    if (empty($v) || trim((string)$v) === '') {
        return $dots ? '...................' : '';
    }
    return trim((string)$v);
}

// ── Préparer les variables ────────────────────────────────────────────────

// Dates
$dateContrat  = $loc['created_at'] ? date('d/m/Y', strtotime($loc['created_at'])) : date('d/m/Y');
$dateDebut    = formatDate($loc['date_debut']);
$dateFin      = formatDate($loc['date_fin']);
$heureDebut   = '08:00';
$heureFin     = '18:00';

// Client
$clientNomComplet = clean(trim($loc['client_nom'] . ' ' . ($loc['client_prenom'] ?? '')));
$clientCni        = clean($loc['numero_piece']);
$clientDateCni    = '...................';
$clientContact    = clean($loc['client_tel']);
$clientEmail      = clean($loc['client_email']);
$clientAdress     = clean($loc['client_adresse']);

// Véhicule
$residenceNom    = clean(trim($loc['marque'] . ' ' . ($loc['modele'] ?? '')));
$immatriculation = clean($loc['immatriculation']);
$couleur         = clean($loc['couleur']);
$kmDepart        = $loc['km_depart']
    ? number_format((int)$loc['km_depart'], 0, '', ' ')
    : number_format((int)$loc['kilometrage_actuel'], 0, '', ' ');
$carburantDepart = clean($loc['carburant_depart']);

// Conducteur : si location avec chauffeur → données chauffeur, sinon données client
$avecChauffeur = !empty($loc['avec_chauffeur']) && !empty($loc['ch_nom']);
if ($avecChauffeur) {
    $nomConducteur      = clean(trim($loc['ch_nom'] . ' ' . ($loc['ch_prenom'] ?? '')));
    $adressConducteur   = clean($loc['ch_adresse']);
    $cniConducteur      = '...................';
    $cniDateConducteur  = '...................';
    $contactConducteur  = clean($loc['ch_tel']);
    $emailConducteur    = clean($loc['ch_email']);
    $permisConducteur   = clean($loc['numero_permis']);
    $permisDateConducteur = $loc['date_permis'] ? formatDate($loc['date_permis']) : '...................';
} else {
    $nomConducteur      = $clientNomComplet;
    $adressConducteur   = $clientAdress;
    $cniConducteur      = $clientCni;
    $cniDateConducteur  = $clientDateCni;
    $contactConducteur  = $clientContact;
    $emailConducteur    = $clientEmail;
    $permisConducteur   = '...................';
    $permisDateConducteur = '...................';
}

// Location
$usageVehicule   = ucfirst(str_replace('_', ' ', $loc['type_location'] ?? 'standard'));
$lieuDestination = clean($loc['lieu_destination']);
$lieuRestitution = clean($loc['lieu_destination'] ?: 'Siège de la société');
$nombreJours     = (int)$loc['nombre_jours'];
$prixJour        = number_format((float)$loc['prix_par_jour'], 0, '', ' ');
$montantTotal    = number_format((float)$loc['montant_final'], 0, '', ' ');
$montantCaution  = number_format((float)($loc['caution'] ?? 0), 0, '', ' ');

// Variables entreprise (depuis paramètres)
$loueurNom          = param($contratParams, 'contrat_loueur_nom', getTenantNom());
$loueurRccm         = param($contratParams, 'contrat_loueur_rccm', '...................');
$loueurAdresse      = param($contratParams, 'contrat_loueur_adresse', '...................');
$loueurRepresentant = param($contratParams, 'contrat_loueur_representant', '...................');
$loueurTelCourt     = param($contratParams, 'contrat_loueur_tel', '...................');
$loueurBanque       = param($contratParams, 'contrat_loueur_banque', '...................');
$loueurOm           = param($contratParams, 'contrat_loueur_om', '...................');
$loueurWave         = param($contratParams, 'contrat_loueur_wave', '...................');
$loueurVille        = param($contratParams, 'contrat_loueur_ville', 'Abidjan');
$penaliteRetard     = number_format((float)param($contratParams, 'contrat_penalite_retard', '15000'), 0, '', ' ');
$fraisCarburant     = number_format((float)param($contratParams, 'contrat_frais_carburant', '10000'), 0, '', ' ');

// ── Choisir le template ───────────────────────────────────────────────────
$templateDynamic = BASE_PATH . '/templates/contrats/template_contrat_dynamic.docx';
$templateFallback = BASE_PATH . '/templates/contrats/template_contrat.docx';

$templatePath = file_exists($templateDynamic) ? $templateDynamic : $templateFallback;

if (!file_exists($templatePath)) {
    setFlash(FLASH_ERROR, 'Template contrat introuvable. Contactez le support.');
    redirect(BASE_URL . 'app/locations/detail.php?id=' . $locationId);
}

// ── Générer le document ───────────────────────────────────────────────────
try {
    $tp = new TemplateProcessor($templatePath);

    // Entreprise / Loueur
    $tp->setValue('loueur_nom',          $loueurNom);
    $tp->setValue('loueur_rccm',         $loueurRccm);
    $tp->setValue('loueur_adresse',      $loueurAdresse);
    $tp->setValue('loueur_representant', $loueurRepresentant);
    $tp->setValue('loueur_tel_court',    $loueurTelCourt);
    $tp->setValue('loueur_banque',       $loueurBanque);
    $tp->setValue('loueur_om',           $loueurOm);
    $tp->setValue('loueur_wave',         $loueurWave);
    $tp->setValue('loueur_ville',        $loueurVille);
    $tp->setValue('penalite_retard',     $penaliteRetard);
    $tp->setValue('frais_carburant',     $fraisCarburant);

    // Dates du contrat
    $tp->setValue('dateContrat',  $dateContrat);
    $tp->setValue('dateDebut',    $dateDebut);
    $tp->setValue('dateFin',      $dateFin);
    $tp->setValue('heureDebut',   $heureDebut);
    $tp->setValue('heureFin',     $heureFin);

    // Client
    $tp->setValue('client_nom',      $clientNomComplet);
    $tp->setValue('clientCni',       $clientCni);
    $tp->setValue('clientDateCni',   $clientDateCni);
    $tp->setValue('clientContact',   $clientContact);
    $tp->setValue('clientEmail',     $clientEmail);
    $tp->setValue('clientAdress',    $clientAdress);

    // Conducteur
    $tp->setValue('nomConducteur',       $nomConducteur);
    $tp->setValue('adressConducteur',    $adressConducteur);
    $tp->setValue('cniConducteur',       $cniConducteur);
    $tp->setValue('cniDateConducteur',   $cniDateConducteur);
    $tp->setValue('contactConducteur',   $contactConducteur);
    $tp->setValue('emailConducteur',     $emailConducteur);
    $tp->setValue('permisConducteur',    $permisConducteur);
    $tp->setValue('permisDateConducteur', $permisDateConducteur);

    // Véhicule
    $tp->setValue('residenceNom',    $residenceNom);
    $tp->setValue('immatriculation', $immatriculation);
    $tp->setValue('couleur',         $couleur);
    $tp->setValue('kmDepart',        $kmDepart);
    $tp->setValue('carburantDepart', $carburantDepart);

    // Location
    $tp->setValue('usageVehicule',   $usageVehicule);
    $tp->setValue('lieuDestination', $lieuDestination);
    $tp->setValue('lieuRestitution', $lieuRestitution);
    $tp->setValue('nombreJours',     $nombreJours);
    $tp->setValue('prixJour',        $prixJour);
    $tp->setValue('montantTotal',    $montantTotal);
    $tp->setValue('montantCaution',  $montantCaution);

    // Log
    logActivite($db, 'DOWNLOAD', 'locations', 'Contrat Word téléchargé — location #' . $locationId);

    // Nom du fichier
    $nomFichier = 'Contrat_Location_'
        . preg_replace('/[^a-zA-Z0-9_]/', '_', $loc['client_nom'])
        . '_' . date('Ymd')
        . '.docx';

    // Headers téléchargement
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
    header('Cache-Control: max-age=0');

    $tp->saveAs('php://output');
    exit;

} catch (Exception $e) {
    setFlash(FLASH_ERROR, 'Erreur génération contrat : ' . $e->getMessage());
    redirect(BASE_URL . 'app/locations/detail.php?id=' . $locationId);
}
