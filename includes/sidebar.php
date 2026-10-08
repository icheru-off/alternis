<?php
$nav = [
    'dashboard'  => ['Tableau de bord', '<path d="M3 13h8V3H3zM13 21h8V3h-8zM3 21h8v-6H3z" fill="currentColor"/>'],
    'companies'  => ['Candidatures', '<path d="M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'],
    'directory'  => ['Entreprises', '<path d="M3 21V7l6-4 6 4v14M9 21v-4h4v4M15 21V11l6 4v6M6 9h.01M6 12h.01M6 15h.01" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round"/>'],
    'todo'       => ['Offres à faire', '<path d="M9 11l3 3 8-8" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round"/>'],
    'followups'  => ['Relances', '<path d="M3 8l9 6 9-6" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.8" fill="none"/>'],
    'resources'  => ['Ressources', '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6z" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linejoin="round"/><path d="M14 3v6h6M9 13h6M9 17h6" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round"/>'],
    'settings'   => ['Paramètres', '<path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z" stroke="currentColor" stroke-width="1.8" fill="none"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.2A1.6 1.6 0 0 0 6.6 19l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0-1.1-2.7H2a2 2 0 1 1 0-4h.2A1.6 1.6 0 0 0 3.3 6.6l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H8a1.6 1.6 0 0 0 1-1.5V2a2 2 0 1 1 4 0v.2A1.6 1.6 0 0 0 15 3.3a1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V8a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.2a1.6 1.6 0 0 0-1.4 1z" stroke="currentColor" stroke-width="1.4" fill="none"/>'],
];
if (is_admin()) {
    $nav['admin'] = ['Administration', '<path d="M12 2 3 6v6c0 5 3.8 8.5 9 10 5.2-1.5 9-5 9-10V6z" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linejoin="round"/><path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'];
}
?>
<aside class="sidebar" id="sidebar">
  <div class="brand">
    <!-- Barre dépliée : logo complet (fond sombre -> version blanche) -->
    <img class="brand-logo" src="<?= e(url('assets/img/logo-light.png?v=' . ASSET_VERSION)) ?>" alt="<?= e(APP_NAME) ?>">
    <!-- Barre repliée : uniquement la tuile -->
    <img class="brand-mark-img" src="<?= e(url('assets/img/mark-512.png?v=' . ASSET_VERSION)) ?>" alt="" aria-hidden="true">
  </div>

  <nav class="nav">
    <?php foreach ($nav as $key => [$label, $icon]): ?>
      <a class="nav-item <?= $__active === $key ? 'active' : '' ?>"
         href="<?= e(url('index.php?page=' . $key)) ?>" data-nav="<?= e($key) ?>" data-tip="<?= e($label) ?>">
        <svg viewBox="0 0 24 24" width="20" height="20"><?= $icon ?></svg>
        <span><?= e($label) ?></span>
        <?php if ($key === 'todo' && !empty($__todoCount)): ?><span class="nav-badge"><?= (int)$__todoCount ?></span><?php endif; ?>
        <?php if ($key === 'followups' && !empty($__followupCount)): ?><span class="nav-badge"><?= (int)$__followupCount ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-foot">
    <a class="nav-item logout" href="<?= e(url('auth/logout.php')) ?>">
      <svg viewBox="0 0 24 24" width="20" height="20"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <span>Se déconnecter</span>
    </a>
  </div>
</aside>
<div class="sidebar-scrim" id="sidebarScrim"></div>
