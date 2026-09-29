<?php
/**
 * 🏷️ مدیریت برندها — لیست کامل با جستجو و فیلتر
 * ==============================================
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

/* 🗃️ P2-25 — لایه Repository: کوئری‌های برند از این پس از BrandRepository */
$brandRepo = new BrandRepository();

/* 🗑️ حذف برند — قبل از هرگونه خروجی پردازش می‌شود */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    /* 🛂 v2.34 — ACL: حذف برند فقط برای نقش سیستمی */
    (new Auth())->requireSystemRole();
    Auth::enforceCsrf();
    $brandId = (int)post('brand_id');
    $brand = $brandRepo->find($brandId);
    if ($brand) {
        $brandRepo->delete($brandId); // CASCADE همه جداول وابسته
        Logger::activity((int)$_SESSION['user_id'], 'حذف برند', $brand['name_fa']);
        flash('success', '✅ برند «' . $brand['name_fa'] . '» و تمام داده‌های وابسته حذف شد.');
    }
    redirect('brands.php');
}

/* 🔀 تغییر وضعیت فعال */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    Auth::enforceCsrf();
    $brandId = (int)post('brand_id');
    $brand = $brandRepo->find($brandId);
    if ($brand) {
        $brandRepo->update($brandId, ['is_active' => $brand['is_active'] ? 0 : 1]);
    }
    flash('success', '✅ وضعیت برند تغییر کرد.');
    redirect('brands.php');
}

$pageTitle = 'مدیریت برندها';
$activeMenu = 'brands';
require __DIR__ . '/includes/header.php';

/* 🔎 جستجو و فیلتر — از طریق Repository (فیلتر ACL یکجا) */
$brands = $brandRepo->searchWithCounts(get_param('q'), get_param('status'), (new Auth())->accessibleBrandIds());

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
                <th>برند</th><th>وضعیت</th><th>استقرار</th><th>دستگاه‌ها</th><th>مقالات</th><th>درخواست‌ها</th><th>تاریخ ایجاد</th><th>عملیات</th>
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
                    <td>
                        <?php if (!empty($brand['is_deployed'])): ?>
                            <span class="badge <?= ($brand['health_status'] ?? '') === 'online' ? 'badge-success' : (($brand['health_status'] ?? '') === 'offline' ? 'badge-danger' : 'badge-success') ?>">🟢 <?= $brand['full_domain'] ? 'مستقر' : 'مستقر' ?></span>
                            <?= ($brand['ssl_status'] ?? 'none') === 'active' ? '<span class="badge badge-success" title="SSL فعال">🔒</span>' : '' ?>
                            <?php /* 🔖 v2.43 — هشدار استقرار کهنه: نسخه سایتِ برند با نسخه قالب فعلی نمی‌خواند
                                   (ریشه «رفع‌ها اعمال نمیشود» = فراموشی بروزرسانی استقرار) */ ?>
                            <?php $tplVer = ''; $tplCfg = ROOT_PATH . '/templates/brand-core/config.php';
                            if (is_file($tplCfg) && preg_match("#define\\('VERSION',\\s*'([^']+)'#", (string)file_get_contents($tplCfg), $vm)) { $tplVer = $vm[1]; } ?>
                            <?php if ($tplVer !== '' && trim((string)($brand['deployed_version'] ?? '')) !== '' && $brand['deployed_version'] !== $tplVer): ?>
                                <a href="deploy.php?brand_id=<?= (int)$brand['id'] ?>" title="نسخه سایت برند (<?= e($brand['deployed_version']) ?>) با نسخه قالب (<?= e($tplVer) ?>) نمی‌خواند — بروزرسانی استقرار لازم است">
                                    <span class="badge badge-warning">⚠️ استقرار کهنه</span>
                                </a>
                            <?php endif; ?>
                        <?php elseif ($brand['status'] !== 'draft'): ?>
                            <a href="deploy.php?brand_id=<?= (int)$brand['id'] ?>" class="btn btn-outline btn-sm" title="استقرار خودکار">🚀 استقرار</a>
                        <?php else: ?>
                            <span class="badge badge-secondary">⚪ ندارد</span>
                        <?php endif; ?>
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
                            <?php if (!empty($brand['is_deployed']) && $brand['status'] !== 'draft'): ?>
                                <a href="deploy.php?brand_id=<?= (int)$brand['id'] ?>" class="btn btn-outline btn-sm" title="بروزرسانی خودکار">🔄</a>
                                <a href="health-dashboard.php?brand_id=<?= (int)$brand['id'] ?>" class="btn btn-outline btn-sm" title="وضعیت سلامت">📊</a>
                            <?php endif; ?>
                            <form method="post" style="display:inline" data-confirm="وضعیت فعال بودن این برند تغییر کند؟">
                                <?= Auth::csrfField() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="brand_id" value="<?= (int)$brand['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" title="فعال/غیرفعال"><?= $brand['is_active'] ? '⏸️' : '▶️' ?></button>
                            </form>
                            <?php if (!(new Auth())->isBrandManager()): /* 🛂 v2.34 — حذف برند: فقط نقش سیستمی */ ?>
                            <form method="post" style="display:inline" data-confirm="⚠️ حذف برند، تمام صفحات، مقالات و داده‌های آن را پاک می‌کند. مطمئن هستید؟">
                                <?= Auth::csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="brand_id" value="<?= (int)$brand['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" title="حذف">🗑️</button>
                            </form>
                            <?php endif; ?>
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
