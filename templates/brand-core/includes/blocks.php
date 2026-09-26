<?php
/**
 * 🧱 رندرگر بلوک‌های قالب‌ساز روی سایت برند (v2.27)
 * ==================================================
 * 🚨 ریشه «تغییر تم در سایت‌ساز روی سایت برندها اعمال نمی‌شود (تکی و عمومی)»:
 * تم‌ها ترکیب قالب‌ها (چیدمان بلوک‌ها) هستند و API مسیر
 * brand/{id}/template/{page_type} را برگرداند — اما هیچ صفحه‌ای از سایت
 * برند آن را فراخوانی و رندر نمی‌کرد! صفحات سایت برند ساختار ثابت
 * هاردکد داشتند و تغییر تم/قالب هرگز اثری نداشت.
 *
 * ✅ اکنون: صفحات اصلی سایت برند (خانه/خدمات/مقالات/تماس/درباره) ابتدا
 * چیدمان را از API می‌گیرند؛ اگر بلوکی داشتند با این رندرگر (آینه PHP
 * پیش‌نمایش قالب‌ساز) نمایش می‌دهند و فقط در نبود چیدمان به ساختار
 * ثابت قبلی برمی‌گردند. عناصر شخصی (pelement) هم با استایل کامل
 * سایت مبدأ داخل iframe ایزوله رندر می‌شوند.
 *
 * @package SahandBrandSite
 */
if (!defined('BRAND_INIT')) { http_response_code(403); exit; }

/* ═══════════════════════════════════════════════════════════════
 * 🧰 توابع کمکی (آینه pv* در admin/template-preview.php)
 * ═══════════════════════════════════════════════════════════════ */
if (!function_exists('pvItems')) {
    /** ➕ نرمال‌سازی آیتم‌های لیست — همیشه [{icon,text,desc}]
     * 🆕 v2.27 — نرمال‌سازی «قبل از» فیلتر متن: آیتم‌های آرایه‌ای قدیمی
     * [['آیکون','متن','توضیح']] قبلاً پیش از نرمال‌سازی حذف می‌شدند! */
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
        $items = array_values(array_filter(
            $norm($props['items'] ?? []),
            static fn($i) => trim((string)$i['text']) !== ''
        ));
        return $items ?: $norm($fallback);
    }
}
if (!function_exists('pvCols')) {
    /** 🗂 تعداد ستون کارت‌ها از props (۲-۶) */
    function pvCols(array $props, int $default = 3): int
    {
        $c = (int)($props['columns'] ?? $default);
        return max(2, min(6, $c ?: $default));
    }
}
if (!function_exists('pvStatStrip')) {
    /** 📊 نوار آمار از آیتم‌ها (icon=عدد، text=برچسب) */
    function pvStatStrip(array $props): string
    {
        $items = pvItems($props, [['۱۲+', 'سال تجربه'], ['۵۰k', 'تعمیر'], ['۹۸٪', 'رضایت']]);
        $out = [];
        foreach (array_slice($items, 0, 8) as $i) {
            $out[] = '<span class="ss-item"><b>' . e($i['icon'] !== '' ? $i['icon'] : '۰') . '</b> ' . e($i['text'] ?: 'آمار') . '</span>';
        }
        return implode('<span class="ss-sep"></span>', $out);
    }
}
if (!function_exists('pvCountdown')) {
    /** ⏱ شمارش معکوس از زمان هدف (استاتیک سمت سرور) */
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
}
if (!function_exists('pvStyleVars')) {
    /** 🎨 استایل درون‌خطی — رنگ عنوان / رنگ گرادیانت انتخابی */
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
}
if (!function_exists('pvImg')) {
    /** 🖼 تصویر واقعی به‌جای ایموجی — مسیر uploads/ از سرور سایت‌ساز لود می‌شود */
    function pvImg(array $props, string $emoji, string $style = ''): string
    {
        $url = trim((string)($props['imageUrl'] ?? ''));
        if (preg_match('#^uploads/#i', $url)) {
            $url = cdn_asset($url);
        }
        if (preg_match('#^(https?://|/)#i', $url)) {
            return '<div class="fake-img" style="' . $style . '"><img src="' . e($url) . '" alt="" style="width:100%;height:100%;object-fit:cover;display:block"></div>';
        }
        return '<div class="fake-img" style="' . $style . '">' . $emoji . '</div>';
    }
}
if (!function_exists('pvItemImg')) {
    /** 🖼 تصویر آیتم گالری — text=آدرس تصویر، icon=ایموجی جایگزین */
    function pvItemImg(array $it, string $style = ''): string
    {
        $url = trim((string)($it['text'] ?? ''));
        if (preg_match('#^uploads/#i', $url)) {
            $url = cdn_asset($url);
        }
        if (preg_match('#^(https?://|/)#i', $url)) {
            return '<div class="fake-img small" style="' . ($style !== '' ? $style : 'min-height:90px') . '"><img src="' . e($url) . '" alt="" style="width:100%;height:100%;object-fit:cover;display:block"></div>';
        }
        return '<div class="fake-img small" style="' . ($style !== '' ? $style : 'min-height:90px') . '">' . e($it['icon'] ?: '🖼️') . '</div>';
    }
}
if (!function_exists('pv_variant_classes')) {
    /** 🎭 کلاس‌های ظواهر متعدد — آینه PHP variantClasses قالب‌ساز */
    function pv_variant_classes(array $props): string
    {
        $v = trim((string)($props['variant'] ?? ''));
        $b = trim((string)($props['btnStyle'] ?? ''));
        $h = trim((string)($props['hoverFx'] ?? ''));
        $out = [];
        if ($v !== '' && $v !== 'default') { $out[] = 'blk-var-' . $v; }
        if ($b !== '' && $b !== 'default') { $out[] = 'blk-btn-' . $b; }
        if ($h !== '' && $h !== 'none') { $out[] = 'blk-hover-' . $h; }
        return implode(' ', $out);
    }
}
if (!function_exists('pv_pelement_doc')) {
    /** 🖼 سند مستقل عنصر شخصی (iframe srcdoc) — استایل کامل سایت مبدأ */
    function pv_pelement_doc(array $el): string
    {
        $css = str_replace('<', '\3C ', (string)($el['css'] ?? ''));
        return '<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<style>*{box-sizing:border-box}body{margin:0;padding:14px;background:transparent;font-family:Vazirmatn,Tahoma,sans-serif}img{max-width:100%;height:auto}a{text-decoration:none}'
            . $css . '</style></head><body>' . (string)($el['html'] ?? '') . '</body></html>';
    }
}

/* ═══════════════════════════════════════════════════════════════
 * 🎨 رندر یک بلوک — آینه کامل renderPreviewBlock در پیش‌نمایش قالب‌ساز
 * $pelements: عناصر شخصی از API (id => [name, html, css])
 * ═══════════════════════════════════════════════════════════════ */
