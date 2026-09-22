<?php
/**
 * 🏷️ اندپوینت برند — اطلاعات کامل و منو
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

function api_brand_info(int $brandId): void
{
    $db = Database::getInstance();
    $brand = $db->fetch('SELECT * FROM brands WHERE id = ? AND is_active = 1', [$brandId]);
    if (!$brand) {
        json_response(['success' => false, 'error' => 'برند یافت نشد'], 404);
    }

    $palette = $db->fetch('SELECT light_palette, dark_palette FROM color_palettes WHERE brand_id = ?', [$brandId]);
    $contacts = (array)(Config::get(Config::KEY_CONTACTS) ?: []);
    $emails = (array)(Config::get(Config::KEY_EMAILS) ?: []);
    $addresses = (array)(Config::get(Config::KEY_ADDRESSES) ?: []);
    $socials = (array)(Config::get(Config::KEY_SOCIALS) ?: []);
    $workHours = (array)(Config::get(Config::KEY_WORK_HOURS) ?: []);
    $cost = (array)(Config::get(Config::KEY_COST) ?: []);
    $agency = [
        'name_fa'   => Config::get(Config::KEY_AGENCY_NAME_FA),
        'name_en'   => Config::get(Config::KEY_AGENCY_NAME_EN),
        'slogan_fa' => Config::get(Config::KEY_AGENCY_SLOGAN_FA),
        'slogan_en' => Config::get(Config::KEY_AGENCY_SLOGAN_EN),
        'logo'      => Config::get(Config::KEY_AGENCY_LOGO) ? asset_url((string)Config::get(Config::KEY_AGENCY_LOGO)) : '',
        'main_site' => Config::get(Config::KEY_MAIN_SITE),
    ];

    json_response([
        'success' => true,
        'data'    => [
            'brand'      => [
                'id'        => (int)$brand['id'],
                'name_fa'   => $brand['name_fa'],
                'name_en'   => $brand['name_en'],
                'logo'      => $brand['logo'] ? asset_url($brand['logo']) : '',
                'domain'    => $brand['domain'],
                'error_codes_enabled' => (bool)$brand['error_codes_enabled'],
                'seo'       => ['title' => $brand['seo_title'], 'description' => $brand['seo_description'], 'keywords' => $brand['seo_keywords']],
            ],
            'palette'   => [
                'light' => $palette ? json_decode($palette['light_palette'], true) : null,
                'dark'  => $palette ? json_decode($palette['dark_palette'], true) : null,
            ],
            'agency'    => $agency,
            'contacts'  => ['phones' => $contacts, 'emails' => $emails, 'addresses' => $addresses, 'socials' => $socials],
            'work_hours'=> $workHours,
            'cost'      => $cost,
            'main_site' => Config::get(Config::KEY_MAIN_SITE),
        ],
    ]);
}

function api_brand_menu(int $brandId, string $location): void
{
    $db = Database::getInstance();
    $location = $location === 'footer' ? 'footer' : 'header';
    // منوی اختصاصی برند یا منوی عمومی
    $menu = $db->fetch('SELECT id FROM menus WHERE brand_id = ? AND location = ?', [$brandId, $location]);
    if (!$menu) {
        $menu = $db->fetch('SELECT id FROM menus WHERE brand_id IS NULL AND location = ?', [$location]);
    }
    if (!$menu) {
        json_response(['success' => true, 'data' => []]);
    }
    $items = $db->fetchAll('SELECT id, parent_id, title, url, page_type, icon, nofollow, is_active, sort_order FROM menu_items WHERE menu_id = ? AND is_active = 1 ORDER BY sort_order, id', [$menu['id']]);
    // ساخت درخت
    $byParent = [];
    foreach ($items as $item) {
        $byParent[$item['parent_id']][] = [
            'title' => $item['title'],
            'url' => $item['url'],
            'page_type' => $item['page_type'],
            'icon' => $item['icon'],
            'nofollow' => (bool)$item['nofollow'],
            'children' => [],
        ];
    }
    $build = function (?int $pid) use (&$build, $byParent) {
        $result = [];
        foreach (($byParent[$pid] ?? []) as $item) {
            $item['children'] = $build((int)array_search($item, $byParent[$pid], true) !== false ? null : null);
            $result[] = $item;
        }
        return $result;
    };
    // ساده‌سازی: ساخت درخت با parent_id
    $nodes = [];
    foreach ($items as $item) {
        $nodes[(int)$item['id']] = $item;
    }
    $tree = [];
    foreach ($nodes as $id => $node) {
        $parentId = $node['parent_id'] !== null ? (int)$node['parent_id'] : null;
        if ($parentId === null || !isset($nodes[$parentId])) {
            $tree[$id] = &$nodes[$id];
        } else {
            $nodes[$parentId]['children'][] = &$nodes[$id];
        }
    }
    // تبدیل به آرایه ساده
    $flat = array_map(function ($n) { unset($n['parent_id'], $n['id']); return $n; }, array_values($tree));
    json_response(['success' => true, 'data' => $flat]);
}
