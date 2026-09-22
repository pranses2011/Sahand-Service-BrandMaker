/**
 * 🧭 ردیاب بازدید داخلی — بدون هیچ سرویس خارجی
 * ==============================================
 * اطلاعات: صفحه، رفرر، رزولوشن، زبان، مدت حضور
 * ارسال به: BRANDMAKER_API/track
 */
(function () {
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

    // 📤 ارسال بازدید صفحه
    function track() {
        var payload = {
            brand_id: typeof BRAND_ID !== 'undefined' ? BRAND_ID : 0,
            session: session,
            page: pageUrl,
            referrer: document.referrer || '',
            user_agent: navigator.userAgent,
            resolution: screen.width + 'x' + screen.height,
            language: navigator.language || ''
        };
        // ارسال با sendBeacon (بدون بلاک کردن صفحه) یا fetch
        if (navigator.sendBeacon) {
            navigator.sendBeacon(TRACKER_URL, new Blob([JSON.stringify(payload)], { type: 'application/json' }));
        } else if (window.fetch) {
            fetch(TRACKER_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload), keepalive: true }).catch(function () {});
        }
    }

    // ⏱️ ارسال مدت حضور هنگام خروج
    window.addEventListener('pagehide', function () {
        var duration = Math.round((Date.now() - startTime) / 1000);
        if (navigator.sendBeacon) {
            navigator.sendBeacon(TRACKER_URL, new Blob([JSON.stringify({
                brand_id: typeof BRAND_ID !== 'undefined' ? BRAND_ID : 0,
                session: session, page: pageUrl, duration: duration, is_exit: true
            })], { type: 'application/json' }));
        }
    });

    track();
})();
