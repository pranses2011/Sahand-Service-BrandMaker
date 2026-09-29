/* ============================================================
 * 🗓️ تقویم انتخاب تاریخ شمسی (جلالی) — v2.42
 * ============================================================
 * کامپوننت مستقل بدون هیچ وابستگی خارجی (CDN/کتابخانه) — مناسب
 * هاست اشتراکی ایرانی. الگوریتم تبدیل = jalaali استاندارد (سازگار
 * با توابع PHP سایت‌ساز: gregorian_to_jalali / jalali_to_gregorian).
 *
 * استفاده:
 *   <input type="text" name="preferred_date" data-jalali-picker>
 * کامپوننت به‌طور خودکار:
 *   ① input اصلی readonly می‌شود و تاریخ شمسی فارسی نمایش می‌دهد
 *   ② یک hidden input هم‌نام، مقدار میلادی YYYY-MM-DD را نگه می‌دارد
 *      (سازگار با ذخیره‌سازی و نمایش jdate پنل)
 *   ③ تقویم گرافیکی زیبا با ناوبری ماه/سال، دکمه امروز و پاک کردن
 *
 * 🆕 v2.42 — رفع دو باگ ریشه‌ای (بازتولیدشده با تست Playwright):
 *   ① «دوبار کلیک برای باز شدن»: رویداد focus تقویم را باز می‌کرد و
 *      clickِ همان تپ، بلافاصله toggle آن را می‌بست → گارد زمانی ۴۰۰ms
 *   ② «کلیک روی تغییر ماه تقویم را می‌بندد»: هنگام رندر مجدد، دکمه
 *      کلیک‌شده از DOM جدا می‌شود و wrap.contains(target) در هندلر
 *      clickِ سند false می‌شد → تشخیص بیرون‌کلیک به pointerdown منتقل
 *      شد (قبل از هر رندر مجدد اجرا می‌شود؛ عنصر هنوز متصل است)
 *   ③ بزرگ‌سازی کامل (عرض ۳۴۸px، فونت روز ۱۴.۵px، تیتر ۱۵px) —
 *      «اعداد و نوشته‌ها خوب دیده نمی‌شدند»
 *
 * @package SahandBrandSite
 */
