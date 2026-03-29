/**
 * FlotteCar - JavaScript Principal
 * Vanilla JS, aucune dépendance externe
 * @version 2.0.0
 */

'use strict';

// =========================================================
// INITIALISATION
// =========================================================
document.addEventListener('DOMContentLoaded', () => {
  initSidebar();
  initUserDropdown();
  initFlashMessages();
  initConfirmDialogs();
  initFormValidations();
  initDatePickers();
  initPrixCalculation();
  initTableSearch();
  initActiveNavLink();
  initModals();
  initTooltips();
  registerServiceWorker();
  initPWAInstallPrompt();
  initPushNotifications();
  initAjaxForms();
  initPasswordToggle();
  initPasswordStrength();
  animateNumbers();
});

// =========================================================
// SIDEBAR TOGGLE
// =========================================================
function initSidebar() {
  const sidebar     = document.querySelector('.sidebar');
  const mainWrapper = document.querySelector('.main-wrapper');
  const toggleBtn   = document.querySelector('.topbar-toggle');
  const overlay     = document.querySelector('.sidebar-overlay');

  if (!sidebar || !toggleBtn) return;

  const isMobile = () => window.innerWidth <= 768;

  // Restaurer l'état depuis localStorage (desktop uniquement)
  if (!isMobile()) {
    const collapsed = localStorage.getItem('sidebar_collapsed') === 'true';
    if (collapsed) {
      sidebar.classList.add('collapsed');
      mainWrapper?.classList.add('collapsed');
    }
  }

  toggleBtn.addEventListener('click', () => {
    if (isMobile()) {
      // Mobile: slide in/out avec overlay
      sidebar.classList.toggle('mobile-open');
      if (overlay) overlay.classList.toggle('active');
      document.body.style.overflow = sidebar.classList.contains('mobile-open') ? 'hidden' : '';
    } else {
      // Desktop: collapse/expand
      sidebar.classList.toggle('collapsed');
      mainWrapper?.classList.toggle('collapsed');
      localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed'));
    }
  });

  // Fermer sidebar mobile en cliquant sur l'overlay
  overlay?.addEventListener('click', closeMobileSidebar);

  // Fermer avec touche Échap
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && isMobile() && sidebar.classList.contains('mobile-open')) {
      closeMobileSidebar();
    }
  });

  function closeMobileSidebar() {
    sidebar.classList.remove('mobile-open');
    if (overlay) overlay.classList.remove('active');
    document.body.style.overflow = '';
  }

  // Recalculer au redimensionnement
  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      if (!isMobile()) {
        closeMobileSidebar();
      }
    }, 150);
  });
}

// =========================================================
// USER DROPDOWN (topbar)
// =========================================================
function initUserDropdown() {
  const userBtn = document.querySelector('.topbar-user');
  if (!userBtn) return;

  userBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    userBtn.classList.toggle('open');
  });

  document.addEventListener('click', (e) => {
    if (!userBtn.contains(e.target)) {
      userBtn.classList.remove('open');
    }
  });
}

// =========================================================
// FLASH MESSAGES - Auto-hide après 5s
// =========================================================
function initFlashMessages() {
  const alerts = document.querySelectorAll('.alert');
  alerts.forEach((alert, i) => {
    // Auto-hide progressif
    setTimeout(() => {
      dismissAlert(alert);
    }, 5000 + i * 500);
  });
}

function dismissAlert(el) {
  if (!el) return;
  el.style.transition = 'opacity 0.4s, transform 0.4s, margin 0.3s, padding 0.3s';
  el.style.opacity    = '0';
  el.style.transform  = 'translateX(20px)';
  setTimeout(() => {
    el.style.maxHeight = '0';
    el.style.margin    = '0';
    el.style.padding   = '0';
    el.style.overflow  = 'hidden';
    setTimeout(() => el.remove(), 300);
  }, 400);
}

// =========================================================
// DIALOGS DE CONFIRMATION (data-confirm)
// =========================================================
function initConfirmDialogs() {
  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-confirm]');
    if (!el) return;
    e.preventDefault();
    const msg = el.dataset.confirm || 'Êtes-vous sûr de vouloir effectuer cette action ?';
    showConfirmModal(msg, () => {
      // Exécuter l'action originale
      if (el.tagName === 'A') {
        window.location.href = el.href;
      } else if (el.tagName === 'BUTTON' || el.type === 'submit') {
        el.removeAttribute('data-confirm');
        el.click();
        el.setAttribute('data-confirm', msg);
      } else if (el.closest('form')) {
        el.closest('form').submit();
      }
    });
  });
}

function showConfirmModal(message, onConfirm) {
  // Supprimer un éventuel modal existant
  document.querySelector('#confirm-modal-overlay')?.remove();

  const overlay = document.createElement('div');
  overlay.id = 'confirm-modal-overlay';
  overlay.className = 'modal-overlay';
  overlay.innerHTML = `
    <div class="modal modal-sm">
      <div class="modal-header">
        <span class="modal-title"><i class="fas fa-exclamation-triangle" style="color:var(--warning);margin-right:8px"></i>Confirmation</span>
        <button class="modal-close" id="confirm-cancel">&times;</button>
      </div>
      <div class="modal-body">
        <p style="color:var(--text-muted);font-size:14px;line-height:1.7">${message}</p>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary btn-sm" id="confirm-no">Annuler</button>
        <button class="btn btn-danger btn-sm" id="confirm-yes">Confirmer</button>
      </div>
    </div>
  `;

  document.body.appendChild(overlay);
  requestAnimationFrame(() => overlay.classList.add('open'));

  const close = () => {
    overlay.classList.remove('open');
    setTimeout(() => overlay.remove(), 250);
  };

  overlay.querySelector('#confirm-cancel').addEventListener('click', close);
  overlay.querySelector('#confirm-no').addEventListener('click', close);
  overlay.querySelector('#confirm-yes').addEventListener('click', () => {
    close();
    onConfirm();
  });

  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) close();
  });
}

