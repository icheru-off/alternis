/* Alternis — cœur JavaScript de l'interface */
(function () {
  'use strict';
  const B = (window.ALT && window.ALT.base) || '';
  // Normalise pour éviter les doubles slashes (« //api/... » serait interprété
  // comme un nom de domaine par le navigateur).
  const api = (p) => {
    const base = B.replace(/\/+$/, '');
    return (base ? base + '/' : '/') + String(p).replace(/^\/+/, '');
  };

  /* ---------- Helpers réseau ---------- */
  window.altFetch = async function (url, opts = {}) {
    opts.headers = Object.assign({ 'X-CSRF': window.ALT.csrf }, opts.headers || {});
    if (opts.json) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(opts.json);
      opts.method = opts.method || 'POST';
      delete opts.json;
    } else if (opts.form) {
      opts.body = opts.form;           // FormData : le navigateur pose le bon Content-Type
      opts.method = opts.method || 'POST';
      delete opts.form;
    }
    const r = await fetch(api(url), opts);
    const ct = r.headers.get('content-type') || '';
    const data = ct.includes('json') ? await r.json() : await r.text();
    if (!r.ok) throw (data && data.error ? data : { error: 'Erreur ' + r.status });
    return data;
  };

  /* ---------- Toasts ---------- */
  window.toast = function (msg, type = '') {
    const stack = document.getElementById('toastStack');
    if (!stack) return;
    // Regroupe : si le dernier toast affiche le même message, on incrémente un compteur
    const last = stack.lastElementChild;
    if (last && last.dataset.msg === msg) {
      const n = (parseInt(last.dataset.count || '1', 10) || 1) + 1;
      last.dataset.count = n;
      last.textContent = msg + '  ×' + n;
      // relance le minuteur de disparition
      clearTimeout(last._timer);
      last._timer = setTimeout(() => { last.classList.add('out'); setTimeout(() => last.remove(), 300); }, 3600);
      return;
    }
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.dataset.msg = msg;
    t.textContent = msg;
    stack.appendChild(t);
    // Ne jamais garder plus de 3 toasts à l'écran : on retire les plus anciens
    while (stack.children.length > 3) {
      const old = stack.firstElementChild;
      clearTimeout(old._timer);
      old.remove();
    }
    t._timer = setTimeout(() => { t.classList.add('out'); setTimeout(() => t.remove(), 300); }, 3600);
  };

  /* ---------- Menu latéral : off-canvas (mobile) / repli (PC) ---------- */
  const sidebar = document.getElementById('sidebar');
  const scrim = document.getElementById('sidebarScrim');
  const toggle = document.getElementById('menuToggle');
  function openMenu() { sidebar.classList.add('open'); scrim.classList.add('show'); }
  function closeMenu() { sidebar.classList.remove('open'); scrim.classList.remove('show'); }
  function toggleCollapse() {
    const on = document.body.classList.toggle('sidebar-collapsed');
    try { localStorage.setItem('alt_sidebar', on ? 'collapsed' : 'expanded'); } catch (e) {}
  }
  if (toggle) toggle.addEventListener('click', () => {
    if (window.innerWidth <= 760) openMenu();  // mobile : ouvre le menu
    else toggleCollapse();                     // PC : réduit / agrandit
  });
  if (scrim) scrim.addEventListener('click', closeMenu);

  /* ---------- Messages / bannières admin ---------- */
  document.querySelectorAll('.ab-close').forEach(function (b) {
    b.addEventListener('click', async function () {
      var id = b.dataset.msg;
      var el = document.querySelector('.admin-banner[data-msg="' + id + '"]') || document.querySelector('.admin-popup[data-msg="' + id + '"]');
      try { await window.altFetch('api/messages.php', { json: { id: +id } }); } catch (e) {}
      if (el) { el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 200); }
    });
  });

  /* ---------- Confirmation de déconnexion ---------- */
  document.querySelectorAll('a.logout, a.logout-mobile').forEach((a) => {
    a.addEventListener('click', async (e) => {
      e.preventDefault();
      const ok = await window.altConfirm('Voulez-vous vraiment vous déconnecter ?', { title: 'Déconnexion', tone: 'danger', okText: 'Se déconnecter' });
      if (ok) window.location.href = a.href;
    });
  });

  /* ---------- Compteurs animés ---------- */
  window.countUp = function (el) {
    const target = parseFloat(el.dataset.count || el.textContent) || 0;
    const dur = 900, start = performance.now();
    const suffix = el.dataset.suffix || '';
    const dec = parseInt(el.dataset.decimals || '0', 10);
    function step(now) {
      const p = Math.min((now - start) / dur, 1);
      const eased = 1 - Math.pow(1 - p, 3);
      const val = target * eased;
      el.textContent = (dec > 0 ? val.toFixed(dec) : Math.round(val)).toLocaleString ?
        (dec > 0 ? val.toFixed(dec).replace('.', ',') : Math.round(val).toLocaleString('fr-FR')) + suffix
        : val + suffix;
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  };
  document.querySelectorAll('[data-count]').forEach((el) => window.countUp(el));

  /* ---------- Barres pipeline ---------- */
  requestAnimationFrame(() => {
    document.querySelectorAll('.pipe-fill').forEach((f) => { f.style.width = (f.dataset.w || 0) + '%'; });
  });

  /* ---------- Dropdowns génériques ---------- */
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-dropdown]');
    document.querySelectorAll('.dropdown-menu.open').forEach((m) => {
      if (!trigger || m !== document.getElementById(trigger.dataset.dropdown)) m.classList.remove('open');
    });
    if (trigger) {
      const menu = document.getElementById(trigger.dataset.dropdown);
      if (menu) menu.classList.toggle('open');
    }
    // Fermer le panneau notif si clic dehors
    const np = document.getElementById('notifPanel');
    if (np && !np.hidden && !e.target.closest('.notif-wrap')) np.hidden = true;
  });

  /* ---------- Modales ---------- */
  window.openModal = (id) => { const m = document.getElementById(id); if (m) m.classList.add('open'); };
  window.closeModal = (id) => { const m = document.getElementById(id); if (m) m.classList.remove('open'); };
  document.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal-scrim')) e.target.classList.remove('open');
    const c = e.target.closest('[data-close]');
    if (c) window.closeModal(c.dataset.close);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-scrim.open').forEach((m) => m.classList.remove('open'));
  });

  /* ---------- Notifications ---------- */
  const notifBtn = document.getElementById('notifBtn');
  const notifPanel = document.getElementById('notifPanel');
  const notifList = document.getElementById('notifList');
  const notifDot = document.getElementById('notifDot');

  async function loadNotifs() {
    try {
      const d = await window.altFetch('api/notifications.php?action=list');
      renderNotifs(d.items || []);
      updateDot(d.unread || 0);
    } catch (e) { if (notifList) notifList.innerHTML = '<div class="notif-empty">Impossible de charger.</div>'; }
  }
  function updateDot(n) {
    if (!notifDot) return;
    if (n > 0) { notifDot.textContent = n; notifDot.hidden = false; }
    else notifDot.hidden = true;
  }
  function renderNotifs(items) {
    if (!notifList) return;
    if (!items.length) { notifList.innerHTML = '<div class="notif-empty">Aucune notification pour le moment.</div>'; return; }
    notifList.innerHTML = items.map((n) => `
      <div class="notif-item ${n.is_read ? '' : 'unread'}" data-id="${n.id}" data-url="${n.url || ''}">
        <div class="notif-ic">${iconFor(n.type)}</div>
        <div class="notif-main"><h5>${esc(n.title)}</h5><p>${esc(n.body)}</p><time>${timeAgo(n.created_at)}</time></div>
        <button class="notif-del" data-id="${n.id}" title="Supprimer" aria-label="Supprimer">✕</button>
      </div>`).join('');
    notifList.querySelectorAll('.notif-del').forEach((btn) => {
      btn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const id = btn.dataset.id;
        try {
          const d = await window.altFetch('api/notifications.php?action=delete', { json: { id } });
          const row = btn.closest('.notif-item');
          if (row) row.remove();
          updateDot(d.unread || 0);
          if (!notifList.querySelector('.notif-item')) {
            notifList.innerHTML = '<div class="notif-empty">Aucune notification pour le moment.</div>';
          }
        } catch (err) { window.toast('Suppression impossible.', 'err'); }
      });
    });
    notifList.querySelectorAll('.notif-item').forEach((it) => {
      it.addEventListener('click', async () => {
        const id = it.dataset.id;
        await window.altFetch('api/notifications.php?action=read', { json: { id } });
        it.classList.remove('unread');
        const url = it.dataset.url;
        if (url) location.href = api(url);
        else loadNotifs();
      });
    });
  }
  function iconFor(t) {
    const map = {
      relance: '↻', entretien: '★', suivi: '⏱', objectif: '＋',
      brouillon: '✎', qualite: '✓', positif: '↗', ajout: '🏢', info: 'ℹ'
    };
    return `<span style="font-size:16px">${map[t] || 'ℹ'}</span>`;
  }
  if (notifBtn) {
    notifBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      notifPanel.hidden = !notifPanel.hidden;
      if (!notifPanel.hidden) loadNotifs();
    });
  }
  const markAll = document.getElementById('notifMarkAll');
  if (markAll) markAll.addEventListener('click', async () => {
    await window.altFetch('api/notifications.php?action=read_all', { method: 'POST' });
    loadNotifs();
  });
  const clearAll = document.getElementById('notifClearAll');
  if (clearAll) clearAll.addEventListener('click', async () => {
    if (!(await window.altConfirm('Effacer toutes les notifications ?', {title:'Tout effacer'}))) return;
    try {
      await window.altFetch('api/notifications.php?action=clear', { method: 'POST' });
      renderNotifs([]);
      updateDot(0);
      window.toast('Notifications effacées.', 'ok');
    } catch (e) { window.toast('Impossible d\'effacer.', 'err'); }
  });

  // Génère + rafraîchit les notifications (throttle serveur), et push navigateur
  async function pollNotifs(showPush) {
    try {
      const d = await window.altFetch('api/notifications.php?action=poll');
      updateDot(d.unread || 0);
      if (showPush && d.fresh && d.fresh.length && 'Notification' in window && Notification.permission === 'granted') {
        d.fresh.forEach((n) => showBrowserNotif(n));
      }
    } catch (e) {}
  }
  function showBrowserNotif(n) {
    const opts = { body: n.body, icon: api('assets/img/icon-192.png'), badge: api('assets/img/icon-192.png'), tag: 'alternis-' + n.group_key };
    if (navigator.serviceWorker && navigator.serviceWorker.ready) {
      navigator.serviceWorker.ready.then((reg) => reg.showNotification(n.title, opts)).catch(() => new Notification(n.title, opts));
    } else { try { new Notification(n.title, opts); } catch (e) {} }
  }
  pollNotifs(true);
  setInterval(() => pollNotifs(true), 90 * 1000);

  /* ---------- Demande d'autorisation des notifications ----------
     Affichée à la première ouverture, et tant que l'utilisateur n'a pas
     répondu (accepté ou bloqué). Le clic sert de « geste utilisateur »
     requis par les navigateurs (Safari/iOS notamment). */
  function maybeAskNotifPermission() {
    if (!('Notification' in window)) return;                 // non supporté
    if (Notification.permission !== 'default') return;       // déjà accepté ou refusé
    try { if (sessionStorage.getItem('alt_notif_later')) return; } catch (e) {}

    const bar = document.createElement('div');
    bar.className = 'notif-optin';
    bar.innerHTML =
      '<div class="no-ic"><svg viewBox="0 0 24 24" width="22" height="22" fill="none"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>' +
      '<div class="no-txt"><strong>Activer les notifications ?</strong><span>Recevez vos rappels et l\'activité du suivi, même en dehors de l\'app.</span></div>' +
      '<div class="no-act"><button class="btn btn-ghost btn-sm" id="noLater">Plus tard</button><button class="btn btn-primary btn-sm" id="noEnable">Activer</button></div>';
    document.body.appendChild(bar);
    requestAnimationFrame(() => bar.classList.add('show'));

    document.getElementById('noLater').onclick = () => {
      try { sessionStorage.setItem('alt_notif_later', '1'); } catch (e) {}
      bar.classList.remove('show'); setTimeout(() => bar.remove(), 250);
    };
    document.getElementById('noEnable').onclick = async () => {
      try {
        const p = await Notification.requestPermission();
        if (p === 'granted') {
          window.toast('Notifications activées.', 'ok');
          pollNotifs(true);
          if (window.altEnablePush) window.altEnablePush();   // abonnement push
        } else {
          window.toast('Notifications non autorisées. Vous pourrez les activer dans les réglages du navigateur.', 'err');
        }
      } catch (e) {}
      bar.classList.remove('show'); setTimeout(() => bar.remove(), 250);
    };
  }
  setTimeout(maybeAskNotifPermission, 1500);

  /* ---------- PWA / Service Worker + abonnement Web Push ----------
     Le sondage ci-dessus ne fonctionne que si un onglet est ouvert.
     Le Web Push, lui, atteint l'appareil même application fermée. */
  function urlB64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }

  async function ensurePushSubscription(reg, loud) {
    try {
      if (!('PushManager' in window)) throw { error: 'Ce navigateur ne gère pas le Web Push.' };
      if (Notification.permission !== 'granted') throw { error: 'Notifications non autorisées.' };

      const kd = await fetch(api('api/push.php?action=key')).then((r) => r.json());
      if (!kd || !kd.key) throw { error: 'Le serveur n\'a pas de clé VAPID configurée.' };

      let sub = await reg.pushManager.getSubscription();
      if (sub) {
        // Si la clé serveur a changé, on se réabonne proprement
        const cur = sub.options && sub.options.applicationServerKey;
        const same = cur && new Uint8Array(cur).toString() === urlB64ToUint8Array(kd.key).toString();
        if (!same) { try { await sub.unsubscribe(); } catch (e) {} sub = null; }
      }
      if (!sub) {
        sub = await reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlB64ToUint8Array(kd.key)
        });
      }
      // L'enregistrement serveur est renvoyé à chaque fois : c'est lui qui
      // garantit que la base connaît réellement cet appareil.
      await window.altFetch('api/push.php?action=subscribe', { json: sub.toJSON() });
      return { ok: true };
    } catch (e) {
      if (loud) throw e;            // appel explicite : on veut voir l'erreur
      return { ok: false, error: (e && e.error) || 'Abonnement impossible.' };
    }
  }

  let swReg = null;
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(api('sw.js'))
      .then((reg) => { swReg = reg; return navigator.serviceWorker.ready; })
      .then((reg) => ensurePushSubscription(reg))
      .catch(() => {});
  }
  window.altEnablePush = async function () {
    const reg = await navigator.serviceWorker.ready;
    return ensurePushSubscription(reg, true);   // remonte l'erreur à l'appelant
  };

  /* ---------- Utilitaires ---------- */
  function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function timeAgo(dt) {
    const d = new Date(dt.replace(' ', 'T')), s = (Date.now() - d) / 1000;
    if (s < 60) return "à l'instant";
    if (s < 3600) return Math.floor(s / 60) + ' min';
    if (s < 86400) return Math.floor(s / 3600) + ' h';
    return d.toLocaleDateString('fr-FR');
  }
  window.altEsc = esc;
  window.altApi = api;

  /* ---------- Effets sonores ---------- */
  let _actx = null;
  function tone(freqs, dur, type, vol) {
    try {
      _actx = _actx || new (window.AudioContext || window.webkitAudioContext)();
      const now = _actx.currentTime;
      freqs.forEach((f, i) => {
        const o = _actx.createOscillator(), g = _actx.createGain();
        o.type = type || 'sine'; o.frequency.value = f;
        const t = now + i * 0.07;
        g.gain.setValueAtTime(0, t);
        g.gain.linearRampToValueAtTime(vol || 0.14, t + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t + (dur || 0.18));
        o.connect(g); g.connect(_actx.destination);
        o.start(t); o.stop(t + (dur || 0.18));
      });
    } catch (e) {}
  }
  window.altSound = function (kind) {
    try { if (localStorage.getItem('alt_sound') === 'off') return; } catch (e) {}
    if (kind === 'add' || kind === 'success') tone([587.33, 880], 0.18, 'sine', 0.14);
    else if (kind === 'delete') tone([330, 220], 0.16, 'triangle', 0.12);
    else if (kind === 'error') tone([220, 175], 0.22, 'sawtooth', 0.10);
    else tone([660], 0.12, 'sine', 0.10);
  };
  // Son automatique sur les toasts de succès/erreur
  const _toast = window.toast;
  window.toast = function (msg, type) {
    if (type === 'ok') window.altSound('success');
    else if (type === 'err') window.altSound('error');
    return _toast(msg, type);
  };

  /* ---------- Pop-ups personnalisés (remplacent confirm/alert/prompt) ---------- */
  function dialog(opts) {
    return new Promise((resolve) => {
      const scrim = document.createElement('div');
      scrim.className = 'dlg-scrim';
      const hasInput = opts.prompt !== undefined;
      scrim.innerHTML =
        '<div class="dlg" role="dialog" aria-modal="true">' +
          (opts.icon ? `<div class="dlg-ic dlg-${opts.tone || 'info'}">${opts.icon}</div>` : '') +
          `<h3>${esc(opts.title || '')}</h3>` +
          (opts.message ? `<p>${esc(opts.message)}</p>` : '') +
          (hasInput ? `<input class="dlg-input" type="text" value="${esc(opts.prompt || '')}" placeholder="${esc(opts.placeholder || '')}">` : '') +
          '<div class="dlg-actions">' +
            (opts.cancel !== false ? `<button class="btn btn-ghost dlg-cancel">${esc(opts.cancelText || 'Annuler')}</button>` : '') +
            `<button class="btn ${opts.tone === 'danger' ? 'btn-danger' : 'btn-primary'} dlg-ok">${esc(opts.okText || 'Confirmer')}</button>` +
          '</div>' +
        '</div>';
      document.body.appendChild(scrim);
      requestAnimationFrame(() => scrim.classList.add('show'));
      const input = scrim.querySelector('.dlg-input');
      if (input) setTimeout(() => { input.focus(); input.select(); }, 60);
      const close = (val) => { scrim.classList.remove('show'); setTimeout(() => scrim.remove(), 200); resolve(val); };
      const ok = scrim.querySelector('.dlg-ok');
      const cancel = scrim.querySelector('.dlg-cancel');
      ok.onclick = () => close(hasInput ? (input.value) : true);
      if (cancel) cancel.onclick = () => close(hasInput ? null : false);
      scrim.addEventListener('mousedown', (e) => { if (e.target === scrim) close(hasInput ? null : false); });
      scrim.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(hasInput ? null : false); if (e.key === 'Enter' && (hasInput || document.activeElement === ok)) close(hasInput ? input.value : true); });
    });
  }
  const iInfo = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 8h.01M11 12h1v4h1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  const iWarn = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none"><path d="M12 3 2 20h20L12 3z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 10v4m0 3h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
  window.altConfirm = (message, o = {}) => dialog(Object.assign({ title: o.title || 'Confirmation', message, icon: iWarn, tone: o.tone || 'danger', okText: o.okText || 'Confirmer' }, o));
  window.altAlert = (message, o = {}) => dialog(Object.assign({ title: o.title || 'Information', message, icon: iInfo, tone: 'info', cancel: false, okText: 'OK' }, o));
  window.altPrompt = (message, def = '', o = {}) => dialog(Object.assign({ title: o.title || '', message, prompt: def, icon: null, okText: o.okText || 'Valider' }, o));

  /* ---------- PDF personnalisé (choix des statuts) ---------- */
  const pdfBtn = document.getElementById('pdfCustomBtn');
  if (pdfBtn) {
    pdfBtn.addEventListener('click', () => { if (window.openModal) window.openModal('pdfCustomModal'); });
    // Boutons « tout cocher / décocher »
    document.querySelectorAll('[data-toggle-all]').forEach((b) => {
      b.addEventListener('click', () => {
        const boxes = document.querySelectorAll('.' + b.dataset.toggleAll);
        const allOn = [...boxes].every((c) => c.checked);
        boxes.forEach((c) => { c.checked = !allOn; });
      });
    });

    const go = document.getElementById('pdfCustomGo');
    if (go) go.addEventListener('click', () => {
      const sts = [...document.querySelectorAll('.pdf-st:checked')].map((c) => c.value);
      const fds = [...document.querySelectorAll('.pdf-fd:checked')].map((c) => c.value);
      if (!sts.length) { window.toast('Cochez au moins un statut.', 'err'); return; }
      if (!fds.length) { window.toast('Cochez au moins une colonne.', 'err'); return; }
      if (window.closeModal) window.closeModal('pdfCustomModal');
      const q = 'export/pdf.php?scope=full&statuses=' + sts.join(',') + '&fields=' + fds.join(',');
      window.open(api(q), '_blank');
    });
  }

  /* ---------- Révélation au scroll ---------- */
  const io = new IntersectionObserver((es) => {
    es.forEach((en) => { if (en.isIntersecting) { en.target.classList.add('fade-up'); io.unobserve(en.target); } });
  }, { threshold: .08 });
  document.querySelectorAll('[data-reveal]').forEach((el) => io.observe(el));

  /* ---------- Animation du titre de l'onglet ----------
     Fait défiler les rappels utiles (notifications, relances, offres à faire)
     en alternance avec le vrai titre. Se met en pause quand l'onglet est actif
     (inutile d'alerter quelqu'un qui regarde déjà la page) et reprend quand il
     passe en arrière-plan. */
  (function titleTicker() {
    const A = window.ALT || {};
    const realTitle = A.title || document.title;
    const c = A.counters || {};

    // Construit la liste des messages selon les compteurs non nuls.
    function buildMessages() {
      const out = [];
      const plur = (n, s, p) => n + ' ' + (n > 1 ? (p || s + 's') : s);
      if (c.notifications > 0) out.push(plur(c.notifications, 'nouvelle notification', 'nouvelles notifications'));
      if (c.followups > 0)     out.push(plur(c.followups, 'relance à effectuer', 'relances à effectuer'));
      if (c.todo > 0)          out.push(plur(c.todo, 'offre à faire', 'offres à faire'));
      return out;
    }

    const messages = buildMessages();
    if (!messages.length) { document.title = realTitle; return; }

    // Séquence : msg1 → vrai titre → msg2 → vrai titre → …
    const seq = [];
    messages.forEach((m) => { seq.push(A.appName + ' — ' + m); seq.push(realTitle); });

    let i = 0, timer = null;
    function step() {
      document.title = seq[i];
      i = (i + 1) % seq.length;
    }
    function start() {
      if (timer) return;
      step();
      timer = setInterval(step, 2200);
    }
    function stop() {
      if (timer) { clearInterval(timer); timer = null; }
      document.title = realTitle;
    }

    // Actif seulement quand l'onglet n'est pas au premier plan.
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) start(); else stop();
    });
    // Si la page se charge déjà en arrière-plan, on démarre.
    if (document.hidden) start();
  })();
})();
