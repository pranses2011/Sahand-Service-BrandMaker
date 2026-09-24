<?php
/**
 * 📋 لاگ عملیات استقرار — مشاهده تاریخچه کامل
 * ==========================================
 * طبق سند بخش ۲۱ (بخش ۹):
 *   - فیلتر بر اساس نوع عملیات و وضعیت
 *   - جزئیات مراحل هر عملیات (deployment_logs)
 *   - مدت زمان، پیام خطا، منبع اجرا (panel/api/cron)
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();
$logger = new DeploymentLogger();

/* 📥 جزئیات مراحل یک عملیات (AJAX) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get_details') {
    Auth::enforceCsrf();
    $deploymentId = (int)post('deployment_id');
    $status = $logger->getStatus($deploymentId);
    json_response($status);
}

$pageTitle = 'لاگ استقرار';
$activeMenu = 'deployment-logs';
require __DIR__ . '/includes/header.php';

/* 🔎 فیلترها */
$actionFilter = get_param('action');
$statusFilter = get_param('status');
$logs = $logger->getRecent(150, $actionFilter ?: null, $statusFilter ?: null);
$csrf = e($_SESSION['csrf_token'] ?? '');
?>

<div class="card">
    <div class="card-header">
        <h3>📋 لاگ عملیات استقرار (<?= en_to_fa_digits((string)count($logs)) ?> عملیات)</h3>
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap">
            <select name="action" class="form-control" style="min-width:150px">
                <option value="">همه عملیات‌ها</option>
                <?php foreach (['deploy' => '🚀 استقرار', 'update' => '🔄 بروزرسانی', 'delete' => '🗑️ حذف', 'backup' => '💾 بکاپ', 'rollback' => '↩️ بازیابی'] as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $actionFilter === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="form-control" style="min-width:140px">
                <option value="">همه وضعیت‌ها</option>
                <option value="success" <?= $statusFilter === 'success' ? 'selected' : '' ?>>✅ موفق</option>
                <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>❌ ناموفق</option>
                <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>🔄 در حال اجرا</option>
            </select>
            <button type="submit" class="btn btn-outline">جستجو</button>
        </form>
    </div>
    <div class="table-wrap">
        <?php if (empty($logs)): ?>
            <div class="empty-state"><div class="icon">📋</div><p>هیچ عملیاتی ثبت نشده است.</p><a href="deploy.php" class="btn btn-primary">🚀 شروع اولین استقرار</a></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>#</th><th>برند</th><th>عملیات</th><th>وضعیت</th><th>دامنه</th><th>منبع</th><th>شروع</th><th>مدت</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): [$label, $badge] = DeploymentLogger::statusBadge((string)$log['status']); ?>
            <tr>
                <td dir="ltr"><?= (int)$log['id'] ?></td>
                <td><?= e((string)($log['brand_name'] ?? '—')) ?></td>
                <td><?= DeploymentLogger::actionLabel((string)$log['action']) ?></td>
                <td><span class="badge <?= $badge ?>"><?= $label ?></span></td>
                <td dir="ltr" style="font-size:12px"><?= e((string)($log['full_domain'] ?? '—')) ?></td>
                <td style="font-size:12px"><?= ['panel' => '🖥️ پنل', 'api' => '🔌 API', 'cron' => '⏰ Cron'][($log['triggered_by'] ?? 'panel')] ?? '—' ?></td>
                <td style="font-size:12px"><?= jdate((string)$log['started_at'], true) ?></td>
                <td><?= $log['duration_seconds'] ? en_to_fa_digits((string)(int)$log['duration_seconds']) . ' ثانیه' : '—' ?></td>
                <td><button class="btn btn-outline btn-sm" onclick="showDetails(<?= (int)$log['id'] ?>)">👁️ مراحل</button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- 👁️ دیالوگ جزئیات مراحل -->
<div class="modal-overlay" id="details-dialog" style="display:none">
    <div class="modal-box" style="max-width:600px">
        <div class="modal-header">
            <h3>👁️ جزئیات عملیات</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('details-dialog').style.display='none'">✕</button>
        </div>
        <div class="modal-body" id="details-body">⏳ در حال بارگذاری...</div>
    </div>
</div>

<style>
.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;z-index:1000;padding:16px}
.modal-box{background:#fff;border-radius:14px;max-width:600px;width:100%;max-height:85vh;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,.25)}
.modal-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border)}
.modal-header h3{margin:0;font-size:16px}
.modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:var(--text-light)}
.modal-body{padding:16px 20px}
.step-row{display:flex;gap:8px;padding:7px 0;border-bottom:1px dashed var(--border);font-size:13px;line-height:1.7}
.step-row .st-time{color:var(--text-light);font-size:11.5px;min-width:110px}
.step-row.failed{color:#991b1b}
.step-row.skipped{color:var(--text-light)}
.btn-sm{padding:4px 10px;font-size:12px}
</style>

<script>
const CSRF = '<?= $csrf ?>';
async function showDetails(deploymentId) {
    document.getElementById('details-dialog').style.display = 'flex';
    document.getElementById('details-body').textContent = '⏳ در حال بارگذاری...';

    const form = new FormData();
    form.append('action', 'get_details');
    form.append('deployment_id', deploymentId);
    form.append('csrf_token', CSRF);
    const body = await (await fetch('deployment-logs.php', {method: 'POST', body: form})).json();

    if (!body.success) {
        document.getElementById('details-body').textContent = '❌ ' + body.error;
        return;
    }
    const d = body.deployment;
    const actionLabels = {deploy: 'استقرار', update: 'بروزرسانی', delete: 'حذف', ssl: 'SSL', backup: 'بکاپ', rollback: 'بازیابی'};
    let html = `<div style="margin-bottom:10px;font-size:13px">
        <b>${actionLabels[d.action] || d.action}</b> — ${d.domain || '—'} |
        وضعیت: <b>${d.status === 'success' ? '✅ موفق' : d.status === 'failed' ? '❌ ناموفق' : '🔄 در حال اجرا'}</b> |
        مدت: ${d.duration ? d.duration + ' ثانیه' : '—'}
    </div>`;
    if (d.error) html += `<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:10px;margin-bottom:10px;font-size:12.5px">❌ ${d.error}</div>`;
    html += body.steps.map(s => {
        const ico = s.status === 'success' ? '✅' : s.status === 'failed' ? '❌' : '⏭️';
        return `<div class="step-row ${s.status}"><span>${ico}</span><span class="st-time">${s.time || ''}</span><span>${s.message}</span></div>`;
    }).join('');
    document.getElementById('details-body').innerHTML = html || '<p>مرحله‌ای ثبت نشده است.</p>';
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
