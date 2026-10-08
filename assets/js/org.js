/* Fiche entreprise : édition + historique des échanges consolidé */
(function () {
  'use strict';
  const esc = window.altEsc;

  const btn = document.getElementById('orgEdit');
  if (btn) btn.onclick = () => window.openModal('orgModal');

  const save = document.getElementById('orgSave');
  if (save) save.onclick = async () => {
    const d = {};
    new FormData(document.getElementById('orgForm')).forEach((v, k) => d[k] = v);
    if (!d.name.trim()) { window.toast('Le nom est obligatoire.', 'err'); return; }
    try {
      await window.altFetch('api/orgs.php?action=save', { json: d });
      window.toast('Fiche mise à jour.', 'ok');
      setTimeout(() => location.reload(), 500);
    } catch (e) { window.toast((e && e.error) || 'Échec.', 'err'); }
  };

  /* Échanges de toutes les candidatures de cette entreprise */
  function fmt(d) {
    if (!d) return '';
    const x = new Date(String(d).replace(' ', 'T'));
    return isNaN(x) ? String(d) : x.toLocaleDateString('fr-FR');
  }

  async function loadMails() {
    const el = document.getElementById('orgMails');
    try {
      const org = (await window.altFetch('api/orgs.php?action=get&id=' + window.ORG_ID)).org;
      const offers = org.offers || [];
      if (!offers.length) { el.innerHTML = '<p class="hint">Aucune candidature, donc aucun échange.</p>'; return; }

      const lists = await Promise.all(offers.map((o) =>
        window.altFetch('api/company_emails.php?action=list&company_id=' + o.id)
          .then((d) => (d.items || []).map((m) => Object.assign({}, m, { position: o.position, company_id: o.id })))
          .catch(() => [])
      ));
      const all = [].concat.apply([], lists);
      if (!all.length) { el.innerHTML = '<p class="hint">Aucun échange enregistré.</p>'; return; }

      all.sort((a, b) => String(b.email_date || b.created_at).localeCompare(String(a.email_date || a.created_at)));
      el.innerHTML = '<div class="mail-list timeline">' + all.map((m) => `
        <div class="mail-item ${m.direction}">
          <div class="mail-top">
            <span class="mail-dir">${m.direction === 'recu' ? '↓ Reçu' : '↑ Envoyé'}</span>
            <span class="mail-date">${fmt(m.email_date || m.created_at)}</span>
          </div>
          ${m.position ? `<div class="cell-sub" style="margin-bottom:4px">${esc(m.position)}</div>` : ''}
          ${m.subject ? `<div class="mail-subj">${esc(m.subject)}</div>` : ''}
          ${m.body ? `<div class="mail-body">${esc(m.body)}</div>` : ''}
        </div>`).join('') + '</div>';
    } catch (e) { el.innerHTML = '<p class="hint">Erreur de chargement.</p>'; }
  }

  loadMails();
})();
