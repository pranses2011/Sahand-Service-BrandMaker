<?php
/**
 * 🔍 ژنراتور سئو — عناوین، متا، Schema، OG و Twitter Cards
 * =========================================================
 * تولید خودکار عناصر سئو با رعایت محدودیت‌های طول استاندارد
 * و پشتیبانی از نام فارسی + انگلیسی برند.
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
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
     */
    public function generateForArticle(string $title, string $content, string $focusKeyword, array $vars): array
    {
        $seoTitle = $this->title($title, $vars);
        $meta = $this->metaDescription($content, $vars);

        // 🏷️ OG tags
        $og = [
            'og:title'       => $seoTitle,
            'og:description' => $meta,
            'og:type'        => 'article',
            'og:locale'      => 'fa_IR',
        ];

        // 🐦 Twitter Card
        $twitter = [
            'twitter:card'        => 'summary_large_image',
            'twitter:title'       => $seoTitle,
            'twitter:description' => $meta,
        ];

        // 🧩 Schema.org JSON-LD Article
        $schema = $this->articleSchema($title, $content, $vars);

        return [
            'title'       => $seoTitle,
            'description' => $meta,
            'keywords'    => implode(', ', $this->extractKeywords($content, $focusKeyword, $vars)),
            'og'          => $og,
            'twitter'     => $twitter,
            'schema'      => $schema,
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
     * 📰 Schema.org Article
     */
    public function articleSchema(string $title, string $content, array $vars): array
    {
        return [
            '@context'      => 'https://schema.org',
            '@type'         => 'Article',
            'headline'      => $title,
            'description'   => excerpt(strip_tags($content), 200),
            'author'        => ['@type' => 'Organization', 'name' => $vars['agency'] ?? 'سهند سرویس'],
            'publisher'     => [
                '@type' => 'Organization',
                'name'  => $vars['agency'] ?? 'سهند سرویس',
            ],
            'inLanguage'    => 'fa-IR',
            'datePublished' => date('c'),
        ];
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
