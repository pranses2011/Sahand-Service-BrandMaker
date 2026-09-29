<?php
/**
 * ⚙️ اتصال دیتابیس (MySQL/MariaDB)
 * مقادیر واقعی پس از نصب در config.local.php
 * 🧩 v2.43 (S12): از config.php تک‌عظیم تفکیک شد — config.php نقش Bootstrap دارد.
 * @package SahandBrandMaker\Config
 */

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