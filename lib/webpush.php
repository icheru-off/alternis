<?php
/**
 * Alternis — Web Push sans dépendance externe.
 *
 * Implémente :
 *   - RFC 8291 : chiffrement du contenu (aes128gcm, ECDH P-256 + HKDF)
 *   - RFC 8292 : authentification VAPID (JWT ES256)
 *
 * Nécessite l'extension openssl (PHP >= 7.3 pour openssl_pkey_derive).
 */

/**
 * Liste les prérequis serveur manquants pour le Web Push.
 * Retourne un tableau vide si tout est en place.
 */
function wp_missing_requirements(): array
{
    $miss = [];
    if (!extension_loaded('openssl'))            $miss[] = 'extension openssl';
    if (!function_exists('openssl_pkey_derive')) $miss[] = 'openssl_pkey_derive (PHP >= 7.3)';
    if (!function_exists('openssl_pkey_new'))    $miss[] = 'openssl_pkey_new';
    if (!in_array('aes-128-gcm', openssl_get_cipher_methods() ?: [], true)) $miss[] = 'chiffrement aes-128-gcm';
    if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) $miss[] = 'cURL ou allow_url_fopen';
    return $miss;
}

/** Base64 « URL-safe » sans remplissage. */
function wp_b64(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** Décodage base64 « URL-safe ». */
function wp_b64d(string $txt): string
{
    $txt = strtr($txt, '-_', '+/');
    $pad = strlen($txt) % 4;
    if ($pad) $txt .= str_repeat('=', 4 - $pad);
    return (string)base64_decode($txt);
}

/** HKDF-Expand réduit (un seul bloc, longueur <= 32). */
function wp_hkdf(string $salt, string $ikm, string $info, int $len): string
{
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $out = hash_hmac('sha256', $info . "\x01", $prk, true);
    return substr($out, 0, $len);
}

/**
 * Reconstruit une clé publique EC P-256 exploitable par openssl
 * à partir du point brut non compressé (65 octets : 0x04 || X || Y).
 */
function wp_public_key_from_point(string $point)
{
    if (strlen($point) !== 65 || $point[0] !== "\x04") return false;
    // SubjectPublicKeyInfo DER pour id-ecPublicKey / prime256v1
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
}

/** Convertit une signature DER en signature « raw » 64 octets (r || s). */
function wp_der_to_raw(string $der): string
{
    $off = 0;
    if (($der[$off++] ?? '') !== "\x30") return '';
    $len = ord($der[$off++]);
    if ($len & 0x80) $off += ($len & 0x7f);      // longueur sur plusieurs octets

    $read = function () use ($der, &$off) {
        if (($der[$off++] ?? '') !== "\x02") return '';
        $l = ord($der[$off++]);
        $v = substr($der, $off, $l);
        $off += $l;
        return ltrim($v, "\x00");                // retire le zéro de signe
    };
    $r = $read();
    $s = $read();
    return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}

/** Construit l'en-tête Authorization VAPID pour une origine donnée. */
function wp_vapid_header(string $audience): ?string
{
    if (!defined('VAPID_PRIVATE_PEM') || trim(VAPID_PRIVATE_PEM) === '') return null;
    $key = openssl_pkey_get_private(VAPID_PRIVATE_PEM);
    if (!$key) return null;

    $header = wp_b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = wp_b64(json_encode([
        'aud' => $audience,
        'exp' => time() + 12 * 3600,
        'sub' => defined('VAPID_SUBJECT') ? VAPID_SUBJECT : 'mailto:admin@example.com',
    ], JSON_UNESCAPED_SLASHES));

    $signingInput = $header . '.' . $claims;
    $der = '';
    if (!openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256)) return null;
    $sig = wp_der_to_raw($der);
    if (strlen($sig) !== 64) return null;

    $jwt = $signingInput . '.' . wp_b64($sig);
    return 'vapid t=' . $jwt . ',k=' . VAPID_PUBLIC_KEY;
}

/**
 * Chiffre le contenu selon aes128gcm (RFC 8291).
 * $uaPublic : clé p256dh brute (65 o), $authSecret : secret auth (16 o).
 */
