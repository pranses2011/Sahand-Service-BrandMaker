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

        /* ✒️ v2.7: پاس نهایی نگارشی — همیشه اجرا می‌شود (مستقل از انتخاب ابعاد ضعیف)،
           چون اصلاح نیم‌فاصله/املای فارسی هیچ‌وقت مضر نیست و کیفیت متن را تضمین می‌کند */
        $grammarPolish = PersianGrammar::fix($current);
        $current = $grammarPolish['content'];

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
     * 🛡 v2.7: HTML-امن — قبلاً strip_tags کل ساختار (هدینگ/لیست/بولد) را
     * نابود می‌کرد و متن بدون قالب برمی‌گشت! حالا فقط گره‌های متنی پردازش می‌شوند.
     */
    private function fixReadability(string $content, string $focus, string $deviceKey, int $round): string
    {
        // محافظت از بلوک‌های کد
        $placeholders = [];
        $protected = preg_split('/(<pre\b[^>]*>.*?<\/pre>|<code\b[^>]*>.*?<\/code>)/ius', $content, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $parts = [];
        foreach ($protected as $i => $chunk) {
            if ($i % 2 === 1) {
                $ph = "\u{2062}RDQ" . str_repeat('Z', count($placeholders) + 1) . "\u{2062}";
                $placeholders[$ph] = $chunk;
                $parts[] = $ph;
            } else {
                $parts[] = $chunk;
            }
        }
        $content = implode('', $parts);

        // جداکردن تگ‌ها — جمله‌شکنی فقط روی متن بین تگ‌ها
        $tokens = preg_split('/(<[^>]+>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $result = '';
        foreach ($tokens as $i => $token) {
            if ($i % 2 === 1 || $token === '') { // تگ HTML
                $result .= $token;
                continue;
            }
            $result .= $this->splitLongSentences($token);
        }
        return strtr($result, $placeholders);
    }

    /** ✂️ شکستن جمله‌های بلند یک پاراگراف متنی (بدون دست‌زدن به تگ‌ها) */
    private function splitLongSentences(string $text): string
    {
        if (trim($text) === '') {
            return $text;
        }
        $sentences = TextProcessor::sentenceSplit($text);
        if (count($sentences) <= 1) {
            // شاید با «؛» بشکند — ویرگول فارسی میان‌جمله‌ای
            $sentences = array_map('trim', array_filter(explode('؛', $text), fn($s) => trim($s) !== ''));
            if (count($sentences) <= 1) {
                return $this->splitAtConjunction($text);
            }
            return implode('؛ ', array_map(fn($s) => $this->splitAtConjunction($s), $sentences));
        }
        return implode(' ', array_map(fn($s) => $this->splitAtConjunction($s), $sentences));
    }

    /** ✂️ شکستن یک جمله بلند در واژه رابط نزدیک وسط */
    private function splitAtConjunction(string $sentence): string
    {
        $words = TextProcessor::wordCount($sentence);
        if ($words <= 22) {
            return $sentence;
        }
        $tokens = explode(' ', trim($sentence));
        $mid = (int)floor(count($tokens) / 2);
        $bestSplit = $mid;
        for ($i = $mid + 4; $i > $mid - 4 && $i > 5; $i--) {
            if (in_array($tokens[$i] ?? '', ['و', 'اما', 'که', 'زیرا', 'بنابراین', 'همچنین', 'در', 'برای', 'سپس'], true)) {
                $bestSplit = $i;
                break;
            }
        }
        $first = trim(implode(' ', array_slice($tokens, 0, $bestSplit + 1)));
        $second = trim(implode(' ', array_slice($tokens, $bestSplit + 1)));
        return $second !== '' ? $first . '. ' . $second : $first;
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
     * 🛡 v2.7: HTML-امن — قبلاً strip_tags ساختار را نابود می‌کرد؛ حالا فقط متن بین تگ‌ها
     */
    private function fixCoherence(string $content, string $focus, string $deviceKey, int $round): string
    {
        $connectors = [
            'در نتیجه، ', 'برای همین، ', 'از طرفی، ', 'به عبارت دیگر، ', 'همچنین ',
            'علاوه بر این، ', 'در مقابل، ', 'به همین دلیل، ', 'جمع‌بندی اینکه، ',
        ];
        $seed = 'improve_coh|' . TextProcessor::contentHash($content) . '|' . $round;

        /* شمارش جمله‌ها روی متن ساده — تزریق روی گره‌های متنی HTML */
        $sentenceOffset = 0;
        $nextInject = 2; // اولین جمله تزریقی (شمارش از ۰)
        $tokens = preg_split('/(<[^>]+>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $result = '';
        foreach ($tokens as $i => $token) {
            if ($i % 2 === 1 || trim($token) === '') {
                $result .= $token;
                continue;
            }
            $sentences = TextProcessor::sentenceSplit($token);
            $rebuilt = [];
            foreach ($sentences as $sentence) {
                $globalIndex = $sentenceOffset;
                $sentenceOffset++;
                if ($globalIndex > 0 && $globalIndex >= $nextInject && ($globalIndex - 2) % 3 === 0) {
                    $conn = TextProcessor::seededPick($connectors, $seed . '|' . $globalIndex);
                    $alreadyStarts = false;
                    foreach ($connectors as $c) {
                        if (mb_strpos($sentence, $c) === 0) {
                            $alreadyStarts = true;
                            break;
                        }
                    }
                    if (!$alreadyStarts) {
                        $sentence = $conn . $sentence;
                        $nextInject = $globalIndex + 3;
                    }
                }
                $rebuilt[] = $sentence;
            }
            $result .= implode(' ', $rebuilt);
        }
        return $result;
    }

    /**
     * 💡 جذابیت — افزودن جمله تعاملی و آماری
     * 🛡 v2.7: HTML-امن — قلاب به‌صورت پاراگراف HTML افزوده می‌شود، نه بازسازی stripped
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

        /* 🛡 v2.7: قلاب به‌صورت پاراگراف HTML — قبل از بسته‌شدن آخرین ساختار یا در انتها */
        $hookHtml = '<p>💡 ' . $hook . '</p>';
        $lastClose = mb_strripos($content, '</p>');
        if ($lastClose !== false) {
            return mb_substr($content, 0, $lastClose) . "\n" . $hookHtml . mb_substr($content, $lastClose);
        }
        return $content . "\n" . $hookHtml;
    }

    /**
     * 🏗️ ساختار — افزودن هدینگ و لیست به متن یکنواخت
     * 🛡 v2.7: اگر محتوا از قبل HTML ساختاریافته دارد (h2/h3/ul)، دست نمی‌خورد —
     * قبلاً strip_tags کل ساختار را نابود و فقط هدینگ مکانیکی می‌ساخت.
     */
    private function fixStructure(string $content, string $focus, string $deviceKey, int $round): string
    {
        /* محتوای HTML با ساختار موجود → فقط افزودن نکات کلیدی در انتها */
        $hasHtmlStructure = preg_match('/<(h[1-6]|ul|ol|table)\b/i', $content) === 1;
        if ($hasHtmlStructure) {
            $subheads = ['نکات کلیدی و کاربردی', 'جمع‌بندی و توصیه نهایی'];
            $seed = 'improve_str|' . TextProcessor::contentHash($content) . '|' . $round;
            $sub = TextProcessor::seededPick($subheads, $seed);
            $bullets = [
                'مشکل را جدی بگیرید اما بدون عجله تصمیم نگیرید.',
                'اقدام‌های اولیه را خودتان انجام دهید.',
                'برای تعمیر تخصصی، از تکنسین مجاز کمک بگیرید.',
            ];
            $list = '<h2>' . $sub . '</h2>' . "\n" . '<ul>' . "\n" .
                implode("\n", array_map(fn($b) => '<li>' . $b . '</li>', $bullets)) . "\n" . '</ul>';
            return $content . "\n" . $list;
        }

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
