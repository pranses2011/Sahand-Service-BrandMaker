<?php
/**
 * 🧪 تست S07 (v2.45) — دقت جغرافیایی: گارد اپراتور موبایل در هر سه سرویس + بازحسابی پاک‌کننده
 * اجرا در بسته: php tests/run.php --filter=s07-geo (نیازمند MariaDB + اینترنت برای بخش زنده)
 */
error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('SAHAND_INIT')) { define('SAHAND_INIT', true); }
require_once dirname(__DIR__) . '/config.local.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/GeoIP.php';

/** @var TestRunner $T */
$T = $GLOBALS['T'] ?? null;
if (!$T) { echo "⛔ این تست باید از طریق tests/run.php اجرا شود\n"; return; }
$t = $T;
$t->file('S07 جغرافیا — گارد اپراتور موبایل');

/* ═══ ① تست واحد: irMobileOrg با نام‌های واقعی سازمان‌ها ═══ */
$t->section('① تشخیص سازمان موبایل (irMobileOrg)');
$ref = new ReflectionMethod('GeoIP', 'irMobileOrg');
$ref->setAccessible(true);

$positives = [
    'Mobile Communication Company of Iran PLC',       /* MCI — نام رسمی */
    'AS197207 Mobile Communication Company of Iran PLC',
    'Iran Cell Service and Communication Company',    /* Irancell — نام رسمی */
    'MTN Irancell',
    'Rightel Communication Services Company',
    'Taliya',
    'Mobin Net |宽带',                                 /* مبین‌نت */
    'Aptus Pardaz',
    'همراه اول پیام کیش',
];
$negatives = [
    'Iran Telecommunication Company PJS',             /* TCI — خط ثابت: مجاز */
    'Respina Networks & Beyond PJSC',                 /* رسپینا: مجاز */
    'Pars Online PJS',
    'Shatel Communication Development Co',
    'Advanced Communications Technology',             /* سنجش مرز کلمه mci */
    'Tribion B.V.',
    '',
    null,
];
foreach ($positives as $org) {
    $t->assert("موبایل شناخته شد: {$org}", $ref->invoke(null, $org) === true);
}
foreach ($negatives as $org) {
    $t->assert("ثابت مجاز ماند: " . var_export($org, true), $ref->invoke(null, $org) === false);
}
/* ASN */
$t->assert('ASN 197207 (همراه اول) شناخته شد', $ref->invoke(null, null, 197207) === true);
$t->assert('ASN 44244 (ایرانسل) شناخته شد', $ref->invoke(null, null, '44244') === true);
$t->assert('ASN 58224 (TCI ثابت) رد شد', $ref->invoke(null, null, 58224) === false);

/* ═══ ② تست زنده: مسیر ipwho.is (سرویس بدون پرچم mobile) ═══
   IP 5.125.100.1 = ایرانسل — ipwho.is می‌گوید «تهران» (محل ثبت اپراتور)
   و connection.org = «Iran Cell Service and Communication Company».
   انتظار: گارد جدید باید is_mobile=true و شهر/استان خالی برگرداند. */
$t->section('② مسیر ipwho.is — گارد سازمان');
$refW = new ReflectionMethod('GeoIP', 'fetchIpWho');
$refW->setAccessible(true);
$res = $refW->invoke(null, '5.125.100.1');
if ($res === null) {
    echo "  ⚠️ ipwho.is از این محیط در دسترس نیست — تست زنده رد شد (شبکه)\n";
    $t->skipped++;
} else {
    $t->assert('ایرانسل از طریق ipwho.is: is_mobile=true', !empty($res['is_mobile']));
    $t->assert('شهر خالی (نه «تهران» ثبت اپراتور)', $res['city'] === '');
    $t->assert('استان خالی', $res['province'] === '');
    $t->assert('کشور ایران ماند', $res['country'] === 'IR');
}

/* ═══ ③ زنجیره کامل lookup — IP موبایل MCI ═══ */
$t->section('③ lookup کامل — 5.106.100.1 (MCI)');
@unlink(dirname(__DIR__) . '/cache/geoip-throttle');
GeoIP::resetMemoForTests();
$geo = GeoIP::lookup('5.106.100.1');
$t->assert('زنجیره کامل: is_mobile=true', !empty($geo['is_mobile']));
$t->assert('زنجیره کامل: شهر خالی', $geo['city'] === '');
$t->assert('زنجیره کامل: استان خالی', $geo['province'] === '');
$t->assert('منبع api یا cache', in_array($geo['source'], ['api', 'cache'], true));

/* ═══ ④ کنترل مثبت: IP ثابت واقعاً شهر می‌گیرد ═══ */
$t->section('④ کنترل مثبت — IP ثابت 2.176.100.1 (TCI)');
@unlink(dirname(__DIR__) . '/cache/geoip-throttle');
GeoIP::resetMemoForTests();
$geo2 = GeoIP::lookup('2.176.100.1');
if ($geo2['source'] === 'local') {
    echo "  ⚠️ سرویس خارجی در دسترس نبود — کنترل مثبت رد شد\n";
    $t->skipped++;
} else {
    $t->assert('IP ثابت: is_mobile نیست', empty($geo2['is_mobile']));
    $t->assert('IP ثابت: شهر دارد (' . $geo2['city'] . ')', $geo2['city'] !== '');
    $t->assert('IP ثابت: استان دارد (' . $geo2['province'] . ')', $geo2['province'] !== '');
}

/* ═══ ⑤ بازحسابی: ردیف آلوده قدیمی پاک می‌شود ═══ */
$t->section('⑤ recompute — پاک‌سازی ردیف آلوده موبایل');
try {
    $db = Database::getInstance();
    /* شبیه‌سازی ردیف آلوده: کاربر موبایلی که قبلاً «خراسان رضوی» گرفته */
    $db->query("DELETE FROM visits WHERE ip_prefix = '5.106.100.0'");
    $db->insert('visits', [
        'brand_id' => 1, 'session_hash' => 's07-test-mobile-1', 'ip_hash' => 'x',
        'ip_prefix' => '5.106.100.0', 'user_agent' => 'test', 'device_type' => 'mobile',
        'country' => 'IR', 'city' => 'مشهد', 'province' => 'خراسان رضوی',
        'geo_src' => 'api', 'visit_date' => date('Y-m-d'), 'visited_at' => date('Y-m-d H:i:s'),
        'last_seen' => date('Y-m-d H:i:s'),
    ]);
    $stats = GeoIP::recompute(120);
    $row = $db->fetch("SELECT city, province, geo_src FROM visits WHERE ip_prefix = '5.106.100.0'");
    $t->assert('ردیف آلوده پاک شد (city=NULL)', $row === null || $row['city'] === null);
    $t->assert('استان غلط پاک شد (province=NULL)', $row === null || $row['province'] === null);
    $t->assert('geo_src=mobile شد', $row !== null && $row['geo_src'] === 'mobile');
    $db->query("DELETE FROM visits WHERE ip_prefix = '5.106.100.0'");
} catch (Throwable $e) {
    echo "  ⚠️ تست بازحسابی: {$e->getMessage()}\n";
    $t->skipped++;
}

echo "\n";
