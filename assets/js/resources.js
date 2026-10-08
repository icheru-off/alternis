/* Page Ressources */
(function () {
  'use strict';
  const api = window.altApi || ((p) => p);
  const fileInput = document.getElementById('resFile');
  let pending = null; // { kind, title }

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function fmtDate(s) {
    if (!s) return '';
    const d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d) ? s : d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
  }
  function fmtSize(n) {
    n = +n || 0;
    if (n < 1024) return n + ' o';
    if (n < 1048576) return (n / 1024).toFixed(0) + ' Ko';
    return (n / 1048576).toFixed(1) + ' Mo';
  }
  function extIcon(name) {
    const e = (name.split('.').pop() || '').toLowerCase();
    return e.toUpperCase();
  }

  async function upload(kind, file, title) {
    const fd = new FormData();
    fd.append('kind', kind);
    fd.append('title', title || '');
    fd.append('file', file);
    fd.append('csrf', window.ALT.csrf);
    const r = await fetch(api('api/resources.php?action=upload'), {
      method: 'POST',
      headers: { 'X-CSRF': window.ALT.csrf },
      body: fd
    });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw d;
    return d;
  }

  function fileRow(item) {
    const dl = api('api/resources.php?action=download&id=' + item.id);
    const view = api('api/resources.php?action=download&inline=1&id=' + item.id);
    return '' +
      '<div class="res-file">' +
        '<span class="res-ext">' + esc(extIcon(item.original_name)) + '</span>' +
        '<div class="res-info">' +
          '<strong>' + esc(item.title || item.original_name) + '</strong>' +
          '<span class="res-sub">' + esc(item.original_name) + ' · ' + fmtSize(item.size) + '</span>' +
          '<span class="res-updated">Dernière mise à jour le : ' + fmtDate(item.updated_at) + '</span>' +
        '</div>' +
        '<div class="res-actions">' +
          '<a class="btn btn-ghost btn-sm" href="' + view + '" target="_blank" rel="noopener">Ouvrir</a>' +
          '<a class="btn btn-ghost btn-sm" href="' + dl + '">Télécharger</a>' +
          '<button class="btn btn-danger btn-sm res-del" data-id="' + item.id + '">Supprimer</button>' +
        '</div>' +
      '</div>';
  }

  function renderSlot(el, kind, item, labelBtn) {
    if (item) {
      el.innerHTML = fileRow(item) +
        '<button class="btn btn-ghost btn-sm res-replace" data-kind="' + kind + '" style="margin-top:12px">Remplacer le fichier</button>';
    } else {
      el.innerHTML =
        '<div class="res-drop" data-kind="' + kind + '">' +
          '<svg viewBox="0 0 24 24" width="26" height="26" fill="none"><path d="M12 16V4m0 0 4 4m-4-4L8 8M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
          '<strong>' + labelBtn + '</strong>' +
          '<span>PDF, DOCX, ODT, JPG, PNG — 8 Mo max.</span>' +
        '</div>';
    }
    bindSlot(el);
  }

  function bindSlot(scope) {
    scope.querySelectorAll('.res-drop, .res-replace').forEach((n) => {
      n.onclick = () => {
        pending = { kind: n.dataset.kind, title: '' };
        fileInput.click();
      };
    });
    scope.querySelectorAll('.res-del').forEach((b) => {
      b.onclick = async () => {
        if (!(await window.altConfirm('Supprimer ce document ?'))) return;
        try {
          await window.altFetch('api/resources.php?action=delete', { json: { id: +b.dataset.id } });
          window.toast('Document supprimé.', 'ok');
          load();
        } catch (e) { window.toast((e && e.error) || 'Erreur.', 'err'); }
      };
    });
  }

  async function load() {
    let items = [];
    try {
      const d = await window.altFetch('api/resources.php?action=list');
      items = d.items || [];
    } catch (e) { /* affichera vide */ }

    const cv = items.find((i) => i.kind === 'cv');
    const lettre = items.find((i) => i.kind === 'lettre');
    const others = items.filter((i) => i.kind === 'autre');

    renderSlot(document.getElementById('cvSlot'), 'cv', cv, 'Déposer / choisir mon CV');
    renderSlot(document.getElementById('lettreSlot'), 'lettre', lettre, 'Déposer / choisir ma lettre');

    const ol = document.getElementById('othersList');
    if (!others.length) {
      ol.innerHTML = '<div class="empty" style="padding:26px 10px"><p>Aucun autre document. Ajoutez-en un (attestation, portfolio, diplôme…).</p></div>';
    } else {
      ol.innerHTML = others.map(fileRow).join('');
      bindSlot(ol);
    }
  }

  document.getElementById('addOther').onclick = async () => {
    const t = await window.altPrompt('Nom du document', '', { title: 'Ajouter un document', placeholder: 'ex. Portfolio, Attestation…' });
    if (t === null) return;
    pending = { kind: 'autre', title: (t || '').trim() };
    fileInput.click();
  };

  fileInput.onchange = async () => {
    const file = fileInput.files && fileInput.files[0];
    fileInput.value = '';
    if (!file || !pending) return;
    window.toast('Envoi en cours…');
    try {
      await upload(pending.kind, file, pending.title);
      window.toast('Document enregistré.', 'ok');
      load();
    } catch (e) {
      window.toast((e && e.error) || "Échec de l'envoi.", 'err');
    }
    pending = null;
  };

  load();
})();
