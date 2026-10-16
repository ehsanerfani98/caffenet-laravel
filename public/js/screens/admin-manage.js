/* Caffenet Lite — screens/admin-manage.js
 * Admin «more» screen: coupons / business settings / system settings /
 * slider manager / SMS inbox. Port of AdminDashboard sheets + SliderManager +
 * SmsInboxModal (part 2 of 2). */
(function () {
  'use strict';

  window.Screens = window.Screens || {};
  const S = {};

  /* ================= More (hub) ================= */

  S.adminMore = function (ctx) {
    const rows = [
      ['services', 'cog', 'tone-rose', 'مدیریت خدمات', 'کاتالوگ، دسته‌بندی و فرم‌ساز پویا'],
      ['coupons', 'tag', 'tone-amber', 'کدهای تخفیف', 'ساخت و مدیریت کدهای تخفیف'],
      ['settings', 'cog', 'tone-sky', 'تنظیمات کسب‌وکار', 'عنوان سایت، ضرایب قیمت و سرویس‌ها'],
      ['system', 'settings', 'tone-indigo', 'تنظیمات سیستمی', 'اعتبارنامه پیامک و درگاه، حالت‌های آزمایشی'],
      ['slider', 'image', 'tone-emerald', 'اسلایدر صفحه اصلی', 'بنرهای صفحه ورود'],
      ['sms', 'sms', 'tone-slate', 'لاگ پیامک‌ها', 'آرشیو پیامک‌های ارسالی'],
    ];
    return '<div class="card">' + rows.map(([key, ic, tone, t1, t2]) =>
      '<div class="list-row" data-open="' + key + '" style="cursor:pointer">' +
        '<div class="lr-icon ' + tone + '">' + icon(ic) + '</div>' +
        '<div class="lr-main"><div class="lr-title">' + t1 + '</div><div class="lr-sub">' + t2 + '</div></div>' +
        icon('arrowleft') + '</div>'
    ).join('') + '</div>';
  };

  S.bind_admin_more = function ($host, ctx) {
    $host.find('[data-open]').on('click', function () {
      const key = $(this).data('open');
      if (key === 'services') { if (window.App) App.navigate('admin/services'); }
      else if (key === 'coupons') openCoupons();
      else if (key === 'settings') openBusinessSettings();
      else if (key === 'system') openSystemSettings();
      else if (key === 'slider') openSliderManager();
      else if (key === 'sms') openSmsInbox();
    });
  };

  /* ================= Coupons ================= */

  function openCoupons() {
    const sheet = UI.sheet({ title: 'کدهای تخفیف', icon: 'tag', tone: 'amber', size: 'tall' });

    function render() {
      api.getCoupons().then((coupons) => {
        sheet.body.html(
          '<button class="btn block sm" id="new-coupon" style="margin-bottom:10px">' + icon('plus') + 'کد جدید</button>' +
          (coupons.map((c) =>
            '<div class="list-row"><div class="lr-icon tone-amber">' + icon('tag') + '</div>' +
            '<div class="lr-main"><div class="lr-title" style="direction:ltr;text-align:right">' + PU.escapeHtml(c.code) + ' — ' + PU.toPersianDigits(c.percent) + '٪</div>' +
            '<div class="lr-sub">سقف: ' + PU.formatToman(c.maxAmount) + ' • حداقل: ' + PU.formatToman(c.minOrderAmount) +
            ' • مصرف: ' + PU.toPersianDigits(c.usageCount) + '/' + PU.toPersianDigits(c.maxUsage || '∞') +
            ' • انقضا: ' + PU.toJalaliDateShort(c.expireDate) + (c.isActive ? '' : ' • غیرفعال') + '</div></div>' +
            '<button class="icon-btn" style="background:var(--slate-soft);color:var(--ink)" data-edit="' + c.id + '">' + icon('edit') + '</button>' +
            '<button class="icon-btn" style="background:var(--rose-soft);color:var(--rose)" data-del="' + c.id + '">' + icon('trash') + '</button></div>'
          ).join('') || UI.emptyState('کد تخفیفی ثبت نشده', '', 'tag'))
        );
        sheet.body.find('#new-coupon').on('click', () => couponForm(null));
        sheet.body.find('[data-edit]').on('click', function () {
          couponForm(coupons.find((c) => c.id === $(this).data('edit')));
        });
        sheet.body.find('[data-del]').on('click', async function () {
          try { await api.deleteCoupon($(this).data('del')); UI.toast('کد حذف شد.', 'success'); render(); }
          catch (e) { UI.toast(e.message, 'error'); }
        });
      }).catch((e) => { sheet.body.html(UI.emptyState('خطا', e.message, 'alert')); });
    }

    function couponForm(c) {
      const inner = UI.sheet({ title: c ? 'ویرایش کد' : 'کد تخفیف جدید', icon: 'tag', tone: 'amber' });
      inner.body.html(
        '<label class="field-label">کد <span class="req">*</span></label>' +
        '<input class="input" id="c-code" value="' + PU.escapeHtml(c ? c.code : '') + '" style="direction:ltr;text-transform:uppercase">' +
        '<label class="field-label">درصد <span class="req">*</span></label>' +
        '<input class="input" id="c-percent" inputmode="numeric" value="' + (c ? c.percent : 10) + '">' +
        '<label class="field-label">حداکثر تخفیف (۰ = بی‌سقف)</label>' +
        '<input class="input" id="c-max" inputmode="numeric" value="' + (c ? c.maxAmount : 0) + '">' +
        '<label class="field-label">حداقل مبلغ سفارش</label>' +
        '<input class="input" id="c-min" inputmode="numeric" value="' + (c ? c.minOrderAmount : 0) + '">' +
        '<label class="field-label">تاریخ انقضا</label>' +
        '<input class="input" id="c-exp-view" data-jdp data-jdp-target-value-input="#c-exp" data-jdp-target-value-type="gregorian" readonly placeholder="انتخاب تاریخ…" value="' + PU.escapeHtml(c && c.expireDate ? JDP.gregorianToJalaliString(c.expireDate) : '') + '">' +
        '<input type="hidden" id="c-exp" value="' + PU.escapeHtml(c ? c.expireDate : '') + '">' +
        '<label class="field-label">حداکثر استفاده (۰ = بی‌نهایت)</label>' +
        '<input class="input" id="c-maxuse" inputmode="numeric" value="' + (c ? c.maxUsage : 0) + '">' +
        '<label style="display:flex;gap:8px;align-items:center;font-size:12.5px;font-weight:600;margin:12px 2px">' +
        '<input type="checkbox" id="c-active"' + (!c || c.isActive ? ' checked' : '') + '> فعال</label>'
      );
      inner.footer.html('<button class="btn block" id="c-save">ذخیره</button>');
      inner.footer.find('#c-save').on('click', async function () {
        const payload = {
          code: inner.body.find('#c-code').val(),
          percent: Number(inner.body.find('#c-percent').val()),
          maxAmount: Number(inner.body.find('#c-max').val()),
          minOrderAmount: Number(inner.body.find('#c-min').val()),
          expireDate: inner.body.find('#c-exp').val(),
          maxUsage: Number(inner.body.find('#c-maxuse').val()),
          isActive: inner.body.find('#c-active').is(':checked'),
        };
        try {
          if (c) await api.updateCoupon(c.id, payload);
          else await api.saveCoupon(payload);
          UI.toast('کد تخفیف ذخیره شد.', 'success');
          inner.close();
          render();
        } catch (e) { UI.toast(e.message, 'error'); }
      });
    }

    render();
  }

  /* Wallet top-up presets — each row pairs an amount with the Cafe Bazaar SKU
     whose fixed panel price equals it. Accepts both the object shape
     ({amount, sku}) and legacy plain numbers. */
  const WALLET_DEFAULT_PRESETS = [50000, 100000, 200000, 500000];

  function presetRowHtml(preset) {
    const isObj = preset && typeof preset === 'object';
    const amount = isObj ? (preset.amount || '') : (preset || '');
    const sku = isObj ? (preset.sku || '') : '';
    return '<div class="preset-row" data-row>' +
      '<input class="input" data-preset-amount inputmode="numeric" placeholder="مبلغ (تومان)" value="' + PU.escapeHtml(String(amount)) + '">' +
      '<input class="input" data-preset-sku dir="ltr" autocomplete="off" placeholder="SKU کافه‌بازار" value="' + PU.escapeHtml(sku) + '">' +
      '<button type="button" class="btn rose sm" data-remove-preset title="حذف">' + icon('trash') + '</button>' +
    '</div>';
  }

  function presetRowsHtml(raw) {
    const list = Array.isArray(raw) && raw.length ? raw : WALLET_DEFAULT_PRESETS;
    return list.map(presetRowHtml).join('');
  }

  function collectPresets($body) {
    const out = [];
    $body.find('#s-presets [data-row]').each(function () {
      const amount = Number(PU.toLatinDigits(String($(this).find('[data-preset-amount]').val() || '')).replace(/[^\d]/g, ''));
      const sku = String($(this).find('[data-preset-sku]').val() || '').trim();
      if (amount > 0) out.push({ amount: amount, sku: sku });
    });
    return out;
  }

  /* ================= Business settings ================= */

  function openBusinessSettings() {
    const s = api.state.settings || {};
    const sheet = UI.sheet({ title: 'تنظیمات کسب‌وکار', icon: 'cog', tone: 'sky', size: 'tall' });

    sheet.body.html(
      '<label class="field-label">عنوان سایت</label><input class="input" id="s-title" value="' + PU.escapeHtml(s.siteTitle || '') + '">' +
      '<label class="field-label">آدرس دفتر</label><input class="input" id="s-addr" value="' + PU.escapeHtml(s.coffeenetAddress || '') + '">' +
      '<label class="field-label">تلفن دفتر</label><input class="input" id="s-phone" value="' + PU.escapeHtml(s.coffeenetPhone || '') + '">' +
      '<label class="field-label">ساعت کاری</label><input class="input" id="s-hours" value="' + PU.escapeHtml(s.workingHours || '') + '">' +
      '<label class="field-label">روز نگهداری فایل‌ها</label><input class="input" id="s-retention" inputmode="numeric" value="' + (s.fileRetentionDays || 14) + '">' +
      '<label class="field-label">ضریب سفارش فوری</label><input class="input" id="s-urgent" value="' + (s.urgentPriceMultiplier || 1.3) + '">' +
      '<label class="field-label">ضریب سفارش آنی</label><input class="input" id="s-immediate" value="' + (s.immediatePriceMultiplier || 1.6) + '">' +
      '<label class="field-label">هزینه پیک (تومان)</label><input class="input" id="s-courier" inputmode="numeric" value="' + (s.courierCost || 35000) + '">' +
      '<div class="section-title">' + icon('wallet') + 'مبالغ آماده شارژ کیف پول</div>' +
      '<div class="help-text" style="margin:0 2px 8px">برای هر مبلغ، شناسه (SKU) متناظر کافه‌بازار را وارد کنید؛ قیمت همان SKU در پنل کافه‌بازار باید با این مبلغ برابر باشد. داخل اپ کافه‌بازار فقط همین بسته‌ها قابل پرداخت‌اند.</div>' +
      '<div id="s-presets">' + presetRowsHtml(s.walletTopUpPresets) + '</div>' +
      '<button type="button" class="btn ghost block sm" id="s-add-preset" style="margin-top:8px">' + icon('plus') + 'افزودن مبلغ</button>' +
      '<div class="info-box" style="margin:12px 2px">پرداخت خدمات همیشه از طریق <b>کیف پول</b> انجام می‌شود و فعال است.</div>' +
      '<label style="display:flex;gap:8px;align-items:center;font-size:12.5px;font-weight:600;margin:12px 2px">' +
      '<input type="checkbox" id="s-coupons"' + (s.enableCoupons !== false ? ' checked' : '') + '> کدهای تخفیف فعال باشند</label>' +
      '<div class="section-title">' + icon('shield') + 'قوانین و حریم خصوصی</div>' +
      '<div class="help-text" style="margin:0 2px 8px">این متن پیش از دریافت شماره همراه و در صفحه «قوانین و حریم خصوصی» به کاربر نمایش داده می‌شود. متن به‌صورت ساده ذخیره می‌شود و نیازی به HTML نیست؛ خط‌های جدید به پاراگراف تبدیل می‌شوند.</div>' +
      '<textarea class="input textarea" id="s-terms" rows="12" style="min-height:200px;line-height:1.9">' + PU.escapeHtml(s.termsText || '') + '</textarea>'
    );
    // Preset editor: append/remove amount+SKU rows (keep at least one).
    sheet.body.on('click', '#s-add-preset', function () {
      sheet.body.find('#s-presets').append(presetRowHtml(null));
    });
    sheet.body.on('click', '[data-remove-preset]', function () {
      if (sheet.body.find('#s-presets [data-row]').length > 1) {
        $(this).closest('[data-row]').remove();
      } else {
        $(this).closest('[data-row]').find('input').val('');
      }
    });
    sheet.footer.html('<button class="btn block" id="s-save">ذخیره تنظیمات</button>');
    sheet.footer.find('#s-save').on('click', async function () {
      try {
        await api.saveSettings({
          siteTitle: sheet.body.find('#s-title').val(),
          coffeenetAddress: sheet.body.find('#s-addr').val(),
          coffeenetPhone: sheet.body.find('#s-phone').val(),
          workingHours: sheet.body.find('#s-hours').val(),
          fileRetentionDays: Number(sheet.body.find('#s-retention').val()),
          urgentPriceMultiplier: Number(sheet.body.find('#s-urgent').val()),
          immediatePriceMultiplier: Number(sheet.body.find('#s-immediate').val()),
          courierCost: Number(sheet.body.find('#s-courier').val()),
          walletTopUpPresets: collectPresets(sheet.body),
          enableCoupons: sheet.body.find('#s-coupons').is(':checked'),
          termsText: sheet.body.find('#s-terms').val(),
        });
        UI.toast('تنظیمات ذخیره شد.', 'success');
        sheet.close();
      } catch (e) { UI.toast(e.message, 'error'); }
    });
  }

  /* ================= System settings ================= */

  /* Bespoke, sectioned layout. Payments expose only two user choices — a
     single-select «نوع درگاه» plus one global «گزینه درگاه پرداخت» — and the
     save handler derives every underlying boolean. The SMS section mirrors this
     with a single «شبیه‌ساز / واقعی» selector that drives smsTestMode. Unknown
     system keys still render under «سایر تنظیمات». */
  function openSystemSettings() {
    const sheet = UI.sheet({ title: 'تنظیمات سیستمی', subtitle: 'اعتبارنامه‌ها ماسک‌شده نمایش داده می‌شوند', icon: 'settings', tone: 'indigo', size: 'tall' });
    sheet.body.html('<div class="spinner" style="margin:30px auto"></div>');

    const esc = (v) => PU.escapeHtml(v == null ? '' : String(v));

    api.fetchSystemSettings().then((r) => {
      const settings = r.settings || [];
      const byKey = {};
      settings.forEach((s) => { byKey[s.key] = s; });
      const val = (k) => (byKey[k] && byKey[k].value != null ? String(byKey[k].value) : '');
      const on = (k) => { const v = val(k).toLowerCase(); return v === '1' || v === 'true'; };
      const rendered = {};
      /* Derived from the visible selectors — never rendered as raw controls. */
      ['paymentEnabled', 'bazaarPayEnabled', 'paymentSandbox', 'paymentTestMode', 'smsTestMode', 'bazaarTestMode', 'paymentCurrency', 'paymentSandboxMerchantId',
       'bazaarPayDirectType', 'bazaarPayDirectPeriod']
        .forEach((k) => { rendered[k] = true; });

      /* Legacy Cafe Bazaar in-app IAP gateway (deep-link based) — replaced by
         the BazaarPay web gateway. The settings keys stay for backward
         compatibility but are no longer editable from the panel. */
      ['bazaarEnabled', 'bazaarSku', 'bazaarPackageName', 'bazaarApiSecret', 'bazaarDealerPackageName',
       'bazaarPermissionScope', 'bazaarRedirectUrl', 'bazaarConsumeUrl', 'bazaarValidateUrl', 'bazaarTimeout']
        .forEach((k) => { rendered[k] = true; });

      const state = {
        paymentMode: (on('paymentTestMode') || on('bazaarTestMode')) ? 'test' : (on('paymentSandbox') ? 'sandbox' : 'real'),
        smsSim: on('smsTestMode'),
        currency: val('paymentCurrency').toUpperCase() === 'IRR' ? 'IRR' : 'IRT',
        directType: ['wallet', 'direct_debit'].includes(val('bazaarPayDirectType')) ? val('bazaarPayDirectType') : 'wallet',
        directPeriod: ['daily', 'weekly', 'monthly', 'quarterly', 'semiannual', 'yearly'].includes(val('bazaarPayDirectPeriod')) ? val('bazaarPayDirectPeriod') : 'monthly',
      };

      function textField(key, label, opts) {
        opts = opts || {};
        rendered[key] = true;
        const isSecret = byKey[key] && byKey[key].type === 'secret';
        const currentValue = isSecret ? '' : val(key); // secrets start blank so the mask doesn't get re-saved
        const placeholderAttr = ' placeholder="' + esc(opts.placeholder || '') + '"';
        const helpHtml = opts.help ? '<div class="help-text">' + esc(opts.help) + '</div>' : '';
        const labelHtml = '<label class="field-label">' + esc(label) + (isSecret ? ' (ماسک‌شده — خالی = بدون تغییر)' : '') + (opts.req ? ' <span class="req">*</span>' : '') + '</label>';

        if (opts.textarea) {
          // Textarea for long values (e.g. the FCM service-account JSON).
          return labelHtml +
            '<textarea class="textarea" data-set="' + key + '" dir="ltr" autocomplete="off" rows="6"' + placeholderAttr + '>' + esc(isSecret ? '' : currentValue) + '</textarea>' +
            helpHtml;
        }
        return labelHtml +
          '<input class="input" data-set="' + key + '" type="text" dir="ltr" autocomplete="off"' + placeholderAttr + ' value="' + esc(currentValue) + '">' +
          helpHtml;
      }

      function switchRow(key, label, help) {
        rendered[key] = true;
        return '<div class="perm-item"><div class="pi-label">' + esc(label) + (help ? '<div class="pi-desc">' + esc(help) + '</div>' : '') + '</div>' +
          '<label class="switch"><input type="checkbox" data-set="' + key + '"' + (on(key) ? ' checked' : '') + '><span class="track"></span></label></div>';
      }

      function advBox(title, inner) {
        return '<details class="adv-box"><summary>' + icon('settings') + esc(title) +
          '<span class="adv-caret">' + icon('chevrondown') + '</span></summary><div class="adv-body">' + inner + '</div></details>';
      }

      function segment(group, current, label, options, help) {
        return '<label class="field-label">' + esc(label) + '</label>' +
          '<div class="segmented" data-mode="' + group + '">' + options.map(([v, t]) =>
            '<button type="button" class="seg ' + (current === v ? 'on' : '') + '" data-mode-val="' + v + '">' + t + '</button>'
          ).join('') + '</div>' +
          (help ? '<div class="help-text">' + esc(help) + '</div>' : '');
      }

      /* ---- عمومی ---- */
      const generalHtml =
        '<div class="section-title">' + icon('cog') + 'عمومی</div>' +
        textField('appUrl', 'نشانی پایه سایت', { placeholder: 'https://example.com', help: 'برای ساخت خودکار نشانی بازگشت درگاه لازم است.' }) +
        textField('adminPhones', 'شماره‌های مدیران (با کاما)', { placeholder: '09120000000, 09130000000' });

      /* ---- پیامک ---- */
      const smsHtml =
        '<div class="section-title">' + icon('sms') + 'پیامک (همه پترن‌محور)</div>' +
        switchRow('smsEnabled', 'سامانه پیامک فعال') +
        segment('sms', state.smsSim ? 'sim' : 'real', 'حالت پیامک',
          [['real', 'واقعی'], ['sim', 'شبیه‌ساز']],
          'در حالت شبیه‌ساز، پیامکی ارسال نمی‌شود و کد تایید به‌صورت آزمایشی نمایش داده می‌شود.') +
        '<div class="warn-box hidden" id="sms-disabled-note" style="margin-top:10px">سامانه پیامک غیرفعال است؛ تا زمانی که آن را فعال نکنید هیچ پیامکی ارسال نمی‌شود.</div>' +
        '<div id="sms-fields">' +
          textField('smsApiKey', 'کلید دسترسی ippanel', { req: true }) +
          textField('smsSender', 'شماره فرستنده', { req: true, help: 'شماره اختصاصی شما در ippanel.' }) +

          '<div class="info-box" style="margin:12px 0;padding:10px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;font-size:11px;line-height:1.8;color:#1e40af">' +
            '<b>راهنما:</b> برای هر رویداد، کد پترن را وارد کنید. سپس برای هر فیلد، نام متغیری که در ippanel تعریف کرده‌اید را بنویسید. ' +
            'مثال: در ippanel الگو «سفارش {factor} به مبلغ {amount}» را ساخته‌اید. ' +
            'پس در فیلد «نام متغیر شماره سفارش» بنویسید: factor. در فیلد «نام متغیر قیمت» بنویسید: amount.' +
          '</div>' +

          '<div id="sms-patterns-container" style="margin-top:12px">' +
            '<div style="text-align:center;padding:16px;color:var(--ink-soft)">در حال بارگذاری پترن‌ها…</div>' +
          '</div>' +

          switchRow('smsNotifyEnabled', 'ارسال پیامک اطلاع‌رسانی سفارش (در کنار پوش)') +
          textField('smsBaseUrl', 'آدرس پایه Edge API', { placeholder: 'https://edge.ippanel.com/v1', help: 'پیش‌فرض: https://edge.ippanel.com/v1 — این آدرس باید https://edge.ippanel.com/v1 باشد.' }) +
        '</div>';

      /* ---- پرداخت ---- */
      const zarinpalBlock =
        '<div class="gw-block" data-gw-block="paymentEnabled">' +
          '<div class="gw-title">' + icon('landmark') + 'زرین‌پال</div>' +
          textField('paymentMerchantId', 'کد پذیرنده زرین‌پال', { req: true, placeholder: 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx', help: 'از پنل زرین‌پال دریافت می‌شود.' }) +
          advBox('تنظیمات پیشرفته زرین‌پال',
            textField('paymentBaseUrl', 'آدرس پایه زرین‌پال', { placeholder: 'https://payment.zarinpal.com' }) +
            textField('paymentCallbackUrl', 'نشانی بازگشت درگاه', { placeholder: 'خالی = ساخت خودکار از نشانی سایت' }) +
            segment('currency', state.currency, 'واحد پولی',
              [['IRT', 'تومان'], ['IRR', 'ریال']],
              'مبالغ سامانه به تومان است؛ «تومان» باید انتخاب بماند تا مبلغ واقعی پرداخت شود.') +
            switchRow('paymentAutoVerify', 'اعتبارسنجی خودکار تراکنش (auto_verify)', 'زرین‌پال پس از پرداخت موفق تراکنش را خودکار تایید می‌کند و این سامانه با کد ۱۰۱ آن را نهایی می‌کند.')
          ) +
        '</div>';

      const bazaarPayBlock =
        '<div class="gw-block" data-gw-block="bazaarPayEnabled">' +
          '<div class="gw-title">' + icon('building') + 'بازارپی (کافه‌بازار)</div>' +
          textField('bazaarPayToken', 'توکن مرچنت بازارپی', { req: true, help: 'توکن تستی از مستندات بازارپی؛ پس از تایید نهایی، توکن اصلی را جایگزین کنید.' }) +
          textField('bazaarPayDestination', 'نام مقصد (Destination Name)', { req: true, placeholder: 'developers', help: 'برای توکن تستی از مقدار developers استفاده کنید؛ مقصد اصلی توسط تیم بازارپی ساخته می‌شود.' }) +
          '<div class="info-box" style="margin:10px 0;padding:10px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;font-size:11px;line-height:1.8;color:#1e40af">' +
            '<b>شارژ کیف پول:</b> کاربر به درگاه بازارپی منتقل و کیف پول شارژ می‌شود (هر مبلغی).<br>' +
            '<b>پرداخت مستقیم:</b> با امضای قرارداد دایرکت‌پی، سفارش‌ها بدون شارژ کیف پول و به‌صورت خودکار از کیف پول/حساب بانکی کافه‌بازار کاربر پرداخت می‌شود.' +
          '</div>' +
          switchRow('bazaarPayDirectEnabled', 'فعال بودن پرداخت مستقیم سفارش (دایرکت‌پی)') +
          '<div id="bazaarpay-direct-fields">' +
            segment('directType', state.directType, 'نوع قرارداد',
              [['wallet', 'کیف پول بازار'], ['direct_debit', 'دایرکت دبیت (بانک)']],
              'کیف پول: پرداخت از اعتبار کافه‌بازار کاربر؛ دایرکت دبیت: برداشت مستقیم از حساب بانکی.') +
            segment('directPeriod', state.directPeriod, 'دوره محدودیت',
              [['daily', 'روزانه'], ['weekly', 'هفتگی'], ['monthly', 'ماهانه'], ['quarterly', 'سه‌ماهه'], ['semiannual', 'شش‌ماهه'], ['yearly', 'سالانه']],
              'سقف پرداخت در هر دوره ریست می‌شود (مثلاً ماهانه = سقف هر ماه شمسی).') +
            textField('bazaarPayDirectAmountLimit', 'سقف پرداخت هر دوره (تومان)', { placeholder: '2000000' }) +
          '</div>' +
          advBox('تنظیمات پیشرفته بازارپی',
            textField('bazaarPayBaseUrl', 'آدرس پایه API بازارپی', { placeholder: 'https://api.bazaar-pay.ir' }) +
            textField('bazaarPayCallbackUrl', 'نشانی پایه بازگشت بازارپی', { placeholder: 'خالی = ساخت خودکار از نشانی سایت' }) +
            textField('bazaarPayRialRate', 'ضریب تبدیل تومان به ریال', { placeholder: '10', help: 'مبالغ بازارپی ریالی است؛ پیش‌فرض ۱۰ (هر تومان = ۱۰ ریال). فقط در صورت اطلاع تغییر دهید.' })
          ) +
        '</div>';

      const paymentHtml =
        '<div class="section-title">' + icon('wallet') + 'پرداخت</div>' +
        '<label class="field-label">نوع درگاه</label>' +
        '<div class="chip-row" id="gw-chips">' +
          '<button type="button" class="chip' + (on('paymentEnabled') ? ' active' : '') + '" data-gw="paymentEnabled">' + icon('landmark') + 'زرین‌پال</button>' +
          '<button type="button" class="chip' + (on('bazaarPayEnabled') ? ' active' : '') + '" data-gw="bazaarPayEnabled">' + icon('building') + 'بازارپی</button>' +
        '</div>' +
        '<div class="help-text">فقط یک درگاه می‌تواند فعال باشد؛ برای فعال‌کردن درگاه دیگر ابتدا درگاه فعلی را غیرفعال کنید. درگاه بازارپی برای نسخه وب‌ویو برنامه (پرداخت سمت سرور) است.</div>' +
        segment('payment', state.paymentMode, 'گزینه درگاه پرداخت',
          [['real', 'واقعی'], ['sandbox', 'سندباکس'], ['test', 'تست آفلاین']],
          '«سندباکس» به محیط آزمایشی زرین‌پال وصل می‌شود؛ «تست آفلاین» هر دو درگاه را شبیه‌سازی می‌کند (بدون اعتبارنامه). فیلدهای اعتبارنامه فقط در حالت «واقعی» نمایش داده می‌شوند.') +
        zarinpalBlock + bazaarPayBlock;

      /* ---- پوش نوتیفیکیشن (Firebase) ---- */
      const firebaseHtml =
        '<div class="section-title">' + icon('bell') + 'پوش نوتیفیکیشن (Firebase)</div>' +
        switchRow('fcmEnabled', 'پوش نوتیفیکیشن فعال') +
        '<div id="fcm-fields">' +
          textField('fcmProjectId', 'Firebase Project ID', { placeholder: 'caffenet-12345', help: 'از Project Settings → General در Firebase Console.' }) +
          textField('fcmApiKey', 'Web API Key (apiKey)', { placeholder: 'AIza...', help: 'از Project Settings → General → Web apps.' }) +
          textField('fcmAuthDomain', 'Auth Domain', { placeholder: 'caffenet-12345.firebaseapp.com' }) +
          textField('fcmMessagingSenderId', 'Messaging Sender ID', { placeholder: '123456789012' }) +
          textField('fcmAppId', 'App ID', { placeholder: '1:1234:web:abcd' }) +
          textField('fcmVapidKey', 'VAPID Key', { placeholder: 'BNbx...', help: 'Project Settings → Cloud Messaging → Web Push certificates.' }) +
          textField('fcmServiceAccount', 'JSON کلید حساب سرویس', { textarea: true, placeholder: 'محتوای فایل service-account.json', help: 'از Project Settings → Service Accounts → Generate New Private Key دانلود کنید و کل محتوای JSON را اینجا بچسبانید.' }) +
          '<div class="warn-box" style="margin-top:8px">این کلید امنیتی است؛ فقط در پنل مدیر قابل مشاهده و ویرایش. هرگز در کد فرانت‌اند قرار ندهید.</div>' +
          '<button type="button" class="btn ghost block sm" id="fcm-test-btn" style="margin-top:10px">' + icon('bell') + 'تست ارسال پوش به دستگاه من</button>' +
        '</div>';

      /* ---- Mark ALL SMS settings as rendered BEFORE building leftovers ----
       * loadSmsPatterns() runs synchronously but AFTER leftoversHtml is built.
       * We must mark every SMS setting key (pattern codes + variable names)
       * as rendered now so they don't also appear in the leftovers section.
       */
      ['smsEnabled', 'smsApiKey', 'smsSender', 'smsNotifyEnabled', 'smsBaseUrl',
       'smsPatternOtp', 'smsPatternOtp_code',
       'smsPatternStarted', 'smsPatternStarted_order_number', 'smsPatternStarted_customer_name', 'smsPatternStarted_service_title', 'smsPatternStarted_eta_minutes', 'smsPatternStarted_start_time', 'smsPatternStarted_tracking_url',
       'smsPatternReady', 'smsPatternReady_order_number', 'smsPatternReady_customer_name', 'smsPatternReady_tracking_url',
       'smsPatternRequeued', 'smsPatternRequeued_order_number', 'smsPatternRequeued_customer_name', 'smsPatternRequeued_queue_position',
       'smsPatternCancelled', 'smsPatternCancelled_order_number', 'smsPatternCancelled_customer_name',
       'smsPatternReminder', 'smsPatternReminder_order_number', 'smsPatternReminder_customer_name', 'smsPatternReminder_minutes_before', 'smsPatternReminder_start_time',
       'smsPatternPaid', 'smsPatternPaid_order_number', 'smsPatternPaid_amount',
       'smsPatternStatusChange', 'smsPatternStatusChange_order_number', 'smsPatternStatusChange_customer_name', 'smsPatternStatusChange_status_title',
       'smsPatternRefund', 'smsPatternRefund_customer_name', 'smsPatternRefund_amount',
       'smsPatternBroadcast', 'smsPatternBroadcast_title', 'smsPatternBroadcast_body',
      ].forEach((k) => { rendered[k] = true; });

      /* ---- fallback: any system key the layout above did not claim ---- */
      const leftoverItems = settings.filter((s) => !s.readOnly && !rendered[s.key]);
      const leftoversHtml = leftoverItems.length
        ? '<div class="section-title">' + icon('more') + 'سایر تنظیمات</div>' + leftoverItems.map((s) =>
            s.type === 'boolean' ? switchRow(s.key, s.label) : textField(s.key, s.label, {})
          ).join('')
        : '';

      sheet.body.html(
        '<div class="status-line" id="sys-status"><span class="dot"></span><span id="sys-status-text"></span></div>' +
        generalHtml + smsHtml + paymentHtml + firebaseHtml + leftoversHtml
      );

      // ── Load SMS pattern variables (dynamic UI) ──────────────
      loadSmsPatterns(sheet);

      // Show/hide FCM fields based on the master toggle.
      function updateFcmVisibility() {
        sheet.body.find('#fcm-fields').toggle(on('fcmEnabled'));
      }
      updateFcmVisibility();
      // Re-run when the toggle changes — listen on any change inside the sheet.
      sheet.body.on('change', '[data-key="fcmEnabled"]', updateFcmVisibility);

      // "تست ارسال پوش" button → POST /api/fcm/test
      sheet.body.find('#fcm-test-btn').on('click', async function () {
        const $btn = $(this).prop('disabled', true).text('در حال ارسال...');
        try {
          const r = await api.request('POST', '/api/fcm/test', {});
          UI.toast(r.message || 'پوش ارسال شد.', 'success');
        } catch (e) {
          UI.toast(e.message || 'ارسال ناموفق بود.', 'error');
        } finally {
          $btn.prop('disabled', false).html(icon('bell') + 'تست ارسال پوش به دستگاه من');
        }
      });

      /* ---- behaviour ---- */
      function chipOn(key) { return sheet.body.find('[data-gw="' + key + '"]').hasClass('active'); }

      /* «تست آفلاین» با زرین‌پالِ تنها خودکار به «سندباکس» می‌رود؛ حالت‌های
         بی‌معنا هم اصلاح می‌شوند (مثلاً سندباکس بدون زرین‌پال). */
      function normalizePaymentMode() {
        const zOn = chipOn('paymentEnabled');
        const bOn = chipOn('bazaarPayEnabled');
        if (state.paymentMode === 'sandbox' && !zOn) state.paymentMode = 'real';
        else if (state.paymentMode === 'test' && zOn && !bOn) state.paymentMode = 'sandbox';
        else if (state.paymentMode === 'test' && !zOn && !bOn) state.paymentMode = 'real';
      }

      function paintPaymentMode() {
        sheet.body.find('[data-mode="payment"] .seg').removeClass('on')
          .filter('[data-mode-val="' + state.paymentMode + '"]').addClass('on');
      }

      /* اعتبارنامه‌ها فقط وقتی نمایش داده می‌شوند که سامانه پیامک فعال و حالت
         «واقعی» باشد؛ وقتی سامانه غیرفعال است هیچ مودی کار نمی‌کند. */
      function refreshSmsFields() {
        const enabled = sheet.body.find('[data-set="smsEnabled"]').is(':checked');
        sheet.body.find('[data-mode="sms"] .seg').prop('disabled', !enabled);
        sheet.body.find('#sms-fields').toggle(enabled && !state.smsSim);
        sheet.body.find('#sms-disabled-note').toggle(!enabled);
      }

      function refreshStatus() {
        const names = [];
        if (chipOn('paymentEnabled')) names.push('زرین‌پال');
        if (chipOn('bazaarPayEnabled')) names.push('بازارپی');
        const modeLabel = { real: 'واقعی', sandbox: 'سندباکس', test: 'تست آفلاین' }[state.paymentMode];
        const $line = sheet.body.find('#sys-status');
        if (names.length) {
          $line.addClass('on');
          sheet.body.find('#sys-status-text').text(
            'درگاه‌های فعال: ' + names.join('، ') + ' • وضعیت: ' + modeLabel + (state.smsSim ? ' • پیامک: شبیه‌ساز' : '')
          );
        } else {
          $line.removeClass('on');
          sheet.body.find('#sys-status-text').text(state.smsSim ? 'پیامک روی شبیه‌ساز است؛ هیچ درگاهی فعال نیست.' : 'هیچ درگاهی فعال نیست.');
        }
      }

      /* Only the active gateway's fields show, and (like SMS) only in «واقعی»
         mode — sandbox/test need no credentials. */
      function syncGatewayBlocks() {
        const realMode = state.paymentMode === 'real';
        sheet.body.find('[data-gw]').each(function () {
          const active = $(this).hasClass('active');
          sheet.body.find('[data-gw-block="' + $(this).data('gw') + '"]').toggle(active && realMode);
        });
      }

      syncGatewayBlocks();
      normalizePaymentMode();
      paintPaymentMode();
      refreshSmsFields();

      sheet.body.find('[data-gw]').on('click', function () {
        const wasActive = $(this).hasClass('active');
        sheet.body.find('[data-gw]').removeClass('active');
        if (!wasActive) $(this).addClass('active');
        syncGatewayBlocks();
        normalizePaymentMode();
        paintPaymentMode();
        refreshStatus();
      });

      sheet.body.find('.segmented[data-mode]').on('click', '[data-mode-val]', function () {
        const $group = $(this).closest('[data-mode]');
        const group = $group.data('mode');
        $group.find('.seg').removeClass('on');
        $(this).addClass('on');
        if (group === 'payment') {
          state.paymentMode = $(this).data('mode-val');
          normalizePaymentMode();
          paintPaymentMode();
          syncGatewayBlocks();
        } else if (group === 'sms') {
          state.smsSim = ($(this).data('mode-val') === 'sim');
          refreshSmsFields();
        } else if (group === 'currency') {
          state.currency = $(this).data('mode-val');
        } else if (group === 'directType') {
          state.directType = $(this).data('mode-val');
        } else if (group === 'directPeriod') {
          state.directPeriod = $(this).data('mode-val');
        }
        refreshStatus();
      });

      // Show/hide Direct Pay contract fields with their master toggle.
      function updateDirectPayVisibility() {
        sheet.body.find('#bazaarpay-direct-fields').toggle(on('bazaarPayDirectEnabled'));
      }
      updateDirectPayVisibility();
      sheet.body.on('change', '[data-key="bazaarPayDirectEnabled"], [data-set="bazaarPayDirectEnabled"]', updateDirectPayVisibility);

      sheet.body.find('[data-set="smsEnabled"]').on('change', refreshSmsFields);

      refreshStatus();

      // ── SMS pattern variable management (dynamic UI) ──────────────
      // Renders all 10 pattern events, each with: pattern code field +
      // dynamic list of variable rows (var_name + data_field dropdown).
      // Variables are stored in the sms_pattern_vars table via API.

      // ── SMS patterns — fixed fields per type, no add/remove ──────
      function loadSmsPatterns(sheet) {
        const $container = sheet.body.find('#sms-patterns-container');
        if (!$container.length) return;

        const PATTERNS = [
          { key: 'otp',           label: '۱. کد تایید ورود (OTP)' },
          { key: 'order_started', label: '۲. شروع سفارش' },
          { key: 'order_ready',   label: '۳. آماده شدن سفارش' },
          { key: 'requeued',      label: '۴. انتقال به انتهای صف' },
          { key: 'cancelled',     label: '۵. لغو سفارش' },
          { key: 'reminder',      label: '۶. یادآوری نزدیک شروع' },
          { key: 'paid',          label: '۷. پرداخت سفارش (کیف پول)' },
          { key: 'status_change', label: '۸. تغییر وضعیت (دستی)' },
          { key: 'refund',        label: '۹. عودت مبلغ به کیف پول' },
          { key: 'broadcast',     label: '۱۰. اطلاع‌رسانی همگانی' },
        ];

        // Fixed fields per pattern type — mirrors SmsTypeRegistry.
        const FIELDS = {
          otp:           { setting: 'smsPatternOtp',           fields: { code: 'نام متغیر کد تایید' } },
          order_started: { setting: 'smsPatternStarted',       fields: { order_number: 'نام متغیر شماره سفارش', customer_name: 'نام متغیر نام مشتری', service_title: 'نام متغیر عنوان خدمت', eta_minutes: 'نام متغیر زمان انتظار', start_time: 'نام متغیر زمان شروع', tracking_url: 'نام متغیر لینک پیگیری' } },
          order_ready:   { setting: 'smsPatternReady',         fields: { order_number: 'نام متغیر شماره سفارش', customer_name: 'نام متغیر نام مشتری', tracking_url: 'نام متغیر لینک پیگیری' } },
          requeued:      { setting: 'smsPatternRequeued',      fields: { order_number: 'نام متغیر شماره سفارش', customer_name: 'نام متغیر نام مشتری', queue_position: 'نام متغیر موقعیت در صف' } },
          cancelled:     { setting: 'smsPatternCancelled',     fields: { order_number: 'نام متغیر شماره سفارش', customer_name: 'نام متغیر نام مشتری' } },
          reminder:      { setting: 'smsPatternReminder',      fields: { order_number: 'نام متغیر شماره سفارش', customer_name: 'نام متغیر نام مشتری', minutes_before: 'نام متغیر دقیقه تا شروع', start_time: 'نام متغیر زمان شروع' } },
          paid:          { setting: 'smsPatternPaid',          fields: { order_number: 'نام متغیر شماره سفارش', amount: 'نام متغیر قیمت' } },
          status_change: { setting: 'smsPatternStatusChange',  fields: { order_number: 'نام متغیر شماره سفارش', customer_name: 'نام متغیر نام مشتری', status_title: 'نام متغیر عنوان وضعیت' } },
          refund:        { setting: 'smsPatternRefund',        fields: { customer_name: 'نام متغیر نام مشتری', amount: 'نام متغیر مبلغ' } },
          broadcast:     { setting: 'smsPatternBroadcast',     fields: { title: 'نام متغیر عنوان', body: 'نام متغیر متن' } },
        };

        function renderPattern(p) {
          const def = FIELDS[p.key];
          const patternCodeKey = def.setting;
          const patternCode = byKey[patternCodeKey] ? byKey[patternCodeKey].value : '';
          let html =
            '<div class="card" style="margin-bottom:10px;padding:12px">' +
              '<div style="font-size:12px;font-weight:700;margin-bottom:8px">' + p.label + '</div>' +
              '<label class="field-label">کد پترن</label>' +
              '<input class="input" data-set="' + patternCodeKey + '" dir="ltr" placeholder="مثلاً 96ttx5xosutvyp7" value="' + esc(patternCode) + '">';
          rendered[patternCodeKey] = true;

          let i = 0;
          for (const fieldId in def.fields) {
            const settingKey = patternCodeKey + '_' + fieldId;
            const label = def.fields[fieldId];
            const val = byKey[settingKey] ? byKey[settingKey].value : '';
            html +=
              '<label class="field-label"' + (i === 0 ? ' style="margin:10px 0 4px"' : ' style="margin:8px 0 4px"') + '>' + label + '</label>' +
              '<input class="input" data-set="' + settingKey + '" dir="ltr" placeholder="نام متغیر در ippanel" value="' + esc(val) + '">';
            rendered[settingKey] = true;
            i++;
          }
          html += '</div>';
          return html;
        }

        $container.html(PATTERNS.map(renderPattern).join(''));
      }

      loadSmsPatterns(sheet);

      sheet.footer.html('<button class="btn block" id="sys-save">ذخیره تنظیمات سیستمی</button>');
      sheet.footer.find('#sys-save').on('click', async function () {
        const payload = {};
        sheet.body.find('[data-set]').each(function () {
          const key = $(this).data('set');
          payload[key] = $(this).is(':checkbox') ? (this.checked ? '1' : '0') : $(this).val();
        });
        sheet.body.find('[data-gw]').each(function () {
          payload[$(this).data('gw')] = $(this).hasClass('active') ? '1' : '0';
        });
        /* Derive the raw flags from the two visible selectors. */
        payload.paymentSandbox = (payload.paymentEnabled === '1' && state.paymentMode !== 'real') ? '1' : '0';
        payload.paymentTestMode = '0';
        payload.paymentCurrency = state.currency;
        payload.bazaarTestMode = (payload.bazaarEnabled === '1' && state.paymentMode === 'test') ? '1' : '0';
        payload.smsTestMode = state.smsSim ? '1' : '0';

        const warnings = [];
        if (payload.paymentEnabled === '1' && state.paymentMode === 'real'
            && !(byKey.paymentMerchantId && byKey.paymentMerchantId.hasValue)) {
          warnings.push('زرین‌پال در حالت واقعی است اما کد پذیرنده ثبت نشده است.');
        }
        if (payload.paymentEnabled === '1' && state.paymentMode === 'real'
            && !((payload.paymentCallbackUrl || '').trim() || (payload.appUrl || '').trim())) {
          warnings.push('برای درگاه واقعی، «نشانی پایه سایت» یا «نشانی بازگشت درگاه» الزامی است.');
        }
        if (payload.bazaarPayEnabled === '1' && state.paymentMode === 'real'
            && !(byKey.bazaarPayToken && byKey.bazaarPayToken.hasValue)) {
          warnings.push('بازارپی در حالت واقعی است اما توکن مرچنت ثبت نشده است.');
        }
        if (payload.bazaarPayEnabled === '1' && state.paymentMode === 'real'
            && !((payload.bazaarPayDestination || '').trim())) {
          warnings.push('بازارپی در حالت واقعی است اما «نام مقصد (Destination Name)» ثبت نشده است.');
        }
        if (payload.bazaarPayEnabled === '1' && payload.bazaarPayDirectEnabled === '1'
            && !((payload.appUrl || '').trim() || (payload.bazaarPayCallbackUrl || '').trim())) {
          warnings.push('برای پرداخت مستقیم بازارپی، «نشانی پایه سایت» یا «نشانی پایه بازگشت بازارپی» الزامی است.');
        }

        try {
          await api.updateSystemSettings(payload);
          UI.toast(warnings.length ? warnings[0] : 'تنظیمات سیستمی ذخیره شد.', warnings.length ? 'warning' : 'success');
          sheet.close();
        } catch (e) { UI.toast(e.message, 'error'); }
      });
    }).catch((e) => {
      sheet.body.html(UI.emptyState('خطا در بارگذاری تنظیمات', e.message, 'alert'));
    });
  }

  /* ================= Slider manager ================= */

  function openSliderManager() {
    const sheet = UI.sheet({ title: 'اسلایدر صفحه اصلی', icon: 'image', tone: 'emerald', size: 'tall' });

    function render() {
      api.request('GET', '/api/slider').then((slides) => {
        sheet.body.html(
          '<button class="btn block sm" id="new-slide" style="margin-bottom:10px">' + icon('plus') + 'اسلاید جدید</button>' +
          (slides.map((s) =>
            '<div class="card" style="margin-bottom:10px;padding:11px">' +
              '<img src="' + PU.escapeHtml(s.imageUrl) + '" style="width:100%;height:90px;object-fit:cover;border-radius:10px" alt="">' +
              '<div style="display:flex;align-items:center;justify-content:space-between;margin-top:8px">' +
                '<b style="font-size:12.5px">' + PU.escapeHtml(s.title || 'بدون عنوان') + '</b>' +
                '<span class="pill ' + (s.isActive ? 'ready' : 'cancelled') + '">' + (s.isActive ? 'فعال' : 'غیرفعال') + '</span>' +
              '</div>' +
              '<div style="display:flex;gap:6px;margin-top:8px">' +
                '<button class="btn ghost sm" data-edit="' + s.id + '">' + icon('edit') + ' ویرایش</button>' +
                '<button class="btn ghost sm" data-up="' + s.id + '">' + icon('arrowright') + '</button>' +
                '<button class="btn ghost sm" data-down="' + s.id + '">' + icon('arrowleft') + '</button>' +
                '<button class="btn rose sm" data-del="' + s.id + '">' + icon('trash') + '</button>' +
              '</div>' +
            '</div>'
          ).join('') || UI.emptyState('اسلایدی ثبت نشده', '', 'image'))
        );

        sheet.body.find('#new-slide').on('click', () => slideForm(null, slides));
        sheet.body.find('[data-edit]').on('click', function () {
          slideForm(slides.find((s) => s.id === $(this).data('edit')), slides);
        });
        sheet.body.find('[data-del]').on('click', async function () {
          try { await api.deleteSlide($(this).data('del')); UI.toast('اسلاید حذف شد.', 'success'); render(); }
          catch (e) { UI.toast(e.message, 'error'); }
        });
        sheet.body.find('[data-up]').on('click', async function () {
          const i = slides.findIndex((s) => s.id === $(this).data('up'));
          if (i > 0) {
            const ids = slides.map((s) => s.id);
            [ids[i - 1], ids[i]] = [ids[i], ids[i - 1]];
            try { await api.reorderSlides(ids); render(); } catch (e) { UI.toast(e.message, 'error'); }
          }
        });
        sheet.body.find('[data-down]').on('click', async function () {
          const i = slides.findIndex((s) => s.id === $(this).data('down'));
          if (i < slides.length - 1) {
            const ids = slides.map((s) => s.id);
            [ids[i], ids[i + 1]] = [ids[i + 1], ids[i]];
            try { await api.reorderSlides(ids); render(); } catch (e) { UI.toast(e.message, 'error'); }
          }
        });
      }).catch((e) => { sheet.body.html(UI.emptyState('خطا', e.message, 'alert')); });
    }

    function slideForm(slide, all) {
      const inner = UI.sheet({ title: slide ? 'ویرایش اسلاید' : 'اسلاید جدید', icon: 'image', tone: 'emerald' });
      let imageUrl = slide ? slide.imageUrl : '';

      function renderInner() {
        inner.body.html(
          '<div id="slide-preview">' +
            (imageUrl ? '<img src="' + PU.escapeHtml(imageUrl) + '" style="width:100%;height:110px;object-fit:cover;border-radius:10px;margin-bottom:10px" alt="">' :
              '<div class="warn-box" style="margin-bottom:10px">تصویری انتخاب نشده</div>') +
          '</div>' +
          '<button class="btn ghost block sm" id="slide-upload">' + icon('upload') + 'بارگذاری تصویر (حداکثر ۵ مگابایت)</button>' +
          '<input type="file" id="slide-file" accept="image/*" class="hidden">' +
          '<label class="field-label">عنوان</label><input class="input" id="slide-title" value="' + PU.escapeHtml(slide ? slide.title || '' : '') + '">' +
          '<label class="field-label">زیرعنوان</label><input class="input" id="slide-sub" value="' + PU.escapeHtml(slide ? slide.subtitle || '' : '') + '">' +
          '<label class="field-label">متن دکمه (اختیاری)</label><input class="input" id="slide-cta" value="' + PU.escapeHtml(slide ? slide.ctaLabel || '' : '') + '">' +
          '<label style="display:flex;gap:8px;align-items:center;font-size:12.5px;font-weight:600;margin:12px 2px">' +
          '<input type="checkbox" id="slide-active"' + (!slide || slide.isActive ? ' checked' : '') + '> فعال</label>'
        );

        inner.body.find('#slide-upload').on('click', () => inner.body.find('#slide-file').trigger('click'));
        inner.body.find('#slide-file').on('change', async function () {
          const file = this.files[0];
          if (!file) return;
          UI.busy(true);
          try {
            imageUrl = await api.uploadSliderImage(file);
            renderInner();
          } catch (e) { UI.toast(e.message, 'error'); }
          finally { UI.busy(false); }
          $(this).val('');
        });
      }

      inner.footer.html('<button class="btn block" id="slide-save">ذخیره اسلاید</button>');
      inner.footer.find('#slide-save').on('click', async function () {
        if (!imageUrl) { UI.toast('ابتدا تصویر را بارگذاری کنید.', 'warning'); return; }
        try {
          await api.saveSlide({
            id: slide ? slide.id : null,
            imageUrl,
            title: inner.body.find('#slide-title').val(),
            subtitle: inner.body.find('#slide-sub').val(),
            ctaLabel: inner.body.find('#slide-cta').val(),
            isActive: inner.body.find('#slide-active').is(':checked'),
          });
          UI.toast('اسلاید ذخیره شد.', 'success');
          inner.close();
          api.syncWithBackend();
          render();
        } catch (e) { UI.toast(e.message, 'error'); }
      });

      renderInner();
    }

    render();
  }

  /* ================= SMS inbox ================= */

  function openSmsInbox() {
    const sheet = UI.sheet({ title: 'لاگ پیامک‌ها', subtitle: 'آرشیو ممیزی ارسال', icon: 'sms', tone: 'slate', size: 'tall' });
    const canDelete = api.hasPermission('sms.delete') || api.hasPermission('sms.manage');

    function render() {
      sheet.body.html('<div class="spinner" style="margin:30px auto"></div>');

      api.getSmsInbox().then((logs) => {
        const clearBtn = (canDelete && logs.length)
          ? '<button class="btn ghost block sm" id="sms-clear" style="margin-bottom:10px">' + icon('trash') + 'حذف همه لاگ‌ها</button>'
          : '';
        const list = logs.map((l) =>
          '<div class="list-row"><div class="lr-icon ' +
            ({ sent: 'tone-emerald', failed: 'tone-rose', test: 'tone-amber' }[l.status] || 'tone-slate') + '">' + icon('sms') + '</div>' +
          '<div class="lr-main"><div class="lr-title">' + PU.escapeHtml(l.text) + '</div>' +
          '<div class="lr-sub">' + PU.toPersianDigits(l.phone) + ' • ' + PU.toJalaliDate(l.timestamp) +
          (l.error ? ' • خطا: ' + PU.escapeHtml(l.error) : '') + '</div></div>' +
          (canDelete ? '<button class="icon-btn" style="background:var(--rose-soft);color:var(--rose)" data-del="' + l.id + '">' + icon('trash') + '</button>' : '') +
          '</div>'
        ).join('');

        sheet.body.html(clearBtn + (list || UI.emptyState('پیامکی ارسال نشده', '', 'sms')));

        sheet.body.find('[data-del]').on('click', async function () {
          if (!window.confirm('این لاگ پیامک حذف شود؟')) return;
          UI.busy(true);
          try {
            await api.deleteSmsLog($(this).data('del'));
            UI.toast('لاگ پیامک حذف شد.', 'success');
            render();
          } catch (e) { UI.toast(e.message, 'error'); }
          finally { UI.busy(false); }
        });

        sheet.body.find('#sms-clear').on('click', async function () {
          if (!window.confirm('تمام لاگ‌های پیامک حذف شوند؟ این عملیات قابل بازگشت نیست.')) return;
          UI.busy(true);
          try {
            await api.clearSmsLogs();
            UI.toast('تمام لاگ‌های پیامک حذف شدند.', 'success');
            render();
          } catch (e) { UI.toast(e.message, 'error'); }
          finally { UI.busy(false); }
        });
      }).catch((e) => {
        sheet.body.html(UI.emptyState('خطا در بارگذاری لاگ', e.message, 'alert'));
      });
    }

    render();
  }

  Screens.openSmsInbox = openSmsInbox;

  Object.assign(window.Screens, S);
})();
