<?php
/**
 * ➕ افزودن برند جدید — فرم اولیه
 * =================================
 * ورودی: نام فارسی + انگلیسی + لوگو + دامنه
 * پس از ثبت، به فرآیند ساخت ۸ مرحله‌ای هدایت می‌شود.
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();
$fm = new FileManager();
$errors = [];

/* 💾 ایجاد برند */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::enforceCsrf();

    $nameFa = post('name_fa');
    $nameEn = post('name_en');
    $domainType = post('domain_type') === 'custom' ? 'custom' : 'subdomain';
    $domain = $domainType === 'custom' ? post('custom_domain') : SlugGenerator::brandDomain($nameEn);
    $errorCodesEnabled = !empty($_POST['error_codes_enabled']) ? 1 : 0;

    // ✅ اعتبارسنجی
    if ($nameFa === '') {
        $errors[] = 'نام فارسی برند الزامی است.';
    }
    if ($nameEn === '') {
        $errors[] = 'نام انگلیسی برند الزامی است (برای دامنه و سئو استفاده می‌شود).';
    }
    if ($domain === '') {
        $errors[] = 'آدرس دامنه تعیین نشده است.';
    }
    if ($db->fetchValue('SELECT COUNT(*) FROM brands WHERE domain = ?', [$domain])) {
        $errors[] = 'این دامنه قبلاً برای برند دیگری ثبت شده است.';
    }

    // 🖼️ آپلود لوگو
    $logoPath = '';
    if (!empty($_FILES['logo']['name'])) {
        $upload = $fm->uploadImage($_FILES['logo'], 'logos');
        if ($upload['success']) {
            $logoPath = $upload['path'];
        } else {
            $errors[] = 'خطای آپلود لوگو: ' . $upload['error'];
        }
    }

    if (empty($errors)) {
        $slug = SlugGenerator::unique($nameEn ?: $nameFa, 'brands');
        $apiKey = 'bmk_' . bin2hex(random_bytes(16));

        $brandId = $db->insert('brands', [
            'name_fa'            => $nameFa,
            'name_en'            => $nameEn,
            'slug'               => $slug,
            'logo'               => $logoPath ?: null,
            'domain'             => $domain,
            'domain_type'        => $domainType,
            'status'             => 'draft',
            'error_codes_enabled'=> $errorCodesEnabled,
            'is_active'          => 1,
            'api_key'            => $apiKey,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        // 🔑 ثبت کلید API برند
        $db->insert('api_keys', [
            'brand_id'   => $brandId,
            'api_key'    => $apiKey,
            'label'      => 'کلید سایت برند ' . $nameFa,
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Logger::activity((int)$_SESSION['user_id'], 'ایجاد برند جدید', $nameFa . ' (' . $nameEn . ')');
        flash('success', '✅ برند «' . $nameFa . '» ایجاد شد — فرآیند ساخت سایت آغاز می‌شود.');
        redirect('brand-build.php?id=' . $brandId);
    }
}

// 💡 پیشنهاد دامنه از نام انگلیسی
$suggestedDomain = SlugGenerator::brandDomain('brand');
?>
$pageTitle = 'افزودن برند جدید';
$activeMenu = 'brands';
require __DIR__ . '/includes/header.php';

?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">⚠️ <?php foreach ($errors as $err): ?><?= e($err) ?><br><?php endforeach; ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
    <?= Auth::csrfField() ?>

    <div class="grid-2">
        <div class="card">
            <div class="card-header"><h3>📇 اطلاعات برند</h3></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>نام برند (فارسی) <span class="req">*</span></label>
                        <input type="text" name="name_fa" class="form-control" required autofocus value="<?= e($_POST['name_fa'] ?? '') ?>" placeholder="مثلاً سامسونگ">
                    </div>
                    <div class="form-group">
                        <label>نام برند (انگلیسی) <span class="req">*</span></label>
                        <input type="text" name="name_en" class="form-control" required style="direction:ltr;text-align:left" value="<?= e($_POST['name_en'] ?? '') ?>" placeholder="Samsung" oninput="updateDomain(this.value)">
                        <div class="hint">برای دامنه و سئو استفاده می‌شود.</div>
                    </div>
                </div>
                <div class="form-group">
                    <label>🖼️ لوگوی برند <span class="req">*</span></label>
                    <input type="file" name="logo" class="form-control" required accept="image/png,image/svg+xml,image/jpeg,image/webp" onchange="previewImage(this, 'logo-preview')">
                    <div class="hint">پالت رنگ سایت به صورت خودکار از لوگو استخراج می‌شود — PNG/SVG با پس‌زمینه شفاف بهترین نتیجه را می‌دهد.</div>
                    <img id="logo-preview" src="" alt="" style="display:none;max-height:110px;margin-top:12px;border:1px solid var(--border);border-radius:12px;padding:8px">
                </div>
                <label class="form-check" style="margin-top:6px">
                    <input type="checkbox" name="error_codes_enabled" checked>
                    فعال بودن صفحه «کدهای خطای دستگاه‌ها» در سایت این برند
                </label>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3>🌐 آدرس دامنه</h3></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-check" style="margin-bottom:10px">
                        <input type="radio" name="domain_type" value="subdomain" checked onchange="document.getElementById('custom-domain-box').style.display='none'">
                        زیردامنه سایت اصلی (پیش‌فرض)
                    </label>
                    <div style="background:var(--bg);border-radius:10px;padding:12px 16px;font-size:13px">
                        <span style="direction:ltr;display:inline-block" id="domain-preview"><?= e($suggestedDomain) ?></span>
                        <div class="hint">فرمت قابل تغییر از تنظیمات → دامنه</div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-check" style="margin-bottom:10px">
                        <input type="radio" name="domain_type" value="custom" onchange="document.getElementById('custom-domain-box').style.display='block'">
                        دامنه اختصاصی
                    </label>
                    <div id="custom-domain-box" style="display:none">
                        <input type="text" name="custom_domain" class="form-control" style="direction:ltr;text-align:left" placeholder="https://mybrand.ir">
                    </div>
                </div>
                <div class="alert alert-info">
                    🤖 پس از ثبت، موتور هوش مصنوعی داخلی به صورت خودکار:
                    <ul style="margin:6px 18px 0 0;line-height:2.1">
                        <li>پالت رنگ را از لوگو استخراج می‌کند</li>
                        <li>برند را با پایگاه دانش ۵۷ برند تطبیق می‌دهد</li>
                        <li>تمام محتوای صفحات را یکتا تولید می‌کند</li>
                        <li>۵ مقاله اولیه + FAQ + کدهای خطا می‌سازد</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div style="text-align:center;padding:6px 0 20px">
        <button type="submit" class="btn btn-primary btn-lg">🚀 ایجاد برند و شروع ساخت سایت</button>
        <a href="brands.php" class="btn btn-outline btn-lg">انصراف</a>
    </div>
</form>

<script>
/* 🔄 بروزرسانی زنده پیش‌نمایش دامنه */
function updateDomain(nameEn) {
    var clean = nameEn.toLowerCase().replace(/[^a-z0-9-]/g, '');
    var format = '<?= e((string)Config::get('brand_domain_format', '{brand}.ea-fixer.ir')) ?>';
    document.getElementById('domain-preview').textContent = format.replace('{brand}', clean || 'brand');
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
