<?php
/**
 * 🗺️ sitemap.xml پویا سایت برند — v2.33
 * ======================================
 * 🚨 ریشه (گزارش تحلیل بخش ۸): sitemap فقط هنگام ساخت ZIP/استقرار تولید
 * می‌شد → مقاله جدیدِ منتشرشده هرگز وارد آن نمی‌شد مگر استقرار مجدد.
 * ✅ اکنون با rewrite (htaccess.template) درخواست sitemap.xml به همین
 * فایل می‌رسد و همیشه تازه است: صفحه‌ها + همه مقالات منتشرشده از API.
 *
 * @package SahandBrandSite
 */
if (!defined('BRAND_INIT')) { define('BRAND_INIT', true); }
require __DIR__ . '/config.php';

header('Content-Type: application/xml; charset=UTF-8');

$domain = 'https://' . BRAND_DOMAIN;
$urls = [['loc' => $domain . '/', 'priority' => '1.0', 'changefreq' => 'weekly', 'lastmod' => '']];

/* 📄 صفحه‌های استاندارد سایت برند (همان مسیرهای rewrite) */
foreach ([
    ['services', '0.8', 'monthly'], ['service-area', '0.6', 'monthly'], ['warranty', '0.5', 'monthly'],
    ['blog', '0.9', 'daily'], ['about-agency', '0.5', 'monthly'], ['about-brand', '0.5', 'monthly'],
    ['contact', '0.7', 'monthly'], ['request', '0.9', 'monthly'], ['other-brands', '0.4', 'monthly'],
    ['error-codes', '0.6', 'weekly'], ['faq', '0.6', 'monthly'], ['terms', '0.3', 'yearly'], ['privacy', '0.3', 'yearly'],
] as $pg) {
    $urls[] = ['loc' => $domain . '/' . $pg[0], 'priority' => $pg[1], 'changefreq' => $pg[2], 'lastmod' => ''];
}

/* 📰 مقالات منتشرشده — تا ۵۰ صفحه (۵۰۰۰ مقاله) با کش ۱۲۰ ثانیه */
$page = 1;
do {
    $data = fetchFromAPI('brand/' . BRAND_ID . '/articles?page=' . $page . '&per_page=50', 120);
    $list = (array)($data['data']['articles'] ?? $data['data'] ?? []);
    if (!$list) { break; }
    foreach ($list as $a) {
        if (!is_array($a) || empty($a['slug'])) { continue; }
        $urls[] = [
            'loc'        => $domain . '/blog/article?slug=' . rawurlencode((string)$a['slug']),
            'priority'   => '0.6',
            'changefreq' => 'monthly',
            'lastmod'    => substr((string)($a['published_at'] ?? ''), 0, 10),
        ];
    }
    $total = (int)($data['data']['total'] ?? count($list));
    $page++;
} while ($page * 50 <= $total + 50 && $page <= 100 && count($list) === 50);

$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    $xml .= "  <url>\n    <loc>" . htmlspecialchars($u['loc'], ENT_XML1) . "</loc>\n";
    if (!empty($u['lastmod'])) { $xml .= "    <lastmod>{$u['lastmod']}</lastmod>\n"; }
    $xml .= "    <changefreq>{$u['changefreq']}</changefreq>\n    <priority>{$u['priority']}</priority>\n  </url>\n";
}
echo $xml . '</urlset>';
