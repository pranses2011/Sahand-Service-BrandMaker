<?php
/**
 * 🚨 صفحه کدهای خطا — جستجو + فیلتر دستگاه
 * @package SahandBrandSite
 */
define('BRAND_INIT', true);
require_once __DIR__ . '/../config.php';
$devices = fetchFromAPI('brand/' . BRAND_ID . '/devices')['data'] ?? [];
$q = (string)($_GET['q'] ?? '');
$device = (string)($_GET['device'] ?? '');
$codes = [];
if ($q !== '' || $device !== '') {
    $codes = fetchFromAPI('brand/' . BRAND_ID . '/error-codes?q=' . urlencode($q) . '&device=' . urlencode($device), 60)['data'] ?? [];
}
$pageTitle = 'کدهای خطای دستگاه‌ها | ' . BRAND_NAME_FA;
$pageDesc = 'راهنمای جامع کدهای خطای دستگاه‌های ' . BRAND_NAME_FA . ' — علت و راه‌حل هر خطا';
$crumbTitle = 'کدهای خطا';
require __DIR__ . '/_page_base.php';
$severityMap = ['low' => ['کم', 'sev-low'], 'medium' => ['متوسط', 'sev-med'], 'high' => ['زیاد', 'sev-high'], 'critical' => ['بحرانی', 'sev-crit']];
?>
<section class="section">
    <div class="container error-codes-page">
        <h1 class="page-title">🚨 کدهای خطای دستگاه‌ها</h1>
        <p class="page-intro">کد خطای دستگاه <?= e(BRAND_NAME_FA) ?> را جستجو کنید تا علت و راه‌حل آن را ببینید.</p>
        <!-- 🔍 جستجو -->
        <form method="get" class="ecode-search">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="🔎 جستجوی کد خطا یا عنوان (مثلاً E1)" autofocus>
            <select name="device">
                <option value="">همه دستگاه‌ها</option>
                <?php foreach ($devices as $d): ?>
                    <option value="<?= e($d['device_key']) ?>" <?= $device === $d['device_key'] ? 'selected' : '' ?>><?= e($d['name_fa']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">جستجو</button>
        </form>
        <?php if ($q !== '' || $device !== ''): ?>
            <?php if (empty($codes)): ?>
                <div class="empty-state"><div style="font-size:48px">🔍</div><p>کد خطایی یافت نشد.<br><small>در صورت عدم یافتن کد، با ما تماس بگیرید.</small></p></div>
            <?php else: ?>
                <div class="ecode-list">
                    <?php foreach ($codes as $code): $sev = $severityMap[$code['severity']] ?? $severityMap['medium']; ?>
                        <div class="ecode-card">
                            <div class="ecode-head">
                                <code class="ecode-code"><?= e($code['code']) ?></code>
                                <span class="ecode-device"><?= e($code['device_key']) ?></span>
                                <span class="ecode-severity <?= $sev[1] ?>"><?= $sev[0] ?></span>
                            </div>
                            <h2><?= e($code['title']) ?></h2>
                            <?php if (!empty($code['description'])): ?><p class="ecode-desc"><?= e($code['description']) ?></p><?php endif; ?>
                            <?php if (!empty($code['causes'])): ?>
                                <div class="ecode-sec"><h3>⚠️ دلایل احتمالی</h3><ul><?php foreach ($code['causes'] as $cause): ?><li><?= e($cause) ?></li><?php endforeach; ?></ul></div>
                            <?php endif; ?>
                            <?php if (!empty($code['solutions'])): ?>
                                <div class="ecode-sec"><h3>✅ راه‌حل‌ها</h3><ul><?php foreach ($code['solutions'] as $sol): ?><li><?= e($sol) ?></li><?php endforeach; ?></ul></div>
                            <?php endif; ?>
                            <div class="ecode-foot">
                                <?php if ($code['needs_technician']): ?>
                                    <span class="badge-tech">🔧 نیازمند تکنسین</span>
                                    <a href="/request" class="btn btn-primary btn-sm">ثبت درخواست تعمیر</a>
                                <?php else: ?>
                                    <span class="badge-diy">✅ قابل رفع توسط کاربر</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="hint" style="text-align:center">برای نمایش نتایج، جستجو کنید یا دستگاه را انتخاب نمایید.</p>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/../includes/floating-btn.php'; require __DIR__ . '/../includes/footer.php'; ?>
