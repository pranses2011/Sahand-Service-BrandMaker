<?php
/**
 * 🔐 مرحله دوم ورود دومرحله‌ای (v2.34)
 * =====================================
 * بعد از رمز درست، اگر TOTP فعال باشد کاربر به این صفحه می‌آید.
 * ورودی: کد ۶ رقمی اپ احرازکننده یا یکی از کدهای بازیابی (XXXX-XXXX).
 * گزینه «این دستگاه را به خاطر بسپار» — کوکی امضاشده ۳۰ روزه (HMAC).
 * محدودیت: ۵ تلاش ناموفق / ۱۰ دقیقه (در Auth::verifyTwoFactor).
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$auth = new Auth();

// ✅ اگر از قبل کامل وارد شده — نیازي به این صفحه نیست
if ($auth->isLoggedIn()) {
    redirect('index.php');
}

// 🔑 اگر مرحله دومرحله‌ای معلقی در کار نیست → برگرد به لاگین
if ($auth->pendingTwoFactorId() < 1) {
    redirect('login.php');
}

$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) {
        $error = 'نشست شما منقضی شده است — دوباره تلاش کنید.';
    } else {
        $code = (string)($_POST['totp_code'] ?? '');
        $remember = !empty($_POST['remember_device']);
        $result = $auth->verifyTwoFactor($code, $remember);
        if ($result['success']) {
            if (!empty($result['used_recovery'])) {
                Logger::activity((int)$result['user']['id'], 'ورود با کد بازیابی', 'کد بازیابی 2FA مصرف شد');
            }
            redirect('index.php');
        }
        $error = $result['message'];
    }
}

$agencyName = Config::get(Config::KEY_AGENCY_NAME_FA) ?: SAHAND_NAME_FA;
$agencyLogo = (string)(Config::get(Config::KEY_AGENCY_LOGO) ?: '');
$logoUrl = $agencyLogo !== '' ? asset_url($agencyLogo) : '';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>کد دومرحله‌ای | <?= e($agencyName) ?></title>
    <link rel="icon" href="<?= asset_url((string)Config::get(Config::KEY_AGENCY_FAVICON)) ?>">
    <link rel="stylesheet" href="<?= asset_ver('assets/css/admin.css') ?>">
    <style>
    .twofa-page {
        min-height: 100vh; min-height: 100dvh;
        display: flex; align-items: center; justify-content: center;
        padding: 24px;
        background: #0b1220;
        background:
            radial-gradient(1100px 700px at 85% -10%, rgba(59,130,246,.20), transparent 60%),
            radial-gradient(900px 600px at 8% 110%, rgba(20,184,166,.16), transparent 55%),
            linear-gradient(160deg, #0b1220 0%, #101c36 55%, #0b1220 100%);
    }
    .twofa-card {
        width: 100%; max-width: 440px;
        background: rgba(255,255,255,.98);
        border-radius: 20px; padding: 38px 34px 28px;
        box-shadow: 0 24px 70px rgba(2,8,23,.5), 0 2px 8px rgba(2,8,23,.25);
        position: relative;
        animation: twofaRise .5s cubic-bezier(.22,.9,.36,1) both;
    }
    @keyframes twofaRise { from { opacity: 0; transform: translateY(14px) } to { opacity: 1; transform: none } }
    .twofa-card::before {
        content: ''; position: absolute; top: 0; left: 24px; right: 24px; height: 4px;
        border-radius: 0 0 6px 6px;
        background: linear-gradient(90deg, #1e40af, #3b82f6 45%, #14b8a6);
    }
    .twofa-head { text-align: center; margin-bottom: 24px; }
    .twofa-head .ico {
        width: 74px; height: 74px; margin: 0 auto 14px;
        border-radius: 22px; background: linear-gradient(140deg, #1e40af, #3b82f6);
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 10px 26px rgba(30,64,175,.35);
    }
    .twofa-head h1 { font-size: 19px; margin-bottom: 6px; }
    .twofa-head .sub { color: var(--text-light, #64748b); font-size: 13px; line-height: 1.9; }
    .twofa-input {
        width: 100%; box-sizing: border-box;
        border: 1.5px solid var(--border, #e2e8f0); border-radius: 12px;
        background: #f8fafc; outline: none;
        font-size: 30px; font-weight: 800; letter-spacing: 12px; text-align: center;
        direction: ltr; color: var(--text, #0f172a);
        padding: 14px; min-height: 72px;
        transition: border-color .18s, box-shadow .18s;
    }
    .twofa-input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 4px rgba(59,130,246,.14); }
    .twofa-input::placeholder { font-size: 13px; letter-spacing: 0; font-weight: 400; }
    .twofa-remember {
        display: flex; align-items: center; gap: 8px; margin: 16px 2px 4px;
        font-size: 13px; color: var(--text-light, #64748b); cursor: pointer; user-select: none;
    }
    .twofa-remember input { width: 16px; height: 16px; accent-color: #2563eb; cursor: pointer; }
    .twofa-submit {
        width: 100%; margin-top: 14px; padding: 14.5px 16px;
        border: none; border-radius: 12px;
        background: linear-gradient(135deg, #1d4ed8, #3b82f6);
        color: #fff; font-family: var(--font, Tahoma); font-size: 16px; font-weight: 800;
        cursor: pointer;
        box-shadow: 0 10px 24px rgba(29,78,216,.32);
        transition: transform .16s, filter .16s;
    }
    .twofa-submit:hover { transform: translateY(-1.5px); filter: brightness(1.05); }
    .twofa-alert {
        display: flex; gap: 8px; align-items: flex-start;
        background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;
        border-radius: 12px; padding: 12px 14px; font-size: 13px; line-height: 1.8;
        margin-bottom: 18px;
    }
    .twofa-links { margin-top: 20px; padding-top: 15px; border-top: 1px dashed var(--border, #e2e8f0); text-align: center; font-size: 12.5px; }
    .twofa-links a { color: #2563eb; text-decoration: none; }
    .twofa-links a:hover { text-decoration: underline; }
    .twofa-recovery-hint {
        margin-top: 12px; font-size: 12px; color: #92400e; background: #fffbeb;
        border: 1px solid #fde68a; border-radius: 10px; padding: 8px 12px; line-height: 1.9;
    }
    </style>
</head>
<body>
<div class="twofa-page">
    <main class="twofa-card">
        <div class="twofa-head">
            <div class="ico">
                <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="لوگو" style="width:100%;height:100%;object-fit:contain"><?php else: ?>
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <?php endif; ?>
            </div>
            <h1>کد دومرحله‌ای را وارد کنید</h1>
            <div class="sub">کد ۶ رقمی را از اپ احرازکننده خود (گوگل آتنتیکیتور و مشابه) وارد نمایید.</div>
        </div>

        <?php if ($error): ?>
            <div class="twofa-alert" role="alert">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:3px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off" novalidate>
            <?= Auth::csrfField() ?>
            <input type="text" class="twofa-input" name="totp_code" id="totp-code" required
                   maxlength="10" inputmode="numeric" autocomplete="one-time-code"
                   placeholder="------" autofocus
                   pattern="[0-9A-Za-z\-]{4,10}">
            <label class="twofa-remember">
                <input type="checkbox" name="remember_device" value="1">
                این دستگاه را به مدت ۳۰ روز به خاطر بسپار
            </label>
            <button type="submit" class="twofa-submit">تأیید و ورود</button>
            <div class="twofa-recovery-hint">
                💡 اگر به اپ احرازکننده دسترسی ندارید، یکی از <b>کدهای بازیابی</b> (به شکل XXXX-XXXX) را که هنگام فعال‌سازی ذخیره کرده بودید وارد کنید.
            </div>
        </form>

        <div class="twofa-links">
            <a href="login.php">← بازگشت به صفحه ورود</a>
            <span style="color:#cbd5e1"> · </span>
            <a href="forgot-password.php">دسترسی به اپ ندارید؟</a>
        </div>
    </main>
</div>
<script>
// پاک‌سازی ورودی: فقط رقم/حرف بزرگ و خط تیره + انتقال خودکار فوکوس
(function () {
    var inp = document.getElementById('totp-code');
    if (!inp) { return; }
    inp.addEventListener('input', function () {
        var v = inp.value.toUpperCase().replace(/[^0-9A-Z\-]/g, '');
        if (v !== inp.value) { inp.value = v; }
    });
    inp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { inp.form.submit(); }
    });
})();
</script>
</body>
</html>
