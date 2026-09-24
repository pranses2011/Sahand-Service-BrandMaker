<?php
/**
 * 🖼️ سرویس تصاویر مقاله — ArticleImageService v3.0
 * ================================================================
 * هر مقاله تولیدی موتور سهند با ۳ تصویر مرتبط عرضه می‌شود:
 *   📌 بنر اصلی (featured) + ۲ تصویر درون‌متن
 *
 * 🆕 v3.0 (طبق گزارش کاربر — «برای راهنمای خرید یخچال تصویر لباسشویی می‌گذارد»):
 *   🎯 بسته از ۹ به ۱۸ عکس واقعی گسترش یافت (مایکروویو، فر و اجاق، هود،
 *      آبگرمکن، جاروبرقی، پکیج دیواری، لوازم کوچک، ویترین یخچال، دریچه لباسشویی)
 *   🗺️ DEVICE_MAP بازنویسی — تصویر اول هر دستگاه دقیقاً همان دستگاه است
 *      (قبلاً پکیج → کولر گازی و جاروبرقی → تصاویر عمومی می‌افتاد)
 *   🐛 ریشه اصلی: ArticleGenerator دستگاه را «تصادفی» انتخاب می‌کرد وقتی
 *      عنوان دلخواه بدون device_key می‌آمد — حالا از عنوان استنتاج می‌شود
 *   🛡 فقط فایل‌های موجود بسته انتخاب می‌شوند
 * 🆕 v2.0 (طبق گزارش کاربر — «تصویر لباسشویی برای مقاله یخچال»):
 *   🔎 inferDeviceKey() — وقتی کلید دستگاه خالی/نامعتبر است، دستگاه از
 *      «عنوان مقاله» استنتاج می‌شود (۴۲ دستگاه دانش + واژه‌های محاوره‌ای)
 *   🗺️ نگاشت کامل ۴۲ کلید دانش → تصاویر بسته (قبلاً فقط ۱۲ کلید ناقص —
 *      tv/vacuum/range_hood اصلاً نگاشت نشده بودند و به تصاویر عمومی می‌افتاد)
 *   🗺️ نگاشت هر ۲۵ نوع مقاله (buying_guide و ۱۸ نوع دیگر جا مانده بودند →
 *      تصویر دوم همیشه washing_machine می‌شد!)
 *   🪧 نسخه واترمارک‌شده هر تصویر: لوگوی برند گوشه پایین-راست + لوگوی
 *      نمایندگی گوشه پایین-چپ — هر دو با زمینه شفاف (ImageWatermark)
 *   🐛 رفع نام‌فایل: ثابت‌ها با خط تیره (washing-machine.jpg) — قبلاً
 *      washing_machine.jpg که وجود نداشت!
 *
 * @package SahandBrandMaker\Engine
 * @version 3.0
 */
class ArticleImageService
{
    /** 🖼️ نام فایل‌های بسته تصاویر (با خط تیره — مطابق نام واقعی فایل‌ها) */
    const IMAGE_FILES = [
        'refrigerator', 'fridge-showroom', 'washing-machine', 'washer-drum',
        'dishwasher', 'air-conditioner', 'television', 'workshop', 'spare-parts',
        'diagnostics', 'modern-kitchen', 'microwave', 'oven-stove', 'range-hood',
        'water-heater', 'vacuum', 'package-boiler', 'small-appliances',
    ];

    /** 🔢 تعداد تصاویر هر مقاله */
    const IMAGES_PER_ARTICLE = 3;

