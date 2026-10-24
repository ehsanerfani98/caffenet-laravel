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

async function loadConfig() {
  if (_fcmConfig) return _fcmConfig;
  try {
    const resp = await fetch('/api/fcm/config');
    _fcmConfig = await resp.json();
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

// Listen for push events. When Firebase's own handler isn't loaded yet
// (cold start), we fall back to a basic notification.
// In-memory diagnostics (reset on every SW restart) — introspectable via
// Playwright worker.evaluate to debug delivery chain issues.
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
    badge: '/pwa-72x72.png',
    tag: data.tag || 'caffenet-fcm',
    data: data,
    requireInteraction: false,
  };

  event.waitUntil(
    (async () => {
      // Try to use the Firebase SDK first (when it's loaded).
      if (self.firebase && self.firebase.messaging) {
        try {
          const cfg = await loadConfig();
          if (cfg && cfg.apiKey) {
            if (!self.firebase.apps.length) {
              self.firebase.initializeApp(cfg);
            }
            // Let Firebase handle it (background display / foreground
            // forwarding to the visible tab).
            self.__fw = 'sdk';
            __trace({ at: new Date().toISOString(), seen: self.__pushSeen, by: 'sdk', sdkReady: !!self.__sdkReady });
            return;
          }
        } catch (e) { /* fall through to manual */ }
      }
      // Fallback: show the notification directly.
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
