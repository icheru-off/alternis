<?php
require_once __DIR__ . '/../includes/auth.php';
$__user = require_login();
$__active = 'settings';
$__pageTitle = 'Paramètres';

$firstName = preg_split('/\s+/', trim($__user['full_name'] ?: $__user['username']))[0];
$__pageScripts = ['assets/js/qrcode.min.js', 'assets/js/webauthn.js', 'assets/js/settings.js'];
// Catalogue des widgets du tableau de bord (libellés) + config par défaut + config utilisateur
$__widgetCatalog = [
    'total'=>'Candidatures au total', 'followups'=>'Relances à faire', 'todo'=>'Offres à faire',
    'response'=>'Taux de réponse', 'interviews'=>'Entretiens en cours', 'accepted'=>'Alternances/Stages décrochées',
    'week'=>'Envoyées cette semaine', 'month'=>'Envoyées ce mois', 'pending'=>'En attente de réponse',
    'relanced'=>'Relancées', 'refused'=>'Refusées', 'drafts'=>'Brouillons',
    'interviewRate'=>"Taux d'entretien", 'successRate'=>'Taux de réussite', 'companies'=>'Entreprises distinctes',
];
$__widgetDefault = ['total','followups','todo','response','interviews','accepted'];
$__widgetCurrent = [];
try { $wp = user_pref_get((int)$__user['id'], 'dashboard_widgets', ''); if ($wp) $__widgetCurrent = array_values(array_filter(explode(',', $wp))); } catch (Throwable $e) {}
if (!$__widgetCurrent) $__widgetCurrent = $__widgetDefault;
$__inlineScript = 'window.WA={base:(window.ALT&&window.ALT.base)||"",csrf:(window.ALT&&window.ALT.csrf)||""};'
  . 'window.DASH_WIDGETS=' . json_encode(['catalog'=>$__widgetCatalog,'default'=>$__widgetDefault,'current'=>$__widgetCurrent], JSON_UNESCAPED_UNICODE) . ';';

require __DIR__ . '/../includes/header.php';
?>