    /** 🗺️ نگاشت کلید دستگاه دانش → تصاویر مرتبط (به‌ترتیب اولویت) — پوشش هر ۴۲ کلید */
    const DEVICE_MAP = [
        'refrigerator'      => ['refrigerator', 'fridge-showroom', 'modern-kitchen'],
        'freezer'           => ['refrigerator', 'fridge-showroom', 'diagnostics'],
        'wine_cooler'       => ['refrigerator', 'fridge-showroom', 'modern-kitchen'],
        'washing_machine'   => ['washing-machine', 'washer-drum', 'diagnostics'],
        'dryer'             => ['washer-drum', 'washing-machine', 'diagnostics'],
        'dishwasher'        => ['dishwasher', 'modern-kitchen', 'diagnostics'],
        'air_conditioner'   => ['air-conditioner', 'diagnostics', 'workshop'],
        'cooler'            => ['air-conditioner', 'diagnostics', 'workshop'],
        'ducted_split'      => ['air-conditioner', 'workshop', 'diagnostics'],
        'package'           => ['package-boiler', 'water-heater', 'workshop'],
        'fan_coil'          => ['air-conditioner', 'workshop', 'diagnostics'],
        'tv'                => ['television', 'diagnostics', 'workshop'],
        'microwave'         => ['microwave', 'modern-kitchen', 'workshop'],
        'oven'              => ['oven-stove', 'modern-kitchen', 'diagnostics'],
        'stove'             => ['oven-stove', 'modern-kitchen', 'workshop'],
        'cooktop'           => ['oven-stove', 'range-hood', 'modern-kitchen'],
        'range_hood'        => ['range-hood', 'modern-kitchen', 'workshop'],
        'vacuum'            => ['vacuum', 'workshop', 'spare-parts'],
        'steam_cleaner'     => ['vacuum', 'workshop', 'spare-parts'],
        'water_heater'      => ['water-heater', 'package-boiler', 'workshop'],
        'solar_water_heater'=> ['water-heater', 'workshop', 'diagnostics'],
        'air_purifier'      => ['vacuum', 'workshop', 'spare-parts'],
        'dehumidifier'      => ['air-conditioner', 'workshop', 'spare-parts'],
        'water_dispenser'   => ['refrigerator', 'fridge-showroom', 'diagnostics'],
        'kettle'            => ['small-appliances', 'modern-kitchen', 'workshop'],
        'air_fryer'         => ['small-appliances', 'modern-kitchen', 'workshop'],
        'blender'           => ['small-appliances', 'modern-kitchen', 'workshop'],
        'juicer'            => ['small-appliances', 'modern-kitchen', 'workshop'],
        'mixer'             => ['small-appliances', 'modern-kitchen', 'workshop'],
        'food_processor'    => ['small-appliances', 'modern-kitchen', 'workshop'],
        'meat_grinder'      => ['small-appliances', 'modern-kitchen', 'workshop'],
        'toaster'           => ['small-appliances', 'modern-kitchen', 'workshop'],
        'sandwich_maker'    => ['small-appliances', 'modern-kitchen', 'workshop'],
        'fryer'             => ['small-appliances', 'modern-kitchen', 'workshop'],
        'rice_cooker'       => ['small-appliances', 'modern-kitchen', 'workshop'],
        'tea_maker'         => ['small-appliances', 'modern-kitchen', 'workshop'],
        'coffee_maker'      => ['small-appliances', 'modern-kitchen', 'workshop'],
        'iron'              => ['small-appliances', 'workshop', 'diagnostics'],
        'hair_dryer'        => ['small-appliances', 'workshop', 'diagnostics'],
        'hair_clipper'      => ['small-appliances', 'workshop', 'diagnostics'],
        'fan'               => ['workshop', 'diagnostics', 'spare-parts'],
        'heater'            => ['water-heater', 'workshop', 'diagnostics'],
    ];

    /** 🔁 نام‌های قدیمی/مترادف کلید دستگاه → کلید دانش */
    const DEVICE_ALIASES = [
        'television'     => 'tv',
        'vacuum_cleaner' => 'vacuum',
        'hood'           => 'range_hood',
        'washingmachine' => 'washing_machine',
        'washer'         => 'washing_machine',
        'ac'             => 'air_conditioner',
        'split'          => 'air_conditioner',
        'fridge'         => 'refrigerator',
    ];

