<?php
/**
 * ✏️ ویرایش نام زیردامنه (قبل از ساخت)
 * =====================================
 * طبق سند بخش ۲۱ (پرامپت تکمیلی بخش ۳):
 *   - نمایش نام فعلی + فیلد نام جدید
 *   - پیش‌نمایش کامل دامنه + مسیر Document Root جدید
 *   - اعتبارسنجی زنده ۸ قانون DNS + بررسی تکرار (دیتابیس + cPanel)
 *   - پیشنهاد ۳ نام جایگزین در صورت تکراری بودن
 *   - ذخیره در ستون‌های brands (subdomain_name / full_domain / server_path / custom_subdomain)
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();
$brandId = (int)get_param('brand_id');
$brand = $db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);

if (!$brand) {
    flash('danger', 'برند یافت نشد.');
    redirect('deploy.php');
}

/* ⚠️ بعد از استقرار، تغییر نام = حذف + ساخت مجدد */
if (!empty($brand['is_deployed'])) {
    flash('warning', 'این برند استقرار دارد — برای تغییر نام باید ابتدا سایت را حذف و مجدداً مستقر کنید.');
    redirect('deploy.php?brand_id=' . $brandId);
}

$settings = PathResolver::getSettings();
$rootDomain = (string)($settings['root_domain'] ?? '');
$subManager = new SubdomainManager();
$currentName = $subManager->previewSubdomain($brand);
$serverPath = PathResolver::resolveDocumentRoot($brand);

/* 💾 اعمال تغییر نام */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_subdomain') {
    Auth::enforceCsrf();
    $newName = strtolower(trim(post('subdomain_name')));

    if ($rootDomain === '') {
        flash('danger', 'دامنه اصلی در تنظیمات cPanel ثبت نشده است.');
        redirect('subdomain-editor.php?brand_id=' . $brandId);
    }

    $check = $subManager->validateSubdomainName($newName, $rootDomain, $brandId);
    if (!$check['valid']) {
        flash('danger', $check['error']);
        redirect('subdomain-editor.php?brand_id=' . $brandId);
    }

    // مسیر جدید بر اساس نام جدید (الگو با brand_slug جایگزین می‌شود — نام سفارشی در مسیر هم اعمال شود)
    $brand['slug'] = $newName; // برای resolve با نام جدید
    $newPath = PathResolver::resolveDocumentRoot($brand);

    $db->update('brands', [
        'subdomain_name'   => $newName,
        'full_domain'      => $newName . '.' . $rootDomain,
        'server_path'      => $newPath,
        'custom_subdomain' => 1,
    ], 'id = ?', [$brandId]);

    Logger::activity((int)$_SESSION['user_id'], 'ویرایش نام زیردامنه', $brand['name_fa'] . ': ' . $currentName . ' ← ' . $newName);
    flash('success', '✅ نام زیردامنه به «' . $newName . '.' . $rootDomain . '» تغییر یافت.');
    redirect('deploy.php?brand_id=' . $brandId);
}

/* 🔍 اعتبارسنجی AJAX */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'validate') {
    Auth::enforceCsrf();
    $name = strtolower(trim((string)($_POST['name'] ?? '')));
    $result = $subManager->checkAvailability($name, $rootDomain, $brandId);
    // پیش‌نمایش مسیر با نام جدید
    $brand['slug'] = $name;
    $result['server_path'] = PathResolver::resolveDocumentRoot($brand);
    json_response($result);
}

$pageTitle = 'ویرایش نام زیردامنه';
$activeMenu = 'deploy';
require __DIR__ . '/includes/header.php';
$csrf = e($_SESSION['csrf_token'] ?? '');
?>

