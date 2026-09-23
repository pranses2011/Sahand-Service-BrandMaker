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

        // 💾 ذخیره در سشن (در صورت فعال بودن — captcha.php سشن را فعال می‌کند)
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['captcha_code'] = $code;
        }

        // 🧹 پاک‌سازی هر بافر خروجی احتمالی تا هدر Content-Type سالم ارسال شود
        // (خروجی ناخواسته = خرابی تصویر در مرورگر)
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $width = 220;
        $height = 72;

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

        if ($font !== null) {
            // ✍️ لایه ۱: فونت واقعی TTF — حروف درشت ۳۰px با چرخش واقعی هر حرف
            $x = 26;
            foreach (str_split($code) as $char) {
                $color = imagecolorallocate($image, rand(20, 90), rand(20, 90), rand(80, 160));
                imagettftext($image, 30, rand(-14, 14), $x, rand(46, 56), $color, $font, $char);
                $x += 36;
            }
        } else {
            // ✍️ لایه ۲: فونت داخلی روی بوم کوچک + بزرگ‌نمایی نرم ۲.۶× با Bicubic
            //    (حروف ~۲۳×۳۹ پیکسل — تقریباً ۲.۵ برابر بزرگ‌تر از رندر قبلی)
            $sw = (int)round($width / 2.6);   // ۸۵
            $sh = (int)round($height / 2.6);  // ۲۸
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

        // ✍️ حروف درشت ۳۲-۳۸px با چرخش
        $x = 34;
        foreach (str_split($code) as $char) {
            $color = 'rgb(' . rand(20, 90) . ',' . rand(20, 90) . ',' . rand(80, 160) . ')';
            $angle = rand(-14, 14);
            $y = rand(48, 57);
            $size = rand(32, 38);
            $parts[] = sprintf(
                '<text x="%d" y="%d" font-family="Vazirmatn,Vazir,Tahoma,Arial,sans-serif" font-size="%d" font-weight="700" fill="%s" text-anchor="middle" transform="rotate(%d %d %d)">%s</text>',
                $x, $y, $size, $color, $angle, $x, $y, htmlspecialchars($char, ENT_QUOTES)
            );
            $x += 38;
        }

        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">'
            . implode('', $parts) . '</svg>';
        exit;
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
}