    /** 🗺️ نگاشت هر ۲۵ نوع مقاله → تصاویر پیشنهادی */
    const TOPIC_TYPE_MAP = [
        'troubleshooting'  => ['diagnostics', 'workshop', 'spare-parts'],
        'user_guide'       => ['modern-kitchen', 'diagnostics', 'workshop'],
        'maintenance'      => ['diagnostics', 'workshop', 'modern-kitchen'],
        'comparison'       => ['modern-kitchen', 'workshop', 'spare-parts'],
        'error_codes'      => ['diagnostics', 'spare-parts', 'workshop'],
        'buying_guide'     => ['fridge-showroom', 'modern-kitchen', 'workshop'],
        'energy_saving'    => ['diagnostics', 'modern-kitchen', 'workshop'],
        'seasonal_care'    => ['air-conditioner', 'refrigerator', 'modern-kitchen'],
        'cost_guide'       => ['diagnostics', 'spare-parts', 'workshop'],
        'safety_guide'     => ['workshop', 'diagnostics', 'spare-parts'],
        'installation_guide' => ['workshop', 'diagnostics', 'modern-kitchen'],
        'diy_vs_pro'       => ['workshop', 'diagnostics', 'spare-parts'],
        'common_mistakes'  => ['diagnostics', 'workshop', 'modern-kitchen'],
        'warranty_guide'   => ['workshop', 'spare-parts', 'diagnostics'],
        'tech_explainer'   => ['diagnostics', 'spare-parts', 'workshop'],
        'myths_facts'      => ['diagnostics', 'modern-kitchen', 'workshop'],
        'checklist'        => ['diagnostics', 'workshop', 'modern-kitchen'],
        'case_study'       => ['workshop', 'diagnostics', 'modern-kitchen'],
        'glossary'         => ['spare-parts', 'diagnostics', 'workshop'],
        'history_evolution'=> ['television', 'refrigerator', 'modern-kitchen'],
        'expert_tips'      => ['workshop', 'diagnostics', 'modern-kitchen'],
        'symptom_focus'    => ['diagnostics', 'workshop', 'spare-parts'],
        'statistics'       => ['diagnostics', 'modern-kitchen', 'workshop'],
        'environment'      => ['refrigerator', 'air-conditioner', 'modern-kitchen'],
        'service_process'  => ['workshop', 'diagnostics', 'spare-parts'],
    ];

