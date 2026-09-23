<?php
/**
 * 📨 مدیریت درخواست‌های خدمات
 * =============================
 * لیست + فیلتر برند/وضعیت/تاریخ + جزئیات + تغییر وضعیت + ارسال مجدد اعلان
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();
$notifier = new NotificationService();

/* 🔀 تغییر وضعیت */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'status') {
    Auth::enforceCsrf();
    $id = (int)post('request_id');
    $status = post('status');
    if (in_array($status, ['new', 'reviewing', 'assigned', 'done', 'canceled'], true)) {
        $db->update('service_requests', [
            'status' => $status,
            'admin_note' => post('note') ?: null,
        ], 'id = ?', [$id]);
        Logger::activity((int)$_SESSION['user_id'], 'تغییر وضعیت درخواست', "درخواست #$id → $status");
        flash('success', '✅ وضعیت درخواست بروزرسانی شد.');
    }
    redirect('requests.php' . ((int)get_param('view') ? '?view=' . get_param('view') : ''));
}

/* 🗑️ حذف */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    Auth::enforceCsrf();
    $db->delete('service_requests', 'id = ?', [(int)post('request_id')]);
    flash('success', '🗑️ درخواست حذف شد.');
    redirect('requests.php');
}

/* 📤 ارسال مجدد اعلان‌ها */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'resend') {
    Auth::enforceCsrf();
    $id = (int)post('request_id');
    $req = $db->fetch('SELECT r.*, b.name_fa, b.name_en, b.logo, b.domain FROM service_requests r JOIN brands b ON b.id = r.brand_id WHERE r.id = ?', [$id]);
    if ($req) {
        $images = array_column($db->fetchAll('SELECT file_path FROM request_attachments WHERE request_id = ?', [$id]), 'file_path');
        $req['images'] = $images;
        $result = $notifier->sendServiceRequest($req, $req);
        $sent = array_filter(array_intersect_key($result, array_flip(['email', 'telegram', 'gscript', 'bale'])));
        flash(!empty($sent) ? 'success' : 'warning', '📤 ارسال مجدد انجام شد — کانال‌های موفق: ' . ($sent ? implode('، ', array_keys($sent)) : 'هیچ (تنظیمات را بررسی کنید)'));
    }
    redirect('requests.php?view=' . $id);
}

