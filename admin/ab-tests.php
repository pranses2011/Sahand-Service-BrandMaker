<?php
/**
 * 🧪 تست A/B سایت برند (P3 — v2.37)
 * =================================
 * نقشه راه P3 گزارش تحلیل جامع: «A/B تست» —
 *   ① ساخت آزمایش روی «عنوان هیرو» یا «متن دکمه CTA» صفحه اصلی
 *   ② تقسیم ۵۰/۵۰ بازدیدکنندگان با هش پایدار (کوکی ۳۶۵ روزه) —
 *      همان کاربر همیشه همان واریانت را می‌بیند
 *   ③ آمار زنده: نمایش یکتا / کلیک / CTR هر واریانت
 *   ④ راهنمای تصمیم: آزمون Z دو-نسبتی + برچسب «اطمینان»
 *   ⑤ پایان آزمایش = متن برنده در هیرو ثابت می‌ماند (winner ثبت می‌شود)
 *
 * 🧮 آمار یکتا: هر visitor_hash فقط یک view می‌گیرد (اندپوینت جلوی
 *    تکرار را می‌گیرد)؛ کلیک واقعی بدون سقف.
 *
 * @package SahandBrandMaker
 */
define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

/* 🛂 ACL سطح‌برند */
$_aclBrand = (int)($_GET['brand'] ?? 0);
if ($_aclBrand < 1) { $_aclBrand = (int)($_POST['brand_id'] ?? ($_POST['brand'] ?? 0)); }
if ($_aclBrand > 0) {
    (new Auth())->requireBrandAccess($_aclBrand);
}

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();

/* ════════════ اکشن‌ها ════════════ */

/* ➕ ساخت آزمایش جدید */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'create') {
    Auth::enforceCsrf();
    $brandId = (int)post('brand_id');
    $name = trim((string)post('name'));
    $element = post('element') === 'hero_cta' ? 'hero_cta' : 'hero_title';
    $variantA = trim((string)post('variant_a'));
    $variantB = trim((string)post('variant_b'));

    $auth->requireBrandAccess($brandId);
    if ($brandId < 1 || mb_strlen($name) < 2) {
        flash('error', 'برند و نام آزمایش را کامل کنید.');
        redirect('ab-tests.php');
    }
    if (mb_strlen($variantA) < 2 || mb_strlen($variantB) < 2 || mb_strlen($variantA) > 500 || mb_strlen($variantB) > 500) {
        flash('error', 'متن هر دو واریانت باید بین ۲ تا ۵۰۰ نویسه باشد.');
        redirect('ab-tests.php');
    }
    /* فقط یک آزمایش running به‌ازای هر برند — واریانت انتخابی قطعی می‌شود */
    $running = $db->fetch("SELECT id FROM ab_tests WHERE brand_id = ? AND status = 'running' LIMIT 1", [$brandId]);
    if ($running) {
        flash('warning', '⚠️ این برند آزمایش در حال اجرا دارد — اول آن را پایان دهید یا متوقف کنید.');
        redirect('ab-tests.php');
    }
    $db->insert('ab_tests', [
        'brand_id'   => $brandId,
        'name'       => mb_substr($name, 0, 190),
        'element'    => $element,
        'variant_a'  => $variantA,
        'variant_b'  => $variantB,
        'status'     => 'running',
        'created_by' => (int)$_SESSION['user_id'],
    ]);
    Logger::activity((int)$_SESSION['user_id'], 'ساخت تست A/B', $name);
    flash('success', '🧪 آزمایش A/B شروع شد — از این لحظه بازدیدکنندگان سایت برند ۵۰/۵۰ تقسیم می‌شوند.');
    redirect('ab-tests.php');
}

/* ⏸ ادامه/توقف */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'toggle') {
    Auth::enforceCsrf();
    $test = $db->fetch('SELECT * FROM ab_tests WHERE id = ? LIMIT 1', [(int)post('test_id')]);
    if ($test) {
        $auth->requireBrandAccess((int)$test['brand_id']);
        if ($test['status'] === 'running') {
            $db->update('ab_tests', ['status' => 'paused'], 'id = ?', [$test['id']]);
            flash('success', '⏸ آزمایش متوقف شد — همه بازدیدکنندگان متن پیش‌فرض را می‌بینند.');
        } elseif ($test['status'] === 'paused') {
            $db->update('ab_tests', ['status' => 'running'], 'id = ?', [$test['id']]);
            flash('success', '▶️ آزمایش از سر گرفته شد.');
        }
    }
    redirect('ab-tests.php');
}

