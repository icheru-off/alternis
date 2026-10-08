<?php
/**
 * Alternis — API des clés d'accès (WebAuthn / passkeys)
 * Actions : register_begin, register_finish (utilisateur connecté)
 *           login_begin, login_finish (avant connexion)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/webauthn.php';

$action = $_GET['action'] ?? '';
$pdo = db();
$rpId = webauthn_rp_id();
$origin = webauthn_origin();

/* ------------------------------------------------ Enregistrement (connecté) */

if ($action === 'register_begin') {
    $user = require_login();
    if (!csrf_check($_SERVER['HTTP_X_CSRF'] ?? '')) json_out(['error' => 'CSRF'], 403);

    $challenge = random_bytes(32);
    $_SESSION['wa_reg_challenge'] = b64url_encode($challenge);

    // Clés déjà enregistrées (à exclure)
    $st = $pdo->prepare('SELECT credential_id FROM webauthn_credentials WHERE user_id=?');
    $st->execute([$user['id']]);
    $exclude = [];
    foreach ($st->fetchAll() as $r) {
        $exclude[] = ['type' => 'public-key', 'id' => $r['credential_id']];
    }

    json_out([
        'challenge' => b64url_encode($challenge),
        'rp' => ['id' => $rpId, 'name' => APP_NAME],
        'user' => [
            'id'          => b64url_encode('u' . $user['id']),
            'name'        => $user['username'],
            'displayName' => $user['full_name'] ?: $user['username'],
        ],
        'pubKeyCredParams' => [
            ['type' => 'public-key', 'alg' => -7],    // ES256
            ['type' => 'public-key', 'alg' => -257],  // RS256 (Windows Hello)
        ],
        'timeout' => 60000,
        'attestation' => 'none',
        'authenticatorSelection' => [
            'residentKey'      => 'preferred',
            'userVerification' => 'preferred',
        ],
        'excludeCredentials' => $exclude,
    ]);
}

if ($action === 'register_finish') {
    $user = require_login();
    if (!csrf_check($_SERVER['HTTP_X_CSRF'] ?? '')) json_out(['error' => 'CSRF'], 403);

    $in = json_in();
    $expected = $_SESSION['wa_reg_challenge'] ?? '';
    unset($_SESSION['wa_reg_challenge']);
    if ($expected === '') json_out(['error' => 'Session expirée, réessayez.'], 400);

    try {
        $clientDataJSON = b64url_decode($in['response']['clientDataJSON'] ?? '');
        $attObj         = b64url_decode($in['response']['attestationObject'] ?? '');
        $client = json_decode($clientDataJSON, true);

        if (($client['type'] ?? '') !== 'webauthn.create') throw new RuntimeException('Type invalide.');
        if (!hash_equals($expected, $client['challenge'] ?? '')) throw new RuntimeException('Challenge invalide.');
        if (($client['origin'] ?? '') !== $origin) throw new RuntimeException('Origine invalide.');

        [$att] = cbor_decode($attObj, 0);
        $authData = $att['authData'] ?? '';
        $parsed = parse_authenticator_data($authData);
        if (empty($parsed['cosePub']) || empty($parsed['credId'])) throw new RuntimeException('Données d\'attestation absentes.');
        if (!hash_equals($rpId ? hash('sha256', $rpId, true) : '', $parsed['rpIdHash'])) {
            throw new RuntimeException('RP ID invalide.');
        }

        [$pem] = cose_to_pem($parsed['cosePub']);
        $credIdB64 = b64url_encode($parsed['credId']);
        $label = trim((string)($in['label'] ?? ''));
        if ($label === '') $label = 'Clé d\'accès';
        $transports = implode(',', array_map('strval', (array)($in['transports'] ?? [])));

        $st = $pdo->prepare(
            'INSERT INTO webauthn_credentials (user_id, credential_id, public_key, sign_count, transports, label)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE public_key=VALUES(public_key), label=VALUES(label)'
        );
        $st->execute([$user['id'], $credIdB64, $pem, $parsed['signCount'], mb_substr($transports, 0, 120), mb_substr($label, 0, 120)]);
        log_activity((int)$user['id'], 'webauthn_add', 'passkey', null, $label);

        json_out(['ok' => true, 'label' => $label]);
    } catch (Throwable $e) {
        json_out(['error' => 'Enregistrement impossible : ' . $e->getMessage()], 400);
    }
}

/* ------------------------------------------------ Connexion (non connecté) */

