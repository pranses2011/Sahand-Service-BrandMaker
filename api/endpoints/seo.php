<?php
/**
 * 🔍 اندپوینت سئو — داده‌های سئوی برند و صفحات
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

function api_brand_seo(int $brandId): void
{
    $db = Database::getInstance();
    $brand = $db->fetch('SELECT id, name_fa, name_en, domain, seo_title, seo_description, seo_keywords FROM brands WHERE id = ?', [$brandId]);
    if (!$brand) {
        json_response(['success' => false, 'error' => 'برند یافت نشد'], 404);
    }
    // تگ‌های وبمستر فعال
    $webmasterTags = $db->fetchAll('SELECT meta_name, content FROM webmaster_tags WHERE is_active = 1 AND content IS NOT NULL');
    // سئوی صفحات
    $pages = $db->fetchAll(
        'SELECT page_type, seo_title, seo_description, seo_keywords, seo_robots, canonical FROM brand_pages WHERE brand_id = ? AND is_active = 1',
        [$brandId]
    );
    json_response([
        'success' => true,
        'data'    => [
            'brand' => $brand,
            'webmaster_tags' => $webmasterTags,
            'pages' => $pages,
        ],
    ]);
}
