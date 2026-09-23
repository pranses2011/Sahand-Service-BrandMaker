<?php
/**
 * 🔍 ژنراتور سئو — عناوین، متا، Schema، OG و Twitter Cards
 * =========================================================
 * تولید خودکار عناصر سئو با رعایت محدودیت‌های طول استاندارد
 * و پشتیبانی از نام فارسی + انگلیسی برند.
 *
 * 🆕 نسخه ۱.۱: اسکیمای Article غنی‌شده (تصاویر/سایز/بازبینی) +
 * HowTo برای مقالات راهنما + Speakable + تحلیل تراکم کلیدواژه +
 * پیشنهاد لینک داخلی + og:image و twitter:image.
 *
 * 🆕 نسخه ۱.۲ (v2.3): چک‌لیست E-E-A-T + استخراج موجودیت‌ها (Entity) +
 * تحلیل شکاف محتوایی نسبت به رقبا + خوشه‌بندی کلیدواژه ثانویه +
 * پیشنهاد پرس‌وجوهای مرتبط (People Also Ask).
 *
 * @package SahandBrandMaker\Engine
 * @version 1.2.0
 */
class SeoGenerator
{
    /**
     * 🏷️ تولید عنوان سئو (حداکثر ۶۰ کاراکتر)
     *
     * @param string $pageTitle عنوان صفحه
     * @param array  $vars متغیرها (brand_fa, brand_en, agency)
     */
    public function title(string $pageTitle, array $vars): string
    {
        $patterns = TextProcessor::loadKnowledge('seo-patterns')['title_patterns'] ?? [];
        $pattern = TextProcessor::seededPick($patterns, 'title|' . $pageTitle) ?: '{{page}} | {{agency}}';

        $title = strtr($pattern, [
            '{{page}}'    => $pageTitle,
            '{{brand_fa}}' => $vars['brand_fa'] ?? '',
            '{{brand_en}}' => $vars['brand_en'] ?? '',
            '{{agency}}'  => $vars['agency'] ?? 'سهند سرویس',
        ]);

        // ✂️ برش ایمن تا ۶۰ کاراکتر (بدون نصف‌کردن کلمه)
        if (mb_strlen($title) > 60) {
            $cut = mb_substr($title, 0, 60);
            $spacePos = mb_strrpos($cut, ' ');
            if ($spacePos > 30) {
                $cut = mb_substr($cut, 0, $spacePos);
            }
            $title = $cut;
        }
        return $title;
    }

    /**
     * 📝 تولید متا توضیحات (حداکثر ۱۶۰ کاراکتر)
     */
    public function metaDescription(string $content, array $vars): string
    {
        $patterns = TextProcessor::loadKnowledge('seo-patterns')['meta_patterns'] ?? [];
        $pattern = TextProcessor::seededPick($patterns, 'meta|' . md5($content)) ?: '{{excerpt}}';

        $excerpt = excerpt(strip_tags($content), 155);
        $meta = strtr($pattern, [
            '{{excerpt}}'   => $excerpt,
            '{{brand_fa}}'  => $vars['brand_fa'] ?? '',
            '{{brand_en}}'  => $vars['brand_en'] ?? '',
            '{{agency}}'    => $vars['agency'] ?? 'سهند سرویس',
            '{{device_fa}}' => $vars['device_fa'] ?? '',
        ]);

        if (mb_strlen($meta) > 160) {
            $cut = mb_substr($meta, 0, 160);
            $spacePos = mb_strrpos($cut, ' ');
            if ($spacePos > 80) {
                $cut = mb_substr($cut, 0, $spacePos);
            }
            $meta = $cut . '…';
        }
        return $meta;
    }

