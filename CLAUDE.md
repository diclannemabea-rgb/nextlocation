# CLAUDE.md — FlotteCar SaaS v2.1
> Document de référence complet — à passer à la prochaine session Claude pour continuer.
> Mis à jour : 2026-03-10

---

## 1. IDENTITÉ DU PROJET

| Champ | Valeur |
|-------|--------|
| Nom | FlotteCar |
| Type | SaaS PWA multi-tenant — gestion de flotte + location + GPS |
| Dossier | `c:\wamp64_2\www\traccargps\` |
| URL locale | `http://localhost/traccargps/` |
| Stack | PHP 8.1 procédural, MySQL/MariaDB, CSS custom, JS Vanilla, Leaflet.js |
| Devise | FCFA (Franc CFA) |
| Timezone | Africa/Abidjan |

### Comptes de test
| Rôle | Email | Mot de passe |
|------|-------|--------------|
| Super Admin | admin@flottecar.ci | FlotteCar@2026 |
| Admin Demo (tenant enterprise) | admin@demo.ci | Demo@2026 |

### Base de données
- Host: localhost | DB: traccargps | User: root | Password: (vide)
- Connexion: `new Database()` → `getConnection()` → PDO (dans `config/database.php`)
- **TOUTES LES MIGRATIONS SONT APPLIQUÉES** (V1 + V2 — 21 tables)
- UNIQUE KEY ajouté: `paiements_taxi(taximetre_id, date_paiement)` pour ON DUPLICATE KEY UPDATE

---

## 2. VISION MÉTIER — 3 PROFILS D'ENTREPRISE

### Profil A — Location (`type_usage = 'location'`)
Entreprise qui achète des véhicules et les loue à des clients.
- Gestion clients avec pièces d'identité + carte grise
- Contrat PDF auto-généré (dompdf)
- Réservations avec calendrier visuel
- Commerciaux apporteurs d'affaires (commission %)
- Caution, avance, reste à payer, modes de paiement

### Profil B — Flotte Taxi (`type_vehicule = 'taxi'`)
Propriétaire qui confie ses taxis à des taximantre.
- Association taximantre <-> véhicule (table `taximetres`)
- Suivi journalier: payé / non payé / jour off / panne / accident
- Solde dû vs perçu, historique par taximantre
- Alerte quand le taximantre accumule des impayés

### Profil C — Flotte Entreprise (`type_vehicule = 'entreprise'`)
Société qui donne ses véhicules à ses propres chauffeurs.
- Véhicule assigné à chauffeur
- Maintenances programmées à X km avec alertes GPS automatiques
- Historique kilométrage, carburant, incidents
- GPS: positions temps réel, alertes vitesse/zone/horaire

---

## 3. ÉTAT ACTUEL DES FICHIERS

### FAIT ET OPERATIONNEL

```
config/
  database.php, constants.php, functions.php, auth.php  <- COMPLETS

includes/
  header.php     <- sidebar PWA avec menus conditionnels
  footer.php     <- ferme body + charge app.js
  TraccarAPI.php <- classe complete Traccar

models/            <- TOUS LES MODELES CREES
  VehiculeModel.php     <- CRUD + stats + filtres type_vehicule
  LocationModel.php     <- CRUD + calcul montant + terminer + PDF
  TaximetreModel.php    <- CRUD + saisirJour() + getSolde() + getHistorique()
  ReservationModel.php  <- CRUD + checkDisponibilite() + getCalendrier() + convertirEnLocation()
  CommercialModel.php   <- CRUD + getStats(id,tenantId)->{nb_locations,chiffre_affaires,commission_earned}
  MaintenanceModel.php  <- CRUD + getUrgentes() + checkAlerteGps() [NOUVEAU]
  FinanceModel.php      <- getRentabiliteParVehicule() + getRevenusParMois() [NOUVEAU]

migrations/
  migrate.php     <- Reset total (DANGER: DROP ALL)
  migrate_v2.php  <- Delta V2 (deja applique — NE PAS REEXECUTER)

auth/
  login.php, register.php, logout.php, abonnement_expire.php

admin/
  dashboard.php, tenants.php, abonnements.php, statistiques.php,
  utilisateurs.php, logs.php, parametres.php

app/
  dashboard.php
  vehicules/      liste, ajouter, modifier, detail, supprimer
  clients/        liste, ajouter, modifier, supprimer
  chauffeurs/     liste, ajouter, modifier, supprimer
  locations/      liste, nouvelle, detail, terminer, contrat_pdf
  taximetres/
    liste.php     (liste + modal ajout taximantre integre)
    detail.php    (fiche + historique paiements + solde)
    paiements.php (saisie journaliere rapide — NOUVEAU)
  reservations/
    calendrier.php (FullCalendar.js)
  commerciaux/
    index.php     (liste + stats + modals ajout/edition — NOUVEAU)
  gps/            carte, historique, alertes, appareils
  finances/       charges, rentabilite
  maintenances/   index (basique — a ameliorer)
  rapports/       journalier, mensuel
  parametres/     index, utilisateurs

api/
  gps.php               <- positions, trips, moteur stop/start
  vehicules.php         <- liste pour selects JS
  maintenance_check.php <- CRON GPS->Maintenance [NOUVEAU]
```

