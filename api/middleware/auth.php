<?php
/**
 * 🔑 میان‌افزار احراز هویت API
 * =============================
 * اعتبارسنجی X-API-Key از جدول api_keys
 *
 * @package SahandBrandMaker
 */

if (!defined('SAHAND_INIT')) {
    http_response_code(403);
    exit;
}

/**
 * 🔎 بررسی کلید API — true = ادامه، false = توقف
 */
function api_auth(array $params = []): bool
{
    $key = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? '');

    if ($key === '') {
        json_response([
            'success' => false,
            'error'   => 'کلید API الزامی است — در هدر X-API-Key ارسال شود',
        ], 401);
        return false;
    }

    if (!preg_match('/^[a-zA-Z0-9_\-]{10,70}$/', $key)) {
        json_response(['success' => false, 'error' => 'قالب کلید API نامعتبر است'], 401);
        return false;
    }

    $db = Database::getInstance();
    $record = $db->fetch('SELECT * FROM api_keys WHERE api_key = ? AND is_active = 1 LIMIT 1', [$key]);

    if (!$record) {
        // 🔒 تأخیر امنیتی برای جلوگیری از حمله کشف کلید
        usleep(500000);
        json_response(['success' => false, 'error' => 'کلید API نامعتبر است'], 401);
        return false;
    }

    // 📊 بروزرسانی آمار استفاده
    $db->update('api_keys', [
        'requests_count' => (int)$record['requests_count'] + 1,
        'last_used_at'   => date('Y-m-d H:i:s'),
    ], 'id = ?', [$record['id']]);

    // در دسترس بودن برند محدود برای کلید برند-محور
    $GLOBALS['API_BRAND_ID'] = $record['brand_id'] !== null ? (int)$record['brand_id'] : null;
    return true;
}
