<?php
/**
 * 🚫 صفحه خطای ۴۰۴ — طراحی زیبا و فارسی (الزام سند)
 * @package SahandBrandSite
 */
define('BRAND_INIT', true);
require_once __DIR__ . '/config.php';
http_response_code(404);
$pageTitle = 'صفحه یافت نشد | ' . BRAND_NAME_FA;
$pageDesc = 'صفحه مورد نظر یافت نشد';
require __DIR__ . '/includes/header.php';
?>
<section class="notfound-page">
    <div class="container">
        <div class="notfound-code">۴۰۴</div>
        <h1>اوه! صفحه مورد نظر پیدا نشد 😕</h1>
        <p style="color:var(--color-text-light);margin:14px 0 26px">متأسفیم، صفحه‌ای که به دنبال آن هستید وجود ندارد یا به آدرس دیگری منتقل شده است.</p>
        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
            <a href="/" class="btn btn-primary">🏠 بازگشت به صفحه اصلی</a>
            <a href="/services" class="btn btn-outline">🔧 مشاهده خدمات</a>
            <a href="/request" class="btn btn-outline">📝 ثبت درخواست</a>
        </div>
    </div>
</section>
<?php require __DIR__ . '/includes/floating-btn.php'; require __DIR__ . '/includes/footer.php'; ?>
