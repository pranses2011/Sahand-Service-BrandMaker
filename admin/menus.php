<?php
/**
 * ☰ مدیریت منوها — v2.12 حرفه‌ای
 * ==============================================
 * درگ‌اند‌دراپ + زیرمنو + آیکون + nofollow + افزودن سریع صفحات سیستمی
 * + کپی منو از برند دیگر + پیش‌نمایش زنده + انتخابگر آیکون
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::enforceCsrf();
    $action = post('action');

    /* 💾 ذخیره ترتیب و ساختار منو (از JSON درگ‌اند‌دراپ) */
    if ($action === 'save_menu') {
        $brandId = (int)post('brand_id') ?: null;
        $location = post('location') === 'footer' ? 'footer' : 'header';
        $menuJson = (string)($_POST['menu_json'] ?? '[]');
        $items = json_decode($menuJson, true);
        if (!is_array($items)) {
            $items = [];
        }
        // حذف و بازسازی منو
        $menu = $db->fetch('SELECT id FROM menus WHERE brand_id <=> ? AND location = ?', [$brandId, $location]);
        if ($menu) {
            $db->delete('menu_items', 'menu_id = ?', [$menu['id']]);
            $menuId = (int)$menu['id'];
        } else {
            $menuId = $db->insert('menus', [
                'brand_id' => $brandId, 'location' => $location,
                'name' => ($location === 'header' ? 'منوی اصلی' : 'منوی فوتر') . ($brandId ? '' : ' (عمومی)'),
            ]);
        }
        $insertItem = function (array $item, int $parentId = null, int &$order = 0) use ($db, $menuId, &$insertItem) {
            $id = $db->insert('menu_items', [
                'menu_id'   => $menuId,
                'parent_id' => $parentId,
                'title'     => clean_input($item['title'] ?? ''),
                'url'       => clean_input($item['url'] ?? '') ?: null,
                'page_type' => clean_input($item['page_type'] ?? '') ?: null,
                'icon'      => clean_input($item['icon'] ?? '') ?: null,
                'nofollow'  => !empty($item['nofollow']) ? 1 : 0,
                'is_active' => !isset($item['is_active']) || $item['is_active'] ? 1 : 0,
                'sort_order'=> $order++,
            ]);
            foreach ((array)($item['children'] ?? []) as $child) {
                $insertItem($child, $id, $order);
            }
        };
        $order = 0;
        foreach ($items as $item) {
            $insertItem($item, null, $order);
        }
        (new Cache())->delete('menu_*');
        flash('success', '✅ منو ذخیره شد (' . count($items) . ' آیتم اصلی).');
        redirect('menus.php?brand=' . ((int)post('brand_id')) . '&location=' . $location);
    }

    /* 🌱 بازنشانی به منوی پیش‌فرض */
    if ($action === 'reset_menu') {
        $brandId = (int)post('brand_id') ?: null;
        $location = post('location') === 'footer' ? 'footer' : 'header';
        $menu = $db->fetch('SELECT id FROM menus WHERE brand_id <=> ? AND location = ?', [$brandId, $location]);
        if ($menu) {
            $db->delete('menu_items', 'menu_id = ?', [$menu['id']]);
        }
        seedDefaultMenu($db, $brandId, $location);
        (new Cache())->delete('menu_*');
        flash('success', '🌱 منوی پیش‌فرض بازسازی شد.');
        redirect('menus.php?brand=' . ((int)post('brand_id')) . '&location=' . $location);
    }

    /* 📋 v2.12: کپی منو از برند دیگر (یا منوی عمومی) */
    if ($action === 'copy_menu') {
        $brandId = (int)post('brand_id') ?: null;
        $sourceBrandId = (int)post('source_brand_id') ?: null;
        $location = post('location') === 'footer' ? 'footer' : 'header';
        $source = $db->fetch('SELECT id FROM menus WHERE brand_id <=> ? AND location = ?', [$sourceBrandId, $location]);
        if (!$source) {
            flash('danger', 'منوی مبدأ یافت نشد — ابتدا آن را بسازید.');
            redirect('menus.php?brand=' . ((int)post('brand_id')) . '&location=' . $location);
        }
        // خواندن درخت منبع
        $srcItems = $db->fetchAll('SELECT * FROM menu_items WHERE menu_id = ? ORDER BY sort_order, id', [$source['id']]);
        $srcByParent = [];
        foreach ($srcItems as $si) {
            $srcByParent[$si['parent_id']][] = $si;
        }
        // بازسازی مقصد
        $menu = $db->fetch('SELECT id FROM menus WHERE brand_id <=> ? AND location = ?', [$brandId, $location]);
        if ($menu) {
            $db->delete('menu_items', 'menu_id = ?', [$menu['id']]);
            $menuId = (int)$menu['id'];
        } else {
            $menuId = $db->insert('menus', [
                'brand_id' => $brandId, 'location' => $location,
                'name' => ($location === 'header' ? 'منوی اصلی' : 'منوی فوتر') . ($brandId ? '' : ' (عمومی)'),
            ]);
        }
        $copied = 0;
        $copyBranch = function (?int $parentId, int $newParent) use (&$copyBranch, $db, $srcByParent, $menuId, &$copied) {
            foreach (($srcByParent[$parentId] ?? []) as $si) {
                $newId = $db->insert('menu_items', [
                    'menu_id'   => $menuId,
                    'parent_id' => $newParent,
                    'title'     => $si['title'],
                    'url'       => $si['url'],
                    'page_type' => $si['page_type'],
                    'icon'      => $si['icon'],
                    'nofollow'  => (int)$si['nofollow'],
                    'is_active' => (int)$si['is_active'],
                    'sort_order'=> (int)$si['sort_order'],
                ]);
                $copied++;
                $copyBranch((int)$si['id'], (int)$newId);
            }
        };
        $copyBranch(null, 0);
        (new Cache())->delete('menu_*');
        flash('success', '📋 ' . en_to_fa_digits((string)$copied) . ' آیتم منو کپی شد.');
        redirect('menus.php?brand=' . ((int)post('brand_id')) . '&location=' . $location);
    }
}

