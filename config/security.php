<?php
/**
 * ⚙️ امنیت، نشست و مدیریت خطا
 * ثابت‌های امنیتی + هندلر خطا + شروع امن نشست
 * 🧩 v2.43 (S12): از config.php تک‌عظیم تفکیک شد — config.php نقش Bootstrap دارد.
 * @package SahandBrandMaker\Config
 */

define('SAHAND_DEBUG', false);            // حالت دیباگ (در محیط عملیاتی خاموش بماند)
define('SESSION_LIFETIME', 7200);         // مدت اعتبار نشست ورود (ثانیه) — ۲ ساعت
define('MAX_LOGIN_ATTEMPTS', 5);          // حداکثر تلاش ناموفق ورود قبل از قفل
define('LOGIN_LOCK_MINUTES', 15);         // مدت قفل حساب پس از تلاش ناموفق (دقیقه)
define('API_RATE_LIMIT', 120);            // حداکثر درخواست API در دقیقه برای هر IP
/* 📦 UPLOAD_MAX_SIZE → config/storage.php | MAX_REQUEST_IMAGES → config/services.php */

/* --------------------------------------------------
 * ⚠️ مدیریت خطاها و استثناها — بهینه PHP ۸.۳
 * -------------------------------------------------- */
error_reporting(SAHAND_DEBUG ? E_ALL : (E_ALL & ~E_DEPRECATED & ~E_STRICT));
ini_set('display_errors', SAHAND_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

/* ⚡ عملکرد PHP ۸+ / ۸.۳:
 * - جدید: فقط برای PHP ۸ به بالا (نسخه هاست شما ۸.۳ است)
 * - zlib خروجی را فشرده می‌کند (اگر هاست اجازه دهد)
 * - گزارش وضعیت سرور در داشبورد: نسخه، GD، OPcache و ... */
if (PHP_VERSION_ID >= 80000) {
    // فشرده‌سازی خروجی HTML/JSON برای سرعت بیشتر (در صورت فعال نبودن در سطح سرور)
    if (!ini_get('zlib.output_compression') && !in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
        @ini_set('zlib.output_compression', '1');
    }
}

set_exception_handler(function ($e) {
    // ثبت خطا در لاگ
    @error_log('[EXCEPTION] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (SAHAND_DEBUG) {
        http_response_code(500);
        echo '<pre dir="ltr">⚠ ' . htmlspecialchars((string)$e) . '</pre>';
    } else {
        // 🎯 تشخیص درخواست AJAX: اندپوینت API یا هدر X-Requested-With
        $isAjax = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false
            || (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        if ($isAjax) {
            // پاسخ JSON برای درخواست‌های ای‌جکس (رفع خطای «Unexpected token '<'» در مرورگر)
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'خطای داخلی سرور: ' . mb_substr($e->getMessage(), 0, 200),
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><body style="font-family:Tahoma;background:#f5f5f5;display:flex;align-items:center;justify-content:center;height:100vh"><div style="text-align:center"><h2>⚠ خطای داخلی سرور</h2><p>لطفاً بعداً تلاش کنید یا با مدیر تماس بگیرید.</p></div></body></html>';
        }
    }
    exit;
});

/* --------------------------------------------------
 * 🕐 شروع امن نشست (Session)
 * -------------------------------------------------- */
if (session_status() === PHP_SESSION_NONE && !defined('SAHAND_NO_SESSION')) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('SAHANDSESS');
    session_start();
}
