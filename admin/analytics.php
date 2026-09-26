<?php
/**
 * 📊 آمار و گزارش‌گیری — داشبورد تحلیلی پیشرفته
 * ==============================================
 * نمودارهای Canvas بدون وابستگی + نقشه حرارتی ایران + گزارش تکی برند
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();

$pageTitle = 'آمار و گزارش‌گیری';
$activeMenu = 'analytics';
require __DIR__ . '/includes/header.php';

$brandFilter = (int)get_param('brand');
$dateFrom = get_param('from');
$dateTo = get_param('to');
$period = get_param('period', '30');

/* 🗓️ بازه زمانی */
if ($dateFrom === '' && $period !== 'all') {
    $dateFrom = date('Y-m-d', strtotime("-{$period} days"));
}
$where = '1=1';
$params = [];
if ($brandFilter > 0) {
    $where .= ' AND v.brand_id = ?';
    $params[] = $brandFilter;
}
if ($dateFrom !== '') {
    $where .= ' AND v.visit_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where .= ' AND v.visit_date <= ?';
    $params[] = $dateTo;
}

/* 📈 سری زمانی بازدید */
/* 🛡️ v2.27 — اگر جدول‌های آمار روی نصب قدیمی وجود نداشته باشند، صفحه
   با ۵۰۰ نمی‌افتد؛ جدول‌های خالی + راهنمای راه‌حل نشان داده می‌شود. */
$safeQuery = static function (string $sql, array $params) use ($db): array {
    try {
        return $db->fetchAll($sql, $params);
    } catch (Throwable $sqlE) {
        return [];
    }
};
$timeline = $safeQuery(
    "SELECT v.visit_date,
            COUNT(DISTINCT v.session_hash) AS unique_visits,
            COUNT(*) AS views
     FROM visits v WHERE {$where}
     GROUP BY v.visit_date ORDER BY v.visit_date",
    $params
);

/* 📱 تفکیک دستگاه / مرورگر / سیستم‌عامل */
$byDevice = $safeQuery("SELECT v.device_type, COUNT(DISTINCT v.session_hash) AS c FROM visits v WHERE {$where} GROUP BY v.device_type ORDER BY c DESC", $params);
$byBrowser = $safeQuery("SELECT v.browser, COUNT(DISTINCT v.session_hash) AS c FROM visits v WHERE {$where} AND v.browser IS NOT NULL GROUP BY v.browser ORDER BY c DESC LIMIT 8", $params);
$byOs = $safeQuery("SELECT v.os, COUNT(DISTINCT v.session_hash) AS c FROM visits v WHERE {$where} AND v.os IS NOT NULL GROUP BY v.os ORDER BY c DESC LIMIT 6", $params);

/* 🗺️ v2.29 — پراکندگی شهرها بر پایه «بازدیدکننده یکتا» به تفکیک شهر
   (قبلاً فقط تعداد پیشوندهای یکتا شمرده می‌شد — عدد گمراه‌کننده بود!)
   ردیف‌های بدون شهر (قدیمی/IPv6) از ip_prefix با GeoIP بازیابی می‌شوند. */
$cityRows = $safeQuery(
    "SELECT COALESCE(NULLIF(v.city, ''), '') AS city_key, v.ip_prefix, COUNT(DISTINCT v.session_hash) AS c
     FROM visits v WHERE {$where} AND (v.city IS NOT NULL AND v.city != '' OR (v.ip_prefix IS NOT NULL AND v.ip_prefix != ''))
     GROUP BY city_key, v.ip_prefix",
    $params
);
$citiesDist = [];
foreach ((array)$cityRows as $cr) {
    $city = trim((string)$cr['city_key']);
    if ($city === '') {
        $city = GeoIP::city((string)($cr['ip_prefix'] ?? ''));
        if ($city === '') { continue; }
    }
    $citiesDist[$city] = ($citiesDist[$city] ?? 0) + (int)$cr['c'];
}
arsort($citiesDist);

/* 📄 صفحات پربازدید */
$topPages = $safeQuery(
    "SELECT vd.page_url, COUNT(*) AS views, AVG(vd.duration) AS avg_duration
     FROM visit_details vd JOIN visits v ON v.id = vd.visit_id
     WHERE {$where} GROUP BY vd.page_url ORDER BY views DESC LIMIT 10",
    $params
);

/* 🏷️ سهم برندها */
$brandShare = $safeQuery(
    "SELECT b.name_fa, COUNT(DISTINCT v.session_hash) AS visits
     FROM brands b LEFT JOIN visits v ON v.brand_id = b.id AND 1=1
     WHERE b.is_active = 1 " . ($brandFilter > 0 ? ' AND b.id = ' . $brandFilter : '') . "
     GROUP BY b.id HAVING visits > 0 ORDER BY visits DESC",
    []
);

/* ═══════════════════════════════════════════════════════════════
 * 🆕 v2.29 — گزارش‌های جدید (درخواست کاربر: «انواع بیشتری از گزارش
 * همراه با نمودارهای زیبا») — همه با safeQuery مقاوم در برابر DB قدیمی
 * ═══════════════════════════════════════════════════════════════ */

/* 🕐 ساعات پربازدید (۲۴ ساعت) */
$byHour = $safeQuery("SELECT HOUR(v.visited_at) AS h, COUNT(DISTINCT v.session_hash) AS c FROM visits v WHERE {$where} GROUP BY h ORDER BY h", $params);
$hourMap = array_fill(0, 24, 0);
foreach ($byHour as $hr) { $hourMap[(int)$hr['h']] = (int)$hr['c']; }

