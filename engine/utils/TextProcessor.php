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