    /**
     * 📰 تولید پکیج کامل سئو برای مقاله
     *
     * @param string $title        عنوان مقاله
     * @param string $content      HTML مقاله
     * @param string $focusKeyword کلیدواژه کانونی
     * @param array  $vars         متغیرها (brand_fa, agency, city, images[])
     */
    public function generateForArticle(string $title, string $content, string $focusKeyword, array $vars): array
    {
        $seoTitle = $this->title($title, $vars);
        $meta = $this->metaDescription($content, $vars);
        $images = array_values(array_filter((array)($vars['images'] ?? [])));

        // 🏷️ OG tags — کامل با تصویر
        $og = [
            'og:title'       => $seoTitle,
            'og:description' => $meta,
            'og:type'        => 'article',
            'og:locale'      => 'fa_IR',
            'og:site_name'   => $vars['agency'] ?? 'سهند سرویس',
        ];
        if ($images) {
            $og['og:image'] = $images[0];
            $og['og:image:width'] = 1344;
            $og['og:image:height'] = 768;
        }

        // 🐦 Twitter Card — کامل با تصویر
        $twitter = [
            'twitter:card'        => $images ? 'summary_large_image' : 'summary',
            'twitter:title'       => $seoTitle,
            'twitter:description' => $meta,
        ];
        if ($images) {
            $twitter['twitter:image'] = $images[0];
        }

        // 🧩 Schema.org JSON-LD Article غنی‌شده
        $schema = $this->articleSchema($title, $content, $vars, $images);

        return [
            'title'       => $seoTitle,
            'description' => $meta,
            'keywords'    => implode(', ', $this->extractKeywords($content, $focusKeyword, $vars)),
            'og'          => $og,
            'twitter'     => $twitter,
            'schema'      => $schema,
            'keyword_density' => $this->keywordDensity($content, $focusKeyword),
            'reading_time' => max(1, (int)ceil(TextProcessor::wordCount(strip_tags($content)) / 220)),
            // 🆕 v1.2
            'entities'      => $this->extractEntities($content),
            'eeat'          => $this->eeatChecklist($content, $vars),
            'secondary_keywords' => $this->secondaryKeywordClusters($focusKeyword, $content),
        ];
    }

    /**
     * 🧠 v1.2: استخراج موجودیت‌ها (Entity) از محتوا — پایه سئوی معنایی
     * برندها/دستگاه‌ها/شهرها/اصطلاحات فنی شناخته‌شده را برمی‌گرداند.
     */
    public function extractEntities(string $content): array
    {
        $text = ' ' . strip_tags($content) . ' ';
        $entities = [];

        // 🏷️ برندهای شناخته‌شده (از پایگاه دانش)
        $knowledge = TextProcessor::loadKnowledge('brands');
        $brandKeys = is_array($knowledge) ? array_keys($knowledge) : [];
        foreach ($brandKeys as $key) {
            if (mb_strlen($key) >= 3 && mb_stripos($text, $key) !== false) {
                $entities[] = ['name' => $key, 'type' => 'Brand'];
            }
        }

        // 🔧 دستگاه‌ها
        $devices = TextProcessor::loadKnowledge('devices');
        if (is_array($devices)) {
            foreach ($devices as $key => $d) {
                $nameFa = (string)($d['name_fa'] ?? '');
                if ($nameFa !== '' && mb_stripos($text, $nameFa) !== false) {
                    $entities[] = ['name' => $nameFa, 'type' => 'Product'];
                }
            }
        }

        // 📍 شهرهای ایران
        $cities = ['تهران', 'مشهد', 'اصفهان', 'شیراز', 'تبریز', 'کرج', 'اهواز', 'قم', 'رشت', 'زاهدان', 'یزد', 'کرمان'];
        foreach ($cities as $city) {
            if (mb_strpos($text, $city) !== false) {
                $entities[] = ['name' => $city, 'type' => 'City'];
            }
        }

        // 🔬 اصطلاحات فنی حوزه تعمیرات
        $terms = [
            'کمپرسور' => 'Thing', 'برد الکترونیکی' => 'Thing', 'واپسوزی' => 'Thing',
            'یخچال فریزر' => 'Product', 'ماشین لباسشویی' => 'Product', 'ظرفشویی' => 'Product',
            'جاروبرقی' => 'Product', 'تلمه' => 'Thing', 'کولر گازی' => 'Product',
            'ایرادیابی' => 'Thing', 'قطعات یدکی' => 'Thing', 'گارانتی' => 'Thing',
        ];
        foreach ($terms as $term => $type) {
            if (mb_strpos($text, $term) !== false) {
                $entities[] = ['name' => $term, 'type' => $type];
            }
        }

        return array_slice($entities, 0, 20);
    }

