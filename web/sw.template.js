// Service worker (served by PHP at sw.js with the version filled in). Its scope is the app's folder: '/' or '/fred/'.
// - the app opens instantly, also offline (the app shell is cached; API calls always go to the server)
// - push notifications, with a badge on the app icon
// - a new version is installed in the background; the page offers to reload
const VERSION = '__VERSION__';
const CACHE = 'talixio-' + VERSION + new URL(self.registration.scope).pathname;
const ROOT = new URL(self.registration.scope).pathname; // '/' or '/fred/'
// the scripts are cached under the address of their version (v/<version>/app.js): a newer page never runs older scripts
const SHELL = ['', ...['app.css', 'app.js', 'i18n.js', 'i18n/fr.js'].map(u => 'v/' + VERSION + '/' + u), 'manifest.json', 'icon.svg', 'icons/icon-192.png', 'icons/badge-96.png'].map(u => ROOT + u);

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL.map(u => new Request(u, { cache: 'reload' })))));
});
self.addEventListener('activate', e => e.waitUntil((async () => {
  for (const k of await caches.keys()) if (k.startsWith('talixio-') && k.endsWith(ROOT) && k !== CACHE) await caches.delete(k); // only this instance's
  await self.clients.claim();
})()));
self.addEventListener('message', e => { if (e.data === 'update') self.skipWaiting(); });

self.addEventListener('fetch', e => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== self.location.origin) return;           // uploads, chat, share: network
  if (!url.pathname.startsWith(ROOT)) return;                                         // another instance on this site
  if (url.pathname.startsWith(ROOT + 'api/') || url.pathname === ROOT + 'sw.js') return; // live data: never cached
  if (req.mode === 'navigate') {                                                     // the page: fresh when online
    e.respondWith(fetch(req).catch(() => caches.match(ROOT, { ignoreSearch: true })));
    return;
  }
  e.respondWith(caches.match(req, { ignoreSearch: true }).then(hit => hit || fetch(req).then(res => {
    if (res.ok && url.pathname.startsWith(ROOT + 'icons/')) { const copy = res.clone(); caches.open(CACHE).then(c => c.put(req, copy)); }
    return res;
  })));
});

self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch { d = { body: e.data && e.data.text() }; }
  e.waitUntil(Promise.all([
    self.registration.showNotification(d.title || 'Talixio', {
      body: d.body || '', icon: ROOT + 'icons/icon-192.png', badge: ROOT + 'icons/badge-96.png', data: { url: d.url || '#chat' }, tag: 'talixio', renotify: true,
    }),
    self.navigator?.setAppBadge ? self.navigator.setAppBadge().catch(() => {}) : null,
  ]));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = new URL(e.notification.data?.url || '#chat', self.registration.scope).href; // relative to this instance
  e.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const w of wins) { if (w.url.startsWith(self.registration.scope)) { await w.focus(); w.navigate?.(url); return; } }
    await self.clients.openWindow(url);
  })());
});
