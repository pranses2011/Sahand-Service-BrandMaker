<?php
/**
 * 📊 اندپوینت AJAX وضعیت و اجرای استقرار
 * =======================================
 * طبق سند بخش ۲۱ — فایل deploy-status.php (AJAX):
 *   - POST action=start   → ایجاد عملیات در صف
 *   - POST action=step    → اجرای مرحله بعدی + برگرداندن پیشرفت
 *   - POST action=status  → وضعیت عملیات (بدون اجرا)
 *   - POST action=cancel  → لغو عملیات pending
 *   - POST action=validate_subdomain → اعتبارسنجی زنده نام زیردامنه
 *   - POST action=preview → پیش‌نمایش استقرار (نام + مسیر + وضعیت)
 *   - GET  (مستقیم)       → نمای HTML وضعیت برای اشتراک‌گذاری
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$auth = new Auth();
$auth->requireLogin();

/* 📥 ورودی JSON یا POST */
$input = Router::jsonInput();
$action = (string)($input['action'] ?? ($_POST['action'] ?? ''));

/* 🛡️ CSRF برای AJAX */
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    json_response(['success' => false, 'error' => 'توکن CSRF نامعتبر است.'], 419);
}

$db = Database::getInstance();

switch ($action) {

    /* 🚀 شروع عملیات استقرار */
    case 'start':
        $brandId = (int)($input['brand_id'] ?? 0);
        $subdomain = strtolower(trim((string)($input['subdomain'] ?? '')));
        $sslInstall = !empty($input['ssl_install']);
        $updateMode = !empty($input['update_mode']); // بروزرسانی به جای استقرار جدید
        /* 🌐 v2.12: نوع دامنه — زیردامنه (پیش‌فرض) یا دامنه الحاقی Addon */
        $domainType = ($input['domain_type'] ?? 'subdomain') === 'addon' ? 'addon' : 'subdomain';
        $addonDomain = strtolower(trim((string)($input['addon_domain'] ?? '')));

        $brand = $db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
        if (!$brand) {
            json_response(['success' => false, 'error' => 'برند یافت نشد.']);
        }

        /* 🛡️ v2.15 — تصمیم «سرورمحور» درباره نوع عملیات: حالت بروزرسانی/استقرار
           از واقعیت زنده دیتابیس محاسبه می‌شود، نه از پارامتر کلاینت. ریشه‌یابی:
           اگر فرانت (کش مرورگر/نسخه قدیمی JS) update_mode=true می‌فرستاد در حالی
           که برند مسیر سرور نداشت، queueUpdate رد می‌شد و کاربر پیام «این برند
           هنوز استقرار خودکار ندارد» می‌دید. حالا چنین حالتی خودکار به «استقرار
           جدید» تبدیل می‌شود و برعکس. */
        $reallyDeployed = !empty($brand['is_deployed']) && trim((string)($brand['server_path'] ?? '')) !== '';
        $updateMode = $reallyDeployed;

        $deployer = new Deployer();
        if ($updateMode) {
            $result = $deployer->queueUpdate($brandId, 'panel');
        } elseif ($domainType === 'addon') {
            // اعتبارسنجی دامنه الحاقی قبل از صف
            $check = SubdomainValidator::validateFullDomain($addonDomain);
            if (!$check['valid']) {
                json_response(['success' => false, 'error' => $check['error']]);
            }
            // تکرار در دیتابیس (برند دیگر با همین دامنه)
            $existing = $db->fetch('SELECT id, name_fa FROM brands WHERE full_domain = ? AND id != ? LIMIT 1', [$addonDomain, $brandId]);
            if ($existing) {
                json_response(['success' => false, 'error' => 'این دامنه قبلاً برای برند «' . $existing['name_fa'] . '» ثبت شده است.']);
            }
            $result = $deployer->queueDeploy($brandId, strtok($addonDomain, '.'), $sslInstall, 'panel', 'addon', $addonDomain);
        } else {
            // اعتبارسنجی نهایی نام زیردامنه قبل از صف
            $settings = PathResolver::getSettings();
            $rootDomain = (string)($settings['root_domain'] ?? '');
            if ($rootDomain === '') {
                json_response(['success' => false, 'error' => 'دامنه اصلی در تنظیمات cPanel ثبت نشده است.']);
            }
            $subManager = new SubdomainManager();
            $check = $subManager->validateSubdomainName($subdomain, $rootDomain, $brandId);
            if (!$check['valid']) {
                json_response(['success' => false, 'error' => $check['error'], 'suggestions' => $check['suggestions']]);
            }
            $result = $deployer->queueDeploy($brandId, $subdomain, $sslInstall, 'panel');
        }

        /* 🛡️ نرمال‌سازی کلید خطا — Deployer با «message» برمی‌گرداند؛
           فرانت (deploy.php) «error» را می‌خواند → بدون این نگاشت، خطای
           «❌ undefined» نمایش داده می‌شد */
        if (empty($result['success'])) {
            $result['error'] = $result['error'] ?? ($result['message'] ?? 'عملیات آغاز نشد.');
        }
        json_response($result + ['step_delay' => 800]);

    /* ⚡ اجرای مرحله بعدی (حلقه پیشرفت) */
    case 'step':
        $deploymentId = (int)($input['deployment_id'] ?? 0);
        $deployer = new Deployer();
        $result = $deployer->runNextStep($deploymentId);
        if (empty($result['success']) && empty($result['error']) && isset($result['message'])) {
            $result['error'] = $result['message'];
        }
        json_response($result + ['step_delay' => 900]);

    /* 📊 فقط وضعیت (بدون اجرا) */
    case 'status':
        $deploymentId = (int)($input['deployment_id'] ?? 0);
        $deployer = new Deployer();
        json_response($deployer->getStatus($deploymentId));

    /* 🗑️ لغو عملیات در صف */
    case 'cancel':
        $deploymentId = (int)($input['deployment_id'] ?? 0);
        $deployment = $db->fetch("SELECT * FROM deployments WHERE id = ? AND status IN ('pending','in_progress')", [$deploymentId]);
        if (!$deployment) {
            json_response(['success' => false, 'error' => 'عملیات قابل لغو نیست.']);
        }
        $db->update('deployments', [
            'status'        => 'failed',
            'error_message' => 'توسط مدیر لغو شد',
            'completed_at'  => date('Y-m-d H:i:s'),
        ], 'id = ?', [$deploymentId]);
        json_response(['success' => true, 'message' => 'عملیات لغو شد.']);

    /* ✅ اعتبارسنجی زنده نام زیردامنه / دامنه الحاقی */
    case 'validate_subdomain':
        $name = strtolower(trim((string)($input['name'] ?? '')));
        $brandId = (int)($input['brand_id'] ?? 0);
        $domainType = ($input['domain_type'] ?? 'subdomain') === 'addon' ? 'addon' : 'subdomain';

        /* 🌐 v2.12: دامنه الحاقی — قالب دامنه کامل + تکرار */
        if ($domainType === 'addon') {
            $check = SubdomainValidator::validateFullDomain($name);
            if (!$check['valid']) {
                json_response(['available' => false, 'error' => $check['error'], 'suggestions' => []]);
            }
            $existing = $db->fetch('SELECT id, name_fa FROM brands WHERE full_domain = ? AND id != ? LIMIT 1', [$name, $brandId]);
            if ($existing) {
                json_response(['available' => false, 'error' => 'این دامنه قبلاً برای برند «' . $existing['name_fa'] . '» ثبت شده است.', 'suggestions' => []]);
            }
            $api = new CpanelAPI();
            if ($api->addonDomainExists($name)) {
                json_response(['available' => false, 'error' => 'دامنه «' . $name . '» در cPanel از قبل ثبت شده است.', 'suggestions' => []]);
            }
            json_response(['available' => true, 'error' => 'دامنه معتبر و آزاد است — مطمئن شوید DNS آن به این سرور اشاره می‌کند.', 'suggestions' => []]);
        }

        $settings = PathResolver::getSettings();
        $rootDomain = (string)($settings['root_domain'] ?? '');
        if ($rootDomain === '') {
            json_response(['valid' => false, 'error' => 'دامنه اصلی در تنظیمات ثبت نشده.', 'suggestions' => []]);
        }
        $subManager = new SubdomainManager();
        $result = $subManager->checkAvailability($name, $rootDomain, $brandId);
        json_response($result);

    /* 👁️ پیش‌نمایش استقرار (نام + مسیر + وضعیت) */
    case 'preview':
        $brandId = (int)($input['brand_id'] ?? 0);
        $brand = $db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
        if (!$brand) {
            json_response(['success' => false, 'error' => 'برند یافت نشد.']);
        }
        $settings = PathResolver::getSettings();
        $subManager = new SubdomainManager();
        $deployer = new Deployer();

        $subdomain = $subManager->previewSubdomain($brand);
        $rootDomain = (string)($settings['root_domain'] ?? '');
        $serverPath = PathResolver::resolveDocumentRoot($brand);

        /* 🛡️ v2.14: استقرار واقعی = پرچم is_deployed + مسیر سرور معتبر
           (قبلاً اگر is_deployed=1 ولی server_path خالی بود، فرانت حالت
           «بروزرسانی» نشان می‌داد و queueUpdate با خطای «این برند هنوز
           استقرار خودکار ندارد» رد می‌شد — حالت استقرار جدید درست است) */
        $reallyDeployed = !empty($brand['is_deployed']) && trim((string)($brand['server_path'] ?? '')) !== '';

        $steps = $deployer->stepsFor($reallyDeployed ? 'update' : 'deploy');

        json_response([
            'success'    => true,
            'brand'      => ['id' => (int)$brand['id'], 'name_fa' => $brand['name_fa'], 'name_en' => $brand['name_en']],
            'is_deployed' => $reallyDeployed,
            'subdomain'  => $subdomain,
            'full_domain'=> $subdomain . '.' . $rootDomain,
            'root_domain'=> $rootDomain,
            'domain_type'=> (string)($brand['domain_type'] ?? 'subdomain'), // 🌐 v2.12
            'server_path'=> $serverPath,
            'ssl_auto'   => (bool)($settings['ssl_auto'] ?? true),
            'settings_ok'=> !empty($settings['deploy_enabled']) && !empty($settings['cpanel_host']) && $rootDomain !== '',
            'steps'      => array_map(function ($s) { return ['key' => $s[0], 'title' => $s[1], 'progress' => $s[2]]; }, $steps),
        ]);

    /* 🔄 بروزرسانی دسته‌ای — صف‌کردن چند برند (یکی‌یکی پردازش می‌شوند) */
    case 'batch_update':
        $brandIds = array_map('intval', (array)($input['brand_ids'] ?? []));
        if (empty($brandIds)) {
            json_response(['success' => false, 'error' => 'هیچ برندی انتخاب نشده است.']);
        }
        $deployer = new Deployer();
        $queued = 0;
        $errors = [];
        foreach ($brandIds as $bid) {
            $r = $deployer->queueUpdate($bid, 'panel');
            if ($r['success']) { $queued++; } else { $errors[] = $r['message']; }
        }
        json_response(['success' => $queued > 0, 'queued' => $queued, 'errors' => $errors]);

    default:
        json_response(['success' => false, 'error' => 'اکشن نامعتبر است.'], 400);
}
