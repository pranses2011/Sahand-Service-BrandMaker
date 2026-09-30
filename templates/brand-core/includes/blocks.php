<?php
/**
 * 🧱 رندرگر بلوک‌ها روی سایت برند — آغازگر حالت site (P2-21)
 * ==================================================
 * تمام منطق رندر اکنون در «هسته رندرگر واحد» است
 * (includes/block-renderer-core.php — مشترک با پیش‌نمایش و بوم AJAX).
 * این فایل: ① حالت site را آغاز می‌کند (فالبک داده‌های زنده برند از
 * API سایت‌ساز) ② توابع bb* قدیمی را برای سازگاری صفحات نگه می‌دارد
 * ③ HTML کامل چیدمان (bb_layout_html) با الگوی زمینه/جلوه‌های صفحه را
 * می‌سازد — بخشی که مخصوص سایت واقعی است.
 *
 * @package SahandBrandSite
 */
if (!defined('BRAND_INIT')) { http_response_code(403); exit; }

/* 🎭 حالت site + هسته رندرگر واحد */
require_once __DIR__ . '/block-renderer-core.php';
pv_renderer_init(['mode' => 'site']);

/* ═══════════════════════════════════════════════════════════════
 * 🔄 توابع سازگاری bb* — صفحات سایت برند و کدهای قدیمی
 * ═══════════════════════════════════════════════════════════════ */
if (!function_exists('bb_render_block')) {
    function bb_render_block(string $block, array $props = [], array $pelements = []): string
    {
        if (!empty($pelements)) { pv_renderer_init(['mode' => 'site', 'pelements' => $pelements]); }
        return pv_render_block($block, $props);
    }
}
if (!function_exists('bb_render_layout')) {
    function bb_render_layout(array $items, array $pelements = []): string
    {
        if (!empty($pelements)) { pv_renderer_init(['mode' => 'site', 'pelements' => $pelements]); }
        return pv_render_layout($items);
    }
}
if (!function_exists('bb_page_css_vars')) {
    function bb_page_css_vars(array $p): string
    {
        return pv_page_css_vars($p);
    }
}

/* ═══════════════════════════════════════════════════════════════
 * 🧱 HTML کامل چیدمان — داده API تم/قالب/صفحه (مخصوص سایت برند)
 * @param array $tplData  پاسخ endpoint قالب: ['name'=>.., 'layout'=>[...], 'pelements'=>[id=>..]]
 * @return string خالی اگر چیدمانی نبود (صفحه به ساختار ثابت برمی‌گردد)
 * ═══════════════════════════════════════════════════════════════ */
