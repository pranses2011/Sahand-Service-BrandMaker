<?php
/**
 * 📰 اندپوینت مقالات — لیست و تکی
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

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

    json_response([
        'success' => true,
        'data'    => array_map(function ($a) {
            $a['tags'] = json_decode($a['tags'] ?? '[]', true) ?: [];
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

    json_response([
        'success' => true,
        'data'    => [
            'title'       => $article['title'],
            'slug'        => $article['slug'],
            'content'     => $article['content'],
            'excerpt'     => $article['excerpt'],
            'featured_image' => $article['featured_image'],
            'tags'        => json_decode($article['tags'] ?? '[]', true) ?: [],
            'published_at' => $article['published_at'],
            'views'       => (int)$article['views'] + 1,
            'seo'         => ['title' => $article['seo_title'], 'description' => $article['seo_description']],
            'related'     => $related,
        ],
    ]);
}
