<?php
/**
 * 👁️ پیش‌نمایش زنده قالب — رندر چیدمان بلوک‌ها با محتوای نمونه (v3.0)
 * =====================================================================
 * درون iframe قالب‌ساز نمایش داده می‌شود؛ GET:
 *   id=    شناسه قالب (اختیاری — از دیتابیس)
 *   json=  چیدمان JSON خام (اختیاری — پیش‌نمایش ذخیره‌نشده)
 *   block= یک بلوک تکی برای پیش‌نمایش (اختیاری)
 *
 * 🆕 v3.0: پشتیبانی «بخش چندستونی» — چیدمان تودرتو (cols) + ۵۵+ بلوک
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

/* 🆕 v2.25: تنظیمات صفحه — گره مخفی «_page» جدا و به‌صورت متغیرهای CSS
   روی body اعمال می‌شود (زمینه/فاصله‌ها/عرض/گردی — همان بوم قالب‌ساز) */
$pageProps = [];
if (!empty($layout) && is_array($layout[0]) && ($layout[0]['block'] ?? '') === '_page') {
    $pageProps = is_array($layout[0]['props'] ?? null) ? $layout[0]['props'] : [];
    array_shift($layout);
}
$pageCssVars = static function (array $p): string {
    $v = [];
    $bg = (string)($p['pageBg'] ?? 'default');
    $bgCss = '';
    if ($bg === 'surface') { $bgCss = '#f1f5f9'; }
    elseif ($bg === 'light') { $bgCss = '#fafafa'; }
    elseif ($bg === 'dark') { $bgCss = '#0f172a'; }
    elseif ($bg === 'custom' && preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($p['pageBgColor'] ?? ''))) { $bgCss = (string)$p['pageBgColor']; }
    if ($bgCss !== '') { $v['--pg-bg'] = $bgCss; }
    $map = [
        'sectionSpacing' => ['compact' => '30px', 'default' => '54px', 'roomy' => '74px', 'airy' => '96px', 'var' => '--pg-section-pad'],
        'sectionGap' => ['tight' => '14px', 'default' => '26px', 'roomy' => '44px', 'var' => '--pg-gap'],
        'containerWidth' => ['narrow' => '860px', 'default' => '1080px', 'wide' => '1240px', 'full' => '100%', 'var' => '--pg-width'],
        'radius' => ['sharp' => '2px', 'default' => '14px', 'round' => '22px', 'pill' => '34px', 'var' => '--pg-radius'],
        'textSize' => ['sm' => '13px', 'default' => '14.5px', 'lg' => '16px', 'var' => '--pg-text'],
    ];
    foreach ($map as $key => $cfg) {
        $val = (string)($p[$key] ?? 'default');
        if ($val !== '' && $val !== 'default' && isset($cfg[$val])) { $v[$cfg['var']] = $cfg[$val]; }
    }
    if (!empty($p['titleColor'])) { $v['--pg-title'] = (string)$p['titleColor']; }
    $out = '';
    foreach ($v as $k => $val) { $out .= $k . ':' . $val . ';'; }
    return $out;
};
$pageStyle = $pageCssVars($pageProps);

/**
 * 🎨 رندر یک بلوک به HTML واقعی با محتوای نمونه فارسی (v3.0 — ۵۵+ بلوک)
 */

/* ➕ v2.15: نرمال‌سازی آیتم‌های لیست — همیشه [{icon,text,desc}] */
function pvItems(array $props, array $fallback): array
{
    $norm = static function ($arr): array {
        $out = [];
        foreach ((array)$arr as $it) {
            if (is_array($it)) {
                $out[] = [
                    'icon' => is_int(array_key_first($it)) ? (string)($it[0] ?? '') : (string)($it['icon'] ?? ''),
                    'text' => is_int(array_key_first($it)) ? (string)($it[1] ?? '') : (string)($it['text'] ?? ''),
                    'desc' => is_int(array_key_first($it)) ? (string)($it[2] ?? '') : (string)($it['desc'] ?? ''),
                ];
            }
        }
        return $out;
    };
    $items = $norm(array_values(array_filter((array)($props['items'] ?? []), static fn($i) => is_array($i) && trim((string)($i['text'] ?? '')) !== '')));
    return $items ?: $norm($fallback);
}

/* 🗂 v2.17: تعداد ستون کارت‌ها از props (۲-۶) */
function pvCols(array $props, int $default = 3): int
{
    $c = (int)($props['columns'] ?? $default);
    return max(2, min(6, $c ?: $default));
}

/* 📊 v2.17: نوار آمار از آیتم‌ها (icon=عدد، text=برچسب) */
function pvStatStrip(array $props): string
{
    $items = pvItems($props, [['۱۲+', 'سال تجربه'], ['۵۰k', 'تعمیر'], ['۹۸٪', 'رضایت']]);
    $out = [];
    foreach (array_slice($items, 0, 8) as $i) {
        $out[] = '<span class="ss-item"><b>' . e($i['icon'] !== '' ? $i['icon'] : '۰') . '</b> ' . e($i['text'] ?: 'آمار') . '</span>';
    }
    return implode('<span class="ss-sep"></span>', $out);
}

/* ⏱ v2.15: شمارش معکوس ایستا از زمان هدف (پیش‌نمایش — بوم JS زنده است) */
function pvCountdown(array $props): string
{
    $target = trim((string)($props['countdownTo'] ?? ''));
    $days = '۰۲'; $hrs = '۱۴'; $min = '۳۰'; $sec = '۰۰';
    if ($target !== '') {
        $ts = strtotime($target);
        if ($ts !== false && $ts > time()) {
            $diff = $ts - time();
            $fa = static fn($n) => strtr(str_pad((string)$n, 2, '0', STR_PAD_LEFT), ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
            $days = $fa(floor($diff / 86400));
            $hrs = $fa(floor(($diff % 86400) / 3600));
            $min = $fa(floor(($diff % 3600) / 60));
            $sec = $fa($diff % 60);
        }
    }
    return '<div class="count-row"><span class="count-box"><b>' . $days . '</b>روز</span><span class="count-box"><b>' . $hrs . '</b>ساعت</span><span class="count-box"><b>' . $min . '</b>دقیقه</span><span class="count-box"><b>' . $sec . '</b>ثانیه</span></div>';
}

/* 🎨 v2.15: استایل درون‌خطی — رنگ عنوان / رنگ گرادیانت انتخابی */
function pvStyleVars(array $props): string
{
    $s = '';
    $tc = trim((string)($props['titleColor'] ?? ''));
    if ($tc !== '') { $s .= '--blk-tc:' . e($tc) . ';'; }
    if (($props['background'] ?? '') === 'gradient') {
        $gf = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($props['gradientFrom'] ?? '')) ? (string)$props['gradientFrom'] : '';
        $gt = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($props['gradientTo'] ?? '')) ? (string)$props['gradientTo'] : '';
        if ($gf !== '' || $gt !== '') {
            $s .= 'background:linear-gradient(135deg,' . ($gf ?: '#1e40af') . ',' . ($gt ?: '#0ea5e9') . ');';
        }
    }
    return $s;
}

/* 🖼 v2.15: تصویر واقعی به‌جای ایموجی قالبی */
function pvImg(array $props, string $emoji, string $style = ''): string
{
    $url = trim((string)($props['imageUrl'] ?? ''));
    if (preg_match('#^(https?://|/|uploads/)#i', $url)) {
        return '<div class="fake-img" style="' . $style . '"><img src="' . e($url) . '" alt="" style="width:100%;height:100%;object-fit:cover;display:block"></div>';
    }
    return '<div class="fake-img" style="' . $style . '">' . $emoji . '</div>';
}

/**
 * 🎛 v2.17: رندر عمومی با پس‌پردازش — تنظیمات پیشرفته (اندازه/تراز/عرض/کلاس/رنگ/گرادیانت)
 * روی خروجی «همه» بلوک‌ها تزریق می‌شود؛ بلوک‌هایی که خودشان اعمال کرده‌اند دچار تکرار نمی‌شوند.
 */
/* 🖼 v2.25: تصویر آیتم گالری — text=آدرس تصویر، icon=ایموجی جایگزین */
function pvItemImg(array $it, string $style = ''): string
{
    $url = trim((string)($it['text'] ?? ''));
    if (preg_match('#^(https?://|/|uploads/)#i', $url)) {
        return '<div class="fake-img small" style="' . ($style !== '' ? $style : 'min-height:90px') . '"><img src="' . e($url) . '" alt="" style="width:100%;height:100%;object-fit:cover;display:block"></div>';
    }
    return '<div class="fake-img small" style="' . ($style !== '' ? $style : 'min-height:90px') . '">' . e($it['icon'] ?: '🖼️') . '</div>';
}

function renderPreviewBlock(string $block, array $props = []): string
{
    $html = renderPreviewBlockInner($block, $props);

    /* بلوک‌های ساختاری بدون .blk رندر می‌شوند — تزریقی ندارند */
    if (!preg_match('#^<div class="blk #', $html)) {
        return $html;
    }
    /* کلاس‌های تنظیمات پیشرفته — فقط مواردی که هنوز در کلاس اول نیستند */
    $sizeCls  = 'blk-ts-' . (($props['titleSize'] ?? 'md') ?: 'md');
    $alignCls = isset($props['align']) && $props['align'] !== 'start' && $props['align'] !== '' ? 'blk-al-' . $props['align'] : '';
    $widthCls = isset($props['width']) && $props['width'] !== 'full' && $props['width'] !== '' ? 'blk-w-' . $props['width'] : '';
    $custom   = preg_replace('/[^a-zA-Z0-9\-_\s]/', '', (string)($props['customClass'] ?? ''));
    /* 🆕 فاصله/پس‌زمینه — بلوک‌هایی که در رندر داخلی اعمال نکرده‌اند */
    $padCls   = 'blk-pad-' . (($props['padding'] ?? 'default') ?: 'default');
    $bgCls    = 'blk-bg-' . (($props['background'] ?? 'default') ?: 'default');
    $inject = [];
    foreach (array_filter([$sizeCls, $alignCls, $widthCls, $custom, $padCls, $bgCls]) as $cls) {
        if (strpos($html, $cls) === false) {
            $inject[] = $cls;
        }
    }
    if ($inject) {
        $html = preg_replace('#^<div class="blk #', '<div class="blk ' . implode(' ', $inject) . ' ', $html, 1);
    }
    /* استایل رنگ عنوان/گرادیانت — اگر تنظیم وجود دارد و هنوز اعمال نشده
       (div اول style دارد → ادغام؛ ندارد → افزودن) */
    $vars = pvStyleVars($props);
    if ($vars !== '' && preg_match('#--blk-title-color|--blk-grad#', $html) === 0) {
        if (preg_match('#^(<div class="blk [^>]*?)style="([^"]*)"#', $html, $sm)) {
            $html = preg_replace('#^(<div class="blk [^>]*?)style="[^"]*"#', '$1style="' . $sm[2] . ';' . $vars . '"', $html, 1);
        } else {
            $html = preg_replace('#^<div class="blk ([^>]*)>#', '<div class="blk $1" style="' . $vars . '">', $html, 1);
        }
    }
    return $html;
}

