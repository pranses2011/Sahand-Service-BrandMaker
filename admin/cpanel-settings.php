<?php
/**
 * ⚙️ تنظیمات اتصال cPanel و استقرار خودکار
 * =========================================
 * طبق سند بخش ۲۱ (بخش ۸ + پرامپت تکمیلی بخش ۲):
 *   - اطلاعات اتصال cPanel (آدرس/پورت/کاربر/توکن) + تست اتصال
 *   - تنظیمات FTP (جایگزین) + تست
 *   - تنظیمات استقرار (SSL/بکاپ/تست/اعلان/حجم/تایم‌اوت)
 *   - الگوی Document Root با ۶ پیش‌فرض + متغیرهای پویا + پیش‌نمایش زنده
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();

/* 🔌 تست اتصال cPanel (AJAX) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_cpanel') {
    Auth::enforceCsrf();
    // اگر توکن جدید وارد شده — همان لحظه تست شود (بدون ذخیره)
    $token = post('cpanel_token_test');
    $settings = PathResolver::getSettings();
    if ($token !== '') {
        $settings['cpanel_token_enc'] = DeployCrypto::encrypt($token);
    }
    $api = new CpanelAPI($settings);
    $result = $api->connect();
    json_response(['success' => $result['success'], 'message' => $result['message'] . ($result['data']['version'] ?? '' ? ' (نسخه: ' . $result['data']['version'] . ')' : '')]);
}

/* 🔌 تست اتصال FTP (AJAX) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_ftp') {
    Auth::enforceCsrf();
    $settings = PathResolver::getSettings();
    $pass = post('ftp_password_test');
    if ($pass !== '') {
        $settings['ftp_password_enc'] = DeployCrypto::encrypt($pass);
    }
    $ftp = new FtpManager($settings);
    $result = $ftp->testConnection();
    json_response(['success' => $result['success'], 'message' => $result['message']]);
}

/* ⏰ افزودن خودکار Cron به cPanel (AJAX) — 🆕 v2.38: ④ وظیفه اصلی ۵ دقیقه‌ای
 * + دو «حالت فرمان»: php CLI (پیش‌فرض) یا curl با توکن (برای هاست‌هایی که
 * اجرای php در cron را با ایمیل «Access denied / 403» مسدود می‌کنند). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_cron') {
    Auth::enforceCsrf();
    $job = post('job', '');
    $mode = post('mode') === 'curl' ? 'curl' : 'cli';
    // 🧭 مسیر واقعی نصب روی سرور — دقیقاً همان جایی که فایل‌های cron هستند
    $cronBaseReal = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    /* 🔑 توکن cron — برای حالت curl لازم است؛ اگر نبود، همین‌جا ساخته می‌شود */
    $cronToken = (string)Config::get('cron_secret_token', '');
    if ($cronToken === '') {
        $cronToken = bin2hex(random_bytes(16));
        Config::set('cron_secret_token', $cronToken);
    }
    $cronUrlBase = rtrim(BASE_URL, '/') . '/cron/';
    /* 🗺️ نقشه وظایف — زمان‌بندی + ساخت فرمان در هر دو حالت */
    $jobs = [
        'main'         => ['file' => 'cron.php',        'minute' => '*/5',  'hour' => '*',  'day' => '*', 'month' => '*', 'weekday' => '*'],
        'health-check' => ['file' => 'health-check.php', 'minute' => '*/15', 'hour' => '*',  'day' => '*', 'month' => '*', 'weekday' => '*'],
        'ssl-check'    => ['file' => 'ssl-check.php',    'minute' => '0',    'hour' => '3',  'day' => '*', 'month' => '*', 'weekday' => '*'],
        'backup'       => ['file' => 'backup.php',       'minute' => '0',    'hour' => '2',  'day' => '*', 'month' => '*', 'weekday' => '6'],
    ];
    $buildCommand = static function (array $spec) use ($mode, $cronBaseReal, $cronUrlBase, $cronToken): string {
        if ($mode === 'curl') {
            /* 🌐 حالت HTTP — برای هاست‌هایی که php CLI در cron را مسدود کرده‌اند */
            return 'curl -s -o /dev/null "' . $cronUrlBase . $spec['file'] . '?token=' . $cronToken . '"';
        }
        return 'php ' . $cronBaseReal . '/cron/' . $spec['file'];
    };
    if ($job === 'all') {
        $results = [];
        $okCount = 0;
        $api = new CpanelAPI();
        foreach ($jobs as $key => $spec) {
            $cmd = $buildCommand($spec);
            $r = $api->addCronJob($cmd, $spec['minute'], $spec['hour'], $spec['day'], $spec['month'], $spec['weekday']);
            $results[] = ($r['success'] ? '✅' : '❌') . ' ' . $cmd . ' — ' . $r['message'];
            if ($r['success']) { $okCount++; }
        }
        json_response(['success' => $okCount > 0, 'message' => $okCount . ' از ' . count($jobs) . " وظیفه Cron (" . ($mode === 'curl' ? 'حالت curl/HTTP' : 'حالت PHP CLI') . ") اضافه شد:\n" . implode("\n", $results)]);
    }
    if (!isset($jobs[$job])) {
        json_response(['success' => false, 'message' => 'دستور ناشناخته است.']);
    }
    $spec = $jobs[$job];
    $api = new CpanelAPI();
    $r = $api->addCronJob($buildCommand($spec), $spec['minute'], $spec['hour'], $spec['day'], $spec['month'], $spec['weekday']);
    /* 🩺 v2.38 — راهنمای شفاف برای ایمیل‌های «Access denied / 403» هاست */
    if (!$r['success'] && (stripos($r['message'], '403') !== false || stripos($r['message'], '401') !== false || stripos($r['message'], 'رد شد') !== false)) {
        $r['message'] .= "\n\n💡 ریشه‌یابی خطای ۴۰۳:\n" .
            "① توکن cPanel باید با دسترسی کامل ساخته شده باشد (cPanel ▸ Manage API Tokens ▸ دسترسی «all features» یا حداقل Cron).\n" .
            "② اگر هاست اجرای «php» در Cron را مسدود کرده (ایمیل Access denied می‌فرستد)، حالت «curl / HTTP» را انتخاب کنید و دوباره امتحان کنید.\n" .
            "③ یا از cPanel ▸ Advanced ▸ Cron Jobs فرمان را دستی اضافه کنید.";
    }
    json_response(['success' => $r['success'], 'message' => $r['message']]);
}

