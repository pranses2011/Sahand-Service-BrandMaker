<?php
/**
 * 📡 IndexNow — اطلاع‌رسانی فوری موتورهای جستجو (v2.34 — P1 #17)
 * ================================================================
 * پروتکل IndexNow (مایکروسافت بینگ / یاندکس / Seznam / ناور):
 * یک POST ساده با کلید عمومی برند، URLهای جدید/تغییرکرده را فوراً
 * به فهرست ایندکس موتور می‌رساند (به‌جای انتظار برای خزش دوره‌ای).
 *
 * کلید هر برند در extra_settings ذخیره می‌شود (indexnow_key) و
 * فایل کلید هنگام استقرار در ریشه سایت برند قرار می‌گیرد.
 *
 * @package SahandBrandMaker
 */
class IndexNow
{
    /** اندپوینت مشترک (به همه موتورهای عضو شامل بینگ توزیع می‌شود) */
    const ENDPOINT = 'https://api.indexnow.org/indexnow';

    /** حداکثر URL در هر درخواست (سقف پروتکل ۱۰هزار — ما محافظه‌کاریم) */
    const MAX_URLS = 100;

    /**
     * 🔑 کلید عمومی برند — اولین بار تولید و در extra_settings ذخیره می‌شود
     */
    public static function keyFor(int $brandId): string
    {
        $db = Database::getInstance();
        $brand = $db->fetch('SELECT extra_settings FROM brands WHERE id = ?', [$brandId]);
        $extra = json_decode((string)($brand['extra_settings'] ?? ''), true) ?: [];
        $key = (string)($extra['indexnow_key'] ?? '');
        if (preg_match('/^[a-f0-9\-]{16,64}$/', $key) === 1) {
            return $key;
        }
        // تولید کلید جدید (فرمت توصیه‌شده: ۱۶-۱۲۸ حرف hex/خط تیره)
        $key = bin2hex(random_bytes(16));
        $extra['indexnow_key'] = $key;
        $db->update('brands', ['extra_settings' => json_encode($extra, JSON_UNESCAPED_UNICODE)], 'id = ?', [$brandId]);
        return $key;
    }

    /**
     * 📄 محتوای فایل کلید — در ریشه سایت برند قرار می‌گیرد ({key}.txt)
     */
    public static function keyFileContent(int $brandId): string
    {
        return self::keyFor($brandId);
    }

    /**
     * 🚀 ارسال URLها به IndexNow — fire-and-forget (هرگز جریان اصلی را نمی‌شکند)
     *
     * @param int   $brandId برند مالک URLها
     * @param array $urls    URLهای کامل (https://...) یا مسیر نسبی
     * @return array ['ok'=>bool,'status'=>?int,'error'=>?string]
     */
    public static function ping(int $brandId, array $urls): array
    {
        try {
            $brand = Database::getInstance()->fetch('SELECT full_domain, domain FROM brands WHERE id = ?', [$brandId]);
            if (!$brand) {
                return ['ok' => false, 'error' => 'brand not found'];
            }
            $host = (string)($brand['full_domain'] ?: $brand['domain']);
            if ($host === '' || str_contains($host, ' ') || str_contains($host, '/')) {
                return ['ok' => false, 'error' => 'no valid domain'];
            }
            $key = self::keyFor($brandId);

            // نرمال‌سازی URLها به مطلق
            $abs = [];
            foreach (array_slice($urls, 0, self::MAX_URLS) as $u) {
                $u = trim((string)$u);
                if ($u === '') { continue; }
                if (preg_match('#^https?://#i', $u) !== 1) {
                    $u = 'https://' . $host . '/' . ltrim($u, '/');
                }
                // فقط URLهای همین هاست
                if (parse_url($u, PHP_URL_HOST) === $host) {
                    $abs[] = $u;
                }
            }
            if ($abs === []) {
                return ['ok' => false, 'error' => 'no valid urls'];
            }

            $payload = json_encode([
                'host'        => $host,
                'key'         => $key,
                'keyLocation' => 'https://' . $host . '/' . $key . '.txt',
                'urlList'     => array_values(array_unique($abs)),
            ], JSON_UNESCAPED_SLASHES);

            $ch = curl_init(self::ENDPOINT);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            // 200/202 = پذیرش | 422 = کلید/فایل کلید هنوز آماده نیست (بی‌خطر — بعداً دوباره)
            $ok = in_array($status, [200, 202], true);
            if (!$ok) {
                @error_log("[IndexNow] brand#{$brandId} status={$status} err={$err} body=" . substr((string)$body, 0, 200));
            }
            return ['ok' => $ok, 'status' => $status, 'error' => $ok ? null : ($err !== '' ? $err : "HTTP {$status}")];
        } catch (Throwable $e) {
            @error_log('[IndexNow] exception: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 🚀 میان‌بر: پینگ مقاله تازه منتشرشده (+ sitemap برای اطمینان)
     */
    public static function pingArticle(int $brandId, string $slug): array
    {
        $brand = Database::getInstance()->fetch('SELECT full_domain, domain FROM brands WHERE id = ?', [$brandId]);
        $host = (string)($brand['full_domain'] ?: $brand['domain'] ?? '');
        if ($host === '') {
            return ['ok' => false, 'error' => 'no domain'];
        }
        return self::ping($brandId, [
            'https://' . $host . '/blog/' . $slug,
            'https://' . $host . '/sitemap.xml',
        ]);
    }
}
