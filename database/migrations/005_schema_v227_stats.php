<?php
/**
 * 🗃️ مهاجرت schema_v227_stats — استخراج‌شده از config.php (v2.43 / S14)
 * 🆕 مهاجرت v2.27 — ستون‌های آمار رفتاری (is_exit / duration)
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v227_stats',
    'order' => 5,
    'up'    => static function (): void {
$pdo = Database::getInstance()->pdo();

        /* ① جدول بازدیدها — در نصب‌های قدیمی ممکن است نباشد */
        $pdo->exec("CREATE TABLE IF NOT EXISTS `visits` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `brand_id` INT UNSIGNED NOT NULL,
            `session_hash` CHAR(64) NOT NULL,
            `ip_hash` CHAR(64) NULL,
            `ip_prefix` VARCHAR(20) NULL,
            `user_agent` VARCHAR(500) NULL,
            `device_type` ENUM('mobile','desktop','tablet','bot') NOT NULL DEFAULT 'desktop',
            `browser` VARCHAR(100) NULL,
            `browser_version` VARCHAR(30) NULL,
            `os` VARCHAR(100) NULL,
            `resolution` VARCHAR(20) NULL,
            `language` VARCHAR(10) NULL,
            `country` VARCHAR(5) NULL,
            `city` VARCHAR(100) NULL,
            `referrer` VARCHAR(500) NULL,
            `search_keyword` VARCHAR(255) NULL,
            `entry_page` VARCHAR(500) NULL,
            `visit_date` DATE NOT NULL,
            `visited_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_visit_brand_date` (`brand_id`, `visit_date`),
            KEY `idx_visit_session` (`session_hash`),
            KEY `idx_visit_country` (`country`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بازدیدها'");

        /* ② جدول جزئیات بازدید — ستون‌های رفتاری v2.26 */
        $pdo->exec("CREATE TABLE IF NOT EXISTS `visit_details` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `visit_id` BIGINT UNSIGNED NOT NULL,
            `page_url` VARCHAR(500) NOT NULL,
            `page_title` VARCHAR(255) NULL,
            `duration` INT UNSIGNED NULL COMMENT 'مدت حضور (ثانیه)',
            `is_exit` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'صفحه خروج',
            `viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_vdetail_visit` (`visit_id`),
            KEY `idx_vdetail_page` (`page_url`(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جزئیات بازدید صفحات'");

        /* ③ جدول موجود ولی قدیمی → ستون‌های غایب اضافه شوند */
        $hasExit = $pdo->query("SHOW COLUMNS FROM `visit_details` LIKE 'is_exit'")->fetchAll();
        if (empty($hasExit)) {
            $pdo->exec("ALTER TABLE `visit_details` ADD COLUMN `is_exit` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'صفحه خروج'");
        }
        $hasDur = $pdo->query("SHOW COLUMNS FROM `visit_details` LIKE 'duration'")->fetchAll();
        if (empty($hasDur)) {
            $pdo->exec("ALTER TABLE `visit_details` ADD COLUMN `duration` INT UNSIGNED NULL COMMENT 'مدت حضور (ثانیه)'");
        }
        $hasTitle = $pdo->query("SHOW COLUMNS FROM `visit_details` LIKE 'page_title'")->fetchAll();
        if (empty($hasTitle)) {
            $pdo->exec("ALTER TABLE `visit_details` ADD COLUMN `page_title` VARCHAR(255) NULL");
        }
        $hasViewed = $pdo->query("SHOW COLUMNS FROM `visit_details` LIKE 'viewed_at'")->fetchAll();
        if (empty($hasViewed)) {
            $pdo->exec("ALTER TABLE `visit_details` ADD COLUMN `viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
    },
];
