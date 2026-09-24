<?php
/**
 * ✍️ PersianGlyphs — شکل‌دهی و چینش بصری متن فارسی برای رندر تصویری
 * =================================================================
 * تابع imagettftext در GD اتصال حروف فارسی و ترتیب RTL را نمی‌فهمد؛
 * این کلاس در خالص PHP:
 *   ۱) هر حرف را به فرم presentation مناسب (مجزا/اولیه/میانی/نهایی) تبدیل می‌کند
 *   ۲) نیم‌فاصله (ZWNJ) را به‌عنوان جداکننده اتصال اعمال می‌کند
 *   ۳) ترتیب بصری را می‌سازد: توالی‌های فارسی معکوس، توالی‌های لاتین/عدد دست‌نخورده
 *
 * خروجی: رشته‌ای آماده برای imagettftext (LTR فیزیکی که درست دیده می‌شود)
 *
 * @package SahandBrandMaker\Engine
 * @version 2.0
 */
class PersianGlyphs
{
    /**
     * جدول نگاشت: حرف پایه → [مجزا, نهایی, اولیه, میانی]
     * اندیس‌های خالی (null) یعنی آن فرم ندارد (حروف غیراتصال‌پذیر از چپ)
     */
    private const TABLE = [
        'ا' => ['FE8D', 'FE8E', null, null],
        'آ' => ['FE81', 'FE82', null, null],
        'أ' => ['FE83', 'FE84', null, null],
        'إ' => ['FE87', 'FE88', null, null],
        'ء' => ['FE80', null, null, null],
        'ب' => ['FE8F', 'FE90', 'FE91', 'FE92'],
        'پ' => ['FB56', 'FB57', 'FB58', 'FB59'],
        'ت' => ['FE95', 'FE96', 'FE97', 'FE98'],
        'ث' => ['FE99', 'FE9A', 'FE9B', 'FE9C'],
        'ج' => ['FE9D', 'FE9E', 'FE9F', 'FEA0'],
        'چ' => ['FB7A', 'FB7B', 'FB7C', 'FB7D'],
        'ح' => ['FEA1', 'FEA2', 'FEA3', 'FEA4'],
        'خ' => ['FEA5', 'FEA6', 'FEA7', 'FEA8'],
        'د' => ['FEA9', 'FEAA', null, null],
        'ذ' => ['FEAB', 'FEAC', null, null],
        'ر' => ['FEAD', 'FEAE', null, null],
        'ز' => ['FEAF', 'FEB0', null, null],
        'ژ' => ['FB8A', 'FB8B', null, null],
        'س' => ['FEB1', 'FEB2', 'FEB3', 'FEB4'],
        'ش' => ['FEB5', 'FEB6', 'FEB7', 'FEB8'],
        'ص' => ['FEB9', 'FEBA', 'FEBB', 'FEBC'],
        'ض' => ['FEBD', 'FEBE', 'FEBF', 'FEC0'],
        'ط' => ['FEC1', 'FEC2', 'FEC3', 'FEC4'],
        'ظ' => ['FEC5', 'FEC6', 'FEC7', 'FEC8'],
        'ع' => ['FEC9', 'FECA', 'FECB', 'FECC'],
        'غ' => ['FECD', 'FECE', 'FECF', 'FED0'],
        'ف' => ['FED1', 'FED2', 'FED3', 'FED4'],
        'ق' => ['FED5', 'FED6', 'FED7', 'FED8'],
        'ک' => ['FB8E', 'FB8F', 'FB90', 'FB91'],
        'ك' => ['FED9', 'FEDA', 'FEDB', 'FEDC'],
        'گ' => ['FB92', 'FB93', 'FB94', 'FB95'],
        'ل' => ['FEDD', 'FEDE', 'FEDF', 'FEE0'],
        'م' => ['FEE1', 'FEE2', 'FEE3', 'FEE4'],
        'ن' => ['FEE5', 'FEE6', 'FEE7', 'FEE8'],
        'و' => ['FEED', 'FEEE', null, null],
        'ؤ' => ['FE85', 'FE86', null, null],
        'ئ' => ['FE89', 'FE8A', 'FE8B', 'FE8C'],
        'ه' => ['FEE9', 'FEEA', 'FEEB', 'FEEC'],
        'ۀ' => ['FEE9', 'FEEA', 'FEEB', 'FEEC'],
        'ی' => ['FBFC', 'FBFD', 'FBFE', 'FBFF'],
        'ي' => ['FBFE', 'FBFF', 'FBFE', 'FBFF'],
        'ى' => ['FEFB', 'FEFC', null, null],
        'ة' => ['FE93', 'FE94', null, null],
    ];