// =========================================================
// VALIDATIONS DE FORMULAIRES
// =========================================================
function initFormValidations() {
  document.querySelectorAll('form[data-validate]').forEach(form => {
    form.addEventListener('submit', (e) => {
      let valid = true;
      // Réinitialiser les erreurs
      form.querySelectorAll('.form-control.is-invalid').forEach(el => {
        el.classList.remove('is-invalid');
      });
      form.querySelectorAll('.form-error').forEach(el => el.remove());

      // Vérifier les champs requis
      form.querySelectorAll('[required]').forEach(field => {
        if (!field.value.trim()) {
          markInvalid(field, 'Ce champ est requis.');
          valid = false;
        }
      });

      // Vérifier les emails
      form.querySelectorAll('input[type="email"]').forEach(field => {
        if (field.value && !isValidEmail(field.value)) {
          markInvalid(field, 'Adresse email invalide.');
          valid = false;
        }
      });

      // Vérifier confirmations de mot de passe
      const pass    = form.querySelector('input[name="password"]');
      const confirm = form.querySelector('input[name="password_confirm"]');
      if (pass && confirm && pass.value && pass.value !== confirm.value) {
        markInvalid(confirm, 'Les mots de passe ne correspondent pas.');
        valid = false;
      }

      if (!valid) {
        e.preventDefault();
        // Scroll vers la première erreur
        form.querySelector('.is-invalid')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
  });
}

function markInvalid(field, msg) {
  field.classList.add('is-invalid');
  const err = document.createElement('div');
  err.className = 'form-error';
  err.textContent = msg;
  field.parentNode.insertBefore(err, field.nextSibling);
}

function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

// =========================================================
// DATE PICKERS - min/max automatiques
// =========================================================
function initDatePickers() {
  const today = new Date().toISOString().split('T')[0];

  // Dates de début: min = aujourd'hui
  document.querySelectorAll('input[data-date="start"]').forEach(input => {
    if (!input.min) input.min = today;
    input.addEventListener('change', () => {
      const endInput = input.closest('form')?.querySelector('input[data-date="end"]');
      if (endInput) endInput.min = input.value || today;
    });
  });

  // Dates de fin: min = date début ou aujourd'hui
  document.querySelectorAll('input[data-date="end"]').forEach(input => {
    if (!input.min) input.min = today;
  });

  // Déclencher les événements pour les valeurs initiales
  document.querySelectorAll('input[data-date="start"]').forEach(input => {
    if (input.value) input.dispatchEvent(new Event('change'));
  });
}

// =========================================================
// CALCUL AUTOMATIQUE PRIX LOCATION
// =========================================================
function initPrixCalculation() {
  const form = document.querySelector('[data-prix-auto]');
  if (!form) return;

  const dateDebut   = form.querySelector('[name="date_debut"]');
  const dateFin     = form.querySelector('[name="date_fin"]');
  const prixJour    = form.querySelector('[name="prix_jour"]');
  const remise      = form.querySelector('[name="remise"]');
  const totalEl     = form.querySelector('[data-total]');
  const joursEl     = form.querySelector('[data-jours]');

  function calculate() {
    if (!dateDebut?.value || !dateFin?.value || !prixJour?.value) return;

    const d1   = new Date(dateDebut.value);
    const d2   = new Date(dateFin.value);
    const diff = Math.max(1, Math.ceil((d2 - d1) / (1000 * 3600 * 24)));
    const prix = parseFloat(prixJour.value) || 0;
    const rem  = parseFloat(remise?.value) || 0;
    const total = Math.max(0, (diff * prix) - rem);

    if (joursEl)  joursEl.textContent = diff + (diff > 1 ? ' jours' : ' jour');
    if (totalEl)  totalEl.textContent = formatNumber(total) + ' FCFA';

    // Mettre à jour un champ caché si présent
    const montantInput = form.querySelector('[name="montant_total"]');
    if (montantInput) montantInput.value = total;
  }

  [dateDebut, dateFin, prixJour, remise].forEach(el => {
    el?.addEventListener('input', calculate);
    el?.addEventListener('change', calculate);
  });

  // Calculer immédiatement si valeurs présentes
  calculate();
}

// =========================================================
// RECHERCHE EN TEMPS RÉEL DANS LES TABLEAUX
// =========================================================
function initTableSearch() {
  document.querySelectorAll('[data-search-table]').forEach(input => {
    const targetId = input.dataset.searchTable;
    const table    = document.getElementById(targetId) || document.querySelector(targetId);
    if (!table) return;

    input.addEventListener('input', () => {
      const query = input.value.toLowerCase().trim();
      const rows  = table.querySelectorAll('tbody tr');
      let visible = 0;

      rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const show = !query || text.includes(query);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
      });

      // Afficher empty state si aucun résultat
      const emptyEl = table.closest('.card')?.querySelector('[data-empty-search]');
      if (emptyEl) emptyEl.style.display = visible === 0 ? '' : 'none';
    });
  });

  // Filtres select
  document.querySelectorAll('[data-filter-table]').forEach(select => {
    const targetId = select.dataset.filterTable;
    const col      = parseInt(select.dataset.filterCol || '0');
    const table    = document.getElementById(targetId) || document.querySelector(targetId);
    if (!table) return;

    select.addEventListener('change', () => {
      const value = select.value.toLowerCase();
      table.querySelectorAll('tbody tr').forEach(row => {
        const cell = row.cells[col];
        const show = !value || (cell?.textContent.toLowerCase().includes(value));
        row.style.display = show ? '' : 'none';
      });
    });
  });
}

// =========================================================
// ACTIVE NAV LINK (correspondance URL)
// =========================================================
function initActiveNavLink() {
  const currentPath = window.location.pathname;
  document.querySelectorAll('.nav-link').forEach(link => {
    const href = link.getAttribute('href');
    if (!href || href === '#') return;
    try {
      const linkPath = new URL(href, window.location.origin).pathname;
      if (currentPath === linkPath || currentPath.startsWith(linkPath.replace(/\/[^/]+\.php$/, '/'))) {
        link.classList.add('active');
      }
    } catch (e) { /* silencieux */ }
  });
}

// =========================================================
// MODALES
// =========================================================
function initModals() {
  // Ouverture par data-modal-open
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-modal-open]');
    if (btn) {
      const modalId = btn.dataset.modalOpen;
      openModal(modalId);
    }

    const closeBtn = e.target.closest('[data-modal-close], .modal-close');
    if (closeBtn) {
      const overlay = closeBtn.closest('.modal-overlay');
      if (overlay) closeModal(overlay.id || overlay);
    }
  });

  // Fermer en cliquant sur l'overlay
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) closeModal(overlay);
    });
  });

  // Fermer avec Échap
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-overlay.open').forEach(closeModal);
    }
  });
}

