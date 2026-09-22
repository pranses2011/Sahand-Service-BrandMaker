<?php
/**
 * 🔒 تولید تصویر CAPTCHA
 * ⚠️ سشن باید فعال باشد تا کد در آن ذخیره شود — SAHAND_NO_SESSION تعریف نمی‌شود
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';
Auth::renderCaptcha();