    /**
     * 🏅 v1.2: چک‌لیست E-E-A-T — تجربه/تخصص/اعتبار/اعتماد محتوا
     */
    public function eeatChecklist(string $content, array $vars = []): array
    {
        $text = strip_tags($content);
        $wordCount = TextProcessor::wordCount($text);
        $checks = [];

        $checks[] = [
            'key' => 'experience', 'label' => 'تجربه (Experience)',
            'pass' => (bool)preg_match('#(سال تجربه|تکنسین|متخصص|نمایندگی|کارشناس)#u', $text),
            'hint' => 'اشاره به سال‌های تجربه یا تخصص تکنسین‌ها در متن اضافه شود',
        ];
        $checks[] = [
            'key' => 'expertise', 'label' => 'تخصص (Expertise)',
            'pass' => (bool)preg_match('#(قطعات اصلی|استاندارد|کالیبراسیون|الگوریتم|فنی|تخصصی)#u', $text),
            'hint' => 'اصطلاحات فنی تخصصی و اشاره به قطعات اصلی اضافه شود',
        ];
        $checks[] = [
            'key' => 'authoritativeness', 'label' => 'اعتبار (Authoritativeness)',
            'pass' => (bool)preg_match('#(نمایندگی رسمی|گارانتی|ضمانت|مشخصه|مجاز)#u', $text),
            'hint' => 'اشاره به نمایندگی رسمی/گارانتی/ضمانت کتبی اضافه شود',
        ];
        $checks[] = [
            'key' => 'trust_safety', 'label' => 'اعتماد و ایمنی (Trust)',
            'pass' => (bool)preg_match('#(هشدار|ایمنی|احتیاط|برق بکشید|خاموش)#u', $text),
            'hint' => 'جعبه هشدار ایمنی برای مقالات فنی اضافه شود',
        ];
        $checks[] = [
            'key' => 'structure', 'label' => 'ساختار عنوان‌بندی',
            'pass' => substr_count($content, '<h2') >= 3,
            'hint' => 'حداقل ۳ سرفصل h2 برای ساختار بهتر داشته باشید',
        ];
        $checks[] = [
            'key' => 'depth', 'label' => 'عمق محتوا (۱۰۰۰+ کلمه)',
            'pass' => $wordCount >= 1000,
            'hint' => 'محتوای عمیق‌تر با جزئیات فنی بیشتر',
        ];
        $checks[] = [
            'key' => 'faq', 'label' => 'پاسخ به سوالات رایج',
            'pass' => (bool)preg_match('#<h3>[^<]*؟#u', $content) || mb_stripos($content, 'سوالات متداول') !== false,
            'hint' => 'بخش سوالات متداول برای پوشش پرس‌وجوهای کاربران',
        ];
        $checks[] = [
            'key' => 'cta', 'label' => 'فراخوان اقدام (CTA)',
            'pass' => (bool)preg_match('#(تماس بگیرید|ثبت درخواست|درخواست تعمیر|همین حالا)#u', $text),
            'hint' => 'دکمه/متن فراخوان اقدام در انتهای مقاله',
        ];

        $passed = count(array_filter($checks, function ($c) { return $c['pass']; }));
        return [
            'score'   => (int)round($passed / max(1, count($checks)) * 100),
            'passed'  => $passed,
            'total'   => count($checks),
            'checks'  => $checks,
        ];
    }

    /**
     * 🔗 v1.2: خوشه‌بندی کلیدواژه‌های ثانویه — برای پوشش معنایی موضوع
     */
    public function secondaryKeywordClusters(string $focusKeyword, string $content): array
    {
        $focus = trim($focusKeyword);
        $clusters = [
            'informational' => [
                $focus . ' چیست',
                'دلایل خرابی ' . str_replace('تعمیر ', '', $focus),
                'علائم خرابی ' . str_replace('تعمیر ', '', $focus),
            ],
            'commercial' => [
                'هزینه ' . $focus,
                'قیمت ' . $focus,
                $focus . ' در تهران',
            ],
            'howto' => [
                'آموزش ' . $focus,
                'راه حل ' . $focus,
                'رفع عیب ' . $focus,
            ],
        ];
        // پرس‌وجوهای People Also Ask پیشنهادی
        $paa = [
            'آیا ' . str_replace('تعمیر ', '', $focus) . ' صرفه اقتصادی دارد؟',
            'چه زمانی باید به تعمیرکار متخصص مراجعه کرد؟',
            'قطعات مصرفی اصلی از کجا تهیه شود؟',
        ];
        return [
            'clusters' => $clusters,
            'people_also_ask' => $paa,
        ];
    }

