<?php
/**
 * 🧬 تشخیص‌دهنده‌ی زیرسیستم کد خطا — موتور خطایاب (v2.33)
 * ==========================================================
 *
 * 🚨 ریشه‌ی مشکل قبلی:
 * تابع deviceCauses() برای **همه** کدهای یک دستگاه یک لیستِ ثابت برمی‌گرداند
 * (بدون توجه به کد و حتی بدون استفاده از پارامتر category). نتیجه: برای خطای
 * «عدم تخلیه» نوشته می‌شد «خرابی شیر برقی ورودی آب» و برای خطای «قفل درب»
 * نوشته می‌شد «گرفتگی فیلتر تخلیه» — یعنی علت‌هایی خارج از زنجیره‌ی خرابی.
 *
 * ✅ راه‌حل این کلاس:
 * هر کد خطا ابتدا به یک «زیرسیستم» نسبت داده می‌شود (مثل drain / inlet /
 * door_lock / motor / heating). تمام علت‌ها، راه‌حل‌ها، قطعه، مشخصات فنی و
 * محل قطعه **فقط از همان زیرسیستم** تأمین می‌شوند. چون همه‌ی اعضای یک
 * زیرسیستم در یک زنجیره‌ی علّی مشترک‌اند، انسجامِ علّی به‌صورت ساختاری
 * تضمین می‌شود — نه با حدس و کلمه‌کلیدی.
 *
 * منبع داده: engine/knowledge/errorcode-subsystems.json
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0 (v2.33)
 */
class ErrorCodeSubsystem
{
    /** @var array|null مدل بارگذاری‌شده (کش استاتیک در طول درخواست) */
    private static $model = null;

    /** @var array نگاشت دستگاه به زیرسیستم پیش‌فرض (وقتی هیچ تطبیقی پیدا نشد) */
    private const DEVICE_DEFAULT = [
        'washing_machine' => 'control_board',
        'refrigerator'    => 'fridge_board',
        'dishwasher'      => 'dw_board',
        'air_conditioner' => 'ac_board',
        'microwave'       => 'mw_board',
        'oven'            => 'oven_board',
        'dryer'           => 'dryer_heat',
        'water_heater'    => 'wh_heat',
        'package'         => 'pkg_pressure',
        'television'      => 'tv_main',
        'vacuum_cleaner'  => 'vc_suction',
        'stove'           => 'stove_ignition',
        'hood'            => 'hood_motor',
    ];

    /**
     * 📖 بارگذاری مدل علّی
     */
    public static function model(): array
    {
        if (self::$model === null) {
            $path = ENGINE_PATH . '/knowledge/errorcode-subsystems.json';
            $raw  = @file_get_contents($path);
            $data = $raw === false ? null : json_decode($raw, true);
            if (!is_array($data) || empty($data['devices'])) {
                self::$model = ['devices' => []];
            } else {
                self::$model = $data;
            }
        }
        return self::$model;
    }

    /**
     * 📦 زیرسیستم‌های یک دستگاه
     *
     * @return array [subsystem_key => data]
     */
    public static function deviceModel(string $deviceKey): array
    {
        $model = self::model();
        $subs  = $model['devices'][$deviceKey] ?? [];
        return is_array($subs) ? $subs : [];
    }

