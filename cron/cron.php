<?php
/**
 * ⏰ Cron اصلی سایت‌ساز — زمان‌بندی انتشار + نگهداری داده (v2.34)
 * =================================================================
 * یک نقطه ورود برای همه وظایف دوره‌ای جدید (P1 #15 + #16):
 *
 *   ۱) انتشار مقالات زمان‌بندی‌شده — status='scheduled' و زمانش رسیده → published
 *   ۲) خلاصه‌سازی آمار قدیمی — بازدیدهای > ۱۸ ماه → جدول visits_daily (خلاصه ماندگار)
 *      و بعد پاک‌سازی ردیف‌های خام (کنترل رشد دیتابیس)
 *   ۳) نظافت: توکن‌های بازیابی منقضی + شمارنده‌های نرخ کهنه
 *
 * نصب (هر ۵ دقیقه — cPanel ▸ Cron Jobs):
 *   star/5 * * * star php /home/user/public_html/brandmaker/cron/cron.php
 *   (star = کاراکتر ستاره)
 *
 * 🔒 امنیت: فقط CLI یا توکن مخفی ?token= (مثل بقیه cronهای سیستم)
 * 🔒 قفل flock: اجرای موازی محال است
 * خروجی: خلاصه JSON (برای لاگ cron) — --quiet برای خاموشی
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
define('SAHAND_NO_SESSION', true);
require_once dirname(__DIR__) . '/config.php';

/* 🛡️ مجوز اجرا: CLI یا توکن */
$token = (string)($_GET['token'] ?? ($argv[1] ?? ''));
$cronToken = Config::get('cron_secret_token', '');
$isCli = PHP_SAPI === 'cli';
if (!$isCli && ($cronToken === '' || !hash_equals($cronToken, $token))) {
    http_response_code(403);
    exit('⛔ دسترسی مجاز نیست');
}
$quiet = in_array('--quiet', $argv ?? [], true);
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

/* 🔒 قفل اجرا — اگر نمونه دیگری در حال اجراست، بدون خطا خارج شو */
$lockFile = CACHE_PATH . '/cron-main.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$quiet) {
        echo json_encode(['success' => true, 'skipped' => 'another instance running', 'at' => date('c')], JSON_UNESCAPED_UNICODE);
    }
    exit(0);
}

$db = Database::getInstance();
$report = ['at' => date('c'), 'tasks' => []];

/* ════════════ وظیفه ۱: انتشار مقالات زمان‌بندی‌شده ════════════ */
try {
    $due = $db->fetchAll(
        "SELECT id, brand_id, title, slug FROM brand_articles
         WHERE status = 'scheduled' AND published_at IS NOT NULL AND published_at <= NOW()"
    );
    $published = 0;
    foreach ($due as $a) {
        $db->update('brand_articles', ['status' => 'published'], 'id = ?', [$a['id']]);
        $published++;
        Logger::activity(0, 'انتشار زمان‌بندی‌شده', "مقاله «{$a['title']}» (برند #{$a['brand_id']}) سر زمان رسید و منتشر شد");
        /* 📡 v2.34 — IndexNow: مقاله تازه منتشرشده به موتورهای جستجو اطلاع داده شود */
        try {
            if (class_exists('IndexNow')) {
                IndexNow::pingArticle((int)$a['brand_id'], (string)$a['slug']);
            }
        } catch (Throwable $inE) { /* fire-and-forget — شبکه نباید cron را بشکند */ }
        /* 🪝 v2.37 — P3: رویداد وب‌هوک article.published */
        try {
            if (class_exists('WebhookDispatcher')) {
                WebhookDispatcher::articlePublished($db, (int)$a['id']);
            }
        } catch (Throwable $whE) { /* fire-and-forget */ }
    }
    if ($published > 0) {
        /* 🧹 کش مقالات همه برندها پاک شود تا بلافاصله در سایت دیده شوند */
        (new Cache())->delete('brand_articles_all');
        (new Cache())->flush('api_brand_');
    }
    $report['tasks']['publish_scheduled'] = ['ok' => true, 'published' => $published];
} catch (Throwable $e) {
    $report['tasks']['publish_scheduled'] = ['ok' => false, 'error' => $e->getMessage()];
}

/* ════════════ وظیفه ۲: خلاصه‌سازی + پاک‌سازی آمار > ۱۸ ماه ════════════ */
try {
    /* ⚙️ سیاست نگهداری: پیش‌فرض ۱۸ ماه — از تنظیمات قابل تغییر (retention_months) */
    $retentionMonths = max(6, (int)(Config::get('retention_months') ?: 18));
    $cutoff = date('Y-m-d', strtotime('-' . $retentionMonths . ' months'));
    $result = summarize_old_visits($db, $cutoff);
    $report['tasks']['retention'] = ['ok' => true, 'months' => $retentionMonths] + $result;
} catch (Throwable $e) {
    $report['tasks']['retention'] = ['ok' => false, 'error' => $e->getMessage()];
}

