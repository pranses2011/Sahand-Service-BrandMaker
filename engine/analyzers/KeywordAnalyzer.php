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
            $w1 = mb_trim($words[$i], '،.');
            $w2 = mb_trim($words[$i + 1], '،.');
            if (mb_strlen($w1) < 3 || mb_strlen($w2) < 3) {
                continue;
            }
            $phrase = $w1 . ' ' . $w2;
            $phrases[$phrase] = ($phrases[$phrase] ?? 0) + 1;
        }

        // سه‌گرام‌ها
        for ($i = 0; $i < count($words) - 2; $i++) {
            $w1 = mb_trim($words[$i], '،.');
            $w2 = mb_trim($words[$i + 1], '،.');
            $w3 = mb_trim($words[$i + 2], '،.');
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

    /* ==================================================
     * 🆕 قابلیت‌های نسخه ۲ (v2.0)
     * ================================================== */

    /**
     * 🌱 استخراج کلیدواژه با ریشه‌یابی — شکل‌های مختلف یک کلمه
     * (تعمیر / تعمیرات / تعمیرها) در یک خوشه شمرده می‌شوند.
     *
     * @param string $text متن تحلیل‌شونده
     * @param int    $topN تعداد خوشه‌های برتر
     */
    public function extractWithStemming(string $text, int $topN = 15): array
    {
        $words = TextProcessor::tokenize($text, true);
        if (empty($words)) {
            return [];
        }
        $stopwords = $this->stopwords();

        // شمارش بر اساس ریشه + نگهداری شکل پرتکرار برای نمایش
        $stemCounts = [];
        $formCounts = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 3 || isset($stopwords[$word])) {
                continue;
            }
            $stem = TextProcessor::stem($word);
            $stemCounts[$stem] = ($stemCounts[$stem] ?? 0) + 1;
            $formCounts[$stem][$word] = ($formCounts[$stem][$word] ?? 0) + 1;
        }
        arsort($stemCounts);

        $total = array_sum($stemCounts) ?: 1;
        $result = [];
        $i = 0;
        foreach ($stemCounts as $stem => $count) {
            if ($i++ >= $topN) {
                break;
            }
            // شکل نمایشی: پرتکرارترین شکل واقعی
            $forms = $formCounts[$stem];
            arsort($forms);
            $display = array_key_first($forms);
            $result[] = [
                'keyword'   => $display,
                'stem'      => $stem,
                'count'     => $count,
                'density'   => round($count / $total * 100, 2),
                'variants'  => array_slice(array_keys($forms), 0, 4),
            ];
        }
        return $result;
    }

    /**
     * 🧮 امتیازدهی TF-IDF کلیدواژه‌ها نسبت به مجموعه اسناد مرجع
     * پایگاه دانش برندها/دستگاه‌ها به عنوان «corpus» استفاده می‌شود.
     *
     * @param string $text متن هدف
     * @param array  $corpusDocs آرایه اسناد مرجع (هر عنصر یک متن)
     * @param int    $topN تعداد نتایج
     */
    public function tfIdf(string $text, array $corpusDocs, int $topN = 15): array
    {
        $words = array_unique(TextProcessor::tokenize($text, true));
        if (empty($words) || empty($corpusDocs)) {
            return [];
        }
        // شمارش اسناد حاوی هر ریشه (IDF)
        $docFreq = [];
        foreach ($corpusDocs as $doc) {
            $docStems = [];
            foreach (TextProcessor::tokenize((string)$doc, true) as $w) {
                $docStems[TextProcessor::stem($w)] = true;
            }
            foreach ($docStems as $stem => $_) {
                $docFreq[$stem] = ($docFreq[$stem] ?? 0) + 1;
            }
        }
        $totalDocs = count($corpusDocs);

        // TF در متن هدف (بر اساس ریشه)
        $tf = [];
        $targetStems = [];
        foreach (TextProcessor::tokenize($text, true) as $w) {
            $stem = TextProcessor::stem($w);
            $tf[$stem] = ($tf[$stem] ?? 0) + 1;
            $targetStems[$stem][] = $w;
        }
        $totalWords = array_sum($tf) ?: 1;

        $scores = [];
        foreach ($tf as $stem => $count) {
            if (mb_strlen($stem) < 3) {
                continue;
            }
            $df = $docFreq[$stem] ?? 0;
            // IDF هموار (smooth) — اگر همه‌جا بود، امتیاز نزدیک صفر
            $idf = log((1 + $totalDocs) / (1 + $df)) + 1;
            $scores[$stem] = ($count / $totalWords) * $idf;
        }
        arsort($scores);

        $result = [];
        $i = 0;
        foreach ($scores as $stem => $score) {
            if ($i++ >= $topN) {
                break;
            }
            $display = $targetStems[$stem][0] ?? $stem;
            $result[] = [
                'keyword' => $display,
                'stem'    => $stem,
                'tf_idf'  => round($score, 4),
                'count'   => $tf[$stem],
                'doc_freq'=> $docFreq[$stem] ?? 0,
            ];
        }
        return $result;
    }

    /**
     * 🗂️ خوشه‌بندی کلیدواژه‌ها — گروه‌بندی بر اساس واژه مشترک
     * برای ساخت صفحات موضوعی (Topic Clusters) و سیلو لینک‌دهی.
     *
     * @param array $keywords لیست کلیدواژه‌ها (رشته یا آرایه با کلید keyword)
     */
    public function cluster(array $keywords): array
    {
        // نرمال‌سازی ورودی
        $clean = [];
        foreach ($keywords as $k) {
            $kw = is_array($k) ? (string)($k['keyword'] ?? '') : (string)$k;
            $kw = trim(mb_strtolower($kw));
            if ($kw !== '') {
                $clean[] = $kw;
            }
        }
        if (empty($clean)) {
            return [];
        }

        // استخراج «واژه‌های ستون» — واژه‌های پرتکرار میان کلیدواژه‌ها
        $wordFreq = [];
        foreach ($clean as $kw) {
            foreach (array_unique(TextProcessor::tokenize($kw, true)) as $w) {
                if (mb_strlen($w) >= 3) {
                    $wordFreq[TextProcessor::stem($w)] = ($wordFreq[TextProcessor::stem($w)] ?? 0) + 1;
                }
            }
        }
        arsort($wordFreq);
        $pillarCandidates = array_slice(array_keys($wordFreq), 0, 8);

        // تخصیص هر کلیدواژه به واژه ستونِ برترِ خود
        $clusters = [];
        $assigned = [];
        foreach ($clean as $kw) {
            $bestPillar = null;
            foreach ($pillarCandidates as $pillar) {
                foreach (TextProcessor::tokenize($kw, true) as $w) {
                    if (TextProcessor::stem($w) === $pillar) {
                        $bestPillar = $bestPillar ?: $pillar;
                        // واژه ستونی که در کلیدواژه هست و بالاترین فراوانی را دارد انتخاب شود
                        break 2;
                    }
                }
            }
            if ($bestPillar !== null) {
                $clusters[$bestPillar][] = $kw;
                $assigned[$kw] = true;
            }
        }
        // کلیدواژه‌های بدون ستون → «متفرقه»
        $rest = array_values(array_diff($clean, array_keys($assigned)));
        if (!empty($rest)) {
            $clusters['متفرقه'] = $rest;
        }

        // خروجی غنی‌شده
        $result = [];
        foreach ($clusters as $pillar => $members) {
            $result[] = [
                'pillar'    => $pillar,
                'size'      => count($members),
                'keywords'  => array_slice($members, 0, 30),
            ];
        }
        usort($result, fn($a, $b) => $b['size'] <=> $a['size']);
        return $result;
    }

    /**
     * ⚔️ برآورد رقابت‌پذیری کلیدواژه (بدون داده خارجی)
     * هیوریستیک: کلیدواژه کوتاه/عمومی = رقابتی، بلند/خاص = در دسترس
     *
     * @return array ['competition' => 'کم|متوسط|زیاد', 'score' => 0-100, 'reasons' => [...]]
     */
    public function estimateCompetition(string $keyword): array
    {
        $keyword = TextProcessor::normalize(mb_strtolower(trim($keyword)));
        $wordCount = TextProcessor::wordCount($keyword);
        $reasons = [];
        $score = 50.0; // پایه

        // ۱) طول کلیدواژه — هر کلمه اضافه، رقابت را کم می‌کند
        if ($wordCount <= 1) {
            $score += 30;
            $reasons[] = 'کلیدواژه تک‌کلمه‌ای و بسیار عمومی است';
        } elseif ($wordCount === 2) {
            $score += 12;
            $reasons[] = 'کلیدواژه دوکلمه‌ای — رقابت متوسط به بالا';
        } elseif ($wordCount >= 4) {
            $score -= 22;
            $reasons[] = 'کلیدواژه طولانی (long-tail) — رقابت پایین‌تر';
        } else {
            $score -= 5;
        }

        // ۲) واژه‌های عمومی پررقابت
        $genericWords = ['لوازم خانگی', 'تعمیرات', 'قیمت', 'خرید', 'بهترین', 'نمایندگی'];
        foreach ($genericWords as $g) {
            if (mb_strpos($keyword, $g) !== false) {
                $score += 8;
                $reasons[] = "شامل واژه پررقابت «{$g}»";
            }
        }

        // ۳) تخصصی‌بودن — نام دستگاه یا برند + مشکل، رقابت را متمرکز و در دسترس می‌کند
        $devices = TextProcessor::loadKnowledge('devices');
        foreach ($devices as $d) {
            if (!empty($d['name_fa']) && mb_strpos($keyword, mb_strtolower($d['name_fa'])) !== false) {
                $score -= 10;
                $reasons[] = 'مشخص‌کننده دستگاه است (جستجوی هدفمند)';
                break;
            }
        }

        // ۴) نیت اطلاعاتی (سؤال) معمولاً رقابت محتوایی آسان‌تری دارد
        if (preg_match('/^(آیا|چرا|چطور|چگونه)/u', $keyword) || mb_strpos($keyword, '؟') !== false) {
            $score -= 12;
            $reasons[] = 'نیت اطلاعاتی — رقابت محتوایی آسان‌تر';
        }

        $score = (int)max(5, min(100, round($score)));
        $level = $score >= 65 ? 'زیاد 🔴' : ($score >= 40 ? 'متوسط 🟡' : 'کم 🟢');

        return [
            'keyword'     => $keyword,
            'competition' => $level,
            'score'       => $score,
            'reasons'     => $reasons ?: ['کلیدواژه با رقابت متعادل'],
        ];
    }

    /**
     * 🐎 پیشنهاد کلیدواژه‌های long-tail بر پایه دانش
     * ترکیب: دستگاه + علامت/خدمت + شهر + اصلاح‌گر
     *
     * @param string $seedKeyword کلیدواژه پایه (مثلاً «تعمیر یخچال»)
     * @param int    $count تعداد پیشنهادها
     */
    public function suggestLongTail(string $seedKeyword, int $count = 12): array
    {
        $knowledge = TextProcessor::loadKnowledge('keywords');
        $devices = TextProcessor::loadKnowledge('devices');
        $cities = $knowledge['cities'] ?? [];
        $services = $knowledge['services'] ?? [];
        $modifiers = $knowledge['modifiers'] ?? [];

        // دستگاه مرتبط با کلیدواژه پایه را پیدا کن
        $relatedDevice = null;
        foreach ($devices as $d) {
            if (!empty($d['name_fa']) && mb_strpos(mb_strtolower($seedKeyword), mb_strtolower($d['name_fa'])) !== false) {
                $relatedDevice = $d['name_fa'];
                break;
            }
        }

        $candidates = [];
        // الگو ۱: پایه + اصلاح‌گر
        foreach (array_slice($modifiers, 0, 6) as $m) {
            $candidates[] = "{$seedKeyword} {$m}";
        }
        // الگو ۲: پایه + شهر
        foreach (array_slice($cities, 0, 4) as $city) {
            $candidates[] = "{$seedKeyword} در {$city}";
        }
        // الگو ۳: سؤال‌محور
        $questionTemplates = [
            "چرا {$seedKeyword} نیاز دارم؟",
            "چه زمانی {$seedKeyword} ضروری است؟",
            "هزینه {$seedKeyword} چقدر است؟",
        ];
        foreach ($questionTemplates as $qt) {
            $candidates[] = $qt;
        }
        // الگو ۴: دستگاه خاص + خدمت
        if ($relatedDevice !== null) {
            foreach (array_slice($services, 0, 4) as $s) {
                $candidates[] = "{$s} {$relatedDevice}";
            }
        }

        // امتیازدهی و انتخاب بهترین‌ها (رقابت کمتر = بهتر)
        $scored = [];
        foreach ($candidates as $cand) {
            $comp = $this->estimateCompetition($cand);
            $scored[] = [
                'keyword'     => $cand,
                'competition' => $comp['competition'],
                'competition_score' => $comp['score'],
            ];
        }
        usort($scored, fn($a, $b) => $a['competition_score'] <=> $b['competition_score']);

        return array_slice($scored, 0, $count);
    }
}
