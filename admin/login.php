<?php
/**
 * 🔐 صفحه ورود به پنل مدیریت — طراحی با اسکیل UI/UX Pro (v2.7)
 * ==================================================================
 * اصول اعمال‌شده از uiux-patterns.json:
 *   - نظام فاصله ۴/۸ پیکسل (۴،۸،۱۲،۱۶،۲۴،۳۲،۴۸)
 *   - نسبت رنگ ۶۰-۳۰-۱۰ (خنثی + برند + تأکید فقط برای CTA)
 *   - گردی یکنواخت کارت ۱۸px / دکمه ۱۲px
 *   - Mobile-First + prefers-reduced-motion
 *   - مسیر چشم F شکل: لوگو → عنوان → فرم → دکمه
 *   - کپچای درون‌خطی data-URI (صفر درخواست HTTP → رفع کامل ۴۰۴/ادبلاکر)
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

// 🖼️ کپچای درون‌خطی — داخل خود HTML رندر می‌شود؛ هیچ درخواست تصویری وجود ندارد
$captchaUri  = Auth::captchaInlineDataUri();
$agencyName  = Config::get(Config::KEY_AGENCY_NAME_FA) ?: SAHAND_NAME_FA;
$agencySlogan = Config::get(Config::KEY_AGENCY_SLOGAN_FA) ?: '';
$agencyLogo  = (string)(Config::get(Config::KEY_AGENCY_LOGO) ?: '');
$logoUrl     = $agencyLogo !== '' ? asset_url($agencyLogo) : '';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>ورود به پنل | <?= e($agencyName) ?></title>
    <link rel="icon" href="<?= asset_url((string)Config::get(Config::KEY_AGENCY_FAVICON)) ?>">
    <link rel="stylesheet" href="<?= asset_ver('assets/css/admin.css') ?>">
    <style>
    /* 🔐 استایل مستقل صفحه ورود — نسخه UI/UX Pro v2.7 */
    .login-page {
        min-height: 100vh; min-height: 100dvh;
        display: flex; align-items: center; justify-content: center;
        padding: 24px;
        position: relative; overflow: hidden;
        background: #0b1220;
        background:
            radial-gradient(1100px 700px at 85% -10%, rgba(59,130,246,.20), transparent 60%),
            radial-gradient(900px 600px at 8% 110%, rgba(20,184,166,.16), transparent 55%),
            linear-gradient(160deg, #0b1220 0%, #101c36 55%, #0b1220 100%);
    }
    /* 💫 حباب‌های نرم پس‌زمینه (فقط دکور مه‌آلود — با reduced-motion خاموش) */
    .login-page::before, .login-page::after {
        content: ''; position: absolute; border-radius: 50%; filter: blur(70px); opacity: .5;
        pointer-events: none;
    }
    .login-page::before { width: 420px; height: 420px; background: rgba(59,130,246,.14); top: -140px; inset-inline-end: -110px; animation: loginFloat 16s ease-in-out infinite alternate; }
    .login-page::after  { width: 360px; height: 360px; background: rgba(20,184,166,.12); bottom: -130px; inset-inline-start: -100px; animation: loginFloat 19s ease-in-out infinite alternate-reverse; }
    @keyframes loginFloat { from { transform: translate3d(0,0,0) } to { transform: translate3d(-34px, 30px, 0) } }

    .login-card {
        position: relative; z-index: 1;
        width: 100%; max-width: 440px;
        background: rgba(255,255,255,.97);
        border-radius: 18px;
        padding: 40px 36px 32px;
        box-shadow: 0 24px 70px rgba(2,8,23,.5), 0 2px 8px rgba(2,8,23,.25);
        animation: loginRise .5s cubic-bezier(.22,.9,.36,1) both;
    }
    @keyframes loginRise { from { opacity: 0; transform: translateY(14px) } to { opacity: 1; transform: none } }

    /* نوار برند بالای کارت */
    .login-card::before {
        content: ''; position: absolute; top: 0; left: 24px; right: 24px; height: 4px;
        border-radius: 0 0 6px 6px;
        background: linear-gradient(90deg, #1e40af, #3b82f6 45%, #14b8a6);
    }

    .login-brand { text-align: center; margin-bottom: 28px; }
    .login-logo {
        width: 76px; height: 76px; margin: 0 auto 16px;
        border-radius: 20px;
        background: linear-gradient(140deg, #1e40af, #3b82f6);
        box-shadow: 0 10px 26px rgba(30,64,175,.35), inset 0 1px 0 rgba(255,255,255,.25);
        display: flex; align-items: center; justify-content: center;
        font-size: 36px; overflow: hidden;
    }
    .login-logo img { width: 100%; height: 100%; object-fit: contain; }
    .login-brand h1 { font-size: 19px; margin-bottom: 6px; }
    .login-brand .sub { color: var(--text-light); font-size: 13px; line-height: 1.8; }

    .login-alert {
        display: flex; align-items: flex-start; gap: 8px;
        background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;
        border-radius: 12px; padding: 12px 14px; font-size: 13px; line-height: 1.8;
        margin-bottom: 20px;
    }
    .login-alert svg { flex-shrink: 0; margin-top: 3px; }

    .login-field { position: relative; margin-bottom: 16px; }
    .login-field label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 8px; }
    .login-field .input-icon {
        position: absolute; inset-inline-start: 13px; top: 41px;
        width: 20px; height: 20px; color: #94a3b8; pointer-events: none;
        transition: color .18s;
    }
    .login-field input {
        width: 100%; box-sizing: border-box;
        padding: 12px 42px 12px 12px;
        border: 1.5px solid var(--border); border-radius: 12px;
        font-family: var(--font); font-size: 15px; color: var(--text);
        background: #f8fafc;
        transition: border-color .18s, box-shadow .18s, background .18s;
    }
    .login-field input:focus {
        outline: none; background: #fff;
        border-color: #3b82f6;
        box-shadow: 0 0 0 4px rgba(59,130,246,.14);
    }
    .login-field:focus-within .input-icon { color: #3b82f6; }
    .login-eye {
        position: absolute; inset-inline-end: 6px; top: 35px;
        width: 36px; height: 36px; border: none; background: none; border-radius: 9px;
        cursor: pointer; color: #94a3b8; display: flex; align-items: center; justify-content: center;
        transition: background .15s, color .15s;
    }
    .login-eye:hover { background: #eef2ff; color: #475569; }

    /* 🖼️ کپچا — تصویر درون‌خطی (بدون درخواست شبکه) */
    .login-captcha {
        display: flex; gap: 8px; align-items: stretch;
        background: #f8fafc; border: 1.5px solid var(--border); border-radius: 12px;
        padding: 8px; transition: border-color .18s, box-shadow .18s;
    }
    .login-captcha:focus-within { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59,130,246,.14); }
    .login-captcha img {
        border-radius: 8px; height: 72px; width: 220px; max-width: 58%;
        cursor: pointer; flex-shrink: 0; background: #f3f4f6;
        transition: transform .15s;
    }
    .login-captcha img:active { transform: scale(.985); }
    .login-captcha input {
        flex: 1; min-width: 0; border: none; background: none; outline: none;
        font-family: var(--font); font-size: 17px; font-weight: 800;
        letter-spacing: 5px; text-align: center; color: var(--text);
        direction: ltr; text-transform: uppercase;
    }
    .captcha-refresh {
        align-self: center; width: 40px; height: 40px; flex-shrink: 0;
        border: 1px solid var(--border); background: #fff; border-radius: 10px;
        cursor: pointer; color: #64748b; font-size: 16px;
        display: flex; align-items: center; justify-content: center;
        transition: background .15s, color .15s, transform .4s;
    }
    .captcha-refresh:hover { background: #eef2ff; color: #2563eb; }
    .captcha-refresh.spin { transform: rotate(360deg); }

    .login-submit {
        width: 100%; margin-top: 8px;
        padding: 13.5px 16px;
        border: none; border-radius: 12px;
        background: linear-gradient(135deg, #1d4ed8, #3b82f6);
        color: #fff; font-family: var(--font); font-size: 15.5px; font-weight: 800;
        cursor: pointer;
        box-shadow: 0 10px 24px rgba(29,78,216,.32), inset 0 1px 0 rgba(255,255,255,.22);
        transition: transform .16s, box-shadow .16s, filter .16s;
        display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .login-submit:hover { transform: translateY(-1.5px); filter: brightness(1.05); box-shadow: 0 14px 30px rgba(29,78,216,.4), inset 0 1px 0 rgba(255,255,255,.22); }
    .login-submit:active { transform: translateY(0); }
    .login-submit[disabled] { opacity: .65; cursor: wait; transform: none; }
    .login-submit .spinner {
        width: 16px; height: 16px; border: 2.5px solid rgba(255,255,255,.35);
        border-top-color: #fff; border-radius: 50%; animation: loginSpin .7s linear infinite; display: none;
    }
    .login-submit.loading .spinner { display: block; }
    @keyframes loginSpin { to { transform: rotate(360deg) } }

    .login-foot {
        margin-top: 24px; padding-top: 16px; border-top: 1px dashed var(--border);
        display: flex; align-items: center; justify-content: center; gap: 6px;
        color: var(--text-light); font-size: 12px;
    }
    .login-foot .dot { width: 5px; height: 5px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.18); }
    .login-hint { display: none; margin-top: 8px; font-size: 12px; color: #b45309; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 6px 10px; text-align: center; }

    @media (max-width: 480px) {
        .login-card { padding: 32px 20px 24px; }
        .login-captcha img { width: 170px; height: 64px; }
        .login-captcha input { font-size: 15px; letter-spacing: 4px; }
    }
    @media (prefers-reduced-motion: reduce) {
        .login-page::before, .login-page::after, .login-card { animation: none; }
        .login-submit, .login-submit:hover { transform: none; }
    }
    </style>
</head>
<body>
<div class="login-page">
    <main class="login-card">
        <div class="login-brand">
            <div class="login-logo">
                <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="لوگو"><?php else: ?>🏗️<?php endif; ?>
            </div>
            <h1>ورود به سایت ساز برند</h1>
            <div class="sub"><?= e($agencyName) ?><?= $agencySlogan !== '' ? ' — ' . e($agencySlogan) : '' ?></div>
        </div>

        <?php if ($error): ?>
            <div class="login-alert" role="alert">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off" novalidate>
            <?= Auth::csrfField() ?>

            <div class="login-field">
                <label for="login-username">نام کاربری</label>
                <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <input type="text" id="login-username" name="username" required autofocus
                       value="<?= e($_POST['username'] ?? '') ?>" autocomplete="username">
            </div>

            <div class="login-field">
                <label for="login-password">رمز عبور</label>
                <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <input type="password" id="login-password" name="password" required autocomplete="current-password">
                <button type="button" class="login-eye" onclick="loginTogglePassword(this)" aria-label="نمایش/پنهان رمز" tabindex="-1">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>

            <div class="login-field">
                <label for="login-captcha-input">کد امنیتی</label>
                <div class="login-captcha">
                    <img src="<?= e($captchaUri) ?>" alt="کد امنیتی" id="captcha-img" width="220" height="72"
                         onclick="refreshLoginCaptcha()" title="برای تغییر کلیک کنید">
                    <input type="text" id="login-captcha-input" name="captcha" required maxlength="5"
                           placeholder="کد ۵ حرفی" inputmode="latin" autocomplete="off" autocapitalize="characters">
                    <button type="button" class="captcha-refresh" onclick="refreshLoginCaptcha(this)" title="تولید کد جدید" aria-label="تولید کد جدید">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    </button>
                </div>
                <div class="login-hint" id="captcha-hint">تولید کد جدید ناموفق بود — لطفاً دوباره تلاش کنید.</div>
            </div>

            <button type="submit" class="login-submit" id="login-submit">
                <span class="spinner"></span>
                <span>ورود به پنل مدیریت</span>
            </button>
        </form>

        <div class="login-foot">
            <span class="dot"></span>
            <span>اتصال امن SSL</span>
            <span>·</span>
            <span>نسخه <?= e(SAHAND_VERSION) ?></span>
        </div>
    </main>
</div>
<script>
/* 👁 نمایش/پنهان رمز عبور */
function loginTogglePassword(btn) {
    var input = document.getElementById('login-password');
    if (!input) { return; }
    input.type = input.type === 'password' ? 'text' : 'password';
    btn.style.color = input.type === 'text' ? '#2563eb' : '';
}

/* 🔄 رفرش کپچا — زنجیره سه‌مرحله‌ای ضدشکست (همه با آدرس مطلق):
   ۱) fetch اندپوینت خنثی security-code.php (JSON/data-URI)
   ۲) در شکست → fetch همان از captcha.php
   ۳) در شکست → تصویر مستقیم security-code.php (کش‌شکن با پارامتر زمان) */
var LOGIN_CAPTCHA_URLS = [
    '<?= e(BASE_URL) ?>/admin/security-code.php?ajax=1',
    '<?= e(BASE_URL) ?>/admin/captcha.php?ajax=1'
];
function refreshLoginCaptcha(btn) {
    var img = document.getElementById('captcha-img');
    if (!img) { return; }
    if (btn) { btn.classList.add('spin'); setTimeout(function () { btn.classList.remove('spin'); }, 450); }
    var hint = document.getElementById('captcha-hint');
    var tryIndex = 0;
    function attempt() {
        if (tryIndex >= LOGIN_CAPTCHA_URLS.length) {
            // 🛟 آخرین لایه: تصویر مستقیم با کش‌شکن
            img.src = '<?= e(BASE_URL) ?>/admin/security-code.php?t=' + Date.now();
            if (hint) { hint.style.display = 'block'; }
            return;
        }
        fetch(LOGIN_CAPTCHA_URLS[tryIndex] + '&t=' + Date.now(), { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
            .then(function (res) {
                if (res && res.success && res.data_uri) {
                    img.src = res.data_uri;
                    if (hint) { hint.style.display = 'none'; }
                    var inp = document.getElementById('login-captcha-input');
                    if (inp) { inp.value = ''; inp.focus(); }
                } else { throw new Error('bad payload'); }
            })
            .catch(function () { tryIndex++; attempt(); });
    }
    attempt();
}

/* ⏳ حالت بارگذاری دکمه ورود */
document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('.login-card form');
    var submit = document.getElementById('login-submit');
    if (form && submit) {
        form.addEventListener('submit', function () {
            submit.classList.add('loading');
            submit.disabled = true;
        });
    }
});

/* ⌨️ ورود با Enter داخل کپچا */
document.addEventListener('DOMContentLoaded', function () {
    var cap = document.getElementById('login-captcha-input');
    if (cap) { cap.addEventListener('keydown', function (e) { if (e.key === 'Enter') { cap.form.submit(); } }); }
});
</script>
</body>
</html>
