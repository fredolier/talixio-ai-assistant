// Service worker (served by PHP at /sw.js with the version filled in).
// - the app opens instantly, also offline (the app shell is cached; API calls always go to the server)
// - push notifications, with a badge on the app icon
// - a new version is installed in the background; the page offers to reload
const VERSION = '__VERSION__';
const CACHE = 'talixio-' + VERSION;
const SHELL = ['/', '/app.css', '/app.js', '/manifest.json', '/icon.svg', '/icons/icon-192.png', '/icons/badge-96.png'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL.map(u => new Request(u, { cache: 'reload' })))));
});
self.addEventListener('activate', e => e.waitUntil((async () => {
  for (const k of await caches.keys()) if (k.startsWith('talixio-') && k !== CACHE) await caches.delete(k);
  await self.clients.claim();
})()));
self.addEventListener('message', e => { if (e.data === 'update') self.skipWaiting(); });

self.addEventListener('fetch', e => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== self.location.origin) return;           // uploads, chat, share: network
  if (url.pathname.startsWith('/api/') || url.pathname === '/sw.js') return;          // live data: never cached
  if (req.mode === 'navigate') {                                                     // the page: fresh when online
    e.respondWith(fetch(req).catch(() => caches.match('/', { ignoreSearch: true })));
    return;
  }
  e.respondWith(caches.match(req, { ignoreSearch: true }).then(hit => hit || fetch(req).then(res => {
    if (res.ok && url.pathname.startsWith('/icons/')) { const copy = res.clone(); caches.open(CACHE).then(c => c.put(req, copy)); }
    return res;
  })));
});

self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch { d = { body: e.data && e.data.text() }; }
  e.waitUntil(Promise.all([
    self.registration.showNotification(d.title || 'Talixio', {
      body: d.body || '', icon: '/icons/icon-192.png', badge: '/icons/badge-96.png', data: { url: d.url || '/#chat' }, tag: 'talixio', renotify: true,
    }),
    self.navigator?.setAppBadge ? self.navigator.setAppBadge().catch(() => {}) : null,
  ]));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = new URL(e.notification.data?.url || '/#chat', self.location.origin).href;
  e.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const w of wins) { if (new URL(w.url).origin === self.location.origin) { await w.focus(); w.navigate?.(url); return; } }
    await self.clients.openWindow(url);
  })());
});
