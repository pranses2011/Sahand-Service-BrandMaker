/**
 * ⚡ اسکریپت اصلی سایت برند
 * ===========================
 * مسیریابی SPA ساده + شمارنده + اسکرول نرم + سال جاری
 * 🆕 v2.29: اسلایدر واقعی چندمقداری (sahand-slider) + انیمیشن ورود
 * عناصر با IntersectionObserver (blk-anim) + لمس موبایل اسلایدر
 */

/* 🔗 آدرس API ردیاب (تزریق در header) */
var TRACKER_URL = (typeof BRANDMAKER_API !== 'undefined' ? BRANDMAKER_API : '') + '/track';

/* ⏱️ v2.43 — شمارش معکوس زنده (درخواست کاربر: «تا وقتی صفحه رو رفرش نکردیم
   بروز نمیشوند»): هر عنصر .count-row[data-ts] هر ثانیه بازمحاسبه می‌شود.
   - ارقام فارسی + صفرِ پیشرو
   - پایان: ۰۰ ثانیه می‌ماند (بدون عدد منفی)
   - عدم همگامی ساعت سرور/کلاینت: مبنای محاسبه اختلافِ سرور (data-diff)
     در زمان رندر است، نه ساعت محلی — یعنی حتی با ساعت اشتباه کاربر،
     شمارش درست ادامه می‌یابد. */
(function () {
    function faDigits(n) {
        n = String(n);
        var fa = '';
        for (var i = 0; i < n.length; i++) {
            fa += '۰۱۲۳۴۵۶۷۸۹'[+n[i]] !== undefined ? '۰۱۲۳۴۵۶۷۸۹'[+n[i]] : n[i];
        }
        return fa;
    }
    function pad2(n) { return (n < 10 ? '0' : '') + n; }

    function tickCountdowns() {
        var rows = document.querySelectorAll('.count-row[data-ts]');
        if (!rows.length) { return; }
        var clientNow = Math.floor(Date.now() / 1000);
        rows.forEach(function (row) {
            var ts = parseInt(row.getAttribute('data-ts'), 10);
            if (!ts) { return; }
            /* 🕐 خنثی‌سازی انحراف ساعت کلاینت: انحراف = clientNow - data-now
               «یک‌بار» در اولین تیک کپسوله می‌شود (data-skew) — اگر هر تیک
               با clientNow لحظه‌ای بازمحاسبه شود، گذر زمان از هر دو طرف
               کم می‌شود و اختلاف هرگز تغییر نمی‌کند (شمارش فریز می‌شود). */
            var skewAttr = row.getAttribute('data-skew');
            if (skewAttr === null || skewAttr === '') {
                var srvRender = parseInt(row.getAttribute('data-now'), 10) || 0;
                skewAttr = String(srvRender > 0 ? (clientNow - srvRender) : 0);
                row.setAttribute('data-skew', skewAttr);
            }
            var skew = parseInt(skewAttr, 10) || 0;
            /* سرورِ «الان» ≈ ساعت کلاینت منهای انحراف → باقی‌مانده واقعی */
            var diff = ts - (clientNow - skew);
            if (diff < 0) { diff = 0; }
            var d = Math.floor(diff / 86400),
                h = Math.floor((diff % 86400) / 3600),
                m = Math.floor((diff % 3600) / 60),
                s = diff % 60;
            var bd = row.querySelector('.cd-d'), bh = row.querySelector('.cd-h'),
                bm = row.querySelector('.cd-m'), bs = row.querySelector('.cd-s');
            if (bd) { bd.textContent = faDigits(d); }
            if (bh) { bh.textContent = faDigits(pad2(h)); }
            if (bm) { bm.textContent = faDigits(pad2(m)); }
            if (bs) { bs.textContent = faDigits(pad2(s)); }
        });
    }

    tickCountdowns();
    setInterval(tickCountdowns, 1000);
    /* بروزرسانی فوری پس از سوئیچ تب (setInterval در تب پس‌زمینه معلق می‌شود) */
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { tickCountdowns(); }
    });
})();

