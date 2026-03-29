/**
 * FlotteCar - Service Worker PWA
 * Gestion du cache offline avec stratégies Network First / Cache First
 */

const CACHE_VERSION = 'flottecar-v2.0.1';
const STATIC_CACHE  = CACHE_VERSION + '-static';
const DYNAMIC_CACHE = CACHE_VERSION + '-dynamic';

// Dériver le chemin de base dynamiquement depuis l'emplacement du SW
const BASE = self.location.pathname.replace(/sw\.js$/, '');

// Assets statiques à mettre en cache immédiatement
const STATIC_ASSETS = [
  BASE,
  BASE + 'index.php',
  BASE + 'assets/css/app.css',
  BASE + 'assets/js/app.js',
  BASE + 'assets/img/icon-192.png',
  BASE + 'assets/img/icon-512.png',
  'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css',
  'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
  'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'
];

// Page offline fallback
const OFFLINE_PAGE = BASE + 'offline.html';

// -------------------------------------------------------
// INSTALLATION - Mise en cache des assets statiques
// -------------------------------------------------------
self.addEventListener('install', (event) => {
  console.log('[SW] Installation du Service Worker FlotteCar');
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) => {
      console.log('[SW] Mise en cache des assets statiques');
      // On ignore les erreurs individuelles pour ne pas bloquer l'install
      return Promise.allSettled(
        STATIC_ASSETS.map(url => cache.add(url).catch(err => {
          console.warn('[SW] Échec cache:', url, err);
        }))
      );
    }).then(() => {
      // Créer la page offline inline
      return caches.open(STATIC_CACHE).then(cache => {
        const offlineHtml = `<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FlotteCar - Hors ligne</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Inter', system-ui, sans-serif;
      background: #0f172a;
      color: #f1f5f9;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 20px;
    }
    .offline-card {
      background: #1e293b;
      border-radius: 16px;
      padding: 48px 40px;
      max-width: 420px;
      width: 100%;
      border: 1px solid #334155;
    }
    .offline-icon {
      width: 80px;
      height: 80px;
      background: linear-gradient(135deg, #1a56db, #3b82f6);
      border-radius: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 24px;
      font-size: 36px;
    }
    h1 { font-size: 24px; font-weight: 700; margin-bottom: 12px; }
    p { color: #94a3b8; line-height: 1.6; margin-bottom: 24px; }
    .btn {
      display: inline-block;
      background: #1a56db;
      color: white;
      padding: 12px 24px;
      border-radius: 8px;
      text-decoration: none;
      font-weight: 600;
      cursor: pointer;
      border: none;
      font-size: 14px;
    }
    .btn:hover { background: #1341a8; }
  </style>
</head>
<body>
  <div class="offline-card">
    <div class="offline-icon">&#128268;</div>
    <h1>Vous êtes hors ligne</h1>
    <p>FlotteCar nécessite une connexion internet pour fonctionner. Vérifiez votre connexion et réessayez.</p>
    <button class="btn" onclick="window.location.reload()">Réessayer</button>
  </div>
</body>
</html>`;
        const response = new Response(offlineHtml, {
          headers: { 'Content-Type': 'text/html; charset=utf-8' }
        });
        return cache.put(OFFLINE_PAGE, response);
      });
    }).then(() => self.skipWaiting())
  );
});

// -------------------------------------------------------
// ACTIVATION - Nettoyage des anciens caches
// -------------------------------------------------------
self.addEventListener('activate', (event) => {
  console.log('[SW] Activation du Service Worker FlotteCar');
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames
          .filter(name => name.startsWith('flottecar-') && name !== STATIC_CACHE && name !== DYNAMIC_CACHE)
          .map(name => {
            console.log('[SW] Suppression ancien cache:', name);
            return caches.delete(name);
          })
      );
    }).then(() => self.clients.claim())
  );
});

// -------------------------------------------------------
// FETCH - Interception des requêtes
// -------------------------------------------------------
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Ignorer les requêtes non-GET
  if (request.method !== 'GET') return;

  // Ignorer les extensions navigateur et les requêtes non-http
  if (!request.url.startsWith('http')) return;

  // Stratégie selon le type de ressource
  if (isStaticAsset(url)) {
    // Cache First pour les assets statiques (CSS, JS, fonts, images)
    event.respondWith(cacheFirst(request));
  } else if (isPhpPage(url)) {
    // Network First pour les pages PHP
    event.respondWith(networkFirst(request));
  } else {
    // Network First par défaut
    event.respondWith(networkFirst(request));
  }
});

// -------------------------------------------------------
// Détection du type de ressource
// -------------------------------------------------------
function isStaticAsset(url) {
  const staticExtensions = ['.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp', '.ico', '.woff', '.woff2', '.ttf'];
  const staticHosts = ['fonts.googleapis.com', 'fonts.gstatic.com', 'cdnjs.cloudflare.com', 'unpkg.com'];

  if (staticHosts.some(host => url.hostname.includes(host))) return true;
  return staticExtensions.some(ext => url.pathname.endsWith(ext));
}

