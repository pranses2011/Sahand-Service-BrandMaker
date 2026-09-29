<?php
/**
 * ⚙️ Bootstrap اصلی سایت ساز برند سهند سرویس
 * ============================================
 * 🧩 v2.43 (S12+S14 — درخواست کاربر: «config.php بیش از حد بزرگ شده»):
 * این فایل دیگر ۱۱۶۷ خطی نیست — فقط نقش بارگذاری ترتیبی دارد:
 *
 *   config/
 *   ├── app.php        ← نسخه/هویت + کانفیگ محلی + آدرس‌ها + مسیرها + بارگذار خودکار + پلی‌فیل‌ها + helpers + BASE_URL
 *   ├── database.php   ← ثابت‌های اتصال دیتابیس
 *   ├── security.php   ← ثابت‌های امنیتی + مدیریت خطا + شروع امن نشست
 *   ├── mail.php       ← سقف/تایم‌اوت ایمیل
 *   ├── telegram.php   ← تایم‌اوت‌های Bot API (تلگرام/بله)
 *   ├── ai.php         ← موتور هوش مصنوعی + فال‌بک زنجیره‌ای (S16)
 *   ├── storage.php    ← سقف آپلود + تضمین پوشه‌ها
 *   └── services.php   ← سقف‌های سرویس‌های جانبی
 *
 *   database/
 *   ├── migrations/    ← ۱۶ مهاجرت استخراج‌شده (۰۰۱ تا ۰۱۶) — اجرا با MigrationRunner
 *   └── schema.sql     ← طرح کامل برای نصب تازه
 *
 * 🏃 مهاجرت‌ها (S14): دیگر داخل مسیر درخواست اجرای سنگین نمی‌شوند؛
 * MigrationRunner::boot() با «نشانگر اثر انگشت» (یک is_file + مقایسه رشته)
 * در حالت عادی هیچ کار دیتابیسی نمی‌کند و فقط پس از آپلود نسخه جدید
 * یک‌بار زنجیره را اجرا می‌کند. اجرای دستی: admin/migrate.php یا CLI.
 *
 * @package SahandBrandMaker
 * @since 2.43.0
 */

/* ⛔ جلوگیری از دسترسی مستقیم */
if (!defined('SAHAND_INIT')) {
    http_response_code(403);
    exit('⛔ دسترسی مستقیم مجاز نیست.');
}

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/config/telegram.php';
require_once __DIR__ . '/config/ai.php';
require_once __DIR__ . '/config/storage.php';
require_once __DIR__ . '/config/services.php';

/* 🏃 S14 — مهاجرت دیتابیس با چک سریع نشانگر (خارج از مسیر درخواست عادی)
   • SAHAND_NO_DB_MIGRATE تعریف شده باشد → رد می‌شود (نصب تازه/تست)
   • نشانگر = اثر انگشت مجموعه مهاجرت‌ها → یکسان؟ هیچ کاری نکن
   • متفاوت (نسخه جدید آپلود شده) → با قفل flock یک‌بار اجرا کن */
MigrationRunner::boot();
