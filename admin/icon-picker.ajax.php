<?php
/**
 * 🎨 اندپوینت AJAX انتخابگر آیکون (v2.38 — آیکون‌پک برای منو و قالب‌ساز)
 * =====================================================================
 * سرویس‌دهی مشترک به مودال انتخابگر آیکون در همه‌ی صفحات پنل:
 *   - POST action=packs  → فهرست پک‌های آیکون نصب‌شده (slug + نام فارسی + تعداد)
 *   - POST action=browse → آیکون‌های یک پک با جستجو + دسته + صفحه‌بندی (۱۲۰تایی)
 *
 * مقدار آیکونِ ذخیره‌شده در منو/المنت‌ها با پیشوند `svg:` شروع می‌شود:
 *   svg:{pack}/{file}.svg  →  رندر <img> از assets/icons/
 *   هر چیز دیگر           →  ایموجی/متن (سازگار با داده‌های موجود)
 *
 * @package SahandBrandMaker
 * @since   2.38.0
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$auth = new Auth();
$auth->requireLogin();
/* 🛡️ دفاع در عمق: رد درخواست بین‌سایتی */
reject_cross_origin();

/* 🛡️ CSRF برای AJAX */
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    json_response(['success' => false, 'error' => 'توکن CSRF نامعتبر است.'], 419);
}

header('Content-Type: application/json; charset=utf-8');
$action = (string)($_POST['action'] ?? '');

/* 🗂 فهرست پک‌های نصب‌شده */
if ($action === 'packs') {
    $packsOnDisk = glob(ASSETS_PATH . '/icons/*', GLOB_ONLYDIR) ?: [];
    $sources = AssetDownloader::iconSources();
    $out = [];
    foreach ($packsOnDisk as $dir) {
        $slug = basename($dir);
        $manifest = is_file($dir . '/manifest.json') ? (json_decode((string)file_get_contents($dir . '/manifest.json'), true) ?: []) : [];
        $count = count(glob($dir . '/*.svg') ?: []);
        if ($count < 1) {
            continue; /* پک خالی — در انتخابگر نمایش داده نمی‌شود */
        }
        $out[] = [
            'slug'    => $slug,
            'name_fa' => (string)($manifest['name_fa'] ?? ($sources[$slug]['name_fa'] ?? $slug)),
            'count'   => $count,
        ];
    }
    usort($out, static fn($a, $b) => $b['count'] <=> $a['count']);
    json_response(['success' => true, 'packs' => $out]);
}

/* 🔎 مرور/جستجوی آیکون‌های یک پک */
if ($action === 'browse') {
    $pack = preg_match('/^[a-z0-9\-]+$/', (string)($_POST['pack'] ?? '')) ? (string)$_POST['pack'] : '';
    $q = trim((string)($_POST['q'] ?? ''));
    $cat = trim((string)($_POST['cat'] ?? ''));
    $page = max(1, (int)($_POST['page'] ?? 1));
    $perPage = 120;
    if ($pack === '') {
        json_response(['success' => false, 'error' => 'پک مشخص نشده است.']);
    }
    $dir = ASSETS_PATH . '/icons/' . $pack;
    if (!is_dir($dir)) {
        json_response(['success' => false, 'error' => 'پک یافت نشد.']);
    }
    $manifest = is_file($dir . '/manifest.json') ? (json_decode((string)file_get_contents($dir . '/manifest.json'), true) ?: []) : [];
    $icons = [];
    $cats = [];
    foreach ($manifest['icons'] ?? [] as $ic) {
        if (!is_array($ic) || empty($ic['file']) || !is_file($dir . '/' . $ic['file'])) {
            continue;
        }
        $ic['category'] = (string)($ic['category'] ?? 'general');
        $ic['label_fa'] = (string)($ic['label_fa'] ?? ($ic['name'] ?? $ic['file']));
        $cats[$ic['category']] = ($cats[$ic['category']] ?? 0) + 1;
        $icons[] = $ic;
    }
    $filtered = $icons;
    if ($cat !== '') {
        $filtered = array_values(array_filter($filtered, static fn($ic) => $ic['category'] === $cat));
    }
    if ($q !== '') {
        $filtered = array_values(array_filter($filtered, static fn($ic) =>
            stripos((string)($ic['name'] ?? ''), $q) !== false
            || stripos((string)$ic['label_fa'], $q) !== false
            || stripos((string)$ic['category'], $q) !== false));
    }
    $totalPages = max(1, (int)ceil(count($filtered) / $perPage));
    $page = min($page, $totalPages);
    $slice = array_slice($filtered, ($page - 1) * $perPage, $perPage);
    json_response([
        'success'    => true,
        'pack'       => $pack,
        'icons'      => array_map(static fn(array $ic): array => [
            'v'     => 'svg:' . $pack . '/' . $ic['file'],
            'url'   => asset_url('assets/icons/' . $pack . '/' . $ic['file']),
            'label' => $ic['label_fa'],
        ], $slice),
        'total'       => count($filtered),
        'page'        => $page,
        'total_pages' => $totalPages,
        'cats'        => $cats,
    ]);
}

json_response(['success' => false, 'error' => 'اکشن نامعتبر است.'], 400);
