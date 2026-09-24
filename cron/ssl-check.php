<?php
/**
 * ⏰ Cron بررسی و تمدید SSL سایت‌های برند
 * ======================================
 * طبق سند بخش ۲۱ (بخش ۷): روزانه ساعت ۳ صبح
 *
 * نصب در cPanel ▸ Cron Jobs:
 *   0 3 * * * php /home/user/public_html/brandmaker/cron/ssl-check.php
 *
 * وظایف:
 *   ۱. بررسی وضعیت SSL همه برندهای مستقر
 *   ۲. درخواست نصب برای SSLهای از دست رفته
 *   ۳. هشدار ۳۰ روز قبل از انقضا (طبق سند)
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
define('SAHAND_NO_SESSION', true);
require_once dirname(__DIR__) . '/config.php';

/* 🛡️ مجوز اجرا: CLI یا توکن */
$token = (string)($_GET['token'] ?? ($argv[1] ?? ''));
$cronToken = Config::get('cron_secret_token', '');
if (PHP_SAPI !== 'cli' && ($cronToken === '' || !hash_equals($cronToken, $token))) {
    http_response_code(403);
    exit('⛔ دسترسی مجاز نیست');
}

set_time_limit(600);
echo "🔒 شروع بررسی SSL — " . date('Y-m-d H:i:s') . "\n";

try {
    $db = Database::getInstance();
    $ssl = new SSLManager();
    $brands = $db->fetchAll('SELECT id, name_fa, full_domain FROM brands WHERE is_deployed = 1 AND is_active = 1');

    $renewed = 0;
    $warned = 0;
    $reinstall = 0;

    foreach ($brands as $brand) {
        if (empty($brand['full_domain'])) {
            continue;
        }

        // ۱. بررسی وضعیت + تمدید (AutoSSL خود cPanel تمدید می‌کند — ما فقط رصد می‌کنیم)
        $result = $ssl->checkRenewal((int)$brand['id']);

        // ۲. SSL از دست رفته → تلاش برای نصب مجدد
        if ($result['needs_renewal'] && $result['days_left'] === null) {
            echo "   🔄 نصب مجدد SSL: {$brand['name_fa']} ({$brand['full_domain']})\n";
            $installResult = $ssl->installSSL((string)$brand['full_domain']);
            if ($installResult['success']) {
                $reinstall++;
            }
            continue;
        }

        // ۳. هشدار ۳۰ روز قبل از انقضا (طبق سند)
        if ($result['needs_renewal'] && $result['days_left'] !== null) {
            $warned++;
            echo "   ⚠️ انقضای نزدیک: {$brand['name_fa']} — {$result['days_left']} روز مانده\n";
            NotificationService::notify(
                1,
                'ssl',
                '⚠️ SSL نزدیک انقضا: ' . $brand['name_fa'],
                'گواهی SSL دامنه ' . $brand['full_domain'] . ' تا ' . $result['days_left'] . ' روز دیگر منقضی می‌شود. AutoSSL باید خودکار تمدید کند — در صورت عدم تمدید، از cPanel بررسی کنید.',
                'health-dashboard.php'
            );
        } else {
            $renewed++;
        }
    }

    echo "✅ بررسی SSL کامل شد:\n";
    echo "   - 🟢 سالم: {$renewed}\n";
    echo "   - ⚠️ هشدار انقضا: {$warned}\n";
    echo "   - 🔄 نصب مجدد: {$reinstall}\n";

    Logger::info('[Cron/SSL] بررسی کامل شد', ['healthy' => $renewed, 'warned' => $warned, 'reinstall' => $reinstall]);
    exit(0);
} catch (Throwable $e) {
    echo "❌ خطا: " . $e->getMessage() . "\n";
    Logger::log('ERROR', '[Cron/SSL] خطای اجرا', ['error' => $e->getMessage()]);
    exit(1);
}
