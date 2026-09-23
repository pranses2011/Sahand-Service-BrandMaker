<?php
/**
 * ⚡ کش موتور هوش مصنوعی — EngineCache v3.0
 * ==========================================
 * لایه کش فایل‌محور برای پاسخ‌های پرهزینه موتور AI.
 * بدون نیاز به افزونه خارجی — کاملاً داخلی.
 *
 * ویژگی‌ها:
 *   🚀 سرعت: پاسخ‌های تکراری در چند میلی‌ثانیه (به‌جای ثانیه)
 *   ⏱️ TTL قابل تنظیم برای هر کلید
 *   🧹 پاکسازی خودکار فایل‌های منقضی
 *   📊 آمار hit/miss برای مانیتورینگ
 *   🔐 کلید امن (SHA-256 از namespace+params)
 *
 * @package SahandBrandMaker\Engine
 * @version 3.0.0
 */
class EngineCache
{
    /** @var string مسیر پوشه کش */
    private static $dir = '';

    /** @var array آمار hit/miss در طول درخواست */
    private static $stats = ['hits' => 0, 'misses' => 0, 'writes' => 0];

    /** @var int حداکثر عمر پیش‌فرض (ثانیه) */
    public const DEFAULT_TTL = 3600;

    /**
     * 📁 مسیر پوشه کش (singleton آماده‌سازی)
     */
    private static function dir(): string
    {
        if (self::$dir === '') {
            self::$dir = dirname(__DIR__, 2) . '/cache/engine';
            if (!is_dir(self::$dir)) {
                @mkdir(self::$dir, 0755, true);
            }
        }
        return self::$dir;
    }

    /**
     * 🔑 ساخت کلید امن
     */
    public static function key(string $namespace, array $params): string
    {
        unset($params['no_cache'], $params['_t']);
        ksort($params);
        return 'ai_' . hash('sha256', $namespace . '|' . json_encode(
            $params,
            JSON_UNESCAPED_UNICODE
        ));
    }

    /**
     * 💾 خواندن از کش (null = miss)
     */
    public static function get(string $key): ?array
    {
        $file = self::dir() . '/' . $key . '.json';
        if (!is_file($file)) {
            self::$stats['misses']++;
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            self::$stats['misses']++;
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['expires_at'] ?? 0) < time()) {
            @unlink($file); // 🧹 منقضی → حذف
            self::$stats['misses']++;
            return null;
        }
        self::$stats['hits']++;
        return $data['value'];
    }

    /**
     * ✍️ نوشتن در کش
     */
    public static function set(string $key, array $value, int $ttl = self::DEFAULT_TTL): bool
    {
        $file = self::dir() . '/' . $key . '.json';
        $payload = json_encode([
            'expires_at' => time() + max(60, $ttl),
            'created_at' => time(),
            'value'      => $value,
        ], JSON_UNESCAPED_UNICODE);

        $ok = @file_put_contents($file, $payload, LOCK_EX) !== false;
        if ($ok) {
            self::$stats['writes']++;
        }
        return $ok;
    }

    /**
     * 🚀 الگوی remember — یا از کش بخوان یا محاسبه کن و ذخیره کن
     *
     * @param string   $namespace فضای نام (نام عملیات)
     * @param array    $params    پارامترهای مؤثر بر خروجی
     * @param int      $ttl       عمر کش (ثانیه)
     * @param callable $fn        تابع محاسبه در صورت miss
     */
    public static function remember(string $namespace, array $params, int $ttl, callable $fn): array
    {
        if (!empty($params['no_cache'])) {
            return $fn();
        }
        $key = self::key($namespace, $params);
        $cached = self::get($key);
        if ($cached !== null) {
            return $cached + ['_cached' => true];
        }
        $result = $fn();
        if (!empty($result)) {
            self::set($key, $result, $ttl);
        }
        return $result;
    }

    /**
     * 🧹 پاکسازی کل کش یا یک namespace
     *
     * @return int تعداد فایل‌های حذف‌شده
     */
    public static function clear(): int
    {
        $count = 0;
        foreach (glob(self::dir() . '/*.json') ?: [] as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 🧹 پاکسازی فایل‌های منقضی (سبک — بدون حذف همه)
     */
    public static function gc(): int
    {
        $count = 0;
        $now = time();
        foreach (glob(self::dir() . '/*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || ($data['expires_at'] ?? 0) < $now) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * 📊 آمار کش
     */
    public static function stats(): array
    {
        $files = glob(self::dir() . '/*.json') ?: [];
        $size = 0;
        $oldest = null;
        $newest = null;
        foreach ($files as $f) {
            $size += filesize($f);
            $mtime = filemtime($f);
            $oldest = $oldest === null ? $mtime : min($oldest, $mtime);
            $newest = $newest === null ? $mtime : max($newest, $mtime);
        }
        return [
            'entries'     => count($files),
            'size_bytes'  => $size,
            'size_human'  => self::humanSize($size),
            'oldest_age'  => $oldest ? (time() - $oldest) : 0,
            'newest_age'  => $newest ? (time() - $newest) : 0,
            'hits'        => self::$stats['hits'],
            'misses'      => self::$stats['misses'],
            'writes'      => self::$stats['writes'],
            'hit_rate'    => (self::$stats['hits'] + self::$stats['misses']) > 0
                ? round(self::$stats['hits'] / (self::$stats['hits'] + self::$stats['misses']), 3)
                : null,
        ];
    }

    /**
     * 📏 تبدیل بایت به خوانا
     */
    private static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $val = (float)$bytes;
        while ($val >= 1024 && $i < 3) {
            $val /= 1024;
            $i++;
        }
        return round($val, 1) . ' ' . $units[$i];
    }
}
