<?php
/**
 * API de l'extension navigateur (jetons, sans cookie ni CSRF).
 * Réponses JSON, CORS ouvert (l'extension appelle depuis n'importe quel onglet).
 *
 * Actions :
 *   POST ?action=login    { identifier, app_password }        -> { token, user }
 *   GET  ?action=me       (Bearer)                            -> { user }
 *   POST ?action=capture  (Bearer) { name, position, ... }    -> { ok, id }
 *   GET  ?action=meta     (Bearer)                            -> { statuses, channels }
 *   POST ?action=logout   (Bearer)                            -> { ok }
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ext_auth.php';
require_once __DIR__ . '/../includes/orgs.php';

// --- CORS : l'extension s'exécute hors de notre origine ---
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function ext_out($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function ext_body(): array
{
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

$action = $_GET['action'] ?? '';

/* ---------- Connexion : mot de passe d'application -> jeton ---------- */
if ($action === 'login') {
    $d = ext_body();
    $identifier = trim($d['identifier'] ?? '');
    $appPw = trim($d['app_password'] ?? '');
    if ($identifier === '' || $appPw === '') ext_out(['error' => 'Identifiant et mot de passe requis.'], 422);

    $u = app_password_check($identifier, $appPw);
    if (!$u) {
        // Petit délai pour limiter le brute-force
        usleep(400000);
        ext_out(['error' => 'Identifiant ou mot de passe d\'application incorrect.'], 401);
    }
    $token = ext_token_issue((int)$u['id'], $u['_app_pw_id'] ?? null);
    ext_out([
        'ok' => true,
        'token' => $token,
        'user' => ['id' => (int)$u['id'], 'name' => $u['full_name'] ?: $u['username'], 'username' => $u['username']],
    ]);
}

/* ---------- Toutes les autres actions exigent un jeton ---------- */
$user = ext_token_user(ext_bearer_token());
if (!$user) ext_out(['error' => 'Jeton invalide ou expiré. Reconnectez-vous.'], 401);
$owner = (int)$user['user_id'];

if ($action === 'me') {
    ext_out(['ok' => true, 'user' => ['id' => $owner, 'name' => $user['full_name'] ?: $user['username']]]);
}

if ($action === 'meta') {
    ext_out(['ok' => true, 'statuses' => status_labels(), 'channels' => apply_channels()]);
}

if ($action === 'logout') {
    $tok = ext_bearer_token();
    if ($tok) db()->prepare('DELETE FROM ext_tokens WHERE token_hash = ?')->execute([hash('sha256', $tok)]);
    ext_out(['ok' => true]);
}

/* ---------- Capture d'une candidature ---------- */
if ($action === 'capture') {
    $d = ext_body();
    $name = trim($d['name'] ?? '');
    if ($name === '') ext_out(['error' => 'Le nom de l\'entreprise est requis.'], 422);

    $validStatus = array_keys(status_labels());
    $status = in_array($d['status'] ?? '', $validStatus, true) ? $d['status'] : 'envoye';
    $cleanDate = function ($x) {
        return (is_string($x) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $x)) ? $x : null;
    };

    $fields = [
        'name'          => mb_substr($name, 0, 160),
        'position'      => mb_substr(trim($d['position'] ?? ''), 0, 160),
        'sector'        => mb_substr(trim($d['sector'] ?? ''), 0, 120),
        'city'          => mb_substr(trim($d['city'] ?? ''), 0, 120),
        'website'       => mb_substr(trim($d['website'] ?? ''), 0, 200),
        'apply_channel' => mb_substr(trim($d['apply_channel'] ?? ''), 0, 60),
        'source_url'    => mb_substr(trim($d['source_url'] ?? ''), 0, 400),
        'status'        => $status,
        'applied_date'  => $cleanDate($d['applied_date'] ?? null),
        'notes'         => mb_substr(trim($d['notes'] ?? ''), 0, 2000),
    ];

    try {
        $pdo = db();
        // Colonne source_url (trace de l'offre) créée à la volée si absente
        try {
            $has = $pdo->query("SHOW COLUMNS FROM companies LIKE 'source_url'")->fetch();
            if (!$has) $pdo->exec("ALTER TABLE companies ADD COLUMN source_url VARCHAR(400) NOT NULL DEFAULT '' AFTER website");
        } catch (Throwable $e) { /* ignore */ }

        $orgId = org_find_or_create($owner, $fields['name'], $fields);

        $st = $pdo->prepare('INSERT INTO companies
            (owner_id, created_by, org_id, name, position, sector, city, website, source_url, apply_channel, status, applied_date, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$owner, $owner, $orgId, $fields['name'], $fields['position'], $fields['sector'],
            $fields['city'], $fields['website'], $fields['source_url'], $fields['apply_channel'],
            $fields['status'], $fields['applied_date'], $fields['notes']]);
        $id = (int)$pdo->lastInsertId();

        log_activity($owner, 'ext_capture', 'company', $id, $fields['name'], $owner);
        ext_out(['ok' => true, 'id' => $id, 'name' => $fields['name']]);
    } catch (Throwable $e) {
        ext_out(['error' => 'Enregistrement impossible.'], 500);
    }
}

ext_out(['error' => 'Action inconnue.'], 400);
