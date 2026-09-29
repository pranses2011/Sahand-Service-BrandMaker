<?php
/**
 * ⚙️ فایل تنظیمات اتصال سایت برند به سایت ساز
 * ============================================
 * این فایل هنگام تولید ZIP به صورت خودکار مقداردهی می‌شود.
 *
 * @package SahandBrandSite
 * @version 1.2.0
 */

// 🛡️ جلوگیری از دسترسی مستقیم
if (!defined('BRAND_INIT')) {
    http_response_code(403);
    exit('⛔ دسترسی مستقیم مجاز نیست.');
}

/* --------------------------------------------------
 * 🏷️ شناسه برند (هنگام تولید سایت پر می‌شود)
 * -------------------------------------------------- */
define('BRAND_ID', '{{BRAND_ID}}');                       // شناسه برند در سایت ساز
define('BRAND_API_KEY', '{{BRAND_API_KEY}}');            // کلید API این برند
define('BRAND_DOMAIN', '{{BRAND_DOMAIN}}');             // دامنه سایت برند
define('BRAND_NAME_FA', '{{BRAND_NAME_FA}}');           // نام فارسی برند
define('BRAND_NAME_EN', '{{BRAND_NAME_EN}}');           // نام انگلیسی برند

/* --------------------------------------------------
 * 🌐 آدرس سایت ساز (منبع داده‌ها و منابع مشترک)
 * -------------------------------------------------- */
define('BRANDMAKER_URL', '{{BRANDMAKER_URL}}');             // 🆕 v2.26 — ریشه سایت ساز (برای uploads/ خارج از assets/)
define('BRANDMAKER_API', '{{BRANDMAKER_URL}}/api');     // آدرس API سایت ساز
define('BRANDMAKER_ASSETS', '{{BRANDMAKER_URL}}/assets'); // آدرس منابع (آیکون/فونت/تصویر)
define('TRACKER_URL', '{{BRANDMAKER_URL}}/api/track');    // 🆕 v2.26 — اندپوینت ردیابی بازدید (قبلا فقط در کانفیگ بازنویسی‌شده تعریف می‌شد و /track هم جا افتاده بود)

/* --------------------------------------------------
 * ⏱️ تنظیمات کش محلی (برای سرعت و کاهش درخواست)
 * -------------------------------------------------- */
define('CACHE_DIR', __DIR__ . '/cache');                // پوشه کش محلی
define('CACHE_ENABLED', true);                          // فعال/غیرفعال بودن کش
define('CACHE_TTL', 300);                                // مدت اعمال کش به ثانیه (۵ دقیقه)

/* --------------------------------------------------
 * 🌍 تنظیمات عمومی
 * -------------------------------------------------- */
define('VERSION', '1.4.0');                              // 🔖 نسخه هسته سایت برند (🆕 v2.41 — دیت‌پیکر شمسی + واترمارک تصاویر درخواست + بلوک‌های پویا + هماهنگی رنگ منو؛ شکستن کش JS/CSS)
date_default_timezone_set('Asia/Tehran');               // ⏰ منطقه زمانی ایران
mb_internal_encoding('UTF-8');                           // 🔤 انکودینگ

/* --------------------------------------------------
 * 🧰 توابع کمکی مشترک سایت برند
 * ⚠️ v1.2: هم‌ارز ConfigGenerator نسخه ۱.۲ — استقرار خودکار config.php را
 * بازنویسی می‌کند؛ این مجموعه توابع باید در هر دو نسخه یکسان بماند وگرنه
 * صفحات با «Call to undefined function» فاتال می‌شوند.
 * -------------------------------------------------- */

/**
 * 📥 دریافت داده از API سایت ساز با کش فایل‌محور
 *
 * @param string $endpoint اندپوینت (مثلاً brand/{id}/page/home)
 * @param int    $cacheTtl مدت کش (ثانیه)
 * @return array|null
 */
