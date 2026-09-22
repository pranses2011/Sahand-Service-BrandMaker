<?php
/**
 * 📝 کلاس لاگر — ثبت فعالیت‌ها و رویدادها
 * =========================================
 * فعالیت‌های کاربران در دیتابیس و رویدادهای سیستمی
 * در فایل‌های متنی ثبت می‌شوند (دو سطح جداگانه).
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class Logger
{
    /** @var array سطح‌های مجاز لاگ فایل */
    private static $levels = ['INFO', 'WARNING', 'ERROR', 'DEBUG'];

    /**
     * 👤 ثبت فعالیت کاربر در دیتابیس
     *
     * @param int    $userId  شناسه کاربر
     * @param string $action  عنوان اقدام (مثلاً «ویرایش برند»)
     * @param string $details جزئیات اقدام
     */
    public static function activity(int $userId, string $action, string $details = ''): void
    {
        try {
            Database::getInstance()->insert('activity_logs', [
                'user_id'    => $userId,
                'action'     => mb_substr($action, 0, 190),
                'details'    => mb_substr($details, 0, 5000),
                'ip_address' => self::clientIp(),
                'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {
            // ثبت خطای لاگ در فایل تا چرخه بی‌نهایت ایجاد نشود
            error_log('[Logger/DB] ' . $e->getMessage());
        }
    }

    /**
     * 📄 ثبت رویداد سیستمی در فایل لاگ
     *
     * @param string $level  سطح رویداد (INFO|WARNING|ERROR|DEBUG)
     * @param string $message پیام رویداد
     * @param array  $context داده‌های تکمیلی
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        if (!in_array($level, self::$levels, true)) {
            $level = 'INFO';
        }
        // در حالت غیر دیباگ، پیام‌های DEBUG ثبت نمی‌شوند
        if ($level === 'DEBUG' && !SAHAND_DEBUG) {
            return;
        }

        $line = sprintf(
            "[%s] [%s] %s %s%s",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : '',
            PHP_EOL
        );

        // 🔒 ایجاد پوشه لاگ در صورت نبود
        if (!is_dir(LOGS_PATH)) {
            @mkdir(LOGS_PATH, 0755, true);
        }
        @file_put_contents(LOGS_PATH . '/system-' . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /** ℹ️ ثبت رویداد اطلاعاتی */
    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    /** ⚠️ ثبت هشدار */
    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }

    /** ❌ ثبت خطا */
    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }

    /** 🐛 ثبت اطلاعات دیباگ (فقط در حالت توسعه) */
    public static function debug(string $message, array $context = []): void
    {
        self::log('DEBUG', $message, $context);
    }

    /**
     * 🌐 استخراج IP واقعی کاربر (با پشتیبانی از پروکسی)
     */
    public static function clientIp(): string
    {
        // در محیط پروکسی/CDN هدرهای استاندارد بررسی می‌شوند
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * 📋 دریافت فعالیت‌های اخیر (برای داشبورد)
     */
    public static function recentActivities(int $limit = 15): array
    {
        try {
            return Database::getInstance()->fetchAll(
                'SELECT a.*, u.full_name FROM activity_logs a
                 LEFT JOIN users u ON u.id = a.user_id
                 ORDER BY a.id DESC LIMIT ' . (int)$limit
            );
        } catch (Exception $e) {
            return [];
        }
    }
}