if ($action === 'login_begin') {
    $in = json_in();
    $ident = trim($in['username'] ?? '');
    if ($ident === '') json_out(['error' => 'Identifiant requis.'], 422);

    $st = $pdo->prepare('SELECT id, username FROM users WHERE (username=? OR email=?) AND is_active=1 LIMIT 1');
    $st->execute([$ident, $ident]);
    $u = $st->fetch();
    // Réponse volontairement identique si aucun compte / aucune clé (anti-énumération)
    if (!$u) json_out(['error' => 'Aucune clé d\'accès pour ce compte.'], 404);

    $cs = $pdo->prepare('SELECT credential_id, transports FROM webauthn_credentials WHERE user_id=?');
    $cs->execute([$u['id']]);
    $creds = $cs->fetchAll();
    if (!$creds) json_out(['error' => 'Aucune clé d\'accès pour ce compte.'], 404);

    $challenge = random_bytes(32);
    $_SESSION['wa_login_challenge'] = b64url_encode($challenge);
    $_SESSION['wa_login_uid'] = (int)$u['id'];

    $allow = [];
    foreach ($creds as $c) {
        $allow[] = [
            'type' => 'public-key',
            'id'   => $c['credential_id'],
            'transports' => $c['transports'] !== '' ? explode(',', $c['transports']) : [],
        ];
    }

    json_out([
        'challenge'        => b64url_encode($challenge),
        'rpId'             => $rpId,
        'timeout'          => 60000,
        'userVerification' => 'preferred',
        'allowCredentials' => $allow,
    ]);
}

if ($action === 'login_finish') {
    $in = json_in();
    $expected = $_SESSION['wa_login_challenge'] ?? '';
    $uid = (int)($_SESSION['wa_login_uid'] ?? 0);
    unset($_SESSION['wa_login_challenge'], $_SESSION['wa_login_uid']);
    if ($expected === '' || !$uid) json_out(['error' => 'Session expirée, réessayez.'], 400);

    try {
        $clientDataJSON = b64url_decode($in['response']['clientDataJSON'] ?? '');
        $authData       = b64url_decode($in['response']['authenticatorData'] ?? '');
        $signature      = b64url_decode($in['response']['signature'] ?? '');
        $credIdB64      = $in['id'] ?? '';

        $client = json_decode($clientDataJSON, true);
        if (($client['type'] ?? '') !== 'webauthn.get') throw new RuntimeException('Type invalide.');
        if (!hash_equals($expected, $client['challenge'] ?? '')) throw new RuntimeException('Challenge invalide.');
        if (($client['origin'] ?? '') !== $origin) throw new RuntimeException('Origine invalide.');

        // Vérifie le rpIdHash contenu dans authData
        $parsed = parse_authenticator_data($authData);
        if (!hash_equals(hash('sha256', $rpId, true), $parsed['rpIdHash'])) {
            throw new RuntimeException('RP ID invalide.');
        }

        $cs = $pdo->prepare('SELECT * FROM webauthn_credentials WHERE credential_id=? AND user_id=? LIMIT 1');
        $cs->execute([$credIdB64, $uid]);
        $cred = $cs->fetch();
        if (!$cred) throw new RuntimeException('Clé inconnue.');

        if (!webauthn_verify_signature($cred['public_key'], $authData, $clientDataJSON, $signature)) {
            throw new RuntimeException('Signature invalide.');
        }

        // Anti-rejeu : le compteur doit progresser (0/0 toléré)
        $newCount = $parsed['signCount'];
        if ($newCount !== 0 && $newCount <= (int)$cred['sign_count'] && (int)$cred['sign_count'] !== 0) {
            throw new RuntimeException('Compteur de signature invalide.');
        }
        $pdo->prepare('UPDATE webauthn_credentials SET sign_count=?, last_used=NOW() WHERE id=?')
            ->execute([$newCount, $cred['id']]);

        $us = $pdo->prepare('SELECT * FROM users WHERE id=? AND is_active=1');
        $us->execute([$uid]);
        $user = $us->fetch();
        if (!$user) throw new RuntimeException('Compte introuvable.');

        finalize_login($user);
        $dest = $_SESSION['after_login'] ?? url('index.php');
        unset($_SESSION['after_login']);
        if (!preg_match('#^/[A-Za-z0-9_./?=&%-]*$#', $dest) || strpos($dest, '//') !== false) $dest = url('index.php');
        json_out(['ok' => true, 'redirect' => $dest]);
    } catch (Throwable $e) {
        json_out(['error' => 'Connexion par clé impossible : ' . $e->getMessage()], 400);
    }
}

/* ------------------------------------------------ Gestion (liste / suppression) */

if ($action === 'list') {
    $user = require_login();
    $st = $pdo->prepare('SELECT id, label, transports, created_at, last_used FROM webauthn_credentials WHERE user_id=? ORDER BY created_at DESC');
    $st->execute([$user['id']]);
    json_out(['items' => $st->fetchAll()]);
}

if ($action === 'delete') {
    $user = require_login();
    if (!csrf_check($_SERVER['HTTP_X_CSRF'] ?? '')) json_out(['error' => 'CSRF'], 403);
    $id = (int)(json_in()['id'] ?? 0);
    $pdo->prepare('DELETE FROM webauthn_credentials WHERE id=? AND user_id=?')->execute([$id, $user['id']]);
    log_activity((int)$user['id'], 'webauthn_del', 'passkey', $id);
    json_out(['ok' => true]);
}

json_out(['error' => 'Action inconnue.'], 400);
