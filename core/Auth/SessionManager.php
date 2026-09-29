<?php
/**
 * 🕐 SessionManager — نشست و توکن CSRF
 * بررسی ورود، الزام ورود، شناسه/نقش کاربر، تولید/اعتبارسنجی CSRF
 * 🧩 v2.43 (S13): از Auth.php تک‌عظیم تفکیک شد — Auth.php نقش Facade دارد.
 * @package SahandBrandMaker\Core\Auth
 */

class SessionManager
{
    public function isLoggedIn(): bool
    {
        return !empty($_SESSION['user_id'])
            && ($_SESSION['login_time'] ?? 0) + SESSION_LIFETIME > time();
    }

    public function requireLogin(): void
    {
        if (!$this->isLoggedIn()) {
            header('Location: login.php');
            exit;
        }
        // تمدید زمان نشست در صورت فعالیت
        if (time() - ($_SESSION['last_activity'] ?? 0) > 300) {
            session_regenerate_id(true);
        }
        $_SESSION['last_activity'] = time();
    }

    public function userId(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    public function role(): string
    {
        return $_SESSION['user_role'] ?? 'guest';
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . self::csrfToken() . '">';
    }

    public static function verifyCsrf(): bool
    {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
    }

    public static function enforceCsrf(): void
    {
        if (!self::verifyCsrf()) {
            http_response_code(419);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'توکن CSRF نامعتبر است — صفحه را رفرش کنید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