function openModal(idOrEl) {
  const el = typeof idOrEl === 'string' ? document.getElementById(idOrEl) : idOrEl;
  if (!el) return;
  el.classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeModal(idOrEl) {
  const el = typeof idOrEl === 'string' ? document.getElementById(idOrEl) : idOrEl;
  if (!el) return;
  el.classList.remove('open');
  document.body.style.overflow = '';
}

// Exposer globalement
window.openModal  = openModal;
window.closeModal = closeModal;

// =========================================================
// TOOLTIPS SIMPLES
// =========================================================
function initTooltips() {
  // Géré par CSS via [data-tip]
  // Aucune logique JS nécessaire sauf pour les positions complexes
}

// =========================================================
// PASSWORD TOGGLE (show/hide)
// =========================================================
function initPasswordToggle() {
  document.querySelectorAll('[data-password-toggle]').forEach(btn => {
    btn.addEventListener('click', () => {
      const targetId = btn.dataset.passwordToggle;
      const input    = document.getElementById(targetId) || btn.closest('.input-group')?.querySelector('input');
      if (!input) return;
      const isText = input.type === 'text';
      input.type   = isText ? 'password' : 'text';
      const icon   = btn.querySelector('i');
      if (icon) {
        icon.classList.toggle('fa-eye',      isText);
        icon.classList.toggle('fa-eye-slash', !isText);
      }
    });
  });
}

// =========================================================
// INDICATEUR FORCE MOT DE PASSE
// =========================================================
function initPasswordStrength() {
  const input  = document.querySelector('input[name="password"][data-strength]');
  const bar    = document.querySelector('.strength-fill');
  const text   = document.querySelector('.strength-text');
  if (!input || !bar) return;

  input.addEventListener('input', () => {
    const val = input.value;
    let score = 0;
    if (val.length >= 8)    score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^a-zA-Z0-9]/.test(val)) score++;

    const levels = [
      { width: '0%',   color: '',                  label: '' },
      { width: '25%',  color: 'var(--danger)',      label: 'Faible' },
      { width: '50%',  color: 'var(--warning)',     label: 'Moyen' },
      { width: '75%',  color: 'var(--primary)',     label: 'Fort' },
      { width: '100%', color: 'var(--success)',     label: 'Très fort' },
    ];

    const level      = levels[score] || levels[0];
    bar.style.width  = level.width;
    bar.style.background = level.color;
    if (text) text.textContent = level.label;
  });
}

// =========================================================
// ENREGISTREMENT SERVICE WORKER
// =========================================================
async function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) return;
  try {
    // Utiliser BASE_URL dynamique pour fonctionner en local ET en production
    const swBase = (window.BASE_URL || '/').replace(/\/+$/, '/');
    const reg = await navigator.serviceWorker.register(swBase + 'sw.js', {
      scope: swBase
    });
    console.log('[FlotteCar] Service Worker enregistré:', reg.scope);

    // Écouter les mises à jour
    reg.addEventListener('updatefound', () => {
      const newWorker = reg.installing;
      newWorker?.addEventListener('statechange', () => {
        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
          showUpdateBanner();
        }
      });
    });
  } catch (err) {
    console.warn('[FlotteCar] Service Worker échec:', err);
  }
}

