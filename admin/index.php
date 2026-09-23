<?php
/**
 * 📊 داشبورد اصلی پنل مدیریت
 * ===========================
 * ویجت‌های آماری + درخواست‌های اخیر + فعالیت‌ها + وضعیت موتور AI
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$pageTitle = 'داشبورد';
$activeMenu = 'dashboard';
require __DIR__ . '/includes/header.php';

$db = Database::getInstance();

/* 📊 جمع‌آوری آمار داشبورد */
try {
    $stats = [
        'brands'        => $db->count('brands', 'is_active = 1'),
        'published'     => $db->count('brands', "status = 'published'"),
        'articles'      => $db->count('brand_articles'),
        'ai_articles'   => $db->count('brand_articles', 'generated_by_ai = 1'),
        'requests_new'  => $db->count('service_requests', "status = 'new'"),
        'requests_total'=> $db->count('service_requests'),
        'visits_today'  => (int)$db->fetchValue('SELECT COUNT(DISTINCT session_hash) FROM visits WHERE visit_date = CURDATE()'),
        'visits_week'   => (int)$db->fetchValue('SELECT COUNT(DISTINCT session_hash) FROM visits WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)'),
        'error_codes'   => $db->count('error_codes'),
        'devices'       => $db->count('brand_devices'),
    ];

    // 📈 بازدید ۷ روز اخیر برای نمودار
    $visitsChart = $db->fetchAll(
        'SELECT visit_date, COUNT(DISTINCT session_hash) as unique_visits, COUNT(*) as views
         FROM visits WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
         GROUP BY visit_date ORDER BY visit_date'
    );

    // 🏷️ پربازدیدترین برندها
    $topBrands = $db->fetchAll(
        'SELECT b.name_fa, b.logo, COUNT(DISTINCT v.session_hash) as visits
         FROM brands b LEFT JOIN visits v ON v.brand_id = b.id
         GROUP BY b.id ORDER BY visits DESC, b.name_fa LIMIT 6'
    );

    // 📨 درخواست‌های اخیر
    $recentRequests = $db->fetchAll(
        'SELECT r.*, b.name_fa AS brand_name, b.logo AS brand_logo
         FROM service_requests r LEFT JOIN brands b ON b.id = r.brand_id
         ORDER BY r.id DESC LIMIT 8'
    );

    // 📝 فعالیت‌های اخیر
    $activities = Logger::recentActivities(10);

    // 🤖 وضعیت موتور AI
    $aiStats = (new SahandAI())->engineStats();

    // 🎨 اطلاعات اسکیل UI/UX Pro + 🖥️ وضعیت سیستم (PHP)
    $uiuxInfo = (new UIUXPro())->info();
    $sysInfo = [
        'php'        => PHP_VERSION,
        'sapi'       => PHP_SAPI,
        'gd'         => extension_loaded('gd') && function_exists('imagecreatetruecolor'),
        'freetype'   => function_exists('imagettftext'),
        'opcache'    => extension_loaded('Zend OPcache') && (ini_get('opcache.enable') === '1'),
        'memory'     => ini_get('memory_limit') ?: '—',
        'exec_time'  => ini_get('max_execution_time') ?: '—',
        'upload'     => ini_get('upload_max_filesize') ?: '—',
        'mbstring'   => extension_loaded('mbstring'),
        'curl'       => extension_loaded('curl'),
    ];
} catch (Throwable $e) {
    // ⚠️ از ۲.۴.۱: Throwable (شامل Error) گرفته می‌شود تا خطای موتور AI هرگز صفحه را خالی نکند
    echo '<div class="alert alert-danger">⚠️ خطای بارگذاری آمار: ' . e($e->getMessage()) . '</div>';
    $stats = $topBrands = $recentRequests = $activities = $visitsChart = [];
    $aiStats = [];
    $uiuxInfo = [];
    $sysInfo = [
        'php' => PHP_VERSION, 'gd' => false, 'freetype' => false, 'opcache' => false,
        'memory' => '—', 'exec_time' => '—', 'upload' => '—', 'mbstring' => false, 'curl' => false,
    ];
}

