<?php
/**
 * Alternis — Outils d'administration
 * Tables + helpers pour : réglages du site, messages/bannières, notifications
 * programmées, mode maintenance. Création des tables à la volée.
 */

function ensure_admin_tables(): void
{
    static $done = false;
    if ($done) return;
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS `site_settings` (
        `skey` VARCHAR(60) NOT NULL PRIMARY KEY,
        `svalue` TEXT NULL,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS `admin_messages` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `target_user_id` INT UNSIGNED NULL,
        `kind` ENUM("popup","banner") NOT NULL DEFAULT "popup",
        `title` VARCHAR(160) NOT NULL DEFAULT "",
        `body` TEXT NOT NULL,
        `color` VARCHAR(20) NOT NULL DEFAULT "accent",
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `dismissible` TINYINT(1) NOT NULL DEFAULT 1,
        `starts_at` DATETIME NULL,
        `ends_at` DATETIME NULL,
        `created_by` INT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `idx_msg_target` (`target_user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS `admin_message_reads` (
        `message_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `read_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`message_id`,`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS `scheduled_notifications` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `target_user_id` INT UNSIGNED NULL,
        `title` VARCHAR(160) NOT NULL DEFAULT "",
        `body` TEXT NOT NULL,
        `freq` ENUM("once","daily","weekly") NOT NULL DEFAULT "once",
        `run_time` TIME NULL,
        `run_dow` TINYINT NULL,
        `next_run` DATETIME NOT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `last_run` DATETIME NULL,
        `created_by` INT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `idx_sched_next` (`next_run`,`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Colonnes utilisateur additionnelles
    try {
        $has = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'must_change_pwd'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE users
                ADD COLUMN must_change_pwd TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN lock_message VARCHAR(255) DEFAULT NULL");
        }
    } catch (Throwable $e) {}
    // Colonne « fermable » pour les messages déjà créés
    try {
        $has = (bool)$pdo->query("SHOW COLUMNS FROM admin_messages LIKE 'dismissible'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE admin_messages ADD COLUMN dismissible TINYINT(1) NOT NULL DEFAULT 1");
        }
    } catch (Throwable $e) {}
    $done = true;
}

function setting_get(string $key, $default = null)
{
    try {
        ensure_admin_tables();
        $st = db()->prepare('SELECT svalue FROM site_settings WHERE skey=?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : $v;
    } catch (Throwable $e) { return $default; }
}

function setting_set(string $key, $value): void
{
    ensure_admin_tables();
    $st = db()->prepare('INSERT INTO site_settings (skey, svalue, updated_at) VALUES (?,?,NOW())
                         ON DUPLICATE KEY UPDATE svalue=VALUES(svalue), updated_at=NOW()');
    $st->execute([$key, $value]);
}

/**
 * Traite les notifications programmées échues et crée les notifications réelles.
 * Appelé à chaque chargement de page (pas de cron nécessaire).
 */
function process_scheduled_notifications(): void
{
    try {
        ensure_admin_tables();
        $pdo = db();
        $due = $pdo->query('SELECT * FROM scheduled_notifications WHERE is_active=1 AND next_run <= NOW() LIMIT 20')->fetchAll();
        if (!$due) return;
        foreach ($due as $s) {
            // Destinataires
            if ($s['target_user_id']) {
                $recips = [(int)$s['target_user_id']];
            } else {
                $recips = $pdo->query('SELECT id FROM users WHERE is_active=1')->fetchAll(PDO::FETCH_COLUMN);
            }
            $ins = $pdo->prepare('INSERT INTO notifications (user_id, type, title, body, url, group_key)
                                  VALUES (?,?,?,?,?,?)');
            $gkey = 'sched_' . $s['id'] . '_' . date('YmdHi');
            foreach ($recips as $rid) {
                $ins->execute([(int)$rid, 'programme', $s['title'], $s['body'], 'index.php?page=dashboard', $gkey]);
            }
            // Calcule la prochaine échéance
            if ($s['freq'] === 'once') {
                $pdo->prepare('UPDATE scheduled_notifications SET is_active=0, last_run=NOW() WHERE id=?')->execute([$s['id']]);
            } else {
                $t = $s['run_time'] ?: '09:00:00';
                if ($s['freq'] === 'daily') {
                    $next = date('Y-m-d ' . $t, strtotime('+1 day'));
                } else { // weekly
                    $next = date('Y-m-d ' . $t, strtotime('+7 days'));
                }
                $pdo->prepare('UPDATE scheduled_notifications SET next_run=?, last_run=NOW() WHERE id=?')->execute([$next, $s['id']]);
            }
        }
    } catch (Throwable $e) { /* silencieux */ }
}

/** Messages/bannières actifs non lus pour un utilisateur. */
function active_messages_for(int $userId): array
{
    try {
        ensure_admin_tables();
        $st = db()->prepare(
            'SELECT m.* FROM admin_messages m
             WHERE m.is_active=1
               AND (m.target_user_id IS NULL OR m.target_user_id=?)
               AND (m.starts_at IS NULL OR m.starts_at <= NOW())
               AND (m.ends_at IS NULL OR m.ends_at >= NOW())
               AND NOT EXISTS (SELECT 1 FROM admin_message_reads r WHERE r.message_id=m.id AND r.user_id=?)
             ORDER BY m.created_at DESC'
        );
        $st->execute([$userId, $userId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function maintenance_active(): bool
{
    return setting_get('maintenance_mode', '0') === '1';
}
