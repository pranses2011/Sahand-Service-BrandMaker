<?php
/**
 * 🖼️ مدیریت آیکون‌ها — پک‌ها + دسته‌بندی پوشه‌ها + جستجو + نصب/لغو نصب
 * =====================================================================
 * v2.6: نمایش همه آیکون‌ها با دسته‌بندی (پوشه‌بندی) و صفحه‌بندی —
 *       قبلاً فقط ۱۲۰ آیکون اول مانیفست نمایش داده می‌شد.
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();
$fm = new FileManager();
$downloader = new AssetDownloader();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::enforceCsrf();
    $action = post('action');

    /* ⬇️ دانلود و نصب کامل پک روی سرور — مستقیم از منبع رسمی */
    if ($action === 'install_pack_online') {
        $packSlug = post('pack_slug');
        $result = $downloader->installIconPack($packSlug, true);
        flash($result['success'] ? 'success' : 'danger', $result['message']);
        (new Cache())->delete('icon_packs_all');
        redirect('icons.php');
    }

    /* 🗑️ لغو نصب پک — حذف کامل پوشه پک از سرور */
    if ($action === 'uninstall_pack') {
        $packSlug = post('pack_slug');
        if (!preg_match('/^[a-z0-9\-]+$/', $packSlug)) {
            flash('danger', 'نام پک نامعتبر است.');
            redirect('icons.php');
        }
        $ok = $fm->deleteDir('assets/icons/' . $packSlug, 'assets/icons');
        (new Cache())->delete('icon_packs_all');
        flash($ok ? 'success' : 'danger',
            $ok ? "🗑️ پک «{$packSlug}» به‌طور کامل از سرور حذف شد — هر زمان لازم بود دوباره نصبش کنید."
                 : 'حذف پک ناموفق بود (دسترسی نوشتن را بررسی کنید).');
        redirect('icons.php');
    }

    /* 📥 ایمپورت پک ZIP — استخراج SVG ها */
    if ($action === 'import_pack' && !empty($_FILES['pack_zip']['name'])) {
        $packSlug = post('pack_slug');
        if (!preg_match('/^[a-z0-9\-]+$/', $packSlug)) {
            flash('danger', 'نام پک نامعتبر است.');
            redirect('icons.php');
        }
        $upload = $fm->uploadData($_FILES['pack_zip']);
        if (!$upload['success']) {
            flash('danger', 'خطای آپلود: ' . $upload['error']);
            redirect('icons.php');
        }
        // استخراج ZIP
        $imported = 0;
        $zip = new ZipArchive();
        if ($zip->open($upload['path']) === true) {
            $targetDir = ASSETS_PATH . '/icons/' . $packSlug;
            $fm->ensureDir($targetDir);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if (mb_strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== 'svg') {
                    continue;
                }
                $safeName = basename($entry);
                $content = $zip->getFromIndex($i);
                // 🔒 بررسی SVG امن (بدون script)
                if (stripos($content, '<script') === false && stripos($content, 'javascript:') === false) {
                    file_put_contents($targetDir . '/' . $safeName, $content);
                    $imported++;
                }
            }
            $zip->close();
            @unlink($upload['path']);
            // به‌روزرسانی مانیفست
            $manifestFile = $targetDir . '/manifest.json';
            $manifest = file_exists($manifestFile) ? json_decode((string)file_get_contents($manifestFile), true) : ['pack' => $packSlug, 'icons' => []];
            $existing = array_column($manifest['icons'] ?? [], 'name');
            foreach (glob($targetDir . '/*.svg') as $svgFile) {
                $name = basename($svgFile, '.svg');
                if (!in_array($name, $existing, true)) {
                    $manifest['icons'][] = ['name' => $name, 'label_fa' => $name, 'file' => basename($svgFile), 'category' => 'general'];
                }
            }
            file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            flash('success', "✅ {$imported} آیکون از ZIP ایمپورت شد (پک: {$packSlug}).");
        } else {
            flash('danger', 'فایل ZIP قابل بازکردن نیست.');
        }
        redirect('icons.php');
    }
}

