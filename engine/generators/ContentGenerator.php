<?php
/**
 * ✍️ ژنراتور محتوای یکتا — هسته موتور AI
 * ========================================
 * الگوریتم ۶ مرحله‌ای:
 *   ۱) انتخاب قالب پایه (از ۱۰+ قالب هر نوع)
 *   ۲) جایگذاری داده‌های برند
 *   ۳) تنوع‌سازی جملات (مترادف + بازنویسی)
 *   ۴) بررسی یکتایی (Anti-Duplicate)
 *   ۵) بهینه‌سازی سئو
 *   ۶) خروجی نهایی HTML + متادیتا
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class ContentGenerator
{
    /** @var UniquenessChecker بررسی‌گر یکتایی */
    private $uniqueness;

    public function __construct()
    {
        $this->uniqueness = new UniquenessChecker();
    }

    /**
     * 🎯 تولید محتوای یکتا برای هر نوع محتوا
     *
     * @param string $type نوع محتوا (brand_intro|brand_history|agency_about|service_area|warranty|device_desc|services_intro)
     * @param array  $vars متغیرهای جایگذاری: brand_fa, brand_en, device_fa, country, founded ...
     * @param int    $brandId شناسه برند (برای مقایسه یکتایی)
     * @return array ['content', 'uniqueness', 'word_count']
     */
    public function generate(string $type, array $vars, int $brandId = 0): array
    {
        // 📚 بارگذاری قالب‌های این نوع
        $templates = TextProcessor::loadKnowledge('templates');
        $typeTemplates = $templates[$type] ?? [];
        if (empty($typeTemplates)) {
            return ['content' => '', 'uniqueness' => null, 'word_count' => 0, 'error' => "قالبی برای نوع «{$type}» یافت نشد"];
        }

        // 🔄 بازتولید تا حصول یکتایی
        $result = $this->uniqueness->ensureUnique(function (string $seed) use ($typeTemplates, $vars, $type) {
            // ۱️⃣ انتخاب قطعی قالب بر اساس seed
            $template = TextProcessor::seededPick($typeTemplates, $seed . '|' . $type . '|' . ($vars['brand_en'] ?? ''));

            // ۲️⃣ جایگذاری متغیرها
            $content = TextProcessor::fillTemplate($template, $vars);

            // ۳️⃣ تنوع‌سازی: مترادف + بازنویسی ساختار
            $content = TextProcessor::applySynonyms($content, $seed . '|syn');
            $content = TextProcessor::restructureSentences($content, $seed . '|rst');

            // 🧹 نرمال‌سازی نهایی
            return TextProcessor::normalize($content);
        }, max(3, 4), $brandId);

        $content = $result['content'] ?? '';
        return [
            'content'    => $content,
            'uniqueness' => $result['result'] ?? null,
            'attempts'   => $result['attempts'] ?? 0,
            'word_count' => TextProcessor::wordCount($content),
            'error'      => '',
        ];
    }

    /**
     * 🏭 تولید متن معرفی کوتاه برند برای صفحه اصلی (۲-۳ پاراگراف)
     * دو قالب + دانش برند ترکیب می‌شوند
     */
    public function brandIntro(array $brand, array $devices, int $brandId = 0): array
    {
        $vars = $this->brandVars($brand, $devices);
        $templates = TextProcessor::loadKnowledge('templates');
        $typeTemplates = $templates['brand_intro'] ?? [];

        $result = $this->uniqueness->ensureUnique(function (string $seed) use ($typeTemplates, $vars, $brand) {
            $parts = [];
            // انتخاب دو قالب متفاوت برای تشکیل ۲-۳ پاراگراف
            $picks = TextProcessor::seededPickMany(
                array_keys($typeTemplates),
                min(2, count($typeTemplates)),
                $seed . '|intro'
            );
            foreach ($picks as $tplKey) {
                $text = TextProcessor::fillTemplate($typeTemplates[$tplKey], $vars);
                $parts[] = TextProcessor::applySynonyms($text, $seed . '|i' . md5($tplKey), 0.3);
            }
            return TextProcessor::normalize(implode("\n\n", $parts));
        }, 3, $brandId);

        $content = $result['content'] ?? '';
        return [
            'content'    => $content,
            'uniqueness' => $result['result'] ?? null,
            'attempts'   => $result['attempts'] ?? 0,
            'word_count' => TextProcessor::wordCount($content),
            'error'      => '',
        ];
    }

    /**
     * 📜 تولید تاریخچه برند (۵۰۰-۱۰۰۰ کلمه)
     * چند قالب + دانش تکمیلی برند ترکیب می‌شود تا حجم الزام برسد
     */
    public function brandHistory(array $brand, array $devices, int $brandId = 0): array
    {
        $vars = $this->brandVars($brand, $devices);
        $templates = TextProcessor::loadKnowledge('templates');
        $typeTemplates = $templates['brand_history'] ?? [];
        $extraTemplates = $templates['brand_history_extra'] ?? [];

        $result = $this->uniqueness->ensureUnique(function (string $seed) use ($typeTemplates, $extraTemplates, $vars, $brand) {
            $parts = [];

            // ۱️⃣ انتخاب سه قالب اصلی متفاوت (بدون تکرار) برای رسیدن به ۵۰۰+ کلمه
            $mainPicks = TextProcessor::seededPickMany(
                array_keys($typeTemplates),
                min(3, count($typeTemplates)),
                $seed . '|main'
            );
            foreach ($mainPicks as $tplKey) {
                $parts[] = TextProcessor::fillTemplate($typeTemplates[$tplKey], $vars);
            }

            // ۲️⃣ افزودن دانش واقعی برند (history_facts) به عنوان پاراگراف میانی
            if (!empty($brand['history_facts'])) {
                $facts = (array)$brand['history_facts'];
                $pickedFacts = TextProcessor::seededPickMany($facts, min(3, count($facts)), $seed . '|facts');
                foreach ($pickedFacts as $fact) {
                    $parts[] = TextProcessor::applySynonyms((string)$fact, $seed . '|fact' . md5((string)$fact));
                }
            }

            // ۳️⃣ پاراگراف‌های پایانی از قالب‌های تکمیلی (دو مورد)
            $extraPicks = TextProcessor::seededPickMany($extraTemplates ?: [''], 2, $seed . '|extra');
            foreach ($extraPicks as $extraPick) {
                if ($extraPick) {
                    $parts[] = TextProcessor::fillTemplate($extraPick, $vars);
                }
            }

            // ۴️⃣ تنوع‌سازی نهایی روی کل متن
            $content = implode("\n\n", array_filter($parts));
            $content = TextProcessor::applySynonyms($content, $seed . '|syn', 0.3);
            return TextProcessor::normalize($content);
        }, 3, $brandId);

        $content = $result['content'] ?? '';
        return [
            'content'    => $content,
            'uniqueness' => $result['result'] ?? null,
            'attempts'   => $result['attempts'] ?? 0,
            'word_count' => TextProcessor::wordCount($content),
            'error'      => '',
        ];
    }

    /**
     * 🏢 تولید متن «درباره نمایندگی» (۳۰۰-۵۰۰ کلمه)
     */
    public function agencyAbout(array $brand, array $devices, int $brandId = 0): array
    {
        $vars = $this->brandVars($brand, $devices);
        return $this->generate('agency_about', $vars, $brandId);
    }

    /**
     * 📍 تولید متن صفحه محدوده خدمات (۲۰۰-۴۰۰ کلمه)
     */
    public function serviceArea(array $brand, array $areas, int $brandId = 0): array
    {
        $vars = $this->brandVars($brand, []);
        $vars['areas_list'] = !empty($areas) ? implode('، ', array_column($areas, 'city')) : ' تهران و کرج';
        $vars['areas_count'] = max(1, count($areas));
        return $this->generate('service_area', $vars, $brandId);
    }

    /**
     * 🛡️ تولید متن صفحه ضمانت (۳۰۰-۵۰۰ کلمه)
     */
    public function warranty(array $brand, array $devices, int $brandId = 0): array
    {
        $vars = $this->brandVars($brand, $devices);
        return $this->generate('warranty', $vars, $brandId);
    }

    /**
     * 🔧 تولید توضیح یکتای هر دستگاه (۱۵۰-۳۰۰ کلمه)
     */
    public function deviceDescription(array $brand, array $device, int $brandId = 0): array
    {
        $vars = $this->brandVars($brand, [$device]);
        $vars['device_fa'] = $device['name_fa'] ?? '';
        $vars['device_en'] = $device['name_en'] ?? '';
        return $this->generate('device_desc', $vars, $brandId);
    }

    /**
     * 🧩 تولید متن مقدمه صفحه خدمات
     */
    public function servicesIntro(array $brand, array $devices, int $brandId = 0): array
    {
        $vars = $this->brandVars($brand, $devices);
        return $this->generate('services_intro', $vars, $brandId);
    }

    /**
     * 🧱 ساخت متغیرهای استاندارد برند برای قالب‌ها
     */
    private function brandVars(array $brand, array $devices): array
    {
        $agency = Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس';
        $deviceNames = array_column($devices, 'name_fa');
        return [
            'brand_fa'      => $brand['name_fa'] ?? '',
            'brand_en'      => $brand['name_en'] ?? '',
            'country'       => $brand['country_fa'] ?? '',
            'founded'       => (string)($brand['founded'] ?? ''),
            'devices_list'  => implode('، ', array_slice($deviceNames, 0, 6)),
            'devices_count' => (string)max(1, count($devices)),
            'first_device'  => $deviceNames[0] ?? 'لوازم خانگی',
            'agency_name'   => $agency,
            'main_site'     => Config::get(Config::KEY_MAIN_SITE) ?: AGENCY_MAIN_SITE,
            'year_now'      => (string)((int)jdate(date('Y-m-d')) ?: date('Y')),
        ];
    }
}
