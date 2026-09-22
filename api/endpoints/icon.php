<?php
/**
 * 🖼️ اندپوینت آیکون — سرو فایل SVG از پک‌ها
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

function api_serve_icon(string $pack, string $name): void
{
    // 🛡️ ضد Directory Traversal
    if (!preg_match('/^[a-z0-9\-]+$/', $pack) || !preg_match('/^[a-z0-9\-_]+$/', $name)) {
        json_response(['success' => false, 'error' => 'نام نامعتبر'], 400);
    }
    $file = ASSETS_PATH . '/icons/' . $pack . '/' . $name . '.svg';
    if (!file_exists($file)) {
        json_response(['success' => false, 'error' => 'آیکون یافت نشد'], 404);
    }
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($file);
    exit;
}
