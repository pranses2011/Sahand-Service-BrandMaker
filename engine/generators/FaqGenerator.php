<?php
/**
 * ❓ ژنراتور سوالات متداول — تولید حداقل ۱۰ FAQ یکتا
 * ====================================================
 * FAQ های مرتبط با برند و دستگاه‌های آن تولید می‌کند
 * همراه با Schema FAQPage برای سئوی ریچ‌ریزالت.
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class FaqGenerator
{
    /** @var Database دیتابیس */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * ❓ تولید FAQ کامل برای برند
     *
     * @param int   $brandId شناسه برند
     * @param array $brand داده‌های برند
     * @param array $devices دستگاه‌های برند
     * @param int   $minCount حداقل تعداد سوال
     * @return int تعداد FAQ های تولیدشده
     */
    public function generateForBrand(int $brandId, array $brand, array $devices, int $minCount = 10): int
    {
        $templates = TextProcessor::loadKnowledge('templates')['faq'] ?? [];
        if (empty($templates)) {
            return 0;
        }

        $vars = [
            'brand_fa'  => $brand['name_fa'] ?? '',
            'brand_en'  => $brand['name_en'] ?? '',
            'agency'    => Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس',
            'device_fa' => $devices[0]['name_fa'] ?? 'دستگاه',
            'warranty_period' => $this->warrantyPeriod(),
        ];

        // 🎨 انتخاب یکتای قالب‌ها — بدون تکرار
        $seed = 'faq|' . $brandId . '|' . $brand['name_en'];
        $picked = TextProcessor::seededPickMany(
            array_keys($templates),
            $minCount + 4, // چند مورد اضافه برای رد موارد نامناسب
            $seed
        );

        $count = 0;
        foreach ($picked as $templateKey) {
            if ($count >= $minCount) {
                break;
            }
            $template = $templates[$templateKey];
            $localVars = $vars;

            // اگر سوال دستگاه‌محور است، دستگاه بعدی را جایگذاری کن
            if (strpos($template['q'], '{{device_fa}}') !== false && count($devices) > 0) {
                $deviceIndex = $count % count($devices);
                $localVars['device_fa'] = $devices[$deviceIndex]['name_fa'];
            }

            $question = TextProcessor::fillTemplate($template['q'], $localVars);
            $answer = TextProcessor::fillTemplate($template['a'], $localVars);
            // تنوع‌سازی پاسخ‌ها
            $answer = TextProcessor::applySynonyms($answer, $seed . '|' . $templateKey);

            // 🔍 رد FAQ تکراری
            $exists = $this->db->fetchValue(
                'SELECT COUNT(*) FROM faqs WHERE brand_id = ? AND question = ?',
                [$brandId, $question]
            );
            if ((int)$exists) {
                continue;
            }

            $this->db->insert('faqs', [
                'brand_id'         => $brandId,
                'question'         => $question,
                'answer'           => $answer,
                'generated_by_ai'  => 1,
                'is_active'        => 1,
                'sort_order'       => $count,
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * ➕ افزودن FAQ دستی
     */
    public function addManual(int $brandId, string $question, string $answer): int
    {
        return $this->db->insert('faqs', [
            'brand_id'        => $brandId,
            'question'        => clean_input($question),
            'answer'          => Validator::sanitizeHtml($answer),
            'generated_by_ai' => 0,
            'is_active'       => 1,
            'sort_order'      => 99,
        ]);
    }

    /**
     * 📋 دریافت FAQ های فعال برند (با کش)
     */
    public function forBrand(int $brandId): array
    {
        $cache = new Cache();
        return $cache->remember('brand_' . $brandId . '_faqs', 600, function () use ($brandId) {
            return $this->db->fetchAll(
                'SELECT question, answer FROM faqs WHERE brand_id = ? AND is_active = 1 ORDER BY sort_order, id',
                [$brandId]
            );
        });
    }

    /**
     * 🛡️ دریافت مدت ضمانت از تنظیمات
     */
    private function warrantyPeriod(): string
    {
        $warranty = Config::get(Config::KEY_WARRANTY) ?: [];
        return $warranty['default_period'] ?? '۶ ماه';
    }
}