<div class="card" style="max-width:640px;margin:0 auto">
    <div class="card-header">
        <h3>✏️ ویرایش نام زیردامنه — <?= e($brand['name_fa']) ?></h3>
        <a href="deploy.php?brand_id=<?= $brandId ?>" class="btn btn-outline">← بازگشت</a>
    </div>
    <form method="post" onsubmit="return validateBeforeSubmit()">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="save_subdomain">
        <div class="card-body">
            <div class="form-group">
                <label>نام فعلی:</label>
                <input type="text" class="form-control" dir="ltr" value="<?= e($currentName . '.' . $rootDomain) ?>" disabled>
            </div>

            <div class="form-group">
                <label>نام جدید:</label>
                <div style="display:flex;align-items:center;gap:8px">
                    <input type="text" name="subdomain_name" id="sub-name" class="form-control" dir="ltr"
                           value="<?= e($currentName) ?>" oninput="liveValidate()" autocomplete="off" required>
                    <span style="color:var(--text-light);direction:ltr">. <?= e($rootDomain) ?></span>
                </div>
            </div>

            <div class="form-group">
                <label>🌐 پیش‌نمایش کامل:</label>
                <div><code dir="ltr" id="pv-url" style="font-size:14px">https://<?= e($currentName . '.' . $rootDomain) ?></code></div>
            </div>

            <div class="form-group">
                <label>📂 مسیر جدید Document Root:</label>
                <div><code dir="ltr" id="pv-path" style="font-size:13px"><?= e($serverPath) ?></code></div>
            </div>

            <div id="validation-msg" style="min-height:24px;margin:8px 0;font-size:13px"></div>
            <div id="suggestions" style="display:none;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:10px;margin:8px 0"></div>

            <div class="alert alert-warning" style="font-size:12.5px">
                ⚠️ توجه: پس از ساخت زیردامنه، تغییر نام دشوار خواهد بود.<br>
                قوانین: فقط حروف انگلیسی کوچک، اعداد و خط تیره — طول ۲ تا ۶۳ کاراکتر — بدون کلمات رزروشده (www، mail، admin و ...)
            </div>
        </div>
        <div style="display:flex;gap:10px;padding:14px 20px;border-top:1px solid var(--border)">
            <a href="deploy.php?brand_id=<?= $brandId ?>" class="btn btn-outline">❌ انصراف</a>
            <button type="submit" class="btn btn-primary" id="submit-btn">💾 اعمال تغییر</button>
        </div>
    </form>
</div>

<script>
const CSRF = '<?= $csrf ?>';
const ROOT = '<?= e($rootDomain) ?>';
let isValidNow = true;

let timer = null;
function liveValidate() {
    const name = document.getElementById('sub-name').value.trim();
    document.getElementById('pv-url').textContent = 'https://' + name + '.' + ROOT;
    clearTimeout(timer);
    timer = setTimeout(async () => {
        if (name.length < 2) return;
        const form = new FormData();
        form.append('action', 'validate');
        form.append('name', name);
        form.append('csrf_token', CSRF);
        const body = await (await fetch('subdomain-editor.php?brand_id=<?= $brandId ?>', {method: 'POST', body: form})).json();

        const msg = document.getElementById('validation-msg');
        const sug = document.getElementById('suggestions');
        if (body.available) {
            isValidNow = true;
            msg.innerHTML = '✅ نام معتبر و آزاد است';
            msg.style.color = '#16a34a';
            sug.style.display = 'none';
            document.getElementById('pv-path').textContent = body.server_path;
        } else {
            isValidNow = false;
            msg.innerHTML = '❌ ' + (body.error || 'نام نامعتبر است');
            msg.style.color = '#dc2626';
            if (body.suggestions && body.suggestions.length) {
                sug.innerHTML = '💡 پیشنهادات جایگزین: ' + body.suggestions
                    .map(s => `<span class="sug" onclick="pick('${s}')" style="display:inline-block;margin:4px;padding:6px 14px;background:#fff;border:1px solid var(--border);border-radius:20px;cursor:pointer">${s}</span>`).join('');
                sug.style.display = 'block';
            }
        }
    }, 400);
}

function pick(name) {
    document.getElementById('sub-name').value = name;
    liveValidate();
}

function validateBeforeSubmit() {
    if (!isValidNow) { alert('❌ نام زیردامنه معتبر نیست — ابتدا خطا را برطرف کنید.'); return false; }
    return confirm('نام زیردامنه اعمال شود؟');
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
