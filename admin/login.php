<?php
/**
 * 🔐 صفحه ورود به پنل مدیریت
 * ============================
 * شامل: CAPTCHA تصویری، محدودیت تلاش، پیام‌های فارسی
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$auth = new Auth();

// ✅ اگر قبلاً وارد شده، به داشبورد هدایت شود
if ($auth->isLoggedIn()) {
    redirect('index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ بررسی توکن CSRF
    if (!Auth::verifyCsrf()) {
        $error = 'نشست شما منقضی شده است — دوباره تلاش کنید.';
    } else {
        $result = $auth->login(post('username'), (string)($_POST['password'] ?? ''), (string)($_POST['captcha'] ?? ''));
        if ($result['success']) {
            redirect('index.php');
        }
        $error = $result['message'];
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>ورود به پنل | <?= e(Config::get(Config::KEY_AGENCY_NAME_FA) ?: SAHAND_NAME_FA) ?></title>
    <link rel="stylesheet" href="<?= asset_ver('assets/css/admin.css') ?>">
</head>
<body>
<div class="login-page">
    <div class="login-box">
        <div class="logo">🏗️</div>
        <h1>ورود به سایت ساز برند</h1>
        <div class="sub"><?= e(Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'نمایندگی سهند سرویس') ?></div>

        <?php if ($error): ?>
            <div class="alert alert-danger">⚠️ <?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <?= Auth::csrfField() ?>
            <div class="form-group">
                <label>👤 نام کاربری</label>
                <input type="text" name="username" class="form-control" required autofocus value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>🔑 رمز عبور</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="form-group">
                <label>🔒 کد امنیتی</label>
                <div class="captcha-row">
                    <input type="text" name="captcha" class="form-control" required maxlength="5" placeholder="کد را وارد کنید" style="direction:ltr;text-align:center;letter-spacing:4px;font-size:17px;font-weight:700;min-width:120px" inputmode="latin" autocomplete="off">
                    <img src="security-code.php" alt="کد امنیتی" id="captcha-img" width="220" height="72" onclick="refreshLoginCaptcha()" title="برای تغییر کلیک کنید" style="background:#f3f4f6;border-radius:10px">
                    <button type="button" class="captcha-refresh" onclick="refreshLoginCaptcha()" title="تولید کد جدید" aria-label="تولید کد جدید">🔄</button>
                </div>
                <div class="hint" id="captcha-hint" style="display:none">🔍 نمایش مستقیم توسط مرورگر مسدود شد — حالت جایگزین فعال شد.</div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block">🚀 ورود به پنل</button>
        </form>

        <div style="text-align:center;margin-top:20px;font-size:11.5px;color:var(--text-light)">
            🛡️ ورود از طریق SSL محافظت می‌شود
        </div>
    </div>
</div>
<script src="<?= asset_ver('assets/js/admin.js') ?>"></script>
<script>
/* 🔒 کپچای ضد-بلاک — سه لایه:
   ۱) اندپوینت با نام خنثی security-code.php (ادبلاکرها واژه captcha را بلاک می‌کنند)
   ۲) اگر تصویر مستقیم بلاک شد → fetch با AJAX و data-URI (هیچ بلاکر تصویری نمی‌تواند fetch/JSON را بلاک کند)
   ۳) رفرش مستقل با هر دو لایه */
function captchaFallback(img) {
    if (img.dataset.fb === '1') { return; }
    img.dataset.fb = '1';
    var hint = document.getElementById('captcha-hint');
    fetch('security-code.php?ajax=1', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.success && res.data_uri) {
                img.src = res.data_uri;
                if (hint) { hint.style.display = 'block'; }
            }
        })
        .catch(function () { /* سکوت — کاربر با رفرش می‌تواند دوباره تلاش کند */ });
}
function refreshLoginCaptcha() {
    var img = document.getElementById('captcha-img');
    if (!img) { return; }
    img.dataset.fb = '';
    img.src = 'security-code.php?t=' + Date.now();
}
document.addEventListener('DOMContentLoaded', function () {
    var img = document.getElementById('captcha-img');
    if (img) { img.addEventListener('error', function () { captchaFallback(img); }); }
});
</script>
</body>
</html>
