<?php
/**
 * 🔐 Auth — Facade یکپارچه احراز هویت (S13 / v2.43)
 * ====================================================
 * درخواست کاربر: «Auth.php بیش از حد بزرگ است — جدا شود (Authentication /
 * SessionManager / PasswordManager / TwoFactorAuth / PermissionManager /
 * RoleManager / LoginRateLimiter)؛ برای پروژه‌ای که سال‌ها توسعه می‌گیرد.»
 *
 * 🏗 ساختار جدید — پیاده‌سازی در core/Auth/ :
 *   Authentication    ← جریان ورود/خروج (کپچا ← قفل ← رمز ← 2FA ← نشست)
 *   SessionManager    ← نشست + شناسه/نقش + توکن CSRF
 *   PasswordManager   ← بازیابی رمز + ایجاد کاربر + ایمیل ماسک‌شده
 *   TwoFactorAuth     ← TOTP + کد بازیابی + دستگاه مورد اعتماد
 *   PermissionManager ← ACL سطح‌برند (admin/editor همه؛ brand_manager تخصیصی)
 *   RoleManager       ← تعریف واحد نقش‌ها و برچسب فارسی
 *   LoginRateLimiter  ← قفل حساب و شمارش تلاش‌های ناموفق
 *   Captcha           ← کپچای تصویری سه‌لایه (GD/SVG/درون‌خطی)
 *
 * ✅ این کلاس Facade است: تمام امضاهای قبلی دست‌نخورده مانده‌اند —
 * هر فراخوانی Auth::method() در کل پروژه بدون تغییر کار می‌کند.
 *
 * @package SahandBrandMaker
 * @version 2.0.0
 */
class Auth
{
    /** @var Database */
    private $db;
    /** @var Authentication */
    private $authentication;
    /** @var SessionManager */
    private $session;
    /** @var TwoFactorAuth */
    private $twoFactor;
    /** @var PermissionManager */
    private $permissions;
    /** @var PasswordManager */
    private $passwords;
    /** @var LoginRateLimiter */
    private $limiter;

    public function __construct()
    {
        $this->db          = Database::getInstance();
        $this->limiter     = new LoginRateLimiter($this->db);
        $this->session     = new SessionManager();
        $this->authentication = new Authentication($this->db, $this->limiter, $this->session);
        $this->twoFactor   = new TwoFactorAuth($this->db, $this->limiter, $this->authentication);
        $this->permissions = new PermissionManager($this->db, $this->session);
        $this->passwords   = new PasswordManager($this->db, $this->limiter);
    }

    /* ══════════ 🔑 Authentication ══════════ */

    public function login(string $username, string $password, string $captcha = ''): array
    {
        return $this->authentication->login($username, $password, $captcha);
    }

    public function completeLogin(array $user): array
    {
        return $this->authentication->completeLogin($user);
    }

    public function logout(): void
    {
        $this->authentication->logout();
    }

    /* ══════════ 🔐 TwoFactorAuth ══════════ */

    public function pendingTwoFactorId(): int
    {
        return $this->twoFactor->pendingTwoFactorId();
    }

    public function verifyTwoFactor(string $code, bool $rememberDevice = false): array
    {
        return $this->twoFactor->verifyTwoFactor($code, $rememberDevice);
    }

    /* ══════════ 🕐 SessionManager ══════════ */

    public function isLoggedIn(): bool
    {
        return $this->session->isLoggedIn();
    }

    public function requireLogin(): void
    {
        $this->session->requireLogin();
    }

    public function userId(): int
    {
        return $this->session->userId();
    }

    public function role(): string
    {
        return $this->session->role();
    }

    public static function csrfToken(): string
    {
        return SessionManager::csrfToken();
    }

    public static function csrfField(): string
    {
        return SessionManager::csrfField();
    }

    public static function verifyCsrf(): bool
    {
        return SessionManager::verifyCsrf();
    }

    public static function enforceCsrf(): void
    {
        SessionManager::enforceCsrf();
    }

    /* ══════════ 🛂 PermissionManager ══════════ */

    public function isAdmin(): bool
    {
        return $this->permissions->isAdmin();
    }

    public function isSystemRole(): bool
    {
        return $this->permissions->isSystemRole();
    }

    public function isBrandManager(): bool
    {
        return $this->permissions->isBrandManager();
    }

    public function accessibleBrandIds(): ?array
    {
        return $this->permissions->accessibleBrandIds();
    }

    public function canAccessBrand(int $brandId): bool
    {
        return $this->permissions->canAccessBrand($brandId);
    }

    public function requireBrandAccess(int $brandId): void
    {
        $this->permissions->requireBrandAccess($brandId);
    }

    public function requireSystemRole(): void
    {
        $this->permissions->requireSystemRole();
    }

    public function requirePermission(string $permission): void
    {
        $this->permissions->requirePermission($permission);
    }

    public function brandAccessSql(string $column = 'b.id'): array
    {
        return $this->permissions->brandAccessSql($column);
    }

    /* ══════════ 📨 PasswordManager ══════════ */

    public function createPasswordReset(string $login): array
    {
        return $this->passwords->createPasswordReset($login);
    }

    public function consumePasswordReset(string $token, string $newPassword): array
    {
        return $this->passwords->consumePasswordReset($token, $newPassword);
    }

    public static function createUser(string $username, string $password, string $fullName, string $role = 'admin'): int
    {
        return PasswordManager::createUser($username, $password, $fullName, $role);
    }

    public static function maskedEmailFor(string $login): string
    {
        return PasswordManager::maskedEmailFor($login);
    }

    /* ══════════ 🖼️ Captcha ══════════ */

    public static function renderCaptcha(): void
    {
        Captcha::renderCaptcha();
    }

    public static function renderCaptchaDataUri(): void
    {
        Captcha::renderCaptchaDataUri();
    }

    public static function captchaInlineDataUri(): string
    {
        return Captcha::captchaInlineDataUri();
    }
}
