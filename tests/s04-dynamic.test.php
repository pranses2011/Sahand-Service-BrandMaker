<?php
/**
 * 🧪 تست S04 v2.44 — پویاسازی بلوک‌های قالب‌ساز از تنظیمات سایت‌ساز
 * ================================================================
 * شبیه‌سازی fetchFromAPI با داده نمایندگی تبریز (بدون دیتابیس) و
 * بررسی اینکه فالبک‌های ایستا جای خود را به داده واقعی می‌دهند.
 * الگوی بدون DB — مطابق p3.test.php (در CI هم اجرا می‌شود).
 */

if (!defined('SAHAND_INIT')) { define('SAHAND_INIT', true); }
require_once dirname(__DIR__) . '/includes/helpers.php';

/* 🎭 ثابت‌های سایت برند + شبیه‌ساز API سایت‌ساز */
if (!defined('BRAND_INIT')) { define('BRAND_INIT', true); }
if (!defined('BRAND_ID')) { define('BRAND_ID', 7); }
if (!defined('BRAND_NAME_FA')) { define('BRAND_NAME_FA', 'سرویس تبریز'); }
if (!defined('BRAND_CITY')) { define('BRAND_CITY', 'تبریز'); }
if (!defined('BRAND_LANG')) { define('BRAND_LANG', 'fa'); }

$GLOBALS['s04FakeApi'] = [
    'settings' => ['data' => [
        'agency' => ['name_fa' => 'سَحَند سرویس', 'slogan_fa' => 'اعتماد شما، افتخار ما'],
        'contacts' => ['phones' => ['phone' => ['04133334455'], 'mobile' => ['09141112233'], 'whatsapp' => '09141112233']],
        'emails' => [['email' => 'info@sahand-tabriz.ir']],
        'addresses' => [
            ['city' => 'تبریز', 'address' => 'چهارراه ابوریحان، ساختمان آذربایجان', 'lat' => '38.08', 'lng' => '46.29'],
            ['city' => 'مراغه', 'address' => 'خیابان اشتیخان', 'map_url' => 'https://maps.google.com/?q=maragheh'],
        ],
        'socials' => [
            ['name' => 'تلگرام', 'url' => 'https://t.me/sahandtab'],
            ['name' => 'اینستاگرام', 'url' => 'https://insta.com/sahandtab'],
            ['name' => 'واتساپ', 'url' => 'https://wa.me/989141111223'],
        ],
        'work_hours' => ['days' => ['sat', 'sun', 'mon', 'tue', 'wed'], 'start' => '08:30', 'end' => '20:00'],
        'warranty' => ['default_period' => '۱۲ ماه', 'text' => '<p>گارانتی طلایی روی همه قطعات تعویضی</p>'],
    ]],
];
if (!function_exists('fetchFromAPI')) {
    function fetchFromAPI(string $ep, int $ttl = 300): array
    {
        $known = ['settings', 'brands', 'brand/7/latest-comments?limit=9', 'brand/7/faqs', 'brand/7/articles?per_page=6', 'brand/7/articles?per_page=30', 'brand/7/error-codes'];
        if (in_array($ep, $known, true)) {
            return $GLOBALS['s04FakeApi'][$ep] ?? ['data' => []];
        }
        return ['data' => []];
    }
}
if (!function_exists('cdn_asset')) {
    function cdn_asset(string $p): string { return '/' . $p; }
}

require_once dirname(__DIR__) . '/templates/brand-core/includes/block-renderer-core.php';
pv_renderer_init(['mode' => 'site']);

$T->section('S04-۱) توابع پویای جدید');

$T->assert('pv_dyn_socials: سه شبکه با آیکون درست', count(pv_dyn_socials()) === 3 && pv_dyn_socials()[0]['icon'] === '📡' && pv_dyn_socials()[2]['link'] === 'https://wa.me/989141111223');
$T->assert('pv_dyn_email: ایمیل واقعی', pv_dyn_email() === 'info@sahand-tabriz.ir');
$T->assert('pv_dyn_warranty: دوره ۱۲ ماه', pv_dyn_warranty()['period'] === '۱۲ ماه');
$T->assert('pv_dyn_city: تبریز', pv_dyn_city() === 'تبریز');
$T->assert('pv_dyn_areas: شهرها + محله‌ها', count(pv_dyn_areas()) === 4 && pv_dyn_areas()[0] === 'کل تبریز' && in_array('کل مراغه', pv_dyn_areas(), true));
$T->assert('pv_brand_logo_html: نام برند واقعی', str_contains(pv_brand_logo_html(), 'سرویس تبریز'));
$T->assert('pv_dyn_brand_title: خدمات سرویس تبریز', pv_dyn_brand_title('x') === 'خدمات سرویس تبریز');

