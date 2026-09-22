<?php
/**
 * 🚨 مدیریت کدهای خطا
 * =====================
 * CRUD + جستجو + ورود Excel/JSON + تولید AI
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();
$fm = new FileManager();
$generator = new ErrorCodeGenerator();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::enforceCsrf();
    $action = post('action');

    /* ➕ افزودن دستی */
    if ($action === 'add') {
        $db->insert('error_codes', [
            'brand_id'         => (int)post('brand_id') ?: null,
            'device_key'       => SlugGenerator::generate(post('device')),
            'code'             => post('code'),
            'title'            => post('title'),
            'description'      => post('description'),
            'causes'           => json_encode(array_filter(array_map('trim', explode("\n", (string)($_POST['causes'] ?? '')))), JSON_UNESCAPED_UNICODE),
            'solutions'        => json_encode(array_filter(array_map('trim', explode("\n", (string)($_POST['solutions'] ?? '')))), JSON_UNESCAPED_UNICODE),
            'severity'         => post('severity'),
            'needs_technician' => !empty($_POST['needs_technician']) ? 1 : 0,
            'is_active'        => 1,
        ]);
        flash('success', '✅ کد خطا ثبت شد.');
        redirect('error-codes.php');
    }

    /* 🗑️ حذف */
    if ($action === 'delete') {
        $db->delete('error_codes', 'id = ?', [(int)post('error_id')]);
        flash('success', '🗑️ کد خطا حذف شد.');
        redirect('error-codes.php');
    }

    /* 📥 ورود از فایل JSON/Excel */
    if ($action === 'import') {
        $upload = $fm->uploadData($_FILES['import_file']);
        if (!$upload['success']) {
            flash('danger', 'خطای آپلود: ' . $upload['error']);
            redirect('error-codes.php');
        }
        $ext = mb_strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        $codes = $ext === 'json' ? $generator->parseJsonFile($upload['path']) : $generator->parseExcelFile($upload['path']);
        @unlink($upload['path']);
        if (empty($codes)) {
            flash('danger', 'هیچ کد خطای معتبری از فایل استخراج نشد. ساختار فایل را با راهنما مطابقت دهید.');
            redirect('error-codes.php');
        }
        $result = $generator->import((int)post('brand_id'), $codes);
        flash($result['errors'] ? 'warning' : 'success',
            "📥 نتیجه ورود: {$result['imported']} کد ثبت شد، {$result['skipped']} تکراری رد شد" .
            ($result['errors'] ? ' — خطاها: ' . implode('؛ ', array_slice($result['errors'], 0, 3)) : ''));
        redirect('error-codes.php');
    }

    /* 🤖 تولید با AI برای برند */
    if ($action === 'generate') {
        $count = $generator->generateForBrand((int)post('brand_id'));
        flash('success', '🤖 ' . $count . ' کد خطای رایج از پایگاه دانش تولید شد.');
        redirect('error-codes.php');
    }
}

$pageTitle = 'مدیریت کدهای خطا';
$activeMenu = 'error-codes';
require __DIR__ . '/includes/header.php';

$brands = $db->fetchAll('SELECT id, name_fa FROM brands ORDER BY name_fa');
$brandFilter = (int)get_param('brand');
$search = get_param('q');

