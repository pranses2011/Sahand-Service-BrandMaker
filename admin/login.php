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
    /* 🔐 استایل مستقل صفحه ورود — نسخه UI/UX Pro v2.7.1 (دوپنلی + کپچای بزرگ) */
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
    /* 🌐 بافت نقطه‌ای خیلی ملایم روی کل پس‌زمینه */
    .login-page .login-grid {
        position: absolute; inset: 0; pointer-events: none;
        background-image: radial-gradient(rgba(148,163,184,.10) 1px, transparent 1px);
        background-size: 26px 26px;
        mask-image: radial-gradient(ellipse at center, black 30%, transparent 75%);
        -webkit-mask-image: radial-gradient(ellipse at center, black 30%, transparent 75%);
    }
    /* 💫 حباب‌های نرم پس‌زمینه (فقط دکور مه‌آلود — با reduced-motion خاموش) */
    .login-page::before, .login-page::after {
        content: ''; position: absolute; border-radius: 50%; filter: blur(70px); opacity: .5;
        pointer-events: none;
    }
    .login-page::before { width: 420px; height: 420px; background: rgba(59,130,246,.14); top: -140px; inset-inline-end: -110px; animation: loginFloat 16s ease-in-out infinite alternate; }
    .login-page::after  { width: 360px; height: 360px; background: rgba(20,184,166,.12); bottom: -130px; inset-inline-start: -100px; animation: loginFloat 19s ease-in-out infinite alternate-reverse; }
    @keyframes loginFloat { from { transform: translate3d(0,0,0) } to { transform: translate3d(-34px, 30px, 0) } }

    /* 🧩 قاب دوپنلی: فرم (راست RTL) + پنل برند (چپ) */
    .login-wrap {
        position: relative; z-index: 1;
        display: flex; align-items: stretch; justify-content: center;
        width: 100%; max-width: 980px; gap: 0;
        animation: loginRise .55s cubic-bezier(.22,.9,.36,1) both;
    }
    @keyframes loginRise { from { opacity: 0; transform: translateY(16px) } to { opacity: 1; transform: none } }

    .login-card {
        flex: 1.12; min-width: 0;
        background: rgba(255,255,255,.98);
        border-radius: 20px;
        padding: 42px 38px 30px;
        box-shadow: 0 24px 70px rgba(2,8,23,.5), 0 2px 8px rgba(2,8,23,.25);
        position: relative;
    }
    /* نوار برند بالای کارت */
    .login-card::before {
        content: ''; position: absolute; top: 0; left: 24px; right: 24px; height: 4px;
        border-radius: 0 0 6px 6px;
        background: linear-gradient(90deg, #1e40af, #3b82f6 45%, #14b8a6);
    }

    /* 🎨 پنل برند — فقط دسکتاپ */
    .login-side {
        flex: .88; min-width: 0;
        border-radius: 20px 0 0 20px;
        padding: 44px 36px;
        color: #fff;
        display: flex; flex-direction: column; justify-content: center; gap: 18px;
        background:
            radial-gradient(500px 340px at 20% 12%, rgba(255,255,255,.14), transparent 60%),
            linear-gradient(155deg, #1d4ed8 0%, #1e3a8a 48%, #0f766e 100%);
        box-shadow: 0 24px 70px rgba(2,8,23,.45);
        position: relative; overflow: hidden;
    }
    .login-side::before, .login-side::after {
        content: ''; position: absolute; border-radius: 50%; border: 1.5px solid rgba(255,255,255,.14);
        pointer-events: none;
    }
    .login-side::before { width: 300px; height: 300px; inset-inline-start: -120px; top: -120px; }
    .login-side::after  { width: 220px; height: 220px; inset-inline-end: -90px; bottom: -90px; border-color: rgba(255,255,255,.10); }
    .side-logo {
        width: 92px; height: 92px; margin-bottom: 6px;
        border-radius: 24px;
        background: rgba(255,255,255,.13);
        border: 1px solid rgba(255,255,255,.25);
        backdrop-filter: blur(6px);
        box-shadow: 0 14px 34px rgba(2,8,23,.35), inset 0 1px 0 rgba(255,255,255,.3);
        display: flex; align-items: center; justify-content: center;
        font-size: 44px; overflow: hidden;
    }
    .side-logo img { width: 100%; height: 100%; object-fit: contain; }
    .login-side h2 { font-size: 21px; margin: 0 0 4px; color: #fff; }
    .login-side .side-slogan { font-size: 13px; line-height: 2; color: rgba(255,255,255,.82); margin-bottom: 10px; }
    .side-features { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
    .side-features li {
        display: flex; align-items: flex-start; gap: 10px;
        font-size: 13.5px; line-height: 1.9; color: rgba(255,255,255,.94);
    }
    .side-features .fico {
        flex-shrink: 0; width: 34px; height: 34px; border-radius: 11px;
        background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.22);
        display: flex; align-items: center; justify-content: center; font-size: 16px;
    }
    .side-badge {
        margin-top: 14px; align-self: flex-start;
        font-size: 11.5px; font-weight: 700; letter-spacing: .3px;
        background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.25);
        padding: 6px 14px; border-radius: 30px; color: rgba(255,255,255,.92);
    }
    .login-brand { text-align: center; margin-bottom: 26px; }
    .login-logo {
        width: 78px; height: 78px; margin: 0 auto 14px;
        border-radius: 22px;
        background: linear-gradient(140deg, #1e40af, #3b82f6);
        box-shadow: 0 10px 26px rgba(30,64,175,.35), inset 0 1px 0 rgba(255,255,255,.25);
        display: flex; align-items: center; justify-content: center;
        font-size: 38px; overflow: hidden;
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

    .login-field { position: relative; margin-bottom: 18px; }
    .login-field label { display: block; font-size: 13.5px; font-weight: 700; margin-bottom: 8px; }
    .login-field .input-icon {
        position: absolute; inset-inline-start: 14px; top: 44px;
        width: 20px; height: 20px; color: #94a3b8; pointer-events: none;
        transition: color .18s;
    }
    .login-field input {
        width: 100%; box-sizing: border-box;
        padding: 14px 44px 14px 14px;
        border: 1.5px solid var(--border); border-radius: 12px;
        font-family: var(--font); font-size: 16px; color: var(--text);
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
        position: absolute; inset-inline-end: 6px; top: 38px;
        width: 38px; height: 38px; border: none; background: none; border-radius: 9px;
        cursor: pointer; color: #94a3b8; display: flex; align-items: center; justify-content: center;
        transition: background .15s, color .15s;
    }
    .login-eye:hover { background: #eef2ff; color: #475569; }

    /* 🖼️ کپچا — تصویر بالا + کادر ورود «زیر تصویر» و بزرگ (v2.7.2 — طبق درخواست کاربر) */
    .login-captcha {
        display: flex; flex-direction: column; gap: 10px;
        background: #f8fafc; border: 1.5px solid var(--border); border-radius: 14px;
        padding: 12px; transition: border-color .18s, box-shadow .18s;
    }
    .login-captcha:focus-within { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59,130,246,.14); }
    .captcha-row { display: flex; gap: 10px; align-items: stretch; }
    .login-captcha img {
        border-radius: 10px; height: 92px; flex: 1; min-width: 0;
        cursor: pointer; background: #f3f4f6;
        transition: transform .15s;
    }
    .login-captcha img:active { transform: scale(.985); }
    .login-captcha input {
        width: 100%; box-sizing: border-box;
        border: 1.5px dashed #cbd5e1; border-radius: 12px; background: #fff;
        outline: none;
        font-family: var(--font); font-size: 28px; font-weight: 800;
        letter-spacing: 9px; text-align: center; color: var(--text);
        direction: ltr; text-transform: uppercase;
        padding: 10px 14px; min-height: 66px;
        transition: border-color .18s, box-shadow .18s;
    }
    .login-captcha input:focus { border-color: #3b82f6; border-style: solid; box-shadow: 0 0 0 4px rgba(59,130,246,.12); }
    .login-captcha input::placeholder { font-size: 14px; letter-spacing: 0; font-weight: 400; color: #94a3b8; }
    .captcha-refresh {
        align-self: center; width: 46px; height: 46px; flex-shrink: 0;
        border: 1px solid var(--border); background: #fff; border-radius: 11px;
        cursor: pointer; color: #64748b; font-size: 16px;
        display: flex; align-items: center; justify-content: center;
        transition: background .15s, color .15s, transform .4s;
    }
    .captcha-refresh:hover { background: #eef2ff; color: #2563eb; }
    .captcha-refresh.spin { transform: rotate(360deg); }

    .login-submit {
        width: 100%; margin-top: 10px;
        padding: 14.5px 16px;
        border: none; border-radius: 12px;
        background: linear-gradient(135deg, #1d4ed8, #3b82f6);
        color: #fff; font-family: var(--font); font-size: 16px; font-weight: 800;
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
        margin-top: 22px; padding-top: 15px; border-top: 1px dashed var(--border);
        display: flex; align-items: center; justify-content: center; gap: 6px;
        color: var(--text-light); font-size: 12px;
    }
    .login-foot .dot { width: 5px; height: 5px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.18); }
    .login-hint { display: none; margin-top: 8px; font-size: 12px; color: #b45309; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 6px 10px; text-align: center; }

    /* 📱 موبایل: تک‌کارته + کپچای بزرگ‌تر (v2.7.2 — کادر کد در موبایل بزرگ و کاملاً خوانا) */
    @media (max-width: 920px) {
        .login-side { display: none; }
        .login-card { border-radius: 20px; max-width: 460px; margin-inline: auto; }
        .login-wrap { max-width: 460px; }
    }
    @media (max-width: 480px) {
        .login-card { padding: 30px 18px 22px; }
        .login-captcha { padding: 10px; gap: 10px; }
        .captcha-row { gap: 8px; }
        .login-captcha img { height: 86px; }
        .login-captcha input { font-size: 26px; letter-spacing: 8px; min-height: 74px; padding: 12px 10px; }
        .captcha-refresh { width: 50px; height: 50px; }
        .login-submit { padding: 16px 16px; font-size: 16.5px; }
    }
    @media (prefers-reduced-motion: reduce) {
        .login-page::before, .login-page::after, .login-wrap { animation: none; }
        .login-submit, .login-submit:hover { transform: none; }
    }
    </style>
</head>
<body>
<div class="login-page">
    <div class="login-grid"></div>
    <div class="login-wrap">
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
                    <div class="captcha-row">
                        <img src="<?= e($captchaUri) ?>" alt="کد امنیتی" id="captcha-img" width="320" height="92"
                             onclick="refreshLoginCaptcha()" title="برای تغییر کلیک کنید">
                        <button type="button" class="captcha-refresh" onclick="refreshLoginCaptcha(this)" title="تولید کد جدید" aria-label="تولید کد جدید">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                        </button>
                    </div>
                    <input type="text" id="login-captcha-input" name="captcha" required maxlength="5"
                           placeholder="کد ۵ حرفی بالا را وارد کنید" inputmode="latin" autocomplete="off" autocapitalize="characters">
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

    <!-- 🎨 پنل برند — فقط دسکتاپ -->
    <aside class="login-side">
        <div class="side-logo">
            <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="لوگو"><?php else: ?>🏗️<?php endif; ?>
        </div>
        <h2><?= e($agencyName) ?></h2>
        <div class="side-slogan"><?= $agencySlogan !== '' ? e($agencySlogan) : 'سایت ساز هوشمند برندهای تعمیرات و خدمات' ?></div>
        <ul class="side-features">
            <li><span class="fico">🤖</span><span>موتور هوش مصنوعی سهند — تولید مقاله، سئو و خطایاب وب‌محور</span></li>
            <li><span class="fico">🌐</span><span>سایت‌ساز چندبرندی با دامنه و قالب اختصاصی هر نمایندگی</span></li>
            <li><span class="fico">📈</span><span>سئو خودکار، اسکیما و پیش‌نمایش زنده صفحات</span></li>
            <li><span class="fico">🛡️</span><span>امنیت چندلایه: CSRF، محدودیت تلاش و کپچای درون‌خطی</span></li>
        </ul>
        <span class="side-badge">نسخه <?= e(SAHAND_VERSION) ?></span>
    </aside>
    </div>
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
