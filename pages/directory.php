<?php
require_once __DIR__ . '/../includes/auth.php';
$__user = require_login();
$__owner = data_owner_id();
$__active = 'directory';
$__pageTitle = 'Entreprises';
$__pageScripts = ['assets/js/directory.js'];
$__inlineScript = 'window.STATUSES=' . json_encode(status_labels(), JSON_UNESCAPED_UNICODE) . ';'
    . 'window.STATUS_COLORS=' . json_encode(array_map(fn($c)=>"$c[0],$c[1],$c[2]", status_colors())) . ';';

require __DIR__ . '/../includes/header.php';
?>

<div class="section-title">
  <div>
    <h2 style="font-size:22px">Vos entreprises</h2>
    <p class="muted" style="margin-top:2px">Une fiche par société, avec toutes ses candidatures, son contact et son historique.</p>
  </div>
  <button class="btn btn-ghost btn-sm" id="dirSelectMode">Sélectionner</button>
</div>

<div class="toolbar">
  <div class="search">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8"/><path d="m20 20-3-3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
    <input id="dirSearch" placeholder="Rechercher une entreprise…">
  </div>
  <label class="chk" id="dirSelAllWrap" hidden style="margin-left:auto"><input type="checkbox" id="dirSelAll"> Tout sélectionner</label>
</div>

<div class="bulk-bar" id="dirBulkBar" hidden>
  <span class="bulk-count"><strong id="dirBulkN">0</strong> sélectionnée(s)</span>
  <div class="bulk-actions">
    <button class="btn btn-ghost btn-sm" id="dirMerge" title="Fusionner en une seule fiche">Fusionner</button>
    <button class="btn btn-danger btn-sm" id="dirBulkDelete">Supprimer</button>
    <button class="btn btn-ghost btn-sm" id="dirBulkClear">Annuler</button>
  </div>
</div>

<div id="dirList" class="dir-grid"><div class="sc-loading">Chargement…</div></div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
