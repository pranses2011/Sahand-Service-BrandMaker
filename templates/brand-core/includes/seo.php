<?php
/**
 * 🔍 تولید تگ‌های سئو اضافی برای صفحات خاص
 * (بخش head توسط header.php مدیریت می‌شود — این فایل برای متادیتای داینامیک مقالات)
 *
 * 🚨 v2.26 — ریشه قطعی «صفحه مقاله باز می‌شود ولی خالی است»:
 * این فایل در هیچ فایلی require نمی‌شد (همان الگوی باگ v2.24 توابع
 * functions.php) → article.php بعد از رندر هدر و مسیر راهنما، در خط
 * render_article_seo() با «Call to undefined function» فاتل می‌شد و بدنه
 * مقاله هرگز رندر نمی‌شد. ✅ اکنون در config.php (قالب + ConfigGenerator)
 * و مستقیماً در article.php بارگذاری می‌شود.
 *
 * @package SahandBrandSite
 */
if (!defined('BRAND_INIT')) { http_response_code(403); exit; }

/** 🛡 گارد تعریف — ضد «Cannot redeclare function» اگر بارگذاری دوباره شد */
if (!function_exists('render_article_seo')) {
    /**
     * 🏷️ رندر متا تگ‌های سئوی مقاله
     */
    function render_article_seo(array $article, string $canonical): void
    {
        $seo = $article['seo'] ?? [];
        echo '<link rel="canonical" href="' . e($canonical) . '">' . "\n";
        echo '<meta property="og:type" content="article">' . "\n";
        echo '<meta property="article:published_time" content="' . e($article['published_at'] ?? '') . '">' . "\n";
        if (!empty($seo['title'])) {
            echo '<meta property="og:title" content="' . e($seo['title']) . '">' . "\n";
        }
        /* 🖼️ v2.26 — og:image باید URL مطلق باشد؛ قبلاً مسیر نسبی
           (uploads/articles/...) در می‌آمد که هم شبکه‌های اجتماعی و هم
           کراولرها نمی‌فهمیدند → اکنون با cdn_asset به دامنه سایت ساز رفع می‌شود */
        if (!empty($article['featured_image'])) {
            echo '<meta property="og:image" content="' . e(cdn_asset((string)$article['featured_image'])) . '">' . "\n";
        }
        // 🧩 Schema مقاله
        echo '<script type="application/ld+json">' . json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $article['title'] ?? '',
            'description' => $seo['description'] ?? ($article['excerpt'] ?? ''),
            'datePublished' => $article['published_at'] ?? '',
            'inLanguage' => 'fa-IR',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }
}
