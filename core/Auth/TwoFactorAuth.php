<?php
/**
 * 🔐 TwoFactorAuth — ورود دومرحله‌ای TOTP (v2.34)
 * کد TOTP + کد بازیابی یک‌بارمصرف + دستگاه مورد اعتماد + محدودیت تلاش
 * 🧩 v2.43 (S13): از Auth.php تک‌عظیم تفکیک شد — Auth.php نقش Facade دارد.
 * @package SahandBrandMaker\Core\Auth
 */

class TwoFactorAuth
{
    /** @var Database */
    private $db;
    /** @var LoginRateLimiter */
    private $limiter;
    /** @var Authentication برای completeLogin پس از تأیید */
    private $auth;

    public function __construct($db, LoginRateLimiter $limiter, Authentication $auth)
    {
        $this->db = $db;
        $this->limiter = $limiter;
        $this->auth = $auth;
    }

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
            $this->limiter->recordKeyAttempt($key, $fails);
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
        $result = $this->auth->completeLogin($user);
        $result['user'] = $user;
        if ($recoveryRemaining !== null) {
            $result['used_recovery'] = true;
        }
        return $result;
    }
}