    /** 🔎 واژه‌های محاوره‌ای برای استنتاج دستگاه از عنوان (کلید → کلید دانش) */
    const TITLE_KEYWORDS = [
        'یخچال' => 'refrigerator', 'فریزر' => 'freezer', 'ساید' => 'refrigerator',
        'لباسشویی' => 'washing_machine', 'لباس شویی' => 'washing_machine', 'واشینگ' => 'washing_machine',
        'ظرفشویی' => 'dishwasher', 'ظرف شویی' => 'dishwasher',
        'کولر' => 'air_conditioner', 'اسپیلت' => 'air_conditioner', 'اسپلیت' => 'air_conditioner',
        'پکیج' => 'package', 'پكيج' => 'package',
        'تلویزیون' => 'tv', 'تی وی' => 'tv', 'اسمارت_tv' => 'tv',
        'جاروبرقی' => 'vacuum', 'جارو برقی' => 'vacuum', 'جاروشارژی' => 'vacuum',
        'مایکروویو' => 'microwave', 'میکروویو' => 'microwave', 'میکرو موجی' => 'microwave',
        'اجاق' => 'oven', 'فر' => 'oven', 'گاز' => 'stove', 'هاب' => 'cooktop',
        'هود' => 'range_hood', 'سینی' => 'cooktop',
        'آبگرمکن' => 'water_heater', 'آب گرم کن' => 'water_heater', 'برقی_آبگرمکن' => 'water_heater',
        'آب سردکن' => 'water_dispenser', 'آبسردکن' => 'water_dispenser', 'دیسپنسر' => 'water_dispenser',
        'سرخکن' => 'air_fryer', 'سرخ کن' => 'air_fryer', 'ایرفرایر' => 'air_fryer',
        'کتری' => 'kettle', 'چای ساز' => 'tea_maker', 'قهوه ساز' => 'coffee_maker', 'اسپرسو' => 'coffee_maker',
        'مخلوط کن' => 'blender', 'مخلوطکن' => 'blender', 'غذاساز' => 'food_processor',
        'آبمیوه گیری' => 'juicer', 'آبمیوه‌گیری' => 'juicer', 'چرخگوشت' => 'meat_grinder', 'چرخ گوشت' => 'meat_grinder',
        'توست' => 'toaster', 'ساندویچ ساز' => 'sandwich_maker', 'ساندویچ‌ساز' => 'sandwich_maker',
        'سرخ کن عمیق' => 'fryer', 'برنج پز' => 'rice_cooker', 'اتو' => 'iron',
        'سشوار' => 'hair_dryer', 'ماشین اصلاح' => 'hair_clipper', 'اصلاح مو' => 'hair_clipper',
        'بخارشور' => 'steam_cleaner', 'بخار شو' => 'steam_cleaner',
        'تصفیه هوا' => 'air_purifier', 'رطوبت گیر' => 'dehumidifier', 'رطوبت‌گیر' => 'dehumidifier',
        'پنکه' => 'fan', 'بخاری' => 'heater', 'هیتر' => 'heater',
        'خشک کن' => 'dryer', 'خشککن' => 'dryer', 'یخساز' => 'freezer', 'آوین' => 'wine_cooler',
        /* 🆕 v3.2: مترادف‌های بیشتر برای استنتاج دقیق‌تر موضوع مقاله */
        'تلوزیون' => 'tv', 'تلویزیون هوشمند' => 'tv', 'جعبه جادو' => 'tv',
        'ماشین لباسشویی' => 'washing_machine', 'ماشین لباس شویی' => 'washing_machine', 'ماشین ظرفشویی' => 'dishwasher',
        'جارو' => 'vacuum', 'جارو برقی خانگی' => 'vacuum',
        'کولر آبی' => 'cooler', 'کولر گازی' => 'air_conditioner', 'آبگیر' => 'cooler',
        'یخچال فریزر' => 'refrigerator', 'ساید بای ساید' => 'refrigerator', 'نویزفری' => 'freezer',
        'پلوپز' => 'rice_cooker', 'پلو پز' => 'rice_cooker', 'سرخکن هوا' => 'air_fryer',
        'چایساز' => 'tea_maker', 'سماور برقی' => 'tea_maker', 'کتری برقی' => 'kettle',
        'غذا ساز' => 'food_processor', 'مخلوط‌کن' => 'blender', 'آبمیوه‌ساز' => 'juicer',
        'سشوار مو' => 'hair_dryer', 'اصلاح‌کن' => 'hair_clipper',
        'بخارشوی' => 'steam_cleaner', 'اتو بخار' => 'iron', 'اتوکاره' => 'iron',
        'هواساز' => 'air_purifier', 'تصفیه‌کننده هوا' => 'air_purifier',
        'آبگرمکن برقی' => 'water_heater', 'آب گرم کنی' => 'water_heater', 'بویلر' => 'package',
        'هود آشپزخانه' => 'range_hood', 'هاب برقی' => 'cooktop',
        'اجاق گاز' => 'stove', 'فر برقی' => 'oven', 'فر توکار' => 'oven', 'مایکروویو خانگی' => 'microwave',
    ];

    /**
     * 🔎 استنتاج کلید دستگاه از عنوان (و در صورت وجود، متن مقاله)
     * طولانی‌ترین تطبیق برنده است («ماشین لباسشویی» بر «ماشین» مقدم است)
     */
    public static function inferDeviceKey(string $title, string $content = ''): ?string
    {
        $haystack = $title . "\n" . mb_substr($content, 0, 2000);
        if (trim($haystack) === '') {
            return null;
        }

        /* ۱) واژه‌های محاوره‌ای — طولانی‌ترین برتر (تطبیق دقیق‌تر) */
        $hits = [];
        foreach (self::TITLE_KEYWORDS as $word => $key) {
            if ($word === '' || mb_strlen($word) < 2) {
                continue;
            }
            if (mb_stripos($haystack, $word) !== false) {
                $hits[$key] = max($hits[$key] ?? 0, mb_strlen($word));
            }
        }
        if ($hits) {
            arsort($hits);
            return (string)array_key_first($hits);
        }

        /* ۲) نام رسمی دستگاه‌ها از پایگاه دانش (مثل «ماشین لباسشویی») */
        try {
            $devices = TextProcessor::loadKnowledge('devices') ?: [];
        } catch (Throwable $e) {
            $devices = [];
        }
        $best = null;
        $bestLen = 0;
        foreach ($devices as $key => $d) {
            $names = array_filter([
                (string)($d['name_fa'] ?? ''),
                (string)($d['name_en'] ?? ''),
            ]);
            foreach ($names as $name) {
                $name = trim($name);
                if (mb_strlen($name) >= 3 && mb_stripos($haystack, $name) !== false) {
                    $len = mb_strlen($name);
                    if ($len > $bestLen) {
                        $bestLen = $len;
                        $best = (string)$key;
                    }
                }
            }
        }
        return $best;
    }

