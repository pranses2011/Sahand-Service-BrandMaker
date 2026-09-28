<?php
/**
 * 🛡️ دریافت گزارش‌های سیاست امنیت محتوا (CSP Report-Only) — v2.33
 * =====================================================================
 * مرورگر تخلف‌های CSP را به این اندپوینت POST می‌کند و ما در
 * logs/csp.log ثبتش می‌کنیم — پس از بررسی و پاک‌سازی تخلف‌ها،
 * هدر در .htaccess از Report-Only به حالت اجرایی تبدیل می‌شود.
 *
 * 🪶 کاملاً مستقل: بدون bootstrap دیتابیس/نشست (فقط لاگ با قفل و سقف حجم)
 * — چون گزارش‌های CSP می‌توانند پرتعداد باشند و هزینه‌ساز است.
 *
 * @package SahandBrandMaker
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

/* 📥 بدنه گزارش — سقف ۶۴ کیلوبایت */
$raw = (string)file_get_contents('php://input');
if ($raw === '' || strlen($raw) > 65536) {
    http_response_code(413);
    exit;
}

/* 🧼 استخراج فیلدهای مفید (فرمت قدیم csp-report و جدید body) */
$doc = json_decode($raw, true);
$r = is_array($doc) ? ($doc['csp-report'] ?? $doc['body'] ?? []) : [];
if (!is_array($r)) { $r = []; }
$directive = (string)($r['violated-directive'] ?? $r['effectiveDirective'] ?? '?');
$blocked = (string)($r['blocked-uri'] ?? $r['blockedURL'] ?? ($r['source-file'] ?? '?'));
if (strlen($blocked) > 300) { $blocked = substr($blocked, 0, 300); }

/* 📝 ثبت با قفل و چرخش در سقف ۱ مگابایت */
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
$logFile = $logDir . '/csp.log';
if (is_file($logFile) && (int)@filesize($logFile) > 1048576) {
    @rename($logFile, $logFile . '.old');
    @unlink($logFile . '.old');
}
$line = date('Y-m-d H:i:s') . ' | ' . $directive . ' | ' . $blocked
    . ' | ' . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120) . "\n";
@file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

/* ۲۰۴ = ثبت شد؛ مرورگر انتظار پاسخ ندارد */
http_response_code(204);
