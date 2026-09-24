<?php
/**
 * 🔌 کلاس ارتباط با cPanel UAPI
 * ==============================
 * تمام فراخوانی‌های cPanel از این کلاس عبور می‌کنند:
 *   - ساخت/حذف/لیست زیردامنه‌ها (SubDomain)
 *   - عملیات فایل (Fileman: mkdir، آپلود، استخراج، chmod، ...)
 *   - نصب SSL (AutoSSL / Let's Encrypt)
 *   - دامنه‌های اختصاصی (AddonDomain)
 *
 * 🔑 احراز هویت: API Token (هدر Authorization: cpanel user:token)
 * 📡 اندپوینت: https://{host}:{port}/execute/{Module}/{Function}
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class CpanelAPI
{
    /** @var array تنظیمات اتصال (از PathResolver::getSettings) */
    private $settings;

    /** @var string|null پیام خطای آخرین عملیات */
    private $lastError = null;

    /** @var array|null پاسخ خام آخرین فراخوانی (برای دیباگ) */
    private $lastResponse = null;

    /** @var int Timeout درخواست‌ها (ثانیه) */
    private $timeout;

    /** @var bool حالت دیباگ — ثبت جزئیات فراخوانی‌ها */
    private $debug;

    /**
     * 🔧 سازنده — دریافت تنظیمات اتصال
     *
     * @param array|null $settings تنظیمات (null = از دیتابیس خوانده می‌شود)
     */
    public function __construct(?array $settings = null)
    {
        $this->settings = $settings ?? PathResolver::getSettings();
        $this->timeout  = max(30, (int)($this->settings['timeout_seconds'] ?? 120));
        $this->debug    = defined('SAHAND_DEBUG') && SAHAND_DEBUG;
    }

    /* ==================================================
     * 📡 لایه ارتباط — UAPI Execute
     * ================================================== */

    /**
     * 📡 فراخوانی عمومی UAPI (POST با پارامترهای form-data)
     *
     * @param string $module  ماژول (مثلاً SubDomain)
     * @param string $function تابع (مثلاً addsubdomain)
     * @param array  $params  پارامترها
     * @param array  $files   فایل‌های multipart (نام => CURLFile)
     * @return array|false پاسخ data یا false در خطا
     */
    public function call(string $module, string $function, array $params = [], array $files = [])
    {
        $this->lastError = null;
        $this->lastResponse = null;

        $host = trim((string)($this->settings['cpanel_host'] ?? ''));
        $port = (int)($this->settings['cpanel_port'] ?? 2083);
        $proto = ($this->settings['cpanel_protocol'] ?? 'https') === 'http' ? 'http' : 'https';
        $user = trim((string)($this->settings['cpanel_username'] ?? ''));
        $token = DeployCrypto::decrypt((string)($this->settings['cpanel_token_enc'] ?? ''));

        // 🔍 بررسی پیش‌نیازهای اتصال
        if ($host === '' || $user === '' || $token === '') {
            $this->lastError = 'تنظیمات اتصال cPanel کامل نیست — آدرس سرور، نام کاربری و API Token الزامی است.';
            return false;
        }

        $url = sprintf('%s://%s:%d/execute/%s/%s', $proto, $host, $port, $module, $function);

        // 🧹 نرمال‌سازی مرکزی مسیرها — پارامترهای مسیر شناخته‌شده UAPI
        // (تضمین می‌کند همه فراخوانی‌ها حتی مستقوی، مسیر کامل /home/user داشته باشند)
        foreach (['path', 'dir', 'file', 'destfiles'] as $pk) {
            if (isset($params[$pk]) && is_string($params[$pk])) {
                $params[$pk] = $this->normalizePath($params[$pk]);
            }
        }
        if (isset($params['sourcefiles']) && is_string($params['sourcefiles'])) {
            $decoded = json_decode($params['sourcefiles'], true);
            if (is_array($decoded)) {
                $params['sourcefiles'] = json_encode(array_map(function ($p) {
                    return $this->normalizePath((string)$p);
                }, $decoded));
            } else {
                $params['sourcefiles'] = $this->normalizePath($params['sourcefiles']);
            }
        }

        // 📦 ساخت بدنه — قرارداد PHP cURL:
        //    فیلدهای معمولی: آرایه ساده key => value (خودکار multipart می‌شود)
        //    فایل‌ها: CURLFile (آپلود واقعی multipart)
        $postFields = [];
        foreach ($params as $key => $value) {
            // آرایه‌ها به صورت key[] (قرارداد UAPI) — با شماره‌گذاری یکتا
            if (is_array($value)) {
                $idx = 0;
                foreach ($value as $item) {
                    $postFields[$key . '[' . $idx++ . ']'] = (string)$item;
                }
            } else {
                $postFields[$key] = (string)$value;
            }
        }
        foreach ($files as $fieldName => $filePath) {
            if (file_exists((string)$filePath)) {
                $postFields[$fieldName] = new CURLFile($filePath, 'application/octet-stream', basename($filePath));
            }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            // 🔑 هدر احراز هویت توکن cPanel
            CURLOPT_HTTPHEADER     => ['Authorization: cpanel ' . $user . ':' . $token],
            // ⚠️ هاست اشتراکی — گواهی self-signed نباید کل فرآیند را متوقف کند
            // (توکن در هدر است و مسیر رمزنگاری‌شده HTTPS است)
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
        ]);

        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $this->lastError = 'خطای شبکه در ارتباط با cPanel: ' . ($curlError ?: 'پاسخی دریافت نشد');
            $this->logDebug('CALL-FAIL', $module . '/' . $function, ['curl_error' => $curlError]);
            return false;
        }

        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            $this->lastError = 'پاسخ cPanel قابل خواندن نیست (HTTP ' . $httpCode . ')';
            return false;
        }

        $this->lastResponse = $json;

        // ⚠️ خطای احراز هویت — راهنمای دقیق
        if ($httpCode === 401 || $httpCode === 403) {
            $this->lastError = 'دسترسی cPanel رد شد (HTTP ' . $httpCode . ') — API Token نامعتبر است یا دسترسی لازم را ندارد.';
            return false;
        }

        // UAPI موفق: status=1 و errors=null
        $status = (int)($json['status'] ?? 0);
        if ($status !== 1) {
            $errors = $json['errors'] ?? null;
            $msg = is_array($errors) ? implode(' | ', $errors) : (is_string($errors) && $errors !== '' ? $errors : '');
            $messages = $json['messages'] ?? null;
            if ($msg === '' && is_array($messages)) {
                $msg = implode(' | ', $messages);
            }
            $this->lastError = 'cPanel خطا برگرداند: ' . ($msg !== '' ? $msg : 'عملیات ناموفق بود (HTTP ' . $httpCode . ')');
            return false;
        }

        return $json['data'] ?? [];
    }

    /**
     * 🔍 تست اتصال به cPanel
     *
     * @return array [success => bool, message => string, data => array]
     */
    public function connect(): array
    {
        $data = $this->call('Version', 'version');
        if ($data === false) {
            return ['success' => false, 'message' => $this->lastError ?: 'اتصال برقرار نشد.', 'data' => []];
        }
        return [
            'success' => true,
            'message' => 'اتصال به cPanel برقرار است',
            'data'    => ['version' => (string)($data['version'] ?? '')],
        ];
    }

    /**
     * 📀 نسخه API سرور
     */
    public function getAPIVersion(): string
    {
        $data = $this->call('Version', 'version');
        return $data === false ? '' : (string)($data['version'] ?? '');
    }

    /**
     * 💽 فضای مصرفی دیسک
     */
    public function getDiskUsage(): array
    {
        $data = $this->call('Quota', 'get_disk_info');
        return $data === false ? [] : (array)$data;
    }

    /* ==================================================
     * 🌐 زیردامنه‌ها — SubDomain
     * ================================================== */

    /**
     * 🌐 ساخت زیردامنه جدید
     *
     * @param string $subdomain نام زیردامنه (مثلاً samsung)
     * @param string $rootDomain دامنه اصلی (مثلاً ea-fixer.ir)
     * @param string $dir مسیر Document Root (مثلاً /public_html/brands/samsung یا مسیر کامل)
     * @return array [success => bool, message => string]
     */
    public function createSubdomain(string $subdomain, string $rootDomain, string $dir): array
    {
        $data = $this->call('SubDomain', 'addsubdomain', [
            'domain'     => $subdomain,
            'rootdomain' => $rootDomain,
            'dir'        => $dir,
            // جلوگیری از ثبت دامنه‌های خطرناک چندنقطه‌ای
            'disallowdot' => 1,
        ]);
        if ($data === false) {
            return ['success' => false, 'message' => $this->lastError ?: 'ساخت زیردامنه ناموفق بود.'];
        }
        return ['success' => true, 'message' => 'زیردامنه ' . $subdomain . '.' . $rootDomain . ' ایجاد شد.'];
    }

    /**
     * 🗑️ حذف زیردامنه
     *
     * @param string $subdomain نام زیردامنه
     * @param string $rootDomain دامنه اصلی
     * @return array [success => bool, message => string]
     */
    public function deleteSubdomain(string $subdomain, string $rootDomain): array
    {
        $data = $this->call('SubDomain', 'delsubdomain', [
            'domain'     => $subdomain . '.' . $rootDomain,
        ]);
        if ($data === false) {
            return ['success' => false, 'message' => $this->lastError ?: 'حذف زیردامنه ناموفق بود.'];
        }
        return ['success' => true, 'message' => 'زیردامنه ' . $subdomain . '.' . $rootDomain . ' حذف شد.'];
    }

    /**
     * 📋 لیست زیردامنه‌ها
     *
     * @return array لیست (خالی در خطا — خطا در lastError)
     */
    public function listSubdomains(): array
    {
        $data = $this->call('SubDomain', 'listsubdomains');
        if ($data === false) {
            // 🔄 روش جایگزین: DomainInfo::list_domains (ساختار جدیدتر cPanel)
            $alt = $this->call('DomainInfo', 'list_domains');
            if ($alt === false) {
                return [];
            }
            $subs = $alt['sub_domains'] ?? [];
            $list = [];
            foreach ((array)$subs as $s) {
                $list[] = is_array($s) ? ($s['servername'] ?? $s['domain'] ?? '') : (string)$s;
            }
            return array_values(array_filter($list));
        }

        // پاسخ ممکن است آرایه مستقیم یا کلید subdomains باشد
        $items = isset($data['subdomains']) ? $data['subdomains'] : $data;
        $list = [];
        foreach ((array)$items as $item) {
            if (is_array($item)) {
                $list[] = (string)($item['servername'] ?? $item['domain'] ?? $item['subdomain'] ?? '');
            } else {
                $list[] = (string)$item;
            }
        }
        return array_values(array_filter($list));
    }

    /**
     * ❓ بررسی وجود زیردامنه
     *
     * @param string $fullSubdomain دامنه کامل (مثلاً samsung.ea-fixer.ir)
     */
    public function subdomainExists(string $fullSubdomain): bool
    {
        $list = $this->listSubdomains();
        $needle = strtolower(trim($fullSubdomain));
        foreach ($list as $item) {
            if (strtolower(trim($item)) === $needle) {
                return true;
            }
        }
        return false;
    }

    /* ==================================================
     * 🌐 دامنه‌های الحاقی — AddonDomain (v2.12)
     * ================================================== */

    /**
     * ➕ ساخت دامنه الحاقی (Addon Domain) جدید
     *
     * @param string $newDomain دامنه کامل (مثلاً lg-service.ir)
     * @param string $dir مسیر Document Root
     * @param string|null $subdomain پیشوند زیردامنه خودکار (خالی = از نام دامنه)
     * @return array [success => bool, message => string]
     */
    public function createAddonDomain(string $newDomain, string $dir, ?string $subdomain = null): array
    {
        $newDomain = strtolower(trim($newDomain));
        if ($subdomain === null || $subdomain === '') {
            // cPanel به‌طور خودکار زیردامنه‌ای با نام دامنه می‌سازد
            $subdomain = $newDomain;
        }
        $data = $this->call('AddonDomain', 'add_addon_domain', [
            'newdomain'  => $newDomain,
            'subdomain'  => $subdomain,
            'dir'        => $dir,
        ]);
        if ($data === false) {
            return ['success' => false, 'message' => $this->lastError ?: 'ساخت دامنه الحاقی ناموفق بود.'];
        }
        return ['success' => true, 'message' => 'دامنه الحاقی ' . $newDomain . ' ایجاد شد.'];
    }

    /**
     * 🗑️ حذف دامنه الحاقی
     */
    public function deleteAddonDomain(string $domain): array
    {
        $data = $this->call('AddonDomain', 'del_addon_domain', [
            'domain' => strtolower(trim($domain)),
        ]);
        if ($data === false) {
            return ['success' => false, 'message' => $this->lastError ?: 'حذف دامنه الحاقی ناموفق بود.'];
        }
        return ['success' => true, 'message' => 'دامنه الحاقی ' . $domain . ' حذف شد.'];
    }

    /**
     * 📋 لیست دامنه‌های الحاقی
     */
    public function listAddonDomains(): array
    {
        $data = $this->call('AddonDomain', 'list_addon_domains');
        if ($data === false) {
            // روش جایگزین: DomainInfo::list_domains
            $alt = $this->call('DomainInfo', 'list_domains');
            if ($alt === false) {
                return [];
            }
            $addons = $alt['addon_domains'] ?? [];
            $list = [];
            foreach ((array)$addons as $a) {
                $list[] = is_array($a) ? ($a['domain'] ?? $a['servername'] ?? '') : (string)$a;
            }
            return array_values(array_filter($list));
        }
        $items = isset($data['addons']) ? $data['addons'] : $data;
        $list = [];
        foreach ((array)$items as $item) {
            if (is_array($item)) {
                $list[] = (string)($item['domain'] ?? $item['servername'] ?? $item['full_domain'] ?? '');
            } else {
                $list[] = (string)$item;
            }
        }
        return array_values(array_filter($list));
    }

    /**
     * ❓ بررسی وجود دامنه الحاقی
     */
    public function addonDomainExists(string $domain): bool
    {
        $needle = strtolower(trim($domain));
        foreach ($this->listAddonDomains() as $item) {
            if (strtolower(trim($item)) === $needle) {
                return true;
            }
        }
        return false;
    }

    /* ==================================================
     * 📂 عملیات فایل — Fileman
     * ================================================== */

    /**
     * 📂 ساخت پوشه
     */
    public function createDirectory(string $path): bool
    {
        return $this->call('Fileman', 'mkdir', ['path' => $this->normalizePath($path)]) !== false;
    }

    /**
     * 📤 آپلود فایل (مثلاً ZIP سایت)
     * نام فیلدهای multipart طبق مستندات cPanel: upload-0، upload-1، ...
     *
     * @param string $localFile مسیر محلی فایل
     * @param string $remoteDir پوشه مقصد (مثلاً /public_html/brands/samsung)
     */
    public function uploadFile(string $localFile, string $remoteDir): bool
    {
        if (!file_exists($localFile)) {
            $this->lastError = 'فایل محلی برای آپلود یافت نشد: ' . basename($localFile);
            return false;
        }
        $data = $this->call('Fileman', 'upload_files', ['dir' => $this->normalizePath($remoteDir)], [
            'upload-0' => $localFile,
        ]);
        return $data !== false;
    }

    /**
     * 📦 استخراج ZIP در مسیر مقصد
     *
     * @param string $remoteZip مسیر ZIP روی سرور
     * @param string $destDir پوشه مقصد استخراج
     */
    public function extractZip(string $remoteZip, string $destDir): bool
    {
        $data = $this->call('Fileman', 'fileop', [
            'op'          => 'extract',
            'sourcefiles' => json_encode([$this->normalizePath($remoteZip)]),
            'destfiles'   => $this->normalizePath($destDir),
            'double_decode' => 1,
        ]);
        return $data !== false;
    }

    /**
     * 🗑️ حذف فایل یا پوشه
     */
    public function deleteFile(string $path): bool
    {
        $data = $this->call('Fileman', 'fileop', [
            'op'          => 'unlink',
            'sourcefiles' => json_encode([$this->normalizePath($path)]),
            'double_decode' => 1,
        ]);
        return $data !== false;
    }

    /**
     * 🗑️ حذف کامل پوشه (بازگشتی)
     */
    public function deleteDirectory(string $path): bool
    {
        return $this->deleteFile($path);
    }

    /**
     * 📋 لیست فایل‌های یک پوشه
     */
    public function listFiles(string $dir): array
    {
        $data = $this->call('Fileman', 'list_files', [
            'dir'    => $this->normalizePath($dir),
            'types'  => 'file,dir',
            'checkleaf' => 1,
        ]);
        if ($data === false) {
            return [];
        }
        // ساختار پاسخ: data => [files => [...]] یا مستقیم آرایه
        return isset($data['files']) && is_array($data['files']) ? $data['files'] : [];
    }

    /**
     * 🔐 تنظیم مجوز (chmod)
     *
     * @param string $path مسیر فایل/پوشه
     * @param string $mode مجوز هشتایی (مثلاً 0755)
     */
    public function setPermissions(string $path, string $mode): bool
    {
        // پاک‌سازی — فقط رقم هشتایی
        $mode = preg_replace('/[^0-7]/', '', $mode);
        if (strlen($mode) !== 3 && strlen($mode) !== 4) {
            $this->lastError = 'مجوز نامعتبر: ' . $mode;
            return false;
        }
        $data = $this->call('Fileman', 'chmod', [
            'file' => $this->normalizePath($path),
            'mode' => $mode,
        ]);
        return $data !== false;
    }

    /**
     * ✍️ نوشتن محتوا در فایل (تولید config.php و .htaccess)
     *
     * @param string $path مسیر کامل فایل مقصد
     * @param string $content محتوا
     */
    public function writeFile(string $path, string $content): bool
    {
        $data = $this->call('Fileman', 'save_file_content', [
            'file'          => $this->normalizePath($path),
            'content'       => $content,
            'from_charset'  => 'UTF-8',
            'to_charset'    => 'UTF-8',
            'fallback_list' => 'UTF-8',
        ]);
        return $data !== false;
    }

    /**
     * 📖 خواندن محتوای فایل (برای حفظ config.php هنگام بروزرسانی)
     *
     * @return string محتوا (خالی در خطا)
     */
    public function readFile(string $path): string
    {
        $data = $this->call('Fileman', 'get_file_content', [
            'file' => $this->normalizePath($path),
        ]);
        if ($data === false) {
            return '';
        }
        // محتوا ممکن است base64 باشد یا مستقیم
        $content = (string)($data['content'] ?? '');
        if (!empty($data['base64']) || preg_match('/^[A-Za-z0-9+\/=\s]+$/', $content) === 1) {
            $decoded = base64_decode($content, true);
            if ($decoded !== false && mb_check_encoding($decoded, 'UTF-8')) {
                return $decoded;
            }
        }
        return $content;
    }

    /* ==================================================
     * 🔒 SSL
     * ================================================== */

    /**
     * 📋 لیست گواهی‌های SSL نصب‌شده
     */
    public function listSSLCertificates(): array
    {
        $data = $this->call('SSL', 'list_ssl_certificates');
        return $data === false ? [] : (array)$data;
    }

    /**
     * ❓ بررسی وجود SSL فعال برای یک دامنه
     *
     * @param string $domain دامنه کامل (مثلاً samsung.ea-fixer.ir)
     * @return array [active => bool, expiry => string|null, issuer => string|null]
     */
    public function checkSSL(string $domain): array
    {
        $certs = $this->listSSLCertificates();
        $domain = strtolower(trim($domain));

        // جستجو در همه گواهی‌ها — دامنه‌ها یا در cpcert/domains هستند
        foreach ($certs as $cert) {
            if (!is_array($cert)) {
                continue;
            }
            $domains = [];
            foreach (['domains', 'cpcert', 'domains_on_cert'] as $key) {
                if (isset($cert[$key]) && is_array($cert[$key])) {
                    foreach ($cert[$key] as $d) {
                        $domains[] = strtolower((string)(is_array($d) ? ($d['servername'] ?? $d['domain'] ?? '') : $d));
                    }
                }
            }
            if (in_array($domain, array_filter($domains), true)) {
                return [
                    'active' => true,
                    'expiry' => $cert['not_after'] ?? null,
                    'issuer' => $cert['issuer']['organizationName'] ?? ($cert['issuer_common_name'] ?? null),
                ];
            }
        }
        return ['active' => false, 'expiry' => null, 'issuer' => null];
    }

    /**
     * 🔒 فعال‌سازی AutoSSL (فراهم‌کننده: cpanel یا letsencrypt)
     *
     * @param string $provider فراهم‌کننده (letsencrypt اگر در دسترس باشد، وگرنه cpanel)
     */
    public function enableAutoSSL(string $provider = 'letsencrypt'): bool
    {
        $ok = $this->call('SSL', 'enable_autossl', ['provider' => $provider]);
        if ($ok === false && $provider !== 'cpanel') {
            // 🔄 fallback به فراهم‌کننده داخلی cPanel
            $ok = $this->call('SSL', 'enable_autossl', ['provider' => 'cpanel']);
        }
        return $ok !== false;
    }

    /**
     * 🚀 اجرای اسکن AutoSSL برای صدور گواهی دامنه‌های جدید
     */
    public function startAutoSSLScan(): bool
    {
        return $this->call('SSL', 'start_autossl_scan') !== false;
    }

    /**
     * 🔒 نصب گواهی SSL (Let's Encrypt از طریق AutoSSL)
     *
     * @param string $domain دامنه کامل
     * @param string $provider فراهم‌کننده
     * @return array [success => bool, message => string]
     */
    public function installSSL(string $domain, string $provider = 'letsencrypt'): array
    {
        // ۱. بررسی گواهی موجود
        $existing = $this->checkSSL($domain);
        if ($existing['active']) {
            return ['success' => true, 'message' => 'SSL از قبل برای این دامنه فعال است.'];
        }

        // ۲. فعال‌سازی AutoSSL
        if (!$this->enableAutoSSL($provider)) {
            return ['success' => false, 'message' => 'فعال‌سازی AutoSSL ناموفق بود: ' . $this->lastError];
        }

        // ۳. اجرای اسکن صدور
        if (!$this->startAutoSSLScan()) {
            return ['success' => false, 'message' => 'شروع اسکن AutoSSL ناموفق بود: ' . $this->lastError];
        }

        return ['success' => true, 'message' => 'درخواست صدور SSL ثبت شد — صدور معمولاً تا ۵ دقیقه طول می‌کشد.'];
    }

    /* ==================================================
     * 🌍 دامنه‌های اختصاصی — Addon Domain
     * ================================================== */

    /**
     * 📋 لیست همه دامنه‌ها (اصلی + زیر + افزوده)
     */
    public function listDomains(): array
    {
        $data = $this->call('DomainInfo', 'list_domains');
        return $data === false ? [] : (array)$data;
    }

    /**
     * ➕ افزودن دامنه اختصاصی (Addon Domain)
     *
     * @param string $domain دامنه کامل (مثلاً samsung-repair.ir)
     * @param string $subdomain نام زیردامنه موقت که cPanel می‌سازد
     * @param string $dir مسیر Document Root
     */
    public function addAddonDomain(string $domain, string $subdomain, string $dir): array
    {
        $data = $this->call('AddonDomain', 'add_addon_domain', [
            'domain'    => $domain,
            'subdomain' => $subdomain,
            'dir'       => $this->normalizePath($dir),
        ]);
        if ($data === false) {
            return ['success' => false, 'message' => $this->lastError ?: 'افزودن دامنه اختصاصی ناموفق بود.'];
        }
        return ['success' => true, 'message' => 'دامنه اختصاصی ' . $domain . ' افزوده شد — رکوردهای DNS را تنظیم کنید.'];
    }

    /* ==================================================
     * 🛠️ ابزارهای کمکی
     * ================================================== */

    /**
     * 🧹 نرمال‌سازی مسیر — پشتیبانی از هر دو فرم:
     *   /public_html/brands/x (نسبی به home)
     *   /home/user/public_html/brands/x (کامل)
     * UAPI مسیرهای کامل می‌پذیرد؛ مسیر نسبی به مسیر کامل تبدیل می‌شود
     */
    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return $path;
        }

        // اگر مسیر کامل است (شامل /home/...) — بدون تغییر
        if (preg_match('#^/home/[^/]+#', $path)) {
            return $path;
        }

        // مسیر نسبی — پیشوند home کاربر اضافه شود
        $user = (string)($this->settings['cpanel_username'] ?? '');
        if ($user !== '') {
            return '/home/' . $user . $path;
        }
        return $path;
    }

    /**
     * ❌ آخرین خطا
     */
    public function getLastError(): string
    {
        return $this->lastError ?: '';
    }

    /**
     * 📋 آخرین پاسخ خام (دیباگ)
     */
    public function getLastResponse(): ?array
    {
        return $this->lastResponse;
    }

    /**
     * 📝 ثبت دیباگ
     */
    private function logDebug(string $tag, string $op, array $context = []): void
    {
        if ($this->debug && class_exists('Logger')) {
            Logger::log('DEBUG', '[CpanelAPI:' . $tag . '] ' . $op, $context);
        }
    }
}