---

## 4. BASE DE DONNÉES — 21 TABLES (TOUTES CRÉÉES)

```
tenants, users, abonnements, vehicules, clients, chauffeurs,
locations, paiements, charges, maintenances, positions_gps,
alertes, zones, logs_gps_commandes, logs_activites, parametres,
commerciaux, reservations, taximetres, paiements_taxi, evenements_gps
```

### Colonnes clés — vehicules
```
type_vehicule: 'location' | 'taxi' | 'entreprise'
km_initial_compteur, recettes_initiales, depenses_initiales, capital_investi
traccar_device_id, imei               <- GPS
prochaine_vidange_km                  <- seuil alerte maintenance
date_expiration_assurance, date_expiration_vignette <- alertes documentaires
```

### Colonnes clés — locations
```
type_location: standard | avec_chauffeur | longue_duree
lieu_destination, avec_chauffeur, commercial_id, canal_acquisition
statut_paiement: non_paye | avance | solde
statut: en_cours | terminee | annulee
```

### Colonnes clés — paiements_taxi
```
statut_jour: paye | non_paye | jour_off | panne | accident
UNIQUE KEY (taximetre_id, date_paiement) <- ON DUPLICATE KEY UPDATE fonctionne
```

### Colonnes clés — maintenances
```
type: vidange | revision | pneus | freins | autre
statut: planifie | en_cours | termine
km_prevu, km_fait, alerte_envoyee  <- coordonne avec GPS
technicien, facture
```

---

## 5. ARCHITECTURE MODÈLES

Chaque modèle suit ce pattern strict:

```php
require_once BASE_PATH . '/models/NomModel.php';
$model = new NomModel($db);

// BASE_PATH selon profondeur:
// app/sous-dossier/ : define('BASE_PATH', dirname(dirname(__DIR__)));
// app/ ou api/     : define('BASE_PATH', dirname(__DIR__));
```

**Méthodes par modèle:**

| Modèle | Méthodes clés |
|--------|--------------|
| VehiculeModel | getById, getAll(filtres), getTaxiVehicules, getMaintenanceUrgente, getStats, create, update, delete, updateStatut, updateKm |
| LocationModel | getById, getAll, getEnCours, isVehiculeDisponible, calcMontant, create, terminer, ajouterPaiement |
| TaximetreModel | getById, getAll, getByVehicule, getSolde, getHistorique, getJoursSaisis, create, update, suspend, saisirJour |
| ReservationModel | getById, getAll, checkDisponibilite, getCalendrier, create, update, annuler, confirmer, convertirEnLocation |
| CommercialModel | getById, getAll, getActifs, getStats(id,tenantId), create, update, delete |
| MaintenanceModel | getById, getAll, getUrgentes, getAVenir, getByVehicule, getStats, create, update, terminer, planifierProchaineVidange, delete, checkAlerteGps |
| FinanceModel | getCharges, createCharge, deleteCharge, getRentabiliteParVehicule, getResumeMensuel, getRevenusParMois, getRevenusTaxi, getTotalCharges |

---

## 6. RÈGLES DE CODE ABSOLUES

```php
// Pattern standard page app/sous-dossier/
define('BASE_PATH', dirname(dirname(__DIR__)));
session_start();
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/config/functions.php';
require_once BASE_PATH . '/config/auth.php';
requireTenantAuth();
$db = (new Database())->getConnection();
$tenantId = getTenantId();
```

**Règles de sécurité:**
1. `WHERE tenant_id = $tenantId` sur **toutes** les requêtes SQL en /app/
2. PDO prepared statements uniquement — jamais de concaténation SQL
3. `csrfField()` sur tous les formulaires POST + `requireCSRF()` en début de handler
4. `sanitize()` sur toutes les sorties HTML
5. Vérifier appartenance de chaque ressource au tenant avant action

---

## 7. FONCTIONS DISPONIBLES (config/functions.php)

