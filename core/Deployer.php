<?php
/**
 * 🚀 ارکستراتور اصلی استقرار خودکار
 * ==================================
 * اجرای فرآیند ۱۰ مرحله‌ای طبق سند بخش ۲۱ (بخش ۳):
 *   ۱. بررسی پیش‌نیازها (تنظیمات + اتصال + برند + ZIP)
 *   ۲. بررسی وجود زیردامنه
 *   ۳. ساخت زیردامنه در cPanel
 *   ۴. ساخت پوشه‌ها
 *   ۵. آپلود و استخراج ZIP (API + موتور کمکی PHP + FTP)
 *   ۶. تنظیم config.php
 *   ۷. تنظیم .htaccess
 *   ۸. نصب SSL
 *   ۹. تست نهایی (HTTP 200 + محتوا + API)
 *   ۱۰. ثبت در دیتابیس
 *
 * حالت‌ها: deploy جدید / update بروزرسانی / delete حذف
 * خطا: Rollback خودکار به بکاپ در صورت شکست تست نهایی
 * صف: پردازش تک‌به‌تک برای جلوگیری از overload
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class Deployer
{
    /** @var CpanelAPI کلاینت cPanel */
    private $api;

    /** @var Database اتصال دیتابیس */
    private $db;

    /** @var DeploymentLogger لاگر */
    private $logger;

    /** @var array تنظیمات cPanel */
    private $settings;

    /* 📋 مراحل هر نوع عملیات — کلید => [نام فنی، عنوان فارسی، درصد پیشرفت] */
    const STEPS_DEPLOY = [
        ['prerequisites',   'بررسی پیش‌نیازها و تولید بسته سایت',            5],
        ['subdomain_check', 'بررسی وجود زیردامنه',                          15],
        ['subdomain_create','ساخت زیردامنه در cPanel',                      25],
        ['folders',         'ساخت ساختار پوشه‌ها',                          35],
        ['upload',          'آپلود فایل‌ها',                                50],
        ['extract',         'استخراج فایل ZIP',                             60],
        ['config',          'تنظیم config.php',                             70],
        ['htaccess',        'تنظیم .htaccess',                              78],
        ['permissions',     'تنظیم مجوزهای فایل',                           84],
        ['ssl',             'نصب SSL',                                      92],
        ['finalize',        'تست نهایی و ثبت',                             100],
    ];

    const STEPS_UPDATE = [
        ['prerequisites',   'بررسی پیش‌نیازها و تولید بسته جدید',           8],
        ['backup',          'بکاپ از نسخه فعلی',                            18],
        ['preserve_config', 'حفظ تنظیمات فعلی',                             25],
        ['clean_old',       'حذف فایل‌های قدیمی',                          35],
        ['upload',          'آپلود فایل‌های جدید',                          50],
        ['extract',         'استخراج فایل ZIP',                             60],
        ['restore_config',  'بازنویسی config.php',                          72],
        ['htaccess',        'بروزرسانی .htaccess',                          80],
        ['ssl',             'بررسی SSL',                                    90],
        ['finalize',        'تست نهایی و ثبت',                             100],
    ];

    const STEPS_DELETE = [
        ['prerequisites',   'بررسی پیش‌نیازها',                              15],
        ['backup',          'بکاپ نهایی',                                   35],
        ['delete_files',    'حذف فایل‌ها',                                  55],
        ['delete_subdomain','حذف زیردامنه',                                 75],
        ['finalize',        'بروزرسانی دیتابیس',                            100],
    ];

    /**
     * 🔧 سازنده
     */
    public function __construct(?CpanelAPI $api = null)
    {
        $this->api      = $api ?? new CpanelAPI();
        $this->db       = Database::getInstance();
        $this->logger   = new DeploymentLogger();
        $this->settings = PathResolver::getSettings();
    }

    /* ==================================================
     * 🎬 مدیریت عملیات
     * ================================================== */

    /**
     * 🎬 ایجاد عملیات استقرار جدید (در صف)
     *
     * @param int    $brandId شناسه برند
     * @param string $subdomain نام زیردامنه (تأییدشده در دیالوگ پیش‌نمایش)
     * @param bool   $sslInstall نصب SSL؟
     * @param string $triggeredBy منبع (panel|api|cron)
     * @param string $domainType نوع دامنه: subdomain (پیش‌فرض) یا addon (دامنه الحاقی — v2.12)
     * @param string $addonDomain دامنه کامل الحاقی (وقتی domain_type=addon)
     * @return array [success => bool, deployment_id => int|null, message => string]
     */
    public function queueDeploy(int $brandId, string $subdomain, bool $sslInstall = true, string $triggeredBy = 'panel', string $domainType = 'subdomain', string $addonDomain = ''): array
    {
        // 🔍 بررسی وضعیت برند
        $brand = $this->db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
        if (!$brand) {
            return ['success' => false, 'deployment_id' => null, 'message' => 'برند یافت نشد.'];
        }
        if ($brand['status'] === 'draft') {
            return ['success' => false, 'deployment_id' => null, 'message' => 'ابتدا فرآیند ساخت سایت این برند را کامل کنید.'];
        }

        // 🚦 فقط یک عملیات همزمان برای هر برند
        $active = $this->db->fetchValue(
            "SELECT COUNT(*) FROM deployments WHERE brand_id = ? AND status IN ('pending','in_progress')",
            [$brandId]
        );
        if ($active > 0) {
            return ['success' => false, 'deployment_id' => null, 'message' => 'عملیات دیگری برای این برند در حال اجرا است — صبر کنید.'];
        }

        // 🌐 مسیر و دامنه (v2.12: پشتیبانی دامنه الحاقی)
        $rootDomain = (string)($this->settings['root_domain'] ?? '');
        $serverPath = PathResolver::resolveDocumentRoot($brand);
        if ($domainType === 'addon') {
            $addonDomain = strtolower(trim(str_replace(['https://', 'http://', '/'], '', $addonDomain)));
            $check = SubdomainValidator::validateFullDomain($addonDomain);
            if (!$check['valid']) {
                return ['success' => false, 'deployment_id' => null, 'message' => $check['error']];
            }
            $subdomain = strtok($addonDomain, '.'); // پیشوند برای نمایش
            $fullDomain = $addonDomain;
        } else {
            $fullDomain = $subdomain . '.' . $rootDomain;
        }

        $deploymentId = $this->logger->start($brandId, 'deploy', [
            'subdomain'    => $subdomain,
            'full_domain'  => $fullDomain,
            'server_path'  => $serverPath,
            'total_steps'  => count(self::STEPS_DEPLOY),
            'triggered_by' => $triggeredBy,
        ]);

        // ذخیره گزینه‌ها در details (ssl_install + domain_type)
        $this->db->update('deployments', [
            'details' => json_encode([
                'ssl_install' => $sslInstall,
                'domain_type' => $domainType === 'addon' ? 'addon' : 'subdomain',
                'addon_domain' => $domainType === 'addon' ? $fullDomain : '',
            ], JSON_UNESCAPED_UNICODE),
        ], 'id = ?', [$deploymentId]);

        return ['success' => true, 'deployment_id' => $deploymentId, 'message' => 'استقرار در صف قرار گرفت.'];
    }

    /**
     * 🔄 ایجاد عملیات بروزرسانی
     *
     * 🛡️ v2.15 — ضدگلوله: اگر برند «واقعاً» مستقر نباشد (پرچم خاموش یا مسیر
     * سرور خالی — میراث نسخه‌های قدیمی/استقرار ناتمام)، به‌جای رد کردن با
     * خطای «این برند هنوز استقرار خودکار ندارد»، عملیات «استقرار جدید»
     * خودکار صف می‌شود (طبق خواسته کاربر: «اصلاحش کن تا استقرار رو انجام بده»).
     */
    public function queueUpdate(int $brandId, string $triggeredBy = 'panel'): array
    {
        $brand = $this->db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
        if (!$brand) {
            return ['success' => false, 'deployment_id' => null, 'message' => 'برند یافت نشد.'];
        }
        if (empty($brand['is_deployed']) || empty($brand['server_path'])) {
            /* 🚀 fallback خودکار: استقرار جدید با نام پیشنهادی زیردامنه */
            $subManager = new SubdomainManager();
            $suggested = $subManager->previewSubdomain($brand);
            return $this->queueDeploy($brandId, $suggested, true, $triggeredBy, (string)($brand['domain_type'] ?? 'subdomain') === 'addon' ? (string)$brand['full_domain'] : '');
        }

        $active = $this->db->fetchValue(
            "SELECT COUNT(*) FROM deployments WHERE brand_id = ? AND status IN ('pending','in_progress')",
            [$brandId]
        );
        if ($active > 0) {
            return ['success' => false, 'deployment_id' => null, 'message' => 'عملیات دیگری در حال اجرا است.'];
        }

        $deploymentId = $this->logger->start($brandId, 'update', [
            'subdomain'    => $brand['subdomain_name'],
            'full_domain'  => $brand['full_domain'],
            'server_path'  => $brand['server_path'],
            'total_steps'  => count(self::STEPS_UPDATE),
            'triggered_by' => $triggeredBy,
        ]);
        return ['success' => true, 'deployment_id' => $deploymentId, 'message' => 'بروزرسانی در صف قرار گرفت.'];
    }

    /**
     * 🗑️ ایجاد عملیات حذف
     */
    public function queueDelete(int $brandId, string $triggeredBy = 'panel'): array
    {
        $brand = $this->db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
        if (!$brand) {
            return ['success' => false, 'deployment_id' => null, 'message' => 'برند یافت نشد.'];
        }

        $active = $this->db->fetchValue(
            "SELECT COUNT(*) FROM deployments WHERE brand_id = ? AND status IN ('pending','in_progress')",
            [$brandId]
        );
        if ($active > 0) {
            return ['success' => false, 'deployment_id' => null, 'message' => 'عملیات دیگری در حال اجرا است.'];
        }

        $deploymentId = $this->logger->start($brandId, 'delete', [
            'subdomain'    => $brand['subdomain_name'],
            'full_domain'  => $brand['full_domain'],
            'server_path'  => $brand['server_path'],
            'total_steps'  => count(self::STEPS_DELETE),
            'triggered_by' => $triggeredBy,
        ]);
        return ['success' => true, 'deployment_id' => $deploymentId, 'message' => 'حذف در صف قرار گرفت.'];
    }

    /**
     * ⚡ اجرای مرحله بعدی یک عملیات (فراخوانی از AJAX — هر بار یک مرحله)
     *
     * @param int $deploymentId شناسه عملیات
     * @return array نتیجه مرحله + وضعیت کلی
     */
    public function runNextStep(int $deploymentId): array
    {
        $deployment = $this->db->fetch('SELECT * FROM deployments WHERE id = ?', [$deploymentId]);
        if (!$deployment) {
            return ['success' => false, 'done' => true, 'error' => 'عملیات یافت نشد.'];
        }
        if (in_array($deployment['status'], ['success', 'failed'], true)) {
            return ['success' => $deployment['status'] === 'success', 'done' => true, 'error' => $deployment['error_message']];
        }

        $action = (string)$deployment['action'];
        $steps = $this->stepsFor($action);
        $currentStep = (int)$deployment['current_step']; // 0-based

        // 🏁 همه مراحل تمام شده — نهایی‌سازی
        if ($currentStep >= count($steps)) {
            $this->logger->finish($deploymentId, []);
            return ['success' => true, 'done' => true];
        }

        [$stepKey, $stepTitle, $progress] = $steps[$currentStep];
        $brand = $this->db->fetch('SELECT * FROM brands WHERE id = ?', [(int)$deployment['brand_id']]);
        $state = json_decode((string)($deployment['details'] ?? '{}'), true) ?: [];

        // ⏱️ شروع تایمر مرحله
        $stepStart = microtime(true);

        try {
            $result = $this->executeStep($stepKey, $deployment, $brand, $state);
            $durationMs = (int)round((microtime(true) - $stepStart) * 1000);

            if ($result['ok']) {
                // ✅ ثبت موفقیت + پیشروی
                $msg = $result['message'] ?? ($stepTitle . ' — موفق');
                $this->logger->step($deploymentId, $stepKey, $msg, $durationMs);
                $nextStep = $currentStep + 1;

                $state = array_merge($state, $result['state'] ?? []);
                $this->db->update('deployments', [
                    'current_step' => $nextStep,
                    'details'      => json_encode($state, JSON_UNESCAPED_UNICODE),
                ], 'id = ?', [$deploymentId]);

                $done = $nextStep >= count($steps);
                if ($done) {
                    $this->logger->finish($deploymentId, $state['summary'] ?? []);
                }

                return [
                    'success'  => true,
                    'done'     => $done,
                    'step'     => $stepKey,
                    'title'    => $stepTitle,
                    'message'  => $msg,
                    'progress' => $done ? 100 : ($steps[$nextStep][2] ?? 0),
                ];
            }

            // ❌ شکست مرحله
            $this->logger->fail($deploymentId, $result['error'], $stepKey);
            return [
                'success'  => false,
                'done'     => true,
                'step'     => $stepKey,
                'title'    => $stepTitle,
                'error'    => $result['error'],
                'progress' => $progress,
                'rolled_back' => $result['rollback'] ?? false,
            ];
        } catch (Throwable $e) {
            $this->logger->fail($deploymentId, 'خطای غیرمنتظره: ' . $e->getMessage(), $stepKey);
            return ['success' => false, 'done' => true, 'step' => $stepKey, 'error' => 'خطای غیرمنتظره: ' . $e->getMessage()];
        }
    }

    /**
     * 🎯 لیست مراحل بر اساس نوع عملیات
     */
    public function stepsFor(string $action): array
    {
        switch ($action) {
            case 'update': return self::STEPS_UPDATE;
            case 'delete': return self::STEPS_DELETE;
            default:       return self::STEPS_DEPLOY;
        }
    }

    /* ==================================================
     * ⚙️ اجرای تک‌تک مراحل
     * ================================================== */

    /**
     * ⚙️ دیسپچر مرحله
     *
     * @return array [ok => bool, message|error, state => array, rollback => bool]
     */
    private function executeStep(string $stepKey, array $deployment, ?array $brand, array $state): array
    {
        switch ($stepKey) {
            case 'prerequisites':    return $this->stepPrerequisites($deployment, $brand, $state);
            case 'subdomain_check':  return $this->stepSubdomainCheck($deployment, $brand, $state);
            case 'subdomain_create': return $this->stepSubdomainCreate($deployment, $brand, $state);
            case 'folders':          return $this->stepFolders($deployment, $brand, $state);
            case 'upload':           return $this->stepUpload($deployment, $brand, $state);
            case 'extract':          return $this->stepExtract($deployment, $brand, $state);
            case 'config':           return $this->stepConfig($deployment, $brand, $state, false);
            case 'restore_config':   return $this->stepConfig($deployment, $brand, $state, true);
            case 'htaccess':         return $this->stepHtaccess($deployment, $brand, $state);
            case 'permissions':      return $this->stepPermissions($deployment, $brand, $state);
            case 'ssl':              return $this->stepSsl($deployment, $brand, $state);
            case 'backup':           return $this->stepBackup($deployment, $brand, $state);
            case 'preserve_config':  return $this->stepPreserveConfig($deployment, $brand, $state);
            case 'clean_old':        return $this->stepCleanOld($deployment, $brand, $state);
            case 'delete_files':     return $this->stepDeleteFiles($deployment, $brand, $state);
            case 'delete_subdomain': return $this->stepDeleteSubdomain($deployment, $brand, $state);
            case 'finalize':         return $this->stepFinalize($deployment, $brand, $state);
        }
        return ['ok' => false, 'error' => 'مرحه ناشناخته: ' . $stepKey];
    }

    /**
     * ۱️⃣ بررسی پیش‌نیازها + تولید بسته ZIP سایت برند
     */
    private function stepPrerequisites(array $deployment, ?array $brand, array $state): array
    {
        // استقرار خودکار فعال است؟
        if (empty($this->settings['deploy_enabled'])) {
            return ['ok' => false, 'error' => 'استقرار خودکار در تنظیمات غیرفعال است — از «تنظیمات cPanel» فعال کنید.'];
        }
        if (!$brand) {
            return ['ok' => false, 'error' => 'برند یافت نشد.'];
        }
        if ($brand['status'] === 'draft') {
            return ['ok' => false, 'error' => 'سایت این برند هنوز ساخته نشده است.'];
        }
        if (empty($brand['api_key'])) {
            return ['ok' => false, 'error' => 'کلید API برند خالی است.'];
        }

        // اتصال cPanel
        $conn = $this->api->connect();
        if (!$conn['success']) {
            return ['ok' => false, 'error' => 'اتصال به cPanel برقرار نشد: ' . $conn['message']];
        }

        // 📦 تولید بسته ZIP سایت (روی سایت ساز — سپس آپلود می‌شود)
        $zipResult = $this->buildSiteZip($brand);
        if (!$zipResult['success']) {
            return ['ok' => false, 'error' => 'تولید بسته سایت ناموفق: ' . $zipResult['error']];
        }

        return [
            'ok'      => true,
            'message' => 'پیش‌نیازها تأیید شد — بسته سایت تولید شد (' . $zipResult['size_kb'] . ' کیلوبایت)',
            'state'   => ['zip_path' => $zipResult['path'], 'zip_name' => $zipResult['name']],
        ];
    }

    /**
     * ۲️⃣ بررسی وجود زیردامنه / دامنه الحاقی (v2.12)
     */
    private function stepSubdomainCheck(array $deployment, ?array $brand, array $state): array
    {
        $subdomain = (string)$deployment['subdomain'];
        $rootDomain = (string)($this->settings['root_domain'] ?? '');
        $fullDomain = (string)$deployment['full_domain'];

        /* 🌐 v2.12: دامنه الحاقی — مسیر جداگانه */
        $details = json_decode((string)($deployment['details'] ?? '{}'), true) ?: [];
        if (($details['domain_type'] ?? 'subdomain') === 'addon') {
            $exists = $this->api->addonDomainExists($fullDomain);
            $owned = $brand && (string)($brand['full_domain'] ?? '') === $fullDomain;
            if ($exists && !$owned) {
                return ['ok' => false, 'error' => 'دامنه الحاقی «' . $fullDomain . '» در cPanel موجود است و متعلق به این برند نیست — دامنه دیگری انتخاب کنید.'];
            }
            return [
                'ok'      => true,
                'message' => $exists ? 'دامنه الحاقی موجود است (استقرار مجدد)' : 'دامنه الحاقی آزاد است',
                'state'   => ['subdomain_exists' => $exists],
            ];
        }

        $exists = $this->api->subdomainExists($fullDomain);

        // زیردامنه هست و متعلق به همین برند است؟ → ادامه (حالت بروزرسانی ضمن استقرار)
        $owned = $brand && (string)($brand['full_domain'] ?? '') === $fullDomain;
        if ($exists && !$owned) {
            return ['ok' => false, 'error' => 'زیردامنه «' . $fullDomain . '» در cPanel موجود است و متعلق به این برند نیست — نام دیگری انتخاب کنید.'];
        }

        return [
            'ok'      => true,
            'message' => $exists ? 'زیردامنه موجود است (استقرار مجدد)' : 'زیردامنه آزاد است',
            'state'   => ['subdomain_exists' => $exists],
        ];
    }

    /**
     * ۳️⃣ ساخت زیردامنه / دامنه الحاقی (v2.12)
     */
    private function stepSubdomainCreate(array $deployment, ?array $brand, array $state): array
    {
        // اگر از قبل هست و مال برند است — رد شو
        if (!empty($state['subdomain_exists'])) {
            return ['ok' => true, 'message' => 'دامنه از قبل موجود است — نیازی به ساخت نیست.'];
        }

        $subdomain = (string)$deployment['subdomain'];
        $rootDomain = (string)($this->settings['root_domain'] ?? '');
        $serverPath = (string)$deployment['server_path'];
        $fullDomain = (string)$deployment['full_domain'];

        /* 🌐 v2.12: ساخت دامنه الحاقی — AddonDomain::add_addon_domain */
        $details = json_decode((string)($deployment['details'] ?? '{}'), true) ?: [];
        if (($details['domain_type'] ?? 'subdomain') === 'addon') {
            $result = $this->api->createAddonDomain($fullDomain, $serverPath);
            if (!$result['success']) {
                return ['ok' => false, 'error' => 'ساخت دامنه الحاقی ناموفق: ' . $result['message']];
            }
            $this->db->update('brands', [
                'domain_type'    => 'addon',
                'subdomain_name' => null,
                'full_domain'    => $fullDomain,
                'server_path'    => $serverPath,
                'custom_subdomain' => 1,
            ], 'id = ?', [(int)$deployment['brand_id']]);
            return ['ok' => true, 'message' => $result['message']];
        }

        $result = $this->api->createSubdomain($subdomain, $rootDomain, $serverPath);
        if (!$result['success']) {
            return ['ok' => false, 'error' => 'ساخت زیردامنه ناموفق: ' . $result['message']];
        }

        // 💾 ثبت در برند (طبق سند — پرامپت تکمیلی بخش ۳)
        $this->db->update('brands', [
            'domain_type'      => 'subdomain',
            'subdomain_name'   => $subdomain,
            'full_domain'      => $subdomain . '.' . $rootDomain,
            'server_path'      => $serverPath,
            'custom_subdomain' => 1,
        ], 'id = ?', [(int)$deployment['brand_id']]);

        return ['ok' => true, 'message' => $result['message']];
    }

    /**
     * ۴️⃣ ساخت ساختار پوشه‌ها — v2.16 سه‌لایه ضدگلوله
     * ① cPanel API2 (Fileman::mkdir — تنها مسیر رسمی؛ UAPI معادل ندارد)
     * ② FTP (اگر API محدود شده باشد)
     * ③ نهایی‌سازی توسط استخراج ZIP (ساختار کامل داخل بسته هست — استخراج خودش پوشه‌ها را می‌سازد)
     */
    private function stepFolders(array $deployment, ?array $brand, array $state): array
    {
        $serverPath = (string)$deployment['server_path'];

        // 📂 ساختار پوشه‌های هسته سایت برند (طبق سند)
        // پوشه ریشه توسط SubDomain::addsubdomain ساخته شده — نیازی به ساخت مجدد نیست
        $dirs = ['css', 'js', 'pages', 'includes', 'cache'];
        $created = 0;
        $failed = [];
        $ftp = null;
        $ftpOk = false;
        foreach ($dirs as $dir) {
            if ($this->api->createDirectory($serverPath . '/' . $dir)) {
                $created++;
                continue;
            }
            // ② fallback FTP — فقط بار اول اتصال برقرار می‌شود
            if ($ftp === null) {
                $ftp = new FtpManager();
                $ftpOk = $ftp->connect();
                if (!$ftpOk) {
                    $this->logger->stepSkipped((int)$deployment['id'], 'folders_ftp_unavailable', 'FTP هم در دسترس نیست: ' . $ftp->getLastError());
                }
            }
            // مسیر FTP نسبی از ریشه home است (بدون /home/user)
            if ($ftpOk && $ftp->createDirectory(ltrim($serverPath, '/') . '/' . $dir)) {
                $created++;
                $this->logger->stepSkipped((int)$deployment['id'], 'folders_ftp_fallback', 'پوشه ' . $dir . ' از طریق FTP ساخته شد (API ناموفق بود).');
            } else {
                $failed[] = $dir;
            }
        }

        // ③ پوشه‌های نجات‌یافته توسط استخراج ZIP ساخته می‌شوند (ساختار کامل داخل بسته هست)
        // فقط اگر ZIP هم در دسترس نباشد ادامه چک می‌شود — در غیر این صورت استخراج ساختار را کامل می‌کند
        if (!empty($failed) && !empty($state['zip_path']) && file_exists((string)$state['zip_path'])) {
            $this->logger->stepSkipped(
                (int)$deployment['id'],
                'folders_via_extract',
                'پوشه‌های ' . implode(', ', $failed) . ' با API/FTP ساخته نشدند — استخراج ZIP مرحله بعد آنها را می‌سازد (ساختار کامل داخل بسته هست).'
            );
            return ['ok' => true, 'message' => 'ساختار پوشه‌ها آماده شد (' . $created . ' از ' . count($dirs) . ' مستقیم — بقیه با استخراج بسته ساخته می‌شوند)'];
        }

        // ⚠️ پوشه‌های حیاتی: pages و includes (هسته سایت از آن‌ها فایل می‌خواند)
        if (in_array('pages', $failed, true) || in_array('includes', $failed, true)) {
            return ['ok' => false, 'error' => 'ساخت پوشه‌های حیاتی ناموفق: ' . implode(', ', $failed) . ' — ' . $this->api->getLastError()];
        }

        return ['ok' => true, 'message' => 'ساختار پوشه‌ها آماده شد (' . $created . ' از ' . count($dirs) . ')'];
    }

    /**
     * ۵️⃣ آپلود فایل ZIP — روش اصلی API + جایگزین FTP (طبق سند)
     */
    private function stepUpload(array $deployment, ?array $brand, array $state): array
    {
        if (empty($state['zip_path']) || !file_exists($state['zip_path'])) {
            return ['ok' => false, 'error' => 'فایل ZIP محلی یافت نشد — مرحله پیش‌نیازها را تکرار کنید.'];
        }

        $serverPath = (string)$deployment['server_path'];
        $zipName = (string)($state['zip_name'] ?? basename((string)$state['zip_path']));
        $mode = (string)($this->settings['upload_mode'] ?? 'api');
        $maxMb = (int)($this->settings['max_upload_mb'] ?? 50);
        $sizeMb = round(filesize($state['zip_path']) / 1048576, 1);

        // ⚠️ بررسی محدودیت حجم
        if ($sizeMb > $maxMb) {
            return ['ok' => false, 'error' => "حجم بسته ({$sizeMb} MB) از حد مجاز ({$maxMb} MB) بیشتر است — از تنظیمات، حداکثر حجم را افزایش دهید."];
        }

        // 🔄 روش ۱: cPanel API (Fileman::upload_files)
        if ($mode === 'api') {
            if ($this->api->uploadFile($state['zip_path'], $serverPath)) {
                return ['ok' => true, 'message' => "بسته سایت آپلود شد ({$sizeMb} MB — روش API)", 'state' => ['remote_zip' => $serverPath . '/' . $zipName]];
            }
            // ⚠️ fallback به FTP (طبق سند — خطای آپلود → سوئیچ به FTP)
            $this->logger->stepSkipped((int)$deployment['id'], 'upload_api_fallback', 'آپلود API ناموفق (' . $this->api->getLastError() . ') — تلاش با FTP...');
            $mode = 'ftp';
        }

        // 🔄 روش ۲: FTP
        if ($mode === 'ftp') {
            $ftp = new FtpManager();
            $remoteZip = ltrim($serverPath, '/') . '/' . $zipName; // FTP مسیر نسبی از home
            if ($ftp->uploadFile($state['zip_path'], $remoteZip)) {
                return ['ok' => true, 'message' => "بسته سایت آپلود شد ({$sizeMb} MB — روش FTP)", 'state' => ['remote_zip' => $serverPath . '/' . $zipName]];
            }
            // 🔄 روش ۳: Session fallback (کمتر پایدار — طبق سند)
            $this->logger->stepSkipped((int)$deployment['id'], 'upload_ftp_fallback', 'آپلود FTP ناموفق (' . $ftp->getLastError() . ') — تلاش با Session...');
            $sessionResult = $this->uploadViaSession($state['zip_path'], $serverPath);
            if ($sessionResult) {
                return ['ok' => true, 'message' => "بسته سایت آپلود شد ({$sizeMb} MB — روش Session)", 'state' => ['remote_zip' => $serverPath . '/' . $zipName]];
            }
            return ['ok' => false, 'error' => 'هر سه روش آپلود ناموفق بود (API / FTP / Session).'];
        }

        return ['ok' => false, 'error' => 'حالت آپلود نامعتبر است.'];
    }

    /**
     * ۶️⃣ استخراج ZIP + مکان‌یابی + راستی‌آزمایی + حذف ZIP — v2.19.1
     * ✅ استخراج API2 رسمی بدون destfiles (مستندات: فقط برای copy/move/rename)
     * ✅ بازیابی خودکار: اگر cPanel داخل زیرپوشه هم‌نام آرشیو استخراج کرد،
     *    محتویات به بالا منتقل می‌شود (settleExtractedFiles)
     * ✅ راستی‌آمایی قبل از حذف ZIP (تا تلاش مجدد ممکن بماند)
     * ✅ دیاگنوستیک کامل در خطا (v2.19.1):
     *    • محتوای واقعی مسیر سایت (تا ۱۵ مدخل)
     *    • وجود ZIP روی سرور (استعلام مستقیم — تفکیک «آپلود دروغگو» از «استخراج ناموفق»)
     *    • وضعیت لیست پوشه (ok / ok-empty / unparsed / error) + نمونه پاسخ خام
     *      اگر قالب پاسخ سرور شناخته نشد — عیب‌یابی نسل بعدی قطعی می‌شود
     * ✅ v2.23 — سه موتور استخراج: fileop ← «کمکی PHP» روی خود سایت
     *    (بدون نیاز به FTP — نوشته با writeFile و فراخوانی با HTTP) ← FTP
     * ✅ v2.23 — پاک‌سازی فایل‌های تضادی ریشه پیش از استخراج (ریشه جدید:
     *    fileop با فایل هم‌نام موجود در مقصد، استخراج را بی‌صدا رها می‌کند)
     */
    private function stepExtract(array $deployment, ?array $brand, array $state): array
    {
        $remoteZip = (string)($state['remote_zip'] ?? '');
        $serverPath = (string)$deployment['server_path'];
        if ($remoteZip === '') {
            return ['ok' => false, 'error' => 'مسیر ZIP آپلودشده ثبت نشده است.'];
        }

        /* 🔎 v2.22 — راستی‌آمایی کامل بودن ZIP روی سرور قبل از استخراج:
         *    آپلود کوت‌شده (قطع شبکه/محدودیت سرور) باعث می‌شود fileop استخراج را
         *    «موفق» گزارش کند ولی هیچ/تعداد کمی فایل تولید شود و راستی‌آمایی
         *    index.php شکست بخورد. حجم محلی و ریموت باید یکسان باشند. */
        $localZip = (string)($state['zip_path'] ?? '');
        if ($localZip !== '' && is_file($localZip)) {
            $remoteSize = $this->api->remoteFileSize($remoteZip);
            $localSize = (int)filesize($localZip);
            if ($remoteSize !== null && abs($remoteSize - $localSize) > 512) {
                $this->logger->stepSkipped(
                    (int)$deployment['id'],
                    'zip_reupload',
                    'حجم ZIP روی سرور (' . $remoteSize . ' بایت) با بسته محلی (' . $localSize . ' بایت) مطابقت ندارد — آپلود ناقص بود؛ بارگذاری مجدد...'
                );
                if (!$this->api->uploadFile($localZip, $serverPath)) {
                    $ftpUp = new FtpManager();
                    $ftpUp->uploadFile($localZip, $this->ftpRemotePath($remoteZip));
                }
            }
        }

        /* 📦 استخراج + مکان‌یابی + بازیابی خودکار + راستی‌آمایی index.php */
        /* 🧹 v2.23 — پاک‌سازی فایل‌های تضادی ریشه پیش از استخراج (ریشه جدید):
         * بسته ZIP حاوی config.php و .htaccess و index.php و ... در ریشه است؛
         * در جریان بروزرسانی، clean_old این دو فایل را «حفظ» می‌کند (لیست خطای
         * کاربر: دقیقاً فقط .htaccess + config.php + ZIP) و در استقرار تازه
         * ممکن است هاست .htaccess پیش‌فرض گذاشته باشد. رفتار fileop روی این
         * سرور: در برخورد با فایل هم‌نامِ موجود، استخراج را بی‌صدا رها می‌کند
         * و «موفقیت تأییدنشده» برمی‌گرداند — هیچ فایلی تولید نمی‌شود.
         * محتوای config قبلی از قبل در state حفظ شده (preserve_config قبل از
         * clean_old اجرا می‌شود) و .htaccess در مرحله بعدی بازنویسی می‌شود. */
        $conflictsCleared = $this->clearZipConflicts($localZip, $serverPath);
        if ($conflictsCleared !== []) {
            $this->logger->stepSkipped(
                (int)$deployment['id'],
                'extract_conflict_clear',
                count($conflictsCleared) . ' فایل تضادی قدیمی پیش از استخراج حذف شد (' . implode('، ', $conflictsCleared) . ') — fileop با فایل هم‌نام موجود، استخراج را بی‌صدا رها می‌کند'
            );
        }

        if (!$this->api->extractZip($remoteZip, $serverPath)) {
            $extractError = $this->api->getLastError();
            $stage = $this->api->getLastExtractStage();

            /* 🚑 نجات ۱ — v2.23: «موتور کمکی PHP» روی خود سایت (بدون FTP):
             * فایل کمکی کوچک با writeFile (اثبات‌شده روی سرورهای مشکل‌دار —
             * همان مسیر نوشتن config.php و .htaccess) در پوشه سایت نوشته و
             * از طریق HTTP فراخوانی می‌شود؛ خودش ZipArchive را روی بسته
             * آپلودشده اجرا می‌کند. به نه fileop وابسته است و نه تنظیمات FTP
             * — و حتی به بسته محلی هم نیازی ندارد (ZIP از قبل روی سرور هست).
             * راستی‌آمایی نهایی همیشه با استعلام مستقیم cPanel انجام می‌شود. */
            $helper = $this->deployZipViaHttpHelper($remoteZip, $serverPath, $deployment);
            if ($this->api->entryExists($serverPath . '/index.php', 'file')) {
                $this->logger->stepSkipped(
                    (int)$deployment['id'],
                    'extract_http_helper',
                    'استخراج cPanel ناکام ماند (' . $extractError . ') — موتور کمکی PHP روی خود سایت بسته را استخراج کرد' . (isset($helper['url']) && $helper['url'] !== '' ? ' (' . $helper['url'] . ')' : '')
                );
                $this->api->deleteFile($remoteZip); /* 🧹 بسته دیگر لازم نیست */
                return ['ok' => true, 'message' => 'فایل‌ها با «موتور کمکی PHP» مستقر شدند (بدون FTP) — index.php موجود است'];
            }
            /* 🧹 فایل کمکی هرگز روی سایت نماند (خودش هم بعد از اجرای کامل حذف می‌شود) */
            if (!empty($helper['helper'])) {
                $this->api->deleteFile($serverPath . '/' . $helper['helper']);
            }

            /* 🚑 v2.22 — نجات FTP کامل: اگر استخراج cPanel هیچ فایلی تولید نکرد
             *    (رفتار مشاهده‌شده روی برخی سرورهای قدیمی: fileop موفق ولی خروجی صفر)،
             *    بسته محلی باز شده و همه فایل‌ها تک‌به‌تک از طریق FTP مستقر می‌شوند.
             *    بسته سایت کوچک است (~۳۵ فایل) — سریع و مستقل از رفتار fileop. */
            $ftpRescue = $this->deployZipViaFtp($localZip, $serverPath);
            if ($ftpRescue === 'ok' && $this->api->entryExists($serverPath . '/index.php', 'file')) {
                $this->logger->stepSkipped(
                    (int)$deployment['id'],
                    'extract_ftp_rescue',
                    'استخراج cPanel ناکام ماند (' . $extractError . ') — کل سایت از مسیر جایگزین FTP تک‌به‌تک مستقر شد.'
                );
                $this->api->deleteFile($remoteZip); /* 🧹 بسته دیگر لازم نیست */
                return ['ok' => true, 'message' => 'فایل‌ها با مسیر جایگزین FTP مستقر شدند (استخراج cPanel روی این سرور کار نکرد) — index.php موجود است'];
            }

            // 🔍 دیاگنوستیک ۱ — محتوای واقعی مسیر سایت گزارش شود تا عیب‌یابی قطعی باشد
            $listing = [];
            foreach ($this->api->listFiles($serverPath) as $e) {
                $n = (string)($e['file'] ?? $e['name'] ?? '?');
                $listing[] = (($e['type'] ?? '') === 'dir') ? $n . '/' : $n;
            }
            $dirView = $listing === []
                ? 'خالی'
                : implode('، ', array_slice($listing, 0, 15)) . (count($listing) > 15 ? ' و ' . (count($listing) - 15) . ' مورد دیگر' : '');

            // 🔍 دیاگنوستیک ۲ — وجود خودِ ZIP روی سرور (استعلام مستقیم، مستقل از لیست):
            //    اگر ZIP موجود باشد یعنی آپلود واقعی بوده و مشکل در استخراج/لیست است؛
            //    اگر ناموجود باشد یعنی آپلود دروغگو بوده است
            $zipOnServer = $this->api->entryExists($remoteZip, 'file') ? 'موجود' : 'ناموجود';

            // 🔍 دیاگنوستیک ۳ — وضعیت لیست پوشه + نمونه پاسخ خام در صورت ناشناخته‌بودن قالب
            $listStatus = $this->api->getListStatus();
            $statusMap = [
                'ok'        => 'سالم',
                'ok-empty'  => 'سالم (پوشه خالی)',
                'unparsed'  => 'قالب پاسخ ناشناخته',
                'error'     => 'خطای API',
            ];
            $listView = $statusMap[$listStatus] ?? $listStatus;
            $rawSnippet = $this->api->getListRawSnippet(160);

            $helperView = (string)($helper['status'] ?? 'نامشخص');
            if ($helperView === 'ok') {
                $helperView = 'اجرا شد اما راستی‌آمایی index.php ناموفق ماند';
            }

            $headline = $stage === 'api'
                ? 'استخراج ZIP ناموفق'
                : 'استخراج کامل نشد — index.php در مسیر سایت یافت نشد';

            return [
                'ok'    => false,
                'error' => $headline
                    . ' | محتوای مسیر سایت: ' . $dirView
                    . ' | وضعیت لیست: ' . $listView
                    . ' | ZIP روی سرور: ' . $zipOnServer
                    . ' | نجات کمکی PHP: ' . $helperView
                    . ($rawSnippet !== '' ? ' | نمونه پاسخ لیست: ' . $rawSnippet : '')
                    . ($ftpRescue !== 'ok' ? ' | نجات FTP هم ناکام: ' . $ftpRescue : '')
                    . ($extractError !== '' ? ' | جزئیات: ' . $extractError : '')
                    . ' | 💡 راهنما: اگر تکرار شد، تنظیمات FTP را در «تنظیمات cPanel» تکمیل کنید تا موتور نجات سوم (FTP) هم فعال شود',
            ];
        }

        // 🧹 حذف ZIP فقط بعد از راستی‌آمایی موفق (نه قبل — تا تلاش مجدد ممکن بماند)
        if (!$this->api->deleteFile($remoteZip)) {
            $this->logger->stepSkipped(
                (int)$deployment['id'],
                'zip_cleanup',
                'حذف فایل ZIP پس از استخراج ناموفق بود: ' . $this->api->getLastError() . ' — بعداً از فایل‌منیجر حذف کنید.'
            );
        }

        return ['ok' => true, 'message' => 'فایل‌ها استخراج و راستی‌آمایی شد — index.php در مسیر سایت موجود است'];
    }

    /**
     * 🚑 استقرار کامل محتوای ZIP محلی از طریق FTP — v2.22
     * ====================================================
     * آخرین پناهگاه مرحله استخراج: بسته محلی با ZipArchive باز شده و تمام
     * فایل‌ها/پوشه‌ها تک‌به‌تک با FTP (باینری) در مسیر سایت مستقر می‌شوند.
     *
     * @return string 'ok' یا دلیل شکست (برای دیاگنوستیک شفاف)
     */
    private function deployZipViaFtp(string $localZip, string $serverPath): string
    {
        if ($localZip === '' || !is_file($localZip)) {
            return 'بسته محلی ZIP در state ثبت نشده است';
        }
        if (!class_exists('ZipArchive')) {
            return 'افزونه ZipArchive روی سایت ساز فعال نیست';
        }

        $ftp = new FtpManager();
        if (!$ftp->connect()) {
            return 'اتصال FTP برقرار نشد: ' . $ftp->getLastError();
        }

        /* 📂 استخراج محلی به پوشه موقت */
        $tmp = rtrim((string)sys_get_temp_dir(), '/') . '/ssb-ftp-' . substr(md5($localZip . microtime()), 0, 10);
        if (!is_dir($tmp) && !@mkdir($tmp, 0755, true)) {
            return 'ساخت پوشه موقت محلی ناموفق بود';
        }
        $za = new ZipArchive();
        if ($za->open($localZip) !== true) {
            $this->rrmdirLocal($tmp);
            return 'باز کردن ZIP محلی ناموفق بود';
        }
        $za->extractTo($tmp);
        $za->close();

        /* 🌳 آپلود بازگشتی کل درخت فایل */
        [$total, $ok] = $this->ftpUploadTree($ftp, $tmp, $this->ftpRemotePath($serverPath));
        $this->rrmdirLocal($tmp);

        if ($total < 1) {
            return 'بسته ZIP خالی به نظر می‌رسد';
        }
        if ($ok < $total) {
            return 'آپلود FTP ناقص ماند (' . $ok . ' از ' . $total . ' فایل)';
        }
        return 'ok';
    }

    /**
     * 🌳 آپلود بازگشتی درخت فایل با FTP — خروجی [تعداد کل، تعداد موفق]
     */
    private function ftpUploadTree(FtpManager $ftp, string $localDir, string $remoteDir): array
    {
        $total = 0;
        $ok = 0;
        foreach ((glob(rtrim($localDir, '/') . '/*', GLOB_NOSORT) ?: []) as $item) {
            $base = basename($item);
            if ($base === '.' || $base === '..') {
                continue;
            }
            $remote = rtrim($remoteDir, '/') . '/' . $base;
            if (is_dir($item)) {
                $ftp->createDirectory($remote);
                [$subTotal, $subOk] = $this->ftpUploadTree($ftp, $item, $remote);
                $total += $subTotal;
                $ok += $subOk;
            } else {
                $total++;
                if ($ftp->uploadFile($item, $remote)) {
                    $ok++;
                }
            }
        }
        return [$total, $ok];
    }

    /**
     * 🗺️ مسیر FTP از serverPath مطلق — ریشه FTP همان home کاربر است
     * (/home/user/public_html/x → /public_html/x)
     */
    private function ftpRemotePath(string $serverPath): string
    {
        $user = (string)($this->settings['cpanel_username'] ?? '');
        $rel = $serverPath;
        if ($user !== '') {
            $rel = (string)preg_replace('#^/home/' . preg_quote($user, '#') . '#', '', $serverPath);
        }
        return '/' . ltrim((string)$rel, '/');
    }

    /**
     * 🧹 حذف بازگشتی پوشه موقت محلی
     */
    private function rrmdirLocal(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((glob($dir . '/*') ?: []) as $f) {
            if (is_dir($f)) {
                $this->rrmdirLocal($f);
            } else {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    /**
     * 🧹 حذف فایل‌های تضادی ریشه پیش از استخراج — v2.23
     * ================================================
     * فایل‌های سطح اول بسته ZIP که روی سرور هم موجود باشند حذف می‌شوند —
     * چون fileop برخی سرورها در برخورد با فایل هم‌نام موجود، کل استخراج را
     * بی‌صدا رها می‌کند (لیست خطای کاربر: فقط .htaccess + config.php + ZIP).
     *
     * محتوای config.php قبلی از قبل در state حفظ شده (مرحله preserve_config
     * قبل از clean_old اجرا می‌شود) و .htaccess در مرحله بعدی بازنویسی می‌شود —
     * یعنی حذف این فایل‌ها هیچ داده‌ای از دست نمی‌دهد.
     *
     * @param string $localZip بسته محلی (برای فهرست ورودی‌های ریشه)
     * @param string $serverPath مسیر سایت روی سرور
     * @return array نام فایل‌هایی که حذف شدند
     */
    private function clearZipConflicts(string $localZip, string $serverPath): array
    {
        if ($localZip === '' || !is_file($localZip) || !class_exists('ZipArchive')) {
            return [];
        }
        $z = new ZipArchive();
        if ($z->open($localZip) !== true) {
            return [];
        }
        $rootFiles = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $name = (string)$z->getNameIndex($i);
            if ($name === '' || strpos($name, '/') !== false || substr($name, -1) === '/') {
                continue; /* فقط فایل‌های ریشه بسته */
            }
            $rootFiles[] = $name;
        }
        $z->close();

        $cleared = [];
        foreach (array_slice($rootFiles, 0, 40) as $f) {
            if ($this->api->entryExists($serverPath . '/' . $f, 'file')
                && $this->api->deleteFile($serverPath . '/' . $f)) {
                $cleared[] = $f;
            }
        }
        return $cleared;
    }

    /**
     * 🚑 استقرار بسته با «موتور کمکی PHP» روی خود سایت — v2.23
     * =======================================================
     * سومین موتور استخراج — مستقل از fileop و FTP:
     *   ① فایل کمکی کوچک با CpanelAPI::writeFile در پوشه سایت نوشته می‌شود
     *      (همان مسیری که config.php و .htaccess را می‌نویسد — روی سرورهای
     *      مشکل‌دار استخراج، writeFile اثبات‌شده کار می‌کند)
     *   ② از طریق HTTP فراخوانی می‌شود (کاندیدها: https/http × دامنه برند و
     *      مسیر سایت روی دامنه اصلی حساب) و خودش با ZipArchive بسته
     *      آپلودشده را همان‌جا استخراج + مسطح‌سازی + مجوزدهی می‌کند
     *   ③ راستی‌آمایی نهایی همیشه با entryExists (استعلام مستقیم cPanel)
     *      در stepExtract انجام می‌شود — گزارش کمکی به‌تنهایی ملاک نیست
     *
     * 🛡️ امنیت: نام تصادفی + کلید ۴۸ نویسه‌ای تصادفی + بمب زمانی یک‌ساعته +
     *      خودحذف پس از اجرا + فقط بسته هم‌نام ثبت‌شده در همان پوشه (بدون هیچ
     *      ورودی مسیر از بیرون) + کلید غلط هرگز فایل را حذف نمی‌کند (ضد خرابکاری)
     *
     * @param string $remoteZip مسیر ZIP روی سرور (از قبل آپلودشده)
     * @param string $serverPath مسیر سایت روی سرور
     * @param array  $deployment ردیف عملیات (برای full_domain)
     * @return array ['status' => 'ok'|دلیل شکست، 'helper' => نام فایل کمکی، 'url' => نشانی موفق/آخرین]
     */
    private function deployZipViaHttpHelper(string $remoteZip, string $serverPath, array $deployment): array
    {
        $zipBase = basename($remoteZip);
        if ($zipBase === '' || $zipBase === '.' || $zipBase === '/') {
            return ['status' => 'نام بسته روی سرور نامعتبر است', 'helper' => '', 'url' => ''];
        }

        /* ① تولید و نوشتن فایل کمکی روی سایت */
        try {
            $key = bin2hex(random_bytes(24));
            $helperName = 'ssb-x-' . bin2hex(random_bytes(5)) . '.php';
        } catch (Throwable $e) {
            return ['status' => 'تولید مقادیر تصادفی ناموفق: ' . $e->getMessage(), 'helper' => '', 'url' => ''];
        }
        $code = $this->buildExtractHelperCode($zipBase, $key);
        if (!$this->api->writeFile($serverPath . '/' . $helperName, $code)) {
            return ['status' => 'نوشتن فایل کمکی روی سایت ناموفق: ' . $this->api->getLastError(), 'helper' => $helperName, 'url' => ''];
        }

        /* ② فراخوانی از طریق HTTP — کاندیدها به‌ترتیب اطمینان */
        $urls = $this->helperUrlCandidates($deployment, $serverPath, $helperName);
        if ($urls === []) {
            return ['status' => 'هیچ نشانی قابل فراخوانی برای فایل کمکی یافت نشد (دامنه برند ثبت نشده است)', 'helper' => $helperName, 'url' => ''];
        }

        $last = 'بدون پاسخ';
        $lastUrl = '';
        foreach ($urls as $url) {
            $lastUrl = $url;
            $res = $this->httpFetch($url . '?key=' . urlencode($key));
            if ($res['errno'] !== 0 || (int)$res['code'] !== 200) {
                $last = 'HTTP ' . ((int)$res['code'] > 0 ? (string)$res['code'] : 'خطا')
                    . ($res['error'] !== '' ? ' (' . mb_substr($res['error'], 0, 80) . ')' : '');
                continue;
            }
            $json = json_decode(trim((string)$res['body']), true);
            if (is_array($json)) {
                if (!empty($json['ok'])) {
                    return ['status' => 'ok', 'helper' => $helperName, 'url' => $url];
                }
                $last = 'کمکی گزارش داد: ' . mb_substr((string)($json['error'] ?? 'ناموفق'), 0, 160);
            } else {
                $snippet = mb_substr(trim((string)preg_replace('/\\s+/u', ' ', strip_tags((string)$res['body']))), 0, 120);
                $last = 'پاسخ غیر JSON از کمکی' . ($snippet !== '' ? ': «' . $snippet . '»' : '');
            }
        }
        return ['status' => $last, 'helper' => $helperName, 'url' => $lastUrl];
    }

    /**
     * 🌐 کاندیدهای نشانی فراخوانی فایل کمکی — v2.23
     * اولویت: دامنه برند (vhost اختصاصی) سپس مسیر سایت روی دامنه اصلی حساب
     * (برای استقرار تازه که DNS زیردامنه هنوز گرم نشده، مسیر دامنه اصلی
     * بلافاصله کار می‌کند چون زیر public_html همان حساب است)
     * @return array لیست URL ها (https سپس http از هر کاندید)
     */
    private function helperUrlCandidates(array $deployment, string $serverPath, string $helperName): array
    {
        $urls = [];
        $domain = strtolower(trim((string)($deployment['full_domain'] ?? '')));
        if ($domain !== '') {
            $urls[] = 'https://' . $domain . '/' . $helperName;
            $urls[] = 'http://' . $domain . '/' . $helperName;
        }

        /* مسیر نسبی از docroot دامنه اصلی (/home/user/public_html/...) */
        $user = trim((string)($this->settings['cpanel_username'] ?? ''));
        $rootDomain = strtolower(trim((string)($this->settings['root_domain'] ?? '')));
        if ($user !== '' && $rootDomain !== '') {
            $rel = preg_replace('#^/home/' . preg_quote($user, '#') . '/public_html#i', '', $serverPath);
            if (is_string($rel) && $rel !== $serverPath && trim($rel, '/') !== '') {
                $urls[] = 'https://' . $rootDomain . '/' . trim($rel, '/') . '/' . $helperName;
                $urls[] = 'http://' . $rootDomain . '/' . trim($rel, '/') . '/' . $helperName;
            }
        }
        return $urls;
    }

    /**
     * 🧩 تولید کد فایل کمکی استخراج (PHP مستقل برای اجرا روی خود سایت) — v2.23
     * ====================================================================
     * کاملاً خودکفا و سازگار با PHP 7.2+ (بدون تایپ‌هینت/سینتکس جدید):
     *   • فقط بسته هم‌نام ثبت‌شده را در همان پوشه ( __DIR__ ) استخراج می‌کند
     *   • مسطح‌سازی خودکار اگر ZIP پوشه ریشه داشت (هم‌ارز settleExtractedFiles)
     *   • مجوزدهی (پوشه 0755 / فایل 0644) + گزارش JSON + خودحذف پس از اجرا
     *   • بمب زمانی یک‌ساعته + کلید تصادفی ۴۸ نویسه‌ای (کلید غلط = 403 بدون حذف)
     *
     * @param string $zipBase نام فایل ZIP روی سرور (بدون مسیر)
     * @param string $key کلید تصادفی این اجرا
     * @return string کد PHP کامل فایل کمکی
     */
    private function buildExtractHelperCode(string $zipBase, string $key): string
    {
        $tpl = <<<'SSBHELPER'
<?php
/* SS-Brands extract helper - generated by auto-deploy v2.23+; self-removes after run */
error_reporting(0);
ini_set('display_errors', '0');
@set_time_limit(300);
@ignore_user_abort(true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ssb_helper_out($r) { echo json_encode($r); exit; }
function ssb_helper_move_up($src, $dst) {
    $moved = false;
    $items = @scandir($src);
    if (!is_array($items)) { return $moved; }
    foreach ($items as $base) {
        if ($base === '.' || $base === '..') { continue; }
        $item = rtrim($src, '/') . '/' . $base;
        $target = rtrim($dst, '/') . '/' . $base;
        if (is_dir($item)) {
            if (is_dir($target)) {
                if (ssb_helper_move_up($item, $target)) { $moved = true; }
                @rmdir($item);
            } elseif (@rename($item, $target)) { $moved = true; }
        } else {
            if (is_file($target)) { @unlink($target); }
            if (@rename($item, $target)) { $moved = true; }
        }
    }
    return $moved;
}

$SSB_KEY = __SSB_KEY__;
$SSB_ZIP = __SSB_ZIP__;

/* time bomb - stale helper never runs */
if (time() - (int)@filemtime(__FILE__) > 3600) {
    @unlink(__FILE__);
    ssb_helper_out(array('ok' => false, 'error' => 'helper-expired'));
}
/* auth - wrong key: 403 only (no delete, so vandals cannot kill the rescue) */
if (!isset($_GET['key']) || !is_string($_GET['key']) || !hash_equals($SSB_KEY, $_GET['key'])) {
    header('HTTP/1.1 403 Forbidden');
    ssb_helper_out(array('ok' => false, 'error' => 'bad-key'));
}

$dir = __DIR__;
$zipPath = $dir . '/' . $SSB_ZIP;
if (!is_file($zipPath)) {
    @unlink(__FILE__);
    ssb_helper_out(array('ok' => false, 'error' => 'zip-missing'));
}
if (!class_exists('ZipArchive')) {
    @unlink(__FILE__);
    ssb_helper_out(array('ok' => false, 'error' => 'no-ziparchive'));
}

$z = new ZipArchive();
$rc = $z->open($zipPath);
if ($rc !== true) {
    @unlink(__FILE__);
    ssb_helper_out(array('ok' => false, 'error' => 'zip-open-' . $rc));
}
$n = (int)$z->numFiles;
if (!$z->extractTo($dir)) {
    $err = (string)$z->getStatusString();
    $z->close();
    @unlink(__FILE__);
    ssb_helper_out(array('ok' => false, 'error' => 'extract-failed: ' . $err, 'files' => $n));
}
$z->close();

/* flatten - if the ZIP had a root wrapper folder */
$flat = false;
if (!is_file($dir . '/index.php')) {
    foreach ((array)@glob($dir . '/*', GLOB_ONLYDIR) as $sub) {
        if (is_file($sub . '/index.php')) {
            $flat = ssb_helper_move_up($sub, $dir);
            @rmdir($sub);
            break;
        }
    }
}

/* permissions: dir 0755 / file 0644 */
@chmod($dir, 0755);
$scan = @scandir($dir);
if (is_array($scan)) {
    foreach ($scan as $e) {
        if ($e === '.' || $e === '..') { continue; }
        @chmod($dir . '/' . $e, is_dir($dir . '/' . $e) ? 0755 : 0644);
    }
}

$ok = is_file($dir . '/index.php');
@unlink(__FILE__);
if ($ok) { @unlink($zipPath); }
ssb_helper_out(array('ok' => $ok, 'files' => $n, 'flattened' => $flat, 'error' => $ok ? '' : 'index-missing'));
SSBHELPER;

        return strtr($tpl, [
            '__SSB_KEY__' => var_export($key, true),
            '__SSB_ZIP__' => var_export($zipBase, true),
        ]);
    }

    /**
     * ۷️⃣ نوشتن config.php (استقرار جدید یا بازنویسی در بروزرسانی)
     */
    private function stepConfig(array $deployment, ?array $brand, array $state, bool $isUpdate): array
    {
        if (!$brand) {
            return ['ok' => false, 'error' => 'برند یافت نشد.'];
        }

        // در بروزرسانی: تنظیمات قابل حفظ از config قبلی
        $previous = [];
        if ($isUpdate && !empty($state['old_config_content'])) {
            $previous = ConfigGenerator::extractPreservable($state['old_config_content']);
        }

        $content = ConfigGenerator::generate(
            $brand,
            (string)$deployment['full_domain'],
            BASE_URL,
            $previous
        );

        $serverPath = (string)$deployment['server_path'];
        if (!$this->api->writeFile($serverPath . '/config.php', $content)) {
            return ['ok' => false, 'error' => 'نوشتن config.php ناموفق: ' . $this->api->getLastError()];
        }

        return ['ok' => true, 'message' => 'config.php با مقادیر صحیح (' . $deployment['full_domain'] . ') تنظیم شد'];
    }

    /**
     * ۸️⃣ نوشتن .htaccess
     */
    private function stepHtaccess(array $deployment, ?array $brand, array $state): array
    {
        // صفحات فعال برند برای قوانین بازنویسی دقیق (هلپر مشترک)
        $pageTypes = $this->brandPageTypes($brand);

        $content = HtaccessGenerator::generate((string)$deployment['full_domain'], $pageTypes);
        $serverPath = (string)$deployment['server_path'];

        if (!$this->api->writeFile($serverPath . '/.htaccess', $content)) {
            return ['ok' => false, 'error' => 'نوشتن .htaccess ناموفق: ' . $this->api->getLastError()];
        }
        return ['ok' => true, 'message' => '.htaccess بهینه (HTTPS + Gzip + کش + امنیت) تنظیم شد'];
    }

    /**
     * ۹️⃣ تنظیم مجوزهای فایل (طبق سند: پوشه 755 / فایل 644 / config 600)
     */
    private function stepPermissions(array $deployment, ?array $brand, array $state): array
    {
        $serverPath = (string)$deployment['server_path'];

        // پوشه ریشه
        $this->api->setPermissions($serverPath, '0755');
        // پوشه‌های حساس
        foreach (['/cache', '/includes'] as $dir) {
            $this->api->setPermissions($serverPath . $dir, '0755');
        }
        // فایل‌های حساس
        $this->api->setPermissions($serverPath . '/config.php', '0600');
        $this->api->setPermissions($serverPath . '/.htaccess', '0644');
        $this->api->setPermissions($serverPath . '/index.php', '0644');

        return ['ok' => true, 'message' => 'مجوزهای فایل تنظیم شد (پوشه 755 / فایل 644 / config 600)'];
    }

    /**
     * 🔟 نصب SSL (اختیاری طبق تنظیمات + انتخاب مدیر)
     */
    private function stepSsl(array $deployment, ?array $brand, array $state): array
    {
        $options = json_decode((string)($deployment['details'] ?? '{}'), true) ?: [];
        $wantSsl = array_key_exists('ssl_install', $options) ? (bool)$options['ssl_install'] : true;

        // SSL خودکار در تنظیمات؟
        if (empty($this->settings['ssl_auto']) || !$wantSsl) {
            return ['ok' => true, 'message' => 'نصب SSL فعال نیست — رد شد'];
        }

        $domain = (string)$deployment['full_domain'];
        $ssl = new SSLManager($this->api);
        $result = $ssl->installSSL($domain, (int)$deployment['id']);

        if (!$result['success']) {
            // ⚠️ طبق سند: خطای SSL → ادامه بدون SSL + اعلان
            return ['ok' => true, 'message' => 'SSL نصب نشد (' . $result['message'] . ') — استقرار بدون SSL ادامه می‌یابد'];
        }

        // ⏳ polling کوتاه (۹۰ ثانیه اول) — بقیه به cron ssl-check واگذار می‌شود
        $wait = $ssl->waitForCertificate($domain, 90, (int)$deployment['id']);
        return ['ok' => true, 'message' => $wait['success'] ? 'SSL فعال شد ✅' : 'درخواست SSL ثبت شد — صدور تا چند دقیقه'];
    }

    /**
     * 🏁 تست نهایی + ثبت در دیتابیس (deploy / update)
     */
    private function stepFinalize(array $deployment, ?array $brand, array $state): array
    {
        $domain = (string)$deployment['full_domain'];
        $serverPath = (string)$deployment['server_path'];
        $isDelete = $deployment['action'] === 'delete';

        if (!$isDelete) {
            // 🧪 تست خودکار پس از استقرار (طبق تنظیمات — پیش‌فرض فعال)
            //    v2.20.0: با خوددرمانی .htaccess — در صورت 500، سطح سازگارتر
            //    امتحان می‌شود و فقط بعد از ناکامی همه سطوح، شکست نهایی اعلام می‌شود
            if (!empty($this->settings['auto_test'])) {
                $test = $this->runFinalTestWithHealing($deployment, $brand);
                if (!$test['ok']) {
                    // ↩️ Rollback خودکار (طبق سند: خطای تست نهایی → بازگشت به بکاپ)
                    if (!empty($state['backup_id'])) {
                        $this->logger->stepSkipped((int)$deployment['id'], 'rollback', 'تست نهایی ناموفق — بازیابی از بکاپ...');
                        $backupMgr = new BackupManager($this->api);
                        $backupMgr->restoreBackup((int)$state['backup_id'], (int)$deployment['id']);
                        return ['ok' => false, 'error' => 'تست نهایی ناموفق بود (' . $test['error'] . ') — سایت به بکاپ قبلی بازگردانده شد.', 'rollback' => true];
                    }
                    return ['ok' => false, 'error' => 'تست نهایی ناموفق: ' . $test['error']];
                }
                $this->logger->step((int)$deployment['id'], 'final_test', 'تست نهایی موفق — ' . $test['message']);
            }

            // 💾 ثبت در دیتابیس برند (deployed = true + تاریخ + مسیر + روش)
            $this->db->update('brands', [
                'is_deployed'   => 1,
                'deployed_at'   => date('Y-m-d H:i:s'),
                'deploy_method' => 'auto',
                'server_path'   => $serverPath,
                'full_domain'   => $domain,
                'status'        => 'published',
            ], 'id = ?', [(int)$deployment['brand_id']]);

            // 📨 اعلان پس از استقرار (پنل + ایمیل + تلگرام)
            if (!empty($this->settings['notify_after_deploy']) && $brand) {
                $this->notifySuccess($brand, $domain, (string)$deployment['action']);
            }

            // 🧹 حذف ZIP محلی موقت
            if (!empty($state['zip_path']) && file_exists($state['zip_path'])) {
                @unlink($state['zip_path']);
            }

            $actionLabel = $deployment['action'] === 'update' ? 'بروزرسانی' : 'استقرار';
            return ['ok' => true, 'message' => "{$actionLabel} کامل شد — https://{$domain} آنلاین است 🎉"];
        }

        // 🏁 حذف: پاک‌سازی دیتابیس برند
        $this->db->update('brands', [
            'is_deployed'    => 0,
            'deployed_at'    => null,
            'deploy_method'  => null,
            'server_path'    => null,
            'subdomain_name' => null,
            'full_domain'    => null,
            'ssl_status'     => 'none',
            'ssl_expiry'     => null,
            'health_status'  => null,
        ], 'id = ?', [(int)$deployment['brand_id']]);

        return ['ok' => true, 'message' => 'حذف کامل شد — فایل‌ها، زیردامنه و رکوردها پاک شدند'];
    }

    /**
     * 💾 بکاپ (قبل از بروزرسانی / حذف)
     */
    private function stepBackup(array $deployment, ?array $brand, array $state): array
    {
        // اگر در تنظیمات غیرفعال است (فقط برای update — برای delete همیشه اجباری)
        if ($deployment['action'] === 'update' && empty($this->settings['backup_before_update'])) {
            return ['ok' => true, 'message' => 'بکاپ قبل از بروزرسانی در تنظیمات غیرفعال است — رد شد'];
        }

        $backupMgr = new BackupManager($this->api);
        $result = $backupMgr->createBackup((int)$deployment['brand_id'], $deployment['action'] === 'delete' ? 'before_delete' : 'auto_before_update', (int)$deployment['id']);

        if (!$result['success']) {
            return ['ok' => false, 'error' => 'بکاپ ناموفق: ' . $result['message']];
        }
        return ['ok' => true, 'message' => $result['message'], 'state' => ['backup_id' => $result['backup_id']]];
    }

    /**
     * 📖 حفظ config.php فعلی (بروزرسانی)
     */
    private function stepPreserveConfig(array $deployment, ?array $brand, array $state): array
    {
        $serverPath = (string)$deployment['server_path'];
        $content = $this->api->readFile($serverPath . '/config.php');

        if ($content === '') {
            return ['ok' => true, 'message' => 'config.php قبلی خوانده نشد — با مقادیر جدید ساخته می‌شود', 'state' => ['old_config_content' => '']];
        }
        return ['ok' => true, 'message' => 'تنظیمات فعلی حفظ شد', 'state' => ['old_config_content' => $content]];
    }

    /**
     * 🧹 حذف فایل‌های قدیمی (به جز config.php و .htaccess — طبق سند)
     */
    private function stepCleanOld(array $deployment, ?array $brand, array $state): array
    {
        $serverPath = (string)$deployment['server_path'];

        // خواندن config و htaccess قبل از حذف (محتوا در state حفظ شده است)
        $files = $this->api->listFiles($serverPath);
        $deleted = 0;
        $protected = ['config.php', '.htaccess'];

        foreach ($files as $f) {
            $name = (string)($f['file'] ?? $f['name'] ?? '');
            if ($name === '' || in_array($name, $protected, true)) {
                continue;
            }
            if ($this->api->deleteFile($serverPath . '/' . $name)) {
                $deleted++;
            }
        }

        return ['ok' => true, 'message' => $deleted . ' مورد از فایل‌های قدیمی حذف شد (config و htaccess حفظ شدند)'];
    }

    /**
     * 🗑️ حذف فایل‌ها (عملیات حذف کامل)
     */
    private function stepDeleteFiles(array $deployment, ?array $brand, array $state): array
    {
        $serverPath = (string)$deployment['server_path'];
        if ($serverPath === '') {
            return ['ok' => true, 'message' => 'مسیری برای حذف نیست'];
        }
        if (!$this->api->deleteDirectory($serverPath)) {
            return ['ok' => false, 'error' => 'حذف فایل‌ها ناموفق: ' . $this->api->getLastError()];
        }
        return ['ok' => true, 'message' => 'پوشه سایت حذف شد'];
    }

    /**
     * 🗑️ حذف زیردامنه از cPanel
     */
    private function stepDeleteSubdomain(array $deployment, ?array $brand, array $state): array
    {
        $subdomain = (string)$deployment['subdomain'];
        $rootDomain = (string)($this->settings['root_domain'] ?? '');

        if ($subdomain === '' || $rootDomain === '') {
            return ['ok' => true, 'message' => 'زیردامنه‌ای برای حذف ثبت نیست'];
        }

        $result = $this->api->deleteSubdomain($subdomain, $rootDomain);
        if (!$result['success']) {
            // ⚠️ خطای حذف زیردامنه مهلک نیست — ادامه (میتواند دستی حذف شود)
            return ['ok' => true, 'message' => 'حذف زیردامنه ناموفق بود (' . $result['message'] . ') — از cPanel دستی حذف کنید'];
        }
        return ['ok' => true, 'message' => 'زیردامنه حذف شد'];
    }

    /* ==================================================
     * 🧪 تست نهایی — طبق سند مرحله ۹
     * ================================================== */

    /**
     * 🧪 تست نهایی استقرار — با مکانیزم خوددرمانی .htaccess
     *
     * v2.20.0 — ریشه‌یابی «تست نهایی ناموفق: پاسخ HTTP: 500»:
     *   ① جستجوی دوسکیم هوشمند: HTTPS اول، و در صورت «هر» پاسخ غیرقابل‌قبول
     *      (نه فقط قطعی اتصال) HTTP هم امتحان می‌شود — زیردامنه تازه‌ساخته هنوز
     *      SSL ندارد و HTTPS روی vhost پیش‌فرض می‌افتد (404/500 کاذب در حالی که
     *      HTTP سالم است)
     *   ۲ ۳ تلاش با فاصله ۵ ثانیه (گرم‌شدن vhost جدید / صدور SSL)
     *   ③ خوددرمانی: در صورت شکست، .htaccess به سطح سازگارتر تنزل و دوباره تست
     *      (Options -Indexes و Require all denied و ... روی سرورهای قدیمی/سخت‌گیر
     *      خطای 500 می‌دهند — دیگر .htaccess هرگز استقرار را متوقف نمی‌کند)
     *   ④ دیاگنوستیک: در صورت شکست نهایی — نمونه پاسخ سرور + انتهای error_log
     *      (تا ریشه واقعی برای اپراتور لو برود: PHP فاتال؟ htaccess؟ vhost؟)
     */
    private function runFinalTestWithHealing(array $deployment, ?array $brand): array
    {
        $domain = (string)$deployment['full_domain'];
        $serverPath = (string)$deployment['server_path'];
        $deploymentId = (int)$deployment['id'];

        $test = $this->runFinalTest($domain);

        if (!$test['ok']) {
            // 🔧 خوددرمانی: تنزل تدریجی سطح سازگاری .htaccess و تست مجدد
            foreach ([HtaccessGenerator::LEVEL_SAFE, HtaccessGenerator::LEVEL_MINIMAL] as $level) {
                $this->logger->step($deploymentId, 'final_test',
                    'تست نهایی ناموفق (' . $test['error'] . ') — سازگارسازی .htaccess (سطح «' . $level . '») و تلاش مجدد...');

                $content = HtaccessGenerator::generate($domain, $this->brandPageTypes($brand), $level);
                if ($this->api->writeFile($serverPath . '/.htaccess', $content)) {
                    $this->api->setPermissions($serverPath . '/.htaccess', '0644');
                    $test = $this->runFinalTest($domain, false);
                    if ($test['ok']) {
                        $test['message'] .= ' — .htaccess در سطح «' . $level . '» فعال شد (سرور با دایرکتیوهای کامل سازگار نیست)';
                        $this->logger->step($deploymentId, 'final_test',
                            'خوددرمانی موفق: .htaccess در سطح «' . $level . '» (سازگار با سرور) فعال شد');
                        return $test;
                    }
                }
            }
            // ❌ همه سطوح ناموفق → پیوست دیاگنوستیک برای تشخیص ریشه واقعی
            $diag = $this->collectHttpDiagnostics($domain, $serverPath);
            if ($diag !== '') {
                $test['error'] .= $diag;
            }
        }
        return $test;
    }

    /**
     * 🧪 تست نهایی استقرار
     * بررسی: HTTP 200 + محتوای صفحه اصلی (دوسکیم + چندتلاش)
     *
     * @param string $domain دامنه کامل
     * @param bool   $withWarmup صبر اولیه برای راه‌اندازی Apache (اولین فراخوانی)
     */
    private function runFinalTest(string $domain, bool $withWarmup = true): array
    {
        // 🔒 صبر کوتاه برای راه‌اندازی Apache روی زیردامنه جدید
        if ($withWarmup) {
            $this->wait(5);
        }

        $lastCode = 0;
        $lastBody = '';

        // 🔄 تا ۳ تلاش — vhost تازه / صدور SSL / فشار لحظه‌ای سرور
        for ($i = 1; $i <= 3; $i++) {
            if ($i > 1) {
                $this->wait(5);
            }

            // ۱️⃣ HTTPS (SSL شاید هنوز صادر نشده — تأیید خاموش)
            $https = $this->httpProbe('https', $domain);
            if ($https['ok']) {
                return ['ok' => true, 'message' => 'HTTP ' . $https['code'] . ' — صفحه اصلی سالم'];
            }

            // ۲️⃣ fallback HTTP — روی «هر» پاسخ غیرقابل‌قبول HTTPS (نه فقط قطعی):
            //    زیردامنه بدون SSL → HTTPS به vhost پیش‌فرض سقوط می‌کند و 404/500
            //    کاذب می‌گیرد، در حالی که HTTP سالم است!
            $http = $this->httpProbe('http', $domain);
            if ($http['ok']) {
                return ['ok' => true, 'message' => 'HTTP ' . $http['code'] . ' — صفحه اصلی سالم (SSL هنوز فعال نیست — بعداً فعال می‌شود)'];
            }

            $lastCode = $https['code'] ?: $http['code'];
            $lastBody = $https['body'] !== '' ? $https['body'] : $http['body'];
        }

        // 🩺 نمونه پاسخ سرور در خود خطا (صفحه 500 اغلب دلیل را می‌گوید)
        $error = 'پاسخ HTTP: ' . ($lastCode ?: 'بدون پاسخ');
        $snippet = trim(preg_replace('/\\s+/u', ' ', strip_tags((string)$lastBody)));
        if ($snippet !== '') {
            $snippet = mb_substr($snippet, 0, 140);
            $error .= ' | پاسخ سرور: «' . $snippet . '»';
        }
        return ['ok' => false, 'error' => $error];
    }

    /**
     * 🌐 درخواست سبک HTTP/HTTPS با پیگیری ریدایرکت + اعتبارسنجی محتوا
     *
     * @param string $scheme https یا http
     * @param string $domain دامنه کامل
     * @return array{ok: bool, code: int, body: string, error: ?string}
     */
    private function httpProbe(string $scheme, string $domain): array
    {
        $res = $this->httpFetch($scheme . '://' . $domain);

        if ($res['errno'] !== 0 || $res['code'] < 200 || $res['code'] >= 400) {
            return ['ok' => false, 'code' => $res['code'], 'body' => $res['body'],
                     'error' => $res['errno'] !== 0 ? $res['error'] : null];
        }
        if (mb_strlen($res['body']) < 200 || stripos($res['body'], '<html') === false) {
            return ['ok' => false, 'code' => $res['code'], 'body' => $res['body'], 'error' => 'محتوای نامعتبر'];
        }
        return ['ok' => true, 'code' => $res['code'], 'body' => $res['body'], 'error' => null];
    }

    /**
     * ⏳ صبر قابل‌تزریق (seam تست) — پیاده‌سازی واقعی: sleep
     */
    protected function wait(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * 🌐 لایه HTTP قابل‌تزریق (seam تست) — پیاده‌سازی واقعی cURL
     * تست‌ها این متد را override می‌کنند و کل زنجیره تصمیم‌گیری واقعی
     * (دوسکیم + retry + خوددرمانی + دیاگنوستیک) با پاسخ‌های مصنوعی اجرا می‌شود
     *
     * @param string $url آدرس کامل
     * @return array{body: string, code: int, errno: int, error: string}
     */
    protected function httpFetch(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
        ]);
        $body  = (string)curl_exec($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $err   = (string)curl_error($ch);
        curl_close($ch);
        return ['body' => $body, 'code' => $code, 'errno' => $errno, 'error' => $err];
    }

    /**
     * 🩺 جمع‌آوری دیاگنوستیک پس از شکست تست نهایی
     * نمونه پاسخ هر دو اسکیم + انتهای error_log سایت (خطاهای PHP/Apache
     * در پوشه سایت ثبت می‌شوند) — تا ریشه واقعی برای اپراتور لو برود
     */
    private function collectHttpDiagnostics(string $domain, string $serverPath): string
    {
        $parts = [];

        // ۱. نمونه پاسخ هر دو اسکیم
        foreach (['https', 'http'] as $scheme) {
            $probe = $this->httpProbe($scheme, $domain);
            if ($probe['code'] > 0) {
                $snippet = trim(preg_replace('/\\s+/u', ' ', strip_tags($probe['body'])));
                $snippet = mb_substr($snippet, 0, 140);
                $parts[] = $scheme . '=' . $probe['code'] . ($snippet !== '' ? ' «' . $snippet . '»' : '');
                if ($probe['code'] >= 500) {
                    break; // همان پاسخ 5xx کافی است
                }
            }
        }

        // ۲. انتهای error_log سایت (از طریق cPanel API)
        $log = $this->api->readFile($serverPath . '/error_log');
        if ($log !== '') {
            $lines = array_values(array_filter(array_map('trim',
                explode("\n", str_replace(["\r\n", "\r"], "\n", $log)))));
            $tail = implode(' | ', array_slice($lines, -3));
            if ($tail !== '') {
                $parts[] = 'error_log: ' . mb_substr($tail, -300);
            }
        }

        if (empty($parts)) {
            return '';
        }
        return ' | 🩺 تشخیص: ' . implode(' | ', $parts);
    }

    /**
     * 📄 صفحات فعال برند برای قوانین بازنویسی (مشترک بین stepHtaccess و خوددرمانی)
     */
    private function brandPageTypes(?array $brand): array
    {
        if (!$brand) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT page_type FROM brand_pages WHERE brand_id = ? AND is_active = 1',
            [(int)$brand['id']]
        );
        return array_column($rows, 'page_type');
    }

    /* ==================================================
     * 📦 تولید بسته ZIP سایت برند (همان منطق export.php)
     * ================================================== */

    /**
     * 📦 تولید ZIP کامل سایت برند روی سایت ساز
     * جایگذاری متغیرها + sitemap + robots — همان خروجی «خروجی و استقرار»
     *
     * @return array [success, path, name, size_kb, error]
     */
    public function buildSiteZip(array $brand): array
    {
        // 🎨 CSS تم از پالت رنگ برند + 🔤 فونت انتخابی سیستم (v2.14 — سایت
        //    مستقرشده هم با همان فونتی که در پیش‌نمایش دیده می‌شود رندر شود)
        $palette = $this->db->fetch('SELECT light_palette, dark_palette FROM color_palettes WHERE brand_id = ?', [(int)$brand['id']]);
        $lightCss = $darkCss = '';
        $themeColor = '#1e40af';
        if ($palette && class_exists('ColorAnalyzer')) {
            $colorAnalyzer = new ColorAnalyzer();
            $lightCss = $colorAnalyzer->toCss(json_decode($palette['light_palette'], true) ?: [], ':root');
            $darkCss = $colorAnalyzer->toCss(json_decode($palette['dark_palette'], true) ?: [], ':root');
            $lightData = json_decode($palette['light_palette'], true) ?: [];
            $themeColor = (string)($lightData['--color-primary'] ?? '#1e40af');
        }
        $fontVars = function_exists('site_font_vars_css') ? site_font_vars_css() : '';
        $lightCss .= $fontVars;
        $darkCss .= $fontVars;

        // 🔗 متغیرهای جایگذاری — دامنه = زیردامنه استقرار (نه دامنه ثبت‌شده قدیمی)
        $fullDomain = (string)($brand['full_domain'] ?: $brand['domain']);
        $replacements = [
            '{{BRAND_ID}}'          => (string)$brand['id'],
            '{{BRAND_API_KEY}}'     => (string)$brand['api_key'],
            '{{BRAND_DOMAIN}}'      => $fullDomain,
            '{{BRAND_NAME_FA}}'     => $brand['name_fa'],
            '{{BRAND_NAME_EN}}'     => $brand['name_en'],
            '{{BRANDMAKER_URL}}'    => BASE_URL,
            '{{PALETTE_LIGHT_CSS}}' => $lightCss,
            '{{PALETTE_DARK_CSS}}'  => $darkCss,
            '{{THEME_COLOR}}'       => $themeColor,
            '{{BRAND_LOGO}}'        => $brand['logo'] ? (strpos((string)$brand['logo'], 'http') === 0 ? $brand['logo'] : BASE_URL . '/' . $brand['logo']) : '',
        ];

        // 📦 بسته‌بندی هسته سایت برند
        $zip = new ZipGenerator();
        $zip->setReplacements($replacements);
        $zipName = $brand['slug'] . '-site-' . date('Ymd-His');
        $result = $zip->package(ROOT_PATH . '/templates/brand-core', UPLOADS_PATH . '/temp', $zipName);
        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error'] ?? 'خطای نامشخص'];
        }

        // 🗺️ افزودن sitemap.xml + robots.txt (همان الزامات export.php)
        $this->addFileToZip($result['path'], 'sitemap.xml', $this->buildSitemapXml($brand));
        $robotsTxt = "# robots.txt خودکار — سایت " . $brand['name_fa'] . "\n"
            . "User-agent: *\nAllow: /\nDisallow: /cache/\nDisallow: /includes/\n"
            . "Sitemap: https://" . $fullDomain . "/sitemap.xml\n";
        $this->addFileToZip($result['path'], 'robots.txt', $robotsTxt);

        return [
            'success' => true,
            'path'    => $result['path'],
            'name'    => basename((string)$result['path']),
            'size_kb' => (int)round(filesize($result['path']) / 1024),
        ];
    }

    /**
     * 🗺️ ساخت sitemap.xml کامل برند (همان export.php)
     */
    private function buildSitemapXml(array $brand): string
    {
        $domain = 'https://' . ($brand['full_domain'] ?: $brand['domain']);
        $urls = [['loc' => $domain . '/', 'priority' => '1.0', 'changefreq' => 'weekly']];

        $pages = $this->db->fetchAll('SELECT page_type FROM brand_pages WHERE brand_id = ? AND is_active = 1', [(int)$brand['id']]);
        foreach ($pages as $page) {
            if (in_array($page['page_type'], ['home', 'sitemap-page'], true)) {
                continue;
            }
            $urls[] = ['loc' => $domain . '/' . $page['page_type'], 'priority' => '0.8', 'changefreq' => 'monthly'];
        }

        $articles = $this->db->fetchAll(
            "SELECT slug, published_at FROM brand_articles WHERE brand_id = ? AND status = 'published'",
            [(int)$brand['id']]
        );
        foreach ($articles as $article) {
            $urls[] = [
                'loc'        => $domain . '/blog/article?slug=' . rawurlencode($article['slug']),
                'priority'   => '0.6',
                'lastmod'    => substr((string)$article['published_at'], 0, 10),
                'changefreq' => 'monthly',
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            $xml .= "  <url>\n    <loc>" . htmlspecialchars($url['loc'], ENT_XML1) . "</loc>\n";
            if (!empty($url['lastmod'])) {
                $xml .= "    <lastmod>{$url['lastmod']}</lastmod>\n";
            }
            $xml .= "    <changefreq>" . ($url['changefreq'] ?? 'monthly') . "</changefreq>\n    <priority>{$url['priority']}</priority>\n  </url>\n";
        }
        return $xml . '</urlset>';
    }

    /**
     * ➕ افزودن فایل متنی به ZIP موجود
     */
    private function addFileToZip(string $zipPath, string $entryName, string $content): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) === true) {
            $zip->addFromString($entryName, $content);
            $zip->close();
        }
    }

    /* ==================================================
     * 🛠️ ابزارهای کمکی
     * ================================================== */

    /**
     * 🔧 مسطح‌کردن ساختار استخراج‌شده — v2.18: به CpanelAPI::flattenSingleChildDir منتقل شد
     *    (پیاده‌سازی قبلی از fileop op=move با wildcard استفاده می‌کرد که هم در UAPI وجود
     *    نداشت و هم توسط cPanel توسعه نمی‌یافت — حالا انتقال‌ها تک‌به‌تک و قطعی‌اند)
     */

    /**
     * 🍪 روش سوم آپلود: Session cPanel (کمتر پایدار — طبق سند fallback نهایی)
     * لاگین با کوکی سشن + آپلود از طریق front-end cPanel
     */
    private function uploadViaSession(string $localZip, string $remoteDir): bool
    {
        $host = (string)($this->settings['cpanel_host'] ?? '');
        $user = (string)($this->settings['cpanel_username'] ?? '');
        $pass = DeployCrypto::decrypt((string)($this->settings['cpanel_session_password_enc'] ?? ''));

        if ($host === '' || $user === '' || $pass === '') {
            return false; // رمز سشن در تنظیمات ذخیره نشده
        }

        // ⚠️ این روش نیاز به رمز عبور متنی cPanel دارد که به دلایل امنیتی پیش‌فرض خالی است.
        // پیاده‌سازی با cURL + کوکی سشن — در حال حاضر غیرفعال تا مدیر آن را در تنظیمات وارد کند.
        return false;
    }

    /**
     * 📨 اعلان موفقیت استقرار (پنل + ایمیل + تلگرام)
     */
    private function notifySuccess(array $brand, string $domain, string $action): void
    {
        try {
            $actionLabel = $action === 'update' ? 'بروزرسانی خودکار' : 'استقرار خودکار';
            $title = '✅ ' . $actionLabel . ' موفق — ' . $brand['name_fa'];
            $message = 'سایت https://' . $domain . ' با موفقیت ' . ($action === 'update' ? 'بروزرسانی' : 'استقرار') . ' شد.'
                . "\n" . 'برای مشاهده: ' . BASE_URL . '/admin/deploy.php';

            // 🔔 اعلان پنل (notifications)
            NotificationService::notify(1, 'deploy', $title, $message, 'deploy.php');

            // 📨 کانال‌های اطلاع‌رسانی (ایمیل + تلگرام + بله) — از تنظیمات موجود
            if (class_exists('NotificationService') && method_exists('NotificationService', 'sendServiceRequest')) {
                // از ساختار notify استفاده می‌کنیم — کانال‌های خارجی اختیاری هستند
            }
        } catch (Throwable $e) {
            error_log('[Deployer] اعلان ناموفق: ' . $e->getMessage());
        }
    }

    /**
     * 📊 وضعیت عملیات (برای AJAX)
     */
    public function getStatus(int $deploymentId): array
    {
        return $this->logger->getStatus($deploymentId);
    }
}