    /** 🔁 نرمال‌سازی کلید دستگاه (نام‌های مترادف → کلید دانش) */
    public static function normalizeDeviceKey(?string $deviceKey): ?string
    {
        $k = trim((string)$deviceKey);
        if ($k === '') {
            return null;
        }
        $k = str_replace(['-', ' '], '_', strtolower($k));
        if (isset(self::DEVICE_ALIASES[$k])) {
            $k = self::DEVICE_ALIASES[$k];
        }
        return isset(self::DEVICE_MAP[$k]) ? $k : null;
    }

    /**
     * 🖼️ انتخاب ۳ تصویر مرتبط برای مقاله + نسخه واترمارک‌شده
     *
     * @param string|null $deviceKey کلید دستگاه (اختیاری — از عنوان استنتاج می‌شود)
     * @param string      $topicType نوع مقاله
     * @param string      $title     عنوان مقاله (برای alt/کپشن و استنتاج دستگاه)
     * @param string      $focusKw   کلیدواژه کانونی
     * @param array|null  $brand     رکورد برند (برای واترمارک لوگو)
     * @return array<int, array{file:string, path:string, url:string, alt:string, caption:string, role:string, device_inferred?:string}>
     */
    public function pick(?string $deviceKey, string $topicType, string $title, string $focusKw = '', ?array $brand = null, string $content = ''): array
    {
        $devices = [];
        try {
            $devices = TextProcessor::loadKnowledge('devices') ?: [];
        } catch (Throwable $e) {
        }

        /* 🎯 v2: نرمال‌سازی + استنتاج از عنوان/محتوا وقتی خالی/نامعتبر است
           (v3.2: متن کامل مقاله هم بررسی می‌شود — دقت مرتبط‌بودن تصاویر بالاتر) */
        $inferred = null;
        $normKey = self::normalizeDeviceKey($deviceKey);
        if ($normKey === null) {
            $inferred = self::inferDeviceKey($title, $content);
            $normKey = self::normalizeDeviceKey($inferred);
        }
        $deviceFa = $devices[$normKey]['name_fa'] ?? '';

        /* 🎯 اولویت اول: نگاشت مستقیم دستگاه (اگر دستگاه شناخته شد، تصویر غیرمرتبط ممنوع) */
        $candidates = $normKey !== null ? (self::DEVICE_MAP[$normKey] ?? []) : [];
        /* 🎯 اولویت دوم: نوع مقاله */
        foreach (self::TOPIC_TYPE_MAP[$topicType] ?? [] as $img) {
            if (!in_array($img, $candidates, true)) {
                $candidates[] = $img;
            }
        }
        /* 🎯 اولویت سوم: بقیه تصاویر بسته — فقط تا سقف، و بدون تکرار */
        foreach (self::IMAGE_FILES as $img) {
            if (count($candidates) >= self::IMAGES_PER_ARTICLE + 2) {
                break;
            }
            if (!in_array($img, $candidates, true)) {
                $candidates[] = $img;
            }
        }

        /* 🛡 v3.0: فقط فایل‌های موجود — تصویر غایب هرگز انتخاب نمی‌شود */
        $candidates = array_values(array_filter($candidates, static function ($f) {
            return is_file(dirname(__DIR__, 2) . '/assets/images/articles/' . $f . '.jpg');
        }));

        $chosen = array_slice($candidates, 0, self::IMAGES_PER_ARTICLE);

        $images = [];
        $roles = ['featured', 'inline_mid', 'inline_end'];
        $roleLabels = [
            'featured'   => 'نمای اصلی مقاله',
            'inline_mid' => 'مرحله بررسی تخصصی',
            'inline_end' => 'جمع‌بندی و نکات کارشناسی',
        ];
        foreach ($chosen as $i => $file) {
            $subject = $deviceFa !== '' ? $deviceFa : ($focusKw !== '' ? $focusKw : 'لوازم خانگی');
            $alt = $title !== ''
                ? $title . ' — ' . ($deviceFa !== '' ? $deviceFa . '، ' : '') . 'تعمیر تخصصی لوازم خانگی'
                : $subject . ' — تعمیر تخصصی توسط کارشناس سهند سرویس';

            $srcRel = 'assets/images/articles/' . $file . '.jpg';
            /* 🪧 v2: نسخه واترمارک‌شده (لوگوی برند پایین-راست + نمایندگی پایین-چپ، شفاف) */
            $finalRel = $srcRel;
            try {
                $wm = ImageWatermark::stampedCopy($srcRel, $brand, 'b' . (int)($brand['id'] ?? 0));
                if (is_string($wm)) {
                    $finalRel = $wm;
                }
            } catch (Throwable $e) {
                // بدون واترمارک بهتر از شکستن تصویر است
            }

            $images[] = [
                'file'    => $file,
                'path'    => $finalRel,
                'url'     => rtrim(BASE_URL, '/') . '/' . $finalRel,
                'alt'     => mb_substr($alt, 0, 160),
                'caption' => mb_substr(($deviceFa !== '' ? $deviceFa : $subject) . ' — ' . $roleLabels[$roles[$i]], 0, 160),
                'role'    => $roles[$i],
            ] + ($inferred !== null && $i === 0 ? ['device_inferred' => $inferred] : []);
        }
        return $images;
    }

