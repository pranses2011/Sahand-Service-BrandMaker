<?php
/**
 * 🔑 تعیین رمز عبور جدید (v2.34)
 * ================================
 * مقصد لینک ایمیل‌شده بازیابی. توکن یک‌بارمصرف ۶۰ دقیقه‌ای است.
 * تغییر موفق رمز → 2FA کاربر نیز بازنشانی می‌شود (محافظت حساب ربوده‌شده).
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$auth = new Auth();

if ($auth->isLoggedIn()) {
    redirect('/admin/index.php');
}

$token = (string)($_GET['token'] ?? '');
$error = '';
$done = false;

if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
    $error = 'لینک بازیابی نامعتبر است — از لینک داخل ایمیل استفاده کنید.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) {
        $error = 'نشست شما منقضی شده است — دوباره تلاش کنید.';
    } else {
        $pass1 = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password2'] ?? '');
        if ($pass1 !== $pass2) {
            $error = 'تکرار رمز عبور با رمز اصلی یکسان نیست.';
        } else {
            $result = $auth->consumePasswordReset($token, $pass1);
            if ($result['ok']) {
                $done = true;
            } else {
                $error = $result['message'];
            }
        }
    }
}

$agencyName = Config::get(Config::KEY_AGENCY_NAME_FA) ?: SAHAND_NAME_FA;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>رمز عبور جدید | <?= e($agencyName) ?></title>
    <link rel="icon" href="<?= asset_url((string)Config::get(Config::KEY_AGENCY_FAVICON)) ?>">
    <link rel="stylesheet" href="<?= asset_ver('assets/css/admin.css') ?>">
    <style>
    .rp-page {
        min-height: 100vh; min-height: 100dvh;
        display: flex; align-items: center; justify-content: center; padding: 24px;
        background:
            radial-gradient(1100px 700px at 85% -10%, rgba(59,130,246,.20), transparent 60%),
            radial-gradient(900px 600px at 8% 110%, rgba(20,184,166,.16), transparent 55%),
            linear-gradient(160deg, #0b1220 0%, #101c36 55%, #0b1220 100%);
    }
    .rp-card {
        width: 100%; max-width: 460px;
        background: rgba(255,255,255,.98);
        border-radius: 20px; padding: 38px 34px 28px;
        box-shadow: 0 24px 70px rgba(2,8,23,.5);
        position: relative;
    }
    .rp-card::before {
        content: ''; position: absolute; top: 0; left: 24px; right: 24px; height: 4px;
        border-radius: 0 0 6px 6px;
        background: linear-gradient(90deg, #1e40af, #3b82f6 45%, #14b8a6);
    }
    .rp-head { text-align: center; margin-bottom: 22px; }
    .rp-head .big { font-size: 52px; margin-bottom: 8px; }
    .rp-head h1 { font-size: 19px; margin-bottom: 6px; }
    .rp-head .sub { color: var(--text-light, #64748b); font-size: 13px; line-height: 1.9; }
    .rp-label { display: block; font-size: 13.5px; font-weight: 700; margin: 0 0 8px; }
    .rp-field { margin-bottom: 16px; }
    .rp-input {
        width: 100%; box-sizing: border-box;
        padding: 13px 16px;
        border: 1.5px solid var(--border, #e2e8f0); border-radius: 12px;
        font-family: var(--font, Tahoma); font-size: 15px;
        background: #f8fafc; outline: none;
        transition: border-color .18s, box-shadow .18s;
    }
    .rp-input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 4px rgba(59,130,246,.14); }
    .rp-submit {
        width: 100%; margin-top: 10px; padding: 14px;
        border: none; border-radius: 12px;
        background: linear-gradient(135deg, #1d4ed8, #3b82f6);
        color: #fff; font-family: var(--font, Tahoma); font-size: 15.5px; font-weight: 800;
        cursor: pointer; box-shadow: 0 10px 24px rgba(29,78,216,.32);
        transition: transform .16s, filter .16s;
    }
    .rp-submit:hover { transform: translateY(-1.5px); filter: brightness(1.05); }
    .rp-alert {
        border-radius: 12px; padding: 12px 14px; font-size: 13px; line-height: 1.9;
        margin-bottom: 18px;
    }
    .rp-alert.err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    .rp-alert.ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
    .rp-meter { height: 6px; border-radius: 6px; background: #e2e8f0; margin-top: 8px; overflow: hidden; }
    .rp-meter > div { height: 100%; width: 0; border-radius: 6px; background: #ef4444; transition: width .25s, background .25s; }
    .rp-hint { font-size: 11.5px; color: #94a3b8; margin-top: 5px; }
    .rp-links { margin-top: 18px; padding-top: 14px; border-top: 1px dashed var(--border, #e2e8f0); text-align: center; font-size: 12.5px; }
    .rp-links a { color: #2563eb; text-decoration: none; font-weight: 700; }
    </style>
</head>
<body>
<div class="rp-page">
    <main class="rp-card">
        <?php if ($done): ?>
            <div class="rp-head">
                <div class="big">✅</div>
                <h1>رمز عبور تغییر کرد</h1>
                <div class="sub">حالا می‌توانید با رمز جدید وارد شوید.<br>اگر ورود دومرحله‌ای داشتید، هنگام ورود دوباره تنظیمش کنید.</div>
            </div>
            <p style="text-align:center;margin:6px 0 0">
                <a href="login.php" style="display:inline-block;background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;text-decoration:none;padding:13px 34px;border-radius:12px;font-weight:800;font-size:15px">ورود به پنل</a>
            </p>
        <?php else: ?>
            <div class="rp-head">
                <div class="big">🔑</div>
                <h1>تعیین رمز عبور جدید</h1>
                <div class="sub">رمز جدید خود را وارد کنید. حداقل ۸ کاراکتر، ترکیب حرف و عدد پیشنهاد می‌شود.</div>
            </div>

            <?php if ($error): ?>
                <div class="rp-alert err" role="alert"><?= e($error) ?></div>
                <?php if (mb_strpos($error, 'منقضی') !== false || mb_strpos($error, 'نامعتبر') !== false): ?>
                    <p style="text-align:center;font-size:13px"><a href="forgot-password.php" style="color:#2563eb">درخواست لینک بازیابی جدید →</a></p>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post" autocomplete="off" novalidate>
                <?= Auth::csrfField() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="rp-field">
                    <label class="rp-label" for="rp-pass1">رمز عبور جدید</label>
                    <input type="password" id="rp-pass1" class="rp-input" name="password" required minlength="8" autocomplete="new-password" autofocus>
                    <div class="rp-meter"><div id="rp-meter-fill"></div></div>
                    <div class="rp-hint" id="rp-strength">حداقل ۸ کاراکتر</div>
                </div>
                <div class="rp-field">
                    <label class="rp-label" for="rp-pass2">تکرار رمز عبور</label>
                    <input type="password" id="rp-pass2" class="rp-input" name="password2" required minlength="8" autocomplete="new-password">
                </div>
                <button type="submit" class="rp-submit">ثبت رمز جدید</button>
            </form>
        <?php endif; ?>
    </main>
</div>
<script>
(function () {
    var p1 = document.getElementById('rp-pass1');
    var fill = document.getElementById('rp-meter-fill');
    var label = document.getElementById('rp-strength');
    if (!p1 || !fill) { return; }
    function score(v) {
        var s = 0;
        if (v.length >= 8) { s++; }
        if (v.length >= 12) { s++; }
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) { s++; }
        if (/\d/.test(v)) { s++; }
        if (/[^A-Za-z0-9]/.test(v)) { s++; }
        return s;
    }
    p1.addEventListener('input', function () {
        var s = score(p1.value);
        var pct = [0, 20, 40, 60, 80, 100][s];
        var colors = ['#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16', '#22c55e'];
        var texts = ['خیلی ضعیف', 'ضعیف', 'متوسط', 'خوب', 'قوی', 'خیلی قوی'];
        fill.style.width = pct + '%';
        fill.style.background = colors[s];
        label.textContent = p1.value === '' ? 'حداقل ۸ کاراکتر' : 'قدرت رمز: ' + texts[s];
    });
})();
</script>
</body>
</html>