$where = '1=1';
$params = [];
if ($brandFilter > 0) {
    $where .= ' AND (e.brand_id = ? OR e.brand_id IS NULL)';
    $params[] = $brandFilter;
} elseif ($brandFilter === -1) {
    $where .= ' AND e.brand_id IS NULL';
}
if ($search !== '') {
    $where .= ' AND (e.code LIKE ? OR e.title LIKE ? OR e.description LIKE ?)';
    $like = "%{$search}%";
    array_push($params, $like, $like, $like);
}
$page = max(1, (int)get_param('p'));
$perPage = 25;
$total = $db->count('error_codes e', $where, $params);
$codes = $db->fetchAll(
    "SELECT e.*, b.name_fa AS brand_name FROM error_codes e
     LEFT JOIN brands b ON b.id = e.brand_id
     WHERE {$where} ORDER BY e.device_key, e.code LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
    $params
);
$severityMap = ['low' => ['کم', 'badge-secondary'], 'medium' => ['متوسط', 'badge-info'], 'high' => ['زیاد', 'badge-warning'], 'critical' => ['بحرانی', 'badge-danger']];
?>
<div class="grid-2">
    <!-- ➕ افزودن دستی -->
    <div class="card">
        <div class="card-header"><h3>➕ افزودن کد خطا</h3></div>
        <div class="card-body">
            <form method="post">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="form-row-3">
                    <div class="form-group">
                        <label>برند</label>
                        <select name="brand_id" class="form-control">
                            <option value="0">— عمومی (همه برندها) —</option>
                            <?php foreach ($brands as $brand): ?>
                                <option value="<?= (int)$brand['id'] ?>"><?= e($brand['name_fa']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>دستگاه</label>
                        <input type="text" name="device" class="form-control" required placeholder="لباسشویی">
                    </div>
                    <div class="form-group">
                        <label>کد خطا</label>
                        <input type="text" name="code" class="form-control" required placeholder="E1" style="direction:ltr;text-align:left">
                    </div>
                </div>
                <div class="form-group"><label>عنوان خطا</label><input type="text" name="title" class="form-control" required></div>
                <div class="form-group"><label>شرح کامل</label><textarea name="description" class="form-control" rows="2"></textarea></div>
                <div class="form-row">
                    <div class="form-group"><label>دلایل (هر خط یک مورد)</label><textarea name="causes" class="form-control" rows="3"></textarea></div>
                    <div class="form-group"><label>راه‌حل‌ها (هر خط یک مورد)</label><textarea name="solutions" class="form-control" rows="3"></textarea></div>
                </div>
                <div class="form-row-3">
                    <div class="form-group">
                        <label>سطح اهمیت</label>
                        <select name="severity" class="form-control">
                            <option value="low">کم</option>
                            <option value="medium" selected>متوسط</option>
                            <option value="high">زیاد</option>
                            <option value="critical">بحرانی</option>
                        </select>
                    </div>
                    <div class="form-group" style="display:flex;align-items:flex-end">
                        <label class="form-check"><input type="checkbox" name="needs_technician" checked> نیاز به تکنسین دارد</label>
                    </div>
                    <div class="form-group" style="display:flex;align-items:flex-end">
                        <button type="submit" class="btn btn-primary btn-block">➕ ثبت</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div>
        <!-- 📥 ورود گروهی -->
        <div class="card">
            <div class="card-header"><h3>📥 ورود گروهی (Excel / JSON)</h3></div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="import">
                    <div class="form-group">
                        <label>برند هدف</label>
                        <select name="brand_id" class="form-control">
                            <option value="0">— عمومی —</option>
                            <?php foreach ($brands as $brand): ?>
                                <option value="<?= (int)$brand['id'] ?>"><?= e($brand['name_fa']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>فایل XLSX یا JSON</label>
                        <input type="file" name="import_file" class="form-control" accept=".xlsx,.json" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">📤 ورود از فایل</button>
                </form>
                <div class="hint" style="margin-top:10px">
                    📋 ساختار استاندارد: <code>device, code, title, description, causes, solutions, severity, needs_technician</code>
                    — راهنمای کامل در <a href="docs.php?doc=error-codes" target="_blank">مستندات</a>
                </div>
            </div>
        </div>

        <!-- 🤖 تولید AI -->
        <div class="card">
            <div class="card-header"><h3>🤖 تولید با AI</h3></div>
            <div class="card-body">
                <form method="post">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="generate">
                    <div class="form-group">
                        <label>برند</label>
                        <select name="brand_id" class="form-control" required>
                            <?php foreach ($brands as $brand): ?>
                                <option value="<?= (int)$brand['id'] ?>"><?= e($brand['name_fa']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-success btn-block">🚨 تولید کدهای خطای رایج دستگاه‌ها</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 📋 لیست -->
<div class="card">
    <div class="card-header">
        <h3>🚨 کدهای خطا (<?= en_to_fa_digits((string)$total) ?>)</h3>
        <div class="tools">
            <form method="get" style="display:flex;gap:8px">
                <input type="text" name="q" class="form-control" placeholder="🔎 جستجو کد یا عنوان..." value="<?= e($search) ?>" style="width:200px">
                <select name="brand" class="form-control" style="width:150px">
                    <option value="">همه</option>
                    <option value="-1">فقط عمومی</option>
                    <?php foreach ($brands as $brand): ?>
                        <option value="<?= (int)$brand['id'] ?>" <?= $brandFilter === (int)$brand['id'] ? 'selected' : '' ?>><?= e($brand['name_fa']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline">فیلتر</button>
            </form>
        </div>
    </div>
    <div class="table-wrap">
        <?php if (empty($codes)): ?>
            <div class="empty-state"><div class="icon">🚨</div><p>کد خطایی ثبت نشده است.</p></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>دستگاه</th><th>کد</th><th>عنوان</th><th>سطح</th><th>تکنسین</th><th>برند</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($codes as $code): ?>
                <tr>
                    <td style="font-weight:700"><?= e($code['device_key']) ?></td>
                    <td><code style="background:var(--danger-light);color:var(--danger);padding:3px 9px;border-radius:7px;font-weight:700"><?= e($code['code']) ?></code></td>
                    <td style="max-width:250px">
                        <?= e(excerpt($code['title'], 55)) ?>
                        <?php if ($code['description']): ?><small style="display:block;color:var(--text-light)"><?= e(excerpt($code['description'], 60)) ?></small><?php endif; ?>
                    </td>
                    <td><span class="badge <?= $severityMap[$code['severity']][1] ?>"><?= $severityMap[$code['severity']][0] ?></span></td>
                    <td><?= $code['needs_technician'] ? '✅ بله' : '❌ خیر' ?></td>
                    <td><?= $code['brand_name'] ? e($code['brand_name']) : '<span class="badge badge-secondary">عمومی</span>' ?></td>
                    <td>
                        <form method="post" style="display:inline" data-confirm="این کد خطا حذف شود؟">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="error_id" value="<?= (int)$code['id'] ?>">
                            <button class="btn btn-danger btn-sm">🗑️</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
