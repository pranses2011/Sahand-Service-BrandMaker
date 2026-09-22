/**
 * ⚡ اسکریپت اصلی سایت برند
 * ===========================
 * مسیریابی SPA ساده + شمارنده + اسکرول نرم + سال جاری
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
            nav.classList.remove('open');
        }
    });
});

/* 🔄 نمایش وضعیت آفلاین */
window.addEventListener('offline', function () {
    var banner = document.createElement('div');
    banner.textContent = '📴 اتصال اینترنت قطع است';
    banner.style.cssText = 'position:fixed;top:0;inset-inline:0;background:#dc2626;color:#fff;text-align:center;padding:7px;z-index:999;font-size:12.5px;font-family:Vazirmatn,Tahoma';
    document.body.appendChild(banner);
    window.addEventListener('online', function () { banner.remove(); }, { once: true });
});