    /**
     * 📊 v1.2: تحلیل شکاف محتوایی — مقایسه عنوان‌های رقبا با محتوای فعلی
     */
    public function contentGap(array $competitorTitles, string $content): array
    {
        $text = ' ' . strip_tags($content) . ' ';
        $gaps = [];
        foreach ($competitorTitles as $title) {
            $title = trim((string)$title);
            if ($title === '') { continue; }
            // استخراج واژه‌های معنادار عنوان رقیب
            $words = preg_split('/[\s\|\-–—:,،]+/u', $title);
            $covered = 0;
            $total = 0;
            foreach ($words as $w) {
                $w = trim($w);
                if (mb_strlen($w) < 3) { continue; }
                $total++;
                if (mb_stripos($text, $w) !== false) { $covered++; }
            }
            if ($total === 0) { continue; }
            $coverage = $covered / $total;
            if ($coverage < 0.5) {
                $gaps[] = ['title' => $title, 'coverage' => round($coverage * 100) . '٪', 'missing' => true];
            }
        }
        return [
            'analyzed' => count($competitorTitles),
            'gaps' => array_slice($gaps, 0, 5),
            'hint' => 'موضوعات فهرست‌شده در محتوا پوشش داده نشده‌اند — افزودن بخش مرتبط به رتبه کمک می‌کند',
        ];
    }

    /**
     * 🏠 تولید پکیج کامل سئو برای صفحه
     */
    public function generateForPage(string $pageType, string $content, array $vars): array
    {
        $pageNames = [
            'home' => 'صفحه اصلی', 'services' => 'خدمات', 'service_area' => 'محدوده خدمات',
            'warranty' => 'ضمانت', 'blog' => 'مقالات', 'about-agency' => 'درباره نمایندگی',
            'about-brand' => 'درباره برند', 'contact' => 'تماس با ما', 'request' => 'ثبت درخواست خدمات',
            'other-brands' => 'سایر برندها', 'error-codes' => 'کدهای خطا', 'faq' => 'سوالات متداول',
            'terms' => 'قوانین و مقررات', 'privacy' => 'حریم خصوصی',
        ];
        $pageTitle = $pageNames[$pageType] ?? $pageType;
        $seoTitle = $this->title($pageTitle, $vars);
        $meta = $this->metaDescription($content, $vars);

        return [
            'title'       => $seoTitle,
            'description' => $meta,
            'keywords'    => implode(', ', $this->extractKeywords($content, '', $vars)),
            'og'          => [
                'og:title'       => $seoTitle,
                'og:description' => $meta,
                'og:type'        => 'website',
                'og:locale'      => 'fa_IR',
            ],
            'twitter'     => [
                'twitter:card'        => 'summary_large_image',
                'twitter:title'       => $seoTitle,
                'twitter:description' => $meta,
            ],
            'schema'      => $this->localBusinessSchema($vars),
        ];
    }

    /**
     * 🏢 Schema.org LocalBusiness — برای سایت‌های برند (سئوی محلی)
     */
    public function localBusinessSchema(array $vars): array
    {
        $contacts = Config::get(Config::KEY_CONTACTS) ?: [];
        $addresses = Config::get(Config::KEY_ADDRESSES) ?: [];
        $address = is_array($addresses) ? ($addresses[0] ?? []) : [];

        return [
            '@context'  => 'https://schema.org',
            '@type'     => 'LocalBusiness',
            'name'      => ($vars['brand_fa'] ?? '') . ' — ' . (Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس'),
            'alternateName' => $vars['brand_en'] ?? '',
            'url'       => $vars['brand_domain'] ?? '',
            'telephone' => $contacts['phone'][0] ?? '',
            'email'     => $contacts['email'][0] ?? '',
            'address'   => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => $address['address'] ?? '',
                'addressLocality' => $address['city'] ?? '',
                'postalCode'      => $address['postal_code'] ?? '',
                'addressCountry'  => 'IR',
            ],
            'geo' => !empty($address['lat']) ? [
                '@type'     => 'GeoCoordinates',
                'latitude'  => $address['lat'],
                'longitude' => $address['lng'],
            ] : null,
            'openingHours' => $this->openingHoursSchema(),
            'priceRange'   => '$$',
        ];
    }

