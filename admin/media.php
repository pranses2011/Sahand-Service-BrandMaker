<?php
/**
 * 🗃️ کتابخانه رسانه (v2.34 — P1 #13)
 * ====================================
 * فهرست گرافیکی همه تصاویر آپلودشده (مقالات / لوگوها / درخواست‌ها) با:
 *   ① اسکن و ثبت خودکار فایل‌های موجود در media_files (متادیتا: ابعاد/حجم/alt)
 *   ② فیلتر نوع + جستجوی نام/alt + مرتب‌سازی
 *   ③ ویرایش alt (دسترس‌پذیری + سئو)
 *   ④ حذف ایمن — بررسی ارجاع قبل از حذف (مقاله/صفحه/برند/تنظیمات)
 *   ⑤ جایگزینی فایل — آپلود تصویر جدید روی همان مسیر (همه ارجاع‌ها سالم می‌مانند)
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();

/** 📂 پوشه‌های قابل اسکن → نوع رسانه */
const MEDIA_DIRS = [
    'uploads/articles' => 'article',
    'uploads/logos'    => 'logo',
    'uploads/requests' => 'request',
    'uploads/misc'     => 'misc',
];

/* ════════════ اکشن‌ها ════════════ */

/* 🔍 اسکن پوشه‌ها و ثبت فایل‌های جدید */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'scan') {
    Auth::enforceCsrf();
    $added = 0;
    $updated = 0;
    foreach (MEDIA_DIRS as $dir => $kind) {
        $full = ROOT_PATH . '/' . $dir;
        if (!is_dir($full)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $fileInfo) {
            if (!$fileInfo->isFile()) { continue; }
            $ext = mb_strtolower($fileInfo->getExtension());
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'avif'], true)) { continue; }
            /* 🖼 v2.34 — نسخه‌های مشتق srcset (-400w/-800w) فایل مستقل نیستند؛
               با فایل اصلی در کتابخانه دیده/حذف می‌شوند */
            if (preg_match('/-\d{3,4}w\.[a-z0-9]+$/i', $fileInfo->getFilename()) === 1) { continue; }
            $rel = $dir . '/' . $it->getSubPathname();
            $rel = str_replace('\\', '/', $rel);
            $size = (int)$fileInfo->getSize();
            $dims = null;
            if ($ext !== 'svg') {
                $info = @getimagesize($fileInfo->getPathname());
                if (is_array($info)) {
                    $dims = ['w' => (int)$info[0], 'h' => (int)$info[1]];
                }
            } else {
                $dims = ['w' => 0, 'h' => 0];
            }
            $exists = $db->fetch('SELECT id FROM media_files WHERE path = ? LIMIT 1', [$rel]);
            if ($exists) {
                $db->update('media_files', [
                    'size'   => $size,
                    'width'  => $dims['w'],
                    'height' => $dims['h'],
                ], 'id = ?', [$exists['id']]);
                $updated++;
            } else {
                try {
                    $db->insert('media_files', [
                        'path'         => $rel,
                        'kind'         => $kind,
                        'original_name' => $fileInfo->getBasename('.' . $ext),
                        'size'         => $size,
                        'width'        => $dims['w'],
                        'height'       => $dims['h'],
                        'uploaded_by'  => $auth->userId() ?: null,
                    ]);
                    $added++;
                } catch (Throwable $e) { /* تکراری */ }
            }
        }
    }
    Logger::activity($auth->userId(), 'اسکن کتابخانه رسانه', $added . ' فایل جدید + ' . $updated . ' بروزرسانی');
    flash('success', '✅ اسکن کامل شد: ' . fa_num((string)$added) . ' فایل جدید ثبت و ' . fa_num((string)$updated) . ' فایل بروزرسانی شد.');
    redirect('media.php');
}

