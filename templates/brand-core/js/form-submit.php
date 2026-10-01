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
   قبلاً این فیلد در لیست سفید نبود → تصاویر هرگز به API نمی‌رسیدند!
   🚨 v2.43 — ریشه قطعی «اطلاعات فرم‌ها ارسال نمیشه و خطای ارتباط با
   سرور میده»: فرم‌های عمومی قالب‌ساز (تماس/خبرنامه/نظرسنجی/...) فقط
   data.fields را می‌فرستند و هیچ فیلد سطح‌بالا ندارند → $payload خالی
   → «فیلدی برای ثبت ارسال نشده است» با کد 400 → مرورگر (که 400 را در
   زنجیره مجاز 422/429 ندارد) آن را catch می‌کرد → «خطای ارتباط با سرور»!
   اکنون: ① فیلدهای استاندارد از data.fields هم برداشته می‌شوند
   ② بررسی خالی بودن «پس از» ادغام دو منبع انجام می‌شود. */

/* ═══ 🆕 v2.45 — پروکسی دیدگاه مقالات (رفع قطعی «خطای ارتباط با سرور») ═══
   🚨 ریشه: فرم دیدگاه صفحه مقاله تا v2.44 مستقیم از مرورگر به API پنل
   (مبدأ متفاوت) fetch با Content-Type: application/json می‌زد → مرورگر
   پیش‌فرض OPTIONS (preflight) می‌فرستاد → روتر API هیچ مسیری با متد
   OPTIONS ثبت نکرده → 404 بدون هدرهای CORS → fetch رد می‌شد →
   «خطای ارتباط با سرور». دیدگاه‌ها روی همه سایت‌های برند قطعی بودند!
   راه‌حل: همان الگوی موفق فرم‌ها — POST همان‌مبدأ به همین پروکسی. */
if (($data['action'] ?? '') === 'comment') {
    $commentPayload = [
        'article_slug' => preg_replace('/[^a-zA-Z0-9\-_%\x{0600}-\x{06FF}]/u', '', mb_substr((string)($data['article_slug'] ?? ''), 0, 190)),
        'name'    => mb_substr(trim((string)($data['name'] ?? '')), 0, 120),
        'email'   => mb_substr(trim((string)($data['email'] ?? '')), 0, 190),
        'body'    => mb_substr(trim((string)($data['body'] ?? '')), 0, 3000),
        'website' => mb_substr((string)($data['website'] ?? ''), 0, 200), /* honeypot */
    ];
    if ($commentPayload['article_slug'] === '' || mb_strlen($commentPayload['name']) < 2 || mb_strlen($commentPayload['body']) < 5) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'نام و متن دیدگاه را کامل وارد کنید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    /* postToAPI خودش api_key برند را ضمیمه می‌کند */
    $response = postToAPI('brand/' . BRAND_ID . '/comment', $commentPayload);
    http_response_code(!empty($response['success']) ? 200 : 422);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

$allowed = ['full_name', 'phone', 'phone2', 'address', 'device_type', 'device_other',
            'device_model', 'description', 'preferred_date', 'preferred_time', 'images'];
$payload = [];
foreach ($allowed as $field) {
    if (array_key_exists($field, $data)) {
        $payload[$field] = is_string($data[$field]) ? mb_substr(trim($data[$field]), 0, 2000) : $data[$field];
    }
}
/* 🩹 v2.43 — فیلدهای استانداردِ داخل data.fields هم به سطح بالا بیایند
   (فرم‌های عمومی قالب‌ساز همه چیز را داخل fields می‌فرستند) */
if (is_array($data['fields'] ?? null)) {
    foreach ($allowed as $field) {
        if (!array_key_exists($field, $payload) && isset($data['fields'][$field]) && is_string($data['fields'][$field])) {
            $payload[$field] = mb_substr(trim((string)$data['fields'][$field]), 0, 2000);
        }
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
/* 🚨 v2.43 — بررسی «خالی» فقط برای فرم درخواست کامل است؛ فرم‌های عمومی
   (form_block) ممکن است فقط فیلدهای آزاد مثل email داشته باشند و قبل
   از این رد می‌شدند (ریشه «خطای ارتباط با سرور» فرم‌ها). */
$hasFormBlock = !empty($data['form_block']) && is_string($data['form_block']);
$hasFields = is_array($data['fields'] ?? null) && !empty(array_filter(array_map('strval', (array)$data['fields'])));
if (empty($payload) && !($hasFormBlock && $hasFields)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'فیلدی برای ثبت ارسال نشده است'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ═══ 🆕 v2.31 — فرم‌های عمومی قالب‌ساز (sahand-form) ═══
   اگر form_block ارسال شده باشد، درخواست از مسیر فرم‌ها می‌رود؛
   در غیر این صورت رفتار قبلی (فرم درخواست کامل صفحه /request) حفظ می‌شود. */
if ($hasFormBlock) {
    $formBlock = preg_replace('/[^a-z0-9_\-]/', '', (string)$data['form_block']);
    if ($formBlock === '') { $formBlock = 'custom'; }
    $formPayload = ['form_block' => $formBlock, 'fields' => []];
    /* 🧩 v2.44 (S12) — شناسه فرم سفارشی فرم‌ساز */
    $cfSlug = preg_replace('/[^a-z0-9\-_]/', '', (string)($data['form_slug'] ?? ''));
    if ($cfSlug !== '') { $formPayload['form_slug'] = $cfSlug; }
    /* فیلدها: کلیدهای امن کوتاه + مقدار متن محدود */
    if (is_array($data['fields'] ?? null)) {
        foreach ($data['fields'] as $fk => $fv) {
            $fk = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$fk);
            if ($fk === '' || strlen($fk) > 40) { continue; }
            $formPayload['fields'][$fk] = is_string($fv) ? mb_substr($fv, 0, 2000) : '';
        }
    }
    /* 🩹 v2.44 (S03) — فیلدهای سطح‌بالا هم داخل fields بیایند:
       ریشه باقیمانده «خطای ارتباط با سرور / فرم خالی است» — کلاینت‌هایی که
       فیلدهای استاندارد را فقط در سطح بالا می‌فرستند (مثل صفحه /request قدیمی
       یا فرم‌های سفارشی)، data.fields ندارند → fields خالی می‌رفت → 422.
       اکنون $payload (فیلدهای استاندارد استخراج‌شده قبلاً) با fields ادغام
       می‌شود؛ اولویت با data.fields است (مقادیر تازه‌تر). */
    foreach ($payload as $pk => $pv) {
        if ($pk !== 'images' && !isset($formPayload['fields'][$pk]) && is_string($pv)) {
            $formPayload['fields'][$pk] = $pv;
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
