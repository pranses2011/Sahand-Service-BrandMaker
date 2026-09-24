<?php
/**
 * 🔑 مدیریت کلیدهای API — v2.12
 * ==================================
 * ساخت/حذف/فعال‌سازی کلیدهای احراز هویت سایت‌های برند و کلیدهای خارجی
 * + مجوز استقرار (can_deploy) از پنل — دیگر نیازی به SQL نیست
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::enforceCsrf();
    $action = post('action');

    /* ➕ ساخت کلید جدید */
    if ($action === 'create_key') {
        $brandId = (int)post('brand_id') ?: null;
        $label = trim(post('label')) ?: null;
        $canDeploy = post('can_deploy') === '1' || post('can_deploy') === 'on';
        try {
            $key = 'sk_' . bin2hex(random_bytes(24)); // ۵۵ کاراکتر — در محدوده اعتبارسنجی API (10-70)
            $db->insert('api_keys', [
                'brand_id' => $brandId,
                'api_key'  => $key,
                'label'    => $label ? mb_substr($label, 0, 180) : null,
                'can_deploy' => $canDeploy ? 1 : 0,
                'is_active' => 1,
            ]);
            $target = $brandId ? 'برند «' . $db->fetchValue('SELECT name_fa FROM brands WHERE id = ?', [$brandId]) . '»' : 'کلید سیستمی';
            Logger::activity((int)$_SESSION['user_id'], 'ساخت کلید API', ($label ?: $key) . " — {$target}" . ($canDeploy ? ' + مجوز استقرار' : ''));
            flash('success', '✅ کلید جدید ساخته شد — مقدار آن را از ستون «کلید» با دکمه 📋 کپی کنید: ' . $key);
        } catch (Throwable $e) {
            flash('danger', 'خطای ساخت کلید: ' . $e->getMessage());
        }
        redirect('api-keys.php');
    }

    /* 🔄 تغییر وضعیت فعال/غیرفعال */
    if ($action === 'toggle_key') {
        $id = (int)post('key_id');
        $key = $db->fetch('SELECT * FROM api_keys WHERE id = ?', [$id]);
        if ($key) {
            $db->update('api_keys', ['is_active' => $key['is_active'] ? 0 : 1], 'id = ?', [$id]);
            flash('success', $key['is_active'] ? '🔴 کلید غیرفعال شد.' : '🟢 کلید فعال شد.');
        }
        redirect('api-keys.php');
    }

    /* 🚀 تغییر مجوز استقرار (can_deploy) */
    if ($action === 'toggle_deploy') {
        $id = (int)post('key_id');
        $key = $db->fetch('SELECT * FROM api_keys WHERE id = ?', [$id]);
        if ($key) {
            $newVal = $key['can_deploy'] ? 0 : 1;
            $db->update('api_keys', ['can_deploy' => $newVal], 'id = ?', [$id]);
            flash('success', $newVal
                ? '🚀 مجوز استقرار فعال شد — این کلید حالا می‌تواند از خارج سایت‌ساز عملیات استقرار/بکاپ/سلامت را فراخوانی کند.'
                : '🔒 مجوز استقرار غیرفعال شد.');
            Logger::activity((int)$_SESSION['user_id'], 'تغییر مجوز استقرار کلید', ($key['label'] ?: mb_substr($key['api_key'], 0, 12)) . ' → ' . ($newVal ? 'فعال' : 'غیرفعال'));
        }
        redirect('api-keys.php');
    }

    /* 🗑️ حذف کلید */
    if ($action === 'delete_key') {
        $id = (int)post('key_id');
        $key = $db->fetch('SELECT * FROM api_keys WHERE id = ?', [$id]);
        if ($key && post('confirm_delete') === '1') {
            $db->delete('api_keys', 'id = ?', [$id]);
            flash('success', '🗑️ کلید حذف شد — سایتی که از آن استفاده می‌کرد دیگر دسترسی ندارد.');
            Logger::activity((int)$_SESSION['user_id'], 'حذف کلید API', $key['label'] ?: mb_substr($key['api_key'], 0, 12));
        } else {
            flash('warning', 'برای حذف، تیک تأیید را بزنید.');
        }
        redirect('api-keys.php');
    }

    /* ✏️ بروزرسانی برچسب */
    if ($action === 'rename_key') {
        $id = (int)post('key_id');
        $label = trim(post('label'));
        if ($label !== '') {
            $db->update('api_keys', ['label' => mb_substr($label, 0, 180)], 'id = ?', [$id]);
            flash('success', '✏️ برچسب کلید بروزرسانی شد.');
        }
        redirect('api-keys.php');
    }
}