    /**
     * 🎯 تشخیص زیرسیستمِ یک کد خطا
     *
     * ترتیب تلاش:
     *   ۱) کلید صریح subsystem در داده‌ی پایگاه دانش (بالاترین قطعیت)
     *   ۲) تطبیق کلیدواژه روی عنوان (فارسی، سپس انگلیسی) — طولانی‌ترین کلیدواژه برنده
     *   ۳) تطبیق کلیدواژه روی قطعه (related_part)
     *   ۴) تطبیق کلیدواژه روی دسته‌بندی
     *   ۵) زیرسیستم پیش‌فرض دستگاه (با قطعیت پایین)
     *
     * @param string $deviceKey کلید دستگاه
     * @param array  $c         داده‌ی کد (code, title, part, category, subsystem, causes...)
     * @return array{key:?string,data:array,method:string,confidence:string}
     */
    public static function resolve(string $deviceKey, array $c): array
    {
        $subs = self::deviceModel($deviceKey);
        if (empty($subs)) {
            return ['key' => null, 'data' => [], 'method' => 'none', 'confidence' => 'none'];
        }

        /* ۱) کلید صریح */
        $explicit = trim((string)($c['subsystem'] ?? ''));
        if ($explicit !== '' && isset($subs[$explicit])) {
            return ['key' => $explicit, 'data' => $subs[$explicit], 'method' => 'explicit', 'confidence' => 'high'];
        }

        $title    = mb_strtolower(trim((string)($c['title'] ?? '')));
        $partTxt  = mb_strtolower(trim((string)($c['part'] ?? $c['related_part'] ?? '')));
        $catTxt   = mb_strtolower(trim((string)($c['category'] ?? '')));
        $codeTxt  = mb_strtoupper(trim((string)($c['code'] ?? '')));

        $best     = null;
        $bestScore = 0;
        $bestMethod = '';

        foreach ($subs as $key => $s) {
            $score   = 0;
            $method  = '';

            /* ۲) تطبیق روی عنوان — امتیاز بر اساس طول کلیدواژه (خاص‌تر = قوی‌تر) */
            $hit = self::matchKeywords($title, (array)($s['keywords_fa'] ?? []), false);
            if ($hit > 0) {
                $score  += 40 + $hit;
                $method  = 'title_fa';
            }
            $hitEn = self::matchKeywords($title, (array)($s['keywords_en'] ?? []), true);
            if ($hitEn > 0) {
                $score  += 30 + $hitEn;
                $method  = $method === '' ? 'title_en' : $method;
            }

            /* ۳) تطبیق روی نام قطعه */
            $hitPart = self::matchKeywords($partTxt, (array)($s['keywords_fa'] ?? []), false);
            if ($hitPart > 0) {
                $score  += 25 + $hitPart;
                $method  = $method === '' ? 'part' : $method;
            }

            /* ۴) تطبیق روی دسته‌بندی */
            $catScore = self::matchCategory($catTxt, (array)($s['category'] ?? []));
            if ($catScore > 0) {
                $score  += 10 + $catScore;
                $method  = $method === '' ? 'category' : $method;
            }

            if ($score > $bestScore) {
                $bestScore  = $score;
                $best       = $key;
                $bestMethod = $method;
            }
        }

        /* ۵) پیش‌فرض دستگاه */
        if ($best === null) {
            $def = self::DEVICE_DEFAULT[$deviceKey] ?? null;
            if ($def !== null && isset($subs[$def])) {
                return ['key' => $def, 'data' => $subs[$def], 'method' => 'device_default', 'confidence' => 'low'];
            }
            return ['key' => null, 'data' => [], 'method' => 'none', 'confidence' => 'none'];
        }

        $confidence = $bestScore >= 40 ? 'high' : ($bestScore >= 20 ? 'medium' : 'low');
        return ['key' => $best, 'data' => $subs[$best], 'method' => $bestMethod, 'confidence' => $confidence];
    }

    /**
     * 🔤 امتیاز تطبیق کلیدواژه‌ها روی یک متن
     *
     * @return int طولِ بلندترین کلیدواژه‌ی یافت‌شده (۰ = عدم تطبیق)
     */
    private static function matchKeywords(string $haystack, array $keywords, bool $ascii): int
    {
        if ($haystack === '' || empty($keywords)) {
            return 0;
        }
        $haystack = self::normalizeFa($haystack);
        $best = 0;
        foreach ($keywords as $kw) {
            $kw = self::normalizeFa(mb_strtolower(trim((string)$kw)));
            if ($kw === '') {
                continue;
            }
            if ($ascii) {
                /* برای کلیدواژه‌های انگلیسی، تطبیقِ مرز‌دار روی متن (عنوان ممکن است دوزبانه باشد) */
                $pattern = '/(?<![a-z0-9])' . preg_quote($kw, '/') . '(?![a-z0-9])/i';
                if (preg_match($pattern, $haystack)) {
                    $best = max($best, mb_strlen($kw));
                }
            } elseif (mb_strpos($haystack, $kw) !== false) {
                $best = max($best, mb_strlen($kw));
            }
        }
        return $best;
    }

