<?php
/**
 * Alternis — Échanges d'e-mails liés à une entreprise.
 * Actions : list, add, delete
 */
require_once __DIR__ . '/../includes/auth.php';
$user  = require_login();
$owner = data_owner_id();
$pdo   = db();
$action = $_GET['action'] ?? '';

function ensure_emails_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS `company_emails` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `company_id` INT UNSIGNED NOT NULL,
        `owner_id` INT UNSIGNED NOT NULL,
        `direction` ENUM("recu","envoye") NOT NULL DEFAULT "recu",
        `subject` VARCHAR(200) NOT NULL DEFAULT "",
        `body` TEXT NOT NULL,
        `email_date` DATE DEFAULT NULL,
        `attachment_name` VARCHAR(200) DEFAULT NULL,
        `attachment_file` VARCHAR(120) DEFAULT NULL,
        `attachment_mime` VARCHAR(100) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `idx_mail_company` (`company_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Colonnes de pièce jointe pour les tables déjà existantes
    try {
        $has = (bool)$pdo->query("SHOW COLUMNS FROM company_emails LIKE 'attachment_file'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE company_emails
                ADD COLUMN attachment_name VARCHAR(200) DEFAULT NULL,
                ADD COLUMN attachment_file VARCHAR(120) DEFAULT NULL,
                ADD COLUMN attachment_mime VARCHAR(100) DEFAULT NULL");
        }
    } catch (Throwable $e) {}
}
try { ensure_emails_table($pdo); } catch (Throwable $e) {}

/** Vérifie que l'entreprise appartient bien au périmètre de l'utilisateur. */
function own_company(PDO $pdo, int $cid, int $owner): bool
{
    $st = $pdo->prepare('SELECT id FROM companies WHERE id=? AND owner_id=?');
    $st->execute([$cid, $owner]);
    return (bool)$st->fetch();
}

if ($action === 'list') {
    $cid = (int)($_GET['company_id'] ?? 0);
    if (!own_company($pdo, $cid, $owner)) json_out(['error' => 'Introuvable.'], 404);
    $st = $pdo->prepare('SELECT id, direction, subject, body, email_date, created_at,
                         attachment_name, attachment_mime,
                         CASE WHEN attachment_file IS NOT NULL THEN 1 ELSE 0 END AS has_attachment
                         FROM company_emails WHERE company_id=? ORDER BY COALESCE(email_date, created_at) DESC, id DESC');
    $st->execute([$cid]);
    json_out(['items' => $st->fetchAll()]);
}

if ($action === 'add') {
    if (!csrf_check($_SERVER['HTTP_X_CSRF'] ?? '')) json_out(['error' => 'CSRF'], 403);
    $isMultipart = !empty($_POST) || !empty($_FILES);
    $d = $isMultipart ? $_POST : json_in();
    $cid = (int)($d['company_id'] ?? 0);
    if (!own_company($pdo, $cid, $owner)) json_out(['error' => 'Introuvable.'], 404);
    $dir = ($d['direction'] ?? 'recu') === 'envoye' ? 'envoye' : 'recu';
    $subject = mb_substr(trim($d['subject'] ?? ''), 0, 200);
    $body = trim($d['body'] ?? '');
    $date = (isset($d['email_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['email_date'])) ? $d['email_date'] : null;

    $attName = $attFile = $attMime = null;
    if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['attachment'];
        if ($f['size'] > 8 * 1024 * 1024) json_out(['error' => 'Piece jointe trop lourde (max 8 Mo).'], 422);
        $mdir = UPLOAD_DIR . '/mail';
        if (!is_dir($mdir)) @mkdir($mdir, 0775, true);
        $ext = preg_replace('/[^a-zA-Z0-9]/', '', substr((string)pathinfo($f['name'], PATHINFO_EXTENSION), 0, 8));
        $stored = 'm' . $cid . '_' . bin2hex(random_bytes(6)) . ($ext ? '.' . $ext : '');
        if (move_uploaded_file($f['tmp_name'], $mdir . '/' . $stored)) {
            $attName = mb_substr($f['name'], 0, 200);
            $attFile = $stored;
            $attMime = mb_substr((string)(function_exists('mime_content_type') ? (mime_content_type($mdir . '/' . $stored) ?: $f['type']) : $f['type']), 0, 100);
        }
    }

    if ($body === '' && $subject === '' && !$attFile) json_out(['error' => 'Ajoutez un objet, un message ou une piece jointe.'], 422);
    $st = $pdo->prepare('INSERT INTO company_emails (company_id, owner_id, direction, subject, body, email_date, attachment_name, attachment_file, attachment_mime)
                         VALUES (?,?,?,?,?,?,?,?,?)');
    $st->execute([$cid, $owner, $dir, $subject, $body, $date, $attName, $attFile, $attMime]);
    log_activity((int)$user['id'], 'email_add', 'company', $cid, $dir, $owner);
    json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'attachment') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT ce.attachment_name, ce.attachment_file, ce.attachment_mime
                         FROM company_emails ce JOIN companies c ON c.id = ce.company_id
                         WHERE ce.id=? AND c.owner_id=?');
    $st->execute([$id, $owner]);
    $m = $st->fetch();
    if (!$m || !$m['attachment_file']) { http_response_code(404); exit; }
    $path = UPLOAD_DIR . '/mail/' . $m['attachment_file'];
    if (!is_file($path)) { http_response_code(404); exit; }
    header('Content-Type: ' . ($m['attachment_mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $m['attachment_name'] ?: 'piece-jointe') . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if ($action === 'delete') {
    if (!csrf_check($_SERVER['HTTP_X_CSRF'] ?? '')) json_out(['error' => 'CSRF'], 403);
    $id = (int)(json_in()['id'] ?? 0);
    // Supprime seulement si l'e-mail appartient à une entreprise du périmètre
    $st = $pdo->prepare('DELETE ce FROM company_emails ce
                         JOIN companies c ON c.id = ce.company_id
                         WHERE ce.id=? AND c.owner_id=?');
    $st->execute([$id, $owner]);
    json_out(['ok' => true]);
}

json_out(['error' => 'Action inconnue.'], 400);
