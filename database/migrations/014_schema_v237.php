<?php
/**
 * 🗃️ مهاجرت schema_v237 — استخراج‌شده از config.php (v2.43 / S14)
 * 
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v237',
    'order' => 14,
    'up'    => static function (): void {
            $pdo = Database::getInstance()->pdo();

            /* ① دیدگاه مقالات — تأییدیه‌ای به‌صورت پیش‌فرض (anti-spam)،
 * پاسخ برند با parent_id و is_brand_reply، بازداشت IP متخلف */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `article_comments` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `brand_id` INT UNSIGNED NOT NULL,
                `article_id` INT UNSIGNED NOT NULL,
                `parent_id` BIGINT UNSIGNED NULL COMMENT 'پاسخ به دیدگاه (سلسله‌مراتب تک‌سطح)',
                `is_brand_reply` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'پاسخ رسمی برند (ثبت پنل)',
                `author_name` VARCHAR(120) NOT NULL,
                `author_email` VARCHAR(190) NULL,
                `body` TEXT NOT NULL,
                `status` ENUM('pending','approved','spam') NOT NULL DEFAULT 'pending',
                `ip` VARCHAR(60) NULL,
                `user_agent` VARCHAR(300) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `approved_at` DATETIME NULL,
                `approved_by` INT UNSIGNED NULL,
                PRIMARY KEY (`id`),
                KEY `idx_comment_article` (`article_id`, `status`, `created_at`),
                KEY `idx_comment_brand` (`brand_id`, `status`),
                KEY `idx_comment_status` (`status`, `created_at`),
                CONSTRAINT `fk_comment_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='دیدگاه مقالات سایت برند (تأییدیه‌ای)'");

            /* ② وب‌هوک‌های خروجی — brand_id NULL یعنی «همه برندها» (گلوبال) */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `webhooks` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `brand_id` INT UNSIGNED NULL COMMENT 'NULL = همه برندها (گلوبال)',
                `label` VARCHAR(190) NOT NULL COMMENT 'نام قابل‌فهم برای پنل',
                `url` VARCHAR(500) NOT NULL COMMENT 'مقصد POST (https ترجیحی)',
                `events` VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'رویدادها با کاما: article.published,request.created,...',
                `secret` CHAR(64) NOT NULL COMMENT 'رمز امضای HMAC-SHA256',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_webhook_brand` (`brand_id`, `is_active`),
                KEY `idx_webhook_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='وب‌هوک‌های خروجی سایت ساز'");

            /* ③ لاگ تحویل — صف با backoff نمایی؛ cron هر ۵ دقیقه دوباره می‌فرستد */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `webhook_deliveries` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `webhook_id` INT UNSIGNED NOT NULL,
                `event` VARCHAR(60) NOT NULL,
                `payload` LONGTEXT NOT NULL,
                `status` ENUM('pending','delivered','failed') NOT NULL DEFAULT 'pending',
                `response_code` SMALLINT UNSIGNED NULL,
                `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `next_retry_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `delivered_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                KEY `idx_wd_retry` (`status`, `next_retry_at`),
                KEY `idx_wd_hook` (`webhook_id`, `created_at`),
                CONSTRAINT `fk_wd_hook` FOREIGN KEY (`webhook_id`) REFERENCES `webhooks`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='لاگ/صف تحویل وب‌هوک با تلاش مجدد'");

            /* ④ تست A/B — element: hero_title | hero_cta؛ تقسیم بازدیدکننده با هش پایدار */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `ab_tests` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `brand_id` INT UNSIGNED NOT NULL,
                `name` VARCHAR(190) NOT NULL COMMENT 'نام آزمایش برای پنل',
                `element` ENUM('hero_title','hero_cta') NOT NULL DEFAULT 'hero_title',
                `variant_a` VARCHAR(500) NOT NULL COMMENT 'متن واریانت A (پایه)',
                `variant_b` VARCHAR(500) NOT NULL COMMENT 'متن واریانت B',
                `status` ENUM('running','paused','finished') NOT NULL DEFAULT 'running',
                `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `ended_at` DATETIME NULL,
                `winner` CHAR(1) NULL COMMENT 'A/B بعد از اتمام (NULL = نامشخص)',
                `created_by` INT UNSIGNED NULL,
                PRIMARY KEY (`id`),
                KEY `idx_ab_brand` (`brand_id`, `status`),
                CONSTRAINT `fk_ab_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تست A/B عنوان هیرو / دکمه CTA'");

            /* ⑤ رویدادهای A/B — visitor_hash پایدار (کوکی) + نوع رویداد view/click */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `ab_events` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `test_id` INT UNSIGNED NOT NULL,
                `variant` CHAR(1) NOT NULL COMMENT 'A یا B',
                `visitor_hash` CHAR(32) NOT NULL COMMENT 'هش پایدار بازدیدکننده (کوکی)',
                `event` ENUM('view','click') NOT NULL DEFAULT 'view',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_abev_test` (`test_id`, `event`),
                KEY `idx_abev_visitor` (`test_id`, `visitor_hash`, `event`),
                CONSTRAINT `fk_abev_test` FOREIGN KEY (`test_id`) REFERENCES `ab_tests`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='رویدادهای view/click تست A/B'");

    },
];
