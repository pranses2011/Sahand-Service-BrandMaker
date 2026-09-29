<?php
/**
 * 🗃️ مهاجرت schema_v215 — استخراج‌شده از config.php (v2.43 / S14)
 * 
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v215',
    'order' => 6,
    'up'    => static function (): void {
            $dsn4 = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
            $probe4 = new PDO($dsn4, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
            $probe4 = null;
            $pdo = Database::getInstance()->pdo();

            /* 🩺 پرچم استقرار بدون مسیر سرور = داده ناسازگار → ریست به «بدون استقرار» */
            $pdo->exec("UPDATE `brands` SET `is_deployed` = 0, `deploy_method` = NULL, `deployed_at` = NULL
                WHERE `is_deployed` = 1 AND (`server_path` IS NULL OR TRIM(`server_path`) = '')");

    },
];
