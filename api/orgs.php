<?php
/** Alternis — Fiches entreprises (1 entreprise -> N candidatures). */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orgs.php';
$user = require_login();
$owner = data_owner_id();
$pdo = db();
ensure_orgs_tables();
$action = $_GET['action'] ?? '';

$writes = ['save', 'merge', 'backfill', 'delete'];
if (in_array($action, $writes, true) && !csrf_check($_SERVER['HTTP_X_CSRF'] ?? '')) {
    json_out(['error' => 'Jeton de sécurité invalide.'], 403);
}

if ($action === 'list') {
    // Rattrape les candidatures créées avant l'arrivée des fiches entreprises
    org_backfill($owner);

    $st = $pdo->prepare("SELECT o.*,
            COUNT(c.id) AS offers,
            SUM(c.status IN ('envoye','relance')) AS pending,
            SUM(c.status = 'entretien') AS interviews,
            SUM(c.status = 'accepte') AS accepted,
            SUM(c.status = 'refuse') AS refused,
            MAX(c.applied_date) AS last_applied
        FROM orgs o LEFT JOIN companies c ON c.org_id = o.id AND c.owner_id = o.owner_id
        WHERE o.owner_id = ?
        GROUP BY o.id
        ORDER BY offers DESC, o.name ASC");
    $st->execute([$owner]);
    json_out(['items' => $st->fetchAll()]);
}

if ($action === 'get') {
    $org = org_get($owner, (int)($_GET['id'] ?? 0));
    if (!$org) json_out(['error' => 'Introuvable.'], 404);
    json_out(['org' => $org]);
}

if ($action === 'save') {
    $d = json_in();
    $id = (int)($d['id'] ?? 0);
    $chk = $pdo->prepare('SELECT id FROM orgs WHERE id=? AND owner_id=?');
    $chk->execute([$id, $owner]);
    if (!$chk->fetch()) json_out(['error' => 'Introuvable.'], 404);

    $name = trim($d['name'] ?? '');
    if ($name === '') json_out(['error' => 'Le nom est obligatoire.'], 422);

    $st = $pdo->prepare('UPDATE orgs SET name=?, name_key=?, name_compact=?, domain=?, sector=?, website=?,
                         city=?, postal_code=?, address=?, contact_name=?, email=?, phone=?, notes=? WHERE id=? AND owner_id=?');
    try {
        $st->execute([
            mb_substr($name, 0, 160), org_name_key($name), org_name_compact($name),
            org_domain((string)($d['website'] ?? '')),
            mb_substr(trim($d['sector'] ?? ''), 0, 120),
            mb_substr(trim($d['website'] ?? ''), 0, 200),
            mb_substr(trim($d['city'] ?? ''), 0, 120),
            mb_substr(trim($d['postal_code'] ?? ''), 0, 20),
            mb_substr(trim($d['address'] ?? ''), 0, 255),
            mb_substr(trim($d['contact_name'] ?? ''), 0, 120),
            mb_substr(trim($d['email'] ?? ''), 0, 160),
            mb_substr(trim($d['phone'] ?? ''), 0, 40),
            trim($d['notes'] ?? ''),
            $id, $owner,
        ]);
    } catch (Throwable $e) {
        json_out(['error' => 'Une autre entreprise porte déjà ce nom.'], 422);
    }
    log_activity((int)$user['id'], 'org_update', 'org', $id, $name, $owner);
    json_out(['ok' => true]);
}

if ($action === 'merge') {
    $d = json_in();
    $ok = org_merge($owner, (int)($d['source_id'] ?? 0), (int)($d['target_id'] ?? 0));
    if (!$ok) json_out(['error' => 'Fusion impossible.'], 422);
    log_activity((int)$user['id'], 'org_merge', 'org', (int)$d['target_id'], '', $owner);
    json_out(['ok' => true]);
}

if ($action === 'delete') {
    // Ne supprime que les fiches sans candidature : on ne détruit jamais de suivi.
    $id = (int)(json_in()['id'] ?? 0);
    $n = $pdo->prepare('SELECT COUNT(*) FROM companies WHERE org_id=? AND owner_id=?');
    $n->execute([$id, $owner]);
    if ((int)$n->fetchColumn() > 0) json_out(['error' => 'Cette entreprise a encore des candidatures.'], 422);
    $pdo->prepare('DELETE FROM orgs WHERE id=? AND owner_id=?')->execute([$id, $owner]);
    json_out(['ok' => true]);
}

if ($action === 'backfill') {
    [$created, $linked] = org_backfill($owner);
    json_out(['ok' => true, 'created' => $created, 'linked' => $linked]);
}

json_out(['error' => 'Action inconnue.'], 400);
