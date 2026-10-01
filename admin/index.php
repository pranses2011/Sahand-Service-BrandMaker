<?php
/**
 * 📊 داشبورد اصلی پنل مدیریت — بازطراحی حرفه‌ای v2.39
 * =====================================================
 * هیرو خوش‌آمد + کارت‌های آماری با روند + نمودار ۱۴ روزه +
 * پربازدیدترین برندها + درخواست‌های اخیر (دستگاه فارسی) +
 * فعالیت‌ها + وضعیت موتور AI + وضعیت سیستم + دسترسی سریع
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';
/* 🛡️ v2.33 — دفاع در عمق: رد درخواست بین‌سایتی (گزارش تحلیل — بخش امنیت) */
reject_cross_origin();

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
        'visits_yesterday' => (int)$db->fetchValue('SELECT COUNT(DISTINCT session_hash) FROM visits WHERE visit_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)'),
        'visits_week'   => (int)$db->fetchValue('SELECT COUNT(DISTINCT session_hash) FROM visits WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)'),
        'requests_week' => (int)$db->fetchValue('SELECT COUNT(*) FROM service_requests WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)'),
        'comments_pending' => $db->count('article_comments', "status = 'pending'"),
        'error_codes'   => $db->count('error_codes'),
        'devices'       => $db->count('brand_devices'),
    ];

    // 📈 بازدید ۱۴ روز اخیر برای نمودار (v2.39 — عمق بیشتر)
    $visitsChart = $db->fetchAll(
        'SELECT visit_date, COUNT(DISTINCT session_hash) as unique_visits, COUNT(*) as views
         FROM visits WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
         GROUP BY visit_date ORDER BY visit_date'
    );

    // 🏷️ پربازدیدترین برندها
    $topBrands = $db->fetchAll(
        'SELECT b.id, b.name_fa, b.logo, b.domain, COUNT(DISTINCT v.session_hash) as visits
         FROM brands b LEFT JOIN visits v ON v.brand_id = b.id
         GROUP BY b.id ORDER BY visits DESC, b.name_fa LIMIT 12'
    );
    /* 🛂 v2.34 — ACL داشبورد: brand_manager فقط آمار برندهای خودش را می‌بیند */
    $_aclIds = (new Auth())->accessibleBrandIds();
    if ($_aclIds !== null) {
        $topBrands = array_slice(array_values(array_filter($topBrands, function ($_b) use ($_aclIds) {
            return in_array((int)$_b['id'], $_aclIds, true);
        })), 0, 6);
    }
    $maxBrandVisits = $topBrands ? max(1, (int)max(array_column($topBrands, 'visits'))) : 1;

    // 📨 درخواست‌های اخیر — 🆕 v2.39: نام دستگاه «فارسی» از brand_devices
    $recentRequests = $db->fetchAll(
        'SELECT r.*, b.name_fa AS brand_name, b.logo AS brand_logo, d.name_fa AS device_fa
         FROM service_requests r
         LEFT JOIN brands b ON b.id = r.brand_id
         LEFT JOIN brand_devices d ON d.brand_id = r.brand_id AND d.device_key = r.device_key
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
        'disk_free'  => function_exists('disk_free_space') ? @disk_free_space(ROOT_PATH) : false,
    ];
} catch (Throwable $e) {
    // ⚠️ از ۲.۴.۱: Throwable (شامل Error) گرفته می‌شود تا خطای موتور AI هرگز صفحه را خالی نکند
    echo '<div class="alert alert-danger">⚠️ خطای بارگذاری آمار: ' . e($e->getMessage()) . '</div>';
    $stats = $topBrands = $recentRequests = $activities = $visitsChart = [];
    $aiStats = [];
    $uiuxInfo = [];
    $maxBrandVisits = 1;
    $sysInfo = [
        'php' => PHP_VERSION, 'gd' => false, 'freetype' => false, 'opcache' => false,
        'memory' => '—', 'exec_time' => '—', 'upload' => '—', 'mbstring' => false, 'curl' => false, 'disk_free' => false,
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

/* 📈 روند بازدید امروز نسبت به دیروز */
$visitsTrend = 0;
$visitsTrendTxt = '—';
if ($stats['visits_yesterday'] > 0) {
    $visitsTrend = round(($stats['visits_today'] - $stats['visits_yesterday']) / $stats['visits_yesterday'] * 100);
    $visitsTrendTxt = ($visitsTrend > 0 ? '▲ ' : ($visitsTrend < 0 ? '▼ ' : '')) . en_to_fa_digits((string)abs($visitsTrend)) . '٪ نسبت به دیروز';
} elseif ($stats['visits_today'] > 0) {
    $visitsTrendTxt = '✦ اولین بازدیدها امروز';
}
$convRate = $stats['visits_week'] > 0 ? round($stats['requests_week'] / $stats['visits_week'] * 100, 1) : 0;
$hour = (int)date('G');
$greet = $hour < 5 ? 'شب بخیر' : ($hour < 12 ? 'صبح بخیر' : ($hour < 17 ? 'وقت بخیر' : ($hour < 20 ? 'عصر بخیر' : 'شب بخیر')));
$meName = (string)($_SESSION['full_name'] ?? 'مدیر');
$meIni = mb_substr($meName !== '' ? $meName : 'م', 0, 1);
?>
<style>
/* 📊 v2.39 — داشبورد حرفه‌ای */
.dash-hero{position:relative;overflow:hidden;border-radius:18px;padding:26px 30px;margin-bottom:22px;color:#fff;
  background:linear-gradient(140deg,#0f172a 0%,#1e3a8a 55%,#3730a3 100%)}
.dash-hero::before{content:'';position:absolute;inset:0;pointer-events:none;
  background:radial-gradient(620px 260px at 88% -30%,rgba(59,130,246,.35),transparent 60%),
             radial-gradient(420px 220px at 5% 130%,rgba(20,184,166,.22),transparent 55%)}
.dash-hero>*{position:relative;z-index:1}
.dash-hero .dh-av{width:62px;height:62px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;
  font-size:25px;font-weight:900;background:linear-gradient(135deg,#38bdf8,#818cf8);border:2.5px solid rgba(255,255,255,.55);flex:none;object-fit:cover}
.dash-hero h1{margin:0;font-size:21px;font-weight:900}
.dash-hero .dh-sub{font-size:12.5px;color:#c7d7f5;margin-top:5px;line-height:2}
.dash-hero .dh-chips{display:flex;gap:9px;flex-wrap:wrap;margin-top:14px}
.dash-hero .dh-chip{background:rgba(255,255,255,.11);border:1px solid rgba(255,255,255,.18);border-radius:20px;
  padding:6px 15px;font-size:12px;font-weight:700;backdrop-filter:blur(4px)}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:14px;margin-bottom:22px}
.stat-card{display:flex;align-items:center;gap:14px;background:var(--card-bg,#fff);border:1px solid var(--border);
  border-radius:15px;padding:16px 18px;text-decoration:none;transition:.18s;position:relative;overflow:hidden}
.stat-card:hover{transform:translateY(-3px);box-shadow:0 12px 28px rgba(15,23,42,.1);border-color:#93c5fd}
.stat-card .icon{width:50px;height:50px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:23px;flex:none}
.stat-card .number{font-size:24px;font-weight:900;line-height:1.25;color:var(--text,#0f172a)}
.stat-card .label{font-size:11.5px;color:var(--text-light,#64748b);line-height:1.9}
.stat-card .trend{font-size:10.5px;font-weight:700;margin-top:2px}
.stat-card .trend.up{color:#059669}.stat-card .trend.down{color:#dc2626}.stat-card .trend.flat{color:#64748b}
.dash-chart-wrap{position:relative;height:190px;display:flex;align-items:flex-end;gap:6px;padding:12px 4px 4px}
.dash-chart-wrap .bar-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:5px;height:100%;justify-content:flex-end;min-width:0}
.dash-chart-wrap .bar{width:100%;max-width:40px;border-radius:7px 7px 2px 2px;background:linear-gradient(180deg,#60a5fa,#1e40af);
  transition:height .5s;position:relative;cursor:default}
.dash-chart-wrap .bar.today{background:linear-gradient(180deg,#34d399,#059669)}
.dash-chart-wrap .bar:hover{filter:brightness(1.12)}
.dash-chart-wrap .val{font-size:10.5px;font-weight:800;color:var(--primary)}
.dash-chart-wrap .day{font-size:9.5px;color:var(--text-light);white-space:nowrap}
.top-brand{display:flex;align-items:center;gap:11px;padding:9px 11px;border-radius:11px;transition:.14s}
.top-brand:hover{background:rgba(37,99,235,.05)}
.top-brand .tb-bar{height:7px;border-radius:6px;background:linear-gradient(90deg,#3b82f6,#8b5cf6);min-width:34px}
.qa-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:11px}
.qa-item{display:flex;flex-direction:column;align-items:center;gap:9px;padding:17px 12px;border:1.5px solid var(--border);
  border-radius:14px;text-decoration:none;text-align:center;transition:.17s;background:var(--card,#fff)}
.qa-item:hover{transform:translateY(-3px);box-shadow:0 10px 24px rgba(15,23,42,.09);border-color:#93c5fd}
.qa-item .qa-ic{width:46px;height:46px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:21px}
.qa-item b{font-size:12.5px;color:var(--text,#0f172a)}
.qa-item small{font-size:10.5px;color:var(--text-light,#64748b);line-height:1.7}
</style>

<!-- 🌟 هیرو خوش‌آمد (v2.39) + 🎬 انیمیشن داشبورد (v2.44 S14) -->
<div class="dash-hero">
    <div style="display:flex;align-items:center;gap:17px;flex-wrap:wrap">
        <div data-lottie="dashboard" data-lottie-size="92" style="flex:0 0 auto"></div>
        <?php
        $heroAvatar = '';
        try {
            $heroAvatar = (string)($db->fetchValue('SELECT avatar FROM users WHERE id = ? LIMIT 1', [(int)($_SESSION['user_id'] ?? 0)]) ?: '');
        } catch (Throwable $hE) { $heroAvatar = ''; }
        if ($heroAvatar !== '' && is_file(ROOT_PATH . '/' . $heroAvatar)): ?>
            <!-- 🖼 v2.40 — کلیک = نمایش بزرگ -->
            <img class="dh-av avatar-zoom" data-name="<?= e($meName) ?>" title="نمایش بزرگ آواتار" src="<?= e(asset_ver($heroAvatar)) ?>" alt="" style="cursor:zoom-in">
        <?php else: ?>
            <span class="dh-av"><?= e($meIni) ?></span>
        <?php endif; ?>
        <div style="min-width:0">
            <h1><?= e($greet) ?>، <?= e($meName) ?> 👋</h1>
            <div class="dh-sub"> امروز <?= e(jdate(date('Y/m/d'), true)) ?> — <?= e(jdate(date('l'), true)) ?><br>
                خلاصهٔ وضعیت نمایندگی شما در یک نگاه</div>
        </div>
    </div>
    <div class="dh-chips">
        <span class="dh-chip">🏷️ <?= en_to_fa_digits((string)$stats['brands']) ?> برند فعال</span>
        <span class="dh-chip">📨 <?= en_to_fa_digits((string)$stats['requests_new']) ?> درخواست جدید</span>
        <span class="dh-chip">📈 <?= en_to_fa_digits((string)$stats['visits_today']) ?> بازدید امروز</span>
        <span class="dh-chip">🎯 نرخ تبدیل هفته: <?= en_to_fa_digits((string)$convRate) ?>٪</span>
        <?php if ((int)$stats['comments_pending'] > 0): ?>
            <span class="dh-chip" style="background:rgba(251,191,36,.2);border-color:rgba(251,191,36,.4)">💬 <?= en_to_fa_digits((string)$stats['comments_pending']) ?> دیدگاه در انتظار</span>
        <?php endif; ?>
    </div>
</div>

<!-- 📊 کارت‌های آماری -->
<div class="stats-grid">
    <a class="stat-card" href="brands.php">
        <div class="icon" style="background:rgba(37,99,235,.12)"><span data-lottie="brands" data-lottie-size="34" data-lottie-mode="hover"></span></div>
        <div>
            <div class="number"><?= en_to_fa_digits((string)$stats['brands']) ?></div>
            <div class="label">برند فعال (<?= en_to_fa_digits((string)$stats['published']) ?> منتشرشده)</div>
        </div>
    </a>
    <a class="stat-card" href="requests.php">
        <div class="icon" style="background:rgba(220,38,38,.12)"><span data-lottie="requests" data-lottie-size="34" data-lottie-mode="hover"></span></div>
        <div>
            <div class="number"><?= en_to_fa_digits((string)$stats['requests_new']) ?></div>
            <div class="label">درخواست جدید (کل: <?= en_to_fa_digits((string)$stats['requests_total']) ?>)</div>
            <div class="trend <?= $stats['requests_week'] > 0 ? 'up' : 'flat' ?>"><?= $stats['requests_week'] > 0 ? '▲ ' . en_to_fa_digits((string)$stats['requests_week']) . ' در هفته' : 'این هفته درخواستی نیست' ?></div>
        </div>
    </a>
    <a class="stat-card" href="analytics.php">
        <div class="icon" style="background:rgba(8,145,178,.12)"><span data-lottie="analytics" data-lottie-size="34" data-lottie-mode="hover"></span></div>
        <div>
            <div class="number"><?= en_to_fa_digits((string)$stats['visits_today']) ?></div>
            <div class="label">بازدید امروز (۷ روز: <?= en_to_fa_digits((string)$stats['visits_week']) ?>)</div>
            <div class="trend <?= $visitsTrend > 0 ? 'up' : ($visitsTrend < 0 ? 'down' : 'flat') ?>"><?= e($visitsTrendTxt) ?></div>
        </div>
    </a>
    <a class="stat-card" href="articles.php">
        <div class="icon" style="background:rgba(5,150,105,.12)"><span data-lottie="articles" data-lottie-size="34" data-lottie-mode="hover"></span></div>
        <div>
            <div class="number"><?= en_to_fa_digits((string)$stats['articles']) ?></div>
            <div class="label">مقاله (<?= en_to_fa_digits((string)$stats['ai_articles']) ?> تولید AI)</div>
        </div>
    </a>
    <a class="stat-card" href="error-codes.php">
        <div class="icon" style="background:rgba(217,119,6,.12)"><span data-lottie="error-codes" data-lottie-size="34" data-lottie-mode="hover"></span></div>
        <div>
            <div class="number"><?= en_to_fa_digits((string)$stats['error_codes']) ?></div>
            <div class="label">کد خطای ثبت‌شده</div>
        </div>
    </a>
    <a class="stat-card" href="brands.php">
        <div class="icon" style="background:rgba(124,58,237,.12)"><span data-lottie="builder" data-lottie-size="34" data-lottie-mode="hover"></span></div>
        <div>
            <div class="number"><?= en_to_fa_digits((string)$stats['devices']) ?></div>
            <div class="label">دستگاه ثبت‌شده</div>
        </div>
    </a>
</div>

<div class="grid-2">
    <!-- 📈 نمودار بازدید ۱۴ روز اخیر -->
    <div class="card">
        <div class="card-header">
            <h3>📈 روند بازدید ۱۴ روز اخیر</h3>
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
                $maxVisits = max(1, max(array_column($visitsChart, 'unique_visits')));
                $days = ['Sat' => 'شنبه', 'Sun' => 'یکشنبه', 'Mon' => 'دوشنبه', 'Tue' => 'سه‌شنبه', 'Wed' => 'چهارشنبه', 'Thu' => 'پنجشنبه', 'Fri' => 'جمعه'];
                ?>
                <div class="dash-chart-wrap">
                    <?php foreach ($visitsChart as $day): ?>
                        <div class="bar-col" title="<?= e(jdate($day['visit_date'], true)) ?> — <?= en_to_fa_digits((string)$day['unique_visits']) ?> بازدیدکنندهٔ یکتا / <?= en_to_fa_digits((string)$day['views']) ?> بازدید">
                            <span class="val"><?= en_to_fa_digits((string)$day['unique_visits']) ?></span>
                            <div class="bar<?= $day['visit_date'] === date('Y-m-d') ? ' today' : '' ?>" style="height:<?= max(5, round($day['unique_visits'] / $maxVisits * 100)) ?>%"></div>
                            <span class="day"><?= $days[date('D', strtotime($day['visit_date']))] ?? e(jdate($day['visit_date'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;gap:16px;margin-top:9px;font-size:11px;color:var(--text-light);flex-wrap:wrap">
                    <span><span style="display:inline-block;width:11px;height:11px;border-radius:3px;background:linear-gradient(180deg,#34d399,#059669);vertical-align:middle;margin-inline-end:5px"></span>امروز</span>
                    <span><span style="display:inline-block;width:11px;height:11px;border-radius:3px;background:linear-gradient(180deg,#60a5fa,#1e40af);vertical-align:middle;margin-inline-end:5px"></span>روزهای گذشته — بازدیدکنندهٔ یکتا</span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 🏷️ پربازدیدترین برندها (v2.39 — قبلاً واکشی می‌شد ولی نمایش داده نمی‌شد!) -->
    <div class="card">
        <div class="card-header">
            <h3>🏷️ پربازدیدترین برندها</h3>
            <div class="tools"><a href="analytics.php" class="btn btn-outline btn-sm">جزئیات</a></div>
        </div>
        <div class="card-body">
            <?php if (empty($topBrands) || (int)$topBrands[0]['visits'] === 0): ?>
                <div class="empty-state"><div class="icon">🏷️</div><p>هنوز بازدیدی برای برندها ثبت نشده است.</p></div>
            <?php else: ?>
                <?php foreach (array_slice($topBrands, 0, 7) as $tb): ?>
                    <a class="top-brand" href="brands.php?brand=<?= (int)$tb['id'] ?>" style="text-decoration:none">
                        <?php if (!empty($tb['logo'])): ?>
                            <?php /* 🆕 v2.41 — contain + padding: لوگو کامل داخل کادر دیده می‌شود (قبلاً cover می‌بُرید) */ ?>
                            <img src="<?= asset_url((string)$tb['logo']) ?>" alt="" style="width:40px;height:40px;flex:none;border-radius:10px;object-fit:contain;background:#f8fafc;border:1px solid var(--border);padding:4px">
                        <?php endif; ?>
                        <div style="flex:1;min-width:0">
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
                                <b style="font-size:13px;color:var(--text,#0f172a);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($tb['name_fa']) ?></b>
                                <span style="font-size:11.5px;font-weight:800;color:var(--primary);flex:none"><?= en_to_fa_digits((string)$tb['visits']) ?> 👁</span>
                            </div>
                            <div class="tb-bar" style="width:<?= max(8, round($tb['visits'] / $maxBrandVisits * 100)) ?>%;margin-top:6px"></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
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
                        <td>
                            <?php /* 🆕 v2.39 — نام دستگاه فارسی (device_key خام نمایش داده نمی‌شود) */ ?>
                            <?= e($req['device_fa'] ?: ($req['device_other'] ?: NotificationService::deviceNameFa((int)$req['brand_id'], (string)$req['device_key']))) ?>
                        </td>
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
            ['⏱️ زمان اجرا', $sysInfo['exec_time'] === '—' ? '—' : ($sysInfo['exec_time'] . ' ثانیه'), true, 'max_execution_time'],
            ['📤 سقف آپلود', $sysInfo['upload'], true, 'upload_max_filesize'],
        ];
        if ($sysInfo['disk_free'] !== false && $sysInfo['disk_free'] !== null) {
            $sysItems[] = ['💽 فضای خالی هاست', round((float)$sysInfo['disk_free'] / 1073741824, 1) . ' GB', (float)$sysInfo['disk_free'] > 1073741824, 'فضای قابل‌نوشتهٔ باقی‌مانده'];
        }
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

<!-- 🤖 وضعیت موتور AI -->
<div class="card" style="margin-top:20px">
    <div class="card-header">
        <h3>🤖 وضعیت موتور هوش مصنوعی</h3>
        <div class="tools"><a href="ai-learning.php" class="btn btn-outline btn-sm">یادگیری</a></div>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px">
            <?php
            $aiCards = [
                ['📚', 'برندهای پایگاه دانش', en_to_fa_digits((string)($aiStats['knowledge_brands'] ?? 0)), 'rgba(37,99,235,.12)'],
                ['🧩', 'قالب‌های محتوایی', en_to_fa_digits((string)($aiStats['knowledge_templates'] ?? 0)) . ' دسته', 'rgba(124,58,237,.12)'],
                ['🔤', 'واژه‌های مترادف', en_to_fa_digits((string)($aiStats['synonym_words'] ?? 0)), 'rgba(8,145,178,.12)'],
                ['📰', 'مقالات تولیدشده', en_to_fa_digits((string)($aiStats['articles_generated'] ?? 0)), 'rgba(5,150,105,.12)'],
                ['❓', 'سوالات متداول', en_to_fa_digits((string)($aiStats['faqs_generated'] ?? 0)), 'rgba(217,119,6,.12)'],
                ['✅', 'سلامت یکتایی', (string)($aiStats['uniqueness_health'] ?? '—'), 'rgba(220,38,38,.12)'],
            ];
            foreach ($aiCards as [$ic, $lbl, $val, $bg]): ?>
                <div style="display:flex;align-items:center;gap:12px;padding:13px 15px;border:1.5px solid var(--border);border-radius:12px">
                    <span style="width:40px;height:40px;border-radius:11px;background:<?= $bg ?>;display:flex;align-items:center;justify-content:center;font-size:18px;flex:none"><?= $ic ?></span>
                    <div style="min-width:0">
                        <div style="font-size:16.5px;font-weight:900"><?= e($val) ?></div>
                        <div style="font-size:11px;color:var(--text-light)"><?= e($lbl) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:10px;font-size:12px;color:var(--text-light)">
            🎨 اسکیل UI/UX Pro: <?= en_to_fa_digits((string)($uiuxInfo['page_blueprints'] ?? 0)) ?> بلوپرینت صفحه • <?= en_to_fa_digits((string)($uiuxInfo['ux_laws'] ?? 0)) ?> قانون UX
        </div>
    </div>
</div>

<!-- ⚡ دسترسی سریع (v2.39 — شبکه کارت‌های آیکونی) -->
<div class="card" style="margin-top:20px">
    <div class="card-header"><h3>⚡ دسترسی سریع</h3></div>
    <div class="card-body">
        <div class="qa-grid">
            <a class="qa-item" href="brand-new.php">
                <span class="qa-ic" style="background:rgba(37,99,235,.12)">➕</span>
                <b>ساخت سایت برند جدید</b>
                <small>ویزارد ۵ مرحله‌ای هوشمند</small>
            </a>
            <a class="qa-item" href="articles.php?generate=1">
                <span class="qa-ic" style="background:rgba(5,150,105,.12)">🤖</span>
                <b>تولید مقاله با AI</b>
                <small>غیرهمزمان با نوار پیشرفت</small>
            </a>
            <a class="qa-item" href="error-codes.php">
                <span class="qa-ic" style="background:rgba(217,119,6,.12)">🚨</span>
                <b>خطایاب کدهای ایراد</b>
                <small>جستجوی چندراندی آنلاین</small>
            </a>
            <a class="qa-item" href="deploy.php">
                <span class="qa-ic" style="background:rgba(124,58,237,.12)">🚀</span>
                <b>استقرار و بروزرسانی</b>
                <small>cPanel / FTP خودکار</small>
            </a>
            <a class="qa-item" href="settings.php">
                <span class="qa-ic" style="background:rgba(8,145,178,.12)">⚙️</span>
                <b>تنظیمات نمایندگی</b>
                <small>لوگو، رنگ‌ها و کانال‌ها</small>
            </a>
            <a class="qa-item" href="media.php">
                <span class="qa-ic" style="background:rgba(2,132,199,.12)">🖼️</span>
                <b>رسانه و لوگوها</b>
                <small>کتابخانه تصاویر</small>
            </a>
            <a class="qa-item" href="export.php">
                <span class="qa-ic" style="background:rgba(71,85,105,.12)">📦</span>
                <b>خروجی سایت‌ها</b>
                <small>بسته ZIP هر برند</small>
            </a>
            <a class="qa-item" href="docs.php">
                <span class="qa-ic" style="background:rgba(190,24,93,.12)">📚</span>
                <b>مستندات راهنما</b>
                <small>راهنمای کامل سیستم</small>
            </a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
