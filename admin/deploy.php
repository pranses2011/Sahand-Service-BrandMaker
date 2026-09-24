<?php
/**
 * 🚀 استقرار خودکار سایت‌های برند
 * ================================
 * طبق سند بخش ۲۱ — صفحه اصلی افزونه:
 *   - لیست برندها با وضعیت استقرار (🟢 آنلاین / 🔴 آفلاین / ⚪ بدون استقرار)
 *   - دکمه 🚀 استقرار / 🔄 بروزرسانی / 📊 وضعیت برای هر برند
 *   - دیالوگ پیش‌نمایش استقرار با ویرایش نام زیردامنه (پرامپت تکمیلی بخش ۳)
 *   - نوار پیشرفت زیبا با متون وضعیت فارسی (مرحله‌به‌مرحله AJAX)
 *   - بروزرسانی دسته‌ای با چک‌باکس (پرامپت اصلی بخش ۱۱)
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();
$settings = PathResolver::getSettings();
$deployEnabled = !empty($settings['deploy_enabled']);

/* 🗑️ حذف سایت برند (با تأیید تایپ نام برند — طبق سند بخش ۱۱) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_deploy') {
    Auth::enforceCsrf();
    $brandId = (int)post('brand_id');
    $confirmName = trim(post('confirm_name'));
    $brand = $db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);

    if (!$brand) {
        flash('danger', 'برند یافت نشد.');
        redirect('deploy.php');
    }
    // تأیید: تایپ نام انگلیسی برند
    if (strtolower($confirmName) !== strtolower((string)$brand['name_en'])) {
        flash('danger', 'برای تأیید حذف، نام انگلیسی برند («' . $brand['name_en'] . '») را دقیق وارد کنید.');
        redirect('deploy.php?brand_id=' . $brandId);
    }
    if (empty($brand['is_deployed'])) {
        flash('warning', 'این برند استقرار خودکار ندارد.');
        redirect('deploy.php?brand_id=' . $brandId);
    }

    $deployer = new Deployer();
    $result = $deployer->queueDelete($brandId, 'panel');
    flash($result['success'] ? 'success' : 'danger',
        $result['success'] ? '🗑️ حذف در صف قرار گرفت — مراحل را دنبال کنید.' : $result['message']);
    redirect('deploy.php?brand_id=' . $brandId . '&deployment=' . ($result['deployment_id'] ?? ''));
}

$pageTitle = 'استقرار خودکار';
$activeMenu = 'deploy';
require __DIR__ . '/includes/header.php';

$brandId = (int)get_param('brand_id');
$openDeployment = (int)get_param('deployment');

/* 📋 داده لیست برندها + آخرین عملیات */
$brands = $db->fetchAll(
    "SELECT b.id, b.name_fa, b.name_en, b.slug, b.logo, b.status, b.is_deployed, b.deployed_at,
            b.full_domain, b.server_path, b.ssl_status, b.ssl_expiry, b.health_status,
            (SELECT d.id FROM deployments d WHERE d.brand_id = b.id AND d.status IN ('pending','in_progress') ORDER BY d.id DESC LIMIT 1) AS active_deployment
     FROM brands b
     WHERE b.is_active = 1 AND b.status != 'draft'
     ORDER BY b.is_deployed DESC, b.name_fa"
);

/* 🔍 وضعیت عملیات فعال هر برند */
$activeDeployments = [];
foreach ($brands as $b) {
    if (!empty($b['active_deployment'])) {
        $activeDeployments[(int)$b['id']] = (int)$b['active_deployment'];
    }
}

/* 👁️ برند انتخاب‌شده برای دیالوگ */
$selectedBrand = null;
if ($brandId > 0) {
    foreach ($brands as $b) {
        if ((int)$b['id'] === $brandId) { $selectedBrand = $b; break; }
    }
    if (!$selectedBrand) {
        $selectedBrand = $db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
    }
}

$logger = new DeploymentLogger();
$recentOps = $logger->getRecent(8);
$csrf = e($_SESSION['csrf_token'] ?? '');
?>

