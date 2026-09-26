<?php
/**
 * 📸 AiPhotoService — تولید عکس واقعی مرتبط با موضوع مقاله (v3.0 — ۱۴ سرویس)
 * =========================================================================
 * طبق درخواست کاربر: «برای ساختن تصاویر مقاله، همه سرویس‌های تولید تصویر
 * (هم رایگان و هم با کلید API) داخل تنظیمات باشند تا از لیستشون انتخاب
 * کنیم. و مطمئن شو که سرویس مربوطه استفاده بشه.»
 *
 * 🆕 v3.0 — گسترش رجیستری ۷ → ۱۴ سرویس + رفع باگ‌های زنجیره:
 *   🆓 بدون کلید (۳):
 *     • pollinations_flux   — Pollinations مدل Flux (کیفیت بالا — پیش‌فرض)
 *     • pollinations_turbo  — Pollinations مدل Turbo (سریع‌تر، کیفیت متوسط)
 *     • stablehorde         — AI Horde / Stable Horde (رایگان-اشتراکی، صف عمومی)
 *   🔑 با کلید رایگان (۴):
 *     • huggingface         — Stable Diffusion XL (توکن رایگان — 🆕 endpoint جدید router.huggingface.co)
 *     • deepai              — Text2Img (کلید رایگان)
 *     • together            — FLUX.1-schnell (کلید رایگان)
 *     • fal                 — fal.ai FLUX.1-schnell (اعتبار اولیه رایگان) 🆕
 *   🔑 با کلید اشتراکی (۷):
 *     • stability           — Stability AI SD3 Core
 *     • openai              — DALL·E 3
 *     • openai_gptimage     — OpenAI gpt-image-1 🆕
 *     • gemini              — Google Imagen 3 (کلید Google AI Studio) 🆕
 *     • ideogram            — Ideogram v2 Turbo (تایپوگرافی/پوستر قوی) 🆕
 *     • getimg              — GetImg SDXL 🆕
 *     • replicate           — Replicate FLUX.1-schnell 🆕
 *
 * 🔧 رفع‌های v3.0 (ریشه‌یابی «سرویس انتخابی استفاده نشد»):
 *   • گزارش سرویس «به‌ازای هر تصویر» — قبلاً سرویس تصویر اول به هر ۳ تصویر
 *     نسبت داده می‌شد حتی اگر تصویر ۲/۳ با سرویس جایگزین ساخته شده بود
 *   • کلید خالی برای سرویس انتخابی → اعلان شفاف در گزارش (نه سقوط بی‌صدا)
 *   • تست سلامت: تصویر آزمایشی بزرگ‌تر (۶۴×۶۴ → ۶۴۰×۳۶۰) + متد صحیح GET
 *     برای اندپوینت‌های models (قبلاً POST → 405 → تست همیشه ناموفق!)
 *   • هم‌سازی ابعاد: هر خروجی غیر-۱۶:۹ (مربع SDXL/DeepAI و…) با GD به
 *     ۱۲۰۰×۶۷۵ (۱۶:۹) تبدیل می‌شود — قالب یکنواخت تصاویر مقاله
 *
 * زنجیره اجرا: «سرویس انتخابی تنظیمات» همیشه اول تلاش می‌شود؛ در صورت شکست
 * سرویس‌های بدون کلید بعدی → کلیددارهای دارای کلید → بسته عکس دستگاه (آخرین پناهگاه).
 *
 * @package SahandBrandMaker\Engine
 * @version 3.0
 */
class AiPhotoService
{
    /** 📐 ابعاد تصویر مقاله (۱۶:۹) */
    const W = 1200;
    const H = 675;

    /** ⏱ مهلت هر دانلود (ثانیه) */
    const TIMEOUT = 45;

    /** 🔢 حداکثر تعداد عکس هر مقاله (۱ شاخص + ۲ درون‌متن) */
    const MAX_PER_ARTICLE = 3;

    /** 📊 گیرنده گزارش پیشرفت (v2.15 — نوار پیشرفت تولید تصاویر مقاله) */
    private $progressSink = null;

    /** 📋 اعلان‌های شفاف این اجرا (مثلاً «سرویس انتخابی کلید ندارد») — v3.0 */
    private $notices = [];

    /**
     * 🌐 رجیستری سرویس‌های تولید تصویر — لیست تنظیمات از همین جا خوانده می‌شود
     *
     * کلید => [برچسب فارسی، نیاز به کلید؟، توضیح کلید]
     */
    const SERVICES = [
        /* 🆓 رایگان — بدون کلید */
        'pollinations_flux'  => ['پولینیشنز — Flux',            false, 'رایگان و بدون کلید — کیفیت بالا (پیش‌فرض)'],
        'pollinations_turbo' => ['پولینیشنز — Turbo',            false, 'رایگان و بدون کلید — سریع‌تر، کیفیت متوسط'],
        'stablehorde'        => ['AI Horde — Stable Diffusion',  false, 'رایگان-اشتراکی بدون کلید — صف عمومی (کندتر، ۶۰-۱۲۰ ثانیه)'],
        /* 🆕 v3.1 — چهار سرویس رایگان بدون کلید دیگر (مجموع ۷ سرویس بدون کلید) */
        'loremflickr'        => ['لورم‌فلیکر — عکس واقعی موضوعی', false, 'رایگان و بدون کلید — عکس استوک واقعی بر اساس کلیدواژه دستگاه (سریع)'],
        'wikimedia'          => ['ویکیمدیا کامانز — عکس واقعی',   false, 'رایگان و بدون کلید — عکسهای دانشنامه‌ای واقعی با کلیدواژه'],
        'lexica'             => ['لکسیکا — تصاویر AI موضوعی',    false, 'رایگان و بدون کلید — جستجو در آرشیو گسترده تصاویر Stable Diffusion'],
        'picsum'             => ['Picsum — عکس استوک تصادفی',    false, 'رایگان و بدون کلید — عکس واقعی تصادفی (آخرین جایگزین اضطراری)'],
        /* 🔑 کلید با پلن رایگان */
        'huggingface'        => ['Hugging Face — SDXL',          true,  'توکن رایگان از huggingface.co/settings/tokens'],
        'deepai'             => ['DeepAI — Text2Img',            true,  'کلید رایگان از deepai.org/dashboard/profile'],
        'together'           => ['Together AI — FLUX schnell',   true,  'کلید رایگان از api.together.ai'],
        'fal'                => ['fal.ai — FLUX schnell',        true,  'اعتبار اولیه رایگان از fal.ai/dashboard/keys'],
        /* 🔑 کلید اشتراکی */
        'stability'          => ['Stability AI — SD3 Core',      true,  'کلید از platform.stability.ai'],
        'openai'             => ['OpenAI — DALL·E 3',            true,  'کلید از platform.openai.com'],
        'openai_gptimage'    => ['OpenAI — gpt-image-1',         true,  'کلید از platform.openai.com (کیفیت بالاتر DALL·E 3)'],
        'gemini'             => ['Google — Imagen 3',            true,  'کلید رایگان از aistudio.google.com/apikey'],
        'ideogram'           => ['Ideogram — v2 Turbo',          true,  'کلید از ideogram.ai/api — قوی در پوستر و تایپوگرافی'],
        'getimg'             => ['GetImg — SDXL',                true,  'کلید از getimg.ai (اعتبار اولیه رایگان)'],
        'replicate'          => ['Replicate — FLUX schnell',     true,  'کلید از replicate.com/account/api-tokens'],
    ];

