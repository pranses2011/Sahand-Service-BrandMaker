<?php
/**
 * 🗃️ مهاجرت schema_v26 — استخراج‌شده از config.php (v2.43 / S14)
 * 
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v26',
    'order' => 1,
    'up'    => static function (): void {
            /* 🛡️ اتصال آزمایشی مستقیم (قابل گرفتن) — چون Database::getInstance
               در خطای اتصال die می‌کند و نباید نصب تازه/محیط بدون DB را بشکند */
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
            $probe = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
            $probe = null;
            $pdo = Database::getInstance()->pdo();
            // 🆕 v2.6: ستون تصویر OG مقاله
            $cols = $pdo->query("SHOW COLUMNS FROM `brand_articles` LIKE 'og_image'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE `brand_articles` ADD COLUMN `og_image` VARCHAR(500) NULL COMMENT 'تصویر OG تولیدی AI' AFTER `featured_image`");
            }
            // 🆕 v2.6: ستون‌های ۱۴ فیلدی کدهای خطا
            $ecCols = [
                ['subtype', "VARCHAR(255) NULL COMMENT 'زیرنوع دستگاه'"],
                ['models', "JSON NULL COMMENT 'مدل‌های دارای این کد'"],
                ['category', "VARCHAR(100) NULL COMMENT 'نوع خطا (سنسور/موتور/برد/...)'"],
                ['related_part', "VARCHAR(255) NULL COMMENT 'قطعه مربوطه'"],
                ['tech_specs', "TEXT NULL COMMENT 'مشخصات فنی قطعه'"],
                ['part_location', "VARCHAR(500) NULL COMMENT 'محل قرارگیری قطعه'"],
                ['source', "VARCHAR(50) NULL COMMENT 'منبع: kb|web|manual'"],
                ['source_urls', "JSON NULL COMMENT 'منابع آنلاین استخراج'"],
            ];
            foreach ($ecCols as [$col, $def]) {
                $exists = $pdo->query("SHOW COLUMNS FROM `error_codes` LIKE '" . $col . "'")->fetchAll();
                if (empty($exists)) {
                    $pdo->exec("ALTER TABLE `error_codes` ADD COLUMN `" . $col . "` " . $def);
                }
            }
            // 🆕 v2.6: سطح اهمیت «اطلاعاتی» برای کدهای خطا
            $sevCol = $pdo->query("SHOW COLUMNS FROM `error_codes` LIKE 'severity'")->fetch();
            if ($sevCol && stripos((string)($sevCol['Type'] ?? ''), 'informational') === false) {
                $pdo->exec("ALTER TABLE `error_codes` MODIFY `severity` ENUM('low','medium','high','critical','informational') NOT NULL DEFAULT 'medium'");
            }
    },
];
