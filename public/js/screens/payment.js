/* Caffenet Lite — screens/payment.js
 * Gateway redirect sheet (auto-redirect after 1.2s) — port of
 * payment/PaymentModal.tsx. */
(function () {
  'use strict';

  window.Screens = window.Screens || {};

  Screens.openPaymentRedirect = function (payResult) {
    const isBazaarPay = !!(payResult && payResult.gateway === 'bazaarpay');
    const sheet = UI.sheet({
      title: 'انتقال به درگاه پرداخت',
      subtitle: isBazaarPay ? 'در حال اتصال به درگاه بازارپی…' : 'در حال اتصال به درگاه…',
      icon: 'wallet',
      tone: 'amber',
      onClose: () => { /* redirect already initiated */ },
    });

    let redirecting = false;

    function go() {
      if (redirecting) return;

      // Legacy Cafe Bazaar in-app gateway: hand the deep link to the browser
      // helper. It only works inside the Bazaar webview, so warn otherwise.
      // (BazaarPay uses the generic redirect path below — it is a plain URL.)
      if (payResult && payResult.gateway === 'bazaar') {
        if (window.BazaarIap && window.BazaarIap.isInBazaar()) {
          redirecting = true;
          window.BazaarIap.pay(payResult);
        } else {
          UI.toast('پرداخت کافه‌بازار فقط داخل اپلیکیشن کافه‌بازار امکان‌پذیر است.', 'warning');
        }
        return;
      }

      redirecting = true;
      window.location.href = payResult.redirectUrl;
    }

    sheet.body.html(
      '<div style="text-align:center;padding:18px 0">' +
        '<div style="width:74px;height:74px;border-radius:24px;background:var(--amber-soft);color:var(--amber);display:flex;align-items:center;justify-content:center;margin:0 auto 14px">' +
        icon('wallet', 'i-lg') + '</div>' +
        '<div style="font-weight:800;font-size:14.5px;margin-bottom:6px">در حال انتقال به درگاه پرداخت امن</div>' +
        '<div style="font-size:12px;color:var(--ink-soft);line-height:2">پس از تکمیل پرداخت، به‌صورت خودکار به سامانه بازمی‌گردید.<br>لطفاً صفحه را نبندید.</div>' +
        '<div class="spinner" style="margin:16px auto 0"></div>' +
      '</div>'
    );

    sheet.footer.html(
      '<button class="btn block" id="go-gateway">' + icon('zap') + 'انتقال به درگاه</button>'
    );
    sheet.footer.find('#go-gateway').on('click', go);

    setTimeout(go, 1200);
  };
})();
