<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/orgs.php';
$user = require_login();
$owner = data_owner_id();
$pdo = db();

$__actorName = preg_split('/\s+/', trim($user['full_name'] ?: $user['username']))[0] ?? $user['username'];

// Vérif CSRF pour les écritures
$action = $_GET['action'] ?? '';
$writes = ['save', 'delete', 'import', 'bulk'];
if (in_array($action, $writes, true)) {
    $token = $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!csrf_check($token)) json_out(['error' => 'Jeton de sécurité invalide.'], 403);
    // Collaboration en lecture seule : aucune écriture autorisée.
    if (!can_edit_current_owner()) {
        json_out(['error' => 'Vous consultez cet espace en lecture seule.'], 403);
    }
}

$validStatus = array_keys(status_labels());
$validPrio = ['basse', 'normale', 'haute'];

function clean_date($d) { return (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null; }

/** Vérifie (et ajoute si besoin) la colonne apply_channel, indépendamment d'upgrade.php. */
function companies_has_channel(PDO $pdo): bool
{
    static $has = null;
    if ($has !== null) return $has;
    try {
        $has = (bool)$pdo->query("SHOW COLUMNS FROM companies LIKE 'apply_channel'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE companies ADD COLUMN apply_channel VARCHAR(60) NOT NULL DEFAULT '' AFTER source");
            $has = true;
        }
    } catch (Throwable $e) { $has = false; }
    return $has;
}

/**
 * Fusionne l'ancien champ « source » dans « Type de candidature »
 * (apply_channel) : là où le type est vide mais qu'une source existe, on
 * recopie la source. Exécuté une seule fois, sans risque à le relancer.
 */
