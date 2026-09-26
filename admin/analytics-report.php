<?php
/**
 * 📊 گزارش چاپی آمار — نمای قابل چاپ / ذخیره PDF
 * ================================================
 * صفحه مستقل بدون قالب پنل — همان فیلترهای analytics.php را می‌پذیرد
 * (brand / period / from / to) و گزارش کامل جدولی چاپی تولید می‌کند.
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

/* 🔐 احراز هویت — صفحه مستقل است */
(new Auth())->requireLogin();

$db = Database::getInstance();

/* 🎛️ فیلترها — همان analytics.php */
$brandFilter = (int)get_param('brand');
$dateFrom = get_param('from');
$dateTo = get_param('to');
$period = get_param('period', '30');
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

/* 🏷️ برند انتخابی */
$brandRow = $brandFilter > 0 ? $db->fetch('SELECT name_fa, name_en, domain FROM brands WHERE id = ?', [$brandFilter]) : null;

/* 🛡️ v2.27 — کوئری‌های آمار در برابر دیتابیس قدیمی مقاوم: اگر جدول/ستون
   غایب باشد، همان بخش خالی برمی‌گردد و صفحه با ۵۰۰ نمی‌افتد. */
$safeQuery = static function (string $sql, array $params) use ($db): array {
    try {
        return $db->fetchAll($sql, $params);
    } catch (Throwable $sqlE) {
        return [];
    }
};

/* 📈 سری زمانی */
$timeline = $safeQuery(
    "SELECT v.visit_date, COUNT(DISTINCT v.session_hash) AS unique_visits, COUNT(*) AS views
     FROM visits v WHERE {$where} GROUP BY v.visit_date ORDER BY v.visit_date",
    $params
);

/* 📱 تفکیک‌ها */
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
     WHERE {$where} GROUP BY vd.page_url ORDER BY views DESC LIMIT 12",
    $params
);

/* 🏷️ سهم برندها (فقط در حالت «همه برندها») */
$brandShare = $brandFilter === 0 ? $safeQuery(
    "SELECT b.name_fa, COUNT(DISTINCT v.session_hash) AS visits
     FROM brands b JOIN visits v ON v.brand_id = b.id
     WHERE b.is_active = 1 GROUP BY b.id HAVING visits > 0 ORDER BY visits DESC LIMIT 12",
    []
) : [];

/* 🆕 v2.29 — گزارش‌های جدید برای نسخه چاپی */
$byHour = $safeQuery("SELECT HOUR(v.visited_at) AS h, COUNT(DISTINCT v.session_hash) AS c FROM visits v WHERE {$where} GROUP BY h ORDER BY h", $params);
$hourMap = array_fill(0, 24, 0);
foreach ($byHour as $hr) { $hourMap[(int)$hr['h']] = (int)$hr['c']; }
$bestHour = array_keys($hourMap, max($hourMap))[0] ?? null;

$byWeekday = $safeQuery("SELECT ((WEEKDAY(v.visit_date) + 2) % 7) AS wd, COUNT(DISTINCT v.session_hash) AS c FROM visits v WHERE {$where} GROUP BY wd ORDER BY wd", $params);
$weekdayFa = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
$weekdayMap = array_fill(0, 7, 0);
foreach ($byWeekday as $wd) { $weekdayMap[(int)$wd['wd']] = (int)$wd['c']; }

$refRows = $safeQuery("SELECT v.referrer, COUNT(DISTINCT v.session_hash) AS c FROM visits v WHERE {$where} AND v.referrer IS NOT NULL GROUP BY v.referrer ORDER BY c DESC LIMIT 400", $params);
$refGroups = ['مستقیم' => 0, 'موتور جستجو' => 0, 'شبکه اجتماعی' => 0, 'سایت‌های ارجاع‌دهنده' => 0];
foreach ((array)$refRows as $rr) {
    $host = strtolower((string)parse_url((string)$rr['referrer'], PHP_URL_HOST) ?: '');
    if ($host === '') { $refGroups['مستقیم'] += (int)$rr['c']; }
    elseif (preg_match('#google|bing|yahoo|duckduckgo|yandex#', $host)) { $refGroups['موتور جستجو'] += (int)$rr['c']; }
    elseif (preg_match('#instagram|telegram|whatsapp|facebook|twitter|linkedin|aparat|youtube|bale|eitaa|rubika#', $host)) { $refGroups['شبکه اجتماعی'] += (int)$rr['c']; }
    else { $refGroups['سایت‌های ارجاع‌دهنده'] += (int)$rr['c']; }
}

