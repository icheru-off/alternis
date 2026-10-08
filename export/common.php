<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/share.php';

/**
 * Lien de partage courant (?t=jeton), ou null.
 * Mis en cache : la validité n'est vérifiée qu'une fois par requête.
 */
function export_share_link(): ?array
{
    static $checked = false, $link = null;
    if ($checked) return $link;
    $checked = true;
    $t = trim((string)($_GET['t'] ?? ''));
    if ($t !== '') $link = share_lookup($t);
    return $link;
}

/** Retourne les données d'export pour l'utilisateur/propriétaire courant. */
function export_dataset(): array
{
    $pdo = db();
    $link = export_share_link();

    if ($link) {
        // Accès public en lecture seule : le propriétaire vient du lien,
        // jamais d'un paramètre d'URL.
        $owner = (int)$link['owner_id'];
    } else {
        require_login();
        $owner = data_owner_id();
    }

    $where = 'owner_id=?';
    $args = [$owner];

    if ($link) {
        // Les statuts autorisés sont ceux figés dans le lien : un visiteur
        // ne peut pas élargir le périmètre via l'URL.
        $valid = array_keys(status_labels());
        $sts = array_values(array_intersect(array_filter(explode(',', (string)$link['statuses'])), $valid));
        if ($sts) { $where .= ' AND status IN (' . implode(',', array_fill(0, count($sts), '?')) . ')'; array_push($args, ...$sts); }
    } else {
        // Filtre par sélection d'ids (?ids=1,2,3)
        $idsParam = trim((string)($_GET['ids'] ?? ''));
        if ($idsParam !== '') {
            $ids = array_values(array_filter(array_map('intval', explode(',', $idsParam))));
            if ($ids) { $where .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; array_push($args, ...$ids); }
        }
        // Filtre par statuts (?statuses=envoye,entretien)
        $stParam = trim((string)($_GET['statuses'] ?? ''));
        if ($stParam !== '') {
            $valid = array_keys(status_labels());
            $sts = array_values(array_filter(explode(',', $stParam), fn($s) => in_array($s, $valid, true)));
            if ($sts) { $where .= ' AND status IN (' . implode(',', array_fill(0, count($sts), '?')) . ')'; array_push($args, ...$sts); }
        }
    }

    $st = $pdo->prepare("SELECT * FROM companies WHERE $where ORDER BY applied_date DESC, created_at DESC");
    $st->execute($args);
    $companies = $st->fetchAll();

    // Nom du propriétaire
    $os = $pdo->prepare('SELECT full_name, username FROM users WHERE id=?');
    $os->execute([$owner]);
    $o = $os->fetch();
    $ownerName = $o ? ($o['full_name'] ?: $o['username']) : 'Utilisateur';

    // Statistiques
    $labels = status_labels();
    $byStatus = array_fill_keys(array_keys($labels), 0);
    foreach ($companies as $c) $byStatus[$c['status']]++;
    $total = count($companies);
    // Aligné sur le tableau de bord : on exclut brouillons ET « à postuler ».
    $sent = $total - ($byStatus['brouillon'] ?? 0) - ($byStatus['a_postuler'] ?? 0);
    $responded = $byStatus['repondu'] + $byStatus['entretien'] + $byStatus['accepte'] + $byStatus['refuse'];
    // Même arrondi que le tableau de bord : 1 décimale.
    $rate = $sent > 0 ? round($responded / $sent * 100, 1) : 0;

    // Croisement statut x mois (pour le tableau croisé)
    $pivot = [];
    $months = [];
    foreach ($companies as $c) {
        $d = $c['applied_date'] ?: substr($c['created_at'], 0, 10);
        $m = substr($d, 0, 7);
        $months[$m] = true;
        $pivot[$c['status']][$m] = ($pivot[$c['status']][$m] ?? 0) + 1;
    }
    ksort($months);

    return [
        'companies' => $companies,
        'ownerName' => $ownerName,
        'vocab'     => search_vocab($owner),
        'byStatus'  => $byStatus,
        'labels'    => $labels,
        'total'     => $total,
        'sent'      => $sent,
        'responded' => $responded,
        'rate'      => $rate,
        'interviews'=> $byStatus['entretien'],
        'accepted'  => $byStatus['accepte'],
        'pivot'     => $pivot,
        'months'    => array_keys($months),
    ];
}