document.addEventListener('DOMContentLoaded', function () {

    /* 🔢 شمارنده‌های آماری (در بلوک counter-stats) */
    var counters = document.querySelectorAll('[data-counter]');
    counters.forEach(function (el) {
        var target = parseInt(el.dataset.counter, 10) || 0;
        var current = 0;
        var step = Math.max(1, Math.round(target / 40));
        var timer = setInterval(function () {
            current += step;
            if (current >= target) { current = target; clearInterval(timer); }
            el.textContent = new Intl.NumberFormat('fa-IR').format(current);
        }, 35);
    });

    /* 🎞 v2.29 — اسلایدرهای چندمقداری (hero-slider / universal-slider)
       اسلاید واقعی: دکمه‌های ‹ › + نقطه‌ها + پخش خودکار + لمس/کشیدن */
    document.querySelectorAll('.sahand-slider').forEach(function (slider) {
        var track = slider.querySelector('.ss-track');
        if (!track) { return; }
        var slides = track.children;
        var n = slides.length;
        if (n < 1) { return; }
        var idx = 0;
        var dotsWrap = slider.querySelector('.ss-dots');
        var dots = dotsWrap ? dotsWrap.querySelectorAll('span') : [];
        var auto = slider.dataset.autoplay === '1';
        var timer2 = null;
        var isRTL = (document.documentElement.dir || 'rtl') !== 'ltr';

        function go(i) {
            idx = (i + n) % n;
            var off = idx * 100;
            track.style.transform = 'translateX(' + (isRTL ? off : -off) + '%)';
            if (dotsWrap) {
                for (var d = 0; d < dots.length; d++) {
                    dots[d].textContent = d === idx ? '●' : '○';
                    dots[d].style.opacity = d === idx ? '1' : '.55';
                }
            }
        }
        var prev = slider.querySelector('.ss-prev');
        var next = slider.querySelector('.ss-next');
        if (prev) { prev.addEventListener('click', function () { go(idx - 1); restart(); }); }
        if (next) { next.addEventListener('click', function () { go(idx + 1); restart(); }); }
        if (dotsWrap) {
            dotsWrap.addEventListener('click', function (e) {
                if (e.target.tagName === 'SPAN') {
                    var arr = Array.prototype.slice.call(dots);
                    go(arr.indexOf(e.target));
                    restart();
                }
            });
        }
        /* 👆👇 لمس/کشیدن در موبایل */
        var touchX = null;
        track.addEventListener('touchstart', function (e) { touchX = e.touches[0].clientX; }, { passive: true });
        track.addEventListener('touchend', function (e) {
            if (touchX === null) { return; }
            var dx = e.changedTouches[0].clientX - touchX;
            if (Math.abs(dx) > 42) { go(dx > 0 ? idx - 1 : idx + 1); restart(); }
            touchX = null;
        }, { passive: true });
        function restart() {
            if (timer2) { clearInterval(timer2); }
            if (auto && n > 1) { timer2 = setInterval(function () { go(idx + 1); }, 4500); }
        }
        go(0);
        restart();
    });

    /* 🎬 v2.29 — انیمیشن ورود عناصر (blk-anim): با اسکرول فعال می‌شود.
       اگر IntersectionObserver نبود، فوراً نمایش داده می‌شوند (no-js). */
    var animEls = document.querySelectorAll('.blk-anim');
    if (animEls.length) {
        if ('IntersectionObserver' in window) {
            var animObs = new IntersectionObserver(function (entries) {
                entries.forEach(function (en) {
                    if (en.isIntersecting) {
                        en.target.classList.add('in-view');
                        animObs.unobserve(en.target);
                    }
                });
            }, { threshold: 0.12, rootMargin: '0px 0px -36px 0px' });
            animEls.forEach(function (el) { animObs.observe(el); });
        } else {
            animEls.forEach(function (el) { el.classList.add('no-js'); });
        }
    }

    /* 📅 بروزرسانی سال شمسی فوتر */
    var yearEl = document.querySelector('.footer-year');
    if (yearEl) {
        yearEl.textContent = new Intl.DateTimeFormat('fa-IR', { year: 'numeric' }).format(new Date());
    }

    /* 🖼️ بارگذاری تنبل تصاویر بدون loading=lazy */
    document.querySelectorAll('img:not([loading])').forEach(function (img) {
        img.loading = 'lazy';
    });

    /* ☰ بستن منوی موبایل با کلیک بیرون */
    document.addEventListener('click', function (e) {
        var nav = document.querySelector('.main-nav ul');
        if (nav && nav.classList.contains('open') && !e.target.closest('.main-nav')) {
            closeNav();
        }
    });

    /* ☰ بستن منوی موبایل بعد از کلیک روی هر لینک */
    var navList = document.getElementById('mainNavList');
    if (navList) {
        navList.addEventListener('click', function (e) {
            if (e.target.closest('a')) { closeNav(); }
        });
    }
});

/* ☰ باز/بسته کردن منوی موبایل */
function toggleNav(btn) {
    var nav = document.getElementById('mainNavList');
    if (!nav) { return; }
    if (nav.classList.contains('open')) {
        closeNav();
    } else {
        nav.classList.add('open');
        if (btn) { btn.setAttribute('aria-expanded', 'true'); }
    }
}

/* ✖ بستن منوی موبایل */
function closeNav() {
    var nav = document.getElementById('mainNavList');
    var btn = document.querySelector('.nav-toggle');
    if (nav) { nav.classList.remove('open'); }
    if (btn) { btn.setAttribute('aria-expanded', 'false'); }
}

/* ⌨️ بستن منو با Escape */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeNav(); }
});

/* 🔄 نمایش وضعیت آفلاین */
window.addEventListener('offline', function () {
    var banner = document.createElement('div');
    banner.textContent = '📴 اتصال اینترنت قطع است';
    banner.style.cssText = 'position:fixed;top:0;inset-inline:0;background:#dc2626;color:#fff;text-align:center;padding:7px;z-index:999;font-size:12.5px;font-family:Vazirmatn,Tahoma';
    document.body.appendChild(banner);
    window.addEventListener('online', function () { banner.remove(); }, { once: true });
});
