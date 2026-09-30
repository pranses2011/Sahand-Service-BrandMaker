<?php
/**
 * 🗃️ مهاجرت schema_v244_custom_forms — فرم‌ساز سفارشی (S12 / v2.44)
 * 🔖 درخواست کاربر: «یک قسمت فرم ساز باشه تا بتونیم فرم سفارشی برای سایتها
 * بسازیم (مانند Gravity Form) که همه نوع فیلدی داشته باشد و همه نوع
 * تنظیماتی هم براش بگذار و در قالب‌ساز عنصر فرم‌های سفارشی هم اضافه شود
 * تا بتوانیم فرم‌های ذخیره‌شده را داخل صفحات سایت بگذاریم.»
 *
 * معماری: جدول custom_forms (تعریف فرم) + ثبت ورودی‌ها در form_entries
 * موجود (form_block = 'custom' + form_slug) — یعنی کل زنجیره اعلان
 * (پنل/ایمیل/تلگرام/بله)، ضداسپم و وب‌هوک بی‌تغییر کار می‌کند.
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v244_custom_forms',
    'order' => 19,
    'up'    => static function (): void {
        $pdo = Database::getInstance()->pdo();
        $exists = $pdo->query("SHOW TABLES LIKE 'custom_forms'")->fetchAll();
        if (empty($exists)) {
            $pdo->exec("
CREATE TABLE `custom_forms` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` INT UNSIGNED NULL COMMENT 'NULL = همه برندها (فرم سراسری)',
  `title` VARCHAR(190) NOT NULL COMMENT 'عنوان فرم',
  `slug` VARCHAR(120) NOT NULL COMMENT 'شناسه لاتین یکتا (در عنصر قالب‌ساز)',
  `description` TEXT NULL COMMENT 'توضیح بالای فرم',
  `fields` MEDIUMTEXT NOT NULL COMMENT 'JSON آرایه فیلدها (نوع/برچسب/تنظیمات)',
  `settings` TEXT NULL COMMENT 'JSON تنظیمات (دکمه/پیام موفقیت/مقصد ارسال/چیدمان)',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `entries_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'شمارنده سریع ورودی‌ها',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cf_slug` (`slug`),
  KEY `idx_cf_brand` (`brand_id`),
  KEY `idx_cf_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='فرم‌ساز سفارشی (S12 / v2.44)'");
        }
        /* ستون form_slug در form_entries برای تفکیک ورودی فرم‌های سفارشی */
        $col = $pdo->query("SHOW COLUMNS FROM `form_entries` LIKE 'form_slug'")->fetchAll();
        if (empty($col)) {
            $pdo->exec("ALTER TABLE `form_entries` ADD COLUMN `form_slug` VARCHAR(120) NULL COMMENT 'شناسه فرم سفارشی (custom_forms.slug)' AFTER `form_block`, ADD KEY `idx_fe_slug` (`form_slug`)");
        }
    },
];
