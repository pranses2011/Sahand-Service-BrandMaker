<?php
/**
 * 🔒 LoginRateLimiter — محدودیت تلاش ناموفق (Brute Force)
 * قفل حساب، شمارش تلاش، ثبت تلاش کلیدهای دلخواه (2FA/بازیابی رمز)
 * 🧩 v2.43 (S13): از Auth.php تک‌عظیم تفکیک شد — Auth.php نقش Facade دارد.
 * @package SahandBrandMaker\Core\Auth
 */

class LoginRateLimiter
{
    /** @var Database */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function recordKeyAttempt(string $key, int $currentFails): void
    {
        $expires = date('Y-m-d H:i:s', time() + 600);
        if ($currentFails > 0) {
            $this->db->update('rate_limits', ['hit_count' => $currentFails + 1, 'expires_at' => $expires], 'limit_key = ?', [$key]);
        } else {
            $this->db->insert('rate_limits', ['limit_key' => $key, 'hit_count' => 1, 'expires_at' => $expires]);
        }
    }

    public function recordFailedAttempt(string $username): void
    {
        $key = 'login_fail_' . md5(mb_strtolower($username));
        $data = $this->db->fetch('SELECT * FROM rate_limits WHERE limit_key = ? LIMIT 1', [$key]);
        if ($data) {
            // افزایش شمارنده
            $this->db->update('rate_limits', [
                'hit_count' => $data['hit_count'] + 1,
                'expires_at' => date('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60),
            ], 'id = ?', [$data['id']]);
        } else {
            $this->db->insert('rate_limits', [
                'limit_key' => $key,
                'hit_count' => 1,
                'expires_at' => date('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60),
            ]);
        }
    }

    public function failedAttempts(string $username): int
    {
        $key = 'login_fail_' . md5(mb_strtolower($username));
        $row = $this->db->fetch(
            'SELECT hit_count FROM rate_limits WHERE limit_key = ? AND expires_at > NOW() LIMIT 1',
            [$key]
        );
        return $row ? (int)$row['hit_count'] : 0;
    }

    public function isLocked(string $username): bool
    {
        return $this->failedAttempts($username) >= MAX_LOGIN_ATTEMPTS;
    }

    public function lockRemaining(string $username): int
    {
        $key = 'login_fail_' . md5(mb_strtolower($username));
        $row = $this->db->fetch(
            'SELECT expires_at FROM rate_limits WHERE limit_key = ? AND expires_at > NOW() LIMIT 1',
            [$key]
        );
        if (!$row) {
            return 0;
        }
        return max(1, (int)ceil((strtotime($row['expires_at']) - time()) / 60));
    }

    public function clearFailedAttempts(string $username): void
    {
        $key = 'login_fail_' . md5(mb_strtolower($username));
        $this->db->delete('rate_limits', 'limit_key = ?', [$key]);
    }
}