/* 🏁 پایان + تعیین برنده */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'finish') {
    Auth::enforceCsrf();
    $test = $db->fetch('SELECT * FROM ab_tests WHERE id = ? LIMIT 1', [(int)post('test_id')]);
    if ($test) {
        $auth->requireBrandAccess((int)$test['brand_id']);
        $winner = post('winner') === 'B' ? 'B' : (post('winner') === 'A' ? 'A' : null);
        $db->update('ab_tests', [
            'status'    => 'finished',
            'ended_at'  => date('Y-m-d H:i:s'),
            'winner'    => $winner,
        ], 'id = ?', [$test['id']]);
        Logger::activity((int)$_SESSION['user_id'], 'پایان تست A/B', (string)$test['name'] . ' — برنده: ' . ($winner ?? 'نامشخص'));
        flash('success', '🏁 آزمایش پایان یافت.' . ($winner ? ' واریانت «' . $winner . '» به‌عنوان برنده ثبت شد — متن آن را در قالب صفحه اصلی اعمال کنید.' : ''));
    }
    redirect('ab-tests.php');
}

/* 🗑 حذف */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    Auth::enforceCsrf();
    $test = $db->fetch('SELECT * FROM ab_tests WHERE id = ? LIMIT 1', [(int)post('test_id')]);
    if ($test) {
        $auth->requireBrandAccess((int)$test['brand_id']);
        $db->delete('ab_tests', 'id = ?', [$test['id']]); /* events با CASCADE */
        Logger::activity((int)$_SESSION['user_id'], 'حذف تست A/B', (string)$test['name']);
        flash('success', '🗑 آزمایش حذف شد.');
    }
    redirect('ab-tests.php');
}

/* 📋 داده‌ها + آمار */
$tests = [];
try {
    $tests = $db->fetchAll(
        "SELECT t.*, b.name_fa AS brand_name,
                (SELECT COUNT(DISTINCT visitor_hash) FROM ab_events e WHERE e.test_id = t.id AND e.event = 'view' AND e.variant = 'A') AS a_views,
                (SELECT COUNT(DISTINCT visitor_hash) FROM ab_events e WHERE e.test_id = t.id AND e.event = 'view' AND e.variant = 'B') AS b_views,
                (SELECT COUNT(*) FROM ab_events e WHERE e.test_id = t.id AND e.event = 'click' AND e.variant = 'A') AS a_clicks,
                (SELECT COUNT(*) FROM ab_events e WHERE e.test_id = t.id AND e.event = 'click' AND e.variant = 'B') AS b_clicks
         FROM ab_tests t LEFT JOIN brands b ON b.id = t.brand_id
         ORDER BY t.started_at DESC LIMIT 60"
    );
} catch (Throwable $e) {
    /* جدول هنوز ساخته نشده — مهاجرت v2.37 با اولین لود می‌سازدش */
}

/* 🧮 محاسبه Z دو-نسبتی برای هر آزمایش (راهنمای تصمیم — نه حکم قطعی) */
$abStats = [];
foreach ($tests as $t) {
    $av = (int)$t['a_views'];
    $bv = (int)$t['b_views'];
    $ac = (int)$t['a_clicks'];
    $bc = (int)$t['b_clicks'];
    $ctrA = $av > 0 ? $ac / $av : 0.0;
    $ctrB = $bv > 0 ? $bc / $bv : 0.0;
    $z = 0.0;
    if ($av >= 30 && $bv >= 30 && ($ac + $bc) > 0 && ($av + $bv - $ac - $bc) > 0) {
        $pPool = ($ac + $bc) / ($av + $bv);
        $se = sqrt($pPool * (1 - $pPool) * (1 / $av + 1 / $bv));
        if ($se > 0) {
            $z = ($ctrB - $ctrA) / $se;
        }
    }
    $confidence = 'ناکافی';
    if (abs($z) >= 2.58) { $confidence = 'بسیار قوی (۹۹٪+)'; }
    elseif (abs($z) >= 1.96) { $confidence = 'قوی (۹۵٪+)'; }
    elseif (abs($z) >= 1.64) { $confidence = 'متوسط (۹۰٪+)'; }
    elseif ($av > 0 || $bv > 0) { $confidence = 'نیازمند داده بیشتر'; }
    $abStats[(int)$t['id']] = [
        'ctrA' => $ctrA, 'ctrB' => $ctrB, 'z' => $z, 'confidence' => $confidence,
        'leader' => $ctrB > $ctrA ? 'B' : ($ctrA > $ctrB ? 'A' : null),
    ];
}