/**
 * 🌱 ساخت منوی پیش‌فرض برای برند
 */
function seedDefaultMenu(Database $db, ?int $brandId, string $location): void
{
    $menu = $db->fetch('SELECT id FROM menus WHERE brand_id <=> ? AND location = ?', [$brandId, $location]);
    if ($menu) {
        $menuId = (int)$menu['id'];
    } else {
        $menuId = $db->insert('menus', [
            'brand_id' => $brandId, 'location' => $location,
            'name' => ($location === 'header' ? 'منوی اصلی' : 'منوی فوتر') . ($brandId ? '' : ' (عمومی)'),
        ]);
    }
    $defaults = $location === 'header'
        ? [
            ['🏠 صفحه اصلی', 'home', '🏠'], ['🔧 خدمات', 'services', '🔧'], ['🚨 کدهای خطا', 'error-codes', '🚨'],
            ['📰 مقالات', 'blog', '📰'], ['🏢 درباره ما', 'about-agency', '🏢'], ['📞 تماس', 'contact', '📞'],
        ]
        : [
            ['📜 قوانین و مقررات', 'terms', ''], ['🔒 حریم خصوصی', 'privacy', ''], ['🗺️ نقشه سایت', 'sitemap-page', ''],
        ];
    foreach ($defaults as $i => [$title, $pageType, $icon]) {
        $db->insert('menu_items', [
            'menu_id' => $menuId, 'title' => $title, 'page_type' => $pageType,
            'icon' => $icon ?: null, 'is_active' => 1, 'sort_order' => $i,
        ]);
    }
}

$pageTitle = 'مدیریت منوها';
$activeMenu = 'menus';
require __DIR__ . '/includes/header.php';

