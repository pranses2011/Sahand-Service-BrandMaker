<?php
/**
 * 🗃️ مهاجرت schema_v232 — استخراج‌شده از config.php (v2.43 / S14)
 * 
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v232',
    'order' => 10,
    'up'    => static function (): void {
            $pdo = Database::getInstance()->pdo();
            $hasGeoSrc = $pdo->query("SHOW COLUMNS FROM `visits` LIKE 'geo_src'")->fetchAll();
            if (empty($hasGeoSrc)) {
                $pdo->exec("ALTER TABLE `visits` ADD COLUMN `geo_src` VARCHAR(10) NULL COMMENT 'منبع جغرافیا: api/cache/local' AFTER `province`");
            }
    },
];
