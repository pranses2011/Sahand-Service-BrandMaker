<?php
/**
 * 🔐 کلاس احراز هویت و مدیریت نشست
 * ===================================
 * شامل: ورود/خروج، CAPTCHA تصویری، محدودیت تلاش ناموفق،
 * توکن CSRF، سطح دسترسی کاربران و رمزنگاری امن.
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class Auth
{
    /** @var Database نمونه دیتابیس */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 🔑 تلاش برای ورود کاربر
     *
     * @param string $username نام کاربری
     * @param string $password رمز عبور
     * @param string $captcha  متن CAPTCHA واردشده توسط کاربر
     * @return array ['success' => bool, 'message' => string]
     */
    public function login(string $username, string $password, string $captcha = ''): array
    {
        // ۱️⃣ بررسی CAPTCHA (در صورت وجود در سشن)
        if (!empty($_SESSION['captcha_code'])) {
            if (mb_strtolower(trim($captcha)) !== mb_strtolower($_SESSION['captcha_code'])) {
                return ['success' => false, 'message' => '🔒 کد امنیتی وارد شده صحیح نیست.'];
            }
        }

        // ۲️⃣ بررسی قفل حساب به دلیل تلاش‌های ناموفق
        if ($this->isLocked($username)) {
            $minutes = $this->lockRemaining($username);
            return ['success' => false, 'message' => "⛔ حساب به دلیل تلاش‌های ناموفق موقتاً قفل شده است. {$minutes} دقیقه دیگر تلاش کنید."];
        }

        // ۳️⃣ جستجوی کاربر در دیتابیس
        $user = $this->db->fetch(
            'SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1',
            [trim($username)]
        );

        // ۴️⃣ بررسی رمز عبور با password_verify امن
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->recordFailedAttempt($username);
            $remaining = MAX_LOGIN_ATTEMPTS - $this->failedAttempts($username);
            return ['success' => false, 'message' => $remaining > 0
                ? "❌ نام کاربری یا رمز عبور اشتباه است. {$remaining} تلاش باقی مانده."
                : '⛔ حساب شما موقتاً قفل شد. کمی بعد تلاش کنید.'];
        }

        // ۵️⃣ ورود موفق — پاکسازی تلاش‌ها و ساخت نشست امن
        $this->clearFailedAttempts($username);
        session_regenerate_id(true); // 🔄 جلوگیری از Session Fixation

        $_SESSION['user_id']    = (int)$user['id'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['full_name']  = $user['full_name'];
        $_SESSION['user_role']  = $user['role'];
        $_SESSION['login_time'] = time();

        // بروزرسانی آخرین زمان ورود
        $this->db->update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

        // ثبت لاگ فعالیت
        Logger::activity((int)$user['id'], 'ورود به سیستم', 'ورود موفق کاربر ' . $user['username']);

        return ['success' => true, 'message' => '✅ خوش آمدید!'];
    }

    /**
     * 🚪 خروج کاربر و پاکسازی نشست
     */
    public function logout(): void
    {
        if ($this->isLoggedIn()) {
            Logger::activity($this->userId(), 'خروج از سیستم', 'خروج کاربر');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain']);
        }
        session_destroy();
    }

    /**
     * ❓ آیا کاربر وارد شده است؟
     */
    public function isLoggedIn(): bool
    {
        return !empty($_SESSION['user_id'])
            && ($_SESSION['login_time'] ?? 0) + SESSION_LIFETIME > time();
    }

    /**
     * 🛡️ الزام ورود — در صورت عدم ورود به صفحه لاگین هدایت می‌شود
     * باید در ابتدای تمام صفحات admin فراخوانی شود.
     */
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

    /**
     * 👤 شناسه کاربر جاری
     */
    public function userId(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    /**
     * 🎭 نقش کاربر جاری (admin / editor)
     */
    public function role(): string
    {
        return $_SESSION['user_role'] ?? 'guest';
    }

    /**
     * 🔐 بررسی دسترسی مدیریتی
     */
    public function isAdmin(): bool
    {
        return $this->role() === 'admin';
    }

    /* ==================================================
     * 🖼️ سیستم CAPTCHA تصویری (بدون وابستگی خارجی)
     * ================================================== */

    /**
     * 🎨 تولید و نمایش تصویر CAPTCHA
     * خروجی: تصویر PNG — مستقیماً به مرورگر ارسال می‌شود
     */
    public static function renderCaptcha(): void
    {
        $code = self::generateCaptchaCode(5);
        $_SESSION['captcha_code'] = $code;

        $width = 170;
        $height = 52;
        $image = imagecreatetruecolor($width, $height);

        // 🎨 رنگ‌های پس‌زمینه و نویز
        $bg = imagecolorallocate($image, 243, 244, 246);
        imagefilledrectangle($image, 0, 0, $width, $height, $bg);

        // خطوط نویز برای جلوگیری از خواندن رباتیک
        for ($i = 0; $i < 6; $i++) {
            $color = imagecolorallocate($image, rand(150, 220), rand(150, 220), rand(150, 220));
            imageline($image, rand(0, $width), rand(0, $height), rand(0, $width), rand(0, $height), $color);
        }

        // نقاط نویز
        for ($i = 0; $i < 180; $i++) {
            $color = imagecolorallocate($image, rand(130, 210), rand(130, 210), rand(130, 210));
            imagesetpixel($image, rand(0, $width), rand(0, $height), $color);
        }

        // ✍️ نوشتن کاراکترها با فونت داخلی و چرخش ظاهری
        $chars = str_split($code);
        $x = 18;
        foreach ($chars as $i => $char) {
            $color = imagecolorallocate($image, rand(20, 90), rand(20, 90), rand(80, 160));
            imagestring($image, 5, $x + rand(-2, 2), rand(8, 22), $char, $color);
            $x += 28;
        }

        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache');
        imagepng($image);
        imagedestroy($image);
        exit;
    }

    /**
     * 🔤 تولید کد تصادفی CAPTCHA (بدون کاراکترهای گیج‌کننده)
     */
    private static function generateCaptchaCode(int $length = 5): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }

    /* ==================================================
     * 🛡️ توکن CSRF — محافظت فرم‌ها
     * ================================================== */

    /**
     * 🎫 تولید (یا بازیابی) توکن CSRF نشست جاری
     */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * 🏷️ تولید فیلد مخفی CSRF برای قرار دادن در فرم‌ها
     */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . self::csrfToken() . '">';
    }

    /**
     * ✅ اعتبارسنجی توکن CSRF درخواست (POST / DELETE / PUT)
     */
    public static function verifyCsrf(): bool
    {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
    }

    /**
     * 🛑 الزام اعتبار توکن CSRF — در صورت نامعتبر بودن، درخواست متوقف می‌شود
     */
    public static function enforceCsrf(): void
    {
        if (!self::verifyCsrf()) {
            http_response_code(419);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'توکن CSRF نامعتبر است — صفحه را رفرش کنید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /* ==================================================
     * 🔒 مدیریت تلاش‌های ناموفق ورود (Brute Force Protection)
     * ================================================== */

    /**
     * 📝 ثبت تلاش ناموفق ورود
     */
    private function recordFailedAttempt(string $username): void
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

    /**
     * 🔢 شمارش تلاش‌های ناموفق فعال
     */
    private function failedAttempts(string $username): int
    {
        $key = 'login_fail_' . md5(mb_strtolower($username));
        $row = $this->db->fetch(
            'SELECT hit_count FROM rate_limits WHERE limit_key = ? AND expires_at > NOW() LIMIT 1',
            [$key]
        );
        return $row ? (int)$row['hit_count'] : 0;
    }

    /**
     * ⛔ آیا حساب قفل است؟
     */
    private function isLocked(string $username): bool
    {
        return $this->failedAttempts($username) >= MAX_LOGIN_ATTEMPTS;
    }

    /**
     * ⏱️ دقایق باقی‌مانده تا رفع قفل
     */
    private function lockRemaining(string $username): int
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

    /**
     * 🧹 پاکسازی تلاش‌های ناموفق پس از ورود موفق
     */
    private function clearFailedAttempts(string $username): void
    {
        $key = 'login_fail_' . md5(mb_strtolower($username));
        $this->db->delete('rate_limits', 'limit_key = ?', [$key]);
    }

    /**
     * 👤 ایجاد کاربر جدید (برای install.php و مدیریت کاربران)
     */
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
}
