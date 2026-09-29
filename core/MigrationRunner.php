<?php
/**
 * 🏃 MigrationRunner — اجراکننده مهاجرت‌های دیتابیس (S14 / v2.43)
 * ================================================================
 * درخواست کاربر (S14): «مهاجرت در زمان اجرای برنامه و داخل config.php
 * انجام نشود — از مسیر درخواست عادی جدا شود».
 *
 * معماری جدید:
 *   نصب/بروزرسانی ← Migration Runner ← Database
 *   درخواست عادی  ← Bootstrap (چک سریع نشانگر) ← Application
 *
 * ✅ چک سریع در هر درخواست: فقط یک is_file + مقایسه رشته (میکروثانیه)
 *    — نشانگر cache/migrations.state اثر انگشت مجموعه مهاجرت‌هاست؛
 *    تا وقتی فایل‌های database/migrations/ تغییر نکرده‌اند، هیچ کاری
 *    انجام نمی‌شود (نه SHOW COLUMNS، نه SELECT).
 * ✅ اجرای واقعی فقط وقتی نسخه جدید آپلود شده باشد (یک‌بار) یا از
 *    صفحه مدیریت admin/migrate.php / CLI خواسته شود.
 * ✅ قفل flock: دو درخواست همزمان (یا cron + کاربر) مهاجرت را دوبار
 *    اجرا نمی‌کنند؛ دومی نشانگر تازه می‌بیند و رد می‌شود.
 * ✅ سازگاری کامل با SchemaMigrations (منبع حقیقت = جدول schema_migrations)
 *    و SAHAND_NO_DB_MIGRATE (نصب تازه / تست‌ها).
 *
 * @package SahandBrandMaker\Core
 * @since   2.43.0
 */
final class MigrationRunner
{
    /** @var array|null رجیستری کش‌شده مهاجرت‌ها */
    private static ?array $registry = null;

    /** مسیر پوشه مهاجرت‌ها */
    private const DIR = ROOT_PATH . '/database/migrations';

    /** مسیر فایل نشانگر وضعیت */
    private const STATE_FILE = CACHE_PATH . '/migrations.state';

    /* --------------------------------------------------
     * 🚀 نقطه ورود بوت‌استرپ — چک سریع + اجرای یک‌باره
     * -------------------------------------------------- */
    public static function boot(): void
    {
        if (defined('SAHAND_NO_DB_MIGRATE')) {
            return; /* نصب تازه / تست‌ها — مهاجرت دستی/نصاب اجرا می‌کند */
        }
        $expected = self::fingerprint();
        $current = self::readState();
        if ($current === $expected) {
            return; /* ✅ هم‌ارز — هیچ کارِ دیتابیسی لازم نیست */
        }
        /* 🔒 قفل: فقط یک پردازش زنجیره را اجرا کند */
        $lock = @fopen(self::STATE_FILE . '.lock', 'c');
        if ($lock === false) {
            return; /* فایل‌سیستم بدون قفل → اجرای مستقیم امن است (idempotent) */
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            /* پردازش دیگری در حال اجراست — او نشانگر را تازه می‌کند */
            flock($lock, LOCK_SH);
            flock($lock, LOCK_UN);
            fclose($lock);
            return;
        }
        try {
            /* دوباره چک — شمال قفل‌گیرنده همین حالا اجرا کرده باشد */
            if (self::readState() !== $expected) {
                self::run();
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * 📋 رجیستری مهاجرت‌ها (به‌ترتیب نام فایل = ترتیب اجرا)
     * @return array<int, array{name: string, file: string, order: int}>
     */
    public static function registry(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }
        self::$registry = [];
        foreach (glob(self::DIR . '/*.php') ?: [] as $file) {
            $mig = include $file;
            if (is_array($mig) && !empty($mig['name']) && is_callable($mig['up'] ?? null)) {
                $mig['file'] = basename($file);
                self::$registry[] = $mig;
            }
        }
        /* ترتیب عددی پیشوند فایل (001_… 002_…) مرجع است */
        usort(self::$registry, static fn($a, $b) => strcmp((string)$a['file'], (string)$b['file']));
        return self::$registry;
    }

    /**
     * ⏳ مهاجرت‌های اجرا نشده
     * @return array<int, array{name: string, file: string}>
     */
    public static function pending(): array
    {
        $out = [];
        foreach (self::registry() as $mig) {
            if (!SchemaMigrations::applied((string)$mig['name'])) {
                $out[] = ['name' => (string)$mig['name'], 'file' => (string)$mig['file']];
            }
        }
        return $out;
    }

    /**
     * ▶️ اجرای مهاجرت‌های در انتظار (idempotent + مقاوم)
     * @param int|null $limit حداکثر تعداد (null = همه)
     * @return array{applied: string[], failed: array<string,string>, skipped: string[]}
     */
    public static function run(?int $limit = null): array
    {
        $result = ['applied' => [], 'failed' => [], 'skipped' => []];
        foreach (self::registry() as $mig) {
            $name = (string)$mig['name'];
            if (SchemaMigrations::applied($name)) {
                $result['skipped'][] = $name;
                continue;
            }
            if ($limit !== null && count($result['applied']) >= $limit) {
                break;
            }
            try {
                ($mig['up'])();
                SchemaMigrations::mark($name);
                $result['applied'][] = $name;
            } catch (Throwable $e) {
                /* همان رفتار config.php قبلی: خطای هر مهاجرت زنجیره را نمی‌شکند
                   (نصب تازه / دسترسی محدود) — علت ثبت می‌شود */
                $result['failed'][$name] = $e->getMessage();
            }
        }
        self::writeState();
        return $result;
    }

    /**
     * 🧾 گزارش وضعیت برای صفحه مدیریت (بدون اجرا)
     */
    public static function status(): array
    {
        $rows = [];
        foreach (self::registry() as $mig) {
            $rows[] = [
                'name'    => (string)$mig['name'],
                'file'    => (string)$mig['file'],
                'applied' => SchemaMigrations::applied((string)$mig['name']),
            ];
        }
        return $rows;
    }

    /* -------------------------------------------------- */

    /** 🧷 اثر انگشت مجموعه مهاجرت‌ها: «تعداد | md5(نام‌ها)» */
    private static function fingerprint(): string
    {
        $names = array_column(self::registry(), 'name');
        return count($names) . '|' . md5(implode(',', $names));
    }

    private static function readState(): string
    {
        clearstatcache(true, self::STATE_FILE);
        $v = @file_get_contents(self::STATE_FILE);
        return is_string($v) ? trim($v) : '';
    }

    private static function writeState(): void
    {
        if (!is_dir(dirname(self::STATE_FILE))) {
            @mkdir(dirname(self::STATE_FILE), 0755, true);
        }
        @file_put_contents(self::STATE_FILE, self::fingerprint(), LOCK_EX);
    }
}
