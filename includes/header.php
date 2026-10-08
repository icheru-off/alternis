<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notifications.php';
$__user = require_login();
$__owner = data_owner_id();
$__pageTitle = $__pageTitle ?? APP_NAME;
$__active = $__active ?? 'dashboard';
// Livre les notifications groupées en attente dès l'ouverture d'une page.
if (function_exists('flush_company_add_notifications')) { flush_company_add_notifications(); }
$__unread = unread_count((int)$__user['id']);
// Préférences par utilisateur (autocomplétion, onboarding, etc.)
$__prefs = [];
try { $__prefs = user_prefs_all((int)$__user['id']); } catch (Throwable $e) { $__prefs = []; }
// Nombre d'offres à faire (statut à postuler) pour le badge de menu
$__todoCount = 0;
try {
    $ts = db()->prepare("SELECT COUNT(*) FROM companies WHERE owner_id=? AND status='a_postuler'");
    $ts->execute([$__owner]);
    $__todoCount = (int)$ts->fetchColumn();
} catch (Throwable $e) { $__todoCount = 0; }

// Nombre de relances à faire, pour la pastille du menu
$__followupCount = 0;
try {
    require_once __DIR__ . '/followups.php';
    $__followupCount = followups_count($__owner, (int)$__user['id']);
} catch (Throwable $e) { $__followupCount = 0; }

// Détermine le prénom d'affichage du propriétaire des données
$__ownerName = $__user['full_name'] ?: $__user['username'];
if ($__owner != $__user['id']) {
    $os = db()->prepare('SELECT full_name, username FROM users WHERE id = ?');
    $os->execute([$__owner]);
    if ($or = $os->fetch()) {
        $__ownerName = $or['full_name'] ?: $or['username'];
    }
}
$__firstName = preg_split('/\s+/', trim($__ownerName))[0] ?? $__ownerName;
?>
<!DOCTYPE html>
<html lang="fr" data-theme="<?= e($__user['theme_pref']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f1220" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#f6f7fb" media="(prefers-color-scheme: light)">
<title><?= e($__pageTitle) ?> · <?= e(APP_NAME) ?></title>
<link rel="manifest" href="<?= e(url('manifest.php')) ?>">
<link rel="icon" href="<?= e(url('assets/img/favicon-32.png?v=' . ASSET_VERSION)) ?>" sizes="32x32" type="image/png">
<link rel="icon" href="<?= e(url('assets/img/favicon-64.png?v=' . ASSET_VERSION)) ?>" sizes="64x64" type="image/png">
<link rel="apple-touch-icon" href="<?= e(url('assets/img/icon-180.png?v=' . ASSET_VERSION)) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css?v=' . ASSET_VERSION)) ?>">
<script>
// Applique la préférence ('auto' laisse le système décider via CSS)
(function(){var t=document.documentElement.getAttribute('data-theme');
 if(t==='auto'){document.documentElement.removeAttribute('data-theme');}})();
