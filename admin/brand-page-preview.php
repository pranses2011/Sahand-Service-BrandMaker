<?php
/**
 * 👁️ پیش‌نمایش صفحه برند (v2.9)
 * =================================
 * رندر محتوای یک صفحه برند در قالبی مشابه سایت نهایی — با لوگو،
 * پالت رنگ برند و استایل RTL — برای بازبینی قبل از انتشار.
 *
 * GET: id = شناسه صفحه (brand_pages)
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$auth = new Auth();
$auth->requireLogin();

$pageId = (int)get_param('id');
$page = Database::getInstance()->fetch(
    'SELECT p.*, b.name_fa AS brand_name, b.logo AS brand_logo, b.domain AS brand_domain
     FROM brand_pages p JOIN brands b ON b.id = p.brand_id
     WHERE p.id = ?',
    [$pageId]
);
if (!$page) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    exit('<html lang="fa" dir="rtl"><body style="font-family:Tahoma;padding:40px;text-align:center">صفحه یافت نشد.</body></html>');
}

/* 📥 محتوای صفحه (JSON چندفیلدی) */
$contentData = json_decode((string)($page['content'] ?? '{}'), true) ?: [];

/* 🎨 پالت رنگ برند — v2.25: تزریق «تمام» متغیرهای واقعی پالت (دقیقاً همان
   CSS Variables سایت برند) به‌جای چیدن تک‌تک رنگ‌ها؛ به این ترتیب رنگ
   پیش‌نمایش با رنگ سایت برند «یک به یک» یکی است (رفع «رنگ پیش‌نمایش با
   سایت فرق دارد») — گرادیانت و رنگ متن دکمه هم همان مقادیر واقعی‌اند. */
$paletteRow = Database::getInstance()->fetch('SELECT * FROM color_palettes WHERE brand_id = ?', [(int)$page['brand_id']]);
$light = $paletteRow ? (json_decode($paletteRow['light_palette'] ?? '', true) ?: []) : [];
$pick = static function (array $vars, array $needles, string $fallback) {
    foreach ($needles as $n) {
        foreach ($vars as $k => $v) {
            if (stripos((string)$k, $n) !== false && preg_match('/^#[0-9a-f]{3,6}$/i', (string)$v)) {
                return $v;
            }
        }
    }
    return $fallback;
};
$primary = $pick($light, ['primary', 'accent'], '#1e40af');
$accent = $pick($light, ['accent', 'secondary'], '#f59e0b');
$bg = $pick($light, ['background', 'bg'], '#f8fafc');
$surface = $pick($light, ['surface', 'card'], '#ffffff');
$text = $pick($light, ['text'], '#1e293b');
$muted = $pick($light, ['text_light', 'muted'], '#64748b');
$border = $pick($light, ['border'], '#e2e8f0');
/* 🌈 مقادیر واقعی سایت برند */
$gradient = trim((string)($light['--gradient-primary'] ?? ''));
if ($gradient === '' || stripos($gradient, 'gradient') === false) {
    $gradient = 'linear-gradient(135deg, ' . $primary . ' 0%, ' . ($light['--color-secondary'] ?? $accent) . ' 100%)';
}
$onPrimary = trim((string)($light['--on-primary'] ?? ''));
if (!preg_match('/^#[0-9a-f]{3,6}$/i', $onPrimary)) { $onPrimary = '#ffffff'; }
$onGradient = trim((string)($light['--on-gradient'] ?? ''));
if (!preg_match('/^#[0-9a-f]{3,6}$/i', $onGradient)) { $onGradient = '#ffffff'; }

$logoUrl = $page['brand_logo'] ? asset_url((string)$page['brand_logo']) : '';
$pageTitle = trim((string)($page['title'] ?: $page['seo_title'] ?: ''));
if ($pageTitle === '') {
    $faTypes = [
        'home' => 'صفحه اصلی', 'services' => 'خدمات', 'contact' => 'تماس با ما',
        'about-agency' => 'درباره نمایندگی', 'about-brand' => 'درباره برند',
        'service-area' => 'محدوده خدمات', 'warranty' => 'ضمانت', 'blog' => 'مقالات',
        'faq' => 'سوالات متداول', 'error-codes' => 'کدهای خطا', 'request' => 'ثبت درخواست',
        'terms' => 'قوانین', 'privacy' => 'حریم خصوصی', 'sitemap-page' => 'نقشه سایت',
    ];
    $pageTitle = $faTypes[$page['page_type']] ?? ('صفحه ' . $page['page_type']);
}

