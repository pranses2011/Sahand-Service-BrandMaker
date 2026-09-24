<?php
/**
 * 🔐 کلاس رمزنگاری افزونه استقرار — AES-256-CBC
 * ==============================================
 * رمزنگاری توکن cPanel و رمز FTP قبل از ذخیره در دیتابیس
 * (الزام بند ۲۰ چک‌لیست سند بخش ۲۱)
 *
 * کلید رمزنگاری: یک بار به صورت تصادفی تولید و در
 * cache/.deploy_secret ذخیره می‌شود (خارج از دسترسی وب).
 * ⚠️ اگر این فایل حذف شود توکن‌ها قابل بازیابی نیستند
 * و باید مجدداً وارد شوند.
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class DeployCrypto
{
    /** @var string|null کلید رمزنگاری کش‌شده */
    private static $key = null;

    /**
     * 🔑 دریافت کلید رمزنگاری (ایجاد در صورت نبود)
     * کلید ۳۲ بایتی تصادفی — دقیقاً برای AES-256
     */
    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $keyFile = CACHE_PATH . '/.deploy_secret';

        // 🆕 تولید کلید جدید در صورت نبود
        if (!file_exists($keyFile)) {
            $bytes = random_bytes(32); // ۲۵۶ بیت
            if (!is_dir(CACHE_PATH)) {
                @mkdir(CACHE_PATH, 0755, true);
            }
            // ذخیره هگز — با محدودیت دسترسی
            if (@file_put_contents($keyFile, bin2hex($bytes), LOCK_EX) === false) {
                throw new RuntimeException('امکان ذخیره کلید رمزنگاری وجود ندارد — پوشه cache قابل نوشتن نیست.');
            }
            @chmod($keyFile, 0600); // فقط مالک قابل خواندن
        }

        $hex = @file_get_contents($keyFile);
        if ($hex === false || strlen(trim($hex)) < 64) {
            throw new RuntimeException('کلید رمزنگاری نامعتبر است.');
        }

        self::$key = hex2bin(substr(trim($hex), 0, 64));
        return self::$key;
    }

    /**
     * 🔒 رمزنگاری متن (AES-256-CBC + HMAC برای یکپارچگی)
     *
     * @param string $plain متن خام (توکن / رمز عبور)
     * @return string متن رمزشده base64 (پیشوند iv + داده)
     */
    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        $key = self::key();
        $iv = random_bytes(16); // بردار اولیه — CBC
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            throw new RuntimeException('خطا در رمزنگاری — openssl در دسترس نیست.');
        }

        // HMAC-SHA256 برای تشخیص دستکاری (Encrypt-then-MAC)
        $mac = hash_hmac('sha256', $iv . $cipher, $key, true);

        // خروجی: base64(iv + mac + cipher)
        return base64_encode($iv . $mac . $cipher);
    }

    /**
     * 🔓 رمزگشایی متن
     *
     * @param string $encrypted متن رمزشده از encrypt()
     * @return string متن خام — رشته خالی اگر ورودی خالی/نامعتبر باشد
     */
    public static function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        $raw = base64_decode($encrypted, true);
        // حداقل طول: iv(16) + mac(32) + cipher(16) = 64
        if ($raw === false || strlen($raw) < 64) {
            return '';
        }

        $key = self::key();
        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 32);
        $cipher = substr($raw, 48);

        // 🔍 راستی‌آزمایی HMAC قبل از رمزگشایی
        $expected = hash_hmac('sha256', $iv . $cipher, $key, true);
        if (!hash_equals($expected, $mac)) {
            error_log('[DeployCrypto] HMAC نامعتبر — داده دستکاری شده یا کلید عوض شده است.');
            return '';
        }

        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }

    /**
     * 🎭 ماسک کردن توکن برای نمایش در پنل
     * فقط ۴ کاراکتر اول و ۴ کاراکتر آخر نمایش داده می‌شود
     *
     * @param string $secret توکن / رمز خام
     * @return string مثلاً ABCD****************WXYZ
     */
    public static function mask(string $secret): string
    {
        $len = strlen($secret);
        if ($len <= 8) {
            return str_repeat('*', max($len, 4));
        }
        return substr($secret, 0, 4) . str_repeat('*', min($len - 8, 20)) . substr($secret, -4);
    }
}
