<?php
/**
 * 🗃️ مهاجرت schema_v244_geo_mobile — پرچم اپراتور موبایل در کش جغرافیایی (S07 / v2.44)
 * 🔖 ریشه‌یابی باقی‌مانده «من در تبریز هستم و بازدید برای خراسان رضوی ثبت می‌شود»:
 * IPهای اپراتورهای موبایل (همراه‌اول/ایرانسل/رایتل) در دیتابیس‌های جهانی به
 * «محل ثبت اپراتور» (اغلب تهران/مشهد) نگاشت می‌شوند نه محل واقعی کاربر — یعنی
 * هر برچسب شهری برای آن‌ها حدس غلط است. ip-api.com پرچم `mobile` برمی‌گرداند؛
 * از این پس بازدیدهای موبایلی با شهر/استان خالی + پرچم is_mobile کش می‌شوند
 * و در آمار به‌صورت «اینترنت موبایل» گزارش می‌شوند (نه شهر غلط).
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v244_geo_mobile',
    'order' => 18,
    'up'    => static function (): void {
        $pdo = Database::getInstance()->pdo();
        $col = $pdo->query("SHOW COLUMNS FROM `geoip_cache` LIKE 'is_mobile'")->fetchAll();
        if (empty($col)) {
            $pdo->exec("ALTER TABLE `geoip_cache` ADD COLUMN `is_mobile` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'IP اپراتور موبایل — شهر/استان قابل اتکا نیست' AFTER `country`");
        }
        /* بازدیدهای قدیمی که از سرویس خارجی شهر گرفته‌اند را نمی‌توان پس‌بگیریم؛
           دکمه «بازحسابی جغرافیایی» موجود، کش را پاک می‌کند و دفعه بعد پرچم
           موبایل به‌درستی اعمال می‌شود. */
    },
];
