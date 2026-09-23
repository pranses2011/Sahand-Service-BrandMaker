<?php
/**
 * 🏆 کلاس امتیازدهی کیفیت محتوا — QualityScorer v2.0
 * ================================================
 * محتوای تولیدشده را در ۷ بُعد کیفیت سنجیده و امتیاز
 * ۰ تا ۱۰۰ به همراه نقشه نقاط قوت/ضعف برمی‌گرداند.
 *
 * ابعاد سنجش:
 *   📖 خوانایی (جمله کوتاه، واژگان متنوع)
 *   🏗️ ساختار (هدینگ، لیست، جدول، پاراگراف)
 *   🔗 انسجام (واژه‌های رابط)
 *   🎯 تمرکز (تراکم کلیدواژه کانونی)
 *   💡 جذابیت (پرسش، خطاب مستقیم، CTA)
 *   📏 کفایت حجم (متناسب با نوع محتوا)
 *   ✨ تنوع جملات (انحراف معیار طول جمله)
 *
 * @package SahandBrandMaker\Engine
 * @version 2.0.0
 */
class QualityScorer
{
    /** @var array وزن ابعاد — مجموع ۱۰۰ */
    private const WEIGHTS = [
        'readability'   => 22,
        'structure'     => 20,
        'coherence'     => 14,
        'focus'         => 14,
        'engagement'    => 12,
        'length'        => 10,
        'variety'       => 8,
    ];

    /**
     * 🏆 امتیازدهی کامل محتوا
     *
     * @param string      $content محتوا (HTML یا متن ساده)
     * @param string      $focusKeyword کلیدواژه کانونی (اختیاری)
     * @param string|null $contentType نوع محتوا (article|page|intro|desc)
     * @return array ['score' => int, 'grade' => string, 'dimensions' => [...], 'strengths' => [...], 'weaknesses' => [...], 'suggestions' => [...]]
     */
    public function score(string $content, string $focusKeyword = '', ?string $contentType = null): array
    {
        $textOnly = trim(strip_tags($content));
        $readability = TextProcessor::readability($content);
        $dimensions = [];

        /* ---------- ۱) 📖 خوانایی ---------- */
        $dimensions['readability'] = $readability['score'];

        /* ---------- ۲) 🏗️ ساختار ---------- */
        $dimensions['structure'] = $this->structureScore($content, $contentType);

        /* ---------- ۳) 🔗 انسجام (واژه‌های رابط) ---------- */
        $sentenceCount = max(1, $readability['sentence_count']);
        $transitions = TextProcessor::transitionWordCount($textOnly);
        $transitionsPerSentence = $transitions / $sentenceCount;
        // بهینه: ~۱ واژه رابط در هر ۳ جمله (۰.۳۳) — نرمال‌سازی تا سقف ۲
        $dimensions['coherence'] = (int)round(min(1.0, $transitionsPerSentence / 0.35) * 100);

        /* ---------- ۴) 🎯 تمرکز بر کلیدواژه ---------- */
        if ($focusKeyword !== '') {
            $analyzer = new KeywordAnalyzer();
            $density = $analyzer->density($textOnly, $focusKeyword);
            // بازه طلایی: ۱٪ تا ۲.۵٪
            if ($density >= 1.0 && $density <= 2.5) {
                $dimensions['focus'] = 100;
            } elseif ($density > 0 && $density < 1.0) {
                $dimensions['focus'] = (int)round($density / 1.0 * 80);
            } elseif ($density > 2.5 && $density <= 4.0) {
                $dimensions['focus'] = (int)round(80 - ($density - 2.5) * 30);
            } else {
                $dimensions['focus'] = $density === 0.0 ? 0 : 20; // stuffing یا غایب
            }
        } else {
            $dimensions['focus'] = 70; // بدون کلیدواژه، بُعد خنثی
        }

        /* ---------- ۵) 💡 جذابیت ---------- */
        $dimensions['engagement'] = $this->engagementScore($textOnly);

        /* ---------- ۶) 📏 کفایت حجم ---------- */
        $dimensions['length'] = $this->lengthScore($readability['word_count'], $contentType);

        /* ---------- ۷) ✨ تنوع جملات ---------- */
        $dimensions['variety'] = $this->varietyScore($content);

        /* ---------- 🧮 امتیاز نهایی وزنی ---------- */
        $total = 0;
        foreach (self::WEIGHTS as $dim => $weight) {
            $total += ($dimensions[$dim] ?? 0) * $weight;
        }
        $finalScore = (int)round($total / 100);

        // نقاط قوت و ضعف (بالای ۷۵ = قوت، زیر ۵۰ = ضعف)
        $strengths = [];
        $weaknesses = [];
        $dimLabels = $this->dimensionLabels();
        arsort($dimensions);
        foreach ($dimensions as $dim => $value) {
            if ($value >= 75) {
                $strengths[] = $dimLabels[$dim] ?? $dim;
            } elseif ($value < 50) {
                $weaknesses[] = $dimLabels[$dim] ?? $dim;
            }
        }

        return [
            'score'       => $finalScore,
            'grade'       => $this->grade($finalScore),
            'dimensions'  => array_map('intval', $dimensions),
            'readability' => $readability,
            'strengths'   => array_slice($strengths, 0, 4),
            'weaknesses'  => array_slice($weaknesses, 0, 4),
            'suggestions' => $this->buildSuggestions($dimensions, $readability),
        ];
    }