function showUpdateBanner() {
  const banner = document.createElement('div');
  banner.style.cssText = `
    position:fixed;bottom:20px;left:50%;transform:translateX(-50%);
    background:var(--primary);color:white;padding:12px 20px;border-radius:10px;
    font-size:13px;font-weight:600;z-index:9999;
    display:flex;align-items:center;gap:12px;box-shadow:0 4px 20px rgba(0,0,0,0.4);
  `;
  banner.innerHTML = `
    <i class="fas fa-arrow-up-circle"></i>
    Mise à jour disponible
    <button onclick="window.location.reload()" style="
      background:rgba(255,255,255,0.2);border:none;color:white;
      padding:4px 10px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700;
    ">Actualiser</button>
    <button onclick="this.closest('div').remove()" style="
      background:none;border:none;color:rgba(255,255,255,0.7);cursor:pointer;font-size:16px;
    ">&times;</button>
  `;
  document.body.appendChild(banner);
}

// =========================================================
// PWA INSTALL PROMPT
// =========================================================
let deferredPrompt = null;

function initPWAInstallPrompt() {
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;

    // Afficher prompt custom si pas encore installé
    if (!localStorage.getItem('pwa_dismissed')) {
      setTimeout(showPWAPrompt, 3000);
    }
  });

  window.addEventListener('appinstalled', () => {
    hidePWAPrompt();
    localStorage.setItem('pwa_installed', 'true');
    deferredPrompt = null;
  });
}

function showPWAPrompt() {
  if (!deferredPrompt) return;
  let prompt = document.querySelector('.pwa-prompt');
  if (!prompt) {
    prompt = document.createElement('div');
    prompt.className = 'pwa-prompt';
    prompt.innerHTML = `
      <div class="pwa-prompt-icon"><i class="fas fa-car-side"></i></div>
      <div class="pwa-prompt-text">
        <div class="pwa-prompt-title">Installer FlotteCar</div>
        <div class="pwa-prompt-sub">Accès rapide depuis votre écran d'accueil</div>
      </div>
      <div class="pwa-prompt-actions">
        <button class="btn btn-ghost btn-sm" id="pwa-dismiss">Plus tard</button>
        <button class="btn btn-primary btn-sm" id="pwa-install">Installer</button>
      </div>
    `;
    document.body.appendChild(prompt);

    document.getElementById('pwa-install')?.addEventListener('click', async () => {
      if (!deferredPrompt) return;
      deferredPrompt.prompt();
      const result = await deferredPrompt.userChoice;
      if (result.outcome === 'accepted') {
        hidePWAPrompt();
      }
      deferredPrompt = null;
    });

    document.getElementById('pwa-dismiss')?.addEventListener('click', () => {
      hidePWAPrompt();
      localStorage.setItem('pwa_dismissed', 'true');
    });
  }
  requestAnimationFrame(() => prompt.classList.add('show'));
}

function hidePWAPrompt() {
  const prompt = document.querySelector('.pwa-prompt');
  if (prompt) {
    prompt.classList.remove('show');
    setTimeout(() => prompt.remove(), 400);
  }
}

window.showPWAPrompt = showPWAPrompt;

// =========================================================
// PUSH NOTIFICATIONS — Permission + Polling + Affichage
// =========================================================

const _BASE = window.BASE_URL || '/';

// Couleurs et icônes par type (complet)
const _NOTIF_COLORS = {
  alerte:       { bg:'#ef4444', light:'#fef2f2', icon:'🚨' },
  maintenance:  { bg:'#f97316', light:'#fff7ed', icon:'🔧' },
  paiement:     { bg:'#10b981', light:'#ecfdf5', icon:'💰' },
  paiement_taxi:{ bg:'#10b981', light:'#ecfdf5', icon:'💰' },
  taxi:         { bg:'#d97706', light:'#fef3c7', icon:'🚕' },
  location:     { bg:'#8b5cf6', light:'#f5f3ff', icon:'🚗' },
  gps:          { bg:'#0ea5e9', light:'#f0f9ff', icon:'📡' },
  contravention:{ bg:'#dc2626', light:'#fef2f2', icon:'🚔' },
  inscription:  { bg:'#1a56db', light:'#eff6ff', icon:'🏢' },
  abonnement:   { bg:'#059669', light:'#ecfdf5', icon:'💳' },
  info:         { bg:'#1a56db', light:'#eff6ff', icon:'ℹ️' },
};
function _notifStyle(type) {
  return _NOTIF_COLORS[type] || _NOTIF_COLORS.info;
}

