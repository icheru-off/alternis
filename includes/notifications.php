<?php
require_once __DIR__ . '/functions.php';

/**
 * Génère au plus NOTIF_MAX_PER_DAY notifications par utilisateur et par jour,
 * espacées d'au moins ~3h, en s'appuyant sur l'état réel des candidatures.
 * Retourne le nombre de notifications créées.
 */
function generate_notifications(int $userId, int $ownerId, string $displayName): int
{
    $pdo = db();
    $today = date('Y-m-d');

    // --- Throttle : combien aujourd'hui, et depuis quand ? ---
    $st = $pdo->prepare('SELECT count, last_generated FROM notif_meta WHERE user_id = ? AND day = ?');
    $st->execute([$userId, $today]);
    $meta = $st->fetch();
    $count = $meta ? (int)$meta['count'] : 0;
    $last  = $meta && $meta['last_generated'] ? strtotime($meta['last_generated']) : 0;

    if ($count >= NOTIF_MAX_PER_DAY) {
        return 0;
    }
    // Au moins 3 heures entre deux salves
    if ($last && (time() - $last) < 3 * 3600) {
        return 0;
    }

    $candidates = build_recommendations($ownerId, $displayName);
    if (!$candidates) {
        return 0;
    }

    // On regroupe : une seule salve, la reco la plus pertinente non déjà envoyée aujourd'hui.
    $created = 0;
    foreach ($candidates as $c) {
        // Évite les doublons du même group_key sur les 20 dernières heures
        $chk = $pdo->prepare(
            'SELECT 1 FROM notifications WHERE user_id = ? AND group_key = ?
             AND created_at > (NOW() - INTERVAL 20 HOUR) LIMIT 1'
        );
        $chk->execute([$userId, $c['group_key']]);
        if ($chk->fetch()) {
            continue;
        }
        $ins = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, body, url, group_key)
             VALUES (?,?,?,?,?,?)'
        );
        $ins->execute([$userId, $c['type'], $c['title'], $c['body'], $c['url'], $c['group_key']]);
        $created = 1;
        break; // une salve = une notification (anti-spam)
    }

    if ($created) {
        if ($meta) {
            $pdo->prepare('UPDATE notif_meta SET count = count + 1, last_generated = NOW() WHERE user_id = ? AND day = ?')
                ->execute([$userId, $today]);
        } else {
            $pdo->prepare('INSERT INTO notif_meta (user_id, day, count, last_generated) VALUES (?,?,1,NOW())')
                ->execute([$userId, $today]);
        }
    }
    return $created;
}

/**
 * Enregistre l'ajout d'une ou plusieurs entreprises dans la file d'attente,
 * pour un envoi groupé différé (évite le spam en cas d'ajouts rapprochés).
 */
