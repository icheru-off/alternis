<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
$__user = require_login();
$__owner = data_owner_id();
$__active = 'dashboard';
$__pageTitle = 'Tableau de bord';

$pdo = db();
// Génère d'éventuelles recommandations (throttle interne)
$fn = $__user['full_name'] ?: $__user['username'];
generate_notifications((int)$__user['id'], $__owner, preg_split('/\s+/', trim($fn))[0] ?? $fn);

// ---- Statistiques ----
$total = (int)$pdo->query('SELECT COUNT(*) FROM companies WHERE owner_id=' . $__owner)->fetchColumn();
$byStatus = array_fill_keys(array_keys(status_labels()), 0);
$q = $pdo->prepare('SELECT status, COUNT(*) c FROM companies WHERE owner_id=? GROUP BY status');
$q->execute([$__owner]);
foreach ($q as $r) $byStatus[$r['status']] = (int)$r['c'];

$sent = $total - $byStatus['brouillon'] - ($byStatus['a_postuler'] ?? 0);
$responded = $byStatus['repondu'] + $byStatus['entretien'] + $byStatus['accepte'] + $byStatus['refuse'];
$interviews = $byStatus['entretien'];
$accepted = $byStatus['accepte'];
$responseRate = $sent > 0 ? round($responded / $sent * 100, 1) : 0;

// ---- Indicateurs supplémentaires (widgets optionnels) ----
$refused    = $byStatus['refuse'] ?? 0;
$drafts     = $byStatus['brouillon'] ?? 0;
$pending    = $byStatus['envoye'] ?? 0;                       // envoyées sans réponse
$relanced   = $byStatus['relance'] ?? 0;
$interviewRate = $sent > 0 ? round($interviews / max(1,$sent) * 100, 1) : 0;
$successRate   = $sent > 0 ? round($accepted / max(1,$sent) * 100, 1) : 0;
// Candidatures envoyées cette semaine / ce mois
$weekCount = (int)$pdo->query("SELECT COUNT(*) FROM companies WHERE owner_id=$__owner AND COALESCE(applied_date,created_at) >= (CURDATE()-INTERVAL 7 DAY)")->fetchColumn();
$monthCount = (int)$pdo->query("SELECT COUNT(*) FROM companies WHERE owner_id=$__owner AND COALESCE(applied_date,created_at) >= (CURDATE()-INTERVAL 30 DAY)")->fetchColumn();
// Entreprises distinctes
$distinctCompanies = (int)$pdo->query("SELECT COUNT(DISTINCT name) FROM companies WHERE owner_id=$__owner")->fetchColumn();

/**
 * Catalogue des widgets du tableau de bord. Chaque entrée définit la valeur,
 * le libellé, l'icône (dégradé) et si c'est un lien. L'ordre ici est l'ordre
 * par défaut. Les widgets affichés sont pilotés par la préférence utilisateur
 * « dashboard_widgets » (liste de clés). Vide/absent = vue par défaut.
 */
