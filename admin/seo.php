<?php
/**
 * 🔍 مرکز سئو — نمای ۳۶۰ درجه سئوی کل سیستم
 * ===========================================
 * ۴ سطح: عمومی | برند | صفحه | مقاله + چک‌لیست فنی + ابزار تحلیل
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();
$seoAnalyzer = new SeoAnalyzer();
$seoGenerator = new SeoGenerator();
$keywordAnalyzer = new KeywordAnalyzer();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'analyze') {
    Auth::enforceCsrf();
    $analysisRequest = [
        'title' => post('a_title'),
        'meta_description' => post('a_meta'),
        'content' => '<p>' . post('a_content') . '</p>',
        'slug' => SlugGenerator::generate(post('a_title')),
        'seo_robots' => 'index,follow',
        'og_image' => post('a_og'),
    ];
    $focus = post('a_focus');
    $result = $seoAnalyzer->analyze($analysisRequest, $focus);
    $keywords = $keywordAnalyzer->extract(post('a_content'), 10);
    $density = $focus !== '' ? $keywordAnalyzer->density(post('a_content'), $focus) : 0;
    // ذخیره نتیجه در سشن برای نمایش
    $_SESSION['seo_analysis'] = ['result' => $result, 'keywords' => $keywords, 'density' => $density, 'focus' => $focus];
    redirect('seo.php?tab=tools');
}

$pageTitle = 'مرکز سئو';
$activeMenu = 'seo';
require __DIR__ . '/includes/header.php';

/* 📊 جمع‌آوری داده‌ها */
$brands = $db->fetchAll('SELECT id, name_fa, domain, status, seo_title FROM brands ORDER BY name_fa');
$brandStats = [];
foreach ($brands as $brand) {
    $pages = $db->fetchAll('SELECT id, page_type, seo_score, title, seo_title FROM brand_pages WHERE brand_id = ?', [$brand['id']]);
    $scores = array_filter(array_column($pages, 'seo_score'));
    $articles = $db->count('brand_articles', 'brand_id = ? AND status = ?', [$brand['id'], 'published']);
    $brandStats[$brand['id']] = [
        'pages' => count($pages),
        'avg_score' => $scores ? round(array_sum($scores) / count($scores)) : 0,
        'with_seo' => count($scores),
        'articles' => $articles,
        'pages_list' => $pages,
    ];
}

$tab = get_param('tab', 'overview');
$analysisResult = $_SESSION['seo_analysis'] ?? null;
unset($_SESSION['seo_analysis']);
?>
<div class="tabs">
    <button type="button" class="tab-btn <?= $tab === 'overview' ? 'active' : '' ?>" onclick="switchTab(this,'tab-overview')">📊 نمای کلی</button>
    <button type="button" class="tab-btn <?= $tab === 'brands' ? 'active' : '' ?>" onclick="switchTab(this,'tab-brands')">🏷️ برندها و صفحات</button>
    <button type="button" class="tab-btn <?= $tab === 'technical' ? 'active' : '' ?>" onclick="switchTab(this,'tab-technical')">⚙️ چک‌لیست فنی</button>
    <button type="button" class="tab-btn <?= $tab === 'tools' ? 'active' : '' ?>" onclick="switchTab(this,'tab-tools')">🛠️ ابزار تحلیل</button>
</div>

