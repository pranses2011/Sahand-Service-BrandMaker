<?php
/**
 * 💬 دستیار فرمان فارسی — CommandAssistant v3.0
 * ===============================================
 * رابط زبان طبیعی برای موتور AI: کاربر فارسی‌زبان دستور
 * می‌نویسد، دستیار عملیات مناسب را تشخیص داده و اجرا می‌کند.
 *
 * نمونه فرمان‌ها:
 *   «مقاله بنویس برای پاکشما درباره ماشین لباسشویی»
 *   «کلمات کلیدی یخچال»
 *   «امتیاز این متن: ...»
 *   «عنوان برای تعمیر ماشین ظرفشویی بده»
 *   «این متن را بهبود بده: ...»
 *   «نیت جستجوی خرید یخچال ساید بای ساید چیست؟»
 *   «تشخیص عیب: یخچال سرد نمی‌کند و صدای داد می‌زند»
 *   «برندهای پایگاه دانش»
 *   «راهنما»
 *
 * @package SahandBrandMaker\Engine
 * @version 3.0.0
 */
class CommandAssistant
{
    /** @var SahandAI موتور */
    private $ai;

    /** @var Database دیتابیس */
    private $db;

    /** @var array نگاشت برند فارسی → کلید دانش */
    private $brandMap = null;

    public function __construct()
    {
        $this->ai = new SahandAI();
        $this->db = Database::getInstance();
    }

