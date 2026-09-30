<?php
/**
 * 🗺️ GeoIP چهارلایه — کش دیتابیس + ۳ سرویس خارجی + دیتابیس ملی
 * ================================================================
 * 🚨 v2.32 — ریشه نهایی «پراکندگی جغرافیایی غلط» (کاربر تبریز →
 * خراسان رضوی). سه ریشه مستقل پیدا و رفع شد:
 *
 *   🐛 ریشه A — پیشوند ناهمخوان: visits.ip_prefix به‌صورت /16
 *      (a.b.0.0) ذخیره می‌شد اما کش GeoIP کلید /24 دارد؛ در زمان
 *      نمایش، برای «آدرس پایه شبکه» از سرویس خارجی پرسیده می‌شد و
 *      جواب = محل «ثبت» ISP (نه محل کاربر). اکنون هر دو /24‌اند و
 *      پیشوندهای قدیمی /16 هرگز بازحلابی نمی‌شوند.
 *   🐛 ریشه B — حدس استاتیک غلط: رنج‌های سراسری TCI/موبایل
 *      (78.38.x، 2.176-2.191، 37.32.x، 217.219.x، ...) برچسب شهری
 *      داشتند (مثلاً مشهد) در حالی که استخر ملی است و در هر شهری
 *      مشتری دارد. اکنون دیتابیس محلی فقط «ایران بودن» را تأیید
 *      می‌کند و شهر/استان فقط از سرویس‌های خارجی معتبر می‌آید —
 *      «نبودن داده بهتر از داده غلط است».
 *   🐛 ریشه C — قفل‌شدن جواب حدسی: نرخ‌سنجی ۱.۵ ثانیه‌ای باعث
 *      می‌شد بازدید دوم به fallback محلی بیفتد و استان حدسی برای
 *      همیشه در جدول ثبت شود (بک‌فیل فقط خانه‌های خالی را پر می‌کرد).
 *      اکنون: ① نرخ‌سنجی ۰.۸ث ② نتیجه بدون شهر+استان کش نمی‌شود
 *      (بازدید بعدی دوباره می‌پرسد) ③ بک‌فیل هوشمند نتیجه api جای
 *      نتیجه local را می‌گیرد ④ ستون geo_src منبع را ثبت می‌کند.
 *
 * لایه‌ها:
 *   ① کش دیتابیس (geoip_cache) — پیشوند /24، TTL ۱۴ روز
 *   ② سرویس‌های خارجی: ip-api.com (lang=fa) → ipapi.co → ipwho.is
 *      (اولین نتیجه دارای شهر یا استان معتبر برنده است)
 *   ③ دیتابیس ملی (geoip/iran-ranges.php) — فقط کشور، بدون حدس شهر
 *
 * @package SahandBrandMaker
 * @version 3.0.0
 */
class GeoIP
{
    /** @var array|null کش دیتابیس رنج‌های ملی */
    private static $ranges = null;

    /** @var array حافظه درون‌درخواست (prefix => نتیجه) */
    private static $memo = [];

    /** @var bool آیا همه سرویس‌های خارجی در این اجرا شکست خوردند؟ (قطعی شبکه) */
    private static $externalDead = false;

    /** TTL کش جغرافیایی در روزها — 🚨 v2.32: ۴۵→۱۴ روز (جواب بد کمتر عمر می‌کند) */
    private const CACHE_TTL_DAYS = 14;

