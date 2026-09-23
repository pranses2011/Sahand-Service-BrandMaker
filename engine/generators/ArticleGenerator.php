<?php
/**
 * 📰 ژنراتور مقالات — تولید مقالات یکتای ۸۰۰-۱۵۰۰ کلمه‌ای
 * =========================================================
 * ساختار: انتخاب موضوع ← ساخت Outline ← نوشتن بخش‌ها ←
 * لینک‌دهی داخلی ← بهینه‌سازی سئو ← خروجی HTML
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class ArticleGenerator
{
    /** @var Database دیتابیس */
    private $db;

    /** @var UniquenessChecker بررسی یکتایی */
    private $uniqueness;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->uniqueness = new UniquenessChecker();
    }

    /**
     * 📰 تولید یک مقاله کامل یکتا برای برند
     *
     * @param array  $brand اطلاعات برند (id, name_fa, name_en, ...)
     * @param string $topicType نوع مقاله (troubleshooting|user_guide|maintenance|comparison|error_codes)
     * @param string|null $deviceKey کلید دستگاه هدف (اختیاری — تصادفی انتخاب می‌شود)
     * @return array مقاله کامل ['title','slug','content','excerpt','seo',...]
     */
    public function generate(array $brand, string $topicType, ?string $deviceKey = null): array
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
            $device = TextProcessor::seededPick($devices, 'artdev|' . $brand['id'] . '|' . mt_rand());
        }

        $deviceKnowledge = TextProcessor::loadKnowledge('devices')[$device['device_key']] ?? [];
        $templates = TextProcessor::loadKnowledge('templates');
        $topicTemplates = $templates['article_topics'][$topicType] ?? [];
        if (empty($topicTemplates)) {
            $topicTemplates = $templates['article_topics']['troubleshooting'] ?? [];
        }

        $seed = 'article|' . $brand['id'] . '|' . $topicType . '|' . $device['device_key'] . '|' . time() . '|' . mt_rand();
        $vars = [
            'brand_fa'  => $brand['name_fa'],
            'brand_en'  => $brand['name_en'],
            'device_fa' => $device['name_fa'],
            'device_en' => $deviceKnowledge['name_en'] ?? '',
            'agency'    => Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس',
        ];

        /* ---------- ۱️⃣ انتخاب عنوان از قالب‌های موضوع ---------- */
        $title = TextProcessor::fillTemplate(
            TextProcessor::seededPick($topicTemplates, $seed . '|title'),
            $vars
        );

        /* ---------- ۲️⃣ ساخت Outline مقاله ---------- */
        $sections = $this->buildOutline($topicType, $device, $deviceKnowledge, $vars, $seed);

        /* ---------- ۳️⃣ نوشتن محتوای هر بخش ---------- */
        $bodyParts = [];
        foreach ($sections as $section) {
            $bodyParts[] = '<h2>' . e($section['title']) . '</h2>';
            $bodyParts[] = $section['content'];
        }

        // مقدمه
        $introTemplate = TextProcessor::seededPick($templates['article_intro'] ?? [''], $seed . '|intro');
        $intro = TextProcessor::applySynonyms(TextProcessor::fillTemplate($introTemplate, $vars), $seed . '|isyn');

        // نتیجه‌گیری + CTA
        $conclusionTemplate = TextProcessor::seededPick($templates['article_conclusion'] ?? [''], $seed . '|concl');
        $conclusion = TextProcessor::fillTemplate($conclusionTemplate, $vars);

        $content = '<p>' . $intro . '</p>' . "\n" . implode("\n", $bodyParts)
            . "\n" . '<h2>جمع‌بندی</h2>' . "\n" . '<p>' . $conclusion . '</p>';

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

        /* ---------- ۶️⃣ سئو ---------- */
        $seoGenerator = new SeoGenerator();
        $focusKeyword = 'تعمیر ' . $device['name_fa'] . ' ' . $brand['name_fa'];
        $seo = $seoGenerator->generateForArticle($title, $content, $focusKeyword, $vars);

        return [
            'title'      => $title,
            'slug'       => SlugGenerator::unique($title, 'brand_articles', 'slug', 0, (int)$brand['id']),
            'content'    => $content,
            'excerpt'    => excerpt($intro, 200),
            'focus_keyword' => $focusKeyword,
            'category'   => $this->categoryForTopic($topicType),
            'tags'       => [$device['name_fa'], $brand['name_fa'], $topicType === 'maintenance' ? 'نگهداری' : 'تعمیرات'],
            'seo'        => $seo,
            'uniqueness' => $check,
            'word_count' => TextProcessor::wordCount(strip_tags($content)),
            'generated_by_ai' => 1,
            'uniqueness_hash' => $check['hash'] ?? TextProcessor::contentHash($content),
        ];
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
     * 🏷️ نگاشت نوع موضوع به دسته‌بندی
     */
    private function categoryForTopic(string $topicType): int
    {
        $map = [
            'troubleshooting' => 1, // رفع ایراد
            'user_guide'      => 2, // راهنمای استفاده
            'maintenance'     => 3, // نگهداری
            'diagnostics'     => 4, // عیب‌یابی
            'comparison'      => 5, // عمومی
            'error_codes'     => 4, // عیب‌یابی
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
        $types = ['troubleshooting', 'user_guide', 'maintenance', 'comparison', 'error_codes', 'buying_guide', 'energy_saving'];
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
            'troubleshooting' => 'علت و راه‌حل مشکلات رایج',
            'user_guide'      => 'آموزش کامل استفاده',
            'maintenance'     => 'نکات مهم نگهداری',
            'comparison'      => 'مقایسه مدل‌های',
            'error_codes'     => 'کدهای خطای رایج',
            'buying_guide'    => 'راهنمای خرید',
            'energy_saving'   => 'صرفه‌جویی در مصرف انرژی',
        ];
        return $device . ' ' . $brand . ' — ' . ($map[$type] ?? 'راهنمای جامع');
    }
}
