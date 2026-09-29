<?php
/**
 * 📨 پروکسی همان‌مبدأ ثبت درخواست (v2.25)
 * ==========================================
 * فرم درخواست سایت برند به همین فایل (همان‌مبدأ = بدون CORS/محتوای ترکیبی/
 * سخت‌گیری SSL مرورگر) POST می‌شود و این فایل سمت سرور با cURL درخواست را
 * به API سایت ساز می‌برد.
 *
 * 🚨 چرا این فایل حیاتی است؟ نسخه‌های قبل فقط fallback مستقیم مرورگر → API
 * سایت ساز (مبدأ متفاوت) داشتند که با کوچکترین مشکل CORS، گواهی SSL پنل یا
 * HTTP/HTTPS ناهماهنگ به «خطای ارتباط با سرور» منجر می‌شد و درخواست کاربر
 * هرگز ثبت نمی‌شد. مسیر PHP همان‌مسیری است که خود صفحات سایت برای fetch
 * داده استفاده می‌کنند (SSL_VERIFYPEER=false و بدون محدودیت مرورگر).
 *
 * @package SahandBrandSite
 * @version 1.0.0
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

/* ⚙️ بارگذاری تنظیمات (ثابت‌ها + توابع postToAPI) — بدون نیاز به BRAND_INIT
   صفحه‌ای؛ این فایل مستقیم توسط fetch صدا زده می‌شود. */
define('BRAND_INIT', true);
require_once __DIR__ . '/../config.php';

/* 🛡️ فقط POST + حداکثر حجم بدنه 64KB */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'متد مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 65536) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'حجم درخواست بیش از حد مجاز است'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 📥 بدنه JSON */
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'داده ارسالی نامعتبر است'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 🧼 فقط فیلدهای مجاز فرم عبور می‌کنند (ضد تزریق فیلد)
   🖼️ v2.30 — فیلد images (آرایه URLهای آپلودشده) هم عبور می‌کند؛
   قبلاً این فیلد در لیست سفید نبود → تصاویر هرگز به API نمی‌رسیدند! */
$allowed = ['full_name', 'phone', 'phone2', 'address', 'device_type', 'device_other',
            'device_model', 'description', 'preferred_date', 'preferred_time', 'images'];
$payload = [];
foreach ($allowed as $field) {
    if (array_key_exists($field, $data)) {
        $payload[$field] = is_string($data[$field]) ? mb_substr(trim($data[$field]), 0, 2000) : $data[$field];
    }
}
/* 🖼️ اعتبارسنجی آرایه تصاویر: فقط رشته‌های URL ساده (حداکثر ۳ مورد) */
if (isset($payload['images'])) {
    $imgs = [];
    if (is_array($payload['images'])) {
        foreach (array_slice($payload['images'], 0, 5) as $imgUrl) {
            if (is_string($imgUrl) && preg_match('#^https?://[^\s"\'<>]{5,500}$#i', $imgUrl)) {
                $imgs[] = $imgUrl;
            }
        }
    }
    if ($imgs) {
        $payload['images'] = $imgs;
    } else {
        unset($payload['images']);
    }
}
if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'فیلدی برای ثبت ارسال نشده است'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ═══ 🆕 v2.31 — فرم‌های عمومی قالب‌ساز (sahand-form) ═══
   اگر form_block ارسال شده باشد، درخواست از مسیر فرم‌ها می‌رود؛
   در غیر این صورت رفتار قبلی (فرم درخواست کامل صفحه /request) حفظ می‌شود. */
if (!empty($data['form_block']) && is_string($data['form_block'])) {
    $formBlock = preg_replace('/[^a-z0-9_\-]/', '', (string)$data['form_block']);
    if ($formBlock === '') { $formBlock = 'custom'; }
    $formPayload = ['form_block' => $formBlock, 'fields' => []];
    /* فیلدها: کلیدهای امن کوتاه + مقدار متن محدود */
    if (is_array($data['fields'] ?? null)) {
        foreach ($data['fields'] as $fk => $fv) {
            $fk = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$fk);
            if ($fk === '' || strlen($fk) > 40) { continue; }
            $formPayload['fields'][$fk] = is_string($fv) ? mb_substr($fv, 0, 2000) : '';
        }
    }
    /* فیلدهای استاندارد فرم درخواست هم در سطح بالا (سازگاری) */
    foreach (['full_name', 'phone', 'phone2', 'address', 'device_type', 'device_other', 'device_model', 'description', 'preferred_date', 'preferred_time'] as $sf) {
        if (isset($payload[$sf])) { $formPayload[$sf] = $payload[$sf]; }
    }
    if (isset($payload['images'])) { $formPayload['images'] = $payload['images']; }
    /* مقصد ارسال (panel/email/telegram/bale) */
    if (is_array($data['dest'] ?? null)) {
        $dests = [];
        foreach ($data['dest'] as $dk) {
            if (in_array($dk, ['panel', 'email', 'telegram', 'bale'], true)) { $dests[] = $dk; }
        }
        if ($dests) { $formPayload['dest'] = implode(',', $dests); }
    }
    if (!empty($data['page'])) { $formPayload['page'] = mb_substr((string)$data['page'], 0, 300); }

    $response = postToAPI('brand/' . BRAND_ID . '/form-entry', $formPayload);
    $httpCode = !empty($response['success']) ? 200 : 422;
    http_response_code($httpCode);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

/* 🚀 ارسال به API سایت ساز (postToAPI خودش api_key را ضمیمه می‌کند) */
$response = postToAPI('brand/' . BRAND_ID . '/request', $payload);

/* 📤 بازگرداندن پاسخ API به مرورگر */
$httpCode = !empty($response['success']) ? 200 : 422;
http_response_code($httpCode);
echo json_encode($response, JSON_UNESCAPED_UNICODE);
