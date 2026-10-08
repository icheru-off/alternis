<?php
/**
 * TOTP (RFC 6238) en PHP pur — compatible Google Authenticator, Authy, etc.
 * Aucune dépendance externe.
 */
class TOTP
{
    /** Génère un secret aléatoire encodé en Base32. */
    public static function generateSecret(int $length = 20): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bytes = random_bytes($length);
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0');
            }
            $secret .= $alphabet[bindec($chunk)];
        }
        return $secret;
    }

    /** Décode une chaîne Base32 en binaire. */
    private static function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
        if ($secret === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($secret) as $c) {
            $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }
        return $bytes;
    }

    /** Calcule le code à 6 chiffres pour une fenêtre temporelle donnée. */
    public static function code(string $secret, ?int $timeSlice = null, int $digits = 6): string
    {
        if ($timeSlice === null) {
            $timeSlice = (int)floor(time() / 30);
        }
        $key = self::base32Decode($secret);
        $bin = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part = substr($hash, $offset, 4);
        $value = (
            ((ord($part[0]) & 0x7F) << 24) |
            ((ord($part[1]) & 0xFF) << 16) |
            ((ord($part[2]) & 0xFF) << 8) |
            (ord($part[3]) & 0xFF)
        ) % (10 ** $digits);
        return str_pad((string)$value, $digits, '0', STR_PAD_LEFT);
    }

    /** Vérifie un code saisi avec une tolérance (±$window fenêtres de 30s). */
    public static function verify(string $secret, string $input, int $window = 1): bool
    {
        $input = preg_replace('/\s+/', '', $input);
        if (!preg_match('/^\d{6}$/', $input)) {
            return false;
        }
        $current = (int)floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $current + $i), $input)) {
                return true;
            }
        }
        return false;
    }

    /** Construit l'URI otpauth:// à encoder en QR code. */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }
}