<div class="settings-grid">
  <nav class="settings-nav">
    <a href="#profil" class="active" data-tab="profil">Profil</a>
    <a href="#securite" data-tab="securite">Sécurité</a>
    <a href="#preferences" data-tab="preferences">Préférences</a>
    <a href="#personnalisation" data-tab="personnalisation">Personnalisation</a>
    <a href="#collaboration" data-tab="collaboration">Collaboration</a>
    <a href="#relances" data-tab="relances">Relances</a>
    <a href="#extension" data-tab="extension">Extension</a>
  </nav>

  <div>
    <!-- ===== Profil ===== -->
    <section id="tab-profil" class="tab-panel">
      <div class="card card-pad mb" data-reveal>
        <div class="section-title"><h2>Photo & identité</h2></div>
        <div class="avatar-edit">
          <div class="avatar-big" id="avatarPreview">
            <?php if ($__user['avatar']): ?>
              <img src="<?= e(url('api/avatar.php?u=' . (int)$__user['id'] . '&v=' . rawurlencode($__user['avatar']))) ?>" alt="">
            <?php else: ?>
              <span class="avatar-fallback"><?= e(mb_strtoupper(mb_substr($firstName, 0, 1))) ?></span>
            <?php endif; ?>
          </div>
          <div>
            <input type="file" id="avatarInput" accept="image/*" hidden>
            <button class="btn btn-ghost btn-sm" onclick="document.getElementById('avatarInput').click()">Changer la photo</button>
            <button class="btn btn-ghost btn-sm" id="avatarDelete" type="button" style="color:#dc2626">Supprimer</button>
            <p class="hint mt">JPG, PNG ou WEBP. 3 Mo maximum.</p>
          </div>
        </div>
        <form id="profileForm">
          <div class="form-grid">
            <div class="field"><label>Nom complet</label><input name="full_name" value="<?= e($__user['full_name']) ?>"></div>
            <div class="field"><label>E-mail</label><input name="email" type="email" value="<?= e($__user['email']) ?>"></div>
          </div>
          <div class="field"><label>Identifiant de connexion</label><input value="<?= e($__user['username']) ?>" disabled><span class="hint">L'identifiant ne peut pas être modifié.</span></div>
          <div class="field"><label>Rôle</label><input value="<?= e(role_label($__user['role'])) ?>" disabled></div>
          <button class="btn btn-primary" id="profileSave">Enregistrer les modifications</button>
        </form>
      </div>

      <div class="card card-pad mb" data-reveal>
        <div class="section-title"><h2>Type de recherche</h2></div>
        <p class="muted" style="margin-top:-6px">Vous pouvez passer d'une recherche d'alternance à une recherche de stage à tout moment. Le vocabulaire de l'application s'adapte.</p>
        <?php $__st = search_type((int)$__user['id']); ?>
        <div class="regtype" id="searchTypeChoice">
          <label class="regtype-opt">
            <input type="radio" name="search_type" value="alternance" <?= $__st !== 'stage' ? 'checked' : '' ?>>
            <span class="regtype-card"><span class="regtype-ic">🎓</span><strong>Alternance</strong><small>Apprentissage, professionnalisation</small></span>
          </label>
          <label class="regtype-opt">
            <input type="radio" name="search_type" value="stage" <?= $__st === 'stage' ? 'checked' : '' ?>>
            <span class="regtype-card"><span class="regtype-ic">💼</span><strong>Stage</strong><small>Conventionné, césure, fin d'études</small></span>
          </label>
        </div>
      </div>
    </section>

    <!-- ===== Sécurité ===== -->
    <section id="tab-securite" class="tab-panel" hidden>
      <div class="card card-pad mb" data-reveal>
        <div class="section-title"><h2>Mot de passe</h2></div>
        <form id="pwdForm" style="max-width:440px">
          <div class="field"><label>Mot de passe actuel</label><input type="password" name="current" required></div>
          <div class="field"><label>Nouveau mot de passe</label><input type="password" name="new" required minlength="8"><span class="hint">Au moins 8 caractères.</span></div>
          <div class="field"><label>Confirmer le nouveau mot de passe</label><input type="password" name="confirm" required></div>
          <button class="btn btn-primary" id="pwdSave">Mettre à jour le mot de passe</button>
        </form>
      </div>

      <div class="card card-pad" data-reveal>
        <div class="setting-row" style="border:none;padding-top:0">
          <div class="st-txt">
            <strong>Vérification en deux étapes (OTP)</strong>
            <p>Ajoutez un code à usage unique à la connexion, via une application comme Google Authenticator ou Authy.</p>
          </div>
          <span class="badge" id="otpBadge" style="<?= $__user['otp_enabled'] ? 'background:rgba(34,197,94,.12);color:#16a34a' : 'background:var(--surface-2);color:var(--muted)' ?>">
            <span class="dot"></span><?= $__user['otp_enabled'] ? 'Activée' : 'Désactivée' ?>
          </span>
        </div>

        <?php if (!$__user['otp_enabled']): ?>
          <button class="btn btn-primary mt" id="otpStart">Activer la double authentification</button>
          <div id="otpSetup" hidden style="margin-top:20px">
            <div class="qr-box">
              <div class="qr-render" id="qrRender"></div>
              <div>
                <strong>1. Scannez le QR code</strong>
                <p class="muted" style="margin:6px 0 14px">Ou saisissez cette clé manuellement :</p>
                <code class="otp-secret" id="otpSecret"></code>
                <div class="field mt" style="max-width:220px">
                  <label>2. Entrez le code à 6 chiffres</label>
                  <input id="otpCode" inputmode="numeric" maxlength="6" placeholder="000000">
                </div>
                <button class="btn btn-primary btn-sm" id="otpConfirm">Confirmer et activer</button>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div id="otpDisableBox" style="margin-top:16px;max-width:260px">
            <div class="field"><label>Code actuel pour désactiver</label><input id="otpDisableCode" inputmode="numeric" maxlength="6" placeholder="000000"></div>
            <button class="btn btn-danger btn-sm" id="otpDisable">Désactiver l'OTP</button>
          </div>
        <?php endif; ?>
      </div>

      <!-- Clés d'accès (passkeys / WebAuthn) -->
      <div class="card card-pad mt" data-reveal>
        <div class="setting-row" style="border:none;padding-top:0">
          <div class="st-txt">
            <strong>Clés d'accès (Face ID, Touch ID, Windows Hello…)</strong>
            <p>Connectez-vous sans mot de passe grâce à la biométrie de votre appareil ou à une clé de sécurité. Plus rapide et plus sûr.</p>
          </div>
          <button class="btn btn-primary btn-sm" id="waAdd">Ajouter une clé</button>
        </div>
        <div id="waUnsupported" class="hint" hidden style="margin-top:6px">Cet appareil ou navigateur ne prend pas en charge les clés d'accès.</div>
        <div id="waList" class="wa-list" style="margin-top:14px"></div>
      </div>

      <!-- Mes appareils connectés -->
      <div class="card card-pad mt" data-reveal>
        <div class="setting-row" style="border:none;padding-top:0">
          <div class="st-txt">
            <strong>Mes appareils connectés</strong>
            <p>Appareils où votre session reste active (« se souvenir de moi »). Révoquez ceux que vous ne reconnaissez pas.</p>
          </div>
        </div>
        <div id="devList" class="wa-list" style="margin-top:8px"></div>
      </div>
    </section>

    <!-- ===== Préférences ===== -->
    <section id="tab-preferences" class="tab-panel" hidden>
      <div class="card card-pad" data-reveal>
        <div class="section-title"><h2>Apparence & notifications</h2></div>
        <div class="setting-row">
          <div class="st-txt"><strong>Thème</strong><p>Par défaut, Alternis suit automatiquement le mode clair/sombre de votre système.</p></div>
          <select id="themeSelect" style="width:auto;min-width:140px">
            <option value="auto"<?= $__user['theme_pref']==='auto'?' selected':'' ?>>Automatique</option>
            <option value="light"<?= $__user['theme_pref']==='light'?' selected':'' ?>>Clair</option>
            <option value="dark"<?= $__user['theme_pref']==='dark'?' selected':'' ?>>Sombre</option>
          </select>
        </div>
        <div class="setting-row">
          <div class="st-txt"><strong>Notifications du navigateur</strong><p>Recevez vos rappels sur cet appareil, <strong>même application fermée</strong>. Sur iPhone/iPad, ajoutez d'abord Alternis à l'écran d'accueil.</p></div>
          <div class="row-actions" style="display:flex;gap:8px">
            <button class="btn btn-ghost btn-sm" id="notifPermBtn">Activer</button>
            <button class="btn btn-ghost btn-sm" id="notifTestBtn">Tester</button>
          </div>
        </div>
        <div class="setting-row">
          <div class="st-txt"><strong>État des notifications</strong><p id="pushStatus" class="muted">Vérification…</p></div>
        </div>
      </div>

      <div class="card card-pad" data-reveal>
        <div class="section-title"><h2>Aide à la saisie</h2></div>
        <div class="setting-row">
          <div class="st-txt"><strong>Suggestions d'entreprise</strong><p>Quand vous tapez le nom d'une entreprise, Alternis propose des correspondances et complète les champs si vous en choisissez une.</p></div>
          <label class="switch">
            <input type="checkbox" id="autocompleteToggle" <?= (($__prefs['autocomplete_off'] ?? '0') !== '1') ? 'checked' : '' ?>>
            <span class="sl"></span>
          </label>
        </div>
        <div class="setting-row">
          <div class="st-txt"><strong>Tutoriel d'accueil</strong><p>Revoir la présentation des fonctionnalités d'Alternis.</p></div>
          <a class="btn btn-ghost btn-sm" href="<?= e(url('index.php?onboarding=1')) ?>">Revoir le tutoriel</a>
        </div>
      </div>
    </section>

    <section id="tab-personnalisation" class="tab-panel" hidden>
      <div class="card card-pad" data-reveal>
        <div class="section-title">
          <h2>Widgets du tableau de bord</h2>
          <button class="btn btn-ghost btn-sm" id="widgetsReset" type="button">Réinitialiser</button>
        </div>
        <p class="muted" style="margin-top:-6px">Choisissez les indicateurs à afficher sur votre tableau de bord, et dans quel ordre. Cochez pour afficher, décochez pour masquer. Les modifications sont enregistrées automatiquement.</p>
        <div class="widget-preview-wrap">
          <div class="widget-preview-lab">Aperçu en temps réel</div>
          <div id="widgetPreview" class="widget-preview"></div>
        </div>
        <div id="widgetList" class="widget-list"><p class="muted">Chargement…</p></div>
      </div>
    </section>

    <section id="tab-collaboration" class="tab-panel" hidden>
      <div class="card card-pad" data-reveal>
        <div class="section-title"><h2>Inviter un étudiant à collaborer</h2></div>
        <p class="muted" style="margin-top:-6px">Générez un lien d'invitation pour qu'un autre étudiant vous aide sur votre recherche. Vous choisissez s'il peut seulement consulter, ou aussi modifier.</p>
        <div class="setting-row">
          <div class="st-txt"><strong>Droits accordés</strong><p>Vous pourrez révoquer l'accès à tout moment.</p></div>
          <select id="collabRights" style="width:auto;min-width:180px">
            <option value="0">Consultation seule</option>
            <option value="1">Consultation + modification</option>
          </select>
        </div>
        <div class="setting-row" style="border:none">
          <div class="st-txt"><strong>Étiquette (facultatif)</strong><p>Pour vous souvenir de qui il s'agit.</p></div>
          <input id="collabLabel" placeholder="ex. Camarade de promo" style="width:auto;min-width:200px">
        </div>
        <button class="btn btn-primary" id="collabInviteBtn">Générer un lien d'invitation</button>
        <div id="collabLinkBox" hidden style="margin-top:14px">
          <div class="field"><label>Lien à partager</label>
            <div style="display:flex;gap:8px">
              <input id="collabLinkInput" readonly style="flex:1">
              <button class="btn btn-ghost btn-sm" id="collabCopyBtn">Copier</button>
            </div>
          </div>
        </div>
      </div>

      <div class="card card-pad" data-reveal>
        <div class="section-title"><h2>Personnes ayant accès à mes recherches</h2></div>
        <div id="collabList"><p class="muted">Chargement…</p></div>
      </div>

      <div class="card card-pad" data-reveal>
        <div class="section-title"><h2>Recherches que je peux consulter</h2></div>
        <div id="collabAccessList"><p class="muted">Chargement…</p></div>
      </div>
    </section>

    <section id="tab-relances" class="tab-panel" hidden>
      <div class="card card-pad mb" data-reveal>
        <div class="section-title"><h2>Envoyer vos relances depuis votre Gmail</h2></div>
        <p class="muted" style="margin-top:-6px">Reliez votre adresse Gmail pour que vos relances partent directement de votre boîte, avec vos réponses reçues au même endroit. Alternis n'utilise qu'un <strong>mot de passe d'application</strong> Google (révocable), jamais votre mot de passe principal.</p>

        <div id="gmailStatus" class="gmail-status" hidden></div>

        <form id="gmailForm" style="margin-top:6px">
          <div class="field"><label>Votre adresse Gmail</label>
            <input type="email" name="user" id="gmailUser" placeholder="prenom.nom@gmail.com" autocomplete="off"></div>
          <div class="field"><label>Nom affiché (facultatif)</label>
            <input type="text" name="from_name" id="gmailFromName" placeholder="Prénom Nom" autocomplete="off"></div>
          <div class="field"><label>Mot de passe d'application Google</label>
            <input type="password" name="pass" id="gmailPass" placeholder="16 caractères" autocomplete="new-password">
            <p class="hint" style="margin-top:6px">Générez-le sur <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">myaccount.google.com/apppasswords</a> (validation en deux étapes requise). Collez-le ici : il est stocké chiffré.</p>
          </div>
          <div class="flex gap wrap" style="margin-top:4px">
            <button class="btn btn-primary" id="gmailSave" type="submit">Enregistrer</button>
            <button class="btn btn-ghost" id="gmailTest" type="button" hidden>Envoyer un test</button>
            <button class="btn btn-ghost btn-danger" id="gmailDelete" type="button" hidden>Déconnecter</button>
          </div>
        </form>
      </div>

      <div class="card card-pad" data-reveal id="autoCard" hidden>
        <div class="setting-row" style="border:none">
          <div class="st-txt"><strong>Relances automatiques</strong><p>Quand une candidature attend une réponse depuis trop longtemps, Alternis envoie une relance à votre place, depuis votre Gmail. Vous gardez la main : désactivez quand vous voulez.</p></div>
          <label class="switch">
            <input type="checkbox" id="followupAuto">
            <span class="sl"></span>
          </label>
        </div>
        <p class="hint">L'envoi automatique nécessite que les relances programmées soient traitées régulièrement (tâche cron côté serveur). Sans cela, vos relances restent disponibles en envoi manuel depuis la page « Relances ».</p>
      </div>
    </section>

    <section id="tab-extension" class="tab-panel" hidden>
      <div class="card card-pad" data-reveal>
        <div class="section-title"><h2>Extension navigateur</h2></div>
        <p class="muted" style="margin-top:-6px">L'extension Alternis enregistre vos candidatures en un clic depuis les sites de recrutement. Installez-la, puis connectez-la avec un <strong>mot de passe d'application</strong> ci-dessous (il ne remplace pas votre mot de passe habituel et reste révocable).</p>
        <div class="setting-row" style="border:none">
          <div class="st-txt"><strong>Télécharger l'extension</strong><p>Choisissez le paquet correspondant à votre navigateur.</p></div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a class="btn btn-ghost btn-sm" href="<?= e(url('extension/alternis-extension-chrome.zip')) ?>" download>Chrome / Edge / Opera</a>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('extension/alternis-extension-firefox.zip')) ?>" download>Firefox</a>
          </div>
        </div>
      </div>

      <div class="card card-pad" data-reveal>
        <div class="section-title"><h2>Mots de passe d'application</h2></div>
        <div class="setting-row" style="border:none">
          <div class="st-txt"><strong>Nouveau mot de passe</strong><p>Un par appareil ou navigateur, pour plus de sécurité.</p></div>
          <div style="display:flex;gap:8px">
            <input id="appPwLabel" placeholder="ex. PC portable" style="width:auto;min-width:160px">
            <button class="btn btn-primary btn-sm" id="appPwCreate">Générer</button>
          </div>
        </div>
        <div id="appPwReveal" hidden style="margin-top:6px">
          <div class="alert" style="background:var(--grad-soft);border:1px solid var(--border);border-radius:12px;padding:14px">
            <p style="margin:0 0 8px;font-size:13px;color:var(--muted)">Copiez ce mot de passe : il ne sera plus affiché.</p>
            <div style="display:flex;gap:8px;align-items:center">
              <code id="appPwValue" style="font-size:20px;letter-spacing:2px;font-weight:700;flex:1"></code>
              <button class="btn btn-ghost btn-sm" id="appPwCopy">Copier</button>
            </div>
          </div>
        </div>
        <div id="appPwList" style="margin-top:10px"><p class="muted">Chargement…</p></div>
      </div>
    </section>
  </div>
</div>

<!-- ===== Modale nom de la clé d'accès ===== -->
<div class="modal-scrim" id="waModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-head"><h3>Ajouter une clé d'accès</h3><button class="close-x" data-close="waModal">✕</button></div>
    <div class="modal-body">
      <div class="field">
        <label>Nom de cette clé</label>
        <input id="waLabel" placeholder="ex. iPhone de Marie, PC portable" maxlength="60">
        <span class="hint">Pour reconnaître l'appareil dans la liste.</span>
      </div>
      <p class="muted" style="font-size:12.5px;margin-top:8px">Votre appareil vous demandera de confirmer avec la biométrie (Face ID, Touch ID, Windows Hello) ou votre clé de sécurité.</p>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" data-close="waModal">Annuler</button>
      <button class="btn btn-primary" id="waConfirm">Continuer</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
