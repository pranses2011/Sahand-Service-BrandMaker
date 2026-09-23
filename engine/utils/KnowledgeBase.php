<?php
/**
 * 📚 سرویس پایگاه دانش — KnowledgeBase v1.0
 * ============================================
 * دسترسی یکپارچه به همه فایل‌های دانش موتور:
 * brands, devices, error-codes, keywords, sentences, seo-patterns,
 * synonyms, templates + فایل‌های جدید:
 * phrases, diagnostics, parts, seasonal-calendar, city-areas
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class KnowledgeBase
{
    /** @var array کش آمار */
    private static $statsCache = null;

    /* ==================================================
     * 📊 آمار کل پایگاه دانش
     * ================================================== */

    /**
     * 📈 شمارش کامل همه منابع دانش
     */
    public static function stats(): array
    {
        if (self::$statsCache !== null) {
            return self::$statsCache;
        }

        $brands = TextProcessor::loadKnowledge('brands');
        $devices = TextProcessor::loadKnowledge('devices');
        $errors = TextProcessor::loadKnowledge('error-codes');
        $synonyms = TextProcessor::loadKnowledge('synonyms');
        $templates = TextProcessor::loadKnowledge('templates');
        $phrases = TextProcessor::loadKnowledge('phrases');
        $diagnostics = TextProcessor::loadKnowledge('diagnostics');
        $parts = TextProcessor::loadKnowledge('parts');
        $seasonal = TextProcessor::loadKnowledge('seasonal-calendar');
        $cities = TextProcessor::loadKnowledge('city-areas');

        $errorCount = 0;
        foreach ($errors as $codes) {
            $errorCount += is_array($codes) ? count($codes) : 0;
        }

        $diagnosticCount = 0;
        foreach ($diagnostics as $scenarios) {
            $diagnosticCount += is_array($scenarios) ? count($scenarios) : 0;
        }

        $faqCount = isset($templates['faq']) && is_array($templates['faq']) ? count($templates['faq']) : 0;
        $templateCount = 0;
        foreach ($templates as $section) {
            if (is_array($section)) {
                $templateCount += count($section);
            }
        }

        $factsCount = 0;
        foreach ($brands as $brand) {
            $factsCount += is_array($brand['history_facts'] ?? null) ? count($brand['history_facts']) : 0;
        }

        self::$statsCache = [
            'brands'             => count($brands),
            'brand_facts'        => $factsCount,
            'devices'            => count($devices),
            'device_issues'      => self::countField($devices, 'common_issues'),
            'device_maintenance' => self::countField($devices, 'maintenance_tips'),
            'error_categories'   => count($errors),
            'error_codes'        => $errorCount,
            'synonym_words'      => count($synonyms),
            'synonym_entries'    => self::countValues($synonyms),
            'phrase_patterns'    => count($phrases),
            'diagnostic_devices' => count($diagnostics),
            'diagnostic_scenarios' => $diagnosticCount,
            'spare_parts'        => count($parts),
            'seasonal_months'    => count(array_filter(array_keys($seasonal), fn($k) => $k !== '_meta')),
            'cities_with_areas'  => count(array_filter(array_keys($cities), fn($k) => $k !== '_meta')),
            'templates'          => $templateCount,
            'faq_templates'      => $faqCount,
            'generated_at'       => date('Y-m-d H:i:s'),
        ];
        return self::$statsCache;
    }

    /* ==================================================
     * 🩺 عیب‌یابی علامت‌محور (diagnostics.json)
     * ================================================== */

    /**
     * 🔍 همه سناریوهای عیب‌یابی یک دستگاه
     */
    public static function diagnostics(string $deviceKey): array
    {
        $all = TextProcessor::loadKnowledge('diagnostics');
        return $all[$deviceKey] ?? [];
    }

    /**
     * 🎯 تشخیص از روی علامت — هسته هوش عیب‌یابی
     * علت‌ها بر اساس احتمال مرتب می‌شوند.
     * تطبیق سه‌سطحی: تطبیق کامل ← شامل بودن ← شباهت هم‌پوشانی واژه‌ها
     */
    public static function diagnose(string $deviceKey, string $symptom): ?array
    {
        $scenarios = self::diagnostics($deviceKey);
        $needle = TextProcessor::normalize(mb_strtolower(trim($symptom)));

        // سطح ۱ و ۲: تطبیق کامل یا شامل بودن
        foreach ($scenarios as $scenario) {
            $target = TextProcessor::normalize(mb_strtolower($scenario['symptom']));
            if ($target === $needle
                || mb_strpos($target, $needle) !== false
                || mb_strpos($needle, $target) !== false) {
                return self::sortedScenario($scenario);
            }
        }

        // سطح ۳: شباهت هم‌پوشانی واژه‌ها (بیش از نصف واژه‌های مشترک)
        $needleWords = array_filter(preg_split('/[\s\x{200c}]+/u', $needle) ?: [], fn($w) => mb_strlen($w) >= 3);
        if (empty($needleWords)) {
            return null;
        }
        $best = null;
        $bestScore = 0;
        foreach ($scenarios as $scenario) {
            $target = TextProcessor::normalize(mb_strtolower($scenario['symptom']));
            $targetWords = array_filter(preg_split('/[\s\x{200c}]+/u', $target) ?: [], fn($w) => mb_strlen($w) >= 3);
            if (empty($targetWords)) {
                continue;
            }
            $overlap = array_intersect($needleWords, $targetWords);
            // امتیاز: نسبت واژه‌های مشترک به واژه‌های علامت ورودی
            $score = count($overlap) / count($needleWords);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $scenario;
            }
        }
        // آستانه: حداقل نصف واژه‌های ورودی در علامت هدف باشد
        if ($best !== null && $bestScore >= 0.5) {
            $best['match_confidence'] = round($bestScore, 2);
            return self::sortedScenario($best);
        }
        return null;
    }

    /**
     * ↕️ مرتب‌سازی علت‌های سناریو بر اساس احتمال نزولی
     */
    private static function sortedScenario(array $scenario): array
    {
        usort($scenario['causes'], function ($a, $b) {
            return ($b['probability'] ?? 0) <=> ($a['probability'] ?? 0);
        });
        return $scenario;
    }

    /* ==================================================
     * 🔧 قطعات یدکی (parts.json)
     * ================================================== */

    /**
     * 🧰 همه قطعات
     */
    public static function parts(): array
    {
        return TextProcessor::loadKnowledge('parts');
    }

    /**
     * 🔩 یک قطعه خاص
     */
    public static function part(string $key): ?array
    {
        $parts = self::parts();
        return $parts[$key] ?? null;
    }

    /**
     * 🔎 یافتن قطعات مرتبط با یک علامت
     * (برای پیشنهاد «شاید این قطعه ایراد دارد»)
     * تطبیق دو‌سطحی: شامل بودن یا هم‌پوشانی واژه‌ها
     */
    public static function partsForSymptom(string $symptom): array
    {
        $needle = TextProcessor::normalize(mb_strtolower(trim($symptom)));
        $needleWords = array_values(array_filter(
            preg_split('/[\s\x{200c}]+/u', $needle) ?: [],
            fn($w) => mb_strlen($w) >= 3
        ));
        $matches = [];
        foreach (self::parts() as $key => $part) {
            foreach (($part['wear_symptoms'] ?? []) as $s) {
                $target = TextProcessor::normalize(mb_strtolower($s));
                $hit = false;
                if ($target !== '' && (mb_strpos($target, $needle) !== false || mb_strpos($needle, $target) !== false)) {
                    $hit = true;
                } elseif (!empty($needleWords)) {
                    // هم‌پوشانی واژه‌ها: حداقل نصف واژه‌های معنادار ورودی
                    $targetWords = preg_split('/[\s\x{200c}]+/u', $target) ?: [];
                    $overlap = array_intersect($needleWords, array_filter($targetWords, fn($w) => mb_strlen($w) >= 3));
                    $threshold = max(1, (int)floor(count($needleWords) / 2));
                    if (count($overlap) >= $threshold) {
                        $hit = true;
                    }
                }
                if ($hit) {
                    $matches[$key] = $part;
                    break;
                }
            }
        }
        return $matches;
    }

    /**
     * 📱 قطعات پرتقاضای یک دستگاه از روی parts_wear آن
     */
    public static function wearableParts(string $deviceKey): array
    {
        $devices = TextProcessor::loadKnowledge('devices');
        return $devices[$deviceKey]['parts_wear'] ?? [];
    }

    /* ==================================================
     * 📅 تقویم فصلی (seasonal-calendar.json)
     * ================================================== */

    /**
     * 🗓 همه تقویم
     */
    public static function seasonalCalendar(): array
    {
        return TextProcessor::loadKnowledge('seasonal-calendar');
    }

    /**
     * 🌸 ماه جلالی فعلی (کلید)
     */
    public static function currentMonth(): string
    {
        // تبدیل تاریخ شمسی به نام ماه
        $jdate = jdate(date('Y-m-d')); // مثل ۱۴۰۴/۰۷/۰۱
        $clean = en_to_fa_digits(fa_to_en_digits($jdate) ?: $jdate);
        $clean = fa_to_en_digits($jdate) ?: $jdate;
        $parts = explode('/', str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], ['0','1','2','3','4','5','6','7','8','9'], $clean));
        $month = (int)($parts[1] ?? 0);
        $map = [
            1 => 'farvardin', 2 => 'ordibehesht', 3 => 'khordad', 4 => 'tir',
            5 => 'mordad', 6 => 'shahrivar', 7 => 'mehr', 8 => 'aban',
            9 => 'azar', 10 => 'dey', 11 => 'bahman', 12 => 'esfand',
        ];
        return $map[$month] ?? 'farvardin';
    }

    /**
     * 🍂 اطلاعات فصل فعلی
     */
    public static function currentSeason(): array
    {
        $calendar = self::seasonalCalendar();
        $month = self::currentMonth();
        return $calendar[$month] ?? [];
    }

    /**
     * 🏷 برچسب فارسی ماه فعلی
     */
    public static function currentMonthLabel(): string
    {
        $season = self::currentSeason();
        return $season['label'] ?? '';
    }

    /* ==================================================
     * 🏙 مناطق شهری (city-areas.json)
     * ================================================== */

    /**
     * 🗺 همه شهرها
     */
    public static function cityAreas(): array
    {
        return TextProcessor::loadKnowledge('city-areas');
    }

    /**
     * 📍 مناطق یک شهر
     */
    public static function areasOf(string $city): array
    {
        $cities = self::cityAreas();
        if (!isset($cities[$city])) {
            return [];
        }
        $data = $cities[$city];
        $areas = [];
        foreach (['areas_north', 'areas_center', 'areas_south', 'areas_east', 'areas_west', 'areas'] as $key) {
            foreach (($data[$key] ?? []) as $area) {
                $areas[] = $area;
            }
        }
        return array_values(array_unique($areas));
    }

    /* ==================================================
     * 🔤 عبارات بازنویسی (phrases.json)
     * ================================================== */

    /**
     * ✍️ دیکشنری عبارات چندکلمه‌ای
     */
    public static function phrases(): array
    {
        return TextProcessor::loadKnowledge('phrases');
    }

    /* ==================================================
     * 🛠 متدهای کمکی
     * ================================================== */

    /** شمارش آیتم‌های یک فیلد آرایه‌ای در همه رکوردها */
    private static function countField(array $records, string $field): int
    {
        $count = 0;
        foreach ($records as $record) {
            if (isset($record[$field]) && is_array($record[$field])) {
                $count += count($record[$field]);
            }
        }
        return $count;
    }

    /** شمارش کل مقادیر یک دیکشنری */
    private static function countValues(array $dict): int
    {
        $count = 0;
        foreach ($dict as $values) {
            if (is_array($values)) {
                $count += count($values);
            }
        }
        return $count;
    }
}
