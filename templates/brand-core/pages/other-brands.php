<?php
/**
 * 🏷️ سایر برندهای مورد خدمت — لینک به سایت هر برند + سایت اصلی
 * @package SahandBrandSite
 */
define('BRAND_INIT', true);
require_once __DIR__ . '/../config.php';
$settings = fetchFromAPI('settings')['data'] ?? [];
$brands = fetchFromAPI('brands')['data'] ?? [];
$pageTitle = 'سایر برندهای مورد خدمت | ' . BRAND_NAME_FA;
$pageDesc = 'فهرست تمام برندهایی که نمایندگی خدمات‌دهی می‌کند';
$crumbTitle = 'سایر برندها';
require __DIR__ . '/_page_base.php';
?>
<section class="section">
    <div class="container">
        <h1 class="page-title">🏷️ سایر برندهای مورد خدمت</h1>
        <p class="page-intro">نمایندگی <?= e($settings['agency']['name_fa'] ?? '') ?> علاوه بر <?= e(BRAND_NAME_FA) ?>، خدمات تعمیرات برندهای زیر را نیز ارائه می‌دهد.</p>
        <div class="brands-grid">
            <?php foreach ($brands as $b): ?>
                <?php if ((int)$b['id'] === (int)BRAND_ID) { continue; } ?>
                <a href="https://<?= e($b['domain']) ?>" class="brand-card" <?= !empty($settings['linking']['default_nofollow']) ? 'rel="nofollow"' : '' ?>>
                    <?php if (!empty($b['logo'])): ?>
                        <img src="<?= e($b['logo']) ?>" alt="<?= e($b['name_fa']) ?>" loading="lazy">
                    <?php else: ?>
                        <span class="brand-card-fallback"><?= e(mb_substr($b['name_fa'], 0, 1)) ?></span>
                    <?php endif; ?>
                    <span class="brand-card-name"><?= e($b['name_fa']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="main-site-cta">
            <p>برای مشاهده همه خدمات نمایندگی:</p>
            <a href="<?= e($settings['agency']['main_site'] ?? '#') ?>" class="btn btn-primary btn-lg" target="_blank" rel="nofollow">🌐 سایت اصلی نمایندگی</a>
        </div>
    </div>
</section>
<?php require __DIR__ . '/../includes/floating-btn.php'; require __DIR__ . '/../includes/footer.php'; ?>
