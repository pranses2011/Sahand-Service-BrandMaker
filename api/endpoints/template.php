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

        /* 🔍 ستون layout_custom (v2.41) — نصب‌های در حال ارتقا هنوز
           ندارند؛ با تشخیص پویا محیط خطا نمی‌دهد */
        $hasCustomFlag = false;
        try {
            $hasCustomFlag = !empty($db->fetchValue(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['brand_pages', 'layout_custom']
            ));
        } catch (Throwable $flagE) {
            $hasCustomFlag = false;
        }

        /* ① چیدمان ویرایش‌شده دستی در قالب‌ساز (layout_custom=1) — بر تم مقدم
           🆕 v2.41 — ریشه «تغییر تم اعمال نمی‌شود»: قبلاً «هر» layout_json
           (حتی خودکار تولیدی ساخت برند) اولویت اول بود و زنجیره تم هرگز
           اجرا نمی‌شد؛ اکنون فقط ویرایش دستی کاربر این اولویت را دارد. */
        $page = $db->fetch(
            'SELECT template_id, layout_json' . ($hasCustomFlag ? ', layout_custom' : '') . ' FROM brand_pages WHERE brand_id = ? AND page_type = ? AND is_active = 1',
            [$brandId, $pageType]
        );
        if ($page && $hasCustomFlag && (int)($page['layout_custom'] ?? 0) === 1
            && trim((string)($page['layout_json'] ?? '')) !== '' && $page['layout_json'] !== '[]') {
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

        /* ③ قالب از تم برند — برای صفحه‌های درباره، تم عمومی «about» هم امتحان می‌شود
           🆕 v2.28: برندهای بدون تم اختصاصی (theme_id خالی) به «تم پیش‌فرض»
           (is_default=1) برمی‌گردند — ریشه حالت «عمومی» در شکایت «تغییر تم
           تکی/عمومی اعمال نمی‌شود»: make_default فقط برندهای جدید را پوشش می‌داد.
           🆕 v2.41: تم اکنون بر چیدمان «خودکار» ساخت برند مقدم است (فال‌بک⑤). */
        if ($layout === null) {
            $themeRow = $db->fetch('SELECT theme_id FROM brands WHERE id = ?', [$brandId]);
            $themeId = $themeRow ? (int)($themeRow['theme_id'] ?: 0) : 0;
            if ($themeId <= 0) {
                $themeId = (int)$db->fetchValue('SELECT id FROM themes WHERE is_default = 1 ORDER BY id LIMIT 1');
            }
            if ($themeId > 0) {
                $config = json_decode((string)$db->fetchValue('SELECT config_json FROM themes WHERE id = ?', [$themeId]), true) ?: [];
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

        /* ⑤ فال‌بک نهایی — چیدمان خودکار تولیدشده هنگام ساخت برند
           (layout_custom=0). فقط زمانی مصرف می‌شود که تم/پیش‌فرض قالبی برای
           این نوع صفحه نداشته باشند (v2.41). */
        if ($layout === null && $page && trim((string)($page['layout_json'] ?? '')) !== '' && $page['layout_json'] !== '[]') {
            $decoded = json_decode((string)$page['layout_json'], true);
            if (is_array($decoded) && !empty($decoded)) {
                $layout = $decoded;
                $source = 'auto';
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
