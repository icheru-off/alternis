<?php
require_once __DIR__ . '/../includes/auth.php';
$__user = require_login();
$__owner = data_owner_id();

$cid = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM companies WHERE id=? AND owner_id=?');
$st->execute([$cid, $__owner]);
$c = $st->fetch();
if (!$c) { redirect(url('index.php?page=companies')); }

$__active = 'companies';
$__pageTitle = $c['name'];
$__pageScripts = ['assets/js/company.js'];

$labels = status_labels();
$colors = status_colors();
$col = $colors[$c['status']] ?? [148, 163, 184];
$prio = ['basse' => 'Basse', 'normale' => 'Normale', 'haute' => 'Haute'][$c['priority']] ?? $c['priority'];

// Domaine pour le logo
$domain = '';
if ($c['website']) {
    $u = $c['website'];
    if (!preg_match('~^https?://~i', $u)) $u = 'https://' . $u;
    $host = parse_url($u, PHP_URL_HOST);
    if ($host) $domain = preg_replace('/^www\./', '', $host);
}
$initial = mb_strtoupper(mb_substr(trim($c['name']), 0, 1));

$infos = [
    ['Poste', $c['position']],
    ['Secteur', $c['sector']],
    ['Type de candidature', $c['apply_channel'] ?? ''],
    ['Priorité', $prio],
    ['Contact', $c['contact_name']],
    ['E-mail', $c['email']],
    ['Téléphone', $c['phone']],
    ['Adresse', trim(implode(' ', array_filter([$c['address'], $c['postal_code'], $c['city']])))],
    ['Rémunération', $c['salary']],
    ['Date de candidature', $c['applied_date'] ? date('d/m/Y', strtotime($c['applied_date'])) : ''],
    ['Date de réponse', $c['response_date'] ? date('d/m/Y', strtotime($c['response_date'])) : ''],
    ['Entretien', $c['interview_date'] ? date('d/m/Y', strtotime($c['interview_date'])) : ''],
    ['Relance prévue', $c['followup_date'] ? date('d/m/Y', strtotime($c['followup_date'])) : ''],
];

require_once __DIR__ . '/../includes/orgs.php';
ensure_orgs_tables();
// Rattachement paresseux : les candidatures d'avant la migration n'ont pas d'org_id
if (empty($c['org_id'])) {
    $__oid = org_find_or_create($__owner, (string)$c['name'], $c);
    if ($__oid) {
        db()->prepare('UPDATE companies SET org_id=? WHERE id=?')->execute([$__oid, $c['id']]);
        $c['org_id'] = $__oid;
    }
}
$__previous = org_previous_applications($__owner, (int)($c['org_id'] ?? 0), (int)$c['id']);
$__statusLabels = status_labels();

require __DIR__ . '/../includes/header.php';
?>

<a class="back-link" href="<?= e(url('index.php?page=companies')) ?>">
  <svg viewBox="0 0 24 24" width="18" height="18" fill="none"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
  Retour aux entreprises
</a>

<?php if ($__previous): ?>
<div class="admin-banner ac-info" style="margin-bottom:16px">
  <div class="ab-txt">
    <strong>Vous avez déjà postulé chez <?= e($c['name']) ?>.</strong>
    <?php foreach ($__previous as $p): ?>
      <span style="display:block;margin-top:3px">
        <?= e($p['position'] ?: 'Poste non précisé') ?> —
        <?= e($__statusLabels[$p['status']] ?? $p['status']) ?><?php if ($p['applied_date']): ?>,
        le <?= e(date('d/m/Y', strtotime($p['applied_date']))) ?><?php endif; ?>
      </span>
    <?php endforeach; ?>
    <?php if (!empty($c['org_id'])): ?>
      <a href="<?= e(url('index.php?page=org&id=' . (int)$c['org_id'])) ?>" style="display:inline-block;margin-top:6px;font-weight:600">Voir la fiche entreprise →</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card card-pad co-header" data-reveal>
  <div class="co-id">
    <span class="clogo co-logo">
      <em><?= e($initial) ?></em>
      <?php if ($domain): ?><img src="https://logo.clearbit.com/<?= e(rawurlencode($domain)) ?>?size=120" alt=""
        onerror="if(!this.dataset.f){this.dataset.f=1;this.src='https://www.google.com/s2/favicons?domain=<?= e(rawurlencode($domain)) ?>&sz=64'}else{this.remove()}"><?php endif; ?>
    </span>
    <div>
      <h2 style="font-size:22px;margin:0"><?= e($c['name']) ?></h2>
      <div class="co-meta">
        <span class="badge" style="background:rgba(<?= "$col[0],$col[1],$col[2]" ?>,.12);color:rgb(<?= "$col[0],$col[1],$col[2]" ?>)"><span class="dot"></span><?= e($labels[$c['status']] ?? $c['status']) ?></span>
        <?php if ($c['position']): ?><span class="muted"><?= e($c['position']) ?></span><?php endif; ?>
        <?php if ($domain): ?><a href="<?= e(preg_match('~^https?://~i', $c['website']) ? $c['website'] : 'https://' . $c['website']) ?>" target="_blank" rel="noopener" class="sc-link"><?= e($domain) ?> ↗</a><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="co-actions">
    <button class="btn btn-ghost btn-sm" id="coEdit">✎ Modifier</button>
    <button class="btn btn-ghost btn-sm" id="coDelete" style="color:#dc2626">Supprimer</button>
  </div>
