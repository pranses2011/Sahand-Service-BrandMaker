<?php
/**
 * 👁️ پیش‌نمایش زنده قالب — رندر چیدمان بلوک‌ها با محتوای نمونه
 * ==============================================================
 * درون iframe قالب‌ساز نمایش داده می‌شود؛ GET:
 *   id=    شناسه قالب (اختیاری — از دیتابیس)
 *   json=  چیدمان JSON خام (اختیاری — پیش‌نمایش ذخیره‌نشده)
 *   block= یک بلوک تکی برای پیش‌نمایش (اختیاری)
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$auth = new Auth();
$auth->requireLogin();

/* 📥 چیدمان از یکی از سه منبع */
$layout = [];
$templateId = (int)get_param('id');
$rawJson = (string)get_param('json', '');
$singleBlock = (string)get_param('block', '');

if ($templateId > 0) {
    $tpl = Database::getInstance()->fetch('SELECT layout_json FROM templates WHERE id = ?', [$templateId]);
    $layout = $tpl ? (json_decode($tpl['layout_json'] ?? '[]', true) ?: []) : [];
} elseif ($rawJson !== '') {
    $decoded = json_decode($rawJson, true);
    $layout = is_array($decoded) ? $decoded : [];
} elseif ($singleBlock !== '') {
    $layout = [['block' => $singleBlock, 'props' => [], 'order' => 0]];
}

/* 🏷️ برچسب فارسی بلوک‌ها (هماهنگ با قالب‌ساز) */
$blockNames = [
    'header-v1' => 'هدر ساده', 'header-v2' => 'هدر با نوار تماس', 'header-v3' => 'هدر شیشه‌ای چسبان',
    'top-bar' => 'نوار بالایی', 'hero' => 'هیرو', 'hero-slider' => 'اسلایدر تصویری',
    'hero-split' => 'هیرو دو بخشی', 'hero-video' => 'هیرو با پس‌زمینه تصویر',
    'text' => 'متن آزاد', 'text-image' => 'متن + تصویر', 'two-col' => 'دو ستونه', 'three-col' => 'سه ستونه',
    'intro' => 'معرفی برند', 'services-grid' => 'کارت‌های خدمات', 'articles-recent' => 'مقالات اخیر',
    'features' => 'چرا ما', 'team' => 'کارت تیم', 'contact-form' => 'فرم تماس',
    'request-form' => 'فرم درخواست', 'counter-stats' => 'شمارنده‌ها', 'progress-bars' => 'نوارهای پیشرفت',
    'testimonials' => 'نظرات مشتریان', 'faq-accordion' => 'سوالات متداول', 'gallery' => 'گالری تصاویر',
    'map' => 'نقشه محدوده', 'brands-links' => 'لوگوی برندها', 'cta-phone' => 'CTA تماس',
    'cta-request' => 'CTA ثبت درخواست', 'cta-banner' => 'بنر فراخوان', 'breadcrumb' => 'مسیر راهنما',
    'separator' => 'جداکننده', 'spacer' => 'فاصله', 'pagination' => 'صفحه‌بندی',
    'stats' => 'آمار', 'articles-grid' => 'شبکه مقالات', 'services-list' => 'لیست خدمات',
];

/**
 * 🎨 رندر یک بلوک به HTML وایرفریم با محتوای نمونه فارسی
 */
