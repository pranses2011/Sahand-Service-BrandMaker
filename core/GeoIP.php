<?php
/**
 * 🗺️ GeoIP سه‌لایه — کش دیتابیس + سرویس‌های خارجی + رنج‌های محلی
 * ================================================================
 * 🚨 v2.31 — ریشه «پراکندگی جغرافیایی غلط» (کاربر در تبریز بود و
 * اصفهان نشان داده می‌شد): دیتابیس استاتیک IP نمی‌تواند محل واقعی
 * کاربران اپراتورهای موبایل (همراه اول/ایرانسل/رایتل) را تشخیص دهد —
 * استخر IP سراسری است و هر برچسب شهری روی آن «حدس» است.
 * ✅ اکنون سه لایه:
 *   ① کش دیتابیس (geoip_cache) — نتیجه سرویس دقیق، ۴۵ روز معتبر
 *   ② سرویس‌های خارجی دقیق (ip-api.com با نام فارسی + ipwho.is پشتیبان)
 *   ③ رنج‌های محلی (geoip/iran-ranges.php) — آخرین پشتیبان آفلاین
 *
 * @package SahandBrandMaker
 * @version 2.0.0
 */
class GeoIP
{
    /** @var array|null کش دیتابیس پیشوندهای محلی */
    private static $ranges = null;

    /** @var array حافظه درون‌درخواست (prefix => نتیجه) */
    private static $memo = [];

    /** @var bool آیا سرویس خارجی در این اجرا هرگز جواب داد؟ (قطعی شبکه) */
    private static $externalDead = false;

    /** TTL کش جغرافیایی در روزها */
    private const CACHE_TTL_DAYS = 45;