$brands = $db->fetchAll('SELECT id, name_fa FROM brands ORDER BY name_fa');
$_aclIds = $auth->accessibleBrandIds();
if ($_aclIds !== null) {
    $brands = array_values(array_filter($brands, function ($_b) use ($_aclIds) {
        return in_array((int)$_b['id'], $_aclIds, true);
    }));
}

$pageTitle = 'تست A/B';
$activeMenu = 'ab-tests';
require __DIR__ . '/includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card"><div class="icon bg-purple">🧪</div><div><div class="number"><?= en_to_fa_digits((string)count($tests)) ?></div><div class="label">کل آزمایش‌ها</div></div></div>
    <div class="stat-card"><div class="icon bg-green">▶️</div><div><div class="number"><?= en_to_fa_digits((string)count(array_filter($tests, static fn($t) => $t['status'] === 'running'))) ?></div><div class="label">در حال اجرا</div></div></div>
    <div class="stat-card"><div class="icon bg-blue">🏁</div><div><div class="number"><?= en_to_fa_digits((string)count(array_filter($tests, static fn($t) => $t['status'] === 'finished'))) ?></div><div class="label">پایان‌یافته</div></div></div>
    <div class="stat-card"><div class="icon bg-orange">👥</div><div><div class="number"><?= en_to_fa_digits((string)array_sum(array_column($tests, 'a_views')) + array_sum(array_column($tests, 'b_views'))) ?></div><div class="label">بازدیدکننده در آزمایش‌ها</div></div></div>
</div>

