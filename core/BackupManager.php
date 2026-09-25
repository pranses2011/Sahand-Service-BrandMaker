<?php
/**
 * 💾 مدیر بکاپ — با تاریخ شمسی و سیاست نگهداری ۵ نسخه
 * ===================================================
 * طبق سند بخش ۲۱ (پرامپت تکمیلی بخش ۱):
 *   - دقیقاً ۵ بکاپ آخر هر برند نگهداری می‌شود (ثابت)
 *   - نامگذاری: {brand-slug}_{YYYY-MM-DD}_{HH-mm-ss}.zip (تاریخ شمسی)
 *   - حذف خودکار بکاپ‌های اضافی پس از هر بکاپ جدید
 *   - الگوریتم تبدیل تاریخ کاملاً داخلی (ShamsiDate)
 *   - انواع بکاپ: manual / auto_before_update / weekly_scheduled / before_delete
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class BackupManager
{
    /** @var int تعداد بکاپ‌های نگهداری‌شده — ثابت طبق سند */
    const KEEP_COUNT = 5;

    /** @var CpanelAPI کلاینت cPanel */
    private $api;

    /** @var Database اتصال دیتابیس */
    private $db;

    /** @var string پوشه ریشه بکاپ‌ها روی سرور میزبان */
    private $backupsRoot;

    /**
     * 🔧 سازنده
     */
    public function __construct(?CpanelAPI $api = null)
    {
        $this->api = $api ?? new CpanelAPI();
        $this->db  = Database::getInstance();
        $settings  = PathResolver::getSettings();
        // مسیر بکاپ‌ها: کنار پوشه برندها — /public_html/brands/backups (طبق سند)
        $this->backupsRoot = '/public_html/brands/backups';
        if (!empty($settings['backup_dir'])) {
            $this->backupsRoot = '/' . trim((string)$settings['backup_dir'], '/');
        }
    }

    /**
     * 💾 ساخت بکاپ جدید از سایت برند
     *
     * @param int    $brandId شناسه برند
     * @param string $type نوع بکاپ (manual|auto_before_update|weekly_scheduled|before_delete)
     * @param int|null $deploymentId شناسه عملیات برای لاگ
     * @return array [success => bool, message => string, backup_id => int|null, filename => string|null]
     */
    public function createBackup(int $brandId, string $type = 'manual', ?int $deploymentId = null): array
    {
        $logger = $deploymentId ? new DeploymentLogger() : null;

        // ۱. برند باید استقرار داشته باشد
        $brand = $this->db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
        if (!$brand) {
            return ['success' => false, 'message' => 'برند یافت نشد.', 'backup_id' => null, 'filename' => null];
        }
        if (empty($brand['server_path'])) {
            return ['success' => false, 'message' => 'این برند هنوز استقرار ندارد — چیزی برای بکاپ نیست.', 'backup_id' => null, 'filename' => null];
        }

        // ۲. پوشه بکاپ برند (ساخت اگر نیست) — طبق سند: /public_html/brands/backups/{brand-slug}/
        $brandBackupDir = $this->backupsRoot . '/' . $brand['slug'];
        if (!$this->api->createDirectory($brandBackupDir)) {
            $msg = 'ساخت پوشه بکاپ ناموفق بود: ' . $this->api->getLastError();
            if ($logger) { $logger->stepFailed($deploymentId, 'backup_mkdir', $msg); }
            return ['success' => false, 'message' => $msg, 'backup_id' => null, 'filename' => null];
        }

        // ۳. تولید نام فایل با تاریخ شمسی — {brand-slug}_{YYYY-MM-DD}_{HH-mm-ss}.zip
        $filename = $brand['slug'] . '_' . ShamsiDate::forFilename() . '.zip';
        $remotePath = $brandBackupDir . '/' . $filename;

        // ۴. فشرده‌سازی پوشه سایت برند روی سرور میزبان — v2.18
        //    ✅ API2 رسمی Fileman::fileop (op=compress + metadata=zip + destfiles)
        //    🔴 قبلاً از UAPI صدا زده می‌شد — fileop در UAPI وجود ندارد!
        if (!$this->api->compressToZip($brand['server_path'], $remotePath)) {
            $msg = 'فشرده‌سازی بکاپ ناموفق بود: ' . $this->api->getLastError();
            if ($logger) { $logger->stepFailed($deploymentId, 'backup_compress', $msg); }
            return ['success' => false, 'message' => $msg, 'backup_id' => null, 'filename' => null];
        }

        // ۴.۵ ✅ راستی‌آمایی — فایل ZIP واقعا ساخته شده؟
        if (!$this->api->entryExists($remotePath)) {
            $msg = 'فشرده‌سازی گزارش موفقیت داد اما فایل بکاپ یافت نشد: ' . $remotePath;
            if ($logger) { $logger->stepFailed($deploymentId, 'backup_verify', $msg); }
            return ['success' => false, 'message' => $msg, 'backup_id' => null, 'filename' => null];
        }

        // ۵. تعیین حجم — از لیست فایل‌های پوشه بکاپ
        $size = 0;
        $files = $this->api->listFiles($brandBackupDir);
        foreach ($files as $f) {
            if (($f['file'] ?? $f['name'] ?? '') === $filename) {
                $size = (int)($f['size'] ?? 0);
                break;
            }
        }

        // ۶. ثبت رکورد در دیتابیس (با تاریخ شمسی — طبق ساختار سند)
        $now = time();
        $backupId = $this->db->insert('backups', [
            'brand_id'            => $brandId,
            'filename'            => $filename,
            'file_path'           => $remotePath,
            'file_size'           => $size,
            'backup_type'         => in_array($type, ['manual', 'auto_before_update', 'weekly_scheduled', 'before_delete'], true) ? $type : 'manual',
            'shamsi_date'         => ShamsiDate::full($now),          // 1404-03-25 14:30:45
            'shamsi_date_display' => ShamsiDate::forDisplay($now),    // ۱۴۰۴/۰۳/۲۵ ۱۴:۳۰
            'gregorian_date'      => date('Y-m-d H:i:s', $now),
            'created_at'          => date('Y-m-d H:i:s', $now),
        ]);

        if ($logger) {
            $logger->step($deploymentId, 'backup_created', 'بکاپ ساخته شد: ' . $filename);
            $logger->setBackupPath($deploymentId, $remotePath);
        }

        // ۷. 🧹 اجرای سیاست نگهداری — فقط ۵ بکاپ آخر بمانند
        $pruned = $this->enforceRetentionPolicy($brandId);

        Logger::info('[Backup] بکاپ ساخته شد', ['brand' => $brand['slug'], 'file' => $filename, 'pruned' => $pruned]);

        return [
            'success'   => true,
            'message'   => 'بکاپ «' . $filename . '» با موفقیت ساخته شد.' . ($pruned > 0 ? ' (' . $pruned . ' بکاپ قدیمی حذف شد)' : ''),
            'backup_id' => $backupId,
            'filename'  => $filename,
        ];
    }

    /**
     * 🧹 اجرای سیاست نگهداری — حذف بکاپ‌های اضافی (بیش از ۵)
     *
     * @param int $brandId شناسه برند
     * @return int تعداد بکاپ‌های حذف‌شده
     */
    public function enforceRetentionPolicy(int $brandId): int
    {
        // مرتب‌سازی بر اساس زمان — قدیمی‌ها آخر
        $backups = $this->db->fetchAll(
            'SELECT * FROM backups WHERE brand_id = ? ORDER BY id DESC',
            [$brandId]
        );

        $count = count($backups);
        if ($count <= self::KEEP_COUNT) {
            return 0;
        }

        // بکاپ‌های اضافی (بعد از ۵ تای اول)
        $toDelete = array_slice($backups, self::KEEP_COUNT);
        $deleted = 0;

        foreach ($toDelete as $backup) {
            // ۱. حذف فایل ZIP از سرور میزبان
            $this->api->deleteFile((string)$backup['file_path']);
            // ۲. حذف رکورد از دیتابیس
            $this->db->delete('backups', 'id = ?', [(int)$backup['id']]);
            $deleted++;
        }

        if ($deleted > 0) {
            Logger::info('[Backup] سیاست نگهداری اجرا شد', ['brand_id' => $brandId, 'deleted' => $deleted]);
        }
        return $deleted;
    }

    /**
     * 📋 لیست بکاپ‌های برند (جدیدترین اول)
     *
     * @param int $brandId شناسه برند
     */
    public function getBackupsList(int $brandId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM backups WHERE brand_id = ? ORDER BY id DESC LIMIT ' . self::KEEP_COUNT,
            [$brandId]
        );
    }

    /**
     * 📊 آمار بکاپ‌های برند (برای نمایش پنل)
     */
    public function getStats(int $brandId): array
    {
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS cnt, COALESCE(SUM(file_size),0) AS total_size FROM backups WHERE brand_id = ?',
            [$brandId]
        );
        return [
            'count'      => (int)($row['cnt'] ?? 0),
            'max'        => self::KEEP_COUNT,
            'total_size' => (int)($row['total_size'] ?? 0),
        ];
    }

    /**
     * 🔄 بازیابی از بکاپ (Rollback)
     * حذف فایل‌های فعلی + استخراج بکاپ
     *
     * @param int         $backupId شناسه بکاپ
     * @param int|null    $deploymentId شناسه عملیات
     * @return array [success => bool, message => string]
     */
    public function restoreBackup(int $backupId, ?int $deploymentId = null): array
    {
        $logger = $deploymentId ? new DeploymentLogger() : null;

        $backup = $this->db->fetch('SELECT * FROM backups WHERE id = ?', [$backupId]);
        if (!$backup) {
            return ['success' => false, 'message' => 'بکاپ یافت نشد.'];
        }

        $brand = $this->db->fetch('SELECT * FROM brands WHERE id = ?', [(int)$backup['brand_id']]);
        if (!$brand || empty($brand['server_path'])) {
            return ['success' => false, 'message' => 'اطلاعات مسیر سرور برند نامعتبر است.'];
        }

        $serverPath = (string)$brand['server_path'];

        // ۱. بکاپ ایمنی از وضعیت فعلی (قبل از خرابکاری!)
        if ($logger) { $logger->step($deploymentId, 'rollback_safety', 'بکاپ ایمنی از وضعیت فعلی...'); }
        $this->createBackup((int)$brand['id'], 'before_delete');

        // ۲. حذف پوشه فعلی سایت
        if ($logger) { $logger->step($deploymentId, 'rollback_clean', 'حذف فایل‌های فعلی...'); }
        if (!$this->api->deleteDirectory($serverPath)) {
            $msg = 'حذف فایل‌های فعلی ناموفق بود: ' . $this->api->getLastError();
            if ($logger) { $logger->stepFailed($deploymentId, 'rollback_clean', $msg); }
            return ['success' => false, 'message' => $msg];
        }

        // ۳. ساخت مجدد پوشه
        $this->api->createDirectory($serverPath);

        // ۴. استخراج بکاپ در مسیر سایت — v2.18: extractZip خودش ZIP خارج از مقصد را
        //    ابتدا به داخل مقصد کپی می‌کند، استخراج می‌کند و کپی را پاک می‌کند
        if ($logger) { $logger->step($deploymentId, 'rollback_extract', 'استخراج بکاپ...'); }
        if (!$this->api->extractZip((string)$backup['file_path'], $serverPath)) {
            $msg = 'استخراج بکاپ ناموفق بود: ' . $this->api->getLastError();
            if ($logger) { $logger->stepFailed($deploymentId, 'rollback_extract', $msg); }
            return ['success' => false, 'message' => $msg];
        }

        // ۵. اصلاح ساختار — ZIP شامل خود پوشه برند است؛ فایل‌ها باید داخل serverPath باشند
        $this->api->flattenSingleChildDir($serverPath);

        Logger::info('[Backup] بازیابی انجام شد', ['backup_id' => $backupId]);
        return ['success' => true, 'message' => 'سایت از بکاپ «' . $backup['filename'] . '» بازیابی شد.'];
    }

    /**
     * 🗑️ حذف دستی یک بکاپ
     *
     * @param int $backupId شناسه بکاپ
     * @return array [success => bool, message => string]
     */
    public function deleteBackup(int $backupId): array
    {
        $backup = $this->db->fetch('SELECT * FROM backups WHERE id = ?', [$backupId]);
        if (!$backup) {
            return ['success' => false, 'message' => 'بکاپ یافت نشد.'];
        }

        // حذف فایل از سرور
        $this->api->deleteFile((string)$backup['file_path']);
        // حذف رکورد
        $this->db->delete('backups', 'id = ?', [$backupId]);

        return ['success' => true, 'message' => 'بکاپ «' . $backup['filename'] . '» حذف شد.'];
    }

    /**
     * ⬇️ دانلود بکاپ — خواندن محتوا از سرور میزبان
     * (بازگرداندن محتوای ZIP به صورت base64)
     *
     * @param int $backupId شناسه بکاپ
     * @return array [success => bool, data => string|null (base64), filename => string]
     */
    public function downloadBackup(int $backupId): array
    {
        $backup = $this->db->fetch('SELECT * FROM backups WHERE id = ?', [$backupId]);
        if (!$backup) {
            return ['success' => false, 'data' => null, 'filename' => ''];
        }

        // Fileman::get_file_content برای فایل باینری ZIP
        $content = $this->api->readFile((string)$backup['file_path']);
        if ($content === '') {
            // 🔄 روش جایگزین: کپی به پوشه public_html سایت ساز و لینک دانلود مستقیم
            return ['success' => false, 'data' => null, 'filename' => $backup['filename']];
        }

        return ['success' => true, 'data' => base64_encode($content), 'filename' => (string)$backup['filename']];
    }

    /**
     * 🗓️ بکاپ هفتگی زمان‌بندی‌شده (برای cron)
     * فقط برندهای استقرارشده
     *
     * @return array نتیجه هر برند
     */
    public function weeklyScheduledBackup(): array
    {
        $brands = $this->db->fetchAll('SELECT id, slug FROM brands WHERE is_deployed = 1 AND is_active = 1');
        $results = [];
        foreach ($brands as $brand) {
            $r = $this->createBackup((int)$brand['id'], 'weekly_scheduled');
            $results[$brand['slug']] = $r['success'] ? 'ok' : $r['message'];
        }
        return $results;
    }

    /**
     * 🔧 انتقال فایل‌های استخراج‌شده از زیرپوشه به ریشه مسیر
     * v2.18: به CpanelAPI::flattenSingleChildDir منتقل شد (پیاده‌سازی قبلی fileop
     * op=move با wildcard را از UAPI صدا می‌زد — تابع و wildcard هر دو نامعتبر بودند)
     */

    /**
     * 🏷️ برچسب فارسی نوع بکاپ
     */
    public static function typeLabel(string $type): string
    {
        $map = [
            'manual'             => '🖐️ دستی',
            'auto_before_update' => '🔄 قبل از بروزرسانی',
            'weekly_scheduled'   => '📅 هفتگی',
            'before_delete'      => '🗑️ قبل از حذف',
        ];
        return $map[$type] ?? $type;
    }
}
