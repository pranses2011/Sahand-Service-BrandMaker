<?php
/**
 * 🚪 مسیریاب ریشه سایت‌ساز (v2.39 — رفع ۴۰۴ پس از ورود)
 * =====================================================================
 * 🩺 ریشهٔ باگ: صفحه ورود از طریق DirectoryIndex در «ریشهٔ دامنه»
 * سرو می‌شود (URL همان / باقی می‌ماند) و ریدایرکتِ نسبیِ «index.php»
 * پس از ورود به ‎/index.php‎ در ریشه می‌رسد — فایلی که وجود نداشت → ۴۰۴.
 *
 * این مسیریاب ریشه را می‌بندد و سه حالت را هوشمندانه هدایت می‌کند:
 *   ۱) نصب‌نشده (بدون install.lock) → نصب‌کننده
 *   ۲) نشست فعال → داشبورد پنل
 *   ۳) مهمان → صفحه ورود
 *
 * @package SahandBrandMaker
 * @since   2.39.0
 */

define('SAHAND_INIT', true);
require_once __DIR__ . '/config.php';

/* ۱) سیستم هنوز نصب نشده → نصب‌کننده */
if (!file_exists(__DIR__ . '/install.lock')) {
    redirect('/install.php');
}

/* ۲) + ۳) نشست فعال → داشبورد — در غیر این صورت → ورود
 * (مسیرهای مطلق از ریشه اپ = مقاوم در برابر سرو از DirectoryIndex) */
$loggedIn = false;
try {
    $auth = new Auth();
    $loggedIn = $auth->isLoggedIn();
} catch (Throwable $e) {
    /* دیتابیس در دسترس نیست — ورود خودش خطای دقیق را نشان می‌دهد */
    $loggedIn = false;
}

redirect($loggedIn ? '/admin/index.php' : '/admin/login.php');
