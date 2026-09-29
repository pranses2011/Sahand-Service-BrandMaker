<?php
/**
 * 👥 مدیریت کاربران پنل (v2.34) — فقط نقش سیستمی (admin/editor)
 * ==============================================================
 * ① فهرست کاربران + نقش + وضعیت + آخرین ورود + تعداد برندهای تخصیصی
 * ② افزودن کاربر (admin / editor / brand_manager)
 * ③ ویرایش: نام، ایمیل، نقش، فعال/غیرفعال
 * ④ بازنشانی رمز (تعیین رمز جدید توسط مدیر — برای کاربرانی که ایمیل ندارند)
 * ⑤ تخصیص برندها به مدیر برند (ACL سطح‌برند)
 *
 * محافظت‌ها: تغییر نقش/حذف آخرین ادمین فعال ممنوع + حذف خود ممنوع + CSRF همه فرم‌ها
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$auth = new Auth();
$auth->requireLogin();
$auth->requireSystemRole(); // brand_manager اجازه ورود به این صفحه را ندارد
$db = Database::getInstance();
$meId = $auth->userId();

/* 🔢 چند ادمین فعال وجود دارد؟ (برای جلوگیری از حذف آخرین ادمین) */
$activeAdmins = (int)$db->fetchValue("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");

/* ════════════ اکشن‌ها ════════════ */

/* ➕ افزودن کاربر */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_user') {
    Auth::enforceCsrf();
    $username = preg_replace('/[^a-zA-Z0-9_.\-]/', '', (string)post('username', '')) ?? '';
    $fullName = trim((string)post('full_name', ''));
    $email = trim((string)post('email', ''));
    $role = (string)post('role', 'brand_manager');
    $password = (string)($_POST['password'] ?? '');
    if (!in_array($role, ['admin', 'editor', 'brand_manager'], true)) {
        $role = 'brand_manager';
    }
    if (mb_strlen($username) < 3) {
        flash('danger', 'نام کاربری باید حداقل ۳ کاراکتر لاتین/عدد باشد.');
    } elseif ($db->fetch('SELECT id FROM users WHERE username = ? LIMIT 1', [$username])) {
        flash('danger', 'این نام کاربری قبلاً ثبت شده است.');
    } elseif (mb_strlen($fullName) < 2) {
        flash('danger', 'نام کامل را وارد کنید.');
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('danger', 'ایمیل واردشده معتبر نیست.');
    } elseif (strlen($password) < 8) {
        flash('danger', 'رمز عبور باید حداقل ۸ کاراکتر باشد.');
    } else {
        $newId = Auth::createUser($username, $password, $fullName, $role);
        if ($email !== '') {
            $db->update('users', ['email' => $email], 'id = ?', [$newId]);
        }
        /* تخصیص برندها (فقط برای brand_manager معنا دارد) */
        if ($role === 'brand_manager') {
            foreach ((array)($_POST['brand_ids'] ?? []) as $bid) {
                $bid = (int)$bid;
                if ($bid > 0 && $db->fetch('SELECT id FROM brands WHERE id = ? LIMIT 1', [$bid])) {
                    try {
                        $db->insert('brand_user_access', ['user_id' => $newId, 'brand_id' => $bid, 'granted_by' => $meId]);
                    } catch (Throwable $e) { /* تکراری */ }
                }
            }
        }
        Logger::activity($meId, 'افزودن کاربر', "کاربر {$username} با نقش {$role} ساخته شد");
        flash('success', "✅ کاربر «{$username}» ساخته شد." . ($role === 'brand_manager' ? ' (برندهای تخصیص‌یافته ذخیره شد)' : ''));
    }
    redirect('users.php');
}

