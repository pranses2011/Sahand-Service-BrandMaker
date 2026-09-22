<?php
/**
 * 🗺️ نقشه سایت HTML — فهرست تمام صفحات
 * @package SahandBrandSite
 */
define('BRAND_INIT', true);
require_once __DIR__ . '/../config.php';
$articles = fetchFromAPI('brand/' . BRAND_ID . '/articles?per_page=50', 300)['data'] ?? [];
$pageTitle = 'نقشه سایت | ' . BRAND_NAME_FA;
$pageDesc = 'فهرست تمام صفحات سایت ' . BRAND_NAME_FA;
$crumbTitle = 'نقشه سایت';
require __DIR__ . '/_page_base.php';
?>
<section class="section">
    <div class="container sitemap-page">
        <h1 class="page-title">🗺️ نقشه سایت</h1>
        <div class="sitemap-grid">
            <div><h3>صفحات اصلی</h3><ul class="sitemap-list">
                <li><a href="/">صفحه اصلی</a></li><li><a href="/services">خدمات</a></li>
                <li><a href="/warranty">ضمانت</a></li><li><a href="/service-area">محدوده خدمات</a></li>
                <li><a href="/about-agency">درباره نمایندگی</a></li><li><a href="/about-brand">درباره برند</a></li>
            </ul></div>
            <div><h3>خدمات مشتریان</h3><ul class="sitemap-list">
                <li><a href="/request">ثبت درخواست خدمات</a></li><li><a href="/contact">تماس با ما</a></li>
                <li><a href="/faq">سوالات متداول</a></li><li><a href="/error-codes">کدهای خطا</a></li>
                <li><a href="/other-brands">سایر برندها</a></li>
            </ul></div>
            <div><h3>مقالات (<?= e(fa_num((string)count($articles))) ?>)</h3><ul class="sitemap-list">
                <?php foreach (array_slice($articles, 0, 20) as $article): ?>
                    <li><a href="/blog/article?slug=<?= e(urlencode($article['slug'])) ?>"><?= e($article['title']) ?></a></li>
                <?php endforeach; ?>
            </ul></div>
            <div><h3>قوانین</h3><ul class="sitemap-list">
                <li><a href="/terms">قوانین و مقررات</a></li><li><a href="/privacy">حریم خصوصی</a></li>
            </ul></div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/../includes/floating-btn.php'; require __DIR__ . '/../includes/footer.php'; ?>
