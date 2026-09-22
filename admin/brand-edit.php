<?php
/**
 * ✏️ ویرایش برند — تمام تنظیمات یک سایت برند
 * ============================================
 * تب‌ها: اطلاعات | دستگاه‌ها | صفحات و محتوا | پالت رنگ | سئو | API
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();
$fm = new FileManager();
$brandId = (int)get_param('id');
$brand = $db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
if (!$brand) {
    flash('danger', 'برند یافت نشد.');
    redirect('brands.php');
}

/* ==================================================
 * 💾 پردازش فرم‌ها
 * ================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::enforceCsrf();
    $action = post('action');

    /* ---------- بروزرسانی اطلاعات پایه ---------- */
    if ($action === 'update_info') {
        $update = [
            'name_fa'            => post('name_fa'),
            'name_en'            => post('name_en'),
            'domain'             => post('domain'),
            'error_codes_enabled'=> !empty($_POST['error_codes_enabled']) ? 1 : 0,
            'is_active'          => !empty($_POST['is_active']) ? 1 : 0,
            'updated_at'         => date('Y-m-d H:i:s'),
        ];
        if (!empty($_FILES['logo']['name'])) {
            $upload = $fm->uploadImage($_FILES['logo'], 'logos');
            if ($upload['success']) {
                $update['logo'] = $upload['path'];
            } else {
                flash('danger', 'خطای آپلود لوگو: ' . $upload['error']);
            }
        }
        $db->update('brands', $update, 'id = ?', [$brandId]);
        Logger::activity((int)$_SESSION['user_id'], 'ویرایش برند', $brand['name_fa']);
        flash('success', '✅ اطلاعات برند بروزرسانی شد.');
        redirect('brand-edit.php?id=' . $brandId);
    }

    /* ---------- مدیریت دستگاه‌ها ---------- */
    if ($action === 'update_devices') {
        $deviceIds = (array)($_POST['device_active'] ?? []);
        $featured = (array)($_POST['device_featured'] ?? []);
        foreach ((array)($_POST['device_id'] ?? []) as $i => $deviceId) {
            $deviceId = (int)$deviceId;
            $db->update('brand_devices', [
                'is_active'   => in_array($deviceId, array_map('intval', $deviceIds), true) ? 1 : 0,
                'is_featured' => in_array($deviceId, array_map('intval', $featured), true) ? 1 : 0,
                'sort_order'  => (int)($_POST['device_order'][$i] ?? 0),
                'description' => Validator::sanitizeHtml((string)($_POST['device_desc'][$i] ?? '')),
            ], 'id = ? AND brand_id = ?', [$deviceId, $brandId]);
        }
        flash('success', '✅ لیست دستگاه‌ها بروزرسانی شد.');
        redirect('brand-edit.php?id=' . $brandId . '&tab=devices');
    }

    /* ---------- ویرایش محتوای صفحه ---------- */
    if ($action === 'update_page') {
        $pageId = (int)post('page_id');
        $page = $db->fetch('SELECT id FROM brand_pages WHERE id = ? AND brand_id = ?', [$pageId, $brandId]);
        if ($page) {
            $contentData = json_decode((string)post('page_content_json'), true) ?: [];
            $contentData[post('content_field') ?: 'content'] = Validator::sanitizeHtml((string)($_POST['content_value'] ?? ''));
            $db->update('brand_pages', [
                'content'   => json_encode($contentData, JSON_UNESCAPED_UNICODE),
                'is_active' => !empty($_POST['page_active']) ? 1 : 0,
            ], 'id = ?', [$pageId]);
            (new Cache())->delete('brand_' . $brandId . '_pages');
            flash('success', '✅ محتوای صفحه ذخیره شد.');
        }
        redirect('brand-edit.php?id=' . $brandId . '&tab=pages');
    }

    /* ---------- ذخیره پالت رنگ (ویرایش دستی) ---------- */
    if ($action === 'update_palette') {
        $light = [];
        $dark = [];
        foreach ((array)($_POST['light_vars'] ?? []) as $i => $var) {
            $light[clean_input($var)] = clean_input(($_POST['light_vals'] ?? [])[$i] ?? '');
        }
        foreach ((array)($_POST['dark_vars'] ?? []) as $i => $var) {
            $dark[clean_input($var)] = clean_input(($_POST['dark_vals'] ?? [])[$i] ?? '');
        }
        $exists = $db->fetchValue('SELECT COUNT(*) FROM color_palettes WHERE brand_id = ?', [$brandId]);
        if ($exists) {
            $db->update('color_palettes', [
                'light_palette' => json_encode($light, JSON_UNESCAPED_UNICODE),
                'dark_palette'  => json_encode($dark, JSON_UNESCAPED_UNICODE),
                'is_manual_edited' => 1,
            ], 'brand_id = ?', [$brandId]);
        } else {
            $db->insert('color_palettes', [
                'brand_id' => $brandId,
                'light_palette' => json_encode($light, JSON_UNESCAPED_UNICODE),
                'dark_palette' => json_encode($dark, JSON_UNESCAPED_UNICODE),
                'is_manual_edited' => 1,
            ]);
        }
        flash('success', '✅ پالت رنگ ذخیره شد.');
        redirect('brand-edit.php?id=' . $brandId . '&tab=palette');
    }

    /* ---------- بازتولید پالت از لوگو ---------- */
    if ($action === 'regenerate_palette') {
        if (!empty($brand['logo'])) {
            $analysis = (new ColorAnalyzer())->analyze(ROOT_PATH . '/' . $brand['logo']);
            if ($analysis) {
                $exists = $db->fetchValue('SELECT COUNT(*) FROM color_palettes WHERE brand_id = ?', [$brandId]);
                if ($exists) {
                    $db->update('color_palettes', [
                        'light_palette' => json_encode($analysis['light'], JSON_UNESCAPED_UNICODE),
                        'dark_palette' => json_encode($analysis['dark'], JSON_UNESCAPED_UNICODE),
                        'dominant_colors' => json_encode($analysis['dominant'], JSON_UNESCAPED_UNICODE),
                        'is_manual_edited' => 0,
                    ], 'brand_id = ?', [$brandId]);
                } else {
                    $db->insert('color_palettes', [
                        'brand_id' => $brandId,
                        'light_palette' => json_encode($analysis['light'], JSON_UNESCAPED_UNICODE),
                        'dark_palette' => json_encode($analysis['dark'], JSON_UNESCAPED_UNICODE),
                        'dominant_colors' => json_encode($analysis['dominant'], JSON_UNESCAPED_UNICODE),
                    ]);
                }
                flash('success', '✅ پالت از لوگو بازتولید شد.');
            } else {
                flash('danger', 'تحلیل لوگو ناموفق بود.');
            }
        } else {
            flash('warning', 'لوگویی برای این برند آپلود نشده است.');
        }
        redirect('brand-edit.php?id=' . $brandId . '&tab=palette');
    }

    /* ---------- بروزرسانی سئوی صفحه ---------- */
    if ($action === 'update_seo') {
        $pageId = (int)post('page_id');
        $analyzer = new SeoAnalyzer();
        $content = (string)post('seo_content');
        $analysis = $analyzer->analyze([
            'title' => post('seo_title'),
            'meta_description' => post('seo_description'),
            'content' => $content,
            'slug' => post('seo_slug'),
            'seo_robots' => post('seo_robots'),
            'og_image' => post('og_image'),
        ], post('focus_keyword'));
        $db->update('brand_pages', [
            'seo_title'       => post('seo_title'),
            'seo_description' => post('seo_description'),
            'seo_keywords'    => post('seo_keywords'),
            'seo_robots'      => post('seo_robots'),
            'og_title'        => post('seo_title'),
            'og_description'  => post('seo_description'),
            'og_image'        => post('og_image') ?: null,
            'seo_score'       => $analysis['score'],
        ], 'id = ? AND brand_id = ?', [$pageId, $brandId]);
        flash('success', '✅ سئوی صفحه ذخیره شد — امتیاز جدید: ' . $analysis['score'] . '/100');
        redirect('brand-edit.php?id=' . $brandId . '&tab=seo&page=' . $pageId);
    }
}