/* 📅 روزهای هفته */
$byWeekday = $safeQuery("SELECT WEEKDAY(v.visit_date) AS wd, COUNT(DISTINCT v.session_hash) AS c FROM visits v WHERE {$where} GROUP BY wd ORDER BY wd", $params);
$weekdayFa = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
$weekdayMap = array_fill(0, 7, 0);
foreach ($byWeekday as $wd) { $weekdayMap[(int)$wd['wd']] = (int)$wd['c']; }

/* 🔗 منابع ورود (مستقیم / جستجو / اجتماعی / ارجاع) */
$refRows = $safeQuery("SELECT v.referrer, COUNT(DISTINCT v.session_hash) AS c FROM visits v WHERE {$where} AND v.referrer IS NOT NULL GROUP BY v.referrer ORDER BY c DESC LIMIT 400", $params);
$refGroups = ['مستقیم' => 0, 'موتور جستجو' => 0, 'شبکه اجتماعی' => 0, 'سایت‌های ارجاع‌دهنده' => 0];
$refDomains = [];
foreach ((array)$refRows as $rr) {
    $host = strtolower((string)parse_url((string)$rr['referrer'], PHP_URL_HOST) ?: '');
    if ($host === '') { $refGroups['مستقیم'] += (int)$rr['c']; continue; }
    $refDomains[$host] = ($refDomains[$host] ?? 0) + (int)$rr['c'];
    if (preg_match('#google|bing|yahoo|duckduckgo|yandex#', $host)) { $refGroups['موتور جستجو'] += (int)$rr['c']; }
    elseif (preg_match('#instagram|telegram|whatsapp|facebook|twitter|linkedin|aparat|youtube|bale|eitaa|rubika#', $host)) { $refGroups['شبکه اجتماعی'] += (int)$rr['c']; }
    else { $refGroups['سایت‌های ارجاع‌دهنده'] += (int)$rr['c']; }
}
arsort($refDomains);
$refDomains = array_slice($refDomains, 0, 8, true);

/* 🔎 کلمات کلیدی جستجو */
$topKeywords = $safeQuery("SELECT v.search_keyword AS kw, COUNT(DISTINCT v.session_hash) AS c FROM visits v WHERE {$where} AND v.search_keyword IS NOT NULL AND v.search_keyword != '' GROUP BY kw ORDER BY c DESC LIMIT 10", $params);

/* 🔄 جدید در برابر بازگشتی (نشست‌های چندروزه = بازگشتی) */
$retRow = $safeQuery(
    "SELECT COUNT(*) AS returning_sessions FROM (
        SELECT v.session_hash FROM visits v WHERE {$where} GROUP BY v.session_hash HAVING COUNT(DISTINCT v.visit_date) > 1
     ) t",
    $params
);
$returningSessions = $retRow ? (int)$retRow[0]['returning_sessions'] : 0;
$newVisitors = max(0, $totalUnique - $returningSessions);

/* 📊 مقایسه با دوره قبل (همان طول بازه، قبل از آن) */
$prevWhere = '1=1';
$prevParams = [];
if ($brandFilter > 0) { $prevWhere .= ' AND v.brand_id = ?'; $prevParams[] = $brandFilter; }
if ($dateFrom !== '') {
    $prevTo = date('Y-m-d', strtotime($dateFrom . ' -1 day'));
    $prevDays = max(1, (strtotime($dateTo ?: date('Y-m-d')) - strtotime($dateFrom)) / 86400 + 1);
    $prevFrom = date('Y-m-d', strtotime($prevTo) - (($prevDays - 1) * 86400));
    $prevWhere .= " AND v.visit_date >= ? AND v.visit_date <= ?";
    $prevParams[] = $prevFrom;
    $prevParams[] = $prevTo;
}
$prevRow = $safeQuery("SELECT COUNT(DISTINCT v.session_hash) AS u, COUNT(*) AS views FROM visits v WHERE {$prevWhere}", $prevParams);
$prevUnique = $prevRow ? (int)$prevRow[0]['u'] : 0;
$prevViews = $prevRow ? (int)$prevRow[0]['views'] : 0;

/* 📱 رزولوشن‌های پرتکرار */
$byResolution = $safeQuery("SELECT v.resolution, COUNT(DISTINCT v.session_hash) AS c FROM visits v WHERE {$where} AND v.resolution IS NOT NULL AND v.resolution != '' GROUP BY v.resolution ORDER BY c DESC LIMIT 6", $params);

/* 🚪 صفحات خروج */
$exitPages = $safeQuery(
    "SELECT vd.page_url, COUNT(*) AS exits FROM visit_details vd JOIN visits v ON v.id = vd.visit_id
     WHERE {$where} AND vd.is_exit = 1 GROUP BY vd.page_url ORDER BY exits DESC LIMIT 8",
    $params
);

/* 🗺 مختصات شهرها برای نقشه SVG (طرح‌واره ایران) */
$cityXY = [
    'تهران' => [364.7, 143.0], 'کرج' => [346.3, 139.4], 'مشهد' => [700.1, 127.2], 'اصفهان' => [375.0, 223.4],
    'شیراز' => [411.0, 303.8], 'تبریز' => [154.4, 80.3], 'اهواز' => [252.0, 258.5], 'قم' => [342.6, 171.0],
    'کرمان' => [596.8, 285.6], 'رشت' => [289.3, 101.4], 'بوشهر' => [340.9, 320.4], 'زاهدان' => [737.0, 306.4],
    'ارومیه' => [102.7, 94.3], 'ساری' => [432.0, 120.4], 'سنندج' => [183.1, 153.1], 'همدان' => [245.4, 166.8],
    'خرم‌آباد' => [239.3, 201.3], 'اردبیل' => [236.4, 75.8], 'بیرجند' => [684.5, 217.6], 'قزوین' => [306.5, 128.0],
    'ایلام' => [159.7, 197.3], 'یزد' => [485.7, 243.2],
];

