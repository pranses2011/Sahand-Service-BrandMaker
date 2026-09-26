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
/* 🚨 v2.31 — ریشه «بازدیدهای شنبه به پنجشنبه می‌افتد»: WEEKDAY()
   MySQL دوشنبه=۰ ... شنبه=۶ برمی‌گرداند اما آرایه فارسی از شنبه=۰
   شروع می‌شود → همه یک روز جابه‌جا! نگاشت صحیح: (WEEKDAY+2)%7 */
$byWeekday = $safeQuery("SELECT ((WEEKDAY(v.visit_date) + 2) % 7) AS wd, COUNT(DISTINCT v.session_hash) AS c FROM visits v WHERE {$where} GROUP BY wd ORDER BY wd", $params);
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

/* ═══════════════════════════════════════════════════════════════
 * 🆕 v2.30 — گزارش‌های جدید (درخواست کاربر: «انواع بیشتری از گزارش
 * و آمار همراه با نمودارهای زیبا»)
 * ═══════════════════════════════════════════════════════════════ */

/* 🌍 پراکندگی کشورها */
$byCountry = $safeQuery(
    "SELECT COALESCE(NULLIF(v.country, ''), 'IR') AS cc, COUNT(DISTINCT v.session_hash) AS c
     FROM visits v WHERE {$where} GROUP BY cc ORDER BY c DESC LIMIT 8",
    $params
);
$countryFa = [
    'IR' => '🇮🇷 ایران', 'TR' => '🇹🇷 ترکیه', 'AE' => '🇦🇪 امارات', 'DE' => '🇩🇪 آلمان',
    'US' => '🇺🇸 آمریکا', 'NL' => '🇳🇱 هلند', 'GB' => '🇬🇧 انگلیس', 'CA' => '🇨🇦 کانادا',
    'AF' => '🇦🇫 افغانستان', 'IQ' => '🇮🇶 عراق', 'RU' => '🇷🇺 روسیه', 'FR' => '🇫🇷 فرانسه',
    'SE' => '🇸🇪 سوئد', 'AU' => '🇦🇺 استرالیا', 'IN' => '🇮🇳 هند', 'CN' => '🇨🇳 چین',
];

/* 🔥 نقشه حرارتی ساعت × روز هفته (۷×۲۴) — نگاشت درست روز هفته v2.31 */
$heatRows = $safeQuery(
    "SELECT ((WEEKDAY(v.visit_date) + 2) % 7) AS wd, HOUR(v.visited_at) AS h, COUNT(DISTINCT v.session_hash) AS c
     FROM visits v WHERE {$where} GROUP BY wd, h",
    $params
);
$heatMap = array_fill(0, 7, array_fill(0, 24, 0));
foreach ((array)$heatRows as $hr) {
    $wd = (int)$hr['wd']; $h = (int)$hr['h'];
    if ($wd >= 0 && $wd < 7 && $h >= 0 && $h < 24) { $heatMap[$wd][$h] = (int)$hr['c']; }
}

/* ⏱ روند میانگین مدت حضور روزانه (ثانیه) */
$durationTrend = $safeQuery(
    "SELECT v.visit_date, ROUND(AVG(vd.duration)) AS avg_dur
     FROM visit_details vd JOIN visits v ON v.id = vd.visit_id
     WHERE {$where} AND vd.duration > 0 GROUP BY v.visit_date ORDER BY v.visit_date LIMIT 30",
    $params
);

/* 🚪 صفحات ورود (شروع سفر کاربر) */
$entryPages = $safeQuery(
    "SELECT v.entry_page, COUNT(DISTINCT v.session_hash) AS c
     FROM visits v WHERE {$where} AND v.entry_page IS NOT NULL AND v.entry_page != ''
     GROUP BY v.entry_page ORDER BY c DESC LIMIT 8",
    $params
);

/* 📊 تعامل کاربران: میانگین صفحات دیده‌شده در هر بازدید */
$engagementRow = $safeQuery(
    "SELECT COUNT(DISTINCT v.session_hash) AS uv, COUNT(vd.id) AS pv
     FROM visits v LEFT JOIN visit_details vd ON vd.visit_id = v.id
     WHERE {$where}",
    $params
);
$pagesPerVisit = ($engagementRow && (int)$engagementRow[0]['uv'] > 0)
    ? round((int)$engagementRow[0]['pv'] / (int)$engagementRow[0]['uv'], 2)
    : 0;

/* 🌡 رتبه‌بندی روز-ساعت داغ */
$heatPeak = ['wd' => 0, 'h' => 0, 'c' => 0];
foreach ($heatMap as $wd => $hours) {
    foreach ($hours as $h => $c) {
        if ($c > $heatPeak['c']) { $heatPeak = ['wd' => $wd, 'h' => $h, 'c' => $c]; }
    }
}

/* 🗺 مختصات شهرها برای فهرست رتبه‌بندی (نقشه استانی جایگزین حباب‌ها شد) */

/* ═══════════════════════════════════════════════════════════
 * 🆕 v2.31 — گزارش‌های جدید: نقشه استانی + ماتریس مرورگر×سیستم +
 * رشد هفتگی + عملکرد مقالات + کاربران آنلاین
 * ═══════════════════════════════════════════════════════════ */

/* 🗺️ پراکندگی استانی — شهر خالی از ip_prefix با GeoIP بازیابی می‌شود */
$provRows = $safeQuery(
    "SELECT COALESCE(NULLIF(v.province, ''), '') AS prov, v.ip_prefix, COUNT(DISTINCT v.session_hash) AS c
     FROM visits v WHERE {$where} AND (v.city IS NOT NULL AND v.city != '' OR (v.ip_prefix IS NOT NULL AND v.ip_prefix != ''))
     GROUP BY prov, v.ip_prefix",
    $params
);
$provincesDist = [];
foreach ((array)$provRows as $pr) {
    $prov = trim((string)$pr['prov']);
    if ($prov === '') {
        /* ردیف قدیمی بدون استان — از GeoIP سه‌لایه بازیابی */
        $prov = GeoIP::province((string)($pr['ip_prefix'] ?? ''));
        if ($prov === '') { continue; }
    }
    $provincesDist[$prov] = ($provincesDist[$prov] ?? 0) + (int)$pr['c'];
}
arsort($provincesDist);

