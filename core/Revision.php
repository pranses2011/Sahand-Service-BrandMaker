<?php
/**
 * 🕘 موتور تاریخچه تغییرات — Revisions (v2.34)
 * ============================================
 * برای برند / صفحه / مقاله پیش از هر ذخیره، یک تصویر لحظه‌ای (snapshot)
 * از داده فعلی در جدول content_revisions ذخیره می‌شود تا همیشه قابل
 * بازگردانی باشد.
 *
 * ویژگی‌ها:
 *   - سقف نگهداری per-entity (پیش‌فرض ۳۰) — قدیمی‌ها خودکار هرس می‌شوند
 *   - ذخیره فقط وقتی واقعاً تغییری رخ داده (مقایسه هش snapshot)
 *   - خروجی متادیتا برای لیست + خود snapshot برای بازگردانی
 *   - هر بازگردانی خود یک revision جدید می‌سازد (بدون از دست رفتن چیزی)
 *
 * @package SahandBrandMaker
 * @version 2.34.0
 */
class Revision
{
    /** حداکثر نسخه نگهداری‌شده برای هر موجودیت */
    const KEEP_PER_ENTITY = 30;

    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /* ==================================================
     * 💾 ذخیره
     * ================================================== */

    /**
     * ثبت نسخه جدید — فقط اگر با آخرین نسخه فرق داشته باشد
     *
     * @param string $entityType brand|page|article|menu
     * @param int    $entityId   شناسه موجودیت
     * @param int|null $brandId  برند مرتبط (برای فیلتر ACL)
     * @param string|null $title عنوان خوانا برای لیست
     * @param array  $snapshot   داده کامل (JSON ذخیره می‌شود)
     * @return int شناسه نسخه (۰ = بدون تغییر، ذخیره نشد)
     */
    public function save(string $entityType, int $entityId, ?int $brandId, ?string $title, array $snapshot): int
    {
        if (!in_array($entityType, ['brand', 'page', 'article', 'menu'], true) || $entityId < 1) {
            return 0;
        }
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || $json === '[]' || $json === '{}') {
            return 0;
        }

        // بدون تغییر نسبت به آخرین نسخه؟
        $last = $this->db->fetch(
            'SELECT id, snapshot FROM content_revisions
             WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 1',
            [$entityType, $entityId]
        );
        if ($last && md5((string)$last['snapshot']) === md5($json)) {
            return 0;
        }

        $revId = $this->db->insert('content_revisions', [
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'brand_id'    => $brandId,
            'user_id'     => (int)($_SESSION['user_id'] ?? 0) ?: null,
            'title'       => mb_substr((string)$title, 0, 250),
            'snapshot'    => $json,
        ]);