    /** فاصله حداقلی بین دو تماس خارجی (ثانیه) — 🚨 v2.32: ۱.۵→۰.۸ */
    private const THROTTLE_SEC = 0.8;

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
     * 🌆 نام شهرهای رایج انگلیسی → فارسی
     */
    private static function cityFa(string $city): string
    {
        if ($city === '' || preg_match('/[\x{0600}-\x{06FF}]/u', $city)) { return $city; }
        static $cmap = [
            'tehran' => 'تهران', 'karaj' => 'کرج', 'mashhad' => 'مشهد', 'isfahan' => 'اصفهان',
            'esfahan' => 'اصفهان', 'shiraz' => 'شیراز', 'tabriz' => 'تبریز', 'ahvaz' => 'اهواز',
            'qom' => 'قم', 'karman' => 'کرمان', 'kerman' => 'کرمان', 'yazd' => 'یزد',
            'rasht' => 'رشت', 'sari' => 'ساری', 'babol' => 'بابل', 'bushehr' => 'بوشهر',
            'zahedan' => 'زاهدان', 'urmia' => 'ارومیه', 'orumiyeh' => 'ارومیه', 'urdus' => 'ارومیه',
            'ardabil' => 'اردبیل', 'sanandaj' => 'سنندج', 'kermanshah' => 'کرمانشاه', 'hamadan' => 'همدان',
            'hamedan' => 'همدان', 'khorramabad' => 'خرم‌آباد', 'ilam' => 'ایلام', 'birjand' => 'بیرجند',
            'bojnord' => 'بجنورد', 'bojnourd' => 'بجنورد', 'semnan' => 'سمنان', 'shahroud' => 'شاهرود',
            'shahrud' => 'شاهرود', 'gorgan' => 'گرگان', 'bandar abbas' => 'بندرعباس',
            'zanjan' => 'زنجان', 'qazvin' => 'قزوین', 'arak' => 'اراک', 'saveh' => 'ساوه',
            'kish' => 'کیش', 'qeshm' => 'قشم', 'mahshahr' => 'ماهشهر', 'dezful' => 'دزفول',
            'abadan' => 'آبادان', 'khorramshahr' => 'خرمشهر', 'maragheh' => 'مراغه', 'marand' => 'مرند',
            'maku' => 'ماکو', 'khoy' => 'خوی', 'mahabad' => 'مهاباد', 'miandoab' => 'میاندوآب',
            'shahre kord' => 'شهرکرد', 'yasuj' => 'یاسوج',
            'lamerd' => 'لامرد', 'kashan' => 'کاشان', 'najafabad' => 'نجف‌آباد', 'shahin shahr' => 'شاهین‌شهر',
            'parandak' => 'پرندک', 'pardis' => 'پردیس', 'varamin' => 'ورامین', 'rey' => 'ری',
            'parsabad' => 'پارس‌آباد', 'astara' => 'آستارا', 'bandar anzali' => 'بندر انزلی',
            'langrud' => 'لنگرود', 'lahijan' => 'لاهیجان', 'tonekabon' => 'تنکابون', 'noshahr' => 'نوشهر',
            'chalous' => 'چالوس', 'chalus' => 'چالوس', 'amol' => 'آمل', 'ghaemshahr' => 'قائم‌شهر',
            'neka' => 'نکا', 'behshahr' => 'بهشهر',
            'torbat heydariyeh' => 'تربت حیدریه', 'neyshabur' => 'نیشابور', 'sabzevar' => 'سبزوار', 'quchan' => 'قوچان',
        ];
        return $cmap[mb_strtolower(trim($city))] ?? $city;
    }

