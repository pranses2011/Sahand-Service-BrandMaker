/**
 * 📱 Service Worker سایت برند (P3 — v2.37)
 * ==========================================
 * نقشه راه P3 گزارش تحلیل جامع: «PWA واقعی» —
 * قبلاً فقط manifest.json حداقلی بود (بدون SW = بدون آفلاین، بدون کش).
 *
 * استراتژی کش (سه‌گانه — مطابق توصیه web.dev برای سایت‌های محتوایی):
 *   ① precache — صفحه آفلاین + اسکریپت/استایل اصلی هنگام نصب
 *   ② static  — cache-first (css/js/font/icon هم‌دامنه — بازدید بعدی آنی)
 *   ③ pages   — network-first با fallback آفلاین (محتوا همیشه تازه اگر شبکه هست)
 *
 * 🧹 چرخه عمر: cache-busting با CACHE_VERSION — با تغییر نسخه، کش قدیمی
 *    پاک می‌شود (activate) و skipWaiting + clientsClaim بلافاصله فعالش می‌کند.
 * 🚫 اسکوپ: فقط همان دامنه سایت برند — API سایت‌ساز (دامنه دیگر) کش نمی‌شود.
 */

const CACHE_VERSION = 'brand-v1.3';
const STATIC_CACHE = CACHE_VERSION + '-static';
const PAGES_CACHE = CACHE_VERSION + '-pages';

/* 📦 منابع پیش‌کش — هسته قابل‌اتکا آفلاین */
const PRECACHE_URLS = [
    '/offline.html',
    '/css/style.css',
    '/js/app.js',
];

/* 💿 نصب — precache + فعال‌سازی فوری */
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => Promise.allSettled(PRECACHE_URLS.map((url) => cache.add(url))))
            .then(() => self.skipWaiting())
    );
});

/* 🧹 فعال‌سازی — حذف کش‌های نسخه‌های قدیمی */
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => !key.startsWith(CACHE_VERSION))
                    .map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

/* 🌐 fetch — مسیریابی بر اساس نوع منبع */
self.addEventListener('fetch', (event) => {
    const request = event.request;

    /* فقط GET هم‌مبدأ — API/تصاویر بین‌مبدأ و POST هرگز کش نمی‌شوند */
    if (request.method !== 'GET') { return; }
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) { return; }

    /* 📄 صفحات HTML — network-first + fallback آفلاین */
    if (request.mode === 'navigate' || (request.headers.get('accept') || '').includes('text/html')) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    const copy = response.clone();
                    caches.open(PAGES_CACHE).then((cache) => cache.put(request, copy)).catch(() => {});
                    return response;
                })
                .catch(() =>
                    caches.match(request).then((cached) => cached || caches.match('/offline.html'))
                )
        );
        return;
    }

    /* 🖼 static — cache-first با به‌روزرسانی در پس‌زمینه (stale-while-revalidate) */
    event.respondWith(
        caches.match(request).then((cached) => {
            const fetchPromise = fetch(request)
                .then((response) => {
                    if (response && response.status === 200 && response.type === 'basic') {
                        const copy = response.clone();
                        caches.open(STATIC_CACHE).then((cache) => cache.put(request, copy)).catch(() => {});
                    }
                    return response;
                })
                .catch(() => cached);
            return cached || fetchPromise;
        })
    );
});
