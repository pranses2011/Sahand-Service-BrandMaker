<?php
/**
 * 🎭 قالب‌ساز درگ‌اند‌دراپ
 * ==========================
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
    Auth::enforceCsrf();
    $id = (int)post('template_id');
    $name = post('name') ?: 'قالب بدون نام';
    $pageType = post('page_type') ?: 'home';
    $layoutJson = (string)($_POST['layout_json'] ?? '[]');
    // اعتبارسنجی JSON
    json_decode($layoutJson);
    if (json_last_error() !== JSON_ERROR_NONE) {
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

/* 📚 کتابخانه بلوک‌ها — ۱۵ دسته مطابق سند */
$blockLibrary = [
    'هدر' => [
        'header-v1' => ['📐', 'هدر ساده (لوگو + منو)'],
        'header-v2' => ['📐', 'هدر با نوار تماس'],
        'header-v3' => ['📐', 'هدر شیشه‌ای چسبان'],
        'top-bar' => ['📏', 'نوار بالایی (تلفن + ساعات)'],
    ],
    'هیرو' => [
        'hero' => ['🦸', 'هیرو متن + دکمه'],
        'hero-slider' => ['🦸', 'اسلایدر تصویری'],
        'hero-split' => ['🦸', 'هیرو دو بخشی'],
        'hero-video' => ['🦸', 'هیرو با پس‌زمینه تصویر'],
    ],
    'محتوا' => [
        'text' => ['📝', 'متن آزاد'],
        'text-image' => ['📝', 'متن + تصویر'],
        'two-col' => ['📋', 'دو ستونه'],
        'three-col' => ['📋', 'سه ستونه'],
        'intro' => ['📋', 'معرفی کوتاه برند'],
    ],
    'کارت‌ها' => [
        'services-grid' => ['🃏', 'کارت‌های خدمات'],
        'articles-recent' => ['🃏', 'کارت‌های مقالات اخیر'],
        'features' => ['🃏', 'کارت‌های چرا ما'],
        'team' => ['🃏', 'کارت تیم'],
    ],
    'فرم' => [
        'contact-form' => ['📝', 'فرم تماس'],
        'request-form' => ['📝', 'فرم درخواست خدمات'],
    ],
    'آمار' => [
        'counter-stats' => ['📊', 'شمارنده‌ها'],
        'progress-bars' => ['📊', 'نوارهای پیشرفت'],
    ],
    'تعامل' => [
        'testimonials' => ['💬', 'اسلایدر نظرات'],
        'faq-accordion' => ['❓', 'آکاردئون سوالات'],
    ],
    'رسانه' => [
        'gallery' => ['🖼️', 'گالری تصاویر'],
        'map' => ['📍', 'نقشه محدوده (تصویری)'],
        'brands-links' => ['🏷️', 'لوگوی برندها'],
    ],
    'فراخوان' => [
        'cta-phone' => ['📞', 'CTA تماس بزرگ'],
        'cta-request' => ['🔗', 'CTA ثبت درخواست'],
        'cta-banner' => ['🔗', 'بنر فراخوان'],
    ],
    'ساختار' => [
        'breadcrumb' => ['🧭', 'مسیر راهنما (Breadcrumb)'],
        'separator' => ['⬜', 'جداکننده'],
        'spacer' => ['⬜', 'فاصله'],
    ],
];
?>
<link rel="stylesheet" href="<?= asset_ver('assets/css/builder.css') ?>">

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
            <div style="margin-inline-start:auto;display:flex;gap:8px">
                <button type="button" class="btn btn-info" onclick="openLivePreview()">👁️ پیش‌نمایش زنده</button>
                <a href="templates.php" class="btn btn-outline">بازگشت</a>
                <button type="submit" class="btn btn-primary">💾 ذخیره قالب</button>
            </div>
        </div>
    </div>

    <div class="builder">
        <!-- 📚 کتابخانه بلوک -->
        <aside class="block-library">
            <div class="block-lib-title">📚 بلوک‌ها را بکشید ↓</div>
            <?php foreach ($blockLibrary as $category => $blocks): ?>
                <div class="block-cat"><?= e($category) ?></div>
                <?php foreach ($blocks as $key => [$icon, $label]): ?>
                    <div class="block-item" draggable="true" data-block="<?= e($key) ?>" title="دابل‌کلیک = افزودن سریع | دکمه 👁 = پیش‌نمایش تک‌بلوک">
                        <span class="icon"><?= $icon ?></span>
                        <span><?= e($label) ?></span>
                        <button type="button" class="block-eye" title="پیش‌نمایش این بلوک" onclick="event.stopPropagation();previewSingleBlock('<?= e($key) ?>')">👁</button>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </aside>

        <!-- 🎨 بوم -->
        <section class="builder-canvas" id="canvas" style="<?= $device ?? '' ?>">
            <div class="canvas-empty" id="canvas-empty" <?= $layout ? 'style="display:none"' : '' ?>>
                <div style="font-size:48px;margin-bottom:10px">🎭</div>
                بلوک‌ها را از پنل راست بکشید و اینجا رها کنید<br>
                <small>برای تغییر ترتیب، بلوک‌ها را روی هم بکشید</small>
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
/* 🎭 موتور قالب‌ساز — درگ‌اند‌دراپ بومی + مدیریت چیدمان */
const BLOCK_LIBRARY = <?= json_encode(array_map(function ($cats) {
    $flat = [];
    foreach ($cats as $key => $meta) { $flat[$key] = $meta[1]; }
    return $flat;
}, $blockLibrary), JSON_UNESCAPED_UNICODE) ?>;