    /** 🗺️ نام انگلیسی دستگاه‌ها برای پرامپت (کلید دانش → عبارت پرامپت) */
    const DEVICE_PROMPTS = [
        'refrigerator'       => 'modern double-door refrigerator',
        'freezer'            => 'chest freezer appliance',
        'wine_cooler'        => 'wine cooler refrigerator',
        'washing_machine'    => 'front-load washing machine',
        'dryer'              => 'clothes dryer appliance',
        'dishwasher'         => 'modern dishwasher',
        'air_conditioner'    => 'split air conditioner indoor unit',
        'cooler'             => 'evaporative air cooler',
        'ducted_split'       => 'ducted air conditioning system',
        'package'            => 'wall-mounted gas boiler heating package',
        'fan_coil'           => 'ceiling fan coil unit',
        'tv'                 => 'large flat-screen smart TV',
        'microwave'          => 'countertop microwave oven',
        'oven'               => 'built-in electric oven',
        'stove'              => 'gas cooktop stove',
        'cooktop'            => 'modern glass cooktop',
        'range_hood'         => 'kitchen range hood',
        'vacuum'             => 'vacuum cleaner',
        'steam_cleaner'      => 'steam cleaner device',
        'water_heater'       => 'wall-mounted water heater',
        'solar_water_heater' => 'rooftop solar water heater',
        'air_purifier'       => 'air purifier device',
        'dehumidifier'       => 'dehumidifier appliance',
        'water_dispenser'    => 'water dispenser',
        'kettle'             => 'electric kettle',
        'air_fryer'          => 'modern air fryer',
        'blender'            => 'kitchen blender',
        'juicer'             => 'juicer machine',
        'mixer'              => 'hand mixer',
        'food_processor'     => 'food processor',
        'meat_grinder'       => 'electric meat grinder',
        'toaster'            => 'toaster',
        'sandwich_maker'     => 'sandwich maker',
        'fryer'              => 'deep fryer',
        'rice_cooker'        => 'rice cooker',
        'tea_maker'          => 'electric tea maker samovar',
        'coffee_maker'       => 'espresso coffee machine',
        'iron'               => 'steam iron',
        'hair_dryer'         => 'hair dryer',
        'hair_clipper'       => 'hair clipper',
        'fan'                => 'pedestal fan',
        'heater'             => 'electric room heater',
    ];

    /** 🎭 سناریوی هر نوع مقاله — دوربین/صحنه/جزئیات (کلید → [صحنه، جزئیات]) */
    const TOPIC_SCENES = [
        'troubleshooting'    => ['a professional appliance repair technician diagnosing {DEVICE} in a bright modern service workshop', 'open tool case with diagnostic tools, spare parts, focused atmosphere'],
        'user_guide'         => ['{DEVICE} in a clean modern home kitchen with soft daylight', 'control panel visible, user-friendly setting'],
        'maintenance'        => ['close-up of professional maintenance service on {DEVICE}', 'technician hands with tools, cleaning and care details'],
        'comparison'         => ['several {DEVICE} units side by side in an appliance showroom', 'clean background, product comparison view'],
        'error_codes'        => ['close-up of the digital display of {DEVICE} with symbols, a technician checking it with a multimeter', 'diagnostic mood, precise details'],
        'buying_guide'       => ['{DEVICE} displayed in a premium appliance store', 'elegant lighting, price-tag-free presentation'],
        'energy_saving'      => ['{DEVICE} in an eco-friendly modern home with green plants', 'soft natural light, fresh atmosphere'],
        'seasonal_care'      => ['{DEVICE} being prepared for the season in a tidy household', 'seasonal warm lighting'],
        'cost_guide'         => ['{DEVICE} on a workbench with repair parts and tools', 'professional workshop environment'],
        'safety_guide'       => ['safe installation check of {DEVICE} by a uniformed technician', 'safety gloves and tools'],
        'installation_guide' => ['technician installing {DEVICE} on a wall in a modern home', 'drill and mounting tools, precise work'],
        'diy_vs_pro'         => ['{DEVICE} with a set of household tools next to professional repair tools', 'split composition'],
        'common_mistakes'    => ['{DEVICE} with warning signs of misuse in a home environment', 'subtle caution mood'],
        'warranty_guide'     => ['{DEVICE} with a service certificate and tools on a table', 'trustworthy professional mood'],
        'tech_explainer'     => ['internal components of {DEVICE} laid out on a repair bench', 'technical detail, gears and electronics'],
        'myths_facts'        => ['{DEVICE} in a bright laboratory-like clean room', 'factual neutral mood'],
        'checklist'          => ['{DEVICE} with a clipboard checklist and tools', 'organized professional setup'],
        'case_study'         => ['before and after repair of {DEVICE} in a workshop', 'transformation view'],
        'glossary'           => ['{DEVICE} with labeled spare parts around it', 'educational display'],
        'history_evolution'  => ['vintage and modern {DEVICE} side by side', 'evolution comparison'],
        'expert_tips'        => ['expert technician giving tips next to {DEVICE}', 'friendly professional mood'],
        'symptom_focus'      => ['{DEVICE} showing a visible problem in a household', 'diagnostic focus'],
        'statistics'         => ['{DEVICE} in a clean minimal studio', 'analytic modern look'],
        'environment'        => ['{DEVICE} in a green sustainable home', 'eco atmosphere'],
        'service_process'   => ['service van and technician arriving to repair {DEVICE}', 'professional service mood'],
    ];

    /** 🌍 پسوند کیفیت پرامپت */
    const STYLE_SUFFIX = 'professional photography, photorealistic, high detail, natural lighting, sharp focus, 16:9 aspect, no text, no words, no watermark, no logo';

    /** 🖼️ کلیدواژه استوک هر دستگاه — v3.1 (سرویسهای عکس واقعی: لورم‌فلیکر/ویکیمدیا/لکسیکا)
     * سرویسهای عکس واقعی با «پرامپت توصیفی» جستجو نمی‌کنند؛ کلیدواژه کوتاه
     * موضوعی لازم دارند (مثل refrigerator). انتخاب با بذر (seed) متنوع می‌شود. */
    const STOCK_KEYWORDS = [
        'refrigerator'       => 'refrigerator',
        'freezer'            => 'freezer',
        'wine_cooler'        => 'wine cooler',
        'washing_machine'    => 'washing machine',
        'dryer'              => 'clothes dryer',
        'dishwasher'         => 'dishwasher',
        'air_conditioner'    => 'air conditioner',
        'cooler'             => 'air cooler',
        'ducted_split'       => 'air conditioning',
        'package'            => 'gas boiler heater',
        'fan_coil'           => 'fan coil',
        'tv'                 => 'television',
        'microwave'          => 'microwave oven',
        'oven'               => 'kitchen oven',
        'stove'              => 'kitchen stove',
        'cooktop'            => 'cooktop',
        'range_hood'         => 'range hood',
        'vacuum'             => 'vacuum cleaner',
        'steam_cleaner'      => 'steam cleaner',
        'water_heater'       => 'water heater',
        'solar_water_heater' => 'solar water heater',
        'air_purifier'       => 'air purifier',
        'dehumidifier'       => 'dehumidifier',
        'water_dispenser'    => 'water dispenser',
        'kettle'             => 'electric kettle',
        'air_fryer'          => 'air fryer',
        'blender'            => 'blender',
        'juicer'             => 'juicer',
        'mixer'              => 'kitchen mixer',
        'food_processor'     => 'food processor',
        'meat_grinder'       => 'meat grinder',
        'toaster'            => 'toaster',
        'sandwich_maker'     => 'sandwich maker',
        'fryer'              => 'deep fryer',
        'rice_cooker'        => 'rice cooker',
        'tea_maker'          => 'samovar tea',
        'coffee_maker'       => 'coffee machine',
        'iron'               => 'steam iron',
        'hair_dryer'         => 'hair dryer',
        'hair_clipper'       => 'hair clipper',
        'fan'                => 'electric fan',
        'heater'             => 'room heater',
    ];

