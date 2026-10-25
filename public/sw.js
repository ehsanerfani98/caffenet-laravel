/* Caffenet Lite — service worker (hand-written, autoUpdate semantics).
 * cache-first for static assets; network-only for /api, /uploads, /media
 * (they are never served the SPA fallback). */
const CACHE = 'caffenet-v45'; // bump purges every stale entry on activate (v45: unified push display)
const SHELL = ['/', '/manifest.webmanifest', '/pwa-192x192.png', '/pwa-512x512.png',
  '/maskable-192x192.png', '/maskable-512x512.png', '/apple-touch-icon.png',
  '/favicon.ico', '/favicon-16x16.png', '/favicon-32x32.png', '/favicon-48x48.png',
  '/push-badge-192.png',
  '/css/app.css', '/js/jquery.min.js', '/js/persian-utils.js',
  '/js/icons.js', '/js/permissions.js', '/js/pricing.js', '/js/order-draft.js',
  '/js/api.js', '/js/ui.js', '/js/track-page.js',
  '/js/pwa.js', '/js/app.js'];

self.addEventListener('install', (e) => {
  self.skipWaiting();
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).catch(() => undefined));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);

  // Ignore non-http(s) schemes (e.g. chrome-extension:// requests injected by
  // browser extensions) — the Cache API only supports http/https requests, and
  // caches.put() throws on any other scheme.
  if (url.protocol !== 'http:' && url.protocol !== 'https:') return;

  const NEVER = url.pathname.startsWith('/api/') || url.pathname.startsWith('/uploads/') ||
    url.pathname.startsWith('/media/') || url.pathname.startsWith('/test-gateway');
  if (e.request.method !== 'GET' || NEVER) return; // network only

  /* Documents (the SPA shell): NETWORK-FIRST. The HTML embeds the
   * filemtime-versioned asset URLs (?v=…); serving it cache-first made every
   * deployment lag a whole session behind — the user kept seeing the previous
   * design (e.g. the old search-sheet spacing) no matter what was deployed.
   * The cache entry is still refreshed in the background and only serves as
   * the offline fallback. */
  if (e.request.mode === 'navigate' ||
      (e.request.headers.get('accept') || '').indexOf('text/html') !== -1) {
    e.respondWith(
      fetch(e.request).then((res) => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(e.request, copy));
        }
        return res;
      }).catch(() =>
        caches.match(e.request).then((cached) => cached || caches.match('/'))
      )
    );
    return;
  }

  e.respondWith(
    caches.match(e.request).then((cached) => {
      const network = fetch(e.request).then((res) => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(e.request, copy));
        }
        return res;
      }).catch(() => cached);
      return cached || network;
    })
  );
});

/* ─── Push safety net ────────────────────────────────────────────────
 * The dedicated FCM worker lives at the '/fcm/' scope
 * (firebase-messaging-sw.js) and fcm.js binds tokens to it. But any
 * token that was EVER registered through THIS worker (scope '/') —
 * e.g. by the old fcm.js fallback — still has its push events
 * delivered HERE. Without a push handler those messages were silently
 * swallowed (server log said sent=1, the device showed nothing).
 * This handler makes this worker deliver them too; both workers
 * showing the same message is prevented server-side by deactivating
 * a browser's previous token when it re-registers (same user_agent).
 * Also matches yadapp, where the root-scope worker owns push. */
self.addEventListener('push', (e) => {
  let title = 'اعلان جدید';
  let body = '';
  let data = {};
  try {
    const payload = e.data ? e.data.json() : null;
    if (payload) {
      if (payload.notification && payload.notification.title) title = payload.notification.title;
      if (payload.notification && payload.notification.body) body = payload.notification.body;
      if (payload.data) data = payload.data;
    } else if (e.data) {
      body = e.data.text();
    }
  } catch (_) {
    if (e.data) body = e.data.text();
  }
  e.waitUntil(self.registration.showNotification(title, {
    body: body,
    icon: '/icon.png',
    // Monochrome E-glyph — Android masks the badge; opaque PNGs render as
    // a plain pale square in the notification header.
    badge: '/push-badge-192.png',
    tag: data.tag || 'caffenet-fcm',
    dir: 'rtl',
    lang: 'fa',
    // Re-alert on tag replacement — identical pushes must still banner.
    renotify: true,
    data: data,
  }));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || '/';
  e.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if (client.url.includes(self.location.origin) && 'focus' in client) {
          client.navigate(url);
          return client.focus();
        }
      }
      return self.clients.openWindow ? self.clients.openWindow(url) : undefined;
    })
  );
});