(function () {
    'use strict';

    /* ---------- 🔢 الگوریتم تبدیل جلالی ↔ میلادی (jalaali استاندارد) ---------- */
    function div(a, b) { return ~~(a / b); }
    function mod(a, b) { return a - ~~(a / b) * b; }

    function jalCal(jy) {
        var breaks = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
        var bl = breaks.length, gy = jy + 621, leapJ = -14, jp = breaks[0], jm, jump = 0, leap, n, i;
        for (i = 1; i < bl; i += 1) {
            jm = breaks[i];
            jump = jm - jp;
            if (jy < jm) break;
            leapJ = leapJ + div(jump, 33) * 8 + div(mod(jump, 33), 4);
            jp = jm;
        }
        n = jy - jp;
        leapJ = leapJ + div(n, 33) * 8 + div(mod(n, 33) + 3, 4);
        if (mod(jump, 33) === 4 && jump - n === 4) leapJ += 1;
        var leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150;
        var march = 20 + leapJ - leapG;
        if (jump - n < 6) n = n - jump + div(jump + 4, 33) * 33;
        leap = mod(mod(n + 1, 33) - 1, 4);
        if (leap === -1) leap = 4;
        return { leap: leap, gy: gy, march: march };
    }
    function g2d(gy, gm, gd) {
        var d = div((gy + div(gm - 8, 6) + 100100) * 1461, 4) + div(153 * mod(gm + 9, 12) + 2, 5) + gd - 34840408;
        d = d - div(div(gy + 100100 + div(gm - 8, 6), 100) * 3, 4) + 752;
        return d;
    }
    function d2g(jdn) {
        var j = 4 * jdn + 139361631;
        j = j + div(div(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
        var i = div(mod(j, 1461), 4) * 5 + 308;
        var gd = div(mod(i, 153), 5) + 1;
        var gm = mod(div(i, 153), 12) + 1;
        var gy = div(j, 1461) - 100100 + div(8 - gm, 6);
        return { gy: gy, gm: gm, gd: gd };
    }
    function j2d(jy, jm, jd) {
        var r = jalCal(jy);
        return g2d(r.gy, 3, r.march) + (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1;
    }
    function d2j(jdn) {
        var gy = d2g(jdn).gy, jy = gy - 621, r = jalCal(jy), jdn1f = g2d(gy, 3, r.march), k;
        k = jdn - jdn1f;
        if (k >= 0) {
            if (k <= 185) return { jy: jy, jm: 1 + div(k, 31), jd: mod(k, 31) + 1 };
            k -= 186;
        } else {
            jy -= 1;
            k += 179;
            /* ⚠️ leap از سال «قبل از کاهش» (r) خوانده می‌شود — الگوی مرجع
               jalaali-js: r.leap===1 یعنی سال جدید کبیسه است و 179 باید
               یک روز بیشتر شود (رفع شیفت یک‌روزه دی/بهمن سال‌های کبیسه) */
            if (r.leap === 1) k += 1;
        }
        return { jy: jy, jm: 7 + div(k, 30), jd: mod(k, 30) + 1 };
    }
    function isLeapJ(jy) { return jalCal(jy).leap === 0; }
    function jMonthLen(jy, jm) {
        if (jm <= 6) return 31;
        if (jm <= 11) return 30;
        return isLeapJ(jy) ? 30 : 29;
    }

    /* ---------- 🏷️ واژه‌نامه فارسی ---------- */
    var MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    var WEEKDAYS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
    var WEEKDAYS_FULL = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
    /* ایندکس روز هفته جلالی: jdn mod 7 → 0=شنبه (خروجی استاندارد jalaali: 5 دی ۱۳۷۷ = شنبه) */
    function jWeekDay(jy, jm, jd) {
        var g = d2g(j2d(jy, jm, jd));
        var jsDate = new Date(g.gy, g.gm - 1, g.gd);
        return (jsDate.getDay() + 1) % 7; /* شنبه=۰ */
    }
    function toFa(n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); }
    function pad2(n) { return n < 10 ? '0' + n : '' + n; }

    /* ---------- 🎨 استایل (یکبار تزریق) — v2.42: بزرگ‌تر و خواناتر ---------- */
    var CSS = [
        '.jdp-input-wrap{position:relative;display:inline-flex;align-items:center;flex:1;min-width:0}',
        '.jdp-input-wrap .jdp-text{width:100%;padding-inline-end:42px;cursor:pointer;background:#fff}',
        '.jdp-input-wrap .jdp-cal-ico{position:absolute;inset-inline-end:11px;pointer-events:none;font-size:19px;opacity:.8}',
        '.jdp-pop{position:absolute;z-index:9999;top:calc(100% + 8px);inset-inline-start:0;background:#fff;border:1px solid #e2e8f0;border-radius:17px;box-shadow:0 18px 54px rgba(2,8,23,.2),0 3px 10px rgba(2,8,23,.08);width:352px;padding:16px 16px 12px;display:none;direction:rtl;font-family:inherit;animation:jdpIn .16s ease}',
        '.jdp-pop.open{display:block}',
        '@keyframes jdpIn{from{opacity:0;transform:translateY(-7px) scale(.985)}to{opacity:1;transform:none}}',
        '.jdp-head{display:flex;align-items:center;justify-content:space-between;gap:7px;margin-bottom:11px}',
        '.jdp-title{flex:1;text-align:center;font-size:15.5px;font-weight:800;color:#0f172a;cursor:pointer;padding:6px 6px;border-radius:9px;transition:background .14s}',
        '.jdp-title:hover{background:#f1f5f9}',
        '.jdp-nav{width:36px;height:36px;border:1px solid #e2e8f0;background:#fff;border-radius:10px;cursor:pointer;font-size:18px;color:#334155;display:inline-flex;align-items:center;justify-content:center;transition:all .14s;flex:none;line-height:1}',
        '.jdp-nav:hover{border-color:#93c5fd;background:#eff6ff;color:#1e40af}',
        '.jdp-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px}',
        '.jdp-wd{text-align:center;font-size:12.5px;font-weight:800;color:#94a3b8;padding:5px 0}',
        '.jdp-wd.jdp-fr{color:#f87171}',
        '.jdp-day{aspect-ratio:1;border:none;background:transparent;border-radius:10px;font-family:inherit;font-size:14.5px;font-weight:600;color:#0f172a;cursor:pointer;transition:all .12s;padding:0}',
        '.jdp-day:hover:not(:disabled){background:#eff6ff;color:#1e40af;transform:scale(1.08)}',
        '.jdp-day:disabled{color:#cbd5e1;cursor:default}',
        '.jdp-day.other{visibility:hidden}',
        '.jdp-day.today{color:#1e40af;font-weight:900;box-shadow:inset 0 0 0 1.8px #93c5fd}',
        '.jdp-day.fri{color:#ef4444}',
        '.jdp-day.sel{background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff;font-weight:800;box-shadow:0 3px 11px rgba(30,64,175,.34)}',
        '.jdp-foot{display:flex;align-items:center;justify-content:space-between;gap:7px;margin-top:11px;padding-top:11px;border-top:1px dashed #e2e8f0}',
        '.jdp-today-btn{border:1px solid #bbf7d0;background:#f0fdf4;color:#15803d;font-family:inherit;font-size:12.5px;font-weight:700;border-radius:10px;padding:8px 14px;cursor:pointer;transition:all .13s}',
        '.jdp-today-btn:hover{background:#dcfce7}',
        '.jdp-clear-btn{border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;font-family:inherit;font-size:12.5px;font-weight:700;border-radius:10px;padding:8px 14px;cursor:pointer;transition:all .13s}',
        '.jdp-clear-btn:hover{background:#fee2e2}',
        '.jdp-yp{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:8px}',
        '.jdp-yp button{border:1px solid #e2e8f0;background:#fff;border-radius:10px;font-family:inherit;font-size:14px;font-weight:700;color:#0f172a;padding:10px 2px;cursor:pointer;transition:all .12s}',
        '.jdp-yp button:hover{border-color:#93c5fd;background:#eff6ff;color:#1e40af}',
        '.jdp-yp button.cur{background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff;border-color:transparent}',
        '.jdp-mp{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}',
        '.jdp-mp button{border:1px solid #e2e8f0;background:#fff;border-radius:10px;font-family:inherit;font-size:13.5px;font-weight:700;color:#0f172a;padding:11px 2px;cursor:pointer;transition:all .12s}',
        '.jdp-mp button:hover{border-color:#93c5fd;background:#eff6ff;color:#1e40af}',
        '.jdp-mp button.cur{background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff;border-color:transparent}',
        '@media (max-width:480px){.jdp-pop{width:min(352px,calc(100vw - 26px))}}'
    ].join('');
    if (!document.getElementById('jdp-style')) {
        var st = document.createElement('style');
        st.id = 'jdp-style';
        st.textContent = CSS;
        document.head.appendChild(st);
    }

    /* ---------- 🔧 سازنده کامپوننت ---------- */
    var openPicker = null; /* فقط یک تقویم باز */

    function attachPicker(input) {
        if (input.dataset.jdpReady === '1') { return; }
        input.dataset.jdpReady = '1';

        var name = input.name || '';
        var form = input.closest('form');
        var hidden = null;
        if (name !== '') {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = name;
            hidden.dataset.jdpHidden = '1';
            input.name = name + '_display';
            input.parentNode.insertBefore(hidden, input);
        }

        input.readOnly = true;
        input.dataset.jdpRole = 'text';
        input.autocomplete = 'off';
        input.style.cursor = 'pointer';
        input.placeholder = input.placeholder || 'انتخاب تاریخ (شمسی)';

        var wrap = document.createElement('span');
        wrap.className = 'jdp-input-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        var ico = document.createElement('span');
        ico.className = 'jdp-cal-ico';
        ico.textContent = '🗓️';
        wrap.appendChild(ico);

        var pop = document.createElement('div');
        pop.className = 'jdp-pop';
        wrap.appendChild(pop);

        var state = { jy: 0, jm: 0, view: 'days', yearPage: 0 };
        var selected = null; /* {jy,jm,jd} */

        /* مقدار اولیه از hidden (میلادی) */
        if (hidden && hidden.value && /^\d{4}-\d{2}-\d{2}$/.test(hidden.value)) {
            var p = hidden.value.split('-');
            var gDate = new Date(+p[0], +p[1] - 1, +p[2]);
            var j = d2j(g2d(gDate.getFullYear(), gDate.getMonth() + 1, gDate.getDate()));
            selected = j;
            input.value = toFa(j.jy + '/' + pad2(j.jm) + '/' + pad2(j.jd));
        }

        function todayJ() {
            var t = new Date();
            return d2j(g2d(t.getFullYear(), t.getMonth() + 1, t.getDate()));
        }

        var openedAt = 0; /* ⏱️ v2.42 — زمان آخرین باز شدن (گارد مسابقه focus/click) */
        function close() { pop.classList.remove('open'); openPicker = null; }

        function selectDate(jy, jm, jd) {
            selected = { jy: jy, jm: jm, jd: jd };
            input.value = toFa(jy + '/' + pad2(jm) + '/' + pad2(jd));
            if (hidden) {
                var g = d2g(j2d(jy, jm, jd));
                hidden.value = g.gy + '-' + pad2(g.gm) + '-' + pad2(g.gd);
            }
            input.dispatchEvent(new Event('change', { bubbles: true }));
            close();
        }

        /* ---------- نمای روزها ---------- */
        function renderDays() {
            state.view = 'days';
            if (!state.jy) { var tj = todayJ(); state.jy = selected ? selected.jy : tj.jy; state.jm = selected ? selected.jm : tj.jm; }
            var y = state.jy, m = state.jm;
            var firstWd = jWeekDay(y, m, 1);
            var len = jMonthLen(y, m);
            var tJ = todayJ();
            var html = '<div class="jdp-head">'
                + '<button type="button" class="jdp-nav" data-act="prev" title="ماه قبل">›</button>'
                + '<button type="button" class="jdp-title" data-act="title">' + MONTHS[m - 1] + ' ' + toFa(y) + '</button>'
                + '<button type="button" class="jdp-nav" data-act="next" title="ماه بعد">‹</button>'
                + '</div><div class="jdp-grid">';
            for (var w = 0; w < 7; w++) {
                html += '<span class="jdp-wd' + (w === 6 ? ' jdp-fr' : '') + '">' + WEEKDAYS[w] + '</span>';
            }
            for (var b = 0; b < firstWd; b++) { html += '<span class="jdp-day other"></span>'; }
            for (var d = 1; d <= len; d++) {
                var cls = 'jdp-day';
                var wd = (firstWd + d - 1) % 7;
                if (wd === 6) { cls += ' fri'; }
                if (tJ.jy === y && tJ.jm === m && tJ.jd === d) { cls += ' today'; }
                if (selected && selected.jy === y && selected.jm === m && selected.jd === d) { cls += ' sel'; }
                html += '<button type="button" class="' + cls + '" data-d="' + d + '">' + toFa(d) + '</button>';
            }
            html += '</div><div class="jdp-foot">'
                + '<button type="button" class="jdp-today-btn" data-act="today">📍 امروز</button>'
                + '<button type="button" class="jdp-clear-btn" data-act="clear">✕ پاک کردن</button>'
                + '</div>';
            pop.innerHTML = html;
        }

        /* ---------- نمای انتخاب ماه ---------- */
        function renderMonths() {
            state.view = 'months';
            var html = '<div class="jdp-head">'
                + '<button type="button" class="jdp-nav" data-act="back">›</button>'
                + '<button type="button" class="jdp-title">' + 'انتخاب ماه — ' + toFa(state.jy) + '</button>'
                + '<span class="jdp-nav" style="visibility:hidden">‹</span>'
                + '</div><div class="jdp-mp">';
            for (var m = 1; m <= 12; m++) {
                var cur = (selected && selected.jy === state.jy && selected.jm === m) ? ' cur' : '';
                html += '<button type="button" class="jdp-mp-btn' + cur + '" data-m="' + m + '">' + MONTHS[m - 1] + '</button>';
            }
            html += '</div>';
            pop.innerHTML = html;
        }

        /* ---------- نمای انتخاب سال ---------- */
        function renderYears() {
            state.view = 'years';
            var tJ = todayJ();
            var start = state.yearPage || (Math.floor((selected ? selected.jy : tJ.jy) / 12) * 12);
            state.yearPage = start;
            var html = '<div class="jdp-head">'
                + '<button type="button" class="jdp-nav" data-act="yprev" title="۱۲ سال قبل">›</button>'
                + '<button type="button" class="jdp-title">' + toFa(start) + ' تا ' + toFa(start + 11) + '</button>'
                + '<button type="button" class="jdp-nav" data-act="ynext" title="۱۲ سال بعد">‹</button>'
                + '</div><div class="jdp-yp">';
            for (var y = start; y < start + 12; y++) {
                var cur = (selected && selected.jy === y) ? ' cur' : '';
                html += '<button type="button" class="jdp-yp-btn' + cur + '" data-y="' + y + '">' + toFa(y) + '</button>';
            }
            html += '</div>';
            pop.innerHTML = html;
        }

        pop.addEventListener('click', function (ev) {
            var btn = ev.target.closest('button');
            if (!btn) { return; }
            var act = btn.dataset.act;
            if (act === 'prev') { state.jm--; if (state.jm < 1) { state.jm = 12; state.jy--; } renderDays(); }
            else if (act === 'next') { state.jm++; if (state.jm > 12) { state.jm = 1; state.jy++; } renderDays(); }
            else if (act === 'title') { renderMonths(); }
            else if (act === 'back') { renderDays(); }
            else if (act === 'yprev') { state.yearPage -= 12; renderYears(); }
            else if (act === 'ynext') { state.yearPage += 12; renderYears(); }
            else if (act === 'today') { var t2 = todayJ(); selectDate(t2.jy, t2.jm, t2.jd); }
            else if (act === 'clear') { selected = null; input.value = ''; if (hidden) { hidden.value = ''; } input.dispatchEvent(new Event('change', { bubbles: true })); close(); }
            else if (btn.dataset.d) { selectDate(state.jy, state.jm, +btn.dataset.d); }
            else if (btn.dataset.m) { state.jm = +btn.dataset.m; renderDays(); }
            else if (btn.dataset.y) { state.jy = +btn.dataset.y; renderMonths(); }
        });

        /* 🖐️ v2.42 — جلوگیری از دزدیده‌شدن فوکوس توسط دکمه‌های تقویم:
           بدون این، کلیک ناوبری ماه، فوکوس input را می‌گیرد و در موبایل
           باعث پرش/اسکرول ناخواسته می‌شود (روی click اثری ندارد). */
        pop.addEventListener('mousedown', function (ev) {
            if (ev.target.closest('button')) { ev.preventDefault(); }
        });

        function open() {
            if (openPicker && openPicker !== close) { openPicker(); }
            renderDays();
            pop.classList.add('open');
            openPicker = close;
            openedAt = Date.now(); /* ⏱️ گارد مسابقه focus/click */
        }

        /* ⌨️ دسترسی صفحه‌کلید: باز شدن با Tab (focus) — بدون toggle-close
           تداخلی؛ کلیکِ همان تپ بلافاصله بعد از focus نادیده گرفته می‌شود. */
        input.addEventListener('focus', open);

        input.addEventListener('click', function () {
            if (pop.classList.contains('open')) {
                /* فقط اگر تقویم از قبل (بیش از ۴۰۰ms) باز بوده ببند —
                   کلیکِ بلافاصله بعد از focus-open را نادیده بگیر */
                if (Date.now() - openedAt > 400) { close(); }
            } else {
                open();
            }
        });

        /* 🚪 v2.42 — بستن با کلیک بیرون روی pointerdown (نه click!):
           ریشه «کلیک روی تغییر ماه تقویم را می‌بندد»: هندلر click سند
           بعد از رندر مجدد اجرا می‌شد؛ دکمه کلیک‌شده از DOM جدا شده بود
           و wrap.contains(target) → false → بسته شدن بی‌دلیل!
           pointerdown همیشه قبل از رندر مجدد اجرا می‌شود؛ عنصر هنوز
           متصل است و تشخیص داخل/بیرون دقیق است. */
        var outsideEvt = ('onpointerdown' in window) ? 'pointerdown' : 'mousedown';
        document.addEventListener(outsideEvt, function (ev) {
            if (pop.classList.contains('open') && !wrap.contains(ev.target)) { close(); }
        });

        /* ⌨️ بستن با Escape */
        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && pop.classList.contains('open')) {
                ev.stopPropagation();
                close();
            }
        });
    }

    /* ---------- 🚀 راه‌اندازی خودکار ---------- */
    function initAll() {
        document.querySelectorAll('input[data-jalali-picker]').forEach(attachPicker);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
    /* برای فرم‌های داینامیک (بلوک‌های قالب‌ساز که بعداً رندر می‌شوند) */
    window.JalaliPicker = { attach: attachPicker, initAll: initAll };
})();
