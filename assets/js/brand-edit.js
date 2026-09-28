/* 🏷️ منطق ویرایش برند — P2-24: از admin/brand-edit.php جدا شد
 * (قبلاً ۳ بلوک <script> درون‌خطی ~۴۱۲ خط داخل فایل PHP بود)
 * وابستگی: بوت‌استرپ BE (آرایه داده) باید قبل از این فایل لود شود
 * @package SahandBrandMaker
 */

/* 🚀 بهبود خودکار سئو تا ۱۰۰ (v2.6) */
(function () {
    'use strict';
    var fa = function (n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); };

    function post(url, body) {
        var csrf = document.querySelector('input[name="csrf_token"]');
        var data = new URLSearchParams();
        Object.keys(body).forEach(function (k) { data.append(k, body[k]); });
        if (csrf) { data.append('csrf_token', csrf.value); }
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf ? csrf.value : '', 'X-Requested-With': 'XMLHttpRequest' },
            body: data.toString(),
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }

    var single = document.getElementById('btn-improve-page-seo');
    var report = document.getElementById('page-seo-report');
    if (single && report) {
        single.addEventListener('click', function () {
            post('brand-edit.php?id=BE[0]', { action: 'improve_page_seo', page_id: single.getAttribute('data-page') })
                .then(function (res) {
                    if (!res.success) { sahandToast({ message: res.error || 'بهبود ناموفق بود', type: 'danger' }); return; }
                    var d = res.data;
                    report.style.display = 'block';
                    report.innerHTML = '<div class="alert alert-' + (d.score_after >= 85 ? 'success' : 'warning') + '">' +
                        '🚀 امتیاز: ' + fa(d.score_before) + ' ← <b>' + fa(d.score_after) + '</b> (' + (d.score_after - d.score_before > 0 ? '+' + fa(d.score_after - d.score_before) : 'بدون تغییر') + ') در ' + fa(d.rounds) + ' دور' +
                        (d.applied.length ? '<br>✅ ' + d.applied.join('؛ ') : '') + '<br>🔄 برای دیدن نتیجه، صفحه را رفرش کنید.</div>';
                    sahandToast({ message: 'سئوی صفحه به ' + fa(d.score_after) + '/۱۰۰ رسید', type: d.score_after >= 85 ? 'success' : 'info' });
                })
                .catch(function (err) { sahandToast({ message: 'خطای ارتباط: ' + err.message, type: 'danger' }); });
        });
    }

    var all = document.getElementById('btn-improve-all-pages');
    if (all) {
        all.addEventListener('click', function () {
            all.disabled = true;
            all.innerHTML = '⏳ در حال بهبود همه صفحات... (چند لحظه)';
            post('brand-edit.php?id=BE[0]', { action: 'improve_all_pages' })
                .then(function (res) {
                    all.disabled = false;
                    all.innerHTML = '🚀🚀 بهبود همه بخش‌ها و صفحات برند با AI';
                    if (!res.success) { sahandToast({ message: res.error || 'بهبود ناموفق بود', type: 'danger' }); return; }
                    var d = res.data;
                    var rows = (d.report || []).map(function (r) {
                        return r.error
                            ? '❌ ' + r.page_type + ': ' + r.error
                            : '📄 ' + r.page_type + ': ' + fa(r.before) + ' → <b>' + fa(r.after) + '</b>';
                    }).join('<br>');
                    if (report) {
                        report.style.display = 'block';
                        report.innerHTML = '<div class="alert alert-success">🚀🚀 ' + fa(d.improved) + ' صفحه از ' + fa(d.pages) + ' صفحه بهبود یافت:<br>' + rows + '<br>🔄 برای دیدن نتیجه، صفحه را رفرش کنید.</div>';
                    }
                    sahandToast({ message: fa(d.improved) + ' از ' + fa(d.pages) + ' صفحه بهبود یافت', type: 'success' });
                })
                .catch(function (err) {
                    all.disabled = false;
                    all.innerHTML = '🚀🚀 بهبود همه بخش‌ها و صفحات برند با AI';
                    sahandToast({ message: 'خطای ارتباط: ' + err.message, type: 'danger' });
                });
        });
    }
})();

