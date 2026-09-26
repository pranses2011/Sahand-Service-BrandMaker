<?php
/**
 * 🗺️ GeoIP محلی — بدون هیچ API خارجی
 * =====================================
 * تشخیص کشور و شهر از پیشوند IP با دیتابیس داخلی
 * (تمرکز بر IP های ایران + دامنه‌های بین‌المللی عمومی)
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class GeoIP
{
    /** @var array|null کش دیتابیس پیشوندها */
    private static $ranges = null;

    /**
     * 📥 بارگذاری دیتابیس پیشوندهای IP از فایل‌های محلی
     * ساختار: بازه‌های IPv4 ایران به تفکیک استان/شهر
     */
    private static function load(): array
    {
        if (self::$ranges !== null) {
            return self::$ranges;
        }
        self::$ranges = [];
        $file = dirname(__DIR__) . '/geoip/iran-ranges.php';
        if (file_exists($file)) {
            $ranges = include $file;
            if (is_array($ranges)) {
                self::$ranges = $ranges;
            }
        }
        return self::$ranges;
    }

    /**
     * 🌍 تشخیص اطلاعات جغرافیایی IP
     *
     * 🚨 v2.30 — «طولانی‌ترین تطابق» (longest-prefix match): قبلاً اولین
     * رنج منطبق برمی‌گشت و چون رنج‌های سراسری بزرگ (تهران) بالای فایل
     * بودند، رنج‌های استانی دقیق‌تر (تبریز/شیراز/ساری/...) که پایین‌تر
     * تعریف شده بودند هرگز انتخاب نمی‌شدند → نقشه همیشه یک‌شهره/خالی!
     * اکنون بین همه رنج‌های منطبق، «محدودترین» (کوچک‌ترین بازه) برنده است.
     *
     * @param string $ip آدرس IP
     * @return array ['country' => 'IR', 'country_fa' => 'ایران', 'city' => 'تهران', 'province' => 'تهران']
     */
    public static function lookup(string $ip): array
    {
        $default = ['country' => 'IR', 'country_fa' => 'ایران', 'city' => '', 'province' => ''];
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $default;
        }
        $ipLong = ip2long($ip);
        $best = null;
        $bestSize = null;
        foreach (self::load() as $range) {
            if ($ipLong >= $range[0] && $ipLong <= $range[1]) {
                $size = $range[1] - $range[0];
                if ($bestSize === null || $size < $bestSize) {
                    $bestSize = $size;
                    $best = $range;
                }
            }
        }
        if ($best !== null) {
            return [
                'country'   => 'IR',
                'country_fa'=> 'ایران',
                'city'      => $best[2] ?? '',
                'province'  => $best[3] ?? '',
            ];
        }
        return $default;
    }

    /**
     * 🏙️ استخراج شهر از IP
     */
    public static function city(string $ip): string
    {
        return self::lookup($ip)['city'];
    }

    /**
     * 🌐 استخراج کد کشور
     */
    public static function country(string $ip): string
    {
        return self::lookup($ip)['country'];
    }

    /**
     * 📊 آمار پراکندگی شهرها (برای نمودار نقشه‌ای)
     *
     * @return array ['تهران' => 152, 'مشهد' => 45, ...]
     */
    public static function citiesDistribution(array $ipPrefixes): array
    {
        $dist = [];
        foreach ($ipPrefixes as $prefix) {
            $city = self::city((string)$prefix);
            if ($city !== '') {
                $dist[$city] = ($dist[$city] ?? 0) + 1;
            }
        }
        arsort($dist);
        return $dist;
    }
}
