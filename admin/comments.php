<?php
/**
 * 💬 مدیریت دیدگاه مقالات (P3 — v2.37)
 * =====================================
 * نقشه راه P3 گزارش تحلیل جامع: «دیدگاه مقالات» —
 *   ① صف مودریشن: pending / approved / spam + فیلتر برند
 *   ② تأیید / رد (به pending بازگشت) / علامت‌گذاری اسپم / حذف
 *   ③ پاسخ رسمی برند (is_brand_reply) — همان لحظه تأییدشده منتشر می‌شود
 *   ④ بازکردن دیدگاه + پاسخ‌ها در همان لیست (بدون صفحه جدا)
 *
 * چرخه: ارسال سایت برند → pending → تأیید اینجا → نمایش روی سایت برند
 *
 * @package SahandBrandMaker
 */
define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

/* 🛂 ACL سطح‌برند — مثل form-entries.php */
$_aclBrand = (int)($_GET['brand'] ?? 0);
if ($_aclBrand < 1) { $_aclBrand = (int)($_POST['brand_id'] ?? ($_POST['brand'] ?? 0)); }
if ($_aclBrand > 0) {
    (new Auth())->requireBrandAccess($_aclBrand);
}

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();

/* ════════════ اکشن‌ها ════════════ */

/* ✅ تأیید / ↩️ رد / 🚫 اسپم */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('action'), ['approve', 'unapprove', 'spam', 'delete'], true)) {
    Auth::enforceCsrf();
    $commentId = (int)post('comment_id');
    $comment = $db->fetch('SELECT * FROM article_comments WHERE id = ? LIMIT 1', [$commentId]);
    if ($comment) {
        (new Auth())->requireBrandAccess((int)$comment['brand_id']);
        switch (post('action')) {
            case 'approve':
                $db->update('article_comments', [
                    'status'      => 'approved',
                    'approved_at' => date('Y-m-d H:i:s'),
                    'approved_by' => (int)$_SESSION['user_id'],
                ], 'id = ?', [$commentId]);
                Logger::activity((int)$_SESSION['user_id'], 'تأیید دیدگاه', 'دیدگاه #' . $commentId);
                flash('success', '✅ دیدگاه تأیید و منتشر شد.');
                break;
            case 'unapprove':
                $db->update('article_comments', ['status' => 'pending', 'approved_at' => null, 'approved_by' => null], 'id = ?', [$commentId]);
                Logger::activity((int)$_SESSION['user_id'], 'رد دیدگاه', 'دیدگاه #' . $commentId);
                flash('success', '↩️ دیدگاه به حالت در انتظار بازگشت.');
                break;
            case 'spam':
                $db->update('article_comments', ['status' => 'spam'], 'id = ?', [$commentId]);
                Logger::activity((int)$_SESSION['user_id'], 'علامت‌گذاری اسپم', 'دیدگاه #' . $commentId);
                flash('success', '🚫 دیدگاه اسپم علامت‌خورد.');
                break;
            case 'delete':
                $db->delete('article_comments', 'id = ? OR parent_id = ?', [$commentId, $commentId]);
                Logger::activity((int)$_SESSION['user_id'], 'حذف دیدگاه', 'دیدگاه #' . $commentId);
                flash('success', '🗑 دیدگاه و پاسخ‌هایش حذف شد.');
                break;
        }
    }
    redirect('comments.php?status=' . rawurlencode((string)($_POST['back_status'] ?? 'pending')));
}

/* 💬 پاسخ رسمی برند — بلافاصله تأییدشده */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'reply') {
    Auth::enforceCsrf();
    $commentId = (int)post('comment_id');
    $replyBody = trim(strip_tags((string)post('reply_body')));
    $comment = $db->fetch('SELECT * FROM article_comments WHERE id = ? LIMIT 1', [$commentId]);
    if ($comment && mb_strlen($replyBody) >= 2 && mb_strlen($replyBody) <= 3000) {
        (new Auth())->requireBrandAccess((int)$comment['brand_id']);
        /* 🏷 نام پاسخ‌دهنده = برند — کاربر سایت صاحب پاسخ را می‌شناسد */
        $brand = $db->fetch('SELECT name_fa FROM brands WHERE id = ?', [(int)$comment['brand_id']]);
        $db->insert('article_comments', [
            'brand_id'      => (int)$comment['brand_id'],
            'article_id'    => (int)$comment['article_id'],
            'parent_id'     => $commentId,
            'is_brand_reply'=> 1,
            'author_name'   => mb_substr(($brand['name_fa'] ?? 'پاسخ برند'), 0, 120),
            'body'          => $replyBody,
            'status'        => 'approved',
            'created_at'    => date('Y-m-d H:i:s'),
            'approved_at'   => date('Y-m-d H:i:s'),
            'approved_by'   => (int)$_SESSION['user_id'],
        ]);
        Logger::activity((int)$_SESSION['user_id'], 'پاسخ برند به دیدگاه', 'دیدگاه #' . $commentId);
        flash('success', '💬 پاسخ برند ثبت و منتشر شد.');
    } else {
        flash('error', 'متن پاسخ باید بین ۲ تا ۳۰۰۰ نویسه باشد.');
    }
    redirect('comments.php?status=' . rawurlencode((string)($_POST['back_status'] ?? 'pending')));
}

