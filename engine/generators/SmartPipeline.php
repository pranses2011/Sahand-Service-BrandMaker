<?php
/**
 * 🧠 خط تولید هوشمند — SmartPipeline v3.0
 * =========================================
 * پیشرفته‌ترین قابلیت موتور سهند: تولید کامل پکیج
 * محتوا با «یک فراخوانی» — از تشخیص نیت تا محتوای
 * نهایی امتیاز-تضمین‌شده.
 *
 * مراحل خط تولید:
 *   ۱️⃣ تشخیص نیت جستجو (IntentClassifier)
 *   ۲️⃣ انتخاب کلیدواژه کانونی (TF-IDF + رقابت‌پذیری)
 *   ۳️⃣ تولید مقاله بهترین-از-N (ArticleGenerator)
 *   ۴️⃣ بهبود خودکار تا رسیدن به امتیاز هدف (ContentImprover)
 *   ۵️⃣ انتخاب عنوان بهینه (TitleGenerator)
 *   ۶️⃣ پکیج سئو کامل (SeoGenerator — متا + اسکیما)
 *   ۷️⃣ سوالات متداول + FAQ Schema (FaqGenerator)
 *   ۸️⃣ راستی‌آزمایی یکتایی (UniquenessChecker)
 *
 * خروجی: مقاله + سئو + FAQ + گزارش کیفیت + ردِیابی
 *
 * @package SahandBrandMaker\Engine
 * @version 3.0.0
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
     *   target_score  => امتیاز هدف کیفیت (پیش‌فرض ۸۵),
     *   max_rounds    => حداکثر دور بهبود (۱-۵, پیش‌فرض ۳),
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
        $focusKeyword = $this->pickFocusKeyword($seedTopic, $brand, $deviceKey);
        $trace[] = $this->step('keywords', 'انتخاب کلیدواژه کانونی', $t, [
            'focus_keyword' => $focusKeyword,
        ]);

        /* ---------- 🌐 تحقیق آنلاین وب (اختیاری — پارامتر research) ---------- */
        $webResearch = null;
        if (!empty($params['research'])) {
            $t = microtime(true);
            try {
                $webResearch = (new WebSearchService())->research($focusKeyword, [
                    'fetch_pages' => !empty($params['research_fetch_pages']),
                    'limit'       => 6,
                ]);
                $trace[] = $this->step('research', 'تحقیق آنلاین وب', $t, [
                    'provider'   => $webResearch['provider'] ?? null,
                    'keywords'   => count($webResearch['keywords'] ?? []),
                    'questions'  => count($webResearch['questions'] ?? []),
                    'facts'      => count($webResearch['facts'] ?? []),
                    'sources'    => count($webResearch['sources'] ?? []),
                ]);
            } catch (Exception $e) {
                // شکست تحقیق نباید خط تولید را متوقف کند — مقاله با دانش داخلی ادامه می‌یابد
                $trace[] = $this->step('research', 'تحقیق آنلاین وب (ناموفق — ادامه با دانش داخلی)', $t, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        /* ---------- ۳️⃣ تولید مقاله بهترین-از-N ---------- */
        $t = microtime(true);
        $variants = (int)($params['variants'] ?? 3);
        $article = $this->ai->generateArticle([
            'brand_id'   => $brandId,
            'topic_type' => $topicType,
            'device_key' => $deviceKey,
            'variants'   => $variants,
        ]);
        $scoreInitial = $article['quality']['score'] ?? 0;
        $trace[] = $this->step('article', 'تولید مقاله (بهترین-از-' . $variants . ')', $t, [
            'score' => $scoreInitial,
            'words' => $article['word_count'] ?? 0,
        ]);

        /* ---------- ۴️⃣ بهبود خودکار ---------- */
        $t = microtime(true);
        $target = (int)($params['target_score'] ?? 85);
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

        /* ---------- ۵️⃣ عنوان بهینه ---------- */
        $t = microtime(true);
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

        /* ---------- ۶️⃣ پکیج سئو ---------- */
        $t = microtime(true);
        $seoGen = new SeoGenerator();
        $finalContent = $improveReport['content'];
        $vars = [
            'brand_fa'   => $brand['name_fa'],
            'agency'     => Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس',
            'city'       => 'تهران',
        ];
        $seo = $seoGen->generateForArticle($bestTitle, $finalContent, $focusKeyword, $vars);
        $trace[] = $this->step('seo', 'تولید پکیج سئو', $t, [
            'title_len' => mb_strlen($seo['title'] ?? ''),
        ]);

        /* ---------- ۷️⃣ سوالات متداول + اسکیما ---------- */
        $t = microtime(true);
        $faqItems = $this->buildFaqs($focusKeyword, $brand, $deviceKnowledge);
        // 🌐 غنی‌سازی FAQ با سؤالات واقعی کاربران از تحقیق وب
        if ($webResearch && !empty($webResearch['questions'])) {
            foreach (array_slice($webResearch['questions'], 0, 3) as $webQ) {
                $webQ = trim($webQ);
                if ($webQ === '' || mb_strlen($webQ) > 120) { continue; }
                $faqItems[] = [
                    'question' => $webQ,
                    'answer'   => 'بر اساس آخرین داده‌های وب، این موضوع یکی از دغدغه‌های رایج کاربران است. ' .
                                 'برای پاسخ دقیق متناسب با مدل و شرایط دستگاه خود، مشاوره رایگان کارشناسان سهند سرویس را دریافت کنید.',
                    'source'   => 'web_research',
                ];
            }
        }
        $faqSchema = $seoGen->faqSchema($faqItems);
        $trace[] = $this->step('faq', 'تولید سوالات متداول', $t, ['count' => count($faqItems)]);

        /* ---------- ۸️⃣ یکتایی ---------- */
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
            ],
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
