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

        var data = {
            form_block: form.getAttribute('data-form') || 'custom',
            dest: (form.getAttribute('data-dest') || 'panel').split(','),
            page: location.pathname,
            fields: {}
        };
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
