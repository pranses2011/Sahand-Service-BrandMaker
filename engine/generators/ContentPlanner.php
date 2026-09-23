<?php
/**
 * 🗓️ ژنراتور تقویم محتوا — ContentPlanner v2.0
 * ============================================
 * برنامه انتشار هوشمند برای هر برند می‌سازد:
 * چه موضوعی، چه زمانی، با چه اولویتی و چرا.
 *
 * منطق اولویت‌بندی (امتیاز ترکیبی):
 *   🍂 مرتبط بودن فصلی با ماه جلالی جاری (تقویم فصلی)
 *   🕳 شکاف پوشش — دستگاه‌هایی که مقاله کمتری دارند
 *   🔄 تنوع نوع موضوع (چرخش بین ۹ نوع)
 *   🏙 فرصت سئوی محلی (شهر/منطقه بدون محتوا)
 *   ⚔️ رقابت‌پذیری پایین‌تر کلیدواژه هدف (بهتر)
 *
 * @package SahandBrandMaker\Engine
 * @version 2.0.0
 */
class ContentPlanner
{
    /** @var Database دیتابیس */
    private $db;

    /** @var IntentClassifier تشخیص نیت */
    private $intent;

    /** @var KeywordAnalyzer تحلیل کلیدواژه */
    private $keywords;

    /** انواع موضوع قابل برنامه‌ریزی */
    private const TOPIC_TYPES = [
        'troubleshooting', 'user_guide', 'maintenance', 'comparison',
        'error_codes', 'buying_guide', 'energy_saving', 'seasonal_care', 'cost_guide',
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->intent = new IntentClassifier();
        $this->keywords = new KeywordAnalyzer();
    }

