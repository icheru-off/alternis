<?php
/**
 * Sert la photo de profil depuis la base (stockage durable) ou, à défaut,
 * depuis un ancien fichier. Réservé aux utilisateurs connectés.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$uid = (int)($_GET['u'] ?? 0);
if ($uid <= 0) { http_response_code(400); exit; }

$pdo = db();

// 1) Version en base ?
try {
    $st = $pdo->prepare('SELECT mime, data, updated_at FROM user_avatars WHERE user_id=?');
    $st->execute([$uid]);
    if ($row = $st->fetch()) {
        $etag = '"' . md5($uid . $row['updated_at']) . '"';
        header('Content-Type: ' . ($row['mime'] ?: 'image/png'));
        header('Cache-Control: private, max-age=86400');
        header('ETag: ' . $etag);
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
        echo $row['data'];
        exit;
    }
} catch (Throwable $e) {
    // table absente : on tente le fichier
}

// 2) Repli : ancien fichier sur disque
$u = $pdo->prepare('SELECT avatar FROM users WHERE id=?');
$u->execute([$uid]);
$av = $u->fetchColumn();
if ($av && substr($av, 0, 3) !== 'db:' && is_file(UPLOAD_DIR . '/' . $av)) {
    $path = UPLOAD_DIR . '/' . $av;
    $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'image/jpeg') : 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;
}

http_response_code(404);
exit;
