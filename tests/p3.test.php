<?php
/**
 * 🧪 تست‌های بسته P3 «مزیت رقابتی» (v2.37)
 * =========================================
 * پوشش:
 *   ۱) WebhookDispatcher — ثابت رویدادها + رمز امضا + الگوریتم HMAC
 *      (همان چیزی که گیرنده باید راستی‌آزمایی کند)
 *   ۲) پایه چندزبانگی i18n — __t / lang_url / localized_path / hreflang
 *      (شبیه‌سازی locale=en با REQUEST_URI واقعی)
 *   ۳) منطق تقسیم واریانت A/B — parity پایدار md5 (همان کاربر = همان واریانت)
 *   ۴) انطباق فایل‌ها — sw.js / manifest.json / offline.html / htaccess / CSS
 *
 * تماماً بدون دیتابیس — در CI هم اجرا می‌شود (الگوی helpers.test.php)
 */

if (!defined('SAHAND_INIT')) { define('SAHAND_INIT', true); }
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/core/WebhookDispatcher.php';

/* ═══ ۱) WebhookDispatcher ═══ */
$T->section('۱) WebhookDispatcher — رویدادها و امضا');

$T->assert('پنج رویداد تعریف‌شده', count(WebhookDispatcher::EVENTS) === 5);
$T->assertEquals('رویداد article.published موجود', true, isset(WebhookDispatcher::EVENTS['article.published']));
$T->assertEquals('رویداد request.created موجود', true, isset(WebhookDispatcher::EVENTS['request.created']));
$T->assertEquals('رویداد form_entry.created موجود', true, isset(WebhookDispatcher::EVENTS['form_entry.created']));
$T->assertEquals('رویداد comment.created موجود', true, isset(WebhookDispatcher::EVENTS['comment.created']));
$T->assertEquals('رویداد brand.deployed موجود', true, isset(WebhookDispatcher::EVENTS['brand.deployed']));

$secret1 = WebhookDispatcher::generateSecret();
$secret2 = WebhookDispatcher::generateSecret();
$T->assert('رمز امضا ۶۴ هگز', (bool)preg_match('/^[a-f0-9]{64}$/', $secret1));
$T->assert('دو رمز متوالی متفاوت (آنتروپی)', $secret1 !== $secret2);
$T->assertEquals('سقف تلاش ۷ (۱ فوری + ۶ مجدد)', 7, WebhookDispatcher::MAX_ATTEMPTS);

/* 🧮 الگوریتم امضا — گیرنده دقیقاً همین را بازتولید می‌کند:
   hash_hmac('sha256', rawBody, secret) با پیشوند sha256= در هدر */
$body = json_encode(['event' => 'comment.created', 'created_at' => '2026-01-01T10:00:00+03:30', 'data' => ['id' => 42]], JSON_UNESCAPED_UNICODE);
$sig = hash_hmac('sha256', $body, $secret1);
$T->assert('امضای HMAC-SHA256 بدنه ۶۴ هگز', (bool)preg_match('/^[a-f0-9]{64}$/', $sig));
$T->assertEquals('امضا با رمز دیگر فرق دارد', false, hash_equals($sig, hash_hmac('sha256', $body, $secret2)));
$T->assertEquals('امضا با بدنه دیگر فرق دارد', false, hash_equals($sig, hash_hmac('sha256', $body . ' ', $secret1)));
/* بردار شناخته‌شده RFC 4231 — پایداری الگوریتم در زمان ارتقا */
$T->assertEquals(
    'بردار مرجع HMAC-SHA256 (RFC 4231 Case 1)',
    'b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7',
    hash_hmac('sha256', 'Hi There', str_repeat("\x0b", 20))
);

/* ═══ ۲) پایه چندزبانگی i18n ═══ */
$T->section('۲) i18n — ترجمه و لینک‌های زبانی');

/* شبیه‌سازی حالت انگلیسی: ?lang=en قبل از بارگذاری لایه */
$_GET['lang'] = 'en';
$_SERVER['REQUEST_URI'] = '/en/blog/washing-machine-error-e21';
define('BRAND_DOMAIN_TEST_HOST', true);
/* i18n.php به BRAND_DOMAIN و e() نیاز دارد — تعریف حداقلی */
if (!defined('BRAND_DOMAIN')) { define('BRAND_DOMAIN', 'test.example'); }
if (!defined('BRAND_INIT')) { define('BRAND_INIT', true); }
require_once dirname(__DIR__) . '/templates/brand-core/includes/i18n.php';

