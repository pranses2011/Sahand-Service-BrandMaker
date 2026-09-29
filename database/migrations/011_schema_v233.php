<?php
/**
 * 🗃️ مهاجرت schema_v233 — استخراج‌شده از config.php (v2.43 / S14)
 * 
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v233',
    'order' => 11,
    'up'    => static function (): void {
            $pdo = Database::getInstance()->pdo();

            $addCol = function (string $col, string $ddl) use ($pdo): void {
                /* 🐛 v2.34 — MariaDB پارامتر در دستور SHOW را پشتیبانی نمی‌کند
                   (Syntax error 1064)؛ مقدار با quote امن درون‌خطی می‌شود */
                $st = $pdo->query("SHOW COLUMNS FROM `error_codes` LIKE " . $pdo->quote($col));
                if ($st === false || empty($st->fetchAll())) {
                    $pdo->exec($ddl);
                }
            };
            $addCol('subsystem', "ALTER TABLE `error_codes` ADD COLUMN `subsystem` VARCHAR(60) NULL COMMENT 'زیرسیستم علّی (drain/inlet/door_lock/...)' AFTER `source_urls`");
            $addCol('content_hash', "ALTER TABLE `error_codes` ADD COLUMN `content_hash` CHAR(64) NULL COMMENT 'هش SHA-256 محتوای نرمال‌شده (تشخیص کپی/تکراری)' AFTER `subsystem`");
            $addCol('needs_review', "ALTER TABLE `error_codes` ADD COLUMN `needs_review` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'نیاز به بازبینی دستی' AFTER `content_hash`");

            /* ایندکس‌ها — فقط اگر وجود ندارند */
            $idx = $pdo->query("SHOW INDEX FROM `error_codes`")->fetchAll(PDO::FETCH_ASSOC);
            $names = [];
            foreach ($idx as $r) {
                $names[(string)$r['Key_name']] = true;
            }
            if (!isset($names['idx_ecode_hash'])) {
                $pdo->exec("ALTER TABLE `error_codes` ADD KEY `idx_ecode_hash` (`content_hash`)");
            }
            if (!isset($names['idx_ecode_review'])) {
                $pdo->exec("ALTER TABLE `error_codes` ADD KEY `idx_ecode_review` (`needs_review`, `is_active`)");
            }

            /* 🧮 محاسبه‌ی هش برای رکوردهای موجود (یک‌بار) */
            try {
                $rows = $pdo->query("SELECT id, title, description, causes, solutions FROM error_codes WHERE content_hash IS NULL OR content_hash = ''")->fetchAll(PDO::FETCH_ASSOC);
                if ($rows && function_exists('error_code_content_hash')) {
                    $upd = $pdo->prepare("UPDATE error_codes SET content_hash = ? WHERE id = ?");
                    foreach ($rows as $r) {
                        $h = error_code_content_hash($r);
                        if ($h !== '') {
                            $upd->execute([$h, (int)$r['id']]);
                        }
                    }
                }
            } catch (Throwable $bh) { /* بی‌صدا */ }

    },
];
