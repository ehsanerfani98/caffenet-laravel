/* Caffenet Lite — screens/service-page.js
 * Dedicated, deep-linkable service page: #/service/{slug}
 * Replaces the order-form popup for catalog / home-tile / search taps.
 * The hero (title, description, estimate) is public; the embedded order
 * form (Screens.mountOrderForm — the same one the sheet used) is mounted
 * in-place for customers. Guests get a login prompt, staff a notice. */
(function () {
  'use strict';

  window.Screens = window.Screens || {};

  function decodeSlug(v) {
    try { return decodeURIComponent(String(v || '')); } catch (e) { return String(v || ''); }
  }

  // slug → service, with a numeric-id fallback so links keep working even
  // for rows the slug seeder has not reached yet.
  function findByKey(key) {
    const list = api.state.services || [];
    return list.find((s) => (s.slug || '') === key) ||
           list.find((s) => String(s.id) === key) || null;
  }

  /* Same visual language as the catalog card, expanded for a full page. */
  function heroHtml(svc, settings) {
    const noPay = !!(settings && settings.paymentsDisabled);
    const price = noPay ? 'هماهنگی پس از ثبت درخواست'
      : (svc.basePrice > 0 ? PU.formatToman(svc.basePrice) : 'محاسبه در سفارش');
    const iconHtml = window.renderCategoryIcon(svc.icon || 'printer', { color: svc.iconColor || null });
    const iconStyle = svc.iconColor ? ' style="background:transparent;color:' + PU.escapeHtml(svc.iconColor) + ';"' : '';
    return (
      '<div class="svc-hero">' +
        '<div class="svc-hero-top">' +
          '<div class="svc-icon"' + iconStyle + '>' + iconHtml + '</div>' +
          '<div class="svc-hero-titles">' +
            '<div class="svc-title">' + PU.escapeHtml(svc.title) + '</div>' +
            (svc.estimatedTime ? '<div class="svc-est">' + icon('clock') + PU.escapeHtml(svc.estimatedTime) + '</div>' : '') +
          '</div>' +
        '</div>' +
        (svc.description ? '<div class="svc-desc">' + PU.escapeHtml(svc.description) + '</div>' : '') +
        '<div class="svc-hero-price">' + price + '</div>' +
      '</div>'
    );
  }

  /* Route shell — bind_service_detail fills #service-detail-root. A spinner
   * shows only on cold deep-links where state has not caught up yet. */
  Screens.serviceDetail = function (ctx) {
    return (
      '<div class="screen-pad">' +
        '<button type="button" class="btn ghost sm" id="service-back" style="margin-bottom:10px">' +
          icon('arrowright') + 'بازگشت</button>' +
        '<div id="service-detail-root"><div class="spinner" style="margin:30px auto"></div></div>' +
      '</div>'
    );
  };

  Screens.bind_service_detail = function ($host, ctx) {
    const key = decodeSlug(ctx.serviceSlug);
    const $root = $host.find('#service-detail-root');

    // Back = previous in-app screen; a direct deep-link falls back to catalog.
    $host.on('click', '#service-back', function () {
      if (history.length > 1) history.back();
      else if (window.App && App.navigate) App.navigate('catalog');
    });

    function renderInto(svc) {
      const user = api.state.currentUser;
      let formHtml;
      if (user && user.role === 'customer') {
        formHtml = '<div id="service-form-region"></div>';
      } else if (user) {
        // Staff accounts are never the customer of an order.
        formHtml = '<div class="info-box">ثبت سفارش تنها با حساب مشتری امکان‌پذیر است.</div>';
      } else {
        formHtml =
          '<div class="card" style="text-align:center;padding:22px">' +
            '<div style="font-weight:800;font-size:14px;margin-bottom:6px">برای ثبت سفارش وارد شوید</div>' +
            '<div class="help-text" style="margin-bottom:12px">با شماره همراه خود وارد شوید و سفارش را در همین صفحه کامل کنید.</div>' +
            '<button class="btn block" id="service-login">' + icon('lock') + 'ورود / ثبت‌نام</button>' +
          '</div>';
      }

      $root.html(heroHtml(svc, api.state.settings || {}) + formHtml);
      $root.find('#service-login').on('click', function () { Screens.openAuth(); });

      if (user && user.role === 'customer') {
        const $region = $root.find('#service-form-region');
        const mount = function () {
          // Fresh child elements every mount so the delegated handlers of
          // mountOrderForm never accumulate after a reset.
          $region.html('<div id="service-form-body"></div><div id="service-form-footer"></div>');
          Screens.mountOrderForm(svc, {
            body: $region.find('#service-form-body'),
            footer: $region.find('#service-form-footer'),
            // Fires after a real order was created (tracker / acknowledgment
            // opens on top) — reset the form in place instead of navigating:
            // applyRoute() would closeAllSheets() and kill that popup.
            close: mount,
          });
        };
        mount();
      }
    }

    const local = findByKey(key);
    if (local) { renderInto(local); return; }

    // Cold deep-link / stale state — resolve from the API by slug.
    api.getServiceBySlug(key).then(renderInto).catch(function () {
      $root.html(UI.emptyState('خدمت یافت نشد', 'شاید حذف شده باشد؛ از فهرست خدمات ادامه دهید', 'search'));
    });
  };
})();
