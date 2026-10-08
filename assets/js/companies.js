/* Page Entreprises — liste, filtres avancés, CRUD, import, logos, e-mails */
(function () {
  const esc = window.altEsc, api = window.altApi;
  const body = document.getElementById('compBody');
  let all = [], term = '';
  let page = 1, perPage = 25;
  const selected = new Set();
  const adv = { priority: '', channel: '', sector: '', city: '', from: '', to: '', responded: '', sort: 'recent' };

  const cap = (s) => (s || '').charAt(0).toUpperCase() + (s || '').slice(1);
  const fmt = (d) => d ? new Date(d).toLocaleDateString('fr-FR') : '';

  /* ---------- Logo d'entreprise depuis le site web ---------- */
  function domainOf(url) {
    if (!url) return '';
    try { let u = url.trim(); if (!/^https?:\/\//i.test(u)) u = 'https://' + u; return new URL(u).hostname.replace(/^www\./, ''); }
    catch (e) { return ''; }
  }
  function logoHtml(c) {
    const d = domainOf(c.website);
    const initials = esc((c.name || '?').trim().charAt(0).toUpperCase());
    if (!d) return `<span class="clogo"><em>${initials}</em></span>`;
    let cached = null; try { cached = localStorage.getItem('alt_logo:' + d); } catch (e) {}
    if (cached === 'n') return `<span class="clogo"><em>${initials}</em></span>`;
    const clearbit = `https://logo.clearbit.com/${encodeURIComponent(d)}?size=80`;
    const fav = `https://www.google.com/s2/favicons?domain=${encodeURIComponent(d)}&sz=64`;
    const primary = cached === 'f' ? fav : clearbit;
    // Mémorise l'issue (c/f/n) pour éviter de re-télécharger inutilement aux visites suivantes.
    const onload = `try{localStorage.setItem('alt_logo:${d}',this.src.indexOf('clearbit')>-1?'c':'f')}catch(e){}`;
    const onerr = `if(!this.dataset.f){this.dataset.f=1;this.src='${fav}'}else{try{localStorage.setItem('alt_logo:${d}','n')}catch(e){}this.remove()}`;
    return `<span class="clogo"><em>${initials}</em><img src="${primary}" alt="" loading="lazy" onload="${onload}" onerror="${onerr}"></span>`;
  }

  async function load() {
    try {
      const d = await window.altFetch('api/companies.php?action=list');
      all = d.items || [];
      render();
    } catch (e) { body.innerHTML = row('Erreur de chargement.'); }
  }
  function row(msg) { return `<tr><td colspan="8" style="text-align:center;padding:40px" class="muted">${msg}</td></tr>`; }

  const isResponded = (c) => ['repondu', 'entretien', 'accepte', 'refuse'].includes(c.status) || !!c.response_date;

  function applyAdvanced(list) {
    if (adv.priority) list = list.filter((c) => c.priority === adv.priority);
    if (adv.channel) list = list.filter((c) => (c.apply_channel || '') === adv.channel);
    if (adv.sector) { const t = adv.sector.toLowerCase(); list = list.filter((c) => (c.sector || '').toLowerCase().includes(t)); }
    if (adv.city) { const t = adv.city.toLowerCase(); list = list.filter((c) => (c.city || '').toLowerCase().includes(t)); }
    if (adv.from) list = list.filter((c) => c.applied_date && c.applied_date >= adv.from);
    if (adv.to) list = list.filter((c) => c.applied_date && c.applied_date <= adv.to);
    if (adv.responded === 'yes') list = list.filter(isResponded);
    if (adv.responded === 'no') list = list.filter((c) => !isResponded(c));
    const dateKey = (c) => c.applied_date || c.created_at || '';
    const prioRank = { haute: 0, normale: 1, basse: 2 };
    if (adv.sort === 'recent') list.sort((a, b) => dateKey(b).localeCompare(dateKey(a)));
    else if (adv.sort === 'old') list.sort((a, b) => dateKey(a).localeCompare(dateKey(b)));
    else if (adv.sort === 'name') list.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
    else if (adv.sort === 'priority') list.sort((a, b) => (prioRank[a.priority] ?? 1) - (prioRank[b.priority] ?? 1));
    return list;
  }

  function activeAdvCount() {
    let n = 0;
    ['priority', 'channel', 'sector', 'city', 'from', 'to', 'responded'].forEach((k) => { if (adv[k]) n++; });
    return n;
  }

  /* ---------- Pagination ---------- */
  function pageNumbers(cur, total) {
    if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);
    if (cur <= 4) return [1, 2, 3, 4, 5, '…', total];
    if (cur >= total - 3) return [1, '…', total - 4, total - 3, total - 2, total - 1, total];
    return [1, '…', cur - 1, cur, cur + 1, '…', total];
  }

  function renderPager(total, pages, start) {
    const pager = document.getElementById('pager');
    if (!pager) return;
    if (!total) { pager.hidden = true; return; }
    pager.hidden = total <= perPage && pages <= 1;

    const end = Math.min(start + perPage, total);
    document.getElementById('pagerInfo').textContent =
      total <= perPage ? `${total} candidature${total > 1 ? 's' : ''}`
                       : `${start + 1}–${end} sur ${total} candidatures`;

    const prev = document.getElementById('pgPrev'), next = document.getElementById('pgNext');
    prev.disabled = page <= 1; next.disabled = page >= pages;

    const wrap = document.getElementById('pgPages');
    wrap.innerHTML = pageNumbers(page, pages).map((p) =>
      p === '…' ? '<span class="pg-dots">…</span>'
                : `<button class="pg-btn${p === page ? ' active' : ''}" data-p="${p}">${p}</button>`).join('');
    wrap.querySelectorAll('.pg-btn').forEach((b) => {
      b.onclick = () => { page = +b.dataset.p; render(); scrollTop(); };
    });
  }
  function scrollTop() {
    const t = document.getElementById('compTable');
    if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function render() {
    let list = all.slice();
    if (filters.size) list = list.filter((c) => filters.has(c.status));
    if (term) {
      const t = term.toLowerCase();
      list = list.filter((c) => [c.name, c.position, c.city, c.contact_name, c.sector, c.email, c.apply_channel].some((v) => (v || '').toLowerCase().includes(t)));
    }
    list = applyAdvanced(list);

    const cnt = document.getElementById('filterCount');
    if (cnt) { const n = activeAdvCount(); cnt.textContent = n; cnt.hidden = n === 0; }

    if (!list.length) { body.innerHTML = row(all.length ? 'Aucun résultat pour ces filtres.' : 'Aucune candidature. Cliquez sur « Nouvelle candidature ».'); renderPager(0, 0, 0); return; }

    // Pagination : on borne la page courante puis on découpe
    const total = list.length;
    const pages = Math.max(1, Math.ceil(total / perPage));
    if (page > pages) page = pages;
    if (page < 1) page = 1;
    const start = (page - 1) * perPage;
    const pageList = list.slice(start, start + perPage);
    renderPager(total, pages, start);

    body.innerHTML = pageList.map((c) => {
      const col = window.STATUS_COLORS[c.status] || '148,163,184';
      const applied = c.applied_date ? new Date(c.applied_date).toLocaleDateString('fr-FR') : '—';
      const chan = c.apply_channel ? `<span class="chan-tag">${esc(c.apply_channel)}</span>` : '';
      return `<tr data-id="${c.id}">
        <td class="col-check"><input type="checkbox" class="row-check" data-id="${c.id}"></td>
        <td data-label="Entreprise"><div class="cell-co">${logoHtml(c)}<div><div class="cell-main">${esc(c.name)}</div><div class="cell-sub">${esc(c.city || c.sector || '')} ${chan}</div></div></div></td>
        <td class="cell-sub" data-label="Poste">${esc(c.position || '—')}</td>
        <td class="cell-sub" data-label="Contact">${esc(c.contact_name || c.email || '—')}</td>
        <td data-label="Statut"><span class="badge" style="background:rgba(${col},.12);color:rgb(${col})"><span class="dot"></span>${esc(window.STATUSES[c.status] || c.status)}</span></td>
        <td data-label="Priorité"><span class="prio prio-${esc(c.priority)}">${cap(c.priority)}</span></td>
        <td class="cell-sub" data-label="Candidature">${applied}</td>
        <td class="cell-actions"><div class="row-actions">
          <button class="mini-btn view" title="Voir">${icoEye}</button>
          <button class="mini-btn edit" title="Modifier">${icoPen}</button>
          <button class="mini-btn del" title="Supprimer">${icoTrash}</button>
        </div></td>
      </tr>`;
    }).join('');
    body.querySelectorAll('tr').forEach((tr) => {
      const id = tr.dataset.id;
      tr.querySelector('.view').onclick = () => view(id);
      const nameCell = tr.querySelector('.cell-co');
      if (nameCell) { nameCell.style.cursor = 'pointer'; nameCell.onclick = () => view(id); }
      tr.querySelector('.edit').onclick = () => edit(id);
      tr.querySelector('.del').onclick = () => del(id, tr);
    });
    // Cases de sélection
    body.querySelectorAll('.row-check').forEach((cb) => {
      cb.checked = selected.has(cb.dataset.id);
      cb.onclick = (e) => { e.stopPropagation(); toggleSel(cb.dataset.id, cb.checked); };
    });
    updateBulkBar();
  }
  const icoEye = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7z" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.7"/></svg>';
  const icoPen = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  const icoTrash = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';

  /* ---------- Filtres rapides (chips) multi-sélection + recherche ---------- */
  const filters = new Set();
  if (window.PRE_FILTER && window.PRE_FILTER !== 'all') filters.add(window.PRE_FILTER);

  function syncChips() {
    document.querySelectorAll('.chip').forEach((x) => {
      const f = x.dataset.filter;
      if (f === 'all') x.classList.toggle('active', filters.size === 0);
      else x.classList.toggle('active', filters.has(f));
    });
  }
  document.getElementById('filterChips').addEventListener('click', (e) => {
    const c = e.target.closest('.chip'); if (!c) return;
    const f = c.dataset.filter;
    if (f === 'all') filters.clear();
    else { if (filters.has(f)) filters.delete(f); else filters.add(f); }
    syncChips(); page = 1; render();
  });
  syncChips();
  if (window.PRE_SEARCH) { term = window.PRE_SEARCH; const si = document.getElementById('searchInput'); if (si) si.value = window.PRE_SEARCH; }

  // Commandes de pagination
  const pgPrev = document.getElementById('pgPrev'), pgNext = document.getElementById('pgNext'), pgSize = document.getElementById('pgSize');
  if (pgPrev) pgPrev.onclick = () => { if (page > 1) { page--; render(); scrollTop(); } };
  if (pgNext) pgNext.onclick = () => { page++; render(); scrollTop(); };
  if (pgSize) {
    try { const sv = localStorage.getItem('alt_perpage'); if (sv) { perPage = +sv; pgSize.value = sv; } } catch (e) {}
    pgSize.onchange = () => {
      perPage = +pgSize.value; page = 1;
      try { localStorage.setItem('alt_perpage', String(perPage)); } catch (e) {}
      render();
    };
  }

  let deb; document.getElementById('searchInput').addEventListener('input', (e) => {
    clearTimeout(deb); deb = setTimeout(() => { term = e.target.value.trim(); page = 1; render(); }, 180);
  });

  /* ---------- Filtres avancés ---------- */
  const advPanel = document.getElementById('advPanel');
  document.getElementById('advToggle').onclick = () => { advPanel.hidden = !advPanel.hidden; };
  const bind = (id, key) => {
    const el = document.getElementById(id); if (!el) return;
    el.addEventListener('input', () => { adv[key] = el.value; page = 1; render(); });
  };
  bind('fPriority', 'priority'); bind('fChannel', 'channel'); bind('fSector', 'sector');
  bind('fCity', 'city'); bind('fDateFrom', 'from'); bind('fDateTo', 'to');
  bind('fResponded', 'responded'); bind('fSort', 'sort');
  document.getElementById('advReset').onclick = () => {
    Object.keys(adv).forEach((k) => adv[k] = k === 'sort' ? 'recent' : '');
    ['fPriority', 'fChannel', 'fSector', 'fCity', 'fDateFrom', 'fDateTo', 'fResponded'].forEach((id) => { const el = document.getElementById(id); if (el) el.value = ''; });
    const s = document.getElementById('fSort'); if (s) s.value = 'recent';
    page = 1; render();
  };

  /* ---------- Formulaire ---------- */
  const form = document.getElementById('compForm');
  const chanSel = document.getElementById('applyChannel');
  const chanCustom = document.getElementById('applyChannelCustom');
  const PRESET_CHAN = ['Spontanée', 'Indeed', 'HelloWork', 'LinkedIn'];
  if (chanSel) chanSel.addEventListener('change', () => {
    chanCustom.hidden = chanSel.value !== '__custom';
    if (chanSel.value === '__custom') chanCustom.focus();
  });
  function setChannel(val) {
    if (!chanSel) return;
    if (!val) { chanSel.value = ''; chanCustom.hidden = true; chanCustom.value = ''; }
    else if (PRESET_CHAN.includes(val)) { chanSel.value = val; chanCustom.hidden = true; chanCustom.value = ''; }
    else { chanSel.value = '__custom'; chanCustom.hidden = false; chanCustom.value = val; }
  }

  function openNew() {
    form.reset(); document.getElementById('compId').value = '';
    setChannel('');
    const _ac = document.getElementById('nameAc'); if (_ac) { _ac.hidden = true; _ac.innerHTML = ''; }
    document.getElementById('compModalTitle').textContent = 'Nouvelle candidature';
    window.openModal('compModal');
  }
  document.getElementById('btnNew').onclick = openNew;
  if (window.AUTO_NEW) openNew();

  function edit(id) {
    const c = all.find((x) => x.id == id); if (!c) return;
    form.reset();
    const _ac = document.getElementById('nameAc'); if (_ac) { _ac.hidden = true; _ac.innerHTML = ''; }
    Object.keys(c).forEach((k) => { if (form[k] !== undefined && k !== 'apply_channel') form[k].value = c[k] == null ? '' : c[k]; });
    setChannel(c.apply_channel || '');
    document.getElementById('compId').value = c.id;
    document.getElementById('compModalTitle').textContent = 'Modifier — ' + c.name;
    window.closeModal('viewModal'); window.openModal('compModal');
  }

  /* ---------- Suggestions d'entreprise en direct ----------
     Plus de bouton « Compléter » : les suggestions apparaissent pendant la
     frappe. Un clic sur un résultat renseigne le nom ET complète les autres
     champs (site, ville, secteur…). Désactivable dans les réglages. */
  const nameAc = document.getElementById('nameAc');
  const nameInput = form.name;
  const autofillOn = !(window.ALT && window.ALT.prefs && window.ALT.prefs.autocomplete_off === '1');
  function hideNameAc() { if (nameAc) { nameAc.hidden = true; nameAc.innerHTML = ''; } }

  async function fillFromLookup(name) {
    try {
      const d = await window.altFetch('api/lookup.php?action=company&q=' + encodeURIComponent(name));
      const it = (d.items || [])[0];
      if (!it) return;
      if (it.sector && form.sector && !form.sector.value) form.sector.value = it.sector;
      if (it.city && form.city && !form.city.value) form.city.value = it.city;
      if (it.postal && form.postal_code && !form.postal_code.value) form.postal_code.value = it.postal;
      if (it.address && form.address && !form.address.value) form.address.value = it.address;
    } catch (e) { /* silencieux */ }
  }

  let nameDeb;
  if (nameInput && autofillOn) nameInput.addEventListener('input', () => {
    const q = nameInput.value.trim();
    clearTimeout(nameDeb);
    if (q.length < 2) { hideNameAc(); return; }
    nameDeb = setTimeout(async () => {
      try {
        const r = await fetch('https://autocomplete.clearbit.com/v1/companies/suggest?query=' + encodeURIComponent(q));
        if (!r.ok) { hideNameAc(); return; }
        const arr = await r.json();
        if (!Array.isArray(arr) || !arr.length) { hideNameAc(); return; }
        nameAc.innerHTML = arr.slice(0, 6).map((a, i) =>
          `<button type="button" class="ac-item" data-i="${i}"><span class="ac-logo"><img src="${window.altEsc(a.logo || '')}" alt="" onerror="this.style.display='none'"></span>${window.altEsc(a.name)} <span>${window.altEsc(a.domain || '')}</span></button>`).join('');
        nameAc.hidden = false;
        nameAc.querySelectorAll('.ac-item').forEach((b) => {
          b.onmousedown = async (e) => {
            e.preventDefault();
            const a = arr[+b.dataset.i];
            form.name.value = a.name;
            if (form.website && !form.website.value.trim() && a.domain) form.website.value = a.domain;
            hideNameAc();
            await fillFromLookup(a.name);
            window.toast('Entreprise complétée.', 'ok');
          };
        });
      } catch (e) { hideNameAc(); }
    }, 250);
  });
  if (nameInput) nameInput.addEventListener('blur', () => setTimeout(hideNameAc, 180));

  document.getElementById('compSave').onclick = async () => {
    if (!form.name.value.trim()) { window.toast('Le nom est requis.', 'err'); return; }
    const data = {}; new FormData(form).forEach((v, k) => data[k] = v);
    // Résout le canal personnalisé
    if (data.apply_channel === '__custom') data.apply_channel = (data.apply_channel_custom || '').trim();
    delete data.apply_channel_custom;
    try {
      await window.altFetch('api/companies.php?action=save', { json: data });
      window.closeModal('compModal');
      window.toast(data.id ? 'Entreprise mise à jour.' : 'Entreprise ajoutée.', 'ok');
      load();
    } catch (e) { window.toast(e.error || 'Échec de l\'enregistrement.', 'err'); }
  };

  /* ---------- Ouverture de la page entreprise dédiée ---------- */
  function view(id) {
    location.href = window.altApi('index.php?page=company&id=' + id);
  }

  function view_OLD(id) {
    const c = all.find((x) => x.id == id); if (!c) return;
    document.getElementById('viewTitle').textContent = c.name;
    const rows = [
      ['Poste', c.position], ['Secteur', c.sector], ['Statut', window.STATUSES[c.status]],
      ['Priorité', cap(c.priority)], ['Type de candidature', c.apply_channel], ['Contact', c.contact_name], ['E-mail', c.email],
      ['Téléphone', c.phone], ['Adresse', [c.address, c.postal_code, c.city].filter(Boolean).join(', ')],
      ['Site', c.website], ['Rémunération', c.salary],
      ['Candidature', fmt(c.applied_date)], ['Réponse', fmt(c.response_date)],
      ['Entretien', fmt(c.interview_date)], ['Relance', fmt(c.followup_date)]
    ].filter((r) => r[1]);
    const head = `<div class="view-co">${logoHtml(c)}<div><strong>${esc(c.name)}</strong>${c.website ? `<a href="${esc(/^https?:/i.test(c.website) ? c.website : 'https://' + c.website)}" target="_blank" rel="noopener" class="muted" style="font-size:12.5px;display:block">${esc(domainOf(c.website))}</a>` : ''}</div></div>`;
    document.getElementById('viewBody').innerHTML =
      head +
      rows.map((r) => `<div class="setting-row"><div class="st-txt"><strong>${esc(r[0])}</strong></div><div class="muted" style="text-align:right;max-width:60%">${esc(r[1])}</div></div>`).join('')
      + (c.notes ? `<div style="margin-top:16px"><strong>Notes</strong><p class="muted" style="margin-top:6px;white-space:pre-wrap">${esc(c.notes)}</p></div>` : '')
      + mailSection(c.id);
    document.getElementById('viewEdit').onclick = () => edit(id);
    window.openModal('viewModal');
    loadMails(c.id);
  }

  function mailSection(cid) {
    return `<div class="mail-block" style="margin-top:20px">
      <div class="section-title" style="margin-bottom:10px"><h2 style="font-size:15px">Échanges d'e-mails</h2>
        <button class="btn btn-ghost btn-sm" id="mailAddBtn" type="button">+ Ajouter</button></div>
      <form id="mailForm" hidden class="mail-form">
        <div class="form-grid">
          <div class="field"><label>Sens</label><select name="direction"><option value="recu">Reçu de l'entreprise</option><option value="envoye">Envoyé par moi</option></select></div>
          <div class="field"><label>Date</label><input name="email_date" type="date"></div>
          <div class="field full"><label>Objet</label><input name="subject" placeholder="Objet de l'e-mail"></div>
          <div class="field full"><label>Message</label><textarea name="body" placeholder="Copiez ici le contenu de l'e-mail…"></textarea></div>
        </div>
        <div class="flex gap" style="justify-content:flex-end">
          <button class="btn btn-ghost btn-sm" type="button" id="mailCancel">Annuler</button>
          <button class="btn btn-primary btn-sm" type="button" id="mailSave" data-cid="${cid}">Enregistrer</button>
        </div>
      </form>
      <div id="mailList" class="mail-list"><p class="hint">Chargement…</p></div>
    </div>`;
  }

  async function loadMails(cid) {
    const listEl = document.getElementById('mailList');
    const addBtn = document.getElementById('mailAddBtn');
    const formEl = document.getElementById('mailForm');
    if (addBtn) addBtn.onclick = () => { formEl.hidden = false; addBtn.hidden = true; };
    if (document.getElementById('mailCancel')) document.getElementById('mailCancel').onclick = () => { formEl.hidden = true; addBtn.hidden = false; formEl.reset(); };
    if (document.getElementById('mailSave')) document.getElementById('mailSave').onclick = async () => {
      const d = {}; new FormData(formEl).forEach((v, k) => d[k] = v); d.company_id = cid;
      if (!(d.body || '').trim() && !(d.subject || '').trim()) { window.toast('Ajoutez un objet ou un message.', 'err'); return; }
      try {
        await window.altFetch('api/company_emails.php?action=add', { json: d });
        formEl.reset(); formEl.hidden = true; if (addBtn) addBtn.hidden = false;
        window.toast('Échange enregistré.', 'ok');
        loadMails(cid);
      } catch (e) { window.toast(e.error || 'Erreur.', 'err'); }
    };
    if (!listEl) return;
    try {
      const d = await window.altFetch('api/company_emails.php?action=list&company_id=' + cid);
      if (!d.items || !d.items.length) { listEl.innerHTML = '<p class="hint">Aucun échange enregistré pour l\'instant.</p>'; return; }
      listEl.innerHTML = d.items.map((m) => `
        <div class="mail-item ${m.direction}">
          <div class="mail-top"><span class="mail-dir">${m.direction === 'recu' ? '↓ Reçu' : '↑ Envoyé'}</span>
            <span class="mail-date">${m.email_date ? fmt(m.email_date) : fmt(m.created_at)}</span>
            <button class="mail-del" data-id="${m.id}" title="Supprimer">✕</button></div>
          ${m.subject ? `<div class="mail-subj">${esc(m.subject)}</div>` : ''}
          ${m.body ? `<div class="mail-body">${esc(m.body)}</div>` : ''}
        </div>`).join('');
      listEl.querySelectorAll('.mail-del').forEach((b) => {
        b.onclick = async () => {
          if (!(await window.altConfirm('Supprimer cet échange ?'))) return;
          try { await window.altFetch('api/company_emails.php?action=delete', { json: { id: +b.dataset.id } }); loadMails(cid); }
          catch (e) { window.toast('Erreur.', 'err'); }
        };
      });
    } catch (e) { listEl.innerHTML = '<p class="hint">Impossible de charger les échanges.</p>'; }
  }

  async function del(id, tr) {
    if (!(await window.altConfirm('Supprimer cette entreprise ? Cette action est définitive.', {title:'Supprimer'}))) return;
    try {
      await window.altFetch('api/companies.php?action=delete', { json: { id } });
      tr.style.transition = 'opacity .2s'; tr.style.opacity = '0';
      setTimeout(() => { all = all.filter((x) => x.id != id); render(); }, 200);
      window.toast('Entreprise supprimée.', 'ok');
    } catch (e) { window.toast('Suppression impossible.', 'err'); }
  }

  /* ---------- Import ---------- */
  document.getElementById('importFile').addEventListener('change', function () {
    const f = this.files[0]; if (!f) return;
    const r = new FileReader(); r.onload = () => { document.getElementById('importText').value = r.result; }; r.readAsText(f, 'UTF-8');
  });
  document.getElementById('importRun').onclick = async () => {
    const raw = document.getElementById('importText').value.trim();
    if (!raw) { window.toast('Collez une liste à importer.', 'err'); return; }
    try {
      const d = await window.altFetch('api/companies.php?action=import', { json: { raw } });
      window.closeModal('importModal');
      document.getElementById('importText').value = '';
      window.toast(d.count + ' entreprise' + (d.count > 1 ? 's importées' : ' importée') + '.', 'ok');
      load();
    } catch (e) { window.toast(e.error || 'Import impossible.', 'err'); }
  };

  /* ---------- Sélection multiple & actions groupées ---------- */
  const bulkBar = document.getElementById('bulkBar');
  const bulkN = document.getElementById('bulkN');
  const selAll = document.getElementById('selAll');

  function toggleSel(id, on) { if (on) selected.add(id); else selected.delete(id); updateBulkBar(); }
  function updateBulkBar() {
    if (bulkN) bulkN.textContent = selected.size;
    if (bulkBar) bulkBar.hidden = selected.size === 0;
    if (selAll) {
      const boxes = body.querySelectorAll('.row-check');
      const checked = body.querySelectorAll('.row-check:checked');
      selAll.checked = boxes.length > 0 && checked.length === boxes.length;
      selAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
    }
  }
  if (selAll) selAll.onclick = () => {
    body.querySelectorAll('.row-check').forEach((cb) => { cb.checked = selAll.checked; toggleSel(cb.dataset.id, cb.checked); });
  };
  // Boutons « Tout sélectionner » (barre d'outils + barre d'actions), avec bascule
  function selectAllRows() {
    const boxes = body.querySelectorAll('.row-check');
    if (!boxes.length) { window.toast('Aucune candidature à sélectionner.', 'err'); return; }
    const allChecked = selected.size >= boxes.length;
    boxes.forEach((cb) => { cb.checked = !allChecked; toggleSel(cb.dataset.id, cb.checked); });
  }
  const selectAllToolbar = document.getElementById('selectAllToolbar');
  if (selectAllToolbar) selectAllToolbar.onclick = selectAllRows;
  const bulkSelectAll = document.getElementById('bulkSelectAll');
  if (bulkSelectAll) bulkSelectAll.onclick = selectAllRows;
  function clearSel() { selected.clear(); body.querySelectorAll('.row-check').forEach((c) => c.checked = false); updateBulkBar(); }
  const bulkClear = document.getElementById('bulkClear');
  if (bulkClear) bulkClear.onclick = clearSel;

  async function bulkRun(payload, okMsg) {
    try {
      const r = await window.altFetch('api/companies.php?action=bulk', { json: Object.assign({ ids: [...selected] }, payload) });
      window.toast(okMsg.replace('%n', r.affected != null ? r.affected : selected.size), 'ok');
      clearSel(); load();
    } catch (e) { window.toast((e && e.error) || 'Action impossible.', 'err'); }
  }
  const bulkStatus = document.getElementById('bulkStatus');
  const bulkPriority = document.getElementById('bulkPriority');
  const bulkChannel = document.getElementById('bulkChannel');
  const bulkApply = document.getElementById('bulkApply');
  // Les menus ne déclenchent plus rien tout seuls : on applique au clic sur « Appliquer ».
  if (bulkApply) bulkApply.onclick = async () => {
    if (!selected.size) { window.toast('Sélectionnez au moins une candidature.', 'err'); return; }
    const status = bulkStatus ? bulkStatus.value : '';
    const priority = bulkPriority ? bulkPriority.value : '';
    let channel = bulkChannel ? bulkChannel.value : '';
    if (!status && !priority && !channel) { window.toast('Choisissez au moins une modification.', 'err'); return; }
    if (channel === '__custom') {
      const custom = await window.altPrompt('Type de candidature personnalisé :', '', { title: 'Type personnalisé' });
      if (custom === null) return;
      channel = custom.trim();
      if (!channel) return;
    }
    // Applique successivement les modifications demandées.
    if (status)   await bulkRun({ op: 'status', status },     '%n candidature(s) mise(s) à jour.');
    if (priority) await bulkRun({ op: 'priority', priority }, 'Priorité appliquée à %n élément(s).');
    if (channel)  await bulkRun({ op: 'channel', channel },   'Type appliqué à %n candidature(s).');
    // Réinitialise les menus après application.
    if (bulkStatus) bulkStatus.value = '';
    if (bulkPriority) bulkPriority.value = '';
    if (bulkChannel) bulkChannel.value = '';
  };
  const bulkDates = document.getElementById('bulkDates');
  if (bulkDates) bulkDates.onclick = () => {
    if (!selected.size) return;
    document.getElementById('bulkDatesN').textContent = selected.size;
    document.getElementById('bulkAppliedDate').value = '';
    document.getElementById('bulkResponseDate').value = '';
    document.getElementById('bulkAppliedClear').checked = false;
    document.getElementById('bulkResponseClear').checked = false;
    window.openModal('bulkDatesModal');
  };
  const bulkDatesApply = document.getElementById('bulkDatesApply');
  if (bulkDatesApply) bulkDatesApply.onclick = async () => {
    const appliedClear = document.getElementById('bulkAppliedClear').checked;
    const responseClear = document.getElementById('bulkResponseClear').checked;
    const applied = document.getElementById('bulkAppliedDate').value;
    const response = document.getElementById('bulkResponseDate').value;

    // On applique séquentiellement ce qui a été renseigné (ou coché "effacer")
    const ops = [];
    if (appliedClear) ops.push({ op: 'applied_date', value: '' });
    else if (applied) ops.push({ op: 'applied_date', value: applied });
    if (responseClear) ops.push({ op: 'response_date', value: '' });
    else if (response) ops.push({ op: 'response_date', value: response });

    if (!ops.length) { window.toast('Aucune date à modifier.', 'err'); return; }
    try {
      const ids = [...selected];
      for (const o of ops) {
        await window.altFetch('api/companies.php?action=bulk', { json: Object.assign({ ids }, o) });
      }
      window.closeModal('bulkDatesModal');
      window.toast('Dates mises à jour pour ' + ids.length + ' candidature(s).', 'ok');
      clearSel(); load();
    } catch (e) { window.toast((e && e.error) || 'Action impossible.', 'err'); }
  };
  const bulkDelete = document.getElementById('bulkDelete');
  if (bulkDelete) bulkDelete.onclick = async () => {
    if (!selected.size) return;
    if (!(await window.altConfirm('Supprimer les ' + selected.size + ' entreprise(s) sélectionnée(s) ? Cette action est définitive.', { title: 'Suppression multiple' }))) return;
    await bulkRun({ op: 'delete' }, '%n entreprise(s) supprimée(s).');
  };
  const bulkExport = document.getElementById('bulkExport');
  if (bulkExport) bulkExport.onclick = () => {
    if (!selected.size) return;
    window.open(window.altApi('export/pdf.php?ids=' + [...selected].join(',')), '_blank');
  };

  /* ---------- Partage en lecture seule ---------- */
  const btnShare = document.getElementById('btnShare');
  if (btnShare) {
    btnShare.onclick = () => { window.openModal('shareModal'); loadShares(); };

    document.querySelectorAll('#shareModal [data-toggle-all]').forEach((b) => {
      b.onclick = () => {
        const boxes = document.querySelectorAll('#shareModal .' + b.dataset.toggleAll);
        const allOn = [...boxes].every((c) => c.checked);
        boxes.forEach((c) => { c.checked = !allOn; });
      };
    });

    async function loadShares() {
      const el = document.getElementById('shList');
      try {
        const d = await window.altFetch('api/share.php?action=list');
        if (!d.items || !d.items.length) { el.innerHTML = '<p class="hint">Aucun lien pour le moment.</p>'; return; }
        el.innerHTML = d.items.map((s) => {
          let state = '<span class="tag" style="color:#16a34a">actif</span>';
          if (s.revoked) state = '<span class="tag" style="color:#dc2626">révoqué</span>';
          else if (s.expired) state = '<span class="tag" style="color:#b45309">expiré</span>';
          const exp = s.expires_at ? ' · expire le ' + s.expires_at.slice(0, 10).split('-').reverse().join('/') : ' · sans expiration';
          return `<div class="setting-row">
            <div class="st-txt"><strong>${esc(s.label || 'Lien de partage')} ${state}</strong>
              <p>${s.views} consultation${s.views > 1 ? 's' : ''}${exp}</p></div>
            <div class="row-actions" style="display:flex;gap:6px">
              ${(!s.revoked && !s.expired) ? `<button class="btn btn-ghost btn-sm sh-copy" data-url="${esc(s.url)}">Copier</button>
              <button class="btn btn-ghost btn-sm sh-revoke" data-id="${s.id}" style="color:#dc2626">Révoquer</button>` : ''}
              <button class="mini-btn sh-del" data-id="${s.id}" title="Supprimer">✕</button>
            </div></div>`;
        }).join('');
        el.querySelectorAll('.sh-copy').forEach((b) => b.onclick = () => copyText(b.dataset.url));
        el.querySelectorAll('.sh-revoke').forEach((b) => b.onclick = async () => {
          if (!(await window.altConfirm('Révoquer ce lien ? Il cessera immédiatement de fonctionner.', { title: 'Révoquer le lien' }))) return;
          await window.altFetch('api/share.php?action=revoke', { json: { id: +b.dataset.id } });
          window.toast('Lien révoqué.', 'ok'); loadShares();
        });
        el.querySelectorAll('.sh-del').forEach((b) => b.onclick = async () => {
          if (!(await window.altConfirm('Supprimer définitivement ce lien de la liste ?'))) return;
          await window.altFetch('api/share.php?action=delete', { json: { id: +b.dataset.id } });
          loadShares();
        });
      } catch (e) { el.innerHTML = '<p class="hint">Erreur de chargement.</p>'; }
    }

    async function copyText(txt) {
      try {
        await navigator.clipboard.writeText(txt);
        window.toast('Lien copié.', 'ok');
      } catch (e) {
        // Repli : les navigateurs refusent le presse-papiers hors HTTPS/geste utilisateur
        const ta = document.createElement('textarea');
        ta.value = txt; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); window.toast('Lien copié.', 'ok'); }
        catch (e2) { window.toast('Copie impossible, sélectionnez le lien manuellement.', 'err'); }
        ta.remove();
      }
    }

    document.getElementById('shCreate').onclick = async () => {
      const statuses = [...document.querySelectorAll('.sh-st:checked')].map((c) => c.value);
      const fields = [...document.querySelectorAll('.sh-fd:checked')].map((c) => c.value);
      if (!statuses.length) { window.toast('Cochez au moins un statut.', 'err'); return; }
      if (!fields.length) { window.toast('Cochez au moins une colonne.', 'err'); return; }
      const showContact = document.getElementById('shContact').checked;
      const showNotes = document.getElementById('shNotes').checked;
      if (showContact && !(await window.altConfirm('Les coordonnées des contacts (nom, e-mail, téléphone) seront visibles par toute personne disposant du lien. Continuer ?', { title: 'Données personnelles', tone: 'danger', okText: 'Je confirme' }))) return;
      if (showContact) fields.push('contact_name', 'email', 'phone');
      if (showNotes) fields.push('notes');
      try {
        const r = await window.altFetch('api/share.php?action=create', {
          json: {
            label: document.getElementById('shLabel').value.trim(),
            statuses, fields,
            show_contact: showContact ? 1 : 0,
            show_notes: showNotes ? 1 : 0,
            expires_days: +document.getElementById('shExpires').value,
          }
        });
        document.getElementById('shResult').hidden = false;
        document.getElementById('shUrl').value = r.url;
        window.toast('Lien créé.', 'ok');
        loadShares();
      } catch (e) { window.toast((e && e.error) || 'Création impossible.', 'err'); }
    };
    document.getElementById('shCopy').onclick = () => copyText(document.getElementById('shUrl').value);
  }

  load();
})();