$T->assertEquals('locale تشخیص داده شد: en', 'en', BRAND_LANG);
$T->assertEquals('جهت صفحه در حالت انگلیسی: ltr', 'ltr', BRAND_DIR);
$T->assertEquals('html lang در حالت انگلیسی', 'en', BRAND_HTML_LANG);
$T->assertEquals('__t: دکمه CTA انگلیسی', 'Request Online Repair Service', __t('hero_cta'));
$T->assertEquals('__t: کلید ناشناخته = خود کلید (شکستن صفحه ممنوع)', 'unknown_key_xyz', __t('unknown_key_xyz'));

$T->assertEquals('lang_url: en→fa روی مسیر en', '/blog/washing-machine-error-e21', lang_url('fa', '/en/blog/washing-machine-error-e21'));
$T->assertEquals('lang_url: fa→en روی مسیر ساده', '/en/blog/x', lang_url('en', '/blog/x'));
$T->assertEquals('lang_url: en→en بدون تغییر', '/en/blog/x', lang_url('en', '/en/blog/x'));
$T->assertEquals('lang_url: fa→fa بدون تغییر', '/blog/x', lang_url('fa', '/blog/x'));
$T->assertEquals('lang_url: ریشه fa→en', '/en/', lang_url('en', '/'));
$T->assertEquals('lang_url: کوئری lang= قدیمی حذف می‌شود', '/en/blog/x?page=2', lang_url('en', '/blog/x?page=2&lang=fa'));
$T->assertEquals('lang_url: کوئری بدون lang حفظ می‌شود', '/en/blog/x?a=1&b=2', lang_url('en', '/blog/x?a=1&b=2'));
$T->assertEquals('localized_path در حالت en', '/en/blog', localized_path('/blog'));
$T->assertEquals('localized_path مسیر خودش‌محلی دست‌نخورده', '/en/blog', localized_path('/en/blog'));

$tags = i18n_hreflang_tags();
$T->assert('hreflang: تگ fa موجود', strpos($tags, 'hreflang="fa"') !== false);
$T->assert('hreflang: تگ en موجود', strpos($tags, 'hreflang="en"') !== false);
$T->assert('hreflang: x-default موجود', strpos($tags, 'hreflang="x-default"') !== false);
$T->assert('hreflang: مسیر en داخل تگ', strpos($tags, 'https://test.example/en/blog') !== false);

/* ═══ ۳) منطق تقسیم A/B ═══ */
$T->section('۳) A/B — تقسیم پایدار بازدیدکننده');

$abAssign = static function (string $visitor, int $testId): string {
    return (hexdec(substr(md5($visitor . '|' . $testId), -1)) % 2 === 0) ? 'A' : 'B';
};
$T->assertEquals('تخصیص فقط A یا B', true, in_array($abAssign('abc123', 1), ['A', 'B'], true));
/* پایداری — همان ورودی همیشه همان خروجی (۱۰۰ نمونه یکجا) */
$stable = true;
for ($i = 0; $i < 100; $i++) {
    $v = md5('visitor-' . $i);
    if ($abAssign($v, 7) !== $abAssign($v, 7)) { $stable = false; break; }
}
$T->assert('پایداری تخصیص (۱۰۰ بازدیدکننده × ۲ محاسبه)', $stable);
$aCount = 0;
for ($i = 0; $i < 400; $i++) {
    if ($abAssign(md5('visitor-' . $i), 7) === 'A') { $aCount++; }
}
$T->assert('توزیع تقریباً ۵۰/۵۰ (' . $aCount . ' از ۴۰۰ = A)', $aCount > 140 && $aCount < 260);
/* تخصیص بین دو تست مستقل — آماره باید تقریباً نصف نمونه‌ها فرق کند */
$diff = 0;
for ($i = 0; $i < 100; $i++) {
    $v = md5('visitor-' . $i);
    if ($abAssign($v, 1) !== $abAssign($v, 2)) { $diff++; }
}
$T->assert('تست مستقل → تخصیص مستقل (' . $diff . ' از ۱۰۰ متفاوت)', $diff > 20 && $diff < 80);

/* ═══ ۴) انطباق فایل‌های P3 ═══ */
$T->section('۴) انطباق فایل‌ها — PWA و قالب');

$bc = dirname(__DIR__) . '/templates/brand-core';
$T->assert('sw.js موجود است', is_file($bc . '/sw.js'));
$T->assert('offline.html موجود است', is_file($bc . '/offline.html'));
$T->assert('i18n.php موجود است', is_file($bc . '/includes/i18n.php'));

$sw = (string)@file_get_contents($bc . '/sw.js');
$T->assert('SW: pre-cache صفحه آفلاین', strpos($sw, '/offline.html') !== false);
$T->assert('SW: چرخه عمر cache version', strpos($sw, 'CACHE_VERSION') !== false);
$T->assert('SW: network-first برای صفحات', strpos($sw, 'navigate') !== false);
$T->assert('SW: فقط GET هم‌مبدأ کش می‌شود', strpos($sw, "request.method !== 'GET'") !== false);