    /**
     * 🏗️ امتیاز ساختار — هدینگ، لیست، جدول، پاراگراف کوتاه
     */
    private function structureScore(string $content, ?string $contentType): int
    {
        if (trim($content) === '') {
            return 0;
        }
        $isHtml = strip_tags($content) !== trim($content);
        $score = 50; // پایه متن ساده سالم

        if ($isHtml) {
            preg_match_all('/<h([1-6])[^>]*>/i', $content, $h);
            $headingCount = count($h[0] ?? []);
            preg_match_all('/<(ul|ol)[^>]*>/i', $content, $l);
            $listCount = count($l[0] ?? []);
            preg_match_all('/<table[^>]*>/i', $content, $t);
            $tableCount = count($t[0] ?? []);

            // هدینگ‌ها تا ۳۵ امتیاز
            $score += min(35, $headingCount * 7);
            // لیست‌ها تا ۱۰ امتیاز
            $score += min(10, $listCount * 5);
            // جدول تا ۵ امتیاز
            $score += min(5, $tableCount * 5);

            // پاراگراف‌های خیلی بلند جریمه می‌شوند
            preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $content, $p);
            $longParagraphs = 0;
            foreach ($p[1] ?? [] as $para) {
                if (TextProcessor::wordCount(strip_tags($para)) > 120) {
                    $longParagraphs++;
                }
            }
            $score -= min(25, $longParagraphs * 10);
        } elseif ($contentType === 'article') {
            $score -= 20; // مقاله بدون ساختار HTML ضعیف است
        }

