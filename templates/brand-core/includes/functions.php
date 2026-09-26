<?php
/**
 * 🧰 توابع کمکی مشترک سایت برند
 * ================================
 * 🆕 v2.24: این فایل حالا واقعاً بارگذاری می‌شود! قبلاً هیچ فایلی آن را
 * require نمی‌کرد و صفحاتی که render_page_section / article_image را صدا
 * می‌زدند با «Call to undefined function» فاتل می‌شدند (ریشه «صفحات خالی»).
 * نقطه بارگذاری: config.php (قالب و تولیدی ConfigGenerator).
 *
 * @package SahandBrandSite
 */
if (!defined('BRAND_INIT')) { http_response_code(403); exit; }

/** 🛡️ گارد تعریف — ضد «Cannot redeclare function» اگر بارگذاری دوباره شد */
if (!function_exists('render_page_section')) {
    /**
     * 📄 رندر بخش محتوای صفحه برند از داده API
     */
    function render_page_section(array $content, string $field = 'content'): void
    {
        $html = $content[$field] ?? '';
        // پاراگراف‌بندی متن ساده
        if ($html !== '' && strpos($html, '<') === false) {
            $html = '<p>' . nl2br(e($html)) . '</p>';
        }
        echo $html;
    }
}

if (!function_exists('article_image')) {
    /**
     * 🖼️ تصویر شاخص مقاله با placeholder
     *
     * 🚨 v2.26 — ریشه «تصویر شاخص مقالات نشان داده نمی‌شود»: مقدار
     * featured_image در دیتابیس «مسیر نسبی» است (uploads/articles/wm/xxx.jpg)
     * و قبلاً همان مسیر خام در src می‌رفت → مرورگر آن را نسبت به «دامنه سایت
     * برند» می‌خواست در حالی که فایل روی سرور «سایت ساز» است → 404 همیشگی.
     * ✅ اکنون مسیرهای نسبی با cdn_asset به دامنه سایت ساز تبدیل می‌شوند.
     */
    function article_image(?string $image, string $alt): string
    {
        $src = $image ? cdn_asset($image) : cdn_asset('images/placeholders/article.svg');
        return '<img src="' . e($src) . '" alt="' . e($alt) . '" loading="lazy" class="article-image">';
    }
}

if (!function_exists('page_url')) {
    /**
     * 🔗 ساخت URL کامل صفحه برند
     */
    function page_url(string $slug): string
    {
        return '/' . ltrim($slug, '/');
    }
}

/* ==================================================
 * 🎨 v2.26 — رنگ هیدر از رنگ‌های تاکیدی لوگو
 * هیدر سایت برند به‌جای سفید همیشگی، گرادیانی از
 * «رنگ تاکیدی» (accent) استخراج‌شده از لوگو می‌گیرد؛
 * رنگ متن هیدر با تضمین کنتراست WCAG (≥ ۴.۵:۱) محاسبه
 * می‌شود — برای هر دو تم روشن و تاریک.
 * ================================================== */

/** 🛡️ گارد تعریف — ضد «Cannot redeclare function» اگر بارگذاری دوباره شد */
if (!function_exists('brand_shift_hex')) {
    /**
     * 🎚️ شیفت رنگ HEX به سمت روشن (+) یا تیره (−) — درصدی
     * (هم‌ارز ColorAnalyzer::shiftToward سایت ساز — نسخه مستقل قالب)
     */
    function brand_shift_hex(string $hex, int $percent): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return $hex;
        }
        $amount = (int)round($percent / 100 * 255);
        return sprintf('%02x%02x%02x',
            max(0, min(255, hexdec(substr($hex, 0, 2)) + $amount)),
            max(0, min(255, hexdec(substr($hex, 2, 2)) + $amount)),
            max(0, min(255, hexdec(substr($hex, 4, 2)) + $amount))
        );
    }
}