function fetchFromAPI(string $endpoint, int $cacheTtl = CACHE_TTL): ?array
{
    /* 🧠 v2.33 — حافظه درون-درخواستی: همان اندپوینت در یک بازدید فقط
       یک‌بار کش/شبکه می‌خورد (header و footer هر دو settings می‌خواندند) */
    static $memo = [];
    $memoKey = $endpoint . '|' . $cacheTtl;
    if (array_key_exists($memoKey, $memo)) {
        return $memo[$memoKey];
    }
    $result = __fetch_cached($endpoint, $cacheTtl);
    $memo[$memoKey] = $result;
    return $result;
}

/**
 * 📥 v2.33 — لایه کش سخت‌گیرانه (گزارش تحلیل بخش ۵.۱)
 * 🔒 قفل flock: جلوگیری از Cache Stampede — فقط یک فرآیند پس از انقضا
 *    به سرور مرکزی می‌زند؛ بقیه تا ۳ ثانیه منتظر کش تازه می‌مانند.
 * 🎲 TTL جیتر (±۲۰٪): انقضای دسته‌جمعی همه فراخوانی‌ها هم‌زمان رخ نمی‌دهد.
 * ⏳ سقف کهنه ۲۴ ساعت: اگر API قطع بماند، سایت حداکثر یک روز کهنه سرو
 *    می‌کند و بعد صادقانه null می‌دهد (قبلاً «کهنه ابدی» = یخ‌زدگی بی‌پایان).
 */
function __fetch_cached(string $endpoint, int $cacheTtl): ?array
{
    // 🚫 کش غیرفعال است → مستقیم به API
    if (!CACHE_ENABLED) {
        return fetchFromAPILive($endpoint);
    }

    $cacheFile = CACHE_DIR . '/' . sha1($endpoint) . '.json';
    $ttl = $cacheTtl + random_int(0, (int)max(1, ceil($cacheTtl * 0.2)));

    $isValid = static function () use ($cacheFile, $ttl): bool {
        clearstatcache(true, $cacheFile);
        return is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < $ttl;
    };
    $readCache = static function () use ($cacheFile): ?array {
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        return is_array($cached) ? $cached : null;
    };
    $age = static function () use ($cacheFile): int {
        clearstatcache(true, $cacheFile);
        return is_file($cacheFile) ? (time() - (int)filemtime($cacheFile)) : PHP_INT_MAX;
    };

    // 📦 کش معتبر؟
    if ($isValid()) {
        return $readCache();
    }

    /* 🔒 قفل کش — فقط یک فرآیند شبکه می‌زند */
    $lock = @fopen($cacheFile . '.lock', 'c');
    if ($lock === false) {
        // فایل‌سیستم قدیمی بدون قفل → رفتار قبلی
        return __fetch_and_store($endpoint, $cacheFile, $readCache, $age);
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        // فرآیند دیگری در حال دریافت است — کوتاه منتظر کش تازه بمان
        $waited = 0.0;
        while ($waited < 3.0) {
            usleep(250000);
            $waited += 0.25;
            if ($isValid()) {
                flock($lock, LOCK_UN);
                fclose($lock);
                return $readCache();
            }
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        // ⏳ کهنه با سقف ۲۴ ساعت — بعد از آن صادقانه null
        return ($age() < 86400) ? $readCache() : null;
    }
    // قفل را گرفتیم — دوباره چک (شاید فرآیند قبلی تازه کرده باشد)
    if ($isValid()) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return $readCache();
    }
    $out = __fetch_and_store($endpoint, $cacheFile, $readCache, $age);
    flock($lock, LOCK_UN);
    fclose($lock);
    return $out;
}

/** 💾 دریافت و ذخیره — با سقف کهنه ۲۴ ساعت در قطعی شبکه */
function __fetch_and_store(string $endpoint, string $cacheFile, callable $readCache, callable $age): ?array
{
    $data = fetchFromAPILive($endpoint);

    // 💾 ذخیره در کش — فقط پاسخ موفق
    if ($data !== null) {
        if (!is_dir(CACHE_DIR)) {
            @mkdir(CACHE_DIR, 0755, true);
        }
        @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $data;
    }
    // 🩹 شبکه قطع است — کهنه بهتر از هیچ، اما حداکثر ۲۴ ساعت
    if ($age() < 86400) {
        return $readCache();
    }
    return null;
}