```php
isLoggedIn(), isSuperAdmin(), isTenantAdmin(), getUserId(), getTenantId()
getUserName(), getTenantNom(), getTenantPlan(), getTenantTypeUsage()
hasLocationModule(), hasGpsModule(), isPlanPro(), isPlanEnterprise()
setFlash(FLASH_SUCCESS|ERROR|WARNING|INFO, $msg) / renderFlashes()
sanitize($val) / redirect($url) / jsonResponse($data, $code)
formatMoney(float) / formatDate(?string) / formatDatetime(?string)
calculateDays(debut, fin) / cleanNumber($val)
csrfField() / requireCSRF() / generateCSRF()
uploadFile($_FILES[x], UPLOAD_LOGOS, ALLOWED_IMAGES)
badgeVehicule($statut) / badgeLocation($statut) / badgePaiement($statut) / badgePlan($plan)
renderPagination($total, $page, $perPage, $baseUrl)
canAddVehicule($db, $tenantId, $plan) / getVehiculeLimit($plan)
logActivite($db, $action, $module, $description)
```

---

## 8. TRACCAR GPS

### Installation Windows
```
1. Telecharger traccar-windows-64-latest.exe sur traccar.org/download
2. Installer -> service Windows demarre automatiquement
3. Interface: http://localhost:8082 | Login: admin/admin
4. Configurer C:\Program Files\Traccar\conf\traccar.xml (ports protocoles)
5. Test: curl http://localhost:8082/api/server
```

### Protocoles GPS supportés
| Protocole | Port | Boîtiers |
|-----------|------|---------|
| GT06 | 5023 | Concox, JMT |
| Teltonika | 5027 | FMB920, FMB130 |
| Coban | 5027 | TK103, GPS103 |
| Sinotrack | 5013 | ST-901, ST-902 |
| Queclink | 5093 | GV55, GV300 |
| H02 | 5013 | Sinotrack, Coban |
| NMEA | 10050 | Generique |

### TraccarAPI — méthodes
```php
$t = new TraccarAPI();
$t->isAvailable() / getDevices() / createDevice($name, $imei)
$t->getPosition($deviceId)  // retourne ['lat','lon','speed','attributes'=>['totalDistance'=>metres]]
$t->getPositions($ids[]) / getTrips($id, $from, $to) / getEvents($id, $from, $to)
$t->stopEngine($id) / resumeEngine($id) / reverseGeocode($lat, $lon)
```

### Cron maintenance GPS
```bash
# Toutes les heures (Windows Task Scheduler):
php c:/wamp64_2/www/traccargps/api/maintenance_check.php

# Via HTTP:
GET http://localhost/traccargps/api/maintenance_check.php?key=flottecar_maint_2026
```

---

## 9. DESIGN CSS — CLASSES DISPONIBLES

```css
--primary: #1a56db | --sidebar-bg: #1e293b | --bg: #ffffff
--border: #eef0f4 | --text: #1e293b | --text-muted: #64748b
--radius: 8px | --topbar-height: 52px
```

```
Layout:  .app-wrapper .sidebar .main-wrapper .main-content .topbar
Cards:   .card .card-header .card-body .card-title
Stats:   .stats-grid  .stat-card.primary|success|warning|danger|info
Tables:  .table .table-responsive
Forms:   .form-group .form-label .form-control .form-row.cols-2|3 .form-hint .form-actions
Buttons: .btn .btn-primary|secondary|success|danger|warning|ghost|outline-primary|sm|lg
Badges:  .badge .bg-primary|success|warning|danger|info|secondary
Modals:  .modal-overlay .modal-card .modal-header .modal-close
Util:    .grid-2col .filter-form .page-header .page-subtitle
JS:      openModal('id') / closeModal('id')
```

---

## 10. CE QUI RESTE À FAIRE

### PRIORITÉ 1 — Pages à compléter

**A. reservations/nouvelle.php**
- Formulaire similaire locations/nouvelle.php + date+heure precise
- Verification dispo AJAX via `api/vehicules.php`
- Conversion en location: `ReservationModel::convertirEnLocation()`

**B. app/maintenances/index.php — ameliorer**
- Utiliser `MaintenanceModel::getUrgentes()` pour alertes en rouge
- Bouton "Terminer" -> `MaintenanceModel::terminer()` + auto-planifier prochaine vidange
- Afficher km Traccar temps reel + colonne technicien/facture

**C. locations/nouvelle.php — completer**
- Ajouter: lieu_destination, toggle avec_chauffeur, select commercial_id
- Calcul montant JS temps reel (prix*jours-remise)
- Modal creation client a la volee

**D. vehicules/ajouter.php — verifier**
- Section "Reset financier initial": km_initial_compteur, capital_investi, recettes_initiales, depenses_initiales
- Selecteur type_vehicule (location/taxi/entreprise)

**E. clients/ajouter.php + modifier.php — verifier**
- type_piece select, photo_piece upload, numero_carte_grise, photo_carte_grise

**F. chauffeurs/ajouter.php + modifier.php — verifier**
- vehicule_id assigne, type_chauffeur

### PRIORITÉ 2 — GPS avancé

