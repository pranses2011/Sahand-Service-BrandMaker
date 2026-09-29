<?php
/**
 * 👤 حساب کاربری من (v2.34)
 * ===========================
 * ① تغییر رمز عبور خود (رمز فعلی + رمز جدید)
 * ② ورود دومرحله‌ای TOTP: فعال‌سازی دومرحله‌ای (QR + کد تأیید) / غیرفعال‌سازی / کدهای بازیابی
 *
 * جریان فعال‌سازی دو مرحله دارد تا کاربر با رمز اشتباه قفل نشود:
 *   مرحله ۱ → تولید secret + نمایش QR → کاربر اپ را اسکن می‌کند
 *   مرحله ۲ → وارد کردن کد جاری → فقط با کد درست فعال می‌شود + کدهای بازیابی نمایش داده می‌شود (یک‌بار)
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();
$uid = $auth->userId();

/* 👤 اطلاعات کاربر جاری (همیشه تازه از DB — وضعیت 2FA ممکن است تغییر کرده باشد) */
$me = $db->fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$uid]);
if (!$me) {
    $auth->logout();
    redirect('login.php');
}

$twoFaOn = !empty($me['totp_enabled']);
$recoveryCount = count(array_filter((array)json_decode((string)($me['totp_recovery'] ?? '[]'), true)));

/* ════════════ اکشن‌ها ════════════ */

/* 🖼 آواتار کاربر (v2.39) — آپلود/به‌روزرسانی */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'avatar_upload') {
    Auth::enforceCsrf();
    if (empty($_FILES['avatar_file']['tmp_name']) || !is_uploaded_file((string)$_FILES['avatar_file']['tmp_name'])) {
        flash('danger', 'فایلی انتخاب نشده است.');
        redirect('profile.php');
    }
    $f = $_FILES['avatar_file'];
    if ((int)$f['error'] !== UPLOAD_ERR_OK) {
        flash('danger', 'خطای آپلود فایل (کد ' . (int)$f['error'] . ') — دوباره تلاش کنید.');
        redirect('profile.php');
    }
    if ((int)$f['size'] > 3 * 1024 * 1024) {
        flash('danger', 'حجم تصویر آواتار باید کمتر از ۳ مگابایت باشد.');
        redirect('profile.php');
    }
    $info = @getimagesize((string)$f['tmp_name']);
    $okTypes = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($okTypes[$info[2]])) {
        flash('danger', 'فرمت تصویر پشتیبانی نمی‌شود — فقط JPG، PNG یا WebP.');
        redirect('profile.php');
    }
    /* 📁 پوشه اختصاصی با htaccess ضد اجرای اسکریپت */
    $dir = UPLOADS_PATH . '/avatars';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/.htaccess', "<FilesMatch \"\\.(?i:php|phtml|phar|cgi|pl|py|sh)$\">\nRequire all denied\n</FilesMatch>\nOptions -Indexes -ExecCGI\n");
    }
    /* 🧹 حذف آواتار قبلی همین کاربر */
    if (!empty($me['avatar']) && strpos((string)$me['avatar'], 'uploads/avatars/') === 0) {
        $oldAbs = ROOT_PATH . '/' . (string)$me['avatar'];
        if (is_file($oldAbs)) { @unlink($oldAbs); }
    }
    $rel = 'uploads/avatars/u' . $uid . '_' . date('YmdHis') . '.jpg';
    $proc = new ImageProcessor();
    if (!$proc->squareThumb((string)$f['tmp_name'], ROOT_PATH . '/' . $rel, 320, 86)) {
        flash('danger', 'پردازش تصویر ناموفق بود — تصویر دیگری امتحان کنید.');
        redirect('profile.php');
    }
    $db->update('users', ['avatar' => $rel], 'id = ?', [$uid]);
    Logger::activity($uid, 'تغییر آواتار', 'تصویر پروفایل به‌روزرسانی شد');
    flash('success', '✅ تصویر آواتار ذخیره شد.');
    redirect('profile.php');
}

