<?php
/**
 * 🚪 خروج از پنل و پاکسازی نشست
 */
define("SAHAND_INIT", true);
require_once dirname(__DIR__) . "/config.php";
/* 🛡️ v2.33 — دفاع در عمق: رد درخواست بین‌سایتی (گزارش تحلیل — بخش امنیت) */
reject_cross_origin();

(new Auth())->logout();
/* 🩺 v2.39 — مسیر مطلق: خروج از ریشهٔ دامنه (DirectoryIndex) دیگر ۴۰۴ نمی‌دهد */
redirect('/admin/login.php');
