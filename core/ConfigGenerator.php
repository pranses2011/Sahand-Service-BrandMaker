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
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class ConfigGenerator
{
    /**
     * ⚙️ تولید محتوای config.php سایت برند
     *
     * @param array $brand    ردیف برند (id، api_key، name_fa، name_en، slug)
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
        $trackerUrl = $brandmakerUrl . '/api';

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
 * @version 1.1.0
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
define('BRANDMAKER_API', '{$brandmakerUrl}/api');          // آدرس API سایت ساز
define('BRANDMAKER_ASSETS', '{$brandmakerUrl}/assets');    // آدرس منابع (آیکون/فونت/تصویر)
define('ASSETS_BASE_URL', '{$brandmakerUrl}/assets');      // نام مستعار سازگار با نسخه‌های قبلی
define('TRACKER_URL', '{$trackerUrl}');                    // آدرس API ردیابی بازدید

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
define('VERSION', '1.1.0');                                // نسخه هسته سایت برند
date_default_timezone_set('Asia/Tehran');                  // ⏰ منطقه زمانی ایران
mb_internal_encoding('UTF-8');                              // 🔤 انکودینگ UTF-8

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
