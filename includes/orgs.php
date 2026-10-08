<?php
/**
 * Alternis — Entreprises (organisations).
 *
 * Une entreprise existe une seule fois par utilisateur ; les candidatures
 * (table `companies`) s'y rattachent par `org_id`. Cela donne un historique
 * unifié : « vous avez déjà postulé ici en février ».
 */

/** Suffixes juridiques ou décoratifs à ignorer pour le rapprochement. */
function org_legal_suffixes(): array
{
    return ['sa', 'sas', 'sasu', 'sarl', 'eurl', 'sci', 'snc', 'scop', 'gie',
            'sa.', 'inc', 'ltd', 'llc', 'gmbh', 'bv', 'nv', 'plc', 'ag', 'spa', 'srl'];
}

/**
 * Clé de rapprochement d'un nom d'entreprise.
 *
 * Prudence volontaire : on retire les accents, la ponctuation et la forme
 * juridique, mais JAMAIS de mots significatifs. « Bouygues Telecom » et
 * « Bouygues » restent deux entreprises distinctes — les fusionner
 * détruirait de la donnée, et une fusion se répare mal.
 */
function org_name_key(string $name): string
{
    $s = trim($name);
    if ($s === '') return '';
    $s = mb_strtolower($s, 'UTF-8');
    // Translittération explicite : iconv('//TRANSLIT') dépend de la libc du
    // serveur (glibc rend « e », musl rend « ? »). On ne s'y fie pas.
    $s = strtr($s, [
        'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'ae',
        'ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
        'ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','œ'=>'oe',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','ß'=>'ss',
    ]);
    $s = preg_replace("/['’`]/u", '', $s);          // l'Oreal -> loreal
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);      // ponctuation -> espace
    $s = trim(preg_replace('/\s+/', ' ', $s));
    if ($s === '') return '';

    // Retrait des formes juridiques, uniquement en tête ou en fin
    $words = explode(' ', $s);
    $legal = org_legal_suffixes();
    while (count($words) > 1 && in_array(end($words), $legal, true)) array_pop($words);
    while (count($words) > 1 && in_array($words[0], $legal, true)) array_shift($words);

    return implode(' ', $words);
}

/**
 * Clé « compacte » : la clé de nom sans les espaces.
 * Rattrape « TotalEnergies » vs « Total Energies », ou « L'Oreal » vs « L Oreal ».
 * Utilisée en second recours seulement, jamais pour l'unicité.
 */
function org_name_compact(string $name): string
{
    return str_replace(' ', '', org_name_key($name));
}

/** Domaine normalisé d'un site web (clé de rapprochement la plus fiable). */
function org_domain(string $website): string
{
    $w = trim($website);
    if ($w === '') return '';
    if (!preg_match('#^https?://#i', $w)) $w = 'https://' . $w;
    $h = parse_url($w, PHP_URL_HOST);
    if (!$h) return '';
    $h = mb_strtolower(preg_replace('/^www\./i', '', $h));
    return $h;
}