    /** 🧭 نگاشت کلیدواژه‌های عنوان فارسی → نوع مقاله (برای بازتولید تصاویر) — v3.0 */
    const TOPIC_INFER = [
        'error_codes'        => '/کد خطا|کد ارور|ارور|خطای|error code|e\\d{2}/iu',
        'troubleshooting'    => '/عیب‌یابی|عیب یابی|رفع مشکل|نشان نمی‌دهد|روشن نمی‌شود|عایب/iu',
        'maintenance'        => '/سرویس دوره|نگهداری|تمیز کردن|اسید|رسوب/iu',
        'installation_guide' => '/نصب|راه‌اندازی|راه اندازی/iu',
        'buying_guide'       => '/خرید|راهنمای انتخاب|کدام مدل/iu',
        'energy_saving'      => '/مصرف برق|انرژی|برق کم/iu',
        'cost_guide'         => '/قیمت|هزینه|تعرفه/iu',
        'warranty_guide'     => '/گارانتی|ضمانت/iu',
        'user_guide'         => '/آموزش|طریقه|نحوه|استفاده/iu',
        'comparison'         => '/مقایسه|بهتر است|یا/iu',
    ];

    /* ==================================================
     * 📊 پیشرفت زنده (v2.15)
     * ================================================== */

    /** 📊 نصب گیرنده گزارش پیشرفت — الگوی یکسان با ErrorCodeEngine */
    public function setProgressSink(?callable $fn): void
    {
        $this->progressSink = $fn;
    }

    /** 📊 ارسال گزارش پیشرفت (بی‌اثر اگر گیرنده‌ای نصب نباشد) */
    private function progress(int $pct, string $title, string $detail = ''): void
    {
        if ($this->progressSink !== null) {
            try {
                ($this->progressSink)($pct, $title, $detail);
            } catch (Throwable $e) {
                // گیرنده هرگز جریان اصلی را نمی‌کند
            }
        }
    }

    /** 📋 اعلان‌های این اجرا (v3.0 — شفافیت «سرویس انتخابی استفاده نشد») */
    public function getNotices(): array
    {
        return array_values(array_unique($this->notices));
    }

    /* ==================================================
     * ⚙️ تنظیمات سرویس («سرویس انتخابی همیشه اول»)
     * ================================================== */

    /** ⚙️ خواندن تنظیمات تولید تصویر مقاله (از جدول settings) */
    public static function settings(): array
    {
        $saved = Config::get('article_photo_settings');
        $saved = is_array($saved) ? $saved : [];
        $cfg = array_merge([
            'service' => 'pollinations_flux', // سرویس انتخابی — همیشه اول تلاش می‌شود
            'keys'    => [],                  // کلید سرویس‌های کلیددار: ['huggingface' => 'hf_...', ...]
            'timeout' => 45,                  // مهلت هر تولید (ثانیه)
        ], $saved);
        /* سرویس نامعتبر → پیش‌فرض */
        if (!isset(self::SERVICES[$cfg['service']])) {
            $cfg['service'] = 'pollinations_flux';
        }
        $cfg['keys'] = is_array($cfg['keys']) ? $cfg['keys'] : [];
        return $cfg;
    }

    /** 📋 فهرست سرویس‌ها برای رابط کاربری تنظیمات */
    public static function servicesList(): array
    {
        $cfg = self::settings();
        $list = [];
        foreach (self::SERVICES as $key => [$label, $needsKey, $hint]) {
            $list[$key] = [
                'label'     => $label,
                'needs_key' => $needsKey,
                'hint'      => $hint,
                'has_key'   => $needsKey ? trim((string)($cfg['keys'][$key] ?? '')) !== '' : true,
            ];
        }
        return $list;
    }

    /** 🔑 کلید یک سرویس از تنظیمات (خالی اگر ندارد) */
    private function serviceKey(string $service): string
    {
        $cfg = self::settings();
        return trim((string)($cfg['keys'][$service] ?? ''));
    }

    /**
     * 🔗 زنجیره تلاش سرویس‌ها — «سرویس انتخابی» همیشه اول؛ سپس بقیه به‌ترتیب:
     * بدون‌کلیدها (به ترتیب رجیستری) و کلیددارهایی که کلیدشان ثبت شده.
     */
    private function serviceChain(): array
    {
        $cfg = self::settings();
        $chain = [$cfg['service']];

        /* 🆕 v3.0: همه سرویس‌های بدون کلید از خود رجیستری (بدون هاردکد) */
        foreach (array_keys(self::SERVICES) as $svc) {
            if (!self::SERVICES[$svc][1] && !in_array($svc, $chain, true)) {
                $chain[] = $svc;
            }
        }
        /* کلیددارهای دارای کلید */
        foreach (array_keys(self::SERVICES) as $svc) {
            if (!in_array($svc, $chain, true) && self::SERVICES[$svc][1] && $this->serviceKey($svc) !== '') {
                $chain[] = $svc;
            }
        }
        return $chain;
    }

    /** 🧭 استنتاج نوع مقاله از عنوان (برای بازتولید تصاویر مقالات موجود) — v3.0 */
    public static function inferTopicType(string $title): string
    {
        foreach (self::TOPIC_INFER as $type => $re) {
            if (preg_match($re, $title)) {
                return $type;
            }
        }
        return 'troubleshooting';
    }

    /* ==================================================
     * 📸 API اصلی
     * ================================================== */

