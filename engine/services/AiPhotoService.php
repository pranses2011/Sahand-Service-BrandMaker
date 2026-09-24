<?php
/**
 * 📸 AiPhotoService — تولید عکس واقعی مرتبط با موضوع مقاله (v1.0)
 * =================================================================
 * طبق درخواست کاربر: «برای تصاویر مقاله از تصاویر آماده استفاده نکن و
 * تصویر واقعی رو در همان لحظه و مرتبط با موضوع مقاله بساز»
 *
 * روش: تولید عکس فوتورئال با سرویس رایگان Pollinations (بدون کلید API)
 *      → ذخیره در uploads/articles/ai/
 *      → مهر واترمارک دوتایی (لوگوی برند پایین-راست + لوگوی نمایندگی پایین-چپ)
 *
 * ویژگی‌ها:
 *   🎯 پرامپت انگلیسی دقیق از «عنوان + دستگاه + نوع مقاله» ساخته می‌شود
 *   🌱 بذر یکتا (زمان + شناسه مقاله) → هر بار تولید، تصویر جدید
 *   🛡 اعتبارسنجی خروجی (JPEG واقعی + حداقل حجم) قبل از پذیرش
 *   🔁 تلاش مجدد با پرامپت ساده‌شده در صورت شکست
 *   🪧 واترمارک‌های بزرگ و شفاف روی تصویر نهایی
 *   🛟 در صورت قطعی سرویس → فراخواننده به بسته تصاویر برمی‌گردد
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0
 */
class AiPhotoService
{
    /** 📐 ابعاد تصویر مقاله (۱۶:۹) */
    const W = 1200;
    const H = 675;

    /** ⏱ مهلت هر دانلود (ثانیه) */
    const TIMEOUT = 45;

    /** 🌐 آدرس سرویس تولید تصویر */
    const ENDPOINT = 'https://image.pollinations.ai/prompt/';

    /** 🔢 حداکثر تعداد عکس هر مقاله (۱ شاخص + ۲ درون‌متن) */
    const MAX_PER_ARTICLE = 3;

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

    /**
     * 📸 تولید عکس‌های واقعی مرتبط با مقاله + واترمارک
     *
     * @param int    $articleId شناسه مقاله (برای نام فایل)
     * @param string $title     عنوان مقاله (برای استنتاج دستگاه)
     * @param string $deviceKey کلید دستگاه (خالی = از عنوان استنتاج)
     * @param string $topicType نوع مقاله
     * @param array  $brand     رکورد برند (لوگو برای واترمارک)
     * @param int    $count     تعداد عکس (۱ تا ۲)
     * @return array ['path','url','alt','caption'] × N — خالی در صورت شکست کامل
     */
    public function generateForArticle(int $articleId, string $title, string $deviceKey, string $topicType, array $brand, int $count = self::MAX_PER_ARTICLE): array
    {
        $count = max(1, min(self::MAX_PER_ARTICLE, $count));

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

            $ok = $this->download($prompt, $seed, $abs);
            if (!$ok) {
                /* 🔁 تلاش دوم — پرامپت ساده‌شده (سرویس گاهی با پرامپت طولانی مشکل دارد) */
                $simple = $devicePrompt . ' ' . $angle . ', professional product photography, photorealistic, high detail, no text, no watermark';
                $ok = $this->download($simple, $seed + 7, $abs);
            }
            if (!$ok) {
                continue; // این عکس رد شد — بقیه ادامه
            }

            /* 🪧 واترمارک دوتایی بزرگ و شفاف روی عکس واقعی */
            $this->stampWatermarks($abs, $brand);

            $images[] = [
                'path'    => $rel,
                'url'     => rtrim(BASE_URL, '/') . '/' . $rel,
                'alt'     => mb_substr($title . ' — ' . $deviceFa . '، تعمیر تخصصی لوازم خانگی', 0, 160),
                'caption' => mb_substr($deviceFa . ' — ' . ($i === 1 ? 'نمای اصلی مرتبط با موضوع مقاله' : ($i === 2 ? 'نمای نزدیک و جزئیات فنی' : 'نمای کارشناسی و صحنه خدمات')), 0, 160),
                'role'    => $i === 1 ? 'featured' : 'inline',
                'source'  => 'ai_photo',
            ];
        }
        return $images;
    }

    /**
     * ⬇️ دانلود عکس از سرویس تولید + اعتبارسنجی
     * @return bool موفقیت ذخیره فایل
     */
    private function download(string $prompt, int $seed, string $destAbs): bool
    {
        $url = self::ENDPOINT . rawurlencode($prompt)
            . '?width=' . self::W . '&height=' . self::H
            . '&nologo=true&enhance=false&model=flux&seed=' . ($seed % 2147483647);

        $ch = curl_init($url);
        $fh = @fopen($destAbs, 'wb');
        if (!$fh) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SahandBrandMaker/2.14)',
            CURLOPT_HTTPHEADER     => ['Accept: image/jpeg,image/png,image/*'],
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
     * 🩺 بررسی سلامت/دسترسی سرویس (برای پنل سلامت)
     */
    public function healthCheck(): array
    {
        $t0 = microtime(true);
        $ch = curl_init(self::ENDPOINT . rawurlencode('a red apple on a table') . '?width=64&height=64&nologo=true&seed=1');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ok = $code === 200 && is_string($body) && strlen($body) > 1000 && str_starts_with($body, "\xFF\xD8\xFF");
        return [
            'ok'      => $ok,
            'status'  => $ok ? 'online' : 'offline',
            'latency' => (int)round((microtime(true) - $t0) * 1000),
        ];
    }
}
