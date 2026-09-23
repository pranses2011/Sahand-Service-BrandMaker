<?php
/**
 * 🔒 تولید تصویر کد امنیتی — اندپوینت با نام خنثی
 * =================================================
 * ⚠️ چرا این فایل با نام «security-code» ساخته شد؟
 *    افزونه‌های مسدودکننده تبلیغ در مرورگرهای دسکتاپ (uBlock Origin، AdGuard و...)
 *    درخواست‌های تصویری که آدرسشان شامل واژه «captcha» است را بلاک می‌کنند —
 *    به همین دلیل کپچا روی لب‌تاپ (دارای افزونه) نمایش داده نمی‌شود ولی روی
 *    گوشی (بدون افزونه) سالم است. این اندپوینت همان خروجی را با نامی خنثی می‌دهد.
 *
 * حالت‌ها:
 *   security-code.php          → خروجی تصویر PNG/SVG (مثل captcha.php)
 *   security-code.php?ajax=1   → خروجی JSON با data-URI (ضد بلاک کامل تصویر)
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

if (isset($_GET['ajax'])) {
    Auth::renderCaptchaDataUri();
}
Auth::renderCaptcha();
