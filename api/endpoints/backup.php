<?php
/**
 * 💾 اندپوینت API بکاپ (خارج از سایت ساز)
 * ======================================
 * طبق سند بخش ۲۱ — api/endpoints/backup.php
 *
 * 🔒 امنیت: علاوه بر X-API-Key، کلید باید مجوز can_deploy داشته باشد
 *
 * اندپوینت‌ها:
 *   GET  /api/backup/{brandId}            → لیست بکاپ‌های برند (۵ نسخه اخیر)
 *   POST /api/backup/{brandId}/create     → بکاپ فوری
 *   POST /api/backup/{backupId}/restore   → بازیابی از بکاپ
 *   POST /api/backup/{backupId}/delete    → حذف بکاپ
 *
 * @package SahandBrandMaker
 */

if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

/**
 * 🛡️ بررسی مجوز بکاپ (همان مجوز استقرار)
 */
function api_backup_require_permission(): void
{
    $key = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? '');
    $record = Database::getInstance()->fetch(
        'SELECT id, can_deploy FROM api_keys WHERE api_key = ? AND is_active = 1 LIMIT 1',
        [$key]
    );
    if (!$record || empty($record['can_deploy'])) {
        json_response([
            'success' => false,
            'error'   => 'این کلید API مجوز مدیریت بکاپ ندارد — کلید با دسترسی can_deploy لازم است',
        ], 403);
    }
}

/**
 * 📋 لیست بکاپ‌های برند
 */
function api_backup_list(int $brandId): void
{
    api_backup_require_permission();

    $db = Database::getInstance();
    $brand = $db->fetch('SELECT id, name_fa FROM brands WHERE id = ?', [$brandId]);
    if (!$brand) {
        json_response(['success' => false, 'error' => 'برند یافت نشد'], 404);
    }

    $backupMgr = new BackupManager();
    $backups = $backupMgr->getBackupsList($brandId);
    $stats = $backupMgr->getStats($brandId);

    json_response([
        'success' => true,
        'data'    => [
            'brand' => $brand['name_fa'],
            'stats' => ['count' => $stats['count'], 'max' => $stats['max'], 'total_size' => $stats['total_size']],
            'backups' => array_map(function ($b) {
                return [
                    'id'             => (int)$b['id'],
                    'filename'       => $b['filename'],
                    'size'           => (int)$b['file_size'],
                    'type'           => $b['backup_type'],
                    'shamsi_date'    => $b['shamsi_date'],
                    'shamsi_display' => $b['shamsi_date_display'],
                    'gregorian_date' => $b['gregorian_date'],
                ];
            }, $backups),
        ],
    ]);
}

/**
 * 💾 ساخت بکاپ فوری (POST)
 */
function api_backup_create(int $brandId): void
{
    api_backup_require_permission();

    $backupMgr = new BackupManager();
    $result = $backupMgr->createBackup($brandId, 'manual');

    json_response([
        'success'   => $result['success'],
        'message'   => $result['message'],
        'backup_id' => $result['backup_id'] ?? null,
        'filename'  => $result['filename'] ?? null,
    ], $result['success'] ? 200 : 400);
}

/**
 * 🔄 بازیابی از بکاپ (POST)
 */
function api_backup_restore(int $backupId): void
{
    api_backup_require_permission();

    $backupMgr = new BackupManager();
    $result = $backupMgr->restoreBackup($backupId);

    json_response($result, $result['success'] ? 200 : 400);
}

/**
 * 🗑️ حذف بکاپ (POST)
 */
function api_backup_delete(int $backupId): void
{
    api_backup_require_permission();

    $backupMgr = new BackupManager();
    $result = $backupMgr->deleteBackup($backupId);

    json_response($result, $result['success'] ? 200 : 404);
}
