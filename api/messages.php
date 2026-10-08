<?php
/** Alternis — marque un message/bannière admin comme lu pour l'utilisateur courant. */
require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
if (!csrf_check($_SERVER['HTTP_X_CSRF'] ?? '')) json_out(['error' => 'CSRF'], 403);
$id = (int)(json_in()['id'] ?? 0);
if ($id > 0) {
    try {
        ensure_admin_tables();
        db()->prepare('INSERT IGNORE INTO admin_message_reads (message_id, user_id) VALUES (?,?)')
            ->execute([$id, $user['id']]);
    } catch (Throwable $e) {}
}
json_out(['ok' => true]);
