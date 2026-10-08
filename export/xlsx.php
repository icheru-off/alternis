<?php
/**
 * Export Excel (.xlsx) — writer pur PHP, sans dépendance.
 * Un fichier .xlsx est une archive ZIP de fichiers XML.
 *
 * Deux feuilles :
 *   1. « Données »  : toutes les candidatures.
 *   2. « Synthèse » : tableau croisé Statut × Mois (pré-calculé côté serveur,
 *                     robuste et non corruptible, contrairement à un vrai
 *                     PivotTable natif dont le cache XML se corrompt facilement).
 */
require_once __DIR__ . '/../includes/auth.php';
$user  = require_login();
$owner = data_owner_id();

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    die("L'extension PHP « zip » est requise pour l'export Excel.");
}

$st = db()->prepare('SELECT * FROM companies WHERE owner_id=? ORDER BY updated_at DESC');
$st->execute([$owner]);
$rows   = $st->fetchAll();
$labels = status_labels();
$prio   = ['basse' => 'Basse', 'normale' => 'Normale', 'haute' => 'Haute'];

/* ---------------------------------------------------------------- Helpers */

function xl_esc(string $s): string
{
    return str_replace(
        ['&', '<', '>', '"', "'"],
        ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
        $s
    );
}

/** Référence de colonne : 0 => A, 25 => Z, 26 => AA … */
function xl_col(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - $m, 26);
    }
    return $s;
}

/**
 * Construit le XML d'une feuille.
 * $data : tableau de lignes ; chaque cellule = ['v'=>valeur,'t'=>'s'|'n','s'=>styleId].
 */
function xl_sheet(array $data, array $colWidths = []): string
{
    $cols = '';
    if ($colWidths) {
        $cols .= '<cols>';
        foreach ($colWidths as $idx => $w) {
            $c = $idx + 1;
            $cols .= '<col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"/>';
        }
        $cols .= '</cols>';
    }

    $sheetData = '';
    foreach ($data as $rNum => $row) {
        $r = $rNum + 1;
        $sheetData .= '<row r="' . $r . '">';
        foreach ($row as $cNum => $cell) {
            if ($cell === null || $cell === '' || (isset($cell['v']) && $cell['v'] === '')) {
                // cellule vide : on peut la sauter, mais on garde le style si présent
                if (empty($cell['s'])) {
                    continue;
                }
            }
            $ref   = xl_col($cNum) . $r;
            $type  = $cell['t'] ?? 's';
            $style = isset($cell['s']) ? ' s="' . $cell['s'] . '"' : '';
            $val   = $cell['v'] ?? '';
            if ($type === 'n') {
                $sheetData .= '<c r="' . $ref . '"' . $style . '><v>' . xl_esc((string)$val) . '</v></c>';
            } else {
                $sheetData .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
                    . xl_esc((string)$val) . '</t></is></c>';
            }
        }
        $sheetData .= '</row>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . $cols
        . '<sheetData>' . $sheetData . '</sheetData>'
        . '</worksheet>';
}

/* ------------------------------------------------------ Feuille « Données » */

$headers = [
    'Entreprise', 'Secteur', 'Poste', 'Contact', 'Email', 'Téléphone',
    'Adresse', 'Ville', 'Code postal', 'Site web', 'Statut', 'Type de candidature',
    'Priorité', 'Rémunération', 'Date candidature', 'Date réponse',
    'Date entretien', 'Date relance', 'Notes',
];

$sheet1 = [];
// Ligne d'en-tête (style 1 = en-tête)
$sheet1[] = array_map(fn($h) => ['v' => $h, 't' => 's', 's' => 1], $headers);

foreach ($rows as $r) {
    $sheet1[] = [
        ['v' => $r['name'], 't' => 's'],
        ['v' => $r['sector'], 't' => 's'],
        ['v' => $r['position'], 't' => 's'],
        ['v' => $r['contact_name'], 't' => 's'],
        ['v' => $r['email'], 't' => 's'],
        ['v' => $r['phone'], 't' => 's'],
        ['v' => $r['address'], 't' => 's'],
        ['v' => $r['city'], 't' => 's'],
        ['v' => $r['postal_code'], 't' => 's'],
        ['v' => $r['website'], 't' => 's'],
        ['v' => $labels[$r['status']] ?? $r['status'], 't' => 's'],
        ['v' => $r['apply_channel'] ?? '', 't' => 's'],
        ['v' => $prio[$r['priority']] ?? $r['priority'], 't' => 's'],
        ['v' => $r['salary'], 't' => 's'],
        ['v' => $r['applied_date'] ?? '', 't' => 's'],
        ['v' => $r['response_date'] ?? '', 't' => 's'],
        ['v' => $r['interview_date'] ?? '', 't' => 's'],
        ['v' => $r['followup_date'] ?? '', 't' => 's'],
        ['v' => preg_replace('/\s+/', ' ', (string)$r['notes']), 't' => 's'],
    ];
}
$widths1 = [26, 16, 22, 18, 26, 15, 26, 16, 11, 24, 13, 14, 11, 14, 15, 14, 15, 14, 40];

/* ----------------------------------------------------- Feuille « Synthèse » */
/* Tableau croisé : lignes = statuts, colonnes = 6 derniers mois + Total.     */

$moisFr = ['01'=>'Janv','02'=>'Févr','03'=>'Mars','04'=>'Avr','05'=>'Mai','06'=>'Juin',
           '07'=>'Juil','08'=>'Août','09'=>'Sept','10'=>'Oct','11'=>'Nov','12'=>'Déc'];
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-$i month"));
    $months[$key] = 0;
}
// matrice statut => [mois => count]
$matrix = [];
foreach (array_keys($labels) as $sK) {
    $matrix[$sK] = array_fill_keys(array_keys($months), 0);
}
foreach ($rows as $r) {
    $d = $r['applied_date'] ?: substr($r['created_at'], 0, 10);
    $mk = substr((string)$d, 0, 7);
    if (isset($months[$mk]) && isset($matrix[$r['status']])) {
        $matrix[$r['status']][$mk]++;
    }
}

