/* Caffenet Lite — support-chat.js
 * Floating «اگر سوالی دارید کلیک کنید» support button + the Goftino
 * live-chat panel it opens in an iframe (owner request).
 *
 *  - The pill button floats at the bottom-LEFT corner, above the bottom
 *    nav (mirroring #notify-nudge which sits on the right) — visible for
 *    everyone, guests included: support matters most before signing up.
 *  - Tapping it opens a centered panel with the Goftino chat page inside
 *    an iframe. The iframe src is assigned LAZILY on first open (no
 *    network cost until the customer actually asks for support) and the
 *    panel is only HIDDEN on close — never torn down — so the ongoing
 *    chat conversation survives open/close cycles.
 *  - A spinner covers the frame until its load event fires.
 *  - Server side, SecurityHeaders.php whitelists the Goftino origin in
 *    frame-src; without that directive the CSP default-src 'self' would
 *    silently block the frame.
 *
 * Intentionally NOT sandboxed: the src is a fixed, owner-chosen trusted
 * URL, and a sandbox would break Goftino's session storage/popups.
 *
 * Exposes window.SupportChat with: open(), close().
 */
(function () {
  'use strict';

  var CHAT_URL = 'https://www.goftino.com/c/FdMb9y';

  window.SupportChat = {
    _built: false,  // panel exists in the DOM
    _loaded: false, // iframe src assigned at least once

    open: function () {
      var host = document.getElementById('support-chat');
      if (!host) return;
      if (!this._built) {
        this._build(host);
        this._built = true;
      }
      if (!this._loaded) {
        // Lazy: the chat page loads on the FIRST open only.
        host.querySelector('#sc-iframe').src = CHAT_URL;
        this._loaded = true;
      }
      host.classList.add('open');
    },

    close: function () {
      var host = document.getElementById('support-chat');
      if (host) host.classList.remove('open'); // iframe stays mounted
    },

    _build: function (host) {
      var self = this;
      var wrap = document.createElement('div');
      wrap.className = 'sc-backdrop';
      wrap.innerHTML =
        '<div class="sc-panel" role="dialog" aria-label="پشتیبانی آنلاین">' +
          '<div class="sc-head">' +
            '<div class="sc-head-title">' + icon('sms') + 'پشتیبانی آنلاین</div>' +
            '<button type="button" class="sc-close" id="sc-close" aria-label="بستن">' + icon('x') + '</button>' +
          '</div>' +
          '<div class="sc-body">' +
            '<div class="sc-loading"><div class="spinner"></div></div>' +
            '<iframe id="sc-iframe" title="پشتیبانی آنلاین" referrerpolicy="no-referrer"></iframe>' +
          '</div>' +
        '</div>';
      host.appendChild(wrap);

      wrap.querySelector('#sc-close').addEventListener('click', function () { self.close(); });
      wrap.addEventListener('click', function (e) {
        if (e.target === wrap) self.close(); // backdrop tap closes, panel taps don't
      });
      wrap.querySelector('#sc-iframe').addEventListener('load', function () {
        var l = wrap.querySelector('.sc-loading');
        if (l) l.style.display = 'none';
      });
    },
  };

  // Floating pill — built once the deferred scripts (icons.js included)
  // have executed, so icon() is guaranteed to exist.
  document.addEventListener('DOMContentLoaded', function () {
    var host = document.getElementById('support-chat');
    if (!host) return;
    var fab = document.createElement('button');
    fab.type = 'button';
    fab.className = 'sc-fab';
    fab.setAttribute('aria-label', 'پشتیبانی آنلاین');
    fab.innerHTML =
      '<span class="sc-fab-icon">' + icon('sms') + '</span>' +
      '<span class="sc-fab-text">اگر سوالی دارید کلیک کنید</span>';
    fab.addEventListener('click', function (e) {
      e.preventDefault();
      window.SupportChat.open();
    });
    host.appendChild(fab);
  });
})();
