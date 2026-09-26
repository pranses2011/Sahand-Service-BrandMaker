<?php
/**
 * 📰 ژنراتور مقالات — نسخه ۲.۲ (چندواریانته + نگارش‌گر فارسی)
 * =========================================================
 * ساختار: انتخاب موضوع ← ساخت Outline ← نوشتن بخش‌ها ←
 * TL;DR ← لینک‌دهی داخلی ← بررسی یکتایی ← اصلاح نگارشی ←
 * سئو + FAQ Schema ← تولید N واریانت و انتخاب بهترین
 *
 * @package SahandBrandMaker\Engine
 * @version 2.2.0
 */
class ArticleGenerator
{
    /** @var Database دیتابیس */
    private $db;

    /** @var UniquenessChecker بررسی یکتایی */
    private $uniqueness;

    /** @var QualityScorer امتیازده کیفیت (نسخه ۲) */
    private $scorer;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->uniqueness = new UniquenessChecker();
        $this->scorer = new QualityScorer();
    }

    /**
     * 📰 تولید یک مقاله کامل یکتا برای برند — نسخه ۲.۳
     *
     * @param array  $brand اطلاعات برند (id, name_fa, name_en, ...)
     * @param string $topicType نوع مقاله (۲۵ نوع فاز Q.4)
     * @param string|null $deviceKey کلید دستگاه هدف (اختیاری — تصادفی انتخاب می‌شود)
     * @param int   $variants تعداد واریانت تولیدی برای انتخاب بهترین (۱ تا ۴ — نسخه ۲)
     * @param string|null $customTitle عنوان دلخواه کاربر (فاز Q.5 — مقاله حول همین عنوان نوشته می‌شود)
     * @param array $options فاز Q.7/Q.8: ['research' => bool (جستجوی آنلاین), 'with_images' => bool (تصاویر خودکار)]
     * @return array مقاله کامل ['title','slug','content','excerpt','seo','quality',...]
     */
    public function generate(array $brand, string $topicType, ?string $deviceKey = null, int $variants = 2, ?string $customTitle = null, array $options = []): array
    {
        $variants = max(1, min(4, $variants));
        $best = null;

        // 🎰 تولید N واریانت با seed های متفاوت و انتخاب بهترین امتیاز کیفیت
        for ($v = 0; $v < $variants; $v++) {
            $candidate = $this->generateSingle($brand, $topicType, $deviceKey, $v, $customTitle, $options);
            if ($best === null || $candidate['quality']['score'] > $best['quality']['score']) {
                $best = $candidate;
            }
        }

        $best['variants_generated'] = $variants;
        return $best;
    }

    /**
     * 🎲 تولید یک واریانت مقاله (هسته تولید نسخه ۱ + قابلیت‌های جدید)
     */
    private function generateSingle(array $brand, string $topicType, ?string $deviceKey, int $variantIndex, ?string $customTitle = null, array $options = []): array
    {
        $devices = $this->db->fetchAll(
            'SELECT * FROM brand_devices WHERE brand_id = ? AND is_active = 1',
            [$brand['id']]
        );
        if (empty($devices)) {
            // fallback به دانش دستگاه‌های عمومی
            $knowledgeDevices = TextProcessor::loadKnowledge('devices');
            foreach (array_slice($knowledgeDevices, 0, 5, true) as $key => $d) {
                $devices[] = ['device_key' => $key, 'name_fa' => $d['name_fa'] ?? $key];
            }
        }

        // 🎯 انتخاب دستگاه هدف
        $device = null;
        if ($deviceKey !== null) {
            foreach ($devices as $d) {
                if ($d['device_key'] === $deviceKey) {
                    $device = $d;
                    break;
                }
            }
        }
        if ($device === null) {
            /* 🐛 v2.9 — ریشه «تصویر/محتوای خارج از موضوع مقاله» (مثلاً تصویر
               لباسشویی برای راهنمای خرید یخچال): قبلاً وقتی عنوان دلخواه بدون
               device_key می‌آمد، دستگاه «تصادفی» انتخاب می‌شد و همه چیز مقاله
               (دانش، تصاویر، جدول کد خطا) حول آن دستگاه ساخته می‌شد!
               حالا: دستگاه از «خود عنوان» استنتاج می‌شود (۴۲ کلید دانش +
               واژه‌های محاوره‌ای: لباسشویی، یخچال، کولر، جاروبرقی و ...) */
            if ($customTitle !== null && $customTitle !== '') {
                $inferred = ArticleImageService::inferDeviceKey($customTitle);
                if ($inferred !== null) {
                    foreach ($devices as $d) {
                        if ($d['device_key'] === $inferred) {
                            $device = $d;
                            break;
                        }
                    }
                    if ($device === null) {
                        /* دستگاه در لیست برند نیست ولی موضوع مقاله همان است —
                           دانش عمومی + تصویر اختصاصی همان دستگاه استفاده می‌شود */
                        $dk = TextProcessor::loadKnowledge('devices')[$inferred] ?? [];
                        $device = ['device_key' => $inferred, 'name_fa' => $dk['name_fa'] ?? $inferred];
                    }
                }
            }
        }
        if ($device === null) {
            $device = TextProcessor::seededPick($devices, 'artdev|' . $brand['id'] . '|' . mt_rand());
        }

        $deviceKnowledge = TextProcessor::loadKnowledge('devices')[$device['device_key']] ?? [];
        $templates = TextProcessor::loadKnowledge('templates');
        $topicTemplates = $templates['article_topics'][$topicType] ?? [];
        if (empty($topicTemplates)) {
            $topicTemplates = $templates['article_topics']['troubleshooting'] ?? [];
        }
        // 📚 دانش دستگاه برای تضمین حجم (نسخه ۲)
        $usage = $deviceKnowledge['usage_tips'] ?? [];
        $maintenance = $deviceKnowledge['maintenance_tips'] ?? [];
        $issues = $deviceKnowledge['common_issues'] ?? [];

        $seed = 'article|' . $brand['id'] . '|' . $topicType . '|' . $device['device_key'] . '|' . time() . '|' . mt_rand() . '|v' . $variantIndex;
        /* 🧩 v2.27 — متغیرهای کامل (رفع «{{warranty_period}} و {{agency_name}}
           همینطوری در متن مقاله می‌ماند»): قبلاً فقط ۵ متغیر پاس می‌شد اما
           قالب‌های پایگاه دانش از agency_name/warranty_period/devices_list و
           ده‌ها متغیر دیگر استفاده می‌کنند → fillTemplate آن‌ها را باز نمی‌کرد. */
        $agencyName = Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس';
        $warrantyCfg = Config::get(Config::KEY_WARRANTY) ?: [];
        $deviceNames = array_column($devices, 'name_fa');
        $vars = [
            'brand_fa'      => $brand['name_fa'],
            'brand_en'      => $brand['name_en'],
            'device_fa'     => $device['name_fa'],
            'device_en'     => $deviceKnowledge['name_en'] ?? '',
            'agency'        => $agencyName,
            'agency_name'   => $agencyName,
            'warranty_period' => (string)($warrantyCfg['default_period'] ?? '۶ ماه'),
            'country'       => $brand['country_fa'] ?? '',
            'founded'       => (string)($brand['founded'] ?? ''),
            'devices_list'  => implode('، ', array_slice($deviceNames, 0, 6)),
            'devices_count' => (string)max(1, count($devices)),
            'first_device'  => $deviceNames[0] ?? 'لوازم خانگی',
            'main_site'     => Config::get(Config::KEY_MAIN_SITE) ?: AGENCY_MAIN_SITE,
            'year_now'      => (string)((int)(jdate(date('Y-m-d')) ?: date('Y'))),
            'slogan'        => $brand['slogan'] ?? '',
            'positioning'   => $brand['positioning'] ?? '',
        ];

        /* ---------- ۱️⃣ انتخاب عنوان: دلخواه کاربر یا قالب‌های موضوع (فاز Q.5) ---------- */
        if ($customTitle !== null && trim($customTitle) !== '') {
            $title = trim($customTitle);
        } else {
            $title = TextProcessor::fillTemplate(
                TextProcessor::seededPick($topicTemplates, $seed . '|title'),
                $vars
            );
        }

        /* ---------- ۱.۵) 🔎 تحقیق آنلاین وب (فاز Q.7 + v3.3: زمینه از پیش‌آماده) ----------
         * جستجوی اینترنت هنگام نوشتن: داده‌های واقعی + منابع معتبر
         * به مقاله اضافه می‌شود تا محتوا و سئو هر دو کامل باشند.
         * v3.3: اگر تحقیق از قبل انجام شده باشد (از SmartPipeline) دوباره جستجو نمی‌شود. */
        $webResearch = null;
        $researchSections = [];
        $researchTags = [];
        if (!empty($options['research_context'])) {
            $webResearch = $options['research_context'];
        } elseif (!empty($options['research'])) {
            try {
                $researchTopic = $title;
                $researchTopic = preg_replace('/[؟?!.:؛]+/u', ' ', $researchTopic) ?? $researchTopic;
                $webResearch = (new WebSearchService())->research(trim($researchTopic), [
                    'limit' => 6,
                ]);
            } catch (Throwable $e) {
                // شکست تحقیق نباید تولید مقاله را متوقف کند
                $webResearch = null;
            }
        }
        if ($webResearch !== null) {
            // 🏷️ کلیدواژه‌های ترند → تگ‌های مقاله
            foreach (array_slice($webResearch['keywords'] ?? [], 0, 3) as $kw) {
                if (is_string($kw) && mb_strlen($kw) >= 3) {
                    $researchTags[] = $kw;
                }
            }
        }

        /* ---------- ۲️⃣ ساخت Outline مقاله (v3.3: موضوع‌محور برای عنوان دلخواه) ---------- */
        $subject = $customTitle !== null ? $this->subjectFromTitle($title) : '';
        if ($subject !== '') {
            $sections = $this->buildTopicOutline($subject, $topicType, $device, $deviceKnowledge, $vars, $seed, $webResearch);
        } else {
            /* 🔎 مقاله قالبی: فکت‌ها و پرسش‌های وب به‌صورت بخش مستقل (فاز Q.7) */
            if ($webResearch !== null) {
                $facts = array_slice($webResearch['facts'] ?? [], 0, 5);
                if (!empty($facts)) {
                    $factsHtml = '<p>بر اساس بررسی منابع آنلاین به‌روز، این داده‌ها به تأیید رسیده است:</p>' . "\n" . '<ul>' . "\n";
                    foreach ($facts as $fact) {
                        $factsHtml .= '<li>' . e((string)$fact) . '</li>' . "\n";
                    }
                    $factsHtml .= '</ul>';
                    $researchSections[] = $this->section('📊 داده‌های به‌روز از منابع آنلاین', $factsHtml);
                }
                $rQuestions = array_slice($webResearch['questions'] ?? [], 0, 4);
                if (!empty($rQuestions)) {
                    $qHtml = '<p>کاربران واقعی در جستجوهای خود این پرسش‌ها را مطرح کرده‌اند:</p>' . "\n" . '<ul>' . "\n";
                    foreach ($rQuestions as $q) {
                        $qHtml .= '<li>' . e((string)$q) . '</li>' . "\n";
                    }
                    $qHtml .= '</ul>';
                    $researchSections[] = $this->section('🤔 پرسش‌های پرتکرار کاربران در وب', $qHtml);
                }
            }
            $sections = $this->buildOutline($topicType, $device, $deviceKnowledge, $vars, $seed);
            // 🔎 درج بخش‌های تحقیق آنلاین قبل از بخش عمومی پایانی (فاز Q.7)
            if (!empty($researchSections)) {
                array_splice($sections, max(0, count($sections) - 1), 0, $researchSections);
            }
        }
        // 📚 بخش منابع و مطالعه بیشتر — لینک خروجی معتبر (E-E-A-T)
        if ($webResearch !== null && !empty($webResearch['sources'])) {
            $srcHtml = '<p>برای مطالعه بیشتر و راستی‌آزمایی داده‌های این مقاله، این منابع آنلاین را ببینید:</p>' . "\n" . '<ul>' . "\n";
            foreach (array_slice($webResearch['sources'], 0, 4) as $src) {
                $srcTitle = (string)($src['title'] ?? '');
                $srcUrl = (string)($src['url'] ?? '');
                if ($srcTitle !== '' && $srcUrl !== '' && preg_match('#^https?://#', $srcUrl)) {
                    $host = parse_url($srcUrl, PHP_URL_HOST) ?: '';
                    $srcHtml .= '<li><a href="' . e($srcUrl) . '" target="_blank" rel="noopener nofollow">' . e(mb_substr($srcTitle, 0, 90)) . '</a>' . ($host !== '' ? ' <small>(' . e($host) . ')</small>' : '') . '</li>' . "\n";
                }
            }
            $srcHtml .= '</ul>';
            $sections[] = $this->section('📚 منابع و مطالعه بیشتر', $srcHtml);
        }

        /* ---------- ۳️⃣ نوشتن محتوای هر بخش ---------- */
        $bodyParts = [];
        foreach ($sections as $section) {
            $bodyParts[] = '<h2>' . e($section['title']) . '</h2>';
            $bodyParts[] = $section['content'];
        }

        // مقدمه (v3.3: برای مقاله موضوع‌محور، مقدمه دقیقاً درباره همان موضوع)
        if ($subject !== '') {
            $intro = $this->topicIntro($subject, $vars, $webResearch, $seed);
        } else {
            $introTemplate = TextProcessor::seededPick($templates['article_intro'] ?? [''], $seed . '|intro');
            $intro = TextProcessor::applySynonyms(TextProcessor::fillTemplate($introTemplate, $vars), $seed . '|isyn');
        }

        // نتیجه‌گیری + CTA
        if ($subject !== '') {
            $conclusion = $this->topicConclusion($subject, $vars, $seed);
        } else {
            $conclusionTemplate = TextProcessor::seededPick($templates['article_conclusion'] ?? [''], $seed . '|concl');
            $conclusion = TextProcessor::fillTemplate($conclusionTemplate, $vars);
        }

        $content = '<p>' . $intro . '</p>' . "\n" . implode("\n", $bodyParts)
            . "\n" . '<h2>جمع‌بندی</h2>' . "\n" . '<p>' . $conclusion . '</p>';

        /* ---------- ۳.۵) 🆕 جعبه «نکات کلیدی» (TL;DR) ---------- */
        $content = $this->addKeyTakeaways($content, $sections, $vars, $seed);

        /* ---------- ۳.۵۵) 🆕 v2.1: ساختار حرفه‌ای مقاله ----------
         * فهرست مطالب (TOC) + زمان مطالعه + جعبه‌های نکته/هشدار +
         * مزایا و معایب + باکس آمار — همان عناصری که نشریات حرفه‌ای
         * برای E-E-A-T و تجربه کاربری بهتر به کار می‌برند */
        $structure = $this->enrichStructure($content, $vars, $topicType, $seed);
        $content = $structure['content'];
        $toc = $structure['toc'];
        $readingTime = $structure['reading_time'];

        /* ---------- ۳.۶) 🆕 بخش سوالات متداول مقاله (v3.3: موضوع‌محور) ---------- */
        $faqs = $this->articleFaq($vars, $topicType, $seed, $subject, $webResearch);
        if (!empty($faqs)) {
            $faqHtml = '<h2>سوالات متداول</h2>' . "\n";
            foreach ($faqs as $faq) {
                $faqHtml .= '<h3>' . e($faq['question']) . '</h3>' . "\n" . '<p>' . e($faq['answer']) . '</p>' . "\n";
            }
            $content .= "\n" . $faqHtml;
        }

        /* ---------- ۳.۷) 🖼️ تصاویر خودکار مقاله (فاز Q.8) ----------
         * ۳ تصویر مرتبط (شاخص + میان‌متن + پایانی) با alt و figcaption
         * استاندارد سئو درج می‌شود — ریسک صفر چون از بسته داخلی است
         * v2: اگر دستگاه مقاله مشخص نیست از عنوان استنتاج می‌شود +
         *     نسخه واترمارک‌شده (لوگوی برند + نمایندگی) تحویل می‌شود */
        $images = [];
        if (!empty($options['with_images'])) {
            try {
                $imageService = new ArticleImageService();
                $images = $imageService->pick(
                    $device['device_key'] ?? null,
                    $topicType,
                    $title,
                    $device['name_fa'] ?? '',
                    $brand
                );
                $content = $imageService->injectIntoContent($content, $images);
            } catch (Throwable $e) {
                $images = []; // شکست تصویر نباید مقاله را متوقف کند
            }
        }

        /* ---------- ۳.۸) 🎨 تصاویر یکتای AI + تصویر OG (v2.6) ----------
         * برای هر مقاله: ۲ تصویر یکتای AI (SVG بذری — هیچ دو مقاله‌ای یکی نیست)
         * + تصویر OG مرتبط با موضوع همان مقاله. فایل‌ها پس از ثبت مقاله
         * (با شناسه نهایی) تولید می‌شوند؛ اینجا فقط علامت می‌دهیم. */
        $aiImagesWanted = !empty($options['with_images']);

        /* ---------- ۴️⃣ لینک‌دهی داخلی ---------- */
        $content = $this->addInternalLinks($content, $brand, $device);

        /* ---------- ۵️⃣ بررسی یکتایی ---------- */
        $check = $this->uniqueness->check($content, (int)$brand['id']);
        $attempts = 1;
        while (!$check['unique'] && $attempts < 4) {
            // بازنویسی شدیدتر برای یکتا شدن
            $content = TextProcessor::applySynonyms($content, $seed . '|retry' . $attempts, 0.5);
            $content = TextProcessor::restructureSentences($content, $seed . '|retry' . $attempts);
            $check = $this->uniqueness->check($content, (int)$brand['id']);
            $attempts++;
        }

        /* ---------- ۵.۵) 🆕 تضمین حداقل حجم مقاله (۱۰۰۰ کلمه — v2.1) ---------- */
        $expandTries = 0;
        while (TextProcessor::wordCount(strip_tags($content)) < 1000 && $expandTries < 4) {
            $extra = $this->paragraphsFrom(array_merge($usage, $maintenance, $issues), 3, $seed . '|expand' . $expandTries);
            if ($extra === '') {
                break;
            }
            // درج قبل از جمع‌بندی
            $pos = mb_strripos($content, '<h2>جمع‌بندی</h2>');
            if ($pos === false) {
                $content .= $extra;
            } else {
                $content = mb_substr($content, 0, $pos) . $extra . "\n" . mb_substr($content, $pos);
            }
            $expandTries++;
        }

        /* ---------- ۵.۶) 🆕 v2.2: اصلاح نگارشی فارسی (PersianGrammar) ----------
         * نیم‌فاصله، علائم سجاوندی، املای رایج، ارقام فارسی و
         * هم‌خوانی فعل و فاعل — پیش از سئو، روی متن نهایی اعمال می‌شود */
        $grammarFix = PersianGrammar::fix($content);
        $content = $grammarFix['content'];
        $grammarAnalysis = PersianGrammar::analyze($content);

        /* ---------- ۶️⃣ سئو ---------- */
        $seoGenerator = new SeoGenerator();
        // 🎯 کلیدواژه کانونی: از عنوان دلخواه استخراج می‌شود (فاز Q.5)
        if ($customTitle !== null && trim($customTitle) !== '') {
            $focusKeyword = $this->focusFromTitle($title, $device['name_fa']);
        } else {
            $focusKeyword = 'تعمیر ' . $device['name_fa'] . ' ' . $brand['name_fa'];
        }
        $seo = $seoGenerator->generateForArticle($title, $content, $focusKeyword, $vars);

        /* ---------- ۶.۵) 🆕 افزودن FAQ Schema به سئو ---------- */
        if (!empty($faqs)) {
            $seo['schema_faq'] = $seoGenerator->faqSchema($faqs);
            $seo['schema']['@graph'][] = $seo['schema_faq'];
        }

        /* ---------- ۷️⃣ 🆕 امتیاز کیفیت (QualityScorer نسخه ۲) ---------- */
        $quality = $this->scorer->score($content, $focusKeyword, 'article');

        /* ---------- ۷.۵) 🧹 v2.27 — جاروی نهایی متغیرهای {{...}} ----------
         * هر متغیری که در هیچ مرحله‌ای باز نشده باشد (قالب جدید دانش،
         * پرسش/پاسخ FAQ، متن تحقیق و ...) اینجا با مقدار درست جایگزین و
         * ناشناخته‌ها حذف می‌شوند — دیگر هرگز {{xxx}} خام به دیتابیس نمی‌رود. */
        $title   = TextProcessor::sweepPlaceholders($title, $vars);
        $content = TextProcessor::sweepPlaceholders($content, $vars);
        $intro   = TextProcessor::sweepPlaceholders($intro, $vars);
        foreach ($faqs as $fk => $faq) {
            $faqs[$fk]['question'] = TextProcessor::sweepPlaceholders((string)($faq['question'] ?? ''), $vars);
            $faqs[$fk]['answer']   = TextProcessor::sweepPlaceholders((string)($faq['answer'] ?? ''), $vars);
        }

        return [
            'title'      => $title,
            'slug'       => SlugGenerator::unique($title, 'brand_articles', 'slug', 0, (int)$brand['id']),
            'content'    => $content,
            'excerpt'    => excerpt($intro, 200),
            'focus_keyword' => $focusKeyword,
            'category'   => $this->categoryForTopic($topicType),
            'tags'       => array_values(array_unique(array_merge(
                [$device['name_fa'], $brand['name_fa']],
                $researchTags, // 🆕 فاز Q.7: کلیدواژه‌های ترند از جستجوی آنلاین
                [$topicType === 'maintenance' ? 'نگهداری' : 'تعمیرات']
            ))),
            'seo'        => $seo,
            'uniqueness' => $check,
            'word_count' => TextProcessor::wordCount(strip_tags($content)),
            'reading_time' => $readingTime,
            'toc'        => $toc,
            'quality'    => $quality,
            'grammar'    => [
                'score'      => $grammarAnalysis['score'],
                'grade'      => $grammarAnalysis['grade'],
                'fixes'      => $grammarFix['stats'],
                'issues'     => array_slice($grammarAnalysis['issues'], 0, 10),
                'metrics'    => $grammarAnalysis['metrics'],
            ],
            'faqs'       => $faqs,
            'images'     => $images, // 🆕 فاز Q.8: ۳ تصویر (featured/inline_mid/inline_end)
            'ai_images_wanted' => $aiImagesWanted, // 🆕 v2.6: درخواست ۲ تصویر یکتای AI + OG پس از ثبت
            'device_key' => $device['device_key'] ?? '', // 🆕 v2.6: برای مولد تصویر AI
            'research'   => $webResearch ? [
                'used'      => true,
                'provider'  => $webResearch['provider'] ?? '',
                'facts'     => count($webResearch['facts'] ?? []),
                'questions' => count($webResearch['questions'] ?? []),
                'sources'   => count($webResearch['sources'] ?? []),
                'keywords'  => array_slice($webResearch['keywords'] ?? [], 0, 5),
            ] : ['used' => false],
            'generated_by_ai' => 1,
            'uniqueness_hash' => $check['hash'] ?? TextProcessor::contentHash($content),
        ];
    }

    /**
     * ✨ v2.1: غنی‌سازی ساختاری مقاله — فهرست مطالب + لنگرها + زمان مطالعه +
     * جعبه‌های نکته/هشدار حرفه‌ای + مزایا و معایب + باکس آمار
     *
     * @return array ['content', 'toc', 'reading_time']
     */
    private function enrichStructure(string $content, array $vars, string $topicType, string $seed): array
    {
        $d = $vars['device_fa'];
        $b = $vars['brand_fa'];

        /* 🧭 فهرست مطالب + لنگرگذاری روی تیترهای h2 */
        $toc = [];
        $anchorIdx = 0;
        $content = preg_replace_callback('#<h2>(.*?)</h2>#u', function ($m) use (&$toc, &$anchorIdx) {
            $anchorIdx++;
            $title = trim(strip_tags($m[1]));
            if ($title === '') { return $m[0]; }
            $slug = 'sec-' . $anchorIdx;
            $toc[] = ['title' => $title, 'anchor' => $slug];
            return '<h2 id="' . $slug . '">' . $m[1] . '</h2>';
        }, $content);

        $wordCount = TextProcessor::wordCount(strip_tags($content));
        $readingTime = max(1, (int)ceil($wordCount / 220)); // میانگین ۲۲۰ کلمه بر دقیقه فارسی

        if (!empty($toc)) {
            $tocHtml = '<div class="article-toc"><div class="toc-title">📑 فهرست مطالب</div><ul>';
            foreach ($toc as $item) {
                $tocHtml .= '<li><a href="#' . e($item['anchor']) . '">' . e($item['title']) . '</a></li>';
            }
            $tocHtml .= '</ul><div class="toc-meta">⏱️ زمان مطالعه: حدود ' . en_to_fa_digits((string)$readingTime) . ' دقیقه</div></div>';
            // درج بعد از اولین پاراگراف (مقدمه)
            $firstP = (int)mb_strpos($content, '</p>');
            if ($firstP !== false) {
                $content = mb_substr($content, 0, $firstP + 4) . "\n" . $tocHtml . "\n" . mb_substr($content, $firstP + 4);
            } else {
                $content = $tocHtml . "\n" . $content;
            }
        }

        /* 💡 جعبه نکته پرو — بعد از اولین h2 */
        $tipPool = [
            'قبل از باز کردن بدنه ' . $d . '، حتماً دستگاه را از برق بکشید تا از برق‌گرفتگی و آسیب به برد الکترونیکی جلوگیری شود.',
            'عکس گرفتن از مراحل باز و بسته شدن قطعات، در هنگام سرهم‌کردن مجدد دستگاه باعث صرفه‌جویی قابل توجهی در زمان می‌شود.',
            'شماره مدل دقیق ' . $d . ' معمولاً روی پلاک پشت دستگاه درج شده است؛ این کد برای تهیه قطعه سازگار ضروری است.',
            'به یاد داشته باشید که کالیبراسیون مجدد پس از تعویض قطعات حساس، بخشی از فرآیند تعمیر حرفه‌ای است نه یک گام اختیاری.',
        ];
        $tip = TextProcessor::seededPick($tipPool, $seed . '|tip');
        $tipBox = '<div class="callout callout-tip"><span class="callout-ico">💡</span><div><b>نکته حرفه‌ای:</b> ' . e($tip) . '</div></div>';
        $content = $this->insertAfterHeading($content, 1, $tipBox);

        /* ⚠️ جعبه هشدار ایمنی — بعد از دومین h2 (فقط مقالات فنی) */
        if (in_array($topicType, ['troubleshooting', 'error_codes', 'user_guide', 'maintenance'], true)) {
            $warnPool = [
                'تعمیرات مرتبط با گاز مبرد، کمپرسور و مدارهای قدرت باید فقط توسط تکنسین مجاز انجام شود؛ انجام شخصی این موارد می‌تواند ضمانت دستگاه را باطل کند.',
                'هرگز قطعات اصلی ' . $d . ' را با قطعات فاقد استاندارد جایگزین نکنید؛ خرابی‌های ثانویه ناشی از قطعات بی‌کیفیت معمولاً پرهزینه‌تر از تعمیر اولیه است.',
                'در صورت بوی سوختگی، صدای غیرعادی بلند یا نشتی آب، دستگاه را فوراً خاموش و از برق بکشید و با نمایندگی ' . $b . ' تماس بگیرید.',
            ];
            $warn = TextProcessor::seededPick($warnPool, $seed . '|warn');
            $warnBox = '<div class="callout callout-warning"><span class="callout-ico">⚠️</span><div><b>هشدار ایمنی:</b> ' . e($warn) . '</div></div>';
            $content = $this->insertAfterHeading($content, 2, $warnBox);
        }

        /* ⚖️ جعبه مزایا و معایب — برای مقالات مقایسه/راهنمای خرید */
        if (in_array($topicType, ['comparison', 'buying_guide'], true)) {
            $prosCons = '<div class="pros-cons"><div class="pros"><div class="pc-title">✅ نقاط قوت</div><ul>'
                . '<li>صرفه‌جویی در مصرف انرژی نسبت به مدل‌های قدیمی‌تر</li>'
                . '<li>دسترسی آسان به قطعات یدکی اصلی در نمایندگی‌های مجاز</li>'
                . '<li>گارانتی معتبر و خدمات پس از فروش رسمی ' . e($b) . '</li></ul></div>'
                . '<div class="cons"><div class="pc-title">⚠️ نقاط ضعف</div><ul>'
                . '<li>هزینه بالاتر تعمیر نسبت به برندهای اقتصادی</li>'
                . '<li>نیاز به تکنسین متخصص برای تشخیص ایرادات برد الکترونیکی</li></ul></div></div>';
            $content = $this->insertAfterHeading($content, 2, $prosCons);
        }

        /* 📊 باکس آمار و اعتماد — قبل از جمع‌بندی */
        $statBox = '<div class="article-stats"><div class="stat"><span class="stat-num">' . en_to_fa_digits('12') . '+</span><span class="stat-lbl">سال تجربه</span></div>'
            . '<div class="stat"><span class="stat-num">' . en_to_fa_digits('98') . '٪</span><span class="stat-lbl">رضایت مشتریان</span></div>'
            . '<div class="stat"><span class="stat-num">' . en_to_fa_digits((string)$readingTime) . '</span><span class="stat-lbl">دقیقه مطالعه</span></div></div>';
        $pos = mb_strripos($content, '<h2>جمع‌بندی</h2>');
        if ($pos !== false) {
            $content = mb_substr($content, 0, $pos) . $statBox . "\n" . mb_substr($content, $pos);
        }

        return ['content' => $content, 'toc' => $toc, 'reading_time' => $readingTime];
    }

    /**
     * 📌 درج یک بلوک HTML بعد از n-امین تیتر h2 (یا انتهای محتوا اگر وجود نداشت)
     */
    private function insertAfterHeading(string $content, int $n, string $html): string
    {
        $count = 0;
        $offset = 0;
        while ($count < $n) {
            $pos = mb_strpos($content, '</h2>', $offset);
            if ($pos === false) {
                return $content . "\n" . $html;
            }
            $count++;
            $offset = $pos + 5;
        }
        return mb_substr($content, 0, $offset) . "\n" . $html . "\n" . mb_substr($content, $offset);
    }

    /**
     * 🏗️ ساخت Outline (سرفصل‌های مقاله) بر اساس نوع موضوع
     */
    private function buildOutline(string $topicType, array $device, array $deviceKnowledge, array $vars, string $seed): array
    {
        $sections = [];
        $d = $vars['device_fa'];
        $b = $vars['brand_fa'];

        // 📚 دانش نگهداری و ایرادات دستگاه از پایگاه دانش
        $issues = $deviceKnowledge['common_issues'] ?? [];
        $maintenance = $deviceKnowledge['maintenance_tips'] ?? [];
        $usage = $deviceKnowledge['usage_tips'] ?? [];

        switch ($topicType) {
            case 'troubleshooting': // رفع ایراد
                $sections[] = $this->section('علت‌های رایج خرابی ' . $d . ' ' . $b, $this->bullets($issues, 5, $seed, "مورد قابل ذکر") . $this->paragraphsFrom($issues, 3, $seed . 's1'));
                $sections[] = $this->section('نشانه‌هایی که نشان می‌دهد ' . $d . ' نیاز به تعمیر دارد', $this->paragraphsFrom($issues, 4, $seed . 's2'));
                $sections[] = $this->section('راه‌حل‌های عملی و گام‌به‌گام', $this->numberedSteps($issues, $seed . 's3') . $this->paragraphsFrom($issues, 2, $seed . 's3b'));
                // 🩺 بخش جدید: عیب‌یابی هوشمند علامت‌محور از پایگاه دانش
                $sections[] = $this->diagnosticSection($device['device_key'], $d, $seed);
                $sections[] = $this->section('هزینه تعمیر و زمان انجام آن', $this->paragraphsFrom($maintenance, 3, $seed . 's5'));
                $sections[] = $this->section('چه زمانی باید با تعمیرکار تماس بگیرید؟', $this->paragraphsFrom($issues, 3, $seed . 's4'));
                break;

            case 'user_guide': // راهنمای استفاده
                $sections[] = $this->section('آشنایی با اجزای اصلی ' . $d . ' ' . $b, $this->paragraphsFrom($usage, 4, $seed . 'g1'));
                $sections[] = $this->section('نکات طلایی استفاده صحیح', $this->bullets($usage, 6, $seed, "نکته کاربردی") . $this->paragraphsFrom($usage, 2, $seed . 'g1b'));
                $sections[] = $this->section('اشتباهات رایج کاربران', $this->bullets($usage, 4, $seed . 'g3', "اشتباه شایع") . $this->paragraphsFrom($usage, 3, $seed . 'g3b'));
                $sections[] = $this->section('افزایش عمر دستگاه با عادت‌های درست', $this->paragraphsFrom($maintenance, 3, $seed . 'g4'));
                $sections[] = $this->section('راهنمای مصرف بهینه انرژی', $this->paragraphsFrom($maintenance, 3, $seed . 'g5'));
                break;

            case 'maintenance': // نگهداری
                $sections[] = $this->section('اهمیت سرویس دوره‌ای ' . $d, $this->paragraphsFrom($maintenance, 3, $seed . 'm1'));
                $sections[] = $this->section('چک‌لیست نگهداری ماهانه', $this->numberedSteps($maintenance, $seed . 'm2') . $this->paragraphsFrom($maintenance, 2, $seed . 'm2b'));
                $sections[] = $this->section('نگهداری فصلی', $this->bullets($maintenance, 5, $seed, "اقدام فصلی") . $this->paragraphsFrom($maintenance, 3, $seed . 'm3b'));
                // 🔧 بخش جدید: قطعات مصرفی و زمان تعویض از دانش دستگاه
                $sections[] = $this->partsWearSection($device['device_key'], $d, $seed);
                $sections[] = $this->section('قطعات مصرفی و زمان تعویض', $this->paragraphsFrom($issues, 3, $seed . 'm4'));
                break;

            case 'comparison': // مقایسه
                $sections[] = $this->section('بررسی تفاوت مدل‌های ' . $d . ' ' . $b, $this->paragraphsFrom($usage, 3, $seed . 'c1'));
                $sections[] = $this->section('معیارهای انتخاب ' . $d . ' مناسب', $this->bullets($usage, 6, $seed, "معیار مهم") . $this->paragraphsFrom($usage, 3, $seed . 'c2b'));
                $sections[] = $this->section('مقایسه مصرف انرژی و کارایی', $this->paragraphsFrom($maintenance, 3, $seed . 'c3'));
                $sections[] = $this->section('جمع‌بندی مقایسه', $this->paragraphsFrom($issues, 2, $seed . 'c4'));
                break;

            case 'error_codes': // عیب‌یابی و کدهای خطا
                $sections[] = $this->section('کدهای خطای رایج ' . $d . ' ' . $b . ' و معنی آن‌ها', $this->errorCodeTable($device['device_key']) . $this->paragraphsFrom($usage, 2, $seed . 'e1b'));
                $sections[] = $this->section('راه‌حل هر کد خطا', $this->paragraphsFrom($issues, 4, $seed . 'e2'));
                $sections[] = $this->section('ریست کردن ' . $d . ' در صورت بروز خطا', $this->resetTipsSection($device['device_key']) . $this->numberedSteps($issues, $seed . 'e3') . $this->paragraphsFrom($maintenance, 2, $seed . 'e3b'));
                $sections[] = $this->section('پیشگیری از بروز مجدد خطاها', $this->paragraphsFrom($maintenance, 3, $seed . 'e4'));
                break;

            case 'buying_guide': // 🆕 راهنمای خرید
                $sections[] = $this->section('معیارهای کلیدی انتخاب ' . $d . ' ' . $b, $this->bullets($usage, 6, $seed, "معیار خرید") . $this->paragraphsFrom($usage, 2, $seed . 'b1'));
                $sections[] = $this->section('بررسی رده‌های قیمتی و تناسب با نیاز', $this->paragraphsFrom($maintenance, 3, $seed . 'b2'));
                $sections[] = $this->section('نکاتی که فروشنده‌ها نمی‌گویند', $this->paragraphsFrom($issues, 4, $seed . 'b3'));
                $sections[] = $this->section('هزینه مالکیت واقعی؛ از مصرف انرژی تا قطعات یدکی', $this->paragraphsFrom($maintenance, 3, $seed . 'b4'));
                break;

            case 'energy_saving': // 🆕 صرفه‌جویی انرژی
                $sections[] = $this->section('مصرف انرژی ' . $d . ' چقدر است؟', $this->paragraphsFrom($usage, 3, $seed . 'n1'));
                $sections[] = $this->section('تنظیمات طلایی برای کاهش قبض', $this->bullets($maintenance, 6, $seed, "تنظیم بهینه") . $this->paragraphsFrom($maintenance, 2, $seed . 'n2b'));
                $sections[] = $this->section('عادت‌هایی که برق را هدر می‌دهند', $this->paragraphsFrom($issues, 4, $seed . 'n3'));
                $sections[] = $this->section('زمان‌بندی هوشمند استفاده از ' . $d, $this->paragraphsFrom($usage, 3, $seed . 'n4'));
                break;

            case 'seasonal_care': // 🆕 مراقبت فصلی — مبتنی بر تقویم فصلی پایگاه دانش
                $sections[] = $this->seasonalIntroSection($d, $b, $seed);
                $sections[] = $this->section('چک‌لیست آماده‌سازی ' . $d . ' برای این فصل', $this->bullets($maintenance, 6, $seed, "آماده‌سازی فصلی") . $this->paragraphsFrom($maintenance, 3, $seed . 'sc2'));
                $sections[] = $this->section('خطرهای فصلی برای ' . $d, $this->paragraphsFrom($issues, 4, $seed . 'sc3'));
                $wearSection = $this->partsWearSection($device['device_key'], $d, $seed);
                $sections[] = $this->section('قطعات مصرفی پرتقاضای این فصل', ($wearSection['content'] ?? '') . $this->paragraphsFrom($issues, 2, $seed . 'sc4'));
                $sections[] = $this->section('سرویس پیش از فصل؛ چرا به‌موقع اقدام کنید؟', $this->paragraphsFrom($maintenance, 3, $seed . 'sc5'));
                break;

            case 'cost_guide': // 🆕 راهنمای هزینه — شفاف‌سازی قیمت تعمیر
                $sections[] = $this->section('هزینه تعمیر ' . $d . ' ' . $b . ' چگونه محاسبه می‌شود؟', $this->paragraphsFrom($usage, 3, $seed . 'cg1'));
                $sections[] = $this->section('عوامل موثر بر قیمت قطعات و خدمات', $this->bullets($issues, 5, $seed, "عامل هزینه") . $this->paragraphsFrom($issues, 3, $seed . 'cg2b'));
                $wearSection2 = $this->partsWearSection($device['device_key'], $d, $seed);
                $sections[] = $this->section('قطعات مصرفی پرتقاضا و بازه بازدید آن‌ها', $wearSection2['content'] ?? '');
                $sections[] = $this->section('تعمیر بخرم یا دستگاه جدید؟ معیار تصمیم‌گیری', $this->paragraphsFrom($maintenance, 4, $seed . 'cg4'));
                $sections[] = $this->section('چگونه از هزینه‌های پنهان جلوگیری کنیم؟', $this->paragraphsFrom($issues, 3, $seed . 'cg5'));
                break;

            /* ════════ 🆕 انواع فاز Q.4 (۱۶ نوع جدید) ════════ */

            case 'safety_guide': // ایمنی و احتیاط
                $sections[] = $this->section('چرا ایمنی در کار با ' . $d . ' اهمیت حیاتی دارد؟', $this->paragraphsFrom($usage, 3, $seed . 'sg1'));
                $sections[] = $this->section('خطرات اصلی: برق، گاز، حرارت و قطعات متحرک', $this->bullets($issues, 6, $seed . 'sg2', "خطر بالقوه") . $this->paragraphsFrom($issues, 2, $seed . 'sg2b'));
                $sections[] = $this->section('قبل از هر اقدام؛ چک‌لیست قطع ایمن', $this->numberedSteps($maintenance, $seed . 'sg3') . $this->paragraphsFrom($maintenance, 2, $seed . 'sg3b'));
                $sections[] = $this->section('علائم هشداردهنده ' . $d . ' که باید بلافاصله جدی گرفته شوند', $this->paragraphsFrom($issues, 4, $seed . 'sg4'));
                $sections[] = $this->section('تجهیزات حفاظت فردی برای تعمیرکاران و کاربران', $this->paragraphsFrom($maintenance, 2, $seed . 'sg5'));
                break;

            case 'installation_guide': // نصب و راه‌اندازی
                $sections[] = $this->section('آماده‌سازی پیش از نصب ' . $d . ' ' . $b, $this->paragraphsFrom($usage, 3, $seed . 'ig1'));
                $sections[] = $this->section('شرایط محیطی استاندارد: فضا، تهویه و زیربنا', $this->bullets($maintenance, 5, $seed . 'ig2', "شرایط لازم") . $this->paragraphsFrom($maintenance, 2, $seed . 'ig2b'));
                $sections[] = $this->section('مراحل نصب گام‌به‌گام', $this->numberedSteps($usage, $seed . 'ig3') . $this->paragraphsFrom($usage, 2, $seed . 'ig3b'));
                $sections[] = $this->section('راه‌اندازی اولیه و تنظیمات پیشنهادی', $this->paragraphsFrom($usage, 3, $seed . 'ig4'));
                $sections[] = $this->section('تست نهایی و امضای گارانتی نصب', $this->paragraphsFrom($issues, 2, $seed . 'ig5'));
                break;

            case 'diy_vs_pro': // خودم یا تعمیرکار؟
                $sections[] = $this->section('ایرادهایی که می‌توانید خودتان رفع کنید', $this->bullets($usage, 5, $seed . 'dp1', "قابل رفع شخصی") . $this->paragraphsFrom($usage, 2, $seed . 'dp1b'));
                $sections[] = $this->section('ایرادهایی که فقط باید متخصص حل کند', $this->bullets($issues, 5, $seed . 'dp2', "نیازمند تخصص") . $this->paragraphsFrom($issues, 2, $seed . 'dp2b'));
                $sections[] = $this->section('مقایسه هزینه، زمان و ریسک دو مسیر', $this->paragraphsFrom($maintenance, 3, $seed . 'dp3'));
                $sections[] = $this->section('ریسک‌های تعمیر شخصی ' . $d . '؛ از باطل‌شدن گارانتی تا آسیب ثانویه', $this->paragraphsFrom($issues, 3, $seed . 'dp4'));
                $sections[] = $this->section('تصمیم‌گیری هوشمندانه؛ درخت انتخاب درست', $this->paragraphsFrom($maintenance, 2, $seed . 'dp5'));
                break;

            case 'common_mistakes': // اشتباهات رایج
                $sections[] = $this->section('اشتباهات پرتکرار کاربران ' . $d . ' ' . $b, $this->bullets($issues, 7, $seed . 'cm1', "اشتباه شایع") . $this->paragraphsFrom($issues, 3, $seed . 'cm1b'));
                $sections[] = $this->section('پیامدهای پنهان هر اشتباه', $this->paragraphsFrom($issues, 3, $seed . 'cm2'));
                $sections[] = $this->section('رفتارهایی که به‌ظاهر بی‌ضرر اما مخرب‌اند', $this->paragraphsFrom($usage, 3, $seed . 'cm3'));
                $sections[] = $this->section('جایگزین درست برای هر عادت غلط', $this->bullets($maintenance, 5, $seed . 'cm4', "عادت درست") . $this->paragraphsFrom($maintenance, 2, $seed . 'cm4b'));
                break;

            case 'warranty_guide': // گارانتی و خدمات پس از فروش
                $sections[] = $this->section('گارانتی ' . $d . ' ' . $b . ' چه مواردی را پوشش می‌دهد؟', $this->paragraphsFrom($usage, 3, $seed . 'wg1'));
                $sections[] = $this->section('استثناهای رایج؛ چه مواردی تحت پوشش نیست؟', $this->bullets($issues, 5, $seed . 'wg2', "خارج از پوشش") . $this->paragraphsFrom($issues, 2, $seed . 'wg2b'));
                $sections[] = $this->section('اقداماتی که گارانتی را باطل می‌کند', $this->paragraphsFrom($issues, 3, $seed . 'wg3'));
                $sections[] = $this->section('مراحل درست استفاده از خدمات گارانتی', $this->numberedSteps($maintenance, $seed . 'wg4') . $this->paragraphsFrom($maintenance, 2, $seed . 'wg4b'));
                $sections[] = $this->section('مدارک لازم برای پذیرش درخواست گارانتی', $this->paragraphsFrom($usage, 2, $seed . 'wg5'));
                break;

            case 'tech_explainer': // فناوری‌های به‌کاررفته
                $sections[] = $this->section('فناوری‌های اصلی به‌کاررفته در ' . $d . ' ' . $b, $this->paragraphsFrom($usage, 3, $seed . 'te1'));
                $sections[] = $this->section('سنسورها و الگوریتم‌های هوشمند؛ به زبان ساده', $this->bullets($usage, 5, $seed . 'te2', "فناوری کلیدی") . $this->paragraphsFrom($usage, 2, $seed . 'te2b'));
                $sections[] = $this->section('تفاوت مدل‌های معمولی و نسل جدید در عمل', $this->paragraphsFrom($maintenance, 3, $seed . 'te3'));
                $sections[] = $this->section('فناوری چگونه در هزینه و راحتی شما اثر می‌گذارد؟', $this->paragraphsFrom($issues, 3, $seed . 'te4'));
                $sections[] = $this->section('نگهداری قطعات الکترونیکی حساس', $this->paragraphsFrom($maintenance, 2, $seed . 'te5'));
                break;

            case 'myths_facts': // باور غلط و واقعیت
                $sections[] = $this->section('شایع‌ترین باورهای غلط درباره ' . $d . ' ' . $b, $this->bullets($issues, 6, $seed . 'mf1', "باور غلط رایج") . $this->paragraphsFrom($issues, 2, $seed . 'mf1b'));
                $sections[] = $this->section('واقعیت علمی در برابر هر باور', $this->paragraphsFrom($usage, 4, $seed . 'mf2'));
                $sections[] = $this->section('ریشه‌یابی شایعات؛ از کجا آمدند؟', $this->paragraphsFrom($maintenance, 2, $seed . 'mf3'));
                $sections[] = $this->section('منبع درست اطلاعات؛ دفترچه یا تکنسین مجاز؟', $this->paragraphsFrom($usage, 2, $seed . 'mf4'));
                break;

            case 'checklist': // چک‌لیست
                $sections[] = $this->section('چک‌لیست بازدید روزانه ' . $d, $this->bullets($usage, 5, $seed . 'cl1', "بازدید روزانه") . $this->paragraphsFrom($usage, 2, $seed . 'cl1b'));
                $sections[] = $this->section('چک‌لیست ماهانه نگهداری', $this->numberedSteps($maintenance, $seed . 'cl2') . $this->paragraphsFrom($maintenance, 2, $seed . 'cl2b'));
                $sections[] = $this->section('چک‌لیست عیب‌یابی اولیه قبل از تماس با تعمیرکار', $this->bullets($issues, 6, $seed . 'cl3', "بررسی سریع") . $this->paragraphsFrom($issues, 2, $seed . 'cl3b'));
                $sections[] = $this->partsWearSection($device['device_key'], $d, $seed);
                $sections[] = $this->section('نحوه استفاده درست از این چک‌لیست‌ها', $this->paragraphsFrom($maintenance, 2, $seed . 'cl5'));
                break;

            case 'case_study': // مطالعه موردی
                $sections[] = $this->section('شرح ماجرا: ورود ' . $d . ' به میز عیب‌یابی', $this->paragraphsFrom($issues, 3, $seed . 'cs1'));
                $sections[] = $this->diagnosticSection($device['device_key'], $d, $seed);
                $sections[] = $this->section('تشخیص نهایی و علت ریشه‌ای', $this->paragraphsFrom($issues, 3, $seed . 'cs3'));
                $sections[] = $this->section('مسیر تعمیر؛ قطعات، مراحل و تست‌ها', $this->numberedSteps($maintenance, $seed . 'cs4') . $this->paragraphsFrom($maintenance, 2, $seed . 'cs4b'));
                $sections[] = $this->section('درس‌های این پرونده برای سایر کاربران', $this->bullets($usage, 4, $seed . 'cs5', "درس کلیدی") . $this->paragraphsFrom($usage, 2, $seed . 'cs5b'));
                break;

            case 'glossary': // واژه‌نامه تخصصی
                $sections[] = $this->section('اصطلاحات پایه دنیای ' . $d, $this->paragraphsFrom($usage, 3, $seed . 'gl1'));
                $sections[] = $this->section('قطعات اصلی و نقش هر یک', $this->bullets($maintenance, 7, $seed . 'gl2', "قطعه و نقش") . $this->paragraphsFrom($maintenance, 2, $seed . 'gl2b'));
                $sections[] = $this->section('اصطلاحات فنی دفترچه راهنما به زبان ساده', $this->paragraphsFrom($usage, 3, $seed . 'gl3'));
                $sections[] = $this->section('پرمخاطب‌ترین سوالات واژگانی کاربران', $this->paragraphsFrom($issues, 3, $seed . 'gl4'));
                break;

            case 'history_evolution': // تاریخچه و تکامل
                $sections[] = $this->section('خاستگاه: ' . $d . ' چگونه متولد شد؟', $this->paragraphsFrom($usage, 3, $seed . 'he1'));
                $sections[] = $this->section('نقاط عطف توسعه فناوری ' . $d, $this->bullets($usage, 6, $seed . 'he2', "نقطه عطف") . $this->paragraphsFrom($usage, 2, $seed . 'he2b'));
                $sections[] = $this->section('نسل امروزی ' . $d . ' ' . $b . ' چه تفاوتی با گذشتگان دارد؟', $this->paragraphsFrom($maintenance, 3, $seed . 'he3'));
                $sections[] = $this->section('روندهای آینده؛ چه چیزهایی در راه است؟', $this->paragraphsFrom($issues, 2, $seed . 'he4'));
                break;

            case 'expert_tips': // نکات خبرگان
                $sections[] = $this->section('نکاتی که تکنسین‌های باتجربه به همه می‌گویند', $this->bullets($maintenance, 6, $seed . 'et1', "نکته حرفه‌ای") . $this->paragraphsFrom($maintenance, 2, $seed . 'et1b'));
                $sections[] = $this->section('ترفندهای کمترشنیده درباره ' . $d . ' ' . $b, $this->paragraphsFrom($usage, 3, $seed . 'et2'));
                $sections[] = $this->section('عادت‌های کوچکی که عمر دستگاه را دو برابر می‌کند', $this->numberedSteps($maintenance, $seed . 'et3') . $this->paragraphsFrom($maintenance, 2, $seed . 'et3b'));
                $sections[] = $this->section('از زبان متخصصان: اشتباهاتی که بیشترین هزینه را دارند', $this->paragraphsFrom($issues, 3, $seed . 'et4'));
                break;

            case 'symptom_focus': // علامت‌محور
                $sections[] = $this->section('علامت اصلی و چرایی اهمیت آن', $this->paragraphsFrom($issues, 3, $seed . 'sf1'));
                $sections[] = $this->diagnosticSection($device['device_key'], $d, $seed);
                $sections[] = $this->section('علت‌های محتمل به ترتیب احتمال', $this->bullets($issues, 6, $seed . 'sf3', "علت محتمل") . $this->paragraphsFrom($issues, 2, $seed . 'sf3b'));
                $sections[] = $this->section('اقدامات فوری که همین حالا می‌توانید انجام دهید', $this->numberedSteps($usage, $seed . 'sf4') . $this->paragraphsFrom($usage, 2, $seed . 'sf4b'));
                $sections[] = $this->section('چه زمانی علامت به معنی تعمیر فوری است؟', $this->paragraphsFrom($maintenance, 3, $seed . 'sf5'));
                break;

            case 'statistics': // آمار و ارقام
                $sections[] = $this->section('نگاه آماری به بازار ' . $d . ' در ایران', $this->paragraphsFrom($usage, 3, $seed . 'st1'));
                $sections[] = $this->section('شایع‌ترین ایرادهای ثبت‌شده ' . $d . ' در اعداد', $this->bullets($issues, 5, $seed . 'st2', "آمار ایراد") . $this->paragraphsFrom($issues, 2, $seed . 'st2b'));
                $sections[] = $this->section('عمر مفید واقعی در برابر عمر تبلیغاتی', $this->paragraphsFrom($maintenance, 3, $seed . 'st3'));
                $sections[] = $this->section('هزینه مالکیت در بازه پنج‌ساله؛ محاسبه واقعی', $this->paragraphsFrom($issues, 2, $seed . 'st4'));
                break;

            case 'environment': // محیط زیست و بازیافت
                $sections[] = $this->section('اثر زیست‌محیطی ' . $d . '؛ نگاه مسئولانه', $this->paragraphsFrom($usage, 3, $seed . 'en1'));
                $sections[] = $this->section('مصرف انرژی و راه‌های کاهش آن', $this->bullets($maintenance, 6, $seed . 'en2', "اقدام سبز") . $this->paragraphsFrom($maintenance, 2, $seed . 'en2b'));
                $sections[] = $this->section('گاز مبرد و مسئولیت مشترک ما', $this->paragraphsFrom($issues, 3, $seed . 'en3'));
                $sections[] = $this->section('بازیافت درست قطعات در پایان عمر مفید', $this->paragraphsFrom($maintenance, 2, $seed . 'en4'));
                break;

            case 'service_process': // فرآیند تعمیر در نمایندگی
                $sections[] = $this->section('از ثبت درخواست تا پذیرش؛ گام‌های اول', $this->paragraphsFrom($usage, 3, $seed . 'sp1'));
                $sections[] = $this->section('پشت صحنه عیب‌یابی تخصصی ' . $d, $this->numberedSteps($issues, $seed . 'sp2') . $this->paragraphsFrom($issues, 2, $seed . 'sp2b'));
                $sections[] = $this->section('اعتبارسنجی و شفافیت قطعات یدکی', $this->bullets($maintenance, 5, $seed . 'sp3', "استاندارد کیفیت") . $this->paragraphsFrom($maintenance, 2, $seed . 'sp3b'));
                $sections[] = $this->section('تحویل، تست نهایی و گارانتی تعمیر', $this->paragraphsFrom($issues, 3, $seed . 'sp4'));
                break;
        }

        // 🎁 بخش عمومی مشترک برای همه انواع مقاله
        $sections[] = $this->section(
            'چرا انتخاب نمایندگی معتبر مهم است؟',
            $this->paragraphsFrom($usage, 3, $seed . 'z1')
        );
        // نتیجه‌گیری نهایی در متد generate() اضافه می‌شود — اینجا فقط بخش‌های بدنه

        return array_filter($sections);
    }

    /**
     * ✂️ استخراج عبارت موضوع از عنوان دلخواه (v3.3)
     * «تعمیر برد ماشین لباسشویی سامسونگ؛ راهنمای جامع» → «تعمیر برد ماشین لباسشویی سامسونگ»
     */
    private function subjectFromTitle(string $title): string
    {
        $s = trim(preg_replace('/[؛;].*$/u', '', $title)); // حذف زیرعنوان بعد از «؛»
        $s = trim(preg_replace('/\s*(?:؛|—|–|\|)\s*.*$/u', '', $s));
        foreach (['؛ راهنمای جامع و کاربردی', '؛ نکات مهم و راه‌حل‌های عملی', '؛ هر آنچه باید بدانید'] as $suf) {
            $s = str_replace($suf, '', $s);
        }
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        if (mb_strlen($s) >= 6 && mb_strlen($s) <= 90) {
            return $s;
        }
        return mb_strlen($title) >= 6 && mb_strlen($title) <= 90 ? trim($title) : '';
    }

    /**
     * 🎯 ساخت Outline موضوع‌محور (v3.3) — بدنه مقاله دقیقاً حول موضوع درخواستی
     *
     * تفاوت با buildOutline قالبی:
     *   - سرفصل‌ها حول خود موضوع (نه دستگاه عمومی)
     *   - فکت‌های تحقیق وب داخل نثر بخش‌ها ادغام می‌شوند (نه لیست خام)
     *   - پرسش‌های واقعی کاربران به زیربخش‌های H3 با پاسخ تبدیل می‌شوند
     *   - دانش دستگاه فقط نقش پشتیبان دارد
     */
    private function buildTopicOutline(string $subject, string $topicType, array $device, array $deviceKnowledge, array $vars, string $seed, ?array $webResearch): array
    {
        $d = $vars['device_fa'];
        $b = $vars['brand_fa'];
        $issues = (array)($deviceKnowledge['common_issues'] ?? []);
        $maintenance = (array)($deviceKnowledge['maintenance_tips'] ?? []);
        $usage = (array)($deviceKnowledge['usage_tips'] ?? []);
        $facts = array_slice((array)($webResearch['facts'] ?? []), 0, 6);
        $questions = array_slice((array)($webResearch['questions'] ?? []), 0, 4);
        $sections = [];

        /* ۱) تعریف و اهمیت موضوع — با فکت‌های واقعی وب در نثر */
        $def = [];
        $def[] = $subject . ' یکی از موضوع‌های پرتکرار برای کاربران ' . ($d !== '' ? $d : 'لوازم خانگی') . ' است و شناخت دقیق آن، هم از هزینه‌های غیرضروری جلوگیری می‌کند و هم عمر مفید دستگاه را بالا می‌برد.';
        if ($facts) {
            $def[] = 'بر اساس بررسی منابع آنلاین به‌روز، ' . $this->weaveFactsIntoProse($facts, 2);
        }
        $def[] = 'در این راهنما، همه ابعاد ' . $subject . ' را از علت‌شناسی تا راه‌حل‌های عملی و هزینه‌ها مرور می‌کنیم تا با خیال راحت تصمیم بگیرید.';
        $sections[] = $this->section($subject . ' چیست و چرا اهمیت دارد؟', '<p>' . implode('</p>' . "\n" . '<p>', $def) . '</p>');

        /* ۲) علت‌شناسی — سه سطح: از وب، از دانش فنی، از تجربه میدانی */
        $causeParts = [];
        if ($facts) {
            $rest = array_slice($facts, 2, 2);
            if ($rest) {
                $causeParts[] = '<p>آنچه منابع تخصصی جدیدتر نشان می‌دهند:' . "\n" . '<ul>' . "\n";
                foreach ($rest as $f) {
                    $causeParts[] = '<li>' . e((string)$f) . '</li>' . "\n";
                }
                $causeParts[] = '</ul></p>';
            }
        }
        $causeParts[] = $this->paragraphsFrom(array_merge($issues, $maintenance), 3, $seed . '|tcause');
        $sections[] = $this->section('علت‌های اصلی و زمینه‌ساز ' . $subject, implode("\n", $causeParts));

        /* ۳) راه‌حل‌های عملی گام‌به‌گام — مراحل شماره‌دار */
        $steps = array_merge(
            array_slice($usage, 0, 3),
            array_slice($maintenance, 0, 2)
        );
        $sol = '<p>برای رسیدن به نتیجه مطمئن در ' . $subject . '، این مسیر پیشنهاد می‌شود:</p>' . "\n";
        $sol .= $this->numberedSteps($steps ?: $issues, $seed . '|tsteps');
        $sol .= $this->paragraphsFrom($maintenance, 2, $seed . '|tsol');
        $sections[] = $this->section('راه‌حل‌های عملی ' . $subject . '؛ گام‌به‌گام', $sol);

        /* ۴) پرسش‌های واقعی کاربران وب → زیربخش H3 با پاسخ تحلیلی */
        if ($questions) {
            $qHtml = '<p>پرسش‌هایی که کاربران واقعی در جستجوهای خود مطرح کرده‌اند و پاسخ تحلیلی هر یک:</p>' . "\n";
            foreach ($questions as $q) {
                $q = trim((string)$q);
                if ($q === '' || mb_strlen($q) > 120) { continue; }
                $qHtml .= '<h3>' . e($q) . '</h3>' . "\n" . '<p>' . $this->answerQuestionFromContext($q, $facts, $subject, $d, $b) . '</p>' . "\n";
            }
            $sections[] = $this->section('پرسش‌های واقعی کاربران درباره ' . $subject, $qHtml);
        }

        /* ۵) هزینه، زمان و ملاحظات تصمیم‌گیری */
        $cost = (string)($deviceKnowledge['avg_repair_cost_range'] ?? '');
        $costHtml = '<p>';
        if ($cost !== '') {
            $costHtml .= 'بازه معمول هزینه در خدمات تخصصی مرتبط با ' . $subject . ' حدود ' . e($cost) . ' است؛ ';
        }
        $costHtml .= 'قیمت نهایی به مدل دستگاه، میزان خرابی و قیمت قطعه بستگی دارد و پیش از شروع کار باید به‌صورت شفاف اعلام شود. ';
        $costHtml .= 'معیار درست تصمیم‌گیری، مقایسه هزینه تعمیر با ارزش فعلی دستگاه و احتمال خرابی‌های ثانویه است، نه فقط رقم اولیه.</p>';
        $costHtml .= $this->paragraphsFrom($issues, 2, $seed . '|tcost');
        $sections[] = $this->section('هزینه و زمان ' . $subject . '؛ چه انتظاری داشته باشید؟', $costHtml);

        /* ۶) پیشگیری و نگهداری */
        $sections[] = $this->section('پیشگیری؛ چطور دوباره به این وضعیت برنگردید؟', $this->bullets($maintenance, 5, $seed . '|tprev', 'اقدام پیشگیرانه') . $this->paragraphsFrom($maintenance, 2, $seed . '|tprevb'));

        /* ۷) بخش عمومی مشترک */
        $sections[] = $this->section('چرا انتخاب نمایندگی معتبر مهم است؟', $this->paragraphsFrom($usage, 3, $seed . '|tz1'));

        return array_filter($sections);
    }

    /**
     * 🧵 بافتن فکت‌های وب در نثر (v3.3) — به‌جای لیست خام، جمله‌های روان
     */
    private function weaveFactsIntoProse(array $facts, int $max = 2): string
    {
        $parts = [];
        foreach (array_slice($facts, 0, $max) as $i => $f) {
            $f = trim((string)$f);
            if ($f === '' || mb_strlen($f) < 10) { continue; }
            $f = rtrim($f, '.؛،');
            if ($i === 0) {
                $parts[] = 'بر این اساس، ' . $f . ' است.';
            } else {
                $parts[] = 'همچنین ' . $f . ' گزارش شده است.';
            }
        }
        if ($parts) {
            return implode(' ', $parts);
        }
        return isset($facts[0]) ? e(trim((string)$facts[0])) : '';
    }

    /**
     * 💬 پاسخ تحلیلی به پرسش واقعی کاربر — از فکت‌های وب + دانش زمینه (v3.3)
     */
    private function answerQuestionFromContext(string $question, array $facts, string $subject, string $deviceFa, string $brandFa): string
    {
        $qNorm = TextProcessor::normalize(mb_strtolower($question));
        $best = '';
        $bestScore = 0;
        foreach ($facts as $f) {
            $fNorm = TextProcessor::normalize(mb_strtolower((string)$f));
            if ($fNorm === '') { continue; }
            $score = 0;
            foreach (preg_split('/\s+/u', $qNorm) ?: [] as $w) {
                if (mb_strlen($w) >= 4 && mb_strpos($fNorm, $w) !== false) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = trim((string)$f);
            }
        }
        $parts = [];
        if ($bestScore > 0 && $best !== '') {
            $parts[] = 'بر اساس داده‌های آنلاین مرتبط، ' . rtrim($best, '.؛،') . '.';
        } else {
            $parts[] = 'پاسخ کوتاه: بله، این مورد با ' . $subject . ' ارتباط مستقیم دارد و باید در کنار سایر نشانه‌ها ارزیابی شود.';
        }
        $parts[] = 'برای بررسی دقیق' . ($deviceFa !== '' ? ' روی ' . $deviceFa : '') . '، توصیه می‌کنیم ابتدا راه‌حل‌های بخش قبل را انجام دهید و در صورت تکرار مشکل، از مشاوره کارشناس استفاده کنید.';
        return implode(' ', $parts);
    }

    /**
     * ✍️ مقدمه موضوع‌محور (v3.3) — روان و مستقیم، بدون قالب کلیشه‌ای
     */
    private function topicIntro(string $subject, array $vars, ?array $webResearch, string $seed): string
    {
        $d = $vars['device_fa'];
        $b = $vars['brand_fa'];
        $openers = [
            'اگر با موضوع «' . $subject . '» مواجه شده‌اید، احتمالاً همین حالا دنبال پاسخی روشن و قابل اتکا هستید.',
            '«' . $subject . '» دقیقاً همان موضوعی است که این راهنما برای آن نوشته شده است.',
            'در این مقاله، ' . $subject . ' را از زبان تیم فنی و بر اساس داده‌های به‌روز بررسی می‌کنیم.',
        ];
        $mids = [];
        if ($webResearch && !empty($webResearch['facts'])) {
            $mids[] = 'برای تهیه این محتوا، منابع آنلاین معتبر نیز بررسی شده تا داده‌های تازه لحاظ شود.';
        }
        $mids[] = 'آنچه در ادامه می‌خوانید' . ($d !== '' ? ' با تمرکز روی ' . $d : '') . '، از علت‌شناسی شروع می‌شود و تا راه‌حل، هزینه و پیشگیری ادامه می‌یابد.';
        $closers = [
            'تا انتها همراه بمانید؛ چند نکته کلیدی هست که اگر همین حالا بدانید، از هزینه‌های بزرگ‌تر جلوگیری می‌کند.',
            'اگر عجله دارید، فهرست مطالب ابتدای مقاله مسیر سریعی به بخش موردنظرتان می‌دهد.',
        ];
        return $openers[crc32($seed) % 3] . ' ' . implode(' ', $mids) . ' ' . $closers[(crc32($seed) >> 3) % 2];
    }

    /**
     * ✍️ جمع‌بندی موضوع‌محور (v3.3)
     */
    private function topicConclusion(string $subject, array $vars, string $seed): string
    {
        $b = $vars['brand_fa'];
        $agency = $vars['agency'] ?? 'سهند سرویس';
        $parts = [
            $subject . ' زمانی به نتیجه مطمئن می‌رسد که علت واقعی شناسایی شود، نه فقط نشانه‌ها.',
            'در این راهنما دیدیم که ترکیب بررسی‌های اولیه کاربر با تشخیص تخصصی، هم زمان را کوتاه می‌کند و هم از خرابی‌های ثانویه جلوگیری می‌کند.',
            'اگر پس از اجرای راه‌حل‌های مطرح‌شده مشکل ادامه داشت، ادامه استفاده از دستگاه توصیه نمی‌شود؛',
            'تیم فنی ' . ($b !== '' ? $b . ' و ' : '') . $agency . ' با بازدید و تشخیص دقیق، مسیر درست را مشخص می‌کند.',
        ];
        return implode(' ', $parts);
    }

    /**
     * 📄 ساخت یک بخش مقاله
     */
    private function section(string $title, string $content): ?array
    {
        if (trim($content) === '') {
            return null;
        }
        return ['title' => $title, 'content' => $content];
    }

    /**
     * 📝 تولید پاراگراف از دانش دستگاه با تنوع‌سازی
     * هر آیتم دانش با ۲ شکل توسعه‌یافته به چند جمله تبدیل می‌شود
     */
    private function paragraphsFrom(array $knowledge, int $count, string $seed): string
    {
        if (empty($knowledge)) {
            return '';
        }
        $templates = TextProcessor::loadKnowledge('templates');
        $shapes = $templates['knowledge_expansion'] ?? ['{{knowledge}}'];
        $parts = [];
        $picked = TextProcessor::seededPickMany($knowledge, $count, $seed);
        foreach ($picked as $item) {
            // هر آیتم دانش با ۲ شکل متفاوت گسترش می‌یابد تا حجم محتوا کامل شود
            $itemShapes = TextProcessor::seededPickMany($shapes, 2, $seed . '|exp|' . md5((string)$item));
            foreach ($itemShapes as $shape) {
                $text = strtr($shape, ['{{knowledge}}' => (string)$item]);
                // تنوع‌سازی دو لایه: عبارت + مترادف
                $text = TextProcessor::applyPhrases($text, $seed . '|phr' . md5($text));
                $text = TextProcessor::applySynonyms($text, $seed . '|' . md5((string)$item . $shape));
                $parts[] = TextProcessor::normalize($text);
            }
        }
        // گروه‌بندی جمله‌ها در ۱-۲ پاراگراف
        return '<p>' . implode(' ', $parts) . '</p>';
    }

    /**
     * 🩺 بخش عیب‌یابی هوشمند علامت‌محور — از diagnostics.json
     * جدول علامت → علت محتمل (مرتب بر اساس احتمال) + اقدام فوری
     */
    private function diagnosticSection(string $deviceKey, string $deviceFa, string $seed): ?array
    {
        $scenarios = KnowledgeBase::diagnostics($deviceKey);
        if (empty($scenarios)) {
            return null;
        }
        $scenario = TextProcessor::seededPick($scenarios, $seed . '|diag');
        if (!$scenario) {
            return null;
        }

        // مرتب‌سازی علت‌ها بر اساس احتمال نزولی
        $causes = $scenario['causes'] ?? [];
        usort($causes, function ($a, $b) {
            return ($b['probability'] ?? 0) <=> ($a['probability'] ?? 0);
        });

        $html = '<p>اگر با علامت «' . e($scenario['symptom']) . '» مواجه هستید، این جدول علت‌های محتمل را به ترتیب احتمال نشان می‌دهد:</p>' . "\n";
        $html .= '<table><thead><tr><th>علت محتمل</th><th>احتمال</th><th>روش بررسی</th></tr></thead><tbody>' . "\n";
        foreach (array_slice($causes, 0, 4) as $cause) {
            $percent = (int)round(($cause['probability'] ?? 0) * 100);
            $html .= '<tr><td>' . e($cause['cause']) . '</td><td>' . $percent . '٪</td><td>'
                . e($cause['check'] ?? '') . '</td></tr>' . "\n";
        }
        $html .= '</tbody></table>' . "\n";

        // اقدام‌های فوری
        if (!empty($scenario['immediate_actions'])) {
            $html .= '<p><strong>قبل از تماس با تعمیرکار این کارها را بکنید:</strong></p>' . "\n" . '<ul>' . "\n";
            foreach (array_slice($scenario['immediate_actions'], 0, 4) as $action) {
                $html .= '<li>' . e($action) . '</li>' . "\n";
            }
            $html .= '</ul>';
        }

        return $this->section('عیب‌یابی هوشمند: ' . $scenario['symptom'], $html);
    }

    /**
     * 🔧 بخش قطعات مصرفی دستگاه — از parts_wear در devices.json
     */
    private function partsWearSection(string $deviceKey, string $deviceFa, string $seed): ?array
    {
        $parts = KnowledgeBase::wearableParts($deviceKey);
        if (empty($parts)) {
            return null;
        }
        $html = '<table><thead><tr><th>قطعه مصرفی</th><th>دوره بازدید/تعویض</th></tr></thead><tbody>' . "\n";
        foreach (array_slice($parts, 0, 5) as $part) {
            if (!is_array($part) || empty($part['part'])) {
                continue;
            }
            $html .= '<tr><td>' . e($part['part']) . '</td><td>' . e($part['interval'] ?? '') . '</td></tr>' . "\n";
        }
        $html .= '</tbody></table>' . "\n";
        $html .= '<p>رعایت زمان‌بندی تعویض این اقلام، از بیش از نیمی از خرابی‌های ناگهانی جلوگیری می‌کند.</p>';
        return $this->section('جدول قطعات مصرفی ' . $deviceFa, $html);
    }

    /**
     * 💡 بخش نکات ریست — از reset_tip در error-codes.json
     */
    private function resetTipsSection(string $deviceKey): string
    {
        $errors = TextProcessor::loadKnowledge('error-codes')[$deviceKey] ?? [];
        if (empty($errors)) {
            return '';
        }
        $html = '';
        foreach (array_slice($errors, 0, 3) as $error) {
            if (!empty($error['reset_tip'])) {
                $html .= '<p><strong>خطای ' . e($error['code']) . ':</strong> ' . e($error['reset_tip']) . '</p>' . "\n";
            }
        }
        return $html;
    }

    /**
     * • تولید لیست گلوله‌ای از دانش
     */
    private function bullets(array $knowledge, int $count, string $seed, string $prefix): string
    {
        if (empty($knowledge)) {
            return '';
        }
        $items = TextProcessor::seededPickMany($knowledge, $count, $seed);
        $html = '<ul>' . "\n";
        foreach ($items as $item) {
            $html .= '<li>' . e(TextProcessor::applySynonyms((string)$item, $seed . md5((string)$item))) . '</li>' . "\n";
        }
        return $html . '</ul>';
    }

    /**
     * ۱️⃣ تولید مراحل شماره‌دار
     */
    private function numberedSteps(array $knowledge, string $seed): string
    {
        if (empty($knowledge)) {
            return '';
        }
        $items = TextProcessor::seededPickMany($knowledge, min(5, count($knowledge)), $seed);
        $html = '<ol>' . "\n";
        foreach ($items as $item) {
            $html .= '<li>' . e((string)$item) . '</li>' . "\n";
        }
        return $html . '</ol>';
    }

    /**
     * 🚨 جدول کدهای خطا از دانش مرجع
     */
    private function errorCodeTable(string $deviceKey): string
    {
        $errorKnowledge = TextProcessor::loadKnowledge('error-codes');
        $codes = $errorKnowledge[$deviceKey] ?? [];
        if (empty($codes)) {
            return '';
        }
        $rows = '';
        foreach (array_slice($codes, 0, 6) as $code) {
            $rows .= '<tr><td><strong>' . e($code['code']) . '</strong></td>'
                . '<td>' . e($code['title']) . '</td></tr>' . "\n";
        }
        return '<table><thead><tr><th>کد خطا</th><th>شرح خطا</th></tr></thead><tbody>' . "\n" . $rows . '</tbody></table>';
    }

    /**
     * 🔗 افزودن لینک‌های داخلی به محتوای مقاله
     */
    private function addInternalLinks(string $content, array $brand, array $device): string
    {
        // لینک به صفحه خدمات در اولین اشاره به دستگاه
        $deviceName = $device['name_fa'] ?? '';
        if ($deviceName !== '' && mb_strpos($content, $deviceName) !== false) {
            $link = '<a href="/services">' . e($deviceName) . '</a>';
            $content = preg_replace('/' . preg_quote($deviceName, '/') . '/u', $link, $content, 1) ?? $content;
        }
        // لینک به صفحه ثبت درخواست در کلمه «تماس» یا مشابه
        $ctaTargets = ['تماس با تعمیرکار', 'تماس با ما', 'ثبت درخواست'];
        foreach ($ctaTargets as $target) {
            if (mb_strpos($content, $target) !== false) {
                $link = '<a href="/request">' . e($target) . '</a>';
                $content = preg_replace('/' . preg_quote($target, '/') . '/u', $link, $content, 1) ?? $content;
                break;
            }
        }
        return $content;
    }

    /**
     * 🎯 استخراج کلیدواژه کانونی از عنوان دلخواه کاربر (فاز Q.5)
     */
    private function focusFromTitle(string $title, string $deviceName): string
    {
        // حذف علائم و حشوها
        $clean = trim(preg_replace('/[؟?!؛،.:\[\]()«»-]+/u', ' ', $title) ?? $title);
        $fillers = ['چگونه', 'چطور', 'راهنمای کامل', 'آموزش کامل', 'راهنمای جامع', 'همه آنچه', 'همه چیز'];
        foreach ($fillers as $f) {
            $clean = preg_replace('/(?<![\p{L}])' . preg_quote($f, '/') . '(?![\p{L}])/u', ' ', $clean) ?? $clean;
        }
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? $clean);

        // اگر نام دستگاه در عنوان است، محور ترکیبی دستگاه + واژه‌های کلیدی
        $words = array_slice(preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 4);
        $focus = implode(' ', $words);
        if ($focus === '') {
            return 'تعمیر ' . $deviceName;
        }
        return $focus;
    }

    /**
     * 🏷️ نگاشت نوع موضوع به دسته‌بندی
     */
    private function categoryForTopic(string $topicType): int
    {
        $map = [
            'troubleshooting'    => 1, // رفع ایراد
            'user_guide'         => 2, // راهنمای استفاده
            'maintenance'        => 3, // نگهداری
            'diagnostics'        => 4, // عیب‌یابی
            'comparison'         => 5, // عمومی
            'error_codes'        => 4, // عیب‌یابی
            'seasonal_care'      => 3, // نگهداری
            'cost_guide'         => 5, // عمومی
            // 🆕 فاز Q.4
            'safety_guide'       => 3,
            'installation_guide' => 2,
            'diy_vs_pro'         => 5,
            'common_mistakes'    => 2,
            'warranty_guide'     => 5,
            'tech_explainer'     => 5,
            'myths_facts'        => 5,
            'checklist'          => 3,
            'case_study'         => 4,
            'glossary'           => 5,
            'history_evolution'  => 5,
            'expert_tips'        => 3,
            'symptom_focus'      => 1,
            'statistics'         => 5,
            'environment'        => 5,
            'service_process'    => 5,
        ];
        return $map[$topicType] ?? 5;
    }

    /**
     * 📚 دریافت موضوعات پیشنهادی برای مقاله بعدی برند
     */
    public function suggestTopics(array $brand, int $count = 5): array
    {
        $devices = $this->db->fetchAll(
            'SELECT device_key, name_fa FROM brand_devices WHERE brand_id = ? AND is_active = 1',
            [$brand['id']]
        );
        $types = [
            'troubleshooting', 'user_guide', 'maintenance', 'comparison', 'error_codes',
            'buying_guide', 'energy_saving', 'seasonal_care', 'cost_guide',
            'safety_guide', 'installation_guide', 'diy_vs_pro', 'common_mistakes', 'warranty_guide',
            'tech_explainer', 'myths_facts', 'checklist', 'case_study', 'glossary',
            'history_evolution', 'expert_tips', 'symptom_focus', 'statistics', 'environment', 'service_process',
        ];
        $suggestions = [];
        $i = 0;
        while (count($suggestions) < $count && $i < 30) {
            $type = $types[$i % count($types)];
            $device = $devices[$i % max(1, count($devices))] ?? null;
            if ($device) {
                $existing = $this->db->count('brand_articles', 'brand_id = ? AND title LIKE ?', [$brand['id'], '%' . $device['name_fa'] . '%']);
                if ($existing < 3) { // حداکثر ۳ مقاله برای هر دستگاه
                    $suggestions[] = [
                        'type'   => $type,
                        'device' => $device['device_key'],
                        'device_name' => $device['name_fa'],
                        'title'  => $this->topicTitle($type, $device['name_fa'], $brand['name_fa']),
                    ];
                }
            }
            $i++;
        }
        return $suggestions;
    }

    /**
     * 📝 عنوان پیشنهادی موضوع
     */
    private function topicTitle(string $type, string $device, string $brand): string
    {
        $map = [
            'troubleshooting'    => 'علت و راه‌حل مشکلات رایج',
            'user_guide'         => 'آموزش کامل استفاده',
            'maintenance'        => 'نکات مهم نگهداری',
            'comparison'         => 'مقایسه مدل‌های',
            'error_codes'        => 'کدهای خطای رایج',
            'buying_guide'       => 'راهنمای خرید',
            'energy_saving'      => 'صرفه‌جویی در مصرف انرژی',
            'seasonal_care'      => 'مراقبت فصلی و آماده‌سازی',
            'cost_guide'         => 'راهنمای هزینه تعمیر و تصمیم درست',
            // 🆕 فاز Q.4
            'safety_guide'       => 'نکات ایمنی کار با',
            'installation_guide' => 'راهنمای نصب و راه‌اندازی',
            'diy_vs_pro'         => 'تعمیر شخصی یا تخصصی',
            'common_mistakes'    => 'اشتباهات رایج در استفاده از',
            'warranty_guide'     => 'راهنمای گارانتی',
            'tech_explainer'     => 'فناوری‌های به‌کاررفته در',
            'myths_facts'        => 'باورهای غلط درباره',
            'checklist'          => 'چک‌لیست کامل',
            'case_study'         => 'مطالعه موردی تعمیر',
            'glossary'           => 'واژه‌نامه تخصصی',
            'history_evolution'  => 'تاریخچه و تکامل',
            'expert_tips'        => 'نکات حرفه‌ای درباره',
            'symptom_focus'      => 'عیب‌یابی علامت‌محور',
            'statistics'         => 'آمار و ارقام',
            'environment'        => 'نگاه زیست‌محیطی به',
            'service_process'    => 'فرآیند تعمیر در نمایندگی',
        ];
        return $device . ' ' . $brand . ' — ' . ($map[$type] ?? 'راهنمای جامع');
    }

    /* ==================================================
     * 🆕 متدهای کمکی نسخه ۲ (v2.0)
     * ================================================== */

    /**
     * 📌 جعبه «نکات کلیدی» (TL;DR) — درج بعد از مقدمه
     * ۳ نکته فشرده از سرفصل‌های مقاله
     */
    private function addKeyTakeaways(string $content, array $sections, array $vars, string $seed): string
    {
        if (count($sections) < 2) {
            return $content;
        }
        $templates = TextProcessor::loadKnowledge('templates');
        $shapes = $templates['takeaway_shapes'] ?? [
            'نکته کلیدی: {{point}}',
            'مهم: {{point}}',
            '{{point}}',
        ];

        // انتخاب ۳ سرفصل به عنوان نکات کلیدی
        $picks = TextProcessor::seededPickMany(
            array_column(array_slice($sections, 0, max(2, count($sections) - 1)), 'title'),
            3,
            $seed . '|tldr'
        );
        if (empty($picks)) {
            return $content;
        }

        $items = '';
        foreach ($picks as $pick) {
            $shape = TextProcessor::seededPick($shapes, $seed . '|shape' . md5((string)$pick));
            $point = strtr($shape, ['{{point}}' => (string)$pick]);
            $items .= '<li>' . e($point) . '</li>' . "\n";
        }
        $box = '<div class="key-takeaways">' . "\n"
            . '<strong>📌 نکات کلیدی این مقاله:</strong>' . "\n"
            . '<ul>' . "\n" . $items . '</ul>' . "\n"
            . '</div>' . "\n";

        // درج بعد از اولین پاراگراف (مقدمه)
        $firstClose = mb_strpos($content, '</p>');
        if ($firstClose === false) {
            return $box . $content;
        }
        $insertAt = $firstClose + 4;
        return mb_substr($content, 0, $insertAt) . "\n" . $box . mb_substr($content, $insertAt);
    }

    /**
     * ❓ تولید ۲-۳ پرسش و پاسخ متداول اختصاصی مقاله
     * از قالب‌های FAQ پایگاه دانش + متغیرهای برند
     */
    private function articleFaq(array $vars, string $topicType, string $seed, string $subject = '', ?array $webResearch = null): array
    {
        $faqs = [];

        /* 🎯 v3.3: پرسش‌های واقعی وب درباره همین موضوع — دقیق‌ترین FAQ ممکن */
        if ($subject !== '' && $webResearch !== null) {
            foreach (array_slice((array)($webResearch['questions'] ?? []), 0, 2) as $wq) {
                $wq = trim((string)$wq);
                if ($wq === '' || mb_strlen($wq) > 120) { continue; }
                $faqs[] = [
                    'question' => TextProcessor::normalize($wq),
                    'answer'   => $this->answerQuestionFromContext($wq, (array)($webResearch['facts'] ?? []), $subject, (string)$vars['device_fa'], (string)$vars['brand_fa']),
                ];
            }
        }

        $templates = TextProcessor::loadKnowledge('templates');
        $faqTemplates = $templates['faq'] ?? [];
        if (empty($faqTemplates)) {
            return $faqs;
        }
        $picks = TextProcessor::seededPickMany($faqTemplates, 3, $seed . '|faq');
        foreach ($picks as $faq) {
            // ساختار دانش: ['q' => سوال, 'a' => پاسخ] (سازگار با question/answer هم هست)
            $questionRaw = is_array($faq) ? ($faq['q'] ?? $faq['question'] ?? '') : '';
            $answerRaw = is_array($faq) ? ($faq['a'] ?? $faq['answer'] ?? '') : '';
            if ($questionRaw === '' || $answerRaw === '') {
                continue;
            }
            $question = TextProcessor::fillTemplate($questionRaw, $vars);
            $answer = TextProcessor::fillTemplate($answerRaw, $vars);
            $answer = TextProcessor::applyPhrases($answer, $seed . '|faqphr' . md5($question), 0.4);
            $faqs[] = [
                'question' => TextProcessor::normalize($question),
                'answer'   => TextProcessor::normalize($answer),
            ];
        }
        return array_slice($faqs, 0, 5);
    }

    /**
     * 🔗 لینک‌دهی به مقالات مرتبط همین برند — سیلوی داخلی
     * ۲ مقاله مرتبط در انتهای محتوا (قبل از جمع‌بندی نهایی)
     */
    private function addRelatedArticleLinks(string $content, array $brand): string
    {
        $related = $this->db->fetchAll(
            'SELECT title, slug FROM brand_articles
             WHERE brand_id = ? AND status = "published"
             ORDER BY published_at DESC LIMIT 3',
            [$brand['id']]
        );
        if (count($related) < 1) {
            return $content;
        }
        $links = '';
        foreach (array_slice($related, 0, 2) as $article) {
            $links .= '<li><a href="/blog/' . e($article['slug']) . '">' . e($article['title']) . '</a></li>' . "\n";
        }
        $box = '<h3>مطالب مرتبط</h3>' . "\n" . '<ul>' . "\n" . $links . '</ul>' . "\n";

        // درج قبل از «جمع‌بندی»
        $pos = mb_strripos($content, '<h2>جمع‌بندی</h2>');
        if ($pos === false) {
            return $content . "\n" . $box;
        }
        return mb_substr($content, 0, $pos) . $box . "\n" . mb_substr($content, $pos);
    }

    /**
     * 🍂 بخش مقدمه فصلی — از تقویم فصلی پایگاه دانش (seasonal-calendar.json)
     */
    private function seasonalIntroSection(string $deviceFa, string $brandFa, string $seed): ?array
    {
        $season = KnowledgeBase::currentSeason();
        $label = $season['label'] ?? '';
        if ($label === '') {
            return null;
        }
        $focus = (array)($season['focus_devices'] ?? []);
        $tips = (array)($season['tips'] ?? []);

        $html = '<p>در ' . e($label) . '، میزان استقبال از سرویس ' . e($deviceFa) . ' به‌طور محسوسی تغییر می‌کند.';
        if (!empty($focus)) {
            $html .= ' طبق تجربه تعمیرگاه‌ها، در این ماه بیشتر روی «' . e(implode('» و «', array_slice($focus, 0, 3))) . '» تمرکز می‌شود.';
        }
        $html .= '</p>' . "\n";

        if (!empty($tips)) {
            $html .= '<p>' . e(implode(' ', array_slice($tips, 0, 2))) . '</p>' . "\n";
        }
        $html .= '<p>در ادامه، چک‌لیست کامل مراقبت فصلی از ' . e($deviceFa) . ' برند ' . e($brandFa) . ' را مرور می‌کنیم.</p>' . "\n";

        return $this->section('چرا مراقبت فصلی از ' . $deviceFa . ' مهم است؟', $html);
    }
}
