<?php
/**
 * 📞 صفحه تماس با ما — اطلاعات + فرم + نقشه تصویری
 * @package SahandBrandSite
 */
define('BRAND_INIT', true);
require_once __DIR__ . '/../config.php';
$settings = fetchFromAPI('settings')['data'] ?? [];
$contacts = $settings['contacts'] ?? [];
$phones = $contacts['phones'] ?? [];
$emails = $settings['emails'] ?? [];
$addresses = $settings['addresses'] ?? [];
$socials = $contacts['socials'] ?? [];
$pageTitle = 'تماس با ما | ' . BRAND_NAME_FA;
$pageDesc = 'راه‌های ارتباطی با نمایندگی ' . BRAND_NAME_FA . ' — تلفن، موبایل، ایمیل و آدرس';
$crumbTitle = 'تماس با ما';
/* 🎨 v2.27 — چیدمان تم/قالب سایت ساز (اگر باشد، جای ساختار ثابت می‌آید) */
$layoutHtml = '';
if (function_exists('bb_layout_html')) {
    $tplData = fetchFromAPI('brand/' . BRAND_ID . '/template/contact', 120)['data'] ?? null;
    $layoutHtml = $tplData ? bb_layout_html($tplData) : '';
}
if ($layoutHtml !== '') { $loadBlocksCss = true; }
require __DIR__ . '/_page_base.php';
?>
<?php if ($layoutHtml !== ''): ?>
<?= $layoutHtml ?>
<?php else: ?>
<section class="section">
    <div class="container contact-page">
        <h1 class="page-title">📞 تماس با ما</h1>
        <div class="contact-grid">
            <!-- ☎️ اطلاعات تماس -->
            <div class="contact-info">
                <?php if (!empty($phones['phone'])): ?>
                    <h3>☎️ تلفن ثابت</h3>
                    <?php foreach ($phones['phone'] as $phone): ?>
                        <a href="tel:<?= e($phone) ?>" class="contact-item" dir="ltr"><?= e(fa_num($phone)) ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if (!empty($phones['mobile'])): ?>
                    <h3>📱 موبایل</h3>
                    <?php foreach ($phones['mobile'] as $mobile): ?>
                        <a href="tel:<?= e($mobile) ?>" class="contact-item" dir="ltr"><?= e(fa_num($mobile)) ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if (!empty($phones['whatsapp'])): ?>
                    <h3>💬 واتساپ</h3>
                    <?php foreach ($phones['whatsapp'] as $wa): ?>
                        <a href="https://wa.me/<?= e($wa) ?>" class="contact-item" target="_blank" rel="nofollow" dir="ltr"><?= e(fa_num($wa)) ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($emails): ?>
                    <h3>📧 ایمیل</h3>
                    <?php foreach (array_slice($emails, 0, 2) as $email): ?>
                        <a href="mailto:<?= e($email) ?>" class="contact-item" dir="ltr"><?= e($email) ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($socials): ?>
                    <h3>🌐 شبکه‌های اجتماعی</h3>
                    <div class="social-links">
                        <?php foreach ($socials as $social): ?>
                            <a href="<?= e($social['url']) ?>" target="_blank" rel="nofollow"><?= e($social['name']) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <!-- 📍 آدرس‌ها + نقشه تصویری -->
            <div class="contact-addresses">
                <?php foreach ($addresses as $addr): ?>
                    <div class="address-card">
                        <h3>📍 <?= e($addr['title'] ?: 'آدرس') ?></h3>
                        <p><?= e($addr['address']) ?></p>
                        <?php if (!empty($addr['postal_code'])): ?><p>📮 کد پستی: <span dir="ltr"><?= e(fa_num($addr['postal_code'])) ?></span></p><?php endif; ?>
                        <?php if (!empty($addr['map_url'])): ?>
                            <a href="<?= e($addr['map_url']) ?>" target="_blank" rel="nofollow" class="btn btn-outline btn-sm">🗺️ مشاهده روی نقشه</a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- 📝 فرم تماس ساده -->
        <div class="contact-form-box">
            <h2>پیام خود را ارسال کنید</h2>
            <p class="hint">برای ثبت درخواست تعمیر، از <a href="/request">فرم درخواست خدمات</a> استفاده کنید.</p>
        </div>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/floating-btn.php'; require __DIR__ . '/../includes/footer.php'; ?>
