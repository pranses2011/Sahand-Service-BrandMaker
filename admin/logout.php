<?php
/**
 * 🚪 خروج از پنل و پاکسازی نشست
 */
define("SAHAND_INIT", true);
require_once dirname(__DIR__) . "/config.php";
(new Auth())->logout();
header("Location: login.php");
exit;