/* ✏️ ذخیره alt */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_alt') {
    Auth::enforceCsrf();
    $id = (int)post('media_id');
    $alt = trim((string)post('alt'));
    $row = $db->fetch('SELECT * FROM media_files WHERE id = ?', [$id]);
    if ($row) {
        $db->update('media_files', ['alt' => mb_substr($alt, 0, 250)], 'id = ?', [$id]);
        Logger::activity($auth->userId(), 'ویرایش alt رسانه', $row['path'] . ' → ' . mb_substr($alt, 0, 60));
        flash('success', '✅ متن جایگزین (alt) ذخیره شد.');
    }
    redirect('media.php' . ((string)get_param('back') !== '' ? '?q=' . urlencode((string)get_param('back')) : ''));
}

/* 🔄 جایگزینی فایل (همان مسیر — ارجاع‌ها سالم می‌مانند) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'replace') {
    Auth::enforceCsrf();
    $id = (int)post('media_id');
    $row = $db->fetch('SELECT * FROM media_files WHERE id = ?', [$id]);
    if (!$row) {
        flash('danger', 'فایل یافت نشد.');
        redirect('media.php');
    }
    if (empty($_FILES['new_file']['name'])) {
        flash('danger', 'فایلی انتخاب نکرده‌اید.');
        redirect('media.php?edit=' . $id);
    }
    $fm = new FileManager();
    $target = ROOT_PATH . '/' . $row['path'];
    $oldExt = mb_strtolower(pathinfo($target, PATHINFO_EXTENSION));
    $newExt = mb_strtolower(pathinfo((string)$_FILES['new_file']['name'], PATHINFO_EXTENSION));
    if ($newExt !== $oldExt) {
        flash('danger', "فرمت جدید ({$newExt}) باید با فرمت فعلی ({$oldExt}) یکسان باشد — تا مسیر و ارجاع‌ها دست‌نخورده بمانند.");
        redirect('media.php?edit=' . $id);
    }
    // اعتبارسنجی محتوا
    $check = getimagesize($_FILES['new_file']['tmp_name']);
    if ($check === false) {
        flash('danger', 'فایل آپلودشده تصویر معتبری نیست.');
        redirect('media.php?edit=' . $id);
    }
    if (!is_dir(dirname($target))) {
        @mkdir(dirname($target), 0755, true);
    }
    if (move_uploaded_file($_FILES['new_file']['tmp_name'], $target)) {
        @clearstatcache(true, $target);
        $size = (int)filesize($target);
        $db->update('media_files', [
            'size'   => $size,
            'width'  => (int)$check[0],
            'height' => (int)$check[1],
        ], 'id = ?', [$id]);
        Logger::activity($auth->userId(), 'جایگزینی فایل رسانه', $row['path'] . ' (' . round($size / 1024) . 'KB)');
        flash('success', '✅ فایل جایگزین شد — همه ارجاع‌ها (مقالات/صفحات/لوگوها) خودکار تصویر جدید را نشان می‌دهند.');
    } else {
        flash('danger', 'خطا در ذخیره فایل جدید (مجوز پوشه را بررسی کنید).');
    }
    redirect('media.php?edit=' . $id);
}

/* 🗑 حذف فایل (با بررسی ارجاع) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    Auth::enforceCsrf();
    $id = (int)post('media_id');
    $row = $db->fetch('SELECT * FROM media_files WHERE id = ?', [$id]);
    if (!$row) {
        flash('danger', 'فایل یافت نشد.');
        redirect('media.php');
    }
    // 🔎 بررسی ارجاع‌ها
    $refs = media_find_references($row['path']);
    if ($refs !== []) {
        $refText = implode(' + ', array_map(static fn ($r) => $r['label'] . ' (' . fa_num((string)$r['count']) . ')', $refs));
        flash('danger', '⛔ این فایل در حال استفاده است: ' . $refText . ' — ابتدا ارجاع‌ها را حذف/تغییر دهید یا از «جایگزینی» استفاده کنید.');
        redirect('media.php?edit=' . $id);
    }
    $target = ROOT_PATH . '/' . $row['path'];
    if (is_file($target) && @unlink($target)) {
        /* 🖼 v2.34 — نسخه‌های مشتق srcset هم پاک شوند (یتیم نمانند) */
        $info = pathinfo($target);
        foreach ([400, 800] as $wv) {
            $variant = $info['dirname'] . '/' . $info['filename'] . '-' . $wv . 'w.' . $info['extension'];
            if (is_file($variant)) {
                @unlink($variant);
            }
        }
        $db->delete('media_files', 'id = ?', [$id]);
        Logger::activity($auth->userId(), 'حذف فایل رسانه', $row['path']);
        flash('success', '✅ فایل حذف شد.');
    } else {
        $db->delete('media_files', 'id = ?', [$id]);
        flash('warning', '⚠️ رکورد حذف شد اما فایل فیزیکی پاک نشد (مجوز) — مسیر: ' . $row['path']);
    }
    redirect('media.php');
}

