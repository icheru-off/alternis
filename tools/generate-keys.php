<?php
/**
 * Alternis — Générateur de secrets
 * --------------------------------
 * Usage (en ligne de commande) :
 *     php tools/generate-keys.php
 *
 * Affiche des lignes prêtes à coller dans config/config.php :
 *   - APP_SECRET        : clé applicative aléatoire (64 caractères hex)
 *   - CRON_TOKEN        : jeton de la tâche planifiée
 *   - VAPID_PUBLIC_KEY  : clé publique Web Push (base64url, point P-256 non compressé)
 *   - VAPID_PRIVATE_PEM : clé privée Web Push (PEM)
 *
 * Nécessite l'extension PHP openssl. Ne publiez jamais la sortie de ce script.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("À exécuter en ligne de commande uniquement.\n");
}
if (!function_exists('openssl_pkey_new')) {
    fwrite(STDERR, "L'extension PHP openssl est requise.\n");
    exit(1);
}

function b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
if (!$key) {
    fwrite(STDERR, "Échec de la génération de la clé EC : " . openssl_error_string() . "\n");
    exit(1);
}
openssl_pkey_export($key, $pem);
$d = openssl_pkey_get_details($key);
$x = str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT);
$y = str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
$public = b64url("\x04" . $x . $y);

$appSecret = bin2hex(random_bytes(32));
$cronToken = bin2hex(random_bytes(24));

echo "// ---- À coller dans config/config.php ----\n";
echo "define('APP_SECRET', '{$appSecret}');\n";
echo "define('CRON_TOKEN', '{$cronToken}');\n";
echo "define('VAPID_PUBLIC_KEY', '{$public}');\n";
echo "define('VAPID_PRIVATE_PEM', <<<'PEM'\n" . trim($pem) . "\nPEM);\n";
