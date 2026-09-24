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

/* 📈 سری زمانی */
$timeline = $db->fetchAll(
    "SELECT v.visit_date, COUNT(DISTINCT v.session_hash) AS unique_visits, COUNT(*) AS views
     FROM visits v WHERE {$where} GROUP BY v.visit_date ORDER BY v.visit_date",
    $params
);

/* 📱 تفکیک‌ها */
$byDevice = $db->fetchAll("SELECT v.device_type, COUNT(DISTINCT v.session_hash) AS c FROM visits v WHERE {$where} GROUP BY v.device_type ORDER BY c DESC", $params);
$byBrowser = $db->fetchAll("SELECT v.browser, COUNT(DISTINCT v.session_hash) AS c FROM visits v WHERE {$where} AND v.browser IS NOT NULL GROUP BY v.browser ORDER BY c DESC LIMIT 8", $params);
$byOs = $db->fetchAll("SELECT v.os, COUNT(DISTINCT v.session_hash) AS c FROM visits v WHERE {$where} AND v.os IS NOT NULL GROUP BY v.os ORDER BY c DESC LIMIT 6", $params);

/* 🗺️ شهرها */
$ipPrefixes = $db->fetchAll("SELECT DISTINCT v.ip_prefix FROM visits v WHERE {$where} AND v.ip_prefix IS NOT NULL AND v.ip_prefix != ''", $params);
$citiesDist = GeoIP::citiesDistribution(array_column($ipPrefixes, 'ip_prefix'));

/* 📄 صفحات پربازدید */
$topPages = $db->fetchAll(
    "SELECT vd.page_url, COUNT(*) AS views, AVG(vd.duration) AS avg_duration
     FROM visit_details vd JOIN visits v ON v.id = vd.visit_id
     WHERE {$where} GROUP BY vd.page_url ORDER BY views DESC LIMIT 12",
    $params
);

/* 🏷️ سهم برندها (فقط در حالت «همه برندها») */
$brandShare = $brandFilter === 0 ? $db->fetchAll(
    "SELECT b.name_fa, COUNT(DISTINCT v.session_hash) AS visits
     FROM brands b JOIN visits v ON v.brand_id = b.id
     WHERE b.is_active = 1 GROUP BY b.id HAVING visits > 0 ORDER BY visits DESC LIMIT 12",
    []
) : [];

/* 📈 شاخص‌های کلی */
$totalUnique = array_sum(array_column($timeline, 'unique_visits'));
$totalViews = array_sum(array_column($timeline, 'views'));
$durRow = $db->fetchAll("SELECT AVG(vd.duration) AS avg_dur FROM visit_details vd JOIN visits v ON v.id = vd.visit_id WHERE {$where}", $params);
$avgDuration = $durRow ? round((float)$durRow[0]['avg_dur']) : 0;
$bounceRow = $db->fetchAll(
    "SELECT COUNT(DISTINCT v.session_hash) AS sessions,
            COUNT(DISTINCT CASE WHEN exits.exit_count = 1 AND views.vc = 1 THEN v.session_hash END) AS bounced
     FROM visits v
     LEFT JOIN (SELECT visit_id, COUNT(*) AS exit_count FROM visit_details WHERE is_exit = 1 GROUP BY visit_id) exits ON exits.visit_id = v.id
     LEFT JOIN (SELECT visit_id, COUNT(*) AS vc FROM visit_details GROUP BY visit_id) views ON views.visit_id = v.id
     WHERE {$where}",
    $params
);
$bounceRate = $bounceRow && (int)$bounceRow[0]['sessions'] > 0 ? round($bounceRow[0]['bounced'] / $bounceRow[0]['sessions'] * 100) : 0;

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

    <!-- 🗺️ شهرها -->
    <h2 class="sec">🗺️ پراکندگی جغرافیایی بازدیدکنندگان</h2>
    <?php if (empty($citiesDist)): ?>
        <p style="color:#94a3b8">داده جغرافیایی ثبت نشده است.</p>
    <?php else: ?>
    <table class="dt">
        <thead><tr><th>شهر / استان</th><th>بازدیدکننده</th><th style="width:220px">سهم</th></tr></thead>
        <tbody>
        <?php $maxCity = max($citiesDist) ?: 1; ?>
        <?php foreach (array_slice($citiesDist, 0, 18, true) as $city => $count): ?>
            <tr>
                <td><?= e($city) ?></td>
                <td class="n"><?= e(en_to_fa_digits((string)$count)) ?></td>
                <td><div class="bar-cell"><i style="width:<?= max(2, round($count / $maxCity * 100)) ?>%"></i></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
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