    /**
     * 📸 تولید عکس‌های واقعی مرتبط با مقاله + واترمارک
     *
     * @param int    $articleId شناسه مقاله (برای نام فایل)
     * @param string $title     عنوان مقاله (برای استنتاج دستگاه)
     * @param string $deviceKey کلید دستگاه (خالی = از عنوان استنتاج)
     * @param string $topicType نوع مقاله
     * @param array  $brand     رکورد برند (لوگو برای واترمارک)
     * @param int    $count     تعداد عکس (۱ تا ۳)
     * @return array ['path','url','alt','caption','service'] × N — خالی در صورت شکست کامل
     */
    public function generateForArticle(int $articleId, string $title, string $deviceKey, string $topicType, array $brand, int $count = self::MAX_PER_ARTICLE): array
    {
        $count = max(1, min(self::MAX_PER_ARTICLE, $count));
        $cfg = self::settings();
        $chain = $this->serviceChain();

        $this->progress(2, 'آماده‌سازی پرامپت تصویر', 'سرویس انتخابی: ' . self::SERVICES[$cfg['service']][0]);

        /* 🆕 v3.0: اعلان شفاف اگر سرویس انتخابی کلیددارِ بدون کلید است */
        if (self::SERVICES[$cfg['service']][1] && trim((string)($cfg['keys'][$cfg['service']] ?? '')) === '') {
            $this->notices[] = 'سرویس انتخابی («' . self::SERVICES[$cfg['service']][0] . '») کلید API ندارد — در تنظیمات ← تولید تصویر ثبت کنید؛ این اجرا از جایگزین‌های رایگان استفاده می‌کند.';
        }

        /* 🎯 دستگاه معتبر — مستقیم یا استنتاج از عنوان/محتوا */
        $norm = ArticleImageService::normalizeDeviceKey($deviceKey);
        if ($norm === null) {
            $norm = ArticleImageService::normalizeDeviceKey((string)ArticleImageService::inferDeviceKey($title, ''));
        }
        $devicePrompt = self::DEVICE_PROMPTS[$norm] ?? 'household appliance';
        /* 🆕 v3.1: کلیدواژه استوک همین دستگاه — برای سرویسهای عکس واقعی
         * (لورم‌فلیکر/ویکیمدیا/لکسیکا) که با پرامپت توصیفی جستجو نمی‌کنند */
        $stockQuery = self::STOCK_KEYWORDS[$norm] ?? 'home appliance';

        /* 🎭 صحنه بر اساس نوع مقاله */
        $scene = self::TOPIC_SCENES[$topicType] ?? self::TOPIC_SCENES['troubleshooting'];
        $sceneMain = str_replace('{DEVICE}', $devicePrompt, $scene[0]);
        $sceneDetail = $scene[1];

        $dir = 'uploads/articles/ai';
        $absDir = ROOT_PATH . '/' . $dir;
        if (!is_dir($absDir) && !@mkdir($absDir, 0755, true)) {
            return [];
        }

        $safeId = preg_replace('/[^a-z0-9\-]/i', '', (string)$articleId) ?: '0';
        $timeSlice = substr((string)time(), -6);
        $devices = [];
        try {
            $devices = TextProcessor::loadKnowledge('devices') ?: [];
        } catch (Throwable $e) {
        }
        $deviceFa = $devices[$norm]['name_fa'] ?? 'لوازم خانگی';

        $images = [];
        for ($i = 1; $i <= $count; $i++) {
            /* 🌱 بذر یکتا — هر تولید تصویر جدید می‌سازد */
            $seed = crc32($articleId . '|' . $title . '|' . $timeSlice . '|' . $i . '|' . random_int(1, 999999));

            /* 🎨 ترکیب‌بندی متفاوت برای هر عکس: شاخص = نمای باز؛ بعدی‌ها = نمای نزدیک/زاویه دیگر */
            $angle = $i === 1
                ? 'wide establishing shot, eye-level view, full subject visible'
                : ($i === 2
                    ? 'close-up detail shot, shallow depth of field'
                    : 'three-quarter angle view, different perspective, workspace details');

            $prompt = $sceneMain . ', ' . $angle . ', ' . $sceneDetail . ', ' . self::STYLE_SUFFIX;

            $rel = $dir . '/art-' . $safeId . '-' . $i . '-' . $timeSlice . '.jpg';
            $abs = ROOT_PATH . '/' . $rel;

            /* 🚀 تلاش زنجیره‌ای سرویس‌ها — سرویس انتخابی تنظیمات همیشه اول */
            $ok = false;
            $imgService = ''; /* 🆕 v3.0: سرویس «همین تصویر» — نه تصویر اول */
            $svcIdx = 0;
            foreach ($chain as $svc) {
                $svcIdx++;
                $this->progress(
                    (int)round(6 + 82 * (($i - 1 + $svcIdx / max(2, count($chain))) / $count)),
                    'تولید تصویر ' . $i . ' از ' . $count,
                    'سرویس: ' . self::SERVICES[$svc][0] . ($svcIdx > 1 ? ' (تلاش جایگزین ' . $svcIdx . ')' : ' (سرویس انتخابی)')
                );
                $ok = $this->downloadViaService($svc, $prompt, $seed, $abs, $stockQuery);
                if ($ok) {
                    $imgService = $svc;
                    break; // ✅ موفق — سرویس بعدی لازم نیست
                }
                /* پرامپت ساده برای تلاش بعدی (سرویس‌ها گاهی با پرامپت طولانی مشکل دارند) */
                $prompt = $devicePrompt . ' ' . $angle . ', professional product photography, photorealistic, high detail, no text, no watermark';
            }
            if (!$ok) {
                continue; // این عکس در هیچ سرویسی نشد — بقیه ادامه
            }

            /* 📐 v3.0: هم‌سازی ابعاد به ۱۶:۹ — سرویس‌های مربع‌خروج (SDXL/DeepAI و…) */
            $this->normalizeAspect169($abs);

            /* 🪧 واترمارک دوتایی بزرگ و شفاف روی عکس واقعی */
            $this->progress((int)round(6 + 82 * ($i / $count)) - 1, 'مهر واترمارک‌ها', 'تصویر ' . $i . ' از ' . $count . ' — لوگوی برند + نمایندگی');
            $this->stampWatermarks($abs, $brand);

            $images[] = [
                'path'    => $rel,
                'url'     => rtrim(BASE_URL, '/') . '/' . $rel,
                'alt'     => mb_substr($title . ' — ' . $deviceFa . '، تعمیر تخصصی لوازم خانگی', 0, 160),
                'caption' => mb_substr($deviceFa . ' — ' . ($i === 1 ? 'نمای اصلی مرتبط با موضوع مقاله' : ($i === 2 ? 'نمای نزدیک و جزئیات فنی' : 'نمای کارشناسی و صحنه خدمات')), 0, 160),
                'role'    => $i === 1 ? 'featured' : 'inline',
                'source'  => 'ai_photo',
                'service' => $imgService, /* 🆕 v3.0: دقیق به‌ازای هر تصویر */
            ];
        }
        if ($images) {
            $usedLabels = array_unique(array_map(fn($im) => self::SERVICES[$im['service']][0] ?? $im['service'], $images));
            $this->progress(90, 'تصاویر آماده شد', count($images) . ' تصویر واقعی با «' . implode(' + ', $usedLabels) . '» تولید شد');
        }
        return $images;
    }

    /* ==================================================
     * 🌐 درایورهای سرویس‌ها (v3.1 — ۱۸ سرویس؛ ۷ رایگان بدون کلید)
     * ================================================== */

