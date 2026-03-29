<?php
/**
 * FlotteCar - Footer commun
 * Ferme le main-content, main-wrapper et body
 *
 * Variable optionnelle:
 *   $extraJs (string) - JS inline ou chemin vers un script supplémentaire
 */
$extraJs = $extraJs ?? '';
?>

    </main>
    <!-- /main-content -->

</div>
<!-- /main-wrapper -->

<!-- ============================================================
     SCRIPTS
     ============================================================ -->

<!-- Leaflet JS (disponible sur toutes les pages, utilisé pour la carte GPS) -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<!-- App JS principal -->
<script src="<?= BASE_URL ?>assets/js/app.js"></script>

<?php if ($extraJs): ?>
<!-- Script supplémentaire de la page -->
<?php
// Détecter si c'est un chemin de fichier ou du JS inline
if (str_ends_with(trim($extraJs), '.js') || str_starts_with(trim($extraJs), 'http')):
?>
<script src="<?= htmlspecialchars(trim($extraJs)) ?>"></script>
<?php else: ?>
<script>
<?= $extraJs ?>
</script>
<?php endif; ?>
<?php endif; ?>

<!-- ── Web Push Notifications ──────────────────────────────────────────── -->
<script>
(function() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

    const VAPID_PUBLIC = 'BPjWDw1_T0PMC2GkJ_oR_sgj7NIT3SZKg7aiQwoDp867K2BfKsljCsLJKUhnJMV4GC4wnqoVwpUdpRLOR7Kb9Dk';

    // Utiliser l'URL réelle du navigateur (garantit https:// en production)
    // On remonte au dossier racine du projet depuis n'importe quelle sous-page
    const _loc   = window.location;
    const _path  = _loc.pathname.replace(/\/(app|admin|api|auth|includes)(\/.*)?$/, '/').replace(/\/[^\/]+\.php.*$/, '/');
    const BASE   = _loc.protocol + '//' + _loc.host + _path;
    const SAVE_URL = BASE + 'api/save_push_sub.php';
    const SW_URL   = BASE + 'sw.js';

    // Enregistrer le SW avec le bon protocole
    navigator.serviceWorker.register(SW_URL, { scope: BASE }).catch(err => console.warn('[SW]', err));

    function urlBase64ToUint8Array(b64) {
        const pad = '='.repeat((4 - b64.length % 4) % 4);
        const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
        const out = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    function showToast(msg, color) {
        const t = document.createElement('div');
        t.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:' + color + ';color:white;padding:10px 20px;border-radius:20px;font-size:.82rem;font-weight:600;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.3);white-space:nowrap';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; setTimeout(() => t.remove(), 300); }, 3000);
    }

    async function subscribePush(btn) {
        if (btn) { btn.disabled = true; btn.textContent = '…'; }
        try {
            const reg  = await navigator.serviceWorker.ready;
            const perm = await Notification.requestPermission();

            if (perm === 'denied') {
                showToast('❌ Notifications refusées — modifiez dans les paramètres navigateur', '#ef4444');
                document.getElementById('push-banner')?.remove();
                return;
            }
            if (perm !== 'granted') {
                if (btn) { btn.disabled = false; btn.textContent = 'Activer'; }
                return;
            }

            // Si une ancienne subscription existe (clés VAPID différentes), la supprimer d'abord
            const oldSub = await reg.pushManager.getSubscription();
            if (oldSub) await oldSub.unsubscribe();

            const sub = await reg.pushManager.subscribe({
                userVisibleOnly:      true,
                applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC)
            });

            const res  = await fetch(SAVE_URL, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ action: 'subscribe', subscription: sub.toJSON() })
            });
            const json = await res.json();

            if (json.ok) {
                localStorage.setItem('fc_push_subscribed', '1');
                document.getElementById('push-banner')?.remove();
                showToast('🔔 Notifications activées !', '#059669');
            } else {
                throw new Error(json.error || 'Erreur serveur');
            }
        } catch (e) {
            console.warn('[FlotteCar Push] ERREUR:', e.name, e.message, e);
            if (btn) { btn.disabled = false; btn.textContent = 'Réessayer'; btn.style.background = '#ef4444'; }
            // Afficher le vrai message d'erreur pour faciliter le debug
            showToast('❌ ' + (e.message || 'Échec activation'), '#ef4444');
        }
    }

    async function syncPush() {
        try {
            const reg = await navigator.serviceWorker.ready;
            const sub = await reg.pushManager.getSubscription();
            if (sub) {
                await fetch(SAVE_URL, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ action: 'subscribe', subscription: sub.toJSON() })
                });
                localStorage.setItem('fc_push_subscribed', '1');
            }
        } catch (e) {}
    }

    function showPushBanner() {
        if (document.getElementById('push-banner')) return;
        const b = document.createElement('div');
        b.id = 'push-banner';
        b.style.cssText = 'position:fixed;top:60px;left:12px;right:12px;z-index:2000;background:#1e293b;color:white;border-radius:14px;padding:13px 15px;display:flex;align-items:center;gap:11px;box-shadow:0 8px 32px rgba(0,0,0,.5);border:1px solid rgba(26,86,219,.4)';
        b.innerHTML = `
            <span style="font-size:1.4rem;flex-shrink:0">🔔</span>
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:.86rem;line-height:1.2">Recevoir les alertes FlotteCar</div>
                <div style="font-size:.71rem;opacity:.6;margin-top:2px">Activez les notifications pour être alerté en temps réel</div>
            </div>
            <button id="push-accept-btn" style="background:#0d9488;color:white;border:none;padding:8px 13px;border-radius:10px;font-weight:700;font-size:.8rem;cursor:pointer;flex-shrink:0;white-space:nowrap;min-width:70px">Activer</button>
            <button onclick="window._dismissPushBanner()" style="background:transparent;color:rgba(255,255,255,.4);border:none;font-size:1.3rem;cursor:pointer;padding:4px 6px;flex-shrink:0;line-height:1">&times;</button>`;
        document.body.appendChild(b);
        document.getElementById('push-accept-btn').addEventListener('click', function() { subscribePush(this); });
    }

    window._dismissPushBanner = function() {
        localStorage.setItem('fc_push_banner_dismissed', Date.now());
        const b = document.getElementById('push-banner');
        if (b) { b.style.transition = 'opacity .25s'; b.style.opacity = '0'; setTimeout(() => b.remove(), 250); }
    };

    navigator.serviceWorker.ready.then(async (reg) => {
        const sub = await reg.pushManager.getSubscription();

        if (sub) {
            syncPush();
            return;
        }

        if (Notification.permission === 'denied') return;

        if (Notification.permission === 'granted') {
            subscribePush(null);
            return;
        }

        // Pas encore demandé → bannière après 2s (cooldown 3 jours)
        const dismissed = localStorage.getItem('fc_push_banner_dismissed');
        if (dismissed && Date.now() - parseInt(dismissed) < 3 * 24 * 3600 * 1000) return;
        setTimeout(showPushBanner, 2000);
    });
})();
</script>

</body>
</html>