    /**
     * 💬 اجرای فرمان زبان طبیعی
     *
     * @param string $command فرمان فارسی کاربر
     * @return array نتیجه + فرمان‌های پیشنهادی بعدی
     */
    public function handle(string $command): array
    {
        $command = trim($command);
        if ($command === '') {
            return $this->help('فرمان خالی است.');
        }
        $norm = TextProcessor::normalize(mb_strtolower($command));

        // 1️⃣ راهنما
        if (preg_match('/^(راهنما|کمک|هدایت|چه کارهایی)/u', $norm)) {
            return $this->help();
        }

        // 1.5️⃣ 🌐 جستجوی آنلاین وب — «جستجوی وب: ...» / «سرچ: ...» / «در اینترنت جستجو کن ...»
        if (preg_match('/(جستجوی وب|جستجو در وب|جستجوی انلاین|جستجوی آنلاین|در اینترنت جستجو|سرچ)/u', $norm)
            && preg_match('/[:：]/u', $command)) {
            $query = trim(preg_split('/[:：]/u', $command, 2)[1] ?? '');
            return $this->webSearch($query, $command);
        }

        // 1.6️⃣ 🧪 تحقیق آنلاین — «تحقیق درباره: ...» / «تحقیق کن: ...»
        if (preg_match('/(تحقیق|بررسی آنلاین|بررسی وب)/u', $norm) && preg_match('/[:：]/u', $command)) {
            $topic = trim(preg_split('/[:：]/u', $command, 2)[1] ?? '');
            return $this->webResearch($topic, $command);
        }

        // 1.7️⃣ 📰 اخبار زنده — «اخبار: ...» / «اخبار امروز درباره ...» / «خبر: ...»
        if (preg_match('/(اخبار|خبر)/u', $norm)
            && (preg_match('/[:：]/u', $command) || preg_match('/(درباره|از)/u', $norm))) {
            $query = trim((string)(preg_split('/[:：]/u', $command, 2)[1] ?? ''));
            if ($query === '') {
                $query = preg_replace('/(اخبار|خبر|امروز|درباره|از|جدید|تازه|را|بده|نمایش)/u', ' ', $command);
                $query = trim(preg_replace('/\s+/u', ' ', $query));
            }
            return $this->webNews($query, $command);
        }

        // 2️⃣ امتیاز متن — «امتیاز این متن: ...» (دارای دونقطه — قبل از تشخیص عیب تا تداخل نشود)
        if (preg_match('/(امتیاز|نمره)/u', $norm) && preg_match('/[:：]/u', $command)) {
            $text = trim(preg_split('/[:：]/u', $command, 2)[1] ?? '');
            return $this->score($text, $command);
        }

        // 1.75️⃣ 📝 اصلاح نگارش فارسی — «نگارش: متن» / «غلط‌گیری: متن» / «اصلاح نگارش متن: ...» (فاز Q.9 — قبل از intent سئو و بهبود)
        if (preg_match('/(نگارش|غلط[\s\x{200C}]?گیری|غلط[\s\x{200C}]?یاب|اصلاح[\s\x{200C}]?نگارش)/u', $norm) && preg_match('/[:：]/u', $command)) {
            $text = trim(preg_split('/[:：]/u', $command, 2)[1] ?? '');
            return $this->grammar($text, $command);
        }

        // 1.78️⃣ 🎯 پیشنهاد بهترین عنوان سئو — «پیشنهاد عنوان سئو: ...» / «عنوان سئو من: ...» (فاز Q.9 — قبل از intent سئو و عنوان عام)
        if (preg_match('/(پیشنهاد ?(بهترین )?عنوان سئو|عنوان سئو|عنوان دلخواه)/u', $norm) && preg_match('/[:：]/u', $command)) {
            $title = trim(preg_split('/[:：]/u', $command, 2)[1] ?? '');
            return $this->suggestSeoTitle($title, $command);
        }

        // 1.8️⃣ 🏅 پکیج سئو — «سئو: ...» / «سئو بررسی کن ...» / «تحلیل سئو ...» (v3.3)
        if (preg_match('/(سئو|seo)/u', $norm)
            && preg_match('/(بررسی|تحلیل|پکیج|چک|کن|بده|:)/u', $norm)) {
            $topic = trim((string)(preg_split('/[:：]/u', $command, 2)[1] ?? ''));
            if ($topic === '') {
                $topic = preg_replace('/(سئو|تحلیل|بررسی|چک|کن|بده|را|برای|از|seo)/iu', ' ', $command);
                $topic = trim(preg_replace('/\s+/u', ' ', $topic));
            }
            return $this->seoPackage($topic, $command);
        }

        // 1.9️⃣ ✅ چک‌لیست E-E-A-T — «اعتمادسنجی: ...» / «eeat ...» (v3.3)
        if (preg_match('/(اعتمادسنجی|ای ای تی|eeat)/u', $norm) && preg_match('/[:：]/u', $command)) {
            $text = trim(preg_split('/[:：]/u', $command, 2)[1] ?? '');
            return $this->eeat($text, $command);
        }

        // 1.95️⃣ 🗓️ برنامه محتوا — «برنامه محتوا برای ...» (v3.3)
        if (preg_match('/(برنامه محتوا|تقویم محتوا|برنامه انتشار)/u', $norm)) {
            return $this->contentPlan($command);
        }

        // 1.97️⃣ ❓ سوالات متداول — «سوالات متداول درباره ...» (v3.3)
        if (preg_match('/(سوالات متداول|سوال رایج|سوالات رایج)/u', $norm)) {
            return $this->faq($command);
        }

        // 1.99️⃣ 📰 انواع مقاله — «انواع مقاله» / «چند نوع مقاله» (فاز Q.9)
        if (preg_match('/(انواع مقاله|نوع مقاله|چند نوع مقاله)/u', $norm)) {
            return $this->articleTypes();
        }

        // 3️⃣ بهبود متن — «این متن را بهبود بده: ...»
        if (preg_match('/(بهبود|اصلاح|تقویت)/u', $norm) && preg_match('/[:：]/u', $command)
            && !preg_match('/نگارش/u', $norm)) {
            $text = trim(preg_split('/[:：]/u', $command, 2)[1] ?? '');
            return $this->improve($text, $command);
        }

        // 4️⃣ تشخیص عیب — «تشخیص عیب: ...» یا شروع با کلیدواژه
        if (preg_match('/(تشخیص عیب|عیب یابی)/u', $norm)
            && (preg_match('/[:：]/u', $command) || preg_match('/^(تشخیص|عیب)/u', $norm))) {
            return $this->diagnose($command);
        }

        // 5️⃣ برندها
        if (preg_match('/(برندهای|لیست برند|برند ها)/u', $norm) && preg_match('/(پایگاه|دانش|لیست)/u', $norm)) {
            return $this->brands();
        }

        // 6️⃣ عنوان
        if (preg_match('/(عنوان|تیتر)/u', $norm)) {
            return $this->titles($command);
        }

        // 7️⃣ نیت جستجو
        if (preg_match('/(نیت|قصد).*(جستجو|کاربر)?/u', $norm) && !preg_match('/[:：]/u', $command)) {
            return $this->intent($command);
        }

        // 8️⃣ کلمات کلیدی
        if (preg_match('/(کلمات کلیدی|کلیدواژه)/u', $norm)) {
            return $this->keywords($command);
        }

        // 9️⃣ مقاله
        if (preg_match('/(مقاله|مطلب).*(بنویس|بنویسید|بساز|تولید)|بنویس.*مقاله|مقاله جدید/u', $norm)) {
            return $this->article($command);
        }

        // 🔟 تشخیص هوشمند (بدون کلیدواژه مشخص) — جملات خبره
        if (preg_match('/(کد خطا|ارور)/u', $norm)) {
            return $this->errorCodes($command);
        }

        // 🤷 نامفهوم
        return [
            'action'   => 'unknown',
            'message'  => 'فرمان شناخته نشد. دستیار این کارها را می‌فهمد: تولید مقاله، کلمات کلیدی، امتیاز متن، بهبود متن، عنوان، نیت جستجو، تشخیص عیب، کد خطا و لیست برندها.',
            'command'  => $command,
            'suggestions' => $this->suggestions(),
        ];
    }

    /* ==================================================
     * 🎬 عملیات‌ها
     * ================================================== */

