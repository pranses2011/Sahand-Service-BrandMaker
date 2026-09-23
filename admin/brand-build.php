<?php
/**
 * 🏗️ فرآیند ساخت سایت برند — نمای ۸ مرحله‌ای با نوار پیشرفت
 * ============================================================
 * مراحل با AJAX به ترتیب اجرا می‌شوند و نتیجه هر مرحله نمایش داده می‌شود.
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();
$brandId = (int)get_param('id');
$brand = $db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
if (!$brand) {
    flash('danger', 'برند یافت نشد.');
    redirect('brands.php');
}

$pageTitle = 'ساخت سایت برند';
$activeMenu = 'brands';
require __DIR__ . '/includes/header.php';

// مراحل فرآیند
$steps = [
    1 => ['🎨', 'تحلیل لوگو و استخراج پالت رنگ', 'استخراج رنگ‌های غالب با الگوریتم K-Means و تولید تم روشن + تاریک'],
    2 => ['📚', 'تطبیق برند با پایگاه دانش', 'جستجوی اطلاعات برند در پایگاه دانش ۵۷ برند و ثبت دستگاه‌ها'],
    3 => ['✍️', 'تولید محتوا + طراحی UI/UX Pro', 'متن یکتای صفحات با موتور AI + چیدمان حرفه‌ای هر صفحه با اسکیل UI/UX Pro'],
    4 => ['📰', 'تولید مقالات اولیه', '۵ مقاله یکتا و مفصل (۸۰۰+ کلمه) با زمان‌بندی انتشار'],
    5 => ['❓', 'تولید سوالات متداول', 'حداقل ۱۰ سوال متداول مرتبط با برند و خدمات'],
    6 => ['🚨', 'تولید کدهای خطا', 'ثبت کدهای خطای رایج دستگاه‌های برند'],
    7 => ['🔍', 'بهینه‌سازی سئو', 'تولید عنوان، متا، Schema و امتیازدهی تمام صفحات'],
    8 => ['🎉', 'نهایی‌سازی', 'فعال‌سازی سایت برند و آماده‌سازی خروجی'],
];
?>
<input type="hidden" id="brand-id" value="<?= (int)$brandId ?>">
<input type="hidden" id="csrf-token" value="<?= e(Auth::csrfToken()) ?>">

<div class="card" style="max-width:860px;margin:auto">
    <div class="card-header">
        <h3>🏗️ ساخت سایت «<?= e($brand['name_fa']) ?>»</h3>
        <div class="tools"><span class="badge badge-info" style="direction:ltr"><?= e($brand['domain']) ?></span></div>
    </div>
    <div class="card-body">
        <?php if ($brand['status'] === 'published'): ?>
            <div class="alert alert-success">✅ سایت این برند قبلاً ساخته شده است. برای بازتولید، از ویرایش برند استفاده کنید.</div>
            <div style="text-align:center">
                <a href="brand-edit.php?id=<?= (int)$brandId ?>" class="btn btn-primary">✏️ ویرایش برند</a>
                <a href="export.php?brand=<?= (int)$brandId ?>" class="btn btn-success">📦 دانلود ZIP</a>
                <a href="brands.php" class="btn btn-outline">بازگشت</a>
            </div>
        <?php else: ?>

        <!-- ⏳ نوار پیشرفت کلی -->
        <div style="margin-bottom:8px;display:flex;justify-content:space-between;font-size:12.5px">
            <span id="progress-label" style="font-weight:700">آماده شروع...</span>
            <span id="progress-percent" style="font-weight:800;color:var(--primary)">۰٪</span>
        </div>
        <div class="progress" style="height:16px;margin-bottom:24px">
            <div class="bar" id="progress-bar" style="width:0%"></div>
        </div>

        <!-- 📋 مراحل -->
        <div id="steps-list">
            <?php foreach ($steps as $num => [$icon, $title, $desc]): ?>
                <div class="build-step" id="step-<?= $num ?>" data-step="<?= $num ?>"
                     style="display:flex;gap:14px;padding:13px 14px;border:1.5px solid var(--border);border-radius:12px;margin-bottom:10px;align-items:flex-start;transition:all .3s">
                    <div class="step-icon" style="width:38px;height:38px;border-radius:10px;background:var(--bg);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0"><?= $icon ?></div>
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:700;font-size:13.5px"><?= en_to_fa_digits((string)$num) ?>. <?= e($title) ?></div>
                        <div style="font-size:11.5px;color:var(--text-light);margin-top:2px"><?= e($desc) ?></div>
                        <div class="step-details" style="font-size:11.5px;color:var(--info);margin-top:6px;display:none"></div>
                    </div>
                    <div class="step-status" style="font-size:16px;flex-shrink:0">⏳</div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- 🎬 دکمه شروع -->
        <div style="text-align:center;margin-top:20px" id="start-area">
            <button type="button" class="btn btn-primary btn-lg" id="btn-start" onclick="startBuild()">🚀 شروع فرآیند ساخت</button>
            <a href="brands.php" class="btn btn-outline btn-lg">انصراف</a>
        </div>

        <!-- 🎁 ناحیه نتیجه نهایی -->
        <div id="finish-area" style="display:none;text-align:center;padding:24px 0">
            <div style="font-size:64px;margin-bottom:12px">🎉</div>
            <h3 style="margin-bottom:8px">سایت «<?= e($brand['name_fa']) ?>» با موفقیت ساخته شد!</h3>
            <p style="color:var(--text-light);font-size:13px;margin-bottom:20px">همه محتوا تولید شد. حالا می‌توانید آن را ویرایش کنید یا فایل ZIP هسته سایت را دانلود کنید.</p>
            <a href="brand-edit.php?id=<?= (int)$brandId ?>" class="btn btn-primary btn-lg">✏️ ویرایش و شخصی‌سازی</a>
            <a href="export.php?brand=<?= (int)$brandId ?>" class="btn btn-success btn-lg">📦 دانلود فایل ZIP</a>
            <a href="brands.php" class="btn btn-outline btn-lg">لیست برندها</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
/* 🎬 اجرای خودکار فرآیند ساخت — مرحله به مرحله با AJAX */
const BRAND_ID = document.getElementById('brand-id').value;
const CSRF = document.getElementById('csrf-token').value;
const TOTAL = 8;
let currentStep = 0;
let running = false;

