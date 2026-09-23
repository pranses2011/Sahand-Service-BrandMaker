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
            'brand_id'     => (int)post('brand_id'),
            'topic_type'   => post('topic_type') ?: 'troubleshooting',
            'device_key'   => post('device_key') ?: null,
            'custom_title' => trim((string)post('custom_title')) ?: null, // 🆕 فاز Q.5
        ]);
        $id = $ai->saveArticle((int)post('brand_id'), $article, post('topic_type') ?: 'troubleshooting');
        flash('success', '🤖 مقاله تولید شد: «' . $article['title'] . '» (' . $article['word_count'] . ' کلمه)');
        redirect('articles.php?edit=' . $id);
    } catch (Exception $e) {
        flash('danger', 'خطای تولید: ' . $e->getMessage());
        redirect('articles.php?generate=1');
    }
}

/* 🎯 پیشنهاد بهترین عنوان سئو برای عنوان دلخواه (فاز Q.5 — AJAX) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'suggest_titles') {
    Auth::enforceCsrf();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $customTitle = trim((string)post('custom_title'));
        if (mb_strlen($customTitle) < 5) {
            json_response(['success' => false, 'error' => 'عنوان دلخواه بسیار کوتاه است (حداقل ۵ نویسه).']);
        }
        $context = [];
        $brandId = (int)post('brand_id');
        if ($brandId > 0) {
            $brand = $db->fetch('SELECT name_fa FROM brands WHERE id = ?', [$brandId]);
            if ($brand) {
                $context['brand_fa'] = $brand['name_fa'];
            }
        }
        $deviceKey = trim((string)post('device_key'));
        if ($deviceKey !== '') {
            $device = $db->fetch('SELECT name_fa FROM brand_devices WHERE device_key = ? LIMIT 1', [$deviceKey]);
            if ($device) {
                $context['device_fa'] = $device['name_fa'];
            }
        }
        $titleGen = new TitleGenerator();
        json_response(['success' => true, 'data' => $titleGen->suggestForCustom($customTitle, $context, 8)]);
    } catch (Exception $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
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
                    <select name="brand_id" id="gen-brand" class="form-control" required>
                        <?php foreach ($brands as $brand): ?>
                            <option value="<?= (int)$brand['id'] ?>"><?= e($brand['name_fa']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>نوع مقاله (۲۵ نوع)</label>
                    <select name="topic_type" class="form-control">
                        <optgroup label="🔧 فنی و عیب‌یابی">
                            <option value="troubleshooting">رفع ایراد و مشکلات رایج</option>
                            <option value="error_codes">کدهای خطا و ریست</option>
                            <option value="symptom_focus">عیب‌یابی علامت‌محور (روشن نمی‌شود، صدا، نشتی...)</option>
                            <option value="case_study">مطالعه موردی تعمیر واقعی</option>
                            <option value="diy_vs_pro">تعمیر شخصی یا تخصصی؟</option>
                        </optgroup>
                        <optgroup label="📘 آموزشی و راهنما">
                            <option value="user_guide">راهنمای استفاده</option>
                            <option value="installation_guide">راهنمای نصب و راه‌اندازی</option>
                            <option value="common_mistakes">اشتباهات رایج کاربران</option>
                            <option value="checklist">چک‌لیست بازدید و نگهداری</option>
                            <option value="glossary">واژه‌نامه تخصصی</option>
                            <option value="tech_explainer">فناوری‌های به‌کاررفته (اینورتر و...)</option>
                        </optgroup>
                        <optgroup label="🛡️ نگهداری و ایمنی">
                            <option value="maintenance">نگهداری و سرویس دوره‌ای</option>
                            <option value="seasonal_care">مراقبت فصلی</option>
                            <option value="safety_guide">نکات ایمنی و احتیاط</option>
                            <option value="expert_tips">نکات حرفه‌ای تکنسین‌ها</option>
                            <option value="environment">محیط زیست و بازیافت</option>
                        </optgroup>
                        <optgroup label="💰 خرید و هزینه">
                            <option value="buying_guide">راهنمای خرید</option>
                            <option value="comparison">مقایسه مدل‌ها</option>
                            <option value="cost_guide">راهنمای هزینه تعمیر</option>
                            <option value="warranty_guide">گارانتی و خدمات پس از فروش</option>
                            <option value="energy_saving">صرفه‌جویی انرژی و قبض</option>
                        </optgroup>
                        <optgroup label="📚 اعتمادسازی و محتوا">
                            <option value="myths_facts">باورهای غلط در برابر واقعیت</option>
                            <option value="history_evolution">تاریخچه و تکامل</option>
                            <option value="statistics">آمار و ارقام صنعت</option>
                            <option value="service_process">فرآیند تعمیر در نمایندگی</option>
                        </optgroup>
                    </select>
                </div>
                <div class="form-group">
                    <label>دستگاه (خالی = تصادفی)</label>
                    <select name="device_key" class="form-control" id="gen-device">
                        <option value="">— تصادفی —</option>
                        <?php foreach ($db->fetchAll('SELECT DISTINCT device_key, name_fa FROM brand_devices ORDER BY name_fa') as $device): ?>
                            <option value="<?= e($device['device_key']) ?>"><?= e($device['name_fa']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>عنوان دلخواه (اختیاری — فاز Q.5)</label>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <input type="text" name="custom_title" id="gen-custom-title" class="form-control" style="flex:1;min-width:220px" placeholder="مثلاً: چرا یخچال سامسونگ من سرد نمی‌کند؟" maxlength="120">
                    <button type="button" id="btn-suggest-titles" class="btn btn-outline" style="white-space:nowrap">🎯 پیشنهاد بهترین عنوان سئو</button>
                </div>
                <div class="hint" style="margin-top:6px">اگر خالی بگذارید، عنوان از قالب‌های نوع مقاله انتخاب می‌شود. با وارد کردن عنوان دلخواه، مقاله حول همان عنوان نوشته می‌شود.</div>
                <div id="title-suggestions" style="display:none;margin-top:12px" class="seo-stats"></div>
            </div>
            <button type="submit" class="btn btn-success btn-lg">🚀 تولید مقاله یکتا</button>
            <div class="hint" style="margin-top:8px">موتور AI محتوای ۸۰۰-۱۵۰۰ کلمه‌ای یکتا با لینک داخلی، سئو و اصلاح خودکار نگارش فارسی تولید می‌کند.</div>
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

<!-- 🎯 فاز Q.5: پیشنهاد بهترین عنوان سئو -->
<script>
(function () {
    'use strict';
    var btn = document.getElementById('btn-suggest-titles');
    var input = document.getElementById('gen-custom-title');
    var box = document.getElementById('title-suggestions');
    if (!btn || !input || !box) { return; }

    function faNum(n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function scoreBadge(s) {
        var cls = s >= 75 ? 'badge-success' : (s >= 55 ? 'badge-warning' : 'badge-secondary');
        return '<span class="badge ' + cls + '">' + faNum(s) + '/۱۰۰</span>';
    }

    btn.addEventListener('click', function () {
        var title = input.value.trim();
        if (title.length < 5) {
            alert('لطفاً عنوان دلخواه را وارد کنید (حداقل ۵ نویسه).');
            input.focus();
            return;
        }
        var csrf = document.querySelector('input[name="csrf_token"]');
        var brandSel = document.getElementById('gen-brand');
        var deviceSel = document.getElementById('gen-device');
        btn.disabled = true;
        btn.textContent = '⏳ در حال تحلیل عنوان...';
        box.style.display = 'block';
        box.innerHTML = '<div style="padding:12px;color:var(--text-light)">در حال تحلیل عنوان و ساخت پیشنهادهای سئو...</div>';

        var body = new URLSearchParams();
        body.append('action', 'suggest_titles');
        body.append('custom_title', title);
        body.append('brand_id', brandSel ? brandSel.value : '0');
        body.append('device_key', deviceSel ? deviceSel.value : '');
        if (csrf) { body.append('csrf_token', csrf.value); }

        fetch('articles.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf ? csrf.value : '' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (res) {
            btn.disabled = false;
            btn.textContent = '🎯 پیشنهاد بهترین عنوان سئو';
            if (!res.success) {
                box.innerHTML = '<div style="padding:12px;color:#e74c3c">خطا: ' + esc(res.error || 'نامشخص') + '</div>';
                return;
            }
            var d = res.data;
            var html = '<div style="border:1px solid var(--border);border-radius:10px;padding:14px;background:rgba(0,0,0,0.02)">';
            html += '<div style="font-weight:700;margin-bottom:6px">📊 تحلیل عنوان شما: ' + scoreBadge(d.original_score) + '</div>';
            html += '<div style="font-size:12px;color:var(--text-light);margin-bottom:4px">' + faNum(d.original_analysis.char_count) + ' کاراکتر — ' + esc(d.original_analysis.char_verdict) + '</div>';
            if (d.best_gain > 0) {
                html += '<div style="font-size:12.5px;margin:8px 0;color:#27ae60">🏆 بهترین پیشنهاد (+' + faNum(d.best_gain) + ' امتیاز): <b>' + esc(d.best) + '</b></div>';
            } else {
                html += '<div style="font-size:12.5px;margin:8px 0;color:#27ae60">✅ عنوان شما از نظر سئو وضعیت خوبی دارد؛ این گزینه‌ها نیز قابل بررسی‌اند:</div>';
            }
            html += '<div style="max-height:320px;overflow:auto;margin-top:8px">';
            (d.suggestions || []).forEach(function (s, i) {
                html += '<div class="title-suggest-item" data-title="' + esc(s.title).replace(/"/g, '&quot;') + '" style="display:flex;gap:10px;align-items:flex-start;padding:9px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px;cursor:pointer;background:#fff">'
                    + '<span style="min-width:26px;text-align:center;color:var(--text-light)">' + faNum(i + 1) + '.</span>'
                    + '<div style="flex:1"><div style="font-size:13px;font-weight:600">' + esc(s.title) + (s.is_original ? ' <span class="badge badge-info">عنوان شما</span>' : '') + '</div>'
                    + '<div style="font-size:11px;color:var(--text-light);margin-top:3px">' + faNum(s.char_count) + ' کاراکتر' + (s.has_number ? ' · 🔢 عدد' : '') + (s.is_question ? ' · ❓ پرسشی' : '') + (s.power_words && s.power_words.length ? ' · ⚡ ' + esc(s.power_words.join('، ')) : '') + (s.gain > 0 ? ' · <b style="color:#27ae60">+' + faNum(s.gain) + '</b>' : '') + '</div></div>'
                    + '<span>' + scoreBadge(s.score) + '</span></div>';
            });
            html += '</div><div class="hint" style="margin-top:8px">💡 روی هر پیشنهاد کلیک کنید تا جایگزین عنوان دلخواه شما شود — سپس «تولید مقاله» را بزنید.</div></div>';
            box.innerHTML = html;
            Array.prototype.forEach.call(box.querySelectorAll('.title-suggest-item'), function (item) {
                item.addEventListener('click', function () {
                    input.value = item.getAttribute('data-title');
                    input.focus();
                    box.querySelectorAll('.title-suggest-item').forEach(function (el) { el.style.outline = 'none'; });
                    item.style.outline = '2px solid var(--primary)';
                });
            });
        }).catch(function (err) {
            btn.disabled = false;
            btn.textContent = '🎯 پیشنهاد بهترین عنوان سئو';
            box.innerHTML = '<div style="padding:12px;color:#e74c3c">خطای ارتباط با سرور: ' + esc(err.message) + '</div>';
        });
    });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
