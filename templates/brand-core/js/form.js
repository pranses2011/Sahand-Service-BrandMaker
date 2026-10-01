/**
 * 📋 فرم‌های واقعی قالب‌ساز — ارسال بدون رفرش (v2.31)
 * ================================================
 * همه فرم‌های .sahand-form (درخواست/تماس/خبرنامه/...) روی سایت برند
 * با AJAX به /js/form-submit.php می‌روند → API سایت‌ساز form-entry
 * مقصد ارسال (پنل/ایمیل/تلگرام/بله) از data-dest خوانده می‌شود.
 */
(function () {
    'use strict';

    function faEn(s) { return String(s || '').replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }); }

    /* ═══ 🎭 v2.45 (S11) — موتور ماسک فرمت‌کننده ورودی ═══
       الگو: «#» = رقم، «A» = حرف لاتین، «*» = هر کاراکتر، بقیه = جداشنما ثابت.
       هنگام تایپ: ارقام فارسی → انگلیسی، کاراکتر اضافه حذف، جداشنبا خودکار. */
    var MASK_ERRORS = {
        national_code: 'کد ملی معتبر نیست — دقیقاً ۱۰ رقم وارد کنید.',
        mobile: 'شماره موبایل معتبر نیست — مثل ۰۹۱۲ ۳۴۵ ۶۷۸۹.',
        phone: 'شماره تلفن معتبر نیست — مثل ۰۴۱ ۳۳۳ ۱۲۳۴۵.',
        card: 'شماره کارت معتبر نیست — ۱۶ رقم وارد کنید.',
        sheba: 'شماره شبا معتبر نیست — IR به‌همراه ۲۴ رقم.',
        postal: 'کد پستی معتبر نیست — دقیقاً ۱۰ رقم.',
        custom: 'مقدار واردشده با فرمت خواسته‌شده نمی‌خواند.'
    };
    function maskApply(pattern, mode, raw) {
        var s = faEn(String(raw || ''));
        if (mode === 'numeric') { s = s.replace(/[^0-9]/g, ''); }
        else { s = s.replace(/[^0-9A-Za-z]/g, ''); }
        var out = '', pi = 0, si = 0;
        while (pi < pattern.length && si < s.length) {
            var pc = pattern.charAt(pi);
            if (pc === '#' || pc === 'A' || pc === '*') {
                var ch = s.charAt(si);
                if (pc === '#') { if (/[0-9]/.test(ch)) { out += ch; si++; pi++; } else { si++; } }
                else if (pc === 'A') { if (/[A-Za-z]/.test(ch)) { out += ch.toUpperCase(); si++; pi++; } else { si++; } }
                else { out += ch; si++; pi++; }
            } else {
                out += pc; pi++;
                /* جداشنبای پشت‌سرهم یا انتهایی */
                if (pattern.charAt(pi) !== '#' && pattern.charAt(pi) !== 'A' && pattern.charAt(pi) !== '*') { continue; }
            }
        }
        return out;
    }
    function maskPatternOf(el) {
        var preset = el.getAttribute('data-mask');
        var defs = {
            national_code: '##########',
            mobile: '#### ### ####',
            phone: '### ### ####',
            card: '####-####-####-####',
            sheba: 'IR########################',
            postal: '##########'
        };
        if (preset === 'custom') { return el.getAttribute('data-mask-pattern') || ''; }
        return defs[preset] || '';
    }
    function maskValidate(el) {
        var preset = el.getAttribute('data-mask');
        var v = faEn(el.value || '').replace(/[^0-9A-Za-z]/g, '');
        var digits = faEn(el.value || '').replace(/[^0-9]/g, '');
        if (preset === 'national_code') {
            if (!/^\d{10}$/.test(digits)) { return false; }
            var sum = 0;
            for (var i = 0; i < 9; i++) { sum += parseInt(digits.charAt(i), 10) * (10 - i); }
            var r = sum % 11;
            var chk = parseInt(digits.charAt(9), 10);
            return (r < 2 ? r : 11 - r) === chk;
        }
        if (preset === 'mobile') { return /^09\d{9}$/.test(digits); }
        if (preset === 'phone') { return /^0\d{10}$/.test(digits); }
        if (preset === 'card') {
            if (!/^\d{16}$/.test(digits)) { return false; }
            var csum = 0;
            for (var j = 0; j < 16; j++) {
                var d = parseInt(digits.charAt(j), 10);
                if (j % 2 === 0) { d *= 2; if (d > 9) { d -= 9; } }
                csum += d;
            }
            return csum % 10 === 0;
        }
        if (preset === 'sheba') { return digits.length === 24; }
        if (preset === 'postal') { return /^\d{10}$/.test(digits); }
        if (preset === 'custom') {
            var pat = el.getAttribute('data-mask-pattern') || '';
            var need = (pat.match(/[#A*]/g) || []).length;
            return v.length >= need;
        }
        return true;
    }
    /* فرمت زنده هنگام تایپ */
    document.addEventListener('input', function (e) {
        var el = e.target;
        if (!el || !el.getAttribute || !el.getAttribute('data-mask')) { return; }
        var pat = maskPatternOf(el);
        if (!pat) { return; }
        var mode = el.getAttribute('data-mask-mode') || 'numeric';
        var before = el.value;
        var after = maskApply(pat, mode, before);
        if (after !== before) {
            el.value = after;
            /* حفظ محل مکانیسم کارت (مرورگرهای مدرن خودکار درست می‌کنند) */
        }
    });
    /* چسباندن مقدار (paste) هم فرمت شود */
    document.addEventListener('paste', function (e) {
        var el = e.target;
        if (!el || !el.getAttribute || !el.getAttribute('data-mask')) { return; }
        setTimeout(function () {
            var pat = maskPatternOf(el);
            el.value = maskApply(pat, el.getAttribute('data-mask-mode') || 'numeric', el.value);
        }, 0);
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.classList || !form.classList.contains('sahand-form')) { return; }
        e.preventDefault();

        var msg = form.querySelector('.sahand-form-msg');
        var btn = form.querySelector('.sahand-form-btn');
        var showMsg = function (text, ok) {
            if (!msg) { alert(text); return; }
            msg.style.display = 'block';
            msg.className = 'sahand-form-msg ' + (ok ? 'ok' : 'err');
            msg.textContent = text;
        };

        /* اعتبارسنجی سمت کلاینت */
        var firstBad = null;
        form.querySelectorAll('[required]').forEach(function (el) {
            el.style.borderColor = '';
            var v = faEn(el.value).trim();
            if (!v || (el.getAttribute('minlength') && v.length < parseInt(el.getAttribute('minlength'), 10))) {
                el.style.borderColor = '#dc2626';
                if (!firstBad) { firstBad = el; }
            }
        });
        if (firstBad) {
            firstBad.focus();
            showMsg('لطفاً فیلدهای ستاره‌دار را کامل کنید.', false);
            return;
        }

        /* 🎭 v2.45 (S11) — اعتبارسنجی فیلدهای ماسک‌دار */
        var badMask = null, badMaskMsg = '';
        form.querySelectorAll('[data-mask]').forEach(function (el) {
            el.style.borderColor = '';
            var val = faEn(el.value || '').trim();
            if (val === '') { return; } /* خالی: اگر الزامی باشد بالا گیر افتاد */
            if (!maskValidate(el)) {
                el.style.borderColor = '#dc2626';
                if (!badMask) {
                    badMask = el;
                    var lbl = el.closest('.form-group') && el.closest('.form-group').querySelector('label');
                    badMaskMsg = (lbl ? lbl.textContent.replace('*', '').trim() + ': ' : '') +
                        (MASK_ERRORS[el.getAttribute('data-mask')] || MASK_ERRORS.custom);
                }
            }
        });
        if (badMask) {
            badMask.focus();
            showMsg(badMaskMsg, false);
            return;
        }

        var data = {
            form_block: form.getAttribute('data-form') || 'custom',
            dest: (form.getAttribute('data-dest') || 'panel').split(','),
            page: location.pathname,
            fields: {}
        };
        /* 🧩 v2.44 (S12) — شناسه فرم سفارشی فرم‌ساز */
        var formSlug = form.getAttribute('data-form-slug');
        if (formSlug) { data.form_slug = formSlug; }
        new FormData(form).forEach(function (v, k) {
            if (k !== 'images') { data.fields[k] = v; }
        });

        /* درخواست خدمات → فیلدهای استاندارد هم ارسال شود (سازگاری API درخواست) */
        if (data.form_block === 'request-form' || data.form_block === 'hero-form') {
            data.full_name = data.fields.full_name || '';
            data.phone = faEn(data.fields.phone || '');
            data.address = data.fields.address || '';
            data.device_type = data.fields.device_type || '';
            data.device_other = data.fields.device_other || '';
            data.device_model = data.fields.device_model || '';
            data.description = data.fields.description || '';
            data.preferred_date = data.fields.preferred_date || '';
            data.preferred_time = data.fields.preferred_time || '';
        }

        /* 🖼️ آپلود تصاویر (فرم درخواست) — همان زنجیره صفحه /request */
        var filesInput = form.querySelector('input[type=file].sahand-file');
        var files = filesInput && filesInput.files ? Array.prototype.slice.call(filesInput.files, 0, 5) : [];
        var previews = form.querySelector('.sahand-imgs');
        if (previews) { previews.innerHTML = ''; }

        if (btn) { btn.disabled = true; btn.dataset.oldText = btn.textContent; btn.textContent = '⏳ در حال ارسال...'; }

        function uploadOne(file) {
            var fd = new FormData();
            fd.append('image', file);
            return fetch('/js/image-upload.php', { method: 'POST', body: fd })
                .then(function (r) { return r.text(); })
                .then(function (t) {
                    var res = {};
                    try { res = JSON.parse(t); } catch (err) {}
                    if (res && res.success && res.data && res.data.url) { return res.data.url; }
                    return null;
                })
                .catch(function () { return null; });
        }

        var chain = Promise.resolve();
        var imageUrls = [];
        files.forEach(function (f) {
            chain = chain.then(function () {
                if (btn) { btn.textContent = '⏳ آپلود تصاویر (' + (imageUrls.length + 1) + ' از ' + files.length + ')...'; }
                return uploadOne(f).then(function (u) { if (u) { imageUrls.push(u); } });
            });
        });

        chain.then(function () {
            if (imageUrls.length) { data.images = imageUrls; }
            if (btn) { btn.textContent = '⏳ در حال ارسال...'; }
            return fetch('/js/form-submit.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            }).then(function (r) {
                /* 🩹 v2.43 — هر پاسخ JSON را می‌پذیریم (حتی 4xx) تا پیام خطای
                   واقعی سرور به کاربر برسد؛ فقط پاسخ غیر JSON (5xx/HTML)
                   به catch می‌رود. ریشه «خطای ارتباط با سرور»ی بی‌اطلاعاتی. */
                return r.text().then(function (t) {
                    var res = {};
                    try { res = JSON.parse(t); } catch (err) { throw new Error('bad-json'); }
                    return res;
                });
            });
        }).then(function (res) {
            if (btn) { btn.disabled = false; btn.textContent = btn.dataset.oldText || 'ارسال'; }
            if (res && res.success) {
                showMsg((res.data && res.data.message) || '✅ با موفقیت ارسال شد — به‌زودی با شما تماس می‌گیریم.', true);
                form.reset();
            } else {
                var m = (res && (res.error || (res.errors || []).join(' — '))) || 'خطا در ارسال — دوباره تلاش کنید.';
                showMsg(m, false);
            }
        }).catch(function () {
            if (btn) { btn.disabled = false; btn.textContent = btn.dataset.oldText || 'ارسال'; }
            showMsg('خطای ارتباط با سرور — اینترنت خود را بررسی کنید یا با تلفن سایت تماس بگیرید.', false);
        });
    });

    /* نمایش/مخفی «سایر» در انتخاب دستگاه */
    document.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'device_type') {
            var box = e.target.closest('form') && e.target.closest('form').querySelector('.sahand-other-box');
            if (box) { box.style.display = e.target.value === 'other' ? '' : 'none'; }
        }
        /* پیش‌نمایش تصاویر */
        if (e.target && e.target.classList && e.target.classList.contains('sahand-file')) {
            var prev = e.target.closest('form') && e.target.closest('form').querySelector('.sahand-imgs');
            if (prev) {
                prev.innerHTML = '';
                Array.prototype.slice.call(e.target.files, 0, 5).forEach(function (f) {
                    var img = document.createElement('img');
                    img.src = URL.createObjectURL(f);
                    prev.appendChild(img);
                });
            }
        }
    });
})();
