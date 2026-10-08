<?php
/** Alternis — gestion des liens de partage en lecture seule. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/share.php';
$user = require_login();
$owner = data_owner_id();
$pdo = db();
ensure_share_table();

if (!csrf_check($_SERVER['HTTP_X_CSRF'] ?? '')) json_out(['error' => 'CSRF'], 403);
$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $st = $pdo->prepare('SELECT * FROM share_links WHERE owner_id=? ORDER BY created_at DESC');
    $st->execute([$owner]);
    $items = [];
    foreach ($st->fetchAll() as $r) {
        $expired = !empty($r['expires_at']) && strtotime($r['expires_at']) < time();
        $items[] = [
            'id' => (int)$r['id'],
            'label' => $r['label'],
            'url' => share_url($r['token']),
            'statuses' => $r['statuses'],
            'views' => (int)$r['views'],
            'last_view' => $r['last_view'],
            'expires_at' => $r['expires_at'],
            'revoked' => (int)$r['revoked'],
            'expired' => $expired,
            'created_at' => $r['created_at'],
        ];
    }
    json_out(['items' => $items]);
}

if ($action === 'create') {
    $d = json_in();
    $label = mb_substr(trim($d['label'] ?? ''), 0, 120);

    $validStatus = array_keys(status_labels());
    $statuses = array_values(array_intersect((array)($d['statuses'] ?? []), $validStatus));

    $allFields = array_keys(pdf_fields_for_share());
    $fields = array_values(array_intersect((array)($d['fields'] ?? []), $allFields));

    $showContact = !empty($d['show_contact']) ? 1 : 0;
    $showNotes = !empty($d['show_notes']) ? 1 : 0;

    // Durée de validité : 7 / 30 / 90 jours, ou illimitée
    $days = (int)($d['expires_days'] ?? 30);
    $expires = in_array($days, [7, 30, 90], true) ? date('Y-m-d H:i:s', time() + $days * 86400) : null;

    $token = share_new_token();
    $st = $pdo->prepare('INSERT INTO share_links (token, owner_id, created_by, label, statuses, fields, show_contact, show_notes, expires_at)
                         VALUES (?,?,?,?,?,?,?,?,?)');
    $st->execute([$token, $owner, $user['id'], $label, implode(',', $statuses), implode(',', $fields), $showContact, $showNotes, $expires]);
    log_activity((int)$user['id'], 'share_create', 'share', (int)$pdo->lastInsertId(), $label, $owner);
    json_out(['ok' => true, 'url' => share_url($token), 'expires_at' => $expires]);
}

if ($action === 'revoke') {
    $id = (int)(json_in()['id'] ?? 0);
    $st = $pdo->prepare('UPDATE share_links SET revoked=1 WHERE id=? AND owner_id=?');
    $st->execute([$id, $owner]);
    log_activity((int)$user['id'], 'share_revoke', 'share', $id, '', $owner);
    json_out(['ok' => true]);
}

if ($action === 'delete') {
    $id = (int)(json_in()['id'] ?? 0);
    $pdo->prepare('DELETE FROM share_links WHERE id=? AND owner_id=?')->execute([$id, $owner]);
    json_out(['ok' => true]);
}

json_out(['error' => 'Action inconnue.'], 400);
