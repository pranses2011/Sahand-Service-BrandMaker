<?php
/**
 * 💾 مدیریت بکاپ‌های سایت‌های برند
 * ================================
 * طبق سند بخش ۲۱ (پرامپت تکمیلی بخش ۱):
 *   - نمایش بکاپ‌ها با تاریخ شمسی زیبا
 *   - وضعیت «X از ۵ بکاپ» + حجم کل
 *   - عملیات هر بکاپ: دانلود / بازیابی / حذف
 *   - بکاپ‌گیری فوری دستی
 *   - بازیابی با تأیید + بکاپ ایمنی
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();
$backupMgr = new BackupManager();
$api = new CpanelAPI();

/* 💾 بکاپ فوری */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_backup') {
    Auth::enforceCsrf();
    $brandId = (int)post('brand_id');
    $result = $backupMgr->createBackup($brandId, 'manual');
    Logger::activity((int)$_SESSION['user_id'], 'بکاپ دستی', $result['message']);
    flash($result['success'] ? 'success' : 'danger', $result['message']);
    redirect('backups.php?brand_id=' . $brandId);
}

/* 🔄 بازیابی */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_backup') {
    Auth::enforceCsrf();
    $backupId = (int)post('backup_id');
    $backup = $db->fetch('SELECT * FROM backups WHERE id = ?', [$backupId]);
    if (!$backup) {
        flash('danger', 'بکاپ یافت نشد.');
        redirect('backups.php');
    }
    // بکاپ ایمنی قبل از بازیابی در BackupManager گرفته می‌شود
    $result = $backupMgr->restoreBackup($backupId);
    Logger::activity((int)$_SESSION['user_id'], 'بازیابی بکاپ', $backup['filename'] . ' — ' . ($result['success'] ? 'موفق' : $result['message']));
    flash($result['success'] ? 'success' : 'danger', $result['message']);
    redirect('backups.php?brand_id=' . (int)$backup['brand_id']);
}

/* 🗑️ حذف بکاپ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_backup') {
    Auth::enforceCsrf();
    $backupId = (int)post('backup_id');
    $backup = $db->fetch('SELECT * FROM backups WHERE id = ?', [$backupId]);
    $result = $backupMgr->deleteBackup($backupId);
    Logger::activity((int)$_SESSION['user_id'], 'حذف بکاپ', $backup['filename'] ?? '');
    flash($result['success'] ? 'success' : 'danger', $result['message']);
    redirect('backups.php' . ($backup ? '?brand_id=' . (int)$backup['brand_id'] : ''));
}

/* ⬇️ دانلود بکاپ — انتقال از سرور میزبان به مرورگر */
if (($_GET['download'] ?? '') !== '') {
    $backupId = (int)$_GET['download'];
    $backup = $db->fetch('SELECT * FROM backups WHERE id = ?', [$backupId]);
    if (!$backup) { http_response_code(404); exit('یافت نشد'); }

    Logger::activity((int)$_SESSION['user_id'], 'دانلود بکاپ', $backup['filename']);

    // خواندن محتوا از سرور میزبان از طریق cPanel API
    $content = $api->readFile((string)$backup['file_path']);
    if ($content === '') {
        flash('danger', 'خواندن فایل بکاپ از سرور ناموفق بود: ' . $api->getLastError());
        redirect('backups.php?brand_id=' . (int)$backup['brand_id']);
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename((string)$backup['filename']) . '"');
    header('Content-Length: ' . strlen($content));
    header('X-Robots-Tag: noindex');
    echo $content;
    exit;
}

$pageTitle = 'بکاپ‌ها';
$activeMenu = 'backups';
require __DIR__ . '/includes/header.php';

/* 📋 برندهای مستقر */
$deployedBrands = $db->fetchAll('SELECT id, name_fa, name_en, slug FROM brands WHERE is_deployed = 1 AND is_active = 1 ORDER BY name_fa');
$brandId = (int)get_param('brand_id', (string)($deployedBrands[0]['id'] ?? '0'));

/* 📊 لیست بکاپ‌های برند انتخاب‌شده */
$backups = $brandId > 0 ? $backupMgr->getBackupsList($brandId) : [];
$stats = $brandId > 0 ? $backupMgr->getStats($brandId) : ['count' => 0, 'max' => 5, 'total_size' => 0];
$selectedBrand = null;
foreach ($deployedBrands as $b) { if ((int)$b['id'] === $brandId) { $selectedBrand = $b; break; } }
?>

