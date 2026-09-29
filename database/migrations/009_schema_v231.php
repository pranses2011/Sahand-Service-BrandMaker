<?php
/**
 * 🗃️ مهاجرت schema_v231 — استخراج‌شده از config.php (v2.43 / S14)
 * 
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v231',
    'order' => 9,
    'up'    => static function (): void {
            $pdo = Database::getInstance()->pdo();

            /* ① کش جغرافیایی */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `geoip_cache` (
                `ip_prefix` VARCHAR(20) NOT NULL COMMENT 'پیشوند /24',
                `city` VARCHAR(120) NULL,
                `province` VARCHAR(120) NULL,
                `country` VARCHAR(5) NULL,
                `fetched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`ip_prefix`),
                KEY `idx_geoip_fetched` (`fetched_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='کش GeoIP سرویس خارجی'");

            /* ② ستون‌های آمار */
            $hasProv = $pdo->query("SHOW COLUMNS FROM `visits` LIKE 'province'")->fetchAll();
            if (empty($hasProv)) {
                $pdo->exec("ALTER TABLE `visits` ADD COLUMN `province` VARCHAR(120) NULL COMMENT 'استان از GeoIP' AFTER `city`");
            }
            $hasLastSeen = $pdo->query("SHOW COLUMNS FROM `visits` LIKE 'last_seen'")->fetchAll();
            if (empty($hasLastSeen)) {
                $pdo->exec("ALTER TABLE `visits` ADD COLUMN `last_seen` DATETIME NULL COMMENT 'آخرین فعالیت (کاربران آنلاین)' AFTER `visited_at`, ADD KEY `idx_visit_last_seen` (`last_seen`)");
            }

            /* ③ فرم‌های دیگر سایت برند */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `form_entries` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `brand_id` INT UNSIGNED NOT NULL,
                `form_block` VARCHAR(60) NOT NULL DEFAULT 'custom' COMMENT 'نوع فرم (contact/newsletter/callback/...)',
                `page_url` VARCHAR(500) NULL,
                `fields` JSON NULL COMMENT 'فیلدهای فرم به‌صورت JSON',
                `ip_address` VARCHAR(60) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_fentry_brand` (`brand_id`, `created_at`),
                KEY `idx_fentry_form` (`form_block`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ثبت فرم‌های سفارشی سایت برند'");

    },
];
