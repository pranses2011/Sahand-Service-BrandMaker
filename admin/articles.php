<?php
/**
 * 📰 مدیریت مقالات برندها
 * ========================
 * لیست + فیلتر برند/وضعیت + ویرایشگر + تولید AI + زمان‌بندی
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();

/* 🗑️ حذف مقاله */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    Auth::enforceCsrf();
    $db->delete('brand_articles', 'id = ?', [(int)post('article_id')]);
    flash('success', '🗑️ مقاله حذف شد.');
    redirect('articles.php' . (get_param('brand') !== '' ? '?brand=' . get_param('brand') : ''));
}

/* 💾 تغییر وضعیت سریع */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'status') {
    Auth::enforceCsrf();
    $id = (int)post('article_id');
    $status = post('status');
    if (in_array($status, ['draft', 'published'], true)) {
        $db->update('brand_articles', [
            'status' => $status,
            'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
        ], 'id = ?', [$id]);
        flash('success', '✅ وضعیت مقاله تغییر کرد.');
    }
    redirect('articles.php' . (get_param('brand') !== '' ? '?brand=' . get_param('brand') : ''));
}

/* 💾 ذخیره ویرایش مقاله */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    Auth::enforceCsrf();
    $id = (int)post('article_id');
    $db->update('brand_articles', [
        'title'           => post('title'),
        'content'         => Validator::sanitizeHtml((string)($_POST['content'] ?? '')),
        'excerpt'         => post('excerpt'),
        'seo_title'       => post('seo_title') ?: null,
        'seo_description' => post('seo_description') ?: null,
        'tags'            => json_encode(array_filter(array_map('trim', explode('،', post('tags')))), JSON_UNESCAPED_UNICODE),
        'updated_at'      => date('Y-m-d H:i:s'),
    ], 'id = ?', [$id]);
    (new Cache())->delete('brand_articles_all');
    flash('success', '✅ مقاله ذخیره شد.');
    redirect('articles.php?edit=' . $id);
}

/* 🤖 تولید مقاله با AI */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'generate') {
    Auth::enforceCsrf();
    try {
        $ai = new SahandAI();
        $article = $ai->generateArticle([
            'brand_id'   => (int)post('brand_id'),
            'topic_type' => post('topic_type') ?: 'troubleshooting',
            'device_key' => post('device_key') ?: null,
        ]);
        $id = $ai->saveArticle((int)post('brand_id'), $article, post('topic_type') ?: 'troubleshooting');
        flash('success', '🤖 مقاله تولید شد: «' . $article['title'] . '» (' . $article['word_count'] . ' کلمه)');
        redirect('articles.php?edit=' . $id);
    } catch (Exception $e) {
        flash('danger', 'خطای تولید: ' . $e->getMessage());
        redirect('articles.php?generate=1');
    }
}

$pageTitle = 'مدیریت مقالات';
$activeMenu = 'articles';
require __DIR__ . '/includes/header.php';

$brands = $db->fetchAll('SELECT id, name_fa FROM brands ORDER BY name_fa');
$editArticle = null;
$showGenerate = (int)get_param('generate') === 1 || (empty($brands) === false && get_param('generate') !== '');

/* ✏️ ویرایش مقاله */
if (($editId = (int)get_param('edit')) > 0) {
    $editArticle = $db->fetch(
        'SELECT a.*, b.name_fa AS brand_name FROM brand_articles a JOIN brands b ON b.id = a.brand_id WHERE a.id = ?',
        [$editId]
    );
}

/* 🔎 فیلترها */
$brandFilter = (int)get_param('brand');
$statusFilter = get_param('status');
$where = '1=1';
$params = [];
if ($brandFilter > 0) {
    $where .= ' AND a.brand_id = ?';
    $params[] = $brandFilter;
}
if ($statusFilter !== '') {
    $where .= ' AND a.status = ?';
    $params[] = $statusFilter;
}
$page = max(1, (int)get_param('p'));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$total = $db->count('brand_articles a', $where, $params);
$articles = $db->fetchAll(
    "SELECT a.*, b.name_fa AS brand_name, b.logo AS brand_logo
     FROM brand_articles a JOIN brands b ON b.id = a.brand_id
     WHERE {$where} ORDER BY a.id DESC LIMIT {$perPage} OFFSET {$offset}",
    $params
);
$statusMap = ['draft' => ['پیش‌نویس', 'badge-secondary'], 'scheduled' => ['زمان‌بندی', 'badge-warning'], 'published' => ['منتشرشده', 'badge-success']];
$categories = $db->fetchAll('SELECT id, name_fa FROM article_categories');
?>