        return (int)max(0, min(100, $score));
    }

    /**
     * 💡 امتیاز جذابیت — پرسش، خطاب مستقیم، اعداد، CTA
     */
    private function engagementScore(string $text): int
    {
        if (trim($text) === '') {
            return 0;
        }
        $score = 30; // پایه
        $sentences = max(1, count(TextProcessor::sentenceSplit($text)));
        $words = TextProcessor::wordCount($text) ?: 1;

        // پرسش‌ها — گفتگومحوری
        $questions = preg_match_all('/[؟?]/u', $text) ?: 0;
        $score += min(25, (int)round($questions * 9));

        // خطاب مستقیم به مخاطب («شما» / «خودتان»)
        $directAddress = mb_substr_count($text, 'شما') + mb_substr_count($text, 'خودتان') + mb_substr_count($text, 'بکنید') + mb_substr_count($text, 'کنید');
        $score += min(20, (int)round($directAddress * 5));

        // اعداد و ارقام — اعتمادسازی
        $numbers = preg_match_all('/[۰-۹0-9]+/u', $text) ?: 0;
        $score += min(15, $numbers * 3);

        // دعوت به اقدام
        if (mb_strpos($text, 'تماس') !== false || mb_strpos($text, 'درخواست') !== false || mb_strpos($text, 'ثبت') !== false) {
            $score += 10;
        }

        return (int)max(0, min(100, $score));
    }

    /**
     * 📏 امتیاز کفایت حجم بر اساس نوع محتوا
     */
    private function lengthScore(int $wordCount, ?string $contentType): int
    {
        $targets = [
            'article' => ['min' => 700, 'ideal' => 1200, 'max' => 2200],  // مقاله
            'page'    => ['min' => 300, 'ideal' => 500,  'max' => 1200],  // صفحه سایت
            'intro'   => ['min' => 120, 'ideal' => 220,  'max' => 500],   // معرفی کوتاه
            'desc'    => ['min' => 100, 'ideal' => 200,  'max' => 400],   // توضیح دستگاه
        ];
        $t = $targets[$contentType] ?? $targets['page'];

        if ($wordCount < $t['min']) {
            // خطی تا صفر (نصف حداقل = تقریباً صفر)
            return (int)max(5, round($wordCount / $t['min'] * 60));
        }
        if ($wordCount <= $t['ideal']) {
            // از ۷۵ تا ۱۰۰ خطی
            return (int)round(75 + ($wordCount - $t['min']) / max(1, $t['ideal'] - $t['min']) * 25);
        }
        if ($wordCount <= $t['max']) {
            return 100;
        }
        // بیش از حد مجاز — افت ملایم
        return (int)max(60, 100 - ($wordCount - $t['max']) / 20);
    }

    /**
     * ✨ امتیاز تنوع جملات — انحراف معیار طول جمله‌ها
     * متن یکنواخت (همه جمله‌ها هم‌طول) خسته‌کننده است.
     */
    private function varietyScore(string $content): int
    {
        $sentences = TextProcessor::sentenceSplit(strip_tags($content));
        if (count($sentences) < 3) {
            return 50;
        }
        $lengths = array_map(fn($s) => TextProcessor::wordCount($s), $sentences);
        $mean = array_sum($lengths) / count($lengths);
        if ($mean == 0) {
            return 50;
        }
        $variance = array_sum(array_map(fn($l) => ($l - $mean) ** 2, $lengths)) / count($lengths);
        $stddev = sqrt($variance);
        // ضریب تغییرات (CV) — بهینه بین ۰.۳ و ۰.۶
        $cv = $stddev / $mean;
        if ($cv >= 0.3 && $cv <= 0.6) {
            return 100;
        }
        if ($cv < 0.3) {
            return (int)round($cv / 0.3 * 100);
        }
        // خیلی پراکنده — احتمالاً جمله‌های خیلی کوتاه و خیلی بلند درهم
        return (int)max(40, round(100 - ($cv - 0.6) * 150));
    }

    /**
     * 🏷️ برچسب فارسی ابعاد
     */
    private function dimensionLabels(): array
    {
        return [
            'readability' => '📖 خوانایی',
            'structure'   => '🏗️ ساختار',
            'coherence'   => '🔗 انسجام',
            'focus'       => '🎯 تمرکز بر کلیدواژه',
            'engagement'  => '💡 جذابیت',
            'length'      => '📏 کفایت حجم',
            'variety'     => '✨ تنوع جملات',
        ];
    }

    /**
     * 🏆 تبدیل امتیاز به درجه
     */
    private function grade(int $score): string
    {
        if ($score >= 85) {
            return 'عالی 🎉';
        }
        if ($score >= 70) {
            return 'خوب ✅';
        }
        if ($score >= 55) {
            return 'قابل قبول 🤔';
        }
        return 'ضعیف ❌';
    }

    /**
     * 💡 پیشنهادهای بهبود بر اساس ضعیف‌ترین ابعاد
     */
    private function buildSuggestions(array $dimensions, array $readability): array
    {
        $suggestions = [];
        $labels = $this->dimensionLabels();

        foreach ($dimensions as $dim => $value) {
            if ($value >= 55) {
                continue;
            }
            switch ($dim) {
                case 'readability':
                    $suggestions[] = 'جمله‌های بلند را بشکنید؛ میانگین طول جمله بین ۱۲ تا ۲۰ کلمه ایده‌آل است.';
                    break;
                case 'structure':
                    $suggestions[] = 'محتوا را با هدینگ (H2/H3)، لیست و در صورت نیاز جدول ساختاربندی کنید.';
                    break;
                case 'coherence':
                    $suggestions[] = 'از واژه‌های رابط («بنابراین»، «در ادامه»، «علاوه بر این») برای اتصال طبیعی جمله‌ها استفاده کنید.';
                    break;
                case 'focus':
                    $suggestions[] = 'تراکم کلیدواژه کانونی را در بازه ۱ تا ۲.۵ درصد تنظیم کنید.';
                    break;
                case 'engagement':
                    $suggestions[] = 'پرسش‌های جذاب، خطاب مستقیم به مخاطب و دعوت به اقدام (CTA) اضافه کنید.';
                    break;
                case 'length':
                    $suggestions[] = 'حجم محتوا را با بخش‌های تکمیلی (نکات، جدول، سوالات متداول) به حد ایده‌آل برسانید.';
                    break;
                case 'variety':
                    $suggestions[] = 'طول جمله‌ها را متنوع کنید؛ ترکیب جمله‌های کوتاه و بلند ریتم بهتری می‌سازد.';
                    break;
            }
        }

        // هشدار ویژه متن‌های خیلی پیچیده
        if (($readability['complex_word_ratio'] ?? 0) > 0.25) {
            $suggestions[] = 'بیش از ۲۵٪ کلمات طولانی (۷+ حرف) است؛ واژگان ساده‌تر جایگزین کنید.';
        }

        return array_slice($suggestions, 0, 5);
    }
}
