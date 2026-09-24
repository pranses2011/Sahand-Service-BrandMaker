<?php
/**
 * 📋 لاگر عملیات استقرار — ثبت استقرارها و مراحل
 * ==============================================
 * دو سطح ثبت:
 *   ۱. جدول deployments — یک ردیف برای هر عملیات کلی (deploy/update/delete/...)
 *   ۲. جدول deployment_logs — یک ردیف برای هر مرحله (ساخت زیردامنه، آپلود، ...)
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class DeploymentLogger
{
    /** @var Database اتصال دیتابیس */
    private $db;

    /**
     * 🔧 سازنده
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * ➕ ایجاد رکورد عملیات جدید
     *
     * @param int    $brandId شناسه برند
     * @param string $action  نوع عملیات (deploy|update|delete|ssl|backup|rollback)
     * @param array  $meta    اطلاعات تکمیلی (subdomain، full_domain، server_path، zip_path، triggered_by)
     * @return int شناسه عملیات
     */
    public function start(int $brandId, string $action, array $meta = []): int
    {
        $id = $this->db->insert('deployments', [
            'brand_id'    => $brandId,
            'action'      => $action,
            'status'      => 'in_progress',
            'total_steps' => (int)($meta['total_steps'] ?? 11),
            'subdomain'   => $meta['subdomain'] ?? null,
            'full_domain' => $meta['full_domain'] ?? null,
            'server_path' => $meta['server_path'] ?? null,
            'zip_path'    => $meta['zip_path'] ?? null,
            'triggered_by'=> $meta['triggered_by'] ?? 'panel',
            'started_at'  => date('Y-m-d H:i:s'),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        Logger::info('[Deploy] شروع عملیات', ['deployment_id' => $id, 'brand_id' => $brandId, 'action' => $action]);
        return $id;
    }

    /**
     * ✅ ثبت موفقیت یک مرحله
     *
     * @param int    $deploymentId شناسه عملیات
     * @param string $step نام مرحله (مثلاً subdomain_created)
     * @param string $message پیام فارسی
     * @param int    $durationMs مدت اجرا (میلی‌ثانیه)
     */
    public function step(int $deploymentId, string $step, string $message, int $durationMs = 0): void
    {
        $this->db->insert('deployment_logs', [
            'deployment_id' => $deploymentId,
            'step'          => mb_substr($step, 0, 100),
            'status'        => 'success',
            'message'       => mb_substr($message, 0, 5000),
            'duration_ms'   => $durationMs,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * ⏭️ ثبت مرحله ردشده (مثلاً SSL غیرفعال در تنظیمات)
     */
    public function stepSkipped(int $deploymentId, string $step, string $message): void
    {
        $this->db->insert('deployment_logs', [
            'deployment_id' => $deploymentId,
            'step'          => mb_substr($step, 0, 100),
            'status'        => 'skipped',
            'message'       => mb_substr($message, 0, 5000),
            'duration_ms'   => 0,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * ❌ ثبت شکست یک مرحله
     */
    public function stepFailed(int $deploymentId, string $step, string $message, int $durationMs = 0): void
    {
        $this->db->insert('deployment_logs', [
            'deployment_id' => $deploymentId,
            'step'          => mb_substr($step, 0, 100),
            'status'        => 'failed',
            'message'       => mb_substr($message, 0, 5000),
            'duration_ms'   => $durationMs,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 🏁 پایان موفق عملیات
     *
     * @param int   $deploymentId شناسه عملیات
     * @param array $details جزئیات JSON برای ذخیره
     */
    public function finish(int $deploymentId, array $details = []): void
    {
        $row = $this->db->fetch('SELECT started_at, total_steps FROM deployments WHERE id = ?', [$deploymentId]);
        $duration = 0;
        if ($row && !empty($row['started_at'])) {
            $duration = max(0, time() - strtotime($row['started_at']));
        }

        $this->db->update('deployments', [
            'status'           => 'success',
            'current_step'     => (int)($row['total_steps'] ?? 11),
            'completed_at'     => date('Y-m-d H:i:s'),
            'duration_seconds' => $duration,
            'details'          => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            'error_message'    => null,
        ], 'id = ?', [$deploymentId]);

        Logger::info('[Deploy] عملیات موفق', ['deployment_id' => $deploymentId, 'duration' => $duration]);
    }

    /**
     * 💥 شکست عملیات
     *
     * @param int    $deploymentId شناسه عملیات
     * @param string $errorMessage پیام خطا
     * @param string $failedStep نام مرحله شکست‌خورده
     */
    public function fail(int $deploymentId, string $errorMessage, string $failedStep = ''): void
    {
        $row = $this->db->fetch('SELECT started_at FROM deployments WHERE id = ?', [$deploymentId]);
        $duration = 0;
        if ($row && !empty($row['started_at'])) {
            $duration = max(0, time() - strtotime($row['started_at']));
        }

        $this->db->update('deployments', [
            'status'           => 'failed',
            'completed_at'     => date('Y-m-d H:i:s'),
            'duration_seconds' => $duration,
            'error_message'    => mb_substr($errorMessage, 0, 5000),
        ], 'id = ?', [$deploymentId]);

        if ($failedStep !== '') {
            $this->stepFailed($deploymentId, $failedStep, $errorMessage);
        }

        Logger::log('ERROR', '[Deploy] عملیات ناموفق', ['deployment_id' => $deploymentId, 'error' => $errorMessage]);
    }

    /**
     * 📊 بروزرسانی مرحله جاری (برای نوار پیشرفت)
     */
    public function updateProgress(int $deploymentId, int $currentStep): void
    {
        $this->db->update('deployments', ['current_step' => $currentStep], 'id = ?', [$deploymentId]);
    }

    /**
     * 💾 ثبت مسیر بکاپ در عملیات
     */
    public function setBackupPath(int $deploymentId, string $path): void
    {
        $this->db->update('deployments', ['backup_path' => $path], 'id = ?', [$deploymentId]);
    }

    /**
     * 📥 دریافت وضعیت عملیات برای AJAX (نوار پیشرفت)
     *
     * @param int $deploymentId شناسه عملیات
     * @return array وضعیت کامل + مراحل
     */
    public function getStatus(int $deploymentId): array
    {
        $deployment = $this->db->fetch('SELECT * FROM deployments WHERE id = ?', [$deploymentId]);
        if (!$deployment) {
            return ['success' => false, 'error' => 'عملیات یافت نشد'];
        }

        $logs = $this->db->fetchAll(
            'SELECT step, status, message, duration_ms, created_at FROM deployment_logs WHERE deployment_id = ? ORDER BY id ASC',
            [$deploymentId]
        );

        return [
            'success'    => true,
            'deployment' => [
                'id'          => (int)$deployment['id'],
                'brand_id'    => (int)$deployment['brand_id'],
                'action'      => $deployment['action'],
                'status'      => $deployment['status'],
                'current_step'=> (int)$deployment['current_step'],
                'total_steps' => (int)$deployment['total_steps'],
                'error'       => $deployment['error_message'],
                'duration'    => (int)$deployment['duration_seconds'],
                'domain'      => $deployment['full_domain'],
            ],
            'steps'      => array_map(function ($log) {
                return [
                    'step'    => $log['step'],
                    'status'  => $log['status'],
                    'message' => $log['message'],
                    'ms'      => (int)$log['duration_ms'],
                    'time'    => $log['created_at'],
                ];
            }, $logs),
        ];
    }

    /**
     * 📜 تاریخچه عملیات یک برند (برای تب استقرار)
     *
     * @param int $brandId شناسه برند
     * @param int $limit حداکثر ردیف‌ها
     */
    public function getBrandHistory(int $brandId, int $limit = 15): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM deployments WHERE brand_id = ? ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)),
            [$brandId]
        );
    }

    /**
     * 📜 همه عملیات اخیر (برای صفحه لاگ‌ها)
     */
    public function getRecent(int $limit = 100, ?string $actionFilter = null, ?string $statusFilter = null): array
    {
        $where = '1=1';
        $params = [];
        if ($actionFilter) {
            $where .= ' AND d.action = ?';
            $params[] = $actionFilter;
        }
        if ($statusFilter) {
            $where .= ' AND d.status = ?';
            $params[] = $statusFilter;
        }
        return $this->db->fetchAll(
            "SELECT d.*, b.name_fa AS brand_name, b.slug AS brand_slug
             FROM deployments d
             LEFT JOIN brands b ON b.id = d.brand_id
             WHERE {$where}
             ORDER BY d.id DESC
             LIMIT " . max(1, min(300, $limit)),
            $params
        );
    }

    /**
     * 🗺️ برچسب فارسی نوع عملیات
     */
    public static function actionLabel(string $action): string
    {
        $map = [
            'deploy'  => '🚀 استقرار',
            'update'  => '🔄 بروزرسانی',
            'delete'  => '🗑️ حذف',
            'ssl'     => '🔒 SSL',
            'backup'  => '💾 بکاپ',
            'rollback'=> '↩️ بازیابی',
        ];
        return $map[$action] ?? $action;
    }

    /**
     * 🎨 کلاس CSS وضعیت
     */
    public static function statusBadge(string $status): array
    {
        $map = [
            'success'     => ['✅ موفق', 'badge-success'],
            'failed'      => ['❌ ناموفق', 'badge-danger'],
            'in_progress' => ['🔄 در حال اجرا', 'badge-warning'],
            'pending'     => ['⏳ در صف', 'badge-secondary'],
        ];
        return $map[$status] ?? [$status, 'badge-secondary'];
    }
}
