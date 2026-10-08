<?php
/** Alternis — Relances assistées. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orgs.php';
require_once __DIR__ . '/../includes/followups.php';
require_once __DIR__ . '/../includes/mailer.php';
$user = require_login();
$owner = data_owner_id();
$pdo = db();
$action = $_GET['action'] ?? '';

$writes = ['send', 'record', 'snooze', 'dismiss', 'settings'];
if (in_array($action, $writes, true) && !csrf_check($_SERVER['HTTP_X_CSRF'] ?? '')) {
    json_out(['error' => 'Jeton de sécurité invalide.'], 403);
}

/** Candidature appartenant bien au propriétaire courant. */
function fu_company(PDO $pdo, int $id, int $owner): ?array
{
    $st = $pdo->prepare('SELECT c.*, o.name AS org_name FROM companies c
                         LEFT JOIN orgs o ON o.id = c.org_id
                         WHERE c.id=? AND c.owner_id=?');
    $st->execute([$id, $owner]);
    $r = $st->fetch();
    return $r ?: null;
}

if ($action === 'list') {
    $due = followups_due($owner, (int)$user['id']);
    $items = array_map(fn($c) => [
        'id' => (int)$c['id'],
        'name' => $c['org_name'] ?: $c['name'],
        'position' => $c['position'],
        'contact_name' => $c['contact_name'],
        'email' => $c['email'],
        'status' => $c['status'],
        'applied_date' => $c['applied_date'],
        'days_since' => (int)$c['days_since'],
        'relances' => (int)$c['relances'],
        'can_email' => filter_var($c['email'], FILTER_VALIDATE_EMAIL) !== false,
    ], $due);
    $gmail = alt_gmail_ready((int)$user['id']);
    json_out([
        'items' => $items,
        'delay' => followup_delay_days((int)$user['id']),
        'gmail_ready' => $gmail,
        'gmail_error' => $gmail ? '' : (alt_mail_account('gmail', (int)$user['id'])['missing'] ?? ''),
    ]);
}

if ($action === 'draft') {
    $c = fu_company($pdo, (int)($_GET['id'] ?? 0), $owner);
    if (!$c) json_out(['error' => 'Introuvable.'], 404);
    $variant = $_GET['variant'] ?? '1';
    if ($variant !== 'interview') $variant = (int)$variant === 2 ? 2 : 1;
    $me = current_user();
    $d = followup_draft($c, $me ?: [], $variant);
    json_out(['subject' => $d['subject'], 'body' => $d['body'], 'to' => $c['email'], 'to_name' => $c['contact_name']]);
}

/** Envoi réel, après relecture par l'utilisateur. */
if ($action === 'send') {
    $d = json_in();
    $c = fu_company($pdo, (int)($d['id'] ?? 0), $owner);
    if (!$c) json_out(['error' => 'Introuvable.'], 404);

    // Les relances partent de la messagerie personnelle de l'étudiant (Gmail),
    // pas de la boîte système : le recruteur voit et répond à la bonne adresse.
    if (!alt_gmail_ready((int)$user['id'])) {
        $why = alt_mail_account('gmail', (int)$user['id'])['missing'] ?? 'Gmail non configuré.';
        json_out(['error' => $why, 'gmail_missing' => true], 503);
    }

    $to = trim((string)($d['to'] ?? $c['email']));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) json_out(['error' => "Adresse du destinataire invalide."], 422);
    $subject = trim((string)($d['subject'] ?? ''));
    $body = trim((string)($d['body'] ?? ''));
    if ($subject === '' || $body === '') json_out(['error' => 'Objet et message obligatoires.'], 422);

    $res = alt_send_mail(
        $to, (string)$c['contact_name'], $subject, followup_html($body), $body,
        ['account' => 'gmail', 'user_id' => (int)$user['id']]
    );
    if (empty($res['ok'])) {
        json_out(['error' => "L'envoi via Gmail a échoué : " . ($res['error'] ?? 'raison inconnue'), 'gmail_error' => true], 502);
    }

    followup_record((int)$c['id'], $owner, $subject, $body);
    log_activity((int)$user['id'], 'followup_sent', 'company', (int)$c['id'], $c['name'], $owner);
    json_out(['ok' => true, 'sent' => true]);
}

/** L'utilisateur a relancé lui-même (depuis sa propre boîte mail). */
if ($action === 'record') {
    $d = json_in();
    $c = fu_company($pdo, (int)($d['id'] ?? 0), $owner);
    if (!$c) json_out(['error' => 'Introuvable.'], 404);
    $subject = trim((string)($d['subject'] ?? '')) ?: 'Relance';
    if (stripos($subject, 'relance') !== 0) $subject = 'Relance — ' . $subject;
    followup_record((int)$c['id'], $owner, $subject, trim((string)($d['body'] ?? '')));
    json_out(['ok' => true, 'sent' => false]);
}

/** Reporter : on repousse l'échéance sans rien envoyer. */
if ($action === 'snooze') {
    $d = json_in();
    $c = fu_company($pdo, (int)($d['id'] ?? 0), $owner);
    if (!$c) json_out(['error' => 'Introuvable.'], 404);
    $days = (int)($d['days'] ?? 7);
    $days = max(1, min(90, $days));
    // La candidature redevient « à relancer » quand
    //   CURDATE() - followup_date >= delai.
    // Pour qu'elle ressorte dans `days` jours, on pose
    //   followup_date = aujourd'hui + (days - delai).
    // L'écart peut être négatif : c'est voulu, MySQL l'accepte.
    $offset = $days - followup_delay_days((int)$user['id']);
    $pdo->prepare('UPDATE companies SET followup_date = DATE_ADD(CURDATE(), INTERVAL ? DAY) WHERE id=? AND owner_id=?')
        ->execute([$offset, (int)$c['id'], $owner]);
    json_out(['ok' => true]);
}

/** Ne plus proposer : la candidature est considérée close côté relances. */
if ($action === 'dismiss') {
    $d = json_in();
    $c = fu_company($pdo, (int)($d['id'] ?? 0), $owner);
    if (!$c) json_out(['error' => 'Introuvable.'], 404);
    $pdo->prepare("UPDATE companies SET response_date = CURDATE() WHERE id=? AND owner_id=? AND response_date IS NULL")
        ->execute([(int)$c['id'], $owner]);
    json_out(['ok' => true]);
}

if ($action === 'settings') {
    $d = json_in();
    $days = (int)($d['delay'] ?? 10);
    $days = max(3, min(60, $days));
    setting_set('followup_delay_' . (int)$user['id'], (string)$days);
    json_out(['ok' => true, 'delay' => $days]);
}

json_out(['error' => 'Action inconnue.'], 400);