/* 🧮 ماتریس مرورگر × سیستم‌عامل (۸×۶) */
$browserOsRows = $safeQuery(
    "SELECT v.browser, v.os, COUNT(DISTINCT v.session_hash) AS c
     FROM visits v WHERE {$where} AND v.browser IS NOT NULL AND v.os IS NOT NULL
     GROUP BY v.browser, v.os ORDER BY c DESC LIMIT 80",
    $params
);
$browserOsMatrix = [];
foreach ((array)$browserOsRows as $br) {
    $b = (string)$br['browser'];
    $osRaw = (string)$br['os'];
    $osPretty = preg_match('/windows/i', $osRaw) ? 'ویندوز'
        : (preg_match('/android/i', $osRaw) ? 'اندروید'
        : (preg_match('/iphone|ipad|mac/i', $osRaw) ? 'iOS/مک'
        : (preg_match('/linux/i', $osRaw) ? 'لینوکس' : mb_substr($osRaw, 0, 12))));
    $browserOsMatrix[$b][$osPretty] = ($browserOsMatrix[$b][$osPretty] ?? 0) + (int)$br['c'];
}

/* 📈 رشد هفتگی (۸ هفته اخیر — از روز شنبه شروع) */
$weeklyRows = $safeQuery(
    "SELECT YEARWEEK(v.visit_date, 3) AS yw, MIN(v.visit_date) AS wk_start, COUNT(DISTINCT v.session_hash) AS u, COUNT(*) AS views
     FROM visits v WHERE {$where} GROUP BY yw ORDER BY yw ASC",
    $params
);
$weeklyGrowth = [];
foreach ((array)$weeklyRows as $wr) {
    $weeklyGrowth[] = ['start' => (string)$wr['wk_start'], 'u' => (int)$wr['u'], 'views' => (int)$wr['views']];
}
$weeklyGrowth = array_slice($weeklyGrowth, -8);

/* 📰 عملکرد مقالات (صفحات /blog/) */
$articleRows = $safeQuery(
    "SELECT vd.page_url, COUNT(*) AS views, AVG(vd.duration) AS avg_dur
     FROM visit_details vd JOIN visits v ON v.id = vd.visit_id
     WHERE {$where} AND vd.page_url LIKE '%/blog/%'
     GROUP BY vd.page_url ORDER BY views DESC LIMIT 8",
    $params
);

/* 👥 کاربران آنلاین (۵ دقیقه اخیر) — صفحه جداگانه AJAX */
$onlineCount = 0;
try {
    $onlineCount = (int)$db->fetchValue(
        'SELECT COUNT(DISTINCT session_hash) FROM visits WHERE last_seen >= ?',
        [date('Y-m-d H:i:s', time() - 300)]
    );
} catch (Throwable $onlineE) { /* ستون last_seen هنوز ساخته نشده */ }

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
    $trackerToday = (int)$db->fetchValue('SELECT COUNT(*) FROM visits WHERE visit_date = ?', [date('Y-m-d')]);
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
    <div class="card-header">
        <h3>🗺️ پراکندگی جغرافیایی بازدیدکنندگان — نقشه استانی ایران</h3>
        <?php if (!empty($provincesDist)): ?>
            <span class="badge badge-info" style="font-size:11px">🎯 <?= en_to_fa_digits((string)count($provincesDist)) ?> استان</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($provincesDist) && empty($citiesDist)): ?>
            <div class="empty-state"><div class="icon">🗺️</div><p>داده جغرافیایی ثبت نشده است.<br><small>پس از بازدید اولین کاربران، نقشه استان‌ها اینجا نمایش داده می‌شود.</small></p></div>
        <?php else: ?>
            <?php
            /* 🎨 v2.31 — رنگ‌آمیزی استان‌ها بر اساس شدت بازدید */
            $iranMap = GeoIP::iranMap();
            $maxProv = max($provincesDist ?: [1]) ?: 1;
            $totalProv = array_sum($provincesDist) ?: 1;
            $provShown = array_slice($provincesDist, 0, 31, true);
            $provincePaths = '';
            foreach ($iranMap as $provName => $geo):
                $count = (int)($provShown[$provName] ?? 0);
                if ($count > 0) {
                    $t = pow($count / $maxProv, 0.6);
                    $r = (int)round(219 + (30 - 219) * $t);
                    $g = (int)round(234 + (64 - 234) * $t);
                    $b = (int)round(254 + (175 - 254) * $t);
                    $fill = "rgb({$r},{$g},{$b})";
                } else {
                    $fill = '#f1f5f9';
                }
                $provincePaths .= '<path d="' . e($geo['path']) . '" fill="' . $fill . '" stroke="#94a3b8" stroke-width="1" stroke-linejoin="round" class="ir-prov" data-prov="' . e($provName) . '" data-count="' . $count . '"><title>' . e($provName) . ($count > 0 ? ' — ' . en_to_fa_digits((string)$count) . ' بازدیدکننده' : '') . '</title></path>';
            endforeach;
            $provLabels = '';
            foreach ($provShown as $provName => $count):
                if (!isset($iranMap[$provName]) || $count < $maxProv * 0.12) { continue; }
                [$lx, $ly] = $iranMap[$provName]['xy'];
                $provLabels .= '<text x="' . $lx . '" y="' . ($ly - 7) . '" text-anchor="middle" font-size="17" font-weight="800" fill="#0c2d6b">' . e(en_to_fa_digits((string)$count)) . '</text>'
                    . '<text x="' . $lx . '" y="' . ($ly + 12) . '" text-anchor="middle" font-size="12.5" font-weight="700" fill="#334155">' . e($provName) . '</text>';
            endforeach;
            ?>
            <div style="display:grid;grid-template-columns:1fr 290px;gap:18px;align-items:start">
                <div style="direction:ltr;background:linear-gradient(160deg,#f8fbff,#eef5fc);border-radius:14px;padding:6px;border:1px solid #dbeafe">
                    <svg viewBox="0 0 1000 903" style="width:100%;height:auto;display:block" role="img" aria-label="نقشه استانی پراکندگی بازدید ایران">
                        <?= $provincePaths ?>
                        <?= $provLabels ?>
                    </svg>
                    <div style="text-align:center;font-size:10px;color:#64748b;padding:4px 0 2px">نقشه استانی واقعی (۳۱ استان) — عدد داخل هر استان = بازدیدکننده یکتا</div>
                </div>
                <div>
                    <div style="font-size:12px;font-weight:800;margin-bottom:9px;color:#334155">🏆 رتبه‌بندی استان‌ها</div>
                    <?php $pi = 0; foreach (array_slice($provShown, 0, 12, true) as $provName => $count): $pi++; ?>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:7px">
                            <span style="flex:none;width:21px;height:21px;border-radius:7px;background:<?= $pi === 1 ? '#1e40af' : ($pi === 2 ? '#3b82f6' : ($pi === 3 ? '#93c5fd' : '#e2e8f0')) ?>;color:<?= $pi <= 3 ? '#fff' : '#475569' ?>;font-size:10.5px;font-weight:800;display:flex;align-items:center;justify-content:center"><?= e(en_to_fa_digits((string)$pi)) ?></span>
                            <span style="flex:1;font-size:12.5px">📍 <?= e($provName) ?></span>
                            <b style="font-size:12.5px"><?= e(en_to_fa_digits((string)$count)) ?></b>
                            <span style="font-size:10px;color:#94a3b8;flex:none;width:38px;text-align:left"><?= e(en_to_fa_digits((string)round($count / $totalProv * 100))) ?>٪</span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!empty($citiesDist)): ?>
                        <div style="font-size:12px;font-weight:800;margin:13px 0 8px;color:#334155">🏙️ شهرها</div>
                        <?php $totalCity = array_sum($citiesDist) ?: 1; foreach (array_slice($citiesDist, 0, 8, true) as $city => $count): ?>
                            <div style="display:flex;justify-content:space-between;font-size:11.5px;margin-bottom:5px">
                                <span>📍 <?= e($city) ?></span>
                                <span><b><?= e(en_to_fa_digits((string)$count)) ?></b> <span style="color:#94a3b8;font-size:10px">(<?= e(en_to_fa_digits((string)round($count / $totalCity * 100))) ?>٪)</span></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div class="hint" style="margin-top:10px">🛡️ تشخیص جغرافیای دقیق با سرویس GeoIP + کش ۴۵ روزه — رنج‌های موبایل سراسری‌اند و شهر دقیق هر کاربر قابل قطعیت نیست؛ استان از سرویس معتبر جغرافیایی خوانده می‌شود.</div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 🆕 v2.31 — کاربران آنلاین -->
