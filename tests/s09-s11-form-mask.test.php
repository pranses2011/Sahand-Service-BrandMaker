<?php
/**
 * 🧪 تست S09a + S11 (v2.45) — عنصر فرم سفارشی + ماسک/فرمت فیلدها
 * =================================================================
 * ① ریشه «فرم‌ها در قالب‌ساز دیده نمی‌شوند»: listAll باید فرم‌های
 *    اختصاصی برند را هم برگرداند (listActive بدون پارامتر فقط سراسری)
 * ② ماسک: اعتبارسنجی سرور (کد ملی/موبایل/تلفن/کارت Luhn/شبا/کد پستی/دلخواه)
 * ③ رندر: فیلد ماسک‌دار باید data-mask/data-mask-pattern بگیرد
 * ④ sanitize: ماسک فقط برای فیلدهای متنی تک‌خطی ذخیره می‌شود
 *
 * اجرا: php tests/run.php --filter=s09-form-mask (نیازمند MariaDB برای بخش ①)
 */

if (!defined('SAHAND_INIT')) { define('SAHAND_INIT', true); }
require_once dirname(__DIR__) . '/includes/helpers.php';

/* 🎭 ثابت‌های سایت برند */
if (!defined('BRAND_INIT')) { define('BRAND_INIT', true); }
if (!defined('BRAND_ID')) { define('BRAND_ID', 7); }
if (!defined('BRAND_NAME_FA')) { define('BRAND_NAME_FA', 'سرویس تست'); }
if (!defined('BRAND_CITY')) { define('BRAND_CITY', 'تبریز'); }

/* 🗄 دیتابیس شبیه‌سازی برای بخش ① (اگر محیط آماده نباشد رد می‌شود) */
$dbLocal = dirname(__DIR__) . '/config.local.php';
if (is_file($dbLocal)) { require_once $dbLocal; }
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/CustomFormManager.php';
require_once dirname(__DIR__) . '/templates/brand-core/includes/blocks.php';

/** @var TestRunner $T */
$T = $GLOBALS['T'] ?? null;
if (!$T) { echo "⛔ اجرا از tests/run.php\n"; return; }
$T->file('S09a+S11 فرم سفارشی + ماسک');

/* ═══ ① ریشه عنصر فرم سفارشی ═══ */
$T->section('① listAll — فرم‌های اختصاصی برند هم دیده می‌شوند');
try {
    $db = Database::getInstance();
    /* ایجاد دو فرم آزمایشی: سراسری + اختصاصی برند ۹۹۹۹۹ */
    $db->query("DELETE FROM custom_forms WHERE slug IN ('s09-global-test','s09-brand-test')");
    $db->insert('custom_forms', [
        'title' => 'فرم سراسری تست', 'slug' => 's09-global-test', 'brand_id' => null,
        'fields' => json_encode([['type' => 'text', 'label' => 'نام', 'name' => 'f1', 'required' => true]], JSON_UNESCAPED_UNICODE),
        'settings' => '{}', 'is_active' => 1, 'entries_count' => 0,
    ]);
    $db->insert('custom_forms', [
        'title' => 'فرم اختصاصی تست', 'slug' => 's09-brand-test', 'brand_id' => 99999,
        'fields' => json_encode([['type' => 'text', 'label' => 'نام', 'name' => 'f1', 'required' => true]], JSON_UNESCAPED_UNICODE),
        'settings' => '{}', 'is_active' => 1, 'entries_count' => 0,
    ]);
    $old = CustomFormManager::listActive();
    $all = CustomFormManager::listAll();
    $oldSlugs = array_column($old, 'slug');
    $allSlugs = array_column($all, 'slug');
    $T->assert('قدیمی (listActive بدون پارامتر): فرم اختصاصی غایب — ریشه تأیید', !in_array('s09-brand-test', $oldSlugs, true));
    $T->assert('جدید (listAll): هر دو فرم حاضر', in_array('s09-global-test', $allSlugs, true) && in_array('s09-brand-test', $allSlugs, true));
    $brandNamed = null;
    foreach ($all as $a) { if ($a['slug'] === 's09-brand-test') { $brandNamed = $a; } }
    $T->assert('listAll کلید brand_id را می‌دهد', $brandNamed !== null && $brandNamed['brand_id'] === 99999);
    $T->assert('listAll کلید brand_name را می‌دهد (حتی خالی)', $brandNamed !== null && array_key_exists('brand_name', $brandNamed));
    $db->query("DELETE FROM custom_forms WHERE slug IN ('s09-global-test','s09-brand-test')");
} catch (Throwable $e) {
    echo "  ⚠️ بخش دیتابیسی رد شد: {$e->getMessage()}\n";
    $T->skipped++;
}

