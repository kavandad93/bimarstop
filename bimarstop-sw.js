const POLL_MS = 5000;
let nonce = '';
try { nonce = new URL(self.location.href).searchParams.get('nonce') || ''; } catch(e) {}

async function poll() {
  if (!nonce) return;
  try {
    const url = new URL('/wp-admin/admin-ajax.php', self.location.origin);
    url.searchParams.set('action', 'bimarstop_get_notifications');
    url.searchParams.set('nonce', nonce);
    const response = await fetch(url, { credentials: 'include', cache: 'no-store' });
    const data = await response.json();
    if (!data.success) return;
    const cache = await caches.open('bimarstop-notifications-v1');
    const marker = await cache.match('/last-notification-id');
    const last = marker ? parseInt(await marker.text(), 10) || 0 : 0;
    let max = last;
    for (const item of (data.data.notifications || []).slice().reverse()) {
      const numericId = parseInt(String(item.id).replace(/\D/g, ''), 10) || 0;
      if (numericId <= last) continue;
      await self.registration.showNotification(item.title || 'بیمار استاپ', {
        body: item.body || 'پیام جدید دارید.',
        icon: '/wp-content/plugins/bimarstop/assets/icon-192.png',
        badge: '/wp-content/plugins/bimarstop/assets/icon-192.png',
        tag: 'bimarstop-' + item.id,
        dir: 'rtl',
        lang: 'fa',
        data: { url: item.url || '/wp-admin/' }
      });
      if (numericId > max) max = numericId;
    }
    if (max > last) await cache.put('/last-notification-id', new Response(String(max)));
  } catch(e) {}
}

self.addEventListener('install', event => event.waitUntil(self.skipWaiting()));
self.addEventListener('activate', event => event.waitUntil(self.clients.claim().then(poll)));
self.addEventListener('message', event => { if (event.data && event.data.type === 'bimarstop-poll') poll(); });
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/wp-admin/';
  event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
    for (const client of list) {
      if ('focus' in client) { client.focus(); client.postMessage({ type: 'bimarstop-notification-click', url }); return; }
    }
    if (clients.openWindow) return clients.openWindow(url);
  }));
});
setInterval(poll, POLL_MS);
