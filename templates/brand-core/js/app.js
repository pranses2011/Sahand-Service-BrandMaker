/**
 * ⚡ اسکریپت اصلی سایت برند
 * ===========================
 * مسیریابی SPA ساده + شمارنده + اسکرول نرم + سال جاری
 * 🆕 v2.29: اسلایدر واقعی چندمقداری (sahand-slider) + انیمیشن ورود
 * عناصر با IntersectionObserver (blk-anim) + لمس موبایل اسلایدر
 */

/* 🔗 آدرس API ردیاب (تزریق در header) */
var TRACKER_URL = (typeof BRANDMAKER_API !== 'undefined' ? BRANDMAKER_API : '') + '/track';

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
