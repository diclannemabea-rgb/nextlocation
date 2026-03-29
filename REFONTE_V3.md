# REFONTE FlotteCar v3 — Plan complet

> Objectif : Transformer FlotteCar d'un outil "code AI" en produit SaaS pro avec identite visuelle forte.
> Date : 2026-03-29

---

## 1. IDENTITE VISUELLE

### Couleurs
| Role | Couleur | Usage |
|------|---------|-------|
| Principale | `#0f172a` (Slate 900) | Textes, titres, sidebar |
| Accent | `#0d9488` (Teal 600) | Boutons primaires, liens, elements actifs |
| Accent hover | `#0f766e` (Teal 700) | Hover boutons |
| Accent light | `#ccfbf1` (Teal 50) | Backgrounds badges, selections |
| Action/CTA | `#f97316` (Orange 500) | Boutons secondaires, alertes urgentes, prix |
| Action hover | `#ea580c` (Orange 600) | Hover CTA |
| Background | `#ffffff` | Fond principal |
| Surface | `#f8fafc` (Slate 50) | Fond cards, sidebar hover |
| Border | `#e2e8f0` (Slate 200) | Bordures, separateurs |
| Text | `#0f172a` (Slate 900) | Texte principal |
| Text muted | `#64748b` (Slate 500) | Texte secondaire |
| Success | `#059669` | Statut OK |
| Danger | `#dc2626` | Erreurs, suppressions |
| Warning | `#d97706` | Alertes |

