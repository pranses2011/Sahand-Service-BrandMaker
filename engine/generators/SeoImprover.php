<?php
/**
 * 🎯 بهبودگر سئوی مقاله — SeoImprover v1.0 (فاز Q.6)
 * ==================================================
 * آمار کامل سئو زیر هر مقاله + اصلاح خودکار برای بالا بردن
 * امتیاز و رتبه: ۱۲ سنجه سئو + ۸ اصلاح خودکار.
 *
 * سنجه‌ها (analyze):
 *   ۱) عنوان سئو (طول ۴۵-۶۰ + کلیدواژه در ابتدا)
 *   ۲) متا توضیحات (طول ۱۲۰-۱۶۰ + کلیدواژه)
 *   ۳) تراکم کلیدواژه کانونی (۱-۲.۵٪)
 *   ۴) کلیدواژه در پاراگراف اول
 *   ۵) حجم محتوا (۷۰۰+ کلمه)
 *   ۶) ساختار هدینگ (H2/H3 + تعداد)
 *   ۷) لینک‌های داخلی (حداقل ۲)
 *   ۸) لینک خروجی معتبر (اختیاری — اطلاع‌رسانی)
 *   ۹) تصاویر با alt (نسبت alt دار)
 *   ۱۰) بخش سوالات متداول + اسکیما
 *   ۱۱) فهرست مطالب (TOC)
 *   ۱۲) نگارش فارسی (PersianGrammar ۰-۱۰۰)
 *
 * اصلاح‌ها (improve):
 *   ✓ اصلاح نگارشی کامل (نیم‌فاصله/سجاوندی/املای رایج/هم‌خوانی فعل و فاعل)
 *   ✓ تضمین کلیدواژه در پاراگراف اول
 *   ✓ بهینه‌سازی عنوان سئو با TitleGenerator
 *   ✓ بازنویسی متا توضیحات در بازه استاندارد
 *   ✓ افزودن لینک داخلی /services و /request
 *   ✓ افزودن alt به تصاویر بی‌alt
 *   ✓ افزودن بخش FAQ در صورت نبود
 *   ✓ افزودن TOC ساده در صورت نبود
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class SeoImprover
{
    /** @var SeoGenerator ژنراتور سئو */
    private $seoGen;

    public function __construct()
    {
        $this->seoGen = new SeoGenerator();
    }

    /* ==================================================
     * ۱) 📊 آمار کامل سئو
     * ================================================== */

    /**
     * 📊 تحلیل کامل سئوی مقاله — امتیاز کلی + ۱۲ سنجه + راهکارها
     *
     * @param array $article رکورد brand_articles (title, content, seo_title, seo_description, ...)
     * @return array ['score','grade','checks' => [['id','label','status','detail','fixable','weight']], 'focus_keyword', 'summary']
     */
    public function analyze(array $article): array
    {
        $title = (string)($article['title'] ?? '');
        $content = (string)($article['content'] ?? '');
        $seoTitle = (string)($article['seo_title'] ?? '');
        $seoDesc = (string)($article['seo_description'] ?? '');
        $focus = $this->focusKeyword($article);

        $text = trim(strip_tags($content));
        $wordCount = TextProcessor::wordCount($text);
        $checks = [];

        /* --- ۱) عنوان سئو --- */
        $tLen = mb_strlen($seoTitle !== '' ? $seoTitle : $title);
        $kwInTitle = $focus !== '' && mb_strpos($seoTitle !== '' ? $seoTitle : $title, $this->firstWords($focus, 2)) !== false;
        $titleOk = $tLen >= 45 && $tLen <= 60 && $kwInTitle;
        $checks[] = [
            'id' => 'seo_title', 'label' => 'عنوان سئو', 'weight' => 12,
            'status' => $titleOk ? 'pass' : ($tLen >= 35 && $tLen <= 70 ? 'warn' : 'fail'),
            'detail' => 'طول: ' . en_to_fa_digits((string)$tLen) . ' کاراکتر (استاندارد ۴۵-۶۰)' . ($kwInTitle ? ' · کلیدواژه موجود ✓' : ' · کلیدواژه غایب ✗'),
            'fixable' => true,
        ];

        /* --- ۲) متا توضیحات --- */
        $dLen = mb_strlen($seoDesc);
        $kwInDesc = $focus !== '' && $seoDesc !== '' && mb_strpos($seoDesc, $this->firstWords($focus, 2)) !== false;
        $descOk = $dLen >= 120 && $dLen <= 160 && $kwInDesc;
        $checks[] = [
            'id' => 'meta_description', 'label' => 'متا توضیحات', 'weight' => 10,
            'status' => $descOk ? 'pass' : ($dLen >= 80 && $dLen <= 180 ? 'warn' : 'fail'),
            'detail' => $seoDesc === '' ? 'مقداردهی نشده' : 'طول: ' . en_to_fa_digits((string)$dLen) . ' کاراکتر (استاندارد ۱۲۰-۱۶۰)' . ($kwInDesc ? ' · کلیدواژه موجود ✓' : ''),
            'fixable' => true,
        ];

        /* --- ۳) تراکم کلیدواژه --- */
        $density = $wordCount > 0 ? (mb_substr_count($text, $focus) / max(1, $wordCount)) * 100 : 0;
        if ($focus === '') {
            $density = 0;
        }
        $densityOk = $density >= 1.0 && $density <= 2.5;
        $checks[] = [
            'id' => 'keyword_density', 'label' => 'تراکم کلیدواژه', 'weight' => 12,
            'status' => $densityOk ? 'pass' : ($density > 0 && ($density < 1.0 && $density >= 0.4 || $density <= 4.0) ? 'warn' : 'fail'),
            'detail' => 'کلیدواژه: «' . $focus . '» — تراکم فعلی: ' . en_to_fa_digits(number_format($density, 1, '.', '')) . '٪ (استاندارد ۱ تا ۲.۵٪)',
            'fixable' => true,
        ];

        /* --- ۴) کلیدواژه در پاراگراف اول --- */
        $firstPara = '';
        if (preg_match('/<p[^>]*>(.*?)<\/p>/uis', $content, $pm)) {
            $firstPara = trim(strip_tags($pm[1]));
        }
        $kwEarly = $focus !== '' && mb_strpos(mb_substr($firstPara . ' ' . $text, 0, 600), $this->firstWords($focus, 2)) !== false;
        $checks[] = [
            'id' => 'keyword_early', 'label' => 'کلیدواژه در مقدمه', 'weight' => 8,
            'status' => $kwEarly ? 'pass' : 'fail',
            'detail' => $kwEarly ? 'کلیدواژه در ۶۰۰ نویسه نخست دیده می‌شود ✓' : 'کلیدواژه در مقدمه حضور ندارد — گوگل به ابتدای متن وزن بیشتری می‌دهد',
            'fixable' => true,
        ];

        /* --- ۵) حجم محتوا --- */
        $lenOk = $wordCount >= 900;
        $checks[] = [
            'id' => 'word_count', 'label' => 'حجم محتوا', 'weight' => 10,
            'status' => $lenOk ? 'pass' : ($wordCount >= 600 ? 'warn' : 'fail'),
            'detail' => en_to_fa_digits((string)$wordCount) . ' کلمه (پیشنهاد: ۹۰۰+ برای رقابت جدی)',
            'fixable' => false,
        ];

        /* --- ۶) ساختار هدینگ --- */
        preg_match_all('/<h2[^>]*>/i', $content, $h2);
        preg_match_all('/<h3[^>]*>/i', $content, $h3);
        $h2Count = count($h2[0] ?? []);
        $h3Count = count($h3[0] ?? []);
        $headOk = $h2Count >= 3;
        $checks[] = [
            'id' => 'headings', 'label' => 'ساختار هدینگ', 'weight' => 8,
            'status' => $headOk ? 'pass' : ($h2Count >= 1 ? 'warn' : 'fail'),
            'detail' => en_to_fa_digits((string)$h2Count) . ' عنوان H2 و ' . en_to_fa_digits((string)$h3Count) . ' عنوان H3' . ($headOk ? ' ✓' : ' — حداقل ۳ بخش H2 لازم است'),
            'fixable' => false,
        ];

        /* --- ۷) لینک داخلی --- */
        preg_match_all('/<a\s[^>]*href="([^"]*)"/iu', $content, $links);
        $internal = 0;
        foreach ($links[1] ?? [] as $href) {
            if (mb_strpos($href, '://') === false || mb_strpos($href, $_SERVER['HTTP_HOST'] ?? '') !== false) {
                $internal++;
            }
        }
        $linkOk = $internal >= 2;
        $checks[] = [
            'id' => 'internal_links', 'label' => 'لینک داخلی', 'weight' => 8,
            'status' => $linkOk ? 'pass' : ($internal === 1 ? 'warn' : 'fail'),
            'detail' => en_to_fa_digits((string)$internal) . ' لینک داخلی (پیشنهاد: حداقل ۲ — صفحه خدمات و ثبت درخواست)',
            'fixable' => true,
        ];

        /* --- ۸) تصاویر و alt --- */
        preg_match_all('/<img\s[^>]*>/iu', $content, $imgs);
        $imgTotal = count($imgs[0] ?? []);
        $imgWithAlt = 0;
        foreach ($imgs[0] ?? [] as $img) {
            if (preg_match('/alt="[^"]+"/iu', $img)) {
                $imgWithAlt++;
            }
        }
        $imgOk = $imgTotal === 0 || $imgWithAlt === $imgTotal;
        $checks[] = [
            'id' => 'image_alt', 'label' => 'تصاویر و alt', 'weight' => 6,
            'status' => $imgOk ? 'pass' : ($imgWithAlt > 0 ? 'warn' : 'fail'),
            'detail' => $imgTotal === 0 ? 'تصویری در متن نیست (تصویر شاخص جدا بررسی می‌شود)' : en_to_fa_digits((string)$imgWithAlt) . ' از ' . en_to_fa_digits((string)$imgTotal) . ' تصویر alt دارد',
            'fixable' => $imgTotal > $imgWithAlt,
        ];

        /* --- ۹) بخش سوالات متداول --- */
        $hasFaq = mb_strpos($content, 'سوالات متداول') !== false || mb_strpos($content, 'سؤالات متداول') !== false;
        $checks[] = [
            'id' => 'faq_section', 'label' => 'سوالات متداول', 'weight' => 8,
            'status' => $hasFaq ? 'pass' : 'fail',
            'detail' => $hasFaq ? 'بخش FAQ موجود ✓ (شایستگی برای ریچ‌ریزالت)' : 'بخش FAQ ندارد — FAQ اسکیمای ریچ‌ریزالت را ممکن می‌کند',
            'fixable' => true,
        ];

        /* --- ۱۰) فهرست مطالب --- */
        $hasToc = mb_strpos($content, 'article-toc') !== false || mb_strpos($content, 'فهرست مطالب') !== false;
        $checks[] = [
            'id' => 'toc', 'label' => 'فهرست مطالب', 'weight' => 4,
            'status' => $hasToc ? 'pass' : 'warn',
            'detail' => $hasToc ? 'TOC موجود ✓ (اسنیپت پرش به بخش در گوگل)' : 'TOC ندارد — برای مقالات بلند توصیه می‌شود',
            'fixable' => true,
        ];

        /* --- ۱۱) نگارش فارسی --- */
        $grammar = PersianGrammar::analyze($content);
        $checks[] = [
            'id' => 'grammar', 'label' => 'نگارش فارسی', 'weight' => 8,
            'status' => $grammar['score'] >= 85 ? 'pass' : ($grammar['score'] >= 60 ? 'warn' : 'fail'),
            'detail' => 'امتیاز نگارش: ' . en_to_fa_digits((string)$grammar['score']) . '/۱۰۰ (' . $grammar['grade'] . ') — ' . en_to_fa_digits((string)count($grammar['issues'])) . ' ایراد شناسایی شد',
            'fixable' => true,
        ];

        /* --- ۱۲) خوانایی --- */
        $readability = TextProcessor::readability($content);
        $readOk = ($readability['score'] ?? 0) >= 60;
        $checks[] = [
            'id' => 'readability', 'label' => 'خوانایی', 'weight' => 6,
            'status' => $readOk ? 'pass' : (($readability['score'] ?? 0) >= 45 ? 'warn' : 'fail'),
            'detail' => 'امتیاز: ' . en_to_fa_digits((string)($readability['score'] ?? 0)) . '/۱۰۰ — میانگین طول جمله: ' . en_to_fa_digits((string)($readability['avg_sentence_length'] ?? 0)) . ' کلمه',
            'fixable' => false,
        ];

        /* --- 🧮 امتیاز کلی وزنی --- */
        $totalWeight = 0;
        $earned = 0;
        foreach ($checks as $c) {
            $totalWeight += $c['weight'];
            if ($c['status'] === 'pass') {
                $earned += $c['weight'];
            } elseif ($c['status'] === 'warn') {
                $earned += $c['weight'] * 0.5;
            }
        }
        $score = (int)round($earned / max(1, $totalWeight) * 100);
        $grade = $score >= 85 ? 'عالی 🎉' : ($score >= 70 ? 'خوب ✅' : ($score >= 55 ? 'قابل قبول 🤔' : 'ضعیف ❌'));

        $fixable = array_values(array_filter($checks, fn($c) => $c['fixable'] && $c['status'] !== 'pass'));

        return [
            'score'         => $score,
            'grade'         => $grade,
            'focus_keyword' => $focus,
            'word_count'    => $wordCount,
            'checks'        => $checks,
            'fixable_count' => count($fixable),
            'fixable_labels'=> array_column($fixable, 'label'),
            'summary'       => en_to_fa_digits((string)count(array_filter($checks, fn($c) => $c['status'] === 'pass'))) . ' از ' . en_to_fa_digits((string)count($checks)) . ' سنجه سئو پاس شده — ' . en_to_fa_digits((string)count($fixable)) . ' مورد قابل اصلاح خودکار',
        ];
    }

    /* ==================================================
     * ۲) 🔧 اصلاح خودکار سئو
     * ================================================== */

    /**
     * 🔧 بهبود خودکار سئوی مقاله — همه ایرادهای قابل اصلاح را برطرف می‌کند
     *
     * @param array $article رکورد brand_articles
     * @return array ['content','seo_title','seo_description','seo_keywords','seo_score','applied' => [], 'before' => [], 'after' => []]
     */
    public function improve(array $article): array
    {
        $before = $this->analyze($article);

        $title = (string)($article['title'] ?? '');
        $content = (string)($article['content'] ?? '');
        $seoTitle = (string)($article['seo_title'] ?? '');
        $seoDesc = (string)($article['seo_description'] ?? '');
        $focus = $before['focus_keyword'];
        $applied = [];

        /* --- ۱) اصلاح نگارشی --- */
        $grammarFix = PersianGrammar::fix($content);
        if ($grammarFix['content'] !== $content) {
            $content = $grammarFix['content'];
            $applied[] = 'اصلاح نگارشی فارسی (' . en_to_fa_digits((string)$grammarFix['stats']['total_fixes']) . ' مورد: نیم‌فاصله، سجاوندی، املای رایج، هم‌خوانی فعل و فاعل)';
        }

        /* --- ۲) تضمین کلیدواژه در پاراگراف اول --- */
        if ($focus !== '' && !$this->hasEarlyKeyword($content, $focus)) {
            $kwSentence = '<p><strong>' . e($focus) . '</strong> موضوع اصلی این راهنماست؛ در ادامه همه نکات کلیدی، علل رایج و راه‌حل‌های عملی را به‌صورت گام‌به‌گام بررسی می‌کنیم.</p>' . "\n";
            // درج بعد از اولین پاراگراف موجود (یا ابتدای محتوا)
            $firstP = mb_strpos($content, '</p>');
            if ($firstP !== false) {
                $content = mb_substr($content, 0, $firstP + 4) . "\n" . $kwSentence . mb_substr($content, $firstP + 4);
            } else {
                $content = $kwSentence . $content;
            }
            $applied[] = 'افزودن کلیدواژه کانونی به مقدمه';
        }

        /* --- ۳) بهینه‌سازی عنوان سئو --- */
        $tLen = mb_strlen($seoTitle !== '' ? $seoTitle : $title);
        $kwInTitle = $focus !== '' && mb_strpos($seoTitle !== '' ? $seoTitle : $title, $this->firstWords($focus, 2)) !== false;
        if ($tLen < 45 || $tLen > 60 || !$kwInTitle) {
            $suggested = $this->optimizedSeoTitle($title, $focus);
            if ($suggested !== null && $suggested !== $seoTitle) {
                $seoTitle = $suggested;
                $applied[] = 'بازنویسی عنوان سئو در بازه ۴۵-۶۰ کاراکتر با کلیدواژه';
            }
        }

        /* --- ۴) بازنویسی متا توضیحات --- */
        $dLen = mb_strlen($seoDesc);
        $kwInDesc = $focus !== '' && $seoDesc !== '' && mb_strpos($seoDesc, $this->firstWords($focus, 2)) !== false;
        if ($dLen < 120 || $dLen > 160 || !$kwInDesc) {
            $newDesc = $this->optimizedMetaDescription($content, $focus, $title);
            if ($newDesc !== null && $newDesc !== $seoDesc) {
                $seoDesc = $newDesc;
                $applied[] = 'بازنویسی متا توضیحات در بازه ۱۲۰-۱۶۰ کاراکتر با کلیدواژه';
            }
        }

        /* --- ۵) افزودن لینک‌های داخلی --- */
        $linkResult = $this->ensureInternalLinks($content, $focus);
        $content = $linkResult['content'];
        if ($linkResult['added'] > 0) {
            $applied[] = 'افزودن ' . en_to_fa_digits((string)$linkResult['added']) . ' لینک داخلی (خدمات / ثبت درخواست)';
        }

        /* --- ۶) alt تصاویر --- */
        $altFixed = 0;
        $content = preg_replace_callback('/<img\s[^>]*>/iu', function ($m) use ($focus, &$altFixed) {
            $img = $m[0];
            if (!preg_match('/alt="[^"]*"/iu', $img)) {
                $altText = $focus !== '' ? $focus : 'تصویر مقاله';
                $altFixed++;
                return preg_replace('/\/?>$/u', ' alt="' . e($altText) . '">', $img) ?? $img;
            }
            return $img;
        }, $content) ?? $content;
        if ($altFixed > 0) {
            $applied[] = 'افزودن alt به ' . en_to_fa_digits((string)$altFixed) . ' تصویر';
        }

        /* --- ۷) افزودن بخش سوالات متداول --- */
        if (mb_strpos($content, 'سوالات متداول') === false && mb_strpos($content, 'سؤالات متداول') === false) {
            $faqs = $this->buildFaqs($focus, $title);
            if (!empty($faqs)) {
                $faqHtml = '<h2>سوالات متداول</h2>' . "\n";
                foreach ($faqs as $faq) {
                    $faqHtml .= '<h3>' . e($faq['question']) . '</h3>' . "\n" . '<p>' . e($faq['answer']) . '</p>' . "\n";
                }
                $content .= "\n" . $faqHtml;
                $applied[] = 'افزودن بخش سوالات متداول (' . en_to_fa_digits((string)count($faqs)) . ' پرسش)';
            }
        }

        /* --- ۸) افزودن TOC ساده --- */
        if (mb_strpos($content, 'article-toc') === false && mb_strpos($content, 'فهرست مطالب') === false) {
            $tocResult = $this->buildSimpleToc($content);
            if ($tocResult !== null) {
                $content = $tocResult['toc'] . $tocResult['content'];
                $applied[] = 'افزودن فهرست مطالب (TOC)';
            }
        }

        /* --- ۹) تنظیم تراکم کلیدواژه (bug: «بهینه است» با وجود ۱ مورد قابل‌اصلاح) ---
               این سنجه fixable=true بود اما هیچ اصلاح‌کننده‌ای نداشت؛ در نتیجه دکمه
               «(۱ مورد)» نشان می‌داد ولی بهبود می‌گفت «از قبل بهینه است». */
        $densityResult = $this->fixKeywordDensity($content, $focus);
        if ($densityResult['changed']) {
            $content = $densityResult['content'];
            $applied[] = $densityResult['message'];
        }

        /* --- سنجش مجدد --- */
        $improved = $article;
        $improved['content'] = $content;
        $improved['seo_title'] = $seoTitle;
        $improved['seo_description'] = $seoDesc;
        $after = $this->analyze($improved);

        // کلیدواژه‌های سئو — کلیدواژه کانونی + پرتکرارهای محتوا
        $seoKeywords = [];
        if ($focus !== '') {
            $seoKeywords[] = $focus;
        }
        try {
            $analyzer = new KeywordAnalyzer();
            // ✂️ حذف تگ‌های HTML قبل از استخراج کلیدواژه (وگرنه «</p>» و «href=» کلیدواژه می‌شوند!)
            foreach (array_slice($analyzer->extract(strip_tags($content), 8), 0, 6) as $kw) {
                $seoKeywords[] = $kw['keyword'];
            }
        } catch (Throwable $e) {
            // تحلیل کلیدواژه اختیاری است
        }
        $seoKeywords = array_values(array_unique(array_filter($seoKeywords)));

        return [
            'content'         => $content,
            'seo_title'       => $seoTitle,
            'seo_description' => $seoDesc,
            'seo_keywords'    => $seoKeywords,
            'seo_score'       => $after['score'],
            'applied'         => $applied,
            'before'          => [
                'score'   => $before['score'],
                'grade'   => $before['grade'],
                'summary' => $before['summary'],
            ],
            'after'           => [
                'score'   => $after['score'],
                'grade'   => $after['grade'],
                'summary' => $after['summary'],
                'checks'  => $after['checks'],
            ],
            'gain'            => $after['score'] - $before['score'],
        ];
    }

    /* ==================================================
     * 🛠️ متدهای داخلی
     * ================================================== */

    /**
     * 🎯 استخراج کلیدواژه کانونی مقاله
     */
    private function focusKeyword(array $article): string
    {
        $title = (string)($article['title'] ?? '');
        $clean = trim(preg_replace('/[؟?!؛،.:\[\]()«»-]+/u', ' ', $title) ?? $title);
        $fillers = ['چگونه', 'چطور', 'راهنمای کامل', 'آموزش کامل', 'راهنمای جامع', 'همه آنچه', 'همه چیز', 'بررسی'];
        foreach ($fillers as $f) {
            $clean = preg_replace('/(?<![\p{L}])' . preg_quote($f, '/') . '(?![\p{L}])/u', ' ', $clean) ?? $clean;
        }
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? $clean);
        $words = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // حذف اعداد از ابتدای کلیدواژه (۷ نکته ...)
        $words = array_values(array_filter($words, fn($w) => !preg_match('/^[0-9۰-۹]+$/', $w)));
        return implode(' ', array_slice($words, 0, 4));
    }

    /**
     * ✂️ دو واژه نخست کلیدواژه (برای جستجوی بخشی)
     */
    private function firstWords(string $focus, int $n): string
    {
        $words = preg_split('/\s+/u', trim($focus), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode(' ', array_slice($words, 0, $n));
    }

    /**
     * 🔎 آیا کلیدواژه در ابتدای متن هست؟
     */
    private function hasEarlyKeyword(string $content, string $focus): bool
    {
        $early = mb_substr(trim(strip_tags($content)), 0, 600);
        $kw = $this->firstWords($focus, 2);
        return $kw !== '' && mb_strpos($early, $kw) !== false;
    }

    /**
     * 🏷️ عنوان سئوی بهینه — از TitleGenerator
     */
    private function optimizedSeoTitle(string $title, string $focus): ?string
    {
        try {
            $titleGen = new TitleGenerator();
            $suggestions = $titleGen->suggestForCustom($title, [], 6);
            $best = $suggestions['suggestions'][0] ?? null;
            if ($best && mb_strlen($best['title']) >= 35) {
                $t = $best['title'];
                return mb_strlen($t) > 65 ? mb_substr($t, 0, 62) . '…' : $t;
            }
        } catch (Throwable $e) {
            // fallback
        }
        // ساخت دستی: عنوان + کلیدواژه اگر کم است
        if ($focus !== '' && mb_strlen($title) < 40) {
            return $title . ' | ' . $focus;
        }
        return mb_strlen($title) > 62 ? mb_substr($title, 0, 60) . '…' : ($title ?: null);
    }

    /**
     * 📝 متا توضیحات بهینه ۱۲۰-۱۶۰ کاراکتر
     */
    private function optimizedMetaDescription(string $content, string $focus, string $title): ?string
    {
        $text = trim(strip_tags($content));
        // اولین جمله‌های معنادار
        $sentences = TextProcessor::sentenceSplit($text);
        $desc = '';
        foreach ($sentences as $s) {
            $candidate = mb_trim($s, " \t\n\r،؛.");
            if (mb_strlen($candidate) < 25) {
                continue;
            }
            if ($desc !== '') {
                $desc .= ' ';
            }
            $desc .= $candidate;
            if (mb_strlen($desc) >= 125) {
                break;
            }
        }
        if ($desc === '') {
            $desc = $title . ' — راهنمای کامل و کاربردی';
        }
        // تضمین کلیدواژه
        $kw = $this->firstWords($focus, 2);
        if ($kw !== '' && mb_strpos($desc, $kw) === false) {
            $desc = $kw . ' — ' . $desc;
        }
        // برش به ۱۵۸
        if (mb_strlen($desc) > 160) {
            $desc = mb_substr($desc, 0, 157) . '…';
        }
        return $desc !== '' ? $desc : null;
    }

    /**
     * 🔗 تضمین لینک‌های داخلی خدمات و ثبت درخواست
     * @return array ['content' => string, 'added' => int]
     */
    private function ensureInternalLinks(string $content, string $focus): array
    {
        $added = 0;
        // لینک خدمات روی کلیدواژه (اولین رخداد)
        $kw = $this->firstWords($focus, 2);
        if ($kw !== '' && mb_strpos($content, 'href="/services"') === false) {
            $link = '<a href="/services">' . e($kw) . '</a>';
            $new = preg_replace('/(?<![\p{L}>])(' . preg_quote($kw, '/') . ')(?![\p{L}<])/u', $link, $content, 1, $count) ?? $content;
            if ($count > 0) {
                $content = $new;
                $added++;
            }
        }
        // لینک ثبت درخواست روی CTA
        if (mb_strpos($content, 'href="/request"') === false) {
            $ctaTargets = ['تماس با ما', 'تماس بگیرید', 'درخواست تعمیر', 'ثبت درخواست'];
            $done = false;
            foreach ($ctaTargets as $target) {
                if (mb_strpos($content, $target) !== false) {
                    $link = '<a href="/request">' . e($target) . '</a>';
                    $content = preg_replace('/(?<!>)(' . preg_quote($target, '/') . ')(?!<)/u', $link, $content, 1) ?? $content;
                    $added++;
                    $done = true;
                    break;
                }
            }
            if (!$done) {
                // افزودن CTA انتهایی
                $content .= "\n" . '<p>برای دریافت خدمات تخصصی، همین حالا <a href="/request">ثبت درخواست</a> کنید.</p>';
                $added++;
            }
        }
        return ['content' => $content, 'added' => $added];
    }

    /**
     * ❓ ساخت FAQ ساده از روی عنوان و کلیدواژه
     */
    private function buildFaqs(string $focus, string $title): array
    {
        $safeTitle = $title !== '' ? $title : ($focus !== '' ? $focus : 'این موضوع');
        return [
            ['question' => 'علت اصلی ' . $safeTitle . ' چیست؟', 'answer' => 'شایع‌ترین علت، شرایط بهره‌برداری و فرسودگی تدریجی قطعات مرتبط است؛ در این راهنما همه علل به‌ترتیب احتمال بررسی شده‌اند.'],
            ['question' => 'آیا ادامه استفاده از دستگاه در این حالت توصیه می‌شود؟', 'answer' => 'بستگی به شدت مشکل دارد؛ در موارد سبک ادامه کار ممکن است اما بررسی سریع‌تر، از خسارت ثانویه به قطعات دیگر جلوگیری می‌کند.'],
            ['question' => 'هزینه تعمیر چقدر است و چه مدت زمان می‌برد؟', 'answer' => 'پس از تشخیص دقیق علت، هزینه قطعه و اجرت به شما اعلام می‌شود؛ بیشتر تعمیرهای این حوزه در نخستین جلسه خدمت قابل انجام است.'],
            ['question' => 'برای رزرو تعمیرکار چه باید کرد؟', 'answer' => 'از طریق صفحه ثبت درخواست همین سایت، شماره تماس و نشانی خود را ثبت کنید تا کارشناس با شما تماس بگیرد.'],
        ];
    }

    /**
     * 🧲 تنظیم تراکم کلیدواژه در بازه استاندارد ۱ تا ۲.۵٪
     * کمبود → افزودن طبیعی کلیدواژه (جمع‌بندی سئو-پسند) / زیادی → جایگزینی بخشی با ضمیر
     * @return array ['changed' => bool, 'content' => string, 'message' => string]
     */
    private function fixKeywordDensity(string $content, string $focus): array
    {
        if ($focus === '' || trim(strip_tags($content)) === '') {
            return ['changed' => false, 'content' => $content, 'message' => ''];
        }
        $wordCount = TextProcessor::wordCount(trim(strip_tags($content)));
        if ($wordCount < 50) {
            return ['changed' => false, 'content' => $content, 'message' => ''];
        }
        $occurrences = mb_substr_count(trim(strip_tags($content)), $focus);
        $density = ($occurrences / max(1, $wordCount)) * 100;

        /* 🔽 تراکم کم → افزودن طبیعی کلیدواژه */
        if ($density < 1.0) {
            $target = (int)ceil(($wordCount * 1.3) / 100); // هدف ~۱.۳٪
            $need = max(1, $target - $occurrences);
            $need = min($need, 6);
            $kwSafe = e($focus);
            $summary = '<h2>جمع‌بندی: نکات کلیدی درباره ' . $kwSafe . '</h2>' . "\n";
            $summary .= '<p>در این راهنما، ' . $kwSafe . ' را از همه ابعاد بررسی کردیم: علل رایج، نشانه‌های هشداردهنده و راه‌حل‌های عملی که می‌توانید همین امروز اجرا کنید. تجربه نشان می‌دهد مراقبت به‌موقع درباره ' . $kwSafe . ' هزینه‌های تعمیر سنگین را تا حد زیادی پیشگیری می‌کند. اگر تازه با ' . $kwSafe . ' آشنا شده‌اید، پیشنهاد می‌کنیم راهنما را یک‌بار کامل بخوانید و بررسی‌های اولیه را بدون عجله انجام دهید.</p>' . "\n";
            $mentions = 4; // تعداد کلیدواژه در متن بالا
            if ($need > $mentions) {
                $summary .= '<p>نکته پایانی: برای ' . $kwSafe . ' همیشه از قطعات اصل و تکنسین مجاز استفاده کنید؛ راه‌حل‌های موقت معمولاً مشکل را پیچیده‌تر می‌کنند و ' . $kwSafe . ' نیاز به برخورد اصولی دارد.</p>' . "\n";
                $mentions += 2;
            }
            $content = rtrim($content) . "\n\n" . $summary;
            return [
                'changed' => true,
                'content' => $content,
                'message' => 'افزایش تراکم کلیدواژه به بازه استاندارد (بخش جمع‌بندی سئو-پسند افزوده شد)',
            ];
        }

        /* 🔼 تراکم زیاد (> ۲.۸٪) → کاهش طبیعی با ضمیر */
        if ($density > 2.8) {
            $text = trim(strip_tags($content));
            $occ = mb_substr_count($text, $focus);
            $target = (int)floor(($wordCount * 2.0) / 100);
            $toRemove = max(1, $occ - max(1, $target));
            $replacements = ['آن', 'این موضوع', 'این مورد', 'همین ایراد'];
            $ri = 0;
            $pos = 0;
            $count = 0;
            // از سومین مورد به بعد، هر دومین تکرار با ضمیر جایگزین می‌شود (حفظ ۲ مورد اول + مقدمه)
            $seen = 0;
            $search = $focus;
            $offset = 0;
            while ($count < $toRemove && ($offset = mb_strpos($content, $search, $offset)) !== false) {
                $seen++;
                if ($seen > 3 && $seen % 2 === 0) {
                    $rep = $replacements[$ri++ % count($replacements)];
                    $content = mb_substr($content, 0, $offset) . $rep . mb_substr($content, $offset + mb_strlen($search));
                    $count++;
                    $offset += mb_strlen($rep);
                } else {
                    $offset += mb_strlen($search);
                }
            }
            if ($count > 0) {
                return [
                    'changed' => true,
                    'content' => $content,
                    'message' => 'کاهش تراکم بیش‌ازحد کلیدواژه (' . en_to_fa_digits((string)$count) . ' تکرار با مترادف/ضمیر جایگزین شد — پرهیز از keyword stuffing)',
                ];
            }
        }

        return ['changed' => false, 'content' => $content, 'message' => ''];
    }

    /**
     * 🧭 ساخت TOC ساده از h2 های موجود
     * @return array|null ['toc' => string, 'content' => string] — null یعنی h2 کافی نیست
     */
    private function buildSimpleToc(string $content): ?array
    {
        preg_match_all('/<h2[^>]*>(.*?)<\/h2>/uis', $content, $h2);
        $titles = [];
        foreach ($h2[1] ?? [] as $i => $raw) {
            $t = trim(strip_tags($raw));
            if ($t !== '') {
                $titles[] = ['title' => $t, 'anchor' => 'sec-' . ($i + 1)];
            }
        }
        if (count($titles) < 3) {
            return null;
        }
        // افزودن id به h2 ها
        $idx = 0;
        $content = preg_replace_callback('/<h2([^>]*)>/ui', function ($m) use (&$idx) {
            $idx++;
            return '<h2 id="sec-' . $idx . '"' . $m[1] . '>';
        }, $content) ?? $content;

        $html = '<div class="article-toc"><div class="toc-title">📑 فهرست مطالب</div><ul>';
        foreach ($titles as $t) {
            $html .= '<li><a href="#' . e($t['anchor']) . '">' . e($t['title']) . '</a></li>';
        }
        $html .= '</ul></div>' . "\n";
        return ['toc' => $html, 'content' => $content];
    }
}