    /**
     * 🧹 نرمال‌سازی متن فارسی برای تطبیقِ مقاوم (v2.33)
     *
     * 🚨 ریشه: «بک‌لایت» (با نیم‌فاصله) با کلیدواژه‌ی «بکلایت» (بدون آن) تطبیق
     * نمی‌خورد و کدهای بک‌لایت به زیرسیستمِ تغذیه نسبت داده می‌شدند.
     * این تابع نیم‌فاصله، پیوند، زیرخط، تشدید و حروف عربی را یکسان می‌کند.
     */
    private static function normalizeFa(string $text): string
    {
        /* حذف نیم‌فاصله، پیوند، زیرخط و نویسه‌های کنترلیِ نامرئی */
        $text = str_replace(
            ["\xE2\x80\x8C", "\xE2\x80\x8D", '-', '_', 'ـ', "\xE2\x80\x8B", '‌'],
            ['', '', '', '', '', '', ''],
            $text
        );
        /* یکسان‌سازی حروف عربی با فارسی */
        $text = str_replace(['ي', 'ك', 'ۀ', 'ة'], ['ی', 'ک', 'ه', 'ه'], $text);
        /* فشرده‌سازی فاصله‌ها */
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * 🏷 امتیاز تطبیق دسته‌بندی
     */
    private static function matchCategory(string $catTxt, array $categories): int
    {
        if ($catTxt === '' || empty($categories)) {
            return 0;
        }
        $best = 0;
        foreach ($categories as $cat) {
            $cat = mb_strtolower(trim((string)$cat));
            if ($cat === '') {
                continue;
            }
            if (mb_strpos($catTxt, $cat) !== false || mb_strpos($cat, $catTxt) !== false) {
                $best = max($best, mb_strlen($cat));
            }
        }
        return $best;
    }

    /* ==================================================
     * 📤 استخراج فیلدها از زیرسیستم
     * ================================================== */

    /**
     * ⚠️ علت‌های درون‌زنجیره (مرتب‌شده از شایع به نادر)
     */
    public static function causes(array $sub): array
    {
        return array_values(array_filter(array_map('trim', (array)($sub['causes'] ?? []))));
    }

    /**
     * ✅ راه‌حل‌های درون‌زنجیره با برچسب [کاربر] / [تکنسین] — از ساده به پیچیده
     */
    public static function solutions(array $sub): array
    {
        $out = [];
        foreach ((array)($sub['user_fixes'] ?? []) as $f) {
            $f = trim((string)$f);
            if ($f !== '') {
                $out[] = '[کاربر] ' . $f;
            }
        }
        foreach ((array)($sub['tech_fixes'] ?? []) as $f) {
            $f = trim((string)$f);
            if ($f !== '') {
                $out[] = '[تکنسین] ' . $f;
            }
        }
        return $out;
    }

    /**
     * 🔧 قطعه مربوطه — اولویت با قطعه‌ای که در عنوان/داده‌ی کد هم آمده باشد
     */
    public static function part(array $sub, string $title = ''): string
    {
        $parts = array_values(array_filter(array_map('trim', (array)($sub['parts'] ?? []))));
        if (empty($parts)) {
            return '';
        }
        if ($title !== '') {
            $t = mb_strtolower($title);
            foreach ($parts as $p) {
                /* مقایسه روی بخش فارسیِ «نام | English» */
                $fa = mb_strtolower(trim(explode('|', $p)[0]));
                if ($fa !== '' && mb_strpos($t, $fa) !== false) {
                    return $p;
                }
            }
        }
        return $parts[0];
    }

    /**
     * 📐 مشخصات فنی قطعه
     */
    public static function specs(array $sub): string
    {
        return trim((string)($sub['specs'] ?? ''));
    }

    /**
     * 📍 محل قرارگیری قطعه
     */
    public static function location(array $sub): string
    {
        return trim((string)($sub['location'] ?? ''));
    }

    /**
     * 🎚 شدت پیش‌فرض زیرسیستم
     */
    public static function severity(array $sub): string
    {
        $s = strtolower(trim((string)($sub['severity'] ?? '')));
        return in_array($s, ['low', 'medium', 'high', 'critical', 'informational'], true) ? $s : 'medium';
    }

    /**
     * 🏷 دسته‌بندی(های) زیرسیستم — با «،» ادغام می‌شود
     */
    public static function category(array $sub): string
    {
        $cats = array_values(array_filter(array_map('trim', (array)($sub['category'] ?? []))));
        return implode('، ', $cats);
    }

    /**
     * 🧪 توضیحِ سازوکار خرابی (برای تولید توضیحِ غیرقالبی)
     */
    public static function mechanism(array $sub): string
    {
        return trim((string)($sub['mechanism_fa'] ?? ''));
    }

    /**
     * 💥 پیامدِ خطا بر عملکرد
     */
    public static function impact(array $sub): string
    {
        return trim((string)($sub['impact_fa'] ?? ''));
    }

    /**
     * ♻️ آیا خطا خودبه‌خود رفع می‌شود؟
     */
    public static function selfHealing(array $sub): bool
    {
        return (bool)($sub['self_healing'] ?? false);
    }

    /**
     * 📋 فهرست زیرسیستم‌های یک دستگاه (برای رابط کاربر و دیباگ)
     *
     * @return array [key => label_fa]
     */
    public static function labels(string $deviceKey): array
    {
        $out = [];
        foreach (self::deviceModel($deviceKey) as $key => $s) {
            $out[$key] = (string)($s['label_fa'] ?? $key);
        }
        return $out;
    }
}
