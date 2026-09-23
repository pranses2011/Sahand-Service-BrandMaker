<?php
/**
 * 🧠 پنل یادگیری AI — مدیریت درس‌های آموخته‌شده موتور خودارتقادهنده
 * ================================================================
 * هر بهبود موفق (سئو/محتوا/UX/کد خطا) یک «درس» ثبت می‌کند که در کارهای
 * بعدی ترجیح داده می‌شود. اگر درسی اشتباه از آب درآمد و عملکرد را مختل
 * کرد، همین‌جا غیرفعالش می‌کنید — بقیه درس‌ها پابرجا می‌مانند.
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

/* 🔘 عملیات */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::enforceCsrf();
    $action = post('action');

    if ($action === 'toggle_lesson') {
        $ok = SelfLearner::toggle((string)post('lesson_id'), post('enabled') === '1');
        flash($ok ? 'success' : 'danger', $ok
            ? (post('enabled') === '1' ? '✅ درس فعال شد — از این پس در بهبودهای بعدی اعمال می‌شود.' : '🔴 درس غیرفعال شد — دیگر اعمال نمی‌شود ولی حفظ شده است.')
            : 'درس یافت نشد.');
        redirect('ai-learning.php');
    }

    if ($action === 'remove_lesson') {
        $ok = SelfLearner::remove((string)post('lesson_id'));
        flash($ok ? 'success' : 'danger', $ok ? '🗑️ درس حذف شد.' : 'درس یافت نشد.');
        redirect('ai-learning.php');
    }
}

$pageTitle = 'یادگیری AI';
$activeMenu = 'ai-learning';
require __DIR__ . '/includes/header.php';

$stats = SelfLearner::stats();
$lessons = SelfLearner::lessons();
$logLines = SelfLearner::recentLog(50);

