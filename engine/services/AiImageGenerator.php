<?php
/**
 * 🎨 AiImageGenerator — مولد تصاویر هوشمند OG و مقاله (v1.0)
 * ==========================================================
 * برای هر مقاله/صفحه، تصویر یکتای مرتبط با «موضوع همان صفحه» تولید می‌کند:
 *
 *   🖼️ ۲ تصویر درون‌متن مقاله (SVG یکتا — گرادیان + الگو + آیکون موضوعی + عنوان)
 *   📌 تصویر OG با نسبت ۱۲۰۰×۶۳۰ (PNG با متن فارسی شکل‌یافته؛ در نبود فونت → SVG)
 *
 * یکتایی: هر تصویر از هش (شناسه + عنوان + موضوع) بذر می‌گیرد → ترکیب رنگ،
 * الگو، آیکون و زاویه در هیچ دو مقاله‌ای یکسان نیست.
 *
 * متن فارسی در PNG: شکل‌دهی حروف (اتصال اولیه/میانی/نهایی/مجزا) + ترتیب بصری
 * RTL به‌صورت خالص PHP انجام می‌شود (بدون نیاز به اکستنشن intl/harfbuzz).
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0
 */
class AiImageGenerator
{
    /** 📐 ابعاد OG (استاندارد شبکه‌های اجتماعی) */
    const OG_W = 1200;
    const OG_H = 630;

    /** 📐 ابعاد تصاویر درون‌متن (۱۶:۹) */
    const ART_W = 1200;
    const ART_H = 675;

    /* ==================================================
     * 🎯 API اصلی
     * ================================================== */

    /**
     * 🎨 تولید کامل تصاویر مقاله: ۲ تصویر درون‌متن یکتا + تصویر OG
     *
     * @param int    $articleId شناسه مقاله
     * @param string $title     عنوان مقاله
     * @param string $deviceKey کلید دستگاه (برای آیکون موضوعی)
     * @param string $topicType نوع مقاله
     * @param array  $brand     رکورد برند (name_fa + رنگ‌ها از extra_settings)
     * @param string $focusKw   کلیدواژه کانونی (برای alt)
     * @return array ['og' => ['path','url'], 'images' => [ ['path','url','alt','caption'] × ۲ ]]
     */
    public function generateForArticle(int $articleId, string $title, string $deviceKey, string $topicType, array $brand, string $focusKw = ''): array
    {
        $seed = crc32($articleId . '|' . $title . '|' . $deviceKey . '|' . $topicType);
        $palette = $this->brandPalette($brand, $seed);
        $dir = 'uploads/articles/ai';
        $absDir = ROOT_PATH . '/' . $dir;
        if (!is_dir($absDir)) {
            @mkdir($absDir, 0755, true);
        }

        $result = ['og' => null, 'images' => []];
        $safeId = preg_replace('/[^a-z0-9\-]/i', '', (string)$articleId) ?: '0';

        /* --- 📌 تصویر OG --- */
        $og = $this->buildOg($seed, $title, $deviceKey, $palette, $brand, $dir . '/og-article-' . $safeId, self::OG_W, self::OG_H);
        $result['og'] = $og;

        /* --- 🖼️ ۲ تصویر درون‌متن یکتا (موضوع متفاوت: نمای ۱ = کل مقاله، نمای ۲ = بخش میانی) --- */
        $alts = [
            $this->articleAlt($title, $focusKw, 1),
            $this->articleAlt($title, $focusKw, 2),
        ];
        $subtitles = [
            $this->trimTitle($title, 42),
            $this->sectionSubtitle($deviceKey, $topicType, $seed),
        ];
        foreach ([1, 2] as $i) {
            $fileBase = $dir . '/ai-article-' . $safeId . '-' . $i;
            $svg = $this->buildArtworkSvg($seed + $i * 7919, $subtitles[$i - 1], $deviceKey, $palette, $brand, self::ART_W, self::ART_H, $i === 1 ? 'hero' : 'inline');
            $path = ROOT_PATH . '/' . $fileBase . '.svg';
            @file_put_contents($path, $svg);
            $result['images'][] = [
                'path' => $fileBase . '.svg',
                'url' => BASE_URL . '/' . $fileBase . '.svg',
                'alt' => $alts[$i - 1],
                'caption' => $subtitles[$i - 1],
            ];
        }

        return $result;
    }