    /**
     * ⬇️ دانلود عکس از سرویس مشخص + اعتبارسنجی
     * @param string      $service    کلید سرویس
     * @param string      $prompt     پرامپت توصیفی (سرویسهای AI)
     * @param int         $seed       بذر یکتا (تنوع + انتخاب از نتایج استوک)
     * @param string      $destAbs    مسیر مطلق فایل مقصد
     * @param string|null $stockQuery کلیدواژه موضوعی دستگاه (سرویسهای عکس واقعی — v3.1)
     * @return bool موفقیت ذخیره فایل
     */
    private function downloadViaService(string $service, string $prompt, int $seed, string $destAbs, ?string $stockQuery = null): bool
    {
        $timeout = max(20, (int)self::settings()['timeout']);
        $key = $this->serviceKey($service);

        switch ($service) {
            /* ---------- 🆓 رایگان — بدون کلید ---------- */
            case 'pollinations_flux':
            case 'pollinations_turbo':
                $model = $service === 'pollinations_turbo' ? 'turbo' : 'flux';
                $url = 'https://image.pollinations.ai/prompt/' . rawurlencode($prompt)
                    . '?width=' . self::W . '&height=' . self::H
                    . '&nologo=true&enhance=false&model=' . $model . '&seed=' . ($seed % 2147483647);
                return $this->httpDownload($url, $destAbs, $timeout, []);

            case 'stablehorde':
                /* AI Horde — رایگان-اشتراکی بدون کلید: ثبت async + poll + دریافت */
                return $this->stablehordeGenerate($prompt, $destAbs, $timeout);

            /* ---------- 🆕 v3.1 — عکسهای واقعی موضوعی بدون کلید ---------- */
            case 'loremflickr':
                /* لورم‌فلیکر — عکس استوک واقعی با کلیدواژه؛ lock بذر = تنوع */
                $q = rawurlencode($stockQuery ?: 'home appliance');
                $url = 'https://loremflickr.com/' . self::W . '/' . self::H . '/' . $q . '?lock=' . ($seed % 999983);
                return $this->httpDownload($url, $destAbs, $timeout, [], 8000);

            case 'wikimedia':
                /* ویکیمدیا کامانز — جستجوی عکس واقعی با کلیدواژه (API عمومی، بدون کلید) */
                $q = $stockQuery ?: 'home appliance';
                $api = 'https://commons.wikimedia.org/w/api.php?action=query&format=json&generator=search'
                    . '&gsrsearch=' . rawurlencode($q) . '&gsrnamespace=6&gsrlimit=24'
                    . '&prop=imageinfo&iiprop=url%7Csize%7Cmime&iiurlwidth=' . self::W;
                $body = $this->httpGetBody($api, ['Accept: application/json'], $timeout);
                if ($body === null) { return false; }
                $data = json_decode($body, true);
                $pages = is_array($data) ? ($data['query']['pages'] ?? null) : null;
                if (!is_array($pages) || $pages === []) { return false; }
                /* فقط تصاویر شطرنجی JPEG/PNG با عرض کافی */
                $candidates = [];
                foreach ($pages as $p) {
                    $info = is_array($p['imageinfo'][0] ?? null) ? $p['imageinfo'][0] : null;
                    if ($info === null) { continue; }
                    if (isset($info['mime']) && !in_array($info['mime'], ['image/jpeg', 'image/png'], true)) { continue; }
                    if ((int)($info['width'] ?? 0) < 640) { continue; }
                    $url = trim((string)($info['thumburl'] ?? ''));
                    if ($url === '') { $url = trim((string)($info['url'] ?? '')); }
                    if ($url !== '') { $candidates[] = $url; }
                }
                if ($candidates === []) { return false; }
                $pick = $candidates[$seed % count($candidates)];
                return $this->httpDownload($pick, $destAbs, $timeout, [], 8000);

            case 'lexica':
                /* لکسیکا — آرشیو تصاویر AI (Stable Diffusion) با جستجوی کلیدواژه، بدون کلید */
                $q = $stockQuery ?: 'home appliance';
                $api = 'https://lexica.art/api/v1/search?q=' . rawurlencode($q) . '&limit=24';
                $body = $this->httpGetBody($api, ['Accept: application/json'], $timeout);
                if ($body === null) { return false; }
                $data = json_decode($body, true);
                $images = is_array($data) ? ($data['images'] ?? null) : null;
                if (!is_array($images) || $images === []) { return false; }
                $candidates = [];
                foreach ($images as $im) {
                    $src = trim((string)($im['src'] ?? ''));
                    if ($src !== '' && preg_match('#^https?://#i', $src)) { $candidates[] = $src; }
                }
                if ($candidates === []) { return false; }
                $pick = $candidates[$seed % count($candidates)];
                return $this->httpDownload($pick, $destAbs, $timeout, [], 8000);

            case 'picsum':
                /* Picsum — عکس واقعی تصادفی (بدون ارتباط موضوعی — آخرین جایگزین اضطراری) */
                $url = 'https://picsum.photos/seed/' . ($seed % 999983) . '/' . self::W . '/' . self::H . '.jpg';
                return $this->httpDownload($url, $destAbs, $timeout, [], 6000);

            /* ---------- 🔑 کلید رایگان ---------- */
            case 'huggingface':
                if ($key === '') { return false; }
                /* 🆕 v3.0: endpoint جدید router.huggingface.co (api-inference بازنشسته شده) */
                $url = 'https://router.huggingface.co/hf-inference/models/stabilityai/stable-diffusion-xl-base-1.0';
                $body = $this->httpPost($url, json_encode(['inputs' => $prompt]), $timeout, [
                    'Authorization: Bearer ' . $key,
                    'Content-Type: application/json',
                    'Accept: image/png',
                ]);
                if ($body !== null && str_starts_with($body, "\x89PNG")) {
                    return $this->saveValidatedBinary($body, $destAbs);
                }
                /* fallback به endpoint قدیمی (سازگاری با توکن‌های قدیمی/سرورهای میانی) */
                $body2 = $this->httpPost('https://api-inference.huggingface.co/models/stabilityai/stable-diffusion-xl-base-1.0',
                    json_encode(['inputs' => $prompt]), $timeout, [
                        'Authorization: Bearer ' . $key,
                        'Content-Type: application/json',
                        'Accept: image/png',
                    ]);
                return $body2 !== null && $this->saveValidatedBinary($body2, $destAbs);

            case 'deepai':
                if ($key === '') { return false; }
                /* DeepAI خروجی JSON با آدرس عکس می‌دهد → دو مرحله */
                $json = $this->httpPost('https://api.deepai.org/api/text2img', http_build_query(['text' => $prompt]), $timeout, [
                    'api-key: ' . $key,
                    'Content-Type: application/x-www-form-urlencoded',
                ]);
                if ($json === null) { return false; }
                $data = json_decode($json, true);
                $imgUrl = is_array($data) ? trim((string)($data['output_url'] ?? '')) : '';
                if ($imgUrl === '' || !preg_match('#^https?://#i', $imgUrl)) { return false; }
                return $this->httpDownload($imgUrl, $destAbs, $timeout, []);

            case 'together':
                if ($key === '') { return false; }
                $json = $this->httpPost('https://api.together.xyz/v1/images/generations', json_encode([
                    'model'  => 'black-forest-labs/FLUX.1-schnell',
                    'prompt' => $prompt,
                    'width'  => self::W,
                    'height' => self::H,
                    'steps'  => 4,
                    'n'      => 1,
                ]), $timeout, [
                    'Authorization: Bearer ' . $key,
                    'Content-Type: application/json',
                ]);
                if ($json === null) { return false; }
                $data = json_decode($json, true);
                $b64 = is_array($data) ? (string)($data['data'][0]['b64_json'] ?? '') : '';
                if ($b64 === '') { return false; }
                $bin = base64_decode($b64, true);
                return $bin !== false && $this->saveValidatedBinary($bin, $destAbs);

            case 'fal':
                if ($key === '') { return false; }
                /* fal.ai — FLUX.1-schnell همگام (landscape_16_9) */
                $json = $this->httpPost('https://fal.run/fal-ai/flux/schnell', json_encode([
                    'prompt'     => $prompt,
                    'image_size' => 'landscape_16_9',
                    'num_images' => 1,
                    'enable_safety_checker' => true,
                ]), $timeout, [
                    'Authorization: Key ' . $key,
                    'Content-Type: application/json',
                ]);
                if ($json === null) { return false; }
                $data = json_decode($json, true);
                $imgUrl = is_array($data) ? trim((string)($data['images'][0]['url'] ?? '')) : '';
                if ($imgUrl === '' || !preg_match('#^https?://#i', $imgUrl)) { return false; }
                return $this->httpDownload($imgUrl, $destAbs, $timeout, []);

            /* ---------- 🔑 کلید اشتراکی ---------- */
            case 'stability':
                if ($key === '') { return false; }
                $body = $this->httpPostMultipart('https://api.stability.ai/v2beta/stable-image/generate/core', [
                    'prompt'        => $prompt,
                    'output_format' => 'jpeg',
                    'aspect_ratio'  => '16:9',
                ], $timeout, [
                    'Authorization: Bearer ' . $key,
                    'Accept: image/jpeg',
                ]);
                return $body !== null && $this->saveValidatedBinary($body, $destAbs);

            case 'openai':
                if ($key === '') { return false; }
                $json = $this->httpPost('https://api.openai.com/v1/images/generations', json_encode([
                    'model'           => 'dall-e-3',
                    'prompt'          => $prompt,
                    'size'            => '1792x1024',
                    'quality'         => 'standard',
                    'response_format' => 'b64_json',
                    'n'               => 1,
                ]), $timeout, [
                    'Authorization: Bearer ' . $key,
                    'Content-Type: application/json',
                ]);
                if ($json === null) { return false; }
                $data = json_decode($json, true);
                $b64 = is_array($data) ? (string)($data['data'][0]['b64_json'] ?? '') : '';
                if ($b64 === '') { return false; }
                $bin = base64_decode($b64, true);
                return $bin !== false && $this->saveValidatedBinary($bin, $destAbs);

            case 'openai_gptimage':
                if ($key === '') { return false; }
                /* gpt-image-1 — همیشه b64 برمی‌گرداند؛ سایز افقی 1536x1024 (سازگار نزدیک ۱۶:۹) */
                $json = $this->httpPost('https://api.openai.com/v1/images/generations', json_encode([
                    'model'  => 'gpt-image-1',
                    'prompt' => $prompt,
                    'size'   => '1536x1024',
                    'n'      => 1,
                ]), max($timeout, 90), [
                    'Authorization: Bearer ' . $key,
                    'Content-Type: application/json',
                ]);
                if ($json === null) { return false; }
                $data = json_decode($json, true);
                $b64 = is_array($data) ? (string)($data['data'][0]['b64_json'] ?? '') : '';
                if ($b64 === '') { return false; }
                $bin = base64_decode($b64, true);
                return $bin !== false && $this->saveValidatedBinary($bin, $destAbs);

            case 'gemini':
                if ($key === '') { return false; }
                /* Google Imagen 3 — predict endpoint با پارامتر key */
                $json = $this->httpPost(
                    'https://generativelanguage.googleapis.com/v1beta/models/imagen-3.0-generate-002:predict?key=' . rawurlencode($key),
                    json_encode([
                        'instances'  => [['prompt' => $prompt]],
                        'parameters' => ['sampleCount' => 1, 'aspectRatio' => '16:9'],
                    ]), max($timeout, 60), [
                        'Content-Type: application/json',
                    ]);
                if ($json === null) { return false; }
                $data = json_decode($json, true);
                $b64 = is_array($data) ? (string)($data['predictions'][0]['bytesBase64Encoded'] ?? '') : '';
                if ($b64 === '') { return false; }
                $bin = base64_decode($b64, true);
                return $bin !== false && $this->saveValidatedBinary($bin, $destAbs);

            case 'ideogram':
                if ($key === '') { return false; }
                /* Ideogram v2 Turbo — پاسخ JSON با آدرس تصویر (اعتبار کم‌مصرف) */
                $json = $this->httpPost('https://api.ideogram.ai/v1/generate', json_encode([
                    'prompt'         => $prompt,
                    'aspect_ratio'   => 'ASPECT_16_9',
                    'model'          => 'v_2_turbo',
                    'magic_prompt'   => 'OFF',
                ]), max($timeout, 60), [
                    'Authorization: Bearer ' . $key,
                    'Content-Type: application/json',
                ]);
                if ($json === null) { return false; }
                $data = json_decode($json, true);
                $imgUrl = is_array($data) ? trim((string)($data['data'][0]['url'] ?? '')) : '';
                if ($imgUrl === '' || !preg_match('#^https?://#i', $imgUrl)) { return false; }
                return $this->httpDownload($imgUrl, $destAbs, $timeout, []);

            case 'getimg':
                if ($key === '') { return false; }
                /* GetImg — SDXL: پاسخ JSON با url امضاشده (کوتاه‌مدت) → دانلود */
                $json = $this->httpPost('https://api.getimg.ai/v1/stable-diffusion-xl/generate', json_encode([
                    'prompt' => $prompt,
                    'width'  => 1280,
                    'height' => 720,
                    'steps'  => 30,
                ]), max($timeout, 60), [
                    'Authorization: Bearer ' . $key,
                    'Content-Type: application/json',
                ]);
                if ($json === null) { return false; }
                $data = json_decode($json, true);
                $imgUrl = is_array($data) ? trim((string)($data['url'] ?? ($data['image_url'] ?? ''))) : '';
                if ($imgUrl === '' || !preg_match('#^https?://#i', $imgUrl)) { return false; }
                return $this->httpDownload($imgUrl, $destAbs, $timeout, []);

            case 'replicate':
                if ($key === '') { return false; }
                /* Replicate — FLUX schnell با هدر Prefer: wait (تقریباً همگام) + poll پشتیبان */
                $create = $this->httpPostRaw('https://api.replicate.com/v1/models/black-forest-labs/flux-schnell/predictions', json_encode([
                    'prompt' => $prompt,
                    'aspect_ratio' => '16:9',
                    'output_format' => 'jpg',
                ]), max($timeout, 60), [
                    'Authorization: Token ' . $key,
                    'Content-Type: application/json',
                    'Prefer: wait',
                ]);
                if ($create === null) { return false; }
                $data = json_decode($create, true);
                /* مسیر ۱: همگام موفق */
                if (is_array($data) && ($data['status'] ?? '') === 'succeeded') {
                    $out = $data['output'] ?? [];
                    $imgUrl = is_array($out) ? trim((string)reset($out)) : (string)$out;
                    if ($imgUrl !== '' && preg_match('#^https?://#i', $imgUrl)) {
                        return $this->httpDownload($imgUrl, $destAbs, $timeout, []);
                    }
                }
                /* مسیر ۲: poll تا سقف زمان */
                $getUrl = is_array($data) ? trim((string)($data['urls']['get'] ?? '')) : '';
                $predId = is_array($data) ? trim((string)($data['id'] ?? '')) : '';
                if ($getUrl === '' && $predId !== '') {
                    $getUrl = 'https://api.replicate.com/v1/predictions/' . rawurlencode($predId);
                }
                if ($getUrl === '') { return false; }
                $deadlineTs = microtime(true) + max($timeout, 60);
                while (microtime(true) < $deadlineTs) {
                    usleep(2500000); /* ۲.۵ ثانیه */
                    $poll = $this->httpGetBody($getUrl, ['Authorization: Token ' . $key], 20);
                    if ($poll === null) { continue; }
                    $pd = json_decode($poll, true);
                    $status = (string)($pd['status'] ?? '');
                    if ($status === 'failed' || $status === 'canceled') { return false; }
                    if ($status === 'succeeded') {
                        $out = $pd['output'] ?? [];
                        $imgUrl = is_array($out) ? trim((string)reset($out)) : (string)$out;
                        if ($imgUrl !== '' && preg_match('#^https?://#i', $imgUrl)) {
                            return $this->httpDownload($imgUrl, $destAbs, $timeout, []);
                        }
                        return false;
                    }
                }
                return false;
        }
        return false;
    }

