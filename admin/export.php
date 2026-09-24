<?php
/**
 * 📦 خروجی و استقرار — تولید فایل ZIP سایت برند
 * ==============================================
 * جایگذاری متغیرها (برند، کلید API، پالت رنگ) در هسته
 * + تولید sitemap.xml و robots.txt خودکار + دانلود
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();

/* 📤 درخواست دانلود */
if (($_GET['download'] ?? '') !== '') {
    $file = UPLOADS_PATH . '/temp/' . basename($_GET['download']);
    if (file_exists($file) && pathinfo($file, PATHINFO_EXTENSION) === 'zip') {
        Logger::activity((int)$_SESSION['user_id'], 'دانلود ZIP سایت', basename($file));
        ZipGenerator::download($file, basename($file));
    }
    flash('danger', 'فایل یافت نشد یا منقضی شده — دوباره تولید کنید.');
    redirect('export.php');
}

/* 🏗️ تولید ZIP */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'build_zip') {
    Auth::enforceCsrf();
    $brandId = (int)post('brand_id');
    $brand = $db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);

    if (!$brand) {
        flash('danger', 'برند یافت نشد.');
        redirect('export.php');
    }
    if ($brand['status'] === 'draft') {
        flash('warning', 'ابتدا فرآیند ساخت سایت این برند را کامل کنید.');
        redirect('brand-build.php?id=' . $brandId);
    }

    // 🎨 تولید CSS تم از پالت رنگ برند
    $palette = $db->fetch('SELECT light_palette, dark_palette FROM color_palettes WHERE brand_id = ?', [$brandId]);
    $colorAnalyzer = new ColorAnalyzer();
    $lightCss = $palette ? $colorAnalyzer->toCss(json_decode($palette['light_palette'], true) ?: [], ':root') : '';
    $darkCss = $palette ? $colorAnalyzer->toCss(json_decode($palette['dark_palette'], true) ?: [], ':root') : '';

    // 🔗 متغیرهای جایگذاری در قالب
    $paletteRow = $db->fetch('SELECT light_palette FROM color_palettes WHERE brand_id = ?', [$brandId]);
    $lightPaletteData = $paletteRow ? (json_decode($paletteRow['light_palette'], true) ?: []) : [];
    $themeColor = (string)($lightPaletteData['--color-primary'] ?? '#1e40af');
    $replacements = [
        '{{BRAND_ID}}'        => (string)$brand['id'],
        '{{BRAND_API_KEY}}'   => (string)$brand['api_key'],
        '{{BRAND_DOMAIN}}'    => (string)$brand['domain'],
        '{{BRAND_NAME_FA}}'   => $brand['name_fa'],
        '{{BRAND_NAME_EN}}'   => $brand['name_en'],
        '{{BRANDMAKER_URL}}'  => BASE_URL,
        '{{PALETTE_LIGHT_CSS}}' => $lightCss,
        '{{PALETTE_DARK_CSS}}'  => $darkCss,
        '{{THEME_COLOR}}'     => $themeColor,
        '{{BRAND_LOGO}}'      => $brand['logo'] ? (strpos($brand['logo'], 'http') === 0 ? $brand['logo'] : BASE_URL . '/' . $brand['logo']) : '',
    ];

    // 📦 بسته‌بندی هسته
    $zip = new ZipGenerator();
    $zip->setReplacements($replacements);
    $zipName = $brand['slug'] . '-site-' . date('Ymd-His');
    $result = $zip->package(
        dirname(__DIR__) . '/templates/brand-core',
        UPLOADS_PATH . '/temp',
        $zipName
    );

    if (!$result['success']) {
        flash('danger', 'خطا در تولید ZIP: ' . $result['error']);
        redirect('export.php');
    }

    // 🗺️ افزودن sitemap.xml خودکار (الزام سند)
    $sitemapXml = buildSitemapXml($db, $brand);
    addFileToZip($result['path'], 'sitemap.xml', $sitemapXml);

    // 🤖 افزودن robots.txt خودکار (الزام سند)
    $robotsTxt = "# robots.txt خودکار — سایت " . $brand['name_fa'] . "\n"
        . "User-agent: *\nAllow: /\nDisallow: /cache/\nDisallow: /includes/\n"
        . "Sitemap: https://" . $brand['domain'] . "/sitemap.xml\n";
    addFileToZip($result['path'], 'robots.txt', $robotsTxt);

    Logger::activity((int)$_SESSION['user_id'], 'تولید ZIP سایت', $brand['name_fa'] . ' — ' . basename($result['path']));
    flash('success', '✅ فایل ZIP سایت «' . $brand['name_fa'] . '» آماده دانلود است.');
    redirect('export.php?generated=' . urlencode(basename($result['path'])));
}

/**
 * 🗺️ ساخت sitemap.xml کامل برند
 */
function buildSitemapXml(Database $db, array $brand): string
{
    $domain = 'https://' . $brand['domain'];
    $urls = [
        ['loc' => $domain . '/', 'priority' => '1.0', 'changefreq' => 'weekly'],
    ];
    // صفحات فعال
    $pages = $db->fetchAll("SELECT page_type FROM brand_pages WHERE brand_id = ? AND is_active = 1", [$brand['id']]);
    foreach ($pages as $page) {
        if (in_array($page['page_type'], ['home', 'sitemap-page'], true)) {
            continue;
        }
        $urls[] = ['loc' => $domain . '/' . $page['page_type'], 'priority' => '0.8', 'changefreq' => 'monthly'];
    }
    // مقالات منتشرشده
    $articles = $db->fetchAll(
        "SELECT slug, published_at FROM brand_articles WHERE brand_id = ? AND status = 'published'",
        [$brand['id']]
    );
    foreach ($articles as $article) {
        $urls[] = [
            'loc' => $domain . '/blog/article?slug=' . rawurlencode($article['slug']),
            'priority' => '0.6',
            'lastmod' => substr((string)$article['published_at'], 0, 10),
        ];
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $url) {
        $xml .= "  <url>\n    <loc>" . htmlspecialchars($url['loc'], ENT_XML1) . "</loc>\n";
        if (!empty($url['lastmod'])) {
            $xml .= "    <lastmod>{$url['lastmod']}</lastmod>\n";
        }
        $xml .= "    <changefreq>" . ($url['changefreq'] ?? 'monthly') . "</changefreq>\n";
        $xml .= "    <priority>{$url['priority']}</priority>\n  </url>\n";
    }
    $xml .= '</urlset>';
    return $xml;
}