    /**
     * 📌 تولید تصویر OG برای صفحه برند (خانه/خدمات/...) — مرتبط با محتوای همان صفحه
     *
     * @param string $pageType نوع صفحه
     * @param string $pageTitle عنوان صفحه
     * @param array  $brand رکورد برند
     * @param string $entityKey کلید یکتا (برند + صفحه) برای بذر
     * @return array ['path','url']
     */
    public function generateOgForPage(string $pageType, string $pageTitle, array $brand, string $entityKey): array
    {
        $seed = crc32('page|' . $entityKey . '|' . $pageTitle);
        $palette = $this->brandPalette($brand, $seed);
        $dir = 'uploads/og';
        $absDir = ROOT_PATH . '/' . $dir;
        if (!is_dir($absDir)) {
            @mkdir($absDir, 0755, true);
        }
        $safeKey = preg_replace('/[^a-z0-9\-]/i', '', $entityKey) ?: 'page';
        return $this->buildOg($seed, $pageTitle, $pageType, $palette, $brand, $dir . '/og-' . $safeKey, self::OG_W, self::OG_H);
    }

    /* ==================================================
     * 🏗️ ساخت OG — PNG با متن فارسی شکل‌یافته (در صورت فونت) وگرنه SVG
     * ================================================== */

    private function buildOg(int $seed, string $title, string $deviceKey, array $palette, array $brand, string $fileBase, int $w, int $h): array
    {
        $font = $this->findPersianFont();
        $title = trim($title);

        /* 🥇 مسیر ۱: ImageMagick — رندر SVG با شکل‌دهی کامل متن فارسی (در صورت وجود) */
        if (class_exists('Imagick')) {
            try {
                $svg = $this->buildArtworkSvg($seed, $title, $deviceKey, $palette, $brand, $w, $h, 'og');
                $im = new Imagick();
                $im->setBackgroundColor(new ImagickPixel('transparent'));
                $im->readImageBlob($svg);
                $im->setImageFormat('png');
                $pngPath = ROOT_PATH . '/' . $fileBase . '.png';
                if (@file_put_contents($pngPath, $im->getImageBlob()) !== false) {
                    $im->clear();
                    return ['path' => $fileBase . '.png', 'url' => BASE_URL . '/' . $fileBase . '.png', 'format' => 'png'];
                }
                $im->clear();
            } catch (Throwable $e) {
                // ادامه به مسیر بعدی
            }
        }

        /* 🥈 مسیر ۲: GD + شکل‌دهنده فارسی داخلی (PersianGlyphs) */
        if ($font !== null && function_exists('imagettftext')) {
            $pngPath = ROOT_PATH . '/' . $fileBase . '.png';
            try {
                $ok = $this->renderOgPng($pngPath, $seed, $title, $palette, $brand, $w, $h, $font);
                if ($ok) {
                    return ['path' => $fileBase . '.png', 'url' => BASE_URL . '/' . $fileBase . '.png', 'format' => 'png'];
                }
            } catch (Throwable $e) {
                @error_log('[AiImageGenerator] PNG OG failed → SVG fallback: ' . $e->getMessage());
            }
        }

        /* 🛟 مسیر ۳: SVG — متن فارسی توسط مرورگر شکل‌یابی می‌شود */
        $svg = $this->buildArtworkSvg($seed, $title, $deviceKey, $palette, $brand, $w, $h, 'og');
        $svgPath = ROOT_PATH . '/' . $fileBase . '.svg';
        @file_put_contents($svgPath, $svg);
        return ['path' => $fileBase . '.svg', 'url' => BASE_URL . '/' . $fileBase . '.svg', 'format' => 'svg'];
    }

