<?php
/**
 * 📄 صفحه تکی مقاله
 * @package SahandBrandSite
 */
define('BRAND_INIT', true);
require_once __DIR__ . '/../config.php';
$slug = (string)($_GET['slug'] ?? '');
$articleData = fetchFromAPI('brand/' . BRAND_ID . '/article/' . urlencode($slug), 120);
$article = $articleData['data'] ?? null;
if (!$article) {
    http_response_code(404);
    $pageTitle = 'مقاله یافت نشد';
    $crumbTitle = '۴۰۴';
    require __DIR__ . '/_page_base.php';
    echo '<section class="section"><div class="container"><div class="empty-state"><div style="font-size:64px">🔍</div><h1>مقاله یافت نشد</h1><p><a href="/blog" class="btn btn-primary">بازگشت به مقالات</a></p></div></div></section>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}
$pageTitle = $article['seo']['title'] ?? $article['title'];
$pageDesc = $article['seo']['description'] ?? ($article['excerpt'] ?? '');
$crumbTitle = mb_substr($article['title'], 0, 30);
require __DIR__ . '/_page_base.php';
render_article_seo($article, 'https://' . BRAND_DOMAIN . '/blog/article?slug=' . urlencode($slug));
?>
<article class="section">
    <div class="container article-single">
        <h1 class="article-single-title"><?= e($article['title']) ?></h1>
        <div class="article-single-meta">
            <span>📅 <?= e(fa_num(date('Y/m/d', strtotime($article['published_at'])))) ?></span>
            <span>👁️ <?= e(fa_num((string)$article['views'])) ?> بازدید</span>
            <?php foreach (array_slice($article['tags'] ?? [], 0, 4) as $tag): ?>
                <span class="tag">#<?= e($tag) ?></span>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($article['featured_image'])): ?>
            <?= article_image($article['featured_image'], $article['title']) ?>
        <?php endif; ?>
        <div class="article-content"><?= $article['content'] ?></div>
        <?php if (!empty($article['related'])): ?>
            <div class="related-articles">
                <h2>مقالات مرتبط</h2>
                <div class="articles-grid">
                    <?php foreach ($article['related'] as $rel): ?>
                        <a href="/blog/article?slug=<?= e(urlencode($rel['slug'])) ?>" class="article-card">
                            <div class="article-card-body"><h3><?= e($rel['title']) ?></h3></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="article-cta">
            <p>دستگاه <?= e(BRAND_NAME_FA) ?> شما ایراد دارد؟</p>
            <a href="/request" class="btn btn-primary btn-lg">📝 ثبت درخواست تعمیر</a>
        </div>
    </div>
</article>
<?php require __DIR__ . '/../includes/floating-btn.php'; require __DIR__ . '/../includes/footer.php'; ?>
