<?php
/**
 * ❓ سوالات متداول — با آکاردئون و Schema FAQPage
 * @package SahandBrandSite
 */
define('BRAND_INIT', true);
require_once __DIR__ . '/../config.php';
$faqs = fetchFromAPI('brand/' . BRAND_ID . '/faqs')['data'] ?? [];
$pageTitle = 'سوالات متداول | ' . BRAND_NAME_FA;
$pageDesc = 'پاسخ پرتکرارترین سوالات درباره خدمات تعمیر ' . BRAND_NAME_FA;
$crumbTitle = 'سوالات متداول';
require __DIR__ . '/_page_base.php';
// 🧩 Schema FAQPage برای ریچ‌ریزالت
if ($faqs) {
    echo '<script type="application/ld+json">' . json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(function ($f) {
            return ['@type' => 'Question', 'name' => $f['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags($f['answer'])]];
        }, $faqs),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
}
?>
<section class="section">
    <div class="container faq-page">
        <h1 class="page-title">❓ سوالات متداول</h1>
        <?php if (empty($faqs)): ?>
            <p class="page-intro">سوالی ثبت نشده است. سوال خود را از طریق <a href="/contact">تماس با ما</a> بپرسید.</p>
        <?php else: ?>
            <div class="faq-accordion">
                <?php foreach ($faqs as $i => $faq): ?>
                    <details class="faq-item" <?= $i === 0 ? 'open' : '' ?>>
                        <summary><?= e($faq['question']) ?></summary>
                        <div class="faq-answer"><?= $faq['answer'] ?></div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/../includes/floating-btn.php'; require __DIR__ . '/../includes/footer.php'; ?>
