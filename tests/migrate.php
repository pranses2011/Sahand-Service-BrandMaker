#!/usr/bin/env php
<?php
/* 🧪 اجرای مهاجرت‌ها روی دیتابیس شبیه‌سازی تست */
if (!defined('SAHAND_INIT')) { define('SAHAND_INIT', true); }
if (PHP_SAPI !== 'cli') { exit(1); }

require_once dirname(__DIR__) . '/config.local.php';
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/MigrationRunner.php';

$res = MigrationRunner::run();
echo "مهاجرت‌ها اجرا شدند: " . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