/** Formate un mois AAAA-MM en "Mois AAAA" (français). */
function fr_month(string $ym): string
{
    $m = ['01'=>'Janvier','02'=>'Février','03'=>'Mars','04'=>'Avril','05'=>'Mai','06'=>'Juin','07'=>'Juillet','08'=>'Août','09'=>'Septembre','10'=>'Octobre','11'=>'Novembre','12'=>'Décembre'];
    $p = explode('-', $ym);
    return ($m[$p[1] ?? ''] ?? $ym) . ' ' . ($p[0] ?? '');
}

/**
 * Champs disponibles dans le rapport PDF détaillé.
 * clé => [libellé, largeur indicative (mm), type]
 * Les largeurs sont proportionnelles : elles sont redimensionnées
 * pour occuper exactement la largeur utile de la page.
 */
function pdf_fields(): array
{
    // clé => [libellé PDF (court), largeur mm, type, libellé interface]
    return [
        'name'           => ['Entreprise',    42, 'text',   'Entreprise'],
        'position'       => ['Poste',         40, 'text',   'Poste'],
        'sector'         => ['Secteur',       28, 'text',   'Secteur'],
        'city'           => ['Ville',         26, 'text',   'Ville'],
        'postal_code'    => ['Code postal',   20, 'text',   'Code postal'],
        'address'        => ['Adresse',       40, 'text',   'Adresse'],
        'contact_name'   => ['Contact',       30, 'text',   'Nom du contact'],
        'email'          => ['E-mail',        44, 'text',   'E-mail'],
        'phone'          => ['Téléphone',     26, 'text',   'Téléphone'],
        'website'        => ['Site web',      34, 'text',   'Site web'],
        'status'         => ['Statut',        24, 'status', 'Statut'],
        'priority'       => ['Priorité',      20, 'text',   'Priorité'],
        'apply_channel'  => ['Canal',         24, 'text',   'Canal de candidature'],
        'salary'         => ['Rémunération',  26, 'text',   'Rémunération'],
        // Libellés explicites : on distingue clairement la date d'envoi
        // de la candidature des autres dates du suivi.
        'applied_date'   => ['Postule le',    24, 'date',   'Date de candidature (postulé le)'],
        'response_date'  => ['Reponse le',    24, 'date',   'Date de réponse'],
        'interview_date' => ['Entretien le',  24, 'date',   "Date d'entretien"],
        'followup_date'  => ['Relance le',    24, 'date',   'Date de relance'],
        'notes'          => ['Notes',         50, 'text',   'Notes'],
    ];
}

/** Champs affichés par défaut si l'utilisateur n'en choisit aucun. */
function pdf_default_fields(): array
{
    return ['name', 'position', 'city', 'contact_name', 'email', 'phone', 'status', 'applied_date'];
}

/** Champs demandés via ?fields=a,b,c — validés contre le registre. */
function pdf_selected_fields(): array
{
    $all = pdf_fields();

    // En mode partage, les colonnes sont celles choisies par le propriétaire.
    // On ignore volontairement ?fields= : sinon un visiteur pourrait réclamer
    // les notes ou les coordonnées que le propriétaire n'a pas partagées.
    $link = function_exists('export_share_link') ? export_share_link() : null;
    if ($link) {
        $sel = array_values(array_filter(share_visible_fields($link), fn($k) => isset($all[$k])));
        return $sel ?: pdf_default_fields();
    }

    $raw = trim((string)($_GET['fields'] ?? ''));
    if ($raw === '') return pdf_default_fields();
    $sel = array_values(array_filter(array_map('trim', explode(',', $raw)), fn($k) => isset($all[$k])));
    return $sel ?: pdf_default_fields();
}
