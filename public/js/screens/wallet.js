/* Caffenet Lite — screens/wallet.js
 * Wallet page (customer): balance, presets, custom top-up.
 * Port of customer/WalletModal.tsx + WalletScreen. */
(function () {
  'use strict';

  window.Screens = window.Screens || {};

  Screens.walletPage = function (ctx) {
    const user = ctx.user;
    if (!user) return UI.emptyState('برای مشاهده کیف پول وارد شوید', '', 'lock');
    if (user.role !== 'customer') return UI.emptyState('کیف پول فقط برای مشتریان فعال است', '', 'wallet');

    const settings = api.state.settings || {};
    const presets = settings.walletTopUpPresets || [50000, 100000, 200000, 500000];

    return (
      '<div class="card" style="background:linear-gradient(135deg,var(--sky),var(--sky-deep));color:#fff;border:none">' +
        '<div style="font-size:12px;opacity:.9">موجودی کیف پول</div>' +
        '<div style="font-size:26px;font-weight:900;margin-top:6px" class="persian-nums">' + PU.formatToman(user.walletBalance) + '</div>' +
        '<div style="font-size:11px;opacity:.8;margin-top:8px">' + icon('shield') + ' موجودی فقط با پرداخت آنلاین شارژ می‌شود</div>' +
      '</div>' +

      '<div class="section-title">' + icon('plus') + 'شارژ کیف پول</div>' +
      '<div class="svc-grid" id="wallet-presets">' +
        presets.map((amount) =>
          '<div class="svc-card" data-amount="' + amount + '" style="align-items:center;text-align:center">' +
            '<div style="font-weight:800;font-size:14px;color:var(--sky-deep)" class="persian-nums">' + PU.toPersianDigits(Number(amount).toLocaleString('en-US')) + '</div>' +
            '<div style="font-size:10.5px;color:var(--ink-soft)">تومان</div>' +
          '</div>'
        ).join('') +
      '</div>' +

      '<label class="field-label">مبلغ دلخواه (بین ۱۰ هزار تا ۵۰ میلیون تومان)</label>' +
      '<input class="input" id="wallet-amount" inputmode="numeric" placeholder="مثلاً ۳۰۰۰۰۰">' +
      '<button class="btn emerald block" id="wallet-topup" style="margin-top:12px">' + icon('wallet') + 'شارژ آنلاین</button>' +

      '<div class="info-box" style="margin-top:16px">مبالغ پیشنهادی را می‌توانید از پنل مدیریت تغییر دهید. افزایش موجودی پس از تایید درگاه به‌صورت خودکار اعمال می‌شود.</div>'
    );
  };

  Screens.bind_walletPage = function ($host, ctx) {
    $host.find('[data-amount]').on('click', function () {
      $host.find('#wallet-amount').val($(this).data('amount'));
    });

    $host.find('#wallet-topup').on('click', async function () {
      const raw = PU.toLatinDigits($host.find('#wallet-amount').val());
      const amount = Number(raw.replace(/[^\d]/g, ''));
      if (!amount || amount < 10000) {
        UI.toast('حداقل مبلغ شارژ ۱۰٬۰۰۰ تومان است.', 'warning');
        return;
      }
      if (amount > 50000000) {
        UI.toast('حداکثر مبلغ شارژ ۵۰٬۰۰۰٬۰۰۰ تومان است.', 'warning');
        return;
      }
      const $btn = $(this).prop('disabled', true);
      UI.busy(true);
      try {
        const pay = await api.startPayment('wallet', { amount });
        Screens.openPaymentRedirect(pay);
      } catch (e) {
        UI.toast(e.message, 'error');
      } finally {
        $btn.prop('disabled', false);
        UI.busy(false);
      }
    });
  };
})();
