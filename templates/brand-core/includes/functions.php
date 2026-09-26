<?php
/**
 * 🧰 توابع کمکی مشترک سایت برند
 * ================================
 * 🆕 v2.24: این فایل حالا واقعاً بارگذاری می‌شود! قبلاً هیچ فایلی آن را
 * require نمی‌کرد و صفحاتی که render_page_section / article_image را صدا
 * می‌زدند با «Call to undefined function» فاتل می‌شدند (ریشه «صفحات خالی»).
 * نقطه بارگذاری: config.php (قالب و تولیدی ConfigGenerator).
 *
 * @package SahandBrandSite
 */
if (!defined('BRAND_INIT')) { http_response_code(403); exit; }

/** 🛡️ گارد تعریف — ضد «Cannot redeclare function» اگر بارگذاری دوباره شد */
if (!function_exists('render_page_section')) {
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
}

if (!function_exists('article_image')) {
    /**
     * 🖼️ تصویر شاخص مقاله با placeholder
     */
    function article_image(?string $image, string $alt): string
    {
        $src = $image ?: cdn_asset('images/placeholders/article.svg');
        return '<img src="' . e($src) . '" alt="' . e($alt) . '" loading="lazy" class="article-image">';
    }
}

if (!function_exists('page_url')) {
    /**
     * 🔗 ساخت URL کامل صفحه برند
     */
    function page_url(string $slug): string
    {
        return '/' . ltrim($slug, '/');
    }
}
