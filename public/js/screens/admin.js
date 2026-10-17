/* Caffenet Lite — screens/admin.js
 * Admin panel screens: dashboard / services+form-builder / users.
 * Port of admin/AdminDashboard.tsx (part 1 of 2). */
(function () {
  'use strict';

  window.Screens = window.Screens || {};
  const S = {};

  /* ================= Dashboard ================= */

  S.adminDashboard = function (ctx) {
    if (!ctx.user) return UI.emptyState('دسترسی محدود', '', 'lock');
    return '<div id="admin-stats"><div class="spinner" style="margin:30px auto"></div></div>';
  };

  function dashboardStatsHtml(stats) {
    const fmt = (n) => PU.formatToman(n);
    return (
      '<div class="stat-grid">' +
        '<div class="stat-card"><div class="s-icon tone-emerald">' + icon('wallet') + '</div>' +
          '<div class="s-label">درآمد امروز</div><div class="s-value">' + fmt(stats.revenueToday) + '</div></div>' +
        '<div class="stat-card"><div class="s-icon tone-sky">' + icon('chart') + '</div>' +
          '<div class="s-label">درآمد ۷ روز</div><div class="s-value">' + fmt(stats.revenue7) + '</div></div>' +
        '<div class="stat-card"><div class="s-icon tone-indigo">' + icon('chart') + '</div>' +
          '<div class="s-label">درآمد ۳۰ روز</div><div class="s-value">' + fmt(stats.revenue30) + '</div></div>' +
        '<div class="stat-card"><div class="s-icon tone-amber">' + icon('folder') + '</div>' +
          '<div class="s-label">صف کار</div><div class="s-value">' + PU.toPersianDigits(stats.queueCount) + '</div></div>' +
      '</div>' +
      '<div class="section-title">' + icon('users') + 'مشتریان: ' + PU.toPersianDigits(stats.customers) + '</div>' +

      '<div class="section-title">' + icon('zap') + 'پرفروش‌ترین خدمات</div>' +
      (stats.topServices.length ?
        '<div class="card">' + stats.topServices.map((s, i) =>
          '<div class="list-row"><div class="lr-icon tone-sky">' + icon('receipt') + '</div>' +
          '<div class="lr-main"><div class="lr-title">' + PU.escapeHtml(s.title) + '</div>' +
          '<div class="lr-sub">' + PU.toPersianDigits(s.count) + ' سفارش</div></div>' +
          '<span style="font-weight:700;font-size:12px;color:var(--sky-deep)">' + fmt(s.revenue) + '</span></div>').join('') + '</div>'
        : UI.emptyState('هنوز سفارش تحویل‌شده‌ای ثبت نشده', '', 'chart')) +

      '<div class="section-title">' + icon('chart') + 'روند ۱۴ روز اخیر</div>' +
      '<div class="card">' + stats.trend.map((d) =>
        '<div class="list-row" style="padding:6px 0">' +
          '<div class="lr-main"><div class="lr-title" style="font-size:12px">' + PU.toJalaliDateShort(d.date) + '</div>' +
          '<div style="height:6px;border-radius:3px;background:var(--slate-soft);margin-top:5px;overflow:hidden">' +
            '<div style="height:100%;background:var(--sky);width:' + Math.min(100, d.orders * 8) + '%"></div></div></div>' +
          '<div class="lr-action" style="font-size:11px;color:var(--ink-soft)">' + PU.toPersianDigits(d.orders) + ' سفارش</div>' +
        '</div>').join('') + '</div>'
    );
  }

  // Runs only when the dashboard route is entered (App renders on route change,
  // not on every data sync), so the stats are fetched once per visit.
  S.bind_admin_dashboard = async function ($host, ctx) {
    try {
      $host.find('#admin-stats').html(dashboardStatsHtml(await api.fetchDashboardStats()));
    } catch (e) {
      $host.find('#admin-stats').html(UI.emptyState('خطا در بارگذاری آمار', e.message, 'alert'));
    }
  };

  // No automatic `refresh_admin_dashboard` hook on purpose: the dashboard stats
  // are a relatively heavy aggregate and are loaded once when the screen is
  // entered (plus whenever the user navigates back). Re-fetching them on every
  // background sync was one of the redundant request sources.

  /* ================= Services + form builder ================= */

  S.adminServices = function (ctx) {
    return (
      '<div class="segmented" id="svc-seg">' +
        '<button class="seg on" data-view="services">خدمات</button>' +
        '<button class="seg" data-view="categories">دسته‌بندی‌ها</button>' +
      '</div>' +
      '<div id="svc-view" style="margin-top:12px"></div>'
    );
  };

  S.bind_admin_services = function ($host, ctx) {
    let view = 'services';

    function render() {
      const $v = $host.find('#svc-view');
      // Delete is gated by the same permission pair the backend route uses
      // (routes/api.php: permission:services.delete|services.manage).
      const canDelete = api.hasPermission('services.delete') || api.hasPermission('services.manage');
      if (view === 'services') {
        const cats = api.state.categories;
        let html = '<button class="btn block sm" id="new-service" style="margin-bottom:10px">' + icon('plus') + 'خدمت جدید</button>';
        cats.forEach((c) => {
          const items = api.state.services.filter((s) => s.categoryId === c.id);
          html += '<div class="cat-head" style="margin:10px 2px 7px">' +
            window.renderCategoryIcon(c.iconName || 'general', { color: c.iconColor || null }) +
            ' ' + PU.escapeHtml(c.title) + '</div>';
          html += items.map((s) =>
            '<div class="list-row"><div class="lr-icon" style="background:transparent;padding:0;width:44px;height:44px;flex:none;overflow:hidden;font-size:20px;' + (s.iconColor ? 'color:' + PU.escapeHtml(s.iconColor) + ';' : '') + '">' +
              window.renderCategoryIcon(s.icon || 'printer', { color: s.iconColor || null }) +
            '</div>' +
            '<div class="lr-main"><div class="lr-title">' + PU.escapeHtml(s.title) +
              (s.isFeatured ? ' <span style="display:inline-flex;align-items:center;gap:2px;color:#f59e0b;font-size:10px;font-weight:700;background:#fef3c7;padding:2px 7px;border-radius:8px;vertical-align:middle">' + icon('star') + 'پرطرفدار</span>' : '') +
            '</div>' +
            '<div class="lr-sub">' + PU.toPersianDigits(s.fields.length) + ' فیلد • ' + (s.isActive ? 'فعال' : 'غیرفعال') + (s.iconColor ? ' • <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:' + PU.escapeHtml(s.iconColor) + ';vertical-align:middle"></span>' : '') + '</div></div>' +
            (canDelete ? '<button class="icon-btn" style="background:var(--rose-soft);color:var(--rose)" data-del-svc="' + s.id + '" title="حذف">' + icon('trash') + '</button>' : '') +
            '<button class="icon-btn" style="background:var(--slate-soft);color:var(--ink)" data-edit-svc="' + s.id + '">' + icon('edit') + '</button></div>'
          ).join('');
        });
        $v.html(html);
        $v.find('#new-service').on('click', () => openServiceForm(null));
        $v.find('[data-edit-svc]').on('click', function () {
          const svc = api.state.services.find((s) => s.id === $(this).data('edit-svc'));
          openServiceForm(svc);
        });
        $v.find('[data-del-svc]').on('click', async function () {
          const svc = api.state.services.find((s) => s.id === $(this).data('del-svc'));
          if (!svc) return;
          if (!window.confirm('خدمت «' + svc.title + '» حذف شود؟ این عملیات قابل بازگشت نیست.')) return;
          UI.busy(true);
          try {
            await api.deleteService(svc.id);
            UI.toast('خدمت حذف شد.', 'success');
            render();
          } catch (e) { UI.toast(e.message, 'error'); }
          finally { UI.busy(false); }
        });
      } else {
        let html = '<button class="btn block sm" id="new-category" style="margin-bottom:10px">' + icon('plus') + 'دسته‌بندی جدید</button>';
        html += api.state.categories.map(function (c) {
          // Font Awesome icon (with optional tint), matching what the home
          // page grid will render.
          const iconHtml = window.renderCategoryIcon(c.iconName || 'general', { color: c.iconColor || null });
          const orderLabel = (typeof c.sortOrder === 'number' && c.sortOrder !== 0)
            ? ' · ترتیب: ' + PU.toPersianDigits(c.sortOrder)
            : '';
          return (
            '<div class="list-row"><div class="lr-icon" style="background:transparent;padding:0;width:44px;height:44px;flex:none;overflow:hidden;">' + iconHtml + '</div>' +
            '<div class="lr-main"><div class="lr-title">' + PU.escapeHtml(c.title) + '</div>' +
            '<div class="lr-sub">' + PU.escapeHtml(c.description || '') + orderLabel + '</div></div>' +
            (canDelete ? '<button class="icon-btn" style="background:var(--rose-soft);color:var(--rose)" data-del-cat="' + c.id + '" title="حذف">' + icon('trash') + '</button>' : '') +
            '<button class="icon-btn" style="background:var(--slate-soft);color:var(--ink)" data-edit-cat="' + c.id + '">' + icon('edit') + '</button></div>'
          );
        }).join('');
        $v.html(html);
        $v.find('#new-category').on('click', () => openCategoryForm(null));
        $v.find('[data-edit-cat]').on('click', function () {
          const cat = api.state.categories.find((c) => c.id === $(this).data('edit-cat'));
          openCategoryForm(cat);
        });
        $v.find('[data-del-cat]').on('click', async function () {
          const cat = api.state.categories.find((c) => c.id === $(this).data('del-cat'));
          if (!cat) return;
          // Same guard as the backend (CatalogController::deleteCategory):
          // a category with services must be emptied first.
          const linked = api.state.services.filter((s) => s.categoryId === cat.id).length;
          if (linked > 0) { UI.toast('ابتدا ' + PU.toPersianDigits(linked) + ' خدمت این دسته‌بندی را حذف یا منتقل کنید.', 'warning'); return; }
          if (!window.confirm('دسته‌بندی «' + cat.title + '» حذف شود؟ این عملیات قابل بازگشت نیست.')) return;
          UI.busy(true);
          try {
            await api.deleteCategory(cat.id);
            UI.toast('دسته‌بندی حذف شد.', 'success');
            render();
          } catch (e) { UI.toast(e.message, 'error'); }
          finally { UI.busy(false); }
        });
      }
    }

    function openCategoryForm(cat) {
      const sheet = UI.sheet({
        title: cat ? 'ویرایش دسته‌بندی' : 'دسته‌بندی جدید',
        icon: 'grid', tone: 'sky',
      });

      const LIB = window.CATEGORY_ICON_LIBRARY || [];
      let currentIcon = (cat && cat.iconName ? cat.iconName : 'printer');
      let currentColor = cat && cat.iconColor ? cat.iconColor : '';
      const currentOrder = cat && typeof cat.sortOrder === 'number' ? cat.sortOrder : 0;

      function lookupLabel(key) {
        const it = LIB.find((x) => x.key === key);
        return it ? it.label : key;
      }

      function previewBox() {
        // Tile mirrors .cat-icon-box sizing — exactly what the home page shows.
        return '<div style="width:72px;height:72px;display:grid;place-items:center;' +
          'background:' + (currentColor ? '#fff' : 'var(--sky-soft)') + ';' +
          'border:1px solid var(--line);border-radius:18px;' +
          'box-shadow:0 2px 10px rgba(24,34,48,.07);">' +
          window.renderCategoryIcon(currentIcon, { color: currentColor || null, size: '2x' }) +
          '</div>';
      }

      function iconFieldHtml() {
        return (
          '<div class="iconpick-field" id="cat-icon-trigger">' +
            '<div class="iconpick-preview" id="cat-icon-preview">' + previewBox() + '</div>' +
            '<div class="iconpick-meta">' +
              '<div class="iconpick-meta-title" id="cat-icon-label">' + PU.escapeHtml(lookupLabel(currentIcon)) + '</div>' +
              '<div class="iconpick-meta-sub">برای تغییر ضربه بزنید</div>' +
            '</div>' +
            '<span class="iconpick-cta">انتخاب</span>' +
          '</div>'
        );
      }

      sheet.body.html(
        '<label class="field-label">عنوان <span class="req">*</span></label>' +
        '<input class="input" id="cat-title" value="' + PU.escapeHtml(cat ? cat.title : '') + '">' +

        '<label class="field-label">آیکون</label>' +
        '<div id="cat-icon-host">' + iconFieldHtml() + '</div>' +

        '<label class="field-label" style="margin-top:12px">رنگ آیکون (اختیاری)</label>' +
        '<input class="input" id="cat-color" type="text" value="' + PU.escapeHtml(currentColor) + '" placeholder="#a82cc7 یا خالی برای رنگ پیش‌فرض">' +
        '<div id="cat-color-swatches" style="display:flex;flex-wrap:wrap;gap:5px;margin-top:6px">' +
          ['#1559de', '#10aec8', '#0ca67d', '#40a83b', '#f4d33b',
           '#f08d37', '#e75b2e', '#d91543', '#ef6c7b', '#a82cc7',
           '#8138d4', '#475d93', '#159db5', '#124be0', '#e40012'].map(function (c) {
            return '<button type="button" class="cat-color-swatch" data-color="' + c + '" ' +
              'style="width:22px;height:22px;border-radius:6px;background:' + c + ';' +
              'border:2px solid #fff;box-shadow:0 0 0 1px var(--line);cursor:pointer;padding:0"></button>';
          }).join('') +
        '</div>' +

        '<label class="field-label" style="margin-top:12px">ترتیب نمایش (عدد کوچک‌تر = زودتر)</label>' +
        '<input class="input" id="cat-sort" type="number" inputmode="numeric" value="' + currentOrder + '" style="margin-bottom:6px">' +
        '<div style="font-size:11px;color:var(--ink-soft);margin-bottom:10px">دسته‌بندی‌ها با عدد مساوی، بر اساس زمان ایجاد مرتب می‌شوند.</div>' +

        '<label class="field-label">توضیح</label>' +
        '<input class="input" id="cat-desc" value="' + PU.escapeHtml(cat ? cat.description : '') + '">'
      );

      // Open the IconPicker sheet on click.
      sheet.body.find('#cat-icon-trigger').on('click', function () {
        UI.iconPicker({
          current: currentIcon,
          title: 'انتخاب آیکن دسته‌بندی',
          onPick: function (key, fa, label) {
            currentIcon = key;
            // Refresh the field
            sheet.body.find('#cat-icon-host').html(iconFieldHtml());
          },
        });
      });

      // Live preview update on color change.
      function updateColorPreview() {
        currentColor = sheet.body.find('#cat-color').val().trim();
        sheet.body.find('#cat-icon-preview').html(previewBox());
      }
      sheet.body.find('#cat-color').on('input', PU.debounce(updateColorPreview, 200));
      sheet.body.find('.cat-color-swatch').on('click', function () {
        sheet.body.find('#cat-color').val($(this).data('color'));
        updateColorPreview();
      });

      // Same permission pair as the backend delete route; the button is only
      // offered for an existing category (matches the user-form pattern).
      const canDeleteCat = !!cat && (api.hasPermission('services.delete') || api.hasPermission('services.manage'));
      sheet.footer.html(
        (canDeleteCat ? '<button class="btn rose block" id="cat-delete" style="margin-bottom:8px">' + icon('trash') + 'حذف دسته‌بندی</button>' : '') +
        '<button class="btn block" id="cat-save">ذخیره</button>'
      );

      if (canDeleteCat) sheet.footer.find('#cat-delete').on('click', async function () {
        // Same guard as the backend (CatalogController::deleteCategory):
        // a category with services must be emptied first.
        const linked = api.state.services.filter((s) => s.categoryId === cat.id).length;
        if (linked > 0) { UI.toast('ابتدا ' + PU.toPersianDigits(linked) + ' خدمت این دسته‌بندی را حذف یا منتقل کنید.', 'warning'); return; }
        if (!window.confirm('دسته‌بندی «' + cat.title + '» حذف شود؟ این عملیات قابل بازگشت نیست.')) return;
        UI.busy(true);
        try {
          await api.deleteCategory(cat.id);
          UI.toast('دسته‌بندی حذف شد.', 'success');
          sheet.close();
          render();
        } catch (e) { UI.toast(e.message, 'error'); }
        finally { UI.busy(false); }
      });

      sheet.footer.find('#cat-save').on('click', async function () {
        try {
          await api.saveCategory({
            id: cat ? cat.id : null,
            title: sheet.body.find('#cat-title').val(),
            iconName: currentIcon,
            iconColor: sheet.body.find('#cat-color').val().trim(),
            sortOrder: parseInt(sheet.body.find('#cat-sort').val() || '0', 10),
            description: sheet.body.find('#cat-desc').val(),
          });
          UI.toast('دسته‌بندی ذخیره شد.', 'success');
          sheet.close();
          render();
        } catch (e) { UI.toast(e.message, 'error'); }
      });
    }

    function openServiceForm(svc) {
      const fields = svc ? JSON.parse(JSON.stringify(svc.fields)) : [];
      const sheet = UI.sheet({
        title: svc ? 'ویرایش خدمت' : 'خدمت جدید',
        subtitle: 'فرم‌ساز پویا',
        icon: 'cog', tone: 'emerald', size: 'tall',
      });

      // Service icon — defaults to a sensible Font Awesome key.
      let svcIconKey = (svc && svc.icon) || 'printer';
      // Service icon color — empty means "use default sky color".
      let svcIconColor = (svc && svc.iconColor) || '';
      let svcSortOrder = (svc && typeof svc.sortOrder === 'number') ? svc.sortOrder : 0;
      const LIB = window.CATEGORY_ICON_LIBRARY || [];
      function lookupLabel(key) {
        const it = LIB.find((x) => x.key === key);
        return it ? it.label : key;
      }

      // Standalone preview tile (mirrors the category form's previewBox):
      // 72×72 rounded tile, white bg when a color is set, sky-soft otherwise.
      // The .iconpick-preview host provides the outer frame; this tile fills
      // it so the icon + color render the SAME way as the category picker.
      function svcPreviewBox() {
        return '<div style="width:72px;height:72px;display:grid;place-items:center;' +
          'background:' + (svcIconColor ? '#fff' : 'var(--sky-soft)') + ';' +
          'border:1px solid var(--line);border-radius:18px;' +
          'box-shadow:0 2px 10px rgba(24,34,48,.07);">' +
          window.renderCategoryIcon(svcIconKey, { color: svcIconColor || null, size: '2x' }) +
          '</div>';
      }

      function svcIconFieldHtml() {
        // Mirrors the category form's iconFieldHtml() exactly — same classes,
        // same structure, same ids (just prefixed svc- instead of cat-).
        return (
          '<div class="iconpick-field" id="svc-icon-trigger">' +
            '<div class="iconpick-preview" id="svc-icon-preview">' + svcPreviewBox() + '</div>' +
            '<div class="iconpick-meta">' +
              '<div class="iconpick-meta-title" id="svc-icon-label">' + PU.escapeHtml(lookupLabel(svcIconKey)) + '</div>' +
              '<div class="iconpick-meta-sub">برای تغییر ضربه بزنید</div>' +
            '</div>' +
            '<span class="iconpick-cta">انتخاب</span>' +
          '</div>'
        );
      }

      function renderBody() {
        sheet.body.html(
          '<label class="field-label">عنوان خدمت <span class="req">*</span></label>' +
          '<input class="input" id="svc-title" value="' + PU.escapeHtml(svc ? svc.title : '') + '">' +
          '<label class="field-label">دسته‌بندی <span class="req">*</span></label>' +
          '<select class="select" id="svc-cat">' +
            api.state.categories.map((c) => '<option value="' + c.id + '"' + (svc && svc.categoryId === c.id ? ' selected' : '') + '>' + PU.escapeHtml(c.title) + '</option>').join('') +
          '</select>' +
          '<label class="field-label">توضیح</label>' +
          '<input class="input" id="svc-desc" value="' + PU.escapeHtml(svc ? svc.description : '') + '">' +
          '<label class="field-label">قیمت پایه (تومان)</label>' +
          '<input class="input" id="svc-price" inputmode="numeric" value="' + (svc ? svc.basePrice : 0) + '">' +
          '<label class="field-label">زمان تقریبی</label>' +
          '<input class="input" id="svc-time" value="' + PU.escapeHtml(svc ? svc.estimatedTime : '') + '">' +

          '<label class="field-label">آیکون</label>' +
          '<div id="svc-icon-host">' + svcIconFieldHtml() + '</div>' +

          '<label class="field-label" style="margin-top:12px">رنگ آیکون (اختیاری)</label>' +
          '<input class="input" id="svc-color" type="text" value="' + PU.escapeHtml(svcIconColor) + '" placeholder="#1559de یا خالی برای رنگ پیش‌فرض">' +
          '<div id="svc-color-swatches" style="display:flex;flex-wrap:wrap;gap:5px;margin-top:6px">' +
            ['#1559de', '#10aec8', '#0ca67d', '#40a83b', '#f4d33b',
             '#f08d37', '#e75b2e', '#d91543', '#ef6c7b', '#a82cc7',
             '#8138d4', '#475d93', '#159db5', '#124be0', '#e40012'].map(function (c) {
              return '<button type="button" class="svc-color-swatch" data-color="' + c + '" ' +
                'style="width:22px;height:22px;border-radius:6px;background:' + c + ';' +
                'border:2px solid #fff;box-shadow:0 0 0 1px var(--line);cursor:pointer;padding:0"></button>';
            }).join('') +
          '</div>' +

          '<label class="field-label" style="margin-top:12px">ترتیب نمایش در دسته (عدد کوچک‌تر = زودتر)</label>' +
          '<input class="input" id="svc-sort" type="number" inputmode="numeric" value="' + svcSortOrder + '" style="margin-bottom:6px">' +
          '<div style="font-size:11px;color:var(--ink-soft);margin-bottom:10px">خدمات با عدد مساوی، بر اساس زمان ایجاد مرتب می‌شوند.</div>' +

          '<div class="card" style="padding:10px 12px;margin-bottom:10px;background:#fffbeb;border-color:#fde68a">' +
            '<label style="display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:700;color:#92400e;cursor:pointer">' +
              '<input type="checkbox" id="svc-featured"' + (svc && svc.isFeatured ? ' checked' : '') + ' style="width:18px;height:18px;accent-color:#f59e0b"> ' +
              icon('star') + 'نمایش در بخش «خدمات پرطرفدار» صفحه اول</label>' +
            '<div id="svc-featured-row" style="margin-top:8px;' + (svc && svc.isFeatured ? '' : 'display:none') + '">' +
              '<label class="field-label" style="margin:0 0 4px">ترتیب در بخش پرطرفدار (عدد کوچک‌تر = زودتر)</label>' +
              '<input class="input" id="svc-featured-sort" type="number" inputmode="numeric" value="' + (svc && typeof svc.featuredSort === 'number' ? svc.featuredSort : 0) + '">' +
            '</div>' +
          '</div>' +

          '<label style="display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:600;margin:14px 2px">' +
            '<input type="checkbox" id="svc-active"' + (!svc || svc.isActive ? ' checked' : '') + '> خدمت فعال باشد</label>' +

          '<div class="section-title">' + icon('edit') + 'فیلدهای پویا (' + PU.toPersianDigits(fields.length) + ')</div>' +
          '<div id="fields-list">' + fields.map((f, i) => fieldRow(f, i)).join('') + '</div>' +
          '<button class="btn ghost block sm" id="add-field">' + icon('plus') + 'افزودن فیلد</button>'
        );
        bindFieldEvents();
      }

      function fieldRow(f, i) {
        return '<div class="card" data-findex="' + i + '" style="margin-bottom:9px;padding:11px">' +
          '<div style="display:flex;gap:7px;margin-bottom:7px">' +
            '<input class="input" data-f="label" placeholder="عنوان فیلد" value="' + PU.escapeHtml(f.label || '') + '">' +
            '<select class="select" data-f="type" style="width:130px;flex:none">' +
              ['text', 'number', 'textarea', 'date', 'select', 'radio', 'checkbox'].map((t) =>
                '<option value="' + t + '"' + (f.type === t ? ' selected' : '') + '>' + ({ text: 'متن', number: 'عدد', textarea: 'متن بلند', date: 'تاریخ (شمسی)', select: 'انتخابی', radio: 'رادیویی', checkbox: 'چک‌باکس' }[t]) + '</option>').join('') +
            '</select>' +
          '</div>' +
          '<div style="display:flex;gap:7px;align-items:center">' +
            '<label style="font-size:11px;font-weight:600;display:flex;gap:5px;align-items:center"><input type="checkbox" data-f="required"' + (f.required ? ' checked' : '') + '> الزامی</label>' +
            '<button class="icon-btn" style="background:var(--rose-soft);color:var(--rose);margin-inline-start:auto" data-delfield="' + i + '">' + icon('trash') + '</button>' +
          '</div>' +
          ((f.type === 'select' || f.type === 'radio') ? optionsEditor(f) : '') +
        '</div>';
      }

      function optionsEditor(f) {
        return '<div style="margin-top:8px;border-top:1px dashed var(--line);padding-top:8px">' +
          '<div style="font-size:11px;font-weight:700;color:var(--ink-soft);margin-bottom:6px">گزینه‌ها (عنوان | قیمت اضافه)</div>' +
          (f.options || []).map((o) =>
            '<div style="display:flex;gap:6px;margin-bottom:5px">' +
              '<input class="input" data-opt-label placeholder="عنوان" value="' + PU.escapeHtml(o.label || '') + '">' +
              '<input class="input" data-opt-value placeholder="value" value="' + PU.escapeHtml(o.value || '') + '" style="width:90px">' +
              '<input class="input" data-opt-price placeholder="قیمت" inputmode="numeric" value="' + (o.extraPrice || 0) + '" style="width:80px">' +
            '</div>').join('') +
          '<button class="btn ghost sm" data-addopt>' + icon('plus') + ' گزینه</button>' +
        '</div>';
      }

      function collectFields() {
        sheet.body.find('[data-findex]').each(function () {
          const idx = Number($(this).data('findex'));
          const f = fields[idx];
          if (!f) return;
          f.label = $(this).find('[data-f="label"]').val();
          f.type = $(this).find('[data-f="type"]').val();
          f.required = $(this).find('[data-f="required"]').is(':checked');
          if (f.type === 'select' || f.type === 'radio') {
            f.options = [];
            $(this).find('[data-opt-label]').each(function () {
              const label = $(this).val();
              const value = $(this).siblings('[data-opt-value]').val() || ('opt-' + Math.random().toString(36).slice(2, 6));
              const price = Number($(this).siblings('[data-opt-price]').val()) || 0;
              f.options.push({ label, value, extraPrice: price });
            });
          }
        });
      }

      function bindFieldEvents() {
        // Open the IconPicker when the service-icon field is tapped.
        sheet.body.find('#svc-icon-trigger').off('click').on('click', function () {
          UI.iconPicker({
            current: svcIconKey,
            title: 'انتخاب آیکن خدمت',
            onPick: function (key, fa, label) {
              svcIconKey = key;
              // Refresh the WHOLE field so the label updates too.
              sheet.body.find('#svc-icon-host').html(svcIconFieldHtml());
            },
          });
        });

        // Color swatch click → fill the input + refresh preview tile only.
        sheet.body.find('.svc-color-swatch').off('click').on('click', function () {
          svcIconColor = $(this).data('color');
          sheet.body.find('#svc-color').val(svcIconColor);
          sheet.body.find('#svc-icon-preview').html(svcPreviewBox());
        });
        // Live preview when typing a hex color — only refresh the preview tile.
        sheet.body.find('#svc-color').off('input').on('input', PU.debounce(function () {
          svcIconColor = ($(this).val() || '').trim();
          sheet.body.find('#svc-icon-preview').html(svcPreviewBox());
        }, 220));

        // Featured checkbox → show/hide the featured_sort field.
        sheet.body.find('#svc-featured').off('change').on('change', function () {
          sheet.body.find('#svc-featured-row').toggle($(this).is(':checked'));
        });

        sheet.body.find('#add-field').off('click').on('click', () => {
          collectFields();
          fields.push({ id: 'fld-' + Math.random().toString(36).slice(2, 8), label: '', type: 'text', required: false });
          renderBody();
        });
        sheet.body.find('[data-delfield]').off('click').on('click', function () {
          collectFields();
          fields.splice(Number($(this).data('delfield')), 1);
          renderBody();
        });
        sheet.body.find('[data-addopt]').off('click').on('click', function () {
          collectFields();
          const idx = Number($(this).closest('[data-findex]').data('findex'));
          fields[idx].options = fields[idx].options || [];
          fields[idx].options.push({ label: '', value: '', extraPrice: 0 });
          renderBody();
        });
      }

      // Same permission pair as the backend delete route; the button is only
      // offered for an existing service (matches the user-form pattern).
      const canDeleteSvc = !!svc && (api.hasPermission('services.delete') || api.hasPermission('services.manage'));
      sheet.footer.html(
        (canDeleteSvc ? '<button class="btn rose block" id="svc-delete" style="margin-bottom:8px">' + icon('trash') + 'حذف خدمت</button>' : '') +
        '<button class="btn emerald block" id="svc-save">ذخیره خدمت</button>'
      );

      if (canDeleteSvc) sheet.footer.find('#svc-delete').on('click', async function () {
        if (!window.confirm('خدمت «' + svc.title + '» حذف شود؟ این عملیات قابل بازگشت نیست.')) return;
        UI.busy(true);
        try {
          await api.deleteService(svc.id);
          UI.toast('خدمت حذف شد.', 'success');
          sheet.close();
          render();
        } catch (e) { UI.toast(e.message, 'error'); }
        finally { UI.busy(false); }
      });

      sheet.footer.find('#svc-save').on('click', async function () {
        collectFields();
        try {
          await api.saveService({
            id: svc ? svc.id : null,
            title: sheet.body.find('#svc-title').val(),
            categoryId: sheet.body.find('#svc-cat').val(),
            description: sheet.body.find('#svc-desc').val(),
            basePrice: Number(sheet.body.find('#svc-price').val()) || 0,
            estimatedTime: sheet.body.find('#svc-time').val(),
            icon: svcIconKey,
            iconColor: (sheet.body.find('#svc-color').val() || '').trim(),
            sortOrder: parseInt(sheet.body.find('#svc-sort').val() || '0', 10),
            isActive: sheet.body.find('#svc-active').is(':checked'),
            isFeatured: sheet.body.find('#svc-featured').is(':checked'),
            featuredSort: parseInt(sheet.body.find('#svc-featured-sort').val() || '0', 10),
            fields,
          });
          UI.toast('خدمت ذخیره شد.', 'success');
          sheet.close();
          render();
        } catch (e) { UI.toast(e.message, 'error'); }
      });

      renderBody();
    }

    $host.find('#svc-seg .seg').on('click', function () {
      $host.find('#svc-seg .seg').removeClass('on');
      $(this).addClass('on');
      view = $(this).data('view');
      render();
    });

    render();
  };

  /* ================= Users + permissions matrix ================= */

  S.adminUsers = function (ctx) {
    return '<div id="users-list"><div class="spinner" style="margin:30px auto"></div></div>';
  };

  // Shared list renderer: used on first bind and on every background sync so
  // the table reflects create/edit/delete without a full screen rebuild.
  async function renderUsersList($host) {
    const $v = $host.find('#users-list');
    if (! $v.length) return;
    try {
      const users = await api.getUsers();
      // The “create user” action is gated by the users.create permission; an
      // admin (or a user with users.manage) always passes the check.
      const newButton = api.hasPermission('users.create')
        ? '<button class="btn block sm" id="new-user" style="margin-bottom:10px">' + icon('plus') + 'کاربر جدید</button>'
        : '';
      // Deleting a user is gated by users.delete (admin/manage implies it).
      const canDelete = api.hasPermission('users.delete');
      const rows = users.map((u) =>
        '<div class="list-row"><div class="lr-icon ' +
          ({ admin: 'tone-rose', operator: 'tone-indigo', customer: 'tone-sky' }[u.role] || 'tone-slate') + '">' + icon('user') + '</div>' +
        '<div class="lr-main"><div class="lr-title">' + PU.escapeHtml(u.name) + (u.isBlocked ? ' ⛔' : '') + '</div>' +
        '<div class="lr-sub">' + PU.toPersianDigits(u.phone) + ' • ' + PU.escapeHtml(PERM.ROLE_LABELS[u.role] || u.role) + ' • ' + PU.formatToman(u.walletBalance) + '</div></div>' +
        (canDelete ? '<button class="icon-btn" style="background:var(--rose-soft);color:var(--rose)" data-del-user="' + u.id + '">' + icon('trash') + '</button>' : '') +
        '<button class="icon-btn" style="background:var(--slate-soft);color:var(--ink)" data-edit-user="' + u.id + '">' + icon('edit') + '</button></div>'
      ).join('');

      $v.html(newButton + (rows || UI.emptyState('کاربری ثبت نشده', '', 'user')));

      $v.find('#new-user').on('click', () => openUserForm(null));
      $v.find('[data-edit-user]').on('click', function () {
        const user = users.find((u) => u.id === $(this).data('edit-user'));
        openUserForm(user);
      });
      $v.find('[data-del-user]').on('click', async function () {
        const id = $(this).data('del-user');
        const user = users.find((u) => u.id === id);
        if (!window.confirm('کاربر «' + (user ? user.name : '') + '» و همهٔ داده‌های مرتبط با او (سفارش‌ها، پرداخت‌ها، تراکنش‌های کیف پول و پیامک‌ها) حذف شود؟ این عملیات قابل بازگشت نیست.')) return;
        UI.busy(true);
        try {
          await api.deleteUser(id);
          UI.toast('کاربر و داده‌های مرتبط حذف شد.', 'success');
          renderUsersList($host);
          api.syncWithBackend();
        } catch (e) { UI.toast(e.message, 'error'); }
        finally { UI.busy(false); }
      });
    } catch (e) {
      $v.html(UI.emptyState('خطا در بارگذاری کاربران', e.message, 'alert'));
    }
  }

  S.bind_admin_users = function ($host, ctx) {
    renderUsersList($host);
  };

  // Background syncs (fired after create/edit) re-render just the list.
  S.refresh_admin_users = function ($host) {
    renderUsersList($host);
  };

  function openUserForm(user) {
    const isNew = ! user;
    if (isNew) {
      user = { id: null, name: '', phone: '', role: 'customer', isBlocked: false, permissions: [], walletBalance: 0 };
    }
    let catalog = null;
    let effective = user.permissions || [];

    const sheet = UI.sheet({
      title: isNew ? 'کاربر جدید' : 'ویرایش کاربر',
      subtitle: isNew ? 'افزودن کاربر تازه به سیستم' : user.name + ' — ' + user.phone,
      icon: 'user', tone: 'indigo', size: 'tall',
    });

    api.getPermissionCatalog().then((cat) => {
      catalog = cat;
      effective = isNew
        ? PERM.resolve(user.role, null)
        : PERM.resolve(user.role, user.permissions && user.permissions.length ? JSON.stringify(user.permissions) : null);
      renderBody();
    });

    function renderBody() {
      sheet.body.html(
        (isNew
          ? '<label class="field-label">شماره موبایل <span class="req">*</span></label>' +
            '<input class="input" id="u-phone" inputmode="numeric" dir="ltr" placeholder="09xxxxxxxxx" value="' + PU.escapeHtml(user.phone) + '">'
          : '') +
        '<label class="field-label">نام</label>' +
        '<input class="input" id="u-name" value="' + PU.escapeHtml(user.name) + '">' +
        '<label class="field-label">نقش</label>' +
        '<div class="segmented" id="u-role">' +
          ['customer', 'operator', 'admin'].map((r) =>
            '<button class="seg ' + (user.role === r ? 'on' : '') + '" data-role="' + r + '">' + PERM.ROLE_LABELS[r] + '</button>').join('') +
        '</div>' +
        (isNew ? '' :
          '<label style="display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:600;margin:14px 2px">' +
            '<input type="checkbox" id="u-blocked"' + (user.isBlocked ? ' checked' : '') + '> حساب مسدود باشد</label>' +
          '<label class="field-label">تغییر موجودی کیف پول (مبلغ با علامت؛ مثلاً ۵۰۰۰۰ یا -۲۰۰۰۰)</label>' +
          '<input class="input" id="u-wallet" inputmode="numeric" placeholder="۰">') +

        '<div class="section-title">' + icon('shield') + 'ماتریس مجوزها</div>' +
        '<div id="perm-matrix"></div>'
      );

      const $matrix = sheet.body.find('#perm-matrix');
      if (!catalog) { $matrix.html('<div class="spinner" style="margin:16px auto"></div>'); return; }

      $matrix.html(catalog.groups.map((g) =>
        '<div class="perm-group"><div class="pg-title">' + PU.escapeHtml(g.title) + '</div>' +
        '<div class="pg-desc">' + PU.escapeHtml(g.description) + '</div>' +
        g.items.map((p) =>
          '<div class="perm-item"><div class="pi-label">' + PU.escapeHtml(p.label) +
          '<div class="pi-desc">' + PU.escapeHtml(p.description) + '</div></div>' +
          '<label class="switch"><input type="checkbox" data-perm="' + p.key + '"' +
          (effective.includes(p.key) ? ' checked' : '') + '><span class="track"></span></label></div>'
        ).join('') + '</div>'
      ).join(''));

      $matrix.find('[data-perm]').on('change', function () {
        const key = $(this).data('perm');
        if (this.checked) { if (!effective.includes(key)) effective.push(key); }
        else effective = effective.filter((k) => k !== key);
      });

      sheet.body.find('#u-role .seg').on('click', function () {
        sheet.body.find('#u-role .seg').removeClass('on');
        $(this).addClass('on');
        user.role = $(this).data('role');
        effective = PERM.resolve(user.role, null);
        renderBody();
      });
    }

    const canDeleteUser = ! isNew && api.hasPermission('users.delete');
    sheet.footer.html(
      (canDeleteUser ? '<button class="btn rose block" id="u-delete" style="margin-bottom:8px">' + icon('trash') + 'حذف کاربر و داده‌های مرتبط</button>' : '') +
      '<button class="btn emerald block" id="u-save">' + (isNew ? 'ایجاد کاربر' : 'ذخیره کاربر') + '</button>'
    );

    if (canDeleteUser) sheet.footer.find('#u-delete').on('click', async function () {
      if (!window.confirm('کاربر «' + user.name + '» و همهٔ داده‌های مرتبط با او (سفارش‌ها، پرداخت‌ها، تراکنش‌های کیف پول و پیامک‌ها) حذف شود؟ این عملیات قابل بازگشت نیست.')) return;
      UI.busy(true);
      try {
        await api.deleteUser(user.id);
        UI.toast('کاربر و داده‌های مرتبط حذف شد.', 'success');
        sheet.close();
        api.syncWithBackend();
      } catch (e) { UI.toast(e.message, 'error'); }
      finally { UI.busy(false); }
    });

    sheet.footer.find('#u-save').on('click', async function () {
      try {
        if (isNew) {
          const phone = PU.toLatinDigits(String(sheet.body.find('#u-phone').val() || '')).replace(/\D/g, '');
          if (!/^09\d{9}$/.test(phone)) { UI.toast('شماره موبایل معتبر نیست (مثال: 09123456789).', 'warning'); return; }
          await api.addUser({
            phone,
            name: sheet.body.find('#u-name').val(),
            role: user.role,
            permissions: effective,
          });
          UI.toast('کاربر ایجاد شد.', 'success');
        } else {
          const walletDelta = Number(PU.toLatinDigits(sheet.body.find('#u-wallet').val()).replace(/[^\d-]/g, '')) || 0;
          await api.updateUser(user.id, {
            name: sheet.body.find('#u-name').val(),
            role: user.role,
            isBlocked: sheet.body.find('#u-blocked').is(':checked'),
            walletChange: walletDelta,
            permissions: effective,
          });
          UI.toast('کاربر ذخیره شد.', 'success');
        }
        sheet.close();
        api.syncWithBackend();
      } catch (e) { UI.toast(e.message, 'error'); }
    });

    renderBody();
  }

  /* ================= Push notifications admin ================= */

  S.adminPush = function (ctx) {
    return (
      '<div class="segmented" id="push-seg">' +
        '<button class="seg on" data-view="broadcast">ارسال همگانی</button>' +
        '<button class="seg" data-view="manual">ارسال به کاربر</button>' +
        '<button class="seg" data-view="logs">تاریخچه</button>' +
      '</div>' +
      '<div id="push-view" style="margin-top:12px"></div>'
    );
  };

  S.bind_admin_push = function ($host, ctx) {
    let view = 'broadcast';

    function render() {
      const $v = $host.find('#push-view');
      if (view === 'broadcast') {
        $v.html(
          '<div class="card" style="padding:14px">' +
            '<div class="section-title" style="margin:0 0 10px">' + icon('bell') + 'ارسال اعلان همگانی (پوش + پیامک)</div>' +
            '<label class="field-label">عنوان <span class="req">*</span></label>' +
            '<input class="input" id="push-bc-title" placeholder="مثلاً: تخفیف ویژه آخر هفته">' +
            '<label class="field-label" style="margin-top:10px">متن <span class="req">*</span></label>' +
            '<textarea class="textarea" id="push-bc-body" rows="3" placeholder="متن اعلان…"></textarea>' +
            '<label class="field-label" style="margin-top:10px">موضوع پوش (topic)</label>' +
            '<input class="input" id="push-bc-topic" value="all" placeholder="all">' +
            '<div style="font-size:11px;color:var(--ink-soft);margin:4px 0 10px">پیش‌فرض «all» به همه کاربرانی که پوش فعال کرده‌اند ارسال می‌شود.</div>' +
            '<div class="card" style="padding:10px 12px;margin:8px 0;background:#f0f9ff;border-color:#bae6fd">' +
              '<label style="display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:700;color:#0c4a6e;cursor:pointer">' +
                '<input type="checkbox" id="push-bc-sms" style="width:18px;height:18px;accent-color:#0284c7"> ' + icon('sms') + 'ارسال همزمان پیامک</label>' +
              '<div id="push-bc-sms-row" style="margin-top:8px;display:none">' +
                '<label class="field-label" style="margin:0 0 4px">شماره‌های موبایل (با کاما جدا کنید)</label>' +
                '<textarea class="textarea" id="push-bc-sms-phones" rows="2" placeholder="09123456789, 09130000000" dir="ltr"></textarea>' +
                '<div style="font-size:10.5px;color:var(--ink-soft);margin-top:4px;line-height:1.6">پیامک فقط به این شماره‌ها ارسال می‌شود (پوش به همه می‌رسد). برای ارسال پترن، پترن «broadcast» را در تنظیمات سیستمی پیکربندی کنید.</div>' +
              '</div>' +
            '</div>' +
            '<button class="btn block" id="push-bc-send">' + icon('send') + 'ارسال اعلان</button>' +
          '</div>'
        );
        $v.find('#push-bc-sms').on('change', function () {
          $v.find('#push-bc-sms-row').toggle($(this).is(':checked'));
        });
        $v.find('#push-bc-send').on('click', async function () {
          const $btn = $(this).prop('disabled', true).text('در حال ارسال...');
          const title = $v.find('#push-bc-title').val().trim();
          const body = $v.find('#push-bc-body').val().trim();
          const topic = $v.find('#push-bc-topic').val().trim() || 'all';
          const sendSms = $v.find('#push-bc-sms').is(':checked');
          const smsRecipients = $v.find('#push-bc-sms-phones').val().trim();
          if (!title || !body) {
            UI.toast('عنوان و متن الزامی است.', 'error');
            $btn.prop('disabled', false).html(icon('send') + 'ارسال اعلان');
            return;
          }
          if (sendSms && !smsRecipients) {
            UI.toast('برای ارسال پیامک، حداقل یک شماره وارد کنید.', 'error');
            $btn.prop('disabled', false).html(icon('send') + 'ارسال اعلان');
            return;
          }
          try {
            const payload = { title, body, topic };
            if (sendSms) {
              payload.sendSms = true;
              payload.smsRecipients = smsRecipients;
            }
            const r = await api.request('POST', '/api/fcm/send-topic', payload);
            UI.toast(r.message, r.ok ? 'success' : 'error');
            if (r.ok) {
              $v.find('#push-bc-title').val('');
              $v.find('#push-bc-body').val('');
            }
          } catch (e) { UI.toast(e.message, 'error'); }
          finally { $btn.prop('disabled', false).html(icon('send') + 'ارسال اعلان'); }
        });
      } else if (view === 'manual') {
        $v.html(
          '<div class="card" style="padding:14px">' +
            '<div class="section-title" style="margin:0 0 10px">' + icon('user') + 'ارسال اعلان به یک کاربر (پوش + پیامک)</div>' +
            '<label class="field-label">شماره موبایل کاربر <span class="req">*</span></label>' +
            '<input class="input" id="push-mu-phone" inputmode="tel" placeholder="09123456789">' +
            '<label class="field-label" style="margin-top:10px">عنوان <span class="req">*</span></label>' +
            '<input class="input" id="push-mu-title" placeholder="عنوان اعلان">' +
            '<label class="field-label" style="margin-top:10px">متن <span class="req">*</span></label>' +
            '<textarea class="textarea" id="push-mu-body" rows="3" placeholder="متن اعلان…"></textarea>' +
            '<div class="card" style="padding:10px 12px;margin:8px 0;background:#f0f9ff;border-color:#bae6fd">' +
              '<label style="display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:700;color:#0c4a6e;cursor:pointer">' +
                '<input type="checkbox" id="push-mu-sms" checked style="width:18px;height:18px;accent-color:#0284c7"> ' + icon('sms') + 'ارسال همزمان پیامک به این شماره</label>' +
            '</div>' +
            '<button class="btn block" id="push-mu-send">' + icon('send') + 'ارسال اعلان</button>' +
          '</div>'
        );
        $v.find('#push-mu-send').on('click', async function () {
          const $btn = $(this).prop('disabled', true).text('در حال ارسال...');
          const phone = $v.find('#push-mu-phone').val().trim();
          const title = $v.find('#push-mu-title').val().trim();
          const body = $v.find('#push-mu-body').val().trim();
          const sendSms = $v.find('#push-mu-sms').is(':checked');
          if (!phone || !title || !body) {
            UI.toast('شماره موبایل، عنوان و متن همگی الزامی هستند.', 'error');
            $btn.prop('disabled', false).html(icon('send') + 'ارسال اعلان');
            return;
          }
          try {
            const payload = { phone, title, body };
            if (sendSms) payload.sendSms = true;
            const r = await api.request('POST', '/api/fcm/send-user', payload);
            UI.toast(r.message, r.sent > 0 || r.sms_sent ? 'success' : 'info');
            if (r.sent > 0 || r.sms_sent) {
              $v.find('#push-mu-title').val('');
              $v.find('#push-mu-body').val('');
            }
          } catch (e) { UI.toast(e.message, 'error'); }
          finally { $btn.prop('disabled', false).html(icon('send') + 'ارسال اعلان'); }
        });
      } else if (view === 'logs') {
        $v.html('<div id="push-logs-list" style="margin-top:4px"><div style="text-align:center;padding:20px;color:var(--ink-soft)">در حال بارگذاری…</div></div>');
        loadLogs($v);
      }
    }

    async function loadLogs($v) {
      try {
        const r = await api.request('GET', '/api/fcm/logs?limit=50');
        const logs = r.logs || [];
        const $list = $v.find('#push-logs-list');
        if (!logs.length) {
          $list.html(UI.emptyState('هنوز پوشی ارسال نشده', '', 'bell'));
          return;
        }
        const STATUS_META = {
          sent:    { tone: 'emerald', label: 'موفق' },
          failed:  { tone: 'rose',    label: 'ناموفق' },
          partial: { tone: 'amber',   label: 'جزئی' },
          pending: { tone: 'slate',   label: 'در انتظار' },
        };
        $list.html(logs.map((l) => {
          const meta = STATUS_META[l.status] || STATUS_META.pending;
          const channelLabel = l.channel === 'topic'
            ? ('موضوع: ' + PU.escapeHtml(l.target || ''))
            : ('کاربر: ' + PU.escapeHtml(String(l.target || '').slice(0, 12)) + '…');
          return (
            '<div class="card" style="margin-bottom:8px;padding:11px">' +
              '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">' +
                '<div style="flex:1;min-width:0">' +
                  '<div style="font-weight:700;font-size:13px">' + PU.escapeHtml(l.title || '(بدون عنوان)') + '</div>' +
                  (l.body ? '<div style="font-size:11.5px;color:var(--ink-soft);margin-top:2px">' + PU.escapeHtml(l.body) + '</div>' : '') +
                '</div>' +
                '<span class="pill ' + meta.tone + '">' + meta.label + '</span>' +
              '</div>' +
              '<div style="font-size:10.5px;color:var(--ink-soft);margin-top:6px;display:flex;flex-wrap:wrap;gap:8px">' +
                '<span>' + channelLabel + '</span>' +
                '<span>•</span>' +
                '<span>' + PU.toJalaliDate(l.createdAt) + '</span>' +
                (l.sentCount ? '<span>• ارسال: ' + PU.toPersianDigits(l.sentCount) + '</span>' : '') +
                (l.failedCount ? '<span>• ناموفق: ' + PU.toPersianDigits(l.failedCount) + '</span>' : '') +
              '</div>' +
              (l.error ? '<div style="font-size:10.5px;color:var(--rose);margin-top:4px">' + PU.escapeHtml(l.error) + '</div>' : '') +
            '</div>'
          );
        }).join(''));
      } catch (e) {
        $v.find('#push-logs-list').html(UI.emptyState('خطا در بارگذاری', e.message, 'alert'));
      }
    }

    render();
    $host.find('#push-seg .seg').on('click', function () {
      $host.find('#push-seg .seg').removeClass('on');
      $(this).addClass('on');
      view = $(this).data('view');
      render();
    });
  };

  Object.assign(window.Screens, S);
})();
