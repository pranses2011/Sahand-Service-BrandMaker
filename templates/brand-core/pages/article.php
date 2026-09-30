<?php
/**
 * 📄 صفحه تکی مقاله
 * @package SahandBrandSite
 */
define('BRAND_INIT', true);
require_once __DIR__ . '/../config.php';
/* 🚨 v2.26 — ریشه «صفحه مقاله باز می‌شود ولی خالی است»: render_article_seo در
   includes/seo.php تعریف شده بود اما هیچ فایلی آن را require نمی‌کرد → رندر
   بعد از مسیر راهنما فاتل می‌شد. بارگذاری مستقیم + بارگذاری در config.php. */
if (!function_exists('render_article_seo')) {
    require_once __DIR__ . '/../includes/seo.php';
}
$slug = (string)($_GET['slug'] ?? '');
/* 🔗 v2.34 — URL تمیز /blog/{slug}: آدرس قدیمی (?slug=) با ۳۰۱ به آدرس جدید
   هدایت می‌شود تا سئو یکپارچه شود (فقط یک شکل آدرس برای هر مقاله) */
if ($slug !== '' && str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), '/blog/article')) {
    http_response_code(301);
    header('Location: /blog/' . rawurlencode($slug), true, 301);
    exit;
}
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
render_article_seo($article, 'https://' . BRAND_DOMAIN . '/blog/' . urlencode($slug), (string)($GLOBALS['brandSeoName'] ?? ''));
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
            <?= article_image($article['featured_image'], $article['title'], true) ?>
        <?php endif; ?>
        <?php /* 🖼️ v2.44 (S02) — تصاویر درون‌متن: مسیر نسبی → مطلق سازنده +
                 ارتقای پروتکل در HTTPS (ضد بلاک محتوای ترکیبی) */ ?>
        <div class="article-content"><?= brand_fix_content_imgs((string)$article['content']) ?></div>
        <?php if (!empty($article['sources'])): ?>
            <!-- 🔎 v2.35: منابعِ آنلاینِ استفاده‌شده در این مقاله (سیگنال E-E-A-T) -->
            <div class="article-sources">
                <h2>منابع و مطالعه بیشتر</h2>
                <ul>
                    <?php foreach (array_slice((array)$article['sources'], 0, 6) as $src): ?>
                        <li>
                            <a href="<?= e($src['url']) ?>" target="_blank" rel="noopener nofollow"><?= e($src['title']) ?></a>
                            <?php if (!empty($src['host'])): ?><small> (<?= e($src['host']) ?>)</small><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if (!empty($article['related'])): ?>
            <div class="related-articles">
                <h2>مقالات مرتبط</h2>
                <div class="articles-grid">
                    <?php foreach ($article['related'] as $rel): ?>
                        <a href="/blog/<?= e(urlencode($rel['slug'])) ?>" class="article-card">
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

        <?php
        /* 💬 v2.37 — P3: دیدگاه مقالات (تأییدیه‌ای)
           فهرست + فرم — ارسال با fetch به اندپوینت comment سایت ساز.
           نمایش: دیدگاه‌های ریشه + پاسخ‌های برند زیر همان دیدگاه. */
        $commentsData = fetchFromAPI('brand/' . BRAND_ID . '/article-comments/' . urlencode($slug), 120);
        $comments = is_array($commentsData['data'] ?? null) ? $commentsData['data'] : [];
        $roots = array_values(array_filter($comments, static fn($c) => empty($c['parent_id'])));
        $byParent = [];
        foreach ($comments as $c) {
            if (!empty($c['parent_id'])) {
                $byParent[(int)$c['parent_id']][] = $c;
            }
        }
        $commentCount = count($roots);
        ?>
        <section class="article-comments" id="comments">
            <h2>💬 دیدگاه شما <span class="comments-count">(<?= e(fa_num((string)$commentCount)) ?>)</span></h2>

            <?php if ($commentCount > 0): ?>
            <div class="comments-list">
                <?php foreach ($roots as $c): ?>
                <article class="comment-item<?= !empty($c['is_brand_reply']) && empty($c['parent_id']) ? ' comment-brand' : '' ?>">
                    <header>
                        <span class="comment-author"><?= e($c['author']) ?></span>
                        <time datetime="<?= e($c['created_at']) ?>"><?= e(fa_num(date('Y/m/d', strtotime($c['created_at'])))) ?></time>
                    </header>
                    <p class="comment-body"><?= nl2br(e($c['body'])) ?></p>
                    <?php foreach ($byParent[(int)$c['id']] ?? [] as $reply): ?>
                    <div class="comment-reply<?= !empty($reply['is_brand_reply']) ? ' comment-brand' : '' ?>">
                        <header>
                            <span class="comment-author"><?= e($reply['author']) ?><?= !empty($reply['is_brand_reply']) ? ' <span class="comment-badge">پاسخ رسمی</span>' : '' ?></span>
                            <time datetime="<?= e($reply['created_at']) ?>"><?= e(fa_num(date('Y/m/d', strtotime($reply['created_at'])))) ?></time>
                        </header>
                        <p class="comment-body"><?= nl2br(e($reply['body'])) ?></p>
                    </div>
                    <?php endforeach; ?>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form class="comment-form" id="commentForm" novalidate>
                <h3>دیدگاه خود را بنویسید</h3>
                <div class="comment-form-row">
                    <input type="text" name="name" maxlength="120" placeholder="نام شما *" required>
                    <input type="email" name="email" maxlength="190" placeholder="ایمیل (اختیاری — منتشر نمی‌شود)">
                </div>
                <!-- 🍯 honeypot — برای کاربران پنهان؛ ربات‌ها پرش می‌کنند -->
                <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;right:-9999px;opacity:0" aria-hidden="true">
                <textarea name="body" rows="4" maxlength="3000" placeholder="متن دیدگاه شما *" required></textarea>
                <div class="comment-form-actions">
                    <button type="submit" class="btn btn-primary">ارسال دیدگاه</button>
                    <span class="comment-note">دیدگاه‌ها پس از بازبینی منتشر می‌شوند.</span>
                </div>
                <p class="comment-msg" id="commentMsg" role="status"></p>
            </form>
        </section>
    </div>
