<?php
/**
 * 🧩 فرم‌ساز سفارشی (S12 / v2.44) — «مانند Gravity Forms»
 * ============================================================
 * درخواست کاربر: ساخت فرم‌های سفارشی با هر نوع فیلد و تنظیمات برای
 * سایت‌های برند + عنصر «فرم سفارشی» در قالب‌ساز برای درج در صفحات.
 *
 * معماری: custom_forms (تعریف) + form_entries (ورودی — همان زنجیره اعلان)
 * این صفحه: فهرست فرم‌ها + ویرایشگر کامل (پالت فیلد + تنظیمات + پیش‌نمایش)
 *
 * @package SahandBrandMaker
 * @since 2.44.0
 */
define('SAHAND_ADMIN', true);
define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/header.php';

$db = Database::getInstance();
$brands = $db->fetchAll('SELECT id, name_fa FROM brands WHERE is_active = 1 ORDER BY name_fa') ?: [];

/* 💾 ذخیره (POST) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_form') {
    Auth::enforceCsrf();
    $in = [
        'title'       => (string)post('title'),
        'slug'        => (string)post('slug'),
        'description' => (string)post('description'),
        'brand_id'    => (int)post('brand_id') ?: null,
        'is_active'   => (int)post('is_active'),
        'fields'      => json_decode((string)post('fields_json'), true) ?: [],
        'settings'    => [
            'btnText'    => (string)post('btn_text'),
            'successMsg' => (string)post('success_msg'),
            'dest'       => (array)($_POST['dest'] ?? ['panel']),
            'layout'     => (string)post('layout'),
        ],
    ];
    $res = (new CustomFormManager())->save((int)post('id') ?: null, $in);
    if (!empty($res['ok'])) {
        flash('success', '✅ فرم ذخیره شد — عنصر «فرم سفارشی» در قالب‌ساز آن را نشان می‌دهد (شناسه: ' . e($res['slug']) . ').');
        redirect('form-builder.php?edit=' . (int)$res['id']);
    }
    flash('danger', 'ذخیره ناموفق: ' . ($res['error'] ?? 'خطای نامشخص'));
    redirect('form-builder.php' . ((int)post('id') ? '?edit=' . (int)post('id') : ''));
}

/* 🗑 حذف */
if (get_param('delete')) {
    Auth::enforceCsrf();
    $delId = (int)get_param('delete');
    $db->delete('custom_forms', 'id = ?', [$delId]);
    flash('success', '🗑 فرم حذف شد.');
    redirect('form-builder.php');
}

/* 📥 ویرایش موجود */
$editForm = null;
if (get_param('edit')) {
    $row = $db->fetch('SELECT * FROM custom_forms WHERE id = ?', [(int)get_param('edit')]);
    if ($row) {
        $editForm = CustomFormManager::hydrate($row);
    }
}

/* 📋 فهرست */
$forms = $db->fetchAll('SELECT f.*, (SELECT COUNT(*) FROM form_entries e WHERE e.form_slug = f.slug) AS real_entries FROM custom_forms f ORDER BY f.updated_at DESC') ?: [];
$totalEntries = array_sum(array_map(static fn($f) => (int)$f['real_entries'], $forms));
?>