window.ALT = {
  base: <?= json_encode(url('')) ?>,
  csrf: <?= json_encode(csrf_token()) ?>,
  user: <?= json_encode(['id'=>(int)$__user['id'],'name'=>$__firstName,'role'=>$__user['role']]) ?>,
  title: <?= json_encode(($__pageTitle ?? APP_NAME) . ' · ' . APP_NAME) ?>,
  appName: <?= json_encode(APP_NAME) ?>,
  counters: {
    notifications: <?= (int)$__unread ?>,
    followups: <?= (int)$__followupCount ?>,
    todo: <?= (int)$__todoCount ?>
  },
  prefs: <?= json_encode($__prefs, JSON_UNESCAPED_UNICODE) ?>
};
</script>
</head>
<body>
<script>try{if(localStorage.getItem('alt_sidebar')==='collapsed')document.body.classList.add('sidebar-collapsed');}catch(e){}</script>
<div class="app-shell">
<?php require __DIR__ . '/sidebar.php'; ?>
<div class="main">
  <header class="topbar">
    <button class="icon-btn menu-toggle" id="menuToggle" aria-label="Ouvrir le menu">
      <svg viewBox="0 0 24 24" width="22" height="22"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>
    <div class="topbar-title">
      <span class="eyebrow"><?= e(ucfirst(french_date())) ?></span>
      <h1><?= e($__pageTitle) ?></h1>
    </div>
    <div class="topbar-actions">
      <?php if ($__owner != $__user['id']): ?>
        <span class="viewing-badge" title="Vous consultez les données de <?= e($__ownerName) ?>">
          <span class="vb-label">Vue : </span><?= e($__firstName) ?>
        </span>
        <?php if (!empty($_SESSION['collab_owner'])): ?>
          <button class="btn btn-ghost btn-sm" id="collabExitBtn" style="margin-right:6px">Revenir à moi</button>
          <script>
          (function(){ var b=document.getElementById('collabExitBtn'); if(!b)return;
            b.onclick=async function(){ try{ await window.altFetch('api/collab.php?action=switch',{json:{owner_id:0}});
              location.href=window.altApi('index.php?page=dashboard'); }catch(e){} }; })();
          </script>
        <?php endif; ?>
      <?php endif; ?>
      <div class="notif-wrap">
        <button class="icon-btn" id="notifBtn" aria-label="Notifications">
          <svg viewBox="0 0 24 24" width="22" height="22"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span class="notif-dot" id="notifDot"<?= $__unread ? '' : ' hidden' ?>><?= $__unread ?></span>
        </button>
        <div class="notif-panel" id="notifPanel" hidden>
          <div class="notif-head">
            <strong>Notifications</strong>
            <div class="notif-head-actions">
              <button class="link-btn" id="notifMarkAll">Tout marquer comme lu</button>
              <button class="link-btn link-danger" id="notifClearAll">Tout effacer</button>
            </div>
          </div>
          <div class="notif-list" id="notifList"><div class="notif-empty">Chargement…</div></div>
        </div>
      </div>
      <a class="avatar-link" href="<?= e(url('index.php?page=settings')) ?>" title="Mon profil">
        <?php if ($__user['avatar']): ?>
          <img src="<?= e(url('api/avatar.php?u=' . (int)$__user['id'] . '&v=' . rawurlencode($__user['avatar']))) ?>" alt="">
        <?php else: ?>
          <span class="avatar-fallback"><?= e(mb_strtoupper(mb_substr($__firstName,0,1))) ?></span>
        <?php endif; ?>
      </a>
      <a class="icon-btn logout-mobile" href="<?= e(url('auth/logout.php')) ?>" title="Se déconnecter" aria-label="Se déconnecter">
        <svg viewBox="0 0 24 24" width="21" height="21" fill="none"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
    </div>
  </header>
  <main class="content">
<?php
$__messages = function_exists('active_messages_for') ? active_messages_for((int)$__user['id']) : [];
foreach ($__messages as $__m):
    $__mc = ['accent' => 'accent', 'info' => 'info', 'warn' => 'warn', 'danger' => 'danger', 'success' => 'success'][$__m['color']] ?? 'accent';
    $__dis = !array_key_exists('dismissible', $__m) || (int)$__m['dismissible'] === 1;
    if ($__m['kind'] === 'banner'):
?>
    <div class="admin-banner ac-<?= e($__mc) ?>" data-msg="<?= (int)$__m['id'] ?>">
      <div class="ab-txt"><?php if ($__m['title']): ?><strong><?= e($__m['title']) ?></strong> <?php endif; ?><span><?= nl2br(e($__m['body'])) ?></span></div>
      <?php if ($__dis): ?><button class="ab-close" data-msg="<?= (int)$__m['id'] ?>" aria-label="Fermer">✕</button><?php endif; ?>
    </div>
<?php else: ?>
    <div class="modal-scrim admin-popup<?= $__dis ? '' : ' no-dismiss' ?>" data-msg="<?= (int)$__m['id'] ?>" style="display:flex">
      <div class="modal msg-modal">
        <div class="modal-head"><h3><?= e($__m['title'] ?: 'Information') ?></h3></div>
        <div class="modal-body msg-body"><p><?= nl2br(e($__m['body'])) ?></p></div>
        <?php if ($__dis): ?>
        <div class="modal-foot"><button class="btn btn-primary ab-close" data-msg="<?= (int)$__m['id'] ?>">J'ai compris</button></div>
        <?php endif; ?>
      </div>
    </div>
<?php endif; endforeach; ?>
