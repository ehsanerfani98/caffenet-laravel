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

      // First open arms the overscroll repaint watchdog (binds once, no-op after).
      this.bindSheetWatchdog();

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

    /* Overscroll repaint watchdog.
       After a pull-to-refresh / rubber-band overscroll (Bazaar WebView shell,
       Chrome, iOS Safari) the compositor can lose absolutely-positioned overlay
       layers: the sheet stays in the DOM but is painted displaced out of the
       viewport and only reappears after the user scrolls (classic symptom:
       "the bottom sheet vanishes until I swipe the page once").

       The .sheet-backdrop translateZ(0) GPU layer prevents most of it; this
       watchdog is the second line of defense — every touch/scroll/resize while
       a sheet is open nudges each open backdrop with a one-frame transform
       toggle, which forces the compositor to re-evaluate and re-place the
       layer. rAF-guarded so a 120Hz touch stream costs at most one style write
       per frame. */
    _watchBound: false,
    _nudgePending: false,
    bindSheetWatchdog() {
      if (this._watchBound) return;
      this._watchBound = true;
      const self = this;
      const nudge = () => {
        if (!openSheets.length || self._nudgePending) return;
        self._nudgePending = true;
        requestAnimationFrame(() => {
          self._nudgePending = false;
          openSheets.forEach((s) => {
            const el = s.el && s.el[0];
            if (!el) return;
            el.style.transform = 'translateZ(0) translateY(0.001px)';
            requestAnimationFrame(() => { el.style.transform = ''; });
          });
        });
      };
      // 'scroll' does not bubble — the capture phase is the only way to also
      // observe the inner .screen-host scroller from window.
      window.addEventListener('scroll', nudge, true);
      window.addEventListener('resize', nudge);
      window.addEventListener('orientationchange', nudge);
      // touchend/touchcancel fire exactly when the rubber-band gesture settles
      // — the precise moment the layers get stuck.
      window.addEventListener('touchmove', nudge, { passive: true });
      window.addEventListener('touchend', nudge, { passive: true });
      window.addEventListener('touchcancel', nudge, { passive: true });
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

    /* ─── Carousel mouse drag-to-scroll ─────────────────────────
     * Touch devices already get native momentum panning on the
     * .cat-icon-carousel rows (CSS overflow-x + scroll-snap). This helper
     * adds the missing desktop affordance: drag the row with the mouse.
     * It is fully delegated on $root, so it keeps working after the home
     * screen re-renders its innerHTML (search typing, category refresh).
     * RTL note: we scroll with scrollBy(delta) — positive = same direction
     * as the pointer — so no browser-specific scrollLeft math is needed.
     */
    carouselDrag($root) {
      if (!$root || !$root.length) return;
      $root.on('pointerdown', '.cat-icon-carousel', function (e) {
        const oe = e.originalEvent || e;
        if (oe.pointerType !== 'mouse' || oe.button !== 0) return;
        const el = this;
        const startX = e.clientX; // displacement threshold anchor
        let lastX = e.clientX;    // per-move scroll delta anchor
        let moved = false;

        function onMove(ev) {
          const step = ev.clientX - lastX;
          lastX = ev.clientX;
          if (!moved && Math.abs(ev.clientX - startX) > 4) {
            moved = true;
            el.classList.add('dragging');
            el.style.userSelect = 'none';
          }
          if (moved) el.scrollBy({ left: step, top: 0 });
        }

        function onUp() {
          window.removeEventListener('pointermove', onMove);
          window.removeEventListener('pointerup', onUp);
          window.removeEventListener('pointercancel', onUp);
          el.classList.remove('dragging');
          el.style.userSelect = '';
          if (moved) {
            // Swallow the click that follows a real drag (capture phase fires
            // before every bubble handler, incl. jQuery delegation) so a
            // mouse-drag scroll never opens the order form.
            function killClick(ev) {
              ev.stopPropagation();
              el.removeEventListener('click', killClick, true);
            }
            el.addEventListener('click', killClick, true);
          }
        }

        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);
        window.addEventListener('pointercancel', onUp);
      });
    },

    busy(on) {
      $('#busy-overlay').toggleClass('hidden', !on);
    },

    emptyState(title, subtitle, iconName) {
      return '<div class="empty-state">' + icon(iconName || 'folder') +
        '<div class="e1">' + PU.escapeHtml(title) + '</div>' +
        (subtitle ? '<div class="e2">' + PU.escapeHtml(subtitle) + '</div>' : '') + '</div>';
    },

    /* ─── IconPicker ─────────────────────────────────────────────
     * Opens a bottom-sheet picker over the Font Awesome 6 free solid
     * library declared in `window.CATEGORY_ICON_LIBRARY`. Returns the
     * chosen icon key to the `onPick` callback.
     *
     * @param {object} opts
     *   - current   : string  currently selected key (highlighted)
     *   - onPick    : function(key, fa, label)  called when user picks
     *   - title     : string  sheet title (default: "انتخاب آیکن")
     *   - showColor : bool    also show a color swatch row (default false)
     *   - currentColor : string  initial color when showColor = true
     *   - onColor   : function(hex)  called when color changes
     */
    iconPicker(opts) {
      opts = opts || {};
      const LIB = window.CATEGORY_ICON_LIBRARY || [];
      const onPick = typeof opts.onPick === 'function' ? opts.onPick : function () {};
      const onColor = typeof opts.onColor === 'function' ? opts.onColor : function () {};
      const currentKey = opts.current || '';
      const showColor = !!opts.showColor;
      let currentColor = opts.currentColor || '';

      const sheet = UI.sheet({
        title: opts.title || 'انتخاب آیکن',
        icon: 'tag', tone: 'sky', size: 'tall',
      });

      // 15 preset color swatches — matches the previous category form.
      const PRESET_COLORS = [
        '#1559de', '#10aec8', '#0ca67d', '#40a83b', '#f4d33b',
        '#f08d37', '#e75b2e', '#d91543', '#ef6c7b', '#a82cc7',
        '#8138d4', '#475d93', '#159db5', '#124be0', '#e40012',
      ];

      function renderGrid(filter) {
        const q = (filter || '').trim().toLowerCase();
        const items = LIB.filter((it) => {
          if (!q) return true;
          return it.label.toLowerCase().indexOf(q) !== -1
            || it.key.toLowerCase().indexOf(q) !== -1
            || it.fa.toLowerCase().indexOf(q) !== -1;
        });
        if (!items.length) {
          return '<div class="iconpicker-empty">موردی یافت نشد.</div>';
        }
        return items.map((it) => {
          const sel = it.key === currentKey ? ' selected' : '';
          return (
            '<button type="button" class="iconpicker-cell' + sel + '" data-key="' + it.key + '" title="' + PU.escapeHtml(it.label) + '">' +
              '<span class="iconpicker-cell-icon">' + window.faIcon(it.fa) + '</span>' +
              '<span class="iconpicker-cell-label">' + PU.escapeHtml(it.label) + '</span>' +
            '</button>'
          );
        }).join('');
      }

      function renderBody() {
        const colorRow = showColor ? (
          '<div class="iconpicker-color-row">' +
            '<input class="input iconpicker-color-input" type="text" value="' + PU.escapeHtml(currentColor) + '" placeholder="رنگ هگز (مثل #a82cc7) یا خالی" style="margin-bottom:8px">' +
            '<div class="iconpicker-swatches">' +
              PRESET_COLORS.map((c) => (
                '<button type="button" class="iconpicker-swatch" data-color="' + c + '" style="background:' + c + '" aria-label="' + c + '"></button>'
              )).join('') +
            '</div>' +
          '</div>'
        ) : '';

        sheet.body.html(
          '<div class="iconpicker-search">' + icon('search') +
            '<input class="input" id="iconpicker-q" placeholder="جستجوی آیکن…" autocomplete="off">' +
          '</div>' +
          colorRow +
          '<div class="iconpicker-grid" id="iconpicker-grid">' + renderGrid('') + '</div>'
        );

        // Live search
        sheet.body.find('#iconpicker-q').on('input', PU.debounce(function () {
          sheet.body.find('#iconpicker-grid').html(renderGrid($(this).val()));
        }, 180));

        // Cell click → call onPick + close
        sheet.body.on('click', '.iconpicker-cell', function () {
          const key = $(this).data('key');
          const item = LIB.find((it) => it.key === key);
          if (!item) return;
          onPick(item.key, item.fa, item.label);
          sheet.close();
        });

        // Color row handlers
        if (showColor) {
          sheet.body.find('.iconpicker-color-input').on('input', PU.debounce(function () {
            currentColor = $(this).val().trim();
            onColor(currentColor);
          }, 200));
          sheet.body.find('.iconpicker-swatch').on('click', function () {
            currentColor = $(this).data('color');
            sheet.body.find('.iconpicker-color-input').val(currentColor);
            onColor(currentColor);
          });
        }
      }

      renderBody();
      return sheet;
    },
  };
})();
