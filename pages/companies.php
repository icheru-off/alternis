<?php
require_once __DIR__ . '/../includes/auth.php';
$__user = require_login();
$__owner = data_owner_id();
$__active = 'companies';
$__pageTitle = 'Candidatures';

$statuses = status_labels();
$colors = status_colors();
$autoNew = ($_GET['action'] ?? '') === 'new';
$preFilter = $_GET['filter'] ?? 'all';
$preSearch = trim($_GET['q'] ?? '');

$__pageScripts = ['assets/js/companies.js'];
$__inlineScript = 'window.STATUSES=' . json_encode($statuses, JSON_UNESCAPED_UNICODE) . ';'
    . 'window.STATUS_COLORS=' . json_encode(array_map(fn($c)=>"$c[0],$c[1],$c[2]", $colors)) . ';'
    . 'window.AUTO_NEW=' . ($autoNew ? 'true' : 'false') . ';'
    . 'window.PRE_FILTER=' . json_encode($preFilter) . ';'
    . 'window.PRE_SEARCH=' . json_encode($preSearch) . ';';

require __DIR__ . '/../includes/header.php';
?>

<div class="section-title">
  <div>
    <h2 style="font-size:22px">Vos candidatures</h2>
    <p class="muted" style="margin-top:2px">Toutes vos candidatures, au même endroit.</p>
  </div>
  <div class="flex gap wrap">
    <?php require __DIR__ . '/../includes/export_menu.php'; ?>
    <button class="btn btn-ghost" id="btnShare">
      <svg viewBox="0 0 24 24" fill="none"><path d="M4 12v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7M16 6l-4-4-4 4M12 2v14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Partager
    </button>
    <button class="btn btn-ghost" onclick="openModal('importModal')">
      <svg viewBox="0 0 24 24" fill="none"><path d="M12 15V3m0 0L8 7m4-4 4 4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" transform="rotate(180 12 12)"/></svg>
      Importer
    </button>
    <button class="btn btn-primary" id="btnNew">
      <svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      Nouvelle candidature
    </button>
  </div>
</div>

<div class="toolbar">
  <div class="search">
    <svg viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8"/><path d="m20 20-3-3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
    <input id="searchInput" placeholder="Rechercher une entreprise, un poste, une ville…">
  </div>
  <div class="chip-row" id="filterChips">
    <button class="chip active" data-filter="all">Toutes</button>
    <?php foreach ($statuses as $k=>$l): ?>
      <button class="chip" data-filter="<?= e($k) ?>"><?= e($l) ?></button>
    <?php endforeach; ?>
  </div>
  <button class="btn btn-ghost btn-sm" id="advToggle" type="button">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none"><path d="M4 6h16M7 12h10M10 18h4" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
    Filtres
    <span class="filter-count" id="filterCount" hidden>0</span>
  </button>
  <button class="btn btn-ghost btn-sm" id="selectAllToolbar" type="button">Tout sélectionner</button>
</div>

<div class="adv-filters card" id="advPanel" hidden>
  <div class="adv-grid">
    <div class="field"><label>Priorité</label>
      <select id="fPriority"><option value="">Toutes</option><option value="haute">Haute</option><option value="normale">Normale</option><option value="basse">Basse</option></select>
    </div>
    <div class="field"><label>Type de candidature</label>
      <select id="fChannel"><option value="">Tous</option><option>Spontanée</option><option>Indeed</option><option>HelloWork</option><option>LinkedIn</option></select>
    </div>
    <div class="field"><label>Secteur</label><input id="fSector" placeholder="ex. Informatique"></div>
    <div class="field"><label>Ville</label><input id="fCity" placeholder="ex. Paris"></div>
    <div class="field"><label>Candidature depuis le</label><input id="fDateFrom" type="date"></div>
    <div class="field"><label>Candidature jusqu'au</label><input id="fDateTo" type="date"></div>
    <div class="field"><label>Réponse</label>
      <select id="fResponded"><option value="">Peu importe</option><option value="yes">Réponse reçue</option><option value="no">Sans réponse</option></select>
    </div>
    <div class="field"><label>Tri</label>
      <select id="fSort"><option value="recent">Plus récentes</option><option value="old">Plus anciennes</option><option value="name">Nom (A→Z)</option><option value="priority">Priorité</option></select>
    </div>
  </div>
  <div class="adv-actions">
    <button class="btn btn-ghost btn-sm" id="advReset" type="button">Réinitialiser</button>
  </div>
