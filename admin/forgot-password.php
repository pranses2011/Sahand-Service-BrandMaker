<?php
/**
 * 📨 درخواست بازیابی رمز عبور (v2.34)
 * ====================================
 * کاربر نام کاربری یا ایمیل خود را وارد می‌کند؛ اگر کاربر فعالِ دارای ایمیل
 * باشد لینک یک‌بارمصرف ۶۰ دقیقه‌ای برایش ایمیل می‌شود.
 * 🔒 پاسخ همیشه یکسان است (بدون افشای وجود/نبود کاربر — ضد User Enumeration)
 * 🔒 محدودیت ۳ درخواست / ۱۰ دقیقه (ضد بمب ایمیل)
 * اگر ایمیل برای کاربر ثبت نشده باشد → راهنمایی تماس با مدیر (ادمین از
 * صفحه «کاربران» می‌تواند رمز هر کاربری را بازنشانی کند).
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$auth = new Auth();

if ($auth->isLoggedIn()) {
    redirect('index.php');
}

$error = '';
$done = false;
$maskedEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf()) {
        $error = 'نشست شما منقضی شده است — دوباره تلاش کنید.';
    } else {
        $login = (string)($_POST['login'] ?? '');
        // نمایش ایمیل ماسک‌شده برای راهنمایی کاربر (فقط اگر کاربر واقعی باشد)
        $maskedEmail = Auth::maskedEmailFor($login);
        $result = $auth->createPasswordReset($login);

        if (!empty($result['error'])) {
            $error = $result['error'];
        } elseif (!empty($result['sent'])) {
            // 📧 ارسال ایمیل با Mailer (SMTP تنظیم‌شده در تنظیمات → تب ارسال)
            $resetUrl = BASE_URL . '/admin/reset-password.php?token=' . $result['token'];
            $agency = Config::get(Config::KEY_AGENCY_NAME_FA) ?: SAHAND_NAME_FA;
            $body = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px">'
                . '<h2 style="color:#1e40af;font-size:17px">بازیابی رمز عبور — ' . e($agency) . '</h2>'
                . '<p style="font-size:14px;line-height:2;color:#334155">سلام ' . e($result['user']['full_name']) . '،</p>'
                . '<p style="font-size:14px;line-height:2;color:#334155">برای تغییر رمز عبور حساب <b>' . e($result['user']['full_name']) . '</b> درخواستی ثبت شده است. روی دکمه زیر کلیک کنید:</p>'
                . '<p style="text-align:center;margin:26px 0"><a href="' . e($resetUrl) . '" style="background:#1d4ed8;color:#fff;text-decoration:none;padding:13px 30px;border-radius:10px;font-size:15px;font-weight:bold;display:inline-block">تغییر رمز عبور</a></p>'
                . '<p style="font-size:12.5px;line-height:2;color:#64748b">این لینک فقط <b>۶۰ دقیقه</b> معتبر است و فقط <b>یک‌بار</b> قابل استفاده است.</p>'
                . '<p style="font-size:12.5px;line-height:2;color:#64748b">اگر این درخواست از شما نبوده، این ایمیل را نادیده بگیرید — رمز شما بدون کلیک روی لینک تغییر نمی‌کند.</p>'
                . '<hr style="border:none;border-top:1px dashed #e2e8f0;margin:18px 0">'
                . '<p style="font-size:11.5px;color:#94a3b8">اگر دکمه کار نکرد، این نشانی را در مرورگر کپی کنید:<br><span dir="ltr" style="word-break:break-all">' . e($resetUrl) . '</span></p>'
                . '</div>';
            $mailer = new Mailer();
            $sent = $mailer->send((string)$result['user']['email'], 'بازیابی رمز عبور | ' . (Config::get(Config::KEY_AGENCY_NAME_FA) ?: SAHAND_NAME_FA), $body);
            if ($sent) {
                Logger::activity((int)$result['user']['id'], 'درخواست بازیابی رمز', 'لینک بازیابی برای ' . $maskedEmail . ' ایمیل شد');
            } else {
                // ایمیل ارسال نشد (SMTP تنظیم نیست) — در لاگ سیستم ثبت می‌شود تا مدیر پیگیری کند
                Logger::activity((int)$result['user']['id'], 'خطای ارسال ایمیل بازیابی', 'Mailer: ' . Mailer::lastError());
                @error_log('[PASSWORD-RESET] mail failed for user #' . $result['user']['id'] . ': ' . Mailer::lastError());
            }
            // ✅ پاسخ عمومی — همیشه پیام موفق (ضد شمارش کاربران)
            $done = true;
        } else {
            // کاربر وجود ندارد یا ایمیل ندارد — همان پیام عمومی
            $done = true;
        }
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
    <title>فراموشی رمز عبور | <?= e($agencyName) ?></title>
    <link rel="icon" href="<?= asset_url((string)Config::get(Config::KEY_AGENCY_FAVICON)) ?>">
    <link rel="stylesheet" href="<?= asset_ver('assets/css/admin.css') ?>">
    <style>
    .fp-page {
        min-height: 100vh; min-height: 100dvh;
        display: flex; align-items: center; justify-content: center;
        padding: 24px;
        background:
            radial-gradient(1100px 700px at 85% -10%, rgba(59,130,246,.20), transparent 60%),
            radial-gradient(900px 600px at 8% 110%, rgba(20,184,166,.16), transparent 55%),
            linear-gradient(160deg, #0b1220 0%, #101c36 55%, #0b1220 100%);
    }
    .fp-card {
        width: 100%; max-width: 460px;
        background: rgba(255,255,255,.98);
        border-radius: 20px; padding: 38px 34px 28px;
        box-shadow: 0 24px 70px rgba(2,8,23,.5);
        position: relative;
        animation: fpRise .5s cubic-bezier(.22,.9,.36,1) both;
    }
    @keyframes fpRise { from { opacity: 0; transform: translateY(14px) } to { opacity: 1; transform: none } }
    .fp-card::before {
        content: ''; position: absolute; top: 0; left: 24px; right: 24px; height: 4px;
        border-radius: 0 0 6px 6px;
        background: linear-gradient(90deg, #1e40af, #3b82f6 45%, #14b8a6);
    }
    .fp-head { text-align: center; margin-bottom: 24px; }
    .fp-head .ico {
        width: 74px; height: 74px; margin: 0 auto 14px;
        border-radius: 22px; background: linear-gradient(140deg, #dc2626, #f59e0b);
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 10px 26px rgba(220,38,38,.3);
    }
    .fp-head h1 { font-size: 19px; margin-bottom: 6px; }
    .fp-head .sub { color: var(--text-light, #64748b); font-size: 13px; line-height: 1.9; }
    .fp-input {
        width: 100%; box-sizing: border-box;
        padding: 14px 16px;
        border: 1.5px solid var(--border, #e2e8f0); border-radius: 12px;
        font-family: var(--font, Tahoma); font-size: 15px;
        background: #f8fafc; outline: none;
        transition: border-color .18s, box-shadow .18s;
    }
    .fp-input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 4px rgba(59,130,246,.14); }
    .fp-label { display: block; font-size: 13.5px; font-weight: 700; margin-bottom: 8px; }
    .fp-submit {
        width: 100%; margin-top: 16px; padding: 14px;
        border: none; border-radius: 12px;
        background: linear-gradient(135deg, #1d4ed8, #3b82f6);
        color: #fff; font-family: var(--font, Tahoma); font-size: 15.5px; font-weight: 800;
        cursor: pointer; box-shadow: 0 10px 24px rgba(29,78,216,.32);
        transition: transform .16s, filter .16s;
    }
    .fp-submit:hover { transform: translateY(-1.5px); filter: brightness(1.05); }
    .fp-alert {
        border-radius: 12px; padding: 12px 14px; font-size: 13px; line-height: 1.9;
        margin-bottom: 18px; display: flex; gap: 8px; align-items: flex-start;
    }
    .fp-alert.err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    .fp-alert.ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
    .fp-links { margin-top: 20px; padding-top: 15px; border-top: 1px dashed var(--border, #e2e8f0); text-align: center; font-size: 12.5px; }
    .fp-links a { color: #2563eb; text-decoration: none; }
    .fp-links a:hover { text-decoration: underline; }
    .fp-done { text-align: center; }
    .fp-done .big { font-size: 54px; margin-bottom: 10px; }
    .fp-mask {
        margin-top: 14px; font-size: 12.5px; color: #64748b; background: #f8fafc;
        border: 1px dashed #e2e8f0; border-radius: 10px; padding: 8px 12px;
        direction: ltr; font-family: monospace;
    }
    </style>
</head>
<body>
<div class="fp-page">
    <main class="fp-card">
        <div class="fp-head">
            <div class="ico">
                <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 7h3a5 5 0 0 1 5 5 5 5 0 0 1-5 5h-3m-6 0H6a5 5 0 0 1-5-5 5 5 0 0 1 5-5h3"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            </div>
            <h1>بازیابی رمز عبور</h1>
            <div class="sub">نام کاربری یا ایمیل حساب خود را وارد کنید تا لینک بازیابی برایتان ارسال شود.</div>
        </div>

        <?php if ($error): ?>
            <div class="fp-alert err" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($done): ?>
            <div class="fp-done">
                <div class="big">📧</div>
                <div class="fp-alert ok" style="text-align:right">
                    اگر حسابی با این نام کاربری/ایمیل وجود داشته باشد و ایمیل ثبت‌شده باشد، لینک بازیابی برایتان ارسال شد.
                    لینک تا <b>۶۰ دقیقه</b> معتبر است.
                </div>
                <?php if ($maskedEmail !== ''): ?>
                    <div class="fp-mask" title="آدرس ایمیل ثبت‌شده شما">→ <?= e($maskedEmail) ?></div>
                <?php endif; ?>
                <p style="font-size:12.5px;color:#64748b;line-height:2;margin-top:12px">
                    ایمیل دریافت نکردید؟ پوشه هرزنامه را ببینید. اگر ایمیلی برای حساب شما ثبت نشده،
                    از مدیر سیستم بخواهید از صفحه «کاربران» رمز شما را بازنشانی کند.
                </p>
            </div>
        <?php else: ?>
            <form method="post" autocomplete="off" novalidate>
                <?= Auth::csrfField() ?>
                <label class="fp-label" for="fp-login">نام کاربری یا ایمیل</label>
                <input type="text" id="fp-login" class="fp-input" name="login" required
                       value="<?= e($_POST['login'] ?? '') ?>" autofocus
                       autocomplete="username" placeholder="admin یا you@example.com">
                <button type="submit" class="fp-submit">ارسال لینک بازیابی</button>
            </form>
        <?php endif; ?>

        <div class="fp-links">
            <a href="login.php">← بازگشت به صفحه ورود</a>
        </div>
    </main>
</div>
</body>
</html>
