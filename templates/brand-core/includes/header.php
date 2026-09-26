<?php
/**
 * 🔝 هدر مشترک سایت برند
 * ========================
 * شامل: تگ‌های سئو، منو، لوگوی برند + نمایندگی
 *
 * @package SahandBrandSite
 */
if (!defined('BRAND_INIT')) {
    http_response_code(403);
    exit;
}

// 📥 داده‌های برند و تنظیمات (با کش)
/* ⏱️ v2.25: TTL اطلاعات برند ۱۲۰ ثانیه (قبلاً ۳۰۰) — تغییر پالت/لوگو در
   پنل حداکثر تا ۲ دقیقه بعد روی سایت برند دیده می‌شود (رفع بخشی از
   «رنگ پیش‌نمایش با سایت فرق دارد» که ریشه‌اش کش طولانی سایت برند بود). */
$brandData = fetchFromAPI('brand/' . BRAND_ID, 120)['data'] ?? null;
$brand = $brandData['brand'] ?? [];
$palette = $brandData['palette'] ?? [];
$agency = $brandData['agency'] ?? [];
$menuData = fetchFromAPI('brand/' . BRAND_ID . '/menu/header')['data'] ?? [];

// 🏷️ عنوان و سئو پیش‌فرض صفحه (توسط هر صفحه قابل بازنویسی)
$pageTitle = $pageTitle ?? ($brand['seo']['title'] ?? BRAND_NAME_FA);
$pageDesc = $pageDesc ?? ($brand['seo']['description'] ?? '');
$pageKey = $pageKey ?? ($brand['seo']['keywords'] ?? '');
$ogImage = $ogImage ?? ($brand['logo'] ?? '');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($pageDesc) ?>">
    <?php if ($pageKey): ?><meta name="keywords" content="<?= e($pageKey) ?>"><?php endif; ?>
    <meta name="robots" content="index,follow">
    <link rel="canonical" href="https://<?= e(BRAND_DOMAIN) ?><?= e($_SERVER['REQUEST_URI'] ?? '/') ?>">
    <!-- 📗 Open Graph -->
    <meta property="og:title" content="<?= e($pageTitle) ?>">
    <meta property="og:description" content="<?= e($pageDesc) ?>">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fa_IR">
    <?php if ($ogImage): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
    <!-- 🐦 Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($pageTitle) ?>">
    <meta name="twitter:description" content="<?= e($pageDesc) ?>">
    <!-- 🔖 فاویکون و لوگو (v2.12: فاوآیکون اختصاصی برند — پیش‌فرض لوگو) -->
    <?php $faviconUrl = trim((string)($brand['favicon'] ?? '')) ?: trim((string)($brand['logo'] ?? '')); ?>
    <link rel="icon" href="<?= e($faviconUrl ?: cdn_asset('images/placeholders/favicon.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e($faviconUrl ?: cdn_asset('images/placeholders/favicon.png')) ?>">
    <!-- 🔤 فونت از سرور سایت ساز -->
    <link rel="stylesheet" href="<?= e(BRANDMAKER_ASSETS) ?>/css/fonts.css">
    <!-- 🎨 استایل‌ها -->
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/theme-light.css" id="theme-stylesheet">
    <?php
    /* 🎨 v2.24 — پالت زنده از API سایت ساز (دو منفعت):
       ① تغییر پالت/تم در پنل، بدون استقرار مجدد و بعد از انقضای کش (پیش‌فرض
         ۵ دقیقه) روی سایت برند اعمال می‌شود — قبلاً رنگ‌ها فقط هنگام ساخت ZIP
         «پخته» می‌شدند و هیچ تغییری اثر نمی‌کرد (ریشه «تنظیمات تم اعمال نمی‌شود»).
       ② اگر theme-*.css مستقرشده خالی/قدیمی باشد، این استایل جایگزین می‌شود.
       ساختار: :root (روشن — همیشه برنده بر theme-light.css چون بعدش لود می‌شود)
       + html[data-theme="dark"] (تاریک — انتخاب‌گر قوی‌تر از :root، فقط وقتی
       کاربر تم تاریک فعال کند اعمال می‌شود؛ هماهنگ با toggleTheme) */
    $liveLight = is_array($palette['light'] ?? null) ? $palette['light'] : [];
    $liveDark  = is_array($palette['dark'] ?? null) ? $palette['dark'] : [];
    $cssVars = static function (array $vars, string $selector): string {
        $lines = '';
        foreach ($vars as $k => $v) {
            $k = trim((string)$k);
            $v = trim((string)$v);
            if ($k !== '' && $v !== '' && preg_match('/^--[\w-]+$/', $k) && preg_match('/^[#()\w\s,.%\-\d]+$/', $v)) {
                $lines .= $k . ':' . $v . ';';
            }
        }
        return $lines !== '' ? $selector . '{' . $lines . '}' : '';
    };
    $inlinePalette = $cssVars($liveLight, ':root') . $cssVars($liveDark, 'html[data-theme="dark"]');
    if ($inlinePalette !== ''): ?>
    <style id="brand-palette-live"><?= $inlinePalette ?></style>
    <?php endif; ?>
    <!-- 🧩 Schema.org -->
    <script type="application/ld+json"><?= json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'LocalBusiness',
        'name' => ($brand['name_fa'] ?? '') . ' — ' . ($agency['name_fa'] ?? ''),
        'url' => 'https://' . BRAND_DOMAIN,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