<style>
/* 🧩 استایل اختصاصی فرم‌ساز */
.fb-palette { display: grid; grid-template-columns: repeat(auto-fill, minmax(126px, 1fr)); gap: 8px; }
.fb-pal-btn { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 6px; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 11px; cursor: pointer; font-size: 11px; font-weight: 700; color: #334155; transition: all .15s; text-align: center; }
.fb-pal-btn:hover { border-color: #2563eb; background: #f0f6ff; transform: translateY(-1px); }
.fb-pal-btn span.fb-ic { font-size: 19px; }
.fb-field { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; margin-bottom: 9px; overflow: hidden; }
.fb-field-head { display: flex; align-items: center; gap: 9px; padding: 9px 12px; cursor: pointer; user-select: none; }
.fb-field-head:hover { background: #f8fafc; }
.fb-field-body { padding: 4px 12px 13px; border-top: 1px dashed #e2e8f0; background: #fcfdff; display: none; }
.fb-field.open .fb-field-body { display: block; }
.fb-field.drag-over { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
.fb-grip { cursor: grab; color: #94a3b8; font-size: 15px; }
.fb-prev-field { margin-bottom: 10px; }
.fb-prev-label { font-size: 12px; font-weight: 700; margin-bottom: 5px; color: #334155; }
.fb-prev-input { width: 100%; padding: 9px 12px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-family: inherit; font-size: 12.5px; background: #fff; }
.fb-type-badge { font-size: 10px; background: #eef2ff; color: #3730a3; padding: 2px 8px; border-radius: 12px; font-weight: 700; }
</style>

<div class="page-header">
    <div>
        <h1>🧩 فرم‌ساز سفارشی</h1>
        <p class="page-desc">فرم‌های دلخواه با هر نوع فیلد بسازید و با عنصر «فرم سفارشی» در صفحات سایت برند قرار دهید — ورودی‌ها به پنل، ایمیل، تلگرام و بله می‌رسند.</p>
    </div>
    <div style="display:flex;gap:8px">
        <a class="btn btn-outline" href="form-entries.php">📥 صندوق ورودی‌ها</a>
        <a class="btn btn-primary" href="form-builder.php?new=1">➕ فرم جدید</a>
    </div>
</div>

<?php if (!$editForm && !get_param('new')): ?>
/* 📋 فهرست فرم‌ها */
<div class="card">
    <div class="card-header">
        <h3>📋 فرم‌های شما</h3>
        <span class="badge badge-info"><?= en_to_fa_digits((string)count($forms)) ?> فرم — <?= en_to_fa_digits((string)$totalEntries) ?> ورودی ثبت‌شده</span>
    </div>
    <div class="card-body">
        <?php if (!$forms): ?>
            <div class="empty-state">
                <div class="icon">🧩</div>
                <p>هنوز فرمی نساخته‌اید.<br><small>«فرم جدید» را بزنید — در ۲ دقیقه یک فرم حرفه‌ای با هر فیلدی آماده می‌شود.</small></p>
                <a class="btn btn-primary" href="form-builder.php?new=1">➕ ساخت اولین فرم</a>
            </div>
        <?php else: ?>
            <table class="dt">
                <thead><tr><th>فرم</th><th>شناسه (اسلاگ)</th><th>فیلدها</th><th>ورودی‌ها</th><th>وضعیت</th><th>بروزرسانی</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($forms as $f): ?>
                    <tr>
                        <td><b><?= e($f['title']) ?></b><?php if ($f['description']): ?><br><small style="color:#94a3b8"><?= e(mb_substr($f['description'], 0, 60)) ?></small><?php endif; ?></td>
                        <td><code dir="ltr" style="font-size:11px"><?= e($f['slug']) ?></code></td>
                        <td class="n"><?= en_to_fa_digits((string)count(json_decode((string)$f['fields'], true) ?: [])) ?></td>
                        <td class="n"><a href="form-entries.php?form=<?= e($f['slug']) ?>" style="color:#1d4ed8"><?= en_to_fa_digits((string)$f['real_entries']) ?></a></td>
                        <td><?= $f['is_active'] ? '<span class="badge badge-success">فعال</span>' : '<span class="badge badge-secondary">غیرفعال</span>' ?></td>
                        <td style="font-size:11px;color:#94a3b8"><?= e(fa_num(date('Y/m/d H:i', strtotime($f['updated_at'])))) ?></td>
                        <td style="white-space:nowrap">
                            <a class="btn btn-outline btn-sm" href="form-builder.php?edit=<?= (int)$f['id'] ?>">✏️ ویرایش</a>
                            <a class="btn btn-outline btn-sm" style="color:#dc2626" href="form-builder.php?delete=<?= (int)$f['id'] ?>&csrf_token=<?= e(Auth::csrfToken()) ?>" onclick="return confirm('فرم حذف شود؟ ورودی‌های ثبت‌شده باقی می‌مانند.')">🗑</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<?php
/* ✏️ ویرایشگر فرم */
$isEdit = $editForm !== null;
$efs = $isEdit ? $editForm['settings'] : ['btnText' => 'ارسال', 'successMsg' => '', 'dest' => ['panel'], 'layout' => 'two'];
?>
<form method="post" id="fb-form">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="save_form">
    <input type="hidden" name="id" value="<?= $isEdit ? (int)$editForm['id'] : '' ?>">
    <input type="hidden" name="fields_json" id="fb-fields-json" value="<?= e($isEdit ? json_encode($editForm['fields'], JSON_UNESCAPED_UNICODE) : '[]') ?>">

    <div style="display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:16px;align-items:start" class="fb-grid">
        <div>
            <div class="card" style="margin-bottom:14px">
                <div class="card-header"><h3><?= $isEdit ? '✏️ ویرایش: ' . e($editForm['title']) : '➕ فرم جدید' ?></h3></div>
                <div class="card-body">
                    <div class="form-row-2">
                        <div class="form-group">
                            <label>عنوان فرم *</label>
                            <input type="text" name="title" class="form-control" required maxlength="190" value="<?= e($isEdit ? $editForm['title'] : '') ?>" placeholder="مثلاً: فرم درخواست سرویس دوره‌ای">
                        </div>
                        <div class="form-group">
                            <label>شناسه (اسلاگ لاتین — خالی = خودکار)</label>
                            <input type="text" name="slug" class="form-control" style="direction:ltr;text-align:left" maxlength="120" value="<?= e($isEdit ? $editForm['slug'] : '') ?>" placeholder="periodic-service-form">
                        </div>
                    </div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label>توضیح بالای فرم (اختیاری)</label>
                            <input type="text" name="description" class="form-control" maxlength="600" value="<?= e($isEdit ? $editForm['description'] : '') ?>" placeholder="یک جمله برای کاربر — چرا این فرم را پر کند">
                        </div>
                        <div class="form-group">
                            <label>محدوده (کدام برندها)</label>
                            <select name="brand_id" class="form-control">
                                <option value="">🌐 همه برندها (سراسری)</option>
                                <?php foreach ($brands as $b): ?>
                                    <option value="<?= (int)$b['id'] ?>" <?= ($isEdit && $editForm['brand_id'] === (int)$b['id']) ? 'selected' : '' ?>>🏷 <?= e($b['name_fa']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" style="margin-bottom:14px">
                <div class="card-header"><h3>🧰 فیلدها <small style="font-weight:400;color:#94a3b8">— روی کارت هر نوع کلیک کنید تا اضافه شود</small></h3></div>
                <div class="card-body">
                    <div class="fb-palette" id="fb-palette"></div>
                    <hr style="border:none;border-top:1px dashed #e2e8f0;margin:13px 0">
                    <div id="fb-fields"></div>
                    <div id="fb-empty" class="empty-state" style="display:none"><div class="icon">🧲</div><p>فیلدی نیست — از پالت بالا فیلد اضافه کنید.<br><small>با کشیدن ⠿ جابه‌جا کنید یا ⬆⬇</small></p></div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3>⚙️ تنظیمات فرم</h3></div>
                <div class="card-body">
                    <div class="form-row-2">
                        <div class="form-group">
                            <label>متن دکمه ارسال</label>
                            <input type="text" name="btn_text" class="form-control" maxlength="60" value="<?= e($efs['btnText']) ?>">
                        </div>
                        <div class="form-group">
                            <label>چیدمان فیلدها</label>
                            <select name="layout" class="form-control">
                                <option value="two" <?= ($efs['layout'] ?? 'two') === 'two' ? 'selected' : '' ?>>دوستونه (فشرده)</option>
                                <option value="one" <?= ($efs['layout'] ?? '') === 'one' ? 'selected' : '' ?>>تک‌ستونه (کلاسیک)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>پیام موفقیت پس از ارسال</label>
                        <input type="text" name="success_msg" class="form-control" maxlength="300" value="<?= e($efs['successMsg'] ?? '') ?>" placeholder="✅ ثبت شد — به‌زودی با شما تماس می‌گیریم">
                    </div>
                    <label style="font-size:12.5px;font-weight:700;display:block;margin:10px 0 7px">📨 مقصد ارسال ورودی‌ها</label>
                    <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:12.5px">
                        <?php foreach (['panel' => '🗄 پنل مدیریت (همیشه)', 'email' => '📧 ایمیل', 'telegram' => '📱 تلگرام', 'bale' => '💬 بله'] as $dk => $dl): ?>
                            <label class="form-check" style="margin:0">
                                <input type="checkbox" name="dest[]" value="<?= $dk ?>" <?= in_array($dk, (array)($efs['dest'] ?? ['panel']), true) || $dk === 'panel' ? 'checked' : '' ?> <?= $dk === 'panel' ? 'onclick="this.checked=true"' : '' ?>>
                                <?= $dl ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <label class="form-check" style="margin:12px 0 0;font-size:12.5px">
                        <input type="checkbox" name="is_active" value="1" <?= (!$isEdit || $editForm['is_active']) ? 'checked' : '' ?>>
                        ✅ فرم فعال است (در عنصر قالب‌ساز و سایت نمایش داده شود)
                    </label>
                </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:14px">
                <button type="submit" class="btn btn-primary btn-lg">💾 ذخیره فرم</button>
                <a class="btn btn-outline btn-lg" href="form-builder.php">بازگشت به فهرست</a>
            </div>
        </div>

        <div class="card" style="position:sticky;top:14px">
            <div class="card-header"><h3>👁 پیش‌نمایش زنده</h3><span class="badge badge-secondary" style="font-size:10.5px">همان چیزی که کاربر می‌بیند</span></div>
            <div class="card-body">
                <div id="fb-preview" style="border:1px solid #e2e8f0;border-radius:12px;padding:14px;background:#fff"></div>
            </div>
        </div>
    </div>
</form>

<script>
/* 🧩 ویرایشگر فرم‌ساز — پالت فیلد + تنظیمات + پیش‌نمایش زنده (S12) */
(function () {
    'use strict';
    var FIELD_TYPES = <?= json_encode(CustomFormManager::FIELD_TYPES, JSON_UNESCAPED_UNICODE) ?>;
    var OPTION_TYPES = <?= json_encode(CustomFormManager::OPTION_TYPES) ?>;
    var STRUCTURAL = <?= json_encode(CustomFormManager::STRUCTURAL_TYPES) ?>;
    var fields = [];
    try { fields = JSON.parse(document.getElementById('fb-fields-json').value || '[]'); } catch (e) { fields = []; }
    if (!Array.isArray(fields)) { fields = []; }

    var palette = document.getElementById('fb-palette');
    var fieldsBox = document.getElementById('fb-fields');
    var emptyBox = document.getElementById('fb-empty');
    var preview = document.getElementById('fb-preview');
    var dragIdx = -1;

    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }

    /* پالت */
    Object.keys(FIELD_TYPES).forEach(function (t) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'fb-pal-btn';
        b.innerHTML = '<span class="fb-ic">' + FIELD_TYPES[t][1] + '</span>' + FIELD_TYPES[t][0];
        b.onclick = function () {
            var f = { type: t, label: FIELD_TYPES[t][0], name: '', required: false, width: 'full' };
            if (OPTION_TYPES.indexOf(t) >= 0) { f.options = ['گزینه ۱', 'گزینه ۲']; }
            if (t === 'consent') { f.text = 'قوانین و شرایط را می‌پذیرم'; f.required = true; }
            if (t === 'html') { f.text = 'متن توضیحی برای کاربر...'; }
            if (t === 'section') { f.label = 'بخش جدید'; }
            fields.push(f);
            render();
            /* آخرین فیلد باز شود */
            setTimeout(function () {
                var last = fieldsBox.querySelector('.fb-field:last-child .fb-field-head');
                if (last) { last.click(); }
            }, 30);
        };
        palette.appendChild(b);
    });

    function syncJson() {
        document.getElementById('fb-fields-json').value = JSON.stringify(fields);
        emptyBox.style.display = fields.length ? 'none' : '';
    }

    function fieldBody(f, i) {
        var isOpt = OPTION_TYPES.indexOf(f.type) >= 0;
        var isStruct = STRUCTURAL.indexOf(f.type) >= 0;
        var h = '<div class="form-row-2">';
        h += '<div class="form-group"><label>برچسب فیلد</label><input type="text" class="form-control fb-in" data-i="' + i + '" data-k="label" value="' + esc(f.label) + '"></div>';
        h += '<div class="form-group"><label>نام فیلد (لاتین — خالی = خودکار)</label><input type="text" class="form-control fb-in" style="direction:ltr;text-align:left" data-i="' + i + '" data-k="name" value="' + esc(f.name || '') + '" placeholder="field_' + (i + 1) + '"></div>';
        h += '</div>';
        if (f.type === 'consent' || f.type === 'html') {
            h += '<div class="form-group"><label>متن</label><textarea class="form-control fb-in" rows="2" data-i="' + i + '" data-k="text">' + esc(f.text || '') + '</textarea></div>';
        }
        if (!isStruct) {
            h += '<div class="form-row-2">';
            h += '<div class="form-group"><label>متن جایگزین (placeholder)</label><input type="text" class="form-control fb-in" data-i="' + i + '" data-k="placeholder" value="' + esc(f.placeholder || '') + '"></div>';
            h += '<div class="form-group"><label>مقدار پیش‌فرض</label><input type="text" class="form-control fb-in" data-i="' + i + '" data-k="default" value="' + esc(f.default || '') + '"></div>';
            h += '</div>';
        }
        if (isOpt) {
            h += '<div class="form-group"><label>گزینه‌ها (هر خط یک گزینه)</label><textarea class="form-control fb-in" rows="3" data-i="' + i + '" data-k="options" placeholder="گزینه ۱&#10;گزینه ۲">' + esc((f.options || []).join('\n')) + '</textarea></div>';
        }
        if (f.type === 'number') {
            h += '<div class="form-row-2"><div class="form-group"><label>حداقل</label><input type="number" class="form-control fb-in" data-i="' + i + '" data-k="min" value="' + esc(f.min != null ? f.min : '') + '"></div><div class="form-group"><label>حداکثر</label><input type="number" class="form-control fb-in" data-i="' + i + '" data-k="max" value="' + esc(f.max != null ? f.max : '') + '"></div></div>';
        }
        if (f.type === 'textarea') {
            h += '<div class="form-group"><label>تعداد سطرها</label><input type="number" min="2" max="12" class="form-control fb-in" data-i="' + i + '" data-k="rows" value="' + esc(f.rows || 4) + '"></div>';
        }
        if (!isStruct) {
            h += '<div class="form-row-2">';
            h += '<div class="form-group"><label>عرض فیلد</label><select class="form-control fb-in" data-i="' + i + '" data-k="width"><option value="full"' + (f.width !== 'half' ? ' selected' : '') + '>سطر کامل</option><option value="half"' + (f.width === 'half' ? ' selected' : '') + '>نیم‌سطر (دوتایی)</option></select></div>';
            h += '<div class="form-group"><label>راهنمای کوچک زیر فیلد</label><input type="text" class="form-control fb-in" data-i="' + i + '" data-k="help" value="' + esc(f.help || '') + '"></div>';
            h += '</div>';
        }
        h += '<label class="form-check" style="margin:4px 0"><input type="checkbox" class="fb-chk" data-i="' + i + '" data-k="required"' + (f.required ? ' checked' : '') + (f.type === 'consent' ? ' onclick="this.checked=true"' : '') + '> الزامی باشد <span style="color:#dc2626">*</span></label>';
        return h;
    }

    function render() {
        fieldsBox.innerHTML = '';
        fields.forEach(function (f, i) {
            var d = document.createElement('div');
            d.className = 'fb-field';
            d.draggable = true;
            d.innerHTML =
                '<div class="fb-field-head">' +
                '<span class="fb-grip" title="بکشید و جابه‌جا کنید">⠿</span>' +
                '<span style="font-size:16px">' + FIELD_TYPES[f.type][1] + '</span>' +
                '<b style="flex:1;font-size:12.5px">' + esc(f.label) + (f.required ? ' <span style="color:#dc2626">*</span>' : '') + '</b>' +
                '<span class="fb-type-badge">' + FIELD_TYPES[f.type][0] + '</span>' +
                '<button type="button" class="btn btn-outline" style="padding:1px 7px;font-size:10.5px" data-mv="up" title="بالا">⬆</button>' +
                '<button type="button" class="btn btn-outline" style="padding:1px 7px;font-size:10.5px" data-mv="down" title="پایین">⬇</button>' +
                '<button type="button" class="btn btn-outline" style="padding:1px 7px;font-size:10.5px;color:#dc2626" data-mv="del" title="حذف">✕</button>' +
                '</div><div class="fb-field-body">' + fieldBody(f, i) + '</div>';
            d.addEventListener('dragstart', function () { dragIdx = i; });
            d.addEventListener('dragover', function (e) { e.preventDefault(); d.classList.add('drag-over'); });
            d.addEventListener('dragleave', function () { d.classList.remove('drag-over'); });
            d.addEventListener('drop', function (e) {
                e.preventDefault();
                d.classList.remove('drag-over');
                if (dragIdx >= 0 && dragIdx !== i) {
                    var mv = fields.splice(dragIdx, 1)[0];
                    fields.splice(i, 0, mv);
                    render();
                }
                dragIdx = -1;
            });
            d.querySelector('.fb-field-head').addEventListener('click', function (e) {
                if (e.target.closest('button')) { return; }
                d.classList.toggle('open');
            });
            d.querySelectorAll('button[data-mv]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var mv = b.getAttribute('data-mv');
                    if (mv === 'up' && i > 0) { var t = fields[i - 1]; fields[i - 1] = fields[i]; fields[i] = t; }
                    if (mv === 'down' && i < fields.length - 1) { var t2 = fields[i + 1]; fields[i + 1] = fields[i]; fields[i] = t2; }
                    if (mv === 'del') { fields.splice(i, 1); }
                    render();
                });
            });
            d.querySelectorAll('.fb-in').forEach(function (inp) {
                inp.addEventListener('input', function () {
                    var ii = +inp.getAttribute('data-i'), k = inp.getAttribute('data-k');
                    if (k === 'options') {
                        fields[ii].options = inp.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
                    } else if (k === 'min' || k === 'max' || k === 'rows') {
                        fields[ii][k] = inp.value === '' ? null : +inp.value;
                    } else {
                        fields[ii][k] = inp.value;
                    }
                    /* عنوان سطر هم زنده بروز شود */
                    d.querySelector('.fb-field-head b').innerHTML = esc(fields[ii].label) + (fields[ii].required ? ' <span style="color:#dc2626">*</span>' : '');
                    syncJson();
                    renderPreview();
                });
            });
            d.querySelectorAll('.fb-chk').forEach(function (chk) {
                chk.addEventListener('change', function () {
                    var ii = +chk.getAttribute('data-i');
                    fields[ii].required = chk.checked;
                    d.querySelector('.fb-field-head b').innerHTML = esc(fields[ii].label) + (fields[ii].required ? ' <span style="color:#dc2626">*</span>' : '');
                    syncJson();
                    renderPreview();
                });
            });
            fieldsBox.appendChild(d);
        });
        syncJson();
        renderPreview();
    }

    function prevFieldHtml(f) {
        var req = f.required ? ' <span style="color:#dc2626">*</span>' : '';
        var half = f.width === 'half' ? 'display:inline-block;width:48%;vertical-align:top;margin-inline-end:2%' : '';
        var h = '<div class="fb-prev-field" style="' + half + '">';
        if (f.type === 'section') {
            return h + '<div style="font-size:13px;font-weight:800;border-bottom:2px solid #e2e8f0;padding-bottom:6px;margin:8px 0">' + esc(f.label) + '</div></div>';
        }
        if (f.type === 'html') {
            return h + '<p style="font-size:11.5px;color:#64748b;line-height:1.9">' + esc(f.text || '') + '</p></div>';
        }
        h += '<div class="fb-prev-label">' + esc(f.label) + req + '</div>';
        var common = 'class="fb-prev-input" placeholder="' + esc(f.placeholder || '') + '"';
        if (f.type === 'textarea') { h += '<textarea ' + common + ' rows="' + (f.rows || 4) + '"></textarea>'; }
        else if (f.type === 'select') {
            h += '<select ' + common.replace('placeholder', 'data-p') + '><option value="">انتخاب کنید...</option>' + (f.options || []).map(function (o) { return '<option>' + esc(o) + '</option>'; }).join('') + '</select>';
        }
        else if (f.type === 'radio') {
            h += '<div style="display:flex;flex-direction:column;gap:5px">' + (f.options || []).map(function (o) { return '<label style="display:flex;gap:7px;align-items:center;font-size:12px"><input type="radio" disabled> ' + esc(o) + '</label>'; }).join('') + '</div>';
        }
        else if (f.type === 'checkbox') {
            h += '<div style="display:flex;flex-direction:column;gap:5px">' + (f.options || []).map(function (o) { return '<label style="display:flex;gap:7px;align-items:center;font-size:12px"><input type="checkbox" disabled> ' + esc(o) + '</label>'; }).join('') + '</div>';
        }
        else if (f.type === 'consent') {
            h += '<label style="display:flex;gap:7px;font-size:11.5px;align-items:flex-start"><input type="checkbox" disabled style="margin-top:3px"> ' + esc(f.text || '') + req + '</label>';
        }
        else if (f.type === 'rating') {
            h += '<div style="font-size:21px;letter-spacing:3px">⭐⭐⭐⭐⭐</div>';
        }
        else if (f.type === 'file') {
            h += '<input type="file" class="fb-prev-input" disabled accept="image/*" multiple>';
        }
        else if (f.type === 'date') {
            h += '<input type="text" ' + common + ' placeholder="انتخاب تاریخ (شمسی)" disabled>';
        }
        else if (f.type === 'number') {
            h += '<input type="number" ' + common + ' disabled>';
        }
        else if (f.type === 'email') {
            h += '<input type="email" ' + common + ' dir="ltr" disabled>';
        }
        else if (f.type === 'tel') {
            h += '<input type="tel" ' + common + ' dir="ltr" disabled>';
        }
        else if (f.type === 'url') {
            h += '<input type="url" ' + common + ' dir="ltr" disabled>';
        }
        else if (f.type === 'hidden') {
            h = '<input type="hidden">';
        }
        else { h += '<input type="text" ' + common + ' disabled>'; }
        if (f.help) { h += '<div style="font-size:10px;color:#94a3b8;margin-top:4px">' + esc(f.help) + '</div>'; }
        return h + '</div>';
    }

    function renderPreview() {
        if (!preview) { return; }
        var title = document.querySelector('input[name="title"]').value || 'عنوان فرم';
        var desc = document.querySelector('input[name="description"]').value;
        var btn = document.querySelector('input[name="btn_text"]').value || 'ارسال';
        var h = '<b style="font-size:14px;display:block;margin-bottom:6px">' + esc(title) + '</b>';
        if (desc) { h += '<p style="font-size:11.5px;color:#64748b;margin-bottom:12px">' + esc(desc) + '</p>'; }
        h += '<div style="border:1px dashed #cbd5e1;border-radius:10px;padding:12px;background:#fafcff">';
        if (!fields.length) {
            h += '<div style="text-align:center;color:#94a3b8;font-size:11.5px;padding:16px 0">فیلدی اضافه نشده — از پالت بالا شروع کنید</div>';
        } else {
            fields.forEach(function (f) { h += prevFieldHtml(f); });
            h += '<button type="button" class="btn btn-primary" style="width:100%;margin-top:6px">' + esc(btn) + '</button>';
        }
        h += '</div>';
        preview.innerHTML = h;
    }
    ['title', 'description', 'btn_text'].forEach(function (n) {
        var el = document.querySelector('input[name="' + n + '"]');
        if (el) { el.addEventListener('input', renderPreview); }
    });

    render();
})();
</script>
<style>
@media (max-width: 1080px) { .fb-grid { grid-template-columns: 1fr !important; } }
</style>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
