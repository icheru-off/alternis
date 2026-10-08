<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/totp.php';
$user = require_login();
$pdo = db();
$action = $_GET['action'] ?? '';

if (!csrf_check($_SERVER['HTTP_X_CSRF'] ?? '')) json_out(['error' => 'CSRF'], 403);

switch ($action) {

  case 'setup':
    // Génère un secret temporaire stocké en session (pas encore activé)
    $secret = TOTP::generateSecret();
    $_SESSION['otp_setup_secret'] = $secret;
    $account = $user['email'] ?: $user['username'];
    $uri = TOTP::provisioningUri($secret, $account, APP_NAME);
    json_out(['secret' => $secret, 'uri' => $uri]);
    break;

  case 'enable':
    $code = json_in()['code'] ?? '';
    $secret = $_SESSION['otp_setup_secret'] ?? '';
    if (!$secret) json_out(['error' => 'Relancez la configuration.'], 422);
    if (!TOTP::verify($secret, $code)) json_out(['error' => 'Code incorrect. Vérifiez l\'heure de votre téléphone.'], 422);
    $pdo->prepare('UPDATE users SET otp_enabled=1, otp_secret=? WHERE id=?')->execute([$secret, $user['id']]);
    unset($_SESSION['otp_setup_secret']);
    log_activity((int)$user['id'], 'otp_enable');
    json_out(['ok' => true]);
    break;

  case 'disable':
    $code = json_in()['code'] ?? '';
    // Exige un code valide pour désactiver
    if (!$user['otp_enabled'] || !TOTP::verify($user['otp_secret'], $code)) {
        json_out(['error' => 'Code de vérification requis pour désactiver.'], 422);
    }
    $pdo->prepare('UPDATE users SET otp_enabled=0, otp_secret=\'\' WHERE id=?')->execute([$user['id']]);
    log_activity((int)$user['id'], 'otp_disable');
    json_out(['ok' => true]);
    break;

  default:
    json_out(['error' => 'Action inconnue.'], 400);
}
