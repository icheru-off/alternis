<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

if (is_logged_in()) redirect('index.php');

// Redémarrage propre de l'inscription
if (isset($_GET['restart'])) { unset($_SESSION['reg_stage'], $_SESSION['reg_data']); redirect('auth/register.php'); }

$error = '';
$stage = $_SESSION['reg_stage'] ?? 'form';   // form -> code
$reg = $_SESSION['reg_data'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Session expirée, réessayez.';
    } elseif (($_POST['step'] ?? '') === 'form') {
        $first = trim($_POST['first_name'] ?? '');
        $last  = trim($_POST['last_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass  = $_POST['password'] ?? '';
        $searchType = ($_POST['search_type'] ?? 'alternance') === 'stage' ? 'stage' : 'alternance';

        if ($first === '' || $last === '') {
            $error = 'Indiquez votre prénom et votre nom.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'E-mail invalide.';
        } elseif (strlen($pass) < 8) {
            $error = 'Le mot de passe doit faire au moins 8 caractères.';
        } else {
            // E-mail déjà utilisé ?
            $c = db()->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
            $c->execute([$email]);
            if ($c->fetch()) {
                $error = 'Un compte existe déjà avec cet e-mail. Essayez de vous connecter.';
            } else {
                $username = username_from_name($first, $last);
                $_SESSION['reg_data'] = [
                    'first' => $first, 'last' => $last, 'email' => $email,
                    'username' => $username, 'pass_hash' => password_hash($pass, PASSWORD_DEFAULT),
                    'search_type' => $searchType,
                ];
                // Émission + envoi du code d'activation
                $code = email_code_issue($email, 'activation', null, 20);
                $html = alt_mail_template('Activez votre compte Alternis',
                    '<p>Bonjour ' . e($first) . ',</p>'
                    . '<p>Votre code d\'activation Alternis est :</p>'
                    . '<p style="font-size:30px;font-weight:800;letter-spacing:6px;text-align:center;margin:22px 0">' . e($code) . '</p>'
                    . '<p>Il est valable 20 minutes. Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet e-mail.</p>');
                $sent = alt_send_mail($email, $first . ' ' . $last, 'Votre code d\'activation Alternis', $html);
                if (empty($sent['ok'])) {
                    $error = 'Envoi de l\'e-mail impossible pour le moment. Réessayez plus tard.';
                    unset($_SESSION['reg_data']);
                } else {
                    $_SESSION['reg_stage'] = 'code';
                    redirect('auth/register.php');
                }
            }
        }
    } elseif (($_POST['step'] ?? '') === 'code') {
        $code = trim($_POST['code'] ?? '');
        $d = $_SESSION['reg_data'] ?? null;
        if (!$d) {
            $error = 'Session expirée. Recommencez l\'inscription.';
            $stage = 'form';
        } else {
            $v = email_code_verify($d['email'], 'activation', $code);
            if (!$v['ok']) {
                $error = $v['error'];
                $stage = 'code';
            } else {
                // Création du compte (actif immédiatement)
                try {
                    $pdo = db();
                    $pdo->prepare('INSERT INTO users (username, email, password_hash, full_name, role, is_active)
                                   VALUES (?,?,?,?,?,1)')
                        ->execute([$d['username'], $d['email'], $d['pass_hash'],
                                   $d['first'] . ' ' . $d['last'], 'student']);
                    $uid = (int)$pdo->lastInsertId();
                    // Type de recherche (stage / alternance) + onboarding à montrer
                    user_pref_set($uid, 'search_type', $d['search_type'] ?? 'alternance');
                    user_pref_set($uid, 'onboarding_done', '0');
                    log_activity($uid, 'register');
                    $u = $pdo->query('SELECT * FROM users WHERE id = ' . $uid)->fetch();
                    unset($_SESSION['reg_stage'], $_SESSION['reg_data']);
                    $_SESSION['remember_me'] = true;
                    finalize_login($u);
                    redirect('index.php?onboarding=1');
                } catch (Throwable $ex) {
                    $error = 'Création du compte impossible. Réessayez.';
                }
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
<title>Créer un compte · <?= e(APP_NAME) ?></title>
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
      <h1>Rejoignez Alternis.</h1>
      <p>Créez votre compte et organisez votre recherche d'alternance dès aujourd'hui.</p>
    </div>
  </div>

  <div class="auth-form-side">
    <div class="auth-card">
      <?php if ($stage === 'code'): ?>
        <h2>Vérifiez votre e-mail</h2>
        <p class="sub">Un code à 6 chiffres a été envoyé à <strong><?= e($reg['email'] ?? '') ?></strong>.</p>
        <?php if ($error): ?><div class="alert alert-err"><?= e($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="step" value="code">
          <div class="field">
            <label>Code d'activation</label>
            <input name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" required autofocus
                   style="letter-spacing:8px;text-align:center;font-size:22px">
          </div>
          <button class="btn btn-primary" style="width:100%;justify-content:center" type="submit">Activer mon compte</button>
        </form>
        <p style="text-align:center;margin-top:16px"><a href="<?= e(url('auth/register.php?restart=1')) ?>" class="muted" style="font-size:13px">Recommencer</a></p>
      <?php else: ?>
        <h2>Créer un compte</h2>
        <p class="sub">Quelques informations pour bien démarrer.</p>
        <?php if ($error): ?><div class="alert alert-err"><?= e($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="on">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="step" value="form">
          <div class="grid-2">
            <div class="field"><label>Prénom</label><input name="first_name" required value="<?= e($_POST['first_name'] ?? '') ?>"></div>
            <div class="field"><label>Nom</label><input name="last_name" required value="<?= e($_POST['last_name'] ?? '') ?>"></div>
          </div>
          <div class="field"><label>E-mail</label><input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>"></div>
          <div class="field"><label>Mot de passe</label><input type="password" name="password" required minlength="8" placeholder="8 caractères minimum"></div>

          <div class="reg-sep"><span>Vous cherchez…</span></div>

          <div class="regtype">
            <label class="regtype-opt">
              <input type="radio" name="search_type" value="alternance" <?= (($_POST['search_type'] ?? 'alternance') !== 'stage') ? 'checked' : '' ?>>
              <span class="regtype-card">
                <span class="regtype-ic">🎓</span>
                <strong>Une alternance</strong>
                <small>Contrat d'apprentissage ou de professionnalisation</small>
              </span>
            </label>
            <label class="regtype-opt">
              <input type="radio" name="search_type" value="stage" <?= (($_POST['search_type'] ?? '') === 'stage') ? 'checked' : '' ?>>
              <span class="regtype-card">
                <span class="regtype-ic">💼</span>
                <strong>Un stage</strong>
                <small>Stage conventionné, césure, fin d'études</small>
              </span>
            </label>
          </div>

          <button class="btn btn-primary" style="width:100%;justify-content:center;margin-top:16px" type="submit">Recevoir mon code</button>
        </form>
        <p style="margin-top:18px;font-size:13px;color:var(--faint);text-align:center">
          Déjà un compte ? <a href="<?= e(url('auth/login.php')) ?>" style="color:var(--accent);font-weight:600">Se connecter</a>
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
