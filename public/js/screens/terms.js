/* Caffenet Lite — screens/terms.js
 * «قوانین و حریم خصوصی» — full page + sheet. Renders the admin-editable
 * `termsText` business setting. The stored text is plain text (no HTML):
 * it is escaped first, then blank-line blocks become <p> and single newlines
 * become <br>, so an admin edit can never inject markup (XSS-safe). */
(function () {
  'use strict';

  window.Screens = window.Screens || {};

  const ACCEPT_KEY = 'caffenet_terms_accepted';

  // Plain-text → safe HTML.
  function renderLegalText(raw) {
    const text = String(raw == null ? '' : raw).trim();
    if (!text) {
      return '<div class="info-box">متن قوانین و حریم خصوصی هنوز تنظیم نشده است.</div>';
    }
    return text.split(/\n{2,}/).map(function (block) {
      return '<p>' + PU.escapeHtml(block).replace(/\n/g, '<br>') + '</p>';
    }).join('');
  }

  // Contact footer built live from the business settings — never part of the
  // editable text, so address/phone always stay current.
  function contactFooter() {
    const s = api.state.settings || {};
    const lines = [];
    if (s.coffeenetAddress) lines.push('آدرس: ' + PU.escapeHtml(s.coffeenetAddress));
    if (s.coffeenetPhone) lines.push('تلفن: ' + PU.toPersianDigits(s.coffeenetPhone));
    if (s.workingHours) lines.push('ساعت کاری: ' + PU.escapeHtml(s.workingHours));
    if (!lines.length) return '';
    return '<div class="info-box" style="margin-top:12px"><b>اطلاعات تماس</b><br>' + lines.join('<br>') + '</div>';
  }

  Screens.termsContent = function () {
    const s = api.state.settings || {};
    return '<div class="legal-text">' + renderLegalText(s.termsText) + '</div>' + contactFooter();
  };

  Screens.termsPage = function () {
    const s = api.state.settings || {};
    return (
      '<div class="card">' +
        '<div class="section-title">' + icon('shield') + 'قوانین و حریم خصوصی</div>' +
        '<div style="font-size:12px;color:var(--ink-soft);margin:0 2px 12px">' +
          PU.escapeHtml(s.siteTitle || 'کافی‌نت احسان') +
        '</div>' +
        Screens.termsContent() +
      '</div>'
    );
  };

  // Opens the policy inside a bottom sheet — used from the login consent link
  // and the profile «حریم خصوصی» row.
  Screens.openTerms = function () {
    const sheet = UI.sheet({
      title: 'قوانین و حریم خصوصی',
      subtitle: 'شرایط استفاده از خدمات و نحوهٔ محافظت از اطلاعات شما',
      icon: 'shield',
      tone: 'slate',
      size: 'tall',
    });
    sheet.body.html(Screens.termsContent());
    sheet.footer.html('<button class="btn block" id="terms-close">متوجه شدم</button>');
    sheet.footer.find('#terms-close').on('click', () => sheet.close());
    return sheet;
  };

  // Shared key so the auth screen can persist/read the explicit acceptance.
  window.TERMS_ACCEPT_KEY = ACCEPT_KEY;
})();
