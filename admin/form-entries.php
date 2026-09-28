<?php
/**
 * 📋 فرم‌های دیگر سایت برند (v2.31)
 * ================================
 * درخواست کاربر: «در کنار صفحه درخواست‌ها صفحه‌ای هم برای فرم‌های دیگر
 * باشه که اطلاعات اونارو نمایش بده» — همه فرم‌های قالب‌ساز (تماس/
 * خبرنامه/نظرسنجی/رزرو نوبت/درخواست تماس/...) اینجا لیست می‌شوند.
 *
 * @package SahandBrandMaker
 */
define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

/* 🛂 v2.34 — ACL سطح‌برند: گارد دسترسی (GET brand / POST brand_id)
   brand_manager فقط به برندهای تخصیص‌یافته در users.php دسترسی دارد */
$_aclBrand = (int)($_GET['brand'] ?? 0);
if ($_aclBrand < 1) { $_aclBrand = (int)($_POST['brand_id'] ?? ($_POST['brand'] ?? 0)); }
if ($_aclBrand > 0) {
    (new Auth())->requireBrandAccess($_aclBrand);
}


$db = Database::getInstance();

/* 🔀 حذف */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    Auth::enforceCsrf();
    $db->delete('form_entries', 'id = ?', [(int)post('entry_id')]);
    Logger::activity((int)$_SESSION['user_id'], 'حذف فرم', "فرم #" . post('entry_id'));
    flash('success', '✅ فرم حذف شد.');
    redirect('form-entries.php');
}

$brandFilter = (int)get_param('brand');
$formFilter = get_param('form');
$view = (int)get_param('view');

/* 📄 نمای جزئیات یک فرم */
$entry = null;
if ($view > 0) {
    $entry = $db->fetch(
        'SELECT fe.*, b.name_fa AS brand_name FROM form_entries fe LEFT JOIN brands b ON b.id = fe.brand_id WHERE fe.id = ? LIMIT 1',
        [$view]
    );
}

/* 📋 فیلترها و لیست */
$where = '1=1';
$params = [];
if ($brandFilter > 0) { $where .= ' AND fe.brand_id = ?'; $params[] = $brandFilter; }
if ($formFilter !== '') { $where .= ' AND fe.form_block = ?'; $params[] = $formFilter; }

$total = 0;
$todayCount = 0;
$formTypes = [];
$entries = [];
try {
    $total = (int)$db->fetchValue('SELECT COUNT(*) FROM form_entries');
    $todayCount = (int)$db->fetchValue('SELECT COUNT(*) FROM form_entries WHERE created_at >= ?', [date('Y-m-d 00:00:00')]);
    $formTypes = $db->fetchAll('SELECT form_block, COUNT(*) AS c FROM form_entries GROUP BY form_block ORDER BY c DESC');
    $entries = $db->fetchAll(
        "SELECT fe.*, b.name_fa AS brand_name FROM form_entries fe LEFT JOIN brands b ON b.id = fe.brand_id
         WHERE {$where} ORDER BY fe.id DESC LIMIT 100",
        $params
    );
} catch (Throwable $e) {
    /* جدول هنوز ساخته نشده — مهاجرت v2.31 با اولین لود می‌سازدش */
}

