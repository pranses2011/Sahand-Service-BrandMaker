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

/* 🛂 v2.34 — ACL سطح‌برند: گارد دسترسی (GET brand / POST brand_id)
   brand_manager فقط به برندهای تخصیص‌یافته در users.php دسترسی دارد */
$_aclBrand = (int)($_GET['brand'] ?? 0);
if ($_aclBrand < 1) { $_aclBrand = (int)($_POST['brand_id'] ?? ($_POST['brand'] ?? 0)); }
if ($_aclBrand > 0) {
    (new Auth())->requireBrandAccess($_aclBrand);
}


$db = Database::getInstance();

/* ═══ 🔄 v2.32 — بازحسابی جغرافیایی (دکمه پنل) ═══
   ریشه «کاربر تبریز → خراسان رضوی»: ① کش کامل پاک می‌شود ② پیشوندهای
   /24 معتبر دوباره از سرویس خارجی حلابی می‌شوند ③ جغرافیای نامعتبر
   (پیشوند /16 قدیمی یا حدس استاتیک) پاک می‌شود تا بازدید بعدی درست
   بک‌فیل شود. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'geo_recompute') {
    (new Auth())->requireLogin();
    Auth::enforceCsrf();
    @set_time_limit(0);
    ignore_user_abort(true);
    try {
        $stats = GeoIP::recompute(120);
        Logger::activity((int)$_SESSION['user_id'], 'بازحسابی جغرافیایی بازدیدها', 'پاک‌سازی کش + ' . (int)$stats['prefixes_resolved'] . ' پیشوند بازحسابی');
        json_response([
            'success' => true,
            'data' => [
                'message' => '✅ بازحسابی انجام شد — ' .
                    (int)$stats['prefixes_resolved'] . ' پیشوند جغرافیایی دوباره حلابی شد (' . (int)$stats['rows_updated'] . ' بازدید بروزرسانی) و ' .
                    (int)$stats['rows_purged'] . ' ردیف جغرافیای نامعتبر پاک شد.<br><small>ردیف‌های پاک‌شده با اولین بازدید بعدی همان کاربر، با داده درست پر می‌شوند.</small>',
                'stats' => $stats,
            ],
        ]);
    } catch (Throwable $e) {
        json_response(['success' => false, 'error' => 'خطای بازحسابی: ' . $e->getMessage()], 500);
    }
}

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
        /* 🚨 v2.32 — پیشوند /16 قدیمی (a.b.0.0) هرگز بازحلابی نمی‌شود:
           آدرس پایه شبکه است و سرویس خارجی محل «ثبت ISP» را برمی‌گرداند */
        if (!GeoIP::isResolvablePrefix((string)($cr['ip_prefix'] ?? ''))) { continue; }
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

/* 🗺️ پراکندگی استانی — شهر خالی از ip_prefix با GeoIP بازیابی می‌شود
   🚨 v2.32 — پیشوندهای /16 قدیمی (a.b.0.0 = آدرس پایه شبکه) هرگز
   بازحلابی نمی‌شوند: سرویس خارجی برای آن‌ها محل «ثبت ISP» را برمی‌گرداند
   (ریشه «کاربر تبریز → خراسان رضوی»). */
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
        if (!GeoIP::isResolvablePrefix((string)($pr['ip_prefix'] ?? ''))) { continue; }
        $prov = GeoIP::province((string)($pr['ip_prefix'] ?? ''));
        if ($prov === '') { continue; }
    }
    $provincesDist[$prov] = ($provincesDist[$prov] ?? 0) + (int)$pr['c'];
}
arsort($provincesDist);

/* 🧭 v2.32 — کیفیت داده جغرافیایی (برای راهنمای دکمه بازحسابی) */
$geoQuality = $safeQuery(
    "SELECT COALESCE(v.geo_src, '') AS src, COUNT(DISTINCT v.session_hash) AS c
     FROM visits v WHERE {$where} GROUP BY src",
    $params
);
$geoSrcCounts = ['api' => 0, 'cache' => 0, 'local' => 0, '' => 0];
foreach ((array)$geoQuality as $gq) {
    $src = (string)($gq['src'] ?? '');
    if (!isset($geoSrcCounts[$src])) { $geoSrcCounts[$src] = 0; }
    $geoSrcCounts[$src] += (int)$gq['c'];
}
$geoUnknown = $geoSrcCounts['local'] + $geoSrcCounts[''];

