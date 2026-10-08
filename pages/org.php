<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orgs.php';
$__user = require_login();
$__owner = data_owner_id();
$__active = 'directory';

$orgId = (int)($_GET['id'] ?? 0);
$org = org_get($__owner, $orgId);
if (!$org) { redirect(url('index.php?page=directory')); }

$__pageTitle = $org['name'];
$__pageScripts = ['assets/js/org.js'];
$__inlineScript = 'window.ORG_ID=' . $orgId . ';'
    . 'window.STATUSES=' . json_encode(status_labels(), JSON_UNESCAPED_UNICODE) . ';'
    . 'window.STATUS_COLORS=' . json_encode(array_map(fn($c) => "$c[0],$c[1],$c[2]", status_colors())) . ';';

$labels = status_labels();
$colors = status_colors();
$offers = $org['offers'];

require __DIR__ . '/../includes/header.php';
?>

<?php
  $orgInitial = mb_strtoupper(mb_substr(trim($org['name']) ?: '?', 0, 1));
  $orgDomain = trim((string)$org['domain']);
  if ($orgDomain === '' && $org['website']) {
      $h = parse_url(preg_match('#^https?://#', $org['website']) ? $org['website'] : 'https://' . $org['website'], PHP_URL_HOST);
      if ($h) $orgDomain = preg_replace('/^www\./', '', $h);
  }
?>
<a class="back-link" href="<?= e(url('index.php?page=directory')) ?>">
  <svg viewBox="0 0 24 24" width="18" height="18" fill="none"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
  Toutes les entreprises
</a>

<div class="card card-pad co-header" data-reveal>
  <div class="co-id">
    <span class="clogo co-logo">
      <em><?= e($orgInitial) ?></em>
      <?php if ($orgDomain): ?><img src="https://logo.clearbit.com/<?= e(rawurlencode($orgDomain)) ?>?size=120" alt=""
        onerror="if(!this.dataset.f){this.dataset.f=1;this.src='https://www.google.com/s2/favicons?domain=<?= e(rawurlencode($orgDomain)) ?>&sz=64'}else{this.remove()}"><?php endif; ?>
    </span>
    <div style="min-width:0">
      <h2 style="font-size:22px;margin:0"><?= e($org['name']) ?></h2>
      <p class="muted" style="margin-top:2px">
        <?= count($offers) ?> candidature<?= count($offers) > 1 ? 's' : '' ?>
        <?php if ($org['sector']): ?> · <?= e($org['sector']) ?><?php endif; ?>
        <?php if ($org['city']): ?> · <?= e($org['city']) ?><?php endif; ?>
      </p>
    </div>
  </div>
  <div class="co-actions flex gap wrap">
    <button class="btn btn-ghost" id="orgEdit">Modifier la fiche</button>
    <a class="btn btn-primary" href="<?= e(url('index.php?page=companies&action=new')) ?>">+ Nouvelle candidature</a>
  </div>
</div>