$pageTitle = 'ویرایش برند';
$activeMenu = 'brands';
require __DIR__ . '/includes/header.php';

/* ==================================================
 * 📥 بارگذاری داده‌ها
 * ================================================== */
$devices = $db->fetchAll('SELECT * FROM brand_devices WHERE brand_id = ? ORDER BY sort_order, id', [$brandId]);
$pages = $db->fetchAll('SELECT * FROM brand_pages WHERE brand_id = ? ORDER BY id', [$brandId]);
$palette = $db->fetch('SELECT * FROM color_palettes WHERE brand_id = ?', [$brandId]);
$lightPalette = $palette ? (json_decode($palette['light_palette'], true) ?: []) : [];
$darkPalette = $palette ? (json_decode($palette['dark_palette'], true) ?: []) : [];
$dominant = $palette ? (json_decode($palette['dominant_colors'], true) ?: []) : [];
$currentTab = get_param('tab', 'info');

$pageNames = [
    'home' => '🏠 صفحه اصلی', 'services' => '🔧 خدمات', 'service-area' => '📍 محدوده خدمات',
    'warranty' => '🛡️ ضمانت', 'blog' => '📰 مقالات', 'about-agency' => '🏢 درباره نمایندگی',
    'about-brand' => 'ℹ️ درباره برند', 'contact' => '📞 تماس با ما', 'request' => '📝 ثبت درخواست',
    'other-brands' => '🏷️ سایر برندها', 'error-codes' => '🚨 کدهای خطا', 'faq' => '❓ سوالات متداول',
    'sitemap-page' => '🗺️ نقشه سایت', 'terms' => '📜 قوانین', 'privacy' => '🔒 حریم خصوصی',
];

