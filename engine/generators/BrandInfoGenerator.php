<?php
/**
 * 🏭 ژنراتور اطلاعات برند — معرفی و تاریخچه
 * ==========================================
 * تولید متن‌های یکتای «درباره برند» با استفاده از
 * پایگاه دانش ۵۰+ برند لوازم خانگی.
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class BrandInfoGenerator
{
    /** @var Database دیتابیس */
    private $db;

    /** @var ContentGenerator ژنراتور محتوا */
    private $content;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->content = new ContentGenerator();
    }

    /**
     * 📚 بازیابی دانش برند از پایگاه دانش
     *
     * @param string $brandKeyOrName کلید یا نام (فارسی/انگلیسی) برند
     * @return array|null داده‌های دانش برند
     */
    public function getKnowledge(string $brandKeyOrName): ?array
    {
        $brands = TextProcessor::loadKnowledge('brands');
        // جستجو مستقیم با کلید
        if (isset($brands[$brandKeyOrName])) {
            return $brands[$brandKeyOrName];
        }
        // جستجو با نام (فارسی یا انگلیسی — بدون حساسیت به حروف)
        $needle = mb_strtolower(trim($brandKeyOrName));
        foreach ($brands as $key => $brand) {
            if (mb_strtolower($brand['name_fa'] ?? '') === $needle
                || mb_strtolower($brand['name_en'] ?? '') === $needle) {
                $brand['key'] = $key;
                return $brand;
            }
        }
        return null;
    }

    /**
     * 🔍 تطبیق برند کاربر با پایگاه دانش (جستجوی فازی)
     * نسخه تقویت‌شده: جستجو در نام‌های مستعار (aliases) هم انجام می‌شود
     */
    public function matchBrand(string $nameFa, string $nameEn): ?array
    {
        $knowledge = $this->getKnowledge($nameEn);
        if ($knowledge) {
            return $knowledge;
        }
        $knowledge = $this->getKnowledge($nameFa);
        if ($knowledge) {
            return $knowledge;
        }
        $brands = TextProcessor::loadKnowledge('brands');
        $needleEn = mb_strtolower(trim($nameEn));
        $needleFa = mb_strtolower(trim($nameFa));

        foreach ($brands as $key => $brand) {
            $brandEn = mb_strtolower($brand['name_en'] ?? '');
            // تطبیق فازی نام اصلی
            if ($brandEn !== '' && ($needleEn !== '' && (strpos($needleEn, $brandEn) !== false || strpos($brandEn, $needleEn) !== false))) {
                $brand['key'] = $key;
                return $brand;
            }
            // 🆕 تطبیق با نام‌های مستعار (aliases) — املاهای مختلف فارسی و انگلیسی
            foreach ((array)($brand['aliases'] ?? []) as $alias) {
                $aliasLower = mb_strtolower(trim($alias));
                if ($aliasLower === '') {
                    continue;
                }
                if ($aliasLower === $needleFa
                    || ($needleEn !== '' && $aliasLower === $needleEn)
                    || ($needleFa !== '' && strpos($needleFa, $aliasLower) !== false)
                    || ($needleEn !== '' && strpos($needleEn, $aliasLower) !== false)) {
                    $brand['key'] = $key;
                    return $brand;
                }
            }
        }
        return null;
    }

    /**
     * 🏗️ تولید کامل اطلاعات اولیه برند جدید
     * شامل: معرفی، تاریخچه، دستگاه‌های پیشنهادی، مقالات، FAQ
     *
     * @param int   $brandId شناسه برند ثبت‌شده
     * @param array $brandData داده‌های برند (name_fa, name_en, ...)
     * @return array گزارش تولید
     */
    public function generateBrandPackage(int $brandId, array $brandData): array
    {
        $report = [
            'knowledge_matched' => false,
            'devices'           => [],
            'intro'             => null,
            'history'           => null,
            'faq_count'         => 0,
            'article_count'     => 0,
            'errors'            => [],
        ];

        // 📚 تطبیق با پایگاه دانش
        $knowledge = $this->matchBrand($brandData['name_fa'], $brandData['name_en']);
        if ($knowledge) {
            $report['knowledge_matched'] = true;
            $brandData = array_merge($brandData, $knowledge);
        }

        // 🔧 ثبت دستگاه‌های برند از دانش
        $deviceKeys = $knowledge['devices'] ?? [];
        if (!empty($deviceKeys)) {
            $devicesKnowledge = TextProcessor::loadKnowledge('devices');
            foreach ($deviceKeys as $deviceKey) {
                if (!isset($devicesKnowledge[$deviceKey])) {
                    continue;
                }
                $dk = $devicesKnowledge[$deviceKey];
                $this->db->insert('brand_devices', [
                    'brand_id'   => $brandId,
                    'device_key' => $deviceKey,
                    'name_fa'    => $dk['name_fa'],
                    'icon'       => 'home-appliance/' . ($dk['icon'] ?? $deviceKey),
                    'is_active'  => 1,
                    'sort_order' => 0,
                ]);
                $report['devices'][] = $deviceKey;
            }
        }
        $devices = $this->db->fetchAll('SELECT * FROM brand_devices WHERE brand_id = ?', [$brandId]);

        /* ---------- 📄 تولید محتوای صفحات ---------- */
        // معرفی کوتاه برند (صفحه اصلی)
        $intro = $this->content->brandIntro($brandData, $devices, $brandId);
        $report['intro'] = $intro;
        $this->savePageContent($brandId, 'home', 'intro', $intro['content']);

        // تاریخچه برند
        $history = $this->content->brandHistory($brandData, $devices, $brandId);
        $report['history'] = $history;
        $this->savePageContent($brandId, 'about-brand', 'content', $history['content']);

        // درباره نمایندگی
        $aboutAgency = $this->content->agencyAbout($brandData, $devices, $brandId);
        $this->savePageContent($brandId, 'about-agency', 'content', $aboutAgency['content']);

        // مقدمه خدمات
        $servicesIntro = $this->content->servicesIntro($brandData, $devices, $brandId);
        $this->savePageContent($brandId, 'services', 'intro', $servicesIntro['content']);

        // توضیح هر دستگاه
        foreach ($devices as $device) {
            $desc = $this->content->deviceDescription($brandData, $device, $brandId);
            $this->db->update('brand_devices', ['description' => $desc['content']], 'id = ?', [$device['id']]);
        }

        // متن ضمانت + محدوده خدمات
        $warranty = $this->content->warranty($brandData, $devices, $brandId);
        $this->savePageContent($brandId, 'warranty', 'content', $warranty['content']);
        $areas = $this->db->fetchAll('SELECT city FROM service_areas WHERE brand_id = ? OR brand_id IS NULL', [$brandId]);
        $areaText = $this->content->serviceArea($brandData, $areas, $brandId);
        $this->savePageContent($brandId, 'service-area', 'content', $areaText['content']);

        /* ---------- ❓ تولید FAQ ---------- */
        $faqGenerator = new FaqGenerator();
        $faqCount = $faqGenerator->generateForBrand($brandId, $brandData, $devices);
        $report['faq_count'] = $faqCount;

        return $report;
    }

    /**
     * 💾 ذخیره محتوای صفحه (درج در صورت نبود)
     */
    private function savePageContent(int $brandId, string $pageType, string $field, string $content): void
    {
        $existing = $this->db->fetch(
            'SELECT id, content FROM brand_pages WHERE brand_id = ? AND page_type = ?',
            [$brandId, $pageType]
        );
        $contentJson = json_encode([$field => $content], JSON_UNESCAPED_UNICODE);
        if ($existing) {
            // ادغام با محتوای موجود
            $current = json_decode($existing['content'] ?? '{}', true) ?: [];
            $current[$field] = $content;
            $this->db->update('brand_pages', [
                'content' => json_encode($current, JSON_UNESCAPED_UNICODE),
            ], 'id = ?', [$existing['id']]);
        } else {
            $this->db->insert('brand_pages', [
                'brand_id'  => $brandId,
                'page_type' => $pageType,
                'content'   => $contentJson,
                'is_active' => 1,
            ]);
        }
    }

    /**
     * ➕ افزودن برند جدید به پایگاه دانش (قابلیت گسترش توسط مدیر)
     */
    public function addToKnowledge(array $brandData): bool
    {
        $file = ENGINE_PATH . '/knowledge/brands.json';
        $brands = TextProcessor::loadKnowledge('brands');
        $key = SlugGenerator::generate($brandData['name_en'] ?: $brandData['name_fa'], true);
        if (isset($brands[$key])) {
            return false; // قبلاً وجود دارد
        }
        $brands[$key] = [
            'name_fa'    => $brandData['name_fa'],
            'name_en'    => $brandData['name_en'],
            'country_fa' => $brandData['country_fa'] ?? '',
            'founded'    => $brandData['founded'] ?? '',
            'devices'    => $brandData['devices'] ?? [],
            'strengths'  => $brandData['strengths'] ?? [],
            'history_facts' => $brandData['history_facts'] ?? [],
        ];
        $json = json_encode($brands, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return file_put_contents($file, $json . "\n", LOCK_EX) !== false;
    }

    /**
     * 📋 لیست تمام برندهای پایگاه دانش
     */
    public function allKnowledgeBrands(): array
    {
        return TextProcessor::loadKnowledge('brands');
    }
}
