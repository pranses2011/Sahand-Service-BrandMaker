<?php
/**
 * 🗃️ مهاجرت schema_v226_personal_elements — استخراج‌شده از config.php (v2.43 / S14)
 * 🆕 مهاجرت v2.26 — جدول «عناصر شخصی» قالب‌ساز
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v226_personal_elements',
    'order' => 4,
    'up'    => static function (): void {
$pdo = Database::getInstance()->pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS `personal_elements` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(191) NOT NULL COMMENT 'نام نمایشی عنصر',
            `element_type` VARCHAR(40) NOT NULL DEFAULT 'button' COMMENT 'نوع (button/card/nav/...)',
            `source_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'سایت مبدأ',
            `html` MEDIUMTEXT NOT NULL COMMENT 'HTML ایمن‌شده عنصر',
            `css` TEXT NOT NULL COMMENT 'استایل تخت‌شده عنصر',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_pelem_type` (`element_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='عناصر شخصی استخراج‌شده از سایت‌ها (v2.26)'");
    },
];
