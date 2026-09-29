<?php
/**
 * 🔑 Authentication — ورود/خروج کاربر
 * جریان کامل ورود (کپچا ← قفل ← رمز ← 2FA ← نشست امن) + خروج
 * 🧩 v2.43 (S13): از Auth.php تک‌عظیم تفکیک شد — Auth.php نقش Facade دارد.
 * @package SahandBrandMaker\Core\Auth
 */

class Authentication
{
    /** @var Database */
    private $db;
    /** @var LoginRateLimiter */
    private $limiter;
    /** @var SessionManager */
    private $session;

    public function __construct($db, LoginRateLimiter $limiter, SessionManager $session)
    {
        $this->db = $db;
        $this->limiter = $limiter;
        $this->session = $session;
    }

    public function login(string $username, string $password, string $captcha = ''): array
    {
        // ۱️⃣ بررسی CAPTCHA (در صورت وجود در سشن)
        /* 🧪 v2.43 (S15) — هوک تست مرورگر: فقط وقتی فلج محیط تست از
           config.local.php تعریف شده باشد کپچا رد می‌شود (تولید/پروڈاکشن
           هرگز این ثابت را تعریف نمی‌کنند — config.local.php گیت‌ایگنور است) */
        if (!empty($_SESSION['captcha_code']) && !defined('SAHAND_TEST_SKIP_CAPTCHA')) {
            if (mb_strtolower(trim($captcha)) !== mb_strtolower($_SESSION['captcha_code'])) {
                return ['success' => false, 'message' => '🔒 کد امنیتی وارد شده صحیح نیست.'];
            }
        }

        // ۲️⃣ بررسی قفل حساب به دلیل تلاش‌های ناموفق
        if ($this->limiter->isLocked($username)) {
            $minutes = $this->limiter->lockRemaining($username);
            return ['success' => false, 'message' => "⛔ حساب به دلیل تلاش‌های ناموفق موقتاً قفل شده است. {$minutes} دقیقه دیگر تلاش کنید."];
        }

        // ۳️⃣ جستجوی کاربر در دیتابیس
        $user = $this->db->fetch(
            'SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1',
            [trim($username)]
        );

        // ۴️⃣ بررسی رمز عبور با password_verify امن
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->limiter->recordFailedAttempt($username);
            $remaining = MAX_LOGIN_ATTEMPTS - $this->limiter->failedAttempts($username);
            return ['success' => false, 'message' => $remaining > 0
                ? "❌ نام کاربری یا رمز عبور اشتباه است. {$remaining} تلاش باقی مانده."
                : '⛔ حساب شما موقتاً قفل شد. کمی بعد تلاش کنید.'];
        }

        // ۵️⃣ 🔐 ورود دومرحله‌ای (v2.34) — اگر TOTP فعال است و این دستگاه مورد اعتماد نیست،
        //    ورود نیمه‌کاره می‌ماند و کد از verify-2fa.php پرسیده می‌شود
        if (!empty($user['totp_enabled']) && !empty($user['totp_secret']) && !Totp::isTrustedDevice()) {
            $this->limiter->clearFailedAttempts($username); // رمز درست بوده — تلاش‌های ناموفق پاک شوند
            session_regenerate_id(true);
            $_SESSION['pending_2fa_user'] = (int)$user['id'];
            $_SESSION['pending_2fa_time'] = time();
            return ['success' => true, 'twofa' => true, 'message' => 'کد دومرحله‌ای لازم است.'];
        }

        // ۶️⃣ ورود موفق — پاکسازی تلاش‌ها و ساخت نشست امن
        $this->limiter->clearFailedAttempts($username);
        return $this->completeLogin($user);
    }

    public function completeLogin(array $user): array
    {
        session_regenerate_id(true); // 🔄 جلوگیری از Session Fixation

        $_SESSION['user_id']    = (int)$user['id'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['full_name']  = $user['full_name'];
        $_SESSION['user_role']  = $user['role'];
        $_SESSION['login_time'] = time();
        unset($_SESSION['pending_2fa_user'], $_SESSION['pending_2fa_time']);

        // بروزرسانی آخرین زمان ورود
        $this->db->update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

        // ثبت لاگ فعالیت
        Logger::activity((int)$user['id'], 'ورود به سیستم', 'ورود موفق کاربر ' . $user['username']);

        return ['success' => true, 'message' => '✅ خوش آمدید!'];
    }

    public function logout(): void
    {
        if ($this->session->isLoggedIn()) {
            Logger::activity($this->session->userId(), 'خروج از سیستم', 'خروج کاربر');
        }
        Totp::revokeTrustCookie(); // 🍪 دستگاه ذی‌نفع دیگر مورد اعتماد نیست (v2.34)
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain']);
        }
        session_destroy();
    }
}
