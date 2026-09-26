<?php
/**
 * 🏠 صفحه اصلی سایت برند
 * ========================
 * بخش‌ها: هیرو، معرفی برند، خدمات برجسته، چرا ما،
 * مقالات اخیر، نظرات، لینک برندها — مطابق بلوک‌های قالب
 *
 * @package SahandBrandSite
 * @version 1.0.0
 */

define('BRAND_INIT', true);
require_once __DIR__ . '/config.php';

// 📥 داده‌های مورد نیاز صفحه اصلی
$pageData = fetchFromAPI('brand/' . BRAND_ID . '/page/home');
$content = $pageData['data']['content'] ?? [];
$devicesData = fetchFromAPI('brand/' . BRAND_ID . '/devices')['data'] ?? [];
$articlesData = fetchFromAPI('brand/' . BRAND_ID . '/articles?per_page=4');
$articles = $articlesData['data'] ?? [];

// 📞 اطلاعات تماس برای بخش CTA
$settingsData = fetchFromAPI('settings')['data'] ?? [];
$contactsInfo = $settingsData['contacts']['phones'] ?? [];
$mobiles = $contactsInfo['mobile'] ?? $contactsInfo['phone'] ?? [];

// 🏷️ سئو صفحه
$pageTitle = $pageData['data']['seo']['title'] ?? (BRAND_NAME_FA . ' | تعمیرات تخصصی');
$pageDesc = $pageData['data']['seo']['description'] ?? '';
$pageKey = $pageData['data']['seo']['keywords'] ?? '';

/* 🎨 v2.27 — چیدمان تم/قالب سایت ساز (ریشه «تغییر تم روی سایت برند اعمال
   نمی‌شود»): اگر برای این صفحه تم/قالب/چیدمانی تعیین شده باشد، همان
   بلوک‌ها رندر می‌شوند؛ در غیر این صورت ساختار ثابت پیش‌فرض زیر می‌آید. */
$layoutHtml = '';
if (function_exists('bb_layout_html')) {
    $tplData = fetchFromAPI('brand/' . BRAND_ID . '/template/home', 120)['data'] ?? null;
    $layoutHtml = $tplData ? bb_layout_html($tplData) : '';
}
if ($layoutHtml !== '') { $loadBlocksCss = true; }

require __DIR__ . '/includes/header.php';
?>
<?php if ($layoutHtml !== ''): ?>
<?= $layoutHtml ?>
<?php else: ?>

<!-- 🦸 بخش هیرو -->
<section class="hero-section">
    <div class="container hero-inner">
        <h1 class="hero-title">تعمیرات تخصصی <?= e(BRAND_NAME_FA) ?></h1>
        <p class="hero-desc">نمایندگی رسمی خدمات پس از فروش — با قطعات اصلی، تکنسین‌های متخصص و ضمانت کتبی</p>
        <div class="hero-actions">
            <a href="/request" class="btn btn-primary btn-lg">📝 ثبت درخواست خدمات آنلاین</a>
            <a href="/services" class="btn btn-outline-light btn-lg">🔧 مشاهده خدمات</a>
        </div>
        <div class="hero-badges">
            <span class="badge-item">✅ ضمانت کتبی</span>
            <span class="badge-item">⚡ اعزام سریع</span>
            <span class="badge-item">🔧 قطعات اصلی</span>
        </div>
    </div>
</section>

<!-- 📋 معرفی کوتاه برند (تولید AI) -->
<?php if (!empty($content['intro'])): ?>
<section class="section section-intro">
    <div class="container">
        <div class="intro-grid">
            <div>
                <h2 class="section-title">درباره <?= e(BRAND_NAME_FA) ?></h2>
                <div class="intro-text"><?php render_page_section($content, 'intro'); ?></div>
            </div>
            <div class="intro-side">
                <div class="intro-card">
                    <span class="intro-card-icon">🛡️</span>
                    <h3>ضمانت کتبی خدمات</h3>
                    <p>تمام تعمیرات با ضمانت‌نامه معتبر ارائه می‌شود</p>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- 🔧 خدمات برجسته -->
