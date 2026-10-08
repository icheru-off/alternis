<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

header('Content-Type: application/json');
$user = current_user();
$uid = (int)$user['id'];
$pdo = db();
ensure_accounts_tables();

$action = $_GET['action'] ?? '';
$writes = ['invite', 'revoke', 'accept', 'switch', 'leave', 'remove_collab'];
if (in_array($action, $writes, true) && !csrf_check($_SERVER['HTTP_X_CSRF'] ?? '')) {
    json_out(['error' => 'Jeton de sécurité invalide.'], 403);
}

/* Crée un lien d'invitation avec droits choisis par l'inviteur. */
if ($action === 'invite') {
    $d = json_in();
    $canEdit = !empty($d['can_edit']) ? 1 : 0;
    $label = mb_substr(trim($d['label'] ?? ''), 0, 80);
    $token = collab_token();
    $pdo->prepare('INSERT INTO collab_invites (owner_id, token, can_edit, label, expires_at)
                   VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL 30 DAY))')
        ->execute([$uid, $token, $canEdit, $label]);
    json_out(['ok' => true, 'token' => $token,
              'url' => rtrim(APP_URL, '/') . '/index.php?page=collab_join&t=' . $token]);
}

/* Liste mes invitations émises + collaborateurs actifs sur MES données. */
if ($action === 'list') {
    $inv = $pdo->prepare("SELECT id, token, can_edit, label, accepted_by, accepted_at, revoked, created_at
                          FROM collab_invites WHERE owner_id = ? ORDER BY created_at DESC");
    $inv->execute([$uid]);
    $invites = $inv->fetchAll();

    // noms des collaborateurs actifs
    $links = $pdo->prepare("SELECT l.collab_id, l.can_edit, u.full_name, u.username
                            FROM collab_links l JOIN users u ON u.id = l.collab_id
                            WHERE l.owner_id = ?");
    $links->execute([$uid]);
    $collaborators = $links->fetchAll();

    // Les espaces auxquels J'AI accès (données d'autres étudiants)
    $mine = $pdo->prepare("SELECT l.owner_id, l.can_edit, u.full_name, u.username
                           FROM collab_links l JOIN users u ON u.id = l.owner_id
                           WHERE l.collab_id = ?");
    $mine->execute([$uid]);
    $accessTo = $mine->fetchAll();

    json_out([
        'invites' => $invites,
        'collaborators' => $collaborators,
        'access_to' => $accessTo,
        'current_owner' => data_owner_id(),
        'self_id' => $uid,
        'base' => rtrim(APP_URL, '/'),
    ]);
}

/* Révoque une invitation (et le lien actif éventuel qui en découle). */
if ($action === 'revoke') {
    $d = json_in();
    $id = (int)($d['id'] ?? 0);
    $st = $pdo->prepare('SELECT accepted_by FROM collab_invites WHERE id = ? AND owner_id = ?');
    $st->execute([$id, $uid]);
    $row = $st->fetch();
    if (!$row) json_out(['error' => 'Invitation introuvable.'], 404);
    $pdo->prepare('UPDATE collab_invites SET revoked = 1 WHERE id = ? AND owner_id = ?')->execute([$id, $uid]);
    if (!empty($row['accepted_by'])) {
        $pdo->prepare('DELETE FROM collab_links WHERE owner_id = ? AND collab_id = ?')
            ->execute([$uid, (int)$row['accepted_by']]);
    }
    json_out(['ok' => true]);
}

/* Retire un collaborateur actif (sans passer par l'invitation). */
if ($action === 'remove_collab') {
    $d = json_in();
    $collabId = (int)($d['collab_id'] ?? 0);
    $pdo->prepare('DELETE FROM collab_links WHERE owner_id = ? AND collab_id = ?')->execute([$uid, $collabId]);
    $pdo->prepare('UPDATE collab_invites SET revoked = 1 WHERE owner_id = ? AND accepted_by = ?')->execute([$uid, $collabId]);
    json_out(['ok' => true]);
}

/* Accepte une invitation via son token. */
if ($action === 'accept') {
    $d = json_in();
    $token = trim($d['token'] ?? '');
    $st = $pdo->prepare('SELECT * FROM collab_invites WHERE token = ? AND revoked = 0 LIMIT 1');
    $st->execute([$token]);
    $inv = $st->fetch();
    if (!$inv) json_out(['error' => 'Invitation invalide ou révoquée.'], 404);
    if ((int)$inv['owner_id'] === $uid) json_out(['error' => 'Vous ne pouvez pas rejoindre votre propre espace.'], 422);
    if (!empty($inv['expires_at']) && strtotime($inv['expires_at']) < time()) json_out(['error' => 'Invitation expirée.'], 410);

    // Crée le lien (ou met à jour les droits)
    $pdo->prepare('INSERT INTO collab_links (owner_id, collab_id, can_edit) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE can_edit = VALUES(can_edit)')
        ->execute([(int)$inv['owner_id'], $uid, (int)$inv['can_edit']]);
    // Marque l'invitation comme acceptée
    $pdo->prepare('UPDATE collab_invites SET accepted_by = ?, accepted_at = NOW() WHERE id = ?')
        ->execute([$uid, (int)$inv['id']]);
    json_out(['ok' => true, 'owner_id' => (int)$inv['owner_id']]);
}

/* Bascule la vue vers les données d'un autre étudiant (ou revient à soi). */
if ($action === 'switch') {
    $d = json_in();
    $ownerId = (int)($d['owner_id'] ?? 0);
    if ($ownerId === 0 || $ownerId === $uid) {
        unset($_SESSION['collab_owner']);
        json_out(['ok' => true, 'owner' => $uid]);
    }
    if (!collab_can_access($uid, $ownerId)) json_out(['error' => 'Accès non autorisé.'], 403);
    $_SESSION['collab_owner'] = $ownerId;
    json_out(['ok' => true, 'owner' => $ownerId]);
}

/* Quitte un espace partagé (renonce à un accès reçu). */
if ($action === 'leave') {
    $d = json_in();
    $ownerId = (int)($d['owner_id'] ?? 0);
    $pdo->prepare('DELETE FROM collab_links WHERE owner_id = ? AND collab_id = ?')->execute([$ownerId, $uid]);
    if (!empty($_SESSION['collab_owner']) && (int)$_SESSION['collab_owner'] === $ownerId) unset($_SESSION['collab_owner']);
    json_out(['ok' => true]);
}

json_out(['error' => 'Action inconnue.'], 400);
