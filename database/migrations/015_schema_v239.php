<?php
/**
 * 🗃️ مهاجرت schema_v239 — استخراج‌شده از config.php (v2.43 / S14)
 * 
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v239',
    'order' => 15,
    'up'    => static function (): void {
            $pdo = Database::getInstance()->pdo();
            $avCol = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'avatar'")->fetchAll();
            if (empty($avCol)) {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `avatar` VARCHAR(255) NULL COMMENT '🆕 v2.39 — مسیر تصویر آواتار کاربر' AFTER `email`");
            }
    },
];