/* 📈 شاخص‌های کلی */
$totalUnique = array_sum(array_column($timeline, 'unique_visits'));
$totalViews = array_sum(array_column($timeline, 'views'));
$durations = $safeQuery("SELECT AVG(vd.duration) AS avg_dur FROM visit_details vd JOIN visits v ON v.id = vd.visit_id WHERE {$where}", $params);
$avgDuration = $durations ? round((float)$durations[0]['avg_dur']) : 0;
$bounceRow = $safeQuery(
    "SELECT COUNT(DISTINCT v.session_hash) AS sessions, COUNT(DISTINCT CASE WHEN exits.exit_count = 1 AND views.vc = 1 THEN v.session_hash END) AS bounced
     FROM visits v
     LEFT JOIN (SELECT visit_id, COUNT(*) AS exit_count FROM visit_details WHERE is_exit = 1 GROUP BY visit_id) exits ON exits.visit_id = v.id
     LEFT JOIN (SELECT visit_id, COUNT(*) AS vc FROM visit_details GROUP BY visit_id) views ON views.visit_id = v.id
     WHERE {$where}",
    $params
);
$bounceRate = $bounceRow && (int)$bounceRow[0]['sessions'] > 0 ? round((int)$bounceRow[0]['bounced'] / (int)$bounceRow[0]['sessions'] * 100) : 0;

$brands = $db->fetchAll('SELECT id, name_fa FROM brands ORDER BY name_fa');
$deviceFa = ['mobile' => '📱 موبایل', 'desktop' => '🖥️ دسکتاپ', 'tablet' => '📲 تبلت', 'bot' => '🤖 ربات'];

/* 🩺 v2.26 — نوار وضعیت ردیاب: اگر حتی یک بازدید هم ثبت نشده باشد،
   کاربر به‌جای «جدول‌های خالی بی‌توضیح» علت و راه‌حل را می‌بیند.
   شمارندها مستقل از فیلترها بازه کل را می‌سنجند تا گمراه‌کننده نباشد. */
$trackerTotal = 0;
$trackerToday = 0;
$trackerLast = false;
try {
    $trackerTotal = (int)$db->fetchValue('SELECT COUNT(*) FROM visits');
    $trackerToday = (int)$db->fetchValue('SELECT COUNT(*) FROM visits WHERE visit_date = CURDATE()');
    $trackerLast = $db->fetchValue('SELECT MAX(visited_at) FROM visits');
} catch (Throwable $trackerSchemaE) {
    /* جدول آمار وجود ندارد — مهاجرت v227 در اولین لود ساخته‌اش می‌کند */
}
$trackerHealthy = $trackerTotal > 0;
$trackerBoxStyle = $trackerHealthy
    ? 'background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46'
    : 'background:#fffbeb;border:1px solid #fde68a;color:#92400e';
