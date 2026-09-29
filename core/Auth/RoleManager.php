<?php
/**
 * 🎭 RoleManager — نقش‌های سیستم
 * تعریف واحد نقش‌ها و برچسب فارسی (admin/editor/brand_manager/guest)
 * 🧩 v2.43 (S13): از Auth.php تک‌عظیم تفکیک شد — Auth.php نقش Facade دارد.
 * @package SahandBrandMaker\Core\Auth
 */

class RoleManager
{
    public const ROLE_ADMIN         = 'admin';
    public const ROLE_EDITOR        = 'editor';
    public const ROLE_BRAND_MANAGER = 'brand_manager';
    public const ROLE_GUEST         = 'guest';

    /** 🏷️ همه نقش‌ها با برچسب فارسی */
    public static function all(): array
    {
        return [
            self::ROLE_ADMIN         => 'مدیر سیستم',
            self::ROLE_EDITOR        => 'ویرایشگر',
            self::ROLE_BRAND_MANAGER => 'مدیر برند',
        ];
    }

    /** نقش سیستمی = دسترسی کامل همه برندها (admin + editor — حفظ رفتار قبلی) */
    public static function isSystem(string $role): bool
    {
        return in_array($role, [self::ROLE_ADMIN, self::ROLE_EDITOR], true);
    }

    /** مدیر برند = فقط برندهای تخصیص‌یافته */
    public static function isBrandManager(string $role): bool
    {
        return $role === self::ROLE_BRAND_MANAGER;
    }

    /** برچسب فارسی نقش */
    public static function label(string $role): string
    {
        return self::all()[$role] ?? $role;
    }
}
