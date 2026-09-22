<?php
/**
 * 📄 اندپوینت صفحه — محتوای صفحه برند
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

function api_brand_page(int $brandId, string $pageType): void
{
    $cache = new Cache();
    $data = $cache->remember("api_brand_{$brandId}_page_{$pageType}", 300, function () use ($brandId, $pageType) {
        $db = Database::getInstance();
        $page = $db->fetch(
            'SELECT * FROM brand_pages WHERE brand_id = ? AND page_type = ? AND is_active = 1',
            [$brandId, $pageType]
        );
        if (!$page) {
            return null;
        }
        return [
            'page_type' => $page['page_type'],
            'title'     => $page['title'],
            'content'   => json_decode($page['content'] ?? '{}', true) ?: [],
            'seo'       => [
                'title'       => $page['seo_title'],
                'description' => $page['seo_description'],
                'keywords'    => $page['seo_keywords'],
                'robots'      => $page['seo_robots'],
                'og_title'    => $page['og_title'],
                'og_description' => $page['og_description'],
                'og_image'    => $page['og_image'],
            ],
            'layout'    => json_decode($page['layout_json'] ?? '[]', true) ?: [],
        ];
    });

    if (!$data) {
        json_response(['success' => false, 'error' => 'صفحه یافت نشد یا غیرفعال است'], 404);
    }
    json_response(['success' => true, 'data' => $data]);
}