/**
 * 🚀 v2.33 — دریافت موازی چند اندپوینت با curl_multi (گزارش تحلیل ۵.۱:
 * «عدم استفاده از curl_multi — فراخوانی‌ها کاملاً سریال‌اند در حالی که مستقل‌اند»)
 * اندپوینت‌های دارای کش معتبر از کش خوانده می‌شوند؛ بقیه همه یک‌جا موازی گرفته می‌شوند.
 *
 * @param string[] $endpoints فهرست اندپوینت‌ها
 * @param int      $cacheTtl  مدت کش نوشته‌شده
 * @return array<string, array|null> اندپوینت => داده (یا null)
 */
function fetchFromAPIMulti(array $endpoints, int $cacheTtl = CACHE_TTL): array
{
    $out = [];
    $need = [];
    foreach (array_unique($endpoints) as $ep) {
        $cached = fetchFromAPI($ep, $cacheTtl);
        if ($cached !== null) {
            $out[$ep] = $cached;
        } else {
            $need[] = $ep;
        }
    }
    if (!$need) {
        return $out;
    }

    /* حالت بدون curl_multi (نیاز به fallback) */
    if (!function_exists('curl_multi_init')) {
        foreach ($need as $ep) {
            $out[$ep] = fetchFromAPILive($ep);
        }
        return $out;
    }

    $mh = curl_multi_init();
    $handles = [];
    foreach ($need as $ep) {
        $ch = curl_init(BRANDMAKER_API . '/' . $ep);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER     => ['X-API-Key: ' . BRAND_API_KEY],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'SahandBrandSite/1.2',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$ep] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 0.2);
        }
    } while ($active && $status === CURLM_OK);

    foreach ($handles as $ep => $ch) {
        $body = (string)curl_multi_getcontent($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        $decoded = ($code === 200 && $body !== '') ? json_decode($body, true) : null;
        $data = (is_array($decoded) && !empty($decoded['success'])) ? $decoded : null;
        if ($data === null) {
            // پاسخ موازی ناموفق → fallback تک‌تک (با منطق کهنه‌ی سخت‌گیرانه)
            $data = fetchFromAPILive($ep);
        }
        if ($data !== null && CACHE_ENABLED) {
            if (!is_dir(CACHE_DIR)) {
                @mkdir(CACHE_DIR, 0755, true);
            }
            @file_put_contents(CACHE_DIR . '/' . sha1($ep) . '.json', json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        $out[$ep] = $data;
    }
    curl_multi_close($mh);
    return $out;
}

/**
 * ⚡ دریافت مستقیم (بدون کش) — قلب شبکه‌ای سایت برند
 * cURL مقدم (مهلت ۱۰ث + کد وضعیت) + fallback file_get_contents — هرگز استثنا پرتاب نمی‌کند.
 */
function fetchFromAPILive(string $endpoint): ?array
{
    $url = BRANDMAKER_API . '/' . $endpoint;
    $response = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER     => ['X-API-Key: ' . BRAND_API_KEY],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'SahandBrandSite/1.2',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
        ]);
        $response = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            $response = false;
        }
    }

    if ($response === false && ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 10,
                'ignore_errors' => true,
                'header'        => "X-API-Key: " . BRAND_API_KEY . "\r\n",
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $response = @file_get_contents($url, false, $context);
    }

    if ($response === false || $response === '') {
        return null;
    }
    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['success'])) {
        return null;
    }
    return $data;
}

/**
 * 📥 ارسال داده به API سایت ساز (بدون کش تو در تو — نسخه ساده برای فرم)
 */
