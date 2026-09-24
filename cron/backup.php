<?php
/**
 * ⏰ Cron بکاپ خودکار هفتگی سایت‌های برند
 * =====================================
 * طبق سند بخش ۲۱ (بخش ۱۰): هفتگی شنبه ساعت ۲ صبح
 *
 * نصب در cPanel ▸ Cron Jobs:
 *   0 2 * * 6 php /home/user/public_html/brandmaker/cron/backup.php
 *
 * وظایف:
 *   ۱. بکاپ از همه برندهای مستقر (نوع: weekly_scheduled)
 *   ۲. اجرای سیاست نگهداری — فقط ۵ بکاپ آخر هر برند
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

set_time_limit(900); // بکاپ چند سایت ممکن است طول بکشد
echo "💾 شروع بکاپ هفتگی — " . date('Y-m-d H:i:s') . "\n";

try {
    $backupMgr = new BackupManager();
    $results = $backupMgr->weeklyScheduledBackup();

    $ok = 0;
    $failed = 0;
    foreach ($results as $slug => $result) {
        if ($result === 'ok') {
            $ok++;
            echo "   ✅ {$slug}\n";
        } else {
            $failed++;
            echo "   ❌ {$slug}: {$result}\n";
        }
    }

    echo "✅ بکاپ هفتگی کامل شد: {$ok} موفق / {$failed} ناموفق\n";
    Logger::info('[Cron/Backup] بکاپ هفتگی', ['ok' => $ok, 'failed' => $failed]);

    // ⚠️ اعلان در صورت خطای بکاپ (طبق سند)
    if ($failed > 0) {
        NotificationService::notify(
            1,
            'backup',
            '⚠️ خطای بکاپ هفتگی',
            $failed . ' بکاپ از ' . ($ok + $failed) . ' سایت ناموفق بود — جزئیات در لاگ سیستم.',
            'backups.php'
        );
    }

    exit(0);
} catch (Throwable $e) {
    echo "❌ خطا: " . $e->getMessage() . "\n";
    Logger::log('ERROR', '[Cron/Backup] خطای اجرا', ['error' => $e->getMessage()]);
    exit(1);
}