?>
<form method="get" class="card" style="padding:13px 18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
    <select name="brand" class="form-control" style="max-width:180px">
        <option value="">🏷️ همه برندها</option>
        <?php foreach ($brands as $brand): ?>
            <option value="<?= (int)$brand['id'] ?>" <?= $brandFilter === (int)$brand['id'] ? 'selected' : '' ?>><?= e($brand['name_fa']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="period" class="form-control" style="max-width:140px" onchange="if(this.value!=='custom')this.form.submit()">
        <?php foreach (['7' => '۷ روز', '30' => '۳۰ روز', '90' => '۳ ماه', '365' => '۱ سال', 'all' => 'همه', 'custom' => 'بازه دلخواه'] as $p => $label): ?>
            <option value="<?= $p ?>" <?= $period === $p ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($period === 'custom'): ?>
        <input type="date" name="from" class="form-control" style="max-width:160px" value="<?= e($dateFrom) ?>">
        <input type="date" name="to" class="form-control" style="max-width:160px" value="<?= e($dateTo) ?>">
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">📊 اعمال</button>
    <a href="analytics-report.php?brand=<?= $brandFilter ?>&amp;period=<?= e($period) ?>&amp;from=<?= e($dateFrom) ?>&amp;to=<?= e($dateTo) ?>" target="_blank" class="btn btn-outline" title="نمای چاپی گزارش / ذخیره PDF">🖨️ گزارش PDF</a>
    <a href="?<?= $brandFilter ? 'brand=' . $brandFilter . '&' : '' ?>export=csv" class="btn btn-outline" style="margin-inline-start:auto">📥 خروجی CSV</a>
</form>

<!-- 🩺 v2.26 — وضعیت زنده ردیاب بازدید -->
<div class="card" style="padding:11px 16px;margin-bottom:18px;<?= $trackerBoxStyle ?>">
    <?php if ($trackerHealthy): ?>
        <b>🟢 ردیاب بازدید فعال است</b> —
        <?= en_to_fa_digits((string)$trackerToday) ?> بازدید امروز،
        <?= en_to_fa_digits((string)$trackerTotal) ?> بازدید کل ثبت‌شده
        <?php if ($trackerLast): ?>
            — آخرین بازدید: <?= e(en_to_fa_digits((string)$trackerLast)) ?>
        <?php endif; ?>
    <?php else: ?>
        <b>🟡 هنوز هیچ بازدیدی ثبت نشده است</b> — ردیاب داخلی (js/tracker.js) از نسخه ۲.۲۵ به بعد
        به سایت‌های برند اضافه شده است. اگر سایت‌های برند شما با نسخه قدیمی مستقر شده‌اند،
        برای هر برند از «ویرایش برند ← استقرار ← <b>بروزرسانی استقرار</b>» استفاده کنید تا
        فایل ردیاب و کانفیگ جدید منتقل شود؛ سپس یک‌بار صفحه اصلی سایت برند را باز کنید و این صفحه را رفرش نمایید.
    <?php endif; ?>
</div>

<div class="stats-grid">
    <div class="stat-card"><div class="icon bg-cyan">👥</div><div><div class="number"><?= en_to_fa_digits((string)$totalUnique) ?></div><div class="label">بازدیدکننده یکتا</div></div></div>
    <div class="stat-card"><div class="icon bg-blue">👁️</div><div><div class="number"><?= en_to_fa_digits((string)$totalViews) ?></div><div class="label">بازدید کل صفحات</div></div></div>
    <div class="stat-card"><div class="icon bg-green">⏱️</div><div><div class="number"><?= en_to_fa_digits((string)floor($avgDuration / 60)) ?>:<?= str_pad(en_to_fa_digits((string)($avgDuration % 60)), 2, '۰', STR_PAD_LEFT) ?></div><div class="label">میانگین مدت حضور</div></div></div>
    <div class="stat-card"><div class="icon bg-orange">📉</div><div><div class="number"><?= en_to_fa_digits((string)$bounceRate) ?>٪</div><div class="label">نرخ پرش</div></div></div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>📈 روند بازدید</h3></div>
        <div class="card-body"><canvas id="visits-chart" height="240"></canvas></div>
    </div>
    <div class="card">
        <div class="card-header"><h3>📱 تفکیک دستگاه</h3></div>
        <div class="card-body">
            <?php if (empty($byDevice)): ?>
                <div class="empty-state"><div class="icon">📊</div><p>داده‌ای برای نمایش نیست.</p></div>
            <?php else: ?>
                <?php $totalDev = max(1, array_sum(array_column($byDevice, 'c'))); ?>
                <?php foreach ($byDevice as $dev): ?>
                    <div style="margin-bottom:14px">
                        <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:5px">
                            <span><?= $deviceFa[$dev['device_type']] ?? $dev['device_type'] ?></span>
                            <b><?= en_to_fa_digits((string)$dev['c']) ?> (<?= en_to_fa_digits((string)round($dev['c'] / $totalDev * 100)) ?>٪)</b>
                        </div>
                        <div class="progress"><div class="bar" style="width:<?= round($dev['c'] / $totalDev * 100) ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:18px">
    <div class="card-header"><h3>🗺️ پراکندگی جغرافیایی بازدیدکنندگان — نقشه ایران</h3></div>
    <div class="card-body">
        <?php if (empty($citiesDist)): ?>
            <div class="empty-state"><div class="icon">🗺️</div><p>داده جغرافیایی ثبت نشده است.<br><small>پس از بازدید اولین کاربران، نقشه شهرها اینجا نمایش داده می‌شود.</small></p></div>
        <?php else: ?>
            <?php
            $maxCity = max($citiesDist) ?: 1;
            $totalCity = array_sum($citiesDist) ?: 1;
            /* رنگ و اندازه حباب هر شهر بر اساس سهم بازدید */
            $bubbles = '';
            foreach (array_slice($citiesDist, 0, 22, true) as $city => $count):
                $xy = $cityXY[$city] ?? null;
                if (!$xy) { continue; }
                $share = $count / $maxCity;
                $r = 7 + 17 * sqrt($share);
                $op = 0.35 + 0.6 * $share;
                $bubbles .= '<g class="ir-map-city" data-city="' . e($city) . '" data-count="' . (int)$count . '">'
                    . '<circle cx="' . $xy[0] . '" cy="' . $xy[1] . '" r="' . round($r + 5, 1) . '" fill="rgba(30,64,175,' . round($op * 0.18, 2) . ')"></circle>'
                    . '<circle cx="' . $xy[0] . '" cy="' . $xy[1] . '" r="' . round($r, 1) . '" fill="rgba(37,99,235,' . round($op, 2) . ')" stroke="#fff" stroke-width="1.6"></circle>'
                    . '<text x="' . $xy[0] . '" y="' . ($xy[1] - $r - 6) . '" text-anchor="middle" font-size="11.5" font-weight="800" fill="#1e40af">' . e($city) . '</text>'
                    . '<text x="' . $xy[0] . '" y="' . ($xy[1] + 4) . '" text-anchor="middle" font-size="10" font-weight="700" fill="#fff">' . e(en_to_fa_digits((string)$count)) . '</text>'
                    . '</g>';
            endforeach;
            ?>
            <div style="display:grid;grid-template-columns:1fr 280px;gap:18px;align-items:start">
                <div class="ir-map-wrap" style="direction:ltr;background:linear-gradient(160deg,#f0f7ff,#eaf3fb);border-radius:14px;padding:8px;border:1px solid #dbeafe">
                    <svg viewBox="0 0 900 460" style="width:100%;height:auto;display:block" role="img" aria-label="نقشه پراکندگی بازدید ایران">
                        <defs>
                            <linearGradient id="irFill" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0" stop-color="#cfe3ff"/><stop offset="1" stop-color="#a8ccf5"/>
                            </linearGradient>
                        </defs>
                        <!-- مرز ایران (نمای طرح‌واره) -->
                        <path d="M76.9,37.6 L163.0,58.7 L224.5,58.7 L261.4,71.9 L310.6,85.1 L359.8,98.2 L409.0,100.9 L466.4,98.2 L503.3,90.3 L552.5,77.2 L605.8,77.2 L655.0,90.3 L696.0,95.6 L728.8,111.4 L753.4,127.2 L765.7,145.7 L761.6,179.9 L778.0,201.0 L765.7,235.3 L753.4,256.4 L786.2,272.2 L790.3,290.6 L769.8,309.1 L737.0,319.6 L741.1,351.2 L790.3,367.0 L831.3,364.4 L851.8,369.7 L831.3,393.4 L782.1,419.7 L696.0,414.5 L634.5,409.2 L605.8,385.5 L560.7,369.7 L511.5,380.2 L470.5,385.5 L413.1,359.1 L359.8,345.9 L314.7,319.6 L286.0,306.4 L265.5,288.0 L249.1,295.9 L224.5,280.1 L245.0,261.6 L224.5,245.8 L212.2,266.9 L199.9,280.1 L146.6,295.9 L122.0,266.9 L117.9,240.5 L146.6,216.8 L117.9,195.7 L130.2,172.0 L146.6,148.3 L117.9,135.1 L93.3,106.1 L72.8,85.1 L93.3,66.6 L68.7,45.5 Z"
                              fill="url(#irFill)" stroke="#5b93d6" stroke-width="2.2" stroke-linejoin="round"/>
                        <!-- دریای خزر -->
                        <path d="M306.5,99.6 L347.5,111.4 L388.5,110.1 L437.7,108.8 L466.4,106.1 L470.5,98.2 L429.5,93.0 L380.3,95.6 L331.1,93.0 L302.4,98.2 Z" fill="#9cc3e8" opacity=".85"/>
                        <!-- خلیج فارس -->
                        <path d="M265.5,288.0 L306.5,309.1 L347.5,330.1 L368.0,351.2 L409.0,361.8 L450.0,374.9 L503.3,385.5 L552.5,374.9 L564.8,369.7 L519.7,367.0 L478.7,359.1 L437.7,348.6 L404.9,335.4 L372.1,319.6 L339.3,303.8 L310.6,288.0 L281.9,282.7 Z" fill="#9cc3e8" opacity=".85"/>
                        <?= $bubbles ?>
                    </svg>
                    <div style="text-align:center;font-size:10px;color:#64748b;padding:4px 0 2px">نمای طرح‌واره — اندازه هر حباب = سهم بازدید شهر</div>
                </div>
                <div>
                    <div style="font-size:12px;font-weight:800;margin-bottom:9px;color:#334155">🏆 رتبه‌بندی شهرها</div>
                    <?php $ci = 0; foreach (array_slice($citiesDist, 0, 12, true) as $city => $count): $ci++; ?>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:7px">
                            <span style="flex:none;width:21px;height:21px;border-radius:7px;background:<?= $ci === 1 ? '#1e40af' : ($ci === 2 ? '#3b82f6' : ($ci === 3 ? '#93c5fd' : '#e2e8f0')) ?>;color:<?= $ci <= 3 ? '#fff' : '#475569' ?>;font-size:10.5px;font-weight:800;display:flex;align-items:center;justify-content:center"><?= e(en_to_fa_digits((string)$ci)) ?></span>
                            <span style="flex:1;font-size:12.5px">📍 <?= e($city) ?></span>
                            <b style="font-size:12.5px"><?= e(en_to_fa_digits((string)$count)) ?></b>
                            <span style="font-size:10px;color:#94a3b8;flex:none;width:38px;text-align:left"><?= e(en_to_fa_digits((string)round($count / $totalCity * 100))) ?>٪</span>
                        </div>
                    <?php endforeach; ?>
                    <div class="hint" style="margin-top:10px">🛡️ تشخیص جغرافیا با GeoIP محلی — بدون API خارجی.</div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 🆕 v2.29 — گزارش‌های جدید -->
<div class="grid-2">
    <!-- 🕐 ساعات پربازدید -->
    <div class="card">
        <div class="card-header"><h3>🕐 بازدید بر اساس ساعت شبانه‌روز</h3></div>
        <div class="card-body"><canvas id="hours-chart" height="200"></canvas></div>
    </div>
    <!-- 📅 روزهای هفته -->
    <div class="card">
        <div class="card-header"><h3>📅 بازدید بر اساس روز هفته</h3></div>
        <div class="card-body"><canvas id="weekday-chart" height="200"></canvas></div>
    </div>
</div>

<div class="grid-2">
    <!-- 🔗 منابع ورود -->
    <div class="card">
        <div class="card-header"><h3>🔗 منابع ورود ترافیک</h3></div>
        <div class="card-body">
            <canvas id="referrers-chart" height="230"></canvas>
            <?php if (!empty($refDomains)): ?>
                <div style="margin-top:13px;font-size:11px;font-weight:800;color:var(--text-light);margin-bottom:6px">دامنه‌های ارجاع‌دهنده برتر:</div>
                <?php foreach ($refDomains as $dom => $cnt): ?>
                    <div style="display:flex;justify-content:space-between;font-size:11.5px;margin-bottom:4px"><span dir="ltr"><?= e($dom) ?></span><b><?= e(en_to_fa_digits((string)$cnt)) ?></b></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <!-- 🔄 جدید/بازگشتی + مقایسه دوره -->
    <div class="card">
        <div class="card-header"><h3>🔄 بازدیدکنندگان جدید و بازگشتی</h3></div>
        <div class="card-body">
            <canvas id="newret-chart" height="175"></canvas>
            <div style="display:flex;gap:12px;margin-top:12px;flex-wrap:wrap">
                <div style="flex:1;min-width:130px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:11px;text-align:center">
                    <div style="font-size:22px;font-weight:900;color:#1e40af"><?= e(en_to_fa_digits((string)$newVisitors)) ?></div>
                    <div style="font-size:11px;color:#64748b">🆕 بازدیدکننده جدید</div>
                </div>
                <div style="flex:1;min-width:130px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:11px;text-align:center">
                    <div style="font-size:22px;font-weight:900;color:#15803d"><?= e(en_to_fa_digits((string)$returningSessions)) ?></div>
                    <div style="font-size:11px;color:#64748b">🔁 بازگشتی (چند روز)</div>
                </div>
                <div style="flex:1;min-width:130px;background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:11px;text-align:center">
                    <div style="font-size:22px;font-weight:900;color:#b45309"><?= $prevUnique > 0 ? e(en_to_fa_digits((string)round(($totalUnique - $prevUnique) / $prevUnique * 100))) . '٪' : '—' ?></div>
                    <div style="font-size:11px;color:#64748b">📈 تغییر نسبت به دوره قبل</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="grid-2">
    <!-- 🥧 سهم برندها -->
    <div class="card">
        <div class="card-header"><h3>🥧 سهم برندها از بازدید</h3></div>
        <div class="card-body"><canvas id="brands-chart" height="260"></canvas></div>
    </div>
    <!-- 🔎 کلمات کلیدی + رزولوشن + خروج -->
    <div class="card">
        <div class="card-header"><h3>🔎 کلمات کلیدی ورودی از جستجو</h3></div>
        <div class="card-body">
            <?php if (empty($topKeywords)): ?>
                <div class="empty-state" style="padding:18px"><div class="icon">🔎</div><p>هنوز ورودی از موتور جستجو ثبت نشده است.</p></div>
            <?php else: ?>
                <?php foreach ($topKeywords as $kw): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:7px 12px;margin-bottom:6px">
                        <span style="font-size:12px"><?= e(mb_substr((string)$kw['kw'], 0, 60)) ?></span>
                        <b style="font-size:12px;color:var(--primary)"><?= e(en_to_fa_digits((string)$kw['c'])) ?></b>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($byResolution)): ?>
                <div style="margin-top:13px;font-size:11px;font-weight:800;color:var(--text-light)">🖥 رزولوشن‌های پرتکرار:</div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">
                    <?php foreach ($byResolution as $rz): ?>
                        <span style="background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:4px 10px;font-size:10.5px" dir="ltr"><?= e($rz['resolution']) ?> <b><?= e(en_to_fa_digits((string)$rz['c'])) ?></b></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>📄 صفحات پربازدید</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>صفحه</th><th>بازدید</th><th>میانگین حضور</th></tr></thead>
                <tbody>
                <?php foreach ($topPages as $page): ?>
                    <tr>
                        <td style="direction:ltr;text-align:left;font-size:11.5px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($page['page_url']) ?></td>
                        <td><b><?= en_to_fa_digits((string)$page['views']) ?></b></td>
                        <td style="font-size:12px"><?= $page['avg_duration'] ? en_to_fa_digits((string)round($page['avg_duration'])) . ' ثانیه' : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>🌐 مرورگرها و سیستم‌عامل</h3></div>
        <div class="card-body" style="display:flex;gap:24px;flex-wrap:wrap">
            <div style="flex:1;min-width:180px">
                <h4 style="font-size:12.5px;margin-bottom:10px;color:var(--text-light)">مرورگر</h4>
                <?php foreach ($byBrowser as $browser): ?>
                    <div style="font-size:12px;margin-bottom:6px;display:flex;justify-content:space-between"><span><?= e($browser['browser']) ?></span><b><?= en_to_fa_digits((string)$browser['c']) ?></b></div>
                <?php endforeach; ?>
            </div>
            <div style="flex:1;min-width:180px">
                <h4 style="font-size:12.5px;margin-bottom:10px;color:var(--text-light)">سیستم‌عامل</h4>
                <?php foreach ($byOs as $os): ?>
                    <div style="font-size:12px;margin-bottom:6px;display:flex;justify-content:space-between"><span><?= e($os['os']) ?></span><b><?= en_to_fa_digits((string)$os['c']) ?></b></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- 🧩 نمودار Canvas بدون وابستگی خارجی -->
