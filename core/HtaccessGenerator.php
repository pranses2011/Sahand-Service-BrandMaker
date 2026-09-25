<?php
/**
 * ⚙️ تولیدکننده .htaccess سایت برند — پس از استقرار
 * ================================================
 * طبق سند بخش ۲۱ (بخش ۵): تولید .htaccess بهینه شامل:
 *   - mod_rewrite + URL Rewriting (آدرس‌های تمیز)
 *   - ریدایرکت HTTP → HTTPS
 *   - ریدایرکت www → non-www
 *   - فشرده‌سازی Gzip
 *   - کش مرورگر (تصاویر ۳۰ روز / CSS-JS ۷ روز / HTML ۱ ساعت)
 *   - محافظت از فایل‌های حساس (config.php، .htaccess، cache)
 *   - هدرهای امنیتی (X-Frame-Options و ...)
 *   - صفحه ۴۰۴ سفارشی
 *
 * 🆕 v2.20.0 — سه سطح سازگاری (compatLevel):
 *   ریشه‌یابی خطای «تست نهایی ناموفق: پاسخ HTTP: 500»:
 *   ① «Require all denied» فقط سینتکس Apache 2.4 است — روی Apache 2.2
 *      (سرورهای cPanel قدیمی) «Invalid command 'Require'» → خطای 500 قطعی!
 *      → بلوک‌های دوسینتکس: IfModule mod_authz_core.c (2.4) + !mod_authz_core.c (2.2: Order/Allow/Deny)
 *   ② «Options -Indexes» روی هاست‌هایی که AllowOverride شامل Options نیست → 500
 *   ③ سطوح تنزل‌پذیر: full (کامل) / safe (بدون Options و بلوک‌های Files — برای
 *      هاست‌های سخت‌گیر) / minimal (فقط DirectoryIndex + ErrorDocument + URLهای تمیز)
 *      — Deployer در صورت شکست تست نهایی، خودکار سطح پایین‌تر را امتحان می‌کند
 *      (مکانیزم خوددرمانی — دیگر .htaccess هرگز باعث شکست استقرار نمی‌شود)
 *
 * @package SahandBrandMaker
 * @version 2.0.0
 */
class HtaccessGenerator
{
    /** سطح کامل — همه بهینه‌سازی‌ها (پیش‌فرض) */
    public const LEVEL_FULL    = 'full';
    /** سطح امن — بدون Options و بلوک‌های Files/FilesMatch (هاست‌های سخت‌گیر) */
    public const LEVEL_SAFE    = 'safe';
    /** سطح حداقلی — فقط فایل پیش‌فرض + 404 + URLهای تمیز */
    public const LEVEL_MINIMAL = 'minimal';

