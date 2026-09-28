<?php
/**
 * 🚨 اندپوینت کدهای خطا — با جستجو و فیلتر دستگاه
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

function api_brand_error_codes(int $brandId, string $q = '', string $device = ''): void
{
    $cache = new Cache();
    $cacheKey = "api_ecodes_{$brandId}_" . md5($q . '|' . $device);
    $data = $cache->remember($cacheKey, 300, function () use ($brandId, $q, $device) {
        $db = Database::getInstance();
        $where = '(brand_id = ? OR brand_id IS NULL) AND is_active = 1';
        $params = [$brandId];
        if ($device !== '') {
            $where .= ' AND device_key = ?';
            $params[] = $device;
        }
        if ($q !== '') {
            $where .= ' AND (code LIKE ? OR title LIKE ? OR description LIKE ?)';
            $like = '%' . clean_input($q) . '%';
            array_push($params, $like, $like, $like);
        }
        /* 🆕 v2.33 — شش فیلد تکمیلی (زیرنوع، مدل‌ها، نوع خطا، قطعه،
           مشخصات فنی، محل قطعه) پیش از این اصلاً به سایت برند ارسال نمی‌شدند */
        return $db->fetchAll(
            "SELECT device_key, code, title, description, causes, solutions, severity,
                    needs_technician, subtype, models, category, related_part,
                    tech_specs, part_location
             FROM error_codes WHERE {$where} ORDER BY device_key, code",
            $params
        );
    });

    json_response([
        'success' => true,
        'data'    => array_map(function ($row) {
        $row['causes'] = json_decode($row['causes'] ?? '[]', true) ?: [];
        $row['solutions'] = json_decode($row['solutions'] ?? '[]', true) ?: [];
        /* 🆕 v2.33 */
        $row['models'] = json_decode($row['models'] ?? '[]', true) ?: [];
        $row['needs_technician'] = (bool)$row['needs_technician'];
        return $row;
        }, $data),
    ]);
}
