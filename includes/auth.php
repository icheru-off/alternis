<?php
require_once __DIR__ . '/functions.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('alternis_sess');
    session_start();
}

// Reconnexion automatique via « se souvenir de moi » (jeton d'appareil)
if (empty($_SESSION['uid']) && !empty($_COOKIE['alt_remember'])) {
    remember_bootstrap();
}

/** Utilisateur connecté (ou null). */
function current_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    static $cache = null;
    if ($cache !== null && $cache['id'] == $_SESSION['uid']) {
        return $cache;
    }
    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    $cache = $u ?: null;
    return $cache;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'admin';
}

/** Exige une session valide, sinon redirige vers la connexion. */
function require_login(): array
{
    $u = current_user();
    if (!$u) {
        // Mémorise la page demandée pour y revenir après connexion
        // (utile notamment pour les liens d'invitation à collaborer).
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !empty($_SERVER['REQUEST_URI'])) {
            $_SESSION['after_login'] = $_SERVER['REQUEST_URI'];
        }
        redirect('auth/login.php');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        die('Accès réservé à l\'administrateur.');
    }
    return $u;
}

/**
 * Renvoie l'ID de l'étudiant dont on manipule les données.
 * - étudiant  : lui-même
 * - parent    : l'étudiant lié
 * - admin     : lui-même, ou un étudiant ciblé via ?owner= (session)
 */
function data_owner_id(): int
{
    $u = current_user();
    if (!$u) {
        return 0;
    }
    if ($u['role'] === 'parent' && !empty($u['linked_student_id'])) {
        return (int)$u['linked_student_id'];
    }
    if ($u['role'] === 'admin' && !empty($_SESSION['admin_owner'])) {
        return (int)$_SESSION['admin_owner'];
    }
    // Collaboration : l'utilisateur consulte les données d'un étudiant qui l'a
    // invité (le lien doit toujours être actif).
    if (!empty($_SESSION['collab_owner'])) {
        $ownerId = (int)$_SESSION['collab_owner'];
        if (collab_can_access((int)$u['id'], $ownerId)) {
            return $ownerId;
        }
        unset($_SESSION['collab_owner']);
    }
    return (int)$u['id'];
}

/** L'utilisateur $collabId a-t-il un lien de collaboration actif vers $ownerId ? */
function collab_can_access(int $collabId, int $ownerId): bool
{
    if ($collabId === $ownerId) return true;
    try {
        ensure_accounts_tables();
        $st = db()->prepare('SELECT 1 FROM collab_links WHERE owner_id = ? AND collab_id = ? LIMIT 1');
        $st->execute([$ownerId, $collabId]);
        return (bool)$st->fetch();
    } catch (Throwable $e) { return false; }
}

/** L'utilisateur courant peut-il MODIFIER les données du propriétaire courant ? */
function can_edit_current_owner(): bool
{
    $u = current_user();
    if (!$u) return false;
    $owner = data_owner_id();
    if ($owner === (int)$u['id']) return true;                 // ses propres données
    if ($u['role'] === 'admin') return true;                   // admin en vue directe
    if (!empty($_SESSION['collab_owner']) && (int)$_SESSION['collab_owner'] === $owner) {
        try {
            $st = db()->prepare('SELECT can_edit FROM collab_links WHERE owner_id = ? AND collab_id = ? LIMIT 1');
            $st->execute([$owner, (int)$u['id']]);
            return (int)$st->fetchColumn() === 1;
        } catch (Throwable $e) { return false; }
    }
    return false;   // parent = lecture seule
}

/** Connexion : renvoie [ok, user|message, needs_otp]. */
function attempt_login(string $identifier, string $password): array
{
    $st = db()->prepare('SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1');
    $st->execute([$identifier, $identifier]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) {
        return ['ok' => false, 'msg' => 'Identifiant ou mot de passe incorrect.'];
    }
    if ($u['otp_enabled']) {
        // Étape OTP requise : on mémorise l'utilisateur en attente
        $_SESSION['pending_otp_uid'] = (int)$u['id'];
        return ['ok' => true, 'needs_otp' => true, 'user' => $u];
    }
    finalize_login($u);
    return ['ok' => true, 'needs_otp' => false, 'user' => $u];
}

