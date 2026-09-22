<?php
/**
 * 🧩 اندپوینت قالب — چیدمان قالب فعال صفحه برند
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

function api_brand_template(int $brandId, string $pageType): void
{
    $db = Database::getInstance();
    // قالب اختصاصی صفحه یا قالب پیش‌فرض نوع صفحه از تم برند
    $page = $db->fetch('SELECT template_id, layout_json FROM brand_pages WHERE brand_id = ? AND page_type = ?', [$brandId, $pageType]);
    $templateId = $page['template_id'] ?? null;
    if (!$templateId) {
        $theme = $db->fetch('SELECT theme_id FROM brands WHERE id = ?', [$brandId]);
        if ($theme['theme_id']) {
            $config = json_decode((string)$db->fetchValue('SELECT config_json FROM themes WHERE id = ?', [$theme['theme_id']]), true) ?: [];
            $templateId = $config[$pageType] ?? null;
        }
        if (!$templateId) {
            $templateId = $db->fetchValue('SELECT id FROM templates WHERE page_type = ? AND is_default = 1', [$pageType]);
        }
    }
    if ($templateId) {
        $tpl = $db->fetch('SELECT id, name, page_type, layout_json FROM templates WHERE id = ?', [$templateId]);
        json_response(['success' => true, 'data' => $tpl ? [
            'name' => $tpl['name'],
            'layout' => json_decode($tpl['layout_json'] ?? '[]', true) ?: [],
        ] : null]);
    }
    json_response(['success' => true, 'data' => null]);
}