if (!function_exists('bb_layout_html')) {
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
        pv_renderer_init(['mode' => 'site', 'pelements' => $pelements]);
        /* گره تنظیمات صفحه */
        $pageProps = [];
        if (is_array($layout[0] ?? null) && ($layout[0]['block'] ?? '') === '_page') {
            $pageProps = is_array($layout[0]['props'] ?? null) ? $layout[0]['props'] : [];
            array_shift($layout);
        }
        $pageStyle = pv_page_css_vars($pageProps);
        /* 🆕 v2.44 (S08) — حالت چیدمان + حالت تیره روی قاب صفحه */
        $pageLayoutCls = '';
        $plMode = (string)($pageProps['pageLayout'] ?? 'normal');
        if (in_array($plMode, ['full', 'boxed', 'center', 'percent'], true)) {
            $pageLayoutCls = ' bb-layout-' . $plMode;
        }
        if (!empty($pageProps['pageDark'])) {
            $pageLayoutCls .= ' bb-page-dark';
        }
        /* 🆕 v2.44 (S08) — جلوه hover کارت‌ها (کلاس روی قاب) */
        $hoverMap = ['none' => '', 'lift' => ' bb-hover-lift', 'zoom' => ' bb-hover-zoom', 'glow' => ' bb-hover-glow'];
        $hv = (string)($pageProps['cardHover'] ?? 'lift');
        $pageLayoutCls .= $hoverMap[$hv] ?? ' bb-hover-lift';
        $inner = pv_render_layout($layout);
        if (trim($inner) === '') {
            return '';
        }
        /* 🆕 v2.31 — الگوی زمینه صفحه */
        $patternCls = '';
        $pattern = (string)($pageProps['pagePattern'] ?? 'none');
        if (in_array($pattern, ['dots', 'grid', 'stripes'], true)) {
            $patternCls = ' bb-pat-' . $pattern;
            if (preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($pageProps['patternColor'] ?? ''))) {
                $pageStyle .= '--pg-pat:' . (string)$pageProps['patternColor'] . ';';
            }
        }
        /* 🆕 v2.32 — تصویر زمینه صفحه + پوشش رنگ (خوانایی متن) */
        $bgImageStyle = '';
        $bgBlurCls = '';
        if ((string)($pageProps['pageBg'] ?? '') === 'image' && preg_match('#^https?://[^\s"\'<>]{5,500}$#i', (string)($pageProps['bgImage'] ?? ''))) {
            $imgUrl = (string)$pageProps['bgImage'];
            $oc = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($pageProps['overlayColor'] ?? '')) ? (string)$pageProps['overlayColor'] : '#0f172a';
            $op = max(0, min(100, (int)($pageProps['overlayOpacity'] ?? 35))) / 100;
            $r = (int)hexdec(substr($oc, 1, 2)); $g = (int)hexdec(substr($oc, 3, 2)); $b = (int)hexdec(substr($oc, 5, 2));
            /* 🆕 v2.44 (S08) — بلور تصویر زمینه: تصویر به لایه شبه‌عنصر می‌رود تا فقط خودش تار شود */
            $bgBlurPx = max(0, min(20, (int)($pageProps['bgBlur'] ?? 0)));
            if ($bgBlurPx > 0) {
                $bgBlurCls = ' bb-bg-blur';
                $pageStyle .= '--pg-bg-img:url(\'' . e($imgUrl) . '\');--pg-bg-blur:' . $bgBlurPx . 'px;'
                    . 'background-image:linear-gradient(rgba(' . $r . ',' . $g . ',' . $b . ',' . $op . '),rgba(' . $r . ',' . $g . ',' . $b . ',' . $op . '));'
                    . 'background-size:cover;background-position:center;';
            } else {
                $bgImageStyle = 'background-image:linear-gradient(rgba(' . $r . ',' . $g . ',' . $b . ',' . $op . '),rgba(' . $r . ',' . $g . ',' . $b . ',' . $op . ')),url(\'' . e($imgUrl) . '\');'
                    . 'background-size:cover;background-position:center;'
                    . (empty($pageProps['bgImageFixed']) || ((int)$pageProps['bgImageFixed'] === 1) ? 'background-attachment:fixed;' : '');
            }
        }
        /* 🆕 v2.32 — جلوه‌های صفحه: نوار پیشرفت اسکرول + دکمه بازگشت به بالا + اسکرول نرم */
        $effectsHtml = '';
        $effectsJs = '';
        if (!empty($pageProps['scrollProgress'])) {
            $effectsHtml .= '<div class="bb-scroll-progress" aria-hidden="true"></div>';
            $effectsJs .= 'var sp=document.querySelector(".bb-scroll-progress");if(sp){var up=function(){var h=document.documentElement.scrollHeight-window.innerHeight;sp.style.width=(h>0?(window.scrollY/h*100):0)+"%"};window.addEventListener("scroll",up,{passive:true});up();}';
        }
        if (!empty($pageProps['backToTop'])) {
            $effectsHtml .= '<button type="button" class="bb-back-top" aria-label="بازگشت به بالا">⬆</button>';
            $effectsJs .= 'var bt=document.querySelector(".bb-back-top");if(bt){bt.addEventListener("click",function(){window.scrollTo({top:0,behavior:"smooth"})});var vis=function(){bt.classList.toggle("show",window.scrollY>420)};window.addEventListener("scroll",vis,{passive:true});vis();}';
        }
        if (!empty($pageProps['smoothScroll'])) {
            $effectsJs .= 'document.querySelectorAll(\'a[href^="#"]\').forEach(function(a){a.addEventListener("click",function(ev){var t=document.querySelector(a.getAttribute("href"));if(t){ev.preventDefault();t.scrollIntoView({behavior:"smooth"})}})});';
        }
        /* 🆕 v2.44 (S08) — نمایش تدریجی بخش‌ها هنگام اسکرول */
        if (!empty($pageProps['scrollReveal'])) {
            $effectsJs .= 'var rvs=document.querySelectorAll(".bb-wrap > .blk");if(rvs.length&&"IntersectionObserver" in window){rvs.forEach(function(el){el.classList.add("bb-reveal")});var io=new IntersectionObserver(function(en){en.forEach(function(x){if(x.isIntersecting){x.target.classList.add("bb-reveal-in");io.unobserve(x.target)}})},{threshold:.12});rvs.forEach(function(el){io.observe(el)})}';
        }
        if ($effectsJs !== '') {
            $effectsHtml .= '<script>document.addEventListener("DOMContentLoaded",function(){' . $effectsJs . '});</script>';
        }
        /* 🆕 v2.31 — CSS سفارشی صفحه (فقط این صفحه) */
        $customCss = trim((string)($pageProps['customCss'] ?? ''));
        $customTag = '';
        if ($customCss !== '' && strlen($customCss) < 8000) {
            /* حذف تگ و برچسب خطرناک */
            $customCss = preg_replace(["#</?[[:space:]]*script#i", "#</?[[:space:]]*style#i", "#expression[[:space:]]*\\(#i", "#url[[:space:]]*\\([[:space:]]*[\"']?javascript:#i"], '', $customCss);
            $customTag = '<style data-page-custom>' . $customCss . '</style>';
        }
        return '<div class="bb-wrap' . $patternCls . $pageLayoutCls . $bgBlurCls . '"' . ($pageStyle !== '' || $bgImageStyle !== '' ? ' style="' . e($pageStyle . $bgImageStyle) . '"' : '') . '>' . $inner . '</div>' . $effectsHtml . $customTag;
    }
}
