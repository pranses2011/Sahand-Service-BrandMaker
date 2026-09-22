<?php
/**
 * 🧩 مدیریت قالب‌ها — ۵ طرح پیش‌فرض برای هر صفحه
 * ================================================
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();

/* 💾 ذخیره/عملیات */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::enforceCsrf();
    $action = post('action');

    if ($action === 'save') {
        $id = (int)post('template_id');
        $db->update('templates', [
            'name'        => post('name'),
            'layout_json' => (string)($_POST['layout_json'] ?? '[]'),
            'is_default'  => !empty($_POST['is_default']) ? 1 : 0,
        ], 'id = ?', [$id]);
        flash('success', '✅ قالب ذخیره شد.');
        redirect('templates.php');
    }
    if ($action === 'delete') {
        $id = (int)post('template_id');
        $db->delete('templates', 'id = ?', [$id]);
        flash('success', '🗑️ قالب حذف شد.');
        redirect('templates.php');
    }
    if ($action === 'make_default') {
        $id = (int)post('template_id');
        $tpl = $db->fetch('SELECT page_type FROM templates WHERE id = ?', [$id]);
        if ($tpl) {
            $db->update('templates', ['is_default' => 0], 'page_type = ?', [$tpl['page_type']]);
            $db->update('templates', ['is_default' => 1], 'id = ?', [$id]);
            flash('success', '✅ قالب پیش‌فرض شد.');
        }
        redirect('templates.php');
    }
}

/* 🌱 ایجاد قالب‌های پیش‌فرض (در صورت نبود) */
$defaultVariants = [
    'home' => ['مدرن', 'کلاسیک', 'مینیمال', 'حرفه‌ای', 'خلاقانه'],
    'services' => ['گرید', 'لیستی', 'کارتی', 'تب‌دار', 'آکاردئونی'],
    'blog' => ['بلاگ', 'مجله', 'گرید', 'ماسونری', 'تایم‌لاین'],
    'contact' => ['ساده', 'نقشه‌دار', 'فرم بزرگ', 'چند ستونه', 'تمام صفحه'],
    'about' => ['داستانی', 'دو ستونه', 'تیمی', 'جدولی', 'گالری'],
];

/* چیدمان پایه هر نوع صفحه (بلوک‌ها) */
$baseLayouts = [
    'home' => ['hero', 'features', 'intro', 'services-grid', 'cta-phone', 'articles-recent', 'testimonials', 'brands-links'],
    'services' => ['breadcrumb', 'services-grid', 'cta-request'],
    'blog' => ['breadcrumb', 'articles-grid', 'pagination'],
    'contact' => ['breadcrumb', 'contact-info', 'contact-form', 'map'],
    'about' => ['breadcrumb', 'text-image', 'stats', 'features'],
];

$tplCount = $db->count('templates');
if ($tplCount === 0) {
    foreach ($defaultVariants as $pageType => $variants) {
        $base = $baseLayouts[$pageType] ?? ['breadcrumb', 'text'];
        foreach ($variants as $i => $variant) {
            // تنوع چیدمان: جابجایی و حذف/افزودن بلوک‌ها بین طرح‌ها
            $layout = $base;
            if ($i === 1) {
                $layout = array_reverse($layout); // کلاسیک: معکوس
            } elseif ($i === 2) {
                $layout = array_slice($layout, 0, max(3, count($layout) - 2)); // مینیمال: کمترین بلوک
            } elseif ($i === 3) {
                array_unshift($layout, 'top-bar'); // حرفه‌ای: نوار بالا
            } elseif ($i === 4) {
                array_splice($layout, 1, 0, 'counter-stats'); // خلاقانه: شمارنده
            }
            $db->insert('templates', [
                'name'        => $pageType . ' — ' . $variant,
                'page_type'   => $pageType,
                'variant'     => $variant,
                'layout_json' => json_encode(array_map(function ($block, $idx) {
                    return ['block' => $block, 'props' => new stdClass(), 'order' => $idx];
                }, $layout, array_keys($layout)), JSON_UNESCAPED_UNICODE),
                'is_default'  => $i === 0 ? 1 : 0,
            ]);
        }
    }
    flash('info', '🌱 قالب‌های پیش‌فرض (۵ طرح × ۵ نوع صفحه) ایجاد شدند.');
    redirect('templates.php');
}


$pageTitle = 'مدیریت قالب‌ها';
$activeMenu = 'templates';
require __DIR__ . '/includes/header.php';

/* 🌱 ایجاد قالب‌های پیش‌فرض (در صورت نبود) */
$defaultVariants = [
    'home' => ['مدرن', 'کلاسیک', 'مینیمال', 'حرفه‌ای', 'خلاقانه'],
    'services' => ['گرید', 'لیستی', 'کارتی', 'تب‌دار', 'آکاردئونی'],
    'blog' => ['بلاگ', 'مجله', 'گرید', 'ماسونری', 'تایم‌لاین'],
    'contact' => ['ساده', 'نقشه‌دار', 'فرم بزرگ', 'چند ستونه', 'تمام صفحه'],
    'about' => ['داستانی', 'دو ستونه', 'تیمی', 'جدولی', 'گالری'],
];

