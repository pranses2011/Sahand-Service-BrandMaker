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

        // ۵️⃣ 🔐 ورود دومرحله‌ای (v2.34) — اگر TOTP فعال است و این دستگاه مورد اعتماد نیست،
        //    ورود نیمه‌کاره می‌ماند و کد از verify-2fa.php پرسیده می‌شود
        if (!empty($user['totp_enabled']) && !empty($user['totp_secret']) && !Totp::isTrustedDevice()) {
            $this->clearFailedAttempts($username); // رمز درست بوده — تلاش‌های ناموفق پاک شوند
            session_regenerate_id(true);
            $_SESSION['pending_2fa_user'] = (int)$user['id'];
            $_SESSION['pending_2fa_time'] = time();
            return ['success' => true, 'twofa' => true, 'message' => 'کد دومرحله‌ای لازم است.'];
        }

        // ۶️⃣ ورود موفق — پاکسازی تلاش‌ها و ساخت نشست امن
        $this->clearFailedAttempts($username);
        return $this->completeLogin($user);
    }

    /**
     * ✅ تکمیل ورود پس از (در صورت نیاز) عبور از دومرحله‌ای
     * @param array $user ردیف کامل کاربر از دیتابیس
     */
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

    /**
     * 🔑 کاربر معلق در مرحله دومرحله‌ای (بعد از رمز درست)
     */
    public function pendingTwoFactorId(): int
    {
        // انقضای مرحله معلق: ۵ دقیقه
        if (!empty($_SESSION['pending_2fa_user'])
            && (int)($_SESSION['pending_2fa_time'] ?? 0) > time() - 300) {
            return (int)$_SESSION['pending_2fa_user'];
        }
        unset($_SESSION['pending_2fa_user'], $_SESSION['pending_2fa_time']);
        return 0;
    }

    /**
     * 🔐 راستی‌آزمایی کد TOTP یا کد بازیابی مرحله دوم — با محدودیت تلاش
     * @return array ['success'=>bool,'message'=>string,'user'=>?array]
     */
    public function verifyTwoFactor(string $code, bool $rememberDevice = false): array
    {
        $uid = $this->pendingTwoFactorId();
        if ($uid < 1) {
            return ['success' => false, 'message' => 'مرحله ورود منقضی شده — دوباره وارد شوید.', 'user' => null];
        }

        // ⛔ محدودیت تلاش ناموفق: ۵ تلاش / ۱۰ دقیقه (جلوگیری از حدس کد ۶ رقمی)
        $key = 'twofa_fail_' . $uid;
        $row = $this->db->fetch('SELECT hit_count, expires_at FROM rate_limits WHERE limit_key = ? LIMIT 1', [$key]);
        $fails = ($row && strtotime((string)$row['expires_at']) > time()) ? (int)$row['hit_count'] : 0;
        if ($fails >= 5) {
            unset($_SESSION['pending_2fa_user'], $_SESSION['pending_2fa_time']);
            return ['success' => false, 'message' => '⛔ تلاش‌های نامعتبر زیاد بود — از ابتدا وارد شوید.', 'user' => null];
        }

        $user = $this->db->fetch('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1', [$uid]);
        if (!$user) {
            return ['success' => false, 'message' => 'کاربر یافت نشد.', 'user' => null];
        }

        // ۱) کد TOTP عادی
        $ok = Totp::verify((string)$user['totp_secret'], $code);

        // ۲) کد بازیابی (یک‌بار مصرف)
        $recoveryRemaining = null;
        if (!$ok) {
            $rc = Totp::consumeRecoveryCode((string)($user['totp_recovery'] ?? '[]'), $code);
            if ($rc['matched']) {
                $ok = true;
                $recoveryRemaining = $rc['remainingJson'];
            }
        }

        if (!$ok) {
            $this->recordKeyAttempt($key, $fails);
            $left = 4 - $fails;
            return ['success' => false, 'message' => $left > 0 ? "❌ کد نادرست است. {$left} تلاش باقی مانده." : '❌ کد نادرست است.', 'user' => null];
        }

        // ✅ موفق — پاک‌سازی شمارنده + درج مجدد کدهای بازیابی باقیمانده
        $this->db->delete('rate_limits', 'limit_key = ?', [$key]);
        if ($recoveryRemaining !== null) {
            $this->db->update('users', ['totp_recovery' => $recoveryRemaining], 'id = ?', [$uid]);
        }
        if ($rememberDevice) {
            Totp::issueTrustCookie($uid);
        }
        $result = $this->completeLogin($user);
        $result['user'] = $user;
        if ($recoveryRemaining !== null) {
            $result['used_recovery'] = true;
        }
        return $result;
    }

    /**
     * 📝 ثبت تلاش ناموفق برای کلید دلخواه (۲FA/بازیابی رمز) — قابل استفاده عمومی
     */
    private function recordKeyAttempt(string $key, int $currentFails): void
    {
        $expires = date('Y-m-d H:i:s', time() + 600);
        if ($currentFails > 0) {
            $this->db->update('rate_limits', ['hit_count' => $currentFails + 1, 'expires_at' => $expires], 'limit_key = ?', [$key]);
        } else {
            $this->db->insert('rate_limits', ['limit_key' => $key, 'hit_count' => 1, 'expires_at' => $expires]);
        }
    }

    /**
     * 🚪 خروج کاربر و پاکسازی نشست
     */
    public function logout(): void
    {
        if ($this->isLoggedIn()) {
            Logger::activity($this->userId(), 'خروج از سیستم', 'خروج کاربر');
        }
        Totp::revokeTrustCookie(); // 🍪 دستگاه ذی‌نفع دیگر مورد اعتماد نیست (v2.34)
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
     * 🛂 ACL سطح‌برند (v2.34) — نقش سوم brand_manager
     * ================================================== */

    /** نقش‌های سیستمی با دسترسی کامل به همه برندها (admin + editor — حفظ رفتار قبلی) */
    public function isSystemRole(): bool
    {
        return in_array($this->role(), ['admin', 'editor'], true);
    }

    /** مدیر برند — فقط برندهای تخصیص‌یافته */
    public function isBrandManager(): bool
    {
        return $this->role() === 'brand_manager';
    }

    /**
     * 🆔 شناسه برندهای در دسترس کاربر جاری — admin/editor همه، brand_manager فقط تخصیص‌یافته‌ها
     * @return int[]|null null یعنی «همه برندها» (بدون محدودیت)
     */
    public function accessibleBrandIds(): ?array
    {
        if (!$this->isBrandManager()) {
            return null;
        }
        // بدون کش static: کوئری اندیس‌دار سبک است و تخصیص لحظه‌ای مدیر (در همان نشست)
        // بلافاصله اعمال می‌شود
        $rows = $this->db->fetchAll(
            'SELECT brand_id FROM brand_user_access WHERE user_id = ?',
            [$this->userId()]
        );
        return array_map('intval', array_column($rows, 'brand_id'));
    }

    /**
     * ✅ آیا کاربر جاری به این برند دسترسی دارد؟
     */
    public function canAccessBrand(int $brandId): bool
    {
        if ($brandId < 1) {
            return false;
        }
        $ids = $this->accessibleBrandIds();
        return $ids === null || in_array($brandId, $ids, true);
    }

    /**
     * 🛑 الزام دسترسی به برند — در صورت نبود، ۴۰۳ (برای صفحات ?brand=)
     */
    public function requireBrandAccess(int $brandId): void
    {
        if (!$this->canAccessBrand($brandId)) {
            http_response_code(403);
            Logger::activity($this->userId(), 'دسترسی غیرمجاز', "تلاش برای دسترسی به برند #{$brandId} بدون مجوز");
            echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>دسترسی غیرمجاز</title></head>'
                . '<body style="font-family:Tahoma,sans-serif;direction:rtl;text-align:center;padding:60px 20px">'
                . '<div style="font-size:56px">⛔</div><h2>دسترسی غیرمجاز</h2>'
                . '<p>شما به این برند دسترسی ندارید. برای دریافت دسترسی با مدیر سیستم تماس بگیرید.</p>'
                . '<p><a href="index.php">بازگشت به داشبورد</a></p></body></html>';
            exit;
        }
    }

    /**
     * 🛑 الزام نقش سیستمی — صفحات حیاتی پنل (users/settings/api-keys/...)
     * brand_manager را با ۴۰۳ رد می‌کند (admin/editor طبق رفتار قبلی عبور می‌کنند)
     */
    public function requireSystemRole(): void
    {
        if ($this->isBrandManager()) {
            http_response_code(403);
            echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>دسترسی غیرمجاز</title></head>'
                . '<body style="font-family:Tahoma,sans-serif;direction:rtl;text-align:center;padding:60px 20px">'
                . '<div style="font-size:56px">⛔</div><h2>این بخش فقط برای مدیر سیستم است</h2>'
                . '<p><a href="index.php">بازگشت به داشبورد</a></p></body></html>';
            exit;
        }
    }

    /**
     * 🏷️ شرط SQL برندهای در دسترس — برای فیلتر خودکار لیست‌ها
     * @return array [sql, params] — sql خالی یعنی بدون محدودیت
     */
    public function brandAccessSql(string $column = 'b.id'): array
    {
        $ids = $this->accessibleBrandIds();
        if ($ids === null) {
            return ['', []];
        }
        if ($ids === []) {
            return [' AND 1=0', []]; // هیچ برندی تخصیص نیافته
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return [" AND {$column} IN ({$ph})", $ids];
    }

    /* ==================================================
     * 📨 بازیابی رمز عبور (v2.34) — توکن یک‌بارمصرف ۶۰ دقیقه‌ای
     * ================================================== */

    /**
     * 🔗 ساخت توکن بازیابی برای کاربر (اگر کاربر معتبر و ایمیل داشته باشد)
     * همیشه پاسخ یکسان برمی‌گردد (جلوگیری از افشای وجود کاربر)
     * @return array ['sent'=>bool,'error'=>?string,'token'=>?string(فقط در محیط تست/دیباگ)]
     */
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
            $this->recordKeyAttempt($key, $fails); // حتی در نبود کاربر شمارش می‌شود (ضد شمارش کاربران)
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
        $this->recordKeyAttempt($key, $fails);
        return ['sent' => true, 'error' => null, 'token' => $token, 'user' => $user];
    }

    /**
     * ✅ مصرف توکن بازیابی + تعیین رمز جدید
     * @return array ['ok'=>bool,'message'=>string,'user_id'=>int]
     */
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

    /* ==================================================
     * 🖼️ سیستم CAPTCHA تصویری (بدون وابستگی خارجی)
     * v2.5 — رندر ۳ لایه‌ای تضمینی + حروف درشت
     * ================================================== */

    /**
     * 🎨 تولید و نمایش تصویر CAPTCHA — همیشه نمایش داده می‌شود
     *
     * سه لایه رندر (به ترتیب تلاش):
     *   ۱️⃣ GD + فونت TTF (فونت‌های سایت‌ساز یا سیستم) → حروف ۳۰px با چرخش واقعی
     *   ۲️⃣ GD فونت داخلی + بزرگ‌نمایی نرم ۲.۶× (Bicubic) → حروف تقریباً ۲.۵ برابر قبلی
     *   ۳️⃣ SVG خالص PHP → حتی بدون اکستنشن GD کار می‌کند
     *
     * خروجی: تصویر PNG یا SVG — مستقیماً به مرورگر ارسال می‌شود
     */
    public static function renderCaptcha(): void
    {
        $code = self::generateCaptchaCode(5);

        // 💾 ذخیره در سشن (در صورت فعال بودن — security-code.php سشن را فعال می‌کند)
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['captcha_code'] = $code;
        }

        // 🧹 پاک‌سازی هر بافر خروجی احتمالی تا هدر Content-Type سالم ارسال شود
        // (خروجی ناخواسته = خرابی تصویر در مرورگر)
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $width = 250;
        $height = 84;

        // 🎯 لایه ۱ و ۲: رندر با GD (در صورت وجود)
        $gdAvailable = extension_loaded('gd') && function_exists('imagecreatetruecolor');
        if ($gdAvailable) {
            try {
                self::renderCaptchaGd($code, $width, $height);
                return; // renderCaptchaGd خودش exit می‌کند
            } catch (Throwable $e) {
                // 🧯 هر خطای GD → سقوط نرم به لایه SVG
                @error_log('[CAPTCHA] GD render failed, falling back to SVG: ' . $e->getMessage());
            }
        } elseif (self::isCaptchaDirectRequest()) {
            // ثبت در لاگ برای اطلاع مدیر (GD خاموش است — هر درخواست کپچا یک خط لاگ)
            @error_log('[CAPTCHA] GD extension is NOT available — using SVG fallback. Enable "gd" in PHP ' . PHP_VERSION . ' settings for raster rendering.');
        }

        // 🛟 لایه ۳: SVG — بدون هیچ وابستگی، همیشه کار می‌کند
        self::renderCaptchaSvg($code, $width, $height);
    }

    /**
     * 📦 حالت AJAX کپچا — data-URI برای دور زدن ادبلاکرهای دسکتاپ
     *
     * برخی افزونه‌های مرورگر (uBlock/AdGuard و...) درخواست‌های تصویری با آدرس حاوی
     * «captcha» را بلاک می‌کنند (لب‌تاپ: بلا می‌شود؛ گوشی بدون افزونه: نمایش داده می‌شود).
     * این متد کپچا را به‌صورت JSON با data-URI برمی‌گرداند تا از fetch (نه تگ img)
     * بارگذاری شود و هیچ بلاکر تصویری نتواند آن را متوقف کند.
     */
    public static function renderCaptchaDataUri(): void
    {
        $code = self::generateCaptchaCode(5);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['captcha_code'] = $code;
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        try {
            $svg = self::buildCaptchaSvg($code, 250, 84);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            echo json_encode([
                'success'  => true,
                'data_uri' => 'data:image/svg+xml;base64,' . base64_encode($svg),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * 🖼️ کپچای درون‌خطی — رفع قطعی ۴۰۴/بلاک در لپ‌تاپ (v2.7)
     * =====================================================
     * کد را تولید و در سشن ذخیره می‌کند و data-URI را «برمی‌گرداند» (خروجی نمی‌دهد).
     * صفحه لاگین آن را مستقیماً داخل src تگ img می‌گذارد →
     * صفر درخواست HTTP → دیگر نه ۴۰۴ ممکن است، نه بلاک ادبلاکر، نه کش منسوخ.
     */
    public static function captchaInlineDataUri(): string
    {
        $code = self::generateCaptchaCode(5);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['captcha_code'] = $code;
        }
        try {
            $svg = self::buildCaptchaSvg($code, 250, 84);
            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        } catch (Throwable $e) {
            // 🛟 هرگز نباید رخ دهد (SVG خالص PHP است) — ولی تضمین: کد متنی
            @error_log('[CAPTCHA] inline build failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 🖼️ رندر CAPTCHA با GD — فونت TTF در صورت وجود، وگرنه فونت داخلی بزرگ‌نمایی‌شده
     */
    private static function renderCaptchaGd(string $code, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);

        // 🎨 پس‌زمینه روشن
        $bg = imagecolorallocate($image, 243, 244, 246);
        imagefilledrectangle($image, 0, 0, $width, $height, $bg);

        // خطوط نویز برای جلوگیری از خواندن رباتیک
        for ($i = 0; $i < 7; $i++) {
            $color = imagecolorallocate($image, rand(150, 220), rand(150, 220), rand(150, 220));
            imageline($image, rand(0, $width), rand(0, $height), rand(0, $width), rand(0, $height), $color);
        }

        // نقاط نویز
        for ($i = 0; $i < 220; $i++) {
            $color = imagecolorallocate($image, rand(130, 210), rand(130, 210), rand(130, 210));
            imagesetpixel($image, rand(0, $width), rand(0, $height), $color);
        }

        $font = self::findCaptchaFont();
        $scale = $width / 220;

        if ($font !== null) {
            // ✍️ لایه ۱: فونت واقعی TTF — حروف درشت با چرخش واقعی هر حرف
            $x = (int)round(26 * $scale);
            $size = (int)round(30 * $scale);
            foreach (str_split($code) as $char) {
                $color = imagecolorallocate($image, rand(20, 90), rand(20, 90), rand(80, 160));
                imagettftext($image, $size, rand(-14, 14), $x, rand((int)round(46 * $scale), (int)round(56 * $scale)), $color, $font, $char);
                $x += (int)round(36 * $scale);
            }
        } else {
            // ✍️ لایه ۲: فونت داخلی روی بوم کوچک + بزرگ‌نمایی نرم ۲.۶× با Bicubic
            //    (حروف ~۲۳×۳۹ پیکسل — تقریباً ۲.۵ برابر بزرگ‌تر از رندر قبلی)
            $sw = (int)round($width / 2.6);
            $sh = (int)round($height / 2.6);
            $small = imagecreatetruecolor($sw, $sh);
            $sbg = imagecolorallocate($small, 243, 244, 246);
            imagefilledrectangle($small, 0, 0, $sw, $sh, $sbg);

            $x = 6;
            foreach (str_split($code) as $char) {
                $color = imagecolorallocate($small, rand(20, 90), rand(20, 90), rand(80, 160));
                imagestring($small, 5, $x + rand(-1, 1), rand(2, 9), $char, $color);
                $x += 16;
            }

            // 📐 بزرگ‌نمایی نرم روی بوم اصلی (نویزها روی بوم بزرگ‌اند؛ حروف از بوم کوچک می‌آیند)
            imagesetinterpolation($image, IMG_BICUBIC);
            imagecopyresampled($image, $small, 0, 0, 0, 0, $width, $height, $sw, $sh);
            imagedestroy($small);
        }

        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        imagepng($image, null, 6);
        imagedestroy($image);
        exit;
    }

    /**
     * 🛟 رندر CAPTCHA به صورت SVG — صفر وابستگی به اکستنشن‌های PHP
     * (اگر GD روی هاست خاموش باشد، کپچا همچنان نمایش داده می‌شود)
     */
    private static function renderCaptchaSvg(string $code, int $width, int $height): void
    {
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo self::buildCaptchaSvg($code, $width, $height);
        exit;
    }

    /**
     * 🧩 ساخت بدنه SVG کپچا (مشترک بین خروجی مستقیم و حالت data-URI)
     */
    private static function buildCaptchaSvg(string $code, int $width, int $height): string
    {
        $parts = [];
        $parts[] = '<rect width="' . $width . '" height="' . $height . '" rx="10" fill="#f3f4f6"/>';

        // خطوط نویز
        for ($i = 0; $i < 7; $i++) {
            $color = 'rgb(' . rand(150, 220) . ',' . rand(150, 220) . ',' . rand(150, 220) . ')';
            $parts[] = sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1.5"/>',
                rand(0, $width), rand(0, $height), rand(0, $width), rand(0, $height), $color
            );
        }

        // نقاط نویز
        for ($i = 0; $i < 90; $i++) {
            $color = 'rgb(' . rand(130, 210) . ',' . rand(130, 210) . ',' . rand(130, 210) . ')';
            $parts[] = sprintf(
                '<circle cx="%d" cy="%d" r="1.1" fill="%s"/>',
                rand(0, $width), rand(0, $height), $color
            );
        }

        // ✍️ حروف درشت با چرخش — مقیاس‌پذیر با ابعاد بوم (v2.7.1: کپچای بزرگ ۲۵۰×۸۴)
        $scale = $width / 220;
        $x = (int)round(34 * $scale);
        $step = (int)round(38 * $scale);
        $yMin = (int)round(48 * $scale);
        $yMax = (int)round(57 * $scale);
        $sizeMin = (int)round(32 * $scale);
        $sizeMax = (int)round(38 * $scale);
        foreach (str_split($code) as $char) {
            $color = 'rgb(' . rand(20, 90) . ',' . rand(20, 90) . ',' . rand(80, 160) . ')';
            $angle = rand(-14, 14);
            $y = rand($yMin, $yMax);
            $size = rand($sizeMin, $sizeMax);
            $parts[] = sprintf(
                '<text x="%d" y="%d" font-family="Vazirmatn,Vazir,Tahoma,Arial,sans-serif" font-size="%d" font-weight="700" fill="%s" text-anchor="middle" transform="rotate(%d %d %d)">%s</text>',
                $x, $y, $size, $color, $angle, $x, $y, htmlspecialchars($char, ENT_QUOTES)
            );
            $x += $step;
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">'
            . implode('', $parts) . '</svg>';
    }

    /**
     * 🔍 یافتن فونت TTF مناسب رندر کپچا
     * ترتیب جستجو: فونت‌های دانلودشده سایت‌ساز → فونت‌های رایج سیستم
     * خروجی: مسیر فونت یا null (در نبود FreeType/فونت)
     */
    private static function findCaptchaFont(): ?string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        if (!function_exists('imagettftext') || !function_exists('imagettfbbox')) {
            return $cached = null; // FreeType در دسترس نیست
        }

        // ۱) فونت‌های TTF نصب‌شده داخل سایت‌ساز (پوشه فونت‌های پنل)
        $candidates = array_merge(
            glob(ROOT_PATH . '/assets/fonts/fa/*/*.ttf') ?: [],
            glob(ROOT_PATH . '/assets/fonts/en/*/*.ttf') ?: [],
            glob(ROOT_PATH . '/assets/fonts/*/*.ttf') ?: []
        );

        // ۲) فونت‌های رایج سیستم‌عامل
        $systemFonts = PHP_OS_FAMILY === 'Windows'
            ? ['C:/Windows/Fonts/arial.ttf', 'C:/Windows/Fonts/tahoma.ttf', 'C:/Windows/Fonts/verdana.ttf', 'C:/Windows/Fonts/calibri.ttf']
            : [
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
                '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
            ];
        foreach ($systemFonts as $f) {
            if (is_file($f)) {
                $candidates[] = $f;
            }
        }

        // اعتبارسنجی هر فونت با یک bbox آزمایشی
        foreach ($candidates as $font) {
            if (is_file($font) && is_readable($font) && @imagettfbbox(20, 0, $font, 'A') !== false) {
                return $cached = $font;
            }
        }
        return $cached = null;
    }

    /**
     * 🔎 آیا درخواست جاری مستقیماً captcha.php است؟ (برای لاگ یک‌باره)
     */
    private static function isCaptchaDirectRequest(): bool
    {
        $uri = $_SERVER['SCRIPT_NAME'] ?? '';
        return substr($uri, -11) === 'captcha.php';
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

    /**
     * 📧 ایمیلِ کاربر فعال با این نام کاربری (برای صفحه بازیابی)
     * فقط بخش اول ایمیل را برمی‌گرداند (نمایش ماسک‌شده — j***@gmail.com)
     */
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
