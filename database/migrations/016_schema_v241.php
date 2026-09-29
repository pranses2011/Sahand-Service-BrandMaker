<?php
/**
 * 🗃️ مهاجرت schema_v241 — استخراج‌شده از config.php (v2.43 / S14)
 * 
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v241',
    'order' => 16,
    'up'    => static function (): void {
            $pdo = Database::getInstance()->pdo();
            $lcCol = $pdo->query("SHOW COLUMNS FROM `brand_pages` LIKE 'layout_custom'")->fetchAll();
            if (empty($lcCol)) {
                $pdo->exec("ALTER TABLE `brand_pages` ADD COLUMN `layout_custom` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = چیدمان ویرایش‌شده دستی در قالب‌ساز (بر تم مقدم) | 0 = چیدمان خودکار تولیدی' AFTER `layout_json`");
            }
    },
];