<!-- 📊 نمای کلی -->
<div id="tab-overview" class="tab-pane <?= $tab === 'overview' ? 'active' : '' ?>">
    <?php
    $allScores = [];
    foreach ($brandStats as $bs) {
        foreach ($bs['pages_list'] as $p) {
            if ((int)$p['seo_score'] > 0) {
                $allScores[] = (int)$p['seo_score'];
            }
        }
    }
    $avg = $allScores ? round(array_sum($allScores) / count($allScores)) : 0;
    $excellent = count(array_filter($allScores, fn($s) => $s >= 75));
    ?>
    <div class="stats-grid">
        <div class="stat-card"><div class="icon bg-blue">📊</div><div><div class="number"><?= en_to_fa_digits((string)$avg) ?>/۱۰۰</div><div class="label">میانگین امتیاز سئو</div></div></div>
        <div class="stat-card"><div class="icon bg-cyan">📄</div><div><div class="number"><?= en_to_fa_digits((string)count($allScores)) ?></div><div class="label">صفحه تحلیل‌شده</div></div></div>
        <div class="stat-card"><div class="icon bg-green">🏆</div><div><div class="number"><?= en_to_fa_digits((string)$excellent) ?></div><div class="label">صفحه عالی (۷۵+)</div></div></div>
        <div class="stat-card"><div class="icon bg-purple">🏷️</div><div><div class="number"><?= en_to_fa_digits((string)count($brands)) ?></div><div class="label">برند تحت پوشش سئو</div></div></div>
    </div>

    <div class="card">
        <div class="card-header"><h3>🎯 راهنمای رسیدن به امتیاز ۱۰۰/۱۰۰</h3></div>
        <div class="card-body" style="font-size:13px;line-height:2.2">
            <ol style="margin-inline-start:20px">
                <li><b>عنوان ۳۰-۶۰ کاراکتر</b> حاوی کلیدواژه کانونی و نام برند (فارسی + انگلیسی)</li>
                <li><b>متا توضیحات ۱۲۰-۱۶۰ کاراکتر</b> با دعوت به اقدام</li>
                <li><b>محتوای ۶۰۰+ کلمه</b> با ساختار H2/H3 منظم و یک H1 یکتا</li>
                <li><b>تراکم کلیدواژه ۱-۳٪</b> و حضور آن در ۱۵۰ کلمه اول</li>
                <li><b>Alt توصیفی</b> برای تمام تصاویر + حداقل ۲ لینک داخلی</li>
                <li><b>Schema.org</b> (به صورت خودکار تولید می‌شود) + OG Image ۱۲۰۰×۶۳۰</li>
                <li><b>sitemap.xml و robots.txt</b> — به صورت خودکار در خروجی ZIP تولید می‌شوند</li>
            </ol>
        </div>
    </div>
</div>

<!-- 🏷️ برندها و صفحات -->
<div id="tab-brands" class="tab-pane <?= $tab === 'brands' ? 'active' : '' ?>">
    <?php foreach ($brands as $brand): $bs = $brandStats[$brand['id']]; ?>
        <div class="card">
            <div class="card-header">
                <h3>🏷️ <?= e($brand['name_fa']) ?> <code style="font-size:11px;direction:ltr"><?= e($brand['domain']) ?></code></h3>
                <div class="tools">
                    <span class="badge <?= $bs['avg_score'] >= 70 ? 'badge-success' : 'badge-warning' ?>">میانگین: <?= en_to_fa_digits((string)$bs['avg_score']) ?>/۱۰۰</span>
                    <a href="brand-edit.php?id=<?= (int)$brand['id'] ?>&tab=seo" class="btn btn-outline btn-sm">✏️ ویرایش سئو</a>
                </div>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>صفحه</th><th>عنوان سئو</th><th>امتیاز</th></tr></thead>
                    <tbody>
                    <?php foreach ($bs['pages_list'] as $page): ?>
                        <tr>
                            <td style="font-weight:700;font-size:12.5px"><?= e($page['page_type']) ?></td>
                            <td style="max-width:400px;font-size:12px"><?= e(excerpt((string)($page['seo_title'] ?: $page['title']), 70)) ?: '—' ?></td>
                            <td>
                                <?php if ((int)$page['seo_score'] > 0): ?>
                                    <span class="badge <?= (int)$page['seo_score'] >= 75 ? 'badge-success' : ((int)$page['seo_score'] >= 50 ? 'badge-warning' : 'badge-danger') ?>"><?= en_to_fa_digits((string)$page['seo_score']) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">تحلیل نشده</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ⚙️ چک‌لیست فنی -->
