<?php
/**
 * 🧰 فایل توابع کمکی عمومی
 * ==========================
 * شامل: اعتبارسنجی، پاکسازی، تاریخ شمسی (جلالی)،
 * تبدیل اعداد فارسی، ابزارهای ریسپان JSON و ...
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */

if (!defined('SAHAND_INIT')) {
    http_response_code(403);
    exit('⛔ دسترسی مستقیم مجاز نیست.');
}

/* ==================================================
 * 🛡️ توابع امنیتی و پاکسازی
 * ================================================== */

/**
 * 🧼 پاکسازی خروجی HTML (ضد XSS) — برای نمایش داده‌های کاربر
 */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * 🧼 پاکسازی ورودی متنی (حذف تگ‌ها + تریم + حذف کاراکترهای کنترلی)
 */
function clean_input(?string $value): string
{
    $value = strip_tags((string)$value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return trim($value);
}

/**
 * 🔢 تبدیل اعداد فارسی/عربی به انگلیسی (برای اعتبارسنجی و ذخیره‌سازی)
 */
function fa_to_en_digits(string $value): string
{
    $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace(array_merge($fa, $ar), array_merge($en, $en), $value);
}

/**
 * 🔢 تبدیل اعداد انگلیسی به فارسی (برای نمایش)
 */
function en_to_fa_digits(string $value): string
{
    $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return str_replace($en, $fa, $value);
}

/**
 * 📱 اعتبارسنجی شماره موبایل ایرانی (09xxxxxxxxx)
 */
function is_valid_iran_mobile(string $phone): bool
{
    $phone = fa_to_en_digits(trim($phone));
    return (bool)preg_match('/^(\+98|0098|98|0)?9\d{9}$/', $phone);
}

/**
 * ☎️ اعتبارسنجی تلفن ثابت ایرانی (0xxxxxxxxx)
 */
function is_valid_iran_phone(string $phone): bool
{
    $phone = fa_to_en_digits(trim($phone));
    return (bool)preg_match('/^0\d{9,10}$/', $phone);
}

/**
 * 📧 اعتبارسنجی ایمیل
 */
function is_valid_email(string $email): bool
{
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
}

/* ==================================================
 * 🌐 ابزارهای HTTP و ریسپان
 * ================================================== */

/**
 * 📤 پاسخ JSON استاندارد API
 */
function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * ➡️ ریدایرکت امن (فقط مسیرهای داخلی جلوگیری از Open Redirect)
 */
function redirect(string $path): void
{
    // جلوگیری از هدرهای خطرناک
    if (preg_match('#^https?://#i', $path) && strpos($path, BASE_URL) !== 0) {
        $path = '/'; // آدرس خارجی مجاز نیست
    }
    header('Location: ' . $path);
    exit;
}

/**
 * 📥 دریافت مقدار POST پاکسازی‌شده
 */
function post(string $key, string $default = ''): string
{
    return clean_input($_POST[$key] ?? $default);
}

/**
 * 📥 دریافت مقدار GET پاکسازی‌شده
 */
function get_param(string $key, string $default = ''): string
{
    return clean_input($_GET[$key] ?? $default);
}

/* ==================================================
 * 🗓️ تقویم جلالی (شمسی) — بدون وابستگی خارجی
 * ================================================== */

/**
 * 🗓️ تبدیل تاریخ میلادی به شمسی
 *
 * @param string $gregorian تاریخ میلادی (Y-m-d یا Y-m-d H:i:s)
 * @param bool   $withTime  نمایش ساعت
 * @return string تاریخ شمسی (مثلاً ۱۴۰۴/۰۷/۰۱ - ۱۴:۳۰)
 */
function jdate(string $gregorian, bool $withTime = false): string
{
    if (empty($gregorian) || $gregorian === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = strtotime($gregorian);
    if ($ts === false) {
        return '—';
    }
    [$jy, $jm, $jd] = gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    $result = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    if ($withTime) {
        $result .= ' - ' . date('H:i', $ts);
    }
    return en_to_fa_digits($result);
}

/**
 * 🗓️ تبدیل تاریخ شمسی به میلادی (برای ورودی فرم‌ها)
 */
function jalali_to_gregorian_date(string $jalali): string
{
    $parts = array_map('intval', explode('/', fa_to_en_digits(trim($jalali))));
    if (count($parts) < 3) {
        return date('Y-m-d');
    }
    [$gy, $gm, $gd] = jalali_to_gregorian($parts[0], $parts[1], $parts[2]);
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/**
 * 🔢 الگوریتم تبدیل جلالی ← میلادی
 */
function jalali_to_gregorian(int $jy, int $jm, int $jd): array
{
    $jy += 1595;
    $days = -355668 + (365 * $jy) + ((int)($jy / 33) * 8) + ((int)(($jy % 33) + 3) / 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * ((int)($days / 146097));
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * (int)(--$days / 36524);
        $days %= 36524;
        if ($days >= 365) {
            $days++;
        }
    }
    $gy += 4 * (int)($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $sal_a = [0, 31, ($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    for ($gm = 0; $gm < 13 && $gd > $sal_a[$gm]; $gm++) {
        $gd -= $sal_a[$gm];
    }
    return [$gy, $gm, $gd];
}

/**
 * 🔢 الگوریتم تبدیل میلادی ← جلالی
 */
function gregorian_to_jalali(int $gy, int $gm, int $gd): array
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100)) + ((int)(($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * ((int)($days / 12053)));
    $days %= 12053;
    $jy += 4 * (int)($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int)($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

/**
 * 📅 نام ماه‌های شمسی
 */
function jdate_month_name(int $month): string
{
    $months = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    return $months[$month] ?? '';
}

/**
 * 📅 نام روزهای هفته
 */
function jdate_day_name(int $dayOfWeek): string
{
    // 0=یکشنبه ... 6=شنبه
    $days = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];
    return $days[$dayOfWeek] ?? '';
}

/**
 * ⏰ نمایش «چند وقت پیش» فارسی (برای لاگ‌ها و اعلان‌ها)
 */
function time_ago_fa(string $datetime): string
{
    $ts = strtotime($datetime);
    if (!$ts) {
        return '—';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'چند لحظه پیش';
    }
    if ($diff < 3600) {
        return en_to_fa_digits((string)ceil($diff / 60)) . ' دقیقه پیش';
    }
    if ($diff < 86400) {
        return en_to_fa_digits((string)ceil($diff / 3600)) . ' ساعت پیش';
    }
    if ($diff < 2592000) {
        return en_to_fa_digits((string)ceil($diff / 86400)) . ' روز پیش';
    }
    return jdate($datetime);
}

/* ==================================================
 * 📝 ابزارهای متنی و فارسی
 * ================================================== */

/**
 * ✂️ خلاصه‌سازی متن فارسی با حفظ کلمات کامل
 */
function excerpt(string $text, int $length = 150): string
{
    $text = trim(strip_tags($text));
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    $cut = mb_substr($text, 0, $length);
    // برش تا آخرین فاصله برای عدم نصف‌شدن کلمات
    $spacePos = mb_strrpos($cut, ' ');
    if ($spacePos !== false) {
        $cut = mb_substr($cut, 0, $spacePos);
    }
    return $cut . '…';
}

/**
 * 🔤 تولید اسلاگ URL از متن (پشتیبانی فارسی + انگلیسی)
 */
function make_slug(string $text): string
{
    $text = trim(mb_strtolower($text));
    // تبدیل جداکننده‌های رایج به خط تیره
    $text = preg_replace('/[\s\_]+/u', '-', $text) ?? '';
    // حذف کاراکترهای غیرمجاز (فارسی، انگلیسی، عدد و خط تیره مجاز)
    $text = preg_replace('/[^a-z0-9\-\x{0600}-\x{06FF}]/u', '', $text) ?? '';
    $text = trim($text, '-');
    return $text !== '' ? $text : 'page-' . substr(md5(uniqid('', true)), 0, 6);
}

/**
 * 🔤 تولید شناسه یکتا (برای کلیدهای عمومی)
 */
function unique_id(int $length = 32): string
{
    return substr(bin2hex(random_bytes((int)ceil($length / 2))), 0, $length);
}

/**
 * 📏 خوانایی حجم فایل
 */
function human_filesize(int $bytes): string
{
    $units = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت'];
    $i = 0;
    while ($bytes >= 1024 && $i < 3) {
        $bytes /= 1024;
        $i++;
    }
    return en_to_fa_digits(round($bytes, 1) . '') . ' ' . $units[$i];
}

/**
 * 🎨 روشن یا تاریک بودن رنگ HEX — برای انتخاب رنگ متن مناسب
 */
function is_dark_color(string $hex): bool
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    // فرمول درک روشنایی چشم انسان
    $brightness = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
    return $brightness < 130;
}

/**
 * 🎨 تولید رنگ متن مناسب (سیاه/سفید) بر اساس پس‌زمینه
 */
function contrast_text_color(string $hex): string
{
    return is_dark_color($hex) ? '#ffffff' : '#111827';
}

/* ==================================================
 * 🔔 فلش‌مسیج‌ها (پیام‌های یک‌بارمصرف)
 * ================================================== */

/**
 * ➕ ثبت پیام فلش
 */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * 📥 دریافت و پاکسازی پیام‌های فلش
 */
function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/* ==================================================
 * 🖼️ آواتار/تصویر پیش‌فرض
 * ================================================== */

/**
 * 🖼️ آدرس تصویر با fallback به placeholder
 */
function asset_url(string $path): string
{
    if (empty($path)) {
        return BASE_URL . '/assets/images/placeholders/default.svg';
    }
    return (strpos($path, 'http') === 0) ? $path : BASE_URL . '/' . ltrim($path, '/');
}
