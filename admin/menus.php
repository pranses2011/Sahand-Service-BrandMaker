<?php
/**
 * ☰ مدیریت منوها — درگ‌اند‌دراپ + زیرمنو + آیکون
 * ==============================================
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
        flash('success', '🌱 منوی پیش‌فرض بازسازی شد.');
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
foreach ($allItems as $item) {
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

$pageTypes = [
    'home' => 'صفحه اصلی', 'services' => 'خدمات', 'service-area' => 'محدوده خدمات', 'warranty' => 'ضمانت',
    'blog' => 'مقالات', 'about-agency' => 'درباره نمایندگی', 'about-brand' => 'درباره برند',
    'contact' => 'تماس با ما', 'request' => 'ثبت درخواست', 'other-brands' => 'سایر برندها',
    'error-codes' => 'کدهای خطا', 'faq' => 'سوالات متداول', 'terms' => 'قوانین', 'privacy' => 'حریم خصوصی',
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
            <div style="margin-inline-start:auto;display:flex;gap:8px">
                <button type="button" class="btn btn-outline" onclick="addMenuItem()">➕ افزودن آیتم</button>
                <button type="submit" class="btn btn-primary">💾 ذخیره منو</button>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>☰ آیتم‌های منو — با درگ مرتب کنید</h3></div>
        <div class="card-body">
            <div id="menu-editor" style="min-height:200px"></div>
            <div class="hint" style="margin-top:12px">💡 آیتم‌ها را بکشید و روی هم رها کنید تا زیرمنو شوند. دکمه ⋯ زیرمنو می‌سازد.</div>
        </div>
    </div>
</form>

<form method="post" style="margin-top:10px">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="reset_menu">
    <input type="hidden" name="brand_id" value="<?= $selectedBrand ?>">
    <input type="hidden" name="location" value="<?= e($selectedLocation) ?>">
    <button type="submit" class="btn btn-outline btn-sm" data-confirm="منو به حالت پیش‌فرض بازگردانده شود؟">🌱 بازنشانی به پیش‌فرض</button>
</form>

<script>
/* ☰ ویرایشگر منو — درگ‌اند‌دراپ درختی */
let menuData = <?= json_encode($menuTree, JSON_UNESCAPED_UNICODE) ?>;
let counter = 1000;

const PAGE_TYPES = <?= json_encode($pageTypes, JSON_UNESCAPED_UNICODE) ?>;

function renderMenu() {
    const editor = document.getElementById('menu-editor');
    editor.innerHTML = '';
    if (!menuData.length) {
        editor.innerHTML = '<div class="empty-state"><div class="icon">☰</div><p>منو خالی است — آیتم اضافه کنید.</p></div>';
    }
    menuData.forEach((item, i) => editor.appendChild(renderItem(item, i, [])));
    document.getElementById('menu-json').value = JSON.stringify(cleanTree(menuData));
}

function renderItem(item, i, path) {
    const wrap = document.createElement('div');
    wrap.style.marginBottom = '6px';

    const row = document.createElement('div');
    row.className = 'canvas-block';
    row.style.padding = '10px 40px 10px 12px';
    row.style.marginBottom = item.children.length ? '2px' : '6px';
    row.draggable = true;
    row.innerHTML = `
        <div class="block-tools">
            <button type="button" onclick="addChild(${i}, ${JSON.stringify(path).replace(/"/g, '&quot;')})" title="زیرمنو">⋯</button>
            <button type="button" onclick="removeItem(${i}, ${JSON.stringify(path).replace(/"/g, '&quot;')})" title="حذف">✕</button>
        </div>
        <div class="block-label" style="gap:6px">
            <span style="cursor:grab;font-size:15px">⠿</span>
            <span style="font-size:16px">${item.icon || '📄'}</span>
            <input type="text" value="${item.title || ''}" placeholder="عنوان" style="border:none;font-family:inherit;font-size:13px;font-weight:700;width:150px;background:none" oninput="updItem(${i}, ${JSON.stringify(path).replace(/"/g, '&quot;')}, 'title', this.value)">
            <select onchange="updItem(${i}, ${JSON.stringify(path).replace(/"/g, '&quot;')}, 'page_type', this.value)" style="border:1px solid var(--border);border-radius:6px;font-family:inherit;font-size:11px;padding:2px 6px;max-width:130px">
                <option value="">— صفحه سیستمی —</option>
                ${Object.entries(PAGE_TYPES).map(([k, v]) => `<option value="${k}" ${item.page_type === k ? 'selected' : ''}>${v}</option>`).join('')}
            </select>
            <input type="text" value="${item.url || ''}" placeholder="لینک سفارشی" style="border:1px solid var(--border);border-radius:6px;font-family:inherit;font-size:11px;padding:2px 6px;width:110px;direction:ltr" oninput="updItem(${i}, ${JSON.stringify(path).replace(/"/g, '&quot;')}, 'url', this.value)">
            <label style="display:flex;gap:3px;align-items:center;font-size:10.5px;font-weight:400"><input type="checkbox" ${item.is_active ? 'checked' : ''} onchange="updItem(${i}, ${JSON.stringify(path).replace(/"/g, '&quot;')}, 'is_active', this.checked)"> فعال</label>
        </div>`;

    // درگ برای جابجایی
    row.addEventListener('dragstart', e => {
        e.dataTransfer.setData('text/plain', JSON.stringify({ path: [...path, i] }));
        row.style.opacity = '.4';
    });
    row.addEventListener('dragend', () => row.style.opacity = '1');
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

/* ناوبری درختی */
function getItem(path) { let node = { children: menuData }; for (const i of path) node = node.children[i]; return node; }
function updItem(i, path, key, value) { const item = getItem([...path, i]); item[key] = value; sync(); }
function addChild(i, path) { const item = getItem([...path, i]); item.children = item.children || []; item.children.push({ id: counter++, title: 'زیرمنو جدید', is_active: 1, children: [] }); syncAndRender(); }
function removeItem(i, path) { const parent = getItem(path); parent.children.splice(i, 1); syncAndRender(); }
function addMenuItem() { menuData.push({ id: counter++, title: 'آیتم جدید', is_active: 1, children: [] }); syncAndRender(); }

function moveItem(srcPath, destPath) {
    const srcParent = getItem(srcPath.slice(0, -1));
    const [moved] = srcParent.children.splice(srcPath[srcPath.length - 1], 1);
    if (moved) {
        const destParent = getItem(destPath);
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
