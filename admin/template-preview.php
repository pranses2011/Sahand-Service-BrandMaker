<?php
/**
 * 👁️ پیش‌نمایش زنده قالب — آغازگر حالت preview (P2-21)
 * =====================================================================
 * درون iframe قالب‌ساز نمایش داده می‌شود؛ GET:
 *   id=    شناسه قالب (اختیاری — از دیتابیس)
 *   json=  چیدمان JSON خام (اختیاری — پیش‌نمایش ذخیره‌نشده)
 *   block= یک بلوک تکی برای پیش‌نمایش (اختیاری)
 *
 * 🆕 v2.36 / P2-21: تمام منطق رندر در «هسته رندرگر واحد» است
 * (templates/brand-core/includes/block-renderer-core.php) — همان کدی
 * که سایت برند واقعی هم اجرا می‌کند؛ پیش‌نمایش دیگر آینه جدا ندارد.
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$auth = new Auth();
$auth->requireLogin();
/* 🛡️ v2.33 — دفاع در عمق: رد درخواست بین‌سایتی (گزارش تحلیل — بخش امنیت) */
reject_cross_origin();

/* 📥 چیدمان از یکی از سه منبع */
$layout = [];
$templateId = (int)get_param('id');
$rawJson = (string)get_param('json', '');
$singleBlock = (string)get_param('block', '');

if ($templateId > 0) {
    $tpl = Database::getInstance()->fetch('SELECT layout_json FROM templates WHERE id = ?', [$templateId]);
    $layout = $tpl ? (json_decode($tpl['layout_json'] ?? '[]', true) ?: []) : [];
} elseif ($rawJson !== '') {
    $decoded = json_decode($rawJson, true);
    $layout = is_array($decoded) ? $decoded : [];
} elseif ($singleBlock !== '') {
    $layout = [['block' => $singleBlock, 'props' => [], 'order' => 0]];
}

/* 🆕 v2.25: تنظیمات صفحه — گره مخفی «_page» جدا و به‌صورت متغیرهای CSS
   روی body اعمال می‌شود (زمینه/فاصله‌ها/عرض/گردی — همان بوم قالب‌ساز) */
$pageProps = [];
if (!empty($layout) && is_array($layout[0]) && ($layout[0]['block'] ?? '') === '_page') {
    $pageProps = is_array($layout[0]['props'] ?? null) ? $layout[0]['props'] : [];
    array_shift($layout);
}

/* ⭐ عناصر شخصی از دیتابیس (برای بلوک pelement) */
$PERSONAL_ELEMENTS = [];
try {
    foreach (Database::getInstance()->fetchAll('SELECT id, name, html, css FROM personal_elements') as $peRow) {
        $PERSONAL_ELEMENTS[(int)$peRow['id']] = ['name' => (string)$peRow['name'], 'html' => (string)$peRow['html'], 'css' => (string)$peRow['css']];
    }
} catch (Throwable $peDbE) {
    $PERSONAL_ELEMENTS = [];
}

/* 🧱 هسته رندرگر واحد + حالت preview (داده نمونه، بدون API برند) */
require_once dirname(__DIR__) . '/templates/brand-core/includes/block-renderer-core.php';
pv_renderer_init(['mode' => 'preview', 'pelements' => $PERSONAL_ELEMENTS]);

$pageStyle = pv_page_css_vars($pageProps);

