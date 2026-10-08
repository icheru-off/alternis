<?php
/**
 * Chiffrement symétrique léger pour stocker des secrets par utilisateur
 * (ex. mot de passe d'application Gmail). Repose sur APP_SECRET défini dans
 * config.php et l'extension OpenSSL. On ne stocke jamais le secret en clair.
 */

/** Clé binaire dérivée de APP_SECRET. */
function alt_crypt_key(): string
{
    $secret = defined('APP_SECRET') ? APP_SECRET : 'alternis-default-key';
    return hash('sha256', 'altmail|' . $secret, true);
}

/**
 * Chiffre une chaîne. Renvoie une valeur base64 (iv + tag + données) préfixée
 * « enc: » pour être reconnaissable. Renvoie '' si l'entrée est vide.
 */
function alt_encrypt(string $plain): string
{
    if ($plain === '') return '';
    if (!function_exists('openssl_encrypt')) {
        // Repli (peu probable) : encodage réversible simple, non sécurisé.
        return 'b64:' . base64_encode($plain);
    }
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', alt_crypt_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) return '';
    return 'enc:' . base64_encode($iv . $tag . $ct);
}

/** Déchiffre une valeur produite par alt_encrypt(). Renvoie '' si impossible. */
function alt_decrypt(string $stored): string
{
    if ($stored === '') return '';
    if (strncmp($stored, 'b64:', 4) === 0) {
        return (string)base64_decode(substr($stored, 4));
    }
    if (strncmp($stored, 'enc:', 4) !== 0) {
        // Valeur héritée non chiffrée : on la renvoie telle quelle.
        return $stored;
    }
    if (!function_exists('openssl_decrypt')) return '';
    $raw = base64_decode(substr($stored, 4), true);
    if ($raw === false || strlen($raw) < 28) return '';
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct = substr($raw, 28);
    $plain = openssl_decrypt($ct, 'aes-256-gcm', alt_crypt_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}

/** Masque une adresse/secret pour affichage (ex. « pr•••@gmail.com »). */
function alt_mask_email(string $email): string
{
    $at = strpos($email, '@');
    if ($at === false || $at < 2) return $email === '' ? '' : '•••';
    return substr($email, 0, 2) . str_repeat('•', min(3, $at - 2)) . substr($email, $at);
}
