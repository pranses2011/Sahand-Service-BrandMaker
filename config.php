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
define('SAHAND_VERSION', '2.8.0');              // نسخه سیستم (۲.۸.۰ — خطایاب صددرصد وب‌محور + پارس جدول کدها + تحویل سند تلگرام + bidi و فونت OG)
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
 * (str_contains و هم‌خانواده‌هایش فقط در PHP ۸+ تعریف شده‌اند)
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
 * ⚡ پلی‌فیل json_validate — بومی PHP ۸.۳
 * روی PHP ۸.۳ نسخه بومی ( بسیار سریع‌تر از json_decode کامل)
 * استفاده می‌شود؛ روی نسخه‌های قدیمی‌تر جایگزین معادل فعال است.
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
 * ⚠️ مدیریت خطاها و استثناها — بهینه PHP ۸.۳
 * حالت عملیاتی: هشدارهای منسوخ (E_DEPRECATED) لاگ نمی‌شوند
 * تا لاگ خطا تمیز بماند؛ در دیباگ همه‌چیز ثبت می‌شود.
 * -------------------------------------------------- */
error_reporting(SAHAND_DEBUG ? E_ALL : (E_ALL & ~E_DEPRECATED & ~E_STRICT));
ini_set('display_errors', SAHAND_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

/* ⚡ عملکرد PHP ۸+ / ۸.۳:
 * - جدید: فقط برای PHP ۸ به بالا (نسخه هاست شما ۸.۳ است)
 * - zlib خروجی را فشرده می‌کند (اگر هاست اجازه دهد)
 * - گزارش وضعیت سرور در داشبورد: نسخه، GD، OPcache و ... */
if (PHP_VERSION_ID >= 80000) {
    // فشرده‌سازی خروجی HTML/JSON برای سرعت بیشتر (در صورت فعال نبودن در سطح سرور)
    if (!ini_get('zlib.output_compression') && !in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
        @ini_set('zlib.output_compression', '1');
    }
}

set_exception_handler(function ($e) {
    // ثبت خطا در لاگ
    @error_log('[EXCEPTION] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (SAHAND_DEBUG) {
        http_response_code(500);
        echo '<pre dir="ltr">⚠ ' . htmlspecialchars((string)$e) . '</pre>';
    } else {
        // 🎯 تشخیص درخواست AJAX: اندپوینت API یا هدر X-Requested-With
        $isAjax = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false
            || (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        if ($isAjax) {
            // پاسخ JSON برای درخواست‌های ای‌جکس (رفع خطای «Unexpected token '<'» در مرورگر)
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'خطای داخلی سرور: ' . mb_substr($e->getMessage(), 0, 200),
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><body style="font-family:Tahoma;background:#f5f5f5;display:flex;align-items:center;justify-content:center;height:100vh"><div style="text-align:center"><h2>⚠ خطای داخلی سرور</h2><p>لطفاً بعداً تلاش کنید یا با مدیر تماس بگیرید.</p></div></body></html>';
        }
    }
    exit;
});

/* --------------------------------------------------
 * 🗃️ مهاجرت خودکار سبک دیتابیس (v2.6+)
 * ستون‌های جدید بدون نیاز به اجرای SQL دستی اضافه می‌شوند؛
 * فقط یک بار (با نشانگر cache/.schema_v26) اجرا می‌شود.
 * -------------------------------------------------- */
if (!defined('SAHAND_NO_DB_MIGRATE')) {
    try {
        $migrateMarker = ROOT_PATH . '/cache/.schema_v26';
        if (!file_exists($migrateMarker)) {
            /* 🛡️ اتصال آزمایشی مستقیم (قابل گرفتن) — چون Database::getInstance
               در خطای اتصال die می‌کند و نباید نصب تازه/محیط بدون DB را بشکند */
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
            $probe = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
            $probe = null;
            $pdo = Database::getInstance()->pdo();
            // 🆕 v2.6: ستون تصویر OG مقاله
            $cols = $pdo->query("SHOW COLUMNS FROM `brand_articles` LIKE 'og_image'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE `brand_articles` ADD COLUMN `og_image` VARCHAR(500) NULL COMMENT 'تصویر OG تولیدی AI' AFTER `featured_image`");
            }
            // 🆕 v2.6: ستون‌های ۱۴ فیلدی کدهای خطا
            $ecCols = [
                ['subtype', "VARCHAR(255) NULL COMMENT 'زیرنوع دستگاه'"],
                ['models', "JSON NULL COMMENT 'مدل‌های دارای این کد'"],
                ['category', "VARCHAR(100) NULL COMMENT 'نوع خطا (سنسور/موتور/برد/...)'"],
                ['related_part', "VARCHAR(255) NULL COMMENT 'قطعه مربوطه'"],
                ['tech_specs', "TEXT NULL COMMENT 'مشخصات فنی قطعه'"],
                ['part_location', "VARCHAR(500) NULL COMMENT 'محل قرارگیری قطعه'"],
                ['source', "VARCHAR(50) NULL COMMENT 'منبع: kb|web|manual'"],
                ['source_urls', "JSON NULL COMMENT 'منابع آنلاین استخراج'"],
            ];
            foreach ($ecCols as [$col, $def]) {
                $exists = $pdo->query("SHOW COLUMNS FROM `error_codes` LIKE '" . $col . "'")->fetchAll();
                if (empty($exists)) {
                    $pdo->exec("ALTER TABLE `error_codes` ADD COLUMN `" . $col . "` " . $def);
                }
            }
            // 🆕 v2.6: سطح اهمیت «اطلاعاتی» برای کدهای خطا
            $sevCol = $pdo->query("SHOW COLUMNS FROM `error_codes` LIKE 'severity'")->fetch();
            if ($sevCol && stripos((string)($sevCol['Type'] ?? ''), 'informational') === false) {
                $pdo->exec("ALTER TABLE `error_codes` MODIFY `severity` ENUM('low','medium','high','critical','informational') NOT NULL DEFAULT 'medium'");
            }
            @file_put_contents($migrateMarker, date('Y-m-d H:i:s'));
        }
    } catch (Throwable $schemaE) {
        // نصب تازه (install.php) یا دسترسی محدود — بی‌صدا رد می‌شود
    }
}

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