$pageTitle = 'مدیریت آیکون‌ها';
$activeMenu = 'icons';
require __DIR__ . '/includes/header.php';

/* 📚 خواندن پک‌ها از دیتابیس یا ساخت خودکار از فایل‌ها */
$packsOnDisk = glob(ASSETS_PATH . '/icons/*', GLOB_ONLYDIR) ?: [];
$iconSourcesCfg = AssetDownloader::iconSources();
$packs = [];
foreach ($packsOnDisk as $dir) {
    $slug = basename($dir);
    $manifestFile = $dir . '/manifest.json';
    $manifest = file_exists($manifestFile) ? json_decode((string)file_get_contents($manifestFile), true) : null;
    $svgCount = count(glob($dir . '/*.svg') ?: []);
    $sourceCfg = $iconSourcesCfg[$slug] ?? [];
    $packs[] = [
        'slug' => $slug,
        'name_fa' => $manifest['name_fa'] ?? ($sourceCfg['name_fa'] ?? $slug),
        'description' => $manifest['description'] ?? ($sourceCfg['description'] ?? ''),
        'count' => $svgCount,
        'full' => $manifest['import_hint'] ?? null,
        'manifest' => $manifest,
        'has_source' => !empty($sourceCfg['archive']),
        'source_full_count' => (int)($sourceCfg['max_icons'] ?? 0),
    ];
}
usort($packs, fn($a, $b) => $b['count'] <=> $a['count']);

/* 🔎 جستجو در همه پک‌ها */
$searchIcon = get_param('q');
$searchResults = [];
if ($searchIcon !== '') {
    foreach ($packs as $pack) {
        foreach ($pack['manifest']['icons'] ?? [] as $icon) {
            if ($searchIcon === ''
                || stripos($icon['name'], $searchIcon) !== false
                || stripos($icon['label_fa'] ?? '', $searchIcon) !== false) {
                $searchResults[] = ['pack' => $pack['slug'], ...$icon];
            }
        }
    }
}

/* 🗂️ مرور پک انتخاب‌شده: دسته‌بندی + جستجوی داخل پک + صفحه‌بندی */
$browsePack = preg_match('/^[a-z0-9\-]+$/', (string)get_param('pack')) ? (string)get_param('pack') : '';
$browseCat = (string)get_param('cat');
$browseQ = trim((string)get_param('q'));
$browsePage = max(1, (int)get_param('ipage'));
$perPage = 96;
$browse = null;

if ($browsePack !== '') {
    $packDir = ASSETS_PATH . '/icons/' . $browsePack;
    if (is_dir($packDir)) {
        $manifestFile = $packDir . '/manifest.json';
        $manifest = file_exists($manifestFile) ? (json_decode((string)file_get_contents($manifestFile), true) ?: []) : [];
        $icons = array_values(array_filter(array_map(function ($ic) use ($packDir, $browsePack) {
            if (!is_array($ic) || empty($ic['file'])) {
                return null;
            }
            if (!file_exists($packDir . '/' . $ic['file'])) {
                return null;
            }
            $ic['category'] = $ic['category'] ?? 'general';
            $ic['label_fa'] = $ic['label_fa'] ?? ($ic['name'] ?? $ic['file']);
            return $ic;
        }, $manifest['icons'] ?? [])));

        // 🗂️ گروه‌بندی دسته‌ها (پوشه‌بندی) با تعداد
        $catCounts = [];
        foreach ($icons as $ic) {
            $catCounts[$ic['category']] = ($catCounts[$ic['category']] ?? 0) + 1;
        }
        uksort($catCounts, function ($a, $b) use ($catCounts) {
            if ($a === 'general') { return 1; }
            if ($b === 'general') { return -1; }
            return $catCounts[$b] <=> $catCounts[$a];
        });

        $filtered = $browseCat !== ''
            ? array_values(array_filter($icons, fn($ic) => $ic['category'] === $browseCat))
            : $icons;

        /* 🔎 جستجوی داخل همین پک (v2.7) — بدون نیاز به گردش در صفحات */
        if ($browseQ !== '') {
            $filtered = array_values(array_filter($filtered, function ($ic) use ($browseQ) {
                $catFa = iconCatFa($ic['category'] ?? '');
                return stripos($ic['name'] ?? '', $browseQ) !== false
                    || stripos($ic['label_fa'] ?? '', $browseQ) !== false
                    || stripos($ic['category'] ?? '', $browseQ) !== false
                    || stripos($catFa, $browseQ) !== false;
            }));
        }

        $totalPages = max(1, (int)ceil(count($filtered) / $perPage));
        $browsePage = min($browsePage, $totalPages);
        $pageIcons = array_slice($filtered, ($browsePage - 1) * $perPage, $perPage);

        $cfg = $iconSourcesCfg[$browsePack] ?? [];
        $browse = [
            'slug' => $browsePack,
            'name_fa' => $manifest['name_fa'] ?? ($cfg['name_fa'] ?? $browsePack),
            'description' => $manifest['description'] ?? ($cfg['description'] ?? ''),
            'icons' => $pageIcons,
            'total' => count($icons),
            'cats' => $catCounts,
            'active_cat' => $browseCat,
            'page' => $browsePage,
            'total_pages' => $totalPages,
            'has_source' => !empty($cfg['archive']),
            'q' => $browseQ,
            'filtered_count' => count($filtered),
        ];
    }
}

