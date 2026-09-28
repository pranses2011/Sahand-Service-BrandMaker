<?php
/**
 * 🏷️ BrandRepository — دسترسی داده برندها (P2-25)
 * ============================================================
 * تمام کوئری‌های برند که قبلاً در فایل‌های UI تکرار می‌شدند — حالا یکجا،
 * با فیلتر ACL سطح‌برند (نقش brand_manager فقط برندهای تخصیص‌یافته) که
 * پیش‌تر باید هر صفحه جداگانه یادش می‌کرد.
 *
 * @package SahandBrandMaker\Core
 * @since   2.36.0
 */
class BrandRepository extends Repository
{
    protected string $table = 'brands';
    protected string $primaryKey = 'id';

    /**
     * فهرست برندها با فیلتر ACL خودکار.
     * $auth: نمونه Auth (اختیاری — از نشست جاری ساخته می‌شود)
     * $managerIds: محدودیت دستی برندها (برای تست)
     * @return array|null null = بدون محدودیت (نقش سیستمی)
     */
    public function accessibleIds(?Auth $auth = null): ?array
    {
        $auth = $auth ?: new Auth();
        return $auth->accessibleBrandIds();
    }

    /**
     * همه برندها (فیلتر ACL برای brand_manager اعمال می‌شود)
     * @param string $order ترتیب SQL امن (ستون‌های مجاز)
     */
    public function all(string $order = 'name_fa', ?array $managerIds = null): array
    {
        $sql = 'SELECT * FROM `brands`';
        $params = [];
        if ($managerIds !== null) {
            if ($managerIds === []) {
                return [];
            }
            $sql .= ' WHERE `id` IN (' . self::inClause($managerIds) . ')';
            $params = array_values($managerIds);
        }
        /* ترتیب: فقط ستون‌های مجاز — جلوگیری از تزریق */
        $allowed = ['id', 'name_fa', 'name_en', 'status', 'is_deployed', 'created_at'];
        [$col, $dir] = array_pad(explode(' ', trim($order)), 2, 'ASC');
        if (!in_array($col, $allowed, true)) {
            $col = 'name_fa';
        }
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
        $sql .= " ORDER BY `{$col}` {$dir}";
        return $this->db->fetchAll($sql, $params);
    }

    /** فهرست سبک برای dropdown ها (id + نام) */
    public function listForSelect(?array $managerIds = null): array
    {
        $rows = $this->all('name_fa', $managerIds);
        return array_map(static fn($r) => ['id' => (int)$r['id'], 'name_fa' => (string)$r['name_fa']], $rows);
    }

    /** برندهای مستقرشده */
    public function deployed(): array
    {
        return $this->findBy('is_deployed = 1', [], '*', null);
    }

    /** آمار کلی داشبورد */
    public function stats(): array
    {
        $row = $this->db->fetch(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN is_deployed = 1 THEN 1 ELSE 0 END) AS deployed,
                    SUM(CASE WHEN status = 'building' THEN 1 ELSE 0 END) AS building
             FROM `brands`"
        );
        return [
            'total'    => (int)($row['total'] ?? 0),
            'deployed' => (int)($row['deployed'] ?? 0),
            'building' => (int)($row['building'] ?? 0),
        ];
    }

    /** یافتن با نام دامنه کامل (برای health-check/استقرار) */
    public function findByDomain(string $domain): ?array
    {
        return $this->findOneBy('full_domain = ?', [$domain]);
    }

    /**
     * 🔎 جستجو/فیلتر برندها با شمارش‌های وابسته — همان کوئری لیست مدیریت
     * (پیش‌تر SQL آن در admin/brands.php زندگی می‌کرد — P2-25)
     * فیلتر ACL خودکار: brand_manager فقط برندهای تخصیص‌یافته
     */
    public function searchWithCounts(
        string $search = '',
        string $status = '',
        ?array $managerIds = null
    ): array {
        $where = '1=1';
        $params = [];
        if ($search !== '') {
            $where .= ' AND (b.name_fa LIKE ? OR b.name_en LIKE ? OR b.domain LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        if ($status !== '') {
            $where .= ' AND b.status = ?';
            $params[] = $status;
        }
        if ($managerIds !== null) {
            if ($managerIds === []) {
                return [];
            }
            $where .= ' AND b.id IN (' . self::inClause($managerIds) . ')';
            array_push($params, ...array_map('intval', $managerIds));
        }
        return $this->db->fetchAll(
            "SELECT b.*,
                (SELECT COUNT(*) FROM brand_articles a WHERE a.brand_id = b.id) AS articles_count,
                (SELECT COUNT(*) FROM brand_devices d WHERE d.brand_id = b.id) AS devices_count,
                (SELECT COUNT(*) FROM service_requests r WHERE r.brand_id = b.id) AS requests_count
             FROM brands b WHERE {$where}
             ORDER BY b.id DESC",
            $params
        );
    }
}
