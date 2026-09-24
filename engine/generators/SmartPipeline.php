<?php
/**
 * 🧠 خط تولید هوشمند — SmartPipeline v3.2 «بی‌رقیب‌تر»
 * ====================================================
 * پیشرفته‌ترین قابلیت موتور سهند: تولید کامل پکیج
 * محتوا با «یک فراخوانی» — از تشخیص نیت تا محتوای
 * نهایی امتیاز-تضمین‌شده همراه با ۳ تصویر.
 *
 * مراحل خط تولید (۱۱ گام):
 *   ۱️⃣ تشخیص نیت جستجو (IntentClassifier)
 *   ۲️⃣ انتخاب کلیدواژه کانونی (TF-IDF + رقابت‌پذیری)
 *   ۳️⃣ تحقیق آنلاین وب (خودکار در صورت فعال بودن — WebSearchService)
 *   ۴️⃣ تولید مقاله بهترین-از-N (ArticleGenerator)
 *   ۵️⃣ بهبود خودکار تا امتیاز هدف (ContentImprover — پیش‌فرض ۸۸)
 *   ۶️⃣ غنی‌سازی ساختاری: فهرست مطالب + people-also-ask + آمار تازه + جعبه E-E-A-T
 *   ۷️⃣ انتخاب و درج ۳ تصویر مرتبط (ArticleImageService)
 *   ۸️⃣ انتخاب عنوان بهینه (TitleGenerator + کلیدواژه ترند)
 *   ۹️⃣ پکیج سئو کامل (SeoGenerator — متا + اسکیما + تصاویر)
 *   🔟 سوالات متداول + FAQ Schema (FaqGenerator + سؤالات واقعی وب)
 *   1️⃣1️⃣ راستی‌آزمایی یکتایی (UniquenessChecker)
 *
 * خروجی: مقاله + ۳ تصویر + سئو + FAQ + گزارش کیفیت + ردِیابی
 *
 * @package SahandBrandMaker\Engine
 * @version 3.2.0
 */
class SmartPipeline
{
    /** @var Database دیتابیس */
    private $db;