function renderPreviewBlockInner(string $block, array $props = []): string
{
    if ($block === '_page') { return ''; } /* 🛡️ گره تنظیمات صفحه */
    $title = $props['title'] ?? '';
    $bg = $props['background'] ?? 'default';
    $bgClass = 'blk-bg-' . ($bg ?: 'default');
    $pad = $props['padding'] ?? 'default';
    $padClass = 'blk-pad-' . ($pad ?: 'default');
    $hidden = isset($props['visible']) && $props['visible'] === false;
    if ($hidden) {
        return '<div class="blk-hidden">🙈 بخش پنهان: <b>' . e($block) . '</b></div>';
    }
    /* 🎛 v3.3: تنظیمات پیشرفته — اندازه عنوان / تراز / عرض محتوا / کلاس سفارشی */
    $sizeCls = 'blk-ts-' . ($props['titleSize'] ?? 'md');
    $alignCls = isset($props['align']) && $props['align'] !== 'start' && $props['align'] !== '' ? 'blk-al-' . $props['align'] : '';
    $widthCls = isset($props['width']) && $props['width'] !== 'full' && $props['width'] !== '' ? 'blk-w-' . $props['width'] : '';
    $customCls = preg_replace('/[^a-zA-Z0-9\-_\s]/', '', (string)($props['customClass'] ?? ''));
    $extraCls = $sizeCls . ' ' . $alignCls . ' ' . $widthCls . ' ' . $customCls;
    $head = $title ? '<div class="blk-title">' . e($title) . '</div>' : '';
    /* 🎨 v2.15: رنگ عنوان + رنگ گرادیانت انتخابی */
    $blkStyle = pvStyleVars($props);
    $styleAttr = $blkStyle !== '' ? ' style="' . $blkStyle . '"' : '';
    /* 🆕 v2.17: wrapper استاندارد PV — مثل B() رندرر JS؛ همه تنظیمات پیشرفته
       (رنگ/اندازه/تراز/عرض/کلاس/پس‌زمینه/فاصله) روی همه بلوک‌ها اعمال می‌شود */
    $PV = static function (string $inner, string $extra = '') use ($bgClass, $padClass, $extraCls, $styleAttr): string {
        return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . ($extra !== '' ? ' ' . $extra : '') . '"' . $styleAttr . '>' . $inner . '</div>';
    };

    switch ($block) {
        case 'top-bar':
            return $PV('<div class="tb-row"><span>📞 ' . e($props['phone'] ?? '۰۲۱-۱۲۳۴۵۶۷۸') . '</span><span>🕐 ' . e($props['hours'] ?? 'شنبه تا پنجشنبه ۹ تا ۲۰') . '</span></div>', 'topbar-blk');
        case 'header-v1':
        case 'header-v2':
        case 'header-v3':
            $menu = pvItems($props, [['', 'خانه', ''], ['', 'خدمات', ''], ['', 'مقالات', ''], ['', 'تماس', '']]);
            $menuHtml = '';
            foreach ($menu as $m) { $menuHtml .= '<span>' . e($m['text']) . '</span>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' header-blk' . ($block === 'header-v3' ? ' glass' : '') . (!empty($props['sticky']) ? ' sticky-demo' : '') . '">' . ($block === 'header-v2' ? '<div class="tb-row"><span>📞 ۰۲۱-۱۲۳۴۵۶۷۸</span><span>💬 پاسخگویی آنلاین</span></div>' : '') . '<div class="h-row"><div class="fake-logo">🏗️</div><nav class="fake-nav">' . $menuHtml . '</nav><div class="fake-cta">' . e($props['btnText'] ?? 'ثبت درخواست') . '</div></div></div>';
        case 'hero':
            return $PV('<div class="hero-title">' . ($title ?: 'تعمیرات تخصصی با قطعات اصلی') . '</div><div class="hero-sub">' . e($props['subtitle'] ?? 'نمایندگی رسمی — پاسخگویی ۷ روز هفته') . '</div><div class="hero-btns"><span class="hero-btn">📞 تماس فوری</span><span class="hero-btn ghost">ثبت درخواست آنلاین</span></div>', 'hero-blk');
        case 'hero-slider':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk slider"><div class="hero-title">' . ($title ?: 'اسلایدر تصویری') . '</div><div class="hero-img wide">🖼️</div><div class="slider-dots">● ○ ○</div></div>';
        case 'hero-split':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk split-hero"' . $styleAttr . '><div class="hero-split"><div><div class="hero-title">' . ($title ?: 'تعمیر لوازم خانگی در محل') . '</div><div class="hero-sub">' . e($props['subtitle'] ?? 'متن معرفی + دکمه فراخوان') . '</div><div class="hero-btns"><span class="hero-btn">شروع کنید</span></div></div>' . pvImg($props, '🛠️') . '</div></div>';
        case 'hero-video':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk video"><div class="hero-title">' . ($title ?: 'هیرو با پس‌زمینه تصویر') . '</div><div class="play">▶</div></div>';
        case 'hero-countdown':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk"' . $styleAttr . '><div class="hero-title">' . ($title ?: 'کمپین سرویس دوره‌ای') . '</div>' . pvCountdown($props) . '</div>';
        case 'text':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="pv-text">' . nl2br(e($props['text'] ?? 'متن نمونه — این بخش در سایت به همین شکل نمایش داده می‌شود.')) . '</div></div>';
        case 'text-image':
        case 'intro':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' split"' . $styleAttr . '><div><div class="blk-title">' . ($title ?: 'درباره برند') . '</div><div class="pv-text" style="font-size:12.5px">' . nl2br(e($props['text'] ?? 'معرفی کوتاه برند و خدمات تخصصی — این متن از پنل ویژگی‌ها قابل ویرایش است.')) . '</div></div>' . pvImg($props, '🖼️') . '</div>';
        case 'rich-text': {
            /* 🎛 v2.14: متن واردشده خط‌به‌خط آیتم لیست می‌شود */
            $lines = array_values(array_filter(array_map('trim', explode("\n", (string)($props['text'] ?? ''))), static fn($l) => $l !== ''));
            $items = $lines ?: ['نصب و راه‌اندازی تخصصی', 'تعمیر با قطعات اصلی', '۶ ماه ضمانت قطعه و خدمات'];
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<ul class="pv-list">' . implode('', array_map(static fn($i) => '<li>✅ ' . e($i) . '</li>', $items)) . '</ul></div>';
        }
        case 'quote':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' quote-blk"><div class="quote">«' . e($props['text'] ?? 'کیفیت تعمیر، اعتبار ماست') . '»</div></div>';
        case 'two-col':
            $its = pvItems($props, [['', 'ستون اول', 'توضیح کوتاه ستون اول'], ['', 'ستون دوم', 'توضیح کوتاه ستون دوم']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c2">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>', array_slice($its, 0, 2))) . '</div></div>';
        case 'three-col':
            $its = pvItems($props, [['', 'موضوع اول', 'توضیح'], ['', 'موضوع دوم', 'توضیح'], ['', 'موضوع سوم', 'توضیح']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c3">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>', array_slice($its, 0, 3))) . '</div></div>';
        case 'section-columns':
        case 'section-split':
            /* 🏛 خود بخش چندستونی — فقط قاب/عنوان؛ ستون‌ها توسط renderLayoutLevel رندر می‌شوند */
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . ($head ?: '<div class="blk-title" style="opacity:.55;margin-bottom:0">🏛 بخش چندستونی</div>') . '</div>';
        case 'feature-list':
            $its = pvItems($props, [['⚡', 'سرعت عمل', 'اعزام تکنسین در کمتر از ۲ ساعت'], ['🛡️', 'ضمانت کتبی', '۶ ماه ضمانت قطعه و خدمات']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="feat-list">' . implode('', array_map(static fn($it) => '<div class="feat-row"><span class="feat-ico">' . e($it['icon'] ?: '⚡') . '</span><div><b>' . e($it['text']) . '</b>' . ($it['desc'] !== '' ? '<div class="feat-d">' . e($it['desc']) . '</div>' : '') . '</div></div>', $its)) . '</div></div>';
        case 'services-grid':
        case 'features':
            $its = pvItems($props, [['🔧', 'تعمیر لباسشویی', 'با قطعات فابریک'], ['🧊', 'تعمیر یخچال', 'همان روز'], ['⚡', 'تعمیر ماکروویو', 'ضمانت‌دار'], ['🎓', 'سرویس دوره‌ای', 'در محل شما']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: ($block === 'features' ? 'چرا ما را انتخاب کنید؟' : 'خدمات ما')) . '</div><div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="card-ico">' . e($it['icon'] ?: '🔧') . '</div><div class="card-t">' . e($it['text']) . '</div>' . ($it['desc'] !== '' ? '<div class="feat-d">' . e($it['desc']) . '</div>' : '') . '</div>', $its)) . '</div></div>';
        case 'features':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: ($block === 'features' ? 'چرا ما را انتخاب کنید؟' : 'خدمات ما')) . '</div><div class="cols c3">' . str_repeat('<div class="fake-card"><div class="card-ico">🔧</div><div class="card-t">سرویس نمونه</div><div class="fl w80"></div></div>', 3) . '</div></div>';
        case 'devices-grid':
            $devs = ['🌀 لباسشویی', '🧊 یخچال', '🍽️ ظرفشویی', '❄️ کولر', '📺 تلویزیون', '♨️ پکیج', '📻 مایکروویو', '🔥 فر و اجاق'];
            $cards = '';
            foreach ($devs as $d) {
                [$ico, $name] = explode(' ', $d, 2);
                $cards .= '<div class="fake-card"><div class="card-ico">' . $ico . '</div><div class="card-t">' . $name . '</div></div>';
            }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'دستگاه‌های تحت پوشش') . '</div><div class="cols c4">' . $cards . '</div></div>';
        case 'articles-recent':
        case 'articles-grid':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'مقالات اخیر') . '</div><div class="cols c3">' . str_repeat('<div class="fake-card"><div class="fake-img small">📰</div><div class="card-t">عنوان مقاله نمونه</div><div class="fl w100"></div></div>', 3) . '</div></div>';
        case 'team':
            $its = pvItems($props, [['👨‍🔧', 'مهندس کریمی', 'متخصص لباسشویی'], ['👩‍🔧', 'مهندس رضایی', 'متخصص یخچال و فریزر'], ['🧑‍🔧', 'مهندس موسوی', 'متخصص تلویزیون']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'تیم ما') . '</div><div class="cols c' . pvCols($props, 4) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="fake-ava">' . e($it['icon'] ?: '👨‍🔧') . '</div><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
        case 'pricing-table':
            $its = pvItems($props, [['', 'دریافت و عیب‌یابی تخصصی', 'رایگان'], ['', 'سرویس دوره‌ای لباسشویی', 'از ۴۵۰ هزار تومان'], ['', 'شارژ گاز کولر گازی', 'از ۹۰۰ هزار تومان']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'تعرفه خدمات') . '</div><div class="price-table">' . implode('', array_map(static fn($it) => '<div class="price-row"><span>' . e($it['text']) . '</span><b>' . e($it['desc']) . '</b></div>', $its)) . '</div></div>';
        case 'brands-links':
            $its = pvItems($props, [['🏷️', 'ال‌جی'], ['🏷️', 'سامسونگ'], ['🏷️', 'بوش'], ['🏷️', 'سونی'], ['🏷️', 'اسنوا'], ['🏷️', 'پاکس']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'برندهای مورد خدمت') . '</div><div class="cols c' . max(3, pvCols($props, 6)) . '">' . implode('', array_map(static fn($it) => '<div class="fake-logo-s" title="' . e($it['text']) . '">' . e($it['icon'] ?: '🏷️') . '</div>', $its)) . '</div></div>';
        case 'contact-form':
        case 'request-form':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: ($block === 'request-form' ? 'فرم درخواست خدمات' : 'فرم تماس')) . '</div><div class="form-grid"><div class="fake-input">نام و نام خانوادگی</div><div class="fake-input">شماره تماس</div><div class="fake-input">شرح مشکل</div><div class="hero-btn full">' . e($props['btnText'] ?? 'ارسال درخواست') . '</div></div></div>';
        case 'newsletter-form':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'عضویت در خبرنامه') . '</div><div class="news-row"><div class="fake-input" style="flex:1">ایمیل شما</div><div class="hero-btn">' . e($props['btnText'] ?? 'عضویت') . '</div></div></div>';
        case 'counter-stats':
        case 'stats':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' stats-blk"><div class="stat"><div class="stat-n">۱۲+</div><div class="stat-l">سال تجربه</div></div><div class="stat"><div class="stat-n">۵۰هزار+</div><div class="stat-l">تعمیر موفق</div></div><div class="stat"><div class="stat-n">۹۸٪</div><div class="stat-l">رضایت</div></div></div>';
        case 'progress-bars':
            $its = pvItems($props, [['', 'سرعت تعمیر', '90'], ['', 'کیفیت قطعات', '95'], ['', 'رضایت مشتری', '98']]);
            $out = '';
            foreach ($its as $it) { $p = max(3, min(100, (int)(preg_replace('/[^0-9]/', '', $it['desc'] ?: $it['icon']) ?: 80))); $out .= '<div class="pbar"><span>' . e($it['text']) . '</span><div class="track"><div class="fill" style="width:' . $p . '%"></div></div></div>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . $out . '</div>';
        case 'skill-bars':
            $its = pvItems($props, [['', 'تعمیر برد و الکترونیک', '88'], ['', 'یخچال و فریزر', '92'], ['', 'ماشین لباس', '95']]);
            $out = '';
            foreach ($its as $it) { $p = max(3, min(100, (int)(preg_replace('/[^0-9]/', '', $it['desc'] ?: $it['icon']) ?: 80))); $out .= '<div class="pbar"><span>' . e($it['text']) . '</span><div class="track"><div class="fill" style="width:' . $p . '%"></div></div></div>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . $out . '</div>';
        case 'testimonials':
            $its = pvItems($props, [['علی محمدی', 'سرویس سریع و منظم بود؛ راضی بودم.'], ['مریم احمدی', 'قیمت شفاف و ضمانت واقعی.']]);
            $q = $its[0] ?? ['icon' => '', 'text' => ''];
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'نظرات مشتریان') . '</div><div class="quote">«' . e($q['text']) . '»</div>' . ($q['icon'] !== '' ? '<div class="feat-d" style="text-align:center;font-weight:800">— ' . e($q['icon']) . '</div>' : '') . '<div class="slider-dots">● ○ ○</div></div>';
        case 'faq-accordion':
            $its = pvItems($props, [['', 'هزینه عیب‌یابی چقدر است؟', 'در صورت تعمیر نزد ما رایگان است.'], ['', 'چقدر طول می‌کشد؟', 'اکثر تعمیرها همان روز انجام می‌شود.'], ['', 'ضمانت دارید؟', 'بله — ۶ ماه ضمانت کتبی.']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'سوالات متداول') . '</div>' . implode('', array_map(static fn($it) => '<div class="acc">' . e($it['text']) . ' <b>＋</b></div>', $its)) . '</div>';
        case 'tabs':
            $its = pvItems($props, [['', 'تعمیر'], ['', 'سرویس'], ['', 'نصب']]);
            $tabs = '';
            foreach ($its as $i => $it) { $tabs .= '<span class="tab' . ($i === 0 ? ' cur' : '') . '">' . e($it['text']) . '</span>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'تب‌بندی محتوا') . '</div><div class="tabs-row">' . $tabs . '</div><div class="fake-card" style="text-align:right"><div class="fl w100"></div><div class="fl w90"></div><div class="fl w60"></div></div></div>';
        case 'timeline':
            $its = pvItems($props, [['✓', 'ثبت درخواست', 'انجام شد'], ['✓', 'عیب‌یابی و پیش‌فاکتور', 'انجام شد'], ['۳', 'تعمیر در حال انجام', 'در جریان'], ['۴', 'تحویل و ضمانت', 'در انتظار']]);
            $out = '';
            foreach ($its as $i => $it) { $cls = $i < 2 ? ' done' : ($i === 2 ? ' cur' : ''); $out .= '<div class="tl-item' . $cls . '"><span class="tl-dot">' . e($it['icon'] ?: (string)($i + 1)) . '</span><div>' . e($it['text']) . ($it['desc'] !== '' ? '<div class="feat-d">' . e($it['desc']) . '</div>' : '') . '</div></div>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'مراحل پیشرفت کار') . '</div><div class="tl">' . $out . '</div></div>';
        case 'steps-process':
            $its = pvItems($props, [['', 'تماس/ثبت درخواست'], ['', 'اعزام تکنسین'], ['', 'تعمیر و تست']]);
            $out = '';
            foreach ($its as $i => $it) { $out .= ($i > 0 ? '<div class="step-arrow">←</div>' : '') . '<div class="step"><span class="step-n">' . strtr((string)($i + 1), ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']) . '</span><div class="step-t">' . e($it['text']) . '</div></div>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'فرآیند کار ما') . '</div><div class="steps-row">' . $out . '</div></div>';
        case 'gallery':
            $gcols = pvCols($props, 4);
            $its = array_values(array_filter(pvItems($props, []), static fn($i) => trim($i['text']) !== ''));
            $gimgs = '';
            if ($its) { foreach (array_slice($its, 0, $gcols + 3) as $it) { $gimgs .= pvItemImg($it); } }
            else { $gimgs = str_repeat(pvImg($props, '🖼️', 'min-height:90px'), $gcols + 2); }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'گالری') . '</div><div class="cols c' . $gcols . '">' . $gimgs . '</div></div>';
        case 'image-carousel':
            $its = array_values(array_filter(pvItems($props, []), static fn($i) => trim($i['text']) !== ''));
            $first = $its[0] ?? null;
            $dots = $its ? implode(' ', array_map(static fn($i) => '○', $its)) : '● ○ ○';
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'کاروسل تصاویر') . '</div><div style="position:relative">' . ($first ? pvItemImg($first, 'min-height:170px') : pvImg($props, '🎠', 'min-height:170px')) . '<span style="position:absolute;top:50%;inset-inline-start:8px;font-size:22px;text-shadow:0 1px 4px #fff">‹</span><span style="position:absolute;top:50%;inset-inline-end:8px;font-size:22px;text-shadow:0 1px 4px #fff">›</span></div><div class="slider-dots">' . ($its ? '● ' . $dots : '● ○ ○') . '</div></div>';
        case 'video-embed':
            $vu = trim((string)($props['videoUrl'] ?? ''));
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'ویدیوی آموزشی') . '</div><div style="position:relative">' . pvImg($props, '🎬', 'min-height:190px') . '<div class="play">▶</div>' . ($vu !== '' ? '<a href="' . e($vu) . '" target="_blank" rel="noopener" style="position:absolute;bottom:8px;inset-inline-start:8px;background:rgba(15,23,42,.82);color:#fff;border-radius:9px;padding:5px 12px;font-size:10.5px;text-decoration:none" dir="ltr">▶ پخش ویدیو</a>' : '') . '</div></div>';
        case 'map':
            $mu = trim((string)($props['mapUrl'] ?? ''));
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'محدوده خدمات') . '</div><div class="fake-map">' . e($props['text'] ?? '📍 نقشه محدوده خدمات') . ($mu !== '' ? ' — <a href="' . e($mu) . '" target="_blank" rel="noopener" style="color:#2563eb">مشاهده در نقشه ↗</a>' : '') . '</div></div>';
        case 'cta-phone':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' cta-blk"><div class="hero-title">' . ($title ?: 'همین حالا تماس بگیرید') . '</div><div class="cta-num" dir="ltr">' . e($props['phone'] ?? '۰۲۱-۱۲۳۴۵۶۷۸') . '</div></div>';
        case 'cta-request':
        case 'cta-banner':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' cta-blk"><div class="hero-title">' . ($title ?: 'درخواست تعمیر خود را ثبت کنید') . '</div><span class="hero-btn">' . e($props['btnText'] ?? '📝 ثبت درخواست') . '</span></div>';
        case 'sticky-mobile-cta':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' sticky-cta-demo"><span>📞 ' . e($props['phone'] ?? '۰۲۱-۱۲۳۴۵۶۷۸') . '</span><span class="hero-btn">' . e($props['btnText'] ?? 'ثبت درخواست') . '</span></div>';
        case 'breadcrumb':
            $crumbs = pvItems($props, [['', 'خانه', ''], ['', 'خدمات', '']]);
            $crumbHtml = '';
            foreach ($crumbs as $c) { $crumbHtml .= ($crumbHtml !== '' ? ' / ' : '') . e($c['text']); }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' crumb">' . $crumbHtml . ' / <b>صفحه فعلی</b></div>';
        case 'alert-notice': {
            $type = $props['alertType'] ?? 'info';
            $ico = ['info' => 'ℹ️', 'warning' => '⚠️', 'success' => '✅'][$type] ?? 'ℹ️';
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="alert-demo ' . e($type) . '">' . $ico . ' ' . e($props['text'] ?? 'سرویس در تعطیلات نیز پاسخگوی شماست') . '</div></div>';
        }
        case 'button-group':
            $its = pvItems($props, [['', 'تماس فوری'], ['', 'مشاهده خدمات'], ['', 'مقالات']]);
            $btnTxt = trim((string)($props['btnText'] ?? ''));
            if ($btnTxt !== '' && !in_array($btnTxt, array_column($its, 'text'), true)) { array_unshift($its, ['icon' => '', 'text' => $btnTxt, 'desc' => '']); }
            $out = '';
            foreach ($its as $i => $it) { $out .= '<span class="hero-btn' . ($i ? ' ghost' : '') . '">' . e($it['text']) . '</span>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="hero-btns" style="justify-content:flex-start">' . $out . '</div></div>';
        case 'icon-list':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="feat-list"><div class="feat-row"><span class="feat-ico">📞</span><div><b>پاسخگویی تلفنی</b><div class="feat-d">۷ روز هفته از ۹ تا ۲۰</div></div></div><div class="feat-row"><span class="feat-ico">📍</span><div><b>اعزام در محل</b><div class="feat-d">کل تهران و کرج</div></div></div></div></div>';
        case 'separator':
            return '<hr class="blk-sep">';
        case 'spacer':
            return '<div class="blk-spacer" style="height:' . (int)($props['height'] ?? 46) . 'px" title="فاصله"></div>';
        case 'footer-simple':
            $fl = pvItems($props, [['', 'خدمات', ''], ['', 'مقالات', ''], ['', 'تماس', '']]);
            $flHtml = '';
            foreach ($fl as $l) { $flHtml .= '<span>' . e($l['text']) . '</span>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' footer-blk"><div class="fake-logo">🏗️</div><nav class="fake-nav" style="justify-content:center">' . $flHtml . '</nav><div class="soc-row"><span> Telegram </span><span> Instagram </span><span> WhatsApp </span></div>' . (!empty($props['phone']) ? '<div class="feat-d" style="text-align:center;margin-top:6px">📞 ' . e($props['phone']) . '</div>' : '') . '</div>';
        case 'footer-contact':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' footer-blk"><div class="cols c3"><div><div class="fake-logo">🏗️</div><div class="fl w80"></div></div><div><div class="card-t">تماس</div><div class="feat-d">📞 ۰۲۱-۱۲۳۴۵۶۷۸<br>📍 تهران، خیابان نمونه</div></div><div><div class="card-t">ساعات کاری</div><div class="feat-d">شنبه تا پنجشنبه<br>۹ صبح تا ۸ شب</div></div></div></div>';
        case 'copyright':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' crump-blk">' . e($props['text'] ?? '© تمامی حقوق برای نمایندگی محفوظ است') . '</div>';
        /* ════════ 🆕 v2.12: عناصر — پیش‌نمایش واقعی (قبلاً fallback بودند!) ════════ */
        case 'notification-bar':
            return '<div class="blk notif-bar ' . e($props['notifColor'] ?? 'info') . '" style="padding:8px 14px">' . e($props['text'] ?? '🎉 سرویس ویژه تعطیلات — ۱۵٪ تخفیف سرویس دوره‌ای') . '</div>';
        case 'hero-form':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk split-hero"' . $styleAttr . '><div class="hero-split"><div><div class="hero-title">' . ($title ?: 'درخواست تعمیر آنلاین') . '</div><div class="hero-sub">' . e($props['subtitle'] ?? 'فرم را پر کنید — کارشناسان ما تماس می‌گیرند') . '</div><div class="hero-btns"><span class="hero-btn">📞 تماس فوری</span></div></div><div class="fake-card" style="text-align:right;background:rgba(255,255,255,.14);border:none"><div class="fake-input">نام و شماره تماس</div><div class="fake-input">نوع دستگاه</div><div class="hero-btn full" style="margin-top:8px">' . e($props['btnText'] ?? 'ثبت درخواست') . '</div></div></div></div>';
        case 'hero-marquee':
            return '<div class="blk marquee-blk"><div class="marquee-track"><span>' . e($props['text'] ?? '⚡ اعزام تکنسین در کمتر از ۲ ساعت — ⭐ بیش از ۵۰ هزار تعمیر موفق') . '</span></div></div>';
        case 'brand-story':
            $its = pvItems($props, [['۱۳۸۵', 'شروع فعالیت', 'با یک تعمیرگاه کوچک'], ['۱۳۹۲', 'نمایندگی رسمی', 'اخذ گواهی‌های تخصصی'], ['۱۴۰۲', '۵۰ هزارمین تعمیر', 'و بیش از ۳۰ همکار']]);
            $out = '';
            foreach ($its as $it) { $out .= '<div class="story-sec"><span class="story-year">' . e($it['icon']) . '</span><div><b>' . e($it['text']) . '</b><div class="feat-d">' . e($it['desc']) . '</div></div></div>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="story-wrap">' . $out . '</div></div>';
        case 'area-list':
            $its = pvItems($props, [['', 'سعادت‌آباد'], ['', 'پونک'], ['', 'ولنجک'], ['', 'تجریش'], ['', 'شهرک غرب'], ['', 'نیاوران']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="chip-row">' . implode('', array_map(static fn($a) => '<span class="chip">📍 ' . e($a['text']) . '</span>', $its)) . '</div></div>';
        case 'checklist':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<div class="feat-list"><div class="feat-row"><span class="feat-ico">☑️</span><div>دستگاه را روشن و خاموش کنید و دوباره امتحان کنید</div></div><div class="feat-row"><span class="feat-ico">☑️</span><div>کد خطای نمایشگر را یادداشت کنید</div></div><div class="feat-row"><span class="feat-ico">☑️</span><div>صداهای غیرعادی و بوی سوختگی را بررسی کنید</div></div><div class="feat-row"><span class="feat-ico">☑️</span><div>فاکتور خرید و گارانتی را آماده داشته باشید</div></div></div></div>';
        case 'search-bar':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="search-wrap"><span class="search-ico">🔎</span><div class="fake-input" style="flex:1;border:none">' . e($props['placeholder'] ?? 'جستجوی کد خطا، مقاله یا دستگاه...') . '</div><span class="hero-btn">جستجو</span></div></div>';
        case 'certificates':
            $its = pvItems($props, [['🎖️', 'نمایندگی رسمی', 'از سال ۱۳۸۵'], ['🏆', 'برند برتر خدمات', 'رأی مشتریان ۱۴۰۲'], ['📋', 'مجوز اتحادیه', 'کد ۱۲۳۴۵']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'گواهینامه‌ها و افتخارات') . '</div><div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="card-ico">' . e($it['icon'] ?: '🎖️') . '</div><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
        case 'review-grid':
            $its = pvItems($props, [['علی محمدی', 'سرویس سریع و منظم بود؛ راضی بودم.'], ['مریم احمدی', 'قیمت شفاف و ضمانت واقعی.'], ['رضا کریمی', 'تکنسین دقیق و حرفه‌ای اعزام شد.']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'مشتریان ما چه می‌گویند') . '</div><div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="feat-d" style="direction:ltr;text-align:left">⭐⭐⭐⭐⭐</div><div class="feat-d">«' . e($it['text']) . '»</div><b class="feat-d">' . e($it['icon']) . '</b></div>', $its)) . '</div></div>';
        case 'contact-cards':
            $its = pvItems($props, [['📞', 'تلفن', '۰۲۱-۱۲۳۴۵۶۷۸'], ['💬', 'واتساپ', '۰۹۱۲-۰۰۰-۰۰۰۰'], ['📍', 'آدرس', 'تهران، خیابان نمونه']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'راه‌های ارتباطی') . '</div><div class="cols c3">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="card-ico">' . e($it['icon'] ?: '📞') . '</div><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
        case 'appointment-form':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '"><div class="blk-title">' . ($title ?: 'رزرو نوبت سرویس') . '</div><div class="form-grid"><div class="fake-input">نام و شماره تماس</div><div class="fake-input">📅 تاریخ مورد نظر</div><div class="fake-input">🕐 بازه ساعتی (۹-۱۲ / ۱۲-۱۵ / ۱۵-۱۸)</div><div class="fake-input">نوع دستگاه و شرح مشکل</div><div class="hero-btn full">رزرو نوبت</div></div></div>';
        case 'stats-grid':
            $its = pvItems($props, [['۱۲+', 'سال تجربه'], ['۵۰k', 'تعمیر موفق'], ['۹۸٪', 'رضایت'], ['۴۲', 'نوع دستگاه'], ['۲۴/۷', 'پشتیبانی'], ['۶ ماه', 'ضمانت']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'سهند سرویس در یک نگاه') . '</div><div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card" style="text-align:center"><div class="stat-n">' . e($it['icon'] ?: '۰') . '</div><div class="feat-d">' . e($it['text']) . '</div></div>', $its)) . '</div></div>';
        case 'before-after':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'نتیجه تعمیر حرفه‌ای') . '</div><div class="ba-wrap"><div class="ba-side"><div class="ba-tag bad">قبل</div><div class="fake-card" style="text-align:center">' . e($props['text'] ?? 'دستگاه روشن نمی‌شود — کد خطا فعال') . '</div></div><div class="ba-arrow">←</div><div class="ba-side"><div class="ba-tag ok">بعد</div><div class="fake-card" style="text-align:center">' . e($props['textAfter'] ?? 'کارکرد کامل — تست‌شده و ضمانت‌دار') . '</div></div></div></div>';
        case 'cta-whatsapp':
            $ph = trim((string)($props['phone'] ?? ''));
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' cta-blk">' . ($title ? '<div class="blk-title" style="margin-bottom:9px">' . e($title) . '</div>' : '') . '<div class="hero-btns"><span class="hero-btn" style="background:#16a34a">💬 ' . ($ph !== '' ? 'گفتگو در واتساپ — ' . e($ph) : 'گفتگو در واتساپ') . '</span><span class="hero-btn ghost">📞 تماس تلفنی</span></div></div>';
        case 'warranty-banner':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="feat-row" style="align-items:center"><span class="feat-ico" style="font-size:30px">🛡️</span><div><b style="font-size:15px">' . e($props['title'] ?? 'ضمانت کتبی ۶ ماهه روی قطعه و خدمات') . '</b><div class="feat-d">' . e($props['text'] ?? 'در صورت ایراد مجدد، تعمیر اصلاحی رایگان') . '</div></div><span class="hero-btn" style="margin-inline-start:auto">مشاهده شرایط</span></div></div>';
        case 'working-hours':
            $its = pvItems($props, [['', 'شنبه تا چهارشنبه', '۹ صبح تا ۸ شب'], ['', 'پنجشنبه', '۹ صبح تا ۲ ظهر'], ['', 'جمعه', '⚠️ فقط امداد فوری']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'ساعات کاری') . '</div><div class="price-table">' . implode('', array_map(static fn($it) => '<div class="price-row"><span>' . e($it['text']) . '</span><b>' . e($it['desc']) . '</b></div>', $its)) . '</div></div>';
        case 'social-follow':
            $its = pvItems($props, [['📡', 'تلگرام'], ['📷', 'اینستاگرام'], ['💬', 'واتساپ'], ['▶️', 'آپارات']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'ما را دنبال کنید') . '</div><div class="hero-btns">' . implode('', array_map(static fn($it) => '<span class="hero-btn">' . e($it['icon'] ?: '📣') . ' ' . e($it['text']) . '</span>', $its)) . '</div></div>';
        case 'trust-badges':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '>' . $head . '<div class="chip-row" style="justify-content:space-around">' . implode('', array_map(static fn($p) => '<div style="text-align:center;min-width:86px"><div style="font-size:26px">' . e($p['icon'] ?: '🏅') . '</div><div class="feat-d" style="font-size:11px">' . e($p['text']) . '</div></div>', pvItems($props, [['🛡️', 'ضمانت کتبی'], ['💳', 'پرداخت اقساطی'], ['⚡', 'اعزام فوری'], ['🏆', 'نمایندگی رسمی'], ['🔧', 'قطعات اصلی']]))) . '</div></div>';
        case 'footer-links':
            $its = pvItems($props, [['', 'خدمات ما'], ['', 'مقالات آموزشی'], ['', 'کدهای خطا'], ['', 'سوالات متداول'], ['', 'قوانین و مقررات'], ['', 'حریم خصوصی']]);
            $out = '';
            foreach ($its as $it) { $out .= '<div class="feat-d" style="padding:3px 0">' . e($it['text']) . ($it['desc'] !== '' ? ' — <span dir="ltr" style="opacity:.6;font-size:10px">' . e($it['desc']) . '</span>' : '') . '</div>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . ($title ? '<div class="blk-title" style="margin-bottom:10px">' . e($title) . '</div>' : '') . '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:4px">' . $out . '</div></div>';
        case 'payment-methods':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '>' . ($title !== '' ? '<div class="blk-title" style="margin-bottom:8px">' . e($title) . '</div>' : '') . '<div class="chip-row" style="justify-content:center">' . implode('', array_map(static fn($p) => '<span class="chip">' . e($p['icon'] ?: '💳') . ' ' . e($p['text']) . '</span>', pvItems($props, [['💳', 'پرداخت کارتی'], ['💰', 'پرداخت نقدی'], ['🧾', 'کارت به کارت'], ['📟', 'درگاه آنلاین'], ['🤝', 'اقساطی']]))) . '</div></div>';

        /* ════════ 🆕 v3.3: عناصر جدید — پیش‌نمایش واقعی ════════ */
        case 'announcement-pill':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="pill-announce"><span class="pill-dot"></span>' . ($title ?: '📣 تیتر مهم امروز') . '</div></div>';
        case 'heading-center':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="text-align:center"><div class="blk-title" style="font-size:23px">' . ($title ?: 'عنوان بزرگ بخش') . '</div><div class="feat-d" style="font-size:13.5px;margin-top:6px">' . e($props['subtitle'] ?? 'زیرعنوان توضیحی این بخش') . '</div><div style="width:56px;height:4px;border-radius:4px;background:var(--p);margin:14px auto 0"></div></div></div>';
        case 'numbered-list':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<div class="num-list"><div class="num-row"><span class="num-n">۱</span><div><b>عیب‌یابی تخصصی رایگان</b><div class="feat-d">بررسی کامل با دستگاه تست</div></div></div><div class="num-row"><span class="num-n">۲</span><div><b>پیش‌فاکتور شفاف</b><div class="feat-d">تأیید قیمت قبل از شروع کار</div></div></div><div class="num-row"><span class="num-n">۳</span><div><b>تعمیر با قطعات اصلی</b><div class="feat-d">همراه با ۶ ماه ضمانت</div></div></div></div></div>';
        case 'info-box':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="info-box-demo"><span class="feat-ico" style="font-size:22px">' . e($props['icon'] ?? '💡') . '</span><div><b>' . ($title ?: 'نکته مهم') . '</b><div class="feat-d">' . e($props['text'] ?? 'متن توضیح جعبه اطلاعات...') . '</div></div></div></div>';
        case 'price-cards':
            $its = pvItems($props, [['اقتصادی', 'سرویس پایه — ۴۵۰ هزار تومان', ''], ['استاندارد', 'سرویس کامل — ۹۵۰ هزار تومان', ''], ['ویژه', 'سرویس + قطعه — ۱٫۵ میلیون', '']]);
            $out = '';
            foreach ($its as $i => $it) { $out .= '<div class="fake-card"' . ($i === 1 ? ' style="border:2px solid var(--p,#2563eb)"' : '') . '><div class="card-t">' . e($it['text']) . '</div><div class="feat-d" style="font-weight:800;color:#1e40af">' . e($it['desc']) . '</div></div>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'پلن‌های سرویس') . '</div><div class="cols c3">' . $out . '</div></div>';
        case 'location-cards':
            $its = pvItems($props, [['🏬', 'شعبه مرکزی', 'تهران، ولیعصر'], ['🏬', 'شعبه غرب', 'تهران، سعادت‌آباد']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'شعب ما') . '</div><div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="card-ico">' . e($it['icon'] ?: '🏬') . '</div><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
        case 'expert-cards':
            $its = pvItems($props, [['🔧', 'مهندس کریمی', 'برد و الکترونیک'], ['❄️', 'مهندس رضایی', 'سیستم سرمایش']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'متخصصین ما') . '</div><div class="cols c' . pvCols($props, 4) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="fake-ava">' . e($it['icon'] ?: '👨‍🔧') . '</div><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
        case 'logo-cloud':
            $its = pvItems($props, [['🏅', 'نشان سفیر خدمت'], ['📋', 'مجوز اتحادیه'], ['🎖️', 'نمایندگی رسمی'], ['✅', 'تاییدیه کیفیت']]);
            $out = '';
            foreach ($its as $it) { $out .= '<div style="text-align:center;min-width:86px"><div style="font-size:26px">' . e($it['icon'] ?: '🏅') . '</div><div class="feat-d" style="font-size:11px">' . e($it['text']) . '</div></div>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="chip-row" style="justify-content:center">' . $out . '</div></div>';
        case 'social-proof':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="soc-proof"><div class="ava-stack"><span class="fake-ava" style="width:34px;height:34px;font-size:13px">👩</span><span class="fake-ava" style="width:34px;height:34px;font-size:13px;margin-inline-start:-10px">🧑</span><span class="fake-ava" style="width:34px;height:34px;font-size:13px;margin-inline-start:-10px">👨</span><span class="fake-ava" style="width:34px;height:34px;font-size:11px;margin-inline-start:-10px">+۵۰k</span></div><div><div class="stars">⭐⭐⭐⭐⭐ <b>۴.۹ از ۵</b></div><div class="feat-d">' . e($props['text'] ?? 'بیش از ۵۰ هزار مشتری به ما اعتماد کرده‌اند') . '</div></div></div></div>';
        case 'link-buttons':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<div class="hero-btns" style="justify-content:flex-start"><span class="hero-btn">📄 دانلود بروشور</span><span class="hero-btn ghost">🔎 پیگیری درخواست</span><span class="hero-btn ghost">🧾 فاکتور آنلاین</span></div></div>';
        case 'promo-card':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="promo-card-demo"><div><span class="badge badge-warning" style="font-size:10.5px">🎁 پیشنهاد ویژه</span><div class="blk-title" style="font-size:19px;margin:9px 0 5px">' . ($title ?: 'کمپین سرویس بهاره') . '</div><div class="feat-d">' . e($props['subtitle'] ?? 'تا ۲۵٪ تخفیف — تا پایان ماه') . '</div></div><div style="text-align:center"><div class="stat-n" style="font-size:33px">۲۵٪</div><span class="hero-btn" style="margin-top:8px">همین حالا رزرو کنید</span></div></div></div>';
        case 'divider-icon':
            return '<div class="blk ' . $bgClass . '" style="padding:10px 16px"><div class="divider-ico"><span class="divider-line"></span><span style="font-size:17px">' . e($props['icon'] ?? '🔧') . '</span><span class="divider-line"></span></div></div>';
        case 'contact-info-bar':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="chip-row" style="justify-content:space-between"><span class="chip">📞 <b dir="ltr">' . e($props['phone'] ?? '۰۲۱-۱۲۳۴۵۶۷۸') . '</b></span><span class="chip">🕐 شنبه-پنجشنبه ۹-۲۰</span><span class="chip">📍 تهران</span><span class="chip">💬 واتساپ</span></div></div>';

        /* ════════ 🆕 v2.14: ۱۲ عنصر جدید — پیش‌نمایش واقعی ════════ */
        /* ════════ 🆕 v2.25: بیست عنصر جدید ════════ */
        case 'pros-cons':
            $its = pvItems($props, [['✅', 'قطعات اصلی و ضمانت‌دار', ''], ['✅', 'اعزام سریع تکنسین', ''], ['⚠️', 'زمان تعمیر ۲ تا ۴ روز کاری', '']]);
            $pros = '';
            foreach ($its as $it) { if (!str_starts_with($it['icon'], '⚠') && !str_starts_with($it['icon'], '❌')) { $pros .= '<div class="feat-row"><span class="feat-ico" style="background:#f0fdf4">' . e($it['icon'] ?: '✅') . '</span><div>' . e($it['text']) . '</div></div>'; } }
            $cons = '';
            foreach ($its as $it) { if (str_starts_with($it['icon'], '⚠') || str_starts_with($it['icon'], '❌')) { $cons .= '<div class="feat-row"><span class="feat-ico" style="background:#fef2f2">' . e($it['icon']) . '</span><div>' . e($it['text']) . '</div></div>'; } }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c2"><div class="fake-card" style="border-inline-start:4px solid #16a34a"><div class="card-t" style="color:#15803d">✅ مزایا</div><div class="feat-list">' . $pros . '</div></div><div class="fake-card" style="border-inline-start:4px solid #dc2626"><div class="card-t" style="color:#b91c1c">⚠️ نکات</div><div class="feat-list">' . ($cons !== '' ? $cons : '<div class="feat-d">موردی ثبت نشده — آیتم با آیکون ⚠️ اضافه کنید</div>') . '</div></div></div></div>';
        case 'text-accent-box':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-inline-start:5px solid #2563eb;border-radius:12px;padding:15px 17px">' . ($title ? '<div class="card-t" style="margin-bottom:6px">' . e($title) . '</div>' : '') . '<div class="feat-d" style="color:#1e40af">' . e($props['text'] ?? 'متنی که باید توجه کاربر را جلب کند.') . '</div></div></div>';
        case 'definition-list':
            $its = pvItems($props, [['🔧', 'ایرادیابی', 'بررسی کامل دستگاه برای یافتن عیب'], ['🧲', 'مگنترون', 'قطعه تولید امواج مایکروویو']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="feat-list">' . implode('', array_map(static fn($it) => '<div class="feat-row"><span class="feat-ico">' . e($it['icon'] ?: '📖') . '</span><div><b>' . e($it['text']) . '</b><div class="feat-d">' . e($it['desc']) . '</div></div></div>', $its)) . '</div></div>';
        case 'article-highlight':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;background:linear-gradient(135deg,#fff7ed,#ffedd5);border:1.5px solid #fdba74;border-radius:15px;padding:19px 21px">' . pvImg($props, '🌟', 'width:130px;height:110px;flex:0 0 130px') . '<div style="flex:1;min-width:200px"><div class="card-t" style="font-size:15px">' . e($title ?: 'محتوای ویژه') . '</div>' . (!empty($props['subtitle']) ? '<div class="feat-d" style="font-weight:700;color:#9a3412">' . e($props['subtitle']) . '</div>' : '') . '<div class="feat-d">' . e($props['text'] ?? 'خلاصه‌ای از مزیت ویژه این بخش.') . '</div></div></div></div>';
        case 'page-header':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="text-align:center;padding:18px 10px 8px"><div class="hero-title" style="font-size:24px">' . e($title ?: 'عنوان صفحه') . '</div>' . (!empty($props['subtitle']) ? '<div class="feat-d">' . e($props['subtitle']) . '</div>' : '') . '<div class="feat-d" style="margin-top:8px;opacity:.65">خانه / ' . e($title ?: 'صفحه') . '</div></div></div>';
        case 'steps-vertical':
            $its = pvItems($props, [['۱', 'ثبت درخواست', 'آنلاین یا تلفنی'], ['۲', 'عیب‌یابی و اعلام هزینه', 'شفاف و پیش از شروع'], ['۳', 'تعمیر و تحویل', 'همراه با ضمانت کتبی']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="feat-list">' . implode('', array_map(static fn($it) => '<div class="feat-row"><span class="feat-ico" style="background:linear-gradient(135deg,#2563eb,#0ea5e9);color:#fff;font-weight:800">' . e($it['icon'] ?: '•') . '</span><div><b>' . e($it['text']) . '</b><div class="feat-d">' . e($it['desc']) . '</div></div></div>', $its)) . '</div></div>';
        case 'service-price-cards':
            $its = pvItems($props, [['🧺', 'شست‌وشوی کامل ماشین لباس', 'از ۹۵۰ هزار تومان'], ['❄️', 'شارژ گاز کولر', 'از ۱٫۲ میلیون تومان'], ['🔥', 'تعویض هیتر ماشین ظرفشویی', 'از ۱٫۵ میلیون تومان']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'تعرفه خدمات پرتقاضا') . '</div><div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="card-ico">' . e($it['icon'] ?: '🔧') . '</div><div class="card-t" style="font-size:12.5px">' . e($it['text']) . '</div><div class="feat-d" style="font-weight:800;color:#1e40af">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
        case 'feature-icons-grid':
            $its = pvItems($props, [['🧊', 'یخچال', ''], ['🧺', 'لباسشویی', ''], ['📺', 'تلویزیون', ''], ['🔥', 'فر و اجاق', '']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'خدمات ما در یک نگاه') . '</div><div class="cols c' . pvCols($props, 4) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card" style="text-align:center;padding:15px 8px"><div style="font-size:31px">' . e($it['icon'] ?: '🔧') . '</div><div class="feat-d" style="font-weight:700;margin-top:6px">' . e($it['text']) . '</div></div>', $its)) . '</div></div>';
        case 'callback-form':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'درخواست تماس کارشناس') . '</div><div class="news-row"><div class="fake-input" style="flex:1">شماره تماس شما</div><div class="hero-btn">' . e($props['btnText'] ?? 'با من تماس بگیرید') . '</div></div><div class="feat-d" style="margin-top:7px">✅ کارشناسان ما در کمتر از ۱۵ دقیقه تماس می‌گیرند</div></div>';
        case 'survey-form':
            $its = pvItems($props, [['⭐', 'بسیار راضی', ''], ['👍', 'راضی', ''], ['😐', 'معمولی', '']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'میزان رضایت شما از سرویس؟') . '</div><div class="cols c' . max(2, min(4, count($its))) . '" style="gap:9px">' . implode('', array_map(static fn($it) => '<div class="fake-card" style="text-align:center;padding:13px 8px;cursor:pointer"><div style="font-size:23px">' . e($it['icon'] ?: '⭐') . '</div><div class="feat-d" style="font-weight:700">' . e($it['text']) . '</div></div>', $its)) . '</div></div>';
        case 'chat-widget':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="display:flex;justify-content:flex-end"><div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:15px 15px 3px 15px;padding:11px 15px;max-width:290px;box-shadow:0 8px 22px rgba(2,8,23,.12)"><div style="font-size:12.5px"><b>💬 ' . e($title ?: 'پشتیبانی آنلاین') . '</b></div><div class="feat-d">سلام! چطور می‌تونیم کمکتون کنیم؟</div><div style="display:flex;gap:6px;margin-top:8px"><span class="hero-btn" style="font-size:11px;padding:5px 12px">شروع گفتگو</span></div></div></div></div>';
        case 'vote-poll':
            $its = pvItems($props, [['🧺', 'لباسشویی', ''], ['❄️', 'یخچال', ''], ['🔥', 'ماکروویو', '']]);
            $faP = static fn($n) => strtr((string)$n, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
            $total = count($its) * 12 + 30;
            $out = '';
            foreach ($its as $i => $it) { $p = (int)round(($total - $i * 11) / max(1, $total) * 100); $out .= '<div class="cap-row"><span class="cap-h">' . e($it['icon']) . ' ' . e($it['text']) . '</span><div class="track" style="flex:1"><div class="fill" style="width:' . $p . '%"></div></div><span class="cap-l">' . $faP($p) . '٪</span></div>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'رای‌گیری') . '</div><div class="cap-demo">' . $out . '</div></div>';
        case 'video-grid':
            $gcols = pvCols($props, 3);
            $gimgs = '';
            for ($gi = 0; $gi < $gcols * 2; $gi++) { $gimgs .= '<div style="position:relative">' . str_replace('fake-img', 'fake-img small', pvImg($props, '🎬', 'min-height:96px')) . '<div class="play" style="width:30px;height:30px;font-size:12px">▶</div></div>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'ویدیوهای آموزشی') . '</div><div class="cols c' . $gcols . '">' . $gimgs . '</div></div>';
        case 'logo-marquee':
            $its = pvItems($props, [['🏷️', 'ال‌جی', ''], ['🏷️', 'سامسونگ', ''], ['🏷️', 'بوش', ''], ['🏷️', 'سونی', ''], ['🏷️', 'پاکس', ''], ['🏷️', 'اسنوا', '']]);
            $chips = '';
            foreach (array_merge($its, $its) as $it) { $chips .= '<span class="chip" style="margin-inline-end:9px">' . e($it['icon'] ?: '🏷️') . ' ' . e($it['text']) . '</span>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'برندهای مورد خدمت') . '</div><div style="overflow:hidden;background:#f8fafc;border-radius:12px;padding:11px 0"><div class="chip-row" style="animation:tickMove 18s linear infinite;white-space:nowrap;width:max-content">' . $chips . '</div></div></div>';
        case 'tag-cloud':
            $its = pvItems($props, [['', 'تعمیر ماشین لباس', ''], ['', 'کد خطا SE', ''], ['', 'شارژ گاز کولر', ''], ['', 'بک‌لایت تلویزیون', '']]);
            $sizes = ['12px', '14px', '13px', '15px', '12.5px', '14.5px'];
            $out = '';
            foreach ($its as $i => $it) { $out .= '<span class="chip" style="font-size:' . $sizes[$i % count($sizes)] . ';font-weight:' . ($i % 3 === 0 ? 800 : 600) . ';opacity:' . (0.72 + ($i % 3) * 0.09) . '">' . e($it['text']) . '</span>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="chip-row" style="justify-content:center;gap:8px">' . $out . '</div></div>';
        case 'quote-slider':
            $its = pvItems($props, [['علی محمدی', 'سرویس سریع و منظم بود؛ راضی بودم.', ''], ['مریم احمدی', 'قیمت شفاف و ضمانت واقعی.', ''], ['رضا کریمی', 'تکنسین دقیق و حرفه‌ای اعزام شد.', '']]);
            $q = $its[0] ?? ['icon' => '', 'text' => ''];
            $dots = implode(' ', array_map(static fn($i) => '○', $its));
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'مشتریان چه می‌گویند') . '</div><div class="quote" style="text-align:center;font-size:15px">«' . e($q['text']) . '»</div><div class="feat-d" style="text-align:center;font-weight:800;margin-top:6px">— ' . e($q['icon']) . '</div><div class="slider-dots" style="margin-top:8px">● ' . $dots . '</div></div>';
        case 'stats-circles':
            $its = pvItems($props, [['۹۲٪', 'تعمیر در روز اول', ''], ['۸۷٪', 'رضایت کامل', ''], ['۹۶٪', 'حل قطعی ایراد', '']]);
            $out = '';
            foreach ($its as $it) { $num = preg_replace('/[^0-9]/', '', $it['icon']) ?: '80'; $deg = (int)round((int)$num / 100 * 360); $out .= '<div style="text-align:center"><div style="width:86px;height:86px;margin:0 auto;border-radius:50%;background:conic-gradient(#2563eb ' . $deg . 'deg,#e2e8f0 ' . $deg . 'deg);display:flex;align-items:center;justify-content:center"><div style="width:66px;height:66px;background:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:16.5px;color:#1e40af">' . e($it['icon'] ?: $num) . '</div></div><div class="feat-d" style="margin-top:8px;font-weight:700">' . e($it['text']) . '</div></div>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'عملکرد ما در آمار واقعی') . '</div><div class="cols c' . max(2, min(4, count($its))) . '" style="gap:14px">' . $out . '</div></div>';
        case 'counter-big':
            $its = pvItems($props, [['۵۰,۰۰۰+', 'تعمیر تکمیل‌شده', '']]);
            $it0 = $its[0] ?? ['icon' => '۵۰,۰۰۰+', 'text' => 'تعمیر تکمیل‌شده'];
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="text-align:center;background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1.5px solid #93c5fd;border-radius:16px;padding:26px 18px"><div class="hero-title" style="font-size:37px;background:linear-gradient(135deg,#1e40af,#0ea5e9);-webkit-background-clip:text;background-clip:text;color:transparent">' . e($it0['icon'] ?: $it0['text']) . '</div><div class="feat-d" style="font-weight:800;font-size:14px;margin-top:5px">' . e($it0['icon'] !== '' ? $it0['text'] : 'شمارنده') . '</div></div></div>';
        case 'brand-stats-bar':
            $its = pvItems($props, [['۱۵+', 'سال تجربه', ''], ['۴۲', 'نوع دستگاه تخصصی', ''], ['۲۴/۷', 'پشتیبانی', ''], ['۶ ماه', 'ضمانت کتبی', '']]);
            $out = '';
            foreach ($its as $i => $it) { $out .= ($i > 0 ? '<span class="ss-sep"></span>' : '') . '<span class="ss-item"><b>' . e($it['icon']) . '</b> ' . e($it['text']) . '</span>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="stats-strip" style="background:linear-gradient(135deg,#0f172a,#1e3a8a)">' . $out . '</div></div>';
        case 'emergency-strip':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border-radius:13px;padding:13px 19px"><b style="font-size:14px">' . e($props['text'] ?? '🚑 امداد تعمیر فوری — ۲۴ ساعته') . '</b><span class="hero-btn" style="background:#fff;color:#b91c1c">📞 ' . e($props['phone'] ?? '۰۲۱-۱۲۳۴۵۶۷۸') . '</span></div></div>';
        case 'stats-strip':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' stats-strip"><span class="ss-item"><b>۱۲+</b> سال تجربه</span><span class="ss-sep"></span><span class="ss-item"><b>۵۰k</b> تعمیر موفق</span><span class="ss-sep"></span><span class="ss-item"><b>۹۸٪</b> رضایت</span><span class="ss-sep"></span><span class="ss-item"><b>۲h</b> اعزام</span></div>';
        case 'benefits-list':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<div class="feat-list">' . implode('', array_map(static fn($b) => '<div class="feat-row"><span class="feat-ico" style="background:#f0fdf4">✅</span><div><b>' . e($b) . '</b></div></div>', ['اعزام تکنسین در کمتر از ۲ ساعت', 'قطعات فابریک با فاکتور معتبر', '۶ ماه ضمانت کتبی قطعه و خدمات', 'پیش‌فاکتور شفاف قبل از شروع کار', 'پیگیری وضعیت درخواست آنلاین'])) . '</div></div>';
        case 'warning-box':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="warning-box-demo"><span class="feat-ico" style="background:#fef2f2;font-size:22px">' . e($props['icon'] ?? '⚠️') . '</span><div><b style="color:#b91c1c">' . ($title ?: 'هشدار ایمنی مهم') . '</b><div class="feat-d">' . e($props['text'] ?? 'قبل از هرگونه باز کردن دستگاه، برق را کاملاً قطع کنید.') . '</div></div></div></div>';
        case 'brand-intro-card':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="brand-intro-demo"><div class="fake-logo" style="font-size:34px">🏗️</div><div style="flex:1"><div class="blk-title" style="margin-bottom:4px">' . ($title ?: 'نمایندگی رسمی خدمات') . '</div><div class="feat-d">' . e($props['subtitle'] ?? 'بیش از یک دهه تجربه تخصصی') . '</div><div class="stars" style="font-size:11px;margin-top:5px">⭐⭐⭐⭐⭐ <b>۴.۹ از ۵</b></div></div><span class="hero-btn" style="align-self:center">مشاهده خدمات</span></div></div>';
        case 'author-box':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="author-box-demo"><div class="fake-ava" style="font-size:38px">' . e($props['icon'] ?? '👨‍🔧') . '</div><div style="flex:1"><b style="font-size:14px">' . ($title ?: 'مهندس کریمی') . '</b><div class="feat-d">کارشناس برد و الکترونیک — ۱۴ سال تجربه</div><div class="feat-d" style="margin-top:4px">' . e($props['text'] ?? 'متخصص تعمیر برد‌های اصلی لباسشویی، یخچال و کولر گازی.') . '</div></div></div></div>';
        case 'download-card':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="download-card-demo"><span class="feat-ico" style="font-size:30px;background:#eff6ff">📄</span><div style="flex:1"><div class="blk-title" style="margin-bottom:3px;text-align:right">' . ($title ?: 'بروشور خدمات ما') . '</div><div class="feat-d">' . e($props['subtitle'] ?? 'فهرست کامل خدمات و تعرفه‌ها در یک فایل PDF') . '</div></div><span class="hero-btn">' . e($props['btnText'] ?? '⬇ دانلود بروشور') . '</span></div></div>';
        case 'schedule-table':
            $its = pvItems($props, [['', 'شنبه', '۹ تا ۲۰'], ['', 'یکشنبه تا چهارشنبه', '۹ تا ۲۰'], ['', 'پنجشنبه', '۹ تا ۱۴'], ['', 'جمعه', 'فقط امداد فوری']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'ساعات کاری ما') . '</div><div class="price-table">' . implode('', array_map(static fn($it) => '<div class="price-row"><span>' . e($it['text']) . '</span><b>' . e($it['desc']) . '</b></div>', $its)) . '</div></div>';
        case 'price-highlight': {
            $badge = trim((string)($props['badge'] ?? ''));
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="fake-card price-highlight-demo" style="text-align:right">' . ($badge !== '' ? '<span class="badge badge-warning" style="font-size:10px">' . e($badge) . '</span>' : '') . '<div class="blk-title" style="text-align:right;margin:8px 0 3px">' . ($title ?: 'سرویس دوره‌ای کامل') . '</div><div class="stat-n" style="font-size:31px;text-align:right">' . e($props['price'] ?? '۴۵۰ هزار تومان') . '</div><div class="feat-d" style="margin:7px 0 11px">شامل شست‌وشو، کالیبراسیون و تست ایمنی + ۶ ماه ضمانت</div><span class="hero-btn full">رزرو همین حالا</span></div></div>';
        }
        case 'feature-table':
            $its = pvItems($props, [['', 'عیب‌یابی رایگان'], ['', 'ضمانت ۶ ماهه'], ['', 'قطعات فابریک']]);
            $rows = '';
            foreach ($its as $it) { $rows .= '<div class="ft-row"><span>' . e($it['text']) . '</span><b>—</b><b>✓</b><b class="ft-hl">✓</b></div>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'مقایسه پلن‌های سرویس') . '</div><div class="feature-table-demo"><div class="ft-row ft-head"><span>ویژگی</span><b>اقتصادی</b><b>استاندارد</b><b class="ft-hl">ویژه</b></div>' . $rows . '</div></div>';
        case 'quick-contact-form':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="quick-form-demo"><div class="fake-input" style="flex:1">📱 شماره تماس شما</div><span class="hero-btn">' . e($props['btnText'] ?? 'درخواست تماس') . '</span></div><div class="feat-d" style="text-align:center;margin-top:7px">' . e($props['subtitle'] ?? $title ?? 'کارشناسان ما در کمتر از ۱۵ دقیقه تماس می‌گیرند') . '</div></div>';
        case 'related-links':
            $its = pvItems($props, [['', 'کد خطای LE لباسشویی ال‌جی — معنی و رفع'], ['', '۱۰ علامت خرابی کمپرسور یخچال'], ['', 'راهنمای نگهداری ماکروویو']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="feat-list">' . implode('', array_map(static fn($it) => '<div class="feat-row"><span class="feat-ico">🔗</span><div>' . e($it['text']) . '</div></div>', $its)) . '</div></div>';
        case 'warranty-steps':
            $its = pvItems($props, [['', 'ثبت سریال دستگاه'], ['', 'صدور برگه ضمانت'], ['', 'پشتیبانی ۶ ماهه']]);
            $out = '';
            foreach ($its as $i => $it) { $out .= ($i > 0 ? '<div class="step-arrow">←</div>' : '') . '<div class="step"><span class="step-n">' . e($it['icon'] ?: strtr((string)($i + 1), ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴'])) . '</span><div class="step-t">' . e($it['text']) . '</div></div>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'گارانتی ما چگونه کار می‌کند') . '</div><div class="steps-row">' . $out . '</div></div>';
        case 'ticker-bar':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="ticker-bar-demo"><span class="ticker-tag">🔴 زنده</span><div class="ticker-track"><span>' . e($props['text'] ?? '⚡ اعزام تکنسین فوری · 🧊 شارژ گاز کولر از ۹۰۰ هزار تومان · 🛡️ گارانتی ۶ ماهه · 📞 پاسخگویی ۷ روز هفته') . '</span></div></div></div>';
        case 'booking-calendar': {
            $days = '';
            foreach (['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'] as $d) { $days .= '<span class="cal-dow">' . $d . '</span>'; }
            for ($i = 1; $i <= 28; $i++) {
                $cls = in_array($i - 1, [3, 8, 14, 19, 25], true) ? ' busy' : (in_array($i - 1, [5, 11, 22], true) ? ' sel' : '');
                $days .= '<span class="cal-day' . $cls . '">' . strtr((string)$i, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']) . '</span>';
            }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'رزرو نوبت آنلاین') . '</div><div class="cal-demo">' . $days . '</div><div class="feat-d" style="text-align:center;margin-top:8px">روزهای <b style="color:#b91c1c">پر</b> ظرفیت ندارند — روز سبز انتخابی شماست</div></div>';
        }
        case 'warranty-check':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'استعلام گارانتی') . '</div><div class="quick-form-demo"><div class="fake-input" style="flex:1;direction:ltr">SN-XXXX-1234</div><span class="hero-btn">' . e($props['btnText'] ?? 'استعلام') . '</span></div><div class="feat-d" style="text-align:center;margin-top:7px">شماره سریال دستگاه را وارد کنید — وضعیت گارانتی همان لحظه نمایش داده می‌شود</div></div>';
        case 'price-estimate':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'برآورد هزینه تعمیر') . '</div><div class="form-grid"><div class="fake-input">🌀 نوع دستگاه (لباسشویی، یخچال...)</div><div class="fake-input">🔧 نوع ایراد (نمایش کد، صدا، نشتی...)</div><div class="fake-input">📍 منطقه</div><div class="hero-btn full">🧮 محاسبه فوری برآورد</div></div></div>';
        case 'device-error-lookup':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'جستجوی کد خطای دستگاه') . '</div><div class="search-wrap"><span class="search-ico">🔢</span><div class="fake-input" style="flex:1;border:none;direction:ltr">E4 / LE / CH-05 ...</div><span class="hero-btn">جستجو</span></div><div class="feat-d" style="text-align:center;margin-top:7px">کد روی نمایشگر دستگاه را وارد کنید — علت، راه‌حل فوری و هزینه تعمیر را ببینید</div></div>';
        case 'live-queue':
            $its = pvItems($props, [['🟢', 'دریافت و عیب‌یابی', 'در حال انجام — ۲ دستگاه'], ['🟡', 'تعمیر برد', 'در صف — ۱ دستگاه'], ['🔴', 'آماده تحویل', '۳ دستگاه']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'وضعیت صف تعمیرات — زنده') . '</div><div class="queue-demo">' . implode('', array_map(static fn($it) => '<div class="queue-row"><span>' . e($it['icon'] ?: '🟢') . ' ' . e($it['text']) . '</span><b>' . e($it['desc']) . '</b></div>', $its)) . '</div></div>';
        case 'hourly-capacity':
            $its = pvItems($props, [['۹–۱۲', '20', 'کم‌تقاضا'], ['۱۲–۱۵', '60', 'متوسط'], ['۱۵–۱۸', '85', 'پرتقاضا']]);
            $out = '';
            foreach ($its as $it) { $p = max(5, min(100, (int)(preg_replace('/[^0-9]/', '', $it['text']) ?: 50))); $out .= '<div class="cap-row"><span class="cap-h">' . e($it['icon']) . '</span><div class="track" style="flex:1"><div class="fill" style="width:' . $p . '%"></div></div><span class="cap-l">' . e($it['desc']) . '</span></div>'; }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'ظرفیت سرویس امروز') . '</div><div class="cap-demo">' . $out . '</div></div>';
        case 'faq-search':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'جستجو در سوالات متداول') . '</div><div class="search-wrap"><span class="search-ico">🔎</span><div class="fake-input" style="flex:1;border:none">' . e($props['placeholder'] ?? 'سوال خود را بنویسید...') . '</div><span class="hero-btn">پرسیدن</span></div><div class="chip-row" style="margin-top:10px;justify-content:center">' . implode('', array_map(static fn($q) => '<span class="chip">❓ ' . $q . '</span>', ['لباسشویی آب تخلیه نمی‌کند', 'یخچال برق دارد ولی خنک نمی‌کند', 'کد E4 یعنی چه؟'])) . '</div></div>';
        case 'faq-category':
            $its = pvItems($props, [['🌀', 'لباسشویی و ظرفشویی', '۱۲ سوال'], ['❄️', 'یخچال و فریزر', '۹ سوال'], ['📺', 'تلویزیون', '۷ سوال']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'سوالات متداول بر اساس موضوع') . '</div><div class="cols c3">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="card-ico">' . e($it['icon'] ?: '❓') . '</div><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
        case 'before-after-slider': {
            $imgUrl = trim((string)($props['imageUrl'] ?? ''));
            $before = preg_match('#^(https?://|/|uploads/)#i', $imgUrl)
                ? '<img src="' . e($imgUrl) . '" alt="" style="width:100%;height:100%;object-fit:cover;filter:grayscale(1) contrast(1.1)">'
                : '🧺 فرسوده';
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'مقایسه تصویری قبل و بعد') . '</div><div class="bas-demo"><div class="bas-before">' . $before . '<span class="bas-tag">قبل</span></div><div class="bas-handle">⇔</div><div class="bas-after">✨ <b>مثل روز اول</b><span class="bas-tag ok">بعد</span></div></div></div>';
        }
        case 'social-wall':
            $its = pvItems($props, [['📷', 'نکته سرویس دوره‌ای', '۲ روز پیش'], ['🎥', 'ویدیوی عیب‌یابی زنده', '۵ روز پیش'], ['📝', 'معرفی تکنسین هفته', '۱ هفته پیش']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'آخرین پست‌های ما') . '</div><div class="cols c3">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div style="font-size:24px">' . e($it['icon'] ?: '📷') . '</div><div class="card-t" style="font-size:12px">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
        case 'newsletter-popup':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="np-demo-wrap"><div class="np-demo"><span class="feat-ico" style="font-size:30px;background:#eff6ff">📧</span><div><b style="font-size:14px">' . ($title ?: 'قبل از رفتن، پیشنهاد ویژه!') . '</b><div class="feat-d">' . e($props['subtitle'] ?? 'عضویت در خبرنامه = ۱۰٪ تخفیف اولین سرویس') . '</div></div><div class="news-row" style="margin-top:10px"><div class="fake-input" style="flex:1">ایمیل شما</div><span class="hero-btn">' . e($props['btnText'] ?? 'دریافت کد تخفیف') . '</span></div></div></div></div>';
        case 'credit-trust':
            $its = pvItems($props, [['98', 'امتیاز اعتماد مشتریان']]);
            $it0 = $its[0] ?? ['icon' => '98', 'text' => 'امتیاز اعتماد مشتریان'];
            $sc = preg_replace('/[^0-9]/', '', $it0['icon'] ?: $it0['text']) ?: '98';
            $fa = static fn($n) => strtr((string)$n, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="ct-demo"><div class="ct-score">' . $fa($sc) . '<small>/' . $fa(100) . '</small></div><div style="flex:1"><b style="font-size:14px">' . e($it0['text']) . '</b><div class="feat-d" style="margin-top:4px">بر اساس نظرسنجی مستقل مشتریان در ۱۲ ماه گذشته</div></div></div></div>';
        case 'brand-badges-row':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '>' . $head . '<div class="chip-row" style="justify-content:center;gap:10px">' . implode('', array_map(static fn($p) => '<span class="chip" style="padding:8px 14px;font-size:11.5px">' . e($p['icon'] ?: '🏅') . ' ' . e($p['text']) . '</span>', pvItems($props, [['🎖️', 'تعمیرکار رسمی سازمان فنی'], ['🛡️', 'بیمه مسئولیت حرفه‌ای'], ['📋', 'مجوز رسمی اتحادیه'], ['🔬', 'تخصص برد و الکترونیک']]))) . '</div></div>';

        /* ════════ 🆕 v2.17: ۱۶ عنصر جدید ════════ */
        case 'hero-minimal':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk hero-minimal-blk"><div class="hero-title" style="font-size:30px">' . ($title ?: 'تعمیر تخصصی، بدون معطلی') . '</div>' . (!empty($props['subtitle']) ? '<div class="hero-sub">' . e($props['subtitle']) . '</div>' : '') . '<div class="hero-btns"><span class="hero-btn">' . e($props['btnText'] ?? 'درخواست تعمیر') . '</span></div></div>';
        case 'hero-glass':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk"><div class="glass-hero-demo"><div class="hero-title">' . ($title ?: 'خدمات رسمی پس از فروش') . '</div><div class="hero-sub">' . e($props['subtitle'] ?? 'شفافیت کامل در قیمت و فرآیند') . '</div><div class="hero-btns"><span class="hero-btn ghost">مشاهده خدمات</span><span class="hero-btn">تماس</span></div></div></div>';
        case 'logo-strip':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="logo-strip-demo">' . str_repeat('<div class="fake-logo-s">🏷️</div>', 6) . '</div></div>';
        case 'text-columns': {
            $ps = array_values(array_filter(array_map('trim', explode("\n", (string)($props['text'] ?? 'پاراگراف اول متن...'))), static fn($l) => $l !== ''));
            $half = max(1, (int)ceil(count($ps) / 2));
            $c1 = array_slice($ps, 0, $half) ?: ['پاراگراف اول...'];
            $c2 = array_slice($ps, $half) ?: ['متن ستون دوم — پاراگراف‌ها با Enter جدا می‌شوند.'];
            $col = static fn(array $c) => implode('', array_map(static fn($p) => '<p>' . e($p) . '</p>', $c));
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="text-cols-demo"><div class="pv-text">' . $col($c1) . '</div><div class="pv-text">' . $col($c2) . '</div></div></div>';
        }
        case 'brand-values':
            $items = pvItems($props, [['💎', 'ارزش برند']]);
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($i) => '<div class="fake-card"><div class="card-ico">' . e($i['icon'] ?: '💎') . '</div><div class="card-t">' . e($i['text'] ?: 'ارزش') . '</div></div>', $items)) . '</div></div>';
        case 'tech-tips':
        case 'steps-compact': {
            $items = pvItems($props, [['۱', 'ثبت درخواست'], ['۲', 'اعزام تکنسین'], ['۳', 'تعمیر و تحویل']]);
            $rows = '';
            foreach ($items as $n => $i) {
                $rows .= '<div class="sc-row"><span class="sc-num">' . e($i['icon'] !== '' ? $i['icon'] : (string)($n + 1)) . '</span><div class="feat-d" style="font-size:12.5px">' . e($i['text'] ?: 'مرحله') . '</div></div>';
            }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="steps-compact-demo">' . $rows . '</div></div>';
        }
        case 'price-compare':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c' . pvCols($props, 3) . '"><div class="fake-card"><div class="card-t">اقتصادی</div><div class="stat-n" style="font-size:22px">پایه</div><div class="fl w90"></div><div class="fl w70"></div></div><div class="fake-card" style="border:2px solid var(--primary)"><span class="badge badge-warning" style="font-size:9.5px">پرطرفدار</span><div class="card-t">استاندارد</div><div class="stat-n" style="font-size:22px">کامل</div><div class="fl w100"></div><div class="fl w80"></div></div><div class="fake-card"><div class="card-t">ویژه</div><div class="stat-n" style="font-size:22px">طلایی</div><div class="fl w90"></div><div class="fl w60"></div></div></div></div>';
        case 'guarantee-card':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' guarantee-blk"><div class="guarantee-demo"><span style="font-size:42px">🛡️</span><div style="flex:1"><div class="blk-title" style="margin-bottom:4px">' . ($title ?: '۶ ماه ضمانت کتبی') . '</div><div class="feat-d">' . e($props['subtitle'] ?? 'تمام تعمیرات با ضمانت کتبی و قابل پیگیری انجام می‌شود.') . '</div></div><span class="hero-btn" style="align-self:center">' . e($props['btnText'] ?? 'مشاهده شرایط') . '</span></div></div>';
        case 'cta-timer':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk cta-timer-blk"' . $styleAttr . '><div class="hero-title" style="font-size:24px">' . ($title ?: 'تخفیف سرویس دوره‌ای') . '</div><div class="hero-sub">' . e($props['subtitle'] ?? 'فقط تا پایان هفته — بعد از پایان تایمر قیمت عادی است') . '</div>' . pvCountdown($props) . '<div class="hero-btns"><span class="hero-btn">همین حالا رزرو کنید</span></div></div>';
        case 'urgent-repair':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' urgent-blk"><div class="urgent-demo"><span style="font-size:34px">🚨</span><div style="flex:1"><b style="font-size:15px">' . ($title ?: 'تعمیر فوری نیاز دارید؟') . '</b><div class="feat-d">۲۴ ساعته — ۷ روز هفته اعزام تکنسین</div></div><div style="text-align:center"><div class="feat-d" style="font-size:10.5px">تماس فوری</div><div class="stat-n" style="font-size:19px" dir="ltr">📞 ' . e($props['phone'] ?? '۰۲۱-۱۲۳۴۵۶۷۸') . '</div><span class="hero-btn" style="margin-top:5px">' . e($props['btnText'] ?? 'درخواست اعزام') . '</span></div></div></div>';
        case 'faq-mini':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="faq-mini-demo"><div class="sc-row"><span class="sc-num">؟</span><b style="font-size:13.5px">' . ($title ?: 'سوال متداول') . '</b></div><div class="feat-d" style="margin-top:7px;font-size:12.5px">' . e($props['text'] ?? 'پاسخ کارشناسان ما به سوال متداول...') . '</div></div></div>';
        case 'reviews-carousel':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' reviews-blk">' . $head . '<div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($r) => '<div class="fake-card"><div class="stars">⭐⭐⭐⭐⭐</div><div class="feat-d">«' . $r . '»</div><div class="fake-ava" style="width:26px;height:26px;font-size:11px">😊</div></div>', ['عالی بود، همان روز آمدند', 'قیمت منصفانه و کار تمیز', 'دستگاه ۵ ساله‌ام مثل نو شد'])) . '</div><div class="slider-dots" style="margin-top:8px">● ○ ○</div></div>';
        case 'appointment-compact':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="apt-compact-demo"><b style="font-size:14px">' . ($title ?: 'نوبت تعمیر رزرو کنید') . '</b><div class="news-row" style="margin-top:9px"><div class="fake-input" style="flex:1">شماره تماس شما</div><div class="fake-input" style="flex:1">دستگاه + مشکل</div><div class="hero-btn">' . e($props['btnText'] ?? 'رزرو نوبت') . '</div></div></div></div>';
        case 'contact-map-split':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="split"><div><div class="chip-row" style="flex-direction:column;align-items:stretch;gap:7px"><span class="chip">📞 <b dir="ltr">' . e($props['phone'] ?? '۰۲۱-۱۲۳۴۵۶۷۸') . '</b></span><span class="chip">📍 تهران، خیابان نمونه، پلاک ۱۲</span><span class="chip">🕐 شنبه تا پنجشنبه ۹ تا ۲۰</span></div></div><div class="fake-img" style="min-height:130px;background:linear-gradient(135deg,#e2e8f0,#cbd5e1)"><span style="font-size:30px">🗺️</span></div></div></div>';
        case 'stats-inline':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' stats-strip-blk">' . $head . '<div class="stats-strip">' . pvStatStrip($props) . '</div></div>';
        default:
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">📦 ' . e($block) . '</div><div class="fake-lines"><div class="fl w90"></div><div class="fl w70"></div></div></div>';
    }
}

/**
 * 🏛 رندگر سطح-بهدار — پشتیبانی بخش‌های چندستونی تودرتو (v3.0)
 */
function renderLayoutLevel(array $items): string
{
    $html = '';
    foreach ($items as $item) {
        $block = (string)($item['block'] ?? '');
        if ($block === '_page') { continue; } /* 🛡️ گره تنظیمات صفحه — در بلوک ترکیبی هم نادیده گرفته می‌شود */
        $props = (array)($item['props'] ?? []);
        if ($block === 'section-columns' || $block === 'section-split') {
            $colCount = $block === 'section-split' ? 2 : max(2, min(4, (int)($props['columns'] ?? 2)));
            $cols = $item['cols'] ?? [];
            if (!is_array($cols)) {
                $cols = [];
            }
            $inner = '';
            for ($c = 0; $c < $colCount; $c++) {
                $colItems = is_array($cols[$c] ?? null) ? $cols[$c] : [];
                $colHtml = renderLayoutLevel($colItems);
                $inner .= '<div class="pv-col">' . ($colHtml !== '' ? $colHtml : '<div class="pv-col-empty">ستون ' . ($c + 1) . ' خالی است</div>') . '</div>';
            }
            $html .= renderPreviewBlock($block, $props) . '<div class="pv-cols" style="--pv-n:' . $colCount . '">' . $inner . '</div>';
        } else {
            $html .= renderPreviewBlock($block, $props);
        }
    }
    return $html;
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
<?= preview_font_html() /* 🔤 v2.14: فونت انتخابی سیستم — مثل سایت نهایی */ ?>
<style>
:root {
    --p: #1e40af; --p-light: #dbeafe; --s: #0ea5e9; --a: #f59e0b;
    --bg: #f8fafc; --card: #fff; --text: #1e293b; --muted: #64748b; --border: #e2e8f0;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--font-body, Vazirmatn, Tahoma, 'Segoe UI', sans-serif); background: var(--bg); color: var(--text); line-height: 1.95; font-size: 14px; }
/* 🔤 تیترها و عناصر تاکیدی با فونت تیتر انتخابی (مثل سایت واقعی) */
.blk-title, .hero-title, .card-t, .page-title, .topbar-blk, .notif-bar, .price-row b, .stat-n, .cta-num, .story-year, .num-n, .fake-cta, .hero-btn { font-family: var(--font-heading, Vazirmatn, Tahoma, sans-serif); }
.preview-wrap { max-width: 100%; margin: 0 auto; }

/* بلوک‌ها */
.blk { background: var(--card); padding: 26px 20px; border-bottom: 1px dashed var(--border); }
.blk:last-child { border-bottom: none; }
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
.header-blk { padding: 14px 18px; }
.header-blk .h-row { display: flex; align-items: center; gap: 14px; }
.header-blk.glass { background: rgba(255,255,255,.85); backdrop-filter: blur(9px); }
.header-blk.sticky-demo { outline: 1.5px dashed #2563eb; outline-offset: -6px; }
.fake-logo { font-size: 22px; }
.fake-nav { display: flex; gap: 16px; font-size: 13px; color: var(--muted); flex: 1; flex-wrap: wrap; }
.fake-cta { background: var(--p); color: #fff; font-size: 12px; padding: 7px 15px; border-radius: 9px; white-space: nowrap; }
.topbar-blk { display: flex; justify-content: space-between; font-size: 11.5px; color: var(--muted); padding: 7px 16px; background: #f1f5f9; flex-wrap: wrap; gap: 6px; }
.tb-row { display: flex; justify-content: space-between; font-size: 11px; color: var(--muted); flex-wrap: wrap; gap: 6px; }

/* هیرو */
.hero-blk { background: linear-gradient(135deg, var(--p), var(--s)); color: #fff; text-align: center; }
.hero-blk.split-hero { text-align: right; }
.hero-title { font-size: 21px; font-weight: 800; margin-bottom: 8px; }
.hero-sub { font-size: 13px; opacity: .88; margin-bottom: 18px; }
.hero-btns { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
.hero-blk.split-hero .hero-btns { justify-content: flex-start; }
.hero-btn { background: var(--a); border-radius: 10px; padding: 9px 22px; font-size: 13px; font-weight: 700; display: inline-block; }
.hero-btn.ghost { background: transparent; border: 1.5px solid rgba(255,255,255,.65); }
.hero-btn.full { width: 100%; text-align: center; }
.hero-img { flex: 1 1 200px; height: 130px; background: rgba(255,255,255,.14); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 34px; }
.hero-img.wide { width: 100%; flex: none; height: 150px; margin-bottom: 9px; }
.hero-split { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
.hero-split > div:first-child { flex: 1 1 240px; }
.slider-dots { letter-spacing: 5px; font-size: 11px; opacity: .8; text-align: center; margin-top: 6px; }
.play { width: 54px; height: 54px; border-radius: 50%; background: rgba(255,255,255,.2); display: flex; align-items: center; justify-content: center; font-size: 20px; margin: 12px auto; }
.count-row { display: flex; gap: 10px; justify-content: center; }
.count-box { background: rgba(255,255,255,.15); border-radius: 10px; padding: 9px 16px; font-size: 11px; }
.count-box b { display: block; font-size: 20px; }

/* متن و کارت */
.pv-text { font-size: 13.5px; line-height: 2.1; color: #334155; }
.pv-list { margin: 0 20px 0 0; font-size: 13px; line-height: 2.2; }
.fake-lines .fl { height: 10px; border-radius: 5px; background: #e2e8f0; margin: 8px 0; }
.w40 { width: 40%; } .w60 { width: 60%; } .w70 { width: 70%; } .w75 { width: 75%; } .w80 { width: 80%; } .w90 { width: 90%; } .w100 { width: 100%; }
.split { display: flex; gap: 22px; align-items: center; flex-wrap: wrap; }
.split > div:first-child { flex: 1 1 260px; }
.fake-img { flex: 1 1 180px; height: 150px; background: var(--p-light); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 36px; }
.fake-img.small { height: 90px; font-size: 26px; width: 100%; flex: none; }
.fake-img.wide { flex: none; }
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

/* 🏛 ستون‌های بخش چندستونی */
.pv-cols { display: grid; grid-template-columns: repeat(var(--pv-n, 2), 1fr); gap: 14px; padding: 16px 20px 20px; background: #f8fafc; border-bottom: 1px dashed var(--border); }
.pv-col { display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.pv-col .blk { border: 1px solid var(--border); border-radius: 12px; }
.pv-col .blk:first-child:last-child { }
.pv-col-empty { border: 2px dashed #cbd5e1; border-radius: 10px; color: #94a3b8; font-size: 11.5px; text-align: center; padding: 18px 8px; }

/* فرم و آمار */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; max-width: 640px; margin: 0 auto; }
.fake-input { background: #f8fafc; border: 1.5px solid var(--border); border-radius: 9px; padding: 10px 13px; font-size: 12px; color: var(--muted); }
.news-row { display: flex; gap: 9px; max-width: 520px; margin: 0 auto; }
.stats-blk { display: flex; justify-content: space-around; flex-wrap: wrap; gap: 18px; background: linear-gradient(135deg, #0f172a, #1e3a8a); color: #fff; }
.stat { text-align: center; }
.stat-n { font-size: 26px; font-weight: 800; color: #93c5fd; }
.stat-l { font-size: 12px; opacity: .85; }
.pbar { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; font-size: 12.5px; }
.pbar span { flex: 0 0 128px; }
.track { flex: 1; height: 9px; background: #e2e8f0; border-radius: 9px; overflow: hidden; }
.fill { height: 100%; background: linear-gradient(90deg, var(--p), var(--s)); border-radius: 9px; }

/* تعامل */
.quote { background: var(--card); border: 1px solid var(--border); border-inline-start: 4px solid var(--p); border-radius: 11px; padding: 17px 19px; font-size: 13px; max-width: 560px; margin: 0 auto 10px; }
.quote-blk .quote { font-size: 16px; font-weight: 800; text-align: center; max-width: 620px; }
.acc { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 12px 16px; margin-bottom: 9px; font-size: 13px; display: flex; justify-content: space-between; align-items: center; max-width: 640px; margin-inline: auto; }
.tabs-row { display: flex; gap: 6px; justify-content: center; margin-bottom: 12px; }
.tab { font-size: 12px; padding: 6px 16px; border-radius: 8px; border: 1px solid var(--border); color: var(--muted); }
.tab.cur { background: var(--p); color: #fff; border-color: var(--p); }
.tl { max-width: 520px; margin: 0 auto; }
.tl-item { display: flex; gap: 11px; align-items: center; padding: 8px 0; opacity: .45; font-size: 12.5px; }
.tl-item.done, .tl-item.cur { opacity: 1; }
.tl-dot { width: 26px; height: 26px; border-radius: 50%; background: #e2e8f0; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #475569; flex: 0 0 26px; }
.tl-item.done .tl-dot { background: #16a34a; color: #fff; }
.tl-item.cur .tl-dot { background: #2563eb; color: #fff; }
.steps-row { display: flex; gap: 9px; align-items: center; justify-content: center; flex-wrap: wrap; }
.step { background: var(--card); border: 1px solid var(--border); border-radius: 11px; padding: 12px 16px; text-align: center; }
.step-n { width: 26px; height: 26px; border-radius: 50%; background: var(--p); color: #fff; display: flex; align-items: center; justify-content: center; margin: 0 auto 6px; font-size: 13px; }
.step-t { font-size: 11.5px; font-weight: 700; }
.step-arrow { color: #94a3b8; font-size: 16px; }
.feat-list { display: flex; flex-direction: column; gap: 11px; max-width: 640px; margin: 0 auto; }
.feat-row { display: flex; gap: 12px; align-items: flex-start; }
.feat-ico { width: 38px; height: 38px; border-radius: 10px; background: var(--p-light); display: flex; align-items: center; justify-content: center; font-size: 18px; flex: 0 0 38px; }
.feat-d { font-size: 11.5px; color: var(--muted); }
.price-table { max-width: 600px; margin: 0 auto; }
.price-row { display: flex; justify-content: space-between; padding: 11px 16px; border-bottom: 1px solid var(--border); font-size: 13px; background: var(--card); }
.price-row:first-child { border-radius: 11px 11px 0 0; }
.price-row:last-child { border-radius: 0 0 11px 11px; border-bottom: none; }
.price-row b { color: var(--p); }

/* متفرقه */
.fake-map { height: 170px; background: repeating-linear-gradient(45deg, #eef2ff, #eef2ff 12px, #e0e7ff 12px, #e0e7ff 24px); border-radius: 13px; display: flex; align-items: center; justify-content: center; color: var(--p); font-weight: 700; }
.fake-logo-s { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 13px; font-size: 21px; text-align: center; }
.cta-blk { background: linear-gradient(135deg, var(--p), var(--s)); color: #fff; text-align: center; }
.cta-num { font-size: 25px; font-weight: 800; margin-top: 6px; letter-spacing: 1px; }
.crumb { font-size: 12px; color: var(--muted); padding: 11px 18px; background: #f8fafc; }
.blk-sep { border: none; border-top: 1px solid var(--border); margin: 6px 0; }
.blk-spacer { background: repeating-linear-gradient(45deg, #f8fafc, #f8fafc 10px, #f1f5f9 10px, #f1f5f9 20px); }
.alert-demo { border-radius: 10px; padding: 11px 15px; font-size: 12.5px; font-weight: 600; }
.alert-demo.info { background: #eff6ff; color: #1d4ed8; }
.alert-demo.warning { background: #fffbeb; color: #b45309; }
.alert-demo.success { background: #f0fdf4; color: #15803d; }
.sticky-cta-demo { display: flex; justify-content: space-between; align-items: center; background: #0f172a; color: #fff; }
.footer-blk { background: #0f172a; color: #e2e8f0; }
.footer-blk .fake-nav { color: #94a3b8; }
.soc-row { display: flex; gap: 12px; justify-content: center; font-size: 11px; color: #94a3b8; margin-top: 9px; }
.crump-blk { text-align: center; font-size: 11.5px; color: var(--muted); background: #f8fafc; }

/* ════════ 🆕 v2.12 + v3.3: استایل عناصر جدید — پیش‌نمایش واقعی ════════ */
.chip-row { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
.chip { background: var(--card); border: 1px solid var(--border); border-radius: 20px; padding: 4px 13px; font-size: 11.5px; color: var(--text); }
.blk-bg-primary .chip, .blk-bg-dark .chip, .blk-bg-gradient .chip { background: rgba(255,255,255,.14); border-color: rgba(255,255,255,.25); color: #fff; }
.notif-bar { text-align: center; font-weight: 700; font-size: 13px; }
.notif-bar.info { background: #eff6ff; color: #1e40af; }
.notif-bar.success { background: #f0fdf4; color: #15803d; }
.notif-bar.warning { background: #fffbeb; color: #b45309; }
.marquee-blk { overflow: hidden; background: #0f172a; color: #fff; }
.marquee-track { white-space: nowrap; animation: pvMarquee 14s linear infinite; padding: 9px 0; font-size: 12.5px; font-weight: 600; }
@keyframes pvMarquee { from { transform: translateX(-100%); } to { transform: translateX(100%); } }
.search-wrap { display: flex; align-items: center; gap: 9px; background: #fff; border: 1.5px solid var(--border); border-radius: 13px; padding: 7px 12px; max-width: 560px; margin: 0 auto; }
.search-ico { font-size: 16px; }
.story-wrap { display: flex; flex-direction: column; gap: 15px; max-width: 620px; margin: 0 auto; }
.story-sec { display: flex; gap: 14px; align-items: center; }
.story-year { background: var(--p); color: #fff; border-radius: 10px; padding: 5px 13px; font-weight: 800; font-size: 13px; white-space: nowrap; }
.ba-wrap { display: flex; gap: 13px; align-items: center; justify-content: center; flex-wrap: wrap; }
.ba-side { flex: 1; min-width: 200px; max-width: 300px; }
.ba-tag { display: inline-block; border-radius: 8px; font-size: 11px; font-weight: 800; padding: 2.5px 11px; margin-bottom: 6px; }
.ba-tag.bad { background: #fef2f2; color: #b91c1c; }
.ba-tag.ok { background: #f0fdf4; color: #15803d; }
.ba-arrow { font-size: 26px; color: var(--p); }
.pill-announce { display: flex; align-items: center; gap: 10px; background: #eff6ff; border: 1.5px solid #bfdbfe; color: #1e40af; border-radius: 40px; padding: 11px 20px; font-weight: 700; font-size: 13px; max-width: 640px; margin: 0 auto; }
.pill-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--p); box-shadow: 0 0 0 4px rgba(37,99,235,.18); flex: 0 0 9px; }
.num-list { display: flex; flex-direction: column; gap: 12px; max-width: 640px; margin: 0 auto; }
.num-row { display: flex; gap: 13px; align-items: flex-start; }
.num-n { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, var(--p), var(--s)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; flex: 0 0 34px; }
.info-box-demo { display: flex; gap: 13px; align-items: flex-start; background: #fffbeb; border: 1.5px solid #fde68a; border-radius: 12px; padding: 14px 16px; }
.soc-proof { display: flex; gap: 15px; align-items: center; justify-content: center; flex-wrap: wrap; }
.ava-stack { display: flex; }
.ava-stack .fake-ava { border: 2px solid #fff; box-shadow: 0 2px 8px rgba(0,0,0,.14); border-radius: 50%; }
.promo-card-demo { display: flex; gap: 18px; align-items: center; justify-content: space-between; flex-wrap: wrap; background: linear-gradient(135deg, #fff7ed, #ffedd5); border: 1.5px solid #fdba74; border-radius: 14px; padding: 20px 22px; }
.divider-ico { display: flex; align-items: center; gap: 12px; }
.divider-line { flex: 1; height: 1.5px; background: linear-gradient(90deg, transparent, #cbd5e1, #cbd5e1, transparent); }
.stars { color: #f59e0b; letter-spacing: 1px; }
.badge-info { background: #dbeafe; color: #1e40af; }
.badge-warning { background: #fef3c7; color: #92400e; }
.badge { border-radius: 20px; padding: 2px 10px; font-size: 10.5px; font-weight: 700; }
/* 🎛 v3.3: تنظیمات پیشرفته */
.blk-ts-sm .blk-title { font-size: 14px; }
.blk-ts-md .blk-title { font-size: 17px; }
.blk-ts-lg .blk-title { font-size: 21px; }
.blk-ts-xl .blk-title { font-size: 26px; }
/* 🎛 v2.14: اندازه عنوان روی تیترهای هیرو هم اثر بگذارد + تراز کامل‌تر */
.blk-ts-sm .hero-title { font-size: 15px; } .blk-ts-md .hero-title { font-size: 19px; }
.blk-ts-lg .hero-title { font-size: 24px; } .blk-ts-xl .hero-title { font-size: 29px; }
.blk-al-center { text-align: center; }
.blk-al-center .feat-list, .blk-al-center .num-list, .blk-al-center .price-table, .blk-al-center .form-grid, .blk-al-center .story-wrap, .blk-al-center .author-box-demo, .blk-al-center .feature-table-demo, .blk-al-center .steps-row { margin: 0 auto; }
.blk-al-center .hero-btns, .blk-al-center .chip-row { justify-content: center; }
.blk-al-end { text-align: left; }
.blk-w-wide { max-width: 1200px; margin-inline: auto; }
.blk-w-boxed { max-width: 960px; margin-inline: auto; }
.blk-w-narrow { max-width: 720px; margin-inline: auto; }

/* ════════ 🆕 v2.14: استایل ۱۲ عنصر جدید ════════ */
.stats-strip { display: flex; align-items: center; justify-content: space-around; flex-wrap: wrap; gap: 12px; background: linear-gradient(135deg, #0f172a, #1e3a8a); color: #fff; }
.stats-strip .ss-item { text-align: center; font-size: 11.5px; opacity: .92; }
.stats-strip .ss-item b { display: block; font-size: 23px; font-weight: 800; color: #93c5fd; }
.stats-strip .ss-sep { width: 1px; height: 34px; background: rgba(255,255,255,.25); }
.warning-box-demo { display: flex; gap: 13px; align-items: flex-start; background: #fef2f2; border: 1.5px solid #fecaca; border-radius: 12px; padding: 14px 16px; }
.brand-intro-demo, .download-card-demo { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; background: var(--card); border: 1.5px solid var(--border); border-radius: 14px; padding: 18px 20px; box-shadow: 0 4px 16px rgba(2,8,23,.06); }
.author-box-demo { display: flex; gap: 14px; align-items: flex-start; background: #f8fafc; border: 1.5px solid var(--border); border-radius: 13px; padding: 16px 18px; }
.price-highlight-demo { border: 2px solid var(--p); box-shadow: 0 10px 28px rgba(37,99,235,.15); }
.feature-table-demo { max-width: 640px; margin: 0 auto; border: 1.5px solid var(--border); border-radius: 12px; overflow: hidden; }
.feature-table-demo .ft-row { display: grid; grid-template-columns: 1.4fr 1fr 1fr 1fr; align-items: center; }
.feature-table-demo .ft-row > * { padding: 9px 10px; font-size: 12px; text-align: center; border-bottom: 1px solid var(--border); }
.feature-table-demo .ft-row:last-child > * { border-bottom: none; }
.feature-table-demo .ft-head { background: #0f172a; color: #fff; font-weight: 800; }
.feature-table-demo .ft-hl { background: #eff6ff; color: #1e40af; font-weight: 800; }
.feature-table-demo .ft-row span { text-align: right; font-weight: 700; }
.quick-form-demo { display: flex; gap: 9px; align-items: center; flex-wrap: wrap; background: var(--card); border: 1.5px solid var(--border); border-radius: 13px; padding: 12px 14px; max-width: 560px; margin: 0 auto; }

/* ════════ 🆕 v2.15: رنگ عنوان انتخابی + ۱۴ عنصر جدید ════════ */
.blk[style*="--blk-tc"] .blk-title, .blk[style*="--blk-tc"] .hero-title, .blk[style*="--blk-tc"] .card-t { color: var(--blk-tc) !important; }
.ticker-bar-demo { display: flex; align-items: center; gap: 10px; background: #0f172a; border-radius: 12px; padding: 10px 14px; color: #e2e8f0; overflow: hidden; }
.ticker-bar-demo .ticker-tag { background: #dc2626; color: #fff; font-size: 10.5px; font-weight: 800; border-radius: 20px; padding: 3px 11px; white-space: nowrap; }
.ticker-bar-demo .ticker-track { flex: 1; overflow: hidden; white-space: nowrap; font-size: 12px; }
.ticker-bar-demo .ticker-track span { display: inline-block; animation: tickMove 22s linear infinite; padding-inline-start: 100%; }
@keyframes tickMove { from { transform: translateX(-100%); } to { transform: translateX(0); } }
.cal-demo { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; max-width: 520px; margin: 0 auto; }
.cal-demo .cal-dow { text-align: center; font-size: 10.5px; font-weight: 800; color: var(--muted); padding: 4px 0; }
.cal-demo .cal-day { text-align: center; font-size: 11.5px; padding: 8px 0; border-radius: 8px; border: 1.5px solid var(--border); background: var(--card); }
.cal-demo .cal-day.busy { background: #fef2f2; border-color: #fecaca; color: #b91c1c; text-decoration: line-through; }
.cal-demo .cal-day.sel { background: #dcfce7; border-color: #16a34a; color: #14532d; font-weight: 800; }
.queue-demo { max-width: 560px; margin: 0 auto; display: flex; flex-direction: column; gap: 8px; }
.queue-demo .queue-row { display: flex; justify-content: space-between; align-items: center; background: var(--card); border: 1.5px solid var(--border); border-radius: 10px; padding: 10px 15px; font-size: 13px; }
.cap-demo { max-width: 600px; margin: 0 auto; display: flex; flex-direction: column; gap: 10px; }
.cap-demo .cap-row { display: flex; align-items: center; gap: 10px; font-size: 12px; }
.cap-demo .cap-h { min-width: 52px; font-weight: 800; }
.cap-demo .cap-l { min-width: 66px; color: var(--muted); text-align: left; }
.bas-demo { position: relative; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; align-items: stretch; }
.bas-demo .bas-before, .bas-demo .bas-after { position: relative; height: 150px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 13px; overflow: hidden; }
.bas-demo .bas-before { background: #f1f5f9; border: 1.5px dashed #94a3b8; }
.bas-demo .bas-after { background: linear-gradient(135deg, #dcfce7, #bbf7d0); border: 1.5px solid #16a34a; }
.bas-demo .bas-tag { position: absolute; top: 8px; right: 8px; background: rgba(15, 23, 42, .85); color: #fff; font-size: 10.5px; font-weight: 800; border-radius: 16px; padding: 3px 11px; }
.bas-demo .bas-tag.ok { background: #16a34a; }
.bas-demo .bas-handle { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 3; width: 38px; height: 38px; border-radius: 50%; background: #fff; border: 3px solid #2563eb; display: flex; align-items: center; justify-content: center; font-weight: 800; color: #2563eb; box-shadow: 0 4px 14px rgba(37, 99, 235, .35); }
.np-demo-wrap { text-align: center; }
.np-demo { display: inline-flex; flex-direction: column; text-align: right; background: var(--card); border: 1.5px solid var(--border); border-radius: 15px; padding: 18px 20px; box-shadow: 0 18px 44px rgba(2, 8, 23, .16); max-width: 430px; }
.ct-demo { display: flex; gap: 16px; align-items: center; background: linear-gradient(135deg, #eff6ff, #dbeafe); border: 1.5px solid #93c5fd; border-radius: 15px; padding: 18px 22px; }
.ct-demo .ct-score { font-size: 39px; font-weight: 900; color: #1e40af; line-height: 1; }
.ct-demo .ct-score small { font-size: 15px; color: #3b82f6; }
.fake-img img { border-radius: inherit; }

/* ═══ v2.17: CSS عناصر جدید ═══ */
.glass-hero-demo { background:rgba(255,255,255,.55); backdrop-filter:blur(9px); border:1px solid rgba(255,255,255,.75); border-radius:17px; padding:26px 24px; text-align:center; box-shadow:0 14px 34px rgba(2,6,23,.10); }
.logo-strip-demo { display:flex; gap:12px; justify-content:space-between; flex-wrap:wrap; opacity:.9; }
.text-cols-demo { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.text-cols-demo p { margin:0 0 9px; font-size:12.5px; line-height:2; }
.steps-compact-demo { display:flex; flex-direction:column; gap:9px; }
.sc-row { display:flex; gap:11px; align-items:center; background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:11px; padding:10px 13px; }
.sc-num { flex:none; width:30px; height:30px; border-radius:50%; background:#2563eb; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; }
.guarantee-demo { display:flex; gap:15px; align-items:center; background:linear-gradient(135deg,#ecfdf5,#f0fdfa); border:1.5px solid #a7f3d0; border-radius:14px; padding:17px 19px; }
.urgent-demo { display:flex; gap:14px; align-items:center; background:linear-gradient(135deg,#fef2f2,#fff7ed); border:1.5px solid #fecaca; border-radius:14px; padding:16px 18px; }
.faq-mini-demo { background:#f8fafc; border:1.5px solid #e2e8f0; border-inline-start:4px solid #2563eb; border-radius:11px; padding:14px 16px; }
.apt-compact-demo { background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:13px; padding:16px 18px; }
.hero-minimal-blk { text-align:center; }
.stats-strip { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; background:#f1f5f9; border-radius:11px; padding:12px 16px; }
.stats-strip .ss-item { font-size:12px; color:#334155; }
.stats-strip .ss-item b { font-size:16px; color:#2563eb; margin-inline-end:3px; }
.stats-strip .ss-sep { width:1px; height:22px; background:#cbd5e1; }
/* ⚙️ v2.25: تنظیمات صفحه — متغیرها روی body */
body { --pg-section-pad: 54px; --pg-gap: 26px; --pg-width: 1080px; --pg-radius: 14px; --pg-text: 14.5px; font-size: var(--pg-text); }
.preview-wrap { max-width: var(--pg-width); margin: 0 auto; padding: 16px; }
body[style*="--pg-bg"] { background: var(--pg-bg); }
.preview-wrap .blk { border-radius: var(--pg-radius); margin-bottom: var(--pg-gap); }
.preview-wrap .blk .blk-title { color: var(--pg-title, inherit); }
.blk-pad-default { padding-top: calc(var(--pg-section-pad) * .6); padding-bottom: calc(var(--pg-section-pad) * .6); }
.blk-pad-roomy { padding-top: var(--pg-section-pad); padding-bottom: var(--pg-section-pad); }
.blk-pad-compact { padding-top: calc(var(--pg-section-pad) * .38); padding-bottom: calc(var(--pg-section-pad) * .38); }
/* 😀 انتخابگر آیکون هم در پیش‌نمایش (فقط کتابخانه کوچک) */

@media (max-width: 640px) {
    .c2, .c3, .c4, .c6, .form-grid, .pv-cols { grid-template-columns: 1fr 1fr; }
    .c6 { grid-template-columns: repeat(3, 1fr); }
    .pv-cols { grid-template-columns: 1fr; }
    .hero-title { font-size: 17px; }
}
@media (max-width: 420px) {
    .c2, .c3, .c4, .c6, .form-grid { grid-template-columns: 1fr; }
    .fake-nav { display: none; }
}
</style>
</head>
<body<?= $pageStyle !== '' ? ' style="' . e($pageStyle) . '"' : '' ?>>
<div class="preview-wrap">
    <?php if (empty($layout)): ?>
        <div class="blk" style="text-align:center;color:var(--muted)">چیدمانی برای پیش‌نمایش وجود ندارد.</div>
    <?php else: ?>
        <?= renderLayoutLevel($layout) ?>
    <?php endif; ?>
</div>
</body>
</html>