// ── Son et vibration ───────────────────────────────────────────────────────
function playNotifSound(urgent = false) {
  try {
    const ctx  = new (window.AudioContext || window.webkitAudioContext)();
    const osc  = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.connect(gain); gain.connect(ctx.destination);
    osc.type = 'sine';
    if (urgent) {
      osc.frequency.setValueAtTime(1200, ctx.currentTime);
      osc.frequency.exponentialRampToValueAtTime(600, ctx.currentTime + 0.1);
      osc.frequency.setValueAtTime(1200, ctx.currentTime + 0.15);
      osc.frequency.exponentialRampToValueAtTime(600, ctx.currentTime + 0.25);
      gain.gain.setValueAtTime(0.7, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
      osc.start(ctx.currentTime); osc.stop(ctx.currentTime + 0.4);
    } else {
      osc.frequency.setValueAtTime(880, ctx.currentTime);
      osc.frequency.exponentialRampToValueAtTime(440, ctx.currentTime + 0.15);
      gain.gain.setValueAtTime(0.4, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.45);
      osc.start(ctx.currentTime); osc.stop(ctx.currentTime + 0.45);
    }
  } catch(e) {}
  if (navigator.vibrate) {
    navigator.vibrate(urgent ? [200, 100, 200, 100, 400, 100, 200] : [150, 80, 150]);
  }
}

// ── Dialog permission ──────────────────────────────────────────────────────
async function requestNotifPermission() {
  if (!('Notification' in window)) return false;
  if (Notification.permission === 'granted') return true;
  if (Notification.permission === 'denied')  return false;
  const perm = await Notification.requestPermission();
  return perm === 'granted';
}

function _markNotifAsked() {
  // Marquer en session PHP + local
  fetch(_BASE + 'api/notifs.php', {method:'POST', body: new URLSearchParams({action:'set_asked'})}).catch(()=>{});
}

function showNotifPermissionDialog() {
  if (!('Notification' in window)) return;
  if (Notification.permission !== 'default') return; // Navigateur = seule source de vérité
  if (document.getElementById('notif-perm-dlg')) return;

  const d = document.createElement('div');
  d.id = 'notif-perm-dlg';
  d.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.7);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px';
  d.innerHTML = `
    <div style="background:#fff;border-radius:20px;padding:36px 32px;max-width:400px;width:100%;text-align:center;box-shadow:0 24px 64px rgba(0,0,0,.3);animation:slideInRight .4s cubic-bezier(.175,.885,.32,1.275)">
      <div style="width:80px;height:80px;background:linear-gradient(135deg,#1a56db,#60a5fa);border-radius:22px;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;font-size:40px;box-shadow:0 8px 24px rgba(26,86,219,.4)">🔔</div>
      <h2 style="font-size:1.4rem;font-weight:800;margin:0 0 10px;color:#0f172a">Activer les notifications</h2>
      <p style="color:#64748b;font-size:.9rem;line-height:1.7;margin:0 0 28px">
        Recevez instantanément les alertes de <strong>maintenance</strong>, <strong>paiements taxi</strong>,
        <strong>locations</strong>, expirations d'<strong>assurance / vignette</strong> et alertes <strong>GPS</strong>.
        <br><br>
        <span style="color:#1a56db;font-size:.82rem">🔔 Son + Vibration sur mobile</span>
      </p>
      <div style="display:flex;gap:10px;justify-content:center">
        <button id="nd-deny" style="flex:1;padding:13px;border-radius:12px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#64748b;font-weight:600;cursor:pointer;font-size:.9rem;font-family:inherit">Plus tard</button>
        <button id="nd-allow" style="flex:2;padding:13px;border-radius:12px;border:none;background:linear-gradient(135deg,#1a56db,#3b82f6);color:#fff;font-weight:700;cursor:pointer;font-size:.95rem;font-family:inherit;box-shadow:0 6px 16px rgba(26,86,219,.45)">🔔 Activer maintenant</button>
      </div>
    </div>`;
  document.body.appendChild(d);

  document.getElementById('nd-allow').onclick = async () => {
    d.remove();
    window.NOTIF_ASKED = true;
    _markNotifAsked();
    const ok = await requestNotifPermission();
    if (ok) {
      playNotifSound();
      showToast('✅ Notifications activées !', 'success');
    }
  };
  document.getElementById('nd-deny').onclick = () => {
    d.remove();
    window.NOTIF_ASKED = true;
    _markNotifAsked();
  };
}

// ── Toast de notification enrichi ─────────────────────────────────────────
// Offset pour empiler les toasts sans se chevaucher
let _toastOffset = 0;

