<?php
/**
 * 🖼️ اندپوینت آپلود تصویر پیوست درخواست (v2.30)
 * =================================================
 * فرم سایت برند ابتدا تصاویر را از طریق پروکسی همان‌مبدأ
 * (js/image-upload.php) به این اندپوینت می‌فرستد، URL مطلق تصویر را
 * می‌گیرد و سپس فرم را همراه آرایه images ثبت می‌کند — در ثبت درخواست،
 * پیوست‌ها با request_id واقعی در جدول ذخیره می‌شوند (بدون نقض FK).
 *
 * 🚨 چرا این اندپوینت حیاتی است؟ قبلاً فیلد فایل فرم با FormData خوانده
 * می‌شد و File در JSON به {} تبدیل می‌شد + پروکسی form-submit فیلد
 * images را در لیست سفید نداشت → تصاویر هرگز به پنل نمی‌رسیدند.
 *
 * احراز هویت: api_key در بدنه فرم (همندپوینت request)
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

function api_upload_request_image(int $urlBrandId): void
{
    $db = Database::getInstance();

    /* 🔑 احراز هویت با کلید API فرم (مثل درخواست) */
    $apiKey = (string)($_POST['api_key'] ?? '');
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

    /* 📥 فایل آپلودشده */
    $file = $_FILES['image'] ?? null;
    if (!$file || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(['success' => false, 'error' => 'فایلی دریافت نشد یا خطای آپلود رخ داد'], 422);
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        json_response(['success' => false, 'error' => 'فایل نامعتبر است'], 422);
    }

    /* 📏 محدودیت حجم: ۵ مگابایت */
    $maxBytes = 5 * 1024 * 1024;
    if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
        json_response(['success' => false, 'error' => 'حجم تصویر باید بین ۱ کیلوبایت تا ۵ مگابایت باشد'], 422);
    }

    /* 🧬 اعتبارسنجی نوع واقعی فایل (نه پسوند) */
    $ext = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    if (!isset($allowed[$ext])) {
        json_response(['success' => false, 'error' => 'فرمت مجاز: JPG، PNG، WebP، GIF'], 422);
    }
    $validImage = false;
    if (function_exists('getimagesize')) {
        $info = @getimagesize($file['tmp_name']);
        $validImage = is_array($info)
            && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)
            && ($info[0] > 0 && $info[0] <= 6000 && $info[1] > 0 && $info[1] <= 6000);
    } elseif (function_exists('finfo_open')) {
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $validImage = in_array($mime, $allowed, true);
    }
    if (!$validImage) {
        json_response(['success' => false, 'error' => 'محتوای فایل تصویر معتبر نیست'], 422);
    }

    /* 📁 ذخیره در پوشه اختصاصی برند با نام تصادفی */
    $relDir = 'uploads/requests/brand-' . (int)$brand['id'];
    $absDir = ROOT_PATH . '/' . $relDir;
    if (!is_dir($absDir)) {
        @mkdir($absDir, 0755, true);
    }

    /* 🛡️ محدودیت نرخ بدون دیتابیس: حداکثر ۱۵ فایل در ساعت از هر IP
       (شمارش فایل‌های اخیر همین پوشه برند — سبک و بدون FK) */
    $ip = Logger::clientIp();
    $recentCount = 0;
    $hourAgo = time() - 3600;
    foreach (glob($absDir . '/req_*.*') ?: [] as $existing) {
        if (@filemtime($existing) >= $hourAgo) { $recentCount++; }
    }
    if ($recentCount >= 15) {
        json_response(['success' => false, 'error' => 'تعداد تصاویر ارسالی شما در این ساعت به حداکثر رسیده است'], 429);
    }

    $newName = 'req_' . substr(hash('sha256', $ip . microtime(true) . random_bytes(4)), 0, 20) . '.' . $ext;
    $relPath = $relDir . '/' . $newName;
    $absPath = ROOT_PATH . '/' . $relPath;

    if (!@move_uploaded_file($file['tmp_name'], $absPath)) {
        /* 🔄 fallback: کپی (برخی محیط‌های پروکسی‌شده move را رد می‌کنند) */
        if (!@copy($file['tmp_name'], $absPath)) {
            json_response(['success' => false, 'error' => 'ذخیره فایل روی سرور ناموفق بود (مجوز پوشه uploads را بررسی کنید)'], 500);
        }
    }
    @chmod($absPath, 0644);

    /* 🔗 آدرس مطلق برای نمایش در پنل + ارسال به کانال‌ها */
    $url = BASE_URL . '/' . $relPath;

    json_response([
        'success' => true,
        'data'    => [
            'url'  => $url,
            'path' => $relPath,
            'size' => (int)$file['size'],
        ],
    ]);
}