    /**
     * 📥 بارگذاری دیتابیس رنج‌های ملی (فقط تشخیص کشور)
     * 🚨 v2.32 — ساختار [شروع, پایان] است؛ شهر/استان عمداً حذف شد
     * (استخرهای TCI/موبایل/ISPهای سراسری ملی‌اند و هر برچسب شهری
     * حدس غلط است — ریشه «تبریز → خراسان رضوی»).
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
     * ✂️ پیشوند کش — IPv4: /24 | IPv6: /64 (چهار هکتت اول)
     */
    public static function prefixOf(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);
            return $p[0] . '.' . $p[1] . '.' . $p[2] . '.0';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            /* نرمال‌سازی فشرده سپس چهار گروه اول */
            $expanded = self::expandV6($ip);
            if ($expanded !== '') {
                $g = explode(':', $expanded);
                return strtolower($g[0] . ':' . $g[1] . ':' . $g[2] . ':' . $g[3] . '::');
            }
        }
        return $ip;
    }

    /** بازکردن آدرس فشرده IPv6 به هشت گروه */
    private static function expandV6(string $ip): string
    {
        $ip = trim(strtolower($ip));
        if (strpos($ip, '::') === false) {
            return $ip;
        }
        [$head, $tail] = explode('::', $ip) + ['', ''];
        $h = $head === '' ? [] : explode(':', $head);
        $t = $tail === '' ? [] : explode(':', $tail);
        $missing = 8 - count($h) - count($t);
        if ($missing < 0) { return ''; }
        $groups = array_merge($h, array_fill(0, $missing, '0'), $t);
        return implode(':', array_map(static fn($g) => str_pad($g, 4, '0', STR_PAD_LEFT), $groups));
    }

    /**
     * 💾 لایه ۱ — کش دیتابیس (فقط نتایج معتبر کش می‌شوند)
     */
    private static function fromCache(string $prefix): ?array
    {
        try {
            $row = Database::getInstance()->fetch(
                'SELECT city, province, country, is_mobile FROM geoip_cache WHERE ip_prefix = ? AND fetched_at > DATE_SUB(?, INTERVAL ' . self::CACHE_TTL_DAYS . ' DAY) LIMIT 1',
                [$prefix, date('Y-m-d H:i:s')]
            );
            if (!$row) { return null; }
            return [
                'country'    => (string)($row['country'] ?: 'IR'),
                'country_fa' => ((string)$row['country'] === 'IR' || (string)$row['country'] === '') ? 'ایران' : self::countryFaName((string)$row['country']),
                'city'       => (string)$row['city'],
                'province'   => (string)$row['province'],
                'is_mobile'  => !empty($row['is_mobile']),
                'source'     => 'cache',
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
     * 🌐 لایه ۲ — سرویس‌های خارجی (۳ تایی، اولین جواب معتبر برنده)
     * 🚨 v2.32 — نتیجه‌ای که نه شهر دارد نه استان «رد» می‌شود تا سرویس
     * بعدی امتحان شود؛ نتیجه ردشده هرگز کش نمی‌شود (بازدید بعدی دوباره
     * می‌پرسد — ریشه قفل‌شدن جواب خالی/حدسی).
     */
    private static function fromExternal(string $ip, string $prefix): ?array
    {
        if (self::$externalDead) { return null; }
        /* 🚦 نرخ‌سنجی سراسری — ۰.۸ ثانیه */
        $throttleFile = dirname(__DIR__) . '/cache/geoip-throttle';
        $now = microtime(true);
        if (is_file($throttleFile)) {
            $last = (float)@file_get_contents($throttleFile);
            if ($now - $last < self::THROTTLE_SEC) { return null; }
        }
        @file_put_contents($throttleFile, (string)$now);

        $result = self::fetchIpApi($ip) ?: self::fetchIpApiCo($ip) ?: self::fetchIpWho($ip);
        /* 📱 v2.44 — نتیجه موبایلی معتبر است حتی بدون شهر/استان (کش هم می‌شود) */
        $isMobileResult = is_array($result) && !empty($result['is_mobile']);
        if ($result === null || (!$isMobileResult && ($result['city'] === '' && $result['province'] === ''))) {
            /* شبکه قطع یا هیچ سرویسی شهر/استان نداد — بدون کش (تلاش مجدد بعدی) */
            return null;
        }

        /* 💾 فقط نتیجه معتبر کش می‌شود */
        try {
            Database::getInstance()->query(
                'INSERT INTO geoip_cache (ip_prefix, city, province, country, is_mobile, fetched_at) VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE city = VALUES(city), province = VALUES(province), country = VALUES(country), is_mobile = VALUES(is_mobile), fetched_at = VALUES(fetched_at)',
                [$prefix, $result['city'], $result['province'], $result['country'], $isMobileResult ? 1 : 0, date('Y-m-d H:i:s')]
            );
        } catch (Throwable $e) { /* کش در دسترس نیست — نتیجه بدون کش استفاده می‌شود */ }
        $result['source'] = 'api';
        return $result;
    }

    /** 🛰 ip-api.com — city با lang=fa فارسی برمی‌گرداند (HTTP رایگان)
     * 🆕 v2.44 (S07) — پرچم mobile هم خوانده می‌شود: IPهای اپراتور موبایل
     * در دیتابیس‌های جهانی به «محل ثبت اپراتور» نگاشت می‌شوند نه محل واقعی
     * کاربر → هر برچسب شهری حدس غلط است (ریشه «تبریز → خراسان رضوی»).
     * برای بازدید موبایلی، شهر/استان خالی + is_mobile=1 برمی‌گردد. */
    private static function fetchIpApi(string $ip): ?array
    {
        $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,country,countryCode,regionName,city,mobile&lang=fa';
        $json = self::httpGet($url, 3.5);
        if ($json === null) { return null; }
        $d = json_decode($json, true);
        if (!is_array($d) || ($d['status'] ?? '') !== 'success') { return null; }
        $cc = strtoupper((string)($d['countryCode'] ?? 'IR')) ?: 'IR';
        /* 📱 اپراتور موبایل ایرانی؟ — شهر/استان نامعتبر است، فقط کشور */
        if (!empty($d['mobile']) && $cc === 'IR') {
            return [
                'country'    => 'IR',
                'country_fa' => 'ایران',
                'city'       => '',
                'province'   => '',
                'is_mobile'  => true,
            ];
        }
        $province = self::regionFa((string)($d['regionName'] ?? ''));
        $city = self::cityFa((string)($d['city'] ?? ''));
        return [
            'country'    => $cc,
            'country_fa' => $cc === 'IR' ? 'ایران' : self::countryFaName($cc),
            'city'       => $city,
            'province'   => $province,
        ];
    }

    /** 🛰 ipapi.co — 🆕 v2.32 سرویس دوم (HTTPS — جایگزین مطمئن ip-api در صورت فیلترینگ) */
    private static function fetchIpApiCo(string $ip): ?array
    {
        $url = 'https://ipapi.co/' . rawurlencode($ip) . '/json/';
        $json = self::httpGet($url, 3.5);
        if ($json === null) { return null; }
        $d = json_decode($json, true);
        if (!is_array($d) || (isset($d['error']) && $d['error']) || empty($d['country_code'])) { return null; }
        $province = self::regionFa((string)($d['region'] ?? ''));
        $city = self::cityFa((string)($d['city'] ?? ''));
        $cc = strtoupper((string)$d['country_code']) ?: 'IR';
        return [
            'country'    => $cc,
            'country_fa' => $cc === 'IR' ? 'ایران' : self::countryFaName($cc),
            'city'       => $city,
            'province'   => $province,
        ];
    }

    /** 🛰 ipwho.is — سرویس سوم (HTTPS) */
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
                CURLOPT_USERAGENT      => 'SahandBrandMaker-GeoIP/2.32',
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
     * 🏠 لایه ۳ — دیتابیس ملی (فقط کشور؛ بدون حدس شهر/استان)
     * 🚨 v2.32 — برچسب‌های شهری حذف شدند: استخرهای TCI/موبایل/سراسری
     * ملی‌اند و برچسب‌زدن شهر = حدس غلط (ریشه شکایت کاربر).
     * 🚨 v2.43 (S07) — IP خارج از رنج‌های ملی دیگر «ایران» برچسب نمی‌خورد:
     * قبلاً fallback این لایه برای هر IP ناشناخته ایران برمی‌گرداند و در
     * قطعی سرویس‌های خارجی، بازدیدکننده خارجی هم «ایران» ثبت می‌شد (نقشه
     * جهانی را غلط می‌کرد). اکنون: داخل رنج = IR، خارج = '' (نامشخص).
     */
    private static function fromLocal(string $ip): array
    {
        $ipLong = ip2long($ip);
        if ($ipLong !== false) {
            foreach (self::load() as $range) {
                $start = is_array($range) ? ($range[0] ?? null) : null;
                $end = is_array($range) ? ($range[1] ?? null) : null;
                if ($start !== null && $end !== null && $ipLong >= $start && $ipLong <= $end) {
                    return ['country' => 'IR', 'country_fa' => 'ایران', 'city' => '', 'province' => '', 'source' => 'local'];
                }
            }
        }
        /* 🌍 خارج از رنج‌های ملی — کشور نامشخص (نه ایران!) */
        return ['country' => '', 'country_fa' => '', 'city' => '', 'province' => '', 'source' => 'local'];
    }

    /**
     * 🌍 تشخیص اطلاعات جغرافیایی IP
     *
     * @param string $ip آدرس IP (IPv4 یا IPv6)
     * @return array ['country' => 'IR', 'country_fa' => 'ایران', 'city' => 'تبریز', 'province' => 'آذربایجان شرقی', 'source' => 'api|cache|local']
     */
    public static function lookup(string $ip): array
    {
        $default = ['country' => 'IR', 'country_fa' => 'ایران', 'city' => '', 'province' => '', 'source' => 'local'];
        $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        if (!$isV4 && !$isV6) {
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
        /* 📱 v2.44 — کلید is_mobile همیشه حاضر (سرویس‌های قدیمی نمی‌دهند) */
        if (!array_key_exists('is_mobile', $result)) {
            $result['is_mobile'] = false;
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
     * 🗺️ استخراج استان از IP
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
     * 🧪 پیشوند قابل بازحلابی است؟ (فرمت /24 یا IPv6 — نه /16 قدیمی)
     * 🚨 v2.32 — پیشوندهای قدیمی /16 (a.b.0.0) «آدرس پایه شبکه» بودند
     * و سرویس خارجی برایشان محل ثبت ISP را برمی‌گرداند → هرگز بازحلابی
     * نشوند (ریشه A از «تبریز → خراسان رضوی»).
     */
    public static function isResolvablePrefix(string $prefix): bool
    {
        $prefix = trim($prefix);
        if ($prefix === '') { return false; }
        if (filter_var($prefix, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $prefix);
            /* /24 صحیح: اکتت سوم می‌تواند هرچیزی باشد ولی اکتت چهارم ۰؛
               قدیمی /16: اکتت سوم هم ۰ است (a.b.0.0) — به‌جز حالت واقعی
               a.b.0.0/24 که نادر است و از دست می‌رود (قابل قبول). */
            return (int)$p[2] !== 0;
        }
        /* IPv6: شامل :: است */
        return strpos($prefix, ':') !== false;
    }

    /**
     * 📊 آمار پراکندگی شهرها (برای نمودار نقشه‌ای)
     * @return array ['تهران' => 152, 'مشهد' => 45, ...]
     */
    public static function citiesDistribution(array $ipPrefixes): array
    {
        $dist = [];
        foreach ($ipPrefixes as $prefix) {
            if (!self::isResolvablePrefix((string)$prefix)) { continue; }
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

    /**
     * 🌍 v2.43 (S07) — نقشه جهانی نقطه‌ای (dot-grid)
     * @return array ['cells' => [[col,row],...], 'boxes' => ['IR' => [lon1,lon2,lat1,lat2], ...]]
     */
    public static function worldMap(): array
    {
        static $w = null;
        if ($w === null) {
            $w = ['cells' => [], 'boxes' => []];
            $file = dirname(__DIR__) . '/geoip/world-map.php';
            if (file_exists($file)) {
                $loaded = include $file;
                if (is_array($loaded)) { $w = $loaded; }
            }
        }
        return $w;
    }

    /**
     * 🏷️ v2.43 (S07) — نام فارسی کشور از کد دو حرفی
     */
    public static function countryNameFa(string $cc): string
    {
        return self::countryFaName(strtoupper(trim($cc)));
    }

    /**
     * 🔄 v2.32 — بازحسابی جغرافیایی دسته‌ای (دکمه پنل آمار)
     * ================================================
     * ① کش GeoIP کامل پاک می‌شود
     * ② پیشوندهای /24 معتبرِ بازدیدهای اخیر دوباره از سرویس خارجی
     *    حلابی و روی همه ردیف‌های همان پیشوند اعمال می‌شوند
     * ③ ردیف‌های جغرافیای نامعتبر (پیشوند /16 قدیمی یا منبع local
     *    بدون امکان بازحسابی) پاک می‌شوند تا آلوده نباشند
     *
     * @param int $limit حداکثر تعداد پیشوند بازحسابی‌شده (نرخ‌سنجی سرویس)
     * @return array آمار عملیات
     */
    public static function recompute(int $limit = 120): array
    {
        $db = Database::getInstance();
        $stats = ['cache_cleared' => 0, 'prefixes_tried' => 0, 'prefixes_resolved' => 0, 'rows_updated' => 0, 'rows_purged' => 0];

        /* ① پاک‌کردن کامل کش — DELETE (نه TRUNCATE) چون MySQL 5.7 در
           prepared واقعی از TRUNCATE پشتیبانی نمی‌کند */
        try {
            $db->query('DELETE FROM geoip_cache');
            $stats['cache_cleared'] = 1;
        } catch (Throwable $e) { /* بی‌صدا */ }
        @unlink(dirname(__DIR__) . '/cache/geoip-throttle');

        /* ② پیشوندهای /24 معتبر — جدیدترین بازدیدها اول */
        $rows = [];
        try {
            $rows = $db->fetchAll(
                "SELECT ip_prefix, MAX(visit_date) AS last_day FROM visits
                 WHERE ip_prefix IS NOT NULL AND ip_prefix != ''
                 GROUP BY ip_prefix ORDER BY last_day DESC LIMIT " . max(10, min(400, $limit))
            );
        } catch (Throwable $e) { $rows = []; }

        foreach ((array)$rows as $r) {
            $prefix = (string)($r['ip_prefix'] ?? '');
            if (!self::isResolvablePrefix($prefix)) { continue; }
            /* خواندن مجدد حافظه — کش TRUNCATE شده اما memo درون‌درخواست ماند */
            self::$memo = [];
            $geo = self::lookup($prefix);
            $stats['prefixes_tried']++;
            if ($geo['city'] !== '' || $geo['province'] !== '') {
                try {
                    $stats['rows_updated'] += $db->query(
                        'UPDATE visits SET city = ?, province = ?, country = ?, geo_src = ? WHERE ip_prefix = ?',
                        [$geo['city'] ?: null, $geo['province'] ?: null, $geo['country'], $geo['source'], $prefix]
                    );
                    $stats['prefixes_resolved']++;
                } catch (Throwable $uE) { /* ستون قدیمی */ }
            }
        }

        /* ③ پاک‌سازی جغرافیای نامعتبر: پیشوند /16 قدیمی (a.b.0.0 = آدرس پایه شبکه) */
        try {
            $stats['rows_purged'] += $db->query(
                "UPDATE visits SET city = NULL, province = NULL, geo_src = NULL
                 WHERE ip_prefix IS NOT NULL AND ip_prefix != ''
                   AND ip_prefix NOT LIKE '%:%'
                   AND ip_prefix REGEXP '^[0-9]+\\.[0-9]+\\.0\\.0$'"
            );
        } catch (Throwable $pE) { /* ستون قدیمی */ }

        /* ③‌ب — جغرافیای بدون منبع معتبر روی پیشوند غیرقابل‌حلابی = حدس قدیمی */
        try {
            $stats['rows_purged'] += $db->query(
                "UPDATE visits SET city = NULL, province = NULL, geo_src = NULL
                 WHERE (geo_src IS NULL OR geo_src = 'local')
                   AND (city IS NOT NULL OR province IS NOT NULL)
                   AND (
                        ip_prefix IS NULL OR ip_prefix = ''
                     OR (ip_prefix NOT LIKE '%:%' AND ip_prefix REGEXP '^[0-9]+\\.[0-9]+\\.0\\.0$')
                   )"
            );
        } catch (Throwable $pE2) { /* ستون قدیمی */ }

        return $stats;
    }
}
