<?php
/**
 * 🧪 تست S09 v2.44 — تکمیل تنظیمات عناصر (متغیرهای عمومی جدید + لنگر)
 * بدون دیتابیس — الگوی p3.test.php
 */

if (!defined('SAHAND_INIT')) { define('SAHAND_INIT', true); }
require_once dirname(__DIR__) . '/includes/helpers.php';

if (!defined('BRAND_INIT')) { define('BRAND_INIT', true); }
if (!defined('BRAND_ID')) { define('BRAND_ID', 7); }
if (!defined('BRAND_NAME_FA')) { define('BRAND_NAME_FA', 'سرویس تبریز'); }
if (!defined('BRAND_LANG')) { define('BRAND_LANG', 'fa'); }
if (!function_exists('fetchFromAPI')) {
    function fetchFromAPI(string $ep, int $ttl = 300): array { return ['data' => []]; }
}
if (!function_exists('cdn_asset')) {
    function cdn_asset(string $p): string { return '/' . $p; }
}

require_once dirname(__DIR__) . '/templates/brand-core/includes/block-renderer-core.php';

$T->section('S09-۱) سایه اختصاصی بخش');
$h = pv_render_block('text', ['text' => 'سلام', 'boxShadow' => 'strong']);
$T->assert('سایه قوی: متغیر --blk-shadow', str_contains($h, '--blk-shadow:0 14px 36px'));
$h = pv_render_block('text', ['text' => 'سلام', 'boxShadow' => 'none']);
$T->assert('بدون سایه: مقدار none', str_contains($h, '--blk-shadow:none'));

$T->section('S09-۲) قاب دور بخش (بوردر)');
$h = pv_render_block('text', ['text' => 'سلام', 'borderStyle' => 'dashed', 'borderColor' => '#f59e0b']);
$T->assert('بوردر خط‌چین با رنگ دلخواه', str_contains($h, '--blk-bd:1.5px dashed #f59e0b'));
$h = pv_render_block('text', ['text' => 'سلام', 'borderStyle' => 'thick']);
$T->assert('بوردر ضخیم با رنگ پیش‌فرض', str_contains($h, '--blk-bd:2.5px solid #cbd5e1'));
$h = pv_render_block('text', ['text' => 'سلام', 'borderStyle' => '']);
$T->assert('بدون بوردر: متغیر ندارد', !str_contains($h, '--blk-bd'));

$T->section('S09-۳) تصویر زمینه بخش');
$h = pv_render_block('text', ['text' => 'سلام', 'bgImage' => 'https://example.com/pat.jpg']);
$T->assert('تصویر زمینه بخش: متغیر --blk-bgi', str_contains($h, '--blk-bgi:url(\'https://example.com/pat.jpg\')'));
$h = pv_render_block('text', ['text' => 'سلام', 'bgImage' => 'javascript:alert(1)']);
$T->assert('آدرس خطرناک رد می‌شود', !str_contains($h, '--blk-bgi'));

$T->section('S09-۴) رنگ و اندازه آیکون‌های بخش');
$h = pv_render_block('feature-list', ['items' => [['icon' => '⚡', 'text' => 'تست', 'desc' => '', 'link' => '', 'color' => '']], 'iconColor' => '#0f766e']);
$T->assert('رنگ آیکون: متغیر --blk-ic', str_contains($h, '--blk-ic:#0f766e'));
$h = pv_render_block('feature-list', ['items' => [['icon' => '⚡', 'text' => 'تست', 'desc' => '', 'link' => '', 'color' => '']], 'iconSize' => 'xl']);
$T->assert('اندازه آیکون خیلی بزرگ: 1.75', str_contains($h, '--blk-ics:1.75'));
$h = pv_render_block('feature-list', ['items' => [['icon' => '⚡', 'text' => 'تست', 'desc' => '', 'link' => '', 'color' => '']], 'iconSize' => 'sm']);
$T->assert('اندازه آیکون کوچک: 0.78', str_contains($h, '--blk-ics:.78'));

$T->section('S09-۵) شناسه لنگری بخش');
$h = pv_render_block('text', ['text' => 'سلام', 'anchorId' => 'services']);
$T->assert('شناسه لنگری تزریق شد', str_contains($h, 'id="services"') && str_contains($h, 'data-anchor="1"'));
$h = pv_render_block('text', ['text' => 'سلام', 'anchorId' => 'bad id!<script>']);
$T->assert('کاراکترهای خطرناک از شناسه حذف می‌شوند', !str_contains($h, '<script>') && (str_contains($h, 'id="badidscript"') || !preg_match('/id="[^"]*[^a-zA-Z0-9\-_"][^"]*"/', $h)));

$T->section('S09-۶) ترکیب با تنظیمات موجود');
$h = pv_render_block('text', ['text' => 'سلام', 'boxShadow' => 'soft', 'borderStyle' => 'thin', 'borderColor' => '#0ea5e9', 'titleColor' => '#7c3aed', 'mt' => '20']);
$T->assert('همزیستی متغیرهای قدیم و جدید', str_contains($h, '--blk-shadow:0 3px 12px') && str_contains($h, '--blk-bd:1.5px solid #0ea5e9') && str_contains($h, '--blk-tc:#7c3aed') && str_contains($h, '--blk-mt:20px'));