$brands = $db->fetchAll('SELECT id, name_fa FROM brands ORDER BY name_fa');
$selectedBrand = (int)get_param('brand');
$selectedLocation = get_param('location', 'header') === 'footer' ? 'footer' : 'header';

/* 🌱 منوی پیش‌فرض در صورت نبود */
$menuBrandId = $selectedBrand ?: null;
$menu = $db->fetch('SELECT id FROM menus WHERE brand_id <=> ? AND location = ?', [$menuBrandId, $selectedLocation]);
if (!$menu) {
    seedDefaultMenu($db, $menuBrandId, $selectedLocation);
    $menu = $db->fetch('SELECT id FROM menus WHERE brand_id <=> ? AND location = ?', [$menuBrandId, $selectedLocation]);
}

/* 🌳 ساختار درختی منو */
$allItems = $db->fetchAll('SELECT * FROM menu_items WHERE menu_id = ? ORDER BY sort_order, id', [$menu['id']]);
$tree = [];
$byParent = [];
$totalItems = 0;
foreach ($allItems as $item) {
    $totalItems++;
    $byParent[$item['parent_id']][] = $item;
}
$buildTree = function (?int $parentId) use (&$buildTree, $byParent) {
    $result = [];
    foreach (($byParent[$parentId] ?? []) as $item) {
        $result[] = [
            'id' => (int)$item['id'],
            'title' => $item['title'],
            'url' => $item['url'],
            'page_type' => $item['page_type'],
            'icon' => $item['icon'],
            'nofollow' => (int)$item['nofollow'],
            'is_active' => (int)$item['is_active'],
            'children' => $buildTree((int)$item['id']),
        ];
    }
    return $result;
};
$menuTree = $buildTree(null);

/* 📋 برندهای دارای منو برای کپی */
$copySources = $db->fetchAll(
    "SELECT b.id, b.name_fa FROM menus m JOIN brands b ON b.id = m.brand_id
     WHERE m.location = ? AND m.brand_id IS NOT NULL AND m.brand_id != ? ORDER BY b.name_fa",
    [$selectedLocation, $selectedBrand]
);

$pageTypes = [
    'home' => 'صفحه اصلی', 'services' => 'خدمات', 'service-area' => 'محدوده خدمات', 'warranty' => 'ضمانت',
    'blog' => 'مقالات', 'about-agency' => 'درباره نمایندگی', 'about-brand' => 'درباره برند',
    'contact' => 'تماس با ما', 'request' => 'ثبت درخواست', 'other-brands' => 'سایر برندها',
    'error-codes' => 'کدهای خطا', 'faq' => 'سوالات متداول', 'terms' => 'قوانین', 'privacy' => 'حریم خصوصی',
    'sitemap-page' => 'نقشه سایت',
];

