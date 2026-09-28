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
/* 🆕 v2.33 — سطح «اطلاعاتی» هم در نقشه هست (قبلاً به «متوسط» می‌افتاد) */
$severityMap = [
    'low'           => ['کم', 'sev-low'],
    'medium'        => ['متوسط', 'sev-med'],
    'high'          => ['زیاد', 'sev-high'],
    'critical'      => ['بحرانی', 'sev-crit'],
    'informational' => ['اطلاعاتی', 'sev-info'],
];
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
                            <?php
                            /* 🆕 v2.33 — نمایش شش فیلد تکمیلی (فقط اگر مقدار دارند؛
                               رکوردهای قدیمی بدون این فیلدها باید همان رفتار قبلی
                               را داشته باشند و بلوک خالی نمایش ندهند) */
                            $ecModels   = is_array($code['models'] ?? null) ? array_values(array_filter((array)$code['models'])) : [];
                            $ecSubtype  = trim((string)($code['subtype'] ?? ''));
                            $ecCategory = trim((string)($code['category'] ?? ''));
                            $ecPart     = trim((string)($code['related_part'] ?? ''));
                            $ecSpecs    = trim((string)($code['tech_specs'] ?? ''));
                            $ecLoc      = trim((string)($code['part_location'] ?? ''));
                            $ecHasMeta  = ($ecSubtype !== '' && $ecSubtype !== 'همه زیرنوع‌ها') || $ecModels || $ecCategory !== '';
                            $ecHasPart  = ($ecPart !== '' || $ecSpecs !== '' || $ecLoc !== '');
                            ?>
                            <?php if ($ecHasMeta || $ecHasPart): ?>
                                <div class="ecode-meta">
                                    <?php if ($ecHasMeta): ?>
                                        <div class="ecode-meta-row">
                                            <?php if ($ecSubtype !== '' && $ecSubtype !== 'همه زیرنوع‌ها'): ?>
                                                <span class="ecode-chip"><b>زیرنوع:</b> <?= e($ecSubtype) ?></span>
                                            <?php endif; ?>
                                            <?php if ($ecCategory !== ''): ?>
                                                <span class="ecode-chip"><b>نوع خطا:</b> <?= e($ecCategory) ?></span>
                                            <?php endif; ?>
                                            <?php if ($ecModels): ?>
                                                <?php
                                                /* fa_num فقط در config.php نسخه‌های جدید است؛ برای سایت‌های
                                                   از قبل مستقرشده که config قدیمی دارند محافظت می‌شود */
                                                $moreN   = count($ecModels) - 6;
                                                $moreTxt = $moreN > 0
                                                    ? ' و ' . (function_exists('fa_num') ? fa_num((string)$moreN) : (string)$moreN) . ' مدل دیگر'
                                                    : '';
                                                ?>
                                                <span class="ecode-chip"><b>مدل‌ها:</b>
                                                    <?= e(implode('، ', array_slice($ecModels, 0, 6))) . e($moreTxt) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($ecHasPart): ?>
                                        <div class="ecode-part">
                                            <?php if ($ecPart !== ''): ?>
                                                <div><b>🔧 قطعه مربوطه:</b> <?= e($ecPart) ?></div>
                                            <?php endif; ?>
                                            <?php if ($ecSpecs !== ''): ?>
                                                <div><b>📐 مشخصات فنی:</b> <?= e($ecSpecs) ?></div>
                                            <?php endif; ?>
                                            <?php if ($ecLoc !== ''): ?>
                                                <div><b>📍 محل قطعه:</b> <?= e($ecLoc) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
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
