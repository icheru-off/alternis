<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
$pdo = db();
if (function_exists('ensure_admin_tables')) { try { ensure_admin_tables(); } catch (Throwable $e) {} }
$action = $_GET['action'] ?? '';

if (!csrf_check($_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? ''))) {
    json_out(['error' => 'Jeton de sécurité invalide.'], 403);
}

switch ($action) {

  case 'update':
    $d = json_in();
    $full = trim($d['full_name'] ?? '');
    $email = trim($d['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'E-mail invalide.'], 422);
    // Unicité e-mail
    $c = $pdo->prepare('SELECT id FROM users WHERE email=? AND id<>?');
    $c->execute([$email, $user['id']]);
    if ($c->fetch()) json_out(['error' => 'Cet e-mail est déjà utilisé.'], 422);
    $pdo->prepare('UPDATE users SET full_name=?, email=? WHERE id=?')->execute([mb_substr($full, 0, 120), $email, $user['id']]);
    json_out(['ok' => true]);
    break;

  case 'password':
    $d = json_in();
    $cur = (string)($d['current'] ?? '');
    $new = (string)($d['new'] ?? '');
    if (!password_verify($cur, $user['password_hash'])) json_out(['error' => 'Mot de passe actuel incorrect.'], 422);
    if (strlen($new) < 8) json_out(['error' => 'Le nouveau mot de passe doit faire au moins 8 caractères.'], 422);
    $hash = password_hash($new, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password_hash=?, must_change_pwd=0 WHERE id=?')->execute([$hash, $user['id']]);
    log_activity((int)$user['id'], 'password_change');
    json_out(['ok' => true]);
    break;

  case 'avatar_delete':
    try { $pdo->prepare('DELETE FROM user_avatars WHERE user_id=?')->execute([$user['id']]); } catch (Throwable $e) {}
    if ($user['avatar'] && substr($user['avatar'], 0, 3) !== 'db:' && file_exists(UPLOAD_DIR . '/' . $user['avatar'])) {
        @unlink(UPLOAD_DIR . '/' . $user['avatar']);
    }
    $pdo->prepare("UPDATE users SET avatar='' WHERE id=?")->execute([$user['id']]);
    json_out(['ok' => true]);
    break;

  case 'theme':
    $t = json_in()['theme'] ?? 'auto';
    if (!in_array($t, ['auto', 'light', 'dark'], true)) $t = 'auto';
    $pdo->prepare('UPDATE users SET theme_pref=? WHERE id=?')->execute([$t, $user['id']]);
    json_out(['ok' => true, 'theme' => $t]);
    break;

  case 'pref':
    // Préférence utilisateur générique (liste blanche de clés autorisées).
    $d = json_in();
    $key = (string)($d['key'] ?? '');
    $allowed = ['autocomplete_off', 'onboarding_done', 'onboarding_skipped', 'search_type', 'seen_release', 'followup_auto', 'dashboard_widgets'];
    if (!in_array($key, $allowed, true)) json_out(['error' => 'Préférence inconnue.'], 422);
    $val = (string)($d['value'] ?? '');
    if ($key === 'search_type') $val = ($val === 'stage') ? 'stage' : 'alternance';
    user_pref_set((int)$user['id'], $key, $val);
    json_out(['ok' => true]);
    break;

  case 'app_pw_list':
    require_once __DIR__ . '/../includes/ext_auth.php';
    ensure_ext_tables();
    $st = db()->prepare('SELECT id, label, last_used, created_at FROM app_passwords WHERE user_id = ? ORDER BY created_at DESC');
    $st->execute([(int)$user['id']]);
    json_out(['ok' => true, 'items' => $st->fetchAll()]);
    break;

  case 'app_pw_create':
    require_once __DIR__ . '/../includes/ext_auth.php';
    ensure_ext_tables();
    $label = mb_substr(trim(json_in()['label'] ?? ''), 0, 80) ?: 'Extension';
    $secret = app_password_generate();
    db()->prepare('INSERT INTO app_passwords (user_id, label, secret_hash) VALUES (?,?,?)')
        ->execute([(int)$user['id'], $label, password_hash($secret, PASSWORD_DEFAULT)]);
    // Le secret n'est montré qu'une seule fois
    json_out(['ok' => true, 'secret' => $secret, 'label' => $label]);
    break;

  case 'app_pw_delete':
    require_once __DIR__ . '/../includes/ext_auth.php';
    ensure_ext_tables();
    $id = (int)(json_in()['id'] ?? 0);
    db()->prepare('DELETE FROM app_passwords WHERE id = ? AND user_id = ?')->execute([$id, (int)$user['id']]);
    // Révoque aussi les jetons émis depuis ce mot de passe
    db()->prepare('DELETE FROM ext_tokens WHERE app_pw_id = ? AND user_id = ?')->execute([$id, (int)$user['id']]);
    json_out(['ok' => true]);
    break;

  case 'avatar':
    if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        json_out(['error' => 'Aucun fichier reçu.'], 422);
    }
    $f = $_FILES['avatar'];
    if ($f['size'] > 3 * 1024 * 1024) json_out(['error' => 'Image trop lourde (max 3 Mo).'], 422);
    $info = @getimagesize($f['tmp_name']);
    if (!$info) json_out(['error' => 'Fichier image invalide.'], 422);
    $mime = $info['mime'];
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        json_out(['error' => 'Format non supporté (JPG, PNG, WEBP, GIF).'], 422);
    }
    $bin = file_get_contents($f['tmp_name']);
    if ($bin === false) json_out(['error' => 'Lecture du fichier impossible.'], 500);

    // Stockage EN BASE : survit aux mises à jour de fichiers du serveur.
    try {
        // Crée la table si besoin (indépendant d'upgrade.php)
        $pdo->exec('CREATE TABLE IF NOT EXISTS `user_avatars` (
            `user_id` INT UNSIGNED NOT NULL PRIMARY KEY,
            `mime` VARCHAR(60) NOT NULL DEFAULT "image/png",
            `data` LONGBLOB NOT NULL,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $st = $pdo->prepare('INSERT INTO user_avatars (user_id, mime, data, updated_at)
                             VALUES (?,?,?,NOW())
                             ON DUPLICATE KEY UPDATE mime=VALUES(mime), data=VALUES(data), updated_at=NOW()');
        $st->bindValue(1, $user['id'], PDO::PARAM_INT);
        $st->bindValue(2, $mime);
        $st->bindValue(3, $bin, PDO::PARAM_LOB);
        $st->execute();
    } catch (Throwable $e) {
        json_out(['error' => 'Enregistrement en base impossible.'], 500);
    }

    // Nettoie un éventuel ancien fichier, et pose un jeton de version (cache-buster)
    if ($user['avatar'] && substr($user['avatar'], 0, 3) !== 'db:' && file_exists(UPLOAD_DIR . '/' . $user['avatar'])) {
        @unlink(UPLOAD_DIR . '/' . $user['avatar']);
    }
    $token = 'db:' . substr(bin2hex(random_bytes(5)), 0, 10);
    $pdo->prepare('UPDATE users SET avatar=? WHERE id=?')->execute([$token, $user['id']]);
    json_out(['ok' => true, 'avatar' => 'api/avatar.php?u=' . (int)$user['id'] . '&v=' . urlencode($token)]);
    break;

  case 'gmail_get':
    // État de la configuration Gmail personnelle (sans révéler le mot de passe).
    require_once __DIR__ . '/../includes/crypto.php';
    $gu = trim((string)user_pref_get((int)$user['id'], 'gmail_user', ''));
    $gp = (string)user_pref_get((int)$user['id'], 'gmail_pass', '');
    $gn = trim((string)user_pref_get((int)$user['id'], 'gmail_from_name', ''));
    json_out([
        'ok' => true,
        'configured' => ($gu !== '' && $gp !== ''),
        'user' => $gu,
        'from_name' => $gn,
        'auto' => user_pref_get((int)$user['id'], 'followup_auto', '0') === '1',
    ]);
    break;

  case 'gmail_save':
    require_once __DIR__ . '/../includes/crypto.php';
    $d = json_in();
    $gu = strtolower(trim($d['user'] ?? ''));
    $gp = preg_replace('/\s+/', '', (string)($d['pass'] ?? '')); // les mots de passe d'app Google s'écrivent avec des espaces
    $gn = mb_substr(trim($d['from_name'] ?? ''), 0, 80);
    if (!filter_var($gu, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'Adresse Gmail invalide.'], 422);
    if (strlen($gp) < 12) json_out(['error' => "Le mot de passe d'application Google fait 16 caractères. Collez-le sans espaces."], 422);
    user_pref_set((int)$user['id'], 'gmail_user', $gu);
    user_pref_set((int)$user['id'], 'gmail_pass', alt_encrypt($gp));
    user_pref_set((int)$user['id'], 'gmail_from_name', $gn);
    json_out(['ok' => true]);
    break;

  case 'gmail_test':
    // Envoie un e-mail de test à soi-même via le compte Gmail personnel.
    require_once __DIR__ . '/../includes/mailer.php';
    $acc = alt_mail_account('gmail', (int)$user['id']);
    if (empty($acc['host'])) json_out(['error' => $acc['missing'] ?? 'Gmail non configuré.'], 422);
    $to = $acc['user'];
    $html = alt_mail_template('Test de configuration',
        '<p>Bravo ! Votre adresse Gmail est correctement configurée dans Alternis.</p>'
        . '<p>Vos relances pourront désormais partir depuis <strong>' . e($to) . '</strong>.</p>');
    $res = alt_send_mail($to, $acc['from_name'] ?? $to, 'Alternis — test d\'envoi', $html, null, ['account' => 'gmail', 'user_id' => (int)$user['id']]);
    if (!empty($res['ok'])) json_out(['ok' => true, 'sent_to' => $to]);
    json_out(['error' => $res['error'] ?? 'Échec de l\'envoi.'], 502);
    break;

  case 'gmail_delete':
    user_pref_set((int)$user['id'], 'gmail_user', '');
    user_pref_set((int)$user['id'], 'gmail_pass', '');
    user_pref_set((int)$user['id'], 'gmail_from_name', '');
    user_pref_set((int)$user['id'], 'followup_auto', '0');
    json_out(['ok' => true]);
    break;

  default:
    json_out(['error' => 'Action inconnue.'], 400);
}
