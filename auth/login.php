<?php
require_once __DIR__ . '/../includes/auth.php';

/** Destination après connexion : la page mémorisée, sinon le tableau de bord. */
function login_dest(): string {
    $to = $_SESSION['after_login'] ?? '';
    unset($_SESSION['after_login']);
    // sécurité : on n'accepte qu'un chemin interne relatif
    if ($to && preg_match('#^/[A-Za-z0-9_./?=&%-]*$#', $to) && strpos($to, '//') === false) {
        return $to;
    }
    return url('index.php');
}

// Déjà connecté ? -> destination
if (is_logged_in()) {
    redirect(login_dest());
}

$error = '';
$stage = isset($_SESSION['pending_otp_uid']) ? 'otp' : 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Session expirée, réessayez.';
    } elseif (($_POST['step'] ?? '') === 'otp') {
        // Vérification du code OTP
        $uid = $_SESSION['pending_otp_uid'] ?? 0;
        $st = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
        $st->execute([$uid]);
        $u = $st->fetch();
        require_once __DIR__ . '/../includes/totp.php';
        if ($u && TOTP::verify($u['otp_secret'], $_POST['otp'] ?? '')) {
            finalize_login($u);
            redirect(login_dest());
        } else {
            $error = 'Code de vérification incorrect.';
            $stage = 'otp';
        }
    } else {
        $_SESSION['remember_me'] = !empty($_POST['remember']);
        $res = attempt_login(trim($_POST['identifier'] ?? ''), $_POST['password'] ?? '');
        if (!$res['ok']) {
            $error = $res['msg'];
            // Compte verrouillé ? Afficher le message personnalisé le cas échéant.
            try {
                $idf = trim($_POST['identifier'] ?? '');
                $lk = db()->prepare('SELECT is_active, lock_message FROM users WHERE username=? OR email=? LIMIT 1');
                $lk->execute([$idf, $idf]);
                if ($row = $lk->fetch()) {
                    if ((int)$row['is_active'] === 0) {
                        $error = $row['lock_message'] ?: "Ce compte est verrouillé. Contactez l'administrateur.";
                    }
                }
            } catch (Throwable $e) {}
        } elseif (!empty($res['needs_otp'])) {
            $stage = 'otp';
        } else {
            redirect(login_dest());
        }
    }
}

