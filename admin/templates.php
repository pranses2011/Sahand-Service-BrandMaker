<?php
/**
 * 🧩 مدیریت قالب‌ها — کتابخانه ۱۰ سبک × ۱۷ نوع صفحه (v2.21)
 * ===========================================================
 * 🆕 v2.21: کتابخانه قالب‌های آماده TemplateLibrary — هر نوع صفحه
 * دقیقاً ۱۰ قالب با سبک‌های واقعاً متفاوت (مدرن/کلاسیک/مینیمال/لوکس/
 * فنی-تیره/شرکتی/مجله‌ای/پرانرژی/تبدیل‌محور/صمیمی).
 * سید خودکارِ غیرمخبر: قالب‌های دستی و پیش‌فرض‌های فعلی هرگز لمس
 * نمی‌شوند؛ فقط جفت‌های غایب درج می‌شوند (مسیر ارتقا بدون تغییر DB).
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
        $tpl = $db->fetch('SELECT variant, name FROM templates WHERE id = ?', [$id]);
        /* 🛡️ v2.21: قالب‌های کتابخانه آماده قابل حذف نیستند — دکمه ↻ بازسازی همیشه برمی‌گردانند؛ حذف فقط برای قالب‌های سفارشی */
        if ($tpl && in_array((string)$tpl['variant'], array_map(static fn($k) => TemplateLibrary::variantLabel($k), array_keys(TemplateLibrary::styleLabels())), true)) {
            flash('warning', '🔒 قالب‌های کتابخانه آماده قابل حذف نیستند — با دکمه «🎭 ویرایش» قابل شخصی‌سازی و «💾 ذخیره به‌عنوان نسخه جدید» هستند.');
            redirect('templates.php');
        }
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
    /* 🆕 v2.21: بازسازی دستی کتابخانه — درج قالب‌های غایب */
    if ($action === 'rebuild_library') {
        $result = TemplateLibrary::seedMissing($db);
        flash($result['inserted'] > 0
            ? '✅ ' . en_to_fa_digits((string)$result['inserted']) . ' قالب کتابلایه غایب اضافه شد (قالب‌های موجود دست‌نخورده ماندند).'
            : '✅ کتابخانه قالب‌ها کامل است — هیچ قالب غایبی وجود ندارد.', 'success');
        redirect('templates.php');
    }
}

/* 🌱 v2.21: سید خودکار کتابخانه (غیرمخبر) — در هر بار باز شدن صفحه فقط کمبودها تکمیل می‌شوند.
   مسیر ارتقا: نصب‌های قدیمی با اولین بازدید، ۱۷۰ قالب کتابلایه را می‌گیرند. */
try {
    $libSeed = TemplateLibrary::seedMissing($db);
    if ($libSeed['inserted'] > 0) {
        flash('success', '📚 ' . en_to_fa_digits((string)$libSeed['inserted']) . ' قالب کتابلایه جدید اضافه شد — هر نوع صفحه اکنون ۱۰ سبک متفاوت دارد (قالب‌های قبلی شما دست‌نخورده ماندند).');
    }
} catch (Throwable $seedErr) {
    Logger::error('خطای سید کتابخانه قالب‌ها', ['message' => $seedErr->getMessage()]);
    $libSeed = ['inserted' => 0, 'defaults_set' => 0];
}

$pageTitle = 'مدیریت قالب‌ها';
$activeMenu = 'templates';
require __DIR__ . '/includes/header.php';

$templates = $db->fetchAll('SELECT * FROM templates ORDER BY page_type, id');
$grouped = [];
foreach ($templates as $tpl) {
    $grouped[$tpl['page_type']][] = $tpl;
}
$pageTypeNames = TemplateLibrary::pageTypeLabels(); /* 🆕 v2.21: همه ۱۷ نوع صفحه */
$libVariants = array_map(static fn($k) => TemplateLibrary::variantLabel($k), array_keys(TemplateLibrary::styleLabels()));
$libCount = TemplateLibrary::countLibraryTemplates($db);
$styleDesc = TemplateLibrary::info()['styles_fa'];
?>

