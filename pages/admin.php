<?php
require_once __DIR__ . '/../includes/auth.php';
$__user = require_admin();
$__owner = data_owner_id();
$__active = 'admin';
$__pageTitle = 'Administration';
$__pageScripts = ['assets/js/admin.js'];
$statusLabels = status_labels();
require __DIR__ . '/../includes/header.php';
?>

<div class="section-title">
  <div><h2 style="font-size:22px">Administration</h2>
    <p class="muted" style="margin-top:2px">Comptes, notifications, messages et réglages du site.</p></div>
  <?php if ($__owner != $__user['id']): ?>
    <button class="btn btn-ghost btn-sm" id="stopViewAs">Revenir à mes données</button>
  <?php endif; ?>
</div>

<div class="scrape-tabs" id="adminTabs">
  <button class="scrape-tab active" data-tab="accounts">Comptes</button>
  <button class="scrape-tab" data-tab="notifs">Notifications</button>
  <button class="scrape-tab" data-tab="messages">Messages &amp; bannières</button>
  <button class="scrape-tab" data-tab="settings">Réglages du site</button>
</div>

<!-- ===================== COMPTES ===================== -->
<section class="admin-pane" data-pane="accounts">
  <div class="section-title"><h2>Comptes utilisateurs</h2>
    <button class="btn btn-primary btn-sm" id="btnNewUser">+ Nouveau compte</button></div>
  <div class="card" data-reveal>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Utilisateur</th><th>Rôle</th><th>Lié à</th><th>Candid.</th><th>OTP</th><th>Connexion</th><th>Statut</th><th></th></tr></thead>
        <tbody id="userBody"><tr><td colspan="8" style="text-align:center;padding:40px" class="muted">Chargement…</td></tr></tbody>
      </table>
    </div>
  </div>
</section>

<!-- ===================== NOTIFICATIONS ===================== -->
<section class="admin-pane" data-pane="notifs" hidden>
  <div class="grid" style="grid-template-columns:1fr 1fr;gap:18px" id="notifGrid">
    <div class="card card-pad" data-reveal>
      <div class="section-title"><h2>Notification immédiate</h2></div>
      <div class="field"><label>Destinataire</label><select id="nTarget"><option value="">Tous les utilisateurs</option></select></div>
      <div class="field"><label>Titre</label><input id="nTitle" placeholder="ex. Nouvelle fonctionnalité"></div>
      <div class="field"><label>Message</label><textarea id="nBody" placeholder="Votre message…"></textarea></div>
      <button class="btn btn-primary" id="nSend">Envoyer maintenant</button>
    </div>
    <div class="card card-pad" data-reveal>
      <div class="section-title"><h2>Notification programmée</h2></div>
      <div class="field"><label>Destinataire</label><select id="sTarget"><option value="">Tous les utilisateurs</option></select></div>
      <div class="field"><label>Titre</label><input id="sTitle" placeholder="Titre"></div>
      <div class="field"><label>Message</label><textarea id="sBody" placeholder="Message…"></textarea></div>
      <div class="field"><label>Fréquence</label>
        <select id="sFreq"><option value="once">Une seule fois</option><option value="daily">Tous les jours</option><option value="weekly">Toutes les semaines</option></select></div>
      <div class="field" id="sOnceWrap"><label>Date et heure d'envoi</label><input type="datetime-local" id="sRunAt"></div>
      <div class="field" id="sTimeWrap" hidden><label>Heure d'envoi</label><input type="time" id="sRunTime" value="09:00"></div>
      <div class="field" id="sDowWrap" hidden><label>Jour de la semaine</label>
        <select id="sDow"><option value="1">Lundi</option><option value="2">Mardi</option><option value="3">Mercredi</option><option value="4">Jeudi</option><option value="5">Vendredi</option><option value="6">Samedi</option><option value="0">Dimanche</option></select></div>
      <button class="btn btn-primary" id="sSave">Programmer</button>
    </div>
  </div>
  <div class="card card-pad mt" data-reveal>
    <div class="section-title"><h2>Notifications programmées</h2></div>
    <div id="schedList"><p class="hint">Chargement…</p></div>
  </div>
</section>

<!-- ===================== MESSAGES ===================== -->
<section class="admin-pane" data-pane="messages" hidden>
  <div class="card card-pad" data-reveal>
    <div class="section-title"><h2>Nouveau message</h2></div>
    <div class="form-grid">
      <div class="field"><label>Type</label><select id="mKind"><option value="popup">Pop-up (au centre)</option><option value="banner">Bannière (en haut)</option></select></div>
      <div class="field"><label>Destinataire</label><select id="mTarget"><option value="">Tous les utilisateurs</option></select></div>
      <div class="field"><label>Couleur</label><select id="mColor"><option value="accent">Violet</option><option value="info">Bleu</option><option value="success">Vert</option><option value="warn">Orange</option><option value="danger">Rouge</option></select></div>
      <div class="field"><label>Expire le (facultatif)</label><input type="datetime-local" id="mEnds"></div>
      <div class="field full"><label>Titre</label><input id="mTitle" placeholder="Titre du message"></div>
      <div class="field full"><label>Message</label><textarea id="mBody" placeholder="Contenu…"></textarea></div>
      <div class="field full"><label class="chk"><input type="checkbox" id="mDismiss" checked> Le message peut être fermé par l'utilisateur</label>
        <p class="hint">Décochez pour un message bloquant (visible tant que vous ne le masquez pas).</p></div>
    </div>
    <button class="btn btn-primary" id="mSave">Publier</button>
  </div>
  <div class="card card-pad mt" data-reveal>
    <div class="section-title"><h2>Messages publiés</h2></div>
    <div id="msgList"><p class="hint">Chargement…</p></div>
  </div>
