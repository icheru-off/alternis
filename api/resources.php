<?php
/**
 * Alternis — API des ressources (CV, lettre de motivation, documents).
 * Actions : list, upload, download, delete
 */
require_once __DIR__ . '/../includes/auth.php';
$user  = require_login();
$owner = data_owner_id();
$pdo   = db();
$action = $_GET['action'] ?? '';

define('RES_DIR', UPLOAD_DIR . '/resources');

/* Types autorisés : extension => libellé mime attendu */
$allowedExt = [
    'pdf'  => ['application/pdf'],
    'doc'  => ['application/msword'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'odt'  => ['application/vnd.oasis.opendocument.text', 'application/zip'],
    'rtf'  => ['application/rtf', 'text/rtf'],
    'txt'  => ['text/plain'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
];
$kinds = ['cv', 'lettre', 'autre'];

/* ------------------------------------------------------------------ list */
if ($action === 'list') {
    $st = $pdo->prepare('SELECT id, kind, title, original_name, mime, size, updated_at, created_at
                         FROM resources WHERE owner_id=? ORDER BY FIELD(kind,\'cv\',\'lettre\',\'autre\'), updated_at DESC');
    $st->execute([$owner]);
    json_out(['items' => $st->fetchAll()]);
}

/* ---------------------------------------------------------------- upload */
if ($action === 'upload') {
    if (!csrf_check($_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? ''))) {
        json_out(['error' => 'Jeton de sécurité invalide.'], 403);
    }
    $kind = in_array($_POST['kind'] ?? '', $kinds, true) ? $_POST['kind'] : 'autre';
    $title = trim($_POST['title'] ?? '');

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $msg = 'Aucun fichier reçu.';
        if (($_FILES['file']['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($_FILES['file']['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
            $msg = 'Fichier trop volumineux pour le serveur.';
        }
        json_out(['error' => $msg], 422);
    }
    $f = $_FILES['file'];
    if ($f['size'] > 8 * 1024 * 1024) json_out(['error' => 'Fichier trop lourd (8 Mo maximum).'], 422);

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!isset($allowedExt[$ext])) {
        json_out(['error' => 'Format non supporté (PDF, DOC, DOCX, ODT, RTF, TXT, JPG, PNG).'], 422);
    }
    // Vérifie le type réel du fichier
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($f['tmp_name']) ?: 'application/octet-stream';
    if (!in_array($realMime, $allowedExt[$ext], true) && $realMime !== 'text/plain') {
        // tolérance : certains .docx/.odt sont détectés « application/zip »
        json_out(['error' => 'Le contenu du fichier ne correspond pas à son extension.'], 422);
    }

    if (!is_dir(RES_DIR) && !@mkdir(RES_DIR, 0775, true)) {
        json_out(['error' => 'Dossier de stockage inaccessible.'], 500);
    }

    $stored = 'r' . $owner . '_' . $kind . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], RES_DIR . '/' . $stored)) {
        json_out(['error' => 'Écriture impossible. Vérifiez les droits du dossier uploads/resources.'], 500);
    }

    // Pour un CV ou une lettre, on ne conserve qu'un exemplaire courant : on remplace.
    if ($kind === 'cv' || $kind === 'lettre') {
        $old = $pdo->prepare('SELECT id, stored_name FROM resources WHERE owner_id=? AND kind=?');
        $old->execute([$owner, $kind]);
        foreach ($old->fetchAll() as $o) {
            if ($o['stored_name'] && file_exists(RES_DIR . '/' . $o['stored_name'])) {
                @unlink(RES_DIR . '/' . $o['stored_name']);
            }
            $pdo->prepare('DELETE FROM resources WHERE id=?')->execute([$o['id']]);
        }
    }

    if ($title === '') {
        $title = $kind === 'cv' ? 'Mon CV' : ($kind === 'lettre' ? 'Lettre de motivation' : pathinfo($f['name'], PATHINFO_FILENAME));
    }

    $st = $pdo->prepare('INSERT INTO resources (owner_id, kind, title, original_name, stored_name, mime, size, uploaded_by)
                         VALUES (?,?,?,?,?,?,?,?)');
    $st->execute([
        $owner, $kind, mb_substr($title, 0, 160),
        mb_substr($f['name'], 0, 255), $stored, mb_substr($realMime, 0, 120),
        (int)$f['size'], $user['id'],
    ]);
    log_activity((int)$user['id'], 'resource_upload', $kind, (int)$pdo->lastInsertId(), $title, $owner);
    json_out(['ok' => true]);
}

/* -------------------------------------------------------------- download */
if ($action === 'download') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM resources WHERE id=? AND owner_id=?');
    $st->execute([$id, $owner]);
    $r = $st->fetch();
    if (!$r) { http_response_code(404); die('Ressource introuvable.'); }
    $path = RES_DIR . '/' . $r['stored_name'];
    if (!is_file($path)) { http_response_code(404); die('Fichier absent du serveur.'); }

    $disp = (($_GET['inline'] ?? '') === '1') ? 'inline' : 'attachment';
    header('Content-Type: ' . ($r['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: ' . $disp . '; filename="' . rawurlencode($r['original_name']) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

/* ---------------------------------------------------------------- delete */
if ($action === 'delete') {
    if (!csrf_check($_SERVER['HTTP_X_CSRF'] ?? '')) json_out(['error' => 'CSRF'], 403);
    $id = (int)(json_in()['id'] ?? 0);
    $st = $pdo->prepare('SELECT stored_name FROM resources WHERE id=? AND owner_id=?');
    $st->execute([$id, $owner]);
    if ($r = $st->fetch()) {
        if ($r['stored_name'] && file_exists(RES_DIR . '/' . $r['stored_name'])) {
            @unlink(RES_DIR . '/' . $r['stored_name']);
        }
        $pdo->prepare('DELETE FROM resources WHERE id=? AND owner_id=?')->execute([$id, $owner]);
        log_activity((int)$user['id'], 'resource_delete', 'resource', $id, '', $owner);
    }
    json_out(['ok' => true]);
}

json_out(['error' => 'Action inconnue.'], 400);
