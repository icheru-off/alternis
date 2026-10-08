<?php
/**
 * Alternis — Vue publique en lecture seule d'un suivi de candidatures.
 *
 * Accessible sans compte, via un jeton imprévisible. Aucune action possible.
 * Volontairement placée hors du routeur (index.php) : ni require_login(),
 * ni mode maintenance, ni service worker.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/share.php';

header('X-Robots-Tag: noindex, nofollow, noarchive');   // jamais indexé
header('Referrer-Policy: no-referrer');

$token = (string)($_GET['t'] ?? '');
$link = share_lookup($token);

if (!$link) {
    http_response_code(404);
    $reason = "Ce lien de partage n'est plus valide. Il a peut-être expiré ou été révoqué par son auteur.";
} else {
    share_touch((int)$link['id']);
    $companies = share_companies($link);
    $fields = share_visible_fields($link);
    $registry = pdf_fields_for_share();

    // Nom du propriétaire, pour l'en-tête
    $ownerName = '';
    try {
        $st = db()->prepare('SELECT full_name, username FROM users WHERE id=?');
        $st->execute([(int)$link['owner_id']]);
        if ($u = $st->fetch()) $ownerName = $u['full_name'] ?: $u['username'];
    } catch (Throwable $e) {}

    $labels = status_labels();
    $colors = status_colors();

    // Largeurs en pourcentages, normalisées pour totaliser exactement 100 %
    // (les arrondis successifs feraient sinon déborder le tableau).
    $weightTotal = 0;
    foreach ($fields as $f) $weightTotal += share_col_weight($f);
    if ($weightTotal <= 0) $weightTotal = 1;
    $colWidths = [];
    $acc = 0;
    foreach ($fields as $i => $f) {
        if ($i === count($fields) - 1) {
            $colWidths[$f] = max(1, 100 - $acc);       // la dernière absorbe l'arrondi
        } else {
            $w = (int)round(share_col_weight($f) / $weightTotal * 100);
            $w = max(1, $w);
            $colWidths[$f] = $w;
            $acc += $w;
        }
    }

    // Quelques chiffres de synthèse
    $total = count($companies);
    $counts = [];
    foreach ($companies as $c) $counts[$c['status']] = ($counts[$c['status']] ?? 0) + 1;
    $interviews = $counts['entretien'] ?? 0;
    $responses = ($counts['repondu'] ?? 0) + ($counts['entretien'] ?? 0) + ($counts['accepte'] ?? 0) + ($counts['refuse'] ?? 0);
}

$fmt = function ($v) { return $v ? date('d/m/Y', strtotime($v)) : '—'; };
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $link ? 'Suivi de candidatures' . ($ownerName ? ' — ' . e($ownerName) : '') : 'Lien indisponible' ?></title>
<link rel="icon" href="assets/img/favicon-32.png" sizes="32x32" type="image/png">
<link rel="stylesheet" href="assets/css/style.css?v=<?= e(ASSET_VERSION) ?>">
<style>
  .sh-wrap{max-width:1280px;margin:0 auto;padding:28px 20px 60px}
  .sh-top{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:6px}
  .sh-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}

  /* Largeurs fixes : le tableau tient dans la page, sans defilement horizontal */
  .sh-table{table-layout:fixed;width:100%}
  .sh-table th,.sh-table td{padding:12px 10px;word-break:break-word;overflow-wrap:anywhere}
  /* Les en-tetes heritent de white-space:nowrap : on les autorise a passer a la ligne */
  .sh-table thead th{white-space:normal;line-height:1.35;vertical-align:bottom}
  .sh-table td{vertical-align:middle}
  .sh-table .cell-co{display:flex;align-items:center;gap:10px;min-width:0}
  .sh-table .cell-main{font-weight:640;min-width:0}
  .sh-table .badge{font-size:11.5px;padding:4px 8px;white-space:normal;text-align:center}
  /* Filet de securite : si l'ecran est vraiment trop etroit, le tableau
     defile a l'interieur de sa carte, jamais la page entiere. */
  .table-wrap{overflow-x:auto}
  .sh-logo{height:34px;width:auto}
  .sh-ro{display:inline-flex;align-items:center;gap:6px;background:var(--grad-soft);color:var(--accent);
    padding:6px 12px;border-radius:999px;font-size:12.5px;font-weight:650}
  .sh-title{font-size:24px;letter-spacing:-.02em;margin:18px 0 4px}
  .sh-sub{color:var(--muted);font-size:14px;margin-bottom:22px}
  .sh-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:22px}
  .sh-stat{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:14px 16px}
  .sh-stat b{display:block;font-size:22px;letter-spacing:-.02em}
  .sh-stat span{font-size:12.5px;color:var(--muted)}
  .sh-foot{margin-top:26px;text-align:center;color:var(--faint);font-size:12.5px;line-height:1.7}
  .sh-empty{padding:50px;text-align:center;color:var(--muted)}
  @media (max-width:620px){ .sh-stats{grid-template-columns:1fr} }

  /* Avec beaucoup de colonnes, un tableau devient illisible sous 1024 px :
     on empile chaque candidature sous forme de fiche. */
  @media (max-width:1024px){
    .sh-table, .sh-table tbody{display:block;width:100%}
    .sh-table thead{display:none}
    .sh-table colgroup{display:none}
    .sh-table tr{display:block;border:1px solid var(--border);border-radius:14px;
      padding:12px 14px;margin-bottom:10px;background:var(--surface)}
    .sh-table td{display:flex;justify-content:space-between;align-items:center;gap:14px;
      border:0;padding:7px 0;text-align:right}
    .sh-table td::before{content:attr(data-label);font-size:12.5px;color:var(--muted);
      font-weight:600;text-align:left;flex:0 0 auto}
    .sh-table td[data-label="Entreprise"]{border-bottom:1px solid var(--border);
      padding-bottom:10px;margin-bottom:4px;justify-content:flex-start;text-align:left}
    .sh-table td[data-label="Entreprise"]::before{display:none}
    .sh-table .badge{white-space:nowrap}
    .table-wrap{overflow-x:visible}
    .card{background:transparent;border:0;box-shadow:none;padding:0}
  }