    /**
     * 🗓️ ساخت برنامه انتشار محتوا برای برند
     *
     * @param int $brandId شناسه برند
     * @param int $weeks تعداد هفته‌های برنامه (۱ تا ۱۲)
     * @param int $perWeek تعداد محتوای پیشنهادی در هفته (۱ تا ۷)
     * @return array برنامه کامل + آمار + منطق اولویت
     */
    public function plan(int $brandId, int $weeks = 4, int $perWeek = 2): array
    {
        $weeks = max(1, min(12, $weeks));
        $perWeek = max(1, min(7, $perWeek));

        $brand = $this->db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
        if (!$brand) {
            throw new RuntimeException('برند یافت نشد.');
        }

        $devices = $this->db->fetchAll(
            'SELECT device_key, name_fa FROM brand_devices WHERE brand_id = ? AND is_active = 1 ORDER BY sort_order',
            [$brandId]
        );
        if (empty($devices)) {
            // fallback به دانش عمومی
            $knowledgeDevices = TextProcessor::loadKnowledge('devices');
            foreach (array_slice($knowledgeDevices, 0, 6, true) as $key => $d) {
                $devices[] = ['device_key' => $key, 'name_fa' => $d['name_fa'] ?? $key];
            }
        }

        /* ---------- ۱) آمار پوشش فعلی (شکاف‌ها) ---------- */
        $coverage = $this->coverageMap($brandId, $devices);

        /* ---------- ۲) مرتبط بودن فصلی دستگاه‌ها ---------- */
        $seasonal = KnowledgeBase::currentSeason();
        $seasonLabel = $seasonal['label'] ?? '';
        $focusDevices = (array)($seasonal['focus_devices'] ?? []);

        /* ---------- ۳) ساخت مخزن کاندیدها با امتیاز ---------- */
        $candidates = [];
        foreach ($devices as $device) {
            $deviceSeasonalScore = 0;
            $seasonalReason = '';
            foreach ($focusDevices as $focus) {
                // تطبیق نام دستگاه با دستگاه‌های کانونی فصل
                if (mb_stripos($device['name_fa'], $focus) !== false || mb_stripos($focus, $device['name_fa']) !== false) {
                    $deviceSeasonalScore = 30;
                    $seasonalReason = 'دستگاه کانونی ' . $seasonLabel;
                    break;
                }
            }
            $articlesForDevice = $coverage[$device['device_key']]['total'] ?? 0;

            foreach (self::TOPIC_TYPES as $type) {
                $typeCount = $coverage[$device['device_key']][$type] ?? 0;

                // امتیاز پایه: شکاف پوشش (هرچه مقاله کمتر، بهتر)
                $score = 40 - min(40, $typeCount * 13);

                // امتیاز فصلی
                $reasons = [];
                if ($deviceSeasonalScore > 0 && in_array($type, ['maintenance', 'seasonal_care', 'user_guide'], true)) {
                    $score += $deviceSeasonalScore;
                    $reasons[] = $seasonalReason;
                }
                // مقاله فصلی همیشه در اولویت وقتی دستگاه کانونی است
                if ($type === 'seasonal_care' && $deviceSeasonalScore > 0) {
                    $score += 15;
                    $reasons[] = 'تناسب مستقیم با فصل جاری';
                }
                // مقاله عیب‌یابی برای دستگاه کم‌پوشش
                if ($type === 'troubleshooting' && $articlesForDevice === 0) {
                    $score += 18;
                    $reasons[] = 'هیچ مقاله‌ای برای این دستگاه ندارید';
                }
                // رقابت‌پذیری کلیدواژه هدف
                $targetKw = 'تعمیر ' . $device['name_fa'] . ' ' . $brand['name_fa'];
                $comp = $this->keywords->estimateCompetition($targetKw);
                if ($comp['score'] < 45) {
                    $score += 10;
                    $reasons[] = 'رقابت کلیدواژه ' . $comp['competition'];
                }

                $candidates[] = [
                    'device_key' => $device['device_key'],
                    'device_name'=> $device['name_fa'],
                    'type'       => $type,
                    'score'      => min(100, $score),
                    'reasons'    => $reasons ?: ['تکمیل پوشش محتوای برند'],
                    'existing'   => $typeCount,
                ];
            }
        }

        // مرتب‌سازی نزولی و حذف موارد کاملاً اشباع (۳+ مقاله از این نوع)
        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
        $candidates = array_values(array_filter($candidates, fn($c) => $c['existing'] < 3));

        /* ---------- ۴) توزیع در هفته‌ها ---------- */
        $calendar = [];
        $usedKeys = [];
        $now = time();
        $candidateIndex = 0;
        for ($w = 1; $w <= $weeks; $w++) {
            $weekPlan = [
                'week'        => $w,
                'publish_from'=> date('Y-m-d', strtotime('+' . (($w - 1) * 7) . ' days', $now)),
                'publish_to'  => date('Y-m-d', strtotime('+' . ($w * 7 - 1) . ' days', $now)),
                'items'       => [],
            ];
            for ($i = 0; $i < $perWeek; $i++) {
                // انتخاب کاندید بعدیِ استفاده‌نشده (چرخش دستگاه و نوع)
                $picked = null;
                while ($candidateIndex < count($candidates)) {
                    $cand = $candidates[$candidateIndex++];
                    $key = $cand['device_key'] . '|' . $cand['type'];
                    if (!isset($usedKeys[$key])) {
                        $picked = $cand;
                        $usedKeys[$key] = true;
                        break;
                    }
                }
                if ($picked === null) {
                    break; // مخزن تمام شد
                }

                // عنوان پیشنهادی از قالب‌های دانش
                $title = $this->suggestTitle($brand, $picked);
                // نیت جستجوی کلیدواژه هدف
                $targetKw = 'تعمیر ' . $picked['device_name'] . ' ' . $brand['name_fa'];
                $intent = $this->intent->classify($targetKw);

                $weekPlan['items'][] = [
                    'device'          => $picked['device_key'],
                    'device_name'     => $picked['device_name'],
                    'topic_type'      => $picked['type'],
                    'title'           => $title,
                    'priority_score'  => $picked['score'],
                    'priority_reasons'=> $picked['reasons'],
                    'target_keyword'  => $targetKw,
                    'search_intent'   => $intent['intent_label'],
                    'recommended_hour'=> $this->bestPublishHour($picked['type']),
                ];
            }
            $calendar[] = $weekPlan;
        }

        /* ---------- ۵) آمار برنامه ---------- */
        $totalItems = array_sum(array_map(fn($w) => count($w['items']), $calendar));
        $typeDist = [];
        foreach ($calendar as $week) {
            foreach ($week['items'] as $item) {
                $typeDist[$item['topic_type']] = ($typeDist[$item['topic_type']] ?? 0) + 1;
            }
        }
        arsort($typeDist);

        return [
            'brand'         => ['id' => $brandId, 'name_fa' => $brand['name_fa'], 'name_en' => $brand['name_en']],
            'current_season'=> $seasonLabel,
            'weeks'         => $weeks,
            'per_week'      => $perWeek,
            'calendar'      => $calendar,
            'stats'         => [
                'total_suggestions' => $totalItems,
                'unique_devices'    => count(array_unique(array_column(array_merge(...array_column($calendar, 'items')) ?: [[]], 'device'))),
                'topic_distribution'=> $typeDist,
                'coverage_before'   => $coverage,
            ],
        ];
    }