/* 📊 آمار + فیلترها */
$status = (string)get_param('status', 'pending');
if (!in_array($status, ['pending', 'approved', 'spam'], true)) { $status = 'pending'; }
$brandFilter = (int)get_param('brand');

$where = 'c.status = ?';
$params = [$status];
if ($brandFilter > 0) { $where .= ' AND c.brand_id = ?'; $params[] = $brandFilter; }

$counts = ['pending' => 0, 'approved' => 0, 'spam' => 0];
$comments = [];
$replies = [];
try {
    foreach ($db->fetchAll("SELECT status, COUNT(*) AS c FROM article_comments GROUP BY status") as $r) {
        $counts[(string)$r['status']] = (int)$r['c'];
    }
    $comments = $db->fetchAll(
        "SELECT c.*, b.name_fa AS brand_name, a.title AS article_title, a.slug AS article_slug
         FROM article_comments c
         LEFT JOIN brands b ON b.id = c.brand_id
         LEFT JOIN brand_articles a ON a.id = c.article_id
         WHERE {$where}
         ORDER BY c.created_at DESC
         LIMIT 100",
        $params
    );
    /* پاسخ‌های برندِ همین دیدگاه‌ها — برای نمایش درجا */
    if ($comments) {
        $ids = implode(',', array_fill(0, count($comments), '?'));
        $replies = $db->fetchAll(
            "SELECT * FROM article_comments WHERE parent_id IN ($ids) AND is_brand_reply = 1 ORDER BY created_at ASC",
            array_column($comments, 'id')
        );
    }
} catch (Throwable $e) {
    /* جدول هنوز ساخته نشده — مهاجرت v2.37 با اولین لود می‌سازدش */
}

$brands = $db->fetchAll('SELECT id, name_fa FROM brands ORDER BY name_fa');
$_aclIds = (new Auth())->accessibleBrandIds();
if ($_aclIds !== null) {
    $brands = array_values(array_filter($brands, function ($_b) use ($_aclIds) {
        return in_array((int)$_b['id'], $_aclIds, true);
    }));
}
$repliesBy = [];
foreach ($replies as $r) { $repliesBy[(int)$r['parent_id']][] = $r; }

$pageTitle = 'مدیریت دیدگاه‌ها';
$activeMenu = 'comments';
require __DIR__ . '/includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card"><div class="icon bg-orange">⏳</div><div><div class="number"><?= en_to_fa_digits((string)$counts['pending']) ?></div><div class="label">در انتظار تأیید</div></div></div>
    <div class="stat-card"><div class="icon bg-green">✅</div><div><div class="number"><?= en_to_fa_digits((string)$counts['approved']) ?></div><div class="label">منتشرشده</div></div></div>
    <div class="stat-card"><div class="icon bg-red">🚫</div><div><div class="number"><?= en_to_fa_digits((string)$counts['spam']) ?></div><div class="label">اسپم</div></div></div>
    <div class="stat-card"><div class="icon bg-blue">📰</div><div><div class="number"><?= en_to_fa_digits((string)count($comments)) ?></div><div class="label">ردیف این نمایگاه</div></div></div>
</div>

