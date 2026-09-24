<?php
/**
 * 📊 اندپوینت API بررسی سلامت (خارج از سایت ساز)
 * =============================================
 * طبق سند بخش ۲۱ — api/endpoints/health.php
 *
 * اندپوینت‌ها:
 *   GET /api/health/{brandId}     → آخرین وضعیت سلامت برند (بدون بررسی مجدد)
 *   POST /api/health/{brandId}/check → بررسی فوری (زنده)
 *   GET /api/health/all           → وضعیت همه سایت‌های مستقر
 *
 * @package SahandBrandMaker
 */

if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

/**
 * 📊 آخرین وضعیت سلامت ثبت‌شده برند
 */
function api_health_brand(int $brandId): void
{
    $db = Database::getInstance();
    $brand = $db->fetch(
        'SELECT id, name_fa, full_domain, health_status, last_health_check, ssl_status, ssl_expiry
         FROM brands WHERE id = ? AND is_deployed = 1',
        [$brandId]
    );
    if (!$brand) {
        json_response(['success' => false, 'error' => 'برند مستقری یافت نشد'], 404);
    }

    $last = $db->fetch('SELECT * FROM site_health WHERE brand_id = ? ORDER BY id DESC LIMIT 1', [$brandId]);

    json_response([
        'success' => true,
        'data'    => [
            'brand'        => $brand['name_fa'],
            'domain'       => $brand['full_domain'],
            'status'       => $brand['health_status'],
            'checked_at'   => $brand['last_health_check'],
            'ssl'          => ['status' => $brand['ssl_status'], 'expiry' => $brand['ssl_expiry']],
            'last_check'   => $last ? [
                'http_status'    => $last['http_status'] !== null ? (int)$last['http_status'] : null,
                'response_time'  => $last['response_time_ms'] !== null ? (int)$last['response_time_ms'] : null,
                'api_ok'         => $last['api_ok'] !== null ? (bool)$last['api_ok'] : null,
                'error'          => $last['error_message'],
            ] : null,
        ],
    ]);
}

/**
 * 🔍 بررسی فوری زنده (POST)
 */
function api_health_check_now(int $brandId): void
{
    $db = Database::getInstance();
    $brand = $db->fetch(
        'SELECT id, name_fa, slug, full_domain FROM brands WHERE id = ? AND is_deployed = 1',
        [$brandId]
    );
    if (!$brand) {
        json_response(['success' => false, 'error' => 'برند مستقری یافت نشد'], 404);
    }

    $checker = new HealthChecker();
    $result = $checker->checkBrand($brand);

    json_response([
        'success' => true,
        'data'    => [
            'status'        => $result['status'],
            'http_status'   => $result['http_status'],
            'response_time' => $result['response_time'],
            'ssl_valid'     => $result['ssl_valid'] !== null ? (bool)$result['ssl_valid'] : null,
            'ssl_expiry'    => $result['ssl_expiry'] ?? null,
            'api_ok'        => $result['api_ok'] !== null ? (bool)$result['api_ok'] : null,
            'error'         => $result['error'] ?? null,
        ],
    ]);
}

/**
 * 📋 وضعیت همه سایت‌های مستقر
 */
function api_health_all(): void
{
    $checker = new HealthChecker();
    $sites = $checker->getDashboard();

    json_response([
        'success' => true,
        'data'    => array_map(function ($s) {
            return [
                'brand_id'      => (int)$s['id'],
                'brand'         => $s['name_fa'],
                'domain'        => $s['full_domain'],
                'status'        => $s['health_status'],
                'http_status'   => $s['http_status'] !== null ? (int)$s['http_status'] : null,
                'response_time' => $s['response_time_ms'] !== null ? (int)$s['response_time_ms'] : null,
                'ssl'           => $s['ssl_status'],
                'checked_at'    => $s['last_health_check'],
            ];
        }, $sites),
        'summary' => [
            'total'   => count($sites),
            'online'  => count(array_filter($sites, function ($x) { return ($x['health_status'] ?? '') === 'online'; })),
            'offline' => count(array_filter($sites, function ($x) { return ($x['health_status'] ?? '') === 'offline'; })),
        ],
    ]);
}
