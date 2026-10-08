/* Page Entreprises — fiches réelles (1 entreprise -> N candidatures) */
(function () {
  'use strict';
  const esc = window.altEsc, api = window.altApi;
  const listEl = document.getElementById('dirList');
  let orgs = [], term = '';

  function logoHtml(name, website, domain) {
    const d = domain || '';
    const ini = esc((name || '?').trim().charAt(0).toUpperCase());
    if (!d) return `<span class="clogo"><em>${ini}</em></span>`;
    let cached = null; try { cached = localStorage.getItem('alt_logo:' + d); } catch (e) {}
    if (cached === 'n') return `<span class="clogo"><em>${ini}</em></span>`;
    const cb = `https://logo.clearbit.com/${encodeURIComponent(d)}?size=80`;
    const fav = `https://www.google.com/s2/favicons?domain=${encodeURIComponent(d)}&sz=64`;
    const primary = cached === 'f' ? fav : cb;
    const onl = `try{localStorage.setItem('alt_logo:${d}',this.src.indexOf('clearbit')>-1?'c':'f')}catch(e){}`;
    const oe = `if(!this.dataset.f){this.dataset.f=1;this.src='${fav}'}else{try{localStorage.setItem('alt_logo:${d}','n')}catch(e){}this.remove()}`;
    return `<span class="clogo"><em>${ini}</em><img src="${primary}" alt="" loading="lazy" onload="${onl}" onerror="${oe}"></span>`;
  }

  async function load() {
    try {
      const d = await window.altFetch('api/orgs.php?action=list');
      orgs = d.items || [];
      render();
    } catch (e) { listEl.innerHTML = '<div class="empty" style="padding:30px"><p>Erreur de chargement.</p></div>'; }
  }

  function chips(o) {
    const out = [];
    if (+o.pending) out.push(`<span class="tag">${o.pending} en attente</span>`);
    if (+o.interviews) out.push(`<span class="tag" style="color:#ea580c">${o.interviews} entretien${o.interviews > 1 ? 's' : ''}</span>`);
    if (+o.accepted) out.push(`<span class="tag" style="color:#16a34a">${o.accepted} accepté${o.accepted > 1 ? 's' : ''}</span>`);
    if (+o.refused) out.push(`<span class="tag" style="color:#dc2626">${o.refused} refus</span>`);
    return out.join(' ');
  }

  let selectMode = false;
  const selected = new Set();

  function render() {
    let list = orgs;
    if (term) { const t = term.toLowerCase(); list = list.filter((o) => (o.name || '').toLowerCase().includes(t)); }
    if (!list.length) {
      listEl.innerHTML = '<div class="empty" style="padding:36px"><p>Aucune entreprise. Elles apparaissent dès votre première candidature.</p></div>';
      return;
    }
    listEl.innerHTML = list.map((o) => {
      const checked = selected.has(o.id) ? 'checked' : '';
      const sel = selectMode
        ? `<label class="dir-pick" onclick="event.stopPropagation()"><input type="checkbox" class="dir-check" data-id="${o.id}" ${checked}></label>`
        : '';
      const inner = `
        ${sel}
        <div class="dir-top">${logoHtml(o.name, o.website, o.domain)}
          <div class="dir-info"><strong>${esc(o.name)}</strong>
            <span class="muted">${esc(o.sector || o.city || '')}</span></div>
          <span class="dir-count">${o.offers}</span>
        </div>
        <div class="dir-chips">${chips(o)}</div>
        ${o.contact_name ? `<div class="dir-contact">Contact : ${esc(o.contact_name)}</div>` : ''}`;
      // En mode sélection : div non navigable. Sinon : lien vers la fiche.
      return selectMode
        ? `<div class="dir-card sel" data-id="${o.id}">${inner}</div>`
        : `<a class="dir-card" href="${api('index.php?page=org&id=' + o.id)}">${inner}</a>`;
    }).join('');

    if (selectMode) {
      listEl.querySelectorAll('.dir-card.sel').forEach((card) => {
        const id = +card.dataset.id;
        const cb = card.querySelector('.dir-check');
        card.onclick = () => { cb.checked = !cb.checked; toggle(id, cb.checked); };
        cb.onclick = (e) => { e.stopPropagation(); toggle(id, cb.checked); };
      });
    }
    updateBulk();
  }

  function toggle(id, on) { if (on) selected.add(id); else selected.delete(id); updateBulk(); }

  function updateBulk() {
    const bar = document.getElementById('dirBulkBar');
    const n = document.getElementById('dirBulkN');
    if (n) n.textContent = selected.size;
    if (bar) bar.hidden = !selectMode || selected.size === 0;
    const all = document.getElementById('dirSelAll');
    if (all) {
      const total = orgs.length;
      all.checked = total > 0 && selected.size === total;
      all.indeterminate = selected.size > 0 && selected.size < total;
    }
  }

  const selModeBtn = document.getElementById('dirSelectMode');
  if (selModeBtn) selModeBtn.onclick = () => {
    selectMode = !selectMode;
    selModeBtn.textContent = selectMode ? 'Quitter la sélection' : 'Sélectionner';
    document.getElementById('dirSelAllWrap').hidden = !selectMode;
    if (!selectMode) selected.clear();
    render();
  };

  const selAll = document.getElementById('dirSelAll');
  if (selAll) selAll.onclick = () => {
    if (selAll.checked) orgs.forEach((o) => selected.add(o.id));
    else selected.clear();
    render();
  };

  const dirClear = document.getElementById('dirBulkClear');
  if (dirClear) dirClear.onclick = () => { selected.clear(); render(); };

  const dirDelete = document.getElementById('dirBulkDelete');
  if (dirDelete) dirDelete.onclick = async () => {
    if (!selected.size) return;
    if (!(await window.altConfirm('Supprimer les ' + selected.size + ' entreprise(s) sélectionnée(s) ? Les fiches ayant encore des candidatures ne seront pas supprimées.', { title: 'Suppression multiple' }))) return;
    let ok = 0, kept = 0;
    for (const id of [...selected]) {
      try { await window.altFetch('api/orgs.php?action=delete', { json: { id } }); ok++; }
      catch (e) { kept++; }
    }
    window.toast(ok + ' supprimée(s)' + (kept ? ', ' + kept + ' conservée(s) (candidatures liées)' : ''), ok ? 'ok' : 'err');
    selected.clear();
    load();
  };

  const dirMerge = document.getElementById('dirMerge');
  if (dirMerge) dirMerge.onclick = async () => {
    if (selected.size < 2) { window.toast('Sélectionnez au moins deux entreprises à fusionner.', 'err'); return; }
    const ids = [...selected];
    const targets = orgs.filter((o) => ids.includes(o.id));
    const names = targets.map((o) => o.name).join(', ');
    if (!(await window.altConfirm('Fusionner ' + selected.size + ' entreprises (' + names + ') en une seule ? Toutes les candidatures seront regroupées sur la première.', { title: 'Fusionner les fiches' }))) return;
    const target = ids[0];
    let merged = 0;
    for (const src of ids.slice(1)) {
      try { await window.altFetch('api/orgs.php?action=merge', { json: { source_id: src, target_id: target } }); merged++; }
      catch (e) { /* on continue */ }
    }
    window.toast(merged + ' fiche(s) fusionnée(s).', merged ? 'ok' : 'err');
    selected.clear();
    load();
  };

  let deb;
  document.getElementById('dirSearch').addEventListener('input', (e) => {
    clearTimeout(deb); deb = setTimeout(() => { term = e.target.value.trim(); render(); }, 160);
  });

  load();
})();
