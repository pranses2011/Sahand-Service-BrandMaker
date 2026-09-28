<?php
/**
 * 🔐 موتور TOTP — ورود دومرحله‌ای RFC 6238 (v2.34)
 * ================================================
 * پیاده‌سازی کامل TOTP بدون وابستگی خارجی:
 *   - تولید رمز Base32 (۱۶۰ بیت)
 *   - کد ۶ رقمی با HMAC-SHA1 و پنجره ۳۰ ثانیه
 *   - پذیرش پنجره ±۱ (خطای ساعت دستگاه کاربر)
 *   - URI استاندارد otpauth:// برای اپ‌های احرازکننده (Google Authenticator، آتنتیفایتر و…)
 *   - QR به‌صورت data-URI از کتابخانه بومی includes/phpqrcode.php
 *   - کدهای بازیابی ۸تایی (هرکد یک‌بار مصرف — هش SHA256 ذخیره می‌شود)
 *
 * @package SahandBrandMaker
 * @version 2.34.0
 */
class Totp
{
    /** ⏱️ طول گام زمانی (ثانیه) — استاندارد RFC 6238 */
    const PERIOD = 30;

    /** 🔢 طول کد */
    const DIGITS = 6;

    /** 🔁 پنجره تحمل خطای ساعت (± چند گام) */
    const WINDOW = 1;

    /** 🧾 تعداد کدهای بازیابی تولیدشده هنگام فعال‌سازی */
    const RECOVERY_CODES = 8;

    /* ==================================================
     * 🔤 Base32 — رمزگذاری/رمزگشایی
     * ================================================== */

