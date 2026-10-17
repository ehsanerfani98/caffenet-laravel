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
  // Mirrors the mobile_services_home.html reference: a 4-column grid of
  // large rounded tiles, each tile shows a Font Awesome icon (optionally
  // tinted with the admin-picked color) and the category title. Tapping a
  // tile opens the catalog filtered by that category.
  //
  // Home-specific behavior:
  //  - a "دسته‌بندی خدمات" section title sits above the grid,
  //  - tiles keep the original full-width grid size (NOT the smaller
  //    carousel tile size — user asked to revert the compact variant),
  //  - only the first 3 rows (12 tiles) show initially; a centered
  //    "مشاهده بیشتر" text button reveals the rest when tapped.
  var CATS_ROW_LIMIT = 12; // 3 rows × 4 tiles

  function categoryIconGridHtml(state, expanded) {
    const cats = (state.categories || []).slice();
    if (!cats.length) {
      return '<div class="cat-icon-empty">هنوز دسته‌بندی ثبت نشده است.</div>';
    }
    let html = '<div class="section-title">' + icon('grid') + 'دسته‌بندی خدمات</div>';
    const visible = expanded ? cats : cats.slice(0, CATS_ROW_LIMIT);
    const tiles = visible.map(function (cat) {
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
    html += '<section class="cat-icon-grid" aria-label="دسته‌بندی خدمات">' + tiles + '</section>';
    if (!expanded && cats.length > CATS_ROW_LIMIT) {
      html += '<button type="button" class="cat-more-btn" id="cat-more">' + icon('chevrondown') + 'مشاهده بیشتر</button>';
    }
    return html;
  }

  // Featured/“popular” services for the home screen and the search sheet's
  // suggestion list. Services explicitly marked isFeatured by the admin,
  // sorted by featuredSort (smaller first), then by created_at as a
  // tie-breaker. When NO service is featured yet, falls back to the legacy
  // behavior (first 4 active services by created_at) so the section isn't
  // empty.
  function popularServices(state) {
    const featured = state.services
      .filter((s) => s.isActive && s.isFeatured)
      .sort((a, b) => {
        const sa = (a.featuredSort || 0) - (b.featuredSort || 0);
        if (sa !== 0) return sa;
        return String(a.createdAt || '').localeCompare(String(b.createdAt || ''));
      });
    return featured.length > 0
      ? featured
      : state.services.filter((s) => s.isActive).slice(0, 4);
  }

  // Default home service listing (popular + per-category).
  function homeServicesHtml(state, settings) {
    let html = '';

    // ── Popular services ──
    const popular = popularServices(state);

    if (popular.length > 0) {
      html += '<div class="section-title">' + icon('star') + 'خدمات پرطرفدار</div>';
      // Same swipeable carousel + tile size as the per-category rows below.
      html += '<section class="cat-icon-carousel">' + popular.map(serviceTile).join('') + '</section>';
    }

    state.categories.forEach((cat) => {
      const items = state.services.filter((s) => s.categoryId === cat.id && s.isActive);
      if (!items.length) return;
      html += '<div class="cat-head" style="margin:12px 2px 8px">' +
        window.renderCategoryIcon(cat.iconName || 'general', { color: cat.iconColor || null }) +
        ' ' + PU.escapeHtml(cat.title) + '</div>';
      // Horizontal swipeable carousel (native overflow-x + scroll-snap):
      // users drag left/right with their finger to reveal the rest of the
      // category's services. Falls back to a static row when the category
      // fits in one viewport. Search results + popular stay a 4-col grid.
      html += '<section class="cat-icon-carousel">' + items.map(serviceTile).join('') + '</section>';
    });
    return html;
  }

  Screens.home = function (ctx) {
    const state = api.state;
    const settings = state.settings || {};
    let html = '';

    // Modern full-width search trigger — sits ABOVE the hero banner. It is a
    // fake input (a button): tapping it opens the search bottom sheet where
    // the user types and sees the live results (see openSearchSheet below).
    const activeCount = state.services.filter((s) => s.isActive).length;
    html += '<button type="button" class="home-search" id="home-search-trigger" aria-label="جستجوی خدمات">' +
      '<span class="hs-icon">' + icon('search') + '</span>' +
      '<span class="hs-text">جستجوی خدمات…</span>' +
      (activeCount > 0 ? '<span class="hs-count">' + PU.toPersianDigits(activeCount) + ' خدمت</span>' : '') +
    '</button>';

    html += '<div id="hero-slider"></div>';

    // Colorful category icon grid — directly below the search field, before
    // the live order block and the services list. Mirrors the reference design
    // (mobile_services_home.html). Tapping a tile opens the catalog screen
    // pre-filtered to that category.
    html += '<div id="home-category-grid">' + categoryIconGridHtml(state) + '</div>';

    // Customer-only: staff accounts (operator/admin) receive EVERY order from
    // GET /api/orders (see OrderController::index) and can never own one, so
    // showing this block to them would label an arbitrary customer's order as
    // their own "current order".
    //
    // ── «سفارش جاری شما» — TEMPORARILY DISABLED (per request, 2026-10-18) ──
    // To re-enable, uncomment the block below. Screens.refresh_home already
    // guards against the missing #home-order-live element, so nothing else
    // needs to change.
    // if (ctx.user && ctx.user.role === 'customer') {
    //   html += '<div id="home-order-live">' + activeOrderBlock(state) + '</div>';
    // }

    html += '<div id="home-services">' + homeServicesHtml(state, settings) + '</div>';

    return html;
  };

  Screens.bind_home = function ($host) {
    UI.imageSlider($host.find('#hero-slider'), api.state.slides.filter((s) => s.isActive));
    // Desktop drag-to-scroll for the per-category carousels (touch devices
    // use native panning). Delegated, so re-renders are covered.
    UI.carouselDrag($host);

    /* ── Search bottom sheet ──
       The home search trigger opens a sheet: the user types inside the sheet
       and the live results render in that same sheet (never replacing the
       home content). Results tap → the search sheet closes first so the
       order-form sheet stacks cleanly on its own, then the order form opens.
       Suggestion list before typing = the same "popular" list as the home
       screen. */
    function openSearchSheet() {
      const sheet = UI.sheet({
        title: 'جستجوی خدمات',
        subtitle: 'نام خدمت مورد نظرتان را بنویسید',
        icon: 'search', tone: 'sky', size: 'tall',
      });

      const suggestions = popularServices(api.state).slice(0, 8);
      const suggestionHtml = () =>
        '<div class="search-sheet-title">' + icon('star') + 'پیشنهاد ما</div>' +
        '<div class="svc-grid">' + suggestions.map(serviceCard).join('') + '</div>';

      sheet.body.html(
        '<div class="search-sheet-field">' +
          '<div class="search-box">' + icon('search') +
            '<input class="input" id="sheet-search-input" placeholder="مثلاً پرینت، ترجمه، اسکن…" autocomplete="off">' +
          '</div>' +
        '</div>' +
        '<div id="sheet-search-results">' + suggestionHtml() + '</div>'
      );

      const $input = sheet.body.find('#sheet-search-input');
      const $results = sheet.body.find('#sheet-search-results');

      function renderResults() {
        const q = ($input.val() || '').trim().toLowerCase();
        if (!q) {
          $results.html(suggestionHtml());
          return;
        }
        const items = api.state.services.filter((s) =>
          s.isActive && (s.title + ' ' + (s.description || '')).toLowerCase().includes(q));
        if (!items.length) {
          $results.html(UI.emptyState('خدمتی یافت نشد', 'عبارت دیگری را جستجو کنید', 'search'));
          return;
        }
        $results.html(
          '<div class="search-sheet-meta">' + PU.toPersianDigits(items.length) +
            ' نتیجه برای «' + PU.escapeHtml(q) + '»</div>' +
          '<div class="svc-grid">' + items.map(serviceCard).join('') + '</div>'
        );
      }

      $input.on('input', PU.debounce(renderResults, 200));

      // The sheet lives in #sheet-host (a sibling of #screen-host), so the
      // home screen's delegated [data-service] handler can't see it — bind
      // the result taps directly on the results container.
      $results.on('click', '[data-service]', function () {
        const svc = api.state.services.find((s) => s.id === $(this).data('service'));
        if (!svc) return;
        sheet.close();
        Screens.openOrderForm(svc);
      });

      // Pop the keyboard immediately on platforms that allow programmatic
      // focus (Android/WebView). iOS ignores the delayed focus — there the
      // field is pre-focused so a single extra tap opens the keyboard.
      setTimeout(function () { $input.trigger('focus'); }, 120);
    }

    $host.on('click', '#home-search-trigger', openSearchSheet);

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

    // «مشاهده بیشتر» — reveal the categories hidden beyond the first 3 rows.
    // The expanded flag lives on $host so background-sync repaints
    // (Screens.refresh_home) keep showing all categories after the tap.
    $host.on('click', '#cat-more', function () {
      $host.data('catsExpanded', true);
      $host.find('#home-category-grid').html(categoryIconGridHtml(api.state, true));
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
    // propagate to staff/customer views alike). Keep the user's expanded
    // state if they already tapped "مشاهده بیشتر".
    const $grid = $host.find('#home-category-grid');
    if ($grid.length) $grid.html(categoryIconGridHtml(api.state, $host.data('catsExpanded') === true));
    if (!user || user.role !== 'customer') return;
    // The current-order block is temporarily disabled on the home screen;
    // refresh it only if its host element exists again (re-enabled build).
    const $live = $host.find('#home-order-live');
    if ($live.length) $live.html(activeOrderBlock(api.state));
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
        '<div class="list-row" id="profile-notify"><div class="lr-icon tone-sky">' + icon('bell') + '</div>' +
          '<div class="lr-main"><div class="lr-title">فعال‌سازی نوتیفیکیشن</div><div class="lr-sub" id="profile-notify-sub">دریافت اعلان وضعیت سفارش‌ها</div></div></div>' +
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

    // ── Notifications toggle ─────────────────────────────────────
    // Reflect the current browser permission state in the subtitle.
    function refreshNotifyState() {
      const $sub = $host.find('#profile-notify-sub');
      if (!('Notification' in window)) {
        $sub.text('مرورگر شما از نوتیفیکیشن پشتیبانی نمی‌کند');
        return;
      }
      const perm = Notification.permission;
      if (perm === 'granted') {
        $sub.text('✓ نوتیفیکیشن فعال است');
      } else if (perm === 'denied') {
        $sub.text('✗ نوتیفیکیشن مسدود شده — از تنظیمات مرورگر فعال کنید');
      } else {
        $sub.text('دریافت اعلان وضعیت سفارش‌ها');
      }
    }
    refreshNotifyState();

    $host.find('#profile-notify').on('click', async function () {
      if (!('Notification' in window)) {
        UI.toast('مرورگر شما از نوتیفیکیشن پشتیبانی نمی‌کند.', 'error');
        return;
      }
      if (Notification.permission === 'granted') {
        UI.toast('نوتیفیکیشن از قبل فعال است.', 'info');
        return;
      }
      if (Notification.permission === 'denied') {
        UI.toast('نوتیفیکیشن در تنظیمات مرورگر مسدود شده. لطفاً از آیکون قفل کنار آدرس فعالش کنید.', 'info');
        return;
      }
      // Request permission via FCM (which also registers the token).
      if (window.FCM && typeof window.FCM.requestPermission === 'function') {
        UI.toast('در حال درخواست اجازه…', 'info');
        const ok = await window.FCM.requestPermission();
        if (ok) {
          UI.toast('نوتیفیکیشن فعال شد. ✓', 'success');
        } else {
          UI.toast('اجازه نوتیفیکیشن داده نشد.', 'info');
        }
      } else {
        // FCM not loaded (probably disabled in admin) — fall back to a plain
        // Notification.requestPermission() so the user at least gets local
        // notifications from the polling layer.
        try {
          const p = await Notification.requestPermission();
          if (p === 'granted') {
            UI.toast('نوتیفیکیشن محلی فعال شد (پوش سرور غیرفعال است).', 'success');
          } else {
            UI.toast('اجازه نوتیفیکیشن داده نشد.', 'info');
          }
        } catch (e) {
          UI.toast('خطا در درخواست اجازه.', 'error');
        }
      }
      refreshNotifyState();
    });
  };
})();
