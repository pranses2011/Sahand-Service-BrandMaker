<?php
/**
 * 🗃️ مهاجرت schema_v212 — استخراج‌شده از config.php (v2.43 / S14)
 * 
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v212',
    'order' => 3,
    'up'    => static function (): void {
            $dsn3 = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
            $probe3 = new PDO($dsn3, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
            $probe3 = null;
            $pdo = Database::getInstance()->pdo();

            /* 🆕 v2.12: ستون فاوآیکون اختصاصی برند (نصب‌های قدیمی‌تر از ستون ندارند) */
            $favCol = $pdo->query("SHOW COLUMNS FROM `brands` LIKE 'favicon'")->fetchAll();
            if (empty($favCol)) {
                $pdo->exec("ALTER TABLE `brands` ADD COLUMN `favicon` VARCHAR(500) NULL COMMENT 'فاویکون برند' AFTER `logo`");
            }

            /* 🆕 v2.12: گسترش enum نوع دامنه برای پشتیبانی Addon Domain
               (ستون از قبل با ENUM('subdomain','custom') وجود دارد — مقدار addon اضافه می‌شود) */
            $dtCol = $pdo->query("SHOW COLUMNS FROM `brands` LIKE 'domain_type'")->fetch();
            if (empty($dtCol)) {
                $pdo->exec("ALTER TABLE `brands` ADD COLUMN `domain_type` ENUM('subdomain','custom','addon') NOT NULL DEFAULT 'subdomain' COMMENT 'نوع دامنه' AFTER `domain`");
            } elseif (stripos((string)($dtCol['Type'] ?? ''), 'addon') === false) {
                $pdo->exec("ALTER TABLE `brands` MODIFY `domain_type` ENUM('subdomain','custom','addon') NOT NULL DEFAULT 'subdomain' COMMENT 'نوع دامنه'");
            }

            /* 🆕 v2.12: جدول بلوک‌های ترکیبی قالب‌ساز (ذخیره/بازیابی چیدمان‌های سفارشی) */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `builder_blocks` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(191) NOT NULL COMMENT 'نام بلوک ترکیبی',
                `category` VARCHAR(100) NOT NULL DEFAULT 'سفارشی' COMMENT 'دسته نمایش در کتابخانه',
                `block_json` LONGTEXT NOT NULL COMMENT 'JSON کامل بلوک با ستون‌های تودرتو',
                `usage_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'دفعات استفاده',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_bblock_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بلوک‌های ترکیبی ذخیره‌شده قالب‌ساز'");

    },
];
