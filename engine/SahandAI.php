<?php
/**
 * 🤖 موتور هوش مصنوعی داخلی سهند — SahandAI Engine v1.0
 * ======================================================
 * موتور تولید محتوای فارسی کاملاً داخلی (بدون هیچ API خارجی)
 * بهینه‌شده برای زبان فارسی و صنعت تعمیرات لوازم خانگی.
 *
 * ساختار:
 *   📚 پایگاه دانش (engine/knowledge/*.json)
 *   ⚙️ موتور پردازش (generators + analyzers)
 *   🔌 API داخلی (routes در api/index.php)
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class SahandAI
{
    /** @var Database دیتابیس */
    private $db;

    /** @var ContentGenerator ژنراتور محتوای یکتا */
    private $contentGen;

    /** @var ArticleGenerator ژنراتور مقاله */
    private $articleGen;

    /** @var SeoGenerator ژنراتور سئو */
    private $seoGen;

    /** @var BrandInfoGenerator ژنراتور اطلاعات برند */
    private $brandGen;

    /** @var FaqGenerator ژنراتور FAQ */
    private $faqGen;

    /** @var ErrorCodeGenerator ژنراتور کد خطا */
    private $errorCodeGen;

    /** @var KeywordAnalyzer تحلیل‌گر کلیدواژه */
    private $keywordAnalyzer;

    /** @var SeoAnalyzer تحلیل‌گر سئو */
    private $seoAnalyzer;

    /** @var UniquenessChecker بررسی‌گر یکتایی */
    private $uniquenessChecker;

    public function __construct()
    {
        $this->db                = Database::getInstance();
        $this->contentGen        = new ContentGenerator();
        $this->articleGen        = new ArticleGenerator();
        $this->seoGen            = new SeoGenerator();
        $this->brandGen          = new BrandInfoGenerator();
        $this->faqGen            = new FaqGenerator();
        $this->errorCodeGen      = new ErrorCodeGenerator();
        $this->keywordAnalyzer   = new KeywordAnalyzer();
        $this->seoAnalyzer       = new SeoAnalyzer();
        $this->uniquenessChecker = new UniquenessChecker();
    }

    /* ==================================================
     * 🧩 API عمومی موتور (مطابق مستندات بخش ۶ سند)
     * ================================================== */

    /**
     * ✍️ تولید محتوای یکتا — POST /api/ai/generate-content
     *
     * @param string $type نوع محتوا (brand_intro|brand_history|agency_about|warranty|service_area|device_desc|services_intro)
     * @param array  $params پارامترها (brand_id, device_key, ...)
     */
    public function generateContent(string $type, array $params = []): array
    {
        $brandId = (int)($params['brand_id'] ?? 0);
        $brand = $this->db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
        if (!$brand) {
            throw new RuntimeException('برند یافت نشد.');
        }

        $devices = $this->db->fetchAll(
            'SELECT * FROM brand_devices WHERE brand_id = ? AND is_active = 1 ORDER BY sort_order',
            [$brandId]
        );

        // غنی‌سازی برند با دانش AI
        $knowledge = $this->brandGen->matchBrand($brand['name_fa'], $brand['name_en']);
        if ($knowledge) {
            $brand = array_merge($brand, $knowledge);
        }

        switch ($type) {
            case 'brand_intro':
                return $this->contentGen->brandIntro($brand, $devices, $brandId);
            case 'brand_history':
                return $this->contentGen->brandHistory($brand, $devices, $brandId);
            case 'agency_about':
                return $this->contentGen->agencyAbout($brand, $devices, $brandId);
            case 'warranty':
                return $this->contentGen->warranty($brand, $devices, $brandId);
            case 'service_area':
                $areas = $this->db->fetchAll('SELECT city FROM service_areas WHERE brand_id = ? OR brand_id IS NULL', [$brandId]);
                return $this->contentGen->serviceArea($brand, $areas, $brandId);
            case 'services_intro':
                return $this->contentGen->servicesIntro($brand, $devices, $brandId);
            case 'device_desc':
                $device = $this->db->fetch(
                    'SELECT * FROM brand_devices WHERE brand_id = ? AND device_key = ?',
                    [$brandId, (string)($params['device_key'] ?? '')]
                );
                if (!$device) {
                    throw new RuntimeException('دستگاه یافت نشد.');
                }
                return $this->contentGen->deviceDescription($brand, $device, $brandId);
            default:
                throw new RuntimeException('نوع محتوای نامعتبر: ' . $type);
        }
    }

    /**
     * 📰 تولید مقاله کامل — POST /api/ai/generate-article
     *
     * @param array $params [brand_id, topic_type, device_key, auto_save]
     */
    public function generateArticle(array $params): array
    {
        $brandId = (int)($params['brand_id'] ?? 0);
        $brand = $this->db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
        if (!$brand) {
            throw new RuntimeException('برند یافت نشد.');
        }

        $topicType = (string)($params['topic_type'] ?? 'troubleshooting');
        $deviceKey = $params['device_key'] ?? null;
        $article = $this->articleGen->generate($brand, $topicType, $deviceKey);

        // 💾 ذخیره خودکار در صورت درخواست
        if (!empty($params['auto_save'])) {
            $article['id'] = $this->saveArticle($brandId, $article, $topicType);
        }
        return $article;
    }

    /**
     * 🔍 تولید پکیج سئو — POST /api/ai/generate-seo
     */
    public function generateSeo(array $params): array
    {
        $content = (string)($params['content'] ?? '');
        $pageType = (string)($params['page_type'] ?? 'home');
        $brandId = (int)($params['brand_id'] ?? 0);

        $brand = $brandId ? $this->db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]) : null;
        $vars = [
            'brand_fa' => $brand['name_fa'] ?? '',
            'brand_en' => $brand['name_en'] ?? '',
            'agency'   => Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس',
            'brand_domain' => $brand['domain'] ?? '',
        ];
        return $this->seoGen->generateForPage($pageType, $content, $vars);
    }

    /**
     * 🏭 تولید کامل اطلاعات برند — POST /api/ai/generate-brand-info
     * برای برند جدید: محتوای همه صفحات + FAQ + کدهای خطا
     */
    public function generateBrandInfo(array $params): array
    {
        $brandId = (int)($params['brand_id'] ?? 0);
        $brand = $this->db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
        if (!$brand) {
            throw new RuntimeException('برند یافت نشد.');
        }

        $report = $this->brandGen->generateBrandPackage($brandId, $brand);

        // 🚨 تولید کدهای خطای رایج
        $report['error_codes_generated'] = $this->errorCodeGen->generateForBrand($brandId);

        return $report;
    }

    /**
     * ❓ تولید FAQ — POST /api/ai/generate-faq
     */
    public function generateFaq(array $params): array
    {
        $brandId = (int)($params['brand_id'] ?? 0);
        $brand = $this->db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
        if (!$brand) {
            throw new RuntimeException('برند یافت نشد.');
        }
        $devices = $this->db->fetchAll('SELECT * FROM brand_devices WHERE brand_id = ?', [$brandId]);
        $count = $this->faqGen->generateForBrand($brandId, $brand, $devices, (int)($params['count'] ?? 10));
        return ['generated' => $count, 'faqs' => $this->faqGen->forBrand($brandId)];
    }

    /**
     * 🔑 تحلیل کلیدواژه — POST /api/ai/analyze-keywords
     */
    public function analyzeKeywords(array $params): array
    {
        $text = (string)($params['text'] ?? '');
        $brandId = (int)($params['brand_id'] ?? 0);
        $result = [
            'keywords' => $this->keywordAnalyzer->extract($text, (int)($params['top'] ?? 20)),
            'phrases'  => $this->keywordAnalyzer->extractPhrases($text),
        ];
        if (!empty($params['focus_keyword'])) {
            $result['density'] = $this->keywordAnalyzer->density($text, (string)$params['focus_keyword']);
            $result['suggestions'] = $this->keywordAnalyzer->analyzeDensity($text, (string)$params['focus_keyword']);
        }
        if ($brandId) {
            $brand = $this->db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
            $devices = $this->db->fetchAll('SELECT name_fa FROM brand_devices WHERE brand_id = ?', [$brandId]);
            if ($brand) {
                $result['brand_suggestions'] = $this->keywordAnalyzer->suggestBrandKeywords($brand, $devices);
            }
        }
        return $result;
    }

    /**
     * ✅ بررسی یکتایی — POST /api/ai/check-uniqueness
     */
    public function checkUniqueness(array $params): array
    {
        return $this->uniquenessChecker->check(
            (string)($params['content'] ?? ''),
            (int)($params['brand_id'] ?? 0)
        );
    }

    /**
     * 💡 پیشنهاد بهبود — POST /api/ai/suggest-improvements
     * تحلیل سئو + کلیدواژه + یکتایی در یک فراخوانی
     */
    public function suggestImprovements(array $params): array
    {
        $content = (string)($params['content'] ?? '');
        $focusKeyword = (string)($params['focus_keyword'] ?? '');

        $seo = $this->seoAnalyzer->analyze($params, $focusKeyword);
        $unique = $this->uniquenessChecker->check($content, (int)($params['brand_id'] ?? 0));
        $keywords = $this->keywordAnalyzer->extract($content, 10);

        return [
            'seo'          => $seo,
            'uniqueness'   => $unique,
            'top_keywords' => $keywords,
            'priority_actions' => array_slice(array_column($seo['suggestions'], 'title'), 0, 5),
        ];
    }

    /**
     * 📚 لیست قالب‌های موجود — GET /api/ai/templates
     */
    public function templates(): array
    {
        $templates = TextProcessor::loadKnowledge('templates');
        $summary = [];
        foreach ($templates as $type => $list) {
            if (is_array($list)) {
                $summary[$type] = [
                    'count' => count($list),
                    'sample'=> is_string(reset($list)) ? excerpt((string)reset($list), 100) : null,
                ];
            }
        }
        return $summary;
    }

    /**
     * 📖 دانش یک برند — GET /api/ai/knowledge/{brand}
     */
    public function knowledge(string $brandKey): array
    {
        $knowledge = $this->brandGen->getKnowledge($brandKey);
        if (!$knowledge) {
            throw new RuntimeException('برند در پایگاه دانش یافت نشد.');
        }
        return $knowledge;
    }

    /**
     * 📚 لیست برندهای پایگاه دانش
     */
    public function knowledgeBrands(): array
    {
        return $this->brandGen->allKnowledgeBrands();
    }

    /**
     * 📰 موضوعات پیشنهادی مقاله برای برند
     */
    public function suggestArticleTopics(array $params): array
    {
        $brandId = (int)($params['brand_id'] ?? 0);
        $brand = $this->db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
        if (!$brand) {
            throw new RuntimeException('برند یافت نشد.');
        }
        return $this->articleGen->suggestTopics($brand, (int)($params['count'] ?? 5));
    }

    /* ==================================================
     * 🛠️ متدهای کمکی داخلی
     * ================================================== */

    /**
     * 💾 ذخیره مقاله تولیدشده در دیتابیس
     */
    public function saveArticle(int $brandId, array $article, string $topicType): int
    {
        $status = !empty($article['publish']) ? 'published' : 'draft';
        $articleId = $this->db->insert('brand_articles', [
            'brand_id'        => $brandId,
            'title'           => $article['title'],
            'slug'            => $article['slug'],
            'content'         => $article['content'],
            'excerpt'         => $article['excerpt'],
            'category_ids'    => json_encode([$article['category']]),
            'tags'            => json_encode($article['tags'], JSON_UNESCAPED_UNICODE),
            'status'          => $status,
            'published_at'    => $status === 'published' ? date('Y-m-d H:i:s') : null,
            'generated_by_ai' => 1,
            'uniqueness_hash' => $article['uniqueness_hash'],
            'seo_title'       => $article['seo']['title'] ?? null,
            'seo_description' => $article['seo']['description'] ?? null,
            'seo_keywords'    => $article['seo']['keywords'] ?? null,
        ]);

        // ثبت در زمان‌بندی در صورت تاریخ انتشار آینده
        if (!empty($article['publish_at'])) {
            $this->db->insert('scheduled_posts', [
                'brand_id'   => $brandId,
                'article_id' => $articleId,
                'task_type'  => 'publish_article',
                'run_at'     => $article['publish_at'],
            ]);
        }
        return $articleId;
    }

    /**
     * 📈 آمار عملکرد موتور AI (برای داشبورد) — نسخه تقویت‌شده
     */
    public function engineStats(): array
    {
        $totalArticles = $this->db->count('brand_articles', 'generated_by_ai = 1');
        $totalFaqs = $this->db->count('faqs', 'generated_by_ai = 1');
        $totalDevices = $this->db->count('brand_devices');
        $knowledgeBrands = count($this->brandGen->allKnowledgeBrands());
        $uniqueness = $this->uniquenessChecker->systemReport();
        $kbStats = KnowledgeBase::stats();

        return [
            'articles_generated'   => $totalArticles,
            'faqs_generated'       => $totalFaqs,
            'device_descriptions'  => $totalDevices,
            'knowledge_brands'     => $knowledgeBrands,
            'knowledge_templates'  => count(TextProcessor::loadKnowledge('templates')),
            'synonym_words'        => count(TextProcessor::loadSynonyms()),
            'uniqueness_health'    => $uniqueness['health'],
            // 🆕 آمار پایگاه دانش تقویت‌شده
            'knowledge_devices'    => $kbStats['devices'],
            'knowledge_error_codes'=> $kbStats['error_codes'],
            'knowledge_diagnostic_scenarios' => $kbStats['diagnostic_scenarios'],
            'knowledge_spare_parts'=> $kbStats['spare_parts'],
            'knowledge_phrases'    => $kbStats['phrase_patterns'],
            'knowledge_stats'      => $kbStats,
        ];
    }

    /* ==================================================
     * 🧠 اندپوینت‌های دانش جدید (فاز K)
     * ================================================== */

    /**
     * 📊 آمار کامل پایگاه دانش — GET /api/ai/knowledge/stats
     */
    public function knowledgeStats(): array
    {
        return KnowledgeBase::stats();
    }

    /**
     * 🩺 سناریوهای عیب‌یابی دستگاه — GET /api/ai/diagnostics/{device}
     */
    public function deviceDiagnostics(string $deviceKey): array
    {
        $scenarios = KnowledgeBase::diagnostics($deviceKey);
        if (empty($scenarios)) {
            throw new RuntimeException('دستگاه در پایگاه عیب‌یابی یافت نشد.');
        }
        return $scenarios;
    }

    /**
     * 🎯 تشخیص علت از روی علامت — POST /api/ai/diagnose
     * پارامترها: device, symptom
     */
    public function diagnose(array $params): array
    {
        $device = (string)($params['device'] ?? '');
        $symptom = (string)($params['symptom'] ?? '');
        if ($device === '' || $symptom === '') {
            throw new RuntimeException('پارامترهای device و symptom الزامی هستند.');
        }
        $result = KnowledgeBase::diagnose($device, $symptom);
        if (!$result) {
            throw new RuntimeException('علامت مورد نظر در پایگاه دانش یافت نشد.');
        }
        // ➕ قطعات مرتبط با این علامت
        $result['related_parts'] = KnowledgeBase::partsForSymptom($symptom);
        return $result;
    }

    /**
     * 📅 تقویم فصلی — GET /api/ai/seasonal (بدون پارامتر = ماه جاری)
     */
    public function seasonal(array $params = []): array
    {
        if (!empty($params['month'])) {
            $calendar = KnowledgeBase::seasonalCalendar();
            if (!isset($calendar[$params['month']])) {
                throw new RuntimeException('ماه نامعتبر است.');
            }
            return $calendar[$params['month']];
        }
        return KnowledgeBase::currentSeason();
    }

    /**
     * 🧰 دانش قطعات — GET /api/ai/parts (همه یا یکی)
     */
    public function parts(array $params = []): array
    {
        if (!empty($params['key'])) {
            $part = KnowledgeBase::part((string)$params['key']);
            if (!$part) {
                throw new RuntimeException('قطعه یافت نشد.');
            }
            return $part;
        }
        return KnowledgeBase::parts();
    }
}