    /**
     * 📰 Schema.org Article — غنی‌شده نسخه ۱.۱
     * تصاویر + حجم محتوا + بازبینی + صفحه مرجع + Speakable
     */
    public function articleSchema(string $title, string $content, array $vars, array $images = []): array
    {
        $agency = $vars['agency'] ?? 'سهند سرویس';
        $schema = [
            '@context'        => 'https://schema.org',
            '@type'           => 'Article',
            'headline'        => $title,
            'description'     => excerpt(strip_tags($content), 200),
            'author'          => ['@type' => 'Organization', 'name' => $agency],
            'publisher'       => [
                '@type' => 'Organization',
                'name'  => $agency,
            ],
            'inLanguage'      => 'fa-IR',
            'datePublished'   => date('c'),
            'dateModified'    => date('c'),
            'wordCount'       => TextProcessor::wordCount(strip_tags($content)),
        ];
        if (!empty($vars['brand_domain'])) {
            $schema['mainEntityOfPage'] = 'https://' . $vars['brand_domain'];
        }
        if ($images) {
            $schema['image'] = array_values($images);
        }
        // 🗣️ Speakable — برای دستیار‌های صوتی و سئوی شفاف
        $schema['speakable'] = [
            '@type'       => 'SpeakableSpecification',
            'cssSelector' => ['.article-single-title', '.article-toc'],
        ];
        return $schema;
    }

    /**
     * 🪜 Schema.org HowTo — برای مقالات راهنما و آموزش (نسخه ۱.۱)
     *
     * @param string $name        عنوان راهنما
     * @param array  $steps       [['name' => '...', 'text' => '...'], ...]
     */
    public function howToSchema(string $name, array $steps): array
    {
        $items = [];
        foreach (array_values($steps) as $i => $step) {
            $items[] = [
                '@type'    => 'HowToStep',
                'position' => $i + 1,
                'name'     => (string)($step['name'] ?? 'مرحله ' . ($i + 1)),
                'text'     => strip_tags((string)($step['text'] ?? '')),
            ];
        }
        return [
            '@context' => 'https://schema.org',
            '@type'    => 'HowTo',
            'name'     => $name,
            'inLanguage' => 'fa-IR',
            'step'     => $items,
        ];
    }

    /**
     * 📊 تحلیل تراکم کلیدواژه — درصد حضور کلیدواژه کانونی (نسخه ۱.۱)
     * بازه سالم سئو: ۰.۵٪ تا ۲.۵٪
     */
    public function keywordDensity(string $content, string $focusKeyword): array
    {
        $text = trim(strip_tags($content));
        $words = TextProcessor::wordCount($text);
        if ($words < 10 || $focusKeyword === '') {
            return ['percent' => 0.0, 'count' => 0, 'status' => 'unknown', 'words' => $words];
        }
        $count = substr_count(strtolower($text), strtolower($focusKeyword));
        $percent = round(($count * mb_substr_count($focusKeyword, ' ') + $count) / max(1, $words) * 100, 2);
        $status = 'low';
        if ($percent > 2.5) {
            $status = 'high';
        } elseif ($percent >= 0.5) {
            $status = 'healthy';
        }
        return ['percent' => $percent, 'count' => $count, 'status' => $status, 'words' => $words];
    }

    /**
     * ❓ Schema.org FAQPage
     */
    public function faqSchema(array $faqs): array
    {
        $items = [];
        foreach ($faqs as $faq) {
            $items[] = [
                '@type'          => 'Question',
                'name'           => $faq['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags($faq['answer'])],
            ];
        }
        return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $items];
    }