/* 📋 فهرست Cron های نصب‌شده در cPanel (AJAX — v2.38) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'list_crons') {
    Auth::enforceCsrf();
    $api = new CpanelAPI();
    $rows = $api->listCronJobs();
    if ($rows === [] && $api->getLastError() !== '') {
        json_response(['success' => false, 'message' => 'خواندن فهرست Cron ناموفق: ' . $api->getLastError()]);
    }
    json_response(['success' => true, 'crons' => $rows]);
}

/* 🗑 حذف یک Cron از cPanel (AJAX — v2.38) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_cron') {
    Auth::enforceCsrf();
    $lineRef = trim((string)post('linekey', post('line', '')));
    if ($lineRef === '') {
        json_response(['success' => false, 'message' => 'شناسه خط Cron ارسال نشده است.']);
    }
    $api = new CpanelAPI();
    $r = $api->removeCronJob($lineRef);
    json_response(['success' => $r['success'], 'message' => $r['message']]);
}

/* 🔍 اعتبارسنجی الگو (AJAX) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'validate_pattern') {
    Auth::enforceCsrf();
    $check = PathResolver::validatePattern(post('pattern'));
    $previews = $check['valid'] ? PathResolver::previewPath(post('pattern')) : [];
    json_response(['success' => $check['valid'], 'error' => $check['error'], 'previews' => $previews]);
}

/* 💾 ذخیره تنظیمات */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    Auth::enforceCsrf();

    $data = [
        'cpanel_host'     => trim(post('cpanel_host')),
        'cpanel_port'     => max(1, min(65535, (int)post('cpanel_port', '2083'))),
        'cpanel_protocol' => post('cpanel_protocol') === 'http' ? 'http' : 'https',
        'cpanel_username' => trim(post('cpanel_username')),
        'root_domain'     => strtolower(trim(post('root_domain'))),
        'deploy_enabled'  => isset($_POST['deploy_enabled']) ? 1 : 0,
        'ssl_auto'        => isset($_POST['ssl_auto']) ? 1 : 0,
        'ssl_provider'    => post('ssl_provider') === 'cpanel' ? 'cpanel' : 'letsencrypt',
        'upload_mode'     => post('upload_mode') === 'ftp' ? 'ftp' : 'api',
        'backup_before_update' => isset($_POST['backup_before_update']) ? 1 : 0,
        'auto_test'       => isset($_POST['auto_test']) ? 1 : 0,
        'notify_after_deploy'  => isset($_POST['notify_after_deploy']) ? 1 : 0,
        'max_upload_mb'   => max(1, min(2048, (int)post('max_upload_mb', '50'))),
        'timeout_seconds' => max(30, min(600, (int)post('timeout_seconds', '120'))),
        'ftp_host'        => trim(post('ftp_host')),
        'ftp_port'        => max(1, min(65535, (int)post('ftp_port', '21'))),
        'ftp_username'    => trim(post('ftp_username')),
        'ftp_passive'     => isset($_POST['ftp_passive']) ? 1 : 0,
        'ftp_ssl'         => isset($_POST['ftp_ssl']) ? 1 : 0,
        'backup_dir'      => trim(post('backup_dir', '')),
        'backup_keep_count' => max(1, min(50, (int)post('backup_keep_count', '5'))),
        'updated_at'      => date('Y-m-d H:i:s'),
    ];

    // 🔑 توکن — فقط اگر فیلد پر شده، بروزرسانی شود (خالی = بدون تغییر)
    $newToken = trim((string)($_POST['cpanel_token'] ?? ''));
    if ($newToken !== '') {
        $data['cpanel_token_enc'] = DeployCrypto::encrypt($newToken);
    }
    // 🔑 رمز FTP — همان منطق
    $newFtpPass = (string)($_POST['ftp_password'] ?? '');
    if ($newFtpPass !== '') {
        $data['ftp_password_enc'] = DeployCrypto::encrypt($newFtpPass);
    }

    // 📂 الگوی Document Root — اعتبارسنجی + ذخیره جداگانه
    $pattern = trim((string)($_POST['doc_root_pattern'] ?? PathResolver::DEFAULT_PATTERN));
    $preset  = trim((string)($_POST['doc_root_preset'] ?? 'custom'));
    $check = PathResolver::validatePattern($pattern);
    if (!$check['valid']) {
        flash('danger', 'خطای الگوی مسیر: ' . $check['error']);
        redirect('cpanel-settings.php');
    }
    $data['doc_root_pattern'] = $pattern;
    $data['doc_root_preset']  = $preset;

    // 🏷️ دامنه اصلی — اعتبارسنجی ساده
    if ($data['root_domain'] !== '' && !preg_match('/^[a-z0-9\.\-]{3,253}$/', $data['root_domain'])) {
        flash('danger', 'قالب دامنه اصلی نامعتبر است (مثال: ea-fixer.ir).');
        redirect('cpanel-settings.php');
    }

    $db->update('cpanel_settings', $data, 'id = 1');
    PathResolver::clearCache();

    Logger::activity((int)$_SESSION['user_id'], 'تنظیمات cPanel', 'ذخیره تنظیمات اتصال و استقرار خودکار');
    flash('success', '✅ تنظیمات cPanel و استقرار خودکار ذخیره شد.');
    redirect('cpanel-settings.php');
}

