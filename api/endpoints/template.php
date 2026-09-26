<?php
/**
 * 🧩 اندپوینت قالب — چیدمان قالب فعال صفحه برند
 *
 * 🆕 v2.27 — ریشه «تغییر تم در سایت‌ساز روی سایت برندها اعمال نمی‌شود»:
 * این اندپوینت از قبل زنجیرهٔ تم را حل می‌کرد اما هیچ صفحه‌ای از سایت برند
 * آن را صدا نمی‌زد! اکنون صفحات سایت برند چیدمان را از اینجا می‌گیرند.
 * زنجیرهٔ اولویت (تکی ← عمومی):
 *   ① چیدمان سفارشی خود صفحه (brand_pages.layout_json — ویرایش مستقیم قالب‌ساز)
 *   ② قالب اختصاصی صفحه (brand_pages.template_id)
 *   ③ قالب تعیین‌شده در تم برند (themes.config_json)
 *   ④ قالب پیش‌فرض نوع صفحه (templates.is_default)
 * + عناصر شخصی (pelement) استفاده‌شده در چیدمان با HTML/CSS کامل برگردانده
 *   می‌شوند تا روی سایت برند دقیقاً مثل سایت مبدأ رندر شوند.
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

function api_brand_template(int $brandId, string $pageType): void
{
    $db = Database::getInstance();
    $pageType = preg_replace('/[^a-z0-9\-]/', '', strtolower($pageType)) ?: 'home';

    $cache = new Cache();
    $data = $cache->remember("api_brand_{$brandId}_template_{$pageType}", 120, function () use ($db, $brandId, $pageType) {
        $layout = null;
        $source = '';

        /* ① چیدمان سفارشی خود صفحه (ویرایش مستقیم در قالب‌ساز) */
        $page = $db->fetch(
            'SELECT template_id, layout_json FROM brand_pages WHERE brand_id = ? AND page_type = ? AND is_active = 1',
            [$brandId, $pageType]
        );
        if ($page && trim((string)($page['layout_json'] ?? '')) !== '' && $page['layout_json'] !== '[]') {
            $decoded = json_decode((string)$page['layout_json'], true);
            if (is_array($decoded) && !empty($decoded)) {
                $layout = $decoded;
                $source = 'page';
            }
        }

        /* ② قالب اختصاصی صفحه */
        if ($layout === null && $page && !empty($page['template_id'])) {
            $tpl = $db->fetch('SELECT name, layout_json FROM templates WHERE id = ?', [(int)$page['template_id']]);
            if ($tpl) {
                $decoded = json_decode((string)($tpl['layout_json'] ?? '[]'), true);
                if (is_array($decoded) && !empty($decoded)) {
                    $layout = $decoded;
                    $source = 'template';
                }
            }
        }

        /* ③ قالب از تم برند — برای صفحه‌های درباره، تم عمومی «about» هم امتحان می‌شود */
        if ($layout === null) {
            $theme = $db->fetch('SELECT theme_id FROM brands WHERE id = ?', [$brandId]);
            if ($theme && $theme['theme_id']) {
                $config = json_decode((string)$db->fetchValue('SELECT config_json FROM themes WHERE id = ?', [$theme['theme_id']]), true) ?: [];
                $tplId = $config[$pageType] ?? null;
                if (!$tplId && str_starts_with($pageType, 'about-')) {
                    $tplId = $config['about'] ?? null;
                }
                if ($tplId) {
                    $tpl = $db->fetch('SELECT name, layout_json FROM templates WHERE id = ?', [(int)$tplId]);
                    if ($tpl) {
                        $decoded = json_decode((string)($tpl['layout_json'] ?? '[]'), true);
                        if (is_array($decoded) && !empty($decoded)) {
                            $layout = $decoded;
                            $source = 'theme';
                        }
                    }
                }
            }
        }

        /* ④ قالب پیش‌فرض نوع صفحه */
        if ($layout === null) {
            $tplId = $db->fetchValue('SELECT id FROM templates WHERE page_type = ? AND is_default = 1', [$pageType]);
            if (!$tplId && str_starts_with($pageType, 'about-')) {
                $tplId = $db->fetchValue('SELECT id FROM templates WHERE page_type = ? AND is_default = 1', ['about']);
            }
            if ($tplId) {
                $tpl = $db->fetch('SELECT name, layout_json FROM templates WHERE id = ?', [(int)$tplId]);
                if ($tpl) {
                    $decoded = json_decode((string)($tpl['layout_json'] ?? '[]'), true);
                    if (is_array($decoded) && !empty($decoded)) {
                        $layout = $decoded;
                        $source = 'default';
                    }
                }
            }
        }

        if ($layout === null) {
            return null;
        }

        /* ⭐ عناصر شخصی استفاده‌شده در چیدمان (بازگشتی در ستون‌ها) */
        $pelementIds = [];
        $walkItems = static function (array $items) use (&$walkItems, &$pelementIds): void {
            foreach ($items as $item) {
                if (!is_array($item)) { continue; }
                if (($item['block'] ?? '') === 'pelement') {
                    $id = (int)($item['props']['element_id'] ?? 0);
                    if ($id > 0) { $pelementIds[$id] = true; }
                }
                if (is_array($item['cols'] ?? null)) {
                    foreach ($item['cols'] as $col) {
                        if (is_array($col)) { $walkItems($col); }
                    }
                }
            }
        };
        $walkItems($layout);
        $pelements = [];
        if ($pelementIds) {
            foreach ($db->fetchAll(
                'SELECT id, name, html, css FROM personal_elements WHERE id IN (' . implode(',', array_map('intval', array_keys($pelementIds))) . ')'
            ) as $pe) {
                $pelements[] = ['id' => (int)$pe['id'], 'name' => (string)$pe['name'], 'html' => (string)$pe['html'], 'css' => (string)$pe['css']];
            }
        }

        return ['name' => '', 'layout' => $layout, 'source' => $source, 'pelements' => $pelements];
    });

    if (!$data) {
        json_response(['success' => true, 'data' => null]);
    }
    json_response(['success' => true, 'data' => $data]);
}