</div>

<div class="grid mb" style="grid-template-columns:1.1fr 1fr;gap:18px" data-reveal>
  <div class="card card-pad">
    <div class="section-title" style="margin-bottom:12px"><h2>Informations</h2></div>
    <div class="co-infos">
      <?php foreach ($infos as $row): if (!$row[1]) continue; ?>
        <div class="co-row"><span class="co-k"><?= e($row[0]) ?></span><span class="co-v"><?= e($row[1]) ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php if ($c['notes']): ?>
      <div style="margin-top:16px"><strong>Notes</strong><p class="muted" style="margin-top:6px;white-space:pre-wrap"><?= e($c['notes']) ?></p></div>
    <?php endif; ?>
  </div>

  <div class="card card-pad">
    <div class="section-title" style="margin-bottom:12px"><h2>Échanges d'e-mails</h2>
      <button class="btn btn-ghost btn-sm" id="mailAddBtn" type="button">+ Ajouter</button></div>
    <form id="mailForm" hidden class="mail-form">
      <div class="form-grid">
        <div class="field"><label>Sens</label><select name="direction"><option value="recu">Reçu de l'entreprise</option><option value="envoye">Envoyé par moi</option></select></div>
        <div class="field"><label>Date</label><input name="email_date" type="date"></div>
        <div class="field full"><label>Objet</label><input name="subject" placeholder="Objet de l'e-mail"></div>
        <div class="field full"><label>Message</label><textarea name="body" placeholder="Copiez ici le contenu de l'e-mail…"></textarea></div>
        <div class="field full"><label>Pièce jointe (facultatif)</label><input name="attachment" type="file"></div>
      </div>
      <div class="flex gap" style="justify-content:flex-end">
        <button class="btn btn-ghost btn-sm" type="button" id="mailCancel">Annuler</button>
        <button class="btn btn-primary btn-sm" type="button" id="mailSave">Enregistrer</button>
      </div>
    </form>
    <div id="mailList" class="mail-list timeline"><p class="hint">Chargement…</p></div>
  </div>
</div>

<!-- Modale édition (réutilise l'API companies) -->
<div class="modal-scrim" id="coModal">
  <div class="modal">
    <div class="modal-head"><h3>Modifier l'entreprise</h3><button class="close-x" data-close="coModal">✕</button></div>
    <div class="modal-body">
      <form id="coForm">
        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <input type="hidden" name="apply_channel" value="<?= e($c['apply_channel'] ?? '') ?>">
        <input type="hidden" name="salary" value="<?= e($c['salary']) ?>">
        <div class="form-grid">
          <div class="field full"><label>Nom *</label><input name="name" required value="<?= e($c['name']) ?>"></div>
          <div class="field"><label>Poste</label><input name="position" value="<?= e($c['position']) ?>"></div>
          <div class="field"><label>Secteur</label><input name="sector" value="<?= e($c['sector']) ?>"></div>
          <div class="field"><label>Statut</label><select name="status">
            <?php foreach ($labels as $k => $l): ?><option value="<?= e($k) ?>" <?= $c['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
          </select></div>
          <div class="field"><label>Priorité</label><select name="priority">
            <option value="basse" <?= $c['priority'] === 'basse' ? 'selected' : '' ?>>Basse</option>
            <option value="normale" <?= $c['priority'] === 'normale' ? 'selected' : '' ?>>Normale</option>
            <option value="haute" <?= $c['priority'] === 'haute' ? 'selected' : '' ?>>Haute</option>
          </select></div>
          <div class="field"><label>Contact</label><input name="contact_name" value="<?= e($c['contact_name']) ?>"></div>
          <div class="field"><label>E-mail</label><input name="email" type="email" value="<?= e($c['email']) ?>"></div>
          <div class="field"><label>Téléphone</label><input name="phone" value="<?= e($c['phone']) ?>"></div>
          <div class="field"><label>Ville</label><input name="city" value="<?= e($c['city']) ?>"></div>
          <div class="field"><label>Code postal</label><input name="postal_code" value="<?= e($c['postal_code']) ?>"></div>
          <div class="field full"><label>Adresse</label><input name="address" value="<?= e($c['address']) ?>"></div>
          <div class="field full"><label>Site web</label><input name="website" value="<?= e($c['website']) ?>"></div>
          <div class="field"><label>Date de candidature</label><input name="applied_date" type="date" value="<?= e($c['applied_date']) ?>"></div>
          <div class="field"><label>Date de réponse</label><input name="response_date" type="date" value="<?= e($c['response_date']) ?>"></div>
          <div class="field"><label>Entretien</label><input name="interview_date" type="date" value="<?= e($c['interview_date']) ?>"></div>
          <div class="field"><label>Relance</label><input name="followup_date" type="date" value="<?= e($c['followup_date']) ?>"></div>
          <div class="field full"><label>Notes</label><textarea name="notes"><?= e($c['notes']) ?></textarea></div>
        </div>
      </form>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" data-close="coModal">Annuler</button>
      <button class="btn btn-primary" id="coSave">Enregistrer</button>
    </div>
  </div>
</div>

<script>window.CO_ID = <?= (int)$c['id'] ?>;</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