$__voc = search_vocab();
$allWidgets = [
    'total'     => ['val'=>$total, 'lab'=>'Candidatures au total', 'grad'=>'var(--grad)', 'ic'=>'M3 21V7l6-4 6 4v14M15 21V11l6 4v6'],
    'followups' => ['val'=>(int)$__followupCount, 'lab'=>'Relances à faire', 'grad'=>'linear-gradient(135deg,#f59e0b,#ef4444)', 'ic'=>'box', 'href'=>'index.php?page=followups'],
    'todo'      => ['val'=>(int)($byStatus['a_postuler'] ?? 0), 'lab'=>'Offres à faire', 'grad'=>'linear-gradient(135deg,#8b5cf6,#6366f1)', 'ic'=>'check', 'href'=>'index.php?page=todo'],
    'response'  => ['val'=>$responseRate, 'lab'=>'Taux de réponse', 'grad'=>'linear-gradient(135deg,#a855f7,#6366f1)', 'ic'=>'pulse', 'suffix'=>'%', 'decimals'=>1, 'trend'=>"$responded réponse".($responded>1?'s':'')." sur $sent envoi".($sent>1?'s':'')],
    'interviews'=> ['val'=>$interviews, 'lab'=>'Entretiens en cours', 'grad'=>'linear-gradient(135deg,#f97316,#f59e0b)', 'ic'=>'cal'],
    'accepted'  => ['val'=>$accepted, 'lab'=>$__voc['Type'].($accepted>1?'s':'').' décrochée'.($accepted>1?'s':''), 'grad'=>'linear-gradient(135deg,#22c55e,#10b981)', 'ic'=>'M20 6 9 17l-5-5'],
    // Nouveaux indicateurs plus précis
    'week'      => ['val'=>$weekCount, 'lab'=>'Envoyées cette semaine', 'grad'=>'linear-gradient(135deg,#06b6d4,#3b82f6)', 'ic'=>'cal'],
    'month'     => ['val'=>$monthCount, 'lab'=>'Envoyées ce mois', 'grad'=>'linear-gradient(135deg,#0ea5e9,#6366f1)', 'ic'=>'cal'],
    'pending'   => ['val'=>$pending, 'lab'=>'En attente de réponse', 'grad'=>'linear-gradient(135deg,#eab308,#f59e0b)', 'ic'=>'box'],
    'relanced'  => ['val'=>$relanced, 'lab'=>'Relancées', 'grad'=>'linear-gradient(135deg,#8b5cf6,#ec4899)', 'ic'=>'box'],
    'refused'   => ['val'=>$refused, 'lab'=>'Refusées', 'grad'=>'linear-gradient(135deg,#ef4444,#b91c1c)', 'ic'=>'M18 6 6 18M6 6l12 12'],
    'drafts'    => ['val'=>$drafts, 'lab'=>'Brouillons', 'grad'=>'linear-gradient(135deg,#94a3b8,#64748b)', 'ic'=>'check'],
    'interviewRate'=> ['val'=>$interviewRate, 'lab'=>"Taux d'entretien", 'grad'=>'linear-gradient(135deg,#f97316,#a855f7)', 'ic'=>'pulse', 'suffix'=>'%', 'decimals'=>1],
    'successRate'  => ['val'=>$successRate, 'lab'=>'Taux de réussite', 'grad'=>'linear-gradient(135deg,#22c55e,#14b8a6)', 'ic'=>'pulse', 'suffix'=>'%', 'decimals'=>1],
    'companies' => ['val'=>$distinctCompanies, 'lab'=>'Entreprises distinctes', 'grad'=>'linear-gradient(135deg,#6366f1,#8b5cf6)', 'ic'=>'M3 21V7l6-4 6 4v14M15 21V11l6 4v6'],
];
// Vue par défaut : les 6 widgets d'origine, dans l'ordre.
$defaultWidgets = ['total','followups','todo','response','interviews','accepted'];
// Préférence utilisateur
$prefWidgets = [];
try { $pw = user_pref_get((int)$__user['id'], 'dashboard_widgets', ''); if ($pw) $prefWidgets = array_values(array_filter(explode(',', $pw))); } catch (Throwable $e) {}
$activeWidgets = $prefWidgets ?: $defaultWidgets;
// On ne garde que des clés valides
$activeWidgets = array_values(array_filter($activeWidgets, fn($k) => isset($allWidgets[$k])));
if (!$activeWidgets) $activeWidgets = $defaultWidgets;

