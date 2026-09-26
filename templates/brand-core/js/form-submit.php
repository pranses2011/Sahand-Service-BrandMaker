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

/* 🧼 فقط فیلدهای مجاز فرم عبور می‌کنند (ضد تزریق فیلد) */
$allowed = ['full_name', 'phone', 'phone2', 'address', 'device_type', 'device_other',
            'device_model', 'description', 'preferred_date', 'preferred_time'];
$payload = [];
foreach ($allowed as $field) {
    if (array_key_exists($field, $data)) {
        $payload[$field] = is_string($data[$field]) ? mb_substr(trim($data[$field]), 0, 2000) : $data[$field];
    }
}
if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'فیلدی برای ثبت ارسال نشده است'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 🚀 ارسال به API سایت ساز (postToAPI خودش api_key را ضمیمه می‌کند) */
$response = postToAPI('brand/' . BRAND_ID . '/request', $payload);

/* 📤 بازگرداندن پاسخ API به مرورگر */
$httpCode = !empty($response['success']) ? 200 : 422;
http_response_code($httpCode);
echo json_encode($response, JSON_UNESCAPED_UNICODE);