    /** 🚫 نویسه‌های جداکننده اتصال (خودشان حذف می‌شوند یا اتصال را می‌شکنند) */
    private const JOIN_BREAKERS = ["\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{0640}"];

    /**
     * ✍️ شکل‌دهی + ترتیب بصری — آماده برای imagettftext
     *
     * 🔢 v2.14: اعداد لاتین (0-9) پیش از شکل‌دهی به ارقام فارسی (۰-۹)
     * تبدیل می‌شوند تا در تصاویر (OG و ...) همه اعداد فارسی دیده شوند.
     */
    public static function shapeForImage(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return $text;
        }
        $text = self::persianDigits($text);

        /* ---------- مرحله ۱: شکل‌دهی حروف (منطقی) ---------- */
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($chars);
        $shaped = [];
        for ($i = 0; $i < $n; $i++) {
            $ch = $chars[$i];

            if (in_array($ch, self::JOIN_BREAKERS, true)) {
                /* نیم‌فاصله: در خروجی حذف می‌شود ولی اتصال دو طرف را می‌شکند */
                $shaped[] = ['glyph' => "\u{200C}", 'rtl' => true, 'joinBreak' => true];
                continue;
            }
            if (!isset(self::TABLE[$ch])) {
                /* لاتین، عدد، فاصله، نشانه — دست‌نخورده */
                $shaped[] = ['glyph' => $ch, 'rtl' => self::isRtlNeutral($ch) ? null : false, 'joinBreak' => false];
                continue;
            }

            $forms = self::TABLE[$ch];
            $prevJoinable = self::prevJoins($shaped, $chars, $i);
            $nextJoinable = self::nextJoins($chars, $i + 1);

            $form = 0; // مجزا
            if ($prevJoinable && $nextJoinable && $forms[3] !== null) {
                $form = 3; // میانی
            } elseif ($prevJoinable && $forms[1] !== null) {
                /* 🐛 v2.9: شرط «!$nextJoinable» حذف شد — حرف یک‌جهته (ا/ر/د/و...)
                   بین دو طرفِ قابلِ اتصال («ساید»: س-ا-ی) باید فرم «نهایی» بگیرد؛
                   قبلاً به شرط نمی‌رسید و «مجزا» می‌شد → اتصال بصری می‌شکست */
                $form = 1; // نهایی
            } elseif (!$prevJoinable && $nextJoinable && $forms[2] !== null) {
                $form = 2; // اولیه
            }
            $shaped[] = ['glyph' => self::cp((int)hexdec($forms[$form] ?? $forms[0])), 'rtl' => true, 'joinBreak' => false];
        }

