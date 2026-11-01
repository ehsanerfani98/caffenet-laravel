/* Caffenet Lite — screens/operator.js
 * Operator work queue: submitted + processing orders (the queue statuses —
 * never pending_payment/failed/refunded, and never ready/delivered/cancelled:
 * finished or terminal orders are out of the work queue) — including
 * request-mode orders that enter the queue at submission and are paid later
 * via a management-registered manual payment; filters, detail sheet with
 * status/result-file/assign/notes/chat.
 * Port of operator/OperatorDashboard.tsx. */
(function () {
  'use strict';

  // Mirrors Order::QUEUE_STATUSES on the backend — the canonical
  // «صف کار» definition used by the admin dashboard stat as well.
  const QUEUE_STATUSES = ['submitted', 'processing'];

  window.Screens = window.Screens || {};

  /* The «مشخصات سفارش» rendering (field/option label maps + Persian value
   * formatting) lives in ui.js as UI.orderSpecRows/UI.orderSpecCardHtml —
   * shared with the admin order sheet so both staff panels always show the
   * exact same order specs. */
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
          if (!QUEUE_STATUSES.includes(o.status)) return false;
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
      // صف کار = submitted + processing; ready is its own bucket (work done,
      // awaiting handover) so it is counted from all, not from the queue.
      const queue = all.filter((o) => QUEUE_STATUSES.includes(o.status));
      const processing = queue.filter((o) => o.status === 'processing').length;
      const ready = all.filter((o) => o.status === 'ready').length;
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
          '<div class="oc-head"><span class="oc-num">' + PU.toPersianDigits(o.orderNumber) + '</span>' + UI.statusBadge(o.status) +
            // «پیام جدید» — red unread-chat pill (filled live by ChatNotify).
            (window.ChatNotify ? ChatNotify.badgeSlotHtml(o.id) : '') + '</div>' +
          '<div class="oc-title">' + PU.escapeHtml(o.serviceTitle) + ' — ' + PU.escapeHtml(o.customerName) + '</div>' +
          (o.status === 'submitted' && o.noResponseDeadline
            ? '<div class="oc-nr">' + UI.nrCountdownHtml(o.noResponseDeadline) + '</div>'
            : '') +
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
              'مشتری: <b>' + PU.escapeHtml(o.customerName) + '</b> — ' +
              // Contact save: the vCard link opens the phone's Contacts app
              // prefilled (name + number) so the operator can save it under
              // any name; the phone glyph is the direct-dial shortcut.
              '<a class="cust-phone" href="/api/orders/' + o.id + '/customer-vcard" download ' +
                'title="ذخیره در مخاطبین گوشی — نام دلخواه">' + PU.toPersianDigits(o.customerPhone) + '</a>' +
              '<a class="cust-call" href="tel:' + PU.escapeHtml(o.customerPhone) + '" title="تماس با مشتری">' + icon('phone') + '</a><br>' +
              // The service's own computed price — visible even when nothing
              // has been paid yet (request-mode orders sit at paid 0).
              'قیمت خدمت: <b>' + PU.formatToman(o.totalPrice) + '</b><br>' +
              'مبلغ پرداختی: <b>' + PU.formatToman(o.paidAmount) + '</b><br>' +
              'تخفیف: ' + PU.formatToman(o.discountAmount) + '<br>' +
              'روش دریافت: ' + ({ download: 'دانلود آنلاین', in_person: 'حضوری', courier: 'پیک' }[o.deliveryMethod] || o.deliveryMethod) +
              (o.deliveryAddress ? '<br>آدرس: ' + PU.escapeHtml(o.deliveryAddress) : '') +
              (o.contactMethods && o.contactMethods.length ? '<br>راه‌های ارتباطی: <b>' + PU.escapeHtml(PU.contactMethodsText(o.contactMethods)) + '</b>' : '') +
            '</div>' +
            '<div class="help-text">با زدن روی شماره، کارت مخاطب در گوشی باز می‌شود و می‌توانید شماره را با نام دلخواه ذخیره کنید. آیکون تلفن تماس مستقیم است.</div>' +
          '</div>' +

          UI.orderSpecCardHtml(o) +

          '<div class="section-title">' + icon('files') + 'فایل‌های پیوست مشتری</div>' +
          '<div id="customer-files"></div>' +

          '<div class="section-title">' + icon('edit') + 'تغییر وضعیت</div>' +
          '<div class="chip-row" id="status-chips">' +
            STATUSES.map(([k, l]) => '<span class="chip ' + (o.status === k ? 'active' : '') + '" data-newstatus="' + k + '">' + l + '</span>').join('') +
          '</div>' +

          // No-response strike clock (same as the admin sheet): the operator
          // arms a 15-minute window after a missed call; each expiry pushes
          // the order to the back of the queue and the 3rd one cancels it.
          // «در حال انجام» (start) clears the clock. Operators hold the
          // orders.manage permission, so this mirrors the admin action 1:1.
          (o.status === 'submitted'
            ? '<div class="section-title">' + icon('clock') + 'مشتری پاسخ نداد؟</div>' +
              (o.noResponseDeadline
                ? '<div style="margin:6px 0 10px">' + UI.nrCountdownHtml(o.noResponseDeadline).replace('nr-timer"', 'nr-timer nr-big"') + '</div>' +
                  '<button class="btn amber block sm" id="od-no-response">' + icon('clock') + 'تماس دوباره بی‌پاسخ — ۱۵ دقیقه دیگر</button>' +
                  '<div class="help-text">تایمر در جریان است؛ اگر مشتری پاسخ داد «تغییر وضعیت ← در حال انجام» را بزنید تا تایمر خاموش شود. اگر مشتری دوباره جواب نداد، همین دکمه ۱۵ دقیقه را از نو شروع می‌کند. بعد از هر انقضا سفارش ته صف می‌رود (پیامک می‌رود) و بعد از ۳ بار لغو کامل می‌شود.</div>'
                : '<button class="btn amber block" id="od-no-response">' + icon('clock') + 'پاسخ نداد — ۱۵ دقیقه فرصت</button>' +
                  '<div class="help-text">وقتی تماس می‌گیرید و مشتری جواب نمی‌دهد این دکمه را بزنید: تایمر ۱۵ دقیقه‌ای فعال می‌شود. اگر تا پایان آن پاسخ ندهد، سفارش خودکار ته صف می‌رود (پیامک اطلاع می‌رود) و بعد از ۳ بار کامل لغو می‌شود. «در حال انجام» تایمر را خاموش می‌کند.</div>')
            : '') +

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

        // «پاسخ نداد» — arm (or re-arm) the 15-min strike clock. The response
        // order replaces the list entry (api._replaceOrder), so the live
        // countdown badge lights up on the card right after this sheet closes.
        sheet.body.find('#od-no-response').on('click', async function () {
          UI.busy(true);
          try {
            await api.markNoResponse(o.id);
            UI.toast('تایمر ۱۵ دقیقه‌ای فعال شد؛ اگر مشتری پاسخ ندهد ته صف می‌رود.', 'success');
            sheet.close();
          } catch (e) { UI.toast(e.message, 'error'); }
          finally { UI.busy(false); }
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
