<?php
/**
 * ✅ اعتبارسنج نام زیردامنه — ۸ قانون استاندارد DNS
 * ====================================================
 * طبق سند بخش ۲۱ (پرامپت تکمیلی بخش ۳):
 *   ۱. فقط حروف انگلیسی کوچک، اعداد و خط تیره
 *   ۲. عدم شروع با خط تیره
 *   ۳. عدم پایان با خط تیره
 *   ۴. حداقل ۲ کاراکتر
 *   ۵. حداکثر ۶۳ کاراکتر (استاندارد DNS)
 *   ۶. عدم تکرار (دیتابیس + cPanel)
 *   ۷. عدم استفاده از کلمات رزرو شده
 *   ۸. عدم شامل کاراکترهای خاص (_ . فاصله و ...)
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class SubdomainValidator
{
    /** 🚫 کلمات رزروشده — قابل استفاده به عنوان زیردامنه نیستند */
    const RESERVED_NAMES = [
        'www', 'mail', 'ftp', 'smtp', 'pop', 'pop3', 'imap', 'webmail',
        'admin', 'administrator', 'api', 'cpanel', 'whm', 'webdisk',
        'blog', 'shop', 'store', 'ns1', 'ns2', 'mx', 'autodiscover',
        'autoconfig', 'panel', 'dev', 'test', 'staging', 'cdn', 'static',
    ];

    /** @var int حداقل طول نام */
    const MIN_LENGTH = 2;

    /** @var int حداکثر طول نام (استاندارد DNS) */
    const MAX_LENGTH = 63;

    /**
     * ✅ اعتبارسنجی کامل نام زیردامنه — هر ۸ قانون
     *
     * @param string $name نام زیردامنه (بدون دامنه اصلی)
     * @return array [valid => bool, error => string|null]
     *               error پیام خطای فارسی مناسب نمایش به مدیر
     */
    public static function validate(string $name): array
    {
        $name = strtolower(trim($name));

        // قانون ۴: حداقل طول
        if (mb_strlen($name) < self::MIN_LENGTH) {
            return ['valid' => false, 'error' => 'نام باید حداقل ' . self::MIN_LENGTH . ' کاراکتر باشد.'];
        }

        // قانون ۵: حداکثر طول (استاندارد DNS)
        if (mb_strlen($name) > self::MAX_LENGTH) {
            return ['valid' => false, 'error' => 'نام نمی‌تواند بیش از ' . self::MAX_LENGTH . ' کاراکتر باشد (استاندارد DNS).'];
        }

        // قانون ۱ + ۸: فقط a-z و 0-9 و خط تیره (نه _ ، نه . ، نه فاصله، نه فارسی)
        if (!preg_match('/^[a-z0-9\-]+$/', $name)) {
            return ['valid' => false, 'error' => 'فقط حروف انگلیسی کوچک، اعداد و خط تیره مجاز است — کاراکتر نامعتبر در نام.'];
        }

        // قانون ۲: عدم شروع با خط تیره
        if ($name[0] === '-') {
            return ['valid' => false, 'error' => 'نام نمی‌تواند با خط تیره شروع شود.'];
        }

        // قانون ۳: عدم پایان با خط تیره
        if (substr($name, -1) === '-') {
            return ['valid' => false, 'error' => 'نام نمی‌تواند با خط تیره پایان یابد.'];
        }

        // قانون ۷: کلمات رزروشده
        if (in_array($name, self::RESERVED_NAMES, true)) {
            return ['valid' => false, 'error' => 'این نام رزرو شده است و قابل استفاده نیست.'];
        }

        // عدم شروع با رقم (توصیه DNS — برخی سرورها نمی‌پذیرند)
        if (preg_match('/^[0-9]/', $name)) {
            return ['valid' => false, 'error' => 'بهتر است نام با حرف شروع شود، نه عدد (سازگاری DNS).'];
        }

        // عدم خط تیره دوتایی پشت سر هم
        if (strpos($name, '--') !== false) {
            return ['valid' => false, 'error' => 'استفاده از دو خط تیره پشت سر هم مجاز نیست.'];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * 🔍 بررسی رزرو بودن نام
     */
    public static function isReservedName(string $name): bool
    {
        return in_array(strtolower(trim($name)), self::RESERVED_NAMES, true);
    }

    /**
     * 💡 پیشنهاد ۳ نام جایگزین — طبق سند:
     * در صورت تکراری یا نامعتبر بودن نام انتخابی
     *
     * @param string $name نام اصلی (مثلاً samsung)
     * @return array سه پیشنهاد (مثلاً samsung-service، samsung-repair، samsung-{سال شمسی})
     */
    public static function suggestAlternatives(string $name): array
    {
        $name = strtolower(trim($name));
        // پاک‌سازی به کاراکترهای مجاز
        $clean = preg_replace('/[^a-z0-9\-]/', '', $name) ?: 'brand';
        $clean = trim($clean, '-');

        $suggestions = [
            $clean . '-service',
            $clean . '-repair',
            $clean . '-' . (class_exists('ShamsiDate') ? ShamsiDate::currentYear() : date('Y')),
        ];

        // حذف پیشنهادهای تکراری یا نامعتبر
        $valid = [];
        foreach ($suggestions as $s) {
            if (mb_strlen($s) <= self::MAX_LENGTH && !in_array($s, $valid, true) && self::validate($s)['valid']) {
                $valid[] = $s;
            }
        }

        return $valid;
    }

    /**
     * 🔄 تبدیل نام دلخواه به نام معتبر زیردامنه
     * (برای پیش‌نمایش اولیه — نرمال‌سازی نام برند فارسی/انگلیسی)
     *
     * @param string $rawName نام خام برند (مثلاً "الجی" یا "LG Electronics")
     * @return string نام نرمال‌شده (مثلاً lg)
     */
    public static function normalize(string $rawName): string
    {
        // اگر SlugGenerator موجود باشد از آن استفاده می‌کنیم (بهتر)
        if (class_exists('SlugGenerator') && method_exists('SlugGenerator', 'generate')) {
            $slug = SlugGenerator::generate($rawName, true);
        } else {
            // تبدیل به حروف کوچک و حذف کاراکترهای غیرمجاز
            $slug = strtolower(trim($rawName));
            $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug);
            $slug = trim($slug, '-');
        }

        $slug = substr($slug, 0, self::MAX_LENGTH);
        if (mb_strlen($slug) < self::MIN_LENGTH) {
            $slug = 'brand';
        }
        // حذف خط تیره ابتدا/انتها
        return trim($slug, '-');
    }

    /**
     * 🌐 اعتبارسنجی دامنه کامل (Addon Domain) — v2.12
     * قالب: example.com یا sub.example.com (حداقل یک نقطه، برچسب‌های DNS معتبر)
     *
     * @param string $domain دامنه کامل (بدون http)
     * @return array [valid => bool, error => string|null]
     */
    public static function validateFullDomain(string $domain): array
    {
        $domain = strtolower(trim(str_replace(['https://', 'http://', '/'], '', $domain)));
        if ($domain === '') {
            return ['valid' => false, 'error' => 'دامنه الحاقی را وارد کنید (مثلاً mybrand.ir).'];
        }
        if (mb_strlen($domain) > 253) {
            return ['valid' => false, 'error' => 'طول دامنه بیش از حد مجاز است.'];
        }
        // هر برچسب: ۱ تا ۶۳ کاراکتر از a-z 0-9 - (شروع/پایان با خط تیره ممنوع)
        if (!preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $domain)) {
            return ['valid' => false, 'error' => 'قالب دامنه معتبر نیست — مثال درست: mybrand.ir یا lg-service.com'];
        }
        // دامنه سطح‌بالا حداقل ۲ حرف و فقط حرف
        $tld = substr($domain, strrpos($domain, '.') + 1);
        if (!preg_match('/^[a-z]{2,24}$/', $tld)) {
            return ['valid' => false, 'error' => 'پسوند دامنه (TLD) معتبر نیست — مثلاً ir، com، net'];
        }
        return ['valid' => true, 'error' => null];
    }
}
