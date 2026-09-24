<?php
/**
 * 🎭 قالب‌ساز درگ‌اند‌دراپ حرفه‌ای (v3.0 — المنتور-گونه)
 * =====================================================
 * ✨ v3.0 (طبق درخواست کاربر):
 *   🏛 ستون‌بندی: بلوک «بخش چندستونی» با ۲ تا ۴ ستون — بلوک‌ها را داخل
 *      هر ستون بکشید و رها کنید؛ چیدمان تودرتو (nested layout)
 *   🧩 ۵۵+ عنصر: هدر/هیرو/محتوا/ستون/کارت/فرم/آمار/تعامل/رسانه/فراخوان/
 *      ساختار/فوتر — همه چیز برای ساخت هر صفحه‌ای
 *   ⚡ طراحی زنده: بوم «رندر واقعی» بلوک‌ها را همان‌طور که در سایت دیده
 *      می‌شوند نشان می‌دهد؛ تغییر ویژگی‌ها بلافاصله اعمال می‌شود؛ متن‌ها
 *      در پنل ویژگی‌ها قابل ویرایش و نتیجه همان لحظه دیده می‌شود
 *
 * بلوک‌ها را بکشید، رها کنید، مرتب کنید و ویژگی‌ها را تنظیم کنید.
 * خروجی: JSON چیدمان ذخیره در جدول templates
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();

/* 💾 ذخیره قالب */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_template') {
    (new Auth())->requireLogin(); // 🛡️ احراز هویت پیش از هدر
    Auth::enforceCsrf();
    $id = (int)post('template_id');
    $name = post('name') ?: 'قالب بدون نام';
    $pageType = post('page_type') ?: 'home';
    $layoutJson = (string)($_POST['layout_json'] ?? '[]');
    // اعتبارسنجی JSON — روی PHP 8.3 از تابع بومی و سریع json_validate استفاده می‌شود
    if (!json_validate($layoutJson)) {
        flash('danger', 'چیدمان نامعتبر است.');
        redirect('templates.php');
    }
    if ($id > 0) {
        $db->update('templates', ['name' => $name, 'page_type' => $pageType, 'layout_json' => $layoutJson], 'id = ?', [$id]);
    } else {
        $id = $db->insert('templates', [
            'name' => $name, 'page_type' => $pageType, 'variant' => 'سفارشی',
            'layout_json' => $layoutJson, 'is_default' => 0,
        ]);
    }
    Logger::activity((int)$_SESSION['user_id'], 'ذخیره قالب', $name);
    flash('success', '✅ قالب «' . $name . '» ذخیره شد.');
    redirect('template-builder.php?id=' . $id);
}

/* 🎨 اسکیل UI/UX Pro — طراحی/ممیزی/اصلاح چیدمان (AJAX) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('action'), ['uiux_design', 'uiux_review', 'uiux_improve'], true)) {
    (new Auth())->requireLogin(); // 🛡️ احراز هویت پیش از هدر
    Auth::enforceCsrf();
    $action = post('action');
    $pageType = post('page_type') ?: 'home';
    $skill = new UIUXPro();
    try {
        if ($action === 'uiux_design') {
            $result = $skill->designPage($pageType, [
                'brand_fa' => post('brand_fa'),
                'devices_count' => (int)post('devices_count', '1'),
                'articles_count' => (int)post('articles_count', '1'),
                'has_testimonials' => post('has_testimonials', '1') === '1',
            ]);
            json_response(['success' => true, 'data' => $result]);
        }
        $layoutRaw = (string)($_POST['layout_json'] ?? '[]');
        if (!json_validate($layoutRaw)) {
            json_response(['success' => false, 'error' => 'چیدمان ارسالی نامعتبر است.'], 400);
        }
        $layout = json_decode($layoutRaw, true);
        if (!is_array($layout)) {
            json_response(['success' => false, 'error' => 'چیدمان ارسالی نامعتبر است.'], 400);
        }
        if ($action === 'uiux_review') {
            json_response(['success' => true, 'data' => $skill->reviewLayout($layout, $pageType)]);
        }
        json_response(['success' => true, 'data' => $skill->improveLayout($layout, $pageType)]);
    } catch (Throwable $e) {
        json_response(['success' => false, 'error' => 'خطای اسکیل UI/UX Pro: ' . $e->getMessage()], 500);
    }
}

/* 📥 بارگذاری قالب (موجود یا جدید) */
$templateId = (int)get_param('id');
$newPageType = get_param('page', 'home');
$template = null;
if ($templateId > 0) {
    $template = $db->fetch('SELECT * FROM templates WHERE id = ?', [$templateId]);
    if ($template) {
        $newPageType = $template['page_type'];
    }
}
$layout = $template ? (json_decode($template['layout_json'] ?? '[]', true) ?: []) : [];

$pageTitle = $template ? 'ویرایش قالب: ' . $template['name'] : 'قالب جدید';
$activeMenu = 'template-builder';
require __DIR__ . '/includes/header.php';

/* 📚 کتابخانه بلوک‌ها — ۵۵+ عنصر در ۱۲ دسته (v3.0)
 * [آیکون، برچسب، پیش‌فرض‌های ویژگی] */
