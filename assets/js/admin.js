/* Page Administration — comptes, notifications, messages, réglages */
(function () {
  'use strict';
  const esc = window.altEsc, api = window.altApi;
  const $ = (id) => document.getElementById(id);
  const roleLabels = { admin: 'Administrateur', student: 'Étudiant', parent: 'Parent' };
  let USERS = [];

  /* ---------- Onglets ---------- */
  document.querySelectorAll('#adminTabs .scrape-tab').forEach((t) => {
    t.onclick = () => {
      document.querySelectorAll('#adminTabs .scrape-tab').forEach((x) => x.classList.toggle('active', x === t));
      document.querySelectorAll('.admin-pane').forEach((p) => { p.hidden = p.dataset.pane !== t.dataset.tab; });
      if (t.dataset.tab === 'notifs') loadSchedules();
      if (t.dataset.tab === 'messages') loadMessages();
      if (t.dataset.tab === 'settings') loadSettings();
    };
  });

  function fillTargets() {
    const opts = '<option value="">Tous les utilisateurs</option>' +
      USERS.map((u) => `<option value="${u.id}">${esc(u.full_name || u.username)}</option>`).join('');
    ['nTarget', 'sTarget', 'mTarget'].forEach((id) => { const el = $(id); if (el) el.innerHTML = opts; });
  }

  /* ---------- Liste des comptes ---------- */
  async function load() {
    try {
      const d = await window.altFetch('api/admin.php?action=list');
      USERS = d.items || [];
      render(USERS);
      fillTargets();
    } catch (e) { $('userBody').innerHTML = '<tr><td colspan="8" class="muted" style="text-align:center;padding:30px">Erreur.</td></tr>'; }
  }

  function render(users) {
    const body = $('userBody');
    body.innerHTML = users.map((u) => {
      const active = u.is_active == 1;
      const last = u.last_login ? new Date(u.last_login.replace(' ', 'T')).toLocaleDateString('fr-FR') : 'Jamais';
      const linked = u.linked_name || u.linked_username || '—';
      const rc = u.role === 'admin' ? '124,92,252' : (u.role === 'parent' ? '249,115,22' : '34,197,94');
      return `<tr data-id="${u.id}">
        <td data-label="Utilisateur"><div class="cell-main">${esc(u.full_name || u.username)}</div><div class="cell-sub">${esc(u.email)} · @${esc(u.username)}</div></td>
        <td data-label="Rôle"><span class="badge" style="background:rgba(${rc},.12);color:rgb(${rc})"><span class="dot"></span>${roleLabels[u.role]}</span></td>
        <td class="cell-sub" data-label="Lié à">${esc(linked)}</td>
        <td class="cell-sub" data-label="Candid.">${u.companies}</td>
        <td data-label="OTP">${u.otp_enabled == 1 ? '<span class="tag" style="color:#16a34a">Oui</span>' : '<span class="tag">Non</span>'}</td>
        <td class="cell-sub" data-label="Connexion">${last}</td>
        <td data-label="Statut"><span class="badge" style="${active ? 'background:rgba(34,197,94,.12);color:#16a34a' : 'background:rgba(239,68,68,.12);color:#dc2626'}"><span class="dot"></span>${active ? 'Actif' : 'Verrouillé'}</span></td>
        <td class="cell-actions"><div class="row-actions">
          <button class="mini-btn edit" title="Modifier">✎</button>
          <button class="mini-btn creds" title="Envoyer les identifiants par e-mail">✉</button>
          ${u.role === 'student' ? `<button class="mini-btn viewas" title="Voir ses données">👁</button>` : ''}
          ${u.role === 'parent' ? `<button class="mini-btn relink" title="Changer la personne suivie">🔗</button>` : ''}
          <button class="mini-btn reset" title="Réinitialiser le mot de passe">🔑</button>
          <button class="mini-btn lock" title="${active ? 'Verrouiller' : 'Déverrouiller'}">${active ? '🔒' : '🔓'}</button>
          <button class="mini-btn del" title="Supprimer">✕</button>
        </div></td>
      </tr>`;
    }).join('');

    body.querySelectorAll('tr').forEach((tr) => {
      const id = tr.dataset.id;
      const u = users.find((x) => x.id == id);
      tr.querySelector('.edit').onclick = () => openEdit(u);
      tr.querySelector('.creds').onclick = () => sendCreds(u);
      tr.querySelector('.reset').onclick = () => {
        $('resetUserId').value = id; $('resetUserName').textContent = 'Pour : ' + (u.full_name || u.username);
        $('resetPwd').value = ''; window.openModal('resetModal');
      };
      tr.querySelector('.lock').onclick = () => (u.is_active == 1 ? openLock(u) : doUnlock(u));
      const va = tr.querySelector('.viewas');
      if (va) va.onclick = async () => { await window.altFetch('api/admin.php?action=view_as', { json: { id } }); window.toast('Vous consultez les données de ' + (u.full_name || u.username) + '.', 'ok'); setTimeout(() => location.href = api('index.php?page=dashboard'), 700); };
      const rl = tr.querySelector('.relink');
      if (rl) rl.onclick = () => openRelink(u);
      tr.querySelector('.del').onclick = async () => {
        if (!(await window.altConfirm('Supprimer ce compte et toutes ses données ? Cette action est définitive.', { title: 'Supprimer le compte' }))) return;
        try { await window.altFetch('api/admin.php?action=delete', { json: { id } }); window.toast('Compte supprimé.', 'ok'); load(); } catch (e) { window.toast(e.error, 'err'); }
      };
    });
  }

  /* ---------- Champ de liaison (rôle parent) ---------- */
  const roleSel = $('roleSelect'), linkField = $('linkField'), linkSel = $('linkSelect');
  async function loadStudents(selectedId) {
    try {
      const d = await window.altFetch('api/admin.php?action=students');
      linkSel.innerHTML = '<option value="">Sélectionner…</option>' + (d.items || []).map((s) =>
        `<option value="${s.id}"${s.id == selectedId ? ' selected' : ''}>${esc(s.full_name || s.username)}${s.role === 'admin' ? ' — Admin' : ''}</option>`).join('');
    } catch (e) { linkSel.innerHTML = '<option value="">Erreur</option>'; }
  }
  roleSel.addEventListener('change', () => {
    if (roleSel.value === 'parent') { linkField.hidden = false; loadStudents(linkSel.value); }
    else linkField.hidden = true;
  });
  const genPwd = $('genPwd'), userPwd = $('userPwd');
  if (genPwd) genPwd.addEventListener('change', () => { userPwd.disabled = genPwd.checked; userPwd.value = genPwd.checked ? '' : userPwd.value; });

  /* ---------- Création / édition ---------- */
  const form = $('userForm');
  $('btnNewUser').onclick = () => {
    form.reset(); $('userId').value = ''; linkField.hidden = true; userPwd.disabled = false;
    $('userModalTitle').textContent = 'Nouveau compte';
    $('createOnly').style.display = '';
    $('userSave').textContent = 'Créer le compte';
    window.openModal('userModal');
  };
  function openEdit(u) {
    form.reset(); $('userId').value = u.id;
    form.full_name.value = u.full_name || ''; form.username.value = u.username; form.email.value = u.email;
    form.role.value = u.role;
    $('userModalTitle').textContent = 'Modifier — ' + (u.full_name || u.username);
    $('createOnly').style.display = 'none';   // pas de mot de passe en édition
    $('userSave').textContent = 'Enregistrer';
    if (u.role === 'parent') { linkField.hidden = false; loadStudents(u.linked_student_id); }
    else linkField.hidden = true;
    window.openModal('userModal');
  }
  $('userSave').onclick = async () => {
    const data = {}; new FormData(form).forEach((v, k) => data[k] = v);
    const editing = !!data.id;
    if (!editing) {
      data.generate_password = genPwd.checked ? 1 : 0;
      data.send_email = $('sendEmail').checked ? 1 : 0;
      data.must_change = $('mustChange').checked ? 1 : 0;
    }
    try {
      const r = await window.altFetch('api/admin.php?action=' + (editing ? 'update' : 'create'), { json: data });
      window.closeModal('userModal');
      if (!editing && r.password) {
        window.altAlert('Compte créé.\n\nMot de passe : ' + r.password + (r.mail && r.mail.sent ? '\n\nE-mail envoyé.' : (r.mail && r.mail.error ? '\n\nE-mail non envoyé : ' + r.mail.error : '')), { title: 'Identifiants' });
      } else if (!editing && r.mail && !r.mail.sent && r.mail.error) {
        window.toast('Compte créé, mais e-mail non envoyé : ' + r.mail.error, 'err');
      } else {
        window.toast(editing ? 'Compte mis à jour.' : 'Compte créé.', 'ok');
      }
      load();
    } catch (e) { window.toast(e.error || 'Échec.', 'err'); }
  };

  async function sendCreds(u) {
    if (!(await window.altConfirm('Envoyer un nouvel e-mail d\'identifiants à ' + esc(u.email) + ' ? Un nouveau mot de passe provisoire sera généré.', { title: 'Envoyer les identifiants', tone: 'info', okText: 'Envoyer' }))) return;
    try {
      const r = await window.altFetch('api/admin.php?action=send_credentials', { json: { id: u.id, regenerate: true } });
      window.altAlert('E-mail envoyé à ' + u.email + '.\n\nMot de passe provisoire : ' + (r.password || '—'), { title: 'Identifiants envoyés' });
    } catch (e) { window.toast(e.error || 'Envoi impossible.', 'err'); }
  }

  /* ---------- Verrouillage ---------- */
  function openLock(u) { $('lockUserId').value = u.id; $('lockUserName').textContent = 'Compte : ' + (u.full_name || u.username); $('lockMsg').value = ''; window.openModal('lockModal'); }
  $('lockGo').onclick = async () => {
    try { await window.altFetch('api/admin.php?action=lock', { json: { id: $('lockUserId').value, message: $('lockMsg').value } }); window.closeModal('lockModal'); window.toast('Compte verrouillé.', 'ok'); load(); }
    catch (e) { window.toast(e.error || 'Échec.', 'err'); }
  };
  async function doUnlock(u) {
    try { await window.altFetch('api/admin.php?action=unlock', { json: { id: u.id } }); window.toast('Compte déverrouillé.', 'ok'); load(); }
    catch (e) { window.toast(e.error, 'err'); }
  }

  /* ---------- Relink ---------- */
  function openRelink(u) {
    $('relinkUserId').value = u.id; $('relinkUserName').textContent = 'Compte parent : ' + (u.full_name || u.username);
    const sel = $('relinkSelect'); sel.innerHTML = '<option value="">Chargement…</option>';
    window.openModal('relinkModal');
    window.altFetch('api/admin.php?action=students').then((d) => {
      sel.innerHTML = '<option value="">Sélectionner…</option>' + (d.items || []).map((s) =>
        `<option value="${s.id}"${s.id == u.linked_student_id ? ' selected' : ''}>${esc(s.full_name || s.username)}${s.role === 'admin' ? ' — Admin' : ''}</option>`).join('');
    }).catch(() => { sel.innerHTML = '<option value="">Erreur</option>'; });
  }
  $('relinkSave').onclick = async () => {
    const id = $('relinkUserId').value, linked = $('relinkSelect').value;
    if (!linked) { window.toast('Choisissez une personne.', 'err'); return; }
    try { await window.altFetch('api/admin.php?action=relink', { json: { id, linked_student_id: linked } }); window.closeModal('relinkModal'); window.toast('Liaison mise à jour.', 'ok'); load(); }
    catch (e) { window.toast(e.error || 'Échec.', 'err'); }
  };
  $('resetSave').onclick = async () => {
    try { await window.altFetch('api/admin.php?action=reset_pwd', { json: { id: $('resetUserId').value, password: $('resetPwd').value } }); window.closeModal('resetModal'); window.toast('Mot de passe réinitialisé.', 'ok'); }
    catch (e) { window.toast(e.error || 'Échec.', 'err'); }
  };

  /* ---------- Notifications immédiates ---------- */
  $('nSend').onclick = async () => {
    const title = $('nTitle').value.trim(), body = $('nBody').value.trim();
    if (!title && !body) { window.toast('Titre ou message requis.', 'err'); return; }
    try { const r = await window.altFetch('api/admin.php?action=notify', { json: { title, body, target_user_id: $('nTarget').value } }); window.toast('Envoyée à ' + r.count + ' utilisateur(s).', 'ok'); $('nTitle').value = ''; $('nBody').value = ''; }
    catch (e) { window.toast(e.error || 'Échec.', 'err'); }
  };

  /* ---------- Notifications programmées ---------- */
  const sFreq = $('sFreq');
  sFreq.addEventListener('change', () => {
    $('sOnceWrap').hidden = sFreq.value !== 'once';
    $('sTimeWrap').hidden = sFreq.value === 'once';
    $('sDowWrap').hidden = sFreq.value !== 'weekly';
  });
  $('sSave').onclick = async () => {
    const data = { title: $('sTitle').value.trim(), body: $('sBody').value.trim(), freq: sFreq.value, target_user_id: $('sTarget').value };
    if (!data.title && !data.body) { window.toast('Titre ou message requis.', 'err'); return; }
    if (sFreq.value === 'once') data.run_at = $('sRunAt').value;
    else { data.run_time = $('sRunTime').value; if (sFreq.value === 'weekly') data.run_dow = $('sDow').value; }
    try { await window.altFetch('api/admin.php?action=schedules&sub=save', { json: data }); window.toast('Notification programmée.', 'ok'); $('sTitle').value = ''; $('sBody').value = ''; loadSchedules(); }
    catch (e) { window.toast(e.error || 'Échec.', 'err'); }
  };
  const freqLabels = { once: 'Une fois', daily: 'Quotidien', weekly: 'Hebdomadaire' };
  async function loadSchedules() {
    const el = $('schedList');
    try {
      const d = await window.altFetch('api/admin.php?action=schedules&sub=list');
      if (!d.items || !d.items.length) { el.innerHTML = '<p class="hint">Aucune notification programmée.</p>'; return; }
      el.innerHTML = d.items.map((s) => `<div class="setting-row">
        <div class="st-txt"><strong>${esc(s.title || '(sans titre)')} <span class="tag">${freqLabels[s.freq] || s.freq}</span> ${s.is_active == 1 ? '' : '<span class="tag" style="color:#dc2626">en pause</span>'}</strong>
          <p>${esc((s.body || '').slice(0, 90))} · ${s.target_name ? 'pour ' + esc(s.target_name) : 'tous'} · prochaine : ${esc((s.next_run || '').replace('T', ' '))}</p></div>
        <div class="row-actions"><button class="btn btn-ghost btn-sm sc-toggle" data-id="${s.id}">${s.is_active == 1 ? 'Pause' : 'Activer'}</button>
          <button class="mini-btn sc-del" data-id="${s.id}" title="Supprimer">✕</button></div></div>`).join('');
      el.querySelectorAll('.sc-toggle').forEach((b) => b.onclick = async () => { await window.altFetch('api/admin.php?action=schedules&sub=toggle', { json: { id: +b.dataset.id } }); loadSchedules(); });
      el.querySelectorAll('.sc-del').forEach((b) => b.onclick = async () => { if (!(await window.altConfirm('Supprimer cette programmation ?'))) return; await window.altFetch('api/admin.php?action=schedules&sub=delete', { json: { id: +b.dataset.id } }); loadSchedules(); });
    } catch (e) { el.innerHTML = '<p class="hint">Erreur de chargement.</p>'; }
  }

  /* ---------- Messages / bannières ---------- */
  $('mSave').onclick = async () => {
    const data = { kind: $('mKind').value, title: $('mTitle').value.trim(), body: $('mBody').value.trim(), color: $('mColor').value, target_user_id: $('mTarget').value, ends_at: $('mEnds').value, dismissible: $('mDismiss').checked ? 1 : 0 };
    if (!data.title && !data.body) { window.toast('Titre ou message requis.', 'err'); return; }
    try { await window.altFetch('api/admin.php?action=messages&sub=save', { json: data }); window.toast('Message publié.', 'ok'); $('mTitle').value = ''; $('mBody').value = ''; loadMessages(); }
    catch (e) { window.toast(e.error || 'Échec.', 'err'); }
  };
  async function loadMessages() {
    const el = $('msgList');
    try {
      const d = await window.altFetch('api/admin.php?action=messages&sub=list');
      if (!d.items || !d.items.length) { el.innerHTML = '<p class="hint">Aucun message.</p>'; return; }
      el.innerHTML = d.items.map((m) => `<div class="setting-row">
        <div class="st-txt"><strong>${esc(m.title || '(sans titre)')} <span class="tag">${m.kind === 'banner' ? 'Bannière' : 'Pop-up'}</span> ${m.dismissible == 0 ? '<span class="tag" style="color:#b45309">bloquant</span>' : ''} ${m.is_active == 1 ? '' : '<span class="tag" style="color:#dc2626">masqué</span>'}</strong>
          <p>${esc((m.body || '').slice(0, 90))} · ${m.target_name ? 'pour ' + esc(m.target_name) : 'tous'}${m.ends_at ? ' · expire ' + esc(m.ends_at.replace('T', ' ')) : ''}</p></div>
        <div class="row-actions"><button class="btn btn-ghost btn-sm mg-toggle" data-id="${m.id}">${m.is_active == 1 ? 'Masquer' : 'Afficher'}</button>
          <button class="mini-btn mg-del" data-id="${m.id}" title="Supprimer">✕</button></div></div>`).join('');
      el.querySelectorAll('.mg-toggle').forEach((b) => b.onclick = async () => { await window.altFetch('api/admin.php?action=messages&sub=toggle', { json: { id: +b.dataset.id } }); loadMessages(); });
      el.querySelectorAll('.mg-del').forEach((b) => b.onclick = async () => { if (!(await window.altConfirm('Supprimer ce message ?'))) return; await window.altFetch('api/admin.php?action=messages&sub=delete', { json: { id: +b.dataset.id } }); loadMessages(); });
    } catch (e) { el.innerHTML = '<p class="hint">Erreur de chargement.</p>'; }
  }

  /* ---------- Réglages ---------- */
  async function loadSettings() {
    try {
      const d = await window.altFetch('api/admin.php?action=settings_get');
      const s = d.settings || {};
      $('setMaintenance').checked = s.maintenance_mode === '1';
      $('setMaintMsg').value = s.maintenance_message || '';
      $('setLoginTitle').value = s.login_title || '';
      $('setLoginSubtitle').value = s.login_subtitle || '';
      $('setLoginNotice').value = s.login_notice || '';
      $('setLoginAccent').value = s.login_accent || '';
    } catch (e) {}
  }
  $('setSave').onclick = async () => {
    const data = {
      maintenance_mode: $('setMaintenance').checked ? 1 : 0,
      maintenance_message: $('setMaintMsg').value,
      login_title: $('setLoginTitle').value,
      login_subtitle: $('setLoginSubtitle').value,
      login_notice: $('setLoginNotice').value,
      login_accent: $('setLoginAccent').value,
    };
    try { await window.altFetch('api/admin.php?action=settings_save', { json: data }); window.toast('Réglages enregistrés.', 'ok'); }
    catch (e) { window.toast(e.error || 'Échec.', 'err'); }
  };

  const stop = $('stopViewAs');
  if (stop) stop.onclick = async () => { await window.altFetch('api/admin.php?action=view_as', { json: { id: 0 } }); location.reload(); };

  load();
})();
