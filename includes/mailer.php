<?php
/**
 * Alternis — Petit client SMTP (authentifié, SSL/TLS) sans dépendance.
 * Utilisé pour envoyer les identifiants de connexion, relances, etc.
 */

/**
 * Résout un profil d'envoi.
 *
 *  - 'smtp'  : la boîte système (identifiants, notifications par e-mail).
 *              Constantes SMTP_HOST / SMTP_PORT / SMTP_SECURE / SMTP_USER /
 *              SMTP_PASS / SMTP_FROM / SMTP_FROM_NAME.
 *
 *  - 'gmail' : la messagerie personnelle de l'étudiant, pour les relances.
 *              Un recruteur qui répond doit écrire à l'étudiant, pas à la
 *              boîte no-reply. À configurer soi-même dans config.php avec un
 *              MOT DE PASSE D'APPLICATION Google (pas le mot de passe du compte) :
 *                define('GMAIL_USER', 'prenom.nom@gmail.com');
 *                define('GMAIL_APP_PASSWORD', 'xxxx xxxx xxxx xxxx');
 *                define('GMAIL_FROM_NAME', 'Prénom Nom'); // facultatif
 *
 * Retourne toujours un tableau ; si 'host' est vide, 'missing' explique pourquoi.
 */
function alt_mail_account(string $which, ?int $userId = null): array
{
    if ($which === 'gmail') {
        // 1) Priorité au compte Gmail personnel de l'utilisateur (réglages).
        if ($userId) {
            $u = alt_user_gmail($userId);
            if ($u && $u['user'] !== '' && $u['pass'] !== '') {
                return [
                    'host' => 'smtp.gmail.com', 'port' => 587, 'secure' => 'tls',
                    'user' => $u['user'], 'pass' => $u['pass'],
                    'from' => $u['user'],
                    'from_name' => $u['from_name'] !== '' ? $u['from_name'] : $u['user'],
                ];
            }
        }
        // 2) Repli : compte Gmail global défini dans config.php (facultatif).
        $user = defined('GMAIL_USER') ? trim(GMAIL_USER) : '';
        $pass = defined('GMAIL_APP_PASSWORD') ? trim(GMAIL_APP_PASSWORD) : '';
        if ($user === '' || $pass === '') {
            return ['host' => '', 'missing' =>
                "Aucune adresse Gmail configurée. Ajoutez la vôtre dans "
                . "Réglages → Relances pour envoyer depuis votre propre adresse."];
        }
        return [
            'host' => defined('GMAIL_HOST') ? GMAIL_HOST : 'smtp.gmail.com',
            'port' => defined('GMAIL_PORT') ? (int)GMAIL_PORT : 587,
            'secure' => defined('GMAIL_SECURE') ? GMAIL_SECURE : 'tls',
            'user' => $user,
            'pass' => $pass,
            'from' => $user,
            'from_name' => defined('GMAIL_FROM_NAME') && GMAIL_FROM_NAME !== '' ? GMAIL_FROM_NAME : $user,
        ];
    }

    // Profil système par défaut
    if (!defined('SMTP_HOST') || SMTP_HOST === '') {
        return ['host' => '', 'missing' => 'SMTP non configuré.'];
    }
    return [
        'host' => SMTP_HOST,
        'port' => defined('SMTP_PORT') ? (int)SMTP_PORT : 465,
        'secure' => defined('SMTP_SECURE') ? SMTP_SECURE : 'ssl',
        'user' => defined('SMTP_USER') ? SMTP_USER : '',
        'pass' => defined('SMTP_PASS') ? SMTP_PASS : '',
        'from' => defined('SMTP_FROM') ? SMTP_FROM : (defined('SMTP_USER') ? SMTP_USER : ''),
        'from_name' => defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Alternis',
    ];
}

/**
 * Lit les identifiants Gmail personnels d'un utilisateur (stockés chiffrés dans
 * ses préférences). Renvoie ['user'=>..,'pass'=>..,'from_name'=>..] ou null.
 */
function alt_user_gmail(int $userId): ?array
{
    if ($userId <= 0) return null;
    require_once __DIR__ . '/crypto.php';
    try {
        $user = trim((string)user_pref_get($userId, 'gmail_user', ''));
        $encPass = (string)user_pref_get($userId, 'gmail_pass', '');
        $fromName = trim((string)user_pref_get($userId, 'gmail_from_name', ''));
    } catch (Throwable $e) { return null; }
    if ($user === '' || $encPass === '') return null;
    return ['user' => $user, 'pass' => alt_decrypt($encPass), 'from_name' => $fromName];
}

/** Le profil Gmail est-il configuré (pour cet utilisateur, ou en global) ? */
function alt_gmail_ready(?int $userId = null): bool
{
    $a = alt_mail_account('gmail', $userId);
    return !empty($a['host']);
}

