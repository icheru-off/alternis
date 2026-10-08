<?php
/**
 * Export CSV des candidatures.
 * UTF-8 avec BOM + séparateur point-virgule (compatible Excel FR).
 */
require_once __DIR__ . '/../includes/auth.php';
$user  = require_login();
$owner = data_owner_id();

$st = db()->prepare('SELECT * FROM companies WHERE owner_id=? ORDER BY updated_at DESC');
$st->execute([$owner]);
$rows = $st->fetchAll();

$labels = status_labels();
$prio   = ['basse' => 'Basse', 'normale' => 'Normale', 'haute' => 'Haute'];

$fname = 'alternis_candidatures_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
// BOM UTF-8 pour qu'Excel reconnaisse l'encodage
fwrite($out, "\xEF\xBB\xBF");

$headers = [
    'Entreprise', 'Secteur', 'Poste', 'Contact', 'Email', 'Téléphone',
    'Adresse', 'Ville', 'Code postal', 'Site web', 'Statut', 'Type de candidature',
    'Priorité', 'Rémunération', 'Date candidature', 'Date réponse',
    'Date entretien', 'Date relance', 'Notes',
];
fputcsv($out, $headers, ';');

foreach ($rows as $r) {
    fputcsv($out, [
        $r['name'],
        $r['sector'],
        $r['position'],
        $r['contact_name'],
        $r['email'],
        $r['phone'],
        $r['address'],
        $r['city'],
        $r['postal_code'],
        $r['website'],
        $labels[$r['status']] ?? $r['status'],
        $r['apply_channel'] ?? '',
        $prio[$r['priority']] ?? $r['priority'],
        $r['salary'],
        $r['applied_date'],
        $r['response_date'],
        $r['interview_date'],
        $r['followup_date'],
        preg_replace('/\s+/', ' ', (string)$r['notes']),
    ], ';');
}
fclose($out);
log_activity((int)$user['id'], 'export', 'csv', null, count($rows) . ' lignes', $owner);
exit;
