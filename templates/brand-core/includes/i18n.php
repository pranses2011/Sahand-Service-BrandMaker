<?php
/**
 * 🌍 پایه چندزبانگی سایت برند (P3 — v2.37)
 * =========================================
 * نقشه راه P3 گزارش تحلیل جامع: «چندزبانگی» — این نسخه «پایه مسیر» است
 * (مثل الگوی شروع Repository در P2): زیرساخت کامل + دو زبان فارسی/انگلیسی
 * برای «رابط کاربری» سایت برند. محتوای اصلی (مقالات/صفحات) فعلاً فارسی
 * می‌ماند و به‌صورت تدریجی با کلیدهای ترجمه قابل افزودن است.
 *
 * چه چیزی فعال است:
 *   ① تشخیص locale: مسیر /en/… (قوی‌ترین — سئو) → پارامتر ?lang= → پیش‌فرض fa
 *   ② BRAND_LANG + BRAND_DIR + BRAND_HTML_LANG ثابت‌های سراسری صفحه
 *   ③ __t(key) — واژه‌نامه رابط کاربری (fa/en): منو، دکمه‌ها، برچسب‌ها
 *   ④ lang_url(path) — ساخت لینکِ همان صفحه در زبان دیگر
 *   ⑤ تگ‌های hreflang در header (جلوگیری از محتوای تکراری بین‌زبانی)
 *
 * 🧭 مسیر رشد بعدی (خارج از این نسخه):
 *   • جدول ترجمه محتوا (عنوان/خلاصه مقاله به زبان‌های دیگر)
 *   • صفحه /en/blog با فهرست مقالات انگلیسی
 *   • زبان سوم (ar) فقط با افزودن به $GLOBALS['BRAND_I18N']
 *
 * @package SahandBrandSite
 * @since   1.4.0 (سیستم 2.37)
 */
if (!defined('BRAND_INIT')) {
    http_response_code(403);
    exit;
}

/** 🗂 واژه‌نامه رابط کاربری — منبع حقیقت واحد ترجمه‌های سایت برند */
$GLOBALS['BRAND_I18N'] = [
    'fa' => [
        'dir' => 'rtl',
        'html_lang' => 'fa',
        'native_name' => 'فارسی',
        'switch_label' => 'English',
        'switch_code' => 'en',
        'nav_home' => 'خانه',
        'nav_services' => 'خدمات',
        'nav_blog' => 'مقالات',
        'nav_about' => 'درباره ما',
        'nav_contact' => 'تماس با ما',
        'nav_request' => 'ثبت درخواست',
        'hero_cta' => 'ثبت درخواست خدمات آنلاین',
        'hero_services' => 'مشاهده خدمات',
        'search_title' => 'جستجو در سایت',
        'language_switch' => 'تغییر زبان',
        'footer_rights' => 'تمامی حقوق محفوظ است.',
        'offline_title' => 'اتصال اینترنت برقرار نیست',
    ],
    'en' => [
        'dir' => 'ltr',
        'html_lang' => 'en',
        'native_name' => 'English',
        'switch_label' => 'فارسی',
        'switch_code' => 'fa',
        'nav_home' => 'Home',
        'nav_services' => 'Services',
        'nav_blog' => 'Articles',
        'nav_about' => 'About Us',
        'nav_contact' => 'Contact',
        'nav_request' => 'Request Service',
        'hero_cta' => 'Request Online Repair Service',
        'hero_services' => 'View Services',
        'search_title' => 'Search the site',
        'language_switch' => 'Switch language',
        'footer_rights' => 'All rights reserved.',
        'offline_title' => 'You are offline',
    ],
];

/* ════════════ تشخیص locale (یک‌بار در هر صفحه) ════════════ */
if (!defined('BRAND_LANG')) {
    $brandLang = 'fa';
    /* ① پارامتر ?lang=en — از مسیرهای htaccessِ /en/ می‌آید یا انتخاب دستی */
    $langParam = strtolower(trim((string)($_GET['lang'] ?? '')));
    if (isset($GLOBALS['BRAND_I18N'][$langParam])) {
        $brandLang = $langParam;
    }
    define('BRAND_LANG', $brandLang);
}

if (!defined('BRAND_DIR')) {
    define('BRAND_DIR', $GLOBALS['BRAND_I18N'][BRAND_LANG]['dir'] ?? 'rtl');
}
if (!defined('BRAND_HTML_LANG')) {
    define('BRAND_HTML_LANG', $GLOBALS['BRAND_I18N'][BRAND_LANG]['html_lang'] ?? 'fa');
}

/**
 * 🔤 ترجمه رابط کاربری — کلید می‌گیرد، متن زبان جاری می‌دهد
 * کلید ناشناخته = خود کلید برمی‌گردد (ترجمه ناقص هرگز صفحه را نمی‌شکند)
 */
function __t(string $key): string
{
    $dict = $GLOBALS['BRAND_I18N'][BRAND_LANG] ?? $GLOBALS['BRAND_I18N']['fa'];
    return (string)($dict[$key] ?? ($GLOBALS['BRAND_I18N']['fa'][$key] ?? $key));
}

/**
 * 🌐 ساخت لینک همان صفحه در زبان دیگر (برای سوییچر + hreflang)
 * نمونه: /blog/post-x + en → /en/blog/post-x
 *        /en/services + fa → /services
 */
function lang_url(string $targetLang, ?string $requestUri = null): string
{
    $uri = $requestUri ?? (string)($_SERVER['REQUEST_URI'] ?? '/');
    /* کوئری‌استرینگ جدا می‌شود (lang= در آن دستکاری نمی‌شود — مسیر منبع حقیقت است) */
    [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');
    $path = '/' . ltrim($path, '/');

    if (preg_match('#^/(en|fa)(/|$)#', $path, $m)) {
        $rest = substr($path, strlen('/' . $m[1]));
        $path = ($targetLang === 'fa') ? $rest : ('/' . $targetLang . $rest);
    } elseif ($targetLang !== 'fa') {
        $path = '/' . $targetLang . $path;
    }
    /* پارامتر lang= قدیمی حذف می‌شود — مسیر، منبع حقیقت locale است */
    if ($query !== '') {
        $keep = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair !== '' && strpos($pair, 'lang=') !== 0) {
                $keep[] = $pair;
            }
        }
        $query = implode('&', $keep);
    }
    return $path . ($query !== '' ? '?' . $query : '');
}

/**
 * 🔗 نسخه محلی‌شده یک مسیر داخلی در زبان جاری
 * نمونه: در حالت fa → '/blog'؛ در حالت en → '/en/blog'
 */
function localized_path(string $path): string
{
    $path = '/' . ltrim($path, '/');
    if (preg_match('#^/(en|fa)(/|$)#', $path)) {
        return $path; /* خودش محلی است */
    }
    return (BRAND_LANG === 'fa') ? $path : ('/' . BRAND_LANG . $path);
}

/**
 * 🏷 تگ‌های hreflang — جلوگیری از محتوای تکراری بین‌زبانی در سئو
 * خروجی: <link rel="alternate" hreflang="fa" href="…"> + en + x-default
 */
function i18n_hreflang_tags(): string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $out = '';
    foreach (['fa', 'en'] as $l) {
        $href = 'https://' . BRAND_DOMAIN . lang_url($l, $uri);
        $out .= '<link rel="alternate" hreflang="' . $l . '" href="' . e($href) . '">' . "\n    ";
    }
    $out .= '<link rel="alternate" hreflang="x-default" href="' . e('https://' . BRAND_DOMAIN . lang_url('fa', $uri)) . '">';
    return $out;
}
