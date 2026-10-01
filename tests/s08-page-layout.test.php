<?php
/**
 * 🧪 تست S08 (v2.45) — راستی‌آزمایی انتها-به-انتهای تنظیمات صفحه
 * =================================================================
 * نگرانی کاربر: «شاید درست کردی ولی رندر نمیشه» — این تست زنجیره کامل
 * «گره _page در layout_json → bb_layout_html سایت برند → کلاس/متغیر CSS»
 * را با هر پنج حالت چیدمان می‌سنجد (بدون دیتابیس — الگوی p3).
 *
 * اجرا: php tests/run.php --filter=s08-page
 */

if (!defined('SAHAND_INIT')) { define('SAHAND_INIT', true); }
require_once dirname(__DIR__) . '/includes/helpers.php';

/* 🎭 ثابت‌های سایت برند */
if (!defined('BRAND_INIT')) { define('BRAND_INIT', true); }
if (!defined('BRAND_ID')) { define('BRAND_ID', 7); }
if (!defined('BRAND_NAME_FA')) { define('BRAND_NAME_FA', 'سرویس تست'); }
if (!defined('BRAND_CITY')) { define('BRAND_CITY', 'تبریز'); }
if (!defined('BRAND_LANG')) { define('BRAND_LANG', 'fa'); }

require_once dirname(__DIR__) . '/templates/brand-core/includes/blocks.php';

/** @var TestRunner $T */
$T = $GLOBALS['T'] ?? null;
if (!$T) { echo "⛔ اجرا از tests/run.php\n"; return; }
$T->file('S08 تنظیمات صفحه — رندر انتها-به-انتها');

/* 🔧 سازنده چیدمان آزمایشی: گره _page + یک بلوک hero */
function s08_tpl(array $pageProps): array
{
    return [
        'name' => 'تست S08',
        'layout' => [
            ['block' => '_page', 'props' => $pageProps],
            ['block' => 'hero', 'props' => ['title' => 'تست', 'subtitle' => 'زیرعنوان', 'btnTexts' => ['تست']]],
        ],
        'pelements' => [],
    ];
}

/* ═══ ① پنج حالت چیدمان ═══ */
$T->section('① حالت‌های چیدمان صفحه');
$cases = [
    ['normal',  '',                    false, 'حالت عادی: بدون کلاس چیدمان'],
    ['full',    'bb-layout-full',      false, 'تمام‌عرض لبه‌به‌لبه'],
    ['boxed',   'bb-layout-boxed',     false, 'جعبه‌ای'],
    ['center',  'bb-layout-center',    false, 'وسط‌چین'],
    ['percent', 'bb-layout-percent',   true,  'درصد دلخواه'],
];
foreach ($cases as [$mode, $cls, $needVar, $label]) {
    $props = ['pageLayout' => $mode];
    if ($mode === 'percent') { $props['customWidthPct'] = '65'; }
    if ($mode === 'boxed') { $props['boxedBg'] = '#dbeafe'; $props['boxedPad'] = '30'; }
    $html = bb_layout_html(s08_tpl($props));
    $ok = $html !== '' && strpos($html, 'bb-wrap') !== false;
    if ($cls !== '') { $ok = $ok && strpos($html, $cls) !== false; }
    $T->assert($label . ' → کلاس ' . ($cls ?: '(هیچ)'), $ok);
    if ($needVar) {
        $T->assert('درصد دلخواه → متغیر --pg-layout-w:65%', strpos($html, '--pg-layout-w:65%') !== false);
    }
}

/* ═══ ② حالت جعبه‌ای — متغیرهای کامل ═══ */
$T->section('② جعبه‌ای — متغیرها');
$html = bb_layout_html(s08_tpl(['pageLayout' => 'boxed', 'boxedBg' => '#dbeafe', 'boxedPad' => '30', 'boxedRadius' => '22']));
$T->assert('متغیر --pg-box-bg:#dbeafe', strpos($html, '--pg-box-bg:#dbeafe') !== false);
$T->assert('متغیر --pg-box-pad:30px', strpos($html, '--pg-box-pad:30px') !== false);
$T->assert('متغیر --pg-box-rad:22px', strpos($html, '--pg-box-rad:22px') !== false);

/* ═══ ③ سایر تنظیمات کلیدی صفحه ═══ */
$T->section('③ زمینه/جلوه/تایپوگرافی');
$html = bb_layout_html(s08_tpl([
    'pageBg' => 'gradient', 'gradFrom' => '#eff6ff', 'gradTo' => '#ddd6fe', 'gradAngle' => '135',
    'cardHover' => 'zoom', 'pageDark' => 1, 'scrollProgress' => 1, 'backToTop' => 1,
    'bodyColor' => '#334155', 'lineHeight' => 'roomy', 'sectionSpacing' => 'roomy',
    'containerWidth' => 'custom', 'containerPx' => '960',
]));
$T->assert('گرادیانت: --pg-grad با دو رنگ', strpos($html, 'linear-gradient(135deg,#eff6ff,#ddd6fe)') !== false);
$T->assert('جلوه hover=zoom روی قاب', strpos($html, 'bb-hover-zoom') !== false);
$T->assert('حالت تیره: کلاس bb-page-dark', strpos($html, 'bb-page-dark') !== false);
$T->assert('نوار پیشرفت اسکرول: عنصر bb-scroll-progress', strpos($html, 'bb-scroll-progress') !== false);
$T->assert('دکمه بازگشت به بالا: عنصر bb-back-top', strpos($html, 'bb-back-top') !== false);
$T->assert('رنگ متن بدنه: --pg-body', strpos($html, '--pg-body:#334155') !== false);
$T->assert('عرض دلخواه px: --pg-width:960px', strpos($html, '--pg-width:960px') !== false);

/* ═══ ④ سازگاری: بدون گره _page (قالب‌های قدیمی) ═══ */
$T->section('④ سازگاری قالب‌های قدیمی (بدون _page)');
$old = ['name' => 'قدیمی', 'layout' => [['block' => 'hero', 'props' => ['title' => 'قدیمی']]], 'pelements' => []];
$html = bb_layout_html($old);
$T->assert('بدون _page رندر می‌شود و کلاس چیدمانی ندارد', $html !== '' && strpos($html, 'bb-layout-') === false);

/* ═══ ⑤ CSS سایت: تعریف کلاس‌ها در blocks.css ═══ */
$T->section('⑤ تعریف CSS چیدمان در blocks.css سایت');
$css = (string)@file_get_contents(dirname(__DIR__) . '/templates/brand-core/css/blocks.css');
foreach (['bb-layout-full', 'bb-layout-boxed', 'bb-layout-center', 'bb-layout-percent', '--pg-layout-w', '--pg-box-bg'] as $needle) {
    $T->assert("blocks.css شامل {$needle}", strpos($css, $needle) !== false);
}

/* ═══ ⑥ ذخیره سازنده: تابع serialize شامل _page ═══ */
$T->section('⑥ سازنده — سریال‌سازی گره _page');
$js = (string)@file_get_contents(dirname(__DIR__) . '/assets/js/template-builder.js');
$T->assert('سازنده گره _page را در layout می‌نویسد', strpos($js, "block: '_page'") !== false);
$T->assert('سازنده حالت‌های چیدمان را ارائه می‌دهد', strpos($js, "'boxed', 'جعبه‌ای") !== false);
$T->assert('سازنده درصد دلخواه را ارائه می‌دهد', strpos($js, 'customWidthPct') !== false);

echo "\n";
