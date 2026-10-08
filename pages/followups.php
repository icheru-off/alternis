<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/followups.php';
$__user = require_login();
$__owner = data_owner_id();
$__active = 'followups';
$__pageTitle = 'Relances';
$__pageScripts = ['assets/js/followups.js'];
$__inlineScript = 'window.FU_DELAY=' . followup_delay_days((int)$__user['id']) . ';';
require __DIR__ . '/../includes/header.php';
?>

<div class="section-title">
  <div>
    <h2 style="font-size:22px">Relances à faire</h2>
    <p class="muted" style="margin-top:2px">
      Les candidatures envoyées depuis plus de <strong id="delayLabel"><?= followup_delay_days((int)$__user['id']) ?></strong> jours, restées sans réponse.
    </p>
  </div>
  <button class="btn btn-ghost btn-sm" id="fuSettings">Réglages</button>
</div>

<div class="card card-pad" data-reveal style="margin-bottom:16px">
  <p class="hint" style="margin:0">
    Alternis n'envoie jamais d'e-mail à votre place sans validation. Chaque message vous est proposé en brouillon :
    vous le relisez, le modifiez, puis décidez de l'envoyer ou simplement de le copier.
  </p>
</div>

<div id="fuList"><div class="sc-loading">Chargement…</div></div>

<!-- Brouillon de relance -->
<div class="modal-scrim" id="fuModal">
  <div class="modal" style="max-width:640px">
    <div class="modal-head"><h3 id="fuTitle">Relancer</h3><button class="close-x" data-close="fuModal">✕</button></div>
    <div class="modal-body">
      <input type="hidden" id="fuId">
      <div class="fu-variants" id="fuVariants">
        <button type="button" class="chip active" data-variant="1">Première relance</button>
        <button type="button" class="chip" data-variant="2">Dernière relance</button>
        <button type="button" class="chip" data-variant="interview">Après entretien</button>
      </div>
      <div class="field"><label>Destinataire</label><input id="fuTo" type="email" placeholder="recruteur@entreprise.fr"></div>
      <div class="field"><label>Objet</label><input id="fuSubject"></div>
      <div class="field"><label>Message</label><textarea id="fuBody" rows="14" style="min-height:280px;font-family:inherit"></textarea></div>
      <p class="hint" id="fuReply"></p>
    </div>
    <div class="modal-foot" style="justify-content:space-between">
      <button class="btn btn-ghost" id="fuCopy">Copier le texte</button>
      <div style="display:flex;gap:10px">
        <button class="btn btn-ghost" id="fuMark">J'ai relancé moi-même</button>
        <button class="btn btn-primary" id="fuSend">Envoyer</button>
      </div>
    </div>
  </div>
</div>

<!-- Réglages -->
<div class="modal-scrim" id="fuSetModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-head"><h3>Réglages des relances</h3><button class="close-x" data-close="fuSetModal">✕</button></div>
    <div class="modal-body">
      <div class="field"><label>Proposer une relance après (jours)</label>
        <input type="number" id="fuDelay" min="3" max="60" value="<?= followup_delay_days((int)$__user['id']) ?>"></div>
      <p class="hint">Entre 3 et 60 jours. Dix jours est un délai d'usage courant.</p>
    </div>
    <div class="modal-foot"><button class="btn btn-ghost" data-close="fuSetModal">Annuler</button>
      <button class="btn btn-primary" id="fuSetSave">Enregistrer</button></div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