function wp_encrypt(string $payload, string $uaPublic, string $authSecret): ?array
{
    $uaKey = wp_public_key_from_point($uaPublic);
    if (!$uaKey) return null;

    // Clé éphémère du serveur d'application
    $as = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$as) return null;
    $asDet = openssl_pkey_get_details($as);
    $asPublic = "\x04"
        . str_pad($asDet['ec']['x'], 32, "\x00", STR_PAD_LEFT)
        . str_pad($asDet['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    // Secret partagé ECDH
    $shared = openssl_pkey_derive($uaKey, $as, 32);
    if ($shared === false) return null;

    // IKM = HKDF(auth_secret, ecdh, "WebPush: info\0" || ua_pub || as_pub)
    $keyInfo = "WebPush: info\x00" . $uaPublic . $asPublic;
    $ikm = wp_hkdf($authSecret, $shared, $keyInfo, 32);

    $salt = random_bytes(16);
    $cek = wp_hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = wp_hkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);

    // Un seul enregistrement : délimiteur 0x02 en fin de contenu
    $plain = $payload . "\x02";
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false) return null;

    // En-tête : salt(16) || rs(4) || idlen(1) || as_public(65)
    $rs = 4096;
    $body = $salt . pack('N', $rs) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;
    return ['body' => $body];
}

/**
 * Envoie une notification push à un abonnement.
 * $sub : ['endpoint'=>..., 'p256dh'=>..., 'auth'=>...]
 * Retour : ['ok'=>bool, 'status'=>int, 'error'=>string, 'gone'=>bool]
 */
function wp_send(array $sub, array $payload, int $ttl = 86400): array
{
    if (!defined('VAPID_PUBLIC_KEY') || VAPID_PUBLIC_KEY === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'VAPID non configuré.', 'gone' => false];
    }
    $endpoint = $sub['endpoint'] ?? '';
    if (!$endpoint) return ['ok' => false, 'status' => 0, 'error' => 'Endpoint manquant.', 'gone' => true];

    // Vérifie les prérequis serveur AVANT toute chose : une défaillance locale
    // ne doit jamais faire croire que l'abonnement est expiré.
    $miss = wp_missing_requirements();
    if ($miss) return ['ok' => false, 'status' => 0, 'error' => 'Serveur incompatible : ' . implode(', ', $miss), 'gone' => false];

    $parts = parse_url($endpoint);
    $audience = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
    $auth = wp_vapid_header($audience);
    if (!$auth) return ['ok' => false, 'status' => 0, 'error' => 'Clé VAPID invalide.', 'gone' => false];

    $enc = wp_encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE), wp_b64d($sub['p256dh'] ?? ''), wp_b64d($sub['auth'] ?? ''));
    if (!$enc) return ['ok' => false, 'status' => 0, 'error' => 'Chiffrement impossible (clé p256dh/auth invalide ou openssl incomplet).', 'gone' => false];

    $headers = [
        'Authorization: ' . $auth,
        'Content-Encoding: aes128gcm',
        'Content-Type: application/octet-stream',
        'TTL: ' . $ttl,
        'Urgency: normal',
    ];

    if (!function_exists('curl_init')) {
        // Repli sans cURL : flux HTTP natif
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $enc['body'],
            'timeout' => 12,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($endpoint, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
        $gone = in_array($status, [404, 410], true);
        $ok = $status >= 200 && $status < 300;
        return ['ok' => $ok, 'status' => $status, 'error' => $ok ? '' : ('HTTP ' . $status), 'gone' => $gone];
    }
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $enc['body'],
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    // 404/410 : l'abonnement n'existe plus, il faut le purger
    $gone = in_array($status, [404, 410], true);
    $ok = $status >= 200 && $status < 300;
    return [
        'ok' => $ok,
        'status' => $status,
        'error' => $ok ? '' : ($cerr ?: ('HTTP ' . $status . ' ' . substr((string)$resp, 0, 120))),
        'gone' => $gone,
    ];
}
