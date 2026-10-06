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
      ['coupons', 'tag', 'tone-amber', 'کدهای تخفیف', 'ساخت و مدیریت کدهای تخفیف'],
      ['settings', 'cog', 'tone-sky', 'تنظیمات کسب‌وکار', 'عنوان سایت، ضرایب قیمت و سرویس‌ها'],
      ['system', 'settings', 'tone-indigo', 'تنظیمات سیستمی', 'اعتبارنامه پیامک و درگاه، حالت‌های آزمایشی'],
      ['slider', 'image', 'tone-emerald', 'اسلایدر صفحه اصلی', 'بنرهای صفحه ورود'],
      ['sms', 'sms', 'tone-slate', 'لاگ پیامک‌ها', 'آرشیو پیامک‌های ارسالی'],
      ['guide', 'info', 'tone-dark', 'راهنمای استقرار', 'نکات نصب و راه‌اندازی'],
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
      if (key === 'coupons') openCoupons();
      else if (key === 'settings') openBusinessSettings();
      else if (key === 'system') openSystemSettings();
      else if (key === 'slider') openSliderManager();
      else if (key === 'sms') openSmsInbox();
      else if (key === 'guide') openGuide();
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
            ' • انقضا: ' + PU.toPersianDigits(c.expireDate) + (c.isActive ? '' : ' • غیرفعال') + '</div></div>' +
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
        '<label class="field-label">تاریخ انقضا (میلادی YYYY-MM-DD)</label>' +
        '<input class="input" id="c-exp" value="' + PU.escapeHtml(c ? c.expireDate : '') + '" dir="ltr">' +
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
      '<label style="display:flex;gap:8px;align-items:center;font-size:12.5px;font-weight:600;margin:12px 2px">' +
      '<input type="checkbox" id="s-wallet"' + (s.enableWalletPayment !== false ? ' checked' : '') + '> پرداخت با کیف پول فعال باشد</label>' +
      '<label style="display:flex;gap:8px;align-items:center;font-size:12.5px;font-weight:600;margin:12px 2px">' +
      '<input type="checkbox" id="s-coupons"' + (s.enableCoupons !== false ? ' checked' : '') + '> کدهای تخفیف فعال باشند</label>' +
      '<label class="field-label">شماره‌های مدیران (با کاما)</label><input class="input" id="s-admins" dir="ltr" value=""><div class="help-text">در «تنظیمات سیستمی» یا با دستور artisan قابل ویرایش است.</div>'
    );
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
          enableWalletPayment: sheet.body.find('#s-wallet').is(':checked'),
          enableCoupons: sheet.body.find('#s-coupons').is(':checked'),
        });
        UI.toast('تنظیمات ذخیره شد.', 'success');
        sheet.close();
      } catch (e) { UI.toast(e.message, 'error'); }
    });
  }

  /* ================= System settings ================= */

  function openSystemSettings() {
    const sheet = UI.sheet({ title: 'تنظیمات سیستمی', subtitle: 'اعتبارنامه‌ها ماسک‌شده نمایش داده می‌شوند', icon: 'settings', tone: 'indigo', size: 'tall' });
    sheet.body.html('<div class="spinner" style="margin:30px auto"></div>');

    const CATEGORY_TITLES = {
      general: 'عمومی', auth: 'احراز هویت', sms: 'پیامک',
      payment: 'پرداخت', test: 'حالت‌های آزمایشی', runtime: 'محیط اجرا (فقط‌خواندنی)',
    };

    api.fetchSystemSettings().then((r) => {
      const settings = r.settings || [];
      const grouped = {};
      settings.forEach((s) => { (grouped[s.category] = grouped[s.category] || []).push(s); });

      sheet.body.html(Object.keys(CATEGORY_TITLES).map((cat) => {
        const items = grouped[cat] || [];
        if (!items.length) return '';
        return '<div class="section-title">' + CATEGORY_TITLES[cat] + '</div>' + items.map((s) => {
          if (s.readOnly) {
            return '<div class="list-row"><div class="lr-main"><div class="lr-title" style="font-size:12px">' + PU.escapeHtml(s.label) + '</div>' +
              '<div class="lr-sub" dir="ltr">' + PU.escapeHtml(s.value || '—') + '</div></div>' + icon('lock') + '</div>';
          }
          if (s.type === 'boolean') {
            return '<div class="perm-item"><div class="pi-label">' + PU.escapeHtml(s.label) + '</div>' +
              '<label class="switch"><input type="checkbox" data-set="' + s.key + '"' + (s.value === '1' || s.value === 'true' ? ' checked' : '') + '><span class="track"></span></label></div>';
          }
          return '<label class="field-label">' + PU.escapeHtml(s.label) + (s.type === 'secret' ? ' (ماسک‌شده)' : '') + '</label>' +
            '<input class="input" data-set="' + s.key + '" type="text" dir="ltr" value="' + PU.escapeHtml(s.value || '') + '">';
        }).join('');
      }).join(''));

      sheet.footer.html('<button class="btn block" id="sys-save">ذخیره تنظیمات سیستمی</button>');
      sheet.footer.find('#sys-save').on('click', async function () {
        const payload = {};
        sheet.body.find('[data-set]').each(function () {
          const key = $(this).data('set');
          payload[key] = $(this).is(':checkbox') ? (this.checked ? '1' : '0') : $(this).val();
        });
        try {
          await api.updateSystemSettings(payload);
          UI.toast('تنظیمات سیستمی ذخیره شد.', 'success');
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
    sheet.body.html('<div class="spinner" style="margin:30px auto"></div>');

    api.getSmsInbox().then((logs) => {
      sheet.body.html(logs.map((l) =>
        '<div class="list-row"><div class="lr-icon ' +
          ({ sent: 'tone-emerald', failed: 'tone-rose', test: 'tone-amber' }[l.status] || 'tone-slate') + '">' + icon('sms') + '</div>' +
        '<div class="lr-main"><div class="lr-title">' + PU.escapeHtml(l.text) + '</div>' +
        '<div class="lr-sub">' + PU.toPersianDigits(l.phone) + ' • ' + PU.toJalaliDate(l.timestamp) +
        (l.error ? ' • خطا: ' + PU.escapeHtml(l.error) : '') + '</div></div></div>'
      ).join('') || UI.emptyState('پیامکی ارسال نشده', '', 'sms'));
    }).catch((e) => {
      sheet.body.html(UI.emptyState('خطا در بارگذاری لاگ', e.message, 'alert'));
    });
  }

  Screens.openSmsInbox = openSmsInbox;

  /* ================= Deployment guide ================= */

  function openGuide() {
    const sheet = UI.sheet({ title: 'راهنمای استقرار', icon: 'info', tone: 'dark', size: 'tall' });
    sheet.body.html(
      '<div class="info-box" style="line-height:2.4;direction:ltr;text-align:left;font-family:monospace;font-size:11px">' +
        'composer install --no-dev<br>' +
        'cp .env.example .env<br>' +
        'php artisan key:generate<br>' +
        'touch database/database.sqlite<br>' +
        'php artisan migrate --seed<br>' +
        'php artisan admin:set 09xxxxxxxxx<br>' +
        'php artisan serve<br>' +
        '# scheduler (crontab):<br>' +
        '* * * * * php artisan schedule:run' +
      '</div>' +
      '<div class="info-box" style="margin-top:12px">تنظیمات پیامک و درگاه را از «تنظیمات سیستمی» وارد کنید. برای راه‌اندازی بدون اعتبار واقعی، حالت‌های آزمایشی را فعال کنید.</div>'
    );
  }

  Object.assign(window.Screens, S);
})();
