<?php
/**
 * Alternis — Couche « push » côté serveur.
 * Stocke les abonnements des navigateurs et pousse les notifications,
 * même lorsque l'application est fermée.
 */
require_once __DIR__ . '/../lib/webpush.php';

function ensure_push_table(): void
{
    static $done = false;
    if ($done) return;
    $pdo = db();

    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS `push_subscriptions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `endpoint` VARCHAR(1000) NOT NULL,
            `p256dh` VARCHAR(255) NOT NULL,
            `auth` VARCHAR(120) NOT NULL,
            `ua` VARCHAR(255) DEFAULT NULL,
            `last_ok` DATETIME NULL,
            `fail_count` SMALLINT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_endpoint` (`endpoint`(191)),
            KEY `idx_push_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } catch (Throwable $e) {}

    // « CREATE TABLE IF NOT EXISTS » n'ajoute rien à une table déjà présente.
    // On réconcilie donc les colonnes une par une : une installation créée par
    // une version antérieure peut manquer de `ua`, `last_ok` ou `fail_count`.
    try {
        $cols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM push_subscriptions') as $c) {
            $cols[strtolower($c['Field'])] = $c;
        }
        $wanted = [
            'user_id'    => 'INT UNSIGNED NOT NULL',
            'endpoint'   => 'VARCHAR(1000) NOT NULL',
            'p256dh'     => 'VARCHAR(255) NOT NULL',
            'auth'       => 'VARCHAR(120) NOT NULL',
            'ua'         => 'VARCHAR(255) DEFAULT NULL',
            'last_ok'    => 'DATETIME NULL',
            'fail_count' => 'SMALLINT NOT NULL DEFAULT 0',
            'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ];
        foreach ($wanted as $name => $def) {
            if (!isset($cols[$name])) {
                try { $pdo->exec("ALTER TABLE push_subscriptions ADD COLUMN `$name` $def"); } catch (Throwable $e) {}
            }
        }
        // Les endpoints Apple dépassent souvent 500 caractères : une troncature
        // silencieuse produirait un 404, donc la purge injustifiée de l'abonnement.
        if (isset($cols['endpoint']) && stripos((string)$cols['endpoint']['Type'], 'varchar(1000)') === false) {
            try { $pdo->exec('ALTER TABLE push_subscriptions MODIFY `endpoint` VARCHAR(1000) NOT NULL'); } catch (Throwable $e) {}
        }
        // Index unique indispensable au « ON DUPLICATE KEY UPDATE »
        $hasUniq = false;
        foreach ($pdo->query('SHOW INDEX FROM push_subscriptions') as $i) {
            if (strtolower($i['Key_name']) === 'uniq_endpoint') { $hasUniq = true; break; }
        }
        if (!$hasUniq) {
            try { $pdo->exec('ALTER TABLE push_subscriptions ADD UNIQUE KEY `uniq_endpoint` (`endpoint`(191))'); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {}

    $done = true;
}

function push_enabled(): bool
{
    return defined('VAPID_PUBLIC_KEY') && VAPID_PUBLIC_KEY !== ''
        && defined('VAPID_PRIVATE_PEM') && trim(VAPID_PRIVATE_PEM) !== '';
}

/** Enregistre (ou met à jour) l'abonnement d'un navigateur. */
function push_subscribe(int $userId, array $sub, ?string $ua = null, ?string &$error = null): bool
{
    ensure_push_table();
    $endpoint = $sub['endpoint'] ?? '';
    $p256dh = $sub['keys']['p256dh'] ?? '';
    $auth = $sub['keys']['auth'] ?? '';
    if (!$endpoint || !$p256dh || !$auth) { $error = 'Données d\'abonnement incomplètes.'; return false; }
    try {
        $st = db()->prepare('INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, ua)
                             VALUES (?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), p256dh=VALUES(p256dh),
                                                     auth=VALUES(auth), ua=VALUES(ua), fail_count=0');
        $st->execute([$userId, $endpoint, $p256dh, $auth, mb_substr((string)$ua, 0, 255)]);
        return true;
    } catch (Throwable $e) {
        // Repli : colonne `ua` absente (ALTER refusé par les droits MySQL).
        // On supprime d'abord l'éventuelle ligne existante, car sans index unique
        // « ON DUPLICATE KEY » ne dédoublonnerait pas : on créerait des doublons
        // et l'appareil recevrait chaque notification plusieurs fois.
        try {
            db()->prepare('DELETE FROM push_subscriptions WHERE endpoint=?')->execute([$endpoint]);
            db()->prepare('INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?,?,?,?)')
                ->execute([$userId, $endpoint, $p256dh, $auth]);
            return true;
        } catch (Throwable $e2) { $error = $e2->getMessage(); return false; }
    }
}

function push_unsubscribe(string $endpoint): void
{
    ensure_push_table();
    try { db()->prepare('DELETE FROM push_subscriptions WHERE endpoint=?')->execute([$endpoint]); } catch (Throwable $e) {}
}

/**
 * Pousse une notification vers tous les appareils d'un utilisateur.
 * Retourne le nombre d'envois réussis.
 */
function push_send_to_user(int $userId, string $title, string $body, string $url = 'index.php?page=dashboard'): int
{
    if (!push_enabled()) return 0;
    ensure_push_table();
    try {
        $st = db()->prepare('SELECT * FROM push_subscriptions WHERE user_id=?');
        $st->execute([$userId]);
        $subs = $st->fetchAll();
    } catch (Throwable $e) { return 0; }
    if (!$subs) return 0;

    $payload = ['title' => $title ?: 'Alternis', 'body' => $body, 'url' => $url];
    $sent = 0;
    foreach ($subs as $s) {
        $res = wp_send(['endpoint' => $s['endpoint'], 'p256dh' => $s['p256dh'], 'auth' => $s['auth']], $payload);
        if ($res['ok']) {
            $sent++;
            try { db()->prepare('UPDATE push_subscriptions SET last_ok=NOW(), fail_count=0 WHERE id=?')->execute([$s['id']]); } catch (Throwable $e) {}
        } elseif ($res['gone']) {
            push_unsubscribe($s['endpoint']);           // abonnement expiré
        } else {
            try { db()->prepare('UPDATE push_subscriptions SET fail_count=fail_count+1 WHERE id=?')->execute([$s['id']]); } catch (Throwable $e) {}
        }
    }
    return $sent;
}

/**
 * Pousse les notifications en base qui n'ont pas encore été envoyées.
 * Appelé par cron.php (et, à défaut, au chargement d'une page).
 */
function push_flush_pending(int $limit = 40): int
{
    if (!push_enabled()) return 0;
    ensure_push_table();
    $pdo = db();
    // Colonne de suivi
    try {
        $has = (bool)$pdo->query("SHOW COLUMNS FROM notifications LIKE 'pushed_at'")->fetch();
        if (!$has) $pdo->exec('ALTER TABLE notifications ADD COLUMN pushed_at DATETIME NULL');
    } catch (Throwable $e) { return 0; }

    try {
        $rows = $pdo->query("SELECT id, user_id, title, body, url FROM notifications
                             WHERE pushed_at IS NULL AND created_at >= (NOW() - INTERVAL 2 DAY)
                             ORDER BY id ASC LIMIT $limit")->fetchAll();
    } catch (Throwable $e) { return 0; }
    if (!$rows) return 0;

    $mark = $pdo->prepare('UPDATE notifications SET pushed_at=NOW() WHERE id=?');
    $count = 0;
    foreach ($rows as $n) {
        // On marque d'abord : une notification ne doit jamais partir deux fois.
        $mark->execute([$n['id']]);
        $count += push_send_to_user((int)$n['user_id'], (string)$n['title'], (string)$n['body'], $n['url'] ?: 'index.php?page=dashboard');
    }
    return $count;
}