    /**
     * 📊 نقشه پوشش محتوای فعلی برند (به تفکیک دستگاه × نوع)
     */
    private function coverageMap(int $brandId, array $devices): array
    {
        $coverage = [];
        foreach ($devices as $device) {
            $coverage[$device['device_key']] = array_fill_keys(self::TOPIC_TYPES, 0);
            $coverage[$device['device_key']]['total'] = 0;
        }
        $articles = $this->db->fetchAll(
            'SELECT title FROM brand_articles WHERE brand_id = ? AND status IN ("draft","published")',
            [$brandId]
        );
        foreach ($articles as $article) {
            $title = (string)$article['title'];
            foreach ($devices as $device) {
                if (mb_strpos($title, $device['name_fa']) !== false) {
                    $coverage[$device['device_key']]['total']++;
                    // تشخیص نوع از روی عنوان
                    foreach (self::TOPIC_TYPES as $type) {
                        if ($this->titleMatchesType($title, $type)) {
                            $coverage[$device['device_key']][$type]++;
                            break;
                        }
                    }
                    break;
                }
            }
        }
        return $coverage;
    }

    /**
     * 🔎 تطبیق عنوان مقاله با نوع موضوع (هیوریستیک)
     */
    private function titleMatchesType(string $title, string $type): bool
    {
        $signals = [
            'troubleshooting' => ['مشکل', 'ایراد', 'علت', 'خرابی'],
            'user_guide'      => ['آموزش', 'راهنمای استفاده', 'نحوه'],
            'maintenance'     => ['نگهداری', 'سرویس دوره', 'مراقبت'],
            'comparison'      => ['مقایسه', 'بهتر است', 'تفاوت'],
            'error_codes'     => ['کد خطا', 'کدهای خطا', 'ارور'],
            'buying_guide'    => ['خرید', 'راهنمای خرید', 'قبل از خرید'],
            'energy_saving'   => ['انرژی', 'مصرف برق', 'صرفه‌جویی'],
            'seasonal_care'   => ['فصلی', 'تابستان', 'زمستان', 'آماده‌سازی'],
            'cost_guide'      => ['هزینه', 'قیمت', 'قیمت‌ها'],
        ];
        foreach (($signals[$type] ?? []) as $signal) {
            if (mb_strpos($title, $signal) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 📝 عنوان پیشنهادی از قالب‌های دانش
     */
    private function suggestTitle(array $brand, array $candidate): string
    {
        $templates = TextProcessor::loadKnowledge('templates');
        $pool = $templates['article_topics'][$candidate['type']] ?? [];
        if (empty($pool)) {
            return $candidate['device_name'] . ' ' . $brand['name_fa'] . ' — محتوای پیشنهادی';
        }
        $title = TextProcessor::seededPick($pool, 'plan|' . $brand['id'] . '|' . $candidate['device_key'] . '|' . $candidate['type']);
        return TextProcessor::fillTemplate($title, [
            'device_fa' => $candidate['device_name'],
            'device_en' => $candidate['device_key'],
            'brand_fa'  => $brand['name_fa'],
            'brand_en'  => $brand['name_en'],
        ]);
    }

    /**
     * ⏰ بهترین ساعت انتشار بر اساس نوع محتوا (منحنی توجه کاربران)
     */
    private function bestPublishHour(string $topicType): string
    {
        $map = [
            'troubleshooting' => '۱۸:۰۰ — ۲۱:۰۰ (عصر؛ جستجوی فوری مشکلات)',
            'user_guide'      => '۱۲:۰۰ — ۱۴:۰۰ (ظهر؛ مطالعه سبک)',
            'maintenance'     => '۰۹:۰۰ — ۱۱:۰۰ (صبح؛ برنامه‌ریزی روزانه)',
            'comparison'      => '۲۰:۰۰ — ۲۳:۰۰ (شب؛ تصمیم خرید)',
            'error_codes'     => '۱۸:۰۰ — ۲۱:۰۰ (عصر؛ عیب‌یابی فوری)',
            'buying_guide'    => '۲۰:۰۰ — ۲۳:۰۰ (شب؛ تصمیم خرید)',
            'energy_saving'   => '۰۹:۰۰ — ۱۱:۰۰ (صبح؛ برنامه‌ریزی)',
            'seasonal_care'   => '۰۹:۰۰ — ۱۱:۰۰ (صبح؛ آماده‌سازی)',
            'cost_guide'      => '۱۲:۰۰ — ۱۴:۰۰ (ظهر؛ بررسی هزینه)',
        ];
        return $map[$topicType] ?? '۱۸:۰۰ — ۲۱:۰۰';
    }
}
