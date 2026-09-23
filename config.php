<?php
/**
 * 🔧 فایل تنظیمات اصلی سایت ساز برند سهند سرویس
 * ------------------------------------------------
 * این فایل تنظیمات پایه سیستم را تعریف می‌کند.
 * در صورت استفاده از install.php این فایل به صورت خودکار
 * مقادیر را از فایل config.local.php (در صورت وجود) بازنویسی می‌کند.
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */

// ⛔ جلوگیری از دسترسی مستقیم به فایل‌های PHP از خارج
if (!defined('SAHAND_INIT')) {
    http_response_code(403);
    exit('⛔ دسترسی مستقیم مجاز نیست.');
}

/* --------------------------------------------------
 * 🌍 تنظیمات عمومی
 * -------------------------------------------------- */
define('SAHAND_VERSION', '1.1.0');              // نسخه سیستم
define('SAHAND_NAME_FA', 'سایت ساز برند سهند سرویس'); // نام فارسی سیستم
define('SAHAND_NAME_EN', 'Sahand BrandMaker');   // نام انگلیسی سیستم
date_default_timezone_set('Asia/Tehran');        // ⏰ منطقه زمانی ایران
mb_internal_encoding('UTF-8');                   // 🔤 انکودینگ UTF-8

/* --------------------------------------------------
 * 📥 بارگذاری تنظیمات محلی (در صورت وجود)
 * این فایل توسط install.php ساخته می‌شود و اطلاعات حساس را نگه می‌دارد.
 * ⚠️ باید قبل از تعریف ثابت‌ها بارگذاری شود تا مقادیر واقعی جایگزین پیش‌فرض شوند.
 * -------------------------------------------------- */
$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    require_once $localConfig;
}

/* --------------------------------------------------
 * 🗄️ تنظیمات دیتابیس (MySQL)
 * مقادیر واقعی پس از نصب در config.local.php ذخیره می‌شوند
 * -------------------------------------------------- */
define('DB_HOST', defined('DB_HOST_VALUE') ? DB_HOST_VALUE : 'localhost');   // آدرس سرور دیتابیس
define('DB_NAME', defined('DB_NAME_VALUE') ? DB_NAME_VALUE : 'brandmaker');  // نام دیتابیس
define('DB_USER', defined('DB_USER_VALUE') ? DB_USER_VALUE : 'root');        // نام کاربری دیتابیس
define('DB_PASS', defined('DB_PASS_VALUE') ? DB_PASS_VALUE : '');            // رمز عبور دیتابیس
define('DB_PORT', defined('DB_PORT_VALUE') ? (int)DB_PORT_VALUE : 3306);     // پورت دیتابیس
define('DB_CHARSET', 'utf8mb4');  // انکودینگ دیتابیس (پشتیبانی کامل فارسی)

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
 * 🔐 تنظیمات امنیتی
 * -------------------------------------------------- */
define('SAHAND_DEBUG', false);            // حالت دیباگ (در محیط عملیاتی خاموش بماند)
define('SESSION_LIFETIME', 7200);         // مدت اعتبار نشست ورود (ثانیه) — ۲ ساعت
define('MAX_LOGIN_ATTEMPTS', 5);          // حداکثر تلاش ناموفق ورود قبل از قفل
define('LOGIN_LOCK_MINUTES', 15);         // مدت قفل حساب پس از تلاش ناموفق (دقیقه)
define('API_RATE_LIMIT', 120);            // حداکثر درخواست API در دقیقه برای هر IP
define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024);   // حداکثر حجم آپلود (۱۰ مگابایت)
define('MAX_REQUEST_IMAGES', 3);          // حداکثر تصویر پیوست هر درخواست خدمات

/* --------------------------------------------------
 * 📁 مسیرهای سیستمی
 * -------------------------------------------------- */
define('ROOT_PATH', __DIR__);                       // مسیر ریشه پروژه
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
        ENGINE_PATH . '/',
        ENGINE_PATH . '/generators/',
        ENGINE_PATH . '/analyzers/',
        ENGINE_PATH . '/utils/',
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
 * 🧰 بارگذاری توابع کمکی عمومی (helpers)
 * شامل: تاریخ جلالی، اعداد فارسی، اعتبارسنجی و ...
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

/* --------------------------------------------------
 * ⚠️ مدیریت خطاها و استثناها
 * -------------------------------------------------- */
error_reporting(E_ALL);
ini_set('display_errors', SAHAND_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

set_exception_handler(function ($e) {
    // ثبت خطا در لاگ
    @error_log('[EXCEPTION] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (SAHAND_DEBUG) {
        http_response_code(500);
        echo '<pre dir="ltr">⚠ ' . htmlspecialchars((string)$e) . '</pre>';
    } else {
        // پاسخ مناسب برای API یا صفحات
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'خطای داخلی سرور'], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><body style="font-family:Tahoma;background:#f5f5f5;display:flex;align-items:center;justify-content:center;height:100vh"><div style="text-align:center"><h2>⚠ خطای داخلی سرور</h2><p>لطفاً بعداً تلاش کنید یا با مدیر تماس بگیرید.</p></div></body></html>';
        }
    }
    exit;
});

/* --------------------------------------------------
 * 🕐 شروع امن نشست (Session)
 * -------------------------------------------------- */
if (session_status() === PHP_SESSION_NONE && !defined('SAHAND_NO_SESSION')) {
    // پارامترهای امن کوکی نشست
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'httponly' => true,   // جلوگیری از دسترسی جاوااسکریپت به کوکی
        'samesite' => 'Lax',  // محافظت CSS
    ]);
    session_name('SAHANDSESS');
    session_start();
}
