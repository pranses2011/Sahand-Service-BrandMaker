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
        /* 🎯 v2.7.2: اگر کلید دستگاه خالی/نامعتبر است، از عنوان استنتاج می‌شود —
           ریشه «آیکون/تصویر لباسشویی برای مقاله یخچال» */
        if (!ArticleImageService::normalizeDeviceKey($deviceKey)) {
            $deviceKey = (string)(ArticleImageService::inferDeviceKey($title) ?? $deviceKey);
        }
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

        /* 🥇 مسیر ۱ (v2.7.1): GD + TTF فونت پیش‌فرض سایت — دقیق‌ترین مسیر؛
           فونت همان فونت عنوان سایت‌هاست، لوگوها و واترمارک سر جای خودشان. */
        if ($font !== null && function_exists('imagettftext')) {
            $pngPath = ROOT_PATH . '/' . $fileBase . '.png';
            try {
                $ok = $this->renderOgPng($pngPath, $seed, $title, $palette, $brand, $w, $h, $font);
                if ($ok) {
                    return ['path' => $fileBase . '.png', 'url' => BASE_URL . '/' . $fileBase . '.png', 'format' => 'png'];
                }
            } catch (Throwable $e) {
                @error_log('[AiImageGenerator] PNG OG failed → fallback: ' . $e->getMessage());
            }
        }

        /* 🥈 مسیر ۲: ImageMagick — رندر SVG با فونت جاسازی‌شده (در صورت وجود) */
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

        /* 🛟 مسیر ۳: SVG — فونت پیش‌فرض به‌صورت base64 جاسازی می‌شود تا
           مرورگرها آن را دقیقاً با فونت سایت رندر کنند (v2.7.1) */
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
        $white = imagecolorallocate($img, 255, 255, 255);
        $shadow = imagecolorallocate($img, 0, 0, 0);

        /* --- 🖼 v2.8: لوگوی برند روی «نشان سفید گردگوشه» — زیبا و خوانا برای
               هر لوگویی (تیره/روشن/JPG). JPGها زمینه خود را دارند که در نشان سفید
               محو می‌شود؛ PNG/SVG شفاف دقیقاً روی نشان می‌نشینند (شفافیت حفظ می‌شود). --- */
        $brandLogo = $this->brandLogoAsset($brand);
        $brandDrawn = false;
        if ($brandLogo !== null) {
            $logoRes = $brandLogo['kind'] === 'svg' ? ImageWatermark::loadResampled($this->rasterizeSvgLogo($brandLogo['path']) ?? '', 320) : ImageWatermark::loadResampled($brandLogo['path'], 320);
            if ($logoRes !== null) {
                $lw = imagesx($logoRes);
                $lh = imagesy($logoRes);
                $scale = min(78 / $lh, 240 / $lw, 1);
                $dw = max(1, (int)round($lw * $scale));
                $dh = max(1, (int)round($lh * $scale));

                /* نشان سفید: حاشیه ۲۲px + ارتفاع ۱۲۲px + گوشه گرد ۲۴px + سایه نرم */
                $pad = 22;
                $badgeW = $dw + $pad * 2;
                $badgeH = 122;
                $logoY = (int)(($badgeH - $dh) / 2);
                $bx = (int)(($w - $badgeW) / 2);
                $by = 34;

                /* سایه نرم زیر نشان */
                imagealphablending($img, true);
                $shCanvas = imagecreatetruecolor($badgeW + 40, $badgeH + 40);
                imagealphablending($shCanvas, false);
                imagesavealpha($shCanvas, true);
                $shTr = imagecolorallocatealpha($shCanvas, 0, 0, 0, 127);
                imagefill($shCanvas, 0, 0, $shTr);
                imagealphablending($shCanvas, true);
                $shCol = imagecolorallocatealpha($shCanvas, 0, 0, 0, 58);
                imagefilledrectangle($shCanvas, 20, 22, 20 + $badgeW, 22 + $badgeH, $shCol);
                imagefilter($shCanvas, IMG_FILTER_GAUSSIAN_BLUR);
                imagefilter($shCanvas, IMG_FILTER_GAUSSIAN_BLUR);
                ImageWatermark::compositeAlpha($img, $shCanvas, $bx - 20, $by - 12);
                imagedestroy($shCanvas);

                /* خود نشان — گردگوشه با آلفا */
                $badge = imagecreatetruecolor($badgeW, $badgeH);
                imagealphablending($badge, false);
                imagesavealpha($badge, true);
                $badgeTr = imagecolorallocatealpha($badge, 0, 0, 0, 127);
                imagefill($badge, 0, 0, $badgeTr);
                $whiteOpaque = imagecolorallocate($badge, 255, 255, 255);
                $r = 24;
                imagefilledrectangle($badge, $r, 0, $badgeW - $r, $badgeH, $whiteOpaque);
                imagefilledrectangle($badge, 0, $r, $badgeW, $badgeH - $r, $whiteOpaque);
                imagefilledellipse($badge, $r, $r, $r * 2, $r * 2, $whiteOpaque);
                imagefilledellipse($badge, $badgeW - $r, $r, $r * 2, $r * 2, $whiteOpaque);
                imagefilledellipse($badge, $r, $badgeH - $r, $r * 2, $r * 2, $whiteOpaque);
                imagefilledellipse($badge, $badgeW - $r, $badgeH - $r, $r * 2, $r * 2, $whiteOpaque);
                /* لوگو با مقیاس و آلفای حفظ‌شده، سپس ترکیب قطعی روی نشان */
                $scaled = imagecreatetruecolor($dw, $dh);
                imagealphablending($scaled, false);
                imagesavealpha($scaled, true);
                $sTr = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
                imagefill($scaled, 0, 0, $sTr);
                imagecopyresampled($scaled, $logoRes, 0, 0, 0, 0, $dw, $dh, $lw, $lh);
                ImageWatermark::compositeAlpha($badge, $scaled, (int)(($badgeW - $dw) / 2), $logoY);
                imagedestroy($scaled);
                ImageWatermark::compositeAlpha($img, $badge, $bx, $by);
                imagedestroy($badge);
                imagedestroy($logoRes);
                $brandDrawn = true;
            }
        }
        if (!$brandDrawn && $font !== null) {
            /* مونوگرام دایره‌ای با حرف اول برند — جایگزین زیبا وقتی لوگو نیست
               (v2.8: دایره روی بوم جداگانه + ترکیب قطعی — مستقل از رفتار آلفای بیلد GD) */
            $brandName0 = trim((string)($brand['name_fa'] ?? ''));
            if ($brandName0 !== '') {
                $first = mb_substr($brandName0, 0, 1);
                $cx = (int)($w / 2);
                $cy = 96;
                $r = 46;
                $mono = imagecreatetruecolor($r * 2 + 8, $r * 2 + 8);
                imagealphablending($mono, false);
                imagesavealpha($mono, true);
                $mTr = imagecolorallocatealpha($mono, 0, 0, 0, 127);
                imagefill($mono, 0, 0, $mTr);
                $circ = imagecolorallocate($mono, 255, 255, 255);
                imagefilledellipse($mono, $r + 4, $r + 4, $r * 2, $r * 2, $circ);
                ImageWatermark::compositeAlpha($img, $mono, $cx - $r - 4, $cy - $r - 4, 0.24);
                imagedestroy($mono);
                imagesetthickness($img, 2);
                imagearc($img, $cx, $cy, $r * 2, $r * 2, 0, 360, $bar);
                $shapedM = PersianGlyphs::shapeForImage($first);
                $box = imagettfbbox(44, 0, $font, $shapedM);
                $tw = abs($box[4] - $box[0]);
                $th = abs($box[5] - $box[1]);
                imagettftext($img, 44, 0, (int)($cx - $tw / 2), (int)($cy + $th / 2), $white, $font, $shapedM);
            }
        }

        /* --- متن عنوان فارسی: شکل‌دهی + دو خط --- */
        $lines = $this->wrapPersian($title, 26, 2);
        $size = count($lines) > 1 ? 46 : 54;
        $lineH = (int)($size * 1.55);
        $blockH = $lineH * count($lines);
        $centerY = (int)(($h - $blockH) / 2 + $size);
        if ($brandLogo !== null) {
            $centerY += 34; // جابه‌جایی جزئی برای جای لوگو
        }
        $y = $centerY;
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

        /* --- 🪧 v2.7.2: واترمارک لوگوی نمایندگی در گوشه — شفافیت واقعی
               (رفع باگ زمینه مشکی: imagecopymerge آلفا را نادیده می‌گرفت) --- */
        $agencyLogo = $this->agencyLogoAsset();
        if ($agencyLogo !== null) {
            $wmRes = $agencyLogo['kind'] === 'svg'
                ? ImageWatermark::loadResampled($this->rasterizeSvgLogo($agencyLogo['path']) ?? '', 240)
                : ImageWatermark::loadResampled($agencyLogo['path'], 240);
            if ($wmRes !== null) {
                $ww = imagesx($img);
                $wh = imagesy($img);
                $dw = imagesx($wmRes);
                $dh = imagesy($wmRes);
                ImageWatermark::drawAlpha($img, $wmRes, 26, $wh - $dh - 26, 0.62);
                imagedestroy($wmRes);
            }
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

        /* 🆕 v2.7.1: برای OG — فونت پیش‌فرض سایت به‌صورت @font-face جاسازی می‌شود
           تا رندر (مرورگر/Imagick-rsvg) دقیقاً با فونت عنوان سایت‌ها باشد */
        $fontFamily = 'Vazirmatn,Vazir,Tahoma,sans-serif';
        $fontFace = '';
        if ($role === 'og') {
            $emb = $this->embeddableFontFile();
            if ($emb !== null && is_readable($emb['path'])) {
                $fam = 'SahandOgFont';
                $mime = $emb['format'] === 'woff2' ? 'font/woff2' : 'font/ttf';
                $fontFamily = $fam . ',Vazirmatn,Tahoma,sans-serif';
                $fontFace = '<style type="text/css">' .
                    '@font-face{font-family:\'' . $fam . '\';src:url(data:' . $mime . ';base64,' . base64_encode(file_get_contents($emb['path'])) . ') format(\'' . $emb['format'] . '\');font-weight:bold;}' .
                    '</style>';
            }
        }

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

        /* 🖼 لوگوها — v2.7.2 چیدمان بر اساس نقش:
           OG: لوگوی برند بالا-وسط + واترمارک نمایندگی پایین-چپ
           تصاویر مقاله (hero/inline): واترمارک برند پایین-راست + نمایندگی پایین-چپ */
        $brandLogo = $this->brandLogoAsset($brand);
        $isOg = ($role === 'og');
        if ($brandLogo === null) {
            if ($isOg) {
                $p[] = sprintf('<text x="%d" y="%d" font-size="86" text-anchor="middle" opacity=".95">%s</text>', $w / 2, $h * .36, $icon);
            }
        } else {
            $data = @file_get_contents($brandLogo['path']);
            if (is_string($data) && $data !== '') {
                $mime = $brandLogo['kind'] === 'svg' ? 'image/svg+xml' : 'image/' . strtolower(pathinfo($brandLogo['path'], PATHINFO_EXTENSION));
                if ($mime === 'image/jpg') { $mime = 'image/jpeg'; }
                if ($isOg) {
                    /* OG: لوگوی برند روی نشان سفید گردگوشه (v2.8 — هماهنگ با مسیر GD)
                       تا لوگوهای JPG/کم‌کنتراست هم زیبا دیده شوند */
                    $p[] = sprintf('<rect x="%d" y="30" rx="24" width="%d" height="122" fill="#ffffff" opacity=".96"/>',
                        $w / 2 - 142, 284);
                    $p[] = sprintf('<image x="%d" y="30" width="240" height="122" preserveAspectRatio="xMidYMid meet" href="data:%s;base64,%s"/>',
                        $w / 2 - 120, $mime, base64_encode($data));
                } else {
                    /* تصویر مقاله: واترمارک لوگوی برند — گوشه پایین-راست، شفاف */
                    $sz = (int)round(min($w, $h) * 0.15);
                    $p[] = sprintf('<image x="%d" y="%d" width="%d" height="%d" preserveAspectRatio="xMidYMax meet" opacity=".85" href="data:%s;base64,%s"/>',
                        $w - $sz - (int)round($w * 0.025), $h - $sz - (int)round($h * 0.035), $sz, $sz, $mime, base64_encode($data));
                }
            }
        }

        /* 🪧 واترمارک لوگوی نمایندگی — گوشه پایین-چپ، شفاف */
        $agencyLogo = $this->agencyLogoAsset();
        if ($agencyLogo !== null) {
            $aData = @file_get_contents($agencyLogo['path']);
            if (is_string($aData) && $aData !== '') {
                $aMime = $agencyLogo['kind'] === 'svg' ? 'image/svg+xml' : 'image/' . strtolower(pathinfo($agencyLogo['path'], PATHINFO_EXTENSION));
                if ($aMime === 'image/jpg') { $aMime = 'image/jpeg'; }
                $aSz = $isOg ? 104 : (int)round(min($w, $h) * 0.12);
                $p[] = sprintf('<image x="%d" y="%d" width="%d" height="%d" preserveAspectRatio="xMidYMax meet" opacity=".5" href="data:%s;base64,%s"/>',
                    (int)round($w * 0.022), $h - $aSz - (int)round($h * 0.035), $aSz, $aSz, $aMime, base64_encode($aData));
            }
        }

        /* عنوان — چند خطی */
        $lines = $this->wrapPersian($title, 30, 3);
        $ty = $h * .60;
        foreach ($lines as $i => $line) {
            $p[] = sprintf('<text x="%d" y="%.0f" font-family="%s" font-size="%d" font-weight="800" fill="#fff" text-anchor="middle">%s</text>',
                $w / 2, $ty + $i * 54, $fontFamily, 38, htmlspecialchars($line, ENT_QUOTES));
        }

        /* نام برند + نشان */
        $brandName = trim((string)($brand['name_fa'] ?? ''));
        $p[] = sprintf('<rect x="%d" y="%d" rx="16" width="%d" height="34" fill="%s" opacity=".92"/>',
            $w / 2 - (mb_strlen($brandName) * 8 + 26), $h - 64, mb_strlen($brandName) * 16 + 52, $accent);
        if ($brandName !== '') {
            $p[] = sprintf('<text x="%d" y="%d" font-family="%s" font-size="19" font-weight="700" fill="#fff" text-anchor="middle">🔧 %s</text>',
                $w / 2, $h - 40, $fontFamily, htmlspecialchars($brandName, ENT_QUOTES));
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">' . $fontFace . implode('', $p) . '</svg>';
    }

    /**
     * 📎 فایل فونت قابل جاسازی در SVG — اولویت فونت پیش‌فرض سایت (v2.7.1)
     * TTF/OTF مستقیم؛ WOFF با تبدیل؛ WOFF2 به‌صورت مستقیم (SVG از آن پشتیبانی می‌کند)
     * @return array{path:string, format:string}|null
     */
    private function embeddableFontFile(): ?array
    {
        try {
            $df = (array)(Config::get(Config::KEY_DEFAULT_FONT) ?: []);
            foreach (['heading_fa', 'body_fa'] as $k) {
                $nameOrSlug = trim((string)($df[$k] ?? ''));
                if ($nameOrSlug === '') {
                    continue;
                }
                $slug = $this->fontSlugByName($nameOrSlug);
                if ($slug === null) {
                    continue;
                }
                $dir = ASSETS_PATH . '/fonts/fa/' . $slug;
                if (!is_dir($dir)) {
                    continue;
                }
                /* ۱) TTF/OTF محلی */
                foreach (['bold', 'black', 'demibold', 'medium', 'regular'] as $wt) {
                    foreach (['ttf'] as $ext) {
                        foreach (glob($dir . '/' . $slug . '-' . $wt . '.' . $ext) ?: [] as $f) {
                            if (is_readable($f) && filesize($f) > 2048 && filesize($f) < 1200000) {
                                return ['path' => $f, 'format' => 'truetype'];
                            }
                        }
                    }
                }
                foreach (glob($dir . '/*.ttf') ?: [] as $f) {
                    if (is_readable($f) && filesize($f) > 2048 && filesize($f) < 1200000) {
                        return ['path' => $f, 'format' => 'truetype'];
                    }
                }
                /* ۲) WOFF → تبدیل به TTF (کش‌شده) */
                foreach (glob($dir . '/*.woff') ?: [] as $f) {
                    $ttf = $this->woffToTtf($f);
                    if ($ttf !== null) {
                        return ['path' => $ttf, 'format' => 'truetype'];
                    }
                }
                /* ۳) WOFF2 مستقیم — SVG/مرورگر از آن پشتیبانی می‌کنند */
                foreach (['bold', 'black', 'medium', 'regular'] as $wt) {
                    foreach (glob($dir . '/' . $slug . '-' . $wt . '.woff2') ?: [] as $f) {
                        if (is_readable($f) && filesize($f) > 2048 && filesize($f) < 900000) {
                            return ['path' => $f, 'format' => 'woff2'];
                        }
                    }
                }
                foreach (glob($dir . '/*.woff2') ?: [] as $f) {
                    if (is_readable($f) && filesize($f) > 2048 && filesize($f) < 900000) {
                        return ['path' => $f, 'format' => 'woff2'];
                    }
                }
            }
        } catch (Throwable $e) {
        }
        /* 🛡 v2.7.2: وزیرمتن بسته‌بندی‌شده — تضمین اینکه مسیر SVG هم فونت فارسی درست داشته باشد */
        foreach (glob(ASSETS_PATH . '/fonts/fa/vazirmatn/Vazirmatn-Bold.ttf') ?: [] as $f) {
            if (is_readable($f) && filesize($f) > 2048) {
                return ['path' => $f, 'format' => 'truetype'];
            }
        }
        return null;
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

    /**
     * 🔎 فونت فارسی برای رندر — اولویت مطلق: فونت پیش‌فرض عنوان سایت‌ها (v2.7.1)
     *
     * زنجیره حل (قبلاً نام فونت را مستقیم به‌عنوان نام پوشه می‌گرفت و همیشه شکست می‌خورد):
     *   نام فارسی ذخیره‌شده → نگاشت به اسلاگ (مانیفست + جدول فونت‌ها)
     *   → TTF/OTF محلی با اولویت وزن‌های سنگین
     *   → تبدیل WOFF محلی به TTF (pure-PHP + zlib، کش‌شده)
     *   → تلاش یک‌باره برای دانلود TTF هم‌مسیر (dist/... .woff2 → .ttf)
     *   → وزیرمتن و سایر فونت‌های نصب‌شده
     */
    private function findPersianFont(): ?string
    {
        $canRender = function_exists('imagettfbbox'); // 🛡 بدون FreeType، TTF قابل رندر نیست

        /* 🅰 فونت پیش‌فرض سیستم — همان فونتی که عنوان سایت‌ها استفاده می‌کند */
        $candidates = [];
        try {
            $df = (array)(Config::get(Config::KEY_DEFAULT_FONT) ?: []);
            foreach (['heading_fa', 'body_fa'] as $k) {
                $nameOrSlug = trim((string)($df[$k] ?? ''));
                if ($nameOrSlug === '') {
                    continue;
                }
                $slug = $this->fontSlugByName($nameOrSlug);
                if ($slug !== null) {
                    $ttf = $this->fontTtfForSlug($slug);
                    if ($ttf !== null) {
                        $candidates[] = $ttf;
                    }
                }
            }
        } catch (Throwable $e) {
        }

        /* 🅱 وزیرمتن بسته‌بندی‌شده در پروژه (تضمینی — v2.7.2) و بعد فونت‌های نصب‌شده سرور */
        $candidates = array_merge(
            $candidates,
            glob(ASSETS_PATH . '/fonts/fa/vazirmatn/Vazirmatn-Bold.ttf') ?: [],
            glob(ASSETS_PATH . '/fonts/fa/vazirmatn/*.ttf') ?: [],
            glob(ASSETS_PATH . '/fonts/fa/vazirmatn/*bold*.ttf') ?: [],
            glob(ASSETS_PATH . '/fonts/fa/vazir/*bold*.ttf') ?: [],
            glob(ASSETS_PATH . '/fonts/fa/vazir/*.ttf') ?: [],
            glob(ASSETS_PATH . '/fonts/fa/*/*bold*.ttf') ?: [],
            glob(ASSETS_PATH . '/fonts/fa/*/*regular*.ttf') ?: [],
            glob(ASSETS_PATH . '/fonts/fa/*/*.ttf') ?: []
        );
        foreach (array_unique($candidates) as $f) {
            if (!is_readable($f) || ($canRender && @imagettfbbox(20, 0, $f, 'آ') === false)) {
                continue;
            }
            /* 🛡 v2.7.2: فونت باید «فرم‌های نمایشی عربی» (اتصال حروف) را داشته باشد —
               وگرنه متن شکل‌یافته به مربع‌های خالی تبدیل می‌شود (فونت خراب!) */
            if ($canRender && !$this->fontCoversPersianForms($f)) {
                continue;
            }
            return $f;
        }
        return null;
    }

    /**
     * 🛡 آیا فونت «همه» فرم‌های نمایشی فارسی/عربی لازم را دارد؟ (v2.8 — دقیق‌شده)
     *
     * قبلاً فقط «تعداد» پوشش بازه U+FB50–U+FEFF شمرده می‌شد (سقف ≥۶۰) — فونت‌های
     * «عربیِ بدون حروف فارسی» (بدون پ چ ژ ک گ ی) هم ۱۴۰+ فرم پایه دارند و از این
     * فیلتر رد می‌شدند؛ نتیجه: مربع/مستطیل به‌جای دقیقاً حروف فارسی!
     *
     * حالا فهرست «دقیق» کدپوینت‌های تولیدی PersianGlyphs بررسی می‌شود؛ نبود حتی
     * یکی = رد فونت (وزیرمتن بسته‌بندی‌شده جانشین می‌شود).
     */
    private function fontCoversPersianForms(string $ttfPath): bool
    {
        static $cache = [];
        $key = $ttfPath . '|' . @filemtime($ttfPath);
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $required = PersianGlyphs::requiredCodepoints();
        $data = @file_get_contents($ttfPath);
        if (!is_string($data) || strlen($data) < 64) {
            return $cache[$key] = false;
        }
        $magic = substr($data, 0, 4);
        if ($magic === 'ttcf' || $magic === "\x00\x01\x00\x00" || $magic === 'true' || $magic === 'OTTO') {
            // فرمت‌های پشتیبانی‌شده SFNT
        } elseif (substr($magic, 0, 2) === 'wO') {
            return $cache[$key] = false; // WOFF خام — نباید اینجا باشد
        } else {
            return $cache[$key] = false;
        }
        /* یافتن جدول cmap */
        $numTables = unpack('n', substr($data, 4, 2))[1] ?? 0;
        if ($numTables < 1 || $numTables > 128) {
            return $cache[$key] = false;
        }
        $cmapOff = 0;
        $cmapLen = 0;
        for ($i = 0; $i < $numTables; $i++) {
            $rec = substr($data, 12 + $i * 16, 16);
            if (strlen($rec) < 16) {
                return $cache[$key] = false;
            }
            if (substr($rec, 0, 4) === 'cmap') {
                $cmapOff = unpack('N', substr($rec, 8, 4))[1];
                $cmapLen = unpack('N', substr($rec, 12, 4))[1];
                break;
            }
        }
        if ($cmapOff <= 0 || $cmapLen < 4 || $cmapOff + $cmapLen > strlen($data)) {
            return $cache[$key] = false;
        }
        /* خواندن زیرجدول‌ها — بهترین: format 4 (BMP) یا 12 (UCS-4) */
        $subtablesCount = unpack('n', substr($data, $cmapOff + 2, 2))[1] ?? 0;
        $best = null; // [offset, format]
        for ($i = 0; $i < $subtablesCount && $i < 32; $i++) {
            $rec = substr($data, $cmapOff + 4 + $i * 8, 8);
            if (strlen($rec) < 8) {
                break;
            }
            $platform = unpack('n', substr($rec, 0, 2))[1];
            $encoding = unpack('n', substr($rec, 2, 2))[1];
            $off = unpack('N', substr($rec, 4, 4))[1];
            if ($off <= 0 || $cmapOff + $off + 2 > strlen($data)) {
                continue;
            }
            $format = unpack('n', substr($data, $cmapOff + $off, 2))[1] ?? 0;
            if ($platform === 3 && $encoding === 10) { // UCS-4
                $best = [$cmapOff + $off, $format];
                break;
            }
            if ($platform === 3 && $encoding === 1 && $best === null) { // BMP
                $best = [$cmapOff + $off, $format];
            }
            if ($platform === 0 && $best === null) {
                $best = [$cmapOff + $off, $format];
            }
        }
        if ($best === null) {
            return $cache[$key] = false;
        }
        [$subOff, $format] = $best;

        /* مجموعه کدپوینت‌های مپ‌شده فونت (فقط بازه‌های مرتبط برای سرعت) */
        $mapped = [];
        if ($format === 4) {
            $segCount = unpack('n', substr($data, $subOff + 6, 2))[1] / 2;
            $endCodesStart = $subOff + 14;
            for ($s = 0; $s < $segCount; $s++) {
                $end = unpack('n', substr($data, $endCodesStart + $s * 2, 2))[1] ?? 0;
                $start = unpack('n', substr($data, $endCodesStart + $segCount * 2 + 2 + $s * 2, 2))[1] ?? 0;
                if ($end === 0xFFFF || $start > $end) {
                    continue;
                }
                $lo = max($start, 0x0600); // از بلوک عربی/فارسی تا فرم‌های نمایشی
                $hi = min($end, 0xFEFF);
                for ($cp = $lo; $cp <= $hi; $cp++) {
                    $mapped[$cp] = true;
                }
            }
        } elseif ($format === 12) {
            $nGroups = unpack('N', substr($data, $subOff + 12, 4))[1] ?? 0;
            for ($g = 0; $g < $nGroups && $g < 4096; $g++) {
                $rec = substr($data, $subOff + 16 + $g * 12, 12);
                if (strlen($rec) < 12) {
                    break;
                }
                $start = unpack('N', substr($rec, 0, 4))[1];
                $end = unpack('N', substr($rec, 4, 4))[1];
                $lo = max($start, 0x0600);
                $hi = min($end, 0xFEFF);
                for ($cp = $lo; $cp <= $hi; $cp++) {
                    $mapped[$cp] = true;
                }
            }
        } else {
            return $cache[$key] = false;
        }

        /* ✅ همه فرم‌های لازم باید موجود باشند — نبود حتی یکی = مستطیل خالی */
        foreach ($required as $cp) {
            if (empty($mapped[$cp])) {
                return $cache[$key] = false;
            }
        }
        /* ZWNJ هم برای شکستن اتصال لازم است (خروجی آن حذف می‌شود اما فونت سالم باشد) */
        return $cache[$key] = true;
    }

    /**
     * 🔎 نگاشت نام فارسی فونت → اسلاگ پوشه (v2.7.1)
     * default_font_settings نام فونت را ذخیره می‌کند («ایران‌سنس») نه اسلاگ را —
     * این متد از مانیفست و جدول فونت‌ها اسلاگ را پیدا می‌کند.
     */
    private function fontSlugByName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        /* خودش اسلاگ معتبر پوشه است؟ */
        if (preg_match('/^[a-z0-9\-]+$/i', $name) && is_dir(ASSETS_PATH . '/fonts/fa/' . $name)) {
            return $name;
        }
        $norm = static function (string $s): string {
            return str_replace(["\u{200c}", ' ', '‌'], '', trim($s)); // حذف نیم‌فاصله/فاصله
        };
        $target = $norm($name);
        if ($target === '') {
            return null;
        }
        /* ۱) مانیفست فونت‌های داخلی */
        $manifest = json_decode((string)@file_get_contents(ASSETS_PATH . '/fonts/manifest.json'), true);
        foreach ((array)(($manifest['fonts']['fa'] ?? [])) as $f) {
            if (($f['slug'] ?? '') === $name || $norm((string)($f['name'] ?? '')) === $target) {
                return (string)$f['slug'];
            }
        }
        /* ۲) جدول فونت‌های آپلودی دیتابیس */
        try {
            $slug = Database::getInstance()->fetchValue(
                "SELECT slug FROM fonts WHERE (name = ? OR slug = ?) AND type = 'fa' LIMIT 1",
                [$name, $name]
            );
            if (is_string($slug) && $slug !== '') {
                return $slug;
            }
        } catch (Throwable $e) {
        }
        /* ۳) تطبیق نرم با نام پوشه‌ها (ایران‌سنس ↔ iransans) */
        foreach (glob(ASSETS_PATH . '/fonts/fa/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $slug = basename($dir);
            if ($norm($slug) === $target || stripos($norm($slug), $target) === 0 || stripos($target, $norm($slug)) === 0) {
                return $slug;
            }
        }
        return null;
    }

    /**
     * 📂 یافتن/آماده‌سازی TTF فونت با اسلاگ — اولویت وزن، تبدیل WOFF، دانلود هم‌مسیر (v2.7.1)
     */
    private function fontTtfForSlug(string $slug): ?string
    {
        if (!preg_match('/^[a-z0-9\-]+$/i', $slug)) {
            return null;
        }
        $dir = ASSETS_PATH . '/fonts/fa/' . $slug;
        if (!is_dir($dir)) {
            return null;
        }
        /* ۱) TTF موجود با اولویت وزن‌های سنگین (عنوان) */
        $found = [];
        foreach (['bold', 'black', 'extrablack', 'extrabold', 'demibold', 'semibold', 'heavy', 'medium', 'regular', ''] as $w) {
            $pattern = $w === '' ? $dir . '/*.ttf' : $dir . '/' . $slug . '-' . $w . '.ttf';
            $found = array_merge($found, glob($pattern) ?: []);
        }
        $found = array_values(array_unique($found));
        if ($found) {
            return $found[0];
        }
        foreach (glob($dir . '/*.otf') ?: [] as $otf) {
            return $otf;
        }
        /* ۲) تبدیل WOFF محلی → TTF (کش در uploads/cache/fonts) */
        $woffs = array_merge(
            glob($dir . '/' . $slug . '-bold.woff') ?: [],
            glob($dir . '/' . $slug . '-*.woff') ?: [],
            glob($dir . '/*.woff') ?: []
        );
        foreach ($woffs as $woff) {
            $ttf = $this->woffToTtf($woff);
            if ($ttf !== null) {
                return $ttf;
            }
        }
        /* ۳) تلاش دانلود TTF هم‌مسیر (dist/X.woff2 → dist/X.ttf) — یک‌بار، با کش منفی */
        $webExt = array_merge(
            glob($dir . '/' . $slug . '-bold.woff2') ?: [],
            glob($dir . '/' . $slug . '-*.woff2') ?: []
        );
        foreach ($webExt as $i => $w2) {
            if ($i >= 1) { break; } // فقط ۱ تلاش شبکه‌ای (کش منفی دارد)
            $ttf = $this->fetchSiblingTtf($w2);
            if ($ttf !== null) {
                return $ttf;
            }
        }
        return null;
    }

    /**
     * 🔄 تبدیل WOFF → TTF با PHP خالص (zlib) — خروجی در کش (v2.7.1)
     * ساختار WOFF: هدر ۴۴ بایتی + دایرکتوری جدول‌ها (هر رکورد ۲۰ بایت)
     */
    private function woffToTtf(string $woffPath): ?string
    {
        $cacheDir = ROOT_PATH . '/uploads/cache/fonts';
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true)) {
            return null;
        }
        $md5 = is_readable($woffPath) ? md5_file($woffPath) : '';
        if ($md5 === '') {
            return null;
        }
        $out = $cacheDir . '/w2t-' . $md5 . '.ttf';
        if (is_file($out) && filesize($out) > 2048) {
            return $out;
        }
        $data = @file_get_contents($woffPath);
        if (!is_string($data) || strlen($data) < 64 || substr($data, 0, 4) !== 'wOFF') {
            return null;
        }
        $hdr = @unpack('Vflavor/Vlength/vnumTables/vreserved/VtotalSfnt/vmajor/vminor', substr($data, 4, 20));
        if (!$hdr || $hdr['numTables'] < 1 || $hdr['numTables'] > 80) {
            return null;
        }
        $numTables = (int)$hdr['numTables'];
        $tables = [];
        for ($i = 0; $i < $numTables; $i++) {
            $rec = substr($data, 44 + $i * 20, 20);
            if (strlen($rec) < 20) {
                return null;
            }
            $tag = substr($rec, 0, 4);
            $f = @unpack('Voffset/VcompLen/VorigLen/Vchecksum', substr($rec, 4, 16));
            if (!$f || $f['compLen'] < 1 || $f['offset'] + $f['compLen'] > strlen($data)) {
                return null;
            }
            $raw = substr($data, $f['offset'], $f['compLen']);
            if ($f['compLen'] < $f['origLen']) {
                $inflated = @gzuncompress($raw, (int)$f['origLen']);
                if ($inflated === false) {
                    $inflated = @gzinflate($raw, (int)$f['origLen']);
                }
                if (!is_string($inflated) || strlen($inflated) !== (int)$f['origLen']) {
                    return null;
                }
                $raw = $inflated;
            }
            $tables[] = ['tag' => $tag, 'data' => $raw, 'checksum' => (int)$f['checksum'], 'origLen' => (int)$f['origLen']];
        }
        /* ساخت SFNT (ترتیب رکوردها باید بر اساس تگ باشد — الزام قالب) */
        usort($tables, static fn($a, $b) => strcmp($a['tag'], $b['tag']));
        $searchRange = 16;
        $entrySelector = 0;
        while ($searchRange * 2 <= $numTables * 16) {
            $searchRange *= 2;
            $entrySelector++;
        }
        $rangeShift = $numTables * 16 - $searchRange;
        $offset = 12 + 16 * $numTables;
        foreach ($tables as &$t) {
            $t['offset'] = $offset;
            $t['pad'] = (4 - (strlen($t['data']) % 4)) % 4;
            $offset += strlen($t['data']) + $t['pad'];
        }
        unset($t);
        $out2 = pack('Nnnnn', 0x00010000, $numTables, $searchRange, $entrySelector, $rangeShift);
        foreach ($tables as $t) {
            $out2 .= $t['tag'] . pack('NNN', $t['checksum'], $t['offset'], $t['origLen']);
        }
        foreach ($tables as $t) {
            $out2 .= $t['data'] . str_repeat("\0", $t['pad']);
        }
        if (strlen($out2) < 2048) {
            return null;
        }
        if (@file_put_contents($out, $out2) === false) {
            return null;
        }
        return $out;
    }

    /**
     * ⬇️ تلاش برای دانلود نسخه TTF هم‌مسیر فایل وب‌فونت (v2.7.1)
     * منبع‌های رستیکردار معمولاً ttf/woff/woff2 را کنار هم دارند؛
     * تلاش‌های ناموفق کش منفی می‌شوند تا تکرار نشوند.
     */
    private function fetchSiblingTtf(string $webfontPath): ?string
    {
        $cacheDir = ROOT_PATH . '/uploads/cache/fonts';
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true)) {
            return null;
        }
        $fail = $cacheDir . '/sibfail-' . md5($webfontPath) . '.txt';
        if (is_file($fail)) {
            return null;
        }
        /* آدرس منبع از نام فایل ساخته نمی‌شود — فقط برای مسیرهای شناخته‌شده CDN */
        $candidatesUrls = [];
        $slug = basename(dirname($webfontPath));
        $weight = preg_replace('/^' . preg_quote($slug, '/') . '[-_]|\.(woff2?|ttf)$/i', '', basename($webfontPath));
        foreach (['https://cdn.jsdelivr.net/gh/rastikerdar/', 'https://raw.githubusercontent.com/rastikerdar/'] as $cdn) {
            $name = str_replace(['-', '_'], '', $slug);
            $name = ucfirst($name);
            foreach (["{$name}-Bold.ttf", "{$name}-Regular.ttf", "{$name}.ttf"] as $file) {
                $candidatesUrls[] = $cdn . $slug . '-font@latest/dist/' . $file;
                $candidatesUrls[] = $cdn . $slug . '@latest/fonts/ttf/' . $file;
            }
        }
        foreach (array_slice($candidatesUrls, 0, 3) as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 7,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($body) && strlen($body) > 4096 && $http === 200 && substr($body, 0, 4) === "\x00\x01\x00\x00") {
                $out = $cacheDir . '/sib-' . md5($url) . '.ttf';
                if (@file_put_contents($out, $body) !== false) {
                    return $out;
                }
            }
        }
        @file_put_contents($fail, date('c'));
        return null;
    }

    /**
     * 🖼 مسیر لوگوی برند قابل استفاده در تصویر OG (v2.7)
     * خروجی: ['path' => مطلق, 'kind' => 'raster'|'svg'] یا null
     */
    private function brandLogoAsset(array $brand): ?array
    {
        $rel = trim((string)($brand['logo'] ?? ''));
        if ($rel === '') {
            return null;
        }
        $abs = ROOT_PATH . '/' . ltrim($rel, '/');
        if (!is_file($abs) || !is_readable($abs) || filesize($abs) > 3 * 1024 * 1024) {
            return null;
        }
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        if ($ext === 'svg') {
            return ['path' => $abs, 'kind' => 'svg'];
        }
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
            return ['path' => $abs, 'kind' => 'raster'];
        }
        return null;
    }

    /**
     * 🪧 لوگوی نمایندگی برای واترمارک گوشه تصویر (v2.7)
     */
    private function agencyLogoAsset(): ?array
    {
        try {
            $rel = trim((string)(Config::get(Config::KEY_AGENCY_LOGO) ?: ''));
        } catch (Throwable $e) {
            $rel = '';
        }
        if ($rel === '') {
            return null;
        }
        $abs = ROOT_PATH . '/' . ltrim($rel, '/');
        if (!is_file($abs) || !is_readable($abs) || filesize($abs) > 3 * 1024 * 1024) {
            return null;
        }
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        if ($ext === 'svg') {
            return ['path' => $abs, 'kind' => 'svg'];
        }
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
            return ['path' => $abs, 'kind' => 'raster'];
        }
        return null;
    }

    /**
     * 🖼 رستر کردن لوگوی SVG به PNG با ImageMagick (v2.7.1)
     * برای مسیر GD که SVG را مستقیم نمی‌خواند — خروجی کش می‌شود.
     * @return string|null مسیر PNG یا null (اگر Imagick نبود/شکست خورد)
     */
    private function rasterizeSvgLogo(string $svgPath, int $maxPx = 320): ?string
    {
        if (!class_exists('Imagick')) {
            return null;
        }
        $cacheDir = ROOT_PATH . '/uploads/cache/logos';
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true)) {
            return null;
        }
        $out = $cacheDir . '/svg-' . md5($svgPath . '|' . filemtime($svgPath)) . '.png';
        if (is_file($out) && filesize($out) > 100) {
            return $out;
        }
        try {
            $im = new Imagick();
            $im->setBackgroundColor(new ImagickPixel('transparent'));
            $im->readImageBlob(file_get_contents($svgPath));
            $im->setImageFormat('png');
            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            if ($w > 0 && $h > 0) {
                $scale = min($maxPx / $w, $maxPx / $h, 2);
                $im->resizeImage((int)round($w * $scale), (int)round($h * $scale), Imagick::FILTER_LANCZOS, 1);
            }
            if (@file_put_contents($out, $im->getImageBlob()) === false) {
                $im->clear();
                return null;
            }
            $im->clear();
            return $out;
        } catch (Throwable $e) {
            return null;
        }
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
