<?php
/**
 * Page d'acceptation d'une invitation de collaboration (?t=token).
 * Enveloppée du header/footer de l'app pour avoir les styles et app.js.
 */
$__pageTitle = 'Rejoindre une recherche';
$__active = '';

require __DIR__ . '/../includes/header.php';

$token = trim($_GET['t'] ?? '');
$invite = null;
$err = '';
if ($token !== '') {
    try {
        ensure_accounts_tables();
        $st = db()->prepare('SELECT ci.*, u.full_name, u.username
                             FROM collab_invites ci JOIN users u ON u.id = ci.owner_id
                             WHERE ci.token = ? AND ci.revoked = 0 LIMIT 1');
        $st->execute([$token]);
        $invite = $st->fetch();
        if (!$invite) $err = 'Cette invitation est invalide ou a été révoquée.';
        elseif ((int)$invite['owner_id'] === (int)$__user['id']) $err = "Il s'agit de votre propre espace.";
        elseif (!empty($invite['expires_at']) && strtotime($invite['expires_at']) < time()) $err = 'Cette invitation a expiré.';
    } catch (Throwable $e) { $err = 'Erreur lors de la lecture de l\'invitation.'; }
} else {
    $err = 'Lien d\'invitation incomplet.';
}
?>
<div class="page-narrow" style="max-width:520px;margin:40px auto">
  <div class="card card-pad" style="text-align:center">
    <div style="font-size:46px;margin-bottom:8px">🤝</div>
    <?php if ($err): ?>
      <h2>Invitation indisponible</h2>
      <p class="muted"><?= e($err) ?></p>
      <a class="btn btn-ghost" href="<?= e(url('index.php?page=dashboard')) ?>" style="margin-top:14px">Retour au tableau de bord</a>
    <?php else: ?>
      <h2>Rejoindre la recherche de <?= e($invite['full_name'] ?: $invite['username']) ?></h2>
      <p class="muted" style="margin-top:6px">
        Vous êtes invité·e à <strong><?= ((int)$invite['can_edit'] === 1) ? 'consulter et modifier' : 'consulter' ?></strong>
        les recherches de cette personne.
        <?php if ((int)$invite['can_edit'] === 1): ?><br>Vous pourrez ajouter et modifier ses candidatures.<?php else: ?><br>Vous pourrez tout voir, sans rien modifier.<?php endif; ?>
      </p>
      <div style="display:flex;gap:10px;justify-content:center;margin-top:20px">
        <a class="btn btn-ghost" href="<?= e(url('index.php?page=dashboard')) ?>">Refuser</a>
        <button class="btn btn-primary" id="collabAcceptBtn" data-token="<?= e($token) ?>">Accepter l'invitation</button>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php
// Script d'acceptation, chargé APRÈS app.js grâce à $__inlineScript (footer).
$__inlineScript = <<<'JS'
(function () {
  var b = document.getElementById('collabAcceptBtn');
  if (!b) return;
  b.addEventListener('click', async function () {
    b.disabled = true;
    try {
      const d = await window.altFetch('api/collab.php?action=accept', { json: { token: b.dataset.token } });
      await window.altFetch('api/collab.php?action=switch', { json: { owner_id: d.owner_id } });
      if (window.toast) window.toast('Vous avez rejoint cette recherche.', 'ok');
      setTimeout(function () { location.href = window.altApi('index.php?page=dashboard'); }, 600);
    } catch (e) {
      if (window.toast) window.toast((e && e.error) || 'Impossible d\'accepter l\'invitation.', 'err');
      else alert((e && e.error) || 'Impossible d\'accepter l\'invitation.');
      b.disabled = false;
    }
  });
})();
JS;
require __DIR__ . '/../includes/footer.php';
