<?php
/**
 * 🔤 اندپوینت فونت — لیست فونت‌های فعال و URL های css
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

function api_fonts_list(): void
{
    $manifestFile = ASSETS_PATH . '/fonts/manifest.json';
    $manifest = file_exists($manifestFile) ? json_decode((string)file_get_contents($manifestFile), true) : [];
    json_response([
        'success' => true,
        'data'    => [
            'fonts' => $manifest['fonts'] ?? [],
            'css_url' => BASE_URL . '/assets/css/fonts.css',
            'note' => 'فایل‌های فونت از سرور سایت ساز سرو می‌شوند (بدون CDN خارجی)',
        ],
    ]);
}