$retRow = $safeQuery(
    "SELECT COUNT(*) AS returning_sessions FROM (
        SELECT v.session_hash FROM visits v WHERE {$where} GROUP BY v.session_hash HAVING COUNT(DISTINCT v.visit_date) > 1
     ) t",
    $params
);
$returningSessions = $retRow ? (int)$retRow[0]['returning_sessions'] : 0;

$topKeywords = $safeQuery("SELECT v.search_keyword AS kw, COUNT(DISTINCT v.session_hash) AS c FROM visits v WHERE {$where} AND v.search_keyword IS NOT NULL AND v.search_keyword != '' GROUP BY kw ORDER BY c DESC LIMIT 10", $params);

/* ═══════════════════════════════════════════════════════════════
 * 🆕 v2.30 — داده گزارش‌های جدید برای نسخه چاپی
 * ═══════════════════════════════════════════════════════════════ */

/* 🌍 پراکندگی کشورها */
$byCountry = $safeQuery(
    "SELECT COALESCE(NULLIF(v.country, ''), 'IR') AS cc, COUNT(DISTINCT v.session_hash) AS c
     FROM visits v WHERE {$where} GROUP BY cc ORDER BY c DESC LIMIT 8",
    $params
);
$countryFa = [
    'IR' => 'ایران', 'TR' => 'ترکیه', 'AE' => 'امارات', 'DE' => 'آلمان',
    'US' => 'آمریکا', 'NL' => 'هلند', 'GB' => 'انگلیس', 'CA' => 'کانادا',
    'AF' => 'افغانستان', 'IQ' => 'عراق', 'RU' => 'روسیه', 'FR' => 'فرانسه',
];

/* 🔥 نقشه حرارتی ساعت × روز هفته */
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
/* 🚨 v2.30 — تخت‌سازی صحیح: array_merge(...$heatMap) آرایه دوبعدی می‌سازد و
   max() روی آن آرایه برمی‌گرداند → تقسیم بر آرایه = Exception وسط رندر! */
$heatMax = 1;
$heatSum = 0;
foreach ($heatMap as $hours) {
    foreach ($hours as $c) { $heatSum += $c; if ($c > $heatMax) { $heatMax = $c; } }
}

/* ⏱ روند میانگین مدت حضور روزانه */
$durationTrend = $safeQuery(
    "SELECT v.visit_date, ROUND(AVG(vd.duration)) AS avg_dur
     FROM visit_details vd JOIN visits v ON v.id = vd.visit_id
     WHERE {$where} AND vd.duration > 0 GROUP BY v.visit_date ORDER BY v.visit_date LIMIT 30",
    $params
);

/* 🚪 صفحات ورود و خروج */
$entryPages = $safeQuery(
    "SELECT v.entry_page, COUNT(DISTINCT v.session_hash) AS c
     FROM visits v WHERE {$where} AND v.entry_page IS NOT NULL AND v.entry_page != ''
     GROUP BY v.entry_page ORDER BY c DESC LIMIT 8",
    $params
);
$exitPages = $safeQuery(
    "SELECT vd.page_url, COUNT(*) AS exits FROM visit_details vd JOIN visits v ON v.id = vd.visit_id
     WHERE {$where} AND vd.is_exit = 1 GROUP BY vd.page_url ORDER BY exits DESC LIMIT 8",
    $params
);

/* 📊 مقایسه با دوره قبل */
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

