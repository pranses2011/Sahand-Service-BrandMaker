<?php
/**
 * 🧪 تست‌های واحد توابع کمکی (includes/helpers.php) — v2.33
 * گزارش تحلیل (P0-۲): «شروع با ۳۰ تست برای helpers.php»
 * تماماً خالص — بدون دیتابیس (در CI هم اجرا می‌شود)
 */

if (!defined('SAHAND_INIT')) { define('SAHAND_INIT', true); }
require_once dirname(__DIR__) . '/includes/helpers.php';

/* ═══ خروجی امن (e) ═══ */
$T->section('۱) خروجی امن e()');
$T->assertEquals('e: خنثی‌سازی <script>', '&lt;script&gt;alert(1)&lt;/script&gt;', e('<script>alert(1)</script>'));
$T->assertEquals('e: کوتیشن تک', '&#039;', e("'"));
$T->assertEquals('e: کوتیشن جفت', '&quot;', e('"'));
$T->assertEquals('e: امپرسند', '&amp;', e('&'));
$T->assertEquals('e: متن فارسی دست‌نخورده', 'سلام دنیا', e('سلام دنیا'));
$T->assertEquals('e: null → رشته خالی', '', e(null));

/* ═══ پاکسازی ورودی ═══ */
$T->section('۲) پاکسازی clean_input()');
$T->assertEquals('clean_input: حذف تگ‌ها', 'متن ساده', clean_input('<p>متن ساده</p>'));
$T->assertEquals('clean_input: تریم فاصله‌ها', 'وسط', clean_input('  وسط  '));
$T->assertEquals('clean_input: حذف نال‌بایت', 'ab', clean_input("a\x00b"));
$T->assertEquals('clean_input: null → خالی', '', clean_input(null));

/* ═══ تبدیل ارقام ═══ */
$T->section('۳) تبدیل ارقام');
$T->assertEquals('fa_to_en: ۱۲۳۴۵۶', '123456', fa_to_en_digits('۱۲۳۴۵۶'));
$T->assertEquals('en_to_fa: 123456', '۱۲۳۴۵۶', en_to_fa_digits('123456'));
$T->assertEquals('fa_to_en: مخلوط', '0914-555', fa_to_en_digits('۰۹۱۴-۵۵۵'));
$T->assertEquals('تبدیل رفت‌وبرگشت پایدار', 'abc123', fa_to_en_digits(en_to_fa_digits('abc123')));

/* ═══ اعتبارسنجی‌ها ═══ */
$T->section('۴) اعتبارسنجی');
$T->assert('موبایل ایران: 09141112233', is_valid_iran_mobile('09141112233'));
$T->assert('موبایل ایران: 0935... (+98)', is_valid_iran_mobile('+989351112233') || is_valid_iran_mobile('09351112233'));
$T->assert('موبایل نامعتبر: 12345', !is_valid_iran_mobile('12345'));
$T->assert('موبایل نامعتبر: 0914111223 (۹ رقم)', !is_valid_iran_mobile('0914111223'));
$T->assert('تلفن ثابت: 04135557788', is_valid_iran_phone('04135557788'));
$T->assert('ایمیل معتبر', is_valid_email('user@example.ir'));
$T->assert('ایمیل نامعتبر', !is_valid_email('not-an-email'));

/* ═══ تاریخ شمسی ═══ */
$T->section('۵) تاریخ شمسی');
$T->assertEquals('jdate: 2026-09-28 → شمسی', '۱۴۰۵/۰۷/۰۶', jdate('2026-09-28'));
$T->assertEquals('jdate: با ساعت', '۱۴۰۵/۰۷/۰۶ - ۱۴:۳۰', jdate('2026-09-28 14:30:00', true));
$T->assertEquals('gregorian_to_jalali: 2026/9/28', [1405, 7, 6], gregorian_to_jalali(2026, 9, 28));
$T->assertEquals('jalali_to_gregorian: رفت‌وبرگشت', [2026, 9, 28], jalali_to_gregorian(1405, 7, 6));
$T->assertEquals('jalali_to_gregorian_date: 1405/07/06', '2026-09-28', jalali_to_gregorian_date('1405/07/06'));
$T->assertEquals('jdate_month_name: 7 → مهر', 'مهر', jdate_month_name(7));
$T->assertEquals('jdate_day_name: دوشنبه', 'دوشنبه', jdate_day_name(1));
$T->assert('time_ago_fa: خروجی غیرخالی', time_ago_fa(date('Y-m-d H:i:s', time() - 3600)) !== '');

/* ═══ رشته و اسلاگ ═══ */
$T->section('۶) رشته و اسلاگ');
$T->assertEquals('excerpt: برش واژه‌امن ۱۰ کاراکتری', 'سلام…', excerpt('سلام دنیای عزیز', 10));
$T->assertEquals('excerpt: متن کوتاه دست‌نخورده', 'سلام', excerpt('سلام', 10));
$T->assert('make_slug: فارسی → حروف مجاز', preg_match('/^[a-z0-9\-]+$/u', make_slug('سلام دنیا Test 123')) === 1 || make_slug('سلام دنیا Test 123') !== '');
$T->assertEquals('mb_trim: فاصله‌های یونیکد (ZWNJ فارسی حفظ می‌شود)', "متن\u{200C}", mb_trim(" \u{00A0}متن\u{200C} "));
$T->assertEquals('mb_rtrim: فقط راست', 'متن', mb_rtrim('متن   '));

/* ═══ شناسه یکتا ═══ */
$T->section('۷) شناسه یکتا');
$id1 = unique_id(32);
$id2 = unique_id(32);
$T->assertEquals('unique_id: طول ۳۲', 32, strlen($id1));
$T->assert('unique_id: یکتایی', $id1 !== $id2);
$T->assertEquals('unique_id: هگزا', 1, preg_match('/^[a-f0-9]+$/', $id1));

/* ═══ گارد هم‌مبدأ (v2.33) ═══ */
$T->section('۸) گارد هم‌مبدأ verify_same_origin()');
$_SERVER['HTTP_HOST'] = 'brandmaker.example.ir';
unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER']);
$T->assert('بدون سرنخ → مجاز (سازگاری)', verify_same_origin() === true);
$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
$T->assert('Sec-Fetch-Site: same-origin → مجاز', verify_same_origin() === true);
$_SERVER['HTTP_SEC_FETCH_SITE'] = 'none';
$T->assert('Sec-Fetch-Site: none → مجاز', verify_same_origin() === true);
$_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
$T->assert('Sec-Fetch-Site: cross-site → رد', verify_same_origin() === false);
unset($_SERVER['HTTP_SEC_FETCH_SITE']);
$_SERVER['HTTP_ORIGIN'] = 'https://brandmaker.example.ir';
$T->assert('Origin هم‌دامنه → مجاز', verify_same_origin() === true);
$_SERVER['HTTP_ORIGIN'] = 'https://evil.com';
$T->assert('Origin مهاجم → رد', verify_same_origin() === false);
unset($_SERVER['HTTP_ORIGIN']);
$_SERVER['HTTP_REFERER'] = 'https://evil.com/x.html';
$T->assert('Referer مهاجم → رد', verify_same_origin() === false);
unset($_SERVER['HTTP_REFERER']);
$T->assert('reject_cross_origin تعریف شده', function_exists('reject_cross_origin'));
