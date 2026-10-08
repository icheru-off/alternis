<?php
/**
 * Alternis — Complétion : recherche d'entreprise par nom (annuaire officiel)
 * et autocomplétion de commune. Sources gratuites data.gouv, sans clé.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();
$action = $_GET['action'] ?? '';

function http_json(string $url, int $timeout = 12): ?array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Alternis/1.4',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code >= 400) return null;
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true, 'header' => 'Accept: application/json']]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

if ($action === 'company') {
    $q = trim((string)($_GET['q'] ?? (json_in()['q'] ?? '')));
    if (mb_strlen($q) < 2) json_out(['items' => []]);
    $url = 'https://recherche-entreprises.api.gouv.fr/search?' . http_build_query([
        'q' => $q, 'per_page' => 5, 'page' => 1, 'etat_administratif' => 'A',
    ]);
    $res = http_json($url);
    $items = [];
    if (is_array($res) && !empty($res['results'])) {
        foreach ($res['results'] as $r) {
            $s = $r['siege'] ?? [];
            $name = $r['nom_complet'] ?? ($r['nom_raison_sociale'] ?? '');
            if ($name === '') continue;
            $items[] = [
                'name'    => $name,
                'sector'  => $r['libelle_activite_principale'] ?? ($r['section_activite_principale'] ?? ''),
                'address' => $s['adresse'] ?? ($s['geo_adresse'] ?? ''),
                'city'    => $s['libelle_commune'] ?? '',
                'postal'  => $s['code_postal'] ?? '',
                'siren'   => $r['siren'] ?? '',
            ];
        }
    }
    json_out(['items' => $items]);
}

if ($action === 'cities') {
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) json_out(['items' => []]);
    $isCp = preg_match('/^\d{4,5}$/', $q);
    // `centre` fournit les coordonnées, nécessaires à La Bonne Alternance
    $url = 'https://geo.api.gouv.fr/communes?' . ($isCp ? 'codePostal=' . $q : 'nom=' . rawurlencode($q))
         . '&fields=nom,code,codesPostaux,centre&format=json&limit=8&boost=population';
    $res = http_json($url, 8);
    $items = [];
    if (is_array($res)) {
        foreach ($res as $c) {
            $coords = $c['centre']['coordinates'] ?? null;   // [lon, lat]
            $items[] = [
                'nom' => $c['nom'] ?? '',
                'cp' => $c['codesPostaux'][0] ?? '',
                'insee' => $c['code'] ?? '',
                'lon' => is_array($coords) ? ($coords[0] ?? null) : null,
                'lat' => is_array($coords) ? ($coords[1] ?? null) : null,
            ];
        }
    }
    json_out(['items' => $items]);
}

json_out(['error' => 'Action inconnue.'], 400);
