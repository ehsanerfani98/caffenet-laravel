/* Caffenet Lite — persian-utils.js
 * Port of src/utils/persianUtils.ts: digits, phone, formatting, Jalali. */
(function () {
  'use strict';

  const FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

  window.PU = {
    toPersianDigits(v) {
      return String(v ?? '').replace(/[0-9]/g, (d) => FA_DIGITS[Number(d)]);
    },

    toLatinDigits(v) {
      return String(v ?? '')
        .replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
        .replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));
    },

    /** Keeps only latin digits (and a leading +). */
    sanitizePhoneInput(v) {
      let s = PU.toLatinDigits(String(v ?? '')).replace(/[^0-9+]/g, '');
      if (s.indexOf('+') > 0) s = s.replace(/\+/g, '');
      if (s.startsWith('+')) s = '+' + s.slice(1).replace(/\+/g, '');
      return s;
    },

    isValidIranianMobile(phone) {
      return /^09\d{9}$/.test(PU.toLatinDigits(String(phone ?? '')));
    },

    normalizeIranianMobile(phone) {
      let s = PU.toLatinDigits(String(phone ?? '')).replace(/[^0-9+]/g, '');
      if (s.startsWith('+98')) s = '0' + s.slice(3);
      else if (s.startsWith('0098')) s = '0' + s.slice(4);
      else if (s.startsWith('98') && s.length >= 12) s = '0' + s.slice(2);
      else if (s.startsWith('9') && s.length === 10) s = '0' + s;
      return s;
    },

    formatToman(amount) {
      const n = Number(amount || 0);
      return PU.toPersianDigits(n.toLocaleString('en-US')) + ' تومان';
    },

    formatBytes(bytes) {
      const n = Number(bytes || 0);
      if (n < 1024) return PU.toPersianDigits(n) + ' بایت';
      if (n < 1024 * 1024) return PU.toPersianDigits((n / 1024).toFixed(1)) + ' کیلوبایت';
      return PU.toPersianDigits((n / (1024 * 1024)).toFixed(1)) + ' مگابایت';
    },

    /** Gregorian → Jalali (standard algorithm). */
    gregorianToJalali(gy, gm, gd) {
      const g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
      let jy = gy <= 1600 ? 0 : 979;
      gy -= gy <= 1600 ? 621 : 1600;
      const gy2 = gm > 2 ? gy + 1 : gy;
      let days =
        365 * gy + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) +
        Math.floor((gy2 + 399) / 400) - 80 + gd + g_d_m[gm - 1];
      jy += 33 * Math.floor(days / 12053);
      days %= 12053;
      jy += 4 * Math.floor(days / 1461);
      days %= 1461;
      if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
      const jm = days < 186 ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
      const jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30);
      return [jy, jm, jd];
    },

    /** Full Jalali date + time, e.g. «۱۴۰۴/۰۷/۱۵ - ۱۲:۳۰». */
    toJalaliDate(iso) {
      if (!iso) return '—';
      const d = new Date(iso);
      if (isNaN(d)) return '—';
      const [jy, jm, jd] = PU.gregorianToJalali(d.getFullYear(), d.getMonth() + 1, d.getDate());
      const hh = String(d.getHours()).padStart(2, '0');
      const mm = String(d.getMinutes()).padStart(2, '0');
      return PU.toPersianDigits(`${jy}/${String(jm).padStart(2, '0')}/${String(jd).padStart(2, '0')} - ${hh}:${mm}`);
    },

    toJalaliDateShort(iso) {
      if (!iso) return '—';
      const d = new Date(iso);
      if (isNaN(d)) return '—';
      const [jy, jm, jd] = PU.gregorianToJalali(d.getFullYear(), d.getMonth() + 1, d.getDate());
      return PU.toPersianDigits(`${jy}/${String(jm).padStart(2, '0')}/${String(jd).padStart(2, '0')}`);
    },

    generateTrackingCode() {
      return 'TRK-' + Math.floor(100000 + Math.random() * 900000);
    },

    escapeHtml(s) {
      return String(s ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    },

    /** Simple icon-name → emoji-free label fallback (icons.js handles SVGs). */
    debounce(fn, wait) {
      let t = null;
      return function (...args) {
        clearTimeout(t);
        t = setTimeout(() => fn.apply(this, args), wait);
      };
    },

    /** Preferred contact channels (multi-select), shared across screens. */
    contactMethods: [
      ['app_chat', 'چت داخلی برنامه'],
      ['phone', 'تماس تلفنی'],
      ['sms', 'پیامک'],
      ['telegram', 'تلگرام'],
      ['whatsapp', 'واتساپ'],
      ['rubika', 'روبیکا'],
      ['bale', 'بله'],
      ['eitaa', 'ایتا'],
    ],

    contactMethodLabel(key) {
      const found = PU.contactMethods.find((m) => m[0] === key);
      return found ? found[1] : String(key ?? '');
    },

    /** Joins selected channel keys into a Persian comma-separated list. */
    contactMethodsText(list) {
      if (!Array.isArray(list) || !list.length) return '';
      return list.map((k) => PU.contactMethodLabel(k)).join('، ');
    },

    /** Format a minute count as a Persian human duration: "۲۰ دقیقه", "۱ ساعت و ۵ دقیقه". */
    formatDuration(minutes) {
      const m = Math.max(0, Math.round(Number(minutes) || 0));
      if (m <= 0) return 'اکنون';
      if (m < 60) return PU.toPersianDigits(m) + ' دقیقه';
      const h = Math.floor(m / 60);
      const rem = m % 60;
      if (rem === 0) return PU.toPersianDigits(h) + ' ساعت';
      return PU.toPersianDigits(h) + ' ساعت و ' + PU.toPersianDigits(rem) + ' دقیقه';
    },

    /** Format an ISO 8601 timestamp as Persian HH:MM (e.g. "۱۴:۳۰"). */
    formatTime(iso) {
      if (!iso) return '—';
      const d = new Date(iso);
      if (isNaN(d.getTime())) return '—';
      const hh = String(d.getHours()).padStart(2, '0');
      const mm = String(d.getMinutes()).padStart(2, '0');
      return PU.toPersianDigits(hh + ':' + mm);
    },

    /** Format an ISO 8601 timestamp as Persian date + time "۱۴۰۳/۰۷/۲۱ - ۱۴:۳۰". */
    formatDateTime(iso) {
      if (!iso) return '—';
      return PU.toJalaliDate(iso);
    },

    /** Friendly label for the queue_type field. */
    queueTypeLabel(t) {
      const map = { quick: 'فوری', normal: 'عادی', long: 'زمان‌بر' };
      return map[t] || 'عادی';
    },
  };
})();