$formLabels = [
    'contact-form' => ['📞 فرم تماس', 'bg-cyan'],
    'newsletter-form' => ['📧 خبرنامه', 'bg-blue'],
    'callback-form' => ['☎️ درخواست تماس', 'bg-green'],
    'quick-contact-form' => ['⚡ تماس سریع', 'bg-orange'],
    'appointment-form' => ['📅 رزرو نوبت', 'bg-purple'],
    'appointment-compact' => ['🗓 رزرو سریع', 'bg-purple'],
    'survey-form' => ['📊 نظرسنجی', 'bg-cyan'],
    'request-form' => ['🚀 درخواست خدمات', 'bg-blue'],
    'hero-form' => ['🦸 فرم سریع هیرو', 'bg-orange'],
    'custom' => ['📝 فرم دلخواه', 'bg-blue'],
];
$fieldLabels = [
    'full_name' => 'نام و نام خانوادگی', 'phone' => 'شماره تماس', 'phone2' => 'تماس دوم', 'email' => 'ایمیل',
    'subject' => 'موضوع', 'address' => 'آدرس', 'description' => 'پیام / شرح', 'device_type' => 'نوع دستگاه', 'device_name' => 'نوع دستگاه',
    'device_other' => 'دستگاه (دستی)', 'device_model' => 'مدل دستگاه', 'preferred_date' => 'تاریخ ترجیحی',
    'preferred_time' => 'بازه ساعتی', 'images' => 'تصاویر پیوست',
];

$brands = $db->fetchAll('SELECT id, name_fa FROM brands ORDER BY name_fa');
/* 🛂 v2.34 — ACL: brand_manager فقط برندهای تخصیص‌یافته را می‌بیند */
$_aclIds = (new Auth())->accessibleBrandIds();
if ($_aclIds !== null) {
    $brands = array_values(array_filter($brands, function ($_b) use ($_aclIds) {
        return in_array((int)$_b['id'], $_aclIds, true);
    }));
}
$pageTitle = 'فرم‌های دیگر';
$activeMenu = 'form-entries';
require __DIR__ . '/includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card"><div class="icon bg-cyan">📋</div><div><div class="number"><?= en_to_fa_digits((string)$total) ?></div><div class="label">کل فرم‌های ثبت‌شده</div></div></div>
    <div class="stat-card"><div class="icon bg-green">📅</div><div><div class="number"><?= en_to_fa_digits((string)$todayCount) ?></div><div class="label">فرم‌های امروز</div></div></div>
    <div class="stat-card"><div class="icon bg-blue">🗂</div><div><div class="number"><?= en_to_fa_digits((string)count($formTypes)) ?></div><div class="label">انواع فرم فعال</div></div></div>
    <div class="stat-card"><div class="icon bg-orange">📨</div><div><div class="number"><a href="requests.php" style="color:inherit;text-decoration:none">درخواست‌ها ←</a></div><div class="label">صفحه درخواست‌های خدمات</div></div></div>
</div>

