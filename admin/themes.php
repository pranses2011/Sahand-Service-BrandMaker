<?php
/**
 * 🎨 مدیریت تم‌ها — ترکیب قالب‌ها و اختصاص به برندها
 * ================================================
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::enforceCsrf();
    $action = post('action');

    /* 💾 ایجاد/ویرایش تم */
    if ($action === 'save') {
        $id = (int)post('theme_id');
        $config = [
            'home'     => post('tpl_home'),
            'services' => post('tpl_services'),
            'blog'     => post('tpl_blog'),
            'contact'  => post('tpl_contact'),
            'about'    => post('tpl_about'),
        ];
        $data = [
            'name'        => post('name'),
            'description' => post('description'),
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE),
        ];
        if ($id > 0) {
            $db->update('themes', $data, 'id = ?', [$id]);
        } else {
            $data['is_default'] = 0;
            $id = $db->insert('themes', $data);
        }
        /* 🎨 v2.27 — پاک‌سازی کش API سایت‌های برند (ریشه «تغییر تم اعمال
           نمی‌شود»): چیدمان تم از کش ۱۲۰ ثانیه‌ای خوانده می‌شد. */
        (new Cache())->flush('api_brand_');
        flash('success', '✅ تم ذخیره شد — روی سایت‌های برندِ این تم پس از چند لحظه اعمال می‌شود.');
        redirect('themes.php');
    }

    if ($action === 'delete') {
        $id = (int)post('theme_id');
        $inUse = $db->fetchValue('SELECT COUNT(*) FROM brands WHERE theme_id = ?', [$id]);
        if ((int)$inUse > 0) {
            flash('danger', '⛔ این تم به ' . $inUse . ' برند اختصاص یافته — ابتدا برندها را تغییر دهید.');
        } else {
            $db->delete('themes', 'id = ?', [$id]);
            flash('success', '🗑️ تم حذف شد.');
        }
        redirect('themes.php');
    }

    if ($action === 'make_default') {
        $id = (int)post('theme_id');
        $db->update('themes', ['is_default' => 0], '1=1');
        $db->update('themes', ['is_default' => 1], 'id = ?', [$id]);
        (new Cache())->flush('api_brand_');
        flash('success', '✅ تم پیش‌فرض برندهای جدید شد.');
        redirect('themes.php');
    }

    if ($action === 'assign') {
        $brandId = (int)post('brand_id');
        $themeId = (int)post('theme_id');
        $db->update('brands', ['theme_id' => $themeId ?: null], 'id = ?', [$brandId]);
        /* 🎨 v2.27 — کش همان برند پاک شود تا تم جدید بلافاصله دیده شود */
        (new Cache())->flush('api_brand_' . $brandId);
        flash('success', '✅ تم به برند اختصاص یافت — سایت برند پس از چند لحظه تم جدید را نشان می‌دهد.');
        redirect('themes.php');
    }
}

/* 🌱 تم پیش‌فرض سیستمی */
if ($db->count('themes') === 0) {
    $defaultTemplates = [];
    foreach (['home', 'services', 'blog', 'contact', 'about'] as $pt) {
        $tpl = $db->fetch('SELECT id FROM templates WHERE page_type = ? AND is_default = 1', [$pt]);
        $defaultTemplates[$pt] = $tpl ? (int)$tpl['id'] : null;
    }
    $db->insert('themes', [
        'name' => 'تم پیش‌فرض سهند',
        'description' => 'ترکیب قالب‌های پیش‌فرض — برای برندهای جدید',
        'config_json' => json_encode($defaultTemplates),
        'is_default' => 1,
    ]);
    redirect('themes.php');
}


$pageTitle = 'مدیریت تم‌ها';
$activeMenu = 'themes';
require __DIR__ . '/includes/header.php';

/* 🌱 تم پیش‌فرض سیستمی */
if ($db->count('themes') === 0) {
    $defaultTemplates = [];
    foreach (['home', 'services', 'blog', 'contact', 'about'] as $pt) {
        $tpl = $db->fetch('SELECT id FROM templates WHERE page_type = ? AND is_default = 1', [$pt]);
        $defaultTemplates[$pt] = $tpl ? (int)$tpl['id'] : null;
    }
    $db->insert('themes', [
        'name' => 'تم پیش‌فرض سهند',
        'description' => 'ترکیب قالب‌های پیش‌فرض — برای برندهای جدید',
        'config_json' => json_encode($defaultTemplates),
        'is_default' => 1,
    ]);
    redirect('themes.php');
}