function ensure_company_queue_table(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS `company_add_queue` (
            `owner_id`   INT UNSIGNED NOT NULL,
            `count`      INT UNSIGNED NOT NULL DEFAULT 0,
            `actor_id`   INT UNSIGNED DEFAULT NULL,
            `actor_name` VARCHAR(120) NOT NULL DEFAULT "",
            `first_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`owner_id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function queue_company_add(int $ownerId, int $actorId, string $actorName, int $n = 1): void
{
    $pdo = db();
    $sql = 'INSERT INTO company_add_queue (owner_id, count, actor_id, actor_name, first_at, last_at)
            VALUES (?,?,?,?,NOW(),NOW())
            ON DUPLICATE KEY UPDATE count = count + VALUES(count), actor_id = VALUES(actor_id),
                                    actor_name = VALUES(actor_name), last_at = NOW()';
    $args = [$ownerId, max(1, $n), $actorId, mb_substr($actorName, 0, 120)];
    try {
        $pdo->prepare($sql)->execute($args);
    } catch (Throwable $e) {
        // Table absente : on la crée à la volée puis on réessaie (pas besoin d'upgrade.php).
        try { ensure_company_queue_table(); $pdo->prepare($sql)->execute($args); }
        catch (Throwable $e2) { /* on n'interrompt jamais l'ajout d'entreprise */ }
    }
}

/**
 * Envoie les notifications groupées pour les lots d'ajouts d'entreprises
 * dont la « période de calme » est écoulée (plus d'ajout récent), ou qui
 * attendent depuis trop longtemps. Une seule notification par lot, adressée
 * à toutes les personnes liées au suivi (propriétaire + parents), sauf l'auteur.
 */
function flush_company_add_notifications(): int
{
    $pdo = db();
    $quiet = defined('NOTIF_BATCH_QUIET_MIN') ? (int)NOTIF_BATCH_QUIET_MIN : 1;
    $max   = defined('NOTIF_BATCH_MAX_MIN') ? (int)NOTIF_BATCH_MAX_MIN : 15;

    try {
        // Lots prêts : aucun ajout depuis $quiet min, OU en attente depuis $max min.
        // (les quantités sont des entiers castés, sans risque d'injection)
        $sel = $pdo->query(
            "SELECT owner_id, count, actor_id, actor_name, last_at
             FROM company_add_queue
             WHERE last_at <= (NOW() - INTERVAL $quiet MINUTE) OR first_at <= (NOW() - INTERVAL $max MINUTE)"
        );
        $ready = $sel->fetchAll();
    } catch (Throwable $e) {
        return 0; // table absente : rien à faire
    }
    if (!$ready) return 0;

    $sent = 0;
    foreach ($ready as $row) {
        $owner = (int)$row['owner_id'];
        // « Réclame » le lot : seul le processus qui supprime réellement la ligne l'envoie
        // (évite les doublons si deux requêtes s'exécutent en même temps).
        $del = $pdo->prepare('DELETE FROM company_add_queue WHERE owner_id = ? AND last_at = ?');
        $del->execute([$owner, $row['last_at']]);
        if ($del->rowCount() < 1) {
            continue; // déjà traité par une autre requête
        }

        $count = max(1, (int)$row['count']);
        $actorId = (int)$row['actor_id'];
        $actor = $row['actor_name'] !== '' ? $row['actor_name'] : 'Quelqu\'un';

        // Destinataires : propriétaire du suivi + parents liés + l'auteur (pour confirmation).
        $rec = $pdo->prepare(
            'SELECT id FROM users
             WHERE (id = ? OR linked_student_id = ? OR id = ?) AND is_active = 1'
        );
        $rec->execute([$owner, $owner, $actorId]);
        $recipients = $rec->fetchAll(PDO::FETCH_COLUMN);
        if (!$recipients) continue;

        $plural = $count > 1 ? 's' : '';
        $gkey  = 'company_add_' . $owner . '_' . strtotime($row['last_at']);

        $ins = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, body, url, group_key)
             VALUES (?,?,?,?,?,?)'
        );
        foreach ($recipients as $rid) {
            if ((int)$rid === $actorId) {
                $title = $count > 1 ? 'Entreprises ajoutées' : 'Entreprise ajoutée';
                $body  = "Vous avez ajouté $count entreprise$plural au suivi.";
            } else {
                $title = $count > 1 ? "$count nouvelles entreprises" : 'Nouvelle entreprise';
                $body  = "$actor a ajouté $count entreprise$plural au suivi.";
            }
            $ins->execute([(int)$rid, 'ajout', $title, $body, 'index.php?page=companies', $gkey]);
            $sent++;
        }
    }
    return $sent;
}

/**
 * Construit une liste ordonnée de recommandations pertinentes,
 * variées, personnalisées avec le prénom.
 */
