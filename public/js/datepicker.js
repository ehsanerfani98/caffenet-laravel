/* Caffenet Lite — datepicker.js
 * Bridges the self-hosted JalaliDatePicker (public/JalaliDatePicker-main) into
 * the SPA. Every input carrying `data-jdp` gets a Jalali (shamsi) date picker.
 *
 * startWatch() installs one delegated `focusin` listener on <body>, so inputs
 * rendered later inside dynamically-created sheets (order form, coupons, …)
 * are picked up automatically — no re-initialisation needed after a re-render. */
(function () {
  'use strict';

  window.JDP = {
    /** Initialise the watcher once, after the DOM (and the library) is ready. */
    init() {
      if (!window.jalaliDatepicker) return;
      window.jalaliDatepicker.startWatch({
        selector: 'input[data-jdp]',
        // Above the app's sheets (60), toast (90), busy overlay (99) and splash (200).
        zIndex: 1100,
        container: 'body',
        useDropdownYears: true,
        changeMonthRotateYear: true,
        autoReadOnlyInput: false,
        // Read target-input rules per field from the input attributes
        // (data-jdp-target-value-input / data-jdp-target-value-type) so a
        // picker can write a Gregorian value into a hidden input while the
        // customer sees a Jalali date. Inputs without the attributes are
        // unaffected (the getters simply return null).
        targetValueInput: 'attr',
        targetValueType: 'attr',
      });
    },

    /** Programmatically open the picker for an input element. */
    show(input) {
      if (window.jalaliDatepicker && input) window.jalaliDatepicker.show(input);
    },

    /** Gregorian "YYYY-MM-DD" → Jalali "YYYY/MM/DD" for pre-filling pickers. */
    gregorianToJalaliString(iso) {
      if (!iso) return '';
      const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso));
      if (!m) return '';
      const parts = window.PU.gregorianToJalali(Number(m[1]), Number(m[2]), Number(m[3]));
      return parts[0] + '/' + String(parts[1]).padStart(2, '0') + '/' + String(parts[2]).padStart(2, '0');
    },
  };

  $(function () { window.JDP.init(); });
})();
