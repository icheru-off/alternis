<?php
/**
 * Alternis — Support WebAuthn / Passkeys (clés d'accès)
 * -----------------------------------------------------
 * Implémentation légère et sans dépendance :
 *   - encodage / décodage base64url
 *   - décodeur CBOR minimal (attestationObject + COSE_Key)
 *   - conversion d'une clé publique COSE (EC P-256 ou RSA) en PEM
 *   - vérification de signature via OpenSSL
 *
 * Attestation « none » acceptée (cas des authentificateurs de plateforme :
 * Face ID, Touch ID, Windows Hello, clés FIDO2). On ne vérifie pas la chaîne
 * d'attestation (inutile ici) mais on vérifie bien challenge, origine et
 * signature — ce qui protège l'authentification.
 */

/* ------------------------------------------------------------ base64url */

function b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64url_decode(string $txt): string
{
    $txt = strtr($txt, '-_', '+/');
    $pad = strlen($txt) % 4;
    if ($pad) $txt .= str_repeat('=', 4 - $pad);
    return base64_decode($txt) ?: '';
}

/* ------------------------------------------------------------ CBOR (décodage) */

/**
 * Décode une valeur CBOR à partir de $offset.
 * Retourne [valeur, nouvel_offset]. Gère les types utiles au WebAuthn :
 * entiers (positifs/négatifs), chaînes d'octets, chaînes de texte,
 * tableaux et maps.
 */
function cbor_decode(string $data, int $offset = 0): array
{
    if ($offset >= strlen($data)) {
        throw new RuntimeException('CBOR : fin de données inattendue.');
    }
    $ib = ord($data[$offset]);
    $major = $ib >> 5;
    $info  = $ib & 0x1f;
    $offset++;

    $readLen = function (int $info) use ($data, &$offset): int {
        if ($info < 24) return $info;
        if ($info === 24) { $v = ord($data[$offset]); $offset += 1; return $v; }
        if ($info === 25) { $v = unpack('n', substr($data, $offset, 2))[1]; $offset += 2; return $v; }
        if ($info === 26) { $v = unpack('N', substr($data, $offset, 4))[1]; $offset += 4; return $v; }
        if ($info === 27) {
            // 64 bits — on lit mais on suppose que ça tient dans un int PHP
            $hi = unpack('N', substr($data, $offset, 4))[1];
            $lo = unpack('N', substr($data, $offset + 4, 4))[1];
            $offset += 8;
            return ($hi << 32) | $lo;
        }
        throw new RuntimeException('CBOR : longueur non supportée.');
    };

    switch ($major) {
        case 0: // entier positif
            return [$readLen($info), $offset];
        case 1: // entier négatif
            return [-1 - $readLen($info), $offset];
        case 2: // chaîne d'octets
            $len = $readLen($info);
            $val = substr($data, $offset, $len);
            $offset += $len;
            return [$val, $offset];
        case 3: // chaîne de texte
            $len = $readLen($info);
            $val = substr($data, $offset, $len);
            $offset += $len;
            return [$val, $offset];
        case 4: // tableau
            $len = $readLen($info);
            $arr = [];
            for ($i = 0; $i < $len; $i++) {
                [$v, $offset] = cbor_decode($data, $offset);
                $arr[] = $v;
            }
            return [$arr, $offset];
        case 5: // map
            $len = $readLen($info);
            $map = [];
            for ($i = 0; $i < $len; $i++) {
                [$k, $offset] = cbor_decode($data, $offset);
                [$v, $offset] = cbor_decode($data, $offset);
                $map[is_int($k) ? $k : (string)$k] = $v;
            }
            return [$map, $offset];
        case 7: // simple / float — on gère true/false/null
            if ($info === 20) return [false, $offset];
            if ($info === 21) return [true, $offset];
            if ($info === 22) return [null, $offset];
            throw new RuntimeException('CBOR : type simple non supporté.');
        default:
            throw new RuntimeException('CBOR : type majeur non supporté (' . $major . ').');
    }
}

/* --------------------------------------------- authenticatorData (parse) */

/**
 * Découpe authenticatorData.
 * Retourne au minimum ['rpIdHash','flags','signCount'] et, si présente,
 * l'attested credential data ['aaguid','credId','cosePub' (map)].
 */