/* 🔄 توابع سازگاری — کدهای قدیمی و شل HTML پایین */
if (!function_exists('renderPreviewBlock')) {
    function renderPreviewBlock(string $block, array $props = []): string
    {
        return pv_render_block($block, $props);
    }
}
if (!function_exists('renderPreviewBlockInner')) {
    function renderPreviewBlockInner(string $block, array $props = []): string
    {
        return pv_render_block_inner($block, $props);
    }
}
if (!function_exists('renderLayoutLevel')) {
    function renderLayoutLevel(array $items): string
    {
        return pv_render_layout($items);
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>پیش‌نمایش قالب</title>
<?= preview_font_html() /* 🔤 v2.14: فونت انتخابی سیستم — مثل سایت نهایی */ ?>
<style>
:root {
    --p: #1e40af; --p-light: #dbeafe; --s: #0ea5e9; --a: #f59e0b;
    --bg: #f8fafc; --card: #fff; --text: #1e293b; --muted: #64748b; --border: #e2e8f0;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--font-body, Vazirmatn, Tahoma, 'Segoe UI', sans-serif); background: var(--bg); color: var(--text); line-height: 1.95; font-size: 14px; }
/* 🔤 تیترها و عناصر تاکیدی با فونت تیتر انتخابی (مثل سایت واقعی) */
.blk-title, .hero-title, .card-t, .page-title, .topbar-blk, .notif-bar, .price-row b, .stat-n, .cta-num, .story-year, .num-n, .fake-cta, .hero-btn { font-family: var(--font-heading, Vazirmatn, Tahoma, sans-serif); }
.preview-wrap { max-width: 100%; margin: 0 auto; }

/* 🆕 v2.32 — تایپوگرافی صفحه */
body, .preview-wrap { color: var(--pg-body, inherit); line-height: var(--pg-lh, inherit); }
a { color: var(--pg-link, inherit); }
.blk-title { font-size: var(--pg-title-size, 17px); }
/* 🆕 v2.32 — تکمیل تنظیمات عناصر: رنگ متن بدنه + زمینه دلخواه + فاصله اختصاصی + گردی + نمایش انتخابی */
.blk { color: var(--blk-txt, inherit); background-color: var(--blk-bg, transparent); margin-top: var(--blk-mt, 0); margin-bottom: var(--blk-mb, 0); }
.blk-rad-sharp { border-radius: 0 !important; }
.blk-rad-round { border-radius: 22px !important; }
.blk-rad-pill { border-radius: 34px !important; }
@media (max-width: 768px) { .blk-hide-mobile { display: none !important; } }
@media (min-width: 769px) { .blk-hide-desktop { display: none !important; } }

/* بلوک‌ها */
.blk { background: var(--card); padding: 26px 20px; border-bottom: 1px dashed var(--border); }
.blk:last-child { border-bottom: none; }
.blk-pad-compact { padding: 14px 16px; }
.blk-pad-roomy { padding: 44px 26px; }
.blk-pad-none { padding: 0; }
.blk-bg-surface { background: #f1f5f9; }
.blk-bg-primary { background: linear-gradient(135deg, var(--p), var(--s)); color: #fff; }
.blk-bg-primary .blk-title, .blk-bg-primary .card-t, .blk-bg-gradient .blk-title { color: #fff; }
.blk-bg-gradient { background: linear-gradient(135deg, var(--p) 0%, var(--s) 60%, var(--a) 100%); color: #fff; }
.blk-bg-dark { background: #0f172a; color: #e2e8f0; }
.blk-bg-dark .blk-title { color: #fff; }
.blk-title { font-size: 16px; font-weight: 800; margin-bottom: 16px; text-align: center; }
.blk-hidden { text-align: center; padding: 14px; color: var(--muted); font-size: 12px; background: repeating-linear-gradient(45deg, #f8fafc, #f8fafc 10px, #f1f5f9 10px, #f1f5f9 20px); }

/* ═══════════════════════════════════════════════════════════════
   🎭 v2.26 — ظواهر متعدد عناصر (blk-var-* | blk-btn-* | blk-hover-*)
   آینه همان قوانین در بوم قالب‌ساز — هر تغییر، دوجا اعمال شود
   ═══════════════════════════════════════════════════════════════ */
/* — ظاهر کلی بدنه — */
.blk.blk-var-glass {
    background: rgba(255,255,255,.55); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(255,255,255,.75); box-shadow: 0 8px 28px rgba(2,8,23,.10);
}
.blk.blk-var-card { background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 14px 38px rgba(2,8,23,.13); }
.blk.blk-var-flat { background: transparent; box-shadow: none !important; border: none; }
.blk.blk-var-outline { background: transparent; border: 2px solid #2563eb; box-shadow: none !important; }
.blk.blk-var-soft { background: linear-gradient(135deg, #eff6ff, #e0f2fe); border: 1px solid #bfdbfe; }
.blk.blk-var-dark { background: #0f172a; color: #e2e8f0; }
.blk.blk-var-dark .blk-title, .blk.blk-var-dark .card-t { color: #f1f5f9; }
.blk.blk-var-dark .pv-text, .blk.blk-var-dark .feat-d { color: #cbd5e1; }
.blk.blk-var-dark .fake-card { background: #1e293b; border-color: #334155; }
.blk.blk-var-hardshadow { background: #fef9c3; border: 2.5px solid #1e293b; box-shadow: 7px 7px 0 #1e293b !important; }
.blk.blk-var-dashed { background: rgba(255,255,255,.6); border: 2px dashed #94a3b8; box-shadow: none !important; }
.blk.blk-var-ribbon { border-inline-start: 6px solid #f59e0b; background: #fffbeb; box-shadow: 0 4px 16px rgba(245,158,11,.12); }
.blk.blk-var-inset { background: #f1f5f9; box-shadow: inset 0 4px 14px rgba(2,8,23,.13) !important; border: 1px solid #e2e8f0; }
.blk.blk-var-gradient { background: linear-gradient(135deg, #1e40af, #0ea5e9) !important; color: #fff; }
.blk.blk-var-gradient .blk-title, .blk.blk-var-gradient .card-t { color: #fff; }
.blk.blk-var-gradient .fake-card { background: rgba(255,255,255,.13); border-color: rgba(255,255,255,.25); }

/* — استایل دکمه‌ها (داخل بلوک) — */
.blk-btn-glass .hero-btn, .blk-btn-glass .fake-cta, .blk-btn-glass .cta-btn {
    background: rgba(255,255,255,.22); backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,.45); color: inherit;
}
.blk-btn-pill .hero-btn, .blk-btn-pill .fake-cta, .blk-btn-pill .cta-btn { border-radius: 999px; }
.blk-btn-outline .hero-btn, .blk-btn-outline .fake-cta, .blk-btn-outline .cta-btn { background: transparent; border: 2px solid #1e40af; color: #1e40af; }
.blk-btn-gradient .hero-btn, .blk-btn-gradient .fake-cta, .blk-btn-gradient .cta-btn { background: linear-gradient(135deg, #1e40af, #0ea5e9); color: #fff; border: none; }
.blk-btn-square .hero-btn, .blk-btn-square .fake-cta, .blk-btn-square .cta-btn { border-radius: 0; }
@keyframes blkBtnPulse { 0%,100% { box-shadow: 0 0 0 0 rgba(37,99,235,.45); } 50% { box-shadow: 0 0 0 9px rgba(37,99,235,0); } }
.blk-btn-glow .hero-btn, .blk-btn-glow .fake-cta, .blk-btn-glow .cta-btn { animation: blkBtnPulse 2.1s infinite; background: #2563eb; color: #fff; }
.blk-btn-shadow .hero-btn, .blk-btn-shadow .fake-cta, .blk-btn-shadow .cta-btn { box-shadow: 0 7px 18px rgba(30,64,175,.38); transition: transform .18s, box-shadow .18s; }
.blk-btn-shadow .hero-btn:hover, .blk-btn-shadow .fake-cta:hover { transform: translateY(-2px); box-shadow: 0 11px 24px rgba(30,64,175,.44); }

/* — افکت‌های هاور (بدنه بلوک) — */
.blk-hover-lift, .blk-hover-zoom, .blk-hover-tilt { transition: transform .22s ease, box-shadow .22s ease; }
.blk-hover-lift:hover { transform: translateY(-6px); box-shadow: 0 18px 40px rgba(2,8,23,.17); }
.blk-hover-zoom:hover { transform: scale(1.022); }
.blk-hover-tilt:hover { transform: rotate(-.5deg) translateY(-3px); }
.blk-hover-glow { transition: box-shadow .24s ease; }
.blk-hover-glow:hover { box-shadow: 0 0 0 3px rgba(37,99,235,.35), 0 0 30px rgba(37,99,235,.30) !important; }

/* هدر */
.header-blk { padding: 14px 18px; }
.header-blk .h-row { display: flex; align-items: center; gap: 14px; }
.header-blk.glass { background: rgba(255,255,255,.85); backdrop-filter: blur(9px); }
.header-blk.sticky-demo { outline: 1.5px dashed #2563eb; outline-offset: -6px; }
.fake-logo { font-size: 22px; }
.fake-nav { display: flex; gap: 16px; font-size: 13px; color: var(--muted); flex: 1; flex-wrap: wrap; }
.fake-cta { background: var(--p); color: #fff; font-size: 12px; padding: 7px 15px; border-radius: 9px; white-space: nowrap; }
.topbar-blk { display: flex; justify-content: space-between; font-size: 11.5px; color: var(--muted); padding: 7px 16px; background: #f1f5f9; flex-wrap: wrap; gap: 6px; }
.tb-row { display: flex; justify-content: space-between; font-size: 11px; color: var(--muted); flex-wrap: wrap; gap: 6px; }

/* هیرو */
.hero-blk { background: linear-gradient(135deg, var(--p), var(--s)); color: #fff; text-align: center; }
.hero-blk.split-hero { text-align: right; }
.hero-title { font-size: 21px; font-weight: 800; margin-bottom: 8px; }
.hero-sub { font-size: 13px; opacity: .88; margin-bottom: 18px; }
.hero-btns { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
.hero-blk.split-hero .hero-btns { justify-content: flex-start; }
.hero-btn { background: var(--a); border-radius: 10px; padding: 9px 22px; font-size: 13px; font-weight: 700; display: inline-block; }
.hero-btn.ghost { background: transparent; border: 1.5px solid rgba(255,255,255,.65); }
.hero-btn.full { width: 100%; text-align: center; }
.hero-img { flex: 1 1 200px; height: 130px; background: rgba(255,255,255,.14); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 34px; }
.hero-img.wide { width: 100%; flex: none; height: 150px; margin-bottom: 9px; }
.hero-split { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
.hero-split > div:first-child { flex: 1 1 240px; }
.slider-dots { letter-spacing: 5px; font-size: 11px; opacity: .8; text-align: center; margin-top: 6px; }
.play { width: 54px; height: 54px; border-radius: 50%; background: rgba(255,255,255,.2); display: flex; align-items: center; justify-content: center; font-size: 20px; margin: 12px auto; }
.count-row { display: flex; gap: 10px; justify-content: center; }
.count-box { background: rgba(255,255,255,.15); border-radius: 10px; padding: 9px 16px; font-size: 11px; }
.count-box b { display: block; font-size: 20px; }

/* متن و کارت */
.pv-text { font-size: 13.5px; line-height: 2.1; color: #334155; }
.pv-list { margin: 0 20px 0 0; font-size: 13px; line-height: 2.2; }
.fake-lines .fl { height: 10px; border-radius: 5px; background: #e2e8f0; margin: 8px 0; }
.w40 { width: 40%; } .w60 { width: 60%; } .w70 { width: 70%; } .w75 { width: 75%; } .w80 { width: 80%; } .w90 { width: 90%; } .w100 { width: 100%; }
.split { display: flex; gap: 22px; align-items: center; flex-wrap: wrap; }
.split > div:first-child { flex: 1 1 260px; }
.fake-img { flex: 1 1 180px; height: 150px; background: var(--p-light); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 36px; }
.fake-img.small { height: 90px; font-size: 26px; width: 100%; flex: none; }
.fake-img.wide { flex: none; }
.cols { display: grid; gap: 14px; }
.c2 { grid-template-columns: repeat(2, 1fr); }
.c3 { grid-template-columns: repeat(3, 1fr); }
.c4 { grid-template-columns: repeat(4, 1fr); }
.c6 { grid-template-columns: repeat(6, 1fr); }
.fake-card { background: var(--card); border: 1px solid var(--border); border-radius: 13px; padding: 16px 13px; text-align: center; min-width: 0; }
.blk-bg-primary .fake-card, .blk-bg-dark .fake-card, .blk-bg-gradient .fake-card { background: rgba(255,255,255,.1); border-color: rgba(255,255,255,.22); }
.card-ico { font-size: 25px; margin-bottom: 8px; }
.card-t { font-size: 13px; font-weight: 700; margin-bottom: 6px; }
.fake-card .fl { margin: 7px auto 0; }
.fake-ava { font-size: 30px; }

/* 🏛 ستون‌های بخش چندستونی */
.pv-cols { display: grid; grid-template-columns: repeat(var(--pv-n, 2), 1fr); gap: 14px; padding: 16px 20px 20px; background: #f8fafc; border-bottom: 1px dashed var(--border); }
.pv-col { display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.pv-col .blk { border: 1px solid var(--border); border-radius: 12px; }
.pv-col .blk:first-child:last-child { }
.pv-col-empty { border: 2px dashed #cbd5e1; border-radius: 10px; color: #94a3b8; font-size: 11.5px; text-align: center; padding: 18px 8px; }

/* فرم و آمار */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; max-width: 640px; margin: 0 auto; }
.fake-input { background: #f8fafc; border: 1.5px solid var(--border); border-radius: 9px; padding: 10px 13px; font-size: 12px; color: var(--muted); }
.news-row { display: flex; gap: 9px; max-width: 520px; margin: 0 auto; }
.stats-blk { display: flex; justify-content: space-around; flex-wrap: wrap; gap: 18px; background: linear-gradient(135deg, #0f172a, #1e3a8a); color: #fff; }
.stat { text-align: center; }
.stat-n { font-size: 26px; font-weight: 800; color: #93c5fd; }
.stat-l { font-size: 12px; opacity: .85; }
.pbar { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; font-size: 12.5px; }
.pbar span { flex: 0 0 128px; }
.track { flex: 1; height: 9px; background: #e2e8f0; border-radius: 9px; overflow: hidden; }
.fill { height: 100%; background: linear-gradient(90deg, var(--p), var(--s)); border-radius: 9px; }

/* تعامل */
.quote { background: var(--card); border: 1px solid var(--border); border-inline-start: 4px solid var(--p); border-radius: 11px; padding: 17px 19px; font-size: 13px; max-width: 560px; margin: 0 auto 10px; }
.quote-blk .quote { font-size: 16px; font-weight: 800; text-align: center; max-width: 620px; }
.acc { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 12px 16px; margin-bottom: 9px; font-size: 13px; display: flex; justify-content: space-between; align-items: center; max-width: 640px; margin-inline: auto; }
.tabs-row { display: flex; gap: 6px; justify-content: center; margin-bottom: 12px; }
.tab { font-size: 12px; padding: 6px 16px; border-radius: 8px; border: 1px solid var(--border); color: var(--muted); }
.tab.cur { background: var(--p); color: #fff; border-color: var(--p); }
.tl { max-width: 520px; margin: 0 auto; }
.tl-item { display: flex; gap: 11px; align-items: center; padding: 8px 0; opacity: .45; font-size: 12.5px; }
.tl-item.done, .tl-item.cur { opacity: 1; }
.tl-dot { width: 26px; height: 26px; border-radius: 50%; background: #e2e8f0; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #475569; flex: 0 0 26px; }
.tl-item.done .tl-dot { background: #16a34a; color: #fff; }
.tl-item.cur .tl-dot { background: #2563eb; color: #fff; }
.steps-row { display: flex; gap: 9px; align-items: center; justify-content: center; flex-wrap: wrap; }
.step { background: var(--card); border: 1px solid var(--border); border-radius: 11px; padding: 12px 16px; text-align: center; }
.step-n { width: 26px; height: 26px; border-radius: 50%; background: var(--p); color: #fff; display: flex; align-items: center; justify-content: center; margin: 0 auto 6px; font-size: 13px; }
.step-t { font-size: 11.5px; font-weight: 700; }
.step-arrow { color: #94a3b8; font-size: 16px; }
.feat-list { display: flex; flex-direction: column; gap: 11px; max-width: 640px; margin: 0 auto; }
.feat-row { display: flex; gap: 12px; align-items: flex-start; }
.feat-ico { width: 38px; height: 38px; border-radius: 10px; background: var(--p-light); display: flex; align-items: center; justify-content: center; font-size: 18px; flex: 0 0 38px; }
.feat-d { font-size: 11.5px; color: var(--muted); }
.price-table { max-width: 600px; margin: 0 auto; }
.price-row { display: flex; justify-content: space-between; padding: 11px 16px; border-bottom: 1px solid var(--border); font-size: 13px; background: var(--card); }
.price-row:first-child { border-radius: 11px 11px 0 0; }
.price-row:last-child { border-radius: 0 0 11px 11px; border-bottom: none; }
.price-row b { color: var(--p); }

/* متفرقه */
.fake-map { height: 170px; background: repeating-linear-gradient(45deg, #eef2ff, #eef2ff 12px, #e0e7ff 12px, #e0e7ff 24px); border-radius: 13px; display: flex; align-items: center; justify-content: center; color: var(--p); font-weight: 700; }
.fake-logo-s { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 13px; font-size: 21px; text-align: center; }
.cta-blk { background: linear-gradient(135deg, var(--p), var(--s)); color: #fff; text-align: center; }
.cta-num { font-size: 25px; font-weight: 800; margin-top: 6px; letter-spacing: 1px; }
.crumb { font-size: 12px; color: var(--muted); padding: 11px 18px; background: #f8fafc; }
.blk-sep { border: none; border-top: 1px solid var(--border); margin: 6px 0; }
.blk-spacer { background: repeating-linear-gradient(45deg, #f8fafc, #f8fafc 10px, #f1f5f9 10px, #f1f5f9 20px); }
.alert-demo { border-radius: 10px; padding: 11px 15px; font-size: 12.5px; font-weight: 600; }
.alert-demo.info { background: #eff6ff; color: #1d4ed8; }
.alert-demo.warning { background: #fffbeb; color: #b45309; }
.alert-demo.success { background: #f0fdf4; color: #15803d; }
.sticky-cta-demo { display: flex; justify-content: space-between; align-items: center; background: #0f172a; color: #fff; }
.footer-blk { background: #0f172a; color: #e2e8f0; }
.footer-blk .fake-nav { color: #94a3b8; }
.soc-row { display: flex; gap: 12px; justify-content: center; font-size: 11px; color: #94a3b8; margin-top: 9px; }
.crump-blk { text-align: center; font-size: 11.5px; color: var(--muted); background: #f8fafc; }

/* ════════ 🆕 v2.12 + v3.3: استایل عناصر جدید — پیش‌نمایش واقعی ════════ */
.chip-row { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
.chip { background: var(--card); border: 1px solid var(--border); border-radius: 20px; padding: 4px 13px; font-size: 11.5px; color: var(--text); }
.blk-bg-primary .chip, .blk-bg-dark .chip, .blk-bg-gradient .chip { background: rgba(255,255,255,.14); border-color: rgba(255,255,255,.25); color: #fff; }
.notif-bar { text-align: center; font-weight: 700; font-size: 13px; }
.notif-bar.info { background: #eff6ff; color: #1e40af; }
.notif-bar.success { background: #f0fdf4; color: #15803d; }
.notif-bar.warning { background: #fffbeb; color: #b45309; }
.marquee-blk { overflow: hidden; background: #0f172a; color: #fff; }
.marquee-track { white-space: nowrap; animation: pvMarquee 14s linear infinite; padding: 9px 0; font-size: 12.5px; font-weight: 600; }
@keyframes pvMarquee { from { transform: translateX(-100%); } to { transform: translateX(100%); } }
.search-wrap { display: flex; align-items: center; gap: 9px; background: #fff; border: 1.5px solid var(--border); border-radius: 13px; padding: 7px 12px; max-width: 560px; margin: 0 auto; }
.search-ico { font-size: 16px; }
.story-wrap { display: flex; flex-direction: column; gap: 15px; max-width: 620px; margin: 0 auto; }
.story-sec { display: flex; gap: 14px; align-items: center; }
.story-year { background: var(--p); color: #fff; border-radius: 10px; padding: 5px 13px; font-weight: 800; font-size: 13px; white-space: nowrap; }
.ba-wrap { display: flex; gap: 13px; align-items: center; justify-content: center; flex-wrap: wrap; }
.ba-side { flex: 1; min-width: 200px; max-width: 300px; }
.ba-tag { display: inline-block; border-radius: 8px; font-size: 11px; font-weight: 800; padding: 2.5px 11px; margin-bottom: 6px; }
.ba-tag.bad { background: #fef2f2; color: #b91c1c; }
.ba-tag.ok { background: #f0fdf4; color: #15803d; }
.ba-arrow { font-size: 26px; color: var(--p); }
.pill-announce { display: flex; align-items: center; gap: 10px; background: #eff6ff; border: 1.5px solid #bfdbfe; color: #1e40af; border-radius: 40px; padding: 11px 20px; font-weight: 700; font-size: 13px; max-width: 640px; margin: 0 auto; }
.pill-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--p); box-shadow: 0 0 0 4px rgba(37,99,235,.18); flex: 0 0 9px; }
.num-list { display: flex; flex-direction: column; gap: 12px; max-width: 640px; margin: 0 auto; }
.num-row { display: flex; gap: 13px; align-items: flex-start; }
.num-n { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, var(--p), var(--s)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; flex: 0 0 34px; }
.info-box-demo { display: flex; gap: 13px; align-items: flex-start; background: #fffbeb; border: 1.5px solid #fde68a; border-radius: 12px; padding: 14px 16px; }
.soc-proof { display: flex; gap: 15px; align-items: center; justify-content: center; flex-wrap: wrap; }
.ava-stack { display: flex; }
.ava-stack .fake-ava { border: 2px solid #fff; box-shadow: 0 2px 8px rgba(0,0,0,.14); border-radius: 50%; }
.promo-card-demo { display: flex; gap: 18px; align-items: center; justify-content: space-between; flex-wrap: wrap; background: linear-gradient(135deg, #fff7ed, #ffedd5); border: 1.5px solid #fdba74; border-radius: 14px; padding: 20px 22px; }
.divider-ico { display: flex; align-items: center; gap: 12px; }
.divider-line { flex: 1; height: 1.5px; background: linear-gradient(90deg, transparent, #cbd5e1, #cbd5e1, transparent); }
.stars { color: #f59e0b; letter-spacing: 1px; }
.badge-info { background: #dbeafe; color: #1e40af; }
.badge-warning { background: #fef3c7; color: #92400e; }
.badge { border-radius: 20px; padding: 2px 10px; font-size: 10.5px; font-weight: 700; }
/* 🎛 v3.3: تنظیمات پیشرفته */
.blk-ts-sm .blk-title { font-size: 14px; }
.blk-ts-md .blk-title { font-size: 17px; }
.blk-ts-lg .blk-title { font-size: 21px; }
.blk-ts-xl .blk-title { font-size: 26px; }
/* 🎛 v2.14: اندازه عنوان روی تیترهای هیرو هم اثر بگذارد + تراز کامل‌تر */
.blk-ts-sm .hero-title { font-size: 15px; } .blk-ts-md .hero-title { font-size: 19px; }
.blk-ts-lg .hero-title { font-size: 24px; } .blk-ts-xl .hero-title { font-size: 29px; }
.blk-al-center { text-align: center; }
.blk-al-center .feat-list, .blk-al-center .num-list, .blk-al-center .price-table, .blk-al-center .form-grid, .blk-al-center .story-wrap, .blk-al-center .author-box-demo, .blk-al-center .feature-table-demo, .blk-al-center .steps-row { margin: 0 auto; }
.blk-al-center .hero-btns, .blk-al-center .chip-row { justify-content: center; }
.blk-al-end { text-align: left; }
.blk-w-wide { max-width: 1200px; margin-inline: auto; }
.blk-w-boxed { max-width: 960px; margin-inline: auto; }
.blk-w-narrow { max-width: 720px; margin-inline: auto; }

/* ════════ 🆕 v2.14: استایل ۱۲ عنصر جدید ════════ */
.stats-strip { display: flex; align-items: center; justify-content: space-around; flex-wrap: wrap; gap: 12px; background: linear-gradient(135deg, #0f172a, #1e3a8a); color: #fff; }
.stats-strip .ss-item { text-align: center; font-size: 11.5px; opacity: .92; }
.stats-strip .ss-item b { display: block; font-size: 23px; font-weight: 800; color: #93c5fd; }
.stats-strip .ss-sep { width: 1px; height: 34px; background: rgba(255,255,255,.25); }
.warning-box-demo { display: flex; gap: 13px; align-items: flex-start; background: #fef2f2; border: 1.5px solid #fecaca; border-radius: 12px; padding: 14px 16px; }
.brand-intro-demo, .download-card-demo { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; background: var(--card); border: 1.5px solid var(--border); border-radius: 14px; padding: 18px 20px; box-shadow: 0 4px 16px rgba(2,8,23,.06); }
.author-box-demo { display: flex; gap: 14px; align-items: flex-start; background: #f8fafc; border: 1.5px solid var(--border); border-radius: 13px; padding: 16px 18px; }
.price-highlight-demo { border: 2px solid var(--p); box-shadow: 0 10px 28px rgba(37,99,235,.15); }
.feature-table-demo { max-width: 640px; margin: 0 auto; border: 1.5px solid var(--border); border-radius: 12px; overflow: hidden; }
.feature-table-demo .ft-row { display: grid; grid-template-columns: 1.4fr 1fr 1fr 1fr; align-items: center; }
.feature-table-demo .ft-row > * { padding: 9px 10px; font-size: 12px; text-align: center; border-bottom: 1px solid var(--border); }
.feature-table-demo .ft-row:last-child > * { border-bottom: none; }
.feature-table-demo .ft-head { background: #0f172a; color: #fff; font-weight: 800; }
.feature-table-demo .ft-hl { background: #eff6ff; color: #1e40af; font-weight: 800; }
.feature-table-demo .ft-row span { text-align: right; font-weight: 700; }
.quick-form-demo { display: flex; gap: 9px; align-items: center; flex-wrap: wrap; background: var(--card); border: 1.5px solid var(--border); border-radius: 13px; padding: 12px 14px; max-width: 560px; margin: 0 auto; }

/* ════════ 🆕 v2.15: رنگ عنوان انتخابی + ۱۴ عنصر جدید ════════ */
.blk[style*="--blk-tc"] .blk-title, .blk[style*="--blk-tc"] .hero-title, .blk[style*="--blk-tc"] .card-t { color: var(--blk-tc) !important; }
.ticker-bar-demo { display: flex; align-items: center; gap: 10px; background: #0f172a; border-radius: 12px; padding: 10px 14px; color: #e2e8f0; overflow: hidden; }
.ticker-bar-demo .ticker-tag { background: #dc2626; color: #fff; font-size: 10.5px; font-weight: 800; border-radius: 20px; padding: 3px 11px; white-space: nowrap; }
.ticker-bar-demo .ticker-track { flex: 1; overflow: hidden; white-space: nowrap; font-size: 12px; }
.ticker-bar-demo .ticker-track span { display: inline-block; animation: tickMove 22s linear infinite; padding-inline-start: 100%; }
@keyframes tickMove { from { transform: translateX(-100%); } to { transform: translateX(0); } }
.cal-demo { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; max-width: 520px; margin: 0 auto; }
.cal-demo .cal-dow { text-align: center; font-size: 10.5px; font-weight: 800; color: var(--muted); padding: 4px 0; }
.cal-demo .cal-day { text-align: center; font-size: 11.5px; padding: 8px 0; border-radius: 8px; border: 1.5px solid var(--border); background: var(--card); }
.cal-demo .cal-day.busy { background: #fef2f2; border-color: #fecaca; color: #b91c1c; text-decoration: line-through; }
.cal-demo .cal-day.sel { background: #dcfce7; border-color: #16a34a; color: #14532d; font-weight: 800; }
.queue-demo { max-width: 560px; margin: 0 auto; display: flex; flex-direction: column; gap: 8px; }
.queue-demo .queue-row { display: flex; justify-content: space-between; align-items: center; background: var(--card); border: 1.5px solid var(--border); border-radius: 10px; padding: 10px 15px; font-size: 13px; }
.cap-demo { max-width: 600px; margin: 0 auto; display: flex; flex-direction: column; gap: 10px; }
.cap-demo .cap-row { display: flex; align-items: center; gap: 10px; font-size: 12px; }
.cap-demo .cap-h { min-width: 52px; font-weight: 800; }
.cap-demo .cap-l { min-width: 66px; color: var(--muted); text-align: left; }
.bas-demo { position: relative; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; align-items: stretch; }
.bas-demo .bas-before, .bas-demo .bas-after { position: relative; height: 150px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 13px; overflow: hidden; }
.bas-demo .bas-before { background: #f1f5f9; border: 1.5px dashed #94a3b8; }
.bas-demo .bas-after { background: linear-gradient(135deg, #dcfce7, #bbf7d0); border: 1.5px solid #16a34a; }
.bas-demo .bas-tag { position: absolute; top: 8px; right: 8px; background: rgba(15, 23, 42, .85); color: #fff; font-size: 10.5px; font-weight: 800; border-radius: 16px; padding: 3px 11px; }
.bas-demo .bas-tag.ok { background: #16a34a; }
.bas-demo .bas-handle { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 3; width: 38px; height: 38px; border-radius: 50%; background: #fff; border: 3px solid #2563eb; display: flex; align-items: center; justify-content: center; font-weight: 800; color: #2563eb; box-shadow: 0 4px 14px rgba(37, 99, 235, .35); }
.np-demo-wrap { text-align: center; }
.np-demo { display: inline-flex; flex-direction: column; text-align: right; background: var(--card); border: 1.5px solid var(--border); border-radius: 15px; padding: 18px 20px; box-shadow: 0 18px 44px rgba(2, 8, 23, .16); max-width: 430px; }
.ct-demo { display: flex; gap: 16px; align-items: center; background: linear-gradient(135deg, #eff6ff, #dbeafe); border: 1.5px solid #93c5fd; border-radius: 15px; padding: 18px 22px; }
.ct-demo .ct-score { font-size: 39px; font-weight: 900; color: #1e40af; line-height: 1; }
.ct-demo .ct-score small { font-size: 15px; color: #3b82f6; }
.fake-img img { border-radius: inherit; }

/* ═══ v2.17: CSS عناصر جدید ═══ */
.glass-hero-demo { background:rgba(255,255,255,.55); backdrop-filter:blur(9px); border:1px solid rgba(255,255,255,.75); border-radius:17px; padding:26px 24px; text-align:center; box-shadow:0 14px 34px rgba(2,6,23,.10); }
.logo-strip-demo { display:flex; gap:12px; justify-content:space-between; flex-wrap:wrap; opacity:.9; }
.text-cols-demo { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.text-cols-demo p { margin:0 0 9px; font-size:12.5px; line-height:2; }
.steps-compact-demo { display:flex; flex-direction:column; gap:9px; }
.sc-row { display:flex; gap:11px; align-items:center; background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:11px; padding:10px 13px; }
.sc-num { flex:none; width:30px; height:30px; border-radius:50%; background:#2563eb; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; }
.guarantee-demo { display:flex; gap:15px; align-items:center; background:linear-gradient(135deg,#ecfdf5,#f0fdfa); border:1.5px solid #a7f3d0; border-radius:14px; padding:17px 19px; }
.urgent-demo { display:flex; gap:14px; align-items:center; background:linear-gradient(135deg,#fef2f2,#fff7ed); border:1.5px solid #fecaca; border-radius:14px; padding:16px 18px; }
.faq-mini-demo { background:#f8fafc; border:1.5px solid #e2e8f0; border-inline-start:4px solid #2563eb; border-radius:11px; padding:14px 16px; }
.apt-compact-demo { background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:13px; padding:16px 18px; }
.hero-minimal-blk { text-align:center; }
.stats-strip { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; background:#f1f5f9; border-radius:11px; padding:12px 16px; }
.stats-strip .ss-item { font-size:12px; color:#334155; }
.stats-strip .ss-item b { font-size:16px; color:#2563eb; margin-inline-end:3px; }
.stats-strip .ss-sep { width:1px; height:22px; background:#cbd5e1; }
/* ⚙️ v2.25: تنظیمات صفحه — متغیرها روی body */
body { --pg-section-pad: 54px; --pg-gap: 26px; --pg-width: 1080px; --pg-radius: 14px; --pg-text: 14.5px; font-size: var(--pg-text); }

/* ═══════════════════════════════════════════════════════════════
   🎬 v2.29 — انیمیشن ورود عناصر (blk-anim-*)
   انتخاب از تنظیمات انیمیشن هر عنصر + سرعت + تأخیر (موجی)
   ═══════════════════════════════════════════════════════════════ */
.blk-anim { animation: blkAnimIn var(--anim-dur, .7s) cubic-bezier(.22,.9,.32,1.02) both; animation-delay: var(--anim-delay, 0ms); }
@keyframes blkAnimIn { from { opacity: 0; } to { opacity: 1; } }
.blk-anim-fade { animation-name: blkFade; }
@keyframes blkFade { from { opacity: 0; } to { opacity: 1; } }
.blk-anim-up { animation-name: blkUp; }
@keyframes blkUp { from { opacity: 0; transform: translateY(38px); } to { opacity: 1; transform: translateY(0); } }
.blk-anim-down { animation-name: blkDown; }
@keyframes blkDown { from { opacity: 0; transform: translateY(-38px); } to { opacity: 1; transform: translateY(0); } }
.blk-anim-right { animation-name: blkRight; }
@keyframes blkRight { from { opacity: 0; transform: translateX(46px); } to { opacity: 1; transform: translateX(0); } }
.blk-anim-left { animation-name: blkLeft; }
@keyframes blkLeft { from { opacity: 0; transform: translateX(-46px); } to { opacity: 1; transform: translateX(0); } }
.blk-anim-zoom { animation-name: blkZoom; }
@keyframes blkZoom { from { opacity: 0; transform: scale(.82); } to { opacity: 1; transform: scale(1); } }
.blk-anim-flip { animation-name: blkFlip; }
@keyframes blkFlip { from { opacity: 0; transform: perspective(700px) rotateX(-52deg); } to { opacity: 1; transform: perspective(700px) rotateX(0); } }
.blk-anim-bounce { animation-name: blkBounce; }
@keyframes blkBounce { 0% { opacity: 0; transform: translateY(-46px); } 55% { opacity: 1; transform: translateY(8px); } 75% { transform: translateY(-5px); } 100% { transform: translateY(0); } }
.blk-anim-rotate { animation-name: blkRotate; }
@keyframes blkRotate { from { opacity: 0; transform: rotate(-4.5deg) scale(.94); } to { opacity: 1; transform: rotate(0) scale(1); } }
@media (prefers-reduced-motion: reduce) { .blk-anim { animation: none !important; } }

/* 🆕 v2.29 — فاصله محتوای صفحه از لبه‌ها (تنظیمات صفحه قالب‌ساز) */
body { padding-top: var(--pg-mt, 0); padding-bottom: var(--pg-mb, 0); padding-inline-start: var(--pg-mr, 0); padding-inline-end: var(--pg-ml, 0); }
.preview-wrap { max-width: var(--pg-width); margin: 0 auto; padding: 16px; }
body[style*="--pg-bg"] { background: var(--pg-bg); }
.preview-wrap .blk { border-radius: var(--pg-radius); margin-bottom: var(--pg-gap); }
.preview-wrap .blk .blk-title { color: var(--pg-title, inherit); }
.blk-pad-default { padding-top: calc(var(--pg-section-pad) * .6); padding-bottom: calc(var(--pg-section-pad) * .6); }
.blk-pad-roomy { padding-top: var(--pg-section-pad); padding-bottom: var(--pg-section-pad); }
.blk-pad-compact { padding-top: calc(var(--pg-section-pad) * .38); padding-bottom: calc(var(--pg-section-pad) * .38); }
/* 😀 انتخابگر آیکون هم در پیش‌نمایش (فقط کتابخانه کوچک) */

@media (max-width: 640px) {
    .c2, .c3, .c4, .c6, .form-grid, .pv-cols { grid-template-columns: 1fr 1fr; }
    .c6 { grid-template-columns: repeat(3, 1fr); }
    .pv-cols { grid-template-columns: 1fr; }
    .hero-title { font-size: 17px; }
}
@media (max-width: 420px) {
    .c2, .c3, .c4, .c6, .form-grid { grid-template-columns: 1fr; }
    .fake-nav { display: none; }
}
</style>
</head>
<body<?= $pageStyle !== '' ? ' style="' . e($pageStyle) . '"' : '' ?>>
<div class="preview-wrap">
    <?php if (empty($layout)): ?>
        <div class="blk" style="text-align:center;color:var(--muted)">چیدمانی برای پیش‌نمایش وجود ندارد.</div>
    <?php else: ?>
        <?= renderLayoutLevel($layout) ?>
    <?php endif; ?>
</div>
</body>
</html>
