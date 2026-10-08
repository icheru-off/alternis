<?php
/**
 * Préférences et profil par utilisateur (indépendant des réglages admin).
 * Table clé-valeur créée paresseusement, comme le reste de l'application.
 */

function ensure_user_prefs_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS `user_prefs` (
        `user_id` INT UNSIGNED NOT NULL,
        `k`       VARCHAR(60)  NOT NULL,
        `v`       TEXT         NULL,
        PRIMARY KEY (`user_id`,`k`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Lit une préférence utilisateur (valeur brute, ou défaut). */
function user_pref_get(int $userId, string $key, $default = null)
{
    ensure_user_prefs_table();
    $st = db()->prepare("SELECT v FROM user_prefs WHERE user_id=? AND k=?");
    $st->execute([$userId, $key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

/** Écrit une préférence utilisateur. */
function user_pref_set(int $userId, string $key, $value): void
{
    ensure_user_prefs_table();
    $st = db()->prepare("INSERT INTO user_prefs (user_id,k,v) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE v=VALUES(v)");
    $st->execute([$userId, $key, (string)$value]);
}

/** Récupère toutes les préférences d'un utilisateur sous forme de tableau. */
function user_prefs_all(int $userId): array
{
    ensure_user_prefs_table();
    $st = db()->prepare("SELECT k,v FROM user_prefs WHERE user_id=?");
    $st->execute([$userId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $out[$k] = $v;
    return $out;
}