function renderPreviewBlock(string $block, array $props = []): string
{
    $title = $props['title'] ?? '';
    $bg = $props['background'] ?? 'default';
    $bgClass = 'blk-bg-' . ($bg ?: 'default');
    $pad = $props['padding'] ?? 'default';
    $padClass = 'blk-pad-' . ($pad ?: 'default');
    $hidden = isset($props['visible']) && $props['visible'] === false;
    if ($hidden) {
        return '<div class="blk-hidden">🙈 بخش پنهان: <b>' . e($block) . '</b></div>';
    }
    $head = $title ? '<div class="blk-title">' . e($title) . '</div>' : '';
    $b = e($block);

    switch ($block) {
        case 'top-bar':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' topbar-blk"><span>📞 ۰۲۱-۱۲۳۴۵۶۷۸</span><span>🕐 شنبه تا پنجشنبه ۹ تا ۲۰</span></div>';
        case 'header-v1':
        case 'header-v2':
        case 'header-v3':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' header-blk' . ($block === 'header-v3' ? ' glass' : '') . '"><div class="fake-logo">🏗️</div><nav class="fake-nav"><span>خانه</span><span>خدمات</span><span>مقالات</span><span>تماس</span></nav><div class="fake-cta">ثبت درخواست</div></div>';
        case 'hero':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk"><div class="hero-title">' . ($title ?: 'تعمیرات تخصصی با قطعات اصلی') . '</div><div class="hero-sub">نمایندگی رسمی — پاسخگویی ۷ روز هفته</div><div class="hero-btns"><span class="hero-btn">📞 تماس فوری</span><span class="hero-btn ghost">ثبت درخواست آنلاین</span></div></div>';
        case 'hero-slider':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk slider"><div class="hero-title">اسلایدر تصویری</div><div class="slider-dots">● ○ ○</div></div>';
        case 'hero-split':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-split"><div><div class="hero-title">' . ($title ?: 'هیرو دو بخشی') . '</div><div class="hero-sub">متن معرفی + دکمه فراخوان</div><div class="hero-btns"><span class="hero-btn">شروع کنید</span></div></div><div class="hero-img"></div></div>';
        case 'hero-video':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk video"><div class="hero-title">' . ($title ?: 'هیرو با پس‌زمینه تصویر') . '</div><div class="play">▶</div></div>';
        case 'text':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="fake-lines"><div class="fl w90"></div><div class="fl w100"></div><div class="fl w75"></div></div></div>';
        case 'text-image':
        case 'intro':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' split"><div><div class="blk-title">' . ($title ?: 'درباره برند') . '</div><div class="fake-lines"><div class="fl w100"></div><div class="fl w90"></div><div class="fl w60"></div></div></div><div class="fake-img">🖼️</div></div>';
        case 'two-col':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c2"><div class="fake-card">ستون ۱</div><div class="fake-card">ستون ۲</div></div></div>';
        case 'three-col':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c3"><div class="fake-card">ستون ۱</div><div class="fake-card">ستون ۲</div><div class="fake-card">ستون ۳</div></div></div>';
        case 'services-grid':
        case 'features':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: ($block === 'features' ? 'چرا ما را انتخاب کنید؟' : 'خدمات ما')) . '</div><div class="cols c3">' . str_repeat('<div class="fake-card"><div class="card-ico">🔧</div><div class="card-t">سرویس نمونه</div><div class="fl w80"></div></div>', 3) . '</div></div>';
        case 'articles-recent':
        case 'articles-grid':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'مقالات اخیر') . '</div><div class="cols c3">' . str_repeat('<div class="fake-card"><div class="fake-img small">📰</div><div class="card-t">عنوان مقاله نمونه</div><div class="fl w100"></div><div class="fl w60"></div></div>', 3) . '</div></div>';
        case 'team':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'تیم ما') . '</div><div class="cols c4">' . str_repeat('<div class="fake-card"><div class="fake-ava">👤</div><div class="card-t">عضو تیم</div></div>', 4) . '</div></div>';
        case 'contact-form':
        case 'request-form':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: ($block === 'request-form' ? 'فرم درخواست خدمات' : 'فرم تماس')) . '</div><div class="form-grid"><div class="fake-input">نام و نام خانوادگی</div><div class="fake-input">شماره تماس</div><div class="fake-input">شرح مشکل</div><div class="hero-btn full">ارسال درخواست</div></div></div>';
        case 'counter-stats':
        case 'stats':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' stats-blk"><div class="stat"><div class="stat-n">۱۲+</div><div class="stat-l">سال تجربه</div></div><div class="stat"><div class="stat-n">۵۰هزار+</div><div class="stat-l">تعمیر موفق</div></div><div class="stat"><div class="stat-n">۹۸٪</div><div class="stat-l">رضایت</div></div></div>';
        case 'progress-bars':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="pbar"><span>سرعت تعمیر</span><div class="track"><div class="fill" style="width:90%"></div></div></div><div class="pbar"><span>کیفیت قطعات</span><div class="track"><div class="fill" style="width:95%"></div></div></div></div>';
        case 'testimonials':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'نظرات مشتریان') . '</div><div class="quote">«سرویس سریع و منظم بود؛ راضی بودم.»</div><div class="slider-dots">● ○ ○</div></div>';
        case 'faq-accordion':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'سوالات متداول') . '</div><div class="acc">سوال نمونه اول؟ <b>＋</b></div><div class="acc">سوال نمونه دوم؟ <b>＋</b></div><div class="acc">سوال نمونه سوم؟ <b>＋</b></div></div>';
        case 'gallery':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'گالری') . '</div><div class="cols c4">' . str_repeat('<div class="fake-img small">🖼️</div>', 4) . '</div></div>';
        case 'map':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'محدوده خدمات') . '</div><div class="fake-map">📍 نقشه محدوده خدمات</div></div>';
        case 'brands-links':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'برندهای مورد خدمت') . '</div><div class="cols c6">' . str_repeat('<div class="fake-logo-s">🏷️</div>', 6) . '</div></div>';
        case 'cta-phone':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' cta-blk"><div class="hero-title">همین حالا تماس بگیرید</div><div class="cta-num" dir="ltr">۰۲۱-۱۲۳۴۵۶۷۸</div></div>';
        case 'cta-request':
        case 'cta-banner':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' cta-blk"><div class="hero-title">' . ($title ?: 'درخواست تعمیر خود را ثبت کنید') . '</div><span class="hero-btn">📝 ثبت درخواست</span></div>';
        case 'breadcrumb':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' crumb">خانه / خدمات / <b>صفحه فعلی</b></div>';
        case 'separator':
            return '<hr class="blk-sep">';
        case 'spacer':
            return '<div class="blk-spacer" title="فاصله"></div>';
        case 'pagination':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' pager"><span class="pg cur">۱</span><span class="pg">۲</span><span class="pg">۳</span><span>›</span></div>';
        default:
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">📦 ' . $b . '</div><div class="fake-lines"><div class="fl w90"></div><div class="fl w70"></div></div></div>';
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>پیش‌نمایش قالب</title>
<style>
:root {
    --p: #1e40af; --p-light: #dbeafe; --s: #0ea5e9; --a: #f59e0b;
    --bg: #f8fafc; --card: #fff; --text: #1e293b; --muted: #64748b; --border: #e2e8f0;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Vazirmatn, Tahoma, 'Segoe UI', sans-serif; background: var(--bg); color: var(--text); line-height: 1.9; font-size: 14px; }
.preview-wrap { max-width: 100%; margin: 0 auto; }

/* بلوک‌ها */
.blk { background: var(--card); padding: 26px 20px; border-bottom: 1px dashed var(--border); }
.blk-pad-compact { padding: 14px 16px; }
.blk-pad-roomy { padding: 44px 26px; }
.blk-pad-none { padding: 0; }
.blk-bg-surface { background: #f1f5f9; }
.blk-bg-primary { background: linear-gradient(135deg, var(--p), var(--s)); color: #fff; }
.blk-bg-primary .blk-title, .blk-bg-primary .card-t, .blk-bg-gradient .blk-title { color: #fff; }
.blk-bg-gradient { background: linear-gradient(135deg, var(--p) 0%, var(--s) 60%, var(--a) 100%); color: #fff; }
.blk-bg-dark { background: #0f172a; color: #e2e8f0; }
.blk-bg-dark .blk-title { color: #fff; }
.blk-title { font-size: 16px; font-weight: 800; margin-bottom: 16px; text-align: center; }
.blk-hidden { text-align: center; padding: 14px; color: var(--muted); font-size: 12px; background: repeating-linear-gradient(45deg, #f8fafc, #f8fafc 10px, #f1f5f9 10px, #f1f5f9 20px); }

/* هدر */
.header-blk { display: flex; align-items: center; gap: 14px; padding: 14px 18px; position: sticky; top: 0; }
.header-blk.glass { background: rgba(255,255,255,.82); backdrop-filter: blur(9px); }
.fake-logo { font-size: 22px; }
.fake-nav { display: flex; gap: 16px; font-size: 13px; color: var(--muted); flex: 1; flex-wrap: wrap; }
.fake-cta { background: var(--p); color: #fff; font-size: 12px; padding: 7px 15px; border-radius: 9px; white-space: nowrap; }
.topbar-blk { display: flex; justify-content: space-between; font-size: 11.5px; color: var(--muted); padding: 7px 16px; background: #f1f5f9; flex-wrap: wrap; gap: 6px; }

/* هیرو */
.hero-blk { background: linear-gradient(135deg, var(--p), var(--s)); color: #fff; text-align: center; padding: 52px 22px; }
.hero-title { font-size: 21px; font-weight: 800; margin-bottom: 8px; }
.hero-sub { font-size: 13px; opacity: .88; margin-bottom: 18px; }
.hero-btns { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
.hero-btn { background: var(--a); border-radius: 10px; padding: 9px 22px; font-size: 13px; font-weight: 700; display: inline-block; }
.hero-btn.ghost { background: transparent; border: 1.5px solid rgba(255,255,255,.65); }
.hero-btn.full { width: 100%; text-align: center; }
.hero-split { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
.hero-split > div:first-child { flex: 1 1 240px; text-align: right; }
.hero-img { flex: 1 1 200px; height: 130px; background: rgba(255,255,255,.14); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 34px; }
.hero-blk.slider { position: relative; }
.slider-dots { letter-spacing: 5px; font-size: 11px; opacity: .8; }
.hero-blk.video .play { width: 54px; height: 54px; border-radius: 50%; background: rgba(255,255,255,.2); display: flex; align-items: center; justify-content: center; font-size: 20px; margin: 12px auto; }

/* متن و کارت */
.fake-lines .fl { height: 10px; border-radius: 5px; background: #e2e8f0; margin: 8px 0; }
.w40 { width: 40%; } .w60 { width: 60%; } .w70 { width: 70%; } .w75 { width: 75%; } .w80 { width: 80%; } .w90 { width: 90%; } .w100 { width: 100%; }
.split { display: flex; gap: 22px; align-items: center; flex-wrap: wrap; }
.split > div:first-child { flex: 1 1 260px; }
.fake-img { flex: 1 1 180px; height: 150px; background: var(--p-light); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 36px; }
.fake-img.small { height: 90px; font-size: 26px; width: 100%; flex: none; }
.cols { display: grid; gap: 14px; }
.c2 { grid-template-columns: repeat(2, 1fr); }
.c3 { grid-template-columns: repeat(3, 1fr); }
.c4 { grid-template-columns: repeat(4, 1fr); }
.c6 { grid-template-columns: repeat(6, 1fr); }
.fake-card { background: var(--card); border: 1px solid var(--border); border-radius: 13px; padding: 16px 13px; text-align: center; min-width: 0; }
.blk-bg-primary .fake-card, .blk-bg-dark .fake-card, .blk-bg-gradient .fake-card { background: rgba(255,255,255,.1); border-color: rgba(255,255,255,.22); }
.card-ico { font-size: 25px; margin-bottom: 8px; }
.card-t { font-size: 13px; font-weight: 700; margin-bottom: 6px; }
.fake-card .fl { margin: 7px auto 0; }
.fake-ava { font-size: 30px; }

/* فرم و آمار */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; max-width: 640px; margin: 0 auto; }
.fake-input { background: #f8fafc; border: 1.5px solid var(--border); border-radius: 9px; padding: 10px 13px; font-size: 12px; color: var(--muted); }
.stats-blk { display: flex; justify-content: space-around; flex-wrap: wrap; gap: 18px; background: linear-gradient(135deg, #0f172a, #1e3a8a); color: #fff; }
.stat { text-align: center; }
.stat-n { font-size: 26px; font-weight: 800; color: #93c5fd; }
.stat-l { font-size: 12px; opacity: .85; }
.pbar { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; font-size: 12.5px; }
.pbar span { flex: 0 0 110px; }
.track { flex: 1; height: 9px; background: #e2e8f0; border-radius: 9px; overflow: hidden; }
.fill { height: 100%; background: linear-gradient(90deg, var(--p), var(--s)); border-radius: 9px; }

/* متفرقه */
.quote { background: var(--card); border: 1px solid var(--border); border-inline-start: 4px solid var(--p); border-radius: 11px; padding: 17px 19px; font-size: 13px; max-width: 560px; margin: 0 auto 10px; }
.acc { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 12px 16px; margin-bottom: 9px; font-size: 13px; display: flex; justify-content: space-between; align-items: center; max-width: 640px; margin-inline: auto; }
.fake-map { height: 170px; background: repeating-linear-gradient(45deg, #eef2ff, #eef2ff 12px, #e0e7ff 12px, #e0e7ff 24px); border-radius: 13px; display: flex; align-items: center; justify-content: center; color: var(--p); font-weight: 700; }
.fake-logo-s { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 13px; font-size: 21px; text-align: center; }
.cta-blk { background: linear-gradient(135deg, var(--p), var(--s)); color: #fff; text-align: center; }
.cta-num { font-size: 25px; font-weight: 800; margin-top: 6px; letter-spacing: 1px; }
.crumb { font-size: 12px; color: var(--muted); padding: 11px 18px; background: #f8fafc; }
.blk-sep { border: none; border-top: 1px solid var(--border); margin: 6px 0; }
.blk-spacer { height: 46px; background: repeating-linear-gradient(45deg, #f8fafc, #f8fafc 10px, #f1f5f9 10px, #f1f5f9 20px); }
.pager { text-align: center; }
.pg { display: inline-block; min-width: 32px; padding: 5px 0; border: 1px solid var(--border); border-radius: 8px; margin: 0 3px; font-size: 12.5px; }
.pg.cur { background: var(--p); color: #fff; border-color: var(--p); }

@media (max-width: 640px) {
    .c2, .c3, .c4, .c6, .form-grid { grid-template-columns: 1fr 1fr; }
    .c6 { grid-template-columns: repeat(3, 1fr); }
    .hero-title { font-size: 17px; }
}
@media (max-width: 420px) {
    .c2, .c3, .c4, .c6, .form-grid { grid-template-columns: 1fr; }
    .c6 { grid-template-columns: repeat(2, 1fr); }
    .fake-nav { display: none; }
}
</style>
</head>
<body>
<div class="preview-wrap">
    <?php if (empty($layout)): ?>
        <div class="blk" style="text-align:center;color:var(--muted)">چیدمانی برای پیش‌نمایش وجود ندارد.</div>
    <?php else: ?>
        <?php foreach ($layout as $item): ?>
            <?= renderPreviewBlock((string)($item['block'] ?? ''), (array)($item['props'] ?? [])) ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>