<section class="section section-services">
    <div class="container">
        <h2 class="section-title">خدمات برجسته</h2>
        <p class="section-desc">تعمیرات تخصصی تمام دستگاه‌های <?= e(BRAND_NAME_FA) ?></p>
        <div class="services-grid">
            <?php $icons = ['refrigerator' => 'refrigerator', 'washing_machine' => 'washing-machine', 'dishwasher' => 'dishwasher', 'air_conditioner' => 'air-conditioner', 'microwave' => 'microwave', 'tv' => 'tv']; ?>
            <?php foreach (array_slice($devicesData, 0, 6) as $device): ?>
                <a href="/services" class="service-card">
                    <img src="<?= e(cdn_asset('icons/home-appliance-icons/' . ($icons[$device['device_key']] ?? $device['device_key']) . '.svg')) ?>" alt="<?= e($device['name_fa']) ?>" class="service-icon" loading="lazy" onerror="this.style.display='none'">
                    <h3><?= e($device['name_fa']) ?></h3>
                    <p><?= e(mb_substr(strip_tags((string)$device['description']), 0, 80)) ?>…</p>
                    <span class="service-link">مشاهده جزئیات ←</span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ⭐ چرا ما؟ -->
<section class="section section-why">
    <div class="container">
        <h2 class="section-title">چرا <?= e($agency['name_fa'] ?? 'ما') ?>؟</h2>
        <div class="features-grid">
            <div class="feature-item"><span class="feature-icon">🎓</span><h3>تکنسین‌های متخصص</h3><p>آموزش‌دیده و مجرب در تعمیرات <?= e(BRAND_NAME_FA) ?></p></div>
            <div class="feature-item"><span class="feature-icon">🔩</span><h3>قطعات اصلی</h3><p>استفاده صرفاً از قطعات فابریک و اورجینال</p></div>
            <div class="feature-item"><span class="feature-icon">🚀</span><h3>اعزام سریع</h3><p>ارسال تکنسین به محل در اولین فرصت ممکن</p></div>
            <div class="feature-item"><span class="feature-icon">🛡️</span><h3>ضمانت کتبی</h3><p>تمام تعمیرات دارای ضمانت‌نامه معتبر</p></div>
            <div class="feature-item"><span class="feature-icon">💰</span><h3>هزینه شفاف</h3><p>اعلام هزینه پس از ایرادیابی و قبل از تعمیر</p></div>
            <div class="feature-item"><span class="feature-icon">🕐</span><h3>پشتیبانی</h3><p>پاسخگویی در ساعات کاری و ثبت درخواست ۲۴ ساعته</p></div>
        </div>
    </div>
</section>

<!-- 📞 CTA تماس -->
<section class="section section-cta">
    <div class="container cta-box">
        <h2>نیاز به تعمیرکار <?= e(BRAND_NAME_FA) ?> دارید؟</h2>
        <p>همین حالا درخواست خود را ثبت کنید — ایرادیابی و اعزام تکنسین در اولین فرصت</p>
        <div class="cta-actions">
            <a href="/request" class="btn btn-light btn-lg">📝 ثبت درخواست آنلاین</a>
            <?php if (!empty($mobiles[0])): ?>
                <a href="tel:<?= e(str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], ['0','1','2','3','4','5','6','7','8','9'], $mobiles[0])) ?>" class="cta-phone">📞 <span dir="ltr"><?= e(fa_num($mobiles[0])) ?></span></a>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- 📰 مقالات اخیر -->
<?php if (!empty($articles)): ?>
<section class="section section-articles">
    <div class="container">
        <h2 class="section-title">آخرین مقالات</h2>
        <div class="articles-grid">
            <?php foreach ($articles as $article): ?>
                <a href="/blog/article?slug=<?= e(urlencode($article['slug'])) ?>" class="article-card">
                    <?php if (!empty($article['featured_image'])): ?>
                        <?= article_image($article['featured_image'], $article['title']) ?>
                    <?php endif; ?>
                    <div class="article-card-body">
                        <h3><?= e($article['title']) ?></h3>
                        <p><?= e(mb_substr(strip_tags((string)$article['excerpt']), 0, 100)) ?>…</p>
                        <span class="article-date"><?= e(fa_num(date('Y/m/d', strtotime($article['published_at'])))) ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <div style="text-align:center;margin-top:24px"><a href="/blog" class="btn btn-outline">همه مقالات ←</a></div>
    </div>
</section>
<?php endif; ?>

<?php endif; ?>

<?php
require __DIR__ . '/includes/floating-btn.php';
require __DIR__ . '/includes/footer.php';
