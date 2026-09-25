<?php
/**
 * 📚 کتابخانه قالب‌های آماده — ۱۰ سبک متفاوت × ۱۷ نوع صفحه (v2.21)
 * =================================================================
 * تولید ساختاریافته ۱۷۰ قالب پیش‌ساخته برای قالب‌ساز:
 *
 *   • هر نوع صفحه (۱۵ نوع سایت برند + درباره عمومی + سفارشی) دقیقاً
 *     ۱۰ قالب با سبک‌های «واقعاً متفاوت» دارد — تفاوت در ترکیب بلوک‌ها،
 *     هدر/هیرو/فوتر، ریتم پس‌زمینه (معمولی/کمرنگ/رنگ اصلی/گرادیانت/تیره)،
 *     فاصله‌گذاری و بلوک‌های تاکیدی هر سبک؛ نه فقط جابجایی چند بلوک.
 *   • seedMissing(): درج idempotent قالب‌های غایب — قالب‌های دستی کاربر و
 *     پیش‌فرض‌های فعلی هرگز لمس نمی‌شوند؛ فقط کمبودها تکمیل می‌شوند.
 *     مسیر ارتقا: نصب‌های قدیمی با باز کردن صفحه «قالب‌ها» کتابخانه را
 *     کامل می‌کنند (بدون نیاز به تغییر دیتابیس).
 *
 * سبک‌ها: مدرن | کلاسیک | مینیمال | لوکس | فنی-تیره | شرکتی | مجله‌ای |
 *         پرانرژی | تبدیل‌محور | صمیمی
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class TemplateLibrary
{
    /** @var string نسخه کتابخانه */
    public const LIB_VERSION = '1.0.0';

    /** @var int تعداد سبک هر نوع صفحه */
    public const STYLES_PER_TYPE = 10;

    /* ==================================================
     * 🎨 تعریف سبک‌ها — ۱۰ سبک با ترکیب‌های واقعاً متفاوت
     * ================================================== */
    private const STYLES = [
        'modern' => [
            'fa' => 'مدرن', 'icon' => '🚀', 'desc' => 'هدر شیشه‌ای چسبان، هیرو گرادیانت، آمار فشرده و فوتر چندستونه — حس تازگی و تکنولوژی',
            'header' => ['header-v3' => ['sticky' => 1]],
            'hero' => 'hero-glass', 'hero_bg' => 'gradient',
            'accents' => ['stats-strip'],
            'cta' => 'cta-banner', 'cta_bg' => 'gradient', 'cta_pad' => 'roomy',
            'footer' => 'footer-links',
            'rhythm' => ['default', 'surface'],
        ],
        'classic' => [
            'fa' => 'کلاسیک', 'icon' => '🏛', 'desc' => 'نوار بالایی، هدر ساده، متن دوستونه و نقل‌قول — سنتی و قابل اعتماد مثل روزنامه',
            'header' => ['top-bar' => [], 'header-v1' => []],
            'hero' => 'hero', 'hero_bg' => 'default',
            'accents' => ['quote'],
            'cta' => 'cta-phone', 'cta_bg' => 'default', 'cta_pad' => 'default',
            'footer' => 'footer-contact',
            'rhythm' => ['default'],
        ],
        'minimal' => [
            'fa' => 'مینیمال', 'icon' => '🧼', 'desc' => 'هیرو تک‌خطی، کمترین بلوک‌ها، فضای سفید زیاد — سادگی و سرعت',
            'header' => ['header-v1' => []],
            'hero' => 'hero-minimal', 'hero_bg' => 'default',
            'accents' => [],
            'cta' => 'cta-request', 'cta_bg' => 'default', 'cta_pad' => 'default',
            'footer' => 'footer-simple',
            'rhythm' => ['default'],
            'maxCore' => 3,
        ],
        'luxury' => [
            'fa' => 'لوکس', 'icon' => '💎', 'desc' => 'هیرو تیره با تصویر، گواهینامه‌ها و قیمت برجسته — پریمیوم و خاص',
            'header' => ['header-v3' => ['sticky' => 1]],
            'hero' => 'hero-video', 'hero_bg' => 'dark',
            'accents' => ['certificates', 'price-highlight'],
            'cta' => 'price-highlight', 'cta_bg' => 'surface', 'cta_pad' => 'roomy',
            'footer' => 'footer-links',
            'rhythm' => ['default', 'surface'],
        ],
        'tech' => [
            'fa' => 'فنی-تیره', 'icon' => '⚙️', 'desc' => 'بخش‌های تیره، نوار مهارت‌ها و جدول مقایسه — برای مخاطب فنی',
            'header' => ['header-v3' => []],
            'hero' => 'hero-split', 'hero_bg' => 'dark',
            'accents' => ['skill-bars', 'feature-table'],
            'cta' => 'cta-request', 'cta_bg' => 'dark', 'cta_pad' => 'roomy',
            'footer' => 'footer-simple',
            'rhythm' => ['default', 'dark'],
        ],
        'corporate' => [
            'fa' => 'شرکتی', 'icon' => '🏢', 'desc' => 'هدر با نوار تماس، معرفی رسمی، تیم و جدول ساعات کاری — رسمی و منظم',
            'header' => ['header-v2' => []],
            'hero' => 'hero', 'hero_bg' => 'surface',
            'accents' => ['schedule-table', 'counter-stats'],
            'cta' => 'contact-info-bar', 'cta_bg' => 'surface', 'cta_pad' => 'default',
            'footer' => 'footer-contact',
            'rhythm' => ['default', 'surface'],
        ],
        'magazine' => [
            'fa' => 'مجله‌ای', 'icon' => '📰', 'desc' => 'بدون هیرو بزرگ — عنوان وسط‌چین، متن روزنامه‌ای و نقل‌قول — ادیتوریال',
            'header' => ['header-v1' => []],
            'hero' => 'heading-center', 'hero_bg' => 'default',
            'accents' => ['quote'],
            'cta' => 'cta-banner', 'cta_bg' => 'surface', 'cta_pad' => 'default',
            'footer' => 'footer-links',
            'rhythm' => ['default', 'surface'],
        ],
        'energetic' => [
            'fa' => 'پرانرژی', 'icon' => '⚡', 'desc' => 'نوار اطلاعیه، هیرو شمارش معکوس، کمپین و باکس تعمیر فوری — حراجی و پویا',
            'header' => ['notification-bar' => [], 'header-v2' => []],
            'hero' => 'hero-countdown', 'hero_bg' => 'gradient',
            'accents' => ['promo-card', 'urgent-repair'],
            'cta' => 'urgent-repair', 'cta_bg' => 'primary', 'cta_pad' => 'roomy',
            'footer' => 'footer-simple',
            'rhythm' => ['default', 'surface'],
        ],
        'conversion' => [
            'fa' => 'تبدیل‌محور', 'icon' => '🎯', 'desc' => 'هیرو با فرم درخواست کنار هم، نظرات مشتریان و CTA تماس بزرگ — نرخ تبدیل بالا',
            'header' => ['top-bar' => [], 'header-v3' => ['sticky' => 1]],
            'hero' => 'hero-form', 'hero_bg' => 'gradient',
            'accents' => ['testimonials', 'sticky-mobile-cta'],
            'cta' => 'cta-phone', 'cta_bg' => 'primary', 'cta_pad' => 'roomy',
            'footer' => 'footer-contact',
            'rhythm' => ['default', 'surface'],
        ],
        'friendly' => [
            'fa' => 'صمیمی', 'icon' => '🤝', 'desc' => 'اسلایدر تصویری، اثبات اجتماعی و اسلایدر نظرات — گرم و مردم‌پسند',
            'header' => ['header-v2' => []],
            'hero' => 'hero-slider', 'hero_bg' => 'default',
            'accents' => ['social-proof', 'reviews-carousel'],
            'cta' => 'cta-whatsapp', 'cta_bg' => 'default', 'cta_pad' => 'default',
            'footer' => 'footer-links',
            'rhythm' => ['default', 'surface'],
        ],
    ];

    /* ==================================================
     * 📄 تعریف انواع صفحه — هسته محتوایی هر نوع
     * ================================================== */
    private const PAGE_META = [
        'home' => [
            'fa' => 'صفحه اصلی', 'icon' => '🏠', 'inner' => false,
            'heroTitle' => 'تعمیرات تخصصی با قطعات اصلی',
            'heroSub' => 'نمایندگی رسمی — اعزام تکنسین در کمتر از ۲ ساعت با ۶ ماه ضمانت کتبی',
            'core' => ['intro', 'services-grid', 'features', 'articles-recent', 'testimonials', 'brands-links'],
        ],
        'services' => [
            'fa' => 'خدمات', 'icon' => '🔧', 'inner' => true,
            'heroTitle' => 'خدمات تخصصی ما',
            'heroSub' => 'از عیب‌یابی تا تعویض قطعات — با تعرفه شفاف و پیش‌فاکتور',
            'core' => ['services-grid', 'pricing-table', 'features', 'steps-process'],
        ],
        'service-area' => [
            'fa' => 'محدوده خدمات', 'icon' => '📍', 'inner' => true,
            'heroTitle' => 'مناطق تحت پوشش خدمات',
            'heroSub' => 'تکنسین‌ها در تمام مناطق زیر حاضر می‌شوند',
            'core' => ['area-list', 'map', 'working-hours'],
        ],
        'warranty' => [
            'fa' => 'ضمانت', 'icon' => '🛡️', 'inner' => true,
            'heroTitle' => 'ضمانت کتبی خدمات و قطعات',
            'heroSub' => 'شرایط، مدت و نحوه استعلام گارانتی',
            'core' => ['warranty-steps', 'warranty-banner', 'guarantee-card'],
        ],
        'blog' => [
            'fa' => 'مقالات', 'icon' => '📰', 'inner' => true,
            'heroTitle' => 'مجله فنی و آموزشی',
            'heroSub' => 'راهنمای نگهداری، عیب‌یابی و خرید هوشمند',
            'core' => ['search-bar', 'articles-grid', 'related-links'],
        ],
        'about-agency' => [
            'fa' => 'درباره نمایندگی', 'icon' => '🏢', 'inner' => true,
            'heroTitle' => 'درباره نمایندگی ما',
            'heroSub' => 'بیش از یک دهه خدمات تخصصی لوازم خانگی',
            'core' => ['brand-story', 'team', 'certificates', 'stats-grid'],
        ],
        'about-brand' => [
            'fa' => 'درباره برند', 'icon' => 'ℹ️', 'inner' => true,
            'heroTitle' => 'درباره این برند',
            'heroSub' => 'تاریخچه، خط تولید و سرویس‌های رسمی پس از فروش',
            'core' => ['text-image', 'brand-values', 'timeline', 'brand-intro-card'],
        ],
        'contact' => [
            'fa' => 'تماس با ما', 'icon' => '📞', 'inner' => true,
            'heroTitle' => 'راه‌های ارتباط با ما',
            'heroSub' => 'تلفن، آدرس و فرم پیام — پاسخگویی ۷ روز هفته',
            'core' => ['contact-cards', 'contact-form', 'map', 'working-hours'],
        ],
        'request' => [
            'fa' => 'ثبت درخواست', 'icon' => '📝', 'inner' => true,
            'heroTitle' => 'درخواست تعمیر آنلاین',
            'heroSub' => 'فرم را پر کنید — کارشناسان ما تماس می‌گیرند',
            'core' => ['request-form', 'steps-process', 'quick-contact-form'],
        ],
        'other-brands' => [
            'fa' => 'سایر برندها', 'icon' => '🏷️', 'inner' => true,
            'heroTitle' => 'برندهای دیگر زیر پوشش',
            'heroSub' => 'علاوه بر این برند، خدمات این برندها را هم ارائه می‌دهیم',
            'core' => ['brands-links', 'logo-strip', 'brand-badges-row'],
        ],
        'error-codes' => [
            'fa' => 'کدهای خطا', 'icon' => '🚨', 'inner' => true,
            'heroTitle' => 'کدهای خطای دستگاه‌ها',
            'heroSub' => 'کد خطا را جستجو کنید — علت و راه‌حل فارسی',
            'core' => ['search-bar', 'device-error-lookup', 'warning-box'],
        ],
        'faq' => [
            'fa' => 'سوالات متداول', 'icon' => '❓', 'inner' => true,
            'heroTitle' => 'سوالات متداول',
            'heroSub' => 'پاسخ شفاف به رایج‌ترین پرسش‌های شما',
            'core' => ['faq-search', 'faq-accordion', 'faq-category', 'quick-contact-form'],
        ],
        'sitemap-page' => [
            'fa' => 'نقشه سایت', 'icon' => '🗺️', 'inner' => true, 'thin' => true,
            'heroTitle' => 'نقشه سایت',
            'heroSub' => 'دسترسی سریع به همه صفحات',
            'core' => ['link-buttons', 'icon-list'],
        ],
        'terms' => [
            'fa' => 'قوانین', 'icon' => '📜', 'inner' => true, 'thin' => true,
            'heroTitle' => 'شرایط و قوانین استفاده',
            'heroSub' => 'لطفاً پیش از ثبت درخواست مطالعه کنید',
            'core' => ['text-columns', 'info-box'],
        ],
        'privacy' => [
            'fa' => 'حریم خصوصی', 'icon' => '🔒', 'inner' => true, 'thin' => true,
            'heroTitle' => 'سیاست حفظ حریم خصوصی',
            'heroSub' => 'با داده‌های شما چه می‌کنیم',
            'core' => ['text-columns', 'info-box'],
        ],
        'about' => [
            'fa' => 'درباره (عمومی قالب‌ساز)', 'icon' => 'ℹ️', 'inner' => true,
            'heroTitle' => 'درباره ما',
            'heroSub' => 'کی هستیم و چه می‌کنیم',
            'core' => ['text-image', 'stats-grid', 'features', 'brand-story'],
        ],
        'custom' => [
            'fa' => 'صفحه سفارشی', 'icon' => '🧩', 'inner' => true,
            'heroTitle' => 'عنوان صفحه سفارشی',
            'heroSub' => 'زیرعنوان توضیحی این صفحه',
            'core' => ['text', 'features', 'rich-text'],
        ],
    ];

    /* ==================================================
     * ℹ️ فراداده
     * ================================================== */

    /** 📇 اطلاعات کتابخانه */
    public static function info(): array
    {
        return [
            'library'   => 'کتابخانه قالب‌های آماده',
            'version'   => self::LIB_VERSION,
            'styles'    => count(self::STYLES),
            'page_types' => count(self::PAGE_META),
            'total'     => count(self::STYLES) * count(self::PAGE_META),
            'styles_fa' => array_map(static fn($s) => $s['icon'] . ' ' . $s['fa'], self::STYLES),
        ];
    }

    /** 📚 فهرست انواع صفحه (کلید => نام فارسی) */
    public static function pageTypeLabels(): array
    {
        $out = [];
        foreach (self::PAGE_META as $type => $meta) {
            $out[$type] = $meta['icon'] . ' ' . $meta['fa'];
        }
        return $out;
    }

    /** 🎨 فهرست سبک‌ها (کلید => نام فارسی) */
    public static function styleLabels(): array
    {
        $out = [];
        foreach (self::STYLES as $key => $s) {
            $out[$key] = $s['icon'] . ' ' . $s['fa'];
        }
        return $out;
    }

    /* ==================================================
     * 🏗️ ساخت چیدمان — ترکیب سبک + نوع صفحه
     * ================================================== */

    /**
     * 🏗️ ساخت چیدمان کامل یک قالب
     *
     * @param string $pageType نوع صفحه
     * @param string $styleKey کلید سبک
     * @return array چیدمان [{block, props}, ...]
     */
    public static function buildLayout(string $pageType, string $styleKey): array
    {
        $meta = self::PAGE_META[$pageType] ?? self::PAGE_META['custom'];
        $style = self::STYLES[$styleKey] ?? self::STYLES['modern'];
        $layout = [];
        $props = static function (string $bg = 'default', string $pad = 'default'): array {
            return ['background' => $bg, 'padding' => $pad, 'visible' => true];
        };

        /* ---------- ۱) هدر (و نوار بالایی/اطلاعیه) ---------- */
        foreach ($style['header'] as $headerBlock => $headerProps) {
            $layout[] = ['block' => $headerBlock, 'props' => array_merge($props(), $headerProps)];
        }

        /* ---------- ۲) بردکرامب صفحات داخلی ---------- */
        if (!empty($meta['inner'])) {
            $layout[] = ['block' => 'breadcrumb', 'props' => $props('default', 'compact')];
        }

        /* ---------- ۳) هیرو (یا جایگزین سبک) ---------- */
        $heroProps = array_merge($props($style['hero_bg'], 'roomy'), [
            'title'    => $meta['heroTitle'],
            'subtitle' => $meta['heroSub'],
        ]);
        if ($styleKey === 'conversion') {
            $heroProps['btnText'] = 'ثبت درخواست';
        }
        $layout[] = ['block' => $style['hero'], 'props' => $heroProps];

        /* ---------- ۴) هسته محتوایی با ریتم سبک ---------- */
        $rhythm = $style['rhythm'];
        $coreBlocks = $meta['core'];
        if (!empty($style['maxCore'])) {
            $coreBlocks = array_slice($coreBlocks, 0, (int)$style['maxCore']);
        }
        $i = 0;
        foreach ($coreBlocks as $coreBlock) {
            $bg = $rhythm[$i % count($rhythm)];
            $layout[] = ['block' => $coreBlock, 'props' => $props($bg, $styleKey === 'minimal' ? 'default' : 'default')];
            $i++;
        }

        /* ---------- ۵) بلوک‌های تاکیدی سبک (فقط صفحات غیرنازک) ---------- */
        if (empty($meta['thin'])) {
            $existing = array_column($layout, 'block');
            foreach ($style['accents'] as $accent) {
                if (!in_array($accent, $existing, true)) {
                    $layout[] = ['block' => $accent, 'props' => $props($rhythm[($i++) % count($rhythm)], 'roomy')];
                    $existing[] = $accent;
                }
            }
        }

        /* ---------- ۶) CTA پایانی ---------- */
        $ctaBlock = $style['cta'];
        // جلوگیری از تکرار دوباره همان بلوک CTA (مثلاً luxury با price-highlight)
        if ($ctaBlock !== 'price-highlight' || !in_array('price-highlight', array_column($layout, 'block'), true)) {
            $layout[] = ['block' => $ctaBlock, 'props' => $props($style['cta_bg'], $style['cta_pad'])];
        }

        /* ---------- ۷) فوتر ---------- */
        $layout[] = ['block' => $style['footer'], 'props' => $props('dark', 'compact')];

        return $layout;
    }

    /** 🏷️ نام قالب برای جفت (نوع صفحه، سبک) */
    public static function templateName(string $pageType, string $styleKey): string
    {
        $meta = self::PAGE_META[$pageType] ?? self::PAGE_META['custom'];
        $style = self::STYLES[$styleKey] ?? self::STYLES['modern'];
        return $meta['fa'] . ' — ' . $style['fa'];
    }

    /** 🏷️ مقدار variant کتابخانه (نشانه idempotent) */
    public static function variantLabel(string $styleKey): string
    {
        $style = self::STYLES[$styleKey] ?? self::STYLES['modern'];
        return 'سبک: ' . $style['fa'];
    }

    /* ==================================================
     * 🌱 درج کتابخانه — idempotent و غیرمخرب
     * ================================================== */

    /**
     * 🌱 درج قالب‌های غایب کتابخانه
     *
     * قالب‌های موجود (دستی یا پیش‌فرض فعلی) هرگز لمس نمی‌شوند؛ فقط جفت‌های
     * (نوع صفحه × سبک) که در جدول نیستند درج می‌شوند. برای هر نوع صفحه
     * فقط در صورت نبود «هیچ» پیش‌فرضی، سبک مدرن پیش‌فرض می‌شود.
     *
     * @param Database $db اتصال دیتابیس
     * @return array ['inserted' => int, 'defaults_set' => int, 'missing_before' => array]
     */
    public static function seedMissing(Database $db): array
    {
        $existing = [];
        $rows = $db->fetchAll('SELECT page_type, variant FROM templates');
        foreach ($rows as $row) {
            $existing[(string)$row['page_type'] . '|' . (string)$row['variant']] = true;
        }
        // وضعیت پیش‌فرض هر نوع صفحه
        $hasDefaultByType = [];
        $defaults = $db->fetchAll('SELECT page_type FROM templates WHERE is_default = 1');
        foreach ($defaults as $d) {
            $hasDefaultByType[(string)$d['page_type']] = true;
        }

        $inserted = 0;
        $defaultsSet = 0;
        foreach (self::PAGE_META as $pageType => $meta) {
            foreach (self::STYLES as $styleKey => $style) {
                $variant = self::variantLabel($styleKey);
                if (isset($existing[$pageType . '|' . $variant])) {
                    continue;
                }
                $isDefault = 0;
                if (empty($hasDefaultByType[$pageType]) && $styleKey === 'modern') {
                    $isDefault = 1;
                    $hasDefaultByType[$pageType] = true;
                    $defaultsSet++;
                }
                $db->insert('templates', [
                    'name'        => self::templateName($pageType, $styleKey),
                    'page_type'   => $pageType,
                    'variant'     => $variant,
                    'layout_json' => json_encode(self::buildLayout($pageType, $styleKey), JSON_UNESCAPED_UNICODE),
                    'is_default'  => $isDefault,
                ]);
                $inserted++;
            }
        }
        return ['inserted' => $inserted, 'defaults_set' => $defaultsSet];
    }

    /** 🔢 تعداد قالب کتابلایه موجود در دیتابیس (برای نمایش) */
    public static function countLibraryTemplates(Database $db): int
    {
        $variants = array_map(static fn($k) => self::variantLabel($k), array_keys(self::STYLES));
        $in = implode(',', array_fill(0, count($variants), '?'));
        return (int)$db->fetchValue('SELECT COUNT(*) FROM templates WHERE variant IN (' . $in . ')', $variants);
    }
}
