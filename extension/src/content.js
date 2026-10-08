/**
 * Content script Alternis.
 *  - répond aux demandes d'extraction du popup (capture manuelle) ;
 *  - surveille la page pour détecter automatiquement l'envoi d'une candidature
 *    sur les plateformes reconnues, et l'enregistre alors dans Alternis avec
 *    une notification visuelle (succès / erreur).
 */
(function () {
  'use strict';
  const api = (typeof browser !== 'undefined') ? browser : chrome;

  /* ---------- Réponse aux demandes du popup ---------- */
  api.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (msg && msg.type === 'ALTERNIS_EXTRACT') {
      let data = {};
      try { data = (typeof window.__alternisExtract === 'function') ? window.__alternisExtract() : {}; }
      catch (e) { data = { error: String(e) }; }
      sendResponse({ ok: true, data: data });
    }
    return true;
  });

  /* ---------- Accès au stockage (jeton + réglages) ---------- */
  function getConfig() {
    return new Promise((res) => {
      api.storage.local.get(['base', 'token', 'autoCapture', 'showButtons'], (d) => res(d || {}));
    });
  }

  async function apiCall(path, body) {
    const cfg = await getConfig();
    if (!cfg.token) return { error: 'not_logged_in' };
    const base = (cfg.base || 'https://alternis.fixit-service.fr').replace(/\/+$/, '');
    try {
      const r = await fetch(base + '/' + path.replace(/^\/+/, ''), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + cfg.token },
        body: JSON.stringify(body),
      });
      const data = await r.json().catch(() => ({}));
      if (!r.ok) return { error: (data && data.error) || ('http_' + r.status) };
      return data;
    } catch (e) { return { error: String(e) }; }
  }

  /* ---------- Notification visuelle in-page ---------- */
  function toast(kind, text) {
    const wrap = document.createElement('div');
    wrap.setAttribute('data-alternis-toast', '1');
    wrap.style.cssText = [
      'position:fixed', 'z-index:2147483647', 'right:18px', 'bottom:18px',
      'max-width:340px', 'padding:13px 16px', 'border-radius:12px',
      'font:600 14px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif',
      'color:#fff', 'box-shadow:0 10px 30px rgba(0,0,0,.25)',
      'display:flex', 'align-items:center', 'gap:10px',
      'background:' + (kind === 'ok' ? 'linear-gradient(135deg,#7C5CFC,#22D3EE)'
        : kind === 'err' ? '#dc2626' : '#334155'),
      'opacity:0', 'transform:translateY(8px)', 'transition:all .25s ease',
    ].join(';');
    const dot = document.createElement('span');
    dot.textContent = kind === 'ok' ? '\u2713' : kind === 'err' ? '!' : '\u2022';
    dot.style.cssText = 'font-size:16px;font-weight:800';
    const span = document.createElement('span');
    span.textContent = text;
    wrap.appendChild(dot); wrap.appendChild(span);
    document.body.appendChild(wrap);
    requestAnimationFrame(() => { wrap.style.opacity = '1'; wrap.style.transform = 'translateY(0)'; });
    setTimeout(() => { wrap.style.opacity = '0'; wrap.style.transform = 'translateY(8px)'; setTimeout(() => wrap.remove(), 300); }, 4200);
  }

  /* ---------- Détection automatique de l'envoi de candidature ---------- */
  const capturedKey = 'alternis_captured_' + location.pathname;
  function alreadyCaptured() {
    try { return sessionStorage.getItem(capturedKey) === '1'; } catch (e) { return false; }
  }
  function markCaptured() {
    try { sessionStorage.setItem(capturedKey, '1'); } catch (e) {}
  }

  async function autoCapture(reason) {
    if (alreadyCaptured()) return;
    const cfg = await getConfig();
    if (!cfg.token) return;
    if (cfg.autoCapture === false) return;

    let data = {};
    try { data = window.__alternisExtract ? window.__alternisExtract() : {}; } catch (e) {}
    if (!data.name) return;

    markCaptured();
    const payload = {
      name: data.name, position: data.position, city: data.city,
      sector: data.sector || '', website: data.website || '',
      apply_channel: channelFromSource(data.source),
      status: 'envoye',
      applied_date: today(),
      source_url: data.source_url || location.href,
      notes: 'Ajout\u00e9 automatiquement par l\'extension (' + reason + ').',
    };
    const res = await apiCall('api/ext.php?action=capture', payload);
    if (res && res.ok) toast('ok', 'Candidature enregistr\u00e9e dans Alternis : ' + res.name);
    else if (res && res.error === 'not_logged_in') toast('info', 'Connectez l\'extension Alternis pour enregistrer automatiquement.');
    else toast('err', 'Alternis : \u00e9chec de l\'enregistrement automatique.');
  }

  function channelFromSource(src) {
    const map = {
      linkedin: 'LinkedIn', indeed: 'Indeed', wttj: 'Welcome to the Jungle',
      hellowork: 'HelloWork', francetravail: 'France Travail', apec: 'APEC',
      jobteaser: 'Jobteaser', monster: 'Monster', glassdoor: 'Glassdoor',
      jooble: 'Jooble', lba: 'La Bonne Alternance',
    };
    return map[src] || '';
  }
  function today() {
    const d = new Date(), p = (n) => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  }

  const SUCCESS_PATTERNS = [
    /candidature\s+(envoy[\u00e9e]e?|transmise|bien re[\u00e7c]ue|prise en compte)/i,
    /votre\s+candidature\s+a\s+bien/i,
    /merci\s+pour\s+votre\s+candidature/i,
    /application\s+(was\s+)?(sent|submitted|received)/i,
    /(successfully\s+)?applied/i,
    /your\s+application\s+(has\s+been|was)/i,
    /candidature\s+enregistr[\u00e9e]e?/i,
  ];
  function textLooksLikeSuccess(text) {
    if (!text) return false;
    return SUCCESS_PATTERNS.some((re) => re.test(text));
  }

  const APPLY_TEXT = /(postuler|envoyer\s+ma\s+candidature|je\s+postule|apply|send\s+application|candidater)/i;
  function watchApplyButtons() {
    document.addEventListener('click', (e) => {
      const el = e.target && e.target.closest ? e.target.closest('a,button,input[type="submit"]') : null;
      if (!el) return;
      const label = (el.textContent || el.value || el.getAttribute('aria-label') || '').trim();
      if (APPLY_TEXT.test(label)) armSuccessObserver();
    }, true);
  }

  let successObserver = null, observerArmedUntil = 0;
  function armSuccessObserver() {
    observerArmedUntil = Date.now() + 60000;
    if (successObserver) return;
    successObserver = new MutationObserver((mutations) => {
      if (Date.now() > observerArmedUntil) return;
      for (const m of mutations) {
        for (const node of m.addedNodes) {
          if (node.nodeType === 1) {
            const t = (node.innerText || node.textContent || '').slice(0, 400);
            if (textLooksLikeSuccess(t)) { autoCapture('confirmation d\u00e9tect\u00e9e'); return; }
          }
        }
      }
    });
    successObserver.observe(document.body, { childList: true, subtree: true });
  }

  function checkConfirmationPage() {
    const url = location.href.toLowerCase();
    const body = (document.body && document.body.innerText || '').slice(0, 3000);
    // Au chargement, on n'accepte QUE des messages de succès explicites, pour
    // éviter les faux positifs sur une page d'offre classique.
    const urlLooksConfirm = /(candidature-envoyee|application-sent|confirmation|merci|thank|postulation)/.test(url);
    if (textLooksLikeSuccess(body)) {
      autoCapture(urlLooksConfirm ? 'page de confirmation' : 'message de confirmation');
    }
  }

  /* ---------- Boutons flottants sur les pages d'offres ---------- */
  async function captureFromPage(status, btn) {
    let data = {};
    try { data = window.__alternisExtract ? window.__alternisExtract() : {}; } catch (e) {}
    if (!data.name) { toast('err', 'Alternis : impossible de lire cette offre.'); return; }
    if (btn) { btn.disabled = true; btn.style.opacity = '.6'; }
    const payload = {
      name: data.name, position: data.position, city: data.city,
      sector: data.sector || '', website: data.website || '',
      apply_channel: channelFromSource(data.source),
      source_url: data.source_url || location.href,
      status: status,
      applied_date: status === 'envoye' ? today() : null,
    };
    const res = await apiCall('api/ext.php?action=capture', payload);
    if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
    if (res && res.ok) {
      if (status === 'envoye') markCaptured();
      toast('ok', '« ' + (res.name || data.name) + ' » ' +
        (status === 'envoye' ? 'ajouté à vos candidatures.' : 'ajouté aux offres à faire.'));
    } else if (res && res.error === 'not_logged_in') {
      toast('info', 'Connectez-vous d’abord dans l’extension Alternis.');
    } else {
      toast('err', 'Alternis : échec de l’enregistrement.');
    }
  }

  function injectButtons() {
    if (document.getElementById('alternis-fab')) return;
    const wrap = document.createElement('div');
    wrap.id = 'alternis-fab';
    wrap.style.cssText = 'position:fixed;right:18px;bottom:110px;z-index:2147483000;display:flex;flex-direction:column;align-items:flex-end;gap:8px;';
    function mkBtn(label, css, onClick) {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = label;
      b.style.cssText = 'all:initial;cursor:pointer;box-sizing:border-box;border-radius:999px;padding:10px 16px;' +
        'font:700 13px/1.2 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;' +
        'box-shadow:0 8px 24px rgba(15,23,42,.22);transition:transform .15s ease;' + css;
      b.addEventListener('mouseenter', () => { b.style.transform = 'translateY(-1px)'; });
      b.addEventListener('mouseleave', () => { b.style.transform = 'none'; });
      b.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); onClick(b); });
      return b;
    }
    wrap.appendChild(mkBtn('✓ Ajouter en candidature',
      'color:#fff;background:linear-gradient(135deg,#7c5cfc,#22d3ee);',
      (b) => captureFromPage('envoye', b)));
    wrap.appendChild(mkBtn('＋ Ajouter aux offres à faire',
      'color:#5b3fd9;background:#fff;border:1.5px solid #c9bdff;',
      (b) => captureFromPage('a_postuler', b)));
    wrap.appendChild(mkBtn('× Masquer',
      'color:#64748b;background:rgba(255,255,255,.92);padding:6px 12px;font-size:11px;',
      () => wrap.remove()));
    document.body.appendChild(wrap);
  }

  getConfig().then((cfg) => {
    if (!cfg.token) return;
    if (cfg.showButtons !== false) {
      const tryInject = () => {
        let data = {};
        try { data = window.__alternisExtract ? window.__alternisExtract() : {}; } catch (e) {}
        if (data && data.name) injectButtons();
      };
      setTimeout(tryInject, 1200);
      setTimeout(tryInject, 3000);
    }
    if (cfg.autoCapture === false) return;
    watchApplyButtons();
    setTimeout(checkConfirmationPage, 1500);
  });
})();
