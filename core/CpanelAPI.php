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
 * @version 2.19.0
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

    /** @var string مرحله شکست آخرین استخراج — 'api' (فراخوانی cPanel) یا 'settle' (مکان‌یابی/راستی‌آزمایی) — v2.19 */
    private $lastExtractStage = '';

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

        /* 🛡️ v2.19: پارس هر دو قالب پاسخ UAPI:
         *   قدیمی (تخت):    {status, data, errors, messages, ...}
         *   جدید (تو-در-تو): {apiversion, module, func, result: {status, data, errors, messages}}
         * مستندات رسمی (cpanel.openapi 11.138) قالب تو-در-تو را تعریف می‌کند؛
         * سرورهای قدیمی قالب تخت برمی‌گردانند — هر دو پارس می‌شوند تا سیستم
         * هم روی هاست فعلی و هم پس از ارتقای سرور کار کند. */
        $payload = isset($json['result']) && is_array($json['result']) ? $json['result'] : $json;

        // UAPI موفق: status=1 و errors=null
        $status = (int)($payload['status'] ?? 0);
        if ($status !== 1) {
            $errors = $payload['errors'] ?? null;
            $msg = is_array($errors) ? implode(' | ', $errors) : (is_string($errors) && $errors !== '' ? $errors : '');
            $messages = $payload['messages'] ?? null;
            if ($msg === '' && is_array($messages)) {
                $msg = implode(' | ', $messages);
            }
            $this->lastError = 'cPanel خطا برگرداند: ' . ($msg !== '' ? $msg : 'عملیات ناموفق بود (HTTP ' . $httpCode . ')');
            return false;
        }

        return is_array($payload['data'] ?? null) ? $payload['data'] : [];
    }

    /**
     * 📡 فراخوانی مستقیم cPanel API 2 (سازگاری با ماژول‌های بدون معادل UAPI)
     * =====================================================================
     * برخی ماژول‌ها (مثل Cron) طبق مستندات رسمی cPanel «معادل UAPI ندارند»
     * و فقط از طریق API2 قابل فراخوانی‌اند:
     *   POST /json-api/cpanel
     *     cpanel_jsonapi_user        = نام کاربری cPanel
     *     cpanel_jsonapi_apiversion  = 2
     *     cpanel_jsonapi_module      = ماژول (مثلاً Cron)
     *     cpanel_jsonapi_func        = تابع (مثلاً add_line)
     *
     * ⚠️ چرا مسیر /execute/ (UAPI) کافی نیست؟
     *    پل سازگاری UAPI→API2 در برخی نسخه‌های جدید cPanel (پرل ۵.۴۲+) با خطای
     *    «Can't locate Cpanel/API/X.pm in @INC» شکست می‌خورد چون در eval محدودی
     *    اجرا می‌شود؛ اما فراخوانی مستقیم API2 از دیسپچر رسمی آن عبور می‌کند.
     *
     * @param string $module ماژول API2 (مثلاً Cron)
     * @param string $func   تابع API2 (مثلاً add_line)
     * @param array  $params پارامترهای تابع
     * @return array|false آرایه data در موفقیت، false در خطا (خطا در lastError)
     */
    public function callApi2(string $module, string $func, array $params = [])
    {
        $this->lastError = null;
        $this->lastResponse = null;

        $host = trim((string)($this->settings['cpanel_host'] ?? ''));
        $port = (int)($this->settings['cpanel_port'] ?? 2083);
        $proto = ($this->settings['cpanel_protocol'] ?? 'https') === 'http' ? 'http' : 'https';
        $user = trim((string)($this->settings['cpanel_username'] ?? ''));
        $token = DeployCrypto::decrypt((string)($this->settings['cpanel_token_enc'] ?? ''));

        if ($host === '' || $user === '' || $token === '') {
            $this->lastError = 'تنظیمات اتصال cPanel کامل نیست — آدرس سرور، نام کاربری و API Token الزامی است.';
            return false;
        }

        $url = sprintf('%s://%s:%d/json-api/cpanel', $proto, $host, $port);

        /* 📦 پارامترهای استاندارد API2 + پارامترهای تابع */
        $postFields = [
            'cpanel_jsonapi_user'       => $user,
            'cpanel_jsonapi_apiversion' => 2,
            'cpanel_jsonapi_module'     => $module,
            'cpanel_jsonapi_func'       => $func,
        ];
        foreach ($params as $key => $value) {
            $postFields[$key] = is_scalar($value) ? (string)$value : json_encode($value);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postFields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: cpanel ' . $user . ':' . $token,
                'Content-Type: application/x-www-form-urlencoded',
            ],
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
            $this->lastError = 'خطای شبکه در ارتباط با cPanel (API2): ' . ($curlError ?: 'پاسخی دریافت نشد');
            return false;
        }

        $json = json_decode((string)$body, true);
        if (!is_array($json) || !isset($json['cpanelresult'])) {
            $this->lastError = 'پاسخ API2 قابل خواندن نیست (HTTP ' . $httpCode . ')';
            return false;
        }

        $this->lastResponse = $json;

        if ($httpCode === 401 || $httpCode === 403) {
            $this->lastError = 'دسترسی cPanel رد شد (HTTP ' . $httpCode . ') — API Token نامعتبر است یا دسترسی لازم را ندارد.';
            return false;
        }

        $result = $json['cpanelresult'];

        /* ✅ موفقیت API2: event.result = 1 */
        $eventResult = (int)($result['event']['result'] ?? 0);
        if ($eventResult === 1) {
            return is_array($result['data'] ?? null) ? $result['data'] : [];
        }

        /* ❌ خطا — از چند مکان ممکن استخراج شود */
        $msg = (string)($result['error'] ?? '');
        if ($msg === '') {
            $data0 = is_array($result['data'][0] ?? null) ? $result['data'][0] : [];
            if (isset($data0['status']) && (int)$data0['status'] === 0) {
                $msg = (string)($data0['statusmsg'] ?? '');
            }
        }
        $this->lastError = 'cPanel (API2) خطا برگرداند: ' . ($msg !== '' ? $msg : 'عملیات ناموفق بود (HTTP ' . $httpCode . ')');
        return false;
    }

    /**
     * 🔍 تست اتصال به cPanel
     *
     * ⚠️ برخی سرورها ماژول Cpanel::API::Version را ندارند؛
     *    بنابراین زنجیره‌ای از ماژول‌های پرکاربرد امتحان می‌شود و
     *    موفقیت هر کدام به معنای برقراری اتصال و اعتبار توکن است.
     *
     * @return array [success => bool, message => string, data => array]
     */
    public function connect(): array
    {
        // 🎯 ماژول‌های امتحانی به‌ترتیب — اولین موفقیت = اتصال سالم
        // 📌 v2.18: همه‌ی توابع واقعاً موجود در UAPI (طبق فهرست رسمی cpanel.openapi):
        //    Version/version و Quota/get_disk_info و Branding/get_application_name در UAPI نیستند!
        $probes = [
            ['module' => 'Variables',   'function' => 'get_server_information', 'label' => 'اطلاعات سرور'],
            ['module' => 'Quota',       'function' => 'get_local_quota_info',   'label' => 'اطلاعات دیسک'],
            ['module' => 'Branding',    'function' => 'get_applications',       'label' => 'پنل'],
            ['module' => 'DomainInfo',  'function' => 'list_domains',           'label' => 'دامنه‌ها'],
        ];

        $errors = [];
        foreach ($probes as $probe) {
            $data = $this->call($probe['module'], $probe['function']);
            if ($data !== false) {
                // 🎉 یکی از ماژول‌ها جواب داد — اتصال و توکن معتبر است
                $version = (string)($data['version'] ?? '');
                $server  = (string)($data['server_host'] ?? $data['hostname'] ?? '');
                $app     = (string)($data['application_name'] ?? '');

                $extra = [];
                if ($version !== '') $extra[] = 'نسخه: ' . $version;
                if ($server  !== '') $extra[] = 'هاست: ' . $server;
                if ($app     !== '') $extra[] = 'پنل: ' . $app;

                return [
                    'success' => true,
                    'message' => '✅ اتصال به cPanel برقرار است' . ($extra !== [] ? ' (' . implode(' — ', $extra) . ')' : ''),
                    'data'    => array_merge(is_array($data) ? $data : [], ['probe' => $probe['module']]),
                ];
            }
            $errors[] = $probe['module'] . ': ' . ($this->lastError ?: 'ناموفق');
        }

        return [
            'success' => false,
            'message' => '❌ ' . ($this->lastError ?: 'اتصال برقرار نشد.') . "\n\n🔍 ماژول‌های امتحان‌شده:\n" . implode("\n", $errors),
            'data'    => [],
        ];
    }

    /**
     * 📀 نسخه API سرور — با fallback روی چند ماژول
     */
    public function getAPIVersion(): string
    {
        foreach ([['Version', 'version'], ['Variables', 'get_server_information'], ['Quota', 'get_disk_info']] as [$m, $f]) {
            $data = $this->call($m, $f);
            if ($data !== false && !empty($data['version'])) {
                return (string)$data['version'];
            }
        }
        return '';
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
     * 📂 ساخت پوشه — v2.16 بازنویسی ریشه‌ای
     * ================================
     * 🔴 ریشه‌یابی خطای «The system could not find the function “mkdir” in the module “Fileman”»:
     *    طبق مستندات رسمی cPanel (api.docs.cpanel.net — Fileman::mkdir):
     *    «We strongly recommend that you use UAPI instead of cPanel API 2.
     *     However, no equivalent UAPI function exists.»
     *    یعنی تابع mkdir در UAPI **هرگز وجود نداشته** — فراخوانی /execute/Fileman/mkdir
     *    روی همه سرورهای cPanel با خطای «تابع پیدا نشد» شکست می‌خورد.
     *
     * ✅ روش صحیح (طبق مستندات): API2 Fileman::mkdir با پارامترها:
     *    path        = مسیر مطلق پوشه والد (مثلاً /home/user/public_html/brands/samsung)
     *    name        = نام پوشه جدید (مثلاً css)
     *    permissions = مجوز هشتایی (0755)
     *
     * 🛡️ زنجیره ضدگلوله:
     *    ① API2 مستقیم (والد موجود — حالت رایج استقرار)
     *    ② ساخت بازگشتی والد+فرزند (برای مسیرهای تودرتو مثل پوشه بکاپ)
     *    ③ راستی‌آزمایی وجود (اگر پوشه از قبل هست → موفق)
     */
    public function createDirectory(string $path): bool
    {
        $path = rtrim($this->normalizePath($path), '/');
        if ($path === '' || $path === '/') {
            return true;
        }

        $errors = [];

        /* ① API2 مستقیم — والد موجود است (حالت رایج: زیردامنه پوشه ریشه را ساخته) */
        if ($this->mkdirApi2($path)) {
            return true;
        }
        $errors[] = 'API2: ' . ($this->lastError ?: 'ناموفق');

        /* ② والد هم وجود ندارد؟ — ساخت بازگشتی سطح‌به‌سطح */
        $parent = rtrim(dirname($path), '/');
        if ($parent !== $path && $parent !== '' && $parent !== '/' && $parent !== '\\') {
            if ($this->createDirectory($parent) && $this->mkdirApi2($path)) {
                return true;
            }
            $errors[] = 'بازگشتی: والد یا فرزند ساخته نشد';
        }

        /* ③ راستی‌آزمایی نهایی — شاید پوشه از قبل موجود بوده است */
        if ($this->entryExists($path)) {
            return true;
        }

        $this->lastError = 'ساخت پوشه «' . basename($path) . '» ناموفق — ' . implode(' | ', array_filter($errors));
        return false;
    }

    /**
     * 📂 ساخت یک سطح پوشه از طریق API2 Fileman::mkdir (تنها مسیر رسمی)
     *
     * @param string $fullPath مسیر مطلق کامل پوشه موردنظر (والد باید موجود باشد)
     */
    private function mkdirApi2(string $fullPath): bool
    {
        $name = basename($fullPath);
        if ($name === '' || $name === '/' || $name === '.') {
            $this->lastError = 'نام پوشه نامعتبر است.';
            return false;
        }
        return $this->callApi2('Fileman', 'mkdir', [
            'path'        => rtrim(dirname($fullPath), '/'),
            'name'        => $name,
            'permissions' => '0755',
        ]) !== false;
    }

    /**
     * ❓ بررسی وجود فایل/پوشه در مسیر (از طریق لیست پوشه والد — UAPI list_files)
     *
     * @param string      $path مسیر مطلق موردنظر
     * @param string|null $type محدود کردن به نوع ('dir' یا 'file') — null = هر دو
     */
    public function entryExists(string $path, ?string $type = null): bool
    {
        $path   = $this->normalizePath($path);
        $parent = $this->normalizePath(dirname($path));
        $name   = basename(rtrim($path, '/'));

        if ($name === '' || $name === '/') {
            return false;
        }

        $files = $this->listFiles($parent);
        foreach ($files as $f) {
            $fname = (string)($f['file'] ?? $f['name'] ?? '');
            if ($fname === $name) {
                return $type === null || (string)($f['type'] ?? '') === $type;
            }
        }
        return false;
    }

    /**
     * 📤 آپلود فایل (مثلاً ZIP سایت) — v2.18
     * نام فیلدهای multipart طبق مستندات رسمی cPanel: file-0، file-1، ...
     * (سامانه فایل را بر اساس ویژگی filename همان part ذخیره می‌کند)
     * 🛡️ دو قرارداد امتحان می‌شود: file-0 (مستندات) سپس upload-0 (اثبات‌شده)
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
        /* 🛡️ v2.19: دو قرارداد فیلد (file-0 مستندات + upload-0 اثبات‌شده)
         *    × دو قالب مسیر (مطلق + نسبی از home — مثال‌های رسمی نسبی‌اند) */
        foreach ([$this->normalizePath($remoteDir), $this->homeRelative($remoteDir)] as $dir) {
            foreach (['file-0', 'upload-0'] as $field) {
                $data = $this->call('Fileman', 'upload_files', ['dir' => $dir], [
                    $field => $localFile,
                ]);
                if ($data !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * ⚙️ fileOp — هلپر مرکزی عملیات فایل (v2.18 — بازنویسی ریشه‌ای)
     * ============================================================
     * 🔴 ریشه‌یابی خطای «The system could not find the function “fileop” in the module “Fileman”»:
     *    طبق مستندات رسمی cPanel (api.docs.cpanel.net — API2 Fileman::fileop):
     *    «We strongly recommend that you use UAPI instead of cPanel API 2.
     *     However, no equivalent UAPI function exists.»
     *    یعنی fileop فقط در API2 وجود دارد — فراخوانی /execute/Fileman/fileop (UAPI)
     *    روی همه سرورها با خطای «تابع پیدا نشد» شکست می‌خورد.
     *
     * 🐛 سه باگ هم‌زمان در پیاده‌سازی قبلی:
     *    ① فراخوانی از UAPI به‌جای API2
     *    ② sourcefiles به‌صورت JSON ارسال می‌شد — مستندات: لیست جداشده با کاما
     *    ③ نام پارامتر double_decode بود — مستندات: doubledecode
     *
     * ✅ امضای رسمی (API2 Fileman::fileop):
     *    op           = extract | compress | copy | move | rename | chmod | link | unlink | trash | restorefile
     *    sourcefiles  = لیست فایل‌ها جداشده با کاما (الزامی)
     *    destfiles    = مقصد (برای copy/move/rename)
     *    doubledecode = 0/1 (الزامی)
     *    metadata     = برای compress: نوع آرشیو (zip) — برای chmod: مجوز هشتایی (0755)
     *    پاسخ: data[0] = {dest, src, output, err, result}
     *
     * 🛡️ دو تلاش: مسیر مطلق (/home/user/...) سپس مسیر نسبی از home (طبق مثال‌های رسمی)
     *
     * @return array [موفق(bool), ردیف پاسخ data[0] یا null]
     */
    private function fileOp(string $op, array $sourcefiles, string $destfiles = '', string $metadata = ''): array
    {
        $sourcefiles = array_values(array_filter(array_map('strval', $sourcefiles)));
        if ($sourcefiles === []) {
            $this->lastError = 'فایلی برای عملیات «' . $op . '» مشخص نشده است.';
            return [false, null];
        }

        /* 🛡️ v2.19: دو قالب مسیر — مطلق سپس نسبی از home (طبق مثال‌های رسمی) */
        $abs = array_map(function ($p) {
            return $this->normalizePath($p);
        }, $sourcefiles);
        $rel = array_map(function ($p) {
            return $this->homeRelative($p);
        }, $sourcefiles);
        $attempts = $abs === $rel ? [$abs] : [$abs, $rel];

        $lastErr = '';
        foreach ($attempts as $files) {
            $params = [
                'op'           => $op,
                'sourcefiles'  => implode(',', $files),
                'doubledecode' => 1,
            ];
            if ($destfiles !== '') {
                $params['destfiles'] = $this->normalizePath($destfiles);
            }
            if ($metadata !== '') {
                $params['metadata'] = $metadata;
            }

            $data = $this->callApi2('Fileman', 'fileop', $params);
            if ($data !== false) {
                /* ✅ API2 موفق — بررسی نتیجه سطح فایل (result/err داخل data[0]) */
                $row = is_array($data[0] ?? null) ? $data[0] : [];
                if ((int)($row['result'] ?? 1) === 1 && trim((string)($row['err'] ?? '')) === '') {
                    return [true, $row];
                }
                $lastErr = trim((string)($row['err'] ?? ''));
                if ($lastErr === '') {
                    $lastErr = 'نتیجه عملیات صفر بود';
                }
                continue; /* تلاش با قالب مسیر دیگر */
            }
            $lastErr = $this->getLastError() ?: 'ناموفق';
        }

        $this->lastError = 'cPanel (API2 Fileman::fileop op=' . $op . '): ' . $lastErr;
        return [false, null];
    }

    /**
     * 📦 استخراج ZIP در مسیر مقصد — v2.19 بازنویسی ریشه‌ای (مکان‌یابی + بازیابی خودکار)
     * ====================================================
     * 🔴 ریشه‌یابی خطای «استخراج کامل نشد — index.php در مسیر سایت یافت نشد»:
     *    طبق مستندات رسمی API2 Fileman::fileop، پارامتر destfiles «فقط برای
     *    عملیات copy/move/rename» تعریف شده — برای extract مقصدی وجود ندارد و
     *    استخراج در دایرکتوری خودِ آرشیو انجام می‌شود. کد v2.18 علاوه بر ارسال
     *    بی‌اثر destfiles، فرض می‌کرد فایل‌ها مستقیم در مقصد می‌نشینند؛ اما
     *    cPanel (به‌ویژه نسخه‌های قدیمی) آرشیو را داخل «پوشه هم‌نام آرشیو»
     *    استخراج می‌کند (site.zip → site/… ) و راستی‌آمایی index.php شکست می‌خورد.
     *
     * ✅ زنجیره v2.19:
     *    ① ZIP داخل مقصد تضمین می‌شود (کپی در صورت نیاز — استخراج کنار آرشیو)
     *    ② استخراج بدون destfiles (رفتار رسمی) سپس با destfiles (نسخه‌های قدیمی)
     *    ③ settleExtractedFiles: مکان‌یابی index.php (مقصد یا زیرپوشه‌ها) +
     *       انتقال محتویات به بالا + راستی‌آمایی نهایی — در همه حالات خروجی قطعی
     *
     * @param string $remoteZip مسیر ZIP روی سرور
     * @param string $destDir پوشه مقصد استخراج
     */
    public function extractZip(string $remoteZip, string $destDir): bool
    {
        $remoteZip = $this->normalizePath($remoteZip);
        $destDir   = rtrim($this->normalizePath($destDir), '/');
        $zipDir    = rtrim(dirname($remoteZip), '/');

        /* ① ZIP بیرون از مقصد است — کپی داخل مقصد (استخراج همیشه کنار آرشیو) */
        if (strcasecmp($zipDir, $destDir) !== 0) {
            if (!$this->copyFile($remoteZip, $destDir)) {
                $this->lastError = 'کپی بسته به پوشه مقصد ناموفق بود (برای استخراج ایمن): ' . $this->lastError;
                return false;
            }
            $remoteZip = $destDir . '/' . basename($remoteZip);
        }

        /* ② استخراج — ابتدا بدون destfiles (طبق مستندات؛ استخراج کنار آرشیو که
         *    خودِ مقصد است)، سپس با destfiles (برخی نسخه‌های قدیمی آن را می‌پذیرند) */
        $this->lastExtractStage = 'api';
        [$ok] = $this->fileOp('extract', [$remoteZip]);
        if (!$ok) {
            [$ok] = $this->fileOp('extract', [$remoteZip], $destDir);
            if (!$ok) {
                return false;
            }
        }

        /* ③ مکان‌یابی + بازیابی خودکار + راستی‌آمایی نهایی */
        $this->lastExtractStage = 'settle';
        return $this->settleExtractedFiles($destDir, $remoteZip);
    }

    /**
     * 🧭 مکان‌یابی فایل‌های استخراج‌شده + بازیابی خودکار — v2.19
     * =========================================================
     * بعد از استخراج، فایل‌ها ممکن است در یکی از این مکان‌ها باشند:
     *   ① مستقیم داخل $destDir (حالت استاندارد)
     *   ② داخل زیرپوشه هم‌نام آرشیو (رفتار cPanel قدیمی: site.zip → site/…)
     *   ③ داخل تک‌پوشه ریشه (ZIP دارای پوشه ریشه — مثل بکاپ برند)
     * اگر index.php در زیرپوشه پیدا شد، کل محتویاتش به $destDir منتقل و
     * پوشه حذف می‌شود — در همه حالات خروجی قطعی است: index.php در $destDir.
     *
     * @return bool آیا index.php نهایتاً در مسیر مقصد موجود است؟
     */
    private function settleExtractedFiles(string $destDir, string $remoteZip): bool
    {
        $destDir = rtrim($this->normalizePath($destDir), '/');

        /* ① حالت استاندارد — فایل‌ها مستقیم در مقصد */
        if ($this->entryExists($destDir . '/index.php', 'file')) {
            return true;
        }

        /* ② جستجوی زیرپوشه‌ها — اولویت: هم‌نام آرشیو، سپس سایر پوشه‌ها */
        $zipBase = preg_replace('/\.(zip|tar\.gz|tar|gz|bz2)$/i', '', basename($remoteZip));
        $priority = [];
        $others   = [];
        foreach ($this->listFiles($destDir) as $e) {
            if (($e['type'] ?? '') !== 'dir') {
                continue;
            }
            $name = (string)($e['file'] ?? $e['name'] ?? '');
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            if ($name === $zipBase) {
                $priority[] = $name;
            } else {
                $others[] = $name;
            }
        }

        foreach (array_merge($priority, $others) as $sub) {
            $inner = $destDir . '/' . $sub;

            /* 🎯 فقط پوشه‌ای که واقعاً «ریشه سایت» است جابجا شود:
             *    index.php + حداقل یک نشانگر ریشه (robots.txt/.htaccess/config.php/404.php/css)
             *    — پوشه‌های بخشی مثل pages/ یا includes/ اشتباهی انتخاب نمی‌شوند */
            if (!$this->entryExists($inner . '/index.php', 'file')) {
                continue;
            }
            $hasRootMarker = $this->entryExists($inner . '/robots.txt')
                || $this->entryExists($inner . '/.htaccess')
                || $this->entryExists($inner . '/config.php', 'file')
                || $this->entryExists($inner . '/404.php', 'file')
                || $this->entryExists($inner . '/css', 'dir');
            if (!$hasRootMarker) {
                continue;
            }

            /* 🚚 انتقال تک‌به‌تک محتویات به بالا — با ادغام هوشمند:
             * پوشه‌های هم‌نام موجود (مثل css/ که stepFolders از قبل ساخته)
             * بازگشتی ادغام می‌شوند نه تودرتو — v2.19.1 */
            $moved = $this->mergeMoveUp($inner, $destDir) ? 1 : 0;
            $this->deleteFile($inner); /* 🧹 پوشه خالی‌شده */

            if ($moved > 0 && $this->entryExists($destDir . '/index.php', 'file')) {
                return true;
            }
        }

        $this->lastError = 'پس از استخراج، index.php نه در مسیر مقصد و نه در زیرپوشه‌های کاندید یافت نشد';
        return false;
    }

    /**
     * 🧬 انتقال محتویات یک پوشه به بالا با ادغام هوشمند — v2.19
     * =========================================================
     * سناریوی حیاتی: stepFolders قبل از استخراج، پوشه‌های خالی css/js/pages/…
     * را در مقصد می‌سازد؛ اگر استخراج داخل زیرپوشه انجام شده باشد، انتقال ساده
     * «css» روی «css» موجود تودرتو می‌سازد (destDir/css/css!) چون مستندات
     * move_file می‌گوید مقصد موجود ← منبع داخل آن می‌رود.
     *
     * ✅ این متد تداخل‌ها را مدیریت می‌کند:
     *   • پوشه روی پوشه → ادغام بازگشتی (محتویات داخل هم می‌روند)
     *   • فایل روی فایل → حذف قدیمی + جایگزینی
     *   • عدم تداخل → انتقال معمولی
     *
     * @return bool آیا حداقل یک مدخل جابجا شد؟
     */
    private function mergeMoveUp(string $srcDir, string $destDir): bool
    {
        $srcDir  = rtrim($this->normalizePath($srcDir), '/');
        $destDir = rtrim($this->normalizePath($destDir), '/');
        $moved   = 0;

        foreach ($this->listFiles($srcDir) as $entry) {
            $name = (string)($entry['file'] ?? $entry['name'] ?? '');
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            $src = $srcDir . '/' . $name;
            $dst = $destDir . '/' . $name;

            if ($this->entryExists($dst)) {
                $srcIsDir = ($entry['type'] ?? '') === 'dir';
                $dstIsDir = $this->entryExists($dst, 'dir');
                if ($srcIsDir && $dstIsDir) {
                    /* 📁 پوشه روی پوشه — ادغام بازگشتی */
                    if ($this->mergeMoveUp($src, $dst)) {
                        $moved++;
                    }
                    continue;
                }
                /* 📄 فایل/تداخل نوع — قدیمی حذف و جایگزین می‌شود */
                $this->deleteFile($dst);
            }
            if ($this->moveFile($src, $destDir)) {
                $moved++;
            }
        }
        return $moved > 0;
    }

    /**
     * 🗑️ حذف فایل یا پوشه — v2.18 سه‌لایه
     * ① UAPI Fileman::delete_file (path — حذف بازگشتی پوشه‌ها طبق مستندات؛ مطلق سپس نسبی)
     * ② API2 fileop op=unlink
     * ③ API2 fileop op=trash (انتقال به سطل بازیافت — حداقل از سایت حذف می‌شود)
     */
    public function deleteFile(string $path): bool
    {
        $path = $this->normalizePath($path);
        $relative = $this->homeRelative($path);

        /* ① UAPI delete_file — مطلق سپس نسبی */
        $candidates = array_unique(array_filter([$path, $relative]));
        foreach ($candidates as $p) {
            if ($this->call('Fileman', 'delete_file', ['path' => $p]) !== false) {
                return true;
            }
        }
        $uapiError = $this->getLastError();

        /* ② API2 fileop unlink */
        [$ok] = $this->fileOp('unlink', [$path]);
        if ($ok) {
            return true;
        }

        /* ③ fileop trash — سطل بازیافت */
        [$ok] = $this->fileOp('trash', [$path]);
        if ($ok) {
            return true;
        }

        $this->lastError = 'حذف ناموفق — UAPI delete_file: ' . $uapiError . ' | fileop: ' . $this->lastError;
        return false;
    }

    /**
     * 🗑️ حذف کامل پوشه (بازگشتی)
     * UAPI delete_file مستنداً پوشه‌ها را بازگشتی حذف می‌کند
     */
    public function deleteDirectory(string $path): bool
    {
        return $this->deleteFile($path);
    }

    /**
     * 🚚 جابجایی فایل/پوشه به داخل پوشه مقصد — v2.19 دو قالب مسیر
     * ① UAPI Fileman::move_file (source + destination — مستندات رسمی:
     *    «If the destination is an existing directory, the source is moved into it»)
     *    هر دو قالب مطلق + نسبی امتحان می‌شود (مثال‌های رسمی نسبی‌اند)
     * ② API2 fileop op=move (sourcefiles جداشده با کاما + destfiles)
     */
    public function moveFile(string $source, string $destDir): bool
    {
        $destAbs = rtrim($this->normalizePath($destDir), '/');
        $destRel = rtrim($this->homeRelative($destDir), '/');

        /* ① UAPI move_file — مطلق سپس نسبی */
        $pairs = $destAbs === $destRel
            ? [[$this->normalizePath($source), $destAbs]]
            : [[$this->normalizePath($source), $destAbs], [$this->homeRelative($source), $destRel]];
        foreach ($pairs as [$src, $dst]) {
            if ($this->call('Fileman', 'move_file', [
                'source'      => $src,
                'destination' => $dst,
            ]) !== false) {
                return true;
            }
        }
        $uapiError = $this->getLastError();

        /* ② API2 fileop move */
        [$ok] = $this->fileOp('move', [$source], $destAbs);
        if ($ok) {
            return true;
        }

        $this->lastError = 'جابجایی ناموفق — UAPI move_file: ' . $uapiError . ' | fileop: ' . $this->lastError;
        return false;
    }

    /**
     * 📄 کپی فایل به داخل پوشه مقصد — v2.18
     * API2 fileop op=copy (sourcefiles + destfiles — مقصد = پوشه؛ نام فایل حفظ می‌شود)
     */
    public function copyFile(string $source, string $destDir): bool
    {
        [$ok] = $this->fileOp('copy', [$source], rtrim($this->normalizePath($destDir), '/'));
        return $ok;
    }

    /**
     * 🗜️ فشرده‌سازی فایل/پوشه به ZIP — v2.18
     * API2 fileop op=compress + metadata=zip (طبق مستندات رسمی)
     */
    public function compressToZip(string $source, string $destZip): bool
    {
        [$ok] = $this->fileOp('compress', [$source], $this->normalizePath($destZip), 'zip');
        return $ok;
    }

    /**
     * 🔧 مسطح‌کردن تک‌پوشه — v2.18 (جایگزین fileop op=move با wildcard)
     * اگر داخل $dir فقط یک پوشه باشد، محتویاتش را تک‌به‌تک به بالا می‌آورد
     * و پوشه را حذف می‌کند (رفع ZIPهای دارای پوشه ریشه — بدون wildcard غیرقطعی)
     *
     * @return bool آیا مسطح‌سازی انجام شد؟
     */
    public function flattenSingleChildDir(string $dir): bool
    {
        $dir = rtrim($this->normalizePath($dir), '/');
        $entries = $this->listFiles($dir);

        if (count($entries) !== 1) {
            return false;
        }
        $only = (string)($entries[0]['file'] ?? $entries[0]['name'] ?? '');
        if ($only === '' || ($entries[0]['type'] ?? '') !== 'dir') {
            return false;
        }

        $inner = $dir . '/' . $only;
        $moved = 0;
        foreach ($this->listFiles($inner) as $entry) {
            $name = (string)($entry['file'] ?? $entry['name'] ?? '');
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            if ($this->moveFile($inner . '/' . $name, $dir)) {
                $moved++;
            }
        }
        $this->deleteFile($inner);
        return $moved > 0;
    }

    /**
     * 📋 لیست فایل‌های یک پوشه — v2.19 دو قالب مسیر
     * 📌 طبق مستندات رسمی، پاسخ در دو آرایه جدا برمی‌گردد:
     *    data.files (فایل‌ها) + data.dirs (پوشه‌ها) → هر دو ادغام و یکتا می‌شوند
     *    و فیلد type هر مدخل تضمین می‌شود ('file' یا 'dir')
     * 🛡️ مسیر پوشه با هر دو قالب امتحان می‌شود (مطلق + نسبی از home —
     *    مثال‌های رسمی نسبی‌اند؛ اولین نتیجه غیرخالی برنده است)
     */
    public function listFiles(string $dir): array
    {
        $formats = [$this->normalizePath($dir)];
        $rel = $this->homeRelative($dir);
        if ($rel !== $formats[0]) {
            $formats[] = $rel;
        }

        foreach ($formats as $dirPath) {
            $data = $this->call('Fileman', 'list_files', [
                'dir'         => $dirPath,
                'show_hidden' => 1,
            ]);
            if ($data === false) {
                continue; /* قالب مسیر دیگر امتحان شود */
            }
            $out = [];
            $seen = [];
            foreach (['files', 'dirs'] as $key) {
                foreach ((array)($data[$key] ?? []) as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $name = (string)($entry['file'] ?? $entry['name'] ?? '');
                    if ($name === '' || isset($seen[$name])) {
                        continue;
                    }
                    $seen[$name] = true;
                    $entry['type'] = ($entry['type'] ?? '') !== '' ? (string)$entry['type'] : ($key === 'dirs' ? 'dir' : 'file');
                    $out[] = $entry;
                }
            }
            if ($out !== []) {
                return $out; /* ✅ اولین قالب با نتیجه غیرخالی */
            }
            /* نتیجه خالی — قالب بعدی هم امتحان شود (شاید قالب مسیر اشتباه بود) */
        }
        return [];
    }

    /**
     * 🔐 تنظیم مجوز (chmod) — v2.18 بازنویسی ریشه‌ای
     * 🔴 UAPI تابع Fileman::chmod ندارد (در فهرست رسمی cpanel.openapi نیست) —
     *    فراخوانی قبلی /execute/Fileman/chmod با خطای «تابع پیدا نشد» شکست می‌خورد.
     * ✅ تنها مسیر مستند: API2 fileop (op=chmod + metadata=مجوز هشتایی)
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
        if (strlen($mode) === 3) {
            $mode = '0' . $mode; /* مستندات: 0755 / 0700 */
        }
        [$ok] = $this->fileOp('chmod', [$path], '', $mode);
        return $ok;
    }

    /**
     * ✍️ نوشتن محتوا در فایل (تولید config.php و .htaccess) — v2.18 اصلاح پارامترها
     *
     * 🐛 قبلاً پارامتر file مسیر کامل می‌گرفت و fallback_list ارسال می‌شد —
     *    طبق مستندات رسمی UAPI save_file_content:
     *    file = فقط نام فایل | dir = پوشه | fallback = 0/1 (نه fallback_list)
     *
     * 🛡️ زنجیره دو-لایه:
     *    ① UAPI Fileman::save_file_content (file + dir + content + charsetها + fallback=1)
     *    ② API2 Fileman::savefile (path=مسیر مطلق پوشه، filename=نام فایل، content=محتوا)
     *
     * @param string $path مسیر کامل فایل مقصد
     * @param string $content محتوا
     */
    public function writeFile(string $path, string $content): bool
    {
        $path = $this->normalizePath($path);
        $dir  = rtrim(dirname($path), '/');
        $name = basename($path);

        /* ① UAPI — روش اصلی (file = نام فایل + dir = پوشه — طبق مستندات) */
        $data = $this->call('Fileman', 'save_file_content', [
            'file'         => $name,
            'dir'          => $dir,
            'content'      => $content,
            'from_charset' => 'UTF-8',
            'to_charset'   => 'UTF-8',
            'fallback'     => 1,
        ]);
        if ($data !== false) {
            return true;
        }
        $uapiError = $this->lastError;

        /* ② API2 savefile — جایگزین (path + filename + content) */
        $api2 = $this->callApi2('Fileman', 'savefile', [
            'path'     => $dir,
            'filename' => $name,
            'content'  => $content,
        ]);
        if ($api2 !== false) {
            return true;
        }

        $this->lastError = 'نوشتن فایل ناموفق — UAPI: ' . ($uapiError ?: 'ناموفق') . ' | API2: ' . ($this->lastError ?: 'ناموفق');
        return false;
    }

    /**
     * 📖 خواندن محتوای فایل (برای حفظ config.php هنگام بروزرسانی) — v2.18 اصلاح پارامترها
     * 🐛 قبلاً پارامتر file مسیر کامل می‌گرفت — طبق مستندات رسمی:
     *    dir = پوشه (الزامی) + file = نام فایل (الزامی) + to_charset=utf-8 برای JSON
     *
     * @return string محتوا (خالی در خطا)
     */
    public function readFile(string $path): string
    {
        $path = $this->normalizePath($path);
        $data = $this->call('Fileman', 'get_file_content', [
            'dir'        => rtrim(dirname($path), '/'),
            'file'       => basename($path),
            'to_charset' => 'utf-8',
        ]);
        if ($data === false) {
            return '';
        }
        $content = (string)($data['content'] ?? '');
        if ($content === '') {
            return '';
        }
        /* محتوای UTF-8 معتبر → مستقیم؛ در غیر این صورت تلاش base64 */
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }
        $decoded = base64_decode($content, true);
        return $decoded !== false ? $decoded : $content;
    }

    /* ==================================================
     * 🔒 SSL
     * ================================================== */

    /**
     * 📋 لیست گواهی‌های SSL نصب‌شده — v2.18 بازنویسی
     * 🔴 UAPI تابع SSL::list_ssl_certificates ندارد (مربوط به API1 است) →
     * ✅ معادل رسمی UAPI: SSL::list_ssl_items (item=crt — فهرست گواهی‌های نصب‌شده)
     */
    public function listSSLCertificates(): array
    {
        $data = $this->call('SSL', 'list_ssl_items', ['item' => 'crt']);
        return $data === false ? [] : (array)$data;
    }

    /**
     * ❓ بررسی وجود SSL فعال برای یک دامنه — v2.18 (سازگار با ساختار list_ssl_items)
     * هر مدخل فیلد host دارد؛ گواهی‌های wildcard (*.domain) هم پوشش داده می‌شوند.
     *
     * @param string $domain دامنه کامل (مثلاً samsung.ea-fixer.ir)
     * @return array [active => bool, expiry => string|null, issuer => string|null]
     */
    public function checkSSL(string $domain): array
    {
        $certs = $this->listSSLCertificates();
        $domain = strtolower(trim($domain));

        foreach ($certs as $cert) {
            if (!is_array($cert)) {
                continue;
            }
            /* دامنه‌های گواهی — فیلد host و در صورت وجود آرایه domains */
            $hosts = [strtolower(trim((string)($cert['host'] ?? '')))];
            foreach ((array)($cert['domains'] ?? []) as $d) {
                $hosts[] = strtolower((string)(is_array($d) ? ($d['servername'] ?? $d['domain'] ?? '') : $d));
            }
            foreach (array_filter($hosts) as $h) {
                $wildcard = $h[0] === '*' && strlen($h) > 1
                    && substr($domain, -(strlen($h) - 1)) === substr($h, 1);
                if ($h === $domain || $wildcard) {
                    return [
                        'active' => true,
                        'expiry' => $cert['not_after'] ?? $cert['expires'] ?? null,
                        'issuer' => $cert['issuer']['organizationName'] ?? ($cert['issuer_common_name'] ?? null),
                    ];
                }
            }
        }
        return ['active' => false, 'expiry' => null, 'issuer' => null];
    }

    /**
     * 🔒 فعال‌سازی AutoSSL — v2.18
     * 🔴 UAPI توابع enable_autossl و start_autossl_scan را ندارد (enable متعلق به API1 است) →
     * ✅ معادل رسمی UAPI: SSL::start_autossl_check («Start AutoSSL for current user»)
     *    فراهم‌کننده (Let's Encrypt یا cPanel) را تنظیمات سرور تعیین می‌کند.
     */
    public function enableAutoSSL(string $provider = 'letsencrypt'): bool
    {
        return $this->startAutoSSLScan();
    }

    /**
     * 🚀 شروع بررسی AutoSSL برای صدور گواهی دامنه‌های جدید — v2.18
     * ✅ UAPI SSL::start_autossl_check (بدون پارامتر — مستندات رسمی)
     */
    public function startAutoSSLScan(): bool
    {
        return $this->call('SSL', 'start_autossl_check') !== false;
    }

    /**
     * 🔒 نصب گواهی SSL (AutoSSL) — v2.18 بازنویسی با توابع واقعی UAPI
     *
     * @param string $domain دامنه کامل
     * @param string $provider فراهم‌کننده (اطلاعاتی — سرور تعیین می‌کند)
     * @return array [success => bool, message => string]
     */
    public function installSSL(string $domain, string $provider = 'letsencrypt'): array
    {
        // ۱. بررسی گواهی موجود
        $existing = $this->checkSSL($domain);
        if ($existing['active']) {
            return ['success' => true, 'message' => 'SSL از قبل برای این دامنه فعال است.'];
        }

        // ۲. شروع بررسی AutoSSL — صدور برای همه دامنه‌های سالم کاربر
        if (!$this->startAutoSSLScan()) {
            return [
                'success' => false,
                'message' => 'شروع AutoSSL ناموفق: ' . $this->getLastError()
                    . ' — اگر AutoSSL در هاست غیرفعال است، از میزبان فعال‌سازی آن را بخواهید.',
            ];
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
     * ⏰ Cron Jobs — مدیریت زمان‌بندی‌ها
     * ================================================== */

    /**
     * ⏰ افزودن یک Cron Job به cPanel
     *
     * 🔁 استراتژی چندلایه (v2.14):
     *   ① API2 مستقیم (json-api/cpanel?module=Cron&func=add_line) — روش رسمی؛
     *      Cron طبق مستندات cPanel «معادل UAPI ندارد» و پل UAPI→API2 روی
     *      نسخه‌های جدید (پرل ۵.۴۲+) با خطای «Can't locate Cpanel/API/Cron.pm»
     *      شکست می‌خورد.
     *   ② پل UAPI (/execute/Cron/add_line) — برای سرورهای قدیمی‌تر که پل سالم است.
     *   ③ اگر هر دو ناموفق بودند → خطای شفاف + راهنمای افزودن دستی.
     *
     * 🛡️ ضدتکرار: اگر همین دستور از قبل در crontab وجود داشته باشد،
     *    بدون خطا «موجود» گزارش می‌شود (دوباره اضافه نمی‌شود).
     *
     * @param string $command  دستور کامل (مثلاً php /home/user/public_html/cron/backup.php)
     * @param string $minute   دقیقه (ستاره یعنی هر دقیقه، یا عبارت هر-۱۵-دقیقه)
     * @param string $hour     ساعت (ستاره یا عدد 0 تا 23)
     * @param string $day      روز ماه (ستاره یا عدد 1 تا 31)
     * @param string $month    ماه (ستاره یا عدد 1 تا 12)
     * @param string $weekday  روز هفته (ستاره یا عدد 0 تا 6)
     * @return array [success => bool, message => string, existed => bool, method => string]
     */
    public function addCronJob(string $command, string $minute = '*', string $hour = '*', string $day = '*', string $month = '*', string $weekday = '*'): array
    {
        $command = trim($command);
        if ($command === '') {
            return ['success' => false, 'message' => 'دستور Cron خالی است.', 'existed' => false, 'method' => ''];
        }

        // 🛡️ فقط دستورات امن مجازند (php / مسیر مطلق) — جلوگیری از تزریق
        if (!preg_match('#^(php|/usr/bin/php|/usr/local/bin/php|curl|wget|/bin/bash|/bin/sh)\s+#i', $command) && !preg_match('#^/home/[^/\s]+/.+#', $command)) {
            return ['success' => false, 'message' => 'دستور مجاز نیست — فقط دستورات php/curl/wget با مسیر مطلق مجازند.', 'existed' => false, 'method' => ''];
        }

        /* 🛡️ ضدتکرار — اگر همین دستور از قبل ثبت شده، دوباره اضافه نکن */
        $existing = $this->listCronJobs();
        foreach ($existing as $line) {
            $cmd = trim((string)($line['command'] ?? ''));
            if ($cmd === $command) {
                return [
                    'success' => true,
                    'existed' => true,
                    'method'  => 'dedup',
                    'message' => '✅ این دستور از قبل در cPanel ثبت شده بود — دوباره اضافه نشد.',
                ];
            }
        }

        /* ① API2 مستقیم — روش رسمی و پایدار */
        $data = $this->callApi2('Cron', 'add_line', [
            'command' => $command,
            'day'     => $day,
            'hour'    => $hour,
            'minute'  => $minute,
            'month'   => $month,
            'weekday' => $weekday,
        ]);
        if ($data !== false) {
            $lineKey = (string)($data[0]['linekey'] ?? '');
            $status  = (int)($data[0]['status'] ?? 1);
            if ($status === 1) {
                return ['success' => true, 'existed' => false, 'method' => 'api2', 'message' => '✅ Cron با موفقیت به cPanel اضافه شد.' . ($lineKey !== '' ? ' (شناسه: ' . $lineKey . ')' : ''), 'line' => $lineKey];
            }
            /* گاهی status=0 ولی event.result=1 — پیام را نشان بده */
            $statusMsg = (string)($data[0]['statusmsg'] ?? '');
            if ($statusMsg !== '' && stripos($statusMsg, 'installed') === false) {
                return ['success' => false, 'existed' => false, 'method' => 'api2', 'message' => 'cPanel پاسخ داد: ' . $statusMsg];
            }
        }

        /* ② پل UAPI — برای سرورهایی که پل UAPI→API2 سالم دارند */
        $api2Error = $this->lastError; // خطای مرحله ① برای پیام نهایی
        $data = $this->call('Cron', 'add_line', [
            'command'  => $command,
            'minute'   => $minute,
            'hour'     => $hour,
            'day'      => $day,
            'month'    => $month,
            'weekday'  => $weekday,
            // اگر خط تکراری وجود داشت، بازنویسی شود
            'confirm'  => 'overwrite',
        ]);
        if ($data !== false) {
            $lineNo = (string)($data['linekey'] ?? $data['line'] ?? '');
            return ['success' => true, 'existed' => false, 'method' => 'uapi', 'message' => '✅ Cron با موفقیت به cPanel اضافه شد.' . ($lineNo !== '' ? ' (شناسه: ' . $lineNo . ')' : ''), 'line' => $lineNo];
        }

        /* ③ هر دو روش ناموفق — راهنمای شفاف */
        return [
            'success' => false,
            'existed' => false,
            'method'  => 'failed',
            'message' => "هر دو روش افزودن Cron (API2 و UAPI) ناموفق بودند.\n" .
                "— خطای API2: " . ($api2Error ?: 'نامشخص') . "\n" .
                "— خطای UAPI: " . ($this->lastError ?: 'نامشخص') . "\n" .
                "💡 راه حل: از cPanel » Advanced » Cron Jobs دستور زیر را دستی اضافه کنید:\n" .
                $minute . ' ' . $hour . ' ' . $day . ' ' . $month . ' ' . $weekday . ' ' . $command,
        ];
    }

    /**
     * 📋 لیست Cron Jobs فعلی cPanel (API2 اول + fallback پل UAPI)
     * خروجی نرمال‌شده: هر خط = [command, minute, hour, day, month, weekday, linekey, count]
     */
    public function listCronJobs(): array
    {
        /* ① API2 مستقیم — listcron */
        $data = $this->callApi2('Cron', 'listcron');
        if ($data !== false) {
            $rows = [];
            foreach ($data as $row) {
                if (!is_array($row) || !isset($row['command'])) {
                    continue; // ردیف «count» پایانی که فقط تعداد کل است
                }
                $rows[] = [
                    'command'  => (string)($row['command'] ?? ''),
                    'minute'   => (string)($row['minute'] ?? '*'),
                    'hour'     => (string)($row['hour'] ?? '*'),
                    'day'      => (string)($row['day'] ?? '*'),
                    'month'    => (string)($row['month'] ?? '*'),
                    'weekday'  => (string)($row['weekday'] ?? '*'),
                    'linekey'  => (string)($row['linekey'] ?? ''),
                    'count'    => (int)($row['count'] ?? 0),
                ];
            }
            return $rows;
        }

        /* ② پل UAPI — list_lines (فرمت UAPI) */
        $data = $this->call('Cron', 'list_lines');
        if ($data === false) {
            return [];
        }
        $rows = [];
        foreach ((array)($data['crons'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'command'  => (string)($row['command'] ?? ''),
                'minute'   => (string)($row['minute'] ?? '*'),
                'hour'     => (string)($row['hour'] ?? '*'),
                'day'      => (string)($row['day'] ?? '*'),
                'month'    => (string)($row['month'] ?? '*'),
                'weekday'  => (string)($row['weekday'] ?? '*'),
                'linekey'  => (string)($row['linekey'] ?? ''),
                'count'    => (int)($row['count'] ?? 0),
            ];
        }
        return $rows;
    }

    /**
     * 🗑️ حذف یک Cron Job (API2 اول + fallback پل UAPI)
     *
     * @param int|string $lineRef شماره خط (count) یا linekey — API2 شماره خط می‌پذیرد
     */
    public function removeCronJob($lineRef): array
    {
        $lineRef = trim((string)$lineRef);
        if ($lineRef === '') {
            return ['success' => false, 'message' => 'شناسه خط Cron نامعتبر است.'];
        }

        /* ① API2 مستقیم — remove_line با پارامتر line */
        $data = $this->callApi2('Cron', 'remove_line', ['line' => $lineRef]);
        if ($data !== false) {
            return ['success' => true, 'message' => '✅ Cron حذف شد.'];
        }
        $api2Error = $this->lastError;

        /* ② پل UAPI — remove_line با lineno */
        $data = $this->call('Cron', 'remove_line', ['lineno' => $lineRef]);
        if ($data !== false) {
            return ['success' => true, 'message' => '✅ Cron حذف شد.'];
        }

        return [
            'success' => false,
            'message' => "حذف Cron در هر دو روش ناموفق بود.\n— API2: " . ($api2Error ?: 'نامشخص') . "\n— UAPI: " . ($this->lastError ?: 'نامشخص'),
        ];
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
        // ⚠️ v2.18: فقط مسیرهایی که با «/» شروع می‌شوند (مثل /public_html/...)
        //    نام فایل تنها (مثل config.php در پارامتر file) نباید تغییر کند!
        $user = (string)($this->settings['cpanel_username'] ?? '');
        if ($user !== '' && isset($path[0]) && $path[0] === '/') {
            return '/home/' . $user . $path;
        }
        return $path;
    }

    /**
     * 🏠 تبدیل مسیر به قالب نسبی از home کاربر — v2.19
     * مستندات رسمی cPanel برای پارامترهای مسیر (dir/sourcefiles/path/...)
     * مثال‌های نسبی می‌دهند («public_html/...»)؛ برخی سرورها فقط یکی از
     * دو قالب را می‌پذیرند — به همین دلیل همه عملیات هر دو را امتحان می‌کنند.
     */
    private function homeRelative(string $path): string
    {
        $path = $this->normalizePath($path);
        $user = (string)($this->settings['cpanel_username'] ?? '');
        if ($user === '') {
            return $path;
        }
        $rel = preg_replace('#^/home/' . preg_quote($user, '#') . '/?#', '', $path);
        return ($rel !== '' && $rel !== $path) ? $rel : $path;
    }

    /**
     * 🧭 مرحله شکست آخرین استخراج — v2.19
     * 'api' یعنی فراخوانی cPanel شکست خورد؛ 'settle' یعنی استخراج انجام شد
     * ولی مکان‌یابی/راستی‌آمایی index.php ناکام ماند (پیام خطای مناسب انتخاب شود)
     */
    public function getLastExtractStage(): string
    {
        return $this->lastExtractStage;
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