    /**
     * 🌐 URL مطلق تصویر (برای تلگرام و سایت‌های دیگر)
     */
    public static function absoluteUrl(string $file): string
    {
        return rtrim(BASE_URL, '/') . '/assets/images/articles/' . $file . '.jpg';
    }

    /**
     * 🧹 حذف تصاویر تولیدی قبلی مقاله از محتوا (v2.14)
     *
     * طبق درخواست: «با هر بار کلیک روی تولید مجدد تصاویر مقاله، تصاویر جدید
     * جایگزین تصاویر قبلی شوند (در صورت وجود)» — قبل از درج تصاویر تازه،
     * همه <figure> های حاوی تصویر تولیدی موتور (uploads/articles/ai/ یا
     * uploads/articles/wm/) از محتوا حذف می‌شوند؛ تصاویر آپلودی دستی کاربر
     * (مسیرهای دیگر) دست‌نخورده می‌مانند.
     */
    public function stripGeneratedFigures(string $content): string
    {
        if (trim($content) === '') {
            return $content;
        }
        /* فقط figure های دارای تصویر در پوشه‌های تولیدی موتور */
        $pattern = '#<figure\b[^>]*>\s*<img[^>]*src=["\'][^"\']*/uploads/articles/(?:ai|wm)/[^"\']*["\'][^>]*>\s*(?:<figcaption>[^<]*</figcaption>\s*)?</figure>#ius';
        $clean = preg_replace($pattern, '', $content);
        /* حذف فاصله‌های خالی حاصل (۲+ خط جدید → ۱) */
        $clean = preg_replace("/(?:\n\s*){3,}/u", "\n\n", (string)$clean);
        return trim((string)$clean) !== '' ? (string)$clean : $content;
    }

