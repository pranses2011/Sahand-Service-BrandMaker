<?php
/**
 * 🗃️ Repository — لایه دسترسی داده (P2-25 — نقشه راه بازسازی معماری)
 * ============================================================
 * گزارش تحلیل جامع §۹.۳: کوئری‌های SQL مستقیم در فایل‌های UI پخش بودند
 * (۴۳ فراخوانی Database::getInstance در admin) — تکرار منطق و ریسک ناسازگاری
 * ACL/فیلتر بین صفحات.
 *
 * این کلاس پایه، الگوی Repository را برای موجودیت‌ها فراهم می‌کند:
 *   • CRUD استاندارد با پارامترهای bound (بدون SQL درون‌خطی UI)
 *   • سازگار با Database موجود (هیچ مهاجرت زیرساختی لازم نیست)
 *   • متدهای خاص هر موجودیت در زیرکلاس‌ها (مثل BrandRepository)
 *
 * @package SahandBrandMaker\Core
 * @since   2.36.0
 */
abstract class Repository
{
    /** @var string نام جدول — در زیرکلاس تعیین می‌شود */
    protected string $table = '';

    /** @var string ستون کلید اصلی */
    protected string $primaryKey = 'id';

    protected Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /* ═══════════ خواندن ═══════════ */

    /** یافتن با کلید اصلی — null اگر نبود */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch(
            "SELECT * FROM `{$this->table}` WHERE `{$this->primaryKey}` = ? LIMIT 1",
            [$id]
        );
        return $row ?: null;
    }

    /** یافتن با شرط — پارامترها همیشه bound */
    public function findBy(string $where, array $params = [], string $select = '*', ?int $limit = null): array
    {
        $sql = "SELECT {$select} FROM `{$this->table}`";
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        return $this->db->fetchAll($sql, $params);
    }

    /** اولین ردیف منطبق یا null */
    public function findOneBy(string $where, array $params = [], string $select = '*'): ?array
    {
        $rows = $this->findBy($where, $params, $select, 1);
        return $rows[0] ?? null;
    }

    /** شمارش با شرط اختیاری */
    public function count(string $where = '', array $params = []): int
    {
        $sql = "SELECT COUNT(*) AS c FROM `{$this->table}`";
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $row = $this->db->fetch($sql, $params);
        return (int)($row['c'] ?? 0);
    }

    /* ═══════════ نوشتن ═══════════ */

    /** درج — شناسه جدید برمی‌گردد */
    public function insert(array $data): int
    {
        $this->db->insert($this->table, $data);
        return (int)$this->db->lastInsertId();
    }

    /** ویرایش با کلید اصلی */
    public function update(int $id, array $data): bool
    {
        if ($data === []) {
            return false;
        }
        $this->db->update($this->table, $data, "`{$this->primaryKey}` = ?", [$id]);
        return true;
    }

    /** حذف با کلید اصلی */
    public function delete(int $id): bool
    {
        $this->db->delete($this->table, "`{$this->primaryKey}` = ?", [$id]);
        return true;
    }

    /* ═══════════ کمکی ═══════════ */

    /** ساخت شرط IN? امن برای آرایه شناسه */
    protected static function inClause(array $values): string
    {
        return implode(',', array_fill(0, max(1, count($values)), '?'));
    }
}