</section>

<!-- ===================== RÉGLAGES ===================== -->
<section class="admin-pane" data-pane="settings" hidden>
  <div class="card card-pad" data-reveal>
    <div class="section-title"><h2>Mode maintenance</h2></div>
    <div class="setting-row">
      <div class="st-txt"><strong>Activer la maintenance</strong><p>Les utilisateurs voient une page de maintenance. Vous, administrateur, gardez l'accès.</p></div>
      <label class="switch"><input type="checkbox" id="setMaintenance"><span class="sl"></span></label>
    </div>
    <div class="field"><label>Message de maintenance</label><textarea id="setMaintMsg" placeholder="Message affiché aux utilisateurs…"></textarea></div>
  </div>
  <div class="card card-pad mt" data-reveal>
    <div class="section-title"><h2>Personnalisation de la page de connexion</h2></div>
    <div class="field"><label>Titre principal</label><input id="setLoginTitle" placeholder="Votre recherche d'alternance, enfin organisée."></div>
    <div class="field"><label>Sous-titre</label><input id="setLoginSubtitle" placeholder="Centralisez vos candidatures…"></div>
    <div class="field"><label>Bandeau d'information (au-dessus du formulaire)</label><input id="setLoginNotice" placeholder="ex. Bienvenue à la promo 2026 !"></div>
    <div class="field"><label>Couleur d'accent (hex, ex. #7c5cfc)</label><input id="setLoginAccent" placeholder="#7c5cfc"></div>
    <button class="btn btn-primary" id="setSave">Enregistrer les réglages</button>
  </div>
</section>

<!-- ===================== MODALES ===================== -->
<div class="modal-scrim" id="userModal">
  <div class="modal">
    <div class="modal-head"><h3 id="userModalTitle">Nouveau compte</h3><button class="close-x" data-close="userModal">✕</button></div>
    <div class="modal-body">
      <form id="userForm">
        <input type="hidden" name="id" id="userId">
        <div class="form-grid">
          <div class="field"><label>Nom complet</label><input name="full_name" placeholder="Prénom Nom"></div>
          <div class="field"><label>Identifiant *</label><input name="username" placeholder="jdupont"></div>
          <div class="field"><label>E-mail *</label><input name="email" type="email" placeholder="email@exemple.fr"></div>
          <div class="field"><label>Rôle</label>
            <select name="role" id="roleSelect"><option value="student">Étudiant</option><option value="parent">Parent / Suiveur</option><option value="admin">Administrateur</option></select></div>
          <div class="field" id="linkField" hidden><label>Personne suivie</label>
            <select name="linked_student_id" id="linkSelect"><option value="">—</option></select></div>
        </div>
        <div id="createOnly">
          <div class="field"><label>Mot de passe</label><input name="password" id="userPwd" placeholder="8 caractères min."></div>
          <label class="chk" style="margin:4px 0"><input type="checkbox" id="genPwd"> Générer un mot de passe automatiquement</label>
          <label class="chk" style="margin:4px 0"><input type="checkbox" id="mustChange" checked> Forcer le changement à la 1re connexion</label>
          <label class="chk" style="margin:4px 0"><input type="checkbox" id="sendEmail" checked> Envoyer les identifiants par e-mail</label>
        </div>
      </form>
    </div>
    <div class="modal-foot"><button class="btn btn-ghost" data-close="userModal">Annuler</button>
      <button class="btn btn-primary" id="userSave">Créer le compte</button></div>
  </div>
</div>

<div class="modal-scrim" id="lockModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-head"><h3>Verrouiller le compte</h3><button class="close-x" data-close="lockModal">✕</button></div>
    <div class="modal-body">
      <input type="hidden" id="lockUserId">
      <p class="muted mb" id="lockUserName"></p>
      <div class="field"><label>Message affiché à la connexion (facultatif)</label><textarea id="lockMsg" placeholder="ex. Compte suspendu, contactez l'administration."></textarea></div>
    </div>
    <div class="modal-foot"><button class="btn btn-ghost" data-close="lockModal">Annuler</button>
      <button class="btn btn-danger" id="lockGo">Verrouiller</button></div>
  </div>
</div>

<div class="modal-scrim" id="relinkModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-head"><h3>Personne suivie</h3><button class="close-x" data-close="relinkModal">✕</button></div>
    <div class="modal-body"><input type="hidden" id="relinkUserId"><p class="muted mb" id="relinkUserName"></p>
      <div class="field"><label>Suivre</label><select id="relinkSelect"><option value="">Chargement…</option></select></div></div>
    <div class="modal-foot"><button class="btn btn-ghost" data-close="relinkModal">Annuler</button>
      <button class="btn btn-primary" id="relinkSave">Enregistrer</button></div>
  </div>
</div>

<div class="modal-scrim" id="resetModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-head"><h3>Réinitialiser le mot de passe</h3><button class="close-x" data-close="resetModal">✕</button></div>
    <div class="modal-body"><input type="hidden" id="resetUserId"><p class="muted mb" id="resetUserName"></p>
      <div class="field"><label>Nouveau mot de passe</label><input type="text" id="resetPwd" placeholder="8 caractères min."></div></div>
    <div class="modal-foot"><button class="btn btn-ghost" data-close="resetModal">Annuler</button>
      <button class="btn btn-primary" id="resetSave">Réinitialiser</button></div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
