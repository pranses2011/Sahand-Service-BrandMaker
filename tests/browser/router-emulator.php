<?php
/**
 * 🧪 روتر شبیه‌ساز htaccess برای PHP built-in server (زیرساخت تست مرورگر)
 * =====================================================================
 * PHP built-in server فایل .htaccess را اجرا نمی‌کند؛ این روتر همان
 * قوانین بازنویسی htaccess.template را برای محیط تست شبیه‌سازی می‌کند تا
 * URLهای تمیز (/blog/{slug}، /services، /en/... و ...) در تست مرورگر
 * واقعی همان رفتار Apache/cPanel استاندارد را داشته باشند.
 *
 * اجرا (تست محلی):
 *   php -S 127.0.0.1:8901 -t brand-site tests/browser/router-emulator.php
 *
 * @package SahandBrandMaker\Tests
 */

$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

/* 📁 مسیر ریشه سایت برند = docroot سرور (-t)؛ این فایل ممکن است داخل
   brand-site/ کپی شده باشد یا از tests/browser/ اجرا شود — DOCUMENT_ROOT
   در هر دو حالت درست است. */
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($root === '' || !is_dir($root)) { $root = dirname(__DIR__); }

/* ① فایل واقعی موجود (css/js/images/php) → خود PHP server سرو کند */
if ($uri !== '/' && preg_match('#^/(css|js|assets|images|includes|cache|api)/#', $uri) === 0) {
    $try = $root . $uri;
    if ($uri !== '/' && is_file($try) && pathinfo($try, PATHINFO_EXTENSION) !== 'php') {
        return false; /* فایل ایستا — سرو مستقیم */
    }
}
if (preg_match('#^/(css|js|assets|images|cache)/#', $uri)) {
    return false;
}

/* ② نقشه بازنویسی — قرینه htaccess.template */
$map = [
    '/services'        => '/pages/services.php',
    '/service-area'    => '/pages/service-area.php',
    '/warranty'        => '/pages/warranty.php',
    '/blog'            => '/pages/blog.php',
    '/search'          => '/pages/search.php',
    '/feed'            => '/feed.php',
    '/rss'             => '/feed.php',
    '/about-agency'    => '/pages/about-agency.php',
    '/about-brand'     => '/pages/about-brand.php',
    '/contact'         => '/pages/contact.php',
    '/request'         => '/pages/request.php',
    '/other-brands'    => '/pages/other-brands.php',
    '/error-codes'     => '/pages/error-codes.php',
    '/faq'             => '/pages/faq.php',
    '/sitemap-page'    => '/pages/sitemap-page.php',
    '/terms'           => '/pages/terms.php',
    '/privacy'         => '/pages/privacy.php',
];

/* چندزبانه /en/ */
if (preg_match('#^/en/(services|service-area|warranty|blog|search|about-agency|about-brand|contact|request|other-brands|error-codes|faq|sitemap-page|terms|privacy)/?$#', $uri, $m)) {
    $_GET['lang'] = 'en';
    require $root . $map['/' . $m[1]];
    return true;
}
if ($uri === '/en' || $uri === '/en/') {
    $_GET['lang'] = 'en';
    require $root . '/index.php';
    return true;
}
if (preg_match('#^/en/blog/([^/]+)/?$#', rawurldecode($uri), $m)) {
    $_GET['slug'] = $m[1];
    $_GET['lang'] = 'en';
    require $root . '/pages/article.php';
    return true;
}

/* مقاله: /blog/{slug} */
if (preg_match('#^/blog/([^/]+)/?$#', rawurldecode($uri), $m)) {
    $_GET['slug'] = $m[1];
    require $root . '/pages/article.php';
    return true;
}
if ($uri === '/blog/article') {
    require $root . '/pages/article.php';
    return true;
}

/* sitemap.xml پویا */
if ($uri === '/sitemap.xml') {
    require $root . '/sitemap.php';
    return true;
}

/* صفحات ثابت نقشه */
$u = rtrim($uri, '/');
if ($u !== '' && isset($map[$u])) {
    require $root . $map[$u];
    return true;
}

/* ریشه */
if ($uri === '/' || $uri === '/index.php') {
    return false; /* index.php خودش سرو می‌شود */
}

/* ③ فایل PHP واقعی موجود (مثل /js/form-submit.php) → مستقیم */
if (is_file($root . $uri) && pathinfo($root . $uri, PATHINFO_EXTENSION) === 'php') {
    return false;
}

/* ۴۰۴ سفارشی */
http_response_code(404);
require $root . '/404.php';
return true;
