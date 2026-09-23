<?php
/**
 * 🚀 نصب‌کننده سایت ساز برند سهند سرویس
 * =======================================
 * راه‌اندازی خودکار سیستم در ۴ مرحله:
 *   ۱) بررسی الزامات سرور
 *   ۲) اتصال به دیتابیس
 *   ۳) ایجاد جداول + کاربر مدیر
 *   ۴) تکمیل نصب و قفل امنیتی
 *
 * @package SahandBrandMaker
 * @version 1.1.0
 */

define('SAHAND_INIT', true);
define('SAHAND_NO_SESSION', true);
require_once __DIR__ . '/config.php';

/* ==================================================
 * 🧩 تجزیه‌گر امن SQL — v1.1 (رفع باگ نصب)
 * ===================================================
 * ⚠️ باگ نسخه قبل: تقسیم ساده با regex باعث می‌شد هر تکه‌ای که
 * با خط کامنت (-- ...) شروع می‌شد «کامل» نادیده گرفته شود و
 * ۳۱ جدول از ۳۳ جدول هرگز ساخته نشود → خطای
 * «Table ... users doesn't exist» هنگام ساخت کاربر مدیر.
 *
 * این تجزیزگر یک ماشین حالت است که:
 *   • کامنت‌های خطی (-- و #) و بلوکی (/* *‌/) را فقط «بیرون» از رشته‌ها حذف می‌کند
 *   • رشته‌های '...' و "..." با ESCAPE (\\ و '' و "") را دست‌نخورده نگه می‌دارد
 *   • هر دستور را تا سمی‌کالونِ واقعی (بیرون از رشته) تفکیک می‌کند
 * ============================================ */
