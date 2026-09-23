<?php
/**
 * 🔒 تولید تصویر CAPTCHA
 * ⚠️ سشن باید فعال باشد تا کد در آن ذخیره شود — SAHAND_NO_SESSION تعریف نمی‌شود
 * v2.7: پشتیبانی ?ajax=1 (data-URI) به عنوان لایه پشتیبان زنجیره رفرش لاگین
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

if (isset($_GET['ajax'])) {
    Auth::renderCaptchaDataUri();
}
Auth::renderCaptcha();
