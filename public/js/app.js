/* Caffenet Lite — app.js
 * SPA shell: hash routing, global state binding, role-based navigation,
 * gateway-return handling. Port of src/App.tsx. */
(function () {
  'use strict';

  // CSP-safe boot data from the JSON data block.
  (function () {
    const el = document.getElementById('boot-data');
    window.__APP_BOOT = el ? JSON.parse(el.textContent || '{}') : {};
  })();

  const App = {
    screen: 'home',

    boot() {
      this.renderSplash();

      api.subscribe((state, event) => {
        // Periodic data syncs must NOT wipe the screen: rebuilding the whole
        // app bar / nav / screen on every notify is what made the SPA flicker
        // and appear to "refresh" constantly. Instead, update only the live
        // regions of the current screen in place. 'orders' only mutates order
        // data, so it follows the same incremental path (plus the nav badge).
        if (event === 'sync' || event === 'sync-error') {
          this.refreshCurrentScreen();
          // Refresh only the small header (wallet balance / sms badge) — the
          // screen body is left untouched, so nothing visibly "reloads".
          UI.renderAppBar({ titles: this.screenTitles() });
          return;
        }
        // Order mutations only change order data — refresh the live region and
        // the pending-count badge without rebuilding the screen.
        if (event === 'orders') {
          this.refreshCurrentScreen();
          UI.renderBottomNav(this.navItems(), this.screen);
          return;
        }
        // Identity / route-affecting events (auth, logout) still need a full
        // rebuild because navigation items and route guards may change.
        if (this._rerendering) return;
        this._rerendering = true;
        try { this.render(); } finally { this._rerendering = false; }
      });

      // A hash change is the single trigger for applying a route. navigate()
      // only mutates the hash (see below), so each navigation applies once.
      window.addEventListener('hashchange', () => this.applyRoute(true));

      $(document).ready(async () => {
        $('#splash').remove();
        await api.getCurrentUser();
        // Maintenance escape hatch («ورود مدیر» on the maintenance page):
        // the shell is served with ?entry=admin while the gate is closed.
        // Open the login sheet straight away and STOP — the public catalog
        // reads below are 503'd by the gate and their error bounce would
        // throw the tab back onto the maintenance page before the admin can
        // log in. After login the 'auth' notify triggers a full render; if a
        // non-admin logs in, the very next data call 503s and the tab lands
        // back on the maintenance page — exactly the intended behavior.
        if (new URLSearchParams(window.location.search).get('entry') === 'admin' && !api.state.currentUser) {
          if (window.Screens && typeof Screens.openAuth === 'function') Screens.openAuth();
          return;
        }
        await api.syncWithBackend();
        api.startSync();
        // «شما پیام جدید دارید» — unread-chat bar + order-card badges.
        if (window.ChatNotify) ChatNotify.start();
        this.applyRoute(true);
        this.handlePaymentReturn();
      });
    },

    renderSplash() {
      $('body').append(
        '<div class="splash" id="splash">' +
        '<img class="splash-logo" src="/pwa-192x192.png" alt="' + PU.escapeHtml(window.__APP_BOOT.siteTitle || 'کافی‌نت احسان') + '">' +
        '<div style="font-weight:800;font-size:15px;color:var(--ink)">' +
        PU.escapeHtml(window.__APP_BOOT.siteTitle || 'کافی‌نت احسان') + '</div>' +
        '<div class="spinner"></div></div>'
      );
    },

    /* ---------------- incremental refresh ---------------- */

    screenKey(screen) {
      return screen === 'home' ? 'home' : String(screen).replace('/', '_');
    },

    /* Re-render only the data-bound regions of the active screen. A screen
     * opts in by exposing Screens.refresh_<key>; screens without one simply
     * keep their current markup (no destructive rebuild, no spinner flash). */
    refreshCurrentScreen() {
      const $host = $('#screen-host');
      const explicit = window.Screens['refresh_' + this.screenKey(this.screen)];
      const bound = $host.data('caffenetRefresh');
      const ctx = {
        user: api.state.currentUser,
        screen: this.screen,
        navigate: (k) => this.navigate(k),
        state: api.state,
      };
      try {
        if (typeof explicit === 'function') explicit($host, ctx);
        else if (typeof bound === 'function') bound($host, ctx);
      } catch (e) { /* never break the shell */ }
    },

    /* ---------------- routing ---------------- */

    navigate(key) {
      const target = '#/' + key;
      // Changing location.hash fires the hashchange listener, which applies the
      // route exactly once. Calling applyRoute() here as well rendered the
      // screen twice, and every screen that fetches in its bind_* hook fired
      // its request twice (e.g. GET /api/users, GET /api/dashboard/stats).
      // Only when the hash already equals the target (a case where no
      // hashchange event fires) do we apply the route directly, so tapping the
      // currently-active tab still refreshes it.
      if (window.location.hash === target) this.applyRoute(true);
      else window.location.hash = target;
    },

    applyRoute(rerender) {
      const hash = (window.location.hash || '#/').replace(/^#\//, '');
      const user = api.state.currentUser;

      if (!hash) { this.screen = this.defaultScreen(user); }
      else { this.screen = hash; }

      // Role guards
      if (this.isAdminScreen(this.screen) && (!user || user.role !== 'admin')) {
        this.screen = user ? this.defaultScreen(user) : 'home';
      }
      if (this.screen === 'operator' && (!user || !['operator', 'admin'].includes(user.role))) {
        this.screen = user ? this.defaultScreen(user) : 'home';
      }
      // Guests may browse the full catalog (services are public reads), but the
      // wallet and their own orders require a session. Ordering still prompts
      // for login from the order form.
      if (['wallet', 'orders'].includes(this.screen) && !user) {
        this.screen = 'home';
      }
      // حالت «غیرفعال کردن پرداخت‌ها»: the wallet page is fully disabled —
      // deep links / stale hashes land on home instead.
      if (this.screen === 'wallet' && api.state.settings && api.state.settings.paymentsDisabled) {
        this.screen = 'home';
      }

      // Any navigation dismisses every open popup/sheet so a stale modal can
      // never linger over the newly rendered screen (e.g. after jumping to the
      // wallet from the "charge wallet" button on the shortage prompt).
      UI.closeAllSheets();

      if (rerender !== false) this.render();
    },

    isAdminScreen(screen) {
      return screen.startsWith('admin/');
    },

    defaultScreen(user) {
      if (!user) return 'home';
      if (user.role === 'customer') return 'home';
      if (user.role === 'operator') return 'operator';
      if (user.role === 'admin') return 'admin/dashboard';
      return 'home';
    },

    /* ---------------- navigation items ---------------- */

    navItems() {
      const user = api.state.currentUser;
      if (!user) {
        return [
          { key: 'home', label: 'خانه', icon: 'home' },
          { key: 'catalog', label: 'خدمات', icon: 'grid' },
          { key: 'profile', label: 'پروفایل', icon: 'user' },
        ];
      }
      if (user.role === 'customer') {
        const pending = api.state.orders.filter((o) => ['pending_payment', 'submitted', 'processing'].includes(o.status)).length;
        const items = [
          { key: 'home', label: 'خانه', icon: 'home' },
          { key: 'catalog', label: 'دسته‌بندی', icon: 'grid' },
          { key: 'orders', label: 'سفارش‌ها', icon: 'folder', badge: pending },
        ];
        // حالت «غیرفعال کردن پرداخت‌ها»: تمام بخش‌های کیف پول از رابط کاربری حذف می‌شوند.
        if (!(api.state.settings && api.state.settings.paymentsDisabled)) {
          items.push({ key: 'wallet', label: 'کیف پول', icon: 'wallet' });
        }
        items.push({ key: 'profile', label: 'پروفایل', icon: 'user' });
        return items;
      }
      if (user.role === 'operator') {
        return [
          { key: 'home', label: 'خانه', icon: 'home' },
          { key: 'operator', label: 'صف کار', icon: 'folder' },
          { key: 'profile', label: 'پروفایل', icon: 'user' },
        ];
      }
      // admin
      return [
        { key: 'home', label: 'خانه', icon: 'home' },
        { key: 'admin/dashboard', label: 'داشبورد', icon: 'chart' },
        { key: 'admin/orders', label: 'سفارش‌ها', icon: 'folder' },
        { key: 'admin/users', label: 'کاربران', icon: 'users' },
        { key: 'admin/push', label: 'پوش', icon: 'bell' },
        { key: 'admin/more', label: 'بیشتر', icon: 'more' },
      ];
    },

    /* ---------------- screen titles ---------------- */

    screenTitles() {
      // Service pages carry their own title — resolve the service from the
      // slug segment so the app bar names the service being ordered.
      if (this.screen.indexOf('service/') === 0) {
        let slug = '';
        try { slug = decodeURIComponent(this.screen.split('/')[1] || ''); } catch (e) { slug = this.screen.split('/')[1] || ''; }
        const svc = (api.state.services || []).find((s) => (s.slug || '') === slug || String(s.id) === slug);
        return svc ? { t1: svc.title, t2: 'ثبت سفارش' } : { t1: 'جزئیات خدمت', t2: 'ثبت سفارش' };
      }
      const map = {
        home: { t1: 'کافی‌نت احسان', t2: 'خدمات آنلاین، سریع و مطمئن' },
        catalog: { t1: 'دسته‌بندی خدمات', t2: 'انتخاب خدمت مورد نظر' },
        orders: { t1: 'سفارش‌های من', t2: 'پیگیری وضعیت سفارش‌ها' },
        wallet: { t1: 'کیف پول', t2: 'موجودی و شارژ' },
        profile: { t1: 'پروفایل', t2: 'حساب کاربری' },
        operator: { t1: 'صف کار اپراتور', t2: 'سفارش‌های پرداخت‌شده' },
        'admin/dashboard': { t1: 'داشبورد مدیریت', t2: 'آمار و گزارش‌ها' },
        'admin/orders': { t1: 'سفارش‌ها و تراکنش‌ها', t2: 'رهگیری سفارش‌ها، اپراتورها و گردش کیف پول' },
        'admin/services': { t1: 'مدیریت خدمات', t2: 'کاتالوگ و فرم‌ساز پویا' },
        'admin/users': { t1: 'مدیریت کاربران', t2: 'نقش‌ها و مجوزها' },
        'admin/push': { t1: 'پوش نوتیفیکیشن', t2: 'ارسال اعلان، تاریخچه پوش‌ها' },
        'admin/more': { t1: 'ابزارهای مدیریتی', t2: 'کد تخفیف، تنظیمات، اسلایدر' },
        terms: { t1: 'قوانین و حریم خصوصی', t2: 'شرایط استفاده و حفظ اطلاعات' },
      };
      if (this.screen.indexOf('admin/user-transactions') === 0) {
        return { t1: 'تراکنش‌های کاربر', t2: 'تاریخچه کامل و فیلترها' };
      }
      return map[this.screen] || null;
    },

    /* ---------------- rendering ---------------- */

    render() {
      const user = api.state.currentUser;
      const $host = $('#screen-host');
      $host.off();
      // The home slider owns an interval — stop it before the DOM is replaced
      // so re-renders cannot accumulate timers.
      if (window.UI && UI.stopImageSlider) UI.stopImageSlider();

      UI.renderAppBar({ titles: this.screenTitles() });
      // Highlight the Orders tab while inside the per-user transactions screen.
      const navActive = this.screen.indexOf('admin/user-transactions') === 0 ? 'admin/orders' : this.screen;
      UI.renderBottomNav(this.navItems(), navActive);

      const ctx = {
        user,
        screen: this.screen,
        navigate: (k) => this.navigate(k),
        state: api.state,
      };

      let html = '';
      const scr = this.screen;

      if (scr === 'home') html = window.Screens.home(ctx);
      else if (scr === 'catalog') html = window.Screens.catalog(ctx);
      else if (scr === 'orders') html = window.Screens.orders(ctx);
      else if (scr === 'wallet') html = window.Screens.walletPage(ctx);
      else if (scr === 'profile') html = window.Screens.profile(ctx);
      else if (scr === 'operator') html = window.Screens.operator(ctx);
      else if (scr === 'admin/dashboard') html = window.Screens.adminDashboard(ctx);
      else if (scr === 'admin/orders') html = window.Screens.adminOrders(ctx);
      else if (scr === 'admin/services') html = window.Screens.adminServices(ctx);
      else if (scr === 'admin/users') html = window.Screens.adminUsers(ctx);
      else if (scr === 'admin/push') html = window.Screens.adminPush(ctx);
      else if (scr === 'admin/more') html = window.Screens.adminMore(ctx);
      else if (scr.indexOf('admin/user-transactions') === 0) {
        ctx.userId = scr.split('/')[2] || '';
        html = window.Screens.adminUserTransactions(ctx);
      }
      else if (scr === 'terms') html = window.Screens.termsPage(ctx);
      else if (scr.indexOf('service/') === 0) {
        // Dynamic route: #/service/{slug} — the dedicated order page.
        try { ctx.serviceSlug = decodeURIComponent(scr.split('/')[1] || ''); }
        catch (e) { ctx.serviceSlug = scr.split('/')[1] || ''; }
        html = window.Screens.serviceDetail(ctx);
      }
      else html = UI.emptyState('صفحه یافت نشد', 'این مسیر در دسترس نیست', 'alert');

      $host.html('<div class="screen">' + html + '</div>');

      // Post-render bindings for the active screen. The per-user transactions
      // screen is a dynamic route (admin/user-transactions/{id}), so it binds
      // through its fixed hook instead of the screenKey lookup.
      if (scr.indexOf('admin/user-transactions') === 0) {
        if (window.Screens.bind_admin_user_transactions) window.Screens.bind_admin_user_transactions($host, ctx);
      } else if (scr.indexOf('service/') === 0) {
        // Dynamic route — bind through the fixed hook (the slug suffix makes
        // the screenKey lookup impossible).
        if (window.Screens.bind_service_detail) window.Screens.bind_service_detail($host, ctx);
      } else {
        const name = this.screenKey(scr);
        if (window.Screens['bind_' + name]) window.Screens['bind_' + name]($host, ctx);
      }
    },

    handleAppBarAction(action) {
      const user = api.state.currentUser;
      if (action === 'login' || action === 'profile') {
        if (user) this.navigate('profile');
        else window.Screens.openAuth();
      } else if (action === 'wallet') {
        this.navigate('wallet');
      } else if (action === 'sms') {
        // Defense in depth: the icon is only RENDERED for admins
        // (UI.renderAppBar), but a stale cached bundle on a customer or
        // operator device must not be able to open the SMS audit log by
        // tapping the (theoretically absent) button.
        if (user && user.role === 'admin') window.Screens.openSmsInbox();
      } else if (action === 'fcm') {
        this.enablePushNotifications();
      }
    },

    /* Tap on the AppBar push icon — (re-)register the FCM token for this
     * device (covers: never registered, revoked, wiped fcm_tokens table)
     * and repaint the red/green status icon in place. FCM.enable() returns
     * WHICH link broke, so each failure gets its own diagnostic message —
     * the old generic "check browser permission" was misleading when
     * permission was already granted and the SDK/token fetch failed. */
    async enablePushNotifications() {
      if (!window.FCM) return;
      UI.busy(true);
      let res = { ok: false, reason: 'sdk' };
      try {
        res = await FCM.enable();
      } catch (e) {
        res = { ok: false, reason: 'sdk', error: e && e.message };
      } finally {
        UI.busy(false);
      }
      const $fcmBtn = $('#app-bar').find('[data-appbar-action="fcm"]');
      if ($fcmBtn.length) $fcmBtn.removeClass('fcm-on fcm-off').addClass(res.ok ? 'fcm-on' : 'fcm-off');
      const REASONS = {
        unsupported: 'مرورگر شما از اعلان وب پشتیبانی نمی‌کند.',
        disabled: 'پوش نوتیفیکیشن در تنظیمات سیستم خاموش است.',
        config: 'تنظیمات Firebase کامل نیست (API Key / Project ID / VAPID).',
        sdk: 'بارگذاری Firebase ناموفق بود — اینترنت را بررسی کنید و دوباره تپ کنید.',
        permission: 'اجازه نوتیفیکیشن داده نشده. در تنظیمات مرورگر اجازه اعلان را روی «مجاز» بگذارید.',
        token: 'دریافت توکن از Firebase ناموفق بود — چند لحظه بعد دوباره تلاش کنید.',
        subscribe: 'ثبت توکن در سرویس Firebase ممکن نشد — اتصال اینترنت را بررسی کنید و دوباره تپ کنید.',
        network: 'ارتباط با سرور برقرار نشد — اتصال اینترنت را بررسی کنید و دوباره تپ کنید.',
        server: 'ثبت توکن روی سرور ناموفق بود' + (res.status ? ' (کد ' + PU.toPersianDigits(res.status) + ')' : '') + ' — دوباره تلاش کنید.',
      };
      UI.toast(
        res.ok ? 'اعلان‌ها برای این دستگاه فعال شد ✅'
               : (REASONS[res.reason] || 'فعال‌سازی اعلان‌ها انجام نشد — دوباره تلاش کنید.'),
        res.ok ? 'success' : 'error'
      );
      if (!res.ok && res.error) console.warn('[FCM] enable failed:', res.reason, res.error);
    },

    /* ---------------- payment gateway return ---------------- */

    handlePaymentReturn() {
      const el = document.getElementById('payment-return');
      if (!el) return;
      let params = {};
      try { params = JSON.parse(el.textContent || '{}'); } catch (e) { return; }
      if (!params.status) return;

      // Clean the URL
      try { window.history.replaceState({}, '', '/'); } catch (e) { /* noop */ }

      if (params.status === 'success' && params.authority) {
        api.getPaymentStatus(params.authority)
          .then((st) => {
            // Refresh the wallet balance too — syncWithBackend() does not
            // re-read /api/auth/me, so a top-up return would otherwise show a
            // stale balance in the header and the order form. The resume below
            // runs only AFTER this refresh so the reopened order form sees the
            // topped-up balance instead of the pre-payment one.
            return api.getCurrentUser().catch(() => {})
              .then(() => api.syncWithBackend()).catch(() => {})
              .then(() => st);
          })
          .then((st) => {
            // An ORDER payment (BazaarPay checkout) returned: the tracker is
            // the meaningful landing spot — the draft was already consumed
            // when the order was created, so there is nothing to resume.
            const isOrder = st.purpose === 'order' && st.orderId;
            if (st.status === 'paid') {
              UI.toast(isOrder ? 'پرداخت سفارش با موفقیت انجام شد و به صف کار اضافه شد.' : 'پرداخت با موفقیت انجام شد.', 'success');
              if (isOrder) Screens.openOrderTracker(st.orderId);
              // The top-up succeeded → reopen the order form that led the
              // customer here, with everything they had filled restored.
              else this.resumeOrderDraft();
            } else if (st.status === 'pending') {
              UI.toast('پرداخت در حال بررسی است.', 'info');
              if (isOrder) Screens.openOrderTracker(st.orderId);
            } else {
              UI.toast('پرداخت ناموفق بود.', 'error');
              // Failed order payment → the tracker offers the retry buttons.
              if (isOrder) Screens.openOrderTracker(st.orderId);
            }
          })
          .catch(() => UI.toast('بررسی وضعیت پرداخت ناموفق بود.', 'error'));
      } else if (params.status === 'success') {
        // Return without a checkout token (e.g. BazaarPay direct-pay contract
        // callback): refresh everything so the paid order / topped-up wallet
        // shows up; the server has already applied the effects.
        api.getCurrentUser().catch(() => {}).then(() => api.syncWithBackend()).catch(() => {})
          .then(() => this.resumeOrderDraft());
        UI.toast('پرداخت با موفقیت انجام شد.', 'success');
      } else if (params.status === 'failed') {
        api.syncWithBackend().catch(() => {});
        UI.toast('پرداخت ناموفق بود یا لغو شد.', 'error');
      }
    },

    /* After a successful top-up return, reopen the order form whose snapshot
     * led the customer to the wallet page (see order-draft.js). Best-effort:
     * a missing/expired draft, another account's draft or a since-deleted
     * service is silently dropped — the boot must never break here. */
    resumeOrderDraft() {
      try {
        if (!window.OrderDraft) return;
        const user = api.state.currentUser;
        if (!user) return;
        const draft = OrderDraft.latestForUser(user.id);
        if (!draft) return;
        const service = (api.state.services || []).find((s) => String(s.id) === String(draft.serviceId));
        if (!service) { OrderDraft.clear(draft.serviceId); return; } // removed meanwhile
        // openOrderForm itself restores the draft state and toasts about it.
        Screens.openOrderForm(service);
      } catch (e) { /* best-effort — never break the boot */ }
    },
  };

  window.App = App;
  window.Screens = window.Screens || {};
  App.boot();
})();
