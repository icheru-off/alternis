<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

if (is_logged_in()) redirect('index.php');

$error = '';
$info  = '';
$stage = $_SESSION['reset_stage'] ?? 'ask';   // ask -> code
$email = $_SESSION['reset_email'] ?? '';

if (isset($_GET['restart'])) { unset($_SESSION['reset_stage'], $_SESSION['reset_email']); redirect('auth/reset.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Session expirée, réessayez.';
    } elseif (($_POST['step'] ?? '') === 'ask') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'E-mail invalide.';
        } else {
            // On envoie un code seulement si le compte existe, mais on affiche
            // toujours le même message (pas de fuite sur l'existence du compte).
            $st = db()->prepare('SELECT id, full_name FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
            $st->execute([$email]);
            if ($u = $st->fetch()) {
                $code = email_code_issue($email, 'reset', null, 20);
                $html = alt_mail_template('Réinitialisation de votre mot de passe',
                    '<p>Bonjour,</p>'
                    . '<p>Voici votre code pour réinitialiser votre mot de passe Alternis :</p>'
                    . '<p style="font-size:30px;font-weight:800;letter-spacing:6px;text-align:center;margin:22px 0">' . e($code) . '</p>'
                    . '<p>Il est valable 20 minutes. Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet e-mail : votre mot de passe reste inchangé.</p>');
                alt_send_mail($email, $u['full_name'] ?: $email, 'Réinitialisation de votre mot de passe Alternis', $html);
            }
            $_SESSION['reset_stage'] = 'code';
            $_SESSION['reset_email'] = $email;
            redirect('auth/reset.php');
        }
    } elseif (($_POST['step'] ?? '') === 'code') {
        $email = $_SESSION['reset_email'] ?? '';
        $code = trim($_POST['code'] ?? '');
        $new  = $_POST['password'] ?? '';
        if ($email === '') {
            $error = 'Session expirée. Recommencez.';
            $stage = 'ask';
        } elseif (strlen($new) < 8) {
            $error = 'Le mot de passe doit faire au moins 8 caractères.';
            $stage = 'code';
        } else {
            $v = email_code_verify($email, 'reset', $code);
            if (!$v['ok']) {
                $error = $v['error'];
                $stage = 'code';
            } else {
                db()->prepare('UPDATE users SET password_hash = ? WHERE email = ?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $email]);
                unset($_SESSION['reset_stage'], $_SESSION['reset_email']);
                $_SESSION['flash_login'] = 'Mot de passe réinitialisé. Vous pouvez vous connecter.';
                redirect('auth/login.php');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mot de passe oublié · <?= e(APP_NAME) ?></title>
<link rel="icon" href="<?= e(url('assets/img/favicon-32.png?v=' . ASSET_VERSION)) ?>" sizes="32x32" type="image/png">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css?v=' . ASSET_VERSION)) ?>">
</head>
<body class="auth-body">
<div class="auth-split">
  <div class="auth-brand">
    <div class="a-top">
      <img class="auth-logo" src="<?= e(url('assets/img/logo-light.png?v=' . ASSET_VERSION)) ?>" alt="<?= e(APP_NAME) ?>">
    </div>
    <div class="auth-hero">
      <h1>Pas de panique.</h1>
      <p>Réinitialisez votre mot de passe en quelques secondes.</p>
    </div>
  </div>

  <div class="auth-form-side">
    <div class="auth-card">
      <?php if ($stage === 'code'): ?>
        <h2>Nouveau mot de passe</h2>
        <p class="sub">Si un compte existe pour <strong><?= e($email) ?></strong>, un code y a été envoyé.</p>
        <?php if ($error): ?><div class="alert alert-err"><?= e($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="step" value="code">
          <div class="field">
            <label>Code reçu par e-mail</label>
            <input name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" required autofocus
                   style="letter-spacing:8px;text-align:center;font-size:22px">
          </div>
          <div class="field">
            <label>Nouveau mot de passe</label>
            <input type="password" name="password" required minlength="8" placeholder="8 caractères minimum">
          </div>
          <button class="btn btn-primary" style="width:100%;justify-content:center" type="submit">Réinitialiser</button>
        </form>
        <p style="text-align:center;margin-top:16px"><a href="<?= e(url('auth/reset.php?restart=1')) ?>" class="muted" style="font-size:13px">Recommencer</a></p>
      <?php else: ?>
        <h2>Mot de passe oublié</h2>
        <p class="sub">Saisissez votre e-mail : nous vous enverrons un code.</p>
        <?php if ($error): ?><div class="alert alert-err"><?= e($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="on">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="step" value="ask">
          <div class="field"><label>E-mail</label><input type="email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>"></div>
          <button class="btn btn-primary" style="width:100%;justify-content:center" type="submit">Envoyer le code</button>
        </form>
        <p style="margin-top:18px;font-size:13px;color:var(--faint);text-align:center">
          <a href="<?= e(url('auth/login.php')) ?>" style="color:var(--accent);font-weight:600">Retour à la connexion</a>
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
