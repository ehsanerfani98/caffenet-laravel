/* Caffenet Lite — test-gateway.js (external, CSP-safe).
 * Drives the internal test gateway: success/cancel both walk the exact same
 * verify pipeline a real Zarinpal callback would. */
(function () {
  'use strict';

  function go(status) {
    const callback = document.body.getAttribute('data-callback') || '';
    const authority = document.body.getAttribute('data-authority') || '';
    window.location.href = callback + '?authority=' + encodeURIComponent(authority) + '&Status=' + status;
  }

  document.addEventListener('DOMContentLoaded', function () {
    const ok = document.getElementById('pay-ok');
    const cancel = document.getElementById('pay-cancel');
    if (ok) ok.addEventListener('click', function () { go('OK'); });
    if (cancel) cancel.addEventListener('click', function () { go('NOK'); });
  });
})();