<!-- ➕ آزمایش جدید -->
<div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3>➕ آزمایش جدید</h3></div>
    <div class="card-body">
        <form method="post">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
                <div>
                    <label style="font-size:12.5px;font-weight:700;display:block;margin-bottom:5px">🏷️ برند</label>
                    <select name="brand_id" class="form-control" required>
                        <option value="">— انتخاب برند —</option>
                        <?php foreach ($brands as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= e($b['name_fa']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="font-size:12.5px;font-weight:700;display:block;margin-bottom:5px">🧩 عنصر آزمایش</label>
                    <select name="element" class="form-control">
                        <option value="hero_title">🦸 عنوان هیرو صفحه اصلی</option>
                        <option value="hero_cta">📝 متن دکمه CTA هیرو</option>
                    </select>
                </div>
                <div style="grid-column:1/-1">
                    <label style="font-size:12.5px;font-weight:700;display:block;margin-bottom:5px">📌 نام آزمایش (فقط برای پنل)</label>
                    <input type="text" name="name" class="form-control" maxlength="190" required placeholder="مثلاً: عنوان هیرو پاییز — سوالالی در برابر فوری">
                </div>
                <div>
                    <label style="font-size:12.5px;font-weight:700;display:block;margin-bottom:5px">🅰️ واریانت A (پایه)</label>
                    <input type="text" name="variant_a" class="form-control" maxlength="500" required placeholder="متن فعلی/پایه">
                </div>
                <div>
                    <label style="font-size:12.5px;font-weight:700;display:block;margin-bottom:5px">🅱️ واریانت B (رقیب)</label>
                    <input type="text" name="variant_b" class="form-control" maxlength="500" required placeholder="متن جدید آزمایشی">
                </div>
            </div>
            <div style="margin-top:13px;display:flex;gap:8px;align-items:center">
                <button type="submit" class="btn btn-primary">🧪 شروع آزمایش</button>
                <span style="font-size:12px;color:#64748b">بازدیدکنندگان سایت برند به‌طور پایدار (کوکی ۱ ساله) نصف‌نصف بین A و B تقسیم می‌شوند.</span>
            </div>
        </form>
    </div>
</div>

<!-- 📋 لیست آزمایش‌ها -->
<div class="card">
    <div class="card-header"><h3>🧪 آزمایش‌ها و آمار زنده</h3></div>
    <?php if (empty($tests)): ?>
        <div class="empty-state"><div class="icon">🧪</div><p>هنوز آزمایشی ساخته نشده است.<br><small>با تست A/B می‌توانید دو نسخه از عنوان هیرو یا متن دکمه CTA را روی بازدیدکنندگان واقعی مقایسه کنید و نسخه پرکلیک‌تر را انتخاب کنید.</small></p></div>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:14px;padding:16px">
            <?php foreach ($tests as $t): ?>
                <?php
                $st = $abStats[(int)$t['id']];
                $statusBadge = $t['status'] === 'running' ? '<span class="badge bg-green">در حال اجرا</span>'
                    : ($t['status'] === 'paused' ? '<span class="badge badge-secondary">متوقف</span>'
                    : '<span class="badge bg-blue">پایان‌یافته' . ($t['winner'] ? ' — برنده ' . e((string)$t['winner']) : '') . '</span>');
                ?>
            <div class="card" style="margin:0;box-shadow:0 1px 2px rgba(0,0,0,.05)">
                <div style="padding:13px 16px">
                    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:9px">
                        <div>
                            <strong style="font-size:13.5px">🧪 <?= e($t['name']) ?></strong>
                            <?= $statusBadge ?>
                            <div style="font-size:11.5px;color:#94a3b8;margin-top:3px">
                                🏷️ <?= e($t['brand_name'] ?? '—') ?> ·
                                <?= $t['element'] === 'hero_cta' ? 'دکمه CTA' : 'عنوان هیرو' ?> ·
                                شروع: <?= e(jdate((string)$t['started_at'], true)) ?>
                            </div>
                        </div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <?php if ($t['status'] !== 'finished'): ?>
                            <form method="post" style="display:inline">
                                <?= Auth::csrfField() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="test_id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm"><?= $t['status'] === 'running' ? '⏸ توقف' : '▶️ ادامه' ?></button>
                            </form>
                            <?php endif; ?>
                            <form method="post" style="display:inline" onsubmit="return sahandSubmitConfirm(this,'آزمایش پایان یابد؟ بعد از این قابل بازگشت نیست.')">
                                <?= Auth::csrfField() ?>
                                <input type="hidden" name="action" value="finish">
                                <input type="hidden" name="test_id" value="<?= (int)$t['id'] ?>">
                                <input type="hidden" name="winner" value="<?= $st['leader'] ?? '' ?>">
                                <button type="submit" class="btn btn-primary btn-sm">🏁 پایان<?= $st['leader'] ? ' (برنده: ' . e($st['leader']) . ')' : '' ?></button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="return sahandSubmitConfirm(this,'آزمایش و همه رویدادهایش حذف شود؟')">
                                <?= Auth::csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="test_id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">🗑</button>
                            </form>
                        </div>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                        <?php foreach (['A' => $t['variant_a'], 'B' => $t['variant_b']] as $vK => $vText):
                            $views = $vK === 'A' ? (int)$t['a_views'] : (int)$t['b_views'];
                            $clicks = $vK === 'A' ? (int)$t['a_clicks'] : (int)$t['b_clicks'];
                            $ctr = $vK === 'A' ? $st['ctrA'] : $st['ctrB'];
                            $isLeader = $st['leader'] === $vK;
                        ?>
                        <div style="border:1px solid <?= $isLeader ? '#16a34a' : '#e2e8f0' ?>;border-radius:11px;padding:11px 13px;background:<?= $isLeader ? '#f0fdf4' : '#f8fafc' ?>">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
                                <strong style="font-size:13px">واریانت <?= e($vK) ?> <?= $isLeader ? '🥇' : '' ?></strong>
                                <small style="font-size:11px;color:#64748b">CTR: <b><?= en_to_fa_digits(number_format($ctr * 100, 1)) ?>٪</b></small>
                            </div>
                            <div style="font-size:13px;line-height:1.8;margin-bottom:7px"><?= e($vText) ?></div>
                            <div style="display:flex;gap:12px;font-size:11.5px;color:#64748b">
                                <span>👁 <?= en_to_fa_digits((string)$views) ?> بازدید یکتا</span>
                                <span>🖱 <?= en_to_fa_digits((string)$clicks) ?> کلیک</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top:9px;font-size:12px;color:#64748b">
                        📊 اطمینان آماری: <b><?= e($st['confidence']) ?></b>
                        <?php if ($st['z'] != 0.0): ?>(Z = <?= en_to_fa_digits(number_format($st['z'], 2)) ?> — B نسبت به A)<?php endif; ?>
                        · برای نتیجه معنادار حداقل ~۳۰ بازدید یکتا در هر واریانت لازم است.
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<div class="hint" style="margin-top:12px">💡 بعد از پایان آزمایش، متنِ واریانت برنده را در <a href="template-builder.php">قالب‌ساز</a> (بلوک هیرو صفحه اصلی) اعمال کنید تا برای همه کاربران ثابت شود. آمار آزمایش‌ها از کوکی بی‌نام استفاده می‌کند — هیچ داده شخصی ذخیره نمی‌شود.</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