    /** 🖼️ رندر PNG او‌جی با GD — گرادیان + الگو + متن فارسی شکل‌یافته */
    private function renderOgPng(string $destPath, int $seed, string $title, array $palette, array $brand, int $w, int $h, string $font): bool
    {
        mt_srand($seed);
        $img = imagecreatetruecolor($w, $h);

        /* --- گرادیان پس‌زمینه (افقی یا عمودی — رسم خطی، بسیار سریع) --- */
        $c1 = $this->hexRgb($palette['c1']);
        $c2 = $this->hexRgb($palette['c2']);
        $vertical = mt_rand(0, 1) === 1;
        $steps = $vertical ? $h : $w;
        for ($i = 0; $i < $steps; $i++) {
            $t = $i / max(1, $steps - 1);
            $r = (int)round($c1[0] + ($c2[0] - $c1[0]) * $t);
            $g = (int)round($c1[1] + ($c2[1] - $c1[1]) * $t);
            $b = (int)round($c1[2] + ($c2[2] - $c1[2]) * $t);
            $col = imagecolorallocate($img, $r, $g, $b);
            if ($vertical) {
                imageline($img, 0, $i, $w, $i, $col);
            } else {
                imageline($img, $i, 0, $i, $h, $col);
            }
        }

        /* --- لکه‌های نرم نیمه‌شفاف (عمق بصری) --- */
        imagealphablending($img, true);
        $accent = $this->hexRgb($palette['accent']);
        foreach ([[$w * .88, $h * .16, 260], [$w * .08, $h * .9, 300]] as [$cx, $cy, $r]) {
            $blob = imagecreatetruecolor((int)$r * 2, (int)$r * 2);
            $transparent = imagecolorallocatealpha($blob, 0, 0, 0, 127);
            imagefill($blob, 0, 0, $transparent);
            $soft = imagecolorallocatealpha($blob, 255, 255, 255, 110);
            imagefilledellipse($blob, (int)$r, (int)$r, (int)$r * 2, (int)$r * 2, $soft);
            imagecopymerge($img, $blob, (int)($cx - $r), (int)($cy - $r), 0, 0, (int)$r * 2, (int)$r * 2, 26);
            imagedestroy($blob);
        }

        /* --- الگوی نقطه‌ای ملایم --- */
        $dotCol = imagecolorallocatealpha($img, 255, 255, 255, 96);
        for ($x = 30; $x < $w; $x += 44) {
            for ($y = 30; $y < $h; $y += 44) {
                if ((int)(($x + $y) / 44) % 7 !== ($seed % 5)) {
                    imagefilledellipse($img, $x, $y, 3, 3, $dotCol);
                }
            }
        }

        /* --- نوار رنگ تاکیدی --- */
        $bar = imagecolorallocate($img, $accent[0], $accent[1], $accent[2]);
        imagefilledrectangle($img, 0, 0, $w, 10, $bar);

        /* --- متن عنوان فارسی: شکل‌دهی + دو خط --- */
        $white = imagecolorallocate($img, 255, 255, 255);
        $shadow = imagecolorallocate($img, 0, 0, 0);
        $lines = $this->wrapPersian($title, 26, 2);
        $size = count($lines) > 1 ? 46 : 54;
        $lineH = (int)($size * 1.55);
        $blockH = $lineH * count($lines);
        $y = (int)(($h - $blockH) / 2 + $size);
        foreach ($lines as $line) {
            $shaped = PersianGlyphs::shapeForImage($line);
            $box = imagettfbbox($size, 0, $font, $shaped);
            $tw = abs($box[4] - $box[0]);
            $x = (int)(($w - $tw) / 2);
            imagettftext($img, $size, 0, $x + 2, $y + 2, $shadow, $font, $shaped); // سایه
            imagettftext($img, $size, 0, $x, $y, $white, $font, $shaped);
            $y += $lineH;
        }

        /* --- نام برند (پایین) --- */
        $brandName = trim((string)($brand['name_fa'] ?? ''));
        if ($brandName !== '') {
            $bShaped = PersianGlyphs::shapeForImage($brandName);
            $bs = 26;
            $box = imagettfbbox($bs, 0, $font, $bShaped);
            $tw = abs($box[4] - $box[0]);
            imagettftext($img, $bs, 0, (int)(($w - $tw) / 2) + 1, $h - 38, $shadow, $font, $bShaped);
            imagettftext($img, $bs, 0, (int)(($w - $tw) / 2), $h - 39, $bar, $font, $bShaped);
        }

        $ok = imagepng($img, $destPath, 7);
        imagedestroy($img);
        return $ok;
    }

    /* ==================================================
     * 🎨 آثار SVG (تصاویر مقاله + fallback های OG)
     * ================================================== */