// Candidatures des 6 derniers mois (par mois)
$months = [];
for ($i = 5; $i >= 0; $i--) $months[date('Y-m', strtotime("-$i month"))] = 0;
$q = $pdo->prepare("SELECT DATE_FORMAT(COALESCE(applied_date,created_at),'%Y-%m') m, COUNT(*) c
                    FROM companies WHERE owner_id=? AND COALESCE(applied_date,created_at) >= (CURDATE()-INTERVAL 6 MONTH)
                    GROUP BY m");
$q->execute([$__owner]);
foreach ($q as $r) if (isset($months[$r['m']])) $months[$r['m']] = (int)$r['c'];

// Top secteurs
$sectors = [];
$q = $pdo->prepare("SELECT NULLIF(sector,'') s, COUNT(*) c FROM companies WHERE owner_id=? GROUP BY s HAVING s IS NOT NULL ORDER BY c DESC LIMIT 5");
$q->execute([$__owner]);
foreach ($q as $r) $sectors[$r['s']] = (int)$r['c'];

// Prochains entretiens
$q = $pdo->prepare("SELECT name, interview_date FROM companies WHERE owner_id=? AND interview_date>=CURDATE() ORDER BY interview_date ASC LIMIT 5");
$q->execute([$__owner]);
$upcoming = $q->fetchAll();

// Dernières candidatures
$q = $pdo->prepare("SELECT * FROM companies WHERE owner_id=? ORDER BY updated_at DESC LIMIT 6");
$q->execute([$__owner]);
$recent = $q->fetchAll();

$colors = status_colors();
$monthLabels = array_map(fn($m) => ucfirst(strftime_fr($m)), array_keys($months));

function strftime_fr($ym) {
    $mois = ['01'=>'jan','02'=>'fév','03'=>'mar','04'=>'avr','05'=>'mai','06'=>'juin','07'=>'juil','08'=>'aoû','09'=>'sep','10'=>'oct','11'=>'nov','12'=>'déc'];
    [$y,$m] = explode('-', $ym);
    return $mois[$m] . ' ' . substr($y, 2);
}

$__pageScripts = ['assets/js/chart.umd.js', 'assets/js/dashboard.js'];
$chartData = [
    'statusLabels' => array_values(array_map('status_label', array_keys($byStatus))),
    'statusData'   => array_values($byStatus),
    'statusColors' => array_map(fn($c) => sprintf('rgb(%d,%d,%d)', $c[0], $c[1], $c[2]), array_values($colors)),
    'months'       => $monthLabels,
    'monthsData'   => array_values($months),
    'sectors'      => array_keys($sectors),
    'sectorsData'  => array_values($sectors),
];
$__inlineScript = 'window.DASH=' . json_encode($chartData, JSON_UNESCAPED_UNICODE) . ';';

require __DIR__ . '/../includes/header.php';
?>

<!-- Barre d'action -->
<div class="section-title">
  <div>
    <h2 style="font-size:22px">Bonjour, <?= e(preg_split('/\s+/', trim($fn))[0] ?? $fn) ?> 👋</h2>
    <p class="muted" style="margin-top:2px">Voici l'état de votre <?= e(search_vocab()['search']) ?>.</p>
  </div>
  <div class="flex gap wrap">
    <?php require __DIR__ . '/../includes/export_menu.php'; ?>
    <a class="btn btn-primary" href="<?= e(url('index.php?page=companies&action=new')) ?>">
      <svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      Nouvelle candidature
    </a>
  </div>
</div>

<!-- Stat cards (widgets configurables) -->
<?php
/** Rend l'icône SVG d'un widget à partir d'un mot-clé ou d'un path. */
function dash_widget_icon(string $ic): string {
    $paths = [
        'box'   => '<rect x="3" y="5" width="18" height="14" rx="2" stroke="#fff" stroke-width="1.8" fill="none"/><path d="M3 8l9 6 9-6" stroke="#fff" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',
        'check' => '<path d="M9 11l3 3 8-8" stroke="#fff" stroke-width="1.9" fill="none" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9" stroke="#fff" stroke-width="1.9" fill="none" stroke-linecap="round"/>',
        'pulse' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2" stroke="#fff" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',
        'cal'   => '<path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z" stroke="#fff" stroke-width="1.7" fill="none"/>',
    ];
    if (isset($paths[$ic])) return $paths[$ic];
    return '<path d="' . e($ic) . '" stroke="#fff" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/>';
}
?>
<div class="grid stats mb">
  <?php foreach ($activeWidgets as $wk): $w = $allWidgets[$wk];
    $tag = !empty($w['href']) ? 'a' : 'div';
    $attrs = !empty($w['href']) ? 'href="'.e(url($w['href'])).'" style="text-decoration:none;color:inherit"' : '';
    $arrow = (!empty($w['href']) && (float)$w['val'] > 0) ? ' →' : '';
  ?>
  <<?= $tag ?> class="stat fade-up" data-widget="<?= e($wk) ?>" <?= $attrs ?>>
    <div class="stat-ic" style="background:<?= $w['grad'] ?>"><svg viewBox="0 0 24 24" width="20" height="20"><?= dash_widget_icon($w['ic']) ?></svg></div>
    <div class="stat-val" data-count="<?= e((string)$w['val']) ?>"<?= isset($w['suffix'])?' data-suffix="'.e($w['suffix']).'"':'' ?><?= isset($w['decimals'])?' data-decimals="'.(int)$w['decimals'].'"':'' ?>>0</div>
    <div class="stat-lab"><?= e($w['lab']) . $arrow ?></div>
    <?php if (!empty($w['trend'])): ?><div class="stat-trend <?= $responseRate>=30?'trend-up':'trend-flat' ?>"><?= e($w['trend']) ?></div><?php endif; ?>
  </<?= $tag ?>>
  <?php endforeach; ?>
</div>

<!-- Pipeline signature -->
<div class="card card-pad mb" data-reveal>
  <div class="section-title" style="margin-bottom:14px"><h2>Progression du pipeline</h2><span class="tag"><?= $total ?> au total</span></div>
  <div class="pipeline">
    <?php $order=['brouillon','envoye','relance','repondu','entretien','accepte']; foreach ($order as $s):
      $n=$byStatus[$s]; $pct = $total? round($n/$total*100):0; $c=$colors[$s]; ?>
      <div class="pipe-seg">
        <div class="pipe-bar"><div class="pipe-fill" data-w="<?= $pct ?>" style="background:rgb(<?= "$c[0],$c[1],$c[2]" ?>)"></div></div>
        <span class="pipe-num"><?= $n ?></span>
        <span class="pipe-lab"><?= e(status_label($s)) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Charts -->
<div class="grid charts mb">
  <div class="card chart-box" data-reveal>
    <div class="section-title"><h2>Candidatures par mois</h2></div>
    <div class="chart-canvas"><canvas id="chartMonths"></canvas></div>
  </div>
  <div class="card chart-box" data-reveal>
    <div class="section-title"><h2>Répartition par statut</h2></div>
    <div class="chart-canvas"><canvas id="chartStatus"></canvas></div>
  </div>
</div>

<!-- Bottom row -->
<div class="grid mb" style="grid-template-columns:1.4fr 1fr;gap:18px">
  <div class="card" data-reveal>
    <div class="section-title" style="padding:20px 22px 0"><h2>Dernières candidatures</h2>
      <a href="<?= e(url('index.php?page=companies')) ?>" class="link-btn">Tout voir →</a></div>
    <div class="table-wrap">
      <?php if ($recent): ?>
      <table class="data">
        <thead><tr><th>Entreprise</th><th>Poste</th><th>Statut</th><th>Maj</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $c): $col=$colors[$c['status']]; ?>
          <tr>
            <td data-label="Entreprise"><div class="cell-main"><?= e($c['name']) ?></div><div class="cell-sub"><?= e($c['city'] ?: $c['sector']) ?></div></td>
            <td class="cell-sub" data-label="Poste"><?= e($c['position'] ?: '—') ?></td>
            <td data-label="Statut"><span class="badge" style="background:rgba(<?= "$col[0],$col[1],$col[2]" ?>,.12);color:rgb(<?= "$col[0],$col[1],$col[2]" ?>)"><span class="dot"></span><?= e(status_label($c['status'])) ?></span></td>
            <td class="cell-sub" data-label="Maj"><?= e(date('d/m', strtotime($c['updated_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
        <div class="empty"><h3>Aucune candidature</h3><p>Ajoutez votre première entreprise pour démarrer.</p></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card card-pad" data-reveal>
    <div class="section-title"><h2>Prochains entretiens</h2></div>
    <?php if ($upcoming): ?>
      <?php foreach ($upcoming as $u): ?>
        <div class="setting-row">
          <div class="st-txt"><strong><?= e($u['name']) ?></strong><p><?= e(date('l j F', strtotime($u['interview_date']))) ?></p></div>
          <span class="badge" style="background:rgba(249,115,22,.12);color:#f97316"><span class="dot"></span><?= e(date('d/m', strtotime($u['interview_date']))) ?></span>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty" style="padding:30px 10px"><p>Aucun entretien programmé. Continuez à postuler !</p></div>
    <?php endif; ?>
    <?php if ($sectors): ?>
      <div class="section-title mt"><h2 style="font-size:15px">Top secteurs</h2></div>
      <?php foreach ($sectors as $s=>$n): ?>
        <div class="flex between center" style="padding:7px 0">
          <span class="cell-sub"><?= e($s) ?></span><span class="tag"><?= $n ?></span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
