<?php
/**
 * 🧱 هسته رندرگر واحد بلوک‌های قالب‌ساز (P2-21 — منبع حقیقت واحد)
 * =====================================================================
 * گزارش تحلیل جامع §۹.۱: «سه رندرگر موازی» بزرگ‌ترین بدهی فنی پروژه بود —
 * هر عنصر در سه جا (JS بوم، PHP پیش‌نمایش، PHP سایت برند) مستقل پیاده
 * شده بود و هر باگ سه‌بار رفع می‌شد.
 *
 * ✅ این فایل «رندرگر PHP واحد» است که هر سه صحنه از آن استفاده می‌کنند:
 *   • سایت برند:   templates/brand-core/includes/blocks.php (حالت site)
 *   • پیش‌نمایش:   admin/template-preview.php (حالت preview)
 *   • بوم قالب‌ساز: admin/block-render.ajax.php (P2-22 — AJAX سرور-محور)
 *
 * تفاوت‌های رفتاری دو صحنه با «حالت» (pv_renderer_init) مدیریت می‌شوند:
 *   site    → فالبک داده‌های زنده برند (تلفن/ساعات/آدرس از API)،
 *             بلوک پنهان = حذف، cdn_asset فعال، فرم واقعی
 *   preview → داده نمونه ایستا، بلوک پنهان = جعبه راهنما 🙈، بدون API
 *
 * ⚠️ این فایل با استقرار سایت برند منتقل می‌شود (داخل templates/brand-core)
 * و نباید وابسته به توابع پنل باشد — pv_asset با function_exists محافظت
 * شده و pv_brand_settings در حالت preview هرگز به API نمی‌رود.
 *
 * @package SahandBrandMaker
 * @since   2.36.0
 */
if (!defined('BRAND_INIT') && !defined('SAHAND_INIT')) { http_response_code(403); exit; }

/* ═══════════════════════════════════════════════════════════════
 * 🎭 زیرساخت حالت (site / preview) — P2-21
 * ═══════════════════════════════════════════════════════════════ */
if (!function_exists('pv_renderer_init')) {
    /** آغازگر حالت رندر — پیش از هر رندر یک‌بار فراخوانی می‌شود
     * $ctx: ['mode' => 'site'|'preview', 'pelements' => [id => [name,html,css]]] */
    function pv_renderer_init(array $ctx = []): void
    {
        $GLOBALS['PV_RENDERER_CTX'] = [
            'mode'      => ($ctx['mode'] ?? 'site') === 'preview' ? 'preview' : 'site',
            'pelements' => is_array($ctx['pelements'] ?? null) ? $ctx['pelements'] : [],
        ];
    }
}
if (!function_exists('pv_renderer_is_preview')) {
    function pv_renderer_is_preview(): bool
    {
        return (($GLOBALS['PV_RENDERER_CTX']['mode'] ?? 'site') === 'preview');
    }
}
if (!function_exists('pv_renderer_pelements')) {
    function pv_renderer_pelements(): array
    {
        return $GLOBALS['PV_RENDERER_CTX']['pelements'] ?? [];
    }
}
if (!function_exists('pv_asset')) {
    /** 🖼 آدرس دارایی — روی سایت برند از cdn_asset (دامنه سایت‌ساز)؛
     * در پیش‌نمایش/AJAX همان مسیر نسبی برمی‌گردد */
    function pv_asset(string $url): string
    {
        return function_exists('cdn_asset') ? cdn_asset($url) : $url;
    }
}
if (!function_exists('pv_icon')) {
    /** 🎨 v2.38 — رندر آیکون آیتم: ایموجی/متن/عدد یا SVG از پک آیکون‌ها
     * مقدار با پیشوند «svg:» (مثلاً svg:remix-icons/home.svg) به‌صورت <img>
     * از assets/icons/ سایت‌ساز رندر می‌شود؛ هر مقدار دیگری مثل قبل به‌صورت
     * متن اِسکیپ‌شده (ایموجی/عدد/نام) — سازگار کامل با داده‌های موجود.
     * اندازه با em نسبت به فونتِ محل رندر مقیاس می‌شود (هم‌زبان با بافت). */
    function pv_icon(?string $icon, string $style = ''): string
    {
        $icon = trim((string)$icon);
        if ($icon === '') {
            return '';
        }
        if (strpos($icon, 'svg:') === 0) {
            $rel = 'icons/' . ltrim(substr($icon, 4), '/');
            /* سایت برند → cdn_asset (دامنه سایت‌ساز)؛ پنل/پیش‌نمایش → BASE_URL مطلق */
            $url = function_exists('cdn_asset')
                ? cdn_asset($rel)
                : (defined('BASE_URL') ? BASE_URL . '/' . $rel : $rel);
            $st = $style !== '' ? $style : 'width:1.2em;height:1.2em;object-fit:contain;vertical-align:-0.22em';
            return '<img src="' . e($url) . '" alt="" loading="lazy" style="' . $st . '">';
        }
        return e($icon);
    }
}
pv_renderer_init(); /* حالت پیش‌فرض site — آغازگرها بازنویسی می‌کنند */

/* ═══════════════════════════════════════════════════════════════
 * 🧰 توابع کمکی مشترک (پیش‌تر دو نسخه آینه هم بودند — حالا یکی)
 * ═══════════════════════════════════════════════════════════════ */

if (!function_exists('pvItems')) {
    function pvItems(array $props, array $fallback): array
    {
        $norm = static function ($arr): array {
            $out = [];
            foreach ((array)$arr as $it) {
                if (is_array($it)) {
                    $isList = is_int(array_key_first($it));
                    $out[] = [
                        'icon' => $isList ? (string)($it[0] ?? '') : (string)($it['icon'] ?? ''),
                        'text' => $isList ? (string)($it[1] ?? '') : (string)($it['text'] ?? ''),
                        'desc' => $isList ? (string)($it[2] ?? '') : (string)($it['desc'] ?? ''),
                        /* 🖱 v2.29 — لینک + 🎨 v2.31 رنگ آیتم */
                        'link' => $isList ? (string)($it[3] ?? '') : (string)($it['link'] ?? ''),
                        'color' => $isList ? (string)($it[4] ?? '') : (string)($it['color'] ?? ''),
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
    function pvCols(array $props, int $default = 3): int
    {
        $c = (int)($props['columns'] ?? $default);
        return max(2, min(6, $c ?: $default));
    }
}

if (!function_exists('pvStatStrip')) {
    function pvStatStrip(array $props): string
    {
        $items = pvItems($props, [['۱۲+', 'سال تجربه'], ['۵۰k', 'تعمیر'], ['۹۸٪', 'رضایت']]);
        $out = [];
        foreach (array_slice($items, 0, 8) as $i) {
            $out[] = '<span class="ss-item"><b>' . pv_icon($i['icon'] !== '' ? $i['icon'] : '۰') . '</b> ' . e($i['text'] ?: 'آمار') . '</span>';
        }
        return implode('<span class="ss-sep"></span>', $out);
    }
}

if (!function_exists('pvCountdown')) {
    function pvCountdown(array $props): string
    {
        $target = trim((string)($props['countdownTo'] ?? ''));
        $days = '۰۲'; $hrs = '۱۴'; $min = '۳۰'; $sec = '۰۰';
        $ts = 0;
        if ($target !== '') {
            $ts = strtotime($target);
        }
        if ($ts !== false && $ts > time()) {
            $diff = $ts - time();
            $fa = static fn($n) => strtr(str_pad((string)$n, 2, '0', STR_PAD_LEFT), ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
            $days = $fa(floor($diff / 86400));
            $hrs = $fa(floor(($diff % 86400) / 3600));
            $min = $fa(floor(($diff % 3600) / 60));
            $sec = $fa($diff % 60);
        } elseif ($ts !== false && $ts > 0 && $ts <= time()) {
            /* ⏱️ v2.43 — هدف گذشته: صفرِ صادقانه به‌جای اعداد پیش‌فرض گمراه‌کننده */
            $days = $hrs = $min = $sec = '۰۰';
        }
        /* ⏱️ v2.43 — شمارش معکوس زنده (درخواست کاربر «تا رفرش بروز نمیشه»):
           data-ts = مهر زمانی هدف + data-now = مهر زمانی رندر سرور؛
           js/app.js هر ثانیه اعداد را بازمحاسبه می‌کند و اختلاف ساعت
           محلیِ غلط کاربر را با data-now خنثی می‌کند. در نبود JS،
           اعداد سرور رندرشده می‌مانند. */
        $tsAttr = ($ts !== false && $ts > 0) ? ' data-ts="' . (int)$ts . '" data-now="' . time() . '"' : '';
        return '<div class="count-row"' . $tsAttr . '><span class="count-box"><b class="cd-d">' . $days . '</b>روز</span><span class="count-box"><b class="cd-h">' . $hrs . '</b>ساعت</span><span class="count-box"><b class="cd-m">' . $min . '</b>دقیقه</span><span class="count-box"><b class="cd-s">' . $sec . '</b>ثانیه</span></div>';
    }
}

if (!function_exists('pvStyleVars')) {
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
        /* 🆕 v2.32 */
        $txt = trim((string)($props['textColor'] ?? ''));
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $txt)) { $s .= '--blk-txt:' . $txt . ';'; }
        if (($props['background'] ?? '') === 'custom' && preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($props['bgColor'] ?? ''))) {
            $s .= '--blk-bg:' . (string)$props['bgColor'] . ';';
        }
        $px = static function ($v): string {
            $n = (int)$v;
            return ($v === '' || $v === null || $n < -80 || $n > 300) ? '' : $n . 'px';
        };
        $mt = $px($props['mt'] ?? ''); $mb = $px($props['mb'] ?? '');
        if ($mt !== '') { $s .= '--blk-mt:' . $mt . ';'; }
        if ($mb !== '') { $s .= '--blk-mb:' . $mb . ';'; }
        /* 🆕 v2.39 — رنگ اختصاصی دکمه‌های همین بخش (تکمیل تنظیمات عناصر:
           «رنگ» برای دکمه‌ها — هم‌رنگ شدن دکمه با هویت بصری برند) */
        $btn = trim((string)($props['btnColor'] ?? ''));
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $btn)) { $s .= '--blk-btn:' . $btn . ';'; }
        /* 🆕 v2.44 (S09) — تکمیل تنظیمات عناصر: سایه/بوردر/تصویر زمینه بخش + رنگ/اندازه آیکون */
        $bsh = (string)($props['boxShadow'] ?? '');
        if ($bsh === 'none') { $s .= '--blk-shadow:none;'; }
        elseif ($bsh === 'soft') { $s .= '--blk-shadow:0 3px 12px rgba(2,8,23,.09);'; }
        elseif ($bsh === 'strong') { $s .= '--blk-shadow:0 14px 36px rgba(2,8,23,.18);'; }
        $bd = (string)($props['borderStyle'] ?? '');
        if (in_array($bd, ['thin', 'dashed', 'thick'], true)) {
            $bc = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($props['borderColor'] ?? '')) ? (string)$props['borderColor'] : '#cbd5e1';
            $w = $bd === 'thick' ? '2.5px' : '1.5px';
            $st = $bd === 'dashed' ? 'dashed' : 'solid';
            $s .= '--blk-bd:' . $w . ' ' . $st . ' ' . $bc . ';';
        }
        $bgi = trim((string)($props['bgImage'] ?? ''));
        if (preg_match('#^https?://[^\s"\'<>]{5,500}$#i', $bgi)) {
            $s .= '--blk-bgi:url(\'' . $bgi . '\');';
        }
        $ic = trim((string)($props['iconColor'] ?? ''));
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $ic)) { $s .= '--blk-ic:' . $ic . ';'; }
        $ics = (string)($props['iconSize'] ?? '');
        if ($ics === 'sm') { $s .= '--blk-ics:.78;'; }
        elseif ($ics === 'lg') { $s .= '--blk-ics:1.35;'; }
        elseif ($ics === 'xl') { $s .= '--blk-ics:1.75;'; }
        return $s;
    }
}

if (!function_exists('pvImg')) {
    function pvImg(array $props, string $emoji, string $style = ''): string
    {
        $url = trim((string)($props['imageUrl'] ?? ''));
        if (preg_match('#^uploads/#i', $url)) {
            $url = pv_asset($url);
        }
        if (preg_match('#^(https?://|/)#i', $url)) {
            return '<div class="fake-img" style="' . $style . '"><img src="' . e($url) . '" alt="" style="width:100%;height:100%;object-fit:cover;display:block"></div>';
        }
        return '<div class="fake-img" style="' . $style . '">' . $emoji . '</div>';
    }
}

if (!function_exists('pvItemImg')) {
    function pvItemImg(array $it, string $style = ''): string
    {
        $url = trim((string)($it['text'] ?? ''));
        if (preg_match('#^uploads/#i', $url)) {
            $url = pv_asset($url);
        }
        if (preg_match('#^(https?://|/)#i', $url)) {
            return '<div class="fake-img small" style="' . ($style !== '' ? $style : 'min-height:90px') . '"><img src="' . e($url) . '" alt="" style="width:100%;height:100%;object-fit:cover;display:block"></div>';
        }
        return '<div class="fake-img small" style="' . ($style !== '' ? $style : 'min-height:90px') . '">' . pv_icon($it['icon'] ?: '🖼️') . '</div>';
    }
}

if (!function_exists('pv_variant_classes')) {
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
    function pv_pelement_doc(array $el): string
    {
        $css = str_replace('<', '\3C ', (string)($el['css'] ?? ''));
        return '<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<style>*{box-sizing:border-box}body{margin:0;padding:14px;background:transparent;font-family:Vazirmatn,Tahoma,sans-serif}img{max-width:100%;height:auto}a{text-decoration:none}'
            . $css . '</style></head><body>' . (string)($el['html'] ?? '') . '</body></html>';
    }
}

/* ➕ مقدارهای پویا از تنظیمات سایت‌ساز — در پیش‌نمایش خالی */

if (!function_exists('pv_brand_settings')) {
    function pv_brand_settings(): array
    {
        static $cache = null;
        static $cacheMode = null;
        $mode = pv_renderer_is_preview() ? 'preview' : 'site';
        if ($cache !== null && $cacheMode === $mode) { return $cache; }
        $cache = [];
        $cacheMode = $mode;
        /* 🎭 P2-21 — پیش‌نمایش: بدون تماس API؛ خالی → فالبک نمونه ایستا */
        if (pv_renderer_is_preview() || !function_exists('fetchFromAPI')) { return $cache; }
        try { $cache = fetchFromAPI('settings', 300)['data'] ?? []; } catch (Throwable $e) { $cache = []; }
        return $cache;
    }
}

if (!function_exists('pv_brand_phone')) {
    function pv_brand_phone(): string
    {
        $st = pv_brand_settings();
        $phones = (array)($st['contacts']['phones'] ?? []);
        foreach (['phone', 'mobile'] as $k) {
            $arr = (array)($phones[$k] ?? []);
            foreach ($arr as $v) {
                $v = trim((string)$v);
                if ($v !== '') { return $v; }
            }
        }
        return '';
    }
}

if (!function_exists('pv_brand_hours')) {
    function pv_brand_hours(): string
    {
        $st = pv_brand_settings();
        $wh = (array)($st['work_hours'] ?? []);
        if (!$wh) { return ''; }
        $daysFa = ['sat' => 'شنبه', 'sun' => 'یکشنبه', 'mon' => 'دوشنبه', 'tue' => 'سه‌شنبه', 'wed' => 'چهارشنبه', 'thu' => 'پنجشنبه', 'fri' => 'جمعه'];
        $days = (array)($wh['days'] ?? []);
        $dayTxt = '';
        if ($days) {
            $labels = [];
            foreach ($days as $d) { if (isset($daysFa[$d])) { $labels[] = $daysFa[$d]; } }
            if ($labels) {
                $dayTxt = count($labels) === 1 ? $labels[0] : ($labels[0] . ' تا ' . $labels[count($labels) - 1]);
            }
        }
        $faDig = static fn($x) => strtr((string)$x, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
        $start = $faDig(substr((string)($wh['start'] ?? ''), 0, 5));
        $end = $faDig(substr((string)($wh['end'] ?? ''), 0, 5));
        $range = ($start !== '' && $end !== '') ? $start . ' تا ' . $end : '';
        return trim($dayTxt . ($dayTxt && $range ? '، ' : '') . $range);
    }
}

if (!function_exists('pv_brand_address')) {
    function pv_brand_address(): string
    {
        $st = pv_brand_settings();
        $addrs = (array)($st['addresses'] ?? []);
        foreach ($addrs as $a) {
            if (!is_array($a)) { continue; }
            $txt = trim((string)($a['address'] ?? ''));
            if ($txt !== '') {
                $city = trim((string)($a['city'] ?? ''));
                return $city !== '' ? $city . '، ' . $txt : $txt;
            }
        }
        return '';
    }
}

if (!function_exists('pv_brand_map_url')) {
    function pv_brand_map_url(): string
    {
        $st = pv_brand_settings();
        $addrs = (array)($st['addresses'] ?? []);
        foreach ($addrs as $a) {
            if (!is_array($a)) { continue; }
            $mu = trim((string)($a['map_url'] ?? ''));
            if ($mu !== '') { return $mu; }
            $lat = trim((string)($a['lat'] ?? ''));
            $lng = trim((string)($a['lng'] ?? ''));
            if ($lat !== '' && $lng !== '') { return 'https://maps.google.com/?q=' . rawurlencode($lat . ',' . $lng); }
        }
        return '';
    }
}

if (!function_exists('pv_safe_link')) {
    function pv_safe_link(string $link): string
    {
        $link = trim($link);
        if ($link === '') { return ''; }
        if (preg_match('#^(javascript|data|vbscript|file|about|blob)\s*:#i', $link)) { return ''; }
        return $link;
    }
}

/* ═══════════════════════════════════════════════════════════
 * 🔄 v2.42 — داده‌های پویای مشترک برای بلوک‌های قالب‌ساز
 * (درخواست کاربر: «همه عناصر قالب ساز رو پویا بکن»)
 * هر تابع یک‌بار در هر رندر کش ایستا می‌کند؛ در پیش‌نمایش
 * قالب‌ساز همیشه [] برمی‌گردند تا نمونه دستی نشان داده شود.
 * ═══════════════════════════════════════════════════════════ */
if (!function_exists('pv_dyn_comments')) {
    /** 💬 آخرین دیدگاه‌های تأییدشده مشتریان همین برند */
    function pv_dyn_comments(int $limit = 9): array
    {
        static $cache = null;
        if ($cache !== null) { return array_slice($cache, 0, $limit); }
        if (pv_renderer_is_preview() || !defined('BRAND_ID') || !function_exists('fetchFromAPI')) { return []; }
        try {
            $cache = (array)(fetchFromAPI('brand/' . BRAND_ID . '/latest-comments?limit=' . max(3, $limit), 600)['data'] ?? []);
        } catch (Throwable $e) { $cache = []; }
        return array_slice($cache, 0, $limit);
    }
}
if (!function_exists('pv_dyn_faqs')) {
    /** ❓ سوالات متداول واقعی همین برند */
    function pv_dyn_faqs(int $limit = 12): array
    {
        static $cache = null;
        if ($cache !== null) { return array_slice($cache, 0, $limit); }
        if (pv_renderer_is_preview() || !defined('BRAND_ID') || !function_exists('fetchFromAPI')) { return []; }
        try {
            $cache = (array)(fetchFromAPI('brand/' . BRAND_ID . '/faqs', 600)['data'] ?? []);
        } catch (Throwable $e) { $cache = []; }
        return array_slice($cache, 0, $limit);
    }
}
if (!function_exists('pv_dyn_articles')) {
    /** 📰 مقالات منتشرشده همین برند (جدیدترین) */
    function pv_dyn_articles(int $limit = 6): array
    {
        static $cache = null;
        if ($cache !== null) { return array_slice($cache, 0, $limit); }
        if (pv_renderer_is_preview() || !defined('BRAND_ID') || !function_exists('fetchFromAPI')) { return []; }
        try {
            $cache = (array)(fetchFromAPI('brand/' . BRAND_ID . '/articles?per_page=' . max(3, $limit), 300)['data'] ?? []);
        } catch (Throwable $e) { $cache = []; }
        return array_slice($cache, 0, $limit);
    }
}
if (!function_exists('pv_dyn_brands')) {
    /** 🏷 برندهای فعال نمایندگی (به‌جز خود برند) */
    function pv_dyn_brands(int $limit = 12): array
    {
        static $cache = null;
        if ($cache !== null) { return array_slice($cache, 0, $limit); }
        if (pv_renderer_is_preview() || !function_exists('fetchFromAPI')) { return []; }
        try {
            $all = (array)(fetchFromAPI('brands', 300)['data'] ?? []);
            $cache = array_values(array_filter($all, static fn($b) => !defined('BRAND_ID') || (int)($b['id'] ?? 0) !== (int)BRAND_ID));
        } catch (Throwable $e) { $cache = []; }
        return array_slice($cache, 0, $limit);
    }
}
if (!function_exists('pv_dyn_error_codes')) {
    /** 🔢 کدهای خطای فعال برند (پرتکرار برای چیپ‌های جستجو) */
    function pv_dyn_error_codes(int $limit = 6): array
    {
        static $cache = null;
        if ($cache !== null) { return array_slice($cache, 0, $limit); }
        if (pv_renderer_is_preview() || !defined('BRAND_ID') || !function_exists('fetchFromAPI')) { return []; }
        try {
            $rows = (array)(fetchFromAPI('brand/' . BRAND_ID . '/error-codes', 600)['data'] ?? []);
            $out = [];
            foreach ($rows as $r) {
                $code = trim((string)($r['code'] ?? ''));
                $title = trim((string)($r['title'] ?? ''));
                if ($code !== '' && $title !== '') {
                    $out[] = ['code' => $code, 'title' => $title];
                }
                if (count($out) >= $limit) { break; }
            }
            $cache = $out;
        } catch (Throwable $e) { $cache = []; }
        return array_slice($cache, 0, $limit);
    }
}
if (!function_exists('pv_dyn_article_tags')) {
    /** #️⃣ برچسب‌های واقعی مقالات همین برند (برای ابر برچسب) */
    function pv_dyn_article_tags(int $limit = 10): array
    {
        static $cache = null;
        if ($cache !== null) { return array_slice($cache, 0, $limit); }
        if (pv_renderer_is_preview() || !defined('BRAND_ID') || !function_exists('fetchFromAPI')) { return []; }
        try {
            $arts = (array)(fetchFromAPI('brand/' . BRAND_ID . '/articles?per_page=30', 600)['data'] ?? []);
            $tags = [];
            foreach ($arts as $a) {
                foreach ((array)($a['tags'] ?? []) as $t) {
                    $t = trim((string)$t);
                    if ($t !== '' && mb_strlen($t) <= 30) { $tags[$t] = ($tags[$t] ?? 0) + 1; }
                }
            }
            arsort($tags);
            $cache = array_keys(array_slice($tags, 0, $limit, true));
        } catch (Throwable $e) { $cache = []; }
        return array_slice($cache, 0, $limit);
    }
}

if (!function_exists('pv_dyn_socials')) {
    /** 🔗 v2.44 (S04) — شبکه‌های اجتماعی واقعی از تنظیمات سایت‌ساز
     * خروجی: [['icon'=>.., 'text'=>نام, 'link'=>URL], ...] — نگاشت آیکون از نام */
    function pv_dyn_socials(): array
    {
        static $cache = null;
        if ($cache !== null) { return $cache; }
        $cache = [];
        $st = pv_brand_settings();
        $soc = (array)($st['socials'] ?? []);
        $map = [
            'telegram' => '📡', 'تلگرام' => '📡', 'tg' => '📡',
            'instagram' => '📷', 'اینستاگرام' => '📷', 'اینستا' => '📷', 'insta' => '📷',
            'whatsapp' => '💬', 'واتساپ' => '💬', 'واتس‌اپ' => '💬',
            'aparat' => '▶️', 'آپارات' => '▶️',
            'youtube' => '▶️', 'یوتیوب' => '▶️',
            'twitter' => '🐦', 'x' => '🐦', 'توییتر' => '🐦', 'ایکس' => '🐦',
            'linkedin' => '💼', 'لینکدین' => '💼',
            'facebook' => '📘', 'فیسبوک' => '📘',
            'eitaa' => '📨', 'ایتا' => '📨',
            'bale' => '💬', 'بله' => '💬',
            'rubika' => '🟣', 'روبیکا' => '🟣',
        ];
        foreach ($soc as $s) {
            if (!is_array($s)) { continue; }
            $name = trim((string)($s['name'] ?? ''));
            $url = trim((string)($s['url'] ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url)) { continue; }
            $key = mb_strtolower($name, 'UTF-8');
            $icon = '📣';
            foreach ($map as $k => $ic) { if (str_contains($key, $k)) { $icon = $ic; break; } }
            $cache[] = ['icon' => $icon, 'text' => $name !== '' ? $name : 'شبکه اجتماعی', 'link' => $url, 'desc' => '', 'color' => ''];
        }
        return $cache;
    }
}
if (!function_exists('pv_dyn_email')) {
    /** ✉️ v2.44 (S04) — نخستین ایمیل عمومی از تنظیمات سایت‌ساز */
    function pv_dyn_email(): string
    {
        $st = pv_brand_settings();
        foreach ((array)($st['emails'] ?? []) as $em) {
            if (is_array($em)) {
                $v = trim((string)($em['email'] ?? $em['address'] ?? ''));
            } else {
                $v = trim((string)$em);
            }
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) { return $v; }
        }
        return '';
    }
}
if (!function_exists('pv_dyn_warranty')) {
    /** 🛡️ v2.44 (S04) — متن ضمانت از تنظیمات سایت‌ساز (تب ضمانت) */
    function pv_dyn_warranty(): array
    {
        $st = pv_brand_settings();
        $w = (array)($st['warranty'] ?? []);
        return [
            'period' => trim((string)($w['default_period'] ?? '')),
            'text'   => trim((string)($w['text'] ?? '')),
        ];
    }
}
if (!function_exists('pv_dyn_city')) {
    /** 🏙 v2.44 (S04) — شهر نمایندگی از نخستین آدرس سایت‌ساز */
    function pv_dyn_city(): string
    {
        $st = pv_brand_settings();
        foreach ((array)($st['addresses'] ?? []) as $a) {
            if (is_array($a)) {
                $c = trim((string)($a['city'] ?? ''));
                if ($c !== '') { return $c; }
            }
        }
        return defined('BRAND_CITY') ? trim((string)BRAND_CITY) : '';
    }
}
if (!function_exists('pv_dyn_areas')) {
    /** 📍 v2.44 (S04) — محدوده‌های خدمت از آدرس‌های سایت‌ساز (شهر + محله) */
    function pv_dyn_areas(int $limit = 8): array
    {
        $st = pv_brand_settings();
        $out = [];
        foreach ((array)($st['addresses'] ?? []) as $a) {
            if (!is_array($a)) { continue; }
            $c = trim((string)($a['city'] ?? ''));
            $ad = trim((string)($a['address'] ?? ''));
            /* شهر (یک‌بار برای هر شهر متمایز): «کل تبریز» */
            if ($c !== '') {
                $cc = 'کل ' . $c;
                if (!in_array($cc, $out, true)) { $out[] = $cc; }
            }
            /* محله/ابتدای آدرس کوتاه: «چهارراه ابوریحان» */
            if ($ad !== '' && mb_strlen($ad) <= 60) {
                $first = trim((string)(explode('،', $ad)[0] ?? ''));
                if (mb_strlen($first) >= 3 && mb_strlen($first) <= 25 && !in_array($first, $out, true)) { $out[] = $first; }
            }
            if (count($out) >= $limit) { break; }
        }
        return $out;
    }
}
if (!function_exists('pv_brand_logo_html')) {
    /** 🏷 v2.44 (S04) — لوگو/نام برند واقعی برای هدر و فوتر قالب‌ساز
     * در سایت برند: نام برند (BRAND_NAME_FA)؛ پیش‌نمایش: نمونه */
    function pv_brand_logo_html(string $class = 'fake-logo'): string
    {
        if (!pv_renderer_is_preview() && defined('BRAND_NAME_FA') && trim((string)BRAND_NAME_FA) !== '') {
            return '<div class="' . $class . '" style="font-size:15px;font-weight:800;line-height:1.25;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . e((string)BRAND_NAME_FA) . '</div>';
        }
        return '<div class="' . $class . '">🏗️</div>';
    }
}
if (!function_exists('pv_dyn_brand_title')) {
    /** ✨ v2.44 (S04) — عنوان پیش‌فرض برنددار: «خدمات {برند}» */
    function pv_dyn_brand_title(string $fallback): string
    {
        if (!pv_renderer_is_preview() && defined('BRAND_NAME_FA') && trim((string)BRAND_NAME_FA) !== '') {
            return 'خدمات ' . trim((string)BRAND_NAME_FA);
        }
        return $fallback;
    }
}

