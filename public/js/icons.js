/* Caffenet Lite — icons.js
 * Inline SVG icon set (24×24, stroke) covering every lucide icon the UI needs. */
(function () {
  'use strict';

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

  window.SERVICE_ICONS = {
    Printer: 'printer',
    GraduationCap: 'gradcap',
    Building2: 'building',
    Landmark: 'landmark',
    Car: 'car',
    FileSpreadsheet: 'grid',
    FolderOpen: 'folder',
  };

  window.renderServiceIcon = function (name) {
    return icon(SERVICE_ICONS[name] || 'grid');
  };

  /* ────────────────────────────────────────────────────────────────────
   * Colorful category icons — each icon key maps to a self-contained SVG
   * (its own fills + strokes) so the admin can pick from a rich palette
   * without importing external icon fonts. Used by the home page icon grid.
   *
   * Each icon is 64×64 viewBox to match the reference design; the host
   * element resizes via CSS.
   * ──────────────────────────────────────────────────────────────────── */
  const CATEGORY_SVGS = {
    // Printer — existing brand default
    printer: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><path d="M14 18h36a4 4 0 0 1 4 4v18H10V22a4 4 0 0 1 4-4Z" fill="#5b8def"/><path d="M16 30h32v22H16z" fill="#cdd9f7"/><path d="M10 40H6a3 3 0 0 1-3-3v-7a3 3 0 0 1 3-3h4z" fill="#3b6dd9"/><rect x="20" y="36" width="24" height="3" rx="1.5" fill="#7a9ef0"/><rect x="20" y="42" width="18" height="3" rx="1.5" fill="#7a9ef0"/><circle cx="48" cy="25" r="2" fill="#fff"/></svg>',
    gradcap: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><path d="M32 8 4 22l28 14 28-14Z" fill="#475d93"/><path d="M14 28v14c0 4 8 7 18 7s18-3 18-7V28" stroke="#475d93" stroke-width="4" fill="none"/><path d="M58 26v14" stroke="#475d93" stroke-width="3" stroke-linecap="round"/><rect x="28" y="36" width="8" height="10" fill="#a9baf0"/></svg>',
    building: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><rect x="14" y="8" width="36" height="48" rx="3" fill="#9bb1ed"/><path d="M22 18h6M22 26h6M22 34h6M22 42h6M36 18h6M36 26h6M36 34h6M36 42h6" stroke="#fff" stroke-width="3" stroke-linecap="round"/><path d="M28 56h8v-6h-8z" fill="#7d96dc"/></svg>',
    landmark: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><polygon points="32 6 58 20 6 20" fill="#a82cc7"/><rect x="10" y="22" width="44" height="34" fill="#7e1ca5"/><path d="M18 24v32M26 24v32M34 24v32M42 24v32" stroke="#fff" stroke-width="3"/><path d="M4 56h56" stroke="#5a1172" stroke-width="4" stroke-linecap="round"/></svg>',
    car: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><path d="m14 28 5-12c1-3 4-5 8-5h16c4 0 7 2 8 5l5 12 4 3v17H4V31l10-3Z" fill="#d91543"/><path d="m21 17-4 11h30l-4-11c-1-2-3-3-5-3H26c-2 0-4 1-5 3Z" fill="#fff"/><path d="M7 29h50" stroke="#d91543" stroke-width="4"/><circle cx="16" cy="45" r="5" fill="#fff"/><circle cx="48" cy="45" r="5" fill="#fff"/><path d="M4 33h56" stroke="#d91543" stroke-width="5" stroke-linecap="round"/></svg>',
    grid: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><rect x="6" y="6" width="22" height="22" rx="4" fill="#10aec8"/><rect x="36" y="6" width="22" height="22" rx="4" fill="#40a83b"/><rect x="6" y="36" width="22" height="22" rx="4" fill="#f4d33b"/><rect x="36" y="36" width="22" height="22" rx="4" fill="#e40012"/></svg>',
    folder: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><path d="M6 14a3 3 0 0 1 3-3h14l5 6h23a3 3 0 0 1 3 3v30a3 3 0 0 1-3 3H9a3 3 0 0 1-3-3Z" fill="#e58be8"/><path d="M6 22a3 3 0 0 1 3-3h46a3 3 0 0 1 3 3v28a3 3 0 0 1-3 3H9a3 3 0 0 1-3-3Z" fill="#a82cc7"/></svg>',
    wallet: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><rect x="6" y="14" width="52" height="36" rx="6" fill="#0ca67d"/><path d="M6 20a6 6 0 0 1 6-6h36v10H6Z" fill="#71d8b0"/><rect x="42" y="28" width="16" height="12" rx="3" fill="#fff"/><circle cx="50" cy="34" r="3" fill="#0ca67d"/></svg>',
    receipt: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><path d="M14 6h36a4 4 0 0 1 4 4v48l-6-4-6 4-6-4-6 4-6-4-6 4-6-4-6 4V10a4 4 0 0 1 4-4Z" fill="#50ddb0"/><path d="M22 20h20M22 30h20M22 40h14" stroke="#fff" stroke-width="3" stroke-linecap="round"/></svg>',
    bank: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><path d="M5 27 32 9l27 18H5Z" fill="#9bb1ed"/><path d="M12 31h40v22H12z" fill="#a9baf0"/><path d="M18 33v15M28 33v15M38 33v15M48 33v15M8 56h48" stroke="#fff" stroke-width="4" stroke-linecap="round"/><circle cx="32" cy="19" r="13" fill="#475d93" stroke="#fff" stroke-width="2"/><path d="M35 13c-4-3-8 1-4 4l4 2c4 2 1 6-4 5M32 10v17" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/></svg>',
    shield: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><path d="M32 5 8 16v16c0 14 10 22 24 27 14-5 24-13 24-27V16Z" fill="#159db5"/><path d="M22 33l8 8 14-14" stroke="#fff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    ticket: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><path d="M8 14h48a5 5 0 0 1 5 5v8a7 7 0 0 0 0 14v8a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5v-8a7 7 0 0 0 0-14v-8a5 5 0 0 1 5-5Z" fill="#f4d33b"/><path d="M34 16v5m0 6v5m0 6v5m0 6v4" stroke="#fff" stroke-width="3" stroke-linecap="round"/></svg>',
    pencil: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><path d="m12 47 5-16L42 6a5 5 0 0 1 7 0l9 9a5 5 0 0 1 0 7L33 47l-16 5-5-5Z" fill="#f08d37"/><path d="m38 11 15 15M21 38l5 5M29 30l5 5M37 22l5 5" stroke="#fff" stroke-width="3" stroke-linecap="round"/></svg>',
    heart: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><path d="M32 57 8 34C-5 18 15 2 29 14l3 3 3-3C49 2 69 18 56 34Z" fill="#ef6c7b"/><path d="M28 20h8v10h10v8H36v10h-8V38H18v-8h10V20Z" fill="#fff"/></svg>',
    passport: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><path d="M13 24h38a4 4 0 0 1 4 4v28H9V28a4 4 0 0 1 4-4Z" fill="#8138d4"/><path d="M20 9h27a3 3 0 0 1 3 3v12H17V12a3 3 0 0 1 3-3Z" stroke="#b997ec" stroke-width="4"/><path d="M22 16h21M22 21h15" stroke="#b997ec" stroke-width="3" stroke-linecap="round"/></svg>',
    chart: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><path d="M8 49h8V37H8v12Zm15 0h8V27h-8v22Zm15 0h8V15h-8v34Z" fill="#124be0"/></svg>',
    bolt: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><polygon points="38 4 8 36 28 36 24 60 56 26 36 26 38 4" fill="#f4d33b" stroke="#e0b820" stroke-width="2"/></svg>',
    users: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><circle cx="22" cy="20" r="10" fill="#40a83b"/><circle cx="46" cy="24" r="8" fill="#71d8b0"/><path d="M4 56c0-10 8-16 18-16s18 6 18 16" fill="#40a83b"/><path d="M40 56c0-8 6-13 13-13s13 5 13 13" fill="#71d8b0"/></svg>',
    globe: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><circle cx="32" cy="32" r="26" fill="#10aec8"/><path d="M7 23h50M5 33h54M9 44h46M32 6c-8 8-12 17-12 26s4 18 12 26M32 6c8 8 12 17 12 26s-4 18-12 26" stroke="#fff" stroke-width="3" stroke-linecap="round"/></svg>',
    stamp: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><path d="M20 8c6-4 18-4 24 0M23 16c5-3 13-3 18 0" stroke="#71d8b0" stroke-width="5" stroke-linecap="round"/><path d="M32 19c-5 0-8 4-10 8L11 46c-4 8 2 14 10 14h22c8 0 14-6 10-14L42 27c-2-4-5-8-10-8Z" fill="#0ca67d"/><path d="M36 31c-1-4-9-4-9 1 0 5 10 3 10 8 0 5-8 6-11 1M32 27v18" stroke="#fff" stroke-width="3" stroke-linecap="round"/></svg>',
    more: '<svg viewBox="0 0 64 64" fill="none" aria-hidden="true"><path d="M32 7a23 23 0 0 0-23 23v4" stroke="#e75b2e" stroke-width="5" stroke-linecap="round"/><path d="M20 34v-4a12 12 0 0 1 24 0v4" stroke="#e75b2e" stroke-width="5" stroke-linecap="round"/><path d="M24 35c0-5 7-5 7 0v7h5c6 0 8 6 4 10l-6 6H20l-7-7V40c0-4 6-4 6 0v-5c0-4 5-4 5 0Z" fill="#f06438"/></svg>',
  };

  // Friendly names (English key → Persian label) for the admin dropdown.
  const CATEGORY_LABELS = {
    printer: 'پرینت و کپی',
    gradcap: 'خدمات دانشجویی',
    building: 'خدمات اداری',
    landmark: 'خدمات دولتی',
    car: 'خودرویی',
    grid: 'عمومی',
    folder: 'آرشیو و فایل',
    wallet: 'مالی و وام',
    receipt: 'قبض و صورتحساب',
    bank: 'بانکی',
    shield: 'بیمه',
    ticket: 'بلیط و سفر',
    pencil: 'طراحی و نگارش',
    heart: 'درمانی',
    passport: ' passport و اتباع',
    chart: 'مالیاتی',
    bolt: 'فوری و ویژه',
    users: 'ارتباطات و شبکه',
    globe: 'ثبت‌نام اینترنتی',
    stamp: 'قضایی',
    more: 'سایر خدمات',
  };

  // Whitelist of icon keys the admin can pick from. Order = display order
  // in the admin dropdown (most common first).
  window.CATEGORY_ICON_KEYS = [
    'printer','gradcap','building','landmark','car','grid','folder','wallet',
    'receipt','bank','shield','ticket','pencil','heart','passport','chart',
    'bolt','users','globe','stamp','more',
  ];

  window.CATEGORY_ICON_LABELS = CATEGORY_LABELS;

  /**
   * Render a colorful category SVG by key. Falls back to a tinted version
   * of the default grid icon when the key is unknown.
   */
  window.renderCategoryIcon = function (key) {
    const svg = CATEGORY_SVGS[key] || CATEGORY_SVGS.grid;
    return svg;
  };

  /**
   * Render a category icon TINTED with a custom hex color. Used when the
   * admin sets an iconColor that should override the icon's natural palette.
   * Implementation: wrap the SVG in a span with CSS `color` set; the inner
   * paths use `currentColor` (we strip the original fill).
   *
   * Note: the colorful SVGs above keep their original colors by default —
   * this tinting is only applied when the admin explicitly sets iconColor.
   */
  window.renderCategoryIconTinted = function (key, color) {
    const svg = CATEGORY_SVGS[key] || CATEGORY_SVGS.grid;
    if (!color) return svg;
    // Replace all fills/strokes inside the svg with currentColor so the
    // outer span's color wins.
    const tinted = svg
      .replace(/fill="[^"]*"/g, 'fill="currentColor"')
      .replace(/stroke="[^"]*"/g, 'stroke="currentColor"');
    return '<span style="color:' + color + ';display:inline-flex;width:58%;height:58%;align-items:center;justify-content:center;">' + tinted + '</span>';
  };
})();
