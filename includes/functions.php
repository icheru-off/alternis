<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/user_prefs.php';
require_once __DIR__ . '/accounts.php';

/** Échappe pour l'affichage HTML. */
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** URL de base de l'application (auto-détectée si non définie). */
function base_url(): string
{
    if (defined('APP_BASE_URL') && APP_BASE_URL !== '') {
        return rtrim(APP_BASE_URL, '/');
    }
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    // Retire un éventuel sous-dossier technique (api, auth, export, pages)
    $dir = preg_replace('#/(api|auth|export|pages)$#', '', $dir);
    return rtrim($dir, '/');
}

/** Construit une URL absolue relative à la racine de l'app. */
function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

/** Redirige puis stoppe. */
function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/** Réponse JSON standard. */
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Récupère un corps JSON POST. */
function json_in(): array
{
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/** Génère / vérifie un jeton CSRF. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_check(?string $token): bool
{
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

/** Journalise une action (sert aux recommandations). */
function log_activity(int $userId, string $action, string $entity = '', ?int $entityId = null, string $detail = '', ?int $ownerId = null): void
{
    $st = db()->prepare(
        'INSERT INTO activity_log (user_id, owner_id, action, entity, entity_id, detail)
         VALUES (?,?,?,?,?,?)'
    );
    $st->execute([$userId, $ownerId, $action, $entity, $entityId, mb_substr($detail, 0, 255)]);
}

/** Libellés lisibles des statuts. */
function status_labels(): array
{
    return [
        'a_postuler' => 'À postuler',
        'brouillon' => 'Brouillon',
        'envoye'    => 'Envoyé',
        'relance'   => 'Relancé',
        'repondu'   => 'Répondu',
        'entretien' => 'Entretien',
        'accepte'   => 'Accepté',
        'refuse'    => 'Refusé',
    ];
}

/**
 * Type de recherche de l'utilisateur courant : 'alternance' (défaut) ou 'stage'.
 * Stocké en préférence utilisateur ; modifiable à tout moment dans les réglages.
 */
function search_type(?int $userId = null): string
{
    static $cache = [];
    if ($userId === null) {
        $u = function_exists('current_user') ? current_user() : null;
        $userId = $u ? (int)$u['id'] : 0;
    }
    if ($userId <= 0) return 'alternance';
    if (isset($cache[$userId])) return $cache[$userId];
    $t = 'alternance';
    try { $t = (user_pref_get($userId, 'search_type', 'alternance') === 'stage') ? 'stage' : 'alternance'; }
    catch (Throwable $e) {}
    return $cache[$userId] = $t;
}

/**
 * Vocabulaire adapté au type de recherche. Renvoie un tableau de libellés
 * réutilisables dans toute l'interface (« votre alternance » / « votre stage »).
 */
function search_vocab(?int $userId = null): array
{
    $stage = search_type($userId) === 'stage';
    return [
        'type'      => $stage ? 'stage' : 'alternance',        // le stage / l'alternance
        'Type'      => $stage ? 'Stage' : 'Alternance',
        'search'    => $stage ? 'recherche de stage' : 'recherche d\'alternance',
        'article'   => $stage ? 'un stage' : 'une alternance',
        'the'       => $stage ? 'le stage' : 'l\'alternance',
        'your'      => $stage ? 'votre stage' : 'votre alternance',
    ];
}

/**
 * Types de candidature proposés (canal / plateforme d'où vient la candidature).
 * Liste centralisée : réutilisée dans le formulaire, l'édition groupée et les
 * filtres. « Autre… » ouvre un champ libre pour une valeur personnalisée.
 */
function apply_channels(): array
{
    return [
        'Spontanée',
        'Site de l\'entreprise',
        'La Bonne Alternance',
        'France Travail',
        'Jobteaser',
        'Indeed',
        'HelloWork',
        'LinkedIn',
        'Jooble',
        'Welcome to the Jungle',
        'Monster',
        'APEC',
        'Glassdoor',
        'Salon / forum',
        'Cooptation',
        'École / CFA',
    ];
}

/** Couleur associée à chaque statut (utilisée en CSS et PDF). */
function status_colors(): array
{
    return [
        'a_postuler' => [139, 92, 246],
        'brouillon' => [148, 163, 184],
        'envoye'    => [59, 130, 246],
        'relance'   => [234, 179, 8],
        'repondu'   => [168, 85, 247],
        'entretien' => [249, 115, 22],
        'accepte'   => [34, 197, 94],
        'refuse'    => [239, 68, 68],
    ];
}

function status_label(string $key): string
{
    $l = status_labels();
    return $l[$key] ?? $key;
}

/** Nettoie un nom de fichier. */
function safe_filename(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $name);
    return trim($name, '_');
}

/**
 * Date complète en français, ex. « lundi 6 juillet 2026 ».
 * N'utilise pas setlocale/strftime (obsolètes/non fiables selon l'hôte).
 */
function french_date(?int $ts = null): string
{
    $ts = $ts ?? time();
    $jours = ['Sunday' => 'dimanche', 'Monday' => 'lundi', 'Tuesday' => 'mardi',
              'Wednesday' => 'mercredi', 'Thursday' => 'jeudi', 'Friday' => 'vendredi', 'Saturday' => 'samedi'];
    $mois  = ['January' => 'janvier', 'February' => 'février', 'March' => 'mars', 'April' => 'avril',
              'May' => 'mai', 'June' => 'juin', 'July' => 'juillet', 'August' => 'août',
              'September' => 'septembre', 'October' => 'octobre', 'November' => 'novembre', 'December' => 'décembre'];
    $jour = $jours[date('l', $ts)] ?? date('l', $ts);
    $moisFr = $mois[date('F', $ts)] ?? date('F', $ts);
    return $jour . ' ' . (int)date('j', $ts) . ' ' . $moisFr . ' ' . date('Y', $ts);
}

// Outils d'administration (réglages, messages, notifications programmées, maintenance)
require_once __DIR__ . '/admin_tools.php';
