<?php
/**
 * 📊 داشبورد سلامت سایت‌های برند
 * ==============================
 * طبق سند بخش ۲۱ (بخش ۹):
 *   - وضعیت آنلاین/آفلاین همه سایت‌های مستقر
 *   - کد HTTP + زمان پاسخ + SSL + اتصال API
 *   - روند آخرین بررسی‌های هر برند
 *   - دکمه بررسی فوری (بدون انتظار برای cron)
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();
$checker = new HealthChecker();

/* 🔄 بررسی فوری (AJAX) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_now') {
    Auth::enforceCsrf();
    $brandId = (int)post('brand_id');
    $brand = $db->fetch('SELECT id, name_fa, slug, full_domain FROM brands WHERE id = ? AND is_deployed = 1', [$brandId]);
    if (!$brand) {
        json_response(['success' => false, 'message' => 'برند مستقری یافت نشد.']);
    }
    $result = $checker->checkBrand($brand);
    $msgMap = ['online' => '🟢 آنلاین', 'offline' => '🔴 آفلاین', 'error' => '⚠️ خطا'];
    json_response([
        'success' => true,
        'message' => $msgMap[$result['status']] . ' — HTTP: ' . ($result['http_status'] ?: 'بدون پاسخ')
            . ' | پاسخ: ' . ($result['response_time'] ?? 0) . 'ms'
            . ' | SSL: ' . ($result['ssl_valid'] ? 'فعال' : 'نامشخص'),
    ]);
}

/* 🔍 بررسی همه (دستی) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_all_now') {
    Auth::enforceCsrf();
    $results = $checker->checkAll();
    Logger::activity((int)$_SESSION['user_id'], 'بررسی فوری سلامت', json_encode($results, JSON_UNESCAPED_UNICODE));
    flash('success', '✅ بررسی فوری انجام شد — ' . en_to_fa_digits((string)$results['online']) . ' آنلاین / '
        . en_to_fa_digits((string)$results['offline']) . ' آفلاین / ' . en_to_fa_digits((string)$results['error']) . ' خطا');
    redirect('health-dashboard.php');
}

$pageTitle = 'سلامت سایت‌ها';
$activeMenu = 'health-dashboard';
require __DIR__ . '/includes/header.php';

$brandId = (int)get_param('brand_id');
$sites = $checker->getDashboard();

/* 📈 آمار کلی */
$stats = ['total' => count($sites), 'online' => 0, 'offline' => 0, 'error' => 0, 'ssl' => 0];
foreach ($sites as $sRow) {
    if (($sRow['health_status'] ?? '') === 'online') { $stats['online']++; }
    elseif (($sRow['health_status'] ?? '') === 'offline') { $stats['offline']++; }
    else { $stats['error']++; }
    if (($sRow['ssl_status'] ?? '') === 'active') { $stats['ssl']++; }
}

/* 📉 روند برند انتخاب‌شده */
$trend = $brandId > 0 ? array_reverse($checker->getTrend($brandId, 24)) : [];
$trendBrand = null;
foreach ($sites as $s) { if ((int)$s['id'] === $brandId) { $trendBrand = $s; break; } }
$csrf = e($_SESSION['csrf_token'] ?? '');
?>

<!-- 📊 کارت‌های آماری -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px">
    <div class="card" style="text-align:center;padding:16px"><div style="font-size:26px"><?= en_to_fa_digits((string)$stats['total']) ?></div><div style="color:var(--text-light);font-size:13px">کل سایت‌های مستقر</div></div>
    <div class="card" style="text-align:center;padding:16px;border-top:3px solid #16a34a"><div style="font-size:26px;color:#16a34a"><?= en_to_fa_digits((string)$stats['online']) ?></div><div style="color:var(--text-light);font-size:13px">🟢 آنلاین</div></div>
    <div class="card" style="text-align:center;padding:16px;border-top:3px solid #dc2626"><div style="font-size:26px;color:#dc2626"><?= en_to_fa_digits((string)$stats['offline']) ?></div><div style="color:var(--text-light);font-size:13px">🔴 آفلاین</div></div>
    <div class="card" style="text-align:center;padding:16px;border-top:3px solid #f59e0b"><div style="font-size:26px;color:#f59e0b"><?= en_to_fa_digits((string)$stats['error']) ?></div><div style="color:var(--text-light);font-size:13px">⚠️ خطا</div></div>
    <div class="card" style="text-align:center;padding:16px;border-top:3px solid #2563eb"><div style="font-size:26px;color:#2563eb"><?= en_to_fa_digits((string)$stats['ssl']) ?></div><div style="color:var(--text-light);font-size:13px">🔒 SSL فعال</div></div>
</div>