<?php if ($editArticle): ?>
<!-- ✏️ فرم ویرایش مقاله -->
<div class="card">
    <div class="card-header">
        <h3>✏️ ویرایش مقاله — <?= e($editArticle['brand_name']) ?></h3>
        <div class="tools">
            <?php if ($editArticle['generated_by_ai']): ?><span class="badge badge-info">🤖 تولید AI</span><?php endif; ?>
            <a href="articles.php" class="btn btn-outline btn-sm">بازگشت</a>
        </div>
    </div>
    <div class="card-body">
        <form method="post">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="article_id" value="<?= (int)$editArticle['id'] ?>">
            <div class="form-group">
                <label>عنوان مقاله</label>
                <input type="text" name="title" class="form-control" required value="<?= e($editArticle['title']) ?>">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>خلاصه (Excerpt)</label>
                    <textarea name="excerpt" class="form-control" rows="2"><?= e($editArticle['excerpt'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>تگ‌ها (با «،» جدا شوند)</label>
                    <input type="text" name="tags" class="form-control" value="<?= e(implode('، ', json_decode($editArticle['tags'] ?? '[]', true) ?: [])) ?>">
                </div>
            </div>
            <div class="form-group">
                <label>محتوا (HTML)</label>
                <textarea name="content" class="form-control" rows="18" style="font-family:monospace;font-size:12.5px;direction:rtl"><?= e($editArticle['content']) ?></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>عنوان سئو</label>
                    <input type="text" name="seo_title" class="form-control" value="<?= e($editArticle['seo_title'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>متا توضیحات</label>
                    <input type="text" name="seo_description" class="form-control" value="<?= e($editArticle['seo_description'] ?? '') ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg">💾 ذخیره مقاله</button>
        </form>
    </div>
</div>

<?php else: ?>

<?php if (!empty($showGenerate) && !empty($brands)): ?>
<!-- 🤖 فرم تولید با AI -->
<div class="card" style="border-color:var(--primary)">
    <div class="card-header"><h3>🤖 تولید مقاله با هوش مصنوعی داخلی</h3></div>
    <div class="card-body">
        <form method="post">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="generate">
            <div class="form-row-3">
                <div class="form-group">
                    <label>برند</label>
                    <select name="brand_id" class="form-control" required>
                        <?php foreach ($brands as $brand): ?>
                            <option value="<?= (int)$brand['id'] ?>"><?= e($brand['name_fa']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>نوع مقاله</label>
                    <select name="topic_type" class="form-control">
                        <option value="troubleshooting">رفع ایراد</option>
                        <option value="user_guide">راهنمای استفاده</option>
                        <option value="maintenance">نگهداری</option>
                        <option value="comparison">مقایسه مدل‌ها</option>
                        <option value="error_codes">کدهای خطا</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>دستگاه (خالی = تصادفی)</label>
                    <select name="device_key" class="form-control">
                        <option value="">— تصادفی —</option>
                        <?php foreach ($db->fetchAll('SELECT DISTINCT device_key, name_fa FROM brand_devices ORDER BY name_fa') as $device): ?>
                            <option value="<?= e($device['device_key']) ?>"><?= e($device['name_fa']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-success btn-lg">🚀 تولید مقاله یکتا</button>
            <div class="hint" style="margin-top:8px">موتور AI محتوای ۸۰۰-۱۵۰۰ کلمه‌ای یکتا با لینک داخلی و سئو تولید می‌کند.</div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- 🔎 فیلتر و لیست -->
<div class="card">
    <div class="card-header">
        <h3>📰 مقالات (<?= en_to_fa_digits((string)$total) ?>)</h3>
        <div class="tools">
            <form method="get" style="display:flex;gap:8px;flex-wrap:wrap">
                <select name="brand" class="form-control" style="max-width:170px;min-width:140px">
                    <option value="">همه برندها</option>
                    <?php foreach ($brands as $brand): ?>
                        <option value="<?= (int)$brand['id'] ?>" <?= $brandFilter === (int)$brand['id'] ? 'selected' : '' ?>><?= e($brand['name_fa']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status" class="form-control" style="max-width:150px;min-width:120px">
                    <option value="">همه وضعیت‌ها</option>
                    <?php foreach ($statusMap as $key => [$label]): ?>
                        <option value="<?= $key ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline">فیلتر</button>
                <a href="articles.php?generate=1" class="btn btn-success">🤖 تولید جدید</a>
            </form>
        </div>
    </div>
    <div class="table-wrap">
        <?php if (empty($articles)): ?>
            <div class="empty-state"><div class="icon">📰</div><p>مقاله‌ای یافت نشد.<br><small>با دکمه «تولید جدید» اولین مقاله را با AI بسازید.</small></p></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>عنوان</th><th>برند</th><th>وضعیت</th><th>کلمات</th><th>سئو</th><th>انتشار</th><th>عملیات</th></tr></thead>
            <tbody>
            <?php foreach ($articles as $article): ?>
                <tr>
                    <td style="max-width:320px">
                        <div style="font-weight:700;font-size:12.5px"><?= e(excerpt($article['title'], 60)) ?></div>
                        <small style="color:var(--text-light);direction:ltr;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($article['slug']) ?></small>
                    </td>
                    <td><div class="brand-cell"><span class="name" style="font-size:12px"><?= e($article['brand_name']) ?></span></div></td>
                    <td>
                        <?php if ($article['status'] === 'scheduled'): ?>
                            <span class="badge badge-warning" title="<?= e($article['published_at'] ?? '') ?>">⏰ <?= jdate($article['published_at'] ?? '') ?></span>
                        <?php else: ?>
                            <span class="badge <?= $statusMap[$article['status']][1] ?>"><?= $statusMap[$article['status']][0] ?></span>
                        <?php endif; ?>
                        <?= $article['generated_by_ai'] ? '<span class="badge badge-info">AI</span>' : '' ?>
                    </td>
                    <td><?= en_to_fa_digits((string)TextProcessor::wordCount(strip_tags((string)$article['content']))) ?></td>
                    <td><span class="badge <?= ($article['seo_score'] ?? 0) >= 60 ? 'badge-success' : 'badge-warning' ?>"><?= en_to_fa_digits((string)($article['seo_score'] ?? 0)) ?></span></td>
                    <td style="font-size:11px;color:var(--text-light)"><?= $article['published_at'] ? jdate($article['published_at']) : '—' ?></td>
                    <td>
                        <div class="actions">
                            <a href="articles.php?edit=<?= (int)$article['id'] ?>" class="btn btn-outline btn-sm">✏️</a>
                            <?php if ($article['status'] !== 'published'): ?>
                                <form method="post" style="display:inline"><?= Auth::csrfField() ?><input type="hidden" name="action" value="status"><input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>"><input type="hidden" name="status" value="published"><button class="btn btn-success btn-sm" title="انتشار">▶️</button></form>
                            <?php else: ?>
                                <form method="post" style="display:inline"><?= Auth::csrfField() ?><input type="hidden" name="action" value="status"><input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>"><input type="hidden" name="status" value="draft"><button class="btn btn-outline btn-sm" title="پیش‌نویس">⏸️</button></form>
                            <?php endif; ?>
                            <form method="post" style="display:inline" data-confirm="این مقاله حذف شود؟"><?= Auth::csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>"><button class="btn btn-danger btn-sm">🗑️</button></form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php if ($total > $perPage): ?>
        <div class="pagination">
            <?php for ($p = 1; $p <= ceil($total / $perPage); $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="current"><?= en_to_fa_digits((string)$p) ?></span>
                <?php else: ?>
                    <a href="?p=<?= $p ?>&brand=<?= $brandFilter ?>&status=<?= e($statusFilter) ?>"><?= en_to_fa_digits((string)$p) ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