<div class="grid" style="grid-template-columns:1fr 340px;gap:18px;align-items:start" id="orgGrid">

  <div>
    <div class="card" data-reveal>
      <div class="card-pad" style="padding-bottom:0"><h3 style="font-size:16px;margin:0">Candidatures chez <?= e($org['name']) ?></h3></div>
      <?php if (!$offers): ?>
        <div class="empty" style="padding:40px"><p>Aucune candidature enregistrée.</p></div>
      <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Poste</th><th>Statut</th><th>Postulé le</th><th>Réponse</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($offers as $o): $col = $colors[$o['status']] ?? [148,163,184]; ?>
            <tr>
              <td data-label="Poste"><div class="cell-main"><?= e($o['position'] ?: '—') ?></div>
                <?php if ($o['apply_channel']): ?><div class="cell-sub"><?= e($o['apply_channel']) ?></div><?php endif; ?></td>
              <td data-label="Statut"><span class="badge" style="background:rgba(<?= implode(',', $col) ?>,.12);color:rgb(<?= implode(',', $col) ?>)"><span class="dot"></span><?= e($labels[$o['status']] ?? $o['status']) ?></span></td>
              <td data-label="Postulé le" class="cell-sub"><?= $o['applied_date'] ? e(date('d/m/Y', strtotime($o['applied_date']))) : '—' ?></td>
              <td data-label="Réponse" class="cell-sub"><?= $o['response_date'] ? e(date('d/m/Y', strtotime($o['response_date']))) : '—' ?></td>
              <td class="cell-actions"><a class="mini-btn" href="<?= e(url('index.php?page=company&id=' . (int)$o['id'])) ?>" title="Ouvrir">→</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="card card-pad mt" data-reveal>
      <h3 style="font-size:16px;margin:0 0 12px">Historique des échanges</h3>
      <div id="orgMails"><p class="hint">Chargement…</p></div>
    </div>
  </div>

  <aside>
    <div class="card card-pad" data-reveal>
      <h3 style="font-size:16px;margin:0 0 14px">Contact</h3>
      <div class="org-field"><span>Interlocuteur</span><strong><?= e($org['contact_name'] ?: '—') ?></strong></div>
      <div class="org-field"><span>E-mail</span><strong><?= $org['email'] ? '<a href="mailto:' . e($org['email']) . '">' . e($org['email']) . '</a>' : '—' ?></strong></div>
      <div class="org-field"><span>Téléphone</span><strong><?= e($org['phone'] ?: '—') ?></strong></div>
      <div class="org-field"><span>Site web</span><strong><?= $org['website'] ? '<a href="' . e(preg_match('#^https?://#', $org['website']) ? $org['website'] : 'https://' . $org['website']) . '" target="_blank" rel="noopener">' . e($org['domain'] ?: $org['website']) . '</a>' : '—' ?></strong></div>
      <div class="org-field"><span>Adresse</span><strong><?= e(trim($org['address'] . ' ' . $org['postal_code'] . ' ' . $org['city'])) ?: '—' ?></strong></div>
    </div>

    <div class="card card-pad mt" data-reveal>
      <h3 style="font-size:16px;margin:0 0 10px">Notes sur l'entreprise</h3>
      <p class="muted" style="white-space:pre-wrap;margin:0;font-size:13.5px"><?= e(trim((string)$org['notes'])) ?: 'Aucune note.' ?></p>
    </div>
  </aside>
</div>

<div class="modal-scrim" id="orgModal">
  <div class="modal">
    <div class="modal-head"><h3>Modifier la fiche entreprise</h3><button class="close-x" data-close="orgModal">✕</button></div>
    <div class="modal-body">
      <form id="orgForm">
        <input type="hidden" name="id" value="<?= (int)$org['id'] ?>">
        <div class="form-grid">
          <div class="field"><label>Nom *</label><input name="name" value="<?= e($org['name']) ?>"></div>
          <div class="field"><label>Secteur</label><input name="sector" value="<?= e($org['sector']) ?>"></div>
          <div class="field"><label>Site web</label><input name="website" value="<?= e($org['website']) ?>"></div>
          <div class="field"><label>Interlocuteur</label><input name="contact_name" value="<?= e($org['contact_name']) ?>"></div>
          <div class="field"><label>E-mail</label><input name="email" type="email" value="<?= e($org['email']) ?>"></div>
          <div class="field"><label>Téléphone</label><input name="phone" value="<?= e($org['phone']) ?>"></div>
          <div class="field"><label>Adresse</label><input name="address" value="<?= e($org['address']) ?>"></div>
          <div class="field"><label>Code postal</label><input name="postal_code" value="<?= e($org['postal_code']) ?>"></div>
          <div class="field"><label>Ville</label><input name="city" value="<?= e($org['city']) ?>"></div>
          <div class="field full"><label>Notes</label><textarea name="notes"><?= e((string)$org['notes']) ?></textarea></div>
        </div>
      </form>
    </div>
    <div class="modal-foot"><button class="btn btn-ghost" data-close="orgModal">Annuler</button>
      <button class="btn btn-primary" id="orgSave">Enregistrer</button></div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
