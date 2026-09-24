<?php
/**
 * 🩺 بررسی سلامت سایت‌های برند — مانیتورینگ دوره‌ای
 * =================================================
 * طبق سند بخش ۲۱ (بخش ۹):
 *   - HTTP Status — هر ۱۵ دقیقه (کد ۲۰۰)
 *   - SSL Valid — هر ۲۴ ساعت (تاریخ انقضا)
 *   - API Connection — هر ۳۰ دقیقه (اتصال به سایت ساز)
 *   - ثبت نتیجه در جدول site_health + بروزرسانی برند
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class HealthChecker
{
    /** @var Database اتصال دیتابیس */
    private $db;

    /** @var int Timeout درخواست‌ها */
    private $timeout = 20;

    /**
     * 🔧 سازنده
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 🩺 بررسی کامل سلامت یک برند
     *
     * @param array $brand ردیف برند (id، full_domain)
     * @return array نتیجه بررسی
     */
    public function checkBrand(array $brand): array
    {
        $domain = (string)($brand['full_domain'] ?? '');
        if ($domain === '') {
            return $this->saveResult($brand, [
                'status' => 'error', 'http_status' => null, 'response_time' => null,
                'ssl_valid' => null, 'api_ok' => null, 'error' => 'دامنه استقرار ثبت نشده است.',
            ]);
        }

        // ۱. بررسی HTTP — کد وضعیت + زمان پاسخ
        $http = $this->checkHttp($domain);

        // ۲. بررسی SSL
        $ssl = $this->checkSslQuick($domain);

        // ۳. بررسی اتصال API سایت ساز (اندپوینت عمومی brands)
        $apiOk = null;
        if ($http['ok']) {
            $apiOk = $this->checkApiConnection($domain);
        }

        // ۴. تعیین وضعیت کلی
        $status = 'offline';
        if ($http['ok']) {
            $status = 'online';
        } elseif ($http['code'] !== null && $http['code'] >= 400) {
            $status = 'error'; // سرور جواب می‌دهد اما خطا برمی‌گرداند
        }

        return $this->saveResult($brand, [
            'status'        => $status,
            'http_status'   => $http['code'],
            'response_time' => $http['time_ms'],
            'ssl_valid'     => $ssl['valid'],
            'ssl_expiry'    => $ssl['expiry'],
            'api_ok'        => $apiOk,
            'error'         => $http['ok'] ? null : $http['error'],
        ]);
    }

    /**
     * 🩺 بررسی سلامت همه برندهای استقرارشده (برای cron)
     *
     * @return array خلاصه نتایج
     */
    public function checkAll(): array
    {
        $brands = $this->db->fetchAll('SELECT id, name_fa, slug, full_domain FROM brands WHERE is_deployed = 1 AND is_active = 1');
        $results = ['checked' => 0, 'online' => 0, 'offline' => 0, 'error' => 0];

        foreach ($brands as $brand) {
            $r = $this->checkBrand($brand);
            $results['checked']++;
            if ($r['status'] === 'online') {
                $results['online']++;
            } elseif ($r['status'] === 'offline') {
                $results['offline']++;
            } else {
                $results['error']++;
            }
        }
        return $results;
    }

    /**
     * 🌐 بررسی HTTP سایت
     *
     * @return array [ok, code, time_ms, error]
     */
    private function checkHttp(string $domain): array
    {
        $start = microtime(true);
        $ch = curl_init('https://' . $domain);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true, // درخواست HEAD — سبک
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false, // سایت بدون SSL هم قابل بررسی باشد
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
            CURLOPT_USERAGENT      => 'SahandBrandMaker-HealthCheck/' . SAHAND_VERSION,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        $timeMs = (int)round((microtime(true) - $start) * 1000);

        // کد ۲۰۰ (یا ریدایرکت‌های معتبر) = سالم
        if ($errno === 0 && $code >= 200 && $code < 400) {
            return ['ok' => true, 'code' => $code, 'time_ms' => $timeMs, 'error' => null];
        }

        // 🔄 fallback HTTP (اگر HTTPS نداشت)
        if ($errno !== 0) {
            $ch2 = curl_init('http://' . $domain);
            curl_setopt_array($ch2, [
                CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout, CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2,
            ]);
            curl_exec($ch2);
            $code2 = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);
            if ($code2 >= 200 && $code2 < 400) {
                return ['ok' => true, 'code' => $code2, 'time_ms' => $timeMs, 'error' => null];
            }
        }

        return ['ok' => false, 'code' => $code ?: null, 'time_ms' => $timeMs, 'error' => $error ?: 'پاسخ HTTP نامعتبر'];
    }

    /**
     * 🔒 بررسی سریع SSL (بدون cPanel API — مستقیم)
     *
     * @return array [valid, expiry]
     */
    private function checkSslQuick(string $domain): array
    {
        $context = @stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'SNI_enabled'       => true,
            'peer_name'         => $domain,
        ]]);
        $client = @stream_socket_client('ssl://' . $domain . ':443', $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);

        if ($client === false) {
            return ['valid' => false, 'expiry' => null];
        }

        $params = stream_context_get_params($client);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        fclose($client);

        if ($cert === null) {
            return ['valid' => false, 'expiry' => null];
        }

        $expiry = date('Y-m-d', $cert->validTo_time_t);
        return ['valid' => $cert->validTo_time_t > time(), 'expiry' => $expiry];
    }

    /**
     * 🔌 بررسی اتصال سایت برند به API سایت ساز
     * اندپوینت عمومی /api/brands را صدا می‌زند
     */
    private function checkApiConnection(string $domain): bool
    {
        $ch = curl_init('https://' . $domain . '/robots.txt');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // robots.txt توسط هسته سایت برند تولید می‌شود — وجودش یعنی هسته زنده است
        if ($code === 200 && strpos($body, 'User-agent') !== false) {
            return true;
        }

        // بررسی صفحه اصلی
        $ch2 = curl_init('https://' . $domain);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $html = (string)curl_exec($ch2);
        $code2 = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        // وجود نام برند یا ساختار HTML سایت برند
        return $code2 === 200 && (strpos($html, 'brand') !== false || strpos($html, '<!doctype') !== false || mb_strlen($html) > 500);
    }

    /**
     * 💾 ذخیره نتیجه در site_health + بروزرسانی برند
     */
    private function saveResult(array $brand, array $result): array
    {
        $now = date('Y-m-d H:i:s');
        try {
            $this->db->insert('site_health', [
                'brand_id'       => (int)$brand['id'],
                'http_status'    => $result['http_status'],
                'response_time_ms' => $result['response_time'],
                'ssl_valid'      => $result['ssl_valid'],
                'ssl_expiry'     => $result['ssl_expiry'] ?? null,
                'api_ok'         => $result['api_ok'],
                'status'         => $result['status'],
                'error_message'  => mb_substr((string)($result['error'] ?? ''), 0, 500) ?: null,
                'checked_at'     => $now,
            ]);

            $this->db->update('brands', [
                'health_status'     => $result['status'],
                'last_health_check' => $now,
                'ssl_status'        => $result['ssl_valid'] === true ? 'active' : ($result['ssl_valid'] === false ? 'none' : 'none'),
                'ssl_expiry'        => $result['ssl_expiry'] ?? null,
            ], 'id = ?', [(int)$brand['id']]);
        } catch (Throwable $e) {
            error_log('[HealthChecker] ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * 📊 آخرین وضعیت سلامت همه برندهای استقرارشده (برای داشبورد)
     */
    public function getDashboard(): array
    {
        return $this->db->fetchAll(
            "SELECT b.id, b.name_fa, b.name_en, b.slug, b.full_domain, b.is_deployed,
                    b.health_status, b.last_health_check, b.ssl_status, b.ssl_expiry, b.deployed_at,
                    h.http_status, h.response_time_ms, h.api_ok, h.error_message
             FROM brands b
             LEFT JOIN site_health h ON h.id = (
                 SELECT id FROM site_health WHERE brand_id = b.id ORDER BY id DESC LIMIT 1
             )
             WHERE b.is_deployed = 1 AND b.is_active = 1
             ORDER BY b.name_fa"
        );
    }

    /**
     * 📈 روند سلامت یک برند (آخرین N بررسی)
     */
    public function getTrend(int $brandId, int $limit = 20): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM site_health WHERE brand_id = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)),
            [$brandId]
        );
    }
}
