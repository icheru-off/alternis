<?php
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../lib/fpdf/fpdf.php';

$data = export_dataset();
$scope = $_GET['scope'] ?? 'full'; // full | stats

/** Convertit l'UTF-8 vers le jeu latin utilise par FPDF. */
function T(string $s): string
{
    // Conversion UTF-8 -> Windows-1252 (cp1252) SANS //TRANSLIT, qui selon la
    // locale du serveur supprimait les accents (é -> e). On préserve les accents.
    if (function_exists('mb_convert_encoding')) {
        $r = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        if ($r !== false && $r !== '') return $r;
    }
    $r = @iconv('UTF-8', 'Windows-1252//IGNORE', $s);
    if ($r !== false) return $r;
    return function_exists('mb_convert_encoding') ? mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8') : utf8_decode($s);
}

class AlternisPDF extends FPDF
{
    public $ownerName = '';
    public $subtitle = '';

    /* --- Accesseurs publics (proprietes FPDF protegees) --- */
    public function pageW() { return $this->w; }
    public function pageH() { return $this->h; }
    public function leftMargin() { return $this->lMargin; }
    public function rightMargin() { return $this->rMargin; }
    public function breakTrigger() { return $this->PageBreakTrigger; }

    /* Nombre de lignes qu'occupera un texte dans une largeur donnee. */
    public function NbLines($w, $txt)
    {
        $cw = $this->CurrentFont['cw'];
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string)$txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if ($c == ' ') $sep = $i;
            $l += $cw[$c] ?? 600;
            if ($l > $wmax) {
                if ($sep == -1) { if ($i == $j) $i++; } else $i = $sep + 1;
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else $i++;
        }
        return $nl;
    }

    function RoundedRect($x, $y, $w, $h, $r, $style = '')
    {
        $k = $this->k; $hp = $this->h;
        $op = $style == 'F' ? 'f' : ($style == 'FD' || $style == 'DF' ? 'B' : 'S');
        $MyArc = 4 / 3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));
        $this->_Arc($xc + $r * $MyArc, $yc - $r, $xc + $r, $yc - $r * $MyArc, $xc + $r, $yc);
        $xc = $x + $w - $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->_Arc($xc + $r, $yc + $r * $MyArc, $xc + $r * $MyArc, $yc + $r, $xc, $yc + $r);
        $xc = $x + $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->_Arc($xc - $r * $MyArc, $yc + $r, $xc - $r, $yc + $r * $MyArc, $xc - $r, $yc);
        $xc = $x + $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', ($x) * $k, ($hp - $yc) * $k));
        $this->_Arc($xc - $r, $yc - $r * $MyArc, $xc - $r * $MyArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }
    function _Arc($x1, $y1, $x2, $y2, $x3, $y3)
    {
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1 * $this->k, ($h - $y1) * $this->k, $x2 * $this->k, ($h - $y2) * $this->k, $x3 * $this->k, ($h - $y3) * $this->k));
    }

    function Header()
    {
        // Bandeau aux couleurs de la marque (bleu nuit + accent cyan)
        $this->SetFillColor(BRAND_NAVY[0], BRAND_NAVY[1], BRAND_NAVY[2]);
        $this->Rect(0, 0, $this->w, 26, 'F');
        $this->SetFillColor(BRAND_CYAN[0], BRAND_CYAN[1], BRAND_CYAN[2]);
        $this->Rect(0, 26, $this->w, 1.1, 'F');   // filet cyan

        // Logo (PNG aplati : FPDF ne gere pas la transparence)
        $logo = __DIR__ . '/../assets/img/pdf-logo.png';
        if (is_file($logo)) {
            $this->Image($logo, 12, 7.5, 0, 11);
        } else {
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Helvetica', 'B', 15);
            $this->SetXY(12, 8);
            $this->Cell(60, 8, T('Alternis'), 0, 0, 'L');
        }

        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(150, 210, 230);
        $this->SetXY(12, 18.5);
        $this->Cell(120, 5, T($this->subtitle), 0, 0, 'L');

        // Mention de generation, a droite
        $this->SetXY(-110, 7.5);
        $this->SetFont('Helvetica', 'B', 8.5);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(98, 5, T($this->ownerName), 0, 2, 'R');
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(160, 168, 190);
        $this->Cell(98, 4.6, T('Généré le ' . date('d/m/Y') . ' à ' . date('H\\hi')), 0, 2, 'R');
        $this->Cell(98, 4.6, T('par Alternis'), 0, 0, 'R');

        $this->SetY(34);
        $this->SetTextColor(30, 30, 40);
    }

    function Footer()
    {
        $this->SetY(-14);
        $this->SetDrawColor(230, 232, 240);
        $this->Line($this->lMargin, $this->GetY(), $this->w - $this->rMargin, $this->GetY());
        $this->SetY(-11);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(150, 155, 170);
        $this->Cell(0, 6, T('Alternis - ' . $this->ownerName . ' - généré le ' . date('d/m/Y') . ' à ' . date('H\\hi')), 0, 0, 'L');
        $this->Cell(0, 6, T('Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'R');
    }

    function StatCard($x, $y, $w, $label, $value, $rgb)
    {
        $this->SetFillColor(248, 249, 253);
        $this->SetDrawColor(231, 233, 242);
        $this->RoundedRect($x, $y, $w, 22, 2.5, 'FD');
        $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $this->RoundedRect($x + 4, $y + 4, 3, 14, 1.5, 'F');
        $this->SetXY($x + 10, $y + 4.5);
        $this->SetTextColor(30, 32, 45);
        $this->SetFont('Helvetica', 'B', 17);
        $this->Cell($w - 12, 8, T($value), 0, 2, 'L');
        $this->SetX($x + 10);
        $this->SetFont('Helvetica', '', 8.5);
        $this->SetTextColor(120, 125, 140);
        $this->Cell($w - 12, 5, T($label), 0, 0, 'L');
    }
}

define('BRAND_NAVY', [11, 18, 32]);
define('BRAND_CYAN', [34, 211, 238]);

$statusColors = status_colors();

$pdf = new AlternisPDF($scope === 'stats' ? 'P' : 'L', 'mm', 'A4');
$pdf->ownerName = $data['ownerName'];
$__voc = $data['vocab'] ?? search_vocab();
$pdf->subtitle = "Suivi de " . $__voc['search'];

// Export depuis un lien de partage : on le signale et on compte la consultation.
$__shareLink = export_share_link();
if ($__shareLink) {
    $pdf->subtitle = "Suivi de " . $__voc['search'] . " - document partage en lecture seule";
    share_touch((int)$__shareLink['id']);
}
$pdf->AliasNbPages();
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();

$pdf->SetFont('Helvetica', 'B', 18);
$pdf->SetTextColor(23, 26, 43);
$pdf->Cell(0, 9, T($scope === 'stats' ? 'Chiffres cles' : 'Rapport complet'), 0, 1, 'L');
$pdf->SetFont('Helvetica', '', 10);
$pdf->SetTextColor(110, 115, 130);
$pdf->Cell(0, 6, T('Candidat : ' . $data['ownerName'] . '  -  ' . $data['total'] . ' candidature(s) suivie(s)'), 0, 1, 'L');
$pdf->Ln(4);

$pageW = $pdf->pageW() - 20;
$cardW = ($pageW - 3 * 5) / 4;
$y0 = $pdf->GetY();
$pdf->StatCard(10, $y0, $cardW, 'Candidatures', (string)$data['total'], [124, 92, 252]);
$pdf->StatCard(10 + ($cardW + 5), $y0, $cardW, T('Taux de réponse'), $data['rate'] . '%', [168, 85, 247]);
$pdf->StatCard(10 + 2 * ($cardW + 5), $y0, $cardW, 'Entretiens', (string)$data['interviews'], [249, 115, 22]);
$pdf->StatCard(10 + 3 * ($cardW + 5), $y0, $cardW, T($__voc['Type'] . 's obtenue' . ($data['accepted'] > 1 ? 's' : '')), (string)$data['accepted'], [34, 197, 94]);
$pdf->SetY($y0 + 22 + 8);

$pdf->SetFont('Helvetica', 'B', 12);
$pdf->SetTextColor(23, 26, 43);
$pdf->Cell(0, 7, T('Répartition par statut'), 0, 1);
$pdf->Ln(1);
foreach ($data['labels'] as $key => $label) {
    $n = $data['byStatus'][$key];
    $pct = $data['total'] ? round($n / $data['total'] * 100) : 0;
    $c = $statusColors[$key];
    $pdf->SetFont('Helvetica', '', 9.5);
    $pdf->SetTextColor(70, 75, 90);
    $pdf->Cell(38, 6, T($label), 0, 0, 'L');
    $bx = $pdf->GetX(); $by = $pdf->GetY() + 1.2;
    $barMax = ($scope === 'stats') ? 110 : 150;
    $pdf->SetFillColor(237, 239, 245);
    $pdf->RoundedRect($bx, $by, $barMax, 3.4, 1.7, 'F');
    if ($pct > 0) {
        $pdf->SetFillColor($c[0], $c[1], $c[2]);
        $pdf->RoundedRect($bx, $by, max(2, $barMax * $pct / 100), 3.4, 1.7, 'F');
    }
    $pdf->SetXY($bx + $barMax + 4, $by - 1.4);
    $pdf->SetTextColor(30, 32, 45);
    $pdf->SetFont('Helvetica', 'B', 9.5);
    $pdf->Cell(30, 6, $n . '  (' . $pct . '%)', 0, 1, 'L');
    $pdf->Ln(1.5);
}

if (!empty($data['months'])) {
    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->SetTextColor(23, 26, 43);
    $pdf->Cell(0, 7, T('Synthèse par mois'), 0, 1);
    $pdf->Ln(1);
    $months = $data['months'];
    $firstW = 34;
    $availW = $pageW - $firstW - 16;
    $colW = min(24, $availW / max(1, count($months)));
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetFillColor(244, 245, 250);
    $pdf->SetTextColor(90, 95, 110);
    $pdf->Cell($firstW, 7, T('Statut'), 1, 0, 'L', true);
    foreach ($months as $m) $pdf->Cell($colW, 7, T(mb_substr(fr_month($m), 0, 3, 'UTF-8') . ' ' . substr($m, 2, 2)), 1, 0, 'C', true);
    $pdf->Cell(16, 7, 'Tot.', 1, 1, 'C', true);
    foreach ($data['labels'] as $key => $label) {
        if ($data['byStatus'][$key] === 0) continue;
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->SetTextColor(60, 63, 78);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Cell($firstW, 6.5, '  ' . T($label), 1, 0, 'L');
        $rowTot = 0;
        foreach ($months as $m) {
            $v = $data['pivot'][$key][$m] ?? 0; $rowTot += $v;
            $pdf->Cell($colW, 6.5, $v ?: '', 1, 0, 'C');
        }
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->Cell(16, 6.5, (string)$rowTot, 1, 1, 'C');
    }
}

if ($scope === 'full' && !empty($data['companies'])) {
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->SetTextColor(23, 26, 43);
    $pdf->Cell(0, 8, T('Detail des candidatures'), 0, 1);

    if (in_array('applied_date', pdf_selected_fields(), true)) {
        $pdf->SetFont('Helvetica', 'I', 8);
        $pdf->SetTextColor(120, 125, 140);
        $pdf->Cell(0, 5, T("\"Postule le\" indique la date d'envoi de la candidature, et non la date de reponse."), 0, 1);
    }
    $pdf->Ln(2);

    // Colonnes choisies par l'utilisateur (?fields=...), sinon les colonnes par defaut
    $registry = pdf_fields();
    $keys = pdf_selected_fields();

    // Les largeurs du registre sont indicatives : on les met a l'echelle
    // pour occuper exactement la largeur utile de la page.
    $avail = $pdf->pageW() - $pdf->leftMargin() - $pdf->rightMargin();
    $raw = array_sum(array_map(fn($k) => $registry[$k][1], $keys));
    $scale = $raw > 0 ? $avail / $raw : 1;

    $cols = [];
    foreach ($keys as $k) {
        $cols[] = [$registry[$k][0], max(14, $registry[$k][1] * $scale), $k, $registry[$k][2]];
    }
    // Corrige l'arrondi pour que le tableau tombe pile
    $sum = array_sum(array_column($cols, 1));
    if ($sum > 0) { $cols[count($cols) - 1][1] += ($avail - $sum); }

    $lineH = 5;
    $tableW = array_sum(array_column($cols, 1));

    $prios = ['basse' => 'Basse', 'normale' => 'Normale', 'haute' => 'Haute'];
    $fmtDate = fn($v) => $v ? date('d/m/Y', strtotime($v)) : '-';

    $drawHead = function () use ($pdf, $cols) {
        $pdf->SetFillColor(BRAND_NAVY[0], BRAND_NAVY[1], BRAND_NAVY[2]);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($cols as $c) {
            // Reduit la police si le libelle deborde de sa colonne
            $size = 8;
            $pdf->SetFont('Helvetica', 'B', $size);
            while ($size > 5.5 && $pdf->GetStringWidth(T($c[0])) > $c[1] - 2.5) {
                $size -= 0.25;
                $pdf->SetFont('Helvetica', 'B', $size);
            }
            $pdf->Cell($c[1], 8, T($c[0]), 0, 0, 'L', true);
        }
        $pdf->Ln(8);
        // Filet cyan sous l'en-tete du tableau
        $pdf->SetFillColor(BRAND_CYAN[0], BRAND_CYAN[1], BRAND_CYAN[2]);
        $pdf->Rect($pdf->leftMargin(), $pdf->GetY(), array_sum(array_column($cols, 1)), 0.7, 'F');
        $pdf->Ln(1);
    };
    $drawHead();

    $zebra = false;
    foreach ($data['companies'] as $c) {
        $vals = [];
        foreach ($cols as $col) {
            $k = $col[2];
            if ($col[3] === 'date')            $v = $fmtDate($c[$k] ?? null);
            elseif ($k === 'status')           $v = status_label($c['status']);
            elseif ($k === 'priority')         $v = $prios[$c['priority'] ?? ''] ?? '-';
            elseif ($k === 'notes')            $v = trim((string)($c['notes'] ?? '')) ?: '-';
            else                               $v = trim((string)($c[$k] ?? '')) ?: '-';
            $vals[] = $v;
        }

        $pdf->SetFont('Helvetica', '', 8);
        $maxLines = 1;
        foreach ($vals as $i => $v) {
            $lines = $pdf->NbLines($cols[$i][1] - 2, T($v));
            if ($lines > $maxLines) $maxLines = $lines;
        }
        $maxLines = min($maxLines, 6);            // garde-fou sur les notes tres longues
        $rowH = $maxLines * $lineH + 2;

        if ($pdf->GetY() + $rowH > $pdf->breakTrigger()) {
            $pdf->AddPage();
            $drawHead();
            $zebra = false;
        }
        $x = $pdf->leftMargin(); $y = $pdf->GetY();
        if ($zebra) { $pdf->SetFillColor(248, 249, 253); $pdf->Rect($x, $y, $tableW, $rowH, 'F'); }

        foreach ($vals as $i => $v) {
            $w = $cols[$i][1];
            if ($cols[$i][3] === 'status') {
                $col = $statusColors[$c['status']];
                $pdf->SetFillColor($col[0], $col[1], $col[2]);
                $pdf->RoundedRect($x + 1, $y + ($rowH - 5) / 2, $w - 3, 5, 2.2, 'F');
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Helvetica', 'B', 6.6);
                $pdf->SetXY($x, $y + ($rowH - 5) / 2);
                $pdf->Cell($w, 5, T($v), 0, 0, 'C');
            } else {
                $pdf->SetTextColor(45, 48, 62);
                $pdf->SetFont('Helvetica', $cols[$i][2] === 'name' ? 'B' : '', 8);
                $pdf->SetXY($x + 1, $y + 1);
                $pdf->MultiCell($w - 2, $lineH, T($v), 0, 'L');
            }
            $x += $w;
        }
        $pdf->SetDrawColor(235, 237, 244);
        $pdf->Line($pdf->leftMargin(), $y + $rowH, $pdf->leftMargin() + $tableW, $y + $rowH);
        $pdf->SetXY($pdf->leftMargin(), $y + $rowH);
        $zebra = !$zebra;
    }
}

$fn = 'Alternis_' . ($scope === 'stats' ? 'chiffres' : 'rapport') . '_' . safe_filename($data['ownerName']) . '_' . date('Ymd') . '.pdf';
if ($__shareLink) $fn = 'Alternis_suivi_' . safe_filename($data['ownerName']) . '_' . date('Ymd') . '.pdf';
$pdf->Output('I', $fn);