/** Alternis — Petit client SMTP (authentifié, SSL/TLS) sans dépendance. */
function alt_send_mail(string $toEmail, string $toName, string $subject, string $htmlBody, ?string $textBody = null, array $opts = []): array
{
    // Profil de compte : 'smtp' (défaut, boîte système) ou 'gmail' (relances).
    $acc = alt_mail_account($opts['account'] ?? 'smtp', $opts['user_id'] ?? null);
    if (empty($acc['host'])) {
        return ['ok' => false, 'error' => $acc['missing'] ?? 'SMTP non configuré.'];
    }
    $host = $acc['host'];
    $port = (int)$acc['port'];
    $secure = $acc['secure'];
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return ['ok' => false, 'error' => "Connexion SMTP impossible : $errstr ($errno)"];
    stream_set_timeout($fp, 20);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function ($c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };
    $code = fn($r) => (int)substr((string)$r, 0, 3);

    $greet = $read();
    if ($code($greet) !== 220) { fclose($fp); return ['ok' => false, 'error' => 'SMTP: pas de bannière (' . trim($greet) . ')']; }

    $ehlo = $cmd('EHLO alternis');
    if ($code($ehlo) !== 250) { $cmd('HELO alternis'); }

    // STARTTLS si mode tls sur port 587
    if ($secure === 'tls') {
        $r = $cmd('STARTTLS');
        if ($code($r) === 220) {
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($fp); return ['ok' => false, 'error' => 'Échec STARTTLS.'];
            }
            $cmd('EHLO alternis');
        }
    }

    $r = $cmd('AUTH LOGIN');
    if ($code($r) !== 334) { fclose($fp); return ['ok' => false, 'error' => 'AUTH refusé (' . trim($r) . ')']; }
    $r = $cmd(base64_encode($acc['user']));
    if ($code($r) !== 334) { fclose($fp); return ['ok' => false, 'error' => 'Utilisateur SMTP refusé.']; }
    $r = $cmd(base64_encode($acc['pass']));
    if ($code($r) !== 235) { fclose($fp); return ['ok' => false, 'error' => 'Authentification SMTP échouée.']; }

    $from = $acc['from'];
    $fromName = $acc['from_name'];
    $r = $cmd('MAIL FROM:<' . $from . '>');
    if ($code($r) !== 250) { fclose($fp); return ['ok' => false, 'error' => 'MAIL FROM refusé.']; }
    $r = $cmd('RCPT TO:<' . $toEmail . '>');
    if ($code($r) !== 250 && $code($r) !== 251) { fclose($fp); return ['ok' => false, 'error' => 'Destinataire refusé (' . trim($r) . ')']; }
    $r = $cmd('DATA');
    if ($code($r) !== 354) { fclose($fp); return ['ok' => false, 'error' => 'DATA refusé.']; }

    $boundary = 'alt_' . bin2hex(random_bytes(8));
    $encFrom = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $encSubj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $textBody = $textBody ?? trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody)));

    $headers = [];
    $headers[] = 'From: ' . $encFrom . ' <' . $from . '>';
    $headers[] = 'To: ' . ($toName !== '' ? '=?UTF-8?B?' . base64_encode($toName) . '?= ' : '') . '<' . $toEmail . '>';
    $headers[] = 'Subject: ' . $encSubj;
    // Réponse adressée à l'étudiant, pas à la boîte no-reply :
    // un recruteur qui répond doit joindre la bonne personne.
    if (!empty($opts['reply_to']) && filter_var($opts['reply_to'], FILTER_VALIDATE_EMAIL)) {
        $rn = trim((string)($opts['reply_name'] ?? ''));
        $headers[] = 'Reply-To: ' . ($rn !== '' ? '=?UTF-8?B?' . base64_encode($rn) . '?= ' : '') . '<' . $opts['reply_to'] . '>';
    }
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $body = '';
    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($textBody)) . "\r\n";
    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
    $body .= '--' . $boundary . "--\r\n";

    // Corps : les lignes commençant par "." doivent être doublées
    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    $message = preg_replace('/^\./m', '..', $message);

    fwrite($fp, $message . "\r\n.\r\n");
    $r = $read();
    $ok = $code($r) === 250;
    $cmd('QUIT');
    fclose($fp);
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Envoi refusé (' . trim($r) . ')'];
}

/** Modèle d'e-mail HTML simple aux couleurs d'Alternis. */
function alt_mail_template(string $title, string $bodyHtml): string
{
    // Les clients mail exigent une URL absolue pour les images.
    $root = (defined('APP_URL') && APP_URL !== '') ? rtrim(APP_URL, '/') : '';
    $logoUrl = $root . '/assets/img/logo-light.png';
    return '<!DOCTYPE html><html><body style="margin:0;background:#f4f5fb;font-family:Segoe UI,Arial,sans-serif;color:#1e2230">'
        . '<div style="max-width:520px;margin:24px auto;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e6e8f0">'
        . '<div style="background:#0b1220;padding:20px 26px;text-align:left">'
        .   '<img src="' . $logoUrl . '" alt="Alternis" width="132" height="44" style="display:block;height:44px;width:auto;border:0">'
        . '</div>'
        . '<div style="padding:26px">'
        . '<h2 style="margin:0 0 14px;font-size:18px">' . htmlspecialchars($title) . '</h2>'
        . $bodyHtml
        . '</div>'
        . '<div style="padding:16px 26px;background:#fafbff;color:#8a90a6;font-size:12px;border-top:1px solid #eef0f6">Cet e-mail vous est envoyé automatiquement par Alternis. Merci de ne pas y répondre.</div>'
        . '</div></body></html>';
}
