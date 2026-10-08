<?php
/**
 * Alternis — Relances assistées.
 *
 * Détecte les candidatures restées sans réponse, propose un brouillon
 * d'e-mail, envoie après validation explicite de l'utilisateur, puis
 * archive l'échange dans la chronologie.
 *
 * Principe : jamais d'envoi automatique. Un e-mail à un recruteur part
 * uniquement quand l'étudiant a relu et cliqué.
 */

/** Délai (jours) avant de proposer une première relance. */
function followup_delay_days(int $userId): int
{
    $v = (int)setting_get('followup_delay_' . $userId, 0);
    if ($v <= 0) $v = (int)setting_get('followup_delay_default', 10);
    return max(3, min(60, $v ?: 10));
}

/** Nombre maximum de relances proposées pour une même candidature. */
function followup_max(): int { return 2; }

/**
 * Candidatures qu'il est pertinent de relancer.
 * Critères : envoyée (ou déjà relancée une fois), sans réponse,
 * et le délai est écoulé depuis le dernier contact.
 */
function followups_due(int $ownerId, int $userId): array
{
    $delay = followup_delay_days($userId);
    $sql = "SELECT c.*, o.name AS org_name,
                   COALESCE(c.followup_date, c.applied_date) AS last_contact,
                   DATEDIFF(CURDATE(), COALESCE(c.followup_date, c.applied_date)) AS days_since,
                   (SELECT COUNT(*) FROM company_emails ce
                     WHERE ce.company_id = c.id AND ce.direction = 'envoye'
                       AND ce.subject LIKE 'Relance%') AS relances
            FROM companies c
            LEFT JOIN orgs o ON o.id = c.org_id
            WHERE c.owner_id = ?
              AND c.status IN ('envoye','relance')
              AND c.applied_date IS NOT NULL
              AND c.response_date IS NULL
              AND DATEDIFF(CURDATE(), COALESCE(c.followup_date, c.applied_date)) >= ?
            ORDER BY days_since DESC";
    try {
        $st = db()->prepare($sql);
        $st->execute([$ownerId, $delay]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) { return []; }

    // On ne harcèle pas : au-delà de deux relances, on n'en propose plus.
    return array_values(array_filter($rows, fn($r) => (int)$r['relances'] < followup_max()));
}

/** Compteur pour la pastille du menu. */
function followups_count(int $ownerId, int $userId): int
{
    return count(followups_due($ownerId, $userId));
}

/** Date au format français, sans dépendance à la locale du serveur. */
function fu_date(?string $d): string
{
    if (!$d) return '';
    $mois = ['01'=>'janvier','02'=>'février','03'=>'mars','04'=>'avril','05'=>'mai','06'=>'juin',
             '07'=>'juillet','08'=>'août','09'=>'septembre','10'=>'octobre','11'=>'novembre','12'=>'décembre'];
    $ts = strtotime($d);
    return (int)date('j', $ts) . ' ' . ($mois[date('m', $ts)] ?? '') . ' ' . date('Y', $ts);
}

/**
 * Brouillon de relance : objet + corps, en texte simple.
 * $variant : 1 = première relance, 2 = seconde (plus courte, plus directe),
 *            'interview' = remerciement après entretien.
 */
function followup_draft(array $c, array $me, $variant = 1): array
{
    $company  = trim($c['org_name'] ?? '') ?: trim($c['name'] ?? '');
    $position = trim($c['position'] ?? '');
    $contact  = trim($c['contact_name'] ?? '');
    $applied  = fu_date($c['applied_date'] ?? null);
    $myName   = trim($me['full_name'] ?? '') ?: trim($me['username'] ?? '');
    $myEmail  = trim($me['email'] ?? '');
    $myPhone  = trim($me['phone'] ?? '');
    $voc      = search_vocab(isset($me['id']) ? (int)$me['id'] : null);

    // Civilité : on ne devine pas le genre, on utilise le nom si on l'a.
    $hello = $contact !== '' ? 'Bonjour ' . $contact . ',' : 'Bonjour,';
    $poste = $position !== '' ? 'le poste de ' . $position : "votre offre de " . $voc['type'];
    $depuis = $applied !== '' ? ' le ' . $applied : '';

    $signature = "\n\nBien cordialement,\n" . $myName
        . ($myEmail !== '' ? "\n" . $myEmail : '')
        . ($myPhone !== '' ? "\n" . $myPhone : '');

    if ($variant === 'interview') {
        $subject = 'Suite à notre entretien — ' . ($position !== '' ? $position : $company);
        $body = $hello . "\n\n"
            . "Je vous remercie pour le temps que vous m'avez accordé lors de notre entretien. "
            . "Notre échange a confirmé mon intérêt pour " . $poste . " au sein de " . $company . ".\n\n"
            . "Je reste à votre disposition pour tout complément d'information et vous confirme ma motivation à rejoindre vos équipes en " . $voc['type'] . ".\n\n"
            . "Dans l'attente de votre retour,";
        return ['subject' => $subject, 'body' => $body . $signature];
    }

    if ((int)$variant === 2) {
        $subject = 'Relance — candidature ' . ($position !== '' ? $position : $voc['type']) . ' — ' . $myName;
        $body = $hello . "\n\n"
            . "Je me permets de revenir une dernière fois vers vous au sujet de ma candidature pour "
            . $poste . ", envoyée" . $depuis . ".\n\n"
            . "Si le poste n'est plus d'actualité ou si mon profil ne correspond pas à vos attentes, je comprendrai tout à fait : "
            . "un simple mot me permettrait d'organiser la suite de mes recherches.\n\n"
            . "Je vous remercie par avance pour votre retour.";
        return ['subject' => $subject, 'body' => $body . $signature];
    }

    $subject = 'Relance — candidature ' . ($position !== '' ? $position : $voc['type']) . ' — ' . $myName;
    // Formulation volontairement neutre : l'application ne connaît pas
    // le genre de l'utilisateur et n'a pas à le deviner.
    $body = $hello . "\n\n"
        . "Je me permets de revenir vers vous concernant ma candidature pour " . $poste
        . " au sein de " . $company . ", que je vous ai adressée" . $depuis . ".\n\n"
        . "Cette opportunité m'intéresse toujours autant, et je souhaitais m'assurer que ma candidature vous est bien parvenue "
        . "et savoir si vous aviez pu l'étudier.\n\n"
        . "Je me tiens à votre disposition pour tout renseignement complémentaire ou pour un échange.";
    return ['subject' => $subject, 'body' => $body . $signature];
}

/** Convertit un corps texte en HTML simple pour l'envoi. */
function followup_html(string $text): string
{
    $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#1f2333;white-space:pre-wrap">'
        . nl2br($safe) . '</div>';
}

/**
 * Enregistre la relance : archive l'e-mail, passe le statut à « relance »
 * et repousse la prochaine échéance.
 */
function followup_record(int $companyId, int $ownerId, string $subject, string $body): void
{
    $pdo = db();
    try {
        $pdo->prepare('INSERT INTO company_emails (company_id, owner_id, direction, subject, body, email_date)
                       VALUES (?,?,?,?,?,CURDATE())')
            ->execute([$companyId, $ownerId, 'envoye', mb_substr($subject, 0, 200), $body]);
    } catch (Throwable $e) {}
    try {
        $pdo->prepare("UPDATE companies SET status = 'relance', followup_date = CURDATE() WHERE id=? AND owner_id=?")
            ->execute([$companyId, $ownerId]);
    } catch (Throwable $e) {}
}

/**
 * Envoi automatique des relances dues pour un utilisateur, depuis son Gmail.
 * Ne s'exécute que si l'utilisateur a activé « followup_auto » ET configuré
 * un compte Gmail personnel valide. Renvoie le nombre de relances envoyées.
 *
 * Sécurités : n'envoie qu'aux candidatures avec une adresse e-mail valide,
 * respecte le plafond de relances (followup_max), et enregistre chaque envoi
 * pour éviter les doublons au passage suivant du cron.
 */
function followups_auto_send(int $userId, int $ownerId, array $me): int
{
    require_once __DIR__ . '/mailer.php';

    // Activé par l'utilisateur ?
    if (user_pref_get($userId, 'followup_auto', '0') !== '1') return 0;
    // Compte Gmail personnel prêt ?
    if (!alt_gmail_ready($userId)) return 0;

    $due = followups_due($ownerId, $userId);
    if (!$due) return 0;

    $sent = 0;
    foreach ($due as $c) {
        $to = trim((string)($c['email'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) continue;   // pas d'adresse : on saute

        $variant = ((int)($c['relances'] ?? 0) >= 1) ? 2 : 1;    // 2e relance = ton différent
        $draft = followup_draft($c, $me, $variant);
        $res = alt_send_mail(
            $to, (string)($c['contact_name'] ?? ''), $draft['subject'],
            followup_html($draft['body']), $draft['body'],
            ['account' => 'gmail', 'user_id' => $userId]
        );
        if (!empty($res['ok'])) {
            followup_record((int)$c['id'], $ownerId, $draft['subject'], $draft['body']);
            log_activity($userId, 'followup_auto_sent', 'company', (int)$c['id'], $c['name'] ?? '', $ownerId);
            $sent++;
            // on évite d'enchaîner trop d'envois d'un coup : 5 max par passage
            if ($sent >= 5) break;
        }
    }
    return $sent;
}