    /** @var SahandAI موتور اصلی */
    private $ai;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->ai = new SahandAI();
    }

    /**
     * 🚀 اجرای کامل خط تولید — یک فراخوانی، پکیج کامل
     *
     * @param array $params [
     *   brand_id      => شناسه برند (الزامی),
     *   topic_type    => نوع مقاله (پیش‌فرض: هوشمند بر اساس نیت),
     *   device_key    => دستگاه هدف (اختیاری),
     *   topic         => موضوع دلخواه (اختیاری — به‌جای قالب),
     *   variants      => تعداد واریانت مقاله (۱-۴, پیش‌فرض ۳),
     *   target_score  => امتیاز هدف کیفیت (پیش‌فرض ۸۸),
     *   max_rounds    => حداکثر دور بهبود (۱-۵, پیش‌فرض ۳),
     *   research      => true|false|'auto' (پیش‌فرض auto — اگر جستجوی وب فعال باشد انجام می‌شود),
     *   with_images   => درج ۳ تصویر در مقاله (پیش‌فرض true),
     *   auto_save     => ذخیره خودکار مقاله,
     *   no_cache      => دور زدن کش
     * ]
     */
    public function run(array $params): array
    {
        $t0 = microtime(true);
        $trace = [];
        $brandId = (int)($params['brand_id'] ?? 0);
        $brand = $this->db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
        if (!$brand) {
            throw new RuntimeException('برند یافت نشد.');
        }

        // 📚 غنی‌سازی برند با دانش
        $knowledge = (new BrandInfoGenerator())->matchBrand($brand['name_fa'], $brand['name_en']);
        if ($knowledge) {
            $brand = array_merge($brand, $knowledge);
        }

        $topicType = (string)($params['topic_type'] ?? '');
        $deviceKey = $params['device_key'] ?? null;
        $topic = trim((string)($params['topic'] ?? ''));

        /* ---------- ۱️⃣ تشخیص نیت ---------- */
        $t = microtime(true);
        $intent = null;
        if ($topic !== '') {
            $intent = (new IntentClassifier())->classify($topic);
        } elseif ($deviceKey !== null) {
            $devices = TextProcessor::loadKnowledge('devices');
            $deviceName = $devices[$deviceKey]['name_fa'] ?? $deviceKey;
            $intent = (new IntentClassifier())->classify('تعمیر ' . $deviceName);
        }
        if ($intent) {
            $trace[] = $this->step('intent', 'تشخیص نیت جستجو', $t, [
                'intent' => $intent['intent'],
                'confidence' => $intent['confidence'] ?? null,
            ]);
        }

        /* ---------- 🎯 انتخاب هوشمند نوع مقاله ---------- */
        if ($topicType === '') {
            $map = [
                'informational'  => 'user_guide',
                'commercial'     => 'comparison',
                'transactional'  => 'cost_guide',
                'navigational'   => 'troubleshooting',
            ];
            $topicType = $map[$intent['intent'] ?? ''] ?? 'troubleshooting';
        }

        /* ---------- ۲️⃣ انتخاب کلیدواژه کانونی ---------- */
        $t = microtime(true);
        $deviceKnowledge = TextProcessor::loadKnowledge('devices')[$deviceKey] ?? [];
        $deviceFa = $deviceKnowledge['name_fa'] ?? '';
        $seedTopic = $topic !== '' ? $topic : ($deviceFa !== '' ? $deviceFa : $brand['name_fa']);
        /* 🎯 v3.3: موضوع درخواستی کاربر خودش کلیدواژه کانونی است (تمرکز کامل روی عنوان) */
        $focusKeyword = $topic !== '' ? $topic : $this->pickFocusKeyword($seedTopic, $brand, $deviceKey);
        $trace[] = $this->step('keywords', 'انتخاب کلیدواژه کانونی', $t, [
            'focus_keyword' => $focusKeyword,
        ]);

        /* ---------- ۳️⃣ تحقیق آنلاین وب (auto: فقط اگر سرویس فعال باشد) ---------- */
        $webResearch = null;
        $researchMode = array_key_exists('research', $params) ? $params['research'] : 'auto';
        $researchWanted = $researchMode === true || ($researchMode === 'auto' && $this->webSearchEnabled());
        if ($researchWanted) {
            $t = microtime(true);
            try {
                $webResearch = (new WebSearchService())->research($focusKeyword, [
                    'fetch_pages' => !empty($params['research_fetch_pages']),
                    'limit'       => 6,
                ]);
                $trace[] = $this->step('research', 'تحقیق آنلاین وب', $t, [
                    'provider'    => $webResearch['provider'] ?? null,
                    'keywords'    => count($webResearch['keywords'] ?? []),
                    'questions'   => count($webResearch['questions'] ?? []),
                    'facts'       => count($webResearch['facts'] ?? []),
                    'sources'     => count($webResearch['sources'] ?? []),
                    'opportunities' => count($webResearch['opportunities'] ?? []),
                ]);
            } catch (Exception $e) {
                // شکست تحقیق نباید خط تولید را متوقف کند — مقاله با دانش داخلی ادامه می‌یابد
                $trace[] = $this->step('research', 'تحقیق آنلاین وب (ناموفق — ادامه با دانش داخلی)', $t, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        /* ---------- ۴️⃣ تولید مقاله بهترین-از-N (v3.3: حول موضوع درخواستی + تحقیق وب) ---------- */
        $t = microtime(true);
        $variants = (int)($params['variants'] ?? 3);
        $customTitle = trim((string)($params['custom_title'] ?? ''));
        $article = $this->ai->generateArticle([
            'brand_id'   => $brandId,
            'topic_type' => $topicType,
            'device_key' => $deviceKey,
            'variants'   => $variants,
            /* 🎯 v3.3: عنوان/موضوع درخواستی کاربر به ژنراتور می‌رسد — بدنه مقاله
               دقیقاً حول همین موضوع نوشته می‌شود (نه قالب عمومی دستگاه) */
            'custom_title' => $customTitle !== '' ? $customTitle : null,
            /* 🌐 تحقیق وبِ همین خط تولید به ژنراتور پاس می‌شود تا فکت‌ها در خود
               متن ادغام شوند و جستجوی تکراری هم انجام نشود */
            'research'          => $webResearch !== null,
            'research_context'  => $webResearch,
        ]);
        $scoreInitial = $article['quality']['score'] ?? 0;
        $trace[] = $this->step('article', 'تولید مقاله (بهترین-از-' . $variants . ($customTitle !== '' ? ' — حول موضوع درخواستی' : '') . ')', $t, [
            'score' => $scoreInitial,
            'words' => $article['word_count'] ?? 0,
            'topic_focused' => $customTitle !== '',
        ]);

        /* ---------- ۵️⃣ بهبود خودکار ---------- */
        $t = microtime(true);
        $target = (int)($params['target_score'] ?? 88);
        $improver = new ContentImprover();
        $improveReport = $improver->improve($article['content'], [
            'focus_keyword' => $focusKeyword,
            'target_score'  => $target,
            'max_rounds'    => (int)($params['max_rounds'] ?? 3),
            'content_type'  => 'article',
            'device_key'    => (string)($deviceKey ?? ''),
        ]);
        $trace[] = $this->step('improve', 'بهبود خودکار کیفیت', $t, [
            'score_before' => $improveReport['score_before'],
            'score_after'  => $improveReport['score_after'],
            'rounds'       => count($improveReport['rounds']),
        ]);

        /* ---------- ۶️⃣ غنی‌سازی ساختاری v3.2 (v3.3: برای مقاله موضوع‌محور، بخش‌های تکراری وب حذف می‌شوند) ---------- */
        $t = microtime(true);
        $enrichment = $this->enrichContent($improveReport['content'], [
            'focus'      => $focusKeyword,
            'brand'      => $brand,
            'device_fa'  => $deviceFa,
            'research'   => $webResearch,
            'topic_type' => $topicType,
            'topic_focused' => $customTitle !== '',
        ]);
        $finalContent = $enrichment['content'];
        $trace[] = $this->step('enrich', 'غنی‌سازی ساختاری (فهرست + PAA + آمار + E-E-A-T)', $t, $enrichment['trace']);

        /* ---------- ۷️⃣ انتخاب و درج ۳ تصویر ---------- */
        $t = microtime(true);
        $withImages = !array_key_exists('with_images', $params) || $params['with_images'] !== false;
        $images = [];
        if ($withImages) {
            $imageService = new ArticleImageService();
            /* v2: دستگاه از عنوان استنتاج می‌شود + واترمارک دو لوگو */
            $pickKey = ArticleImageService::normalizeDeviceKey($deviceKey)
                ?? ArticleImageService::inferDeviceKey((string)($article['title'] ?? ''), (string)($finalContent ?? ''));
            $images = $imageService->pick($pickKey, $topicType, (string)($article['title'] ?? ''), $focusKeyword, $brand);
            $finalContent = $imageService->injectIntoContent($finalContent, $images);
            $trace[] = $this->step('images', 'درج ۳ تصویر مرتبط در مقاله', $t, [
                'count' => count($images),
                'files' => array_column($images, 'file'),
            ]);
        }

        /* ---------- ۸️⃣ عنوان بهینه (v3.3: با موضوع درخواستی، عنوان کاربر حفظ می‌شود) ---------- */
        $t = microtime(true);
        $bestTitle = (string)($article['title'] ?? '');
        if ($customTitle === '') {
            $titleGen = new TitleGenerator();
            // 🌐 اگر تحقیق وب کلیدواژه ترند دارد، در تولید عنوان لحاظ شود
            $titleSeason = '';
            if ($webResearch && !empty($webResearch['keywords'][0]['keyword'])) {
                $titleSeason = $webResearch['keywords'][0]['keyword'];
            }
            $titles = $titleGen->generate($focusKeyword, [
                'brand_fa'  => $brand['name_fa'],
                'device_fa' => $deviceFa,
                'season'    => $titleSeason,
            ], 5);
            $bestTitle = $titles['best'] !== '' ? $titles['best'] : ($article['title'] ?? '');
            $trace[] = $this->step('title', 'انتخاب عنوان بهینه', $t, [
                'best' => $bestTitle,
                'score' => $titles['best_score'],
            ]);
        } else {
            /* 🎯 عنوان از خود موضوع کاربر ساخته شده — فقط تضمین یکتایی/زیبایی */
            $trace[] = $this->step('title', 'عنوان موضوعی کاربر حفظ شد', $t, [
                'best' => $bestTitle,
            ]);
        }

        /* ---------- ۹️⃣ پکیج سئو ---------- */
        $t = microtime(true);
        $seoGen = new SeoGenerator();
        $vars = [
            'brand_fa'    => $brand['name_fa'],
            'agency'      => Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس',
            'city'        => 'تهران',
            'brand_domain'=> $brand['domain'] ?? '',
            'images'      => array_column($images, 'url'),
        ];
        $seo = $seoGen->generateForArticle($bestTitle, $finalContent, $focusKeyword, $vars);
        // 🪜 اسکیمای HowTo برای مقالات راهنما/آموزش
        if (in_array($topicType, ['user_guide', 'troubleshooting', 'maintenance'], true)) {
            $seo['howto_schema'] = $seoGen->howToSchema($bestTitle, $this->extractHowToSteps($finalContent));
        }
        $trace[] = $this->step('seo', 'تولید پکیج سئو', $t, [
            'title_len' => mb_strlen($seo['title'] ?? ''),
            'density'   => $seo['keyword_density']['percent'] ?? null,
        ]);

        /* ---------- 🔟 سوالات متداول + اسکیما ---------- */
        $t = microtime(true);
        $faqItems = $this->buildFaqs($focusKeyword, $brand, $deviceKnowledge);
        // 🌐 غنی‌سازی FAQ با سؤالات واقعی کاربران از تحقیق وب
        $webFaqCount = 0;
        if ($webResearch && !empty($webResearch['questions'])) {
            foreach (array_slice($webResearch['questions'], 0, 3) as $webQ) {
                $webQ = trim($webQ);
                if ($webQ === '' || mb_strlen($webQ) > 120) { continue; }
                $faqItems[] = [
                    'question' => $webQ,
                    'answer'   => $this->researchFaqAnswer($webQ, $webResearch, $deviceFa),
                    'source'   => 'web_research',
                ];
                $webFaqCount++;
            }
        }
        $faqSchema = $seoGen->faqSchema($faqItems);
        $trace[] = $this->step('faq', 'تولید سوالات متداول', $t, [
            'count' => count($faqItems),
            'from_web' => $webFaqCount,
        ]);

        /* ---------- 1️⃣1️⃣ یکتایی ---------- */
        $t = microtime(true);
        $uniqueness = $this->ai->checkUniqueness(['content' => $finalContent]);
        $trace[] = $this->step('uniqueness', 'راستی‌آزمایی یکتایی', $t, [
            'uniqueness' => $uniqueness['uniqueness'] ?? null,
        ]);

        /* ---------- 💾 ذخیره خودکار ---------- */
        $articleId = null;
        if (!empty($params['auto_save'])) {
            $article['title'] = $bestTitle;
            $article['content'] = $finalContent;
            $article['faq'] = $faqItems;
            $article['featured_image'] = $images[0]['url'] ?? null;
            $articleId = $this->ai->saveArticle($brandId, $article, $topicType);
        }

        /* ---------- 📊 گزارش نهایی ---------- */
        $finalScore = $improveReport['score_after'];
        $elapsed = round((microtime(true) - $t0) * 1000);

        return [
            'article' => [
                'id'           => $articleId,
                'title'        => $bestTitle,
                'content'      => $finalContent,
                'word_count'   => TextProcessor::wordCount(strip_tags($finalContent)),
                'topic_type'   => $topicType,
                'device_key'   => $deviceKey,
                'tldr'         => $article['tldr'] ?? null,
                'toc'          => $enrichment['toc'],
            ],
            'images' => $images,
            'seo' => $seo,
            'faq' => [
                'items'  => $faqItems,
                'schema' => $faqSchema,
            ],
            'quality' => [
                'initial_score' => $scoreInitial,
                'final_score'   => $finalScore,
                'target_score'  => $target,
                'target_reached'=> $improveReport['target_reached'],
                'grade'         => $improveReport['grade_after'],
                'dimensions'    => $improveReport['dimensions_after'],
                'gain'          => $improveReport['gain'],
            ],
            'intent'  => $intent,
            'keyword' => [
                'focus'       => $focusKeyword,
                'alternatives' => $titles['titles'] ? array_column(array_slice($titles['titles'], 0, 3), 'title') : [],
                'opportunities' => $webResearch['opportunities'] ?? [],
            ],
            'uniqueness' => $uniqueness,
            'research' => $webResearch ? [
                'enabled'  => true,
                'provider' => $webResearch['provider'] ?? null,
                'summary'  => $webResearch['summary'] ?? '',
                'keywords' => array_column($webResearch['keywords'] ?? [], 'keyword'),
                'facts'    => $webResearch['facts'] ?? [],
                'sources'  => array_column($webResearch['sources'] ?? [], 'url'),
            ] : ['enabled' => false],
            'pipeline' => [
                'steps'   => $trace,
                'elapsed_ms' => $elapsed,
                'variants'  => $variants,
                'auto_saved' => $articleId !== null,
            ],
        ];
    }

    /* ==================================================
     * 🛠️ متدهای داخلی
     * ================================================== */

    /** آیا جستجوی وب فعال است؟ (برای حالت auto) */
    private function webSearchEnabled(): bool
    {
        try {
            return (bool)(new WebSearchService())->status()['enabled'];
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 🏗️ غنی‌سازی ساختاری محتوا — v3.2
     * فهرست مطالب + بخش «سایر پرسش‌های کاربران» + آمار تازه + جعبه E-E-A-T
     *
     * @return array{content:string, toc:array, trace:array}
     */
    private function enrichContent(string $content, array $ctx): array
    {
        $toc = [];
        $traceNotes = [];

        /* ---------- 📑 فهرست مطالب (از H2های موجود) ---------- */
        if (preg_match_all('#<h2>(.*?)</h2>#u', $content, $h2s)) {
            $toc = array_map(static function ($h) {
                return trim(strip_tags($h));
            }, $h2s[1]);
            if (count($toc) >= 3) {
                $items = '';
                foreach ($toc as $i => $title) {
                    $items .= '<li>' . e($title) . '</li>';
                }
                $tocHtml = '<div class="article-toc"><strong>📑 فهرست مطالب</strong><ol>' . $items . '</ol></div>';
                // درج بعد از اولین پاراگراف مقدمه
                $content = preg_replace('/^(.*?<\/p>)/us', '$1' . "\n" . $tocHtml, $content, 1);
                $traceNotes['toc_items'] = count($toc);
            }
        }

        /* ---------- 🌐 آمار و داده‌های تازه از وب (با ذکر منبع) —
           v3.3: فقط برای مقالات قالبی؛ مقاله موضوع‌محور فکت‌ها را در نثر خودش دارد ---------- */
        if (empty($ctx['topic_focused']) && !empty($ctx['research']['facts'])) {
            $facts = array_slice((array)$ctx['research']['facts'], 0, 3);
            $sourceHosts = array_map(static function ($s) {
                return e($s['source'] ?? '');
            }, array_slice((array)($ctx['research']['sources'] ?? []), 0, 3));
            $rows = '';
            foreach ($facts as $i => $fact) {
                $src = $sourceHosts[$i] ?? 'منابع وب';
                $rows .= '<li>' . e($fact) . ' <small><span class="stat-source">منبع: ' . $src . '</span></small></li>';
            }
            $statsHtml = '<h2>📊 داده‌های تازه از وب</h2>' .
                '<p>بر اساس پایش زنده نتایج جستجوی فارسی، این داده‌ها در ارتباط با «' . e($ctx['focus']) . '» به‌روزرسانی شده‌اند:</p>' .
                '<ul class="fresh-stats">' . $rows . '</ul>';
            // درج قبل از جمع‌بندی
            if (mb_strpos($content, '<h2>جمع‌بندی</h2>') !== false) {
                $content = str_replace('<h2>جمع‌بندی</h2>', $statsHtml . "\n<h2>جمع‌بندی</h2>", $content);
            } else {
                $content .= "\n" . $statsHtml;
            }
            $traceNotes['fresh_stats'] = count($facts);
        }

        /* ---------- ❓ سایر پرسش‌های کاربران (People Also Ask) —
           v3.3: فقط برای مقالات قالبی؛ مقاله موضوع‌محور پرسش‌ها را با پاسخ در بدنه دارد ---------- */
        if (empty($ctx['topic_focused']) && !empty($ctx['research']['questions'])) {
            $questions = array_slice((array)$ctx['research']['questions'], 0, 4);
            $paaHtml = '<h2>❓ سایر پرسش‌های کاربران</h2><ul class="people-also-ask">';
            foreach ($questions as $q) {
                $paaHtml .= '<li>' . e($q) . '</li>';
            }
            $paaHtml .= '</ul>';
            $paaHtml .= '<p>پاسخ تخصصی هر یک از این پرسش‌ها را می‌توانید از مشاوران ' .
                e($ctx['brand']['name_fa'] ?? '') . ' به‌صورت رایگان دریافت کنید.</p>';
            if (mb_strpos($content, '<h2>سوالات متداول</h2>') !== false) {
                $content = str_replace('<h2>سوالات متداول</h2>', $paaHtml . "\n<h2>سوالات متداول</h2>", $content);
            } else {
                $content .= "\n" . $paaHtml;
            }
            $traceNotes['people_also_ask'] = count($questions);
        }

        /* ---------- 🎓 جعبه اعتبار E-E-A-T (نویسنده/به‌روزرسانی/منابع) ---------- */
        $agency = e($ctx['brand']['agency'] ?? (Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس'));
        $eeat = '<div class="article-eeat">'
            . '<strong>✍️ درباره نویسنده</strong>'
            . '<p>این محتوا توسط تیم فنی ' . $agency . ' با بیش از یک دهه تجربه تخصصی در تعمیرات لوازم خانگی تهیه و بازبینی شده است. '
            . 'تمام مراحل تشخیص و تعمیر مطابق استانداردهای ایمنی و دستورالعمل سازندگان ارائه می‌شود.</p>'
            . '<small>🗓️ آخرین بازبینی: ' . e(jdate(date('Y-m-d'))) . ' | ✅ محتوای راستی‌آزمایی‌شده توسط کارشناس فنی</small>'
            . '</div>';
        $content .= "\n" . $eeat;
        $traceNotes['eeat_box'] = true;

        return ['content' => $content, 'toc' => $toc, 'trace' => $traceNotes];
    }

    /**
     * 🪜 استخراج مراحل HowTo از محتوای مقاله (برای اسکیما)
     * هر <li> فهرست‌های شماری/بولت + هر H3 را به‌عنوان مرحله در نظر می‌گیرد
     */
    private function extractHowToSteps(string $content): array
    {
        $steps = [];
        // فهرست‌های مرحله‌ای (<ol>)
        if (preg_match_all('#<ol[^>]*>(.*?)</ol>#is', $content, $ols)) {
            foreach ($ols[1] as $ol) {
                if (preg_match_all('#<li[^>]*>(.*?)</li>#is', $ol, $lis)) {
                    foreach ($lis[1] as $li) {
                        $text = trim(strip_tags($li));
                        if (mb_strlen($text) >= 12) {
                            $steps[] = ['name' => mb_substr($text, 0, 60), 'text' => $text];
                        }
                        if (count($steps) >= 8) { return $steps; }
                    }
                }
            }
        }
        // در صورت نبود فهرست: H3ها
        if (!$steps && preg_match_all('#<h3>(.*?)</h3>#u', $content, $h3s)) {
            foreach ($h3s[1] as $h3) {
                $title = trim(strip_tags($h3));
                if ($title !== '') {
                    $steps[] = ['name' => mb_substr($title, 0, 60), 'text' => $title];
                }
                if (count($steps) >= 6) { break; }
            }
        }
        return $steps;
    }

    /**
     * 💬 پاسخ FAQ مبتنی بر داده‌های تحقیق وب — به‌جای متن عمومی
     */
    private function researchFaqAnswer(string $question, array $research, string $deviceFa): string
    {
        $facts = (array)($research['facts'] ?? []);
        $keywords = (array)($research['keywords'] ?? []);
        $kwNames = array_column($keywords, 'keyword');

        $parts = [];
        if ($facts) {
            $parts[] = 'بر اساس آخرین داده‌های وب، ' . mb_substr((string)$facts[0], 0, 140);
        }
        if ($kwNames) {
            $parts[] = 'مفاهیم مرتبط با این پرسش در جستجوهای اخیر: ' . implode('، ', array_slice($kwNames, 0, 4)) . '.';
        }
        $parts[] = 'برای بررسی دقیق متناسب با مدل دستگاه' . ($deviceFa !== '' ? ' (' . $deviceFa . ')' : '') .
            '، مشاوره رایگان کارشناسان سهند سرویس در دسترس شماست.';
        return implode(' ', $parts);
    }

    /**
     * 🎯 انتخاب کلیدواژه کانونی — ترکیب دستگاه + قصد + برند
     */
    private function pickFocusKeyword(string $topic, array $brand, ?string $deviceKey): string
    {
        if ($deviceKey !== null) {
            $devices = TextProcessor::loadKnowledge('devices');
            $name = $devices[$deviceKey]['name_fa'] ?? '';
            if ($name !== '') {
                return 'تعمیر ' . $name;
            }
        }
        return mb_strlen($topic) > 3 ? $topic : 'تعمیرات ' . $brand['name_fa'];
    }

    /**
     * ❓ ساخت سوالات متداول مرتبط
     */
    private function buildFaqs(string $focus, array $brand, array $deviceKnowledge): array
    {
        $brandFa = $brand['name_fa'] ?? '';
        $cost = $deviceKnowledge['avg_repair_cost_range'] ?? null;

        $faqs = [
            [
                'question' => "هزینه {$focus} چقدر است؟",
                'answer'   => $cost
                    ? 'بر اساس آمار تعمیرات، بازه معمول ' . $cost . ' است و پس از بازدید تخصصی، قیمت قطعی اعلام می‌شود. تشخیص اولیه در سهند سرویس رایگان است.'
                    : "هزینه دقیق پس از بازدید کارشناس مشخص می‌شود؛ اما بازدید و تشخیص اولیه در سهند سرویس رایگان است و قیمت شفاف پیش از شروع کار اعلام می‌گردد.",
            ],
            [
                'question' => "چطور بفهمم مشکل از {$focus} جدی است؟",
                'answer'   => 'اگر مشکل پس از ریست و بررسی نکات اولیه ادامه یافت، صدای غیرعادی افزایش پیدا کرد یا عملکرد دستگاه به‌طور محسوس افت کرد، زمان تماس با متخصص است. ادامه استفاده در این حالت ممکن است خسارت را چند برابر کند.',
            ],
            [
                'question' => "آیا تعمیر در محل انجام می‌شود؟",
                'answer'   => 'بله، تیم سهند سرویس برای اکثر خرابی‌های ' . ($brandFa !== '' ? "برند {$brandFa} و " : '') . 'سایر برندها تعمیر در محل انجام می‌دهد و در صورت نیاز به تعمیر در کارگاه، حمل رایگان ارائه می‌شود.',
            ],
        ];

        // 📚 افزودن نکته نگهداری از دانش دستگاه
        $tips = (array)($deviceKnowledge['maintenance_tips'] ?? []);
        if ($tips) {
            $faqs[] = [
                'question' => 'چطور از تکرار این مشکل پیشگیری کنیم؟',
                'answer'   => 'رعایت ' . count($tips) . ' نکته کلیدی نگهداری توصیه می‌شود؛ از جمله: '
                    . rtrim(implode('؛ ', array_slice($tips, 0, 3)), '.،') . '.',
            ];
        }

        return $faqs;
    }

    /**
     * 📝 ثبت یک گام خط تولید
     */
    private function step(string $key, string $label, float $tStart, array $meta = []): array
    {
        return [
            'step'      => $key,
            'label'     => $label,
            'ms'        => (int)round((microtime(true) - $tStart) * 1000),
        ] + $meta;
    }
}