/* ════════════ وظیفه ۳: نظافت توکن‌ها و شمارنده‌ها ════════════ */
try {
    $pr1 = $db->delete('password_resets', 'expires_at < NOW() - INTERVAL 1 DAY');
    $pr2 = $db->delete('rate_limits', 'expires_at < NOW() - INTERVAL 1 DAY');
    $report['tasks']['cleanup'] = ['ok' => true, 'expired_resets' => $pr1, 'expired_limits' => $pr2];
} catch (Throwable $e) {
    $report['tasks']['cleanup'] = ['ok' => false, 'error' => $e->getMessage()];
}

/* ════════════ وظیفه ۴: تلاش مجدد وب‌هوک‌های ناموفق (P3 — v2.37) ════════════ */
try {
    if (class_exists('WebhookDispatcher')) {
        $retry = WebhookDispatcher::retryPending(25);
        $report['tasks']['webhooks'] = ['ok' => true] + $retry;
    }
} catch (Throwable $e) {
    $report['tasks']['webhooks'] = ['ok' => false, 'error' => $e->getMessage()];
}

/* 📤 خروجی */
$report['success'] = true;
if (!$quiet) {
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

/**
 * 🗄️ خلاصه‌سازی بازدیدهای قدیمی به visits_daily + حذف ردیف‌های خام
 *
 * استراتژی: برای هر (برند، روز) از بازدیدهای کهنه‌تر از cutoff:
 *   ۱) اگر خلاصه آن روز قبلاً ساخته شده → فقط ردیف‌های خام را پاک کن
 *      (شناسه: visits_daily.updated_at — خلاصه هرگز دوباره ساخته نمی‌شود)
 *   ۲) وگرنه: تجمیع (بازدید یکتا / بازدیدکننده یکتا / نمای صفحه / دستگاه / استان)
 *      → درج ON DUPLICATE KEY → بعد پاک‌سازی همان روز
 *
 * @return array ['summarized_days'=>int, 'deleted_rows'=>int]
 */
function summarize_old_visits(Database $db, string $cutoff): array
{
    /* روزهای (برند، تاریخ) needing summary */
    $days = $db->fetchAll(
        'SELECT brand_id, DATE(visited_at) AS d
         FROM visits
         WHERE visited_at < ? AND visit_date IS NOT NULL
         GROUP BY brand_id, DATE(visited_at), visit_date
         LIMIT 400',
        [$cutoff]
    );

    $summarized = 0;
    $deleted = 0;
    foreach ($days as $day) {
        $brandId = (int)$day['brand_id'];
        $date = (string)$day['d'];

        /* فقط روزهایی که هنوز خلاصه ندارند */
        $exists = $db->fetch('SELECT id FROM visits_daily WHERE brand_id = ? AND stat_date = ? LIMIT 1', [$brandId, $date]);
        if (!$exists) {
            $agg = $db->fetch(
                'SELECT COUNT(*) AS visits,
                        COUNT(DISTINCT session_hash) AS visitors,
                        (SELECT COUNT(*) FROM visit_details vd JOIN visits v2 ON v2.id = vd.visit_id
                         WHERE v2.brand_id = ? AND v2.visit_date = ?) AS page_views
                 FROM visits WHERE brand_id = ? AND visit_date = ?',
                [$brandId, $date, $brandId, $date]
            );
            $devices = $db->fetchAll(
                'SELECT device_type, COUNT(*) AS c FROM visits WHERE brand_id = ? AND visit_date = ? GROUP BY device_type',
                [$brandId, $date]
            );
            $provinces = $db->fetchAll(
                'SELECT province, COUNT(*) AS c FROM visits WHERE brand_id = ? AND visit_date = ? AND province IS NOT NULL AND province <> \'\' GROUP BY province',
                [$brandId, $date]
            );
            $deviceMap = [];
            foreach ($devices as $dv) {
                $deviceMap[(string)$dv['device_type']] = (int)$dv['c'];
            }
            $provMap = [];
            foreach ($provinces as $pv) {
                $provMap[(string)$pv['province']] = (int)$pv['c'];
            }
            try {
                $db->query(
                    'INSERT INTO visits_daily (brand_id, stat_date, visits, visitors, page_views, device_types, provinces)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE visits = VALUES(visits), visitors = VALUES(visitors),
                       page_views = VALUES(page_views), device_types = VALUES(device_types), provinces = VALUES(provinces)',
                    [$brandId, $date, (int)$agg['visits'], (int)$agg['visitors'], (int)$agg['page_views'],
                     json_encode($deviceMap, JSON_UNESCAPED_UNICODE), json_encode($provMap, JSON_UNESCAPED_UNICODE)]
                );
                $summarized++;
            } catch (Throwable $e) {
                /* اگر خلاصه نشد، خام‌ها را پاک نکن */
                continue;
            }
        }

        /* پاک‌سازی خام‌های همان روز (خلاصه موجود است) */
        $deleted += $db->delete('visits', 'brand_id = ? AND visit_date = ?', [$brandId, $date]);
    }
    return ['summarized_days' => $summarized, 'deleted_rows' => $deleted];
}

flock($lock, LOCK_UN);
fclose($lock);
exit(0);