/* ✏️ ویرایش کاربر */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_user') {
    Auth::enforceCsrf();
    $id = (int)post('user_id');
    $target = $db->fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
    $fullName = trim((string)post('full_name', ''));
    $email = trim((string)post('email', ''));
    $role = (string)post('role', 'editor');
    $isActive = post('is_active') === '1' ? 1 : 0;
    if (!in_array($role, ['admin', 'editor', 'brand_manager'], true)) {
        $role = 'editor';
    }
    if (!$target) {
        flash('danger', 'کاربر یافت نشد.');
    } elseif (mb_strlen($fullName) < 2) {
        flash('danger', 'نام کامل را وارد کنید.');
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('danger', 'ایمیل واردشده معتبر نیست.');
    } elseif ($target['role'] === 'admin' && $role !== 'admin' && !$isActive === false && $activeAdmins <= 1) {
        flash('danger', 'آخرین مدیر سیستم را نمی‌توان تغییر نقش داد.');
    } elseif ($target['role'] === 'admin' && $isActive === 0 && $activeAdmins <= 1) {
        flash('danger', 'آخرین مدیر سیستم را نمی‌توان غیرفعال کرد.');
    } elseif ($id === $meId && $isActive === 0) {
        flash('danger', 'خودتان را نمی‌توانید غیرفعال کنید.');
    } else {
        $db->update('users', [
            'full_name' => $fullName,
            'email'     => $email !== '' ? $email : null,
            'role'      => $role,
            'is_active' => $isActive,
        ], 'id = ?', [$id]);
        /* اگر نقش از brand_manager تغییر کرد → تخصیص‌ها بی‌معنا می‌شوند (پاک‌سازی) */
        if ($role !== 'brand_manager') {
            $db->delete('brand_user_access', 'user_id = ?', [$id]);
        }
        Logger::activity($meId, 'ویرایش کاربر', 'کاربر #' . $id . ' (' . $target['username'] . ') ویرایش شد');
        flash('success', '✅ کاربر بروزرسانی شد.');
    }
    redirect('users.php?edit=' . $id);
}

/* 🔑 بازنشانی رمز کاربر */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    Auth::enforceCsrf();
    $id = (int)post('user_id');
    $password = (string)($_POST['new_password'] ?? '');
    $target = $db->fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
    if (!$target) {
        flash('danger', 'کاربر یافت نشد.');
    } elseif (strlen($password) < 8) {
        flash('danger', 'رمز جدید باید حداقل ۸ کاراکتر باشد.');
    } else {
        $db->update('users', ['password_hash' => password_hash($password, PASSWORD_BCRYPT)], 'id = ?', [$id]);
        Logger::activity($meId, 'بازنشانی رمز کاربر', 'رمز کاربر ' . $target['username'] . ' توسط مدیر بازنشانی شد');
        flash('success', "✅ رمز کاربر «{$target['username']}» بازنشانی شد — به او اطلاع دهید.");
    }
    redirect('users.php?edit=' . $id);
}

/* 🏷️ ذخیره برندهای تخصیصی مدیر برند */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_brands') {
    Auth::enforceCsrf();
    $id = (int)post('user_id');
    $target = $db->fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
    if (!$target) {
        flash('danger', 'کاربر یافت نشد.');
    } elseif ($target['role'] !== 'brand_manager') {
        flash('danger', 'تخصیص برند فقط برای مدیر برند معنا دارد.');
    } else {
        $keep = [];
        foreach ((array)($_POST['brand_ids'] ?? []) as $bid) {
            $bid = (int)$bid;
            if ($bid > 0 && $db->fetch('SELECT id FROM brands WHERE id = ? LIMIT 1', [$bid])) {
                $keep[$bid] = true;
                try {
                    $db->insert('brand_user_access', ['user_id' => $id, 'brand_id' => $bid, 'granted_by' => $meId]);
                } catch (Throwable $e) { /* تکراری */ }
            }
        }
        /* حذف تخصیص‌هایی که دیگر تیک نخورده‌اند */
        $rows = $db->fetchAll('SELECT id, brand_id FROM brand_user_access WHERE user_id = ?', [$id]);
        foreach ($rows as $r) {
            if (!isset($keep[(int)$r['brand_id']])) {
                $db->delete('brand_user_access', 'id = ?', [$r['id']]);
            }
        }
        Logger::activity($meId, 'تخصیص برند', count($keep) . ' برند به ' . $target['username'] . ' تخصیص یافت');
        flash('success', '✅ برندهای تخصیص‌یافته ذخیره شد (' . fa_num((string)count($keep)) . ' برند).');
    }
    redirect('users.php?edit=' . $id);
}

