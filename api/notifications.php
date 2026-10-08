<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
$user = require_login();
$owner = data_owner_id();
$pdo = db();
$action = $_GET['action'] ?? '';

$writes = ['read', 'read_all', 'delete', 'clear'];
if (in_array($action, $writes, true)) {
    if (!csrf_check($_SERVER['HTTP_X_CSRF'] ?? '')) json_out(['error' => 'CSRF'], 403);
}

switch ($action) {

  case 'list':
    $st = $pdo->prepare('SELECT id,type,title,body,url,is_read,group_key,created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 30');
    $st->execute([$user['id']]);
    json_out(['items' => $st->fetchAll(), 'unread' => unread_count((int)$user['id'])]);
    break;

  case 'poll':
    // Génère si nécessaire, renvoie les fraîches (dernières 10 min, non lues)
    $fn = $user['full_name'] ?: $user['username'];
    generate_notifications((int)$user['id'], $owner, preg_split('/\s+/', trim($fn))[0] ?? $fn);
    $st = $pdo->prepare("SELECT id,type,title,body,url,group_key FROM notifications
                         WHERE user_id=? AND is_read=0 AND created_at > (NOW()-INTERVAL 10 MINUTE)
                         ORDER BY created_at DESC LIMIT 3");
    $st->execute([$user['id']]);
    json_out(['fresh' => $st->fetchAll(), 'unread' => unread_count((int)$user['id'])]);
    break;

  case 'read':
    $id = (int)(json_in()['id'] ?? 0);
    $pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([$id, $user['id']]);
    json_out(['ok' => true, 'unread' => unread_count((int)$user['id'])]);
    break;

  case 'read_all':
    $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([$user['id']]);
    json_out(['ok' => true, 'unread' => 0]);
    break;

  case 'delete':
    $id = (int)(json_in()['id'] ?? 0);
    $pdo->prepare('DELETE FROM notifications WHERE id=? AND user_id=?')->execute([$id, $user['id']]);
    json_out(['ok' => true, 'unread' => unread_count((int)$user['id'])]);
    break;

  case 'clear':
    // Efface toutes les notifications de l'utilisateur.
    // On conserve notif_meta (throttle) pour éviter qu'elles ne réapparaissent aussitôt.
    $pdo->prepare('DELETE FROM notifications WHERE user_id=?')->execute([$user['id']]);
    json_out(['ok' => true, 'unread' => 0]);
    break;

  default:
    json_out(['error' => 'Action inconnue.'], 400);
}