/* 🎨 آیکون‌های پیشنهادی انتخابگر */
$iconPalette = [
    '🏠', '🔧', '🚨', '📰', '🏢', '📞', '📋', '🔒', '📜', '🗺️', '❓', '💬', '📍', '⭐', '⚡', '🛡️',
    '💰', '🏷️', '🧰', '🔧', '🧊', '🌀', '❄️', '📺', '♨️', '📻', '🔥', '🍽️', '🧺', '💡', '🔌', '🔋',
    '⚙️', '🚀', '✅', '⚠️', '🎁', '🎓', '📈', '📊', '🕐', '📅', '✉️', '📌', '🔗', '🌐', '📱', '💻',
];
?>
<form method="post" id="menu-form">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="save_menu">
    <input type="hidden" name="brand_id" value="<?= $selectedBrand ?>">
    <input type="hidden" name="location" value="<?= e($selectedLocation) ?>">
    <input type="hidden" name="menu_json" id="menu-json">

    <div class="card" style="margin-bottom:16px">
        <div class="card-body" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:14px 18px">
            <select class="form-control" style="max-width:200px" onchange="location='menus.php?brand='+this.value+'&location=<?= e($selectedLocation) ?>'">
                <option value="0">🌐 منوی عمومی (پیش‌فرض برندهای جدید)</option>
                <?php foreach ($brands as $brand): ?>
                    <option value="<?= (int)$brand['id'] ?>" <?= $selectedBrand === (int)$brand['id'] ? 'selected' : '' ?>>🏷️ <?= e($brand['name_fa']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="device-tabs">
                <button type="button" class="device-tab <?= $selectedLocation === 'header' ? 'active' : '' ?>" onclick="location='menus.php?brand=<?= $selectedBrand ?>&location=header'">🔝 هدر</button>
                <button type="button" class="device-tab <?= $selectedLocation === 'footer' ? 'active' : '' ?>" onclick="location='menus.php?brand=<?= $selectedBrand ?>&location=footer'">🔻 فوتر</button>
            </div>
            <span class="badge badge-info"> <?= en_to_fa_digits((string)$totalItems) ?> آیتم</span>
            <div style="margin-inline-start:auto;display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="btn btn-outline" onclick="addMenuItem()" title="افزودن آیتم خالی جدید">➕ آیتم جدید</button>
                <button type="button" class="btn btn-outline" onclick="addMissingPages()" title="همه صفحات سیستمی که در منو نیستند یکجا اضافه می‌شوند">⚡ افزودن صفحات استاندارد</button>
                <button type="submit" class="btn btn-primary">💾 ذخیره منو</button>
            </div>
        </div>
    </div>

    <!-- 👁 پیش‌نمایش زنده منو (همان‌طور که در سایت دیده می‌شود) -->
    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><h3>👁️ پیش‌نمایش زنده منوی <?= $selectedLocation === 'header' ? 'هدر' : 'فوتر' ?></h3></div>
        <div class="card-body" style="background:linear-gradient(135deg,#0f172a,#1e3a8a);border-radius:0 0 var(--radius) var(--radius);padding:14px 20px">
            <div id="menu-preview" dir="rtl" style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;font-size:13.5px"></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>☰ آیتم‌های منو — با درگ مرتب کنید</h3>
            <div class="tools">
                <?php if ($copySources): ?>
                <select id="copy-source" class="form-control" style="max-width:190px;font-size:12px">
                    <?php foreach ($copySources as $cs): ?>
                        <option value="<?= (int)$cs['id'] ?>">📋 از <?= e($cs['name_fa']) ?></option>
                    <?php endforeach; ?>
                    <?php if ($selectedBrand > 0): ?>
                        <option value="0">📋 از منوی عمومی</option>
                    <?php endif; ?>
                </select>
                <button type="button" class="btn btn-outline btn-sm" onclick="copyMenu()" title="کل منوی انتخابی جایگزین منوی فعلی می‌شود">📋 کپی منو</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <div id="menu-editor" style="min-height:200px"></div>
            <div class="hint" style="margin-top:12px">💡 آیتم‌ها را بکشید و روی هم رها کنید تا زیرمنو شوند. دکمه ⋯ زیرمنو می‌سازد — 🎨 انتخابگر آیکون — Nofollow برای لینک‌های خارجی (سئو).</div>
        </div>
    </div>
</form>

<form method="post" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap" id="menu-tools-form">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="brand_id" value="<?= $selectedBrand ?>">
    <input type="hidden" name="location" value="<?= e($selectedLocation) ?>">
    <input type="hidden" name="action" value="reset_menu">
    <button type="submit" class="btn btn-outline btn-sm" data-confirm="منو به حالت پیش‌فرض بازگردانده شود؟">🌱 بازنشانی به پیش‌فرض</button>
</form>
<form method="post" id="copy-menu-form" style="display:none">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="copy_menu">
    <input type="hidden" name="brand_id" value="<?= $selectedBrand ?>">
    <input type="hidden" name="location" value="<?= e($selectedLocation) ?>">
    <input type="hidden" name="source_brand_id" id="copy-source-hidden" value="">
</form>

<!-- 🎨 انتخابگر آیکون -->
<div class="modal-backdrop" id="icon-picker" style="display:none" onclick="if(event.target===this)closeIconPicker()">
    <div class="modal" style="max-width:480px">
        <div class="modal-header" style="justify-content:space-between">
            <span>🎨 انتخاب آیکون آیتم</span>
            <button type="button" class="btn btn-outline btn-sm" onclick="closeIconPicker()">✕</button>
        </div>
        <div class="modal-body">
            <div id="icon-grid" style="display:grid;grid-template-columns:repeat(8,1fr);gap:6px"></div>
            <div class="form-group" style="margin-top:12px">
                <label>آیکون سفارشی (ایموجی یا خالی برای حذف)</label>
                <div style="display:flex;gap:8px">
                    <input type="text" id="icon-custom" class="form-control" style="direction:ltr;font-size:18px" maxlength="8" placeholder="🏠">
                    <button type="button" class="btn btn-primary" onclick="applyCustomIcon()">تأیید</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
/* ☰ ویرایشگر منو — v2.12: درگ‌اند‌دراپ درختی + nofollow + انتخابگر آیکون + پیش‌نمایش زنده */
let menuData = <?= json_encode($menuTree, JSON_UNESCAPED_UNICODE) ?>;
let counter = 1000;

const PAGE_TYPES = <?= json_encode($pageTypes, JSON_UNESCAPED_UNICODE) ?>;
const ICON_PALETTE = <?= json_encode($iconPalette, JSON_UNESCAPED_UNICODE) ?>;
let iconTarget = null; // [i, path] آیتم در انتظار آیکون

function renderMenu() {
    const editor = document.getElementById('menu-editor');
    editor.innerHTML = '';
    if (!menuData.length) {
        editor.innerHTML = '<div class="empty-state"><div class="icon">☰</div><p>منو خالی است — آیتم اضافه کنید.</p></div>';
    }
    menuData.forEach((item, i) => editor.appendChild(renderItem(item, i, [])));
    sync();
    renderPreview();
}

function renderItem(item, i, path) {
    const wrap = document.createElement('div');
    wrap.style.marginBottom = '6px';

    const pStr = JSON.stringify(path);
    const row = document.createElement('div');
    row.className = 'canvas-block';
    row.style.padding = '10px 44px 10px 12px';
    row.style.marginBottom = item.children && item.children.length ? '2px' : '6px';
    row.style.opacity = item.is_active ? '1' : '.55';
    row.draggable = true;
    row.innerHTML = `
        <div class="block-tools" style="gap:2px">
            <button type="button" onclick="openIconPicker(${i}, ${pStr})" title="انتخاب آیکون">🎨</button>
            <button type="button" onclick="addChild(${i}, ${pStr})" title="زیرمنو">⋯</button>
            <button type="button" onclick="removeItem(${i}, ${pStr})" title="حذف">✕</button>
        </div>
        <div class="block-label" style="gap:6px;flex-wrap:wrap">
            <span style="cursor:grab;font-size:15px">⠿</span>
            <button type="button" onclick="openIconPicker(${i}, ${pStr})" title="تغییر آیکون" style="border:none;background:none;cursor:pointer;font-size:17px">${item.icon || '📄'}</button>
            <input type="text" value="${(item.title || '').replace(/"/g, '&quot;')}" placeholder="عنوان" style="border:none;font-family:inherit;font-size:13px;font-weight:700;width:140px;background:none" oninput="updItem(${i}, ${pStr}, 'title', this.value)">
            <select onchange="updItem(${i}, ${pStr}, 'page_type', this.value)" style="border:1px solid var(--border);border-radius:6px;font-family:inherit;font-size:11px;padding:2px 6px;max-width:125px">
                <option value="">— صفحه سیستمی —</option>
                ${Object.entries(PAGE_TYPES).map(([k, v]) => `<option value="${k}" ${item.page_type === k ? 'selected' : ''}>${v}</option>`).join('')}
            </select>
            <input type="text" value="${(item.url || '').replace(/"/g, '&quot;')}" placeholder="لینک سفارشی" style="border:1px solid var(--border);border-radius:6px;font-family:inherit;font-size:11px;padding:2px 6px;width:100px;direction:ltr" oninput="updItem(${i}, ${pStr}, 'url', this.value)">
            <label style="display:flex;gap:3px;align-items:center;font-size:10.5px;font-weight:400" title="برای لینک‌های خارجی — موتورهای جستجو دنبال نکنند">
                <input type="checkbox" ${item.nofollow ? 'checked' : ''} onchange="updItem(${i}, ${pStr}, 'nofollow', this.checked)"> nofollow
            </label>
            <label style="display:flex;gap:3px;align-items:center;font-size:10.5px;font-weight:400"><input type="checkbox" ${item.is_active ? 'checked' : ''} onchange="updItem(${i}, ${pStr}, 'is_active', this.checked)"> فعال</label>
            ${item.children && item.children.length ? `<span class="badge badge-secondary" style="font-size:9.5px">${item.children.length} زیرمنو</span>` : ''}
        </div>`;

    // درگ برای جابجایی
    row.addEventListener('dragstart', e => {
        e.dataTransfer.setData('text/plain', JSON.stringify({ path: [...path, i] }));
        row.style.opacity = '.4';
    });
    row.addEventListener('dragend', () => row.style.opacity = item.is_active ? '1' : '.55');
    row.addEventListener('dragover', e => { e.preventDefault(); row.style.borderColor = 'var(--primary)'; });
    row.addEventListener('dragleave', () => row.style.borderColor = '');
    row.addEventListener('drop', e => {
        e.preventDefault();
        e.stopPropagation();
        row.style.borderColor = '';
        try {
            const src = JSON.parse(e.dataTransfer.getData('text/plain'));
            moveItem(src.path, [...path, i]);
        } catch (err) {}
    });

    wrap.appendChild(row);

    // زیرمنوها با تورفتگی
    if (item.children && item.children.length) {
        const sub = document.createElement('div');
        sub.style.marginInlineStart = '34px';
        sub.style.borderInlineStart = '2px dashed var(--border)';
        sub.style.paddingInlineStart = '10px';
        item.children.forEach((child, ci) => sub.appendChild(renderItem(child, ci, [...path, i])));
        wrap.appendChild(sub);
    }
    return wrap;
}

/* 👁 پیش‌نمایش زنده — شبیه هدر سایت برند */
function renderPreview() {
    const pv = document.getElementById('menu-preview');
    let html = '<span style="font-size:17px">🏗️</span><span style="font-weight:800;color:#fff">نمایندگی</span><span style="opacity:.4">|</span>';
    const visible = menuData.filter(m => m.is_active);
    if (!visible.length) {
        html += '<span style="color:#94a3b8;font-size:12px">منو خالی است — آیتم اضافه کنید</span>';
    }
    visible.slice(0, 8).forEach(m => {
        const icon = m.icon ? `<span style="font-size:12px">${m.icon}</span> ` : '';
        const kids = (m.children || []).filter(c => c.is_active).length;
        const arrow = kids ? ' <span style="font-size:9px;opacity:.7">▾</span>' : '';
        html += `<span style="color:#e2e8f0;cursor:default">${icon}${m.title || 'بدون عنوان'}${arrow}</span>`;
    });
    if (visible.length > 8) {
        html += `<span style="color:#94a3b8">+${visible.length - 8} مورد دیگر</span>`;
    }
    pv.innerHTML = html;
}

/* ناوبری درختی */
function getItem(path) { let node = { children: menuData }; for (const i of path) node = node.children[i]; return node; }
function updItem(i, path, key, value) { const item = getItem([...path, i]); item[key] = value; sync(); renderMenu(); }
function addChild(i, path) { const item = getItem([...path, i]); item.children = item.children || []; item.children.push({ id: counter++, title: 'زیرمنو جدید', is_active: 1, children: [] }); syncAndRender(); }
function removeItem(i, path) { if (!confirm('این آیتم (و زیرمنوهایش) حذف شود؟')) return; const parent = getItem(path); parent.children.splice(i, 1); syncAndRender(); }
function addMenuItem() { menuData.push({ id: counter++, title: 'آیتم جدید', is_active: 1, children: [] }); syncAndRender(); }

/* ⚡ افزودن همه صفحات استاندارد غایب از منو */
function addMissingPages() {
    const present = new Set();
    const walk = nodes => nodes.forEach(n => { if (n.page_type) present.add(n.page_type); walk(n.children || []); });
    walk(menuData);
    let added = 0;
    Object.entries(PAGE_TYPES).forEach(([k, label]) => {
        if (!present.has(k)) {
            menuData.push({ id: counter++, title: label, page_type: k, is_active: 1, children: [] });
            added++;
        }
    });
    if (!added) { alert('همه صفحات استاندارد از قبل در منو هستند.'); return; }
    syncAndRender();
    alert(added + ' صفحه استاندارد به انتهای منو اضافه شد — در صورت نیاز جابجا کنید و ذخیره کنید.');
}

/* 🎨 انتخابگر آیکون */
function openIconPicker(i, path) {
    iconTarget = [i, path];
    const grid = document.getElementById('icon-grid');
    grid.innerHTML = ICON_PALETTE.map(ic =>
        `<button type="button" style="font-size:20px;padding:7px;border:1px solid var(--border);border-radius:8px;background:var(--card,#fff);cursor:pointer" onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border)'" onclick="pickIcon('${ic}')">${ic}</button>`
    ).join('');
    const item = getItem([...path, i]);
    document.getElementById('icon-custom').value = item.icon || '';
    document.getElementById('icon-picker').style.display = 'flex';
}
function pickIcon(ic) {
    if (!iconTarget) return;
    const [i, path] = iconTarget;
    getItem([...path, i]).icon = ic;
    closeIconPicker();
    syncAndRender();
}
function applyCustomIcon() {
    if (!iconTarget) return;
    const [i, path] = iconTarget;
    getItem([...path, i]).icon = document.getElementById('icon-custom').value.trim();
    closeIconPicker();
    syncAndRender();
}
function closeIconPicker() {
    document.getElementById('icon-picker').style.display = 'none';
    iconTarget = null;
}

/* 📋 کپی منو از برند دیگر */
function copyMenu() {
    const src = document.getElementById('copy-source');
    const label = src.options[src.selectedIndex].textContent.replace('📋 از ', '');
    if (!confirm(`منوی فعلی کامل حذف و با منوی «${label}» جایگزین شود؟`)) return;
    document.getElementById('copy-source-hidden').value = src.value;
    document.getElementById('copy-menu-form').submit();
}

function moveItem(srcPath, destPath) {
    const srcParent = getItem(srcPath.slice(0, -1));
    const [moved] = srcParent.children.splice(srcPath[srcPath.length - 1], 1);
    if (moved) {
        const destParent = getItem(destPath);
        // جلوگیری از انتقال آیتم به زیرمجموعه خودش
        const destIsInsideSrc = srcPath.slice(0, srcPath.length - 1).join('.') === destPath.slice(0, Math.min(destPath.length, srcPath.length - 1)).join('.');
        if (destIsInsideSrc) { srcParent.children.splice(srcPath[srcPath.length - 1], 0, moved); return; }
        // قرار دادن زیر آیتم مقصد
        destParent.children = destParent.children || [];
        destParent.children.push(moved);
        syncAndRender();
    }
}

function cleanTree(nodes) {
    return nodes.map(n => ({
        title: n.title, url: n.url || '', page_type: n.page_type || '', icon: n.icon || '',
        nofollow: !!n.nofollow, is_active: !!n.is_active,
        children: n.children ? cleanTree(n.children) : []
    }));
}
function sync() { document.getElementById('menu-json').value = JSON.stringify(cleanTree(menuData)); }
function syncAndRender() { sync(); renderMenu(); }

renderMenu();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