function ensure_orgs_tables(): void
{
    static $done = false;
    if ($done) return;
    $pdo = db();
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS `orgs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `owner_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(160) NOT NULL,
            `name_key` VARCHAR(160) NOT NULL,
            `name_compact` VARCHAR(160) NOT NULL DEFAULT "",
            `domain` VARCHAR(160) NOT NULL DEFAULT "",
            `sector` VARCHAR(120) NOT NULL DEFAULT "",
            `website` VARCHAR(200) NOT NULL DEFAULT "",
            `city` VARCHAR(120) NOT NULL DEFAULT "",
            `postal_code` VARCHAR(20) NOT NULL DEFAULT "",
            `address` VARCHAR(255) NOT NULL DEFAULT "",
            `contact_name` VARCHAR(120) NOT NULL DEFAULT "",
            `email` VARCHAR(160) NOT NULL DEFAULT "",
            `phone` VARCHAR(40) NOT NULL DEFAULT "",
            `notes` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_owner_key` (`owner_id`,`name_key`),
            KEY `idx_org_owner` (`owner_id`),
            KEY `idx_org_domain` (`domain`),
            KEY `idx_org_compact` (`owner_id`,`name_compact`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } catch (Throwable $e) {}

    // Colonne ajoutée après coup sur une installation existante
    try {
        $has = (bool)$pdo->query("SHOW COLUMNS FROM orgs LIKE 'name_compact'")->fetch();
        if (!$has) {
            $pdo->exec('ALTER TABLE orgs ADD COLUMN `name_compact` VARCHAR(160) NOT NULL DEFAULT "" AFTER name_key');
            $pdo->exec('ALTER TABLE orgs ADD KEY `idx_org_compact` (`owner_id`,`name_compact`)');
            $pdo->exec("UPDATE orgs SET name_compact = REPLACE(name_key,' ','')");
        }
    } catch (Throwable $e) {}

    // Colonne de rattachement sur les candidatures
    try {
        $has = (bool)$pdo->query("SHOW COLUMNS FROM companies LIKE 'org_id'")->fetch();
        if (!$has) {
            $pdo->exec('ALTER TABLE companies ADD COLUMN `org_id` INT UNSIGNED NULL AFTER owner_id');
            $pdo->exec('ALTER TABLE companies ADD KEY `idx_company_org` (`org_id`)');
        }
    } catch (Throwable $e) {}
    $done = true;
}

/**
 * Trouve l'entreprise correspondante, ou la crée.
 * Priorité au domaine (fiable), repli sur la clé de nom.
 */
function org_find_or_create(int $ownerId, string $name, array $extra = []): ?int
{
    ensure_orgs_tables();
    $name = trim($name);
    if ($name === '') return null;
    $key = org_name_key($name);
    if ($key === '') return null;
    $domain = org_domain((string)($extra['website'] ?? ''));
    $pdo = db();

    // 1) Rapprochement par domaine
    if ($domain !== '') {
        $st = $pdo->prepare('SELECT id FROM orgs WHERE owner_id=? AND domain=? LIMIT 1');
        $st->execute([$ownerId, $domain]);
        if ($id = $st->fetchColumn()) { org_enrich((int)$id, $extra); return (int)$id; }
    }
    // 2) Rapprochement par nom normalisé
    $st = $pdo->prepare('SELECT id FROM orgs WHERE owner_id=? AND name_key=? LIMIT 1');
    $st->execute([$ownerId, $key]);
    if ($id = $st->fetchColumn()) { org_enrich((int)$id, $extra); return (int)$id; }

    // 3) Rapprochement compact (espaces ignorés)
    $compact = str_replace(' ', '', $key);
    if ($compact !== '') {
        $st = $pdo->prepare('SELECT id FROM orgs WHERE owner_id=? AND name_compact=? LIMIT 1');
        $st->execute([$ownerId, $compact]);
        if ($id = $st->fetchColumn()) { org_enrich((int)$id, $extra); return (int)$id; }
    }

    // 4) Création
    try {
        $st = $pdo->prepare('INSERT INTO orgs (owner_id,name,name_key,name_compact,domain,sector,website,city,postal_code,address,contact_name,email,phone)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([
            $ownerId, mb_substr($name, 0, 160), $key, $compact, $domain,
            mb_substr((string)($extra['sector'] ?? ''), 0, 120),
            mb_substr((string)($extra['website'] ?? ''), 0, 200),
            mb_substr((string)($extra['city'] ?? ''), 0, 120),
            mb_substr((string)($extra['postal_code'] ?? ''), 0, 20),
            mb_substr((string)($extra['address'] ?? ''), 0, 255),
            mb_substr((string)($extra['contact_name'] ?? ''), 0, 120),
            mb_substr((string)($extra['email'] ?? ''), 0, 160),
            mb_substr((string)($extra['phone'] ?? ''), 0, 40),
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        // Course entre deux requêtes : on relit
        $st = $pdo->prepare('SELECT id FROM orgs WHERE owner_id=? AND name_key=? LIMIT 1');
        $st->execute([$ownerId, $key]);
        $id = $st->fetchColumn();
        return $id ? (int)$id : null;
    }
}

/** Complète les champs vides d'une entreprise sans jamais écraser l'existant. */
function org_enrich(int $orgId, array $extra): void
{
    $fields = ['sector', 'website', 'city', 'postal_code', 'address', 'contact_name', 'email', 'phone'];
    $set = []; $args = [];
    foreach ($fields as $f) {
        $v = trim((string)($extra[$f] ?? ''));
        if ($v === '') continue;
        $set[] = "$f = IF($f = '' OR $f IS NULL, ?, $f)";
        $args[] = $v;
    }
    if (!$set) return;
    $d = org_domain((string)($extra['website'] ?? ''));
    if ($d !== '') { $set[] = "domain = IF(domain = '', ?, domain)"; $args[] = $d; }
    $args[] = $orgId;
    try { db()->prepare('UPDATE orgs SET ' . implode(',', $set) . ' WHERE id=?')->execute($args); } catch (Throwable $e) {}
}

/**
 * Rattache les candidatures orphelines à une entreprise.
 * Idempotent : peut être relancé sans risque.
 * Retourne [entreprises_creees, candidatures_rattachees].
 */
function org_backfill(int $ownerId): array
{
    ensure_orgs_tables();
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM companies WHERE owner_id=? AND (org_id IS NULL OR org_id=0) ORDER BY created_at ASC');
    $st->execute([$ownerId]);
    $rows = $st->fetchAll();

    $before = (int)$pdo->query('SELECT COUNT(*) FROM orgs WHERE owner_id=' . (int)$ownerId)->fetchColumn();
    $linked = 0;
    $upd = $pdo->prepare('UPDATE companies SET org_id=? WHERE id=?');
    foreach ($rows as $c) {
        $orgId = org_find_or_create($ownerId, (string)$c['name'], $c);
        if ($orgId) { $upd->execute([$orgId, $c['id']]); $linked++; }
    }
    $after = (int)$pdo->query('SELECT COUNT(*) FROM orgs WHERE owner_id=' . (int)$ownerId)->fetchColumn();
    return [$after - $before, $linked];
}

/** Fiche entreprise + ses candidatures. */
function org_get(int $ownerId, int $orgId): ?array
{
    ensure_orgs_tables();
    $st = db()->prepare('SELECT * FROM orgs WHERE id=? AND owner_id=?');
    $st->execute([$orgId, $ownerId]);
    $org = $st->fetch();
    if (!$org) return null;
    $cs = db()->prepare('SELECT * FROM companies WHERE org_id=? AND owner_id=? ORDER BY applied_date DESC, created_at DESC');
    $cs->execute([$orgId, $ownerId]);
    $org['offers'] = $cs->fetchAll();
    return $org;
}

/**
 * Fusionne $sourceId dans $targetId : les candidatures sont déplacées,
 * les champs vides de la cible sont complétés, la source est supprimée.
 */
function org_merge(int $ownerId, int $sourceId, int $targetId): bool
{
    if ($sourceId === $targetId) return false;
    ensure_orgs_tables();
    $pdo = db();
    $a = org_get($ownerId, $sourceId);
    $b = org_get($ownerId, $targetId);
    if (!$a || !$b) return false;
    try {
        $pdo->beginTransaction();
        org_enrich($targetId, $a);
        $pdo->prepare('UPDATE companies SET org_id=? WHERE org_id=? AND owner_id=?')->execute([$targetId, $sourceId, $ownerId]);
        $pdo->prepare('DELETE FROM orgs WHERE id=? AND owner_id=?')->execute([$sourceId, $ownerId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return false;
    }
}

/** Candidatures antérieures chez la même entreprise (hors celle en cours). */
function org_previous_applications(int $ownerId, int $orgId, int $exceptCompanyId = 0): array
{
    if (!$orgId) return [];
    $st = db()->prepare('SELECT id, position, status, applied_date FROM companies
                         WHERE owner_id=? AND org_id=? AND id<>? ORDER BY applied_date DESC LIMIT 5');
    $st->execute([$ownerId, $orgId, $exceptCompanyId]);
    return $st->fetchAll();
}
