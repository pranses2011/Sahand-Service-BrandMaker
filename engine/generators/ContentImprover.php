<?php
/**
 * 🔧 بهبوددهنده خودکار محتوا — ContentImprover v3.0
 * ==================================================
 * متن را تحلیل می‌کند، ابعاد ضعیف امتیاز کیفیت را
 * می‌یابد و استراتژی‌های هدفمند اصلاح را اجرا می‌کند.
 *
 * چرخه خود-درمانی:
 *   📊 سنجش کیفیت (۷ بُعد) → 🎯 شناسایی ابعاد ضعیف
 *   → 🔧 اعمال استراتژی هدفمند → 📊 سنجش مجدد (تا رسیدن به هدف)
 *
 * استراتژی‌ها:
 *   readability → شکستن جمله‌های بلند
 *   variety     → جایگزینی مترادف (seed دار، تکرارپذیر)
 *   coherence   → تزریق واژه‌های رابط
 *   engagement  → افزودن جملات تعاملی و آمار
 *   structure   → افزودن هدینگ/لیست به متن یکنواخت
 *   length      → افزودن پاراگراف دانش‌محور
 *   focus       → تزریق طبیعی کلیدواژه کانونی
 *
 * @package SahandBrandMaker\Engine
 * @version 3.0.0
 */
class ContentImprover
{
    /** @var QualityScorer امتیازده کیفیت */
    private $scorer;

    /** @var int حداقل امتیاز هدف */
    private $targetScore = 85;

    public function __construct()
    {
        $this->scorer = new QualityScorer();
    }

    /**
     * 🔧 بهبود محتوا — چرخه خود-درمانی
     *
     * @param string $content محتوا (HTML یا متن ساده)
     * @param array  $options [focus_keyword, target_score, max_rounds, content_type, device_key]
     * @return array محتوای بهبودیافته + گزارش قبل/بعد
     */
    public function improve(string $content, array $options = []): array
    {
        $focus = (string)($options['focus_keyword'] ?? '');
        $contentType = $options['content_type'] ?? null;
        $target = (int)($options['target_score'] ?? $this->targetScore);
        $maxRounds = max(1, min(5, (int)($options['max_rounds'] ?? 3)));
        $deviceKey = (string)($options['device_key'] ?? '');

        $before = $this->scorer->score($content, $focus, $contentType);
        $current = $content;
        $rounds = [];
        $applied = [];

        for ($round = 1; $round <= $maxRounds; $round++) {
            $score = $this->scorer->score($current, $focus, $contentType);
            if ($score['score'] >= $target) {
                break; // 🎯 هدف محقق شد
            }

            $weak = $this->weakestDimensions($score['dimensions'], 3);
            $strategiesThisRound = [];
            foreach ($weak as $dim => $val) {
                $method = 'fix' . ucfirst($dim);
                if (method_exists($this, $method)) {
                    $current = $this->{$method}($current, $focus, $deviceKey, $round);
                    $strategiesThisRound[] = $dim;
                }
            }

            $afterRound = $this->scorer->score($current, $focus, $contentType);
            $rounds[] = [
                'round'         => $round,
                'weak_dimensions' => array_keys($weak),
                'strategies'    => $strategiesThisRound,
                'score_before'  => $score['score'],
                'score_after'   => $afterRound['score'],
            ];

            if ($afterRound['score'] <= $score['score']) {
                // 🛑 بهبود بیشتر ممکن نیست — خروج برای جلوگیری از افت
                break;
            }
        }

        $after = $this->scorer->score($current, $focus, $contentType);

        return [
            'content'         => $current,
            'improved'        => $after['score'] > $before['score'],
            'gain'            => $after['score'] - $before['score'],
            'score_before'    => $before['score'],
            'score_after'     => $after['score'],
            'grade_before'    => $before['grade'],
            'grade_after'     => $after['grade'],
            'target_score'    => $target,
            'target_reached'  => $after['score'] >= $target,
            'rounds'          => $rounds,
            'dimensions_before' => $before['dimensions'],
            'dimensions_after'  => $after['dimensions'],
            'remaining_weaknesses' => $after['weaknesses'],
            'final_suggestions'   => array_slice($after['suggestions'], 0, 5),
        ];
    }

    /* ==================================================
     * 📝 اصلاح نگارش فارسی (بُعد جدید grammar — v1.1)
     * ================================================== */

    /**
     * 📝 اصلاح نگارشی فارسی — نیم‌فاصله، سجاوندی، املای رایج، هم‌خوانی فعل و فاعل
     */
    private function fixGrammar(string $content, string $focus, string $deviceKey, int $round): string
    {
        $fixed = PersianGrammar::fix($content);
        return $fixed['content'];
    }