function postToAPI(string $endpoint, array $payload): array
{
    $url = BRANDMAKER_API . '/' . $endpoint;
    $payload['api_key'] = BRAND_API_KEY;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'content' => $json,
                'timeout' => 15,
                'header'  => "Content-Type: application/json\r\n",
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $response = @file_get_contents($url, false, $context);
    }
    $data = json_decode((string)$response, true);
    return is_array($data) ? $data : ['success' => false, 'error' => 'خطای ارتباط با سرور'];
}

/**
 * 🧼 پاکسازی خروجی HTML (ضد XSS)
 */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * 🔢 تبدیل اعداد به فارسی
 */
function fa_num(string $value): string
{
    return str_replace(['0','1','2','3','4','5','6','7','8','9'], ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], $value);
}

/**
 * 🖼️ آدرس منبع روی سرور سایت ساز
 *
 * 🆕 v2.26 — آگاه از پوشه‌ها: قبلاً «هر» مسیر نسبی به /assets/ چسبانده می‌شد؛
 * اما تصاویر آپلودی/تولیدی (تصویر شاخص، واترمارک، عکس AI) در uploads/ هستند
 * نه assets/ → URL غلط و 404. اکنون:
 *   uploads/... → BRANDMAKER_URL/uploads/...  (ریشه سایت ساز)
 *   assets/...  → BRANDMAKER_URL/assets/...   (معادل قدیمی)
 *   سایر        → BRANDMAKER_ASSETS/...        (سازگار با فراخوانی‌های قدیمی مثل icons/...)
 */
function cdn_asset(string $path): string
{
    if ($path === '' || $path === null) {
        return '';
    }
    if (strpos($path, 'http') === 0) {
        return $path;
    }
    $path = ltrim($path, '/');
    if (strpos($path, 'uploads/') === 0) {
        return BRANDMAKER_URL . '/' . $path;
    }
    return BRANDMAKER_ASSETS . '/' . ltrim($path, 'assets/');
}

/* ==================================================
 * 🧰 توابع رندر مشترک صفحات — v2.24
 * 🚨 ریشه‌یابی «صفحات سایت برند خالی هستند»: فایل includes/functions.php
 * (render_page_section / article_image / page_url) در هیچ فایلی require
 * نمی‌شد! هر صفحه‌ای که محتوای API می‌گرفت، در اولین فراخوانی این توابع
 * با «Call to undefined function» فاتال می‌شد و رندر نیمه‌کاره متوقف
 * می‌شد — دقیقاً علامت «صفحات خالی». حالا اینجا (نقطه ورود مشترک همه
 * صفحات) بارگذاری می‌شود؛ فایل کمکی به‌ازای هر درخواست یک‌بار.
 * ================================================== */
if (is_file(__DIR__ . '/includes/functions.php')) {
    require_once __DIR__ . '/includes/functions.php';
}

/* 🆕 v2.26 — بارگذاری توابع سئو (render_article_seo) — ریشه قطعی «صفحه مقاله
   خالی»: includes/seo.php در هیچ فایلی require نمی‌شد و article.php بعد از رندر
   هدر در همان خط render_article_seo فاتل می‌شد (الگوی یکسان با باگ v2.24). */
if (is_file(__DIR__ . '/includes/seo.php')) {
    require_once __DIR__ . '/includes/seo.php';
}

/* 🆕 v2.27 — بارگذاری رندرگر بلوک‌های قالب‌ساز (bb_render_block / bb_layout_html)
   ریشه «تغییر تم سایت‌ساز روی سایت برند اعمال نمی‌شود»: صفحات سایت برند
   چیدمان تم را از API می‌گیرند و با این رندرگر نمایش می‌دهند. */
if (is_file(__DIR__ . '/includes/blocks.php')) {
    require_once __DIR__ . '/includes/blocks.php';
}

/* 🌍 v2.37 — P3: پایه چندزبانگی — تشخیص locale + واژه‌نامه UI + توابع hreflang
   باید «قبل از» رندر هر صفحه بارگذاری شود (ثابت‌های BRAND_LANG/BRAND_DIR
   در header.php استفاده می‌شوند) */
if (is_file(__DIR__ . '/includes/i18n.php')) {
    require_once __DIR__ . '/includes/i18n.php';
}
