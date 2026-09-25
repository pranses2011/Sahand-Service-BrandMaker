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
define('BRANDMAKER_API', '{{BRANDMAKER_URL}}/api');     // آدرس API سایت ساز
define('BRANDMAKER_ASSETS', '{{BRANDMAKER_URL}}/assets'); // آدرس منابع (آیکون/فونت/تصویر)

/* --------------------------------------------------
 * ⏱️ تنظیمات کش محلی (برای سرعت و کاهش درخواست)
 * -------------------------------------------------- */
define('CACHE_DIR', __DIR__ . '/cache');                // پوشه کش محلی
define('CACHE_ENABLED', true);                          // فعال/غیرفعال بودن کش
define('CACHE_TTL', 300);                                // مدت اعمال کش به ثانیه (۵ دقیقه)

/* --------------------------------------------------
 * 🌍 تنظیمات عمومی
 * -------------------------------------------------- */
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
    // 🚫 کش غیرفعال است → مستقیم به API
    if (!CACHE_ENABLED) {
        return fetchFromAPILive($endpoint);
    }

    $cacheFile = CACHE_DIR . '/' . sha1($endpoint) . '.json';

    // 📦 کش معتبر؟
    if (file_exists($cacheFile) && time() - filemtime($cacheFile) < $cacheTtl) {
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $data = fetchFromAPILive($endpoint);

    // 💾 ذخیره در کش — فقط پاسخ موفق
    if ($data !== null) {
        if (!is_dir(CACHE_DIR)) {
            @mkdir(CACHE_DIR, 0755, true);
        }
        @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    } elseif (file_exists($cacheFile)) {
        // 🩹 شبکه قطع است — کش کهنه بهتر از هیچ
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    return $data;
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
 */
function cdn_asset(string $path): string
{
    if ($path === '' || $path === null) {
        return '';
    }
    return (strpos($path, 'http') === 0) ? $path : BRANDMAKER_ASSETS . '/' . ltrim($path, 'assets/');
}
