<?php
/**
 * 🔻 فوتر مشترک سایت برند
 * =========================
 * اطلاعات تماس + لینک به سایت اصلی + سایر برندها (الزام سند)
 *
 * @package SahandBrandSite
 */
if (!defined('BRAND_INIT')) { http_response_code(403); exit; }

// 📥 اطلاعات تماس و سایر برندها
$settingsData = fetchFromAPI('settings')['data'] ?? [];
$contacts = $settingsData['contacts'] ?? [];
$otherBrands = fetchFromAPI('brands')['data'] ?? [];
$phones = $contacts['phones'] ?? [];
$mobiles = $phones['mobile'] ?? [];
$landlines = $phones['phone'] ?? [];
$socials = $contacts['socials'] ?? [];
?>
</main>

<!-- 🔻 فوتر سایت -->
<footer class="site-footer">
    <div class="container">
        <div class="footer-grid">
            <!-- 📇 درباره برند -->
            <div class="footer-col">
                <h4><?= e($brand['name_fa'] ?? '') ?> (<?= e($brand['name_en'] ?? '') ?>)</h4>
                <p class="footer-desc"><?= e($pageDesc ?: 'خدمات تعمیرات تخصصی با قطعات اصلی و ضمانت کتبی') ?></p>
                <?php if (!empty($agency['logo'])): ?>
                    <a href="<?= e($agency['main_site'] ?? '#') ?>" target="_blank" rel="nofollow">
                        <img src="<?= e($agency['logo']) ?>" alt="<?= e($agency['name_fa'] ?? '') ?>" class="footer-agency-logo">
                    </a>
                <?php endif; ?>
            </div>

            <!-- 📞 تماس -->
            <div class="footer-col">
                <h4>تماس با ما</h4>
                <?php if ($landlines): ?>
                    <?php foreach (array_slice($landlines, 0, 2) as $phone): ?>
                        <a href="tel:<?= e($phone) ?>" class="footer-contact">☎️ <span dir="ltr"><?= e(fa_num($phone)) ?></span></a>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php foreach (array_slice($mobiles, 0, 2) as $mobile): ?>
                    <a href="tel:<?= e($mobile) ?>" class="footer-contact">📱 <span dir="ltr"><?= e(fa_num($mobile)) ?></span></a>
                <?php endforeach; ?>
            </div>

            <!-- 🔗 لینک‌های داخلی -->
            <div class="footer-col">
                <h4>دسترسی سریع</h4>
                <a href="/services" class="footer-link">خدمات</a>
                <a href="/warranty" class="footer-link">ضمانت</a>
                <a href="/blog" class="footer-link">مقالات</a>
                <a href="/request" class="footer-link">ثبت درخواست</a>
                <a href="/contact" class="footer-link">تماس با ما</a>
            </div>

            <!-- 🏷️ سایر برندها (الزام سند) -->
            <div class="footer-col">
                <h4>سایر برندهای مورد خدمت</h4>
                <?php foreach (array_slice($otherBrands, 0, 6) as $ob): ?>
                    <?php if (($ob['id'] ?? 0) != BRAND_ID): ?>
                        <a href="https://<?= e($ob['domain']) ?>" class="footer-link" <?= !empty($settingsData['linking']['default_nofollow']) ? 'rel="nofollow"' : '' ?>><?= e($ob['name_fa']) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <a href="/other-brands" class="footer-link" style="font-weight:700">همه برندها ←</a>
            </div>
        </div>

        <!-- 🌐 شبکه‌های اجتماعی -->
        <?php if ($socials): ?>
            <div class="footer-socials">
                <?php foreach ($socials as $social): ?>
                    <a href="<?= e($social['url']) ?>" target="_blank" rel="nofollow" title="<?= e($social['name']) ?>"><?= e($social['name']) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="footer-bottom">
            <span>© <?= fa_num(date('Y')) ?> <?= e($brand['name_fa'] ?? '') ?> — کلیه حقوق محفوظ است</span>
            <a href="<?= e($agency['main_site'] ?? '#') ?>" target="_blank" rel="nofollow" class="footer-main-site">
                <?= e($agency['name_fa'] ?? 'سایت اصلی نمایندگی') ?>
            </a>
        </div>
    </div>
</footer>

<!-- 🧭 ردیاب بازدید (آمار داخلی بدون سرویس خارجی) -->
<!-- 🚨 v2.26 — ریشه قطعی «آمار و گزارش چیزی نشان نمی‌دهد»: tracker.js به
     TRACKER_URL و BRAND_ID به‌عنوان «متغیر جاوااسکریپت» اشاره می‌کرد اما این‌ها
     فقط ثابت PHP بودند و هرگز به مرورگر تزریق نشده بودند → ReferenceError در
     همان خط اول → هیچ بازدیدی ثبت نمی‌شد و همه گزارش‌ها خالی می‌ماندند.
     ✅ اکنون مقادیر واقعی (PHP constants) پیش از بارگذاری tracker.js تزریق می‌شوند.
     🛡 دفاع دومگانه: کانفیگ‌های قدیمی TRACKER_URL را «بدون /track» (ریشه API)
     تعریف کرده بودند → مسیر صحیح از BRANDMAKER_API بازسازی می‌شود. -->
<?php
$trackerTarget = defined('TRACKER_URL') ? trim((string)TRACKER_URL) : '';
if ($trackerTarget === '' || !preg_match('#/track$#', $trackerTarget)) {
    $trackerTarget = rtrim(BRANDMAKER_API, '/') . '/track';
}
?>
<script>
    window.TRACKER_URL = <?= json_encode($trackerTarget) ?>;
    window.BRAND_ID = <?= (int)BRAND_ID ?>;
</script>
<script src="/js/tracker.js<?= defined('VERSION') ? '?v=' . rawurlencode(VERSION) : '' ?>"></script>
<!-- ⚡ اسکریپت اصلی -->
<script src="/js/app.js"></script>
</body>
</html>
