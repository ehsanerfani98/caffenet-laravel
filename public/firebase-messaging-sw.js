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
self.addEventListener('push', (event) => {
  if (!event.data) return;

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
            // Let Firebase handle it.
            return;
          }
        } catch (e) { /* fall through to manual */ }
      }
      // Fallback: show the notification directly.
      return self.registration.showNotification(title, options);
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

// Dynamically import the Firebase compat SDK + initialize with the live config.
// Using importScripts (works in classic SWs) — the compat build is the only
// one that auto-handles background push without app-level glue.
(async function () {
  try {
    const cfg = await loadConfig();
    if (!cfg || !cfg.enabled || !cfg.apiKey) return;

    importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js');
    importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js');

    if (!self.firebase.apps.length) {
      self.firebase.initializeApp(cfg);
    }
    const messaging = self.firebase.messaging();
    // Firebase's compat SW will now handle `push` events automatically.
  } catch (e) {
    // If the SDK fails to load, the manual push handler above still works.
  }
})();
