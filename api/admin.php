<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_tools.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/push.php';
$user = require_admin();
$pdo = db();
$action = $_GET['action'] ?? '';

if (!csrf_check($_SERVER['HTTP_X_CSRF'] ?? '')) json_out(['error' => 'CSRF'], 403);

function gen_password(int $len = 12): string
{
    $sets = ['abcdefghijkmnpqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!@#$%*?-'];
    $pw = '';
    foreach ($sets as $s) $pw .= $s[random_int(0, strlen($s) - 1)];
    $all = implode('', $sets);
    for ($i = strlen($pw); $i < $len; $i++) $pw .= $all[random_int(0, strlen($all) - 1)];
    return str_shuffle($pw);
}

function send_credentials_mail(array $u, string $plainPassword): array
{
    // URL absolue obligatoire : un chemin relatif serait interprété
    // par le client mail comme un domaine (ex. http://auth/login.php).
    $root = (defined('APP_URL') && APP_URL !== '') ? rtrim(APP_URL, '/') : rtrim(base_url(), '/');
    if (!preg_match('#^https?://#i', $root)) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $root = $host ? $scheme . '://' . $host . $root : $root;
    }
    $link = $root . '/auth/login.php';
    $body = '<p>Bonjour ' . htmlspecialchars($u['full_name'] ?: $u['username']) . ',</p>'
        . '<p>Un compte Alternis vient d\'être créé pour vous. Voici vos identifiants de connexion :</p>'
        . '<div style="background:#f4f5fb;border-radius:10px;padding:14px 16px;margin:14px 0;font-size:14px">'
        . '<div><strong>Identifiant :</strong> ' . htmlspecialchars($u['username']) . '</div>'
        . '<div><strong>Mot de passe :</strong> ' . htmlspecialchars($plainPassword) . '</div>'
        . '</div>'
        . '<p>Pour votre sécurité, ce mot de passe devra être modifié lors de votre première connexion.</p>'
        . '<p style="margin:22px 0"><a href="' . htmlspecialchars($link) . '" style="background:#7c5cfc;color:#fff;padding:11px 20px;border-radius:10px;text-decoration:none;font-weight:600">Se connecter à Alternis</a></p>'
        . '<p style="font-size:13px;color:#8a90a6">Ou copiez ce lien : ' . htmlspecialchars($link) . '</p>';
    return alt_send_mail($u['email'], $u['full_name'] ?: $u['username'], 'Vos identifiants Alternis', alt_mail_template('Bienvenue sur Alternis', $body));
}

