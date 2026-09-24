-- ============================================================
-- 🗄️ اسکیمای دیتابیس سایت ساز برند سهند سرویس
-- ============================================================
-- نسخه: 1.0.0 | انکودینگ: utf8mb4 (پشتیبانی کامل زبان فارسی)
-- این فایل توسط install.php به صورت خودکار اجرا می‌شود
-- یا می‌توانید آن را در phpMyAdmin ایمپورت کنید.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+03:30'; -- ⏰ منطقه زمانی ایران
SET foreign_key_checks = 0;

-- ============================================================
-- 1️⃣ settings — تنظیمات عمومی سیستم (Key/Value)
-- ============================================================
CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(191) NOT NULL COMMENT 'کلید تنظیم',
  `setting_value` LONGTEXT NULL COMMENT 'مقدار (JSON یا متن)',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تنظیمات عمومی سایت ساز';

-- ============================================================
-- 2️⃣ users — کاربران پنل مدیریت
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL COMMENT 'رمزنگاری BCRYPT',
  `full_name` VARCHAR(191) NOT NULL,
  `email` VARCHAR(191) NULL,
  `role` ENUM('admin','editor') NOT NULL DEFAULT 'editor' COMMENT 'نقش کاربر',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='کاربران پنل';

-- ============================================================
-- 3️⃣ brands — برندها (رکورد هر سایت برند)
-- ============================================================
CREATE TABLE IF NOT EXISTS `brands` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name_fa` VARCHAR(255) NOT NULL COMMENT 'نام فارسی برند',
  `name_en` VARCHAR(255) NOT NULL COMMENT 'نام انگلیسی برند',
  `slug` VARCHAR(255) NOT NULL COMMENT 'اسلاگ URL',
  `logo` VARCHAR(500) NULL COMMENT 'مسیر لوگوی برند',
  `favicon` VARCHAR(500) NULL COMMENT 'فاویکون برند',
  `domain` VARCHAR(255) NULL COMMENT 'آدرس دامنه سایت برند',
  `domain_type` ENUM('subdomain','custom','addon') NOT NULL DEFAULT 'subdomain' COMMENT 'نوع دامنه (addon = دامنه الحاقی)',
  `theme_id` INT UNSIGNED NULL COMMENT 'شناسه تم اختصاصی',
  `palette_id` INT UNSIGNED NULL COMMENT 'شناسه پالت رنگ',
  `status` ENUM('draft','building','published','suspended') NOT NULL DEFAULT 'draft' COMMENT 'وضعیت سایت برند',
  `error_codes_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'فعال بودن صفحه کدهای خطا',
  `font_settings` JSON NULL COMMENT 'تنظیمات فونت برند',
  `extra_settings` JSON NULL COMMENT 'تنظیمات تکمیلی برند',
  `seo_title` VARCHAR(255) NULL COMMENT 'عنوان سئو برند',
  `seo_description` TEXT NULL COMMENT 'توضیحات سئو',
  `seo_keywords` TEXT NULL COMMENT 'کلمات کلیدی',
  `api_key` VARCHAR(64) NULL COMMENT 'کلید API سایت برند',
  `is_deployed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'آیا استقرار خودکار شده',
  `deployed_at` DATETIME NULL COMMENT 'تاریخ آخرین استقرار',
  `deploy_method` ENUM('auto','manual') NULL COMMENT 'روش استقرار',
  `server_path` VARCHAR(500) NULL COMMENT 'مسیر فایل‌ها در سرور',
  `subdomain_name` VARCHAR(63) NULL COMMENT 'نام زیردامنه (بدون دامنه اصلی)',
  `full_domain` VARCHAR(255) NULL COMMENT 'دامنه کامل سایت برند',
  `custom_subdomain` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'آیا نام دستی ویرایش شده',
  `ssl_status` ENUM('active','pending','none') NOT NULL DEFAULT 'none' COMMENT 'وضعیت SSL',
  `ssl_expiry` DATE NULL COMMENT 'تاریخ انقضای SSL',
  `last_health_check` DATETIME NULL COMMENT 'آخرین بررسی سلامت',
  `health_status` ENUM('online','offline','error') NULL COMMENT 'وضعیت سلامت سایت',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`),
  UNIQUE KEY `uk_api_key` (`api_key`),
  KEY `idx_status` (`status`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='برندها';

-- ============================================================
-- 4️⃣ brand_pages — صفحات هر برند + محتوا + سئو
-- ============================================================
CREATE TABLE IF NOT EXISTS `brand_pages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` INT UNSIGNED NOT NULL,
  `page_type` VARCHAR(50) NOT NULL COMMENT 'نوع صفحه (home/services/contact/...)',
  `slug` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'اسلاگ صفحه',
  `title` VARCHAR(255) NULL COMMENT 'عنوان صفحه',
  `content` LONGTEXT NULL COMMENT 'محتوای ساختاریافته JSON',
  `template_id` INT UNSIGNED NULL COMMENT 'قالب انتخابی صفحه',
  `layout_json` LONGTEXT NULL COMMENT 'چیدمان درگ‌اند‌دراپ (JSON)',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'فعال/غیرفعال',
  `sort_order` INT NOT NULL DEFAULT 0,
  `seo_title` VARCHAR(255) NULL,
  `seo_description` VARCHAR(500) NULL,
  `seo_keywords` TEXT NULL,
  `seo_robots` VARCHAR(50) NULL DEFAULT 'index,follow',
  `seo_canonical` VARCHAR(500) NULL,
  `og_title` VARCHAR(255) NULL,
  `og_description` VARCHAR(500) NULL,
  `og_image` VARCHAR(500) NULL,
  `seo_score` TINYINT UNSIGNED NULL DEFAULT 0 COMMENT 'امتیاز سئو ۰-۱۰۰',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_brand_page` (`brand_id`, `page_type`),
  CONSTRAINT `fk_pages_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='صفحات سایت برند';

-- ============================================================
-- 5️⃣ brand_devices — دستگاه‌هایی که برند تولید/خدمات می‌دهد
-- ============================================================
CREATE TABLE IF NOT EXISTS `brand_devices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` INT UNSIGNED NOT NULL,
  `device_key` VARCHAR(100) NOT NULL COMMENT 'کلید دستگاه (washing_machine/...)',
  `name_fa` VARCHAR(191) NOT NULL COMMENT 'نام فارسی دستگاه',
  `icon` VARCHAR(255) NULL COMMENT 'آیکون دستگاه',
  `description` TEXT NULL COMMENT 'توضیح یکتای AI',
  `is_featured` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'نمایش در صفحه اصلی',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_brand_device` (`brand_id`, `device_key`),
  CONSTRAINT `fk_devices_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='دستگاه‌های برند';

-- ============================================================
-- 6️⃣ article_categories — دسته‌بندی مقالات
-- ============================================================
CREATE TABLE IF NOT EXISTS `article_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name_fa` VARCHAR(191) NOT NULL COMMENT 'نام دسته',
  `slug` VARCHAR(191) NOT NULL,
  `description` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cat_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='دسته‌بندی مقالات';

-- ============================================================
-- 7️⃣ brand_articles — مقالات برندها
-- ============================================================
CREATE TABLE IF NOT EXISTS `brand_articles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(500) NOT NULL COMMENT 'عنوان مقاله',
  `slug` VARCHAR(500) NOT NULL COMMENT 'اسلاگ',
  `content` LONGTEXT NULL COMMENT 'محتوای HTML',
  `excerpt` VARCHAR(1000) NULL COMMENT 'خلاصه',
  `featured_image` VARCHAR(500) NULL COMMENT 'تصویر شاخص',
  `og_image` VARCHAR(500) NULL COMMENT 'تصویر OG تولیدی AI (قابل تعویض)',
  `category_ids` JSON NULL COMMENT 'شناسه دسته‌بندی‌ها',
  `tags` JSON NULL COMMENT 'تگ‌ها',
  `status` ENUM('draft','scheduled','published') NOT NULL DEFAULT 'draft',
  `published_at` DATETIME NULL COMMENT 'زمان انتشار (برای زمان‌بندی)',
  `views` INT UNSIGNED NOT NULL DEFAULT 0,
  `generated_by_ai` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'تولیدشده توسط AI',
  `uniqueness_hash` CHAR(64) NULL COMMENT 'هش یکتایی محتوا',
  `seo_title` VARCHAR(255) NULL,
  `seo_description` VARCHAR(500) NULL,
  `seo_keywords` TEXT NULL,
  `seo_score` TINYINT UNSIGNED NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_article_brand` (`brand_id`, `status`),
  KEY `idx_article_published` (`published_at`),
  KEY `idx_article_slug` (`slug`(191)),
  KEY `idx_uniqueness` (`uniqueness_hash`),
  CONSTRAINT `fk_articles_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مقالات برندها';

-- ============================================================
-- 8️⃣ scheduled_posts — پست‌های زمان‌بندی شده
-- ============================================================
CREATE TABLE IF NOT EXISTS `scheduled_posts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` INT UNSIGNED NOT NULL,
  `article_id` INT UNSIGNED NULL,
  `task_type` ENUM('publish_article','rebuild_site','sitemap_refresh') NOT NULL DEFAULT 'publish_article',
  `run_at` DATETIME NOT NULL COMMENT 'زمان اجرا',
  `is_done` TINYINT(1) NOT NULL DEFAULT 0,
  `executed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sched_run` (`run_at`, `is_done`),
  CONSTRAINT `fk_sched_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='زمان‌بندی انتشار';

-- ============================================================
-- 9️⃣ error_codes — کدهای خطای دستگاه‌ها
-- ============================================================
CREATE TABLE IF NOT EXISTS `error_codes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` INT UNSIGNED NULL COMMENT 'خالی = عمومی برای همه برندها',
  `device_key` VARCHAR(100) NOT NULL COMMENT 'کلید دستگاه',
  `code` VARCHAR(50) NOT NULL COMMENT 'کد خطا (مثلاً E1)',
  `title` VARCHAR(255) NOT NULL COMMENT 'عنوان خطا',
  `description` TEXT NULL COMMENT 'شرح کامل',
  `causes` JSON NULL COMMENT 'دلایل احتمالی',
  `solutions` JSON NULL COMMENT 'راه‌حل‌ها',
  `subtype` VARCHAR(255) NULL COMMENT 'زیرنوع دستگاه (اینورتر، فرانت‌لود و ...)',
  `models` JSON NULL COMMENT 'مدل‌های دارای این کد',
  `category` VARCHAR(100) NULL COMMENT 'نوع خطا (سنسور/موتور/برد/...)',
  `related_part` VARCHAR(255) NULL COMMENT 'قطعه مربوطه',
  `tech_specs` TEXT NULL COMMENT 'مشخصات فنی قطعه',
  `part_location` VARCHAR(500) NULL COMMENT 'محل قرارگیری قطعه',
  `severity` ENUM('low','medium','high','critical','informational') NOT NULL DEFAULT 'medium' COMMENT 'سطح اهمیت',
  `source` VARCHAR(50) NULL COMMENT 'منبع: kb|web|manual',
  `source_urls` JSON NULL COMMENT 'منابع آنلاین استخراج',
  `needs_technician` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'نیاز به تکنسین',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ecode_brand` (`brand_id`, `device_key`),
  KEY `idx_ecode_code` (`code`),
  CONSTRAINT `fk_ecode_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='کدهای خطای دستگاه‌ها';

-- ============================================================
-- 🔟 service_requests — درخواست‌های خدمات
-- ============================================================
CREATE TABLE IF NOT EXISTS `service_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` INT UNSIGNED NOT NULL COMMENT 'برند مبدأ درخواست',
  `full_name` VARCHAR(255) NOT NULL COMMENT 'نام و نام خانوادگی',
  `phone` VARCHAR(20) NOT NULL COMMENT 'شماره تماس',
  `phone2` VARCHAR(20) NULL COMMENT 'شماره تماس دوم',
  `address` TEXT NOT NULL COMMENT 'آدرس',
  `device_key` VARCHAR(100) NOT NULL COMMENT 'نوع دستگاه',
  `device_other` VARCHAR(191) NULL COMMENT 'توضیح دستگاه سایر',
  `device_model` VARCHAR(191) NULL COMMENT 'مدل دستگاه',
  `description` TEXT NOT NULL COMMENT 'شرح ایراد',
  `preferred_date` DATE NULL COMMENT 'تاریخ مراجعه ترجیحی',
  `preferred_time` VARCHAR(50) NULL COMMENT 'بازه ساعتی',
  `status` ENUM('new','reviewing','assigned','done','canceled') NOT NULL DEFAULT 'new',
  `admin_note` TEXT NULL COMMENT 'یادداشت مدیر',
  `ip_address` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_req_brand` (`brand_id`, `status`),
  KEY `idx_req_created` (`created_at`),
  CONSTRAINT `fk_req_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='درخواست‌های خدمات';

-- 1️⃣1️⃣ request_attachments — تصاویر پیوست درخواست
CREATE TABLE IF NOT EXISTS `request_attachments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` INT UNSIGNED NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attach_req` (`request_id`),
  CONSTRAINT `fk_attach_req` FOREIGN KEY (`request_id`) REFERENCES `service_requests`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='پیوست‌های درخواست';

-- ============================================================
-- 1️⃣2️⃣ templates — قالب‌های صفحات
-- ============================================================
CREATE TABLE IF NOT EXISTS `templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL COMMENT 'نام قالب',
  `page_type` VARCHAR(50) NOT NULL COMMENT 'نوع صفحه هدف',
  `variant` VARCHAR(100) NULL COMMENT 'نوع طرح (modern/classic/...)',
  `thumbnail` VARCHAR(500) NULL COMMENT 'تصویر پیش‌نمایش',
  `layout_json` LONGTEXT NULL COMMENT 'ساختار بلوک‌ها',
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tpl_page` (`page_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قالب‌های صفحات';

-- 1️⃣2️⃣-ب builder_blocks — بلوک‌های ترکیبی ذخیره‌شده قالب‌ساز (v2.12)
CREATE TABLE IF NOT EXISTS `builder_blocks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL COMMENT 'نام بلوک ترکیبی',
  `category` VARCHAR(100) NOT NULL DEFAULT 'سفارشی' COMMENT 'دسته نمایش در کتابخانه',
  `block_json` LONGTEXT NOT NULL COMMENT 'JSON کامل بلوک با ستون‌های تودرتو',
  `usage_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'دفعات استفاده',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bblock_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بلوک‌های ترکیبی ذخیره‌شده قالب‌ساز';

-- 1️⃣3️⃣ themes — تم‌ها (ترکیب قالب‌ها)
CREATE TABLE IF NOT EXISTS `themes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL,
  `description` TEXT NULL,
  `config_json` JSON NULL COMMENT 'ترکیب قالب صفحات مختلف',
  `thumbnail` VARCHAR(500) NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تم‌ها';

-- ============================================================
-- 1️⃣4️⃣ color_palettes — پالت رنگ استخراج‌شده از لوگو
-- ============================================================
CREATE TABLE IF NOT EXISTS `color_palettes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` INT UNSIGNED NOT NULL,
  `light_palette` JSON NOT NULL COMMENT 'متغیرهای تم روشن',
  `dark_palette` JSON NOT NULL COMMENT 'متغیرهای تم تاریک',
  `dominant_colors` JSON NULL COMMENT 'رنگ‌های غالب لوگو',
  `is_manual_edited` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_palette_brand` (`brand_id`),
  CONSTRAINT `fk_palette_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='پالت رنگ برندها';

-- ============================================================
-- 1️⃣5️⃣ icon_packs — پک‌های آیکون
-- ============================================================
CREATE TABLE IF NOT EXISTS `icon_packs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL COMMENT 'نام پک',
  `slug` VARCHAR(191) NOT NULL,
  `description` TEXT NULL,
  `icon_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_builtin` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'پک داخلی سیستمی',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pack_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='پک‌های آیکون';

-- 1️⃣6️⃣ icons — آیکون‌های تکی
CREATE TABLE IF NOT EXISTS `icons` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pack_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(191) NOT NULL COMMENT 'نام آیکون (انگلیسی)',
  `label_fa` VARCHAR(191) NULL COMMENT 'برچسب فارسی',
  `file_path` VARCHAR(500) NOT NULL COMMENT 'مسیر فایل SVG',
  `category` VARCHAR(100) NULL COMMENT 'دسته‌بندی',
  `tags` JSON NULL COMMENT 'کلیدواژه‌های جستجو',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_icon_pack` (`pack_id`),
  KEY `idx_icon_name` (`name`),
  CONSTRAINT `fk_icon_pack` FOREIGN KEY (`pack_id`) REFERENCES `icon_packs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='آیکون‌ها';

-- ============================================================
-- 1️⃣7️⃣ fonts — فونت‌ها
-- ============================================================
CREATE TABLE IF NOT EXISTS `fonts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL COMMENT 'نام فونت',
  `slug` VARCHAR(191) NOT NULL,
  `type` ENUM('fa','en') NOT NULL DEFAULT 'fa' COMMENT 'فارسی یا انگلیسی',
  `weights` JSON NULL COMMENT 'وزن‌های موجود',
  `files` JSON NULL COMMENT 'مسیر فایل‌های هر وزن',
  `category` VARCHAR(100) NULL COMMENT 'کاربرد (عمومی/تیتر/...)',
  `is_builtin` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_font_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فونت‌ها';

-- ============================================================
-- 1️⃣8️⃣ menus و menu_items — منوهای ناوبری
-- ============================================================
CREATE TABLE IF NOT EXISTS `menus` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` INT UNSIGNED NULL COMMENT 'خالی = منوی عمومی پیش‌فرض',
  `location` ENUM('header','footer') NOT NULL DEFAULT 'header' COMMENT 'محل نمایش منو',
  `name` VARCHAR(191) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_menu_brand` (`brand_id`, `location`),
  CONSTRAINT `fk_menu_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='منوها';

CREATE TABLE IF NOT EXISTS `menu_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `menu_id` INT UNSIGNED NOT NULL,
  `parent_id` INT UNSIGNED NULL COMMENT 'والد (برای زیرمنو)',
  `title` VARCHAR(191) NOT NULL COMMENT 'عنوان آیتم',
  `url` VARCHAR(500) NULL COMMENT 'لینک سفارشی',
  `page_type` VARCHAR(50) NULL COMMENT 'اتصال به صفحه سیستمی',
  `icon` VARCHAR(255) NULL COMMENT 'آیکون آیتم',
  `nofollow` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_mitem_menu` (`menu_id`, `sort_order`),
  CONSTRAINT `fk_mitem_menu` FOREIGN KEY (`menu_id`) REFERENCES `menus`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='آیتم‌های منو';

-- ============================================================
-- 1️⃣9️⃣ seo_settings — تنظیمات سئوی عمومی
-- ============================================================
CREATE TABLE IF NOT EXISTS `seo_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope` ENUM('global','brand','page','article') NOT NULL DEFAULT 'global',
  `scope_id` INT UNSIGNED NULL COMMENT 'شناسه رکورد مرتبط',
  `title_template` VARCHAR(255) NULL COMMENT 'الگوی عنوان {{brand}} | سهند سرویس',
  `meta_description` VARCHAR(500) NULL,
  `meta_keywords` TEXT NULL,
  `og_image_default` VARCHAR(500) NULL,
  `robots_txt` TEXT NULL COMMENT 'محتوای سفارشی robots',
  `extra` JSON NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_seo_scope` (`scope`, `scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تنظیمات سئو';

-- ============================================================
-- 2️⃣0️⃣ webmaster_tags — تگ‌های تأیید وبمستر
-- ============================================================
CREATE TABLE IF NOT EXISTS `webmaster_tags` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_key` VARCHAR(50) NOT NULL COMMENT 'کلید سرویس (google/bing/...)',
  `service_name` VARCHAR(100) NOT NULL COMMENT 'نام سرویس',
  `meta_name` VARCHAR(100) NOT NULL COMMENT 'نام متاتگ',
  `content` VARCHAR(500) NULL COMMENT 'محتوای تگ',
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_wmt_service` (`service_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تگ‌های وبمستر';

-- ============================================================
-- 2️⃣1️⃣ visits و visit_details — سیستم آمار داخلی
-- ============================================================
CREATE TABLE IF NOT EXISTS `visits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` INT UNSIGNED NOT NULL,
  `session_hash` CHAR(64) NOT NULL COMMENT 'هش نشست (بدون IP خام — حفظ حریم خصوصی)',
  `ip_hash` CHAR(64) NULL COMMENT 'هش IP برای شمارش یکتا',
  `ip_prefix` VARCHAR(20) NULL COMMENT 'پیشوند IP برای GeoIP محلی',
  `user_agent` VARCHAR(500) NULL,
  `device_type` ENUM('mobile','desktop','tablet','bot') NOT NULL DEFAULT 'desktop',
  `browser` VARCHAR(100) NULL,
  `browser_version` VARCHAR(30) NULL,
  `os` VARCHAR(100) NULL,
  `resolution` VARCHAR(20) NULL,
  `language` VARCHAR(10) NULL,
  `country` VARCHAR(5) NULL COMMENT 'کد کشور از GeoIP محلی',
  `city` VARCHAR(100) NULL COMMENT 'شهر از GeoIP محلی',
  `referrer` VARCHAR(500) NULL COMMENT 'صفحه ارجاع‌دهنده',
  `search_keyword` VARCHAR(255) NULL COMMENT 'کلمه جستجو (در صورت ورود از موتور)',
  `entry_page` VARCHAR(500) NULL,
  `visit_date` DATE NOT NULL,
  `visited_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_visit_brand_date` (`brand_id`, `visit_date`),
  KEY `idx_visit_session` (`session_hash`),
  KEY `idx_visit_country` (`country`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بازدیدها';

CREATE TABLE IF NOT EXISTS `visit_details` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visit_id` BIGINT UNSIGNED NOT NULL,
  `page_url` VARCHAR(500) NOT NULL COMMENT 'صفحه بازدیدشده',
  `page_title` VARCHAR(255) NULL,
  `duration` INT UNSIGNED NULL COMMENT 'مدت حضور (ثانیه)',
  `is_exit` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'صفحه خروج',
  `viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vdetail_visit` (`visit_id`),
  KEY `idx_vdetail_page` (`page_url`(191)),
  CONSTRAINT `fk_vdetail_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جزئیات بازدید صفحات';

-- ============================================================
-- 2️⃣2️⃣ notifications — اعلان‌های پنل
-- ============================================================
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL COMMENT 'گیرنده (خالی = همه)',
  `type` ENUM('request','system','seo','security','article') NOT NULL DEFAULT 'system',
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NULL,
  `link` VARCHAR(500) NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_read` (`is_read`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='اعلان‌ها';

-- ============================================================
-- 2️⃣3️⃣ activity_logs — لاگ فعالیت کاربران
-- ============================================================
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `action` VARCHAR(191) NOT NULL COMMENT 'عنوان اقدام',
  `details` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='لاگ فعالیت‌ها';

-- ============================================================
-- 2️⃣4️⃣ brand_links — لینک‌دهی بین برندها و سایت اصلی
-- ============================================================
CREATE TABLE IF NOT EXISTS `brand_links` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` INT UNSIGNED NOT NULL,
  `target_type` ENUM('main_site','other_brand','custom') NOT NULL DEFAULT 'main_site',
  `target_url` VARCHAR(500) NOT NULL,
  `anchor_text` VARCHAR(255) NULL,
  `nofollow` TINYINT(1) NOT NULL DEFAULT 0,
  `location` SET('footer','other_brands_page','sidebar') NOT NULL DEFAULT 'footer',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_blink_brand` (`brand_id`),
  CONSTRAINT `fk_blink_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='لینک‌های برند';

-- ============================================================
-- 2️⃣5️⃣ custom_pages — صفحات سفارشی برندها
-- ============================================================
CREATE TABLE IF NOT EXISTS `custom_pages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL,
  `content` LONGTEXT NULL,
  `template_id` INT UNSIGNED NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `seo_title` VARCHAR(255) NULL,
  `seo_description` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cpage_brand` (`brand_id`, `slug`),
  CONSTRAINT `fk_cpage_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='صفحات سفارشی';

-- ============================================================
-- 2️⃣6️⃣ ai_knowledge — پایگاه دانش قابل گسترش AI
-- ============================================================
CREATE TABLE IF NOT EXISTS `ai_knowledge` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `topic` VARCHAR(100) NOT NULL COMMENT 'موضوع (brand/device/template/synonym/...)',
  `key` VARCHAR(191) NOT NULL COMMENT 'کلید رکورد',
  `value` JSON NOT NULL COMMENT 'محتوای دانش',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ai_topic_key` (`topic`, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='پایگاه دانش AI';

-- ============================================================
-- 2️⃣7️⃣ api_keys — کلیدهای احراز هویت API سایت‌های برند
-- ============================================================
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` INT UNSIGNED NULL COMMENT 'برند مرتبط (خالی = کلید سیستمی)',
  `api_key` VARCHAR(64) NOT NULL,
  `label` VARCHAR(191) NULL COMMENT 'برچسب توضیحی',
  `requests_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_used_at` DATETIME NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `can_deploy` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'اجازه استقرار/بکاپ از API خارجی',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_api_key` (`api_key`),
  KEY `fk_key_brand` (`brand_id`),
  CONSTRAINT `fk_key_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='کلیدهای API';

-- ============================================================
-- 2️⃣8️⃣ rate_limits — محدودیت نرخ درخواست‌ها
-- ============================================================
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `limit_key` VARCHAR(191) NOT NULL COMMENT 'کلید (api_ + هش IP یا login_fail_ + هش نام)',
  `hit_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_limit_key` (`limit_key`),
  KEY `idx_limit_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='محدودیت نرخ';

-- ============================================================
-- 2️⃣9️⃣ service_areas — مناطق تحت پوشش خدمات
-- ============================================================
CREATE TABLE IF NOT EXISTS `service_areas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` INT UNSIGNED NULL COMMENT 'خالی = عمومی همه برندها',
  `city` VARCHAR(100) NOT NULL COMMENT 'شهر',
  `district` VARCHAR(191) NULL COMMENT 'منطقه/محله',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_area_brand` (`brand_id`),
  CONSTRAINT `fk_area_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مناطق تحت پوشش';

-- ============================================================
-- 3️⃣0️⃣ faqs — سوالات متداول
-- ============================================================
CREATE TABLE IF NOT EXISTS `faqs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` INT UNSIGNED NOT NULL,
  `question` VARCHAR(500) NOT NULL COMMENT 'سوال',
  `answer` TEXT NOT NULL COMMENT 'پاسخ',
  `generated_by_ai` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_faq_brand` (`brand_id`),
  CONSTRAINT `fk_faq_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سوالات متداول';

-- ============================================================
-- 3️⃣1️⃣ testimonials — نظرات مشتریان
-- ============================================================
CREATE TABLE IF NOT EXISTS `testimonials` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` INT UNSIGNED NULL COMMENT 'خالی = نظرات عمومی نمایندگی',
  `customer_name` VARCHAR(191) NOT NULL,
  `customer_city` VARCHAR(100) NULL,
  `rating` TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'امتیاز ۱-۵',
  `comment` TEXT NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_testi_brand` (`brand_id`),
  CONSTRAINT `fk_testi_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='نظرات مشتریان';

SET foreign_key_checks = 1;

-- ============================================================
-- 3️⃣2️⃣ cpanel_settings — تنظیمات اتصال cPanel و استقرار خودکار (v2.11)
-- ============================================================
CREATE TABLE IF NOT EXISTS `cpanel_settings` (
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
  `backup_dir` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'پوشه بکاپ‌ها (خالی = brands/backups)',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تنظیمات اتصال cPanel و استقرار خودکار';

INSERT IGNORE INTO `cpanel_settings` (`id`) VALUES (1);

-- ============================================================
-- 3️⃣3️⃣ deployments — تاریخچه استقرارها (v2.11)
-- ============================================================
CREATE TABLE IF NOT EXISTS `deployments` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تاریخچه استقرارها';

-- ============================================================
-- 3️⃣4️⃣ deployment_logs — لاگ مراحل عملیات استقرار (v2.11)
-- ============================================================
CREATE TABLE IF NOT EXISTS `deployment_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='لاگ جزئیات عملیات استقرار';

-- ============================================================
-- 3️⃣5️⃣ site_health — وضعیت سلامت سایت‌های برند (v2.11)
-- ============================================================
CREATE TABLE IF NOT EXISTS `site_health` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='وضعیت سلامت سایت‌های برند';

-- ============================================================
-- 3️⃣6️⃣ backups — بکاپ‌های سایت‌های برند با تاریخ شمسی (v2.11)
-- ============================================================
CREATE TABLE IF NOT EXISTS `backups` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بکاپ‌های سایت‌های برند';

-- ============================================================
-- 3️⃣7️⃣ ssl_certificates — وضعیت گواهی‌های SSL (v2.11)
-- ============================================================
CREATE TABLE IF NOT EXISTS `ssl_certificates` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='وضعیت SSLها';

-- ============================================================
-- 🌱 داده‌های اولیه (Seed Data)
-- ============================================================

-- 📇 دسته‌بندی‌های پیش‌فرض مقالات
INSERT IGNORE INTO `article_categories` (`id`, `name_fa`, `slug`, `description`) VALUES
(1, 'رفع ایراد', 'troubleshooting', 'راهنمای رفع ایرادهای رایج دستگاه‌ها'),
(2, 'راهنمای استفاده', 'user-guide', 'آموزش استفاده صحیح از لوازم خانگی'),
(3, 'نگهداری', 'maintenance', 'نکات نگهداری و افزایش عمر دستگاه‌ها'),
(4, 'عیب‌یابی', 'diagnostics', 'روش‌های عیب‌یابی و تشخیص خطا'),
(5, 'مقالات عمومی', 'general', 'مقالات عمومی لوازم خانگی');

-- 🏷️ تگ‌های وبمستر پیش‌فرض
INSERT IGNORE INTO `webmaster_tags` (`service_key`, `service_name`, `meta_name`, `content`, `is_active`) VALUES
('google', 'Google Search Console', 'google-site-verification', NULL, 0),
('bing', 'Bing Webmaster', 'msvalidate.01', NULL, 0),
('yandex', 'Yandex Webmaster', 'yandex-verification', NULL, 0),
('pinterest', 'Pinterest', 'p:domain_verify', NULL, 0),
('alexa', 'Alexa', 'alexaVerifyID', NULL, 0);

-- ⚙️ تنظیمات اولیه سیستم
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('agency_name_fa', 'سهند سرویس'),
('agency_name_en', 'Sahand Service'),
('agency_slogan_fa', 'خدمات پس از فروش حرفه‌ای لوازم خانگی'),
('agency_slogan_en', 'Professional After-Sales Service'),
('agency_main_site', 'https://ea-fixer.ir'),
('brand_domain_format', '{brand}.ea-fixer.ir'),
('cost_settings', '{"show_cost": false, "replacement_text": "هزینه پس از بررسی و ایرادیابی دستگاه اعلام می‌شود", "free_diagnosis_text": "ایرادیابی رایگان"}'),
('notify_email_settings', '{"enabled": false, "to": "", "subject": "درخواست خدمات جدید"}'),
('notify_telegram_settings', '{"enabled": false, "bot_token": "", "chat_id": ""}'),
('notify_gscript_settings', '{"enabled": false, "webapp_url": ""}'),
('notify_bale_settings', '{"enabled": false, "bot_token": "", "chat_id": ""}'),
('link_settings', '{"main_site_footer": true, "other_brands_page": true, "default_nofollow": true}'),
('work_hours', '{"start": "09:00", "end": "20:00", "days": ["sat","sun","mon","tue","wed","thu"], "holidays": ["fri"], "off_message": "در حال حاضر خارج از ساعات کاری هستیم؛ درخواست شما ثبت شد و در اولین زمان کاری پاسخ داده می‌شود."}'),
('websearch_settings', '{"enabled": true, "timeout": 12, "connect_timeout": 5, "max_results": 8, "cache_ttl": 1800, "rate_per_hour": 60, "providers": [], "serpapi_key": "", "google_cse_key": "", "google_cse_cx": "", "bing_api_key": ""}'),
('telegram_bot_settings', '{"enabled": false, "bot_token": "", "allowed_chat_ids": "", "webhook_secret": "", "notify_new_request": true, "welcome_text": "سلام! من دستیار هوشمند سهند سرویس هستم. هر سؤال یا دستور فارسی بنویسید تا کمکتان کنم."}');
