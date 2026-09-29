<?php
/**
 * 🧪 اندپوینت تست A/B سایت برند (P3 — v2.37)
 * ============================================
 * نقشه راه P3 گزارش تحلیل جامع: «A/B تست» —
 *
 * دو اندپوینت:
 *   ① GET  brand/{id}/ab-active — تستِ running این برند برای رندر هیرو
 *      (index.php سایت برند می‌گیرد: element + متن دو واریانت + test_id)
 *   ② POST brand/{id}/ab-event   — بیکِن view/click از مرورگر
 *      (سبک مثل track — بدون پاسخ انتظار، فقط ۲۰۴)
 *
 * 🧮 تقسیم بازدیدکننده: هش پایدار md5(visitor_id) + test_id → parity بیت
 * آخر → A/B — یعنی همان کاربر همیشه همان واریانت را می‌بیند (کوکی ۳۶۵ روزه).
 *
 * 🔒 نکات:
 *   • visitor_hash فقط md5 رشته تصادفی کوکی است — قابل برگشت به کاربر نیست
 *   • هر visitor برای هر تست فقط یک view شمرده می‌شود (کلید یگانه منطقی)
 *   • click بدون قید — هر کلیک ثبت می‌شود (CTR واقعی)
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

/**
 * 🧪 تست فعال برند — GET brand/{brandId}/ab-active
 */
function api_ab_active(int $brandId): void
{
    $db = Database::getInstance();
    $test = $db->fetch(
        "SELECT id, element, variant_a, variant_b FROM ab_tests
         WHERE brand_id = ? AND status = 'running'
         ORDER BY started_at DESC LIMIT 1",
        [$brandId]
    );
    if (!$test) {
        json_response(['success' => true, 'data' => null]);
    }
    json_response([
        'success' => true,
        'data'    => [
            'test_id'    => (int)$test['id'],
            'element'    => (string)$test['element'],
            'variant_a'  => (string)$test['variant_a'],
            'variant_b'  => (string)$test['variant_b'],
        ],
    ]);
}

/**
 * 📡 بیکِن رویداد — POST brand/{brandId}/ab-event
 * بدنه: { api_key, test_id, variant: 'A'|'B', event: 'view'|'click', visitor: md5 }
 */
function api_ab_event(int $urlBrandId): void
{
    $db = Database::getInstance();
    $input = Router::jsonInput();

    /* 🔑 احراز هویت برند — مثل سایر اندپوینت‌های عمومی سایت برند */
    $apiKey = (string)($input['api_key'] ?? '');
    $brand = null;
    if ($apiKey !== '') {
        $brand = $db->fetch(
            'SELECT b.* FROM brands b JOIN api_keys k ON k.brand_id = b.id WHERE k.api_key = ? AND k.is_active = 1',
            [$apiKey]
        );
    }
    if (!$brand && $urlBrandId > 0) {
        $brand = $db->fetch('SELECT * FROM brands WHERE id = ? AND is_active = 1', [$urlBrandId]);
    }
    if (!$brand) {
        json_response(['success' => false, 'error' => 'برند معتبر شناسایی نشد'], 401);
    }

    $testId = (int)($input['test_id'] ?? 0);
    $variant = strtoupper((string)($input['variant'] ?? ''));
    $event = strtolower((string)($input['event'] ?? 'view'));
    $visitor = (string)($input['visitor'] ?? '');

    if ($testId <= 0 || !in_array($variant, ['A', 'B'], true) || !in_array($event, ['view', 'click'], true)) {
        json_response(['success' => false, 'error' => 'پارامترهای نامعتبر'], 422);
    }
    if (!preg_match('/^[a-f0-9]{32}$/', $visitor)) {
        json_response(['success' => false, 'error' => 'شناسه بازدیدکننده نامعتبر'], 422);
    }

    /* تست متعلق به همین برند + در حال اجرا؟ */
    $test = $db->fetch(
        'SELECT id, brand_id FROM ab_tests WHERE id = ? AND status = ?',
        [$testId, 'running']
    );
    if (!$test || (int)$test['brand_id'] !== (int)$brand['id']) {
        json_response(['success' => false, 'error' => 'تست فعال نیست'], 404);
    }

    /* 👁 view فقط یک‌بار به‌ازای هر بازدیدکننده — click همیشه */
    if ($event === 'view') {
        try {
            $seen = (int)$db->fetchValue(
                "SELECT COUNT(*) FROM ab_events WHERE test_id = ? AND visitor_hash = ? AND event = 'view'",
                [$testId, $visitor]
            );
            if ($seen > 0) {
                json_response(['success' => true], 200);
            }
        } catch (Throwable $sv) { /* بی‌صدا — ثبت ادامه می‌کند */ }
    }

    try {
        $db->insert('ab_events', [
            'test_id'      => $testId,
            'variant'      => $variant,
            'visitor_hash' => $visitor,
            'event'        => $event,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $in) { /* تکراری/خطا — بیکِن بی‌صدا رد می‌شود */ }

    json_response(['success' => true], 200);
}
