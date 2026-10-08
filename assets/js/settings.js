/* Page Paramètres */
(function () {
  /* Onglets */
  document.querySelectorAll('.settings-nav a').forEach((a) => {
    a.addEventListener('click', (e) => {
      e.preventDefault();
      document.querySelectorAll('.settings-nav a').forEach((x) => x.classList.remove('active'));
      a.classList.add('active');
      document.querySelectorAll('.tab-panel').forEach((p) => p.hidden = true);
      document.getElementById('tab-' + a.dataset.tab).hidden = false;
    });
  });

  /* Profil */
  const pf = document.getElementById('profileForm');
  document.getElementById('profileSave').onclick = async () => {
    const data = { full_name: pf.full_name.value, email: pf.email.value };
    try { await window.altFetch('api/profile.php?action=update', { json: data }); window.toast('Profil mis à jour.', 'ok'); }
    catch (e) { window.toast(e.error || 'Échec.', 'err'); }
  };

  /* Avatar */
  const ai = document.getElementById('avatarInput');
  if (ai) ai.addEventListener('change', async function () {
    const f = this.files[0]; if (!f) return;
    const fd = new FormData(); fd.append('avatar', f); fd.append('csrf', window.ALT.csrf);
    try {
      const d = await fetch(window.altApi('api/profile.php?action=avatar'), { method: 'POST', body: fd }).then((r) => r.json());
      if (d.error) throw d;
      document.getElementById('avatarPreview').innerHTML = '<img src="' + window.altApi(d.avatar) + '" alt="">';
      window.toast('Photo mise à jour.', 'ok');
    } catch (e) { window.toast(e.error || 'Envoi impossible.', 'err'); }
  });

  const adel = document.getElementById('avatarDelete');
  if (adel) adel.addEventListener('click', async function () {
    if (!(await window.altConfirm('Supprimer votre photo de profil ?'))) return;
    try {
      await window.altFetch('api/profile.php?action=avatar_delete', { json: {} });
      const initial = (window.ALT.user && window.ALT.user.name ? window.ALT.user.name : '?').trim().charAt(0).toUpperCase();
      document.getElementById('avatarPreview').innerHTML = '<span class="avatar-fallback">' + initial + '</span>';
      window.toast('Photo supprimée.', 'ok');
    } catch (e) { window.toast(e.error || 'Échec.', 'err'); }
  });

  /* Mot de passe */
  const pw = document.getElementById('pwdForm');
  document.getElementById('pwdSave').onclick = async () => {
    if (pw.new.value !== pw.confirm.value) { window.toast('Les mots de passe ne correspondent pas.', 'err'); return; }
    if (pw.new.value.length < 8) { window.toast('8 caractères minimum.', 'err'); return; }
    try {
      await window.altFetch('api/profile.php?action=password', { json: { current: pw.current.value, new: pw.new.value } });
      pw.reset(); window.toast('Mot de passe modifié.', 'ok');
    } catch (e) { window.toast(e.error || 'Échec.', 'err'); }
  };

  /* OTP — activation */
  const otpStart = document.getElementById('otpStart');
  if (otpStart) otpStart.onclick = async () => {
    try {
      const d = await window.altFetch('api/otp.php?action=setup', { method: 'POST' });
      document.getElementById('otpSetup').hidden = false;
      otpStart.hidden = true;
      document.getElementById('otpSecret').textContent = d.secret;
      const box = document.getElementById('qrRender'); box.innerHTML = '';
      new QRCode(box, { text: d.uri, width: 168, height: 168, correctLevel: QRCode.CorrectLevel.M });
    } catch (e) { window.toast('Impossible de démarrer la configuration.', 'err'); }
  };
  const otpConfirm = document.getElementById('otpConfirm');
  if (otpConfirm) otpConfirm.onclick = async () => {
    const code = document.getElementById('otpCode').value.trim();
    try {
      await window.altFetch('api/otp.php?action=enable', { json: { code } });
      window.toast('Double authentification activée.', 'ok');
      setTimeout(() => location.reload(), 900);
    } catch (e) { window.toast(e.error || 'Code incorrect.', 'err'); }
  };

  /* OTP — désactivation */
  const otpDisable = document.getElementById('otpDisable');
  if (otpDisable) otpDisable.onclick = async () => {
    const code = document.getElementById('otpDisableCode').value.trim();
    try {
      await window.altFetch('api/otp.php?action=disable', { json: { code } });
      window.toast('Double authentification désactivée.', 'ok');
      setTimeout(() => location.reload(), 900);
    } catch (e) { window.toast(e.error || 'Échec.', 'err'); }
  };

  /* Thème */
  const ts = document.getElementById('themeSelect');
  if (ts) ts.addEventListener('change', async () => {
    const t = ts.value;
    try {
      await window.altFetch('api/profile.php?action=theme', { json: { theme: t } });
      if (t === 'auto') document.documentElement.removeAttribute('data-theme');
      else document.documentElement.setAttribute('data-theme', t);
      window.toast('Thème appliqué.', 'ok');
    } catch (e) { window.toast('Échec.', 'err'); }
  });

  const acToggle = document.getElementById('autocompleteToggle');
  if (acToggle) acToggle.addEventListener('change', async () => {
    try {
      // Coché = suggestions activées => autocomplete_off = 0
      await window.altFetch('api/profile.php?action=pref', { json: { key: 'autocomplete_off', value: acToggle.checked ? '0' : '1' } });
      if (window.ALT && window.ALT.prefs) window.ALT.prefs.autocomplete_off = acToggle.checked ? '0' : '1';
      window.toast(acToggle.checked ? 'Suggestions activées.' : 'Suggestions désactivées.', 'ok');
    } catch (e) { window.toast('Échec.', 'err'); }
  });

  /* Notifications navigateur */
  const nb = document.getElementById('notifPermBtn');
  if (nb) {
    if ('Notification' in window && Notification.permission === 'granted') { nb.textContent = 'Activées'; nb.disabled = true; }
    nb.onclick = async () => {
      if (!('Notification' in window)) { window.toast('Notifications non supportées par ce navigateur.', 'err'); return; }
      const p = await Notification.requestPermission();
      if (p === 'granted') { nb.textContent = 'Activées'; nb.disabled = true; window.toast('Notifications activées.', 'ok'); new Notification('Alternis', { body: 'Les notifications sont maintenant actives sur cet appareil.' }); }
      else window.toast('Autorisation refusée.', 'err');
    };
  }

  /* ---------- Clés d'accès (WebAuthn) ---------- */
  const waAdd = document.getElementById('waAdd');
  const waList = document.getElementById('waList');
  if (waAdd && waList) {
    if (!window.waSupported || !window.waSupported()) {
      waAdd.disabled = true;
      const u = document.getElementById('waUnsupported');
      if (u) u.hidden = false;
    }

    const fmtDate = (s) => {
      if (!s) return '';
      const d = new Date(s.replace(' ', 'T'));
      return isNaN(d) ? s : d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' });
    };

    async function loadKeys() {
      try {
        const d = await window.altFetch('api/webauthn.php?action=list');
        if (!d.items || !d.items.length) {
          waList.innerHTML = '<p class="hint">Aucune clé enregistrée pour le moment.</p>';
          return;
        }
        waList.innerHTML = d.items.map((k) =>
          '<div class="wa-item">' +
            '<span class="wa-ic"><svg viewBox="0 0 24 24" width="18" height="18" fill="none"><path d="M7 14a4 4 0 1 1 4-4M11 10h9l-2 2 2 2-3 3-2-2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>' +
            '<div class="wa-meta"><strong>' + (k.label || "Clé d'accès") + '</strong>' +
            '<span>Ajoutée le ' + fmtDate(k.created_at) + (k.last_used ? ' · utilisée le ' + fmtDate(k.last_used) : '') + '</span></div>' +
            '<button class="btn btn-danger btn-sm wa-del" data-id="' + k.id + '">Supprimer</button>' +
          '</div>'
        ).join('');
        waList.querySelectorAll('.wa-del').forEach((b) => {
          b.onclick = async () => {
            if (!(await window.altConfirm('Supprimer cette clé d\'accès ?'))) return;
            try {
              await window.altFetch('api/webauthn.php?action=delete', { json: { id: +b.dataset.id } });
              window.toast('Clé supprimée.', 'ok');
              loadKeys();
            } catch (e) { window.toast(e.error || 'Erreur.', 'err'); }
          };
        });
      } catch (e) {
        waList.innerHTML = '<p class="hint">Impossible de charger les clés.</p>';
      }
    }

    waAdd.onclick = () => {
      const inp = document.getElementById('waLabel');
      if (inp) inp.value = '';
      window.openModal('waModal');
      setTimeout(() => { if (inp) inp.focus(); }, 50);
    };

    const waConfirm = document.getElementById('waConfirm');
    if (waConfirm) waConfirm.onclick = async () => {
      const inp = document.getElementById('waLabel');
      const label = (inp && inp.value.trim()) || '';
      window.closeModal('waModal');
      // Laisse le temps à la modale de se fermer et au document de reprendre le focus
      // (WebAuthn exige un document focalisé).
      await new Promise((r) => setTimeout(r, 250));
      if (!document.hasFocus()) { try { window.focus(); } catch (e) {} }
      waAdd.disabled = true;
      const old = waAdd.textContent;
      waAdd.textContent = 'En attente…';
      try {
        await window.waRegister(label);
        window.toast("Clé d'accès enregistrée.", 'ok');
        loadKeys();
      } catch (e) {
        const msg = /not focused|document/i.test(e.message || '')
          ? "Réessayez : touchez à nouveau « Ajouter une clé » sans changer de fenêtre."
          : (e.message || 'Enregistrement annulé.');
        window.toast(msg, 'err');
      } finally {
        waAdd.disabled = false;
        waAdd.textContent = old;
      }
    };

    loadKeys();
  }

  /* ---------- Mes appareils connectés ---------- */
  const devList = document.getElementById('devList');
  if (devList) {
    const fmtDate2 = (s) => {
      if (!s) return '';
      const d = new Date(String(s).replace(' ', 'T'));
      return isNaN(d) ? s : d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' }) +
        ' à ' + d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    };
    const uaLabel = (ua) => {
      ua = ua || '';
      let os = 'Appareil';
      if (/iPhone/i.test(ua)) os = 'iPhone';
      else if (/iPad/i.test(ua)) os = 'iPad';
      else if (/Android/i.test(ua)) os = 'Android';
      else if (/Windows/i.test(ua)) os = 'Windows';
      else if (/Mac OS X|Macintosh/i.test(ua)) os = 'Mac';
      else if (/Linux/i.test(ua)) os = 'Linux';
      let br = '';
      if (/Edg\//i.test(ua)) br = 'Edge';
      else if (/OPR\/|Opera/i.test(ua)) br = 'Opera';
      else if (/Chrome\//i.test(ua)) br = 'Chrome';
      else if (/Firefox\//i.test(ua)) br = 'Firefox';
      else if (/Safari\//i.test(ua)) br = 'Safari';
      return br ? os + ' · ' + br : os;
    };

    async function loadDevices() {
      try {
        const d = await window.altFetch('api/devices.php?action=list');
        if (!d.items || !d.items.length) { devList.innerHTML = '<p class="hint">Aucun appareil mémorisé.</p>'; return; }
        devList.innerHTML = d.items.map((v) =>
          '<div class="wa-item">' +
            '<span class="wa-ic"><svg viewBox="0 0 24 24" width="18" height="18" fill="none"><rect x="5" y="2" width="14" height="20" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M11 18h2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></span>' +
            '<div class="wa-meta"><strong>' + uaLabel(v.user_agent) + (v.current ? ' <span class="tag" style="color:#16a34a">Cet appareil</span>' : '') + '</strong>' +
            '<span>' + (v.ip ? v.ip + ' · ' : '') + 'Actif le ' + fmtDate2(v.last_active) + '</span></div>' +
            '<button class="btn btn-danger btn-sm dev-revoke" data-id="' + v.id + '">' + (v.current ? 'Déconnecter' : 'Révoquer') + '</button>' +
          '</div>'
        ).join('');
        devList.querySelectorAll('.dev-revoke').forEach((b) => {
          b.onclick = async () => {
            if (!(await window.altConfirm('Révoquer cet appareil ?'))) return;
            try {
              const r = await window.altFetch('api/devices.php?action=revoke', { json: { id: +b.dataset.id } });
              if (r.current && r.redirect) { window.location.href = r.redirect; return; }
              window.toast('Appareil révoqué.', 'ok');
              loadDevices();
            } catch (e) { window.toast('Erreur.', 'err'); }
          };
        });
      } catch (e) { devList.innerHTML = '<p class="hint">Impossible de charger les appareils.</p>'; }
    }
    loadDevices();
  }
})();

/* ---------- Notifications push : état, diagnostic et test ---------- */
(function () {
  const st = document.getElementById('pushStatus');
  const testBtn = document.getElementById('notifTestBtn');
  if (!st) return;

  const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);

  function set(html) { st.innerHTML = html; }

  async function refresh() {
    if (!('Notification' in window)) { set('Ce navigateur ne gère pas les notifications.'); return; }
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) { set('Ce navigateur ne gère pas le Web Push.'); return; }
    if (isIOS && !isStandalone) {
      set('⚠️ Sur iPhone/iPad, les notifications ne fonctionnent que si Alternis est <strong>ajouté à l\'écran d\'accueil</strong> (Partager → Sur l\'écran d\'accueil), puis ouvert depuis cette icône.');
      return;
    }
    if (Notification.permission === 'denied') { set('⛔ Notifications bloquées dans les réglages du navigateur.'); return; }
    if (Notification.permission !== 'granted') { set('Notifications non activées sur cet appareil.'); return; }

    let localSub = null;
    try { const reg = await navigator.serviceWorker.ready; localSub = await reg.pushManager.getSubscription(); } catch (e) {}

    // On croise l'état du navigateur avec celui du serveur : c'est le seul
    // moyen de détecter un abonnement présent ici mais absent en base.
    let diag = null;
    try { diag = await window.altFetch('api/push.php?action=diag'); } catch (e) {}

    if (diag && diag.missing && diag.missing.length) {
      set('⛔ Le serveur ne gère pas le Web Push : ' + diag.missing.join(', ') + '.');
      return;
    }
    if (!localSub) { set('Autorisées, mais cet appareil n\'est pas abonné. Cliquez sur « Activer ».'); return; }
    if (diag && diag.subscriptions === 0) {
      set('⚠️ Abonné sur cet appareil, mais <strong>rien enregistré côté serveur</strong>. Cliquez sur « Activer » pour réessayer.');
      return;
    }
    let msg = '✅ Cet appareil est abonné (' + (diag ? diag.subscriptions : '?') + ' appareil(s) sur ce compte). Vous recevrez les notifications même application fermée.';
    if (diag && !diag.cron_configured) {
      msg += '<br><span style="color:#b45309">⚠️ La tâche cron n\'est pas encore configurée : les notifications programmées ne partiront que si quelqu\'un ouvre le site.</span>';
    } else if (diag && diag.cron_last_run) {
      msg += '<br><span class="muted" style="font-size:12.5px">Dernier passage du cron : ' + diag.cron_last_run + '</span>';
    }
    set(msg);
  }

  if (testBtn) testBtn.onclick = async () => {
    if (Notification.permission !== 'granted') { window.toast('Activez d\'abord les notifications.', 'err'); return; }
    testBtn.disabled = true;
    try {
      if (window.altEnablePush) await window.altEnablePush();   // (ré)abonne et enregistre côté serveur
      const r = await window.altFetch('api/push.php?action=test', { json: {} });
      if (r.sent > 0) {
        window.toast('Notification envoyée (' + r.sent + ' appareil' + (r.sent > 1 ? 's' : '') + ').', 'ok');
      } else if (r.reason === 'server') {
        window.altAlert(r.error, { title: 'Serveur incompatible' });
      } else if (r.reason === 'nosub') {
        window.altAlert('Le serveur n\'a aucun abonnement pour ce compte. L\'enregistrement de l\'abonnement a échoué.', { title: 'Aucun abonnement' });
      } else {
        // Remonte l'erreur exacte renvoyée par Apple / Google / Mozilla
        const d = (r.details || []).map((x) => '• ' + x.service + ' → HTTP ' + x.status + (x.error ? ' : ' + x.error : '')).join('\n');
        window.altAlert('Le service de notification a refusé l\'envoi :\n\n' + (d || 'raison inconnue'), { title: 'Échec de l\'envoi' });
      }
    } catch (e) { window.toast((e && e.error) || 'Échec de l\'envoi.', 'err'); }
    finally { testBtn.disabled = false; refresh(); }
  };

  const permBtn = document.getElementById('notifPermBtn');
  if (permBtn) permBtn.addEventListener('click', async () => {
    try {
      if (Notification.permission === 'default') await Notification.requestPermission();
      if (Notification.permission === 'granted' && window.altEnablePush) await window.altEnablePush();
    } catch (e) {}
    setTimeout(refresh, 500);
  });

  /* ---------- Collaboration ---------- */
  const esc = (s) => window.altEsc(s || '');

  // Mise à jour du profil de recherche
  // Bascule alternance / stage : sauvegarde immédiate puis recharge pour
  // appliquer le nouveau vocabulaire dans toute l'interface.
  document.querySelectorAll('#searchTypeChoice input[name="search_type"]').forEach((r) => {
    r.addEventListener('change', async () => {
      if (!r.checked) return;
      try {
        await window.altFetch('api/profile.php?action=pref', { json: { key: 'search_type', value: r.value } });
        window.toast(r.value === 'stage' ? 'Mode stage activé.' : 'Mode alternance activé.', 'ok');
        setTimeout(() => location.reload(), 700);
      } catch (err) { window.toast('Changement impossible.', 'err'); }
    });
  });
  const inviteBtn = document.getElementById('collabInviteBtn');
  const linkBox = document.getElementById('collabLinkBox');
  const linkInput = document.getElementById('collabLinkInput');

  if (inviteBtn) inviteBtn.onclick = async () => {
    inviteBtn.disabled = true;
    try {
      const d = await window.altFetch('api/collab.php?action=invite', {
        json: { can_edit: document.getElementById('collabRights').value === '1', label: document.getElementById('collabLabel').value.trim() }
      });
      linkInput.value = d.url;
      linkBox.hidden = false;
      window.toast('Lien d\'invitation créé.', 'ok');
      loadCollab();
    } catch (e) { window.toast('Création impossible.', 'err'); }
    finally { inviteBtn.disabled = false; }
  };
  const copyBtn = document.getElementById('collabCopyBtn');
  if (copyBtn) copyBtn.onclick = () => {
    linkInput.select();
    navigator.clipboard?.writeText(linkInput.value).then(() => window.toast('Lien copié.', 'ok'));
  };

  async function loadCollab() {
    const listEl = document.getElementById('collabList');
    const accessEl = document.getElementById('collabAccessList');
    if (!listEl) return;
    const initials = (n) => (n || '?').trim().split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
    try {
      const d = await window.altFetch('api/collab.php?action=list');

      // Personnes ayant accès à mes données
      const active = d.collaborators || [];
      const pending = (d.invites || []).filter((i) => !i.accepted_by && +i.revoked === 0);
      let html = '';
      active.forEach((c) => {
        const name = c.full_name || c.username;
        html += `<div class="collab-item"><div class="ci-main"><div class="ci-av">${esc(initials(name))}</div><div class="ci-txt"><strong>${esc(name)}</strong><span>A rejoint votre espace</span></div></div><div class="collab-actions"><span class="collab-badge ${+c.can_edit ? 'edit' : 'read'}">${+c.can_edit ? 'Peut modifier' : 'Lecture seule'}</span><button class="btn btn-danger btn-sm" data-remove="${c.collab_id}">Retirer</button></div></div>`;
      });
      pending.forEach((i) => {
        html += `<div class="collab-item"><div class="ci-main"><div class="ci-av" style="background:var(--surface-2);color:var(--muted)">⏳</div><div class="ci-txt"><strong>Invitation en attente</strong><span>${esc(i.label || 'Lien non encore utilisé')}</span></div></div><div class="collab-actions"><span class="collab-badge pending">${+i.can_edit ? 'Modification' : 'Lecture'}</span><button class="btn btn-ghost btn-sm" data-revoke="${i.id}">Révoquer</button></div></div>`;
      });
      listEl.innerHTML = html || '<div class="collab-empty">Personne n\'a encore accès à vos recherches.<br>Générez un lien d\'invitation ci-dessus pour commencer.</div>';

      listEl.querySelectorAll('[data-remove]').forEach((b) => b.onclick = async () => {
        if (!(await window.altConfirm('Retirer l\'accès de cette personne ?'))) return;
        await window.altFetch('api/collab.php?action=remove_collab', { json: { collab_id: +b.dataset.remove } });
        window.toast('Accès retiré.', 'ok'); loadCollab();
      });
      listEl.querySelectorAll('[data-revoke]').forEach((b) => b.onclick = async () => {
        await window.altFetch('api/collab.php?action=revoke', { json: { id: +b.dataset.revoke } });
        window.toast('Invitation révoquée.', 'ok'); loadCollab();
      });

      // Espaces auxquels j'ai accès
      const access = d.access_to || [];
      if (!access.length) {
        accessEl.innerHTML = '<div class="collab-empty">Vous n\'avez accès à aucune autre recherche pour l\'instant.</div>';
      } else {
        accessEl.innerHTML = access.map((a) => {
          const name = a.full_name || a.username;
          const isCurrent = +d.current_owner === +a.owner_id;
          const badge = isCurrent ? '<span class="collab-badge active">Vue active</span>' : `<span class="collab-badge ${+a.can_edit ? 'edit' : 'read'}">${+a.can_edit ? 'Peut modifier' : 'Lecture seule'}</span>`;
          const openBtn = isCurrent ? `<button class="btn btn-ghost btn-sm" data-switch="0">Revenir à moi</button>` : `<button class="btn btn-primary btn-sm" data-switch="${a.owner_id}">Ouvrir</button>`;
          return `<div class="collab-item"><div class="ci-main"><div class="ci-av">${esc(initials(name))}</div><div class="ci-txt"><strong>${esc(name)}</strong><span>Espace partagé</span></div></div><div class="collab-actions">${badge}${openBtn}<button class="btn btn-ghost btn-sm" data-leave="${a.owner_id}">Quitter</button></div></div>`;
        }).join('');
        accessEl.querySelectorAll('[data-switch]').forEach((b) => b.onclick = async () => {
          await window.altFetch('api/collab.php?action=switch', { json: { owner_id: +b.dataset.switch } });
          location.href = window.altApi('index.php?page=dashboard');
        });
        accessEl.querySelectorAll('[data-leave]').forEach((b) => b.onclick = async () => {
          if (!(await window.altConfirm('Quitter cet espace partagé ?'))) return;
          await window.altFetch('api/collab.php?action=leave', { json: { owner_id: +b.dataset.leave } });
          window.toast('Vous avez quitté cet espace.', 'ok'); loadCollab();
        });
      }
    } catch (e) { listEl.innerHTML = '<div class="collab-empty">Chargement impossible.</div>'; }
  }
  // Charge la collaboration quand on ouvre l'onglet
  document.querySelectorAll('[data-tab="collaboration"]').forEach((t) => t.addEventListener('click', loadCollab));
  if (location.hash === '#collaboration') loadCollab();

  /* ---------- Mots de passe d'application (extension) ---------- */
  async function loadAppPw() {
    const el = document.getElementById('appPwList');
    if (!el) return;
    try {
      const d = await window.altFetch('api/profile.php?action=app_pw_list');
      const items = d.items || [];
      if (!items.length) { el.innerHTML = '<p class="muted">Aucun mot de passe d\'application pour l\'instant.</p>'; return; }
      el.innerHTML = items.map((p) => {
        const used = p.last_used ? ('utilisé le ' + p.last_used.slice(0, 10)) : 'jamais utilisé';
        return `<div class="setting-row"><div class="st-txt"><strong>${esc(p.label || 'Extension')}</strong><p>Créé le ${(p.created_at || '').slice(0, 10)} · ${used}</p></div><button class="btn btn-danger btn-sm" data-delpw="${p.id}">Révoquer</button></div>`;
      }).join('');
      el.querySelectorAll('[data-delpw]').forEach((b) => b.onclick = async () => {
        if (!(await window.altConfirm('Révoquer ce mot de passe ? L\'appareil qui l\'utilise sera déconnecté.'))) return;
        await window.altFetch('api/profile.php?action=app_pw_delete', { json: { id: +b.dataset.delpw } });
        window.toast('Mot de passe révoqué.', 'ok'); loadAppPw();
      });
    } catch (e) { el.innerHTML = '<p class="muted">Chargement impossible.</p>'; }
  }
  const appPwCreate = document.getElementById('appPwCreate');
  if (appPwCreate) appPwCreate.onclick = async () => {
    appPwCreate.disabled = true;
    try {
      const d = await window.altFetch('api/profile.php?action=app_pw_create', { json: { label: document.getElementById('appPwLabel').value.trim() } });
      document.getElementById('appPwValue').textContent = d.secret;
      document.getElementById('appPwReveal').hidden = false;
      document.getElementById('appPwLabel').value = '';
      loadAppPw();
    } catch (e) { window.toast('Création impossible.', 'err'); }
    finally { appPwCreate.disabled = false; }
  };
  const appPwCopy = document.getElementById('appPwCopy');
  if (appPwCopy) appPwCopy.onclick = () => {
    const v = document.getElementById('appPwValue').textContent;
    navigator.clipboard?.writeText(v).then(() => window.toast('Copié.', 'ok'));
  };
  document.querySelectorAll('[data-tab="extension"]').forEach((t) => t.addEventListener('click', loadAppPw));
  if (location.hash === '#extension') loadAppPw();

  /* ---------- Relances : compte Gmail personnel ---------- */
  const gmailForm = document.getElementById('gmailForm');
  const gmailStatus = document.getElementById('gmailStatus');
  const gmailTestBtn = document.getElementById('gmailTest');
  const gmailDelBtn = document.getElementById('gmailDelete');
  const autoCard = document.getElementById('autoCard');
  const followupAuto = document.getElementById('followupAuto');

  async function loadGmail() {
    if (!gmailForm) return;
    try {
      const d = await window.altFetch('api/profile.php?action=gmail_get');
      const configured = !!d.configured;
      if (configured) {
        document.getElementById('gmailUser').value = d.user || '';
        document.getElementById('gmailFromName').value = d.from_name || '';
        document.getElementById('gmailPass').placeholder = '•••••••••••• (enregistré)';
        gmailStatus.hidden = false;
        gmailStatus.className = 'gmail-status ok';
        gmailStatus.innerHTML = '✓ Connecté à <strong>' + esc(d.user) + '</strong>. Vos relances partiront de cette adresse.';
        gmailTestBtn.hidden = false;
        gmailDelBtn.hidden = false;
        autoCard.hidden = false;
        if (followupAuto) followupAuto.checked = !!d.auto;
      } else {
        gmailStatus.hidden = true;
        gmailTestBtn.hidden = true;
        gmailDelBtn.hidden = true;
        autoCard.hidden = true;
      }
    } catch (e) { /* onglet non ouvert */ }
  }

  if (gmailForm) {
    gmailForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = document.getElementById('gmailSave');
      btn.disabled = true;
      try {
        await window.altFetch('api/profile.php?action=gmail_save', {
          json: {
            user: document.getElementById('gmailUser').value.trim(),
            pass: document.getElementById('gmailPass').value,
            from_name: document.getElementById('gmailFromName').value.trim(),
          },
        });
        document.getElementById('gmailPass').value = '';
        window.toast('Adresse Gmail enregistrée.', 'ok');
        loadGmail();
      } catch (err) { window.toast((err && err.error) || 'Enregistrement impossible.', 'err'); }
      finally { btn.disabled = false; }
    });
  }
  if (gmailTestBtn) gmailTestBtn.onclick = async () => {
    gmailTestBtn.disabled = true;
    const old = gmailTestBtn.textContent;
    gmailTestBtn.textContent = 'Envoi…';
    try {
      const d = await window.altFetch('api/profile.php?action=gmail_test');
      window.toast('E-mail de test envoyé à ' + (d.sent_to || 'votre adresse') + '.', 'ok');
    } catch (err) { window.toast((err && err.error) || 'Échec du test.', 'err'); }
    finally { gmailTestBtn.disabled = false; gmailTestBtn.textContent = old; }
  };
  if (gmailDelBtn) gmailDelBtn.onclick = async () => {
    if (!(await window.altConfirm('Déconnecter votre adresse Gmail ? Les relances automatiques seront désactivées.'))) return;
    try {
      await window.altFetch('api/profile.php?action=gmail_delete', { json: {} });
      document.getElementById('gmailUser').value = '';
      document.getElementById('gmailFromName').value = '';
      document.getElementById('gmailPass').value = '';
      window.toast('Adresse Gmail déconnectée.', 'ok');
      loadGmail();
    } catch (err) { window.toast('Impossible de déconnecter.', 'err'); }
  };
  if (followupAuto) followupAuto.addEventListener('change', async () => {
    try {
      await window.altFetch('api/profile.php?action=pref', { json: { key: 'followup_auto', value: followupAuto.checked ? '1' : '0' } });
      window.toast(followupAuto.checked ? 'Relances automatiques activées.' : 'Relances automatiques désactivées.', 'ok');
    } catch (err) { window.toast('Changement impossible.', 'err'); followupAuto.checked = !followupAuto.checked; }
  });
  document.querySelectorAll('[data-tab="relances"]').forEach((t) => t.addEventListener('click', loadGmail));
  if (location.hash === '#relances') loadGmail();

  /* ---------- Personnalisation : widgets du tableau de bord ---------- */
  const widgetList = document.getElementById('widgetList');
  const widgetsReset = document.getElementById('widgetsReset');
  const widgetPreview = document.getElementById('widgetPreview');
  const WD = window.DASH_WIDGETS || { catalog: {}, default: [], current: [] };

  // Petite icône générique par widget pour l'aperçu
  const WICONS = {
    total:'▦', followups:'✉', todo:'☑', response:'📈', interviews:'📅', accepted:'✓',
    week:'📆', month:'🗓', pending:'⏳', relanced:'↻', refused:'✕', drafts:'✎',
    interviewRate:'📊', successRate:'🎯', companies:'🏢'
  };

  function renderPreview() {
    if (!widgetPreview) return;
    const active = [...widgetList.querySelectorAll('.widget-row')]
      .filter((r) => r.querySelector('input').checked)
      .map((r) => r.dataset.key);
    if (!active.length) { widgetPreview.innerHTML = '<span class="wp-empty">Aucun widget sélectionné.</span>'; return; }
    widgetPreview.innerHTML = active.map((k) =>
      `<div class="wp-tile"><span class="wp-ic">${WICONS[k] || '•'}</span><span class="wp-lab">${WD.catalog[k] || k}</span></div>`
    ).join('');
  }

  function saveWidgets(order) {
    try {
      window.altFetch('api/profile.php?action=pref', { json: { key: 'dashboard_widgets', value: order.join(',') } });
    } catch (e) {}
  }

  function currentOrder() {
    return [...widgetList.querySelectorAll('.widget-row')].map((r) => r.dataset.key);
  }

  function renderWidgets(active) {
    if (!widgetList) return;
    // active = liste ordonnée des clés cochées ; on ajoute ensuite les non cochées
    const cat = WD.catalog;
    const ordered = active.filter((k) => cat[k]).concat(Object.keys(cat).filter((k) => !active.includes(k)));
    widgetList.innerHTML = ordered.map((k) => {
      const on = active.includes(k);
      return `<div class="widget-row" data-key="${k}" draggable="true">
        <span class="widget-grip" title="Glisser pour réordonner">⠿</span>
        <label class="widget-name"><input type="checkbox" ${on ? 'checked' : ''}> ${cat[k]}</label>
      </div>`;
    }).join('');
    bindWidgetRows();
    renderPreview();
  }

  function persist() {
    // Ordre = lignes cochées dans leur ordre d'affichage
    const order = currentOrder().filter((k) => {
      const cb = widgetList.querySelector(`.widget-row[data-key="${k}"] input`);
      return cb && cb.checked;
    });
    renderPreview();
    if (!order.length) { window.toast('Gardez au moins un widget affiché.', 'err'); return false; }
    saveWidgets(order);
    window.toast('Tableau de bord mis à jour.', 'ok');
    return true;
  }

  let dragEl = null;
  function bindWidgetRows() {
    widgetList.querySelectorAll('.widget-row input').forEach((cb) => {
      cb.addEventListener('change', persist);
    });
    widgetList.querySelectorAll('.widget-row').forEach((row) => {
      row.addEventListener('dragstart', () => { dragEl = row; row.classList.add('dragging'); });
      row.addEventListener('dragend', () => { row.classList.remove('dragging'); persist(); });
      row.addEventListener('dragover', (e) => {
        e.preventDefault();
        const after = [...widgetList.querySelectorAll('.widget-row:not(.dragging)')].find((r) => {
          const box = r.getBoundingClientRect();
          return e.clientY < box.top + box.height / 2;
        });
        if (after) widgetList.insertBefore(dragEl, after);
        else widgetList.appendChild(dragEl);
        renderPreview();
      });
    });
  }

  function loadWidgets() {
    if (!widgetList || !WD.catalog) return;
    renderWidgets(WD.current && WD.current.length ? WD.current : WD.default);
  }

  if (widgetsReset) widgetsReset.onclick = async () => {
    if (!(await window.altConfirm('Réinitialiser le tableau de bord à la vue par défaut ?'))) return;
    renderWidgets(WD.default);
    saveWidgets(WD.default);
    window.toast('Vue par défaut rétablie.', 'ok');
  };
  document.querySelectorAll('[data-tab="personnalisation"]').forEach((t) => t.addEventListener('click', loadWidgets));
  if (location.hash === '#personnalisation') loadWidgets();

  refresh();
})();