</style>
</head>
<body>
<div class="sh-wrap">

<?php if (!$link): ?>
  <div style="min-height:70vh;display:grid;place-items:center;text-align:center">
    <div style="max-width:420px">
      <div style="width:66px;height:66px;margin:0 auto 18px;border-radius:18px;display:grid;place-items:center;background:var(--grad);color:#fff">
        <svg viewBox="0 0 24 24" width="30" height="30" fill="none"><path d="M18 8h-1V6a5 5 0 0 0-10 0v2H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2zM9 6a3 3 0 0 1 6 0v2H9z" stroke="#fff" stroke-width="1.6" stroke-linejoin="round"/></svg>
      </div>
      <h1 style="font-size:22px;margin:0 0 10px">Lien indisponible</h1>
      <p class="muted" style="line-height:1.6"><?= e($reason) ?></p>
    </div>
  </div>
<?php else: ?>

  <div class="sh-top">
    <img class="sh-logo" src="assets/img/logo-dark.png" alt="Alternis">
    <div class="sh-actions">
      <a class="btn btn-primary btn-sm" href="export/pdf.php?scope=full&amp;t=<?= e($token) ?>" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none"><path d="M12 3v12m0 0-4-4m4 4 4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Télécharger en PDF
      </a>
      <span class="sh-ro">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z" stroke="currentColor" stroke-width="1.9"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.9"/></svg>
        Lecture seule
      </span>
    </div>
  </div>

  <h1 class="sh-title">Suivi de recherche d'alternance<?= $ownerName ? ' — ' . e($ownerName) : '' ?></h1>
  <p class="sh-sub">
    <?php if ($link['label']): ?><?= e($link['label']) ?> · <?php endif; ?>
    Document consulté le <?= e(date('d/m/Y \à H\hi')) ?>
    <?php if (!empty($link['expires_at'])): ?> · Lien valable jusqu'au <?= e(date('d/m/Y', strtotime($link['expires_at']))) ?><?php endif; ?>
  </p>

  <div class="sh-stats">
    <div class="sh-stat"><b><?= (int)$total ?></b><span>candidature<?= $total > 1 ? 's' : '' ?> partagée<?= $total > 1 ? 's' : '' ?></span></div>
    <div class="sh-stat"><b><?= (int)$responses ?></b><span>réponse<?= $responses > 1 ? 's' : '' ?> reçue<?= $responses > 1 ? 's' : '' ?></span></div>
    <div class="sh-stat"><b><?= (int)$interviews ?></b><span>entretien<?= $interviews > 1 ? 's' : '' ?></span></div>
  </div>

  <div class="card">
    <?php if (!$companies): ?>
      <div class="sh-empty">Aucune candidature à afficher pour ce partage.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data sh-table">
        <colgroup>
          <?php foreach ($fields as $f): ?><col style="width:<?= (int)$colWidths[$f] ?>%"><?php endforeach; ?>
        </colgroup>
        <thead><tr>
          <?php foreach ($fields as $f): ?><th><?= e($registry[$f] ?? $f) ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($companies as $c): ?>
          <tr>
            <?php foreach ($fields as $f): ?>
              <?php if ($f === 'status'): $col = $colors[$c['status']] ?? [148,163,184]; ?>
                <td data-label="Statut"><span class="badge" style="background:rgba(<?= implode(',', $col) ?>,.12);color:rgb(<?= implode(',', $col) ?>)"><span class="dot"></span><?= e($labels[$c['status']] ?? $c['status']) ?></span></td>
              <?php elseif (in_array($f, ['applied_date','response_date','interview_date'], true)): ?>
                <td data-label="<?= e($registry[$f]) ?>" class="sh-date"><?= e($fmt($c[$f])) ?></td>
              <?php elseif ($f === 'name'): ?>
                <td data-label="Entreprise">
                  <div class="cell-co">
                    <?= share_logo_html($c['name'], $c['website'] ?? '') ?>
                    <div class="cell-main"><?= e($c['name']) ?></div>
                  </div>
                </td>
              <?php else: ?>
                <td data-label="<?= e($registry[$f] ?? $f) ?>"><?= e(trim((string)($c[$f] ?? '')) ?: '—') ?></td>
              <?php endif; ?>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <p class="sh-foot">
    Vue en lecture seule générée par Alternis. Aucune modification n'est possible depuis cette page.<br>
    Le propriétaire de ce suivi peut révoquer ce lien à tout moment.
  </p>

<?php endif; ?>
</div>
</body>
</html>