    /**
     * 🏷️ نرمال‌سازی نام استان انگلیسی → فارسی
     * سرویس‌های مختلف املا‌های متفاوتی برمی‌گردانند.
     */
    private static function regionFa(string $en): string
    {
        static $map = null;
        if ($map === null) {
            $raw = [
                'تهران' => ['tehran'],
                'البرز' => ['alborz'],
                'اصفهان' => ['isfahan', 'esfahan', 'isfehan'],
                'فارس' => ['fars', 'fars province'],
                'خراسان رضوی' => ['razavi khorasan', 'khorasan razavi', 'khorasan e razavi', 'khorasan'],
                'خراسان شمالی' => ['north khorasan', 'khorasan shomali', 'khorasan e shomali', 'northern khorasan'],
                'خراسان جنوبی' => ['south khorasan', 'khorasan jonubi', 'khorasan e jonubi', 'southern khorasan'],
                'آذربایجان شرقی' => ['east azarbaijan', 'eastern azarbaijan', 'azarbaijan e sharqi', 'east azerbaijan', 'azerbaijan e sharqi', 'east azarbayjan'],
                'آذربایجان غربی' => ['west azarbaijan', 'western azarbaijan', 'azarbaijan e gharbi', 'west azerbaijan', 'azerbaijan e gharbi', 'west azarbayjan'],
                'اردبیل' => ['ardabil', 'ardebil'],
                'گیلان' => ['gilan'],
                'مازندران' => ['mazandaran'],
                'گلستان' => ['golestan'],
                'سمنان' => ['semnan'],
                'قم' => ['qom', 'ghom'],
                'مرکزی' => ['markazi', 'central province'],
                'قزوین' => ['qazvin', 'ghazvin', 'qazvin province'],
                'زنجان' => ['zanjan'],
                'کردستان' => ['kordestan', 'kurdistan'],
                'کرمانشاه' => ['kermanshah', 'kermanshahan', 'bakhtaran'],
                'همدان' => ['hamadan', 'hamedan'],
                'کرمان' => ['kerman'],
                'یزد' => ['yazd'],
                'بوشهر' => ['bushehr', 'booshehr'],
                'هرمزگان' => ['hormozgan'],
                'خوزستان' => ['khuzestan', 'khuzistan'],
                'چهارمحال و بختیاری' => ['chahar mahall and bakhtiari', 'chaharmahal and bakhtiari', 'chahar mahal va bakhtiari', 'chaharmahal va bakhtiari'],
                'کهگیلویه و بویراحمد' => ['kohgiluyeh and buyer ahmad', 'kohgiluyeh and bowyer ahmad', 'kohgiluyeh va boyerahmad', 'kohgiluyeh and boyerahmad'],
                'لرستان' => ['lorestan', 'loristan'],
                'ایلام' => ['ilam'],
                'سیستان و بلوچستان' => ['sistan and baluchestan', 'sistan & baluchestan', 'sistan va baluchestan', 'sistan and baluchistan'],
            ];
            $map = [];
            foreach ($raw as $fa => $ens) {
                foreach ($ens as $e) { $map[$e] = $fa; }
            }
        }
        $key = self::normRegion($en);
        if ($key === '' ) { return ''; }
        if (isset($map[$key])) { return $map[$key]; }
        /* نام از قبل فارسی است؟ */
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $en)) {
            $en = trim(str_ireplace(['استان ', 'استان'], '', $en));
            return $en;
        }
        /* تطبیق «contains» برای املا‌های نادر */
        foreach ($map as $needle => $fa) {
            if (strpos($key, $needle) !== false || strpos($needle, $key) !== false) { return $fa; }
        }
        return '';
    }

    private static function normRegion(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = str_ireplace(['ostan-e ', 'ostan ', 'province', 'province of'], ' ', $s);
        $s = str_replace(['-', '_', '&', '.', ','], ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /**
     * 🌆 نام شهرهای رایج انگلیسی → فارسی (برای سرویس پشتیبان ipwho.is)
     */
    private static function cityFa(string $city): string
    {
        if ($city === '' || preg_match('/[\x{0600}-\x{06FF}]/u', $city)) { return $city; }
        static $cmap = [
            'tehran' => 'تهران', 'karaj' => 'کرج', 'mashhad' => 'مشهد', 'isfahan' => 'اصفهان',
            'esfahan' => 'اصفهان', 'shiraz' => 'شیراز', 'tabriz' => 'تبریز', 'ahvaz' => 'اهواز',
            'qom' => 'قم', 'karman' => 'کرمان', 'kerman' => 'کرمان', 'yazd' => 'یزد',
            'rasht' => 'رشت', 'sari' => 'ساری', 'babol' => 'بابل', 'bushehr' => 'بوشهر',
            'zahedan' => 'زاهدان', 'urmia' => 'ارومیه', 'orumiyeh' => 'ارومیه', 'ardabil' => 'اردبیل',
            'sanandaj' => 'سنندج', 'kermanshah' => 'کرمانشاه', 'hamadan' => 'همدان', 'hamedan' => 'همدان',
            'khorramabad' => 'خرم‌آباد', 'ilam' => 'ایلام', 'birjand' => 'بیرجند', 'bojnord' => 'بجنورد',
            'semnan' => 'سمنان', 'shahroud' => 'شاهرود', 'gorgan' => 'گرگان', 'bandar abbas' => 'بندرعباس',
            'zanjan' => 'زنجان', 'qazvin' => 'قزوین', 'arak' => 'اراک', 'saveh' => 'ساوه',
            'kish' => 'کیش', 'qeshm' => 'قشم', 'mahshahr' => 'ماهشهر', 'dezful' => 'دزفول',
            'abadan' => 'آبادان', 'khorramshahr' => 'خرمشهر', 'maragheh' => 'مراغه', 'marand' => 'مرند',
            'maku' => 'ماکو', 'khoy' => 'خوی', 'mahabad' => 'مهاباد', 'miandoab' => 'میاندوآب',
            'gorgan city' => 'گرگان', 'shahre kord' => 'شهرکرد', 'yasuj' => 'یاسوج', 'qom city' => 'قم',
            'lamerd' => 'لامرد', 'kashan' => 'کاشان', 'najafabad' => 'نجف‌آباد', 'shahin shahr' => 'شاهین‌شهر',
            'parandak' => 'پرندک', 'pardis' => 'پردیس', 'varamin' => 'ورامین', 'rey' => 'ری',
            'kish island' => 'کیش', 'parsabad' => 'پارس‌آباد', 'astara' => 'آستارا', 'bandar anzali' => 'بندر انزلی',
            'langrud' => 'لنگرود', 'lahijan' => 'لاهیجان', 'tonekabon' => 'تنکابون', 'noshahr' => 'نوشهر',
            'chalous' => 'چالوس', 'chalus' => 'چالوس', 'amol' => 'آمل', 'ghaemshahr' => 'قائم‌شهر',
            'neka' => 'نکا', 'behshahr' => 'بهشهر', 'gorgan-' => 'گرگان', 'torbat heydariyeh' => 'تربت حیدریه',
            'neyshabur' => 'نیشابور', 'sabzevar' => 'سبزوار', 'quchan' => 'قوچان',
        ];
        return $cmap[mb_strtolower(trim($city))] ?? $city;
    }

    /**
     * 📥 بارگذاری دیتابیس پیشوندهای محلی
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
     * ✂️ پیشوند /24 برای کش (دقت بالاتر از /16 — رنج‌های مجاور شهرهای
     * مختلفی دارند)
     */
    private static function prefixOf(string $ip): string
    {
        $p = explode('.', $ip);
        return $p[0] . '.' . $p[1] . '.' . $p[2] . '.0';
    }

    /**
     * 💾 لایه ۱ — کش دیتابیس
     */
    private static function fromCache(string $prefix): ?array
    {
        try {
            $row = Database::getInstance()->fetch(
                'SELECT city, province, country FROM geoip_cache WHERE ip_prefix = ? AND fetched_at > DATE_SUB(?, INTERVAL ' . self::CACHE_TTL_DAYS . ' DAY) LIMIT 1',
                [$prefix, date('Y-m-d H:i:s')]
            );
            if (!$row) { return null; }
            return [
                'country'    => (string)($row['country'] ?: 'IR'),
                'country_fa' => ((string)$row['country'] === 'IR' || (string)$row['country'] === '') ? 'ایران' : self::countryFaName((string)$row['country']),
                'city'       => (string)$row['city'],
                'province'   => (string)$row['province'],
            ];
        } catch (Throwable $e) {
            return null; /* جدول کش هنوز ساخته نشده */
        }
    }

    private static function countryFaName(string $cc): string
    {
        $map = [
            'IR' => 'ایران', 'TR' => 'ترکیه', 'AE' => 'امارات', 'DE' => 'آلمان', 'US' => 'آمریکا',
            'NL' => 'هلند', 'GB' => 'انگلیس', 'CA' => 'کانادا', 'AF' => 'افغانستان', 'IQ' => 'عراق',
            'RU' => 'روسیه', 'FR' => 'فرانسه', 'SE' => 'سوئد', 'AU' => 'استرالیا', 'IN' => 'هند',
            'CN' => 'چین', 'AZ' => 'آذربایجان', 'PK' => 'پاکستان', 'JP' => 'ژاپن', 'QA' => 'قطر',
        ];
        return $map[strtoupper($cc)] ?? strtoupper($cc);
    }

    /**
     * 🌐 لایه ۲ — سرویس خارجی (ip-api با فارسی + ipwho پشتیبان)
     * نتیجه در کش ذخیره می‌شود.
     */
    private static function fromExternal(string $ip, string $prefix): ?array
    {
        if (self::$externalDead) { return null; }
        /* 🚦 نرخ‌سنجی: حداکثر یک تماس خارجی در هر ۱.۵ ثانیه برای کل سایت
           (ip-api رایگان: ۴۵ درخواست در دقیقه — با کش /24 خیلی جا داریم) */
        $throttleFile = dirname(__DIR__) . '/cache/geoip-throttle';
        $now = microtime(true);
        if (is_file($throttleFile)) {
            $last = (float)@file_get_contents($throttleFile);
            if ($now - $last < 1.5) { return null; }
        }
        @file_put_contents($throttleFile, (string)$now);

        $result = self::fetchIpApi($ip) ?? self::fetchIpWho($ip);
        if ($result === null) {
            return null; /* شبکه قطع — رنج محلی جواب می‌دهد */
        }

        /* 💾 ذخیره در کش (شهر خالی هم کش می‌شود تا سرویس دوباره پرسیده نشود) */
        try {
            Database::getInstance()->query(
                'INSERT INTO geoip_cache (ip_prefix, city, province, country, fetched_at) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE city = VALUES(city), province = VALUES(province), country = VALUES(country), fetched_at = VALUES(fetched_at)',
                [$prefix, $result['city'], $result['province'], $result['country'], date('Y-m-d H:i:s')]
            );
        } catch (Throwable $e) { /* کش در دسترس نیست — نتیجه بدون کش استفاده می‌شود */ }
        return $result;
    }

    /** 🛰 ip-api.com — city با lang=fa فارسی برمی‌گردد */
    private static function fetchIpApi(string $ip): ?array
    {
        $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,country,countryCode,regionName,city&lang=fa';
        $json = self::httpGet($url, 3.5);
        if ($json === null) { return null; }
        $d = json_decode($json, true);
        if (!is_array($d) || ($d['status'] ?? '') !== 'success') { return null; }
        $province = self::regionFa((string)($d['regionName'] ?? ''));
        /* اگر شهر فارسی نبود (سرویس گاهی انگلیسی می‌دهد) از جدول شهرها */
        $city = self::cityFa((string)($d['city'] ?? ''));
        $cc = strtoupper((string)($d['countryCode'] ?? 'IR')) ?: 'IR';
        return [
            'country'    => $cc,
            'country_fa' => $cc === 'IR' ? 'ایران' : self::countryFaName($cc),
            'city'       => $city,
            'province'   => $province,
        ];
    }

    /** 🛰 ipwho.is — پشتیبان HTTPS */
    private static function fetchIpWho(string $ip): ?array
    {
        $url = 'https://ipwho.is/' . rawurlencode($ip) . '?fields=success,country,country_code,region,city';
        $json = self::httpGet($url, 3.5);
        if ($json === null) { return null; }
        $d = json_decode($json, true);
        if (!is_array($d) || empty($d['success'])) { return null; }
        $province = self::regionFa((string)($d['region'] ?? ''));
        $city = self::cityFa((string)($d['city'] ?? ''));
        $cc = strtoupper((string)($d['country_code'] ?? 'IR')) ?: 'IR';
        return [
            'country'    => $cc,
            'country_fa' => $cc === 'IR' ? 'ایران' : self::countryFaName($cc),
            'city'       => $city,
            'province'   => $province,
        ];
    }

    /** 📡 GET خام با curl یا file_get_contents */
    private static function httpGet(string $url, float $timeout): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => (int)ceil($timeout),
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USERAGENT      => 'SahandBrandMaker-GeoIP/2.31',
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($body) && $code === 200 && $body !== '') { return $body; }
            return null;
        }
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => false]]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) && $body !== '' ? $body : null;
    }

    /**
     * 🏠 لایه ۳ — رنج‌های محلی (پشتیبان آفلاین)
     */
    private static function fromLocal(string $ip): array
    {
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
                'country'    => 'IR',
                'country_fa' => 'ایران',
                'city'       => $best[2] ?? '',
                'province'   => $best[3] ?? '',
            ];
        }
        return ['country' => 'IR', 'country_fa' => 'ایران', 'city' => '', 'province' => ''];
    }

    /**
     * 🌍 تشخیص اطلاعات جغرافیایی IP
     *
     * @param string $ip آدرس IP
     * @return array ['country' => 'IR', 'country_fa' => 'ایران', 'city' => 'تبریز', 'province' => 'آذربایجان شرقی']
     */
    public static function lookup(string $ip): array
    {
        $default = ['country' => 'IR', 'country_fa' => 'ایران', 'city' => '', 'province' => ''];
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $default;
        }
        $prefix = self::prefixOf($ip);
        if (isset(self::$memo[$prefix])) {
            return self::$memo[$prefix];
        }
        $result = self::fromCache($prefix);
        if ($result === null) {
            $result = self::fromExternal($ip, $prefix);
        }
        if ($result === null) {
            $result = self::fromLocal($ip);
        }
        return self::$memo[$prefix] = $result;
    }

    /**
     * 🏙️ استخراج شهر از IP
     */
    public static function city(string $ip): string
    {
        return self::lookup($ip)['city'];
    }

    /**
     * 🗺️ استخراج استان از IP (v2.31)
     */
    public static function province(string $ip): string
    {
        return self::lookup($ip)['province'];
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

    /**
     * 🗺️ نقشه استان‌ها برای نمودار (فایل SVG تولیدشده)
     * @return array [نام استان => ['path' => '...', 'xy' => [x, y]]]
     */
    public static function iranMap(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            $file = dirname(__DIR__) . '/geoip/iran-map.php';
            if (file_exists($file)) {
                $loaded = include $file;
                if (is_array($loaded)) { $map = $loaded; }
            }
        }
        return $map;
    }
}
