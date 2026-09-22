<?php
/**
 * 🌐 میان‌افزار CORS
 * ===================
 * دسترسی سایت‌های برند (هر دامنه‌ای از دامنه‌های مجاز)
 *
 * @package SahandBrandMaker
 */

if (!defined('SAHAND_INIT')) {
    http_response_code(403);
    exit;
}

/**
 * 🌐 اعمال هدرهای CORS
 */
function api_cors(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
    header('Access-Control-Max-Age: 86400');
}
