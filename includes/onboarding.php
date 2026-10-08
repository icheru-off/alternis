<?php
/**
 * Tutoriel guidé (spotlight). Affiché à la première connexion (ou via
 * ?onboarding=1). Il met en surbrillance de vrais éléments de l'interface,
 * accompagne l'utilisateur pas à pas pour créer une première candidature avec
 * des informations fictives, et reste ignorable à tout moment.
 *
 * Ne s'affiche que sur la page « Candidatures » (là où se trouvent les cibles).
 * Si on demande le tuto depuis ailleurs, on redirige vers Candidatures.
 */
$__showOnboarding = false;
try {
    $done = user_pref_get((int)$__user['id'], 'onboarding_done', null);
    if (isset($_GET['onboarding'])) $__showOnboarding = true;
    elseif ($done === '0') $__showOnboarding = true;
} catch (Throwable $e) { $__showOnboarding = false; }

if (!$__showOnboarding) return;

// Le tuto guide l'ajout d'une candidature : il lui faut la page Candidatures.
$__onbPage = $__active ?? ($_GET['page'] ?? 'dashboard');
if ($__onbPage !== 'companies') {
    $sep = strpos(url('index.php?page=companies'), '?') !== false ? '&' : '?';
    echo '<script>location.replace(' . json_encode(url('index.php?page=companies') . $sep . 'onboarding=1') . ');</script>';
    return;
}
?>
<div id="gtRoot" aria-hidden="true"></div>
<script>
(function () {
  'use strict';
  var doneUrl = 'api/profile.php?action=pref';

  // Marque le tuto comme vu (et nettoie l'URL).
  function markDone() {
    try {
      window.altFetch(doneUrl, { json: { key: 'onboarding_done', value: '1' } });
      if (window.ALT && window.ALT.prefs) window.ALT.prefs.onboarding_done = '1';
    } catch (e) {}
    if (location.search.indexOf('onboarding') !== -1 && history.replaceState) {
      history.replaceState(null, '', location.pathname);
    }
  }

  // Étapes du parcours. Chaque étape cible un élément réel (sélecteur), affiche
  // une bulle, et peut exécuter une action avant de s'afficher (before) ou
  // remplir un champ (fill).
  var STEPS = [
    {
      sel: '[data-nav="companies"]',
      title: 'Vos candidatures',
      body: "Tout commence ici. « Candidatures » regroupe chacune de vos démarches, avec son statut et ses dates. C'est votre tableau de bord au quotidien.",
      place: 'right'
    },
    {
      sel: '#btnNew',
      title: 'Ajouter une candidature',
      body: "Cliquez sur « Nouvelle entreprise » pour créer une fiche. Faisons-le ensemble avec un exemple.",
      place: 'bottom',
      spotlightClick: true
    },
    {
      before: function () { if (window.openModal) window.openModal('compModal'); },
      sel: '#compForm [name="name"]',
      title: "Le nom de l'entreprise",
      body: "Saisissez l'entreprise visée. Le nom se complète automatiquement pendant que vous tapez. Exemple : Capgemini.",
      place: 'bottom',
      fill: { sel: '#compForm [name="name"]', value: 'Capgemini' }
    },
    {
      sel: '#compForm [name="position"]',
      title: 'Le poste visé',
      body: "Indiquez l'intitulé du poste. Ici : Alternant développeur.",
      place: 'bottom',
      fill: { sel: '#compForm [name="position"]', value: 'Alternant développeur' },
      optional: true
    },
    {
      sel: '#compForm [name="status"]',
      title: 'Le statut',
      body: "Suivez l'avancée : à postuler, envoyé, relancé, entretien, accepté… Choisissez « Envoyé » pour cet exemple.",
      place: 'top',
      selectValue: { sel: '#compForm [name="status"]', value: 'envoye' },
      optional: true
    },
    {
      sel: '#compSave',
      title: 'Enregistrez',
      body: "Cliquez sur « Enregistrer » et votre première candidature apparaît dans la liste. À vous de jouer !",
      place: 'top'
    }
  ];

  var i = 0, root = document.getElementById('gtRoot');
  var maskT, maskR, maskB, maskL, ring, pop, skipBtn;

  function el(cls, parent) { var d = document.createElement('div'); d.className = cls; (parent || document.body).appendChild(d); return d; }

  function buildChrome() {
    maskT = el('gt-mask'); maskR = el('gt-mask'); maskB = el('gt-mask'); maskL = el('gt-mask');
    ring = el('gt-ring');
    pop = el('gt-pop');
    skipBtn = document.createElement('button');
    skipBtn.className = 'gt-skip'; skipBtn.textContent = 'Ignorer le tuto';
    skipBtn.onclick = finish;
    document.body.appendChild(skipBtn);
  }

  function removeChrome() {
    [maskT, maskR, maskB, maskL, ring, pop, skipBtn].forEach(function (n) { if (n && n.parentNode) n.parentNode.removeChild(n); });
  }

  function finish() { removeChrome(); markDone(); }

  // Positionne le voile en 4 rectangles autour de la cible (effet spotlight)
  function positionMask(r) {
    var pad = 6, W = window.innerWidth, H = window.innerHeight;
    var x = r.left - pad, y = r.top - pad, w = r.width + pad * 2, h = r.height + pad * 2;
    maskT.style.cssText = 'left:0;top:0;width:100%;height:' + Math.max(0, y) + 'px';
    maskB.style.cssText = 'left:0;top:' + (y + h) + 'px;width:100%;height:' + Math.max(0, H - y - h) + 'px';
    maskL.style.cssText = 'left:0;top:' + y + 'px;width:' + Math.max(0, x) + 'px;height:' + h + 'px';
    maskR.style.cssText = 'left:' + (x + w) + 'px;top:' + y + 'px;width:' + Math.max(0, W - x - w) + 'px;height:' + h + 'px';
    ring.style.cssText = 'left:' + x + 'px;top:' + y + 'px;width:' + w + 'px;height:' + h + 'px';
  }

  function positionPop(r, place) {
    var gap = 18, W = window.innerWidth, H = window.innerHeight;
    var pw = pop.offsetWidth, ph = pop.offsetHeight, x, y;
    if (place === 'right') { x = r.right + gap; y = r.top + r.height / 2 - ph / 2; }
    else if (place === 'top') { x = r.left + r.width / 2 - pw / 2; y = r.top - ph - gap; }
    else if (place === 'bottom') { x = r.left + r.width / 2 - pw / 2; y = r.bottom + gap; }
    else { x = r.left - pw - gap; y = r.top + r.height / 2 - ph / 2; }
    // garde-fous pour rester dans l'écran
    x = Math.max(12, Math.min(x, W - pw - 12));
    y = Math.max(12, Math.min(y, H - ph - 12));
    pop.style.left = x + 'px'; pop.style.top = y + 'px';
  }

  function render() {
    var step = STEPS[i];
    if (!step) { finish(); return; }

    // action préalable (ouvrir la modale par ex.)
    if (step.before) { try { step.before(); } catch (e) {} }

    setTimeout(function () {
      var target = document.querySelector(step.sel);
      // si la cible est absente et l'étape est optionnelle, on saute
      if (!target) {
        if (step.optional) { i++; render(); return; }
        // sinon on retente une fois puis on avance
        setTimeout(function () {
          target = document.querySelector(step.sel);
          if (!target) { i++; render(); return; }
          paint(step, target);
        }, 350);
        return;
      }
      paint(step, target);
    }, step.before ? 260 : 30);
  }

  function paint(step, target) {
    try { target.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {}
    setTimeout(function () {
      var r = target.getBoundingClientRect();
      positionMask(r);

      // pré-remplissage éventuel (montre l'exemple concret)
      if (step.fill) {
        var f = document.querySelector(step.fill.sel);
        if (f && !f.value) { f.value = step.fill.value; f.dispatchEvent(new Event('input', { bubbles: true })); }
      }
      if (step.selectValue) {
        var s = document.querySelector(step.selectValue.sel);
        if (s) { s.value = step.selectValue.value; s.dispatchEvent(new Event('change', { bubbles: true })); }
      }

      // contenu de la bulle
      var last = i === STEPS.length - 1;
      var dots = STEPS.map(function (_, k) { return '<span class="gt-dot' + (k === i ? ' on' : '') + '"></span>'; }).join('');
      pop.innerHTML =
        '<div class="gt-step">Étape ' + (i + 1) + ' / ' + STEPS.length + '</div>' +
        '<h3>' + step.title + '</h3>' +
        '<p>' + step.body + '</p>' +
        '<div class="gt-foot"><div class="gt-dots">' + dots + '</div>' +
        '<div class="gt-btns">' +
        (i > 0 ? '<button class="btn btn-ghost btn-sm" id="gtPrev">Précédent</button>' : '') +
        '<button class="btn btn-primary btn-sm" id="gtNext">' + (last ? 'Terminer' : 'Suivant') + '</button>' +
        '</div></div>';
      positionPop(r, step.place || 'bottom');

      var nx = document.getElementById('gtNext'), pv = document.getElementById('gtPrev');
      if (nx) nx.onclick = function () { if (last) finish(); else { i++; render(); } };
      if (pv) pv.onclick = function () { if (i > 0) { i--; render(); } };
    }, 260);
  }

  // repositionne si la fenêtre bouge
  function onResize() {
    var step = STEPS[i]; if (!step) return;
    var t = document.querySelector(step.sel); if (!t) return;
    var r = t.getBoundingClientRect(); positionMask(r); positionPop(r, step.place || 'bottom');
  }
  window.addEventListener('resize', onResize);
  window.addEventListener('scroll', onResize, true);

  // ---- Écran d'introduction ----
  function intro() {
    var scrim = document.createElement('div');
    scrim.className = 'gt-intro';
    scrim.innerHTML =
      '<div class="gt-intro-card">' +
      '<div class="gt-emoji">👋</div>' +
      '<h2>Bienvenue sur Alternis</h2>' +
      '<p>En moins d\'une minute, on vous montre comment ajouter votre première candidature. Vous pouvez ignorer ce guide à tout moment.</p>' +
      '<div class="gt-intro-actions">' +
      '<button class="btn btn-ghost" id="gtIntroSkip">Ignorer</button>' +
      '<button class="btn btn-primary" id="gtIntroStart">C\'est parti →</button>' +
      '</div></div>';
    document.body.appendChild(scrim);
    document.getElementById('gtIntroStart').onclick = function () {
      scrim.parentNode.removeChild(scrim); buildChrome(); render();
    };
    document.getElementById('gtIntroSkip').onclick = function () {
      scrim.parentNode.removeChild(scrim); markDone();
    };
  }

  // Démarre après le chargement complet (les scripts de page doivent être prêts)
  if (document.readyState === 'complete') setTimeout(intro, 400);
  else window.addEventListener('load', function () { setTimeout(intro, 400); });
})();
</script>