/** 🔗 ساخت لینک مرور با حفظ پارامترها (شامل جستجو) */
function browseUrl(string $pack, string $cat, int $page, string $q = ''): string
{
    $qs = http_build_query(['pack' => $pack, 'cat' => $cat, 'ipage' => $page, 'q' => $q]);
    return 'icons.php?' . $qs . '#browse';
}

/** 🏷️ نام فارسی دسته */
function iconCatFa(string $cat): string
{
    $map = [
        'general' => 'عمومی', 'system' => 'سیستمی', 'media' => 'رسانه‌ای', 'communication' => 'ارتباطات',
        'weather' => 'آب‌وهوا', 'home' => 'خانه', 'kitchen' => 'آشپزخانه', 'cleaning' => 'نظافت',
        'climate' => 'تهویه و سرمایش', 'laundry' => 'لباسشویی', 'sound' => 'صدا', 'security' => 'امنیت',
        'energy' => 'انرژی', 'plumbing' => 'تأسیسات', 'tools' => 'ابزار', 'device' => 'دستگاه‌ها',
        'brand' => 'برندها', 'solid' => 'توپر (Solid)', 'outline' => 'خطی (Outline)',
        'duotone' => 'دورنگ (Duotone)', 'logos' => 'لوگوها', 'maps' => 'نقشه', 'editor' => 'ویرایشگر',
        'files' => 'فایل‌ها', 'users' => 'کاربران', 'commerce' => 'تجارت', 'design' => 'طراحی',
        'food' => 'خوراکی', 'transport' => 'حمل‌ونقل', 'health' => 'سلامت', 'education' => 'آموزش',
        'finance' => 'مالی', 'gaming' => 'بازی', 'science' => 'علمی', 'nature' => 'طبیعت',
        'technology' => 'فناوری', 'travel' => 'سفر', 'photography' => 'عکاسی', 'text' => 'متن',
    ];
    return $map[$cat] ?? $cat;
}
?>
<style>
.icon-tile{text-align:center;border:1px solid var(--border);border-radius:11px;padding:13px 6px;cursor:pointer;transition:.15s;background:#fff}
.icon-tile:hover{border-color:var(--primary);transform:translateY(-2px);box-shadow:0 6px 18px rgba(37,99,235,.13)}
#iconPreviewModal{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.62);backdrop-filter:blur(4px)}
#iconPreviewModal.open{display:flex}
.icon-preview-box{background:#fff;border-radius:18px;width:min(480px,92vw);max-height:92vh;overflow:auto;padding:22px;box-shadow:0 24px 70px rgba(0,0,0,.3);animation:popIn .18s ease}
@keyframes popIn{from{transform:scale(.92);opacity:0}to{transform:scale(1);opacity:1}}
.icon-preview-stage{display:flex;align-items:center;justify-content:center;min-height:230px;border-radius:13px;background:repeating-conic-gradient(#f1f5f9 0% 25%,#fff 0% 50%) 50%/22px 22px;border:1px solid var(--border);padding:18px}
.icon-preview-stage img{filter:drop-shadow(0 4px 10px rgba(0,0,0,.18))}
.icon-preview-size{display:flex;align-items:center;gap:12px;margin:16px 0 4px}
.icon-preview-size input[type=range]{flex:1;accent-color:var(--primary);height:6px;cursor:pointer}
.icon-preview-size .size-val{min-width:64px;text-align:center;font-weight:800;color:var(--primary);background:#eff6ff;border-radius:8px;padding:5px 8px;font-size:13px;direction:ltr}
</style>

<!-- 🔍 مودال پیش‌نمایش بزرگ آیکون -->
<div id="iconPreviewModal" onclick="if(event.target===this)closeIconPreview()">
    <div class="icon-preview-box">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px">
            <div style="font-weight:800;font-size:15px" id="iconPreviewTitle">پیش‌نمایش آیکون</div>
            <button type="button" class="btn btn-outline btn-sm" onclick="closeIconPreview()">✖ بستن</button>
        </div>
        <div class="icon-preview-stage">
            <img id="iconPreviewImg" src="" alt="" style="width:128px;height:128px">
        </div>
        <div class="icon-preview-size">
            <span style="font-size:12.5px;font-weight:700;white-space:nowrap">🔍 اندازه:</span>
            <input type="range" id="iconSizeSlider" min="24" max="320" value="128" oninput="applyIconSize(this.value)">
            <span class="size-val" id="iconSizeVal">128px</span>
        </div>
        <div class="hint" style="margin:4px 0 12px">💡 اسلایدر را بکشید تا اندازه واقعی آیکون را در ابعاد مختلف ببینید (۲۴ تا ۳۲۰ پیکسل).</div>
        <div style="display:flex;flex-direction:column;gap:6px;font-size:12.5px;border-top:1px dashed var(--border);padding-top:12px">
            <div>🏷️ نام: <b id="iconPreviewLabel"></b></div>
            <div style="direction:ltr;text-align:right">🔤 <code id="iconPreviewName" dir="ltr"></code></div>
            <div>📦 پک: <b id="iconPreviewPack"></b></div>
        </div>
        <div style="display:flex;gap:8px;margin-top:14px">
            <button type="button" class="btn btn-outline btn-sm" onclick="copyIconPath()">📋 کپی مسیر</button>
            <a id="iconPreviewLink" href="#" download class="btn btn-outline btn-sm" style="text-decoration:none">⬇️ دانلود SVG</a>
        </div>
    </div>
</div>

<script>
/* 🔍 نمایش پیش‌نمایش بزرگ آیکون */
function showIconPreview(tile) {
    const src = tile.dataset.src;
    document.getElementById('iconPreviewImg').src = src;
    document.getElementById('iconPreviewTitle').textContent = tile.dataset.label || 'پیش‌نمایش آیکون';
    document.getElementById('iconPreviewLabel').textContent = tile.dataset.label || '-';
    document.getElementById('iconPreviewName').textContent = tile.dataset.name || '-';
    document.getElementById('iconPreviewPack').textContent = tile.dataset.pack || '-';
    const link = document.getElementById('iconPreviewLink');
    link.href = src;
    // ریست اسلایدر به ۱۲۸
    const slider = document.getElementById('iconSizeSlider');
    slider.value = 128;
    applyIconSize(128);
    document.getElementById('iconPreviewModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeIconPreview() {
    document.getElementById('iconPreviewModal').classList.remove('open');
    document.body.style.overflow = '';
}

function applyIconSize(px) {
    px = Math.max(24, Math.min(320, parseInt(px) || 128));
    const img = document.getElementById('iconPreviewImg');
    img.style.width = px + 'px';
    img.style.height = px + 'px';
    document.getElementById('iconSizeVal').textContent = px + 'px';
}

function copyIconPath() {
    const src = document.getElementById('iconPreviewImg').src;
    navigator.clipboard.writeText(src).then(() => alert('✅ مسیر آیکون کپی شد'));
}

/* بستن با کلید Esc */
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeIconPreview(); });
</script>

<div class="stats-grid">
    <?php foreach (array_slice($packs, 0, 6) as $pack): ?>
        <div class="stat-card">
            <div class="icon <?= $pack['slug'] === 'home-appliance-icons' ? 'bg-purple' : 'bg-blue' ?>"><?= $pack['slug'] === 'home-appliance-icons' ? '🏠' : '🖼️' ?></div>
            <div>
                <div class="number"><?= en_to_fa_digits((string)$pack['count']) ?></div>
                <div class="label"><?= e($pack['name_fa']) ?> <?= $pack['full'] ? '<small>(از ' . e($pack['full']) . ')</small>' : '' ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<form method="get" class="card" style="padding:14px 18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <input type="text" name="q" class="form-control" placeholder="🔎 جستجوی آیکون در تمام پک‌ها (نام فارسی یا انگلیسی)..." value="<?= e($searchIcon) ?>" style="flex:1;min-width:200px">
    <button type="submit" class="btn btn-primary">جستجو</button>
</form>

<?php if ($searchIcon !== ''): ?>
    <div class="card">
        <div class="card-header"><h3>🔎 نتایج جستجو «<?= e($searchIcon) ?>» (<?= en_to_fa_digits((string)count($searchResults)) ?>)</h3></div>
        <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:12px">
            <?php foreach ($searchResults as $icon): ?>
                <div class="icon-tile" onclick="showIconPreview(this)"
                     data-src="<?= BASE_URL ?>/assets/icons/<?= e($icon['pack']) ?>/<?= e($icon['file']) ?>"
                     data-label="<?= e($icon['label_fa']) ?>"
                     data-name="<?= e($icon['name']) ?>"
                     data-pack="<?= e($icon['pack']) ?>">
                    <img src="<?= BASE_URL ?>/assets/icons/<?= e($icon['pack']) ?>/<?= e($icon['file']) ?>" alt="<?= e($icon['label_fa']) ?>" style="width:34px;height:34px" loading="lazy">
                    <div style="font-size:10.5px;margin-top:7px;font-weight:700"><?= e($icon['label_fa']) ?></div>
                    <div style="font-size:9px;color:var(--text-light);direction:ltr"><?= e($icon['name']) ?></div>
                    <div style="font-size:9px;color:var(--primary);margin-top:3px"><?= e($icon['pack']) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($searchResults)): ?>
                <div class="empty-state" style="grid-column:1/-1"><div class="icon">🔍</div><p>آیکونی یافت نشد.</p></div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php foreach ($packs as $pack): ?>
    <div class="card">
        <div class="card-header">
            <h3>📦 <?= e($pack['name_fa']) ?> <span class="badge badge-info"><?= en_to_fa_digits((string)$pack['count']) ?> آیکون</span>
                <?php if ($pack['has_source'] && $pack['source_full_count'] > $pack['count']): ?>
                    <span class="badge badge-warning" title="با دکمه دانلود مستقیم، کل پک نصب می‌شود">کامل: ~<?= en_to_fa_digits((string)$pack['source_full_count']) ?></span>
                <?php endif; ?>
            </h3>
            <div class="tools" style="display:flex;gap:8px;flex-wrap:wrap">
                <?php if ($pack['has_source']): ?>
                    <form method="post" data-confirm="کل پک مستقیم روی سرور از منبع رسمی دانلود و نصب می‌شود (چند مگابایت — کمی زمان می‌برد). ادامه؟">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="action" value="install_pack_online">
                        <input type="hidden" name="pack_slug" value="<?= e($pack['slug']) ?>">
                        <button type="submit" class="btn <?= $pack['count'] > 20 ? 'btn-outline' : 'btn-success' ?> btn-sm">
                            <?= $pack['count'] > 20 ? '🔄 نصب مجدد کامل' : '⬇️ نصب کامل پک' ?>
                        </button>
                    </form>
                <?php endif; ?>
                <a href="<?= e(browseUrl($pack['slug'], '', 1)) ?>" class="btn btn-primary btn-sm">👁️ نمایش آیکون‌ها (<?= en_to_fa_digits((string)$pack['count']) ?>)</a>
                <form method="post" data-confirm="پک «<?= e($pack['name_fa']) ?>» به‌طور کامل از سرور حذف می‌شود (همه <?= en_to_fa_digits((string)$pack['count']) ?> آیکون). هر زمان خواستید می‌توانید دوباره نصبش کنید. ادامه؟">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="uninstall_pack">
                    <input type="hidden" name="pack_slug" value="<?= e($pack['slug']) ?>">
                    <button type="submit" class="btn btn-danger btn-sm">🗑️ لغو نصب</button>
                </form>
            </div>
        </div>
        <div class="card-body" style="display:none">
            <p style="font-size:12px;color:var(--text-light)"><?= e($pack['description']) ?></p>
            <div class="hint">💡 برای مرور همه آیکون‌های این پک با دسته‌بندی و صفحه‌بندی، روی «نمایش آیکون‌ها» کلیک کنید.</div>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($browse): ?>
<!-- 🗂️ مرور کامل پک: دسته‌بندی (پوشه‌ها) + صفحه‌بندی -->
<div class="card" id="browse" style="border-color:var(--primary)">
    <div class="card-header">
        <h3>🗂️ <?= e($browse['name_fa']) ?> — <?= en_to_fa_digits((string)$browse['total']) ?> آیکون</h3>
        <div class="tools">
            <a href="icons.php" class="btn btn-outline btn-sm">✖ بستن مرور</a>
        </div>
    </div>
    <div class="card-body">
        <!-- 🔎 جستجوی داخل همین پک (v2.7) -->
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px;padding:12px 14px;background:#f8fafc;border:1px solid var(--border);border-radius:11px">
            <input type="hidden" name="pack" value="<?= e($browse['slug']) ?>">
            <input type="hidden" name="cat" value="<?= e($browse['active_cat']) ?>">
            <input type="text" name="q" class="form-control" value="<?= e($browse['q']) ?>"
                   placeholder="🔎 جستجوی نام آیکون در همین پک (فارسی یا انگلیسی) — بدون گردش در صفحات..."
                   style="flex:1;min-width:200px">
            <button type="submit" class="btn btn-primary btn-sm">جستجو</button>
            <?php if ($browse['q'] !== ''): ?>
                <a href="<?= e(browseUrl($browse['slug'], $browse['active_cat'], 1)) ?>" class="btn btn-outline btn-sm">✖ پاک کردن</a>
            <?php endif; ?>
        </form>
        <?php if ($browse['q'] !== ''): ?>
            <div class="hint" style="margin:-6px 0 14px">🔎 <?= en_to_fa_digits((string)$browse['filtered_count']) ?> آیکون منطبق با «<b><?= e($browse['q']) ?></b>» از <?= en_to_fa_digits((string)$browse['total']) ?> آیکون این پک</div>
        <?php endif; ?>

        <!-- 📁 نوار پوشه‌بندی (دسته‌ها) -->
        <div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-bottom:16px;padding-bottom:14px;border-bottom:1px dashed var(--border)">
            <span style="font-size:12px;font-weight:700;color:var(--text-light)">📁 پوشه‌ها:</span>
            <a href="<?= e(browseUrl($browse['slug'], '', 1)) ?>" class="badge <?= $browse['active_cat'] === '' ? 'badge-info' : 'badge-secondary' ?>" style="font-size:11.5px;padding:5px 12px;text-decoration:none">همه (<?= en_to_fa_digits((string)$browse['total']) ?>)</a>
            <?php foreach ($browse['cats'] as $cat => $cnt): ?>
                <a href="<?= e(browseUrl($browse['slug'], $cat, 1)) ?>" class="badge <?= $browse['active_cat'] === $cat ? 'badge-info' : 'badge-secondary' ?>" style="font-size:11.5px;padding:5px 12px;text-decoration:none"><?= e(iconCatFa($cat)) ?> (<?= en_to_fa_digits((string)$cnt) ?>)</a>
            <?php endforeach; ?>
        </div>

        <!-- 🖼️ آیکون‌های صفحه جاری -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(105px,1fr));gap:10px">
            <?php foreach ($browse['icons'] as $icon): ?>
                <div class="icon-tile" onclick="showIconPreview(this)" title="<?= e($icon['label_fa']) ?> · <?= e($icon['name']) ?>"
                     data-src="<?= BASE_URL ?>/assets/icons/<?= e($browse['slug']) ?>/<?= e($icon['file']) ?>"
                     data-label="<?= e($icon['label_fa']) ?>"
                     data-name="<?= e($icon['name']) ?>"
                     data-pack="<?= e($browse['slug']) ?>">
                    <img src="<?= BASE_URL ?>/assets/icons/<?= e($browse['slug']) ?>/<?= e($icon['file']) ?>" alt="<?= e($icon['label_fa']) ?>" style="width:30px;height:30px" loading="lazy">
                    <div style="font-size:10px;margin-top:6px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($icon['label_fa']) ?></div>
                    <div style="font-size:9px;color:var(--text-light);direction:ltr;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($icon['name']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- 📄 صفحه‌بندی -->
        <?php if ($browse['total_pages'] > 1): ?>
            <div class="pagination" style="margin-top:18px">
                <?php for ($p = 1; $p <= $browse['total_pages']; $p++): ?>
                    <?php if ($p === $browse['page']): ?>
                        <span class="current"><?= en_to_fa_digits((string)$p) ?></span>
                    <?php elseif ($p <= 2 || $p > $browse['total_pages'] - 2 || abs($p - $browse['page']) <= 2): ?>
                        <a href="<?= e(browseUrl($browse['slug'], $browse['active_cat'], $p, $browse['q'])) ?>"><?= en_to_fa_digits((string)$p) ?></a>
                    <?php elseif (abs($p - $browse['page']) === 3): ?>
                        <span style="color:var(--text-light)">…</span>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
        <div class="hint" style="margin-top:10px">📄 نمایش <?= en_to_fa_digits((string)count($browse['icons'])) ?> آیکون از <?= en_to_fa_digits((string)$browse['filtered_count']) ?><?= $browse['q'] !== '' ? ' (نتیجه جستجو)' : '' ?> — صفحه <?= en_to_fa_digits((string)$browse['page']) ?> از <?= en_to_fa_digits((string)$browse['total_pages']) ?></div>
    </div>
</div>
<?php endif; ?>

<!-- 📥 ایمپورت پک -->
<div class="card">
    <div class="card-header"><h3>📥 ایمپورت پک آیکون (ZIP)</h3></div>
    <div class="card-body">
        <div class="alert alert-success" style="margin-bottom:14px">
            ⬇️ <b>راه سریع‌تر:</b> برای ۱۱ پک معروف (لوسید، فدر، تبلر، هیروآیکون، رمیکس، باکس‌آیکون، متریال، فونت‌اوسام، فسفر، آیکونویر، بوت‌استرپ) از دکمه سبز «نصب کامل پک» بالای همین صفحه استفاده کنید —
            کل پک مستقیماً روی سرور سایت‌ساز دانلود و نصب می‌شود و نیازی به دانلود ZIP روی کامپیوتر و آپلود مجدد نیست.
        </div>
        <form method="post" enctype="multipart/form-data">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="import_pack">
            <div class="form-row">
                <div class="form-group">
                    <label>نام پک (اسلاگ انگلیسی)</label>
                    <input type="text" name="pack_slug" class="form-control" style="direction:ltr;text-align:left" required placeholder="my-custom-pack">
                </div>
                <div class="form-group">
                    <label>فایل ZIP حاوی SVG</label>
                    <input type="file" name="pack_zip" class="form-control" accept=".zip" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">📤 ایمپورت</button>
            <div class="hint" style="margin-top:10px">🔒 فایل‌های SVG حاوی اسکریپت به صورت خودکار رد می‌شوند (ضد XSS).</div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
