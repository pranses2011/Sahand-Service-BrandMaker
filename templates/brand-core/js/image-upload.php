<?php
/**
 * 🖼️ پروکسی همان‌مبدأ آپلود تصویر درخواست (v2.30)
 * =================================================
 * فرم درخواست سایت برند فایل تصویر را به همین فایل (همان‌مبدأ = بدون
 * CORS/محدودیت مرورگر) POST می‌کند و این فایل سمت سرور با cURL فایل را
 * به اندپوینت آپلود سایت ساز می‌برد و URL تصویر را برمی‌گرداند.
 *
 * سپس فرم، URLهای دریافت‌شده را همراه درخواست ثبت می‌کند — به همین
 * دلیل تصاویر پیوست برای اولین بار واقعاً به پنل/کانال‌ها می‌رسند.
 *
 * @package SahandBrandSite
 * @version 1.0.0
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

/* ⚙️ بارگذاری تنظیمات (ثابت‌ها) — بدون نیاز به BRAND_INIT صفحه‌ای */
define('BRAND_INIT', true);
require_once __DIR__ . '/../config.php';

/* 🛡️ فقط POST */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'متد مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 📥 فیلد فایل (image) + اعتبارسنجی اولیه حجم */
if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'فایلی ارسال نشده است'], JSON_UNESCAPED_UNICODE);
    exit;
}
$maxBytes = 5 * 1024 * 1024;
if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(422);
    $msg = $_FILES['image']['error'] === UPLOAD_ERR_INI_SIZE ? 'حجم فایل بیش از حد مجاز سرور است' : 'خطا در دریافت فایل';
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_FILES['image']['size'] > $maxBytes) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'حجم تصویر باید حداکثر ۵ مگابایت باشد'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 🚀 ارسال multipart به اندپوینت آپلود سایت ساز */
$endpoint = BRANDMAKER_API . '/brand/' . BRAND_ID . '/upload-request-image';

$ch = null;
if (function_exists('curl_init')) {
    /* فایل موقت با ساختار CURLFile (بدون @deprecated) */
    $cf = new CURLFile($_FILES['image']['tmp_name'], $_FILES['image']['type'] ?: 'application/octet-stream', $_FILES['image']['name']);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['api_key' => BRAND_API_KEY, 'image' => $cf],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
} else {
    /* 🔄 fallback بدون cURL: ساخت بدنه multipart به‌صورت دستی */
    $boundary = '----SahandBoundary' . md5(uniqid('', true));
    $cType = $_FILES['image']['type'] ?: 'application/octet-stream';
    $name = basename((string)$_FILES['image']['name']);
    $content = (string)file_get_contents($_FILES['image']['tmp_name']);
    $body = '--' . $boundary . "\r\n"
        . 'Content-Disposition: form-data; name="api_key"' . "\r\n\r\n"
        . BRAND_API_KEY . "\r\n"
        . '--' . $boundary . "\r\n"
        . 'Content-Disposition: form-data; name="image"; filename="' . addslashes($name) . '"' . "\r\n"
        . 'Content-Type: ' . $cType . "\r\n\r\n"
        . $content . "\r\n"
        . '--' . $boundary . "--\r\n";
    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'content' => $body,
            'timeout' => 25,
            'header'  => "Content-Type: multipart/form-data; boundary={$boundary}\r\n",
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $response = @file_get_contents($endpoint, false, $context);
}

/* 📤 بازگرداندن پاسخ API به مرورگر */
$data = json_decode((string)$response, true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'خطای ارتباط با سرور سایت ساز'], JSON_UNESCAPED_UNICODE);
    exit;
}
http_response_code(!empty($data['success']) ? 200 : 422);
echo json_encode($data, JSON_UNESCAPED_UNICODE);