<form method="post">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="check_all_now">
    <div class="card" style="margin-bottom:16px">
        <div class="card-header">
            <h3>📊 وضعیت سلامت سایت‌ها</h3>
            <button type="submit" class="btn btn-primary" onclick="this.textContent='⏳ در حال بررسی... حدود ۱۵ ثانیه'">🔄 بررسی فوری همه</button>
        </div>
        <div class="table-wrap">
            <?php if (empty($sites)): ?>
                <div class="empty-state"><div class="icon">📊</div><p>هنوز سایتی مستقر نشده است.<br><small>از بخش «استقرار خودکار» اولین سایت را مستقر کنید.</small></p><a href="deploy.php" class="btn btn-primary">🚀 استقرار خودکار</a></div>
            <?php else: ?>
            <table class="table">
                <thead><tr><th>برند</th><th>دامنه</th><th>وضعیت</th><th>HTTP</th><th>زمان پاسخ</th><th>SSL</th><th>آخرین بررسی</th><th>عملیات</th></tr></thead>
                <tbody>
                <?php foreach ($sites as $s):
                    $h = (string)($s['health_status'] ?? '');
                    $badge = $h === 'online' ? ['🟢 آنلاین', 'badge-success'] : ($h === 'offline' ? ['🔴 آفلاین', 'badge-danger'] : ['⚪ نامشخص', 'badge-secondary']);
                ?>
                <tr>
                    <td><b><?= e((string)$s['name_fa']) ?></b></td>
                    <td dir="ltr" style="font-size:12.5px"><a href="https://<?= e((string)$s['full_domain']) ?>" target="_blank" rel="noopener"><?= e((string)$s['full_domain']) ?></a></td>
                    <td><span class="badge <?= $badge[1] ?>"><?= $badge[0] ?></span></td>
                    <td dir="ltr"><?= $s['http_status'] ? (string)(int)$s['http_status'] : '—' ?></td>
                    <td dir="ltr"><?= $s['response_time_ms'] ? en_to_fa_digits((string)(int)$s['response_time_ms']) . ' ms' : '—' ?></td>
                    <td><?= ($s['ssl_status'] ?? '') === 'active' ? '🔒 فعال' : ($s['ssl_expiry'] ? '⏳ ' . jdate((string)$s['ssl_expiry']) : '—') ?></td>
                    <td style="font-size:12px"><?= $s['last_health_check'] ? jdate((string)$s['last_health_check'], true) : '—' ?></td>
                    <td style="display:flex;gap:6px">
                        <button type="button" class="btn btn-outline btn-sm" onclick="checkNow(<?= (int)$s['id'] ?>, this)">🔍 فوری</button>
                        <a class="btn btn-outline btn-sm" href="health-dashboard.php?brand_id=<?= (int)$s['id'] ?>">📈 روند</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if ($trendBrand): ?>
<!-- 📈 روند بررسی‌های برند -->
<div class="card">
    <div class="card-header"><h3>📈 روند بررسی‌ها — <?= e((string)$trendBrand['name_fa']) ?></h3></div>
    <div class="table-wrap">
        <?php if (empty($trend)): ?>
            <div class="empty-state" style="padding:16px"><p>هنوز بررسی‌ای ثبت نشده — دکمه «🔍 فوری» یا cron را اجرا کنید.</p></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>زمان</th><th>وضعیت</th><th>HTTP</th><th>زمان پاسخ</th><th>SSL</th><th>API</th><th>خطا</th></tr></thead>
            <tbody>
            <?php foreach ($trend as $t): ?>
            <tr>
                <td style="font-size:12px"><?= jdate((string)$t['checked_at'], true) ?></td>
                <td><?= $t['status'] === 'online' ? '🟢' : ($t['status'] === 'offline' ? '🔴' : '⚠️') ?> <?= e($t['status']) ?></td>
                <td dir="ltr"><?= $t['http_status'] ? (string)(int)$t['http_status'] : '—' ?></td>
                <td dir="ltr"><?= $t['response_time_ms'] ? en_to_fa_digits((string)(int)$t['response_time_ms']) . ' ms' : '—' ?></td>
                <td><?= $t['ssl_valid'] !== null ? ($t['ssl_valid'] ? '🔒' : '❌') : '—' ?></td>
                <td><?= $t['api_ok'] !== null ? ($t['api_ok'] ? '✅' : '❌') : '—' ?></td>
                <td style="font-size:11.5px;color:var(--text-light)"><?= e((string)($t['error_message'] ?? '')) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
const CSRF = '<?= $csrf ?>';
/* 🔍 بررسی فوری یک برند */
async function checkNow(brandId, btn) {
    btn.disabled = true; btn.textContent = '⏳...';
    const form = new FormData();
    form.append('action', 'check_now');
    form.append('brand_id', brandId);
    form.append('csrf_token', CSRF);
    const body = await (await fetch('health-dashboard.php', {method: 'POST', body: form})).json();
    alert(body.success ? body.message : '❌ ' + body.message);
    location.reload();
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
