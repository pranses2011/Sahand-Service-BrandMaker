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
                    <input type="text" name="captcha" class="form-control" required maxlength="5" placeholder="کد را وارد کنید" style="direction:ltr;text-align:center;letter-spacing:3px">
                    <img src="captcha.php" alt="کد امنیتی" onclick="refreshCaptcha(this)" title="برای تغییر کلیک کنید">
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block">🚀 ورود به پنل</button>
        </form>

        <div style="text-align:center;margin-top:20px;font-size:11.5px;color:var(--text-light)">
            🛡️ ورود از طریق SSL محافظت می‌شود
        </div>
    </div>
</div>
<script src="<?= asset_ver('assets/js/admin.js') ?>"></script>
</body>
</html>
