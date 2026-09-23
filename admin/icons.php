<?php
/**
 * 🖼️ مدیریت آیکون‌ها — ۱۰ پک + جستجو + ایمپورت
 * ==============================================
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
?>
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

<form method="get" class="card" style="padding:14px 18px;display:flex;gap:10px;align-items:center">
    <input type="text" name="q" class="form-control" placeholder="🔎 جستجوی آیکون در تمام پک‌ها (نام فارسی یا انگلیسی)..." value="<?= e($searchIcon) ?>" style="flex:1">
    <button type="submit" class="btn btn-primary">جستجو</button>
</form>

<?php if ($searchIcon !== ''): ?>
    <div class="card">
        <div class="card-header"><h3>🔎 نتایج جستجو «<?= e($searchIcon) ?>» (<?= en_to_fa_digits((string)count($searchResults)) ?>)</h3></div>
        <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:12px">
            <?php foreach ($searchResults as $icon): ?>
                <div style="text-align:center;border:1px solid var(--border);border-radius:11px;padding:13px 6px">
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
                <button type="button" class="btn btn-outline btn-sm" onclick="togglePack('pack-<?= e($pack['slug']) ?>')">👁️ نمایش / مخفی</button>
            </div>
        </div>
        <div class="card-body" id="pack-<?= e($pack['slug']) ?>" style="display:none">
            <p style="font-size:12px;color:var(--text-light);margin-bottom:14px"><?= e($pack['description']) ?></p>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(105px,1fr));gap:10px;max-height:420px;overflow-y:auto;padding:4px">
                <?php foreach (array_slice($pack['manifest']['icons'] ?? [], 0, 120) as $icon): ?>
                    <?php if (!file_exists(ASSETS_PATH . '/icons/' . $pack['slug'] . '/' . $icon['file'])) { continue; } ?>
                    <div style="text-align:center;border:1px solid var(--border);border-radius:10px;padding:11px 5px">
                        <img src="<?= BASE_URL ?>/assets/icons/<?= e($pack['slug']) ?>/<?= e($icon['file']) ?>" alt="<?= e($icon['label_fa']) ?>" style="width:30px;height:30px" loading="lazy">
                        <div style="font-size:10px;margin-top:6px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($icon['label_fa']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<!-- 📥 ایمپورت پک -->
<div class="card">
    <div class="card-header"><h3>📥 ایمپورت پک آیکون (ZIP)</h3></div>
    <div class="card-body">
        <div class="alert alert-success" style="margin-bottom:14px">
            ⬇️ <b>راه سریع‌تر:</b> برای ۹ پک معروف (لوسید، فدر، تبلر، هیروآیکون، رمیکس، باکس‌آیکون، متریال، فونت‌اوسام، فسفر) از دکمه سبز «نصب کامل پک» بالای همین صفحه استفاده کنید —
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

<script>
function togglePack(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
