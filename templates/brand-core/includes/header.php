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
$brandData = fetchFromAPI('brand/' . BRAND_ID)['data'] ?? null;
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
    <!-- 🔖 فاویکون و لوگو -->
    <link rel="icon" href="<?= e($brand['logo'] ?: cdn_asset('images/placeholders/favicon.png')) ?>">
    <!-- 🔤 فونت از سرور سایت ساز -->
    <link rel="stylesheet" href="<?= e(BRANDMAKER_ASSETS) ?>/css/fonts.css">
    <!-- 🎨 استایل‌ها -->
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/theme-light.css" id="theme-stylesheet">
    <!-- 🧩 Schema.org -->
    <script type="application/ld+json"><?= json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'LocalBusiness',
        'name' => ($brand['name_fa'] ?? '') . ' — ' . ($agency['name_fa'] ?? ''),
        'url' => 'https://' . BRAND_DOMAIN,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
</head>
<body>
<!-- ⚙️ تغییر تم روشن/تاریک -->
<script>
    (function () {
        var theme = localStorage.getItem('brand-theme');
        if (theme === 'dark') {
            document.getElementById('theme-stylesheet').href = '/css/theme-dark.css';
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    })();
    function toggleTheme() {
        var current = localStorage.getItem('brand-theme') === 'dark' ? 'light' : 'dark';
        localStorage.setItem('brand-theme', current);
        document.getElementById('theme-stylesheet').href = current === 'dark' ? '/css/theme-dark.css' : '/css/theme-light.css';
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
            <button class="nav-toggle" onclick="document.querySelector('.main-nav ul').classList.toggle('open')" aria-label="منو">☰</button>
            <ul>
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
