<?php
/**
 * 🪝 وب‌هوک‌های خروجی (P3 — v2.37)
 * ================================
 * نقشه راه P3 گزارش تحلیل جامع: «وب‌هوک خروجی» —
 * اتصال سیستم به سرویس‌های بیرونی (CRM / n8n / Zapier-like / اسلک):
 *   ① ساخت هوک: برند (یا گلوبال) + URL مقصد + انتخاب رویدادها
 *   ② رمز امضای HMAC-SHA256 — خودکار ساخته می‌شود؛ گیرنده با هدر
 *      X-Sahand-Signature اصالت رخداد را راستی‌آزمایی می‌کند
 *   ③ تست فوری (رویداد test) + لاگ تحویل (کد پاسخ/تلاش/زمان‌بندی مجدد)
 *   ④ توقف/فعال‌سازی + حذف
 *
 * @package SahandBrandMaker
 */
define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();

/* 🛂 ACL: brand_manager فقط هوک‌های برندهای خودش را می‌بیند */
$_aclBrand = (int)($_GET['brand'] ?? 0);
if ($_aclBrand < 1) { $_aclBrand = (int)($_POST['brand_id'] ?? ($_POST['brand'] ?? 0)); }
if ($_aclBrand > 0) {
    $auth->requireBrandAccess($_aclBrand);
}

/* ════════════ اکشن‌ها ════════════ */

/* ➕ ساخت / ✏️ ویرایش */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    Auth::enforceCsrf();
    $hookId = (int)post('hook_id');
    $brandId = (int)post('brand_id'); /* 0 = گلوبال */
    $label = trim((string)post('label'));
    $url = trim((string)post('url'));
    $events = array_values(array_intersect(
        array_keys(WebhookDispatcher::EVENTS),
        array_filter(array_map('trim', (array)($_POST['events'] ?? [])))
    ));

    if ($brandId > 0) { $auth->requireBrandAccess($brandId); }
    if (mb_strlen($label) < 2) {
        flash('error', 'نام هوک باید حداقل ۲ نویسه باشد.');
        redirect('webhooks.php');
    }
    if (!preg_match('#^https?://[^\s]{5,490}$#i', $url)) {
        flash('error', 'آدرس مقصد باید یک URL معتبر http(s) باشد.');
        redirect('webhooks.php');
    }
    if (!$events) {
        flash('error', 'حداقل یک رویداد انتخاب کنید.');
        redirect('webhooks.php');
    }

    $data = [
        'brand_id' => $brandId > 0 ? $brandId : null,
        'label'    => mb_substr($label, 0, 190),
        'url'      => $url,
        'events'   => implode(',', $events),
    ];
    if ($hookId > 0) {
        $existing = $db->fetch('SELECT * FROM webhooks WHERE id = ? LIMIT 1', [$hookId]);
        if ($existing && (int)($existing['brand_id'] ?? 0) > 0) {
            $auth->requireBrandAccess((int)$existing['brand_id']);
        }
        $db->update('webhooks', $data, 'id = ?', [$hookId]);
        Logger::activity((int)$_SESSION['user_id'], 'ویرایش وب‌هوک', $label);
        flash('success', '✅ وب‌هوک بروزرسانی شد.');
    } else {
        $data['secret'] = WebhookDispatcher::generateSecret();
        $data['created_by'] = (int)$_SESSION['user_id'];
        $data['is_active'] = 1;
        $db->insert('webhooks', $data);
        Logger::activity((int)$_SESSION['user_id'], 'ساخت وب‌هوک', $label);
        flash('success', '✅ وب‌هوک ساخته شد — رمز امضا را کپی و در سرویس مقصد تنظیم کنید.');
    }
    redirect('webhooks.php');
}

/* 🧪 تست فوری */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'test') {
    Auth::enforceCsrf();
    $hookId = (int)post('hook_id');
    $existing = $db->fetch('SELECT * FROM webhooks WHERE id = ? LIMIT 1', [$hookId]);
    if ($existing && (int)($existing['brand_id'] ?? 0) > 0) {
        $auth->requireBrandAccess((int)$existing['brand_id']);
    }
    $result = WebhookDispatcher::test($hookId);
    if ($result['success']) {
        flash('success', '✅ رویداد آزمایشی تحویل شد (کد پاسخ ' . en_to_fa_digits((string)($result['code'] ?? 200)) . ').');
    } else {
        flash('error', '❌ تحویل ناموفق: ' . e($result['error'] ?? $result['code'] ?? 'خطای نامشخص'));
    }
    redirect('webhooks.php');
}

