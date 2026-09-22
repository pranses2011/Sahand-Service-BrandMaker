<?php
/**
 * 🔍 تولید تگ‌های سئو اضافی برای صفحات خاص
 * (بخش head توسط header.php مدیریت می‌شود — این فایل برای متادیتای داینامیک مقالات)
 *
 * @package SahandBrandSite
 */
if (!defined('BRAND_INIT')) { http_response_code(403); exit; }

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
    if (!empty($article['featured_image'])) {
        echo '<meta property="og:image" content="' . e($article['featured_image']) . '">' . "\n";
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
