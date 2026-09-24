<?php
/**
 * 🗓️ کلاس تاریخ شمسی (جلالی) — مخصوص افزونه استقرار خودکار
 * ==========================================================
 * تبدیل تاریخ میلادی به شمسی به صورت کاملاً داخلی
 * (بدون API یا کتابخانه خارجی — طبق الزام سند بخش ۲۱)
 *
 * فرمت‌های خروجی:
 *   🔤 نام فایل بکاپ:  1404-03-25_14-30-45
 *   🖥️ نمایش پنل:      ۱۴۰۴/۰۳/۲۵ ۱۴:۳۰
 *   🗄️ ذخیره دیتابیس:  1404-03-25 14:30:45
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class ShamsiDate
{
    /**
     * 📅 تبدیل میلادی → شمسی (الگوریتم داخلی — تقویم جلالی دقیق)
     *
     * @param int $gy سال میلادی
     * @param int $gm ماه میلادی (۱-۱۲)
     * @param int $gd روز میلادی (۱-۳۱)
     * @return array [سال، ماه، روز] شمسی
     */
    public static function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        // جدول تجمعی روزهای ماه‌های میلادی (غیر کبیسه — کبیسه با جابجایی gy2 مدیریت می‌شود)
        $gDaysInMonth = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

        // اگر بعد از فوریه است، سال برای محاسبه کبیسه یک واحد جلو می‌رود
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;

        // شمارش روزها از مبدأ تقویم جلالی
        $days = 355666
            + (365 * $gy)
            + intdiv($gy2 + 3, 4)
            - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400)
            + $gd
            + $gDaysInMonth[$gm - 1];

        // محاسبه سال شمسی با تقسیم‌های متوالی (دوره‌های ۳۳ ساله)
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        // ۶ ماه اول ۳۱ روزه، ۵ ماه بعدی ۳۰ روزه
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }

    /**
     * 📅 تبدیل شمسی → میلادی (برای بازیابی تاریخ‌های ذخیره‌شده)
     *
     * @param int $jy سال شمسی
     * @param int $jm ماه شمسی (۱-۱۲)
     * @param int $jd روز شمسی (۱-۳۱)
     * @return array [سال، ماه، روز] میلادی
     */
    public static function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668
            + (365 * $jy)
            + (intdiv($jy, 33) * 8)
            + intdiv(($jy % 33) + 3, 4)
            + $jd
            + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);

        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;

        // طول ماه‌های میلادی با در نظر گرفتن کبیسه
        $isLeap = ($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0);
        $months = [31, $isLeap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        $gm = 0;
        while ($gm < 12 && $gd > $months[$gm]) {
            $gd -= $months[$gm];
            $gm++;
        }
        return [$gy, $gm + 1, $gd];
    }

    /**
     * 📝 تاریخ شمسی برای نام فایل بکاپ — الگوی سند:
     * {brand-slug}_{YYYY-MM-DD}_{HH-mm-ss}.zip
     * همه اعداد انگلیسی (نه فارسی) — طبق الزام سند
     *
     * @param int|null $timestamp زمان یونیکس (null = اکنون)
     * @return string مثلاً 1404-03-25_14-30-45
     */
    public static function forFilename(?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();
        [$jy, $jm, $jd] = self::gregorianToJalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
        return sprintf('%04d-%02d-%02d_%02d-%02d-%02d', $jy, $jm, $jd, (int)date('H', $ts), (int)date('i', $ts), (int)date('s', $ts));
    }

    /**
     * 📝 تاریخ شمسی کامل برای ذخیره در دیتابیس (ستون shamsi_date)
     *
     * @param int|null $timestamp زمان یونیکس (null = اکنون)
     * @return string مثلاً 1404-03-25 14:30:45
     */
    public static function full(?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();
        [$jy, $jm, $jd] = self::gregorianToJalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $jy, $jm, $jd, (int)date('H', $ts), (int)date('i', $ts), (int)date('s', $ts));
    }

    /**
     * 🎨 تاریخ شمسی زیبا برای نمایش در پنل (ستون shamsi_date_display)
     * با اعداد فارسی — مثلاً ۱۴۰۴/۰۳/۲۵ ۱۴:۳۰
     *
     * @param int|null $timestamp زمان یونیکس (null = اکنون)
     * @return string
     */
    public static function forDisplay(?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();
        [$jy, $jm, $jd] = self::gregorianToJalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
        $plain = sprintf('%04d/%02d/%02d %02d:%02d', $jy, $jm, $jd, (int)date('H', $ts), (int)date('i', $ts));
        // تبدیل ارقام به فارسی برای نمایش زیبا (تابع سراسری helpers.php)
        return function_exists('en_to_fa_digits') ? en_to_fa_digits($plain) : $plain;
    }

    /**
     * 🔢 سال شمسی جاری (برای متغیر {year} در الگوی مسیر)
     *
     * @return string مثلاً 1404
     */
    public static function currentYear(): string
    {
        [$jy] = self::gregorianToJalali((int)date('Y'), (int)date('n'), (int)date('j'));
        return sprintf('%04d', $jy);
    }

    /**
     * 🔢 ماه شمسی جاری (برای متغیر {month} در الگوی مسیر)
     *
     * @return string مثلاً 03
     */
    public static function currentMonth(): string
    {
        [, $jm] = self::gregorianToJalali((int)date('Y'), (int)date('n'), (int)date('j'));
        return sprintf('%02d', $jm);
    }
}
