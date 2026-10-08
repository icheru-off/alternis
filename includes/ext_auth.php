<?php
/**
 * Authentification de l'extension navigateur par jetons (sans cookie ni CSRF).
 *
 * Deux niveaux :
 *  - « mot de passe d'application » : un secret dédié, généré depuis les
 *    réglages, révocable, qui ne remplace pas le mot de passe du compte ;
 *  - « jeton d'accès » : délivré à l'extension après connexion, envoyé ensuite
 *    en en-tête Authorization: Bearer pour chaque appel.
 */

function ensure_ext_tables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    // Mots de passe d'application (un par appareil/usage, révocables)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `app_passwords` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`    INT UNSIGNED NOT NULL,
        `label`      VARCHAR(80) NOT NULL DEFAULT '',
        `secret_hash` VARCHAR(255) NOT NULL,
        `last_used`  DATETIME NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Jetons d'accès délivrés à l'extension
    $pdo->exec("CREATE TABLE IF NOT EXISTS `ext_tokens` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`    INT UNSIGNED NOT NULL,
        `token_hash` CHAR(64) NOT NULL,
        `app_pw_id`  INT UNSIGNED NULL,
        `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
        `expires_at` DATETIME NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_token` (`token_hash`),
        KEY `idx_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Génère un mot de passe d'application lisible : 4 groupes de 4 (xxxx-xxxx-…). */
function app_password_generate(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';   // sans caractères ambigus
    $groups = [];
    for ($g = 0; $g < 4; $g++) {
        $s = '';
        for ($i = 0; $i < 4; $i++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $groups[] = $s;
    }
    return implode('-', $groups);
}

/**
 * Vérifie un couple identifiant + mot de passe d'application.
 * Renvoie l'utilisateur (array) ou null.
 */
function app_password_check(string $identifier, string $appPassword): ?array
{
    ensure_ext_tables();
    $pdo = db();
    $identifier = trim($identifier);
    $appPassword = trim(str_replace(' ', '', $appPassword));

    $st = $pdo->prepare('SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1');
    $st->execute([$identifier, $identifier]);
    $u = $st->fetch();
    if (!$u) return null;

    $rows = $pdo->prepare('SELECT * FROM app_passwords WHERE user_id = ?');
    $rows->execute([(int)$u['id']]);
    foreach ($rows->fetchAll() as $ap) {
        if (password_verify($appPassword, $ap['secret_hash'])) {
            $pdo->prepare('UPDATE app_passwords SET last_used = NOW() WHERE id = ?')->execute([(int)$ap['id']]);
            $u['_app_pw_id'] = (int)$ap['id'];
            return $u;
        }
    }
    return null;
}

/** Délivre un jeton d'accès (renvoie le jeton en clair ; stocke son hash). */
function ext_token_issue(int $userId, ?int $appPwId, int $ttlDays = 90): string
{
    ensure_ext_tables();
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + $ttlDays * 86400);
    db()->prepare('INSERT INTO ext_tokens (user_id, token_hash, app_pw_id, user_agent, expires_at)
                   VALUES (?,?,?,?,?)')
        ->execute([$userId, hash('sha256', $token), $appPwId,
                   substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $expires]);
    return $token;
}

/** Valide un jeton Bearer. Renvoie l'utilisateur ou null. */
function ext_token_user(?string $token): ?array
{
    if (!$token) return null;
    ensure_ext_tables();
    $pdo = db();
    $st = $pdo->prepare('SELECT et.*, u.* FROM ext_tokens et
                         JOIN users u ON u.id = et.user_id
                         WHERE et.token_hash = ? AND u.is_active = 1 LIMIT 1');
    $st->execute([hash('sha256', (string)$token)]);
    $row = $st->fetch();
    if (!$row) return null;
    if (strtotime($row['expires_at']) < time()) {
        $pdo->prepare('DELETE FROM ext_tokens WHERE token_hash = ?')->execute([hash('sha256', (string)$token)]);
        return null;
    }
    return $row;
}

/** Récupère le jeton Bearer de la requête. */
function ext_bearer_token(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; } }
    }
    if (preg_match('/Bearer\s+(\S+)/i', $h, $m)) return $m[1];
    return null;
}