/* تبدیل درصد به فارسی */
function faPercent(p) {
    return String(Math.round(p)).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]) + '٪';
}

/* بروزرسانی نمای مرحله */
function setStepState(step, state, details) {
    const el = document.getElementById('step-' + step);
    if (!el) return;
    const status = el.querySelector('.step-status');
    const icon = el.querySelector('.step-icon');
    const det = el.querySelector('.step-details');
    el.style.borderColor = 'var(--border)';
    icon.style.background = 'var(--bg)';
    if (state === 'running') {
        el.style.borderColor = 'var(--primary)';
        el.style.background = 'rgba(30,64,175,.04)';
        icon.style.background = 'var(--primary-light)';
        status.innerHTML = '<span class="spinner" style="border-color:rgba(30,64,175,.25);border-top-color:var(--primary)"></span>';
    } else if (state === 'done') {
        icon.style.background = 'var(--success-light)';
        status.textContent = '✅';
        if (details) {
            det.style.display = 'block';
            det.innerHTML = details;
        }
    } else if (state === 'error') {
        el.style.borderColor = 'var(--danger)';
        el.style.background = 'rgba(220,38,38,.04)';
        icon.style.background = 'var(--danger-light)';
        status.textContent = '❌';
    }
}

/* بروزرسانی نوار پیشرفت */
function setProgress(percent, label) {
    document.getElementById('progress-bar').style.width = percent + '%';
    document.getElementById('progress-percent').textContent = faPercent(percent);
    if (label) {
        document.getElementById('progress-label').textContent = label;
    }
}

/* تبدیل جزئیات به HTML */
function detailsToHtml(details, swatches) {
    if (!details) return '';
    let html = '';
    if (Array.isArray(details)) {
        details.forEach(d => { html += '<div>• ' + String(d) + '</div>'; });
    } else if (typeof details === 'object') {
        Object.keys(details).forEach(k => { html += '<div>• ' + k + ': <b>' + details[k] + '</b></div>'; });
    }
    if (swatches && swatches.length) {
        html += '<div style="display:flex;gap:5px;margin-top:6px">' +
            swatches.map(c => '<span title="' + c + '" style="width:22px;height:22px;border-radius:6px;background:' + c + ';display:inline-block;border:1px solid rgba(0,0,0,.12)"></span>').join('') +
            '</div>';
    }
    return html;
}

/* اجرای یک مرحله */
async function runStep(step) {
    setStepState(step, 'running');
    setProgress((step - 1) / TOTAL * 100, 'در حال اجرای مرحله ' + step + ' از ' + TOTAL + '...');
    try {
        const res = await fetch('brand-build.ajax.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ step: step, brand_id: BRAND_ID })
        });
        const data = await res.json();
        if (!data.success) {
            setStepState(step, 'error', '<div style="color:var(--danger)">' + data.error + '</div>');
            setProgress((step - 1) / TOTAL * 100, '❌ خطا در مرحله ' + step);
            return false;
        }
        setStepState(step, 'done', detailsToHtml(data.details, data.swatches));
        setProgress(step / TOTAL * 100, 'مرحله ' + step + ' کامل شد — ' + (data.message || ''));
        return true;
    } catch (err) {
        setStepState(step, 'error', '<div style="color:var(--danger)">خطای ارتباط با سرور</div>');
        return false;
    }
}

/* شروع و اجرای زنجیره‌ای مراحل */
async function startBuild() {
    if (running) return;
    running = true;
    const btn = document.getElementById('btn-start');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> در حال ساخت...';

    for (let s = 1; s <= TOTAL; s++) {
        const ok = await runStep(s);
        if (!ok) {
            running = false;
            btn.disabled = false;
            btn.innerHTML = '🔄 تلاش مجدد از مرحله ' + s;
            btn.onclick = async () => { for (let r = s; r <= TOTAL; r++) { if (!await runStep(r)) return; } finish(); };
            return;
        }
        // مکث کوتاه بین مراحل برای نمایش روان
        await new Promise(r => setTimeout(r, 350));
    }
    finish();
}

/* پایان موفق */
function finish() {
    setProgress(100, '✅ فرآیند ساخت کامل شد');
    document.getElementById('start-area').style.display = 'none';
    document.getElementById('finish-area').style.display = 'block';
    running = false;
}
</script>

<style>
/* 🎨 استایل مخصوص اسپینر مراحل */
.spinner {
    width: 18px; height: 18px;
    border: 2.5px solid rgba(30,64,175,.25);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin .7s linear infinite;
    display: inline-block;
}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
