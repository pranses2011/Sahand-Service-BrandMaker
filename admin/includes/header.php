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

// 🛂 v2.34 — نقش محدود (brand_manager): آیتم‌های سیستمی منو پنهان می‌شوند
$isLimitedRole = $auth->isBrandManager();

/* 🛑 v2.34 — صفحات سیستمی فقط برای نقش سیستمی (admin/editor)
   محافظت مرکزی: حتی با تایپ مستقیم URL هم ۴۰۳ می‌گیرد */
if ($isLimitedRole) {
    $_systemOnlyPages = [
        'settings.php', 'api-keys.php', 'webmaster.php', 'cpanel-settings.php', 'migrate.php',
        'fonts.php', 'icons.php', 'themes.php', 'ai-learning.php',
        'telegram.php', 'bale.php', 'health-dashboard.php', 'deployment-logs.php',
        'users.php',
    ];
    $_currentPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($_currentPage, $_systemOnlyPages, true)) {
        Logger::activity($auth->userId(), 'دسترسی غیرمجاز', 'تلاش برای ورود به صفحه سیستمی ' . $_currentPage);
        http_response_code(403);
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>دسترسی غیرمجاز</title></head>'
            . '<body style="font-family:Tahoma,sans-serif;direction:rtl;text-align:center;padding:60px 20px;background:#f8fafc">'
            . '<div style="font-size:56px">⛔</div><h2>این بخش فقط برای مدیر سیستم است</h2>'
            . '<p>حساب شما فقط به برندهای تخصیص‌یافته دسترسی دارد.</p>'
            . '<p><a href="index.php">بازگشت به داشبورد</a></p></body></html>';
        exit;
    }
}

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
    <?php
    /* 🔤 v2.12: فونت محیط سایت‌ساز — انتخابی از تنظیمات ← تب «فونت محیط»
       فونت‌های نصب‌شده با @font-face فارسی نام ثبت شده‌اند؛ انتخاب مدیر روی
       متغیرهای CSS (--font / --font-heading / --font-mono) اعمال می‌شود. */
    $uiFonts = (array)(Config::get('admin_ui_fonts') ?: []);
    if (!empty($uiFonts['body']) || !empty($uiFonts['heading']) || !empty($uiFonts['mono'])):
        $fBody = trim((string)($uiFonts['body'] ?? ''));
        $fHead = trim((string)($uiFonts['heading'] ?? ''));
        $fMono = trim((string)($uiFonts['mono'] ?? ''));
    ?>
    <link rel="stylesheet" href="<?= asset_ver('assets/css/fonts.css') ?>">
    <style>
        :root {
            <?= $fBody !== '' ? "--font: '{$fBody}', Vazirmatn, Tahoma, 'Segoe UI', Arial, sans-serif;" : '' ?>
            <?= $fHead !== '' ? "--font-heading: '{$fHead}', Vazirmatn, Tahoma, sans-serif;" : '' ?>
            <?= $fMono !== '' ? "--font-mono: '{$fMono}', ui-monospace, monospace;" : '' ?>
        }
    </style>
    <?php endif; ?>
    <!-- 🧠 اسکریپت پنل در هد بارگذاری می‌شود تا حتی اگر رندر صفحه وسط کار قطع شود،
         منو و تعاملات پایه (toggleSidebar و ...) همچنان کار کنند -->
    <script src="<?= asset_ver('assets/js/admin.js') ?>" defer></script>
    <!-- 💬 کادرهای تعاملی زیبا (جایگزین alert/confirm) — قبل از admin.js تا همیشه در دسترس باشد -->
    <script src="<?= asset_ver('assets/js/sahand-dialog.js') ?>" defer></script>
    <!-- 🎬 v2.44 (S14): انیمیشن‌های Lottie — fflate (باز کردن بسته .lottie) + پخش‌کننده + رابط SLottie -->
    <script src="<?= asset_ver('assets/js/vendor/fflate.min.js') ?>" defer></script>
    <script src="<?= asset_ver('assets/js/vendor/lottie.min.js') ?>" defer></script>
    <script src="<?= asset_ver('assets/js/lottie-player.js') ?>" defer></script>
    <script>window.SAHAND_VER='<?= e(SAHAND_VERSION) ?>';</script>
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
                <a class="nav-link" href="../logo-motion.html">
                    <span class="icon">🎞️</span> لوگوموشن
                </a>
                <a class="nav-link <?= $activeMenu === 'themes' ? 'active' : '' ?>" href="themes.php" <?= $isLimitedRole ? 'style="display:none"' : '' ?>>
                    <span class="icon">🎨</span> تم‌ها
                </a>
                <a class="nav-link <?= $activeMenu === 'menus' ? 'active' : '' ?>" href="menus.php">
                    <span class="icon">☰</span> منوها
                </a>
                <a class="nav-link <?= $activeMenu === 'icons' ? 'active' : '' ?>" href="icons.php" <?= $isLimitedRole ? 'style="display:none"' : '' ?>>
                    <span class="icon">🖼️</span> آیکون‌ها
                </a>
                <a class="nav-link <?= $activeMenu === 'fonts' ? 'active' : '' ?>" href="fonts.php" <?= $isLimitedRole ? 'style="display:none"' : '' ?>>
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
                <a class="nav-link <?= $activeMenu === 'form-entries' ? 'active' : '' ?>" href="form-entries.php">
                    <span class="icon">📋</span> فرم‌های دیگر
                    <?php
                    /* 🆕 v2.31 — بج فرم‌های امروز */
                    try {
                        $todayForms = (int)Database::getInstance()->fetchValue('SELECT COUNT(*) FROM form_entries WHERE created_at >= ?', [date('Y-m-d 00:00:00')]);
                    } catch (Throwable $tfE) { $todayForms = 0; }
                    if ($todayForms > 0):
                        ?>
                        <span class="badge"><?= en_to_fa_digits((string)$todayForms) ?></span>
                    <?php endif; ?>
                </a>
                <?php /* 🧩 v2.44 (S12) — فرم‌ساز سفارشی */ ?>
                <a class="nav-link <?= $activeMenu === 'form-builder' ? 'active' : '' ?>" href="form-builder.php">
                    <span class="icon">🧩</span> فرم‌ساز
                    <?php
                    try {
                        $activeForms = (int)Database::getInstance()->fetchValue('SELECT COUNT(*) FROM custom_forms WHERE is_active = 1');
                    } catch (Throwable $afE) { $activeForms = 0; }
                    if ($activeForms > 0):
                        ?>
                        <span class="badge" style="background:#818cf8"><?= en_to_fa_digits((string)$activeForms) ?></span>
                    <?php endif; ?>
                </a>
                <a class="nav-link <?= $activeMenu === 'analytics' ? 'active' : '' ?>" href="analytics.php">
                    <span class="icon">📈</span> آمار و گزارش
                </a>
                <a class="nav-link <?= $activeMenu === 'seo' ? 'active' : '' ?>" href="seo.php">
                    <span class="icon">🔍</span> مرکز سئو
                </a>
                <a class="nav-link <?= $activeMenu === 'ai-learning' ? 'active' : '' ?>" href="ai-learning.php" <?= $isLimitedRole ? 'style="display:none"' : '' ?>>
                    <span class="icon">🧠</span> یادگیری AI
                </a>
                <a class="nav-link <?= $activeMenu === 'telegram' ? 'active' : '' ?>" href="telegram.php" <?= $isLimitedRole ? 'style="display:none"' : '' ?>>
                    <span class="icon">🤖</span> ربات تلگرام
                </a>
                <a class="nav-link <?= $activeMenu === 'bale' ? 'active' : '' ?>" href="bale.php" <?= $isLimitedRole ? 'style="display:none"' : '' ?>>
                    <span class="icon">💬</span> ربات بله
                </a>
                <a class="nav-link <?= $activeMenu === 'media' ? 'active' : '' ?>" href="media.php">
                    <span class="icon">🗃️</span> رسانه‌ها
                </a>
                <!-- 💬 v2.37 — P3: دیدگاه مقالات + وب‌هوک + تست A/B -->
                <a class="nav-link <?= $activeMenu === 'comments' ? 'active' : '' ?>" href="comments.php">
                    <span class="icon">💬</span> دیدگاه‌ها
                    <?php
                    /* 🆕 v2.37 — بج دیدگاه‌های در انتظار تأیید */
                    try {
                        $pendingComments = (int)Database::getInstance()->fetchValue("SELECT COUNT(*) FROM article_comments WHERE status = 'pending'");
                    } catch (Throwable $pcE) { $pendingComments = 0; }
                    if ($pendingComments > 0):
                        ?>
                        <span class="badge"><?= en_to_fa_digits((string)$pendingComments) ?></span>
                    <?php endif; ?>
                </a>
                <a class="nav-link <?= $activeMenu === 'ab-tests' ? 'active' : '' ?>" href="ab-tests.php" <?= $isLimitedRole ? 'style="display:none"' : '' ?>>
                    <span class="icon">🧪</span> تست A/B
                </a>
                <a class="nav-link <?= $activeMenu === 'webhooks' ? 'active' : '' ?>" href="webhooks.php" <?= $isLimitedRole ? 'style="display:none"' : '' ?>>
                    <span class="icon">🪝</span> وب‌هوک‌ها
                </a>
                <a class="nav-link <?= $activeMenu === 'revisions' ? 'active' : '' ?>" href="revisions.php">
                    <span class="icon">🕘</span> تاریخچه تغییرات
                </a>
                <a class="nav-link <?= $activeMenu === 'export' ? 'active' : '' ?>" href="export.php">
                    <span class="icon">📦</span> خروجی و استقرار
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">🚀 استقرار خودکار</div>
                <a class="nav-link <?= $activeMenu === 'deploy' ? 'active' : '' ?>" href="deploy.php">
                    <span class="icon">🚀</span> استقرار سایت‌ها
                </a>
                <a class="nav-link <?= $activeMenu === 'health-dashboard' ? 'active' : '' ?>" href="health-dashboard.php" <?= $isLimitedRole ? 'style="display:none"' : '' ?>>
                    <span class="icon">📊</span> سلامت سایت‌ها
                </a>
                <a class="nav-link <?= $activeMenu === 'backups' ? 'active' : '' ?>" href="backups.php">
                    <span class="icon">💾</span> بکاپ‌ها
                </a>
                <a class="nav-link <?= $activeMenu === 'deployment-logs' ? 'active' : '' ?>" href="deployment-logs.php" <?= $isLimitedRole ? 'style="display:none"' : '' ?>>
                    <span class="icon">📋</span> لاگ استقرار
                </a>
                <a class="nav-link <?= $activeMenu === 'cpanel-settings' ? 'active' : '' ?>" href="cpanel-settings.php" <?= $isLimitedRole ? 'style="display:none"' : '' ?>>
                    <span class="icon">⚙️</span> اتصال cPanel
                </a>
                <a class="nav-link <?= $activeMenu === 'migrate' ? 'active' : '' ?>" href="migrate.php" <?= $isLimitedRole ? 'style="display:none"' : '' ?>>
                    <span class="icon">🗃️</span> مهاجرت دیتابیس
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">سیستم</div>
                <a class="nav-link <?= $activeMenu === 'users' ? 'active' : '' ?>" href="users.php" <?= $isLimitedRole ? 'style="display:none"' : '' ?>>
                    <span class="icon">👥</span> کاربران و دسترسی
                </a>
                <a class="nav-link <?= $activeMenu === 'settings' ? 'active' : '' ?>" href="settings.php" <?= $isLimitedRole ? 'style="display:none"' : '' ?>>
                    <span class="icon">⚙️</span> تنظیمات
                </a>
                <a class="nav-link <?= $activeMenu === 'api-keys' ? 'active' : '' ?>" href="api-keys.php" <?= $isLimitedRole ? 'style="display:none"' : '' ?>>
                    <span class="icon">🔑</span> کلیدهای API
                </a>
                <a class="nav-link <?= $activeMenu === 'webmaster' ? 'active' : '' ?>" href="webmaster.php" <?= $isLimitedRole ? 'style="display:none"' : '' ?>>
                    <span class="icon">🔗</span> تگ‌های وبمستر
                </a>
                <a class="nav-link <?= $activeMenu === 'docs' ? 'active' : '' ?>" href="docs.php">
                    <span class="icon">📚</span> مستندات
                </a>
                <a class="nav-link <?= $activeMenu === 'profile' ? 'active' : '' ?>" href="profile.php">
                    <span class="icon">👤</span> حساب کاربری من
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
                <a href="profile.php" title="حساب کاربری من — رمز، آواتار و ورود دومرحله‌ای" style="text-decoration:none">
                    <div class="user-chip" style="cursor:pointer">
                        <?php
                        /* 🖼 آواتار کاربر (v2.39) — از DB؛ در نبود آن حرف اول نام */
                        $topAvatar = '';
                        try {
                            $topAvatar = (string)(Database::getInstance()->fetchValue('SELECT avatar FROM users WHERE id = ? LIMIT 1', [(int)($_SESSION['user_id'] ?? 0)]) ?: '');
                        } catch (Throwable $avE) { $topAvatar = ''; }
                        if ($topAvatar !== '' && is_file(ROOT_PATH . '/' . $topAvatar)): ?>
                            <img class="avatar" src="<?= e(asset_ver($topAvatar)) ?>" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;display:block">
                        <?php else: ?>
                            <span class="avatar"><?= e(mb_substr($_SESSION['full_name'] ?? 'م', 0, 1)) ?></span>
                        <?php endif; ?>
                        <span><?= e($_SESSION['full_name'] ?? '') ?></span>
                    </div>
                </a>
            </div>
        </header>

        <main class="content">
            <?php foreach (get_flashes() as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> alert-auto"><?= e($flash['message']) ?></div>
            <?php endforeach; ?>