function isPhpPage(url) {
  return url.hostname === 'localhost' && (
    url.pathname.endsWith('.php') || url.pathname.endsWith('/')
  );
}

// -------------------------------------------------------
// Stratégie Cache First
// -------------------------------------------------------
async function cacheFirst(request) {
  try {
    const cached = await caches.match(request);
    if (cached) {
      // Rafraîchir le cache en arrière-plan
      updateCache(request);
      return cached;
    }
    const response = await fetch(request);
    if (response.ok) {
      const cache = await caches.open(STATIC_CACHE);
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    const cached = await caches.match(request);
    if (cached) return cached;
    console.warn('[SW] Cache First: ressource non disponible', request.url);
    throw error;
  }
}

// -------------------------------------------------------
// Stratégie Network First
// -------------------------------------------------------
async function networkFirst(request) {
  try {
    const response = await fetch(request, { signal: AbortSignal.timeout(8000) });
    if (response.ok || response.status === 304) {
      // Mettre en cache les pages PHP réussies
      if (isPhpPage(new URL(request.url))) {
        const cache = await caches.open(DYNAMIC_CACHE);
        cache.put(request, response.clone());
      }
    }
    return response;
  } catch (error) {
    // Réseau indisponible → chercher dans le cache
    const cached = await caches.match(request);
    if (cached) return cached;

    // Page offline pour les navigations HTML
    if (request.headers.get('accept') && request.headers.get('accept').includes('text/html')) {
      const offlineResponse = await caches.match(OFFLINE_PAGE);
      if (offlineResponse) return offlineResponse;
    }
    throw error;
  }
}

// -------------------------------------------------------
// Mise à jour du cache en arrière-plan
// -------------------------------------------------------
async function updateCache(request) {
  try {
    const response = await fetch(request);
    if (response.ok) {
      const cache = await caches.open(STATIC_CACHE);
      cache.put(request, response);
    }
  } catch (e) { /* silencieux */ }
}

// -------------------------------------------------------
// Notifications Push (API Push externe)
// -------------------------------------------------------
const NOTIF_ICONS = {
  alerte: '🚨', paiement_taxi: '💰', location: '🚗',
  gps: '📡', maintenance: '🔧', contravention: '🚔', info: 'ℹ️'
};

self.addEventListener('push', (event) => {
  if (!event.data) return;
  let data;
  try { data = event.data.json(); } catch(e) { data = { title: event.data.text() }; }

  const type  = data.type || 'info';
  const icon  = NOTIF_ICONS[type] || 'ℹ️';
  const title = `${icon} ${data.title || 'FlotteCar'}`;

  const options = {
    body:    data.body || '',
    icon:    BASE + 'assets/img/icon-192.png',
    badge:   BASE + 'assets/img/icon-192.png',
    vibrate: [200, 100, 200, 100, 400],
    tag:     'flottecar-' + (data.id || Date.now()),
    renotify: true,
    requireInteraction: type === 'alerte',
    data:    { url: data.url || BASE },
    actions: [
      { action: 'view',  title: '👁 Voir' },
      { action: 'close', title: '✕ Fermer' }
    ]
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  if (event.action === 'close') return;
  const url = event.notification.data?.url || BASE;
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
      // Ouvrir dans un onglet existant si possible
      for (const client of clientList) {
        if (client.url.includes(self.location.hostname) && 'focus' in client) {
          client.navigate(url);
          return client.focus();
        }
      }
      return clients.openWindow(url);
    })
  );
});

// -------------------------------------------------------
// Messages depuis l'app (ex: déclencher notif locale)
// -------------------------------------------------------
self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') { self.skipWaiting(); return; }
  if (event.data === 'CLEAR_CACHE') {
    caches.keys().then(names => names.forEach(name => caches.delete(name)));
    return;
  }
  // Badge PWA (icône écran d'accueil)
  if (event.data?.type === 'SET_BADGE') {
    const count = event.data.count || 0;
    if ('setAppBadge' in self.registration) {
      if (count > 0) self.registration.setAppBadge(count).catch(() => {});
      else self.registration.clearAppBadge().catch(() => {});
    }
    return;
  }
  // Notif locale depuis page foreground
  if (event.data?.type === 'SHOW_NOTIF') {
    const d = event.data.payload;
    const type  = d.type || 'info';
    const icon  = NOTIF_ICONS[type] || 'ℹ️';
    self.registration.showNotification(`${icon} ${d.title}`, {
      body:    d.body || '',
      icon:    BASE + 'assets/img/icon-192.png',
      badge:   BASE + 'assets/img/icon-192.png',
      vibrate: [150, 80, 150],
      tag:     'flottecar-local-' + Date.now(),
      data:    { url: d.url || BASE }
    });
  }
});