</div>

<div class="bulk-bar" id="bulkBar" hidden>
  <span class="bulk-count"><strong id="bulkN">0</strong> sélectionnée(s)</span>
  <button class="btn btn-ghost btn-sm" id="bulkSelectAll" type="button">Tout sélectionner</button>
  <div class="bulk-actions">
    <select id="bulkStatus" class="bulk-sel"><option value="">Changer le statut…</option>
      <?php foreach ($statuses as $k=>$l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <select id="bulkPriority" class="bulk-sel"><option value="">Priorité…</option>
      <option value="haute">Haute</option><option value="normale">Normale</option><option value="basse">Basse</option>
    </select>
    <select id="bulkChannel" class="bulk-sel"><option value="">Type…</option>
      <?php foreach (apply_channels() as $ch): ?><option value="<?= e($ch) ?>"><?= e($ch) ?></option><?php endforeach; ?>
      <option value="__custom">Autre…</option>
    </select>
    <button class="btn btn-primary btn-sm" id="bulkApply">Appliquer</button>
    <button class="btn btn-ghost btn-sm" id="bulkDates">Modifier les dates…</button>
    <button class="btn btn-ghost btn-sm" id="bulkExport">Exporter en PDF</button>
    <button class="btn btn-danger btn-sm" id="bulkDelete">Supprimer</button>
    <button class="btn btn-ghost btn-sm" id="bulkClear">Annuler</button>
  </div>
</div>

<!-- Édition groupée des dates -->
<div class="modal-scrim" id="bulkDatesModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-head"><h3>Modifier les dates</h3><button class="close-x" data-close="bulkDatesModal">✕</button></div>
    <div class="modal-body">
      <p class="hint" style="margin-top:0"><strong id="bulkDatesN">0</strong> candidature(s) sélectionnée(s). Laissez un champ vide pour ne pas y toucher.</p>
      <div class="field">
        <label>Date de candidature</label>
        <input type="date" id="bulkAppliedDate">
        <label class="chk" style="margin-top:6px"><input type="checkbox" id="bulkAppliedClear"> Effacer cette date</label>
      </div>
      <div class="field">
        <label>Date de réponse</label>
        <input type="date" id="bulkResponseDate">
        <label class="chk" style="margin-top:6px"><input type="checkbox" id="bulkResponseClear"> Effacer cette date</label>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" data-close="bulkDatesModal">Annuler</button>
      <button class="btn btn-primary" id="bulkDatesApply">Appliquer</button>
    </div>
  </div>
</div>

<div class="card" data-reveal>
  <div class="table-wrap">
    <table class="data" id="compTable">
      <thead><tr>
        <th class="col-check"><input type="checkbox" id="selAll" title="Tout sélectionner"></th>
        <th>Entreprise</th><th>Poste</th><th>Contact</th><th>Statut</th><th>Priorité</th><th>Candidature</th><th></th>
      </tr></thead>
      <tbody id="compBody"><tr><td colspan="8" style="text-align:center;padding:40px" class="muted">Chargement…</td></tr></tbody>
    </table>
  </div>
  <div class="pager" id="pager" hidden>
    <div class="pager-info" id="pagerInfo"></div>
    <div class="pager-ctl">
      <button class="pg-btn pg-nav" id="pgPrev" aria-label="Page précédente">‹</button>
      <span class="pg-pages" id="pgPages"></span>
      <button class="pg-btn pg-nav" id="pgNext" aria-label="Page suivante">›</button>
      <select class="pg-size" id="pgSize" aria-label="Lignes par page">
        <option value="10">10 / page</option>
        <option value="25" selected>25 / page</option>
        <option value="50">50 / page</option>
        <option value="100">100 / page</option>
      </select>
    </div>
  </div>
</div>

<!-- ===== Modale Ajout / Édition ===== -->
<div class="modal-scrim" id="compModal">
  <div class="modal">
    <div class="modal-head">
      <h3 id="compModalTitle">Nouvelle candidature</h3>
      <button class="close-x" data-close="compModal">✕</button>
    </div>
    <div class="modal-body">
      <form id="compForm">
        <input type="hidden" name="id" id="compId">
        <div class="form-grid">
          <div class="field full"><label>Nom de l'entreprise *</label>
            <div class="name-row"><input name="name" required autocomplete="off"></div>
            <div class="ac-list" id="nameAc" hidden></div>
          </div>
          <div class="field"><label>Poste / intitulé visé</label><input name="position" placeholder="Alternant développeur…"></div>
          <div class="field"><label>Secteur</label><input name="sector" placeholder="Informatique, BTP…"></div>
          <div class="field"><label>Contact</label><input name="contact_name" placeholder="Nom du recruteur"></div>
          <div class="field"><label>E-mail</label><input name="email" type="email"></div>
          <div class="field"><label>Téléphone</label><input name="phone"></div>
          <div class="field"><label>Site web</label><input name="website" placeholder="https://…"></div>
          <div class="field full"><label>Adresse</label><input name="address"></div>
          <div class="field"><label>Ville</label><input name="city"></div>
          <div class="field"><label>Code postal</label><input name="postal_code"></div>
          <div class="field">
            <label>Statut</label>
            <select name="status">
              <?php foreach ($statuses as $k=>$l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Priorité</label>
            <select name="priority"><option value="normale">Normale</option><option value="haute">Haute</option><option value="basse">Basse</option></select>
          </div>
          <div class="field">
            <label>Type de candidature</label>
            <select name="apply_channel" id="applyChannel">
              <option value="">—</option>
              <?php foreach (apply_channels() as $ch): ?>
              <option value="<?= e($ch) ?>"><?= e($ch) ?></option>
              <?php endforeach; ?>
              <option value="__custom">Autre…</option>
            </select>
            <input name="apply_channel_custom" id="applyChannelCustom" placeholder="Précisez le type" hidden style="margin-top:8px">
          </div>
          <div class="field"><label>Rémunération</label><input name="salary" placeholder="ex. 1 200 € brut"></div>
          <div class="field"><label>Date de candidature</label><input name="applied_date" type="date"></div>
          <div class="field"><label>Date de réponse</label><input name="response_date" type="date"></div>
          <div class="field"><label>Date d'entretien</label><input name="interview_date" type="date"></div>
          <div class="field"><label>Relance prévue le</label><input name="followup_date" type="date"></div>
          <div class="field full"><label>Notes</label><textarea name="notes" placeholder="Détails, échanges, points à retenir…"></textarea></div>
        </div>
      </form>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" data-close="compModal">Annuler</button>
      <button class="btn btn-primary" id="compSave">Enregistrer</button>
    </div>
  </div>
</div>

<!-- ===== Modale Import ===== -->
<div class="modal-scrim" id="importModal">
  <div class="modal">
    <div class="modal-head"><h3>Importer une liste d'entreprises</h3><button class="close-x" data-close="importModal">✕</button></div>
    <div class="modal-body">
      <p class="muted mb">Collez votre liste (une entreprise par ligne). Séparez les champs par une virgule ou un point-virgule, dans cet ordre :</p>
      <p class="otp-secret" style="letter-spacing:normal;font-size:12.5px">Nom ; Poste ; Ville ; E-mail ; Téléphone ; Statut</p>
      <div class="field mt"><label>Ou importez un fichier CSV</label><input type="file" id="importFile" accept=".csv,.txt"></div>
      <div class="field"><label>Liste à importer</label>
        <textarea id="importText" style="min-height:160px" placeholder="Google, Alternant data, Paris, rh@google.com, 0100000000, envoye&#10;OpenAI ; Développeur ; Lyon ; jobs@openai.com"></textarea>
      </div>
      <p class="hint">Statuts acceptés : <?= e(implode(', ', array_keys($statuses))) ?>. Par défaut : brouillon.</p>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" data-close="importModal">Annuler</button>
      <button class="btn btn-primary" id="importRun">Importer</button>
    </div>
  </div>
</div>

<!-- ===== Modale Détail ===== -->
<div class="modal-scrim" id="viewModal">
  <div class="modal">
    <div class="modal-head"><h3 id="viewTitle">Détail</h3><button class="close-x" data-close="viewModal">✕</button></div>
    <div class="modal-body" id="viewBody"></div>
    <div class="modal-foot"><button class="btn btn-ghost" data-close="viewModal">Fermer</button><button class="btn btn-primary" id="viewEdit">Modifier</button></div>
  </div>
</div>

<div class="modal-scrim" id="shareModal">
  <div class="modal" style="max-width:560px">
    <div class="modal-head"><h3>Partager en lecture seule</h3><button class="close-x" data-close="shareModal">✕</button></div>
    <div class="modal-body">
      <p class="muted" style="margin:0 0 16px;font-size:13.5px">
        Générez un lien à transmettre, par exemple au responsable de votre école ou de votre CFA.
        La page est en <strong>lecture seule</strong> : aucune modification n'y est possible, et vous pouvez révoquer le lien à tout moment.
      </p>

      <div class="field"><label>Intitulé (pour vous y retrouver)</label>
        <input id="shLabel" placeholder="ex. Justificatif — Mme Bernard, BTS SIO"></div>

      <div class="field"><label>Durée de validité</label>
        <select id="shExpires">
          <option value="7">7 jours</option>
          <option value="30" selected>30 jours</option>
          <option value="90">90 jours</option>
          <option value="0">Sans expiration</option>
        </select>
      </div>

      <div class="pdf-sec">
        <div class="pdf-sec-head"><strong>Statuts à inclure</strong>
          <button type="button" class="linkish" data-toggle-all="sh-st">Tout cocher / décocher</button></div>
        <div class="pdf-status-list">
          <?php foreach ($statuses as $k => $l): ?>
            <label class="chk"><input type="checkbox" class="sh-st" value="<?= e($k) ?>" checked> <?= e($l) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="pdf-sec">
        <div class="pdf-sec-head"><strong>Colonnes visibles</strong>
          <button type="button" class="linkish" data-toggle-all="sh-fd">Tout cocher / décocher</button></div>
        <div class="pdf-field-list">
          <?php
            require_once __DIR__ . '/../includes/share.php';
            $shDefaults = share_default_fields();
            $sensitive = ['contact_name', 'email', 'phone', 'notes'];
            foreach (pdf_fields_for_share() as $k => $l):
                if (in_array($k, $sensitive, true)) continue;
          ?>
            <label class="chk"><input type="checkbox" class="sh-fd" value="<?= e($k) ?>" <?= in_array($k, $shDefaults, true) ? 'checked' : '' ?>> <?= e($l) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="pdf-sec">
        <div class="pdf-sec-head"><strong>Données personnelles</strong></div>
        <p class="hint" style="margin:0 0 8px">Décochées par défaut : ces informations concernent des tiers.</p>
        <label class="chk" style="margin-bottom:6px"><input type="checkbox" id="shContact"> Afficher les coordonnées des contacts (nom, e-mail, téléphone)</label>
        <label class="chk"><input type="checkbox" id="shNotes"> Afficher mes notes personnelles</label>
      </div>

      <div id="shResult" hidden style="margin-top:18px">
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Lien généré</label>
        <div style="display:flex;gap:8px">
          <input id="shUrl" readonly style="flex:1;font-size:13px">
          <button class="btn btn-primary btn-sm" id="shCopy">Copier</button>
        </div>
      </div>

      <div class="pdf-sec" style="margin-top:20px">
        <div class="pdf-sec-head"><strong>Liens existants</strong></div>
        <div id="shList"><p class="hint">Chargement…</p></div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" data-close="shareModal">Fermer</button>
      <button class="btn btn-primary" id="shCreate">Générer le lien</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