function showPushNotif(notif) {
  const s       = _notifStyle(notif.type);
  const urgent  = notif.type === 'alerte' || notif.type === 'gps';
  playNotifSound(urgent);

  _toastOffset += 1;
  const idx = _toastOffset;

  const t = document.createElement('div');
  t.dataset.toastIdx = idx;
  t.style.cssText = [
    'position:fixed',
    `top:${16 + (idx - 1) * 88}px`,
    'right:16px',
    'z-index:99998',
    'background:#fff',
    'border-radius:16px',
    'padding:0',
    `box-shadow:0 8px 32px rgba(0,0,0,.16)`,
    `border-left:5px solid ${s.bg}`,
    'max-width:360px',
    'width:calc(100vw - 32px)',
    'animation:slideInRight .4s cubic-bezier(.175,.885,.32,1.275)',
    'overflow:hidden',
    'cursor:pointer',
  ].join(';');

  t.innerHTML = `
    <div style="display:flex;align-items:flex-start;padding:14px 12px 14px 16px;gap:12px">
      <div style="width:44px;height:44px;border-radius:12px;background:${s.light};display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0">${s.icon}</div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:800;font-size:.95rem;color:#0f172a;line-height:1.3;margin-bottom:4px">${notif.titre}</div>
        ${notif.corps ? `<div style="font-size:.82rem;color:#475569;line-height:1.5">${notif.corps}</div>` : ''}
        <div style="font-size:.7rem;color:#94a3b8;margin-top:5px;display:flex;align-items:center;gap:4px">
          <img src="${_BASE}assets/img/icon-192.png" style="width:12px;height:12px;border-radius:3px;vertical-align:middle" onerror="this.style.display='none'">
          FlotteCar &nbsp;·&nbsp; À l'instant
        </div>
      </div>
      <button onclick="event.stopPropagation();this.closest('div[data-toast-idx]').style.animation='slideOutRight .3s ease forwards';setTimeout(()=>{this.closest('div[data-toast-idx]').remove();_reindexToasts()},300)"
              style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:1.2rem;padding:0 4px;line-height:1;flex-shrink:0;margin-top:-2px">×</button>
    </div>
    <div style="height:3px;background:${s.bg};animation:notifProgress 7s linear forwards;border-radius:0 0 0 3px"></div>
  `;

  if (notif.url) {
    t.onclick = () => { window.location.href = notif.url; };
  }

  document.body.appendChild(t);
  setTimeout(() => { t.style.animation = 'slideOutRight .35s ease forwards'; }, 7000);
  setTimeout(() => { t.remove(); _reindexToasts(); }, 7400);

  // Notification OS (si permission accordée)
  if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
    try {
      const n = new Notification(notif.titre, {
        body:              notif.corps || '',
        icon:              _BASE + 'assets/img/icon-192.png',
        badge:             _BASE + 'assets/img/icon-192.png',
        tag:               'fc-' + (notif.id || Date.now()),
        renotify:          true,
        requireInteraction: urgent,
        silent:            false,
      });
      if (notif.url) n.onclick = () => { window.focus(); window.location.href = notif.url; n.close(); };
    } catch(e) {}
  }
}

function _reindexToasts() {
  const toasts = document.querySelectorAll('div[data-toast-idx]');
  toasts.forEach((t, i) => {
    t.dataset.toastIdx = i + 1;
    t.style.top = (16 + i * 88) + 'px';
  });
  _toastOffset = toasts.length;
}

// ── Panel de notifications (cloche header) ────────────────────────────────
let _notifs    = [];
let _panelOpen = false;
let _polling   = false; // true pendant un fetch en cours

function toggleNotifPanel() {
  const p = document.getElementById('notifPanel');
  if (!p) return;
  _panelOpen = !_panelOpen;
  p.style.display = _panelOpen ? 'flex' : 'none';

  if (_panelOpen) {
    // Si pas encore de données → montrer spinner + forcer poll
    if (_notifs.length === 0) {
      const list = document.getElementById('notifList');
      if (list) list.innerHTML = `<div style="padding:32px 16px;text-align:center;color:#94a3b8">
        <div style="width:28px;height:28px;border:3px solid #e2e8f0;border-top-color:#1a56db;border-radius:50%;animation:spin .7s linear infinite;margin:0 auto 12px"></div>
        <div style="font-size:.82rem">Chargement…</div>
      </div>`;
      _doPoll(true); // forcer même si déjà en cours
    } else {
      _refreshNotifPanel();
    }
    // Marquer tout comme lu côté serveur quand panel ouvert
    setTimeout(() => {
      _notifs.forEach(n => n._read = true);
      _refreshNotifPanel();
      fetch(_BASE + 'api/notifs.php', {method:'POST', body: new URLSearchParams({action:'mark_read'})}).catch(()=>{});
    }, 800); // délai pour que l'user voit les items non-lus
  }
}

function _refreshNotifPanel() {
  const badge = document.getElementById('notifBadge');
  const list  = document.getElementById('notifList');
  if (!badge || !list) return;

  // Badge cloche
  const unread = _notifs.filter(n => !n._read).length;
  if (unread > 0) {
    badge.textContent = unread > 99 ? '99+' : String(unread);
    badge.style.display        = 'inline-flex';
    badge.style.alignItems     = 'center';
    badge.style.justifyContent = 'center';
  } else {
    badge.style.display = 'none';
  }

  // Badge icône PWA (Android)
  _setPWABadge(unread);

  // Contenu liste
  if (_notifs.length === 0) {
    list.innerHTML = `<div style="padding:36px 16px;text-align:center;color:#94a3b8">
      <div style="font-size:2.5rem;margin-bottom:10px">🔔</div>
      <div style="font-size:.88rem;font-weight:600;color:#64748b">Aucune notification</div>
      <div style="font-size:.75rem;margin-top:4px">Tout est à jour !</div>
    </div>`;
    return;
  }

  list.innerHTML = _notifs.map(n => {
    const s   = _notifStyle(n.type);
    const ago = _timeAgo(n.created_at);
    const isNew = !n._read;
    return `<div style="display:flex;align-items:flex-start;gap:10px;padding:13px 14px;border-bottom:1px solid #f1f5f9;background:${isNew?'#f8faff':'#fff'};transition:background .2s;cursor:pointer;position:relative"
      onclick="if(event.target.tagName!=='BUTTON'){ window.location='${n.url||'#'}'; }">
      ${isNew ? `<div style="position:absolute;left:0;top:0;bottom:0;width:3px;background:${s.bg};border-radius:0 2px 2px 0"></div>` : ''}
      <div style="width:38px;height:38px;border-radius:10px;background:${s.light};display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0">${s.icon}</div>
      <div style="flex:1;min-width:0;${isNew?'':'opacity:.6'}">
        <div style="font-size:.84rem;font-weight:${isNew?'700':'600'};color:#0f172a;line-height:1.35">${n.titre}</div>
        ${n.corps ? `<div style="font-size:.77rem;color:#64748b;margin-top:2px;line-height:1.4">${n.corps}</div>` : ''}
        <div style="font-size:.68rem;color:#94a3b8;margin-top:4px">${ago}</div>
      </div>
      <button onclick="event.stopPropagation();deleteNotif(${n.id})" title="Supprimer"
        style="background:none;border:none;cursor:pointer;color:#cbd5e1;font-size:1.1rem;padding:4px 6px;line-height:1;flex-shrink:0;border-radius:6px"
        onmouseover="this.style.background='#fee2e2';this.style.color='#ef4444'"
        onmouseout="this.style.background='none';this.style.color='#cbd5e1'">×</button>
    </div>`;
  }).join('');
}