/**
 * 🔎 ارجاع‌های یک مسیر فایل در محتوا
 * @return array [[label, count], ...]
 */
function media_find_references(string $path): array
{
    $db = Database::getInstance();
    $refs = [];
    $like = '%' . $path . '%';

    $c = (int)$db->fetchValue('SELECT COUNT(*) FROM brand_articles WHERE featured_image = ? OR og_image = ?', [$path, $path]);
    if ($c > 0) { $refs[] = ['label' => 'تصویر شاخص مقالات', 'count' => $c]; }

    $c = (int)$db->fetchValue('SELECT COUNT(*) FROM brand_articles WHERE content LIKE ?', [$like]);
    if ($c > 0) { $refs[] = ['label' => 'داخل متن مقالات', 'count' => $c]; }

    $c = (int)$db->fetchValue('SELECT COUNT(*) FROM brands WHERE logo = ? OR favicon = ?', [$path, $path]);
    if ($c > 0) { $refs[] = ['label' => 'لوگو/فاویکون برند', 'count' => $c]; }

    $c = (int)$db->fetchValue('SELECT COUNT(*) FROM brand_pages WHERE content LIKE ? OR layout_json LIKE ?', [$like, $like]);
    if ($c > 0) { $refs[] = ['label' => 'صفحات برند', 'count' => $c]; }

    $c = (int)$db->fetchValue('SELECT COUNT(*) FROM settings WHERE setting_value LIKE ?', [$like]);
    if ($c > 0) { $refs[] = ['label' => 'تنظیمات سیستم', 'count' => $c]; }

    return $refs;
}

/* ════════════ داده‌ها و فیلترها ════════════ */
$kindFilter = (string)get_param('kind');
if (!in_array($kindFilter, ['article', 'logo', 'request', 'misc', ''], true)) {
    $kindFilter = '';
}
$q = trim((string)get_param('q'));
$p = max(1, (int)get_param('p'));
$perPage = 36;

