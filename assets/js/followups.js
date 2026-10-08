/* Page Relances — brouillons, envoi, report */
(function () {
  'use strict';
  const esc = window.altEsc;
  const $ = (id) => document.getElementById(id);
  let items = [], current = null, variant = '1';
  let gmailReady = false, gmailError = '';

  async function load() {
    const el = $('fuList');
    try {
      const d = await window.altFetch('api/followups.php?action=list');
      items = d.items || [];
      gmailReady = !!d.gmail_ready;
      gmailError = d.gmail_error || '';
      if (d.delay) { $('delayLabel').textContent = d.delay; $('fuDelay').value = d.delay; }
      render();
    } catch (e) { el.innerHTML = '<div class="empty" style="padding:40px"><p>Erreur de chargement.</p></div>'; }
  }

  function render() {
    const el = $('fuList');
    if (!items.length) {
      el.innerHTML = `<div class="card card-pad" style="text-align:center;padding:50px 24px">
        <div style="font-size:34px;margin-bottom:10px">✅</div>
        <h3 style="font-size:17px;margin:0 0 6px">Rien à relancer</h3>
        <p class="muted" style="margin:0">Aucune candidature ne dépasse le délai. Revenez dans quelques jours.</p>
      </div>`;
      return;
    }
    el.innerHTML = items.map((c) => {
      const urgent = c.days_since >= 21;
      const rel = c.relances > 0 ? `<span class="tag">${c.relances}re relance déjà envoyée</span>` : '';
      return `<div class="card card-pad fu-card" data-id="${c.id}" style="margin-bottom:12px">
        <div class="fu-head">
          <div style="min-width:0">
            <strong style="font-size:15px">${esc(c.name)}</strong>
            <div class="cell-sub">${esc(c.position || 'Poste non précisé')}</div>
          </div>
          <span class="fu-days ${urgent ? 'urgent' : ''}">${c.days_since} jours sans réponse</span>
        </div>
        <div class="fu-meta">
          ${c.contact_name ? `<span>Contact : ${esc(c.contact_name)}</span>` : '<span class="muted">Aucun contact renseigné</span>'}
          ${c.can_email ? `<span>${esc(c.email)}</span>` : '<span class="muted">Pas d\'adresse e-mail</span>'}
          ${rel}
        </div>
        <div class="fu-actions">
          <button class="btn btn-primary btn-sm fu-open" ${c.can_email ? '' : 'title="Aucune adresse : vous pourrez copier le texte"'}>Préparer la relance</button>
          <button class="btn btn-ghost btn-sm fu-snooze">Reporter</button>
          <button class="btn btn-ghost btn-sm fu-dismiss">Ne plus proposer</button>
        </div>
      </div>`;
    }).join('');

    el.querySelectorAll('.fu-card').forEach((card) => {
      const id = +card.dataset.id;
      const c = items.find((x) => x.id === id);
      card.querySelector('.fu-open').onclick = () => openDraft(c);
      card.querySelector('.fu-snooze').onclick = () => snooze(c);
      card.querySelector('.fu-dismiss').onclick = () => dismiss(c);
    });
  }

  async function openDraft(c) {
    current = c;
    variant = c.relances >= 1 ? '2' : '1';
    $('fuTitle').textContent = 'Relancer — ' + c.name;
    document.querySelectorAll('#fuVariants .chip').forEach((b) => b.classList.toggle('active', b.dataset.variant === variant));
    $('fuId').value = c.id;
    $('fuTo').value = c.email || '';

    const sendBtn = $('fuSend');
    const hint = $('fuReply');
    if (gmailReady) {
      sendBtn.style.display = '';
      hint.className = 'hint';
      hint.textContent = 'Le message partira depuis votre adresse Gmail. Un recruteur qui répond vous écrira directement.';
    } else {
      // Gmail non configuré : on masque « Envoyer », on garde « Copier »,
      // et on affiche l'erreur de connexion détaillée.
      sendBtn.style.display = 'none';
      hint.className = 'hint fu-gmail-err';
      hint.innerHTML = '⚠️ <strong>Envoi direct indisponible.</strong> ' + esc(gmailError || 'Gmail n\'est pas configuré.')
        + '<br>En attendant, utilisez <strong>Copier le texte</strong> puis envoyez le message depuis votre messagerie, ou notez la relance avec « J\'ai relancé moi-même ».';
    }
    await fillDraft();
    window.openModal('fuModal');
  }

  async function fillDraft() {
    try {
      const d = await window.altFetch('api/followups.php?action=draft&id=' + current.id + '&variant=' + variant);
      $('fuSubject').value = d.subject;
      $('fuBody').value = d.body;
    } catch (e) { window.toast('Brouillon indisponible.', 'err'); }
  }

  document.querySelectorAll('#fuVariants .chip').forEach((b) => {
    b.onclick = async () => {
      variant = b.dataset.variant;
      document.querySelectorAll('#fuVariants .chip').forEach((x) => x.classList.toggle('active', x === b));
      if (current) await fillDraft();
    };
  });

  $('fuSend').onclick = async () => {
    const to = $('fuTo').value.trim();
    if (!to) { window.toast('Renseignez l\'adresse du destinataire.', 'err'); return; }
    if (!(await window.altConfirm('Envoyer ce message à ' + to + ' ?', { title: 'Envoyer la relance', tone: 'info', okText: 'Envoyer' }))) return;
    const btn = $('fuSend'); btn.disabled = true; const t = btn.textContent; btn.textContent = 'Envoi…';
    try {
      await window.altFetch('api/followups.php?action=send', {
        json: { id: current.id, to, subject: $('fuSubject').value, body: $('fuBody').value }
      });
      window.closeModal('fuModal');
      window.toast('Relance envoyée et archivée.', 'ok');
      load();
    } catch (e) {
      // Gmail non configuré ou refus SMTP : message détaillé + repli sur Copier
      const msg = (e && e.error) || 'Envoi impossible.';
      if (e && (e.gmail_missing || e.gmail_error)) {
        gmailReady = false; gmailError = msg;
        $('fuSend').style.display = 'none';
        $('fuReply').className = 'hint fu-gmail-err';
        $('fuReply').innerHTML = '⚠️ <strong>Envoi via Gmail impossible.</strong> ' + esc(msg)
          + '<br>Utilisez <strong>Copier le texte</strong> puis envoyez depuis votre messagerie.';
      }
      window.altAlert(msg, { title: 'Échec de l\'envoi' });
    }
    finally { btn.disabled = false; btn.textContent = t; }
  };

  $('fuMark').onclick = async () => {
    if (!(await window.altConfirm('Enregistrer cette relance comme envoyée depuis votre propre messagerie ?', { title: 'Relance manuelle', tone: 'info', okText: 'Enregistrer' }))) return;
    try {
      await window.altFetch('api/followups.php?action=record', {
        json: { id: current.id, subject: $('fuSubject').value, body: $('fuBody').value }
      });
      window.closeModal('fuModal');
      window.toast('Relance enregistrée.', 'ok');
      load();
    } catch (e) { window.toast((e && e.error) || 'Erreur.', 'err'); }
  };

  $('fuCopy').onclick = async () => {
    const txt = $('fuSubject').value + '\n\n' + $('fuBody').value;
    try { await navigator.clipboard.writeText(txt); window.toast('Texte copié.', 'ok'); }
    catch (e) {
      const ta = document.createElement('textarea'); ta.value = txt; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); window.toast('Texte copié.', 'ok'); } catch (e2) { window.toast('Copie impossible.', 'err'); }
      ta.remove();
    }
  };

  async function snooze(c) {
    const d = await window.altPrompt('Reporter cette relance de combien de jours ?', '7', { title: 'Reporter' });
    if (d === null) return;
    const days = parseInt(d, 10);
    if (!days || days < 1) { window.toast('Nombre de jours invalide.', 'err'); return; }
    try { await window.altFetch('api/followups.php?action=snooze', { json: { id: c.id, days } });
      window.toast('Reportée de ' + days + ' jours.', 'ok'); load(); }
    catch (e) { window.toast('Erreur.', 'err'); }
  }

  async function dismiss(c) {
    if (!(await window.altConfirm('Ne plus proposer de relance pour ' + c.name + ' ? La candidature sera considérée comme close.', { title: 'Ne plus proposer' }))) return;
    try { await window.altFetch('api/followups.php?action=dismiss', { json: { id: c.id } }); load(); }
    catch (e) { window.toast('Erreur.', 'err'); }
  }

  $('fuSettings').onclick = () => window.openModal('fuSetModal');
  $('fuSetSave').onclick = async () => {
    try {
      const r = await window.altFetch('api/followups.php?action=settings', { json: { delay: +$('fuDelay').value } });
      window.closeModal('fuSetModal');
      $('delayLabel').textContent = r.delay;
      window.toast('Réglage enregistré.', 'ok');
      load();
    } catch (e) { window.toast('Erreur.', 'err'); }
  };

  load();
})();
