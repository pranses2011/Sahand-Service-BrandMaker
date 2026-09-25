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
define('SAHAND_VERSION', '2.14.0');             // نسخه سیستم (۲.۱۴.۰ — عکس واقعی AI مرتبط با موضوع مقاله + جایگزینی تصاویر در تولید مجدد + اعداد فارسی OG + فونت در همه پیش‌نمایش‌ها و سایت‌های مستقر + واترمارک نمایندگی بزرگ‌تر + ریشه‌یابی کامل خطایاب (کدهای خط‌تیره‌دار + استخراج ساختاریافته + ترجمه فارسی فیلدها) + نوار پیشرفت زنده خطایاب + قالب‌ساز ۸۶ عنصر با تنظیمات اعلانی کامل + رفع دکمه‌های نوع نمایش + رفع باگ‌های استقرار و Cron API2)
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
 * 🚀 مهاجرت افزونه استقرار خودکار (v2.11+)
 * جداول cpanel/deployments/backups/... و ستون‌های جدید brands؛
 * فقط یک بار (با نشانگر cache/.schema_v211) اجرا می‌شود.
 * -------------------------------------------------- */
if (!defined('SAHAND_NO_DB_MIGRATE')) {
    try {
        $deployMarker = ROOT_PATH . '/cache/.schema_v211';
        if (!file_exists($deployMarker)) {
            $dsn2 = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
            $probe2 = new PDO($dsn2, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
            $probe2 = null;
            $pdo = Database::getInstance()->pdo();

            /* 🆕 v2.11: ستون‌های استقرار در جدول brands */
            $brandCols = [
                ['is_deployed',      "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'آیا استقرار شده'"],
                ['deployed_at',      "DATETIME NULL COMMENT 'تاریخ آخرین استقرار'"],
                ['deploy_method',    "ENUM('auto','manual') NULL COMMENT 'روش استقرار'"],
                ['server_path',      "VARCHAR(500) NULL COMMENT 'مسیر فایل‌ها در سرور'"],
                ['subdomain_name',   "VARCHAR(63) NULL COMMENT 'نام زیردامنه (بدون دامنه اصلی)'"],
                ['full_domain',      "VARCHAR(255) NULL COMMENT 'دامنه کامل سایت برند'"],
                ['custom_subdomain', "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'آیا نام دستی ویرایش شده'"],
                ['ssl_status',       "ENUM('active','pending','none') NOT NULL DEFAULT 'none' COMMENT 'وضعیت SSL'"],
                ['ssl_expiry',       "DATE NULL COMMENT 'تاریخ انقضای SSL'"],
                ['last_health_check',"DATETIME NULL COMMENT 'آخرین بررسی سلامت'"],
                ['health_status',    "ENUM('online','offline','error') NULL COMMENT 'وضعیت سلامت سایت'"],
            ];
            foreach ($brandCols as [$col, $def]) {
                $exists = $pdo->query("SHOW COLUMNS FROM `brands` LIKE '" . $col . "'")->fetchAll();
                if (empty($exists)) {
                    $pdo->exec("ALTER TABLE `brands` ADD COLUMN `" . $col . "` " . $def);
                }
            }

            /* 🆕 v2.11: ستون مجوز استقرار در api_keys (کلیدهای خارجی) */
            $deployKeyCol = $pdo->query("SHOW COLUMNS FROM `api_keys` LIKE 'can_deploy'")->fetchAll();
            if (empty($deployKeyCol)) {
                $pdo->exec("ALTER TABLE `api_keys` ADD COLUMN `can_deploy` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'اجازه استقرار/بکاپ از API خارجی'");
            }

            /* 🆕 v2.11: جدول تنظیمات cpanel (تک‌ردیفی id=1) */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `cpanel_settings` (
                `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `cpanel_host` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'آدرس سرور cPanel',
                `cpanel_port` SMALLINT UNSIGNED NOT NULL DEFAULT 2083 COMMENT 'پورت (پیش‌فرض HTTPS)',
                `cpanel_protocol` ENUM('http','https') NOT NULL DEFAULT 'https' COMMENT 'پروتکل',
                `cpanel_username` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'نام کاربری هاست',
                `cpanel_token_enc` TEXT NULL COMMENT 'API Token رمزنگاری‌شده AES-256',
                `root_domain` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'دامنه اصلی (مثلاً ea-fixer.ir)',
                `doc_root_pattern` VARCHAR(500) NOT NULL DEFAULT '/public_html/brands/{brand_slug}' COMMENT 'الگوی مسیر Document Root',
                `doc_root_preset` VARCHAR(50) NOT NULL DEFAULT 'default' COMMENT 'نام الگوی انتخابی',
                `deploy_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'استقرار خودکار فعال؟',
                `ssl_auto` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'نصب خودکار SSL؟',
                `ssl_provider` ENUM('cpanel','letsencrypt') NOT NULL DEFAULT 'letsencrypt' COMMENT 'فراهم‌کننده SSL',
                `upload_mode` ENUM('api','ftp') NOT NULL DEFAULT 'api' COMMENT 'روش آپلود',
                `backup_before_update` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'بکاپ قبل از بروزرسانی؟',
                `auto_test` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'تست خودکار پس از استقرار؟',
                `notify_after_deploy` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'اعلان پس از استقرار؟',
                `max_upload_mb` INT UNSIGNED NOT NULL DEFAULT 50 COMMENT 'حداکثر حجم آپلود (مگابایت)',
                `timeout_seconds` INT UNSIGNED NOT NULL DEFAULT 120 COMMENT 'Timeout عملیات (ثانیه)',
                `ftp_host` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'آدرس سرور FTP',
                `ftp_port` SMALLINT UNSIGNED NOT NULL DEFAULT 21 COMMENT 'پورت FTP',
                `ftp_username` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'نام کاربری FTP',
                `ftp_password_enc` TEXT NULL COMMENT 'رمز FTP رمزنگاری‌شده',
                `ftp_passive` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'حالت Passive',
                `ftp_ssl` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'FTPS فعال؟',
                `backup_dir` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'پوشه بکاپ‌ها (خالی = پیش‌فرض brands/backups)',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تنظیمات اتصال cPanel و استقرار خودکار'");
            // ردیف پیش‌فرض
            $pdo->exec("INSERT IGNORE INTO `cpanel_settings` (`id`) VALUES (1)");

            /* 🆕 v2.11: جدول تاریخچه استقرارها */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `deployments` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `brand_id` INT UNSIGNED NOT NULL COMMENT 'برند',
                `action` ENUM('deploy','update','delete','ssl','backup','rollback') NOT NULL DEFAULT 'deploy' COMMENT 'نوع عملیات',
                `status` ENUM('pending','in_progress','success','failed') NOT NULL DEFAULT 'pending' COMMENT 'وضعیت',
                `current_step` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'مرحله جاری',
                `total_steps` TINYINT UNSIGNED NOT NULL DEFAULT 11 COMMENT 'تعداد مراحل',
                `subdomain` VARCHAR(63) NULL COMMENT 'نام زیردامنه',
                `full_domain` VARCHAR(255) NULL COMMENT 'دامنه کامل',
                `server_path` VARCHAR(500) NULL COMMENT 'مسیر روی سرور',
                `zip_path` VARCHAR(500) NULL COMMENT 'مسیر ZIP محلی',
                `started_at` DATETIME NULL COMMENT 'شروع',
                `completed_at` DATETIME NULL COMMENT 'پایان',
                `duration_seconds` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'مدت (ثانیه)',
                `details` TEXT NULL COMMENT 'جزئیات JSON',
                `error_message` TEXT NULL COMMENT 'پیام خطا',
                `backup_path` VARCHAR(500) NULL COMMENT 'مسیر بکاپ',
                `triggered_by` ENUM('panel','api','cron') NOT NULL DEFAULT 'panel' COMMENT 'منبع اجرا',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_deploy_brand` (`brand_id`),
                KEY `idx_deploy_status` (`status`),
                CONSTRAINT `fk_deploy_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تاریخچه استقرارها'");

            /* 🆕 v2.11: لاگ مراحل هر عملیات */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `deployment_logs` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `deployment_id` INT UNSIGNED NOT NULL COMMENT 'شناسه استقرار',
                `step` VARCHAR(100) NOT NULL COMMENT 'نام مرحله',
                `status` ENUM('success','failed','skipped','in_progress') NOT NULL DEFAULT 'success' COMMENT 'وضعیت',
                `message` TEXT NULL COMMENT 'پیام',
                `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'مدت (میلی‌ثانیه)',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'زمان',
                PRIMARY KEY (`id`),
                KEY `idx_dlog_deployment` (`deployment_id`),
                CONSTRAINT `fk_dlog_deployment` FOREIGN KEY (`deployment_id`) REFERENCES `deployments`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='لاگ جزئیات عملیات استقرار'");

            /* 🆕 v2.11: وضعیت سلامت سایت‌ها */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `site_health` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `brand_id` INT UNSIGNED NOT NULL COMMENT 'برند',
                `http_status` SMALLINT UNSIGNED NULL COMMENT 'کد HTTP',
                `response_time_ms` INT UNSIGNED NULL COMMENT 'زمان پاسخ (میلی‌ثانیه)',
                `ssl_valid` TINYINT(1) NULL COMMENT 'SSL معتبر؟',
                `ssl_expiry` DATE NULL COMMENT 'انقضای SSL',
                `api_ok` TINYINT(1) NULL COMMENT 'اتصال API سایت ساز؟',
                `status` ENUM('online','offline','error') NOT NULL DEFAULT 'error' COMMENT 'وضعیت کلی',
                `error_message` VARCHAR(500) NULL COMMENT 'خطا',
                `checked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'زمان بررسی',
                PRIMARY KEY (`id`),
                KEY `idx_health_brand` (`brand_id`, `checked_at`),
                CONSTRAINT `fk_health_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='وضعیت سلامت سایت‌های برند'");

            /* 🆕 v2.11: بکاپ‌ها — با تاریخ شمسی (طبق پرامپت تکمیلی) */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `backups` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `brand_id` INT UNSIGNED NOT NULL COMMENT 'برند',
                `filename` VARCHAR(255) NOT NULL COMMENT 'نام فایل بکاپ',
                `file_path` VARCHAR(500) NOT NULL COMMENT 'مسیر کامل روی سرور',
                `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'حجم (بایت)',
                `backup_type` ENUM('manual','auto_before_update','weekly_scheduled','before_delete') NOT NULL DEFAULT 'manual' COMMENT 'نوع بکاپ',
                `shamsi_date` VARCHAR(20) NULL COMMENT 'تاریخ شمسی (1404-03-25 14:30:45)',
                `shamsi_date_display` VARCHAR(30) NULL COMMENT 'نمایش زیبا (۱۴۰۴/۰۳/۲۵ ۱۴:۳۰)',
                `gregorian_date` DATETIME NULL COMMENT 'تاریخ میلادی معادل',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_backup_brand` (`brand_id`),
                CONSTRAINT `fk_backup_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بکاپ‌های سایت‌های برند'");

            /* 🆕 v2.11: وضعیت گواهی‌های SSL */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `ssl_certificates` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `brand_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'برند',
                `domain` VARCHAR(255) NOT NULL COMMENT 'دامنه',
                `status` ENUM('active','pending','none','expired') NOT NULL DEFAULT 'none' COMMENT 'وضعیت',
                `issuer` VARCHAR(255) NULL COMMENT 'صادرکننده',
                `issued_at` DATETIME NULL COMMENT 'تاریخ صدور',
                `expires_at` DATETIME NULL COMMENT 'تاریخ انقضا',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_ssl_domain` (`domain`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='وضعیت SSLها'");

            @file_put_contents($deployMarker, date('Y-m-d H:i:s'));
        }
    } catch (Throwable $deploySchemaE) {
        // نصب تازه یا دسترسی محدود — بی‌صدا رد می‌شود (database.sql کامل است)
    }
}

/* --------------------------------------------------
 * 🆕 مهاجرت v2.12 — فاوآیکون برند + بلوک‌های ترکیبی قالب‌ساز
 * فقط یک بار (با نشانگر cache/.schema_v212) اجرا می‌شود.
 * -------------------------------------------------- */
if (!defined('SAHAND_NO_DB_MIGRATE')) {
    try {
        $v212Marker = ROOT_PATH . '/cache/.schema_v212';
        if (!file_exists($v212Marker)) {
            $dsn3 = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
            $probe3 = new PDO($dsn3, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
            $probe3 = null;
            $pdo = Database::getInstance()->pdo();

            /* 🆕 v2.12: ستون فاوآیکون اختصاصی برند (نصب‌های قدیمی‌تر از ستون ندارند) */
            $favCol = $pdo->query("SHOW COLUMNS FROM `brands` LIKE 'favicon'")->fetchAll();
            if (empty($favCol)) {
                $pdo->exec("ALTER TABLE `brands` ADD COLUMN `favicon` VARCHAR(500) NULL COMMENT 'فاویکون برند' AFTER `logo`");
            }

            /* 🆕 v2.12: گسترش enum نوع دامنه برای پشتیبانی Addon Domain
               (ستون از قبل با ENUM('subdomain','custom') وجود دارد — مقدار addon اضافه می‌شود) */
            $dtCol = $pdo->query("SHOW COLUMNS FROM `brands` LIKE 'domain_type'")->fetch();
            if (empty($dtCol)) {
                $pdo->exec("ALTER TABLE `brands` ADD COLUMN `domain_type` ENUM('subdomain','custom','addon') NOT NULL DEFAULT 'subdomain' COMMENT 'نوع دامنه' AFTER `domain`");
            } elseif (stripos((string)($dtCol['Type'] ?? ''), 'addon') === false) {
                $pdo->exec("ALTER TABLE `brands` MODIFY `domain_type` ENUM('subdomain','custom','addon') NOT NULL DEFAULT 'subdomain' COMMENT 'نوع دامنه'");
            }

            /* 🆕 v2.12: جدول بلوک‌های ترکیبی قالب‌ساز (ذخیره/بازیابی چیدمان‌های سفارشی) */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `builder_blocks` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(191) NOT NULL COMMENT 'نام بلوک ترکیبی',
                `category` VARCHAR(100) NOT NULL DEFAULT 'سفارشی' COMMENT 'دسته نمایش در کتابخانه',
                `block_json` LONGTEXT NOT NULL COMMENT 'JSON کامل بلوک با ستون‌های تودرتو',
                `usage_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'دفعات استفاده',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_bblock_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بلوک‌های ترکیبی ذخیره‌شده قالب‌ساز'");

            @file_put_contents($v212Marker, date('Y-m-d H:i:s'));
        }
    } catch (Throwable $v212SchemaE) {
        // نصب تازه یا دسترسی محدود — بی‌صدا رد می‌شود
    }
}

/* --------------------------------------------------
 * 🆕 مهاجرت v2.15 — خودترمیمی پرچم‌های استقرار ناسازگار
 * ریشه‌یابی باگ «این برند هنوز استقرار خودکار ندارد»: برندهایی که
 * is_deployed=1 دارند اما server_path خالی است (میراث نسخه‌های قدیمی یا
 * استقرار ناتمام)، در دیالوگ «بروزرسانی» باز می‌شدند و شروع عملیات با
 * خطای queueUpdate رد می‌شد. پرچم ناسازگار = استقرار واقعی نیست → صفر می‌شود
 * تا دیالوگ درست «استقرار جدید» نشان دهد. فقط یک بار (cache/.schema_v215).
 * -------------------------------------------------- */
if (!defined('SAHAND_NO_DB_MIGRATE')) {
    try {
        $v215Marker = ROOT_PATH . '/cache/.schema_v215';
        if (!file_exists($v215Marker)) {
            $dsn4 = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
            $probe4 = new PDO($dsn4, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
            $probe4 = null;
            $pdo = Database::getInstance()->pdo();

            /* 🩺 پرچم استقرار بدون مسیر سرور = داده ناسازگار → ریست به «بدون استقرار» */
            $pdo->exec("UPDATE `brands` SET `is_deployed` = 0, `deploy_method` = NULL, `deployed_at` = NULL
                WHERE `is_deployed` = 1 AND (`server_path` IS NULL OR TRIM(`server_path`) = '')");

            @file_put_contents($v215Marker, date('Y-m-d H:i:s'));
        }
    } catch (Throwable $v215SchemaE) {
        // نصب تازه یا دسترسی محدود — بی‌صدا رد می‌شود
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