$where = '1=1';
$params = [];
if ($kindFilter !== '') {
    $where .= ' AND kind = ?';
    $params[] = $kindFilter;
}
if ($q !== '') {
    $where .= ' AND (path LIKE ? OR alt LIKE ? OR original_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$total = (int)$db->fetchValue("SELECT COUNT(*) FROM media_files WHERE {$where}", $params);
$pages = max(1, (int)ceil($total / $perPage));
$p = min($p, $pages);
$offset = ($p - 1) * $perPage;
$media = $db->fetchAll("SELECT * FROM media_files WHERE {$where} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}", $params);

/* آمار کلی */
$stats = [];
foreach ($db->fetchAll('SELECT kind, COUNT(*) AS c, COALESCE(SUM(size),0) AS s FROM media_files GROUP BY kind') as $st) {
    $stats[$st['kind']] = $st;
}
$totalSize = (int)($db->fetchValue('SELECT COALESCE(SUM(size),0) FROM media_files') ?: 0);

$editMedia = null;
$editRefs = [];
if (($_GET['edit'] ?? '') !== '') {
    $editMedia = $db->fetch('SELECT * FROM media_files WHERE id = ?', [(int)$_GET['edit']]);
    if ($editMedia) {
        $editRefs = media_find_references((string)$editMedia['path']);
    }
}

$kindLabels = ['article' => '📰 مقالات', 'logo' => '🏷️ لوگوها', 'request' => '📨 درخواست‌ها', 'misc' => '📦 متفرقه'];

$pageTitle = 'کتابخانه رسانه';
$activeMenu = 'media';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1>🗃️ کتابخانه رسانه</h1>
    <p class="page-header-desc">همه تصاویر سایت‌ساز یک‌جا — متادیتا، متن جایگزین (alt)، جایگزینی و حذف ایمن</p>
</div>

<?php if ($editMedia): ?>
    <!-- ✏️ پنل ویرایش یک رسانه -->
    <div class="card" style="margin-bottom:18px;border-inline-start:4px solid #8b5cf6">
        <div style="padding:20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:22px;align-items:start">
            <div style="text-align:center">
                <img src="<?= e(asset_url((string)$editMedia['path'])) ?>" alt="<?= e($editMedia['alt'] ?? '') ?>"
                     style="max-width:100%;max-height:260px;border-radius:12px;border:1px solid var(--border);background:#f8fafc">
                <div style="font-size:11.5px;color:var(--text-light);margin-top:8px;direction:ltr;word-break:break-all"><?= e($editMedia['path']) ?></div>
            </div>
            <div>
                <div style="font-size:12.5px;color:var(--text-light);line-height:2.1;margin-bottom:12px">
                    📐 <?= $editMedia['width'] ? e(fa_num($editMedia['width'] . '×' . $editMedia['height'])) : '—' ?> پیکسل ·
                    💾 <?= e(fa_num((string)round((int)$editMedia['size'] / 1024))) ?> کیلوبایت ·
                    📅 <?= e(fa_num(jdate('Y/m/d', strtotime((string)$editMedia['created_at'])))) ?>
                </div>

                <?php if ($editRefs !== []): ?>
                    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 14px;font-size:12.5px;line-height:2;margin-bottom:14px">
                        ⛔ <b>در حال استفاده است:</b><br>
                        <?php foreach ($editRefs as $r): ?>
                            • <?= e($r['label']) ?> (<?= e(fa_num((string)$r['count'])) ?> مورد)<br>
                        <?php endforeach; ?>
                        <span style="color:#991b1b">حذف ممکن نیست — از «جایگزینی» برای تعویض تصویر بدون شکستن ارجاع‌ها استفاده کنید.</span>
                    </div>
                <?php else: ?>
                    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:10px 14px;font-size:12.5px;margin-bottom:14px">
                        ✅ هیچ ارجاعی به این فایل نیست — حذف امن است.
                    </div>
                <?php endif; ?>

                <!-- ✏️ alt -->
                <form method="post" style="margin-bottom:14px">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="save_alt">
                    <input type="hidden" name="media_id" value="<?= (int)$editMedia['id'] ?>">
                    <label style="font-size:13px;font-weight:700;display:block;margin-bottom:6px">✏️ متن جایگزین (alt) — سئو و دسترس‌پذیری</label>
                    <input type="text" name="alt" value="<?= e($editMedia['alt'] ?? '') ?>" placeholder="مثلاً: تکنسین در حال تعمیر لباسشویی سامسونگ" style="width:100%;box-sizing:border-box">
                    <button type="submit" class="btn btn-primary btn-sm" style="margin-top:8px">💾 ذخیره alt</button>
                </form>

                <!-- 🔄 جایگزینی -->
                <form method="post" enctype="multipart/form-data" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px;margin-bottom:14px">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="replace">
                    <input type="hidden" name="media_id" value="<?= (int)$editMedia['id'] ?>">
                    <label style="font-size:13px;font-weight:700;display:block;margin-bottom:6px">🔄 جایگزینی فایل (همان مسیر می‌ماند)</label>
                    <input type="file" name="new_file" accept=".<?= e(mb_strtolower(pathinfo((string)$editMedia['path'], PATHINFO_EXTENSION))) ?>" required style="font-size:12.5px;width:100%;box-sizing:border-box">
                    <button type="submit" class="btn btn-info btn-sm" style="margin-top:8px">⬆️ جایگزین کن</button>
                </form>

                <!-- 🗑 حذف -->
                <?php if ($editRefs === []): ?>
                    <form method="post" onsubmit="return confirm('فایل «<?= e($editMedia['original_name'] ?? basename((string)$editMedia['path'])) ?>» برای همیشه حذف شود؟')">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="media_id" value="<?= (int)$editMedia['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">🗑 حذف قطعی</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- 🔍 نوار ابزار -->
<div class="card" style="margin-bottom:16px">
    <div style="padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;flex:1">
            <select name="kind" style="width:auto" onchange="this.form.submit()">
                <option value="">همه انواع (<?= e(fa_num((string)$total)) ?>)</option>
                <?php foreach ($kindLabels as $k => $lbl): ?>
                    <option value="<?= e($k) ?>" <?= $kindFilter === $k ? 'selected' : '' ?>><?= e($lbl) ?> (<?= e(fa_num((string)($stats[$k]['c'] ?? 0))) ?>)</option>
                <?php endforeach; ?>
            </select>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="جستجوی نام فایل یا alt…" style="width:220px">
            <button type="submit" class="btn btn-outline btn-sm">🔍</button>
        </form>
        <div style="font-size:12px;color:var(--text-light)">
            💾 کل: <?= e(fa_num((string)round($totalSize / 1048576, 1))) ?> مگابایت
        </div>
        <form method="post">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="scan">
            <button type="submit" class="btn btn-info btn-sm" title="فایل‌های پوشه uploads را پیدا و ثبت می‌کند">🔄 اسکن پوشه‌ها</button>
        </form>
    </div>
</div>

<?php if ($media === []): ?>
    <div class="card">
        <div style="padding:50px 20px;text-align:center;color:var(--text-light)">
            <div style="font-size:52px;margin-bottom:12px">🗃️</div>
            <?php if ($total === 0): ?>
                هنوز فایلی ثبت نشده است — روی «🔄 اسکن پوشه‌ها» بزنید تا تصاویر موجود در uploads پیدا و ثبت شوند.
            <?php else: ?>
                نتیجه‌ای برای فیلتر فعلی نیست.
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:14px">
        <?php foreach ($media as $m): ?>
            <a href="media.php?edit=<?= (int)$m['id'] ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="media-card" style="text-decoration:none;background:var(--card,#fff);border:1px solid var(--border);border-radius:14px;overflow:hidden;display:block;transition:border-color .18s,transform .18s" onmouseover="this.style.borderColor='#3b82f6';this.style.transform='translateY(-2px)'" onmouseout="this.style.borderColor='var(--border)';this.style.transform='none'">
                <div style="height:130px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;overflow:hidden">
                    <img src="<?= e(asset_url((string)$m['path'])) ?>" alt="<?= e($m['alt'] ?? '') ?>" loading="lazy" style="width:100%;height:100%;object-fit:cover">
                </div>
                <div style="padding:9px 11px">
                    <div style="font-size:11.5px;font-weight:700;direction:ltr;text-align:left;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text)"><?= e(basename((string)$m['path'])) ?></div>
                    <div style="font-size:10.5px;color:var(--text-light);margin-top:3px;display:flex;justify-content:space-between">
                        <span><?= e(explode(' ', $kindLabels[$m['kind']] ?? '?')[1] ?? '') ?></span>
                        <span><?= $m['width'] ? e(fa_num($m['width'] . '×' . $m['height'])) : '' ?> · <?= e(fa_num((string)round((int)$m['size'] / 1024))) ?>KB</span>
                    </div>
                    <?php if (!empty($m['alt'])): ?>
                        <div style="font-size:10px;color:#059669;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($m['alt']) ?>">✓ alt: <?= e($m['alt']) ?></div>
                    <?php else: ?>
                        <div style="font-size:10px;color:#d97706;margin-top:3px">⚠ بدون alt</div>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
        <nav style="display:flex;gap:7px;justify-content:center;margin-top:22px;flex-wrap:wrap">
            <?php for ($i = 1; $i <= min($pages, 12); $i++): ?>
                <a href="?p=<?= $i ?><?= $kindFilter !== '' ? '&kind=' . e($kindFilter) : '' ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
                   style="padding:8px 14px;border-radius:9px;font-size:13px;text-decoration:none;<?= $i === $p ? 'background:#1d4ed8;color:#fff' : 'border:1px solid var(--border);color:var(--text)' ?>"><?= e(fa_num((string)$i)) ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<style>.btn-sm{padding:5px 11px;font-size:12px}</style>
<?php require __DIR__ . '/includes/footer.php'; ?>
