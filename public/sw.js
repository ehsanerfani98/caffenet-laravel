/* Caffenet Lite — service worker (hand-written, autoUpdate semantics).
 * cache-first for static assets; network-only for /api, /uploads, /media
 * (they are never served the SPA fallback). */
const CACHE = 'caffenet-v10';
const SHELL = ['/', '/manifest.webmanifest', '/pwa-192x192.png', '/pwa-512x512.png',
  '/maskable-192x192.png', '/maskable-512x512.png', '/apple-touch-icon.png',
  '/favicon.ico', '/favicon-16x16.png', '/favicon-32x32.png', '/favicon-48x48.png',
  '/css/app.css', '/js/jquery.min.js', '/js/persian-utils.js',
  '/js/icons.js', '/js/permissions.js', '/js/pricing.js', '/js/api.js', '/js/ui.js',
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
