<?php
/**
 * 🗃️ مهاجرت schema_v211 — استخراج‌شده از config.php (v2.43 / S14)
 * 
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v211',
    'order' => 2,
    'up'    => static function (): void {
            $dsn2 = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
            $probe2 = new PDO($dsn2, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
            $probe2 = null;
            $pdo = Database::getInstance()->pdo();

            /* 🆕 v2.11: ستون‌های استقرار در جدول brands */
            $brandCols = [
                ['is_deployed',      "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'آیا استقرار شده'"],
                ['deployed_at',      "DATETIME NULL COMMENT 'تاریخ آخرین استقرار'"],
                ['deploy_method',    "ENUM('auto','manual') NULL COMMENT 'روش استقرار'"],
                ['server_path',      "VARCHAR(500) NULL COMMENT 'مسیر فایل‌ها در سرور'"],
                ['subdomain_name',   "VARCHAR(63) NULL COMMENT 'نام زیردامنه (بدون دامنه اصلی)'"],
                ['full_domain',      "VARCHAR(255) NULL COMMENT 'دامنه کامل سایت برند'"],
                ['custom_subdomain', "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'آیا نام دستی ویرایش شده'"],
                ['ssl_status',       "ENUM('active','pending','none') NOT NULL DEFAULT 'none' COMMENT 'وضعیت SSL'"],
                ['ssl_expiry',       "DATE NULL COMMENT 'تاریخ انقضای SSL'"],
                ['last_health_check',"DATETIME NULL COMMENT 'آخرین بررسی سلامت'"],
                ['health_status',    "ENUM('online','offline','error') NULL COMMENT 'وضعیت سلامت سایت'"],
            ];
            foreach ($brandCols as [$col, $def]) {
                $exists = $pdo->query("SHOW COLUMNS FROM `brands` LIKE '" . $col . "'")->fetchAll();
                if (empty($exists)) {
                    $pdo->exec("ALTER TABLE `brands` ADD COLUMN `" . $col . "` " . $def);
                }
            }

            /* 🆕 v2.11: ستون مجوز استقرار در api_keys (کلیدهای خارجی) */
            $deployKeyCol = $pdo->query("SHOW COLUMNS FROM `api_keys` LIKE 'can_deploy'")->fetchAll();
            if (empty($deployKeyCol)) {
                $pdo->exec("ALTER TABLE `api_keys` ADD COLUMN `can_deploy` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'اجازه استقرار/بکاپ از API خارجی'");
            }

            /* 🆕 v2.11: جدول تنظیمات cpanel (تک‌ردیفی id=1) */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `cpanel_settings` (
                `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `cpanel_host` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'آدرس سرور cPanel',
                `cpanel_port` SMALLINT UNSIGNED NOT NULL DEFAULT 2083 COMMENT 'پورت (پیش‌فرض HTTPS)',
                `cpanel_protocol` ENUM('http','https') NOT NULL DEFAULT 'https' COMMENT 'پروتکل',
                `cpanel_username` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'نام کاربری هاست',
                `cpanel_token_enc` TEXT NULL COMMENT 'API Token رمزنگاری‌شده AES-256',
                `root_domain` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'دامنه اصلی (مثلاً ea-fixer.ir)',
                `doc_root_pattern` VARCHAR(500) NOT NULL DEFAULT '/public_html/brands/{brand_slug}' COMMENT 'الگوی مسیر Document Root',
                `doc_root_preset` VARCHAR(50) NOT NULL DEFAULT 'default' COMMENT 'نام الگوی انتخابی',
                `deploy_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'استقرار خودکار فعال؟',
                `ssl_auto` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'نصب خودکار SSL؟',
                `ssl_provider` ENUM('cpanel','letsencrypt') NOT NULL DEFAULT 'letsencrypt' COMMENT 'فراهم‌کننده SSL',
                `upload_mode` ENUM('api','ftp') NOT NULL DEFAULT 'api' COMMENT 'روش آپلود',
                `backup_before_update` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'بکاپ قبل از بروزرسانی؟',
                `auto_test` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'تست خودکار پس از استقرار؟',
                `notify_after_deploy` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'اعلان پس از استقرار؟',
                `max_upload_mb` INT UNSIGNED NOT NULL DEFAULT 50 COMMENT 'حداکثر حجم آپلود (مگابایت)',
                `timeout_seconds` INT UNSIGNED NOT NULL DEFAULT 120 COMMENT 'Timeout عملیات (ثانیه)',
                `ftp_host` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'آدرس سرور FTP',
                `ftp_port` SMALLINT UNSIGNED NOT NULL DEFAULT 21 COMMENT 'پورت FTP',
                `ftp_username` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'نام کاربری FTP',
                `ftp_password_enc` TEXT NULL COMMENT 'رمز FTP رمزنگاری‌شده',
                `ftp_passive` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'حالت Passive',
                `ftp_ssl` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'FTPS فعال؟',
                `backup_dir` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'پوشه بکاپ‌ها (خالی = پیش‌فرض brands/backups)',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تنظیمات اتصال cPanel و استقرار خودکار'");
            // ردیف پیش‌فرض
            $pdo->exec("INSERT IGNORE INTO `cpanel_settings` (`id`) VALUES (1)");

            /* 🆕 v2.11: جدول تاریخچه استقرارها */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `deployments` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `brand_id` INT UNSIGNED NOT NULL COMMENT 'برند',
                `action` ENUM('deploy','update','delete','ssl','backup','rollback') NOT NULL DEFAULT 'deploy' COMMENT 'نوع عملیات',
                `status` ENUM('pending','in_progress','success','failed') NOT NULL DEFAULT 'pending' COMMENT 'وضعیت',
                `current_step` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'مرحله جاری',
                `total_steps` TINYINT UNSIGNED NOT NULL DEFAULT 11 COMMENT 'تعداد مراحل',
                `subdomain` VARCHAR(63) NULL COMMENT 'نام زیردامنه',
                `full_domain` VARCHAR(255) NULL COMMENT 'دامنه کامل',
                `server_path` VARCHAR(500) NULL COMMENT 'مسیر روی سرور',
                `zip_path` VARCHAR(500) NULL COMMENT 'مسیر ZIP محلی',
                `started_at` DATETIME NULL COMMENT 'شروع',
                `completed_at` DATETIME NULL COMMENT 'پایان',
                `duration_seconds` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'مدت (ثانیه)',
                `details` TEXT NULL COMMENT 'جزئیات JSON',
                `error_message` TEXT NULL COMMENT 'پیام خطا',
                `backup_path` VARCHAR(500) NULL COMMENT 'مسیر بکاپ',
                `triggered_by` ENUM('panel','api','cron') NOT NULL DEFAULT 'panel' COMMENT 'منبع اجرا',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_deploy_brand` (`brand_id`),
                KEY `idx_deploy_status` (`status`),
                CONSTRAINT `fk_deploy_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تاریخچه استقرارها'");

            /* 🆕 v2.11: لاگ مراحل هر عملیات */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `deployment_logs` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `deployment_id` INT UNSIGNED NOT NULL COMMENT 'شناسه استقرار',
                `step` VARCHAR(100) NOT NULL COMMENT 'نام مرحله',
                `status` ENUM('success','failed','skipped','in_progress') NOT NULL DEFAULT 'success' COMMENT 'وضعیت',
                `message` TEXT NULL COMMENT 'پیام',
                `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'مدت (میلی‌ثانیه)',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'زمان',
                PRIMARY KEY (`id`),
                KEY `idx_dlog_deployment` (`deployment_id`),
                CONSTRAINT `fk_dlog_deployment` FOREIGN KEY (`deployment_id`) REFERENCES `deployments`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='لاگ جزئیات عملیات استقرار'");

            /* 🆕 v2.11: وضعیت سلامت سایت‌ها */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `site_health` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `brand_id` INT UNSIGNED NOT NULL COMMENT 'برند',
                `http_status` SMALLINT UNSIGNED NULL COMMENT 'کد HTTP',
                `response_time_ms` INT UNSIGNED NULL COMMENT 'زمان پاسخ (میلی‌ثانیه)',
                `ssl_valid` TINYINT(1) NULL COMMENT 'SSL معتبر؟',
                `ssl_expiry` DATE NULL COMMENT 'انقضای SSL',
                `api_ok` TINYINT(1) NULL COMMENT 'اتصال API سایت ساز؟',
                `status` ENUM('online','offline','error') NOT NULL DEFAULT 'error' COMMENT 'وضعیت کلی',
                `error_message` VARCHAR(500) NULL COMMENT 'خطا',
                `checked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'زمان بررسی',
                PRIMARY KEY (`id`),
                KEY `idx_health_brand` (`brand_id`, `checked_at`),
                CONSTRAINT `fk_health_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='وضعیت سلامت سایت‌های برند'");

            /* 🆕 v2.11: بکاپ‌ها — با تاریخ شمسی (طبق پرامپت تکمیلی) */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `backups` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `brand_id` INT UNSIGNED NOT NULL COMMENT 'برند',
                `filename` VARCHAR(255) NOT NULL COMMENT 'نام فایل بکاپ',
                `file_path` VARCHAR(500) NOT NULL COMMENT 'مسیر کامل روی سرور',
                `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'حجم (بایت)',
                `backup_type` ENUM('manual','auto_before_update','weekly_scheduled','before_delete') NOT NULL DEFAULT 'manual' COMMENT 'نوع بکاپ',
                `shamsi_date` VARCHAR(20) NULL COMMENT 'تاریخ شمسی (1404-03-25 14:30:45)',
                `shamsi_date_display` VARCHAR(30) NULL COMMENT 'نمایش زیبا (۱۴۰۴/۰۳/۲۵ ۱۴:۳۰)',
                `gregorian_date` DATETIME NULL COMMENT 'تاریخ میلادی معادل',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_backup_brand` (`brand_id`),
                CONSTRAINT `fk_backup_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بکاپ‌های سایت‌های برند'");

            /* 🆕 v2.11: وضعیت گواهی‌های SSL */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `ssl_certificates` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `brand_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'برند',
                `domain` VARCHAR(255) NOT NULL COMMENT 'دامنه',
                `status` ENUM('active','pending','none','expired') NOT NULL DEFAULT 'none' COMMENT 'وضعیت',
                `issuer` VARCHAR(255) NULL COMMENT 'صادرکننده',
                `issued_at` DATETIME NULL COMMENT 'تاریخ صدور',
                `expires_at` DATETIME NULL COMMENT 'تاریخ انقضا',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_ssl_domain` (`domain`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='وضعیت SSLها'");

    },
];
