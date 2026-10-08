<?php
/**
 * Alternis — Tâche planifiée.
 *
 * À appeler toutes les 5 minutes par le cron de l'hébergeur :
 *   /usr/bin/php -q /home/USER/public_html/cron.php token=VOTRE_TOKEN
 * ou par URL :
 *   curl -s "https://alternis.example.com/cron.php?token=VOTRE_TOKEN"
 *
 * C'est ce qui permet aux notifications de partir même quand
 * PERSONNE n'a l'application ouverte.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/push.php';
require_once __DIR__ . '/includes/followups.php';

$cli = PHP_SAPI === 'cli';

// Jeton : en argument (cli) ou en paramètre d'URL
$token = '';
if ($cli) {
    foreach ($argv ?? [] as $a) if (strpos($a, 'token=') === 0) $token = substr($a, 6);
} else {
    $token = (string)($_GET['token'] ?? '');
}
if (!defined('CRON_TOKEN') || CRON_TOKEN === '' || !hash_equals(CRON_TOKEN, $token)) {
    if (!$cli) http_response_code(403);
    exit("Interdit.\n");
}

if (!$cli) header('Content-Type: text/plain; charset=utf-8');
$t0 = microtime(true);

// 1) Notifications programmées échues -> insérées en base
ensure_admin_tables();
process_scheduled_notifications();

// 2) Notifications automatiques (relances, entretiens…) pour chaque utilisateur actif
$made = 0;
$autoFollowups = 0;
try {
    $users = db()->query("SELECT id, full_name, username, email, phone, role, linked_student_id
                          FROM users WHERE is_active=1")->fetchAll();
    foreach ($users as $u) {
        $ownerId = ($u['role'] === 'parent' && $u['linked_student_id']) ? (int)$u['linked_student_id'] : (int)$u['id'];
        $name = $u['full_name'] ?: $u['username'];
        $made += generate_notifications((int)$u['id'], $ownerId, $name);
        // Relances automatiques depuis le Gmail personnel (si activé et configuré)
        try { $autoFollowups += followups_auto_send((int)$u['id'], $ownerId, $u); } catch (Throwable $e) {}
    }
} catch (Throwable $e) {}

// 3) Envoi push de tout ce qui n'est pas encore parti
$pushed = push_flush_pending(60);

// Trace du dernier passage (visible dans le diagnostic)
try { setting_set('cron_last_run', date('Y-m-d H:i:s')); } catch (Throwable $e) {}

$ms = round((microtime(true) - $t0) * 1000);
echo "OK — notifications creees: $made | relances auto: $autoFollowups | push envoyes: $pushed | {$ms}ms\n";