/* 📈 شاخص‌های کلی */
$totalUnique = array_sum(array_column($timeline, 'unique_visits'));
$newVisitors = max(0, $totalUnique - $returningSessions);
$totalViews = array_sum(array_column($timeline, 'views'));
$durRow = $safeQuery("SELECT AVG(vd.duration) AS avg_dur FROM visit_details vd JOIN visits v ON v.id = vd.visit_id WHERE {$where}", $params);
$avgDuration = $durRow ? round((float)$durRow[0]['avg_dur']) : 0;
$bounceRow = $safeQuery(
    "SELECT COUNT(DISTINCT v.session_hash) AS sessions,
            COUNT(DISTINCT CASE WHEN exits.exit_count = 1 AND views.vc = 1 THEN v.session_hash END) AS bounced
     FROM visits v
     LEFT JOIN (SELECT visit_id, COUNT(*) AS exit_count FROM visit_details WHERE is_exit = 1 GROUP BY visit_id) exits ON exits.visit_id = v.id
     LEFT JOIN (SELECT visit_id, COUNT(*) AS vc FROM visit_details GROUP BY visit_id) views ON views.visit_id = v.id
     WHERE {$where}",
    $params
);
$bounceRate = $bounceRow && (int)$bounceRow[0]['sessions'] > 0 ? round((int)$bounceRow[0]['bounced'] / (int)$bounceRow[0]['sessions'] * 100) : 0;

/* 🗓️ برچسب بازه */
$periodLabels = ['7' => '۷ روز اخیر', '30' => '۳۰ روز اخیر', '90' => '۳ ماه اخیر', '365' => '۱ سال اخیر', 'all' => 'همه زمان‌ها', 'custom' => 'بازه دلخواه'];
$periodLabel = $periodLabels[$period] ?? $periodLabel ?? '۳۰ روز اخیر';
if ($period === 'custom' && ($dateFrom || $dateTo)) {
    $periodLabel = 'از ' . jdate($dateFrom ?: date('Y-m-d')) . ' تا ' . jdate($dateTo ?: date('Y-m-d'));
}

$deviceFa = ['mobile' => 'موبایل', 'desktop' => 'دسکتاپ', 'tablet' => 'تبلت', 'bot' => 'ربات'];