if (!function_exists('brand_hex_luminance')) {
    /**
     * 💡 روشنایی نسبی رنگ (WCAG relative luminance) — ورودی بدون #
     */
    function brand_hex_luminance(string $hex): float
    {
        $rgb = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        $ch = [];
        foreach ($rgb as $c) {
            $c = $c / 255;
            $ch[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $ch[0] + 0.7152 * $ch[1] + 0.0722 * $ch[2];
    }
}

if (!function_exists('brand_contrast_ratio')) {
    /**
     * 📏 نسبت کنتراست دو رنگ HEX (بدون #) — ۱ تا ۲۱
     */
    function brand_contrast_ratio(string $h1, string $h2): float
    {
        $l1 = brand_hex_luminance($h1);
        $l2 = brand_hex_luminance($h2);
        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }
}

if (!function_exists('brand_header_vars_css')) {
    /**
     * 🎨 تولید متغیرهای CSS هیدر از رنگ تاکیدی لوگو
     * ==========================================================
     * خروجی: رشته CSS مثل:
     *   :root{--header-bg:linear-gradient(...);--header-text:#fff;...}
     * یا رشته خالی اگر پالت خودش --header-bg داشته باشد (تقدم پالت).
     *
     * منطق:
     *  ① دو سر گرادیانت از مشتقات accent (تیره‌تر ← خود accent)
     *  ② متن سفید یا تیره — هر کدام کنتراست بیشتری روی دو سر بدهد
     *  ③ اگر کنتراستِ رنگ انتخاب‌شده < ۴.۵ باشد، هر دو سر گرادیانت
     *     هم‌جهت (تیره‌تر اگر متن سفید / روشن‌تر اگر متن تیره) شیفت
     *     می‌شوند تا خوانایی تضمین شود (حداکثر ۲۵ گام ۳٪).
     *  ④ هاور منو: پوشش نیمه‌شفاف هم‌خانواده متن.
     *
     * @param array $palette پالت تم (light یا dark) — کلیدهای '--color-accent' و ...
     * @param bool  $dark    تم تاریک؟
     */
    function brand_header_vars_css(array $palette, bool $dark): string
    {
        /* 🎨 رنگ تاکیدی از پالت (اگر نبود: کهربایی روشن / آبی تیره) */
        $accent = strtolower(ltrim(trim((string)($palette['--color-accent'] ?? '')), '#'));
        if (preg_match('/^[0-9a-f]{3}$/', $accent)) {
            $accent = $accent[0] . $accent[0] . $accent[1] . $accent[1] . $accent[2] . $accent[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/', $accent)) {
            $accent = $dark ? '3b82f6' : 'f59e0b';
        }
        /* اگر پالت از قبل --header-bg دارد، همان معتبر است (نسخه‌های جدید پالت) */
        if (trim((string)($palette['--header-bg'] ?? '')) !== '') {
            return '';
        }

        /* ① دو سر گرادیانت — تیره‌تر در ابتدای خط (حرکت نوری هماهنگ RTL) */
        $end1 = brand_shift_hex($accent, $dark ? -26 : -22);
        $end2 = brand_shift_hex($accent, $dark ? -12 : 0);

        /* ② انتخاب متن با بیشترین کمینه کنتراست */
        $pickText = static function (string $e1, string $e2): string {
            $whiteMin = min(brand_contrast_ratio('ffffff', $e1), brand_contrast_ratio('ffffff', $e2));
            $darkMin  = min(brand_contrast_ratio('0f172a', $e1), brand_contrast_ratio('0f172a', $e2));
            return $whiteMin >= $darkMin ? 'ffffff' : '0f172a';
        };
        $text = $pickText($end1, $end2);

        /* ③ تضمین WCAG — شیفت هم‌جهت گرادیانت تا متن خوانا شود */
        $dir = ($text === 'ffffff') ? -3 : 3;
        for ($i = 0; $i < 25; $i++) {
            $minC = min(brand_contrast_ratio($text, $end1), brand_contrast_ratio($text, $end2));
            if ($minC >= 4.5) {
                break;
            }
            $end1 = brand_shift_hex($end1, $dir);
            $end2 = brand_shift_hex($end2, $dir);
        }

        /* ④ هاور — پوشش نیمه‌شفاف روی گرادیانت */
        $hover = ($text === 'ffffff') ? 'rgba(255,255,255,.16)' : 'rgba(15,23,42,.10)';

        return '--header-bg:linear-gradient(135deg,#' . $end1 . ' 0%,#' . $end2 . ' 100%);'
            . '--header-text:#' . $text . ';'
            . '--header-hover-bg:' . $hover . ';';
    }
}
