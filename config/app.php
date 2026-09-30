<?php
/**
 * ⚙️ کرهٔ اپلیکیشن — نسخه، مسیرها، بارگذاری خودکار، پلی‌فیل‌ها، BASE_URL
 * اولین فایل بارگذاری‌شده توسط config.php
 * 🧩 v2.43 (S12): از config.php تک‌عظیم تفکیک شد — config.php نقش Bootstrap دارد.
 * @package SahandBrandMaker\Config
 */

/* ⛔ جلوگیری از دسترسی مستقیم به فایل‌های PHP از خارج */
if (!defined('SAHAND_INIT')) {
    http_response_code(403);
    exit('⛔ دسترسی مستقیم مجاز نیست.');
}

/* --------------------------------------------------
 * 🔖 نسخه و هویت سیستم
 * -------------------------------------------------- */
define('SAHAND_VERSION', '2.44.0');           // نسخه سیستم (۲.۴۴٫۰ — چهارده درخواست کاربر S01-S14: متد واحد سه‌گانه AI + راهنمای کلید + ترتیب فال‌بک دلخواه + چت مستقیم AI + سوییچ چندزبانه + پویاسازی کامل بلوک‌ها + تکمیل تنظیمات صفحه و عناصر + فرم‌ساز سفارشی + ویرایشگر متن حرفه‌ای + ۴۹ انیمیشن Lottie)
// قدیمی: ۲.۳۱٫۰ — ① GeoIP سه‌لایه دقیق با کش DB + ip-api فارسی + نقشه استانی واقعی ۳۱ استان از Natural Earth ② رفع WEEKDAY (شنبه=پنجشنبه) + تایم‌زون تهران صریح ③ کاربران آنلاین زنده + مودال جزئیات ۴ گزارش جدید ④ کارت تصویری واحد درخواست با واترمارک دو لوگو + متن فارسی GD ⑤ نام دستگاه فارسی ⑥ رفع ایمیل با خطای دقیق + تست ایمیل ⑦ ۲۰ عنصر جدید (دکمه/پیشرفت چندرنگ/گردونه) ⑧ رنگ جداگانه هر آیتم ⑨ لینک دکمه‌ها ⑩ فرم‌های واقعی قابل تنظیم + مقصد ارسال + صفحه فرم‌های دیگر ⑪ انیمیشن ۴ ورود + ۶ پیوسته + ۶ هاور جدید ⑫ ۸ تنظیم صفحه جدید شامل CSS دلخواه)
define('SAHAND_NAME_FA', 'سایت ساز برند سهند سرویس'); // نام فارسی سیستم
define('SAHAND_NAME_EN', 'Sahand BrandMaker');   // نام انگلیسی سیستم
date_default_timezone_set('Asia/Tehran');        // ⏰ منطقه زمانی ایران
mb_internal_encoding('UTF-8');                   // 🔤 انکودینگ UTF-8

/* --------------------------------------------------
 * 📥 بارگذاری تنظیمات محلی (در صورت وجود)
 * این فایل توسط install.php ساخته می‌شود و اطلاعات حساس را نگه می‌دارد.
 * ⚠️ باید قبل از تعریف ثابت‌ها بارگذاری شود تا مقادیر واقعی جایگزین پیش‌فرض شوند.
 * -------------------------------------------------- */
$localConfig = dirname(__DIR__) . '/config.local.php';
if (file_exists($localConfig)) {
    require_once $localConfig;
}

/* --------------------------------------------------
 * 🌐 تنظیمات آدرس‌ها
 * -------------------------------------------------- */
// آدرس سایت اصلی نمایندگی (همه سایت‌های برند به آن لینک می‌دهند)
define('AGENCY_MAIN_SITE', 'https://ea-fixer.ir');
// آدرس پنل سایت ساز (در صورت خالی بودن به صورت خودکار تشخیص داده می‌شود)
define('BRANDMAKER_URL', '');
// فرمت پیش‌فرض دامنه سایت‌های برند
define('BRAND_DOMAIN_FORMAT', '{brand}.ea-fixer.ir');