/* ═══════════ بلوک بعدی <script> قبلی ═══════════ */

/* ==================================================
 * ✨ v2.21: طراحی حرفه‌ای چیدمان هر صفحه با اسکیل UI/UX Pro
 * دکمه «✨ طراحی با UI/UX Pro» روی کارت هر صفحه در تب «صفحه‌ها»
 * ================================================== */
(function () {
    'use strict';
    var fa = function (n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); };

    window.uiuxDesignPage = function (pageId, pageName) {
        var btn = document.getElementById('btn-uiux-page-' + pageId);
        var panel = document.getElementById('uiux-page-result-' + pageId);
        if (!btn || !panel) { return; }
        var csrf = document.querySelector('input[name="csrf_token"]');
        var data = new URLSearchParams();
        data.append('action', 'uiux_design_page');
        data.append('page_id', pageId);
        if (csrf) { data.append('csrf_token', csrf.value); }

        var confirmMsg = 'چیدمان حرفه‌ای صفحه «' + (pageName || '') + '» با اسکیل UI/UX Pro طراحی و جایگزین چیدمان فعلی شود؟';
        var doDesign = function () {
            var old = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> در حال طراحی...';
            panel.style.display = 'none';
            fetch('brand-edit.php?id=BE[0]', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: data.toString(),
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).then(function (res) {
                btn.disabled = false;
                btn.innerHTML = old;
                if (!res.success) {
                    panel.style.display = 'block';
                    panel.className = 'alert alert-danger';
                    panel.innerHTML = '❌ ' + (res.error || 'خطای نامشخص');
                    sahandToast({ message: res.error || 'طراحی ناموفق بود', type: 'danger' });
                    return;
                }
                var d = res.data;
                var color = d.ux_score >= 85 ? '#16a34a' : d.ux_score >= 70 ? '#2563eb' : d.ux_score >= 50 ? '#d97706' : '#dc2626';
                var html = '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px">' +
                    '<span style="display:inline-block;min-width:86px;text-align:center;background:' + color + ';color:#fff;border-radius:10px;padding:4px 12px;font-weight:800;font-size:15px">' + fa(d.ux_score) + '/۱۰۰</span>' +
                    '<b>' + (d.grade_fa || '') + '</b>' +
                    '<span style="color:var(--text-light);font-size:12px">هدف صفحه: ' + (d.goal_fa || '') + '</span></div>' +
                    '<div style="font-size:12.5px;margin-bottom:4px"><b>💡 منطق طراحی (قوانین UX اعمال‌شده):</b></div><ul style="font-size:12.5px;margin:0 18px 6px 0;padding:0">';
                (d.rationale || []).forEach(function (r) { html += '<li style="margin-bottom:3px">' + r + '</li>'; });
                html += '</ul><div style="font-size:12px;color:var(--text-light)">💾 چیدمان روی این صفحه ذخیره شد — برای ویرایش درگ‌اند‌دراپ از دکمه «🎭 قالب‌ساز» استفاده کنید.</div>';
                panel.style.display = 'block';
                panel.className = 'alert alert-success';
                panel.innerHTML = html;
                /* نشان «چیدمان اختصاصی» اگر هنوز نیست */
                var card = document.getElementById('page-card-' + pageId);
                if (card && !card.querySelector('.badge-success')) {
                    var h = card.querySelector('.card-header .tools');
                    if (h) {
                        var b = document.createElement('span');
                        b.className = 'badge badge-success';
                        b.title = 'چیدمان درگ‌اند‌دراپ اختصاصی';
                        b.textContent = '🎭 چیدمان اختصاصی';
                        h.insertBefore(b, h.firstChild);
                    }
                }
                sahandToast({ message: 'چیدمان «' + (pageName || 'صفحه') + '» با امتیاز ' + fa(d.ux_score) + '/۱۰۰ طراحی شد', type: 'success' });
            }).catch(function (err) {
                btn.disabled = false;
                btn.innerHTML = old;
                panel.style.display = 'block';
                panel.className = 'alert alert-danger';
                panel.innerHTML = '❌ خطای ارتباط با سرور — دوباره تلاش کنید';
                sahandToast({ message: 'خطای ارتباط: ' + err.message, type: 'danger' });
            });
        };

        sahandConfirm({ title: 'طراحی با UI/UX Pro', message: confirmMsg, type: 'question', confirmText: 'بله، طراحی کن', confirmIcon: '✨' })
            .then(function (ok) { if (ok) { doDesign(); } });
    };
})();

