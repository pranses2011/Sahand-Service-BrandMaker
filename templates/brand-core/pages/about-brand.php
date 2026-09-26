<?php
/**
 * ℹ️ صفحه درباره برند
 * @package SahandBrandSite
 */
define('BRAND_INIT', true);
require_once __DIR__ . '/../config.php';
$pageData = fetchFromAPI('brand/' . BRAND_ID . '/page/about-brand');
$content = $pageData['data']['content'] ?? [];
$pageTitle = $pageData['data']['seo']['title'] ?? ('درباره برند | ' . BRAND_NAME_FA);
$pageDesc = $pageData['data']['seo']['description'] ?? 'تاریخچه کامل برند — متن یکتای AI';
$crumbTitle = 'درباره برند';
/* 🎨 v2.27 — چیدمان تم/قالب سایت ساز (اگر باشد، جای ساختار ثابت می‌آید) */
$layoutHtml = '';
if (function_exists('bb_layout_html')) {
    $tplData = fetchFromAPI('brand/' . BRAND_ID . '/template/about-brand', 120)['data'] ?? null;
    $layoutHtml = $tplData ? bb_layout_html($tplData) : '';
}
if ($layoutHtml !== '') { $loadBlocksCss = true; }
require __DIR__ . '/_page_base.php';
?>
<?php if ($layoutHtml !== ''): ?>
<?= $layoutHtml ?>
<?php else: ?>
<section class="section">
    <div class="container prose-page">
        <h1 class="page-title">ℹ️ درباره برند</h1>
        <div class="prose-content">
            <?php if (!empty($content['content']) || !empty($content['intro'])): ?>
                <?php render_page_section($content, !empty($content['content']) ? 'content' : 'intro'); ?>
            <?php else: ?>
                <p>محتوای این صفحه به زودی تکمیل می‌شود. برای اطلاعات بیشتر با ما تماس بگیرید.</p>
            <?php endif; ?>
        </div>
        <div class="page-cta">
            <a href="/request" class="btn btn-primary">📝 ثبت درخواست خدمات</a>
            <a href="/contact" class="btn btn-outline">📞 تماس با ما</a>
        </div>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/floating-btn.php'; require __DIR__ . '/../includes/footer.php'; ?>