</article>
<script>
/* 📮 v2.37 — ارسال دیدگاه (fetch + JSON — همان الگوی فرم‌های قالب‌ساز) */
(function () {
    var form = document.getElementById('commentForm');
    if (!form) { return; }
    var msg = document.getElementById('commentMsg');
    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var btn = form.querySelector('button[type="submit"]');
        var payload = {
            api_key: <?= json_encode(BRAND_API_KEY) ?>,
            article_slug: <?= json_encode($slug) ?>,
            name: form.elements.name.value.trim(),
            email: form.elements.email.value.trim(),
            body: form.elements.body.value.trim(),
            website: form.elements.website.value /* honeypot */
        };
        msg.textContent = '';
        msg.className = 'comment-msg';
        if (payload.name.length < 2 || payload.body.length < 5) {
            msg.textContent = 'لطفاً نام و متن دیدگاه را کامل وارد کنید.';
            msg.classList.add('is-error');
            return;
        }
        if (btn) { btn.disabled = true; }
        fetch(<?= json_encode(rtrim(BRANDMAKER_API, '/') . '/brand/' . BRAND_ID . '/comment') ?>, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (j && j.success) {
                form.reset();
                msg.textContent = (j.data && j.data.message) ? j.data.message : 'دیدگاه شما ثبت شد و پس از بازبینی منتشر می‌شود.';
                msg.classList.add('is-ok');
            } else {
                msg.textContent = (j && j.error) ? j.error : 'خطا در ثبت دیدگاه — دوباره تلاش کنید.';
                msg.classList.add('is-error');
            }
        }).catch(function () {
            msg.textContent = 'خطای ارتباط با سرور — اتصال خود را بررسی کنید.';
            msg.classList.add('is-error');
        }).finally(function () {
            if (btn) { btn.disabled = false; }
        });
    });
})();
</script>
<?php require __DIR__ . '/../includes/floating-btn.php'; require __DIR__ . '/../includes/footer.php'; ?>