    /**
     * 🌐 جستجوی آنلاین وب از فرمان طبیعی
     */
    private function webSearch(string $query, string $command): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return ['action' => 'web_search', 'success' => false, 'message' => 'عبارت جستجو را بعد از دونقطه بنویسید؛ مثال: «جستجوی وب: تعمیر ماشین لباسشویی در تهران»'];
        }
        try {
            $result = (new WebSearchService())->search($query, 6);
            return [
                'action'  => 'web_search',
                'success' => true,
                'message' => 'جستجوی وب انجام شد — ' . count($result['results']) . ' نتیجه از ' . ($result['provider'] ?? 'وب') .
                             ($result['cached'] ? ' (کش)' : ''),
                'result'  => $result,
                'suggestions' => ["تحقیق درباره: {$query}", "مقاله بنویس درباره {$query}"],
            ];
        } catch (Exception $e) {
            return ['action' => 'web_search', 'success' => false, 'message' => 'جستجوی وب ناموفق بود: ' . $e->getMessage()];
        }
    }

    /**
     * 🧪 تحقیق ساختاریافته آنلاین از فرمان طبیعی
     */
    private function webResearch(string $topic, string $command): array
    {
        $topic = trim($topic);
        if (mb_strlen($topic) < 3) {
            return ['action' => 'web_research', 'success' => false, 'message' => 'موضوع تحقیق را بعد از دونقطه بنویسید؛ مثال: «تحقیق درباره: ماشین لباسشویی دوو»'];
        }
        try {
            $result = (new WebSearchService())->research($topic, ['limit' => 6]);
            return [
                'action'  => 'web_research',
                'success' => true,
                'message' => $result['summary'] ?? ('تحقیق آنلاین درباره «' . $topic . '» انجام شد.'),
                'result'  => $result,
                'suggestions' => ["مقاله بنویس درباره {$topic}", "کلمات کلیدی {$topic}"],
            ];
        } catch (Exception $e) {
            return ['action' => 'web_research', 'success' => false, 'message' => 'تحقیق آنلاین ناموفق بود: ' . $e->getMessage()];
        }
    }

    /**
     * 📰 اخبار زنده وب از فرمان طبیعی (v3.2)
     */
    private function webNews(string $query, string $command): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 3) {
            return ['action' => 'web_news', 'success' => false, 'message' => 'موضوع خبر را بنویسید؛ مثال: «اخبار: قیمت یخچال» یا «اخبار امروز درباره ماشین لباسشویی»'];
        }
        try {
            $result = (new WebSearchService())->news($query, 6);
            return [
                'action'  => 'web_news',
                'success' => true,
                'message' => count($result['results']) . ' خبر تازه درباره «' . $query . '» پیدا شد' .
                             ($result['cached'] ? ' (کش)' : ''),
                'result'  => $result,
                'suggestions' => ["مقاله بنویس درباره {$query}", "تحقیق درباره: {$query}"],
            ];
        } catch (Exception $e) {
            return ['action' => 'web_news', 'success' => false, 'message' => 'جستجوی اخبار ناموفق بود: ' . $e->getMessage()];
        }
    }

    /**
     * 📰 تولید مقاله از فرمان طبیعی
     */
    private function article(string $command): array
    {
        $norm = TextProcessor::normalize(mb_strtolower($command));
        $brandKey = $this->findBrand($norm);
        $deviceKey = $this->findDevice($norm);

        // یافتن برند دیتابیسی (پایگاه دانش مستقل از برندهای ثبت‌شده کاربر)
        $brandId = null;
        if ($brandKey !== null) {
            $row = $this->db->fetch('SELECT id FROM brands WHERE name_fa LIKE ? OR name_en LIKE ? LIMIT 1', ["%{$brandKey}%", "%{$brandKey}%"]);
            if ($row) {
                $brandId = (int)$row['id'];
            }
        }
        if ($brandId === null) {
            $row = $this->db->fetchValue('SELECT MIN(id) FROM brands WHERE is_active = 1');
            $brandId = $row ? (int)$row : null;
        }

        if ($brandId === null) {
            return [
                'action'  => 'article',
                'success' => false,
                'message' => 'هنوز برندی ثبت نشده است. ابتدا از پنل مدیریت برند بسازید.',
                'suggestions' => $this->suggestions(),
            ];
        }

        // نوع مقاله از فرمان
        $topicType = 'troubleshooting';
        if (preg_match('/(آموزش|راهنمای استفاده)/u', $norm)) {
            $topicType = 'user_guide';
        } elseif (preg_match('/(نگهداری|تعمیر و نگهداری|سرویس دوره)/u', $norm)) {
            $topicType = 'maintenance';
        } elseif (preg_match('/(مقایسه|بهترین)/u', $norm)) {
            $topicType = 'comparison';
        } elseif (preg_match('/(هزینه|قیمت|تعرفه)/u', $norm)) {
            $topicType = 'cost_guide';
        } elseif (preg_match('/(فصلی|تابستان|زمستان)/u', $norm)) {
            $topicType = 'seasonal_care';
        }

        // 🆕 v3.2: موضوع دلخواه — برای ساخت مقاله درباره هر موضوعی (حتی خارج از دامنه دستگاه‌ها)
        $customTopic = $this->extractTopic($command, ['/مقاله/', '/مطلب/', '/بنویس/', '/بنویسید/', '/بساز/', '/تولید/', '/جدید/']);
        $hasCustomTopic = ($customTopic !== '' && mb_strlen($customTopic) >= 4);

        $result = $this->ai->smartGenerate([
            'brand_id'  => $brandId,
            'topic_type'=> $topicType,
            'device_key'=> $deviceKey,
            'topic'     => $hasCustomTopic ? $customTopic : null,
            /* 🎯 v3.3: مقاله دقیقاً حول موضوع درخواستی کاربر نوشته می‌شود —
               قبلاً موضوع فقط برای کلیدواژه استفاده می‌شد و بدنه مقاله
               از قالب عمومی می‌آمد (ریشه «مقاله بی‌ربط» بودن) */
            'custom_title' => $hasCustomTopic ? $this->topicToTitle($customTopic) : null,
            'research'  => 'auto', // جستجوی وب برای محتوای واقعی و به‌روز
            'variants'  => 2,
            'no_cache'  => true, // هر درخواست مقاله = مقاله تازه و یکتا
        ]);

        return [
            'action'  => 'article',
            'success' => true,
            'message' => 'مقاله با خط تولید هوشمند تولید شد.',
            'params'  => ['brand_id' => $brandId, 'topic_type' => $topicType, 'device_key' => $deviceKey],
            'result'  => $result,
            'suggestions' => [
                'این متن را بهبود بده: ...',
                'امتیاز این متن: ...',
                'عنوان برای ' . ($result['keyword']['focus'] ?? 'این موضوع') . ' بده',
            ],
        ];
    }

    /**
     * 🔑 کلمات کلیدی از فرمان
     */
    private function keywords(string $command): array
    {
        $topic = $this->extractTopic($command, ['/کلمات کلیدی/', '/کلیدواژه/']);
        if ($topic === '') {
            return ['action' => 'keywords', 'success' => false, 'message' => 'موضوع کلمات کلیدی را مشخص کنید؛ مثال: «کلمات کلیدی ماشین لباسشویی»'];
        }
        $result = $this->ai->suggestLongTail(['keyword' => $topic, 'count' => 10]);
        $comp = (new KeywordAnalyzer())->estimateCompetition($topic);
        return [
            'action'  => 'keywords',
            'success' => true,
            'message' => "تحلیل کلیدواژه «{$topic}» انجام شد.",
            'result'  => [
                'topic'         => $topic,
                'competition'   => $comp,
                'longtail'      => $result,
            ],
            'suggestions' => ["مقاله بنویس درباره {$topic}", "عنوان برای {$topic} بده"],
        ];
    }

    /**
     * 🏆 امتیازدهی متن
     */
    private function score(string $text, string $command): array
    {
        if (TextProcessor::wordCount($text) < 30) {
            return ['action' => 'score', 'success' => false, 'message' => 'متن برای امتیازدهی خیلی کوتاه است (حداقل ۳۰ کلمه).'];
        }
        $result = $this->ai->scoreContent(['content' => $text]);
        return [
            'action'  => 'score',
            'success' => true,
            'message' => 'امتیاز کیفیت متن: ' . $result['score'] . '/100 (' . $result['grade'] . ')',
            'result'  => $result,
            'suggestions' => ['این متن را بهبود بده: ' . mb_substr($text, 0, 80) . '...'],
        ];
    }

    /**
     * 🔧 بهبود متن
     */
    private function improve(string $text, string $command): array
    {
        if (TextProcessor::wordCount($text) < 30) {
            return ['action' => 'improve', 'success' => false, 'message' => 'متن برای بهبود خیلی کوتاه است (حداقل ۳۰ کلمه).'];
        }
        $result = (new ContentImprover())->improve($text, ['target_score' => 85]);
        return [
            'action'  => 'improve',
            'success' => true,
            'message' => 'امتیاز از ' . $result['score_before'] . ' به ' . $result['score_after'] . ' رسید.',
            'result'  => $result,
            'suggestions' => ['امتیاز این متن: ' . mb_substr($result['content'], 0, 80) . '...'],
        ];
    }

    /**
     * 🏷️ پیشنهاد عنوان
     */
    private function titles(string $command): array
    {
        $topic = $this->extractTopic($command, ['/عنوان/', '/تیتر/', '/برای/', '/بده/', '/بساز/']);
        if ($topic === '') {
            return ['action' => 'titles', 'success' => false, 'message' => 'موضوع عنوان را مشخص کنید؛ مثال: «عنوان برای تعمیر ماشین ظرفشویی بده»'];
        }
        $result = (new TitleGenerator())->generate($topic, [], 8);
        return [
            'action'  => 'titles',
            'success' => true,
            'message' => count($result['titles']) . ' عنوان پیشنهادی برای «' . $topic . '».',
            'result'  => $result,
            'suggestions' => ["مقاله بنویس درباره {$topic}"],
        ];
    }

    /**
     * 📰 فهرست انواع مقاله — فاز Q.9 (۲۵ نوع)
     */
    private function articleTypes(): array
    {
        $templates = TextProcessor::loadKnowledge('templates');
        $types = array_keys($templates['article_topics'] ?? []);
        $labels = [
            'troubleshooting' => 'رفع ایراد', 'user_guide' => 'راهنمای استفاده', 'maintenance' => 'نگهداری',
            'comparison' => 'مقایسه مدل‌ها', 'error_codes' => 'کدهای خطا', 'buying_guide' => 'راهنمای خرید',
            'energy_saving' => 'صرفه‌جویی انرژی', 'seasonal_care' => 'مراقبت فصلی', 'cost_guide' => 'راهنمای هزینه',
            'safety_guide' => 'نکات ایمنی', 'installation_guide' => 'نصب و راه‌اندازی', 'diy_vs_pro' => 'تعمیر شخصی یا تخصصی',
            'common_mistakes' => 'اشتباهات رایج', 'warranty_guide' => 'گارانتی و خدمات', 'tech_explainer' => 'فناوری‌ها به زبان ساده',
            'myths_facts' => 'باور غلط و واقعیت', 'checklist' => 'چک‌لیست', 'case_study' => 'مطالعه موردی',
            'glossary' => 'واژه‌نامه تخصصی', 'history_evolution' => 'تاریخچه و تکامل', 'expert_tips' => 'نکات حرفه‌ای',
            'symptom_focus' => 'عیب‌یابی علامت‌محور', 'statistics' => 'آمار و ارقام', 'environment' => 'محیط زیست',
            'service_process' => 'فرآیند تعمیر نمایندگی',
        ];
        $list = [];
        foreach ($types as $key) {
            $list[] = $labels[$key] ?? $key;
        }
        return [
            'action'  => 'article_types',
            'success' => true,
            'message' => count($types) . ' نوع مقاله: ' . implode('، ', $list),
            'result'  => ['count' => count($types), 'types' => $list],
            'suggestions' => ['مقاله آموزشی درباره یخچال بنویس'],
        ];
    }

    /**
     * 📝 اصلاح نگارش فارسی — فاز Q.9 (PersianGrammar)
     */
    private function grammar(string $text, string $command): array
    {
        if (mb_strlen(trim($text)) < 20) {
            return ['action' => 'grammar', 'success' => false, 'message' => 'متن برای بررسی نگارشی خیلی کوتاه است (حداقل ۲۰ نویسه).'];
        }
        $analysis = PersianGrammar::analyze($text);
        $fixed = PersianGrammar::fixText($text);
        $fluency = PersianGrammar::fluency($text);
        return [
            'action'  => 'grammar',
            'success' => true,
            'message' => 'امتیاز نگارش: ' . $analysis['score'] . '/۱۰۰ (' . $analysis['grade'] . ') — ' . count($analysis['issues']) . ' ایراد',
            'result'  => [
                'analysis' => $analysis,
                'fixed'    => $fixed,
                'fluency'  => $fluency,
                'changed'  => $fixed !== $text,
            ],
            'suggestions' => ['این متن را بهبود بده: ' . mb_substr($text, 0, 80) . '...'],
        ];
    }

    /**
     * 🎯 پیشنهاد بهترین عنوان سئو برای عنوان دلخواه — فاز Q.9 (TitleGenerator v3.1)
     */
    private function suggestSeoTitle(string $title, string $command): array
    {
        $title = trim($title);
        if (mb_strlen($title) < 5) {
            return ['action' => 'suggest_seo_title', 'success' => false, 'message' => 'عنوان دلخواه بسیار کوتاه است (حداقل ۵ نویسه).'];
        }
        try {
            $result = (new TitleGenerator())->suggestForCustom($title, [], 8);
            return [
                'action'  => 'suggest_seo_title',
                'success' => true,
                'message' => 'بهترین عنوان سئو (+ ' . $result['best_gain'] . ' امتیاز): ' . $result['best'],
                'result'  => $result,
                'suggestions' => ['مقاله بنویس درباره ' . $result['best']],
            ];
        } catch (Exception $e) {
            return ['action' => 'suggest_seo_title', 'success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 🧭 نیت جستجو
     */
    private function intent(string $command): array
    {
        // حذف کلمات دستیار، موضوع باقی می‌ماند
        $topic = preg_replace('/(نیت|قصد|جستجوی|جستجو|کاربر|چیست|چیه|؟|\?)/u', ' ', $command);
        $topic = trim($topic);
        if ($topic === '') {
            return ['action' => 'intent', 'success' => false, 'message' => 'عبارت جستجو را مشخص کنید؛ مثال: «نیت جستجوی خرید یخچال چیست؟»'];
        }
        $result = $this->ai->classifyIntent(['queries' => [$topic]]);
        return [
            'action'  => 'intent',
            'success' => true,
            'message' => 'نیت تشخیص داده شد.',
            'result'  => $result,
            'suggestions' => ["کلمات کلیدی {$topic}"],
        ];
    }

    /**
     * 🩺 تشخیص عیب — علامت‌ها + تطبیق فازی سه‌سطحی
     */
    private function diagnose(string $command): array
    {
        $norm = TextProcessor::normalize(mb_strtolower($command));
        $deviceKey = $this->findDevice($norm);
        $devicesKnowledge = TextProcessor::loadKnowledge('devices');

        // علامت: بخش پس از دونقطه، یا کل فرمان بدون کلیدواژه‌های دستوری
        $symptom = trim((string)(preg_split('/[:：]/u', $command, 2)[1] ?? ''));
        if (mb_strlen($symptom) < 4) {
            $symptom = preg_replace('/(تشخیص عیب|عیب یابی|ایراد|را|از|دستگاه|برند)/u', ' ', $command);
        }
        $symptom = trim(preg_replace('/\s+/u', ' ', $symptom));

        if ($deviceKey === null) {
            // بدون دستگاه مشخص — تلاش با دستگاه‌های پرکاربرد
            $candidates = ['refrigerator', 'washing_machine', 'dishwasher', 'air_conditioner', 'package'];
            foreach ($candidates as $cand) {
                if (isset($devicesKnowledge[$cand])) {
                    $deviceKey = $cand;
                    break;
                }
            }
        }
        if ($deviceKey === null || mb_strlen($symptom) < 4) {
            return ['action' => 'diagnose', 'success' => false,
                'message' => 'نشانه‌ها را بنویسید؛ مثال: «تشخیص عیب: یخچال سرد نمی‌کند»',
                'suggestions' => ['تشخیص عیب: یخچال خنک نمی‌کند', 'تشخیص عیب: ماشین لباسشویی آب تخلیه نمی‌کند']];
        }

        try {
            $result = $this->ai->diagnose([
                'device'   => $deviceKey,
                'symptom'  => $symptom,
            ]);
        } catch (RuntimeException $e) {
            return ['action' => 'diagnose', 'success' => false,
                'message' => 'علامت در پایگاه دانش یافت نشد: ' . $e->getMessage(),
                'suggestions' => ['تشخیص عیب: یخچال خنک نمی‌کند', 'تشخیص عیب: ماشین لباسشویی آب تخلیه نمی‌کند']];
        }

        return [
            'action'  => 'diagnose',
            'success' => true,
            'message' => 'تشخیص سه‌سطحی برای دستگاه «' . ($devicesKnowledge[$deviceKey]['name_fa'] ?? $deviceKey) . '» انجام شد.',
            'result'  => ['device_key' => $deviceKey] + $result,
            'suggestions' => ['مقاله بنویس درباره ' . ($devicesKnowledge[$deviceKey]['name_fa'] ?? 'این مشکل')],
        ];
    }

    /**
     * 🚨 جستجوی کد خطا
     */
    private function errorCodes(string $command): array
    {
        preg_match('/([eE]\s?-?\s?\d{1,2}|[0-9]{1,2}[eE]|[0-9]{1,2})/u', $command, $m);
        $code = trim(str_replace(' ', '', $m[1] ?? ''));
        $deviceKey = $this->findDevice(TextProcessor::normalize(mb_strtolower($command)));
        $kb = TextProcessor::loadKnowledge('error-codes');
        $hits = [];
        foreach ($kb as $key => $ec) {
            if ($code !== '' && (strcasecmp($key, $code) === 0 || str_contains($key, $code))) {
                $hits[$key] = $ec;
            }
        }
        return [
            'action'  => 'error-codes',
            'success' => !empty($hits),
            'message' => empty($hits)
                ? 'کد خطا یافت نشد. کد را دقیق‌تر بنویسید؛ مثال: «کد خطا E24 ماشین ظرفشویی بوش»'
                : count($hits) . ' کد خطا یافت شد.',
            'result'  => ['code' => $code, 'device_key' => $deviceKey, 'matches' => $hits],
            'suggestions' => $this->suggestions(),
        ];
    }

    /**
     * 🏷️ لیست برندهای دانش
     */
    private function brands(): array
    {
        $brands = $this->ai->knowledgeBrands();
        return [
            'action'  => 'brands',
            'success' => true,
            'message' => count((array)$brands) . ' برند در پایگاه دانش موجود است.',
            'result'  => $brands,
            'suggestions' => ['مقاله بنویس برای اسنوا درباره یخچال'],
        ];
    }

    /* ==================================================
     * 🛠️ ابزارها
     * ================================================== */

    /**
     * ❓ راهنمای دستیار
     */
    private function help(string $note = ''): array
    {
        return [
            'action'  => 'help',
            'success' => true,
            'message' => $note !== '' ? $note : 'دستیار هوشمند سهند آماده است.',
            'examples' => [
                'مقاله بنویس برای اسنوا درباره ماشین لباسشویی',
                'مقاله آموزشی درباره یخچال ببنویس',
                'کلمات کلیدی ماشین ظرفشویی',
                'امتیاز این متن: <متن شما>',
                'این متن را بهبود بده: <متن شما>',
                'نگارش: <متن شما>',
                'پیشنهاد عنوان سئو: <عنوان دلخواه شما>',
                'عنوان برای تعمیر جاروبرشی بده',
                'نیت جستجوی خرید تلویزیون چیست؟',
                'تشخیص عیب: یخچال سرد نمی‌کند',
                'کد خطا E24 ماشین ظرفشویی',
                'برندهای پایگاه دانش',
            ],
            'capabilities' => [
                'article'     => 'تولید مقاله کامل با خط تولید هوشمند + ۳ تصویر',
                'keywords'    => 'تحلیل کلیدواژه + رقابت‌پذیری + long-tail',
                'score'       => 'امتیاز ۷ بُعدی کیفیت متن',
                'improve'     => 'بهبود خودکار متن تا امتیاز هدف',
                'titles'      => 'پیشنهاد عنوان بهینه CTR-محور',
                'intent'      => 'تشخیص نیت جستجو',
                'diagnose'    => 'تشخیص سه‌سطحی علامت → علت → قطعه',
                'errors'      => 'جستجوی کد خطا در پایگاه دانش',
                'brands'      => 'لیست برندهای پایگاه دانش',
                'web_search'  => 'جستجوی آنلاین اینترنت — «جستجوی وب: ...»',
                'web_research'=> 'تحقیق ساختاریافته آنلاین — «تحقیق درباره: ...»',
                'web_news'    => 'اخبار زنده — «اخبار: ...» (v3.2)',
                'seo_package' => 'پکیج سئوی پیشرفته — «سئو بررسی کن: ...» (v3.3)',
                'eeat'        => 'اعتمادسنجی E-E-A-T — «اعتمادسنجی: <متن>» (v3.3)',
                'content_plan'=> 'برنامه محتوا — «برنامه محتوا برای ...» (v3.3)',
                'faq'         => 'سوالات متداول — «سوالات متداول درباره ...» (v3.3)',
                'grammar'     => 'اصلاح نگارش فارسی — «نگارش: <متن>» (فاز Q)',
                'seo_title'   => 'پیشنهاد بهترین عنوان سئو — «پیشنهاد عنوان سئو: <عنوان>» (فاز Q)',
            ],
            'suggestions' => $this->suggestions(),
        ];
    }

    /* ==================================================
     * 🆕 قابلیت‌های v3.3
     * ================================================== */

    /**
     * 🏅 پکیج سئوی پیشرفته — v3.3
     */
    private function seoPackage(string $topic, string $command): array
    {
        if ($topic === '' || mb_strlen($topic) < 3) {
            return ['action' => 'seo', 'success' => false,
                'message' => 'موضوع را مشخص کنید؛ مثال: «سئو بررسی کن: تعمیر یخچال اسنوا» یا «تحلیل سئو ماشین لباسشویی»'];
        }
        try {
            $ai = new SahandAI();
            $seo = $ai->seoPackage([
                'title'         => mb_substr($topic, 0, 120),
                'content'       => $topic,
                'focus_keyword' => $topic,
            ]);
            return ['action' => 'seo', 'success' => true, 'message' => 'پکیج سئو تولید شد', 'result' => $seo];
        } catch (Throwable $e) {
            return ['action' => 'seo', 'success' => false, 'message' => 'خطا در تولید پکیج سئو: ' . $e->getMessage()];
        }
    }

    /**
     * ✅ اعتمادسنجی E-E-A-T — v3.3
     */
    private function eeat(string $text, string $command): array
    {
        if ($text === '' || mb_strlen($text) < 20) {
            return ['action' => 'eeat', 'success' => false,
                'message' => 'متن کافی نیست؛ مثال: «اعتمادسنجی: <متن مقاله شما>»'];
        }
        try {
            $result = (new SahandAI())->eeatCheck(['content' => $text]);
            return ['action' => 'eeat', 'success' => true, 'message' => 'چک‌لیست E-E-A-T اجرا شد', 'result' => $result];
        } catch (Throwable $e) {
            return ['action' => 'eeat', 'success' => false, 'message' => 'خطا: ' . $e->getMessage()];
        }
    }

    /**
     * 🗓️ برنامه محتوا — v3.3
     */
    private function contentPlan(string $command): array
    {
        try {
            $ai = new SahandAI();
            $result = $ai->contentPlan(['weeks' => 8]);
            return ['action' => 'content_plan', 'success' => true, 'message' => 'برنامه محتوای ۸ هفته‌ای', 'result' => $result];
        } catch (Throwable $e) {
            return ['action' => 'content_plan', 'success' => false, 'message' => 'خطا: ' . $e->getMessage()];
        }
    }

    /**
     * ❓ سوالات متداول — v3.3
     */
    private function faq(string $command): array
    {
        try {
            // اولین برند فعال به عنوان پیش‌فرض (اگر کاربر برندی مشخص نکرده)
            $db = Database::getInstance();
            $brandId = (int)$db->fetchValue('SELECT id FROM brands WHERE is_active = 1 ORDER BY id LIMIT 1');
            if ($brandId < 1) {
                return ['action' => 'faq', 'success' => false,
                    'message' => 'هنوز برندی ثبت نشده است؛ ابتدا از پنل مدیریت برند بسازید.'];
            }
            $ai = new SahandAI();
            $result = $ai->generateFaq(['brand_id' => $brandId, 'count' => 10]);
            return ['action' => 'faq', 'success' => true, 'message' => 'سوالات متداول تولید شد', 'result' => $result];
        } catch (Throwable $e) {
            return ['action' => 'faq', 'success' => false, 'message' => 'خطا: ' . $e->getMessage()];
        }
    }

    /**
     * 💡 فرمان‌های پیشنهادی
     */
    private function suggestions(): array
    {
        return [
            'راهنما',
            'مقاله بنویس برای پاکشما درباره ماشین لباسشویی',
            'کلمات کلیدی یخچال',
            'عنوان برای تعمیر کولر گازی بده',
            'جستجوی وب: قیمت موتور ماشین لباسشویی',
            'تحقیق درباره: یخچال ساید بای ساید اسنوا',
            'سئو بررسی کن: تعمیر یخچال اسنوا',
            'برنامه محتوا برای یخچال',
        ];
    }

    /**
     * 🔎 یافتن برند دانشی در متن فرمان
     */
    private function findBrand(string $norm): ?string
    {
        if ($this->brandMap === null) {
            $this->brandMap = [];
            foreach (TextProcessor::loadKnowledge('brands') as $key => $b) {
                $names = array_filter([
                    $b['name_fa'] ?? null,
                    $b['name_en'] ?? null,
                    is_array($b['aliases'] ?? null) ? null : null,
                ]);
                $this->brandMap[$key] = $names;
                foreach ((array)($b['aliases'] ?? []) as $alias) {
                    $this->brandMap[$key][] = $alias;
                }
            }
        }
        foreach ($this->brandMap as $key => $names) {
            foreach ($names as $name) {
                if (is_string($name) && $name !== '' && mb_strpos($norm, TextProcessor::normalize(mb_strtolower($name))) !== false) {
                    return $key;
                }
            }
        }
        return null;
    }

    /**
     * 🔎 یافتن دستگاه در متن فرمان
     */
    private function findDevice(string $norm): ?string
    {
        foreach (TextProcessor::loadKnowledge('devices') as $key => $d) {
            $name = TextProcessor::normalize(mb_strtolower($d['name_fa'] ?? ''));
            if ($name !== '' && mb_strpos($norm, $name) !== false) {
                return $key;
            }
        }
        /* 🆕 v2.9: واژه‌های محاوره‌ای — «یخچال، لباسشویی، کولر، جاروبرقی...»
           (نام رسمی دانش «یخچال و فریزر» است و «یخچال» تنها پیدا نمی‌شد →
           دستگاه تصادفی انتخاب می‌شد و تصویر مقاله بی‌ربط می‌شد!) */
        try {
            $inferred = ArticleImageService::inferDeviceKey($norm);
            if ($inferred !== null) {
                return $inferred;
            }
        } catch (Throwable $e) {
            // بی‌اثر بر جریان اصلی
        }
        return null;
    }

    /**
     * ✂️ استخراج موضوع از فرمان (حذف کلمات دستوری)
     */
    private function extractTopic(string $command, array $stripPatterns): string
    {
        $topic = $command;
        foreach ($stripPatterns as $p) {
            $topic = preg_replace($p . 'u', ' ', $topic);
        }
        $topic = preg_replace('/(برای|درباره|از|را|بده|بساز|بنویس|چیست|چیه|لطففاً|لطفا|؟|\?|:|،)/u', ' ', $topic);
        $topic = trim(preg_replace('/\s+/u', ' ', $topic));
        return mb_strlen($topic) >= 3 ? $topic : '';
    }

    /**
     * 🎯 تبدیل موضوع درخواستی به عنوان مقاله (v3.3)
     * «تعمیر برد ماشین لباسشویی سامسونگ» → «تعمیر برد ماشین لباسشویی سامسونگ؛ راهنمای جامع»
     */
    private function topicToTitle(string $topic): string
    {
        $topic = trim(preg_replace('/\s+/u', ' ', $topic));
        if ($topic === '') {
            return '';
        }
        /* اگر خودش عنوان‌گون است (با «راهنما/چگونه/آموزش/بررسی» شروع می‌شود) دست نزن */
        if (preg_match('/^(راهنمای|چگونه|چطور|آموزش|بررسی|مقایسه|بهترین|نکات)/u', $topic)) {
            return $topic;
        }
        $suffixes = ['؛ راهنمای جامع و کاربردی', '؛ نکات مهم و راه‌حل‌های عملی', '؛ هر آنچه باید بدانید'];
        return $topic . $suffixes[crc32($topic) % 3];
    }
}
