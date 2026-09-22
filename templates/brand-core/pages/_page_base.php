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