<form method="get" class="card" style="padding:13px 18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
    <select name="status" class="form-control" style="max-width:190px">
        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>⏳ در انتظار تأیید</option>
        <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>✅ منتشرشده</option>
        <option value="spam" <?= $status === 'spam' ? 'selected' : '' ?>>🚫 اسپم</option>
    </select>
    <select name="brand" class="form-control" style="max-width:200px">
        <option value="">🏷️ همه برندها</option>
        <?php foreach ($brands as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= $brandFilter === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name_fa']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">🔍 اعمال فیلتر</button>
    <a href="comments.php" class="btn btn-outline">↺ همه</a>
</form>

<div class="card">
    <div class="card-header"><h3>💬 دیدگاه‌ها — <?= $status === 'pending' ? 'در انتظار تأیید' : ($status === 'approved' ? 'منتشرشده' : 'اسپم') ?></h3></div>
    <?php if (empty($comments)): ?>
        <div class="empty-state"><div class="icon">💬</div><p>در این وضعیت دیدگاهی نیست.<br><small>دیدگاه‌های ارسالی از صفحه مقالات سایت برند پس از ثبت اینجا در انتظار تأیید می‌آیند و فقط موارد تأییدشده روی سایت نمایش می‌یابند.</small></p></div>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:14px;padding:16px">
            <?php foreach ($comments as $c): ?>
            <div class="card" style="margin:0;box-shadow:0 1px 2px rgba(0,0,0,.05)">
                <div style="padding:13px 16px">
                    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:7px">
                        <div>
                            <strong style="font-size:13.5px">👤 <?= e($c['author_name']) ?></strong>
                            <?php if (!empty($c['author_email'])): ?><small style="color:#94a3b8" dir="ltr"> — <?= e($c['author_email']) ?></small><?php endif; ?>
                            <span class="badge <?= $c['status'] === 'approved' ? 'bg-green' : ($c['status'] === 'spam' ? 'bg-red' : 'bg-orange') ?>" style="margin-right:6px">
                                <?= $c['status'] === 'approved' ? 'منتشرشده' : ($c['status'] === 'spam' ? 'اسپم' : 'در انتظار') ?>
                            </span>
                        </div>
                        <small style="color:#94a3b8;font-size:11.5px"><?= e(jdate((string)$c['created_at'], true)) ?></small>
                    </div>
                    <div style="font-size:12px;color:#64748b;margin-bottom:9px">
                        🏷️ <?= e($c['brand_name'] ?? '—') ?> · 📰 <a href="articles.php?brand=<?= (int)$c['brand_id'] ?>&edit=<?= (int)$c['article_id'] ?>" style="color:inherit"><?= e($c['article_title'] ?? 'مقاله حذف‌شده') ?></a>
                        <?php if ($c['ip']): ?> · <span dir="ltr"><?= e($c['ip']) ?></span><?php endif; ?>
                    </div>
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:11px 13px;font-size:13.5px;line-height:1.9"><?= nl2br(e($c['body'])) ?></div>

                    <?php foreach ($repliesBy[(int)$c['id']] ?? [] as $rep): ?>
                    <div style="margin-top:9px;margin-right:12px;border-right:3px solid #7c3aed;background:#faf5ff;border-radius:0 9px 9px 0;padding:10px 13px;font-size:13px;line-height:1.9">
                        <strong style="font-size:12.5px">💬 <?= e($rep['author_name']) ?></strong> <span class="badge bg-purple" style="font-size:10px">پاسخ رسمی</span>
                        <div><?= nl2br(e($rep['body'])) ?></div>
                    </div>
                    <?php endforeach; ?>

                    <div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:12px">
                        <?php if ($c['status'] !== 'approved'): ?>
                        <form method="post" style="display:inline" onsubmit="return sahandSubmitConfirm(this)">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="back_status" value="<?= e($status) ?>">
                            <button type="submit" class="btn btn-success btn-sm">✅ تأیید و انتشار</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($c['status'] === 'approved'): ?>
                        <form method="post" style="display:inline" onsubmit="return sahandSubmitConfirm(this)">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="action" value="unapprove">
                            <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="back_status" value="<?= e($status) ?>">
                            <button type="submit" class="btn btn-outline btn-sm">↩️ لغو انتشار</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($c['status'] !== 'spam'): ?>
                        <form method="post" style="display:inline" onsubmit="return sahandSubmitConfirm(this)">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="action" value="spam">
                            <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="back_status" value="<?= e($status) ?>">
                            <button type="submit" class="btn btn-warning btn-sm">🚫 اسپم</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline" onsubmit="return sahandSubmitConfirm(this,'این دیدگاه و پاسخ‌هایش برای همیشه حذف شوند؟')">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="back_status" value="<?= e($status) ?>">
                            <button type="submit" class="btn btn-danger btn-sm">🗑 حذف</button>
                        </form>
                    </div>

                    <details style="margin-top:10px">
                        <summary style="cursor:pointer;font-size:12.5px;font-weight:700;color:#7c3aed">💬 پاسخ رسمی برند</summary>
                        <form method="post" style="margin-top:9px">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="action" value="reply">
                            <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="back_status" value="<?= e($status) ?>">
                            <textarea name="reply_body" rows="3" maxlength="3000" class="form-control" style="width:100%;font-size:13px" placeholder="پاسخ شما به‌عنوان <?= e($c['brand_name'] ?? 'برند') ?> — بلافاصله منتشر می‌شود" required></textarea>
                            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:7px">📨 ثبت پاسخ</button>
                        </form>
                    </details>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<div class="hint" style="margin-top:12px">💡 دیدگاه‌ها همیشه با وضعیت «در انتظار تأیید» ثبت می‌شوند و تنها موارد تأییدشده روی سایت برند نمایش می‌یابند — محدودیت ارسال: ۵ دیدگاه در ساعت به‌ازای هر IP و تشخیص خودکار لینک‌گذاری بیش‌ازحد.</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
