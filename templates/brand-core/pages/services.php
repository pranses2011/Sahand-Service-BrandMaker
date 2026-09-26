<?php
/**
 * 🔧 صفحه خدمات — لیست دستگاه‌های برند
 * @package SahandBrandSite
 */
define('BRAND_INIT', true);
require_once __DIR__ . '/../config.php';
$pageData = fetchFromAPI('brand/' . BRAND_ID . '/page/services');
$content = $pageData['data']['content'] ?? [];
$devices = fetchFromAPI('brand/' . BRAND_ID . '/devices')['data'] ?? [];
$pageTitle = $pageData['data']['seo']['title'] ?? ('خدمات ' . BRAND_NAME_FA);
$pageDesc = $pageData['data']['seo']['description'] ?? '';
$crumbTitle = 'خدمات';
/* 🎨 v2.27 — چیدمان تم/قالب سایت ساز (اگر باشد، جای ساختار ثابت می‌آید) */
$layoutHtml = '';
if (function_exists('bb_layout_html')) {
    $tplData = fetchFromAPI('brand/' . BRAND_ID . '/template/services', 120)['data'] ?? null;
    $layoutHtml = $tplData ? bb_layout_html($tplData) : '';
}
if ($layoutHtml !== '') { $loadBlocksCss = true; }
require __DIR__ . '/_page_base.php';
?>
<?php if ($layoutHtml !== ''): ?>
<?= $layoutHtml ?>
<?php else: ?>
<section class="section">
    <div class="container">
        <h1 class="page-title">خدمات <?= e(BRAND_NAME_FA) ?></h1>
        <?php if (!empty($content['intro'])): ?>
            <div class="page-intro"><?php render_page_section($content, 'intro'); ?></div>
        <?php endif; ?>
        <div class="services-grid">
            <?php foreach ($devices as $device): ?>
                <div class="service-card service-card-full">
                    <div class="service-card-head">
                        <h2><?= e($device['name_fa']) ?></h2>
                    </div>
                    <div class="service-card-body">
                        <?php if (!empty($device['description'])): ?>
                            <p><?= e(mb_substr(strip_tags($device['description']), 0, 220)) ?>…</p>
                        <?php else: ?>
                            <p>تعمیرات تخصصی <?= e($device['name_fa']) ?> <?= e(BRAND_NAME_FA) ?> با قطعات اصلی و ضمانت</p>
                        <?php endif; ?>
                    </div>
                    <div class="service-card-foot">
                        <span>✅ ایرادیابی</span><span>✅ تعویض قطعات</span><span>✅ سرویس دوره‌ای</span>
                        <a href="/request" class="btn btn-primary btn-sm">ثبت درخواست</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/floating-btn.php'; require __DIR__ . '/../includes/footer.php'; ?>