$sheet2 = [];
// Titre
$sheet2[] = [['v' => 'Synthèse croisée — Statut × Mois (6 derniers mois)', 't' => 's', 's' => 3]];
$sheet2[] = []; // ligne vide

// En-tête colonnes
$head = [['v' => 'Statut', 't' => 's', 's' => 1]];
foreach (array_keys($months) as $mk) {
    [$y, $m] = explode('-', $mk);
    $head[] = ['v' => $moisFr[$m] . ' ' . substr($y, 2), 't' => 's', 's' => 1];
}
$head[] = ['v' => 'Total', 't' => 's', 's' => 1];
$sheet2[] = $head;

// Lignes de données
$colTotals = array_fill_keys(array_keys($months), 0);
$grand = 0;
foreach ($labels as $sK => $sLabel) {
    $line = [['v' => $sLabel, 't' => 's', 's' => 2]];
    $rowTotal = 0;
    foreach (array_keys($months) as $mk) {
        $n = $matrix[$sK][$mk];
        $line[] = ['v' => $n, 't' => 'n'];
        $rowTotal += $n;
        $colTotals[$mk] += $n;
    }
    $line[] = ['v' => $rowTotal, 't' => 'n', 's' => 4];
    $grand += $rowTotal;
    $sheet2[] = $line;
}
// Ligne totaux
$totLine = [['v' => 'Total', 't' => 's', 's' => 4]];
foreach (array_keys($months) as $mk) {
    $totLine[] = ['v' => $colTotals[$mk], 't' => 'n', 's' => 4];
}
$totLine[] = ['v' => $grand, 't' => 'n', 's' => 4];
$sheet2[] = $totLine;
$widths2 = array_merge([16], array_fill(0, count($months), 10), [10]);

/* ------------------------------------------------------------- styles.xml  */
/* 0 = normal, 1 = en-tête (gras/fond violet/blanc), 2 = libellé gras,        */
/* 3 = titre, 4 = total (gras + fond clair)                                   */
$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<fonts count="4">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="14"/><color rgb="FF7C5CFC"/><name val="Calibri"/></font>'
    . '</fonts>'
    . '<fills count="4">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF7C5CFC"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFEEEBFF"/><bgColor indexed="64"/></patternFill></fill>'
    . '</fills>'
    . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="5">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
    . '</cellXfs>'
    . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
    . '</styleSheet>';

/* ------------------------------------------------------- fichiers du paquet */

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . '</Types>';

$relsRoot = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>';

$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
    . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets>'
    . '<sheet name="Données" sheetId="1" r:id="rId1"/>'
    . '<sheet name="Synthèse" sheetId="2" r:id="rId2"/>'
    . '</sheets></workbook>';

$wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
    . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '</Relationships>';

/* ------------------------------------------------------------- assemblage   */

$tmp = tempnam(sys_get_temp_dir(), 'xlsx');
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $relsRoot);
$zip->addFromString('xl/workbook.xml', $workbook);
$zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
$zip->addFromString('xl/styles.xml', $styles);
$zip->addFromString('xl/worksheets/sheet1.xml', xl_sheet($sheet1, $widths1));
$zip->addFromString('xl/worksheets/sheet2.xml', xl_sheet($sheet2, $widths2));
$zip->close();

$fname = 'alternis_candidatures_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);
log_activity((int)$user['id'], 'export', 'xlsx', null, count($rows) . ' lignes', $owner);
exit;