let layout = JSON.parse(document.getElementById('layout-json').value || '[]');
let selectedIdx = -1;

/* 🖼️ وایرفریم مینی هر بلوک — پیش‌نمایش بصری داخل بوم */
const WIREFRAMES = {
    'header-v1': '<div class="wf-row"><span class="wf-logo">LOGO</span><span class="wf-line w40"></span><span class="wf-btn"></span></div>',
    'header-v2': '<div class="wf-col"><div class="wf-line w60"></div><div class="wf-row"><span class="wf-logo">LOGO</span><span class="wf-line w40"></span><span class="wf-btn"></span></div></div>',
    'header-v3': '<div class="wf-row glassy"><span class="wf-logo">LOGO</span><span class="wf-line w40"></span><span class="wf-btn"></span></div>',
    'top-bar': '<div class="wf-row tiny"><span class="wf-line w25"></span><span class="wf-line w25"></span></div>',
    'hero': '<div class="wf-hero"><div class="wf-line w50 center"></div><div class="wf-line w35 center"></div><div class="wf-btn"></div></div>',
    'hero-slider': '<div class="wf-hero"><div class="wf-line w50 center"></div><div class="wf-dots">● ○ ○</div></div>',
    'hero-split': '<div class="wf-row"><div class="wf-col"><div class="wf-line w80"></div><div class="wf-line w60"></div><div class="wf-btn"></div></div><div class="wf-img"></div></div>',
    'hero-video': '<div class="wf-hero"><div class="wf-play">▶</div></div>',
    'text': '<div class="wf-line w90"></div><div class="wf-line w100"></div><div class="wf-line w70"></div>',
    'text-image': '<div class="wf-row"><div class="wf-col"><div class="wf-line w100"></div><div class="wf-line w80"></div></div><div class="wf-img"></div></div>',
    'intro': '<div class="wf-row"><div class="wf-col"><div class="wf-line w90"></div><div class="wf-line w70"></div></div><div class="wf-img"></div></div>',
    'two-col': '<div class="wf-row"><div class="wf-box"></div><div class="wf-box"></div></div>',
    'three-col': '<div class="wf-row"><div class="wf-box"></div><div class="wf-box"></div><div class="wf-box"></div></div>',
    'services-grid': '<div class="wf-row"><div class="wf-box ico">🔧</div><div class="wf-box ico">🛠️</div><div class="wf-box ico">⚡</div></div>',
    'features': '<div class="wf-row"><div class="wf-box ico">✅</div><div class="wf-box ico">🏆</div><div class="wf-box ico">🛡️</div></div>',
    'articles-recent': '<div class="wf-row"><div class="wf-box img">📰</div><div class="wf-box img">📰</div><div class="wf-box img">📰</div></div>',
    'articles-grid': '<div class="wf-row"><div class="wf-box img">📰</div><div class="wf-box img">📰</div><div class="wf-box img">📰</div></div>',
    'team': '<div class="wf-row"><div class="wf-box ava">👤</div><div class="wf-box ava">👤</div><div class="wf-box ava">👤</div><div class="wf-box ava">👤</div></div>',
    'contact-form': '<div class="wf-row"><div class="wf-input"></div><div class="wf-input"></div></div><div class="wf-btn full"></div>',
    'request-form': '<div class="wf-row"><div class="wf-input"></div><div class="wf-input"></div></div><div class="wf-btn full"></div>',
    'counter-stats': '<div class="wf-row"><div class="wf-stat"></div><div class="wf-stat"></div><div class="wf-stat"></div></div>',
    'progress-bars': '<div class="wf-line w30"></div><div class="wf-track"><div class="wf-fill" style="width:85%"></div></div><div class="wf-line w30"></div><div class="wf-track"><div class="wf-fill" style="width:70%"></div></div>',
    'testimonials': '<div class="wf-quote"></div><div class="wf-dots">● ○ ○</div>',
    'faq-accordion': '<div class="wf-acc"></div><div class="wf-acc"></div>',
    'gallery': '<div class="wf-row"><div class="wf-img small">🖼️</div><div class="wf-img small">🖼️</div><div class="wf-img small">🖼️</div><div class="wf-img small">🖼️</div></div>',
    'map': '<div class="wf-map">📍</div>',
    'brands-links': '<div class="wf-row"><div class="wf-box">🏷️</div><div class="wf-box">🏷️</div><div class="wf-box">🏷️</div><div class="wf-box">🏷️</div></div>',
    'cta-phone': '<div class="wf-hero"><div class="wf-btn big"></div></div>',
    'cta-request': '<div class="wf-hero"><div class="wf-btn"></div></div>',
    'cta-banner': '<div class="wf-hero"><div class="wf-line w60 center"></div><div class="wf-btn"></div></div>',
    'breadcrumb': '<div class="wf-row tiny"><span class="wf-line w15"></span>/<span class="wf-line w15"></span>/<span class="wf-line w20 strong"></span></div>',
    'separator': '<div class="wf-sep"></div>',
    'spacer': '<div class="wf-spacer"></div>',
    'pagination': '<div class="wf-row center"><span class="wf-pg cur"></span><span class="wf-pg"></span><span class="wf-pg"></span></div>',
};