// ── Badge icône PWA (home screen Android/desktop) ─────────────────────────
function _setPWABadge(count) {
  try {
    if ('setAppBadge' in navigator) {
      if (count > 0) {
        navigator.setAppBadge(count).catch(() => {});
      } else {
        navigator.clearAppBadge().catch(() => {});
      }
    }
    // Via service worker
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
      navigator.serviceWorker.controller.postMessage({ type: 'SET_BADGE', count });
    }
  } catch(e) {}
}

function _timeAgo(dateStr) {
  if (!dateStr) return '';
  const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
  if (diff < 60)   return 'À l\'instant';
  if (diff < 3600) return `Il y a ${Math.floor(diff/60)} min`;
  if (diff < 86400) return `Il y a ${Math.floor(diff/3600)}h`;
  return `Il y a ${Math.floor(diff/86400)}j`;
}

function markAllRead() {
  _notifs.forEach(n => n._read = true);
  _refreshNotifPanel();
  fetch(_BASE + 'api/notifs.php', {method:'POST', body: new URLSearchParams({action:'mark_read'})}).catch(()=>{});
}

async function deleteNotif(id) {
  _notifs = _notifs.filter(n => n.id != id);
  _refreshNotifPanel();
  fetch(_BASE + 'api/notifs.php', {method:'POST', body: new URLSearchParams({action:'delete', id})}).catch(()=>{});
}

// Fermer panel si clic hors
document.addEventListener('click', function(e) {
  if (!e.target.closest('#notifBtn') && !e.target.closest('#notifPanel')) {
    const p = document.getElementById('notifPanel');
    if (p && _panelOpen) { p.style.display='none'; _panelOpen=false; }
  }
});

// ── Polling ────────────────────────────────────────────────────────────────
let _pollTimer = null;

function startNotifPolling() {
  if (_pollTimer) return;
  _doPoll();
  _pollTimer = setInterval(_doPoll, 30000);
}

async function _doPoll(force = false) {
  if (_polling && !force) return; // Si forçé (ouverture panel), on laisse passer
  _polling = true;
  try {
    const res  = await fetch(_BASE + 'api/notifs.php?action=pending', {cache:'no-store'});
    if (!res.ok) return;
    const data = await res.json();
    if (!data.notifs) return;

    // Conserver les flags _read locaux puis remplacer
    const readIds = new Set(_notifs.filter(n => n._read).map(n => n.id));
    _notifs = data.notifs.map(n => ({ ...n, _read: readIds.has(n.id) }));

    _refreshNotifPanel();

    // Mettre à jour le badge icône PWA immédiatement après chaque poll
    const unreadCount = data.notifs.filter(n => !n._read).length;
    _setPWABadge(unreadCount > 0 ? data.count : 0);

    // Toasts uniquement pour les nouvelles (is_new=true)
    const fresh = data.notifs.filter(n => n.is_new);
    fresh.forEach((n, i) => setTimeout(() => showPushNotif(n), i * 1000));

  } catch(e) {
    // Silencieux
  } finally {
    _polling = false;
  }
}

// ── Initialisation ─────────────────────────────────────────────────────────
function initPushNotifications() {
  // Le polling alimente le panel cloche et les toasts
  startNotifPolling();
  // La demande de permission WebPush est gérée par footer.php (bannière)
}

// CSS animations injectées une seule fois
(function injectNotifCSS() {
  if (document.getElementById('notif-css')) return;
  const s = document.createElement('style');
  s.id = 'notif-css';
  s.textContent = `
    @keyframes slideInRight { from{transform:translateX(120%);opacity:0} to{transform:translateX(0);opacity:1} }
    @keyframes slideOutRight { from{transform:translateX(0);opacity:1} to{transform:translateX(120%);opacity:0} }
    @keyframes notifProgress { from{width:100%} to{width:0} }
    @keyframes spin { to{transform:rotate(360deg)} }
  `;
  document.head.appendChild(s);
})();

window.initPushNotifications = initPushNotifications;
window.toggleNotifPanel      = toggleNotifPanel;
window.markAllRead           = markAllRead;
window.deleteNotif           = deleteNotif;