/* 🔄 بازگشت الگو به پیش‌فرض */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_pattern') {
    Auth::enforceCsrf();
    PathResolver::resetPattern();
    flash('success', 'الگوی مسیر به پیش‌فرض بازگشت.');
    redirect('cpanel-settings.php');
}

$pageTitle = 'تنظیمات cPanel و استقرار خودکار';
$activeMenu = 'cpanel-settings';
require __DIR__ . '/includes/header.php';

$s = PathResolver::getSettings();
$hasToken = !empty($s['cpanel_token_enc']);
$hasFtpPass = !empty($s['ftp_password_enc']);
$presets = PathResolver::getPresetPatterns();
$variables = PathResolver::getPatternVariables();
$previews = PathResolver::previewPath((string)($s['doc_root_pattern'] ?? PathResolver::DEFAULT_PATTERN));
$cronBase = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
// مسیر واقعی نصب روی سرور — دقیقاً همان جایی که فایل‌های cron قرار دارند
?>

<form method="post" id="settings-form">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="save_settings">
    <input type="hidden" name="doc_root_preset" id="doc_root_preset" value="<?= e($s['doc_root_preset'] ?? 'default') ?>">

    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><h3>🔌 اتصال cPanel</h3></div>
        <div class="card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label>آدرس سرور cPanel (بدون پورت)</label>
                    <input type="text" name="cpanel_host" class="form-control" dir="ltr" value="<?= e($s['cpanel_host'] ?? '') ?>" placeholder="ea-fixer.ir">
                </div>
                <div class="form-group">
                    <label>پورت</label>
                    <input type="number" name="cpanel_port" class="form-control" dir="ltr" value="<?= e((string)($s['cpanel_port'] ?? 2083)) ?>">
                </div>
                <div class="form-group">
                    <label>پروتکل</label>
                    <select name="cpanel_protocol" class="form-control">
                        <option value="https" <?= ($s['cpanel_protocol'] ?? 'https') === 'https' ? 'selected' : '' ?>>HTTPS (پیش‌فرض)</option>
                        <option value="http" <?= ($s['cpanel_protocol'] ?? '') === 'http' ? 'selected' : '' ?>>HTTP</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>نام کاربری هاست</label>
                    <input type="text" name="cpanel_username" class="form-control" dir="ltr" value="<?= e($s['cpanel_username'] ?? '') ?>" placeholder="username">
                </div>
                <div class="form-group">
                    <label>API Token <?= $hasToken ? '<span class="badge badge-success">ثبت شده ✅</span>' : '<span class="badge badge-secondary">ثبت نشده</span>' ?></label>
                    <input type="password" name="cpanel_token" class="form-control" dir="ltr" placeholder="<?= $hasToken ? '•••••• (بدون تغییر خالی بگذارید)' : 'توکن را اینجا وارد کنید' ?>" autocomplete="new-password">
                    <small>📖 دریافت: cPanel ▸ Security ▸ Manage API Tokens ▸ نام SahandBrandMaker</small>
                </div>
                <div class="form-group">
                    <label>دامنه اصلی (ریشه زیردامنه‌ها)</label>
                    <input type="text" name="root_domain" class="form-control" dir="ltr" value="<?= e($s['root_domain'] ?? '') ?>" placeholder="ea-fixer.ir">
                </div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px">
                <button type="button" class="btn btn-outline" onclick="testConnection('cpanel')">🔍 تست اتصال</button>
                <span id="cpanel-test-result" class="test-result"></span>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><h3>📂 الگوی مسیر Document Root زیردامنه‌ها</h3></div>
        <div class="card-body">
            <div class="form-group">
                <label>الگوی فعلی</label>
                <input type="text" name="doc_root_pattern" id="doc_root_pattern" class="form-control" dir="ltr" value="<?= e($s['doc_root_pattern'] ?? PathResolver::DEFAULT_PATTERN) ?>" oninput="onPatternChange()">
            </div>

            <label style="margin:10px 0 6px">الگوهای آماده:</label>
            <div class="preset-list">
                <?php foreach ($presets as $key => [$pattern, $recommended]): ?>
                    <label class="preset-item">
                        <input type="radio" name="preset_radio" value="<?= e($key) ?>" data-pattern="<?= e($pattern) ?>" <?= ($s['doc_root_preset'] ?? 'default') === $key ? 'checked' : '' ?> onchange="selectPreset(this)">
                        <code dir="ltr"><?= e($pattern) ?></code>
                        <?php if ($recommended): ?><span class="badge badge-success">توصیه‌شده</span><?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <details style="margin-top:12px">
                <summary style="cursor:pointer">📖 متغیرهای قابل استفاده در الگو</summary>
                <div class="table-wrap" style="margin-top:8px">
                    <table class="table">
                        <thead><tr><th>متغیر</th><th>توضیح</th><th>مثال</th></tr></thead>
                        <tbody>
                        <?php foreach ($variables as $var => [$desc, $example]): ?>
                            <tr><td><code dir="ltr"><?= e($var) ?></code></td><td><?= e($desc) ?></td><td dir="ltr"><?= e($example) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>

            <div style="margin-top:12px">
                <label>🔍 پیش‌نمایش با برندهای نمونه:</label>
                <div id="pattern-previews" class="preview-box">
                    <?php foreach ($previews as $p): ?>
                        <div>برند «<?= e($p['brand']) ?>» ← <code dir="ltr"><?= e($p['path']) ?></code></div>
                    <?php endforeach; ?>
                </div>
                <span id="pattern-error" class="field-error"></span>
            </div>

            <div class="alert alert-warning" style="margin-top:12px">
                ⚠️ تغییر این الگو فقط برای <b>برندهای جدید</b> اعمال می‌شود — برای برندهای موجود، مسیر قبلی حفظ می‌شود.
            </div>

            <button type="button" class="btn btn-outline" onclick="resetPattern()">🔄 بازگشت به پیش‌فرض</button>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><h3>📂 تنظیمات FTP (جایگزین آپلود)</h3></div>
        <div class="card-body">
            <p class="hint">💡 اگر cPanel API محدودیت داشت (Fileman غیرفعال)، آپلود فایل‌ها از FTP انجام می‌شود. ساخت زیردامنه همچنان از API است.</p>
            <div class="form-grid">
                <div class="form-group">
                    <label>آدرس FTP</label>
                    <input type="text" name="ftp_host" class="form-control" dir="ltr" value="<?= e($s['ftp_host'] ?? '') ?>" placeholder="ftp.ea-fixer.ir">
                </div>
                <div class="form-group">
                    <label>پورت</label>
                    <input type="number" name="ftp_port" class="form-control" dir="ltr" value="<?= e((string)($s['ftp_port'] ?? 21)) ?>">
                </div>
                <div class="form-group">
                    <label>نام کاربری FTP</label>
                    <input type="text" name="ftp_username" class="form-control" dir="ltr" value="<?= e($s['ftp_username'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>رمز عبور <?= $hasFtpPass ? '<span class="badge badge-success">ثبت شده ✅</span>' : '' ?></label>
                    <input type="password" name="ftp_password" class="form-control" dir="ltr" placeholder="<?= $hasFtpPass ? '•••••• (بدون تغییر خالی بگذارید)' : '' ?>" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label>گزینه‌ها</label>
                    <div>
                        <label style="display:inline-flex;gap:6px;align-items:center;margin-inline-end:14px">
                            <input type="checkbox" name="ftp_passive" <?= !empty($s['ftp_passive']) ? 'checked' : '' ?>> حالت Passive
                        </label>
                        <label style="display:inline-flex;gap:6px;align-items:center">
                            <input type="checkbox" name="ftp_ssl" <?= !empty($s['ftp_ssl']) ? 'checked' : '' ?>> SSL/TLS (FTPS)
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label>پوشه بکاپ‌ها (خالی = پیش‌فرض brands/backups)</label>
                    <input type="text" name="backup_dir" class="form-control" dir="ltr" value="<?= e($s['backup_dir'] ?? '') ?>" placeholder="public_html/brands/backups">
                </div>
                <div class="form-group">
                    <label>💾 تعداد بکاپ نگهداری‌شده هر برند (🆕 v2.29)</label>
                    <input type="number" name="backup_keep_count" class="form-control" dir="ltr" min="1" max="50" value="<?= (int)($s['backup_keep_count'] ?? 5) ?>">
                    <div class="hint" style="margin-top:5px">سقف پیش‌فرض نگهداری بکاپ برای همه برندها (۱ تا ۵۰) — با ساخته شدن بکاپ جدیدتر، قدیمی‌ترین‌ها خودکار حذف می‌شوند. برای یک برند خاص می‌توانید از صفحه «بکاپ‌ها» سقف اختصاصی تعیین کنید.</div>
                </div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <button type="button" class="btn btn-outline" onclick="testConnection('ftp')">🔍 تست FTP</button>
                <span id="ftp-test-result" class="test-result"></span>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><h3>🔧 تنظیمات استقرار</h3></div>
        <div class="card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label>گزینه‌های اصلی</label>
                    <div style="display:flex;flex-direction:column;gap:6px">
                        <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="deploy_enabled" <?= !empty($s['deploy_enabled']) ? 'checked' : '' ?>> <b>🚀 استقرار خودکار فعال</b> (کل قابلیت)</label>
                        <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="ssl_auto" <?= !isset($s['ssl_auto']) || !empty($s['ssl_auto']) ? 'checked' : '' ?>> 🔒 نصب خودکار SSL</label>
                        <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="backup_before_update" <?= !isset($s['backup_before_update']) || !empty($s['backup_before_update']) ? 'checked' : '' ?>> 💾 بکاپ قبل از بروزرسانی</label>
                        <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="auto_test" <?= !isset($s['auto_test']) || !empty($s['auto_test']) ? 'checked' : '' ?>> 🧪 تست خودکار پس از استقرار</label>
                        <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="notify_after_deploy" <?= !isset($s['notify_after_deploy']) || !empty($s['notify_after_deploy']) ? 'checked' : '' ?>> 🔔 اعلان پس از استقرار موفق</label>
                    </div>
                </div>
                <div class="form-group">
                    <label>فراهم‌کننده SSL</label>
                    <select name="ssl_provider" class="form-control">
                        <option value="letsencrypt" <?= ($s['ssl_provider'] ?? 'letsencrypt') === 'letsencrypt' ? 'selected' : '' ?>>Let's Encrypt (توصیه‌شده)</option>
                        <option value="cpanel" <?= ($s['ssl_provider'] ?? '') === 'cpanel' ? 'selected' : '' ?>>AutoSSL داخلی cPanel</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>روش آپلود فایل‌ها</label>
                    <select name="upload_mode" class="form-control">
                        <option value="api" <?= ($s['upload_mode'] ?? 'api') === 'api' ? 'selected' : '' ?>>cPanel API (توصیه‌شده)</option>
                        <option value="ftp" <?= ($s['upload_mode'] ?? '') === 'ftp' ? 'selected' : '' ?>>FTP</option>
                    </select>
                    <small>⚠️ در حالت API اگر آپلود ناموفق بود، خودکار به FTP و بعد Session سوئیچ می‌شود.</small>
                </div>
                <div class="form-group">
                    <label>حداکثر حجم بسته (مگابایت)</label>
                    <input type="number" name="max_upload_mb" class="form-control" dir="ltr" value="<?= e((string)($s['max_upload_mb'] ?? 50)) ?>">
                </div>
                <div class="form-group">
                    <label>Timeout عملیات (ثانیه)</label>
                    <input type="number" name="timeout_seconds" class="form-control" dir="ltr" value="<?= e((string)($s['timeout_seconds'] ?? 120)) ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><h3>⏰ Cron Jobs مورد نیاز</h3></div>
        <div class="card-body">
            <?php
            /* 🔑 توکن cron برای حالت curl (نمایش در راهنما — ساخته می‌شود اگر نباشد) */
            $cronTokenShow = (string)Config::get('cron_secret_token', '');
            $cronUrlShow = rtrim(BASE_URL, '/') . '/cron/';
            ?>
            <!-- 🩺 v2.38 — راهنمای رفع ایمیل‌های «Access denied / 403» -->
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 14px;font-size:12.5px;line-height:2;color:#92400e;margin-bottom:14px">
                ⚠️ <b>ایمیل «Access denied / 403» از هاست می‌گیرید؟</b> برخی هاست‌ها اجرای مستقیم <code dir="ltr">php</code> در Cron را مسدود می‌کنند.
                در این صورت حالت <b>«🌐 curl / HTTP»</b> را انتخاب کنید — وظایف از طریق آدرس وب با توکن امن اجرا می‌شوند و هیچ مسدودیتی ندارند.
            </div>

            <!-- 🎚️ انتخاب حالت فرمان -->
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
                <label class="cron-mode-card selected" id="cron-mode-cli" onclick="setCronMode('cli')">
                    <input type="radio" name="cron_mode" value="cli" checked>
                    <b>🐘 PHP CLI</b>
                    <span>اجرای مستقیم php — روش استاندارد</span>
                </label>
                <label class="cron-mode-card" id="cron-mode-curl" onclick="setCronMode('curl')">
                    <input type="radio" name="cron_mode" value="curl">
                    <b>🌐 curl / HTTP</b>
                    <span>برای هاست‌های مسدودکننده‌ی php</span>
                </label>
            </div>

            <p class="hint">هر وظیفه را با یک کلیک به cPanel اضافه کنید — یا هر چهار وظیفه را یکجا:</p>
            <div style="margin-bottom:12px">
                <button type="button" class="btn btn-primary" onclick="addCronToCpanel('all', this)">⚡ افزودن همه به cPanel</button>
            </div>
            <div class="cron-job-list" id="cron-job-list">
                <div class="cron-job-row">
                    <div class="cron-job-info">
                        <div class="cron-job-title">🧭 وظیفه اصلی — هر ۵ دقیقه (انتشار زمان‌بندی‌شده + وب‌هوک‌ها + نظافت)</div>
                        <code dir="ltr" class="cron-cmd cli-cmd">*/5 * * * * php <?= e($cronBase) ?>/cron/cron.php</code>
                        <code dir="ltr" class="cron-cmd curl-cmd" style="display:none">*/5 * * * * curl -s -o /dev/null "<?= e($cronUrlShow) ?>cron.php?token=..."</code>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" onclick="addCronToCpanel('main', this)">➕ افزودن به cPanel</button>
                </div>
                <div class="cron-job-row">
                    <div class="cron-job-info">
                        <div class="cron-job-title">🩺 بررسی سلامت سایت‌ها — هر ۱۵ دقیقه</div>
                        <code dir="ltr" class="cron-cmd cli-cmd">*/15 * * * * php <?= e($cronBase) ?>/cron/health-check.php</code>
                        <code dir="ltr" class="cron-cmd curl-cmd" style="display:none">*/15 * * * * curl -s -o /dev/null "<?= e($cronUrlShow) ?>health-check.php?token=..."</code>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" onclick="addCronToCpanel('health-check', this)">➕ افزودن به cPanel</button>
                </div>
                <div class="cron-job-row">
                    <div class="cron-job-info">
                        <div class="cron-job-title">🔒 بررسی SSL — روزانه ساعت ۳ صبح</div>
                        <code dir="ltr" class="cron-cmd cli-cmd">0 3 * * * php <?= e($cronBase) ?>/cron/ssl-check.php</code>
                        <code dir="ltr" class="cron-cmd curl-cmd" style="display:none">0 3 * * * curl -s -o /dev/null "<?= e($cronUrlShow) ?>ssl-check.php?token=..."</code>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" onclick="addCronToCpanel('ssl-check', this)">➕ افزودن به cPanel</button>
                </div>
                <div class="cron-job-row">
                    <div class="cron-job-info">
                        <div class="cron-job-title">💾 بکاپ خودکار هفتگی — شنبه ساعت ۲ صبح</div>
                        <code dir="ltr" class="cron-cmd cli-cmd">0 2 * * 6 php <?= e($cronBase) ?>/cron/backup.php</code>
                        <code dir="ltr" class="cron-cmd curl-cmd" style="display:none">0 2 * * 6 curl -s -o /dev/null "<?= e($cronUrlShow) ?>backup.php?token=..."</code>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" onclick="addCronToCpanel('backup', this)">➕ افزودن به cPanel</button>
                </div>
            </div>
            <div id="cron-add-result" class="test-result" style="margin-top:10px;white-space:pre-line"></div>
            <button type="button" class="btn btn-outline" style="margin-top:10px" onclick="copyCron()">📋 کپی دستی دستورات (حالت فعلی)</button>

            <!-- 📋 v2.38 — مدیریت Cronهای نصب‌شده در cPanel -->
            <div style="margin-top:22px;padding-top:18px;border-top:1px dashed var(--border)">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px">
                    <b style="font-size:14px">📋 Cronهای نصب‌شده در cPanel</b>
                    <button type="button" class="btn btn-outline btn-sm" onclick="loadInstalledCrons(this)">🔄 بروزرسانی فهرست</button>
                </div>
                <div id="installed-crons" style="font-size:12.5px;color:var(--text-light)">برای دیدن فهرست، «بروزرسانی فهرست» را بزنید (نیازمند اتصال cPanel سالم).</div>
            </div>
        </div>
    </div>

    <div style="position:sticky;bottom:12px;z-index:5">
        <button type="submit" class="btn btn-primary" style="width:100%;box-shadow:0 4px 14px rgba(0,0,0,.18)">💾 ذخیره تنظیمات</button>
    </div>
