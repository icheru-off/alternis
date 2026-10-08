<?php
/**
 * Notes de version : slider affiché UNE fois par utilisateur et par salve de
 * notes (RELEASE_ID). On ne l'affiche pas aux tout nouveaux comptes (le
 * tutoriel d'accueil s'en charge) ni pendant l'onboarding.
 */
require_once __DIR__ . '/release_notes.php';

// Ne pas cumuler avec le tutoriel d'accueil.
if (!empty($__showOnboarding)) return;
if (isset($_GET['onboarding'])) return;

$rn = release_notes();
$already = false;
$isNew = false;
try {
    $seen = user_pref_get((int)$__user['id'], 'seen_release', '');
    $already = ($seen === $rn['id']);
    // compte tout neuf : on laisse d'abord passer l'onboarding, pas les notes
    $ob = user_pref_get((int)$__user['id'], 'onboarding_done', '1');
    $isNew = ($ob === '0');
} catch (Throwable $e) { $already = false; }

if ($already || $isNew) return;
?>
<div id="wnRoot" data-release="<?= e($rn['id']) ?>"></div>
<script>
(function () {
  var slides = <?= json_encode($rn['slides'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var releaseId = <?= json_encode($rn['id']) ?>;
  var version = <?= json_encode($rn['version']) ?>;
  if (!slides || !slides.length) return;

  function ready(fn) {
    if (window.altFetch) return fn();
    var t = 0, iv = setInterval(function () { if (window.altFetch || t++ > 60) { clearInterval(iv); fn(); } }, 50);
  }

  function markSeen() {
    try { window.altFetch('api/profile.php?action=pref', { json: { key: 'seen_release', value: releaseId } }); } catch (e) {}
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  ready(function () {
    var i = 0;
    var scrim = document.createElement('div');
    scrim.className = 'wn-scrim';
    scrim.innerHTML =
      '<div class="wn-modal" role="dialog" aria-modal="true" aria-label="Nouveautés">' +
        '<div class="wn-hero" id="wnHero"></div>' +
        '<div class="wn-body" id="wnBody"></div>' +
        '<div class="wn-foot">' +
          '<div class="wn-dots" id="wnDots"></div>' +
          '<div class="wn-actions">' +
            '<button class="wn-skip" id="wnSkip">Passer</button>' +
            '<button class="btn btn-primary btn-sm" id="wnNext">Suivant</button>' +
          '</div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(scrim);
    requestAnimationFrame(function () { scrim.classList.add('in'); });

    var hero = scrim.querySelector('#wnHero');
    var body = scrim.querySelector('#wnBody');
    var dots = scrim.querySelector('#wnDots');
    var nextBtn = scrim.querySelector('#wnNext');
    var skipBtn = scrim.querySelector('#wnSkip');

    dots.innerHTML = slides.map(function (_, k) { return '<span class="wn-dot' + (k === 0 ? ' on' : '') + '" data-k="' + k + '"></span>'; }).join('');

    function paint() {
      var s = slides[i], last = i === slides.length - 1;
      hero.style.background = s.grad || 'var(--grad)';
      hero.innerHTML = '<span class="wn-emoji">' + (s.icon || '✨') + '</span>';
      body.innerHTML =
        (s.tag ? '<span class="wn-tag">' + esc(s.tag) + '</span>' : '') +
        '<h2 class="wn-title">' + esc(s.title) + '</h2>' +
        '<p class="wn-text">' + esc(s.body) + '</p>';
      Array.prototype.forEach.call(dots.children, function (d, k) { d.classList.toggle('on', k === i); });
      nextBtn.textContent = last ? 'Découvrir' : 'Suivant';
    }

    function close() {
      markSeen();
      scrim.classList.remove('in');
      setTimeout(function () { if (scrim.parentNode) scrim.parentNode.removeChild(scrim); }, 300);
    }

    nextBtn.addEventListener('click', function () { if (i === slides.length - 1) close(); else { i++; paint(); } });
    skipBtn.addEventListener('click', close);
    dots.addEventListener('click', function (e) { var k = e.target.getAttribute('data-k'); if (k !== null) { i = +k; paint(); } });
    scrim.addEventListener('click', function (e) { if (e.target === scrim) close(); });
    document.addEventListener('keydown', function onKey(e) {
      if (!scrim.parentNode) { document.removeEventListener('keydown', onKey); return; }
      if (e.key === 'Escape') close();
      else if (e.key === 'ArrowRight' && i < slides.length - 1) { i++; paint(); }
      else if (e.key === 'ArrowLeft' && i > 0) { i--; paint(); }
    });

    paint();
  });
})();
</script>
