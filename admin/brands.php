<?php
/**
 * 🏷️ مدیریت برندها — لیست کامل با جستجو و فیلتر
 * ==============================================
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();

/* 🗑️ حذف برند — قبل از هرگونه خروجی پردازش می‌شود */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    Auth::enforceCsrf();
    $brandId = (int)post('brand_id');
    $brand = $db->fetch('SELECT name_fa FROM brands WHERE id = ?', [$brandId]);
    if ($brand) {
        $db->delete('brands', 'id = ?', [$brandId]); // CASCADE همه جداول وابسته
        Logger::activity((int)$_SESSION['user_id'], 'حذف برند', $brand['name_fa']);
        flash('success', '✅ برند «' . $brand['name_fa'] . '» و تمام داده‌های وابسته حذف شد.');
    }
    redirect('brands.php');
}

/* 🔀 تغییر وضعیت فعال */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    Auth::enforceCsrf();
    $brandId = (int)post('brand_id');
    $current = $db->fetchValue('SELECT is_active FROM brands WHERE id = ?', [$brandId]);
    $db->update('brands', ['is_active' => $current ? 0 : 1], 'id = ?', [$brandId]);
    flash('success', '✅ وضعیت برند تغییر کرد.');
    redirect('brands.php');
}

$pageTitle = 'مدیریت برندها';
$activeMenu = 'brands';
require __DIR__ . '/includes/header.php';

/* 🔎 جستجو و فیلتر */
$search = get_param('q');
$statusFilter = get_param('status');
$where = '1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (b.name_fa LIKE ? OR b.name_en LIKE ? OR b.domain LIKE ?)';
    $like = "%{$search}%";
    array_push($params, $like, $like, $like);
}
if ($statusFilter !== '') {
    $where .= ' AND b.status = ?';
    $params[] = $statusFilter;
}

$brands = $db->fetchAll(
    "SELECT b.*,
        (SELECT COUNT(*) FROM brand_articles a WHERE a.brand_id = b.id) as articles_count,
        (SELECT COUNT(*) FROM brand_devices d WHERE d.brand_id = b.id) as devices_count,
        (SELECT COUNT(*) FROM service_requests r WHERE r.brand_id = b.id) as requests_count
     FROM brands b WHERE {$where}
     ORDER BY b.id DESC",
    $params
);

$statusMap = [
    'draft'     => ['پیش‌نویس', 'badge-secondary'],
    'building'  => ['در حال ساخت', 'badge-warning'],
    'published' => ['منتشرشده', 'badge-success'],
    'suspended' => ['معلق', 'badge-danger'],
];
?>
<div class="card">
    <div class="card-header">
        <h3>🏷️ لیست برندها (<?= en_to_fa_digits((string)count($brands)) ?>)</h3>
        <div class="tools">
            <form method="get" style="display:flex;gap:8px;flex-wrap:wrap">
                <input type="text" name="q" class="form-control" placeholder="🔎 جستجو برند..." value="<?= e($search) ?>" style="max-width:220px;min-width:150px">
                <select name="status" class="form-control" style="max-width:160px;min-width:130px">
                    <option value="">همه وضعیت‌ها</option>
                    <?php foreach ($statusMap as $key => [$label]): ?>
                        <option value="<?= $key ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline">جستجو</button>
            </form>
            <a href="brand-new.php" class="btn btn-primary">➕ برند جدید</a>
        </div>
    </div>
    <div class="table-wrap">
        <?php if (empty($brands)): ?>
            <div class="empty-state">
                <div class="icon">🏷️</div>
                <p>هنوز برندی ثبت نشده است.<br><small>با افزودن اولین برند، سایت آن به صورت خودکار تولید می‌شود.</small></p>
                <a href="brand-new.php" class="btn btn-primary">➕ ساخت اولین سایت برند</a>
            </div>
        <?php else: ?>
        <table class="table">
            <thead>
            <tr>
                <th>برند</th><th>وضعیت</th><th>دستگاه‌ها</th><th>مقالات</th><th>درخواست‌ها</th><th>تاریخ ایجاد</th><th>عملیات</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($brands as $brand): ?>
                <tr>
                    <td>
                        <div class="brand-cell">
                            <?php if ($brand['logo']): ?>
                                <img src="<?= asset_url($brand['logo']) ?>" alt="<?= e($brand['name_fa']) ?>">
                            <?php else: ?>
                                <div style="width:38px;height:38px;border-radius:10px;background:var(--primary-light);display:flex;align-items:center;justify-content:center">🏷️</div>
                            <?php endif; ?>
                            <div>
                                <div class="name"><?= e($brand['name_fa']) ?> <small style="color:var(--text-light)">(<?= e($brand['name_en']) ?>)</small></div>
                                <div class="domain"><?= e($brand['domain'] ?: '—') ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge <?= $statusMap[$brand['status']][1] ?? 'badge-secondary' ?>"><?= $statusMap[$brand['status']][0] ?? $brand['status'] ?></span>
                        <?= $brand['is_active'] ? '' : '<span class="badge badge-danger">غیرفعال</span>' ?>
                    </td>
                    <td><?= en_to_fa_digits((string)$brand['devices_count']) ?></td>
                    <td><?= en_to_fa_digits((string)$brand['articles_count']) ?></td>
                    <td><?= en_to_fa_digits((string)$brand['requests_count']) ?></td>
                    <td style="font-size:11.5px;color:var(--text-light)"><?= jdate($brand['created_at']) ?></td>
                    <td>
                        <div class="actions">
                            <?php if ($brand['status'] === 'draft'): ?>
                                <a href="brand-build.php?id=<?= (int)$brand['id'] ?>" class="btn btn-success btn-sm" title="تولید محتوا و ساخت">🏗️ ساخت</a>
                            <?php endif; ?>
                            <a href="brand-edit.php?id=<?= (int)$brand['id'] ?>" class="btn btn-outline btn-sm" title="ویرایش">✏️</a>
                            <?php if ($brand['status'] !== 'draft'): ?>
                                <a href="export.php?brand=<?= (int)$brand['id'] ?>" class="btn btn-outline btn-sm" title="دانلود ZIP">📦</a>
                            <?php endif; ?>
                            <form method="post" style="display:inline" data-confirm="وضعیت فعال بودن این برند تغییر کند؟">
                                <?= Auth::csrfField() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="brand_id" value="<?= (int)$brand['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" title="فعال/غیرفعال"><?= $brand['is_active'] ? '⏸️' : '▶️' ?></button>
                            </form>
                            <form method="post" style="display:inline" data-confirm="⚠️ حذف برند، تمام صفحات، مقالات و داده‌های آن را پاک می‌کند. مطمئن هستید؟">
                                <?= Auth::csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="brand_id" value="<?= (int)$brand['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" title="حذف">🗑️</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
