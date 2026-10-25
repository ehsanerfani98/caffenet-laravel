/* Caffenet Lite — notify-nudge.js
 * Floating «فعال‌سازی اعلان‌ها» nudge (owner design: notification_preview-1.html).
 *
 * A white hint card («نوبتت رو از دست نده!») plus a blue round push button
 * with a red badge, floating above the bottom nav on the right. It shows for
 * ANY logged-in user while push is not yet active for them:
 *   - browser notification permission not granted, OR
 *   - no ACTIVE FCM token registered on the server,
 * and it disappears the moment the token registers (AppBar bell turns green
 * — fcm-on — exactly as before).
 *
 * Zero extra network cost: visibility is decided from the SAME FCM.status()
 * result that UI.renderAppBar already fetches on every app-bar paint.
 * Tapping the button reuses App.handleAppBarAction('fcm') — the full enable
 * flow (permission prompt → token registration → diagnostic toasts → bell
 * repaint); App.enablePushNotifications hides the nudge on success.
 *
 * Exposes window.NotifyNudge with: sync(st), refresh(), hide().
 */
(function () {
  'use strict';

  window.NotifyNudge = {
    _built: false,

    /* Tear down + hide. Safe to call any time (no-op when not visible). */
    hide() {
      this._built = false;
      const el = document.getElementById('notify-nudge');
      if (el) el.innerHTML = '';
    },

    /**
     * Decide visibility from a pre-fetched FCM.status() result.
     * st = { registered, enabled } from GET /api/fcm/token.
     * Callers: UI.renderAppBar (already holds the status) and refresh().
     * Hides when: logged out / FCM module missing / browser unsupported /
     * token registered (client flag OR server truth) / FCM disabled in
     * admin settings. Shows only when the server actively confirmed an
     * unregistered, enabled state — so a returning subscriber never sees
     * the nudge flash.
     */
    sync(st) {
      const el = document.getElementById('notify-nudge');
      if (!el) return;

      const user = window.api && api.state && api.state.currentUser;
      const supported = ('Notification' in window) && ('serviceWorker' in navigator);
      const registered = !!(st && st.registered) || !!(window.FCM && FCM.registered);
      const disabled = !!(st && st.enabled === false);

      if (!user || !window.FCM || !supported || registered || disabled) {
        this.hide();
        return;
      }
      if (!st) return; // server unreachable → keep whatever is painted (no flapping)
      if (this._built) return; // already visible
      this._render(el);
    },

    /**
     * Manual re-check (fetches status itself). Used after a failed enable
     * attempt so a server-side change is picked up without a full repaint.
     */
    async refresh() {
      if (!window.FCM) return;
      try {
        const st = await FCM.status();
        if (st) this.sync(st);
      } catch (e) { /* keep current state */ }
    },

    _render(el) {
      this._built = true;
      el.innerHTML =
        '<div class="nn-hint">' +
          '<div class="nn-hint-icon">' + icon('bell') + '</div>' +
          '<div class="nn-text">' +
            '<strong>نوبتت رو از دست نده!</strong>' +
            '<span>برای اطلاع از زمان نوبت، اعلان‌ها را فعال کن.</span>' +
          '</div>' +
        '</div>' +
        '<button class="nn-btn" type="button" aria-label="فعال کردن اعلان‌ها">' +
          icon('bellring') +
          '<span class="badge">' + PU.toPersianDigits(1) + '</span>' +
        '</button>';

      $(el).off('click', '.nn-btn').on('click', '.nn-btn', function (e) {
        e.preventDefault();
        // Same enable flow as the AppBar bell: permission prompt, token
        // registration, diagnostic toasts, bell repaint. On success
        // App.enablePushNotifications calls NotifyNudge.hide().
        if (window.App && App.handleAppBarAction) App.handleAppBarAction('fcm');
      });
    },
  };
})();
