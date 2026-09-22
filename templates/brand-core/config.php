<?php
/**
 * ⚙️ فایل تنظیمات اتصال سایت برند به سایت ساز
 * ============================================
 * این فایل هنگام تولید ZIP به صورت خودکار مقداردهی می‌شود.
 *
 * @package SahandBrandSite
 * @version 1.0.0
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
define('CACHE_TTL', 300);                                // مدت اعمال کش به ثانیه (۵ دقیقه)

/* --------------------------------------------------
 * 🌍 تنظیمات عمومی
 * -------------------------------------------------- */
date_default_timezone_set('Asia/Tehran');               // ⏰ منطقه زمانی ایران
mb_internal_encoding('UTF-8');                           // 🔤 انکودینگ

/* --------------------------------------------------
 * 🧰 توابع کمکی مشترک سایت برند
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
    $cacheFile = CACHE_DIR . '/' . sha1($endpoint) . '.json';

    // 📦 کش معتبر؟
    if (file_exists($cacheFile) && time() - filemtime($cacheFile) < $cacheTtl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    // 🌐 درخواست به API
    $url = BRANDMAKER_API . '/' . $endpoint;
    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 8,
            'ignore_errors' => true,
            'header'        => "X-API-Key: " . BRAND_API_KEY . "\r\n",
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false && function_exists('curl_init')) {
        // 🔄 fallback به cURL
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => ['X-API-Key: ' . BRAND_API_KEY],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
    }
    if ($response === false) {
        return $cached ?? null; // کش کهنه بهتر از هیچ
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['success'])) {
        return $cached ?? null;
    }

    // 💾 ذخیره در کش
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
    }
    @file_put_contents($cacheFile, $response, LOCK_EX);
    return $data;
}

/**
 * 📥 دریافت داده (بدون کش تو در تو — نسخه ساده برای فرم)
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
            CURLOPT_SSL_VERIFYPEER => false,
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