    private const B32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** تولید رمز تصادفی Base32 */
    public static function generateSecret(int $length = 32): string
    {
        $out = '';
        $max = strlen(self::B32_CHARS) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::B32_CHARS[random_int(0, $max)];
        }
        return $out;
    }

    /** رمزگشایی Base32 → رشته باینری خام */
    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32) ?? '');
        if ($b32 === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($b32) as $char) {
            $pos = strpos(self::B32_CHARS, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int)bindec($byte));
            }
        }
        return $out;
    }

    /* ==================================================
     * 🔢 تولید و راستی‌آزمایی کد
     * ================================================== */

    /** کد TOTP برای یک گام زمانی خاص */
    public static function code(string $secret, ?int $timeSlice = null): string
    {
        if ($timeSlice === null) {
            $timeSlice = (int)floor(time() / self::PERIOD);
        }
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '';
        }
        // شمارنده ۸ بایتی big-endian
        $counter = pack('N2', ($timeSlice >> 32) & 0xFFFFFFFF, $timeSlice & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $counter, $key, true);
        // داینامیک_TRUNCATION طبق RFC 4226
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string)($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * ✅ راستی‌آزمایی کد واردشده با تحمل پنجره ±۱
     * مقایسه time-constant برای جلوگیری از حمله زمانی
     */
    public static function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return false;
        }
        $slice = (int)floor(time() / self::PERIOD);
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $expected = self::code($secret, $slice + $i);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }
        return false;
    }

    /* ==================================================
     * 🔗 URI و QR
     * ================================================== */

    /** URI استاندارد otpauth:// برای اپ‌های احرازکننده */
    public static function otpauthUri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS
            . '&period=' . self::PERIOD;
    }

    /**
     * 🖼️ QR به‌صورت data-URI PNG — برای نمایش در پنل
     * از کتابخانه بومی (بدون درخواست خارجی — امنیت و آفلاین)
     */
    public static function qrDataUri(string $uri): string
    {
        if (!class_exists('QRcode')) {
            require_once dirname(__DIR__) . '/includes/phpqrcode.php';
        }
        try {
            if (ob_get_length()) {
                ob_clean();
            }
            ob_start();
            QRcode::png($uri, null, QR_ECLEVEL_M, 6, 2);
            $png = ob_get_contents();
            ob_end_clean();
            if (strlen($png) < 100) {
                return '';
            }
            return 'data:image/png;base64,' . base64_encode($png);
        } catch (Throwable $e) {
            @error_log('[TOTP] QR render failed: ' . $e->getMessage());
            return '';
        }
    }

    /* ==================================================
     * 🧾 کدهای بازیابی (Recovery Codes)
     * ================================================== */

    /**
     * تولید ۸ کد بازیابی — خروجی: [کدهای خام برای نمایش یک‌بار، آرایه هش برای ذخیره]
     * فرمت هر کد: XXXX-XXXX (۹ کاراکتر از الفبای بدون ابهام)
     */
    public static function generateRecoveryCodes(): array
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $code = '';
            for ($j = 0; $j < 8; $j++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $formatted = substr($code, 0, 4) . '-' . substr($code, 4);
            $plain[] = $formatted;
            $hashes[] = self::hashRecoveryCode($formatted);
        }
        return ['plain' => $plain, 'hashes' => $hashes];
    }

    /** هش یک‌طرفه کد بازیابی (نرمال‌سازی ورودی: حذف فاصله/خط تیره + بزرگ) */
    public static function hashRecoveryCode(string $code): string
    {
        $norm = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
        return hash('sha256', 'sahand-rc:' . $norm);
    }

    /**
     * ✅ مصرف کد بازیابی — اگر کد معتبر بود هشش را از لیست حذف می‌کند
     * @param string $json  ستون totp_recovery کاربر (JSON آرایه هش‌ها)
     * @param string $code  کد واردشده کاربر
     * @return array [matched => bool, remainingJson => string]
     */
    public static function consumeRecoveryCode(string $json, string $code): array
    {
        $hashes = json_decode($json, true);
        if (!is_array($hashes) || $hashes === []) {
            return ['matched' => false, 'remainingJson' => $json ?: '[]'];
        }
        $target = self::hashRecoveryCode($code);
        $idx = array_search($target, $hashes, true);
        if ($idx === false) {
            return ['matched' => false, 'remainingJson' => $json];
        }
        unset($hashes[$idx]);
        $remaining = array_values($hashes);
        return ['matched' => true, 'remainingJson' => json_encode($remaining, JSON_UNESCAPED_SLASHES)];
    }

    /* ==================================================
     * 🤖 دستگاه مورد اعتماد (کوکی امضاشده ۳۰ روزه)
     * ================================================== */

    /** نام کوکی اعتماد */
    const TRUST_COOKIE = 'sahand_2fa_trust';

    /** کلید امضای کوکی — از settings (اولین بار تولید و ذخیره می‌شود) */
    private static function trustSecret(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $db = Database::getInstance();
            $row = $db->fetch("SELECT setting_value FROM settings WHERE setting_key = 'totp_trust_secret' LIMIT 1");
            if ($row && strlen((string)$row['setting_value']) >= 64) {
                return $cached = (string)$row['setting_value'];
            }
            $secret = bin2hex(random_bytes(32));
            if ($row) {
                $db->update('settings', ['setting_value' => $secret], "setting_key = 'totp_trust_secret'");
            } else {
                $db->insert('settings', ['setting_key' => 'totp_trust_secret', 'setting_value' => $secret]);
            }
            return $cached = $secret;
        } catch (Throwable $e) {
            // محیط بدون دیتابیس — کلتر فرّار این اجرا
            return $cached = hash('sha256', __FILE__ . PHP_VERSION);
        }
    }

    /** صدور کوکی اعتماد برای ۳۰ روز */
    public static function issueTrustCookie(int $userId): void
    {
        $exp = time() + 30 * 86400;
        $sig = hash_hmac('sha256', $userId . '|' . $exp, self::trustSecret());
        $value = $userId . '.' . $exp . '.' . $sig;
        // همگام‌سازی $_COOKIE برای خواندن در همان درخواست (و تست‌پذیری)
        $_COOKIE[self::TRUST_COOKIE] = $value;
        if (PHP_VERSION_ID >= 80100) {
            setcookie(self::TRUST_COOKIE, $value, [
                'expires' => $exp, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            ]);
        } else {
            setcookie(self::TRUST_COOKIE, $value, $exp, '/', '', false, true);
        }
    }

    /** اعتبارسنجی کوکی اعتماد — آیا این دستگاه می‌تواند 2FA را رد کند؟ */
    public static function isTrustedDevice(): bool
    {
        $raw = (string)($_COOKIE[self::TRUST_COOKIE] ?? '');
        $parts = explode('.', $raw);
        if (count($parts) !== 3) {
            return false;
        }
        [$uid, $exp, $sig] = $parts;
        if ((int)$exp < time() || (int)$uid < 1) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $uid . '|' . $exp, self::trustSecret()), $sig);
    }

    /** ابطال کوکی اعتماد (خروج یا غیرفعال‌سازی 2FA) */
    public static function revokeTrustCookie(): void
    {
        unset($_COOKIE[self::TRUST_COOKIE]);
        if (PHP_VERSION_ID >= 80100) {
            setcookie(self::TRUST_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        } else {
            setcookie(self::TRUST_COOKIE, '', time() - 3600, '/', '');
        }
    }
}
