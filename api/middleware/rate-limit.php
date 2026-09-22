<?php
/**
 * 🚦 میان‌افزار محدودیت نرخ درخواست
 * ==================================
 * سقف API_RATE_LIMIT درخواست در دقیقه برای هر IP
 *
 * @package SahandBrandMaker
 */

if (!defined('SAHAND_INIT')) {
    http_response_code(403);
    exit;
}

/**
 * 🚦 بررسی سقف نرخ — true = مجاز، false = مسدود
 */
function api_rate_limit(): bool
{
    $db = Database::getInstance();
    $ip = Logger::clientIp();
    $key = 'api_' . hash('sha256', $ip);

    // 🧹 پاکسازی منقضی‌ها (احتمالی)
    if (random_int(1, 50) === 1) {
        try {
            $db->delete('rate_limits', 'expires_at < NOW()');
        } catch (Exception $e) {
            // بی‌صدا
        }
    }

    try {
        $row = $db->fetch('SELECT * FROM rate_limits WHERE limit_key = ? LIMIT 1', [$key]);
        $now = time();

        if ($row && strtotime($row['expires_at']) > $now) {
            if ((int)$row['hit_count'] >= API_RATE_LIMIT) {
                header('Retry-After: 60');
                json_response([
                    'success' => false,
                    'error'   => 'محدودیت نرخ درخواست — لطفاً یک دقیقه دیگر تلاش کنید',
                ], 429);
                return false;
            }
            $db->update('rate_limits', ['hit_count' => (int)$row['hit_count'] + 1], 'id = ?', [$row['id']]);
        } else {
            if ($row) {
                $db->update('rate_limits', ['hit_count' => 1, 'expires_at' => date('Y-m-d H:i:s', $now + 60)], 'id = ?', [$row['id']]);
            } else {
                $db->insert('rate_limits', [
                    'limit_key'  => $key,
                    'hit_count'  => 1,
                    'expires_at' => date('Y-m-d H:i:s', $now + 60),
                ]);
            }
        }
    } catch (Exception $e) {
        // خطای دیتابیس نباید مانع سرویس‌دهی شود
    }
    return true;
}
