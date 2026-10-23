/* Caffenet Lite — fcm.js
 * Firebase Cloud Messaging client for the SPA.
 *
 * Responsibilities:
 *  - Fetch /api/fcm/config on boot to read the public Firebase web config
 *    (projectId, apiKey, appId, messagingSenderId, vapidKey).
 *  - When enabled, dynamically import the Firebase SDK from gstatic.com,
 *    initialize messaging, and listen for foreground messages.
 *  - Register the device token via POST /api/fcm/token — but ONLY when
 *    notification permission was ALREADY granted (returning subscriber).
 *    The browser permission prompt is NEVER shown automatically at boot:
 *    getToken() would otherwise pop it on its own. Permission is asked
 *    exclusively from the AppBar push icon (enable() →
 *    Notification.requestPermission() on that click).
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
    // True only after a token was fetched AND the server accepted it in
    // this browser session — the AppBar red/green icon reads this.
    registered: false,

    /**
     * Boot FCM. Called from app.js after the user logs in (and on session
     * restore). No-op when FCM is disabled in the system settings.
     *
     * NEVER asks for notification permission: the token is (re-)registered
     * silently only when permission was already granted — the browser
     * prompt must appear exclusively from the AppBar push icon click
     * (enable()), not automatically on every app start.
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
        // Auto-register ONLY for returning subscribers. getToken() fires
        // the browser permission prompt by itself when permission is still
        // 'default' — so it must not run here, or the prompt appears at
        // every boot instead of on the AppBar push icon click.
        if ('Notification' in window && Notification.permission === 'granted') {
          await this._registerToken();
        } else {
          this.registered = false;
        }
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
     * Ensure Firebase SDK + messaging are ready. Unlike init() — which runs
     * ONCE at boot and gives up permanently on failure — this is RETRYABLE:
     * a flaky network / blocked CDN at boot no longer bricks the manual
     * enable button for the whole session.
     * Resolves {ok:true} or {ok:false, reason: 'disabled'|'config'|'sdk'}.
     */
    async _ensureMessaging() {
      // 1) public config — cached, but re-fetched while disabled so an admin
      //    flipping FCM on in settings works without a full page reload.
      if (!this._config || this._config.enabled === false) {
        try {
          const resp = await fetch('/api/fcm/config', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
          });
          if (!resp.ok) return { ok: false, reason: 'config' };
          this._config = await resp.json();
        } catch (e) {
          return { ok: false, reason: 'config' };
        }
      }
      if (!this._config.enabled) return { ok: false, reason: 'disabled' };
      if (!this._config.apiKey || !this._config.projectId || !this._config.appId || !this._config.vapidKey) {
        return { ok: false, reason: 'config' };
      }
      // 2) SDK + messaging — retried until they load successfully once.
      if (!this._messaging) {
        try {
          await this._loadFirebaseSdk();
          await this._initMessaging();
        } catch (e) {
          console.warn('[FCM] SDK load failed:', e.message);
          return { ok: false, reason: 'sdk', error: e.message };
        }
      }
      return { ok: true };
    },

    /**
     * Full manual enable flow (AppBar push icon): ensure messaging, ask
     * permission, register the token — and on failure return WHICH link
     * broke, so the UI can show a diagnostic message instead of a generic
     * "check your browser permission" (misleading when permission was
     * already granted and the real failure was the SDK or the token POST).
     * Resolves {ok:true} or {ok:false, reason, error?, status?}.
     */
    async enable() {
      if (!('Notification' in window) || !('serviceWorker' in navigator)) {
        return { ok: false, reason: 'unsupported' };
      }
      const ready = await this._ensureMessaging();
      if (!ready.ok) return ready;

      try {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') return { ok: false, reason: 'permission' };
      } catch (e) {
        return { ok: false, reason: 'permission', error: e.message };
      }
      return await this._registerToken();
    },

    /**
     * Legacy boolean wrapper — landing.js profile toggle checks the result
     * with a plain truthy test and must never receive an object.
     */
    async requestPermission() {
      const res = await this.enable();
      return !!res.ok;
    },

    /**
     * Ask the server whether the current user still has an ACTIVE push
     * token. Drives the AppBar icon — a wiped fcm_tokens table must turn
     * it red again even if this browser registered long ago.
     * Resolves {registered, enabled}, or null when the request fails.
     */
    async status() {
      try {
        const resp = await fetch('/api/fcm/token', {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        if (!resp.ok) return null;
        return await resp.json();
      } catch (e) {
        return null;
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
      if (!messaging || !this._config || !this._config.vapidKey) {
        return { ok: false, reason: 'config' };
      }

      // Step 1 — fetch the FCM token from Google. Failures here are NOT
      // SDK-load problems: the SDK already loaded (we have messaging).
      // Typical causes: network blocked, CSP blocking the Google endpoints,
      // VAPID mismatch — surfaced to the UI as reason 'subscribe'.
      let token;
      try {
        token = await msgMod.getToken(messaging, {
          vapidKey: this._config.vapidKey,
          serviceWorkerRegistration: await this._getSwRegistration(),
        });
      } catch (e) {
        this.registered = false;
        console.warn('[FCM] getToken failed:', e.message);
        return { ok: false, reason: 'subscribe', error: e.message };
      }
      if (! token) {
        console.warn('[FCM] No token received — permission may not be granted.');
        this.registered = false;
        return { ok: false, reason: 'token' };
      }

      // Step 2 — hand the token to our server. A thrown fetch means the
      // request never reached the server (offline / connection reset).
      let resp;
      try {
        resp = await fetch('/api/fcm/token', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': (window.bootData && window.bootData.csrf) || '',
          },
          body: JSON.stringify({ token: token, platform: 'web' }),
        });
      } catch (e) {
        this.registered = false;
        console.warn('[FCM] token POST failed:', e.message);
        return { ok: false, reason: 'network', error: e.message };
      }
      this.registered = !!resp.ok;
      return resp.ok ? { ok: true } : { ok: false, reason: 'server', status: resp.status };
    },

    /**
     * Get (or register) the service worker registration that FCM needs.
     *
     * Dedicated '/fcm/' scope — do NOT reuse the PWA /sw.js registration:
     * sw.js owns offline caching but has NO push handler, so a token
     * subscribed through it can never receive a push event (nothing is
     * shown and onMessage never fires). firebase-messaging-sw.js owns
     * push → notification + foreground forwarding. Distinct scopes keep
     * the two workers side by side ('/' vs '/fcm/').
     */
    async _getSwRegistration() {
      if (!('serviceWorker' in navigator)) return null;
      const waitActive = function (reg) {
        // PushManager.subscribe requires an ACTIVE worker — wait through
        // the installing/waiting phases (first install activates fast).
        if (reg.active || !reg.installing && !reg.waiting) return Promise.resolve(reg);
        const w = reg.installing || reg.waiting;
        return new Promise(function (resolve) {
          const done = function () { resolve(reg); };
          w.addEventListener('statechange', function () {
            if (w.state === 'activated') done();
          });
          setTimeout(done, 12000); // safety net — subscribe may still retry
        });
      };
      // register() can STALL indefinitely when the SW script's synchronous
      // importScripts (gstatic) hangs on a bad network — bound every step
      // and fall back to the page-controlled SW so token registration never
      // bricks (delivery then depends on that SW, so /fcm/ is preferred).
      const withTimeout = function (p, ms) {
        return Promise.race([p, new Promise(function (resolve) {
          setTimeout(function () { resolve(null); }, ms);
        })]);
      };
      try {
        const existing = await navigator.serviceWorker.getRegistration('/fcm/');
        if (existing) {
          const ready = await withTimeout(waitActive(existing), 15000);
          if (ready) return ready;
          console.warn('[FCM] /fcm/ SW not active in time — using the page SW.');
        } else {
          const reg = await withTimeout(
            navigator.serviceWorker.register('/firebase-messaging-sw.js', { scope: '/fcm/' }),
            20000
          );
          if (reg) {
            const ready = await withTimeout(waitActive(reg), 15000);
            if (ready) return ready;
          }
          console.warn('[FCM] /fcm/ SW registration stalled — using the page SW.');
        }
      } catch (e) {
        console.warn('[FCM] SW registration failed (' + ((e && e.message) || e) + ') — using the page SW.');
      }
      try { return await navigator.serviceWorker.ready; } catch (e) { return null; }
    },

    /**
     * Deactivate this user's tokens on the server (called on logout).
     */
    async unregister() {
      this.registered = false;
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