<?php if (!$deployEnabled): ?>
    <div class="alert alert-warning" style="margin-bottom:16px">
        ⚠️ <b>استقرار خودکار غیرفعال است.</b> برای فعال‌سازی، ابتدا تنظیمات اتصال cPanel را کامل کنید:
        <a href="cpanel-settings.php" class="btn btn-outline" style="margin-inline-start:8px">⚙️ تنظیمات cPanel</a>
    </div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
    <div class="card-header">
        <h3>🚀 استقرار خودکار سایت‌ها (<?= en_to_fa_digits((string)count($brands)) ?> برند)</h3>
        <div class="tools" style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="cpanel-settings.php" class="btn btn-outline">⚙️ اتصال cPanel</a>
            <a href="health-dashboard.php" class="btn btn-outline">📊 سلامت سایت‌ها</a>
            <a href="backups.php" class="btn btn-outline">💾 بکاپ‌ها</a>
            <button type="button" class="btn btn-primary" onclick="batchUpdate()">🔄 بروزرسانی دسته‌ای انتخاب‌شده‌ها</button>
        </div>
    </div>
    <div class="table-wrap">
        <?php if (empty($brands)): ?>
            <div class="empty-state">
                <div class="icon">🚀</div>
                <p>هنوز برند ساخته‌شده‌ای وجود ندارد.<br><small>ابتدا از بخش «برندها» سایت برند بسازید.</small></p>
                <a href="brand-new.php" class="btn btn-primary">➕ ساخت اولین سایت برند</a>
            </div>
        <?php else: ?>
        <table class="table" id="brands-table">
            <thead>
            <tr>
                <th style="width:36px"><input type="checkbox" onclick="toggleAll(this)" title="انتخاب همه"></th>
                <th>برند</th>
                <th>دامنه</th>
                <th>وضعیت استقرار</th>
                <th>SSL</th>
                <th>آخرین استقرار</th>
                <th>عملیات</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($brands as $b):
                $isDeployed = !empty($b['is_deployed']);
                $hasActive = !empty($b['active_deployment']);
                $health = (string)($b['health_status'] ?? '');
                $statusInfo = $hasActive
                    ? ['🟡 در حال عملیات', 'badge-warning']
                    : ($isDeployed
                        ? ($health === 'online' ? ['🟢 آنلاین', 'badge-success'] : ($health === 'offline' ? ['🔴 آفلاین', 'badge-danger'] : ['🟢 مستقر', 'badge-success']))
                        : ['⚪ بدون استقرار', 'badge-secondary']);
                $sslInfo = $b['ssl_status'] === 'active' ? ['🔒 فعال', 'badge-success'] : ($b['ssl_status'] === 'pending' ? ['⏳ در حال صدور', 'badge-warning'] : ['—', 'badge-secondary']);
            ?>
            <tr data-brand-id="<?= (int)$b['id'] ?>">
                <td><input type="checkbox" class="brand-check" value="<?= (int)$b['id'] ?>" <?= $isDeployed ? '' : 'disabled title="بدون استقرار"' ?>></td>
                <td>
                    <div style="display:flex;align-items:center;gap:8px">
                        <?php if ($b['logo']): ?><img src="<?= asset_url((string)$b['logo']) ?>" style="width:28px;height:28px;border-radius:6px;object-fit:contain" alt=""><?php endif; ?>
                        <div><b><?= e($b['name_fa']) ?></b><br><small dir="ltr" style="color:var(--text-light)"><?= e($b['name_en']) ?></small></div>
                    </div>
                </td>
                <td dir="ltr" style="font-size:12.5px"><?= $isDeployed ? e((string)$b['full_domain']) : '—' ?></td>
                <td><span class="badge <?= $statusInfo[1] ?>"><?= $statusInfo[0] ?></span></td>
                <td><span class="badge <?= $sslInfo[1] ?>"><?= $sslInfo[0] ?></span></td>
                <td style="font-size:12px"><?= $b['deployed_at'] ? jdate((string)$b['deployed_at'], true) : '—' ?></td>
                <td>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <?php if ($hasActive): ?>
                            <button class="btn btn-warning btn-sm" onclick="resumeProgress(<?= (int)$b['active_deployment'] ?>, <?= (int)$b['id'] ?>)">⏳ دنبال کردن</button>
                        <?php elseif ($isDeployed): ?>
                            <button class="btn btn-outline btn-sm" onclick="openDeployDialog(<?= (int)$b['id'] ?>, true)">🔄 بروزرسانی</button>
                            <button class="btn btn-outline btn-sm" onclick="location.href='health-dashboard.php?brand_id=<?= (int)$b['id'] ?>'">📊 وضعیت</button>
                            <button class="btn btn-danger btn-sm" onclick="openDeleteDialog(<?= (int)$b['id'] ?>, '<?= e(addslashes((string)$b['name_en'])) ?>')">🗑️ حذف</button>
                        <?php else: ?>
                            <button class="btn btn-primary btn-sm" onclick="openDeployDialog(<?= (int)$b['id'] ?>, false)">🚀 استقرار خودکار</button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- 📋 آخرین عملیات -->
