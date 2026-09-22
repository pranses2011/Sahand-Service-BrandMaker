<?php
/**
 * 🔍 کلاس تحلیل کلیدواژه — موتور سئو
 * ===================================
 * استخراج کلیدواژه‌ها، محاسبه تراکم، پیشنهاد
 * long-tail و تحلیل رقابتی‌بودن.
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class KeywordAnalyzer
{
    /**
     * 📊 استخراج کلیدواژه‌های متن (فراوانی + موقعیت)
     *
     * @param string $text متن تحلیل‌شونده
     * @param int    $topN تعداد کلیدواژه‌های برتر
     */
    public function extract(string $text, int $topN = 20): array
    {
        $normalized = TextProcessor::normalize(mb_strtolower($text));
        $words = preg_split('/[\s\x{200c}.,،؛:!؟()«»"\'\[\]]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words)) {
            return [];
        }

        // 🚫 حذف ایست‌واژه‌های فارسی (Stopwords)
        $stopwords = $this->stopwords();
        $freq = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 3 || isset($stopwords[$word])) {
                continue;
            }
            $freq[$word] = ($freq[$word] ?? 0) + 1;
        }
        arsort($freq);

        $total = array_sum($freq) ?: 1;
        $result = [];
        $i = 0;
        foreach ($freq as $word => $count) {
            if ($i++ >= $topN) {
                break;
            }
            $result[] = [
                'keyword' => $word,
                'count'   => $count,
                'density' => round($count / $total * 100, 2), // درصد تراکم
            ];
        }
        return $result;
    }

    /**
     * 🔗 استخراج عبارات دو و سه‌کلمه‌ای (n-gram فراوان)
     */
    public function extractPhrases(string $text, int $topN = 15): array
    {
        $normalized = TextProcessor::normalize(mb_strtolower($text));
        $words = preg_split('/[\s\x{200c}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words) || count($words) < 2) {
            return [];
        }
        $stopwords = $this->stopwords();
        $phrases = [];

        // بی‌گرام‌ها
        for ($i = 0; $i < count($words) - 1; $i++) {
            $w1 = trim($words[$i], '،.');
            $w2 = trim($words[$i + 1], '،.');
            if (mb_strlen($w1) < 3 || mb_strlen($w2) < 3) {
                continue;
            }
            $phrase = $w1 . ' ' . $w2;
            $phrases[$phrase] = ($phrases[$phrase] ?? 0) + 1;
        }

        // سه‌گرام‌ها
        for ($i = 0; $i < count($words) - 2; $i++) {
            $w1 = trim($words[$i], '،.');
            $w2 = trim($words[$i + 1], '،.');
            $w3 = trim($words[$i + 2], '،.');
            if (mb_strlen($w1) < 3 || mb_strlen($w3) < 3 || mb_strlen($w2) < 2) {
                continue;
            }
            $phrase = $w1 . ' ' . $w2 . ' ' . $w3;
            $phrases[$phrase] = ($phrases[$phrase] ?? 0) + 1;
        }

        // حذف عبارات دارای ایست‌واژه در ابتدا/انتها + مرتب‌سازی
        $filtered = [];
        foreach ($phrases as $phrase => $count) {
            if ($count < 2) {
                continue; // فقط عبارات تکرارشده
            }
            $parts = explode(' ', $phrase);
            if (isset($stopwords[$parts[0]]) || isset($stopwords[end($parts)])) {
                continue;
            }
            $filtered[$phrase] = $count;
        }
        arsort($filtered);

        return array_slice(array_keys($filtered), 0, $topN);
    }

    /**
     * 🎯 پیشنهاد کلیدواژه‌های برند — ترکیب برند + دستگاه + خدمات
     *
     * @param array $brand اطلاعات برند (name_fa, name_en)
     * @param array $devices لیست دستگاه‌های برند (name_fa)
     */
    public function suggestBrandKeywords(array $brand, array $devices): array
    {
        $knowledge = TextProcessor::loadKnowledge('keywords');
        $services = $knowledge['services'] ?? [];
        $modifiers = $knowledge['modifiers'] ?? [];
        $cities = $knowledge['cities'] ?? [];

        $keywords = [];
        $brandFa = $brand['name_fa'];
        $brandEn = $brand['name_en'];

        // 🔑 کلیدواژه‌های اصلی
        $keywords[] = "تعمیرات {$brandFa}";
        $keywords[] = "نمایندگی {$brandFa}";
        $keywords[] = "تعمیر {$brandFa} {$brandEn}";
        $keywords[] = "خدمات پس از فروش {$brandFa}";

        // 🔗 ترکیب برند + دستگاه
        foreach (array_slice($devices, 0, 10) as $device) {
            $d = $device['name_fa'] ?? '';
            if ($d === '') {
                continue;
            }
            $keywords[] = "تعمیر {$d} {$brandFa}";
            $keywords[] = "تعمیرکار {$d} {$brandEn}";
            foreach (array_slice($services, 0, 4) as $service) {
                $keywords[] = "{$service} {$d} {$brandFa}";
            }
        }

        // 🐎 کلیدواژه‌های Long-tail
        foreach (array_slice($modifiers, 0, 6) as $modifier) {
            $keywords[] = "{$modifier} تعمیر {$brandFa}";
            if (!empty($devices)) {
                $keywords[] = "{$modifier} تعمیر " . $devices[0]['name_fa'] . " {$brandFa}";
            }
        }

        // 🏙️ ترکیب با شهرها (سئوی محلی)
        foreach (array_slice($cities, 0, 3) as $city) {
            $keywords[] = "تعمیر {$brandFa} در {$city}";
            $keywords[] = "تعویض قطعات {$brandFa} {$city}";
        }

        return array_values(array_unique($keywords));
    }

    /**
     * 📊 محاسبه تراکم یک کلیدواژه در متن (درصد)
     */
    public function density(string $text, string $keyword): float
    {
        $normalized = TextProcessor::normalize(mb_strtolower($text));
        $totalWords = TextProcessor::wordCount($normalized);
        if ($totalWords === 0) {
            return 0.0;
        }
        $keyword = TextProcessor::normalize(mb_strtolower($keyword));
        $occurrences = mb_substr_count($normalized, $keyword);
        // برای کلیدواژه چندکلمه‌ای، تقسیم بر تعداد کلمات آن
        $kwWords = max(1, TextProcessor::wordCount($keyword));
        return round(($occurrences * $kwWords / $totalWords) * 100, 2);
    }

    /**
     * 💡 تحلیل و پیشنهاد بهبود توزیع کلیدواژه در متن
     *
     * @return array لیست پیشنهادها
     */
    public function analyzeDensity(string $text, string $keyword): array
    {
        $density = $this->density($text, $keyword);
        $suggestions = [];

        if ($density === 0.0) {
            $suggestions[] = "❗ کلیدواژه «{$keyword}» در متن استفاده نشده است. حداقل ۲ بار استفاده کنید.";
        } elseif ($density < 0.5) {
            $suggestions[] = "📉 تراکم کلیدواژه «{$keyword}» پایین است ({$density}%) — حد ایده‌آل ۱ تا ۳ درصد است.";
        } elseif ($density > 3.5) {
            $suggestions[] = "📈 تراکم کلیدواژه «{$keyword}» بالاست ({$density}%) — از Keyword Stuffing بپرهیزید.";
        } else {
            $suggestions[] = "✅ تراکم کلیدواژه «{$keyword}» بهینه است ({$density}%).";
        }

        // بررسی حضور در ابتدای متن (۱۵۰ کلمه اول)
        $intro = implode(' ', array_slice(preg_split('/\s+/u', $text, 200) ?: [], 0, 150));
        if (mb_stripos(TextProcessor::normalize($intro), TextProcessor::normalize($keyword)) === false) {
            $suggestions[] = "⚠️ کلیدواژه باید در ۱۵۰ کلمه اول متن (مقدمه) حضور داشته باشد.";
        }
        return $suggestions;
    }

    /**
     * 🚫 ایست‌واژه‌های فارسی
     */
    private function stopwords(): array
    {
        $list = [
            'و','در','به','از','که','این','آن','را','با','برای','است','می','شد','شده','های','تر','ترین',
            'هم','نیز','اگر','تا','هر','یک','دو','سه','خود','او','ما','شما','آنها','اینها','بر','pero',
            'اما','یا','وقتی','زیرا','چون','بنابراین','پس','دیگر','بسیار','خب','البته','یعنی','مثلا',
            'the','and','for','with','that','this','from','are','was','were','have','has','will','can',
        ];
        return array_flip($list);
    }
}
