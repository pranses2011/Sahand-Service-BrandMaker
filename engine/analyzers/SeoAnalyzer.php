<?php
/**
 * 📊 کلاس تحلیل و امتیازدهی سئو
 * ===============================
 * امتیاز ۰ تا ۱۰۰ برای هر صفحه بر اساس چک‌لیست جامع
 * سئوی On-Page و فنی + ارائه پیشنهادهای عملی.
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class SeoAnalyzer
{
    /**
     * 📊 تحلیل کامل صفحه و محاسبه امتیاز
     *
     * @param array $page داده‌های صفحه:
     *   title, meta_description, keywords, content(html), slug, h1..h6, images, links
     * @param string $focusKeyword کلیدواژه کانونی
     * @return array ['score' => int, 'checks' => [...], 'suggestions' => [...]]
     */
    public function analyze(array $page, string $focusKeyword = ''): array
    {
        $checks = [];
        $score = 0;
        $totalWeight = 0;

        // ⚖️ هر بررسی دارای وزن است — مجموع ۱۰۰
        $addCheck = function (string $id, string $label, bool $passed, int $weight, string $advice = '') use (&$checks, &$score, &$totalWeight) {
            $totalWeight += $weight;
            if ($passed) {
                $score += $weight;
            }
            $checks[] = [
                'id'      => $id,
                'label'   => $label,
                'passed'  => $passed,
                'weight'  => $weight,
                'advice'  => $advice,
            ];
        };

        $title = trim((string)($page['title'] ?? ''));
        $meta = trim((string)($page['meta_description'] ?? ''));
        $content = (string)($page['content'] ?? '');
        $textOnly = trim(strip_tags($content));
        $wordCount = TextProcessor::wordCount($textOnly);

        /* ---------- 🏷️ عنوان صفحه ---------- */
        $addCheck('title_exists', 'وجود عنوان (Title)', $title !== '', 8, 'یک عنوان برای صفحه تعیین کنید.');
        $titleLen = mb_strlen($title);
        $addCheck('title_length', 'طول عنوان ۳۰ تا ۶۰ کاراکتر', $titleLen >= 30 && $titleLen <= 60, 5,
            "طول عنوان فعلی {$titleLen} کاراکتر است.");

        /* ---------- 📝 متا توضیحات ---------- */
        $addCheck('meta_exists', 'وجود متا توضیحات', $meta !== '', 7, 'توضیحات متا را بنویسید.');
        $metaLen = mb_strlen($meta);
        $addCheck('meta_length', 'طول متا ۱۲۰ تا ۱۶۰ کاراکتر', $metaLen >= 120 && $metaLen <= 160, 5,
            "طول متا فعلی {$metaLen} کاراکتر است.");

        /* ---------- 🔑 کلیدواژه کانونی ---------- */
        if ($focusKeyword !== '') {
            $analyzer = new KeywordAnalyzer();
            $inTitle = mb_stripos($title, $focusKeyword) !== false;
            $inMeta = mb_stripos($meta, $focusKeyword) !== false;
            $inUrl = isset($page['slug']) && mb_stripos(SlugGenerator::generate($page['slug']), SlugGenerator::generate($focusKeyword)) !== false;
            $density = $analyzer->density($textOnly, $focusKeyword);

            $addCheck('kw_in_title', 'کلیدواژه در عنوان', $inTitle, 6, 'کلیدواژه کانونی را در عنوان بگنجانید.');
            $addCheck('kw_in_meta', 'کلیدواژه در متا', $inMeta, 5, 'کلیدواژه کانونی را در متا توضیحات بگنجانید.');
            $addCheck('kw_in_url', 'کلیدواژه در URL', $inUrl, 4, 'از کلیدواژه در اسلاگ صفحه استفاده کنید.');
            $addCheck('kw_density', 'تراکم کلیدواژه ۱-۳٪', $density >= 0.8 && $density <= 3.0, 6,
                "تراکم فعلی: {$density}%");

            // حضور کلیدواژه در ابتدای محتوا
            $intro = mb_substr($textOnly, 0, 600);
            $addCheck('kw_in_intro', 'کلیدواژه در پاراگراف اول', mb_stripos($intro, $focusKeyword) !== false, 4);
        }

        /* ---------- 📄 محتوا ---------- */
        $addCheck('content_length', 'حداقل ۳۰۰ کلمه محتوا', $wordCount >= 300, 8,
            "محتوای فعلی {$wordCount} کلمه دارد.");
        $addCheck('content_depth', 'محتوای مفصل ۶۰۰+ کلمه', $wordCount >= 600, 4);

        // ساختار هدینگ‌ها
        preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $content, $hMatches);
        $h1Count = 0;
        $headings = [];
        foreach ($hMatches[1] ?? [] as $i => $level) {
            $headings[] = ['level' => (int)$level, 'text' => trim(strip_tags($hMatches[2][$i]))];
            if ((int)$level === 1) {
                $h1Count++;
            }
        }
        $addCheck('h1_exists', 'وجود یک H1', $h1Count === 1, 6,
            'هر صفحه باید دقیقاً یک تگ H1 داشته باشد.');
        $addCheck('heading_structure', 'ساختار H2/H3', count($headings) >= 2, 5,
            'محتوا را با زیرعنوان‌های H2 و H3 ساختاربندی کنید.');

        /* ---------- 🖼️ تصاویر ---------- */
        preg_match_all('/<img[^>]*>/i', $content, $imgMatches);
        $imgs = $imgMatches[0] ?? [];
        $imgsTotal = count($imgs);
        $withAlt = 0;
        foreach ($imgs as $img) {
            if (preg_match('/alt\s*=\s*(["\'])[^\1]+\1/i', $img)) {
                $withAlt++;
            }
        }
        $addCheck('img_alt', 'Alt تصاویر', $imgsTotal === 0 || $withAlt === $imgsTotal, 5,
            "{$withAlt} از {$imgsTotal} تصویر دارای alt هستند.");

        /* ---------- 🔗 لینک‌ها ---------- */
        preg_match_all('/<a[^>]*href\s*=/i', $content, $aMatches);
        $linksCount = count($aMatches[0] ?? []);
        $addCheck('internal_links', 'لینک‌دهی داخلی', $linksCount >= 2, 4,
            'حداقل ۲ لینک داخلی به صفحات مرتبط بدهید.');

        /* ---------- ⚙️ فنی ---------- */
        $addCheck('slug_clean', 'URL کوتاه و تمیز', mb_strlen((string)($page['slug'] ?? '')) <= 75, 3);
        $robots = (string)($page['seo_robots'] ?? 'index,follow');
        $addCheck('robots_index', 'قابل ایندکس', strpos($robots, 'noindex') === false, 5,
            'صفحه روی noindex تنظیم شده است.');
        $addCheck('og_image', 'تصویر Open Graph', !empty($page['og_image']), 4,
            'یک تصویر OG با ابعاد ۱۲۰۰×۶۳۰ تعیین کنید.');

        /* ---------- 🌡️ خوانایی فارسی ---------- */
        $sentences = preg_split('/(?<=[.！!؟?])\s+/u', $textOnly, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $avgSentenceLen = count($sentences) > 0 ? $wordCount / count($sentences) : 0;
        $addCheck('readability', 'جمله‌های کوتاه (میانگین <۲۵ کلمه)', $avgSentenceLen > 0 && $avgSentenceLen < 25, 4,
            'میانگین طول جمله: ' . round($avgSentenceLen, 1) . ' کلمه');

        /* ==================================================
         * 🆕 بررسی‌های نسخه ۲ (v2.0)
         * ================================================== */

        /* ---------- 🏷️ کلیدواژه در سرفصل‌ها (H2/H3) ---------- */
        if ($focusKeyword !== '' && !empty($headings)) {
            $kwInHeadings = 0;
            foreach ($headings as $h) {
                if ($h['level'] >= 2 && mb_stripos($h['text'], $focusKeyword) !== false) {
                    $kwInHeadings++;
                }
            }
            $addCheck('kw_in_subheadings', 'کلیدواژه در حداقل یک سرفصل H2/H3', $kwInHeadings >= 1, 5,
                $kwInHeadings === 0 ? 'کلیدواژه کانونی در هیچ سرفصلی نیامده است.' : "در {$kwInHeadings} سرفصل حضور دارد.");
        }

        /* ---------- 🔗 انسجام متن (واژه‌های رابط) ---------- */
        $transitionCount = TextProcessor::transitionWordCount($textOnly);
        $transitionRatio = count($sentences) > 0 ? $transitionCount / count($sentences) : 0;
        $addCheck('transition_words', 'انسجام متن (واژه‌های رابط ≥۱ به ازای هر ۵ جمله)', $transitionRatio >= 0.2, 4,
            "نسبت واژه‌های رابط: " . round($transitionRatio, 2) . ' به ازای هر جمله');

        /* ---------- ❓ حضور سؤال و پاسخ (پشتیبان FAQ Schema) ---------- */
        $questionCount = preg_match_all('/[؟?]/u', $textOnly) ?: 0;
        $addCheck('question_presence', 'حضور پرسش در محتوا (پشتیبان Featured Snippet)', $questionCount >= 2, 3,
            "تعداد پرسش‌ها: {$questionCount}");

        /* ---------- 🧩 اسکیمای سازگار (JSON-LD) ---------- */
        $hasSchema = !empty($page['schema']) || !empty($page['has_schema']);
        $addCheck('schema_markup', 'داده ساختاریافته (Schema.org)', $hasSchema, 5,
            'برای صفحه Article یا FAQPage اسکیما اضافه کنید.');

        /* ---------- 🖼️ کفایت تصویر (۱ تصویر در هر ~۴۰۰ کلمه) ---------- */
        $idealImgs = max(1, (int)ceil($wordCount / 400));
        $addCheck('img_adequacy', 'کفایت تصاویر متناسب با حجم', $imgsTotal >= min($idealImgs, 3), 3,
            "برای {$wordCount} کلمه حدود {$idealImgs} تصویر مناسب است؛ فعلاً {$imgsTotal} تصویر دارد.");

        /* ---------- 🔗 تنوع متن انکر لینک‌ها ---------- */
        if ($linksCount >= 2) {
            preg_match_all('/<a[^>]*>(.*?)<\/a>/is', $content, $anchorMatches);
            $anchors = array_map(fn($a) => trim(strip_tags($a)), $anchorMatches[1] ?? []);
            $anchors = array_filter($anchors, fn($a) => $a !== '');
            $uniqueAnchors = count(array_unique($anchors));
            $addCheck('anchor_variety', 'تنوع متن انکر لینک‌ها', $uniqueAnchors === count($anchors), 3,
                'متن‌های انکر تکراری را متنوع کنید (همه لینک‌ها نباید یک متن داشته باشند).');
        }

        /* ---------- 🏅 سیگنال‌های E-E-A-T ---------- */
        $eeatSignals = 0;
        if (!empty($page['author'])) {
            $eeatSignals++;
        }
        if (!empty($page['date_published']) || !empty($page['datePublished'])) {
            $eeatSignals++;
        }
        if ($wordCount >= 600) {
            $eeatSignals++; // عمق محتوا خودش سیگنال تجربه است
        }
        if (preg_match_all('/[۰-۹0-9]+/u', $textOnly) >= 3) {
            $eeatSignals++; // داده عددی و مشخص
        }
        $addCheck('eeat_signals', 'سیگنال‌های E-E-A-T (نویسنده/تاریخ/داده)', $eeatSignals >= 3, 4,
            " {$eeatSignals} از ۴ سیگنال موجود است — نویسنده، تاریخ انتشار و آمار عددی اضافه کنید.");

        /* ---------- 🎯 تراکم بهینه واژه‌های ستونی (خوانایی کلیدی) ---------- */
        $readability = TextProcessor::readability($textOnly);
        $addCheck('readability_score', 'امتیاز خوانایی ≥۵۵ (متریک داخلی)', $readability['score'] >= 55, 3,
            'امتیاز خوانایی فعلی: ' . $readability['score']);

        // 🎯 امتیاز نهایی نرمال‌شده به ۱۰۰
        $finalScore = $totalWeight > 0 ? (int)round($score / $totalWeight * 100) : 0;
        $suggestions = $this->buildSuggestions($checks);

        return [
            'score'       => $finalScore,
            'grade'       => $this->grade($finalScore),
            'word_count'  => $wordCount,
            'checks'      => $checks,
            'suggestions' => $suggestions,
            // 🆕 متریک‌های نسخه ۲
            'readability' => $readability,
            'check_count' => count($checks),
            'passed_count'=> count(array_filter($checks, fn($c) => $c['passed'])),
        ];
    }

    /**
     * 🏆 تبدیل امتیاز به درجه
     */
    private function grade(int $score): string
    {
        if ($score >= 90) {
            return 'عالی 🎉';
        }
        if ($score >= 75) {
            return 'خوب ✅';
        }
        if ($score >= 50) {
            return 'متوسط ⚠️';
        }
        return 'نیازمند بهبود ❌';
    }

    /**
     * 💡 ساخت لیست پیشنهادهای عملی از بررسی‌های ناموفق
     */
    private function buildSuggestions(array $checks): array
    {
        $suggestions = [];
        // مرتب‌سازی بر اساس وزن (مهم‌ترین اول)
        usort($checks, function ($a, $b) {
            return $b['weight'] <=> $a['weight'];
        });
        foreach ($checks as $check) {
            if (!$check['passed']) {
                $suggestions[] = [
                    'title' => $check['label'],
                    'advice'=> $check['advice'] !== '' ? $check['advice'] : 'این مورد را اصلاح کنید تا امتیاز سئو افزایش یابد.',
                    'gain'  => $check['weight'],
                ];
            }
        }
        return $suggestions;
    }

    /**
     * 📋 چک‌لیست سئوی فنی کل سایت (برای داشبورد سئو)
     */
    public function technicalChecklist(array $context): array
    {
        return [
            ['sitemap_xml'   , 'تولید خودکار sitemap.xml', !empty($context['sitemap'])],
            ['robots_txt'    , 'فایل robots.txt', !empty($context['robots'])],
            ['ssl'           , 'HTTPS فعال', !empty($context['ssl'])],
            ['responsive'    , 'ریسپانسیو موبایل', true],
            ['lazy_loading'  , 'بارگذاری تنبل تصاویر', !empty($context['lazy'])],
            ['minified'      , 'فشرده‌سازی CSS/JS', !empty($context['minified'])],
            ['semantic'      , 'HTML معنایی', true],
            ['schema'        , 'Structured Data (Schema.org)', !empty($context['schema'])],
            ['canonical'     , 'Canonical URLs', !empty($context['canonical'])],
            ['404_custom'    , 'صفحه ۴۰۴ سفارشی', !empty($context['custom404'])],
            ['og_tags'       , 'Open Graph Tags', !empty($context['og'])],
            ['twitter_cards' , 'Twitter Cards', !empty($context['twitter'])],
        ];
    }
}
