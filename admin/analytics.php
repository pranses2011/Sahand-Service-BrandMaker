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

/* 🗺️ پراکندگی جغرافیایی با GeoIP محلی */
$ipPrefixes = $safeQuery("SELECT DISTINCT v.ip_prefix FROM visits v WHERE {$where} AND v.ip_prefix IS NOT NULL AND v.ip_prefix != ''", $params);
$citiesDist = GeoIP::citiesDistribution(array_column($ipPrefixes, 'ip_prefix'));

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
            — آخرین بازدید: <?= e(fa_num((string)$trackerLast)) ?>
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

<div class="grid-2">
    <!-- 🗺️ نقشه جغرافیایی (محرک حرارتی شهرها) -->
    <div class="card">
        <div class="card-header"><h3>🗺️ پراکندگی جغرافیایی بازدیدکنندگان</h3></div>
        <div class="card-body">
            <?php if (empty($citiesDist)): ?>
                <div class="empty-state"><div class="icon">🗺️</div><p>داده جغرافیایی ثبت نشده است.<br><small>پس از بازدید اولین کاربران، نقشه شهرها اینجا نمایش داده می‌شود.</small></p></div>
            <?php else: ?>
                <?php $maxCity = max($citiesDist); ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px">
                    <?php foreach (array_slice($citiesDist, 0, 20, true) as $city => $count): ?>
                        <div style="border-radius:11px;padding:11px 13px;background:rgba(30,64,175,<?= 0.06 + 0.5 * $count / $maxCity ?>);border:1px solid rgba(30,64,175,.18)">
                            <div style="font-weight:700;font-size:12.5px">📍 <?= e($city) ?></div>
                            <div style="font-size:16px;font-weight:800;color:var(--primary)"><?= en_to_fa_digits((string)$count) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="hint" style="margin-top:12px">🛡️ تشخیص جغرافیا با دیتابیس GeoIP محلی انجام می‌شود — بدون هیچ API خارجی.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 🥧 سهم برندها -->
    <div class="card">
        <div class="card-header"><h3>🥧 سهم برندها از بازدید</h3></div>
        <div class="card-body"><canvas id="brands-chart" height="260"></canvas></div>
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
