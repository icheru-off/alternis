<?php
require_once __DIR__ . '/../includes/auth.php';
$__user = require_login();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Changer le mot de passe · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/css/style.css?v=' . ASSET_VERSION)) ?>">
<script>window.ALT = { base: <?= json_encode(url('')) ?>, csrf: <?= json_encode(csrf_token()) ?> };</script>
</head>
<body>
<div style="min-height:100vh;display:grid;place-items:center;padding:24px">
  <div class="card card-pad" style="max-width:420px;width:100%">
    <div style="text-align:center;margin-bottom:18px">
      <span style="display:inline-block;background:var(--grad);color:#fff;font-weight:800;font-size:18px;padding:9px 16px;border-radius:11px"><?= e(APP_NAME) ?></span>
    </div>
    <h1 style="font-size:20px;margin:0 0 6px">Choisissez un nouveau mot de passe</h1>
    <p class="muted" style="margin:0 0 18px;font-size:14px">Pour votre sécurité, vous devez définir un mot de passe personnel avant de continuer.</p>
    <div class="field"><label>Mot de passe actuel (provisoire)</label><input type="password" id="cur" autocomplete="current-password"></div>
    <div class="field"><label>Nouveau mot de passe</label><input type="password" id="new1" autocomplete="new-password" placeholder="8 caractères minimum"></div>
    <div class="field"><label>Confirmer le nouveau mot de passe</label><input type="password" id="new2" autocomplete="new-password"></div>
    <button class="btn btn-primary" id="cpGo" style="width:100%;justify-content:center;margin-top:8px">Valider</button>
    <p id="cpMsg" style="text-align:center;margin-top:12px;font-size:13px"></p>
  </div>
</div>
<div class="toast-stack" id="toastStack"></div>
<script src="<?= e(url('assets/js/app.js?v=' . ASSET_VERSION)) ?>"></script>
<script>
document.getElementById('cpGo').onclick = async function () {
  var cur = document.getElementById('cur').value;
  var n1 = document.getElementById('new1').value;
  var n2 = document.getElementById('new2').value;
  var msg = document.getElementById('cpMsg');
  msg.style.color = 'var(--danger,#dc2626)';
  if (n1.length < 8) { msg.textContent = 'Le nouveau mot de passe doit faire au moins 8 caractères.'; return; }
  if (n1 !== n2) { msg.textContent = 'Les deux mots de passe ne correspondent pas.'; return; }
  try {
    await window.altFetch('api/profile.php?action=password', { json: { current: cur, new: n1 } });
    msg.style.color = '#16a34a'; msg.textContent = 'Mot de passe modifié. Redirection…';
    setTimeout(function () { window.location.href = window.ALT.base + 'index.php?page=dashboard'; }, 800);
  } catch (e) { msg.textContent = (e && e.error) || 'Erreur.'; }
};
</script>
</body>
</html>