if (!function_exists('bb_render_block')) {

    function bb_render_block(string $block, array $props = [], array $pelements = []): string
    {
        $html = bb_render_block_inner($block, $props, $pelements);

        /* بلوک‌های ساختاری بدون .blk رندر می‌شوند — تزریقی ندارند */
        if (!preg_match('#^<div class="blk #', $html)) {
            return $html;
        }
        $sizeCls  = 'blk-ts-' . (($props['titleSize'] ?? 'md') ?: 'md');
        $alignCls = isset($props['align']) && $props['align'] !== 'start' && $props['align'] !== '' ? 'blk-al-' . $props['align'] : '';
        $widthCls = isset($props['width']) && $props['width'] !== 'full' && $props['width'] !== '' ? 'blk-w-' . $props['width'] : '';
        $custom   = preg_replace('/[^a-zA-Z0-9\-_\s]/', '', (string)($props['customClass'] ?? ''));
        $padCls   = 'blk-pad-' . (($props['padding'] ?? 'default') ?: 'default');
        $bgCls    = 'blk-bg-' . (($props['background'] ?? 'default') ?: 'default');
        $varCls   = pv_variant_classes($props);
        $inject = [];
        foreach (array_filter([$sizeCls, $alignCls, $widthCls, $custom, $padCls, $bgCls, $varCls]) as $cls) {
            if (strpos($html, $cls) === false) {
                $inject[] = $cls;
            }
        }
        if ($inject) {
            $html = preg_replace('#^<div class="blk #', '<div class="blk ' . implode(' ', $inject) . ' ', $html, 1);
        }
        $vars = pvStyleVars($props);
        if ($vars !== '' && preg_match('#--blk-tc|--blk-grad#', $html) === 0) {
            if (preg_match('#^(<div class="blk [^>]*?)style="([^"]*)"#', $html, $sm)) {
                $html = preg_replace('#^(<div class="blk [^>]*?)style="[^"]*"#', '$1style="' . $sm[2] . ';' . $vars . '"', $html, 1);
            } else {
                $html = preg_replace('#^<div class="blk ([^>]*)>#', '<div class="blk $1" style="' . $vars . '">', $html, 1);
            }
        }
        return $html;
    }

    function bb_render_block_inner(string $block, array $props, array $pelements): string
    {
        if ($block === '_page') { return ''; }
        /* بلوک پنهان — روی سایت واقعی اصلاً رندر نمی‌شود */
        if (isset($props['visible']) && $props['visible'] === false) { return ''; }
        $title = $props['title'] ?? '';
        $bg = $props['background'] ?? 'default';
        $bgClass = 'blk-bg-' . ($bg ?: 'default');
        $pad = $props['padding'] ?? 'default';
        $padClass = 'blk-pad-' . ($pad ?: 'default');
        $sizeCls = 'blk-ts-' . ($props['titleSize'] ?? 'md');
        $alignCls = isset($props['align']) && $props['align'] !== 'start' && $props['align'] !== '' ? 'blk-al-' . $props['align'] : '';
        $widthCls = isset($props['width']) && $props['width'] !== 'full' && $props['width'] !== '' ? 'blk-w-' . $props['width'] : '';
        $customCls = preg_replace('/[^a-zA-Z0-9\-_\s]/', '', (string)($props['customClass'] ?? ''));
        $extraCls = $sizeCls . ' ' . $alignCls . ' ' . $widthCls . ' ' . $customCls;
        $head = $title ? '<div class="blk-title">' . e($title) . '</div>' : '';
        $blkStyle = pvStyleVars($props);
        $styleAttr = $blkStyle !== '' ? ' style="' . $blkStyle . '"' : '';
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
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="pv-text">' . nl2br(e($props['text'] ?? '')) . '</div></div>';
            case 'text-image':
            case 'intro':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' split"' . $styleAttr . '><div><div class="blk-title">' . ($title ?: 'درباره برند') . '</div><div class="pv-text" style="font-size:13px">' . nl2br(e($props['text'] ?? '')) . '</div></div>' . pvImg($props, '🖼️') . '</div></div>';
            case 'rich-text': {
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
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . ($head ?: '') . '</div>';
            case 'feature-list':
                $its = pvItems($props, [['⚡', 'سرعت عمل', 'اعزام تکنسین در کمتر از ۲ ساعت'], ['🛡️', 'ضمانت کتبی', '۶ ماه ضمانت قطعه و خدمات']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="feat-list">' . implode('', array_map(static fn($it) => '<div class="feat-row"><span class="feat-ico">' . e($it['icon'] ?: '⚡') . '</span><div><b>' . e($it['text']) . '</b>' . ($it['desc'] !== '' ? '<div class="feat-d">' . e($it['desc']) . '</div>' : '') . '</div></div>', $its)) . '</div></div>';
            case 'services-grid':
            case 'features':
                $its = pvItems($props, [['🔧', 'تعمیر لباسشویی', 'با قطعات فابریک'], ['🧊', 'تعمیر یخچال', 'همان روز'], ['⚡', 'تعمیر ماکروویو', 'ضمانت‌دار'], ['🎓', 'سرویس دوره‌ای', 'در محل شما']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: ($block === 'features' ? 'چرا ما را انتخاب کنید؟' : 'خدمات ما')) . '</div><div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="card-ico">' . e($it['icon'] ?: '🔧') . '</div><div class="card-t">' . e($it['text']) . '</div>' . ($it['desc'] !== '' ? '<div class="feat-d">' . e($it['desc']) . '</div>' : '') . '</div>', $its)) . '</div></div>';
            case 'devices-grid':
                $devs = ['🌀 لباسشویی', '🧊 یخچال', '🍽️ ظرفشویی', '❄️ کولر', '📺 تلویزیون', '♨️ پکیج', '📻 مایکروویو', '🔥 فر و اجاق'];
                $cards = '';
                foreach ($devs as $d) {
                    [$ico, $name] = array_pad(explode(' ', $d, 2), 2, '');
                    $cards .= '<div class="fake-card"><div class="card-ico">' . $ico . '</div><div class="card-t">' . $name . '</div></div>';
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'دستگاه‌های تحت پوشش') . '</div><div class="cols c4">' . $cards . '</div></div>';
            case 'articles-recent':
            case 'articles-grid':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'مقالات اخیر') . '</div><div class="cols c3">' . str_repeat('<div class="fake-card"><div class="fake-img small">📰</div><div class="card-t">عنوان مقاله نمونه</div></div>', 3) . '</div></div>';
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
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' stats-blk">' . pvStatStrip($props) . '</div>';
            case 'progress-bars':
            case 'skill-bars':
                $its = pvItems($props, [['', 'سرعت تعمیر', '90'], ['', 'کیفیت قطعات', '95'], ['', 'رضایت مشتری', '98']]);
                $out = '';
                foreach ($its as $it) { $p = max(3, min(100, (int)(preg_replace('/[^0-9]/', '', $it['desc'] ?: $it['icon']) ?: 80))); $out .= '<div class="pbar"><span>' . e($it['text']) . '</span><div class="track"><div class="fill" style="width:' . $p . '%"></div></div></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . $out . '</div>';
            case 'testimonials': {
                $its = pvItems($props, [['علی محمدی', 'سرویس سریع و منظم بود؛ راضی بودم.'], ['مریم احمدی', 'قیمت شفاف و ضمانت واقعی.']]);
                $q = $its[0] ?? ['icon' => '', 'text' => ''];
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'نظرات مشتریان') . '</div><div class="quote">«' . e($q['text']) . '»</div>' . ($q['icon'] !== '' ? '<div class="feat-d" style="text-align:center;font-weight:800">— ' . e($q['icon']) . '</div>' : '') . '<div class="slider-dots">● ○ ○</div></div>';
            }
            case 'faq-accordion':
                $its = pvItems($props, [['', 'هزینه عیب‌یابی چقدر است؟', 'در صورت تعمیر نزد ما رایگان است.'], ['', 'چقدر طول می‌کشد؟', 'اکثر تعمیرها همان روز انجام می‌شود.'], ['', 'ضمانت دارید؟', 'بله — ۶ ماه ضمانت کتبی.']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'سوالات متداول') . '</div>' . implode('', array_map(static fn($it) => '<div class="acc">' . e($it['text']) . ' <b>＋</b></div>', $its)) . '</div>';
            case 'tabs': {
                $its = pvItems($props, [['', 'تعمیر'], ['', 'سرویس'], ['', 'نصب']]);
                $tabs = '';
                foreach ($its as $i => $it) { $tabs .= '<span class="tab' . ($i === 0 ? ' cur' : '') . '">' . e($it['text']) . '</span>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'تب‌بندی محتوا') . '</div><div class="tabs-row">' . $tabs . '</div><div class="fake-card" style="text-align:right"><div class="fl w100"></div><div class="fl w90"></div><div class="fl w60"></div></div></div>';
            }
            case 'timeline': {
                $its = pvItems($props, [['✓', 'ثبت درخواست', 'انجام شد'], ['✓', 'عیب‌یابی و پیش‌فاکتور', 'انجام شد'], ['۳', 'تعمیر در حال انجام', 'در جریان'], ['۴', 'تحویل و ضمانت', 'در انتظار']]);
                $out = '';
                foreach ($its as $i => $it) { $cls = $i < 2 ? ' done' : ($i === 2 ? ' cur' : ''); $out .= '<div class="tl-item' . $cls . '"><span class="tl-dot">' . e($it['icon'] ?: (string)($i + 1)) . '</span><div>' . e($it['text']) . ($it['desc'] !== '' ? '<div class="feat-d">' . e($it['desc']) . '</div>' : '') . '</div></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'مراحل پیشرفت کار') . '</div><div class="tl">' . $out . '</div></div>';
            }
            case 'steps-process': {
                $its = pvItems($props, [['', 'تماس/ثبت درخواست'], ['', 'اعزام تکنسین'], ['', 'تعمیر و تست']]);
                $out = '';
                foreach ($its as $i => $it) { $out .= ($i > 0 ? '<div class="step-arrow">←</div>' : '') . '<div class="step"><span class="step-n">' . strtr((string)($i + 1), ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']) . '</span><div class="step-t">' . e($it['text']) . '</div></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'فرآیند کار ما') . '</div><div class="steps-row">' . $out . '</div></div>';
            }
            case 'gallery': {
                $gcols = pvCols($props, 4);
                $its = array_values(array_filter(pvItems($props, []), static fn($i) => trim($i['text']) !== ''));
                $gimgs = '';
                if ($its) { foreach (array_slice($its, 0, $gcols + 3) as $it) { $gimgs .= pvItemImg($it); } }
                else { $gimgs = str_repeat(pvImg($props, '🖼️', 'min-height:90px'), $gcols + 2); }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'گالری') . '</div><div class="cols c' . $gcols . '">' . $gimgs . '</div></div>';
            }
            case 'image-carousel': {
                $its = array_values(array_filter(pvItems($props, []), static fn($i) => trim($i['text']) !== ''));
                $first = $its[0] ?? null;
                $dots = $its ? implode(' ', array_map(static fn($i) => '○', $its)) : '● ○ ○';
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'کاروسل تصاویر') . '</div><div style="position:relative">' . ($first ? pvItemImg($first, 'min-height:170px') : pvImg($props, '🎠', 'min-height:170px')) . '</div><div class="slider-dots">' . ($its ? '● ' . $dots : '● ○ ○') . '</div></div>';
            }
            case 'video-embed': {
                $vu = trim((string)($props['videoUrl'] ?? ''));
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'ویدیوی آموزشی') . '</div><div style="position:relative">' . pvImg($props, '🎬', 'min-height:190px') . '<div class="play">▶</div>' . ($vu !== '' ? '<a href="' . e($vu) . '" target="_blank" rel="noopener" style="position:absolute;bottom:8px;inset-inline-start:8px;background:rgba(15,23,42,.82);color:#fff;border-radius:9px;padding:5px 12px;font-size:10.5px;text-decoration:none" dir="ltr">▶ پخش ویدیو</a>' : '') . '</div></div>';
            }
            case 'map': {
                $mu = trim((string)($props['mapUrl'] ?? ''));
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'محدوده خدمات') . '</div><div class="fake-map">' . e($props['text'] ?? '📍 نقشه محدوده خدمات') . ($mu !== '' ? ' — <a href="' . e($mu) . '" target="_blank" rel="noopener" style="color:var(--color-primary)">مشاهده در نقشه ↗</a>' : '') . '</div></div>';
            }
            case 'cta-phone':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' cta-blk"><div class="hero-title">' . ($title ?: 'همین حالا تماس بگیرید') . '</div><div class="cta-num" dir="ltr">' . e($props['phone'] ?? '۰۲۱-۱۲۳۴۵۶۷۸') . '</div></div>';
            case 'cta-request':
            case 'cta-banner':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' cta-blk"><div class="hero-title">' . ($title ?: 'درخواست تعمیر خود را ثبت کنید') . '</div><span class="hero-btn">' . e($props['btnText'] ?? '📝 ثبت درخواست') . '</span></div>';
            case 'sticky-mobile-cta':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' sticky-cta-demo"><span>📞 ' . e($props['phone'] ?? '۰۲۱-۱۲۳۴۵۶۷۸') . '</span><span class="hero-btn">' . e($props['btnText'] ?? 'ثبت درخواست') . '</span></div>';
            case 'breadcrumb': {
                $crumbs = pvItems($props, [['', 'خانه', ''], ['', 'خدمات', '']]);
                $crumbHtml = '';
                foreach ($crumbs as $c) { $crumbHtml .= ($crumbHtml !== '' ? ' / ' : '') . e($c['text']); }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' crumb">' . $crumbHtml . ' / <b>صفحه فعلی</b></div>';
            }
            case 'alert-notice': {
                $type = $props['alertType'] ?? 'info';
                $ico = ['info' => 'ℹ️', 'warning' => '⚠️', 'success' => '✅'][$type] ?? 'ℹ️';
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="alert-demo ' . e($type) . '">' . $ico . ' ' . e($props['text'] ?? 'سرویس در تعطیلات نیز پاسخگوی شماست') . '</div></div>';
            }
            case 'button-group': {
                $its = pvItems($props, [['', 'تماس فوری'], ['', 'مشاهده خدمات'], ['', 'مقالات']]);
                $btnTxt = trim((string)($props['btnText'] ?? ''));
                if ($btnTxt !== '' && !in_array($btnTxt, array_column($its, 'text'), true)) { array_unshift($its, ['icon' => '', 'text' => $btnTxt, 'desc' => '']); }
                $out = '';
                foreach ($its as $i => $it) { $out .= '<span class="hero-btn' . ($i ? ' ghost' : '') . '">' . e($it['text']) . '</span>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="hero-btns" style="justify-content:flex-start">' . $out . '</div></div>';
            }
            case 'icon-list':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="feat-list"><div class="feat-row"><span class="feat-ico">📞</span><div><b>پاسخگویی تلفنی</b><div class="feat-d">۷ روز هفته از ۹ تا ۲۰</div></div></div><div class="feat-row"><span class="feat-ico">📍</span><div><b>اعزام در محل</b><div class="feat-d">کل تهران و کرج</div></div></div></div></div>';
            case 'separator':
                return '<hr class="blk-sep">';
            case 'spacer':
                return '<div class="blk-spacer" style="height:' . (int)($props['height'] ?? 46) . 'px"></div>';
            case 'footer-simple': {
                $fl = pvItems($props, [['', 'خدمات', ''], ['', 'مقالات', ''], ['', 'تماس', '']]);
                $flHtml = '';
                foreach ($fl as $l) { $flHtml .= '<span>' . e($l['text']) . '</span>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' footer-blk"><div class="fake-logo">🏗️</div><nav class="fake-nav" style="justify-content:center">' . $flHtml . '</nav><div class="soc-row"><span> Telegram </span><span> Instagram </span><span> WhatsApp </span></div>' . (!empty($props['phone']) ? '<div class="feat-d" style="text-align:center;margin-top:6px">📞 ' . e($props['phone']) . '</div>' : '') . '</div>';
            }
            case 'footer-contact':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' footer-blk"><div class="cols c3"><div><div class="fake-logo">🏗️</div></div><div><div class="card-t">تماس</div><div class="feat-d">📞 ۰۲۱-۱۲۳۴۵۶۷۸<br>📍 تهران، خیابان نمونه</div></div><div><div class="card-t">ساعات کاری</div><div class="feat-d">شنبه تا پنجشنبه<br>۹ صبح تا ۸ شب</div></div></div></div>';
            case 'copyright':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' crump-blk">' . e($props['text'] ?? '© تمامی حقوق برای نمایندگی محفوظ است') . '</div>';
            case 'notification-bar':
                return '<div class="blk notif-bar ' . e($props['notifColor'] ?? 'info') . '" style="padding:8px 14px">' . e($props['text'] ?? '🎉 سرویس ویژه تعطیلات') . '</div>';
            case 'hero-form':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk split-hero"' . $styleAttr . '><div class="hero-split"><div><div class="hero-title">' . ($title ?: 'درخواست تعمیر آنلاین') . '</div><div class="hero-sub">' . e($props['subtitle'] ?? 'فرم را پر کنید — کارشناسان ما تماس می‌گیرند') . '</div><div class="hero-btns"><span class="hero-btn">📞 تماس فوری</span></div></div><div class="fake-card" style="text-align:right;background:rgba(255,255,255,.14);border:none"><div class="fake-input">نام و شماره تماس</div><div class="fake-input">نوع دستگاه</div><div class="hero-btn full" style="margin-top:8px">' . e($props['btnText'] ?? 'ثبت درخواست') . '</div></div></div></div>';
            case 'hero-marquee':
                return '<div class="blk marquee-blk"><div class="marquee-track"><span>' . e($props['text'] ?? '⚡ اعزام تکنسین در کمتر از ۲ ساعت — ⭐ بیش از ۵۰ هزار تعمیر موفق') . '</span></div></div>';
            case 'brand-story': {
                $its = pvItems($props, [['۱۳۸۵', 'شروع فعالیت', 'با یک تعمیرگاه کوچک'], ['۱۳۹۲', 'نمایندگی رسمی', 'اخذ گواهی‌های تخصصی'], ['۱۴۰۲', '۵۰ هزارمین تعمیر', 'و بیش از ۳۰ همکار']]);
                $out = '';
                foreach ($its as $it) { $out .= '<div class="story-sec"><span class="story-year">' . e($it['icon']) . '</span><div><b>' . e($it['text']) . '</b><div class="feat-d">' . e($it['desc']) . '</div></div></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="story-wrap">' . $out . '</div></div>';
            }
            case 'area-list':
                $its = pvItems($props, [['', 'سعادت‌آباد'], ['', 'پونک'], ['', 'ولنجک'], ['', 'تجریش'], ['', 'شهرک غرب'], ['', 'نیاوران']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="chip-row">' . implode('', array_map(static fn($a) => '<span class="chip">📍 ' . e($a['text']) . '</span>', $its)) . '</div></div>';
            case 'checklist':
                $its = pvItems($props, [['☑️', 'دستگاه را روشن و خاموش کنید و دوباره امتحان کنید'], ['☑️', 'کد خطای نمایشگر را یادداشت کنید'], ['☑️', 'صداهای غیرعادی و بوی سوختگی را بررسی کنید'], ['☑️', 'فاکتور خرید و گارانتی را آماده داشته باشید']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<div class="feat-list">' . implode('', array_map(static fn($it) => '<div class="feat-row"><span class="feat-ico">' . e($it['icon'] ?: '☑️') . '</span><div>' . e($it['text']) . '</div></div>', $its)) . '</div></div>';
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
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'در یک نگاه') . '</div><div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card" style="text-align:center"><div class="stat-n">' . e($it['icon'] ?: '۰') . '</div><div class="feat-d">' . e($it['text']) . '</div></div>', $its)) . '</div></div>';
            case 'before-after':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'نتیجه تعمیر حرفه‌ای') . '</div><div class="ba-wrap"><div class="ba-side"><div class="ba-tag bad">قبل</div><div class="fake-card" style="text-align:center">' . e($props['text'] ?? 'دستگاه روشن نمی‌شود — کد خطا فعال') . '</div></div><div class="ba-arrow">←</div><div class="ba-side"><div class="ba-tag ok">بعد</div><div class="fake-card" style="text-align:center">' . e($props['textAfter'] ?? 'کارکرد کامل — تست‌شده و ضمانت‌دار') . '</div></div></div></div>';
            case 'cta-whatsapp': {
                $ph = trim((string)($props['phone'] ?? ''));
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' cta-blk">' . ($title ? '<div class="blk-title" style="margin-bottom:9px">' . e($title) . '</div>' : '') . '<div class="hero-btns"><span class="hero-btn" style="background:#16a34a">💬 ' . ($ph !== '' ? 'گفتگو در واتساپ — ' . e($ph) : 'گفتگو در واتساپ') . '</span><span class="hero-btn ghost">📞 تماس تلفنی</span></div></div>';
            }
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
            case 'footer-links': {
                $its = pvItems($props, [['', 'خدمات ما'], ['', 'مقالات آموزشی'], ['', 'کدهای خطا'], ['', 'سوالات متداول'], ['', 'قوانین و مقررات'], ['', 'حریم خصوصی']]);
                $out = '';
                foreach ($its as $it) { $out .= '<div class="feat-d" style="padding:3px 0">' . e($it['text']) . ($it['desc'] !== '' ? ' — <span dir="ltr" style="opacity:.6;font-size:10px">' . e($it['desc']) . '</span>' : '') . '</div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . ($title ? '<div class="blk-title" style="margin-bottom:10px">' . e($title) . '</div>' : '') . '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:4px">' . $out . '</div></div>';
            }
            case 'payment-methods':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '>' . ($title !== '' ? '<div class="blk-title" style="margin-bottom:8px">' . e($title) . '</div>' : '') . '<div class="chip-row" style="justify-content:center">' . implode('', array_map(static fn($p) => '<span class="chip">' . e($p['icon'] ?: '💳') . ' ' . e($p['text']) . '</span>', pvItems($props, [['💳', 'پرداخت کارتی'], ['💰', 'پرداخت نقدی'], ['🧾', 'کارت به کارت'], ['📟', 'درگاه آنلاین'], ['🤝', 'اقساطی']]))) . '</div></div>';
            case 'announcement-pill':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="pill-announce"><span class="pill-dot"></span>' . ($title ?: '📣 تیتر مهم امروز') . '</div></div>';
            case 'heading-center':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="text-align:center"><div class="blk-title" style="font-size:23px">' . ($title ?: 'عنوان بزرگ بخش') . '</div><div class="feat-d" style="font-size:13.5px;margin-top:6px">' . e($props['subtitle'] ?? 'زیرعنوان توضیحی این بخش') . '</div><div style="width:56px;height:4px;border-radius:4px;background:var(--p);margin:14px auto 0"></div></div></div>';
            case 'numbered-list':
                $its = pvItems($props, [['۱', 'عیب‌یابی تخصصی رایگان', 'بررسی کامل با دستگاه تست'], ['۲', 'پیش‌فاکتور شفاف', 'تأیید قیمت قبل از شروع کار'], ['۳', 'تعمیر با قطعات اصلی', 'همراه با ۶ ماه ضمانت']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<div class="num-list">' . implode('', array_map(static fn($it) => '<div class="num-row"><span class="num-n">' . e($it['icon']) . '</span><div><b>' . e($it['text']) . '</b><div class="feat-d">' . e($it['desc']) . '</div></div></div>', $its)) . '</div></div>';
            case 'info-box':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="info-box-demo"><span class="feat-ico" style="font-size:22px">' . e($props['icon'] ?? '💡') . '</span><div><b>' . ($title ?: 'نکته مهم') . '</b><div class="feat-d">' . e($props['text'] ?? 'متن توضیح جعبه اطلاعات...') . '</div></div></div></div>';
            case 'price-cards': {
                $its = pvItems($props, [['اقتصادی', 'سرویس پایه — ۴۵۰ هزار تومان', ''], ['استاندارد', 'سرویس کامل — ۹۵۰ هزار تومان', ''], ['ویژه', 'سرویس + قطعه — ۱٫۵ میلیون', '']]);
                $out = '';
                foreach ($its as $i => $it) { $out .= '<div class="fake-card"' . ($i === 1 ? ' style="border:2px solid var(--p,#2563eb)"' : '') . '><div class="card-t">' . e($it['text']) . '</div><div class="feat-d" style="font-weight:800;color:var(--p)">' . e($it['desc']) . '</div></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'پلن‌های سرویس') . '</div><div class="cols c3">' . $out . '</div></div>';
            }
            case 'location-cards':
                $its = pvItems($props, [['🏬', 'شعبه مرکزی', 'تهران، ولیعصر'], ['🏬', 'شعبه غرب', 'تهران، سعادت‌آباد']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'شعب ما') . '</div><div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="card-ico">' . e($it['icon'] ?: '🏬') . '</div><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
            case 'expert-cards':
                $its = pvItems($props, [['🔧', 'مهندس کریمی', 'برد و الکترونیک'], ['❄️', 'مهندس رضایی', 'سیستم سرمایش']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'متخصصین ما') . '</div><div class="cols c' . pvCols($props, 4) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="fake-ava">' . e($it['icon'] ?: '👨‍🔧') . '</div><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
            case 'logo-cloud': {
                $its = pvItems($props, [['🏅', 'نشان سفیر خدمت'], ['📋', 'مجوز اتحادیه'], ['🎖️', 'نمایندگی رسمی'], ['✅', 'تاییدیه کیفیت']]);
                $out = '';
                foreach ($its as $it) { $out .= '<div style="text-align:center;min-width:86px"><div style="font-size:26px">' . e($it['icon'] ?: '🏅') . '</div><div class="feat-d" style="font-size:11px">' . e($it['text']) . '</div></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="chip-row" style="justify-content:center">' . $out . '</div></div>';
            }
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
            case 'pros-cons': {
                $its = pvItems($props, [['✅', 'قطعات اصلی و ضمانت‌دار', ''], ['✅', 'اعزام سریع تکنسین', ''], ['⚠️', 'زمان تعمیر ۲ تا ۴ روز کاری', '']]);
                $pros = '';
                foreach ($its as $it) { if (!str_starts_with($it['icon'], '⚠') && !str_starts_with($it['icon'], '❌')) { $pros .= '<div class="feat-row"><span class="feat-ico" style="background:#f0fdf4">' . e($it['icon'] ?: '✅') . '</span><div>' . e($it['text']) . '</div></div>'; } }
                $cons = '';
                foreach ($its as $it) { if (str_starts_with($it['icon'], '⚠') || str_starts_with($it['icon'], '❌')) { $cons .= '<div class="feat-row"><span class="feat-ico" style="background:#fef2f2">' . e($it['icon']) . '</span><div>' . e($it['text']) . '</div></div>'; } }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c2"><div class="fake-card" style="border-inline-start:4px solid #16a34a"><div class="card-t" style="color:#15803d">✅ مزایا</div><div class="feat-list">' . $pros . '</div></div><div class="fake-card" style="border-inline-start:4px solid #dc2626"><div class="card-t" style="color:#b91c1c">⚠️ نکات</div><div class="feat-list">' . ($cons !== '' ? $cons : '<div class="feat-d">موردی ثبت نشده</div>') . '</div></div></div></div>';
            }
            case 'text-accent-box':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-inline-start:5px solid var(--p);border-radius:12px;padding:15px 17px">' . ($title ? '<div class="card-t" style="margin-bottom:6px">' . e($title) . '</div>' : '') . '<div class="feat-d" style="color:#1e40af">' . e($props['text'] ?? 'متنی که باید توجه کاربر را جلب کند.') . '</div></div></div>';
            case 'definition-list':
                $its = pvItems($props, [['🔧', 'ایرادیابی', 'بررسی کامل دستگاه برای یافتن عیب'], ['🧲', 'مگنترون', 'قطعه تولید امواج مایکروویو']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="feat-list">' . implode('', array_map(static fn($it) => '<div class="feat-row"><span class="feat-ico">' . e($it['icon'] ?: '📖') . '</span><div><b>' . e($it['text']) . '</b><div class="feat-d">' . e($it['desc']) . '</div></div></div>', $its)) . '</div></div>';
            case 'article-highlight':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;background:linear-gradient(135deg,#fff7ed,#ffedd5);border:1.5px solid #fdba74;border-radius:15px;padding:19px 21px">' . pvImg($props, '🌟', 'width:130px;height:110px;flex:0 0 130px') . '<div style="flex:1;min-width:200px"><div class="card-t" style="font-size:15px">' . e($title ?: 'محتوای ویژه') . '</div>' . (!empty($props['subtitle']) ? '<div class="feat-d" style="font-weight:700;color:#9a3412">' . e($props['subtitle']) . '</div>' : '') . '<div class="feat-d">' . e($props['text'] ?? 'خلاصه‌ای از مزیت ویژه این بخش.') . '</div></div></div></div>';
            case 'page-header':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="text-align:center;padding:18px 10px 8px"><div class="hero-title" style="font-size:24px">' . e($title ?: 'عنوان صفحه') . '</div>' . (!empty($props['subtitle']) ? '<div class="feat-d">' . e($props['subtitle']) . '</div>' : '') . '<div class="feat-d" style="margin-top:8px;opacity:.65">خانه / ' . e($title ?: 'صفحه') . '</div></div></div>';
            case 'steps-vertical':
                $its = pvItems($props, [['۱', 'ثبت درخواست', 'آنلاین یا تلفنی'], ['۲', 'عیب‌یابی و اعلام هزینه', 'شفاف و پیش از شروع'], ['۳', 'تعمیر و تحویل', 'همراه با ضمانت کتبی']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="feat-list">' . implode('', array_map(static fn($it) => '<div class="feat-row"><span class="feat-ico" style="background:linear-gradient(135deg,var(--p),var(--s));color:#fff;font-weight:800">' . e($it['icon'] ?: '•') . '</span><div><b>' . e($it['text']) . '</b><div class="feat-d">' . e($it['desc']) . '</div></div></div>', $its)) . '</div></div>';
            case 'service-price-cards':
                $its = pvItems($props, [['🧺', 'شست‌وشوی کامل ماشین لباس', 'از ۹۵۰ هزار تومان'], ['❄️', 'شارژ گاز کولر', 'از ۱٫۲ میلیون تومان'], ['🔥', 'تعویض هیتر ماشین ظرفشویی', 'از ۱٫۵ میلیون تومان']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'تعرفه خدمات پرتقاضا') . '</div><div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="card-ico">' . e($it['icon'] ?: '🔧') . '</div><div class="card-t" style="font-size:12.5px">' . e($it['text']) . '</div><div class="feat-d" style="font-weight:800;color:var(--p)">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
            case 'feature-icons-grid':
                $its = pvItems($props, [['🧊', 'یخچال', ''], ['🧺', 'لباسشویی', ''], ['📺', 'تلویزیون', ''], ['🔥', 'فر و اجاق', '']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'خدمات ما در یک نگاه') . '</div><div class="cols c' . pvCols($props, 4) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card" style="text-align:center;padding:15px 8px"><div style="font-size:31px">' . e($it['icon'] ?: '🔧') . '</div><div class="feat-d" style="font-weight:700;margin-top:6px">' . e($it['text']) . '</div></div>', $its)) . '</div></div>';
            case 'callback-form':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'درخواست تماس کارشناس') . '</div><div class="news-row"><div class="fake-input" style="flex:1">شماره تماس شما</div><div class="hero-btn">' . e($props['btnText'] ?? 'با من تماس بگیرید') . '</div></div><div class="feat-d" style="margin-top:7px">✅ کارشناسان ما در کمتر از ۱۵ دقیقه تماس می‌گیرند</div></div>';
            case 'survey-form': {
                $its = pvItems($props, [['⭐', 'بسیار راضی', ''], ['👍', 'راضی', ''], ['😐', 'معمولی', '']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'میزان رضایت شما از سرویس؟') . '</div><div class="cols c' . max(2, min(4, count($its))) . '" style="gap:9px">' . implode('', array_map(static fn($it) => '<div class="fake-card" style="text-align:center;padding:13px 8px"><div style="font-size:23px">' . e($it['icon'] ?: '⭐') . '</div><div class="feat-d" style="font-weight:700">' . e($it['text']) . '</div></div>', $its)) . '</div></div>';
            }
            case 'chat-widget':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="display:flex;justify-content:flex-end"><div style="background:var(--card);border:1.5px solid var(--border);border-radius:15px 15px 3px 15px;padding:11px 15px;max-width:290px;box-shadow:0 8px 22px rgba(2,8,23,.12)"><div style="font-size:12.5px"><b>💬 ' . e($title ?: 'پشتیبانی آنلاین') . '</b></div><div class="feat-d">سلام! چطور می‌تونیم کمکتون کنیم؟</div><div style="display:flex;gap:6px;margin-top:8px"><span class="hero-btn" style="font-size:11px;padding:5px 12px">شروع گفتگو</span></div></div></div></div>';
            case 'vote-poll': {
                $its = pvItems($props, [['🧺', 'لباسشویی', ''], ['❄️', 'یخچال', ''], ['🔥', 'ماکروویو', '']]);
                $faP = static fn($n) => strtr((string)$n, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
                $total = count($its) * 12 + 30;
                $out = '';
                foreach ($its as $i => $it) { $p = (int)round(($total - $i * 11) / max(1, $total) * 100); $out .= '<div class="cap-row"><span class="cap-h">' . e($it['icon']) . ' ' . e($it['text']) . '</span><div class="track" style="flex:1"><div class="fill" style="width:' . $p . '%"></div></div><span class="cap-l">' . $faP($p) . '٪</span></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'رای‌گیری') . '</div><div class="cap-demo">' . $out . '</div></div>';
            }
            case 'video-grid': {
                $gcols = pvCols($props, 3);
                $gimgs = '';
                for ($gi = 0; $gi < $gcols * 2; $gi++) { $gimgs .= '<div style="position:relative">' . str_replace('fake-img', 'fake-img small', pvImg($props, '🎬', 'min-height:96px')) . '<div class="play" style="width:30px;height:30px;font-size:12px">▶</div></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'ویدیوهای آموزشی') . '</div><div class="cols c' . $gcols . '">' . $gimgs . '</div></div>';
            }
            case 'logo-marquee': {
                $its = pvItems($props, [['🏷️', 'ال‌جی', ''], ['🏷️', 'سامسونگ', ''], ['🏷️', 'بوش', ''], ['🏷️', 'سونی', ''], ['🏷️', 'پاکس', ''], ['🏷️', 'اسنوا', '']]);
                $chips = '';
                foreach (array_merge($its, $its) as $it) { $chips .= '<span class="chip" style="margin-inline-end:9px">' . e($it['icon'] ?: '🏷️') . ' ' . e($it['text']) . '</span>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'برندهای مورد خدمت') . '</div><div style="overflow:hidden;border-radius:12px;padding:11px 0"><div class="chip-row" style="animation:tickMove 18s linear infinite;white-space:nowrap;width:max-content">' . $chips . '</div></div></div>';
            }
            case 'tag-cloud': {
                $its = pvItems($props, [['', 'تعمیر ماشین لباس', ''], ['', 'کد خطا SE', ''], ['', 'شارژ گاز کولر', ''], ['', 'بک‌لایت تلویزیون', '']]);
                $sizes = ['12px', '14px', '13px', '15px', '12.5px', '14.5px'];
                $out = '';
                foreach ($its as $i => $it) { $out .= '<span class="chip" style="font-size:' . $sizes[$i % count($sizes)] . ';font-weight:' . ($i % 3 === 0 ? 800 : 600) . ';opacity:' . (0.72 + ($i % 3) * 0.09) . '">' . e($it['text']) . '</span>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="chip-row" style="justify-content:center;gap:8px">' . $out . '</div></div>';
            }
            case 'quote-slider': {
                $its = pvItems($props, [['علی محمدی', 'سرویس سریع و منظم بود؛ راضی بودم.', ''], ['مریم احمدی', 'قیمت شفاف و ضمانت واقعی.', ''], ['رضا کریمی', 'تکنسین دقیق و حرفه‌ای اعزام شد.', '']]);
                $q = $its[0] ?? ['icon' => '', 'text' => ''];
                $dots = implode(' ', array_map(static fn($i) => '○', $its));
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'مشتریان چه می‌گویند') . '</div><div class="quote" style="text-align:center;font-size:15px">«' . e($q['text']) . '»</div><div class="feat-d" style="text-align:center;font-weight:800;margin-top:6px">— ' . e($q['icon']) . '</div><div class="slider-dots" style="margin-top:8px">● ' . $dots . '</div></div>';
            }
            case 'stats-circles': {
                $its = pvItems($props, [['۹۲٪', 'تعمیر در روز اول', ''], ['۸۷٪', 'رضایت کامل', ''], ['۹۶٪', 'حل قطعی ایراد', '']]);
                $out = '';
                foreach ($its as $it) { $num = preg_replace('/[^0-9]/', '', $it['icon']) ?: '80'; $deg = (int)round((int)$num / 100 * 360); $out .= '<div style="text-align:center"><div style="width:86px;height:86px;margin:0 auto;border-radius:50%;background:conic-gradient(var(--p) ' . $deg . 'deg,var(--border) ' . $deg . 'deg);display:flex;align-items:center;justify-content:center"><div style="width:66px;height:66px;background:var(--card);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:16.5px">' . e($it['icon'] ?: $num) . '</div></div><div class="feat-d" style="margin-top:8px;font-weight:700">' . e($it['text']) . '</div></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'عملکرد ما در آمار واقعی') . '</div><div class="cols c' . max(2, min(4, count($its))) . '" style="gap:14px">' . $out . '</div></div>';
            }
            case 'counter-big': {
                $its = pvItems($props, [['۵۰,۰۰۰+', 'تعمیر تکمیل‌شده', '']]);
                $it0 = $its[0] ?? ['icon' => '۵۰,۰۰۰+', 'text' => 'تعمیر تکمیل‌شده'];
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="text-align:center;border-radius:16px;padding:26px 18px"><div class="hero-title" style="font-size:37px">' . e($it0['icon'] ?: $it0['text']) . '</div><div class="feat-d" style="font-weight:800;font-size:14px;margin-top:5px">' . e($it0['icon'] !== '' ? $it0['text'] : 'شمارنده') . '</div></div></div>';
            }
            case 'brand-stats-bar': {
                $its = pvItems($props, [['۱۵+', 'سال تجربه', ''], ['۴۲', 'نوع دستگاه تخصصی', ''], ['۲۴/۷', 'پشتیبانی', ''], ['۶ ماه', 'ضمانت کتبی', '']]);
                $out = '';
                foreach ($its as $i => $it) { $out .= ($i > 0 ? '<span class="ss-sep"></span>' : '') . '<span class="ss-item"><b>' . e($it['icon']) . '</b> ' . e($it['text']) . '</span>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="stats-strip">' . $out . '</div></div>';
            }
            case 'emergency-strip':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border-radius:13px;padding:13px 19px"><b style="font-size:14px">' . e($props['text'] ?? '🚑 امداد تعمیر فوری — ۲۴ ساعته') . '</b><span class="hero-btn" style="background:#fff;color:#b91c1c">📞 ' . e($props['phone'] ?? '۰۲۱-۱۲۳۴۵۶۷۸') . '</span></div></div>';
            case 'stats-strip':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' stats-strip">' . pvStatStrip($props) . '</div>';
            case 'benefits-list': {
                $its = pvItems($props, [['', 'اعزام تکنسین در کمتر از ۲ ساعت', ''], ['', 'قطعات فابریک با فاکتور معتبر', ''], ['', '۶ ماه ضمانت کتبی قطعه و خدمات', ''], ['', 'پیش‌فاکتور شفاف قبل از شروع کار', ''], ['', 'پیگیری وضعیت درخواست آنلاین', '']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<div class="feat-list">' . implode('', array_map(static fn($it) => '<div class="feat-row"><span class="feat-ico" style="background:#f0fdf4">✅</span><div><b>' . e($it['text']) . '</b></div></div>', $its)) . '</div></div>';
            }
            case 'warning-box':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="warning-box-demo"><span class="feat-ico" style="background:#fef2f2;font-size:22px">' . e($props['icon'] ?? '⚠️') . '</span><div><b style="color:#b91c1c">' . ($title ?: 'هشدار ایمنی مهم') . '</b><div class="feat-d">' . e($props['text'] ?? 'قبل از هرگونه باز کردن دستگاه، برق را کاملاً قطع کنید.') . '</div></div></div></div>';
            case 'brand-intro-card':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="brand-intro-demo"><div class="fake-logo" style="font-size:34px">🏗️</div><div style="flex:1"><div class="blk-title" style="margin-bottom:4px">' . ($title ?: 'نمایندگی رسمی خدمات') . '</div><div class="feat-d">' . e($props['subtitle'] ?? 'بیش از یک دهه تجربه تخصصی') . '</div><div class="stars" style="font-size:11px;margin-top:5px">⭐⭐⭐⭐⭐ <b>۴.۹ از ۵</b></div></div><span class="hero-btn" style="align-self:center">مشاهده خدمات</span></div></div>';
            case 'author-box':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="author-box-demo"><div class="fake-ava" style="font-size:38px">' . e($props['icon'] ?? '👨‍🔧') . '</div><div style="flex:1"><b style="font-size:14px">' . ($title ?: 'مهندس کریمی') . '</b><div class="feat-d">کارشناس برد و الکترونیک — ۱۴ سال تجربه</div><div class="feat-d" style="margin-top:4px">' . e($props['text'] ?? 'متخصص تعمیر برد‌های اصلی لباسشویی، یخچال و کولر گازی.') . '</div></div></div></div>';
            case 'download-card':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="download-card-demo"><span class="feat-ico" style="font-size:30px">📄</span><div style="flex:1"><div class="blk-title" style="margin-bottom:3px;text-align:right">' . ($title ?: 'بروشور خدمات ما') . '</div><div class="feat-d">' . e($props['subtitle'] ?? 'فهرست کامل خدمات و تعرفه‌ها در یک فایل PDF') . '</div></div><span class="hero-btn">' . e($props['btnText'] ?? '⬇ دانلود بروشور') . '</span></div></div>';
            case 'schedule-table':
                $its = pvItems($props, [['', 'شنبه', '۹ تا ۲۰'], ['', 'یکشنبه تا چهارشنبه', '۹ تا ۲۰'], ['', 'پنجشنبه', '۹ تا ۱۴'], ['', 'جمعه', 'فقط امداد فوری']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'ساعات کاری ما') . '</div><div class="price-table">' . implode('', array_map(static fn($it) => '<div class="price-row"><span>' . e($it['text']) . '</span><b>' . e($it['desc']) . '</b></div>', $its)) . '</div></div>';
            case 'price-highlight': {
                $badge = trim((string)($props['badge'] ?? ''));
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="fake-card price-highlight-demo" style="text-align:right">' . ($badge !== '' ? '<span class="badge badge-warning" style="font-size:10px">' . e($badge) . '</span>' : '') . '<div class="blk-title" style="text-align:right;margin:8px 0 3px">' . ($title ?: 'سرویس دوره‌ای کامل') . '</div><div class="stat-n" style="font-size:31px;text-align:right">' . e($props['price'] ?? '۴۵۰ هزار تومان') . '</div><div class="feat-d" style="margin:7px 0 11px">شامل شست‌وشو، کالیبراسیون و تست ایمنی + ۶ ماه ضمانت</div><span class="hero-btn full">رزرو همین حالا</span></div></div>';
            }
            case 'feature-table': {
                $its = pvItems($props, [['', 'عیب‌یابی رایگان'], ['', 'ضمانت ۶ ماهه'], ['', 'قطعات فابریک']]);
                $rows = '';
                foreach ($its as $it) { $rows .= '<div class="ft-row"><span>' . e($it['text']) . '</span><b>—</b><b>✓</b><b class="ft-hl">✓</b></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'مقایسه پلن‌های سرویس') . '</div><div class="feature-table-demo"><div class="ft-row ft-head"><span>ویژگی</span><b>اقتصادی</b><b>استاندارد</b><b class="ft-hl">ویژه</b></div>' . $rows . '</div></div>';
            }
            case 'quick-contact-form':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="quick-form-demo"><div class="fake-input" style="flex:1">📱 شماره تماس شما</div><span class="hero-btn">' . e($props['btnText'] ?? 'درخواست تماس') . '</span></div><div class="feat-d" style="text-align:center;margin-top:7px">' . e($props['subtitle'] ?? $title ?? 'کارشناسان ما در کمتر از ۱۵ دقیقه تماس می‌گیرند') . '</div></div>';
            case 'related-links':
                $its = pvItems($props, [['', 'کد خطای LE لباسشویی ال‌جی — معنی و رفع'], ['', '۱۰ علامت خرابی کمپرسور یخچال'], ['', 'راهنمای نگهداری ماکروویو']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="feat-list">' . implode('', array_map(static fn($it) => '<div class="feat-row"><span class="feat-ico">🔗</span><div>' . e($it['text']) . '</div></div>', $its)) . '</div></div>';
            case 'warranty-steps': {
                $its = pvItems($props, [['', 'ثبت سریال دستگاه'], ['', 'صدور برگه ضمانت'], ['', 'پشتیبانی ۶ ماهه']]);
                $out = '';
                foreach ($its as $i => $it) { $out .= ($i > 0 ? '<div class="step-arrow">←</div>' : '') . '<div class="step"><span class="step-n">' . e($it['icon'] ?: strtr((string)($i + 1), ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴'])) . '</span><div class="step-t">' . e($it['text']) . '</div></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'گارانتی ما چگونه کار می‌کند') . '</div><div class="steps-row">' . $out . '</div></div>';
            }
            case 'ticker-bar':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="ticker-bar-demo"><span class="ticker-tag">🔴 زنده</span><div class="ticker-track"><span>' . e($props['text'] ?? '⚡ اعزام تکنسین فوری · 🧊 شارژ گاز کولر · 🛡️ گارانتی ۶ ماهه · 📞 پاسخگویی ۷ روز هفته') . '</span></div></div></div>';
            case 'booking-calendar': {
                $days = '';
                foreach (['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'] as $d) { $days .= '<span class="cal-dow">' . $d . '</span>'; }
                for ($i = 1; $i <= 28; $i++) {
                    $cls = in_array($i - 1, [3, 8, 14, 19, 25], true) ? ' busy' : (in_array($i - 1, [5, 11, 22], true) ? ' sel' : '');
                    $days .= '<span class="cal-day' . $cls . '">' . strtr((string)$i, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']) . '</span>';
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'رزرو نوبت آنلاین') . '</div><div class="cal-demo">' . $days . '</div></div>';
            }
            case 'warranty-check':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'استعلام گارانتی') . '</div><div class="quick-form-demo"><div class="fake-input" style="flex:1;direction:ltr">SN-XXXX-1234</div><span class="hero-btn">' . e($props['btnText'] ?? 'استعلام') . '</span></div></div>';
            case 'price-estimate':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'برآورد هزینه تعمیر') . '</div><div class="form-grid"><div class="fake-input">🌀 نوع دستگاه (لباسشویی، یخچال...)</div><div class="fake-input">🔧 نوع ایراد (نمایش کد، صدا، نشتی...)</div><div class="fake-input">📍 منطقه</div><div class="hero-btn full">🧮 محاسبه فوری برآورد</div></div></div>';
            case 'device-error-lookup':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'جستجوی کد خطای دستگاه') . '</div><div class="search-wrap"><span class="search-ico">🔢</span><div class="fake-input" style="flex:1;border:none;direction:ltr">E4 / LE / CH-05 ...</div><span class="hero-btn">جستجو</span></div><div class="feat-d" style="text-align:center;margin-top:7px">کد روی نمایشگر دستگاه را وارد کنید — علت، راه‌حل فوری و هزینه تعمیر را ببینید</div></div>';
            case 'live-queue':
                $its = pvItems($props, [['🟢', 'دریافت و عیب‌یابی', 'در حال انجام — ۲ دستگاه'], ['🟡', 'تعمیر برد', 'در صف — ۱ دستگاه'], ['🔴', 'آماده تحویل', '۳ دستگاه']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'وضعیت صف تعمیرات — زنده') . '</div><div class="queue-demo">' . implode('', array_map(static fn($it) => '<div class="queue-row"><span>' . e($it['icon'] ?: '🟢') . ' ' . e($it['text']) . '</span><b>' . e($it['desc']) . '</b></div>', $its)) . '</div></div>';
            case 'hourly-capacity': {
                $its = pvItems($props, [['۹–۱۲', '20', 'کم‌تقاضا'], ['۱۲–۱۵', '60', 'متوسط'], ['۱۵–۱۸', '85', 'پرتقاضا']]);
                $out = '';
                foreach ($its as $it) { $p = max(5, min(100, (int)(preg_replace('/[^0-9]/', '', $it['text']) ?: 50))); $out .= '<div class="cap-row"><span class="cap-h">' . e($it['icon']) . '</span><div class="track" style="flex:1"><div class="fill" style="width:' . $p . '%"></div></div><span class="cap-l">' . e($it['desc']) . '</span></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'ظرفیت سرویس امروز') . '</div><div class="cap-demo">' . $out . '</div></div>';
            }
            case 'faq-search':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'جستجو در سوالات متداول') . '</div><div class="search-wrap"><span class="search-ico">🔎</span><div class="fake-input" style="flex:1;border:none">' . e($props['placeholder'] ?? 'سوال خود را بنویسید...') . '</div><span class="hero-btn">پرسیدن</span></div></div>';
            case 'faq-category':
                $its = pvItems($props, [['🌀', 'لباسشویی و ظرفشویی', '۱۲ سوال'], ['❄️', 'یخچال و فریزر', '۹ سوال'], ['📺', 'تلویزیون', '۷ سوال']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'سوالات متداول بر اساس موضوع') . '</div><div class="cols c3">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="card-ico">' . e($it['icon'] ?: '❓') . '</div><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
            case 'before-after-slider': {
                $imgUrl = trim((string)($props['imageUrl'] ?? ''));
                if (preg_match('#^uploads/#i', $imgUrl)) { $imgUrl = cdn_asset($imgUrl); }
                $before = preg_match('#^(https?://|/)#i', $imgUrl)
                    ? '<img src="' . e($imgUrl) . '" alt="" style="width:100%;height:100%;object-fit:cover;filter:grayscale(1) contrast(1.1)">'
                    : '🧺 فرسوده';
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'مقایسه تصویری قبل و بعد') . '</div><div class="bas-demo"><div class="bas-before">' . $before . '<span class="bas-tag">قبل</span></div><div class="bas-handle">⇔</div><div class="bas-after">✨ <b>مثل روز اول</b><span class="bas-tag ok">بعد</span></div></div></div>';
            }
            case 'social-wall':
                $its = pvItems($props, [['📷', 'نکته سرویس دوره‌ای', '۲ روز پیش'], ['🎥', 'ویدیوی عیب‌یابی زنده', '۵ روز پیش'], ['📝', 'معرفی تکنسین هفته', '۱ هفته پیش']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'آخرین پست‌های ما') . '</div><div class="cols c3">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div style="font-size:24px">' . e($it['icon'] ?: '📷') . '</div><div class="card-t" style="font-size:12px">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
            case 'newsletter-popup':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="np-demo-wrap"><div class="np-demo"><span class="feat-ico" style="font-size:30px">📧</span><div><b style="font-size:14px">' . ($title ?: 'پیشنهاد ویژه!') . '</b><div class="feat-d">' . e($props['subtitle'] ?? 'عضویت در خبرنامه = ۱۰٪ تخفیف اولین سرویس') . '</div></div><div class="news-row" style="margin-top:10px"><div class="fake-input" style="flex:1">ایمیل شما</div><span class="hero-btn">' . e($props['btnText'] ?? 'دریافت کد تخفیف') . '</span></div></div></div></div>';
            case 'credit-trust': {
                $its = pvItems($props, [['98', 'امتیاز اعتماد مشتریان']]);
                $it0 = $its[0] ?? ['icon' => '98', 'text' => 'امتیاز اعتماد مشتریان'];
                $sc = preg_replace('/[^0-9]/', '', $it0['icon'] ?: $it0['text']) ?: '98';
                $fa = static fn($n) => strtr((string)$n, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="ct-demo"><div class="ct-score">' . $fa($sc) . '<small>/' . $fa(100) . '</small></div><div style="flex:1"><b style="font-size:14px">' . e($it0['text']) . '</b><div class="feat-d" style="margin-top:4px">بر اساس نظرسنجی مستقل مشتریان در ۱۲ ماه گذشته</div></div></div></div>';
            }
            case 'brand-badges-row':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '>' . $head . '<div class="chip-row" style="justify-content:center;gap:10px">' . implode('', array_map(static fn($p) => '<span class="chip" style="padding:8px 14px;font-size:11.5px">' . e($p['icon'] ?: '🏅') . ' ' . e($p['text']) . '</span>', pvItems($props, [['🎖️', 'تعمیرکار رسمی سازمان فنی'], ['🛡️', 'بیمه مسئولیت حرفه‌ای'], ['📋', 'مجوز رسمی اتحادیه'], ['🔬', 'تخصص برد و الکترونیک']]))) . '</div></div>';
            case 'hero-minimal':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk hero-minimal-blk"><div class="hero-title" style="font-size:30px">' . ($title ?: 'تعمیر تخصصی، بدون معطلی') . '</div>' . (!empty($props['subtitle']) ? '<div class="hero-sub">' . e($props['subtitle']) . '</div>' : '') . '<div class="hero-btns"><span class="hero-btn">' . e($props['btnText'] ?? 'درخواست تعمیر') . '</span></div></div>';
            case 'hero-glass':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk"><div class="glass-hero-demo"><div class="hero-title">' . ($title ?: 'خدمات رسمی پس از فروش') . '</div><div class="hero-sub">' . e($props['subtitle'] ?? 'شفافیت کامل در قیمت و فرآیند') . '</div><div class="hero-btns"><span class="hero-btn ghost">مشاهده خدمات</span><span class="hero-btn">تماس</span></div></div></div>';
            case 'logo-strip':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="logo-strip-demo">' . str_repeat('<div class="fake-logo-s">🏷️</div>', 6) . '</div></div>';
            case 'text-columns': {
                $ps = array_values(array_filter(array_map('trim', explode("\n", (string)($props['text'] ?? ''))), static fn($l) => $l !== ''));
                if (!$ps) { $ps = ['پاراگراف اول متن...', 'متن ستون دوم — پاراگراف‌ها با Enter جدا می‌شوند.']; }
                $half = max(1, (int)ceil(count($ps) / 2));
                $c1 = array_slice($ps, 0, $half);
                $c2 = array_slice($ps, $half) ?: ['متن ستون دوم...'];
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
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c' . pvCols($props, 3) . '"><div class="fake-card"><div class="card-t">اقتصادی</div><div class="stat-n" style="font-size:22px">پایه</div></div><div class="fake-card" style="border:2px solid var(--p)"><span class="badge badge-warning" style="font-size:9.5px">پرطرفدار</span><div class="card-t">استاندارد</div><div class="stat-n" style="font-size:22px">کامل</div></div><div class="fake-card"><div class="card-t">ویژه</div><div class="stat-n" style="font-size:22px">طلایی</div></div></div></div>';
            case 'guarantee-card':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="guarantee-demo"><span style="font-size:42px">🛡️</span><div style="flex:1"><div class="blk-title" style="margin-bottom:4px">' . ($title ?: '۶ ماه ضمانت کتبی') . '</div><div class="feat-d">' . e($props['subtitle'] ?? 'تمام تعمیرات با ضمانت کتبی و قابل پیگیری انجام می‌شود.') . '</div></div><span class="hero-btn" style="align-self:center">' . e($props['btnText'] ?? 'مشاهده شرایط') . '</span></div></div>';
            case 'cta-timer':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk cta-timer-blk"' . $styleAttr . '><div class="hero-title" style="font-size:24px">' . ($title ?: 'تخفیف سرویس دوره‌ای') . '</div><div class="hero-sub">' . e($props['subtitle'] ?? 'فقط تا پایان هفته — بعد از پایان تایمر قیمت عادی است') . '</div>' . pvCountdown($props) . '<div class="hero-btns"><span class="hero-btn">همین حالا رزرو کنید</span></div></div>';
            case 'urgent-repair':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="urgent-demo"><span style="font-size:34px">🚨</span><div style="flex:1"><b style="font-size:15px">' . ($title ?: 'تعمیر فوری نیاز دارید؟') . '</b><div class="feat-d">۲۴ ساعته — ۷ روز هفته اعزام تکنسین</div></div><div style="text-align:center"><div class="feat-d" style="font-size:10.5px">تماس فوری</div><div class="stat-n" style="font-size:19px" dir="ltr">📞 ' . e($props['phone'] ?? '۰۲۱-۱۲۳۴۵۶۷۸') . '</div><span class="hero-btn" style="margin-top:5px">' . e($props['btnText'] ?? 'درخواست اعزام') . '</span></div></div></div>';
            case 'faq-mini':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="faq-mini-demo"><div class="sc-row"><span class="sc-num">؟</span><b style="font-size:13.5px">' . ($title ?: 'سوال متداول') . '</b></div><div class="feat-d" style="margin-top:7px;font-size:12.5px">' . e($props['text'] ?? 'پاسخ کارشناسان ما به سوال متداول...') . '</div></div></div>';
            case 'reviews-carousel':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($r) => '<div class="fake-card"><div class="stars">⭐⭐⭐⭐⭐</div><div class="feat-d">«' . $r . '»</div></div>', ['عالی بود، همان روز آمدند', 'قیمت منصفانه و کار تمیز', 'دستگاه ۵ ساله‌ام مثل نو شد'])) . '</div><div class="slider-dots" style="margin-top:8px">● ○ ○</div></div>';
            case 'appointment-compact':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="apt-compact-demo"><b style="font-size:14px">' . ($title ?: 'نوبت تعمیر رزرو کنید') . '</b><div class="news-row" style="margin-top:9px"><div class="fake-input" style="flex:1">شماره تماس شما</div><div class="fake-input" style="flex:1">دستگاه + مشکل</div><div class="hero-btn">' . e($props['btnText'] ?? 'رزرو نوبت') . '</div></div></div></div>';
            case 'contact-map-split':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="split"><div><div class="chip-row" style="flex-direction:column;align-items:stretch;gap:7px"><span class="chip">📞 <b dir="ltr">' . e($props['phone'] ?? '۰۲۱-۱۲۳۴۵۶۷۸') . '</b></span><span class="chip">📍 تهران، خیابان نمونه، پلاک ۱۲</span><span class="chip">🕐 شنبه تا پنجشنبه ۹ تا ۲۰</span></div></div><div class="fake-img" style="min-height:130px;background:linear-gradient(135deg,#e2e8f0,#cbd5e1)"><span style="font-size:30px">🗺️</span></div></div></div>';
            case 'stats-inline':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' stats-strip-blk">' . $head . '<div class="stats-strip">' . pvStatStrip($props) . '</div></div>';
            case 'pelement':
                /* ⭐ عنصر شخصی استخراج‌شده — iframe ایزوله با استایل کامل سایت مبدأ */
                {
                    $peId = (int)($props['element_id'] ?? 0);
                    if (!isset($pelements[$peId])) {
                        return '';
                    }
                    $pe = $pelements[$peId];
                    $frameDoc = htmlspecialchars(pv_pelement_doc($pe), ENT_QUOTES, 'UTF-8');
                    return '<div class="blk ' . $bgClass . ' ' . $padClass . '">'
                        . ($title !== '' ? '<div class="blk-title">' . e($title) . '</div>' : '')
                        . '<iframe class="pelement-frame" sandbox="allow-same-origin" srcdoc="' . $frameDoc . '" style="width:100%;min-height:210px;border:none;border-radius:11px;background:#fff" loading="lazy" onload="try{var d=this.contentDocument;if(d){this.style.height=Math.max(200,d.documentElement.scrollHeight+18)+\'px\'}}catch(e){}" title="' . e($pe['name'] ?? 'عنصر شخصی') . '"></iframe></div>';
                }
            default:
                /* بلوک ناشناخته/قدیمی — به‌جای جعبه خطا، هیچ چاپ نمی‌شود */
                return '';
        }
    }

    /** 🏛 رندر سطح‌بهدار — ستون‌های تودرتو */
    function bb_render_layout(array $items, array $pelements = []): string
    {
        $html = '';
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $block = (string)($item['block'] ?? '');
            if ($block === '_page' || $block === '') { continue; }
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
                    $colHtml = bb_render_layout($colItems, $pelements);
                    if ($colHtml !== '') {
                        $inner .= '<div class="pv-col">' . $colHtml . '</div>';
                    }
                }
                $head = bb_render_block($block, $props, $pelements);
                if ($inner !== '') {
                    $html .= $head . '<div class="pv-cols" style="--pv-n:' . $colCount . '">' . $inner . '</div>';
                }
            } else {
                $html .= bb_render_block($block, $props, $pelements);
            }
        }
        return $html;
    }

    /** 🎛 متغیرهای CSS تنظیمات صفحه (گره مخفی _page) */
    function bb_page_css_vars(array $p): string
    {
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
    }

    /**
     * 🧱 HTML کامل چیدمان — داده API تم/قالب/صفحه
     *
     * @param array $tplData  پاسخ endpoint قالب: ['name'=>.., 'layout'=>[...], 'pelements'=>[id=>..]]
     * @return string خالی اگر چیدمانی نبود (صفحه به ساختار ثابت برمی‌گردد)
     */
    function bb_layout_html(array $tplData): string
    {
        $layout = $tplData['layout'] ?? [];
        if (!is_array($layout) || empty($layout)) {
            return '';
        }
        $pelements = [];
        foreach ((array)($tplData['pelements'] ?? []) as $pe) {
            if (is_array($pe) && isset($pe['id'])) {
                $pelements[(int)$pe['id']] = ['name' => (string)($pe['name'] ?? ''), 'html' => (string)($pe['html'] ?? ''), 'css' => (string)($pe['css'] ?? '')];
            }
        }
        /* گره تنظیمات صفحه */
        $pageProps = [];
        if (is_array($layout[0] ?? null) && ($layout[0]['block'] ?? '') === '_page') {
            $pageProps = is_array($layout[0]['props'] ?? null) ? $layout[0]['props'] : [];
            array_shift($layout);
        }
        $pageStyle = bb_page_css_vars($pageProps);
        $inner = bb_render_layout($layout, $pelements);
        if (trim($inner) === '') {
            return '';
        }
        return '<div class="bb-wrap"' . ($pageStyle !== '' ? ' style="' . e($pageStyle) . '"' : '') . '>' . $inner . '</div>';
    }
}