/* 🗑 حذف کاربر */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user') {
    Auth::enforceCsrf();
    $id = (int)post('user_id');
    $target = $db->fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
    if (!$target) {
        flash('danger', 'کاربر یافت نشد.');
    } elseif ($id === $meId) {
        flash('danger', 'حساب خودتان را نمی‌توانید حذف کنید.');
    } elseif ($target['role'] === 'admin' && $activeAdmins <= 1) {
        flash('danger', 'آخرین مدیر سیستم قابل حذف نیست.');
    } else {
        $db->delete('users', 'id = ?', [$id]); // brand_user_access هم CASCADE حذف می‌شود
        Logger::activity($meId, 'حذف کاربر', 'کاربر ' . $target['username'] . ' حذف شد');
        flash('success', "✅ کاربر «{$target['username']}» حذف شد.");
    }
    redirect('users.php');
}

/* ════════════ داده‌ها ════════════ */
$users = $db->fetchAll(
    "SELECT u.*, (SELECT COUNT(*) FROM brand_user_access b WHERE b.user_id = u.id) AS brand_count
     FROM users u ORDER BY u.id ASC"
);
$allBrands = $db->fetchAll('SELECT id, name_fa, slug FROM brands ORDER BY name_fa ASC');
$roleLabels = ['admin' => 'مدیر سیستم', 'editor' => 'ویراستار', 'brand_manager' => 'مدیر برند'];
$roleBadges = ['admin' => 'badge-primary', 'editor' => 'badge-info', 'brand_manager' => 'badge-warning'];

$editUser = null;
$editBrands = [];
if (($_GET['edit'] ?? '') !== '') {
    $editUser = $db->fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [(int)$_GET['edit']]);
    if ($editUser) {
        $editBrands = array_map('intval', array_column(
            $db->fetchAll('SELECT brand_id FROM brand_user_access WHERE user_id = ?', [$editUser['id']]),
            'brand_id'
        ));
    }
}

$pageTitle = 'کاربران و دسترسی‌ها';
$activeMenu = 'users';
require __DIR__ . '/includes/header.php';

/* 📊 آمار صفحه (v2.38 — کارت‌های آماری بالای صفحه) */
$statActive = count(array_filter($users, static fn($u) => !empty($u['is_active'])));
$statAdmins = count(array_filter($users, static fn($u) => $u['role'] === 'admin'));
$statManagers = count(array_filter($users, static fn($u) => $u['role'] === 'brand_manager'));
$stat2fa = count(array_filter($users, static fn($u) => !empty($u['totp_enabled'])));
$gradPool = [
    'linear-gradient(135deg,#2563eb,#0ea5e9)', 'linear-gradient(135deg,#7c3aed,#a855f7)',
    'linear-gradient(135deg,#059669,#10b981)', 'linear-gradient(135deg,#d97706,#f59e0b)',
    'linear-gradient(135deg,#dc2626,#f87171)', 'linear-gradient(135deg,#0891b2,#06b6d4)',
];
$avatarFor = static function (string $name, int $id) use ($gradPool): array {
    $words = preg_split('/\s+/u', trim($name)) ?: [];
    $ini = mb_substr($words[0] ?? '?', 0, 1) . (count($words) > 1 ? mb_substr($words[1], 0, 1) : '');
    return [$ini ?: '?', $gradPool[$id % count($gradPool)]];
};
?>

