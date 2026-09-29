<?php
/**
 * 🗃️ مرکز مهاجرت دیتابیس (S14 / v2.43)
 * =======================================
 * درخواست کاربر: «مهاجرت‌ها از مسیر درخواست عادی جدا شود —
 * نصب/بروزرسانی ← Migration Runner ← Database».
 *
 * این صفحه:
 *   • وضعیت تک‌تک مهاجرت‌ها (اجرا شده / در انتظار) را نشان می‌دهد
 *   • دکمه «اجرای مهاجرت‌های در انتظار» زنجیره را با قفل اجرا می‌کند
 *   • پشتیبانی CLI:  php admin/migrate.php --run   (برای cron/SSH)
 *
 * @package SahandBrandMaker\Admin
 */
if (PHP_SAPI === 'cli') {
    /* 🖥️ حالت CLI — بدون نشست/CSRF */
    define('SAHAND_INIT', true);
    require dirname(__DIR__) . '/config.php';
    $wantRun = in_array('--run', $argv ?? [], true);
    echo "🗂️ مرکز مهاجرت دیتابیس — " . count(MigrationRunner::registry()) . " مهاجرت ثبت شده\n";
    foreach (MigrationRunner::status() as $row) {
        echo ($row['applied'] ? '  ✅ ' : '  ⏳ ') . $row['file'] . ' — ' . $row['name'] . "\n";
    }
    if ($wantRun) {
        $res = MigrationRunner::run();
        echo "▶️ اجرا شد: " . count($res['applied']) . " | رد شده: " . count($res['skipped']) . " | خطا: " . count($res['failed']) . "\n";
        foreach ($res['failed'] as $name => $err) {
            echo "  ❌ $name: $err\n";
        }
    }
    exit(0);
}

define('SAHAND_INIT', true);
require dirname(__DIR__) . '/config.php';

$auth = new Auth();
$auth->requireLogin();
$auth->requirePermission('admin'); /* فقط مدیر — تغییر ساختار دیتابیس */

$pageTitle = 'مهاجرت دیتابیس';
$activeMenu = 'settings';

/* ▶️ اجرای درخواستی */
$runResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'run_migrations') {
    Auth::enforceCsrf();
    if (function_exists('set_time_limit')) { @set_time_limit(300); }
    $runResult = MigrationRunner::run();
    Logger::activity((int)$_SESSION['user_id'], 'اجرای مهاجرت دیتابیس',
        'اعمال‌شده: ' . count($runResult['applied']) . ' | خطا: ' . count($runResult['failed']));
    flash(count($runResult['failed']) === 0 ? 'success' : 'warning',
        count($runResult['applied']) > 0
            ? '✅ ' . count($runResult['applied']) . ' مهاجرت با موفقیت اعمال شد.'
            : 'ℹ️ مهاجرت در انتظاری نبود — ساختار دیتابیس به‌روز است.');
    foreach ($runResult['failed'] as $mName => $mErr) {
        flash('danger', '❌ ' . $mName . ': ' . mb_substr($mErr, 0, 200));
    }
    redirect('migrate.php');
}

$rows = MigrationRunner::status();
$pending = array_filter($rows, static fn($r) => !$r['applied']);
require __DIR__ . '/includes/header.php';
?>
<div class="card" style="margin-bottom:18px">
    <div class="card-header">
        <h3>🗃️ مرکز مهاجرت دیتابیس</h3>
        <span class="badge <?= $pending ? 'badge-warning' : 'badge-success' ?>">
            <?= $pending ? '⏳ ' . count($pending) . ' در انتظار' : '✅ همه اعمال شده' ?>
        </span>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <b>معماری جدید (S14):</b> مهاجرت‌ها دیگر در هر درخواست صفحه اجرا نمی‌شوند —
            فقط پس از آپلود نسخه جدید، <b>یک‌بار</b> و با قفل امن اجرا می‌شوند (چک نشانگر: میکروثانیه).
            اجرای دستی از همین صفحه یا CLI: <code dir="ltr">php admin/migrate.php --run</code>
        </div>

        <?php if ($pending): ?>
        <form method="post" style="margin-bottom:14px">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="run_migrations">
            <button type="submit" class="btn btn-primary">▶️ اجرای <?= count($pending) ?> مهاجرت در انتظار</button>
        </form>
        <?php endif; ?>

        <table class="dt">
            <thead><tr><th>#</th><th>فایل</th><th>شناسه</th><th>وضعیت</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r): ?>
                <tr>
                    <td class="n"><?= e(en_to_fa_digits((string)($i + 1))) ?></td>
                    <td style="direction:ltr;text-align:left;font-size:11.5px"><?= e($r['file']) ?></td>
                    <td style="direction:ltr;text-align:left;font-size:11px;color:var(--text-light)"><?= e($r['name']) ?></td>
                    <td><?= $r['applied'] ? '<span class="badge badge-success">✅ اعمال شده</span>' : '<span class="badge badge-warning">⏳ در انتظار</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="hint" style="margin-top:12px">
            📦 نصب تازه از <code dir="ltr">database.sql</code> انجام می‌شود و مهاجرت‌ها فقط برای
            <b>بروزرسانی</b> نصب‌های موجود لازم‌اند. هر مهاجرت idempotent است و اجرای دوباره آن بی‌ضرر است.
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