/* ═══ ② اعتبارسنجی سرور ماسک‌ها ═══ */
$T->section('② maskError — اعتبارسنجی سرور');
/* کد ملی معتبر: 0012345678 → sum=1*10+0*9+1*8+2*7+3*6+4*5+5*4+6*3+7*2=133... محاسبه دقیق در تست */
$nc = '0076439647'; /* نمونه معتبر شناخته‌شده */
$sum = 0;
for ($i = 0; $i < 9; $i++) { $sum += (int)$nc[$i] * (10 - $i); }
$r = $sum % 11;
$ncCheck = (string)($r < 2 ? $r : 11 - $r);
$ncValid = substr($nc, 0, 9) . $ncCheck;
$ncInvalid = substr($nc, 0, 9) . ($ncCheck === '9' ? '8' : '9');

$f = ['label' => 'کد ملی', 'name' => 'nc', 'mask' => 'national_code'];
$T->assert('کد ملی معتبر قبول', CustomFormManager::maskError($f, $ncValid) === null);
$T->assert('کد ملی با رقم کنترل غلط رد', CustomFormManager::maskError($f, $ncInvalid) !== null);
$T->assert('کد ملی ۹ رقمی رد', CustomFormManager::maskError($f, '123456789') !== null);
$T->assert('کد ملی با ارقام فارسی قبول (نرمال‌سازی)', CustomFormManager::maskError($f, self_fa($ncValid)) === null);

$m = ['label' => 'موبایل', 'name' => 'mob', 'mask' => 'mobile'];
$T->assert('موبایل فرمت‌دار قبول', CustomFormManager::maskError($m, '0912 345 6789') === null);
$T->assert('موبایل بدون صفر رد', CustomFormManager::maskError($m, '9123456789') !== null);
$T->assert('موبایل ۱۰ رقمی رد', CustomFormManager::maskError($m, '0912345678') !== null);

$p = ['label' => 'تلفن', 'name' => 'ph', 'mask' => 'phone'];
$T->assert('تلفن ثابت تبریز قبول', CustomFormManager::maskError($p, '041 333 12345') === null);
$T->assert('تلفن ۹ رقمی رد', CustomFormManager::maskError($p, '041333123') !== null);

$c = ['label' => 'کارت', 'name' => 'card', 'mask' => 'card'];
/* ساخت کارت معتبر Luhn: 60379975 در ۱۶ رقم */
$validCard = luhn_complete('603799751234567');
$T->assert('کارت ۱۶رقمی معتبر Luhn قبول', CustomFormManager::maskError($c, $validCard) === null);
$T->assert('کارت با رقم غلط رد (Luhn)', CustomFormManager::maskError($c, '6037997512345670') !== null || CustomFormManager::maskError($c, '6037997512345679') !== null);
$T->assert('کارت ۱۵ رقمی رد', CustomFormManager::maskError($c, '603799751234567') !== null);

$s = ['label' => 'شبا', 'name' => 'sh', 'mask' => 'sheba'];
$T->assert('شبا ۲۴ رقمی قبول', CustomFormManager::maskError($s, 'IR' . str_repeat('1', 24)) === null);
$T->assert('شبا ۲۲ رقمی رد', CustomFormManager::maskError($s, 'IR' . str_repeat('1', 22)) !== null);

$po = ['label' => 'کد پستی', 'name' => 'pc', 'mask' => 'postal'];
$T->assert('کد پستی ۱۰ رقم قبول', CustomFormManager::maskError($po, '5164831234') === null);
$T->assert('کد پستی با حروف رد', CustomFormManager::maskError($po, '5164a31234') !== null);

$cu = ['label' => 'سریال', 'name' => 'sr', 'mask' => 'custom', 'maskPattern' => 'AA-#####'];
$T->assert('الگوی دلخواه کامل قبول', CustomFormManager::maskError($cu, 'AB-12345') === null);
$T->assert('الگوی دلخواه ناقص رد', CustomFormManager::maskError($cu, 'AB-123') !== null);

$none = ['label' => 'آزاد', 'name' => 'fr', 'mask' => 'none'];
$T->assert('بدون ماسک: هر مقداری قبول', CustomFormManager::maskError($none, 'هر چی!') === null);

/* ═══ ③ رندر فیلد ماسک‌دار ═══ */
$T->section('③ رندر — ویژگی‌های ماسک روی اینپوت');
$formDef = [
    'name' => 'تست ماسک', 'slug' => 's11-mask-test',
    'fields' => [
        ['type' => 'text', 'label' => 'کد ملی', 'name' => 'nc', 'required' => true, 'mask' => 'national_code'],
        ['type' => 'tel', 'label' => 'موبایل', 'name' => 'mob', 'mask' => 'mobile'],
        ['type' => 'text', 'label' => 'سریال', 'name' => 'sr', 'mask' => 'custom', 'maskPattern' => 'AA-#####'],
        ['type' => 'text', 'label' => 'آزاد', 'name' => 'free'],
    ],
    'settings' => ['btnText' => 'بفرست'],
];
/* 🎭 شبیه‌ساز fetchFromAPI — در فرایند تست، config.php قالب لود نشده و
   فراخوانی واقعی شبکه ندارد؛ stub همین‌جا تعریف می‌شود (بدون تداخل) */