// =========================================================
// SOUMISSIONS AJAX (data-ajax)
// =========================================================
function initAjaxForms() {
  document.querySelectorAll('form[data-ajax]').forEach(form => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn      = form.querySelector('[type="submit"]');
      const endpoint = form.action || form.dataset.ajax;
      const method   = form.method?.toUpperCase() || 'POST';

      // Loader sur le bouton
      const originalHtml = btn?.innerHTML;
      if (btn) {
        btn.disabled   = true;
        btn.innerHTML  = '<span class="spinner spinner-sm"></span> Envoi...';
      }

      try {
        const resp = await fetch(endpoint, {
          method,
          body:    new FormData(form),
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await resp.json();

        if (data.success) {
          if (data.message) showToast(data.message, 'success');
          if (data.redirect) setTimeout(() => window.location.href = data.redirect, 800);
          if (data.reload)   setTimeout(() => window.location.reload(), 800);
          form.dispatchEvent(new CustomEvent('ajax:success', { detail: data }));
        } else {
          showToast(data.message || 'Une erreur est survenue.', 'error');
          form.dispatchEvent(new CustomEvent('ajax:error', { detail: data }));
        }
      } catch (err) {
        showToast('Erreur réseau. Vérifiez votre connexion.', 'error');
      } finally {
        if (btn) {
          btn.disabled  = false;
          btn.innerHTML = originalHtml;
        }
      }
    });
  });
}

// =========================================================
// TOAST NOTIFICATION
// =========================================================
function showToast(message, type = 'info') {
  const container = getOrCreateToastContainer();
  const toast     = document.createElement('div');
  const icons     = { success: 'check-circle', error: 'times-circle', warning: 'exclamation-triangle', info: 'info-circle' };

  toast.className = `alert alert-${type}`;
  toast.style.cssText = 'margin:0;min-width:280px;max-width:420px;cursor:pointer;';
  toast.innerHTML = `<i class="fas fa-${icons[type] || 'info-circle'}"></i> ${escapeHtml(message)}`;
  toast.addEventListener('click', () => dismissAlert(toast));

  container.appendChild(toast);

  setTimeout(() => dismissAlert(toast), 4500);
}

function getOrCreateToastContainer() {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    container.style.cssText = `
      position:fixed;top:80px;right:20px;z-index:9999;
      display:flex;flex-direction:column;gap:8px;max-width:420px;
    `;
    document.body.appendChild(container);
  }
  return container;
}

window.showToast = showToast;

// =========================================================
// ANIMATION DES NOMBRES (stat cards)
// =========================================================
function animateNumbers() {
  const els = document.querySelectorAll('[data-count]');
  if (!els.length) return;

  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      const el      = entry.target;
      const target  = parseFloat(el.dataset.count) || 0;
      const suffix  = el.dataset.suffix || '';
      const decimals= parseInt(el.dataset.decimals || '0');
      const duration= 1200;
      const start   = performance.now();

      const tick = (now) => {
        const elapsed  = Math.min((now - start) / duration, 1);
        const eased    = 1 - Math.pow(1 - elapsed, 3); // ease-out cubic
        const current  = target * eased;
        el.textContent = formatNumber(current, decimals) + suffix;
        if (elapsed < 1) requestAnimationFrame(tick);
      };

      requestAnimationFrame(tick);
      observer.unobserve(el);
    });
  }, { threshold: 0.3 });

  els.forEach(el => observer.observe(el));
}

// =========================================================
// HELPERS UTILITAIRES
// =========================================================

/**
 * Formate un nombre avec séparateurs de milliers
 */
function formatNumber(n, decimals = 0) {
  return new Intl.NumberFormat('fr-FR', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals
  }).format(n);
}

/**
 * Échappe le HTML pour prévenir XSS
 */
function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = String(str);
  return div.innerHTML;
}

/**
 * Debounce d'une fonction
 */
function debounce(fn, delay = 300) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), delay);
  };
}

/**
 * Copier dans le presse-papier
 */
async function copyToClipboard(text) {
  try {
    await navigator.clipboard.writeText(text);
    showToast('Copié dans le presse-papier !', 'success');
  } catch {
    showToast('Impossible de copier.', 'error');
  }
}

window.copyToClipboard = copyToClipboard;
window.formatNumber    = formatNumber;
window.showConfirmModal = showConfirmModal;

// =========================================================
// CHARGEMENT BOUTONS (feedback visuel) - via submit event
// =========================================================
document.querySelectorAll('form').forEach(form => {
  form.addEventListener('submit', (e) => {
    const btn = form.querySelector('[data-loading][type="submit"], [data-loading]:not([type="button"])');
    if (!btn || btn.disabled) return;
    const original = btn.innerHTML;
    // Laisser le form soumettre AVANT de désactiver
    requestAnimationFrame(() => {
      btn.innerHTML = `<span class="spinner spinner-sm"></span> ${btn.dataset.loading}`;
      // Ne pas désactiver pour éviter de bloquer le submit natif
    });
    // Reset de secours si on reste sur la page (erreur)
    setTimeout(() => {
      if (document.contains(btn)) {
        btn.innerHTML = original;
      }
    }, 8000);
  });
});

// =========================================================
// SIDEBAR LINKS avec data-tooltip pour mode collapsed
// =========================================================
document.querySelectorAll('.nav-link').forEach(link => {
  const text = link.querySelector('.nav-link-text')?.textContent?.trim();
  if (text && !link.dataset.tooltip) {
    link.dataset.tooltip = text;
  }
});

// (Initialisation dans le DOMContentLoaded en début de fichier)