    /**
     * 🐎 AI Horde — تولید رایگان-اشتراکی بدون کلید (ثبت async + poll + دریافت)
     * v3.0 — «همه سرویس‌های رایگان داخل تنظیمات»
     */
    private function stablehordeGenerate(string $prompt, string $destAbs, int $timeout): bool
    {
        $create = $this->httpPost('https://stablehorde.net/api/v2/generate/async', json_encode([
            'prompt' => $prompt,
            'params' => [
                'sampler_name' => 'k_euler_a',
                'cfg_scale'    => 7,
                'width'        => 1024,
                'height'       => 576,
                'steps'        => 25,
                'n'            => 1,
            ],
            'nsfw'         => false,
            'censor_nsfw'  => true,
            'models'       => ['stable_diffusion'],
        ]), 25, [
            'API-Key: 0000000000', /* 🔑 ناشناس — رایگان بدون ثبت‌نام */
            'Content-Type: application/json',
            'Accept: application/json',
            'Client-Agent: SahandBrandMaker:3.0:opensource',
        ]);
        if ($create === null) { return false; }
        $data = json_decode($create, true);
        $jobId = is_array($data) ? trim((string)($data['id'] ?? '')) : '';
        if ($jobId === '') { return false; }

        $deadlineTs = microtime(true) + max($timeout, 120); /* صف عمومی کند است */
        while (microtime(true) < $deadlineTs) {
            usleep(4000000); /* ۴ ثانیه */
            $chk = $this->httpGetBody('https://stablehorde.net/api/v2/generate/check/' . rawurlencode($jobId), [
                'Accept: application/json',
                'Client-Agent: SahandBrandMaker:3.0:opensource',
            ], 15);
            if ($chk === null) { continue; }
            $cd = json_decode($chk, true);
            if (!is_array($cd)) { continue; }
            if (!empty($cd['faulted'])) { return false; }
            if (empty($cd['done'])) { continue; }

            /* آماده — دریافت نتیجه */
            $st = $this->httpGetBody('https://stablehorde.net/api/v2/generate/status/' . rawurlencode($jobId), [
                'Accept: application/json',
                'Client-Agent: SahandBrandMaker:3.0:opensource',
            ], 20);
            if ($st === null) { return false; }
            $sd = json_decode($st, true);
            $img = is_array($sd) ? trim((string)($sd['generations'][0]['img'] ?? '')) : '';
            if ($img === '') { return false; }
            if (preg_match('#^https?://#i', $img)) {
                return $this->httpDownload($img, $destAbs, $timeout, []);
            }
            $bin = base64_decode($img, true);
            return $bin !== false && $this->saveValidatedBinary($bin, $destAbs);
        }
        return false;
    }

    /* ==================================================
     * 🌐 زیرساخت HTTP
     * ================================================== */