/* ⏯ فعال/غیرفعال */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'toggle') {
    Auth::enforceCsrf();
    $hookId = (int)post('hook_id');
    $existing = $db->fetch('SELECT * FROM webhooks WHERE id = ? LIMIT 1', [$hookId]);
    if ($existing) {
        if ((int)($existing['brand_id'] ?? 0) > 0) {
            $auth->requireBrandAccess((int)$existing['brand_id']);
        }
        $db->update('webhooks', ['is_active' => $existing['is_active'] ? 0 : 1], 'id = ?', [$hookId]);
        flash('success', $existing['is_active'] ? '⏸ وب‌هوک متوقف شد.' : '▶️ وب‌هوک فعال شد.');
    }
    redirect('webhooks.php');
}

/* 🗑 حذف */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    Auth::enforceCsrf();
    $hookId = (int)post('hook_id');
    $existing = $db->fetch('SELECT * FROM webhooks WHERE id = ? LIMIT 1', [$hookId]);
    if ($existing) {
        if ((int)($existing['brand_id'] ?? 0) > 0) {
            $auth->requireBrandAccess((int)$existing['brand_id']);
        }
        $db->delete('webhooks', 'id = ?', [$hookId]); /* deliveries با CASCADE حذف می‌شوند */
        Logger::activity((int)$_SESSION['user_id'], 'حذف وب‌هوک', (string)$existing['label']);
        flash('success', '🗑 وب‌هوک حذف شد.');
    }
    redirect('webhooks.php');
}

/* 🔁 بازتولید رمز امضا */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'resecret') {
    Auth::enforceCsrf();
    $hookId = (int)post('hook_id');
    $existing = $db->fetch('SELECT * FROM webhooks WHERE id = ? LIMIT 1', [$hookId]);
    if ($existing) {
        if ((int)($existing['brand_id'] ?? 0) > 0) {
            $auth->requireBrandAccess((int)$existing['brand_id']);
        }
        $db->update('webhooks', ['secret' => WebhookDispatcher::generateSecret()], 'id = ?', [$hookId]);
        flash('success', '🔑 رمز امضای جدید ساخته شد — رمز قبلی باطل شد.');
    }
    redirect('webhooks.php');
}

/* 📋 داده‌ها */
$brandFilter = (int)get_param('brand');
$editId = (int)get_param('edit');

$where = '1=1';
$params = [];
$aclIds = $auth->accessibleBrandIds();
if ($aclIds !== null) {
    /* brand_manager: فقط برندهای خودش (گلوبال‌ها مخصوص admin هستند) */
    $where .= ' AND w.brand_id IN (' . implode(',', array_map('intval', $aclIds ?: [0])) . ')';
}
if ($brandFilter > 0) { $where .= ' AND w.brand_id = ?'; $params[] = $brandFilter; }

$hooks = [];
$deliveries = [];
$editHook = null;
try {
    $hooks = $db->fetchAll(
        "SELECT w.*, b.name_fa AS brand_name,
                (SELECT COUNT(*) FROM webhook_deliveries d WHERE d.webhook_id = w.id) AS total_deliveries,
                (SELECT COUNT(*) FROM webhook_deliveries d WHERE d.webhook_id = w.id AND d.status = 'delivered') AS ok_deliveries,
                (SELECT COUNT(*) FROM webhook_deliveries d WHERE d.webhook_id = w.id AND d.status = 'pending') AS pending_deliveries
         FROM webhooks w LEFT JOIN brands b ON b.id = w.brand_id
         WHERE {$where}
         ORDER BY w.created_at DESC LIMIT 100",
        $params
    );
    /* 🔍 نمایش رمز فقط هنگام ویرایش (رمز در لیست never نمایش نمی‌یابد) */
    if ($editId > 0) {
        $editHook = $db->fetch('SELECT * FROM webhooks WHERE id = ? LIMIT 1', [$editId]);
        if ($editHook && (int)($editHook['brand_id'] ?? 0) > 0 && $aclIds !== null && !in_array((int)$editHook['brand_id'], $aclIds, true)) {
            $editHook = null;
        }
    }
    /* لاگ تحویل — آخرین ۳۰ رخداد */
    $deliveries = $db->fetchAll(
        "SELECT d.*, w.label AS hook_label
         FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id
         " . ($aclIds !== null ? 'WHERE w.brand_id IN (' . implode(',', array_map('intval', $aclIds ?: [0])) . ')' : '') . "
         ORDER BY d.created_at DESC LIMIT 30"
    );
} catch (Throwable $e) {
    /* جدول هنوز ساخته نشده — مهاجرت v2.37 با اولین لود می‌سازدش */
}

$brands = $db->fetchAll('SELECT id, name_fa FROM brands ORDER BY name_fa');
if ($aclIds !== null) {
    $brands = array_values(array_filter($brands, function ($_b) use ($aclIds) {
        return in_array((int)$_b['id'], $aclIds, true);
    }));
}
$eventLabels = WebhookDispatcher::EVENTS;