    /* ==================================================
     * 🎯 انتخاب ابعاد ضعیف
     * ================================================== */

    private function weakestDimensions(array $dimensions, int $count): array
    {
        asort($dimensions);
        return array_slice($dimensions, 0, $count, true);
    }

    /* ==================================================
     * 🔧 استراتژی‌های اصلاح
     * ================================================== */

    /**
     * 📖 خوانایی — شکستن جمله‌های بلند (>۲۲ کلمه)
     */
    private function fixReadability(string $content, string $focus, string $deviceKey, int $round): string
    {
        $plain = trim(strip_tags($content));
        $isHtml = $plain !== $content;
        $source = $plain;

        $sentences = TextProcessor::sentenceSplit($source);
        $rebuilt = [];
        foreach ($sentences as $sentence) {
            $words = TextProcessor::wordCount($sentence);
            if ($words > 22) {
                // ✂️ نصف کردن در نزدیک‌ترین نقطه میانی
                $tokens = explode(' ', trim($sentence));
                $mid = (int)floor(count($tokens) / 2);
                // جستجوی بهترین نقطه شکست (واژه رابط یا و)
                $bestSplit = $mid;
                for ($i = $mid + 3; $i > $mid - 3 && $i > 5; $i--) {
                    if (in_array($tokens[$i] ?? '', ['و', 'اما', 'که', 'زیرا', 'بنابراین', 'همچنین'], true)) {
                        $bestSplit = $i;
                        break;
                    }
                }
                $first = trim(implode(' ', array_slice($tokens, 0, $bestSplit + 1)));
                $second = trim(implode(' ', array_slice($tokens, $bestSplit + 1)));
                $rebuilt[] = $second !== '' ? $first . '. ' . $second : $first;
            } else {
                $rebuilt[] = $sentence;
            }
        }

        $text = implode(' ', array_filter($rebuilt));
        return $text;
    }

    /**
     * ✨ تنوع — جایگزینی مترادف seed دار
     */
    private function fixVariety(string $content, string $focus, string $deviceKey, int $round): string
    {
        $seed = 'improve_variety|' . TextProcessor::contentHash($content) . '|' . $round;
        $ratio = min(0.5, 0.25 + $round * 0.1);
        return TextProcessor::applySynonyms($content, $seed, $ratio);
    }

    /**
     * 🔗 انسجام — تزریق واژه‌های رابط
     */
    private function fixCoherence(string $content, string $focus, string $deviceKey, int $round): string
    {
        $connectors = [
            'در نتیجه، ', 'برای همین، ', 'از طرفی، ', 'به عبارت دیگر، ', 'همچنین ',
            'علاوه بر این، ', 'در مقابل، ', 'به همین دلیل، ', 'جمع‌بندی اینکه، ',
        ];
        $seed = 'improve_coh|' . TextProcessor::contentHash($content) . '|' . $round;

        // تزریق در ابتدای جمله‌های دوم به بعد (هر ۳ جمله یکی)
        $sentences = TextProcessor::sentenceSplit(trim(strip_tags($content)));
        $out = [];
        foreach ($sentences as $i => $sentence) {
            if ($i > 0 && $i % 3 === 0) {
                $conn = TextProcessor::seededPick($connectors, $seed . '|' . $i);
                // پرهیز از دوباره‌چسباندن اگر جمله از قبل با رابط شروع می‌شود
                $alreadyStarts = false;
                foreach ($connectors as $c) {
                    if (mb_strpos($sentence, $c) === 0) {
                        $alreadyStarts = true;
                        break;
                    }
                }
                if (!$alreadyStarts) {
                    $sentence = $conn . $sentence;
                }
            }
            $out[] = $sentence;
        }
        return implode(' ', $out);
    }

