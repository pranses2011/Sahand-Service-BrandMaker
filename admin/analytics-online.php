<?php
/**
 * 👥 کاربران آنلاین — اندپوینت AJAX برای پنل آمار (v2.31)
 * =========================================================
 * دو حالت:
 *   ajax=1                  → فهرست کاربران آنلاین (۵ دقیقه اخیر)
 *   ajax=1&details=<hash>   → جزئیات کامل یک نشست (کادر مودال)
 *
 * @package SahandBrandMaker
 */
define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

/* 🛡️ فقط مدیر واردشده (همان الگوی header.php) */
$auth = new Auth();
$auth->requireLogin();
/* 🛡️ v2.33 — دفاع در عمق: رد درخواست بین‌سایتی (گزارش تحلیل — بخش امنیت) */
reject_cross_origin();


header('Content-Type: application/json; charset=UTF-8');

if (empty($_GET['ajax'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'درخواست نامعتبر'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = Database::getInstance();
$brandFilter = (int)($_GET['brand'] ?? 0);
$onlineSince = date('Y-m-d H:i:s', time() - 300);

function online_json(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    /* 👤 جزئیات یک نشست خاص — 🆕 v2.41: گزارش کامل و حرفه‌ای
       (بازدیدهای قبلی همان نشست + کل مدت + مرورگر/OS ساختارمند +
       معیار تعامل + مسیر کامل تا ۲۰ صفحه) */
    if (!empty($_GET['details'])) {
        $sessionHash = (string)$_GET['details'];
        if (!preg_match('/^[a-f0-9]{16,64}$/', $sessionHash)) {
            online_json(['success' => false, 'error' => 'شناسه نشست نامعتبر']);
        }
        $v = $db->fetch(
            'SELECT v.*, b.name_fa AS brand_name, b.logo AS brand_logo, b.domain AS brand_domain FROM visits v LEFT JOIN brands b ON b.id = v.brand_id
             WHERE v.session_hash = ? ORDER BY v.last_seen DESC, v.id DESC LIMIT 1',
            [$sessionHash]
        );
        if (!$v) {
            online_json(['success' => false, 'error' => 'نشست یافت نشد']);
        }
        /* 📑 مسیر کامل تا ۲۰ صفحه اخیر (با مدت هر کدام) */
        $pages = $db->fetchAll(
            'SELECT page_url, duration, viewed_at FROM visit_details WHERE visit_id = ? ORDER BY id DESC LIMIT 20',
            [(int)$v['id']]
        );
        $totalDur = (int)$db->fetchValue(
            'SELECT COALESCE(SUM(duration), 0) FROM visit_details WHERE visit_id = ?',
            [(int)$v['id']]
        );
        /* 🔄 بازدیدهای قبلی همین نشست (بازگشتی بودن) */
        $visitCount = (int)$db->fetchValue(
            'SELECT COUNT(*) FROM visits WHERE session_hash = ?',
            [$sessionHash]
        );
        $firstSeen = (string)$db->fetchValue(
            'SELECT MIN(visited_at) FROM visits WHERE session_hash = ?',
            [$sessionHash]
        );
        $deviceFa = ['mobile' => '📱 موبایل', 'desktop' => '🖥️ رایانه رومیزی', 'tablet' => '📲 تبلت', 'bot' => '🤖 ربات'];
        /* 🏷️ نام‌های محوشده/ساختارمند مرورگر و سیستم‌عامل */
        $browserLabel = trim(($v['browser'] ?? '') . (!empty($v['browser_version']) ? ' ' . en_to_fa_digits((string)$v['browser_version']) : ''));
        online_json([
            'success' => true,
            'details' => [
                'brand_name'    => (string)($v['brand_name'] ?? ''),
                'brand_logo'    => (string)($v['brand_logo'] ?? ''),
                'brand_domain'  => (string)($v['brand_domain'] ?? ''),
                'device_type'   => $deviceFa[$v['device_type']] ?? $v['device_type'],
                'device_raw'    => (string)$v['device_type'],
                'browser'       => $browserLabel !== '' ? $browserLabel : 'نامشخص',
                'os'            => (string)($v['os'] ?? ''),
                'resolution'    => (string)($v['resolution'] ?? ''),
                'language'      => (string)($v['language'] ?? ''),
                'country'       => (string)($v['country'] ?? ''),
                'city'          => (string)($v['city'] ?? ''),
                'province'      => (string)($v['province'] ?? ''),
                'geo_src'       => (string)($v['geo_src'] ?? ''),
                'entry_page'    => (string)($v['entry_page'] ?? ''),
                'current_page'  => (string)($pages[0]['page_url'] ?? ''),
                'referrer'      => (string)($v['referrer'] ?? ''),
                'search_keyword'=> (string)($v['search_keyword'] ?? ''),
                'last_seen'     => jdate((string)($v['last_seen'] ?: $v['visited_at']), true),
                'first_seen'    => $firstSeen !== '' ? jdate($firstSeen, true) : '',
                'visit_count'   => $visitCount,
                'pages_seen'    => count($pages),
                'duration_text' => $totalDur >= 3600 ? gmdate('H:i:s', $totalDur) : gmdate('i:s', $totalDur) . ' دقیقه',
                'duration_sec'  => $totalDur,
                'recent_pages'  => array_map(static function ($p) {
                    return [
                        'url'      => mb_substr((string)$p['page_url'], 0, 80),
                        'time'     => en_to_fa_digits(substr((string)$p['viewed_at'], 11, 5)),
                        'duration' => (int)$p['duration'],
                    ];
                }, $pages),
            ],
        ]);
    }

    /* 📋 فهرست کاربران آنلاین */
    $where = 'v.last_seen >= ?';
    $params = [$onlineSince];
    if ($brandFilter > 0) {
        $where .= ' AND v.brand_id = ?';
        $params[] = $brandFilter;
    }
    $users = $db->fetchAll(
        "SELECT v.session_hash, v.device_type, v.browser, v.city, v.province, v.last_seen,
                b.name_fa AS brand_name,
                (SELECT vd.page_url FROM visit_details vd WHERE vd.visit_id = v.id ORDER BY vd.id DESC LIMIT 1) AS current_page
         FROM visits v LEFT JOIN brands b ON b.id = v.brand_id
         WHERE {$where}
         ORDER BY v.last_seen DESC LIMIT 24",
        $params
    );
    online_json([
        'success' => true,
        'count'   => count($users),
        'users'   => array_map(static function ($u) {
            return [
                'session'     => (string)$u['session_hash'],
                'brand_name'  => (string)($u['brand_name'] ?? ''),
                'device_type' => (string)$u['device_type'],
                'browser'     => (string)($u['browser'] ?? ''),
                'city'        => (string)($u['city'] ?? ''),
                'province'    => (string)($u['province'] ?? ''),
                'current_page'=> mb_substr((string)($u['current_page'] ?? ''), 0, 40),
                'minutes_ago' => max(0, (int)floor((time() - strtotime((string)$u['last_seen'])) / 60)),
            ];
        }, $users),
    ]);
} catch (Throwable $e) {
    online_json(['success' => false, 'error' => 'خطای پایگاه داده: ' . $e->getMessage()]);
}