if (!function_exists('splitSqlStatements')) {
    function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $inSingle = false;  // داخل رشته '...'
        $inDouble = false;  // داخل رشته "..."
        $inLine   = false;  // داخل کامنت خطی تا انتهای خط
        $inBlock  = false;  // داخل کامنت بلوکی /* ... */

        for ($i = 0; $i < $len; $i++) {
            $ch   = $sql[$i];
            $next = ($i + 1 < $len) ? $sql[$i + 1] : '';

            // ── حالت کامنت خطی: تا انتهای خط رد شود ──
            if ($inLine) {
                if ($ch === "\n") { $inLine = false; $current .= $ch; }
                continue;
            }
            // ── حالت کامنت بلوکی: تا */ رد شود ──
            if ($inBlock) {
                if ($ch === '*' && $next === '/') { $inBlock = false; $i++; }
                continue;
            }
            // ── داخل رشته: فقط پایان رشته/کاراکتر فرار مهم است ──
            if ($inSingle || $inDouble) {
                $current .= $ch;
                if ($ch === '\\' && $next !== '') {
                    // کاراکتر فرار مثل \' یا \\ — هر دو کاراکتر حفظ شود
                    $current .= $next;
                    $i++;
                } elseif ($inSingle && $ch === "'") {
                    if ($next === "'") { $current .= $next; $i++; } // فرار ''
                    else { $inSingle = false; }
                } elseif ($inDouble && $ch === '"') {
                    if ($next === '"') { $current .= $next; $i++; } // فرار ""
                    else { $inDouble = false; }
                }
                continue;
            }
            // ── بیرون رشته: آغازگرهای کامنت ──
            if ($ch === '-' && $next === '-') { $inLine = true; $i++; continue; }
            if ($ch === '#')               { $inLine = true;       continue; }
            if ($ch === '/' && $next === '*') { $inBlock = true; $i++; continue; }
            // ── آغازگر رشته ──
            if ($ch === "'") { $inSingle = true; $current .= $ch; continue; }
            if ($ch === '"') { $inDouble = true; $current .= $ch; continue; }
            // ── پایان دستور ──
            if ($ch === ';') {
                if (trim($current) !== '') { $statements[] = trim($current); }
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if (trim($current) !== '') { $statements[] = trim($current); }
        return $statements;
    }
}

// ⛔ جلوگیری از اجرای مجدد نصب در صورت وجود قفل
$lockFile = __DIR__ . '/install.lock';
$lockExists = file_exists($lockFile);

$step = (int)($_GET['step'] ?? 1);
$error = '';
$done = false;

/* ==================================================
 * 🧠 منطق پردازش مراحل (POST)
 * ================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$lockExists) {
    $step = (int)($_POST['step'] ?? 1);

    /* ---------- مرحله ۲: تست دیتابیس ---------- */
    if ($step === 2) {
        $host = trim($_POST['db_host'] ?? 'localhost');
        $name = trim($_POST['db_name'] ?? '');
        $user = trim($_POST['db_user'] ?? '');
        $pass = (string)($_POST['db_pass'] ?? '');
        $prefixNote = trim($_POST['site_url'] ?? '');

        if ($name === '' || $user === '') {
            $error = 'نام دیتابیس و نام کاربری الزامی است.';
            $step = 2;
        } else {
            try {
                // 🔌 تست اتصال به سرور دیتابیس
                $pdo = new PDO(
                    "mysql:host={$host};dbname={$name};charset=utf8mb4",
                    $user,
                    $pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );

                // 📄 اجرای اسکیمای دیتابیس — با تجزیزگر امن (کامنت‌ها حذف، رشته‌ها محفوظ)
                $sql = (string)file_get_contents(__DIR__ . '/database.sql');
                $statements = splitSqlStatements($sql);
                if (count($statements) < 20) {
                    throw new RuntimeException('فایل database.sql خوانده نشد یا محتوای کافی ندارد (' . count($statements) . ' دستور).');
                }
                $executed = 0;
                foreach ($statements as $statement) {
                    $pdo->exec($statement);
                    $executed++;
                }

                // ✅ راستی‌آزمایی حیاتی: جداول اصلی باید واقعاً ساخته شده باشند
                $criticalTables = ['users', 'brands', 'settings', 'api_keys', 'brand_pages', 'brand_articles'];
                $missing = [];
                foreach ($criticalTables as $table) {
                    $found = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
                    if ($found === false) {
                        $missing[] = $table;
                    }
                }
                if ($missing) {
                    throw new RuntimeException('ایجاد جداول ناموفق بود — جداول مفقود: ' . implode('، ', $missing));
                }

                // 👤 ایجاد/به‌روزرسانی کاربر مدیر (نصب مجدد → کاربر تکراری نساز)
                $adminUser = trim($_POST['admin_user'] ?? 'admin');
                $adminPass = (string)($_POST['admin_pass'] ?? '');
                $adminName = trim($_POST['admin_name'] ?? 'مدیر سیستم');
                if (mb_strlen($adminPass) < 8) {
                    throw new RuntimeException('رمز عبور مدیر باید حداقل ۸ کاراکتر باشد.');
                }
                $hash = password_hash($adminPass, PASSWORD_BCRYPT);
                $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
                $check->execute([$adminUser]);
                if ($check->fetchColumn() !== false) {
                    $pdo->prepare('UPDATE users SET password_hash = ?, full_name = ?, role = ?, is_active = 1 WHERE username = ?')
                        ->execute([$hash, $adminName, 'admin', $adminUser]);
                } else {
                    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())')
                        ->execute([$adminUser, $hash, $adminName, 'admin']);
                }

                // 🔑 ایجاد کلید API سیستمی (فقط اگر قبلاً نساخته شده باشد)
                $hasSysKey = $pdo->query("SELECT COUNT(*) FROM api_keys WHERE label = 'کلید سیستمی سایت ساز'")->fetchColumn();
                if (!$hasSysKey) {
                    $sysKey = 'smk_' . bin2hex(random_bytes(24));
                    $pdo->prepare('INSERT INTO api_keys (brand_id, api_key, label, is_active, created_at) VALUES (NULL, ?, ?, 1, NOW())')
                        ->execute([$sysKey, 'کلید سیستمی سایت ساز']);
                }

                // 💾 نوشتن فایل تنظیمات محلی
                $localConfig = "<?php\n"
                    . "/**\n * ⚙️ تنظیمات محلی — توسط نصب‌کننده ساخته شده\n * این فایل را به صورت دستی ویرایش نکنید مگر ضرورت داشته باشد.\n */\n"
                    . "define('DB_HOST_VALUE', " . var_export($host, true) . ");\n"
                    . "define('DB_NAME_VALUE', " . var_export($name, true) . ");\n"
                    . "define('DB_USER_VALUE', " . var_export($user, true) . ");\n"
                    . "define('DB_PASS_VALUE', " . var_export($pass, true) . ");\n"
                    . "define('BRANDMAKER_URL_VALUE', " . var_export($prefixNote, true) . ");\n";
                if (@file_put_contents(__DIR__ . '/config.local.php', $localConfig) === false) {
                    throw new RuntimeException('نوشتن فایل config.local.php ناموفق بود — دسترسی نوشتن پوشه را بررسی کنید.');
                }

                // 🔒 ایجاد قفل نصب
                @file_put_contents($lockFile, 'نصب در تاریخ ' . date('Y-m-d H:i:s') . ' انجام شد.' . PHP_EOL);
                $done = true;
                $step = 4;
            } catch (PDOException $e) {
                $error = 'خطای دیتابیس: ' . $e->getMessage();
                $step = 2;
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
                $step = 2;
            }
        }
    }
}

