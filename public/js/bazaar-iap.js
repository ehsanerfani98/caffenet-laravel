/* Caffenet Bazaar IAP — browser helper
 * ---------------------------------------------------------------------------
 * Makes the in-app login / in-app payment flows usable from a Laravel web app
 * (PWA or in-app webview) WITHOUT ever touching the Cafe Bazaar API secret.
 *
 * All server calls (deep-link building, consume) happen in Laravel; this file
 * only:
 *   1. reads the buyer's account id from the Bazaar JS bridge, so the UI can
 *      decide whether an in-app login is required first;
 *   2. opens the `bazaar://` deep links returned by the backend;
 *   3. exposes the `response` / `purchaseToken` query params when Bazaar
 *      returns to a normal page (instead of the server callback route).
 *
 * The Bazaar app injects a global `Bazaar` object into pages it loads. Outside
 * the Bazaar webview it is undefined, so every accessor here is defensive.
 *
 * NOTE: this file is published from the `caffenet/bazaar-iap` package
 * (`php artisan vendor:publish --tag=bazaar-iap-assets`). Keep the copy in
 * packages/caffenet/bazaar-iap/resources/assets/js in sync.
 */
(function (window) {
  'use strict';

  function bridge() {
    return typeof window.Bazaar !== 'undefined' ? window.Bazaar : null;
  }

  /** True when the page is running inside the Cafe Bazaar webview. */
  function isInBazaar() {
    return bridge() !== null;
  }

  /**
   * The current user's Cafe Bazaar account id, or null when not available
   * (not inside Bazaar, not logged in, or the bridge rejected the package).
   */
  function getAccountId(packageName) {
    var b = bridge();
    if (!b || typeof b.getAccountId !== 'function') return null;
    try {
      var id = b.getAccountId(packageName || '');
      return id ? String(id) : null;
    } catch (e) {
      return null;
    }
  }

  /** Open a `bazaar://` deep link (login or payment). */
  function open(deepLink) {
    if (!deepLink) return false;
    window.location.replace(deepLink);
    return true;
  }

  /**
   * Start a payment from the object returned by the Laravel backend
   * (`{ gateway: 'bazaar', deepLink }`). Falls back to `redirectUrl` when the
   * server returned one (non-Bazaar gateways are untouched).
   */
  function pay(payResult) {
    if (!payResult) return false;
    if (payResult.deepLink) return open(payResult.deepLink);
    if (payResult.redirectUrl) {
      window.location.href = payResult.redirectUrl;
      return true;
    }
    return false;
  }

  /** Start the in-app login from a backend login result. */
  function login(loginResult) {
    return pay(loginResult);
  }

  /**
   * Read the Bazaar return parameters from the current URL. Useful when Bazaar
   * redirects to a normal SPA page instead of the server callback.
   * @returns {{response: string|null, purchaseToken: string|null, status: string|null, sku: string|null}}
   */
  function returnParams() {
    var p = new URLSearchParams(window.location.search);
    return {
      response: p.get('response'),
      purchaseToken: p.get('purchaseToken'),
      status: p.get('status'),
      sku: p.get('sku'),
    };
  }

  // Bazaar intercepts the hardware back button by dispatching popstate: route
  // it to the native `Bazaar.back()` so navigation stays inside the app shell.
  function installBackHandler() {
    window.addEventListener('popstate', function () {
      var b = bridge();
      if (b && typeof b.back === 'function') b.back();
    }, true);
  }

  window.BazaarIap = {
    isInBazaar: isInBazaar,
    getAccountId: getAccountId,
    open: open,
    pay: pay,
    login: login,
    returnParams: returnParams,
    installBackHandler: installBackHandler,
  };
})(window);
