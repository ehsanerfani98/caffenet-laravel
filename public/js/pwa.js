/* Caffenet Lite — pwa.js
 * Install prompt + online/offline indicator (port of utils/pwa.ts +
 * OfflineIndicator/PWAInstallButton). */
(function () {
  'use strict';

  let deferredPrompt = null;

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
  });

  window.addEventListener('appinstalled', () => { deferredPrompt = null; });

  window.PWA = {
    isInstallable() { return !!deferredPrompt; },

    isIOS() {
      return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    },

    isStandalone() {
      return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    },

    async promptInstall() {
      if (!deferredPrompt) return false;
      deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;
      deferredPrompt = null;
      return outcome === 'accepted';
    },

    watchOnline() {
      const update = () => {
        $('#offline-indicator').toggleClass('hidden', navigator.onLine);
      };
      window.addEventListener('online', update);
      window.addEventListener('offline', update);
      update();
    },
  };

  document.addEventListener('DOMContentLoaded', () => PWA.watchOnline());

  // Register the hand-written service worker (autoUpdate semantics).
  // isSecureContext covers https AND localhost/127.0.0.1 — the old
  // precedence-broken check skipped 127.0.0.1 (http) dev/testing origins.
  if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/sw.js').catch(() => { /* offline is optional */ });
    });
  }
})();