$pageTitle = 'مدیریت کلیدهای API';
$activeMenu = 'api-keys';
require __DIR__ . '/includes/header.php';

$brands = $db->fetchAll('SELECT id, name_fa, name_en FROM brands ORDER BY name_fa');
$keys = $db->fetchAll(
    'SELECT k.*, b.name_fa AS brand_name
     FROM api_keys k LEFT JOIN brands b ON b.id = k.brand_id
     ORDER BY k.brand_id IS NULL DESC, k.requests_count DESC, k.id DESC'
);
$totalRequests = 0;
foreach ($keys as $k) {
    $totalRequests += (int)$k['requests_count'];
}
?>

<div class="grid-2">
    <!-- ➕ ساخت کلید جدید -->
    <div class="card" style="border-color:var(--primary)">
        <div class="card-header"><h3>➕ ساخت کلید API جدید</h3></div>
        <div class="card-body">
            <form method="post">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="create_key">
                <div class="form-group">
                    <label>برچسب توضیحی (اختیاری)</label>
                    <input type="text" name="label" class="form-control" placeholder="مثلاً: سرور اتوماسیون خارجی / اپ موبایل">
                    <div class="hint">برای شناسایی بعدی — مثلاً «ربات پشتیبان‌گیری شبانه»</div>
                </div>
                <div class="form-group">
                    <label>برند مرتبط (اختیاری)</label>
                    <select name="brand_id" class="form-control">
                        <option value="0">🌐 کلید سیستمی (دسترسی کامل همه برندها)</option>
                        <?php foreach ($brands as $b): ?>
                            <option value="<?= (int)$b['id'] ?>">🏷️ <?= e($b['name_fa']) ?> (<?= e($b['name_en']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">کلید برند-محور فقط به داده‌های همان برند دسترسی دارد؛ کلید سیستمی به همه برندها.</div>
                </div>
                <label class="form-check" style="margin:12px 0">
                    <input type="checkbox" name="can_deploy" value="1">
                    🚀 مجوز استقرار (can_deploy) — اجازه فراخوانی اندپوینت‌های استقرار/بکاپ/سلامت از خارج سایت‌ساز
                </label>
                <button type="submit" class="btn btn-primary btn-block">🔑 ساخت کلید</button>
            </form>
            <div class="alert alert-info" style="font-size:12px;margin-top:14px">
                💡 کلید ساخته‌شده را در هدر <code dir="ltr">X-API-Key</code> درخواست‌های API قرار دهید.
                مجوز استقرار را فقط به کلیدهایی بدهید که واقعاً باید از بیرون سایت‌ساز عملیات استقرار انجام دهند.
            </div>
        </div>
    </div>

    <!-- 📊 خلاصه -->
    <div class="card">
        <div class="card-header"><h3>📊 خلاصه</h3></div>
        <div class="card-body">
            <div class="stats-row" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;text-align:center">
                <div style="background:var(--bg-secondary,#f1f5f9);border-radius:12px;padding:16px 8px">
                    <div style="font-size:24px;font-weight:800"><?= en_to_fa_digits((string)count($keys)) ?></div>
                    <div style="font-size:11.5px;color:var(--text-light)">کلید فعال و غیرفعال</div>
                </div>
                <div style="background:var(--bg-secondary,#f1f5f9);border-radius:12px;padding:16px 8px">
                    <div style="font-size:24px;font-weight:800"><?= en_to_fa_digits((string)count(array_filter($keys, fn($k) => (int)$k['can_deploy'] === 1))) ?></div>
                    <div style="font-size:11.5px;color:var(--text-light)">دارای مجوز استقرار</div>
                </div>
                <div style="background:var(--bg-secondary,#f1f5f9);border-radius:12px;padding:16px 8px">
                    <div style="font-size:24px;font-weight:800"><?= en_to_fa_digits((string)$totalRequests) ?></div>
                    <div style="font-size:11.5px;color:var(--text-light)">مجموع درخواست‌ها</div>
                </div>
            </div>
            <div class="hint" style="margin-top:12px">
                🔒 کلیدهای برند-محور هنگام ساخت برند به‌صورت خودکار ساخته می‌شوند («تب API» در ویرایش برند).
                این صفحه برای ساخت کلیدهای خارجی با مجوز استقرار (<code>can_deploy</code>) است — بدون نیاز به اجرای SQL.
            </div>
        </div>
    </div>
</div>

<!-- 📋 فهرست کلیدها -->
<div class="card" style="margin-top:16px">
    <div class="card-header"><h3>📋 کلیدهای API (<?= en_to_fa_digits((string)count($keys)) ?>)</h3></div>
    <div class="table-wrap">
        <?php if (empty($keys)): ?>
            <div class="empty-state"><div class="icon">🔑</div><p>هنوز کلیدی ساخته نشده است.</p></div>
        <?php else: ?>
        <table class="table">
            <thead>
            <tr><th>برچسب / برند</th><th>کلید</th><th>مجوز استقرار</th><th>وضعیت</th><th>استفاده</th><th>آخرین استفاده</th><th>عملیات</th></tr>
            </thead>
            <tbody>
            <?php foreach ($keys as $k): ?>
                <tr style="<?= $k['is_active'] ? '' : 'opacity:.55' ?>">
                    <td>
                        <form method="post" style="display:flex;gap:6px;align-items:center">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="action" value="rename_key">
                            <input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
                            <input type="text" name="label" class="form-control" style="width:150px;font-size:12px;padding:4px 8px" value="<?= e((string)$k['label']) ?>" placeholder="— بدون برچسب —">
                            <button type="submit" class="btn btn-outline btn-sm" title="ذخیره برچسب">💾</button>
                        </form>
                        <small style="color:var(--text-light)"><?= $k['brand_id'] ? '🏷️ ' . e((string)$k['brand_name']) : '🌐 سیستمی (همه برندها)' ?></small>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center">
                            <code dir="ltr" style="font-size:11px;font-family:var(--font-mono,monospace)"><?= e(mb_substr((string)$k['api_key'], 0, 14)) ?>…</code>
                            <button type="button" class="btn btn-outline btn-sm" onclick="copyText(<?= json_encode((string)$k['api_key']) ?>, this)" title="کپی کل کلید">📋</button>
                        </div>
                    </td>
                    <td>
                        <form method="post">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="action" value="toggle_deploy">
                            <input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
                            <button type="submit" class="btn btn-sm <?= $k['can_deploy'] ? 'btn-success' : 'btn-outline' ?>" title="کلیک برای تغییر">
                                <?= $k['can_deploy'] ? '🚀 فعال' : '🔒 غیرفعال' ?>
                            </button>
                        </form>
                    </td>
                    <td>
                        <form method="post">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="action" value="toggle_key">
                            <input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
                            <button type="submit" class="btn btn-sm <?= $k['is_active'] ? 'btn-success' : 'btn-warning' ?>" title="کلیک برای تغییر">
                                <?= $k['is_active'] ? '🟢 فعال' : '🔴 غیرفعال' ?>
                            </button>
                        </form>
                    </td>
                    <td><?= en_to_fa_digits((string)(int)$k['requests_count']) ?></td>
                    <td style="font-size:11.5px"><?= $k['last_used_at'] ? jdate((string)$k['last_used_at'], true) : '— هرگز' ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('این کلید برای همیشه حذف شود؟ سایتی که از آن استفاده می‌کند قطع می‌شود.')">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="action" value="delete_key">
                            <input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
                            <label class="form-check" style="font-size:10.5px;margin-bottom:4px"><input type="checkbox" name="confirm_delete" value="1"> تأیید حذف</label>
                            <button type="submit" class="btn btn-danger btn-sm">🗑️ حذف</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="hint" style="margin-top:12px">
    📚 راهنمای کامل اندپوینت‌های API و نحوه احراز هویت: <a href="https://github.com/pranses2011/Sahand-Service-BrandMaker/blob/main/docs/AI-API-GUIDE.md" target="_blank" rel="noopener">راهنمای API (AI-API-GUIDE)</a>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
