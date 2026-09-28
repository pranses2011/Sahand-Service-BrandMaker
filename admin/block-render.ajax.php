<?php
/**
 * ⚡ اندپوینت رندر سرور-محور بوم قالب‌ساز (P2-22 — حذف رندرگر سوم)
 * =====================================================================
 * گزارش تحلیل جامع §۹.۱: رندرگر JS بوم سومین پیاده‌سازی موازی بود —
 * هر باگ بصری سه‌بار رفع می‌شد و بوم با پیش‌نمایش/سایت هم‌گرا نمی‌ماند.
 *
 * ✅ اکنون بوم HTML هر بلوک را از همین اندپوینت می‌گیرد که از
 * «هسته رندرگر واحد» (templates/brand-core/includes/block-renderer-core.php)
 * با حالت preview رندر می‌کند — همان کدی که سایت واقعی برند اجرا می‌کند.
 *
 * POST (JSON):
 *   { "items": [ {"block": "hero", "props": {...}}, ... ] }
 * پاسخ:
 *   { "success": true, "html": ["<div class=\"blk ...>", ...] }  — به‌ترتیب درخواست
 *
 * محدودیت: حداکثر ۶۰ بلوک در هر فراخوانی (بوم در دسته‌های ۴۰تایی می‌گیرد).
 *
 * @package SahandBrandMaker
 * @since   2.36.0
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$auth = new Auth();
$auth->requireLogin();
/* 🛡️ دفاع در عمق: رد درخواست بین‌سایتی */
reject_cross_origin();

/* 🛡️ CSRF برای AJAX */
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    json_response(['success' => false, 'error' => 'توکن CSRF نامعتبر است.'], 419);
}

/* 📥 ورودی */
$input = Router::jsonInput();
$items = $input['items'] ?? [];
if (!is_array($items) || $items === []) {
    json_response(['success' => false, 'error' => 'فهرست بلوک‌ها خالی است.'], 400);
}
if (count($items) > 60) {
    json_response(['success' => false, 'error' => 'حداکثر ۶۰ بلوک در هر فراخوانی.'], 400);
}

/* ⭐ عناصر شخصی از دیتابیس (برای بلوک pelement) */
$pelements = [];
try {
    foreach (Database::getInstance()->fetchAll('SELECT id, name, html, css FROM personal_elements') as $peRow) {
        $pelements[(int)$peRow['id']] = ['name' => (string)$peRow['name'], 'html' => (string)$peRow['html'], 'css' => (string)$peRow['css']];
    }
} catch (Throwable $peDbE) {
    $pelements = [];
}

/* 🧱 هسته رندرگر واحد + حالت preview */
require_once dirname(__DIR__) . '/templates/brand-core/includes/block-renderer-core.php';
pv_renderer_init(['mode' => 'preview', 'pelements' => $pelements]);

/* 🎬 رندر به‌ترتیب درخواست */
$html = [];
foreach ($items as $it) {
    $block = (string)($it['block'] ?? '');
    $props = is_array($it['props'] ?? null) ? $it['props'] : [];
    /* کانتینرهای ستونی: اسکلت بوم (ستون‌های رهاسازی را خود بوم می‌سازد) */
    if ($block === 'section-columns' || $block === 'section-split') {
        $html[] = '';
        continue;
    }
    $html[] = pv_render_block($block, $props);
}

json_response(['success' => true, 'html' => $html]);
