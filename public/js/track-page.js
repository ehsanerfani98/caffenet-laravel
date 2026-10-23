/* Caffenet Lite — track-page.js
 * Standalone public order tracking page (served by /track/{token}).
 * No dependencies, no login: the long random track_token IS the auth.
 * Polls GET /api/orders/track/{token} every 15s and renders the same live
 * queue picture the in-app tracker shows (position / wait / start / end),
 * plus the status timeline and the recent notifications.
 *
 * Task 38 — the share button in the app now points here instead of the raw
 * JSON endpoint, so customers finally get a real UI on a second device. */
(function () {
  'use strict';

  var TOKEN = '';
  try {
    TOKEN = decodeURIComponent((location.pathname.split('/').pop() || '').trim());
  } catch (e) {
    TOKEN = (location.pathname.split('/').pop() || '').trim();
  }

  /* Mirror of App\Models\Order::STATUS_TITLES — the JSON endpoint ships raw
   * status keys, the page maps them to Persian titles + brand colors. */
  var STATUS_TITLES = {
    pending_payment: 'در انتظار پرداخت',
    submitted: 'ثبت‌شده',
    processing: 'در حال انجام',
    ready: 'آماده تحویل / دانلود',
    delivered: 'تحویل داده شده',
    failed: 'ناموفق',
    cancelled: 'لغو شده',
    refunded: 'عودت شده',
  };
  var STATUS_COLORS = {
    pending_payment: { fg: '#92400e', bg: '#fef3c7' },
    submitted: { fg: '#1147b2', bg: '#e3eafc' },
    processing: { fg: '#b45309', bg: '#ffedd5' },
    ready: { fg: '#065f46', bg: '#d1fae5' },
    delivered: { fg: '#065f46', bg: '#d1fae5' },
    failed: { fg: '#9f1239', bg: '#ffe4e6' },
    cancelled: { fg: '#9f1239', bg: '#ffe4e6' },
    refunded: { fg: '#3730a3', bg: '#e0e7ff' },
  };
  var ACTIVE = ['submitted', 'processing', 'ready'];

  var content = document.getElementById('content');
  var lastHash = '';

  /* ---------- tiny formatters (PU equivalents, dependency-free) ---------- */

  function fa(n) {
    return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function formatDuration(mins) {
    mins = Number(mins);
    if (!isFinite(mins) || mins < 0) return '—';
    if (mins < 60) return fa(mins) + ' دقیقه';
    var h = Math.floor(mins / 60);
    var m = mins % 60;
    return fa(h) + ' ساعت' + (m ? ' و ' + fa(m) + ' دقیقه' : '');
  }

  function formatTime(iso) {
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '—';
    try {
      return d.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
    } catch (e) {
      return fa(('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2));
    }
  }

  function formatDateTime(iso) {
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '—';
    try {
      return d.toLocaleDateString('fa-IR', { year: 'numeric', month: '2-digit', day: '2-digit' }) +
        ' - ' + formatTime(iso);
    } catch (e) {
      return formatTime(iso);
    }
  }

  function statusBadge(status) {
    var c = STATUS_COLORS[status] || { fg: '#334155', bg: '#f1f5f9' };
    return '<span class="badge" style="color:' + c.fg + ';background:' + c.bg + '">' +
      esc(STATUS_TITLES[status] || status) + '</span>';
  }

  /* ---------- renderers ---------- */

  function orderCardHtml(o) {
    return (
      '<div class="card">' +
        '<div class="card-title"><b>' + esc(o.service_title) + '</b>' + statusBadge(o.status) + '</div>' +
        '<div class="meta">' +
          'شماره پیگیری: <b>' + fa(o.order_number) + '</b>' +
          (o.assigned_operator_name ? '<br>اپراتور: <b>' + esc(o.assigned_operator_name) + '</b>' : '') +
          (o.updated_at ? '<br>آخرین به‌روزرسانی: ' + formatDateTime(o.updated_at) : '') +
        '</div>' +
      '</div>'
    );
  }

  /* Live queue card — mirrors the in-app tracker (order-tracker.js):
   *   ⏳ انتظار تقریبی / 👥 نوبت شما در صف / ⏱ شروع تقریبی
   *   ⚙️ مدت انجام تقریبی / ⏱ تکمیل تقریبی
   * + the 15-minute «پاسخ نداد» warning when a deadline is armed. */
  function queueCardHtml(o) {
    var status = o.status;
    if (ACTIVE.indexOf(status) === -1) {
      var msg = {
        delivered: 'این سفارش تحویل داده شده است. از اعتماد شما سپاسگزاریم.',
        cancelled: 'این سفارش لغو شده است. برای ثبت سفارش جدید به اپلیکیشن مراجعه کنید.',
        failed: 'این سفارش ناموفق بود. برای پیگیری بیشتر با پشتیبانی در تماس باشید.',
        refunded: 'مبلغ این سفارش عودت داده شده است.',
        pending_payment: 'این سفارش هنوز پرداخت نشده است.',
      }[status];
      if (!msg) return '';
      return '<div class="card"><div class="empty"><div class="big">' +
        (status === 'delivered' ? '✅' : status === 'cancelled' ? '🚫' : 'ℹ️') +
        '</div>' + esc(msg) + '</div></div>';
    }

    var hero;
    if (status === 'submitted') {
      var pos = o.queue_position != null ? Number(o.queue_position) : null;
      var ahead = pos != null && pos > 1 ? pos - 1 : 0;
      hero = { icon: '👥', label: 'نفرهای جلوی شما', value: fa(ahead), color: '#1559de' };
    } else if (status === 'processing') {
      hero = { icon: '⚙️', label: 'وضعیت سفارش', value: 'در حال انجام', color: '#f08d37' };
    } else {
      hero = { icon: '✅', label: 'وضعیت سفارش', value: 'آماده تحویل', color: '#0ca67d' };
    }

    var rows = [];
    if (status === 'submitted') {
      if (o.estimated_wait_minutes != null) {
        rows.push({ label: '⏳ انتظار تقریبی', value: formatDuration(o.estimated_wait_minutes) });
      }
      if (o.queue_position != null && Number(o.queue_position) > 0) {
        rows.push({ label: '👥 نوبت شما در صف', value: fa(o.queue_position) });
      }
      if (o.estimated_start_at) {
        rows.push({ label: '⏱ شروع تقریبی', value: formatTime(o.estimated_start_at) });
      }
    }
    if (o.estimated_duration_minutes != null) {
      rows.push({ label: '⚙️ مدت انجام تقریبی', value: formatDuration(o.estimated_duration_minutes) });
    }
    if (o.estimated_end_at) {
      rows.push({ label: '⏱ تکمیل تقریبی', value: formatTime(o.estimated_end_at) });
    }

    var warnHtml = '';
    if (o.no_response_deadline && status === 'submitted') {
      var minsLeft = Math.max(0, Math.round((new Date(o.no_response_deadline) - Date.now()) / 60000));
      if (minsLeft > 0 && minsLeft <= 15) {
        warnHtml = '<div class="queue-warn">⚠️ پاسخ شما تا ' + fa(minsLeft) +
          ' دقیقه دیگر نیاز است؛ در غیر این صورت سفارش به انتهای صف منتقل می‌شود.</div>';
      }
    }

    var rowsHtml = rows.length
      ? '<div class="eta-grid">' + rows.map(function (r) {
          return '<div class="eta-cell"><div class="eta-label">' + r.label +
            '</div><div class="eta-value">' + esc(r.value) + '</div></div>';
        }).join('') + '</div>'
      : '';

    return (
      '<div class="card queue-card">' +
        '<div class="queue-hero" style="background:' + hero.color + '1a;color:' + hero.color + '">' +
          '<div class="queue-hero-icon">' + hero.icon + '</div>' +
          '<div><div class="queue-hero-value">' + esc(hero.value) + '</div>' +
          '<div class="queue-hero-label">' + esc(hero.label) + '</div></div>' +
        '</div>' + rowsHtml + warnHtml +
      '</div>'
    );
  }

  function timelineHtml(history) {
    if (!history || !history.length) {
      return '<div class="card"><div class="empty"><div class="big">🕓</div>هنوز روندی ثبت نشده است.</div></div>';
    }
    var items = history.map(function (h) {
      var title = STATUS_TITLES[h.to_status] || h.to_status;
      return '<div class="tl-item"><span class="tl-dot"></span>' +
        '<div class="tl-action">' + esc(title) + '</div>' +
        '<div class="tl-meta">' + formatDateTime(h.changed_at) + '</div>' +
        (h.note ? '<div class="tl-note">' + esc(h.note) + '</div>' : '') +
        '</div>';
    }).join('');
    return '<div class="card"><div class="card-title"><b>روند سفارش</b></div>' + items + '</div>';
  }

  function noticesHtml(notifications) {
    if (!notifications || !notifications.length) return '';
    var items = notifications.map(function (n) {
      var p = n.payload || {};
      var text = p.body || p.title || p.message || '';
      if (!text) return '';
      return '<div class="notice"><div>' + esc(text) + '</div>' +
        '<div class="n-time">' + formatDateTime(n.created_at) + '</div></div>';
    }).filter(Boolean).join('');
    if (!items) return '';
    return '<div class="card"><div class="card-title"><b>اعلان‌های اخیر</b></div>' +
      '<div style="display:flex;flex-direction:column;gap:8px">' + items + '</div></div>';
  }

  function renderError(title, sub) {
    content.innerHTML = '<div class="card"><div class="empty"><div class="big">🔍</div>' +
      '<b style="display:block;font-size:13px;color:var(--ink);margin-bottom:4px">' + esc(title) + '</b>' +
      esc(sub || '') + '</div></div>';
  }

  function hashOf(data) {
    // Skip DOM churn when nothing visible changed between polls.
    try { return JSON.stringify([data.order && data.order.updated_at, data.order && data.order.status,
      data.order && data.order.queue_position, data.history && data.history.length,
      data.notifications && data.notifications.length]); } catch (e) { return String(Date.now()); }
  }

  function render(data) {
    var h = hashOf(data);
    if (h === lastHash) return;
    lastHash = h;
    var o = data.order || {};
    document.title = 'پیگیری سفارش ' + fa(o.order_number || '') + ' — کافی‌نت احسان';
    content.innerHTML =
      orderCardHtml(o) +
      queueCardHtml(o) +
      timelineHtml(data.history) +
      noticesHtml(data.notifications);
  }

  /* ---------- polling loop ---------- */

  var stopped = false;

  async function load() {
    if (stopped) return;
    if (!TOKEN) {
      renderError('لینک پیگیری نامعتبر است.', 'لطفاً لینک کامل را از اپلیکیشن دریافت کنید.');
      return;
    }
    try {
      var res = await fetch('/api/orders/track/' + encodeURIComponent(TOKEN), { cache: 'no-store' });
      var data = null;
      try { data = await res.json(); } catch (e) { /* non-JSON error body */ }
      if (!res.ok) {
        renderError(
          res.status === 404 ? 'سفارشی با این لینک یافت نشد.' : 'خطا در دریافت اطلاعات سفارش.',
          'اگر این مشکل ادامه داشت، لینک جدید را از اپلیکیشن دریافت کنید.'
        );
        return;
      }
      render(data);
    } catch (e) {
      renderError('اتصال برقرار نشد.', 'تلاش مجدد در ۱۵ ثانیه بعد…');
    }
  }

  load();
  setInterval(load, 15000);
})();