**G. app/gps/alertes.php — ameliorer**
- Lire `evenements_gps` (table) + alertes Traccar
- Bouton "Marquer lu" -> `UPDATE evenements_gps SET lu=1`
- Filtres: vehicule, type (maintenance/vitesse/zone), lu/non lu

**H. api/gps.php — ajouter**
- `GET ?action=evenements` -> evenements_gps non lus pour badge sidebar

**I. Alertes assurance/vignette**
- Check au login: date_expiration_assurance/vignette dans 30 jours -> flash warning

### PRIORITÉ 3 — Rapports + Export

**J. finances/rentabilite.php — ameliorer**
- Utiliser `FinanceModel::getRentabiliteParVehicule()` -> tableau ROI par vehicule
- Graphique Chart.js avec `getRevenusParMois()` 12 mois

**K. Rapport taximantre mensuel**
- Par taximantre: jours travailles/payes/off, total du/recu/solde
- Export PDF (dompdf)

**L. Dashboard — ameliorer**
- Badge rouge "alertes" si `MaintenanceModel::getUrgentes()` > 0
- Section "Vehicules a verifier" (assurance/vignette expiree bientot)

---

## 11. CONSTANTES IMPORTANTES

```php
BASE_URL = 'http://localhost/traccargps/'
TRACCAR_URL = 'http://localhost:8082/api'
TRACCAR_USER = 'admin' / TRACCAR_PASS = 'admin'
PLAN_STARTER / PLAN_PRO / PLAN_ENTERPRISE
LIMIT_STARTER=3 / LIMIT_PRO=10 / LIMIT_ENTERPRISE=9999
VEH_DISPONIBLE / VEH_LOUE / VEH_MAINTENANCE / VEH_INDISPONIBLE
LOC_EN_COURS / LOC_TERMINEE / LOC_ANNULEE
PAIE_NON_PAYE / PAIE_AVANCE / PAIE_SOLDE
UPLOAD_LOGOS / UPLOAD_CONTRATS / UPLOAD_DOCUMENTS
ALLOWED_IMAGES = ['jpg','jpeg','png','gif','webp']
DATE_FORMAT = 'd/m/Y' / DATETIME_FORMAT = 'd/m/Y H:i'
ITEMS_PER_PAGE = 20
CRON_KEY = 'flottecar_maint_2026'
```

---

## 12. COMMANDES UTILES

```bash
# Verifier DB
php -r "\$p=new PDO('mysql:host=localhost;dbname=traccargps','root',''); echo implode(', ',\$p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));"

# Syntax check PHP
php -l app/taximetres/paiements.php

# Cron maintenance manuellement
php c:/wamp64_2/www/traccargps/api/maintenance_check.php

# Test Traccar
curl http://localhost:8082/api/server
```

---

## 13. PATTERNS DE CODE RÉUTILISABLES

### Liste avec pagination
```php
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = ITEMS_PER_PAGE;
$offset  = ($page - 1) * $perPage;
$q       = trim($_GET['q'] ?? '');
$sql     = 'SELECT * FROM table WHERE tenant_id = ?';
$params  = [$tenantId];
if ($q) { $sql .= ' AND nom LIKE ?'; $params[] = "%$q%"; }

$countStmt = $db->prepare("SELECT COUNT(*) FROM table WHERE tenant_id = ?" . ($q ? " AND nom LIKE ?" : ''));
$countStmt->execute($q ? [$tenantId, "%$q%"] : [$tenantId]);
$total = (int) $countStmt->fetchColumn();

$dataStmt = $db->prepare($sql . ' ORDER BY nom ASC LIMIT ? OFFSET ?');
$dataStmt->execute(array_merge($params, [$perPage, $offset]));
$rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

echo renderPagination($total, $page, $perPage, BASE_URL . 'app/module/liste.php');
```

### Calcul location (JS)
```javascript
function calculer() {
    const jours  = Math.max(1, Math.round((new Date(fin) - new Date(debut)) / 86400000));
    const prix   = parseFloat(document.getElementById('prix_par_jour').value) || 0;
    const remise = parseFloat(document.getElementById('remise').value) || 0;
    const avance = parseFloat(document.getElementById('avance').value) || 0;
    const final  = Math.max(0, jours * prix - remise);
    const reste  = Math.max(0, final - avance);
    // afficher dans les spans
}
```

### Modal inline
```html
<div id="modal-X" class="modal-overlay" style="display:none">
  <div class="modal-card" style="max-width:500px">
    <div class="modal-header">
      <h3>Titre</h3>
      <button class="modal-close" onclick="closeModal('modal-X')">&times;</button>
    </div>
    <form method="POST" style="padding:20px">
      <?= csrfField() ?>
      <div class="form-actions">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-X')">Annuler</button>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
      </div>
    </form>
  </div>
</div>
```