/**
 * ➕ افزودن فایل متنی به ZIP موجود
 */
function addFileToZip(string $zipPath, string $entryName, string $content): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) === true) {
        $zip->addFromString($entryName, $content);
        $zip->close();
    }
}

$pageTitle = 'خروجی و استقرار';
$activeMenu = 'export';
require __DIR__ . '/includes/header.php';

$brands = $db->fetchAll("SELECT id, name_fa, name_en, domain, status, logo FROM brands ORDER BY name_fa");
$generatedFile = get_param('generated');

// 🧹 پاکسازی ZIP های قدیمی‌تر از ۱ ساعت
(new ZipGenerator())->cleanupOld(UPLOADS_PATH . '/temp');
$existingZips = array_map('basename', glob(UPLOADS_PATH . '/temp/*.zip') ?: []);
?>
<?php if ($generatedFile !== ''): ?>
    <div class="alert alert-success" style="display:flex;align-items:center;gap:14px">
        <span style="font-size:30px">📦</span>
        <div style="flex:1">
            <b>فایل ZIP آماده است!</b><br>
            <code style="direction:ltr;display:inline-block"><?= e($generatedFile) ?></code>
        </div>
        <a href="?download=<?= e($generatedFile) ?>" class="btn btn-success btn-lg">⬇️ دانلود</a>
    </div>
<?php endif; ?>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>📦 تولید فایل ZIP سایت برند</h3></div>
        <div class="card-body">
            <form method="post">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="build_zip">
                <div class="form-group">
                    <label>انتخاب برند</label>
                    <select name="brand_id" class="form-control" required>
                        <option value="">— انتخاب برند —</option>
                        <?php foreach ($brands as $brand): ?>
                            <option value="<?= (int)$brand['id'] ?>" <?= $brand['status'] === 'draft' ? 'disabled' : '' ?>>
                                <?= e($brand['name_fa']) ?> — <?= e($brand['domain']) ?><?= $brand['status'] === 'draft' ? ' (تکمیل نشده)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-lg btn-block">🏗️ تولید ZIP</button>
            </form>
            <div class="hint" style="margin-top:12px">
                خروجی شامل: هسته کامل PHP (۳۰+ فایل) + متغیرهای جایگذاری‌شده (کلید API، برند، پالت رنگ)
                + sitemap.xml و robots.txt خودکار + کامنت‌های فارسی
            </div>
            <?php if (!empty(PathResolver::getSettings()['deploy_enabled'])): ?>
            <div style="margin-top:14px;padding:12px;background:linear-gradient(135deg,#eff6ff,#f0fdf4);border:1px dashed #93c5fd;border-radius:10px">
                <b>🚀 استقرار خودکار در دسترس است!</b><br>
                <small style="color:var(--text-light)">بدون دانلود ZIP و ورود به cPanel — زیردامنه + آپلود + SSL خودکار:</small>
                <a href="deploy.php" class="btn btn-primary btn-sm" style="margin-top:8px">🚀 رفتن به استقرار خودکار</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>🚀 راهنمای استقرار روی هاست</h3></div>
        <div class="card-body" style="font-size:13px;line-height:2.3">
            <ol style="margin-inline-start:18px">
                <li>فایل ZIP را دانلود کنید</li>
                <li>در cPanel → File Manager پوشه دامنه/زیردامنه برند را باز کنید</li>
                <li>فایل ZIP را آپلود و همان‌جا <b>Extract</b> کنید</li>
                <li>مطمئن شوید زیردامنه (مثلاً <code>samsung.ea-fixer.ir</code>) به همین پوشه اشاره می‌کند</li>
                <li>SSL را از بخش SSL/T Status فعال کنید</li>
                <li>تمام! سایت برند به دیتابیس سایت ساز متصل است</li>
            </ol>
            <div class="alert alert-info" style="font-size:12px">
                💡 تصاویر، آیکون‌ها و فونت‌ها از سرور سایت ساز فراخوانی می‌شوند — نیازی به آپلود مجدد ندارند.
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>📁 فایل‌های ZIP موجود (اختیاری — ۱ ساعت اعتبار)</h3></div>
    <div class="table-wrap">
        <?php if (empty($existingZips)): ?>
            <div class="empty-state" style="padding:26px"><p>فایلی موجود نیست — یک ZIP جدید تولید کنید.</p></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>فایل</th><th>حجم</th><th>تاریخ</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($existingZips as $zipName): ?>
                <?php $zipPath = UPLOADS_PATH . '/temp/' . $zipName; ?>
                <tr>
                    <td><code style="direction:ltr;display:inline-block;font-size:11.5px"><?= e($zipName) ?></code></td>
                    <td><?= human_filesize((int)filesize($zipPath)) ?></td>
                    <td style="font-size:11.5px;color:var(--text-light)"><?= jdate(date('Y-m-d H:i:s', filemtime($zipPath)), true) ?></td>
                    <td><a href="?download=<?= e($zipName) ?>" class="btn btn-success btn-sm">⬇️ دانلود</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
