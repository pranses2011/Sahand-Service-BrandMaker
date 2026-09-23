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
 * @version 1.0
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
        'ؤ' => ['FEE5', 'FEE6', null, null],
        'ه' => ['FEE9', 'FEEA', 'FEEB', 'FEEC'],
        'ۀ' => ['FEE9', 'FEEA', 'FEEB', 'FEEC'],
        'ی' => ['FBFC', 'FBFD', 'FBFE', 'FBFF'],
        'ي' => ['FBFE', 'FBFF', 'FBFE', 'FBFF'],
        'ة' => ['FE93', 'FE94', null, null],
    ];

    /** 🚫 نویسه‌های جداکننده اتصال (خودشان حذف می‌شوند یا اتصال را می‌شکنند) */
    private const JOIN_BREAKERS = ["\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{0640}"];

    /**
     * ✍️ شکل‌دهی + ترتیب بصری — آماده برای imagettftext
     */
    public static function shapeForImage(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return $text;
        }

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
            } elseif ($prevJoinable && !$nextJoinable && $forms[1] !== null) {
                $form = 1; // نهایی
            } elseif (!$prevJoinable && $nextJoinable && $forms[2] !== null) {
                $form = 2; // اولیه
            }
            $shaped[] = ['glyph' => self::cp((int)hexdec($forms[$form] ?? $forms[0])), 'rtl' => true, 'joinBreak' => false];
        }

        /* ---------- مرحله ۲: ترتیب بصری (bidi ساده‌شده) ---------- */
        return self::visualOrder($shaped);
    }

    /** ↔ آیا عنصر قبلی اجازه اتصال می‌دهد؟ */
    private static function prevJoins(array $shaped, array $chars, int $i): bool
    {
        for ($j = count($shaped) - 1; $j >= 0; $j--) {
            $el = $shaped[$j];
            if ($el['joinBreak']) { return false; } // نیم‌فاصله اتصال را می‌شکند
            if ($el['rtl'] === false) { return false; } // لاتین/عدد
            if ($el['rtl'] === null) { continue; }     // فاصله/نشانه — از قبل ادامه بده
            /* حروفی که فقط فرم مجزا/نهایی دارند از «چپ» متصل نمی‌شوند */
            return self::joinsFromLeft($chars[$i - (count($shaped) - $j)] ?? '');
        }
        return false;
    }

    /** ↔ آیا حرف در موقعیت $i از چپ به بعدی می‌چسبد؟ */
    private static function nextJoins(array $chars, int $i): bool
    {
        $n = count($chars);
        while ($i < $n) {
            $ch = $chars[$i];
            if (in_array($ch, self::JOIN_BREAKERS, true)) { return false; }
            if (isset(self::TABLE[$ch])) {
                return self::joinsFromRight($ch);
            }
            if (preg_match('/[\p{Arabic}]/u', $ch)) { return false; }
            if ($ch === ' ') { return false; } // فاصله اتصال را قطع می‌کند
            return false; // لاتین/عدد
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

    /** ↔ ترتیب بصری: توالی‌های RTL معکوس، LTR دست‌نخورده، ترتیب توالی‌ها معکوس */
    private static function visualOrder(array $shaped): string
    {
        $out = [];
        $i = 0;
        $n = count($shaped);
        while ($i < $n) {
            /* توالی جاری را تا تغییر جهت جمع کن (خنثی‌ها به توالی قبلی RTL می‌پیوندند اگر بعدش RTL است) */
            $isRtl = $shaped[$i]['rtl'] === true || $shaped[$i]['rtl'] === null;
            $run = [];
            while ($i < $n) {
                $el = $shaped[$i];
                $elIsRtl = $el['rtl'] === true;
                $elIsLtr = $el['rtl'] === false;
                if ($elIsLtr && $isRtl) { break; }
                if ($elIsRtl && !$isRtl) { break; }
                if ($el['rtl'] === null && $isRtl) {
                    /* خنثی: فقط اگر بعد از آن RTL بیاید جزو توالی RTL بماند */
                    $k = $i + 1;
                    $nextRtl = false;
                    while ($k < $n && $shaped[$k]['rtl'] === null) { $k++; }
                    if ($k < $n && $shaped[$k]['rtl'] === true) { $nextRtl = true; }
                    if (!$nextRtl) { break; }
                }
                $run[] = $el;
                $i++;
            }
            if (empty($run)) {
                /* 🛡️ پیشروی تضمینی — نویسه خنثیِ مرزی به‌عنوان توالی تک‌عضوی مصرف می‌شود
                   (نبود این شاخه = حلقه بی‌نهایت روی «متن + فاصله + عدد/لاتین») */
                $el = $shaped[$i];
                if (!$el['joinBreak']) { $out[] = $el['glyph']; }
                $i++;
                continue;
            }
            if ($isRtl) {
                /* معکوس کردن توالی RTL (نیم‌فاصله‌ها حذف) */
                for ($j = count($run) - 1; $j >= 0; $j--) {
                    if (!$run[$j]['joinBreak']) { $out[] = $run[$j]['glyph']; }
                }
            } else {
                foreach ($run as $el) { $out[] = $el['glyph']; }
            }
        }
        return implode('', $out);
    }

    /** 🔢 کدپوینت → نویسه UTF-8 */
    private static function cp(int $code): string
    {
        return mb_chr($code, 'UTF-8') ?: '';
    }
}
