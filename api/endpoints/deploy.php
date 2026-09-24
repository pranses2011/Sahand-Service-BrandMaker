<?php
/**
 * 🚀 اندپوینت API استقرار (خارج از سایت ساز)
 * ==========================================
 * طبق سند بخش ۲۱ — api/endpoints/deploy.php
 *
 * 🔒 امنیت: علاوه بر X-API-Key، کلید باید مجوز can_deploy داشته باشد
 *    (از پنل: کلید سیستمی با دسترسی استقرار بسازید)
 *
 * اندپوینت‌ها:
 *   POST /api/deploy/{brandId}           → شروع استقرار (بدنه: subdomain, ssl_install)
 *   POST /api/deploy/{brandId}/update    → شروع بروزرسانی
 *   GET  /api/deploy/{brandId}/status    → وضعیت آخرین عملیات
 *   GET  /api/deploy/{deploymentId}/logs → لاگ مراحل یک عملیات
 *   POST /api/deploy/step/{deploymentId} → اجرای مرحله بعدی (برای پردازش خارجی صف)
 *
 * @package SahandBrandMaker
 */

if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

/**
 * 🛡️ بررسی مجوز استقرار کلید فعلی
 * (کلیدهای معمولی سایت برند اجازه استقرار ندارند)
 */
function api_deploy_require_permission(): void
{
    $key = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? '');
    $record = Database::getInstance()->fetch(
        'SELECT id, can_deploy, label FROM api_keys WHERE api_key = ? AND is_active = 1 LIMIT 1',
        [$key]
    );
    if (!$record || empty($record['can_deploy'])) {
        json_response([
            'success' => false,
            'error'   => 'این کلید API مجوز استقرار ندارد — از پنل، کلیدی با دسترسی can_deploy بسازید',
        ], 403);
    }
}

/**
 * 🚀 شروع استقرار جدید برند
 *
 * 🌐 v2.12: بدنه می‌تواند domain_type = 'subdomain' (پیش‌فرض) یا 'addon' باشد؛
 *    در حالت addon، پارامتر addon_domain الزامی است (دامنه کامل مثل mybrand.ir)
 */
function api_deploy_start(int $brandId): void
{
    api_deploy_require_permission();

    $db = Database::getInstance();
    $brand = $db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
    if (!$brand) {
        json_response(['success' => false, 'error' => 'برند یافت نشد'], 404);
    }

    $input = Router::jsonInput();
    $settings = PathResolver::getSettings();
    $rootDomain = (string)($settings['root_domain'] ?? '');
    $domainType = ($input['domain_type'] ?? 'subdomain') === 'addon' ? 'addon' : 'subdomain';
    $sslInstall = !isset($input['ssl_install']) ? true : (bool)$input['ssl_install'];

    /* 🌐 مسیر دامنه الحاقی (Addon) */
    if ($domainType === 'addon') {
        $addonDomain = strtolower(trim((string)($input['addon_domain'] ?? '')));
        $check = SubdomainValidator::validateFullDomain($addonDomain);
        if (!$check['valid']) {
            json_response(['success' => false, 'error' => $check['error']], 422);
        }
        $existing = $db->fetch('SELECT id, name_fa FROM brands WHERE full_domain = ? AND id != ? LIMIT 1', [$addonDomain, $brandId]);
        if ($existing) {
            json_response(['success' => false, 'error' => 'این دامنه قبلاً برای برند «' . $existing['name_fa'] . '» ثبت شده است.'], 409);
        }
        $deployer = new Deployer();
        $result = $deployer->queueDeploy($brandId, strtok($addonDomain, '.'), $sslInstall, 'api', 'addon', $addonDomain);
        json_response($result + [
            'steps' => array_map(function ($s) {
                return ['key' => $s[0], 'title' => $s[1]];
            }, $deployer->stepsFor('deploy')),
        ], $result['success'] ? 200 : 400);
    }

    if ($rootDomain === '') {
        json_response(['success' => false, 'error' => 'دامنه اصلی در تنظیمات cPanel ثبت نشده است'], 400);
    }

    // نام زیردامنه: از بدنه یا از برند
    $subManager = new SubdomainManager();
    $subdomain = strtolower(trim((string)($input['subdomain'] ?? '')));
    if ($subdomain === '') {
        $subdomain = $subManager->previewSubdomain($brand);
    }

    // اعتبارسنجی
    $check = $subManager->validateSubdomainName($subdomain, $rootDomain, $brandId);
    if (!$check['valid']) {
        json_response([
            'success'     => false,
            'error'       => $check['error'],
            'suggestions' => $check['suggestions'],
        ], 422);
    }

    $deployer = new Deployer();
    $result = $deployer->queueDeploy($brandId, $subdomain, $sslInstall, 'api');

    json_response($result + [
        'steps' => array_map(function ($s) {
            return ['key' => $s[0], 'title' => $s[1]];
        }, $deployer->stepsFor('deploy')),
    ], $result['success'] ? 200 : 400);
}

/**
 * 🔄 شروع بروزرسانی
 */
function api_deploy_update(int $brandId): void
{
    api_deploy_require_permission();

    $deployer = new Deployer();
    $result = $deployer->queueUpdate($brandId, 'api');
    json_response($result, $result['success'] ? 200 : 400);
}

/**
 * 📊 وضعیت آخرین عملیات برند
 */
function api_deploy_status(int $brandId): void
{
    api_deploy_require_permission();

    $db = Database::getInstance();
    $deployment = $db->fetch(
        'SELECT * FROM deployments WHERE brand_id = ? ORDER BY id DESC LIMIT 1',
        [$brandId]
    );
    if (!$deployment) {
        json_response(['success' => false, 'error' => 'هنوز عملیاتی برای این برند ثبت نشده است'], 404);
    }

    $logger = new DeploymentLogger();
    json_response($logger->getStatus((int)$deployment['id']));
}

/**
 * 📜 لاگ مراحل یک عملیات مشخص
 */
function api_deploy_logs(int $deploymentId): void
{
    api_deploy_require_permission();
    $logger = new DeploymentLogger();
    $status = $logger->getStatus($deploymentId);
    json_response($status, $status['success'] ? 200 : 404);
}

/**
 * ⚡ اجرای مرحله بعدی (پردازش صف از سیستم خارجی)
 */
function api_deploy_step(int $deploymentId): void
{
    api_deploy_require_permission();
    $deployer = new Deployer();
    $result = $deployer->runNextStep($deploymentId);
    json_response($result);
}

/**
 * 📋 لیست عملیات اخیر (همه برندها)
 */
function api_deploy_list(): void
{
    api_deploy_require_permission();
    $logger = new DeploymentLogger();
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $rows = $logger->getRecent($limit);

    json_response([
        'success' => true,
        'data'    => array_map(function ($r) {
            return [
                'id'         => (int)$r['id'],
                'brand_id'   => (int)$r['brand_id'],
                'brand'      => $r['brand_name'],
                'action'     => $r['action'],
                'status'     => $r['status'],
                'domain'     => $r['full_domain'],
                'started_at' => $r['started_at'],
                'duration'   => (int)$r['duration_seconds'],
                'error'      => $r['error_message'],
            ];
        }, $rows),
    ]);
}
