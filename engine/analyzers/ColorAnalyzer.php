<?php
/**
 * 🎨 کلاس تحلیل رنگ — استخراج پالت از لوگو
 * =========================================
 * با الگوریتم K-Means رنگ‌های غالب لوگو را استخراج،
 * طبقه‌بندی و پالت تم روشن/تاریک تولید می‌کند.
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class ColorAnalyzer
{
    /** @var int تعداد خوشه‌های K-Means */
    const K = 6;

    /** @var int حداکثر ابعاد پردازش لوگو (برای سرعت) */
    const MAX_DIMENSION = 160;

    /**
     * 🎨 تحلیل کامل لوگو — خروجی: رنگ‌های غالب + طبقه‌بندی + دو پالت
     *
     * @param string $logoPath مسیر فایل لوگو
     * @return array|null در صورت خطا null
     */
    public function analyze(string $logoPath): ?array
    {
        $processor = new ImageProcessor();
        $img = $processor->load($logoPath);
        if (!$img) {
            return null;
        }

        // 🎨 مسیر SVG — رنگ‌ها از fill/stroke استخراج شده‌اند (بدون نیاز به GD)
        if (is_array($img) && !empty($img['svg'])) {
            return $this->analyzeFromPixels($img['pixels'], true);
        }

        // 📏 کوچک‌سازی برای سرعت پردازش
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, self::MAX_DIMENSION / max($w, $h));
        if ($scale < 1) {
            $newW = (int)round($w * $scale);
            $newH = (int)round($h * $scale);
            $small = imagecreatetruecolor($newW, $newH);
            imagealphablending($small, false);
            imagesavealpha($small, true);
            $transparent = imagecolorallocatealpha($small, 0, 0, 0, 127);
            imagefilledrectangle($small, 0, 0, $newW, $newH, $transparent);
            imagecopyresampled($small, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($img);
            $img = $small;
            $w = $newW;
            $h = $newH;
        }

        // 🌈 استخراج پیکسل‌های معنادار (غیرشفاف و نه سیاه/سفید کامل)
        $pixels = [];
        for ($x = 0; $x < $w; $x += 2) {
            for ($y = 0; $y < $h; $y += 2) {
                $rgba = imagecolorat($img, $x, $y);
                $a = ($rgba >> 24) & 0x7F;
                if ($a > 60) {
                    continue; // پیکسل تقریباً شفاف — رد شود
                }
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                // حذف سیاه/سفید خالص (پس‌زمینه‌ها)
                $max = max($r, $g, $b);
                $min = min($r, $g, $b);
                if ($max > 245 && $min > 235) {
                    continue; // سفید
                }
                if ($max < 18) {
                    continue; // سیاه
                }
                // حذف خاکستری‌های بی‌رنگ کم‌اشباع
                $sat = $max == 0 ? 0 : ($max - $min) / $max;
                if ($sat < 0.12) {
                    continue;
                }
                $pixels[] = [$r, $g, $b];
            }
        }

        // 🧯 اگر پیکسل رنگی کافی نبود، کل پیکسل‌ها را استفاده کن
        // ⚠️ باید قبل از imagedestroy انجام شود — قبلاً بعد از آن بود و
        // برای لوگوهای تک‌رنگ/سیاه‌وسفید خطای Fatal می‌داد (باگ مرحله ۱)
        if (count($pixels) < 30) {
            for ($x = 0; $x < $w; $x += 2) {
                for ($y = 0; $y < $h; $y += 2) {
                    $rgba = imagecolorat($img, $x, $y);
                    $r = ($rgba >> 16) & 0xFF;
                    $g = ($rgba >> 8) & 0xFF;
                    $b = $rgba & 0xFF;
                    $max = max($r, $g, $b);
                    if ($max > 248) {
                        continue; // سفید کامل پس‌زمینه
                    }
                    $pixels[] = [$r, $g, $b];
                }
            }
        }
        imagedestroy($img);

        return $this->analyzeFromPixels($pixels, false);
    }

    /**
     * 🧮 تحلیل مشترک از آرایه پیکسل‌ها (PNG/WEBP و SVG هر دو از این مسیر)
     *
     * @param array $pixels آرایه [r,g,b]
     * @param bool  $isSvg  آیا پیکسل‌ها از SVG آمده‌اند؟
     */
    private function analyzeFromPixels(array $pixels, bool $isSvg = false): ?array
    {
        if (empty($pixels)) {
            return null;
        }

        // 🔵 اجرای K-Means
        $clusters = $this->kmeans($pixels, self::K);

        // 📊 مرتب‌سازی بر اساس تعداد اعضا (رنگ پرتکرار اول)
        usort($clusters, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        $dominant = array_map(function ($c) {
            return $this->rgbToHex($c['r'], $c['g'], $c['b']);
        }, $clusters);

        // 🏷️ طبقه‌بندی نقش رنگ‌ها
        $classified = $this->classify($dominant);

        // 🌗 تولید پالت‌های روشن و تاریک
        $light = $this->buildLightPalette($classified);
        $dark = $this->buildDarkPalette($classified);

        return [
            'dominant'   => array_slice($dominant, 0, 6),           // رنگ‌های غالب
            'classified' => $classified,                             // طبقه‌بندی نقش‌ها
            'light'      => $light,                                  // تم روشن (CSS Variables)
            'dark'       => $dark,                                   // تم تاریک
        ];
    }

    /**
     * 🔵 الگوریتم K-Means — خوشه‌بندی رنگ‌ها
     *
     * @param array $pixels آرایه [r,g,b]
     * @param int   $k تعداد خوشه
     * @return array آرایه خوشه‌ها ['r','g','b','count']
     */
    private function kmeans(array $pixels, int $k): array
    {
        $count = count($pixels);
        $k = min($k, max(2, (int)floor($count / 10)));

        // مقداردهی اولیه: انتخاب پراکنده نقاط
        $centroids = [];
        $step = max(1, (int)floor($count / $k));
        for ($i = 0; $i < $k; $i++) {
            $idx = min($count - 1, $i * $step);
            $centroids[] = $pixels[$idx];
        }

        $assignments = array_fill(0, $count, 0);

        // 🔄 تکرار تا همگرایی (حداکثر ۱۲ دور)
        for ($iter = 0; $iter < 12; $iter++) {
            $changed = false;

            // انتساب هر پیکسل به نزدیک‌ترین خوشه
            for ($i = 0; $i < $count; $i++) {
                $best = 0;
                $bestDist = PHP_INT_MAX;
                foreach ($centroids as $ci => $c) {
                    $dist = ($pixels[$i][0] - $c[0]) ** 2
                          + ($pixels[$i][1] - $c[1]) ** 2
                          + ($pixels[$i][2] - $c[2]) ** 2;
                    if ($dist < $bestDist) {
                        $bestDist = $dist;
                        $best = $ci;
                    }
                }
                if ($assignments[$i] !== $best) {
                    $assignments[$i] = $best;
                    $changed = true;
                }
            }
            if (!$changed && $iter > 0) {
                break;
            }

            // بازمحاسبه مراکز خوشه‌ها
            $sums = array_fill(0, $k, [0, 0, 0, 0]);
            for ($i = 0; $i < $count; $i++) {
                $a = $assignments[$i];
                $sums[$a][0] += $pixels[$i][0];
                $sums[$a][1] += $pixels[$i][1];
                $sums[$a][2] += $pixels[$i][2];
                $sums[$a][3]++;
            }
            foreach ($sums as $ci => $s) {
                if ($s[3] > 0) {
                    $centroids[$ci] = [(int)round($s[0] / $s[3]), (int)round($s[1] / $s[3]), (int)round($s[2] / $s[3])];
                }
            }
        }

        // 📊 شمارش اعضای هر خوشه
        $clusters = [];
        foreach ($centroids as $ci => $c) {
            $cnt = 0;
            foreach ($assignments as $a) {
                if ($a === $ci) {
                    $cnt++;
                }
            }
            $clusters[] = ['r' => $c[0], 'g' => $c[1], 'b' => $c[2], 'count' => $cnt];
        }
        return $clusters;
    }

    /**
     * 🏷️ طبقه‌بندی رنگ‌ها به نقش‌های پالت
     */
    private function classify(array $dominant): array
    {
        $result = [
            'primary'   => $dominant[0] ?? '#2563eb',
            'secondary' => $dominant[1] ?? '#0ea5e9',
            'accent'    => $dominant[2] ?? '#f59e0b',
        ];
        // اگر رنگ سوم با دومی بسیار نزدیک بود، رنگ چهارم یا مکمل را استفاده کن
        if (isset($dominant[2]) && $this->colorDistance($result['secondary'], $dominant[2]) < 60) {
            $result['accent'] = $dominant[3] ?? $this->complement($result['primary']);
        }
        return $result;
    }

    /**
     * 🌞 ساخت پالت تم روشن (۱۶+ متغیر CSS)
     */
    private function buildLightPalette(array $c): array
    {
        $primary = $c['primary'];
        $palette = [
            '--color-primary'        => $primary,
            '--color-primary-light'  => $this->adjustBrightness($primary, 25),
            '--color-primary-dark'   => $this->adjustBrightness($primary, -25),
            '--color-secondary'      => $c['secondary'],
            '--color-accent'         => $c['accent'],
            '--color-background'     => '#ffffff',
            '--color-surface'        => '#f5f7fa',
            '--color-text'           => '#1f2937',
            '--color-text-light'     => '#6b7280',
            '--color-border'         => '#e5e7eb',
            '--color-success'        => '#16a34a',
            '--color-warning'        => '#f59e0b',
            '--color-error'          => '#dc2626',
            '--color-info'           => '#2563eb',
            '--gradient-primary'     => 'linear-gradient(135deg, ' . $primary . ' 0%, ' . $c['secondary'] . ' 100%)',
            '--shadow-color'         => 'rgba(0, 0, 0, 0.08)',
            '--on-primary'           => contrast_text_color($primary),
        ];
        /* 🎨 v2.25 — تضمین خوانایی WCAG (کنتراست متن/دکمه/گرادیانت) */
        [$palette] = self::ensureReadable($palette, 'light');
        return $palette;
    }

    /**
     * 🌙 ساخت پالت تم تاریک
     */
    private function buildDarkPalette(array $c): array
    {
        $primaryDark = $this->adjustBrightness($c['primary'], -15);
        $secondaryDark = $this->adjustBrightness($c['secondary'], -15);
        $palette = [
            '--color-primary'        => $primaryDark,
            '--color-primary-light'  => $c['primary'],
            '--color-primary-dark'   => $this->adjustBrightness($primaryDark, -20),
            '--color-secondary'      => $secondaryDark,
            '--color-accent'         => $this->adjustBrightness($c['accent'], 10),
            '--color-background'     => '#111827',
            '--color-surface'        => '#1f2937',
            '--color-text'           => '#f3f4f6',
            '--color-text-light'     => '#9ca3af',
            '--color-border'         => '#374151',
            '--color-success'        => '#22c55e',
            '--color-warning'        => '#fbbf24',
            '--color-error'          => '#ef4444',
            '--color-info'           => '#3b82f6',
            '--gradient-primary'     => 'linear-gradient(135deg, ' . $primaryDark . ' 0%, ' . $secondaryDark . ' 100%)',
            '--shadow-color'         => 'rgba(0, 0, 0, 0.4)',
            '--on-primary'           => contrast_text_color($primaryDark),
        ];
        /* 🎨 v2.25 — در تم تاریک primary باید روی زمینه تیره «روشن» باشد */
        [$palette] = self::ensureReadable($palette, 'dark');
        return $palette;
    }

    /**
     * 🎚️ روشن/تیره کردن رنگ HEX
     *
     * @param string $hex رنگ ورودی
     * @param int    $percent درصد تغییر (+ روشن‌تر / - تیره‌تر)
     */
    public function adjustBrightness(string $hex, int $percent): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $amount = $percent / 100 * 255;
        $r = max(0, min(255, (int)round($r + $amount)));
        $g = max(0, min(255, (int)round($g + $amount)));
        $b = max(0, min(255, (int)round($b + $amount)));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /* ==================================================
     * 🎨 v2.25 — موتور تضمین کنتراست WCAG
     * ریشه شکایت «متن‌ها و دکمه‌ها دیده نمی‌شوند / تداخل رنگ»:
     * رنگ‌های ورودی (از لوگو یا ویرایش دستی) هیچ تضمینی ندارند؛ این متد
     * پالت را طوری اصلاح می‌کند که:
     *   ① متن روی زمینه ≥ ۴.۵:۱ (WCAG AA)
     *   ② متن دکمه (--on-primary) روی primary ≥ ۴.۵:۱
     *   ③ primary خودش روی زمینه ≥ ۴.۵:۱ (لینک‌ها/دکمه‌های خطی)
     *   ④ متن هیرو (--on-gradient) روی «هر دو» سر گرادیانت خوانا باشد
     *   ⑤ سطح/حاشیه از زمینه قابل‌تشخیص باشد
     * خروجی: [پالت اصلاح‌شده, یادداشت‌های اصلاح]
     * ================================================== */

    /** @var float حداقل نسبت کنتراست متن عادی (WCAG AA) */
    const CONTRAST_TEXT = 4.5;
    /** @var float حداقل نسبت کنتراست متن درشت/سطح (WCAG AA large) */
    const CONTRAST_LARGE = 3.0;

    /**
     * 💡 روشنایی نسبی رنگ (WCAG relative luminance)
     */
    public static function luminance(string $hex): float
    {
        $rgb = self::hexToRgbArr($hex);
        $ch = [];
        foreach ($rgb as $c) {
            $c = $c / 255;
            $ch[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $ch[0] + 0.7152 * $ch[1] + 0.0722 * $ch[2];
    }

    /**
     * 📏 نسبت کنتراست دو رنگ (۱ تا ۲۱)
     */
    public static function contrastRatio(string $hex1, string $hex2): float
    {
        $l1 = self::luminance($hex1);
        $l2 = self::luminance($hex2);
        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /**
     * 🔤 تبدیل HEX به RGB (استاتیک — برای موتور کنتراست)
     */
    private static function hexToRgbArr(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return [0, 0, 0];
        }
        return [(int)hexdec(substr($hex, 0, 2)), (int)hexdec(substr($hex, 2, 2)), (int)hexdec(substr($hex, 4, 2))];
    }

    /** 🔍 اعتبارسنجی HEX شش‌رقمی */
    private static function isHex6(string $hex): bool
    {
        return (bool)preg_match('/^#?[0-9a-f]{6}$/i', trim($hex));
    }

    /**
     * 🎚️ شیفت همسو رنگ به سمت روشن (+) یا تیره (−) — گام درصدی
     */
    private static function shiftToward(string $hex, int $percent): string
    {
        $rgb = self::hexToRgbArr($hex);
        $amount = (int)round($percent / 100 * 255);
        return sprintf('#%02x%02x%02x',
            max(0, min(255, $rgb[0] + $amount)),
            max(0, min(255, $rgb[1] + $amount)),
            max(0, min(255, $rgb[2] + $amount))
        );
    }

    /**
     * 🎯 جابه‌جایی رنگ تا رسیدن به کنتراست هدف نسبت به زمینه
     * جهت: زمینه روشن → تیره‌سازی؛ زمینه تیره → روشن‌سازی (حداکثر ۲۵ گام ۳٪)
     */
    private static function adjustForContrast(string $hex, string $bg, float $target): string
    {
        if (!self::isHex6($hex) || !self::isHex6($bg)) {
            return $hex;
        }
        $bgLight = self::luminance($bg) > 0.35;
        $dir = $bgLight ? -3 : 3;
        $out = $hex;
        for ($i = 0; $i < 25; $i++) {
            if (self::contrastRatio($out, $bg) >= $target) {
                break;
            }
            $out = self::shiftToward($out, $dir);
        }
        return $out;
    }

    /**
     * 🧪 بهترین رنگ متن (سفید یا تیره) برای یک/دو پس‌زمینه — بیشترین کمینه کنتراست
     */
    public static function bestTextColor(string $bg1, string $bg2 = ''): string
    {
        $white = '#ffffff';
        $dark = '#0f172a';
        if (!self::isHex6($bg1)) {
            return $dark;
        }
        $wMin = self::contrastRatio($white, $bg1);
        $dMin = self::contrastRatio($dark, $bg1);
        if ($bg2 !== '' && self::isHex6($bg2)) {
            $wMin = min($wMin, self::contrastRatio($white, $bg2));
            $dMin = min($dMin, self::contrastRatio($dark, $bg2));
        }
        return $wMin >= $dMin ? $white : $dark;
    }

    /**
     * 🛡️ اصلاح پالت تا خوانایی تضمین‌شده — تم روشن یا تاریک
     *
     * @param array  $palette پالت (متغیر => مقدار)
     * @param string $theme   'light' | 'dark'
     * @return array [پالت اصلاح‌شده, آرایه یادداشت اصلاح‌ها]
     */
    public static function ensureReadable(array $palette, string $theme = 'light'): array
    {
        $notes = [];
        $get = static function (array $p, string $k, string $def) {
            $v = trim((string)($p[$k] ?? ''));
            return self::isHex6($v) ? (strpos($v, '#') === 0 ? $v : '#' . $v) : $def;
        };
        $bg = $get($palette, '--color-background', $theme === 'dark' ? '#111827' : '#ffffff');
        $isDark = $theme === 'dark';
        $setText = $isDark ? '#f3f4f6' : '#1f2937';
        $setTextLight = $isDark ? '#9ca3af' : '#6b7280';

        /* ① متن اصلی و کم‌رنگ روی زمینه */
        foreach (['--color-text' => $setText, '--color-text-light' => $setTextLight] as $var => $fallback) {
            $cur = $get($palette, $var, $fallback);
            if (self::contrastRatio($cur, $bg) < self::CONTRAST_TEXT) {
                $fixed = self::adjustForContrast($cur, $bg, self::CONTRAST_TEXT);
                if (self::contrastRatio($fixed, $bg) >= self::CONTRAST_TEXT) {
                    $palette[$var] = $fixed;
                    $notes[] = $var . ' برای خوانایی روی زمینه اصلاح شد';
                } else {
                    $palette[$var] = $fallback;
                    $notes[] = $var . ' به مقدار استاندارد بازگشت (کنتراست ناکافی)';
                }
            }
        }

        /* ② primary باید روی زمینه خوانا باشد (لینک‌ها/دکمه‌های خطی/عنوان‌های رنگی) */
        $primary = $get($palette, '--color-primary', $isDark ? '#3b82f6' : '#1e40af');
        $fixedPrimary = self::adjustForContrast($primary, $bg, self::CONTRAST_TEXT);
        if (strcasecmp($fixedPrimary, $primary) !== 0
            && self::contrastRatio($fixedPrimary, $bg) >= self::CONTRAST_LARGE) {
            $palette['--color-primary'] = $fixedPrimary;
            $notes[] = 'رنگ اصلی برای دیده‌شدن روی زمینه ' . ($isDark ? 'روشن‌تر' : 'تیره‌تر') . ' شد';
            /* تیره‌تر/روشن‌ترِ primary هم هماهنگ شود */
            $pd = $get($palette, '--color-primary-dark', $fixedPrimary);
            $palette['--color-primary-dark'] = self::adjustForContrast($pd, $bg, self::CONTRAST_TEXT);
        }
        $primary = $get($palette, '--color-primary', $isDark ? '#3b82f6' : '#1e40af');

        /* ③ متن دکمه اصلی — با بهترین کنتراست ممکن */
        $palette['--on-primary'] = self::bestTextColor($primary);

        /* ④ متن هیرو — روی هر دو سر گرادیانت خوانا */
        $secondary = $get($palette, '--color-secondary', $primary);
        $palette['--on-gradient'] = self::bestTextColor($primary, $secondary);

        /* ⑤ سطح و حاشیه باید از زمینه قابل‌تشخیص باشند */
        $surface = $get($palette, '--color-surface', $isDark ? '#1f2937' : '#f5f7fa');
        /* آستانه ۱.۰۵: سطح استاندارد (#f5f7fa روی سفید = ۱.۰۷) عمداً ظریف است و
           نباید «اصلاح» شود؛ فقط رنگ‌های تقریباً یکسان با زمینه تفکیک می‌شوند */
        if (self::contrastRatio($surface, $bg) < 1.05) {
            $palette['--color-surface'] = self::shiftToward($bg, $isDark ? 6 : -6);
            $notes[] = 'سطح (surface) از زمینه تفکیک شد';
        }
        $border = $get($palette, '--color-border', $isDark ? '#374151' : '#e5e7eb');
        if (self::contrastRatio($border, $bg) < 1.05) {
            $palette['--color-border'] = self::shiftToward($bg, $isDark ? 14 : -14);
            $notes[] = 'حاشیه از زمینه تفکیک شد';
        }

        /* ⑥ گرادیانت رشته‌ای با primary اصلاح‌شده بازسازی شود */
        if (isset($palette['--gradient-primary']) && is_string($palette['--gradient-primary'])
            && preg_match('/^linear-gradient\(\s*135deg\s*,\s*(#[0-9a-f]{3,6})/i', (string)$palette['--gradient-primary'])) {
            $palette['--gradient-primary'] = 'linear-gradient(135deg, ' . $palette['--color-primary'] . ' 0%, ' . $secondary . ' 100%)';
            $palette['--on-gradient'] = self::bestTextColor($palette['--color-primary'], $secondary);
        }

        return [$palette, $notes];
    }

    /**
     * 🔄 رنگ مکمل (برای accent در صورت نیاز)
     */
    private function complement(string $hex): string
    {
        $hex = ltrim($hex, '#');
        $r = 255 - hexdec(substr($hex, 0, 2));
        $g = 255 - hexdec(substr($hex, 2, 2));
        $b = 255 - hexdec(substr($hex, 4, 2));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * 📏 فاصله اقلیدسی دو رنگ
     */
    private function colorDistance(string $hex1, string $hex2): float
    {
        $c1 = $this->hexToRgb($hex1);
        $c2 = $this->hexToRgb($hex2);
        return sqrt(($c1[0] - $c2[0]) ** 2 + ($c1[1] - $c2[1]) ** 2 + ($c1[2] - $c2[2]) ** 2);
    }

    /**
     * 🔤 تبدیل RGB به HEX
     */
    private function rgbToHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * 🔤 تبدیل HEX به RGB
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /**
     * 📝 تولید فایل CSS متغیرهای پالت
     *
     * @param array $palette آرایه متغیر => مقدار
     * @param string $selector سلکتور CSS (مثلاً :root یا [data-theme=dark])
     */
    public function toCss(array $palette, string $selector = ':root'): string
    {
        $lines = [$selector . ' {'];
        foreach ($palette as $var => $value) {
            $lines[] = '    ' . $var . ': ' . $value . ';';
        }
        $lines[] = '}';
        return implode("\n", $lines);
    }
}
