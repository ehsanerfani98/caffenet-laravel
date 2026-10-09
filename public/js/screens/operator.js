/* Caffenet Lite — screens/operator.js
 * Operator work queue: paid orders only (never pending_payment/failed),
 * filters, detail sheet with status/result-file/assign/notes/chat.
 * Port of operator/OperatorDashboard.tsx. */
(function () {
  'use strict';

  const NON_QUEUE_STATUSES = ['pending_payment', 'failed', 'refunded'];

  window.Screens = window.Screens || {};

  /* Fallback Persian labels for the seeded catalog fields/options — used when
   * the order's service was edited or removed after the order was placed, so an
   * operator never sees a latin field id or raw option value. */
  const FIELD_LABELS = {
    pageCount: 'تعداد صفحات',
    copies: 'تعداد نسخه',
    printColor: 'نوع چاپ',
    paperSize: 'سایز کاغذ',
    binding: 'صحافی',
    university: 'نام دانشگاه',
    serviceLevel: 'سطح خدمات',
    subject: 'موضوع پیگیری',
    taxType: 'نوع خدمت',
  };

  const OPTION_LABELS = {
    bw: 'سیاه‌وسفید (۱۵۰۰ تومان/صفحه)',
    color: 'رنگی (۵۵۰۰ تومان/صفحه)',
    A4: 'A4',
    A3: 'A3 (دو برابر A4)',
    none: 'بدون صحافی',
    plastic_coil: 'فن پلاستیکی (۲۵٬۰۰۰ تومان)',
    metal_coil: 'فن فلزی (۴۰٬۰۰۰ تومان)',
    basic: 'فقط ثبت‌نام',
    full: 'ثبت‌نام + انتخاب واحد (۲۰٬۰۰۰ تومان اضافه)',
    declaration: 'اظهارنامه مالیاتی (۵۰٬۰۰۰ تومان اضافه)',
    loan: 'مشاوره وام',
  };

  /* Field definitions of the service an order belongs to (may be empty when
   * the service has since been removed). */
  function serviceFieldsFor(order) {
    const service = (api.state.services || []).find((s) => s.id === order.serviceId);
    return (service && service.fields) || [];
  }

  /* Any stored value → Persian display string: booleans become بله/خیر,
   * numbers/ids get Persian digits, arrays are comma-joined, empty → «—». */
  function toPersianValue(v) {
    if (v === true || v === 'true') return 'بله';
    if (v === false || v === 'false') return 'خیر';
    if (v === null || v === undefined || v === '') return '—';
    if (Array.isArray(v)) return v.map(toPersianValue).join('، ');
    return PU.toPersianDigits(String(v));
  }

  /* Maps raw formData (latin field ids + option values) to Persian label/value
   * rows for the order detail sheet, using the order's service field defs. */
  function orderFormDataRows(order) {
    const fields = serviceFieldsFor(order);
    return Object.entries(order.formData || {}).map(function (entry) {
      const key = entry[0];
      const raw = entry[1];
      const field = fields.find((f) => f.id === key);
      const label = (field && field.label) || FIELD_LABELS[key] || key;
      let value;

      if (field && field.type === 'checkbox') {
        value = toPersianValue(raw === true || raw === 'true' || raw === '1' || raw === 1 || raw === 'on');
      } else if (field && Array.isArray(field.options)) {
        const opt = field.options.find((o) => String(o.value) === String(raw));
        value = opt ? PU.toPersianDigits(String(opt.label)) : toPersianValue(raw);
      } else if (OPTION_LABELS[raw] !== undefined) {
        value = PU.toPersianDigits(String(OPTION_LABELS[raw]));
      } else {
        value = toPersianValue(raw);
      }

      return { label: String(label), value: String(value) };
    });
  }

  Screens.operator = function (ctx) {
    if (!ctx.user || !['operator', 'admin'].includes(ctx.user.role)) {
      return UI.emptyState('این بخش برای اپراتورها است', 'وارد حساب اپراتوری شوید', 'lock');
    }

    let html =
      '<div class="search-box">' + icon('search') +
      '<input class="input" id="op-search" placeholder="جستجو: شماره سفارش، نام یا خدمت…"></div>' +
      '<div class="chip-row" id="op-chips">' +
        '<span class="chip active" data-st="queue">صف کار</span>' +
        '<span class="chip" data-st="processing">در حال انجام</span>' +
        '<span class="chip" data-st="ready">آماده تحویل</span>' +
        '<span class="chip" data-st="delivered">تحویل شده</span>' +
        '<span class="chip" data-st="all">همه</span>' +
      '</div>' +
      '<div id="op-stats" style="margin:12px 0"></div>' +
      '<div id="op-list"></div>';
    return html;
  };

  Screens.bind_operator = function ($host, ctx) {
    function filteredOrders() {
      const st = $host.find('#op-chips .chip.active').data('st') || 'queue';
      const q = ($('#op-search').val() || '').trim().toLowerCase();
      return api.state.orders.filter((o) => {
        if (st === 'queue') {
          if (NON_QUEUE_STATUSES.includes(o.status)) return false;
        } else if (st !== 'all') {
          if (o.status !== st) return false;
        }
        if (q) {
          const hay = (o.orderNumber + ' ' + o.customerName + ' ' + o.customerPhone + ' ' + o.serviceTitle).toLowerCase();
          if (!hay.includes(q)) return false;
        }
        return true;
      });
    }

    function renderStats() {
      const all = api.state.orders;
      const queue = all.filter((o) => !NON_QUEUE_STATUSES.includes(o.status));
      const processing = queue.filter((o) => o.status === 'processing').length;
      const ready = queue.filter((o) => o.status === 'ready').length;
      $host.find('#op-stats').html(
        '<div class="stat-grid">' +
          '<div class="stat-card"><div class="s-icon tone-sky">' + icon('folder') + '</div>' +
            '<div class="s-label">صف کار</div><div class="s-value">' + PU.toPersianDigits(queue.length) + '</div></div>' +
          '<div class="stat-card"><div class="s-icon tone-indigo">' + icon('clock') + '</div>' +
            '<div class="s-label">در حال انجام</div><div class="s-value">' + PU.toPersianDigits(processing) + '</div></div>' +
          '<div class="stat-card"><div class="s-icon tone-emerald">' + icon('checkcircle') + '</div>' +
            '<div class="s-label">آماده تحویل</div><div class="s-value">' + PU.toPersianDigits(ready) + '</div></div>' +
          '<div class="stat-card"><div class="s-icon tone-amber">' + icon('star') + '</div>' +
            '<div class="s-label">امتیاز مشتریان</div><div class="s-value">' + PU.toPersianDigits(avgRating(all)) + '</div></div>' +
        '</div>'
      );
    }

    function avgRating(orders) {
      const rated = orders.filter((o) => o.rating);
      if (!rated.length) return '—';
      return (rated.reduce((s, o) => s + o.rating, 0) / rated.length).toFixed(1);
    }

    function renderList() {
      const orders = filteredOrders();
      const $list = $host.find('#op-list');
      if (!orders.length) {
        $list.html(UI.emptyState('سفارشی در این دسته نیست', '', 'folder'));
        return;
      }
      $list.html(orders.map((o) =>
        '<div class="order-card" data-order="' + o.id + '" style="margin-bottom:10px">' +
          '<div class="oc-head"><span class="oc-num">' + PU.toPersianDigits(o.orderNumber) + '</span>' + UI.statusBadge(o.status) + '</div>' +
          '<div class="oc-title">' + PU.escapeHtml(o.serviceTitle) + ' — ' + PU.escapeHtml(o.customerName) + '</div>' +
          '<div class="oc-foot">' +
            '<span class="oc-price">' + PU.formatToman(o.paidAmount) + '</span>' +
            '<span style="font-size:11px;color:var(--ink-soft)">' + PU.toJalaliDateShort(o.createdAt) + '</span>' +
          '</div>' +
        '</div>'
      ).join(''));
      $list.find('[data-order]').on('click', function () {
        openDetail($(this).data('order'));
      });
    }

    function openDetail(orderId) {
      api.fetchOrderById(orderId).then((o) => {
        let currentOrder = o;
        const sheet = UI.sheet({
          title: 'جزئیات سفارش',
          subtitle: o.orderNumber + ' — ' + o.customerName,
          icon: 'folder',
          tone: 'indigo',
          size: 'tall',
        });

        const STATUSES = [
          ['submitted', 'ثبت‌شده'], ['processing', 'در حال انجام'], ['ready', 'آماده تحویل'],
          ['delivered', 'تحویل داده شده'], ['cancelled', 'لغو شده'],
        ];

        sheet.body.html(
          '<div class="card" style="margin-bottom:12px">' +
            '<div style="display:flex;justify-content:space-between;align-items:center"><b>' + PU.escapeHtml(o.serviceTitle) + '</b>' + UI.statusBadge(o.status) + '</div>' +
            '<div style="font-size:11.5px;color:var(--ink-soft);line-height:2;margin-top:8px">' +
              'مشتری: <b>' + PU.escapeHtml(o.customerName) + '</b> — ' + PU.toPersianDigits(o.customerPhone) + '<br>' +
              'مبلغ پرداختی: <b>' + PU.formatToman(o.paidAmount) + '</b><br>' +
              'تخفیف: ' + PU.formatToman(o.discountAmount) + '<br>' +
              'روش دریافت: ' + ({ download: 'دانلود آنلاین', in_person: 'حضوری', courier: 'پیک' }[o.deliveryMethod] || o.deliveryMethod) +
              (o.deliveryAddress ? '<br>آدرس: ' + PU.escapeHtml(o.deliveryAddress) : '') +
              (o.contactMethods && o.contactMethods.length ? '<br>راه‌های ارتباطی: <b>' + PU.escapeHtml(PU.contactMethodsText(o.contactMethods)) + '</b>' : '') +
            '</div>' +
          '</div>' +

          (Object.keys(o.formData || {}).length ?
            '<div class="section-title">' + icon('receipt') + 'مشخصات سفارش</div>' +
            '<div class="card">' + orderFormDataRows(o).map((row) =>
              '<div class="list-row" style="padding:7px 0"><div class="lr-main"><div class="lr-sub" style="margin:0">' + PU.escapeHtml(row.label) + '</div>' +
              '<div class="lr-title" style="font-size:12.5px">' + PU.escapeHtml(row.value) + '</div></div></div>').join('') + '</div>' : '') +

          '<div class="section-title">' + icon('files') + 'فایل‌های پیوست مشتری</div>' +
          '<div id="customer-files"></div>' +

          '<div class="section-title">' + icon('edit') + 'تغییر وضعیت</div>' +
          '<div class="chip-row" id="status-chips">' +
            STATUSES.map(([k, l]) => '<span class="chip ' + (o.status === k ? 'active' : '') + '" data-newstatus="' + k + '">' + l + '</span>').join('') +
          '</div>' +

          '<label class="field-label">یادداشت پرسنلی (داخلی)</label>' +
          '<textarea class="textarea" id="op-note" rows="2">' + PU.escapeHtml(o.internalNote || '') + '</textarea>' +
          '<button class="btn ghost block sm" id="save-note" style="margin-top:8px">ذخیره یادداشت</button>' +

          '<div class="section-title">' + icon('upload') + 'فایل خروجی سفارش</div>' +
          '<div id="result-files"></div>' +
          '<button class="btn block sm" id="upload-result" style="margin-bottom:18px">' + icon('upload') + 'افزودن فایل خروجی</button>' +
          '<input type="file" id="result-input" class="hidden">' +

          '<div class="section-title">' + icon('users') + 'گفتگو با مشتری</div>' +
          '<button class="btn ghost block sm" id="open-chat">' + icon('sms') + 'ورود به صفحه گفتگو با مشتری</button>'
        );

        sheet.body.find('[data-newstatus]').on('click', async function () {
          const status = $(this).data('newstatus');
          try {
            await api.updateOrderStatus(o.id, status);
            UI.toast('وضعیت به‌روزرسانی شد.', 'success');
            sheet.close();
          } catch (e) { UI.toast(e.message, 'error'); }
        });

        sheet.body.find('#save-note').on('click', async function () {
          try {
            await api.updateOrder(o.id, { internalNote: sheet.body.find('#op-note').val() });
            UI.toast('یادداشت ذخیره شد.', 'success');
          } catch (e) { UI.toast(e.message, 'error'); }
        });

        // Uploaded result files: view / replace / delete are all available to
        // the operator. The list re-renders in place from the latest order
        // payload the server returns, so the sheet never has to be reopened.
        let replaceTarget = null;

        function renderResultFiles() {
          const files = currentOrder.resultFiles || [];
          const $box = sheet.body.find('#result-files');
          if (!files.length) {
            $box.html('<div class="help-text">هنوز فایل خروجی بارگذاری نشده است.</div>');
            return;
          }
          $box.html(files.map(function (f) {
            return '<div class="file-chip">' +
              '<div class="fc-icon">' + icon('file') + '</div>' +
              '<div class="fc-main"><div class="fc-name">' + PU.escapeHtml(f.name) + '</div>' +
              '<div class="fc-size">' + PU.formatBytes(f.size) + '</div></div>' +
              '<div class="fc-actions">' +
                '<a class="fc-act" href="' + PU.escapeHtml(f.url) + '" target="_blank" rel="noopener" title="مشاهده فایل">' + icon('eye') + '</a>' +
                '<button class="fc-act" data-replace-file="' + PU.escapeHtml(f.url) + '" title="جایگزینی فایل">' + icon('refresh') + '</button>' +
                '<button class="fc-act danger" data-delete-file="' + PU.escapeHtml(f.url) + '" title="حذف فایل">' + icon('trash') + '</button>' +
              '</div>' +
            '</div>';
          }).join(''));
        }

        // Customer attachments: view or delete. Deletion removes the metadata
        // and the disk copy server-side and re-renders from the latest order.
        function renderCustomerFiles() {
          const files = currentOrder.customerFiles || [];
          const $box = sheet.body.find('#customer-files');
          if (!files.length) {
            $box.html('<div class="help-text">مشتری فایلی پیوست نکرده است.</div>');
            return;
          }
          $box.html(files.map(function (f) {
            return '<div class="file-chip">' +
              '<div class="fc-icon">' + icon('file') + '</div>' +
              '<div class="fc-main"><div class="fc-name">' + PU.escapeHtml(f.name) + '</div>' +
              '<div class="fc-size">' + PU.formatBytes(f.size) + '</div></div>' +
              '<div class="fc-actions">' +
                '<a class="fc-act" href="' + PU.escapeHtml(f.url) + '" target="_blank" rel="noopener" title="مشاهده فایل">' + icon('eye') + '</a>' +
                '<button class="fc-act danger" data-delete-cfile="' + PU.escapeHtml(f.url) + '" title="حذف فایل">' + icon('trash') + '</button>' +
              '</div>' +
            '</div>';
          }).join(''));
        }

        function applyOrder(updated) {
          if (updated && updated.id) currentOrder = updated;
          renderCustomerFiles();
          renderResultFiles();
        }

        sheet.body.on('click', '[data-delete-cfile]', async function () {
          const url = $(this).data('delete-cfile');
          const file = (currentOrder.customerFiles || []).find((f) => f.url === url);
          if (!window.confirm('فایل «' + (file ? file.name : '') + '» حذف شود؟')) return;
          UI.busy(true);
          try {
            const updated = await api.deleteOrderCustomerFile(currentOrder.id, url);
            applyOrder(updated);
            UI.toast('فایل پیوست مشتری حذف شد.', 'success');
          } catch (e) {
            UI.toast(e.message, 'error');
          } finally { UI.busy(false); }
        });

        // "افزودن فایل" and "جایگزینی" share one hidden input; replaceTarget
        // tells the change handler whether to drop an existing file.
        sheet.body.find('#upload-result').on('click', function () {
          replaceTarget = null;
          sheet.body.find('#result-input').trigger('click');
        });

        sheet.body.on('click', '[data-replace-file]', function () {
          replaceTarget = $(this).data('replace-file');
          sheet.body.find('#result-input').trigger('click');
        });

        sheet.body.on('click', '[data-delete-file]', async function () {
          const url = $(this).data('delete-file');
          const file = (currentOrder.resultFiles || []).find((f) => f.url === url);
          if (!window.confirm('فایل «' + (file ? file.name : '') + '» حذف شود؟')) return;
          UI.busy(true);
          try {
            const updated = await api.deleteOrderResult(currentOrder.id, url);
            applyOrder(updated);
            UI.toast('فایل خروجی حذف شد.', 'success');
          } catch (e) {
            UI.toast(e.message, 'error');
          } finally { UI.busy(false); }
        });

        sheet.body.find('#result-input').on('change', async function () {
          const file = this.files[0];
          const target = replaceTarget;
          replaceTarget = null;
          if (!file) return;
          UI.busy(true);
          try {
            const res = await api.uploadOrderResult(currentOrder.id, file, target || undefined);
            applyOrder(res && res.order);
            UI.toast(target ? 'فایل خروجی جایگزین شد.' : 'فایل خروجی بارگذاری شد.', 'success');
          } catch (e) {
            UI.toast(e.message, 'error');
          } finally { UI.busy(false); }
          $(this).val('');
        });

        renderCustomerFiles();
        renderResultFiles();

        sheet.body.find('#open-chat').on('click', function () {
          Screens.openOrderChat(o.id, {
            title: 'گفتگو با مشتری',
            subtitle: PU.toPersianDigits(o.orderNumber) + ' — ' + o.customerName,
          });
        });
      }).catch((e) => UI.toast(e.message, 'error'));
    }

    renderStats();
    renderList();
    $host.find('#op-search').on('input', PU.debounce(renderList, 200));
    $host.find('#op-chips .chip').on('click', function () {
      $host.find('#op-chips .chip').removeClass('active');
      $(this).addClass('active');
      renderList();
    });

    // Live refresh on background sync: repaint the queue in place (no rebuild).
    $host.data('caffenetRefresh', function () { renderStats(); renderList(); });
  };
})();
