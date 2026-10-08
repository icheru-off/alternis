/* Page Offres à faire — entrées en statut « a_postuler » */
(function () {
  'use strict';
  const esc = window.altEsc;
  const listEl = document.getElementById('todoList');
  const form = document.getElementById('todoForm');
  let items = [];

  function domainOf(url) {
    if (!url) return '';
    try { let u = url.trim(); if (!/^https?:\/\//i.test(u)) u = 'https://' + u; return new URL(u).hostname.replace(/^www\./, ''); } catch (e) { return ''; }
  }
  function logoHtml(c) {
    const d = domainOf(c.website);
    const ini = esc((c.name || '?').trim().charAt(0).toUpperCase());
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
      const d = await window.altFetch('api/companies.php?action=list');
      items = (d.items || []).filter((c) => c.status === 'a_postuler');
      render();
    } catch (e) { listEl.innerHTML = '<div class="empty" style="padding:30px"><p>Erreur de chargement.</p></div>'; }
  }

  function render() {
    if (!items.length) {
      listEl.innerHTML = '<div class="empty" style="padding:40px"><p>Aucune offre à faire pour l\'instant.<br>Marquez une candidature « À postuler » pour la retrouver ici.</p></div>';
      return;
    }
    listEl.innerHTML = items.map((c, i) => {
      const link = c.website ? (/^https?:/i.test(c.website) ? c.website : 'https://' + c.website) : '';
      return `<div class="todo-card" data-i="${i}">
        <div class="todo-logo">${logoHtml(c)}</div>
        <div class="todo-main">
          <strong>${esc(c.position || 'Poste non précisé')}</strong>
          <div class="todo-sub">${esc(c.name)}${c.city ? ' · ' + esc(c.city) : ''}${c.apply_channel ? ' · <span class="chan-tag">' + esc(c.apply_channel) + '</span>' : ''}</div>
          ${link ? `<a href="${esc(link)}" target="_blank" rel="noopener" class="sc-link">Voir l'offre ↗</a>` : '<span class="hint">Aucun lien</span>'}
          ${c.notes ? `<div class="todo-notes">${esc(c.notes)}</div>` : ''}
        </div>
        <div class="todo-actions">
          <button class="btn btn-primary btn-sm todo-apply" title="Marquer comme postulé">Postulé ✓</button>
          <button class="mini-btn todo-edit" title="Modifier">✎</button>
          <button class="mini-btn todo-del" title="Supprimer">✕</button>
        </div>
      </div>`;
    }).join('');
    listEl.querySelectorAll('.todo-card').forEach((card) => {
      const c = items[+card.dataset.i];
      card.querySelector('.todo-apply').onclick = () => markApplied(c);
      card.querySelector('.todo-edit').onclick = () => openEdit(c);
      card.querySelector('.todo-del').onclick = () => del(c, card);
    });
  }

  function openNew() {
    form.reset(); form.id.value = '';
    document.getElementById('todoModalTitle').textContent = 'Ajouter une offre';
    window.openModal('todoModal');
  }
  function openEdit(c) {
    form.reset();
    ['id', 'name', 'position', 'website', 'city', 'apply_channel', 'notes'].forEach((k) => { if (form[k] !== undefined) form[k].value = c[k] == null ? '' : c[k]; });
    document.getElementById('todoModalTitle').textContent = 'Modifier l\'offre';
    window.openModal('todoModal');
  }
  document.getElementById('todoNew').onclick = openNew;

  document.getElementById('todoSave').onclick = async () => {
    const data = {}; new FormData(form).forEach((v, k) => data[k] = v);
    if (!data.name.trim() || !data.position.trim()) { window.toast('Poste et entreprise sont requis.', 'err'); return; }
    data.status = 'a_postuler';
    try {
      await window.altFetch('api/companies.php?action=save', { json: data });
      window.closeModal('todoModal');
      window.toast(data.id ? 'Offre mise à jour.' : 'Offre ajoutée.', 'ok');
      load();
    } catch (e) { window.toast(e.error || 'Échec.', 'err'); }
  };

  async function markApplied(c) {
    if (!(await window.altConfirm('Marquer « ' + (c.position || c.name) + ' » comme postulée ? Elle rejoindra vos candidatures.', { title: 'Candidature envoyée', tone: 'info', okText: 'Oui, postulé' }))) return;
    try {
      const payload = Object.assign({}, c, { status: 'envoye', applied_date: c.applied_date || new Date().toISOString().slice(0, 10) });
      await window.altFetch('api/companies.php?action=save', { json: payload });
      window.toast('Déplacée vers vos candidatures.', 'ok');
      load();
    } catch (e) { window.toast('Erreur.', 'err'); }
  }

  async function del(c, card) {
    if (!(await window.altConfirm('Supprimer cette offre ?'))) return;
    try {
      await window.altFetch('api/companies.php?action=delete', { json: { id: c.id } });
      card.style.opacity = '0'; setTimeout(load, 180);
      window.toast('Offre supprimée.', 'ok');
    } catch (e) { window.toast('Suppression impossible.', 'err'); }
  }

  load();
})();
