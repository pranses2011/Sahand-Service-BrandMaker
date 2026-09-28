<?php
/**
 * 🕘 تاریخچه تغییرات (v2.34) — Revisions
 * =======================================
 * فهرست نسخه‌های ذخیره‌شده برند/صفحه/مقاله + پیش‌نمایش + بازگردانی.
 * ACL: brand_manager فقط نسخه‌های برندهای خودش را می‌بیند.
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();
$revMgr = new Revision();

/* ♻️ بازگردانی نسخه */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'restore') {
    Auth::enforceCsrf();
    $rid = (int)post('revision_id');
    $rev = $revMgr->get($rid);
    if (!$rev) {
        flash('danger', 'نسخه یافت نشد.');
        redirect('revisions.php');
    }
    /* 🛂 ACL — نسخه‌های برندهای دیگر ممنوع */
    if ($auth->isBrandManager()) {
        $ids = $auth->accessibleBrandIds();
        if (!in_array((int)$rev['brand_id'], $ids === null ? [] : $ids, true)) {
            flash('danger', 'شما به این نسخه دسترسی ندارید.');
            redirect('revisions.php');
        }
    }
    $result = $revMgr->restore($rid);
    flash($result['ok'] ? 'success' : 'danger', $result['message']);
    redirect('revisions.php' . ((int)$rev['entity_id'] > 0 && !empty($_POST['back_entity']) ? '?entity=' . urlencode($rev['entity_type'] . '-' . (int)$rev['entity_id']) : ''));
}

/* 🗑 حذف نسخه (فقط نقش سیستمی) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    Auth::enforceCsrf();
    $auth->requireSystemRole();
    $rid = (int)post('revision_id');
    if ($revMgr->delete($rid)) {
        Logger::activity((int)$_SESSION['user_id'], 'حذف نسخه تاریخچه', 'نسخه #' . $rid);
        flash('success', 'نسخه حذف شد.');
    } else {
        flash('danger', 'حذف ناموفق بود.');
    }
    redirect('revisions.php');
}

/* 🧹 پاک‌سازی همه نسخه‌های قدیمی‌تر از ۳۰ (فقط نقش سیستمی) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'prune_all') {
    Auth::enforceCsrf();
    $auth->requireSystemRole();
    $entities = $db->fetchAll('SELECT DISTINCT entity_type, entity_id FROM content_revisions');
    $n = 0;
    foreach ($entities as $en) {
        $revMgr->prune((string)$en['entity_type'], (int)$en['entity_id']);
        $n++;
    }
    flash('success', '✅ هرس نسخه‌های قدیمی برای ' . fa_num((string)$n) . ' موجودیت انجام شد (سقف ۳۰ نسخه).');
    redirect('revisions.php');
}

/* ════════════ فیلترها ════════════ */
$typeFilter = (string)get_param('type');
if (!in_array($typeFilter, ['brand', 'page', 'article', 'menu', ''], true)) {
    $typeFilter = '';
}
$entityFilter = (string)get_param('entity'); // مثل article-12
$viewRev = (int)get_param('view'); // پیش‌نمایش نسخه

/* لیست: فیلتر نوع + برند (ACL) */
$brandFilterParam = (int)get_param('brand');
if ($brandFilterParam > 0) {
    $auth->requireBrandAccess($brandFilterParam);
}
$rows = $revMgr->listAll(
    $typeFilter !== '' ? $typeFilter : null,
    $brandFilterParam > 0 ? $brandFilterParam : null,
    200
);

/* 🛂 ACL: brand_manager فقط نسخه‌های برندهای خودش */
if ($auth->isBrandManager()) {
    $ids = $auth->accessibleBrandIds() ?: [];
    $rows = array_values(array_filter($rows, function ($r) use ($ids) {
        return (int)$r['brand_id'] > 0 && in_array((int)$r['brand_id'], $ids, true);
    }));
}

/* فیلتر موجودیت خاص (entity=article-12) */
if (preg_match('/^(brand|page|article|menu)-(\d+)$/', $entityFilter, $em)) {
    $rows = array_values(array_filter($rows, function ($r) use ($em) {
        return $r['entity_type'] === $em[1] && (int)$r['entity_id'] === (int)$em[2];
    }));
}

/* 👁 پیش‌نمایش یک نسخه */
$preview = null;
if ($viewRev > 0) {
    $preview = $revMgr->get($viewRev);
    if ($preview && $auth->isBrandManager()) {
        $ids = $auth->accessibleBrandIds() ?: [];
        if ((int)$preview['brand_id'] > 0 && !in_array((int)$preview['brand_id'], $ids, true)) {
            $preview = null;
        }
    }
}

/* برندها برای فیلتر */
$brands = $db->fetchAll('SELECT id, name_fa FROM brands ORDER BY name_fa');
if ($auth->isBrandManager()) {
    $ids = $auth->accessibleBrandIds() ?: [];
    $brands = array_values(array_filter($brands, function ($b) use ($ids) {
        return in_array((int)$b['id'], $ids, true);
    }));
}

$typeLabels = ['brand' => '🏷️ برند', 'page' => '📄 صفحه', 'article' => '📰 مقاله', 'menu' => '☰ منو'];

$pageTitle = 'تاریخچه تغییرات';
$activeMenu = 'revisions';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1>🕘 تاریخچه تغییرات</h1>
    <p class="page-header-desc">
        هر تغییر برند / چیدمان صفحه / مقاله قبل از ذخیره بایگانی می‌شود — تا ۳۰ نسخه برای هر مورد.
        بازگردانی، وضعیت فعلی را هم به‌عنوان نسخه جدید ذخیره می‌کند (هیچ‌چیز از دست نمی‌رود).
    </p>