<style>
/* 👥 v2.38 — صفحه حرفه‌ای کاربران و دسترسی‌ها */
.usr-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px}
.usr-stat{background:var(--card-bg,#fff);border:1px solid var(--border);border-radius:14px;padding:16px 18px;display:flex;align-items:center;gap:13px;transition:.18s}
.usr-stat:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(15,23,42,.08)}
.usr-stat .ic{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex:none}
.usr-stat .num{font-size:22px;font-weight:900;line-height:1.2}
.usr-stat .lbl{font-size:11.5px;color:var(--text-light,#64748b);font-weight:600}
.usr-avatar{width:38px;height:38px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:13.5px;flex:none;box-shadow:0 3px 8px rgba(0,0,0,.18)}
.usr-avatar.lg{width:56px;height:56px;font-size:19px}
.usr-table tr{transition:.14s}
.usr-table tbody tr:hover{background:rgba(37,99,235,.045)}
.usr-chip{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;border-radius:14px;padding:3px 10px}
.role-card{border:1.5px solid var(--border);border-radius:13px;padding:13px 15px;cursor:pointer;transition:.16s;background:var(--card,#fff);position:relative}
.role-card:hover{border-color:#93c5fd;transform:translateY(-1px)}
.role-card.selected{border-color:var(--primary);background:rgba(37,99,235,.05);box-shadow:0 0 0 3px rgba(37,99,235,.13)}
.role-card input{position:absolute;opacity:0;pointer-events:none}
.role-card .rc-t{font-size:13.5px;font-weight:800;display:flex;align-items:center;gap:7px}
.role-card .rc-d{font-size:11px;color:var(--text-light,#64748b);line-height:1.8;margin-top:5px}
.role-card.selected .rc-t::after{content:'✓';position:absolute;top:10px;left:12px;background:var(--primary);color:#fff;border-radius:50%;width:19px;height:19px;font-size:11px;display:flex;align-items:center;justify-content:center}
.perm-matrix{width:100%;border-collapse:separate;border-spacing:0;font-size:12px}
.perm-matrix th,.perm-matrix td{padding:8px 10px;border-bottom:1px solid var(--border);text-align:center}
.perm-matrix th:first-child,.perm-matrix td:first-child{text-align:right;font-weight:700}
.perm-matrix thead th{background:#f1f5f9;font-size:11.5px}
</style>

<div class="page-header">
    <h1>👥 کاربران و دسترسی‌ها</h1>
    <p class="page-header-desc">
        مدیر سیستم دسترسی کامل دارد؛ ویراستار هم کامل (بدون مدیریت کاربران)؛
        <b>مدیر برند</b> فقط برندهای تخصیص‌یافته را می‌بیند.
    </p>
</div>

<!-- 📊 آمار کاربران -->
<div class="usr-stats">
    <div class="usr-stat"><div class="ic" style="background:rgba(37,99,235,.12);color:#2563eb">👥</div><div><div class="num"><?= fa_num((string)count($users)) ?></div><div class="lbl">کل کاربران</div></div></div>
    <div class="usr-stat"><div class="ic" style="background:rgba(16,185,129,.12);color:#059669">✅</div><div><div class="num"><?= fa_num((string)$statActive) ?></div><div class="lbl">حساب فعال</div></div></div>
    <div class="usr-stat"><div class="ic" style="background:rgba(124,58,237,.12);color:#7c3aed">🛡️</div><div><div class="num"><?= fa_num((string)$statAdmins) ?></div><div class="lbl">مدیر سیستم</div></div></div>
    <div class="usr-stat"><div class="ic" style="background:rgba(217,119,6,.12);color:#d97706">🏷️</div><div><div class="num"><?= fa_num((string)$statManagers) ?></div><div class="lbl">مدیر برند</div></div></div>
    <div class="usr-stat"><div class="ic" style="background:rgba(8,145,178,.12);color:#0891b2">🔐</div><div><div class="num"><?= fa_num((string)$stat2fa) ?></div><div class="lbl">ورود دومرحله‌ای</div></div></div>
</div>

<?php if ($editUser): ?>
    <?php [$avIni, $avGrad] = $avatarFor((string)$editUser['full_name'], (int)$editUser['id']); ?>
    <!-- ✏️ پنل ویرایش کاربر (v2.38 — سربرگ پروفایل + چهار کارت سازمان‌یافته) -->
    <div class="card" style="margin-bottom:20px;border-inline-start:4px solid #3b82f6">
        <div style="padding:20px">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid var(--border)">
                <div style="display:flex;align-items:center;gap:14px;min-width:0">
                    <span class="usr-avatar lg" style="background:<?= $avGrad ?>"><?= e($avIni) ?></span>
                    <div style="min-width:0">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                            <h3 style="margin:0">✏️ ویرایش: <span dir="ltr"><?= e($editUser['username']) ?></span></h3>
                            <span class="badge <?= e($roleBadges[$editUser['role']] ?? '') ?>"><?= e($roleLabels[$editUser['role']] ?? $editUser['role']) ?></span>
                            <?= !empty($editUser['totp_enabled']) ? '<span class="usr-chip" style="background:rgba(8,145,178,.1);color:#0891b2">🔐 2FA فعال</span>' : '' ?>
                        </div>
                        <div style="font-size:12px;color:var(--text-light);margin-top:4px">
                            <?= e($editUser['full_name']) ?><?= (int)$editUser['id'] === $meId ? ' — <b style="color:#2563eb">حساب شما</b>' : '' ?>
                            · آخرین ورود: <?= $editUser['last_login'] ? e(fa_num(jdate('Y/m/d H:i', strtotime((string)$editUser['last_login'])))) : '—' ?>
                        </div>
                    </div>
                </div>
                <a href="users.php" class="btn btn-outline btn-sm">✕ بستن</a>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;align-items:start">
                <!-- مشخصات + نقش -->
                <form method="post" autocomplete="off" style="background:#f8fafc;border:1px solid var(--border);border-radius:13px;padding:16px;min-width:0">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
                    <h4 style="margin:0 0 12px;font-size:13.5px">📇 مشخصات و نقش</h4>
                    <div class="form-group">
                        <label>نام کامل</label>
                        <input type="text" name="full_name" value="<?= e($editUser['full_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>ایمیل</label>
                        <input type="email" name="email" value="<?= e($editUser['email'] ?? '') ?>" dir="ltr">
                    </div>
                    <div class="form-group">
                        <label>نقش</label>
                        <select name="role">
                            <?php foreach ($roleLabels as $rk => $rl): ?>
                                <option value="<?= e($rk) ?>" <?= $editUser['role'] === $rk ? 'selected' : '' ?>><?= e($rl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                            <input type="checkbox" name="is_active" value="1" <?= !empty($editUser['is_active']) ? 'checked' : '' ?> style="width:16px;height:16px">
                            حساب فعال باشد
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary">💾 ذخیره</button>
                </form>

                <!-- 🔑 بازنشانی رمز -->
                <form method="post" autocomplete="off" style="background:#fff7ed;border:1px solid #fed7aa;border-radius:13px;padding:16px;min-width:0">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
                    <h4 style="margin:0 0 12px;font-size:13.5px">🔑 بازنشانی رمز عبور</h4>
                    <p style="font-size:12px;color:#9a3412;line-height:2;margin:0 0 10px">
                        رمز جدید را مستقیم تعیین کنید و به کاربر بگویید (برای کاربرانی که ایمیل ندارند).
                    </p>
                    <div class="form-group">
                        <label>رمز جدید (حداقل ۸ کاراکتر)</label>
                        <input type="text" name="new_password" minlength="8" required dir="ltr" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-outline">🔑 بازنشانی رمز</button>
                </form>

                <!-- 🏷️ تخصیص برند (فقط brand_manager) -->
                <form method="post" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:13px;padding:16px;min-width:0;<?= $editUser['role'] === 'brand_manager' ? '' : 'opacity:.45;pointer-events:none' ?>">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="save_brands">
                    <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
                    <h4 style="margin:0 0 12px;font-size:13.5px">🏷️ برندهای قابل دسترس <span style="color:#94a3b8;font-weight:400">(فقط مدیر برند)</span></h4>
                    <?php if ($allBrands === []): ?>
                        <p style="font-size:12.5px;color:#94a3b8">هنوز برندی ساخته نشده است.</p>
                    <?php else: ?>
                        <div style="max-height:230px;overflow:auto;border:1px solid #bfdbfe;border-radius:10px;padding:10px;background:#fff">
                            <?php foreach ($allBrands as $b): ?>
                                <label style="display:flex;align-items:center;gap:8px;padding:6px 4px;font-size:13px;cursor:pointer;border-bottom:1px dashed #e2e8f0">
                                    <input type="checkbox" name="brand_ids[]" value="<?= (int)$b['id'] ?>" <?= in_array((int)$b['id'], $editBrands, true) ? 'checked' : '' ?> style="width:15px;height:15px">
                                    <?= e($b['name_fa']) ?>
                                    <span style="color:#94a3b8;font-size:11px;margin-inline-start:auto" dir="ltr"><?= e($b['slug']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary" style="margin-top:10px" <?= $editUser['role'] === 'brand_manager' ? '' : 'disabled' ?>>💾 ذخیره تخصیص‌ها</button>
                </form>

                <!-- 🗑 حذف -->
                <form method="post" onsubmit="return confirm('کاربر «<?= e($editUser['username']) ?>» و همه تخصیص‌هایش حذف شود؟')" style="background:#fef2f2;border:1px solid #fecaca;border-radius:13px;padding:16px;min-width:0;align-self:start">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
                    <h4 style="margin:0 0 12px;font-size:13.5px;color:#991b1b">🗑 حذف کاربر</h4>
                    <p style="font-size:12px;color:#b91c1c;line-height:2;margin:0 0 10px">غیرقابل بازگشت است. آخرین مدیر سیستم حذف نمی‌شود.</p>
                    <button type="submit" class="btn btn-danger" <?= (int)$editUser['id'] === $meId ? 'disabled' : '' ?>>حذف قطعی</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:20px;align-items:start;margin-bottom:20px">
    <!-- ➕ افزودن کاربر (v2.38 — کارت‌های نقش بصری) -->
    <div class="card" style="margin:0">
        <div style="padding:20px">
            <h3 style="margin:0 0 14px">➕ افزودن کاربر جدید</h3>
            <form method="post" autocomplete="off">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="add_user">
                <input type="hidden" name="role" id="nu-role-hidden" value="brand_manager">
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:9px;margin-bottom:14px" id="nu-role-cards">
                    <label class="role-card selected" data-role="brand_manager">
                        <input type="radio" name="role_radio" value="brand_manager" checked>
                        <div class="rc-t">🏷️ مدیر برند</div>
                        <div class="rc-d">فقط برندهای تخصیص‌یافته را می‌بیند</div>
                    </label>
                    <label class="role-card" data-role="editor">
                        <input type="radio" name="role_radio" value="editor">
                        <div class="rc-t">✍️ ویراستار</div>
                        <div class="rc-d">کامل — بدون مدیریت کاربران</div>
                    </label>
                    <label class="role-card" data-role="admin">
                        <input type="radio" name="role_radio" value="admin">
                        <div class="rc-t">🛡️ مدیر سیستم</div>
                        <div class="rc-d">دسترسی کامل به همه‌چیز</div>
                    </label>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
                    <div class="form-group">
                        <label>نام کاربری (لاتین)</label>
                        <input type="text" name="username" required minlength="3" dir="ltr" pattern="[a-zA-Z0-9_.\-]{3,50}" placeholder="ali_rezaei">
                    </div>
                    <div class="form-group">
                        <label>نام کامل</label>
                        <input type="text" name="full_name" required placeholder="علی رضایی">
                    </div>
                    <div class="form-group">
                        <label>ایمیل (برای بازیابی رمز)</label>
                        <input type="email" name="email" dir="ltr" placeholder="ali@example.com">
                    </div>
                    <div class="form-group">
                        <label>رمز عبور (حداقل ۸ کاراکتر)</label>
                        <input type="text" name="password" required minlength="8" dir="ltr" autocomplete="new-password">
                    </div>
                </div>
                <div id="nu-brands" style="margin-top:6px">
                    <label style="font-size:13px;font-weight:700;display:block;margin-bottom:6px">🏷️ برندهای قابل دسترس: <span id="nu-brands-count" style="color:#2563eb"></span></label>
                    <div style="display:flex;flex-wrap:wrap;gap:9px;max-height:130px;overflow:auto;border:1px dashed var(--border);border-radius:10px;padding:10px">
                        <?php if ($allBrands === []): ?>
                            <span style="font-size:12px;color:#94a3b8">هنوز برندی نیست — بعد از ساخت برند از همین صفحه تخصیص دهید.</span>
                        <?php else: ?>
                            <?php foreach ($allBrands as $b): ?>
                                <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;background:#f1f5f9;border-radius:8px;padding:6px 10px;cursor:pointer;transition:.13s">
                                    <input type="checkbox" name="brand_ids[]" value="<?= (int)$b['id'] ?>" style="width:14px;height:14px">
                                    <?= e($b['name_fa']) ?>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" style="margin-top:8px" id="nu-select-all">✅ انتخاب همه</button>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:14px">➕ ساخت کاربر</button>
            </form>
        </div>
    </div>

    <!-- 🔐 ماتریس دسترسی نقش‌ها (v2.38) -->
    <div class="card" style="margin:0">
        <div style="padding:20px">
            <h3 style="margin:0 0 6px">🔐 ماتریس دسترسی نقش‌ها</h3>
            <p style="font-size:12px;color:var(--text-light);line-height:1.9;margin:0 0 14px">هر نقش چه بخش‌هایی از پنل را می‌بیند — هنگام ساخت کاربر راهنمای انتخاب نقش است.</p>
            <div style="overflow-x:auto">
                <table class="perm-matrix">
                    <thead>
                        <tr><th>بخش</th><th>🛡️ مدیر سیستم</th><th>✍️ ویراستار</th><th>🏷️ مدیر برند</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>برندها + قالب‌ساز</td><td>✅ همه</td><td>✅ همه</td><td>✅ فقط تخصیصی</td></tr>
                        <tr><td>مقالات و محتوا</td><td>✅</td><td>✅</td><td>✅ فقط تخصیصی</td></tr>
                        <tr><td>استقرار و بروزرسانی سایت</td><td>✅</td><td>✅</td><td>⛔</td></tr>
                        <tr><td>تنظیمات + کاربران</td><td>✅</td><td>⛔ کاربران</td><td>⛔</td></tr>
                        <tr><td>آمار و گزارش‌ها</td><td>✅ همه</td><td>✅ همه</td><td>✅ فقط تخصیصی</td></tr>
                        <tr><td>خروجی ZIP / API</td><td>✅</td><td>✅</td><td>⛔</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 📋 فهرست کاربران (v2.38 — جدول مدرن با آواتار) -->
<div class="card">
    <div style="padding:20px">
        <h3 style="margin:0 0 14px">📋 کاربران (<?= e(fa_num((string)count($users))) ?>)</h3>
        <div style="overflow-x:auto">
            <table class="table usr-table">
                <thead>
                    <tr>
                        <th>کاربر</th>
                        <th>ایمیل</th>
                        <th>نقش</th>
                        <th>برندها</th>
                        <th>امنیت</th>
                        <th>وضعیت</th>
                        <th>آخرین ورود</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <?php [$ini, $grad] = $avatarFor((string)$u['full_name'], (int)$u['id']); ?>
                        <tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px;min-width:150px">
                                    <span class="usr-avatar" style="background:<?= $grad ?>"><?= e($ini) ?></span>
                                    <div style="min-width:0">
                                        <div dir="ltr" style="font-weight:700;text-align:right"><?= e($u['username']) ?><?= (int)$u['id'] === $meId ? ' <span style="color:#3b82f6;font-size:11px">(شما)</span>' : '' ?></div>
                                        <div style="font-size:11.5px;color:var(--text-light)"><?= e($u['full_name']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td dir="ltr" style="font-size:12px;color:#64748b"><?= e($u['email'] ?? '—') ?></td>
                            <td><span class="badge <?= e($roleBadges[$u['role']] ?? '') ?>"><?= e($roleLabels[$u['role']] ?? $u['role']) ?></span></td>
                            <td style="text-align:center">
                                <?php if ($u['role'] === 'brand_manager'): ?>
                                    <span class="usr-chip" style="background:rgba(217,119,6,.1);color:#b45309">🏷️ <?= e(fa_num((string)$u['brand_count'])) ?></span>
                                <?php else: ?>
                                    <span style="font-size:12px;color:var(--text-light)">همه</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center"><?= !empty($u['totp_enabled']) ? '<span class="usr-chip" style="background:rgba(8,145,178,.1);color:#0891b2">🔐 2FA</span>' : '<span style="color:#cbd5e1">—</span>' ?></td>
                            <td><?= !empty($u['is_active']) ? '<span class="badge badge-success">فعال</span>' : '<span class="badge badge-danger">غیرفعال</span>' ?></td>
                            <td style="font-size:12px"><?= $u['last_login'] ? e(fa_num(jdate('Y/m/d H:i', strtotime((string)$u['last_login'])))) : '—' ?></td>
                            <td><a class="btn btn-outline btn-sm" href="users.php?edit=<?= (int)$u['id'] ?>">✏️ ویرایش</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>.btn-sm{padding:4px 10px;font-size:12px}</style>

<script>
/* 👥 v2.38 — کارت‌های نقش بصری + شمارنده برند (نمایش/پنهان بر اساس نقش) */
(function () {
    var cards = document.querySelectorAll('#nu-role-cards .role-card');
    var hidden = document.getElementById('nu-role-hidden');
    var box = document.getElementById('nu-brands');
    var countEl = document.getElementById('nu-brands-count');
    function updateBrandCount() {
        if (!countEl) { return; }
        var all = box ? box.querySelectorAll('input[type=checkbox]') : [];
        var on = [].filter.call(all, function (x) { return x.checked; }).length;
        countEl.textContent = on ? ('(' + String(on).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }) + ' انتخاب‌شده)') : '';
    }
    function selectRole(role) {
        if (hidden) { hidden.value = role; }
        cards.forEach(function (c) { c.classList.toggle('selected', c.getAttribute('data-role') === role); });
        if (box) { box.style.display = role === 'brand_manager' ? '' : 'none'; }
    }
    cards.forEach(function (c) {
        c.addEventListener('click', function (e) {
            e.preventDefault();
            selectRole(c.getAttribute('data-role'));
        });
    });
    if (box) {
        box.addEventListener('change', updateBrandCount);
        updateBrandCount();
    }
    /* ✅ دکمه انتخاب/لغو همه برندها */
    var selAll = document.getElementById('nu-select-all');
    if (selAll) {
        selAll.addEventListener('click', function () {
            var list = box ? box.querySelectorAll('input[type=checkbox]') : [];
            var on = [].filter.call(list, function (x) { return x.checked; }).length;
            list.forEach(function (x) { x.checked = !on; });
            updateBrandCount();
        });
    }
    selectRole('brand_manager');
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
