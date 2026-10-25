/* firebase-messaging-sw.js
 * Service worker for Firebase Cloud Messaging background notifications.
 *
 * Firebase automatically imports this SW and attaches its own message
 * handler. We just need to:
 *  1. Import the Firebase Compat SDK (the SW build, not the modular build).
 *  2. Initialize the app with the public web config.
 *  3. Let firebase-messaging handle the `push` event + show the
 *     notification.
 *
 * The config is fetched at SW install time from /api/fcm/config so the
 * admin can change it from the settings panel without redeploying the SW.
 */

// The Firebase Compat SDK must be imported SYNCHRONOUSLY during the SW's
// initial script evaluation — Chrome refuses importScripts() calls made
// asynchronously (after an await), failing with "The script at ... failed
// to load". Config fetch + initializeApp below may stay async.
try {
  importScripts('https://www.gstatic.com/firebasejs/12.14.0/firebase-app-compat.js');
  importScripts('https://www.gstatic.com/firebasejs/12.14.0/firebase-messaging-compat.js');
} catch (e) {
  // gstatic unreachable / blocked — the manual push fallback below still works.
  self.__sdkErr = (e && e.message) || 'compat SDK importScripts failed';
}

// Cache the config so we don't fetch on every push event.
let _fcmConfig = null;

/**
 * Config resolution order: memory → CacheStorage (offline-fast, set by a
 * previous lifecycle) → network. The CacheStorage layer matters because a
 * push event WAKES this worker: the faster firebase.messaging() runs, the
 * sooner the SDK's own push listener exists (see the __sdkReady note in
 * the push handler — before that, our manual handler must show it).
 */
async function loadConfig() {
  if (_fcmConfig) return _fcmConfig;
  try {
    const cache = await caches.open('fcm-cfg-v1');
    const cached = await cache.match('/api/fcm/config');
    if (cached) {
      _fcmConfig = await cached.json();
      return _fcmConfig;
    }
  } catch (e) { /* cache unavailable — fall through to network */ }
  try {
    const resp = await fetch('/api/fcm/config');
    if (!resp.ok) return null;
    _fcmConfig = await resp.json();
    try {
      const cache = await caches.open('fcm-cfg-v1');
      cache.put('/api/fcm/config', new Response(JSON.stringify(_fcmConfig)));
    } catch (e) { /* cache write is best-effort */ }
    return _fcmConfig;
  } catch (e) {
    return null;
  }
}

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

// Listen for push events.
//
// ⚠ DELIVERY RULE (root-cause fix for "push sent=1 but nothing appears"):
// A push event WAKES this worker. At that moment the async init block at
// the bottom has only STARTED — firebase.messaging() (which attaches the
// SDK's own push listener) runs only AFTER loadConfig() resolves. The
// old code decided "the SDK will handle it" by testing
// `self.firebase && self.firebase.messaging` — those exist right after
// importScripts, so on every cold start we returned and NOBODY handled
// the message: the SDK's listener was not attached yet and the event is
// one-shot. The decision must therefore be based on __sdkReady, which is
// set only AFTER firebase.messaging() has actually run and attached the
// listener. Cold start → we show the notification ourselves (no network
// dependency); warm start (SDK attached) → let the SDK handle it so a
// focused tab gets onMessage forwarding instead of a system toast.
// (yadapp never had this race: it calls firebase.messaging()
// synchronously with a hardcoded config.)
self.addEventListener('push', (event) => {
  self.__pushSeen = (self.__pushSeen || 0) + 1;
  // Persistent delivery trace — survives SW idle restarts (unlike the
  // in-memory flags). The page/E2E reads cache 'fcm-debug-v1'.
  const __trace = (rec) => {
    try { event.waitUntil(caches.open('fcm-debug-v1')
      .then(c => c.put('/__fcm-push-trace', new Response(JSON.stringify(rec))))); } catch (e) {}
  };
  __trace({ at: new Date().toISOString(), seen: self.__pushSeen, by: null, sdkReady: !!self.__sdkReady });
  if (!event.data) { self.__pushErr = 'no data'; return; }

  let payload;
  try {
    payload = event.data.json();
  } catch (e) {
    payload = { notification: { title: 'اعلان جدید', body: event.data.text() } };
  }

  const title = (payload.notification && payload.notification.title) || 'اعلان جدید';
  const body = (payload.notification && payload.notification.body) || '';
  const data = payload.data || {};

  const options = {
    body: body,
    icon: '/icon.png',
    // Monochrome (white-on-transparent) E-glyph — Android renders the
    // badge as an alpha mask; an opaque PNG shows as a plain pale square.
    badge: '/push-badge-192.png',
    tag: data.tag || 'caffenet-fcm',
    data: data,
    requireInteraction: false,
  };

  event.waitUntil(
    (async () => {
      // SDK path ONLY when its push listener is REALLY attached
      // (__sdkReady) and the message carries a notification payload —
      // data-only messages are never auto-displayed by the SDK.
      if (self.__sdkReady === true && self.firebase && self.firebase.apps && self.firebase.apps.length && payload.notification) {
        self.__fw = 'sdk';
        __trace({ at: new Date().toISOString(), seen: self.__pushSeen, by: 'sdk', sdkReady: true });
        return;
      }
      // Deterministic fallback: show the notification directly. Works
      // with zero config, zero network, zero SDK — the cold-start race
      // can never drop a message again.
      try {
        await self.registration.showNotification(title, options);
        self.__fw = 'manual';
        __trace({ at: new Date().toISOString(), seen: self.__pushSeen, by: 'manual', sdkReady: !!self.__sdkReady });
      } catch (e) {
        self.__pushErr = 'showNotification: ' + (e && e.message);
      }
    })()
  );
});

// Handle notification click — focus the app (or open it).
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if (client.url.includes(self.location.origin) && 'focus' in client) {
          client.navigate(url);
          return client.focus();
        }
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(url);
      }
    })
  );
});

// Initialize with the live config (async is fine here — only importScripts
// had to be synchronous). Once firebase.messaging() runs, the compat SDK
// owns push: background → system notification, foreground (visible tab) →
// forwarded to the page so onMessage can show a toast.
(async function () {
  try {
    if (!self.firebase || !self.firebase.messaging) return;
    const cfg = await loadConfig();
    if (!cfg || !cfg.enabled || !cfg.apiKey) return;

    if (!self.firebase.apps.length) {
      self.firebase.initializeApp(cfg);
    }
    const messaging = self.firebase.messaging();
    self.__sdkReady = true;
  } catch (e) {
    // If the SDK fails to load, the manual push handler above still works.
    self.__sdkErr = (e && e.message) || 'sdk init failed';
  }
})();
