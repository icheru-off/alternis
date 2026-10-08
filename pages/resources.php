<?php
require_once __DIR__ . '/../includes/auth.php';
$__user = require_login();
$__owner = data_owner_id();
$__active = 'resources';
$__pageTitle = 'Ressources';
$__pageScripts = ['assets/js/resources.js'];

require __DIR__ . '/../includes/header.php';
?>

<div class="section-title">
  <div>
    <h2 style="font-size:22px">Mes ressources</h2>
    <p class="muted" style="margin-top:2px">Conservez votre CV et votre lettre de motivation au même endroit, prêts à être réutilisés.</p>
  </div>
</div>

<input type="file" id="resFile" hidden accept=".pdf,.doc,.docx,.odt,.rtf,.txt,.jpg,.jpeg,.png">

<div class="grid mb" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px">

  <!-- CV -->
  <div class="card card-pad res-slot" data-reveal>
    <div class="res-head">
      <span class="res-badge res-cv">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M14 3v6h6" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
      </span>
      <div><h3>Curriculum Vitæ</h3><p class="muted">Votre CV à jour, au format PDF de préférence.</p></div>
    </div>
    <div class="res-body" id="cvSlot"><div class="res-loading">Chargement…</div></div>
  </div>

  <!-- Lettre de motivation -->
  <div class="card card-pad res-slot" data-reveal>
    <div class="res-head">
      <span class="res-badge res-lettre">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none"><path d="M4 5h16v14H4z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="m4 6 8 6 8-6" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
      </span>
      <div><h3>Lettre de motivation</h3><p class="muted">Un modèle de base que vous adapterez à chaque candidature.</p></div>
    </div>
    <div class="res-body" id="lettreSlot"><div class="res-loading">Chargement…</div></div>
  </div>
</div>

<!-- Autres documents -->
<div class="card" data-reveal>
  <div class="section-title" style="padding:20px 22px 0">
    <h2>Autres documents</h2>
    <button class="btn btn-ghost btn-sm" id="addOther">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      Ajouter un document
    </button>
  </div>
  <div class="res-others" id="othersList"><div class="res-loading" style="padding:22px">Chargement…</div></div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