/** Ouvre réellement la session pour un utilisateur. */
function finalize_login(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    unset($_SESSION['pending_otp_uid'], $_SESSION['admin_owner']);
    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$u['id']]);
    log_activity((int)$u['id'], 'login');
    _issue_device_token((int)$u['id'], !empty($_SESSION['remember_me']));
    unset($_SESSION['remember_me']);
}

function ensure_devices_table(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS `user_devices` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `selector` CHAR(24) NOT NULL,
        `validator` CHAR(64) NOT NULL,
        `user_agent` VARCHAR(255) NOT NULL DEFAULT "",
        `ip` VARCHAR(45) NOT NULL DEFAULT "",
        `last_active` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expires_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`), UNIQUE KEY `uq_selector` (`selector`), KEY `idx_dev_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function _remember_cookie(string $value, int $expires): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;
    setcookie('alt_remember', $value, [
        'expires' => $expires, 'path' => '/', 'secure' => $secure,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    $_COOKIE['alt_remember'] = $value;
}

function _issue_device_token(int $uid, bool $remember): void
{
    try {
        ensure_devices_table();
        $selector  = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $days = $remember ? 30 : 1;
        $expires = date('Y-m-d H:i:s', time() + $days * 86400);
        db()->prepare('INSERT INTO user_devices (user_id, selector, validator, user_agent, ip, expires_at)
                       VALUES (?,?,?,?,?,?)')
            ->execute([$uid, $selector, hash('sha256', $validator),
                       substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                       $_SERVER['REMOTE_ADDR'] ?? '', $expires]);
        $_SESSION['device_selector'] = $selector;
        _remember_cookie($selector . '.' . $validator, $remember ? time() + $days * 86400 : 0);
    } catch (Throwable $e) {}
}

/** Tente une reconnexion depuis le cookie « se souvenir de moi ». */
function remember_bootstrap(): void
{
    $raw = $_COOKIE['alt_remember'] ?? '';
    if (strpos($raw, '.') === false) return;
    [$selector, $validator] = explode('.', $raw, 2);
    try {
        $st = db()->prepare('SELECT * FROM user_devices WHERE selector = ? AND expires_at > NOW() LIMIT 1');
        $st->execute([$selector]);
        $dev = $st->fetch();
        if (!$dev) { _remember_cookie('', time() - 3600); return; }
        if (!hash_equals($dev['validator'], hash('sha256', $validator))) {
            db()->prepare('DELETE FROM user_devices WHERE id = ?')->execute([$dev['id']]);
            _remember_cookie('', time() - 3600);
            return;
        }
        $us = db()->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
        $us->execute([$dev['user_id']]);
        if (!$us->fetchColumn()) return;
        $_SESSION['uid'] = (int)$dev['user_id'];
        $_SESSION['device_selector'] = $selector;
        db()->prepare('UPDATE user_devices SET last_active = NOW(), ip = ? WHERE id = ?')
            ->execute([$_SERVER['REMOTE_ADDR'] ?? '', $dev['id']]);
    } catch (Throwable $e) {}
}

function logout(): void
{
    try {
        if (!empty($_SESSION['device_selector'])) {
            db()->prepare('DELETE FROM user_devices WHERE selector = ?')->execute([$_SESSION['device_selector']]);
        }
    } catch (Throwable $e) {}
    _remember_cookie('', time() - 3600);

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Rôle lisible. */
function role_label(string $r): string
{
    return ['admin' => 'Administrateur', 'student' => 'Étudiant', 'parent' => 'Parent'][$r] ?? $r;
}