<div class="card">
    <div class="card-header"><h3>📋 آخرین عملیات استقرار</h3></div>
    <div class="table-wrap">
        <?php if (empty($recentOps)): ?>
            <div class="empty-state" style="padding:20px"><p>هنوز عملیاتی ثبت نشده است.</p></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>برند</th><th>عملیات</th><th>وضعیت</th><th>مدت</th><th>زمان</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recentOps as $op): [$label, $badge] = DeploymentLogger::statusBadge((string)$op['status']); ?>
            <tr>
                <td><?= e((string)$op['brand_name']) ?></td>
                <td><?= DeploymentLogger::actionLabel((string)$op['action']) ?></td>
                <td><span class="badge <?= $badge ?>"><?= $label ?></span></td>
                <td><?= $op['duration_seconds'] ? en_to_fa_digits((string)$op['duration_seconds']) . ' ثانیه' : '—' ?></td>
                <td style="font-size:12px"><?= jdate((string)$op['started_at'], true) ?></td>
                <td>
                    <button class="btn btn-outline btn-sm" onclick="resumeProgress(<?= (int)$op['id'] ?>, <?= (int)$op['brand_id'] ?>)">👁️ جزئیات</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════ دیالوگ پیش‌نمایش استقرار ═══════════════ -->
<div class="modal-overlay" id="deploy-dialog" style="display:none">
    <div class="modal-box deploy-modal">
        <div class="modal-header">
            <h3 id="deploy-dialog-title">🚀 پیش‌نمایش استقرار خودکار</h3>
            <button type="button" class="modal-close" onclick="closeDeployDialog()">✕</button>
        </div>
        <div class="modal-body" id="deploy-dialog-body">
            <!-- 👁️ فرم پیش‌نمایش (با JS پر می‌شود) -->
            <div id="preview-section">
                <div class="preview-row">
                    <span class="preview-label">🏷️ برند:</span>
                    <span id="pv-brand">—</span>
                </div>
                <!-- 🌐 v2.12: انتخاب نوع دامنه — زیردامنه / دامنه الحاقی -->
                <div class="preview-row" id="pv-domain-type-row">
                    <span class="preview-label">🌐 نوع دامنه:</span>
                    <div style="display:flex;gap:14px;flex-wrap:wrap">
                        <label style="display:inline-flex;gap:6px;align-items:center;cursor:pointer">
                            <input type="radio" name="pv-domain-type" value="subdomain" checked onchange="switchDomainType()">
                            <b>زیردامنه</b> <small style="color:var(--text-light)">(رایگان — روی دامنه اصلی)</small>
                        </label>
                        <label style="display:inline-flex;gap:6px;align-items:center;cursor:pointer">
                            <input type="radio" name="pv-domain-type" value="addon" onchange="switchDomainType()">
                            <b>دامنه الحاقی</b> <small style="color:var(--text-light)">(Addon — دامنه مستقل شما)</small>
                        </label>
                    </div>
                </div>
                <div class="preview-row" id="pv-subdomain-row">
                    <span class="preview-label">🌐 نام زیردامنه:</span>
                    <div class="subdomain-edit">
                        <input type="text" id="pv-subdomain" dir="ltr" oninput="liveValidateSubdomain()" autocomplete="off">
                        <span class="root-domain">. <span id="pv-root-domain">—</span></span>
                    </div>
                </div>
                <div class="preview-row" id="pv-addon-row" style="display:none">
                    <span class="preview-label">🔗 دامنه الحاقی کامل:</span>
                    <div class="subdomain-edit">
                        <input type="text" id="pv-addon-domain" dir="ltr" placeholder="mybrand.ir" oninput="liveValidateSubdomain()" autocomplete="off" style="flex:1">
                    </div>
                    <div class="hint" style="width:100%;font-size:11.5px">⚠️ دامنه باید قبل از استقرار به سرور همین هاست اشاره (Point) شود — رکورد A یا NS در پنل ثبت‌کننده دامنه.</div>
                </div>
                <div class="preview-row">
                    <span class="preview-label">🔗 پیش‌نمایش کامل:</span>
                    <code dir="ltr" id="pv-full-url">—</code>
                </div>
                <div class="preview-row">
                    <span class="preview-label">📂 مسیر Document Root:</span>
                    <code dir="ltr" id="pv-server-path">—</code>
                </div>
                <div class="preview-row">
                    <span class="preview-label">🔒 نصب SSL:</span>
                    <label style="display:inline-flex;gap:6px;align-items:center">
                        <input type="checkbox" id="pv-ssl" checked> فعال
                    </label>
                </div>
                <div class="preview-row">
                    <span class="preview-label">💾 بکاپ قبل از استقرار:</span>
                    <span id="pv-backup-note">➖ (نصب اولیه)</span>
                </div>
                <div id="pv-validation" class="validation-msg"></div>
                <div id="pv-suggestions" class="suggestions-box" style="display:none"></div>
                <div class="alert alert-info" style="margin-top:10px;font-size:12.5px">
                    ⚠️ پس از ساخت زیردامنه، تغییر نام آن دشوار خواهد بود.
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeDeployDialog()">❌ لغو</button>
            <button type="button" class="btn btn-primary" id="pv-start-btn" onclick="startDeployment()">✅ تأیید و شروع</button>
        </div>
    </div>
