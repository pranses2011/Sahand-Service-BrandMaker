<?php
/**
 * 🖼️ سرویس تصاویر مقاله — ArticleImageService v1.0
 * ================================================
 * هر مقاله تولیدی موتور سهند با ۳ تصویر مرتبط عرضه می‌شود:
 *   📌 بنر اصلی (featured) + ۲ تصویر درون‌متن
 *
 * قابلیت‌ها:
 *   🗂️ انتخاب هوشمند تصویر بر اساس دستگاه/موضوع مقاله
 *   ✍️ تولید خودکار alt و کپشن سئو-محور فارسی
 *   🧩 درج تصاویر در جایگاه‌های راهبردی محتوا (بعد از مقدمه، وسط، جمع‌بندی)
 *   🌐 URL مطلق برای استفاده خارج از سایت ساز (تلگرام، سایت دیگر و ...)
 *
 * تصاویر بسته‌بندی‌شده: assets/images/articles/*.jpg (۹ تصویر تخصصی)
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class ArticleImageService
{
    /** 🖼️ نام فایل‌های بسته تصاویر (بدون پسوند) */
    const IMAGE_FILES = [
        'refrigerator', 'washing_machine', 'dishwasher', 'air_conditioner',
        'television', 'workshop', 'spare_parts', 'diagnostics', 'modern_kitchen',
    ];

    /** 🔢 تعداد تصاویر هر مقاله */
    const IMAGES_PER_ARTICLE = 3;

    /** 🗺️ نگاشت دستگاه → تصاویر مرتبط (به‌ترتیب اولویت) */
    const DEVICE_MAP = [
        'refrigerator'     => ['refrigerator', 'diagnostics', 'modern_kitchen'],
        'freezer'          => ['refrigerator', 'diagnostics', 'modern_kitchen'],
        'washing_machine'  => ['washing_machine', 'diagnostics', 'spare_parts'],
        'dishwasher'       => ['dishwasher', 'diagnostics', 'modern_kitchen'],
        'air_conditioner'  => ['air_conditioner', 'diagnostics', 'workshop'],
        'package'          => ['air_conditioner', 'workshop', 'diagnostics'],
        'television'       => ['television', 'diagnostics', 'workshop'],
        'microwave'        => ['workshop', 'diagnostics', 'spare_parts'],
        'vacuum_cleaner'   => ['workshop', 'diagnostics', 'spare_parts'],
        'oven'             => ['modern_kitchen', 'diagnostics', 'workshop'],
        'hood'             => ['modern_kitchen', 'workshop', 'diagnostics'],
        'water_heater'     => ['workshop', 'diagnostics', 'spare_parts'],
    ];

    /** 🗺️ نگاشت نوع مقاله → تصاویر پیشنهادی */
    const TOPIC_TYPE_MAP = [
        'cost_guide'     => ['diagnostics', 'spare_parts', 'workshop'],
        'comparison'     => ['modern_kitchen', 'workshop', 'spare_parts'],
        'user_guide'     => ['modern_kitchen', 'diagnostics', 'workshop'],
        'maintenance'    => ['diagnostics', 'workshop', 'modern_kitchen'],
        'troubleshooting'=> ['diagnostics', 'workshop', 'spare_parts'],
        'seasonal_care'  => ['air_conditioner', 'refrigerator', 'modern_kitchen'],
    ];

    /**
     * 🖼️ انتخاب ۳ تصویر مرتبط برای مقاله
     *
     * @param string|null $deviceKey کلید دستگاه (اختیاری)
     * @param string      $topicType نوع مقاله
     * @param string      $title     عنوان مقاله (برای alt/کپشن)
     * @param string      $focusKw   کلیدواژه کانونی
     * @return array<int, array{file:string, path:string, url:string, alt:string, caption:string, role:string}>
     */
    public function pick(?string $deviceKey, string $topicType, string $title, string $focusKw = ''): array
    {
        $devices = TextProcessor::loadKnowledge('devices');
        $deviceFa = $devices[$deviceKey]['name_fa'] ?? '';

        // 🎯 اولویت اول: نگاشت مستقیم دستگاه
        $candidates = self::DEVICE_MAP[$deviceKey] ?? [];
        // 🎯 اولویت دوم: نوع مقاله
        if (count($candidates) < self::IMAGES_PER_ARTICLE) {
            foreach (self::TOPIC_TYPE_MAP[$topicType] ?? [] as $img) {
                if (!in_array($img, $candidates, true)) {
                    $candidates[] = $img;
                }
            }
        }
        // 🎯 اولویت سوم: بقیه تصاویر بسته
        foreach (self::IMAGE_FILES as $img) {
            if (count($candidates) >= self::IMAGES_PER_ARTICLE + 3) { break; }
            if (!in_array($img, $candidates, true)) {
                $candidates[] = $img;
            }
        }

        $chosen = array_slice($candidates, 0, self::IMAGES_PER_ARTICLE);

        $images = [];
        $roles = ['featured', 'inline_mid', 'inline_end'];
        $roleLabels = [
            'featured'  => 'نمای اصلی مقاله',
            'inline_mid' => 'مرحله بررسی تخصصی',
            'inline_end' => 'جمع‌بندی و نکات کارشناسی',
        ];
        foreach ($chosen as $i => $file) {
            $subject = $deviceFa !== '' ? $deviceFa : ($focusKw !== '' ? $focusKw : 'لوازم خانگی');
            $alt = $title !== ''
                ? $title . ' — ' . ($deviceFa !== '' ? $deviceFa . '، ' : '') . 'تعمیر تخصصی لوازم خانگی'
                : $subject . ' — تعمیر تخصصی توسط کارشناس سهند سرویس';
            $images[] = [
                'file'    => $file,
                'path'    => 'assets/images/articles/' . $file . '.jpg',
                'url'     => self::absoluteUrl($file),
                'alt'     => mb_substr($alt, 0, 160),
                'caption' => mb_substr(($deviceFa !== '' ? $deviceFa : $subject) . ' — ' . $roleLabels[$roles[$i]], 0, 160),
                'role'    => $roles[$i],
            ];
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
     */
    public function figure(array $img): string
    {
        $url = htmlspecialchars($img['url'], ENT_QUOTES, 'UTF-8');
        $alt = htmlspecialchars($img['alt'], ENT_QUOTES, 'UTF-8');
        $cap = htmlspecialchars($img['caption'], ENT_QUOTES, 'UTF-8');
        return "\n<figure class=\"article-figure\">\n" .
            "  <img src=\"{$url}\" alt=\"{$alt}\" loading=\"lazy\" width=\"1344\" height=\"768\">\n" .
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
