/* Graphiques du tableau de bord */
(function () {
  let tries = 0;
  function boot() {
    if (!window.DASH) return;
    if (!window.Chart) { if (tries++ < 25) return setTimeout(boot, 120); return; }
    draw();
  }
  function draw() {
  const D = window.DASH;
  const css = getComputedStyle(document.documentElement);
  const text = css.getPropertyValue('--muted').trim() || '#6b7180';
  const grid = css.getPropertyValue('--border').trim() || '#e7e9f2';
  Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
  Chart.defaults.color = text;

  // --- Candidatures par mois (aire) ---
  const m = document.getElementById('chartMonths');
  if (m) {
    const ctx = m.getContext('2d');
    const g = ctx.createLinearGradient(0, 0, 0, 260);
    g.addColorStop(0, 'rgba(124,92,252,.35)');
    g.addColorStop(1, 'rgba(124,92,252,0)');
    new Chart(m, {
      type: 'line',
      data: {
        labels: D.months,
        datasets: [{
          data: D.monthsData, label: 'Candidatures',
          borderColor: '#7c5cfc', backgroundColor: g, fill: true,
          tension: .4, borderWidth: 3, pointRadius: 4,
          pointBackgroundColor: '#7c5cfc', pointBorderColor: '#fff', pointBorderWidth: 2
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0, stepSize: 1 }, grid: { color: grid, drawBorder: false } },
          x: { grid: { display: false } }
        }
      }
    });
  }

  // --- Répartition par statut (donut) ---
  const s = document.getElementById('chartStatus');
  if (s) {
    new Chart(s, {
      type: 'doughnut',
      data: { labels: D.statusLabels, datasets: [{ data: D.statusData, backgroundColor: D.statusColors, borderWidth: 0, hoverOffset: 8 }] },
      options: {
        responsive: true, maintainAspectRatio: false, cutout: '64%',
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, padding: 14, font: { size: 12 } } }
        }
      }
    });
  }
  }
  boot();
})();
