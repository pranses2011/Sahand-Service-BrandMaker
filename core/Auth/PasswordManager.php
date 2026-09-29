<?php
/**
 * 📨 PasswordManager — رمز عبور و بازیابی (v2.34)
 * توکن یک‌بارمصرف ۶۰ دقیقه‌ای + ایجاد کاربر + ایمیل ماسک‌شده
 * 🧩 v2.43 (S13): از Auth.php تک‌عظیم تفکیک شد — Auth.php نقش Facade دارد.
 * @package SahandBrandMaker\Core\Auth
 */

class PasswordManager
{
    /** @var Database */
    private $db;
    /** @var LoginRateLimiter */
    private $limiter;

    public function __construct($db, LoginRateLimiter $limiter)
    {
        $this->db = $db;
        $this->limiter = $limiter;
    }

    public function createPasswordReset(string $login): array
    {
        $login = trim($login);
        if ($login === '') {
            return ['sent' => false, 'error' => null];
        }
        // محدودیت: ۳ درخواست / ۱۰ دقیقه بر اساس نام ورودی (جلوگیری از بمب ایمیل)
        $key = 'pwreset_' . md5(mb_strtolower($login));
        $row = $this->db->fetch('SELECT hit_count, expires_at FROM rate_limits WHERE limit_key = ? LIMIT 1', [$key]);
        $fails = ($row && strtotime((string)$row['expires_at']) > time()) ? (int)$row['hit_count'] : 0;
        if ($fails >= 3) {
            return ['sent' => false, 'error' => 'درخواست‌های زیاد — ۱۰ دقیقه دیگر تلاش کنید.'];
        }

        $user = $this->db->fetch(
            'SELECT id, email, full_name FROM users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1',
            [$login, $login]
        );
        if (!$user || empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            $this->limiter->recordKeyAttempt($key, $fails); // حتی در نبود کاربر شمارش می‌شود (ضد شمارش کاربران)
            return ['sent' => false, 'error' => null];
        }

        // توکن ۳۲ بایتی — فقط هش در دیتابیس (افشای DB = لو نرفتن توکن‌ها)
        $token = bin2hex(random_bytes(32));
        $this->db->delete('password_resets', 'user_id = ?', [$user['id']]); // توکن‌های قبلی باطل
        $this->db->insert('password_resets', [
            'user_id'    => $user['id'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'created_at' => date('Y-m-d H:i:s'),
            'ip'         => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 60),
        ]);
        $this->limiter->recordKeyAttempt($key, $fails);
        return ['sent' => true, 'error' => null, 'token' => $token, 'user' => $user];
    }

    public function consumePasswordReset(string $token, string $newPassword): array
    {
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return ['ok' => false, 'message' => 'لینک بازیابی نامعتبر است.', 'user_id' => 0];
        }
        if (strlen($newPassword) < 8) {
            return ['ok' => false, 'message' => 'رمز عبور باید حداقل ۸ کاراکتر باشد.', 'user_id' => 0];
        }
        $row = $this->db->fetch(
            'SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1',
            [hash('sha256', $token)]
        );
        if (!$row) {
            return ['ok' => false, 'message' => 'لینک بازیابی منقضی یا استفاده‌شده است. دوباره درخواست دهید.', 'user_id' => 0];
        }
        $this->db->update('users', [
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
            'totp_enabled'  => 0, // 🔐 تغییر رمز = بی‌اعتبار شدن 2FA (محافظت در برابر حساب ربوده‌شده)
            'totp_secret'   => null,
            'totp_recovery' => null,
        ], 'id = ?', [$row['user_id']]);
        $this->db->update('password_resets', ['used_at' => date('Y-m-d H:i:s')], 'id = ?', [$row['id']]);
        $this->db->delete('password_resets', 'user_id = ?', [$row['user_id']]);
        Logger::activity((int)$row['user_id'], 'بازیابی رمز عبور', 'رمز عبور از طریق لینک بازیابی تغییر کرد');
        return ['ok' => true, 'message' => '✅ رمز عبور با موفقیت تغییر کرد. حالا وارد شوید.', 'user_id' => (int)$row['user_id']];
    }

    public static function createUser(string $username, string $password, string $fullName, string $role = 'admin'): int
    {
        return Database::getInstance()->insert('users', [
            'username'      => trim($username),
            'password_hash' => password_hash($password, PASSWORD_BCRYPT), // 🔐 رمزنگاری BCRYPT
            'full_name'     => trim($fullName),
            'role'          => $role,
            'is_active'     => 1,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    public static function maskedEmailFor(string $login): string
    {
        $row = Database::getInstance()->fetch(
            'SELECT email FROM users WHERE (username = ? OR email = ?) AND is_active = 1 AND email IS NOT NULL AND email <> \'\' LIMIT 1',
            [trim($login), trim($login)]
        );
        $email = (string)($row['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        [$local, $domain] = explode('@', $email, 2);
        $shown = mb_substr($local, 0, 1) . str_repeat('*', max(1, min(5, mb_strlen($local) - 1)));
        return $shown . '@' . $domain;
    }
}
