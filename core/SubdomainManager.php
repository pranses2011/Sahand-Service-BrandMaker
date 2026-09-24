<?php
/**
 * 🌐 مدیر زیردامنه‌ها — پیش‌نمایش، اعتبارسنجی، ساخت
 * ==================================================
 * طبق سند بخش ۲۱ (بخش ۶ + پرامپت تکمیلی بخش ۳):
 *   - پیش‌نمایش نام پیش‌فرض از اسلاگ برند
 *   - اعتبارسنجی کامل (۸ قانون DNS) — SubdomainValidator
 *   - بررسی موجود بودن (دیتابیس + cPanel)
 *   - پیشنهاد نام‌های جایگزین
 *   - تأیید و ساخت نهایی
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class SubdomainManager
{
    /** @var CpanelAPI کلاینت cPanel */
    private $api;

    /** @var Database اتصال دیتابیس */
    private $db;

    /**
     * 🔧 سازنده
     */
    public function __construct(?CpanelAPI $api = null)
    {
        $this->api = $api ?? new CpanelAPI();
        $this->db  = Database::getInstance();
    }

    /**
     * 👁️ پیش‌نمایش نام پیش‌فرض زیردامنه برای یک برند
     * اولویت: subdomain_name ذخیره‌شده → اسلاگ برند
     *
     * @param array $brand ردیف برند
     * @return string نام پیشنهادی (مثلاً samsung)
     */
    public function previewSubdomain(array $brand): string
    {
        if (!empty($brand['subdomain_name'])) {
            return (string)$brand['subdomain_name'];
        }
        return SubdomainValidator::normalize((string)($brand['name_en'] ?: $brand['slug'] ?: $brand['name_fa']));
    }

    /**
     * ✅ اعتبارسنجی کامل نام زیردامنه (۸ قانون + بررسی تکرار)
     *
     * @param string $name نام زیردامنه
     * @param string $rootDomain دامنه اصلی
     * @param int    $excludeBrandId برند فعلی (برای ویرایش — از بررسی تکرار مستثنا)
     * @return array [valid => bool, error => string|null, suggestions => array]
     */
    public function validateSubdomainName(string $name, string $rootDomain, int $excludeBrandId = 0): array
    {
        // ۱. اعتبارسنجی قواعد
        $check = SubdomainValidator::validate($name);
        if (!$check['valid']) {
            return [
                'valid'       => false,
                'error'       => $check['error'],
                'suggestions' => SubdomainValidator::suggestAlternatives($name),
            ];
        }

        // ۲. بررسی تکرار در دیتابیس (برندهای دیگر با همین زیردامنه)
        $existing = $this->db->fetch(
            'SELECT id, name_fa FROM brands WHERE subdomain_name = ? AND id != ? LIMIT 1',
            [$name, $excludeBrandId]
        );
        if ($existing) {
            return [
                'valid'       => false,
                'error'       => 'این زیردامنه قبلاً برای برند «' . $existing['name_fa'] . '» ثبت شده است.',
                'suggestions' => SubdomainValidator::suggestAlternatives($name),
            ];
        }

        // ۳. بررسی تکرار در cPanel (اگر اتصال برقرار باشد)
        $fullDomain = $name . '.' . $rootDomain;
        if ($this->api->subdomainExists($fullDomain)) {
            // اگر همین زیردامنه متعلق به همین برند است (استقرار مجدد) — مجاز
            $owned = $this->db->fetch(
                'SELECT id FROM brands WHERE full_domain = ? AND id = ?',
                [$fullDomain, $excludeBrandId]
            );
            if (!$owned) {
                return [
                    'valid'       => false,
                    'error'       => 'زیردامنه «' . $fullDomain . '» در cPanel از قبل وجود دارد.',
                    'suggestions' => SubdomainValidator::suggestAlternatives($name),
                ];
            }
        }

        return ['valid' => true, 'error' => null, 'suggestions' => []];
    }

    /**
     * 🔍 بررسی موجود بودن نام (دیتابیس + cPanel)
     *
     * @return array [available => bool, error => string|null, suggestions => array]
     */
    public function checkAvailability(string $name, string $rootDomain, int $excludeBrandId = 0): array
    {
        $result = $this->validateSubdomainName($name, $rootDomain, $excludeBrandId);
        return [
            'available'   => $result['valid'],
            'error'       => $result['error'],
            'suggestions' => $result['suggestions'],
        ];
    }

    /**
     * 💡 پیشنهاد نام‌های جایگزین
     */
    public function suggestAlternatives(string $name): array
    {
        return SubdomainValidator::suggestAlternatives($name);
    }

    /**
     * ✅ تأیید نهایی و ساخت زیردامنه در cPanel
     *
     * @param int    $brandId شناسه برند
     * @param string $name نام تأییدشده
     * @param string $rootDomain دامنه اصلی
     * @param string $serverPath مسیر Document Root
     * @return array [success => bool, message => string]
     */
    public function confirmAndCreate(int $brandId, string $name, string $rootDomain, string $serverPath): array
    {
        // اعتبارسنجی نهایی قبل از ساخت
        $check = $this->validateSubdomainName($name, $rootDomain, $brandId);
        if (!$check['valid']) {
            return ['success' => false, 'message' => $check['error']];
        }

        // ساخت در cPanel
        $result = $this->api->createSubdomain($name, $rootDomain, $serverPath);
        if (!$result['success']) {
            return $result;
        }

        // 💾 ذخیره در دیتابیس (طبق سند — ستون‌های جدید brands)
        $this->db->update('brands', [
            'subdomain_name'   => $name,
            'full_domain'      => $name . '.' . $rootDomain,
            'server_path'      => $serverPath,
            'custom_subdomain' => 1, // نام از فرآیند تأیید آمده — حتی اگر تغییر نکرده باشد تأیید شده است
        ], 'id = ?', [$brandId]);

        return ['success' => true, 'message' => 'زیردامنه ' . $name . '.' . $rootDomain . ' با موفقیت ایجاد شد.'];
    }

    /**
     * 🗑️ حذف زیردامنه از cPanel + پاک‌سازی دیتابیس
     *
     * @param array $brand ردیف برند
     * @return array [success => bool, message => string]
     */
    public function delete(array $brand): array
    {
        $rootDomain = (string)($this->getSettings()['root_domain'] ?? '');
        $name = (string)($brand['subdomain_name'] ?? '');

        if ($name === '' || $rootDomain === '') {
            return ['success' => false, 'message' => 'اطلاعات زیردامنه برای حذف کامل نیست.'];
        }

        $result = $this->api->deleteSubdomain($name, $rootDomain);
        if (!$result['success']) {
            return $result;
        }

        // پاک‌سازی ستون‌های استقرار برند
        $this->db->update('brands', [
            'is_deployed'    => 0,
            'subdomain_name' => null,
            'full_domain'    => null,
            'server_path'    => null,
            'deployed_at'    => null,
            'ssl_status'     => 'none',
        ], 'id = ?', [(int)$brand['id']]);

        return ['success' => true, 'message' => 'زیردامنه و اطلاعات استقرار حذف شد.'];
    }

    /**
     * 📥 تنظیمات cPanel
     */
    private function getSettings(): array
    {
        return PathResolver::getSettings();
    }
}
