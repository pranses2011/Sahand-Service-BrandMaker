<?php
/**
 * 🗃️ مهاجرت schema_v235 — استخراج‌شده از config.php (v2.43 / S14)
 * 
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v235',
    'order' => 13,
    'up'    => static function (): void {
            $pdo = Database::getInstance()->pdo();

            $addArticleCol = function (string $col, string $ddl) use ($pdo): void {
                /* 🐛 MariaDB پارامتر در دستور SHOW را پشتیبانی نمی‌کند (Syntax 1064) */
                $st = $pdo->query("SHOW COLUMNS FROM `brand_articles` LIKE " . $pdo->quote($col));
                if ($st === false || empty($st->fetchAll())) {
                    $pdo->exec($ddl);
                }
            };
            $addArticleCol('focus_keyword', "ALTER TABLE `brand_articles` ADD COLUMN `focus_keyword` VARCHAR(255) NULL COMMENT 'کلیدواژه کانونی مقاله' AFTER `excerpt`");
            $addArticleCol('research', "ALTER TABLE `brand_articles` ADD COLUMN `research` LONGTEXT NULL COMMENT 'خروجی تحقیق وب (JSON: منابع/فکت‌ها/آمار/عمق)' AFTER `og_image`");
            $addArticleCol('depth', "ALTER TABLE `brand_articles` ADD COLUMN `depth` VARCHAR(16) NULL DEFAULT NULL COMMENT 'عمق تحقیق وب: fast/balanced/deep' AFTER `research`");

            /* 🧩 ردیابیِ گزاره‌های مصرف‌شده (استخرِ بدون تکرار) */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `article_pool_usage` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `brand_id` INT UNSIGNED NOT NULL,
                `device_key` VARCHAR(60) NOT NULL DEFAULT '',
                `stmt_hash` CHAR(32) NOT NULL COMMENT 'md5 متن گزاره',
                `used_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_pool` (`brand_id`, `device_key`, `stmt_hash`),
                KEY `idx_pool_brand` (`brand_id`, `device_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='گزاره‌های دانش مصرف‌شده در مقالات (ضد تکرار)';");

    },
];
