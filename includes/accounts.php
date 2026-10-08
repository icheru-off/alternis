<?php
/**
 * Comptes : inscription, activation par code e-mail, réinitialisation de mot de
 * passe, identifiant automatique « pnom », et collaboration entre étudiants.
 * Toutes les tables sont créées paresseusement.
 */

/** Tables liées aux comptes (codes e-mail + invitations de collaboration). */
function ensure_accounts_tables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    // Codes temporaires : activation de compte et réinitialisation de mot de passe
    $pdo->exec("CREATE TABLE IF NOT EXISTS `email_codes` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `email`      VARCHAR(160) NOT NULL,
        `purpose`    ENUM('activation','reset') NOT NULL,
        `code_hash`  VARCHAR(255) NOT NULL,
        `payload`    TEXT NULL,
        `attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `expires_at` DATETIME NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_email_purpose` (`email`,`purpose`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Invitations de collaboration entre étudiants
    $pdo->exec("CREATE TABLE IF NOT EXISTS `collab_invites` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `owner_id`   INT UNSIGNED NOT NULL,
        `token`      VARCHAR(64) NOT NULL,
        `can_edit`   TINYINT(1) NOT NULL DEFAULT 0,
        `label`      VARCHAR(80) NOT NULL DEFAULT '',
        `accepted_by` INT UNSIGNED NULL,
        `accepted_at` DATETIME NULL,
        `revoked`    TINYINT(1) NOT NULL DEFAULT 0,
        `expires_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_token` (`token`),
        KEY `idx_owner` (`owner_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Liens de collaboration actifs (qui peut accéder aux données de qui)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `collab_links` (
        `owner_id`   INT UNSIGNED NOT NULL,
        `collab_id`  INT UNSIGNED NOT NULL,
        `can_edit`   TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`owner_id`,`collab_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Génère un identifiant « pnom » : première lettre du prénom + nom, collés,
 * sans espace ni accent. Ajoute un suffixe numérique en cas de collision.
 * Ex. « Marie Dupont » -> « mdupont » (puis « mdupont2 » si pris).
 */
function username_from_name(string $firstName, string $lastName): string
{
    $slug = function (string $s): string {
        $s = mb_strtolower(trim($s), 'UTF-8');
        // translittération accents -> ASCII
        $map = ['à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','ç'=>'c',
                'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
                'ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ù'=>'u','ú'=>'u',
                'û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','œ'=>'oe','æ'=>'ae'];
        $s = strtr($s, $map);
        return preg_replace('/[^a-z0-9]/', '', $s);
    };
    $first = $slug($firstName);
    $last  = $slug($lastName);
    $base = (($first !== '' ? mb_substr($first, 0, 1) : '') . $last);
    if ($base === '') $base = 'user';
    $base = substr($base, 0, 55);

    $pdo = db();
    $candidate = $base;
    $i = 1;
    while (true) {
        $st = $pdo->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
        $st->execute([$candidate]);
        if (!$st->fetch()) return $candidate;
        $i++;
        $candidate = $base . $i;
    }
}

/** Code numérique à 6 chiffres. */
function gen_email_code(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Crée (ou remplace) un code e-mail pour une adresse et un usage donnés.
 * Renvoie le code en clair (à envoyer par e-mail) ; ne stocke que son hash.
 */
function email_code_issue(string $email, string $purpose, ?string $payload = null, int $ttlMinutes = 20): string
{
    ensure_accounts_tables();
    $pdo = db();
    // On invalide les anciens codes du même usage pour cette adresse
    $pdo->prepare('DELETE FROM email_codes WHERE email = ? AND purpose = ?')->execute([$email, $purpose]);
    $code = gen_email_code();
    $expires = date('Y-m-d H:i:s', time() + $ttlMinutes * 60);
    $pdo->prepare('INSERT INTO email_codes (email, purpose, code_hash, payload, expires_at)
                   VALUES (?,?,?,?,?)')
        ->execute([$email, $purpose, password_hash($code, PASSWORD_DEFAULT), $payload, $expires]);
    return $code;
}

/**
 * Vérifie un code. Renvoie ['ok'=>bool, 'payload'=>?string, 'error'=>string].
 * Limite les tentatives (5) pour éviter le brute-force.
 */
function email_code_verify(string $email, string $purpose, string $code): array
{
    ensure_accounts_tables();
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM email_codes WHERE email = ? AND purpose = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$email, $purpose]);
    $row = $st->fetch();
    if (!$row) return ['ok' => false, 'payload' => null, 'error' => 'Aucun code en attente. Demandez-en un nouveau.'];
    if (strtotime($row['expires_at']) < time()) {
        $pdo->prepare('DELETE FROM email_codes WHERE id = ?')->execute([$row['id']]);
        return ['ok' => false, 'payload' => null, 'error' => 'Code expiré. Demandez-en un nouveau.'];
    }
    if ((int)$row['attempts'] >= 5) {
        return ['ok' => false, 'payload' => null, 'error' => 'Trop de tentatives. Demandez un nouveau code.'];
    }
    if (!password_verify($code, $row['code_hash'])) {
        $pdo->prepare('UPDATE email_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
        return ['ok' => false, 'payload' => null, 'error' => 'Code incorrect.'];
    }
    // Succès : on consomme le code
    $payload = $row['payload'];
    $pdo->prepare('DELETE FROM email_codes WHERE email = ? AND purpose = ?')->execute([$email, $purpose]);
    return ['ok' => true, 'payload' => $payload, 'error' => ''];
}

/** Jeton d'invitation aléatoire. */
function collab_token(): string
{
    return bin2hex(random_bytes(24));
}
