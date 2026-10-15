/* Caffenet Lite — icons.js
 *
 * Two icon systems coexist:
 *
 *  1. The legacy `icon(name)` helper renders inline 24×24 stroke SVGs (lucide
 *     style). It is still used across most of the UI (bottom nav, buttons,
 *     toasts, sheets). DO NOT remove it — too many call sites depend on it.
 *
 *  2. The new Font Awesome 6 system (`faIcon(cls, opts)`) renders
 *     `<i class="fa-solid fa-<name>"></i>`. It is used by the admin
 *     IconPicker and the home-page category grid (because the user wants
 *     icons to be picked from the Font Awesome set, not hand-rolled SVGs).
 *
 * `renderCategoryIcon()` and `renderServiceIcon()` now BOTH go through the
 * Font Awesome path, while keeping their old call signatures so the
 * templates don't need to change.
 *
 * The Font Awesome CSS (`/public/css/fa.css`) declares the @font-face rules
 * and the `.fa-<name>:before { content: "..." }` aliases. Only the icons
 * listed in `ICON_LIBRARY` below are guaranteed to have a CSS alias — but
 * any FA6 free solid class will render as long as its alias is declared in
 * fa.css.
 */
(function () {
  'use strict';

  /* ────────────────────────────────────────────────────────────────────
   * 1) Legacy inline SVG icon set (lucide-style, 24×24 stroke)
   *    Used by the existing UI: bottom nav, buttons, toasts, sheets.
   * ──────────────────────────────────────────────────────────────────── */
  const PATHS = {
    coffee: '<path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><line x1="6" x2="6" y1="2" y2="4"/><line x1="10" x2="10" y1="2" y2="4"/><line x1="14" x2="14" y1="2" y2="4"/>',
    printer: '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
    gradcap: '<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>',
    building: '<rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
    landmark: '<line x1="3" x2="21" y1="22" y2="22"/><line x1="6" x2="6" y1="18" y2="11"/><line x1="10" x2="10" y1="18" y2="11"/><line x1="14" x2="14" y1="18" y2="11"/><line x1="18" x2="18" y1="18" y2="11"/><polygon points="12 2 20 7 4 7"/>',
    car: '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>',
    grid: '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
    home: '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    folder: '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
    wallet: '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/>',
    user: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    file: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
    files: '<path d="M20 7h-3a2 2 0 0 1-2-2V2"/><path d="M9 18a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h7l5 5v9a2 2 0 0 1-2 2Z"/><path d="M3 7.6v12.8A1.6 1.6 0 0 0 4.6 22h9.8"/>',
    clock: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    chart: '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
    cog: '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
    more: '<circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
    plus: '<path d="M5 12h14"/><path d="M12 5v14"/>',
    search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    check: '<polyline points="20 6 9 17 4 12"/>',
    checkcircle: '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    xcircle: '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
    alert: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
    bell: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    send: '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
    upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
    star: '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
    starFill: '<polygon fill="currentColor" points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
    ticket: '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/>',
    sms: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    shield: '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
    settings: '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
    edit: '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
    trash: '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
    arrowright: '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
    arrowleft: '<path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>',
    downloadcloud: '<path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="M12 12v9"/><path d="m16 16-4 4-4-4"/>',
    image: '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
    tag: '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
    lock: '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    phone: '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
    map: '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
    chevrondown: '<path d="m6 9 6 6 6-6"/>',
    refresh: '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
    eye: '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    zap: '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    gift: '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"/>',
    receipt: '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
  };

  window.icon = function (name, cls) {
    const body = PATHS[name] || PATHS.info;
    const c = cls ? ' ' + cls : '';
    return '<svg class="i' + c + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + body + '</svg>';
  };

  // Legacy PascalCase → lucide name map (kept for backward compatibility).
  // renderServiceIcon() now prefers the Font Awesome path (see below), but
  // still falls back to this map when the icon key isn't a known FA class.
  window.SERVICE_ICONS = {
    Printer: 'printer',
    GraduationCap: 'gradcap',
    Building2: 'building',
    Landmark: 'landmark',
    Car: 'car',
    FileSpreadsheet: 'grid',
    FolderOpen: 'folder',
  };

  /* ────────────────────────────────────────────────────────────────────
   * 2) Font Awesome 6 icon system
   * ──────────────────────────────────────────────────────────────────── */

  /**
   * Render a Font Awesome icon as `<i class="fa-solid fa-<name>"></i>`.
   * Optionally tinted via a `color` hex (defaults to currentColor).
   *
   * @param {string} faName  — Font Awesome icon name WITHOUT the `fa-`
   *                           prefix, e.g. "printer", "graduation-cap",
   *                           "building-columns".
   * @param {object} opts    — { color?: string, size?: 'sm'|'lg'|'2x'|'3x',
   *                              fw?: bool, cls?: string (extra classes) }
   */
  window.faIcon = function (faName, opts) {
    opts = opts || {};
    const name = String(faName || 'circle-question').replace(/^fa-/, '');
    const classes = ['fa-solid', 'fa-' + name];
    if (opts.fw) classes.push('fa-fw');
    if (opts.size) classes.push('fa-' + opts.size);
    if (opts.cls) classes.push(opts.cls);
    const style = opts.color ? ' style="color:' + opts.color + ';"' : '';
    return '<i class="' + classes.join(' ') + '"' + style + ' aria-hidden="true"></i>';
  };

  /* ─── Category icon library ───
   * Curated list of Font Awesome 6 free solid icons suitable for the
   * home-page category grid. Each entry: { key, fa, label }.
   *  - key   : stable storage key (stored in `categories.icon_name`)
   *  - fa    : Font Awesome class name (without `fa-` prefix)
   *  - label : Persian label shown in the IconPicker
   *
   * To add a new icon: append to this list AND make sure the alias is
   * declared in /public/css/fa.css. The IconPicker iterates this list,
   * so the new icon immediately appears in the admin dropdown.
   */
  const CATEGORY_LIBRARY = [
    { key: 'printer',          fa: 'print',                label: 'پرینت و کپی' },
    { key: 'student',          fa: 'graduation-cap',       label: 'خدمات دانشجویی' },
    { key: 'office',           fa: 'building',             label: 'خدمات اداری' },
    { key: 'government',       fa: 'landmark',             label: 'خدمات دولتی' },
    { key: 'car',              fa: 'car',                  label: 'خودرویی' },
    { key: 'general',          fa: 'grip',                 label: 'عمومی' },
    { key: 'archive',          fa: 'folder',               label: 'آرشیو و فایل' },
    { key: 'finance',          fa: 'wallet',               label: 'مالی و وام' },
    { key: 'bills',            fa: 'receipt',              label: 'قبض و صورتحساب' },
    { key: 'bank',             fa: 'building-columns',     label: 'بانکی' },
    { key: 'insurance',        fa: 'shield',               label: 'بیمه' },
    { key: 'travel',           fa: 'ticket',               label: 'بلیط و سفر' },
    { key: 'design',           fa: 'pen',                  label: 'طراحی و نگارش' },
    { key: 'medical',          fa: 'heart',                label: 'درمانی' },
    { key: 'passport',         fa: 'passport',             label: 'passport و اتباع' },
    { key: 'tax',              fa: 'chart-column',         label: 'مالیاتی' },
    { key: 'urgent',           fa: 'bolt',                 label: 'فوری و ویژه' },
    { key: 'social',           fa: 'users',                label: 'ارتباطات و شبکه' },
    { key: 'internet',         fa: 'globe',                label: 'ثبت‌نام اینترنتی' },
    { key: 'legal',            fa: 'stamp',                label: 'قضایی' },
    { key: 'other',            fa: 'ellipsis',             label: 'سایر خدمات' },
    { key: 'phone',            fa: 'phone',                label: 'تماس و تلفن' },
    { key: 'mail',             fa: 'envelope',             label: 'پست و ایمیل' },
    { key: 'shopping',         fa: 'cart-shopping',        label: 'خرید و فروش' },
    { key: 'store',            fa: 'store',                label: 'فروشگاه' },
    { key: 'delivery',         fa: 'truck-fast',           label: 'ارسال و پیک' },
    { key: 'box',              fa: 'box',                  label: 'بسته‌بندی' },
    { key: 'warehouse',        fa: 'warehouse',            label: 'انبار' },
    { key: 'tools',            fa: 'screwdriver-wrench',   label: 'تعمیرات و ابزار' },
    { key: 'wrench',           fa: 'wrench',               label: 'تعمیرکار' },
    { key: 'hammer',           fa: 'hammer',               label: 'نجاری' },
    { key: 'paint',            fa: 'paintbrush',           label: 'رنگ‌آمیزی' },
    { key: 'palette',          fa: 'palette',              label: 'هنری' },
    { key: 'camera',           fa: 'camera',               label: 'عکاسی' },
    { key: 'video',            fa: 'video',                label: 'ویدیو' },
    { key: 'music',            fa: 'music',                label: 'موسیقی' },
    { key: 'headphones',       fa: 'headphones',           label: 'صوتی' },
    { key: 'book',             fa: 'book',                 label: 'کتاب و مطالعه' },
    { key: 'clipboard',        fa: 'clipboard',            label: 'فرم و گزارش' },
    { key: 'file',             fa: 'file-lines',           label: 'اسناد متنی' },
    { key: 'pdf',              fa: 'file-pdf',             label: 'فایل PDF' },
    { key: 'word',             fa: 'file-word',            label: 'فایل Word' },
    { key: 'excel',            fa: 'file-excel',           label: 'فایل Excel' },
    { key: 'image',            fa: 'file-image',           label: 'تصاویر' },
    { key: 'archive-zip',      fa: 'file-zipper',          label: 'فایل فشرده' },
    { key: 'calendar',         fa: 'calendar',             label: 'تقویم و زمان‌بندی' },
    { key: 'clock',            fa: 'clock',                label: 'زمان‌سنجی' },
    { key: 'stopwatch',        fa: 'stopwatch',            label: 'کرنومتر' },
    { key: 'hourglass',        fa: 'hourglass-half',       label: 'در انتظار' },
    { key: 'coffee',           fa: 'mug-saucer',           label: 'کافی‌نت' },
    { key: 'food',             fa: 'utensils',             label: 'غذا' },
    { key: 'drink',            fa: 'glass-water',          label: 'نوشیدنی' },
    { key: 'leaf',             fa: 'leaf',                 label: 'محیط‌زیست' },
    { key: 'seed',             fa: 'seedling',             label: 'کشاورزی' },
    { key: 'tree',             fa: 'tree',                 label: 'فضای سبز' },
    { key: 'sun',              fa: 'sun',                  label: 'انرژی' },
    { key: 'fire',             fa: 'fire',                 label: 'اطفاء حریق' },
    { key: 'water',            fa: 'droplet',              label: 'آب' },
    { key: 'bus',              fa: 'bus',                  label: 'اتوبوس' },
    { key: 'train',            fa: 'train',                label: 'قطار' },
    { key: 'plane',            fa: 'plane',                label: 'هواپیما' },
    { key: 'ship',             fa: 'ship',                 label: 'کشتی' },
    { key: 'motorcycle',       fa: 'motorcycle',           label: 'موتور' },
    { key: 'bicycle',          fa: 'bicycle',              label: 'دوچرخه' },
    { key: 'rocket',           fa: 'rocket',               label: 'استارتاپ' },
    { key: 'flask',            fa: 'flask',                label: 'آزمایشگاه' },
    { key: 'atom',             fa: 'atom',                 label: 'علمی' },
    { key: 'microscope',       fa: 'microscope',           label: 'پزشکی آزمایشگاهی' },
    { key: 'stethoscope',      fa: 'stethoscope',          label: 'معاینه' },
    { key: 'hospital',         fa: 'hospital',             label: 'بیمارستان' },
    { key: 'firstaid',         fa: 'briefcase-medical',    label: 'کمک‌های اولیه' },
    { key: 'pills',            fa: 'pills',                label: 'دارو' },
    { key: 'prescription',     fa: 'prescription',         label: 'نسخه' },
    { key: 'tooth',            fa: 'tooth',                label: 'دندانپزشکی' },
    { key: 'gavel',            fa: 'gavel',                label: 'دادگاه' },
    { key: 'scale',            fa: 'scale-balanced',       label: 'عدالت' },
    { key: 'fingerprint',      fa: 'fingerprint',          label: 'هویت و احراز' },
    { key: 'idcard',           fa: 'id-card',              label: 'کارت شناسایی' },
    { key: 'school',           fa: 'school',               label: 'مدرسه' },
    { key: 'chalkboard',       fa: 'chalkboard-user',      label: 'آموزش' },
    { key: 'book-open',        fa: 'book-open',            label: 'کتاب‌خانه' },
    { key: 'bookmark',         fa: 'bookmark',             label: 'نشانه‌گذاری' },
    { key: 'tag',              fa: 'tag',                  label: 'برچسب' },
    { key: 'tags',             fa: 'tags',                 label: 'برچسب‌ها' },
    { key: 'gift',             fa: 'gift',                 label: 'هدیه' },
    { key: 'star',             fa: 'star',                 label: 'امتیاز' },
    { key: 'trophy',           fa: 'trophy',               label: 'مسابقه' },
    { key: 'medal',            fa: 'medal',                label: 'افتخار' },
    { key: 'crown',            fa: 'crown',                label: 'ویژه' },
    { key: 'helmet',           fa: 'helmet-safety',        label: 'ایمنی' },
    { key: 'wifi',             fa: 'wifi',                 label: 'اینترنت' },
    { key: 'signal',           fa: 'signal',               label: 'ارتباط' },
    { key: 'tower',            fa: 'tower-broadcast',      label: 'رادیویی' },
    { key: 'satellite',        fa: 'satellite',            label: 'ماهواره‌ای' },
    { key: 'chip',             fa: 'microchip',            label: 'الکترونیک' },
    { key: 'memory',           fa: 'memory',               label: 'حافظه' },
    { key: 'server',           fa: 'server',               label: 'سرور' },
    { key: 'database',         fa: 'database',             label: 'پایگاه داده' },
    { key: 'hdd',              fa: 'hard-drive',           label: 'دیسک' },
    { key: 'cloud',            fa: 'cloud',                label: 'ابر' },
    { key: 'cloud-up',         fa: 'cloud-arrow-up',       label: 'آپلود ابری' },
    { key: 'cloud-down',       fa: 'cloud-arrow-down',     label: 'دانلود ابری' },
    { key: 'lock',             fa: 'lock',                 label: 'امنیت' },
    { key: 'lock-open',        fa: 'lock-open',            label: 'باز' },
    { key: 'key',              fa: 'key',                  label: 'کلید' },
    { key: 'keyboard',         fa: 'keyboard',             label: 'تایپ' },
    { key: 'plug',             fa: 'plug',                 label: 'برق' },
    { key: 'battery',          fa: 'battery-full',         label: 'باتری' },
    { key: 'sitemap',          fa: 'sitemap',              label: 'ساختار' },
    { key: 'network',          fa: 'network-wired',        label: 'شبکه' },
    { key: 'share',            fa: 'share-nodes',          label: 'اشتراک‌گذاری' },
    { key: 'link',             fa: 'link',                 label: 'لینک' },
    { key: 'bell',             fa: 'bell',                 label: 'اطلاعیه' },
    { key: 'message',          fa: 'message',              label: 'پیام' },
    { key: 'comments',         fa: 'comments',             label: 'گفتگو' },
    { key: 'comment',          fa: 'comment',              label: 'نظر' },
    { key: 'phone-volume',     fa: 'phone-volume',         label: 'پاسخگویی' },
    { key: 'headset',          fa: 'headset',              label: 'پشتیبانی' },
    { key: 'bullhorn',         fa: 'bullhorn',             label: 'اعلان' },
    { key: 'percent',          fa: 'percent',              label: 'تخفیف' },
    { key: 'credit-card',      fa: 'credit-card',          label: 'کارت بانکی' },
    { key: 'money',            fa: 'money-bill',           label: 'پول نقد' },
    { key: 'coins',            fa: 'coins',                label: 'سکه' },
    { key: 'piggy',            fa: 'piggy-bank',           label: 'پس‌انداز' },
    { key: 'handshake',        fa: 'handshake',            label: 'توافق' },
    { key: 'paw',              fa: 'paw',                  label: 'حیوانات' },
    { key: 'dog',              fa: 'dog',                  label: 'سگ' },
    { key: 'cat',              fa: 'cat',                  label: 'گربه' },
    { key: 'fish',             fa: 'fish',                 label: 'ماهی' },
    { key: 'bird',             fa: 'feather',              label: 'پرنده' },
    { key: 'globe-asia',       fa: 'globe',                label: 'جهانی' },
    { key: 'user-tie',         fa: 'user-tie',             label: 'مدیر' },
    { key: 'user-doctor',      fa: 'user-doctor',          label: 'پزشک' },
    { key: 'user-graduate',    fa: 'user-graduate',        label: 'دانشجو' },
    { key: 'user-shield',      fa: 'user-shield',          label: 'نگهبان' },
    { key: 'users-gear',       fa: 'users-gear',           label: 'مدیریت کاربران' },
  ];

  // Expose the library for the IconPicker.
  window.CATEGORY_ICON_LIBRARY = CATEGORY_LIBRARY;
  window.CATEGORY_ICON_KEYS = CATEGORY_LIBRARY.map((it) => it.key);
  window.CATEGORY_ICON_LABELS = CATEGORY_LIBRARY.reduce((acc, it) => {
    acc[it.key] = it.label;
    return acc;
  }, {});

  // Quick lookups
  const CATEGORY_BY_KEY = CATEGORY_LIBRARY.reduce((acc, it) => {
    acc[it.key] = it;
    return acc;
  }, {});
  const CATEGORY_BY_FA = CATEGORY_LIBRARY.reduce((acc, it) => {
    acc[it.fa] = it;
    return acc;
  }, {});

  /**
   * Render a category icon by its storage key. Storage key may be:
   *  - one of the new keys ("printer", "student", …) — preferred path
   *  - a legacy PascalCase key ("Printer", "GraduationCap", …) — converted
   *    via SERVICE_ICONS and rendered through faIcon() with the closest FA
   *    match
   *  - a raw FA class name ("fa-print", "print", "graduation-cap") — used
   *    as-is
   *
   * `opts.color` tints the icon (hex). Falls back to currentColor.
   */
  window.renderCategoryIcon = function (key, opts) {
    opts = opts || {};
    const k = String(key || 'general');
    let fa = null;

    // 1) Direct hit in the library (preferred).
    if (CATEGORY_BY_KEY[k]) fa = CATEGORY_BY_KEY[k].fa;
    // 2) Raw FA class name (with or without `fa-` prefix).
    else if (typeof window.faIcon === 'function' && /^[a-z][a-z0-9-]*$/.test(k)) {
      fa = k.replace(/^fa-/, '');
    }
    // 3) Legacy PascalCase → map to FA via SERVICE_ICONS.
    else if (SERVICE_ICONS[k]) {
      // Map our internal lucide names → closest FA6 names.
      const LEGACY_TO_FA = {
        printer: 'print', gradcap: 'graduation-cap', building: 'building',
        landmark: 'landmark', car: 'car', grid: 'grip', folder: 'folder',
      };
      fa = LEGACY_TO_FA[SERVICE_ICONS[k]] || 'circle-question';
    }

    if (!fa) fa = 'circle-question';
    return window.faIcon(fa, opts);
  };

  /** Backward-compat: old code passed (key) and got back raw HTML.
   *  renderCategoryIconTinted(key, color) is now just an alias for
   *  renderCategoryIcon(key, { color }). */
  window.renderCategoryIconTinted = function (key, color) {
    return window.renderCategoryIcon(key, { color: color || null });
  };

  /**
   * Render a service icon by name. Mirrors renderCategoryIcon so the home
   * page grid + admin service form both show Font Awesome icons.
   */
  window.renderServiceIcon = function (name) {
    return window.renderCategoryIcon(name);
  };
})();
