<?php
/**
 * 🔌 روتر API داخلی سایت ساز
 * ============================
 * تمام درخواست‌های /api/* به این فایل هدایت می‌شوند (.htaccess)
 * احراز هویت: API Key هدر X-API-Key
 *
 * اندپوینت‌ها:
 *   🌐 سایت برند: brand, page, article, request, analytics, seo, error-code, settings, icon, font, template
 *   🤖 موتور AI: ai/* (مطابق بخش ۶ سند)
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Powered-By: SahandBrandMaker/' . SAHAND_VERSION);

$router = new Router();

/* ==================================================
 * 🛡️ میان‌افزار سراسری
 * ================================================== */

// 🌐 CORS — دسترسی سایت‌های برند
$router->use(function () {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        return false;
    }
    return true;
});

// 🚦 محدودیت نرخ — بر اساس IP
$router->use(function () {
    require_once __DIR__ . '/middleware/rate-limit.php';
    return api_rate_limit();
});

// 🔑 احراز هویت API Key (مسیرهای عمومی مستثنا هستند)
$router->use(function (array $params) {
    $path = trim(preg_replace('#^.*?/api/#', '', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)), '/');
    // 🌐 مسیرهای عمومی بدون احراز هویت (محدودیت نرخ فعال است):
    //    📨 request — ثبت درخواست از فرم سایت برند (کلید در بدنه بررسی می‌شود)
    //    📊 track — ردیاب بازدید (brand_id اعتبارسنجی می‌شود)
    //    🖼️ icon — سرو آیکون در تگ <img> (امکان ارسال هدر نیست)
    //    🏷️ brands — لیست عمومی برندها برای فوتر سایت‌ها
    if (preg_match('#^brand/[^/]+/request$#', $path) || preg_match('#^request$#', $path)
        || preg_match('#^track$#', $path)
        || preg_match('#^icon/#', $path)
        || preg_match('#^brands$#', $path)) {
        return true;
    }
    require_once __DIR__ . '/middleware/auth.php';
    return api_auth($params);
});

/* ==================================================
 * 🏷️ اندپوینت‌های برند — مصرف سایت‌های برند
 * ================================================== */

// ℹ️ اطلاعات کامل برند (لوگو، پالت، تنظیمات، تماس‌ها)
$router->add('GET', 'brand/{brandId}', function ($p) {
    require __DIR__ . '/endpoints/brand.php';
    api_brand_info((int)$p['brandId']);
});

// 📄 محتوای یک صفحه
$router->add('GET', 'brand/{brandId}/page/{pageType}', function ($p) {
    require __DIR__ . '/endpoints/page.php';
    api_brand_page((int)$p['brandId'], $p['pageType']);
});

// 📰 لیست مقالات
$router->add('GET', 'brand/{brandId}/articles', function ($p) {
    require __DIR__ . '/endpoints/article.php';
    api_brand_articles((int)$p['brandId'], (int)($_GET['page'] ?? 1), (int)($_GET['per_page'] ?? 10));
});

// 📰 مقاله تکی
$router->add('GET', 'brand/{brandId}/article/{slug}', function ($p) {
    require __DIR__ . '/endpoints/article.php';
    api_brand_article((int)$p['brandId'], $p['slug']);
});

// 🚨 کدهای خطا + جستجو
$router->add('GET', 'brand/{brandId}/error-codes', function ($p) {
    require __DIR__ . '/endpoints/error-code.php';
    api_brand_error_codes((int)$p['brandId'], $_GET['q'] ?? '', $_GET['device'] ?? '');
});

// ❓ سوالات متداول
$router->add('GET', 'brand/{brandId}/faqs', function ($p) {
    $faqs = (new FaqGenerator())->forBrand((int)$p['brandId']);
    json_response(['success' => true, 'data' => $faqs]);
});

// 🔧 دستگاه‌های برند
$router->add('GET', 'brand/{brandId}/devices', function ($p) {
    $devices = Database::getInstance()->fetchAll(
        'SELECT device_key, name_fa, icon, description, is_featured FROM brand_devices WHERE brand_id = ? AND is_active = 1 ORDER BY sort_order, id',
        [(int)$p['brandId']]
    );
    json_response(['success' => true, 'data' => $devices]);
});

// 🗺️ منوی برند
$router->add('GET', 'brand/{brandId}/menu/{location}', function ($p) {
    require __DIR__ . '/endpoints/brand.php';
    api_brand_menu((int)$p['brandId'], $p['location']);
});

// 📨 ثبت درخواست خدمات (از فرم سایت برند) — عمومی با API Key بدنه
$router->add('POST', 'brand/{brandId}/request', function ($p) {
    require __DIR__ . '/endpoints/request.php';
    api_submit_request((int)$p['brandId']);
});
$router->add('POST', 'request', function () {
    require __DIR__ . '/endpoints/request.php';
    api_submit_request(0);
});