/* ═══════════ بلوک بعدی <script> قبلی ═══════════ */

/* ==================================================
 * 🎨 انتخابگر رنگ حرفه‌ای + پیش‌نمایش زنده پالت (v2.9)
 * ================================================== */
(function () {
    'use strict';

    /* ---------- مبدل‌های رنگ ---------- */
    function hexToRgb(hex) {
        hex = hex.replace('#', '');
        if (hex.length === 3) { hex = hex.split('').map(function (c) { return c + c; }).join(''); }
        var n = parseInt(hex, 16);
        return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    }
    function rgbToHex(r, g, b) {
        return '#' + [r, g, b].map(function (v) {
            v = Math.max(0, Math.min(255, Math.round(v)));
            return v.toString(16).padStart(2, '0');
        }).join('').toUpperCase();
    }
    function rgbToHsv(r, g, b) {
        r /= 255; g /= 255; b /= 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b), d = max - min;
        var h = 0;
        if (d) {
            if (max === r) { h = ((g - b) / d + (g < b ? 6 : 0)); }
            else if (max === g) { h = (b - r) / d + 2; }
            else { h = (r - g) / d + 4; }
            h *= 60;
        }
        return [h, max ? d / max : 0, max];
    }
    function hsvToRgb(h, s, v) {
        var c = v * s, x = c * (1 - Math.abs((h / 60) % 2 - 1)), m = v - c;
        var r, g, b;
        if (h < 60) { r = c; g = x; b = 0; }
        else if (h < 120) { r = x; g = c; b = 0; }
        else if (h < 180) { r = 0; g = c; b = x; }
        else if (h < 240) { r = 0; g = x; b = c; }
        else if (h < 300) { r = x; g = 0; b = c; }
        else { r = c; g = 0; b = x; }
        return [(r + m) * 255, (g + m) * 255, (b + m) * 255];
    }

    /* ---------- وضعیت انتخابگر ---------- */
    var cp = { hue: 220, sat: 0.6, val: 0.85, hex: '#2563EB', target: null, theme: 'light', varName: '' };
    var svEl = document.getElementById('cp-sv');
    var hueEl = document.getElementById('cp-hue');
    if (!svEl) { return; }

    function setCpFromHex(hex) {
        var rgb = hexToRgb(hex);
        var hsv = rgbToHsv(rgb[0], rgb[1], rgb[2]);
        cp.hue = hsv[0]; cp.sat = hsv[1]; cp.val = hsv[2];
        cp.hex = hex.toUpperCase();
        syncCpUi();
    }
    function syncCpUi() {
        /* پس‌زمینه ناحیه SV = رنگ خالص hue */
        var pure = rgbToHex.apply(null, hsvToRgb(cp.hue, 1, 1));
        svEl.style.background = 'linear-gradient(to top, #000, transparent), linear-gradient(to right, #fff, ' + pure + ')';
        svEl.style.backgroundImage = 'linear-gradient(to top, #000, rgba(0,0,0,0)), linear-gradient(to right, #fff, ' + pure + ')';
        svEl.style.backgroundColor = pure;
        var dot = document.getElementById('cp-sv-dot');
        dot.style.left = (cp.sat * 100) + '%';
        dot.style.top = ((1 - cp.val) * 100) + '%';
        dot.style.background = cp.hex;
        var hd = document.getElementById('cp-hue-dot');
        hd.style.left = (cp.hue / 360 * 100) + '%';
        document.getElementById('cp-hex').value = cp.hex;
        var rgb = hexToRgb(cp.hex);
        document.getElementById('cp-rgb').value = rgb.join(', ');
        document.getElementById('cp-preview').style.background = cp.hex;
    }
    function updateHexFromHsv() {
        var rgb = hsvToRgb(cp.hue, cp.sat, cp.val);
        cp.hex = rgbToHex(rgb[0], rgb[1], rgb[2]);
        syncCpUi();
    }

    /* ---------- تعامل SV ---------- */
    function svPick(e) {
        var rect = svEl.getBoundingClientRect();
        cp.sat = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
        cp.val = Math.max(0, Math.min(1, 1 - (e.clientY - rect.top) / rect.height));
        updateHexFromHsv();
    }
    var svDragging = false;
    svEl.addEventListener('mousedown', function (e) { svDragging = true; svPick(e); });
    document.addEventListener('mousemove', function (e) { if (svDragging) { svPick(e); } });
    document.addEventListener('mouseup', function () { svDragging = false; });

    /* ---------- تعامل Hue ---------- */
    function huePick(e) {
        var rect = hueEl.getBoundingClientRect();
        cp.hue = Math.max(0, Math.min(360, (e.clientX - rect.left) / rect.width * 360));
        updateHexFromHsv();
    }
    var hueDragging = false;
    hueEl.addEventListener('mousedown', function (e) { hueDragging = true; huePick(e); });
    document.addEventListener('mousemove', function (e) { if (hueDragging) { huePick(e); } });
    document.addEventListener('mouseup', function () { hueDragging = false; });

    /* ---------- HEX دستی ---------- */
    document.getElementById('cp-hex').addEventListener('input', function () {
        var v = this.value.trim();
        if (/^#?[0-9a-fA-F]{6}$/.test(v)) { setCpFromHex(v[0] === '#' ? v : '#' + v); }
    });

    /* ---------- باز/بستن ---------- */
    window.closeColorPicker = function () {
        document.getElementById('cp-backdrop').classList.remove('show');
        cp.target = null;
    };
    window.applyColorPicker = function () {
        if (cp.target) {
            cp.target.value = cp.hex;
            /* به‌روزرسانی بصری سواچ و کد hex در لیست */
            var row = cp.target.closest('div[style*="display:flex"]');
            if (row) {
                var btn = row.querySelector('.cp-open');
                if (btn) { btn.style.background = cp.hex; }
                var code = row.querySelector('.cp-hex');
                if (code) { code.textContent = cp.hex; }
            }
            renderPalettePreview();
            if (window.sahandToast) { sahandToast({ message: 'رنگ «' + cp.varName + '» = ' + cp.hex, type: 'success' }); }
        }
        window.closeColorPicker();
    };

    /* دکمه‌های سواچ رنگ → باز کردن انتخابگر */
    document.querySelectorAll('.cp-open').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = this.getAttribute('data-target');
            cp.target = document.getElementById(targetId);
            if (!cp.target) { return; }
            cp.theme = this.getAttribute('data-theme') || 'light';
            cp.varName = this.getAttribute('data-var') || '';
            document.getElementById('cp-var-name').textContent = cp.varName;
            setCpFromHex(cp.target.value || '#2563EB');
            document.getElementById('cp-backdrop').classList.add('show');
        });
    });

    /* پیش‌فرض‌ها از لوگو */
    document.querySelectorAll('.cp-preset').forEach(function (b) {
        b.addEventListener('click', function () { setCpFromHex(this.getAttribute('data-color')); });
    });

    /* قطره‌چشم مرورگر */
    var edBtn = document.getElementById('cp-eyedropper');
    if (edBtn && window.EyeDropper) {
        edBtn.addEventListener('click', function () {
            new EyeDropper().open().then(function (res) {
                setCpFromHex(res.sRGBHex);
            }).catch(function () {});
        });
    } else if (edBtn) {
        edBtn.style.display = 'none';
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            window.closeColorPicker();
            window.closeBrandPagePreview && window.closeBrandPagePreview();
        }
    });

    /* ==================================================
     * 🖥 پیش‌نمایش زنده پالت — مینی‌سایت با رنگ‌های فعلی
     * ================================================== */
    function collectTheme(theme) {
        /* متغیرها از ردیف‌های همان تم خوانده می‌شوند (v2.25: مقادیر غیر hex مثل
           گرادیانت هم گرفته می‌شوند تا پیش‌نمایش «دقیقاً» با سایت برند یکی باشد) */
        var vars = {};
        var prefix = theme === 'light' ? 'light' : 'dark';
        var vIns = document.querySelectorAll('input[name="' + prefix + '_vars[]"]');
        var valIns = document.querySelectorAll('input[name="' + prefix + '_vals[]"]');
        vIns.forEach(function (vIn, idx) {
            var valIn = valIns[idx];
            if (valIn) {
                var val = String(valIn.value || '').trim();
                /* hex یا مقدار مرکب (گرادیانت/سایه) */
                if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(val) || /gradient\(/i.test(val) || /rgba?\(/i.test(val)) {
                    vars[vIn.value] = val;
                }
            }
        });
        return vars;
    }
    function findVar(vars, needles, fallback) {
        for (var i = 0; i < needles.length; i++) {
            for (var k in vars) {
                if (k.toLowerCase().indexOf(needles[i]) !== -1) { return vars[k]; }
            }
        }
        return fallback;
    }
    var previewTheme = 'light';
    window.switchPreviewTheme = function (t) {
        previewTheme = t;
        document.getElementById('pv-btn-light').classList.toggle('active', t === 'light');
        document.getElementById('pv-btn-dark').classList.toggle('active', t === 'dark');
        renderPalettePreview();
    };
    window.renderPalettePreview = function () {
        var box = document.getElementById('palette-live-preview');
        if (!box) { return; }
        var v = collectTheme(previewTheme);
        var primary = findVar(v, ['primary', 'accent', 'brand'], '#2563eb');
        var accent = findVar(v, ['accent', 'secondary'], '#f59e0b');
        var bg = findVar(v, ['background', 'bg', 'body'], '#f8fafc');
        var surface = findVar(v, ['surface', 'card'], '#ffffff');
        var text = findVar(v, ['text', 'color'], '#1e293b');
        var muted = findVar(v, ['text_light', 'muted', 'text_secondary'], '#64748b');
        var border = findVar(v, ['border', 'line'], '#e2e8f0');
        /* 🎨 v2.25 — دقیقاً مثل سایت برند: گرادیانت واقعی --gradient-primary
           (نه ترکیب primary+accent که باعث «فرق رنگ پیش‌نمایش با سایت» می‌شد)
           + متن دکمه از --on-primary (نه #fff هاردکد) */
        var gradient = v['--gradient-primary'] || ('linear-gradient(135deg,' + primary + ',' + (v['--color-secondary'] || accent) + ')');
        var btnText = v['--on-primary'] || '#ffffff';
        var heroText = v['--on-gradient'] || '#ffffff';
        var heroBtnBg = heroText === '#ffffff' ? '#ffffff' : '#0f172a';
        var heroBtnTx = v['--color-primary'] || primary;
        box.innerHTML =
        '<div style="background:' + bg + ';color:' + text + ';padding:18px;font-family:inherit;transition:background .2s">' +
          '<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:' + surface + ';border-radius:12px;border:1px solid ' + border + ';margin-bottom:14px">' +
            '<span style="font-weight:800">🏗️ ' + BE[1] + '</span>' +
            '<span style="background:' + primary + ';color:' + btnText + ';padding:6px 14px;border-radius:9px;font-size:12px;font-weight:700">ثبت درخواست</span>' +
          '</div>' +
          '<div style="background:' + gradient + ';border-radius:14px;padding:26px 18px;text-align:center;color:' + heroText + ';margin-bottom:14px">' +
            '<div style="font-size:16px;font-weight:800;margin-bottom:6px">تعمیرات تخصصی و سریع</div>' +
            '<div style="font-size:12px;opacity:.9;margin-bottom:12px">نمایندگی رسمی با قطعات اصلی</div>' +
            '<span style="background:' + heroBtnBg + ';color:' + heroBtnTx + ';padding:7px 16px;border-radius:9px;font-size:12px;font-weight:800">📞 تماس فوری</span>' +
          '</div>' +
          '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">' +
            '<div style="background:' + surface + ';border:1px solid ' + border + ';border-radius:11px;padding:13px;text-align:center"><div style="font-size:22px">🔧</div><div style="font-size:11.5px;font-weight:700;margin-top:5px">سرویس</div><div style="font-size:10px;color:' + muted + '">تخصصی</div></div>' +
            '<div style="background:' + surface + ';border:1px solid ' + border + ';border-radius:11px;padding:13px;text-align:center"><div style="font-size:22px">⚡</div><div style="font-size:11.5px;font-weight:700;margin-top:5px">سریع</div><div style="font-size:10px;color:' + muted + '">همان روز</div></div>' +
            '<div style="background:' + surface + ';border:1px solid ' + border + ';border-radius:11px;padding:13px;text-align:center"><div style="font-size:22px">🛡️</div><div style="font-size:11.5px;font-weight:700;margin-top:5px">ضمانت</div><div style="font-size:10px;color:' + muted + '">۶ ماه</div></div>' +
          '</div>' +
          '<div style="margin-top:12px;padding:11px 13px;background:' + surface + ';border:1px solid ' + border + ';border-radius:11px;font-size:11.5px;color:' + muted + ';line-height:1.9">این پیش‌نمایش، رنگ‌های «' + (previewTheme === 'light' ? 'تم روشن' : 'تم تاریک') + '» را با همان گرادیانت و رنگ متن دکمه‌ای که سایت برند استفاده می‌کند نشان می‌دهد. با تغییر هر رنگ در لیست بالا، این بخش بلافاصله به‌روز می‌شود.</div>' +
        '</div>';
    };    /* تغییر هر مقدار → پیش‌نمایش زنده (مقدارهای hex از طریق انتخابگر، بقیه دستی) */
    document.querySelectorAll('input[name$="_vals[]"]').forEach(function (inp) {
        inp.addEventListener('input', renderPalettePreview);
        inp.addEventListener('change', renderPalettePreview);
    });
    renderPalettePreview();

    /* ==================================================
     * 👁 پیش‌نمایش صفحه‌های برند (v2.9)
     * ================================================== */
    window.previewBrandPage = function (pageId, slug) {
        document.getElementById('pp-title').textContent = slug || ('صفحه #' + pageId);
        var frame = document.getElementById('page-preview-frame');
        frame.src = 'brand-page-preview.php?id=' + pageId;
        document.getElementById('page-preview-backdrop').classList.add('show');
    };
    window.closeBrandPagePreview = function () {
        document.getElementById('page-preview-backdrop').classList.remove('show');
        document.getElementById('page-preview-frame').src = 'about:blank';
    };
    window.setPpWidth = function (btn, w) {
        document.querySelectorAll('#page-preview-backdrop .device-tab').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        document.getElementById('page-preview-frame').style.maxWidth = w > 0 ? w + 'px' : '100%';
    };
})();
