<?php
/**
 * 📰 اندپوینت مقالات — لیست و تکی
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

/**
 * 🧹 v2.27 — بازکردن متغیرهای {{...}} در پاسخ API (لایه نجات)
 * ==========================================================================
 * ریشه باگ «متن مقالات {{warranty_period}} و {{agency_name}} خام نشان می‌دهد»:
 * مقالات تولیدشده با نسخه‌های قبل از 2.27 این متغیرها را بازنشده در
 * دیتابیس دارند. این تابع هنگام «خواندن» جایگزین می‌کند تا مقالات قدیمی
 * بدون بازتولید درست نمایش داده شوند؛ مقالات جدید از مبدأ تمیزند.
 */
function api_article_sweep_vars(string $text, array $brand): string
{
    if ($text === '' || strpos($text, '{{') === false) {
        return $text;
    }
    $agencyName = Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس';
    $warranty = Config::get(Config::KEY_WARRANTY) ?: [];
    $map = [
        'agency_name'   => $agencyName,
        'agency'        => $agencyName,
        'brand_fa'      => $brand['name_fa'] ?? '',
        'brand_en'      => $brand['name_en'] ?? '',
        'warranty_period' => (string)($warranty['default_period'] ?? '۶ ماه'),
        'main_site'     => Config::get(Config::KEY_MAIN_SITE) ?: AGENCY_MAIN_SITE,
        'year_now'      => (string)date('Y'),
    ];
    foreach ($map as $key => $value) {
        $text = str_replace(['{{' . $key . '}}', '{{ ' . $key . ' }}', '{{ ' . $key . '}}', '{{' . $key . ' }}'], $value, $text);
    }
    /* متغیر ناشناخته باقی‌مانده → حذف امن (فقط شناسه‌های ساده — HTML دست‌نخورده) */
    return preg_replace('/\{\{\s*[\w\-\x{0600}-\x{06FF}\.]+\s*\}\}/u', '', $text) ?? $text;
}

function api_brand_articles(int $brandId, int $page = 1, int $perPage = 10): void
{
    $page = max(1, $page);
    $perPage = min(50, max(1, $perPage));
    $offset = ($page - 1) * $perPage;
    $db = Database::getInstance();

    $total = $db->count('brand_articles', 'brand_id = ? AND status = ?', [$brandId, 'published']);
    $articles = $db->fetchAll(
        "SELECT id, title, slug, excerpt, featured_image, tags, published_at, views,
                seo_title, seo_description, TIMESTAMPDIFF(SECOND, '2000-01-01', published_at) as ts
         FROM brand_articles
         WHERE brand_id = ? AND status = 'published'
         ORDER BY published_at DESC LIMIT {$perPage} OFFSET {$offset}",
        [$brandId]
    );

    $brandRow = $db->fetch('SELECT name_fa, name_en FROM brands WHERE id = ?', [$brandId]) ?: [];

    json_response([
        'success' => true,
        'data'    => array_map(function ($a) use ($brandRow) {
            $a['tags'] = json_decode($a['tags'] ?? '[]', true) ?: [];
            /* 🧹 v2.27: عنوان/خلاصه مقالات قدیمی هم تمیز می‌شوند */
            $a['title'] = api_article_sweep_vars((string)$a['title'], $brandRow);
            $a['excerpt'] = api_article_sweep_vars((string)$a['excerpt'], $brandRow);
            unset($a['ts']);
            return $a;
        }, $articles),
        'meta'    => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => (int)ceil($total / $perPage)],
    ]);
}

function api_brand_article(int $brandId, string $slug): void
{
    $db = Database::getInstance();
    $article = $db->fetch(
        'SELECT * FROM brand_articles WHERE brand_id = ? AND slug = ? AND status = ?',
        [$brandId, urldecode($slug), 'published']
    );
    if (!$article) {
        json_response(['success' => false, 'error' => 'مقاله یافت نشد'], 404);
    }
    // افزایش بازدید
    $db->update('brand_articles', ['views' => (int)$article['views'] + 1], 'id = ?', [$article['id']]);

    // مقالات مرتبط (همان برند، ۳ مورد)
    $related = $db->fetchAll(
        'SELECT title, slug, excerpt FROM brand_articles WHERE brand_id = ? AND id != ? AND status = ? ORDER BY RAND() LIMIT 3',
        [$brandId, $article['id'], 'published']
    );

    /* 🧹 v2.27 — محتوای مقالات قدیمی (قبل از این نسخه) متغیرهای خام دارند؛
       همین‌جا باز می‌شوند تا صفحه مقاله سایت برند همیشه تمیز باشد. */
    $brandRow = $db->fetch('SELECT name_fa, name_en FROM brands WHERE id = ?', [$brandId]) ?: [];
    $cleanContent = api_article_sweep_vars((string)$article['content'], $brandRow);

    json_response([
        'success' => true,
        'data'    => [
            'title'       => api_article_sweep_vars((string)$article['title'], $brandRow),
            'slug'        => $article['slug'],
            'content'     => $cleanContent,
            'excerpt'     => api_article_sweep_vars((string)$article['excerpt'], $brandRow),
            'featured_image' => $article['featured_image'],
            'tags'        => json_decode($article['tags'] ?? '[]', true) ?: [],
            'published_at' => $article['published_at'],
            'views'       => (int)$article['views'] + 1,
            'seo'         => [
                'title'       => api_article_sweep_vars((string)$article['seo_title'], $brandRow),
                'description' => api_article_sweep_vars((string)$article['seo_description'], $brandRow),
            ],
            'related'     => array_map(static function ($r) use ($brandRow) {
                $r['title'] = api_article_sweep_vars((string)$r['title'], $brandRow);
                return $r;
            }, $related),
        ],
    ]);
}