    /**
     * ⚙️ تولید محتوای .htaccess سایت برند
     *
     * @param string $fullDomain دامنه کامل (برای ریدایرکت www و HTTPS)
     * @param array  $pageTypes لیست اسلاگ صفحات فعال برند (اختیاری)
     * @param string $compatLevel سطح سازگاری: full | safe | minimal (پیش‌فرض full)
     * @return string محتوای .htaccess
     */
    public static function generate(string $fullDomain, array $pageTypes = [], string $compatLevel = self::LEVEL_FULL): string
    {
        $domain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', strtolower($fullDomain));
        $wwwDomain = 'www.' . $domain;
        $level = in_array($compatLevel, [self::LEVEL_FULL, self::LEVEL_SAFE, self::LEVEL_MINIMAL], true)
            ? $compatLevel : self::LEVEL_FULL;

        // 📄 قوانین بازنویسی صفحات — از صفحات فعال برند یا مجموعه استاندارد
        $rewriteRules = self::buildRewriteRules($pageTypes);

        // 📅 تاریخ تولید برای سربرگ (شمسی)
        $generationDate = ShamsiDate::forDisplay();

        // 🏷️ برچسب سطح در سربرگ (به‌جز full — رفتار پیش‌فرض تغییری نکرده)
        $levelNote = $level === self::LEVEL_FULL
            ? ''
            : "# ⚠️ سطح سازگاری: {$level} — سرور شما با دایرکتیوهای کامل سازگار نیست (تنزل خودکار)\n";

        // 🧩 بخش‌های مشروط بر اساس سطح
        $optionsBlock    = $level === self::LEVEL_FULL ? "# 🚫 غیرفعال‌سازی فهرست پوشه‌ها\nOptions -Indexes\n\n" : '';
        $redirectsBlock  = $level === self::LEVEL_MINIMAL ? '' : <<<BLOCK
    # 🔒 ریدایرکت HTTP → HTTPS
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://{$domain}/$1 [R=301,L]

    # 🌐 ریدایرکت www → non-www
    RewriteCond %{HTTP_HOST} ^{$wwwDomain}$ [NC]
    RewriteRule ^(.*)$ https://{$domain}/$1 [R=301,L]

BLOCK;
        $sensitiveBlock  = <<<'BLOCK'
    # 🚫 جلوگیری از دسترسی مستقیم به فایل‌های حساس
    RewriteRule ^config\.php$ - [F,L]
    RewriteRule ^\.htaccess$ - [F,L]
    RewriteRule ^includes/ - [F,L]
    RewriteRule ^cache/ - [F,L]
    RewriteRule ^\.env - [F,L]
BLOCK;
        $deflateBlock    = $level === self::LEVEL_MINIMAL ? '' : <<<'BLOCK'

# 📦 فشرده‌سازی Gzip — کاهش حجم انتقال
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript
    AddOutputFilterByType DEFLATE application/javascript application/json application/xml
    AddOutputFilterByType DEFLATE image/svg+xml
</IfModule>
BLOCK;
        $expiresBlock    = $level === self::LEVEL_MINIMAL ? '' : <<<'BLOCK'

# ⏱️ کش مرورگر (Browser Caching)
<IfModule mod_expires.c>
    ExpiresActive On
    # تصاویر: ۳۰ روز
    ExpiresByType image/jpeg "access plus 30 days"
    ExpiresByType image/png "access plus 30 days"
    ExpiresByType image/gif "access plus 30 days"
    ExpiresByType image/webp "access plus 30 days"
    ExpiresByType image/svg+xml "access plus 30 days"
    # CSS/JS: ۷ روز
    ExpiresByType text/css "access plus 7 days"
    ExpiresByType application/javascript "access plus 7 days"
    # فونت‌ها: ۱ سال
    ExpiresByType font/woff2 "access plus 1 year"
    ExpiresByType font/woff "access plus 1 year"
    ExpiresByType application/x-font-ttf "access plus 1 year"
    # HTML: ۱ ساعت
    ExpiresByType text/html "access plus 1 hour"
</IfModule>
BLOCK;
        $headersBlock    = $level === self::LEVEL_MINIMAL ? '' : <<<'BLOCK'

# 🛡️ هدرهای امنیتی
<IfModule mod_headers.c>
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"
</IfModule>
BLOCK;

        // 🧊 بلوک‌های محروم‌سازی — فقط در سطح full + با دوسینتکس Apache 2.2/2.4
        //    («Require all denied» در Apache 2.2 وجود ندارد و خطای 500 قطعی می‌دهد!)
        $denyBlocks = '';
        if ($level === self::LEVEL_FULL) {
            $denyBlocks = <<<'BLOCK'

# 🚫 جلوگیری از نمایش فایل‌های مخفی (شروع با نقطه) — سازگار با Apache 2.2 و 2.4
<FilesMatch "^\.">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>

# 🔒 محافظت ویژه config.php — سازگار با Apache 2.2 و 2.4
<Files "config.php">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</Files>
BLOCK;
        }

        return <<<HTACCESS
# ⚙️ تنظیمات Apache سایت برند — تولید خودکار توسط افزونه استقرار سایت ساز سهند سرویس
# =================================================================================
# برند: {$domain}
# تاریخ تولید: {$generationDate}
# ⚠️ ویرایش دستی توصیه نمی‌شود — با هر بروزرسانی بازنویسی می‌شود.
{$levelNote}# =================================================================================

{$optionsBlock}# 📌 فایل پیش‌فرض
DirectoryIndex index.php

# 🔀 URL Rewriting — آدرس‌های تمیز
<IfModule mod_rewrite.c>
    RewriteEngine On

{$redirectsBlock}{$rewriteRules}

{$sensitiveBlock}
</IfModule>
{$deflateBlock}{$expiresBlock}{$headersBlock}{$denyBlocks}

# ⛔ صفحه ۴۰۴ سفارشی
ErrorDocument 404 /404.php

HTACCESS;
    }

    /**
     * 📄 ساخت قوانین بازنویسی صفحات
     * از لیست صفحات فعال برند؛ در نبود، مجموعه استاندارد هسته
     *
     * @param array $pageTypes لیست page_type های فعال
     * @return string قوانین RewriteRule با تورفتگی
     */
    private static function buildRewriteRules(array $pageTypes): string
    {
        // مجموعه استاندارد صفحات هسته سایت برند (مطابق htaccess.template)
        $standard = [
            'services'      => 'خدمات',
            'service-area'  => 'محدوده خدمات',
            'warranty'      => 'ضمانت',
            'blog'          => 'مقالات',
            'about-agency'  => 'درباره نمایندگی',
            'about-brand'   => 'درباره برند',
            'contact'       => 'تماس',
            'request'       => 'ثبت درخواست',
            'other-brands'  => 'سایر برندها',
            'error-codes'   => 'کدهای خطا',
            'faq'           => 'سوالات متداول',
            'sitemap-page'  => 'نقشه سایت HTML',
            'terms'         => 'قوانین',
            'privacy'       => 'حریم خصوصی',
        ];

        // اگر صفحات فعال مشخص شده — فقط همان‌ها؛ وگرنه همه استاندارد
        $pages = !empty($pageTypes)
            ? array_intersect_key($standard, array_flip($pageTypes))
            : $standard;

        $rules = '';
        foreach ($pages as $slug => $label) {
            if ($slug === 'blog') {
                // مقاله تکی هم اضافه شود
                $rules .= "    # {$label}\n";
                $rules .= "    RewriteRule ^blog/?$ pages/blog.php [L]\n";
                $rules .= "    RewriteRule ^blog/article/?$ pages/article.php [L]\n";
            } else {
                $rules .= "    # {$label}\n";
                $rules .= "    RewriteRule ^{$slug}/?$ pages/{$slug}.php [L]\n";
            }
        }
        return rtrim($rules);
    }
}