/* رندر بوم */
function render() {
    const container = document.getElementById('canvas-blocks');
    container.innerHTML = '';
    document.getElementById('canvas-empty').style.display = layout.length ? 'none' : 'block';
    layout.forEach((item, i) => {
        const el = document.createElement('div');
        el.className = 'canvas-block' + (i === selectedIdx ? ' selected' : '');
        el.draggable = true;
        el.dataset.idx = i;
        const wf = WIREFRAMES[item.block] || '<div class="wf-line w80"></div><div class="wf-line w60"></div>';
        el.innerHTML = `
            <div class="block-tools">
                <button type="button" onclick="moveBlock(${i},-1)" title="بالا">↑</button>
                <button type="button" onclick="moveBlock(${i},1)" title="پایین">↓</button>
                <button type="button" onclick="duplicateBlock(${i})" title="کپی">⧉</button>
                <button type="button" onclick="previewBlockAt(${i})" title="پیش‌نمایش">👁</button>
                <button type="button" onclick="removeBlock(${i})" title="حذف">✕</button>
            </div>
            <div class="block-label">📦 ${BLOCK_LIBRARY[item.block] || item.block}${item.props && item.props.title ? ' — ' + item.props.title : ''}</div>
            <div class="block-preview">${wf}</div>`;
        // رویدادها
        el.addEventListener('dragstart', e => { e.dataTransfer.setData('text/plain', 'move:' + i); el.classList.add('dragging'); });
        el.addEventListener('dragend', () => el.classList.remove('dragging'));
        el.addEventListener('dragover', e => { e.preventDefault(); el.classList.add('drop-above'); });
        el.addEventListener('dragleave', () => el.classList.remove('drop-above'));
        el.addEventListener('drop', e => {
            e.preventDefault(); el.classList.remove('drop-above');
            const data = e.dataTransfer.getData('text/plain');
            if (data.startsWith('move:')) {
                const from = parseInt(data.slice(5));
                const [moved] = layout.splice(from, 1);
                layout.splice(from < i ? i - 1 : i, 0, moved);
            } else if (data.startsWith('new:')) {
                layout.splice(i, 0, makeBlock(data.slice(4)));
            }
            syncAndRender();
        });
        el.addEventListener('click', () => selectBlock(i));
        container.appendChild(el);
    });
    document.getElementById('layout-json').value = JSON.stringify(layout);
}

/* ساخت بلوک جدید با ویژگی‌های پیش‌فرض */
function makeBlock(key) {
    return { block: key, props: { padding: 'default', background: 'default', visible: true } };
}

/* رها کردن بلوک جدید در بوم */
const canvas = document.getElementById('canvas');
canvas.addEventListener('dragover', e => e.preventDefault());
canvas.addEventListener('drop', e => {
    e.preventDefault();
    const data = e.dataTransfer.getData('text/plain');
    if (data.startsWith('new:')) {
        layout.push(makeBlock(data.slice(4)));
        selectedIdx = layout.length - 1;
        syncAndRender();
        renderProps();
    }
});

/* کتابخانه: شروع درگ */
document.querySelectorAll('.block-item').forEach(item => {
    item.addEventListener('dragstart', e => e.dataTransfer.setData('text/plain', 'new:' + item.dataset.block));
    // 🖱️ دابل‌کلیک = افزودن سریع
    item.addEventListener('dblclick', () => {
        layout.push(makeBlock(item.dataset.block));
        selectedIdx = layout.length - 1;
        syncAndRender();
        renderProps();
    });
});