$T->section('S04-۲) بلوک‌های پویاشده');

$h = pv_render_block('top-bar', []);
$T->assert('top-bar: تلفن تبریز', str_contains($h, '04133334455'));
$h = pv_render_block('header-v1', []);
$T->assert('header-v1: نام برند در لوگو', str_contains($h, 'سرویس تبریز'));
$h = pv_render_block('hero', []);
$T->assert('hero: عنوان برنددار', str_contains($h, 'خدمات سرویس تبریز'));
$T->assert('hero: شعار نمایندگی', str_contains($h, 'اعتماد شما، افتخار ما'));
$h = pv_render_block('social-follow', []);
$T->assert('social-follow: لینک تلگرام واقعی + تگ a', str_contains($h, 'https://t.me/sahandtab') && str_contains($h, '<a href="https://t.me'));
$h = pv_render_block('area-list', []);
$T->assert('area-list: کل تبریز نه محله‌های تهران', str_contains($h, 'کل تبریز') && !str_contains($h, 'سعادت‌آباد') && !str_contains($h, 'ولنجک'));
$h = pv_render_block('cta-whatsapp', []);
$T->assert('cta-whatsapp: شماره از تنظیمات + لینک wa.me', str_contains($h, '09141112233') && str_contains($h, 'https://wa.me/09141112233'));
$h = pv_render_block('warranty-banner', []);
$T->assert('warranty-banner: دوره + متن ضمانت از تنظیمات', str_contains($h, '۱۲ ماه') && str_contains($h, 'گارانتی طلایی'));
$h = pv_render_block('contact-info-bar', []);
$T->assert('contact-info-bar: آدرس تبریز نه تهرانِ ایستا', str_contains($h, 'تبریز') && !str_contains($h, '>تهران<'));
$h = pv_render_block('footer-contact', []);
$T->assert('footer-contact: ایمیل + نام برند', str_contains($h, 'info@sahand-tabriz.ir') && str_contains($h, 'سرویس تبریز'));
$h = pv_render_block('footer-simple', []);
$T->assert('footer-simple: سوشال واقعی + تلفن', str_contains($h, 'تلگرام') && str_contains($h, '04133334455'));
$h = pv_render_block('copyright', []);
$T->assert('copyright: نام برند', str_contains($h, 'سرویس تبریز'));
$h = pv_render_block('contact-map-split', []);
$T->assert('contact-map-split: آدرس واقعی + نقشه', str_contains($h, 'چهارراه ابوریحان') && str_contains($h, 'maps.google.com'));
$h = pv_render_block('contact-cards', []);
$T->assert('contact-cards: کارت ایمیل', str_contains($h, 'mailto:info@sahand-tabriz.ir'));
$h = pv_render_block('stats-grid', []);
$T->assert('stats-grid: عنوان برنددار', str_contains($h, 'سرویس تبریز در یک نگاه'));
$h = pv_render_block('brand-intro-card', []);
$T->assert('brand-intro-card: نام برند + شعار', str_contains($h, 'سرویس تبریز') && str_contains($h, 'اعتماد شما'));
$h = pv_render_block('location-cards', []);
$T->assert('location-cards: هر دو شعبه واقعی', str_contains($h, 'مراغه') && str_contains($h, 'اشتیخان'));

$T->section('S04-۳) اولویت props کاربر بر داده پویا');

$h = pv_render_block('social-follow', ['items' => [['icon' => '📌', 'text' => 'کانال اختصاصی', 'desc' => '', 'link' => 'https://x.ir', 'color' => '']]]);
$T->assert('آیتم دستی مقدم بر داده پویا', str_contains($h, 'کانال اختصاصی') && !str_contains($h, 't.me'));
$h = pv_render_block('area-list', ['items' => [['icon' => '', 'text' => 'منطقه الف', 'desc' => '', 'link' => '', 'color' => '']]]);
$T->assert('نواحی دستی مقدم بر آدرس‌های سایت‌ساز', str_contains($h, 'منطقه الف') && !str_contains($h, 'کل تبریز'));