function companies_merge_source_once(PDO $pdo, int $owner): void
{
    static $done = [];
    if (isset($done[$owner])) return;
    $done[$owner] = true;
    if (!companies_has_channel($pdo)) return;
    try {
        $hasSource = (bool)$pdo->query("SHOW COLUMNS FROM companies LIKE 'source'")->fetch();
        if (!$hasSource) return;
        $pdo->prepare("UPDATE companies
                       SET apply_channel = LEFT(source, 60)
                       WHERE owner_id = ? AND (apply_channel IS NULL OR apply_channel = '')
                         AND source IS NOT NULL AND source <> ''")
            ->execute([$owner]);
    } catch (Throwable $e) { /* silencieux : non bloquant */ }
}

switch ($action) {

  case 'list':
    companies_merge_source_once($pdo, $owner);
    $st = $pdo->prepare('SELECT * FROM companies WHERE owner_id=? ORDER BY updated_at DESC');
    $st->execute([$owner]);
    json_out(['items' => $st->fetchAll()]);
    break;

  case 'save':
    $d = json_in();
    $id = (int)($d['id'] ?? 0);
    $name = trim($d['name'] ?? '');
    if ($name === '') json_out(['error' => 'Le nom est obligatoire.'], 422);
    $status = in_array($d['status'] ?? '', $validStatus, true) ? $d['status'] : 'brouillon';
    $prio = in_array($d['priority'] ?? '', $validPrio, true) ? $d['priority'] : 'normale';

    $fields = [
        'name' => $name,
        'sector' => trim($d['sector'] ?? ''),
        'position' => trim($d['position'] ?? ''),
        'contact_name' => trim($d['contact_name'] ?? ''),
        'email' => trim($d['email'] ?? ''),
        'phone' => trim($d['phone'] ?? ''),
        'address' => trim($d['address'] ?? ''),
        'city' => trim($d['city'] ?? ''),
        'postal_code' => trim($d['postal_code'] ?? ''),
        'website' => trim($d['website'] ?? ''),
        'status' => $status,
        'apply_channel' => mb_substr(trim($d['apply_channel'] ?? ''), 0, 60),
        'priority' => $prio,
        'salary' => trim($d['salary'] ?? ''),
        'applied_date' => clean_date($d['applied_date'] ?? null),
        'response_date' => clean_date($d['response_date'] ?? null),
        'interview_date' => clean_date($d['interview_date'] ?? null),
        'followup_date' => clean_date($d['followup_date'] ?? null),
        'notes' => trim($d['notes'] ?? ''),
    ];
    if (!companies_has_channel($pdo)) unset($fields['apply_channel']);

    // Rattachement à la fiche entreprise (créée si besoin)
    $orgId = org_find_or_create($owner, $name, $fields);
    if ($orgId) $fields['org_id'] = $orgId;

    if ($id > 0) {
        // Vérifie l'appartenance
        $chk = $pdo->prepare('SELECT id FROM companies WHERE id=? AND owner_id=?');
        $chk->execute([$id, $owner]);
        if (!$chk->fetch()) json_out(['error' => 'Introuvable.'], 404);
        $set = implode(',', array_map(fn($k) => "$k=?", array_keys($fields)));
        $st = $pdo->prepare("UPDATE companies SET $set WHERE id=?");
        $st->execute([...array_values($fields), $id]);
        log_activity((int)$user['id'], 'update', 'company', $id, $name, $owner);
        json_out(['ok' => true, 'id' => $id]);
    } else {
        $cols = array_keys($fields);
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $st = $pdo->prepare('INSERT INTO companies (owner_id, created_by, ' . implode(',', $cols) . ") VALUES (?,?,$ph)");
        $st->execute([$owner, $user['id'], ...array_values($fields)]);
        $nid = (int)$pdo->lastInsertId();
        log_activity((int)$user['id'], 'create', 'company', $nid, $name, $owner);
        queue_company_add($owner, (int)$user['id'], $__actorName, 1);
        json_out(['ok' => true, 'id' => $nid]);
    }
    break;

  case 'delete':
    $d = json_in();
    $id = (int)($d['id'] ?? 0);
    $st = $pdo->prepare('DELETE FROM companies WHERE id=? AND owner_id=?');
    $st->execute([$id, $owner]);
    log_activity((int)$user['id'], 'delete', 'company', $id, '', $owner);
    json_out(['ok' => true]);
    break;

  case 'import':
    $d = json_in();
    $raw = (string)($d['raw'] ?? '');
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $count = 0;
    $ins = $pdo->prepare('INSERT INTO companies (owner_id, created_by, org_id, name, position, city, email, phone, status) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        // Séparateur : ; ou , (le premier trouvé sur la ligne)
        $sep = (strpos($line, ';') !== false) ? ';' : ',';
        $parts = array_map('trim', explode($sep, $line));
        $name = $parts[0] ?? '';
        if ($name === '') continue;
        $status = $parts[5] ?? 'brouillon';
        if (!in_array($status, $validStatus, true)) $status = 'brouillon';
        $orgId = org_find_or_create($owner, $name, [
            'city' => $parts[2] ?? '', 'email' => $parts[3] ?? '', 'phone' => $parts[4] ?? '',
        ]);
        $ins->execute([
            $owner, $user['id'], $orgId, mb_substr($name, 0, 160),
            mb_substr($parts[1] ?? '', 0, 160), mb_substr($parts[2] ?? '', 0, 120),
            mb_substr($parts[3] ?? '', 0, 160), mb_substr($parts[4] ?? '', 0, 40), $status
        ]);
        $count++;
    }
    log_activity((int)$user['id'], 'import', 'company', null, "$count entreprises", $owner);
    if ($count > 0) queue_company_add($owner, (int)$user['id'], $__actorName, $count);
    json_out(['ok' => true, 'count' => $count]);
    break;

  default:
    if ($action === 'bulk') {
    $d = json_in();
    $ids = array_values(array_filter(array_map('intval', (array)($d['ids'] ?? []))));
    $op  = $d['op'] ?? '';
    if (!$ids) json_out(['error' => 'Aucune sélection.'], 422);
    $ph = implode(',', array_fill(0, count($ids), '?'));

    if ($op === 'delete') {
        $st = $pdo->prepare("DELETE FROM companies WHERE owner_id=? AND id IN ($ph)");
        $st->execute([$owner, ...$ids]);
        log_activity((int)$user['id'], 'bulk_delete', 'company', null, count($ids) . ' supprimées', $owner);
        json_out(['ok' => true, 'affected' => $st->rowCount()]);
    }
    if ($op === 'status') {
        $status = in_array($d['status'] ?? '', $validStatus, true) ? $d['status'] : null;
        if (!$status) json_out(['error' => 'Statut invalide.'], 422);
        $st = $pdo->prepare("UPDATE companies SET status=? WHERE owner_id=? AND id IN ($ph)");
        $st->execute([$status, $owner, ...$ids]);
        log_activity((int)$user['id'], 'bulk_status', 'company', null, $status, $owner);
        json_out(['ok' => true, 'affected' => $st->rowCount()]);
    }
    if ($op === 'priority') {
        $prio = in_array($d['priority'] ?? '', ['basse', 'normale', 'haute'], true) ? $d['priority'] : null;
        if (!$prio) json_out(['error' => 'Priorité invalide.'], 422);
        $st = $pdo->prepare("UPDATE companies SET priority=? WHERE owner_id=? AND id IN ($ph)");
        $st->execute([$prio, $owner, ...$ids]);
        json_out(['ok' => true, 'affected' => $st->rowCount()]);
    }
    if ($op === 'channel') {
        // Type de candidature (apply_channel). Valeur libre plafonnée à 60 car.
        companies_has_channel($pdo);
        $chan = mb_substr(trim((string)($d['channel'] ?? '')), 0, 60);
        $st = $pdo->prepare("UPDATE companies SET apply_channel=? WHERE owner_id=? AND id IN ($ph)");
        $st->execute([$chan, $owner, ...$ids]);
        log_activity((int)$user['id'], 'bulk_channel', 'company', null, $chan, $owner);
        json_out(['ok' => true, 'affected' => $st->rowCount()]);
    }
    if ($op === 'applied_date' || $op === 'response_date') {
        $col = $op; // nom de colonne validé par la liste blanche ci-dessus
        $val = trim((string)($d['value'] ?? ''));
        // Chaîne vide => on efface la date (NULL) ; sinon format AAAA-MM-JJ strict
        if ($val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            json_out(['error' => 'Date invalide (attendu AAAA-MM-JJ).'], 422);
        }
        $sqlVal = $val === '' ? null : $val;
        $st = $pdo->prepare("UPDATE companies SET $col=? WHERE owner_id=? AND id IN ($ph)");
        $st->execute([$sqlVal, $owner, ...$ids]);
        log_activity((int)$user['id'], 'bulk_' . $col, 'company', null, $val, $owner);
        json_out(['ok' => true, 'affected' => $st->rowCount()]);
    }
    json_out(['error' => 'Opération inconnue.'], 400);
}

json_out(['error' => 'Action inconnue.'], 400);
}
