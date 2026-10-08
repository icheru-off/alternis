<?php
/** Alternis — abonnement / désabonnement aux notifications push. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/push.php';
$user = require_login();
$action = $_GET['action'] ?? '';

// La clé publique peut être lue sans jeton CSRF (elle n'est pas secrète).
if ($action === 'key') {
    json_out(['key' => push_enabled() ? VAPID_PUBLIC_KEY : null]);
}

// L'abonnement peut être envoyé par le service worker (événement
// pushsubscriptionchange), qui ne dispose pas du jeton CSRF de la page.
// Il reste protégé par la session : seul un service worker de notre
// propre origine peut atteindre ce point.
if ($action === 'subscribe') {
    $sub = json_in();
    if (!push_enabled()) json_out(['error' => 'Push non configuré sur le serveur.'], 503);
    $err = null;
    $ok = push_subscribe((int)$user['id'], $sub, $_SERVER['HTTP_USER_AGENT'] ?? '', $err);
    if (!$ok) json_out(['error' => 'Abonnement non enregistré : ' . ($err ?: 'raison inconnue')], 422);
    json_out(['ok' => true]);
}

if (!csrf_check($_SERVER['HTTP_X_CSRF'] ?? '')) json_out(['error' => 'CSRF'], 403);

if ($action === 'unsubscribe') {
    $endpoint = (string)(json_in()['endpoint'] ?? '');
    if ($endpoint) push_unsubscribe($endpoint);
    json_out(['ok' => true]);
}

if ($action === 'test') {
    ensure_push_table();
    $miss = wp_missing_requirements();
    if ($miss) {
        json_out(['ok' => false, 'sent' => 0, 'subs' => 0,
                  'reason' => 'server', 'error' => 'Serveur incompatible : ' . implode(', ', $miss)], 200);
    }

    $st = db()->prepare('SELECT * FROM push_subscriptions WHERE user_id=?');
    $st->execute([(int)$user['id']]);
    $subs = $st->fetchAll();
    if (!$subs) {
        json_out(['ok' => false, 'sent' => 0, 'subs' => 0, 'reason' => 'nosub',
                  'error' => "Aucun abonnement enregistré côté serveur pour ce compte."], 200);
    }

    // Envoi réel, en remontant l'erreur exacte de chaque service push
    $sent = 0; $details = [];
    foreach ($subs as $sub) {
        $res = wp_send(
            ['endpoint' => $sub['endpoint'], 'p256dh' => $sub['p256dh'], 'auth' => $sub['auth']],
            ['title' => 'Alternis', 'body' => 'Notification de test — tout fonctionne 🎉', 'url' => 'index.php?page=dashboard']
        );
        $host = parse_url($sub['endpoint'], PHP_URL_HOST);
        $details[] = ['service' => $host, 'status' => $res['status'], 'error' => $res['error']];
        if ($res['ok']) {
            $sent++;
            db()->prepare('UPDATE push_subscriptions SET last_ok=NOW(), fail_count=0 WHERE id=?')->execute([$sub['id']]);
        } elseif ($res['gone']) {
            push_unsubscribe($sub['endpoint']);
        }
    }
    json_out(['ok' => $sent > 0, 'sent' => $sent, 'subs' => count($subs),
              'reason' => $sent > 0 ? 'ok' : 'send', 'details' => $details]);
}

if ($action === 'diag') {
    ensure_push_table();
    $n = 0;
    try {
        $q = db()->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id=?');
        $q->execute([(int)$user['id']]);
        $n = (int)$q->fetchColumn();
    } catch (Throwable $e) {}
    json_out([
        'php'            => PHP_VERSION,
        'push_enabled'   => push_enabled(),
        'missing'        => wp_missing_requirements(),
        'subscriptions'  => $n,
        'curl'           => function_exists('curl_init'),
        'app_url'        => defined('APP_URL') ? APP_URL : null,
        'cron_configured'=> (bool)setting_get('cron_last_run', ''),
        'cron_last_run'  => setting_get('cron_last_run', 'jamais'),
    ]);
}

json_out(['error' => 'Action inconnue.'], 400);