$blockLibrary = [
    'هدر' => [
        'header-v1' => ['📐', 'هدر ساده (لوگو + منو)', ['sticky' => 0]],
        'header-v2' => ['📐', 'هدر با نوار تماس', []],
        'header-v3' => ['📐', 'هدر شیشه‌ای چسبان', ['sticky' => 1]],
        'top-bar' => ['📏', 'نوار بالایی (تلفن + ساعات)', []],
    ],
    'هیرو' => [
        'hero' => ['🦸', 'هیرو متن + دکمه', ['title' => 'تعمیرات تخصصی با قطعات اصلی', 'subtitle' => 'نمایندگی رسمی — پاسخگویی ۷ روز هفته']],
        'hero-slider' => ['🦸', 'اسلایدر تصویری', ['slides' => 3]],
        'hero-split' => ['🦸', 'هیرو دو بخشی', ['title' => 'تعمیر لوازم خانگی در محل']],
        'hero-video' => ['🦸', 'هیرو با پس‌زمینه تصویر', []],
        'hero-countdown' => ['⏱️', 'هیرو با شمارش معکوس', []],
    ],
    'محتوا' => [
        'text' => ['📝', 'متن آزاد', ['title' => 'درباره ما', 'text' => 'متن خود را اینجا بنویسید — این بخش در سایت به همین شکل نمایش داده می‌شود.']],
        'text-image' => ['📝', 'متن + تصویر', ['title' => 'درباره برند']],
        'rich-text' => ['📝', 'متن غنی (عنوان + لیست)', ['title' => 'خدمات ما شامل:']],
        'quote' => ['❝', 'نقل‌قول / شعار', ['text' => 'کیفیت تعمیر، اعتبار ماست']],
        'intro' => ['📋', 'معرفی کوتاه برند', []],
        'two-col' => ['📋', 'مقایسه دو ستونه', []],
        'three-col' => ['📋', 'سه ستونه متنی', []],
    ],
    '🏛 ستون‌بندی' => [
        'section-columns' => ['🏛', 'بخش چندستونی (۲-۴ ستون)', ['columns' => 2]],
        'section-split' => ['🏛', 'بخش دو بخشی نامتقارن', []],
        'feature-list' => ['✅', 'فهرست ویژگی با آیکون', []],
    ],
    'کارت‌ها' => [
        'services-grid' => ['🃏', 'کارت‌های خدمات', ['columns' => 3]],
        'devices-grid' => ['🃏', 'کارت دستگاه‌ها (داینامیک)', ['columns' => 4]],
        'articles-recent' => ['🃏', 'کارت مقالات اخیر (داینامیک)', []],
        'articles-grid' => ['🃏', 'شبکه مقالات', []],
        'features' => ['🃏', 'کارت‌های چرا ما', []],
        'team' => ['🃏', 'کارت تیم', []],
        'pricing-table' => ['💰', 'جدول تعرفه خدمات', []],
        'brands-links' => ['🏷️', 'لوگوی برندها', []],
    ],
    'فرم' => [
        'contact-form' => ['📝', 'فرم تماس', []],
        'request-form' => ['📝', 'فرم درخواست خدمات', []],
        'newsletter-form' => ['📧', 'فرم عضویت خبرنامه', []],
    ],
    'آمار' => [
        'counter-stats' => ['📊', 'شمارنده‌ها', []],
        'progress-bars' => ['📊', 'نوارهای پیشرفت', []],
        'skill-bars' => ['📊', 'مهارت‌های تخصصی', []],
    ],
    'تعامل' => [
        'testimonials' => ['💬', 'اسلایدر نظرات مشتریان', []],
        'faq-accordion' => ['❓', 'آکاردئون سوالات', []],
        'tabs' => ['🗂️', 'تب‌بندی محتوا', []],
        'timeline' => ['🕐', 'خط زمانی پیشرفت کار', []],
        'steps-process' => ['👣', 'مراحل کار (فرآیند)', []],
    ],
    'رسانه' => [
        'gallery' => ['🖼️', 'گالری تصاویر', []],
        'image-carousel' => ['🎠', 'کاروسل تصاویر', []],
        'video-embed' => ['🎬', 'ویدیو (نصب/آموزش)', []],
        'map' => ['📍', 'نقشه محدوده خدمات', []],
    ],
    'فراخوان' => [
        'cta-phone' => ['📞', 'CTA تماس بزرگ', ['phone' => '۰۲۱-۱۲۳۴۵۶۷۸']],
        'cta-request' => ['🔗', 'CTA ثبت درخواست', []],
        'cta-banner' => ['🔗', 'بنر فراخوان عریض', []],
        'sticky-mobile-cta' => ['📱', 'نوار فراخوان چسبان موبایل', []],
    ],
    'ساختار' => [
        'breadcrumb' => ['🧭', 'مسیر راهنما (Breadcrumb)', []],
        'alert-notice' => ['⚠️', 'هشدار/اطلاعیه', ['text' => 'سرویس در تعطیلات نیز پاسخگوی شماست']],
        'button-group' => ['🔘', 'گروه دکمه', []],
        'icon-list' => ['📋', 'فهرست با آیکون', []],
        'separator' => ['⬜', 'جداکننده', []],
        'spacer' => ['⬜', 'فاصله', ['height' => 46]],
    ],
    'فوتر' => [
        'footer-simple' => ['🦶', 'فوتر ساده', []],
        'footer-contact' => ['🦶', 'فوتر با اطلاعات تماس', []],
        'copyright' => ['©️', 'نوار کپی‌رایت', []],
    ],
];
?>
<link rel="stylesheet" href="<?= asset_ver('assets/css/builder.css') ?>">
<style>
/* ⚡ v3.0: استایل بوم طراحی زنده */
.tb-block { position: relative; border-radius: 12px; }
.tb-block > .block-tools {
    position: absolute; top: 6px; left: 6px; z-index: 20; display: none; gap: 3px;
    background: rgba(15,23,42,.92); padding: 3px; border-radius: 8px; direction: ltr;
    box-shadow: 0 4px 14px rgba(0,0,0,.25);
}
.tb-block:hover > .block-tools, .tb-block.selected > .block-tools { display: flex; }
.tb-block > .block-tools button {
    background: none; border: none; color: #e2e8f0; cursor: pointer; font-size: 13px;
    padding: 4px 7px; border-radius: 6px; line-height: 1;
}
.tb-block > .block-tools button:hover { background: #334155; }
.tb-block.selected { outline: 2.5px solid #2563eb; outline-offset: 2px; }
.tb-label {
    position: absolute; top: -11px; right: 10px; z-index: 21; font-size: 10.5px; font-weight: 700;
    background: #2563eb; color: #fff; border-radius: 20px; padding: 2.5px 11px; white-space: nowrap;
    box-shadow: 0 2px 8px rgba(37,99,235,.4); pointer-events: none;
}
.tb-dropzone {
    min-height: 74px; border: 2px dashed #94a3b8; border-radius: 10px; padding: 10px;
    display: flex; flex-direction: column; gap: 10px; transition: all .15s; background: rgba(148,163,184,.06);
}
.tb-dropzone.drag-over { border-color: #2563eb; background: rgba(37,99,235,.1); transform: scale(1.008); }
.tb-dropzone .tb-empty-hint { color: #94a3b8; font-size: 11.5px; text-align: center; margin: auto; line-height: 1.9 }
.tb-col-wrap { display: grid; gap: 14px; }
</style>

<form method="post" id="builder-form">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="save_template">
    <input type="hidden" name="template_id" value="<?= (int)($template['id'] ?? 0) ?>">
    <input type="hidden" name="layout_json" id="layout-json" value="<?= e(json_encode($layout, JSON_UNESCAPED_UNICODE)) ?>">

    <div class="card" style="margin-bottom:16px">
        <div class="card-body" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:14px 18px">
            <input type="text" name="name" class="form-control" style="max-width:240px" placeholder="نام قالب..." value="<?= e($template['name'] ?? '') ?>" required>
            <select name="page_type" class="form-control" style="max-width:170px">
                <?php foreach (['home' => 'صفحه اصلی', 'services' => 'خدمات', 'blog' => 'مقالات', 'contact' => 'تماس', 'about' => 'درباره', 'custom' => 'سفارشی'] as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $newPageType === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <div class="device-tabs">
                <button type="button" class="device-tab active" onclick="setDevice(this,'desktop')" title="دسکتاپ">🖥️</button>
                <button type="button" class="device-tab" onclick="setDevice(this,'tablet')" title="تبلت">📱</button>
                <button type="button" class="device-tab" onclick="setDevice(this,'mobile')" title="موبایل">📲</button>
            </div>
            <div style="margin-inline-start:auto;display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="btn btn-info" onclick="uiuxDesign()" id="btn-uiux-design" title="طراحی چیدمان حرفه‌ای با اسکیل UI/UX Pro">✨ طراحی با UI/UX Pro</button>
                <button type="button" class="btn btn-outline" onclick="uiuxReview()" id="btn-uiux-review" title="ممیزی UX چیدمان فعلی">🔍 بررسی UX</button>
                <button type="button" class="btn btn-info" onclick="openLivePreview()">👁️ پیش‌نمایش زنده</button>
                <button type="button" class="btn btn-outline" onclick="clearLayout()" title="خالی کردن بوم">🗑️ خالی‌کردن</button>
                <a href="templates.php" class="btn btn-outline">بازگشت</a>
                <button type="submit" class="btn btn-primary">💾 ذخیره قالب</button>
            </div>
        </div>
    </div>

    <!-- 🎨 پنل نتایج اسکیل UI/UX Pro -->
    <div class="card" id="uiux-panel" style="display:none;margin-bottom:16px">
        <div class="card-header">
            <h3 id="uiux-panel-title">🎨 اسکیل UI/UX Pro</h3>
            <div class="tools"><button type="button" class="btn btn-outline btn-sm" onclick="closeUiuxPanel()">✕ بستن</button></div>
        </div>
        <div class="card-body" id="uiux-panel-body"></div>
    </div>

    <div class="builder">
        <!-- 📚 کتابخانه بلوک -->
        <aside class="block-library">
            <div class="block-lib-title">📚 بلوک‌ها را بکشید ↓ <small style="font-weight:400;color:var(--text-light)">(دابل‌کلیک = افزودن)</small></div>
            <?php foreach ($blockLibrary as $category => $blocks): ?>
                <div class="block-cat"><?= e($category) ?> <span class="badge badge-secondary" style="font-size:9.5px"><?= count($blocks) ?></span></div>
                <?php foreach ($blocks as $key => [$icon, $label, $defaults]): ?>
                    <div class="block-item" draggable="true" data-block="<?= e($key) ?>" title="دابل‌کلیک = افزودن سریع | دکمه 👁 = پیش‌نمایش تک‌بلوک">
                        <span class="icon"><?= $icon ?></span>
                        <span><?= e($label) ?></span>
                        <button type="button" class="block-eye" title="پیش‌نمایش این بلوک" onclick="event.stopPropagation();previewSingleBlock('<?= e($key) ?>')">👁</button>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </aside>

        <!-- 🎨 بوم طراحی زنده -->
        <section class="builder-canvas" id="canvas">
            <div class="canvas-empty" id="canvas-empty" <?= $layout ? 'style="display:none"' : '' ?>>
                <div style="font-size:48px;margin-bottom:10px">🎭</div>
                بلوک‌ها را از پنل راست بکشید و اینجا رها کنید<br>
                <small>⚡ بوم، بلوک‌ها را «زنده و واقعی» رندر می‌کند — همان‌طور که در سایت دیده می‌شوند<br>
                🏛 برای چندستونه کردن، ابتدا «بخش چندستونی» اضافه کنید و بلوک‌ها را داخل ستون‌ها بیندازید</small>
            </div>
            <div id="canvas-blocks"></div>
        </section>

        <!-- 🎛️ ویژگی‌ها -->
        <aside class="block-props" id="props-panel">
            <div class="prop-title">🎛️ ویژگی‌های بلوک</div>
            <div id="props-content" style="font-size:12px;color:var(--text-light)">
                یک بلوک را در بوم انتخاب کنید تا ویژگی‌هایش اینجا نمایش داده شود.
            </div>
        </aside>
    </div>
</form>

<!-- 👁️ مودال پیش‌نمایش زنده -->
<div class="modal-backdrop" id="preview-backdrop">
    <div class="modal preview-modal">
        <div class="modal-header" style="justify-content:space-between;gap:10px">
            <span>👁️ پیش‌نمایش زنده قالب</span>
            <div class="device-tabs" style="margin:0">
                <button type="button" class="device-tab active" onclick="setPreviewDevice(this,375,'موبایل')" title="موبایل">📲</button>
                <button type="button" class="device-tab" onclick="setPreviewDevice(this,768,'تبلت')" title="تبلت">📱</button>
                <button type="button" class="device-tab" onclick="setPreviewDevice(this,0,'دسکتاپ')" title="دسکتاپ">🖥️</button>
            </div>
            <button type="button" class="btn btn-outline btn-sm" onclick="closeLivePreview()">✕ بستن</button>
        </div>
        <div class="modal-body preview-body">
            <iframe id="preview-frame" class="preview-frame" src="about:blank" title="پیش‌نمایش"></iframe>
        </div>
    </div>
</div>

<script>
/* 🎭 موتور قالب‌ساز v3.0 — طراحی زنده + ستون‌بندی تودرتو */
const BLOCK_META = <?= json_encode(array_map(function ($cats) {
    $flat = [];
    foreach ($cats as $key => $meta) { $flat[$key] = ['label' => $meta[1], 'defaults' => $meta[2]]; }
    return $flat;
}, $blockLibrary), JSON_UNESCAPED_UNICODE) ?>;

let layout = JSON.parse(document.getElementById('layout-json').value || '[]');
let selected = null; // رشته مسیر مثل '3' یا '3.cols.1.0'

/* ==================================================
 * ⚡ رندر واقعی بلوک‌ها (طراحی زنده — همان HTML سایت)
 * ================================================== */
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function blockHtml(block, props) {
    const t = props.title || '';
    const padCls = 'blk-pad-' + (props.padding || 'default');
    const bgCls = 'blk-bg-' + (props.background || 'default');
    const B = (inner, extra) => `<div class="blk ${bgCls} ${padCls} ${extra || ''}">${inner}</div>`;
    const TITLE = t ? `<div class="blk-title">${esc(t)}</div>` : '';

    switch (block) {
        case 'top-bar': return B(`<div class="tb-row"><span>📞 ۰۲۱-۱۲۳۴۵۶۷۸</span><span>🕐 شنبه تا پنجشنبه ۹ تا ۲۰</span></div>`, 'topbar-blk');
        case 'header-v1': case 'header-v2': case 'header-v3':
            return `<div class="blk ${bgCls} ${padCls} header-blk ${block === 'header-v3' ? 'glass' : ''} ${props.sticky ? 'sticky-demo' : ''}">${block === 'header-v2' ? '<div class="tb-row"><span>📞 ۰۲۱-۱۲۳۴۵۶۷۸</span><span>💬 پاسخگویی آنلاین</span></div>' : ''}<div class="h-row"><div class="fake-logo">🏗️</div><nav class="fake-nav"><span>خانه</span><span>خدمات</span><span>مقالات</span><span>تماس</span></nav><div class="fake-cta">ثبت درخواست</div></div></div>`;
        case 'hero': return B(`<div class="hero-title">${esc(t || 'تعمیرات تخصصی با قطعات اصلی')}</div><div class="hero-sub">${esc(props.subtitle || 'نمایندگی رسمی — پاسخگویی ۷ روز هفته')}</div><div class="hero-btns"><span class="hero-btn">📞 تماس فوری</span><span class="hero-btn ghost">ثبت درخواست آنلاین</span></div>`, 'hero-blk');
        case 'hero-slider': return B(`<div class="hero-title">${esc(t || 'اسلایدر تصویری')}</div><div class="hero-img wide">🖼️</div><div class="slider-dots">● ○ ○</div>`, 'hero-blk slider');
        case 'hero-split': return B(`<div class="hero-split"><div><div class="hero-title">${esc(t || 'تعمیر لوازم خانگی در محل')}</div><div class="hero-sub">متن معرفی + دکمه فراخوان</div><div class="hero-btns"><span class="hero-btn">شروع کنید</span></div></div><div class="hero-img">🛠️</div></div>`, 'hero-blk split-hero');
        case 'hero-video': return B(`<div class="hero-title">${esc(t || 'هیرو با پس‌زمینه تصویر')}</div><div class="play">▶</div>`, 'hero-blk video');
        case 'hero-countdown': return B(`<div class="hero-title">${esc(t || 'کمپین سرویس دوره‌ای')}</div><div class="count-row"><span class="count-box"><b>۰۲</b>روز</span><span class="count-box"><b>۱۴</b>ساعت</span><span class="count-box"><b>۳۰</b>دقیقه</span></div>`, 'hero-blk');
        case 'text': return B(`${TITLE}<div class="pv-text">${esc(props.text || 'متن خود را اینجا بنویسید — این بخش در سایت به همین شکل نمایش داده می‌شود. می‌توانید از پنل ویژگی‌ها ویرایش کنید و نتیجه را همان لحظه ببینید.').replace(/\n/g, '<br>')}</div>`);
        case 'text-image': case 'intro': return B(`<div class="split">${TITLE ? '' : ''}<div><div class="blk-title">${esc(t || 'درباره برند')}</div><div class="fake-lines"><div class="fl w100"></div><div class="fl w90"></div><div class="fl w60"></div></div></div><div class="fake-img">🖼️</div></div>`);
        case 'rich-text': return B(`${TITLE}<ul class="pv-list"><li>✅ نصب و راه‌اندازی تخصصی</li><li>✅ تعمیر با قطعات اصلی</li><li>✅ ۶ ماه ضمانت قطعه و خدمات</li></ul>`);
        case 'quote': return B(`<div class="quote">«${esc(props.text || 'کیفیت تعمیر، اعتبار ماست')}»</div>`, 'quote-blk');
        case 'two-col': return B(`${TITLE}<div class="cols c2"><div class="fake-card"><div class="card-t">ستون اول</div><div class="fl w90"></div><div class="fl w70"></div></div><div class="fake-card"><div class="card-t">ستون دوم</div><div class="fl w90"></div><div class="fl w70"></div></div></div>`);
        case 'three-col': return B(`${TITLE}<div class="cols c3"><div class="fake-card"><div class="card-t">۱</div><div class="fl w80"></div></div><div class="fake-card"><div class="card-t">۲</div><div class="fl w80"></div></div><div class="fake-card"><div class="card-t">۳</div><div class="fl w80"></div></div></div>`);
        case 'section-columns': {
            /* 🏛 کانتینر چندستونی — ستون‌ها با ناحیه رهاسازی */
            const cols = Math.max(2, Math.min(4, parseInt(props.columns || 2, 10)));
            let inner = '';
            for (let c = 0; c < cols; c++) { inner += `<div class="tb-col" style="display:flex;flex-direction:column;gap:10px;min-width:0"></div>`; }
            return `<div class="blk ${bgCls} ${padCls} section-cols-blk">${TITLE}<div class="tb-col-wrap" style="grid-template-columns:repeat(${cols},1fr)">${inner}</div></div>`;
        }
        case 'section-split': return B(`${TITLE}<div class="tb-col-wrap" style="grid-template-columns:2fr 1fr"><div style="display:flex;flex-direction:column;gap:10px"></div><div style="display:flex;flex-direction:column;gap:10px"></div></div>`, 'section-cols-blk');
        case 'feature-list': return B(`${TITLE}<div class="feat-list"><div class="feat-row"><span class="feat-ico">⚡</span><div><b>سرعت عمل</b><div class="feat-d">اعزام تکنسین در کمتر از ۲ ساعت</div></div></div><div class="feat-row"><span class="feat-ico">🛡️</span><div><b>ضمانت کتبی</b><div class="feat-d">۶ ماه ضمانت روی قطعه و خدمات</div></div></div><div class="feat-row"><span class="feat-ico">💰</span><div><b>قیمت شفاف</b><div class="feat-d">پیش‌فاکتور قبل از شروع کار</div></div></div></div>`);
        case 'services-grid': case 'features': return B(`<div class="blk-title">${esc(t || (block === 'features' ? 'چرا ما را انتخاب کنید؟' : 'خدمات ما'))}</div><div class="cols c3">${'<div class="fake-card"><div class="card-ico">🔧</div><div class="card-t">سرویس نمونه</div><div class="fl w80"></div></div>'.repeat(3)}</div>`);
        case 'devices-grid': return B(`<div class="blk-title">${esc(t || 'دستگاه‌های تحت پوشش')}</div><div class="cols c4">${['🌀 لباسشویی', '🧊 یخچال', '🍽️ ظرفشویی', '❄️ کولر', '📺 تلویزیون', '♨️ پکیج', '📻 مایکروویو', '🔥 فر و اجاق'].map(d => `<div class="fake-card"><div class="card-ico">${d.split(' ')[0]}</div><div class="card-t">${d.split(' ')[1]}</div></div>`).join('')}</div>`);
        case 'articles-recent': case 'articles-grid': return B(`<div class="blk-title">${esc(t || 'مقالات اخیر')}</div><div class="cols c3">${'<div class="fake-card"><div class="fake-img small">📰</div><div class="card-t">عنوان مقاله نمونه</div><div class="fl w100"></div></div>'.repeat(3)}</div>`);
        case 'team': return B(`<div class="blk-title">${esc(t || 'تیم ما')}</div><div class="cols c4">${'<div class="fake-card"><div class="fake-ava">👤</div><div class="card-t">عضو تیم</div></div>'.repeat(4)}</div>`);
        case 'pricing-table': return B(`<div class="blk-title">${esc(t || 'تعرفه خدمات')}</div><div class="price-table"><div class="price-row"><span>دریافت و عیب‌یابی تخصصی</span><b>رایگان</b></div><div class="price-row"><span>سرویس دوره‌ای لباسشویی</span><b>از ۴۵۰ هزار تومان</b></div><div class="price-row"><span>شارژ گاز کولر گازی</span><b>از ۹۰۰ هزار تومان</b></div></div>`);
        case 'brands-links': return B(`<div class="blk-title">${esc(t || 'برندهای مورد خدمت')}</div><div class="cols c6">${'<div class="fake-logo-s">🏷️</div>'.repeat(6)}</div>`);
        case 'contact-form': case 'request-form': return B(`<div class="blk-title">${esc(t || (block === 'request-form' ? 'فرم درخواست خدمات' : 'فرم تماس'))}</div><div class="form-grid"><div class="fake-input">نام و نام خانوادگی</div><div class="fake-input">شماره تماس</div><div class="fake-input">شرح مشکل</div><div class="hero-btn full">ارسال درخواست</div></div>`);
        case 'newsletter-form': return B(`<div class="blk-title">${esc(t || 'عضویت در خبرنامه')}</div><div class="news-row"><div class="fake-input" style="flex:1">ایمیل شما</div><div class="hero-btn">عضویت</div></div>`);
        case 'counter-stats': case 'stats': return `<div class="blk ${bgCls} ${padCls} stats-blk"><div class="stat"><div class="stat-n">۱۲+</div><div class="stat-l">سال تجربه</div></div><div class="stat"><div class="stat-n">۵۰هزار+</div><div class="stat-l">تعمیر موفق</div></div><div class="stat"><div class="stat-n">۹۸٪</div><div class="stat-l">رضایت</div></div></div>`;
        case 'progress-bars': return B(`${TITLE}<div class="pbar"><span>سرعت تعمیر</span><div class="track"><div class="fill" style="width:90%"></div></div></div><div class="pbar"><span>کیفیت قطعات</span><div class="track"><div class="fill" style="width:95%"></div></div></div>`);
        case 'skill-bars': return B(`${TITLE}<div class="pbar"><span>تعمیر برد و الکترونیک</span><div class="track"><div class="fill" style="width:88%"></div></div></div><div class="pbar"><span>کمپرسور و مدار گاز</span><div class="track"><div class="fill" style="width:82%"></div></div></div><div class="pbar"><span>سیستم‌های هیدرولیک</span><div class="track"><div class="fill" style="width:76%"></div></div></div>`);
        case 'testimonials': return B(`<div class="blk-title">${esc(t || 'نظرات مشتریان')}</div><div class="quote">«سرویس سریع و منظم بود؛ راضی بودم.»</div><div class="slider-dots">● ○ ○</div>`);
        case 'faq-accordion': return B(`<div class="blk-title">${esc(t || 'سوالات متداول')}</div><div class="acc">سوال نمونه اول؟ <b>＋</b></div><div class="acc">سوال نمونه دوم؟ <b>＋</b></div><div class="acc">سوال نمونه سوم؟ <b>＋</b></div>`);
        case 'tabs': return B(`<div class="blk-title">${esc(t || 'تب‌بندی محتوا')}</div><div class="tabs-row"><span class="tab cur">تعمیر</span><span class="tab">سرویس</span><span class="tab">نصب</span></div><div class="fake-card" style="text-align:right"><div class="fl w100"></div><div class="fl w90"></div><div class="fl w60"></div></div>`);
        case 'timeline': return B(`<div class="blk-title">${esc(t || 'مراحل پیشرفت کار')}</div><div class="tl"><div class="tl-item done"><span class="tl-dot">✓</span><div>ثبت درخواست</div></div><div class="tl-item done"><span class="tl-dot">✓</span><div>عیب‌یابی و پیش‌فاکتور</div></div><div class="tl-item cur"><span class="tl-dot">۳</span><div>تعمیر در حال انجام</div></div><div class="tl-item"><span class="tl-dot">۴</span><div>تحویل و ضمانت</div></div></div>`);
        case 'steps-process': return B(`<div class="blk-title">${esc(t || 'فرآیند کار ما')}</div><div class="steps-row"><div class="step"><span class="step-n">۱</span><div class="step-t">تماس/ثبت درخواست</div></div><div class="step-arrow">←</div><div class="step"><span class="step-n">۲</span><div class="step-t">اعزام تکنسین</div></div><div class="step-arrow">←</div><div class="step"><span class="step-n">۳</span><div class="step-t">تعمیر و تست</div></div></div>`);
        case 'gallery': return B(`<div class="blk-title">${esc(t || 'گالری')}</div><div class="cols c4">${'<div class="fake-img small">🖼️</div>'.repeat(4)}</div>`);
        case 'image-carousel': return B(`<div class="blk-title">${esc(t || 'کاروسل تصاویر')}</div><div class="hero-img wide">🎠 ‹ ›</div><div class="slider-dots">● ○ ○</div>`);
        case 'video-embed': return B(`<div class="blk-title">${esc(t || 'ویدیوی آموزشی')}</div><div class="fake-img wide" style="height:190px">▶ ویدیوی آموزشی</div>`);
        case 'map': return B(`<div class="blk-title">${esc(t || 'محدوده خدمات')}</div><div class="fake-map">📍 نقشه محدوده خدمات</div>`);
        case 'cta-phone': return B(`<div class="hero-title">همین حالا تماس بگیرید</div><div class="cta-num" dir="ltr">${esc(props.phone || '۰۲۱-۱۲۳۴۵۶۷۸')}</div>`, 'cta-blk');
        case 'cta-request': case 'cta-banner': return B(`<div class="hero-title">${esc(t || 'درخواست تعمیر خود را ثبت کنید')}</div><span class="hero-btn">📝 ثبت درخواست</span>`, 'cta-blk');
        case 'sticky-mobile-cta': return `<div class="blk ${bgCls} ${padCls} sticky-cta-demo"><span>📞 ۰۲۱-۱۲۳۴۵۶۷۸</span><span class="hero-btn">ثبت درخواست</span></div>`;
        case 'breadcrumb': return B(`خانه / خدمات / <b>صفحه فعلی</b>`, 'crumb');
        case 'alert-notice': return `<div class="blk ${bgCls} ${padCls}"><div class="alert-demo ${props.alertType || 'info'}">${props.alertType === 'warning' ? '⚠️' : props.alertType === 'success' ? '✅' : 'ℹ️'} ${esc(props.text || 'سرویس در تعطیلات نیز پاسخگوی شماست')}</div></div>`;
        case 'button-group': return B(`<div class="hero-btns" style="justify-content:flex-start"><span class="hero-btn">تماس فوری</span><span class="hero-btn ghost">مشاهده خدمات</span><span class="hero-btn ghost">مقالات</span></div>`);
        case 'icon-list': return B(`${TITLE}<div class="feat-list"><div class="feat-row"><span class="feat-ico">📞</span><div><b>پاسخگویی تلفنی</b><div class="feat-d">۷ روز هفته از ۹ تا ۲۰</div></div></div><div class="feat-row"><span class="feat-ico">📍</span><div><b>اعزام در محل</b><div class="feat-d">کل تهران و کرج</div></div></div></div>`);
        case 'separator': return `<hr class="blk-sep">`;
        case 'spacer': return `<div class="blk-spacer" style="height:${parseInt(props.height || 46, 10)}px" title="فاصله"></div>`;
        case 'footer-simple': return `<div class="blk ${bgCls} ${padCls} footer-blk"><div class="fake-logo">🏗️</div><nav class="fake-nav" style="justify-content:center"><span>خدمات</span><span>مقالات</span><span>تماس</span></nav><div class="soc-row"><span> Telegram </span><span> Instagram </span><span> WhatsApp </span></div></div>`;
        case 'footer-contact': return `<div class="blk ${bgCls} ${padCls} footer-blk"><div class="tb-col-wrap" style="grid-template-columns:repeat(3,1fr)"><div><div class="fake-logo">🏗️</div><div class="fl w80"></div></div><div><div class="card-t">تماس</div><div class="feat-d">📞 ۰۲۱-۱۲۳۴۵۶۷۸<br>📍 تهران، خیابان نمونه</div></div><div><div class="card-t">ساعات کاری</div><div class="feat-d">شنبه تا پنجشنبه<br>۹ صبح تا ۸ شب</div></div></div></div>`;
        case 'copyright': return `<div class="blk ${bgCls} ${padCls} crump-blk">© تمامی حقوق برای نمایندگی محفوظ است — ساخته‌شده با ❤️</div>`;
        default: return B(`${TITLE}<div class="fake-lines"><div class="fl w90"></div><div class="fl w70"></div></div>`);
    }
}

/* استایل‌های درون‌بوم رندر زنده (تزریق یک‌بار) */
(function injectLiveStyles() {
    const css = `
.tb-live { font-family: Vazirmatn, Tahoma, sans-serif; background: #f8fafc; border-radius: 12px; overflow: hidden; color:#1e293b; direction: rtl; text-align: right; }
.blk { background:#fff; padding:24px 20px; border-bottom:1px dashed #e2e8f0; position: relative; }
.blk:last-child { border-bottom: none; }
.blk-pad-compact { padding: 12px 14px; } .blk-pad-roomy { padding: 42px 26px; } .blk-pad-none { padding: 0; }
.blk-bg-surface { background:#f1f5f9; } .blk-bg-primary { background:linear-gradient(135deg,#1e40af,#0ea5e9); color:#fff; }
.blk-bg-gradient { background:linear-gradient(135deg,#1e40af 0%,#0ea5e9 60%,#f59e0b 100%); color:#fff; }
.blk-bg-dark { background:#0f172a; color:#e2e8f0; }
.blk-title { font-size:15px; font-weight:800; margin-bottom:14px; text-align:center; color:#1e293b; }
.blk-bg-primary .blk-title, .blk-bg-gradient .blk-title, .blk-bg-dark .blk-title { color:#fff; }
.topbar-blk .tb-row { display:flex; justify-content:space-between; font-size:11px; color:#64748b; flex-wrap:wrap; gap:6px; }
.header-blk { padding:12px 16px; } .header-blk .h-row { display:flex; align-items:center; gap:13px; }
.header-blk.glass { background:rgba(255,255,255,.85); backdrop-filter:blur(8px); }
.header-blk.sticky-demo { outline:1.5px dashed #2563eb; outline-offset:-6px; }
.fake-logo { font-size:20px; } .fake-nav { display:flex; gap:14px; font-size:12px; color:#64748b; flex:1; flex-wrap:wrap; }
.fake-cta { background:#1e40af; color:#fff; font-size:11.5px; padding:6px 14px; border-radius:8px; white-space:nowrap; }
.hero-blk { background:linear-gradient(135deg,#1e40af,#0ea5e9); color:#fff; text-align:center; }
.hero-blk.split-hero { text-align:right; }
.hero-title { font-size:19px; font-weight:800; margin-bottom:7px; } .hero-sub { font-size:12px; opacity:.9; margin-bottom:14px; }
.hero-btns { display:flex; gap:9px; justify-content:center; flex-wrap:wrap; }
.hero-blk.split-hero .hero-btns { justify-content:flex-start; }
.hero-btn { background:#f59e0b; border-radius:9px; padding:8px 20px; font-size:12.5px; font-weight:700; display:inline-block; color:#fff; }
.hero-btn.ghost { background:transparent; border:1.5px solid rgba(255,255,255,.6); }
.hero-btn.full { width:100%; text-align:center; }
.hero-img { background:rgba(255,255,255,.16); border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:30px; }
.hero-img.wide { width:100%; height:150px; margin-bottom:9px; }
.hero-split { display:flex; gap:16px; align-items:center; flex-wrap:wrap; } .hero-split > div:first-child { flex:1 1 220px; }
.hero-img:not(.wide) { flex:1 1 170px; height:120px; }
.play { width:48px; height:48px; border-radius:50%; background:rgba(255,255,255,.2); display:flex; align-items:center; justify-content:center; font-size:18px; margin:10px auto; }
.slider-dots { letter-spacing:5px; font-size:10px; opacity:.85; text-align:center; margin-top:6px; }
.count-row { display:flex; gap:10px; justify-content:center; }
.count-box { background:rgba(255,255,255,.15); border-radius:10px; padding:8px 14px; font-size:11px; }
.count-box b { display:block; font-size:20px; }
.pv-text { font-size:13px; line-height:2.05; color:#334155; }
.fake-lines .fl { height:9px; border-radius:5px; background:#e2e8f0; margin:8px 0; }
.w40{width:40%}.w60{width:60%}.w70{width:70%}.w80{width:80%}.w90{width:90%}.w100{width:100%}
.split { display:flex; gap:18px; align-items:center; flex-wrap:wrap; } .split > div:first-child { flex:1 1 240px; }
.fake-img { flex:1 1 170px; height:140px; background:#dbeafe; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:32px; }
.fake-img.small { height:84px; font-size:24px; width:100%; flex:none; }
.fake-img.wide { flex:none; }
.cols { display:grid; gap:12px; } .c2{grid-template-columns:repeat(2,1fr)}.c3{grid-template-columns:repeat(3,1fr)}.c4{grid-template-columns:repeat(4,1fr)}.c6{grid-template-columns:repeat(6,1fr)}
.fake-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 12px; text-align:center; min-width:0; }
.blk-bg-primary .fake-card, .blk-bg-dark .fake-card, .blk-bg-gradient .fake-card { background:rgba(255,255,255,.1); border-color:rgba(255,255,255,.25); }
.card-ico { font-size:23px; margin-bottom:6px; } .card-t { font-size:12.5px; font-weight:700; margin-bottom:5px; }
.fake-ava { font-size:28px; }
.quote { background:#fff; border:1px solid #e2e8f0; border-inline-start:4px solid #1e40af; border-radius:10px; padding:15px 17px; font-size:13px; max-width:540px; margin:0 auto 8px; }
.quote-blk .quote { margin:0 auto; max-width:620px; font-size:16px; font-weight:700; text-align:center; }
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; max-width:600px; margin:0 auto; }
.fake-input { background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:8px; padding:9px 12px; font-size:11.5px; color:#94a3b8; }
.news-row { display:flex; gap:9px; max-width:520px; margin:0 auto; }
.stats-blk { display:flex; justify-content:space-around; flex-wrap:wrap; gap:16px; background:linear-gradient(135deg,#0f172a,#1e3a8a); color:#fff; }
.stat { text-align:center; } .stat-n { font-size:24px; font-weight:800; color:#93c5fd; } .stat-l { font-size:11.5px; opacity:.85; }
.pbar { display:flex; align-items:center; gap:11px; margin-bottom:11px; font-size:12px; } .pbar span { flex:0 0 128px; }
.track { flex:1; height:8px; background:#e2e8f0; border-radius:8px; overflow:hidden; } .fill { height:100%; background:linear-gradient(90deg,#1e40af,#0ea5e9); border-radius:8px; }
.acc { background:#fff; border:1px solid #e2e8f0; border-radius:9px; padding:11px 14px; margin-bottom:8px; font-size:12.5px; display:flex; justify-content:space-between; align-items:center; max-width:600px; margin-inline:auto; }
.tabs-row { display:flex; gap:6px; justify-content:center; margin-bottom:12px; }
.tab { font-size:12px; padding:6px 16px; border-radius:8px; border:1px solid #e2e8f0; color:#64748b; }
.tab.cur { background:#1e40af; color:#fff; border-color:#1e40af; }
.tl { max-width:520px; margin:0 auto; }
.tl-item { display:flex; gap:11px; align-items:center; padding:8px 0; opacity:.45; font-size:12.5px; }
.tl-item.done, .tl-item.cur { opacity:1; }
.tl-dot { width:26px; height:26px; border-radius:50%; background:#e2e8f0; display:flex; align-items:center; justify-content:center; font-size:12px; color:#475569; flex:0 0 26px; }
.tl-item.done .tl-dot { background:#16a34a; color:#fff; }
.tl-item.cur .tl-dot { background:#2563eb; color:#fff; }
.steps-row { display:flex; gap:9px; align-items:center; justify-content:center; flex-wrap:wrap; }
.step { background:#fff; border:1px solid #e2e8f0; border-radius:11px; padding:12px 16px; text-align:center; }
.step-n { width:26px; height:26px; border-radius:50%; background:#1e40af; color:#fff; display:flex; align-items:center; justify-content:center; margin:0 auto 6px; font-size:13px; }
.step-t { font-size:11.5px; font-weight:700; } .step-arrow { color:#94a3b8; font-size:16px; }
.fake-map { height:160px; background:repeating-linear-gradient(45deg,#eef2ff,#eef2ff 12px,#e0e7ff 12px,#e0e7ff 24px); border-radius:12px; display:flex; align-items:center; justify-content:center; color:#1e40af; font-weight:700; }
.fake-logo-s { background:#fff; border:1px solid #e2e8f0; border-radius:9px; padding:12px; font-size:20px; text-align:center; }
.cta-blk { background:linear-gradient(135deg,#1e40af,#0ea5e9); color:#fff; text-align:center; }
.cta-num { font-size:23px; font-weight:800; margin-top:6px; letter-spacing:1px; }
.crumb { font-size:11.5px; color:#64748b; padding:10px 16px; background:#f8fafc; }
.blk-sep { border:none; border-top:1px solid #e2e8f0; margin:6px 0; }
.blk-spacer { background:repeating-linear-gradient(45deg,#f8fafc,#f8fafc 10px,#f1f5f9 10px,#f1f5f9 20px); }
.alert-demo { border-radius:10px; padding:11px 15px; font-size:12.5px; font-weight:600; }
.alert-demo.info { background:#eff6ff; color:#1d4ed8; } .alert-demo.warning { background:#fffbeb; color:#b45309; } .alert-demo.success { background:#f0fdf4; color:#15803d; }
.feat-list { display:flex; flex-direction:column; gap:11px; max-width:640px; margin:0 auto; }
.feat-row { display:flex; gap:12px; align-items:flex-start; }
.feat-ico { width:38px; height:38px; border-radius:10px; background:#eff6ff; display:flex; align-items:center; justify-content:center; font-size:18px; flex:0 0 38px; }
.feat-d { font-size:11.5px; color:#64748b; }
.price-table { max-width:600px; margin:0 auto; }
.price-row { display:flex; justify-content:space-between; padding:11px 16px; border-bottom:1px solid #e2e8f0; font-size:13px; background:#fff; }
.price-row:first-child { border-radius:11px 11px 0 0; } .price-row:last-child { border-radius:0 0 11px 11px; border-bottom:none; }
.price-row b { color:#1e40af; }
.sticky-cta-demo { display:flex; justify-content:space-between; align-items:center; position:relative; background:#0f172a; color:#fff; }
.footer-blk { background:#0f172a; color:#e2e8f0; }
.footer-blk .fake-nav { color:#94a3b8; justify-content:center; }
.soc-row { display:flex; gap:12px; justify-content:center; font-size:11px; color:#94a3b8; margin-top:9px; }
.crump-blk { text-align:center; font-size:11.5px; color:#64748b; background:#f8fafc; }
.pv-list { margin:0 20px 0 0; font-size:13px; line-height:2.2; color:#334155; }
@media (max-width:640px) { .c2,.c3,.c4,.c6,.form-grid { grid-template-columns:1fr 1fr; } .c6{grid-template-columns:repeat(3,1fr);} }
@media (max-width:420px) { .c2,.c3,.c4,.form-grid { grid-template-columns:1fr; } .fake-nav{display:none} }
`;
    const st = document.createElement('style');
    st.textContent = css;
    document.head.appendChild(st);
})();

/* ==================================================
 * 🧭 ناوبری مسیر تودرتو: '2' یا '2.cols.1.0'
 * ================================================== */
function resolveArray(path) {
    /* آرایه‌ای که فرزندهای path داخلش هستند */
    if (!path) { return layout; }
    const parts = path.split('.');
    /* اگر مسیر به .cols.N ختم شده → آن ستون */
    if (parts.length >= 2 && parts[parts.length - 2] === 'cols') {
        const node = resolveNode(parts.slice(0, parts.length - 2).join('.'));
        const colIdx = parseInt(parts[parts.length - 1], 10);
        return node && node.cols ? node.cols[colIdx] : null;
    }
    return layout;
}
function resolveNode(path) {
    if (!path) { return null; }
    const parts = path.split('.');
    let self = null, arr = layout;
    for (let i = 0; i < parts.length; i++) {
        if (parts[i] === 'cols') {
            const colIdx = parseInt(parts[i + 1], 10);
            if (!self || !Array.isArray(self.cols)) { return null; }
            arr = self.cols[colIdx];
            if (!Array.isArray(arr)) { return null; }
            self = null; /* داخل ستون؛ آیتم بعدی از arr */
            i++;
            continue;
        }
        const idx = parseInt(parts[i], 10);
        if (self !== null) { return null; }
        self = arr[idx];
        if (!self) { return null; }
    }
    return self;
}

/* ==================================================
 * 🖨 رندر بوم (سطح-بهدار — پشتیبانی ستون‌های تودرتو)
 * ================================================== */
function render() {
    const container = document.getElementById('canvas-blocks');
    container.innerHTML = '';
    document.getElementById('canvas-empty').style.display = layout.length ? 'none' : 'block';
    renderLevel(layout, container, '');
    document.getElementById('layout-json').value = JSON.stringify(layout);
}

function renderLevel(arr, container, prefix) {
    arr.forEach((item, i) => {
        const path = prefix ? prefix + '.' + i : String(i);
        const el = document.createElement('div');
        el.className = 'tb-block' + (selected === path ? ' selected' : '');
        el.dataset.path = path;
        el.draggable = true;

        const meta = BLOCK_META[item.block] || { label: item.block };
        const tools = document.createElement('div');
        tools.className = 'block-tools';
        tools.innerHTML = `
            <button type="button" onclick="event.stopPropagation();moveBlock('${path}',-1)" title="بالا">↑</button>
            <button type="button" onclick="event.stopPropagation();moveBlock('${path}',1)" title="پایین">↓</button>
            <button type="button" onclick="event.stopPropagation();duplicateBlock('${path}')" title="کپی">⧉</button>
            <button type="button" onclick="event.stopPropagation();previewBlockAt('${path}')" title="پیش‌نمایش">👁</button>
            <button type="button" onclick="event.stopPropagation();removeBlock('${path}')" title="حذف">✕</button>`;
        el.appendChild(tools);

        const label = document.createElement('div');
        label.className = 'tb-label';
        label.textContent = (item.props && item.props.title ? item.props.title + ' · ' : '') + meta.label;
        el.appendChild(label);

        if (item.block === 'section-columns' || item.block === 'section-split') {
            /* 🏛 کانتینر ستونی: رندر بلوک + مناطق رهاسازی ستون‌ها */
            const live = document.createElement('div');
            live.className = 'tb-live';
            live.innerHTML = blockHtml(item.block, item.props || {});
            el.appendChild(live);
            const colsWrap = live.querySelector('.tb-col-wrap');
            const colCount = item.block === 'section-split' ? 2 : Math.max(2, Math.min(4, parseInt((item.props || {}).columns || 2, 10)));
            if (!Array.isArray(item.cols) || item.cols.length !== colCount) {
                item.cols = Array.from({ length: colCount }, (_, c) => (item.cols && item.cols[c]) || []);
            }
            const colCells = colsWrap.querySelectorAll('.tb-col');
            item.cols.forEach((colArr, ci) => {
                const cell = colCells[ci];
                if (!cell) { return; }
                const dz = document.createElement('div');
                dz.className = 'tb-dropzone';
                dz.dataset.path = path + '.cols.' + ci;
                if (!colArr.length) {
                    dz.innerHTML = '<div class="tb-empty-hint">➕ بلوک را داخل ستون ' + (ci + 1) + ' رها کنید<br><small>یا دابل‌کلیک روی بلوک کتابخانه</small></div>';
                }
                attachDropzone(dz, path + '.cols.' + ci);
                renderLevel(colArr, dz, path + '.cols.' + ci);
                cell.appendChild(dz);
            });
        } else {
            const live = document.createElement('div');
            live.className = 'tb-live';
            live.innerHTML = blockHtml(item.block, item.props || {});
            el.appendChild(live);
        }

        /* درج بین بلوک‌ها */
        attachInsertDrop(el, path);

        el.addEventListener('click', e => { e.stopPropagation(); selectBlock(path); });
        el.addEventListener('dragstart', e => {
            e.dataTransfer.setData('text/plain', 'move:' + path);
            e.dataTransfer.effectAllowed = 'move';
            el.classList.add('dragging');
        });
        el.addEventListener('dragend', () => el.classList.remove('dragging'));

        container.appendChild(el);
    });
}

/* ناحیه رهاسازی ستون‌ها */
function attachDropzone(dz, colPath) {
    dz.addEventListener('dragover', e => { e.preventDefault(); e.stopPropagation(); dz.classList.add('drag-over'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('drag-over'));
    dz.addEventListener('drop', e => {
        e.preventDefault(); e.stopPropagation();
        dz.classList.remove('drag-over');
        const data = e.dataTransfer.getData('text/plain');
        const arr = resolveArray(colPath);
        if (!arr) { return; }
        if (data.startsWith('move:')) {
            movePathTo(data.slice(5), arr, arr.length);
        } else if (data.startsWith('new:')) {
            arr.push(makeBlock(data.slice(4)));
            selected = colPath + '.' + (arr.length - 1);
        }
        syncAndRender();
        renderProps();
    });
}

/* درج قبل از بلوک (بین بلوک‌های هم‌سطح) */
function attachInsertDrop(el, path) {
    el.addEventListener('dragover', e => {
        if (e.dataTransfer.types.includes('text/plain')) {
            e.preventDefault(); e.stopPropagation();
            el.style.outline = '2.5px dashed #2563eb';
        }
    });
    el.addEventListener('dragleave', () => { el.style.outline = ''; });
    el.addEventListener('drop', e => {
        e.preventDefault(); e.stopPropagation();
        el.style.outline = '';
        const rect = el.getBoundingClientRect();
        const after = (rect.top + rect.height / 2) < e.clientY;
        const data = e.dataTransfer.getData('text/plain');
        const parts = path.split('.');
        const idx = parseInt(parts[parts.length - 1], 10);
        const parentPath = parts.slice(0, parts.length - 1).join('.');
        const arr = resolveArray(parentPath);
        if (!arr) { return; }
        const target = after ? idx + 1 : idx;
        if (data.startsWith('move:')) {
            movePathTo(data.slice(5), arr, target);
        } else if (data.startsWith('new:')) {
            arr.splice(target, 0, makeBlock(data.slice(4)));
            selected = (parentPath ? parentPath + '.' : '') + target;
        }
        syncAndRender();
        renderProps();
    });
}

/* جابه‌جایی مسیر به آرایه مقصد (با حذف از مبدأ) */
function movePathTo(fromPath, targetArr, targetIdx) {
    const parts = fromPath.split('.');
    const idx = parseInt(parts[parts.length - 1], 10);
    const parentPath = parts.slice(0, parts.length - 1).join('.');
    const fromArr = resolveArray(parentPath);
    if (!fromArr) { return; }
    /* جابه‌جایی در همان آرایه */
    if (fromArr === targetArr) {
        const [moved] = fromArr.splice(idx, 1);
        const adj = idx < targetIdx ? targetIdx - 1 : targetIdx;
        targetArr.splice(adj, 0, moved);
        return;
    }
    const [moved] = fromArr.splice(idx, 1);
    targetArr.splice(Math.min(targetIdx, targetArr.length), 0, moved);
}

/* ساخت بلوک جدید با پیش‌فرض‌های کتابخانه */
function makeBlock(key) {
    const meta = BLOCK_META[key] || {};
    const props = Object.assign({ padding: 'default', background: 'default', visible: true }, (meta.defaults && typeof meta.defaults === 'object') ? JSON.parse(JSON.stringify(meta.defaults)) : {});
    const blk = { block: key, props };
    if (key === 'section-columns') { blk.cols = [[], []]; }
    if (key === 'section-split') { blk.cols = [[], []]; }
    return blk;
}

/* رها کردن بلوک جدید در سطح بوم */
const canvas = document.getElementById('canvas');
canvas.addEventListener('dragover', e => e.preventDefault());
canvas.addEventListener('drop', e => {
    e.preventDefault();
    const data = e.dataTransfer.getData('text/plain');
    if (data.startsWith('new:')) {
        layout.push(makeBlock(data.slice(4)));
        selected = String(layout.length - 1);
        syncAndRender();
        renderProps();
    }
});

/* کتابخانه: شروع درگ + دابل‌کلیک */
document.querySelectorAll('.block-item').forEach(item => {
    item.addEventListener('dragstart', e => e.dataTransfer.setData('text/plain', 'new:' + item.dataset.block));
    item.addEventListener('dblclick', () => {
        /* اگر بخش ستونی انتخاب است → داخل ستون آخر اضافه کن */
        const node = selected ? resolveNode(selected) : null;
        if (node && Array.isArray(node.cols)) {
            const colArr = node.cols[0];
            colArr.push(makeBlock(item.dataset.block));
            selected = selected + '.cols.0.' + (colArr.length - 1);
        } else {
            layout.push(makeBlock(item.dataset.block));
            selected = String(layout.length - 1);
        }
        syncAndRender();
        renderProps();
    });
});

/* انتخاب و ویژگی‌ها */
function selectBlock(path) {
    selected = path;
    render();
    renderProps();
}

const PROP_LABELS = {
    title: 'عنوان بخش', subtitle: 'زیرعنوان', text: 'متن', phone: 'شماره تماس',
    columns: 'تعداد ستون', height: 'ارتفاع فاصله (px)', alertType: 'نوع هشدار', sticky: 'چسبان',
};

function renderProps() {
    const panel = document.getElementById('props-content');
    const node = selected ? resolveNode(selected) : null;
    if (!node) {
        panel.innerHTML = '<div style="text-align:center;margin-top:26px">یک بلوک را در بوم انتخاب کنید.<br><br>🏛 برای چندستونه: «بخش چندستونی» اضافه کنید و بلوک‌ها را داخل ستون‌ها بیندازید.</div>';
        return;
    }
    const item = node;
    const meta = BLOCK_META[item.block] || { label: item.block };
    const props = item.props || {};
    let html = `<div style="font-weight:800;margin-bottom:12px;font-size:13px">📦 ${meta.label}</div>`;

    /* فیلدهای متنی مخصوص بلوک (عنوان/متن/زیرعنوان/تلفن) */
    const textFields = ['title', 'subtitle', 'phone'];
    textFields.forEach(f => {
        if (item.block === 'section-columns' && f === 'title') {
            html += textField(f, props[f] || '');
        } else if (['hero', 'hero-split', 'hero-slider', 'hero-video', 'hero-countdown', 'text', 'text-image', 'intro', 'rich-text', 'cta-request', 'cta-banner', 'alert-notice', 'quote', 'devices-grid', 'services-grid', 'features', 'articles-recent', 'articles-grid', 'team', 'pricing-table', 'faq-accordion', 'testimonials', 'counter-stats', 'gallery', 'map', 'tabs', 'timeline', 'steps-process', 'icon-list', 'cta-phone', 'video-embed', 'image-carousel', 'two-col', 'three-col', 'skill-bars', 'progress-bars'].includes(item.block)) {
            if (f === 'title') { html += textField(f, props[f] || ''); }
            if (f === 'subtitle' && ['hero'].includes(item.block)) { html += textField(f, props[f] || ''); }
            if (f === 'phone' && item.block === 'cta-phone') { html += textField(f, props[f] || ''); }
        }
    });
    /* متن بلوک متن/هشدار/نقل‌قول */
    if (['text', 'rich-text', 'alert-notice', 'quote'].includes(item.block)) {
        html += `<div class="form-group"><label>متن</label>
            <textarea class="form-control" rows="4" style="font-size:12px" oninput="setProp('${selected}','text',this.value)">${esc(props.text || '')}</textarea></div>`;
    }

    /* 🏛 تعداد ستون */
    if (item.block === 'section-columns') {
        html += `<div class="form-group"><label>🏛 تعداد ستون‌ها</label>
            <select class="form-control" style="font-size:12px" onchange="setProp('${selected}','columns',parseInt(this.value,10));rebuildCols('${selected}')">
                ${[2, 3, 4].map(n => `<option value="${n}" ${parseInt(props.columns || 2, 10) === n ? 'selected' : ''}>${n} ستون</option>`).join('')}
            </select></div>`;
    }
    if (item.block === 'spacer') {
        html += `<div class="form-group"><label>ارتفاع (px)</label>
            <input type="number" class="form-control" style="font-size:12px" value="${parseInt(props.height || 46, 10)}" min="8" max="240" onchange="setProp('${selected}','height',parseInt(this.value,10))"></div>`;
    }
    if (item.block === 'alert-notice') {
        html += `<div class="form-group"><label>نوع هشدار</label>
            <select class="form-control" style="font-size:12px" onchange="setProp('${selected}','alertType',this.value)">
                ${[['info', 'اطلاعیه آبی'], ['warning', 'هشدار زرد'], ['success', 'موفقیت سبز']].map(([v, l]) => `<option value="${v}" ${(props.alertType || 'info') === v ? 'selected' : ''}>${l}</option>`).join('')}
            </select></div>`;
    }

    /* عمومی‌ها */
    html += `
        <div class="form-group"><label>فاصله داخلی</label>
            <select class="form-control" style="font-size:12px" onchange="setProp('${selected}','padding',this.value)">
                ${['default', 'compact', 'roomy', 'none'].map(v => `<option value="${v}" ${props.padding === v ? 'selected' : ''}>${{ default: 'پیش‌فرض', compact: 'فشرده', roomy: 'جادار', none: 'بدون فاصله' }[v]}</option>`).join('')}
            </select></div>
        <div class="form-group"><label>پس‌زمینه</label>
            <select class="form-control" style="font-size:12px" onchange="setProp('${selected}','background',this.value)">
                ${['default', 'surface', 'primary', 'gradient', 'dark'].map(v => `<option value="${v}" ${props.background === v ? 'selected' : ''}>${{ default: 'معمولی', surface: 'کمرنگ', primary: 'رنگ اصلی', gradient: 'گرادیانت', dark: 'تیره' }[v]}</option>`).join('')}
            </select></div>
        <label class="form-check" style="font-size:12px"><input type="checkbox" ${props.visible !== false ? 'checked' : ''} onchange="setProp('${selected}','visible',this.checked)"> نمایش داده شود</label>
        ${item.block === 'header-v3' || item.block === 'header-v1' ? `<label class="form-check" style="font-size:12px"><input type="checkbox" ${props.sticky ? 'checked' : ''} onchange="setProp('${selected}','sticky',this.checked)}"> چسبان (Sticky)</label>` : ''}
        <hr style="border:none;border-top:1px solid var(--border);margin:13px 0">
        <button type="button" class="btn btn-danger btn-sm btn-block" onclick="removeBlock('${selected}')">🗑️ حذف بلوک</button>`;
    panel.innerHTML = html;
}

function textField(f, v) {
    return `<div class="form-group"><label>${PROP_LABELS[f] || f}</label>
        <input type="text" class="form-control" style="font-size:12px" value="${esc(v)}" oninput="setProp('${selected}','${f}',this.value)" placeholder="مثلاً خدمات برجسته"></div>`;
}

function setProp(path, key, value) {
    const node = resolveNode(path);
    if (!node) { return; }
    node.props = node.props || {};
    node.props[key] = value;
    syncAndRender();
}
function rebuildCols(path) {
    const node = resolveNode(path);
    if (!node) { return; }
    const n = Math.max(2, Math.min(4, parseInt(node.props.columns || 2, 10)));
    node.cols = Array.from({ length: n }, (_, c) => (node.cols && node.cols[c]) || []);
    syncAndRender();
    renderProps();
}

/* عملیات بلوک (مسیر-محور) */
function moveBlock(path, dir) {
    const parts = path.split('.');
    const idx = parseInt(parts[parts.length - 1], 10);
    const parentPath = parts.slice(0, parts.length - 1).join('.');
    const arr = resolveArray(parentPath);
    if (!arr) { return; }
    const j = idx + dir;
    if (j < 0 || j >= arr.length) { return; }
    [arr[idx], arr[j]] = [arr[j], arr[idx]];
    selected = (parentPath ? parentPath + '.' : '') + j;
    syncAndRender();
    renderProps();
}
function duplicateBlock(path) {
    const parts = path.split('.');
    const idx = parseInt(parts[parts.length - 1], 10);
    const parentPath = parts.slice(0, parts.length - 1).join('.');
    const arr = resolveArray(parentPath);
    if (!arr) { return; }
    arr.splice(idx + 1, 0, JSON.parse(JSON.stringify(arr[idx])));
    selected = (parentPath ? parentPath + '.' : '') + (idx + 1);
    syncAndRender();
    renderProps();
}
function removeBlock(path) {
    const parts = path.split('.');
    const idx = parseInt(parts[parts.length - 1], 10);
    const parentPath = parts.slice(0, parts.length - 1).join('.');
    const arr = resolveArray(parentPath);
    if (!arr) { return; }
    arr.splice(idx, 1);
    selected = '';
    syncAndRender();
    renderProps();
}
function clearLayout() {
    if (!layout.length || confirm('همه بلوک‌های بوم پاک شوند؟')) {
        layout = [];
        selected = '';
        syncAndRender();
        renderProps();
    }
}
function syncAndRender() {
    document.getElementById('layout-json').value = JSON.stringify(layout);
    render();
}

/* 🖥️ تغییر نمای دستگاه */
function setDevice(btn, device) {
    document.querySelectorAll('.builder .device-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const canvasEl = document.getElementById('canvas');
    canvasEl.style.maxWidth = device === 'desktop' ? '100%' : device === 'tablet' ? '768px' : '400px';
    canvasEl.style.margin = device === 'desktop' ? '0' : '0 auto';
}

/* 👁️ پیش‌نمایش زنده — رندر چیدمان فعلی در iframe با template-preview.php */
function openLivePreview() {
    const backdrop = document.getElementById('preview-backdrop');
    const frame = document.getElementById('preview-frame');
    frame.src = 'template-preview.php?json=' + encodeURIComponent(JSON.stringify(layout));
    backdrop.classList.add('show');
}
function closeLivePreview() {
    document.getElementById('preview-backdrop').classList.remove('show');
    document.getElementById('preview-frame').src = 'about:blank';
}
function previewSingleBlock(key) {
    const backdrop = document.getElementById('preview-backdrop');
    const frame = document.getElementById('preview-frame');
    frame.src = 'template-preview.php?block=' + encodeURIComponent(key);
    backdrop.classList.add('show');
}
function previewBlockAt(path) {
    const node = resolveNode(path);
    if (!node) { return; }
    const backdrop = document.getElementById('preview-backdrop');
    const frame = document.getElementById('preview-frame');
    frame.src = 'template-preview.php?json=' + encodeURIComponent(JSON.stringify([node]));
    backdrop.classList.add('show');
}
function setPreviewDevice(btn, width) {
    document.querySelectorAll('.preview-modal .device-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const frame = document.getElementById('preview-frame');
    frame.style.maxWidth = width > 0 ? width + 'px' : '100%';
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeLivePreview(); }
});

/* ==================================================
 * 🎨 اسکیل UI/UX Pro — طراحی خودکار + ممیزی UX
 * ================================================== */
const UIUX_CSRF = (document.querySelector('input[name="csrf_token"]') || {}).value || '';
const UIUX_SEVERITY_FA = { critical: '🔴 بحرانی', high: '🟠 مهم', medium: '🟡 متوسط', low: '🔵 جزئی' };

async function uiuxRequest(action, extra) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('page_type', (document.querySelector('select[name="page_type"]') || {}).value || 'home');
    fd.append('csrf_token', UIUX_CSRF);
    fd.append('layout_json', JSON.stringify(layout));
    if (extra) { Object.keys(extra).forEach(k => fd.append(k, extra[k])); }
    const res = await fetch('template-builder.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    return res.json();
}

function uiuxScoreBadge(score) {
    const color = score >= 85 ? '#16a34a' : score >= 70 ? '#2563eb' : score >= 50 ? '#d97706' : '#dc2626';
    return '<span style="display:inline-block;min-width:92px;text-align:center;background:' + color + ';color:#fff;border-radius:10px;padding:5px 12px;font-weight:800;font-size:16px">' + score + '/۱۰۰</span>';
}

function showUiuxPanel(title, html) {
    document.getElementById('uiux-panel-title').textContent = title;
    document.getElementById('uiux-panel-body').innerHTML = html;
    document.getElementById('uiux-panel').style.display = 'block';
    document.getElementById('uiux-panel').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
function closeUiuxPanel() {
    document.getElementById('uiux-panel').style.display = 'none';
}

/* 🪄 طراحی خودکار صفحه با اسکیل */
async function uiuxDesign() {
    const btn = document.getElementById('btn-uiux-design');
    const old = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> در حال طراحی...';
    try {
        const json = await uiuxRequest('uiux_design');
        if (!json.success) { sahandError('خطا: ' + (json.error || 'نامشخص')); return; }
        const d = json.data;
        const applyLayout = function () {
            layout = d.layout;
            selected = '';
            syncAndRender();
            renderProps();
        };
        if (!layout.length) {
            applyLayout();
        } else if (window.sahandConfirm) {
            const ok = await sahandConfirm({ title: 'جایگزینی چیدمان', message: 'چیدمان حرفه‌ای «' + (d.page_name_fa || '') + '» جایگزین چیدمان فعلی شود؟', type: 'question', confirmText: 'بله، جایگزین کن', confirmIcon: '🪄' });
            if (ok) { applyLayout(); }
        } else if (window.confirm('چیدمان حرفه‌ای «' + (d.page_name_fa || '') + '» جایگزین چیدمان فعلی شود؟')) {
            applyLayout();
        }
        let html = '<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px">' +
            uiuxScoreBadge(d.ux_score) +
            '<b>' + (d.grade_fa || '') + '</b>' +
            '<span style="color:var(--text-light);font-size:12px">هدف صفحه: ' + (d.goal_fa || '') + '</span></div>' +
            '<div style="font-size:12.5px;margin-bottom:6px"><b>💡 منطق طراحی (قوانین UX اعمال‌شده):</b></div><ul style="font-size:12.5px;margin:0 18px 8px 0;padding:0">';
        (d.rationale || []).forEach(r => { html += '<li style="margin-bottom:4px">' + r + '</li>'; });
        html += '</ul><div class="alert alert-info" style="margin:10px 0 0">💾 برای ذخیره، دکمه «ذخیره قالب» را بزنید. با «🔍 بررسی UX» می‌توانید چیدمان را ممیزی کنید.</div>';
        showUiuxPanel('✨ طراحی UI/UX Pro — ' + (d.page_name_fa || ''), html);
    } catch (err) {
        sahandError('خطای ارتباط با سرور — دوباره تلاش کنید');
    } finally {
        btn.disabled = false;
        btn.innerHTML = old;
    }
}

/* 🔍 ممیزی UX چیدمان فعلی */
async function uiuxReview() {
    if (!layout.length) { sahandError('اول حداقل یک بلوک به صفحه اضافه کنید یا از «✨ طراحی با UI/UX Pro» استفاده کنید.'); return; }
    const btn = document.getElementById('btn-uiux-review');
    const old = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> در حال بررسی...';
    try {
        const json = await uiuxRequest('uiux_review');
        if (!json.success) { sahandError('خطا: ' + (json.error || 'نامشخص')); return; }
        const d = json.data;
        let html = '<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px">' +
            uiuxScoreBadge(d.score) + '<b>' + (d.grade_fa || '') + '</b>' +
            '<span style="color:var(--text-light);font-size:12px">' + (d.stats ? d.stats.blocks : 0) + ' بخش • ' + (d.stats ? d.stats.cta_count : 0) + ' دکمه اقدام • ' + (d.stats ? d.stats.trust_count : 0) + ' سیگنال اعتماد</span></div>';
        if (d.wins && d.wins.length) {
            html += '<div style="font-size:12.5px;margin-bottom:4px"><b>✅ نقاط قوت:</b></div><ul style="font-size:12.5px;color:var(--success);margin:0 18px 10px 0;padding:0">';
            d.wins.forEach(w => { html += '<li style="margin-bottom:3px">' + w + '</li>'; });
            html += '</ul>';
        }
        if (d.issues && d.issues.length) {
            html += '<div style="font-size:12.5px;margin-bottom:4px"><b>⚠️ موارد قابل بهبود (به اولویت):</b></div><ul style="font-size:12.5px;margin:0 18px 10px 0;padding:0">';
            d.issues.forEach(i => { html += '<li style="margin-bottom:5px"><span class="badge badge-secondary" style="font-size:10.5px">' + (UIUX_SEVERITY_FA[i.severity] || i.severity) + '</span> ' + i.fa + '</li>'; });
            html += '</ul>';
        } else {
            html += '<div class="alert alert-success">🎉 مشکلی یافت نشد — چیدمان استانداردهای UX را رعایت می‌کند.</div>';
        }
        html += '<div style="text-align:center;margin-top:10px"><button type="button" class="btn btn-primary" onclick="uiuxImprove()">🛠️ اصلاح خودکار مشکلات</button></div>';
        showUiuxPanel('🔍 ممیزی UX — ' + (d.skill || ''), html);
    } catch (err) {
        sahandError('خطای ارتباط با سرور — دوباره تلاش کنید');
    } finally {
        btn.disabled = false;
        btn.innerHTML = old;
    }
}

/* 🛠️ اصلاح خودکار چیدمان */
async function uiuxImprove() {
    try {
        const json = await uiuxRequest('uiux_improve');
        if (!json.success) { sahandError('خطا: ' + (json.error || 'نامشخص')); return; }
        const d = json.data;
        layout = d.layout;
        selected = '';
        syncAndRender();
        renderProps();
        let html = '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px">' +
            '<span style="font-weight:800;font-size:13px">' + d.score_before + '</span><span>→</span>' + uiuxScoreBadge(d.score_after) +
            '<b>' + (d.grade_fa || '') + '</b></div><div style="font-size:12.5px;margin-bottom:4px"><b>🔧 تغییرات اعمال‌شده:</b></div><ul style="font-size:12.5px;margin:0 18px 8px 0;padding:0">';
        (d.changes || []).forEach(c => { html += '<li style="margin-bottom:4px">' + c + '</li>'; });
        html += '</ul><div class="alert alert-info" style="margin:8px 0 0">💾 برای ذخیره، دکمه «ذخیره قالب» را بزنید.</div>';
        showUiuxPanel('🛠️ اصلاح خودکار UI/UX Pro', html);
    } catch (err) {
        sahandError('خطای ارتباط با سرور — دوباره تلاش کنید');
    }
}

/* شروع */
render();
renderProps();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
