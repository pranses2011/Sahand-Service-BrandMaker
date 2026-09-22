<?php
/**
 * 🧰 توابع کمکی مشترک سایت برند
 * ================================
 *
 * @package SahandBrandSite
 */
if (!defined('BRAND_INIT')) { http_response_code(403); exit; }

/**
 * 📄 رندر بخش محتوای صفحه برند از داده API
 */
function render_page_section(array $content, string $field = 'content'): void
{
    $html = $content[$field] ?? '';
    // پاراگراف‌بندی متن ساده
    if ($html !== '' && strpos($html, '<') === false) {
        $html = '<p>' . nl2br(e($html)) . '</p>';
    }
    echo $html;
}

/**
 * 🖼️ تصویر شاخص مقاله با placeholder
 */
function article_image(?string $image, string $alt): string
{
    $src = $image ?: cdn_asset('images/placeholders/article.svg');
    return '<img src="' . e($src) . '" alt="' . e($alt) . '" loading="lazy" class="article-image">';
}

/**
 * 🔗 ساخت URL کامل صفحه برند
 */
function page_url(string $slug): string
{
    return '/' . ltrim($slug, '/');
}
