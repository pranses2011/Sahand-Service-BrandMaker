<?php
/**
 * 📸 AiPhotoService — تولید عکس واقعی مرتبط با موضوع مقاله (v2.0 — چند-سرویسی)
 * =========================================================================
 * طبق درخواست کاربر: «همه سرویس‌های تولید تصویر رایگان رو داخل تنظیمات قرار
 * بده تا از لیستشون انتخاب بکنیم. و مطمئن شو که سرویس مربوطه استفاده بشه.»
 *
 * 🆕 v2.0 — معماری درایور چند-سرویسی (۷ سرویس رایگان/کلید-رایگان):
 *   🆓 بدون کلید:
 *     • pollinations_flux   — Pollinations مدل Flux (کیفیت بالا)
 *     • pollinations_turbo  — Pollinations مدل Turbo (سریع‌تر)
 *   🔑 با کلید رایگان (از تنظیمات ← تب «تولید تصویر مقاله»):
 *     • huggingface         — Stable Diffusion XL (توکن رایگان)
 *     • deepai              — Text2Img (کلید رایگان)
 *     • together            — FLUX.1-schnell (کلید رایگان)
 *   🔑 با کلید اشتراکی:
 *     • stability           — Stability AI SD3 Core
 *     • openai              — DALL·E 3
 *
 * زنجیره اجرا: «سرویس انتخابی تنظیمات» همیشه اول تلاش می‌شود؛ در صورت شکست
 * سرویس‌های بدون کلید بعدی → در نهایت بسته عکس دستگاه (آخرین پناهگاه).
 * سرویسی که واقعاً عکس را ساخت در نتیجه (`service`) گزارش می‌شود.
 *
 * v1.0:
 *   🎯 پرامپت انگلیسی دقیق از «عنوان + دستگاه + نوع مقاله»
 *   🌱 بذر یکتا (زمان + شناسه مقاله) → هر بار تولید، تصویر جدید
 *   🛡 اعتبارسنجی خروجی (JPEG/PNG واقعی + حداقل حجم)
 *   🪧 واترمارک دوتایی بزرگ روی تصویر نهایی (برند + نمایندگی)
 *   📊 v2.15: setProgressSink برای نوار پیشرفت زنده (مثل خطایاب)
 *
 * @package SahandBrandMaker\Engine
 * @version 2.0
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

    /**
     * 🌐 رجیستری سرویس‌های تولید تصویر — لیست تنظیمات از همین جا خوانده می‌شود
     *
     * کلید => [برچسب فارسی، نیاز به کلید؟، توضیح کلید]
     */
    const SERVICES = [
        'pollinations_flux'  => ['پولینیشنز — Flux',            false, 'رایگان و بدون کلید — کیفیت بالا (پیش‌فرض)'],
        'pollinations_turbo' => ['پولینیشنز — Turbo',            false, 'رایگان و بدون کلید — سریع‌تر، کیفیت متوسط'],
        'huggingface'        => ['Hugging Face — SDXL',          true,  'توکن رایگان از huggingface.co/settings/tokens'],
        'deepai'             => ['DeepAI — Text2Img',            true,  'کلید رایگان از deepai.org/dashboard/profile'],
        'together'           => ['Together AI — FLUX schnell',   true,  'کلید رایگان از api.together.ai'],
        'stability'          => ['Stability AI — SD3 Core',      true,  'کلید از platform.stability.ai'],
        'openai'             => ['OpenAI — DALL·E 3',            true,  'کلید از platform.openai.com'],
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
                // گیرنده هرگز جریان اصلی را نمی‌شکند
            }
        }
    }

    /* ==================================================
     * ⚙️ تنظیمات سرویس (v2.0 — «سرویس انتخابی همیشه استفاده می‌شود»)
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
     * بدون‌کلیدها (flux → turbo) و کلیددارهایی که کلیدشان ثبت شده.
     */
    private function serviceChain(): array
    {
        $cfg = self::settings();
        $chain = [$cfg['service']];
        foreach (['pollinations_flux', 'pollinations_turbo'] as $free) {
            if (!in_array($free, $chain, true)) {
                $chain[] = $free;
            }
        }
        foreach (array_keys(self::SERVICES) as $svc) {
            if (!in_array($svc, $chain, true) && self::SERVICES[$svc][1] && $this->serviceKey($svc) !== '') {
                $chain[] = $svc;
            }
        }
        return $chain;
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

        /* 🎯 دستگاه معتبر — مستقیم یا استنتاج از عنوان/محتوا */
        $norm = ArticleImageService::normalizeDeviceKey($deviceKey);
        if ($norm === null) {
            $norm = ArticleImageService::normalizeDeviceKey((string)ArticleImageService::inferDeviceKey($title, ''));
        }
        $devicePrompt = self::DEVICE_PROMPTS[$norm] ?? 'household appliance';

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
        $usedService = '';
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
            $svcIdx = 0;
            foreach ($chain as $svc) {
                $svcIdx++;
                $this->progress(
                    (int)round(6 + 82 * (($i - 1 + $svcIdx / max(2, count($chain))) / $count)),
                    'تولید تصویر ' . $i . ' از ' . $count,
                    'سرویس: ' . self::SERVICES[$svc][0] . ($svcIdx > 1 ? ' (تلاش جایگزین ' . $svcIdx . ')' : ' (سرویس انتخابی)')
                );
                $ok = $this->downloadViaService($svc, $prompt, $seed, $abs);
                if ($ok) {
                    $usedService = $usedService ?: $svc;
                    break; // ✅ موفق — سرویس بعدی لازم نیست
                }
                /* پرامپت ساده برای تلاش بعدی (سرویس‌ها گاهی با پرامپت طولانی مشکل دارند) */
                $prompt = $devicePrompt . ' ' . $angle . ', professional product photography, photorealistic, high detail, no text, no watermark';
            }
            if (!$ok) {
                continue; // این عکس در هیچ سرویسی نشد — بقیه ادامه
            }

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
                'service' => $usedService,
            ];
        }
        if ($images) {
            $this->progress(90, 'تصاویر آماده شد', count($images) . ' تصویر واقعی با سرویس «' . self::SERVICES[$usedService][0] . '» تولید شد');
        }
        return $images;
    }

    /* ==================================================
     * 🌐 درایورهای سرویس‌ها (v2.0)
     * ================================================== */

    /**
     * ⬇️ دانلود عکس از سرویس مشخص + اعتبارسنجی
     * @return bool موفقیت ذخیره فایل
     */
    private function downloadViaService(string $service, string $prompt, int $seed, string $destAbs): bool
    {
        $timeout = max(20, (int)self::settings()['timeout']);
        $key = $this->serviceKey($service);

        switch ($service) {
            case 'pollinations_flux':
            case 'pollinations_turbo':
                $model = $service === 'pollinations_turbo' ? 'turbo' : 'flux';
                $url = 'https://image.pollinations.ai/prompt/' . rawurlencode($prompt)
                    . '?width=' . self::W . '&height=' . self::H
                    . '&nologo=true&enhance=false&model=' . $model . '&seed=' . ($seed % 2147483647);
                return $this->httpDownload($url, $destAbs, $timeout, []);

            case 'huggingface':
                if ($key === '') { return false; }
                $url = 'https://api-inference.huggingface.co/models/stabilityai/stable-diffusion-xl-base-1.0';
                $body = $this->httpPost($url, json_encode(['inputs' => $prompt]), $timeout, [
                    'Authorization: Bearer ' . $key,
                    'Content-Type: application/json',
                    'Accept: image/png',
                ]);
                return $body !== null && $this->saveValidatedBinary($body, $destAbs);

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
        }
        return false;
    }

    /** ⬇️ دانلود مستقیم فایل از URL با اعتبارسنجی تصویر */
    private function httpDownload(string $url, string $destAbs, int $timeout, array $headers): bool
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
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SahandBrandMaker/2.15)',
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
            && filesize($destAbs) > 15000
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
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SahandBrandMaker/2.15)',
            CURLOPT_HTTPHEADER     => $headers,
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
        if (!(str_starts_with($bin, "\xFF\xD8\xFF") || str_starts_with($bin, "\x89PNG\r\n\x1A\n"))) {
            return false; // JSON خطا یا HTML — نه تصویر
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
            /* تست سبک: تصویر ریز ۶۴×۶۴ با پرامپت کوتاه */
            $tmpDir = sys_get_temp_dir() ?: ROOT_PATH . '/cache';
            $testAbs = $tmpDir . '/aiphoto-test-' . substr(md5((string)mt_rand()), 0, 8) . '.jpg';
            $timeout = 25;
            $key = ($keyOverride !== null && trim($keyOverride) !== '') ? trim($keyOverride) : $this->serviceKey($service);
            switch ($service) {
                case 'pollinations_flux':
                case 'pollinations_turbo':
                    $model = $service === 'pollinations_turbo' ? 'turbo' : 'flux';
                    $url = 'https://image.pollinations.ai/prompt/' . rawurlencode('a red apple on a table')
                        . '?width=64&height=64&nologo=true&model=' . $model . '&seed=1';
                    $ok = $this->httpDownload($url, $testAbs, $timeout, []);
                    $message = $ok ? 'سرویس پاسخ داد و تصویر ساخت.' : 'سرویس در مهلت ' . $timeout . ' ثانیه تصویر برنگرداند.';
                    break;
                case 'huggingface':
                    if ($key === '') {
                        $message = 'کلید Hugging Face در تنظیمات ثبت نشده است.';
                        break;
                    }
                    $body = $this->httpPost('https://api-inference.huggingface.co/models/stabilityai/stable-diffusion-xl-base-1.0',
                        json_encode(['inputs' => 'a red apple']), $timeout,
                        ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: image/png']);
                    $ok = $body !== null && str_starts_with($body, "\x89PNG");
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
                case 'together':
                case 'openai':
                case 'stability':
                    if ($key === '') {
                        $message = 'کلید این سرویس در تنظیمات ثبت نشده است.';
                        break;
                    }
                    /* برای سرویس‌های کلیددار پولی، فقط اعتبار کلید با درخواست حداقلی بررسی می‌شود */
                    if ($service === 'together') {
                        $json = $this->httpPost('https://api.together.xyz/v1/models', '', 15, ['Authorization: Bearer ' . $key]);
                        $ok = $json !== null;
                    } elseif ($service === 'openai') {
                        $json = $this->httpPost('https://api.openai.com/v1/models', '', 15, ['Authorization: Bearer ' . $key]);
                        $ok = $json !== null;
                    } else {
                        $body = $this->httpPostMultipart('https://api.stability.ai/v2beta/stable-image/generate/core',
                            ['prompt' => 'a red apple', 'output_format' => 'jpeg'], 25,
                            ['Authorization: Bearer ' . $key, 'Accept: image/jpeg']);
                        $ok = $body !== null && str_starts_with($body, "\xFF\xD8\xFF");
                    }
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