    /**
     * 🧭 Schema.org BreadcrumbList
     */
    public function breadcrumbSchema(array $breadcrumbs): array
    {
        $items = [];
        foreach (array_values($breadcrumbs) as $i => $crumb) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $crumb['name'],
                'item'     => $crumb['url'] ?? '',
            ];
        }
        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /**
     * 🔧 Schema.org Service — برای صفحه خدمات
     */
    public function serviceSchema(string $serviceName, array $vars): array
    {
        return [
            '@context'    => 'https://schema.org',
            '@type'       => 'Service',
            'name'        => $serviceName,
            'provider'    => [
                '@type' => 'LocalBusiness',
                'name'  => Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس',
                'url'   => Config::get(Config::KEY_MAIN_SITE),
            ],
            'areaServed'  => 'IR',
            'serviceType' => 'تعمیرات لوازم خانگی',
            'inLanguage'  => 'fa-IR',
        ];
    }

    /**
     * ⏰ ساعات کاری به فرمت Schema
     */
    private function openingHoursSchema(): array
    {
        $wh = Config::get(Config::KEY_WORK_HOURS) ?: [];
        $daysMap = ['sat' => 'Sa', 'sun' => 'Su', 'mon' => 'Mo', 'tue' => 'Tu', 'wed' => 'We', 'thu' => 'Th', 'fri' => 'Fr'];
        $days = [];
        foreach (($wh['days'] ?? []) as $day) {
            if (isset($daysMap[$day])) {
                $days[] = $daysMap[$day];
            }
        }
        $start = str_replace(':', '', $wh['start'] ?? '0900') ?: '0900';
        $end = str_replace(':', '', $wh['end'] ?? '2000') ?: '2000';
        if (empty($days)) {
            $days = ['Mo', 'Tu', 'We', 'Th', 'Sa', 'Su'];
        }
        return [implode(',', $days) . ' ' . $start . '-' . $end];
    }

    /**
     * 🔑 استخراج کلیدواژه‌های سئو
     */
    private function extractKeywords(string $content, string $focusKeyword, array $vars): array
    {
        $keywords = [];
        if ($focusKeyword !== '') {
            $keywords[] = $focusKeyword;
        }
        if (!empty($vars['brand_fa'])) {
            $keywords[] = 'تعمیر ' . $vars['brand_fa'];
            $keywords[] = 'نمایندگی ' . $vars['brand_fa'];
        }
        if (!empty($vars['brand_en'])) {
            $keywords[] = $vars['brand_en'] . ' repair';
        }
        // کلیدواژه‌های پرتکرار محتوا
        $analyzer = new KeywordAnalyzer();
        foreach (array_slice($analyzer->extract($content, 8), 0, 5) as $kw) {
            $keywords[] = $kw['keyword'];
        }
        return array_slice(array_unique($keywords), 0, 12);
    }

    /**
     * 📝 تولید تگ‌های HTML سئو برای درج در <head>
     *
     * @param array $seo پکیج سئو (title, description, keywords, og, twitter, schema)
     * @param array $webmasterTags تگ‌های وبمستر فعال
     */
    public function renderHead(array $seo, array $webmasterTags = [], string $canonicalUrl = ''): string
    {
        $html = '<title>' . e($seo['title'] ?? '') . '</title>' . "\n";
        $html .= '<meta name="description" content="' . e($seo['description'] ?? '') . '">' . "\n";
        if (!empty($seo['keywords'])) {
            $html .= '<meta name="keywords" content="' . e($seo['keywords']) . '">' . "\n";
        }
        if (!empty($seo['robots'])) {
            $html .= '<meta name="robots" content="' . e($seo['robots']) . '">' . "\n";
        }
        if ($canonicalUrl !== '') {
            $html .= '<link rel="canonical" href="' . e($canonicalUrl) . '">' . "\n";
        }
        // 🏷️ تگ‌های وبمستر
        foreach ($webmasterTags as $tag) {
            if (!empty($tag['content']) && !empty($tag['is_active'])) {
                $html .= '<meta name="' . e($tag['meta_name']) . '" content="' . e($tag['content']) . '">' . "\n";
            }
        }
        // 📗 Open Graph
        foreach (($seo['og'] ?? []) as $property => $value) {
            $html .= '<meta property="' . e($property) . '" content="' . e((string)$value) . '">' . "\n";
        }
        // 🐦 Twitter
        foreach (($seo['twitter'] ?? []) as $name => $value) {
            $html .= '<meta name="' . e($name) . '" content="' . e((string)$value) . '">' . "\n";
        }
        // 🧩 Schema JSON-LD
        if (!empty($seo['schema'])) {
            $json = json_encode($seo['schema'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $html .= '<script type="application/ld+json">' . $json . '</script>' . "\n";
        }
        return $html;
    }
}