    private function buildArtworkSvg(int $seed, string $title, string $deviceKey, array $palette, array $brand, int $w, int $h, string $role): string
    {
        mt_srand($seed);
        $c1 = $palette['c1'];
        $c2 = $palette['c2'];
        $accent = $palette['accent'];
        $angle = mt_rand(0, 360);
        $icon = $this->deviceEmoji($deviceKey);
        $pattern = mt_rand(0, 4);

        $p = [];
        $p[] = sprintf('<defs><linearGradient id="g%d" gradientTransform="rotate(%d .5 .5)">
            <stop offset="0" stop-color="%s"/><stop offset="1" stop-color="%s"/></linearGradient>
            <linearGradient id="s%d" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#000" stop-opacity=".38"/><stop offset="1" stop-color="#000" stop-opacity=".82"/></linearGradient></defs>',
            $seed, $angle, $c1, $c2, $seed);
        $p[] = '<rect width="' . $w . '" height="' . $h . '" fill="url(#g' . $seed . ')"/>';

        /* الگوها */
        if ($pattern === 0) { // نقاط
            $dots = [];
            for ($x = 34; $x < $w; $x += 48) {
                for ($y = 34; $y < $h; $y += 48) {
                    $dots[] = sprintf('<circle cx="%d" cy="%d" r="2.6" fill="#fff" opacity=".14"/>', $x, $y);
                }
            }
            $p[] = implode('', $dots);
        } elseif ($pattern === 1) { // خطوط مورب
            $lines = [];
            for ($i = -$h; $i < $w + $h; $i += 90) {
                $lines[] = sprintf('<line x1="%d" y1="0" x2="%d" y2="%d" stroke="#fff" stroke-width="26" opacity=".06"/>', $i, $i + $h, $h);
            }
            $p[] = implode('', $lines);
        } elseif ($pattern === 2) { // موج
            $p[] = sprintf('<path d="M0 %d Q %d %d %d %d T %d %d V %d H 0 Z" fill="#fff" opacity=".08"/>',
                $h * .72, $w * .25, $h * .55, $w * .5, $h * .72, $w, $h * .62, $h);
        } elseif ($pattern === 3) { // شش‌ضلعی‌ها
            $hexes = [];
            for ($x = 40; $x < $w; $x += 105) {
                for ($y = 45; $y < $h; $y += 96) {
                    $hexes[] = sprintf('<polygon points="%s" fill="none" stroke="#fff" stroke-width="1.6" opacity=".12"/>',
                        implode(' ', array_map(fn($a) => sprintf('%.1f,%.1f', $x + 34 * cos(deg2rad($a)), $y + 34 * sin(deg2rad($a))), [0, 60, 120, 180, 240, 300])));
                }
            }
            $p[] = implode('', $hexes);
        } else { // حلقه‌ها
            $p[] = sprintf('<circle cx="%d" cy="%d" r="210" fill="none" stroke="#fff" stroke-width="2" opacity=".14"/>', mt_rand(80, $w - 80), mt_rand(80, $h - 80));
            $p[] = sprintf('<circle cx="%d" cy="%d" r="150" fill="none" stroke="%s" stroke-width="2.4" opacity=".4"/>', mt_rand(80, $w - 80), mt_rand(80, $h - 80), $accent);
        }

        /* لکه تاکیدی */
        $p[] = sprintf('<circle cx="%d" cy="%d" r="%d" fill="%s" opacity=".3"/>', $w * .87, $h * .2, 170, $accent);

        /* پرده پایین برای خوانایی متن */
        $p[] = '<rect y="' . ($h * .48) . '" width="' . $w . '" height="' . ($h * .52) . '" fill="url(#s' . $seed . ')"/>';

        /* آیکون موضوعی */
        $p[] = sprintf('<text x="%d" y="%d" font-size="86" text-anchor="middle" opacity=".95">%s</text>', $w / 2, $h * .36, $icon);

        /* عنوان — چند خطی */
        $lines = $this->wrapPersian($title, 30, 3);
        $ty = $h * .60;
        foreach ($lines as $i => $line) {
            $p[] = sprintf('<text x="%d" y="%.0f" font-family="Vazirmatn,Vazir,Tahoma,sans-serif" font-size="%d" font-weight="800" fill="#fff" text-anchor="middle">%s</text>',
                $w / 2, $ty + $i * 54, 38, htmlspecialchars($line, ENT_QUOTES));
        }

        /* نام برند + نشان */
        $brandName = trim((string)($brand['name_fa'] ?? ''));
        $p[] = sprintf('<rect x="%d" y="%d" rx="16" width="%d" height="34" fill="%s" opacity=".92"/>',
            $w / 2 - (mb_strlen($brandName) * 8 + 26), $h - 64, mb_strlen($brandName) * 16 + 52, $accent);
        if ($brandName !== '') {
            $p[] = sprintf('<text x="%d" y="%d" font-family="Vazirmatn,Vazir,Tahoma,sans-serif" font-size="19" font-weight="700" fill="#fff" text-anchor="middle">🔧 %s</text>',
                $w / 2, $h - 40, htmlspecialchars($brandName, ENT_QUOTES));
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">' . implode('', $p) . '</svg>';
    }

    /* ==================================================
     * 🛠️ ابزارها
     * ================================================== */

    /** 🎨 پالت برند (از extra_settings یا پالت دلفریض بذری) */
    private function brandPalette(array $brand, int $seed): array
    {
        $extra = json_decode((string)($brand['extra_settings'] ?? ''), true) ?: [];
        $p = $extra['palette'] ?? [];
        if (!empty($p['primary']) && !empty($p['secondary'])) {
            return [
                'c1' => $p['primary'],
                'c2' => $p['secondary'],
                'accent' => $p['accent'] ?? '#f59e0b',
            ];
        }
        /* پالت‌های حرفه‌ای دلفریض (بذر-محور → هر مقاله رنگ متفاوت اما هماهنگ) */
        $palettes = [
            ['#0f2b5b', '#2563eb', '#f59e0b'], ['#1e3a5f', '#0ea5e9', '#22d3ee'],
            ['#312e81', '#6366f1', '#fbbf24'], ['#0f766e', '#14b8a6', '#f97316'],
            ['#7c2d12', '#ea580c', '#fbbf24'], ['#14532d', '#16a34a', '#fde047'],
            ['#4c1d95', '#8b5cf6', '#f472b6'], ['#831843', '#ec4899', '#fbbf24'],
            ['#0c4a6e', '#38bdf8', '#facc15'], ['#1f2937', '#4b5563', '#22d3ee'],
        ];
        return array_combine(['c1', 'c2', 'accent'], $palettes[abs($seed) % count($palettes)]);
    }

    /** 🔎 فونت فارسی TTF نصب‌شده روی سرور */
    private function findPersianFont(): ?string
    {
        $candidates = array_merge(
            glob(ASSETS_PATH . '/fonts/fa/vazirmatn/*.ttf') ?: [],
            glob(ASSETS_PATH . '/fonts/fa/vazir/*.ttf') ?: [],
            glob(ASSETS_PATH . '/fonts/fa/*/*regular*.ttf') ?: [],
            glob(ASSETS_PATH . '/fonts/fa/*/*.ttf') ?: []
        );
        foreach ($candidates as $f) {
            if (is_readable($f)) {
                return $f;
            }
        }
        return null;
    }

    /** 🧵 شکستن عنوان فارسی به خطوط (تقریبی بر اساس عرض نویسه) */
    private function wrapPersian(string $text, int $maxChars, int $maxLines): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return ['—'];
        }
        $words = explode(' ', $text);
        $lines = [];
        $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            if (mb_strlen($try) > $maxChars && $cur !== '') {
                $lines[] = $cur;
                $cur = $w;
                if (count($lines) >= $maxLines) { break; }
            } else {
                $cur = $try;
            }
        }
        if ($cur !== '' && count($lines) < $maxLines) {
            $lines[] = $cur;
        }
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = mb_substr($lines[$maxLines - 1], 0, $maxChars - 1) . '…';
        }
        return $lines;
    }

    private function trimTitle(string $title, int $max): string
    {
        return mb_strlen($title) > $max ? mb_substr($title, 0, $max - 1) . '…' : $title;
    }

    /** 🏷️ زیرعنوان بخش دوم (مرتبط با دستگاه/نوع مقاله) */
    private function sectionSubtitle(string $deviceKey, string $topicType, int $seed): string
    {
        $device = [
            'washing_machine' => 'ماشین لباسشویی', 'refrigerator' => 'یخچال و فریزر', 'dishwasher' => 'ماشین ظرفشویی',
            'air_conditioner' => 'کولر گازی', 'package' => 'پکیج و آبگرمکن', 'microwave' => 'مایکروویو',
            'television' => 'تلویزیون', 'oven' => 'فر و اجاق', 'water_heater' => 'آبگرمکن', 'vacuum_cleaner' => 'جاروبرقی',
        ][$deviceKey] ?? 'دستگاه خانگی';
        $aspects = ['علل رایج و راه‌حل‌ها', 'نشانه‌ها و عیب‌یابی', 'نکات نگهداری و افزایش عمر', 'هزینه تعمیر و قطعات', 'مراحل تعمیر تخصصی'];
        return $device . ' — ' . $aspects[abs($seed) % count($aspects)];
    }

    private function articleAlt(string $title, string $focusKw, int $i): string
    {
        $base = $focusKw !== '' ? $focusKw : $this->trimTitle($title, 40);
        return $base . ' — تصویر راهنمای ' . ($i === 1 ? 'کامل' : 'بخش فنی');
    }

    private function deviceEmoji(string $deviceKey): string
    {
        return [
            'washing_machine' => '🌀', 'refrigerator' => '🧊', 'dishwasher' => '🍽️',
            'air_conditioner' => '❄️', 'package' => '♨️', 'microwave' => '📻',
            'television' => '📺', 'oven' => '🔥', 'water_heater' => '🚿',
            'vacuum_cleaner' => '🧹', 'stove' => '🍳', 'hood' => '💨',
        ][$deviceKey] ?? '🛠️';
    }

    private function hexRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return [37, 99, 235];
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
