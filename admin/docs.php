<?php
/**
 * 📚 مستندات و راهنما — نمایش درون پنل
 * =====================================
 * فهرست مستندات فارسی + نمایش محتوا
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$pageTitle = 'مستندات و راهنما';
$activeMenu = 'docs';
require __DIR__ . '/includes/header.php';

// 📚 فهرست مستندات موجود در پوشه docs
$docsList = [
    'user-guide'      => ['📘 راهنمای کاربری سایت ساز', 'آموزش کامل کار با تمام بخش‌های پنل مدیریت'],
    'installation'    => ['🚀 راهنمای نصب و راه‌اندازی', 'نحوه آپلود روی هاست و تنظیم اولیه'],
    'api-reference'   => ['🔌 راهنمای API داخلی', 'مستند فنی API با مثال‌ها'],
    'manual-editing'  => ['✏️ ویرایش دستی سایت‌ها', 'نحوه ویرایش فایل‌های سایت برند'],
    'google-script'   => ['📜 راهنمای واسط Google Script', 'راه‌اندازی ارسال تلگرام در زمان تحریم + کد آماده'],
    'error-codes'     => ['🚨 راهنمای ورود کدهای خطا', 'فرمت فایل‌های Excel و JSON'],
    'icons-guide'     => ['🖼️ راهنمای سیستم آیکون', 'ساخت و مدیریت پک‌های آیکون'],
];

// 🌐 مستندات خارجی (لینک مستقیم — فایل‌های Markdown در ریپو)
$externalDocs = [
    'ai-api' => [
        '🤖 راهنمای کامل API هوش مصنوعی (نسخه ۳.۱)',
        'استفاده از ۳۷ اندپوینت موتور AI از بیرون سایت ساز + 🌐 جستجوی آنلاین وب + راهنمای ربات تلگرام — با مثال PHP/Python/JS',
        'https://github.com/pranses2011/Sahand-Service-BrandMaker/blob/main/docs/AI-API-GUIDE.md',
    ],
];
$requested = get_param('doc', '');

// 🌐 هدایت مستندات خارجی (Markdown در ریپو)
if ($requested !== '' && isset($externalDocs[$requested])) {
    header('Location: ' . $externalDocs[$requested][2]);
    exit;
}

/* 📄 نمایش یک مستند */
if ($requested !== '' && isset($docsList[$requested])) {
    $docFile = dirname(__DIR__) . '/docs/' . $requested . '.html';
    if (file_exists($docFile)) {
        // 🛡️ نمایش امن محتوای HTML مستند (خودمان تولیدش کرده‌ایم)
        echo '<div class="card"><div class="card-header"><h3>' . e($docsList[$requested][0]) . '</h3>';
        echo '<div class="tools"><a href="docs.php" class="btn btn-outline btn-sm">→ بازگشت به فهرست</a></div></div>';
        echo '<div class="card-body" style="line-height:2.2">' . file_get_contents($docFile) . '</div></div>';
        require __DIR__ . '/includes/footer.php';
        exit;
    }
}
?>
<div class="card">
    <div class="card-header"><h3>📚 مستندات فارسی سایت ساز</h3></div>
    <div class="card-body">
        <?php if ($requested !== '' && !isset($docsList[$requested])): ?>
            <div class="alert alert-danger">⚠️ مستند درخواستی یافت نشد.</div>
        <?php endif; ?>
        <div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(300px,1fr))">
            <?php foreach ($docsList as $key => [$title, $desc]): ?>
                <?php $exists = file_exists(dirname(__DIR__) . '/docs/' . $key . '.html'); ?>
                <a class="stat-card" style="align-items:flex-start;<?= $exists ? '' : 'opacity:.55' ?>" href="<?= $exists ? 'docs.php?doc=' . e($key) : '#' ?>">
                    <div class="icon bg-blue"><?= mb_substr($title, 0, 2) ?></div>
                    <div>
                        <div style="font-weight:700;font-size:14px;margin-bottom:3px"><?= e($title) ?></div>
                        <div style="font-size:12px;color:var(--text-light);line-height:1.9"><?= e($desc) ?></div>
                        <span class="badge <?= $exists ? 'badge-success' : 'badge-secondary' ?>" style="margin-top:8px"><?= $exists ? '✅ آماده' : '⏳ در دست تولید' ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
            <?php foreach ($externalDocs as $key => [$title, $desc, $url]): ?>
                <a class="stat-card" style="align-items:flex-start" href="<?= e($url) ?>" target="_blank" rel="noopener">
                    <div class="icon bg-blue"><?= mb_substr($title, 0, 2) ?></div>
                    <div>
                        <div style="font-weight:700;font-size:14px;margin-bottom:3px"><?= e($title) ?> ↗</div>
                        <div style="font-size:12px;color:var(--text-light);line-height:1.9"><?= e($desc) ?></div>
                        <span class="badge badge-success" style="margin-top:8px">🌐 نسخه ۳ — ۳۴ اندپوینت</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="alert alert-warning" style="margin-top:8px">
            📌 سند مرجع کامل درخواست پروژه: <a href="<?= BASE_URL ?>/BrandMaker.md" target="_blank" style="color:inherit">BrandMaker.md</a>
            — چک‌لیست پیشرفت: <a href="https://github.com/pranses2011/Sahand-Service-BrandMaker/blob/main/CHECKLIST.md" target="_blank" style="color:inherit">CHECKLIST.md</a>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