$offline = (string)@file_get_contents($bc . '/offline.html');
$T->assert('صفحه آفلاین: جای‌نگهدار برند', strpos($offline, '{{BRAND_NAME_FA}}') !== false);
$T->assert('صفحه آفلاین: noindex', strpos($offline, 'noindex') !== false);

$manifest = json_decode((string)@file_get_contents($bc . '/manifest.json'), true);
$T->assert('مانیفست: JSON معتبر', is_array($manifest));
if (is_array($manifest)) {
    $T->assert('مانیفست: id + scope', isset($manifest['id'], $manifest['scope']));
    $T->assert('مانیفست: آیکون maskable', in_array('maskable', array_column($manifest['icons'], 'purpose'), true) || strpos(json_encode($manifest['icons']), 'maskable') !== false);
    $T->assert('مانیفست: shortcuts نصب', count($manifest['shortcuts'] ?? []) >= 2);
    $T->assertEquals('مانیفست: زبان فارسی', 'fa', $manifest['lang'] ?? '');
}

$ht = (string)@file_get_contents($bc . '/htaccess.template');
$T->assert('htaccess: مسیر /en/ چندزبانه', strpos($ht, 'RewriteRule ^(en)/') !== false);
$T->assert('htaccess: no-cache برای sw.js', strpos($ht, 'sw\.js') !== false);

$css = (string)@file_get_contents($bc . '/css/style.css');
$T->assert('CSS: استایل دیدگاه‌ها', strpos($css, '.comment-form') !== false);
$T->assert('CSS: استایل سوییچر زبان', strpos($css, '.lang-switch') !== false);

$articlePage = (string)@file_get_contents($bc . '/pages/article.php');
$T->assert('صفحه مقاله: فرم دیدگاه', strpos($articlePage, 'commentForm') !== false);
$T->assert('صفحه مقاله: honeypot ضداسپم', strpos($articlePage, 'website') !== false);

$indexPage = (string)@file_get_contents($bc . '/index.php');
$T->assert('صفحه اصلی: موتور واریانت A/B', strpos($indexPage, 'ab-active') !== false);
$T->assert('صفحه اصلی: بیکِن sendBeacon', strpos($indexPage, 'sendBeacon') !== false);

$footer = (string)@file_get_contents($bc . '/includes/footer.php');
$T->assert('فوتر: ثبت Service Worker', strpos($footer, 'serviceWorker') !== false);

$header = (string)@file_get_contents($bc . '/includes/header.php');
$T->assert('هدر: سوییچر زبان', strpos($header, 'lang-switch') !== false);
$T->assert('هدر: مانیفست PWA لینک شده', strpos($header, 'manifest.json') !== false);
$T->assert('هدر: theme-color', strpos($header, 'theme-color') !== false);

/* صفحات پنل */
$admin = dirname(__DIR__) . '/admin';
$T->assert('پنل: comments.php موجود', is_file($admin . '/comments.php'));
$T->assert('پنل: webhooks.php موجود', is_file($admin . '/webhooks.php'));
$T->assert('پنل: ab-tests.php موجود', is_file($admin . '/ab-tests.php'));
$adminHeader = (string)@file_get_contents($admin . '/includes/header.php');
$T->assert('منوی پنل: لینک دیدگاه‌ها', strpos($adminHeader, 'comments.php') !== false);
$T->assert('منوی پنل: لینک تست A/B', strpos($adminHeader, 'ab-tests.php') !== false);
$T->assert('منوی پنل: لینک وب‌هوک‌ها', strpos($adminHeader, 'webhooks.php') !== false);

/* اندپوینت‌های API */
$api = dirname(__DIR__) . '/api/endpoints';
$T->assert('API: comment.php موجود', is_file($api . '/comment.php'));
$T->assert('API: ab-test.php موجود', is_file($api . '/ab-test.php'));
$commentEp = (string)@file_get_contents($api . '/comment.php');
$T->assert('اندپوینت دیدگاه: محدودیت نرخ', strpos($commentEp, 'INTERVAL 1 HOUR') !== false);
$T->assert('اندپوینت دیدگاه: ثبت pending (تأییدیه‌ای)', strpos($commentEp, "'pending'") !== false);
$routerFile = (string)@file_get_contents(dirname(__DIR__) . '/api/index.php');
$T->assert('روتر: مسیر comment', strpos($routerFile, '/comment') !== false);
$T->assert('روتر: مسیر ab-event', strpos($routerFile, 'ab-event') !== false);
$T->assert('روتر: مسیر article-comments', strpos($routerFile, 'article-comments') !== false);