// 🏷️ نقشه وضعیت درخواست
$statusMap = [
    'new' => ['جدید', 'badge-danger'],
    'reviewing' => ['در حال بررسی', 'badge-warning'],
    'assigned' => ['تخصیص یافته', 'badge-info'],
    'done' => ['انجام شده', 'badge-success'],
    'canceled' => ['لغو شده', 'badge-secondary'],
];
?>

<!-- 📊 کارت‌های آماری -->
<div class="stats-grid">
    <a class="stat-card" href="brands.php">
        <div class="icon bg-blue">🏷️</div>
        <div>
            <div class="number"><?= en_to_fa_digits((string)$stats['brands']) ?></div>
            <div class="label">برند فعال (<?= en_to_fa_digits((string)$stats['published']) ?> منتشرشده)</div>
        </div>
    </a>
    <a class="stat-card" href="requests.php">
        <div class="icon bg-red">📨</div>
        <div>
            <div class="number"><?= en_to_fa_digits((string)$stats['requests_new']) ?></div>
            <div class="label">درخواست جدید (کل: <?= en_to_fa_digits((string)$stats['requests_total']) ?>)</div>
        </div>
    </a>
    <a class="stat-card" href="analytics.php">
        <div class="icon bg-cyan">📈</div>
        <div>
            <div class="number"><?= en_to_fa_digits((string)$stats['visits_today']) ?></div>
            <div class="label">بازدید امروز (۷ روز: <?= en_to_fa_digits((string)$stats['visits_week']) ?>)</div>
        </div>
    </a>
    <a class="stat-card" href="articles.php">
        <div class="icon bg-green">📰</div>
        <div>
            <div class="number"><?= en_to_fa_digits((string)$stats['articles']) ?></div>
            <div class="label">مقاله (<?= en_to_fa_digits((string)$stats['ai_articles']) ?> تولید AI)</div>
        </div>
    </a>
    <a class="stat-card" href="error-codes.php">
        <div class="icon bg-orange">🚨</div>
        <div>
            <div class="number"><?= en_to_fa_digits((string)$stats['error_codes']) ?></div>
            <div class="label">کد خطای ثبت‌شده</div>
        </div>
    </a>
    <a class="stat-card" href="brands.php">
        <div class="icon bg-purple">🔧</div>
        <div>
            <div class="number"><?= en_to_fa_digits((string)$stats['devices']) ?></div>
            <div class="label">دستگاه ثبت‌شده</div>
        </div>
    </a>
</div>

