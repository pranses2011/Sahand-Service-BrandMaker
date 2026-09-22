<?php
/**
 * 📍 صفحه محدوده خدمات
 * @package SahandBrandSite
 */
define('BRAND_INIT', true);
require_once __DIR__ . '/../config.php';
$pageData = fetchFromAPI('brand/' . BRAND_ID . '/page/service-area');
$content = $pageData['data']['content'] ?? [];
$pageTitle = $pageData['data']['seo']['title'] ?? ('محدوده خدمات | ' . BRAND_NAME_FA);
$pageDesc = $pageData['data']['seo']['description'] ?? 'مناطق تحت پوشش خدمات‌دهی';
$crumbTitle = 'محدوده خدمات';
require __DIR__ . '/_page_base.php';
?>
<section class="section">
    <div class="container prose-page">
        <h1 class="page-title">📍 محدوده خدمات</h1>
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
<?php require __DIR__ . '/../includes/floating-btn.php'; require __DIR__ . '/../includes/footer.php'; ?>