<script>
/* 📈 نمودار خطی روند بازدید */
(function () {
    const canvas = document.getElementById('visits-chart');
    if (!canvas) return;
    const data = <?= json_encode(array_map(fn($r) => ['date' => $r['visit_date'], 'u' => (int)$r['unique_visits'], 'v' => (int)$r['views']], $timeline)) ?>;
    if (!data.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">📉</div><p>هنوز بازدیدی ثبت نشده است.</p></div>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    canvas.width = w * dpr; canvas.height = 240 * dpr;
    ctx.scale(dpr, dpr);
    const pad = { t: 15, r: 15, b: 28, l: 40 };
    const cw = w - pad.l - pad.r, ch = 240 - pad.t - pad.b;
    const maxV = Math.max(...data.map(d => d.v), 4);
    // خطوط راهنما
    ctx.strokeStyle = '#e2e8f0'; ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
        const y = pad.t + ch - (ch * i / 4);
        ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(pad.l + cw, y); ctx.stroke();
        ctx.fillStyle = '#64748b'; ctx.font = '10px Tahoma'; ctx.textAlign = 'right';
        ctx.fillText(Math.round(maxV * i / 4), pad.l - 6, y + 3);
    }
    const xAt = i => pad.l + (data.length === 1 ? cw / 2 : cw * i / (data.length - 1));
    const yAt = v => pad.t + ch - (ch * v / maxV);
    // ناحیه زیر نمودار
    const gradient = ctx.createLinearGradient(0, pad.t, 0, pad.t + ch);
    gradient.addColorStop(0, 'rgba(30,64,175,.22)'); gradient.addColorStop(1, 'rgba(30,64,175,0)');
    ctx.beginPath(); ctx.moveTo(xAt(0), yAt(data[0].v));
    data.forEach((d, i) => ctx.lineTo(xAt(i), yAt(d.v)));
    ctx.lineTo(xAt(data.length - 1), pad.t + ch); ctx.lineTo(xAt(0), pad.t + ch); ctx.closePath();
    ctx.fillStyle = gradient; ctx.fill();
    // خط بازدید کل
    ctx.beginPath(); ctx.strokeStyle = '#1e40af'; ctx.lineWidth = 2.2;
    data.forEach((d, i) => i ? ctx.lineTo(xAt(i), yAt(d.v)) : ctx.moveTo(xAt(i), yAt(d.v)));
    ctx.stroke();
    // خط بازدید یکتا
    ctx.beginPath(); ctx.strokeStyle = '#16a34a'; ctx.lineWidth = 1.8; ctx.setLineDash([5, 4]);
    data.forEach((d, i) => i ? ctx.lineTo(xAt(i), yAt(d.u)) : ctx.moveTo(xAt(i), yAt(d.u)));
    ctx.stroke(); ctx.setLineDash([]);
    // تاریخ‌ها
    ctx.fillStyle = '#64748b'; ctx.font = '9px Tahoma'; ctx.textAlign = 'center';
    const step = Math.ceil(data.length / 7);
    data.forEach((d, i) => { if (i % step === 0) ctx.fillText(d.date.slice(5), xAt(i), 232); });
})();

