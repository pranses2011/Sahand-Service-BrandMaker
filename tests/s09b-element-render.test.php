<?php
/**
 * 🧪 تست S09b (v2.45) — اثبات رندرشدن تنظیمات عناصر روی سایت برند
 * ====================================================================
 * پاسخ به تردید کاربر: «شاید درست کردی ولی رندر نمیشه» — این تست برای هر
 * تنظیم عمومی، هر سه حلقه زنجیره را می‌سنجد:
 *   ① پراپ در خروجی HTML بلوک می‌نشیند (style inline / کلاس)
 *   ② متغیر CSS مربوطه در blocks.css سایت «مصرف» می‌شود (نه فقط تولید)
 *   ③ مقدار ناامن رد می‌شود (تزریق CSS ناممکن)
 *
 * اجرا: php tests/run.php --filter=s09b-element-render
 */

if (!defined('SAHAND_INIT')) { define('SAHAND_INIT', true); }
require_once dirname(__DIR__) . '/includes/helpers.php';

if (!defined('BRAND_INIT')) { define('BRAND_INIT', true); }
if (!defined('BRAND_ID')) { define('BRAND_ID', 7); }
if (!defined('BRAND_NAME_FA')) { define('BRAND_NAME_FA', 'سرویس تست'); }
if (!defined('BRAND_CITY')) { define('BRAND_CITY', 'تبریز'); }

require_once dirname(__DIR__) . '/templates/brand-core/includes/blocks.php';

/** @var TestRunner $T */
$T = $GLOBALS['T'] ?? null;
if (!$T) { echo "⛔ اجرا از tests/run.php\n"; return; }
$T->file('S09b رندر تنظیمات عناصر — اثبات انتها-به-انتها');

/* بلوک آزمایشی با «همه» تنظیمات عمومی فعال */
$props = [
    'title'      => 'بلوک آزمایشی',
    'subtitle'   => 'زیرعنوان',
    'textColor'  => '#dc2626',
    'background' => 'custom',
    'bgColor'    => '#fef2f2',
    'mt'         => '24',
    'mb'         => '32',
    'btnColor'   => '#7c3aed',
    'boxShadow'  => 'strong',
    'borderStyle'=> 'dashed',
    'borderColor'=> '#fca5a5',
    'bgImage'    => 'https://example.com/bg.jpg',
    'iconColor'  => '#059669',
    'iconSize'   => 'xl',
    'radiusOverride' => 'pill',
    'hideMobile' => 1,
    'anchorId'   => 'test-block-1',
];
$html = bb_render_block('features', $props);

/* ═══ ① حلقه اول: پراپ‌ها در HTML ═══ */
$T->section('① نشستن تنظیمات در HTML بلوک');
$T->assert('رنگ متن → --blk-txt:#dc2626', strpos($html, '--blk-txt:#dc2626') !== false);
$T->assert('رنگ زمینه دلخواه → --blk-bg:#fef2f2', strpos($html, '--blk-bg:#fef2f2') !== false);
$T->assert('فاصله بالا → --blk-mt:24px', strpos($html, '--blk-mt:24px') !== false);
$T->assert('فاصله پایین → --blk-mb:32px', strpos($html, '--blk-mb:32px') !== false);
$T->assert('رنگ دکمه → --blk-btn:#7c3aed', strpos($html, '--blk-btn:#7c3aed') !== false);
$T->assert('سایه قوی → --blk-shadow', strpos($html, '--blk-shadow:0 14px 36px') !== false);
$T->assert('بوردر خط‌چین → --blk-bd', strpos($html, '--blk-bd:1.5px dashed #fca5a5') !== false);
$T->assert('تصویر زمینه بخش → --blk-bgi', strpos($html, "--blk-bgi:url('https://example.com/bg.jpg')") !== false);
$T->assert('رنگ آیکون → --blk-ic:#059669', strpos($html, '--blk-ic:#059669') !== false);
$T->assert('اندازه آیکون xl → --blk-ics:1.75', strpos($html, '--blk-ics:1.75') !== false);
$T->assert('گردی کپسولی → کلاس blk-rad-pill', strpos($html, 'blk-rad-pill') !== false);
$T->assert('مخفی در موبایل → کلاس blk-hide-mobile', strpos($html, 'blk-hide-mobile') !== false);
$T->assert('شناسه لنگر تزریق شد (id + data-anchor)', strpos($html, 'id="test-block-1"') !== false && strpos($html, 'data-anchor="1"') !== false);

/* ═══ ② حلقه دوم: مصرف در CSS سایت ═══ */
$T->section('② مصرف متغیرها در blocks.css سایت');
$css = (string)@file_get_contents(dirname(__DIR__) . '/templates/brand-core/css/blocks.css');
$consumers = [
    'var(--blk-txt'  => 'رنگ متن',
    'var(--blk-bg'   => 'رنگ زمینه',
    'var(--blk-mt'   => 'فاصله بالا',
    'var(--blk-mb'   => 'فاصله پایین',
    'var(--blk-btn'  => 'رنگ دکمه',
    'var(--blk-shadow' => 'سایه',
    'var(--blk-bd'   => 'بوردر',
    'var(--blk-bgi'  => 'تصویر زمینه',
    'var(--blk-ic'   => 'رنگ آیکون',
    'var(--blk-ics'  => 'اندازه آیکون',
    '.blk-hide-mobile' => 'مخفی موبایل',
    '.blk-hide-desktop' => 'مخفی دسکتاپ',
    '.blk-rad-pill'  => 'گردی کپسولی',
];
foreach ($consumers as $needle => $label) {
    $T->assert("CSS مصرف‌کننده {$label}", strpos($css, $needle) !== false);
}

/* ═══ ③ حلقه سوم: مقادیر ناامن رد می‌شوند ═══ */
$T->section('③ دفاع امنیتی');
$bad = bb_render_block('features', [
    'title'     => 'تست امنیت',
    'textColor' => 'red;}body{display:none',
    'bgColor'   => 'javascript:alert(1)',
    'bgImage'   => 'javascript:alert(1)',
    'mt'        => '9999',
    'borderColor' => '"onmouseover=alert(1)',
]);
$T->assert('رنگ خراب تزریقی رد شد (بدون --blk-txt)', strpos($bad, '--blk-txt') === false);
$T->assert('bgColor جاوااسکریپتی رد شد', strpos($bad, '--blk-bg:javascript') === false);
$T->assert('bgImage جاوااسکریپتی رد شد', strpos($bad, 'javascript:alert') === false);
$T->assert('فاصله خارج محدوده (۹۹۹۹) رد شد', strpos($bad, '--blk-mt:9999px') === false);
$T->assert('بوردر خراب رد شد', strpos($bad, 'onmouseover') === false);

/* ═══ ④ سازگاری: بلوک بدون هیچ تنظیم عمومی ═══ */
$T->section('④ سازگاری');
$plain = bb_render_block('features', ['title' => 'ساده']);
$T->assert('بلوک ساده بدون متغیر اضافی رندر شد', strpos($plain, 'blk-title') !== false && strpos($plain, '--blk-txt') === false);

echo "\n";