function build_recommendations(int $ownerId, string $name): array
{
    $pdo = db();
    $recs = [];

    // Statistiques de base
    $total = (int)$pdo->query('SELECT COUNT(*) FROM companies WHERE owner_id = ' . (int)$ownerId)->fetchColumn();

    $by = [];
    $q = $pdo->prepare('SELECT status, COUNT(*) c FROM companies WHERE owner_id = ? GROUP BY status');
    $q->execute([$ownerId]);
    foreach ($q as $r) {
        $by[$r['status']] = (int)$r['c'];
    }

    // 1) Relances à faire : envoyées il y a +7j, sans réponse
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM companies WHERE owner_id = ? AND status = 'envoye'
         AND applied_date IS NOT NULL AND applied_date < (CURDATE() - INTERVAL 7 DAY)"
    );
    $st->execute([$ownerId]);
    $toFollow = (int)$st->fetchColumn();
    if ($toFollow > 0) {
        $recs[] = [
            'type' => 'relance',
            'group_key' => 'relance',
            'title' => "$name, pensez à relancer",
            'body' => "$toFollow candidature" . ($toFollow > 1 ? 's' : '') . " sans réponse depuis plus d'une semaine. Une relance peut faire la différence.",
            'url' => 'index.php?page=companies&filter=relance',
        ];
    }

    // 2) Entretiens à venir dans les 7 jours
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM companies WHERE owner_id = ? AND interview_date IS NOT NULL
         AND interview_date BETWEEN CURDATE() AND (CURDATE() + INTERVAL 7 DAY)"
    );
    $st->execute([$ownerId]);
    $upcoming = (int)$st->fetchColumn();
    if ($upcoming > 0) {
        $recs[] = [
            'type' => 'entretien',
            'group_key' => 'entretien',
            'title' => "$name, entretien en approche",
            'body' => "$upcoming entretien" . ($upcoming > 1 ? 's programmés' : ' programmé') . " cette semaine. Préparez vos questions et relisez la fiche entreprise.",
            'url' => 'index.php?page=companies&filter=entretien',
        ];
    }

    // 3) Suivis (followup_date) échus
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM companies WHERE owner_id = ? AND followup_date IS NOT NULL
         AND followup_date <= CURDATE() AND status NOT IN ('accepte','refuse')"
    );
    $st->execute([$ownerId]);
    $due = (int)$st->fetchColumn();
    if ($due > 0) {
        $recs[] = [
            'type' => 'suivi',
            'group_key' => 'suivi',
            'title' => "$name, des suivis vous attendent",
            'body' => "$due action" . ($due > 1 ? 's de suivi sont' : ' de suivi est') . " arrivée" . ($due > 1 ? 's' : '') . " à échéance. Mettez à jour le statut correspondant.",
            'url' => 'index.php?page=companies',
        ];
    }

    // 4) Volume faible : encourager à ajouter des entreprises
    if ($total < 10) {
        $need = max(5, 10 - $total);
        $recs[] = [
            'type' => 'objectif',
            'group_key' => 'volume',
            'title' => "$name, élargissez vos cibles",
            'body' => "Ajoutez $need nouvelles entreprises pour multiplier vos chances de décrocher " . search_vocab($ownerId)['article'] . ".",
            'url' => 'index.php?page=companies&action=new',
        ];
    }

    // 5) Brouillons non envoyés
    if (!empty($by['brouillon'])) {
        $n = $by['brouillon'];
        $recs[] = [
            'type' => 'brouillon',
            'group_key' => 'brouillon',
            'title' => "$name, finalisez vos brouillons",
            'body' => "$n candidature" . ($n > 1 ? 's sont' : ' est') . " en brouillon. Complétez et passez-les au statut « Envoyé ».",
            'url' => 'index.php?page=companies&filter=brouillon',
        ];
    }

    // 6) Données incomplètes (email ou téléphone manquant)
    $st = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE owner_id = ? AND (email = '' OR phone = '')");
    $st->execute([$ownerId]);
    $incomplete = (int)$st->fetchColumn();
    if ($incomplete > 2) {
        $recs[] = [
            'type' => 'qualite',
            'group_key' => 'incomplet',
            'title' => "$name, complétez vos fiches",
            'body' => "$incomplete fiches n'ont pas d'email ou de téléphone. Des coordonnées complètes facilitent les relances.",
            'url' => 'index.php?page=companies',
        ];
    }

    // 7) Encouragement positif si des réponses arrivent
    if (!empty($by['repondu']) || !empty($by['entretien'])) {
        $good = ($by['repondu'] ?? 0) + ($by['entretien'] ?? 0);
        $recs[] = [
            'type' => 'positif',
            'group_key' => 'momentum',
            'title' => "$name, ça avance !",
            'body' => "$good entreprise" . ($good > 1 ? 's ont' : ' a') . " donné suite. Gardez le rythme et continuez à postuler.",
            'url' => 'index.php?page=dashboard',
        ];
    }

    // Mélange léger pour varier l'ordre entre deux salves
    usort($recs, function () {
        return random_int(-1, 1);
    });

    return $recs;
}

/** Nombre de notifications non lues. */
function unread_count(int $userId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}