/* ═══════════════════════════════════════════════════════════════
 * 🆕 v2.29 — نمودارهای جدید: ساعتی / روز هفته / منابع ورود / جدید-بازگشتی
 * بدون وابستگی خارجی — Canvas خالص با گرادیانت و میله‌های گرد
 * ═══════════════════════════════════════════════════════════════ */

/* 🔧 ابزار مشترک میله‌ای افقی/عمودی با میله‌های گرد و گرادیانت */
function sahandBars(canvasId, data, opts) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    if (!data || !data.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">📊</div><p>داده‌ای موجود نیست.</p></div>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    const H = opts.height || 200;
    canvas.width = w * dpr; canvas.height = H * dpr;
    ctx.scale(dpr, dpr);
    const horizontal = !!opts.horizontal;
    const pad = { t: 14, r: 14, b: 26, l: horizontal ? 110 : 34 };
    const cw = w - pad.l - pad.r, ch = H - pad.t - pad.b;
    const maxV = Math.max(...data.map(d => d.v), 4);
    const c1 = opts.color1 || '#1e40af', c2 = opts.color2 || '#3b82f6';
    data.forEach((d, i) => {
        const grad = horizontal
            ? ctx.createLinearGradient(pad.l, 0, pad.l + cw, 0)
            : ctx.createLinearGradient(0, pad.t + ch, 0, pad.t);
        grad.addColorStop(0, c1); grad.addColorStop(1, c2);
        ctx.fillStyle = grad;
        if (horizontal) {
            const bh = Math.min(26, ch / data.length - 7);
            const y = pad.t + i * (ch / data.length) + (ch / data.length - bh) / 2;
            const bw = Math.max(3, d.v / maxV * cw);
            ctx.beginPath();
            const rr = Math.min(bh / 2, 7);
            ctx.moveTo(pad.l + rr, y); ctx.arcTo(pad.l + bw, y, pad.l + bw, y + bh, rr);
            ctx.arcTo(pad.l + bw, y + bh, pad.l, y + bh, rr); ctx.arcTo(pad.l, y + bh, pad.l, y, rr);
            ctx.arcTo(pad.l, y, pad.l + bw, y, rr); ctx.closePath(); ctx.fill();
            ctx.fillStyle = '#1e293b'; ctx.font = 'bold 11.5px Vazirmatn, Tahoma'; ctx.textAlign = 'right';
            ctx.fillText(d.l, pad.l - 8, y + bh / 2 + 4);
            ctx.fillStyle = '#64748b'; ctx.font = '10.5px Vazirmatn, Tahoma'; ctx.textAlign = 'left';
            ctx.fillText(new Intl.NumberFormat('fa-IR').format(d.v), pad.l + bw + 6, y + bh / 2 + 4);
        } else {
            const bw = Math.min(38, cw / data.length - 6);
            const x = pad.l + i * (cw / data.length) + (cw / data.length - bw) / 2;
            const bh = Math.max(3, d.v / maxV * ch);
            const y = pad.t + ch - bh;
            ctx.beginPath();
            const rr = Math.min(6, bw / 2);
            ctx.moveTo(x + rr, y); ctx.arcTo(x + bw, y, x + bw, y + bh, rr);
            ctx.arcTo(x + bw, y + bh, x, y + bh, 0); ctx.lineTo(x, pad.t + ch);
            ctx.arcTo(x, y + bh, x, y, rr); ctx.closePath(); ctx.fill();
            ctx.fillStyle = '#64748b'; ctx.font = '10px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
            ctx.fillText(d.l, x + bw / 2, H - 8);
            if (d.v > 0) {
                ctx.fillStyle = '#1e40af'; ctx.font = 'bold 10px Vazirmatn, Tahoma';
                ctx.fillText(new Intl.NumberFormat('fa-IR').format(d.v), x + bw / 2, y - 4);
            }
        }
    });
}

