/* Caffenet Lite — screens/wallet.js
 * Wallet page (customer): balance, presets, custom top-up.
 * Port of customer/WalletModal.tsx + WalletScreen. */
(function () {
  'use strict';

  window.Screens = window.Screens || {};

  /* Wallet top-up presets come from business settings as [{amount, sku}] (each
   * carrying its Cafe Bazaar SKU), but legacy plain numbers [50000, …] are still
   * accepted; both reduce to a plain list of amounts for the customer UI. */
  const WALLET_DEFAULT_PRESETS = [50000, 100000, 200000, 500000];
  function walletPresetAmounts(raw) {
    const list = Array.isArray(raw) ? raw : WALLET_DEFAULT_PRESETS;
    const amounts = list
      .map((p) => (p && typeof p === 'object' ? Number(p.amount) : Number(p)))
      .filter((n) => n > 0);
    return amounts.length ? amounts : WALLET_DEFAULT_PRESETS.slice();
  }

  Screens.walletPage = function (ctx) {
    const user = ctx.user;
    if (!user) return UI.emptyState('برای مشاهده کیف پول وارد شوید', '', 'lock');
    if (user.role !== 'customer') return UI.emptyState('کیف پول فقط برای مشتریان فعال است', '', 'wallet');

    const settings = api.state.settings || {};
    const presets = walletPresetAmounts(settings.walletTopUpPresets);

    // Cafe Bazaar charges the FIXED price configured for each SKU and its
    // payment deep link carries no amount, so when Bazaar is the ACTIVE gateway
    // the wallet page offers only the preset packs (each mapped to a SKU) and
    // hides the free-form amount field. Zarinpal (free amount) is a mutually
    // exclusive gateway, so the custom field is rendered only when Bazaar is off.
    const bazaar = settings.bazaar;
    const bazaarGateway = !!(bazaar && bazaar.enabled);

    const presetsGrid =
      '<div class="svc-grid" id="wallet-presets"' + (bazaarGateway ? ' data-bazaar="1"' : '') + '>' +
        presets.map((amount) =>
          '<div class="svc-card" data-amount="' + amount + '" style="align-items:center;text-align:center">' +
            '<div style="font-weight:800;font-size:14px;color:var(--sky-deep)" class="persian-nums">' + PU.toPersianDigits(Number(amount).toLocaleString('en-US')) + '</div>' +
            '<div style="font-size:10.5px;color:var(--ink-soft)">تومان</div>' +
          '</div>'
        ).join('') +
      '</div>';

    const customBlock = bazaarGateway
      ? ''
      : '<label class="field-label">مبلغ دلخواه (بین ۱۰ هزار تا ۵۰ میلیون تومان)</label>' +
        '<input class="input" id="wallet-amount" inputmode="numeric" placeholder="مثلاً ۳۰۰۰۰۰">' +
        '<button class="btn emerald block" id="wallet-topup" style="margin-top:12px">' + icon('wallet') + 'شارژ آنلاین</button>';

    return (
      '<div class="card" style="background:linear-gradient(135deg,var(--sky),var(--sky-deep));color:#fff;border:none">' +
        '<div style="font-size:12px;opacity:.9">موجودی کیف پول</div>' +
        '<div style="font-size:26px;font-weight:900;margin-top:6px" class="persian-nums">' + PU.formatToman(user.walletBalance) + '</div>' +
        '<div style="font-size:11px;opacity:.8;margin-top:8px">' + icon('shield', 'i-sm') + ' موجودی فقط با پرداخت آنلاین شارژ می‌شود</div>' +
      '</div>' +

      '<div class="section-title">' + icon('plus') + 'شارژ کیف پول</div>' +
      presetsGrid +
      customBlock +

      '<div class="info-box" style="margin-top:16px"> افزایش موجودی پس از تایید درگاه به‌صورت خودکار اعمال می‌شود.<br>پس از شارژ، می‌توانید سفارش خود را ثبت و از کیف پول پرداخت کنید.</div>'
    );
  };

  Screens.bind_wallet = function ($host, ctx) {
    const settings = api.state.settings || {};
    const bazaar = settings.bazaar;
    const bazaarGateway = !!(bazaar && bazaar.enabled);

    // Single path shared by preset taps and the custom-amount button. When
    // Bazaar is the active gateway it is always `bazaar` (fixed SKU price);
    // otherwise it is Zarinpal, which accepts the free-form amount.
    const startTopUp = async (amount, useBazaar) => {
      if (!amount || amount < 10000) {
        UI.toast('حداقل مبلغ شارژ ۱۰٬۰۰۰ تومان است.', 'warning');
        return;
      }
      if (amount > 50000000) {
        UI.toast('حداکثر مبلغ شارژ ۵۰٬۰۰۰٬۰۰۰ تومان است.', 'warning');
        return;
      }
      UI.busy(true);
      try {
        const pay = await api.startPayment('wallet', {
          amount,
          gateway: useBazaar ? 'bazaar' : undefined,
        });
        Screens.openPaymentRedirect(pay);
      } catch (e) {
        UI.toast(e.message, 'error');
      } finally {
        UI.busy(false);
      }
    };

    $host.find('[data-amount]').on('click', function () {
      const amount = Number($(this).data('amount'));
      if (bazaarGateway) {
        // Bazaar has no free amount: tapping a pack charges it right away (the
        // custom-amount field is not rendered in that mode). The deep link only
        // works inside the Bazaar app, so guard against creating an orphan
        // payment when running in a plain browser.
        if (!(window.BazaarIap && window.BazaarIap.isInBazaar())) {
          UI.toast('پرداخت کافه‌بازار فقط داخل اپلیکیشن کافه‌بازار امکان‌پذیر است.', 'warning');
          return;
        }
        startTopUp(amount, true);
        return;
      }
      $host.find('[data-amount]').removeClass('selected');
      $(this).addClass('selected');
      $host.find('#wallet-amount').val(amount).trigger('input');
    });

    $host.find('#wallet-topup').on('click', async function () {
      const raw = PU.toLatinDigits($host.find('#wallet-amount').val());
      const amount = Number(String(raw).replace(/[^\d]/g, ''));
      const $btn = $(this).prop('disabled', true);
      try {
        await startTopUp(amount, false);
      } finally {
        $btn.prop('disabled', false);
      }
    });
  };

  /* Shared wallet-shortage prompt. Used by the order form and the order tracker
   * when the wallet cannot cover a service payment; it explains the shortfall
   * and guides the customer to the wallet page to top up. */
  Screens.openWalletShortage = function (required, balance) {
    const need = Number(required || 0);
    const have = Number(balance || 0);
    const shortfall = Math.max(0, need - have);

    const sheet = UI.sheet({
      title: 'موجودی کیف پول کافی نیست',
      subtitle: '',
      icon: 'wallet',
      tone: 'amber',
    });

    sheet.body.html(
      '<div class="warn-box" style="margin-bottom:12px">برای ثبت و پرداخت این سفارش، ابتدا کیف پول خود را شارژ کنید.</div>' +
      '<div class="price-summary">' +
        '<div class="ps-row"><span>مبلغ قابل پرداخت</span><span>' + PU.formatToman(need) + '</span></div>' +
        '<div class="ps-row"><span>موجودی فعلی کیف پول</span><span>' + PU.formatToman(have) + '</span></div>' +
        '<div class="ps-row total"><span>مبلغ کمبود</span><span class="val">' + PU.formatToman(shortfall) + '</span></div>' +
      '</div>'
    );

    sheet.footer.html('<button class="btn emerald block" id="go-wallet">' + icon('plus') + 'شارژ کیف پول</button>');
    sheet.footer.find('#go-wallet').on('click', function () {
      // Close every open sheet (this shortage prompt plus any parent sheet such
      // as the order form or order tracker) so no popup lingers over the wallet
      // page after navigating there to top up.
      UI.closeAllSheets();
      if (window.App && window.App.navigate) window.App.navigate('wallet');
    });
  };
})();