<div class="card" style="margin-bottom:16px">
    <div class="card-header">
        <h3>💾 مدیریت بکاپ‌ها</h3>
        <form method="get" style="display:flex;gap:8px">
            <select name="brand_id" class="form-control" onchange="this.form.submit()" style="min-width:220px">
                <?php if (empty($deployedBrands)): ?>
                    <option value="">برند مستقری وجود ندارد</option>
                <?php endif; ?>
                <?php foreach ($deployedBrands as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= (int)$b['id'] === $brandId ? 'selected' : '' ?>><?= e($b['name_fa']) ?> (<?= e($b['slug']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <?php if ($selectedBrand): ?>
            <button type="submit" class="btn btn-primary" formmethod="post" onclick="if(!confirm('بکاپ فوری از «<?= e(addslashes((string)$selectedBrand['name_fa'])) ?>» گرفته شود؟'))return false">
                <?= Auth::csrfField() ?><input type="hidden" name="action" value="create_backup"><input type="hidden" name="brand_id" value="<?= $brandId ?>">
                💾 بکاپ‌گیری فوری
            </button>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($selectedBrand): ?>
    <div style="display:flex;gap:16px;flex-wrap:wrap;padding:12px 20px;border-bottom:1px solid var(--border);font-size:13.5px">
        <span>📊 وضعیت: <b><?= en_to_fa_digits((string)$stats['count']) ?> از <?= en_to_fa_digits((string)$stats['max']) ?></b> بکاپ (حداکثر)</span>
        <span>💽 حجم کل: <b dir="ltr"><?= number_format($stats['total_size'] / 1048576, 1) ?> MB</b></span>
        <span style="color:var(--text-light)">💡 با ساخته شدن بکاپ ششم، قدیمی‌ترین بکاپ خودکار حذف می‌شود (سیاست نگهداری ۵ نسخه)</span>
    </div>
    <?php endif; ?>

    <div class="table-wrap">
        <?php if (!$selectedBrand): ?>
            <div class="empty-state"><div class="icon">💾</div><p>هنوز سایتی مستقر نشده است.</p><a href="deploy.php" class="btn btn-primary">🚀 استقرار خودکار</a></div>
        <?php elseif (empty($backups)): ?>
            <div class="empty-state" style="padding:24px"><div class="icon">📦</div><p>هنوز بکاپی برای «<?= e((string)$selectedBrand['name_fa']) ?>» وجود ندارد.<br><small>قبل از هر بروزرسانی به صورت خودکار بکاپ گرفته می‌شود.</small></p></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>#</th><th>نام فایل</th><th>تاریخ بکاپ (شمسی)</th><th>حجم</th><th>نوع</th><th>عملیات</th></tr></thead>
            <tbody>
            <?php $i = 0; foreach ($backups as $b): $i++; ?>
            <tr>
                <td><?= en_to_fa_digits((string)$i) ?></td>
                <td dir="ltr" style="font-size:11.5px"><?= e((string)$b['filename']) ?></td>
                <td><?= e((string)($b['shamsi_date_display'] ?: jdate((string)$b['gregorian_date'], true))) ?></td>
                <td dir="ltr"><?= $b['file_size'] > 0 ? number_format($b['file_size'] / 1048576, 2) . ' MB' : '—' ?></td>
                <td><?= BackupManager::typeLabel((string)$b['backup_type']) ?></td>
                <td style="display:flex;gap:6px">
                    <a class="btn btn-outline btn-sm" href="backups.php?download=<?= (int)$b['id'] ?>" title="دانلود">⬇️</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('🔄 بازیابی از این بکاپ؟\n⚠️ وضعیت فعلی سایت جایگزین می‌شود (ابتدا بکاپ ایمنی گرفته می‌شود)')">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="action" value="restore_backup">
                        <input type="hidden" name="backup_id" value="<?= (int)$b['id'] ?>">
                        <button type="submit" class="btn btn-warning btn-sm" title="بازیابی">🔄</button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('🗑️ این بکاپ حذف شود؟')">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="action" value="delete_backup">
                        <input type="hidden" name="backup_id" value="<?= (int)$b['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm" title="حذف">🗑️</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<style>.btn-sm{padding:4px 10px;font-size:12px}</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
