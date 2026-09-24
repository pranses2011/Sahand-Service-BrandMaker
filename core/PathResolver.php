<?php
/**
 * 📂 حل‌کننده الگوی مسیر Document Root زیردامنه‌ها
 * ==================================================
 * طبق سند بخش ۲۱ (پرامپت تکمیلی بخش ۲):
 *   - الگوی قابل تنظیم با متغیرهای پویا
 *   - ۶ الگوی پیش‌فرض آماده
 *   - اعتبارسنجی امنیتی کامل
 *   - پیش‌نمایش مسیر برای برند نمونه
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class PathResolver
{
    /** 📌 الگوی پیش‌فرض توصیه‌شده */
    const DEFAULT_PATTERN = '/public_html/brands/{brand_slug}';

    /** @var array|null کش تنظیمات اتصال cPanel */
    private static $settingsCache = null;

    /**
     * 📋 الگوهای آماده پیشنهادی — طبق جدول سند
     *
     * @return array کلید => [الگو، عنوان فارسی، توصیه‌شده؟]
     */
    public static function getPresetPatterns(): array
    {
        return [
            'default'     => ['/public_html/brands/{brand_slug}', true],
            'flat'        => ['/public_html/{brand_slug}', false],
            'sites'       => ['/public_html/sites/{brand_slug}', false],
            'subdomains'  => ['/public_html/subdomains/{brand_slug}', false],
            'yearly'      => ['/public_html/{year}/{brand_slug}', false],
            'home_brands' => ['/home/{username}/brands/{brand_slug}', false],
        ];
    }

    /**
     * 📖 متغیرهای قابل استفاده در الگو — با مقدار نمونه
     *
     * @return array متغیر => [توضیح فارسی، مثال]
     */
    public static function getPatternVariables(): array
    {
        return [
            '{brand_slug}'    => ['اسلاگ انگلیسی برند (مثلاً samsung)', 'samsung'],
            '{brand_name_en}' => ['نام انگلیسی برند', 'samsung'],
            '{brand_id}'      => ['شناسه عددی برند', '12'],
            '{year}'          => ['سال شمسی جاری', ShamsiDate::currentYear()],
            '{month}'         => ['ماه شمسی جاری', ShamsiDate::currentMonth()],
            '{username}'      => ['نام کاربری cPanel', 'user'],
            '{public_html}'   => ['مسیر کامل public_html', '/home/user/public_html'],
            '{home}'          => ['مسیر home directory', '/home/user'],
        ];
    }

    /**
     * ✅ اعتبارسنجی الگو — طبق جدول سند:
     *   ۱. حداقل شامل {brand_slug} یا {brand_id} (منحصربفرد بودن)
     *   ۲. شروع با /
     *   ۳. بدون کاراکترهای غیرمجاز (< > : " | ? *)
     *   ۴. حداکثر ۲۵۵ کاراکتر
     *   ۵. عدم شروع با مسیرهای حساس (/etc، /root، /bin، ...)
     *   ۶. فقط متغیرهای شناخته‌شده
     *
     * @param string $pattern الگوی ورودی
     * @return array [valid => bool, error => string|null]
     */
    public static function validatePattern(string $pattern): array
    {
        $pattern = trim($pattern);

        // خالی نباشد
        if ($pattern === '') {
            return ['valid' => false, 'error' => 'الگوی مسیر نمی‌تواند خالی باشد.'];
        }

        // قانون ۲: شروع با /
        if ($pattern[0] !== '/') {
            return ['valid' => false, 'error' => 'الگو باید با / شروع شود (مسیر مطلق).'];
        }

        // قانون ۴: حداکثر طول
        if (mb_strlen($pattern) > 255) {
            return ['valid' => false, 'error' => 'طول الگو نمی‌تواند بیش از ۲۵۵ کاراکتر باشد.'];
        }

        // قانون ۳: کاراکترهای غیرمجاز
        if (preg_match('/[<>:"|?*\\\\]/', $pattern)) {
            return ['valid' => false, 'error' => 'کاراکتر غیرمجاز در الگو (مجاز: حروف، رقم، /، -، _ و متغیرها).'];
        }

        // فاصله مجاز نیست
        if (strpos($pattern, ' ') !== false) {
            return ['valid' => false, 'error' => 'استفاده از فاصله در مسیر مجاز نیست.'];
        }

        // قانون ۵: مسیرهای حساس
        $forbiddenPrefixes = ['/etc', '/root', '/bin', '/sbin', '/usr', '/var', '/proc', '/sys', '/dev', '/boot', '/tmp', '/opt', '/lib'];
        foreach ($forbiddenPrefixes as $prefix) {
            if (strpos($pattern, $prefix) === 0) {
                return ['valid' => false, 'error' => 'مسیرهای سیستمی (' . $prefix . '/...) مجاز نیست — مسیر باید داخل هاست باشد.'];
            }
        }

        // قانون ۶: فقط متغیرهای شناخته‌شده
        preg_match_all('/\{[a-z_]+\}/', $pattern, $matches);
        $known = array_keys(self::getPatternVariables());
        foreach ($matches[0] as $var) {
            if (!in_array($var, $known, true)) {
                return ['valid' => false, 'error' => 'متغیر ناشناخته: ' . $var];
            }
        }

        // قانون ۱: منحصربفرد بودن
        if (strpos($pattern, '{brand_slug}') === false && strpos($pattern, '{brand_id}') === false) {
            return ['valid' => false, 'error' => 'الگو باید شامل {brand_slug} یا {brand_id} باشد تا مسیر هر برند منحصربفرد شود.'];
        }

        // قطعه‌های ثابت باید فقط کاراکترهای مسیر امن باشند
        $staticParts = preg_replace('/\{[a-z_]+\}/', '', $pattern);
        if (!preg_match('#^[a-zA-Z0-9/_\-\.]*$#', $staticParts)) {
            return ['valid' => false, 'error' => 'کاراکتر نامعتبر در بخش ثابت مسیر.'];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * 🔄 جایگذاری متغیرها و تولید مسیر نهایی
     *
     * @param string      $pattern  الگو (اگر خالی — از تنظیمات خوانده می‌شود)
     * @param array       $brand    ردیف برند (slug، name_en، id)
     * @param string|null $username نام کاربری cPanel (اگر null — از تنظیمات)
     * @return string مسیر نهایی (مثلاً /public_html/brands/samsung)
     */
    public static function resolveDocumentRoot(array $brand, string $pattern = '', ?string $username = null): string
    {
        // الگو از تنظیمات ذخیره‌شده
        if ($pattern === '') {
            $settings = self::getSettings();
            $pattern = (string)($settings['doc_root_pattern'] ?? self::DEFAULT_PATTERN);
        }
        if ($username === null) {
            $settings = $settings ?? self::getSettings();
            $username = (string)($settings['cpanel_username'] ?? 'user');
        }

        // home directory واقعی (اگر در تنظیمات ذخیره شده باشد)
        $homeDir = '/home/' . $username;

        // 🔄 جایگذاری متغیرها — ترتیب مهم نیست چون متغیرها همپوشان نیستند
        $replacements = [
            '{brand_slug}'    => (string)($brand['slug'] ?? ''),
            '{brand_name_en}' => strtolower((string)($brand['name_en'] ?? $brand['slug'] ?? '')),
            '{brand_id}'      => (string)($brand['id'] ?? ''),
            '{year}'          => ShamsiDate::currentYear(),
            '{month}'         => ShamsiDate::currentMonth(),
            '{username}'      => $username,
            '{home}'          => $homeDir,
        ];

        $path = strtr($pattern, $replacements);

        // {public_html} بعد از ساخت پایه جایگزین می‌شود (چون ممکن است شامل خود {home} باشد)
        $path = str_replace('{public_html}', $homeDir . '/public_html', $path);

        // پاک‌سازی نهایی — حذف اسلش‌های تکراری و انتهایی (به جز ریشه)
        $path = preg_replace('#/+#', '/', $path);
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    /**
     * 🔍 پیش‌نمایش مسیر برای برند نمونه — طبق سند:
     * «برای برند samsung: /public_html/brands/samsung»
     *
     * @param string $pattern الگو
     * @return array مثال‌ها برای برندهای نمونه
     */
    public static function previewPath(string $pattern, array $sampleBrands = []): array
    {
        if (empty($sampleBrands)) {
            $sampleBrands = [
                ['id' => 12, 'slug' => 'samsung', 'name_en' => 'Samsung'],
                ['id' => 15, 'slug' => 'lg', 'name_en' => 'LG'],
            ];
        }

        $previews = [];
        foreach ($sampleBrands as $sample) {
            $previews[] = [
                'brand' => $sample['slug'],
                'path'  => self::resolveDocumentRoot($sample, $pattern),
            ];
        }
        return $previews;
    }

    /**
     * 💾 ذخیره الگو در جدول cpanel_settings
     *
     * @param string $pattern الگوی اعتبارسنجی‌شده
     * @param string $presetName نام الگوی آماده (اگر از لیست انتخاب شده)
     */
    public static function savePattern(string $pattern, string $presetName = 'custom'): bool
    {
        $check = self::validatePattern($pattern);
        if (!$check['valid']) {
            return false;
        }

        Database::getInstance()->update('cpanel_settings', [
            'doc_root_pattern' => $pattern,
            'doc_root_preset'  => $presetName,
            'updated_at'       => date('Y-m-d H:i:s'),
        ], 'id = 1');

        self::$settingsCache = null; // باطل‌سازی کش
        return true;
    }

    /**
     * 🔄 بازگشت به الگوی پیش‌فرض
     */
    public static function resetPattern(): bool
    {
        Database::getInstance()->update('cpanel_settings', [
            'doc_root_pattern' => self::DEFAULT_PATTERN,
            'doc_root_preset'  => 'default',
            'updated_at'       => date('Y-m-d H:i:s'),
        ], 'id = 1');
        self::$settingsCache = null;
        return true;
    }

    /**
     * 📥 خواندن تنظیمات cPanel (جدول تک‌ردیفی id=1) با کش درون‌حافظه
     *
     * @return array تنظیمات (رمزها رمزگشایی نشده — رمزگشایی فقط در CpanelAPI)
     */
    public static function getSettings(): array
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }
        try {
            $row = Database::getInstance()->fetch('SELECT * FROM cpanel_settings WHERE id = 1');
            self::$settingsCache = $row ?: [];
        } catch (Throwable $e) {
            // جدول هنوز ساخته نشده (نصب تازه) — خالی
            self::$settingsCache = [];
        }
        return self::$settingsCache;
    }

    /**
     * 🧹 باطل‌سازی کش تنظیمات (بعد از ذخیره تنظیمات جدید)
     */
    public static function clearCache(): void
    {
        self::$settingsCache = null;
    }
}
