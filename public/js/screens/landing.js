/* Caffenet Lite — screens/landing.js
 * Home + catalog + profile screens (guest & customer), ports of
 * HomeScreen / LandingScreen / ProfileScreen / ServiceCatalog. */
(function () {
  'use strict';

  window.Screens = window.Screens || {};

  function serviceCard(svc, settings) {
    const price = svc.basePrice > 0 ? PU.formatToman(svc.basePrice) : 'محاسبه در سفارش';
    // Apply the admin-picked icon color when set; otherwise the .svc-icon
    // background (sky-soft) + color (sky-deep) defaults apply.
    const iconHtml = window.renderCategoryIcon(svc.icon || 'printer', { color: svc.iconColor || null });
    const iconStyle = svc.iconColor
      ? ' style="background:transparent;color:' + PU.escapeHtml(svc.iconColor) + ';"'
      : '';
    return (
      '<div class="svc-card" data-service="' + svc.id + '">' +
        '<div class="svc-icon"' + iconStyle + '>' + iconHtml + '</div>' +
        '<div class="svc-title">' + PU.escapeHtml(svc.title) + '</div>' +
        '<div class="svc-desc">' + PU.escapeHtml(svc.description || '') + '</div>' +
        '<div class="svc-meta"><span>' + (svc.estimatedTime ? PU.escapeHtml(svc.estimatedTime) : '') + '</span>' +
        '<span class="svc-price">' + price + '</span></div>' +
      '</div>'
    );
  }

  // Home service tile (icon + title) — deliberately mirrors the category icon
  // grid that sits directly below the search field: a 4-column grid of rounded
  // tiles, each showing a large colorful icon and the service title beneath it.
  // The admin-picked iconColor is applied as a CSS color so the FA glyph
  // inherits it; when no color is set, the default sky-deep applies.
  function serviceTile(svc) {
    const color = svc.iconColor || null;
    const svg = window.renderCategoryIcon(svc.icon || 'printer', { color: color, size: '2x' });
    return (
      '<button type="button" class="cat-icon-tile" data-service="' + svc.id + '" aria-label="' + PU.escapeHtml(svc.title) + '">' +
        '<span class="cat-icon-box">' + svg + '</span>' +
        '<span class="cat-icon-label">' + PU.escapeHtml(svc.title) + '</span>' +
      '</button>'
    );
  }

  // The single live "current order" block, shared by the initial render and the
  // in-place refresh so both stay identical without rebuilding the whole screen.
  function activeOrderBlock(state) {
    const active = (state.orders || []).find((o) =>
      ['pending_payment', 'submitted', 'processing', 'ready'].includes(o.status));
    if (!active) return '';

    // ── Queue summary badge ──
    // Shown only for paid orders that are waiting or being processed.
    // Mirrors the same data as the order-tracker queue status card but
    // compressed into a one-line badge so the home page stays compact.
    let queueBadge = '';
    if (active.status === 'submitted' && active.queuePosition != null) {
      const ahead = Math.max(0, active.queuePosition - 1);
      const cls = ahead === 0 ? 'urgent' : '';
      const text = ahead === 0
        ? 'نوبت شما رسیده — در انتظار شروع'
        : (PU.toPersianDigits(ahead) + ' نفر جلوی شما');
      queueBadge = '<span class="home-order-queue-badge ' + cls + '">' + icon('clock') + text + '</span>';
    } else if (active.status === 'submitted' && active.estimatedWaitMinutes != null) {
      queueBadge = '<span class="home-order-queue-badge">' + icon('clock') +
        'شروع تقریبی: ' + PU.formatDuration(active.estimatedWaitMinutes) + ' دیگر</span>';
    } else if (active.status === 'processing') {
      queueBadge = '<span class="home-order-queue-badge processing">' + icon('cog') + 'در حال انجام</span>';
    } else if (active.status === 'ready') {
      queueBadge = '<span class="home-order-queue-badge" style="background:#dcfce7;color:#15803d">' + icon('check') + 'آماده تحویل</span>';
    }

    return (
      '<div class="home-order-block">' +
        '<div class="section-title">' + icon('folder') + 'سفارش جاری شما</div>' +
        '<div class="order-card" data-track="' + active.id + '">' +
          '<div class="oc-head"><span class="oc-num">' + PU.toPersianDigits(active.orderNumber) + '</span>' + UI.statusBadge(active.status) + '</div>' +
          '<div class="oc-title">' + PU.escapeHtml(active.serviceTitle) + '</div>' +
          queueBadge +
          '<div class="oc-foot"><span class="oc-price">' + PU.formatToman(Math.max(0, active.totalPrice - active.discountAmount)) + '</span>' +
          '<span style="color:var(--sky);font-weight:700">پیگیری ↗</span></div>' +
        '</div>' +
      '</div>'
    );
  }

  /* ---------------- Home ---------------- */

  // The colorful category icon grid directly below the search field.
  // Mirrors the mobile_services_home.html reference: 4-column grid of
  // large rounded tiles, each tile shows a Font Awesome icon (optionally
  // tinted with the admin-picked color) and the category title. Tapping a
  // tile opens the catalog filtered by that category.
  function categoryIconGridHtml(state) {
    const cats = (state.categories || []).slice();
    if (!cats.length) {
      return '<div class="cat-icon-empty">هنوز دسته‌بندی ثبت نشده است.</div>';
    }
    const tiles = cats.map(function (cat) {
      // Font Awesome icon — renderCategoryIcon handles new keys, raw FA
      // class names, and legacy PascalCase names alike.
      const iconHtml = window.renderCategoryIcon(cat.iconName || 'general', {
        color: cat.iconColor || null,
        size: '2x',
      });
      return (
        '<button type="button" class="cat-icon-tile" data-cat-tile="' + cat.id + '" aria-label="' + PU.escapeHtml(cat.title) + '">' +
          '<span class="cat-icon-box">' + iconHtml + '</span>' +
          '<span class="cat-icon-label">' + PU.escapeHtml(cat.title) + '</span>' +
        '</button>'
      );
    }).join('');
    return '<section class="cat-icon-grid" aria-label="دسته‌بندی خدمات">' + tiles + '</section>';
  }

  // Default home service listing (popular + per-category). Kept as a helper so
  // the search box can restore it verbatim when the query is cleared.
  function homeServicesHtml(state, settings) {
    let html = '';

    // ── Popular services ──
    // Services explicitly marked isFeatured by the admin, sorted by
    // featuredSort (smaller first), then by created_at as a tie-breaker.
    // When NO service is featured yet, fall back to the legacy behavior
    // (first 4 active services by created_at) so the section isn't empty.
    const featured = state.services
      .filter((s) => s.isActive && s.isFeatured)
      .sort((a, b) => {
        const sa = (a.featuredSort || 0) - (b.featuredSort || 0);
        if (sa !== 0) return sa;
        return String(a.createdAt || '').localeCompare(String(b.createdAt || ''));
      });
    const popular = featured.length > 0
      ? featured
      : state.services.filter((s) => s.isActive).slice(0, 4);

    if (popular.length > 0) {
      html += '<div class="section-title">' + icon('star') + 'خدمات پرطرفدار</div>';
      html += '<section class="cat-icon-grid">' + popular.map(serviceTile).join('') + '</section>';
    }

    html += '<div class="section-title">' + icon('grid') + 'همه خدمات</div>';
    state.categories.forEach((cat) => {
      const items = state.services.filter((s) => s.categoryId === cat.id && s.isActive);
      if (!items.length) return;
      html += '<div class="cat-head" style="margin:12px 2px 8px">' +
        window.renderCategoryIcon(cat.iconName || 'general', { color: cat.iconColor || null }) +
        ' ' + PU.escapeHtml(cat.title) + '</div>';
      html += '<section class="cat-icon-grid">' + items.map(serviceTile).join('') + '</section>';
    });
    return html;
  }

  Screens.home = function (ctx) {
    const state = api.state;
    const settings = state.settings || {};
    let html = '';

    html += '<div id="hero-slider"></div>';

    // Search field directly below the slider.
    html += '<div class="search-box">' + icon('search') +
      '<input class="input" id="home-search" placeholder="جستجوی خدمات…" autocomplete="off"></div>';

    // Colorful category icon grid — directly below the search field, before
    // the live order block and the services list. Mirrors the reference design
    // (mobile_services_home.html). Tapping a tile opens the catalog screen
    // pre-filtered to that category.
    html += '<div id="home-category-grid">' + categoryIconGridHtml(state) + '</div>';

    // Customer-only: staff accounts (operator/admin) receive EVERY order from
    // GET /api/orders (see OrderController::index) and can never own one, so
    // showing this block to them would label an arbitrary customer's order as
    // their own "current order".
    if (ctx.user && ctx.user.role === 'customer') {
      html += '<div id="home-order-live">' + activeOrderBlock(state) + '</div>';
    }

    html += '<div id="home-services">' + homeServicesHtml(state, settings) + '</div>';

    return html;
  };

  Screens.bind_home = function ($host) {
    UI.imageSlider($host.find('#hero-slider'), api.state.slides.filter((s) => s.isActive));

    function renderHomeServices() {
      const q = ($host.find('#home-search').val() || '').trim().toLowerCase();
      const $box = $host.find('#home-services');
      if (!q) {
        $box.html(homeServicesHtml(api.state, api.state.settings || {}));
        return;
      }
      const items = api.state.services.filter((s) =>
        s.isActive && (s.title + ' ' + (s.description || '')).toLowerCase().includes(q));
      if (!items.length) {
        $box.html(UI.emptyState('خدمتی یافت نشد', 'عبارت دیگری را جستجو کنید', 'search'));
        return;
      }
      $box.html(
        '<div class="section-title">' + icon('search') + 'نتایج جستجو</div>' +
        '<section class="cat-icon-grid">' + items.map(serviceTile).join('') + '</section>'
      );
    }

    $host.on('input', '#home-search', PU.debounce(renderHomeServices, 200));

    // Category icon tile → open catalog and select that category.
    $host.on('click', '[data-cat-tile]', function () {
      const catId = $(this).data('cat-tile');
      const cat = api.state.categories.find((c) => c.id === catId);
      if (!cat) return;
      // Prefer opening the catalog screen with the chip pre-selected, if such
      // a helper exists; otherwise fall back to a simple toast.
      if (typeof Screens.openCatalog === 'function') {
        Screens.openCatalog(catId);
      } else if (typeof App !== 'undefined' && App.navigate) {
        App.navigate('catalog');
        // The catalog screen renders asynchronously; select the chip after.
        setTimeout(function () {
          const $chip = $('#catalog-chips .chip[data-cat="' + catId + '"]');
          if ($chip.length) { $chip.trigger('click'); }
        }, 80);
      } else {
        UI.toast('دسته‌بندی: ' + cat.title, 'info');
      }
    });

    // Delegated so the handlers survive the #home-order-live / #home-services
    // innerHTML refreshes.
    $host.on('click', '[data-service]', function () {
      const svc = api.state.services.find((s) => s.id === $(this).data('service'));
      if (svc) Screens.openOrderForm(svc);
    });
    $host.on('click', '[data-track]', function () {
      const order = api.state.orders.find((o) => o.id === $(this).data('track'));
      if (order) Screens.openOrderTracker(order.id);
    });
  };

  // Live refresh on background sync: update only the active-order region and
  // the category icon grid (in case the admin added/edited categories), so the
  // hero slider and its autoplay timer are left untouched (no flicker, no leak).
  Screens.refresh_home = function ($host) {
    const user = api.state.currentUser;
    // The category grid is always refreshed (cheap, and admin edits need to
    // propagate to staff/customer views alike).
    const $grid = $host.find('#home-category-grid');
    if ($grid.length) $grid.html(categoryIconGridHtml(api.state));
    if (!user || user.role !== 'customer') return;
    $host.find('#home-order-live').html(activeOrderBlock(api.state));
  };

  /* ---------------- Catalog (guest/customer) ---------------- */

  Screens.catalog = function (ctx) {
    let html =
      '<div class="search-box">' + icon('search') +
      '<input class="input" id="catalog-search" placeholder="جستجو در خدمات…"></div>' +
      '<div class="chip-row" id="catalog-chips"><span class="chip active" data-cat="all">همه</span>';
    api.state.categories.forEach((c) => {
      html += '<span class="chip" data-cat="' + c.id + '">' + PU.escapeHtml(c.title) + '</span>';
    });
    html += '</div><div id="catalog-list" style="margin-top:12px"></div>';

    return html;
  };

  Screens.bind_catalog = function ($host) {
    function renderList() {
      const q = ($('#catalog-search').val() || '').trim().toLowerCase();
      const cat = $host.find('#catalog-chips .chip.active').data('cat') || 'all';
      const items = api.state.services.filter((s) =>
        s.isActive &&
        (cat === 'all' || s.categoryId === cat) &&
        (!q || (s.title + ' ' + (s.description || '')).toLowerCase().includes(q))
      );
      const $list = $host.find('#catalog-list');
      if (!items.length) {
        $list.html(UI.emptyState('خدمتی یافت نشد', 'عبارت دیگری را جستجو کنید', 'search'));
        return;
      }
      $list.html('<div class="svc-grid">' + items.map((s) => serviceCard(s)).join('') + '</div>');
      $list.find('[data-service]').on('click', function () {
        const svc = api.state.services.find((s) => s.id === $(this).data('service'));
        if (svc) Screens.openOrderForm(svc);
      });
    }

    renderList();
    $host.find('#catalog-search').on('input', PU.debounce(renderList, 200));
    $host.find('#catalog-chips .chip').on('click', function () {
      $host.find('#catalog-chips .chip').removeClass('active');
      $(this).addClass('active');
      renderList();
    });
    // Live refresh on background sync: repaint the list in place.
    $host.data('caffenetRefresh', renderList);
  };

  /* ---------------- Orders list (customer) ---------------- */

  Screens.orders = function (ctx) {
    if (!ctx.user) {
      return UI.emptyState('برای مشاهده سفارش‌ها وارد شوید', '', 'lock');
    }
    const orders = api.state.orders;
    if (!orders.length) {
      return UI.emptyState('هنوز سفارشی ثبت نکرده‌اید', 'از صفحه خدمات شروع کنید', 'folder');
    }

    const FILTERS = [
      { key: 'all', label: 'همه' },
      { key: 'open', label: 'در جریان' },
      { key: 'ready', label: 'آماده تحویل' },
      { key: 'delivered', label: 'تحویل شده' },
      { key: 'cancelled', label: 'لغو شده' },
      { key: 'refunded', label: 'عودت شده' },
    ];

    let html =
      '<div class="chip-row" id="orders-chips">' +
      FILTERS.map((f, i) => '<span class="chip ' + (i === 0 ? 'active' : '') + '" data-filter="' + f.key + '">' + f.label + '</span>').join('') +
      '</div><div id="orders-list" style="margin-top:12px"></div>';
    return html;
  };

  Screens.bind_orders = function ($host) {
    function renderList() {
      const filter = $host.find('#orders-chips .chip.active').data('filter') || 'all';
      const OPEN = ['pending_payment', 'submitted', 'processing'];
      const items = api.state.orders.filter((o) => {
        if (filter === 'all') return true;
        if (filter === 'open') return OPEN.includes(o.status);
        return o.status === filter;
      });
      const $list = $host.find('#orders-list');
      if (!items.length) {
        $list.html(UI.emptyState('سفارشی در این دسته نیست', '', 'folder'));
        return;
      }
      $list.html(items.map((o) =>
        '<div class="order-card" data-track="' + o.id + '" style="margin-bottom:10px">' +
          '<div class="oc-head"><span class="oc-num">' + PU.toPersianDigits(o.orderNumber) + '</span>' + UI.statusBadge(o.status) + '</div>' +
          '<div class="oc-title">' + PU.escapeHtml(o.serviceTitle) + '</div>' +
          '<div class="oc-foot">' +
            '<span class="oc-price">' + PU.formatToman(Math.max(0, o.totalPrice - o.discountAmount)) + '</span>' +
            '<span style="font-size:11px;color:var(--ink-soft)">' + PU.toJalaliDateShort(o.createdAt) + '</span>' +
          '</div>' +
        '</div>'
      ).join(''));
      $list.find('[data-track]').on('click', function () {
        Screens.openOrderTracker($(this).data('track'));
      });
    }
    renderList();
    $host.find('#orders-chips .chip').on('click', function () {
      $host.find('#orders-chips .chip').removeClass('active');
      $(this).addClass('active');
      renderList();
    });
    // Live refresh on background sync: repaint the list in place.
    $host.data('caffenetRefresh', renderList);
  };

  /* ---------------- Profile ---------------- */

  Screens.profile = function (ctx) {
    const user = ctx.user;
    const settings = api.state.settings || {};

    if (!user) {
      return (
        '<div class="card" style="text-align:center;padding:26px">' +
          '<div style="width:64px;height:64px;border-radius:20px;background:var(--sky-soft);color:var(--sky-deep);display:flex;align-items:center;justify-content:center;margin:0 auto 12px">' +
          icon('user', 'i-lg') + '</div>' +
          '<div style="font-weight:800;font-size:15px">کاربر مهمان</div>' +
          '<div style="font-size:12px;color:var(--ink-soft);margin:6px 0 16px">برای ثبت سفارش و استفاده از کیف پول وارد شوید</div>' +
          '<button class="btn block" id="profile-login">' + icon('lock') + 'ورود / ثبت‌نام</button>' +
        '</div>' +
        '<div class="info-box" style="margin-top:14px">' +
          '<b>' + PU.escapeHtml(settings.siteTitle || 'کافی‌نت احسان') + '</b><br>' +
          (settings.coffeenetAddress ? 'آدرس: ' + PU.escapeHtml(settings.coffeenetAddress) + '<br>' : '') +
          (settings.coffeenetPhone ? 'تلفن: ' + PU.toPersianDigits(settings.coffeenetPhone) + '<br>' : '') +
          (settings.workingHours ? 'ساعت کاری: ' + PU.escapeHtml(settings.workingHours) : '') +
        '</div>'
      );
    }

    const roleLabel = PERM.ROLE_LABELS[user.role] || user.role;
    return (
      '<div class="card" style="text-align:center;padding:24px">' +
        '<div style="width:64px;height:64px;border-radius:20px;background:var(--sky-soft);color:var(--sky-deep);display:flex;align-items:center;justify-content:center;margin:0 auto 12px">' + icon('user', 'i-lg') + '</div>' +
        '<div style="font-weight:800;font-size:15px">' + PU.escapeHtml(user.name) + '</div>' +
        '<div style="font-size:12.5px;color:var(--ink-soft);margin:4px 0 8px">' + PU.toPersianDigits(user.phone) + '</div>' +
        '<span class="pill submitted">' + PU.escapeHtml(roleLabel) + '</span>' +
      '</div>' +
      (user.role === 'customer' ?
        '<div class="card" style="margin-top:10px;display:flex;align-items:center;gap:10px;cursor:pointer" id="profile-wallet">' +
          '<div class="lr-icon tone-emerald">' + icon('wallet') + '</div>' +
          '<div class="lr-main"><div class="lr-title">کیف پول</div><div class="lr-sub">موجودی: ' + PU.formatToman(user.walletBalance) + '</div></div>' +
          icon('arrowleft') +
        '</div>' : '') +
      (user.role === 'operator' ?
        '<div class="card" style="margin-top:10px;display:flex;align-items:center;gap:10px;cursor:pointer" id="profile-queue">' +
          '<div class="lr-icon tone-sky">' + icon('folder') + '</div>' +
          '<div class="lr-main"><div class="lr-title">صف کار</div><div class="lr-sub">سفارش‌های پرداخت‌شده</div></div>' +
        '</div>' : '') +
      '<div class="card" style="margin-top:10px">' +
        '<div class="list-row" id="profile-install"><div class="lr-icon tone-indigo">' + icon('download') + '</div>' +
          '<div class="lr-main"><div class="lr-title">نصب برنامه</div><div class="lr-sub">افزودن به صفحه اصلی دستگاه</div></div></div>' +
        '<div class="list-row" id="profile-privacy"><div class="lr-icon tone-slate">' + icon('shield') + '</div>' +
          '<div class="lr-main"><div class="lr-title">حریم خصوصی</div><div class="lr-sub">داده‌های شما فقط روی همین سامانه ذخیره می‌شود</div></div></div>' +
      '</div>' +
      '<button class="btn rose block" id="profile-logout" style="margin-top:14px">' + icon('logout') + 'خروج از حساب</button>'
    );
  };

  Screens.bind_profile = function ($host, ctx) {
    if (!ctx.user) {
      $host.find('#profile-login').on('click', () => Screens.openAuth());
      return;
    }
    $host.find('#profile-wallet').on('click', () => App.navigate('wallet'));
    $host.find('#profile-queue').on('click', () => App.navigate('operator'));
    $host.find('#profile-privacy').on('click', () => { if (Screens.openTerms) Screens.openTerms(); });
    $host.find('#profile-logout').on('click', async () => {
      await api.logout();
      UI.toast('از حساب خارج شدید.', 'info');
      // navigate() changes the hash, which fires hashchange -> applyRoute and
      // renders the home screen. Calling App.render() here as well would render
      // a stale screen a second time.
      App.navigate('home');
    });
    $host.find('#profile-install').on('click', async () => {
      const ok = await PWA.promptInstall();
      if (!ok && PWA.isIOS()) {
        UI.toast('در iOS: دکمه اشتراک‌گذاری، سپس «Add to Home Screen» را انتخاب کنید.', 'info');
      } else if (!ok) {
        UI.toast('نصب در حال حاضر در دسترس نیست.', 'info');
      }
    });
  };
})();
