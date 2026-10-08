<?php
require_once __DIR__ . '/../export/common.php';
/**
 * Menu d'export unifié (PDF / CSV / Excel) — dropdown.
 * $exportScope : 'companies' (par défaut) ou 'stats'.
 */
$exportScope = $exportScope ?? 'companies';
$expBase = url('export');
?>
<div class="dropdown">
  <button class="btn btn-ghost" data-dropdown="exportMenu" aria-haspopup="true">
    <svg viewBox="0 0 24 24" fill="none"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
    Exporter
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none"><path d="m6 9 6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
  </button>
  <div class="dropdown-menu" id="exportMenu">
    <div class="dropdown-group">Rapport complet</div>
    <a class="dropdown-item" href="<?= e($expBase . '/pdf.php?scope=full') ?>" target="_blank">
      <span class="di-ic di-pdf"><svg viewBox="0 0 24 24" width="16" height="16" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="1.8"/><path d="M14 2v6h6" stroke="currentColor" stroke-width="1.8"/></svg></span>
      <span>Rapport PDF complet<br><span class="di-sub">Stats + candidatures, mise en page soignée</span></span>
    </a>
    <div class="dropdown-group">Tableur</div>
    <a class="dropdown-item" href="<?= e($expBase . '/xlsx.php') ?>">
      <span class="di-ic di-xls"><svg viewBox="0 0 24 24" width="16" height="16" fill="none"><rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M8 3v18M3 9h18" stroke="currentColor" stroke-width="1.5"/></svg></span>
      <span>Excel (.xlsx)<br><span class="di-sub">Données + feuille de synthèse croisée</span></span>
    </a>
    <a class="dropdown-item" href="<?= e($expBase . '/csv.php') ?>">
      <span class="di-ic di-csv"><svg viewBox="0 0 24 24" width="16" height="16" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="1.8"/><path d="M14 2v6h6M8 13h1m3 0h1m3 0h1M8 17h8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
      <span>CSV (.csv)<br><span class="di-sub">Compatible Excel / Google Sheets</span></span>
    </a>
    <div class="dropdown-group">Ciblé</div>
    <a class="dropdown-item" href="<?= e($expBase . '/pdf.php?scope=stats') ?>" target="_blank">
      <span class="di-ic di-pdf"><svg viewBox="0 0 24 24" width="16" height="16" fill="none"><path d="M3 3v18h18M18 17V9M13 17V5M8 17v-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
      <span>Chiffres clés en PDF<br><span class="di-sub">Synthèse statistique uniquement</span></span>
    </a>
    <button type="button" class="dropdown-item" id="pdfCustomBtn" style="width:100%;background:none;border:none;text-align:left;cursor:pointer">
      <span class="di-ic di-pdf"><svg viewBox="0 0 24 24" width="16" height="16" fill="none"><path d="M4 6h16M7 12h10M10 18h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
      <span>PDF personnalisé…<br><span class="di-sub">Choisir les statuts à inclure</span></span>
    </button>
  </div>
</div>

<div class="modal-scrim" id="pdfCustomModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-head"><h3>PDF personnalisé</h3><button class="close-x" data-close="pdfCustomModal">✕</button></div>
    <div class="modal-body">
      <div class="pdf-sec">
        <div class="pdf-sec-head">
          <strong>Statuts à inclure</strong>
          <button type="button" class="linkish" data-toggle-all="pdf-st">Tout cocher / décocher</button>
        </div>
        <div class="pdf-status-list">
          <?php foreach (status_labels() as $k => $l): ?>
            <label class="chk"><input type="checkbox" class="pdf-st" value="<?= e($k) ?>" checked> <?= e($l) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="pdf-sec">
        <div class="pdf-sec-head">
          <strong>Colonnes à afficher</strong>
          <button type="button" class="linkish" data-toggle-all="pdf-fd">Tout cocher / décocher</button>
        </div>
        <p class="hint" style="margin:0 0 8px">L'ordre des colonnes suit celui de cette liste. Plus vous en cochez, plus elles seront étroites.</p>
        <div class="pdf-field-list">
          <?php
            $__pdfDefaults = pdf_default_fields();
            foreach (pdf_fields() as $k => $f):
                $checked = in_array($k, $__pdfDefaults, true);
          ?>
            <label class="chk"><input type="checkbox" class="pdf-fd" value="<?= e($k) ?>" <?= $checked ? 'checked' : '' ?>> <?= e($f[3]) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" data-close="pdfCustomModal">Annuler</button>
      <button class="btn btn-primary" id="pdfCustomGo">Générer le PDF</button>
    </div>
  </div>
</div>