</div>

<!-- ═══════════════ دیالوگ حذف سایت ═══════════════ -->
<div class="modal-overlay" id="delete-dialog" style="display:none">
    <div class="modal-box" style="max-width:460px">
        <div class="modal-header"><h3>🗑️ حذف سایت برند</h3><button type="button" class="modal-close" onclick="document.getElementById('delete-dialog').style.display='none'">✕</button></div>
        <form method="post" data-confirm="⚠️ حذف کامل: فایل‌ها + زیردامنه + بکاپ نهایی. مطمئنید؟">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="delete_deploy">
            <input type="hidden" name="brand_id" id="del-brand-id" value="">
            <div class="modal-body">
                <p>این عملیات <b>بکاپ نهایی</b> می‌گیرد، سپس <b>فایل‌ها و زیردامنه</b> را حذف می‌کند.</p>
                <p>برای تأیید، نام انگلیسی برند را دقیق تایپ کنید:</p>
                <p dir="ltr" style="text-align:center;font-weight:bold;background:#f1f5f9;padding:8px;border-radius:8px" id="del-brand-name">—</p>
                <input type="text" name="confirm_name" class="form-control" dir="ltr" placeholder="نام انگلیسی برند" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('delete-dialog').style.display='none'">انصراف</button>
                <button type="submit" class="btn btn-danger">🗑️ حذف کامل</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════ پنل پیشرفت استقرار ═══════════════ -->
<div class="modal-overlay" id="progress-dialog" style="display:none">
    <div class="modal-box deploy-modal">
        <div class="modal-header">
            <h3 id="pr-title">⏳ عملیات در حال اجرا...</h3>
            <button type="button" class="modal-close" onclick="minimizeProgress()" title="کوچک‌سازی">—</button>
        </div>
        <div class="modal-body">
            <div class="progress" style="height:22px;margin-bottom:12px">
                <div class="bar" id="pr-bar" style="width:0%;transition:width .5s ease">
                    <span id="pr-percent">۰٪</span>
                </div>
            </div>
            <div class="pr-current" id="pr-current">آماده‌سازی...</div>
            <div class="pr-steps" id="pr-steps"></div>
            <div class="pr-error" id="pr-error" style="display:none"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="pr-cancel-btn" onclick="cancelDeployment()">🛑 لغو عملیات</button>
            <button type="button" class="btn btn-primary" id="pr-close-btn" style="display:none" onclick="closeProgress()">بستن</button>
        </div>
    </div>
</div>