<div class="card" style="margin-bottom:16px">
    <div class="card-body" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding:14px 18px">
        <div style="flex:1;min-width:240px">
            <b>📚 کتابخانه قالب‌های آماده</b> — <span class="badge badge-info"><?= en_to_fa_digits((string)$libCount) ?> قالب کتابلایه</span>
            <span class="hint" style="display:block;margin-top:4px">هر نوع صفحه ۱۰ سبک متفاوت دارد: <?= implode(' • ', $styleDesc) ?></span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <form method="post"><?= Auth::csrfField() ?><input type="hidden" name="action" value="rebuild_library"><button type="submit" class="btn btn-outline btn-sm" title="قالب‌های غایب کتابلایه را اضافه کن — قالب‌های موجود دست نمی‌خورند">↻ بازسازی کتابخانه</button></form>
            <a href="template-builder.php?page=home" class="btn btn-primary btn-sm">🎭 قالب جدید بساز</a>
        </div>
    </div>
</div>

<?php foreach ($grouped as $pageType => $list): ?>
<div class="card">
    <div class="card-header">
        <h3><?= $pageTypeNames[$pageType] ?? e($pageType) ?> <span class="badge badge-secondary"><?= en_to_fa_digits((string)count($list)) ?> طرح</span></h3>
        <div class="tools"><a href="template-builder.php?page=<?= e($pageType) ?>" class="btn btn-primary btn-sm">🎭 قالب‌ساز</a></div>
    </div>
    <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px">
        <?php foreach ($list as $tpl): ?>
            <?php $isLib = in_array((string)$tpl['variant'], $libVariants, true); ?>
            <div style="border:1.5px solid var(--border);border-radius:12px;overflow:hidden">
                <!-- پیش‌نمایش بصری چیدمان -->
                <div style="height:110px;background:linear-gradient(135deg,#eef2ff,#e0e7ff);display:flex;flex-direction:column;gap:4px;padding:10px">
                    <?php $layout = json_decode($tpl['layout_json'] ?? '[]', true) ?: []; ?>
                    <?php foreach (array_slice($layout, 0, 5) as $block): ?>
                        <?php
                        /* 🎨 رنگ ردیف پیش‌نمایش بر اساس پس‌زمینه بلوک — حس تفاوت سبک‌ها */
                        $bgMap = ['primary' => 'rgba(30,64,175,.30)', 'gradient' => 'rgba(180,83,9,.30)', 'dark' => 'rgba(15,23,42,.55)', 'surface' => 'rgba(30,64,175,.20)'];
                        $rowBg = $bgMap[(string)($block['props']['background'] ?? '')] ?? 'rgba(30,64,175,.14)';
                        $rowFg = (($block['props']['background'] ?? '') === 'dark') ? '#e2e8f0' : '#1e40af';
                        ?>
                        <div style="flex:1;background:<?= $rowBg ?>;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:8.5px;color:<?= $rowFg ?>;overflow:hidden;white-space:nowrap"><?= e($block['block'] ?? '') ?></div>
                    <?php endforeach; ?>
                </div>
                <div style="padding:10px 12px">
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px">
                        <strong style="font-size:12.5px;flex:1"><?= e($tpl['name']) ?></strong>
                        <?php if ($tpl['is_default']): ?><span class="badge badge-success">پیش‌فرض</span><?php endif; ?>
                        <?php if ($isLib): ?><span class="badge badge-secondary" style="font-size:9px" title="قالب آماده کتابلایه">📚</span><?php endif; ?>
                    </div>
                    <div style="display:flex;gap:5px;flex-wrap:wrap">
                        <a href="template-builder.php?id=<?= (int)$tpl['id'] ?>" class="btn btn-outline btn-sm">🎭 ویرایش</a>
                        <?php if (!$tpl['is_default']): ?>
                        <form method="post" style="display:inline"><?= Auth::csrfField() ?><input type="hidden" name="action" value="make_default"><input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>"><button class="btn btn-outline btn-sm" type="submit" title="پیش‌فرض این نوع صفحه شود">⭐</button></form>
                        <?php if (!$isLib): ?>
                        <form method="post" style="display:inline" data-confirm="این قالب حذف شود؟"><?= Auth::csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>"><button class="btn btn-danger btn-sm" type="submit" title="حذف قالب سفارشی">🗑️</button></form>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<div class="alert alert-info">💡 قالب پیش‌فرض هر صفحه، هنگام ساخت سایت برند جدید به‌صورت خودکار اعمال می‌شود. برای ویرایش چیدمان بلوک‌ها از <a href="template-builder.php" style="color:inherit"><b>قالب‌ساز</b></a> استفاده کنید. قالب‌های 📚 کتابلایه قابل حذف نیستند اما آزادانه ویرایش می‌شوند — نسخه ویرایش‌شده را با نام جدید ذخیره کنید تا در کتابلایه بماند.</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
