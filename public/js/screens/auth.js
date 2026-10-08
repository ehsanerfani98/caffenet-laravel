/* Caffenet Lite — screens/auth.js
 * Two-step OTP login sheet (port of auth/AuthModal.tsx). */
(function () {
  'use strict';

  let timer = null;
  let secondsLeft = 0;

  function startTimer($el, onDone) {
    clearInterval(timer);
    secondsLeft = 120;
    const update = () => {
      $el.text('ارسال مجدد کد تا ' + PU.toPersianDigits(secondsLeft) + ' ثانیه دیگر');
      if (secondsLeft <= 0) {
        clearInterval(timer);
        onDone();
      }
      secondsLeft--;
    };
    update();
    timer = setInterval(update, 1000);
  }

  window.Screens = window.Screens || {};

  Screens.openAuth = function () {
    const sheet = UI.sheet({
      title: 'ورود / ثبت‌نام',
      subtitle: 'ورود با کد یکبارمصرف پیامکی',
      icon: 'lock',
      tone: 'sky',
      onClose: () => clearInterval(timer),
    });

    sheet.body.html(
      '<div class="step-phone">' +
        '<div class="warn-box hidden" id="auth-sms-off" style="margin-bottom:12px">سامانه پیامک غیرفعال است؛ در حال حاضر امکان دریافت کد تایید وجود ندارد. با مدیر سامانه تماس بگیرید.</div>' +
        '<label class="field-label">شماره موبایل <span class="req">*</span></label>' +
        '<input class="input" id="auth-phone" inputmode="tel" placeholder="۰۹xxxxxxxxx" dir="ltr" style="text-align:center;font-size:16px">' +
        '<label class="terms-consent">' +
          '<input type="checkbox" id="auth-terms">' +
          '<span><button type="button" id="auth-terms-link" class="terms-link">شرایط استفاده از خدمات و حریم خصوصی</button> را می‌پذیرم</span>' +
        '</label>' +
        '<button class="btn block" id="auth-send" style="margin-top:14px">' + icon('send') + 'دریافت کد تایید</button>' +
      '</div>' +
      '<div class="step-otp hidden">' +
        '<div class="info-box" style="margin-bottom:12px">کد تایید به شماره <b id="auth-shown-phone"></b> ارسال شد.</div>' +
        '<input class="input" id="auth-code" inputmode="numeric" maxlength="5" placeholder="- - - - -" dir="ltr" style="text-align:center;font-size:22px;letter-spacing:8px">' +
        '<div class="test-code hidden" id="auth-test-code" style="margin-top:10px"></div>' +
        '<button class="btn emerald block" id="auth-verify" style="margin-top:14px">' + icon('check') + 'تایید و ورود</button>' +
        '<button class="btn ghost block" id="auth-resend" style="margin-top:9px" disabled></button>' +
        '<button class="btn ghost block" id="auth-back" style="margin-top:9px">تغییر شماره</button>' +
      '</div>'
    );

    const phone = PU.normalizeIranianMobile(api.state.currentUser ? '' : '') ;

    // SMS off ⇒ the login cannot proceed (no real SMS, no simulator). Tell the
    // user up-front instead of failing silently on the send attempt.
    const smsOn = !(api.state.settings && api.state.settings.smsEnabled === false);
    if (!smsOn) {
      sheet.body.find('#auth-sms-off').removeClass('hidden');
      sheet.body.find('#auth-send').prop('disabled', true);
      UI.toast('سامانه پیامک غیرفعال است.', 'warning');
    }

    // Explicit consent: an intentional tap is the ONLY thing treated as
    // acceptance (never an implicit action, auto message or expiry). The flag
    // is a technical marker only — no personal data is stored locally.
    const TERMS_KEY = window.TERMS_ACCEPT_KEY || 'caffenet_terms_accepted';
    const termsAccepted = (function () {
      try { return localStorage.getItem(TERMS_KEY) === '1'; } catch (e) { return false; }
    })();
    sheet.body.find('#auth-terms').prop('checked', termsAccepted).on('change', function () {
      try {
        if (this.checked) localStorage.setItem(TERMS_KEY, '1');
        else localStorage.removeItem(TERMS_KEY);
      } catch (e) { /* storage unavailable — acceptance still holds for this attempt */ }
    });
    sheet.body.find('#auth-terms-link').on('click', function (e) {
      // Keep the link inside the sheet: open the policy without toggling the
      // checkbox or closing the login sheet.
      e.preventDefault();
      e.stopPropagation();
      if (window.Screens && Screens.openTerms) Screens.openTerms();
    });

    $('#auth-phone').on('input', function () {
      this.value = PU.sanitizePhoneInput(this.value);
    });

    $('#auth-send').on('click', async function () {
      if (!$('#auth-terms').is(':checked')) {
        UI.toast('برای ادامه، پذیرش شرایط استفاده از خدمات و حریم خصوصی الزامی است.', 'warning');
        return;
      }
      const p = PU.normalizeIranianMobile($('#auth-phone').val());
      if (!PU.isValidIranianMobile(p)) {
        UI.toast('شماره موبایل معتبر نیست.', 'error');
        return;
      }
      const $btn = $(this).prop('disabled', true);
      try {
        const r = await api.requestOtp(p);
        sheet.body.find('.step-phone').addClass('hidden');
        sheet.body.find('.step-otp').removeClass('hidden');
        $('#auth-shown-phone').text(PU.toPersianDigits(p));
        $('#auth-code').val('').focus();
        if (r.testCode) {
          $('#auth-test-code').removeClass('hidden').html(
            '<div class="warn-box">حالت آزمایشی: کد تایید شما <b style="font-size:16px;letter-spacing:3px">' +
            PU.toPersianDigits(r.testCode) + '</b> است. <button class="btn sm slate" id="auth-autofill" style="margin-top:6px">درج خودکار کد</button></div>'
          );
          $('#auth-autofill').on('click', function () { $('#auth-code').val(r.testCode); });
        }
        startTimer($('#auth-resend'), () => $('#auth-resend').prop('disabled', false).text('ارسال مجدد کد'));
        $('#auth-resend').prop('disabled', true);
        $('#auth-resend').off('click').on('click', async function () {
          if ($(this).prop('disabled')) return;
          const $btn = $(this).prop('disabled', true).text('در حال ارسال…');
          try {
            const r = await api.requestOtp(p);
            if (r.testCode) {
              $('#auth-test-code').removeClass('hidden').html(
                '<div class="warn-box">حالت آزمایشی: کد تایید شما <b style="font-size:16px;letter-spacing:3px">' +
                PU.toPersianDigits(r.testCode) + '</b> است. <button class="btn sm slate" id="auth-autofill" style="margin-top:6px">درج خودکار کد</button></div>'
              );
              $('#auth-autofill').on('click', function () { $('#auth-code').val(r.testCode); });
            }
            startTimer($('#auth-resend'), () => $('#auth-resend').prop('disabled', false).text('ارسال مجدد کد'));
          } catch (e) {
            UI.toast(e.message, 'error');
            $btn.prop('disabled', false).text('ارسال مجدد کد');
          }
        });
      } catch (e) {
        UI.toast(e.message, 'error');
      } finally {
        $btn.prop('disabled', false);
      }
    });

    $('#auth-verify').on('click', async function () {
      const code = PU.toLatinDigits($('#auth-code').val()).replace(/\D/g, '');
      if (code.length !== 5) {
        UI.toast('کد تایید پنج‌رقمی را وارد کنید.', 'warning');
        return;
      }
      const $btn = $(this).prop('disabled', true);
      UI.busy(true);
      try {
        const p = PU.normalizeIranianMobile($('#auth-shown-phone').text());
        await api.verifyOtp(p, code);
        clearInterval(timer);
        sheet.close();
        UI.toast('خوش آمدید!', 'success');
        App.render();
      } catch (e) {
        UI.toast(e.message, 'error');
      } finally {
        $btn.prop('disabled', false);
        UI.busy(false);
      }
    });

    $('#auth-back').on('click', () => {
      clearInterval(timer);
      sheet.body.find('.step-otp').addClass('hidden');
      sheet.body.find('.step-phone').removeClass('hidden');
    });
  };
})();
