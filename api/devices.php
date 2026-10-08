<?php
/**
 * Alternis — API des appareils connectés (sessions « se souvenir de moi »).
 * Actions : list, revoke
 */
require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
$pdo  = db();
$action = $_GET['action'] ?? '';

try { ensure_devices_table(); } catch (Throwable $e) {}

if ($action === 'list') {
    $st = $pdo->prepare('SELECT id, user_agent, ip, last_active, created_at, expires_at, selector
                         FROM user_devices WHERE user_id=? AND expires_at > NOW()
                         ORDER BY last_active DESC');
    $st->execute([$user['id']]);
    $cur = $_SESSION['device_selector'] ?? '';
    $items = array_map(function ($d) use ($cur) {
        return [
            'id'          => (int)$d['id'],
            'user_agent'  => $d['user_agent'],
            'ip'          => $d['ip'],
            'last_active' => $d['last_active'],
            'created_at'  => $d['created_at'],
            'current'     => hash_equals($cur, $d['selector']),
        ];
    }, $st->fetchAll());
    json_out(['items' => $items]);
}

if ($action === 'revoke') {
    if (!csrf_check($_SERVER['HTTP_X_CSRF'] ?? '')) json_out(['error' => 'CSRF'], 403);
    $id = (int)(json_in()['id'] ?? 0);
    // Récupère le sélecteur pour savoir si on révoque l'appareil courant
    $st = $pdo->prepare('SELECT selector FROM user_devices WHERE id=? AND user_id=?');
    $st->execute([$id, $user['id']]);
    $sel = $st->fetchColumn();
    $pdo->prepare('DELETE FROM user_devices WHERE id=? AND user_id=?')->execute([$id, $user['id']]);
    $isCurrent = $sel && hash_equals($_SESSION['device_selector'] ?? '', $sel);
    if ($isCurrent) {
        // Révoquer l'appareil courant = se déconnecter proprement
        _remember_cookie('', time() - 3600);
    }
    log_activity((int)$user['id'], 'device_revoke', 'device', $id);
    json_out(['ok' => true, 'current' => $isCurrent, 'redirect' => $isCurrent ? url('auth/login.php') : null]);
}

json_out(['error' => 'Action inconnue.'], 400);
