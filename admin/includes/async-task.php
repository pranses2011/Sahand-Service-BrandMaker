<?php
/**
 * 🪜 v2.38 — زیرساخت وظایف غیرهمگامِ مرحله‌ای (Step-Based Async Tasks)
 * =====================================================================
 * ریشه‌یابی «تولید مقاله/خطایاب در انتها با خطای داخلی سرور قطع می‌شود»:
 *   هاست اشتراکی (LiteSpeed) هر درخواست HTTP را پس از ~۱۰۰ تا ۳۰۰ ثانیه
 *   می‌کُشد — مهم نیست PHP چقدر مهلت داشته باشد. راه‌حل قطری: «صف کارِ
 *   سمت-کلاینت» — هر درخواست فقط «یک گام» کوتاه (~۳۵ ثانیه) انجام می‌دهد،
 *   وضعیت در فایل ذخیره می‌شود و مرورگر بلافاصله گام بعدی را می‌خواهد.
 *   نتیجه: ① صفر تایم‌اوت ② نوار پیشرفت زنده ③ حتی با بستن مرورگر،
 *   گام‌های انجام‌شده از دست نمی‌روند (ازسرگیری خودکار).
 *
 * دو نوع فایل برای هر وظیفه:
 *   📦 state   → cache/tasks/{type}-{key}.json   (وضعیت کامل گام‌ها)
 *   📊 progress→ cache/{type}-progress-{key}.json (پیشرفت برای polling سبک)
 *
 * @package SahandBrandMaker
 */

if (!defined('SAHAND_INIT')) {
    http_response_code(403);
    exit;
}

/**
 * 📦 ساخت وظیفه جدید — کلید یکتا برمی‌گرداند
 */
function async_task_create(string $type, array $payload): string
{
    $key = substr(bin2hex(random_bytes(8)), 0, 16);
    $dir = CACHE_PATH . '/tasks';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $task = [
        'type'    => $type,
        'key'     => $key,
        'payload' => $payload,
        'state'   => [],
        'phase'   => '',
        'created' => time(),
        'updated' => time(),
        'steps_done' => 0,
        'article_id' => null,
        'error'   => null,
    ];
    @file_put_contents(async_task_path($type, $key), json_encode($task, JSON_UNESCAPED_UNICODE), LOCK_EX);
    async_task_progress_reset($type, $key);
    return $key;
}

/**
 * 📁 مسیر فایل وضعیت
 */
function async_task_path(string $type, string $key): string
{
    $safeType = preg_replace('/[^a-z0-9\-_]/i', '', $type) ?? 'task';
    $safeKey = preg_replace('/[^a-z0-9]/i', '', $key) ?? '';
    return CACHE_PATH . '/tasks/' . $safeType . '-' . $safeKey . '.json';
}

/**
 * 📖 بارگذاری وظیفه (null = وجود ندارد)
 */
function async_task_load(string $type, string $key): ?array
{
    $path = async_task_path($type, $key);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

/**
 * 💾 ذخیره وضعیت وظیفه
 */
function async_task_save(string $type, string $key, array $task): void
{
    $task['updated'] = time();
    @file_put_contents(async_task_path($type, $key), json_encode($task, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * 🗑 حذف وظیفه + فایل پیشرفتش
 */
function async_task_delete(string $type, string $key): void
{
    @unlink(async_task_path($type, $key));
    @unlink(async_task_progress_path($type, $key));
}

/**
 * 🧹 پاک‌سازی وظایف رهاشده (بیش از ۳۰ دقیقه) — بهترین تلاش
 * نسخه‌ی v2.38: فایل‌های قفلِ گام (*.lock) هم پاک می‌شوند
 */
function async_task_gc(): void
{
    $dir = CACHE_PATH . '/tasks';
    if (!is_dir($dir)) {
        return;
    }
    $files = array_merge(glob($dir . '/*.json') ?: [], glob($dir . '/*.lock') ?: []);
    if (count($files) < 8) {
        return; /* کمتر از حد آستانه — کار لازم نیست */
    }
    foreach ($files as $f) {
        if (is_file($f) && (time() - (int)filemtime($f)) > 1800) {
            @unlink($f);
        }
    }
}

/* ==================================================
 * 📊 فایل پیشرفت (پولینگ سبک — الگوی imggen/errorgen موجود)
 * ================================================== */

function async_task_progress_path(string $type, string $key): string
{
    $safeType = preg_replace('/[^a-z0-9\-_]/i', '', $type) ?? 'task';
    $safeKey = preg_replace('/[^a-z0-9]/i', '', $key) ?? '';
    return CACHE_PATH . '/' . $safeType . '-progress-' . $safeKey . '.json';
}

function async_task_progress_reset(string $type, string $key): void
{
    async_task_progress_write($type, $key, 1, 'آماده‌سازی...', '');
}

function async_task_progress_write(string $type, string $key, int $pct, string $title, string $detail = ''): void
{
    @file_put_contents(
        async_task_progress_path($type, $key),
        json_encode(['pct' => max(0, min(100, $pct)), 'title' => $title, 'detail' => $detail, 'ts' => time()], JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function async_task_progress_read(string $type, string $key): ?array
{
    $path = async_task_progress_path($type, $key);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : null;
}