if (!function_exists('pvA')) {
    function pvA(array $it, string $inner): string
    {
        $link = pv_safe_link((string)($it['link'] ?? ''));
        if ($link === '') { return $inner; }
        $ext = preg_match('#^https?://#i', $link) ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' . e($link) . '"' . $ext . ' style="text-decoration:none;color:inherit;display:block">' . $inner . '</a>';
    }
}

if (!function_exists('pv_slider_slides')) {
    function pv_slider_slides(array $props, string $defType = 'image'): string
    {
        $type = (string)($props['slideType'] ?? $defType);
        $its = array_values(array_filter(pvItems($props, []), static fn($i) => trim((string)$i['text']) !== '' || trim((string)$i['desc']) !== ''));
        /* 📰 / 🏷 حالت داینامیک — مقالات یا برندهای واقعی از API */
        if (($type === 'article' || $type === 'brand') && defined('BRAND_ID')) {
            try {
                if ($type === 'article') {
                    $arts = (array)(fetchFromAPI('brand/' . BRAND_ID . '/articles?per_page=' . max(3, count($its) ?: 5), 300)['data'] ?? []);
                    if ($arts) {
                        $its = array_map(static fn($a) => [
                            'icon' => '📰',
                            'text' => (string)($a['title'] ?? ''),
                            'desc' => trim((string)(preg_replace('#<[^>]+>#', ' ', (string)($a['excerpt'] ?? ''))) ?: 'مطالعه مقاله...'),
                            'link' => '/blog/' . rawurlencode((string)($a['slug'] ?? '')),
                        ], array_values($arts));
                    }
                } else {
                    $brs = (array)(fetchFromAPI('brands', 300)['data'] ?? []);
                    if ($brs) {
                        $its = array_map(static fn($b) => [
                            'icon' => '🏷️',
                            'text' => (string)($b['name_fa'] ?? ''),
                            'desc' => 'مشاهده سایت ' . (string)($b['name_fa'] ?? 'برند'),
                            'link' => !empty($b['domain']) ? 'https://' . (string)$b['domain'] : '',
                        ], array_values($brs));
                    }
                }
            } catch (Throwable $dynE) { /* شبکه قطع — اسلایدهای دستی می‌مانند */ }
        }
        if (!$its) {
            return '<div class="fake-img" style="min-height:180px">🖼️</div><div class="slider-dots">● ○ ○</div>';
        }
        /* 🎞 رندر کاروسل واقعی — JS در app.js اسلاید می‌کند */
        $out = '<div class="sahand-slider" style="position:relative;overflow:hidden" data-autoplay="' . (!empty($props['autoplay']) ? '1' : '0') . '"><div class="ss-track" style="display:flex;transition:transform .45s cubic-bezier(.25,.8,.3,1)">';
        foreach ($its as $it) {
            $link = trim((string)($it['link'] ?? ''));
            $aOpen = $link !== '' ? '<a href="' . e($link) . '"' . (preg_match('#^https?://#i', $link) ? ' target="_blank" rel="noopener"' : '') . ' style="text-decoration:none;color:inherit;flex:0 0 100%">' : '<div style="flex:0 0 100%">';
            $aClose = $link !== '' ? '</a>' : '</div>';
            if ($type === 'text') {
                $out .= $aOpen . '<div class="fake-card" style="margin:4px 6px"><div class="card-t" style="font-size:16px">' . e($it['text']) . '</div>' . ($it['desc'] !== '' ? '<div class="feat-d">' . e($it['desc']) . '</div>' : '') . '</div>' . $aClose;
            } elseif ($type === 'card' || $type === 'article' || $type === 'brand') {
                $ico = $type === 'article' ? '📰' : ($type === 'brand' ? '🏷️' : ($it['icon'] !== '' ? $it['icon'] : '🃏'));
                $out .= $aOpen . '<div class="fake-card" style="margin:4px 6px"><div class="card-ico">' . e($ico) . '</div><div class="card-t">' . e($it['text']) . '</div>' . ($it['desc'] !== '' ? '<div class="feat-d">' . e($it['desc']) . '</div>' : '') . '</div>' . $aClose;
            } else {
                $url = trim((string)$it['desc']);
                if (preg_match('#^uploads/#i', $url)) { $url = pv_asset($url); }
                $inner = preg_match('#^(https?://|/)#i', $url)
                    ? '<img src="' . e($url) . '" alt="' . e($it['text']) . '" style="width:100%;height:100%;object-fit:cover" loading="lazy">'
                    : '<div style="display:flex;align-items:center;justify-content:center;height:100%;font-size:38px">' . pv_icon($it['icon'] !== '' ? $it['icon'] : '🖼️') . '</div>';
                $out .= $aOpen . '<div style="position:relative;margin:4px 6px"><div class="fake-img" style="min-height:230px;height:230px;flex:none;width:100%">' . $inner . '</div>' . ($it['text'] !== '' ? '<div class="feat-d" style="text-align:center;margin-top:6px;font-weight:700">' . e($it['text']) . '</div>' : '') . '</div>' . $aClose;
            }
        }
        $out .= '</div>';
        $n = count($its);
        $out .= '<button type="button" class="ss-nav ss-prev" aria-label="اسلاید قبلی" style="position:absolute;top:50%;inset-inline-start:6px;transform:translateY(-50%);width:34px;height:34px;border-radius:50%;border:none;background:rgba(255,255,255,.92);box-shadow:0 3px 10px rgba(2,8,23,.25);cursor:pointer;font-size:16px">‹</button>';
        $out .= '<button type="button" class="ss-nav ss-next" aria-label="اسلاید بعدی" style="position:absolute;top:50%;inset-inline-end:6px;transform:translateY(-50%);width:34px;height:34px;border-radius:50%;border:none;background:rgba(255,255,255,.92);box-shadow:0 3px 10px rgba(2,8,23,.25);cursor:pointer;font-size:16px">›</button>';
        $out .= '<div class="ss-dots" style="text-align:center;margin-top:9px;letter-spacing:6px;font-size:11px;cursor:pointer">' . implode(' ', array_map(static fn($i) => '<span style="cursor:pointer">' . ($i === 0 ? '●' : '○') . '</span>', range(0, $n - 1))) . '</div>';
        $out .= '</div>';
        return $out;
    }
}

if (!function_exists('pv_anim_style')) {
    function pv_anim_style(array $props): array
    {
        $anim = trim((string)($props['anim'] ?? ''));
        if ($anim === '' || $anim === 'none') { return ['', '']; }
        $speed = ['slow' => '1.2s', 'normal' => '.7s', 'fast' => '.4s'][($props['animSpeed'] ?? 'normal')] ?? '.7s';
        $delay = max(0, min(3000, (int)($props['animDelay'] ?? 0)));
        return ['blk-anim blk-anim-' . $anim, '--anim-dur:' . $speed . ';--anim-delay:' . $delay . 'ms;'];
    }
}

if (!function_exists('pv_link_wrap')) {
    function pv_link_wrap(array $props, string $html): string
    {
        if (empty($props['clickable'])) { return $html; }
        $link = trim((string)($props['link'] ?? ''));
        if ($link === '') { return $html; }
        $target = (($props['linkTarget'] ?? 'same') === 'new') ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' . e($link) . '"' . $target . ' style="display:block;text-decoration:none;color:inherit">' . $html . '</a>';
    }
}

/* ═══════════════════════════════════════════════════════════════
 * 🎨 رندر یک بلوک + پس‌پردازش (کلاس/انیمیشن/دکمه‌لینک/کلیک‌پذیری)
 * ═══════════════════════════════════════════════════════════════ */

if (!function_exists('pv_render_block')) {
    function pv_render_block(string $block, array $props = [])
    {
        $html = pv_render_block_inner($block, $props);

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
        /* 🆕 v2.32 — تکمیل تنظیمات: مخفی در موبایل/دسکتاپ + گردی اختصاصی */
        $hideCls  = (!empty($props['hideMobile']) ? 'blk-hide-mobile ' : '') . (!empty($props['hideDesktop']) ? 'blk-hide-desktop ' : '');
        $radCls   = in_array((string)($props['radiusOverride'] ?? 'default'), ['sharp', 'round', 'pill'], true) ? 'blk-rad-' . (string)$props['radiusOverride'] : '';
        $inject = [];
        foreach (array_filter([$sizeCls, $alignCls, $widthCls, $custom, $padCls, $bgCls, $varCls, $hideCls, $radCls]) as $cls) {
            $cls = trim($cls);
            if ($cls !== '' && strpos($html, $cls) === false) {
                $inject[] = $cls;
            }
        }
        if ($inject) {
            $html = preg_replace('#^<div class="blk #', '<div class="blk ' . implode(' ', $inject) . ' ', $html, 1);
        }
        $vars = pvStyleVars($props);
        if ($vars !== '' && preg_match('#--blk-tc|--blk-grad|--blk-txt|--blk-bg|--blk-mt|--blk-mb|--blk-btn|--blk-shadow|--blk-bd|--blk-bgi|--blk-ic|--blk-ics#', $html) === 0) {
            if (preg_match('#^(<div class="blk [^>]*?)style="([^"]*)"#', $html, $sm)) {
                $html = preg_replace('#^(<div class="blk [^>]*?)style="[^"]*"#', '$1style="' . $sm[2] . ';' . $vars . '"', $html, 1);
            } else {
                $html = preg_replace('#^<div class="blk ([^>]*)>#', '<div class="blk $1" style="' . $vars . '">', $html, 1);
            }
        }
        /* 🎬 v2.29 — انیمیشن ورود: کلاس + متغیرهای سرعت/تأخیر (فعال‌سازی با اسکرول در app.js) */
        [$animCls, $animVars] = pv_anim_style($props);
        if ($animCls !== '') {
            $html = preg_replace('#^<div class="blk #', '<div class="blk ' . $animCls . ' ', $html, 1);
            if (preg_match('#^(<div class="blk [^>]*?)style="([^"]*)"#', $html, $sm2)) {
                $html = preg_replace('#^(<div class="blk [^>]*?)style="[^"]*"#', '$1style="' . $sm2[2] . ';' . $animVars . '"', $html, 1);
            } else {
                $html = preg_replace('#^<div class="blk ([^>]*)>#', '<div class="blk $1" style="' . $animVars . '">', $html, 1);
            }
        }
        /* 🔘 v2.32 — دکمه‌های عنصر: لینک جداگانه هر دکمه + متن قابل تغییر
           (درخواست «چند دکمه باشد برای هر کدام لینک جداگانه»)
           اولویت: btnLinks[i] ← btnLink (همه) ← حفظ لینک قبلی (مثل tel:) */
        $btnLink = trim((string)($props['btnLink'] ?? ''));
        $btnLinksMap = is_array($props['btnLinks'] ?? null) ? $props['btnLinks'] : [];
        $btnTextsMap = is_array($props['btnTexts'] ?? null) ? $props['btnTexts'] : [];
        $btnIdx = 0;
        $html = preg_replace_callback(
            '#<([a-z]+)([^>]*\bclass="(hero-btn[^"]*)"[^>]*)>([^<]*)</\1>#',
            static function ($m) use (&$btnIdx, $btnLinksMap, $btnTextsMap, $btnLink) {
                $btnIdx++;
                $i = (string)$btnIdx;
                $text = trim((string)($btnTextsMap[$i] ?? ''));
                $text = $text !== '' ? e($text) : $m[4];
                $link = pv_safe_link((string)($btnLinksMap[$i] ?? '')) ?: pv_safe_link($btnLink);
                if ($link === '') {
                    /* بدون لینک جدید: فقط متن بازنویسی؛ صفت‌ها (مثل href تل) حفظ */
                    return $text !== $m[4] ? ('<' . $m[1] . $m[2] . '>' . $text . '</' . $m[1] . '>') : $m[0];
                }
                $ext = preg_match('#^https?://#i', $link) ? ' target="_blank" rel="noopener"' : '';
                return '<a class="' . $m[3] . '" href="' . e($link) . '"' . $ext . ' style="text-decoration:none;display:inline-block">' . $text . '</a>';
            },
            $html
        );
        /* 🆕 v2.39 — زیرعنوان سراسری بلوک‌ها: تزریق بعد از اولین تیتر بخش
           (تکمیل تنظیمات عناصر — «متن»: ۸۵ بلوک تیتردار حالا زیرعنوان هم دارند).
           بلوک‌هایی که hero-sub اختصاصی دارند (هیروها) از قبل زیرعنوان دارند
           و چون blk-title ندارند، این تزریق رویشان اثر نمی‌گذارد. */
        $blkSub = trim((string)($props['subtitle'] ?? ''));
        if ($blkSub !== '' && strpos($html, 'blk-sub') === false && strpos($html, 'blk-title') !== false) {
            $subDiv = '<div class="blk-sub">' . e($blkSub) . '</div>';
            /* callback → مقدار بازگشتی literal است (امن در برابر $ در متن زیرعنوان) */
            $html = preg_replace_callback(
                '#<div class="blk-title"[^>]*>.*?</div>#us',
                static function (array $m) use ($subDiv): string { return $m[0] . $subDiv; },
                $html,
                1
            );
        }
        /* 🆕 v2.44 (S09) — شناسه لنگری بخش: پیوند #id از منو/دکمه به همین بخش */
        $anchorId = preg_replace('/[^a-zA-Z0-9\-_]/', '', (string)($props['anchorId'] ?? ''));
        if ($anchorId !== '') {
            if (preg_match('#^(<div class="blk [^>]*?)>#', $html, $am)) {
                $html = preg_replace('#^(<div class="blk [^>]*?)>#', '$1 id="' . $anchorId . '" data-anchor="1">', $html, 1);
            }
        }
        /* 🖱 v2.29 — کلیک‌پذیری: کل عنصر داخل لینک */
        return pv_link_wrap($props, $html);
    }
}

if (!function_exists('pv_generic_block')) {
    function pv_generic_block(string $block, array $props, callable $PV): string
    {
        $type = (string)($props['renderType'] ?? 'cards');
        $title = (string)($props['title'] ?? '');
        $head = $title !== '' ? '<div class="blk-title">' . e($title) . '</div>' : '';
        $its = array_values(array_filter(pvItems($props, []), static fn($i) => trim((string)$i['text']) !== '' || trim((string)$i['icon']) !== ''));
        $sub = !empty($props['subtitle']) ? '<div class="feat-d" style="text-align:center;max-width:560px;margin:0 auto 10px">' . e((string)$props['subtitle']) . '</div>' : '';
        if ($type === 'features') {
            return $PV($head . '<div class="feat-list">' . implode('', array_map(static fn($it) => pvA($it, '<div class="feat-row"><span class="feat-ico">' . pv_icon($it['icon'] ?: '✨') . '</span><div><b>' . e($it['text']) . '</b>' . ($it['desc'] !== '' ? '<div class="feat-d">' . e($it['desc']) . '</div>' : '') . '</div></div>'), $its)) . '</div>');
        }
        if ($type === 'stats') {
            $n = max(2, min(6, count($its) ?: 3));
            return $PV($head . '<div class="cols c' . $n . '" style="gap:14px">' . implode('', array_map(static fn($i) => '<div class="stat"><div class="stat-n">' . pv_icon($i['icon'] !== '' ? $i['icon'] : '۰') . '</div><div class="stat-l">' . e($i['text'] !== '' ? $i['text'] : 'آمار') . '</div></div>', $its)) . '</div>');
        }
        if ($type === 'chips') {
            return $PV($head . '<div class="chip-row">' . implode('', array_map(static fn($i) => pvA($i, '<span class="chip">' . ($i['icon'] !== '' ? pv_icon($i['icon']) . ' ' : '') . e($i['text']) . ($i['desc'] !== '' ? ' — ' . e($i['desc']) : '') . '</span>'), $its)) . '</div>');
        }
        if ($type === 'banner') {
            $bLink = trim((string)($props['link'] ?? '')) ?: trim((string)($props['btnLink'] ?? ''));
            $btn = !empty($props['btnText'])
                ? ($bLink !== ''
                    ? '<a href="' . e($bLink) . '" class="hero-btn" style="text-decoration:none;margin-top:10px">' . e((string)$props['btnText']) . '</a>'
                    : '<div class="hero-btn" style="margin-top:10px">' . e((string)$props['btnText']) . '</div>')
                : '';
            $bIts = $its ? '<div class="cols c' . min(4, max(2, count($its))) . '" style="margin-top:11px">' . implode('', array_map(static fn($i) => pvA($i, '<div class="fake-card"><div class="card-ico">' . pv_icon($i['icon'] ?: '✨') . '</div><div class="card-t">' . e($i['text']) . '</div>' . ($i['desc'] !== '' ? '<div class="feat-d">' . e($i['desc']) . '</div>' : '') . '</div>'), $its)) . '</div>' : '';
            return $PV('<div class="hero-title" style="font-size:22px">' . e($title !== '' ? $title : 'بنر ویژه') . '</div>' . $sub . $bIts . $btn);
        }
        if ($type === 'steps') {
            $out = '';
            foreach ($its as $i => $it) {
                $fa = static fn($x) => strtr((string)$x, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
                $out .= ($i > 0 ? '<div class="step-arrow">←</div>' : '') . pvA($it, '<div class="step"><span class="step-n">' . pv_icon($it['icon'] !== '' ? $it['icon'] : $fa($i + 1)) . '</span><div class="step-t">' . e($it['text']) . ($it['desc'] !== '' ? '<div class="feat-d">' . e($it['desc']) . '</div>' : '') . '</div></div>');
            }
            return $PV($head . '<div class="steps-row" style="flex-wrap:wrap">' . $out . '</div>');
        }
        if ($type === 'price') {
            return $PV($head . '<div class="price-table">' . implode('', array_map(static fn($it) => pvA($it, '<div class="price-row"><span>' . e($it['text']) . '</span><b>' . e($it['desc']) . '</b></div>'), $its)) . '</div>');
        }
        if ($type === 'quote') {
            $qt = trim((string)($props['text'] ?? ''));
            if ($qt === '' && $its) { $qt = (string)$its[0]['text']; }
            return $PV($head . '<div class="quote">«' . e($qt !== '' ? $qt : 'متن نقل‌قول') . '»</div>');
        }
        if ($type === 'divider') {
            return $PV('<div style="text-align:center;font-size:22px;letter-spacing:3px;opacity:.5">' . e($its ? (string)$its[0]['text'] : '〰️〰️〰️') . '</div>');
        }
        /* 🆕 v2.31 — progress: نوارهای پیشرفت با رنگ هر آیتم */
        if ($type === 'progress') {
            $out = '';
            $striped = !empty($props['striped']) ? ' pb-stripes' : '';
            foreach ($its as $it) {
                $p = max(3, min(100, (int)(preg_replace('/[^0-9]/', '', (string)($it['desc'] ?: $it['icon'])) ?: 80)));
                $c = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($it['color'] ?? '')) ? (string)$it['color']
                    : (preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($props['barColor'] ?? '')) ? (string)$props['barColor'] : '#1e40af');
                $out .= '<div class="pbar"><span>' . e($it['text']) . '</span><div class="track"><div class="fill' . $striped . '" style="width:' . $p . '%;background:' . e($c) . '"></div></div></div>';
            }
            return $PV($head . $out);
        }
        /* 🆕 v2.31 — wheels: گردونه‌های درصدی */
        if ($type === 'wheels') {
            $out = '';
            $n = max(2, min(4, count($its) ?: 3));
            $faDig = static fn($x) => strtr((string)$x, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
            foreach ($its as $it) {
                $num = preg_replace('/[^0-9]/', '', (string)($it['icon'] ?: $it['desc'])) ?: '80';
                $deg = (int)round((int)$num / 100 * 360);
                $c = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($it['color'] ?? '')) ? (string)$it['color']
                    : (preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($props['barColor'] ?? '')) ? (string)$props['barColor'] : '#2563eb');
                $out .= '<div style="text-align:center"><div style="width:92px;height:92px;margin:0 auto;border-radius:50%;background:conic-gradient(' . e($c) . ' ' . $deg . 'deg,#e2e8f0 ' . $deg . 'deg);display:flex;align-items:center;justify-content:center"><div style="width:70px;height:70px;background:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:17px;color:' . e($c) . '">' . e($faDig($num) . '٪') . '</div></div><div class="feat-d" style="margin-top:8px;font-weight:700">' . e($it['text']) . '</div></div>';
            }
            return $PV($head . '<div class="cols c' . $n . '" style="gap:16px">' . $out . '</div>');
        }
        /* 🆕 v2.31 — gauge: حلقه بزرگ تک‌مقدار */
        if ($type === 'gauge') {
            $it = $its[0] ?? ['text' => 'شاخص', 'desc' => '80', 'color' => '#16a34a'];
            $num = preg_replace('/[^0-9]/', '', (string)($it['desc'] ?: $it['icon'])) ?: '80';
            $deg = (int)round((int)$num / 100 * 360);
            $c = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($it['color'] ?? '')) ? (string)$it['color'] : '#16a34a';
            $faDig = static fn($x) => strtr((string)$x, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
            return $PV($head . '<div style="display:flex;justify-content:center"><div><div style="width:170px;height:170px;border-radius:50%;background:conic-gradient(' . e($c) . ' ' . $deg . 'deg,#e2e8f0 ' . $deg . 'deg);display:flex;align-items:center;justify-content:center"><div style="width:132px;height:132px;background:#fff;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center"><b style="font-size:34px;color:' . e($c) . '">' . e($faDig($num)) . '</b><span style="font-size:11px;color:#64748b">' . e($it['text']) . '</span></div></div>' . (!empty($props['gaugeText']) ? '<div class="feat-d" style="text-align:center;margin-top:9px">' . e((string)$props['gaugeText']) . '</div>' : '') . '</div></div>');
        }
        /* 🆕 v2.31 — buttons: مجموعه دکمه با استایل/رنگ/لینک هر آیتم */
        if ($type === 'buttons') {
            $out = '';
            foreach ($its as $it) {
                $st = trim((string)($it['desc'] ?? 'primary')) ?: 'primary';
                $cls = 'hero-btn' . ($st === 'ghost' || $st === 'outline' ? ' ghost' : '');
                $style = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($it['color'] ?? '')) ? ' style="background:' . e((string)$it['color']) . '"' : ($st === 'gradient' ? ' style="background:linear-gradient(135deg,#1e40af,#0ea5e9)"' : '');
                $inner = '<span class="' . $cls . '"' . $style . '>' . ($it['icon'] !== '' ? pv_icon($it['icon']) . ' ' : '') . e($it['text'] !== '' ? $it['text'] : 'دکمه') . '</span>';
                $lk = trim((string)($it['link'] ?? ''));
                $out .= $lk !== ''
                    ? '<a href="' . e($lk) . '"' . (preg_match('#^https?://#i', $lk) ? ' target="_blank" rel="noopener"' : '') . ' style="text-decoration:none;display:inline-block">' . $inner . '</a>'
                    : $inner;
            }
            return $PV($head . $sub . '<div class="hero-btns" style="justify-content:flex-start;flex-wrap:wrap;gap:10px">' . $out . '</div>');
        }
        $cols = pvCols($props, 3);
        return $PV($head . '<div class="cols c' . $cols . '">' . implode('', array_map(static fn($it) => pvA($it, '<div class="fake-card">' . ($it['icon'] !== '' ? '<div class="card-ico">' . pv_icon($it['icon']) . '</div>' : '') . '<div class="card-t">' . e($it['text']) . '</div>' . ($it['desc'] !== '' ? '<div class="feat-d">' . e($it['desc']) . '</div>' : '') . '</div>'), $its)) . '</div>');
    }
}

