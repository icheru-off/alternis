/* Alternis — Service Worker (PWA + notifications) */
const CACHE = 'alternis-v46';
const ASSETS = [
  'assets/css/style.css',
  'assets/js/app.js',
  'assets/js/chart.umd.js',
  'assets/img/icon-192.png',
  'assets/img/favicon-32.png'
];

self.addEventListener('install', (e) => {
  self.skipWaiting();
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(ASSETS.map((a) => new Request(a, { cache: 'reload' }))).catch(() => {})));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))));
  self.clients.claim();
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  // Ne pas mettre en cache l'API ni les pages dynamiques
  if (url.pathname.includes('/api/') || url.pathname.includes('/export/') || url.search.includes('page=')) return;
  // Cache-first pour les assets statiques
  if (/\.(css|js|png|svg|woff2?)$/.test(url.pathname)) {
    e.respondWith(caches.match(req).then((r) => r || fetch(req).then((res) => {
      const copy = res.clone();
      caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
      return res;
    }).catch(() => r)));
  }
});

// Clic sur une notification -> ouvre (ou refocalise) la bonne page
self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const target = (e.notification.data && e.notification.data.url) || 'index.php?page=dashboard';
  e.waitUntil((async () => {
    const url = new URL(target, self.registration.scope).href;
    const list = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of list) {
      if ('focus' in c) {
        if ('navigate' in c && c.url !== url) { try { await c.navigate(url); } catch (err) {} }
        return c.focus();
      }
    }
    if (clients.openWindow) return clients.openWindow(url);
  })());
});

// Web Push : reçu même quand l'application est fermée
self.addEventListener('push', (e) => {
  let data = { title: 'Alternis', body: 'Vous avez une nouvelle notification.' };
  try { if (e.data) data = e.data.json(); } catch (err) {
    try { data.body = e.data.text(); } catch (err2) {}
  }
  const opts = {
    body: data.body || '',
    icon: 'assets/img/icon-192.png',
    badge: 'assets/img/icon-192.png',
    tag: data.tag || ('alternis-' + Date.now()),
    renotify: true,
    data: { url: data.url || 'index.php?page=dashboard' }
  };
  e.waitUntil(self.registration.showNotification(data.title || 'Alternis', opts));
});

// Le service push peut demander un réabonnement
self.addEventListener('pushsubscriptionchange', (e) => {
  e.waitUntil((async () => {
    try {
      const kd = await fetch('api/push.php?action=key').then((r) => r.json());
      if (!kd || !kd.key) return;
      const pad = '='.repeat((4 - (kd.key.length % 4)) % 4);
      const b64 = (kd.key + pad).replace(/-/g, '+').replace(/_/g, '/');
      const raw = self.atob(b64);
      const key = new Uint8Array(raw.length);
      for (let i = 0; i < raw.length; i++) key[i] = raw.charCodeAt(i);
      const sub = await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
      await fetch('api/push.php?action=subscribe', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(sub.toJSON())
      });
    } catch (err) {}
  })());
});
