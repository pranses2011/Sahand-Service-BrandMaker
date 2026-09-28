<?php
/**
 * 📡 فید RSS/Atom سایت برند (v2.34 — P1 #17)
 * ============================================
 * RSS 2.0 (پیش‌فرض /feed) + Atom (/feed?type=atom)
 * ۲۰ مقاله آخر منتشرشده از API با کش ۵ دقیقه.
 * htaccess: ^feed/?$ و ^rss/?$ → feed.php
 *
 * @package SahandBrandSite
 */
define('BRAND_INIT', true);
require_once __DIR__ . '/config.php';

$type = ($_GET['type'] ?? '') === 'atom' ? 'atom' : 'rss';
$articlesData = fetchFromAPI('brand/' . BRAND_ID . '/articles?page=1&per_page=20', 300);
$articles = $articlesData['data'] ?? [];
$brandData = fetchFromAPI('brand/' . BRAND_ID, 300);
$brand = $brandData['data'] ?? [];
$siteName = $brand['name_fa'] ?? BRAND_NAME_FA;
$siteDesc = $brand['seo_description'] ?? ('خدمات تعمیرات ' . $siteName);
$selfUrl = 'https://' . BRAND_DOMAIN . '/feed' . ($type === 'atom' ? '?type=atom' : '');

while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: application/' . ($type === 'atom' ? 'atom' : 'rss') . '+xml; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

if ($type === 'atom'):
    /* ───── Atom 1.0 ───── */ ?>
<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="fa" dir="rtl">
    <title><?= e($siteName) ?></title>
    <subtitle><?= e($siteDesc) ?></subtitle>
    <id>https://<?= e(BRAND_DOMAIN) ?>/</id>
    <link rel="alternate" type="text/html" href="https://<?= e(BRAND_DOMAIN) ?>/"/>
    <link rel="self" type="application/atom+xml" href="<?= e($selfUrl) ?>"/>
    <updated><?= !empty($articles) ? e(date('c', strtotime((string)$articles[0]['published_at']))) : e(date('c')) ?></updated>
    <generator uri="https://<?= e(BRAND_DOMAIN) ?>/">Sahand BrandMaker</generator>
    <?php foreach ($articles as $a): ?>
    <entry>
        <title><?= e($a['title']) ?></title>
        <link rel="alternate" type="text/html" href="https://<?= e(BRAND_DOMAIN) ?>/blog/<?= e(urlencode((string)$a['slug'])) ?>"/>
        <id>https://<?= e(BRAND_DOMAIN) ?>/blog/<?= e(urlencode((string)$a['slug'])) ?></id>
        <published><?= e(date('c', strtotime((string)$a['published_at']))) ?></published>
        <updated><?= e(date('c', strtotime((string)($a['updated_at'] ?? $a['published_at'])))) ?></updated>
        <summary type="text"><?= e($a['excerpt'] ?? '') ?></summary>
    </entry>
    <?php endforeach; ?>
</feed>
<?php else:
    /* ───── RSS 2.0 ───── */ ?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
    <title><?= e($siteName) ?></title>
    <link>https://<?= e(BRAND_DOMAIN) ?>/</link>
    <description><?= e($siteDesc) ?></description>
    <language>fa-ir</language>
    <lastBuildDate><?= !empty($articles) ? e(date('r', strtotime((string)$articles[0]['published_at']))) : e(date('r')) ?></lastBuildDate>
    <generator>Sahand BrandMaker</generator>
    <atom:link rel="self" type="application/rss+xml" href="<?= e($selfUrl) ?>"/>
    <?php foreach ($articles as $a): ?>
    <item>
        <title><?= e($a['title']) ?></title>
        <link>https://<?= e(BRAND_DOMAIN) ?>/blog/<?= e(urlencode((string)$a['slug'])) ?></link>
        <guid isPermaLink="true">https://<?= e(BRAND_DOMAIN) ?>/blog/<?= e(urlencode((string)$a['slug'])) ?></guid>
        <description><![CDATA[<?= $a['excerpt'] ?? '' ?>]]></description>
        <?php if (!empty($a['tags'])): ?><category><?= e(implode(', ', (array)$a['tags'])) ?></category><?php endif; ?>
        <pubDate><?= e(date('r', strtotime((string)$a['published_at']))) ?></pubDate>
    </item>
    <?php endforeach; ?>
</channel>
</rss>
<?php endif;
