<?php
/**
 * 📄 الگوی پایه صفحات داخلی سایت برند
 * =====================================
 * @package SahandBrandSite
 */
if (!defined('BRAND_INIT')) { http_response_code(403); exit; }
require __DIR__ . '/../includes/header.php';
?>
<!-- 🧭 مسیر راهنما -->
<nav class="breadcrumb" aria-label="مسیر">
    <div class="container">
        <a href="/">🏠 خانه</a><span>›</span><span><?= e($crumbTitle ?? 'صفحه') ?></span>
    </div>
</nav>
<!-- 🧩 v2.33 — Schema مسیر راهنما (گزارش تحلیل بخش ۸: رندر می‌شد ولی اسکیمایش نه) -->
<script type="application/ld+json"><?= json_encode([
    '@context'        => 'https://schema.org',
    '@type'           => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'خانه', 'item' => 'https://' . BRAND_DOMAIN . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => ($crumbTitle ?? 'صفحه'), 'item' => 'https://' . BRAND_DOMAIN . rtrim(e($_SERVER['REQUEST_URI'] ?? '/'), '/')],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