<div class="card" style="margin-bottom:18px">
    <div class="card-header">
        <h3>👥 کاربران آنلاین</h3>
        <span class="badge <?= $onlineCount > 0 ? 'badge-success' : '' ?>" style="font-size:11px" id="online-badge">🟢 <?= en_to_fa_digits((string)$onlineCount) ?> نفر آنلاین</span>
    </div>
    <div class="card-body" id="online-users-body">
        <div class="empty-state" style="padding:18px"><div class="icon">👤</div><p>در ۵ دقیقه اخیر کاربر فعالی روی سایت‌های برند مشاهده نشده است.</p></div>
    </div>
    <style>.online-user-btn:hover{border-color:#93c5fd !important;background:#eff6ff !important}</style>
<div class="hint" style="padding:0 16px 12px">💡 روی نام هر کاربر کلیک کنید تا جزئیات کامل او (صفحات بازدیدشده، دستگاه، مبدأ ورود و...) در کادر باز شود — فهرست هر ۳۰ ثانیه خودکار بروزرسانی می‌شود.</div>
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

<!-- ═══════════════════════════════════════════════════════════════
     🆕 v2.30 — گزارش‌های جدید: نقشه حرارتی + کشورها + مقایسه دوره
     ═══════════════════════════════════════════════════════════════ -->

<!-- 🔥 نقشه حرارتی ساعت × روز هفته (تمام‌عرض) -->
<div class="card" style="margin-bottom:18px">
    <div class="card-header">
        <h3>🔥 نقشه حرارتی بازدید — ساعت × روز هفته</h3>
        <?php if ($heatPeak['c'] > 0): ?>
            <span class="badge badge-info" style="font-size:11px">اوج بازدید: <?= e($weekdayFa[$heatPeak['wd']]) ?> ساعت <?= e(en_to_fa_digits(str_pad((string)$heatPeak['h'], 2, '۰', STR_PAD_LEFT))) ?>:۰۰</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <canvas id="heatmap-chart" height="250"></canvas>
        <div style="display:flex;align-items:center;gap:6px;justify-content:center;margin-top:10px;font-size:10.5px;color:var(--text-light)">
            کم
            <?php foreach ([0.08, 0.25, 0.45, 0.65, 0.85, 1] as $ii): ?>
                <span style="width:26px;height:11px;border-radius:3px;display:inline-block;background:rgba(30,64,175,<?= $ii ?>)"></span>
            <?php endforeach; ?>
            زیاد
        </div>
    </div>
</div>

<div class="grid-2">
    <!-- 🌍 پراکندگی کشورها -->
    <div class="card">
        <div class="card-header"><h3>🌍 پراکندگی کشورهای بازدیدکنندگان</h3></div>
        <div class="card-body"><canvas id="countries-chart" height="230"></canvas></div>
    </div>
    <!-- 📊 مقایسه دوره جاری با دوره قبل -->
    <div class="card">
        <div class="card-header"><h3>📊 مقایسه با دوره قبل</h3></div>
        <div class="card-body">
            <canvas id="compare-chart" height="230"></canvas>
            <div style="display:flex;gap:12px;margin-top:12px;flex-wrap:wrap">
                <div style="flex:1;min-width:130px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:11px;text-align:center">
                    <div style="font-size:20px;font-weight:900;color:#1e40af"><?= e(en_to_fa_digits((string)$totalUnique)) ?></div>
                    <div style="font-size:11px;color:#64748b">🟦 بازدیدکننده این دوره</div>
                </div>
                <div style="flex:1;min-width:130px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:12px;padding:11px;text-align:center">
                    <div style="font-size:20px;font-weight:900;color:#475569"><?= e(en_to_fa_digits((string)$prevUnique)) ?></div>
                    <div style="font-size:11px;color:#64748b">⬜ دوره قبل</div>
                </div>
                <div style="flex:1;min-width:130px;background:<?= $totalViews >= $prevViews ? '#f0fdf4;border:1px solid #bbf7d0' : '#fef2f2;border:1px solid #fecaca' ?>;border-radius:12px;padding:11px;text-align:center">
                    <div style="font-size:20px;font-weight:900;color:<?= $totalViews >= $prevViews ? '#15803d' : '#b91c1c' ?>"><?= $prevViews > 0 ? e(en_to_fa_digits((string)round(($totalViews - $prevViews) / $prevViews * 100))) . '٪' : '—' ?></div>
                    <div style="font-size:11px;color:#64748b">📈 رشد بازدید صفحات</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="grid-2">
    <!-- ⏱ روند مدت حضور -->
    <div class="card">
        <div class="card-header"><h3>⏱ روند میانگین مدت حضور (ثانیه)</h3></div>
        <div class="card-body"><canvas id="duration-chart" height="210"></canvas></div>
    </div>
    <!-- 🚪 صفحات ورود و خروج -->
    <div class="card">
        <div class="card-header"><h3>🚪 صفحات ورود و خروج کاربران</h3></div>
        <div class="card-body">
            <canvas id="entryexit-chart" height="230"></canvas>
            <?php if (!empty($entryPages) || !empty($exitPages)): ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px">
                    <div>
                        <div style="font-size:11px;font-weight:800;color:var(--text-light);margin-bottom:6px">⬅️ پرتکرارترین صفحات ورود:</div>
                        <?php foreach (array_slice($entryPages, 0, 5) as $ep): ?>
                            <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:4px"><span dir="ltr" style="max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($ep['entry_page']) ?></span><b><?= e(en_to_fa_digits((string)$ep['c'])) ?></b></div>
                        <?php endforeach; ?>
                    </div>
                    <div>
                        <div style="font-size:11px;font-weight:800;color:var(--text-light);margin-bottom:6px">➡️ پرتکرارترین صفحات خروج:</div>
                        <?php foreach (array_slice($exitPages, 0, 5) as $xp): ?>
                            <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:4px"><span dir="ltr" style="max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($xp['page_url']) ?></span><b><?= e(en_to_fa_digits((string)$xp['exits'])) ?></b></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     🆕 v2.31 — گزارش‌های جدید: ماتریس مرورگر×سیستم + رشد هفتگی آبشاری + عملکرد مقالات
     ═══════════════════════════════════════════════════════════════ -->
<div class="grid-2">
    <!-- 🧮 ماتریس مرورگر × سیستم‌عامل -->
    <div class="card">
        <div class="card-header"><h3>🧮 ماتریس مرورگر × سیستم‌عامل</h3></div>
        <div class="card-body">
            <?php if (empty($browserOsMatrix)): ?>
                <div class="empty-state" style="padding:18px"><div class="icon">🧮</div><p>داده مرورگر/سیستم‌عامل ثبت نشده است.</p></div>
            <?php else: ?>
                <?php
                $osCols = [];
                foreach ($browserOsMatrix as $bRow) { foreach ($bRow as $osName => $c) { $osCols[$osName] = true; } }
                $osCols = array_keys($osCols);
                $matrixMax = 0;
                foreach ($browserOsMatrix as $bRow) { foreach ($bRow as $c) { $matrixMax = max($matrixMax, $c); } }
                ?>
                <div style="overflow-x:auto">
                    <table class="table" style="font-size:11.5px;min-width:380px">
                        <thead><tr><th>مرورگر</th><?php foreach ($osCols as $osName): ?><th style="text-align:center"><?= e($osName) ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($browserOsMatrix, 0, 7, true) as $bName => $osRow): ?>
                            <tr>
                                <td><b><?= e($bName) ?></b></td>
                                <?php foreach ($osCols as $osName): ?>
                                    <?php $cellVal = (int)($osRow[$osName] ?? 0); ?>
                                    <td style="text-align:center">
                                        <?php if ($cellVal > 0): ?>
                                            <?php $intensity = $matrixMax > 0 ? round($cellVal / $matrixMax, 2) : 0; ?>
                                            <span style="display:inline-block;min-width:34px;padding:3px 7px;border-radius:7px;font-weight:700;color:<?= $intensity > 0.5 ? '#fff' : '#1e3a8a' ?>;background:rgba(30,64,175,<?= max(0.08, $intensity) ?>)"><?= e(en_to_fa_digits((string)$cellVal)) ?></span>
                                        <?php else: ?>
                                            <span style="color:#cbd5e1">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- 📈 رشد هفتگی -->
    <div class="card">
        <div class="card-header"><h3>📈 رشد هفتگی بازدید (۸ هفته اخیر)</h3></div>
        <div class="card-body"><canvas id="weekly-chart" height="230"></canvas></div>
    </div>