/* 📥 خروجی CSV */
if (get_param('export') === 'csv') {
    $rows = $db->fetchAll('SELECT r.*, b.name_fa AS brand FROM service_requests r JOIN brands b ON b.id = r.brand_id ORDER BY r.id DESC');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="requests-' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM برای اکسل فارسی
    $out = fopen('php://output', 'w');
    fputcsv($out, ['شناسه', 'برند', 'نام', 'تلفن', 'تلفن دوم', 'آدرس', 'دستگاه', 'مدل', 'شرح ایراد', 'وضعیت', 'تاریخ ثبت']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'], $r['brand'], $r['full_name'], $r['phone'], $r['phone2'], $r['address'], $r['device_key'], $r['device_model'], $r['description'], $r['status'], $r['created_at']]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'مدیریت درخواست‌ها';
$activeMenu = 'requests';
require __DIR__ . '/includes/header.php';

/* 👁️ نمای جزئیات یک درخواست */
if (($viewId = (int)get_param('view')) > 0) {
    $req = $db->fetch('SELECT r.*, b.name_fa AS brand_name, b.name_en AS brand_en, b.logo AS brand_logo, b.domain FROM service_requests r JOIN brands b ON b.id = r.brand_id WHERE r.id = ?', [$viewId]);
    if ($req) {
        $attachments = $db->fetchAll('SELECT * FROM request_attachments WHERE request_id = ?', [$viewId]);
        $statusMap = ['new' => ['جدید', 'badge-danger'], 'reviewing' => ['در حال بررسی', 'badge-warning'], 'assigned' => ['تخصیص یافته', 'badge-info'], 'done' => ['انجام شده', 'badge-success'], 'canceled' => ['لغو شده', 'badge-secondary']];
        $devices = $db->fetchAll('SELECT device_key, name_fa FROM brand_devices WHERE brand_id = ?', [$req['brand_id']]);
        ?>
        <div class="card">
            <div class="card-header">
                <h3>📨 درخواست #<?= en_to_fa_digits((string)$req['id']) ?></h3>
                <div class="tools">
                    <span class="badge <?= $statusMap[$req['status']][1] ?>"><?= $statusMap[$req['status']][0] ?></span>
                    <a href="requests.php" class="btn btn-outline btn-sm">بازگشت</a>
                </div>
            </div>
            <div class="card-body">
                <div style="display:flex;align-items:center;gap:14px;background:var(--bg);border-radius:12px;padding:14px 18px;margin-bottom:20px">
                    <?php if ($req['brand_logo']): ?>
                        <img src="<?= asset_url($req['brand_logo']) ?>" alt="<?= e($req['brand_name']) ?>" style="height:52px;object-fit:contain">
                    <?php endif; ?>
                    <div>
                        <div style="font-weight:800;font-size:15px">🏷️ <?= e($req['brand_name']) ?> (<?= e($req['brand_en']) ?>)</div>
                        <div style="font-size:12px;color:var(--text-light);direction:ltr;text-align:right"><?= e($req['domain']) ?></div>
                    </div>
                    <div style="margin-inline-start:auto;text-align:left;font-size:11.5px;color:var(--text-light)">
                        ⏰ <?= jdate($req['created_at'], true) ?><br>
                        🌐 <?= e($req['ip_address'] ?? '—') ?>
                    </div>
                </div>

                <div class="form-row">
                    <div>
                        <?php
                        $fields = [
                            '👤 نام و نام خانوادگی' => $req['full_name'],
                            '📞 شماره تماس' => '<a href="tel:' . e(fa_to_en_digits($req['phone'])) . '" style="direction:ltr;display:inline-block">' . e(fa_to_en_digits($req['phone'])) . '</a>',
                            '📞 شماره تماس دوم' => $req['phone2'] ? '<a href="tel:' . e(fa_to_en_digits($req['phone2'])) . '" style="direction:ltr;display:inline-block">' . e(fa_to_en_digits($req['phone2'])) . '</a>' : '—',
                            '📍 آدرس' => $req['address'],
                            '🔧 نوع دستگاه' => e($req['device_key']) . ($req['device_other'] ? ' (' . e($req['device_other']) . ')' : ''),
                            '📋 مدل دستگاه' => $req['device_model'] ?: '—',
                            '📅 زمان مراجعه ترجیحی' => trim(($req['preferred_date'] ? jdate($req['preferred_date']) : '') . ' ' . ($req['preferred_time'] ?: '')) ?: '—',
                        ];
                        ?>
                        <table class="table">
                            <?php foreach ($fields as $label => $value): ?>
                                <tr><td style="width:170px;color:var(--text-light);font-size:12px"><?= $label ?></td><td style="font-weight:600"><?= $value ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                    <div>
                        <div class="form-group">
                            <label>📝 شرح ایراد</label>
                            <div style="background:var(--bg);border-radius:11px;padding:14px 16px;line-height:2.1"><?= nl2br(e($req['description'])) ?></div>
                        </div>
                        <?php if (!empty($attachments)): ?>
                            <div class="form-group">
                                <label>🖼️ تصاویر پیوست (<?= en_to_fa_digits((string)count($attachments)) ?>)</label>
                                <div style="display:flex;gap:10px;flex-wrap:wrap">
                                    <?php foreach ($attachments as $att): ?>
                                        <a href="<?= asset_url($att['file_path']) ?>" target="_blank"><img src="<?= asset_url($att['file_path']) ?>" style="width:88px;height:88px;object-fit:cover;border-radius:10px;border:1px solid var(--border)" loading="lazy"></a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">
                <!-- 🔄 تغییر وضعیت -->
                <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                    <div class="form-group" style="flex:0 0 180px;margin:0">
                        <label>وضعیت</label>
                        <select name="status" class="form-control">
                            <?php foreach ($statusMap as $key => [$label]): ?>
                                <option value="<?= $key ?>" <?= $req['status'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;min-width:220px;margin:0">
                        <label>یادداشت</label>
                        <input type="text" name="note" class="form-control" value="<?= e($req['admin_note'] ?? '') ?>" placeholder="یادداشت داخلی...">
                    </div>
                    <button type="submit" class="btn btn-primary">💾 ذخیره</button>
                </form>
                <form method="post" style="margin-top:10px;display:inline">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="resend">
                    <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                    <button type="submit" class="btn btn-outline">📤 ارسال مجدد به کانال‌ها</button>
                </form>
                <form method="post" style="margin-top:10px;display:inline" data-confirm="این درخواست حذف شود؟">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                    <button type="submit" class="btn btn-danger">🗑️ حذف</button>
                </form>
            </div>
        </div>
        <?php
        require __DIR__ . '/includes/footer.php';
        exit;
    }
}

/* 🔎 فیلترها */
$brandFilter = (int)get_param('brand');
$statusFilter = get_param('status');
$where = '1=1';
$params = [];
if ($brandFilter > 0) {
    $where .= ' AND r.brand_id = ?';
    $params[] = $brandFilter;
}
if ($statusFilter !== '') {
    $where .= ' AND r.status = ?';
    $params[] = $statusFilter;
}
$page = max(1, (int)get_param('p'));
$perPage = 20;
$total = $db->count('service_requests r', $where, $params);
$requests = $db->fetchAll(
    "SELECT r.*, b.name_fa AS brand_name, b.logo AS brand_logo
     FROM service_requests r JOIN brands b ON b.id = r.brand_id
     WHERE {$where} ORDER BY r.id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
    $params
);
$brands = $db->fetchAll('SELECT id, name_fa FROM brands ORDER BY name_fa');
$statusMap = ['new' => ['جدید', 'badge-danger'], 'reviewing' => ['در حال بررسی', 'badge-warning'], 'assigned' => ['تخصیص یافته', 'badge-info'], 'done' => ['انجام شده', 'badge-success'], 'canceled' => ['لغو شده', 'badge-secondary']];
$counts = ['new' => $db->count('service_requests', "status = 'new'"), 'all' => $db->count('service_requests')];
?>
<div class="stats-grid">
    <a class="stat-card" href="requests.php"><div class="icon bg-blue">📨</div><div><div class="number"><?= en_to_fa_digits((string)$counts['all']) ?></div><div class="label">کل درخواست‌ها</div></div></a>
    <a class="stat-card" href="requests.php?status=new"><div class="icon bg-red">🆕</div><div><div class="number"><?= en_to_fa_digits((string)$counts['new']) ?></div><div class="label">درخواست جدید</div></div></a>
</div>

<div class="card">
    <div class="card-header">
        <h3>📨 لیست درخواست‌ها</h3>
        <div class="tools">
            <form method="get" style="display:flex;gap:8px;flex-wrap:wrap">
                <select name="brand" class="form-control" style="max-width:170px;min-width:140px">
                    <option value="">همه برندها</option>
                    <?php foreach ($brands as $brand): ?>
                        <option value="<?= (int)$brand['id'] ?>" <?= $brandFilter === (int)$brand['id'] ? 'selected' : '' ?>><?= e($brand['name_fa']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status" class="form-control" style="max-width:160px;min-width:130px">
                    <option value="">همه وضعیت‌ها</option>
                    <?php foreach ($statusMap as $key => [$label]): ?>
                        <option value="<?= $key ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline">فیلتر</button>
                <a href="?export=csv" class="btn btn-outline">📥 CSV</a>
            </form>
        </div>
    </div>
    <div class="table-wrap">
        <?php if (empty($requests)): ?>
            <div class="empty-state"><div class="icon">📭</div><p>درخواستی یافت نشد.<br><small>درخواست‌های ثبت‌شده در سایت‌های برند اینجا نمایش داده می‌شوند.</small></p></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>#</th><th>برند</th><th>متقاضی</th><th>دستگاه</th><th>وضعیت</th><th>زمان</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($requests as $req): ?>
                <tr <?= $req['status'] === 'new' ? 'style="background:rgba(220,38,38,.03)"' : '' ?>>
                    <td><b><?= en_to_fa_digits((string)$req['id']) ?></b></td>
                    <td>
                        <div class="brand-cell">
                            <?php if ($req['brand_logo']): ?><img src="<?= asset_url($req['brand_logo']) ?>" alt=""><?php endif; ?>
                            <span class="name" style="font-size:12px"><?= e($req['brand_name']) ?></span>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight:700;font-size:12.5px"><?= e($req['full_name']) ?></div>
                        <a href="tel:<?= e(fa_to_en_digits($req['phone'])) ?>" style="font-size:11.5px;direction:ltr;display:inline-block;color:var(--primary)"><?= e(fa_to_en_digits($req['phone'])) ?></a>
                    </td>
                    <td style="font-size:12px"><?= e($req['device_key']) ?><?= $req['device_other'] ? '<br><small style="color:var(--text-light)">' . e($req['device_other']) . '</small>' : '' ?></td>
                    <td><span class="badge <?= $statusMap[$req['status']][1] ?>"><?= $statusMap[$req['status']][0] ?></span></td>
                    <td style="font-size:11px;color:var(--text-light)"><?= time_ago_fa($req['created_at']) ?></td>
                    <td><a href="requests.php?view=<?= (int)$req['id'] ?>" class="btn btn-outline btn-sm">👁️ مشاهده</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php if ($total > $perPage): ?>
        <div class="pagination">
            <?php for ($p = 1; $p <= ceil($total / $perPage); $p++): ?>
                <?= $p === $page ? "<span class=\"current\">" . en_to_fa_digits((string)$p) . "</span>" : "<a href=\"?p=$p&brand=$brandFilter&status=" . e($statusFilter) . "\">" . en_to_fa_digits((string)$p) . "</a>" ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