</head>
<body>
<!-- ⚙️ تغییر تم روشن/تاریک — v2.25: آنی + آیکون پویا
     🚨 ریشه باگ قبلی: toggleTheme فقط href استایل‌شیت را عوض می‌کرد اما
     خصیصه data-theme را تنظیم نمی‌کرد؛ پالت زنده (html[data-theme="dark"])
     و قوانین تیره فقط با این خصیصه فعال می‌شوند → تغییر تم تا رفرش بعدی
     اثر نمی‌کرد و آیکون دکمه هم ثابت می‌ماند. -->
<script>
    (function () {
        var stored = null;
        try { stored = localStorage.getItem('brand-theme'); } catch (e) {}
        if (stored === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            var l = document.getElementById('theme-stylesheet');
            if (l) { l.href = '/css/theme-dark.css'; }
        }
        if (stored !== 'dark' && stored !== 'light') {
            /* 🌓 پیش‌فرض هوشمند: تم سیستم کاربر */
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                applyTheme('dark', false);
            }
        }
    })();
    function applyTheme(t, save) {
        document.documentElement.setAttribute('data-theme', t);
        document.documentElement.style.colorScheme = t === 'dark' ? 'dark' : 'light';
        var link = document.getElementById('theme-stylesheet');
        if (link) { link.href = t === 'dark' ? '/css/theme-dark.css' : '/css/theme-light.css'; }
        var btn = document.querySelector('.theme-toggle');
        if (btn) {
            btn.textContent = t === 'dark' ? '☀️' : '🌙';
            btn.title = t === 'dark' ? 'تغییر به تم روشن' : 'تغییر به تم تاریک';
            btn.setAttribute('aria-label', btn.title);
        }
        if (save !== false) {
            try { localStorage.setItem('brand-theme', t); } catch (e) {}
        }
    }
    function toggleTheme() {
        var cur = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        applyTheme(cur, true);
    }
</script>

<!-- 🔝 هدر سایت -->
<header class="site-header">
    <div class="container header-inner">
        <div class="brand-logos">
            <a href="/" class="brand-logo">
                <?php if (!empty($brand['logo'])): ?>
                    <img src="<?= e($brand['logo']) ?>" alt="<?= e($brand['name_fa']) ?>">
                <?php endif; ?>
                <span class="brand-name"><?= e($brand['name_fa'] ?? '') ?></span>
            </a>
            <span class="logo-separator">|</span>
            <a href="<?= e($agency['main_site'] ?? '#') ?>" class="agency-logo" target="_blank" rel="nofollow" title="<?= e($agency['name_fa'] ?? '') ?>">
                <?php if (!empty($agency['logo'])): ?>
                    <img src="<?= e($agency['logo']) ?>" alt="<?= e($agency['name_fa'] ?? '') ?>">
                <?php else: ?>
                    <span class="agency-name"><?= e($agency['name_fa'] ?? '') ?></span>
                <?php endif; ?>
            </a>
        </div>
        <nav class="main-nav" aria-label="منوی اصلی">
            <button class="nav-toggle" onclick="toggleNav(this)" aria-label="باز و بسته کردن منو" aria-controls="mainNavList" aria-expanded="false">☰</button>
            <ul id="mainNavList">
                <?php foreach ($menuData as $item): ?>
                    <?php $href = !empty($item['url']) ? $item['url'] : '/' . ($item['page_type'] === 'home' ? '' : $item['page_type']); ?>
                    <li>
                        <a href="<?= e($href) ?>" <?= !empty($item['nofollow']) ? 'rel="nofollow"' : '' ?>>
                            <?= $item['icon'] ? '<span class="menu-icon">' . e($item['icon']) . '</span>' : '' ?>
                            <?= e($item['title']) ?>
                        </a>
                        <?php if (!empty($item['children'])): ?>
                            <ul class="submenu">
                                <?php foreach ($item['children'] as $child): ?>
                                    <?php $chref = !empty($child['url']) ? $child['url'] : '/' . ($child['page_type'] === 'home' ? '' : $child['page_type']); ?>
                                    <li><a href="<?= e($chref) ?>"><?= e($child['title']) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <button class="theme-toggle" onclick="toggleTheme()" title="تغییر تم روشن/تاریک">🌙</button>
    </div>
</header>
<main>