// صفحه انتخابی سئو
$seoPageId = (int)get_param('page', ($pages[0]['id'] ?? 0));
$seoPage = null;
foreach ($pages as $p) {
    if ((int)$p['id'] === $seoPageId) {
        $seoPage = $p;
        break;
    }
}
?>

<form method="post" enctype="multipart/form-data" id="main-form">
    <?= Auth::csrfField() ?>

    <div class="tabs">
        <button type="button" class="tab-btn <?= $currentTab === 'info' ? 'active' : '' ?>" onclick="switchTab(this,'tab-info')">📇 اطلاعات</button>
        <button type="button" class="tab-btn <?= $currentTab === 'devices' ? 'active' : '' ?>" onclick="switchTab(this,'tab-devices')">🔧 دستگاه‌ها (<?= en_to_fa_digits((string)count($devices)) ?>)</button>
        <button type="button" class="tab-btn <?= $currentTab === 'pages' ? 'active' : '' ?>" onclick="switchTab(this,'tab-pages')">📄 صفحات</button>
        <button type="button" class="tab-btn <?= $currentTab === 'palette' ? 'active' : '' ?>" onclick="switchTab(this,'tab-palette')">🎨 پالت رنگ</button>
        <button type="button" class="tab-btn <?= $currentTab === 'seo' ? 'active' : '' ?>" onclick="switchTab(this,'tab-seo')">🔍 سئو</button>
        <button type="button" class="tab-btn <?= $currentTab === 'api' ? 'active' : '' ?>" onclick="switchTab(this,'tab-api')">🔌 API</button>
    </div>

    <!-- 📇 تب اطلاعات -->
    <div id="tab-info" class="tab-pane <?= $currentTab === 'info' ? 'active' : '' ?>">
        <div class="card">
            <div class="card-header"><h3>📇 اطلاعات برند</h3></div>
            <div class="card-body">
                <input type="hidden" name="action" value="update_info">
                <div class="form-row">
                    <div class="form-group">
                        <label>نام فارسی</label>
                        <input type="text" name="name_fa" class="form-control" required value="<?= e($brand['name_fa']) ?>">
                    </div>
                    <div class="form-group">
                        <label>نام انگلیسی</label>
                        <input type="text" name="name_en" class="form-control" style="direction:ltr;text-align:left" required value="<?= e($brand['name_en']) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>🌐 آدرس دامنه</label>
                    <input type="text" name="domain" class="form-control" style="direction:ltr;text-align:left" value="<?= e($brand['domain']) ?>">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>🖼️ لوگو</label>
                        <?php if ($brand['logo']): ?><img src="<?= asset_url($brand['logo']) ?>" alt="لوگو" style="max-height:70px;margin-bottom:8px;display:block"><?php endif; ?>
                        <input type="file" name="logo" class="form-control" accept="image/*">
                    </div>
                    <div class="form-group" style="display:flex;flex-direction:column;justify-content:center;gap:12px">
                        <label class="form-check"><input type="checkbox" name="is_active" <?= $brand['is_active'] ? 'checked' : '' ?>> برند فعال باشد</label>
                        <label class="form-check"><input type="checkbox" name="error_codes_enabled" <?= $brand['error_codes_enabled'] ? 'checked' : '' ?>> صفحه کدهای خطا فعال باشد</label>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">💾 ذخیره اطلاعات</button>
            </div>
        </div>
    </div>

    <!-- 🔧 تب دستگاه‌ها -->
    <div id="tab-devices" class="tab-pane <?= $currentTab === 'devices' ? 'active' : '' ?>">
        <div class="card">
            <div class="card-header">
                <h3>🔧 دستگاه‌های <?= e($brand['name_fa']) ?></h3>
                <div class="tools"><span class="badge badge-info">انتخاب موارد نمایش در سایت</span></div>
            </div>
            <div class="card-body">
                <input type="hidden" name="action" value="update_devices">
                <?php if (empty($devices)): ?>
                    <div class="empty-state"><div class="icon">🔧</div><p>دستگاهی ثبت نشده — فرآیند ساخت را اجرا کنید یا دستگاه‌ها از پایگاه دانش ثبت شوند.</p></div>
                <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>نمایش</th><th>برجسته</th><th>دستگاه</th><th>ترتیب</th><th>وضعیت توضیح AI</th></tr></thead>
                        <tbody>
                        <?php foreach ($devices as $i => $device): ?>
                            <tr>
                                <td><input type="hidden" name="device_id[]" value="<?= (int)$device['id'] ?>"><input type="checkbox" name="device_active[]" value="<?= (int)$device['id'] ?>" <?= $device['is_active'] ? 'checked' : '' ?>></td>
                                <td><input type="checkbox" name="device_featured[]" value="<?= (int)$device['id'] ?>" <?= $device['is_featured'] ? 'checked' : '' ?>></td>
                                <td style="font-weight:700"><?= e($device['name_fa']) ?> <small style="color:var(--text-light);direction:ltr"><?= e($device['device_key']) ?></small></td>
                                <td><input type="number" name="device_order[]" class="form-control" style="width:70px" value="<?= (int)($device['sort_order'] ?? $i) ?>"></td>
                                <td><?= $device['description'] ? '<span class="badge badge-success">✅ تولید شد</span>' : '<span class="badge badge-secondary">—</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary" <?= empty($devices) ? 'disabled' : '' ?>>💾 ذخیره دستگاه‌ها</button>
            </div>
        </div>
    </div>

    <!-- 📄 تب صفحات و محتوا -->
    <div id="tab-pages" class="tab-pane <?= $currentTab === 'pages' ? 'active' : '' ?>">
        <?php if (empty($pages)): ?>
            <div class="card"><div class="empty-state"><div class="icon">📄</div><p>محتوایی تولید نشده — ابتدا <a href="brand-build.php?id=<?= (int)$brandId ?>">فرآیند ساخت</a> را اجرا کنید.</p></div></div>
        <?php else: ?>
            <?php foreach ($pages as $page): ?>
                <?php $contentData = json_decode($page['content'] ?? '{}', true) ?: []; ?>
                <?php $mainField = isset($contentData['content']) ? 'content' : (isset($contentData['intro']) ? 'intro' : (array_key_first($contentData) ?: 'content')); ?>
                <div class="card">
                    <div class="card-header">
                        <h3><?= $pageNames[$page['page_type']] ?? e($page['page_type']) ?></h3>
                        <div class="tools">
                            <?php if ((int)$page['seo_score'] > 0): ?><span class="badge badge-info">سئو: <?= en_to_fa_digits((string)$page['seo_score']) ?>/۱۰۰</span><?php endif; ?>
                            <label class="switch"><input type="checkbox" form="page-form-<?= (int)$page['id'] ?>" name="page_active" <?= $page['is_active'] ? 'checked' : '' ?>><span class="slider"></span></label>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="post" id="page-form-<?= (int)$page['id'] ?>">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="action" value="update_page">
                            <input type="hidden" name="page_id" value="<?= (int)$page['id'] ?>">
                            <input type="hidden" name="page_content_json" value="<?= e($page['content'] ?? '{}') ?>">
                            <input type="hidden" name="content_field" value="<?= e((string)$mainField) ?>">
                            <textarea name="content_value" class="form-control" rows="7" style="line-height:2.1"><?= e((string)($contentData[$mainField] ?? '')) ?></textarea>
                            <div class="hint" style="margin:8px 0 12px">ویرایش دستی محتوای تولیدشده توسط AI — تغییرات بلافاصله در سایت برند اعمال می‌شود (کش ۱۰ دقیقه).</div>
                            <button type="submit" class="btn btn-primary btn-sm">💾 ذخیره محتوا</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- 🎨 تب پالت رنگ -->
    <div id="tab-palette" class="tab-pane <?= $currentTab === 'palette' ? 'active' : '' ?>">
        <div class="card">
            <div class="card-header">
                <h3>🎨 پالت رنگ <?= e($brand['name_fa']) ?></h3>
                <div class="tools">
                    <button type="submit" form="palette-regen-form" class="btn btn-outline btn-sm">🔄 بازتولید از لوگو</button>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($lightPalette)): ?>
                    <div class="empty-state"><div class="icon">🎨</div><p>پالتی ثبت نشده — فرآیند ساخت یا بازتولید از لوگو را اجرا کنید.</p></div>
                <?php else: ?>
                    <?php if (!empty($dominant)): ?>
                        <div style="margin-bottom:18px">
                            <strong style="font-size:13px">🌈 رنگ‌های غالب لوگو:</strong>
                            <div style="display:flex;gap:8px;margin-top:10px">
                                <?php foreach ($dominant as $color): ?>
                                    <div style="text-align:center">
                                        <div style="width:54px;height:54px;border-radius:12px;background:<?= e($color) ?>;border:1px solid rgba(0,0,0,.1)"></div>
                                        <code style="font-size:10px;direction:ltr;display:block;margin-top:4px"><?= e($color) ?></code>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="post">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="action" value="update_palette">
                        <div class="grid-2">
                            <div>
                                <h4 style="font-size:13.5px;margin-bottom:12px">🌞 تم روشن</h4>
                                <?php foreach ($lightPalette as $var => $value): ?>
                                    <div style="display:flex;gap:8px;align-items:center;margin-bottom:7px">
                                        <input type="hidden" name="light_vars[]" value="<?= e($var) ?>">
                                        <code style="font-size:10.5px;direction:ltr;width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($var) ?></code>
                                        <?php if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string)$value)): ?>
                                            <input type="color" name="light_vals[]" value="<?= e($value) ?>" style="width:44px;height:32px;border:1px solid var(--border);border-radius:7px;cursor:pointer;background:none">
                                            <code style="font-size:10.5px;direction:ltr"><?= e($value) ?></code>
                                        <?php else: ?>
                                            <input type="text" name="light_vals[]" class="form-control" style="direction:ltr;text-align:left;font-size:11px;padding:5px 9px" value="<?= e((string)$value) ?>">
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div>
                                <h4 style="font-size:13.5px;margin-bottom:12px">🌙 تم تاریک</h4>
                                <?php foreach ($darkPalette as $var => $value): ?>
                                    <div style="display:flex;gap:8px;align-items:center;margin-bottom:7px">
                                        <input type="hidden" name="dark_vars[]" value="<?= e($var) ?>">
                                        <code style="font-size:10.5px;direction:ltr;width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($var) ?></code>
                                        <?php if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string)$value)): ?>
                                            <input type="color" name="dark_vals[]" value="<?= e($value) ?>" style="width:44px;height:32px;border:1px solid var(--border);border-radius:7px;cursor:pointer;background:none">
                                            <code style="font-size:10.5px;direction:ltr"><?= e($value) ?></code>
                                        <?php else: ?>
                                            <input type="text" name="dark_vals[]" class="form-control" style="direction:ltr;text-align:left;font-size:11px;padding:5px 9px" value="<?= e((string)$value) ?>">
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div style="margin-top:16px">
                            <button type="submit" class="btn btn-primary">💾 ذخیره پالت</button>
                            <?= $palette['is_manual_edited'] ? '<span class="badge badge-warning" style="margin-inline-start:8px">ویرایش دستی شده</span>' : '' ?>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <!-- فرم جداگانه بازتولید -->
        <form method="post" id="palette-regen-form"><?= Auth::csrfField() ?><input type="hidden" name="action" value="regenerate_palette"></form>
    </div>

    <!-- 🔍 تب سئو -->
    <div id="tab-seo" class="tab-pane <?= $currentTab === 'seo' ? 'active' : '' ?>">
        <div class="card">
            <div class="card-header">
                <h3>🔍 سئوی صفحات <?= e($brand['name_fa']) ?></h3>
                <div class="tools">
                    <form method="get" style="display:flex;gap:8px">
                        <input type="hidden" name="id" value="<?= (int)$brandId ?>">
                        <input type="hidden" name="tab" value="seo">
                        <select name="page" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($pages as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $seoPageId ? 'selected' : '' ?>><?= $pageNames[$p['page_type']] ?? $p['page_type'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <?php if (!$seoPage): ?>
                    <div class="empty-state"><div class="icon">🔍</div><p>صفحه‌ای برای ویرایش سئو وجود ندارد.</p></div>
                <?php else: ?>
                    <?php
                    $contentData = json_decode($seoPage['content'] ?? '{}', true) ?: [];
                    $seoContent = implode("\n", $contentData);
                    $lastAnalysis = (new SeoAnalyzer())->analyze([
                        'title' => $seoPage['seo_title'],
                        'meta_description' => $seoPage['seo_description'],
                        'content' => '<p>' . $seoContent . '</p>',
                        'slug' => $seoPage['page_type'],
                        'og_image' => $seoPage['og_image'],
                    ], $brand['name_fa']);
                    ?>
                    <!-- ⚡ امتیاز فعلی -->
                    <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;background:var(--bg);padding:16px;border-radius:12px">
                        <div style="width:74px;height:74px;border-radius:50%;background:conic-gradient(var(--primary) <?= (int)$seoPage['seo_score'] ?>%, var(--border) 0);display:flex;align-items:center;justify-content:center">
                            <div style="width:58px;height:58px;border-radius:50%;background:var(--card);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px"><?= en_to_fa_digits((string)$seoPage['seo_score']) ?></div>
                        </div>
                        <div>
                            <div style="font-weight:700">امتیاز سئوی صفحه</div>
                            <div style="font-size:12px;color:var(--text-light)"><?= e($lastAnalysis['grade']) ?> — <?= en_to_fa_digits((string)$lastAnalysis['word_count']) ?> کلمه محتوا</div>
                        </div>
                    </div>

                    <form method="post">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="action" value="update_seo">
                        <input type="hidden" name="page_id" value="<?= (int)$seoPage['id'] ?>">
                        <input type="hidden" name="seo_content" value="<?= e($seoContent) ?>">
                        <div class="form-row">
                            <div class="form-group">
                                <label>عنوان سئو (حداکثر ۶۰)</label>
                                <input type="text" name="seo_title" class="form-control" maxlength="70" value="<?= e($seoPage['seo_title'] ?? '') ?>" oninput="document.getElementById('title-count').textContent=this.value.length">
                                <div class="hint">طول فعلی: <span id="title-count"><?= mb_strlen((string)$seoPage['seo_title']) ?></span> — <span style="color:var(--success)">بهینه: ۳۰ تا ۶۰</span></div>
                            </div>
                            <div class="form-group">
                                <label>کلیدواژه کانونی</label>
                                <input type="text" name="focus_keyword" class="form-control" value="<?= e('تعمیر ' . $brand['name_fa']) ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>متا توضیحات (حداکثر ۱۶۰)</label>
                            <textarea name="seo_description" class="form-control" rows="2" maxlength="170" oninput="document.getElementById('meta-count').textContent=this.value.length"><?= e($seoPage['seo_description'] ?? '') ?></textarea>
                            <div class="hint">طول فعلی: <span id="meta-count"><?= mb_strlen((string)$seoPage['seo_description']) ?></span> — <span style="color:var(--success)">بهینه: ۱۲۰ تا ۱۶۰</span></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>کلمات کلیدی (با کاما)</label>
                                <input type="text" name="seo_keywords" class="form-control" value="<?= e($seoPage['seo_keywords'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Robots</label>
                                <select name="seo_robots" class="form-control">
                                    <?php foreach (['index,follow' => 'ایندکس شود (پیش‌فرض)', 'noindex,follow' => 'ایندکس نشود', 'noindex,nofollow' => 'کاملاً مخفی'] as $val => $label): ?>
                                        <option value="<?= $val ?>" <?= ($seoPage['seo_robots'] ?? 'index,follow') === $val ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>🖼️ تصویر Open Graph</label>
                            <input type="url" name="og_image" class="form-control" style="direction:ltr;text-align:left" value="<?= e($seoPage['og_image'] ?? '') ?>" placeholder="https://... (1200x630)">
                        </div>
                        <button type="submit" class="btn btn-primary">💾 ذخیره سئو + امتیازدهی مجدد</button>
                    </form>

                    <!-- 💡 پیشنهادهای بهبود -->
                    <?php if (!empty($lastAnalysis['suggestions'])): ?>
                        <div style="margin-top:22px">
                            <h4 style="font-size:13.5px;margin-bottom:10px">💡 پیشنهادهای بهبود سئو:</h4>
                            <?php foreach (array_slice($lastAnalysis['suggestions'], 0, 5) as $sug): ?>
                                <div class="alert alert-warning" style="padding:9px 14px;font-size:12.5px;margin-bottom:8px">
                                    <b><?= e($sug['title']) ?></b> — <?= e($sug['advice']) ?> <span class="badge badge-info">+<?= $sug['gain'] ?> امتیاز</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success" style="margin-top:20px">🎉 عالی! هیچ مورد بهبودی برای این صفحه باقی نمانده است.</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 🔌 تب API -->
    <div id="tab-api" class="tab-pane <?= $currentTab === 'api' ? 'active' : '' ?>">
        <div class="card">
            <div class="card-header"><h3>🔌 کلید API سایت برند</h3></div>
            <div class="card-body">
                <p style="font-size:13px;margin-bottom:14px">این کلید در <code>config.php</code> هسته سایت برند قرار می‌گیرد و برای دریافت داده‌ها از سایت ساز استفاده می‌شود.</p>
                <div style="display:flex;gap:10px;align-items:center">
                    <input type="text" class="form-control" style="direction:ltr;text-align:left;font-family:monospace" readonly value="<?= e($brand['api_key']) ?>" id="api-key-input">
                    <button type="button" class="btn btn-outline" onclick="copyText(document.getElementById('api-key-input').value, this)">📋 کپی</button>
                </div>
                <div class="hint" style="margin-top:10px">🔒 این کلید محرمانه است — در اختیار افراد غیرمجاز قرار ندهید.</div>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
