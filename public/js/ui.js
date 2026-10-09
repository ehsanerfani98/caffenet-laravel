/* Caffenet Lite — ui.js
 * Shared UI components: Sheet (bottom sheet), Toast, AppBar, BottomNav,
 * ImageSlider, OrderStatusBadge — ports of src/components/common/*. */
(function () {
  'use strict';

  const STATUS_MAP = {
    pending_payment: { label: 'در انتظار پرداخت', tone: 'pending_payment' },
    submitted: { label: 'ثبت‌شده', tone: 'submitted' },
    processing: { label: 'در حال انجام', tone: 'processing' },
    ready: { label: 'آماده تحویل / دانلود', tone: 'ready' },
    delivered: { label: 'تحویل داده شده', tone: 'delivered' },
    failed: { label: 'ناموفق', tone: 'failed' },
    cancelled: { label: 'لغو شده', tone: 'cancelled' },
    refunded: { label: 'عودت شده', tone: 'refunded' },
  };

  const TONE_CLASS = {
    sky: 'tone-sky', emerald: 'tone-emerald', amber: 'tone-amber',
    indigo: 'tone-indigo', rose: 'tone-rose', slate: 'tone-slate', dark: 'tone-dark',
  };

  let openSheets = [];
  // Single owner of the hero-slider autoplay interval. Kept module-scoped so a
  // re-render can never leak an old interval still driving a detached DOM.
  let sliderTimer = null;

  window.UI = {
    STATUS_MAP,

    statusBadge(status) {
      const meta = STATUS_MAP[status] || STATUS_MAP.pending_payment;
      return '<span class="pill ' + meta.tone + '">' + PU.escapeHtml(meta.label) + '</span>';
    },

    /* ---------- Sheet ---------- */

    sheet(options) {
      const opts = Object.assign({ title: '', subtitle: '', icon: 'info', tone: 'sky', size: 'auto', onClose: null, footer: null }, options);
      const $host = $('#sheet-host');

      const $backdrop = $(
        '<div class="sheet-backdrop">' +
          '<div class="sheet ' + (opts.size !== 'auto' ? opts.size : '') + '">' +
            '<div class="sheet-handle"></div>' +
            '<div class="sheet-head">' +
              '<div class="sh-icon ' + (TONE_CLASS[opts.tone] || TONE_CLASS.sky) + '">' + icon(opts.icon) + '</div>' +
              '<div class="sh-titles">' +
                '<div class="sh-title">' + PU.escapeHtml(opts.title) + '</div>' +
                (opts.subtitle ? '<div class="sh-sub">' + PU.escapeHtml(opts.subtitle) + '</div>' : '') +
              '</div>' +
              '<button class="sheet-close" aria-label="بستن">' + icon('x') + '</button>' +
            '</div>' +
            '<div class="sheet-body"></div>' +
            '<div class="sheet-footer"></div>' +
          '</div>' +
        '</div>'
      );

      const api = {
        el: $backdrop,
        body: $backdrop.find('.sheet-body'),
        footer: $backdrop.find('.sheet-footer'),
        close() {
          $backdrop.remove();
          openSheets = openSheets.filter((s) => s !== api);
          document.body.style.removeProperty('overflow');
          if (opts.onClose) opts.onClose();
        },
      };

      $backdrop.find('.sheet-close').on('click', api.close);
      $backdrop.on('click', (e) => { if (e.target === $backdrop[0]) api.close(); });

      $host.append($backdrop);
      openSheets.push(api);
      return api;
    },

    closeAllSheets() {
      openSheets.slice().forEach((s) => s.close());
      // Also dismiss any stray busy overlay so navigation never leaves a
      // blocking spinner on screen.
      $('#busy-overlay').addClass('hidden');
      document.body.style.removeProperty('overflow');
    },

    /* ---------- Toast ---------- */

    toast(message, type) {
      type = type || 'info';
      const icons = { success: 'checkcircle', error: 'xcircle', warning: 'alert', info: 'info' };
      const $t = $(
        '<div class="toast ' + type + '">' + icon(icons[type] || 'info') +
        '<span>' + PU.escapeHtml(message) + '</span></div>'
      );
      $('#toast-host').append($t);
      setTimeout(() => {
        $t.addClass('leaving');
        setTimeout(() => $t.remove(), 220);
      }, 3200);
    },

    /* ---------- AppBar ---------- */

    renderAppBar(ctx) {
      const user = api.state.currentUser;
      const settings = api.state.settings || {};
      const isAdmin = user && user.role === 'admin';
      const isCustomer = user && user.role === 'customer';

      let actions = '';
      if (isCustomer) {
        actions += '<button class="chip-wallet" data-appbar-action="wallet">' +
          icon('wallet') + '<span>' + PU.formatToman(user.walletBalance) + '</span></button>';
      }
      if (isAdmin) {
        const smsCount = api.state.smsInboxUnread || 0;
        actions += '<button class="icon-btn" data-appbar-action="sms" aria-label="لاگ پیامک">' +
          icon('bell') + (smsCount ? '<span class="badge">' + PU.toPersianDigits(smsCount) + '</span>' : '') + '</button>';
      }
      if (user) {
        actions += '<button class="icon-btn" data-appbar-action="profile" aria-label="پروفایل">' + icon('user') + '</button>';
      } else {
        actions += '<button class="chip-wallet" data-appbar-action="login">' + icon('user') + '<span>ورود</span></button>';
      }

      const titles = ctx.titles || { t1: settings.siteTitle || window.__APP_BOOT.siteTitle || 'کافی‌نت احسان', t2: '' };

      $('#app-bar').html(
        '<div class="app-bar">' +
          '<div class="brand"><img class="brand-logo" src="/pwa-96x96.png" alt="' + PU.escapeHtml(titles.t1) + '"></div>' +
          '<div class="titles"><div class="t1">' + PU.escapeHtml(titles.t1) + '</div>' +
          (titles.t2 ? '<div class="t2">' + PU.escapeHtml(titles.t2) + '</div>' : '') + '</div>' +
          actions +
        '</div>'
      );

      $('#app-bar').off('click', '[data-appbar-action]').on('click', '[data-appbar-action]', function () {
        const action = $(this).data('appbar-action');
        if (window.App && App.handleAppBarAction) App.handleAppBarAction(action);
      });
    },

    /* ---------- BottomNav ---------- */

    renderBottomNav(items, activeKey) {
      const html = items.map((item) =>
        '<button class="nav-item ' + (item.key === activeKey ? 'active' : '') + '" data-nav="' + item.key + '">' +
          (item.badge ? '<span class="badge">' + PU.toPersianDigits(item.badge) + '</span>' : '') +
          icon(item.icon) +
          '<span>' + PU.escapeHtml(item.label) + '</span>' +
        '</button>'
      ).join('');
      $('#bottom-nav').html('<div class="bottom-nav">' + html + '</div>');

      $('#bottom-nav').off('click', '[data-nav]').on('click', '[data-nav]', function () {
        const key = $(this).data('nav');
        if (window.App && App.navigate) App.navigate(key);
      });
    },

    /* ---------- ImageSlider ---------- */

    imageSlider($container, slides) {
      const list = slides || [];
      if (!list.length) {
        $container.html(
          '<div class="hero-slider"><div class="caption" style="position:absolute;inset:0;padding:14px;background:linear-gradient(135deg,#1559de,#1147b2);color:#fff;display:flex;flex-direction:column;justify-content:center">' +
          '<div class="s1" style="font-size:16px;font-weight:700">به کافی‌نت احسان خوش آمدید</div>' +
          '<div class="s2" style="font-size:12px;opacity:.9;margin-top:4px">پرینت، خدمات دانشجویی، اداری و مالیاتی — همه آنلاین</div>' +
          '</div><div style="height:170px"></div></div>'
        );
        return;
      }

      let index = 0;

      function render() {
        const inner = list.map((s) =>
          '<div class="hero-slide">' +
            '<img src="' + PU.escapeHtml(s.imageUrl) + '" alt="" draggable="false">' +
            ((s.title || s.subtitle) ?
              '<div class="caption"><div class="s1">' + PU.escapeHtml(s.title || '') + '</div>' +
              (s.subtitle ? '<div class="s2">' + PU.escapeHtml(s.subtitle) + '</div>' : '') + '</div>' : '') +
          '</div>'
        ).join('');
        $container.html(
          '<div class="hero-slider"><div class="slides">' + inner + '</div></div>' +
          '<div class="slider-dots">' + list.map((_, i) => '<span class="dot ' + (i === index ? 'on' : '') + '"></span>').join('') + '</div>'
        );
        const $slides = $container.find('.slides');
        $slides.css('transform', 'translateX(' + (index * 100) + '%)'); // RTL

        $container.find('.dot').each(function (i) {
          $(this).on('click', () => {
            index = i;
            $container.find('.slides').css('transform', 'translateX(' + (index * 100) + '%)');
            $container.find('.dot').removeClass('on');
            $(this).addClass('on');
          });
        });
      }

      render();
      if (sliderTimer) { clearInterval(sliderTimer); sliderTimer = null; }
      if (list.length > 1) {
        sliderTimer = setInterval(() => {
          index = (index + 1) % list.length;
          $container.find('.slides').css('transform', 'translateX(' + (index * 100) + '%)');
          $container.find('.dot').removeClass('on').each(function (i) { if (i === index) $(this).addClass('on'); });
        }, 5000);
      }

      // touch swipe
      let startX = null;
      $container.find('.hero-slider').on('touchstart', (e) => { startX = e.originalEvent.touches[0].clientX; });
      $container.find('.hero-slider').on('touchend', (e) => {
        if (startX === null) return;
        const dx = e.originalEvent.changedTouches[0].clientX - startX;
        if (Math.abs(dx) > 60) {
          index = (index + (dx > 0 ? -1 : 1) + list.length) % list.length;
          $container.find('.slides').css('transform', 'translateX(' + (index * 100) + '%)');
          $container.find('.dot').removeClass('on').each(function (i) { if (i === index) $(this).addClass('on'); });
        }
        startX = null;
      });
    },

    stopImageSlider() {
      if (sliderTimer) { clearInterval(sliderTimer); sliderTimer = null; }
    },

    busy(on) {
      $('#busy-overlay').toggleClass('hidden', !on);
    },

    emptyState(title, subtitle, iconName) {
      return '<div class="empty-state">' + icon(iconName || 'folder') +
        '<div class="e1">' + PU.escapeHtml(title) + '</div>' +
        (subtitle ? '<div class="e2">' + PU.escapeHtml(subtitle) + '</div>' : '') + '</div>';
    },
  };
})();
