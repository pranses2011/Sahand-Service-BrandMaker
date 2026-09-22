<?php
/**
 * 🔤 مدیریت فونت‌ها — ۲۰ فارسی + ۱۰ انگلیسی
 * ==========================================
 * نمایش وضعیت فایل‌ها + آپلود وزن‌های موجود + تنظیمات فونت برند
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();
$fm = new FileManager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::enforceCsrf();
    $action = post('action');

    /* 📤 آپلود فایل فونت */
    if ($action === 'upload_font') {
        $slug = post('font_slug');
        $type = post('font_type') === 'en' ? 'en' : 'fa';
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            flash('danger', 'اسلاگ فونت نامعتبر است.');
            redirect('fonts.php');
        }
        $fontDir = ASSETS_PATH . '/fonts/' . $type . '/' . $slug;
        $upload = $fm->uploadFont($_FILES['font_file'], $fontDir);
        if ($upload['success']) {
            // ثبت در دیتابیس در صورت نبود
            $exists = $db->fetchValue('SELECT COUNT(*) FROM fonts WHERE slug = ?', [$slug]);
            if (!$exists) {
                $db->insert('fonts', [
                    'name' => post('font_name') ?: $slug,
                    'slug' => $slug,
                    'type' => $type,
                    'is_builtin' => 0,
                ]);
            }
            flash('success', '✅ فایل فونت آپلود شد: ' . $upload['name']);
        } else {
            flash('danger', 'خطای آپلود: ' . $upload['error']);
        }
        redirect('fonts.php');
    }

    /* 💾 تنظیمات فونت پیش‌فرض سیستم */
    if ($action === 'save_defaults') {
        Config::set(Config::KEY_DEFAULT_FONT, [
            'heading_fa' => post('heading_fa'),
            'body_fa'    => post('body_fa'),
            'numeric'    => post('numeric'),
            'weight'     => post('weight'),
            'base_size'  => (int)post('base_size'),
            'line_height'=> (float)post('line_height'),
        ]);
        flash('success', '✅ تنظیمات فونت پیش‌فرض ذخیره شد.');
        redirect('fonts.php');
    }

    /* 🗑️ حذف فایل فونت */
    if ($action === 'delete_file') {
        $path = post('file_path');
        if (strpos($path, 'assets/fonts/') === 0 && $fm->deleteFile($path)) {
            flash('success', '🗑️ فایل فونت حذف شد.');
        } else {
            flash('danger', 'حذف فایل ناموفق بود.');
        }
        redirect('fonts.php');
    }
}

$pageTitle = 'مدیریت فونت‌ها';
$activeMenu = 'fonts';
require __DIR__ . '/includes/header.php';

/* 📚 مانیفست فونت‌ها */
$manifest = json_decode((string)file_get_contents(ASSETS_PATH . '/fonts/manifest.json'), true) ?: [];
$fontsList = $manifest['fonts'] ?? [];
$defaultFont = (array)(Config::get(Config::KEY_DEFAULT_FONT) ?: []);

$fontNames = [];
foreach ($fontsList as $type => $list) {
    foreach ($list as $font) {
        $fontNames[$font['name']] = $type;
    }
}