    /** ⬇️ دانلود مستقیم فایل از URL با اعتبارسنجی تصویر */
    private function httpDownload(string $url, string $destAbs, int $timeout, array $headers, int $minBytes = 15000): bool
    {
        $ch = curl_init($url);
        $fh = @fopen($destAbs, 'wb');
        if (!$fh) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SahandBrandMaker/3.0)',
            CURLOPT_HTTPHEADER     => array_merge(['Accept: image/jpeg,image/png,image/*'], $headers),
        ]);
        curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        fclose($fh);

        /* 🛡 اعتبارسنجی: JPEG/PNG واقعی و معقول (نه صفحه خطای HTML) */
        $valid = $httpCode === 200
            && is_file($destAbs)
            && filesize($destAbs) > $minBytes
            && (str_contains($type, 'image/') || $this->looksLikeImage($destAbs));
        if (!$valid) {
            @unlink($destAbs);
            return false;
        }
        return true;
    }

    /** 📤 POST با بدنه خام — بدنه پاسخ یا null */
    private function httpPost(string $url, string $body, int $timeout, array $headers): ?string
    {
        return $this->httpPostRaw($url, $body, $timeout, $headers);
    }

    /** 📤 POST خام — بدون پیش‌فرض‌های اضافه (برای APIهایی که کد وضعیت غیر-۲xx را برمی‌گردانند) */
    private function httpPostRaw(string $url, string $body, int $timeout, array $headers): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SahandBrandMaker/3.0)',
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $res = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return (is_string($res) && $httpCode >= 200 && $httpCode < 300) ? $res : null;
    }

    /** 📥 GET بدنه پاسخ یا null — v3.0 (تست سلامت endpoints + poll) */
    private function httpGetBody(string $url, array $headers, int $timeout): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SahandBrandMaker/3.0)',
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        ]);
        $res = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return (is_string($res) && $httpCode >= 200 && $httpCode < 300) ? $res : null;
    }

    /** 📤 POST چندبخشی (multipart) — بدنه پاسخ یا null */
    private function httpPostMultipart(string $url, array $fields, int $timeout, array $headers): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $fields, // آرایه → multipart خودکار
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $res = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        /* Stability گاهی خطای JSON با کد 200 می‌دهد — فقط پاسخ تصویری قبول */
        if (is_string($res) && $httpCode >= 200 && $httpCode < 300 && str_contains($type, 'image/')) {
            return $res;
        }
        return null;
    }

    /** 💾 ذخیره اعتبارسنجی‌شده باینری تصویر */
    private function saveValidatedBinary(string $bin, string $destAbs): bool
    {
        if (strlen($bin) < 15000) {
            return false;
        }
        if (!(str_starts_with($bin, "\xFF\xD8\xFF") || str_starts_with($bin, "\x89PNG\r\n\x1A\n") || str_starts_with($bin, "RIFF"))) {
            return false; // JSON خطا یا HTML — نه تصویر (RIFF = webp هورده)
        }
        return @file_put_contents($destAbs, $bin) !== false;
    }

    /** 🔎 بررسی امضای باینری تصویر (JPEG/PNG) */
    private function looksLikeImage(string $path): bool
    {
        $head = (string)@file_get_contents($path, false, null, 0, 12);
        return str_starts_with($head, "\xFF\xD8\xFF")      // JPEG
            || str_starts_with($head, "\x89PNG\r\n\x1A\n"); // PNG
    }

    /**
     * 📐 هم‌سازی ابعاد به ۱۶:۹ — v3.0
     * سرویس‌هایی مثل SDXL (۱۰۲۴×۱۰۲۴) و DeepAI (۵۱۲×۵۱۲) مربع برمی‌گردانند؛
     * همه خروجی‌ها با GD به بوم ۱۲۰۰×۶۷۵ (کروپ مرکزی) تبدیل می‌شوند تا قالب
     * تصاویر مقاله یکنواخت بماند. در نبود GD بی‌اثر و بی‌خطا است.
     */
    private function normalizeAspect169(string $absPath): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            return;
        }
        $data = @file_get_contents($absPath);
        $img = $data ? @imagecreatefromstring($data) : false;
        if (!$img) {
            return;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $targetRatio = self::W / self::H;
        $ratio = $w / max(1, $h);
        /* فقط وقتی انحراف معنادار است (بیش از ~۷٪) */
        if (abs($ratio - $targetRatio) / $targetRatio > 0.07) {
            if ($ratio > $targetRatio) {
                $cropH = $h;
                $cropW = (int)round($h * $targetRatio);
            } else {
                $cropW = $w;
                $cropH = (int)round($w / $targetRatio);
            }
            $sx = max(0, (int)floor(($w - $cropW) / 2));
            $sy = max(0, (int)floor(($h - $cropH) / 2));
            $canvas = imagecreatetruecolor(self::W, self::H);
            imagecopyresampled($canvas, $img, 0, 0, $sx, $sy, self::W, self::H, $cropW, $cropH);
            imagedestroy($img);
            $img = $canvas;
        }
        imagejpeg($img, $absPath, 92);
        imagedestroy($img);
    }

    /**
     * 🪧 مهر واترمارک دوتایی بزرگ روی فایل (درجا)
     * لوگوی برند پایین-راست + لوگوی نمایندگی پایین-چپ — مثل stampedCopy ولی درجا
     */
    private function stampWatermarks(string $absPath, array $brand): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            return;
        }
        $data = @file_get_contents($absPath);
        $img = $data ? @imagecreatefromstring($data) : false;
        if (!$img) {
            return;
        }
        $brandLogo = ImageWatermark::brandLogoFile($brand);
        $agencyLogo = ImageWatermark::agencyLogoFile();
        if ($brandLogo !== null || $agencyLogo !== null) {
            $shortSide = min(imagesx($img), imagesy($img));
            /* 🪧 v2.14: واترمارک‌های بزرگ — لوگوی نمایندگی هم‌اندازه و کمی بزرگ‌تر از برند
               (طبق درخواست: واترمارک لوگوی نمایندگی روی تصاویر مقاله بزرگ‌تر شود) */
            $max = max(150, (int)round($shortSide * 0.31));
            ImageWatermark::stampCorners($img, $brandLogo, $agencyLogo, [
                'brand_max'  => $max,
                'agency_max' => (int)round($max * 1.10),
                'opacity'    => 0.93,
                'margin'     => max(14, (int)round($shortSide * 0.022)),
            ]);
        }
        imagejpeg($img, $absPath, 90);
        imagedestroy($img);
    }

    /**
     * 🩺 بررسی سلامت/دسترسی سرویس (برای پنل سلامت + دکمه تست تنظیمات)
     * @param string|null $service سرویس مشخص، یا null = سرویس انتخابی تنظیمات
     * @param string|null $keyOverride کلید تستی فرم (بدون ذخیره) — اولویت با آن است
     */
    public function healthCheck(?string $service = null, ?string $keyOverride = null): array
    {
        $cfg = self::settings();
        $service = $service ?? $cfg['service'];
        if (!isset(self::SERVICES[$service])) {
            return ['ok' => false, 'status' => 'unknown', 'latency' => 0, 'service' => $service, 'message' => 'سرویس ناشناخته است.'];
        }
        $t0 = microtime(true);
        $testAbs = null;
        $ok = false;
        $message = '';
        try {
            /* 🆕 v3.0: تصویر آزمایشی ۶۴۰×۳۶۰ (قبلاً ۶۴×۶۴ → حجم < آستانه اعتبارسنجی
               و تست سرویس‌های سالم «ناموفق» گزارش می‌شد!) */
            $tmpDir = sys_get_temp_dir() ?: ROOT_PATH . '/cache';
            $testAbs = $tmpDir . '/aiphoto-test-' . substr(md5((string)mt_rand()), 0, 8) . '.jpg';
            $timeout = 30;
            $key = ($keyOverride !== null && trim($keyOverride) !== '') ? trim($keyOverride) : $this->serviceKey($service);
            switch ($service) {
                case 'pollinations_flux':
                case 'pollinations_turbo':
                    $model = $service === 'pollinations_turbo' ? 'turbo' : 'flux';
                    $url = 'https://image.pollinations.ai/prompt/' . rawurlencode('a red apple on a table')
                        . '?width=640&height=360&nologo=true&model=' . $model . '&seed=1';
                    $ok = $this->httpDownload($url, $testAbs, $timeout, [], 4000);
                    $message = $ok ? 'سرویس پاسخ داد و تصویر ساخت.' : 'سرویس در مهلت ' . $timeout . ' ثانیه تصویر برنگرداند.';
                    break;
                case 'stablehorde':
                    /* تست رایگان — فقط ضربان قلب سرویس (بدون صف تولید) */
                    $beat = $this->httpGetBody('https://stablehorde.net/api/v2/status/heartbeat', [
                        'Accept: application/json',
                        'Client-Agent: SahandBrandMaker:3.0:opensource',
                    ], 15);
                    $ok = $beat !== null;
                    $message = $ok ? 'سرویس زنده است (صف عمومی — تولید ۶۰ تا ۱۲۰ ثانیه).' : 'سرویس پاسخ نداد — بعداً تست کنید.';
                    break;
                case 'huggingface':
                    if ($key === '') {
                        $message = 'کلید Hugging Face در تنظیمات ثبت نشده است.';
                        break;
                    }
                    $body = $this->httpPost('https://router.huggingface.co/hf-inference/models/stabilityai/stable-diffusion-xl-base-1.0',
                        json_encode(['inputs' => 'a red apple']), $timeout,
                        ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: image/png']);
                    $ok = $body !== null && (str_starts_with($body, "\x89PNG") || str_starts_with($body, "\xFF\xD8\xFF"));
                    $message = $ok ? 'سرویس پاسخ داد.' : ($body === null ? 'پاسخی دریافت نشد — کلید یا اتصال را بررسی کنید.' : 'پاسخ سرویس تصویر نبود.');
                    break;
                case 'deepai':
                    if ($key === '') {
                        $message = 'کلید DeepAI در تنظیمات ثبت نشده است.';
                        break;
                    }
                    $json = $this->httpPost('https://api.deepai.org/api/text2img', http_build_query(['text' => 'a red apple']), $timeout,
                        ['api-key: ' . $key, 'Content-Type: application/x-www-form-urlencoded']);
                    $data = $json !== null ? json_decode($json, true) : null;
                    $ok = is_array($data) && !empty($data['output_url']);
                    $message = $ok ? 'سرویس پاسخ داد.' : 'پاسخ معتبر نگرفتیم — کلید را بررسی کنید.';
                    break;
                case 'fal':
                    if ($key === '') {
                        $message = 'کلید fal.ai در تنظیمات ثبت نشده است.';
                        break;
                    }
                    $json = $this->httpPost('https://fal.run/fal-ai/flux/schnell', json_encode([
                        'prompt' => 'a red apple', 'image_size' => 'square_hd', 'num_images' => 1,
                    ]), 40, ['Authorization: Key ' . $key, 'Content-Type: application/json']);
                    $data = $json !== null ? json_decode($json, true) : null;
                    $ok = is_array($data) && !empty($data['images'][0]['url']);
                    $message = $ok ? 'کلید معتبر و سرویس در دسترس است.' : 'کلید یا اتصال سرویس معتبر نیست.';
                    break;
                case 'together':
                case 'openai':
                case 'openai_gptimage':
                    if ($key === '') {
                        $message = 'کلید این سرویس در تنظیمات ثبت نشده است.';
                        break;
                    }
                    /* 🆕 v3.0: اندپوینت models متد GET دارد — قبلاً POST → 405 → تست همیشه ناموفق! */
                    $url = $service === 'together' ? 'https://api.together.xyz/v1/models' : 'https://api.openai.com/v1/models';
                    $json = $this->httpGetBody($url, ['Authorization: Bearer ' . $key], 15);
                    $ok = $json !== null;
                    $message = $ok ? 'کلید معتبر و سرویس در دسترس است.' : 'کلید یا اتصال سرویس معتبر نیست.';
                    break;
                case 'gemini':
                    if ($key === '') {
                        $message = 'کلید Google AI Studio در تنظیمات ثبت نشده است.';
                        break;
                    }
                    $json = $this->httpGetBody('https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode($key), [], 15);
                    $ok = $json !== null;
                    $message = $ok ? 'کلید معتبر و سرویس در دسترس است.' : 'کلید یا اتصال سرویس معتبر نیست.';
                    break;
                case 'stability':
                    if ($key === '') {
                        $message = 'کلید این سرویس در تنظیمات ثبت نشده است.';
                        break;
                    }
                    $body = $this->httpPostMultipart('https://api.stability.ai/v2beta/stable-image/generate/core',
                        ['prompt' => 'a red apple', 'output_format' => 'jpeg'], 30,
                        ['Authorization: Bearer ' . $key, 'Accept: image/jpeg']);
                    $ok = $body !== null && str_starts_with($body, "\xFF\xD8\xFF");
                    $message = $ok ? 'کلید معتبر و سرویس در دسترس است.' : 'کلید یا اتصال سرویس معتبر نیست.';
                    break;
                case 'ideogram':
                    if ($key === '') {
                        $message = 'کلید Ideogram در تنظیمات ثبت نشده است.';
                        break;
                    }
                    $json = $this->httpPost('https://api.ideogram.ai/v1/generate', json_encode([
                        'prompt' => 'a red apple', 'aspect_ratio' => 'ASPECT_16_9', 'model' => 'v_2_turbo', 'magic_prompt' => 'OFF',
                    ]), 40, ['Authorization: Bearer ' . $key, 'Content-Type: application/json']);
                    $data = $json !== null ? json_decode($json, true) : null;
                    $ok = is_array($data) && !empty($data['data'][0]['url']);
                    $message = $ok ? 'کلید معتبر و سرویس در دسترس است.' : 'کلید یا اتصال سرویس معتبر نیست.';
                    break;
                case 'getimg':
                    if ($key === '') {
                        $message = 'کلید GetImg در تنظیمات ثبت نشده است.';
                        break;
                    }
                    $json = $this->httpPost('https://api.getimg.ai/v1/stable-diffusion-xl/generate', json_encode([
                        'prompt' => 'a red apple', 'width' => 512, 'height' => 512, 'steps' => 10,
                    ]), 40, ['Authorization: Bearer ' . $key, 'Content-Type: application/json']);
                    $data = $json !== null ? json_decode($json, true) : null;
                    $ok = is_array($data) && !empty($data['url']);
                    $message = $ok ? 'کلید معتبر و سرویس در دسترس است.' : 'کلید یا اتصال سرویس معتبر نیست.';
                    break;
                case 'replicate':
                    if ($key === '') {
                        $message = 'کلید Replicate در تنظیمات ثبت نشده است.';
                        break;
                    }
                    /* تست حداقلی: یک پیش‌بینی flux-schnell (ارزان ~۰.۰۰۳ دلار) */
                    $create = $this->httpPostRaw('https://api.replicate.com/v1/models/black-forest-labs/flux-schnell/predictions',
                        json_encode(['prompt' => 'a red apple', 'aspect_ratio' => '16:9', 'output_format' => 'jpg']),
                        60, ['Authorization: Token ' . $key, 'Content-Type: application/json', 'Prefer: wait']);
                    $data = $create !== null ? json_decode($create, true) : null;
                    $ok = is_array($data) && ($data['status'] ?? '') === 'succeeded' && !empty($data['output']);
                    $message = $ok ? 'کلید معتبر و سرویس در دسترس است.' : 'کلید یا اتصال سرویس معتبر نیست.';
                    break;
            }
        } catch (Throwable $e) {
            $message = 'خطای تست: ' . $e->getMessage();
        }
        if ($testAbs !== null && is_file($testAbs)) {
            @unlink($testAbs);
        }
        return [
            'ok'      => $ok,
            'status'  => $ok ? 'online' : 'offline',
            'latency' => (int)round((microtime(true) - $t0) * 1000),
            'service' => $service,
            'label'   => self::SERVICES[$service][0],
            'message' => $message,
        ];
    }
}