/* 🧩 رندر محتوا: همه فیلدهای متنی به‌ترتیب طبیعی */
$sectionsHtml = '';
foreach ($contentData as $field => $html) {
    $html = trim((string)$html);
    if ($html === '' || !preg_match('/<(p|h[1-6]|ul|ol|table|div|figure)/i', $html)) {
        $html = '<p>' . e($html) . '</p>';
    }
    $sectionsHtml .= '<section class="pv-section">' . $html . '</section>';
}
if ($sectionsHtml === '') {
    $sectionsHtml = '<section class="pv-section pv-empty">محتوایی برای این صفحه ثبت نشده است.</section>';
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>پیش‌نمایش — <?= e($pageTitle) ?></title>
<?= preview_font_html() /* 🔤 v2.14: فونت انتخابی سیستم — مثل سایت نهایی */ ?>
<style>
:root {
    --primary: <?= e($primary) ?>;
    --accent: <?= e($accent) ?>;
    --bg: <?= e($bg) ?>;
    --surface: <?= e($surface) ?>;
    --text: <?= e($text) ?>;
    --muted: <?= e($muted) ?>;
    --border: <?= e($border) ?>;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--font-body, Vazirmatn, Tahoma, 'Segoe UI', sans-serif); background: var(--bg); color: var(--text); line-height: 2.05; font-size: 14.5px; }
/* 🔤 تیترها و عناصر تاکیدی با فونت تیتر انتخابی (مثل سایت واقعی) */
.pv-brand, .pv-cta, .pv-hero h1, .pv-section h2, .pv-section h3, .pv-section th, b { font-family: var(--font-heading, Vazirmatn, Tahoma, sans-serif); }
.pv-header { position:sticky; top:0; background:var(--surface); border-bottom:1px solid var(--border); padding:12px 22px; display:flex; align-items:center; gap:14px; z-index:5; box-shadow:0 2px 12px rgba(0,0,0,.05); }
.pv-header img { height:44px; width:auto; }
.pv-brand { font-weight:800; font-size:16px; }
.pv-nav { display:flex; gap:16px; font-size:12.5px; color:var(--muted); flex:1; flex-wrap:wrap; }
.pv-nav b { color:var(--primary); }
.pv-cta { background:var(--primary); color:<?= e($onPrimary) ?>; font-size:12px; padding:8px 16px; border-radius:9px; white-space:nowrap; font-weight:700; }
.pv-hero { background:<?= e($gradient) ?>; color:<?= e($onGradient) ?>; text-align:center; padding:44px 22px; }
.pv-hero h1 { font-size:22px; margin-bottom:8px; }
.pv-hero p { font-size:13px; opacity:.92; }
.pv-wrap { max-width:860px; margin:0 auto; padding:26px 20px 60px; }
.pv-section { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:22px 24px; margin-bottom:18px; }
.pv-section h2 { color:var(--primary); font-size:17px; margin-bottom:12px; padding-bottom:8px; border-bottom:2px solid var(--border); }
.pv-section h3 { color:var(--primary); font-size:14.5px; margin:14px 0 8px; }
.pv-section p { margin-bottom:10px; text-align:justify; }
.pv-section ul, .pv-section ol { margin:0 22px 10px 0; }
.pv-section li { margin-bottom:6px; }
.pv-section table { width:100%; border-collapse:collapse; font-size:13px; margin:10px 0; }
.pv-section th { background:var(--primary); color:#fff; padding:9px 12px; text-align:right; }
.pv-section td { border:1px solid var(--border); padding:8px 12px; }
.pv-section tr:nth-child(even) td { background:var(--bg); }
.pv-empty { text-align:center; color:var(--muted); }
.pv-badge { position:fixed; bottom:14px; right:14px; background:rgba(15,23,42,.85); color:#fff; font-size:11px; padding:7px 14px; border-radius:20px; z-index:9; backdrop-filter:blur(4px); }
</style>
</head>
<body>
<div class="pv-header">
    <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="لوگو"><?php endif; ?>
    <span class="pv-brand"><?= e((string)$page['brand_name']) ?></span>
    <nav class="pv-nav">
        <span>خانه</span><span>خدمات</span><span>مقالات</span><b><?= e($pageTitle) ?></b>
    </nav>
    <span class="pv-cta">📞 ثبت درخواست</span>
</div>
<div class="pv-hero">
    <h1><?= e($pageTitle) ?></h1>
    <p><?= e(mb_substr((string)($page['seo_description'] ?: 'پیش‌نمایش زنده محتوای صفحه برند'), 0, 160)) ?></p>
</div>
<div class="pv-wrap">
<?= $sectionsHtml ?>
</div>
<div class="pv-badge">👁 پیش‌نمایش مدیریتی — <?= e($pageTitle) ?></div>
</body>
</html>