$themes = $db->fetchAll('SELECT * FROM themes ORDER BY is_default DESC, id');
$templates = $db->fetchAll('SELECT id, name, page_type FROM templates ORDER BY page_type, name');
$tplMap = [];
foreach ($templates as $tpl) {
    $tplMap[$tpl['page_type']][$tpl['id']] = $tpl['name'];
}
$brands = $db->fetchAll('SELECT id, name_fa, theme_id FROM brands ORDER BY name_fa');
$editTheme = null;
if (($editId = (int)get_param('edit')) > 0) {
    foreach ($themes as $t) {
        if ((int)$t['id'] === $editId) {
            $editTheme = $t;
            break;
        }
    }
}
?>
<div class="grid-2">
    <div>
        <?php foreach ($themes as $theme): ?>
            <?php $config = json_decode($theme['config_json'] ?? '{}', true) ?: []; ?>
            <div class="card">
                <div class="card-header">
                    <h3>🎨 <?= e($theme['name']) ?> <?= $theme['is_default'] ? '<span class="badge badge-success">پیش‌فرض</span>' : '' ?></h3>
                    <div class="tools">
                        <a href="themes.php?edit=<?= (int)$theme['id'] ?>" class="btn btn-outline btn-sm">✏️</a>
                        <?php if (!$theme['is_default']): ?>
                        <form method="post" style="display:inline"><?= Auth::csrfField() ?><input type="hidden" name="action" value="make_default"><input type="hidden" name="theme_id" value="<?= (int)$theme['id'] ?>"><button class="btn btn-outline btn-sm">⭐</button></form>
                        <form method="post" style="display:inline" data-confirm="این تم حذف شود؟"><?= Auth::csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="theme_id" value="<?= (int)$theme['id'] ?>"><button class="btn btn-danger btn-sm">🗑️</button></form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body" style="font-size:12.5px">
                    <p style="color:var(--text-light);margin-bottom:10px"><?= e($theme['description'] ?? '') ?></p>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <?php foreach (['home' => 'خانه', 'services' => 'خدمات', 'blog' => 'مقالات', 'contact' => 'تماس', 'about' => 'درباره'] as $pt => $label): ?>
                            <span class="badge badge-primary"><?= $label ?>: <?= e($tplMap[$pt][(int)($config[$pt] ?? 0)] ?? 'پیش‌فرض') ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php $assigned = array_filter($brands, fn($b) => (int)$b['theme_id'] === (int)$theme['id']); ?>
                    <div style="margin-top:10px;font-size:11.5px;color:var(--text-light)">
                        🏷️ برندهای اختصاص‌یافته: <?= $assigned ? e(implode('، ', array_column($assigned, 'name_fa'))) : '—' ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div>
        <!-- فرم ساخت/ویرایش تم -->
        <div class="card">
            <div class="card-header"><h3><?= $editTheme ? '✏️ ویرایش تم' : '➕ تم جدید' ?></h3></div>
            <div class="card-body">
                <form method="post">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="theme_id" value="<?= (int)($editTheme['id'] ?? 0) ?>">
                    <?php $editConfig = $editTheme ? (json_decode($editTheme['config_json'] ?? '{}', true) ?: []) : []; ?>
                    <div class="form-group">
                        <label>نام تم</label>
                        <input type="text" name="name" class="form-control" required value="<?= e($editTheme['name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>توضیح</label>
                        <input type="text" name="description" class="form-control" value="<?= e($editTheme['description'] ?? '') ?>">
                    </div>
                    <?php foreach (['home' => 'صفحه اصلی', 'services' => 'خدمات', 'blog' => 'مقالات', 'contact' => 'تماس', 'about' => 'درباره'] as $pt => $label): ?>
                        <div class="form-group">
                            <label>قالب <?= $label ?></label>
                            <select name="tpl_<?= $pt ?>" class="form-control">
                                <?php foreach (($tplMap[$pt] ?? []) as $tid => $tname): ?>
                                    <option value="<?= $tid ?>" <?= (int)($editConfig[$pt] ?? 0) === $tid ? 'selected' : '' ?>><?= e($tname) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-primary btn-block">💾 ذخیره تم</button>
                    <?php if ($editTheme): ?><a href="themes.php" class="btn btn-outline btn-block" style="margin-top:8px">انصراف از ویرایش</a><?php endif; ?>
                </form>
            </div>
        </div>

        <!-- اختصاص تم به برند -->
        <div class="card">
            <div class="card-header"><h3>🏷️ اختصاص تم به برند</h3></div>
            <div class="card-body">
                <form method="post">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="assign">
                    <div class="form-group">
                        <label>برند</label>
                        <select name="brand_id" class="form-control" required>
                            <option value="">انتخاب برند...</option>
                            <?php foreach ($brands as $brand): ?>
                                <option value="<?= (int)$brand['id'] ?>"><?= e($brand['name_fa']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>تم</label>
                        <select name="theme_id" class="form-control" required>
                            <?php foreach ($themes as $theme): ?>
                                <option value="<?= (int)$theme['id'] ?>"><?= e($theme['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-success btn-block">✅ اختصاص</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