/* انتخاب بلوک و نمایش ویژگی‌ها */
function selectBlock(i) {
    selectedIdx = i;
    render();
    renderProps();
}

function renderProps() {
    const panel = document.getElementById('props-content');
    if (selectedIdx < 0 || !layout[selectedIdx]) {
        panel.innerHTML = 'یک بلوک را در بوم انتخاب کنید.';
        return;
    }
    const item = layout[selectedIdx];
    const props = item.props || {};
    panel.innerHTML = `
        <div style="font-weight:700;margin-bottom:10px;font-size:13px">📦 ${BLOCK_LIBRARY[item.block] || item.block}</div>
        <div class="form-group"><label>فاصله داخلی</label>
            <select class="form-control" style="font-size:12px" onchange="setProp('padding', this.value)">
                ${['default','compact','roomy','none'].map(v => `<option value="${v}" ${props.padding===v?'selected':''}>${{default:'پیش‌فرض',compact:'فشرده',roomy:'جادار',none:'بدون فاصله'}[v]}</option>`).join('')}
            </select>
        </div>
        <div class="form-group"><label>پس‌زمینه</label>
            <select class="form-control" style="font-size:12px" onchange="setProp('background', this.value)">
                ${['default','surface','primary','gradient','dark'].map(v => `<option value="${v}" ${props.background===v?'selected':''}>${{default:'معمولی',surface:'کمرنگ',primary:'رنگ اصلی',gradient:'گرادیانت',dark:'تیره'}[v]}</option>`).join('')}
            </select>
        </div>
        <div class="form-group"><label>عنوان سفارشی بخش (اختیاری)</label>
            <input type="text" class="form-control" style="font-size:12px" value="${props.title || ''}" oninput="setProp('title', this.value)" placeholder="مثلاً خدمات برجسته">
        </div>
        <label class="form-check" style="font-size:12px"><input type="checkbox" ${props.visible !== false ? 'checked' : ''} onchange="setProp('visible', this.checked)"> نمایش داده شود</label>
        <hr style="border:none;border-top:1px solid var(--border);margin:14px 0">
        <button type="button" class="btn btn-danger btn-sm btn-block" onclick="removeBlock(selectedIdx)">🗑️ حذف بلوک</button>`;
}

function setProp(key, value) {
    if (selectedIdx >= 0 && layout[selectedIdx]) {
        layout[selectedIdx].props = layout[selectedIdx].props || {};
        layout[selectedIdx].props[key] = value;
        document.getElementById('layout-json').value = JSON.stringify(layout);
    }
}

/* عملیات بلوک */
function moveBlock(i, dir) {
    const j = i + dir;
    if (j < 0 || j >= layout.length) return;
    [layout[i], layout[j]] = [layout[j], layout[i]];
    selectedIdx = j;
    syncAndRender();
}
function duplicateBlock(i) {
    layout.splice(i + 1, 0, JSON.parse(JSON.stringify(layout[i])));
    syncAndRender();
}
function removeBlock(i) {
    layout.splice(i, 1);
    selectedIdx = -1;
    syncAndRender();
    renderProps();
}
function syncAndRender() {
    document.getElementById('layout-json').value = JSON.stringify(layout);
    render();
}

/* 🖥️ تغییر نمای دستگاه */
function setDevice(btn, device) {
    document.querySelectorAll('.device-tab').forEach(b => b.classList.remove('active'));
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
/* پیش‌نمایش تک بلوک از کتابخانه */
function previewSingleBlock(key) {
    const backdrop = document.getElementById('preview-backdrop');
    const frame = document.getElementById('preview-frame');
    frame.src = 'template-preview.php?block=' + encodeURIComponent(key);
    backdrop.classList.add('show');
}
/* پیش‌نمایش از روی بوم */
function previewBlockAt(i) {
    if (!layout[i]) { return; }
    const backdrop = document.getElementById('preview-backdrop');
    const frame = document.getElementById('preview-frame');
    frame.src = 'template-preview.php?json=' + encodeURIComponent(JSON.stringify([layout[i]]));
    backdrop.classList.add('show');
}
/* تغییر دستگاه پیش‌نمایش (عرض iframe) */
function setPreviewDevice(btn, width) {
    document.querySelectorAll('.preview-modal .device-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const frame = document.getElementById('preview-frame');
    frame.style.maxWidth = width > 0 ? width + 'px' : '100%';
}
/* بستن با Escape */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeLivePreview(); }
});

/* شروع */
render();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