</form>

<style>
.form-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px}
.preset-list{display:flex;flex-direction:column;gap:6px}
.preset-item{display:flex;gap:8px;align-items:center;padding:8px 10px;border:1px solid var(--border);border-radius:8px;cursor:pointer;transition:.15s}
.preset-item:hover{background:var(--bg-secondary,#f8fafc)}
.preview-box{background:#f1f5f9;border:1px dashed var(--border);border-radius:8px;padding:10px 14px;line-height:2}
.test-result{font-size:13px;white-space:pre-line}
.cron-job-list{display:flex;flex-direction:column;gap:10px}
.cron-job-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border:1px solid var(--border);border-radius:10px;background:var(--bg-secondary,#f8fafc);transition:.15s}
.cron-job-row:hover{border-color:#2563eb}
.cron-job-info{display:flex;flex-direction:column;gap:6px;min-width:0}
.cron-job-title{font-size:13px;font-weight:700}
.cron-cmd{direction:ltr;text-align:left;font-size:11.5px;background:#0f172a;color:#e2e8f0;padding:8px 12px;border-radius:6px;overflow:auto;white-space:nowrap;max-width:100%}
/* 🎚 v2.38 — کارت‌های حالت فرمان Cron */
.cron-mode-card{flex:1;min-width:210px;border:1.5px solid var(--border);border-radius:11px;padding:10px 14px;cursor:pointer;transition:.15s;background:var(--card,#fff);display:flex;flex-direction:column;gap:3px;font-size:12.5px}
.cron-mode-card:hover{border-color:#93c5fd}
.cron-mode-card.selected{border-color:var(--primary);background:rgba(37,99,235,.06);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.cron-mode-card input{display:none}
.cron-mode-card span{font-size:11px;color:var(--text-light,#64748b)}
/* 📋 v2.38 — فهرست Cronهای نصب‌شده */
.cron-installed-list{display:flex;flex-direction:column;gap:8px}
.cron-installed-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--card,#fff)}
.cron-installed-row .cron-job-title{font-size:11.5px;font-family:monospace;direction:ltr}
.btn-sm{padding:6px 12px;font-size:12px;white-space:nowrap}
.field-error{color:var(--danger,#dc2626);font-size:12.5px}
.hint{color:var(--text-light);font-size:12.5px;margin-bottom:10px}
</style>

<script>
/* 🔍 تست اتصال (cPanel / FTP) */
function testConnection(kind) {
    const resultEl = document.getElementById(kind + '-test-result');
    resultEl.textContent = '⏳ در حال تست...';
    resultEl.style.color = '';

    const form = new FormData();
    form.append('action', kind === 'cpanel' ? 'test_cpanel' : 'test_ftp');
    form.append('csrf_token', '<?= e($_SESSION['csrf_token'] ?? '') ?>');
    if (kind === 'cpanel') {
        form.append('cpanel_host', document.querySelector('[name=cpanel_host]').value);
        form.append('cpanel_port', document.querySelector('[name=cpanel_port]').value);
        form.append('cpanel_username', document.querySelector('[name=cpanel_username]').value);
        const t = document.querySelector('[name=cpanel_token]').value;
        if (t) form.append('cpanel_token_test', t);
    } else {
        form.append('ftp_host', document.querySelector('[name=ftp_host]').value);
        form.append('ftp_port', document.querySelector('[name=ftp_port]').value);
        form.append('ftp_username', document.querySelector('[name=ftp_username]').value);
        const p = document.querySelector('[name=ftp_password]').value;
        if (p) form.append('ftp_password_test', p);
    }

    fetch('cpanel-settings.php', {method: 'POST', body: form})
        .then(r => r.json())
        .then(data => {
            resultEl.textContent = (data.success ? '✅ ' : '❌ ') + data.message;
            resultEl.style.color = data.success ? '#16a34a' : '#dc2626';
        })
        .catch(() => { resultEl.textContent = '❌ خطای شبکه'; resultEl.style.color = '#dc2626'; });
}

/* 📂 انتخاب الگوی آماده */
function selectPreset(radio) {
    document.getElementById('doc_root_pattern').value = radio.dataset.pattern;
    document.getElementById('doc_root_preset').value = radio.value;
    validatePattern();
}

/* ✏️ تغییر دستی الگو */
let patternTimer = null;
function onPatternChange() {
    document.getElementById('doc_root_preset').value = 'custom';
    // رادیوها از حالت انتخاب خارج شوند
    document.querySelectorAll('input[name=preset_radio]').forEach(r => r.checked = false);
    clearTimeout(patternTimer);
    patternTimer = setTimeout(validatePattern, 500);
}

/* ✅ اعتبارسنجی زنده الگو + پیش‌نمایش */
function validatePattern() {
    const pattern = document.getElementById('doc_root_pattern').value;
    const form = new FormData();
    form.append('action', 'validate_pattern');
    form.append('pattern', pattern);
    form.append('csrf_token', '<?= e($_SESSION['csrf_token'] ?? '') ?>');
    fetch('cpanel-settings.php', {method: 'POST', body: form})
        .then(r => r.json())
        .then(data => {
            const errEl = document.getElementById('pattern-error');
            const box = document.getElementById('pattern-previews');
            if (data.success) {
                errEl.textContent = '✅ الگو معتبر است';
                errEl.style.color = '#16a34a';
                box.innerHTML = data.previews.map(p => `<div>برند «${p.brand}» ← <code dir="ltr">${p.path}</code></div>`).join('');
            } else {
                errEl.textContent = '❌ ' + data.error;
                errEl.style.color = '#dc2626';
            }
        });
}

/* 🔄 بازگشت به پیش‌فرض */
async function resetPattern() {
    const ok = await sahandConfirm({
        title: 'بازگردانی الگوی مسیر',
        message: 'الگوی مسیر به پیش‌فرض (/public_html/brands/{brand_slug}) بازگردانده شود؟',
        type: 'question', icon: '🧭',
        confirmText: 'بله، بازگردانی کن',
    });
    if (!ok) return;
    const form = new FormData();
    form.append('action', 'reset_pattern');
    form.append('csrf_token', '<?= e($_SESSION['csrf_token'] ?? '') ?>');
    fetch('cpanel-settings.php', {method: 'POST', body: form}).then(() => location.reload());
}

/* 📋 کپی دستورات cron */
function copyCron() {
    /* 🎚 v2.38 — فقط دستورات «حالت فعلی» کپی می‌شوند */
    const visible = [...document.querySelectorAll('.cron-cmd')].filter(c => c.style.display !== 'none');
    const text = visible.map(c => c.textContent).join('\n');
    navigator.clipboard.writeText(text.trim()).then(() => {
        sahandToast({ message: 'دستورات Cron (حالت ' + (cronMode === 'curl' ? 'curl/HTTP' : 'PHP CLI') + ') کپی شد — در cPanel » Advanced » Cron Jobs جای‌گذاری کنید', type: 'success', icon: '📋', duration: 4500 });
    });
}

/* 🎚 v2.38 — حالت فرمان Cron: cli یا curl */
let cronMode = 'cli';
function setCronMode(mode) {
    cronMode = mode;
    document.getElementById('cron-mode-cli').classList.toggle('selected', mode === 'cli');
    document.getElementById('cron-mode-curl').classList.toggle('selected', mode === 'curl');
    document.querySelectorAll('.cli-cmd').forEach(el => el.style.display = mode === 'cli' ? '' : 'none');
    document.querySelectorAll('.curl-cmd').forEach(el => el.style.display = mode === 'curl' ? '' : 'none');
}

/* 📋 v2.38 — فهرست Cronهای نصب‌شده در cPanel */
function loadInstalledCrons(btn) {
    const box = document.getElementById('installed-crons');
    const original = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '⏳'; }
    box.innerHTML = '<span style="color:var(--text-light)">⏳ در حال خواندن فهرست از cPanel...</span>';
    const form = new FormData();
    form.append('action', 'list_crons');
    form.append('csrf_token', '<?= e($_SESSION['csrf_token'] ?? '') ?>');
    fetch('cpanel-settings.php', {method: 'POST', body: form})
        .then(r => r.json())
        .then(data => {
            if (btn) { btn.disabled = false; btn.innerHTML = original; }
            if (!data.success) {
                box.innerHTML = '<span style="color:#dc2626">❌ ' + (data.message || 'خطا') + '</span>';
                return;
            }
            const crons = data.crons || [];
            if (!crons.length) {
                box.innerHTML = '<span style="color:var(--text-light)">هیچ Cronی در cPanel ثبت نشده است.</span>';
                return;
            }
            let html = '<div class="cron-installed-list">';
            crons.forEach(c => {
                const schedule = [c.minute, c.hour, c.day, c.month, c.weekday].join(' ');
                const isOurs = /\/cron\/(cron|health-check|ssl-check|backup)\.php/.test(c.command || '');
                html += '<div class="cron-installed-row">' +
                    '<div class="cron-job-info"><div class="cron-job-title">' + (isOurs ? '🟢 ' : '⚪ ') + schedule + '</div>' +
                    '<code dir="ltr" class="cron-cmd">' + (c.command || '').replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</code></div>' +
                    '<button type="button" class="btn btn-danger btn-sm" onclick="removeCronLine(\'' + String(c.linekey || c.count || '').replace(/'/g, '') + '\', this)">🗑 حذف</button></div>';
            });
            html += '</div>';
            box.innerHTML = html;
        })
        .catch(() => {
            if (btn) { btn.disabled = false; btn.innerHTML = original; }
            box.innerHTML = '<span style="color:#dc2626">❌ خطای شبکه</span>';
        });
}

/* 🗑 v2.38 — حذف یک Cron از cPanel */
function removeCronLine(linekey, btn) {
    sahandConfirm({ title: 'حذف Cron', message: 'این زمان‌بندی از cPanel حذف شود؟', type: 'danger', confirmText: 'بله، حذف کن' })
        .then(ok => {
            if (!ok) { return; }
            btn.disabled = true;
            btn.innerHTML = '⏳';
            const form = new FormData();
            form.append('action', 'remove_cron');
            form.append('linekey', linekey);
            form.append('csrf_token', '<?= e($_SESSION['csrf_token'] ?? '') ?>');
            fetch('cpanel-settings.php', {method: 'POST', body: form})
                .then(r => r.json())
                .then(data => {
                    sahandToast({ message: data.message || (data.success ? 'حذف شد' : 'حذف ناموفق'), type: data.success ? 'success' : 'danger', icon: data.success ? '🗑' : '⛔' });
                    if (data.success) { loadInstalledCrons(null); } else { btn.disabled = false; btn.innerHTML = '🗑 حذف'; }
                })
                .catch(() => { btn.disabled = false; btn.innerHTML = '🗑 حذف'; });
        });
}

/* ⏰ افزودن خودکار دستور cron به cPanel */
function addCronToCpanel(job, btn) {
    const resultEl = document.getElementById('cron-add-result');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ در حال افزودن...';
    resultEl.textContent = '';
    resultEl.style.color = '';

    const form = new FormData();
    form.append('action', 'add_cron');
    form.append('job', job);
    form.append('mode', cronMode);
    form.append('csrf_token', '<?= e($_SESSION['csrf_token'] ?? '') ?>');

    fetch('cpanel-settings.php', {method: 'POST', body: form})
        .then(r => r.json())
        .then(data => {
            resultEl.textContent = (data.success ? '' : '❌ ') + (data.message || '');
            resultEl.style.color = data.success ? '#16a34a' : '#dc2626';
            btn.innerHTML = data.success ? '✅ اضافه شد' : original;
            if (data.success) setTimeout(() => { btn.innerHTML = original; }, 2500);
            /* 💬 دیالوگ زیبا برای نتیجه افزودن Cron (به‌جای متن خام) */
            sahandAlert({
                title: data.success ? '⏰ Cron ثبت شد' : 'ثبت Cron ناموفق بود',
                message: data.message || '',
                type: data.success ? 'success' : 'danger',
                icon: data.success ? '⏰' : '⛔',
                confirmText: 'متوجه شدم',
            });
        })
        .catch(() => {
            resultEl.textContent = '❌ خطای شبکه — دوباره تلاش کنید';
            resultEl.style.color = '#dc2626';
            btn.innerHTML = original;
            sahandAlert({ title: 'خطای شبکه', message: 'ارتباط با سرور برقرار نشد — دوباره تلاش کنید.', type: 'danger', icon: '🌐' });
        })
        .finally(() => { btn.disabled = false; });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