switch ($action) {

  case 'list':
    $rows = $pdo->query(
      "SELECT u.id,u.username,u.email,u.full_name,u.role,u.is_active,u.otp_enabled,u.last_login,u.created_at,
              u.linked_student_id, s.full_name AS linked_name, s.username AS linked_username,
              (SELECT COUNT(*) FROM companies c WHERE c.owner_id=u.id) AS companies
       FROM users u LEFT JOIN users s ON s.id=u.linked_student_id
       ORDER BY u.created_at DESC"
    )->fetchAll();
    json_out(['items' => $rows]);
    break;

  case 'students':
    // Personnes qu'un parent peut suivre : étudiants ET administrateurs
    $rows = $pdo->query("SELECT id, full_name, username, role FROM users
                         WHERE role IN ('student','admin') AND is_active=1
                         ORDER BY FIELD(role,'student','admin'), full_name")->fetchAll();
    json_out(['items' => $rows]);
    break;

  case 'create':
    ensure_admin_tables();
    $d = json_in();
    $username = trim($d['username'] ?? '');
    $email = trim($d['email'] ?? '');
    $full = trim($d['full_name'] ?? '');
    $role = in_array($d['role'] ?? '', ['admin', 'student', 'parent'], true) ? $d['role'] : 'student';
    $linked = ($d['linked_student_id'] ?? '') !== '' ? (int)$d['linked_student_id'] : null;
    $generate = !empty($d['generate_password']);
    $sendEmail = !empty($d['send_email']);
    $mustChange = !empty($d['must_change']) || $generate;
    $pass = $generate ? gen_password() : (string)($d['password'] ?? '');

    if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username)) json_out(['error' => 'Identifiant invalide (3-60 caractères, sans espace).'], 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'E-mail invalide.'], 422);
    if (strlen($pass) < 8) json_out(['error' => 'Mot de passe : 8 caractères minimum (ou cochez « générer »).'], 422);
    if ($role === 'parent' && !$linked) json_out(['error' => 'Un compte parent doit être lié à un étudiant.'], 422);

    $c = $pdo->prepare('SELECT id FROM users WHERE username=? OR email=?');
    $c->execute([$username, $email]);
    if ($c->fetch()) json_out(['error' => 'Identifiant ou e-mail déjà utilisé.'], 422);

    if ($role !== 'parent') $linked = null;
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $st = $pdo->prepare('INSERT INTO users (username,email,full_name,role,password_hash,linked_student_id,must_change_pwd) VALUES (?,?,?,?,?,?,?)');
    $st->execute([$username, $email, $full, $role, $hash, $linked, $mustChange ? 1 : 0]);
    $newId = (int)$pdo->lastInsertId();
    log_activity((int)$user['id'], 'user_create', 'user', $newId, $username);

    $mail = ['sent' => false];
    if ($sendEmail) {
        $res = send_credentials_mail(['username' => $username, 'email' => $email, 'full_name' => $full], $pass);
        $mail = ['sent' => $res['ok'], 'error' => $res['ok'] ? null : ($res['error'] ?? 'Envoi impossible')];
    }
    json_out(['ok' => true, 'id' => $newId, 'password' => $generate ? $pass : null, 'mail' => $mail]);
    break;

  case 'update':
    // Édition d'un utilisateur (corrige le blocage quand le compte n'est lié à personne)
    $d = json_in();
    $id = (int)($d['id'] ?? 0);
    $target = $pdo->prepare('SELECT * FROM users WHERE id=?');
    $target->execute([$id]);
    $tu = $target->fetch();
    if (!$tu) json_out(['error' => 'Utilisateur introuvable.'], 404);

    $username = trim($d['username'] ?? $tu['username']);
    $email = trim($d['email'] ?? $tu['email']);
    $full = trim($d['full_name'] ?? $tu['full_name']);
    $role = in_array($d['role'] ?? '', ['admin', 'student', 'parent'], true) ? $d['role'] : $tu['role'];
    $linked = array_key_exists('linked_student_id', $d) && $d['linked_student_id'] !== ''
        ? (int)$d['linked_student_id'] : null;

    if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username)) json_out(['error' => 'Identifiant invalide.'], 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'E-mail invalide.'], 422);
    if ($role === 'parent' && !$linked) json_out(['error' => 'Un compte parent doit être lié à une personne.'], 422);
    if ($role !== 'parent') $linked = null;

    $c = $pdo->prepare('SELECT id FROM users WHERE (username=? OR email=?) AND id<>?');
    $c->execute([$username, $email, $id]);
    if ($c->fetch()) json_out(['error' => 'Identifiant ou e-mail déjà utilisé.'], 422);

    $pdo->prepare('UPDATE users SET username=?, email=?, full_name=?, role=?, linked_student_id=? WHERE id=?')
        ->execute([$username, $email, $full, $role, $linked, $id]);
    log_activity((int)$user['id'], 'user_update', 'user', $id, $username);
    json_out(['ok' => true]);
    break;

  case 'send_credentials':
    ensure_admin_tables();
    $d = json_in();
    $id = (int)($d['id'] ?? 0);
    $regen = !empty($d['regenerate']);
    $t = $pdo->prepare('SELECT * FROM users WHERE id=?');
    $t->execute([$id]);
    $tu = $t->fetch();
    if (!$tu) json_out(['error' => 'Utilisateur introuvable.'], 404);

    $plain = null;
    if ($regen) {
        $plain = gen_password();
        $pdo->prepare('UPDATE users SET password_hash=?, must_change_pwd=1 WHERE id=?')
            ->execute([password_hash($plain, PASSWORD_DEFAULT), $id]);
    }
    if ($plain === null) {
        // Sans régénération, on ne connaît pas le mot de passe : on en crée un nouveau
        $plain = gen_password();
        $pdo->prepare('UPDATE users SET password_hash=?, must_change_pwd=1 WHERE id=?')
            ->execute([password_hash($plain, PASSWORD_DEFAULT), $id]);
    }
    $res = send_credentials_mail($tu, $plain);
    log_activity((int)$user['id'], 'user_send_credentials', 'user', $id);
    if (!$res['ok']) json_out(['error' => 'E-mail non envoyé : ' . ($res['error'] ?? '')], 502);
    json_out(['ok' => true, 'password' => $plain]);
    break;

  case 'lock':
    ensure_admin_tables();
    $d = json_in();
    $id = (int)($d['id'] ?? 0);
    if ($id === (int)$user['id']) json_out(['error' => 'Vous ne pouvez pas verrouiller votre propre compte.'], 422);
    $msg = mb_substr(trim($d['message'] ?? ''), 0, 255);
    $pdo->prepare('UPDATE users SET is_active=0, lock_message=? WHERE id=?')->execute([$msg ?: null, $id]);
    log_activity((int)$user['id'], 'user_lock', 'user', $id);
    json_out(['ok' => true]);
    break;

  case 'unlock':
    $id = (int)(json_in()['id'] ?? 0);
    $pdo->prepare('UPDATE users SET is_active=1, lock_message=NULL WHERE id=?')->execute([$id]);
    log_activity((int)$user['id'], 'user_unlock', 'user', $id);
    json_out(['ok' => true]);
    break;

  case 'delete_avatar':
    $id = (int)(json_in()['id'] ?? 0);
    try { $pdo->prepare('DELETE FROM user_avatars WHERE user_id=?')->execute([$id]); } catch (Throwable $e) {}
    $pdo->prepare("UPDATE users SET avatar='' WHERE id=?")->execute([$id]);
    json_out(['ok' => true]);
    break;

  case 'notify':
    // Notification immédiate personnalisée (tous ou un seul)
    ensure_admin_tables();
    $d = json_in();
    $title = mb_substr(trim($d['title'] ?? ''), 0, 160);
    $body = trim($d['body'] ?? '');
    $tid = ($d['target_user_id'] ?? '') !== '' ? (int)$d['target_user_id'] : null;
    if ($title === '' && $body === '') json_out(['error' => 'Titre ou message requis.'], 422);
    $recips = $tid ? [$tid] : $pdo->query('SELECT id FROM users WHERE is_active=1')->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare('INSERT INTO notifications (user_id, type, title, body, url, group_key) VALUES (?,?,?,?,?,?)');
    $g = 'admin_' . time();
    foreach ($recips as $rid) $ins->execute([(int)$rid, 'admin', $title, $body, 'index.php?page=dashboard', $g]);

    // Envoi push immédiat (n'attend pas le cron)
    $pushed = 0;
    if (function_exists('push_flush_pending') && push_enabled()) {
        try { $pushed = push_flush_pending(count($recips) + 5); } catch (Throwable $e) {}
    }
    json_out(['ok' => true, 'count' => count($recips), 'pushed' => $pushed]);
    break;

  case 'schedules':
    ensure_admin_tables();
    $sub = $_GET['sub'] ?? 'list';
    if ($sub === 'list') {
        $rows = $pdo->query('SELECT s.*, u.full_name AS target_name FROM scheduled_notifications s
                             LEFT JOIN users u ON u.id=s.target_user_id ORDER BY s.created_at DESC')->fetchAll();
        json_out(['items' => $rows]);
    }
    if ($sub === 'save') {
        $d = json_in();
        $title = mb_substr(trim($d['title'] ?? ''), 0, 160);
        $body = trim($d['body'] ?? '');
        $freq = in_array($d['freq'] ?? '', ['once', 'daily', 'weekly'], true) ? $d['freq'] : 'once';
        $tid = ($d['target_user_id'] ?? '') !== '' ? (int)$d['target_user_id'] : null;
        $time = preg_match('/^\d{2}:\d{2}$/', $d['run_time'] ?? '') ? $d['run_time'] . ':00' : '09:00:00';
        $dow = isset($d['run_dow']) && $d['run_dow'] !== '' ? (int)$d['run_dow'] : null;
        if ($title === '' && $body === '') json_out(['error' => 'Titre ou message requis.'], 422);

        // Calcule next_run
        if ($freq === 'once') {
            $next = preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?/', $d['run_at'] ?? '') ? str_replace('T', ' ', $d['run_at']) : date('Y-m-d H:i:s', time() + 60);
            if (strlen($next) === 16) $next .= ':00';
        } elseif ($freq === 'daily') {
            $next = date('Y-m-d ' . $time);
            if (strtotime($next) <= time()) $next = date('Y-m-d ' . $time, strtotime('+1 day'));
        } else { // weekly
            $next = date('Y-m-d ' . $time);
            if (strtotime($next) <= time()) $next = date('Y-m-d ' . $time, strtotime('+7 days'));
        }
        $st = $pdo->prepare('INSERT INTO scheduled_notifications (target_user_id,title,body,freq,run_time,run_dow,next_run,created_by)
                             VALUES (?,?,?,?,?,?,?,?)');
        $st->execute([$tid, $title, $body, $freq, $time, $dow, $next, $user['id']]);
        json_out(['ok' => true]);
    }
    if ($sub === 'toggle') {
        $id = (int)(json_in()['id'] ?? 0);
        $pdo->prepare('UPDATE scheduled_notifications SET is_active=1-is_active WHERE id=?')->execute([$id]);
        json_out(['ok' => true]);
    }
    if ($sub === 'delete') {
        $id = (int)(json_in()['id'] ?? 0);
        $pdo->prepare('DELETE FROM scheduled_notifications WHERE id=?')->execute([$id]);
        json_out(['ok' => true]);
    }
    json_out(['error' => 'Sous-action inconnue.'], 400);
    break;

  case 'messages':
    ensure_admin_tables();
    $sub = $_GET['sub'] ?? 'list';
    if ($sub === 'list') {
        $rows = $pdo->query('SELECT m.*, u.full_name AS target_name FROM admin_messages m
                             LEFT JOIN users u ON u.id=m.target_user_id ORDER BY m.created_at DESC')->fetchAll();
        json_out(['items' => $rows]);
    }
    if ($sub === 'save') {
        $d = json_in();
        $kind = ($d['kind'] ?? 'popup') === 'banner' ? 'banner' : 'popup';
        $title = mb_substr(trim($d['title'] ?? ''), 0, 160);
        $body = trim($d['body'] ?? '');
        $color = in_array($d['color'] ?? '', ['accent', 'info', 'warn', 'danger', 'success'], true) ? $d['color'] : 'accent';
        $tid = ($d['target_user_id'] ?? '') !== '' ? (int)$d['target_user_id'] : null;
        $ends = preg_match('/^\d{4}-\d{2}-\d{2}/', $d['ends_at'] ?? '') ? str_replace('T', ' ', $d['ends_at']) : null;
        if ($ends && strlen($ends) === 16) $ends .= ':00';
        if ($body === '' && $title === '') json_out(['error' => 'Titre ou message requis.'], 422);
        $dismissible = array_key_exists('dismissible', $d) ? (!empty($d['dismissible']) ? 1 : 0) : 1;
        $st = $pdo->prepare('INSERT INTO admin_messages (target_user_id,kind,title,body,color,ends_at,dismissible,created_by)
                             VALUES (?,?,?,?,?,?,?,?)');
        $st->execute([$tid, $kind, $title, $body, $color, $ends, $dismissible, $user['id']]);
        json_out(['ok' => true]);
    }
    if ($sub === 'toggle') {
        $id = (int)(json_in()['id'] ?? 0);
        $pdo->prepare('UPDATE admin_messages SET is_active=1-is_active WHERE id=?')->execute([$id]);
        json_out(['ok' => true]);
    }
    if ($sub === 'delete') {
        $id = (int)(json_in()['id'] ?? 0);
        $pdo->prepare('DELETE FROM admin_messages WHERE id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM admin_message_reads WHERE message_id=?')->execute([$id]);
        json_out(['ok' => true]);
    }
    json_out(['error' => 'Sous-action inconnue.'], 400);
    break;

  case 'settings_get':
    ensure_admin_tables();
    json_out(['settings' => [
        'login_title'    => setting_get('login_title', ''),
        'login_subtitle' => setting_get('login_subtitle', ''),
        'login_accent'   => setting_get('login_accent', ''),
        'login_notice'   => setting_get('login_notice', ''),
        'maintenance_mode'    => setting_get('maintenance_mode', '0'),
        'maintenance_message' => setting_get('maintenance_message', ''),
    ]]);
    break;

  case 'settings_save':
    ensure_admin_tables();
    $d = json_in();
    foreach (['login_title', 'login_subtitle', 'login_accent', 'login_notice', 'maintenance_message'] as $k) {
        if (array_key_exists($k, $d)) setting_set($k, mb_substr((string)$d[$k], 0, 2000));
    }
    if (array_key_exists('maintenance_mode', $d)) setting_set('maintenance_mode', $d['maintenance_mode'] ? '1' : '0');
    log_activity((int)$user['id'], 'settings_save', 'site');
    json_out(['ok' => true]);
    break;

  case 'toggle':
    $id = (int)(json_in()['id'] ?? 0);
    if ($id === (int)$user['id']) json_out(['error' => 'Vous ne pouvez pas désactiver votre propre compte.'], 422);
    $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id=?')->execute([$id]);
    json_out(['ok' => true]);
    break;

  case 'reset_pwd':
    $d = json_in();
    $id = (int)($d['id'] ?? 0);
    $pass = (string)($d['password'] ?? '');
    if (strlen($pass) < 8) json_out(['error' => '8 caractères minimum.'], 422);
    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
    log_activity((int)$user['id'], 'user_reset_pwd', 'user', $id);
    json_out(['ok' => true]);
    break;

  case 'delete':
    $id = (int)(json_in()['id'] ?? 0);
    if ($id === (int)$user['id']) json_out(['error' => 'Vous ne pouvez pas supprimer votre propre compte.'], 422);
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    log_activity((int)$user['id'], 'user_delete', 'user', $id);
    json_out(['ok' => true]);
    break;

  case 'relink':
    // Change la personne suivie par un compte parent
    $d = json_in();
    $id = (int)($d['id'] ?? 0);
    $linked = (int)($d['linked_student_id'] ?? 0);
    $p = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='parent'");
    $p->execute([$id]);
    if (!$p->fetch()) json_out(['error' => 'Compte parent introuvable.'], 404);
    $t = $pdo->prepare("SELECT id FROM users WHERE id=? AND role IN ('student','admin') AND is_active=1");
    $t->execute([$linked]);
    if (!$t->fetch()) json_out(['error' => 'Personne à suivre invalide.'], 422);
    $pdo->prepare('UPDATE users SET linked_student_id=? WHERE id=?')->execute([$linked, $id]);
    log_activity((int)$user['id'], 'user_relink', 'user', $id, (string)$linked);
    json_out(['ok' => true]);
    break;

  case 'view_as':
    // Permet à l'admin de consulter les données d'un étudiant
    $id = (int)(json_in()['id'] ?? 0);
    if ($id > 0) {
        $c = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='student'");
        $c->execute([$id]);
        if ($c->fetch()) $_SESSION['admin_owner'] = $id;
    } else {
        unset($_SESSION['admin_owner']);
    }
    json_out(['ok' => true]);
    break;

  default:
    json_out(['error' => 'Action inconnue.'], 400);
}
