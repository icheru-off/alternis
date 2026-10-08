<?php
require_once __DIR__ . '/../includes/auth.php';
$__user = require_login();
$__owner = data_owner_id();
$__active = 'todo';
$__pageTitle = 'Offres à faire';
$__pageScripts = ['assets/js/todo.js'];

require __DIR__ . '/../includes/header.php';
?>

<div class="section-title">
  <div>
    <h2 style="font-size:22px">Offres à faire</h2>
    <p class="muted" style="margin-top:2px">Vos offres repérées, pas encore postulées. Une fois la candidature envoyée, elles rejoignent vos candidatures.</p>
  </div>
  <button class="btn btn-primary" id="todoNew">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    Ajouter une offre
  </button>
</div>

<div id="todoList" class="todo-list"><div class="sc-loading">Chargement…</div></div>

<!-- Modale ajout / édition -->
<div class="modal-scrim" id="todoModal">
  <div class="modal" style="max-width:520px">
    <div class="modal-head"><h3 id="todoModalTitle">Ajouter une offre</h3><button class="close-x" data-close="todoModal">✕</button></div>
    <div class="modal-body">
      <form id="todoForm">
        <input type="hidden" name="id">
        <div class="form-grid">
          <div class="field full"><label>Poste / intitulé *</label><input name="position" required placeholder="ex. Développeur web"></div>
          <div class="field full"><label>Entreprise *</label><input name="name" required placeholder="Nom de l'entreprise"></div>
          <div class="field full"><label>Lien vers l'offre</label><input name="website" type="url" placeholder="https://…"></div>
          <div class="field"><label>Ville</label><input name="city" placeholder="ex. Lyon"></div>
          <div class="field"><label>Type de candidature</label>
            <select name="apply_channel">
              <option value="">—</option>
              <option>Indeed</option><option>HelloWork</option><option>LinkedIn</option><option>Spontanée</option><option>Site entreprise</option>
            </select>
          </div>
          <div class="field full"><label>Notes</label><textarea name="notes" placeholder="Détails, date limite, contact…"></textarea></div>
        </div>
      </form>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" data-close="todoModal">Annuler</button>
      <button class="btn btn-primary" id="todoSave">Enregistrer</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