        /* ---------- مرحله ۲: ترتیب بصری (bidi ساده‌شده برای پاراگراف RTL) ---------- */
        return self::visualOrder($shaped);
    }

    /** ↔ آیا عنصر قبلی اجازه اتصال می‌دهد؟
     *
     * 🐛 v2.9 — ریشه «حروف یک کلمه به هم چسبیده نیستند»:
     * حرف فعلی می‌تواند به حرف «قبل» بچسبد فقط اگر حرف قبل «دوجهته» باشد و
     * به سمت چپ خودش امتداد پیدا کند = باید فرم «اولیه» داشته باشد (TABLE[2]).
     * قبلاً به‌اشتباه joinsFromLeft (دارا بودن فرم «نهایی» = پذیرش اتصال از راست)
     * صدا زده می‌شد؛ برای حروف یک‌جهته (ا د ذ ر ز ژ و ء ة) جواب غلط می‌داد:
     * «ساید» → ی فرم نهایی می‌گرفت و ا فرم مجزا → اتصال بصری می‌شکست. */
    private static function prevJoins(array $shaped, array $chars, int $i): bool
    {
        for ($j = count($shaped) - 1; $j >= 0; $j--) {
            $el = $shaped[$j];
            if ($el['joinBreak']) { return false; } // نیم‌فاصله اتصال را می‌شکند
            if ($el['rtl'] === false) { return false; } // لاتین/عدد
            if ($el['rtl'] === null) {
                /* 🐛 v2.8: فقط اعراب/combined marks اتصال را نمی‌شکنند —
                   فاصله و نشانه‌ها (پرانتز، ویرگول و...) می‌شکنند!
                   (قبلاً همه خنثی‌ها رد می‌شدند → حروف دو کلمه مجزا به‌هم می‌چسبیدند:
                   «سلام علی» → ع به‌اشتباه فرم میانی می‌گرفت و dangling connector می‌شد) */
                if (preg_match('/^[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]$/u', $el['glyph'])) {
                    continue;
                }
                return false;
            }
            /* حرف قبل باید «دوجهته» باشد و به سمت چپ (به سمت ما) امتداد یابد */
            return self::joinsFromRight($chars[$i - (count($shaped) - $j)] ?? '');
        }
        return false;
    }

    /** ↔ آیا حرف در موقعیت $i به حرف بعدی می‌چسبد؟
     *
     * 🐛 v2.9 — قرینه رفع باگ prevJoins: حرف بعدی باید «از راست» اتصال را
     * «بپذیرد» = فرم «نهایی» داشته باشد (TABLE[1]). قبلاً به‌اشتباه joinsFromRight
     * (دوجهته بودن = داشتن فرم اولیه) صدا زده می‌شد؛ نتیجه: حرف قبل از «ا/د/ر/و»
     * هرگز به آن نمی‌چسبید («ساید» → سِ مجزا به‌جای سـِـ!). */
    private static function nextJoins(array $chars, int $i): bool
    {
        $n = count($chars);
        while ($i < $n) {
            $ch = $chars[$i];
            if (in_array($ch, self::JOIN_BREAKERS, true)) { return false; }
            /* اعراب/combined marks اتصال را نمی‌شکنند (v2.8) */
            if (preg_match('/^[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]$/u', $ch)) {
                $i++;
                continue;
            }
            if (isset(self::TABLE[$ch])) {
                return self::joinsFromLeft($ch);
            }
            return false; // فاصله، لاتین/عدد و سایر نشانه‌ها
        }
        return false;
    }

    /** آیا این حرف به حرف «بعد از خودش» (سمت چپ بصری) متصل می‌شود؟ (اتصال از راست) */
    private static function joinsFromRight(string $ch): bool
    {
        return isset(self::TABLE[$ch]) && self::TABLE[$ch][2] !== null;
    }

    /** آیا این حرف به حرف «قبل از خودش» (سمت راست بصری) متصل می‌شود؟ (اتصال از چپ) */
    private static function joinsFromLeft(string $ch): bool
    {
        return isset(self::TABLE[$ch]) && self::TABLE[$ch][1] !== null && $ch !== 'ء';
    }

    /** 🧩 خنثی (فاصله/نشانه‌ها) — rtl=null */
    private static function isRtlNeutral(string $ch): bool
    {
        return $ch === ' ' || preg_match('/[\p{P}\p{S}]/u', $ch);
    }

    /** 🔁 آینه جف پرانتزها — در توالی RTL باز/بسته جابه‌جا می‌شوند تا درست دیده شوند */
    private const MIRROR = [
        '(' => ')', ')' => '(', '[' => ']', ']' => '[',
        '{' => '}', '}' => '{', '<' => '>', '>' => '<',
    ];

    /**
     * ↔ ترتیب بصری (v2.8 — بازنویسی کامل):
     *
     * الگوریتم قبلی فقط «حروف داخل هر توالی RTL» را معکوس می‌کرد اما «ترتیب خود
     * توالی‌ها» را نگه می‌داشت — برای متن کاملاً فارسی درست بود ولی به‌محض ورود
     * عدد یا حرف انگلیسی، چیدمان به‌هم می‌ریخت («کد 4E خطا» به‌صورت «کد…خطا 4E»).
     *
     * الگوریتم استاندارد ساده‌شده برای پاراگراف RTL:
     *   ۱) متن به توالی‌های جهت‌دار شکسته می‌شود؛ خنثی (فاصله/نشانه) فقط وقتی
     *      «همسایه چپ و راستش» هر دو LTR باشند به توالی LTR می‌پیوندد، وگرنه
     *      جزو جهت پاراگراف (RTL) است.
     *   ۲) «ترتیب توالی‌ها» معکوس می‌شود (کلمه اول منطقی = راست‌ترین توالی بصری)
     *   ۳) داخل توالی RTL حروف معکوس + آینه پرانتزها؛ داخل توالی LTR دست‌نخورده
     */
    private static function visualOrder(array $shaped): string
    {
        /* ---------- ۱) ساخت توالی‌ها ---------- */
        $runs = []; // هر عنصر: ['rtl' => bool, 'els' => [...]]
        $i = 0;
        $n = count($shaped);
        while ($i < $n) {
            $el = $shaped[$i];
            if ($el['rtl'] === false) {
                /* توالی LTR: حروف/اعداد لاتین متوالی + خنثی‌هایی که دو طرفشان LTR است */
                $els = [];
                while ($i < $n) {
                    $cur = $shaped[$i];
                    if ($cur['rtl'] === true) { break; }
                    if ($cur['rtl'] === null) {
                        /* خنثی فقط با همسایه چپِ LTR و همسایه راستِ LTR به این توالی می‌پیوندد */
                        $nextStrong = null;
                        for ($k = $i + 1; $k < $n; $k++) {
                            if ($shaped[$k]['rtl'] !== null) { $nextStrong = $shaped[$k]['rtl']; break; }
                        }
                        if ($nextStrong !== false) { break; } // ادامه LTR نیست → خنثی به توالی RTL بعدی می‌پیوندد
                    }
                    $els[] = $cur;
                    $i++;
                }
                if ($els) {
                    $runs[] = ['rtl' => false, 'els' => $els];
                }
                continue;
            }
            /* توالی RTL: حروف فارسی + خنثی‌هایی که پیش/پس زمینه RTL دارند */
            $els = [];
            while ($i < $n) {
                $cur = $shaped[$i];
                if ($cur['rtl'] === false) { break; }
                $els[] = $cur;
                $i++;
            }
            if ($els) {
                $runs[] = ['rtl' => true, 'els' => $els];
            }
        }

        /* ---------- ۲+۳) خروجی: ترتیب توالی‌ها معکوس + داخل RTL معکوس ---------- */
        $out = [];
        for ($r = count($runs) - 1; $r >= 0; $r--) {
            $run = $runs[$r];
            if ($run['rtl']) {
                for ($j = count($run['els']) - 1; $j >= 0; $j--) {
                    $el = $run['els'][$j];
                    if ($el['joinBreak']) { continue; } // نیم‌فاصله در خروجی حذف
                    $g = $el['glyph'];
                    $out[] = self::MIRROR[$g] ?? $g; // آینه پرانتزها
                }
            } else {
                foreach ($run['els'] as $el) {
                    $out[] = $el['glyph'];
                }
            }
        }
        return implode('', $out);
    }

    /** 🔢 کدپوینت → نویسه UTF-8 */
    private static function cp(int $code): string
    {
        return mb_chr($code, 'UTF-8') ?: '';
    }

    /**
     * 🔢 تبدیل ارقام لاتین/عربی به ارقام فارسی (v2.14)
     * «کد 4E خطای 12» ← «کد ۴E خطای ۱۲» — برای متن تصاویر
     */
    public static function persianDigits(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        /* ارقام ASCII */
        $text = strtr($text, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
        /* ارقام عربی (U+0660–U+0669) */
        $text = strtr($text, [
            '٠' => '۰', '١' => '۱', '٢' => '۲', '٣' => '۳', '٤' => '۴',
            '٥' => '۵', '٦' => '۶', '٧' => '۷', '٨' => '۸', '٩' => '۹',
        ]);
        return $text;
    }

    /**
     * 📋 فهرست همه کدپوینت‌های فرم نمایشی که این کلاس تولید می‌کند (v2.8)
     * برای اعتبارسنجی فونت: فونتی که حتی یکی از این گلیف‌ها را نداشته باشد،
     * متن شکل‌یافته را مربع/مستطیل خالی نشان می‌دهد و نباید انتخاب شود.
     *
     * @return int[] کدپوینت‌های لازم (منحصربه‌فرد)
     */
    public static function requiredCodepoints(): array
    {
        $cps = [];
        foreach (self::TABLE as $forms) {
            foreach ($forms as $hex) {
                if ($hex !== null) {
                    $cps[(int)hexdec($hex)] = true;
                }
            }
        }
        /* 🔢 v2.14: ارقام فارسی (U+06F0–U+06F9) هم الزامی‌اند — چون همه اعداد
           متن تصاویر حالا فارسی رندر می‌شوند؛ فونت بدون این ارقام رد می‌شود */
        for ($d = 0x06F0; $d <= 0x06F9; $d++) {
            $cps[$d] = true;
        }
        return array_keys($cps);
    }
}