// 📊 ثبت بازدید (tracker.js)
$router->add('POST', 'track', function () {
    require __DIR__ . '/endpoints/analytics.php';
    api_track_visit();
});

// ⚙️ تنظیمات عمومی قابل نمایش در سایت برند
$router->add('GET', 'settings', function () {
    require __DIR__ . '/endpoints/settings.php';
    api_public_settings();
});

// 🖼️ آیکون (سرو منابع)
$router->add('GET', 'icon/{pack}/{name}', function ($p) {
    require __DIR__ . '/endpoints/icon.php';
    api_serve_icon($p['pack'], $p['name']);
});

// 🔤 فونت‌ها (لیست فعال)
$router->add('GET', 'fonts', function () {
    require __DIR__ . '/endpoints/font.php';
    api_fonts_list();
});

// 🏷️ لیست برندهای فعال (برای صفحه سایر برندها)
$router->add('GET', 'brands', function () {
    $brands = Database::getInstance()->fetchAll(
        'SELECT id, name_fa, name_en, slug, logo, domain FROM brands WHERE is_active = 1 AND status = ? ORDER BY name_fa',
        ['published']
    );
    json_response(['success' => true, 'data' => $brands]);
});

/* ==================================================
 * 🤖 اندپوینت‌های موتور AI (بخش ۶ سند)
 * ================================================== */

$aiHandler = function (string $method) {
    return function () use ($method) {
        $ai = new SahandAI();
        $input = Router::jsonInput();
        try {
            $result = $ai->{$method}($input);
            json_response(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            json_response(['success' => false, 'error' => $e->getMessage()], 400);
        }
    };
};

$router->add('POST', 'ai/generate-content',     $aiHandler('generateContent'));
$router->add('POST', 'ai/generate-article',     $aiHandler('generateArticle'));
$router->add('POST', 'ai/generate-seo',         $aiHandler('generateSeo'));
$router->add('POST', 'ai/generate-brand-info',  $aiHandler('generateBrandInfo'));
$router->add('POST', 'ai/generate-faq',         $aiHandler('generateFaq'));
$router->add('POST', 'ai/analyze-keywords',     $aiHandler('analyzeKeywords'));
$router->add('POST', 'ai/check-uniqueness',     $aiHandler('checkUniqueness'));
$router->add('POST', 'ai/suggest-improvements', $aiHandler('suggestImprovements'));
$router->add('POST', 'ai/suggest-topics',       $aiHandler('suggestArticleTopics'));

$router->add('GET', 'ai/templates', function () {
    json_response(['success' => true, 'data' => (new SahandAI())->templates()]);
});
$router->add('GET', 'ai/knowledge', function () {
    json_response(['success' => true, 'data' => (new SahandAI())->knowledgeBrands()]);
});
$router->add('GET', 'ai/knowledge/stats', function () {
    json_response(['success' => true, 'data' => KnowledgeBase::stats()]);
});
$router->add('GET', 'ai/knowledge/{brand}', function ($p) {
    try {
        json_response(['success' => true, 'data' => (new SahandAI())->knowledge($p['brand'])]);
    } catch (Exception $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 404);
    }
});

/* ==================================================
 * 🧠 اندپوینت‌های پایگاه دانش تقویت‌شده (فاز K)
 * ================================================== */
$router->add('GET', 'ai/diagnostics', function () {
    json_response(['success' => true, 'data' => array_keys(TextProcessor::loadKnowledge('diagnostics'))]);
});
$router->add('GET', 'ai/diagnostics/{device}', function ($p) {
    try {
        json_response(['success' => true, 'data' => (new SahandAI())->deviceDiagnostics($p['device'])]);
    } catch (Exception $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 404);
    }
});
$router->add('POST', 'ai/diagnose', $aiHandler('diagnose'));
$router->add('GET', 'ai/seasonal', function () {
    json_response(['success' => true, 'data' => (new SahandAI())->seasonal()]);
});
$router->add('GET', 'ai/seasonal/{month}', function ($p) {
    try {
        json_response(['success' => true, 'data' => (new SahandAI())->seasonal(['month' => $p['month']])]);
    } catch (Exception $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 404);
    }
});
$router->add('GET', 'ai/parts', function () {
    json_response(['success' => true, 'data' => (new SahandAI())->parts()]);
});
$router->add('GET', 'ai/parts/{key}', function ($p) {
    try {
        json_response(['success' => true, 'data' => (new SahandAI())->parts(['key' => $p['key']])]);
    } catch (Exception $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 404);
    }
});

/* ==================================================
 * 🔍 سئو (تولید برای صفحه)
 * ================================================== */
$router->add('POST', 'seo/generate', $aiHandler('generateSeo'));

/* 🚀 اجرا */
$router->dispatch();
