<?php
/**
 * 🧪 تست S08 v2.44 — تکمیل تنظیمات صفحه (رندر سرور bb_layout_html)
 * بدون دیتابیس — مطابق الگوی p3.test.php
 */

if (!defined('SAHAND_INIT')) { define('SAHAND_INIT', true); }
require_once dirname(__DIR__) . '/includes/helpers.php';

if (!defined('BRAND_INIT')) { define('BRAND_INIT', true); }
if (!defined('BRAND_ID')) { define('BRAND_ID', 7); }
if (!defined('BRAND_NAME_FA')) { define('BRAND_NAME_FA', 'سرویس تبریز'); }
if (!defined('BRAND_LANG')) { define('BRAND_LANG', 'fa'); }
if (!function_exists('fetchFromAPI')) {
    function fetchFromAPI(string $ep, int $ttl = 300): array { return ['data' => []]; }
}
if (!function_exists('cdn_asset')) {
    function cdn_asset(string $p): string { return '/' . $p; }
}

require_once dirname(__DIR__) . '/templates/brand-core/includes/block-renderer-core.php';
require_once dirname(__DIR__) . '/templates/brand-core/includes/blocks.php';

$T->section('S08-۱) متغیرهای CSS جدید صفحه');

/* خروجی تابع رشته است — تجزیه به آرایه برای ادعاها */
$parseVars = static function (array $props): array {
    $str = pv_page_css_vars($props);
    $out = [];
    foreach (array_filter(explode(';', $str)) as $chunk) {
        $kv = explode(':', $chunk, 2);
        if (count($kv) === 2) { $out[trim($kv[0])] = trim($kv[1]); }
    }
    return $out;
};

$vars = $parseVars(['containerWidth' => 'custom', 'containerPx' => '1320']);
$T->assertEquals('عرض دلخواه px', '1320px', $vars['--pg-width'] ?? '');

$vars = $parseVars(['cardRadius' => 'pill']);
$T->assertEquals('گردی کارت pill', '34px', $vars['--pg-card-rad'] ?? '');
$vars = $parseVars(['cardRadius' => 'default']);
$T->assert('گردی کارت default = بدون متغیر (مانند صفحه)', !isset($vars['--pg-card-rad']));

$vars = $parseVars(['btnRadius' => 'sharp']);
$T->assertEquals('گردی دکمه تیز', '3px', $vars['--pg-btn-rad'] ?? '');
$vars = $parseVars(['btnRadius' => 'pill']);
$T->assertEquals('گردی دکمه کپسولی', '999px', $vars['--pg-btn-rad'] ?? '');

$vars = $parseVars(['darkText' => '#dbeafe']);
$T->assertEquals('رنگ متن حالت تیره', '#dbeafe', $vars['--pg-dark-text'] ?? '');

$T->section('S08-۲) کلاس‌های قاب صفحه در bb_layout_html');

$layout = [
    ['block' => '_page', 'props' => ['pageDark' => 1, 'cardHover' => 'glow']],
    ['block' => 'text', 'props' => ['text' => 'سلام']],
];
$html = bb_layout_html(['layout' => $layout]);
$T->assert('حالت تیره: کلاس bb-page-dark', str_contains($html, 'bb-page-dark'));
$T->assert('جلوه hover: کلاس bb-hover-glow', str_contains($html, 'bb-hover-glow'));

$layout = [
    ['block' => '_page', 'props' => ['pageLayout' => 'boxed', 'cardHover' => 'none']],
    ['block' => 'text', 'props' => ['text' => 'سلام']],
];
$html = bb_layout_html(['layout' => $layout]);
$T->assert('چیدمان جعبه‌ای + بدون hover', str_contains($html, 'bb-layout-boxed') && !str_contains($html, 'bb-hover-'));

$T->section('S08-۳) بلور زمینه + نمایش تدریجی');

$layout = [
    ['block' => '_page', 'props' => [
        'pageBg' => 'image',
        'bgImage' => 'https://example.com/bg.jpg',
        'bgBlur' => '8',
        'scrollReveal' => 1,
        'cardHover' => 'none',
    ]],
    ['block' => 'text', 'props' => ['text' => 'سلام']],
];
$html = bb_layout_html(['layout' => $layout]);
$T->assert('بلور: کلاس bb-bg-blur + متغیر', str_contains($html, 'bb-bg-blur') && str_contains($html, '--pg-bg-blur:8px') && str_contains($html, '--pg-bg-img:url'));
$T->assert('reveal: اسکریپت IntersectionObserver', str_contains($html, 'IntersectionObserver') && str_contains($html, 'bb-reveal'));

$layout = [
    ['block' => '_page', 'props' => ['pageBg' => 'image', 'bgImage' => 'https://example.com/bg.jpg', 'bgBlur' => '0', 'cardHover' => 'none']],
    ['block' => 'text', 'props' => ['text' => 'سلام']],
];
$html = bb_layout_html(['layout' => $layout]);
$T->assert('بدون بلور: تصویر inline (رفتار قدیمی)', str_contains($html, 'background-image:linear-gradient') && str_contains($html, 'bg.jpg') && !str_contains($html, 'bb-bg-blur'));