/* 🗑 حذف آواتار */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'avatar_remove') {
    Auth::enforceCsrf();
    if (!empty($me['avatar']) && strpos((string)$me['avatar'], 'uploads/avatars/') === 0) {
        $oldAbs = ROOT_PATH . '/' . (string)$me['avatar'];
        if (is_file($oldAbs)) { @unlink($oldAbs); }
    }
    $db->update('users', ['avatar' => null], 'id = ?', [$uid]);
    Logger::activity($uid, 'حذف آواتار', 'تصویر پروفایل حذف شد');
    flash('success', 'آواتار حذف شد — حرف اول نام شما نمایش داده می‌شود.');
    redirect('profile.php');
}

/* 🔑 تغییر رمز عبور خود */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    Auth::enforceCsrf();
    $current = (string)($_POST['current_password'] ?? '');
    $new1 = (string)($_POST['new_password'] ?? '');
    $new2 = (string)($_POST['new_password2'] ?? '');
    if (!password_verify($current, (string)$me['password_hash'])) {
        flash('danger', 'رمز عبور فعلی نادرست است.');
    } elseif ($new1 !== $new2) {
        flash('danger', 'تکرار رمز جدید با رمز اصلی یکسان نیست.');
    } elseif (strlen($new1) < 8) {
        flash('danger', 'رمز جدید باید حداقل ۸ کاراکتر باشد.');
    } elseif (password_verify($new1, (string)$me['password_hash'])) {
        flash('danger', 'رمز جدید نباید با رمز فعلی یکسان باشد.');
    } else {
        $db->update('users', ['password_hash' => password_hash($new1, PASSWORD_BCRYPT)], 'id = ?', [$uid]);
        Logger::activity($uid, 'تغییر رمز عبور', 'کاربر رمز خود را از صفحه حساب کاربری تغییر داد');
        flash('success', '✅ رمز عبور با موفقیت تغییر کرد.');
    }
    redirect('profile.php');
}

/* 🚀 مرحله ۱ فعال‌سازی 2FA — تولید secret و QR */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'twofa_start') {
    Auth::enforceCsrf();
    if (!$twoFaOn) {
        $secret = Totp::generateSecret();
        $_SESSION['totp_pending_secret'] = $secret; // تا تأیید کد، در DB ذخیره نمی‌شود
        redirect('profile.php?step=setup');
    }
    redirect('profile.php');
}

/* ✅ مرحله ۲ فعال‌سازی — تأیید کد + صدور کدهای بازیابی */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'twofa_confirm') {
    Auth::enforceCsrf();
    $secret = (string)($_SESSION['totp_pending_secret'] ?? '');
    $code = (string)($_POST['totp_code'] ?? '');
    if ($secret === '') {
        flash('danger', 'مرحله تنظیم منقضی شده — دوباره روی «شروع تنظیم» بزنید.');
        redirect('profile.php');
    }
    if (!Totp::verify($secret, $code)) {
        flash('danger', 'کد واردشده درست نیست — کد جدیدی از اپ بردارید (هر ۳۰ ثانیه عوض می‌شود).');
        redirect('profile.php?step=setup');
    }
    $rc = Totp::generateRecoveryCodes();
    $db->update('users', [
        'totp_secret'   => $secret,
        'totp_enabled'  => 1,
        'totp_recovery' => json_encode($rc['hashes'], JSON_UNESCAPED_SLASHES),
    ], 'id = ?', [$uid]);
    unset($_SESSION['totp_pending_secret']);
    Logger::activity($uid, 'فعال‌سازی ورود دومرحله‌ای', 'TOTP فعال شد و ۸ کد بازیابی صادر شد');
    $_SESSION['totp_recovery_plain'] = $rc['plain']; // فقط برای نمایش یک‌بار
    redirect('profile.php?step=recovery');
}

