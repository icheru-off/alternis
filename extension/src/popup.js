/**
 * Logique du popup Alternis.
 * - stocke le jeton et l'URL du site dans le stockage local de l'extension ;
 * - se connecte via un mot de passe d'application (api/ext.php?action=login) ;
 * - extrait les infos de l'onglet actif (content script) ;
 * - enregistre la candidature (api/ext.php?action=capture).
 */
(function () {
  'use strict';
  const api = (typeof browser !== 'undefined') ? browser : chrome;
  const DEFAULT_BASE = 'https://alternis.fixit-service.fr';

  const $ = (id) => document.getElementById(id);
  const store = {
    get: (keys) => new Promise((res) => api.storage.local.get(keys, res)),
    set: (obj) => new Promise((res) => api.storage.local.set(obj, res)),
    remove: (keys) => new Promise((res) => api.storage.local.remove(keys, res)),
  };

  let state = { base: DEFAULT_BASE, token: '', user: null, meta: null, detected: null };

  function showErr(el, msg) { el.textContent = msg; el.hidden = false; }
  function hide(el) { el.hidden = true; }

  function apiUrl(path) {
    let b = (state.base || DEFAULT_BASE).replace(/\/+$/, '');
    return b + '/' + path.replace(/^\/+/, '');
  }

  async function call(path, opts) {
    opts = opts || {};
    const headers = { 'Content-Type': 'application/json' };
    if (state.token) headers['Authorization'] = 'Bearer ' + state.token;
    const r = await fetch(apiUrl(path), {
      method: opts.method || 'GET',
      headers: headers,
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    let data = {};
    try { data = await r.json(); } catch (e) { data = {}; }
    if (!r.ok) throw (data && data.error ? data : { error: 'Erreur ' + r.status });
    return data;
  }

  /* ---------- Vues ---------- */
  function showLogin() {
    $('viewLogin').hidden = false;
    $('viewCapture').hidden = true;
    $('logoutBtn').hidden = true;
    $('baseUrl').value = state.base || DEFAULT_BASE;
  }
  function showCapture() {
    $('viewLogin').hidden = true;
    $('viewCapture').hidden = false;
    $('logoutBtn').hidden = false;
    $('whoName').textContent = (state.user && state.user.name) || '';
  }

  /* ---------- Connexion ---------- */
  $('loginBtn').addEventListener('click', async () => {
    hide($('loginErr'));
    const base = ($('baseUrl').value || DEFAULT_BASE).trim();
    const identifier = $('identifier').value.trim();
    const appPassword = $('appPassword').value.trim();
    if (!identifier || !appPassword) { showErr($('loginErr'), 'Remplissez tous les champs.'); return; }
    state.base = base;
    $('loginBtn').disabled = true;
    try {
      const d = await call('api/ext.php?action=login', { method: 'POST', body: { identifier, app_password: appPassword } });
      state.token = d.token;
      state.user = d.user;
      await store.set({ base: state.base, token: state.token, user: state.user });
      await loadMeta();
      showCapture();
      await runExtract();
    } catch (e) {
      showErr($('loginErr'), (e && e.error) || 'Connexion impossible.');
    } finally {
      $('loginBtn').disabled = false;
    }
  });

  $('logoutBtn').addEventListener('click', async () => {
    try { await call('api/ext.php?action=logout', { method: 'POST' }); } catch (e) {}
    state.token = ''; state.user = null;
    await store.remove(['token', 'user']);
    showLogin();
  });

  /* ---------- Métadonnées (statuts, types) ---------- */
  async function loadMeta() {
    try {
      const m = await call('api/ext.php?action=meta');
      state.meta = m;
      const st = $('fStatus'); st.innerHTML = '';
      Object.keys(m.statuses || {}).forEach((k) => {
        const o = document.createElement('option');
        o.value = k; o.textContent = m.statuses[k];
        if (k === 'envoye') o.selected = true;
        st.appendChild(o);
      });
      const ch = $('fChannel'); ch.innerHTML = '<option value="">—</option>';
      (m.channels || []).forEach((c) => {
        const o = document.createElement('option'); o.value = c; o.textContent = c; ch.appendChild(o);
      });
    } catch (e) { /* silencieux */ }
  }

  /* ---------- Extraction depuis l'onglet actif ---------- */
  function activeTab() {
    return new Promise((res) => api.tabs.query({ active: true, currentWindow: true }, (tabs) => res(tabs && tabs[0])));
  }

  function askContent(tabId) {
    return new Promise((res) => {
      let done = false;
      try {
        api.tabs.sendMessage(tabId, { type: 'ALTERNIS_EXTRACT' }, (resp) => {
          done = true;
          if (api.runtime.lastError) { res(null); return; }
          res(resp && resp.data ? resp.data : null);
        });
      } catch (e) { res(null); }
      // Filet de sécurité si le content script n'est pas injecté
      setTimeout(() => { if (!done) res(null); }, 800);
    });
  }

  async function runExtract() {
    const tab = await activeTab();
    if (!tab) return;
    let data = await askContent(tab.id);

    // Si le content script n'a pas répondu (page ouverte avant l'install),
    // on injecte les scripts à la volée puis on redemande.
    if (!data && api.scripting && tab.id) {
      try {
        await api.scripting.executeScript({ target: { tabId: tab.id }, files: ['parsers/parsers.js', 'content.js'] });
        data = await askContent(tab.id);
      } catch (e) { /* certaines pages (chrome://) sont interdites */ }
    }
    state.detected = data || {};
    fillForm(state.detected);
  }

  function fillForm(d) {
    $('fName').value = d.name || '';
    $('fPosition').value = d.position || '';
    $('fCity').value = d.city || '';
    $('fSector').value = d.sector || '';
    $('fWebsite').value = d.website || '';
    $('fDate').value = today();
    $('srcHint').textContent = d.source_url ? ('Source : ' + d.source_url) : '';

    const badge = $('detectedBadge');
    if (d.supported) {
      badge.textContent = 'Site reconnu — informations pré-remplies. Vérifiez avant d\'enregistrer.';
      badge.className = 'detected show';
    } else if (d.name) {
      badge.textContent = 'Site non répertorié : informations détectées automatiquement. Vérifiez-les.';
      badge.className = 'detected show generic';
    } else {
      badge.className = 'detected';
    }
    // Type par défaut selon la source détectée
    guessChannel(d.source);
  }

  function guessChannel(source) {
    const map = {
      linkedin: 'LinkedIn', indeed: 'Indeed', wttj: 'Welcome to the Jungle',
      hellowork: 'HelloWork', francetravail: 'France Travail', apec: 'APEC',
      jobteaser: 'Jobteaser', monster: 'Monster', glassdoor: 'Glassdoor',
      jooble: 'Jooble', lba: 'La Bonne Alternance', meta: 'Site de l\'entreprise',
      jsonld: 'Site de l\'entreprise',
    };
    const want = map[source];
    if (!want) return;
    const sel = $('fChannel');
    for (const o of sel.options) { if (o.value === want) { sel.value = want; break; } }
  }

  function today() {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  }

  /* ---------- Enregistrement ---------- */
  function collect() {
    return {
      name: $('fName').value.trim(),
      position: $('fPosition').value.trim(),
      city: $('fCity').value.trim(),
      sector: $('fSector').value.trim(),
      website: $('fWebsite').value.trim(),
      apply_channel: $('fChannel').value,
      status: $('fStatus').value,
      applied_date: $('fDate').value,
      notes: $('fNotes').value.trim(),
      source_url: (state.detected && state.detected.source_url) || '',
    };
  }

  async function save(forceStatus) {
    hide($('captureErr')); hide($('captureOk'));
    const payload = collect();
    if (forceStatus) payload.status = forceStatus;
    if (!payload.name) { showErr($('captureErr'), 'Le nom de l\'entreprise est requis.'); return false; }
    try {
      const d = await call('api/ext.php?action=capture', { method: 'POST', body: payload });
      const dest = forceStatus === 'a_postuler' ? 'vos offres à faire' : 'vos candidatures';
      $('captureOk').textContent = '« ' + d.name + ' » ajouté à ' + dest + '.';
      $('captureOk').hidden = false;
      return true;
    } catch (e) {
      if (e && /jeton/i.test(e.error || '')) { showLogin(); return false; }
      showErr($('captureErr'), (e && e.error) || 'Enregistrement impossible.');
      return false;
    }
  }

  // Bouton « Offres à faire » : ajoute avec le statut « à postuler »
  const todoBtn = $('todoBtn');
  if (todoBtn) todoBtn.addEventListener('click', async () => {
    todoBtn.disabled = true;
    const ok = await save('a_postuler');
    todoBtn.disabled = false;
    if (ok) setTimeout(() => window.close(), 900);
  });

  // Bouton candidature : enregistre comme candidature envoyée et ferme
  $('quickBtn').addEventListener('click', async () => {
    $('quickBtn').disabled = true;
    const ok = await save('envoye');
    $('quickBtn').disabled = false;
    if (ok) setTimeout(() => window.close(), 900);
  });

  // Bouton éditer : déplie les détails, met le focus (pas de fermeture auto)
  $('editBtn').addEventListener('click', () => {
    $('moreWrap').open = true;
    $('fName').focus();
    $('captureOk').hidden = true;
  });

  // Toggle « enregistrement automatique »
  const autoBox = $('autoCapture');
  if (autoBox) autoBox.addEventListener('change', async () => {
    await store.set({ autoCapture: autoBox.checked });
  });
  const showBtnBox = $('showButtons');
  if (showBtnBox) showBtnBox.addEventListener('change', async () => {
    await store.set({ showButtons: showBtnBox.checked });
  });

  /* ---------- Démarrage ---------- */
  (async function init() {
    const saved = await store.get(['base', 'token', 'user', 'autoCapture', 'showButtons']);
    state.base = saved.base || DEFAULT_BASE;
    state.token = saved.token || '';
    state.user = saved.user || null;
    const ab = $('autoCapture');
    if (ab) ab.checked = saved.autoCapture !== false;
    const sb = $('showButtons');
    if (sb) sb.checked = saved.showButtons !== false;

    if (state.token) {
      // Vérifie que le jeton est encore valide
      try {
        await call('api/ext.php?action=me');
        await loadMeta();
        showCapture();
        await runExtract();
        return;
      } catch (e) {
        await store.remove(['token', 'user']);
        state.token = '';
      }
    }
    showLogin();
  })();
})();