if (!function_exists('fetchFromAPI')) {
    function fetchFromAPI(string $endpoint, int $cacheTtl = 60): ?array
    {
        if (strpos($endpoint, 'custom-form/s11-mask-test') !== false) {
            return ['success' => true, 'data' => $GLOBALS['s11FormStub'] ?? null];
        }
        return null;
    }
}
$GLOBALS['s11FormStub'] = $formDef;
$ref = new ReflectionFunction('pv_custom_form');
$html = $ref->invoke(['customFormSlug' => 's11-mask-test'], 'فرم تست');
$T->assert('اینپوت ماسک کد ملی: data-mask', strpos($html, 'data-mask="national_code"') !== false);
$T->assert('الگوی ۱۰ رقم کد ملی روی اینپوت', strpos($html, 'data-mask-pattern="##########"') !== false);
$T->assert('موبایل: الگوی گروه‌بندی سه‌تایی', strpos($html, 'data-mask-pattern="#### ### ####"') !== false);
$T->assert('ماسک دلخواه: الگو منتقل شد', strpos($html, 'data-mask-pattern="AA-#####"') !== false);
$T->assert('اینپوت ماسک‌دار inputmode=numeric', strpos($html, 'inputmode="numeric"') !== false);
$T->assert('اینپوت ماسک‌دار LTR', strpos($html, 'dir="ltr"') !== false);
$T->assert('فیلد بدون ماسک: بدون data-mask', substr_count($html, 'data-mask=') === 3);
$T->assert('کلاس sahind-masked روی فیلدهای ماسک‌دار', substr_count($html, 'sahind-masked') === 3);

/* ═══ ④ sanitizeFields — ماسک فقط برای متنی تک‌خطی ═══ */
$T->section('④ sanitizeFields — قواعد ذخیره');
$san = CustomFormManager::sanitizeFields([
    ['type' => 'text', 'label' => 'کد', 'name' => 'a', 'mask' => 'national_code'],          /* مجاز */
    ['type' => 'textarea', 'label' => 'یادداشت', 'name' => 'b', 'mask' => 'mobile'],        /* ممنوع → حذف */
    ['type' => 'text', 'label' => 'خراب', 'name' => 'c', 'mask' => 'unknown-mask'],         /* نامعلوم → none */
    ['type' => 'text', 'label' => 'خالی', 'name' => 'd', 'mask' => 'custom'],               /* الگو خالی → حذف ماسک */
    ['type' => 'text', 'label' => 'درست', 'name' => 'e', 'mask' => 'custom', 'maskPattern' => '####/##/##'],
]);
$T->assert('متنی + کد ملی: ماسک ذخیره شد', ($san[0]['mask'] ?? '') === 'national_code');
$T->assert('textarea: ماسک حذف شد', !isset($san[1]['mask']));
$T->assert('نام ماسک نامعلوم: حذف شد', !isset($san[2]['mask']));
$T->assert('custom بدون الگو: حذف شد', !isset($san[3]['mask']));
$T->assert('custom با الگو: هر دو ذخیره شد', ($san[4]['mask'] ?? '') === 'custom' && ($san[4]['maskPattern'] ?? '') === '####/##/##');

/* ═══ ⑤ موتور JS ماسک — بررسی وجود توابع ═══ */
$T->section('⑤ form.js — موتور ماسک کلاینت');
$js = (string)@file_get_contents(dirname(__DIR__) . '/templates/brand-core/js/form.js');
$T->assert('تابع maskApply موجود', strpos($js, 'function maskApply') !== false);
$T->assert('اعتبارسنجی Luhn کارت در کلاینت', strpos($js, 'csum % 10 === 0') !== false);
$T->assert('رقم کنترل کد ملی در کلاینت', strpos($js, '11 - r') !== false);
$T->assert('گیت ارسال روی ماسک نامعتبر', strpos($js, 'اعتبارسنجی فیلدهای ماسک‌دار') !== false);

/* ابزارک‌های کمکی */
function self_fa(string $en): string
{
    return str_replace(['0','1','2','3','4','5','6','7','8','9'], ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], $en);
}
function luhn_complete(string $fifteen): string
{
    $sum = 0;
    for ($i = 0; $i < 15; $i++) {
        $d = (int)$fifteen[$i];
        /* رقم شانزدهم ایندکس ۱۵ (فرد) → چون از صفر می‌شماریم ایندکس زوج = دوبرابر */
        if ($i % 2 === 0) { $d *= 2; if ($d > 9) { $d -= 9; } }
        $sum += $d;
    }
    return $fifteen . ((10 - ($sum % 10)) % 10);
}

echo "\n";