/* --------------------------------------------------
 * 📁 مسیرهای سیستمی
 * ⚠️ ROOT_PATH با dirname(__DIR__) ساخته می‌شود چون این فایل در config/ است
 * -------------------------------------------------- */
define('ROOT_PATH', dirname(__DIR__));              // مسیر ریشه پروژه
define('CORE_PATH', ROOT_PATH . '/core');           // مسیر کلاس‌های هسته
define('ENGINE_PATH', ROOT_PATH . '/engine');       // مسیر موتور AI
define('ASSETS_PATH', ROOT_PATH . '/assets');       // مسیر منابع (آیکون/فونت/تصویر)
define('UPLOADS_PATH', ROOT_PATH . '/uploads');     // مسیر آپلودها
define('CACHE_PATH', ROOT_PATH . '/cache');         // مسیر کش
define('LOGS_PATH', ROOT_PATH . '/logs');           // مسیر لاگ‌ها

/* --------------------------------------------------
 * 🧩 بارگذاری خودکار کلاس‌ها (بدون نیاز به Composer)
 * -------------------------------------------------- */
spl_autoload_register(function ($className) {
    // جستجوی کلاس در پوشه‌های مشخص‌شده
    $dirs = [
        CORE_PATH . '/',
        CORE_PATH . '/Auth/',   // 🧩 v2.43 (S13) — اجزای تفکیک‌شده Auth
        ENGINE_PATH . '/',
        ENGINE_PATH . '/generators/',
        ENGINE_PATH . '/analyzers/',
        ENGINE_PATH . '/utils/',
        ENGINE_PATH . '/services/',
        ENGINE_PATH . '/skills/',   // 🎨 اسکیل‌های تخصصی (UI/UX Pro و ...)
    ];
    foreach ($dirs as $dir) {
        $file = $dir . $className . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

/* --------------------------------------------------
 * 🔗 پلی‌فیل توابع PHP ۸ برای سرورهای PHP ۷.۴+
 * -------------------------------------------------- */
if (PHP_VERSION_ID < 80000) {
    if (!function_exists('str_contains')) {
        function str_contains(string $haystack, string $needle): bool
        {
            return $needle === '' || mb_strpos($haystack, $needle) !== false;
        }
    }
    if (!function_exists('str_starts_with')) {
        function str_starts_with(string $haystack, string $needle): bool
        {
            return $needle === '' || mb_strpos($haystack, $needle) === 0;
        }
    }
    if (!function_exists('str_ends_with')) {
        function str_ends_with(string $haystack, string $needle): bool
        {
            if ($needle === '') {
                return true;
            }
            return mb_substr($haystack, -mb_strlen($needle)) === $needle;
        }
    }
}

/* --------------------------------------------------
 * ⚡ پلی‌فیل json_validate
 * -------------------------------------------------- */
if (!function_exists('json_validate')) {
    function json_validate(string $json, int $depth = 512): bool
    {
        if ($json === '') {
            return false;
        }
        json_decode($json, true, $depth);
        return json_last_error() === JSON_ERROR_NONE;
    }
}

/* --------------------------------------------------
 * 🧰 بارگذاری توابع کمکی عمومی (helpers)
 * -------------------------------------------------- */
require_once ROOT_PATH . '/includes/helpers.php';

/* --------------------------------------------------
 * 🌐 تشخیص آدرس پایه سایت ساز
 * -------------------------------------------------- */
if (!defined('BASE_URL')) {
    if (defined('BRANDMAKER_URL_VALUE') && BRANDMAKER_URL_VALUE !== '') {
        define('BASE_URL', rtrim(BRANDMAKER_URL_VALUE, '/'));
    } else {
        // تشخیص خودکار بر اساس اطلاعات سرور
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // حذف زیرپوشه‌های احتمالی مثل /admin از مسیر
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        // اگر داخل پوشه admin یا api هستیم، یک سطح بالا برویم
        if (preg_match('#/(admin|api|engine)$#', $dir)) {
            $dir = dirname($dir);
        }
        define('BASE_URL', $scheme . '://' . $host . ($dir === '/' ? '' : $dir));
    }
}