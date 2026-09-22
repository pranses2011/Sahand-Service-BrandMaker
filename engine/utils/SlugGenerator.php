<?php
/**
 * 🔗 کلاس تولید اسلاگ — آدرس‌های SEO-فرندلی
 * ==========================================
 * تولید اسلاگ استاندارد از نام فارسی/انگلیسی با
 * پشتیبانی کامل حروف فارسی و تبدیل هوشمند.
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class SlugGenerator
{
    /** @var array نگاشت حروف خاص به معادل ساده */
    private static $charMap = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'آ', 'ة' => 'ه', 'ؤ' => 'و',
        'ئ' => 'ی', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3',
        '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8',
        '٩' => '9',
    ];

    /**
     * 🔗 تولید اسلاگ از متن دلخواه
     *
     * @param string $text متن ورودی (فارسی یا انگلیسی)
     * @param bool   $preferEnglish در صورت وجود حروف لاتین فقط از آن‌ها استفاده شود
     */
    public static function generate(string $text, bool $preferEnglish = false): string
    {
        $text = trim($text);
        $text = strtr($text, self::$charMap);

        // اگر متن شامل حروف لاتین معنادار است و ترجیح انگلیسی فعال است
        if ($preferEnglish && preg_match('/[a-zA-Z0-9]{3,}/', $text)) {
            $text = preg_replace('/[^\x00-\x7F]+/u', ' ', $text) ?? $text; // حذف غیرلاتین
        }

        // تبدیل به lowercase لاتین
        $slug = mb_strtolower($text);
        // فاصله و جداکننده‌ها به خط تیره
        $slug = preg_replace('/[\s\_\-\.\+]+/u', '-', $slug) ?? $slug;
        // حذف کاراکترهای غیرمجاز: فقط لاتین، عدد، فارسی و خط تیره
        $slug = preg_replace('/[^a-z0-9\-\x{0600}-\x{06FF}\x{200c}]/u', '', $slug) ?? $slug;
        // فشرده‌سازی خط تیره‌های متوالی
        $slug = preg_replace('/-{2,}/', '-', $slug) ?? $slug;
        // حذف خط تیره ابتدا و انتها
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'page-' . substr(uniqid(), -6);
    }

    /**
     * 🔗 تولید اسلاگ یکتا (بررسی از دیتابیس در صورت تکرار)
     *
     * @param string $text متن ورودی
     * @param string $table نام جدول برای بررسی یکتایی
     * @param string $column نام ستون اسلاگ
     * @param int    $excludeId شناسه رکورد فعلی (برای ویرایش)
     * @param int    $brandId شناسه برند (در جداول برند-محور)
     */
    public static function unique(string $text, string $table, string $column = 'slug', int $excludeId = 0, int $brandId = 0): string
    {
        $base = self::generate($text);
        $db = Database::getInstance();
        $slug = $base;
        $counter = 2;

        while (true) {
            $params = [$slug];
            $where = "`{$column}` = ?";
            if ($excludeId > 0) {
                $where .= ' AND id != ?';
                $params[] = $excludeId;
            }
            if ($brandId > 0 && $table === 'brand_articles') {
                $where .= ' AND brand_id = ?';
                $params[] = $brandId;
            }
            $exists = $db->fetchValue("SELECT COUNT(*) FROM `{$table}` WHERE {$where}", $params);
            if (!(int)$exists) {
                return $slug;
            }
            $slug = $base . '-' . $counter;
            $counter++;
            // جلوگیری از حلقه بی‌نهایت
            if ($counter > 200) {
                return $base . '-' . substr(uniqid(), -4);
            }
        }
    }

    /**
     * 🌐 تولید نام دامنه برند از نام انگلیسی
     * مثلاً Samsung → samsung.ea-fixer.ir
     */
    public static function brandDomain(string $nameEn, string $format = ''): string
    {
        $format = $format ?: (string)Config::get('brand_domain_format', '{brand}.ea-fixer.ir');
        $clean = preg_replace('/[^a-z0-9\-]/', '', mb_strtolower(trim($nameEn)));
        $clean = $clean !== '' ? $clean : 'brand';
        return str_replace('{brand}', $clean, $format);
    }
}