/* 🔄 تولید مجدد کدهای بازیابی (نیاز به رمز فعلی) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'twofa_new_recovery') {
    Auth::enforceCsrf();
    $current = (string)($_POST['current_password'] ?? '');
    if (!password_verify($current, (string)$me['password_hash'])) {
        flash('danger', 'رمز عبور فعلی نادرست است.');
        redirect('profile.php');
    }
    $rc = Totp::generateRecoveryCodes();
    $db->update('users', ['totp_recovery' => json_encode($rc['hashes'], JSON_UNESCAPED_SLASHES)], 'id = ?', [$uid]);
    Logger::activity($uid, 'کدهای بازیابی جدید', 'کدهای بازیابی 2FA بازتولید شد');
    $_SESSION['totp_recovery_plain'] = $rc['plain'];
    redirect('profile.php?step=recovery');
}

/* 🚫 غیرفعال‌سازی 2FA (رمز فعلی + کد الزامی) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'twofa_disable') {
    Auth::enforceCsrf();
    $current = (string)($_POST['current_password'] ?? '');
    $code = (string)($_POST['totp_code'] ?? '');
    if (!password_verify($current, (string)$me['password_hash'])) {
        flash('danger', 'رمز عبور فعلی نادرست است.');
    } elseif (!Totp::verify((string)$me['totp_secret'], $code)) {
        flash('danger', 'کد دومرحله‌ای نادرست است.');
    } else {
        $db->update('users', ['totp_enabled' => 0, 'totp_secret' => null, 'totp_recovery' => null], 'id = ?', [$uid]);
        Totp::revokeTrustCookie();
        Logger::activity($uid, 'غیرفعال‌سازی ورود دومرحله‌ای', 'TOTP خاموش شد');
        flash('success', 'ورود دومرحله‌ای غیرفعال شد.');
    }
    redirect('profile.php');
}

/* 👤 به‌روزرسانی نام/ایمیل خود */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    Auth::enforceCsrf();
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    if (mb_strlen($fullName) < 2) {
        flash('danger', 'نام کامل را وارد کنید.');
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('danger', 'ایمیل واردشده معتبر نیست (برای بازیابی رمز لازم است).');
    } else {
        $db->update('users', ['full_name' => $fullName, 'email' => $email !== '' ? $email : null], 'id = ?', [$uid]);
        $_SESSION['full_name'] = $fullName;
        Logger::activity($uid, 'بروزرسانی پروفایل', 'نام/ایمیل خود را تغییر داد');
        flash('success', '✅ اطلاعات حساب بروزرسانی شد.');
    }
    redirect('profile.php');
}

/* 🧹 نمایش یک‌باره کدهای بازیابی */
$recoveryPlain = [];
if (($_GET['step'] ?? '') === 'recovery' && !empty($_SESSION['totp_recovery_plain'])) {
    $recoveryPlain = (array)$_SESSION['totp_recovery_plain'];
}

/* 🖼️ داده‌های مرحله تنظیم */
$setupStep = (($_GET['step'] ?? '') === 'setup') && !empty($_SESSION['totp_pending_secret']);
$qrUri = '';
$secretShow = '';
if ($setupStep) {
    $secretShow = (string)$_SESSION['totp_pending_secret'];
    $issuer = Config::get(Config::KEY_AGENCY_NAME_FA) ?: SAHAND_NAME_FA;
    $qrUri = Totp::otpauthUri($secretShow, (string)$me['username'], (string)$issuer);
}

$pageTitle = 'حساب کاربری من';
$activeMenu = 'profile';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1>👤 حساب کاربری من</h1>
    <p class="page-header-desc">رمز عبور، اطلاعات حساب و ورود دومرحله‌ای (2FA)</p>
</div>

