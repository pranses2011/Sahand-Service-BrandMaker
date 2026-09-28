<?php
/**
 * 🗃️ SchemaMigrations — منبع حقیقت واحد مهاجرت‌های دیتابیس (P2-26)
 * =====================================================
 * گزارش تحلیل جامع §۹.۲: «مهاجرت با نشانگر فایلِ جداگانه» بدهی فنی است —
 * نشانگرهای cache/.schema_vXXX در محیط‌های چند-نمونه‌ای (load-balancer)،
 * پاک‌سازی cache/ یا restore جزئی دیتابیس گم می‌شوند و مهاجرت یا تکراری
 * اجرا می‌شود یا بعد از restore قدیمی اصلاً اجرا نمی‌شود.
 *
 * این کلاس جدول `schema_migrations` را به‌عنوان منبع حقیقت واحد جایگزین
 * نشانگرهای فایل می‌کند:
 *   • applied($name) — آیا این مهاجرت قبلاً اجرا شده؟
 *   • mark($name)    — ثبت اجرای موفق مهاجرت
 *
 * 🔄 سازگاری با نسخه‌های قبل: در اولین اجرا، نشانگرهای فایل قدیمی
 * (cache/.schema_*) به‌صورت خودکار به جدول «وارد» می‌شوند — یعنی نصب‌های
 * موجود که مهاجرت‌ها را با نشانگر اجرا کرده‌اند، دوباره اجرایشان نمی‌کنند.
 * خودکاره (Idempotent): INSERT IGNORE + CREATE TABLE IF NOT EXISTS.
 *
 * @package SahandBrandMaker\Core
 * @since   2.36.0
 */
class SchemaMigrations
{
    /** @var array<string,bool>|null کش در-حافظه‌ای مهاجرت‌های اجراشده */
    private static ?array $applied = null;

    /** نام جدول مهاجرت‌ها */
    private const TABLE = 'schema_migrations';

    /**
     * آیا مهاجرت با این نام قبلاً اجرا و ثبت شده است؟
     * در خطای DB (نصب تازه پیش از install.php / دسترسی محدود) «false»
     * برمی‌گرداند — بدنه مهاجرت خودش با try/catch بی‌صدا رد می‌شود و
     * همه مهاجرت‌ها idempotent هستند، پس رفتار با نسخه نشانگر-فایل
     * (که در cache غیرقابل‌نوشتن دوباره اجرا می‌شد) یکسان است.
     */
    public static function applied(string $name): bool
    {
        self::load();
        return isset(self::$applied[$name]);
    }

    /**
     * ثبت اجرای موفق یک مهاجرت (به‌همراه مهر زمانی).
     * INSERT IGNORE → اجرای همزمان دو پردازش (race) امن است.
     */
    public static function mark(string $name): void
    {
        try {
            $pdo = self::pdo();
            self::ensureTable($pdo);
            $st = $pdo->prepare('INSERT IGNORE INTO `' . self::TABLE . '` (`name`) VALUES (?)');
            $st->execute([$name]);
            self::$applied[$name] = true;
        } catch (Throwable $e) {
            /* بی‌صدا — بدنه مهاجرت idempotent است؛ اجرای مجدد بی‌ضرر */
        }
    }

    /**
     * فهرست مهاجرت‌های ثبت‌شده (برای صفحه وضعیت سیستم / دیباگ).
     * @return array<string,string> نام => تاریخ اجرا
     */
    public static function all(): array
    {
        self::load();
        try {
            $rows = self::pdo()->query('SELECT `name`, `applied_at` FROM `' . self::TABLE . '`')->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) {
                $out[(string)$r['name']] = (string)$r['applied_at'];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /* -------------------------------------------------- */

    /** بارگذاری یک‌باره + واردات نشانگرهای قدیمی */
    private static function load(): void
    {
        if (self::$applied !== null) {
            return;
        }
        self::$applied = [];
        try {
            $pdo = self::pdo();
            self::ensureTable($pdo);
            foreach ($pdo->query('SELECT `name` FROM `' . self::TABLE . '`')->fetchAll(PDO::FETCH_COLUMN) as $n) {
                self::$applied[(string)$n] = true;
            }
            /* 🔄 واردات نشانگرهای فایل قدیمی — فقط مهاجرت‌هایی که هنوز
               در جدول نیستند؛ بعد از واردات فایل حذف نمی‌شود (سازگاری
               باگرایش به عقب در صورت برگشت به نسخه قدیمی) */
            $imported = false;
            foreach (glob(ROOT_PATH . '/cache/.schema_*') ?: [] as $markerFile) {
                $name = ltrim(basename($markerFile), '.');
                if ($name !== '' && !isset(self::$applied[$name])) {
                    $st = $pdo->prepare('INSERT IGNORE INTO `' . self::TABLE . '` (`name`) VALUES (?)');
                    $st->execute([$name]);
                    self::$applied[$name] = true;
                    $imported = true;
                }
            }
            /* اگر جدول خالی و هیچ نشانگری نبود، مهاجرت‌ها از نو اجرا می‌شوند —
               idempotent بودن همه مهاجرت‌ها این مسیر را بی‌خطر می‌کند */
            unset($imported);
        } catch (Throwable $e) {
            /* DB در دسترس نیست — همه «اجرا-نشده» فرض می‌شوند */
        }
    }

    private static function ensureTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
            `name` VARCHAR(96) NOT NULL COMMENT \'نام مهاجرت (مطابق نام نشانگر قدیمی، مثل schema_v234)\',
            `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'زمان اجرای موفق\',
            PRIMARY KEY (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'منبع حقیقت واحد مهاجرت‌های دیتابیس (P2-26)\'');
    }

    private static function pdo(): PDO
    {
        return Database::getInstance()->pdo();
    }
}