</div>

<!-- 🔍 فیلترها -->
<div class="card" style="margin-bottom:16px">
    <div style="padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;flex:1">
            <select name="type" class="form-control" style="width:auto" onchange="this.form.submit()">
                <option value="">همه انواع</option>
                <?php foreach ($typeLabels as $tk => $tl): ?>
                    <option value="<?= e($tk) ?>" <?= $typeFilter === $tk ? 'selected' : '' ?>><?= e($tl) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="brand" class="form-control" style="width:auto" onchange="this.form.submit()">
                <option value="">همه برندها</option>
                <?php foreach ($brands as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $brandFilterParam === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name_fa']) ?></option>
                <?php endforeach; ?>
            </select>
            <span style="font-size:12.5px;color:var(--text-light)"><?= e(fa_num((string)count($rows))) ?> نسخه</span>
        </form>
        <?php if (!$auth->isBrandManager()): ?>
        <form method="post" onsubmit="return confirm('نسخه‌های قدیمی‌تر از ۳۰ برای هر موجودیت حذف شوند؟')">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="prune_all">
            <button type="submit" class="btn btn-outline btn-sm">🧹 هرس قدیمی‌ها</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($preview): ?>
    <!-- 👁 پیش‌نمایش نسخه -->
    <div class="card" style="margin-bottom:16px;border-inline-start:4px solid #8b5cf6">
        <div style="padding:18px">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
                <h3 style="margin:0">
                    👁 نسخه #<?= e(fa_num((string)$preview['id'])) ?> — <?= e($typeLabels[$preview['entity_type']] ?? $preview['entity_type']) ?>
                    «<?= e($preview['title'] ?? '') ?>»
                </h3>
                <a href="revisions.php" class="btn btn-outline btn-sm">✕ بستن</a>
            </div>
            <div style="font-size:12.5px;color:var(--text-light);line-height:2;margin-bottom:12px">
                🕐 <?= e(fa_num(jdate('Y/m/d H:i:s', strtotime((string)$preview['created_at'])))) ?>
                | 👤 <?= e($preview['user_full'] ?? 'سیستم') ?>
                <?php if (!empty($preview['brand_name'])): ?> | 🏷️ <?= e($preview['brand_name']) ?><?php endif; ?>
            </div>
            <div style="background:#0f172a;border-radius:10px;padding:14px;max-height:340px;overflow:auto;direction:ltr;text-align:left">
                <pre style="margin:0;font-size:11.5px;line-height:1.7;color:#e2e8f0;white-space:pre-wrap;word-break:break-all"><?= e(json_encode($preview['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
            </div>
            <form method="post" style="margin-top:14px" onsubmit="return confirm('این نسخه جایگزین وضعیت فعلی شود؟ (وضعیت فعلی هم در تاریخچه ذخیره می‌شود)')">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="revision_id" value="<?= (int)$preview['id'] ?>">
                <input type="hidden" name="back_entity" value="1">
                <button type="submit" class="btn btn-primary">♻️ بازگردانی این نسخه</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- 📋 لیست -->
<div class="card">
    <div style="padding:18px">
        <?php if ($rows === []): ?>
            <div style="text-align:center;padding:40px 20px;color:var(--text-light)">
                <div style="font-size:44px;margin-bottom:10px">🕘</div>
                هنوز نسخه‌ای ثبت نشده است. با ویرایش برند، ذخیره چیدمان صفحه در قالب‌ساز یا ذخیره مقاله، نسخه‌ها خودکار ثبت می‌شوند.
            </div>
        <?php else: ?>
            <div style="overflow-x:auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>نوع</th>
                            <th>عنوان</th>
                            <th>برند</th>
                            <th>کاربر</th>
                            <th>زمان</th>
                            <th style="width:220px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?= e(fa_num((string)$r['id'])) ?></td>
                                <td><span class="badge badge-info"><?= e($typeLabels[$r['entity_type']] ?? $r['entity_type']) ?></span></td>
                                <td style="font-weight:600"><?= e($r['title'] ?? '—') ?> <span style="color:#94a3b8;font-size:11px">(#<?= e(fa_num((string)$r['entity_id'])) ?>)</span></td>
                                <td><?= e($r['brand_name'] ?? '—') ?></td>
                                <td style="font-size:12.5px"><?= e($r['user_full'] ?? 'سیستم') ?></td>
                                <td style="font-size:12.5px" title="<?= e($r['created_at']) ?>"><?= e(fa_num(jdate('Y/m/d H:i', strtotime((string)$r['created_at'])))) ?></td>
                                <td>
                                    <a class="btn btn-outline btn-sm" href="revisions.php?view=<?= (int)$r['id'] ?>">👁 پیش‌نمایش</a>
                                    <form method="post" style="display:inline" onsubmit="return confirm('این نسخه جایگزین وضعیت فعلی شود؟')">
                                        <?= Auth::csrfField() ?>
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="revision_id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm">♻️ بازگردانی</button>
                                    </form>
                                    <?php if (!$auth->isBrandManager()): ?>
                                    <form method="post" style="display:inline" onsubmit="return confirm('این نسخه حذف شود؟')">
                                        <?= Auth::csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="revision_id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="حذف">🗑️</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>.btn-sm{padding:4px 10px;font-size:12px}</style>
<?php require __DIR__ . '/includes/footer.php'; ?>
