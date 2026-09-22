<?php
/**
 * ⚙️ کلاس مدیریت تنظیمات (Config)
 * =================================
 * تنظیمات عمومی سیستم را از جدول settings می‌خواند،
 * کش می‌کند و قابلیت ذخیره با ساختار JSON را فراهم می‌کند.
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class Config
{
    /** @var array کش درون‌حافظه تنظیمات */
    private static $cache = [];

    /** @var bool آیا تنظیمات بارگذاری شده است */
    private static $loaded = false;

    /**
     * 📥 بارگذاری تمام تنظیمات از دیتابیس (فقط یک بار)
     */
    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        try {
            $rows = Database::getInstance()->fetchAll('SELECT setting_key, setting_value FROM settings');
            foreach ($rows as $row) {
                // مقادیر JSON به صورت خودکار دیکد می‌شوند
                $decoded = json_decode($row['setting_value'], true);
                self::$cache[$row['setting_key']] = ($decoded !== null && $row['setting_value'] !== '')
                    ? $decoded
                    : $row['setting_value'];
            }
        } catch (Exception $e) {
            // در زمان نصب، جداول هنوز وجود ندارند — بی‌صدا رد شود
            error_log('[Config] ' . $e->getMessage());
        }
        self::$loaded = true;
    }

    /**
     * 🔎 دریافت یک تنظیم با کلید مشخص
     *
     * @param string $key     کلید تنظیم (مثلاً agency_name_fa)
     * @param mixed  $default مقدار پیش‌فرض در صورت عدم وجود
     */
    public static function get(string $key, $default = null)
    {
        self::load();
        return array_key_exists($key, self::$cache) ? self::$cache[$key] : $default;
    }

    /**
     * 💾 ذخیره یک تنظیم (درج یا بروزرسانی)
     *
     * @param string $key   کلید تنظیم
     * @param mixed  $value مقدار (آرایه/شیء به JSON تبدیل می‌شود)
     */
    public static function set(string $key, $value): void
    {
        $db = Database::getInstance();
        $stored = is_array($value) || is_object($value)
            ? json_encode($value, JSON_UNESCAPED_UNICODE)
            : (string)$value;

        $exists = $db->fetchValue('SELECT COUNT(*) FROM settings WHERE setting_key = ?', [$key]);
        if ($exists) {
            $db->update('settings', [
                'setting_value' => $stored,
                'updated_at'    => date('Y-m-d H:i:s'),
            ], 'setting_key = ?', [$key]);
        } else {
            $db->insert('settings', [
                'setting_key'   => $key,
                'setting_value' => $stored,
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
        }

        // بروزرسانی کش درون‌حافظه
        self::$cache[$key] = is_string($stored) && $stored !== '' ? json_decode($stored, true) ?? $stored : $stored;
    }

    /**
     * 💾 ذخیره گروهی تنظیمات
     */
    public static function setMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            self::set($key, $value);
        }
    }

    /**
     * 📋 دریافت همه تنظیمات (برای صفحه تنظیمات)
     */
    public static function all(): array
    {
        self::load();
        return self::$cache;
    }

    /* ==================================================
     * 🏷️ کلیدهای استاندارد تنظیمات (ثابت‌های مرجع)
     * ================================================== */

    // 📇 اطلاعات پایه نمایندگی
    const KEY_AGENCY_NAME_FA   = 'agency_name_fa';    // نام فارسی نمایندگی
    const KEY_AGENCY_NAME_EN   = 'agency_name_en';    // نام انگلیسی نمایندگی
    const KEY_AGENCY_LOGO      = 'agency_logo';       // مسیر لوگوی نمایندگی
    const KEY_AGENCY_FAVICON   = 'agency_favicon';    // فاویکون
    const KEY_AGENCY_SLOGAN_FA = 'agency_slogan_fa';  // شعار فارسی
    const KEY_AGENCY_SLOGAN_EN = 'agency_slogan_en';  // شعار انگلیسی
    const KEY_MAIN_SITE        = 'agency_main_site';  // آدرس سایت اصلی

    // 📞 اطلاعات تماس (آرایه‌های چندتایی)
    const KEY_CONTACTS    = 'contacts';          // تلفن‌ها، موبایل‌ها، واتساپ، تلگرام
    const KEY_EMAILS      = 'emails';            // ایمیل‌ها
    const KEY_ADDRESSES   = 'addresses';         // آدرس‌های فیزیکی + مختصات + کدپستی
    const KEY_SOCIALS     = 'social_links';      // شبکه‌های اجتماعی

    // 🕐 ساعات کاری
    const KEY_WORK_HOURS  = 'work_hours';        // ساعات و روزهای کاری

    // 🛡️ ضمانت
    const KEY_WARRANTY    = 'warranty_settings'; // شرایط ضمانت

    // 💰 هزینه
    const KEY_COST        = 'cost_settings';     // عدم نمایش هزینه + متن جایگزین

    // 🌐 دامنه
    const KEY_DOMAIN      = 'domain_settings';   // فرمت دامنه برندها

    // 📨 ارسال درخواست
    const KEY_NOTIFY_EMAIL  = 'notify_email_settings';   // کانال ایمیل
    const KEY_NOTIFY_TELEGRAM = 'notify_telegram_settings'; // کانال تلگرام مستقیم
    const KEY_NOTIFY_GSCRIPT  = 'notify_gscript_settings';  // واسط گوگل اسکریپت
    const KEY_NOTIFY_BALE     = 'notify_bale_settings';     // کانال بله

    // 🔗 لینک‌دهی
    const KEY_LINKING     = 'link_settings';     // تنظیمات nofollow و لینک‌دهی

    // 🔤 فونت پیش‌فرض
    const KEY_DEFAULT_FONT = 'default_font_settings'; // فونت پیش‌فرض برندهای جدید
}
