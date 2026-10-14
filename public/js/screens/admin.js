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
      if (view === 'services') {
        const cats = api.state.categories;
        let html = '<button class="btn block sm" id="new-service" style="margin-bottom:10px">' + icon('plus') + 'خدمت جدید</button>';
        cats.forEach((c) => {
          const items = api.state.services.filter((s) => s.categoryId === c.id);
          html += '<div class="cat-head" style="margin:10px 2px 7px">' + renderServiceIcon(c.iconName) + ' ' + PU.escapeHtml(c.title) + '</div>';
          html += items.map((s) =>
            '<div class="list-row"><div class="lr-icon ' + (s.isActive ? 'tone-emerald' : 'tone-slate') + '">' + renderServiceIcon(s.icon) + '</div>' +
            '<div class="lr-main"><div class="lr-title">' + PU.escapeHtml(s.title) + '</div>' +
            '<div class="lr-sub">' + PU.toPersianDigits(s.fields.length) + ' فیلد • ' + (s.isActive ? 'فعال' : 'غیرفعال') + '</div></div>' +
            '<button class="icon-btn" style="background:var(--slate-soft);color:var(--ink)" data-edit-svc="' + s.id + '">' + icon('edit') + '</button></div>'
          ).join('');
        });
        $v.html(html);
        $v.find('#new-service').on('click', () => openServiceForm(null));
        $v.find('[data-edit-svc]').on('click', function () {
          const svc = api.state.services.find((s) => s.id === $(this).data('edit-svc'));
          openServiceForm(svc);
        });
      } else {
        let html = '<button class="btn block sm" id="new-category" style="margin-bottom:10px">' + icon('plus') + 'دسته‌بندی جدید</button>';
        html += api.state.categories.map(function (c) {
          // Use the colorful icon (matching home page) when available; tint
          // with the saved color when set. Otherwise fall back to the legacy
          // stroke icon to preserve backward compatibility.
          const key = (c.iconName || '').toLowerCase();
          let iconSvg;
          if (window.CATEGORY_ICON_KEYS && window.CATEGORY_ICON_KEYS.indexOf(key) !== -1) {
            iconSvg = c.iconColor
              ? window.renderCategoryIconTinted(key, c.iconColor)
              : window.renderCategoryIcon(key);
          } else {
            iconSvg = renderServiceIcon(c.iconName);
          }
          const orderLabel = (typeof c.sortOrder === 'number' && c.sortOrder !== 0)
            ? ' · ترتیب: ' + PU.toPersianDigits(c.sortOrder)
            : '';
          return (
            '<div class="list-row"><div class="lr-icon" style="background:transparent;padding:0;width:44px;height:44px;flex:none;overflow:hidden;">' + iconSvg + '</div>' +
            '<div class="lr-main"><div class="lr-title">' + PU.escapeHtml(c.title) + '</div>' +
            '<div class="lr-sub">' + PU.escapeHtml(c.description || '') + orderLabel + '</div></div>' +
            '<button class="icon-btn" style="background:var(--slate-soft);color:var(--ink)" data-edit-cat="' + c.id + '">' + icon('edit') + '</button></div>'
          );
        }).join('');
        $v.html(html);
        $v.find('#new-category').on('click', () => openCategoryForm(null));
        $v.find('[data-edit-cat]').on('click', function () {
          const cat = api.state.categories.find((c) => c.id === $(this).data('edit-cat'));
          openCategoryForm(cat);
        });
      }
    }

    function openCategoryForm(cat) {
      const sheet = UI.sheet({
        title: cat ? 'ویرایش دسته‌بندی' : 'دسته‌بندی جدید',
        icon: 'grid', tone: 'sky',
      });

      // Build the icon picker: a dropdown of friendly names + a live preview
      // tile that mirrors exactly how the icon will appear on the home page.
      const ICON_KEYS = window.CATEGORY_ICON_KEYS || ['printer','gradcap','building','landmark','car','grid','folder','wallet','receipt','bank','shield','ticket','pencil','heart','passport','chart','bolt','users','globe','stamp','more'];
      const ICON_LABELS = window.CATEGORY_ICON_LABELS || {};
      const currentIcon = (cat && cat.iconName ? cat.iconName : 'printer').toLowerCase();
      const currentColor = cat && cat.iconColor ? cat.iconColor : '';
      const currentOrder = cat && typeof cat.sortOrder === 'number' ? cat.sortOrder : 0;

      // Preset palette shown as swatches — picks fill the hex input.
      const PRESET_COLORS = [
        '#1559de', '#10aec8', '#0ca67d', '#40a83b', '#f4d33b',
        '#f08d37', '#e75b2e', '#d91543', '#ef6c7b', '#a82cc7',
        '#8138d4', '#475d93', '#159db5', '#124be0', '#e40012',
      ];

      function previewHtml(iconKey, color) {
        const key = (iconKey || 'printer').toLowerCase();
        const svg = color
          ? window.renderCategoryIconTinted(key, color)
          : window.renderCategoryIcon(key);
        // Use a tile that mirrors .cat-icon-box sizing (roughly 72px wide).
        return '<div style="width:72px;height:72px;display:grid;place-items:center;' +
          'background:#fff;border:1px solid var(--line);border-radius:18px;' +
          'box-shadow:0 2px 10px rgba(24,34,48,.07);margin:0 auto;">' + svg + '</div>';
      }

      sheet.body.html(
        '<label class="field-label">عنوان <span class="req">*</span></label>' +
        '<input class="input" id="cat-title" value="' + PU.escapeHtml(cat ? cat.title : '') + '">' +

        '<label class="field-label">آیکون</label>' +
        '<select class="select" id="cat-icon">' +
          ICON_KEYS.map(function (k) {
            const label = ICON_LABELS[k] || k;
            const sel = (k === currentIcon) ? ' selected' : '';
            return '<option value="' + k + '"' + sel + '>' + PU.escapeHtml(label) + '</option>';
          }).join('') +
        '</select>' +

        '<div style="display:flex;gap:10px;align-items:center;margin:10px 0">' +
          '<div id="cat-icon-preview">' + previewHtml(currentIcon, currentColor) + '</div>' +
          '<div style="flex:1;min-width:0">' +
            '<label class="field-label" style="margin:0 0 6px">رنگ آیکون (اختیاری)</label>' +
            '<input class="input" id="cat-color" type="text" value="' + PU.escapeHtml(currentColor) + '" placeholder="#a82cc7 یا خالی برای رنگ پیش‌فرض" style="margin-bottom:8px">' +
            '<div id="cat-color-swatches" style="display:flex;flex-wrap:wrap;gap:5px">' +
              PRESET_COLORS.map(function (c) {
                return '<button type="button" class="cat-color-swatch" data-color="' + c + '" ' +
                  'style="width:22px;height:22px;border-radius:6px;background:' + c + ';' +
                  'border:2px solid #fff;box-shadow:0 0 0 1px var(--line);cursor:pointer;padding:0"></button>';
              }).join('') +
            '</div>' +
          '</div>' +
        '</div>' +

        '<label class="field-label">ترتیب نمایش (عدد کوچک‌تر = زودتر)</label>' +
        '<input class="input" id="cat-sort" type="number" inputmode="numeric" value="' + currentOrder + '" style="margin-bottom:6px">' +
        '<div style="font-size:11px;color:var(--ink-soft);margin-bottom:10px">دسته‌بندی‌ها با عدد مساوی، بر اساس زمان ایجاد مرتب می‌شوند.</div>' +

        '<label class="field-label">توضیح</label>' +
        '<input class="input" id="cat-desc" value="' + PU.escapeHtml(cat ? cat.description : '') + '">'
      );

      // Live preview update on icon / color change.
      function updatePreview() {
        const iconKey = sheet.body.find('#cat-icon').val();
        const color = sheet.body.find('#cat-color').val().trim();
        sheet.body.find('#cat-icon-preview').html(previewHtml(iconKey, color));
      }
      sheet.body.find('#cat-icon').on('change', updatePreview);
      sheet.body.find('#cat-color').on('input', PU.debounce(updatePreview, 200));
      sheet.body.find('.cat-color-swatch').on('click', function () {
        sheet.body.find('#cat-color').val($(this).data('color'));
        updatePreview();
      });

      sheet.footer.html('<button class="btn block" id="cat-save">ذخیره</button>');
      sheet.footer.find('#cat-save').on('click', async function () {
        try {
          await api.saveCategory({
            id: cat ? cat.id : null,
            title: sheet.body.find('#cat-title').val(),
            iconName: sheet.body.find('#cat-icon').val(),
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
          '<select class="select" id="svc-icon">' +
            ['Printer', 'GraduationCap', 'Building2', 'Landmark', 'Car', 'FileSpreadsheet', 'FolderOpen'].map((i) =>
              '<option value="' + i + '"' + (svc && svc.icon === i ? ' selected' : '') + '>' + i + '</option>').join('') +
          '</select>' +
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

      sheet.footer.html('<button class="btn emerald block" id="svc-save">ذخیره خدمت</button>');
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
            icon: sheet.body.find('#svc-icon').val(),
            isActive: sheet.body.find('#svc-active').is(':checked'),
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

  Object.assign(window.Screens, S);
})();