/* ==================================================
 * 🔍 بررسی الزامات سرور (مرحله ۱)
 * ================================================== */
$requirements = [
    ['نسخه PHP ≥ 7.4', version_compare(PHP_VERSION, '7.4.0', '>='), 'نسخه فعلی: ' . PHP_VERSION],
    ['افزونه PDO MySQL', extension_loaded('pdo_mysql'), 'برای اتصال دیتابیس الزامی است'],
    ['افزونه mbstring', extension_loaded('mbstring'), 'پردازش متن فارسی'],
    ['افزونه JSON', extension_loaded('json'), 'تبدیل داده‌های ساختاریافته'],
    ['افزونه GD', extension_loaded('gd'), 'تحلیل لوگو و پردازش تصویر'],
    ['افزونه cURL', extension_loaded('curl'), 'ارسال پیام به تلگرام/بله'],
    ['افزونه ZipArchive', extension_loaded('zip'), 'تولید فایل ZIP خروجی'],
    ['دسترسی نوشتن پوشه uploads', is_writable(__DIR__ . '/uploads'), 'آپلود فایل‌ها'],
    ['دسترسی نوشتن پوشه cache', is_writable(__DIR__ . '/cache') || @mkdir(__DIR__ . '/cache', 0755, true), 'کش سیستم'],
    ['دسترسی نوشتن پوشه logs', is_writable(__DIR__ . '/logs') || @mkdir(__DIR__ . '/logs', 0755, true), 'لاگ سیستم'],
    ['دسترسی نوشتن ریشه (config)', is_writable(__DIR__), 'ساخت config.local.php'],
];
$allPassed = !in_array(false, array_column($requirements, 1), true);
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🚀 نصب سایت ساز برند سهند سرویس</title>
<style>
    :root { --primary:#1e40af; --primary-light:#3b82f6; --bg:#f1f5f9; --card:#fff; --text:#1e293b; --muted:#64748b; --ok:#16a34a; --err:#dc2626; --border:#e2e8f0; }
    * { box-sizing:border-box; margin:0; padding:0; font-family:Vazirmatn,Tahoma,Arial,sans-serif; }
    body { background:var(--bg); color:var(--text); min-height:100vh; padding:32px 16px; }
    .wrap { max-width:760px; margin:auto; }
    .header { text-align:center; margin-bottom:28px; }
    .header .logo { font-size:52px; }
    .header h1 { font-size:22px; margin-top:8px; }
    .header p { color:var(--muted); font-size:13px; margin-top:4px; }
    .card { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:26px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,.05); }
    .steps { display:flex; gap:8px; margin-bottom:22px; }
    .step { flex:1; text-align:center; padding:10px 6px; border-radius:10px; font-size:12px; background:#e2e8f0; color:var(--muted); }
    .step.active { background:var(--primary); color:#fff; font-weight:bold; }
    .step.done { background:var(--ok); color:#fff; }
    h2 { font-size:17px; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
    table.req { width:100%; border-collapse:collapse; font-size:13px; }
    table.req td, table.req th { padding:9px 12px; border-bottom:1px solid var(--border); text-align:right; }
    table.req th { background:#f8fafc; }
    .badge-ok { color:var(--ok); font-weight:bold; }
    .badge-err { color:var(--err); font-weight:bold; }
    .form-group { margin-bottom:15px; }
    .form-group label { display:block; font-size:13px; font-weight:bold; margin-bottom:6px; }
    .form-group input { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:8px; font-size:14px; font-family:inherit; direction:ltr; text-align:left; }
    .form-group input:focus { outline:none; border-color:var(--primary-light); box-shadow:0 0 0 3px rgba(59,130,246,.15); }
    .hint { font-size:11px; color:var(--muted); margin-top:4px; }
    .btn { display:inline-block; background:var(--primary); color:#fff; border:none; padding:12px 34px; border-radius:9px; font-size:15px; font-family:inherit; cursor:pointer; text-decoration:none; }
    .btn:hover { background:var(--primary-light); }
    .btn:disabled { background:#94a3b8; cursor:not-allowed; }
    .alert { padding:12px 16px; border-radius:9px; font-size:13px; margin-bottom:16px; }
    .alert-err { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
    .alert-ok { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
    .success-box { text-align:center; padding:16px; }
    .success-box .big { font-size:60px; }
    .actions { text-align:center; margin-top:20px; }
    .locked { text-align:center; padding:50px 20px; }
    code { background:#f1f5f9; padding:2px 8px; border-radius:5px; font-size:12px; direction:ltr; display:inline-block; }
    /* 📱 ریسپانسیو موبایل */
    @media (max-width: 600px) {
        body { padding: 18px 10px; }
        .header .logo { font-size: 42px; }
        .header h1 { font-size: 18px; }
        .card { padding: 17px 14px; }
        .steps { flex-wrap: wrap; gap: 6px; }
        .step { flex: 1 1 45%; font-size: 11px; padding: 8px 4px; }
        table.req { font-size: 12px; }
        table.req td, table.req th { padding: 7px 8px; }
        .btn { width: 100%; padding: 12px 20px; }
    }
</style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <div class="logo">🏗️</div>
        <h1>نصب سایت ساز برند سهند سرویس</h1>
        <p>Sahand BrandMaker — نسخه <?= SAHAND_VERSION ?></p>
    </div>

    <?php if ($lockExists && !$done): ?>
        <!-- ⛔ نصب قبلاً انجام شده -->
        <div class="card locked">
            <div class="big">🔒</div>
            <h2 style="justify-content:center">نصب قبلاً تکمیل شده است</h2>
            <p style="color:var(--muted);font-size:13px">برای اجرای مجدد نصب، فایل <code>install.lock</code> را از ریشه حذف کنید.</p>
            <div class="actions">
                <a class="btn" href="admin/login.php">ورود به پنل مدیریت</a>
            </div>
        </div>
    <?php elseif ($step === 1): ?>
        <!-- 📋 مرحله ۱: بررسی الزامات -->
        <div class="steps">
            <div class="step active">۱. الزامات</div>
            <div class="step">۲. دیتابیس</div>
            <div class="step">۳. مدیر</div>
            <div class="step">۴. تکمیل</div>
        </div>
        <div class="card">
            <h2>📋 بررسی الزامات سرور</h2>
            <table class="req">
                <tr><th>مورد</th><th>وضعیت</th><th>توضیح</th></tr>
                <?php foreach ($requirements as [$label, $ok, $note]): ?>
                <tr>
                    <td><?= htmlspecialchars($label) ?></td>
                    <td class="<?= $ok ? 'badge-ok' : 'badge-err' ?>"><?= $ok ? '✅ مجاز' : '❌ ناموفق' ?></td>
                    <td style="color:var(--muted);font-size:12px"><?= htmlspecialchars($note) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <div class="actions">
                <?php if ($allPassed): ?>
                    <a class="btn" href="?step=2">ادامه نصب ←</a>
                <?php else: ?>
                    <button class="btn" disabled>الزامات ناقص است</button>
                    <p class="hint" style="margin-top:10px">موارد ناموفق را با پشتیبانی هاست خود رفع کنید و صفحه را رفرش کنید.</p>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($step === 2): ?>
        <!-- 🗄️ مرحله ۲: اطلاعات دیتابیس -->
        <div class="steps">
            <div class="step done">۱. الزامات</div>
            <div class="step active">۲. دیتابیس</div>
            <div class="step">۳. مدیر</div>
            <div class="step">۴. تکمیل</div>
        </div>
        <div class="card">
            <h2>🗄️ اطلاعات دیتابیس و مدیر</h2>
            <?php if ($error): ?><div class="alert alert-err">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="step" value="2">
                <div class="form-group">
                    <label>🖥️ آدرس سرور دیتابیس</label>
                    <input name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>
                    <div class="hint">در هاست اشتراکی cPanel معمولاً localhost است.</div>
                </div>
                <div class="form-group">
                    <label>📦 نام دیتابیس</label>
                    <input name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" required placeholder="cpaneluser_brandmaker">
                </div>
                <div class="form-group">
                    <label>👤 نام کاربری دیتابیس</label>
                    <input name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>🔑 رمز عبور دیتابیس</label>
                    <input type="password" name="db_pass" value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>🌐 آدرس سایت ساز (اختیاری)</label>
                    <input name="site_url" value="<?= htmlspecialchars($_POST['site_url'] ?? '') ?>" placeholder="https://brandmaker.ea-fixer.ir">
                    <div class="hint">در صورت خالی بودن به صورت خودکار تشخیص داده می‌شود.</div>
                </div>
                <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">
                <h2 style="font-size:15px">👤 حساب مدیر</h2>
                <div class="form-group">
                    <label>نام نمایشی مدیر</label>
                    <input name="admin_name" value="<?= htmlspecialchars($_POST['admin_name'] ?? 'مدیر سهند سرویس') ?>" style="direction:rtl;text-align:right" required>
                </div>
                <div class="form-group">
                    <label>نام کاربری</label>
                    <input name="admin_user" value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin') ?>" required>
                </div>
                <div class="form-group">
                    <label>🔑 رمز عبور مدیر</label>
                    <input type="password" name="admin_pass" minlength="8" required>
                    <div class="hint">حداقل ۸ کاراکتر — از حروف بزرگ، کوچک و عدد استفاده کنید.</div>
                </div>
                <div class="actions">
                    <button class="btn" type="submit">🚀 اجرای نصب</button>
                </div>
            </form>
        </div>

    <?php elseif ($step === 4 && $done): ?>
        <!-- ✅ مرحله ۴: تکمیل نصب -->
        <div class="steps">
            <div class="step done">۱. الزامات</div>
            <div class="step done">۲. دیتابیس</div>
            <div class="step done">۳. مدیر</div>
            <div class="step done">۴. تکمیل</div>
        </div>
        <div class="card">
            <div class="success-box">
                <div class="big">🎉</div>
                <h2 style="justify-content:center">نصب با موفقیت تکمیل شد!</h2>
                <div class="alert alert-ok" style="text-align:right">
                    ✅ تمام جداول دیتابیس ایجاد شدند<br>
                    ✅ حساب کاربری مدیر ساخته شد<br>
                    ✅ فایل تنظیمات محلی نوشته شد<br>
                    ✅ قفل نصب (install.lock) فعال شد
                </div>
                <div style="font-size:13px;color:var(--muted);margin:14px 0;line-height:2">
                    🛡️ <b>توصیه امنیتی:</b> برای امنیت بیشتر، پس از اطمینان از کارکرد صحیح سیستم،
                    فایل <code>install.php</code> را از هاست حذف کنید.
                </div>
            </div>
            <div class="actions">
                <a class="btn" href="admin/login.php">ورود به پنل مدیریت ←</a>
            </div>
        </div>
    <?php endif; ?>

    <div style="text-align:center;color:var(--muted);font-size:11px;margin-top:10px">
        🏗️ سایت ساز برند سهند سرویس — سازگار با هاست اشتراکی cPanel | بدون هیچ وابستگی خارجی
    </div>
</div>
</body>
</html>