/* 📊 داده روزهای اخیر برای جدول (حداکثر ۳۵ سطر) */
$recentDays = array_slice(array_reverse($timeline), 0, 35);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>گزارش آماری <?= $brandRow ? e($brandRow['name_fa']) : 'همه برندها' ?> — <?= e($periodLabel) ?></title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Vazirmatn, Tahoma, Arial, sans-serif; background: #eceff3; color: #1f2937; font-size: 12.5px; line-height: 1.9; padding: 24px; }
    .sheet { max-width: 880px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 34px 38px; box-shadow: 0 4px 24px rgba(16,42,80,.10); }

    header.rep { display: flex; align-items: center; gap: 14px; border-bottom: 3px solid #1d5ba6; padding-bottom: 14px; }
    header.rep .t { flex: 1; }
    header.rep h1 { font-size: 18px; color: #16437e; }
    header.rep .sub { font-size: 11.5px; color: #64748b; }
    .rep-meta { text-align: left; font-size: 11.5px; color: #475569; }

    h2.sec { font-size: 14px; color: #0f3a6d; margin: 24px 0 10px; padding-inline-start: 10px; border-inline-start: 4px solid #2f7bd0; }

    /* 🃏 کارت شاخص‌ها */
    .kpi { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-top: 18px; }
    .kpi .k { border: 1.5px solid #cbd5e1; border-radius: 11px; padding: 13px 12px; text-align: center; }
    .kpi .k b { display: block; font-size: 19px; color: #16437e; }
    .kpi .k span { font-size: 11px; color: #64748b; }

    table.dt { width: 100%; border-collapse: collapse; }
    table.dt th, table.dt td { border: 1px solid #cbd5e1; padding: 7px 10px; text-align: right; }
    table.dt th { background: #f1f5f9; color: #334155; font-size: 11.5px; }
    table.dt td.n { text-align: center; direction: ltr; font-variant-numeric: tabular-nums; }
    table.dt tr:nth-child(even) td { background: #f8fafc; }

    .bar-cell { background: #e2e8f0; border-radius: 5px; height: 10px; position: relative; min-width: 90px; }
    .bar-cell i { position: absolute; inset-inline-start: 0; top: 0; bottom: 0; background: #2f7bd0; border-radius: 5px; }

    footer.rep { margin-top: 26px; padding-top: 12px; border-top: 1px solid #cbd5e1; display: flex; justify-content: space-between; font-size: 10.5px; color: #64748b; }

    .print-bar { max-width: 880px; margin: 0 auto 14px; display: flex; gap: 10px; justify-content: flex-end; }
    .print-bar button, .print-bar a { font-family: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; border: none; border-radius: 9px; padding: 9px 20px; text-decoration: none; }
    .btn-print { background: #1d5ba6; color: #fff; }
    .btn-back { background: #fff; color: #334155; border: 1.5px solid #cbd5e1 !important; }

    @media print {
        body { background: #fff; padding: 0; font-size: 11.5px; }
        .sheet { box-shadow: none; border-radius: 0; max-width: 100%; padding: 8px 4px; }
        .print-bar { display: none; }
        h2.sec { break-after: avoid; }
        table.dt { break-inside: auto; }
        table.dt tr { break-inside: avoid; }
    }
    @page { size: A4; margin: 12mm; }
</style>
</head>
<body>

<div class="print-bar">
    <a class="btn-back" href="analytics.php?brand=<?= $brandFilter ?>&amp;period=<?= e($period) ?>&amp;from=<?= e($dateFrom) ?>&amp;to=<?= e($dateTo) ?>">↩ بازگشت به داشبورد</a>
    <button class="btn-print" onclick="window.print()">🖨️ چاپ / ذخیره PDF</button>
</div>

<div class="sheet">
    <!-- 🔝 سربرگ -->
    <header class="rep">
        <div class="t">
            <h1>📊 گزارش آماری — <?= $brandRow ? e($brandRow['name_fa']) . ' (' . e($brandRow['name_en']) . ')' : 'همه سایت‌های برند' ?></h1>
            <div class="sub"><?= e(Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس') ?> — <?= e($periodLabel) ?><?= $brandRow ? ' | 🌐 ' . e($brandRow['domain']) : '' ?></div>
        </div>
        <div class="rep-meta">
            تاریخ گزارش: <b><?= e(jdate(date('Y-m-d'), true)) ?></b>
        </div>
    </header>

    <!-- 📈 شاخص‌های کلی -->
    <div class="kpi">
        <div class="k"><b><?= e(en_to_fa_digits((string)$totalUnique)) ?></b><span>بازدیدکننده یکتا</span></div>
        <div class="k"><b><?= e(en_to_fa_digits((string)$totalViews)) ?></b><span>بازدید کل صفحات</span></div>
        <div class="k"><b><?= e(en_to_fa_digits((string)floor($avgDuration / 60))) ?>:<?= str_pad(en_to_fa_digits((string)($avgDuration % 60)), 2, '۰', STR_PAD_LEFT) ?></b><span>میانگین مدت حضور</span></div>
        <div class="k"><b><?= e(en_to_fa_digits((string)$bounceRate)) ?>٪</b><span>نرخ پرش</span></div>
    </div>

    <!-- 📅 روند روزانه -->
    <h2 class="sec">📅 روند بازدید روزانه (<?= count($recentDays) ?> روز اخیر ثبت‌شده)</h2>
    <?php if (empty($recentDays)): ?>
        <p style="color:#94a3b8">در این بازه بازدیدی ثبت نشده است.</p>
    <?php else: ?>
    <table class="dt">
        <thead><tr><th style="width:130px">تاریخ</th><th>بازدیدکننده یکتا</th><th>بازدید صفحات</th><th style="width:220px">سهم</th></tr></thead>
        <tbody>
        <?php $maxDayViews = max(array_column($recentDays, 'views')) ?: 1; ?>
        <?php foreach ($recentDays as $day): ?>
            <tr>
                <td><?= e(jdate($day['visit_date'])) ?></td>
                <td class="n"><?= e(en_to_fa_digits((string)$day['unique_visits'])) ?></td>
                <td class="n"><?= e(en_to_fa_digits((string)$day['views'])) ?></td>
                <td><div class="bar-cell"><i style="width:<?= max(2, round($day['views'] / $maxDayViews * 100)) ?>%"></i></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- 📱 تفکیک دستگاه/مرورگر/سیستم‌عامل -->
    <h2 class="sec">📱 تفکیک بازدیدکنندگان</h2>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px">
        <div>
            <b style="font-size:12px;color:#334155">دستگاه</b>
            <table class="dt" style="margin-top:6px">
                <thead><tr><th>نوع</th><th>تعداد</th></tr></thead>
                <tbody>
                <?php foreach ($byDevice as $d): ?>
                    <tr><td><?= e($deviceFa[$d['device_type']] ?? $d['device_type']) ?></td><td class="n"><?= e(en_to_fa_digits((string)$d['c'])) ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($byDevice)): ?><tr><td colspan="2" style="color:#94a3b8">—</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div>
            <b style="font-size:12px;color:#334155">مرورگر</b>
            <table class="dt" style="margin-top:6px">
                <thead><tr><th>نام</th><th>تعداد</th></tr></thead>
                <tbody>
                <?php foreach ($byBrowser as $d): ?>
                    <tr><td style="direction:ltr;text-align:right"><?= e($d['browser']) ?></td><td class="n"><?= e(en_to_fa_digits((string)$d['c'])) ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($byBrowser)): ?><tr><td colspan="2" style="color:#94a3b8">—</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div>
            <b style="font-size:12px;color:#334155">سیستم‌عامل</b>
            <table class="dt" style="margin-top:6px">
                <thead><tr><th>نام</th><th>تعداد</th></tr></thead>
                <tbody>
                <?php foreach ($byOs as $d): ?>
                    <tr><td style="direction:ltr;text-align:right"><?= e($d['os']) ?></td><td class="n"><?= e(en_to_fa_digits((string)$d['c'])) ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($byOs)): ?><tr><td colspan="2" style="color:#94a3b8">—</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 🆕 v2.29 — ساعت/روز پربازدید + منابع + جدید/بازگشتی -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div>
            <h2 class="sec">🕐 پربازدیدترین ساعت‌ها</h2>
            <?php $maxHour = max($hourMap) ?: 1; ?>
            <table class="dt">
                <thead><tr><th>ساعت</th><th>بازدیدکننده</th><th style="width:110px">سهم</th></tr></thead>
                <tbody>
                <?php arsort($hourMap); $hi = 0; foreach (array_slice($hourMap, 0, 8, true) as $h => $c): if ($c == 0) continue; $hi++; ?>
                    <tr><td class="n"><?= e(en_to_fa_digits(str_pad((string)$h, 2, '۰', STR_PAD_LEFT)) . ':۰۰') ?></td><td class="n"><?= e(en_to_fa_digits((string)$c)) ?></td><td><div class="bar-cell"><i style="width:<?= max(2, round($c / $maxHour * 100)) ?>%"></i></div></td></tr>
                <?php endforeach; ?>
                <?php if ($hi === 0): ?><tr><td colspan="3" style="color:#94a3b8">—</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div>
            <h2 class="sec">📅 بازدید بر اساس روز هفته</h2>
            <?php $maxWd = max($weekdayMap) ?: 1; ?>
            <table class="dt">
                <thead><tr><th>روز</th><th>بازدیدکننده</th><th style="width:110px">سهم</th></tr></thead>
                <tbody>
                <?php foreach ($weekdayFa as $wi => $wname): if ($weekdayMap[$wi] == 0) continue; ?>
                    <tr><td><?= e($wname) ?></td><td class="n"><?= e(en_to_fa_digits((string)$weekdayMap[$wi])) ?></td><td><div class="bar-cell"><i style="width:<?= max(2, round($weekdayMap[$wi] / $maxWd * 100)) ?>%"></i></div></td></tr>
                <?php endforeach; ?>
                <?php if (array_sum($weekdayMap) === 0): ?><tr><td colspan="3" style="color:#94a3b8">—</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div>
            <h2 class="sec">🔗 منابع ورود ترافیک</h2>
            <?php $refTotal = max(1, array_sum($refGroups)); ?>
            <table class="dt">
                <thead><tr><th>منبع</th><th>بازدیدکننده</th><th style="width:110px">سهم</th></tr></thead>
                <tbody>
                <?php foreach ($refGroups as $rg => $rc): ?>
                    <tr><td><?= e($rg) ?></td><td class="n"><?= e(en_to_fa_digits((string)$rc)) ?></td><td><div class="bar-cell"><i style="width:<?= max(2, round($rc / $refTotal * 100)) ?>%"></i></div></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div>
            <h2 class="sec">🔄 جدید و بازگشتی</h2>
            <table class="dt">
                <thead><tr><th>نوع</th><th>تعداد</th><th style="width:110px">سهم</th></tr></thead>
                <tbody>
                <?php $nrTotal = max(1, $newVisitors + $returningSessions); ?>
                <tr><td>🆕 بازدیدکننده جدید</td><td class="n"><?= e(en_to_fa_digits((string)$newVisitors)) ?></td><td><div class="bar-cell"><i style="width:<?= max(2, round($newVisitors / $nrTotal * 100)) ?>%"></i></div></td></tr>
                <tr><td>🔁 بازگشتی (بیش از یک روز)</td><td class="n"><?= e(en_to_fa_digits((string)$returningSessions)) ?></td><td><div class="bar-cell"><i style="width:<?= max(2, round($returningSessions / $nrTotal * 100)) ?>%"></i></div></td></tr>
                </tbody>
            </table>
            <?php if (!empty($topKeywords)): ?>
            <h2 class="sec" style="margin-top:16px">🔎 کلمات کلیدی ورودی</h2>
            <table class="dt">
                <tbody>
                <?php foreach ($topKeywords as $kw): ?>
                    <tr><td><?= e(mb_substr((string)$kw['kw'], 0, 50)) ?></td><td class="n"><?= e(en_to_fa_digits((string)$kw['c'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- 🗺️ استان‌ها و شهرها -->
    <h2 class="sec">🗺️ پراکندگی جغرافیایی بازدیدکنندگان — استان‌ها</h2>
    <?php
    /* v2.31 — تجمیع استانی همان کوئری شهرها */
    $provincesDist = [];
    try {
        $provRowsR = $safeQuery(
            "SELECT COALESCE(NULLIF(v.province, ''), '') AS prov, v.ip_prefix, COUNT(DISTINCT v.session_hash) AS c
             FROM visits v WHERE {$where} AND (v.city IS NOT NULL AND v.city != '' OR (v.ip_prefix IS NOT NULL AND v.ip_prefix != ''))
             GROUP BY prov, v.ip_prefix",
            $params
        );
        foreach ((array)$provRowsR as $pr) {
            $prov = trim((string)$pr['prov']);
            if ($prov === '') {
                $prov = GeoIP::province((string)($pr['ip_prefix'] ?? ''));
                if ($prov === '') { continue; }
            }
            $provincesDist[$prov] = ($provincesDist[$prov] ?? 0) + (int)$pr['c'];
        }
        arsort($provincesDist);
    } catch (Throwable $geoE) { $provincesDist = []; }
    ?>
    <?php if (empty($provincesDist) && empty($citiesDist)): ?>
        <p style="color:#94a3b8">داده جغرافیایی ثبت نشده است.</p>
    <?php else: ?>
    <?php if (!empty($provincesDist)): ?>
    <table class="dt">
        <thead><tr><th>استان</th><th>بازدیدکننده</th><th style="width:220px">سهم</th></tr></thead>
        <tbody>
        <?php $maxProvR = max($provincesDist) ?: 1; ?>
        <?php foreach (array_slice($provincesDist, 0, 16, true) as $provName => $count): ?>
            <tr>
                <td><?= e($provName) ?></td>
                <td class="n"><?= e(en_to_fa_digits((string)$count)) ?></td>
                <td><div class="bar-cell"><i style="width:<?= max(2, round($count / $maxProvR * 100)) ?>%"></i></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <?php if (!empty($citiesDist)): ?>
    <h2 class="sec">🏙️ شهرها</h2>
    <table class="dt">
        <thead><tr><th>شهر</th><th>بازدیدکننده</th><th style="width:220px">سهم</th></tr></thead>
        <tbody>
        <?php $maxCity = max($citiesDist) ?: 1; ?>
        <?php foreach (array_slice($citiesDist, 0, 14, true) as $city => $count): ?>
            <tr>
                <td><?= e($city) ?></td>
                <td class="n"><?= e(en_to_fa_digits((string)$count)) ?></td>
                <td><div class="bar-cell"><i style="width:<?= max(2, round($count / $maxCity * 100)) ?>%"></i></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <?php endif; ?>

    <!-- 📄 صفحات پربازدید -->
    <h2 class="sec">📄 صفحات پربازدید</h2>
    <?php if (empty($topPages)): ?>
        <p style="color:#94a3b8">داده‌ای ثبت نشده است.</p>
    <?php else: ?>
    <table class="dt">
        <thead><tr><th>#</th><th>صفحه</th><th>بازدید</th><th>میانگین زمان (ثانیه)</th></tr></thead>
        <tbody>
        <?php foreach ($topPages as $i => $p): ?>
            <tr>
                <td class="n"><?= e(en_to_fa_digits((string)($i + 1))) ?></td>
                <td style="direction:ltr;text-align:right;font-size:11.5px"><?= e(mb_substr($p['page_url'], 0, 70)) ?></td>
                <td class="n"><?= e(en_to_fa_digits((string)$p['views'])) ?></td>
                <td class="n"><?= e(en_to_fa_digits((string)round((float)$p['avg_duration']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- 🏷️ سهم برندها (بدون فیلتر برند) -->
    <?php if (!empty($brandShare)): ?>
    <h2 class="sec">🏷️ سهم برندها از بازدید</h2>
    <table class="dt">
        <thead><tr><th>#</th><th>برند</th><th>بازدیدکننده یکتا</th><th style="width:220px">سهم</th></tr></thead>
        <tbody>
        <?php $maxShare = max(array_column($brandShare, 'visits')) ?: 1; ?>
        <?php foreach ($brandShare as $i => $b): ?>
            <tr>
                <td class="n"><?= e(en_to_fa_digits((string)($i + 1))) ?></td>
                <td><?= e($b['name_fa']) ?></td>
                <td class="n"><?= e(en_to_fa_digits((string)$b['visits'])) ?></td>
                <td><div class="bar-cell"><i style="width:<?= max(2, round($b['visits'] / $maxShare * 100)) ?>%"></i></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php /* ═══════════ 🆕 v2.30 — بخش‌های جدید گزارش چاپی ═══════════ */ ?>

    <?php if (!empty($byCountry)): ?>
    <h2 class="sec">🌍 پراکندگی کشورهای بازدیدکنندگان</h2>
    <table class="dt">
        <thead><tr><th>کشور</th><th>بازدیدکننده یکتا</th><th style="width:220px">سهم</th></tr></thead>
        <tbody>
        <?php $maxC = max(array_column($byCountry, 'c')) ?: 1; $sumC = array_sum(array_column($byCountry, 'c')) ?: 1; ?>
        <?php foreach ($byCountry as $cRow): ?>
            <tr>
                <td><?= e($countryFa[$cRow['cc']] ?? $cRow['cc']) ?></td>
                <td class="n"><?= e(en_to_fa_digits((string)$cRow['c'])) ?> (<?= e(en_to_fa_digits((string)round($cRow['c'] / $sumC * 100))) ?>٪)</td>
                <td><div class="bar-cell"><i style="width:<?= max(2, round($cRow['c'] / $maxC * 100)) ?>%"></i></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($heatMax > 1 || $heatSum > 0): ?>
    <h2 class="sec">🔥 نقشه حرارتی بازدید — ساعت × روز هفته</h2>
    <table class="dt heat">
        <thead><tr><th>روز</th><?php foreach (range(0, 23) as $h): if ($h % 3 !== 0 && $h !== 23) continue; ?><th class="n"><?= e(en_to_fa_digits(sprintf('%02d', $h))) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($heatMap as $wd => $hours): ?>
            <tr>
                <th><?= e($weekdayFa[$wd]) ?></th>
                <?php foreach ($hours as $h => $c): if ($h % 3 !== 0 && $h !== 23) continue; ?>
                    <?php $t = $c > 0 ? 0.12 + 0.88 * pow($c / $heatMax, 0.65) : 0; ?>
                    <td class="n hc" <?= $t > 0 ? 'style="background:rgba(29,91,166,' . round($t, 2) . ');color:' . ($t > 0.5 ? '#fff' : '#334155') . '"' : '' ?>><?= $c > 0 ? e(en_to_fa_digits((string)$c)) : '·' ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div style="font-size:10px;color:#64748b;margin-top:5px">ساعت‌ها هر ۳ ساعت نمایش داده می‌شوند — اعداد: بازدیدکننده یکتا</div>
    <?php endif; ?>

    <?php if (!empty($durationTrend)): ?>
    <h2 class="sec">⏱ روند میانگین مدت حضور روزانه</h2>
    <table class="dt">
        <thead><tr><th>تاریخ</th><th>میانگین مدت حضور (ثانیه)</th><th style="width:220px">نسبت</th></tr></thead>
        <tbody>
        <?php $maxDur = max(array_column($durationTrend, 'avg_dur')) ?: 1; ?>
        <?php foreach (array_reverse(array_slice($durationTrend, -14)) as $dt): ?>
            <tr>
                <td><?= e(jdate($dt['visit_date'])) ?></td>
                <td class="n"><?= e(en_to_fa_digits((string)$dt['avg_dur'])) ?></td>
                <td><div class="bar-cell"><i style="width:<?= max(2, round($dt['avg_dur'] / $maxDur * 100)) ?>%;background:#0d9488"></i></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (!empty($entryPages) || !empty($exitPages)): ?>
    <h2 class="sec">🚪 صفحات ورود و خروج کاربران</h2>
    <table class="dt">
        <thead><tr><th style="width:50%">⬅️ صفحات ورود</th><th style="width:50%">➡️ صفحات خروج</th></tr></thead>
        <tbody>
        <?php $maxRows = max(count($entryPages), count($exitPages)); ?>
        <?php for ($i = 0; $i < $maxRows; $i++): ?>
            <tr>
                <td dir="ltr" style="text-align:left;font-size:10.5px"><?= isset($entryPages[$i]) ? e($entryPages[$i]['entry_page']) . ' <b>(' . e(en_to_fa_digits((string)$entryPages[$i]['c'])) . ')</b>' : '—' ?></td>
                <td dir="ltr" style="text-align:left;font-size:10.5px"><?= isset($exitPages[$i]) ? e($exitPages[$i]['page_url']) . ' <b>(' . e(en_to_fa_digits((string)$exitPages[$i]['exits'])) . ')</b>' : '—' ?></td>
            </tr>
        <?php endfor; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h2 class="sec">📊 مقایسه با دوره قبل</h2>
    <table class="dt">
        <thead><tr><th>شاخص</th><th>دوره جاری</th><th>دوره قبل</th><th>تغییر</th></tr></thead>
        <tbody>
        <?php
        $chg = static fn($cur, $prev) => $prev > 0 ? round(($cur - $prev) / $prev * 100) : null;
        $rows = [
            ['بازدیدکننده یکتا', $totalUnique, $prevUnique],
            ['بازدید کل صفحات', $totalViews, $prevViews],
        ];
        ?>
        <?php foreach ($rows as [$label, $cur, $prev]): $d = $chg($cur, $prev); ?>
            <tr>
                <th><?= e($label) ?></th>
                <td class="n"><?= e(en_to_fa_digits((string)$cur)) ?></td>
                <td class="n"><?= e(en_to_fa_digits((string)$prev)) ?></td>
                <td class="n" style="font-weight:800;color:<?= $d === null ? '#64748b' : ($d >= 0 ? '#15803d' : '#b91c1c') ?>"><?= $d === null ? '—' : ($d >= 0 ? '▲ ' : '▼ ') . e(en_to_fa_digits((string)abs($d))) . '٪' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <footer class="rep">
        <span>صادرشده توسط سایت ساز برند سهند سرویس — <?= e(Config::get(Config::KEY_MAIN_SITE) ?: 'ea-fixer.ir') ?></span>
        <span>گزارش آماری <?= $brandRow ? e($brandRow['name_fa']) : 'کلی' ?></span>
    </footer>
</div>

<?php if (get_param('auto') === '1'): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
<?php endif; ?>
</body>
</html>