/* 🧨 سوئیچ اصلی ۱۵۳ بلوک — تنها پیاده‌سازی (پیش‌تر ×۲ کپی می‌شد) */
if (!function_exists('pv_render_block_inner')) {
    function pv_render_block_inner(string $block, array $props): string
    {
        if ($block === '_page') { return ''; }
        $pvPreview = pv_renderer_is_preview();
        /* بلوک پنهان — سایت: حذف کامل؛ پیش‌نمایش: جعبه راهنما */
        if (isset($props['visible']) && $props['visible'] === false) {
            return $pvPreview ? '<div class="blk-hidden">🙈 بخش پنهان: <b>' . e($block) . '</b></div>' : '';
        }
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
                /* 🆕 v2.29 — خالی = از تنظیمات سایت‌ساز */
                $ph = trim((string)($props['phone'] ?? '')) ?: pv_brand_phone() ?: '۰۲۱-۱۲۳۴۵۶۷۸';
                $hr = trim((string)($props['hours'] ?? '')) ?: pv_brand_hours() ?: 'شنبه تا پنجشنبه ۹ تا ۲۰';
                return $PV('<div class="tb-row"><span>📞 <a href="tel:' . e($ph) . '" style="color:inherit;text-decoration:none" dir="ltr">' . e(fa_num($ph)) . '</a></span><span>🕐 ' . e($hr) . '</span></div>', 'topbar-blk');
            case 'header-v1':
            case 'header-v2':
            case 'header-v3':
                $menu = pvItems($props, [['', 'خانه', ''], ['', 'خدمات', ''], ['', 'مقالات', ''], ['', 'تماس', '']]);
                $menuHtml = '';
                foreach ($menu as $m) { $menuHtml .= '<span>' . e($m['text']) . '</span>'; }
                /* 🆕 v2.40 — تلفن/ساعات هدر از props (قبلاً hardcoded ۰۲۱-... بود و
                   فیلدهای پنل بی‌اثر بودند — تکمیل تنظیمات عناصر) */
                $hvPh = trim((string)($props['phone'] ?? '')) ?: pv_brand_phone() ?: '۰۲۱-۱۲۳۴۵۶۷۸';
                $hvHr = trim((string)($props['hours'] ?? '')) ?: pv_brand_hours() ?: 'شنبه تا پنجشنبه ۹ تا ۲۰';
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' header-blk' . ($block === 'header-v3' ? ' glass' : '') . (!empty($props['sticky']) ? ' sticky-demo' : '') . '">' . ($block === 'header-v2' ? '<div class="tb-row"><span>📞 <a href="tel:' . e($hvPh) . '" style="color:inherit;text-decoration:none" dir="ltr">' . e(fa_num($hvPh)) . '</a></span><span>🕐 ' . e($hvHr) . '</span></div>' : '') . '<div class="h-row">' . pv_brand_logo_html() . '<nav class="fake-nav">' . $menuHtml . '</nav><div class="fake-cta">' . e($props['btnText'] ?? 'ثبت درخواست') . '</div></div></div>';
            case 'hero':
                /* 🆕 v2.44 (S04) — عنوان/زیرعنوان پیش‌فرض از نام برند + شعار نمایندگی */
                $heroT = $title !== '' ? $title : pv_dyn_brand_title('خدمات تخصصی با قطعات اصلی');
                $heroS = trim((string)($props['subtitle'] ?? ''));
                if ($heroS === '') {
                    $heroAg = (string)(pv_brand_settings()['agency']['slogan_fa'] ?? '');
                    $heroS = $heroAg !== '' ? $heroAg : 'پاسخگویی ۷ روز هفته';
                }
                return $PV('<div class="hero-title">' . $heroT . '</div><div class="hero-sub">' . e($heroS) . '</div><div class="hero-btns"><span class="hero-btn">📞 تماس فوری</span><span class="hero-btn ghost">ثبت درخواست آنلاین</span></div>', 'hero-blk');
            case 'hero-slider':
            case 'universal-slider':
                /* 🎞 v2.29 — اسلایدر چندمقداری: هر تعداد اسلاید + نوع دلخواه + اسلاید واقعی با JS */
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk slider"' . $styleAttr . '><div class="hero-title">' . ($title ?: ($block === 'universal-slider' ? 'اسلایدر همه‌کاره' : 'اسلایدر تصویری')) . '</div>' . pv_slider_slides($props, $block === 'universal-slider' ? 'card' : 'image') . '</div>';
            case 'hero-split':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk split-hero"' . $styleAttr . '><div class="hero-split"><div><div class="hero-title">' . ($title ?: 'تعمیر لوازم خانگی در محل') . '</div><div class="hero-sub">' . e($props['subtitle'] ?? 'متن معرفی + دکمه فراخوان') . '</div><div class="hero-btns"><span class="hero-btn">شروع کنید</span></div></div>' . pvImg($props, '🛠️') . '</div></div>';
            case 'hero-video':
                /* 🆕 v2.40 — تصویر پس‌زمینه از props (IMG قبلاً بی‌اثر بود) + زیرعنوان
                   + دو دکمه قابل تنظیم (BTN) — تکمیل تنظیمات عناصر */
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk video"' . $styleAttr . '><div class="hero-title">' . ($title ?: 'هیرو با پس‌زمینه تصویر') . '</div>' . pvImg($props, '🎬', 'min-height:150px') . '<div class="hero-btns"><span class="hero-btn">' . e($props['btnText'] ?? 'مشاهده خدمات') . '</span><span class="hero-btn ghost">تماس فوری</span></div></div>';
            case 'hero-countdown':
                /* 🆕 v2.40 — دکمه CTA به شمارش معکوس اضافه شد (BTN فعال شد) */
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk"' . $styleAttr . '><div class="hero-title">' . ($title ?: 'کمپین سرویس دوره‌ای') . '</div>' . pvCountdown($props) . '<div class="hero-btns"><span class="hero-btn">' . e($props['btnText'] ?? 'همین حالا رزرو کنید') . '</span></div></div>';
            case 'text':
                /* 🎭 P2-21 — پیش‌نمایش: محتوای نمونه (رفتار قدیمی) */
                if ($pvPreview) {
                    return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="pv-text">' . nl2br(e($props['text'] ?? 'متن نمونه — این بخش در سایت به همین شکل نمایش داده می‌شود.')) . '</div></div>';
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="pv-text">' . nl2br(e($props['text'] ?? '')) . '</div></div>';
            case 'text-image':
            case 'intro':
                /* 🎭 P2-21 — پیش‌نمایش: محتوای نمونه (رفتار قدیمی) */
                if ($pvPreview) {
                    return '<div class="blk ' . $bgClass . ' ' . $padClass . ' split"' . $styleAttr . '><div><div class="blk-title">' . ($title ?: 'درباره برند') . '</div><div class="pv-text" style="font-size:12.5px">' . nl2br(e($props['text'] ?? 'معرفی کوتاه برند و خدمات تخصصی — این متن از پنل ویژگی‌ها قابل ویرایش است.')) . '</div></div>' . pvImg($props, '🖼️') . '</div>';
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' split"' . $styleAttr . '><div><div class="blk-title">' . ($title ?: 'درباره برند') . '</div><div class="pv-text" style="font-size:13px">' . nl2br(e($props['text'] ?? '')) . '</div></div>' . pvImg($props, '🖼️') . '</div></div>';
            case 'rich-text': {
                /* 🆕 v2.29 — آیتم‌های ویرایشگر مقدم بر خط‌های متن */
                $its = array_values(array_filter(pvItems($props, []), static fn($i) => trim((string)$i['text']) !== ''));
                if ($its) {
                    $items = array_map(static fn($i) => (string)$i['text'], $its);
                } else {
                    $lines = array_values(array_filter(array_map('trim', explode("\n", (string)($props['text'] ?? ''))), static fn($l) => $l !== ''));
                    $items = $lines ?: ['نصب و راه‌اندازی تخصصی', 'تعمیر با قطعات اصلی', '۶ ماه ضمانت قطعه و خدمات'];
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<ul class="pv-list">' . implode('', array_map(static fn($i) => '<li>✅ ' . e($i) . '</li>', $items)) . '</ul></div>';
            }
            case 'quote':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' quote-blk"><div class="quote">«' . e($props['text'] ?? 'کیفیت تعمیر، اعتبار ماست') . '»</div></div>';
            case 'two-col':
                $its = pvItems($props, [['', 'ستون اول', 'توضیح کوتاه ستون اول'], ['', 'ستون دوم', 'توضیح کوتاه ستون دوم']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c2">' . implode('', array_map(static fn($it) => pvA($it, '<div class="fake-card"><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>'), array_slice($its, 0, 2))) . '</div></div>';
            case 'three-col':
                $its = pvItems($props, [['', 'موضوع اول', 'توضیح'], ['', 'موضوع دوم', 'توضیح'], ['', 'موضوع سوم', 'توضیح']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c3">' . implode('', array_map(static fn($it) => pvA($it, '<div class="fake-card"><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>'), array_slice($its, 0, 3))) . '</div></div>';
            case 'section-columns':
            case 'section-split':
                /* 🎭 P2-21 — پیش‌نمایش: محتوای نمونه (رفتار قدیمی) */
                if ($pvPreview) {
                    /* 🏛 خود بخش چندستونی — فقط قاب/عنوان؛ ستون‌ها توسط renderLayoutLevel رندر می‌شوند */
                    return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . ($head ?: '<div class="blk-title" style="opacity:.55;margin-bottom:0">🏛 بخش چندستونی</div>') . '</div>';
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . ($head ?: '') . '</div>';
            case 'feature-list':
                $its = pvItems($props, [['⚡', 'سرعت عمل', 'اعزام تکنسین در کمتر از ۲ ساعت'], ['🛡️', 'ضمانت کتبی', '۶ ماه ضمانت قطعه و خدمات']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="feat-list">' . implode('', array_map(static fn($it) => pvA($it, '<div class="feat-row"><span class="feat-ico">' . pv_icon($it['icon'] ?: '⚡') . '</span><div><b>' . e($it['text']) . '</b>' . ($it['desc'] !== '' ? '<div class="feat-d">' . e($it['desc']) . '</div>' : '') . '</div></div>'), $its)) . '</div></div>';
            case 'services-grid':
            case 'features':
                $its = pvItems($props, [['🔧', 'تعمیر لباسشویی', 'با قطعات فابریک'], ['🧊', 'تعمیر یخچال', 'همان روز'], ['⚡', 'تعمیر ماکروویو', 'ضمانت‌دار'], ['🎓', 'سرویس دوره‌ای', 'در محل شما']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: ($block === 'features' ? 'چرا ما را انتخاب کنید؟' : 'خدمات ما')) . '</div><div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($it) => pvA($it, '<div class="fake-card"><div class="card-ico">' . pv_icon($it['icon'] ?: '🔧') . '</div><div class="card-t">' . e($it['text']) . '</div>' . ($it['desc'] !== '' ? '<div class="feat-d">' . e($it['desc']) . '</div>' : '') . '</div>'), $its)) . '</div></div>';
            case 'devices-grid':
                /* 🔄 v2.41 — پویا: دستگاه‌های واقعی همین برند از API
                   (درخواست کاربر: «همه عناصر قالب ساز رو پویا بکن»)؛
                   پیش‌نمایش قالب‌ساز همچنان نمونه نشان می‌دهد */
                $devs = [];
                if (!$pvPreview && defined('BRAND_ID') && function_exists('fetchFromAPI')) {
                    try {
                        $devs = (array)(fetchFromAPI('brand/' . BRAND_ID . '/devices', 300)['data'] ?? []);
                    } catch (Throwable $dynE) { $devs = []; }
                }
                $cards = '';
                if ($devs) {
                    foreach ($devs as $dev) {
                        $ico = trim((string)($dev['icon'] ?? '')) ?: '🔧';
                        $name = trim((string)($dev['name_fa'] ?? ''));
                        if ($name === '') { continue; }
                        $link = function_exists('localized_path') ? localized_path('/services') : '/services';
                        $desc = trim((string)($dev['description'] ?? ''));
                        $cards .= '<a href="' . e($link) . '" style="text-decoration:none;color:inherit"><div class="fake-card"><div class="card-ico">' . pv_icon($ico) . '</div><div class="card-t">' . e($name) . '</div>' . ($desc !== '' ? '<div class="feat-d">' . e(mb_substr($desc, 0, 60)) . '</div>' : '') . '</div></a>';
                    }
                } else {
                    foreach (['🌀 لباسشویی', '🧊 یخچال', '🍽️ ظرفشویی', '❄️ کولر', '📺 تلویزیون', '♨️ پکیج', '📻 مایکروویو', '🔥 فر و اجاق'] as $d) {
                        [$ico, $name] = array_pad(explode(' ', $d, 2), 2, '');
                        $cards .= '<div class="fake-card"><div class="card-ico">' . $ico . '</div><div class="card-t">' . $name . '</div></div>';
                    }
                }
                /* 🆕 v2.40 — ستون‌ها از props (قبلاً c4 ثابت — فیلد پنل بی‌اثر بود) */
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'دستگاه‌های تحت پوشش') . '</div><div class="cols c' . pvCols($props, 4) . '">' . $cards . '</div></div>';
            case 'articles-recent':
            case 'articles-grid':
                /* 🎭 P2-21 — پیش‌نمایش: محتوای نمونه (رفتار قدیمی)
                   🔄 v2.41 — پویا در سایت برند: «مقالات اخیرِ همین برند»
                   از API با تصویر شاخص و لینک واقعی (درخواست کاربر) */
                /* 🆕 v2.40 — ستون‌ها از props (قبلاً c3 ثابت — فیلد پنل بی‌اثر بود) */
                if ($pvPreview) {
                    return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'مقالات اخیر') . '</div><div class="cols c' . pvCols($props, 3) . '">' . str_repeat('<div class="fake-card"><div class="fake-img small">📰</div><div class="card-t">عنوان مقاله نمونه</div><div class="fl w100"></div></div>', 3) . '</div></div>';
                }
                $arts = [];
                if (defined('BRAND_ID') && function_exists('fetchFromAPI')) {
                    try {
                        $n = max(3, (int)pvCols($props, 3) * 2);
                        $arts = (array)(fetchFromAPI('brand/' . BRAND_ID . '/articles?per_page=' . $n, 300)['data'] ?? []);
                    } catch (Throwable $dynE) { $arts = []; }
                }
                if ($arts) {
                    $cards = '';
                    foreach ($arts as $a) {
                        $aTitle = trim((string)($a['title'] ?? ''));
                        if ($aTitle === '') { continue; }
                        $slug = rawurlencode((string)($a['slug'] ?? ''));
                        $link = '/blog/' . $slug;
                        $img = trim((string)($a['featured_image'] ?? ''));
                        $imgHtml = $img !== '' && preg_match('#^(https?://|uploads/|/)#i', $img)
                            ? '<div class="fake-img small"><img src="' . e(preg_match('#^uploads/#i', $img) && function_exists('pv_asset') ? pv_asset($img) : $img) . '" alt="' . e($aTitle) . '" style="width:100%;height:100%;object-fit:cover" loading="lazy"></div>'
                            : '<div class="fake-img small">📰</div>';
                        $excerpt = trim((string)preg_replace('#<[^>]+>#', ' ', (string)($a['excerpt'] ?? '')));
                        $cards .= '<a href="' . e($link) . '" style="text-decoration:none;color:inherit"><div class="fake-card">' . $imgHtml . '<div class="card-t">' . e($aTitle) . '</div>' . ($excerpt !== '' ? '<div class="feat-d">' . e(mb_substr($excerpt, 0, 90)) . '…</div>' : '') . '</div></a>';
                    }
                    if ($cards !== '') {
                        return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'مقالات اخیر') . '</div><div class="cols c' . pvCols($props, 3) . '">' . $cards . '</div></div>';
                    }
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'مقالات اخیر') . '</div><div class="cols c' . pvCols($props, 3) . '">' . str_repeat('<div class="fake-card"><div class="fake-img small">📰</div><div class="card-t">عنوان مقاله نمونه</div></div>', 3) . '</div></div>';
            case 'team':
                $its = pvItems($props, [['👨‍🔧', 'مهندس کریمی', 'متخصص لباسشویی'], ['👩‍🔧', 'مهندس رضایی', 'متخصص یخچال و فریزر'], ['🧑‍🔧', 'مهندس موسوی', 'متخصص تلویزیون']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'تیم ما') . '</div><div class="cols c' . pvCols($props, 4) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="fake-ava">' . pv_icon($it['icon'] ?: '👨‍🔧') . '</div><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
            case 'pricing-table':
                $its = pvItems($props, [['', 'دریافت و عیب‌یابی تخصصی', 'رایگان'], ['', 'سرویس دوره‌ای لباسشویی', 'از ۴۵۰ هزار تومان'], ['', 'شارژ گاز کولر گازی', 'از ۹۰۰ هزار تومان']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'تعرفه خدمات') . '</div><div class="price-table">' . implode('', array_map(static fn($it) => '<div class="price-row"><span>' . e($it['text']) . '</span><b>' . e($it['desc']) . '</b></div>', $its)) . '</div></div>';
            case 'brands-links':
                /* 🔄 v2.41 — پویا: برندهای واقعی نمایندگی از API با لینک سایت
                   هر برند؛ آیتم‌های دستی props فقط در پیش‌نمایش/فال‌بک */
                $brs = [];
                if (function_exists('fetchFromAPI')) {
                    try {
                        $brs = (array)(fetchFromAPI('brands', 300)['data'] ?? []);
                    } catch (Throwable $dynE) { $brs = []; }
                }
                if ($brs) {
                    $cards = '';
                    foreach ($brs as $b) {
                        $name = trim((string)($b['name_fa'] ?? ''));
                        if ($name === '') { continue; }
                        if (defined('BRAND_ID') && (int)($b['id'] ?? 0) === (int)BRAND_ID) { continue; } /* خود برند نه */
                        $domain = trim((string)($b['domain'] ?? ''));
                        $href = $domain !== '' ? 'https://' . $domain : (function_exists('localized_path') ? localized_path('/other-brands') : '/other-brands');
                        $logo = trim((string)($b['logo'] ?? ''));
                        $inner = $logo !== '' && preg_match('#^(https?://|uploads/|/)#i', $logo)
                            ? '<img src="' . e(preg_match('#^uploads/#i', $logo) && function_exists('pv_asset') ? pv_asset($logo) : $logo) . '" alt="' . e($name) . '" style="max-width:100%;max-height:100%;object-fit:contain" loading="lazy">'
                            : '🏷️';
                        $cards .= '<a href="' . e($href) . '" class="fake-logo-s" title="' . e($name) . '"' . ($domain !== '' ? ' target="_blank" rel="nofollow"' : '') . '>' . $inner . '</a>';
                    }
                    if ($cards !== '') {
                        return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'برندهای مورد خدمت') . '</div><div class="cols c' . max(3, pvCols($props, 6)) . '">' . $cards . '</div></div>';
                    }
                }
                $its = pvItems($props, [['🏷️', 'ال‌جی'], ['🏷️', 'سامسونگ'], ['🏷️', 'بوش'], ['🏷️', 'سونی'], ['🏷️', 'اسنوا'], ['🏷️', 'پاکس']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'برندهای مورد خدمت') . '</div><div class="cols c' . max(3, pvCols($props, 6)) . '">' . implode('', array_map(static fn($it) => '<div class="fake-logo-s" title="' . e($it['text']) . '">' . pv_icon($it['icon'] ?: '🏷️') . '</div>', $its)) . '</div></div>';
            case 'hero-form':
            case 'contact-form':
            case 'request-form':
                /* 📋 v2.31 — فرم واقعی با فیلدهای قابل تنظیم + مقصد ارسال
                   (درخواست کاربر: «عنصر فرم درخواست خدمات با فیلدهای کامل باشه و
                   امکان غیرفعال کردن هر کدام از فیلدها هم باشه» + «تنظیم کنیم که
                   اطلاعات فرم به کجا ارسال بشه»)
                   🎨 v2.33 — hero-form کلاس هیرو می‌گیرد (طراحی تفصیلی ادغام‌شده) */
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ($block === 'hero-form' ? ' hero-blk split-hero' : '') . '"' . $styleAttr . '>' . pv_real_form($block, $props, $title) . '</div>';
            case 'newsletter-form':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '>' . pv_real_form($block, $props, $title) . '</div>';
            case 'callback-form':
            case 'quick-contact-form':
            case 'appointment-form':
            case 'appointment-compact':
            case 'survey-form':
                /* 📋 v2.31 — همه فرم‌های دیگر هم واقعی شدند */
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '>' . pv_real_form($block, $props, $title) . '</div>';
            case 'custom-form':
                /* 🧩 v2.44 (S12) — فرم سفارشی از فرم‌ساز: تعریف از API سایت‌ساز
                   خوانده می‌شود (فیلدها + دکمه + پیام موفقیت + مقصد ارسال) و
                   با همان موتور ارسال .sahand-form کار می‌کند. */
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '>' . pv_custom_form($props, $title) . '</div>';
            case 'counter-stats':
            case 'stats':
                /* 🆕 v2.40 — عنوان (T) + زیرعنوان (S) فعال شد (قبلاً نوار بدون تیتر) */
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' stats-blk">' . $head . pvStatStrip($props) . '</div>';
            case 'progress-bars':
            case 'skill-bars':
                /* 🎭 P2-21 — پیش‌نمایش: محتوای نمونه (رفتار قدیمی) */
                if ($pvPreview) {
                    /* 🎨 v2.31 — رنگ هر نوار از آیتم
                    /* 🎨 v2.29 — رنگ نوارها قابل تنظیم (barColor) */
                    $its = pvItems($props, $block === 'skill-bars' ? [['', 'تعمیر برد و الکترونیک', '88'], ['', 'یخچال و فریزر', '92'], ['', 'ماشین لباس', '95']] : [['', 'سرعت تعمیر', '90'], ['', 'کیفیت قطعات', '95'], ['', 'رضایت مشتری', '98']]);
                    $barC = ''; /* v2.31: رنگ از هر آیتم */
                    $out = '';
                    foreach ($its as $it) { $p = max(3, min(100, (int)(preg_replace('/[^0-9]/', '', $it['desc'] ?: $it['icon']) ?: 80))); $ic = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($it['color'] ?? '')) ? (string)$it['color'] : (preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($props['barColor'] ?? '')) ? (string)$props['barColor'] : '');
                        $out .= '<div class="pbar"><span>' . e($it['text']) . '</span><div class="track"><div class="fill" style="width:' . $p . '%;' . ($ic !== '' ? 'background:' . e($ic) . ';' : '') . '"></div></div></div>'; }
                    return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . $out . '</div>';
                }
                /* 🎨 v2.29 + v2.31 — رنگ هر نوار از آیتم، بعد barColor، بعد پیش‌فرض */
                $its = pvItems($props, [['', 'سرعت تعمیر', '90'], ['', 'کیفیت قطعات', '95'], ['', 'رضایت مشتری', '98']]);
                $out = '';
                foreach ($its as $it) {
                    $p = max(3, min(100, (int)(preg_replace('/[^0-9]/', '', $it['desc'] ?: $it['icon']) ?: 80)));
                    $ic = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($it['color'] ?? '')) ? (string)$it['color']
                        : (preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($props['barColor'] ?? '')) ? (string)$props['barColor'] : '');
                    $out .= '<div class="pbar"><span>' . e($it['text']) . '</span><div class="track"><div class="fill" style="width:' . $p . '%;' . ($ic !== '' ? 'background:' . e($ic) . ';' : '') . '"></div></div></div>';
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . $out . '</div>';
            case 'testimonials': {
                /* 🔄 v2.42 — پویا: دیدگاه‌های واقعی مشتریان همین برند از API
                   (فال‌بک: آیتم‌های دستی props) */
                $its = [];
                foreach (pv_dyn_comments(6) as $c) {
                    $body = trim((string)($c['body'] ?? ''));
                    if ($body !== '') {
                        $its[] = ['icon' => (string)($c['author'] ?? ''), 'text' => $body, 'desc' => '', 'link' => '', 'color' => ''];
                    }
                }
                if (!$its) {
                    $its = pvItems($props, [['علی محمدی', 'سرویس سریع و منظم بود؛ راضی بودم.'], ['مریم احمدی', 'قیمت شفاف و ضمانت واقعی.']]);
                }
                /* 🔄 v2.41 — همه نظرات با اسلایدر dots (قبلاً فقط اولین نظر
                   نمایش داده می‌شد و بقیه آیتم‌ها بی‌اثر بودند) */
                $cards = '';
                foreach ($its as $i => $q) {
                    $cards .= '<div class="ss-tst"' . ($i === 0 ? '' : ' style="display:none"') . '><div class="quote">«' . e($q['text']) . '»</div>' . ($q['icon'] !== '' ? '<div class="feat-d" style="text-align:center;font-weight:800">— ' . e($q['icon']) . '</div>' : '') . '</div>';
                }
                $dots = count($its) > 1 ? '<div class="slider-dots" data-tst-dots="1">' . implode(' ', array_map(static fn($i) => '<span style="cursor:pointer">' . ($i === 0 ? '●' : '○') . '</span>', array_keys($its))) . '</div>' : '';
                $js = count($its) > 1 ? '<script>document.addEventListener("DOMContentLoaded",function(){var ws=document.querySelectorAll(".ss-tst"),ds=document.querySelectorAll("[data-tst-dots] span"),ci=0;ds.forEach(function(d,i){d.addEventListener("click",function(){ws[ci]&&(ws[ci].style.display="none");ds[ci]&&(ds[ci].textContent="○");ci=i;ws[ci]&&(ws[ci].style.display="");ds[ci]&&(ds[ci].textContent="●")})});setInterval(function(){if(ws.length&&ds.length>1){ds[(ci+1)%ws.length].click()}},6000)});</script>' : '';
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'نظرات مشتریان') . '</div>' . $cards . $dots . '</div>' . $js;
            }
            case 'faq-accordion':
                /* 🔄 v2.41 — پویا در سایت برند: سوالات متداول واقعی همین برند
                   از API؛ آیتم‌های دستی props در پیش‌نمایش/فال‌بک */
                $faqs = [];
                if (!$pvPreview && defined('BRAND_ID') && function_exists('fetchFromAPI')) {
                    try {
                        $faqs = (array)(fetchFromAPI('brand/' . BRAND_ID . '/faqs', 600)['data'] ?? []);
                    } catch (Throwable $dynE) { $faqs = []; }
                }
                $its = [];
                foreach ($faqs as $f) {
                    $qText = trim((string)($f['question'] ?? ''));
                    $aText = trim((string)($f['answer'] ?? ''));
                    if ($qText !== '' && $aText !== '') {
                        $its[] = ['icon' => '', 'text' => $qText, 'desc' => $aText, 'link' => '', 'color' => ''];
                    }
                }
                if (!$its) {
                    $its = pvItems($props, [['', 'هزینه عیب‌یابی چقدر است؟', 'در صورت تعمیر نزد ما رایگان است.'], ['', 'چقدر طول می‌کشد؟', 'اکثر تعمیرها همان روز انجام می‌شود.'], ['', 'ضمانت دارید؟', 'بله — ۶ ماه ضمانت کتبی.']]);
                }
                /* 🆕 v2.41 — آکاردئون واقعی کلیک‌شونده (قبلاً دکمه + بی‌عمل) */
                $items = '';
                foreach ($its as $i => $it) {
                    $items .= '<div class="acc-w"><div class="acc" data-acc="' . $i . '">' . e($it['text']) . ' <b>＋</b></div><div class="acc-body" style="display:none;padding:4px 10px 12px;font-size:12.5px;color:var(--color-text-light)">' . e($it['desc']) . '</div></div>';
                }
                $js = '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("[data-acc]").forEach(function(h){h.addEventListener("click",function(){var b=h.nextElementSibling;var open=b.style.display!=="none";b.style.display=open?"none":"";h.querySelector("b").textContent=open?"＋":"−";h.style.fontWeight=open?"700":"800"})})});</script>';
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'سوالات متداول') . '</div>' . $items . '</div>' . $js;
            case 'tabs': {
                $its = pvItems($props, [['', 'تعمیر'], ['', 'سرویس'], ['', 'نصب']]);
                $tabs = '';
                foreach ($its as $i => $it) { $tabs .= '<span class="tab' . ($i === 0 ? ' cur' : '') . '">' . e($it['text']) . '</span>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'تب‌بندی محتوا') . '</div><div class="tabs-row">' . $tabs . '</div><div class="fake-card" style="text-align:right"><div class="fl w100"></div><div class="fl w90"></div><div class="fl w60"></div></div></div>';
            }
            case 'timeline': {
                $its = pvItems($props, [['✓', 'ثبت درخواست', 'انجام شد'], ['✓', 'عیب‌یابی و پیش‌فاکتور', 'انجام شد'], ['۳', 'تعمیر در حال انجام', 'در جریان'], ['۴', 'تحویل و ضمانت', 'در انتظار']]);
                $out = '';
                foreach ($its as $i => $it) { $cls = $i < 2 ? ' done' : ($i === 2 ? ' cur' : ''); $out .= '<div class="tl-item' . $cls . '"><span class="tl-dot">' . pv_icon($it['icon'] ?: (string)($i + 1)) . '</span><div>' . e($it['text']) . ($it['desc'] !== '' ? '<div class="feat-d">' . e($it['desc']) . '</div>' : '') . '</div></div>'; }
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
                /* 🆕 v2.29 — متن/لینک خالی = از تنظیمات سایت‌ساز (آدرس + نقشه) */
                $mu = trim((string)($props['mapUrl'] ?? '')) ?: pv_brand_map_url();
                $mtxt = trim((string)($props['text'] ?? '')) ?: (pv_brand_address() !== '' ? '📍 ' . pv_brand_address() : '📍 نقشه محدوده خدمات');
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'محدوده خدمات') . '</div><div class="fake-map">' . e($mtxt) . ($mu !== '' ? ' — <a href="' . e($mu) . '" target="_blank" rel="noopener" style="color:var(--p)">مشاهده در نقشه ↗</a>' : '') . '</div></div>';
            }
            case 'cta-phone':
                $ph5 = trim((string)($props['phone'] ?? '')) ?: pv_brand_phone() ?: '۰۲۱-۱۲۳۴۵۶۷۸';
                /* 🆕 v2.40 — زیرعنوان (S) فعال شد */
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' cta-blk"><div class="hero-title">' . ($title ?: 'همین حالا تماس بگیرید') . '</div>' . (!empty($props['subtitle']) ? '<div class="hero-sub">' . e($props['subtitle']) . '</div>' : '') . '<a href="tel:' . e($ph5) . '" class="cta-num" dir="ltr" style="color:inherit;text-decoration:none">' . e(fa_num($ph5)) . '</a></div>';
            case 'cta-request':
            case 'cta-banner':
                /* 🆕 v2.40 — زیرعنوان (S) فعال شد */
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' cta-blk"><div class="hero-title">' . ($title ?: 'درخواست تعمیر خود را ثبت کنید') . '</div>' . (!empty($props['subtitle']) ? '<div class="hero-sub">' . e($props['subtitle']) . '</div>' : '') . '<span class="hero-btn">' . e($props['btnText'] ?? '📝 ثبت درخواست') . '</span></div>';
            case 'sticky-mobile-cta':
                $ph6 = trim((string)($props['phone'] ?? '')) ?: pv_brand_phone() ?: '۰۲۱-۱۲۳۴۵۶۷۸';
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' sticky-cta-demo"><span>📞 <a href="tel:' . e($ph6) . '" style="color:inherit;text-decoration:none" dir="ltr">' . e(fa_num($ph6)) . '</a></span><a href="/request" class="hero-btn" style="text-decoration:none">' . e($props['btnText'] ?? 'ثبت درخواست') . '</a></div>';
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
            case 'icon-list': {
                /* 🆕 v2.43 (S04) — پویا از تنظیمات سایت‌ساز: ساعات کاری واقعی +
                   محدوده اعزام از آدرس برند (قبلاً «۷ روز هفته از ۹ تا ۲۰» و
                   «کل تهران و کرج» hardcoded بود — برای نمایندگی تبریز غلط) */
                $ilHours = trim((string)($props['hoursText'] ?? '')) ?: pv_brand_hours() ?: '۷ روز هفته از ۹ تا ۲۰';
                $ilAddr  = pv_brand_address();
                $ilArea  = trim((string)($props['areaText'] ?? ''));
                if ($ilArea === '') {
                    /* شهر از اولین آدرس برند: «تبریز» → «کل تبریز» */
                    $ilCity = preg_split('/[،,]/u', $ilAddr)[0] ?? '';
                    $ilArea = $ilCity !== '' ? 'کل ' . trim($ilCity) : 'منطقه نمایندگی';
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="feat-list"><div class="feat-row"><span class="feat-ico">📞</span><div><b>پاسخگویی تلفنی</b><div class="feat-d">' . e($ilHours) . '</div></div></div><div class="feat-row"><span class="feat-ico">📍</span><div><b>اعزام در محل</b><div class="feat-d">' . e($ilArea) . '</div></div></div></div></div>';
            }
            case 'separator':
                return '<hr class="blk-sep">';
            case 'spacer':
                /* 🎭 P2-21 — پیش‌نمایش: محتوای نمونه (رفتار قدیمی) */
                if ($pvPreview) {
                    return '<div class="blk-spacer" style="height:' . (int)($props['height'] ?? 46) . 'px" title="فاصله"></div>';
                }
                return '<div class="blk-spacer" style="height:' . (int)($props['height'] ?? 46) . 'px"></div>';
            case 'footer-simple': {
                $fl = pvItems($props, [['', 'خدمات', ''], ['', 'مقالات', ''], ['', 'تماس', '']]);
                $flHtml = '';
                foreach ($fl as $l) { $flHtml .= '<span>' . e($l['text']) . '</span>'; }
                /* 🆕 v2.44 (S04) — سوشال‌ها و تلفن از تنظیمات سایت‌ساز */
                $fsSoc = pv_dyn_socials();
                $socHtml = '';
                foreach ($fsSoc ?: [['icon' => '📣', 'text' => 'شبکه‌های اجتماعی', 'link' => ''], ['icon' => '📷', 'text' => 'اینستاگرام', 'link' => '']] as $s) {
                    $socHtml .= '<span>' . pv_icon($s['icon']) . ' ' . e($s['text']) . '</span>';
                }
                $fsPh = trim((string)($props['phone'] ?? '')) ?: pv_brand_phone();
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' footer-blk">' . pv_brand_logo_html() . '<nav class="fake-nav" style="justify-content:center">' . $flHtml . '</nav><div class="soc-row">' . $socHtml . '</div>' . ($fsPh !== '' ? '<div class="feat-d" style="text-align:center;margin-top:6px">📞 <a href="tel:' . e($fsPh) . '" style="color:inherit" dir="ltr">' . e(fa_num($fsPh)) . '</a></div>' : '') . '</div>';
            }
            case 'footer-contact':
                /* 🆕 v2.29 — از تنظیمات سایت‌ساز */
                $fcPh = trim((string)($props['phone'] ?? '')) ?: pv_brand_phone() ?: '۰۲۱-۱۲۳۴۵۶۷۸';
                /* 🆕 v2.40 — متن (X) = آدرس دلخواه (قبلاً فقط از تنظیمات برند) */
                $fcAd = trim((string)($props['text'] ?? '')) ?: pv_brand_address() ?: 'آدرس نمایندگی — از تنظیمات سایت‌ساز';
                $fcHr = trim((string)($props['hours'] ?? '')) ?: pv_brand_hours() ?: 'شنبه تا پنجشنبه، ۹ صبح تا ۸ شب';
                /* 🆕 v2.44 (S04) — ایمیل واقعی هم در کارت تماس فوتر (در صورت وجود) */
                $fcEm = pv_dyn_email();
                $fcEmHtml = $fcEm !== '' ? '<br>✉️ <a href="mailto:' . e($fcEm) . '" style="color:inherit" dir="ltr">' . e($fcEm) . '</a>' : '';
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' footer-blk"><div class="cols c3"><div>' . pv_brand_logo_html() . '</div><div><div class="card-t">تماس</div><div class="feat-d">📞 <a href="tel:' . e($fcPh) . '" style="color:inherit" dir="ltr">' . e(fa_num($fcPh)) . '</a><br>📍 ' . e($fcAd) . $fcEmHtml . '</div></div><div><div class="card-t">ساعات کاری</div><div class="feat-d">' . e($fcHr) . '</div></div></div></div>';
            case 'copyright':
                /* 🆕 v2.44 (S04) — متن پیش‌فرض با نام برند واقعی */
                $crT = trim((string)($props['text'] ?? ''));
                if ($crT === '' && defined('BRAND_NAME_FA') && !pv_renderer_is_preview() && trim((string)BRAND_NAME_FA) !== '') {
                    $crT = '© تمامی حقوق برای ' . trim((string)BRAND_NAME_FA) . ' محفوظ است';
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' crump-blk">' . e($crT !== '' ? $crT : '© تمامی حقوق برای نمایندگی محفوظ است') . '</div>';
            case 'notification-bar':
                /* 🎭 P2-21 — پیش‌نمایش: محتوای نمونه (رفتار قدیمی) */
                if ($pvPreview) {
                    return '<div class="blk notif-bar ' . e($props['notifColor'] ?? 'info') . '" style="padding:8px 14px">' . e($props['text'] ?? '🎉 سرویس ویژه تعطیلات — ۱۵٪ تخفیف سرویس دوره‌ای') . '</div>';
                }
                return '<div class="blk notif-bar ' . e($props['notifColor'] ?? 'info') . '" style="padding:8px 14px">' . e($props['text'] ?? '🎉 سرویس ویژه تعطیلات') . '</div>';
            case 'hero-marquee':
                return '<div class="blk marquee-blk"><div class="marquee-track"><span>' . e($props['text'] ?? '⚡ اعزام تکنسین در کمتر از ۲ ساعت — ⭐ بیش از ۵۰ هزار تعمیر موفق') . '</span></div></div>';
            case 'brand-story': {
                $its = pvItems($props, [['۱۳۸۵', 'شروع فعالیت', 'با یک تعمیرگاه کوچک'], ['۱۳۹۲', 'نمایندگی رسمی', 'اخذ گواهی‌های تخصصی'], ['۱۴۰۲', '۵۰ هزارمین تعمیر', 'و بیش از ۳۰ همکار']]);
                $out = '';
                foreach ($its as $it) { $out .= '<div class="story-sec"><span class="story-year">' . pv_icon($it['icon']) . '</span><div><b>' . e($it['text']) . '</b><div class="feat-d">' . e($it['desc']) . '</div></div></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="story-wrap">' . $out . '</div></div>';
            }
            case 'area-list':
                /* 🆕 v2.44 (S04) — مناطق از آدرس‌های واقعی سایت‌ساز (نه محله‌های تهران) */
                $alIts = pvItems($props, []);
                if (!$alIts) {
                    $alDyn = pv_dyn_areas(8);
                    $alCity = pv_dyn_city();
                    if ($alDyn) {
                        $alIts = array_map(static fn($a) => ['icon' => '📍', 'text' => $a, 'desc' => '', 'link' => '', 'color' => ''], $alDyn);
                    } elseif ($alCity !== '') {
                        $alIts = [['icon' => '📍', 'text' => 'کل ' . $alCity, 'desc' => '', 'link' => '', 'color' => '']];
                    } else {
                        $alIts = [['', 'منطقه اول', ''], ['', 'منطقه دوم', ''], ['', 'منطقه سوم', ''], ['', 'منطقه چهارم', '']];
                    }
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="chip-row">' . implode('', array_map(static fn($a) => '<span class="chip">📍 ' . e($a['text']) . '</span>', $alIts)) . '</div></div>';
            case 'checklist':
                $its = pvItems($props, [['☑️', 'دستگاه را روشن و خاموش کنید و دوباره امتحان کنید'], ['☑️', 'کد خطای نمایشگر را یادداشت کنید'], ['☑️', 'صداهای غیرعادی و بوی سوختگی را بررسی کنید'], ['☑️', 'فاکتور خرید و گارانتی را آماده داشته باشید']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<div class="feat-list">' . implode('', array_map(static fn($it) => pvA($it, '<div class="feat-row"><span class="feat-ico">' . pv_icon($it['icon'] ?: '☑️') . '</span><div>' . e($it['text']) . '</div></div>'), $its)) . '</div></div>';
            case 'search-bar':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="search-wrap"><span class="search-ico">🔎</span><div class="fake-input" style="flex:1;border:none">' . e($props['placeholder'] ?? 'جستجوی کد خطا، مقاله یا دستگاه...') . '</div><span class="hero-btn">جستجو</span></div></div>';
            case 'certificates':
                $its = pvItems($props, [['🎖️', 'نمایندگی رسمی', 'از سال ۱۳۸۵'], ['🏆', 'برند برتر خدمات', 'رأی مشتریان ۱۴۰۲'], ['📋', 'مجوز اتحادیه', 'کد ۱۲۳۴۵']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'گواهینامه‌ها و افتخارات') . '</div><div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="card-ico">' . pv_icon($it['icon'] ?: '🎖️') . '</div><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
            case 'review-grid':
                /* 🔄 v2.42 — پویا: دیدگاه‌های واقعی مشتریان همین برند */
                $its = [];
                foreach (pv_dyn_comments(6) as $c) {
                    $body = trim((string)($c['body'] ?? ''));
                    if ($body !== '') {
                        $its[] = ['icon' => (string)($c['author'] ?? ''), 'text' => $body, 'desc' => '', 'link' => '', 'color' => ''];
                    }
                }
                if (!$its) {
                    $its = pvItems($props, [['علی محمدی', 'سرویس سریع و منظم بود؛ راضی بودم.'], ['مریم احمدی', 'قیمت شفاف و ضمانت واقعی.'], ['رضا کریمی', 'تکنسین دقیق و حرفه‌ای اعزام شد.']]);
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'مشتریان ما چه می‌گویند') . '</div><div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($it) => pvA($it, '<div class="fake-card"><div class="feat-d" style="direction:ltr;text-align:left">⭐⭐⭐⭐⭐</div><div class="feat-d">«' . e($it['text']) . '»</div><b class="feat-d">' . pv_icon($it['icon']) . '</b></div>'), $its)) . '</div></div>';
            case 'contact-cards':
                /* 🆕 v2.29 — خالی = از تنظیمات سایت‌ساز (تلفن/آدرس) + آیتم‌ها لینک‌دار */
                $ccIts = array_values(array_filter(pvItems($props, []), static fn($i) => trim((string)$i['text']) !== ''));
                if (!$ccIts) {
                    $ccPh = pv_brand_phone();
                    $ccAd = pv_brand_address();
                    $ccIts = [['icon' => '📞', 'text' => 'تلفن', 'desc' => $ccPh !== '' ? fa_num($ccPh) : '۰۲۱-۱۲۳۴۵۶۷۸', 'link' => $ccPh !== '' ? 'tel:' . $ccPh : '']];
                    if ($ccAd !== '') { $ccIts[] = ['icon' => '📍', 'text' => 'آدرس', 'desc' => $ccAd, 'link' => pv_brand_map_url()]; }
                    $ccIts[] = ['icon' => '🕐', 'text' => 'ساعات کاری', 'desc' => (pv_brand_hours() ?: 'شنبه تا پنجشنبه ۹ تا ۲۰'), 'link' => ''];
                    /* 🆕 v2.44 (S04) — کارت ایمیل واقعی در صورت وجود در تنظیمات */
                    $ccEm = pv_dyn_email();
                    if ($ccEm !== '') { $ccIts[] = ['icon' => '✉️', 'text' => 'ایمیل', 'desc' => $ccEm, 'link' => 'mailto:' . $ccEm]; }
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'راه‌های ارتباطی') . '</div><div class="cols c3">' . implode('', array_map(static fn($it) => pvA($it, '<div class="fake-card"><div class="card-ico">' . pv_icon($it['icon'] ?: '📞') . '</div><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>'), $ccIts)) . '</div></div>';
            case 'stats-grid':
                /* 🎭 P2-21 — پیش‌نمایش: محتوای نمونه (رفتار قدیمی) */
                if ($pvPreview) {
                    $its = pvItems($props, [['۱۲+', 'سال تجربه'], ['۵۰k', 'تعمیر موفق'], ['۹۸٪', 'رضایت'], ['۴۲', 'نوع دستگاه'], ['۲۴/۷', 'پشتیبانی'], ['۶ ماه', 'ضمانت']]);
                    return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'سهند سرویس در یک نگاه') . '</div><div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card" style="text-align:center"><div class="stat-n">' . pv_icon($it['icon'] ?: '۰') . '</div><div class="feat-d">' . e($it['text']) . '</div></div>', $its)) . '</div></div>';
                }
                $its = pvItems($props, [['۱۲+', 'سال تجربه'], ['۵۰k', 'تعمیر موفق'], ['۹۸٪', 'رضایت'], ['۴۲', 'نوع دستگاه'], ['۲۴/۷', 'پشتیبانی'], ['۶ ماه', 'ضمانت']]);
                /* 🆕 v2.44 (S04) — عنوان پیش‌فرض آمار: نام برند واقعی */
                $sgT = $title !== '' ? $title : (defined('BRAND_NAME_FA') && trim((string)BRAND_NAME_FA) !== '' ? trim((string)BRAND_NAME_FA) . ' در یک نگاه' : 'در یک نگاه');
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . $sgT . '</div><div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card" style="text-align:center"><div class="stat-n">' . pv_icon($it['icon'] ?: '۰') . '</div><div class="feat-d">' . e($it['text']) . '</div></div>', $its)) . '</div></div>';
            case 'before-after':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'نتیجه تعمیر حرفه‌ای') . '</div><div class="ba-wrap"><div class="ba-side"><div class="ba-tag bad">قبل</div><div class="fake-card" style="text-align:center">' . e($props['text'] ?? 'دستگاه روشن نمی‌شود — کد خطا فعال') . '</div></div><div class="ba-arrow">←</div><div class="ba-side"><div class="ba-tag ok">بعد</div><div class="fake-card" style="text-align:center">' . e($props['textAfter'] ?? 'کارکرد کامل — تست‌شده و ضمانت‌دار') . '</div></div></div></div>';
            case 'cta-whatsapp': {
                /* 🆕 v2.44 (S04) — شماره از props یا تنظیمات سایت‌ساز (واتساپ/موبایل) */
                $cwPh = trim((string)($props['phone'] ?? ''));
                if ($cwPh === '') {
                    $st = pv_brand_settings();
                    $wa = trim((string)((array)($st['contacts']['phones'] ?? []) + [])['whatsapp'] ?? '');
                    $cwPh = $wa !== '' ? $wa : pv_brand_phone();
                }
                $cwWa = '';
                if (preg_match('/^\+?\d{7,15}$/', str_replace([' ', '-'], '', $cwPh))) {
                    $cwWa = 'https://wa.me/' . preg_replace('/\D/', '', $cwPh);
                }
                $btn = '<span class="hero-btn" style="background:#16a34a">💬 ' . ($cwPh !== '' ? 'گفتگو در واتساپ — ' . e($cwPh) : 'گفتگو در واتساپ') . '</span>';
                if ($cwWa !== '') { $btn = '<a href="' . e($cwWa) . '" target="_blank" rel="noopener" style="text-decoration:none">' . $btn . '</a>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' cta-blk">' . ($title ? '<div class="blk-title" style="margin-bottom:9px">' . e($title) . '</div>' : '') . '<div class="hero-btns">' . $btn . '<span class="hero-btn ghost">📞 تماس تلفنی</span></div></div>';
            }
            case 'warranty-banner':
                /* 🆕 v2.44 (S04) — متن ضمانت از تنظیمات سایت‌ساز (تب ضمانت) */
                $wbT = trim((string)($props['title'] ?? ''));
                $wbX = trim((string)($props['text'] ?? ''));
                if ($wbT === '' || $wbX === '') {
                    $wbDyn = pv_dyn_warranty();
                    if ($wbT === '') { $wbT = $wbDyn['period'] !== '' ? 'ضمانت ' . $wbDyn['period'] . ' روی قطعه و خدمات' : 'ضمانت کتبی روی قطعه و خدمات'; }
                    if ($wbX === '') { $wbX = $wbDyn['text'] !== '' ? trim(mb_substr(strip_tags($wbDyn['text']), 0, 110, 'UTF-8')) : 'در صورت ایراد مجدد، تعمیر اصلاحی رایگان'; }
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="feat-row" style="align-items:center"><span class="feat-ico" style="font-size:30px">🛡️</span><div><b style="font-size:15px">' . e($wbT) . '</b><div class="feat-d">' . e($wbX) . '</div></div><span class="hero-btn" style="margin-inline-start:auto">مشاهده شرایط</span></div></div>';
            case 'working-hours':
                /* 🆕 v2.29 — اگر آیتمی تنظیم نشده باشد از ساعات کاری سایت‌ساز پر می‌شود */
                $its = array_values(array_filter(pvItems($props, []), static fn($i) => trim((string)$i['text']) !== ''));
                if (!$its) {
                    $dynHr = pv_brand_hours();
                    $its = $dynHr !== '' ? [['text' => 'روزهای کاری', 'desc' => $dynHr, 'icon' => '']] : [['text' => 'شنبه تا چهارشنبه', 'desc' => '۹ صبح تا ۸ شب', 'icon' => ''], ['text' => 'پنجشنبه', 'desc' => '۹ صبح تا ۲ ظهر', 'icon' => ''], ['text' => 'جمعه', 'desc' => '⚠️ فقط امداد فوری', 'icon' => '']];
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'ساعات کاری') . '</div><div class="price-table">' . implode('', array_map(static fn($it) => '<div class="price-row"><span>' . e($it['text']) . '</span><b>' . e($it['desc']) . '</b></div>', $its)) . '</div></div>';
            case 'social-follow':
                /* 🆕 v2.44 (S04) — لینک‌های واقعی شبکه‌های اجتماعی از تنظیمات سایت‌ساز */
                $sfIts = pvItems($props, []);
                if (!$sfIts) {
                    $sfDyn = pv_dyn_socials();
                    if ($sfDyn) { $sfIts = $sfDyn; }
                }
                if (!$sfIts) { $sfIts = [['📡', 'تلگرام', ''], ['📷', 'اینستاگرام', ''], ['💬', 'واتساپ', '']]; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'ما را دنبال کنید') . '</div><div class="hero-btns">' . implode('', array_map(static fn($it) => pvA($it, '<span class="hero-btn">' . pv_icon($it['icon'] ?: '📣') . ' ' . e($it['text']) . '</span>'), $sfIts)) . '</div></div>';
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
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<div class="num-list">' . implode('', array_map(static fn($it) => '<div class="num-row"><span class="num-n">' . pv_icon($it['icon']) . '</span><div><b>' . e($it['text']) . '</b><div class="feat-d">' . e($it['desc']) . '</div></div></div>', $its)) . '</div></div>';
            case 'info-box':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="info-box-demo"><span class="feat-ico" style="font-size:22px">' . pv_icon($props['icon'] ?? '💡') . '</span><div><b>' . ($title ?: 'نکته مهم') . '</b><div class="feat-d">' . e($props['text'] ?? 'متن توضیح جعبه اطلاعات...') . '</div></div></div></div>';
            case 'price-cards': {
                $its = pvItems($props, [['اقتصادی', 'سرویس پایه — ۴۵۰ هزار تومان', ''], ['استاندارد', 'سرویس کامل — ۹۵۰ هزار تومان', ''], ['ویژه', 'سرویس + قطعه — ۱٫۵ میلیون', '']]);
                $out = '';
                foreach ($its as $i => $it) { $out .= '<div class="fake-card"' . ($i === 1 ? ' style="border:2px solid var(--p,#2563eb)"' : '') . '><div class="card-t">' . e($it['text']) . '</div><div class="feat-d" style="font-weight:800;color:var(--p)">' . e($it['desc']) . '</div></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'پلن‌های سرویس') . '</div><div class="cols c3">' . $out . '</div></div>';
            }
            case 'location-cards':
                /* 🆕 v2.43 (S04) — شعبه‌ها از آدرس‌های واقعی سایت‌ساز (درخواست
                   کاربر: «همه عناصر از سایت ساز اطلاعاتشان را بگیرند»)؛
                   در نبود آدرس، آیتم‌های دستی کاربر می‌مانند */
                $its = pvItems($props, [['🏬', 'شعبه مرکزی', 'تهران، ولیعصر'], ['🏬', 'شعبه غرب', 'تهران، سعادت‌آباد']]);
                $dynOn = !in_array((string)($props['dynamic'] ?? '1'), ['0', 'off', 'false'], true); /* 🆕 S04: هم رشته هم عدد */
                if ($dynOn) {
                    $dynAddr = (array)(pv_brand_settings()['addresses'] ?? []);
                    $dynIts = [];
                    foreach ($dynAddr as $da) {
                        if (!is_array($da)) { continue; }
                        $txt = trim((string)($da['address'] ?? ''));
                        if ($txt === '') { continue; }
                        $city = trim((string)($da['city'] ?? ''));
                        $mu = trim((string)($da['map_url'] ?? ''));
                        if ($mu === '' && (string)($da['lat'] ?? '') !== '' && (string)($da['lng'] ?? '') !== '') {
                            $mu = 'https://maps.google.com/?q=' . rawurlencode($da['lat'] . ',' . $da['lng']);
                        }
                        $dynIts[] = ['icon' => '🏬', 'text' => ($city !== '' ? $city : 'شعبه'), 'desc' => $txt, 'link' => $mu, 'color' => ''];
                        if (count($dynIts) >= 6) { break; }
                    }
                    if ($dynIts) { $its = $dynIts; }
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'شعب ما') . '</div><div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card"' . (!empty($it['link']) ? ' onclick="location.href=\'' . e(pv_safe_link((string)$it['link'])) . '\'" style="cursor:pointer"' : '') . '><div class="card-ico">' . pv_icon($it['icon'] ?: '🏬') . '</div><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
            case 'expert-cards':
                $its = pvItems($props, [['🔧', 'مهندس کریمی', 'برد و الکترونیک'], ['❄️', 'مهندس رضایی', 'سیستم سرمایش']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'متخصصین ما') . '</div><div class="cols c' . pvCols($props, 4) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="fake-ava">' . pv_icon($it['icon'] ?: '👨‍🔧') . '</div><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
            case 'logo-cloud': {
                $its = pvItems($props, [['🏅', 'نشان سفیر خدمت'], ['📋', 'مجوز اتحادیه'], ['🎖️', 'نمایندگی رسمی'], ['✅', 'تاییدیه کیفیت']]);
                $out = '';
                foreach ($its as $it) { $out .= '<div style="text-align:center;min-width:86px"><div style="font-size:26px">' . pv_icon($it['icon'] ?: '🏅') . '</div><div class="feat-d" style="font-size:11px">' . e($it['text']) . '</div></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="chip-row" style="justify-content:center">' . $out . '</div></div>';
            }
            case 'social-proof':
                /* 🆕 v2.40 — عنوان (T) + زیرعنوان (S) فعال شد */
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="soc-proof"><div class="ava-stack"><span class="fake-ava" style="width:34px;height:34px;font-size:13px">👩</span><span class="fake-ava" style="width:34px;height:34px;font-size:13px;margin-inline-start:-10px">🧑</span><span class="fake-ava" style="width:34px;height:34px;font-size:13px;margin-inline-start:-10px">👨</span><span class="fake-ava" style="width:34px;height:34px;font-size:11px;margin-inline-start:-10px">+۵۰k</span></div><div><div class="stars">⭐⭐⭐⭐⭐ <b>۴.۹ از ۵</b></div><div class="feat-d">' . e($props['text'] ?? 'بیش از ۵۰ هزار مشتری به ما اعتماد کرده‌اند') . '</div></div></div></div>';
            case 'link-buttons':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<div class="hero-btns" style="justify-content:flex-start"><span class="hero-btn">📄 دانلود بروشور</span><span class="hero-btn ghost">🔎 پیگیری درخواست</span><span class="hero-btn ghost">🧾 فاکتور آنلاین</span></div></div>';
            case 'promo-card':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="promo-card-demo"><div><span class="badge badge-warning" style="font-size:10.5px">🎁 پیشنهاد ویژه</span><div class="blk-title" style="font-size:19px;margin:9px 0 5px">' . ($title ?: 'کمپین سرویس بهاره') . '</div><div class="feat-d">' . e($props['subtitle'] ?? 'تا ۲۵٪ تخفیف — تا پایان ماه') . '</div></div><div style="text-align:center"><div class="stat-n" style="font-size:33px">۲۵٪</div><span class="hero-btn" style="margin-top:8px">همین حالا رزرو کنید</span></div></div></div>';
            case 'divider-icon':
                return '<div class="blk ' . $bgClass . '" style="padding:10px 16px"><div class="divider-ico"><span class="divider-line"></span><span style="font-size:17px">' . pv_icon($props['icon'] ?? '🔧') . '</span><span class="divider-line"></span></div></div>';
            case 'contact-info-bar':
                /* 🆕 v2.29 — مقادیر پویا از تنظیمات سایت‌ساز */
                $ciPh = trim((string)($props['phone'] ?? '')) ?: pv_brand_phone() ?: '۰۲۱-۱۲۳۴۵۶۷۸';
                $ciHr = trim((string)($props['hours'] ?? '')) ?: pv_brand_hours() ?: 'شنبه تا پنجشنبه ۹ تا ۲۰';
                /* 🆕 v2.44 (S04) — آدرس از تنظیمات سایت‌ساز (نه «تهران» ایستا) */
                $ciAd = pv_brand_address() ?: (pv_dyn_city() ?: 'آدرس نمایندگی');
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="chip-row" style="justify-content:space-between"><span class="chip">📞 <a href="tel:' . e($ciPh) . '" style="color:inherit;text-decoration:none"><b dir="ltr">' . e(fa_num($ciPh)) . '</b></a></span><span class="chip">🕐 ' . e($ciHr) . '</span><span class="chip">📍 ' . e($ciAd) . '</span></div></div>';
            case 'pros-cons': {
                /* 🎭 P2-21 — پیش‌نمایش: محتوای نمونه (رفتار قدیمی) */
                if ($pvPreview) {
                    $its = pvItems($props, [['✅', 'قطعات اصلی و ضمانت‌دار', ''], ['✅', 'اعزام سریع تکنسین', ''], ['⚠️', 'زمان تعمیر ۲ تا ۴ روز کاری', '']]);
                    $pros = '';
                    foreach ($its as $it) { if (!str_starts_with($it['icon'], '⚠') && !str_starts_with($it['icon'], '❌')) { $pros .= '<div class="feat-row"><span class="feat-ico" style="background:#f0fdf4">' . pv_icon($it['icon'] ?: '✅') . '</span><div>' . e($it['text']) . '</div></div>'; } }
                    $cons = '';
                    foreach ($its as $it) { if (str_starts_with($it['icon'], '⚠') || str_starts_with($it['icon'], '❌')) { $cons .= '<div class="feat-row"><span class="feat-ico" style="background:#fef2f2">' . pv_icon($it['icon']) . '</span><div>' . e($it['text']) . '</div></div>'; } }
                    return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c2"><div class="fake-card" style="border-inline-start:4px solid #16a34a"><div class="card-t" style="color:#15803d">✅ مزایا</div><div class="feat-list">' . $pros . '</div></div><div class="fake-card" style="border-inline-start:4px solid #dc2626"><div class="card-t" style="color:#b91c1c">⚠️ نکات</div><div class="feat-list">' . ($cons !== '' ? $cons : '<div class="feat-d">موردی ثبت نشده — آیتم با آیکون ⚠️ اضافه کنید</div>') . '</div></div></div></div>';
                }
                $its = pvItems($props, [['✅', 'قطعات اصلی و ضمانت‌دار', ''], ['✅', 'اعزام سریع تکنسین', ''], ['⚠️', 'زمان تعمیر ۲ تا ۴ روز کاری', '']]);
                $pros = '';
                foreach ($its as $it) { if (!str_starts_with($it['icon'], '⚠') && !str_starts_with($it['icon'], '❌')) { $pros .= '<div class="feat-row"><span class="feat-ico" style="background:#f0fdf4">' . pv_icon($it['icon'] ?: '✅') . '</span><div>' . e($it['text']) . '</div></div>'; } }
                $cons = '';
                foreach ($its as $it) { if (str_starts_with($it['icon'], '⚠') || str_starts_with($it['icon'], '❌')) { $cons .= '<div class="feat-row"><span class="feat-ico" style="background:#fef2f2">' . pv_icon($it['icon']) . '</span><div>' . e($it['text']) . '</div></div>'; } }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c2"><div class="fake-card" style="border-inline-start:4px solid #16a34a"><div class="card-t" style="color:#15803d">✅ مزایا</div><div class="feat-list">' . $pros . '</div></div><div class="fake-card" style="border-inline-start:4px solid #dc2626"><div class="card-t" style="color:#b91c1c">⚠️ نکات</div><div class="feat-list">' . ($cons !== '' ? $cons : '<div class="feat-d">موردی ثبت نشده</div>') . '</div></div></div></div>';
            }
            case 'text-accent-box':
                /* 🆕 v2.40 — زیرعنوان (S) فعال شد */
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-inline-start:5px solid var(--p);border-radius:12px;padding:15px 17px">' . ($title ? '<div class="card-t" style="margin-bottom:6px">' . e($title) . '</div>' : '') . (!empty($props['subtitle']) ? '<div class="feat-d" style="font-weight:700;margin-bottom:4px">' . e($props['subtitle']) . '</div>' : '') . '<div class="feat-d" style="color:#1e40af">' . e($props['text'] ?? 'متنی که باید توجه کاربر را جلب کند.') . '</div></div></div>';
            case 'definition-list':
                $its = pvItems($props, [['🔧', 'ایرادیابی', 'بررسی کامل دستگاه برای یافتن عیب'], ['🧲', 'مگنترون', 'قطعه تولید امواج مایکروویو']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="feat-list">' . implode('', array_map(static fn($it) => '<div class="feat-row"><span class="feat-ico">' . pv_icon($it['icon'] ?: '📖') . '</span><div><b>' . e($it['text']) . '</b><div class="feat-d">' . e($it['desc']) . '</div></div></div>', $its)) . '</div></div>';
            case 'article-highlight':
                /* 🔄 v2.42 — پویا: جدیدترین مقاله واقعی برند (تصویر + لینک) */
                $latest = pv_dyn_articles(1)[0] ?? null;
                if ($latest && trim((string)($latest['title'] ?? '')) !== '') {
                    $aSlug = rawurlencode((string)($latest['slug'] ?? ''));
                    $aTitle = (string)$latest['title'];
                    $aEx = trim((string)preg_replace('#<[^>]+>#', ' ', (string)($latest['excerpt'] ?? '')));
                    $aImg = trim((string)($latest['featured_image'] ?? ''));
                    $imgBox = $aImg !== '' && preg_match('#^(https?://|uploads/|/)#i', $aImg)
                        ? '<img src="' . e(preg_match('#^uploads/#i', $aImg) && function_exists('pv_asset') ? pv_asset($aImg) : $aImg) . '" alt="' . e($aTitle) . '" style="width:130px;height:110px;flex:0 0 130px;object-fit:cover;border-radius:12px" loading="lazy">'
                        : pvImg($props, '🌟', 'width:130px;height:110px;flex:0 0 130px');
                    return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><a href="/blog/' . e($aSlug) . '" style="text-decoration:none;color:inherit"><div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;background:linear-gradient(135deg,#fff7ed,#ffedd5);border:1.5px solid #fdba74;border-radius:15px;padding:19px 21px">' . $imgBox . '<div style="flex:1;min-width:200px"><div class="card-t" style="font-size:15px">' . e($title ?: $aTitle) . '</div><div class="feat-d" style="font-weight:700;color:#9a3412">📰 مقاله جدید سایت</div>' . ($aEx !== '' ? '<div class="feat-d">' . e(mb_substr($aEx, 0, 110)) . '…</div>' : '') . '<div class="feat-d" style="margin-top:5px;font-weight:800">مطالعه مقاله ←</div></div></div></a></div>';
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;background:linear-gradient(135deg,#fff7ed,#ffedd5);border:1.5px solid #fdba74;border-radius:15px;padding:19px 21px">' . pvImg($props, '🌟', 'width:130px;height:110px;flex:0 0 130px') . '<div style="flex:1;min-width:200px"><div class="card-t" style="font-size:15px">' . e($title ?: 'محتوای ویژه') . '</div>' . (!empty($props['subtitle']) ? '<div class="feat-d" style="font-weight:700;color:#9a3412">' . e($props['subtitle']) . '</div>' : '') . '<div class="feat-d">' . e($props['text'] ?? 'خلاصه‌ای از مزیت ویژه این بخش.') . '</div></div></div></div>';
            case 'page-header':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="text-align:center;padding:18px 10px 8px"><div class="hero-title" style="font-size:24px">' . e($title ?: 'عنوان صفحه') . '</div>' . (!empty($props['subtitle']) ? '<div class="feat-d">' . e($props['subtitle']) . '</div>' : '') . '<div class="feat-d" style="margin-top:8px;opacity:.65">خانه / ' . e($title ?: 'صفحه') . '</div></div></div>';
            case 'steps-vertical':
                $its = pvItems($props, [['۱', 'ثبت درخواست', 'آنلاین یا تلفنی'], ['۲', 'عیب‌یابی و اعلام هزینه', 'شفاف و پیش از شروع'], ['۳', 'تعمیر و تحویل', 'همراه با ضمانت کتبی']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="feat-list">' . implode('', array_map(static fn($it) => '<div class="feat-row"><span class="feat-ico" style="background:linear-gradient(135deg,var(--p),var(--s));color:#fff;font-weight:800">' . pv_icon($it['icon'] ?: '•') . '</span><div><b>' . e($it['text']) . '</b><div class="feat-d">' . e($it['desc']) . '</div></div></div>', $its)) . '</div></div>';
            case 'service-price-cards':
                $its = pvItems($props, [['🧺', 'شست‌وشوی کامل ماشین لباس', 'از ۹۵۰ هزار تومان'], ['❄️', 'شارژ گاز کولر', 'از ۱٫۲ میلیون تومان'], ['🔥', 'تعویض هیتر ماشین ظرفشویی', 'از ۱٫۵ میلیون تومان']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'تعرفه خدمات پرتقاضا') . '</div><div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div class="card-ico">' . pv_icon($it['icon'] ?: '🔧') . '</div><div class="card-t" style="font-size:12.5px">' . e($it['text']) . '</div><div class="feat-d" style="font-weight:800;color:var(--p)">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
            case 'feature-icons-grid':
                $its = pvItems($props, [['🧊', 'یخچال', ''], ['🧺', 'لباسشویی', ''], ['📺', 'تلویزیون', ''], ['🔥', 'فر و اجاق', '']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'خدمات ما در یک نگاه') . '</div><div class="cols c' . pvCols($props, 4) . '">' . implode('', array_map(static fn($it) => '<div class="fake-card" style="text-align:center;padding:15px 8px"><div style="font-size:31px">' . pv_icon($it['icon'] ?: '🔧') . '</div><div class="feat-d" style="font-weight:700;margin-top:6px">' . e($it['text']) . '</div></div>', $its)) . '</div></div>';
            case 'chat-widget':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="display:flex;justify-content:flex-end"><div style="background:var(--card);border:1.5px solid var(--border);border-radius:15px 15px 3px 15px;padding:11px 15px;max-width:290px;box-shadow:0 8px 22px rgba(2,8,23,.12)"><div style="font-size:12.5px"><b>💬 ' . e($title ?: 'پشتیبانی آنلاین') . '</b></div><div class="feat-d">سلام! چطور می‌تونیم کمکتون کنیم؟</div><div style="display:flex;gap:6px;margin-top:8px"><span class="hero-btn" style="font-size:11px;padding:5px 12px">شروع گفتگو</span></div></div></div></div>';
            case 'vote-poll': {
                $its = pvItems($props, [['🧺', 'لباسشویی', ''], ['❄️', 'یخچال', ''], ['🔥', 'ماکروویو', '']]);
                $faP = static fn($n) => strtr((string)$n, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
                $total = count($its) * 12 + 30;
                $out = '';
                foreach ($its as $i => $it) { $p = (int)round(($total - $i * 11) / max(1, $total) * 100); $out .= '<div class="cap-row"><span class="cap-h">' . pv_icon($it['icon']) . ' ' . e($it['text']) . '</span><div class="track" style="flex:1"><div class="fill" style="width:' . $p . '%"></div></div><span class="cap-l">' . $faP($p) . '٪</span></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'رای‌گیری') . '</div><div class="cap-demo">' . $out . '</div></div>';
            }
            case 'video-grid': {
                $gcols = pvCols($props, 3);
                $gimgs = '';
                for ($gi = 0; $gi < $gcols * 2; $gi++) { $gimgs .= '<div style="position:relative">' . str_replace('fake-img', 'fake-img small', pvImg($props, '🎬', 'min-height:96px')) . '<div class="play" style="width:30px;height:30px;font-size:12px">▶</div></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'ویدیوهای آموزشی') . '</div><div class="cols c' . $gcols . '">' . $gimgs . '</div></div>';
            }
            case 'logo-marquee': {
                /* 🔄 v2.42 — پویا: برندهای واقعی نمایندگی با لوگو/نام + لینک سایت */
                $its = array_values(array_filter(pvItems($props, []), static fn($i) => trim((string)$i['text']) !== ''));
                $dynBrands = pv_dyn_brands(10);
                if (!$its || $dynBrands) {
                    $mapped = [];
                    foreach ($dynBrands as $b) {
                        $name = trim((string)($b['name_fa'] ?? ''));
                        if ($name === '') { continue; }
                        $domain = trim((string)($b['domain'] ?? ''));
                        $logo = trim((string)($b['logo'] ?? ''));
                        $mapped[] = ['icon' => $logo !== '' && preg_match('#^(https?://|uploads/|/)#i', $logo) ? $logo : '🏷️', 'text' => $name, 'desc' => '', 'link' => $domain !== '' ? 'https://' . $domain : '', 'color' => ''];
                    }
                    if ($mapped) { $its = $mapped; }
                }
                if (!$its) {
                    $its = [['icon' => '🏷️', 'text' => 'ال‌جی', 'desc' => '', 'link' => '', 'color' => ''], ['icon' => '🏷️', 'text' => 'سامسونگ', 'desc' => '', 'link' => '', 'color' => ''], ['icon' => '🏷️', 'text' => 'بوش', 'desc' => '', 'link' => '', 'color' => ''], ['icon' => '🏷️', 'text' => 'سونی', 'desc' => '', 'link' => '', 'color' => ''], ['icon' => '🏷️', 'text' => 'پاکس', 'desc' => '', 'link' => '', 'color' => ''], ['icon' => '🏷️', 'text' => 'اسنوا', 'desc' => '', 'link' => '', 'color' => '']];
                }
                $chips = '';
                foreach (array_merge($its, $its) as $it) {
                    $isImg = preg_match('#^(https?://|uploads/|/)#i', (string)$it['icon']) && !preg_match('/^[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', (string)$it['icon']);
                    $inner = $isImg
                        ? '<img src="' . e(preg_match('#^uploads/#i', (string)$it['icon']) && function_exists('pv_asset') ? pv_asset((string)$it['icon']) : (string)$it['icon']) . '" alt="' . e($it['text']) . '" style="height:22px;max-width:86px;object-fit:contain;vertical-align:middle" loading="lazy">'
                        : pv_icon($it['icon'] !== '' ? $it['icon'] : '🏷️') . ' ' . e($it['text']);
                    $href = pv_safe_link((string)$it['link']);
                    $chip = $href !== '' ? '<a href="' . e($href) . '" class="chip" style="margin-inline-end:9px;text-decoration:none" target="_blank" rel="nofollow">' . $inner . '</a>' : '<span class="chip" style="margin-inline-end:9px">' . $inner . '</span>';
                    $chips .= $chip;
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'برندهای مورد خدمت') . '</div><div style="overflow:hidden;border-radius:12px;padding:11px 0"><div class="chip-row" style="animation:tickMove 18s linear infinite;white-space:nowrap;width:max-content">' . $chips . '</div></div></div>';
            }
            case 'tag-cloud': {
                /* 🔄 v2.42 — پویا: برچسب‌های واقعی مقالات همین برند + لینک جستجو */
                $manual = array_values(array_filter(pvItems($props, []), static fn($i) => trim((string)$i['text']) !== ''));
                $dynTags = pv_dyn_article_tags(10);
                $texts = $dynTags ?: array_column($manual, 'text');
                if (!$texts) { $texts = ['تعمیر ماشین لباس', 'کد خطا SE', 'شارژ گاز کولر', 'بک‌لایت تلویزیون']; }
                $searchLink = function_exists('localized_path') ? localized_path('/search') : '/search';
                $sizes = ['12px', '14px', '13px', '15px', '12.5px', '14.5px'];
                $out = '';
                foreach ($texts as $i => $t) {
                    $t = trim((string)$t);
                    if ($t === '') { continue; }
                    $out .= '<a href="' . e($searchLink . '?q=' . urlencode($t)) . '" class="chip" style="font-size:' . $sizes[$i % count($sizes)] . ';font-weight:' . ($i % 3 === 0 ? 800 : 600) . ';opacity:' . (0.72 + ($i % 3) * 0.09) . ';text-decoration:none">' . e($t) . '</a>';
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="chip-row" style="justify-content:center;gap:8px">' . $out . '</div></div>';
            }
            case 'quote-slider': {
                /* 🔄 v2.42 — پویا: دیدگاه‌های واقعی مشتریان همین برند + اسلایدر واقعی */
                $its = [];
                foreach (pv_dyn_comments(5) as $c) {
                    $body = trim((string)($c['body'] ?? ''));
                    if ($body !== '') {
                        $its[] = ['icon' => (string)($c['author'] ?? ''), 'text' => $body, 'desc' => '', 'link' => '', 'color' => ''];
                    }
                }
                if (!$its) {
                    $its = pvItems($props, [['علی محمدی', 'سرویس سریع و منظم بود؛ راضی بودم.', ''], ['مریم احمدی', 'قیمت شفاف و ضمانت واقعی.', ''], ['رضا کریمی', 'تکنسین دقیق و حرفه‌ای اعزام شد.', '']]);
                }
                /* اسلایدر واقعی (همان الگوی testimonials) */
                $cards = '';
                foreach ($its as $i => $q) {
                    $cards .= '<div class="ss-tst"' . ($i === 0 ? '' : ' style="display:none"') . '><div class="quote" style="text-align:center;font-size:15px">«' . e($q['text']) . '»</div>' . ($q['icon'] !== '' ? '<div class="feat-d" style="text-align:center;font-weight:800;margin-top:6px">— ' . e($q['icon']) . '</div>' : '') . '</div>';
                }
                $dots = count($its) > 1 ? '<div class="slider-dots" data-tst-dots="1" style="margin-top:8px">' . implode(' ', array_map(static fn($i) => '<span style="cursor:pointer">' . ($i === 0 ? '●' : '○') . '</span>', array_keys($its))) . '</div>' : '';
                $js = count($its) > 1 ? '<script>document.addEventListener("DOMContentLoaded",function(){var ws=document.querySelectorAll(".ss-tst"),ds=document.querySelectorAll("[data-tst-dots] span"),ci=0;ds.forEach(function(d,i){d.addEventListener("click",function(){ws[ci]&&(ws[ci].style.display="none");ds[ci]&&(ds[ci].textContent="○");ci=i;ws[ci]&&(ws[ci].style.display="");ds[ci]&&(ds[ci].textContent="●")})});setInterval(function(){if(ws.length&&ds.length>1){ds[(ci+1)%ws.length].click()}},6000)});</script>' : '';
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'مشتریان چه می‌گویند') . '</div>' . $cards . $dots . '</div>' . $js;
            }
            case 'stats-circles': {
                $its = pvItems($props, [['۹۲٪', 'تعمیر در روز اول', ''], ['۸۷٪', 'رضایت کامل', ''], ['۹۶٪', 'حل قطعی ایراد', '']]);
                $out = '';
                foreach ($its as $it) { $num = preg_replace('/[^0-9]/', '', $it['icon']) ?: '80'; $deg = (int)round((int)$num / 100 * 360); $out .= '<div style="text-align:center"><div style="width:86px;height:86px;margin:0 auto;border-radius:50%;background:conic-gradient(var(--p) ' . $deg . 'deg,var(--border) ' . $deg . 'deg);display:flex;align-items:center;justify-content:center"><div style="width:66px;height:66px;background:var(--card);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:16.5px">' . pv_icon($it['icon'] ?: $num) . '</div></div><div class="feat-d" style="margin-top:8px;font-weight:700">' . e($it['text']) . '</div></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'عملکرد ما در آمار واقعی') . '</div><div class="cols c' . max(2, min(4, count($its))) . '" style="gap:14px">' . $out . '</div></div>';
            }
            case 'counter-big': {
                /* 🆕 v2.40 — عنوان (T) فعال شد (قبلاً نادیده گرفته می‌شد) */
                $its = pvItems($props, [['۵۰,۰۰۰+', 'تعمیر تکمیل‌شده', '']]);
                $it0 = $its[0] ?? ['icon' => '۵۰,۰۰۰+', 'text' => 'تعمیر تکمیل‌شده'];
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div style="text-align:center;border-radius:16px;padding:26px 18px"><div class="hero-title" style="font-size:37px">' . e($it0['icon'] ?: $it0['text']) . '</div><div class="feat-d" style="font-weight:800;font-size:14px;margin-top:5px">' . e($it0['icon'] !== '' ? $it0['text'] : 'شمارنده') . '</div></div></div>';
            }
            case 'brand-stats-bar': {
                $its = pvItems($props, [['۱۵+', 'سال تجربه', ''], ['۴۲', 'نوع دستگاه تخصصی', ''], ['۲۴/۷', 'پشتیبانی', ''], ['۶ ماه', 'ضمانت کتبی', '']]);
                $out = '';
                foreach ($its as $i => $it) { $out .= ($i > 0 ? '<span class="ss-sep"></span>' : '') . '<span class="ss-item"><b>' . pv_icon($it['icon']) . '</b> ' . e($it['text']) . '</span>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="stats-strip">' . $out . '</div></div>';
            }
            case 'emergency-strip':
                $esPh = trim((string)($props['phone'] ?? '')) ?: pv_brand_phone() ?: '۰۲۱-۱۲۳۴۵۶۷۸';
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border-radius:13px;padding:13px 19px"><b style="font-size:14px">' . e($props['text'] ?? '🚑 امداد تعمیر فوری — ۲۴ ساعته') . '</b><a href="tel:' . e($esPh) . '" class="hero-btn" style="background:#fff;color:#b91c1c;text-decoration:none" dir="ltr">📞 ' . e(fa_num($esPh)) . '</a></div></div>';
            case 'stats-strip':
                /* 🆕 v2.40 — عنوان (T) + زیرعنوان (S) فعال شد */
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' stats-strip">' . $head . pvStatStrip($props) . '</div>';
            case 'benefits-list': {
                $its = pvItems($props, [['', 'اعزام تکنسین در کمتر از ۲ ساعت', ''], ['', 'قطعات فابریک با فاکتور معتبر', ''], ['', '۶ ماه ضمانت کتبی قطعه و خدمات', ''], ['', 'پیش‌فاکتور شفاف قبل از شروع کار', ''], ['', 'پیگیری وضعیت درخواست آنلاین', '']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<div class="feat-list">' . implode('', array_map(static fn($it) => '<div class="feat-row"><span class="feat-ico" style="background:#f0fdf4">✅</span><div><b>' . e($it['text']) . '</b></div></div>', $its)) . '</div></div>';
            }
            case 'warning-box':
                /* 🆕 v2.40 — زیرعنوان (S) فعال شد */
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="warning-box-demo"><span class="feat-ico" style="background:#fef2f2;font-size:22px">' . pv_icon($props['icon'] ?? '⚠️') . '</span><div><b style="color:#b91c1c">' . ($title ?: 'هشدار ایمنی مهم') . '</b>' . (!empty($props['subtitle']) ? '<div class="feat-d" style="font-weight:700">' . e($props['subtitle']) . '</div>' : '') . '<div class="feat-d">' . e($props['text'] ?? 'قبل از هرگونه باز کردن دستگاه، برق را کاملاً قطع کنید.') . '</div></div></div></div>';
            case 'brand-intro-card':
                /* 🆕 v2.44 (S04) — عنوان/زیرعنوان از نام برند و شعار واقعی */
                $biT = $title !== '' ? $title : (defined('BRAND_NAME_FA') && !pv_renderer_is_preview() && trim((string)BRAND_NAME_FA) !== '' ? trim((string)BRAND_NAME_FA) : 'نمایندگی رسمی خدمات');
                $biS = trim((string)($props['subtitle'] ?? ''));
                if ($biS === '') {
                    $biAg = (string)(pv_brand_settings()['agency']['slogan_fa'] ?? '');
                    $biS = $biAg !== '' ? $biAg : 'تجربه تخصصی در خدمات';
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="brand-intro-demo">' . pv_brand_logo_html() . '<div style="flex:1"><div class="blk-title" style="margin-bottom:4px">' . $biT . '</div><div class="feat-d">' . e($biS) . '</div><div class="stars" style="font-size:11px;margin-top:5px">⭐⭐⭐⭐⭐ <b>۴.۹ از ۵</b></div></div><span class="hero-btn" style="align-self:center">مشاهده خدمات</span></div></div>';
            case 'author-box':
                /* 🆕 v2.40 — زیرعنوان (S) فعال شد (سمت/تخصص نویسنده) */
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="author-box-demo"><div class="fake-ava" style="font-size:38px">' . pv_icon($props['icon'] ?? '👨‍🔧') . '</div><div style="flex:1"><b style="font-size:14px">' . ($title ?: 'مهندس کریمی') . '</b>' . (!empty($props['subtitle']) ? '<div class="feat-d" style="font-weight:700">' . e($props['subtitle']) . '</div>' : '') . '<div class="feat-d">کارشناس برد و الکترونیک — ۱۴ سال تجربه</div><div class="feat-d" style="margin-top:4px">' . e($props['text'] ?? 'متخصص تعمیر برد‌های اصلی لباسشویی، یخچال و کولر گازی.') . '</div></div></div></div>';
            case 'download-card':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="download-card-demo"><span class="feat-ico" style="font-size:30px">📄</span><div style="flex:1"><div class="blk-title" style="margin-bottom:3px;text-align:right">' . ($title ?: 'بروشور خدمات ما') . '</div><div class="feat-d">' . e($props['subtitle'] ?? 'فهرست کامل خدمات و تعرفه‌ها در یک فایل PDF') . '</div></div><span class="hero-btn">' . e($props['btnText'] ?? '⬇ دانلود بروشور') . '</span></div></div>';
            case 'schedule-table':
                $its = pvItems($props, [['', 'شنبه', '۹ تا ۲۰'], ['', 'یکشنبه تا چهارشنبه', '۹ تا ۲۰'], ['', 'پنجشنبه', '۹ تا ۱۴'], ['', 'جمعه', 'فقط امداد فوری']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'ساعات کاری ما') . '</div><div class="price-table">' . implode('', array_map(static fn($it) => '<div class="price-row"><span>' . e($it['text']) . '</span><b>' . e($it['desc']) . '</b></div>', $its)) . '</div></div>';
            case 'price-highlight': {
                $badge = trim((string)($props['badge'] ?? ''));
                /* 🆕 v2.40 — متن دکمه (B) فعال شد + زیرعنوان (S) از پس‌پردازش */
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="fake-card price-highlight-demo" style="text-align:right">' . ($badge !== '' ? '<span class="badge badge-warning" style="font-size:10px">' . e($badge) . '</span>' : '') . '<div class="blk-title" style="text-align:right;margin:8px 0 3px">' . ($title ?: 'سرویس دوره‌ای کامل') . '</div><div class="stat-n" style="font-size:31px;text-align:right">' . e($props['price'] ?? '۴۵۰ هزار تومان') . '</div><div class="feat-d" style="margin:7px 0 11px">شامل شست‌وشو، کالیبراسیون و تست ایمنی + ۶ ماه ضمانت</div><span class="hero-btn full">' . e($props['btnText'] ?? 'رزرو همین حالا') . '</span></div></div>';
            }
            case 'feature-table': {
                $its = pvItems($props, [['', 'عیب‌یابی رایگان'], ['', 'ضمانت ۶ ماهه'], ['', 'قطعات فابریک']]);
                $rows = '';
                foreach ($its as $it) { $rows .= '<div class="ft-row"><span>' . e($it['text']) . '</span><b>—</b><b>✓</b><b class="ft-hl">✓</b></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'مقایسه پلن‌های سرویس') . '</div><div class="feature-table-demo"><div class="ft-row ft-head"><span>ویژگی</span><b>اقتصادی</b><b>استاندارد</b><b class="ft-hl">ویژه</b></div>' . $rows . '</div></div>';
            }
            case 'related-links':
                /* 🔄 v2.42 — پویا: مقالات واقعی همین برند با لینک مستقیم */
                $its = array_values(array_filter(pvItems($props, []), static fn($i) => trim((string)$i['text']) !== ''));
                $dynArts = pv_dyn_articles(5);
                if ($dynArts) {
                    $its = [];
                    foreach ($dynArts as $a) {
                        $aTitle = trim((string)($a['title'] ?? ''));
                        $aSlug = trim((string)($a['slug'] ?? ''));
                        if ($aTitle !== '' && $aSlug !== '') {
                            $its[] = ['icon' => '', 'text' => $aTitle, 'desc' => '', 'link' => '/blog/' . rawurlencode($aSlug), 'color' => ''];
                        }
                    }
                }
                if (!$its) {
                    $its = [['icon' => '', 'text' => 'کد خطای LE لباسشویی ال‌جی — معنی و رفع', 'desc' => '', 'link' => '', 'color' => ''], ['icon' => '', 'text' => '۱۰ علامت خرابی کمپرسور یخچال', 'desc' => '', 'link' => '', 'color' => ''], ['icon' => '', 'text' => 'راهنمای نگهداری ماکروویو', 'desc' => '', 'link' => '', 'color' => '']];
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="feat-list">' . implode('', array_map(static fn($it) => pvA($it, '<div class="feat-row"><span class="feat-ico">🔗</span><div>' . e($it['text']) . '</div></div>'), $its)) . '</div></div>';
            case 'warranty-steps': {
                $its = pvItems($props, [['', 'ثبت سریال دستگاه'], ['', 'صدور برگه ضمانت'], ['', 'پشتیبانی ۶ ماهه']]);
                $out = '';
                foreach ($its as $i => $it) { $out .= ($i > 0 ? '<div class="step-arrow">←</div>' : '') . '<div class="step"><span class="step-n">' . pv_icon($it['icon'] ?: strtr((string)($i + 1), ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴'])) . '</span><div class="step-t">' . e($it['text']) . '</div></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'گارانتی ما چگونه کار می‌کند') . '</div><div class="steps-row">' . $out . '</div></div>';
            }
            case 'ticker-bar':
                /* 🎭 P2-21 — پیش‌نمایش: محتوای نمونه (رفتار قدیمی) */
                if ($pvPreview) {
                    return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="ticker-bar-demo"><span class="ticker-tag">🔴 زنده</span><div class="ticker-track"><span>' . e($props['text'] ?? '⚡ اعزام تکنسین فوری · 🧊 شارژ گاز کولر از ۹۰۰ هزار تومان · 🛡️ گارانتی ۶ ماهه · 📞 پاسخگویی ۷ روز هفته') . '</span></div></div></div>';
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="ticker-bar-demo"><span class="ticker-tag">🔴 زنده</span><div class="ticker-track"><span>' . e($props['text'] ?? '⚡ اعزام تکنسین فوری · 🧊 شارژ گاز کولر · 🛡️ گارانتی ۶ ماهه · 📞 پاسخگویی ۷ روز هفته') . '</span></div></div></div>';
            case 'booking-calendar': {
                /* 🎭 P2-21 — پیش‌نمایش: محتوای نمونه (رفتار قدیمی) */
                if ($pvPreview) {
                    $days = '';
                    foreach (['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'] as $d) { $days .= '<span class="cal-dow">' . $d . '</span>'; }
                    for ($i = 1; $i <= 28; $i++) {
                        $cls = in_array($i - 1, [3, 8, 14, 19, 25], true) ? ' busy' : (in_array($i - 1, [5, 11, 22], true) ? ' sel' : '');
                        $days .= '<span class="cal-day' . $cls . '">' . strtr((string)$i, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']) . '</span>';
                    }
                    return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'رزرو نوبت آنلاین') . '</div><div class="cal-demo">' . $days . '</div><div class="feat-d" style="text-align:center;margin-top:8px">روزهای <b style="color:#b91c1c">پر</b> ظرفیت ندارند — روز سبز انتخابی شماست</div></div>';
                }
                $days = '';
                foreach (['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'] as $d) { $days .= '<span class="cal-dow">' . $d . '</span>'; }
                for ($i = 1; $i <= 28; $i++) {
                    $cls = in_array($i - 1, [3, 8, 14, 19, 25], true) ? ' busy' : (in_array($i - 1, [5, 11, 22], true) ? ' sel' : '');
                    $days .= '<span class="cal-day' . $cls . '">' . strtr((string)$i, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']) . '</span>';
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'رزرو نوبت آنلاین') . '</div><div class="cal-demo">' . $days . '</div></div>';
            }
            case 'warranty-check':
                /* 🎭 P2-21 — پیش‌نمایش: محتوای نمونه (رفتار قدیمی) */
                if ($pvPreview) {
                    return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'استعلام گارانتی') . '</div><div class="quick-form-demo"><div class="fake-input" style="flex:1;direction:ltr">SN-XXXX-1234</div><span class="hero-btn">' . e($props['btnText'] ?? 'استعلام') . '</span></div><div class="feat-d" style="text-align:center;margin-top:7px">شماره سریال دستگاه را وارد کنید — وضعیت گارانتی همان لحظه نمایش داده می‌شود</div></div>';
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'استعلام گارانتی') . '</div><div class="quick-form-demo"><div class="fake-input" style="flex:1;direction:ltr">SN-XXXX-1234</div><span class="hero-btn">' . e($props['btnText'] ?? 'استعلام') . '</span></div></div>';
            case 'price-estimate':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'برآورد هزینه تعمیر') . '</div><div class="form-grid"><div class="fake-input">🌀 نوع دستگاه (لباسشویی، یخچال...)</div><div class="fake-input">🔧 نوع ایراد (نمایش کد، صدا، نشتی...)</div><div class="fake-input">📍 منطقه</div><div class="hero-btn full">🧮 محاسبه فوری برآورد</div></div></div>';
            case 'device-error-lookup': {
                /* 🔄 v2.42 — پویا: کدهای خطای واقعی برند به‌صورت چیپ‌های لینک‌دار
                   به صفحه کدهای خطا + فرم جستجوی واقعی */
                $ecLink = function_exists('localized_path') ? localized_path('/error-codes') : '/error-codes';
                $chips = '';
                foreach (pv_dyn_error_codes(5) as $ec) {
                    $chips .= '<a href="' . e($ecLink . '?q=' . urlencode($ec['code'])) . '" class="chip" style="text-decoration:none" title="' . e($ec['title']) . '">🔢 ' . e($ec['code']) . '</a>';
                }
                $chipsHtml = $chips !== ''
                    ? '<div class="chip-row" style="margin-top:10px;justify-content:center">' . $chips . '</div>'
                    : '<div class="feat-d" style="text-align:center;margin-top:7px">کد روی نمایشگر دستگاه را وارد کنید — علت، راه‌حل فوری و هزینه تعمیر را ببینید</div>';
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'جستجوی کد خطای دستگاه') . '</div><form action="' . e($ecLink) . '" method="get" style="display:contents"><div class="search-wrap"><span class="search-ico">🔢</span><input type="text" name="q" class="sahand-fi" style="flex:1;border:none" placeholder="E4 / LE / CH-05 ..." dir="ltr"><button type="submit" class="hero-btn" style="border:none;cursor:pointer;font-family:inherit">جستجو</button></div></form>' . $chipsHtml . '</div>';
            }
            case 'live-queue':
                $its = pvItems($props, [['🟢', 'دریافت و عیب‌یابی', 'در حال انجام — ۲ دستگاه'], ['🟡', 'تعمیر برد', 'در صف — ۱ دستگاه'], ['🔴', 'آماده تحویل', '۳ دستگاه']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'وضعیت صف تعمیرات — زنده') . '</div><div class="queue-demo">' . implode('', array_map(static fn($it) => '<div class="queue-row"><span>' . pv_icon($it['icon'] ?: '🟢') . ' ' . e($it['text']) . '</span><b>' . e($it['desc']) . '</b></div>', $its)) . '</div></div>';
            case 'hourly-capacity': {
                $its = pvItems($props, [['۹–۱۲', '20', 'کم‌تقاضا'], ['۱۲–۱۵', '60', 'متوسط'], ['۱۵–۱۸', '85', 'پرتقاضا']]);
                $out = '';
                foreach ($its as $it) { $p = max(5, min(100, (int)(preg_replace('/[^0-9]/', '', $it['text']) ?: 50))); $out .= '<div class="cap-row"><span class="cap-h">' . pv_icon($it['icon']) . '</span><div class="track" style="flex:1"><div class="fill" style="width:' . $p . '%"></div></div><span class="cap-l">' . e($it['desc']) . '</span></div>'; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'ظرفیت سرویس امروز') . '</div><div class="cap-demo">' . $out . '</div></div>';
            }
            case 'faq-search':
                /* 🔄 v2.42 — پویا: سوالات واقعی برند به‌صورت چیپ + فرم جستجوی واقعی
                   (پیش‌نمایش قالب‌ساز: نمونه دستی) */
                if ($pvPreview) {
                    return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'جستجو در سوالات متداول') . '</div><div class="search-wrap"><span class="search-ico">🔎</span><div class="fake-input" style="flex:1;border:none">' . e($props['placeholder'] ?? 'سوال خود را بنویسید...') . '</div><span class="hero-btn">پرسیدن</span></div><div class="chip-row" style="margin-top:10px;justify-content:center">' . implode('', array_map(static fn($q) => '<span class="chip">❓ ' . $q . '</span>', ['لباسشویی آب تخلیه نمی‌کند', 'یخچال برق دارد ولی خنک نمی‌کند', 'کد E4 یعنی چه؟'])) . '</div></div>';
                }
                $faqLink = function_exists('localized_path') ? localized_path('/faq') : '/faq';
                $fqChips = '';
                foreach (array_slice(pv_dyn_faqs(12), 0, 4) as $f) {
                    $qq = trim((string)($f['question'] ?? ''));
                    if ($qq !== '') { $fqChips .= '<a href="' . e($faqLink) . '" class="chip" style="text-decoration:none">❓ ' . e(mb_substr($qq, 0, 40)) . (mb_strlen($qq) > 40 ? '…' : '') . '</a>'; }
                }
                $fqChipsHtml = $fqChips !== '' ? '<div class="chip-row" style="margin-top:10px;justify-content:center">' . $fqChips . '</div>' : '';
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'جستجو در سوالات متداول') . '</div><form action="' . e($faqLink) . '" method="get" style="display:contents"><div class="search-wrap"><span class="search-ico">🔎</span><input type="text" name="q" class="sahand-fi" style="flex:1;border:none" placeholder="' . e($props['placeholder'] ?? 'سوال خود را بنویسید...') . '"><button type="submit" class="hero-btn" style="border:none;cursor:pointer;font-family:inherit">پرسیدن</button></div></form>' . $fqChipsHtml . '</div>';
            case 'faq-category':
                /* 🔄 v2.42 — پویا: اگر آیتم دستی نبود، دستگاه‌های واقعی برند به‌عنوان
                   موضوع سوالات (لینک به صفحه سوالات متداول) */
                $its = array_values(array_filter(pvItems($props, []), static fn($i) => trim((string)$i['text']) !== ''));
                if (!$its && !pv_renderer_is_preview() && defined('BRAND_ID') && function_exists('fetchFromAPI')) {
                    try {
                        $devs = (array)(fetchFromAPI('brand/' . BRAND_ID . '/devices', 300)['data'] ?? []);
                        foreach (array_slice($devs, 0, 6) as $dev) {
                            $name = trim((string)($dev['name_fa'] ?? ''));
                            if ($name !== '') {
                                $its[] = ['icon' => trim((string)($dev['icon'] ?? '')) ?: '❓', 'text' => $name, 'desc' => 'سوالات ' . $name, 'link' => '', 'color' => ''];
                            }
                        }
                    } catch (Throwable $dynE) { /* فال‌بک زیر */ }
                }
                if (!$its) {
                    $its = [['icon' => '🌀', 'text' => 'لباسشویی و ظرفشویی', 'desc' => 'سوالات رایج', 'link' => '', 'color' => ''], ['icon' => '❄️', 'text' => 'یخچال و فریزر', 'desc' => 'سوالات رایج', 'link' => '', 'color' => ''], ['icon' => '📺', 'text' => 'تلویزیون', 'desc' => 'سوالات رایج', 'link' => '', 'color' => '']];
                }
                $faqLink2 = function_exists('localized_path') ? localized_path('/faq') : '/faq';
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'سوالات متداول بر اساس موضوع') . '</div><div class="cols c3">' . implode('', array_map(static fn($it) => '<a href="' . e($faqLink2) . '" style="text-decoration:none;color:inherit"><div class="fake-card"><div class="card-ico">' . pv_icon($it['icon'] ?: '❓') . '</div><div class="card-t">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div></a>', $its)) . '</div></div>';
            case 'before-after-slider': {
                $imgUrl = trim((string)($props['imageUrl'] ?? ''));
                if (preg_match('#^uploads/#i', $imgUrl)) { $imgUrl = pv_asset($imgUrl); }
                $before = preg_match('#^(https?://|/)#i', $imgUrl)
                    ? '<img src="' . e($imgUrl) . '" alt="" style="width:100%;height:100%;object-fit:cover;filter:grayscale(1) contrast(1.1)">'
                    : '🧺 فرسوده';
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="blk-title">' . ($title ?: 'مقایسه تصویری قبل و بعد') . '</div><div class="bas-demo"><div class="bas-before">' . $before . '<span class="bas-tag">قبل</span></div><div class="bas-handle">⇔</div><div class="bas-after">✨ <b>مثل روز اول</b><span class="bas-tag ok">بعد</span></div></div></div>';
            }
            case 'social-wall':
                $its = pvItems($props, [['📷', 'نکته سرویس دوره‌ای', '۲ روز پیش'], ['🎥', 'ویدیوی عیب‌یابی زنده', '۵ روز پیش'], ['📝', 'معرفی تکنسین هفته', '۱ هفته پیش']]);
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'آخرین پست‌های ما') . '</div><div class="cols c3">' . implode('', array_map(static fn($it) => '<div class="fake-card"><div style="font-size:24px">' . pv_icon($it['icon'] ?: '📷') . '</div><div class="card-t" style="font-size:12px">' . e($it['text']) . '</div><div class="feat-d">' . e($it['desc']) . '</div></div>', $its)) . '</div></div>';
            case 'newsletter-popup':
                /* 🎭 P2-21 — پیش‌نمایش: محتوای نمونه (رفتار قدیمی) */
                if ($pvPreview) {
                    return '<div class="blk ' . $bgClass . ' ' . $padClass . '"' . $styleAttr . '><div class="np-demo-wrap"><div class="np-demo"><span class="feat-ico" style="font-size:30px;background:#eff6ff">📧</span><div><b style="font-size:14px">' . ($title ?: 'قبل از رفتن، پیشنهاد ویژه!') . '</b><div class="feat-d">' . e($props['subtitle'] ?? 'عضویت در خبرنامه = ۱۰٪ تخفیف اولین سرویس') . '</div></div><div class="news-row" style="margin-top:10px"><div class="fake-input" style="flex:1">ایمیل شما</div><span class="hero-btn">' . e($props['btnText'] ?? 'دریافت کد تخفیف') . '</span></div></div></div></div>';
                }
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
                /* 🎭 P2-21 — پیش‌نمایش: محتوای نمونه (رفتار قدیمی) */
                if ($pvPreview) {
                    $ps = array_values(array_filter(array_map('trim', explode("\n", (string)($props['text'] ?? 'پاراگراف اول متن...'))), static fn($l) => $l !== ''));
                    $half = max(1, (int)ceil(count($ps) / 2));
                    $c1 = array_slice($ps, 0, $half) ?: ['پاراگراف اول...'];
                    $c2 = array_slice($ps, $half) ?: ['متن ستون دوم — پاراگراف‌ها با Enter جدا می‌شوند.'];
                    $col = static fn(array $c) => implode('', array_map(static fn($p) => '<p>' . e($p) . '</p>', $c));
                    return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="text-cols-demo"><div class="pv-text">' . $col($c1) . '</div><div class="pv-text">' . $col($c2) . '</div></div></div>';
                }
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
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($i) => '<div class="fake-card"><div class="card-ico">' . pv_icon($i['icon'] ?: '💎') . '</div><div class="card-t">' . e($i['text'] ?: 'ارزش') . '</div></div>', $items)) . '</div></div>';
            case 'tech-tips':
            case 'steps-compact': {
                $items = pvItems($props, [['۱', 'ثبت درخواست'], ['۲', 'اعزام تکنسین'], ['۳', 'تعمیر و تحویل']]);
                $rows = '';
                foreach ($items as $n => $i) {
                    $rows .= '<div class="sc-row"><span class="sc-num">' . pv_icon($i['icon'] !== '' ? $i['icon'] : (string)($n + 1)) . '</span><div class="feat-d" style="font-size:12.5px">' . e($i['text'] ?: 'مرحله') . '</div></div>';
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="steps-compact-demo">' . $rows . '</div></div>';
            }
            case 'price-compare':
                /* 🆕 v2.40 — پلن‌ها از آیتم‌ها (IT فعال شد — قبلاً ۳ کارت ثابت بود)
                   + دکمه CTA (BTN فعال شد) */
                $pcIts = pvItems($props, []);
                $pcPlans = $pcIts !== [] ? $pcIts : [['icon' => '', 'text' => 'اقتصادی', 'desc' => 'پایه'], ['', 'استاندارد', 'کامل'], ['', 'ویژه', 'طلایی']];
                $pcCards = '';
                foreach (array_slice($pcPlans, 0, 4) as $pi => $pc) {
                    $pcCards .= '<div class="fake-card"' . ($pi === 1 ? ' style="border:2px solid var(--primary)"' : '') . '>' . ($pi === 1 ? '<span class="badge badge-warning" style="font-size:9.5px">پرطرفدار</span>' : '') . '<div class="card-t">' . e($pc['text'] !== '' ? $pc['text'] : 'پلن') . '</div><div class="stat-n" style="font-size:22px">' . e($pc['desc'] !== '' ? $pc['desc'] : '—') . '</div></div>';
                }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c' . pvCols($props, 3) . '">' . $pcCards . '</div><div class="hero-btns" style="margin-top:10px"><span class="hero-btn">' . e($props['btnText'] ?? 'مقایسه پلن‌ها') . '</span></div></div>';
            case 'guarantee-card':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="guarantee-demo"><span style="font-size:42px">🛡️</span><div style="flex:1"><div class="blk-title" style="margin-bottom:4px">' . ($title ?: '۶ ماه ضمانت کتبی') . '</div><div class="feat-d">' . e($props['subtitle'] ?? 'تمام تعمیرات با ضمانت کتبی و قابل پیگیری انجام می‌شود.') . '</div></div><span class="hero-btn" style="align-self:center">' . e($props['btnText'] ?? 'مشاهده شرایط') . '</span></div></div>';
            case 'cta-timer':
                /* 🆕 v2.40 — متن دکمه (B) فعال شد */
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk cta-timer-blk"' . $styleAttr . '><div class="hero-title" style="font-size:24px">' . ($title ?: 'تخفیف سرویس دوره‌ای') . '</div><div class="hero-sub">' . e($props['subtitle'] ?? 'فقط تا پایان هفته — بعد از پایان تایمر قیمت عادی است') . '</div>' . pvCountdown($props) . '<div class="hero-btns"><span class="hero-btn">' . e($props['btnText'] ?? 'همین حالا رزرو کنید') . '</span></div></div>';
            case 'urgent-repair':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="urgent-demo"><span style="font-size:34px">🚨</span><div style="flex:1"><b style="font-size:15px">' . ($title ?: 'تعمیر فوری نیاز دارید؟') . '</b><div class="feat-d">۲۴ ساعته — ۷ روز هفته اعزام تکنسین</div></div><div style="text-align:center"><div class="feat-d" style="font-size:10.5px">تماس فوری</div><div class="stat-n" style="font-size:19px" dir="ltr">📞 ' . e(fa_num(trim((string)($props['phone'] ?? '')) ?: pv_brand_phone() ?: '۰۲۱-۱۲۳۴۵۶۷۸')) . '</div><span class="hero-btn" style="margin-top:5px">' . e($props['btnText'] ?? 'درخواست اعزام') . '</span></div></div></div>';
            case 'faq-mini':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="faq-mini-demo"><div class="sc-row"><span class="sc-num">؟</span><b style="font-size:13.5px">' . ($title ?: 'سوال متداول') . '</b></div><div class="feat-d" style="margin-top:7px;font-size:12.5px">' . e($props['text'] ?? 'پاسخ کارشناسان ما به سوال متداول...') . '</div></div></div>';
            case 'reviews-carousel':
                /* 🔄 v2.42 — پویا: دیدگاه‌های واقعی مشتریان همین برند */
                $rcIts = [];
                foreach (pv_dyn_comments(6) as $c) {
                    $body = trim((string)($c['body'] ?? ''));
                    if ($body !== '') { $rcIts[] = $body; }
                }
                if (!$rcIts) { $rcIts = ['عالی بود، همان روز آمدند', 'قیمت منصفانه و کار تمیز', 'دستگاه ۵ ساله‌ام مثل نو شد']; }
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c' . pvCols($props, 3) . '">' . implode('', array_map(static fn($r) => '<div class="fake-card"><div class="stars">⭐⭐⭐⭐⭐</div><div class="feat-d">«' . e($r) . '»</div></div>', $rcIts)) . '</div><div class="slider-dots" style="margin-top:8px">● ○ ○</div></div>';
            case 'contact-map-split':
                /* 🆕 v2.29 — مقادیر پویا از تنظیمات سایت‌ساز + نقشه لینک‌دار */
                $cmPh = trim((string)($props['phone'] ?? '')) ?: pv_brand_phone() ?: '۰۲۱-۱۲۳۴۵۶۷۸';
                /* 🆕 v2.44 (S04) — فالبک آدرس خنثی (نه آدرس ساختگی تهران) */
                $cmAd = pv_brand_address() ?: 'آدرس نمایندگی — از تنظیمات سایت‌ساز';
                $cmHr = pv_brand_hours() ?: 'ساعات کاری — از تنظیمات سایت‌ساز';
                $cmMap = pv_brand_map_url();
                $mapBox = $cmMap !== ''
                    ? '<a href="' . e($cmMap) . '" target="_blank" rel="noopener" class="fake-img" style="min-height:130px;background:linear-gradient(135deg,#e2e8f0,#cbd5e1);text-decoration:none"><span style="font-size:30px">🗺️</span></a>'
                    : '<div class="fake-img" style="min-height:130px;background:linear-gradient(135deg,#e2e8f0,#cbd5e1)"><span style="font-size:30px">🗺️</span></div>';
                return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="split"><div><div class="chip-row" style="flex-direction:column;align-items:stretch;gap:7px"><span class="chip">📞 <a href="tel:' . e($cmPh) . '" style="color:inherit;text-decoration:none"><b dir="ltr">' . e(fa_num($cmPh)) . '</b></a></span><span class="chip">📍 ' . e($cmAd) . '</span><span class="chip">🕐 ' . e($cmHr) . '</span></div></div>' . $mapBox . '</div></div>';
            case 'stats-inline':
                return '<div class="blk ' . $bgClass . ' ' . $padClass . ' stats-strip-blk">' . $head . '<div class="stats-strip">' . pvStatStrip($props) . '</div></div>';
            case 'pelement':
                /* ⭐ عنصر شخصی استخراج‌شده — iframe ایزوله با استایل کامل سایت مبدأ */
                {
                    $peId = (int)($props['element_id'] ?? 0);
                    if (!isset(pv_renderer_pelements()[$peId])) {
                        /* سایت: سکوت؛ پیش‌نمایش/بوم: جعبه راهنما */
                        return $pvPreview ? '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="pv-text">⭐ این عنصر شخصی حذف شده است.</div></div>' : '';
                    }
                    $pe = pv_renderer_pelements()[$peId];
                    $frameDoc = htmlspecialchars(pv_pelement_doc($pe), ENT_QUOTES, 'UTF-8');
                    return '<div class="blk ' . $bgClass . ' ' . $padClass . '">'
                        . ($title !== '' ? '<div class="blk-title">' . e($title) . '</div>' : '')
                        . '<iframe class="pelement-frame" sandbox="allow-same-origin" srcdoc="' . $frameDoc . '" style="width:100%;min-height:210px;border:none;border-radius:11px;background:#fff" loading="lazy" onload="try{var d=this.contentDocument;if(d){this.style.height=Math.max(200,d.documentElement.scrollHeight+18)+\'px\'}}catch(e){}" title="' . e($pe['name'] ?? 'عنصر شخصی') . '"></iframe></div>';
                }
            default:
                /* 🧬 v2.29 — عناصر جدید (renderType در props ذخیره شده) با رندرگر عمومی */
                if (!empty($props['renderType'])) {
                    return pv_generic_block($block, $props, $PV);
                }
                /* بلوک ناشناخته/قدیمی — سایت: هیچ؛ پیش‌نمایش: جعبه راهنما */
                return $pvPreview
                    ? '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">📦 ' . e($block) . '</div><div class="fake-lines"><div class="fl w90"></div><div class="fl w70"></div></div></div>'
                    : '';
        }
    }
}

if (!function_exists('pv_custom_form')) {
    /**
     * 🧩 v2.44 (S12) — رندر فرم سفارشی ساخته‌شده در فرم‌ساز
     *
     * تعریف فرم (فیلدها + تنظیمات) با fetchFromAPI از سایت‌ساز خوانده
     * می‌شود و با همان کلاس‌های .sahand-form رندر می‌شود — یعنی اعتبارسنجی
     * سمت کلاینت، آپلود تصویر و ارسال AJAX همه رایگان موجود است.
     * form.js مقدار data-form-slug را هم ارسال می‌کند.
     */
    function pv_custom_form(array $props, string $title = ''): string
    {
        $slug = preg_replace('/[^a-z0-9\-_]/', '', strtolower((string)($props['customFormSlug'] ?? '')) ?? '');
        if ($slug === '') {
            return ($title !== '' ? '<div class="blk-title">' . e($title) . '</div>' : '')
                . '<div class="feat-d" style="text-align:center;padding:18px">🧩 فرم سفارشی انتخاب نشده — در قالب‌ساز، فرم موردنظر را از تنظیمات این عنصر انتخاب کنید.</div>';
        }

        /* 📡 تعریف فرم از سایت‌ساز (کش ۶۰ ثانیه) */
        $form = null;
        try {
            $form = fetchFromAPI('brand/' . BRAND_ID . '/custom-form/' . rawurlencode($slug), 60)['data'] ?? null;
        } catch (Throwable $cfE) {
            $form = null;
        }
        if (!is_array($form) || empty($form['fields'])) {
            return ($title !== '' ? '<div class="blk-title">' . e($title) . '</div>' : '')
                . '<div class="feat-d" style="text-align:center;padding:18px">🧩 فرم «' . e($slug) . '» یافت نشد یا غیرفعال است.</div>';
        }

        $s = is_array($form['settings'] ?? null) ? $form['settings'] : [];
        $btnText = (string)($s['btnText'] ?? 'ارسال');
        $dest = !empty($s['dest']) && is_array($s['dest']) ? $s['dest'] : ['panel'];
        $oneCol = ($s['layout'] ?? 'two') === 'one';

        $html = '';
        if ($title !== '') {
            $html .= '<div class="blk-title">' . e($title) . '</div>';
        }
        if (!empty($form['description'])) {
            $html .= '<p class="feat-d" style="margin-bottom:12px">' . e($form['description']) . '</p>';
        }

        $fieldsHtml = '';
        foreach ((array)$form['fields'] as $f) {
            $type = (string)($f['type'] ?? 'text');
            $label = (string)($f['label'] ?? '');
            $name = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($f['name'] ?? '')) ?: 'field';
            $req = !empty($f['required']);
            $reqMark = $req ? ' <span class="req" style="color:#dc2626">*</span>' : '';
            $reqAttr = $req ? ' required' : '';
            $ph = e((string)($f['placeholder'] ?? ''));
            $def = e((string)($f['default'] ?? ''));
            $fullCls = (($f['width'] ?? 'full') === 'half' && !$oneCol) ? '' : ' full';
            $help = !empty($f['help']) ? '<div style="font-size:10.5px;color:#94a3b8;margin-top:4px">' . e((string)$f['help']) . '</div>' : '';

            /* انواع ساختاری */
            if ($type === 'section') {
                $fieldsHtml .= '<div class="form-group' . $fullCls . '" style="grid-column:1/-1"><label style="font-size:14px;font-weight:800;border-bottom:2px solid var(--color-border,#e2e8f0);padding-bottom:6px">' . e($label) . '</label></div>';
                continue;
            }
            if ($type === 'html') {
                $fieldsHtml .= '<div class="form-group" style="grid-column:1/-1"><p style="font-size:12.5px;color:#64748b;line-height:2">' . e((string)($f['text'] ?? '')) . '</p></div>';
                continue;
            }
            if ($type === 'hidden') {
                $fieldsHtml .= '<input type="hidden" name="' . e($name) . '" value="' . $def . '">';
                continue;
            }

            $input = '';
            /* 🎭 v2.45 (S11) — فیلد ماسک‌دار: فرمت زنده هنگام تایپ + اعتبارسنجی
               (کد ملی/موبایل/تلفن/کارت/شبا/کد پستی/الگوی دلخواه) */
            $mask = (string)($f['mask'] ?? 'none');
            $isMasked = in_array($type, ['text', 'tel'], true) && $mask !== '' && $mask !== 'none';
            if ($isMasked) {
                $maskDefs = [
                    'national_code' => ['##########', 'numeric', 'کد ملی — ۱۰ رقم'],
                    'mobile'        => ['#### ### ####', 'numeric', '۰۹۱۲ ۳۴۵ ۶۷۸۹'],
                    'phone'         => ['### ### ####', 'numeric', '۰۴۱ ۳۳۳ ۱۲۳۴۵'],
                    'card'          => ['####-####-####-####', 'numeric', '۶۰۳۷-۹۹۷۵-XXXX-XXXX'],
                    'sheba'         => ['IR########################', 'numeric', 'IR + ۲۴ رقم'],
                    'postal'        => ['##########', 'numeric', 'کد پستی — ۱۰ رقم'],
                ];
                $pat = isset($maskDefs[$mask]) ? $maskDefs[$mask][0] : (string)($f['maskPattern'] ?? '');
                $mode = isset($maskDefs[$mask]) ? $maskDefs[$mask][1] : 'text';
                $exPh = isset($maskDefs[$mask]) ? $maskDefs[$mask][2] : $pat;
                $autoPh = ($ph === '') ? $exPh : $ph;
                $input = '<input type="text" name="' . e($name) . '" class="sahand-fi sahind-masked" data-mask="' . e($mask) . '"'
                    . ' data-mask-pattern="' . e($pat) . '" data-mask-mode="' . $mode . '"'
                    . ' placeholder="' . e($autoPh) . '" value="' . $def . '" dir="ltr" inputmode="' . ($mode === 'numeric' ? 'numeric' : 'text') . '" autocomplete="off"' . $reqAttr . '>';
            } elseif ($type === 'textarea') {
                $input = '<textarea name="' . e($name) . '" rows="' . max(2, min(12, (int)($f['rows'] ?? 4))) . '" class="sahand-fi" placeholder="' . $ph . '"' . $reqAttr . '>' . $def . '</textarea>';
            } elseif ($type === 'select') {
                $opts = '<option value="">انتخاب کنید...</option>';
                foreach ((array)($f['options'] ?? []) as $o) {
                    $opts .= '<option value="' . e((string)$o) . '"' . ($def !== '' && $def === (string)$o ? ' selected' : '') . '>' . e((string)$o) . '</option>';
                }
                $input = '<select name="' . e($name) . '" class="sahand-fi"' . $reqAttr . '>' . $opts . '</select>';
            } elseif ($type === 'radio' || $type === 'checkbox') {
                $input = '<div style="display:flex;flex-direction:column;gap:6px">';
                foreach ((array)($f['options'] ?? []) as $o) {
                    $input .= '<label style="display:flex;gap:7px;align-items:center;font-size:12.5px"><input type="' . ($type === 'radio' ? 'radio' : 'checkbox') . '" name="' . e($name . ($type === 'checkbox' ? '[]' : '')) . '" value="' . e((string)$o) . '"> ' . e((string)$o) . '</label>';
                }
                $input .= '</div>';
            } elseif ($type === 'consent') {
                $input = '<label style="display:flex;gap:8px;font-size:12px;align-items:flex-start;font-weight:400"><input type="checkbox" name="' . e($name) . '" value="بله" style="margin-top:4px"' . $reqAttr . '> <span>' . e((string)($f['text'] ?? '')) . $reqMark . '</span></label>';
                $fieldsHtml .= '<div class="form-group' . $fullCls . '">' . $input . $help . '</div>';
                continue;
            } elseif ($type === 'rating') {
                $input = '<div class="survey-opts" dir="ltr" style="display:flex;gap:4px;justify-content:flex-end">';
                foreach ([5, 4, 3, 2, 1] as $r) {
                    $input .= '<label style="cursor:pointer"><input type="radio" name="' . e($name) . '" value="' . $r . '" style="display:none"><span style="font-size:22px">⭐</span></label>';
                }
                $input .= '</div>';
            } elseif ($type === 'file') {
                $input = '<input type="file" name="' . e($name) . '" class="sahand-fi sahand-file" accept="image/*" multiple><div class="sahand-imgs"></div>';
            } elseif ($type === 'date') {
                $input = '<input type="text" name="' . e($name) . '" data-jalali-picker class="sahand-fi" placeholder="انتخاب تاریخ (شمسی)" inputmode="none" value="' . $def . '"' . $reqAttr . '>';
            } elseif ($type === 'number') {
                $min = isset($f['min']) && $f['min'] !== null ? ' min="' . e((string)$f['min']) . '"' : '';
                $max = isset($f['max']) && $f['max'] !== null ? ' max="' . e((string)$f['max']) . '"' : '';
                $input = '<input type="number" name="' . e($name) . '" class="sahand-fi" placeholder="' . $ph . '" value="' . $def . '"' . $min . $max . $reqAttr . '>';
            } else {
                $t = in_array($type, ['email', 'tel', 'url'], true) ? $type : 'text';
                $ltr = in_array($type, ['email', 'tel', 'url'], true) ? ' dir="ltr"' : '';
                $input = '<input type="' . $t . '" name="' . e($name) . '" class="sahand-fi" placeholder="' . $ph . '" value="' . $def . '"' . $ltr . $reqAttr . '>';
            }
            $fieldsHtml .= '<div class="form-group' . $fullCls . '"><label>' . e($label) . $reqMark . '</label>' . $input . $help . '</div>';
        }

        $html .= '<form class="sahand-form" data-form="custom" data-form-slug="' . e($slug) . '" data-dest="' . e(implode(',', $dest)) . '" novalidate>'
            . '<div class="form-grid">' . $fieldsHtml . '</div>'
            . '<button type="submit" class="hero-btn full sahand-form-btn">' . e($btnText) . '</button>'
            . '<div class="sahand-form-msg" style="display:none"></div>'
            . '</form>';
        return $html;
    }
}

if (!function_exists('pv_real_form')) {
    function pv_real_form(string $block, array $props, $title = ''): string
    {
        $FF = is_array($props['formFields'] ?? null) ? $props['formFields'] : [];
        $FD = is_array($props['formDest'] ?? null) ? $props['formDest'] : [];
        $on = static function (string $k, bool $def) use ($FF): bool {
            if (!isset($FF[$k]) || !is_array($FF[$k])) { return $def; }
            return !((int)($FF[$k]['on'] ?? 1) === 0 || ($FF[$k]['on'] ?? 1) === false);
        };
        $req = static function (string $k, bool $def) use ($FF, $on): bool {
            if (!$on($k, $def)) { return false; }
            if (!isset($FF[$k]) || !is_array($FF[$k])) { return $def; }
            return !((int)($FF[$k]['req'] ?? ($def ? 1 : 0)) === 0);
        };
        $R = static function (bool $r): string { return $r ? ' <span class="req" style="color:#dc2626">*</span>' : ''; };
        $requiredAttr = static function (bool $r): string { return $r ? ' required' : ''; };

        /* مقصد ارسال — data-dest */
        $dest = [];
        if (!isset($FD['panel']) || (int)$FD['panel'] !== 0) { $dest[] = 'panel'; }
        foreach (['email', 'telegram', 'bale'] as $d) { if (!empty($FD[$d])) { $dest[] = $d; } }
        if (!$dest) { $dest = ['panel']; }
        $btnText = (string)($props['btnText'] ?? '');
        if ($btnText === '') {
            $btnText = ['request-form' => '🚀 ثبت درخواست', 'hero-form' => 'ثبت درخواست سریع', 'contact-form' => '✉️ ارسال پیام', 'newsletter-form' => 'عضویت', 'callback-form' => '📞 درخواست تماس', 'quick-contact-form' => 'درخواست تماس', 'appointment-form' => 'رزرو نوبت', 'appointment-compact' => 'رزرو سریع', 'survey-form' => 'ثبت نظر'][$block] ?? 'ارسال';
        }

        $fields = '';
        $defaults = ['fullName' => [true, true], 'phone' => [true, true]];
        $per = [
            'request-form' => ['phone2' => [true, false], 'address' => [true, true], 'deviceType' => [true, true], 'deviceModel' => [true, false], 'preferredTime' => [true, false], 'description' => [true, true], 'images' => [true, false]],
            'hero-form' => ['deviceType' => [true, false], 'description' => [true, true]],
            'contact-form' => ['email' => [true, false], 'subject' => [true, false], 'address' => [false, false], 'description' => [true, true]],
            'newsletter-form' => ['email' => [true, true]],
            'callback-form' => ['phone2' => [false, false], 'preferredTime' => [true, false], 'description' => [true, false]],
            'quick-contact-form' => ['description' => [false, false]],
            'appointment-form' => ['deviceType' => [true, false], 'preferredTime' => [true, true], 'address' => [true, false], 'description' => [true, false]],
            'appointment-compact' => ['preferredTime' => [true, false]],
            'survey-form' => ['description' => [true, false]],
        ];
        $defs = ($per[$block] ?? []) + $defaults;

        /* نام */
        if ($on('fullName', true)) {
            $fields .= '<div class="form-group"><label>نام و نام خانوادگی' . $R($req('fullName', true)) . '</label><input type="text" name="full_name" class="sahand-fi" placeholder="نام شما"' . $requiredAttr($req('fullName', true)) . ' minlength="3"></div>';
        }
        /* تلفن */
        if ($on('phone', true)) {
            $fields .= '<div class="form-group"><label>شماره تماس' . $R($req('phone', true)) . '</label><input type="tel" name="phone" class="sahand-fi" placeholder="09123456789" dir="ltr"' . $requiredAttr($req('phone', true)) . '></div>';
        }
        /* تلفن دوم */
        if ($on('phone2', false)) {
            $fields .= '<div class="form-group"><label>شماره تماس دوم</label><input type="tel" name="phone2" class="sahand-fi" placeholder="09123456789" dir="ltr"></div>';
        }
        /* ایمیل */
        if ($on('email', false)) {
            $fields .= '<div class="form-group"><label>ایمیل' . $R($req('email', false)) . '</label><input type="email" name="email" class="sahand-fi" placeholder="you@example.com" dir="ltr"' . $requiredAttr($req('email', false)) . '></div>';
        }
        /* موضوع */
        if ($on('subject', false)) {
            $fields .= '<div class="form-group"><label>موضوع</label><input type="text" name="subject" class="sahand-fi" placeholder="موضوع پیام"></div>';
        }
        /* دستگاه */
        if ($on('deviceType', false) && in_array($block, ['request-form', 'hero-form', 'appointment-form'], true)) {
            $devices = [];
            try { $devices = (array)(fetchFromAPI('brand/' . BRAND_ID . '/devices', 600)['data'] ?? []); } catch (Throwable $dE) { $devices = []; }
            $opts = '<option value="">انتخاب کنید...</option>';
            foreach ($devices as $dv) {
                $opts .= '<option value="' . e((string)($dv['device_key'] ?? '')) . '">' . e((string)($dv['name_fa'] ?? '')) . '</option>';
            }
            $opts .= '<option value="other">سایر</option>';
            $fields .= '<div class="form-group"><label>نوع دستگاه' . $R($req('deviceType', false)) . '</label><select name="device_type" class="sahand-fi"' . $requiredAttr($req('deviceType', false)) . '>' . $opts . '</select></div>'
                . '<div class="form-group sahand-other-box" style="display:none"><label>نام دستگاه</label><input type="text" name="device_other" class="sahand-fi" placeholder="نوع دستگاه را بنویسید"></div>';
        }
        /* مدل */
        if ($on('deviceModel', false) && $block === 'request-form') {
            $fields .= '<div class="form-group"><label>مدل دستگاه</label><input type="text" name="device_model" class="sahand-fi" placeholder="مثلاً WS12T440"></div>';
        }
        /* زمان ترجیحی — v2.42: تاریخ و بازه ساعتی در دو سطر جدا (درخواست کاربر:
           «زمان ترجیحی و بازه زمانی چون در یک سطر هستند باهم قاطی میشن»)
           🩹 v2.44 (S05) — هر دو فیلد «full» شدند: گرید دوستونه فرم، این دو را
           کنار هم در یک سطر می‌چید و باز هم قاطی می‌شدند؛ اکنون هر کدام یک سطر کامل */
        if ($on('preferredTime', false)) {
            $fields .= '<div class="form-group full"><label>تاریخ مراجعه ترجیحی (شمسی)</label><input type="text" name="preferred_date" data-jalali-picker class="sahand-fi" placeholder="انتخاب تاریخ (شمسی)" inputmode="none"></div>'
                . '<div class="form-group full"><label>بازه ساعتی مراجعه</label><select name="preferred_time" class="sahand-fi"><option value="">انتخاب بازه ساعتی...</option><option>۹ تا ۱۲</option><option>۱۲ تا ۱۵</option><option>۱۵ تا ۱۸</option><option>۱۸ تا ۲۱</option></select></div>';
        }
        /* آدرس */
        if ($on('address', false)) {
            $fields .= '<div class="form-group"><label>آدرس' . $R($req('address', false)) . '</label><textarea name="address" rows="2" class="sahand-fi" placeholder="آدرس دقیق"' . $requiredAttr($req('address', false)) . '></textarea></div>';
        }
        /* شرح */
        if ($on('description', false)) {
            $ph = $block === 'contact-form' || $block === 'survey-form' ? 'پیام شما...' : 'شرح ایراد دستگاه...';
            $fields .= '<div class="form-group"><label>' . ($block === 'contact-form' || $block === 'survey-form' ? 'پیام' : 'شرح ایراد') . $R($req('description', false)) . '</label><textarea name="description" rows="4" class="sahand-fi" placeholder="' . $ph . '" minlength="10"' . $requiredAttr($req('description', false)) . '></textarea></div>';
        }
        /* تصویر */
        if ($on('images', false) && $block === 'request-form') {
            $fields .= '<div class="form-group"><label>تصویر دستگاه (اختیاری — حداکثر ۵)</label><input type="file" name="images" class="sahand-fi sahand-file" accept="image/*" multiple><div class="sahand-imgs"></div></div>';
        }

        if ($fields === '') { $fields = '<div class="feat-d">همه فیلدهای این فرم غیرفعال شده‌اند.</div>'; }

        /* 🆕 v2.33 — survey-form: گزینه‌های امتیازی به‌صورت radio واقعی داخل فرم
           (طراحی تفصیلیِ سابق که case تکراریِ مرده بود — اکنون عملکردی) */
        if ($block === 'survey-form') {
            $opts = '';
            foreach (pvItems($props, [['⭐', 'بسیار راضی'], ['👍', 'راضی'], ['😐', 'معمولی']]) as $si => $so) {
                $opts .= '<label class="survey-opt"><input type="radio" name="rating" value="' . e($so['text']) . '"' . ($si === 0 ? ' checked' : '') . '> <span style="font-size:21px">' . e($so['icon'] ?: '⭐') . '</span> ' . e($so['text']) . '</label>';
            }
            $fields = '<div class="form-group" style="grid-column:1/-1"><label>میزان رضایت شما</label><div class="survey-opts">' . $opts . '</div></div>' . $fields;
        }

        $form = '<form class="sahand-form" data-form="' . e($block) . '" data-dest="' . e(implode(',', $dest)) . '" novalidate>'
            . '<div class="form-grid">' . $fields . '</div>'
            . '<button type="submit" class="hero-btn full sahand-form-btn">' . e($btnText) . '</button>'
            . '<div class="sahand-form-msg" style="display:none"></div>'
            . '</form>';

        /* 🎨 v2.33 — قالب اختصاصی هر فرم (ادغام طراحی تفصیلیِ سابق در فرم واقعی) */
        if ($block === 'hero-form') {
            $ph = trim((string)($props['phone'] ?? '')) ?: pv_brand_phone();
            return '<div class="hero-split"><div><div class="hero-title">' . e($title ?: 'درخواست تعمیر آنلاین') . '</div><div class="hero-sub">' . e($props['subtitle'] ?? 'فرم را پر کنید — کارشناسان ما تماس می‌گیرند') . '</div><div class="hero-btns">' . ($ph !== '' ? '<a class="hero-btn" href="tel:' . e($ph) . '">📞 ' . e($ph) . '</a>' : '<span class="hero-btn">📞 تماس فوری</span>') . '</div></div><div class="bb-form-card">' . $form . '</div></div>';
        }
        if ($block === 'callback-form' || $block === 'quick-contact-form') {
            return '<div class="quick-form-demo"><b>' . e($title ?: 'درخواست تماس کارشناس') . '</b>' . $form . '</div><div class="feat-d" style="text-align:center;margin-top:7px">✅ کارشناسان ما در کمتر از ۱۵ دقیقه تماس می‌گیرند</div>';
        }
        if ($block === 'appointment-compact') {
            return '<div class="apt-compact-demo"><b style="font-size:14px">' . e($title ?: 'نوبت تعمیر رزرو کنید') . '</b>' . $form . '</div>';
        }
        $head = $title ? '<div class="blk-title">' . e($title) . '</div>' : '';
        return $head . $form;
    }
}

if (!function_exists('pv_render_layout')) {
    function pv_render_layout(array $items): string
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
                    $colHtml = pv_render_layout($colItems);
                    $inner .= '<div class="pv-col">' . ($colHtml !== '' ? $colHtml : (pv_renderer_is_preview() ? '<div class="pv-col-empty">ستون ' . ($c + 1) . ' خالی است</div>' : '')) . '</div>';
                }
                $head = pv_render_block($block, $props);
                if ($inner !== '') {
                    $html .= $head . '<div class="pv-cols" style="--pv-n:' . $colCount . '">' . $inner . '</div>';
                }
            } else {
                $html .= pv_render_block($block, $props);
            }
        }
        return $html;
    }
}

if (!function_exists('pv_page_css_vars')) {
    function pv_page_css_vars(array $p): string
    {
        $v = [];
        $bg = (string)($p['pageBg'] ?? 'default');
        $bgCss = '';
        if ($bg === 'surface') { $bgCss = '#f1f5f9'; }
        elseif ($bg === 'light') { $bgCss = '#fafafa'; }
        elseif ($bg === 'dark') { $bgCss = '#0f172a'; }
        elseif ($bg === 'custom' && preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($p['pageBgColor'] ?? ''))) { $bgCss = (string)$p['pageBgColor']; }
        /* 🆕 v2.32 — گرادیانت زمینه */
        elseif ($bg === 'gradient') {
            $gf = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($p['gradFrom'] ?? '')) ? (string)$p['gradFrom'] : '#1e40af';
            $gt = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($p['gradTo'] ?? '')) ? (string)$p['gradTo'] : '#0ea5e9';
            $ang = max(0, min(360, (int)($p['gradAngle'] ?? 135)));
            $bgCss = 'linear-gradient(' . $ang . 'deg,' . $gf . ',' . $gt . ')';
        }
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
        /* 🆕 v2.44 (S08) — عرض دلخواه px + گردی کارت/دکمه + متن تیره */
        if ((string)($p['containerWidth'] ?? '') === 'custom') {
            $v['--pg-width'] = max(480, min(1920, (int)($p['containerPx'] ?? 1080))) . 'px';
        }
        $cardRadMap = ['sharp' => '2px', 'default' => '', 'round' => '22px', 'pill' => '34px'];
        $crad = (string)($p['cardRadius'] ?? 'default');
        if (isset($cardRadMap[$crad]) && $cardRadMap[$crad] !== '') { $v['--pg-card-rad'] = $cardRadMap[$crad]; }
        $btnRadMap = ['sharp' => '3px', 'default' => '', 'pill' => '999px'];
        $brad = (string)($p['btnRadius'] ?? 'default');
        if (isset($btnRadMap[$brad]) && $btnRadMap[$brad] !== '') { $v['--pg-btn-rad'] = $btnRadMap[$brad]; }
        if (!empty($p['darkText']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$p['darkText'])) { $v['--pg-dark-text'] = (string)$p['darkText']; }
        if (!empty($p['titleColor'])) { $v['--pg-title'] = (string)$p['titleColor']; }
        /* 🆕 v2.29 — فاصله محتوای صفحه از لبه‌ها (بالا/پایین/چپ/راست — درخواست کاربر) */
        $pgPad = static function ($val): string {
            $n = (int)$val;
            return ($val === '' || $val === null || $n < 0 || $n > 400) ? '' : $n . 'px';
        };
        $pt = $pgPad($p['padTop'] ?? ''); $pb = $pgPad($p['padBottom'] ?? '');
        $pl = $pgPad($p['padLeft'] ?? ''); $pr = $pgPad($p['padRight'] ?? '');
        if ($pt !== '') { $v['--pg-mt'] = $pt; }
        if ($pb !== '') { $v['--pg-mb'] = $pb; }
        if ($pl !== '') { $v['--pg-ml'] = $pl; }
        if ($pr !== '') { $v['--pg-mr'] = $pr; }
        /* 🆕 v2.31 — تنظیمات صفحه کامل‌تر */
        if (!empty($p['accentColor']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$p['accentColor'])) {
            $v['--pg-accent'] = (string)$p['accentColor'];
        }
        $fontMap = ['default' => '', 'vazir' => "'Vazir',Vazirmatn,Tahoma,sans-serif", 'system' => 'Tahoma,Arial,sans-serif'];
        $fam = (string)($p['fontFamily'] ?? 'default');
        if (isset($fontMap[$fam]) && $fontMap[$fam] !== '') { $v['--pg-font'] = $fontMap[$fam]; }
        $hw = (string)($p['headingWeight'] ?? '');
        if (in_array($hw, ['700', '800', '900'], true)) { $v['--pg-hw'] = $hw; }
        $ls = (string)($p['letterSpacing'] ?? 'default');
        if ($ls === 'tight') { $v['--pg-ls'] = '-.5px'; }
        elseif ($ls === 'wide') { $v['--pg-ls'] = '1.2px'; }
        /* 🆕 v2.43 (S08) — استایل چیدمان صفحه: تمام‌عرض/جعبه/وسط/درصد دلخواه */
        $layout = (string)($p['pageLayout'] ?? 'normal');
        if (in_array($layout, ['full', 'boxed', 'center', 'percent'], true)) {
            $v['--pg-layout'] = $layout;
        }
        if ($layout === 'percent') {
            $v['--pg-layout-w'] = max(40, min(100, (int)($p['customWidthPct'] ?? 80))) . '%';
        }
        if ($layout === 'boxed') {
            $v['--pg-box-pad'] = max(0, min(80, (int)($p['boxedPad'] ?? 26))) . 'px';
            $v['--pg-box-rad'] = max(0, min(40, (int)($p['boxedRadius'] ?? 18))) . 'px';
            if (preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($p['boxedBg'] ?? ''))) {
                $v['--pg-box-bg'] = (string)$p['boxedBg'];
            }
        }
        /* 🆕 v2.32 — تایپوگرافی: رنگ متن بدنه + رنگ لینک + ارتفاع خط + اندازه عنوان */
        if (!empty($p['bodyColor']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$p['bodyColor'])) { $v['--pg-body'] = (string)$p['bodyColor']; }
        if (!empty($p['linkColor']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$p['linkColor'])) { $v['--pg-link'] = (string)$p['linkColor']; }
        $lhMap = ['compact' => '1.6', 'default' => '', 'roomy' => '2.1'];
        $lh = (string)($p['lineHeight'] ?? 'default');
        if (isset($lhMap[$lh]) && $lhMap[$lh] !== '') { $v['--pg-lh'] = $lhMap[$lh]; }
        $tsMap = ['sm' => '15px', 'md' => '', 'lg' => '21px', 'xl' => '26px'];
        $tsz = (string)($p['titleSize'] ?? 'md');
        if (isset($tsMap[$tsz]) && $tsMap[$tsz] !== '') { $v['--pg-title-size'] = $tsMap[$tsz]; }
        $out = '';
        foreach ($v as $k => $val) { $out .= $k . ':' . $val . ';'; }
        return $out;
    }
}
