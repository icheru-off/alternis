/* Page entreprise dédiée — e-mails (chronologie), édition, suppression */
(function () {
  'use strict';
  const esc = window.altEsc;
  const cid = window.CO_ID;
  const fmt = (d) => d ? new Date(d).toLocaleDateString('fr-FR') : '';

  /* ---- Édition ---- */
  const coForm = document.getElementById('coForm');
  document.getElementById('coEdit').onclick = () => window.openModal('coModal');
  document.getElementById('coSave').onclick = async () => {
    const data = {}; new FormData(coForm).forEach((v, k) => data[k] = v);
    if (!data.name.trim()) { window.toast('Le nom est requis.', 'err'); return; }
    try {
      await window.altFetch('api/companies.php?action=save', { json: data });
      window.toast('Entreprise mise à jour.', 'ok');
      setTimeout(() => location.reload(), 500);
    } catch (e) { window.toast(e.error || 'Échec.', 'err'); }
  };

  document.getElementById('coDelete').onclick = async () => {
    if (!(await window.altConfirm('Supprimer cette entreprise ? Cette action est définitive.', { title: 'Supprimer' }))) return;
    try {
      await window.altFetch('api/companies.php?action=delete', { json: { id: cid } });
      window.toast('Entreprise supprimée.', 'ok');
      setTimeout(() => location.href = window.altApi('index.php?page=companies'), 400);
    } catch (e) { window.toast('Suppression impossible.', 'err'); }
  };

  /* ---- E-mails ---- */
  const listEl = document.getElementById('mailList');
  const formEl = document.getElementById('mailForm');
  const addBtn = document.getElementById('mailAddBtn');
  addBtn.onclick = () => { formEl.hidden = false; addBtn.hidden = true; };
  document.getElementById('mailCancel').onclick = () => { formEl.hidden = true; addBtn.hidden = false; formEl.reset(); };
  document.getElementById('mailSave').onclick = async () => {
    const fd = new FormData(formEl);
    fd.append('company_id', cid);
    const subj = (fd.get('subject') || '').trim();
    const bod = (fd.get('body') || '').trim();
    const file = fd.get('attachment');
    if (!subj && !bod && (!file || !file.name)) { window.toast('Ajoutez un objet, un message ou une pièce jointe.', 'err'); return; }
    try {
      await window.altFetch('api/company_emails.php?action=add', { form: fd });
      formEl.reset(); formEl.hidden = true; addBtn.hidden = false;
      window.toast('Échange enregistré.', 'ok');
      loadMails();
    } catch (e) { window.toast(e.error || 'Erreur.', 'err'); }
  };

  async function loadMails() {
    try {
      const d = await window.altFetch('api/company_emails.php?action=list&company_id=' + cid);
      if (!d.items || !d.items.length) { listEl.innerHTML = '<p class="hint">Aucun échange enregistré pour l\'instant.</p>'; return; }
      listEl.innerHTML = d.items.map((m) => {
        const att = (m.has_attachment == 1)
          ? `<a class="mail-att" href="${window.altApi('api/company_emails.php?action=attachment&id=' + m.id)}" target="_blank" rel="noopener">📎 ${esc(m.attachment_name || 'Pièce jointe')}</a>`
          : '';
        return `<div class="mail-item ${m.direction}">
          <div class="mail-top"><span class="mail-dir">${m.direction === 'recu' ? '↓ Reçu' : '↑ Envoyé'}</span>
            <span class="mail-date">${m.email_date ? fmt(m.email_date) : fmt(m.created_at)}</span>
            <button class="mail-del" data-id="${m.id}" title="Supprimer">✕</button></div>
          ${m.subject ? `<div class="mail-subj">${esc(m.subject)}</div>` : ''}
          ${m.body ? `<div class="mail-body">${esc(m.body)}</div>` : ''}
          ${att}
        </div>`;
      }).join('');
      listEl.querySelectorAll('.mail-del').forEach((b) => {
        b.onclick = async () => {
          if (!(await window.altConfirm('Supprimer cet échange ?'))) return;
          try { await window.altFetch('api/company_emails.php?action=delete', { json: { id: +b.dataset.id } }); loadMails(); }
          catch (e) { window.toast('Erreur.', 'err'); }
        };
      });
    } catch (e) { listEl.innerHTML = '<p class="hint">Impossible de charger les échanges.</p>'; }
  }
  loadMails();
})();
