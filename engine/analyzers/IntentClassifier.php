<?php
/**
 * * 🧭 کلاس تشخیص نیت جستجو — IntentClassifier v2.0
 * ===============================================
 * نیت کاربر را از عبارت جستجو تشخیص می‌دهد تا نوع
 * محتوای مناسب (مقاله آموزشی / صفحه خدمات / صفحه فرود)
 * و لحن آن انتخاب شود.
 *
 * چهار نیت قابل تشخیص:
 *   📚 informational — دنبال یادگیری («چرا یخچال سرد نمی‌کند؟»)
 *   🛒 commercial   — در حال مقایسه و ارزیابی («بهترین ماشین لباسشویی»)
 *   💳 transactional — آماده اقدام («ثبت درخواست تعمیر»)
 *   🧭 navigational  — دنبال برند/سایت مشخص («سایت سهند سرویس»)
 *
 * @package SahandBrandMaker\Engine
 * @version 2.0.0
 */
class IntentClassifier
{
    /** @var array|null کش سیگنال‌های دانش */
    private static $signals = null;

    /**
     * 🧭 تشخیص نیت یک عبارت جستجو
     *
     * @param string $query عبارت جستجو (فارسی یا انگلیسی)
     * @return array [
     *   'intent' => 'informational|commercial|transactional|navigational',
     *   'confidence' => float 0-1,
     *   'scores' => [هر نیت => امتیاز],
     *   'recommended_content' => نوع محتوای پیشنهادی,
     *   'signals' => سیگنال‌های یافت‌شده
     * ]
     */
    public function classify(string $query): array
    {
        $query = TextProcessor::normalize(mb_strtolower(trim($query)));
        if ($query === '') {
            return $this->result('informational', 0.0, [], 'user_guide', []);
        }

        $scores = [
            'informational'  => 0.0,
            'commercial'     => 0.0,
            'transactional'  => 0.0,
            'navigational'   => 0.0,
        ];
        $foundSignals = [];

        /* ---------- ۱) سیگنال‌های قاعده‌محور ---------- */
        foreach ($this->signalPatterns() as $intent => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $query)) {
                    $scores[$intent] += 2.0;
                    $foundSignals[] = ['intent' => $intent, 'match' => $pattern];
                }
            }
        }

        /* ---------- ۲) سیگنال‌های پایگاه دانش (keywords.json) ---------- */
        /* الگوهای دانش دارای جایگاه‌نما ({device}/{brand}) هستند؛
           بخش‌های ثابت آن‌ها به عنوان عبارت سیگنال استخراج می‌شود. */
        $knowledge = TextProcessor::loadKnowledge('keywords');
        $intentKeys = [
            'informational' => array_merge(
                (array)($knowledge['informational_patterns'] ?? []),
                (array)($knowledge['question_patterns'] ?? [])
            ),
            'commercial'    => array_merge(
                (array)($knowledge['commercial_patterns'] ?? []),
                (array)($knowledge['longtail_patterns'] ?? [])
            ),
            'transactional' => array_merge(
                (array)($knowledge['urgency'] ?? []),
                (array)($knowledge['services'] ?? [])
            ),
        ];
        foreach ($intentKeys as $intent => $keys) {
            foreach ($keys as $key) {
                // شکستن الگو به بخش‌های ثابت (حذف جایگاه‌نماها)
                $literal = trim(preg_replace('/\{[a-z_]+\}/u', ' ', (string)$key));
                $literal = preg_replace('/\s+/u', ' ', $literal);
                if (mb_strlen($literal) < 4) {
                    continue;
                }
                if (mb_strpos($query, mb_strtolower($literal)) !== false) {
                    $scores[$intent] += 1.5;
                    $foundSignals[] = ['intent' => $intent, 'match' => $literal];
                }
            }
        }

        /* ---------- ۳) نشانه‌های سؤالی (؟ / آیا / چرا / چگونه) ---------- */
        if (mb_substr_count($query, '؟') > 0 || mb_substr_count($query, '?') > 0
            || preg_match('/^(آیا|چرا|چطور|چگونه|چیست|کدام|چه)/u', $query)) {
            $scores['informational'] += 1.0;
        }

        /* ---------- ۴) نام برند در عبارت = سیگنال ناوبری ضعیف ---------- */
        $brands = TextProcessor::loadKnowledge('brands');
        foreach ($brands as $brand) {
            $brandFa = mb_strtolower((string)($brand['name_fa'] ?? ''));
            if ($brandFa !== '' && mb_strpos($query, $brandFa) !== false) {
                $scores['navigational'] += 0.8;
                break;
            }
        }
        if (preg_match('/(سایت|وب‌سایت|وب سایت|صفحه|دانلود|اپلیکیشن|لاگین|ورود)/u', $query)) {
            $scores['navigational'] += 1.2;
        }

        /* ---------- 🏆 انتخاب برنده ---------- */
        arsort($scores);
        $top = array_key_first($scores);
        $second = array_slice($scores, 1, 1, true);
        $topScore = $scores[$top];
        $secondScore = reset($second) ?: 0.0;

        // اطمینان: فاصله از نیت دوم + قدرت مطلق سیگنال‌ها
        $margin = ($topScore - $secondScore) / max(1.0, $topScore);
        $magnitude = min(1.0, $topScore / 4.0);
        $confidence = round(max(0.3, min(1.0, 0.5 * $margin + 0.5 * $magnitude)), 2);

        // پیش‌فرض هوشمند: بدون سیگنال = اطلاعاتی (رایج‌ترین نیت)
        if ($topScore == 0.0) {
            $top = 'informational';
            $confidence = 0.3;
        }

        return $this->result($top, $confidence, $scores, $this->contentFor($top), $foundSignals);
    }

    /**
     * 📚 دسته‌بندی گروهی کلیدواژه‌ها
     *
     * @param array $queries لیست عبارات
     * @return array [عبارت => نیت] + خلاصه آماری
     */
    public function classifyBatch(array $queries): array
    {
        $results = [];
        $summary = ['informational' => 0, 'commercial' => 0, 'transactional' => 0, 'navigational' => 0];
        foreach ($queries as $query) {
            $r = $this->classify((string)$query);
            $results[(string)$query] = $r['intent'];
            $summary[$r['intent']]++;
        }
        arsort($summary);
        return [
            'classifications' => $results,
            'summary'         => $summary,
            'dominant_intent' => array_key_first($summary),
        ];
    }

    /**
     * 📝 نوع محتوای پیشنهادی برای هر نیت
     */
    private function contentFor(string $intent): string
    {
        $map = [
            'informational'  => 'user_guide|troubleshooting',  // مقاله آموزشی / رفع ایراد
            'commercial'     => 'comparison|buying_guide',     // مقایسه / راهنمای خرید
            'transactional'  => 'services_intro',              // صفحه خدمات + CTA
            'navigational'   => 'brand_intro',                 // صفحه اصلی برند
        ];
        return $map[$intent] ?? 'user_guide';
    }

    /**
     * 🔤 الگوهای قاعده‌محور هر نیت (regex فارسی)
     */
    private function signalPatterns(): array
    {
        return [
            'informational' => [
                '/^چرا/u', '/چگونه/u', '/چطور/u', '/آیا/u', '/چیست/u', '/راهنمای/u',
                '/آموزش/u', '/علت/u', '/دلیل/u', '/رفع مشکل/u', '/تعمیر خود/u', '/خودم/u',
            ],
            'commercial' => [
                '/بهترین/u', '/مقایسه/u', '/کی بهتر/u', '/کدام/u', '/بررسی/u', '/نقد/u',
                '/معایب/u', '/مزایا/u', '/قیمت/u', '/خرید/u', '/ارزش خرید/u', '/جدول/u',
            ],
            'transactional' => [
                '/ثبت درخواست/u', '/درخواست تعمیر/u', '/رزرو/u', '/نوبت/u', '/سفارش/u',
                '/تعمیر فوری/u', '/تعمیرکار/u', '/اعزام/u', '/تماس با/u', '/شماره/u',
                '/تعویض قطعه/u', '/سرویس فوری/u',
            ],
            'navigational' => [
                '/^سایت/u', '/^وب/u', '/^صفحه/u', '/لاگین/u', '/^ورود/u', '/دانلود/u',
            ],
        ];
    }

    /**
     * 🧱 ساخت خروجی استاندارد
     */
    private function result(string $intent, float $confidence, array $scores, string $content, array $signals): array
    {
        $labels = [
            'informational'  => '📚 اطلاعاتی',
            'commercial'     => '🛒 تجاری/مقایسه‌ای',
            'transactional'  => '💳 تراکنشی',
            'navigational'   => '🧭 ناوبری',
        ];
        return [
            'intent'              => $intent,
            'intent_label'        => $labels[$intent] ?? $intent,
            'confidence'          => $confidence,
            'scores'              => array_map(fn($s) => round($s, 2), $scores),
            'recommended_content' => $content,
            'signals'             => array_slice($signals, 0, 6),
        ];
    }
}
