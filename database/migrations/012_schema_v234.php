<?php
/**
 * 🗃️ مهاجرت schema_v234 — استخراج‌شده از config.php (v2.43 / S14)
 * 
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v234',
    'order' => 12,
    'up'    => static function (): void {
            $pdo = Database::getInstance()->pdo();

            /* ① ستون‌های 2FA روی users */
            $hasTotp = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'totp_secret'")->fetchAll();
            if (empty($hasTotp)) {
                $pdo->exec("ALTER TABLE `users`
                    ADD COLUMN `totp_secret` VARCHAR(64) NULL COMMENT 'رمز Base32 ورود دومرحله‌ای' AFTER `last_login`,
                    ADD COLUMN `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'فعال بودن 2FA' AFTER `totp_secret`,
                    ADD COLUMN `totp_recovery` TEXT NULL COMMENT 'هش کدهای بازیابی (JSON)' AFTER `totp_enabled`");
            }

            /* افزودن نقش brand_manager به enum (بدون از دست رفتن نقش‌های موجود) */
            $roleCol = $pdo->query("SHOW COLUMNS FROM `users` WHERE Field = 'role'")->fetch();
            if ($roleCol && mb_strpos((string)$roleCol['Type'], 'brand_manager') === false) {
                $pdo->exec("ALTER TABLE `users` MODIFY `role` ENUM('admin','editor','brand_manager') NOT NULL DEFAULT 'editor' COMMENT 'نقش کاربر'");
            }

            /* ② ACL برند */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `brand_user_access` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `brand_id` INT UNSIGNED NOT NULL,
                `granted_by` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_user_brand` (`user_id`, `brand_id`),
                KEY `idx_bua_brand` (`brand_id`),
                CONSTRAINT `fk_bua_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_bua_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تخصیص برند به مدیر برند (ACL)'");

            /* ③ بازیابی رمز عبور */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `password_resets` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `token_hash` CHAR(64) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `used_at` DATETIME NULL,
                `ip` VARCHAR(60) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_pr_token` (`token_hash`),
                KEY `idx_pr_user` (`user_id`),
                CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='توکن‌های بازیابی رمز عبور'");

            /* ④ تاریخچه تغییرات */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `content_revisions` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entity_type` ENUM('brand','page','article','menu') NOT NULL,
                `entity_id` INT UNSIGNED NOT NULL,
                `brand_id` INT UNSIGNED NULL,
                `user_id` INT UNSIGNED NULL,
                `title` VARCHAR(255) NULL,
                `snapshot` LONGTEXT NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_rev_entity` (`entity_type`, `entity_id`, `created_at`),
                KEY `idx_rev_brand` (`brand_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تاریخچه تغییرات برند/صفحه/مقاله'");

            /* ⑤ کتابخانه رسانه */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `media_files` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `path` VARCHAR(500) NOT NULL,
                `kind` ENUM('article','logo','request','misc') NOT NULL DEFAULT 'misc',
                `original_name` VARCHAR(255) NULL,
                `alt` VARCHAR(255) NULL,
                `size` INT UNSIGNED NULL,
                `width` SMALLINT UNSIGNED NULL,
                `height` SMALLINT UNSIGNED NULL,
                `uploaded_by` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_media_path` (`path`),
                KEY `idx_media_kind` (`kind`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='کتابخانه رسانه — فهرست و متادیتا'");

            /* ⑥ خلاصه روزانه آمار (سیاست نگهداری داده) */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `visits_daily` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `brand_id` INT UNSIGNED NOT NULL,
                `stat_date` DATE NOT NULL,
                `visits` INT UNSIGNED NOT NULL DEFAULT 0,
                `visitors` INT UNSIGNED NOT NULL DEFAULT 0,
                `page_views` INT UNSIGNED NOT NULL DEFAULT 0,
                `device_types` JSON NULL,
                `provinces` JSON NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_vd` (`brand_id`, `stat_date`),
                CONSTRAINT `fk_vd_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='خلاصه روزانه آمار — پس از ۱۸ ماه ردیف visits پاک می‌شود'");

    },
];