### Typographie
- Font : **Inter** (deja en place, c'est bien)
- Titres pages : 20px, font-weight 700, couleur `#0f172a`
- Sous-titres : 13px, font-weight 400, couleur `#64748b`
- Texte courant : 14px, font-weight 400
- Tableau : 13px
- Labels formulaires : 13px, font-weight 500
- Boutons : 13px, font-weight 600, MAJUSCULE NON (rester en normal)

### Regles de design
- **PAS de gradients** sauf sidebar (subtil)
- **PAS d'icones partout** — icones UNIQUEMENT sur : navigation sidebar, boutons d'action principaux, badges statut
- **PAS d'emoji** dans l'interface
- **Ombres** : `0 1px 3px rgba(0,0,0,0.08)` sur cards uniquement
- **Border-radius** : 8px cards, 6px boutons/inputs, 4px badges
- **Espacement** : 24px entre sections, 16px entre elements d'une section
- **Animations** : transitions 150ms ease uniquement (hover, focus) — PAS de bounce, pulse, etc.

---

## 2. COMPOSANTS A REFONDRE

### 2.1 Sidebar (`includes/header.php`)
**Actuel** : Dark sidebar classique avec trop d'icones
**Nouveau** :
- Background : `#0f172a`
- Logo : texte "FlotteCar" en blanc, font-weight 800, pas d'icone voiture
- Navigation : texte uniquement + indicateur actif (barre verticale teal 3px a gauche)
- Groupes separes par label gris (VEHICULES, LOCATION, GPS, FINANCES)
- Hover : background `rgba(255,255,255,0.06)`
- Badge compteur : petit cercle teal, pas de background rouge criard
- Mobile : slide-in avec overlay sombre
- Bouton plan en bas : badge simple teal, pas de couronne/emoji

### 2.2 Topbar
**Actuel** : Barre avec user dropdown
**Nouveau** :
- Hauteur : 56px
- Gauche : breadcrumb simple (Accueil / Vehicules / Detail)
- Droite : cloche notifications (point teal si non lues) + avatar initiales (cercle teal)
- Fond blanc, border-bottom 1px `#e2e8f0`

### 2.3 Boutons
**Primaire** : Background teal `#0d9488`, texte blanc, hover `#0f766e`
**Secondaire** : Background blanc, border `#e2e8f0`, texte `#0f172a`, hover background `#f8fafc`
**Action/CTA** : Background orange `#f97316`, texte blanc (utilise pour: payer, reserver, souscrire)
**Danger** : Background `#dc2626`, texte blanc
**Ghost** : Pas de border, texte teal, hover underline
**Taille** : padding 8px 16px, font 13px — PAS de tailles xl/xxl

### 2.4 Cards
- Background blanc
- Border : 1px `#e2e8f0`
- Shadow : `0 1px 3px rgba(0,0,0,0.08)`
- Radius : 8px
- Header : pas de background gris, juste titre 15px bold + border-bottom
- PAS de card-header avec background colore

### 2.5 Inputs / Formulaires
- Height : 40px
- Border : 1px `#e2e8f0`
- Focus : border `#0d9488` + shadow `0 0 0 3px rgba(13,148,136,0.1)`
- Radius : 6px
- Label : au-dessus, 13px, font-weight 500, couleur `#334155`
- Placeholder : couleur `#94a3b8`
- PAS d'icones dans les inputs (sauf recherche)
- Select : fleche custom SVG grise

### 2.6 Tables
- Header : background `#f8fafc`, texte `#64748b`, 11px uppercase
- Lignes : border-bottom 1px `#f1f5f9`, hover background `#f8fafc`
- Actions : boutons ghost petits, pas de colonne "Actions" surchargee
- Mobile : transformer en cards empilees

### 2.7 Stats/KPI Cards
- PAS de stat-card avec icone ronde coloree (trop AI)
- Juste : chiffre gros (24px bold), label petit dessous (12px muted)
- Couleur de bordure gauche 3px pour categoriser
- Grid : 4 colonnes desktop, 2 colonnes mobile
- Cliquable avec hover subtle

### 2.8 Badges
- Petits : padding 2px 8px, font 11px, font-weight 600
- Couleurs : fond pastel + texte fonce (ex: bg `#ccfbf1` + texte `#0f766e` pour actif)
- PAS de grosses badges rondes

### 2.9 Modals
- Overlay : `rgba(0,0,0,0.4)` (pas trop sombre)
- Card : radius 12px, max-width 480px, shadow forte
- Header : titre 16px bold, bouton X discret
- Animation : scale 0.95 -> 1 + opacity, 200ms

---

## 3. PAGES A REFONDRE (ordre de priorite)

### Phase 1 — Pages vitrine (login/register/pricing)

#### 3.1 `auth/login.php`
- Design 2 colonnes : gauche = branding FlotteCar fond `#0f172a` avec phrase d'accroche + feature bullets / droite = formulaire blanc
- Formulaire : email + mot de passe, pas d'icones dans les champs
- Bouton "Se connecter" teal pleine largeur
- Lien "Creer un compte" en dessous
- Mobile : branding masque, formulaire seul

#### 3.2 `auth/register.php`
- Wizard 3 etapes (garder le concept mais redesigner)
- Progress : 3 cercles relies par ligne, cercle actif = teal
- Etape 3 (choix plan) : 2 cartes cote a cote :
  - **Mensuel** : 20 000 FCFA/mois
  - **Annuel** : 150 000 FCFA/an (economisez 37%)
- Chaque plan montre : prix, nb vehicules, features incluses
- Bouton CTA orange "Commencer"

#### 3.3 Page pricing (NOUVELLE — `auth/tarifs.php`)
- 2 plans : Mensuel 20 000 / Annuel 150 000
- Comparaison features en liste
- CTA orange "Demarrer maintenant"
- FAQ section en bas

### Phase 2 — Core app

#### 3.4 `app/dashboard.php`
- Header : "Bonjour, [Prenom]" + date du jour
- KPIs : 4 cartes en ligne (chiffre + label, bordure gauche coloree)
- Section principale selon type_usage :
  - Location : locations en cours + calendrier mini
  - Taxi : VTC non payes + solde global
  - GPS : mini carte Leaflet + alertes recentes
- Tableaux : max 5 lignes + lien "Voir tout"
- PAS de gradient banner en haut

#### 3.5 `app/vehicules/` (liste, ajouter, modifier, detail)
- Liste : tableau propre avec badge statut, immat en bold
- Detail : fiche vehicule en 2 colonnes (infos + stats financieres)
- Ajouter : formulaire en sections (Identite / Financier / GPS)

#### 3.6 `app/taximetres/` (liste, detail, paiements)
- Paiements : grille calendrier du mois, chaque jour = case cliquable
- Couleurs : vert=paye, rouge=non paye, gris=jour off, jaune=panne
- Modal paiement rapide au clic

#### 3.7 `app/locations/` (liste, nouvelle, detail, terminer)
- Nouvelle location : formulaire en etapes (vehicule > client > dates > prix)
- Calcul montant en temps reel
- Detail : timeline des paiements

#### 3.8 `app/finances/comptabilite.php`
- Corriger les erreurs `htmlspecialchars null` lignes 378/387
- Journal : mobile = cards au lieu de table
- Graphique : garder mais simplifier les couleurs

#### 3.9 `app/gps/` (carte, appareils, alertes, historique, regles)
- Carte : pleine largeur, sidebar filtres collapsible
- Appareils : mobile cards (deja fait, affiner)
- Alertes : liste simple sans trop de couleurs

#### 3.10 `app/parametres/index.php`
- Tabs : scrollable horizontalement sur mobile
- Formulaires : single column sur mobile
- Zone danger : garder mais design plus sobre

### Phase 3 — Admin / Rapports

#### 3.11 `admin/` (dashboard, tenants, abonnements)
- Admin dashboard : stats globales + liste tenants recents
- Tenants : tableau + actions inline
- Abonnements : timeline par tenant

#### 3.12 `app/rapports/` (journalier, mensuel)
- Rapports propres avec tableaux exportables
- Bouton export PDF/Excel

---

## 4. FICHIER CSS — MODIFICATIONS

### 4.1 Variables a changer dans `assets/css/app.css`
```css
:root {
    --primary: #0d9488;        /* Teal — etait #1a56db */
    --primary-dark: #0f766e;   /* etait #1341a8 */
    --primary-light: #14b8a6;  /* etait #3b82f6 */
    --primary-bg: #ccfbf1;     /* NOUVEAU */
    --secondary: #f97316;      /* Orange — inchange */
    --secondary-dark: #ea580c;
    --dark: #0f172a;
    --sidebar-bg: #0f172a;     /* Plus sombre — etait #1e293b */
    --text: #0f172a;           /* etait #1e293b */
    --text-muted: #64748b;
    --bg: #ffffff;
    --card-bg: #ffffff;
    --border: #e2e8f0;         /* Plus visible — etait #eef0f4 */
    --success: #059669;
    --danger: #dc2626;
    --warning: #d97706;
    --info: #0d9488;           /* Utiliser teal — etait #2563eb */
    --radius: 8px;
    --radius-sm: 6px;
    --radius-xs: 4px;
    --shadow-card: 0 1px 3px rgba(0,0,0,0.08);
    --shadow-hover: 0 4px 12px rgba(0,0,0,0.1);
    --transition: 150ms ease;
}
```

### 4.2 Supprimmer
- Tous les `.stat-card` avec gros background colore et icones rondes
- Les `.badge` avec background plein sature
- Les animations `bounce`, `pulse`, `slideIn` excessives
- Les gradients sur les headers de page

### 4.3 Ajouter
- `.kpi` : design minimal (chiffre + label + bordure gauche)
- `.table-mobile-cards` : auto-switch table->cards sous 640px
- `.sidebar-item.active` : barre gauche teal 3px + font-weight 600

---

## 5. CORRECTIONS TECHNIQUES

### 5.1 Bugs PHP a corriger
| Fichier | Ligne | Bug | Fix |
|---------|-------|-----|-----|
| `app/finances/comptabilite.php` | 378 | `htmlspecialchars(null)` | `htmlspecialchars($row['libelle'] ?? '')` |
| `app/finances/comptabilite.php` | 387 | `htmlspecialchars(null)` | `htmlspecialchars($row['tiers'] ?? '')` |

### 5.2 Models — Verification
Tous les models sont presents et fonctionnels :
- `VehiculeModel.php` — OK
- `LocationModel.php` — OK
- `TaximetreModel.php` — OK
- `ReservationModel.php` — OK
- `CommercialModel.php` — OK
- `MaintenanceModel.php` — OK
- `FinanceModel.php` — OK
- `ComptabiliteModel.php` — OK
- `WaveApiModel.php` — OK

### 5.3 Traccar API (`includes/TraccarAPI.php`)
**Status** : Complet, 28 methodes.
**Pour la production** :
1. Changer `TRACCAR_URL` dans `config/constants.php` vers l'IP serveur production
2. Changer `TRACCAR_USER` / `TRACCAR_PASS` vers le compte prod
3. S'assurer que les ports GPS (5023, 5027, 5013, etc.) sont ouverts sur le pare-feu serveur
4. Configurer le cron `api/maintenance_check.php` toutes les heures
5. **Conseil** : Mettre les credentials dans un fichier `.env` hors du repo (pas dans constants.php)

### 5.4 Wave API (`models/WaveApiModel.php`)
**Status** : Complet. Methodes disponibles :
- `createCheckoutLink()` — generer un lien de paiement Wave
- `getTransaction()` — verifier un paiement
- `verifyWebhookSignature()` — securiser le webhook
- `imputerPaiement()` — imputer auto sur jours impayes (du plus ancien au plus recent)
- `findTaximetreByPhone()` — trouver un taximetrepar numero de telephone

**Pour la production** :
1. Creer un compte Wave Business sur https://business.wave.com
2. Dans Settings > API : copier la Secret Key → coller dans Parametres > Wave Business
3. Dans Settings > Webhooks : ajouter l'URL `https://votredomaine.com/api/wave_webhook.php`
4. Copier le Webhook Secret → coller dans Parametres
5. Entrer le numero marchand Wave
6. Activer le toggle dans FlotteCar
7. **Test** : envoyer un petit montant depuis un numero connu, verifier les logs

### 5.5 Push Notifications
- Verifier que le service worker (`sw.js`) est servi via HTTPS en prod
- VAPID keys deja configurees dans `config/push.php`
- Tester avec Android Chrome + iOS Safari 16.4+

---

## 6. TARIFICATION — MISE A JOUR

### Anciens prix (a remplacer)
```
Starter: 5 000 FCFA/mois — 3 vehicules
Pro: 15 000 FCFA/mois — 10 vehicules
Enterprise: 35 000 FCFA/mois — illimite
```

### Nouveaux prix
```
Mensuel: 20 000 FCFA/mois — vehicules illimites, toutes fonctionnalites
Annuel: 150 000 FCFA/an — vehicules illimites, toutes fonctionnalites (economie 37%)
```

### Fichiers a modifier
1. `config/constants.php` :
   - `PRIX_MENSUEL = 20000`
   - `PRIX_ANNUEL = 150000`
   - Supprimer les 3 plans (starter/pro/enterprise) → plan unique
   - Supprimer `LIMIT_STARTER`, `LIMIT_PRO`
   - `LIMIT_VEHICULES = 9999` (illimite)
2. `config/functions.php` :
   - Supprimer `canAddVehicule()`, `getVehiculeLimit()`, `badgePlan()`
   - Ou adapter pour plan unique
3. `auth/register.php` : etape plan → choix mensuel/annuel
4. `app/parametres/index.php` : tab abonnement → afficher plan unique
5. `admin/abonnements.php` : gerer mensuel/annuel
6. `includes/header.php` : supprimer badge plan starter/pro/enterprise
7. Toute reference a `getTenantPlan()` === 'starter' etc.

---

## 7. FONCTIONNALITES CONCURRENTIELLES (inspirees du marche)

### 7.1 VTC Control (Google Play)
- Planning automatique chauffeurs
- Gestion entretiens vehicules
- Messagerie integree chauffeurs
- Gestion recettes/depenses par vehicule

**A ajouter dans FlotteCar** :
- [ ] Chat simple chauffeur <-> gestionnaire (notifications push)
- [ ] Planning hebdomadaire chauffeur (vue semaine)
- [ ] Fiche rentabilite par vehicule (existe deja via FinanceModel, ameliorer la vue)

### 7.2 Fleeti / IT Mobile / GTS Afrique
- Geolocalisation temps reel
- Rapports automatiques par email
- Alertes carburant
- Gestion zones geographiques

**A ajouter dans FlotteCar** :
- [ ] Rapport hebdomadaire auto par email (cron)
- [ ] Alerte carburant si consommation anormale
- [ ] Zones geofencing avec alertes entree/sortie (table `zones` existe deja)

### 7.3 Navixy / Webfleet (references internationales)
- Dashboard role-based (chauffeur vs gestionnaire vs direction)
- Score conducteur (conduite, vitesse, freinage)
- Maintenance predictive basee sur km

**A ajouter dans FlotteCar** :
- [ ] Vue simplifiee chauffeur (mobile only) : ma position, mes courses, mes paiements
- [ ] Score conduite base sur evenements Traccar (vitesse, freinage brusque)
- [ ] Maintenance predictive : alerte auto quand km approche seuil vidange (existe deja via MaintenanceModel::checkAlerteGps)

---

## 8. RESPONSIVE / MOBILE

### Principes
- Mobile first : concevoir d'abord pour 360px puis elargir
- Breakpoints : 640px (mobile→tablet), 1024px (tablet→desktop)
- Sidebar : off-canvas sur mobile, toujours visible desktop
- Tables : deviennent des cards empilees sous 640px
- Formulaires : single column sous 640px
- Modals : plein ecran sur mobile (100vh)
- Boutons : pleine largeur sur mobile

### Pattern table→cards (reutilisable)
```html
<!-- Desktop: table classique visible -->
<div class="desktop-only">
    <table class="table">...</table>
</div>
<!-- Mobile: cards empilees -->
<div class="mobile-only">
    <div class="mobile-card">...</div>
</div>
```

---

## 9. ORDRE D'EXECUTION

### Sprint 1 — Fondations (CSS + composants)
1. [ ] Modifier `assets/css/app.css` : nouvelles variables, nouveaux composants
2. [ ] Modifier `includes/header.php` : nouvelle sidebar
3. [ ] Corriger `comptabilite.php` : bugs htmlspecialchars
4. [ ] Modifier `config/constants.php` : nouveaux prix

### Sprint 2 — Auth + Dashboard
5. [ ] Refondre `auth/login.php`
6. [ ] Refondre `auth/register.php` (avec choix mensuel/annuel)
7. [ ] Creer `auth/tarifs.php`
8. [ ] Refondre `app/dashboard.php`

### Sprint 3 — Pages metier
9. [ ] Refondre `app/vehicules/` (liste, detail, ajouter)
10. [ ] Refondre `app/taximetres/` (liste, detail, paiements)
11. [ ] Refondre `app/locations/` (liste, nouvelle, detail)
12. [ ] Refondre `app/clients/` et `app/chauffeurs/`

### Sprint 4 — GPS + Finances
13. [ ] Refondre `app/gps/` (carte, appareils, alertes)
14. [ ] Refondre `app/finances/comptabilite.php`
15. [ ] Refondre `app/finances/rentabilite.php`

### Sprint 5 — Admin + Parametres
16. [ ] Refondre `app/parametres/index.php`
17. [ ] Refondre `admin/` (dashboard, tenants, abonnements)
18. [ ] Adapter toute la logique plan unique (supprimer starter/pro/enterprise)

### Sprint 6 — Nouvelles fonctionnalites
19. [ ] Vue chauffeur mobile simplifiee
20. [ ] Rapport automatique hebdomadaire (cron email)
21. [ ] Geofencing alertes

---

## 10. CHECKLIST MISE EN PRODUCTION

- [ ] HTTPS obligatoire (certificat SSL)
- [ ] `.env` pour credentials (DB, Traccar, Wave, VAPID)
- [ ] `config/constants.php` : BASE_URL → domaine production
- [ ] Traccar installe sur serveur + ports ouverts
- [ ] Wave webhook URL configuree
- [ ] Cron maintenance GPS toutes les heures
- [ ] Cron rapport hebdo (si implemente)
- [ ] Service Worker + manifest.json → PWA installable
- [ ] Tester push notifications sur Android + iOS
- [ ] Optimiser images (logos uploads)
- [ ] Error logging PHP → fichier log (pas affichage)
- [ ] `display_errors = Off` en production
