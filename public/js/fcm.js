/* Caffenet Lite — fcm.js
 * Firebase Cloud Messaging client for the SPA.
 *
 * Responsibilities:
 *  - Fetch /api/fcm/config on boot to read the public Firebase web config
 *    (projectId, apiKey, appId, messagingSenderId, vapidKey).
 *  - When enabled, dynamically import the Firebase SDK from gstatic.com,
 *    initialize messaging, request notification permission, and register
 *    the device token via POST /api/fcm/token.
 *  - Listen for incoming foreground messages and show them as toasts.
 *  - On logout, call DELETE /api/fcm/token to deactivate tokens.
 *
 * The Firebase SDK is loaded ONLY when FCM is enabled in the admin panel —
 * so sites that don't use push notifications pay zero download cost.
 *
 * Exposes window.FCM with: init(), requestPermission(), unregister().
 */
(function () {
  'use strict';

  window.FCM = {
    _initialized: false,
    _messaging: null,
    _config: null,

    /**
     * Boot FCM. Called from app.js after the user logs in. No-op when FCM
     * is disabled in the system settings.
     */
    async init() {
      if (this._initialized) return;
      this._initialized = true;

      try {
        const resp = await fetch('/api/fcm/config', {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        if (!resp.ok) return;
        const cfg = await resp.json();
        this._config = cfg;

        if (!cfg.enabled) return;
        if (!cfg.apiKey || !cfg.projectId || !cfg.appId) {
          console.warn('[FCM] Config incomplete — skipping init.');
          return;
        }

        await this._loadFirebaseSdk();
        await this._initMessaging();
        await this._registerToken();
      } catch (e) {
        console.warn('[FCM] init failed:', e.message);
      }
    },

    /**
     * Dynamically import the Firebase modular SDK. Uses the official
     * gstatic.com CDN (the only CDN that Firebase officially supports).
     * No bundler, no build step — pure ES module imports.
     */
    async _loadFirebaseSdk() {
      const v = '10.12.2';
      // Import the modular SDK — these URLs are stable and versioned.
      const appMod = await import('https://www.gstatic.com/firebasejs/' + v + '/firebase-app.js');
      const msgMod = await import('https://www.gstatic.com/firebasejs/' + v + '/firebase-messaging.js');

      this._app = appMod.initializeApp(this._config);
      this._messaging = msgMod.getMessaging(this._app);
      this._msgMod = msgMod;
    },

    /**
     * Initialize Firebase Messaging + set up foreground message handler.
     */
    async _initMessaging() {
      const msgMod = this._msgMod;
      const messaging = this._messaging;

      // Foreground messages — when the tab is open, FCM delivers here
      // instead of showing a system notification. We surface a toast.
      msgMod.onMessage(messaging, (payload) => {
        const title = (payload.notification && payload.notification.title) || 'اعلان جدید';
        const body = (payload.notification && payload.notification.body) || '';
        if (window.UI && typeof window.UI.toast === 'function') {
          window.UI.toast(title + (body ? ' — ' + body : ''), 'info');
        }
        // Also fire a browser notification if permission is granted AND
        // the tab is in the background.
        if ('Notification' in window && Notification.permission === 'granted' && document.visibilityState === 'hidden') {
          try {
            new Notification(title, {
              body: body,
              icon: '/icon.png',
              tag: 'caffenet-fcm',
              data: payload.data || {},
            });
          } catch (e) { /* ignore */ }
        }
      });
    },

    /**
     * Request notification permission + register the FCM token.
     * Called automatically on init, but can also be called manually
     * (e.g. from a "enable notifications" button).
     */
    async requestPermission() {
      if (!this._messaging || !this._msgMod) {
        await this.init();
      }
      if (!this._messaging) return false;

      try {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') return false;
        await this._registerToken();
        return true;
      } catch (e) {
        console.warn('[FCM] requestPermission failed:', e.message);
        return false;
      }
    },

    /**
     * Get the FCM token + register it with the server via POST /api/fcm/token.
     * The server ALSO subscribes the token to the "all" broadcast topic
     * (handled in FcmController::registerToken via FirebasePushService) so
     * the admin can send a single topic push that reaches every device.
     * Uses the VAPID key from the public config for web push.
     */
    async _registerToken() {
      const msgMod = this._msgMod;
      const messaging = this._messaging;
      if (!messaging || !this._config.vapidKey) return;

      try {
        const token = await msgMod.getToken(messaging, {
          vapidKey: this._config.vapidKey,
          serviceWorkerRegistration: await this._getSwRegistration(),
        });
        if (! token) {
          console.warn('[FCM] No token received — permission may not be granted.');
          return;
        }

        await fetch('/api/fcm/token', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': (window.bootData && window.bootData.csrf) || '',
          },
          body: JSON.stringify({ token: token, platform: 'web' }),
        });
      } catch (e) {
        console.warn('[FCM] getToken failed:', e.message);
      }
    },

    /**
     * Get (or register) the service worker registration that FCM needs.
     * We reuse the existing /sw.js registration when present; otherwise
     * we register /firebase-messaging-sw.js as a separate SW.
     */
    async _getSwRegistration() {
      if (!('serviceWorker' in navigator)) return null;
      try {
        // Try to reuse an existing registration first (the app already
        // registers /sw.js for PWA purposes).
        const existing = await navigator.serviceWorker.getRegistration();
        if (existing && existing.active) return existing;

        // Otherwise register the FCM-specific SW.
        return await navigator.serviceWorker.register('/firebase-messaging-sw.js', {
          scope: '/',
        });
      } catch (e) {
        console.warn('[FCM] SW registration failed:', e.message);
        return null;
      }
    },

    /**
     * Deactivate this user's tokens on the server (called on logout).
     */
    async unregister() {
      try {
        await fetch('/api/fcm/token', {
          method: 'DELETE',
          credentials: 'same-origin',
          headers: {
            'X-CSRF-TOKEN': (window.bootData && window.bootData.csrf) || '',
          },
        });
      } catch (e) { /* ignore */ }
    },
  };
})();