    /**
     * 💡 جذابیت — افزودن جمله تعاملی و آماری
     */
    private function fixEngagement(string $content, string $focus, string $deviceKey, int $round): string
    {
        $hooks = [
            'آیا می‌دانستید بیش از ۶۰٪ خرابی‌های این دستگاه با نگهداری ساده قابل پیشگیری است؟',
            'نکته جالب: تعمیر به‌موقع معمولاً تا ۴۰٪ ارزان‌تر از تعویض قطعه اصلی است.',
            'سؤال: آخرین بار چه زمانی این دستگاه را سرویس دوره‌ای کردید؟',
            'تجربه نشان داده رعایت این نکات عمر دستگاه را ۲ تا ۳ سال افزایش می‌دهد.',
        ];
        $seed = 'improve_eng|' . TextProcessor::contentHash($content) . '|' . $round;
        $hook = TextProcessor::seededPick($hooks, $seed);

        // درج قبل از آخرین پاراگراف یا انتهای متن
        $paragraphs = array_values(array_filter(array_map('trim', explode("\n", trim(strip_tags($content))))));
        if (count($paragraphs) >= 2) {
            $pos = count($paragraphs) - 1;
            array_splice($paragraphs, $pos, 0, [$hook]);
            return implode("\n\n", $paragraphs);
        }
        return $content . "\n\n" . $hook;
    }

    /**
     * 🏗️ ساختار — افزودن هدینگ و لیست به متن یکنواخت
     */
    private function fixStructure(string $content, string $focus, string $deviceKey, int $round): string
    {
        $plain = trim(strip_tags($content));
        $paragraphs = array_values(array_filter(array_map('trim', explode("\n", $plain))));
        if (count($paragraphs) < 3) {
            return $content; // ساختار قابل بهبود نیست
        }

        $subheads = [
            'چرا این موضوع مهم است؟',
            'نکات کلیدی و کاربردی',
            'جمع‌بندی و توصیه نهایی',
        ];
        $out = [];
        $chunk = (int)ceil(count($paragraphs) / (count($subheads) + 1));
        $idx = 0;
        foreach ($subheads as $si => $sub) {
            for ($j = 0; $j < $chunk && $idx < count($paragraphs); $j++) {
                $out[] = $paragraphs[$idx++];
            }
            $out[] = '## ' . $sub;
        }
        while ($idx < count($paragraphs)) {
            $out[] = $paragraphs[$idx++];
        }

        $text = implode("\n\n", $out);
        // تبدیل مارک‌داون به HTML سبک
        $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
        return $text;
    }

    /**
     * 📏 حجم — افزودن پاراگراف دانش‌محور
     */
    private function fixLength(string $content, string $focus, string $deviceKey, int $round): string
    {
        $knowledge = TextProcessor::loadKnowledge('devices')[$deviceKey] ?? null;
        $extra = '';

        if ($knowledge) {
            $tips = (array)($knowledge['usage_tips'] ?? []);
            $maint = (array)($knowledge['maintenance_tips'] ?? []);
            $pick = array_merge($tips, $maint);
            if ($pick) {
                $seed = 'improve_len|' . TextProcessor::contentHash($content) . '|' . $round;
                $lines = TextProcessor::seededPickMany($pick, min(3, count($pick)), $seed);
                $extra = 'برای عملکرد بهتر و افزایش عمر دستگاه، رعایت این نکات توصیه می‌شود: '
                    . implode('؛ ', array_map(fn($t) => mb_rtrim($t, '.،') . ' است', $lines)) . '.';
            }
        }

        if ($extra === '') {
            $extra = 'لازم به یادآوری است که بازبینی دوره‌ای و رعایت اصول نگهداری، هزینه‌های تعمیر را به‌طور چشمگیری کاهش می‌دهد '
                . 'و از خرابی‌های ناگهانی در ساعات اوج استفاده پیشگیری می‌کند. توصیه کارشناسان سهند سرویس، ثبت یادآور سرویس فصلی است.';
        }

        return rtrim($content) . "\n\n" . $extra;
    }

    /**
     * 🎯 تمرکز — تزریق طبیعی کلیدواژه کانونی
     */
    private function fixFocus(string $content, string $focus, string $deviceKey, int $round): string
    {
        if ($focus === '') {
            return $content;
        }
        $density = (new KeywordAnalyzer())->density(strip_tags($content), $focus);
        if ($density >= 1.0) {
            return $content; // چگالی کافی است
        }

        $injections = [
            "در این راهنما، «{$focus}» را از زبان متخصصان بررسی می‌کنیم.",
            "نکته اصلی درباره {$focus} در ادامه آمده است.",
        ];
        $seed = 'improve_focus|' . TextProcessor::contentHash($content) . '|' . $round;
        $sentence = TextProcessor::seededPick($injections, $seed);

        // درج در پاراگراف دوم (اگر وجود داشت)
        $paragraphs = array_values(array_filter(array_map('trim', explode("\n", trim(strip_tags($content))))));
        if (count($paragraphs) >= 2) {
            array_splice($paragraphs, 1, 0, [$sentence]);
            return implode("\n\n", $paragraphs);
        }
        return rtrim($content) . "\n\n" . $sentence;
    }
}