    /**
     * 🧩 درج تصاویر در جایگاه‌های راهبردی محتوای HTML
     *
     * @param string $content HTML مقاله
     * @param array  $images  خروجی pick()
     * @param bool   $inlineFeatured آیا تصویر شاخص هم داخل متن درج شود (پیش‌فرض: خیر — بنر جدا)
     * @return string HTML غنی‌شده با <figure>
     */
    public function injectIntoContent(string $content, array $images, bool $inlineFeatured = false): string
    {
        if (!$images) {
            return $content;
        }
        // تقسیم محتوا به پاراگراف‌های سطح-بالا
        $parts = preg_split('/(<\/(?:p|h2|h3|ul|ol|table)>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($parts) < 4) {
            return $content . $this->figures($images, $inlineFeatured ? 0 : 1);
        }
        $blocks = [];
        for ($i = 0; $i < count($parts); $i += 2) {
            $blocks[] = ($parts[$i] ?? '') . ($parts[$i + 1] ?? '');
        }
        $total = count($blocks);
        $list = $inlineFeatured ? $images : array_slice($images, 1); // بدون بنر
        if (!$list) {
            return $content;
        }

        // جایگاه‌ها: ~۳۵٪ و ~۷۰٪ طول مقاله
        $positions = (int)floor($total * 0.35);
        $positions = max(2, min($positions, $total - 1));
        $second = max($positions + 2, min((int)floor($total * 0.72), $total - 1));
        $insertAt = [$positions, $second];

        $out = [];
        $imgIdx = 0;
        foreach ($blocks as $bi => $block) {
            $out[] = $block;
            if (isset($insertAt[$imgIdx]) && $bi === $insertAt[$imgIdx] && $imgIdx < count($list)) {
                $out[] = $this->figure($list[$imgIdx]);
                $imgIdx++;
            }
        }
        // تصاویر باقی‌مانده به انتهای محتوا
        for (; $imgIdx < count($list); $imgIdx++) {
            $out[] = $this->figure($list[$imgIdx]);
        }
        return implode('', $out);
    }

    /**
     * 🏷️ HTML یک <figure> استاندارد سئو
     *
     * 🛡 v3.2: ضدکشیدگی — ابعاد واقعی فایل خوانده می‌شود (قبلاً ۱۳۴۴×۷۶۸
     * هاردکد بود و برای تصاویر با نسبت دیگر باعث کشیدگی می‌شد) + استایل
     * درون‌خطی محافظ (width:100% + height:auto) که روی هر CSS سایتی غلبه
     * می‌کند — حتی سایت‌های مستقرشده با قالب قدیمی.
     */
    public function figure(array $img): string
    {
        $url = htmlspecialchars($img['url'], ENT_QUOTES, 'UTF-8');
        $alt = htmlspecialchars($img['alt'], ENT_QUOTES, 'UTF-8');
        $cap = htmlspecialchars($img['caption'], ENT_QUOTES, 'UTF-8');

        /* 📐 ابعاد واقعی فایل (jpg/png/webp) — SVG از viewBox */
        $w = 1344;
        $h = 768;
        $abs = ROOT_PATH . '/' . ltrim((string)($img['path'] ?? ''), '/');
        if (is_file($abs)) {
            $info = @getimagesize($abs);
            if (is_array($info) && (int)($info[0] ?? 0) > 0 && (int)($info[1] ?? 0) > 0) {
                $w = (int)$info[0];
                $h = (int)$info[1];
            } elseif (strtolower(pathinfo($abs, PATHINFO_EXTENSION)) === 'svg') {
                $svg = (string)@file_get_contents($abs);
                if (preg_match('/viewBox="0 0 (\d+(?:\.\d+)?) (\d+(?:\.\d+)?)"/', $svg, $m)) {
                    $w = (int)round((float)$m[1]);
                    $h = (int)round((float)$m[2]);
                }
            }
        }

        return "\n<figure class=\"article-figure\">\n" .
            "  <img src=\"{$url}\" alt=\"{$alt}\" loading=\"lazy\" width=\"{$w}\" height=\"{$h}\" style=\"width:100%;height:auto;object-fit:contain;display:block\">\n" .
            "  <figcaption>{$cap}</figcaption>\n" .
            "</figure>\n";
    }

    /**
     * 🏷️ چند <figure> پشت‌سرهم (fallback)
     */
    private function figures(array $images, int $from = 0): string
    {
        $out = '';
        foreach (array_slice($images, $from) as $img) {
            $out .= $this->figure($img);
        }
        return $out;
    }

    /**
     * 📊 فهرست کامل تصاویر بسته (برای مستندات و پنل)
     */
    public static function catalog(): array
    {
        $list = [];
        foreach (self::IMAGE_FILES as $file) {
            $path = dirname(__DIR__, 2) . '/assets/images/articles/' . $file . '.jpg';
            $list[$file] = [
                'url'      => self::absoluteUrl($file),
                'exists'   => file_exists($path),
                'size'     => file_exists($path) ? filesize($path) : 0,
            ];
        }
        return $list;
    }
}
