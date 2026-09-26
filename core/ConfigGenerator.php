<?php
/**
 * ⚙️ تولیدکننده config.php سایت برند — پس از استقرار
 * =================================================
 * طبق سند بخش ۲۱ (بخش ۵): پس از آپلود و استخراج فایل‌ها،
 * config.php با مقادیر صحیح (دامنه زیردامنه جدید، API، ...)
 * بازنویسی می‌شود.
 *
 * ⚠️ در فرآیند بروزرسانی: مقادیر قبلی (کش و ...) حفظ می‌شوند.
 *
 * 🚨 v2.22 — ریشه‌یابی قطعی خطای «تست نهایی ناموفق: پاسخ HTTP: 500 |
 *    thrown in .../index.php on line 16»:
 *    نسخه‌های قبلی این کلاس فقط «ثابت‌ها» را می‌ساختند، در حالی که
 *    config.php قالب (templates/brand-core/config.php) «توابع کمکی»
 *    (fetchFromAPI / postToAPI / e / fa_num / cdn_asset) را هم دارد.
 *    بازنویسی config.php در مرحله stepConfig همه توابع را پاک می‌کرد →
 *    index.php خط ۱۶ با «Call to undefined function fetchFromAPI()»
 *    فاتال می‌شد → HTTP 500 و «صفحات خالی» سایت برند.
 *    ✅ اکنون فایل تولیدی «ثابت‌ها + کامل مجموعه توابع» است — هم‌ارز
 *    دقیق قالب، با پشتیبانی CACHE_ENABLED (که فقط در نسخه تولیدی تعریف
 *    می‌شود) — و رندر سریع صفحه خطای کش.
 *
 * @package SahandBrandMaker
 * @version 1.2.0
 */
class ConfigGenerator
{
    /**
     * ⚙️ تولید محتوای config.php سایت برند (ثابت‌ها + توابع کمکی کامل)
     *
     * @param array  $brand    ردیف برند (id، api_key، name_fa، name_en، slug)
     * @param string $fullDomain دامنه کامل سایت برند (مثلاً samsung.ea-fixer.ir)
     * @param string $brandmakerUrl آدرس سایت ساز (BASE_URL)
     * @param array  $previous تنظیمات قبلی برای حفظ (اختیاری — در بروزرسانی)
     * @return string محتوای PHP کامل
     */
    public static function generate(array $brand, string $fullDomain, string $brandmakerUrl, array $previous = []): string
    {
        // مقادیر قابل حفظ از نصب قبلی (بروزرسانی)
        $cacheEnabled = $previous['cache_enabled'] ?? true;
        $cacheTtl = (int)($previous['cache_ttl'] ?? 300);
        $debugMode = false; // در محیط اجرای واقعی همیشه خاموش

        // 🧹 پاک‌سازی ورودی‌ها برای جلوگیری از تزریق
        $fullDomain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', $fullDomain);
        $brandmakerUrl = rtrim(preg_replace('/[^a-zA-Z0-9\:\.\/\-]/', '', $brandmakerUrl), '/');
        $nameFa = str_replace(["'", '\\'], '', (string)$brand['name_fa']);
        $nameEn = str_replace(["'", '\\'], '', (string)$brand['name_en']);
        $apiKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$brand['api_key']);
        $slug = preg_replace('/[^a-zA-Z0-9\-_]/', '', (string)$brand['slug']);
        $brandId = (int)$brand['id'];

        // آدرس API ردیابی بازدید (همان سایت ساز)
        // 🚨 v2.26 — ریشه «آمار و گزارش چیزی نشان نمی‌دهد» (ضمن ریشه JS):
        // قبلا '/api' بدون '/track' بود → beacon به ریشه روتر می‌رفت و ۴۰۴ می‌گرفت
        $trackerUrl = $brandmakerUrl . '/api/track';

