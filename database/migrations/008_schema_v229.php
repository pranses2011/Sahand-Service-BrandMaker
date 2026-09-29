<?php
/**
 * 🗃️ مهاجرت schema_v229 — استخراج‌شده از config.php (v2.43 / S14)
 * 
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v229',
    'order' => 8,
    'up'    => static function (): void {
            $pdo = Database::getInstance()->pdo();

            $hasGk = $pdo->query("SHOW COLUMNS FROM `cpanel_settings` LIKE 'backup_keep_count'")->fetchAll();
            if (empty($hasGk)) {
                $pdo->exec("ALTER TABLE `cpanel_settings` ADD COLUMN `backup_keep_count` INT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'تعداد بکاپ نگهداری‌شده هر برند (پیش‌فرض عمومی)' AFTER `backup_dir`");
            }
            $hasBk = $pdo->query("SHOW COLUMNS FROM `brands` LIKE 'backup_keep_count'")->fetchAll();
            if (empty($hasBk)) {
                $pdo->exec("ALTER TABLE `brands` ADD COLUMN `backup_keep_count` INT UNSIGNED NULL COMMENT 'تعداد بکاپ اختصاصی این برند (NULL = از تنظیمات عمومی)' AFTER `is_active`");
            }

    },
];