<div class="grid-2">
    <!-- 📈 نمودار بازدید ۷ روز اخیر -->
    <div class="card">
        <div class="card-header">
            <h3>📈 روند بازدید ۷ روز اخیر</h3>
            <div class="tools"><a href="analytics.php" class="btn btn-outline btn-sm">گزارش کامل</a></div>
        </div>
        <div class="card-body">
            <?php if (empty($visitsChart)): ?>
                <div class="empty-state">
                    <div class="icon">📉</div>
                    <p>هنوز بازدیدی ثبت نشده است.<br><small>پس از راه‌اندازی اولین سایت برند، آمار اینجا نمایش داده می‌شود.</small></p>
                </div>
            <?php else: ?>
                <?php
                // نمودار ستونی ساده CSS (بدون وابستگی خارجی)
                $maxVisits = max(1, max(array_column($visitsChart, 'unique_visits')));
                $days = ['Sat' => 'شنبه', 'Sun' => 'یکشنبه', 'Mon' => 'دوشنبه', 'Tue' => 'سه‌شنبه', 'Wed' => 'چهارشنبه', 'Thu' => 'پنجشنبه', 'Fri' => 'جمعه'];
                ?>
                <div style="display:flex;align-items:flex-end;gap:10px;height:180px;padding:10px 4px">
                    <?php foreach ($visitsChart as $day): ?>
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end">
                            <span style="font-size:11px;font-weight:700;color:var(--primary)"><?= en_to_fa_digits((string)$day['unique_visits']) ?></span>
                            <div style="width:100%;max-width:46px;background:linear-gradient(180deg,#3b82f6,#1e40af);border-radius:8px 8px 3px 3px;height:<?= max(6, round($day['unique_visits'] / $maxVisits * 100)) ?>%;transition:height .5s"></div>
                            <span style="font-size:10.5px;color:var(--text-light)"><?= $days[date('D', strtotime($day['visit_date']))] ?? jdate($day['visit_date']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 🤖 وضعیت موتور AI -->
    <div class="card">
        <div class="card-header"><h3>🤖 وضعیت موتور هوش مصنوعی</h3></div>
        <div class="card-body">
            <table class="table">
                <tr><td>📚 برندهای پایگاه دانش</td><td style="text-align:left;font-weight:700"><?= en_to_fa_digits((string)($aiStats['knowledge_brands'] ?? 0)) ?></td></tr>
                <tr><td>🧩 قالب‌های محتوایی</td><td style="text-align:left;font-weight:700"><?= en_to_fa_digits((string)($aiStats['knowledge_templates'] ?? 0)) ?> دسته</td></tr>
                <tr><td>🔤 واژه‌های مترادف</td><td style="text-align:left;font-weight:700"><?= en_to_fa_digits((string)($aiStats['synonym_words'] ?? 0)) ?></td></tr>
                <tr><td>📰 مقالات تولیدشده</td><td style="text-align:left;font-weight:700"><?= en_to_fa_digits((string)($aiStats['articles_generated'] ?? 0)) ?></td></tr>
                <tr><td>❓ سوالات متداول تولیدشده</td><td style="text-align:left;font-weight:700"><?= en_to_fa_digits((string)($aiStats['faqs_generated'] ?? 0)) ?></td></tr>
                <tr><td>✅ سلامت یکتایی محتوا</td><td style="text-align:left;font-weight:700"><?= $aiStats['uniqueness_health'] ?? '—' ?></td></tr>
                <tr><td>🎨 اسکیل UI/UX Pro</td><td style="text-align:left;font-weight:700"><?= en_to_fa_digits((string)($uiuxInfo['page_blueprints'] ?? 0)) ?> بلوپرینت • <?= en_to_fa_digits((string)($uiuxInfo['ux_laws'] ?? 0)) ?> قانون UX</td></tr>
            </table>
        </div>
    </div>
</div>

<div class="grid-2">
    <!-- 📨 درخواست‌های اخیر -->
    <div class="card">
        <div class="card-header">
            <h3>📨 درخواست‌های خدمات اخیر</h3>
            <div class="tools"><a href="requests.php" class="btn btn-outline btn-sm">همه درخواست‌ها</a></div>
        </div>
        <div class="table-wrap">
            <?php if (empty($recentRequests)): ?>
                <div class="empty-state"><div class="icon">📭</div><p>هنوز درخواستی ثبت نشده است.</p></div>
            <?php else: ?>
            <table class="table">
                <thead><tr><th>برند</th><th>متقاضی</th><th>دستگاه</th><th>وضعیت</th><th>زمان</th></tr></thead>
                <tbody>
                <?php foreach ($recentRequests as $req): ?>
                    <tr>
                        <td>
                            <div class="brand-cell">
                                <?php if ($req['brand_logo']): ?><img src="<?= asset_url($req['brand_logo']) ?>" alt=""><?php endif; ?>
                                <span class="name"><?= e($req['brand_name'] ?? '—') ?></span>
                            </div>
                        </td>
                        <td><?= e($req['full_name']) ?></td>
                        <td><?= e($req['device_key']) ?><?= $req['device_other'] ? ' (' . e($req['device_other']) . ')' : '' ?></td>
                        <td><span class="badge <?= $statusMap[$req['status']][1] ?? 'badge-secondary' ?>"><?= $statusMap[$req['status']][0] ?? $req['status'] ?></span></td>
                        <td style="font-size:11.5px;color:var(--text-light)"><?= time_ago_fa($req['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- 📝 فعالیت‌های اخیر -->
    <div class="card">
        <div class="card-header"><h3>📝 آخرین فعالیت‌ها</h3></div>
        <div class="card-body">
            <?php if (empty($activities)): ?>
                <div class="empty-state"><div class="icon">🕐</div><p>فعالیتی ثبت نشده است.</p></div>
            <?php else: ?>
                <div class="log-timeline">
                    <?php foreach (array_slice($activities, 0, 8) as $log): ?>
                        <div class="log-item">
                            <div class="title"><?= e($log['action']) ?><?php if ($log['full_name']): ?> <small style="color:var(--text-light)">— <?= e($log['full_name']) ?></small><?php endif; ?></div>
                            <div class="meta"><?= time_ago_fa($log['created_at']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 🖥️ وضعیت سیستم و عملکرد (PHP 8.3) -->
<div class="card">
    <div class="card-header">
        <h3>🖥️ وضعیت سیستم و عملکرد</h3>
        <div class="tools"><span class="badge <?= version_compare(PHP_VERSION, '8.0.0', '>=') ? 'badge-success' : 'badge-danger' ?>" style="direction:ltr">PHP <?= PHP_VERSION ?></span></div>
    </div>
    <div class="card-body">
        <?php
        $sysItems = [
            ['🖥️ نسخه PHP', PHP_VERSION, version_compare(PHP_VERSION, '8.0.0', '>='), 'نسخه ۸ به بالای PHP'],
            ['🖼️ کتابخانه GD', $sysInfo['gd'] ? ('فعال' . ($sysInfo['freetype'] ? ' + FreeType' : '')) : 'غیرفعال', $sysInfo['gd'], 'برای کپچا و تحلیل لوگو (در صورت غیرفعال بودن، کپچای SVG جایگزین می‌شود)'],
            ['⚡ OPcache', $sysInfo['opcache'] ? 'فعال' : 'غیرفعال', $sysInfo['opcache'], 'شتاب‌دهنده PHP — از ۲ تا ۵ برابر سرعت'],
            ['🔤 mbstring', $sysInfo['mbstring'] ? 'فعال' : 'غیرفعال', $sysInfo['mbstring'], 'پردازش متن فارسی چندبایتی'],
            ['🌐 cURL', $sysInfo['curl'] ? 'فعال' : 'غیرفعال', $sysInfo['curl'], 'جستجوی وب و تلگرام'],
            ['🧠 حافظه مجاز', $sysInfo['memory'], true, 'memory_limit'],
            ['⏱️ زمان اجرا', $sysInfo['exec_time'] . 's', true, 'max_execution_time'],
            ['📤 سقف آپلود', $sysInfo['upload'], true, 'upload_max_filesize'],
        ];
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px">
            <?php foreach ($sysItems as [$label, $value, $ok, $hint]): ?>
                <div title="<?= e($hint) ?>" style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:9px 13px;border:1.5px solid var(--border);border-radius:10px;font-size:12.5px">
                    <span><?= e($label) ?></span>
                    <span class="badge <?= $ok ? 'badge-success' : 'badge-danger' ?>" style="direction:ltr"><?= e((string)$value) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$sysInfo['opcache']): ?>
            <div class="alert alert-warning" style="margin-top:10px">⚠️ OPcache غیرفعال است — فعال‌کردن آن در تنظیمات PHP هاست، سرعت سایت‌ساز را ۲ تا ۵ برابر افزایش می‌دهد (در cPanel: Select PHP Version → Options → opcache).</div>
        <?php endif; ?>
    </div>
</div>

<!-- ⚡ دسترسی سریع -->
<div class="card">
    <div class="card-header"><h3>⚡ دسترسی سریع</h3></div>
    <div class="card-body" style="display:flex;gap:10px;flex-wrap:wrap">
        <a href="brand-new.php" class="btn btn-primary">➕ ساخت سایت برند جدید</a>
        <a href="articles.php?generate=1" class="btn btn-success">🤖 تولید مقاله با AI</a>
        <a href="settings.php" class="btn btn-outline">⚙️ تنظیمات نمایندگی</a>
        <a href="export.php" class="btn btn-outline">📦 خروجی سایت‌ها</a>
        <a href="docs.php" class="btn btn-outline">📚 مستندات راهنما</a>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