/* ═══════════════════════════════════════════════════════════════
 * 🆕 v2.32 — هفت گزارش جدید (درخواست «انواع بیشتری از گزارش و آمار
 * همراه با نمودارهای زیبا») — همه با safeQuery مقاوم
 * ═══════════════════════════════════════════════════════════════ */

/* ① 📆 تقویم فعالیت ۱۲ هفته اخیر (مستقل از فیلتر — همیشه ۸۴ روز) */
$calendarRows = $safeQuery(
    "SELECT v.visit_date, COUNT(DISTINCT v.session_hash) AS c
     FROM visits v WHERE v.visit_date >= DATE_SUB(?, INTERVAL 83 DAY)
     GROUP BY v.visit_date ORDER BY v.visit_date",
    [date('Y-m-d')]
);
$calendarMap = [];
foreach ((array)$calendarRows as $cr2) { $calendarMap[(string)$cr2['visit_date']] = (int)$cr2['c']; }

/* ② 🎯 عمق گردش — توزیع تعداد صفحات دیده‌شده در هر بازدید */
$depthRows = $safeQuery(
    "SELECT t.pages AS p, COUNT(*) AS c FROM (
        SELECT vd.visit_id, COUNT(DISTINCT vd.page_url) AS pages
        FROM visit_details vd JOIN visits v ON v.id = vd.visit_id
        WHERE {$where} GROUP BY vd.visit_id
     ) t GROUP BY t.pages ORDER BY t.pages LIMIT 12",
    $params
);
$depthDist = [];
foreach ((array)$depthRows as $dr) { $depthDist[(int)$dr['p']] = (int)$dr['c']; }

/* ③ ⏳ سطوح تعامل — مدت حضور هر نشست در سطل‌ها */
$engageRows = $safeQuery(
    "SELECT t.avg_dur AS d, COUNT(*) AS c FROM (
        SELECT vd.visit_id, AVG(vd.duration) AS avg_dur
        FROM visit_details vd JOIN visits v ON v.id = vd.visit_id
        WHERE {$where} AND vd.duration > 0
        GROUP BY vd.visit_id
     ) t GROUP BY t.avg_dur LIMIT 400",
    $params
);
/* دسته‌بندی سطوح: پرش (<10ث) / کوتاه (10-60ث) / متوسط (1-5د) / عمیق (>5د) */
$engageLevels = ['پرش سریع' => 0, 'کوتاه' => 0, 'متوسط' => 0, 'عمیق' => 0];
foreach ((array)$engageRows as $er) {
    $dur = (float)$er['d']; $cnt = (int)$er['c'];
    if ($dur < 10) { $engageLevels['پرش سریع'] += $cnt; }
    elseif ($dur < 60) { $engageLevels['کوتاه'] += $cnt; }
    elseif ($dur < 300) { $engageLevels['متوسط'] += $cnt; }
    else { $engageLevels['عمیق'] += $cnt; }
}

/* ④ 📱 روند سهم دستگاه‌ها در روزهای بازه */
$deviceTrendRows = $safeQuery(
    "SELECT v.visit_date, v.device_type, COUNT(DISTINCT v.session_hash) AS c
     FROM visits v WHERE {$where} AND v.device_type IS NOT NULL
     GROUP BY v.visit_date, v.device_type ORDER BY v.visit_date",
    $params
);
$deviceTrend = ['dates' => [], 'mobile' => [], 'desktop' => [], 'tablet' => []];
foreach ((array)$deviceTrendRows as $dtr) {
    $dt = (string)$dtr['visit_date'];
    if (!in_array($dt, $deviceTrend['dates'], true)) { $deviceTrend['dates'][] = $dt; }
}
$deviceTrend['dates'] = array_values($deviceTrend['dates']);
foreach ($deviceTrend['dates'] as $dt) { $deviceTrend['mobile'][$dt] = 0; $deviceTrend['desktop'][$dt] = 0; $deviceTrend['tablet'][$dt] = 0; }
foreach ((array)$deviceTrendRows as $dtr) {
    $dt = (string)$dtr['visit_date'];
    $tp = (string)$dtr['device_type'];
    if (!isset($deviceTrend[$tp])) { continue; }
    $deviceTrend[$tp][$dt] += (int)$dtr['c'];
}