<form method="get" class="card" style="padding:13px 18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
    <select name="brand" class="form-control" style="max-width:200px">
        <option value="">🏷️ همه برندها</option>
        <?php foreach ($brands as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= $brandFilter === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name_fa']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="form" class="form-control" style="max-width:200px">
        <option value="">📋 همه انواع فرم</option>
        <?php foreach ($formTypes as $ft): ?>
            <option value="<?= e($ft['form_block']) ?>" <?= $formFilter === $ft['form_block'] ? 'selected' : '' ?>><?= e($formLabels[$ft['form_block']][0] ?? $ft['form_block']) ?> (<?= en_to_fa_digits((string)$ft['c']) ?>)</option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">🔍 اعمال فیلتر</button>
    <a href="form-entries.php" class="btn btn-outline">↺ همه</a>
</form>

<?php if ($entry): ?>
    <div class="card" style="margin-bottom:16px" id="entry-details">
        <div class="card-header">
            <h3>📄 جزئیات فرم #<?= en_to_fa_digits((string)$entry['id']) ?></h3>
            <a href="form-entries.php?<?= $brandFilter ? 'brand=' . $brandFilter . '&' : '' ?><?= $formFilter !== '' ? 'form=' . e($formFilter) : '' ?>" class="btn btn-outline btn-sm">✕ بستن</a>
        </div>
        <div class="card-body">
            <?php
            $flds = json_decode((string)$entry['fields'], true) ?: [];
            $imgs = (array)($flds['images'] ?? []);
            unset($flds['images']);
            /* 🚨 v2.32 — کلید خام دستگاه وقتی نام فارسی هست نشان داده نمی‌شود */
            if (!empty($flds['device_name'])) { unset($flds['device_type'], $flds['device_other']); }
            ?>
            <table class="table" style="font-size:13px">
                <tbody>
                <tr><td style="width:170px;background:#f8fafc;font-weight:700">🏷️ برند</td><td><?= e($entry['brand_name'] ?? '—') ?></td></tr>
                <tr><td style="background:#f8fafc;font-weight:700">📋 نوع فرم</td><td><?= e($formLabels[$entry['form_block']][0] ?? $entry['form_block']) ?></td></tr>
                <?php foreach ($flds as $k => $v): ?>
                    <tr>
                        <td style="background:#f8fafc;font-weight:700"><?= e($fieldLabels[$k] ?? $k) ?></td>
                        <td><?= nl2br(e((string)$v)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr><td style="background:#f8fafc;font-weight:700">📄 صفحه ارسال</td><td dir="ltr" style="text-align:right"><?= e($entry['page_url'] ?: '—') ?></td></tr>
                <tr><td style="background:#f8fafc;font-weight:700">⏰ تاریخ ثبت</td><td><?= e(jdate((string)$entry['created_at'], true)) ?></td></tr>
                </tbody>
            </table>
            <?php if ($imgs): ?>
                <div style="margin-top:12px;font-weight:800;font-size:12.5px;margin-bottom:8px">🖼️ تصاویر پیوست (<?= en_to_fa_digits((string)count($imgs)) ?>)</div>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <?php foreach ($imgs as $im): ?>
                        <a href="<?= e(asset_url((string)$im)) ?>" target="_blank" style="display:block">
                            <img src="<?= e(asset_url((string)$im)) ?>" alt="پیوست" style="width:110px;height:110px;object-fit:cover;border-radius:11px;border:1px solid #e2e8f0">
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form method="post" style="margin-top:16px" onsubmit="return sahandSubmitConfirm(this)">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">🗑 حذف این فرم</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h3>📋 فهرست فرم‌ها (آخرین ۱۰۰ مورد)</h3></div>
    <?php if (empty($entries)): ?>
        <div class="empty-state"><div class="icon">📋</div><p>هنوز فرمی ثبت نشده است.<br><small>فرم‌های قالب‌ساز (تماس، خبرنامه، نظرسنجی و ...) که کاربران در سایت برند پر کنند، اینجا نمایش داده می‌شوند.</small></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>#</th><th>نوع فرم</th><th>برند</th><th>خلاصه</th><th>تاریخ</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($entries as $en): ?>
                    <?php
                    $f = json_decode((string)$en['fields'], true) ?: [];
                    $summary = trim((string)($f['full_name'] ?? ''));
                    if (($f['phone'] ?? '') !== '') { $summary .= ($summary ? ' — ' : '') . $f['phone']; }
                    if ($summary === '' && ($f['email'] ?? '') !== '') { $summary = $f['email']; }
                    if ($summary === '') { $summary = mb_substr(trim((string)($f['description'] ?? '')), 0, 50); }
                    ?>
                    <tr>
                        <td class="n"><?= en_to_fa_digits((string)$en['id']) ?></td>
                        <td><span class="badge <?= $formLabels[$en['form_block']][1] ?? 'bg-blue' ?>"><?= e($formLabels[$en['form_block']][0] ?? $en['form_block']) ?></span></td>
                        <td><?= e($en['brand_name'] ?? '—') ?></td>
                        <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($summary ?: '—') ?></td>
                        <td style="font-size:11.5px"><?= e(jdate((string)$en['created_at'], true)) ?></td>
                        <td><a href="form-entries.php?view=<?= (int)$en['id'] ?>" class="btn btn-outline btn-sm">👁 مشاهده</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<div class="hint" style="margin-top:12px">💡 فرم «درخواست خدمات» (صفحه /request سایت برند) در <a href="requests.php">صفحه درخواست‌ها</a> نمایش داده می‌شود — این صفحه مخصوص سایر فرم‌های قالب‌ساز است.</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
