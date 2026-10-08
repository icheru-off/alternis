<?php
/**
 * Alternis — Liens de partage en lecture seule.
 *
 * Permet à un étudiant de générer une URL publique, non indexable et
 * révocable, donnant une vue figée de ses candidatures (par exemple pour
 * un responsable d'alternance qui demande un justificatif de recherche).
 *
 * Le lien ne donne accès à AUCUNE action : ni modification, ni suppression,
 * ni consultation du reste de l'application.
 */

function ensure_share_table(): void
{
    static $done = false;
    if ($done) return;
    $pdo = db();
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS `share_links` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `token` VARCHAR(64) NOT NULL,
            `owner_id` INT UNSIGNED NOT NULL,
            `created_by` INT UNSIGNED NOT NULL,
            `label` VARCHAR(120) NOT NULL DEFAULT "",
            `statuses` VARCHAR(255) NOT NULL DEFAULT "",
            `fields` VARCHAR(500) NOT NULL DEFAULT "",
            `show_contact` TINYINT(1) NOT NULL DEFAULT 0,
            `show_notes` TINYINT(1) NOT NULL DEFAULT 0,
            `expires_at` DATETIME NULL,
            `revoked` TINYINT(1) NOT NULL DEFAULT 0,
            `views` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_view` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_token` (`token`),
            KEY `idx_share_owner` (`owner_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } catch (Throwable $e) {}

    // Réconciliation des colonnes pour les installations antérieures
    try {
        $cols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM share_links') as $c) $cols[strtolower($c['Field'])] = true;
        $wanted = [
            'label' => 'VARCHAR(120) NOT NULL DEFAULT ""',
            'statuses' => 'VARCHAR(255) NOT NULL DEFAULT ""',
            'fields' => 'VARCHAR(500) NOT NULL DEFAULT ""',
            'show_contact' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'show_notes' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'expires_at' => 'DATETIME NULL',
            'revoked' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'views' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'last_view' => 'DATETIME NULL',
        ];
        foreach ($wanted as $n => $d) {
            if (!isset($cols[$n])) {
                try { $pdo->exec("ALTER TABLE share_links ADD COLUMN `$n` $d"); } catch (Throwable $e) {}
            }
        }
    } catch (Throwable $e) {}
    $done = true;
}

/** Jeton d'URL : 32 caractères hexadécimaux, imprévisible. */
function share_new_token(): string
{
    return bin2hex(random_bytes(16));
}

/** URL publique complète d'un lien de partage. */
function share_url(string $token): string
{
    $root = (defined('APP_URL') && APP_URL !== '') ? rtrim(APP_URL, '/') : '';
    if ($root === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $root = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(base_url(), '/');
    }
    return $root . '/share.php?t=' . $token;
}

/**
 * Retourne le lien si le jeton est valide (existant, non révoqué, non expiré),
 * sinon null. N'incrémente pas le compteur de vues.
 */
function share_lookup(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    ensure_share_table();
    try {
        $st = db()->prepare('SELECT * FROM share_links WHERE token=? LIMIT 1');
        $st->execute([$token]);
        $row = $st->fetch();
    } catch (Throwable $e) { return null; }
    if (!$row) return null;
    if ((int)$row['revoked'] === 1) return null;
    if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) return null;
    return $row;
}

/** Enregistre une consultation. */
function share_touch(int $id): void
{
    try { db()->prepare('UPDATE share_links SET views=views+1, last_view=NOW() WHERE id=?')->execute([$id]); } catch (Throwable $e) {}
}

/**
 * Candidatures visibles par un lien donné, dans l'ordre le plus récent d'abord.
 */
function share_companies(array $link): array
{
    $where = 'owner_id=?';
    $args = [(int)$link['owner_id']];
    $statuses = array_values(array_filter(explode(',', (string)$link['statuses'])));
    $valid = array_keys(status_labels());
    $statuses = array_values(array_intersect($statuses, $valid));
    if ($statuses) {
        $where .= ' AND status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        array_push($args, ...$statuses);
    }
    try {
        $st = db()->prepare("SELECT * FROM companies WHERE $where ORDER BY applied_date DESC, created_at DESC");
        $st->execute($args);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Colonnes affichées dans la vue partagée (jamais de données sensibles par défaut). */
function share_visible_fields(array $link): array
{
    $default = ['name', 'position', 'city', 'status', 'applied_date'];
    $sel = array_values(array_filter(explode(',', (string)$link['fields'])));
    $allowed = ['name', 'position', 'sector', 'city', 'status', 'applied_date', 'response_date', 'interview_date', 'apply_channel'];
    if ((int)$link['show_contact'] === 1) { $allowed[] = 'contact_name'; $allowed[] = 'email'; $allowed[] = 'phone'; }
    if ((int)$link['show_notes'] === 1)   { $allowed[] = 'notes'; }
    $sel = array_values(array_intersect($sel, $allowed));
    return $sel ?: $default;
}

/**
 * Champs proposés lors de la création d'un lien de partage.
 * Les coordonnées et les notes sont exclues par défaut : ce sont
 * des données personnelles qui n'ont pas à circuler sans intention explicite.
 */
function pdf_fields_for_share(): array
{
    return [
        'name'           => 'Entreprise',
        'position'       => 'Poste',
        'sector'         => 'Secteur',
        'city'           => 'Ville',
        'status'         => 'Statut',
        'applied_date'   => 'Date de candidature',
        'response_date'  => 'Date de réponse',
        'interview_date' => "Date d'entretien",
        'apply_channel'  => 'Canal de candidature',
        'contact_name'   => 'Nom du contact',
        'email'          => 'E-mail du contact',
        'phone'          => 'Téléphone du contact',
        'notes'          => 'Notes',
    ];
}

/** Champs cochés par défaut dans le formulaire de partage. */
function share_default_fields(): array
{
    return ['name', 'position', 'city', 'status', 'applied_date'];
}

/**
 * Poids relatif d'une colonne dans la vue partagée.
 * Sert à répartir la largeur en pourcentages : le tableau tient alors
 * dans la page, sans défilement horizontal.
 */
function share_col_weight(string $field): int
{
    $w = [
        'name' => 26, 'position' => 26, 'sector' => 14, 'city' => 11,
        'status' => 13, 'applied_date' => 12, 'response_date' => 12,
        'interview_date' => 12, 'apply_channel' => 13,
        'contact_name' => 15, 'email' => 20, 'phone' => 13, 'notes' => 26,
    ];
    return $w[$field] ?? 12;
}

/** Domaine d'un site web, sans www. */
function share_domain(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    $h = parse_url($url, PHP_URL_HOST);
    return $h ? preg_replace('/^www\./i', '', $h) : '';
}

/**
 * Pastille logo de l'entreprise : logo distant si un site est connu,
 * repli sur l'initiale. Le repli est purement HTML/CSS : si l'image
 * ne charge pas, l'initiale reste visible dessous.
 */
function share_logo_html(string $name, string $website): string
{
    $initial = mb_strtoupper(mb_substr(trim($name) ?: '?', 0, 1));
    $d = share_domain($website);
    $out = '<span class="clogo"><em>' . e($initial) . '</em>';
    if ($d !== '') {
        $primary = 'https://logo.clearbit.com/' . rawurlencode($d) . '?size=64';
        $fallback = 'https://www.google.com/s2/favicons?domain=' . rawurlencode($d) . '&sz=64';
        $out .= '<img src="' . e($primary) . '" alt="" loading="lazy" referrerpolicy="no-referrer"'
             . ' onerror="if(!this.dataset.f){this.dataset.f=1;this.src=\'' . e($fallback) . '\'}else{this.remove()}">';
    }
    return $out . '</span>';
}