/* ⑤ 📊 نرخ پرش صفحات — ورود + فقط همان یک صفحه دیده شده */
$bounceRows = $safeQuery(
    "SELECT t.entry_page AS pg,
            COUNT(*) AS entries,
            SUM(t.pages = 1) AS bounces
     FROM (
        SELECT v.session_hash, v.entry_page, COUNT(DISTINCT vd.page_url) AS pages
        FROM visits v JOIN visit_details vd ON vd.visit_id = v.id
        WHERE {$where}
        GROUP BY v.session_hash, v.entry_page
     ) t
     GROUP BY t.entry_page ORDER BY entries DESC LIMIT 8",
    $params
);
$bouncePages = [];
foreach ((array)$bounceRows as $br) {
    $entries = max(1, (int)$br['entries']);
    $bouncePages[] = [
        'page' => (string)$br['pg'],
        'entries' => $entries,
        'bounces' => (int)$br['bounces'],
        'rate' => round((int)$br['bounces'] / $entries * 100),
    ];
}

/* ⑥ 🔮 داده پیش‌بینی — ۲۸ روز اخیر (مستقل از فیلتر) */
$forecastRows = $safeQuery(
    "SELECT v.visit_date, COUNT(DISTINCT v.session_hash) AS c
     FROM visits v WHERE v.visit_date >= DATE_SUB(?, INTERVAL 27 DAY)
     GROUP BY v.visit_date ORDER BY v.visit_date",
    [date('Y-m-d')]
);
$forecastSeries = [];
foreach ((array)$forecastRows as $fr) { $forecastSeries[] = [(string)$fr['visit_date'], (int)$fr['c']]; }


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
/* ⑦ 🕸 داده رادار سلامت — از سنجه‌های موجود محاسبه می‌شود (در JS) */
$avgDurationAll = $avgDuration;
$engageTotal = array_sum($engageLevels);
$engageGoodPct = $engageTotal > 0 ? round(($engageLevels['متوسط'] + $engageLevels['عمیق']) / $engageTotal * 100) : 0;
$returningPct = $totalUnique > 0 ? round($returningSessions / $totalUnique * 100) : 0;

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
/* 🛂 v2.34 — ACL: brand_manager فقط برندهای تخصیص‌یافته را می‌بیند */
$_aclIds = (new Auth())->accessibleBrandIds();
if ($_aclIds !== null) {
    $brands = array_values(array_filter($brands, function ($_b) use ($_aclIds) {
        return in_array((int)$_b['id'], $_aclIds, true);
    }));
}
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

/* ═══ 📥 v2.38 — خروجی CSV (اکسل-سازگار) ═══
   🚨 ریشه‌یابی: دکمه «خروجی CSV» در فرم فیلتر وجود داشت اما هیچ هندلری
   برای export=csv نوشته نشده بود — کلیک فقط صفحه را رفرش می‌کرد!
   اکنون: گزارش کاملِ همین فیلتر (بازه/برند) در یک CSV چندبخشی با BOM
   (باز شدن مستقیم در اکسل فارسی) دانلود می‌شود. */
