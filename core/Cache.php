<?php
/**
 * ⚡ کلاس کش — سیستم کش فایل‌محور
 * =================================
 * برای هاست اشتراکی cPanel که معمولاً APCu/Redis ندارد،
 * کش مبتنی بر فایل با پشتیبانی TTL پیاده‌سازی شده است.
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class Cache
{
    /** @var string پوشه ذخیره فایل‌های کش */
    private $cacheDir;

    public function __construct()
    {
        $this->cacheDir = CACHE_PATH;
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * 🔑 تولید نام فایل کش امن از کلید
     */
    private function fileFor(string $key): string
    {
        return $this->cacheDir . '/cache_' . sha1($key) . '.cache';
    }

    /**
     * 📥 دریافت مقدار از کش
     *
     * @param string $key     کلید یکتا
     * @param mixed  $default مقدار پیش‌فرض در صورت نبود/انقضا
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $file = $this->fileFor($key);
        if (!file_exists($file)) {
            return $default;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return $default;
        }

        $data = @unserialize($raw);
        if (!is_array($data) || !isset($data['expires_at'], $data['value'])) {
            @unlink($file);
            return $default;
        }

        // ⏰ بررسی انقضا
        if ($data['expires_at'] !== 0 && $data['expires_at'] < time()) {
            @unlink($file);
            return $default;
        }

        return $data['value'];
    }

    /**
     * 💾 ذخیره مقدار در کش
     *
     * @param string $key      کلید یکتا
     * @param mixed  $value    مقدار (هر نوع قابل serialize)
     * @param int    $ttl      مدت اعمال به ثانیه (۰ = بی‌نهایت)
     */
    public function set(string $key, $value, int $ttl = 3600): bool
    {
        $payload = serialize([
            'expires_at' => $ttl === 0 ? 0 : time() + $ttl,
            'value'      => $value,
        ]);
        return @file_put_contents($this->fileFor($key), $payload, LOCK_EX) !== false;
    }

    /**
     * ❌ حذف یک کلید از کش
     */
    public function delete(string $key): void
    {
        $file = $this->fileFor($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * 🧹 پاکسازی تمام کش‌ها (یا کش‌های با پیشوند مشخص)
     *
     * @param string $prefix پیشوند کلیدها (مثلاً brand_5_)
     */
    public function flush(string $prefix = ''): void
    {
        $files = glob($this->cacheDir . '/cache_*.cache') ?: [];
        foreach ($files as $file) {
            if ($prefix === '') {
                @unlink($file);
            } else {
                // بررسی محتوا برای تطبیق پیشوند کلید
                $raw = @file_get_contents($file);
                if ($raw !== false && strpos($raw, 's:' . strlen($prefix) . ':"' . $prefix) !== false) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * 🎯 دریافت یا محاسبه (شکل راحت برای الگوی Cache-Aside)
     *
     * @param string   $key      کلید کش
     * @param int      $ttl      مدت اعتبار
     * @param callable $callback تابع محاسبه در صورت نبود کش
     */
    public function remember(string $key, int $ttl, callable $callback)
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    /**
     * 📊 آمار کش (تعداد فایل‌ها و حجم کل)
     */
    public function stats(): array
    {
        $files = glob($this->cacheDir . '/cache_*.cache') ?: [];
        $size = 0;
        foreach ($files as $f) {
            $size += filesize($f);
        }
        return [
            'files' => count($files),
            'size'  => $size,              // بایت
            'size_readable' => $this->humanSize($size),
        ];
    }

    /**
     * 📏 تبدیل بایت به خوانا
     */
    private function humanSize(int $bytes): string
    {
        $units = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
