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
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class HtaccessGenerator
{
    /**
     * ⚙️ تولید محتوای .htaccess سایت برند
     *
     * @param string $fullDomain دامنه کامل (برای ریدایرکت www و HTTPS)
     * @param array  $pageTypes لیست اسلاگ صفحات فعال برند (اختیاری)
     * @return string محتوای .htaccess
     */
    public static function generate(string $fullDomain, array $pageTypes = []): string
    {
        $domain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', strtolower($fullDomain));
        $wwwDomain = 'www.' . $domain;

        // 📄 قوانین بازنویسی صفحات — از صفحات فعال برند یا مجموعه استاندارد
        $rewriteRules = self::buildRewriteRules($pageTypes);

        // 📅 تاریخ تولید برای سربرگ (شمسی)
        $generationDate = ShamsiDate::forDisplay();

        return <<<HTACCESS
# ⚙️ تنظیمات Apache سایت برند — تولید خودکار توسط افزونه استقرار سایت ساز سهند سرویس
# =================================================================================
# برند: {$domain}
# تاریخ تولید: {$generationDate}
# ⚠️ ویرایش دستی توصیه نمی‌شود — با هر بروزرسانی بازنویسی می‌شود.
# =================================================================================

# 🚫 غیرفعال‌سازی فهرست پوشه‌ها
Options -Indexes

# 📌 فایل پیش‌فرض
DirectoryIndex index.php

# 🔀 URL Rewriting — آدرس‌های تمیز
<IfModule mod_rewrite.c>
    RewriteEngine On

    # 🔒 ریدایرکت HTTP → HTTPS
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://{$domain}/$1 [R=301,L]

    # 🌐 ریدایرکت www → non-www
    RewriteCond %{HTTP_HOST} ^{$wwwDomain}$ [NC]
    RewriteRule ^(.*)$ https://{$domain}/$1 [R=301,L]

{$rewriteRules}

    # 🚫 جلوگیری از دسترسی مستقیم به فایل‌های حساس
    RewriteRule ^config\.php$ - [F,L]
    RewriteRule ^\.htaccess$ - [F,L]
    RewriteRule ^includes/ - [F,L]
    RewriteRule ^cache/ - [F,L]
    RewriteRule ^\.env - [F,L]
</IfModule>

# 📦 فشرده‌سازی Gzip — کاهش حجم انتقال
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript
    AddOutputFilterByType DEFLATE application/javascript application/json application/xml
    AddOutputFilterByType DEFLATE image/svg+xml
</IfModule>

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

# 🛡️ هدرهای امنیتی
<IfModule mod_headers.c>
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"
</IfModule>

# 🚫 جلوگیری از نمایش فایل‌های مخفی (شروع با نقطه)
<FilesMatch "^\.">
    Require all denied
</FilesMatch>

# 🔒 محافظت ویژه config.php
<Files "config.php">
    Require all denied
</Files>

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
