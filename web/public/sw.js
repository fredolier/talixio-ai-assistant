// Service worker: makes the app installable and shows push notifications sent by Talixio.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});

self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch { d = { body: e.data && e.data.text() }; }
  e.waitUntil(self.registration.showNotification(d.title || 'Talixio', {
    body: d.body || '', icon: '/icon.svg', badge: '/icon.svg', data: { url: d.url || '/#chat' }, tag: 'talixio', renotify: true,
  }));
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