<?php if ($recoveryPlain !== []): ?>
    <!-- 🧾 نمایش یک‌بار کدهای بازیابی -->
    <div class="card" style="border:2px solid #f59e0b;margin-bottom:20px">
        <div style="padding:20px">
            <h3 style="margin:0 0 8px;color:#92400e">🧾 کدهای بازیابی شما — همین یک‌بار نمایش داده می‌شوند!</h3>
            <p style="font-size:13px;line-height:2;color:#64748b;margin:0 0 14px">
                هر کد فقط <b>یک‌بار</b> قابل استفاده است (جایگزین کد ۶ رقمی وقتی به گوشی/اپ دسترسی ندارید).
                آن‌ها را چاپ یا در جای امنی ذخیره کنید. <?= count($recoveryPlain) ?> کد فعال دارید.
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;direction:ltr" id="recovery-grid">
                <?php foreach ($recoveryPlain as $rc): ?>
                    <code style="background:#fffbeb;border:1px dashed #fcd34d;border-radius:8px;padding:9px 12px;font-size:15px;font-weight:800;text-align:center;color:#92400e"><?= e($rc) ?></code>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
                <button type="button" class="btn btn-outline" onclick="copyRecoveryCodes()">📋 کپی همه</button>
                <a class="btn btn-primary" href="profile.php">✅ ذخیره کردم — تمام</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:20px;align-items:start">

    <!-- ═══════════ بخش ۱: اطلاعات حساب + آواتار ═══════════ -->
    <div class="card">
        <div style="padding:20px">
            <h3 style="margin:0 0 16px">📇 اطلاعات حساب</h3>

            <!-- 🖼 آواتار (v2.39) -->
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:14px;margin-bottom:16px">
                <?php $avatarRel = (string)($me['avatar'] ?? ''); ?>
                <?php if ($avatarRel !== '' && is_file(ROOT_PATH . '/' . $avatarRel)): ?>
                    <img src="<?= e(asset_ver($avatarRel)) ?>" alt="آواتار" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:3px solid #fff;box-shadow:0 3px 12px rgba(0,0,0,.14)">
                <?php else: ?>
                    <span style="width:72px;height:72px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:#fff;background:linear-gradient(135deg,#2563eb,#7c3aed);flex:0 0 auto"><?= e(mb_substr($me['full_name'] !== '' ? $me['full_name'] : 'م', 0, 1)) ?></span>
                <?php endif; ?>
                <div style="flex:1;min-width:200px">
                    <div style="font-weight:800;font-size:13.5px;margin-bottom:2px">🖼 تصویر آواتار</div>
                    <div style="font-size:11.5px;color:#94a3b8;line-height:1.9">JPG / PNG / WebP تا ۳ مگابایت — مربعی برش داده می‌شود (۳۲۰×۳۲۰)</div>
                    <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="action" value="avatar_upload">
                        <input type="file" name="avatar_file" accept="image/jpeg,image/png,image/webp" required style="font-size:12px;max-width:230px" class="form-control">
                        <button type="submit" class="btn btn-primary" style="padding:7px 16px;font-size:12.5px">💾 ذخیره تصویر</button>
                        <?php if ($avatarRel !== ''): ?>
                            <button type="submit" name="action" value="avatar_remove" formnovalidate class="btn btn-outline" style="padding:7px 16px;font-size:12.5px;color:#dc2626;border-color:#fecaca">🗑 حذف</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <form method="post" autocomplete="off">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="update_profile">
                <div class="form-group">
                    <label>نام کاربری (غیرقابل تغییر)</label>
                    <input type="text" value="<?= e($me['username']) ?>" disabled style="background:#f1f5f9">
                </div>
                <div class="form-group">
                    <label>نقش</label>
                    <input type="text" value="<?= e(['admin' => 'مدیر سیستم', 'editor' => 'ویراستار', 'brand_manager' => 'مدیر برند'][$me['role']] ?? $me['role']) ?>" disabled style="background:#f1f5f9">
                </div>
                <div class="form-group">
                    <label>نام کامل</label>
                    <input type="text" name="full_name" value="<?= e($me['full_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label>ایمیل <span style="color:#94a3b8;font-weight:400">(برای بازیابی رمز عبور — پیشنهاد می‌شود پر شود)</span></label>
                    <input type="email" name="email" value="<?= e($me['email'] ?? '') ?>" placeholder="you@example.com" dir="ltr">
                </div>
                <div style="font-size:12px;color:#94a3b8;line-height:1.9;margin:4px 0 12px">
                    آخرین ورود: <?= $me['last_login'] ? e(fa_num(jdate('Y/m/d H:i', strtotime((string)$me['last_login'])))) : '—' ?>
                </div>
                <button type="submit" class="btn btn-primary">💾 ذخیره اطلاعات</button>
            </form>
        </div>
    </div>

    <!-- ═══════════ بخش ۲: تغییر رمز ═══════════ -->
    <div class="card">
        <div style="padding:20px">
            <h3 style="margin:0 0 16px">🔑 تغییر رمز عبور</h3>
            <form method="post" autocomplete="off">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label>رمز عبور فعلی</label>
                    <input type="password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="form-group">
                    <label>رمز عبور جدید (حداقل ۸ کاراکتر)</label>
                    <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label>تکرار رمز عبور جدید</label>
                    <input type="password" name="new_password2" required minlength="8" autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary">🔐 تغییر رمز</button>
            </form>
        </div>
    </div>

    <!-- ═══════════ بخش ۳: ورود دومرحله‌ای ═══════════ -->
    <div class="card" style="grid-column:1/-1">
        <div style="padding:20px">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px">
                <h3 style="margin:0">🛡️ ورود دومرحله‌ای (2FA)</h3>
                <span class="badge <?= $twoFaOn ? 'badge-success' : 'badge-secondary' ?>" style="font-size:12px;padding:5px 12px">
                    <?= $twoFaOn ? '✅ فعال' : '⛔ غیرفعال' ?>
                </span>
            </div>

            <?php if ($twoFaOn): ?>
                <!-- وضعیت فعال: مدیریت -->
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px 16px;font-size:13.5px;line-height:2;color:#166534;margin-bottom:16px">
                    ورود دومرحله‌ای حساب شما فعال است. هنگام ورود، بعد از رمز عبور یک کد ۶ رقمی از اپ احرازکننده پرسیده می‌شود.<br>
                    کدهای بازیابی باقیمانده: <b><?= e(fa_num((string)$recoveryCount)) ?></b> عدد
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px">
                    <form method="post" style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:16px;min-width:0">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="action" value="twofa_new_recovery">
                        <h4 style="margin:0 0 8px;font-size:14px;color:#92400e">🔄 تولید مجدد کدهای بازیابی</h4>
                        <p style="font-size:12.5px;color:#a16207;line-height:2;margin:0 0 10px">۸ کد جدید می‌سازد و کدهای قبلی باطل می‌شوند.</p>
                        <div class="form-group">
                            <label>رمز عبور فعلی</label>
                            <input type="password" name="current_password" required autocomplete="current-password">
                        </div>
                        <button type="submit" class="btn btn-outline">تولید کدهای جدید</button>
                    </form>
                    <form method="post" style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:16px;min-width:0">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="action" value="twofa_disable">
                        <h4 style="margin:0 0 8px;font-size:14px;color:#991b1b">🚫 غیرفعال‌سازی دومرحله‌ای</h4>
                        <p style="font-size:12.5px;color:#b91c1c;line-height:2;margin:0 0 10px">پیشنهاد می‌شود فعال بماند! برای غیرفعال‌سازی رمز فعلی + کد الزامی است.</p>
                        <div class="form-group">
                            <label>رمز عبور فعلی</label>
                            <input type="password" name="current_password" required autocomplete="current-password">
                        </div>
                        <div class="form-group">
                            <label>کد فعلی اپ احرازکننده</label>
                            <input type="text" name="totp_code" required maxlength="6" inputmode="numeric" dir="ltr" placeholder="------" style="letter-spacing:6px;text-align:center;font-weight:800">
                        </div>
                        <button type="submit" class="btn btn-danger">غیرفعال‌سازی</button>
                    </form>
                </div>

            <?php elseif ($setupStep): ?>
                <!-- مرحله ۲: اسکن + تأیید کد — 🩹 v2.38: چیدمان مقاوم (رفع به‌هم‌ریختگی) -->
                <style>
                /* 🛡️ v2.38 — نشانگر مراحل راه‌اندازی 2FA + چیدمان شکست‌ناپذیر */
                .tfa-steps{display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;margin-bottom:18px}
                .tfa-st{font-size:12px;font-weight:800;padding:6px 16px;border-radius:20px;background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0}
                .tfa-st.active{background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;border-color:transparent;box-shadow:0 3px 10px rgba(124,58,237,.3)}
                .tfa-arrow{color:#94a3b8;font-size:14px}
                @media (max-width:640px){.tfa-arrow{transform:rotate(90deg)}}
                </style>
                <div class="tfa-steps">
                    <span class="tfa-st active">۱. اسکن QR با اپ احرازکننده</span>
                    <span class="tfa-arrow">←</span>
                    <span class="tfa-st">۲. وارد کردن کد ۶ رقمی</span>
                    <span class="tfa-arrow">←</span>
                    <span class="tfa-st">۳. ذخیره کدهای بازیابی</span>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,430px));gap:20px;align-items:start;justify-content:center">
                    <div style="text-align:center;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:18px;min-width:0">
                        <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:14px;display:inline-block;max-width:100%">
                            <?php $qr = Totp::qrDataUri($qrUri); ?>
                            <?php if ($qr !== ''): ?>
                                <img src="<?= e($qr) ?>" alt="QR راه‌اندازی دومرحله‌ای" width="230" height="230" style="max-width:100%;height:auto;display:block">
                            <?php else: ?>
                                <div style="width:230px;max-width:100%;aspect-ratio:1/1;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:12px;padding:12px">QR در دسترس نیست — کلید دستی زیر</div>
                            <?php endif; ?>
                        </div>
                        <p style="font-size:12.5px;color:#64748b;line-height:2;margin-top:12px">
                            ① اپ Google Authenticator / Aegis / Authy را باز کنید<br>
                            ② «+» → «اسکن QR» → این کد را اسکن کنید
                        </p>
                        <div style="font-size:11.5px;color:#94a3b8;margin-top:8px">
                            کلید دستی (اگر اسکن ممکن نیست):<br>
                            <code dir="ltr" style="background:#f1f5f9;padding:4px 10px;border-radius:6px;font-weight:700;word-break:break-all;overflow-wrap:anywhere;display:inline-block;max-width:100%"><?= e(trim(chunk_split($secretShow, 4, ' '))) ?></code>
                        </div>
                    </div>
                    <div style="min-width:0;background:linear-gradient(160deg,#eff6ff,#f0f9ff);border:1px solid #bfdbfe;border-radius:14px;padding:18px">
                        <form method="post">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="action" value="twofa_confirm">
                            <h4 style="margin:0 0 8px;font-size:15px">۲. کد ۶ رقمی نمایش‌داده‌شده در اپ را وارد کنید</h4>
                            <p style="font-size:12.5px;color:#64748b;line-height:2;margin:0 0 12px">
                                با وارد کردن کد درست، دومرحله‌ای فعال می‌شود و <b>کدهای بازیابی</b> به شما نشان داده می‌شود — آن‌ها را ذخیره کنید.
                            </p>
                            <div class="form-group">
                                <label>کد ۶ رقمی</label>
                                <input type="text" name="totp_code" required maxlength="6" inputmode="numeric" dir="ltr" autofocus
                                       placeholder="------" style="letter-spacing:8px;text-align:center;font-size:20px;font-weight:800">
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <button type="submit" class="btn btn-primary">✅ تأیید و فعال‌سازی</button>
                                <a href="profile.php" class="btn btn-outline">انصراف</a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <!-- خاموش: شروع تنظیم -->
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;font-size:13.5px;line-height:2.1;color:#475569">
                    با فعال‌سازی ورود دومرحله‌ای، حتی اگر رمز عبور شما لو برود، هیچ‌کس بدون کد ۶ رقمی گوشی شما نمی‌تواند وارد پنل شود.
                    <ul style="margin:8px 0;padding-inline-start:22px;font-size:13px">
                        <li>با هر اپ استاندارد کار می‌کند: Google Authenticator، Aegis، Authy، 1Password</li>
                        <li>۸ کد بازیابی اضطراری می‌گیرید (برای وقتی گوشی گم شود)</li>
                        <li>می‌توانید دستگاه خود را ۳۰ روزه «مورد اعتماد» کنید تا هر بار کد نخواهد</li>
                    </ul>
                </div>
                <form method="post" style="margin-top:12px">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="twofa_start">
                    <button type="submit" class="btn btn-primary">🚀 شروع تنظیم دومرحله‌ای</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function copyRecoveryCodes() {
    var codes = [];
    document.querySelectorAll('#recovery-grid code').forEach(function (el) { codes.push(el.textContent.trim()); });
    var text = codes.join('\n');
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { alert('✅ ' + codes.length + ' کد بازیابی کپی شد — در جای امنی ذخیره کنید.'); });
    } else {
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); document.body.removeChild(ta);
        alert('✅ ' + codes.length + ' کد بازیابی کپی شد — در جای امنی ذخیره کنید.');
    }
}
/* پاک‌سازی یک‌باره بعد از ترک صفحه (کدها فقط یک‌بار دیده شوند) */
window.addEventListener('beforeunload', function () { /* session plain بعد از رندر یک‌بار پاک می‌شود */ });
</script>

<?php
/* 🧹 کدهای بازیابی فقط یک‌بار نمایش داده شوند — بعد از رندر از سشن پاک می‌شوند */
unset($_SESSION['totp_recovery_plain']);
require __DIR__ . '/includes/footer.php';
