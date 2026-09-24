<?php
/**
 * 🚨 مدیریت کدهای خطا — v2.6
 * ============================
 * ساختار ۱۴ فیلدی + تولید موتور خطایاب AI (پایگاه دانش + جستجوی آنلاین)
 * + فیلتر برند → دستگاه + ویرایش کامل با بهینه‌سازی تک‌فیلد AI
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();
$fm = new FileManager();
$engine = new ErrorCodeEngine();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::enforceCsrf();
    $action = post('action');

    /* ➕ افزودن دستی (۱۴ فیلد) */
    if ($action === 'add') {
        $db->insert('error_codes', [
            'brand_id'         => (int)post('brand_id') ?: null,
            'device_key'       => SlugGenerator::generate(post('device')),
            'code'             => post('code'),
            'title'            => post('title'),
            'description'      => post('description'),
            'subtype'          => post('subtype') ?: 'همه زیرنوع‌ها',
            'models'           => json_encode(array_values(array_filter(array_map('trim', explode("\n", (string)($_POST['models'] ?? ''))))), JSON_UNESCAPED_UNICODE),
            'causes'           => json_encode(array_filter(array_map('trim', explode("\n", (string)($_POST['causes'] ?? '')))), JSON_UNESCAPED_UNICODE),
            'solutions'        => json_encode(array_filter(array_map('trim', explode("\n", (string)($_POST['solutions'] ?? '')))), JSON_UNESCAPED_UNICODE),
            'category'         => post('category') ?: 'سایر',
            'related_part'     => post('related_part'),
            'tech_specs'       => post('tech_specs'),
            'part_location'    => post('part_location'),
            'severity'         => post('severity'),
            'needs_technician' => !empty($_POST['needs_technician']) ? 1 : 0,
            'source'           => 'manual',
            'is_active'        => 1,
        ]);
        flash('success', '✅ کد خطا با ساختار کامل ثبت شد.');
        redirect('error-codes.php');
    }

    /* 🗑️ حذف */
    if ($action === 'delete') {
        $db->delete('error_codes', 'id = ?', [(int)post('error_id')]);
        flash('success', '🗑️ کد خطا حذف شد.');
        redirect('error-codes.php');
    }

    /* 🗑️🗑️ حذف دسته‌جمعی نتایج فیلتر (برند + دستگاه + جستجو) — v2.7.2
       فقط همان رکوردهایی حذف می‌شوند که «الان در فهرست فیلترشده» دیده می‌شوند */
    if ($action === 'bulk_delete') {
        $b = (int)post('brand');
        $d = trim((string)post('device'));
        $q = trim((string)post('q'));
        $where = '1=1';
        $params = [];
        if ($b > 0) {
            $where .= ' AND brand_id = ?';
            $params[] = $b;
        } elseif ($b === -1) {
            $where .= ' AND brand_id IS NULL';
        }
        if ($d !== '') {
            $where .= ' AND device_key = ?';
            $params[] = $d;
        }
        if ($q !== '') {
            $where .= ' AND (code LIKE ? OR title LIKE ? OR description LIKE ? OR related_part LIKE ?)';
            $like = "%{$q}%";
            array_push($params, $like, $like, $like, $like);
        }
        $count = $db->count('error_codes', $where, $params);
        if ($count > 0) {
            $db->delete('error_codes', $where, $params);
            flash('success', '🗑️ ' . en_to_fa_digits((string)$count) . ' کد خطا مطابق فیلتر حذف شد.');
        } else {
            flash('warning', 'هیچ کد خطایی مطابق این فیلتر یافت نشد.');
        }
        redirect('error-codes.php' . ($b > 0 ? '?brand=' . $b : ''));
    }

    /* 💾 ذخیره ویرایش ۱۴ فیلدی */
    if ($action === 'save_edit') {
        $id = (int)post('error_id');
        $db->update('error_codes', [
            'code'          => post('code'),
            'title'         => post('title'),
            'description'   => post('description'),
            'subtype'       => post('subtype'),
            'models'        => json_encode(array_values(array_filter(array_map('trim', explode("\n", (string)($_POST['models'] ?? ''))))), JSON_UNESCAPED_UNICODE),
            'causes'        => json_encode(array_filter(array_map('trim', explode("\n", (string)($_POST['causes'] ?? '')))), JSON_UNESCAPED_UNICODE),
            'solutions'     => json_encode(array_filter(array_map('trim', explode("\n", (string)($_POST['solutions'] ?? '')))), JSON_UNESCAPED_UNICODE),
            'category'      => post('category'),
            'related_part'  => post('related_part'),
            'tech_specs'    => post('tech_specs'),
            'part_location' => post('part_location'),
            'severity'      => post('severity'),
            'needs_technician' => !empty($_POST['needs_technician']) ? 1 : 0,
        ], 'id = ?', [$id]);
        flash('success', '✅ کد خطا ذخیره شد.');
        redirect('error-codes.php?edit=' . $id);
    }

    /* 📥 ورود از فایل JSON/Excel */
    if ($action === 'import') {
        $upload = $fm->uploadData($_FILES['import_file']);
        if (!$upload['success']) {
            flash('danger', 'خطای آپلود: ' . $upload['error']);
            redirect('error-codes.php');
        }
        $legacy = new ErrorCodeGenerator();
        $ext = mb_strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        $codes = $ext === 'json' ? $legacy->parseJsonFile($upload['path']) : $legacy->parseExcelFile($upload['path']);
        @unlink($upload['path']);
        if (empty($codes)) {
            flash('danger', 'هیچ کد خطای معتبری از فایل استخراج نشد.');
            redirect('error-codes.php');
        }
        $result = $legacy->import((int)post('brand_id'), $codes);
        flash($result['errors'] ? 'warning' : 'success',
            "📥 نتیجه ورود: {$result['imported']} کد ثبت شد، {$result['skipped']} تکراری رد شد" .
            ($result['errors'] ? ' — خطاها: ' . implode('؛ ', array_slice($result['errors'], 0, 3)) : ''));
        redirect('error-codes.php');
    }

    /* 🚨 تولید همه کدهای واقعی یک دستگاه با موتور خطایاب AI */
    if ($action === 'generate_device') {
        $brandId = (int)post('brand_id');
        $deviceKey = (string)post('device_key');
        /* 🐛 v2.12: چک‌باکس HTML بدون value="1" مقدار «on» می‌فرستاد و === '1'
           همیشه false بود → جستجوی آنلاین هرگز اجرا نمی‌شد و خطای
           «جستجوی آنلاین غیرفعال بود» نمایش داده می‌شد. حالا هر دو مقدار
           پذیرفته می‌شود (HTML هم value="1" گرفت). */
        $useWeb = in_array(post('use_web'), ['1', 'on', 'true'], true);
        $overwrite = in_array(post('overwrite'), ['1', 'on', 'true'], true);
        try {
            $result = $engine->generateForDevice($brandId, $deviceKey, $useWeb, $overwrite);
            $msg = '🚨 موتور خطایاب AI: ' . $result['report'] . ' — منابع: ' . implode('، ', array_slice($result['sources'], 0, 3));
            flash($result['inserted'] > 0 ? 'success' : 'warning', $msg);
        } catch (Throwable $e) {
            flash('danger', 'خطای تولید: ' . $e->getMessage());
        }
        redirect('error-codes.php?brand=' . $brandId . '&device=' . urlencode($deviceKey));
    }

    /* ✨ بهینه‌سازی یک فیلد با AI (AJAX) */
    if ($action === 'improve_field') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $result = $engine->improveField((int)post('error_id'), (string)post('field'));
            json_response(['success' => true, 'data' => $result]);
        } catch (Throwable $e) {
            json_response(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}

$pageTitle = 'مدیریت کدهای خطا';
$activeMenu = 'error-codes';
require __DIR__ . '/includes/header.php';

$brands = $db->fetchAll('SELECT id, name_fa, name_en FROM brands ORDER BY name_fa');
$brandFilter = (int)get_param('brand');
$deviceFilter = (string)get_param('device');
$search = get_param('q');
$editId = (int)get_param('edit');

/* ✏️ رکورد ویرایش */
$editCode = null;
if ($editId > 0) {
    $editCode = $db->fetch('SELECT e.*, b.name_fa AS brand_name FROM error_codes e LEFT JOIN brands b ON b.id = e.brand_id WHERE e.id = ?', [$editId]);
}

/* 🗂️ دستگاه‌های هر برند (برای فیلتر برند→دستگاه) */
$brandDevices = [];
if ($brandFilter > 0) {
    $brandDevices = $db->fetchAll('SELECT device_key, name_fa FROM brand_devices WHERE brand_id = ? ORDER BY sort_order, id', [$brandFilter]);
}

/* 🔎 فهرست */
$where = '1=1';
$params = [];
if ($brandFilter > 0) {
    $where .= ' AND e.brand_id = ?';
    $params[] = $brandFilter;
} elseif ($brandFilter === -1) {
    $where .= ' AND e.brand_id IS NULL';
}
if ($deviceFilter !== '') {
    $where .= ' AND e.device_key = ?';
    $params[] = $deviceFilter;
}
if ($search !== '') {
    $where .= ' AND (e.code LIKE ? OR e.title LIKE ? OR e.description LIKE ? OR e.related_part LIKE ?)';
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like);
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
$severityMap = [
    'low' => ['کم 🟢', 'badge-secondary'],
    'medium' => ['متوسط 🟡', 'badge-info'],
    'high' => ['زیاد 🟠', 'badge-warning'],
    'critical' => ['بحرانی 🔴', 'badge-danger'],
    'informational' => ['اطلاعاتی 🔵', 'badge-secondary'],
];
$categories = ErrorCodeEngine::CATEGORIES;
?>

<?php if ($editCode): ?>
<!-- ✏️ ویرایش کامل ۱۴ فیلدی -->
<div class="card" style="border-color:var(--primary)">
    <div class="card-header">
        <h3>✏️ ویرایش کد خطا — <code><?= e($editCode['code']) ?></code> (<?= e($editCode['brand_name'] ?: 'عمومی') ?>)</h3>
        <div class="tools"><a href="error-codes.php" class="btn btn-outline btn-sm">بازگشت به فهرست</a></div>
    </div>
    <div class="card-body">
        <form method="post">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="save_edit">
            <input type="hidden" name="error_id" value="<?= (int)$editCode['id'] ?>">

            <?php
            $causesArr = json_decode((string)$editCode['causes'], true) ?: [];
            $solutionsArr = json_decode((string)$editCode['solutions'], true) ?: [];
            $modelsArr = json_decode((string)$editCode['models'], true) ?: [];
            $fieldRow = function ($label, $name, $value, $hint = '', $tag = 'input', $rows = 3, $ltr = false) {
                $aiFields = ['title', 'description', 'causes', 'solutions', 'related_part', 'tech_specs', 'part_location', 'subtype'];
                $aiBtn = in_array($name, $aiFields, true)
                    ? '<button type="button" class="btn btn-success btn-sm btn-ec-ai" data-field="' . $name . '" data-error="' . (int)$editCode['id'] . '" title="این فیلد را با AI بازنویسی و یکتا کن">✨ AI</button>'
                    : '';
                echo '<div class="form-group"><label style="display:flex;justify-content:space-between;align-items:center">' . $label . $aiBtn . '</label>';
                $style = $ltr ? ' style="direction:ltr;text-align:left"' : '';
                if ($tag === 'textarea') {
                    echo '<textarea name="' . $name . '" id="ec-' . $name . '" class="form-control" rows="' . $rows . '"' . $style . '>' . e($value) . '</textarea>';
                } else {
                    echo '<input type="text" name="' . $name . '" id="ec-' . $name . '" class="form-control" value="' . e($value) . '"' . $style . '>';
                }
                if ($hint) { echo '<div class="hint">' . $hint . '</div>'; }
                echo '</div>';
            };
            ?>

            <div class="form-row-3">
                <?php $fieldRow('۱️⃣ کد خطا (روی نمایشگر)', 'code', $editCode['code'], 'مثل OE / F01 / CH05 — دقیقاً همان‌طور که نمایش داده می‌شود', 'input', 3, true); ?>
                <div class="form-group">
                    <label>۷️⃣ شدت خطا</label>
                    <select name="severity" class="form-control">
                        <?php foreach ($severityMap as $k => [$lbl]): ?>
                            <option value="<?= $k ?>" <?= $editCode['severity'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>نیاز به تکنسین</label>
                    <label class="form-check"><input type="checkbox" name="needs_technician" <?= $editCode['needs_technician'] ? 'checked' : '' ?>> مداخله تکنسین لازم است</label>
                </div>
            </div>

            <?php $fieldRow('۶️⃣ عنوان فارسی', 'title', $editCode['title'], 'کوتاه، رسا و فنی'); ?>
            <?php $fieldRow('۸️⃣ توضیح کامل فارسی (۳-۵ جمله یکتا)', 'description', $editCode['description'], 'ماهیت خطا، اتفاق داخلی، تأثیر بر عملکرد، قابل استفاده بودن، خوداصلاحی', 'textarea', 5); ?>
            <?php $fieldRow('۳️⃣ زیرنوع دستگاه', 'subtype', $editCode['subtype'], 'مثل: اینورتر | Inverter — یا «همه زیرنوع‌ها»'); ?>
            <?php $fieldRow('۴️⃣ مدل‌های دارای این کد (هر خط یک مدل)', 'models', implode("\n", $modelsArr), 'تا حد امکان کامل و واقعی', 'textarea', 3, true); ?>
            <?php $fieldRow('۹️⃣ دلایل احتمالی (هر خط یک مورد — شایع→نادر)', 'causes', implode("\n", $causesArr), 'حداقل ۳، حداکثر ۷ — مختص همین دستگاه و برند', 'textarea', 5); ?>
            <?php $fieldRow('🔟 راه‌حل‌ها (هر خط یک مورد با برچسب [کاربر] یا [تکنسین])', 'solutions', implode("\n", $solutionsArr), 'حداقل ۳، حداکثر ۷ — ساده→پیچیده', 'textarea', 5); ?>

            <div class="form-row">
                <div class="form-group">
                    <label>1️⃣1️⃣ نوع خطا (دسته‌بندی)</label>
                    <select name="category" class="form-control">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= e($cat) ?>" <?= $editCode['category'] === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php $fieldRow('1️⃣2️⃣ قطعه مربوطه', 'related_part', $editCode['related_part'], 'مثل: سنسور NTC دمای اواپراتور'); ?>
            </div>
            <?php $fieldRow('1️⃣3️⃣ مشخصات فنی قطعه', 'tech_specs', $editCode['tech_specs'], 'مقاومت/ولتاژ/توان/دما — مقادیر واقعی', 'textarea', 2); ?>
            <?php $fieldRow('1️⃣4️⃣ محل قرارگیری قطعه', 'part_location', $editCode['part_location'], 'مثل: یونیت خارجی، سمت چپ کمپرسور'); ?>

            <button type="submit" class="btn btn-primary btn-lg">💾 ذخیره کد خطا</button>
        </form>
    </div>
</div>

<?php else: ?>

<div class="grid-2">
    <!-- 🚨 موتور خطایاب AI — برند → دستگاه → همه کدها -->
    <div class="card" style="border-color:var(--primary)">
        <div class="card-header"><h3>🚨 تولید با موتور خطایاب AI (کدهای واقعی)</h3></div>
        <div class="card-body">
            <form method="post" id="form-generate">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="generate_device">
                <div class="form-group">
                    <label>۱. برند را انتخاب کنید</label>
                    <select name="brand_id" id="gen-brand" class="form-control" required>
                        <?php foreach ($brands as $brand): ?>
                            <option value="<?= (int)$brand['id'] ?>"><?= e($brand['name_fa']) ?> (<?= e($brand['name_en']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>۲. دستگاه این برند را انتخاب کنید</label>
                    <select name="device_key" id="gen-device" class="form-control" required>
                        <option value="">— ابتدا برند را انتخاب کنید —</option>
                    </select>
                    <div class="hint" id="gen-device-hint">فهرست دستگاه‌ها بر اساس برند انتخابی به‌صورت خودکار فیلتر می‌شود.</div>
                </div>
                <label class="form-check" style="margin:10px 0"><input type="checkbox" name="use_web" value="1" checked> 🌐 جستجوی آنلاین اینترنت (فارسی + خارجی) برای کدهای بیشتر با منبع‌یابی</label>
                <label class="form-check" style="margin-bottom:10px"><input type="checkbox" name="overwrite" value="1"> 🔄 جایگزینی کدهای قبلی همین دستگاه</label>
                <button type="submit" class="btn btn-success btn-block">🚨 تولید همه کدهای خطای واقعی این دستگاه</button>
                <div class="hint" style="margin-top:10px">
                    🧠 کدها «ساخته» نمی‌شوند — از پایگاه دانش ۱۵۰ کدی ۹ برند پرتقاضا + جستجوی آنلاین وب «استخراج» می‌شوند و همه ۱۴ فیلد به‌صورت یکتا و سئو-پسند تکمیل می‌گردد.
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
                <div class="hint" style="margin-top:10px">📋 ساختار استاندارد در <a href="docs.php?doc=error-codes" target="_blank">مستندات</a></div>
            </div>
        </div>

        <!-- ➕ افزودن دستی ۱۴ فیلدی -->
        <div class="card">
            <div class="card-header"><h3>➕ افزودن دستی (۱۴ فیلد)</h3></div>
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
                    <div class="form-row">
                        <div class="form-group"><label>زیرنوع</label><input type="text" name="subtype" class="form-control" placeholder="اینورتر | Inverter"></div>
                        <div class="form-group"><label>قطعه مربوطه</label><input type="text" name="related_part" class="form-control"></div>
                    </div>
                    <div class="form-group"><label>شرح کامل</label><textarea name="description" class="form-control" rows="2"></textarea></div>
                    <div class="form-row">
                        <div class="form-group"><label>دلایل (هر خط یک مورد)</label><textarea name="causes" class="form-control" rows="3"></textarea></div>
                        <div class="form-group"><label>راه‌حل‌ها (هر خط: [کاربر]/[تکنسین] ...)</label><textarea name="solutions" class="form-control" rows="3"></textarea></div>
                    </div>
                    <div class="form-row-3">
                        <div class="form-group">
                            <label>نوع خطا</label>
                            <select name="category" class="form-control">
                                <?php foreach ($categories as $cat): ?><option><?= e($cat) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>شدت</label>
                            <select name="severity" class="form-control">
                                <option value="low">کم</option>
                                <option value="medium" selected>متوسط</option>
                                <option value="high">زیاد</option>
                                <option value="critical">بحرانی</option>
                                <option value="informational">اطلاعاتی</option>
                            </select>
                        </div>
                        <div class="form-group" style="display:flex;align-items:flex-end">
                            <button type="submit" class="btn btn-primary btn-block">➕ ثبت</button>
                        </div>
                    </div>
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
            <form method="get" style="display:flex;gap:8px;flex-wrap:wrap">
                <input type="text" name="q" class="form-control" placeholder="🔎 جستجو کد/عنوان/قطعه..." value="<?= e($search) ?>" style="max-width:220px;min-width:150px">
                <select name="brand" id="filter-brand" class="form-control" style="max-width:170px;min-width:140px">
                    <option value="">همه برندها</option>
                    <option value="-1" <?= $brandFilter === -1 ? 'selected' : '' ?>>فقط عمومی</option>
                    <?php foreach ($brands as $brand): ?>
                        <option value="<?= (int)$brand['id'] ?>" <?= $brandFilter === (int)$brand['id'] ? 'selected' : '' ?>><?= e($brand['name_fa']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="device" id="filter-device" class="form-control" style="max-width:170px;min-width:140px">
                    <option value="">همه دستگاه‌ها</option>
                    <?php foreach ($brandDevices as $bd): ?>
                        <option value="<?= e($bd['device_key']) ?>" <?= $deviceFilter === $bd['device_key'] ? 'selected' : '' ?>><?= e($bd['name_fa']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline">فیلتر</button>
            </form>
            <?php if ($total > 0): ?>
            <!-- 🗑️🗑️ حذف دسته‌جمعی نتایج همین فیلتر — v2.7.2 -->
            <form method="post" data-confirm="حذف دسته‌جمعی: همه <?= en_to_fa_digits((string)$total) ?> کد خطایی که با همین فیلتر (برند/دستگاه/جستجو) دیده می‌شوند حذف شوند؟ این عمل بازگشت‌پذیر نیست!">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="bulk_delete">
                <input type="hidden" name="brand" value="<?= (int)$brandFilter ?>">
                <input type="hidden" name="device" value="<?= e($deviceFilter) ?>">
                <input type="hidden" name="q" value="<?= e($search) ?>">
                <button type="submit" class="btn btn-danger btn-sm" title="حذف همه نتایج فیلتر فعلی" style="white-space:nowrap">🗑️ حذف نتایج فیلتر (<?= en_to_fa_digits((string)$total) ?>)</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-wrap">
        <?php if (empty($codes)): ?>
            <div class="empty-state"><div class="icon">🚨</div><p>کد خطایی ثبت نشده است — با کارت «موتور خطایاب AI» برند و دستگاه انتخاب کنید تا همه کدهای واقعی تولید شود.</p></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>دستگاه</th><th>کد</th><th>عنوان</th><th>نوع / قطعه</th><th>شدت</th><th>برند</th><th>منبع</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($codes as $code): ?>
                <?php [$sevLabel, $sevBadge] = $severityMap[$code['severity']] ?? ['—', 'badge-secondary']; ?>
                <tr>
                    <td style="font-weight:700"><?= e($code['device_key']) ?></td>
                    <td><code style="background:var(--danger-light);color:var(--danger);padding:3px 9px;border-radius:7px;font-weight:700"><?= e($code['code']) ?></code></td>
                    <td style="max-width:250px">
                        <a href="error-codes.php?edit=<?= (int)$code['id'] ?>" style="font-weight:600;color:var(--text)"><?= e(excerpt($code['title'], 55)) ?></a>
                        <?php if ($code['description']): ?><small style="display:block;color:var(--text-light)"><?= e(excerpt($code['description'], 60)) ?></small><?php endif; ?>
                    </td>
                    <td style="font-size:11.5px">
                        <?= $code['category'] ? '<span class="badge badge-secondary">' . e($code['category']) . '</span>' : '' ?>
                        <small style="display:block;margin-top:3px"><?= e(excerpt((string)$code['related_part'], 30)) ?></small>
                    </td>
                    <td><span class="badge <?= $sevBadge ?>"><?= $sevLabel ?></span></td>
                    <td><?= $code['brand_name'] ? e($code['brand_name']) : '<span class="badge badge-secondary">عمومی</span>' ?></td>
                    <td><small style="color:var(--text-light)"><?= $code['source'] === 'web' ? '🌐 وب' : ($code['source'] === 'manual' ? '✍️ دستی' : '📚 دانش') ?></small></td>
                    <td style="white-space:nowrap">
                        <a href="error-codes.php?edit=<?= (int)$code['id'] ?>" class="btn btn-outline btn-sm" title="ویرایش ۱۴ فیلد">✏️</a>
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
    <?php if ($total > $perPage): ?>
        <div class="pagination">
            <?php for ($p = 1; $p <= ceil($total / $perPage); $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="current"><?= en_to_fa_digits((string)$p) ?></span>
                <?php else: ?>
                    <a href="?p=<?= $p ?>&brand=<?= $brandFilter ?>&device=<?= e($deviceFilter) ?>&q=<?= e($search) ?>"><?= en_to_fa_digits((string)$p) ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
/* 🔗 فیلتر برند → دستگاه (برای فرم تولید) */
(function () {
    'use strict';
    /* فهرست کامل دستگاه‌های هر برند از سرور (one-shot) */
    var deviceData = <?= json_encode(
        array_reduce($brands, function ($acc, $b) use ($db) {
            $rows = $db->fetchAll('SELECT device_key, name_fa FROM brand_devices WHERE brand_id = ? ORDER BY sort_order, id', [$b['id']]);
            $list = [];
            foreach ($rows as $r) { $list[$r['device_key']] = $r['name_fa']; }
            if (empty($list)) {
                $list = ['washing_machine' => 'ماشین لباسشویی', 'refrigerator' => 'یخچال‌فریزر', 'dishwasher' => 'ماشین ظرفشویی', 'air_conditioner' => 'کولر گازی'];
            }
            $acc[(int)$b['id']] = $list;
            return $acc;
        }, [])
    , JSON_UNESCAPED_UNICODE) ?>;

    function fillDevices(select, brandId, keepValue) {
        var keep = keepValue ? select.value : '';
        select.innerHTML = '<option value="">— ابتدا برند را انتخاب کنید —</option>';
        var devices = deviceData[brandId] || {};
        Object.keys(devices).forEach(function (k) {
            var o = document.createElement('option');
            o.value = k;
            o.textContent = devices[k];
            select.appendChild(o);
        });
        if (keep && devices[keep]) { select.value = keep; }
    }

    var genBrand = document.getElementById('gen-brand');
    var genDevice = document.getElementById('gen-device');
    if (genBrand && genDevice) {
        genBrand.addEventListener('change', function () { fillDevices(genDevice, parseInt(genBrand.value, 10), false); });
        if (genBrand.value) { fillDevices(genDevice, parseInt(genBrand.value, 10), false); }
    }

    var fBrand = document.getElementById('filter-brand');
    var fDevice = document.getElementById('filter-device');
    if (fBrand && fDevice) {
        fBrand.addEventListener('change', function () {
            fillDevices(fDevice, parseInt(fBrand.value, 10), true);
            if (!fBrand.value) { fDevice.value = ''; }
        });
    }
})();
</script>
<script>
/* ✨ بهینه‌سازی تک‌فیلد کد خطا با AI */
(function () {
    'use strict';
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-ec-ai');
        if (!btn) { return; }
        var field = btn.getAttribute('data-field');
        var errId = btn.getAttribute('data-error');
        var run = function () {
            var csrf = document.querySelector('input[name="csrf_token"]');
            var old = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '⏳';
            var body = new URLSearchParams();
            body.append('action', 'improve_field');
            body.append('error_id', errId);
            body.append('field', field);
            if (csrf) { body.append('csrf_token', csrf.value); }
            fetch('error-codes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf ? csrf.value : '', 'X-Requested-With': 'XMLHttpRequest' },
                body: body.toString(),
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).then(function (res) {
                btn.disabled = false;
                btn.innerHTML = old;
                if (!res.success) {
                    if (window.sahandToast) { sahandToast({ message: res.error || 'بهینه‌سازی ناموفق بود', type: 'warning' }); }
                    return;
                }
                /* نمایش مقدار جدید در کادر مربوطه (برای آرایه‌ها: هر مورد یک خط) */
                var el = document.getElementById('ec-' + field);
                if (el) {
                    var val = res.data.new;
                    try {
                        var parsed = JSON.parse(val);
                        if (Array.isArray(parsed)) { val = parsed.join('\n'); }
                    } catch (ignore) {}
                    el.value = val;
                }
                if (window.sahandToast) { sahandToast({ message: 'فیلد «' + field + '» با AI بازنویسی شد — برای ثبت، ذخیره کنید', type: 'success', duration: 5000 }); }
            }).catch(function (err) {
                btn.disabled = false;
                btn.innerHTML = old;
                if (window.sahandToast) { sahandToast({ message: 'خطای ارتباط: ' + err.message, type: 'danger' }); }
            });
        };
        if (window.sahandConfirm) {
            sahandConfirm({ title: 'بهینه‌سازی با AI', message: 'فیلد انتخابی بازنویسی و یکتاسازی شود؟ (برای ثبت نهایی، فرم را ذخیره کنید)', type: 'question', confirmText: 'بله، بازنویسی کن', confirmIcon: '✨' }).then(function (ok) { if (ok) { run(); } });
        } else { run(); }
    });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