        $this->prune($entityType, $entityId);
        return (int)$revId;
    }

    /** هرس نسخه‌های قدیمی بالاتر از سقف */
    public function prune(string $entityType, int $entityId): void
    {
        $ids = $this->db->fetchAll(
            'SELECT id FROM content_revisions WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT ' . self::KEEP_PER_ENTITY . ', 1000',
            [$entityType, $entityId]
        );
        foreach ($ids as $row) {
            $this->db->delete('content_revisions', 'id = ?', [$row['id']]);
        }
    }

    /* ==================================================
     * 📖 خواندن
     * ================================================== */

    /** لیست نسخه‌های یک موجودیت (جدیدترین اول) */
    public function listFor(string $entityType, int $entityId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            'SELECT r.id, r.entity_type, r.entity_id, r.brand_id, r.title, r.created_at,
                    u.username AS user_name, u.full_name AS user_full
             FROM content_revisions r
             LEFT JOIN users u ON u.id = r.user_id
             WHERE r.entity_type = ? AND r.entity_id = ?
             ORDER BY r.id DESC LIMIT ' . max(1, min(200, $limit)),
            [$entityType, $entityId]
        );
    }

    /** لیست همه نسخه‌ها با فیلتر (صفحه تاریخچه) */
    public function listAll(?string $entityType = null, ?int $brandId = null, int $limit = 100): array
    {
        $where = '1=1';
        $params = [];
        if ($entityType !== null && $entityType !== '') {
            $where .= ' AND r.entity_type = ?';
            $params[] = $entityType;
        }
        if ($brandId !== null && $brandId > 0) {
            $where .= ' AND r.brand_id = ?';
            $params[] = $brandId;
        }
        return $this->db->fetchAll(
            "SELECT r.id, r.entity_type, r.entity_id, r.brand_id, r.title, r.created_at,
                    u.username AS user_name, u.full_name AS user_full,
                    b.name_fa AS brand_name
             FROM content_revisions r
             LEFT JOIN users u ON u.id = r.user_id
             LEFT JOIN brands b ON b.id = r.brand_id
             WHERE {$where}
             ORDER BY r.id DESC LIMIT " . max(1, min(300, $limit)),
            $params
        );
    }

    /** یک نسخه + محتوایش */
    public function get(int $revisionId): ?array
    {
        $row = $this->db->fetch(
            'SELECT r.*, u.full_name AS user_full, b.name_fa AS brand_name
             FROM content_revisions r
             LEFT JOIN users u ON u.id = r.user_id
             LEFT JOIN brands b ON b.id = r.brand_id
             WHERE r.id = ? LIMIT 1',
            [$revisionId]
        );
        if (!$row) {
            return null;
        }
        $row['data'] = json_decode((string)$row['snapshot'], true) ?: [];
        return $row;
    }

    /* ==================================================
     * 📸 ساخت snapshot از موجودیت‌های اصلی
     * ================================================== */

    /** تصویر لحظه‌ای برند (قبل از ویرایش) */
    public function snapshotBrand(int $brandId): array
    {
        $b = $this->db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
        if (!$b) {
            return [];
        }
        unset($b['id'], $b['created_at']); // فیلدهای غیرقابل بازگردانی
        return ['fields' => $b];
    }

    /** تصویر لحظه‌ای صفحه برند (چیدمان + سئو) */
    public function snapshotPage(int $pageId): array
    {
        $p = $this->db->fetch('SELECT * FROM brand_pages WHERE id = ?', [$pageId]);
        if (!$p) {
            return [];
        }
        return [
            'brand_id'    => (int)$p['brand_id'],
            'page_type'   => $p['page_type'],
            'layout_json' => (string)$p['layout_json'],
            'seo_title'   => $p['seo_title'],
            'seo_description' => $p['seo_description'],
            'is_active'   => (int)$p['is_active'],
        ];
    }

    /** تصویر لحظه‌ای مقاله */
    public function snapshotArticle(int $articleId): array
    {
        $a = $this->db->fetch('SELECT * FROM brand_articles WHERE id = ?', [$articleId]);
        if (!$a) {
            return [];
        }
        unset($a['id'], $a['created_at']);
        return ['fields' => $a];
    }

    /* ==================================================
     * ♻️ بازگردانی
     * ================================================== */

    /**
     * بازگردانی یک نسخه — قبل از اعمال، وضعیت فعلی هم ذخیره می‌شود
     * @return array ['ok'=>bool,'message'=>string]
     */
    public function restore(int $revisionId): array
    {
        $rev = $this->get($revisionId);
        if (!$rev) {
            return ['ok' => false, 'message' => 'نسخه یافت نشد.'];
        }
        $data = $rev['data'];

        try {
            switch ($rev['entity_type']) {
                case 'brand':
                    $fields = $data['fields'] ?? [];
                    if (!$fields) {
                        return ['ok' => false, 'message' => 'محتوای نسخه نامعتبر است.'];
                    }
                    // اول وضعیت فعلی را ذخیره کن (بدون از دست رفتن)
                    $this->save('brand', (int)$rev['entity_id'], (int)$rev['brand_id'], 'قبل از بازگردانی — ' . ($rev['title'] ?? ''), $this->snapshotBrand((int)$rev['entity_id']));
                    unset($fields['updated_at']);
                    $this->db->update('brands', $fields, 'id = ?', [$rev['entity_id']]);
                    (new Cache())->delete('brand_' . (int)$rev['entity_id'] . '_pages');
                    break;

                case 'page':
                    if (!isset($data['layout_json'])) {
                        return ['ok' => false, 'message' => 'محتوای نسخه نامعتبر است.'];
                    }
                    $this->save('page', (int)$rev['entity_id'], (int)$rev['brand_id'], 'قبل از بازگردانی — ' . ($rev['title'] ?? ''), $this->snapshotPage((int)$rev['entity_id']));
                    $this->db->update('brand_pages', [
                        'layout_json'     => $data['layout_json'],
                        'layout_custom'   => 1, /* v2.41 — بازگردانی دستی = انتخاب کاربر؛ بر تم مقدم */
                        'seo_title'       => $data['seo_title'] ?? null,
                        'seo_description' => $data['seo_description'] ?? null,
                        'is_active'       => (int)($data['is_active'] ?? 1),
                    ], 'id = ?', [$rev['entity_id']]);
                    (new Cache())->flush('api_brand_' . (int)($rev['brand_id'] ?? 0));
                    break;

                case 'article':
                    $fields = $data['fields'] ?? [];
                    if (!$fields) {
                        return ['ok' => false, 'message' => 'محتوای نسخه نامعتبر است.'];
                    }
                    $this->save('article', (int)$rev['entity_id'], (int)$rev['brand_id'], 'قبل از بازگردانی — ' . ($rev['title'] ?? ''), $this->snapshotArticle((int)$rev['entity_id']));
                    unset($fields['updated_at']);
                    $this->db->update('brand_articles', $fields, 'id = ?', [$rev['entity_id']]);
                    (new Cache())->delete('brand_articles_all');
                    break;

                default:
                    return ['ok' => false, 'message' => 'بازگردانی این نوع موجودیت پشتیبانی نمی‌شود.'];
            }

            Logger::activity((int)($_SESSION['user_id'] ?? 0), 'بازگردانی نسخه', "{$rev['entity_type']} #{$rev['entity_id']} به نسخه #{$revisionId}");
            return ['ok' => true, 'message' => '✅ نسخه با موفقیت بازگردانی شد — وضعیت قبلِ بازگردانی هم در تاریخچه ذخیره شد.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'خطای بازگردانی: ' . $e->getMessage()];
        }
    }

    /** حذف یک نسخه (مدیریت فضا) */
    public function delete(int $revisionId): bool
    {
        return $this->db->delete('content_revisions', 'id = ?', [$revisionId]) > 0;
    }
}
