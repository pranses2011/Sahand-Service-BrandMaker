/**
 * 🧭 ردیاب بازدید داخلی — بدون هیچ سرویس خارجی
 * ==============================================
 * اطلاعات: صفحه، رفرر، رزولوشن، زبان، مدت حضور
 * ارسال به: BRANDMAKER_API/track
 */
(function () {
    /* 🛡 v2.26 — گارد دفاعی: اگر صفحه‌ای footer جدید را نداشته باشد
       (استقرار قدیمی)، به‌جای ReferenceError بی‌صدا خاموش می‌شویم.
       مقادیر توسط footer.php پیش از این فایل تزریق می‌شوند:
       window.TRACKER_URL (آدرس کامل /api/track) و window.BRAND_ID */
    var target = (typeof TRACKER_URL !== 'undefined' && TRACKER_URL) ? TRACKER_URL : '';
    var brandId = (typeof BRAND_ID !== 'undefined' && BRAND_ID) ? (parseInt(BRAND_ID, 10) || 0) : 0;
    if (!target) { return; }

    // 🔑 شناسه نشست (یکتا برای هر بازدیدکننده در روز)
    var KEY = 'brand_session';
    var today = new Date().toISOString().slice(0, 10);
    var session = localStorage.getItem(KEY);
    var sessionDate = localStorage.getItem(KEY + '_date');

    if (!session || sessionDate !== today) {
        var arr = new Uint8Array(16);
        (window.crypto || {}).getRandomValues ? crypto.getRandomValues(arr) : arr.forEach(function (v, i) { arr[i] = Math.random() * 256; });
        session = Array.from(arr).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('');
        localStorage.setItem(KEY, session);
        localStorage.setItem(KEY + '_date', today);
    }

    var startTime = Date.now();
    var pageUrl = location.pathname + location.search;

    /* 📤 ارسال بازدید صفحه
       🛡 v2.26: نوع Blob عمداً text/plain است (نه application/json) —
       درخواست «ساده» می‌شود و مرورگر هیچ preflight CORS نمی‌فرستد؛
       سمت سرور Router::jsonInput بدنه را مستقل از Content-Type می‌خواند.
       پشتیبان: fetch با keepalive برای مرورگرهای بدون sendBeacon. */
    function beacon(payload) {
        var body = JSON.stringify(payload);
        if (navigator.sendBeacon) {
            try {
                return navigator.sendBeacon(target, new Blob([body], { type: 'text/plain;charset=UTF-8' }));
            } catch (e) { /* ادامه به fetch */ }
        }
        if (window.fetch) {
            fetch(target, { method: 'POST', headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, body: body, keepalive: true }).catch(function () {});
        }
    }

    // ⏱️ ارسال مدت حضور هنگام خروج
    window.addEventListener('pagehide', function () {
        var duration = Math.round((Date.now() - startTime) / 1000);
        beacon({ brand_id: brandId, session: session, page: pageUrl, duration: duration, is_exit: true });
    });

    beacon({
        brand_id: brandId,
        session: session,
        page: pageUrl,
        referrer: document.referrer || '',
        user_agent: navigator.userAgent,
        resolution: screen.width + 'x' + screen.height,
        language: navigator.language || ''
    });
})();
