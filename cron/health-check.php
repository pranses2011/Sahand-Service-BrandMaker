<?php
/**
 * ⏰ Cron بررسی سلامت سایت‌های برند
 * =================================
 * طبق سند بخش ۲۱ (بخش ۹): هر ۱۵ دقیقه
 *
 * نصب در cPanel ▸ Cron Jobs (هر ۱۵ دقیقه):
 *   star/15 * * * star php /home/user/public_html/brandmaker/cron/health-check.php
 *   (star = کاراکتر ستاره)
 *
 * 🔒 امنیت:
 *   - فقط از CLI اجرا می‌شود (دسترسی وب مسدود است)
 *   - یا با توکن مخفی ?token=... (برای cron خارجی)
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

/* ⏱️ بدون محدودیت زمان اجرا + لاگ خروجی */
set_time_limit(300);
echo "🩺 شروع بررسی سلامت — " . date('Y-m-d H:i:s') . "\n";

try {
    $checker = new HealthChecker();
    $results = $checker->checkAll();

    echo "✅ بررسی کامل شد:\n";
    echo "   - کل سایت‌های مستقر: {$results['checked']}\n";
    echo "   - 🟢 آنلاین: {$results['online']}\n";
    echo "   - 🔴 آفلاین: {$results['offline']}\n";
    echo "   - ⚠️ خطا: {$results['error']}\n";

    // ⚠️ اعلان سایت‌های down (طبق سند: ایمیل + تلگرام)
    if ($results['offline'] > 0) {
        Logger::log('WARNING', '[Cron/Health] سایت آفلاین شناسایی شد', $results);
        $offlineBrands = Database::getInstance()->fetchAll(
            "SELECT name_fa, full_domain FROM brands WHERE is_deployed = 1 AND is_active = 1 AND health_status = 'offline'"
        );
        foreach ($offlineBrands as $b) {
            NotificationService::notify(
                1,
                'health',
                '🔴 سایت آفلاین: ' . $b['name_fa'],
                'سایت https://' . $b['full_domain'] . ' پاسخ نمی‌دهد — لطفاً بررسی کنید.',
                'health-dashboard.php'
            );
        }
    } else {
        Logger::info('[Cron/Health] همه سایت‌ها آنلاین هستند', $results);
    }

    exit(0);
} catch (Throwable $e) {
    echo "❌ خطا: " . $e->getMessage() . "\n";
    Logger::log('ERROR', '[Cron/Health] خطای اجرا', ['error' => $e->getMessage()]);
    exit(1);
}