// Personnalisation de la page de connexion (réglages admin)
$loginTitle    = setting_get('login_title', '') ?: 'Votre recherche d\'alternance, enfin organisée.';
$loginSubtitle = setting_get('login_subtitle', '') ?: 'Centralisez vos candidatures, suivez vos relances et décrochez le contrat.';
$loginNotice   = setting_get('login_notice', '');
$loginAccent   = trim((string)setting_get('login_accent', ''));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connexion · <?= e(APP_NAME) ?></title>
<link rel="icon" href="<?= e(url('assets/img/favicon-32.png?v=' . ASSET_VERSION)) ?>" sizes="32x32" type="image/png">
<link rel="icon" href="<?= e(url('assets/img/favicon-64.png?v=' . ASSET_VERSION)) ?>" sizes="64x64" type="image/png">
<link rel="apple-touch-icon" href="<?= e(url('assets/img/icon-180.png?v=' . ASSET_VERSION)) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css?v=' . ASSET_VERSION)) ?>">
<?php if ($loginAccent && preg_match('/^#?[0-9a-fA-F]{6}$/', $loginAccent)): $ac = (strpos($loginAccent, '#') === 0 ? '' : '#') . $loginAccent; ?>
<style>:root{--accent:<?= e($ac) ?>;--grad:linear-gradient(135deg,<?= e($ac) ?>,#22d3ee)}</style>
<?php endif; ?>
<script>window.WA = { base: <?= json_encode(url('')) ?>, csrf: <?= json_encode(csrf_token()) ?> };</script>
</head>
<body>
<div class="auth-wrap">
  <div class="auth-brand">
    <div class="a-top">
      <img class="auth-logo" src="<?= e(url('assets/img/logo-light.png?v=' . ASSET_VERSION)) ?>" alt="<?= e(APP_NAME) ?>">
    </div>
    <div class="auth-hero">
      <h1><?= nl2br(e($loginTitle)) ?></h1>
      <p><?= e($loginSubtitle) ?></p>
    </div>
  </div>

  <div class="auth-form-side">
    <div class="auth-card">
      <?php if ($stage === 'otp'): ?>
        <h2>Vérification en deux étapes</h2>
        <p class="sub">Saisissez le code à 6 chiffres de votre application d'authentification.</p>
        <?php if ($error): ?><div class="alert alert-err"><?= e($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off" id="otpForm">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="step" value="otp">
          <input type="hidden" name="otp" id="otpValue">
          <div class="otp-inputs" id="otpInputs">
            <?php for ($i = 0; $i < 6; $i++): ?><input inputmode="numeric" maxlength="1" pattern="\d"><?php endfor; ?>
          </div>
          <button class="btn btn-primary" style="width:100%;justify-content:center" type="submit">Vérifier</button>
        </form>
        <p style="text-align:center;margin-top:16px"><a href="<?= e(url('auth/logout.php')) ?>" class="muted" style="font-size:13px">Annuler</a></p>
      <?php else: ?>
        <h2>Bon retour 👋</h2>
        <p class="sub">Connectez-vous pour reprendre votre suivi.</p>
        <?php if (!empty($_SESSION['flash_login'])): ?><div class="alert" style="background:#dcfce7;color:#166534;border-radius:10px;padding:10px 12px;font-size:13px;margin-bottom:12px"><?= e($_SESSION['flash_login']) ?></div><?php unset($_SESSION['flash_login']); endif; ?>
        <?php if ($loginNotice): ?><div class="alert" style="background:var(--grad-soft);color:var(--accent);border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:13px;margin-bottom:12px"><?= nl2br(e($loginNotice)) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-err"><?= e($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="on">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <div class="field">
            <label>Identifiant ou e-mail</label>
            <input name="identifier" required autofocus value="<?= e($_POST['identifier'] ?? '') ?>">
          </div>
          <div class="field">
            <label>Mot de passe</label>
            <input type="password" name="password" required>
          </div>
          <label class="remember-row">
            <input type="checkbox" name="remember" value="1" checked>
            <span>Se souvenir de moi sur cet appareil</span>
          </label>
          <button class="btn btn-primary" style="width:100%;justify-content:center" type="submit">Se connecter</button>
        </form>
        <div class="auth-sep" id="waSep" hidden><span>ou</span></div>
        <button type="button" class="btn btn-ghost" id="waLoginBtn" hidden style="width:100%;justify-content:center;gap:8px">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none"><path d="M7 14a4 4 0 1 1 4-4M11 10h9l-2 2 2 2-3 3-2-2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Se connecter avec une clé d'accès
        </button>
        <p style="margin-top:22px;font-size:13px;color:var(--faint);text-align:center">
          <a href="<?= e(url('auth/reset.php')) ?>" style="color:var(--accent);font-weight:600">Mot de passe oublié ?</a>
        </p>
        <div class="auth-sep"><span>ou</span></div>
        <a href="<?= e(url('auth/register.php')) ?>" class="btn btn-ghost" style="width:100%;justify-content:center">Créer un compte</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
// Saisie OTP fluide (6 cases)
(function(){
  var inputs = document.querySelectorAll('#otpInputs input');
  if(!inputs.length) return;
  var hidden = document.getElementById('otpValue');
  function sync(){ hidden.value = Array.from(inputs).map(i=>i.value).join(''); }
  inputs.forEach(function(inp, idx){
    inp.addEventListener('input', function(){
      this.value = this.value.replace(/\D/g,'');
      if(this.value && idx < inputs.length-1) inputs[idx+1].focus();
      sync();
      if(hidden.value.length===6) document.getElementById('otpForm').submit();
    });
    inp.addEventListener('keydown', function(e){
      if(e.key==='Backspace' && !this.value && idx>0) inputs[idx-1].focus();
    });
    inp.addEventListener('paste', function(e){
      var d=(e.clipboardData.getData('text')||'').replace(/\D/g,'').slice(0,6);
      d.split('').forEach(function(c,i){ if(inputs[i]) inputs[i].value=c; });
      sync(); e.preventDefault();
      if(hidden.value.length===6) document.getElementById('otpForm').submit();
    });
  });
  inputs[0].focus();
})();
</script>
<script src="<?= e(url('assets/js/webauthn.js?v=' . ASSET_VERSION)) ?>"></script>
<script>
// Connexion par clé d'accès
(function(){
  var btn = document.getElementById('waLoginBtn');
  var sep = document.getElementById('waSep');
  if(!btn || !window.waSupported || !window.waSupported()) return;
  btn.hidden = false; if(sep) sep.hidden = false;
  var idField = document.querySelector('input[name="identifier"]');
  btn.addEventListener('click', async function(){
    var id = (idField && idField.value.trim()) || '';
    if(!id){ if(idField){ idField.focus(); } alert("Entrez d'abord votre identifiant ou e-mail."); return; }
    btn.disabled = true; var old = btn.textContent; btn.textContent = 'Vérification…';
    try {
      var r = await window.waLogin(id);
      window.location.href = r.redirect || <?= json_encode(url('index.php')) ?>;
    } catch(e){
      alert(e.message || "Connexion par clé impossible.");
      btn.disabled = false; btn.textContent = old;
    }
  });
})();
</script>
</body>
</html>
