<?php
/**
 * 🛂 PermissionManager — دسترسی‌ها و ACL سطح‌برند (v2.34)
 * admin/editor همه برندها؛ brand_manager فقط تخصیص‌یافته‌ها + شرط SQL خودکار
 * 🧩 v2.43 (S13): از Auth.php تک‌عظیم تفکیک شد — Auth.php نقش Facade دارد.
 * @package SahandBrandMaker\Core\Auth
 */

class PermissionManager
{
    /** @var Database */
    private $db;
    /** @var SessionManager */
    private $session;

    public function __construct($db, SessionManager $session)
    {
        $this->db = $db;
        $this->session = $session;
    }

    public function isAdmin(): bool
    {
        return $this->session->role() === RoleManager::ROLE_ADMIN;
    }

    public function isSystemRole(): bool
    {
        return RoleManager::isSystem($this->session->role());
    }

    public function isBrandManager(): bool
    {
        return RoleManager::isBrandManager($this->session->role());
    }

    public function accessibleBrandIds(): ?array
    {
        if (!$this->isBrandManager()) {
            return null;
        }
        // بدون کش static: کوئری اندیس‌دار سبک است و تخصیص لحظه‌ای مدیر (در همان نشست)
        // بلافاصله اعمال می‌شود
        $rows = $this->db->fetchAll(
            'SELECT brand_id FROM brand_user_access WHERE user_id = ?',
            [$this->session->userId()]
        );
        return array_map('intval', array_column($rows, 'brand_id'));
    }

    public function canAccessBrand(int $brandId): bool
    {
        if ($brandId < 1) {
            return false;
        }
        $ids = $this->accessibleBrandIds();
        return $ids === null || in_array($brandId, $ids, true);
    }

    public function requireBrandAccess(int $brandId): void
    {
        if (!$this->canAccessBrand($brandId)) {
            http_response_code(403);
            Logger::activity($this->session->userId(), 'دسترسی غیرمجاز', "تلاش برای دسترسی به برند #{$brandId} بدون مجوز");
            echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>دسترسی غیرمجاز</title></head>'
                . '<body style="font-family:Tahoma,sans-serif;direction:rtl;text-align:center;padding:60px 20px">'
                . '<div style="font-size:56px">⛔</div><h2>دسترسی غیرمجاز</h2>'
                . '<p>شما به این برند دسترسی ندارید. برای دریافت دسترسی با مدیر سیستم تماس بگیرید.</p>'
                . '<p><a href="index.php">بازگشت به داشبورد</a></p></body></html>';
            exit;
        }
    }

    /**
     * 🛑 v2.43 (S13) — الزام مجوز نقش: فقط admin (مهاجرت دیتابیس/تنظیمات حیاتی)
     * @param string $permission 'admin' (در حال حاضر) — توسعه‌پذیر برای مجوزهای ریز
     */
    public function requirePermission(string $permission): void
    {
        $ok = ($permission === 'admin') ? $this->isAdmin() : $this->isSystemRole();
        if (!$ok) {
            http_response_code(403);
            echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>دسترسی غیرمجاز</title></head>'
                . '<body style="font-family:Tahoma,sans-serif;direction:rtl;text-align:center;padding:60px 20px">'
                . '<div style="font-size:56px">⛔</div><h2>این عملیات فقط برای مدیر سیستم مجاز است</h2>'
                . '<p><a href="index.php">بازگشت به داشبورد</a></p></body></html>';
            exit;
        }
    }

    public function requireSystemRole(): void
    {
        if ($this->isBrandManager()) {
            http_response_code(403);
            echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>دسترسی غیرمجاز</title></head>'
                . '<body style="font-family:Tahoma,sans-serif;direction:rtl;text-align:center;padding:60px 20px">'
                . '<div style="font-size:56px">⛔</div><h2>این بخش فقط برای مدیر سیستم است</h2>'
                . '<p><a href="index.php">بازگشت به داشبورد</a></p></body></html>';
            exit;
        }
    }

    public function brandAccessSql(string $column = 'b.id'): array
    {
        $ids = $this->accessibleBrandIds();
        if ($ids === null) {
            return ['', []];
        }
        if ($ids === []) {
            return [' AND 1=0', []]; // هیچ برندی تخصیص نیافته
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return [" AND {$column} IN ({$ph})", $ids];
    }
}