$typeMeta = [
    'seo_fix'        => ['بهبود سئو', 'badge-info', '🔍'],
    'content_fix'    => ['بهبود محتوا', 'badge-success', '📝'],
    'uiux_fix'       => ['بهبود UX', 'badge-warning', '🎨'],
    'error_code_fix' => ['کد خطا', 'badge-danger', '🚨'],
];
?>
<div class="stats-grid">
    <div class="stat-card">
        <div class="icon bg-blue">🧠</div>
        <div><div class="number"><?= en_to_fa_digits((string)$stats['total']) ?></div><div class="label">کل درس‌های آموخته</div></div>
    </div>
    <div class="stat-card">
        <div class="icon bg-green">✅</div>
        <div><div class="number"><?= en_to_fa_digits((string)$stats['active']) ?></div><div class="label">درس فعال</div></div>
    </div>
    <div class="stat-card">
        <div class="icon bg-red">🔴</div>
        <div><div class="number"><?= en_to_fa_digits((string)$stats['disabled']) ?></div><div class="label">درس غیرفعال (معوق)</div></div>
    </div>
    <div class="stat-card">
        <div class="icon bg-purple">📈</div>
        <div><div class="number"><?= en_to_fa_digits((string)$stats['total_gain']) ?></div><div class="label">مجموع امتیاز کسب‌شده</div></div>
    </div>
    <div class="stat-card">
        <div class="icon bg-orange">🔁</div>
        <div><div class="number"><?= en_to_fa_digits((string)$stats['total_uses']) ?></div><div class="label">دفعات به‌کارگیری</div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>🧠 درس‌های آموخته‌شده موتور AI</h3>
    </div>
    <div class="card-body">
        <div class="alert alert-info" style="margin-bottom:16px">
            🧠 <b>موتور خودیادگیر چطور کار می‌کند؟</b> هر بار که دکمه‌های «بهبود» (سئو، محتوا، UX، کد خطا) نتیجه بهتری بگیرند، اقدام موفق به‌صورت یک «درس» ثبت می‌شود تا در تولید و بهبودهای بعدی ترجیح داده شود.
            اگر درسی اشتباه بود و عملکرد را مختل کرد، فقط همان یک درس را غیرفعال کنید — بقیه درس‌ها همچنان فعال می‌مانند.
            تاریخچه کامل ارتقاها در فایل <code>logs/ai-upgrades.log</code> (فقط-افزودنی) نگهداری می‌شود.
        </div>

        <?php if (empty($lessons)): ?>
            <div class="empty-state">
                <div class="icon">🧠</div>
                <p>هنوز درسی ثبت نشده است — با زدن دکمه‌های «بهبود سئو» (کلی یا تک‌سنجه) در صفحه مقالات، اولین درس‌ها اینجا ثبت می‌شوند.</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>حوزه</th><th>سنجه / بخش</th><th>اقدام موفق</th><th>اثر (قبل → بعد)</th><th>دفعات</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                <tbody>
                <?php foreach ($lessons as $lesson): ?>
                    <?php [$typeLabel, $typeBadge, $typeIcon] = $typeMeta[$lesson['type']] ?? [$lesson['type'], 'badge-secondary', '•']; ?>
                    <tr>
                        <td><span class="badge <?= $typeBadge ?>"><?= $typeIcon ?> <?= e($typeLabel) ?></span></td>
                        <td style="font-weight:700"><?= e($lesson['metric']) ?></td>
                        <td style="font-size:12px"><code style="direction:ltr;display:inline-block"><?= e($lesson['action']) ?></code><br><small style="color:var(--text-light)"><?= e(mb_substr((string)$lesson['rule'], 0, 90)) ?><?= mb_strlen((string)$lesson['rule']) > 90 ? '…' : '' ?></small></td>
                        <td style="white-space:nowrap">
                            <?= en_to_fa_digits((string)($lesson['before']['score'] ?? '-')) ?> → <b style="color:#16a34a"><?= en_to_fa_digits((string)($lesson['after']['score'] ?? '-')) ?></b>
                            <br><small style="color:var(--text-light)">مجموع: +<?= en_to_fa_digits((string)($lesson['total_gain'] ?? 0)) ?></small>
                        </td>
                        <td><?= en_to_fa_digits((string)($lesson['uses'] ?? 1)) ?></td>
                        <td><?= !empty($lesson['enabled']) ? '<span class="badge badge-success">فعال</span>' : '<span class="badge badge-danger">غیرفعال</span>' ?></td>
                        <td style="white-space:nowrap">
                            <form method="post" style="display:inline">
                                <?= Auth::csrfField() ?>
                                <input type="hidden" name="action" value="toggle_lesson">
                                <input type="hidden" name="lesson_id" value="<?= e($lesson['id']) ?>">
                                <input type="hidden" name="enabled" value="<?= !empty($lesson['enabled']) ? '0' : '1' ?>">
                                <button class="btn <?= !empty($lesson['enabled']) ? 'btn-outline' : 'btn-success' ?> btn-sm"><?= !empty($lesson['enabled']) ? '🔴 غیرفعال کن' : '🟢 فعال کن' ?></button>
                            </form>
                            <form method="post" style="display:inline" data-confirm="این درس به‌طور کامل حذف شود؟ (غیرفعال کردن کافی است — حذف برگشت‌پذیر نیست)">
                                <?= Auth::csrfField() ?>
                                <input type="hidden" name="action" value="remove_lesson">
                                <input type="hidden" name="lesson_id" value="<?= e($lesson['id']) ?>">
                                <button class="btn btn-danger btn-sm">🗑️</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>📜 آخرین رویدادهای ارتقا (logs/ai-upgrades.log)</h3></div>
    <div class="card-body">
        <?php if (empty($logLines)): ?>
            <div class="empty-state" style="padding:26px"><div class="icon">📜</div><p>هنوز رویدادی ثبت نشده است.</p></div>
        <?php else: ?>
            <div style="background:#0f172a;color:#e2e8f0;border-radius:11px;padding:14px 16px;font-family:monospace;font-size:11px;direction:ltr;text-align:left;max-height:320px;overflow-y:auto;line-height:1.9">
                <?php foreach ($logLines as $line): ?><?= e($line) ?><br><?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
