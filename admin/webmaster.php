<?php
/**
 * 🔗 مدیریت تگ‌های وبمستر
 * =========================
 * سرویس‌ها: Google, Bing, Yandex, Pinterest, Alexa + سفارشی
 * تگ‌های فعال هنگام تولید سایت برند در head قرار می‌گیرند.
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$pageTitle = 'تگ‌های وبمستر';
$activeMenu = 'webmaster';
$db = Database::getInstance();

/* 💾 ذخیره تگ‌ها — قبل از هدر (الگوی PRG — جلوگیری از شکست redirect) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_webmaster') {
    Auth::enforceCsrf();
    $ids = (array)($_POST['tag_id'] ?? []);
    $contents = (array)($_POST['tag_content'] ?? []);
    $actives = (array)($_POST['tag_active'] ?? []);

    foreach ($ids as $i => $id) {
        $content = clean_input($contents[$i] ?? '');
        $isActive = in_array($id, $actives, true) ? 1 : 0;
        $db->update('webmaster_tags', [
            'content' => $content !== '' ? $content : null,
            'is_active' => $isActive,
        ], 'id = ?', [(int)$id]);
    }

    // ➕ افزودن سرویس سفارشی
    if (post('custom_name') !== '' && post('custom_meta') !== '') {
        $exists = $db->fetchValue('SELECT COUNT(*) FROM webmaster_tags WHERE service_key = ?', [SlugGenerator::generate(post('custom_name'), true)]);
        if (!$exists) {
            $db->insert('webmaster_tags', [
                'service_key'  => SlugGenerator::generate(post('custom_name'), true),
                'service_name' => post('custom_name'),
                'meta_name'    => post('custom_meta'),
                'content'      => post('custom_content') ?: null,
                'is_active'    => !empty($_POST['custom_active']) ? 1 : 0,
            ]);
        }
    }

    Logger::activity((int)$_SESSION['user_id'], 'بروزرسانی تگ‌های وبمستر');
    flash('success', '✅ تگ‌های وبمستر ذخیره شدند.');
    redirect('webmaster.php');
}

require __DIR__ . '/includes/header.php';

$tags = $db->fetchAll('SELECT * FROM webmaster_tags ORDER BY id');
?>
<form method="post">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="save_webmaster">

    <div class="card">
        <div class="card-header">
            <h3>🔗 تگ‌های تأیید وبمستر</h3>
            <div class="tools"><button type="submit" class="btn btn-primary btn-sm">💾 ذخیره</button></div>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                💡 این تگ‌ها هنگام تولید سایت هر برند، به صورت خودکار در بخش <code>head</code> تمام صفحات قرار می‌گیرند.
                فقط تگ‌هایی که هم «کد تأیید» داشته باشند و هم «فعال» باشند اعمال می‌شوند.
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr><th>سرویس</th><th>نام متاتگ</th><th>کد تأیید (Content)</th><th>فعال</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tags as $tag): ?>
                        <tr>
                            <td style="font-weight:700;white-space:nowrap"><?= e($tag['service_name']) ?></td>
                            <td><code style="direction:ltr;display:inline-block"><?= e($tag['meta_name']) ?></code></td>
                            <td>
                                <input type="hidden" name="tag_id[]" value="<?= (int)$tag['id'] ?>">
                                <input type="text" name="tag_content[]" class="form-control" style="direction:ltr;text-align:left" value="<?= e($tag['content'] ?? '') ?>" placeholder="کد تأیید را از پنل سرویس کپی کنید">
                            </td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" name="tag_active[]" value="<?= (int)$tag['id'] ?>" <?= $tag['is_active'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>➕ افزودن سرویس سفارشی</h3></div>
        <div class="card-body">
            <div class="form-row-3">
                <div class="form-group">
                    <label>نام سرویس</label>
                    <input type="text" name="custom_name" class="form-control" placeholder="مثلاً Webmaster Tools X">
                </div>
                <div class="form-group">
                    <label>نام متاتگ</label>
                    <input type="text" name="custom_meta" class="form-control" style="direction:ltr;text-align:left" placeholder="x-site-verification">
                </div>
                <div class="form-group">
                    <label>کد تأیید</label>
                    <input type="text" name="custom_content" class="form-control" style="direction:ltr;text-align:left">
                </div>
            </div>
            <label class="form-check"><input type="checkbox" name="custom_active" checked> فعال باشد</label>
        </div>
    </div>

    <div style="text-align:center;padding:0 0 20px">
        <button type="submit" class="btn btn-primary btn-lg">💾 ذخیره تگ‌ها</button>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