if (get_param('export') === 'csv') {
    (new Auth())->requireLogin();
    $csv = [];
    $csv[] = ['گزارش آمار بازدید — سایت‌ساز برند سهند سرویس'];
    $csv[] = ['تاریخ تولید', jdate('Y/m/d H:i')];
    $csv[] = ['بازه', ($dateFrom !== '' ? $dateFrom : 'ابتدا') . ' تا ' . ($dateTo !== '' ? $dateTo : 'امروز')];
    $csv[] = ['برند', $brandFilter > 0 ? (string)($db->fetchValue('SELECT name_fa FROM brands WHERE id = ?', [$brandFilter]) ?: $brandFilter) : 'همه برندها'];
    $csv[] = ['بازدیدکننده یکتا', (string)$totalUnique];
    $csv[] = ['بازدید کل صفحات', (string)$totalViews];
    $csv[] = ['میانگین مدت حضور (ثانیه)', (string)$avgDuration];
    $csv[] = ['نرخ پرش (٪)', (string)$bounceRate];
    $csv[] = [];

    $csv[] = ['■ بازدید روزانه'];
    $csv[] = ['تاریخ', 'بازدیدکننده یکتا', 'بازدید صفحات'];
    foreach ((array)$timeline as $t) {
        $csv[] = [(string)$t['visit_date'], (string)$t['unique_visits'], (string)$t['views']];
    }
    $csv[] = [];

    $csv[] = ['■ تفکیک دستگاه'];
    $csv[] = ['دستگاه', 'بازدیدکننده یکتا'];
    foreach ((array)$byDevice as $d) {
        $csv[] = [(string)($deviceFa[$d['device_type']] ?? $d['device_type']), (string)$d['c']];
    }
    $csv[] = [];

    $csv[] = ['■ مرورگرها'];
    $csv[] = ['مرورگر', 'بازدیدکننده یکتا'];
    foreach ((array)$byBrowser as $b) {
        $csv[] = [(string)$b['browser'], (string)$b['c']];
    }
    $csv[] = [];

    $csv[] = ['■ سیستم‌عامل‌ها'];
    $csv[] = ['سیستم‌عامل', 'بازدیدکننده یکتا'];
    foreach ((array)$byOs as $o) {
        $csv[] = [(string)$o['os'], (string)$o['c']];
    }
    $csv[] = [];

    $csv[] = ['■ پراکندگی شهرها'];
    $csv[] = ['شهر', 'بازدیدکننده یکتا'];
    foreach ($citiesDist as $city => $c) {
        $csv[] = [(string)$city, (string)$c];
    }
    $csv[] = [];

    $csv[] = ['■ صفحات پربازدید'];
    $csv[] = ['صفحه', 'بازدید', 'میانگین زمان (ثانیه)'];
    foreach ((array)$topPages as $p) {
        $csv[] = [(string)$p['page_url'], (string)$p['views'], (string)round((float)$p['avg_duration'])];
    }
    $csv[] = [];

    $csv[] = ['■ سهم برندها'];
    $csv[] = ['برند', 'بازدیدکننده یکتا'];
    foreach ((array)$brandShare as $bs) {
        $csv[] = [(string)($bs['name_fa'] ?? ''), (string)($bs['visits'] ?? '')];
    }

    /* 📤 خروجی — BOM برای اکسل + escape استاندارد */
    $filename = 'analytics-' . date('Y-m-d-Hi') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); /* 🔤 BOM — نمایش درست فارسی در Excel */
    foreach ($csv as $row) {
        fputcsv($out, $row, ',', '"', '\\');
    }
    fclose($out);
    exit;
}
?>
<form method="get" class="card" style="padding:13px 18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
    <!-- 🗓️ v2.38 — چیپ‌های بازه سریع -->
    <div style="display:flex;gap:6px;flex-wrap:wrap" dir="rtl">
        <?php foreach (['1' => 'امروز', '7' => '۷ روز', '30' => '۳۰ روز', '90' => '۳ ماه'] as $qp => $ql): ?>
            <a href="?<?= $brandFilter ? 'brand=' . $brandFilter . '&amp;' : '' ?>period=<?= $qp ?>" class="an-period-chip <?= $period === $qp ? 'active' : '' ?>"><?= $ql ?></a>
        <?php endforeach; ?>
        <a href="?<?= $brandFilter ? 'brand=' . $brandFilter . '&amp;' : '' ?>period=all" class="an-period-chip <?= $period === 'all' ? 'active' : '' ?>">همه</a>
    </div>
    <select name="brand" class="form-control" style="max-width:180px">
        <option value="">🏷️ همه برندها</option>
        <?php foreach ($brands as $brand): ?>
            <option value="<?= (int)$brand['id'] ?>" <?= $brandFilter === (int)$brand['id'] ? 'selected' : '' ?>><?= e($brand['name_fa']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="period" class="form-control" style="max-width:140px" onchange="if(this.value!=='custom')this.form.submit()">
        <?php foreach (['1' => 'امروز', '7' => '۷ روز', '30' => '۳۰ روز', '90' => '۳ ماه', '365' => '۱ سال', 'all' => 'همه', 'custom' => 'بازه دلخواه'] as $p => $label): ?>
            <option value="<?= $p ?>" <?= $period === $p ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($period === 'custom'): ?>
        <input type="date" name="from" class="form-control" style="max-width:160px" value="<?= e($dateFrom) ?>">
        <input type="date" name="to" class="form-control" style="max-width:160px" value="<?= e($dateTo) ?>">
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">📊 اعمال</button>
    <a href="analytics-report.php?brand=<?= $brandFilter ?>&amp;period=<?= e($period) ?>&amp;from=<?= e($dateFrom) ?>&amp;to=<?= e($dateTo) ?>" target="_blank" class="btn btn-outline" title="نمای چاپی گزارش / ذخیره PDF">🖨️ گزارش PDF</a>
    <a href="?<?= $brandFilter ? 'brand=' . $brandFilter . '&' : '' ?>period=<?= e($period) ?>&<?= $dateFrom !== '' ? 'from=' . e($dateFrom) . '&' : '' ?><?= $dateTo !== '' ? 'to=' . e($dateTo) . '&' : '' ?>export=csv" class="btn btn-outline" style="margin-inline-start:auto" title="دانلود گزارش همین فیلتر در قالب CSV (باز شدن مستقیم در اکسل)">📥 خروجی CSV</a>
</form>

<style>
/* 🗓️ v2.38 — چیپ‌های بازه سریع آمار */
.an-period-chip{font-size:11.5px;font-weight:700;padding:5px 13px;border-radius:18px;border:1px solid var(--border);color:var(--text-light,#64748b);background:var(--card,#fff);text-decoration:none;transition:.14s;display:inline-block}
.an-period-chip:hover{border-color:#93c5fd;color:#2563eb}
.an-period-chip.active{background:linear-gradient(90deg,#2563eb,#0ea5e9);color:#fff;border-color:transparent;box-shadow:0 3px 9px rgba(37,99,235,.3)}
</style>

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
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <?php if (!empty($provincesDist)): ?>
                <span class="badge badge-info" style="font-size:11px">🎯 <?= en_to_fa_digits((string)count($provincesDist)) ?> استان</span>
            <?php endif; ?>
            <?php if ($geoUnknown > 0): ?>
                <span class="badge badge-warning" style="font-size:11px" title="این بازدیدها هنوز جواب سرویس جغرافیایی معتبر نگرفته‌اند و در نقشه «نامشخص»اند">❓ <?= en_to_fa_digits((string)$geoUnknown) ?> در انتظار مکان</span>
            <?php endif; ?>
            <!-- 🔄 v2.32 — بازحسابی جغرافیایی (رفع «تبریز → خراسان رضوی») -->
            <button type="button" class="btn btn-outline btn-sm" id="btn-geo-recompute" onclick="geoRecompute(this)" title="پاک‌سازی کش جغرافیایی + بازحلابی از سرویس‌های معتبر + حذف داده‌های حدسی قدیمی">🔄 بازحسابی جغرافیایی</button>
        </div>
    </div>
    <div class="card-body">
        <div id="geo-recompute-msg" style="display:none;margin-bottom:12px"></div>
        <?= Auth::csrfField() /* 🔄 v2.32 — توکن درخواست بازحسابی جغرافیایی */ ?>
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

<!-- ═══ 🆕 v2.32 — هفت گزارش جدید با نمودارهای زیبا ═══ -->

<!-- 📆 تقویم فعالیت ۱۲ هفته (سبک گیت‌هاب) -->
<div class="card" style="margin-bottom:18px">
    <div class="card-header">
        <h3>📆 تقویم فعالیت — ۱۲ هفته اخیر</h3>
        <span class="badge badge-info" style="font-size:11px">🔥 <?= en_to_fa_digits((string)array_sum($calendarMap)) ?> بازدید یکتا</span>
    </div>
    <div class="card-body">
        <?php $calMax = max($calendarMap ?: [1]) ?: 1; ?>
        <div id="activity-calendar" data-days='<?= json_encode($calendarMap, JSON_UNESCAPED_UNICODE) ?>' data-max="<?= (int)$calMax ?>"></div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;font-size:11px;color:var(--text-light)">
            <span>۱۲ هفته گذشته</span>
            <span style="display:flex;align-items:center;gap:6px">کمتر
                <?php foreach ([0.08, 0.3, 0.55, 0.8, 1] as $li): ?>
                    <span style="width:13px;height:13px;border-radius:3px;display:inline-block;background:rgb(<?= (int)round(226 + (30 - 226) * $li) ?>,<?= (int)round(232 + (64 - 232) * $li) ?>,<?= (int)round(240 + (175 - 240) * $li) ?>)"></span>
                <?php endforeach; ?>
            بیشتر</span>
            <span>امروز</span>
        </div>
    </div>
</div>

<!-- 🔮 پیش‌بینی + 📱 روند دستگاه‌ها -->
<div class="grid-2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(430px,1fr));gap:18px;margin-bottom:18px">
    <div class="card">
        <div class="card-header"><h3>🔮 پیش‌بینی روند بازدید — ۷ روز آینده</h3><span class="badge badge-warning" style="font-size:11px">رگرسیون خطی</span></div>
        <div class="card-body"><canvas id="forecast-chart" height="230"></canvas>
            <div class="hint" style="margin-top:7px;font-size:11px">خط‌چین = برآورد روند بر پایه ۲۸ روز اخیر — راهنمای تصمیم برای کمپین‌ها.</div></div>
    </div>
    <div class="card">
        <div class="card-header"><h3>📱 روند سهم دستگاه‌ها در بازه</h3></div>
        <div class="card-body"><canvas id="devicetrend-chart" height="230"></canvas>
            <div style="display:flex;gap:14px;margin-top:8px;font-size:11.5px">
                <span style="display:flex;align-items:center;gap:5px"><span style="width:11px;height:11px;border-radius:3px;background:#2563eb"></span>📱 موبایل</span>
                <span style="display:flex;align-items:center;gap:5px"><span style="width:11px;height:11px;border-radius:3px;background:#059669"></span>🖥️ دسکتاپ</span>
                <span style="display:flex;align-items:center;gap:5px"><span style="width:11px;height:11px;border-radius:3px;background:#d97706"></span>📲 تبلت</span>
            </div></div>
    </div>
</div>

<!-- ⏳ تعامل + 🎯 عمق گردش -->
<div class="grid-2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(430px,1fr));gap:18px;margin-bottom:18px">
    <div class="card">
        <div class="card-header"><h3>⏳ سطوح تعامل کاربران</h3>
            <?php if ($engageTotal > 0): ?><span class="badge badge-success" style="font-size:11px">💚 <?= en_to_fa_digits((string)$engageGoodPct) ?>٪ تعامل خوب</span><?php endif; ?>
        </div>
        <div class="card-body"><canvas id="engage-chart" height="230"></canvas></div>
    </div>
    <div class="card">
        <div class="card-header"><h3>🎯 عمق گردش — صفحات در هر بازدید</h3>
            <span class="badge badge-info" style="font-size:11px">میانگین: <?= en_to_fa_digits((string)($pagesPerVisit ?: 0)) ?></span>
        </div>
        <div class="card-body"><canvas id="depth-chart" height="230"></canvas></div>
    </div>
</div>

<!-- 🕸 رادار سلامت + 📊 نرخ پرش -->
<div class="grid-2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(430px,1fr));gap:18px;margin-bottom:18px">
    <div class="card">
        <div class="card-header"><h3>🕸 رادار سلامت سایت</h3></div>
        <div class="card-body"><canvas id="radar-chart" height="260"></canvas>
            <div class="hint" style="margin-top:6px;font-size:11px">پنج محور نرمال‌شده — هر چه چندضلعی بزرگ‌تر، وضعیت کلی بهتر.</div></div>
    </div>
    <div class="card">
        <div class="card-header"><h3>📊 نرخ پرش صفحات پرورود</h3></div>
        <div class="card-body">
            <?php if (empty($bouncePages)): ?>
                <div class="empty-state" style="padding:16px"><div class="icon">📊</div><p>داده ورود صفحه‌ای ثبت نشده است.</p></div>
            <?php else: ?>
                <canvas id="bouncerate-chart" height="<?= max(150, count($bouncePages) * 38) ?>"></canvas>
            <?php endif; ?>
        </div>
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
<?php
/* 📦 P2-23 — داده‌های نمودارها: همان ۳۷ عبارت PHP که قبلاً درون‌خطی در JS بودند */
$_ad = [
    array_map(fn($r) => ['date' => $r['visit_date'], 'u' => (int)$r['unique_visits'], 'v' => (int)$r['views']], $timeline),
    $hourMap,
    array_map(static fn($h) => strtr(sprintf('%02d', $h), ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']), range(0, 23)),
    $weekdayMap,
    $weekdayFa,
    array_map(static fn($v) => (int)$v, array_values($refGroups)),
    array_keys($refGroups),
    (int)$newVisitors,
    (int)$returningSessions,
    array_map(fn($r) => ['label' => $r['name_fa'], 'value' => (int)$r['visits']], $brandShare),
    $heatMap,
    array_map(fn($r) => ['label' => $countryFa[$r['cc']] ?? $r['cc'], 'value' => (int)$r['c']], $byCountry),
    (int)$totalUnique,
    (int)$totalViews,
    (int)$prevUnique,
    (int)$prevViews,
    array_map(fn($r) => ['date' => $r['visit_date'], 'v' => (int)$r['avg_dur']], $durationTrend),
    array_map(fn($r) => ['l' => mb_substr((string)$r['entry_page'], 0, 26), 'v' => (int)$r['c']], $entryPages),
    array_map(fn($r) => ['l' => mb_substr((string)$r['page_url'], 0, 26), 'v' => (int)$r['exits']], $exitPages),
    array_map(fn($w) => ['label' => jdate_short($w['start']), 'u' => $w['u'], 'views' => $w['views']], $weeklyGrowth),
    array_map(fn($a) => ['l' => mb_substr((string)preg_replace('#^.*/blog/#', '', $a['page_url']), 0, 30), 'v' => (int)$a['views'], 'dur' => (int)round((float)$a['avg_dur'])], $articleRows),
    $brandFilter > 0 ? '&brand=' . (int)$brandFilter : '',
    $forecastSeries,
    ['dates' => $deviceTrend['dates'], 'mobile' => array_values($deviceTrend['mobile']), 'desktop' => array_values($deviceTrend['desktop']), 'tablet' => array_values($deviceTrend['tablet'])],
    $engageLevels,
    array_values($depthDist),
    array_map(static fn($p) => $p == 8 ? '۸+' : en_to_fa_digits((string)$p), array_keys($depthDist)),
    (int)$avgDurationAll,
    (float)$pagesPerVisit,
    (int)$returningPct,
    (int)$engageGoodPct,
    en_to_fa_digits((string)$totalUnique),
    en_to_fa_digits((string)$avgDurationAll),
    en_to_fa_digits((string)($pagesPerVisit ?: 0)),
    en_to_fa_digits((string)$returningPct),
    en_to_fa_digits((string)$engageGoodPct),
    $bouncePages,
];
?>
<script>
/* 📊 آرایه داده‌های آمار (مقادیر سرور — بوت‌استرپ JS خارجی) */
const AD = <?= json_encode($_ad, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="../assets/js/analytics.js?v=2.36"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