        // 📅 تاریخ تولید برای سربرگ فایل (شمسی)
        $generationDate = ShamsiDate::forDisplay();
        // تبدیل boolean به رشته PHP
        $cacheEnabledStr = $cacheEnabled ? 'true' : 'false';
        $debugModeStr = $debugMode ? 'true' : 'false';

        return <<<PHP
<?php
/**
 * ⚙️ فایل تنظیمات اتصال سایت برند به سایت ساز
 * ============================================
 * ⚠️ این فایل به صورت خودکار توسط افزونه استقرار
 * سایت ساز برند سهند سرویس تولید شده است.
 * ویرایش دستی توصیه نمی‌شود — با هر بروزرسانی بازنویسی می‌شود.
 *
 * برند: {$nameFa} ({$nameEn})
 * دامنه: {$fullDomain}
 * تاریخ تولید: {$generationDate}
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
 * 🏷️ شناسه‌های برند
 * -------------------------------------------------- */
define('BRAND_ID', '{$brandId}');                          // شناسه برند در سایت ساز
define('BRAND_API_KEY', '{$apiKey}');                      // کلید API اختصاصی این برند
define('BRAND_DOMAIN', '{$fullDomain}');                   // دامنه کامل سایت برند
define('BRAND_NAME_FA', '{$nameFa}');                      // نام فارسی برند
define('BRAND_NAME_EN', '{$nameEn}');                      // نام انگلیسی برند
define('BRAND_SLUG', '{$slug}');                           // اسلاگ انگلیسی برند

/* --------------------------------------------------
 * 🌐 آدرس سایت ساز (منبع داده‌ها و منابع مشترک)
 * -------------------------------------------------- */
define('BRANDMAKER_URL', '{$brandmakerUrl}');              // 🆕 v2.26 — ریشه سایت ساز (برای uploads/ خارج از assets/)
define('BRANDMAKER_API', '{$brandmakerUrl}/api');          // آدرس API سایت ساز
define('BRANDMAKER_ASSETS', '{$brandmakerUrl}/assets');    // آدرس منابع (آیکون/فونت/تصویر)
define('ASSETS_BASE_URL', '{$brandmakerUrl}/assets');      // نام مستعار سازگار با نسخه‌های قبلی
define('TRACKER_URL', '{$trackerUrl}');                    // آدرس API ردیابی بازدید (🆕 v2.26 — اکنون با /track)

/* --------------------------------------------------
 * ⏱️ تنظیمات کش محلی (برای سرعت و کاهش درخواست)
 * -------------------------------------------------- */
define('CACHE_DIR', __DIR__ . '/cache');                   // پوشه کش محلی
define('CACHE_ENABLED', {$cacheEnabledStr});                    // فعال/غیرفعال بودن کش
define('CACHE_TTL', {$cacheTtl});                          // مدت اعتبار کش (ثانیه)

/* --------------------------------------------------
 * 🌍 تنظیمات عمومی
 * -------------------------------------------------- */
define('DEBUG_MODE', {$debugModeStr});                          // حالت دیباگ (در محیط اجرا: خاموش)
define('VERSION', '1.2.1');                                // نسخه هسته سایت برند (🆕 v2.26 — شکستن کش tracker.js)
date_default_timezone_set('Asia/Tehran');                  // ⏰ منطقه زمانی ایران
mb_internal_encoding('UTF-8');                              // 🔤 انکودینگ UTF-8

/* ==================================================
 * 🧰 توابع کمکی مشترک سایت برند
 * ⚠️ v1.2: این توابع قبلاً فقط در config.php قالب (ZIP) وجود داشتند —
 * بازنویسی config.php در استقرار آنها را حذف می‌کرد و همه صفحات با
 * «Call to undefined function fetchFromAPI()» فاتال (HTTP 500) می‌شدند.
 * ================================================== */

/**
 * 📥 دریافت داده از API سایت ساز با کش فایل‌محور
 *
 * @param string \$endpoint اندپوینت (مثلاً brand/{id}/page/home)
 * @param int    \$cacheTtl مدت کش (ثانیه)
 * @return array|null
 */
function fetchFromAPI(string \$endpoint, int \$cacheTtl = CACHE_TTL): ?array
{
    // 🚫 کش غیرفعال است → مستقیم به API
    if (!CACHE_ENABLED) {
        return fetchFromAPILive(\$endpoint);
    }

    \$cacheFile = CACHE_DIR . '/' . sha1(\$endpoint) . '.json';

    // 📦 کش معتبر؟
    if (file_exists(\$cacheFile) && time() - filemtime(\$cacheFile) < \$cacheTtl) {
        \$cached = json_decode((string)@file_get_contents(\$cacheFile), true);
        if (is_array(\$cached)) {
            return \$cached;
        }
    }

    \$data = fetchFromAPILive(\$endpoint);

    // 💾 ذخیره در کش — فقط پاسخ موفق
    if (\$data !== null) {
        if (!is_dir(CACHE_DIR)) {
            @mkdir(CACHE_DIR, 0755, true);
        }
        @file_put_contents(\$cacheFile, json_encode(\$data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    } elseif (file_exists(\$cacheFile)) {
        // 🩹 شبکه قطع است — کش کهنه بهتر از هیچ
        \$cached = json_decode((string)@file_get_contents(\$cacheFile), true);
        if (is_array(\$cached)) {
            return \$cached;
        }
    }
    return \$data;
}

/**
 * ⚡ دریافت مستقیم (بدون کش) — قلب شبکه‌ای سایت برند
 * file_get_contents با مهلت + fallback cURL — هرگز استثنا پرتاب نمی‌کند.
 */
function fetchFromAPILive(string \$endpoint): ?array
{
    \$url = BRANDMAKER_API . '/' . \$endpoint;
    \$response = false;

    if (function_exists('curl_init')) {
        // 🔄 cURL مقدم — پایدارترین
        \$ch = curl_init(\$url);
        curl_setopt_array(\$ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER     => ['X-API-Key: ' . BRAND_API_KEY],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'SahandBrandSite/' . VERSION,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
        ]);
        \$response = curl_exec(\$ch);
        \$code = (int)curl_getinfo(\$ch, CURLINFO_HTTP_CODE);
        curl_close(\$ch);
        if (\$code !== 200) {
            \$response = false;
        }
    }

    if (\$response === false && ini_get('allow_url_fopen')) {
        // 🔄 fallback به file_get_contents
        \$context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 10,
                'ignore_errors' => true,
                'header'        => "X-API-Key: " . BRAND_API_KEY . "\r\n",
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        \$response = @file_get_contents(\$url, false, \$context);
    }

    if (\$response === false || \$response === '') {
        return null;
    }
    \$data = json_decode(\$response, true);
    if (!is_array(\$data) || empty(\$data['success'])) {
        return null;
    }
    return \$data;
}

/**
 * 📥 ارسال داده به API سایت ساز (فرم درخواست و ردیابی)
 */
function postToAPI(string \$endpoint, array \$payload): array
{
    \$url = BRANDMAKER_API . '/' . \$endpoint;
    \$payload['api_key'] = BRAND_API_KEY;
    \$json = json_encode(\$payload, JSON_UNESCAPED_UNICODE);

    if (function_exists('curl_init')) {
        \$ch = curl_init(\$url);
        curl_setopt_array(\$ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => \$json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        \$response = curl_exec(\$ch);
        curl_close(\$ch);
    } else {
        \$context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'content' => \$json,
                'timeout' => 15,
                'header'  => "Content-Type: application/json\r\n",
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        \$response = @file_get_contents(\$url, false, \$context);
    }
    \$data = json_decode((string)\$response, true);
    return is_array(\$data) ? \$data : ['success' => false, 'error' => 'خطای ارتباط با سرور'];
}

/**
 * 🧼 پاکسازی خروجی HTML (ضد XSS)
 */
function e(?string \$value): string
{
    return htmlspecialchars((string)\$value, ENT_QUOTES, 'UTF-8');
}

/**
 * 🔢 تبدیل اعداد به فارسی
 */
function fa_num(string \$value): string
{
    return str_replace(['0','1','2','3','4','5','6','7','8','9'], ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], \$value);
}

/**
 * 🖼️ آدرس منبع روی سرور سایت ساز
 *
 * 🚨 v2.26 — ریشه قطعی «تصویر شاخص مقالات نشان داده نمی‌شود» در سایت‌های
 * مستقرشده: این نسخهٔ تولیدی از cdn_asset با نسخهٔ قالب (templates/brand-core)
 * هم‌گام نشده بود و شاخهٔ uploads/ را نداشت → تصاویر آپلودی/تولیدی
 * (تصویر شاخص مقاله، واترمارک، عکس AI) که در uploads/ هستند به
 * BRANDMAKER_ASSETS/uploads/... می‌رفتند که وجود ندارد → ۴۰۴ همیشگی.
 * ✅ اکنون ۱:۱ هم‌ارز قالب است:
 *   uploads/... → BRANDMAKER_URL/uploads/...  (ریشه سایت ساز)
 *   assets/...  → BRANDMAKER_URL/assets/...   (معادل قدیمی)
 *   سایر        → BRANDMAKER_ASSETS/...        (سازگار با فراخوانی‌های قدیمی)
 */
function cdn_asset(string \$path): string
{
    if (\$path === '' || \$path === null) {
        return '';
    }
    if (strpos(\$path, 'http') === 0) {
        return \$path;
    }
    \$path = ltrim(\$path, '/');
    if (strpos(\$path, 'uploads/') === 0) {
        return BRANDMAKER_URL . '/' . \$path;
    }
    return BRANDMAKER_ASSETS . '/' . ltrim(\$path, 'assets/');
}

/* ==================================================
 * 🧰 توابع رندر مشترک صفحات — v2.24
 * 🚨 ریشه‌یابی «صفحات سایت برند خالی هستند»: فایل includes/functions.php
 * (render_page_section / article_image / page_url) در هیچ فایلی require
 * نمی‌شد — بازنویسی config.php در استقرار، مشکل را پنهان‌تر هم می‌کرد چون
 * فایل قالب هم همین کمبود را داشت. هر صفحه‌ای که محتوای API می‌گرفت در
 * اولین فراخوانی این توابع فاتال می‌شد و رندر نیمه‌کاره متوقف می‌شد.
 * اینجا (نقطه ورود مشترک همه صفحات) بارگذاری می‌شود.
 * ================================================== */
if (is_file(__DIR__ . '/includes/functions.php')) {
    require_once __DIR__ . '/includes/functions.php';
}
/* 🩹 v2.26 — لایه دفاعی دوم «صفحه مقاله خالی»: seo.php (render_article_seo)
   نیز از نقطه ورود مشترک بارگذاری می‌شود تا حتی اگر نسخه‌ای از
   pages/article.php بدون require مستقیم منتشر شده باشد، فراخوانی
   تابع فاتل نشود (همان الگوی سه‌لایه functions.php در v2.24). */
if (is_file(__DIR__ . '/includes/seo.php')) {
    require_once __DIR__ . '/includes/seo.php';
}

PHP;
    }

    /**
     * 📖 استخراج مقادیر قابل حفظ از config.php موجود
     * (برای بروزرسانی بدون از دست رفتن تنظیمات — طبق سند)
     *
     * @param string $content محتوای config.php قبلی
     * @return array [cache_enabled, cache_ttl]
     */
    public static function extractPreservable(string $content): array
    {
        $result = ['cache_enabled' => true, 'cache_ttl' => 300];

        if (preg_match("/define\\('CACHE_ENABLED'\\s*,\\s*(true|false)\\)/", $content, $m)) {
            $result['cache_enabled'] = $m[1] === 'true';
        }
        if (preg_match("/define\\('CACHE_TTL'\\s*,\\s*(\\d+)\\)/", $content, $m)) {
            $result['cache_ttl'] = (int)$m[1];
        }
        return $result;
    }
}
