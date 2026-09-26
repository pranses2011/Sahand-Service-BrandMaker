<?php
/**
 * 🔤 کلاس پردازش متن فارسی — ابزار اصلی موتور AI
 * ================================================
 * نرمال‌سازی، شمارش کلمات، جایگزینی مترادف، بازنویسی جملات
 * و تولید متن یکتا از قالب‌ها.
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class TextProcessor
{
    /** @var array|null کش دیکشنری مترادف‌ها */
    private static $synonyms = null;

    /** @var array|null کش ساختارهای جمله */
    private static $sentences = null;

    /** @var array|null کش دیکشنری عبارات چندکلمه‌ای */
    private static $phrases = null;

    /**
     * 🧹 نرمال‌سازی متن فارسی (حذف نیم‌فاصله‌های خراب، یکسان‌سازی ی/ک)
     */
    public static function normalize(string $text): string
    {
        // تبدیل ی و ک عربی به فارسی
        $text = str_replace(['ي', 'ك'], ['ی', 'ک'], $text);
        // حذف اعراب
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text) ?? $text;
        // نرمال‌سازی فاصله‌ها
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        // حذف فاصله قبل از علائم
        $text = str_replace([' ،', ' .', ' ؛', ' !', ' ؟'], ['،', '.', '؛', '!', '؟'], $text);
        return trim($text);
    }

    /**
     * 🔢 شمارش کلمات متن
     */
    public static function wordCount(string $text): int
    {
        $words = preg_split('/[\s\x{200c}]+/u', self::normalize($text), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($words) ? count($words) : 0;
    }

    /**
     * 📚 بارگذاری دیکشنری مترادف‌ها از پایگاه دانش
     */
    public static function loadSynonyms(): array
    {
        if (self::$synonyms === null) {
            self::$synonyms = self::loadKnowledge('synonyms');
        }
        return self::$synonyms;
    }

    /**
     * 📚 بارگذاری ساختارهای جمله از پایگاه دانش
     */
    public static function loadSentences(): array
    {
        if (self::$sentences === null) {
            self::$sentences = self::loadKnowledge('sentences');
        }
        return self::$sentences;
    }

    /**
     * 🔤 بارگذاری دیکشنری عبارات چندکلمه‌ای از پایگاه دانش
     */
    public static function loadPhrases(): array
    {
        if (self::$phrases === null) {
            self::$phrases = self::loadKnowledge('phrases');
        }
        return self::$phrases;
    }

    /**
     * 🔄 بازنویسی عبارات چندکلمه‌ای — سطح قوی‌تر یکتاسازی
     * قبل از جایگزینی تک‌واژه‌ای مترادف‌ها اجرا می‌شود.
     * مثلاً «با کیفیت بالا» ← «در سطحی بالا از کیفیت»
     *
     * @param string $text متن ورودی
     * @param string $seed بذر تصادفی (تکرارپذیری)
     * @param float  $ratio نسبت عبارات جایگزین‌شونده
     */
    public static function applyPhrases(string $text, string $seed, float $ratio = 0.6): string
    {
        $phrases = self::loadPhrases();
        if (empty($phrases)) {
            return $text;
        }

        // مرتب‌سازی بر اساس طول نزولی تا عبارات بلندتر اول جایگزین شوند
        $keys = array_keys($phrases);
        usort($keys, function ($a, $b) {
            return mb_strlen($b) <=> mb_strlen($a);
        });

        $state = self::seededRandom($seed . '|phr');
        foreach ($keys as $phrase) {
            if (mb_strpos($text, $phrase) === false) {
                continue;
            }
            $state = ($state * 1103515245 + 12345 + mb_strlen($phrase)) & 0x7FFFFFFF;
            if (($state % 1000) / 1000 >= $ratio) {
                continue; // این عبارت این بار جایگزین نمی‌شود
            }
            $options = $phrases[$phrase];
            if (!is_array($options) || empty($options)) {
                continue;
            }
            $replacement = $options[$state % count($options)];
            $text = str_replace($phrase, $replacement, $text);
        }
        return $text;
    }

    /**
     * 📖 بارگذاری فایل دانش JSON با کش
     */
    public static function loadKnowledge(string $name): array
    {
        static $knowledgeCache = [];
        if (isset($knowledgeCache[$name])) {
            return $knowledgeCache[$name];
        }
        $file = ENGINE_PATH . '/knowledge/' . $name . '.json';
        if (!file_exists($file)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($file), true);
        $knowledgeCache[$name] = is_array($data) ? $data : [];
        return $knowledgeCache[$name];
    }

    /**
     * 🎲 مولد شانس‌های قطعی (Seedable) — برای تولید تکرارپذیر
     * هش ورودی را به عدد شانس تبدیل می‌کند
     */
    public static function seededRandom(string $seed): int
    {
        return crc32(md5($seed));
    }

    /**
     * 🎲 انتخاب یک عنصر تصادفی قطعی از آرایه بر اساس seed
     */
    public static function seededPick(array $items, string $seed)
    {
        if (empty($items)) {
            return null;
        }
        $index = self::seededRandom($seed) % count($items);
        return $items[$index];
    }

    /**
     * 🎲 انتخاب چند عنصر یکتای قطعی
     */
    public static function seededPickMany(array $items, int $count, string $seed): array
    {
        if (empty($items) || $count <= 0) {
            return [];
        }
        $keys = array_keys($items);
        // جابجایی قطعی آرایه با الگوریتم Fisher-Yates مبتنی بر seed
        $state = self::seededRandom($seed);
        for ($i = count($keys) - 1; $i > 0; $i--) {
            $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;
            $j = $state % ($i + 1);
            [$keys[$i], $keys[$j]] = [$keys[$j], $keys[$i]];
        }
        $picked = array_slice($keys, 0, min($count, count($keys)));
        return array_map(function ($k) use ($items) {
            return $items[$k];
        }, $picked);
    }

    /**
     * 🔄 جایگزینی مترادف‌ها در متن (کلید تنوع‌سازی)
     *
     * @param string $text متن ورودی
     * @param string $seed بذر تصادفی (تکرارپذیری)
     * @param float  $ratio نسبت کلمات جایگزین‌شونده (۰ تا ۱)
     */
    public static function applySynonyms(string $text, string $seed, float $ratio = 0.35): string
    {
        $synonyms = self::loadSynonyms();
        if (empty($synonyms)) {
            return $text;
        }

        // تقسیم متن به کلمات با حفظ علائم
        $tokens = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($tokens)) {
            return $text;
        }

        $state = self::seededRandom($seed);
        foreach ($tokens as $i => &$token) {
            if (trim($token) === '') {
                continue;
            }
            // کلمه بدون علائم برای جستجو
            $cleanWord = preg_replace('/[^\p{L}\p{N}\x{0600}-\x{06FF}]/u', '', $token);
            if ($cleanWord === '' || mb_strlen($cleanWord) < 3) {
                continue;
            }
            if (!isset($synonyms[$cleanWord]) || !is_array($synonyms[$cleanWord])) {
                continue;
            }
            // آیا این کلمه باید جایگزین شود؟ (قطعی بر اساس seed)
            $state = ($state * 1103515245 + 12345 + $i) & 0x7FFFFFFF;
            if (($state % 1000) / 1000 < $ratio) {
                $options = $synonyms[$cleanWord];
                $replacement = $options[$state % count($options)];
                // حفظ علائم ابتدا و انتهای توکن
                $token = preg_replace(
                    '/'. preg_quote($cleanWord, '/') . '/u',
                    $replacement,
                    $token,
                    1
                );
            }
        }
        unset($token);
        return implode('', $tokens);
    }

    /**
     * 🔀 بازنویسی ساختار جمله — تغییر ترتیب و اتصال جملات
     * مثلاً «A است چون B» ← «به این دلیل که B، A است»
     */
    public static function restructureSentences(string $text, string $seed): string
    {
        $sentences = self::loadSentences();
        $connectors = $sentences['connectors'] ?? [];
        $openers = $sentences['openers'] ?? [];
        if (empty($openers)) {
            return $text;
        }

        // تقسیم به جمله‌ها (بر اساس . ! ؟)
        $parts = preg_split('/(?<=[.！!؟?])\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || count($parts) < 2) {
            return $text;
        }

        $result = [];
        $state = self::seededRandom($seed . 'restruct');
        foreach ($parts as $i => $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }
            $state = ($state * 1103515245 + 12345 + $i) & 0x7FFFFFFF;
            // با احتمال ۳۰٪ یک شروع‌کننده به جمله اضافه می‌شود
            if ($i > 0 && ($state % 100) < 30 && !empty($openers)) {
                $opener = $openers[$state % count($openers)];
                // اگر جمله با شروع‌کننده شروع نشده باشد
                if (mb_strpos($sentence, $opener) !== 0) {
                    $result[] = $opener . '، ' . mb_lcfirst_safe($sentence);
                    continue;
                }
            }
            $result[] = $sentence;
        }
        $text = implode(' ', $result);

        // 🔗 اتصال جملات کوتاه متوالی با conjuction ها
        if (!empty($connectors) && count($result) >= 2) {
            $state = self::seededRandom($seed . 'conn');
            $merged = [];
            $i = 0;
            while ($i < count($result)) {
                $current = $result[$i];
                $next = $result[$i + 1] ?? '';
                $nextWords = self::wordCount($next);
                if ($next !== '' && $nextWords < 12 && ($state % 100) < 40) {
                    $connector = $connectors[$state % count($connectors)];
                    $merged[] = rtrim($current, '.') . ' ' . $connector . ' ' . mb_lcfirst_safe($next);
                    $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;
                    $i += 2;
                } else {
                    $merged[] = $current;
                    $i++;
                    $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;
                }
            }
            $text = implode(' ', $merged);
        }
        return $text;
    }

    /**
     * ✂️ تقسیم متن به پاراگراف‌های خوانا (هر ۳-۵ جمله)
     */
    public static function toParagraphs(string $text, int $sentencesPerParagraph = 4): string
    {
        $parts = preg_split('/(?<=[.！!؟?])\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || count($parts) <= $sentencesPerParagraph) {
            return $text;
        }
        $paragraphs = [];
        $current = [];
        foreach ($parts as $sentence) {
            $current[] = $sentence;
            if (count($current) >= $sentencesPerParagraph) {
                $paragraphs[] = implode(' ', $current);
                $current = [];
            }
        }
        if (!empty($current)) {
            $paragraphs[] = implode(' ', $current);
        }
        return implode("\n\n", $paragraphs);
    }

    /**
     * 🧩 جایگذاری متغیرها در قالب — {{name}} => value
     */
    public static function fillTemplate(string $template, array $vars): string
    {
        $map = [];
        foreach ($vars as $key => $value) {
            $map['{{' . $key . '}}'] = (string)$value;
        }
        return strtr($template, $map);
    }

    /**
     * 🧹 v2.27 — جاروی متغیرهای باقی‌مانده {{...}}
     * ===========================================
     * ریشه باگ «در متن مقالات نوشته‌هایی مانند {{warranty_period}} و
     * {{agency_name}} همینطوری باقی می‌ماند»: بعضی قالب‌های پایگاه دانش
     * متغیرهایی دارند که مولد مقاله به $vars پاس نمی‌داد → fillTemplate
     * فقط متغیرهای معلوم را جایگزین می‌کند و بقیه خام می‌مانند.
     *
     * این متد دو کار می‌کند:
     *  ① متغیرهای شناخته‌شدهٔ $map را جایگزین می‌کند
     *  ② هر {{unknown_var}} باقی‌مانده را با $fallback (یا حذف امن) پاک می‌کند
     *
     * الگو فقط {{identifier}} ساده است — HTML خام دست‌نخورده می‌ماند.
     */
    public static function sweepPlaceholders(string $text, array $map = [], string $fallback = ''): string
    {
        if ($text === '' || strpos($text, '{{') === false) {
            return $text;
        }
        /* ① جایگزینی متغیرهای معلوم (حساس به فاصله‌های داخل آکولاد) */
        foreach ($map as $key => $value) {
            $text = str_replace(['{{' . $key . '}}', '{{ ' . $key . ' }}', '{{ ' . $key . '}}', '{{' . $key . ' }}'], (string)$value, $text);
        }
        /* ② هر متغیر ناشناختهٔ باقی‌مانده — شناسهٔ امن (\w و - و فاصله) */
        if (strpos($text, '{{') !== false) {
            $text = preg_replace('/\{\{\s*[\w\-\x{0600}-\x{06FF}\.]+\s*\}\}/u', $fallback, $text) ?? $text;
        }
        return $text;
    }

    /**
     * 📊 استخراج n-gram های متن (برای بررسی یکتایی)
     *
     * @return array آرایه از عبارت‌های n کلمه‌ای
     */
    public static function shingles(string $text, int $n = 3): array
    {
        $normalized = self::normalize(mb_strtolower($text));
        $words = preg_split('/[\s\x{200c}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words) || count($words) < $n) {
            return [$normalized];
        }
        $shingles = [];
        for ($i = 0; $i <= count($words) - $n; $i++) {
            $shingles[] = implode(' ', array_slice($words, $i, $n));
        }
        return $shingles;
    }

    /**
     * 🔐 هش محتوا (برای مقایسه سریع یکتایی)
     */
    public static function contentHash(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(self::normalize($text)));
        return hash('sha256', $normalized);
    }

    /* ==================================================
     * 🆕 قابلیت‌های نسخه ۲ موتور (v2.0)
     * ================================================== */

    /**
     * ✂️ توکنایزر عمومی کلمات فارسی
     * متن را به آرایه کلمات (بدون علائم و ایست‌واژه) تبدیل می‌کند.
     *
     * @param bool $removeStopwords حذف ایست‌واژه‌ها
     */
    public static function tokenize(string $text, bool $removeStopwords = false): array
    {
        $normalized = self::normalize(mb_strtolower($text));
        $words = preg_split('/[\s\x{200c}\.,،؛:!؟()\[\]«»"\'\/\-]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words)) {
            return [];
        }
        if (!$removeStopwords) {
            return $words;
        }
        $stopwords = [
            'و','در','به','از','که','این','آن','را','با','برای','است','می','شد','شده','های','تر','ترین',
            'هم','نیز','اگر','تا','هر','یک','دو','سه','خود','او','ما','شما','آنها','اینها','بر',
            'اما','یا','وقتی','زیرا','چون','بنابراین','پس','دیگر','بسیار','خب','البته','یعنی','مثلا','شود','بود',
            'the','and','for','with','that','this','from','are','was','were','have','has','will','can',
        ];
        return array_values(array_filter($words, fn($w) => !in_array($w, $stopwords, true)));
    }

    /**
     * 🌱 ریشه‌یاب سبک فارسی (Light Stemmer)
     * پسوندهای رایج را جدا می‌کند تا شکل‌های مختلف یک کلمه
     * (مثل «تعمیر»، «تعمیرات»، «تعمیرها») یکسان دیده شوند.
     * برای خوشه‌بندی کلیدواژه و TF-IDF.
     */
    public static function stem(string $word): string
    {
        $word = self::normalize(mb_strtolower(trim($word)));
        if (mb_strlen($word) <= 3) {
            return $word;
        }
        // پسوندهای فارسی — از بلندترین به کوتاه‌ترین (ترتیب مهم است)
        $suffixes = [
            'هایشان', 'هایتان', 'هایمان', 'هایی', 'ترین‌ها', 'هایم', 'هایت', 'هایش',
            'ترین', 'های', 'ات', 'هایی', 'شان', 'تان', 'مان', 'ها', 'تر',
        ];
        foreach ($suffixes as $suffix) {
            if (mb_strlen($word) > mb_strlen($suffix) + 2 && mb_substr($word, -mb_strlen($suffix)) === $suffix) {
                $word = mb_substr($word, 0, mb_strlen($word) - mb_strlen($suffix));
                // حذف نیم‌فاصله/فاصله باقی‌مانده انتهای کلمه (مثل «گران‌ترین» → «گران»)
                $word = rtrim($word, "\u{200C} ");
                break; // فقط یک پسوند حذف شود (سبک)
            }
        }
        // حذف «می‌» ابتدای فعل (نیم‌فاصله یا فاصله)
        if (mb_substr($word, 0, 3) === 'می‌') {
            $word = mb_substr($word, 3);
        }
        return $word;
    }

    /**
     * 📖 تقسیم متن به جمله‌ها (عمومی — برای همه ماژول‌ها)
     */
    public static function sentenceSplit(string $text): array
    {
        $parts = preg_split('/(?<=[.！!؟?؛])\s+/u', trim(strip_tags($text)), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return trim($text) === '' ? [] : [trim($text)];
        }
        return array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));
    }

    /**
     * 📊 سنجش خوانایی متن فارسی — مجموعه متریک‌های نسخه ۲
     * میانگین طول جمله، نسبت جمله‌های بلند، تنوع واژگان (TTR)،
     * میانگین طول کلمه و نسبت کلمات پیچیده (≥۷ حرف).
     */
    public static function readability(string $text): array
    {
        $plain = trim(strip_tags($text));
        $sentences = self::sentenceSplit($plain);
        $words = self::tokenize($plain);
        $wordCount = count($words);
        $sentenceCount = count($sentences);

        if ($wordCount === 0 || $sentenceCount === 0) {
            return [
                'word_count' => 0, 'sentence_count' => 0, 'avg_sentence_length' => 0.0,
                'long_sentence_ratio' => 0.0, 'avg_word_length' => 0.0,
                'lexical_diversity' => 0.0, 'complex_word_ratio' => 0.0, 'score' => 0,
            ];
        }

        // میانگین طول جمله (کلمه)
        $sentenceLengths = array_map(fn($s) => self::wordCount($s), $sentences);
        $avgSentenceLength = array_sum($sentenceLengths) / $sentenceCount;

        // نسبت جمله‌های بلند (>۲۵ کلمه)
        $longSentences = count(array_filter($sentenceLengths, fn($l) => $l > 25));
        $longRatio = $longSentences / $sentenceCount;

        // میانگین طول کلمه
        $totalChars = array_sum(array_map('mb_strlen', $words));
        $avgWordLength = $totalChars / $wordCount;

        // تنوع واژگان — Type-Token Ratio روی ریشه کلمات
        $stems = array_map([self::class, 'stem'], $words);
        $lexicalDiversity = count(array_unique($stems)) / $wordCount;

        // نسبت کلمات پیچیده (≥۷ حرف)
        $complexWords = count(array_filter($words, fn($w) => mb_strlen($w) >= 7));
        $complexRatio = $complexWords / $wordCount;

        // امتیاز خوانایی ۰-۱۰۰ (هرچه بالاتر، خواناتر)
        // جمله کوتاه‌تر + واژگان متنوع‌تر اما نه پیچیده = خواناتر
        $lengthScore = max(0, 100 - ($avgSentenceLength - 8) * 5);        // بهینه: ۸-۲۰ کلمه
        $varietyScore = min(100, $lexicalDiversity * 160);                // بهینه: ۰.۵۵-۰.۶۵
        $simplicityScore = max(0, 100 - $complexRatio * 350);             // زیر ۲۰٪ کلمه پیچیده
        $longPenalty = $longRatio * 60;
        $score = (int)round(max(0, min(100, ($lengthScore * 0.4 + $varietyScore * 0.25 + $simplicityScore * 0.35) - $longPenalty)));

        return [
            'word_count'          => $wordCount,
            'sentence_count'      => $sentenceCount,
            'avg_sentence_length' => round($avgSentenceLength, 1),
            'long_sentence_ratio' => round($longRatio, 3),
            'avg_word_length'     => round($avgWordLength, 1),
            'lexical_diversity'   => round($lexicalDiversity, 3),
            'complex_word_ratio'  => round($complexRatio, 3),
            'score'               => $score,
        ];
    }

    /**
     * 🔗 شمارش واژه‌های رابط (Transition Words)
     * نشانه انسجام متن — برای QualityScorer و SeoAnalyzer v2
     */
    public static function transitionWordCount(string $text): int
    {
        $transitions = [
            'بنابراین','در نتیجه','به همین دلیل','از این رو','در واقع','در حقیقت','به عبارت دیگر',
            'علاوه بر این','همچنین','در کنار آن','از سوی دیگر','برعکس','در مقابل','در مقابل آن',
            'ابتدا','سپس','در ادامه','در پایان','در نهایت','سرانجام','اول','دوم','سوم',
            'برای مثال','مثلا','به عنوان مثال','به طور مثال','یعنی','به بیان دیگر',
            'به طور کلی','کلاً','معمولا','اغلب','بیشتر وقت‌ها','در اکثر موارد',
            'نکته مهم','نکته کلیدی','خلاصه اینکه','جمع‌بندی','در مجموع','در کل',
            'توجه کنید','توجه داشته باشید','لازم به ذکر است','گفتنی است','شایان ذکر است',
        ];
        $count = 0;
        foreach ($transitions as $tr) {
            $count += mb_substr_count($text, $tr);
        }
        return $count;
    }
}

/**
 * 🐫 تبدیل حرف اول جمله به کوچک (فارسی حروف بزرگ ندارد؛ برای سازگاری)
 */
if (!function_exists('mb_lcfirst_safe')) {
    function mb_lcfirst_safe(string $s): string
    {
        return $s;
    }
}