$pageTitle = 'وب‌هوک‌های خروجی';
$activeMenu = 'webhooks';
require __DIR__ . '/includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card"><div class="icon bg-blue">🪝</div><div><div class="number"><?= en_to_fa_digits((string)count($hooks)) ?></div><div class="label">هوک‌های ثبت‌شده</div></div></div>
    <div class="stat-card"><div class="icon bg-green">📤</div><div><div class="number"><?= en_to_fa_digits((string)array_sum(array_column($hooks, 'ok_deliveries'))) ?></div><div class="label">تحویل موفق (کل)</div></div></div>
    <div class="stat-card"><div class="icon bg-orange">⏳</div><div><div class="number"><?= en_to_fa_digits((string)array_sum(array_column($hooks, 'pending_deliveries'))) ?></div><div class="label">در صف تلاش مجدد</div></div></div>
    <div class="stat-card"><div class="icon bg-purple">⚡</div><div><div class="number"><?= en_to_fa_digits((string)count($eventLabels)) ?></div><div class="label">رویداد قابل اشتراک</div></div></div>
</div>

<!-- ➕ فرم ساخت / ✏️ ویرایش -->
<div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3><?= $editHook ? '✏️ ویرایش وب‌هوک #' . en_to_fa_digits((string)$editHook['id']) : '➕ وب‌هوک جدید' ?></h3></div>
    <div class="card-body">
        <form method="post">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="hook_id" value="<?= (int)($editHook['id'] ?? 0) ?>">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
                <div>
                    <label style="font-size:12.5px;font-weight:700;display:block;margin-bottom:5px">🏷️ نام هوک</label>
                    <input type="text" name="label" class="form-control" maxlength="190" required value="<?= e($editHook['label'] ?? '') ?>" placeholder="مثلاً: اتصال CRM شرکت">
                </div>
                <div>
                    <label style="font-size:12.5px;font-weight:700;display:block;margin-bottom:5px">🔗 برند (خالی = همه برندها)</label>
                    <select name="brand_id" class="form-control">
                        <option value="0">🌐 گلوبال — همه برندها</option>
                        <?php foreach ($brands as $b): ?>
                            <option value="<?= (int)$b['id'] ?>" <?= (int)($editHook['brand_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name_fa']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="grid-column:1/-1">
                    <label style="font-size:12.5px;font-weight:700;display:block;margin-bottom:5px">📡 آدرس مقصد (POST + JSON)</label>
                    <input type="url" name="url" class="form-control" maxlength="500" required dir="ltr" style="text-align:left" value="<?= e($editHook['url'] ?? '') ?>" placeholder="https://example.com/hooks/sahand">
                </div>
            </div>
            <div style="margin-top:12px">
                <label style="font-size:12.5px;font-weight:700;display:block;margin-bottom:7px">⚡ رویدادهای اشتراک‌شده</label>
                <div style="display:flex;gap:14px;flex-wrap:wrap">
                    <?php
                    $editEvents = $editHook ? array_filter(array_map('trim', explode(',', (string)$editHook['events']))) : array_keys($eventLabels);
                    foreach ($eventLabels as $ev => $evLabel): ?>
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:8px 13px;cursor:pointer">
                        <input type="checkbox" name="events[]" value="<?= e($ev) ?>" <?= in_array($ev, $editEvents, true) ? 'checked' : '' ?>>
                        <?= e($evLabel) ?> <code style="font-size:10.5px;color:#64748b" dir="ltr"><?= e($ev) ?></code>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:14px">
                <button type="submit" class="btn btn-primary"><?= $editHook ? '💾 ذخیره تغییرات' : '➕ ساخت وب‌هوک' ?></button>
                <?php if ($editHook): ?><a href="webhooks.php" class="btn btn-outline">✕ انصراف</a><?php endif; ?>
            </div>
        </form>
        <?php if ($editHook): ?>
        <div style="margin-top:13px;background:#0f172a;color:#e2e8f0;border-radius:10px;padding:12px 14px;direction:ltr;text-align:left;font-size:12px;line-height:1.8">
            <div style="color:#94a3b8;font-size:11px;margin-bottom:4px">Signing secret (HMAC-SHA256) — header <b>X-Sahand-Signature: sha256=…</b></div>
            <code style="word-break:break-all;user-select:all"><?= e($editHook['secret']) ?></code>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 📋 لیست هوک‌ها -->
<div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3>🪝 هوک‌ها</h3></div>
    <?php if (empty($hooks)): ?>
        <div class="empty-state"><div class="icon">🪝</div><p>هنوز وب‌هوکی ثبت نشده است.<br><small>با وب‌هوک، رخدادهای سیستم (درخواست جدید، انتشار مقاله، فرم، دیدگاه، استقرار) به‌صورت JSON امضاشده به سرویس‌های بیرونی ارسال می‌شوند — مناسب اتصال CRM و اتوماسیون.</small></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>#</th><th>نام</th><th>برند</th><th>رویدادها</th><th>تحویل</th><th>وضعیت</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($hooks as $h): ?>
                    <tr>
                        <td class="n"><?= en_to_fa_digits((string)$h['id']) ?></td>
                        <td>
                            <strong style="font-size:13px"><?= e($h['label']) ?></strong>
                            <div style="font-size:11px;color:#94a3b8;direction:ltr;text-align:right;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($h['url']) ?></div>
                        </td>
                        <td><?= $h['brand_id'] === null ? '<span class="badge bg-purple">گلوبال</span>' : e($h['brand_name'] ?? '—') ?></td>
                        <td style="font-size:11px">
                            <?php foreach (array_filter(array_map('trim', explode(',', (string)$h['events']))) as $ev): ?>
                                <span class="badge bg-blue" style="margin:1px"><?= e($eventLabels[$ev] ?? $ev) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td style="font-size:12px">
                            ✅ <?= en_to_fa_digits((string)$h['ok_deliveries']) ?>
                            <?php if ((int)$h['pending_deliveries'] > 0): ?> · ⏳ <?= en_to_fa_digits((string)$h['pending_deliveries']) ?><?php endif; ?>
                            <div style="color:#94a3b8;font-size:10.5px">از <?= en_to_fa_digits((string)$h['total_deliveries']) ?></div>
                        </td>
                        <td><span class="badge <?= $h['is_active'] ? 'bg-green' : 'badge-secondary' ?>"><?= $h['is_active'] ? 'فعال' : 'متوقف' ?></span></td>
                        <td style="white-space:nowrap">
                            <a href="webhooks.php?edit=<?= (int)$h['id'] ?>" class="btn btn-outline btn-sm">✏️</a>
                            <form method="post" style="display:inline">
                                <?= Auth::csrfField() ?>
                                <input type="hidden" name="action" value="test">
                                <input type="hidden" name="hook_id" value="<?= (int)$h['id'] ?>">
                                <button type="submit" class="btn btn-primary btn-sm">🧪 تست</button>
                            </form>
                            <form method="post" style="display:inline">
                                <?= Auth::csrfField() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="hook_id" value="<?= (int)$h['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm"><?= $h['is_active'] ? '⏸' : '▶️' ?></button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="return sahandSubmitConfirm(this,'این وب‌هوک و تاریخچه تحویلش حذف شود؟')">
                                <?= Auth::csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="hook_id" value="<?= (int)$h['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">🗑</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- 📤 لاگ تحویل -->
<div class="card">
    <div class="card-header"><h3>📤 آخرین تحویل‌ها (۳۰ مورد)</h3></div>
    <?php if (empty($deliveries)): ?>
        <div class="empty-state"><div class="icon">📤</div><p>هنوز رخدادی ارسال نشده است.</small></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>هوک</th><th>رویداد</th><th>وضعیت</th><th>کد</th><th>تلاش</th><th>تلاش بعدی</th><th>زمان</th></tr></thead>
                <tbody>
                <?php foreach ($deliveries as $d): ?>
                    <tr>
                        <td style="font-size:12.5px"><?= e($d['hook_label']) ?></td>
                        <td><code style="font-size:11px" dir="ltr"><?= e($d['event']) ?></code></td>
                        <td><span class="badge <?= $d['status'] === 'delivered' ? 'bg-green' : ($d['status'] === 'failed' ? 'bg-red' : 'bg-orange') ?>"><?= $d['status'] === 'delivered' ? 'تحویل‌شده' : ($d['status'] === 'failed' ? 'ناموفق' : 'در صف') ?></span></td>
                        <td class="n"><?= $d['response_code'] !== null ? en_to_fa_digits((string)$d['response_code']) : '—' ?></td>
                        <td class="n"><?= en_to_fa_digits((string)$d['attempts']) ?>/<?= en_to_fa_digits((string)WebhookDispatcher::MAX_ATTEMPTS) ?></td>
                        <td style="font-size:11.5px"><?= $d['next_retry_at'] ? e(jdate((string)$d['next_retry_at'], true)) : '—' ?></td>
                        <td style="font-size:11.5px"><?= e(jdate((string)$d['created_at'], true)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="hint" style="margin-top:12px">💡 هر رخداد با متد POST و بدنه JSON ارسال می‌شود: <code dir="ltr">{ "event", "created_at", "data": {…} }</code> — امضای HMAC-SHA256 بدنه در هدر <code dir="ltr">X-Sahand-Signature</code> می‌آید (الگوی GitHub/Stripe). تحویل ناموفق تا ۶ بار با فاصله فزاینده (۵ تا ۲۰ دقیقه) توسط cron تلاش مجدد می‌شود.</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