<style>
.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;z-index:1000;padding:16px}
.modal-box{background:var(--card-bg,#fff);border-radius:14px;max-width:560px;width:100%;max-height:92vh;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,.25)}
.modal-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border)}
.modal-header h3{margin:0;font-size:16px}
.modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:var(--text-light);padding:4px 8px;border-radius:6px}
.modal-close:hover{background:var(--bg-secondary,#f1f5f9)}
.modal-body{padding:20px}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;padding:14px 20px;border-top:1px solid var(--border)}
.deploy-modal{max-width:620px}
.preview-row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px dashed var(--border);flex-wrap:wrap}
.preview-label{min-width:170px;color:var(--text-light);font-size:13px}
.subdomain-edit{display:flex;align-items:center;gap:6px;flex:1;min-width:220px}
.subdomain-edit input{flex:1;min-width:120px}
.root-domain{color:var(--text-light);direction:ltr}
.validation-msg{margin-top:10px;font-size:13px;min-height:20px}
.suggestions-box{margin-top:8px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:10px}
.suggestions-box .sug-item{display:inline-flex;gap:6px;margin:4px;padding:6px 12px;background:#fff;border:1px solid var(--border);border-radius:20px;cursor:pointer;font-size:13px}
.suggestions-box .sug-item:hover{border-color:var(--primary,#2563eb);color:var(--primary,#2563eb)}
.pr-current{font-weight:bold;margin-bottom:12px;font-size:14.5px}
.pr-steps{max-height:260px;overflow:auto;font-size:12.5px;line-height:2}
.pr-step{display:flex;gap:8px;align-items:flex-start;padding:4px 0;border-bottom:1px dashed var(--border)}
.pr-step .st-ico{width:20px;text-align:center}
.pr-error{margin-top:12px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:12px;font-size:13px}
.progress .bar span{font-size:12px;font-weight:bold}
.btn-sm{padding:4px 10px;font-size:12px}
</style>

<script>
/* 🗝️ توکن CSRF */
const CSRF = '<?= $csrf ?>';
let currentBrandId = 0;
let currentDeploymentId = <?= $openDeployment ?: '0' ?>;
let updateMode = false;
let stepTimer = null;
let running = false;

/* ═══════════ دیالوگ پیش‌نمایش استقرار ═══════════ */
async function openDeployDialog(brandId, isUpdate) {
    if (!<?= $deployEnabled ? 'true' : 'false' ?>) {
        sahandAlert({ title: 'استقرار غیرفعال', message: 'استقرار خودکار فعال نیست — ابتدا از «تنظیمات cPanel» فعال کنید.', type: 'warning', icon: '🚀' });
        return;
    }
    currentBrandId = brandId;

    const body = await api({action: 'preview', brand_id: brandId});
    if (!body.success) { sahandAlert({ title: 'خطا', message: errText(body), type: 'danger' }); return; }

    /* 🛡️ v2.14: حالت عملیات از پاسخ زنده سرور (preview) تعیین می‌شود —
       نه از پارامتر فراخوانی‌کننده؛ اگر پرچم is_deployed قدیمی/ناسازگار
       بود، دیالوگ حالت اشتباه («بروزرسانی») نشان می‌داد و شروع با خطای
       «این برند هنوز استقرار خودکار ندارد» رد می‌شد. */
    updateMode = !!body.is_deployed;

    document.getElementById('deploy-dialog-title').textContent = body.is_deployed ? '🔄 بروزرسانی خودکار سایت' : '🚀 پیش‌نمایش استقرار خودکار';
    document.getElementById('pv-brand').textContent = body.brand.name_fa + ' (' + body.brand.name_en + ')';
    document.getElementById('pv-subdomain').value = body.subdomain;
    document.getElementById('pv-subdomain').disabled = body.is_deployed; // بعد از استقرار نام قابل تغییر نیست (حذف+ساخت لازم است)
    document.getElementById('pv-root-domain').textContent = body.root_domain;
    document.getElementById('pv-addon-domain').value = body.domain_type === 'addon' ? body.full_domain : '';
    document.getElementById('pv-server-path').textContent = body.server_path;
    document.getElementById('pv-ssl').checked = body.ssl_auto;
    document.getElementById('pv-backup-note').textContent = body.is_deployed ? '✅ (اتوماتیک قبل از بروزرسانی)' : '➖ (نصب اولیه)';
    document.getElementById('pv-suggestions').style.display = 'none';
    document.getElementById('pv-validation').innerHTML = body.is_deployed ? 'ℹ️ این برند استقرار دارد — عملیات بروزرسانی انجام می‌شود.' : '';
    document.getElementById('pv-start-btn').textContent = body.is_deployed ? '🔄 شروع بروزرسانی' : '✅ تأیید و شروع';

    /* 🌐 v2.12: نوع دامنه — در حالت بروزرسانی قفل، در استقرار جدید انتخابی */
    const typeRow = document.getElementById('pv-domain-type-row');
    if (body.is_deployed) {
        typeRow.style.display = 'none';
        document.getElementById('pv-addon-row').style.display = body.domain_type === 'addon' ? 'flex' : 'none';
        document.getElementById('pv-subdomain-row').style.display = body.domain_type === 'subdomain' ? 'flex' : 'none';
        if (body.domain_type === 'addon') document.getElementById('pv-addon-domain').disabled = true;
    } else {
        typeRow.style.display = 'flex';
        document.querySelectorAll('input[name=pv-domain-type]').forEach(r => { r.disabled = false; r.checked = r.value === 'subdomain'; });
        document.getElementById('pv-addon-domain').disabled = false;
        switchDomainType();
    }

    document.getElementById('pv-full-url').textContent = 'https://' + body.full_domain;

    if (!body.settings_ok) {
        document.getElementById('pv-validation').innerHTML = '❌ <b>تنظیمات cPanel کامل نیست</b> — <a href="cpanel-settings.php">تنظیمات</a>';
        document.getElementById('pv-start-btn').disabled = true;
    } else {
        document.getElementById('pv-start-btn').disabled = false;
    }

    document.getElementById('deploy-dialog').style.display = 'flex';
    if (!body.is_deployed) liveValidateSubdomain();
}

/* 🌐 v2.12: جابجایی زیردامنه ↔ دامنه الحاقی */
function switchDomainType() {
    const isAddon = document.querySelector('input[name=pv-domain-type]:checked')?.value === 'addon';
    document.getElementById('pv-addon-row').style.display = isAddon ? 'flex' : 'none';
    document.getElementById('pv-subdomain-row').style.display = isAddon ? 'none' : 'flex';
    liveValidateSubdomain(true);
}

function closeDeployDialog() {
    document.getElementById('deploy-dialog').style.display = 'none';
}

/* ✅ اعتبارسنجی زنده نام زیردامنه / دامنه الحاقی (۸ قانون + تکرار) */
let validateTimer = null;
function liveValidateSubdomain(force = false) {
    clearTimeout(validateTimer);
    validateTimer = setTimeout(async () => {
        const isAddon = document.querySelector('input[name=pv-domain-type]:checked')?.value === 'addon';
        const name = (isAddon ? document.getElementById('pv-addon-domain') : document.getElementById('pv-subdomain')).value.trim();
        const msgEl = document.getElementById('pv-validation');
        const sugEl = document.getElementById('pv-suggestions');
        if (name.length < 2) {
            msgEl.textContent = '';
            sugEl.style.display = 'none';
            if (isAddon) updateFullUrlAddon(name);
            return;
        }

        const body = await api({action: 'validate_subdomain', name, brand_id: currentBrandId, domain_type: isAddon ? 'addon' : 'subdomain'});
        if (body.available) {
            msgEl.innerHTML = '✅ ' + (body.error || 'نام معتبر و آزاد است');
            msgEl.style.color = '#16a34a';
            sugEl.style.display = 'none';
        } else {
            msgEl.innerHTML = '❌ ' + (body.error || 'نام نامعتبر است');
            msgEl.style.color = '#dc2626';
            if (body.suggestions && body.suggestions.length) {
                sugEl.innerHTML = '💡 پیشنهادات: ' + body.suggestions.map(s => `<span class="sug-item" onclick="pickSuggestion('${s}')">${s}</span>`).join('');
                sugEl.style.display = 'block';
            }
        }
        if (isAddon) updateFullUrlAddon(name);
    }, force ? 150 : 400);
}

/* 🌐 بروزرسانی پیش‌نمایش URL در حالت دامنه الحاقی */
function updateFullUrlAddon(domain) {
    document.getElementById('pv-full-url').textContent = domain.length >= 2 ? 'https://' + domain : '—';
}

function pickSuggestion(name) {
    document.getElementById('pv-subdomain').value = name;
    liveValidateSubdomain();
}

/* ═══════════ شروع عملیات ═══════════ */
async function startDeployment() {
    const subdomain = document.getElementById('pv-subdomain').value.trim();
    const sslInstall = document.getElementById('pv-ssl').checked;
    /* 🌐 v2.12: نوع دامنه — زیردامنه یا دامنه الحاقی */
    const domainType = document.querySelector('input[name=pv-domain-type]:checked')?.value || 'subdomain';
    const addonDomain = document.getElementById('pv-addon-domain')?.value.trim() || '';

    if (domainType === 'addon' && addonDomain.length < 4) {
        sahandAlert({ title: 'دامنه ناقص', message: 'دامنه الحاقی را وارد کنید (مثلاً mybrand.ir)', type: 'warning', icon: '🌐' });
        return;
    }

    const body = await api({
        action: 'start',
        brand_id: currentBrandId,
        subdomain,
        ssl_install: sslInstall,
        update_mode: updateMode,
        domain_type: domainType,
        addon_domain: addonDomain,
    });

    if (!body.success) {
        sahandAlert({ title: 'شروع نشد', message: errText(body), type: 'danger', icon: '🚀' });
        return;
    }
    closeDeployDialog();
    await resumeProgress(body.deployment_id, currentBrandId);
    runStepLoop(body.deployment_id);
}

/* ═══════════ حلقه پیشرفت (مرحله‌به‌مرحله) ═══════════ */
async function runStepLoop(deploymentId) {
    if (running) return;
    running = true;
    currentDeploymentId = deploymentId;
    document.getElementById('progress-dialog').style.display = 'flex';
    document.getElementById('pr-error').style.display = 'none';
    document.getElementById('pr-close-btn').style.display = 'none';
    document.getElementById('pr-cancel-btn').style.display = '';

    while (true) {
        const body = await api({action: 'step', deployment_id: deploymentId});

        if (body.done) {
            if (body.success) {
                setProgress(100, '✅ عملیات با موفقیت کامل شد');
                addStep('✅', 'عملیات کامل شد', 'success');
            } else {
                showError(body.error || 'عملیات ناموفق بود' + (body.rolled_back ? ' — سایت به بکاپ قبلی بازگردانده شد.' : ''));
            }
            finishProgress();
            break;
        }
        if (!body.success && body.done === undefined) { /* ادامه */ }
        if (body.step) {
            setProgress(body.progress || 0, body.title || body.message || '');
            addStep('✅', body.message || body.title, 'success');
        }
        await sleep(body.step_delay || 900);
    }
    running = false;
}

/* ▶️ از سرگیری نمایش عملیات در حال اجرا (بدون اجرای مرحله اگر متعلق به صفحه نیست) */
async function resumeProgress(deploymentId, brandId) {
    currentDeploymentId = deploymentId;
    currentBrandId = brandId;
    document.getElementById('progress-dialog').style.display = 'flex';
    document.getElementById('pr-error').style.display = 'none';

    const body = await api({action: 'status', deployment_id: deploymentId});
    if (!body.success) { sahandAlert({ title: 'خطا', message: errText(body), type: 'danger' }); return; }

    const d = body.deployment;
    document.getElementById('pr-title').textContent = (d.action === 'update' ? '🔄 بروزرسانی' : d.action === 'delete' ? '🗑️ حذف' : '🚀 استقرار') + ' — ' + (d.domain || '');
    setProgress(d.status === 'success' ? 100 : Math.round((d.current_step / d.total_steps) * 100), d.status === 'success' ? '✅ کامل شد' : 'در حال اجرا...');

    // نمایش مراحل ثبت‌شده
    const stepsEl = document.getElementById('pr-steps');
    stepsEl.innerHTML = '';
    (body.steps || []).forEach(s => {
        const ico = s.status === 'success' ? '✅' : s.status === 'failed' ? '❌' : '⏭️';
        addStep(ico, s.message, s.status);
    });

    if (d.status === 'failed') {
        showError(d.error || 'عملیات ناموفق بود');
        finishProgress();
    } else if (d.status === 'success') {
        finishProgress();
    } else if (!running) {
        // ⚡ ادامه اجرا از مراحل باقی‌مانده
        runStepLoop(deploymentId);
    }
}

/* 🛑 لغو */
async function cancelDeployment() {
    const ok = await sahandConfirm({ title: 'لغو عملیات', message: 'عملیات فعلی لغو شود؟', type: 'warning', confirmText: 'بله، لغو کن', cancelText: 'ادامه بده', icon: '🛑' });
    if (!ok) return;
    await api({action: 'cancel', deployment_id: currentDeploymentId});
    showError('توسط شما لغو شد');
    finishProgress();
}

/* ═══════════ بروزرسانی دسته‌ای ═══════════ */
async function batchUpdate() {
    const ids = Array.from(document.querySelectorAll('.brand-check:checked')).map(c => parseInt(c.value));
    if (!ids.length) { sahandAlert({ title: 'انتخاب نشده', message: 'حداقل یک برند مستقر انتخاب کنید', type: 'warning', icon: '☑️' }); return; }
    const ok = await sahandConfirm({
        title: 'بروزرسانی دسته‌ای',
        message: 'بروزرسانی ' + faDigits(ids.length) + ' برند شروع شود؟\n(یکی‌یکی پردازش می‌شوند تا سرور overloaded نشود)',
        type: 'question', confirmText: 'شروع کن', icon: '🔄',
    });
    if (!ok) return;

    const body = await api({action: 'batch_update', brand_ids: ids});
    if (body.success) {
        await sahandAlert({
            title: 'در صف قرار گرفت',
            message: '✅ ' + faDigits(body.queued) + ' برند در صف بروزرسانی قرار گرفت' + (body.errors.length ? '\n⚠️ ' + faDigits(body.errors.length) + ' خطا:\n' + body.errors.join('\n') : ''),
            type: 'success', icon: '🔄',
        });
        location.reload();
    } else {
        sahandAlert({ title: 'خطا', message: (Array.isArray(body.errors) && body.errors.length ? body.errors.join('\n') : errText(body)), type: 'danger' });
    }
}

/* ═══════════ ابزارهای UI ═══════════ */
function setProgress(percent, title) {
    document.getElementById('pr-bar').style.width = percent + '%';
    document.getElementById('pr-percent').textContent = faDigits(percent) + '٪';
    document.getElementById('pr-current').textContent = title;
}
function addStep(ico, message, status) {
    const el = document.createElement('div');
    el.className = 'pr-step';
    el.innerHTML = `<span class="st-ico">${ico}</span><span>${message}</span>`;
    document.getElementById('pr-steps').prepend(el);
}
function showError(msg) {
    const el = document.getElementById('pr-error');
    el.textContent = '❌ ' + msg;
    el.style.display = 'block';
    setProgress(100, '⚠️ عملیات متوقف شد');
}
function finishProgress() {
    document.getElementById('pr-cancel-btn').style.display = 'none';
    document.getElementById('pr-close-btn').style.display = '';
    running = false;
}
function closeProgress() { document.getElementById('progress-dialog').style.display = 'none'; location.reload(); }
function minimizeProgress() { document.getElementById('progress-dialog').style.display = 'none'; }

function openDeleteDialog(brandId, nameEn) {
    document.getElementById('del-brand-id').value = brandId;
    document.getElementById('del-brand-name').textContent = nameEn;
    document.getElementById('delete-dialog').style.display = 'flex';
}

function toggleAll(master) {
    document.querySelectorAll('.brand-check').forEach(c => { if (!c.disabled) c.checked = master.checked; });
}
function faDigits(n) { return String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]); }
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
/* 🛡️ استخراج امن متن خطا — هرگز «undefined» نمایش داده نمی‌شود */
function errText(body) {
    if (!body) { return 'خطای نامشخص — پاسخی از سرور دریافت نشد.'; }
    if (typeof body.error === 'string' && body.error.trim() !== '') { return body.error; }
    if (typeof body.message === 'string' && body.message.trim() !== '') { return body.message; }
    return 'خطای نامشخص (پاسخ سرور: ' + String(body.status || body.code || 'بدون کد') + ')';
}

/* 📡 فراخوانی AJAX مشترک — مقاوم در برابر پاسخ غیر JSON (مثل صفحه خطای ۵۰۰) */
async function api(payload) {
    const form = new FormData();
    Object.entries(payload).forEach(([k, v]) => form.append(k, v));
    form.append('csrf_token', CSRF);
    let res;
    try {
        res = await fetch('deploy-status.php', {method: 'POST', body: form});
    } catch (e) {
        return {success: false, error: 'خطای شبکه — ارتباط با سرور برقرار نشد.'};
    }
    try {
        return await res.json();
    } catch (e) {
        return {success: false, error: 'پاسخ سرور قابل خواندن نبود (HTTP ' + res.status + ') — احتمالاً خطای موقت سرور.'};
    }
}

/* 🚀 اجرای خودکار: اگر deployment در URL بود */
<?php if ($openDeployment > 0): ?>
document.addEventListener('DOMContentLoaded', () => resumeProgress(<?= $openDeployment ?>, <?= $brandId ?: 0 ?>));
<?php elseif ($selectedBrand): ?>
document.addEventListener('DOMContentLoaded', () => openDeployDialog(<?= (int)$selectedBrand['id'] ?>, <?= !empty($selectedBrand['is_deployed']) ? 'true' : 'false' ?>));
<?php endif; ?>
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
