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
    /* 👤 جزئیات یک نشست خاص */
    if (!empty($_GET['details'])) {
        $sessionHash = (string)$_GET['details'];
        if (!preg_match('/^[a-f0-9]{16,64}$/', $sessionHash)) {
            online_json(['success' => false, 'error' => 'شناسه نشست نامعتبر']);
        }
        $v = $db->fetch(
            'SELECT v.*, b.name_fa AS brand_name FROM visits v LEFT JOIN brands b ON b.id = v.brand_id
             WHERE v.session_hash = ? ORDER BY v.id DESC LIMIT 1',
            [$sessionHash]
        );
        if (!$v) {
            online_json(['success' => false, 'error' => 'نشست یافت نشد']);
        }
        $pages = $db->fetchAll(
            'SELECT page_url, duration, viewed_at FROM visit_details WHERE visit_id = ? ORDER BY id DESC LIMIT 10',
            [(int)$v['id']]
        );
        $totalDur = (int)$db->fetchValue(
            'SELECT COALESCE(SUM(duration), 0) FROM visit_details WHERE visit_id = ?',
            [(int)$v['id']]
        );
        $deviceFa = ['mobile' => '📱 موبایل', 'desktop' => '🖥️ رایانه رومیزی', 'tablet' => '📲 تبلت', 'bot' => '🤖 ربات'];
        online_json([
            'success' => true,
            'details' => [
                'brand_name'    => (string)($v['brand_name'] ?? ''),
                'device_type'   => $deviceFa[$v['device_type']] ?? $v['device_type'],
                'browser'       => (string)($v['browser'] ?? ''),
                'os'            => (string)($v['os'] ?? ''),
                'resolution'    => (string)($v['resolution'] ?? ''),
                'language'      => (string)($v['language'] ?? ''),
                'city'          => (string)($v['city'] ?? ''),
                'province'      => (string)($v['province'] ?? ''),
                'entry_page'    => (string)($v['entry_page'] ?? ''),
                'current_page'  => (string)($pages[0]['page_url'] ?? ''),
                'referrer'      => (string)($v['referrer'] ?? ''),
                'last_seen'     => jdate((string)($v['last_seen'] ?: $v['visited_at']), true),
                'pages_seen'    => count($pages),
                'duration_text' => gmdate('i:s', $totalDur) . ' دقیقه',
                'recent_pages'  => array_map(static function ($p) {
                    return [
                        'url'      => mb_substr((string)$p['page_url'], 0, 60),
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
