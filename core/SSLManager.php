<?php
/**
 * 🔒 مدیر SSL — نصب، بررسی و تمدید خودکار
 * =======================================
 * طبق سند بخش ۲۱ (بخش ۷):
 *   ۱. بررسی وجود SSL فعال (SSL::list_ssl_certificates)
 *   ۲. فعال‌سازی AutoSSL (Let's Encrypt یا cPanel)
 *   ۳. درخواست صدور گواهی
 *   ۴. انتظار برای صدور (Polling هر ۳۰ ثانیه)
 *   ۵. تأیید نصب (بررسی HTTPS response)
 *   ۶. ثبت در دیتابیس (وضعیت + تاریخ انقضا)
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class SSLManager
{
    /** @var CpanelAPI کلاینت cPanel */
    private $api;

    /** @var Database اتصال دیتابیس */
    private $db;

    /** @var string فراهم‌کننده پیش‌فرض */
    private $provider;

    /**
     * 🔧 سازنده
     */
    public function __construct(?CpanelAPI $api = null)
    {
        $this->api = $api ?? new CpanelAPI();
        $this->db  = Database::getInstance();
        $settings  = PathResolver::getSettings();
        $this->provider = in_array($settings['ssl_provider'] ?? '', ['cpanel', 'letsencrypt'], true)
            ? $settings['ssl_provider']
            : 'letsencrypt';
    }

    /**
     * ❓ بررسی وضعیت SSL یک دامنه
     *
     * @param string $domain دامنه کامل
     * @return array [active, expiry, issuer, days_left]
     */
    public function checkSSL(string $domain): array
    {
        // ۱. از cPanel API — v2.18: SSL::list_ssl_items (معادل رسمی UAPI)
        $cert = $this->api->checkSSL($domain);
        if ($cert['active']) {
            $normalized = $this->normalizeCert($cert, $domain);

            /* ✍️ v2.18: list_ssl_items تاریخ انقضا/صادرکننده نمی‌دهد —
               جزئیات از اتصال مستقیم HTTPS تکمیل می‌شود (در صورت برقراری) */
            if (empty($normalized['expiry']) || empty($normalized['issuer'])) {
                $direct = $this->checkViaHttps($domain);
                if ($direct['active']) {
                    $normalized['expiry']    = $normalized['expiry'] ?: $direct['expiry'];
                    $normalized['issuer']    = $normalized['issuer'] ?: $direct['issuer'];
                    $normalized['days_left'] = $direct['days_left'] ?? $normalized['days_left'];
                }
            }
            return $normalized;
        }

        // ۲. بررسی مستقیم HTTPS (اگر cPanel پاسخ نداد)
        $direct = $this->checkViaHttps($domain);
        if ($direct['active']) {
            return $direct;
        }

        return ['active' => false, 'expiry' => null, 'issuer' => null, 'days_left' => null];
    }

    /**
     * 🔒 نصب SSL برای دامنه (AutoSSL)
     *
     * @param string $domain دامنه کامل
     * @param int|null $deploymentId شناسه عملیات برای لاگ مراحل
     * @return array [success => bool, message => string, expiry => string|null]
     */
    public function installSSL(string $domain, ?int $deploymentId = null): array
    {
        $logger = $deploymentId ? new DeploymentLogger() : null;

        // ۱. اگر فعال است — بدون تغییر
        $existing = $this->checkSSL($domain);
        if ($existing['active']) {
            if ($logger) {
                $logger->step($deploymentId, 'ssl_check', 'SSL از قبل فعال است (انقضا: ' . ($existing['expiry'] ?: 'نامشخص') . ')');
            }
            $this->saveToDb($domain, 'active', $existing);
            return ['success' => true, 'message' => 'SSL از قبل فعال است.', 'expiry' => $existing['expiry']];
        }

        // ۲. فعال‌سازی AutoSSL + اسکن
        if ($logger) {
            $logger->step($deploymentId, 'ssl_request', 'درخواست صدور SSL از ' . $this->providerLabel());
        }
        $result = $this->api->installSSL($domain, $this->provider);
        if (!$result['success']) {
            if ($logger) {
                $logger->stepSkipped($deploymentId, 'ssl_failed', 'SSL نصب نشد: ' . $result['message'] . ' — استقرار بدون SSL ادامه می‌یابد.');
            }
            $this->saveToDb($domain, 'none', []);
            return ['success' => false, 'message' => $result['message'], 'expiry' => null];
        }

        // ۳. ثبت وضعیت pending — polling در مرحله بعدی
        $this->saveToDb($domain, 'pending', []);
        return ['success' => true, 'message' => 'درخواست صدور SSL ثبت شد.', 'expiry' => null];
    }

    /**
     * ⏳ انتظار برای صدور گواهی (Polling)
     *
     * @param string $domain دامنه کامل
     * @param int    $maxWaitSeconds حداکثر انتظار (پیش‌فرض ۳۰۰ ثانیه = ۵ دقیقه طبق سند)
     * @param int|null $deploymentId شناسه عملیات
     * @return array [success, expiry, message]
     */
    public function waitForCertificate(string $domain, int $maxWaitSeconds = 300, ?int $deploymentId = null): array
    {
        $logger = $deploymentId ? new DeploymentLogger() : null;
        $interval = 30; // هر ۳۰ ثانیه طبق سند
        $elapsed = 0;

        while ($elapsed < $maxWaitSeconds) {
            $cert = $this->checkSSL($domain);
            if ($cert['active']) {
                if ($logger) {
                    $logger->step($deploymentId, 'ssl_active', 'SSL فعال شد (انقضا: ' . ($cert['expiry'] ?: 'نامشخص') . ')');
                }
                $this->saveToDb($domain, 'active', $cert);
                return ['success' => true, 'expiry' => $cert['expiry'], 'message' => 'SSL فعال شد.'];
            }
            sleep(min($interval, $maxWaitSeconds - $elapsed));
            $elapsed += $interval;
        }

        // ⏳ زمان تمام شد — اما AutoSSL ممکن است بعداً صادر کند
        if ($logger) {
            $logger->stepSkipped($deploymentId, 'ssl_pending', 'صدور SSL بیش از ' . (int)($maxWaitSeconds / 60) . ' دقیقه طول کشید — AutoSSL بعداً آن را نصب می‌کند.');
        }
        $this->saveToDb($domain, 'pending', []);
        return ['success' => false, 'expiry' => null, 'message' => 'صدور SSL هنوز کامل نشده — بعداً بررسی می‌شود.'];
    }

    /**
     * 🔄 تمدید خودکار — AutoSSL خود cPanel انجام می‌دهد
     * این متد فقط وضعیت را بروزرسانی و هشدار می‌دهد
     *
     * @param int $brandId شناسه برند
     * @return array [needs_renewal => bool, days_left => int|null, message => string]
     */
    public function checkRenewal(int $brandId): array
    {
        $brand = $this->db->fetch('SELECT full_domain, ssl_status, ssl_expiry FROM brands WHERE id = ?', [$brandId]);
        if (!$brand || empty($brand['full_domain'])) {
            return ['needs_renewal' => false, 'days_left' => null, 'message' => 'برند استقرار ندارد.'];
        }

        $cert = $this->checkSSL((string)$brand['full_domain']);
        if (!$cert['active']) {
            // SSL از دست رفته — نیاز به نصب مجدد
            $this->db->update('brands', ['ssl_status' => 'none', 'ssl_expiry' => null], 'id = ?', [$brandId]);
            $this->saveToDb((string)$brand['full_domain'], 'none', []);
            return ['needs_renewal' => true, 'days_left' => null, 'message' => 'SSL غیرفعال است — نیاز به نصب مجدد.'];
        }

        $daysLeft = $cert['days_left'];
        $this->db->update('brands', [
            'ssl_status' => 'active',
            'ssl_expiry' => $cert['expiry'] ? substr($cert['expiry'], 0, 10) : null,
        ], 'id = ?', [$brandId]);
        $this->saveToDb((string)$brand['full_domain'], 'active', $cert);

        // ⚠️ هشدار ۳۰ روز قبل از انقضا (طبق سند)
        if ($daysLeft !== null && $daysLeft <= 30) {
            return [
                'needs_renewal' => true,
                'days_left'     => $daysLeft,
                'message'       => 'SSL این برند تا ' . $daysLeft . ' روز دیگر منقضی می‌شود — AutoSSL باید آن را تمدید کند.',
            ];
        }

        return ['needs_renewal' => false, 'days_left' => $daysLeft, 'message' => 'SSL سالم است.'];
    }

    /**
     * 📥 بررسی مستقیم HTTPS برای دامنه (fallback)
     */
    private function checkViaHttps(string $domain): array
    {
        $ch = curl_init('https://' . $domain);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true, // HEAD
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        curl_exec($ch);
        $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
        $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) > 0 && curl_errno($ch) === 0;
        curl_close($ch);

        if (!$ok || empty($certInfo)) {
            return ['active' => false, 'expiry' => null, 'issuer' => null, 'days_left' => null];
        }

        // استخراج تاریخ انقضا از گواهی
        $expiry = null;
        $issuer = null;
        if (!empty($certInfo[0]['Subject'])) {
            // جستجوی خط issuer
        }
        // تلاش با openssl برای دقت بیشتر — سازگار با PHP 8.3+
        $remote = @stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'peer_name' => $domain]]);
        $client = @stream_socket_client('ssl://' . $domain . ':443', $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $remote);
        if ($client) {
            $params = stream_context_get_params($client);
            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            if ($cert) {
                $parsed = @openssl_x509_parse($cert);
                if (is_array($parsed)) {
                    $expiry = !empty($parsed['validTo_time_t']) ? date('Y-m-d H:i:s', $parsed['validTo_time_t']) : null;
                    $issuerName = $parsed['issuer'] ?? [];
                    $issuer = $issuerName['organizationName'] ?? $issuerName['commonName'] ?? null;
                }
            }
            fclose($client);
        }

        $daysLeft = $expiry ? (int)floor((strtotime($expiry) - time()) / 86400) : null;
        return ['active' => true, 'expiry' => $expiry, 'issuer' => $issuer, 'days_left' => $daysLeft];
    }

    /**
     * 🧹 یکسان‌سازی ساختار گواهی
     */
    private function normalizeCert(array $cert, string $domain): array
    {
        $expiry = $cert['expiry'] ?? null;
        // فرمت cPanel: YYYY-MM-DD HH:MM:SS یا timestamp
        if ($expiry && is_numeric($expiry)) {
            $expiry = date('Y-m-d H:i:s', (int)$expiry);
        }
        $daysLeft = $expiry ? (int)floor((strtotime((string)$expiry) - time()) / 86400) : null;
        return [
            'active'    => true,
            'expiry'    => $expiry ? (string)$expiry : null,
            'issuer'    => $cert['issuer'] ?? null,
            'days_left' => $daysLeft,
        ];
    }

    /**
     * 💾 ثبت وضعیت SSL در جدول ssl_certificates + برند
     */
    private function saveToDb(string $domain, string $status, array $cert): void
    {
        try {
            // 🔎 رکورد موجود برای این دامنه؟
            $existing = $this->db->fetch('SELECT id FROM ssl_certificates WHERE domain = ? LIMIT 1', [$domain]);

            $data = [
                'status'     => $status,
                'issuer'     => $cert['issuer'] ?? null,
                'expires_at' => !empty($cert['expiry']) ? $cert['expiry'] : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($existing) {
                $this->db->update('ssl_certificates', $data, 'id = ?', [(int)$existing['id']]);
            } else {
                // برند مرتبط از روی دامنه
                $brand = $this->db->fetch('SELECT id FROM brands WHERE full_domain = ? LIMIT 1', [$domain]);
                $data['brand_id'] = (int)($brand['id'] ?? 0);
                $data['domain'] = $domain;
                // 🔑 ستون created_at وجود ندارد — updated_at مقدار پیش‌فرض دارد
                $this->db->insert('ssl_certificates', $data);
            }
        } catch (Throwable $e) {
            error_log('[SSLManager] ' . $e->getMessage());
        }
    }

    /**
     * 🏷️ نام فارسی فراهم‌کننده
     */
    private function providerLabel(): string
    {
        return $this->provider === 'cpanel' ? 'AutoSSL داخلی cPanel' : "Let's Encrypt";
    }
}
