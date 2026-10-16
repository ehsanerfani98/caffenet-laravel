/* Caffenet Lite — screens/order-form.js
 * Dynamic form builder + live pricing preview + file upload + coupon +
 * payment method choice. Port of customer/OrderFormModal.tsx. */
(function () {
  'use strict';

  const MAX_FILE_MB = 25;

  window.Screens = window.Screens || {};

  // Values coming back from the API may be numbers while option values are
  // strings (or vice-versa); compare loosely so a configured default still
  // matches its option when we build the markup.
  function sameValue(a, b) {
    return a != null && b != null && String(a) === String(b);
  }

  function fieldInput(f, value) {
    const v = value != null ? value : (f.defaultValue != null ? f.defaultValue : '');
    const req = f.required ? ' <span class="req">*</span>' : '';
    let html = '<label class="field-label">' + PU.escapeHtml(f.label) + req + '</label>';

    switch (f.type) {
      case 'text':
      case 'number':
        html += '<input class="input" data-field="' + f.id + '" type="' + f.type + '" value="' + PU.escapeHtml(v) + '"' +
          (f.placeholder ? ' placeholder="' + PU.escapeHtml(f.placeholder) + '"' : '') + '>';
        break;
      case 'textarea':
        html += '<textarea class="textarea" data-field="' + f.id + '" rows="3"' +
          (f.placeholder ? ' placeholder="' + PU.escapeHtml(f.placeholder) + '"' : '') + '>' + PU.escapeHtml(v) + '</textarea>';
        break;
      case 'select':
        html += '<select class="select" data-field="' + f.id + '">';
        html += '<option value="">انتخاب کنید…</option>';
        (f.options || []).forEach((o) => {
          const extra = o.extraPrice ? ' (+' + PU.toPersianDigits(Number(o.extraPrice).toLocaleString('en-US')) + ' تومان)' : '';
          html += '<option value="' + PU.escapeHtml(o.value) + '"' + (sameValue(v, o.value) ? ' selected' : '') + '>' +
            PU.escapeHtml(o.label) + extra + '</option>';
        });
        html += '</select>';
        break;
      case 'radio':
        (f.options || []).forEach((o) => {
          const sel = sameValue(v, o.value);
          html += '<div class="radio-card ' + (sel ? 'sel' : '') + '" data-field-radio="' + f.id + '" data-value="' + PU.escapeHtml(o.value) + '">' +
            '<span class="rc-dot"></span><span>' + PU.escapeHtml(o.label) + '</span>' +
            (o.extraPrice ? '<span class="rc-price">+' + PU.toPersianDigits(Number(o.extraPrice).toLocaleString('en-US')) + ' ت</span>' : '') +
            '</div>';
        });
        break;
      case 'checkbox':
        html += '<label style="display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:600;margin-bottom:8px">' +
          '<input type="checkbox" data-field="' + f.id + '"' + (v ? ' checked' : '') + '> ' + PU.escapeHtml(f.label) + '</label>';
        break;
      case 'date':
        // Jalali date field — the shared datepicker.js watcher binds any
        // input[data-jdp] through a delegated focusin listener, so it works
        // inside this dynamically rendered sheet without extra wiring.
        html += '<input class="input" data-jdp data-field="' + f.id + '" readonly value="' + PU.escapeHtml(v) + '"' +
          (f.placeholder ? ' placeholder="' + PU.escapeHtml(f.placeholder) + '"' : '') + '>';
        break;
      default:
        html += '<input class="input" data-field="' + f.id + '" value="' + PU.escapeHtml(v) + '">';
    }

    if (f.helpText) html += '<div class="help-text">' + PU.escapeHtml(f.helpText) + '</div>';
    return html;
  }

  Screens.openOrderForm = function (service) {
    const user = api.state.currentUser;
    if (!user) { Screens.openAuth(); return; }
    // Only customers place orders for themselves; operators/admins are staff
    // accounts and are not the customer of an order.
    if (user.role !== 'customer') {
      UI.toast('ثبت سفارش تنها با حساب مشتری امکان‌پذیر است.', 'warning');
      return;
    }

    const settings = api.state.settings || {};
    // BazaarPay Direct Pay (دایرکت‌پی) — when enabled the customer picks the
    // payment method: wallet balance or direct payment through BazaarPay.
    const directPayEnabled = !!(settings.bazaarPay && settings.bazaarPay.directEnabled);
    const state = {
      formData: {},
      urgency: 'normal',
      deliveryMethod: 'download',
      deliveryAddress: '',
      deliveryPostalCode: '',
      customerNote: '',
      contactMethods: [],
      files: [],
      couponCode: '',
      couponDiscount: 0,
      couponError: null,
      paymentMethod: 'wallet',
    };

    // Pre-populate formData from each field's configured default value. Without
    // this, a select/radio that already renders with a default selection is not
    // reflected in state.formData, so required-field validation fails until the
    // customer manually re-picks the option (which is what fires the handlers
    // that normally populate formData).
    (service.fields || []).forEach((f) => {
      const def = f.defaultValue;
      if (def !== undefined && def !== null && def !== '') {
        state.formData[f.id] = def;
      } else if (f.type === 'checkbox') {
        state.formData[f.id] = false;
      }
    });

    const sheet = UI.sheet({
      title: 'ثبت سفارش',
      subtitle: service.title,
      icon: 'receipt',
      tone: 'emerald',
      size: 'tall',
    });

    function renderForm() {
      const needsAddress = state.deliveryMethod === 'courier';
      const couponsEnabled = settings.enableCoupons !== false;

      sheet.body.html(
        /* service dynamic fields */
        '<div class="form-fields">' +
          service.fields.map((f) => fieldInput(f, state.formData[f.id])).join('') +
        '</div>' +

        /* urgency */
        '<label class="field-label">سطح فوریت <span class="req">*</span></label>' +
        '<div class="segmented" id="urgency-seg">' +
          [['normal', 'معمولی'], ['urgent', 'فوری (×' + PU.toPersianDigits(settings.urgentPriceMultiplier || 1.3) + ')'], ['immediate', 'آنی (×' + PU.toPersianDigits(settings.immediatePriceMultiplier || 1.6) + ')']]
            .map(([k, l]) => '<button class="seg ' + (state.urgency === k ? 'on' : '') + '" data-urgency="' + k + '">' + l + '</button>').join('') +
        '</div>' +

        /* delivery */
        '<label class="field-label">روش دریافت</label>' +
        '<div class="segmented" id="delivery-seg">' +
          [['download', 'دانلود آنلاین'], ['in_person', 'حضوری'], ['courier', 'ارسال با پیک']]
            .map(([k, l]) => '<button class="seg ' + (state.deliveryMethod === k ? 'on' : '') + '" data-delivery="' + k + '">' + l + '</button>').join('') +
        '</div>' +
        (needsAddress ?
          '<label class="field-label">آدرس تحویل <span class="req">*</span></label>' +
          '<textarea class="textarea" id="delivery-address" rows="2" placeholder="نشانی کامل…">' + PU.escapeHtml(state.deliveryAddress) + '</textarea>' +
          '<label class="field-label">کد پستی</label>' +
          '<input class="input" id="delivery-postal" inputmode="numeric" value="' + PU.escapeHtml(state.deliveryPostalCode) + '">' : '') +

        /* contact methods — multi-select */
        '<label class="field-label">راه‌های ارتباط با شما <span class="req">*</span></label>' +
        '<div class="contact-grid" id="contact-methods">' +
          PU.contactMethods.map(([k, l]) =>
            '<span class="chip ' + (state.contactMethods.includes(k) ? 'active' : '') + '" data-contact="' + k + '">' + l + '</span>'
          ).join('') +
        '</div>' +
        '<div class="help-text">می‌توانید چند گزینه را انتخاب کنید تا اپراتور از همان راه با شما در تماس باشد.</div>' +

        /* files */
        '<label class="field-label">فایل‌های پیوست (اختیاری — حداکثر ' + PU.toPersianDigits(MAX_FILE_MB) + ' مگابایت)</label>' +
        '<div id="files-list"></div>' +
        '<button class="btn ghost block sm" id="add-file">' + icon('upload') + 'افزودن فایل</button>' +
        '<input type="file" id="file-input" class="hidden" multiple>' +

        /* coupon */
        (couponsEnabled ?
          '<label class="field-label">کد تخفیف</label>' +
          '<div style="display:flex;gap:8px">' +
            '<input class="input" id="coupon-code" placeholder="مثلاً WELCOME" style="text-transform:uppercase">' +
            '<button class="btn slate" id="apply-coupon">اعمال</button>' +
          '</div>' +
          '<div id="coupon-msg"></div>' : '') +

        /* customer note */
        '<label class="field-label">توضیحات سفارش</label>' +
        '<textarea class="textarea" id="customer-note" rows="2" placeholder="هر نکته‌ای برای اپراتور…">' + PU.escapeHtml(state.customerNote) + '</textarea>' +



        /* live price */
        '<div id="price-summary" style="margin-top:16px"></div>'
      );

      renderFiles();
      renderPrice();
    }

    function renderFiles() {
      const $list = sheet.body.find('#files-list');
      $list.html(state.files.map((f) =>
        '<div class="file-chip"><div class="fc-icon">' + icon('file') + '</div>' +
        '<div class="fc-main"><div class="fc-name">' + PU.escapeHtml(f.name) + '</div>' +
        '<div class="fc-size">' + PU.formatBytes(f.size) + '</div></div>' +
        '<button class="fc-del" data-del="' + PU.escapeHtml(f.url) + '">' + icon('trash') + '</button></div>'
      ).join(''));
      $list.find('[data-del]').on('click', async function () {
        const url = $(this).data('del');
        state.files = state.files.filter((f) => f.url !== url);
        try { await api.deleteUploadedFile(url); } catch (e) { /* best effort */ }
        renderFiles();
      });
    }

    function livePricing() {
      return Pricing.calculate({
        service,
        formData: state.formData,
        urgency: state.urgency,
        deliveryMethod: state.deliveryMethod,
        multipliers: {
          normal: 1,
          urgent: Number(settings.urgentPriceMultiplier || 1.3),
          immediate: Number(settings.immediatePriceMultiplier || 1.6),
        },
        courierCost: Number(settings.courierCost || 35000),
        discountAmount: state.couponDiscount,
      });
    }

    function renderPrice() {
      const p = livePricing();
      const balance = Number((api.state.currentUser && api.state.currentUser.walletBalance) || 0);
      const shortfall = Math.max(0, p.payable - balance);
      const methodRow = directPayEnabled
        ? '<label class="field-label" style="margin-top:14px">روش پرداخت</label>' +
          '<div class="segmented" id="pay-method-seg">' +
            '<button class="seg ' + (state.paymentMethod === 'wallet' ? 'on' : '') + '" data-pay-method="wallet">' + icon('wallet') + ' کیف پول</button>' +
            '<button class="seg ' + (state.paymentMethod === 'bazaarpay' ? 'on' : '') + '" data-pay-method="bazaarpay">' + icon('zap') + ' پرداخت مستقیم بازارپی</button>' +
          '</div>' +
          (state.paymentMethod === 'bazaarpay'
            ? '<div class="help-text" style="margin-top:6px">پرداخت از کیف پول/حساب بانکی کافه‌بازار شما؛ در اولین استفاده، تایید قرارداد از شما خواسته می‌شود.</div>'
            : '')
        : '';
      sheet.body.find('#price-summary').html(
        '<div class="price-summary">' +
          '<div class="ps-row"><span>جمع خدمات</span><span>' + PU.formatToman(p.subtotal) + '</span></div>' +
          (p.urgencyMultiplier > 1 ? '<div class="ps-row"><span>ضریب فوریت</span><span>×' + PU.toPersianDigits(p.urgencyMultiplier) + '</span></div>' : '') +
          (p.deliveryCost > 0 ? '<div class="ps-row"><span>هزینه پیک</span><span>' + PU.formatToman(p.deliveryCost) + '</span></div>' : '') +
          (p.discountAmount > 0 ? '<div class="ps-row" style="color:var(--emerald)"><span>تخفیف</span><span>−' + PU.formatToman(p.discountAmount) + '</span></div>' : '') +
          '<div class="ps-row total"><span>مبلغ قابل پرداخت</span><span class="val">' + PU.formatToman(p.payable) + '</span></div>' +
        '</div>' +
        methodRow +
        (shortfall > 0 && state.paymentMethod === 'wallet'
          ? '<div class="warn-box" style="margin-top:10px">موجودی کیف پول کافی نیست؛ <b>' + PU.formatToman(shortfall) + '</b> کسری دارید. ابتدا کیف پول را شارژ کنید' + (directPayEnabled ? ' یا «پرداخت مستقیم بازارپی» را انتخاب کنید.' : '.') + '</div>'
          : '')
      );

      sheet.body.find('[data-pay-method]').on('click', function () {
        state.paymentMethod = $(this).data('pay-method');
        renderPrice();
      });
    }

    /* ---- events (delegated on sheet body) ---- */

    sheet.body.on('input change', '[data-field]', function () {
      const id = $(this).data('field');
      const field = service.fields.find((f) => f.id === id);
      if (field && field.type === 'checkbox') state.formData[id] = this.checked;
      else if (field && field.type === 'number') state.formData[id] = this.value;
      else state.formData[id] = this.value;
      renderPrice();
    });

    sheet.body.on('click', '[data-field-radio]', function () {
      const id = $(this).data('field-radio');
      state.formData[id] = $(this).data('value');
      sheet.body.find('[data-field-radio="' + id + '"]').removeClass('sel');
      $(this).addClass('sel');
      renderPrice();
    });

    sheet.body.on('click', '[data-urgency]', function () {
      state.urgency = $(this).data('urgency');
      sheet.body.find('[data-urgency]').removeClass('on');
      $(this).addClass('on');
      renderPrice();
    });

    sheet.body.on('click', '[data-delivery]', function () {
      state.deliveryMethod = $(this).data('delivery');
      sheet.body.find('[data-delivery]').removeClass('on');
      $(this).addClass('on');
      renderForm(); // address fields toggle
    });

    sheet.body.on('click', '[data-contact]', function () {
      const key = $(this).data('contact');
      const idx = state.contactMethods.indexOf(key);
      if (idx === -1) state.contactMethods.push(key);
      else state.contactMethods.splice(idx, 1);
      $(this).toggleClass('active', state.contactMethods.includes(key));
    });

    sheet.body.on('click', '#add-file', () => sheet.body.find('#file-input').trigger('click'));

    sheet.body.on('change', '#file-input', async function () {
      const files = Array.from(this.files || []);
      for (const file of files) {
        if (file.size > MAX_FILE_MB * 1024 * 1024) {
          UI.toast('حجم فایل «' + file.name + '» بیش از ' + MAX_FILE_MB + ' مگابایت است.', 'error');
          continue;
        }
        try {
          UI.busy(true);
          const uploaded = await api.uploadRealFile(file);
          state.files.push(uploaded);
          renderFiles();
        } catch (e) {
          UI.toast(e.message, 'error');
        } finally {
          UI.busy(false);
        }
      }
      $(this).val('');
    });

    sheet.body.on('click', '#apply-coupon', async function () {
      const code = ($('#coupon-code').val() || '').trim();
      const p = Pricing.calculate({
        service, formData: state.formData, urgency: state.urgency, deliveryMethod: state.deliveryMethod,
        multipliers: { normal: 1, urgent: Number(settings.urgentPriceMultiplier || 1.3), immediate: Number(settings.immediatePriceMultiplier || 1.6) },
        courierCost: Number(settings.courierCost || 35000),
      });
      if (!code) { state.couponDiscount = 0; state.couponError = null; renderPrice(); return; }
      try {
        const r = await api.validateCoupon(code, p.totalBeforeDiscount);
        state.couponCode = code;
        state.couponDiscount = r.discount;
        state.couponError = null;
        sheet.body.find('#coupon-msg').html('<div class="info-box" style="margin-top:8px;color:var(--emerald);background:var(--emerald-soft)">کد تخفیف اعمال شد: ' + PU.formatToman(r.discount) + ' تخفیف</div>');
      } catch (e) {
        state.couponCode = '';
        state.couponDiscount = 0;
        sheet.body.find('#coupon-msg').html('<div class="warn-box" style="margin-top:8px">' + PU.escapeHtml(e.message) + '</div>');
      }
      renderPrice();
    });

    /* ---- footer: submit ---- */
    sheet.footer.html('<button class="btn emerald block" id="order-submit">' + icon('check') + 'ثبت و ادامه به پرداخت</button>');

    sheet.footer.find('#order-submit').on('click', async function () {
      const p = livePricing();

      // client-side validation mirrors the server
      for (const f of service.fields) {
        if (f.required) {
          const v = state.formData[f.id];
          if (v === undefined || v === '' || (f.type === 'checkbox' && !v)) {
            UI.toast('تکمیل فیلد «' + f.label + '» الزامی است.', 'warning');
            return;
          }
        }
      }
      if (state.deliveryMethod === 'courier' && !state.deliveryAddress.trim()) {
        UI.toast('برای ارسال با پیک، وارد کردن آدرس الزامی است.', 'warning');
        return;
      }
      if (!state.contactMethods.length) {
        UI.toast('حداقل یک راه ارتباطی را انتخاب کنید.', 'warning');
        return;
      }

      // Wallet payments require sufficient balance up-front — never persist an
      // order the customer cannot pay for. BazaarPay Direct Pay has no such
      // requirement (money comes from the user's Bazaar wallet/bank), so the
      // balance check is skipped for it.
      const balance = Number((api.state.currentUser && api.state.currentUser.walletBalance) || 0);
      if (state.paymentMethod === 'wallet' && balance < p.payable) {
        Screens.openWalletShortage(p.payable, balance);
        return;
      }

      const payload = {
        serviceId: service.id,
        formData: state.formData,
        urgency: state.urgency,
        deliveryMethod: state.deliveryMethod,
        deliveryAddress: state.deliveryAddress || undefined,
        deliveryPostalCode: state.deliveryPostalCode || undefined,
        customerNote: state.customerNote || undefined,
        contactMethods: state.contactMethods,
        customerFiles: state.files,
        couponCode: state.couponCode || undefined,
      };

      const $btn = $(this).prop('disabled', true);
      UI.busy(true);
      try {
        const order = await api.createOrder(payload);

        // ── BazaarPay Direct Pay ──
        if (state.paymentMethod === 'bazaarpay') {
          sheet.close();
          try {
            const r = await api.payOrderDirect(order.id);
            if (r.status === 'paid') {
              UI.toast('پرداخت مستقیم با بازارپی انجام شد و سفارش به صف کار اضافه شد.', 'success');
            } else if (r.status === 'contract_pending') {
              // First use: the user must sign the contract on BazaarPay; the
              // callback finishes the payment automatically after signing.
              UI.toast('برای فعال‌سازی پرداخت مستقیم، قرارداد را در بازارپی تایید کنید.', 'info');
              Screens.openPaymentRedirect({ gateway: 'bazaarpay', redirectUrl: r.contractRedirectUrl });
            } else if (r.status === 'pending') {
              UI.toast(r.message || 'پرداخت در حال بررسی است؛ وضعیت سفارش به‌صورت خودکار به‌روز می‌شود.', 'info');
            } else {
              UI.toast(r.error || 'پرداخت مستقیم ناموفق بود — از صفحه سفارش دوباره تلاش کنید.', 'error');
            }
          } catch (e) {
            UI.toast(e.message + ' — می‌توانید از صفحه سفارش‌ها دوباره تلاش کنید.', 'error');
          }
          Screens.openOrderTracker(order.id);
          return;
        }

        // ── Wallet payment ──
        try {
          await api.payOrderWithWallet(order.id);
          sheet.close();
          UI.toast('پرداخت از کیف پول انجام شد و سفارش به صف کار اضافه شد.', 'success');
          Screens.openOrderTracker(order.id);
        } catch (e) {
          if (e.code === 'INSUFFICIENT_WALLET') {
            // Rare race: the balance changed between the check and the debit.
            sheet.close();
            Screens.openWalletShortage(e.data && e.data.required, e.data && e.data.balance);
            Screens.openOrderTracker(order.id);
            return;
          }
          UI.toast(e.message + ' — می‌توانید از صفحه سفارش‌ها دوباره تلاش کنید.', 'error');
          sheet.close();
          Screens.openOrderTracker(order.id);
        }
      } catch (e) {
        if (e.code === 'INSUFFICIENT_WALLET') {
          // Server-side guard: no order was created.
          Screens.openWalletShortage(e.data && e.data.required, e.data && e.data.balance);
          return;
        }
        UI.toast(e.message, 'error');
      } finally {
        $btn.prop('disabled', false);
        UI.busy(false);
      }
    });

    // keep address/note in sync
    sheet.body.on('input', '#delivery-address', function () { state.deliveryAddress = this.value; });
    sheet.body.on('input', '#delivery-postal', function () { state.deliveryPostalCode = this.value; });
    sheet.body.on('input', '#customer-note', function () { state.customerNote = this.value; });

    renderForm();
  };
})();