/* چیدمان پایه هر نوع صفحه (بلوک‌ها) */
$baseLayouts = [
    'home' => ['hero', 'features', 'intro', 'services-grid', 'cta-phone', 'articles-recent', 'testimonials', 'brands-links'],
    'services' => ['breadcrumb', 'services-grid', 'cta-request'],
    'blog' => ['breadcrumb', 'articles-grid', 'pagination'],
    'contact' => ['breadcrumb', 'contact-info', 'contact-form', 'map'],
    'about' => ['breadcrumb', 'text-image', 'stats', 'features'],
];

$tplCount = $db->count('templates');
if ($tplCount === 0) {
    foreach ($defaultVariants as $pageType => $variants) {
        $base = $baseLayouts[$pageType] ?? ['breadcrumb', 'text'];
        foreach ($variants as $i => $variant) {
            // تنوع چیدمان: جابجایی و حذف/افزودن بلوک‌ها بین طرح‌ها
            $layout = $base;
            if ($i === 1) {
                $layout = array_reverse($layout); // کلاسیک: معکوس
            } elseif ($i === 2) {
                $layout = array_slice($layout, 0, max(3, count($layout) - 2)); // مینیمال: کمترین بلوک
            } elseif ($i === 3) {
                array_unshift($layout, 'top-bar'); // حرفه‌ای: نوار بالا
            } elseif ($i === 4) {
                array_splice($layout, 1, 0, 'counter-stats'); // خلاقانه: شمارنده
            }
            $db->insert('templates', [
                'name'        => $pageType . ' — ' . $variant,
                'page_type'   => $pageType,
                'variant'     => $variant,
                'layout_json' => json_encode(array_map(function ($block, $idx) {
                    return ['block' => $block, 'props' => new stdClass(), 'order' => $idx];
                }, $layout, array_keys($layout)), JSON_UNESCAPED_UNICODE),
                'is_default'  => $i === 0 ? 1 : 0,
            ]);
        }
    }
    flash('info', '🌱 قالب‌های پیش‌فرض (۵ طرح × ۵ نوع صفحه) ایجاد شدند.');
    redirect('templates.php');
}

$templates = $db->fetchAll('SELECT * FROM templates ORDER BY page_type, id');
$grouped = [];
foreach ($templates as $tpl) {
    $grouped[$tpl['page_type']][] = $tpl;
}
$pageTypeNames = ['home' => '🏠 صفحه اصلی', 'services' => '🔧 خدمات', 'blog' => '📰 مقالات', 'contact' => '📞 تماس', 'about' => 'ℹ️ درباره'];
?>

<?php foreach ($grouped as $pageType => $list): ?>
<div class="card">
    <div class="card-header">
        <h3><?= $pageTypeNames[$pageType] ?? e($pageType) ?> <span class="badge badge-secondary"><?= en_to_fa_digits((string)count($list)) ?> طرح</span></h3>
        <div class="tools"><a href="template-builder.php?page=<?= e($pageType) ?>" class="btn btn-primary btn-sm">🎭 قالب‌ساز</a></div>
    </div>
    <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px">
        <?php foreach ($list as $tpl): ?>
            <div style="border:1.5px solid var(--border);border-radius:12px;overflow:hidden">
                <!-- پیش‌نمایش بصری چیدمان -->
                <div style="height:110px;background:linear-gradient(135deg,#eef2ff,#e0e7ff);display:flex;flex-direction:column;gap:4px;padding:10px">
                    <?php $layout = json_decode($tpl['layout_json'] ?? '[]', true) ?: []; ?>
                    <?php foreach (array_slice($layout, 0, 5) as $block): ?>
                        <div style="flex:1;background:rgba(30,64,175,.14);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:8.5px;color:#1e40af;overflow:hidden;white-space:nowrap"><?= e($block['block'] ?? '') ?></div>
                    <?php endforeach; ?>
                </div>
                <div style="padding:10px 12px">
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px">
                        <strong style="font-size:12.5px;flex:1"><?= e($tpl['name']) ?></strong>
                        <?php if ($tpl['is_default']): ?><span class="badge badge-success">پیش‌فرض</span><?php endif; ?>
                    </div>
                    <div style="display:flex;gap:5px">
                        <a href="template-builder.php?id=<?= (int)$tpl['id'] ?>" class="btn btn-outline btn-sm">✏️ ویرایش</a>
                        <?php if (!$tpl['is_default']): ?>
                        <form method="post" style="display:inline"><?= Auth::csrfField() ?><input type="hidden" name="action" value="make_default"><input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>"><button class="btn btn-outline btn-sm" type="submit">⭐</button></form>
                        <form method="post" style="display:inline" data-confirm="این قالب حذف شود؟"><?= Auth::csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>"><button class="btn btn-danger btn-sm" type="submit">🗑️</button></form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<div class="alert alert-info">💡 قالب پیش‌فرض هر صفحه، هنگام ساخت سایت برند جدید به‌صورت خودکار اعمال می‌شود. برای ویرایش چیدمان بلوک‌ها از <a href="template-builder.php" style="color:inherit"><b>قالب‌ساز</b></a> استفاده کنید.</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