/* 🕐 ساعات شبانه‌روز */
(function () {
    const hMap = <?= json_encode($hourMap) ?>;
    const labels = <?= json_encode(array_map(static fn($h) => strtr(sprintf('%02d', $h), ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']), range(0, 23))) ?>;
    sahandBars('hours-chart', hMap.map((v, h) => ({ l: labels[h], v })), { height: 200 });
})();

/* 📅 روزهای هفته */
(function () {
    const wdMap = <?= json_encode($weekdayMap) ?>;
    const wdFa = <?= json_encode($weekdayFa) ?>;
    sahandBars('weekday-chart', wdMap.map((v, d) => ({ l: wdFa[d], v })), { height: 200, color1: '#0f766e', color2: '#14b8a6' });
})();

/* 🔗 منابع ورود */
(function () {
    const refs = <?= json_encode(array_map(static fn($v) => (int)$v, array_values($refGroups))) ?>;
    const refLabels = <?= json_encode(array_keys($refGroups)) ?>;
    sahandBars('referrers-chart', refLabels.map((l, i) => ({ l, v: refs[i] })), { height: 230, horizontal: true, color1: '#7c2d12', color2: '#f59e0b' });
})();

/* 🔄 جدید در برابر بازگشتی — دونات */
(function () {
    const canvas = document.getElementById('newret-chart');
    if (!canvas) return;
    const nv = <?= (int)$newVisitors ?>, rv = <?= (int)$returningSessions ?>;
    if (nv + rv === 0) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">🔄</div><p>داده‌ای موجود نیست.</p></div>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    canvas.width = w * dpr; canvas.height = 175 * dpr;
    ctx.scale(dpr, dpr);
    const total = nv + rv;
    const cx = w * 0.5, cy = 87, r = 66;
    let angle = -Math.PI / 2;
    [[nv, '#1e40af', 'جدید'], [rv, '#16a34a', 'بازگشتی']].forEach(([val, color]) => {
        const slice = (val / total) * Math.PI * 2;
        ctx.beginPath(); ctx.moveTo(cx, cy); ctx.arc(cx, cy, r, angle, angle + slice); ctx.closePath();
        ctx.fillStyle = color; ctx.fill();
        angle += slice;
    });
    ctx.beginPath(); ctx.arc(cx, cy, r * 0.58, 0, Math.PI * 2); ctx.fillStyle = '#fff'; ctx.fill();
    ctx.fillStyle = '#1e293b'; ctx.font = 'bold 17px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
    ctx.fillText(new Intl.NumberFormat('fa-IR').format(total), cx, cy + 6);
    ctx.font = '11px Vazirmatn, Tahoma';
    const nw = Math.round(nv / total * 100), rw = 100 - nw;
    ctx.textAlign = 'right'; ctx.fillStyle = '#1e40af';
    ctx.fillText('🆕 جدید: ' + new Intl.NumberFormat('fa-IR').format(nv) + ' (' + new Intl.NumberFormat('fa-IR').format(nw) + '٪)', w - 14, 26);
    ctx.fillStyle = '#16a34a';
    ctx.fillText('🔁 بازگشتی: ' + new Intl.NumberFormat('fa-IR').format(rv) + ' (' + new Intl.NumberFormat('fa-IR').format(rw) + '٪)', w - 14, 48);
})();

/* 🥧 نمودار دایره‌ای سهم برندها */
(function () {
    const canvas = document.getElementById('brands-chart');
    if (!canvas) return;
    const data = <?= json_encode(array_map(fn($r) => ['label' => $r['name_fa'], 'value' => (int)$r['visits']], $brandShare)) ?>;
    if (!data.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">🥧</div><p>داده‌ای موجود نیست.</p></div>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    canvas.width = w * dpr; canvas.height = 260 * dpr;
    ctx.scale(dpr, dpr);
    const colors = ['#1e40af', '#16a34a', '#f59e0b', '#0891b2', '#7c3aed', '#dc2626', '#db2777', '#4d7c0f'];
    const total = data.reduce((s, d) => s + d.value, 0);
    const cx = w * 0.32, cy = 130, r = 82;
    let angle = -Math.PI / 2;
    data.forEach((d, i) => {
        const slice = (d.value / total) * Math.PI * 2;
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, r, angle, angle + slice);
        ctx.closePath();
        ctx.fillStyle = colors[i % colors.length];
        ctx.fill();
        angle += slice;
    });
    // حفره مرکزی (دونات)
    ctx.beginPath(); ctx.arc(cx, cy, r * 0.55, 0, Math.PI * 2);
    ctx.fillStyle = '#fff'; ctx.fill();
    ctx.fillStyle = '#1e293b'; ctx.font = 'bold 15px Tahoma'; ctx.textAlign = 'center';
    ctx.fillText(new Intl.NumberFormat('fa-IR').format(total), cx, cy + 5);
    // راهنما
    ctx.textAlign = 'right'; ctx.font = '12px Tahoma';
    data.forEach((d, i) => {
        const y = 40 + i * 24;
        ctx.fillStyle = colors[i % colors.length];
        ctx.fillRect(w * 0.62, y - 9, 13, 13);
        ctx.fillStyle = '#1e293b';
        ctx.fillText(d.label + ' (' + new Intl.NumberFormat('fa-IR').format(d.value) + ')', w - 10, y + 2);
    });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
