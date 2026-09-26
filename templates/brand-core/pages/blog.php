<?php
/**
 * 📰 صفحه مقالات — لیست با صفحه‌بندی
 * @package SahandBrandSite
 */
define('BRAND_INIT', true);
require_once __DIR__ . '/../config.php';
$pageNum = max(1, (int)($_GET['page'] ?? 1));
$articlesData = fetchFromAPI('brand/' . BRAND_ID . '/articles?page=' . $pageNum . '&per_page=9', 120);
$articles = $articlesData['data'] ?? [];
$meta = $articlesData['meta'] ?? ['pages' => 1, 'page' => 1];
$pageTitle = 'مقالات و راهنما | ' . BRAND_NAME_FA;
$pageDesc = 'مقالات آموزشی، راهنمای نگهداری و عیب‌یابی دستگاه‌های ' . BRAND_NAME_FA;
$crumbTitle = 'مقالات';
/* 🎨 v2.27 — چیدمان تم/قالب سایت ساز (اگر باشد، جای ساختار ثابت می‌آید) */
$layoutHtml = '';
if (function_exists('bb_layout_html')) {
    $tplData = fetchFromAPI('brand/' . BRAND_ID . '/template/blog', 120)['data'] ?? null;
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
        <h1 class="page-title">مقالات و راهنما</h1>
        <?php if (empty($articles)): ?>
            <div class="empty-state"><p>هنوز مقاله‌ای منتشر نشده است.</p></div>
        <?php else: ?>
        <div class="articles-grid">
            <?php foreach ($articles as $article): ?>
                <a href="/blog/article?slug=<?= e(urlencode($article['slug'])) ?>" class="article-card">
                    <?= article_image($article['featured_image'] ?? null, $article['title']) ?>
                    <div class="article-card-body">
                        <h2><?= e($article['title']) ?></h2>
                        <p><?= e(mb_substr(strip_tags((string)$article['excerpt']), 0, 120)) ?>…</p>
                        <div class="article-meta">
                            <span>📅 <?= e(fa_num(date('Y/m/d', strtotime($article['published_at'])))) ?></span>
                            <span>👁️ <?= e(fa_num((string)$article['views'])) ?></span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if (($meta['pages'] ?? 1) > 1): ?>
        <nav class="pagination" aria-label="صفحه‌بندی">
            <?php for ($p = 1; $p <= min(10, (int)$meta['pages']); $p++): ?>
                <a href="?page=<?= $p ?>" class="<?= $p === (int)$meta['page'] ? 'current' : '' ?>"><?= e(fa_num((string)$p)) ?></a>
            <?php endfor; ?>
        </nav>
<?php endif; ?>
<?php endif; ?>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/floating-btn.php'; require __DIR__ . '/../includes/footer.php'; ?>
