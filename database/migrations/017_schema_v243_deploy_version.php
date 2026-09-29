<?php
/**
 * 🗃️ مهاجرت schema_v243_deploy_version — نسخه استقرار هر برند (S02 / v2.43)
 * 🔖 ریشه‌یابی «رفع‌ها بعد از آپدیت پنل روی سایت برند اعمال نمیشود»:
 * هیچ سنجه‌ای برای تشخیص «استقرار کهنه» وجود نداشت. اکنون Deployer نسخه
 * هسته سایتِ برند (متای generator) را در brands.deployed_version ذخیره
 * می‌کند و پنل برندها هشدار «بروزرسانی استقرار» می‌دهد.
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v243_deploy_version',
    'order' => 17,
    'up'    => static function (): void {
        $pdo = Database::getInstance()->pdo();
        $col = $pdo->query("SHOW COLUMNS FROM `brands` LIKE 'deployed_version'")->fetchAll();
        if (empty($col)) {
            $pdo->exec("ALTER TABLE `brands` ADD COLUMN `deployed_version` VARCHAR(32) NULL COMMENT 'نسخه هسته سایتِ استقرارشده (متای generator) — تشخیص استقرار کهنه' AFTER `health_status`");
        }
    },
];