/* بررسی موجود بودن فایل‌ها */
function fontFilesStatus(string $type, string $slug, array $weights): array
{
    $dir = ASSETS_PATH . '/fonts/' . $type . '/' . $slug;
    $status = [];
    foreach ($weights as $weight) {
        $found = '';
        foreach (['woff2', 'ttf'] as $ext) {
            if (file_exists($dir . '/' . $slug . '-' . $weight . '.' . $ext)) {
                $found = $ext;
                break;
            }
        }
        $status[$weight] = $found;
    }
    return $status;
}
?>
<div class="card">
    <div class="card-header"><h3>⚙️ فونت پیش‌فرض برندهای جدید</h3></div>
    <div class="card-body">
        <form method="post">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="save_defaults">
            <div class="form-row-3">
                <div class="form-group">
                    <label>فونت عنوان (فارسی)</label>
                    <select name="heading_fa" class="form-control">
                        <?php foreach ($fontsList['fa'] ?? [] as $font): ?>
                            <option value="<?= e($font['name']) ?>" <?= ($defaultFont['heading_fa'] ?? '') === $font['name'] ? 'selected' : '' ?>><?= e($font['name']) ?> (<?= e($font['category']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>فونت متن (فارسی)</label>
                    <select name="body_fa" class="form-control">
                        <?php foreach ($fontsList['fa'] ?? [] as $font): ?>
                            <option value="<?= e($font['name']) ?>" <?= ($defaultFont['body_fa'] ?? 'وزیرمتن') === $font['name'] ? 'selected' : '' ?>><?= e($font['name']) ?> (<?= e($font['category']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>فونت اعداد / انگلیسی</label>
                    <select name="numeric" class="form-control">
                        <?php foreach ($fontsList['en'] ?? [] as $font): ?>
                            <option value="<?= e($font['name']) ?>" <?= ($defaultFont['numeric'] ?? '') === $font['name'] ? 'selected' : '' ?>><?= e($font['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row-3">
                <div class="form-group">
                    <label>وزن پیش‌فرض</label>
                    <select name="weight" class="form-control">
                        <?php foreach (['400' => 'معمولی (400)', '500' => 'متوسط (500)', '700' => 'بولد (700)'] as $w => $label): ?>
                            <option value="<?= $w ?>" <?= ($defaultFont['weight'] ?? '400') === $w ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>اندازه پایه (پیکسل)</label>
                    <input type="number" name="base_size" class="form-control" min="12" max="22" value="<?= e((string)($defaultFont['base_size'] ?? 16)) ?>">
                </div>
                <div class="form-group">
                    <label>ارتفاع خط (نسبت)</label>
                    <input type="number" step="0.1" name="line_height" class="form-control" min="1.2" max="2.5" value="<?= e((string)($defaultFont['line_height'] ?? 1.9)) ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">💾 ذخیره تنظیمات</button>
        </form>
    </div>
</div>

<?php foreach (['fa' => '🔤 فونت‌های فارسی', 'en' => '🔤 فونت‌های انگلیسی'] as $type => $title): ?>
<div class="card">
    <div class="card-header"><h3><?= $title ?> <span class="badge badge-info"><?= en_to_fa_digits((string)count($fontsList[$type] ?? [])) ?> فونت</span></h3></div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>فونت</th><th>کاربرد</th><th>وزن‌ها (وضعیت فایل)</th><th>آپلود</th></tr></thead>
            <tbody>
            <?php foreach ($fontsList[$type] ?? [] as $font): ?>
                <?php $status = fontFilesStatus($type, $font['slug'], $font['weights']); ?>
                <tr>
                    <td style="font-weight:700"><?= e($font['name']) ?><br><small style="color:var(--text-light);direction:ltr"><?= e($font['slug']) ?></small></td>
                    <td><span class="badge badge-secondary"><?= e($font['category']) ?></span></td>
                    <td>
                        <div style="display:flex;gap:5px;flex-wrap:wrap">
                            <?php foreach ($status as $weight => $ext): ?>
                                <span class="badge <?= $ext ? 'badge-success' : 'badge-secondary' ?>" title="<?= $ext ? "فایل {$ext} موجود" : 'فایل آپلود نشده' ?>"><?= e($weight) ?> <?= $ext ? '✓' : '' ?></span>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td>
                        <form method="post" enctype="multipart/form-data" style="display:flex;gap:6px;align-items:center">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="action" value="upload_font">
                            <input type="hidden" name="font_slug" value="<?= e($font['slug']) ?>">
                            <input type="hidden" name="font_type" value="<?= $type ?>">
                            <input type="hidden" name="font_name" value="<?= e($font['name']) ?>">
                            <input type="file" name="font_file" class="form-control" style="font-size:11px;padding:5px 9px;width:200px" accept=".woff2,.ttf,.woff,.otf" required>
                            <button type="submit" class="btn btn-outline btn-sm">📤</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<div class="alert alert-info">
    💡 تمام فونت‌ها روی <b>سرور سایت ساز</b> ذخیره و از آنجا به سایت‌های برند ارائه می‌شوند (بدون هیچ CDN خارجی).
    فایل‌های باینری فونت (woff2/ttf) به دلیل حجم، از طریق همین صفحه آپلود می‌شوند.
    تعریف <code>@font-face</code> همه ۳۰ فونت و ۱۳۴ وزن، از قبل در <code>assets/css/fonts.css</code> آماده است.
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
