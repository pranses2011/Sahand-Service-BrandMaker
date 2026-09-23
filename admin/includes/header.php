<?php
/**
 * 🔝 هدر مشترک پنل مدیریت
 * =========================
 * شامل: بررسی ورود، سایدبار، نوار بالا، فلش‌مسیج‌ها
 * این فایل توسط تمام صفحات admin فراخوانی می‌شود.
 *
 * @package SahandBrandMaker
 */

if (!defined('SAHAND_INIT')) {
    http_response_code(403);
    exit('⛔ دسترسی مستقیم مجاز نیست.');
}

$auth = new Auth();
$auth->requireLogin();

// 📌 عنوان صفحه و آیتم فعال منو (توسط هر صفحه تعیین می‌شود)
$pageTitle = $pageTitle ?? 'پنل مدیریت';
$activeMenu = $activeMenu ?? '';

// 🔔 شمارش درخواست‌های جدید برای نشان منو
try {
    $newRequests = Database::getInstance()->count('service_requests', "status = 'new'");
} catch (Throwable $e) {
    $newRequests = 0;
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($pageTitle) ?> | <?= e(Config::get(Config::KEY_AGENCY_NAME_FA) ?: SAHAND_NAME_FA) ?></title>
    <link rel="stylesheet" href="<?= asset_ver('assets/css/admin.css') ?>">
    <link rel="icon" href="<?= asset_url((string)Config::get(Config::KEY_AGENCY_FAVICON)) ?>">
    <!-- 🧠 اسکریپت پنل در هد بارگذاری می‌شود تا حتی اگر رندر صفحه وسط کار قطع شود،
         منو و تعاملات پایه (toggleSidebar و ...) همچنان کار کنند -->
    <script src="<?= asset_ver('assets/js/admin.js') ?>" defer></script>
    <!-- 💬 کادرهای تعاملی زیبا (جایگزین alert/confirm) — قبل از admin.js تا همیشه در دسترس باشد -->
    <script src="<?= asset_ver('assets/js/sahand-dialog.js') ?>" defer></script>
</head>
<body>
<div class="layout">

    <!-- 🌒 پس‌زمینه تیره پشت سایدبار (موبایل) -->
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar(false)"></div>

    <!-- 🧭 سایدبار -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="logo">🏗️</div>
            <div>
                <h1>سایت ساز برند</h1>
                <small><?= e(Config::get(Config::KEY_AGENCY_NAME_EN) ?: 'Sahand Service') ?></small>
            </div>
        </div>

        <nav>
            <div class="nav-section">
                <div class="nav-section-title">اصلی</div>
                <a class="nav-link <?= $activeMenu === 'dashboard' ? 'active' : '' ?>" href="index.php">
                    <span class="icon">📊</span> داشبورد
                </a>
                <a class="nav-link <?= $activeMenu === 'brands' ? 'active' : '' ?>" href="brands.php">
                    <span class="icon">🏷️</span> مدیریت برندها
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">محتوا</div>
                <a class="nav-link <?= $activeMenu === 'articles' ? 'active' : '' ?>" href="articles.php">
                    <span class="icon">📰</span> مقالات
                </a>
                <a class="nav-link <?= $activeMenu === 'error-codes' ? 'active' : '' ?>" href="error-codes.php">
                    <span class="icon">🚨</span> کدهای خطا
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">طراحی</div>
                <a class="nav-link <?= $activeMenu === 'templates' ? 'active' : '' ?>" href="templates.php">
                    <span class="icon">🧩</span> قالب‌ها
                </a>
                <a class="nav-link <?= $activeMenu === 'template-builder' ? 'active' : '' ?>" href="template-builder.php">
                    <span class="icon">🎭</span> قالب‌ساز
                </a>
                <a class="nav-link <?= $activeMenu === 'themes' ? 'active' : '' ?>" href="themes.php">
                    <span class="icon">🎨</span> تم‌ها
                </a>
                <a class="nav-link <?= $activeMenu === 'menus' ? 'active' : '' ?>" href="menus.php">
                    <span class="icon">☰</span> منوها
                </a>
                <a class="nav-link <?= $activeMenu === 'icons' ? 'active' : '' ?>" href="icons.php">
                    <span class="icon">🖼️</span> آیکون‌ها
                </a>
                <a class="nav-link <?= $activeMenu === 'fonts' ? 'active' : '' ?>" href="fonts.php">
                    <span class="icon">🔤</span> فونت‌ها
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">عملیات</div>
                <a class="nav-link <?= $activeMenu === 'requests' ? 'active' : '' ?>" href="requests.php">
                    <span class="icon">📨</span> درخواست‌ها
                    <?php if ($newRequests > 0): ?>
                        <span class="badge"><?= en_to_fa_digits((string)$newRequests) ?></span>
                    <?php endif; ?>
                </a>
                <a class="nav-link <?= $activeMenu === 'analytics' ? 'active' : '' ?>" href="analytics.php">
                    <span class="icon">📈</span> آمار و گزارش
                </a>
                <a class="nav-link <?= $activeMenu === 'seo' ? 'active' : '' ?>" href="seo.php">
                    <span class="icon">🔍</span> مرکز سئو
                </a>
                <a class="nav-link <?= $activeMenu === 'ai-learning' ? 'active' : '' ?>" href="ai-learning.php">
                    <span class="icon">🧠</span> یادگیری AI
                </a>
                <a class="nav-link <?= $activeMenu === 'telegram' ? 'active' : '' ?>" href="telegram.php">
                    <span class="icon">🤖</span> ربات تلگرام
                </a>
                <a class="nav-link <?= $activeMenu === 'export' ? 'active' : '' ?>" href="export.php">
                    <span class="icon">📦</span> خروجی و استقرار
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">سیستم</div>
                <a class="nav-link <?= $activeMenu === 'settings' ? 'active' : '' ?>" href="settings.php">
                    <span class="icon">⚙️</span> تنظیمات
                </a>
                <a class="nav-link <?= $activeMenu === 'webmaster' ? 'active' : '' ?>" href="webmaster.php">
                    <span class="icon">🔗</span> تگ‌های وبمستر
                </a>
                <a class="nav-link <?= $activeMenu === 'docs' ? 'active' : '' ?>" href="docs.php">
                    <span class="icon">📚</span> مستندات
                </a>
                <a class="nav-link" href="logout.php">
                    <span class="icon">🚪</span> خروج
                </a>
            </div>
        </nav>
    </aside>

    <!-- 📄 بدنه اصلی -->
    <div class="main">
        <header class="topbar">
            <button class="menu-toggle" onclick="toggleSidebar()" aria-label="باز و بسته کردن منو" aria-controls="sidebar" aria-expanded="false" id="menuToggleBtn">☰</button>
            <div class="topbar-titles">
                <h2><?= e($pageTitle) ?></h2>
                <div class="breadcrumb">پنل مدیریت سایت ساز — <?= jdate(date('Y-m-d'), true) ?></div>
            </div>
            <div class="topbar-actions">
                <a class="btn-bell" href="requests.php" title="درخواست‌های جدید">
                    🔔
                    <?php if ($newRequests > 0): ?><span class="dot"></span><?php endif; ?>
                </a>
                <div class="user-chip">
                    <span class="avatar"><?= e(mb_substr($_SESSION['full_name'] ?? 'م', 0, 1)) ?></span>
                    <span><?= e($_SESSION['full_name'] ?? '') ?></span>
                </div>
            </div>
        </header>

        <main class="content">
            <?php foreach (get_flashes() as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> alert-auto"><?= e($flash['message']) ?></div>
            <?php endforeach; ?>