</div>

<!-- 📰 عملکرد مقالات -->
<div class="card" style="margin-bottom:18px">
    <div class="card-header"><h3>📰 عملکرد مقالات منتشرشده</h3></div>
    <div class="card-body">
        <?php if (empty($articleRows)): ?>
            <div class="empty-state" style="padding:18px"><div class="icon">📰</div><p>هنوز بازدیدی از صفحات مقالات ثبت نشده است.</p></div>
        <?php else: ?>
            <canvas id="articles-chart" height="<?= max(140, count($articleRows) * 34) ?>"></canvas>
        <?php endif; ?>
    </div>
</div>

<!-- 🧩 نمودار Canvas بدون وابستگی خارجی -->
<script>
/* 🔄 v2.30 — همه نمودارها در یک تابع؛ تغییر اندازه پنجره → بازترسیم
   (قبلاً نمودار فقط یک‌بار در لود اول با عرض لحظه‌ای ترسیم می‌شد) */
function sahandDrawCharts() {

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

/* ═══════════════════════════════════════════════════════════════
 * 🆕 v2.30 — نمودارهای جدید: حرارتی / کشورها / مقایسه / مدت / ورود-خروج
 * ═══════════════════════════════════════════════════════════════ */

/* 🔥 نقشه حرارتی ساعت × روز هفته */
(function () {
    const canvas = document.getElementById('heatmap-chart');
    if (!canvas) return;
    const heat = <?= json_encode($heatMap) ?>;
    const wdFa = <?= json_encode($weekdayFa) ?>;
    const faNum = n => new Intl.NumberFormat('fa-IR').format(n);
    const totalCells = heat.flat().filter(v => v > 0).length;
    if (!totalCells) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">🔥</div><p>داده‌ای برای نقشه حرارتی موجود نیست.</p></div>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    const H = 250;
    canvas.width = w * dpr; canvas.height = H * dpr;
    ctx.scale(dpr, dpr);
    const pad = { t: 26, r: 14, b: 26, l: 58 };
    const cw = w - pad.l - pad.r, ch = H - pad.t - pad.b;
    const cellW = cw / 24, cellH = ch / 7;
    const maxC = Math.max(...heat.flat(), 1);
    /* خط‌کش ساعت (بالای شبکه) */
    ctx.fillStyle = '#64748b'; ctx.font = '9px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
    for (let h = 0; h < 24; h += 3) {
        ctx.fillText(String(h).padStart(2, '0'), pad.l + cellW * (h + 0.5), pad.t - 7);
    }
    for (let d = 0; d < 7; d++) {
        /* برچسب روز (سمت راست — RTL) */
        ctx.fillStyle = '#334155'; ctx.font = 'bold 10.5px Vazirmatn, Tahoma'; ctx.textAlign = 'right';
        ctx.fillText(wdFa[d], w - pad.r, pad.t + cellH * (d + 0.5) + 4);
        for (let h = 0; h < 24; h++) {
            const v = heat[d][h] || 0;
            const t = Math.pow(v / maxC, 0.65);
            const x = w - pad.r - cellW * (h + 1) + 1.2; /* RTL: ساعت از راست */
            const y = pad.t + cellH * d + 1.2;
            ctx.fillStyle = v > 0 ? 'rgba(30,64,175,' + (0.1 + 0.9 * t).toFixed(2) + ')' : '#f1f5f9';
            ctx.beginPath();
            const rr = Math.min(3.5, cellW / 3);
            ctx.moveTo(x + rr, y); ctx.arcTo(x + cellW - 2.4, y, x + cellW - 2.4, y + cellH - 2.4, rr);
            ctx.arcTo(x + cellW - 2.4, y + cellH - 2.4, x, y + cellH - 2.4, rr);
            ctx.arcTo(x, y + cellH - 2.4, x, y, rr); ctx.arcTo(x, y, x + cellW - 2.4, y, rr);
            ctx.closePath(); ctx.fill();
            if (v / maxC >= 0.55) {
                ctx.fillStyle = '#fff'; ctx.font = 'bold 9.5px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
                ctx.fillText(faNum(v), x + (cellW - 2.4) / 2, y + cellH / 2 + 3.5);
            }
        }
    }
})();

/* 🌍 دونات پراکندگی کشورها */
(function () {
    const canvas = document.getElementById('countries-chart');
    if (!canvas) return;
    const data = <?= json_encode(array_map(fn($r) => ['label' => $countryFa[$r['cc']] ?? $r['cc'], 'value' => (int)$r['c']], $byCountry)) ?>;
    if (!data.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">🌍</div><p>داده‌ای موجود نیست.</p></div>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    canvas.width = w * dpr; canvas.height = 230 * dpr;
    ctx.scale(dpr, dpr);
    const colors = ['#1e40af', '#0891b2', '#16a34a', '#f59e0b', '#7c3aed', '#dc2626', '#db2777', '#4d7c0f'];
    const total = data.reduce((s, d) => s + d.value, 0);
    const cx = w * 0.30, cy = 115, r = 76;
    let angle = -Math.PI / 2;
    data.forEach((d, i) => {
        const slice = (d.value / total) * Math.PI * 2;
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, r, angle, angle + slice);
        ctx.closePath();
        ctx.fillStyle = colors[i % colors.length];
        ctx.fill();
        /* فاصله بین قاچ‌ها */
        ctx.strokeStyle = '#fff'; ctx.lineWidth = 2.4; ctx.stroke();
        angle += slice;
    });
    ctx.beginPath(); ctx.arc(cx, cy, r * 0.56, 0, Math.PI * 2);
    ctx.fillStyle = '#fff'; ctx.fill();
    ctx.fillStyle = '#1e293b'; ctx.font = 'bold 15px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
    ctx.fillText(new Intl.NumberFormat('fa-IR').format(total), cx, cy + 5);
    ctx.font = '9.5px Vazirmatn, Tahoma'; ctx.fillStyle = '#64748b';
    ctx.fillText('بازدیدکننده', cx, cy + 20);
    /* راهنما */
    ctx.textAlign = 'right'; ctx.font = '11.5px Vazirmatn, Tahoma';
    data.forEach((d, i) => {
        const y = 34 + i * 25;
        ctx.fillStyle = colors[i % colors.length];
        ctx.fillRect(w * 0.60, y - 9, 13, 13);
        ctx.fillStyle = '#1e293b';
        ctx.fillText(d.label + ' — ' + new Intl.NumberFormat('fa-IR').format(d.value) + ' (' + Math.round(d.value / total * 100) + '٪)', w - 8, y + 2);
    });
})();

/* 📊 میله‌های جفتی مقایسه دوره جاری با دوره قبل */
(function () {
    const canvas = document.getElementById('compare-chart');
    if (!canvas) return;
    const cur = { u: <?= (int)$totalUnique ?>, v: <?= (int)$totalViews ?>, b: <?= (int)$returningSessions ?> };
    const prev = { u: <?= (int)$prevUnique ?>, v: <?= (int)$prevViews ?> };
    const faNum = n => new Intl.NumberFormat('fa-IR').format(n);
    const rows = [
        { l: 'بازدیدکننده یکتا', a: cur.u, b: prev.u },
        { l: 'بازدید صفحات', a: cur.v, b: prev.b !== undefined ? prev.v : 0 },
    ];
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    const H = 230;
    canvas.width = w * dpr; canvas.height = H * dpr;
    ctx.scale(dpr, dpr);
    const pad = { t: 34, r: 14, b: 26, l: 14 };
    const cw = w - pad.l - pad.r, ch = H - pad.t - pad.b;
    const maxV = Math.max(cur.u, cur.v, prev.u, prev.v, 4);
    /* راهنمای رنگ */
    ctx.font = 'bold 11px Vazirmatn, Tahoma'; ctx.textAlign = 'right';
    ctx.fillStyle = '#1e40af'; ctx.fillRect(w - 150, 8, 12, 12);
    ctx.fillStyle = '#1e293b'; ctx.fillText('دوره جاری', w - 158, 18);
    ctx.fillStyle = '#cbd5e1'; ctx.fillRect(w - 260, 8, 12, 12);
    ctx.fillStyle = '#1e293b'; ctx.fillText('دوره قبل', w - 268, 18);
    const groupW = cw / rows.length;
    rows.forEach((row, gi) => {
        const gx = w - pad.r - groupW * (gi + 1); /* RTL */
        const barW = Math.min(44, groupW / 2 - 14);
        const h1 = row.a / maxV * ch, h2 = row.b / maxV * ch;
        const xA = gx + groupW / 2 + 3, xB = gx + groupW / 2 - barW - 3;
        /* میله دوره جاری (راست) */
        const g1 = ctx.createLinearGradient(0, pad.t + ch - h1, 0, pad.t);
        g1.addColorStop(0, '#1e40af'); g1.addColorStop(1, '#3b82f6');
        ctx.fillStyle = g1;
        ctx.beginPath(); ctx.moveTo(xA + 6, pad.t + ch - h1);
        ctx.arcTo(xA + barW, pad.t + ch - h1, xA + barW, pad.t + ch, 6);
        ctx.lineTo(xA + barW, pad.t + ch); ctx.lineTo(xA, pad.t + ch);
        ctx.arcTo(xA, pad.t + ch, xA, pad.t + ch - h1, 6); ctx.closePath(); ctx.fill();
        /* میله دوره قبل (چپ) */
        ctx.fillStyle = '#cbd5e1';
        ctx.beginPath(); ctx.moveTo(xB + 6, pad.t + ch - h2);
        ctx.arcTo(xB + barW, pad.t + ch - h2, xB + barW, pad.t + ch, 6);
        ctx.lineTo(xB + barW, pad.t + ch); ctx.lineTo(xB, pad.t + ch);
        ctx.arcTo(xB, pad.t + ch, xB, pad.t + ch - h2, 6); ctx.closePath(); ctx.fill();
        /* اعداد بالای میله‌ها */
        ctx.fillStyle = '#1e40af'; ctx.font = 'bold 11.5px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
        ctx.fillText(faNum(row.a), xA + barW / 2, pad.t + ch - h1 - 6);
        ctx.fillStyle = '#64748b'; ctx.font = '10.5px Vazirmatn, Tahoma';
        ctx.fillText(faNum(row.b), xB + barW / 2, pad.t + ch - h2 - 6);
        /* برچسب گروه */
        ctx.fillStyle = '#334155'; ctx.font = 'bold 11.5px Vazirmatn, Tahoma';
        ctx.fillText(row.l, gx + groupW / 2, H - 8);
        /* خط صفر */
        ctx.strokeStyle = '#e2e8f0'; ctx.lineWidth = 1;
        ctx.beginPath(); ctx.moveTo(pad.l, pad.t + ch); ctx.lineTo(w - pad.r, pad.t + ch); ctx.stroke();
    });
})();

/* ⏱ روند میانگین مدت حضور */
(function () {
    const canvas = document.getElementById('duration-chart');
    if (!canvas) return;
    const data = <?= json_encode(array_map(fn($r) => ['date' => $r['visit_date'], 'v' => (int)$r['avg_dur']], $durationTrend)) ?>;
    if (!data.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">⏱</div><p>هنوز داده مدت حضور ثبت نشده است.<br><small>با خروج کاربر از سایت، مدت حضور واقعی ثبت می‌شود.</small></p></div>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    canvas.width = w * dpr; canvas.height = 210 * dpr;
    ctx.scale(dpr, dpr);
    const pad = { t: 15, r: 15, b: 26, l: 44 };
    const cw = w - pad.l - pad.r, ch = 210 - pad.t - pad.b;
    const maxV = Math.max(...data.map(d => d.v), 10);
    const xAt = i => pad.l + (data.length === 1 ? cw / 2 : cw * i / (data.length - 1));
    const yAt = v => pad.t + ch - (ch * v / maxV);
    for (let i = 0; i <= 4; i++) {
        const y = pad.t + ch - (ch * i / 4);
        ctx.strokeStyle = '#e2e8f0'; ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(pad.l + cw, y); ctx.stroke();
        ctx.fillStyle = '#64748b'; ctx.font = '9.5px Vazirmatn, Tahoma'; ctx.textAlign = 'right';
        ctx.fillText(Math.round(maxV * i / 4), pad.l - 5, y + 3);
    }
    const gradient = ctx.createLinearGradient(0, pad.t, 0, pad.t + ch);
    gradient.addColorStop(0, 'rgba(13,148,136,.25)'); gradient.addColorStop(1, 'rgba(13,148,136,0)');
    ctx.beginPath(); ctx.moveTo(xAt(0), yAt(data[0].v));
    data.forEach((d, i) => ctx.lineTo(xAt(i), yAt(d.v)));
    ctx.lineTo(xAt(data.length - 1), pad.t + ch); ctx.lineTo(xAt(0), pad.t + ch); ctx.closePath();
    ctx.fillStyle = gradient; ctx.fill();
    ctx.beginPath(); ctx.strokeStyle = '#0d9488'; ctx.lineWidth = 2.2;
    data.forEach((d, i) => i ? ctx.lineTo(xAt(i), yAt(d.v)) : ctx.moveTo(xAt(i), yAt(d.v)));
    ctx.stroke();
    /* نقطه‌ها */
    data.forEach((d, i) => {
        ctx.beginPath(); ctx.arc(xAt(i), yAt(d.v), 3, 0, Math.PI * 2);
        ctx.fillStyle = '#0d9488'; ctx.fill();
        ctx.strokeStyle = '#fff'; ctx.lineWidth = 1.4; ctx.stroke();
    });
    ctx.fillStyle = '#64748b'; ctx.font = '9px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
    const step = Math.ceil(data.length / 6);
    data.forEach((d, i) => { if (i % step === 0) ctx.fillText(d.date.slice(5), xAt(i), 200); });
})();

/* 🚪 صفحات ورود و خروج — میله‌های افقی جفتی */
(function () {
    const canvas = document.getElementById('entryexit-chart');
    if (!canvas) return;
    const entries = <?= json_encode(array_map(fn($r) => ['l' => mb_substr((string)$r['entry_page'], 0, 26), 'v' => (int)$r['c']], $entryPages)) ?>;
    const exits = <?= json_encode(array_map(fn($r) => ['l' => mb_substr((string)$r['page_url'], 0, 26), 'v' => (int)$r['exits']], $exitPages)) ?>;
    if (!entries.length && !exits.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">🚪</div><p>داده ورود/خروج ثبت نشده است.</p></div>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    const H = 230;
    canvas.width = w * dpr; canvas.height = H * dpr;
    ctx.scale(dpr, dpr);
    const faNum = n => new Intl.NumberFormat('fa-IR').format(n);
    const pad = { t: 24, r: 118, b: 12, l: 60 };
    const cw = w - pad.l - pad.r;
    /* نیمه راست: ورود | نیمه چپ: خروج */
    const half = cw / 2 - 8;
    const maxE = Math.max(...entries.map(d => d.v), 1);
    const maxX = Math.max(...exits.map(d => d.v), 1);
    const rowH = Math.min(26, (H - pad.t - pad.b) / Math.max(entries.length, exits.length, 1) - 4);
    ctx.font = 'bold 10.5px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
    ctx.fillStyle = '#16a34a'; ctx.fillText('⬅️ ورود', w - pad.r - half / 2, 12);
    ctx.fillStyle = '#dc2626'; ctx.fillText('خروج ➡️', pad.l + half / 2, 12);
    entries.slice(0, 7).forEach((d, i) => {
        const y = pad.t + i * (rowH + 4);
        const bw = Math.max(3, d.v / maxE * (half - 46));
        const x = w - pad.r - bw;
        const g = ctx.createLinearGradient(x, 0, w - pad.r, 0);
        g.addColorStop(0, '#15803d'); g.addColorStop(1, '#4ade80');
        ctx.fillStyle = g;
        ctx.beginPath();
        const rr = Math.min(rowH / 2, 6);
        ctx.moveTo(x + rr, y); ctx.arcTo(w - pad.r, y, w - pad.r, y + rowH, rr);
        ctx.arcTo(w - pad.r, y + rowH, x, y + rowH, rr); ctx.arcTo(x, y + rowH, x, y, rr);
        ctx.arcTo(x, y, w - pad.r, y, rr); ctx.closePath(); ctx.fill();
        ctx.fillStyle = '#334155'; ctx.font = '10px Vazirmatn, Tahoma'; ctx.textAlign = 'left';
        ctx.fillText(d.l, w - pad.r + 6, y + rowH / 2 + 3.5);
        ctx.fillStyle = '#15803d'; ctx.font = 'bold 9.5px Vazirmatn, Tahoma'; ctx.textAlign = 'right';
        ctx.fillText(faNum(d.v), x - 4, y + rowH / 2 + 3.5);
    });
    exits.slice(0, 7).forEach((d, i) => {
        const y = pad.t + i * (rowH + 4);
        const bw = Math.max(3, d.v / maxX * (half - 46));
        const g = ctx.createLinearGradient(pad.l, 0, pad.l + bw, 0);
        g.addColorStop(0, '#f87171'); g.addColorStop(1, '#b91c1c');
        ctx.fillStyle = g;
        ctx.beginPath();
        const rr = Math.min(rowH / 2, 6);
        ctx.moveTo(pad.l + rr, y); ctx.arcTo(pad.l + bw, y, pad.l + bw, y + rowH, rr);
        ctx.arcTo(pad.l + bw, y + rowH, pad.l, y + rowH, rr); ctx.arcTo(pad.l, y + rowH, pad.l, y, rr);
        ctx.arcTo(pad.l, y, pad.l + bw, y, rr); ctx.closePath(); ctx.fill();
        ctx.fillStyle = '#334155'; ctx.font = '10px Vazirmatn, Tahoma'; ctx.textAlign = 'right';
        ctx.fillText(d.l, pad.l - 6, y + rowH / 2 + 3.5);
        ctx.fillStyle = '#b91c1c'; ctx.font = 'bold 9.5px Vazirmatn, Tahoma'; ctx.textAlign = 'left';
        ctx.fillText(faNum(d.v), pad.l + bw + 4, y + rowH / 2 + 3.5);
    });
})();

/* ═══════════════════════════════════════════════════════════════
 * 🆕 v2.31 — نمودارهای جدید: رشد هفتگی آبشاری + عملکرد مقالات
 * ═══════════════════════════════════════════════════════════════ */

/* 📈 رشد هفتگی — میله‌های رنگی با خط روند */
(function () {
    const canvas = document.getElementById('weekly-chart');
    if (!canvas) return;
    const data = <?= json_encode(array_map(fn($w) => ['label' => jdate_short($w['start']), 'u' => $w['u'], 'views' => $w['views']], $weeklyGrowth)) ?>;
    if (!data.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">📈</div><p>داده هفتگی موجود نیست.</p></div>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    const H = 230;
    canvas.width = w * dpr; canvas.height = H * dpr;
    ctx.scale(dpr, dpr);
    const faNum = n => new Intl.NumberFormat('fa-IR').format(n);
    const pad = { t: 22, r: 12, b: 30, l: 38 };
    const cw = w - pad.l - pad.r, ch = H - pad.t - pad.b;
    const maxV = Math.max(...data.map(d => d.u), 4);
    for (let i = 0; i <= 3; i++) {
        const y = pad.t + ch - (ch * i / 3);
        ctx.strokeStyle = '#e2e8f0'; ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(pad.l + cw, y); ctx.stroke();
        ctx.fillStyle = '#64748b'; ctx.font = '9.5px Vazirmatn, Tahoma'; ctx.textAlign = 'right';
        ctx.fillText(Math.round(maxV * i / 3), pad.l - 5, y + 3);
    }
    const bw = Math.min(40, cw / data.length - 10);
    data.forEach((d, i) => {
        const x = pad.l + cw - (i + 0.5) * (cw / data.length) - bw / 2; /* RTL */
        const bh = Math.max(3, d.u / maxV * ch);
        const y = pad.t + ch - bh;
        /* رنگ میله بر اساس رشد نسبت به هفته قبل */
        const prev = i > 0 ? data[i - 1].u : d.u;
        const up = d.u >= prev;
        const grad = ctx.createLinearGradient(0, y, 0, pad.t + ch);
        if (up) { grad.addColorStop(0, '#1e40af'); grad.addColorStop(1, '#60a5fa'); }
        else { grad.addColorStop(0, '#b45309'); grad.addColorStop(1, '#fbbf24'); }
        ctx.fillStyle = grad;
        ctx.beginPath();
        const rr = Math.min(7, bw / 2);
        ctx.moveTo(x + rr, y); ctx.arcTo(x + bw, y, x + bw, y + bh, rr);
        ctx.lineTo(x + bw, y + bh); ctx.lineTo(x, y + bh);
        ctx.arcTo(x, y + bh, x, y, rr); ctx.arcTo(x, y, x + bw, y, rr); ctx.closePath(); ctx.fill();
        ctx.fillStyle = '#334155'; ctx.font = 'bold 10px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
        ctx.fillText(faNum(d.u), x + bw / 2, y - 4);
        ctx.fillStyle = '#64748b'; ctx.font = '9px Vazirmatn, Tahoma';
        ctx.fillText(d.label, x + bw / 2, H - 10);
    });
    /* خط روند */
    ctx.beginPath(); ctx.strokeStyle = '#0d9488'; ctx.lineWidth = 2; ctx.setLineDash([]);
    data.forEach((d, i) => {
        const x = pad.l + cw - (i + 0.5) * (cw / data.length);
        const y = pad.t + ch - Math.max(3, d.u / maxV * ch);
        i ? ctx.lineTo(x, y) : ctx.moveTo(x, y);
    });
    ctx.stroke();
})();

/* 📰 عملکرد مقالات — میله‌های افقی با مدت مطالعه */
(function () {
    const canvas = document.getElementById('articles-chart');
    if (!canvas) return;
    const data = <?= json_encode(array_map(fn($a) => ['l' => mb_substr((string)preg_replace('#^.*/blog/#', '', $a['page_url']), 0, 30), 'v' => (int)$a['views'], 'dur' => (int)round((float)$a['avg_dur'])], $articleRows)) ?>;
    if (!data.length) { return; }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    const H = Math.max(140, data.length * 34);
    canvas.width = w * dpr; canvas.height = H * dpr;
    ctx.scale(dpr, dpr);
    const faNum = n => new Intl.NumberFormat('fa-IR').format(n);
    const pad = { t: 12, r: 14, b: 10, l: 190 };
    const cw = w - pad.l - pad.r, ch = H - pad.t - pad.b;
    const maxV = Math.max(...data.map(d => d.v), 1);
    data.forEach((d, i) => {
        const rowH = ch / data.length;
        const y = pad.t + i * rowH + 3;
        const bh = rowH - 8;
        const bw = Math.max(4, d.v / maxV * (cw - 60));
        const x = w - pad.r - bw;
        const grad = ctx.createLinearGradient(x, 0, w - pad.r, 0);
        grad.addColorStop(0, '#7c2d12'); grad.addColorStop(1, '#fb923c');
        ctx.fillStyle = grad;
        ctx.beginPath();
        const rr = Math.min(bh / 2, 6);
        ctx.moveTo(x + rr, y); ctx.arcTo(w - pad.r, y, w - pad.r, y + bh, rr);
        ctx.arcTo(w - pad.r, y + bh, x, y + bh, rr); ctx.arcTo(x, y + bh, x, y, rr);
        ctx.arcTo(x, y, w - pad.r, y, rr); ctx.closePath(); ctx.fill();
        ctx.fillStyle = '#334155'; ctx.font = '10px Vazirmatn, Tahoma'; ctx.textAlign = 'right';
        ctx.fillText(d.l, w - pad.r + 4, y + bh / 2 + 3.5);
        ctx.fillStyle = '#7c2d12'; ctx.font = 'bold 10.5px Vazirmatn, Tahoma'; ctx.textAlign = 'left';
        ctx.fillText(faNum(d.v) + (d.dur > 0 ? ' · ' + faNum(Math.round(d.dur)) + 'ث' : ''), x - 5, y + bh / 2 + 3.5);
    });
})();

/* ═══════════════════════════════════════════════════════════════
 * 👥 v2.31 — کاربران آنلاین: بارگذاری زنده + کادر جزئیات
 * ═══════════════════════════════════════════════════════════════ */
window.sahandLoadOnlineUsers = function () {
    const body = document.getElementById('online-users-body');
    const badge = document.getElementById('online-badge');
    if (!body) return;
    fetch('analytics-online.php?ajax=1<?= $brandFilter > 0 ? '&brand=' . (int)$brandFilter : '' ?>', { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
        .then(function (res) {
            if (!res || !res.success) { return; }
            const faNum = n => new Intl.NumberFormat('fa-IR').format(n);
            if (badge) {
                badge.textContent = '🟢 ' + faNum(res.count) + ' نفر آنلاین';
                badge.className = 'badge ' + (res.count > 0 ? 'badge-success' : '');
            }
            if (!res.users || !res.users.length) {
                body.innerHTML = '<div class="empty-state" style="padding:18px"><div class="icon">👤</div><p>در ۵ دقیقه اخیر کاربر فعالی روی سایت‌های برند مشاهده نشده است.</p></div>';
                return;
            }
            let html = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px">';
            res.users.forEach(function (u) {
                const devIcon = { mobile: '📱', desktop: '🖥️', tablet: '📲', bot: '🤖' }[u.device_type] || '🖥️';
                html += '<button type="button" class="online-user-btn" data-session="' + u.session + '" style="text-align:right;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:10px 13px;cursor:pointer;font-family:inherit;transition:all .15s">'
                    + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:5px">'
                    + '<span style="font-size:19px">' + devIcon + '</span>'
                    + '<b style="font-size:12.5px;color:#0f172a">' + (u.brand_name || 'برند') + '</b>'
                    + '<span style="margin-inline-start:auto;font-size:10.5px;color:#16a34a;font-weight:700">● ' + (u.minutes_ago === 0 ? 'همین حالا' : faNum(u.minutes_ago) + ' دقیقه پیش') + '</span>'
                    + '</div>'
                    + '<div style="font-size:11px;color:#475569">📄 ' + (u.current_page || '—') + '</div>'
                    + '<div style="font-size:11px;color:#475569">📍 ' + (u.city || u.province || 'نامشخص') + ' · ' + (u.browser || '—') + '</div>'
                    + '</button>';
            });
            html += '</div>';
            body.innerHTML = html;
            body.querySelectorAll('.online-user-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    sahandShowOnlineDetails(btn.getAttribute('data-session'));
                });
            });
        })
        .catch(function () { /* بی‌صدا — تلاش بعدی در ۳۰ ثانیه */ });
};

window.sahandShowOnlineDetails = function (sessionHash) {
    fetch('analytics-online.php?ajax=1&details=' + encodeURIComponent(sessionHash) + '<?= $brandFilter > 0 ? '&brand=' . (int)$brandFilter : '' ?>', { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
        .then(function (res) {
            if (!res || !res.success || !res.details) { return; }
            const d = res.details;
            const faNum = n => new Intl.NumberFormat('fa-IR').format(n);
            let html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:12.5px">';
            const row = (k, v) => '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:8px 12px"><div style="font-size:10.5px;color:#64748b;margin-bottom:3px">' + k + '</div><b>' + (v || '—') + '</b></div>';
            html += row('🏷️ برند', d.brand_name);
            html += row('⏰ آخرین فعالیت', d.last_seen);
            html += row('📱 دستگاه', d.device_type);
            html += row('🌐 مرورگر', d.browser + ' · ' + d.os);
            html += row('📍 مکان', (d.city ? d.city : '') + (d.province ? ' / ' + d.province : '') || 'نامشخص');
            html += row('🖥️ رزولوشن', d.resolution);
            html += row('🗣️ زبان', d.language);
            html += row('🚪 صفحه ورود', d.entry_page);
            html += row('📄 صفحه فعلی', d.current_page);
            html += row('🔗 مبدأ ورود', d.referrer);
            html += row('👁️ صفحات دیده‌شده', faNum(d.pages_seen));
            html += row('⏱️ مدت حضور', d.duration_text);
            html += '</div>';
            html += '<div style="margin-top:12px;font-size:11px;font-weight:800;color:#334155;margin-bottom:6px">📜 مسیر بازدید (آخرین ۱۰ صفحه):</div><div style="font-size:11.5px;line-height:2.1">';
            (d.recent_pages || []).forEach(function (p) {
                html += '<div style="display:flex;justify-content:space-between;background:#f8fafc;border-radius:8px;padding:4px 10px;margin-bottom:4px"><span style="direction:ltr">' + p.url + '</span><span style="color:#64748b;white-space:nowrap">' + p.time + (p.duration > 0 ? ' · ' + faNum(p.duration) + ' ثانیه' : '') + '</span></div>';
            });
            html += '</div>';
            if (window.SahandDialog) {
                SahandDialog.dialog({ title: '👤 جزئیات کاربر آنلاین', html: html, buttons: [{ text: 'بستن', btn: 'primary' }] });
            } else {
                alert(html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' '));
            }
        })
        .catch(function () { /* بی‌صدا */ });
};

sahandLoadOnlineUsers();
setInterval(sahandLoadOnlineUsers, 30000);

} /* پایان sahandDrawCharts */

/* ▶ اجرای اولیه + بازترسیم با تأخیر هنگام تغییر اندازه */
sahandDrawCharts();
let sahandResizeTimer = null;
window.addEventListener('resize', function () {
    clearTimeout(sahandResizeTimer);
    sahandResizeTimer = setTimeout(sahandDrawCharts, 180);
});
/* بازترسیم پس از بارگذاری کامل فونت (متن نمودارها با فونت درست) */
if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(function () { sahandDrawCharts(); });
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
