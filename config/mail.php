<?php
/**
 * ⚙️ ایمیل و SMTP
 * سقف‌ها و تایم‌اوت‌های ارسال ایمیل (تنظیمات حساب SMTP در پنل ذخیره می‌شود)
 * 🧩 v2.43 (S12): از config.php تک‌عظیم تفکیک شد — config.php نقش Bootstrap دارد.
 * @package SahandBrandMaker\Config
 */

define('MAIL_CONNECT_TIMEOUT', 8);        // ⏱️ مهلت اتصال SMTP (ثانیه)
define('MAIL_TIMEOUT', 25);               // ⏱️ مهلت کل ارسال ایمیل (ثانیه)
define('MAIL_MAX_ATTACH_MB', 9);          // 📎 سقف حجم پیوست ایمیل (مگابایت)