function parse_authenticator_data(string $ad): array
{
    if (strlen($ad) < 37) throw new RuntimeException('authData trop court.');
    $out = [
        'rpIdHash'  => substr($ad, 0, 32),
        'flags'     => ord($ad[32]),
        'signCount' => unpack('N', substr($ad, 33, 4))[1],
    ];
    $out['userPresent']  = (bool)($out['flags'] & 0x01);
    $out['userVerified'] = (bool)($out['flags'] & 0x04);
    $hasAttested         = (bool)($out['flags'] & 0x40);

    if ($hasAttested) {
        $p = 37;
        $out['aaguid'] = substr($ad, $p, 16); $p += 16;
        $credLen = unpack('n', substr($ad, $p, 2))[1]; $p += 2;
        $out['credId'] = substr($ad, $p, $credLen); $p += $credLen;
        [$cose, $p] = cbor_decode($ad, $p);
        $out['cosePub'] = $cose;
    }
    return $out;
}

/* --------------------------------------------- COSE_Key -> PEM (clé publique) */

/** Encode une longueur DER. */
function der_len(int $len): string
{
    if ($len < 0x80) return chr($len);
    $bytes = '';
    while ($len > 0) { $bytes = chr($len & 0xff) . $bytes; $len >>= 8; }
    return chr(0x80 | strlen($bytes)) . $bytes;
}

/** Encode un INTEGER DER (positif, non signé). */
function der_uint(string $bin): string
{
    $bin = ltrim($bin, "\x00");
    if ($bin === '') $bin = "\x00";
    if (ord($bin[0]) & 0x80) $bin = "\x00" . $bin; // garde le signe positif
    return "\x02" . der_len(strlen($bin)) . $bin;
}

/**
 * Construit un PEM de clé publique depuis une map COSE_Key.
 * Supporte EC2/P-256 (alg -7) et RSA (alg -257).
 * Retourne [pem, algo] où algo = OPENSSL_ALGO_SHA256.
 */
function cose_to_pem(array $cose): array
{
    $kty = $cose[1] ?? null;

    if ($kty === 2) { // EC2
        $x = $cose[-2] ?? '';
        $y = $cose[-3] ?? '';
        if (strlen($x) !== 32 || strlen($y) !== 32) {
            throw new RuntimeException('Clé EC invalide.');
        }
        // SubjectPublicKeyInfo pour prime256v1
        $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
             . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00\x04"
             . $x . $y;
        return [der_to_pem($der), OPENSSL_ALGO_SHA256];
    }

    if ($kty === 3) { // RSA
        $n = $cose[-1] ?? '';
        $e = $cose[-2] ?? '';
        if ($n === '' || $e === '') throw new RuntimeException('Clé RSA invalide.');
        // RSAPublicKey ::= SEQUENCE { modulus INTEGER, exponent INTEGER }
        $rsaPub = "\x30" . der_len(strlen(der_uint($n) . der_uint($e))) . der_uint($n) . der_uint($e);
        // SubjectPublicKeyInfo { AlgorithmIdentifier(rsaEncryption), BIT STRING(rsaPub) }
        $algId  = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $bitStr = "\x03" . der_len(strlen($rsaPub) + 1) . "\x00" . $rsaPub;
        $spki   = "\x30" . der_len(strlen($algId . $bitStr)) . $algId . $bitStr;
        return [der_to_pem($spki), OPENSSL_ALGO_SHA256];
    }

    throw new RuntimeException('Type de clé COSE non supporté.');
}

function der_to_pem(string $der): string
{
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

/* --------------------------------------------- Vérification de signature */

/**
 * Vérifie une assertion WebAuthn.
 * $pem      : clé publique stockée (PEM)
 * $authData : authenticatorData brut (bytes)
 * $clientDataJSON : clientDataJSON brut (bytes)
 * $signature: signature brute (bytes) — DER pour EC, PKCS#1 pour RSA
 */
function webauthn_verify_signature(string $pem, string $authData, string $clientDataJSON, string $signature): bool
{
    $signedData = $authData . hash('sha256', $clientDataJSON, true);
    $key = openssl_pkey_get_public($pem);
    if ($key === false) return false;
    $ok = openssl_verify($signedData, $signature, $key, OPENSSL_ALGO_SHA256);
    return $ok === 1;
}

/** rp id (domaine) déduit de l'hôte courant. */
function webauthn_rp_id(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return preg_replace('/:\d+$/', '', $host); // retire le port éventuel
}

/** Origine attendue (schéma + hôte). */
function webauthn_origin(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
