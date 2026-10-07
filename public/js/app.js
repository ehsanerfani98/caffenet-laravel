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
        await api.syncWithBackend();
        api.startSync();
        this.applyRoute(true);
        this.handlePaymentReturn();
      });
    },

    renderSplash() {
      $('body').append(
        '<div class="splash" id="splash">' + icon('coffee') +
        '<div style="font-weight:800;font-size:15px;color:var(--ink)">' +
        PU.escapeHtml(window.__APP_BOOT.siteTitle || 'کافی‌نت آنلاین') + '</div>' +
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
      if (['wallet', 'orders', 'catalog'].includes(this.screen) && !user) {
        this.screen = 'home';
      }

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
        return [
          { key: 'home', label: 'خانه', icon: 'home' },
          { key: 'catalog', label: 'دسته‌بندی', icon: 'grid' },
          { key: 'orders', label: 'سفارش‌ها', icon: 'folder', badge: pending },
          { key: 'wallet', label: 'کیف پول', icon: 'wallet' },
          { key: 'profile', label: 'پروفایل', icon: 'user' },
        ];
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
        { key: 'admin/services', label: 'خدمات', icon: 'cog' },
        { key: 'admin/users', label: 'کاربران', icon: 'users' },
        { key: 'admin/more', label: 'بیشتر', icon: 'more' },
      ];
    },

    /* ---------------- screen titles ---------------- */

    screenTitles() {
      const map = {
        home: { t1: 'کافی‌نت آنلاین', t2: 'خدمات آنلاین، سریع و مطمئن' },
        catalog: { t1: 'دسته‌بندی خدمات', t2: 'انتخاب خدمت مورد نظر' },
        orders: { t1: 'سفارش‌های من', t2: 'پیگیری وضعیت سفارش‌ها' },
        wallet: { t1: 'کیف پول', t2: 'موجودی و شارژ' },
        profile: { t1: 'پروفایل', t2: 'حساب کاربری' },
        operator: { t1: 'صف کار اپراتور', t2: 'سفارش‌های پرداخت‌شده' },
        'admin/dashboard': { t1: 'داشبورد مدیریت', t2: 'آمار و گزارش‌ها' },
        'admin/services': { t1: 'مدیریت خدمات', t2: 'کاتالوگ و فرم‌ساز پویا' },
        'admin/users': { t1: 'مدیریت کاربران', t2: 'نقش‌ها و مجوزها' },
        'admin/more': { t1: 'ابزارهای مدیریتی', t2: 'کد تخفیف، تنظیمات، اسلایدر' },
      };
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
      UI.renderBottomNav(this.navItems(), this.screen);

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
      else if (scr === 'admin/services') html = window.Screens.adminServices(ctx);
      else if (scr === 'admin/users') html = window.Screens.adminUsers(ctx);
      else if (scr === 'admin/more') html = window.Screens.adminMore(ctx);
      else html = UI.emptyState('صفحه یافت نشد', 'این مسیر در دسترس نیست', 'alert');

      $host.html('<div class="screen">' + html + '</div>');

      // Post-render bindings for the active screen.
      const name = this.screenKey(scr);
      if (window.Screens['bind_' + name]) window.Screens['bind_' + name]($host, ctx);
    },

    handleAppBarAction(action) {
      const user = api.state.currentUser;
      if (action === 'login' || action === 'profile') {
        if (user) this.navigate('profile');
        else window.Screens.openAuth();
      } else if (action === 'wallet') {
        this.navigate('wallet');
      } else if (action === 'sms') {
        window.Screens.openSmsInbox();
      }
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
            api.syncWithBackend();
            if (st.status === 'paid') {
              UI.toast('پرداخت با موفقیت انجام شد.', 'success');
            } else if (st.status === 'pending') {
              UI.toast('پرداخت در حال بررسی است.', 'info');
            } else {
              UI.toast('پرداخت ناموفق بود.', 'error');
            }
          })
          .catch(() => UI.toast('بررسی وضعیت پرداخت ناموفق بود.', 'error'));
      } else if (params.status === 'failed') {
        UI.toast('پرداخت ناموفق بود یا لغو شد.', 'error');
      }
    },
  };

  window.App = App;
  window.Screens = window.Screens || {};
  App.boot();
})();