<div id="tab-technical" class="tab-pane <?= $tab === 'technical' ? 'active' : '' ?>">
    <div class="card">
        <div class="card-header"><h3>⚙️ چک‌لیست سئوی فنی سیستم</h3></div>
        <div class="card-body">
            <?php
            $context = [
                'sitemap' => true, 'robots' => true, 'ssl' => true, 'lazy' => true,
                'minified' => true, 'schema' => true, 'canonical' => true,
                'custom404' => true, 'og' => true, 'twitter' => true,
            ];
            $checks = $seoAnalyzer->technicalChecklist($context);
            ?>
            <table class="table">
                <thead><tr><th>مورد فنی</th><th>وضعیت</th><th>توضیح</th></tr></thead>
                <tbody>
                <?php
                $techNotes = [
                    'sitemap_xml' => 'تولید خودکار برای هر سایت برند در فایل ZIP',
                    'robots_txt' => 'تولید خودکار + قابل ویرایش دستی',
                    'ssl' => '.htaccess و لینک‌های HTTPS آماده',
                    'responsive' => 'تمام قالب‌ها کاملاً ریسپانسیو',
                    'lazy_loading' => 'loading=lazy در تمام تصاویر',
                    'minified' => 'CSS/JS فشرده در هسته سایت برند',
                    'semantic' => 'HTML5 معنایی (header/main/section/footer)',
                    'schema' => '۵ نوع Schema JSON-LD خودکار',
                    'canonical' => 'تولید خودکار برای تمام صفحات',
                    '404_custom' => 'صفحه ۴۰۴ اختصاصی فارسی',
                    'og_tags' => 'تولید خودکار از سئوی هر صفحه',
                    'twitter_cards' => 'summary_large_image خودکار',
                ];
                foreach ($checks as [$id, $label, $passed]): ?>
                    <tr>
                        <td style="font-weight:700"><?= e($label) ?></td>
                        <td><span class="badge <?= $passed ? 'badge-success' : 'badge-danger' ?>"><?= $passed ? '✅ فعال' : '❌' ?></span></td>
                        <td style="font-size:12px;color:var(--text-light)"><?= e($techNotes[$id] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 🛠️ ابزار تحلیل -->
<div id="tab-tools" class="tab-pane <?= $tab === 'tools' ? 'active' : '' ?>">
    <div class="grid-2">
        <div class="card">
            <div class="card-header"><h3>🛠️ تحلیل‌گر محتوا و کلیدواژه</h3></div>
            <div class="card-body">
                <form method="post">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="analyze">
                    <div class="form-group"><label>عنوان صفحه</label><input type="text" name="a_title" class="form-control" required></div>
                    <div class="form-group"><label>کلیدواژه کانونی</label><input type="text" name="a_focus" class="form-control" placeholder="تعمیر یخچال سامسونگ"></div>
                    <div class="form-group"><label>متا توضیحات</label><textarea name="a_meta" class="form-control" rows="2"></textarea></div>
                    <div class="form-group"><label>محتوا</label><textarea name="a_content" class="form-control" rows="8"></textarea></div>
                    <button type="submit" class="btn btn-primary btn-block">🔍 تحلیل کن</button>
                </form>
            </div>
        </div>
        <div>
            <?php if ($analysisResult): ?>
                <?php $r = $analysisResult['result']; ?>
                <div class="card">
                    <div class="card-header">
                        <h3>📊 نتیجه تحلیل — امتیاز <?= en_to_fa_digits((string)$r['score']) ?>/۱۰۰ (<?= e($r['grade']) ?>)</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($analysisResult['density'] > 0): ?>
                            <div class="alert alert-info">🔑 تراکم کلیدواژه «<?= e($analysisResult['focus']) ?>»: <b><?= en_to_fa_digits((string)$analysisResult['density']) ?>٪</b> (بهینه: ۱-۳٪)</div>
                        <?php endif; ?>
                        <?php $passed = array_filter($r['checks'], fn($c) => $c['passed']); $failed = array_filter($r['checks'], fn($c) => !$c['passed']); ?>
                        <h4 style="font-size:13px;margin-bottom:8px">✅ موارد رعایت‌شده (<?= count($passed) ?>)</h4>
                        <?php foreach (array_slice($passed, 0, 6) as $check): ?>
                            <div style="font-size:12px;margin-bottom:4px">✔ <?= e($check['label']) ?> <b>(+<?= $check['weight'] ?>)</b></div>
                        <?php endforeach; ?>
                        <?php if (!empty($failed)): ?>
                            <h4 style="font-size:13px;margin:14px 0 8px">⚠️ نیازمند بهبود (<?= count($failed) ?>)</h4>
                            <?php foreach ($failed as $check): ?>
                                <div class="alert alert-warning" style="padding:7px 12px;font-size:12px;margin-bottom:6px">📌 <b><?= e($check['label']) ?></b> — <?= e($check['advice']) ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <h4 style="font-size:13px;margin:14px 0 8px">🔑 کلیدواژه‌های برتر محتوا</h4>
                        <div style="display:flex;gap:5px;flex-wrap:wrap">
                            <?php foreach (array_slice($analysisResult['keywords'], 0, 8) as $kw): ?>
                                <span class="badge badge-primary"><?= e($kw['keyword']) ?> (<?= en_to_fa_digits((string)$kw['count']) ?>)</span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="card"><div class="empty-state"><div class="icon">🛠️</div><p>محتوای خود را در فرم روبه‌رو وارد و تحلیل کنید تا امتیاز سئو، پیشنهادهای بهبود و کلیدواژه‌ها نمایش داده شود.</p></div></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
