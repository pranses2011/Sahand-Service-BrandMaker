<?php
/**
 * 🖼️ کارت تصویری درخواست — تصویر + واترمارک لوگوها + متن کامل فارسی
 * ================================================================
 * 🆕 v2.31 — درخواست کاربر: «در ارسال درخواست به ایمیل و تلگرام و بله،
 * لوگوی برند و لوگوی نمایندگی رو بصورت واترمارک در گوشه های پایین تصویر
 * ارسالی بزاره و فیلدهای متنی درخواست رو بطور کامل زیرش بنویسه.
 * تصویر و متن در قالب یک پیام باشن نه جداگانه.»
 *
 * خروجی: یک JPEG واحد —
 *   ┌────────────────────────────┐
 *   │   تصویر ارسالی مشتری        │
 *   │ [لوگوی برند]      [لوگوی نمایندگی] ← واترمارک گوشه‌های پایین
 *   ├────────────────────────────┤
 *   │  📨 درخواست خدمات جدید      │
 *   │  نام: ...    تماس: ...      │
 *   │  ... (همه فیلدها)           │
 *   │  برند | نمایندگی | تاریخ    │
 *   └────────────────────────────┘
 *
 * @package SahandBrandMaker
 */
class RequestCard
{
    /** عرض کارت (پیکسل) */
    private const W = 900;

    /** @var string|null مسیر فونت بولد */
    private static $fontBold = null;
    /** @var string|null مسیر فونت معمولی */
    private static $fontRegular = null;

    private static function fonts(): void
    {
        if (self::$fontBold === null) {
            $base = ROOT_PATH . '/assets/fonts/fa/vazirmatn/';
            self::$fontBold = is_file($base . 'Vazirmatn-Bold.ttf') ? $base . 'Vazirmatn-Bold.ttf' : $base . 'Vazirmatn-Regular.ttf';
            self::$fontRegular = is_file($base . 'Vazirmatn-Regular.ttf') ? $base . 'Vazirmatn-Regular.ttf' : self::$fontBold;
        }
    }

    /* ═══════════════════════════════════════════════════════════
     * 🔤 شکل‌دهی متن فارسی برای GD (Presentation Forms)
     * imagettftext حروف را جدا و چپ‌به‌راست می‌کشد — این تابع:
     *  ① هر حرف را به فرم اتصالی صحیح (آغازین/میانی/پایانی/مجزا) می‌برد
     *  ② ترتیب بصری RTL می‌سازد (توکن‌ها معکوس، ارقام LTR می‌مانند)
     * ═══════════════════════════════════════════════════════════ */

    /** جدول حروف: [مجزا, پایانی, آغازین, میانی] — null = فقط راست‌چسب */
    private const GLYPHS = [
        'ا' => ['FE8D', 'FE8E', null, null],
        'أ' => ['FE83', 'FE84', null, null],
        'إ' => ['FE87', 'FE88', null, null],
        'آ' => ['FE81', 'FE82', null, null],
        'ب' => ['FE8F', 'FE90', 'FE91', 'FE92'],
        'پ' => ['FB56', 'FB57', 'FB58', 'FB59'],
        'ت' => ['FE95', 'FE96', 'FE97', 'FE98'],
        'ث' => ['FE99', 'FE9A', 'FE9B', 'FE9C'],
        'ج' => ['FE9D', 'FE9E', 'FE9F', 'FEA0'],
        'چ' => ['FB7A', 'FB7B', 'FB7C', 'FB7D'],
        'ح' => ['FEA1', 'FEA2', 'FEA3', 'FEA4'],
        'خ' => ['FEA5', 'FEA6', 'FEA7', 'FEA8'],
        'د' => ['FEA9', 'FEAA', null, null],
        'ذ' => ['FEAB', 'FEAC', null, null],
        'ر' => ['FEAD', 'FEAE', null, null],
        'ز' => ['FEAF', 'FEB0', null, null],
        'ژ' => ['FB8A', 'FB8B', null, null],
        'س' => ['FEB1', 'FEB2', 'FEB3', 'FEB4'],
        'ش' => ['FEB5', 'FEB6', 'FEB7', 'FEB8'],
        'ص' => ['FEB9', 'FEBA', 'FEBB', 'FEBC'],
        'ض' => ['FEBD', 'FEBE', 'FEBF', 'FEC0'],
        'ط' => ['FEC1', 'FEC2', 'FEC3', 'FEC4'],
        'ظ' => ['FEC5', 'FEC6', 'FEC7', 'FEC8'],
        'ع' => ['FEC9', 'FECA', 'FECB', 'FECC'],
        'غ' => ['FECD', 'FECE', 'FECF', 'FED0'],
        'ف' => ['FED1', 'FED2', 'FED3', 'FED4'],
        'ق' => ['FED5', 'FED6', 'FED7', 'FED8'],
        'ک' => ['FB8E', 'FB8F', 'FB90', 'FB91'],
        'ك' => ['FED5', 'FED6', 'FED7', 'FED8'],
        'گ' => ['FB92', 'FB93', 'FB94', 'FB95'],
        'ل' => ['FEDD', 'FEDE', 'FEDF', 'FEE0'],
        'م' => ['FEE1', 'FEE2', 'FEE3', 'FEE4'],
        'ن' => ['FEE5', 'FEE6', 'FEE7', 'FEE8'],
        'و' => ['FEED', 'FEEE', null, null],
        'ؤ' => ['FE85', 'FE86', null, null],
        'ه' => ['FEE9', 'FEEA', 'FEEB', 'FEEC'],
        'ة' => ['FE93', 'FE94', null, null],
        'ی' => ['FBFC', 'FBFD', 'FBFE', 'FBFF'],
        'ي' => ['FEF1', 'FEF2', 'FEF3', 'FEF4'],
        'ئ' => ['FE89', 'FE8A', 'FE8B', 'FE8C'],
        'ء' => ['FE80', null, null, null],
    ];

    /** آیا کاراکتر حرف عربی/فارسی متصل‌شونده است؟ */
    private static function isArabicLetter(string $ch): bool
    {
        return isset(self::GLYPHS[$ch]);
    }

    /** آیا به حرف قبلی می‌چسبد (فرم پایانی دارد)؟ */
    private static function canJoinPrev(string $ch): bool
    {
        return isset(self::GLYPHS[$ch]) && self::GLYPHS[$ch][1] !== null;
    }

    /** آیا به حرف بعدی می‌چسبد (فرم آغازین دارد — دوجانبه)؟ */
    private static function canJoinNext(string $ch): bool
    {
        return isset(self::GLYPHS[$ch]) && self::GLYPHS[$ch][2] !== null;
    }

    /**
     * 🔤 تبدیل متن فارسی به فرم‌های نمایشی + ترتیب بصری RTL
     */
    public static function shape(string $text): string
    {
        if ($text === '') { return ''; }
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($chars);

        /* ① انتخاب فرم اتصالی هر حرف */
        $shaped = [];
        for ($i = 0; $i < $n; $i++) {
            $ch = $chars[$i];
            if (!self::isArabicLetter($ch)) {
                $shaped[] = $ch;
                continue;
            }
            $prev = $i > 0 ? $chars[$i - 1] : '';
            $next = $i < $n - 1 ? $chars[$i + 1] : '';
            $joinPrev = $prev !== '' && self::canJoinNext($prev);
            $joinNext = $next !== '' && self::canJoinPrev($next);
            $g = self::GLYPHS[$ch];
            if ($joinPrev && $joinNext && $g[3] !== null) { $shaped[] = self::chrHex($g[3]); }
            elseif ($joinPrev && $g[1] !== null) { $shaped[] = self::chrHex($g[1]); }
            elseif ($joinNext && $g[2] !== null) { $shaped[] = self::chrHex($g[2]); }
            else { $shaped[] = self::chrHex($g[0]); }
        }

        /* ② توکن‌بندی: رشته‌های حروف عربی ↔ رشته‌های LTR (ارقام/لاتین/نمادها) */
        $tokens = [];
        $cur = '';
        $curArabic = null;
        foreach ($shaped as $ch) {
            /* حروف نمایشی U+FE70–U+FEFF و U+FB50–U+FDFF عربی‌اند */
            $isAr = preg_match('/^[\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]$/u', $ch) === 1;
            if ($curArabic === null || $isAr === $curArabic) {
                $cur .= $ch;
                $curArabic = $isAr;
            } else {
                $tokens[] = [$curArabic, $cur];
                $cur = $ch;
                $curArabic = $isAr;
            }
        }
        if ($cur !== '') { $tokens[] = [$curArabic, $cur]; }

        /* ③ چیدمان بصری: ترتیب توکن‌ها معکوس؛ حروف عربی داخل توکن هم معکوس */
        $visual = '';
        for ($t = count($tokens) - 1; $t >= 0; $t--) {
            [$isAr, $str] = $tokens[$t];
            if ($isAr) {
                $rev = array_reverse(preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY) ?: []);
                $visual .= implode('', $rev);
            } else {
                $visual .= $str;
            }
        }
        return $visual;
    }

    private static function chrHex(string $hex): string
    {
        return self::mbChr(hexdec($hex));
    }

    private static function mbChr(int $cp): string
    {
        return mb_decode_numericentity('&#' . $cp . ';', [0, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
    }

    /* ═══════════════════════════════════════════════════════════
     * 🏷️ واترمارک تصاویر درخواست — v2.41 (درخواست کاربر)
     * ==========================================================
     * «تصاویر به هر تعدادی که باشند برای همه آنها واترمارک لوگوی
     *  برند و لوگوی نمایندگی رو بزار که در گوشه های پایین تصویر و با
     *  زمینه شفاف باشند. بجز واترمارک ها چیز دیگری روی تصاویر نزار.
     *  طول و عرض تصاویر رو تغییر اندازه نده.»
     *
     * ① ابعاد تصویر اصلی هرگز تغییر نمی‌کند (بدون برش/کوچک‌سازی)
     * ② فقط دو واترمارک در گوشه‌های پایین: برند (چپ) + نمایندگی (راست)
     * ③ زمینه واترمارک «شفاف» است: کپسول شیشه‌ای با ماتی ~۳۸٪ —
     *    تصویر کاملاً از پشتش پیدا است (نه چیپ سفید مات قدیمی)
     * ④ هیچ متن/سایه/نوار دیگری روی تصویر قرار نمی‌گیرد — متن کامل
     *    درخواست به‌صورت کپشن همان پیام ارسال می‌شود (زیر تصویر)
     * ⑤ خروجی در همان فرمت ورودی (PNG→PNG / JPEG→JPEG / WebP→WebP)
     * ═══════════════════════════════════════════════════════════ */

    /**
     * 🏷️ اعمال واترمارک دو لوگو روی یک تصویر — بدون تغییر ابعاد
     *
     * @param string $imgPath        مسیر تصویر (نسبی به ROOT_PATH یا مطلق)
     * @param array  $brand          برند [logo => مسیر]
     * @param string $agencyLogoPath مسیر لوگوی نمایندگی
     * @return string|null مسیر فایل واترمارک‌شده یا null در خطا
     */
    public static function watermark(string $imgPath, array $brand, string $agencyLogoPath = ''): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }
        try {
            $abs = (strpos($imgPath, '/') === 0) ? $imgPath : ROOT_PATH . '/' . ltrim($imgPath, '/');
            if (!is_file($abs)) {
                return null;
            }
            $info = @getimagesize($abs);
            if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                return null;
            }
            $src = self::loadImage($abs);
            if ($src === null) {
                return null;
            }
            $w = imagesx($src);
            $h = imagesy($src);

            /* ① بوم خروجی با همان ابعاد — تصویر کامل، بدون هیچ تغییری */
            $out = imagecreatetruecolor($w, $h);
            imagealphablending($out, false);
            imagesavealpha($out, true);
            $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
            imagefill($out, 0, 0, $transparent);
            imagealphablending($out, true);
            imagecopy($out, $src, 0, 0, 0, 0, $w, $h);
            imagedestroy($src);

            /* ② اندازه واترمارک متناسب با ابعاد واقعی تصویر (۸٪ ارتفاع، سقف ۹۰px) */
            $wmH = (int)max(26, min(90, round($h * 0.08)));
            $margin = (int)max(7, round(min($w, $h) * 0.028));

            /* ③ دو گوشه پایین: برند — پایین چپ | نمایندگی — پایین راست */
            if (!empty($brand['logo'])) {
                self::stampWatermark($out, (string)$brand['logo'], $margin, $h - $margin, 'left', $wmH);
            }
            if ($agencyLogoPath !== '') {
                self::stampWatermark($out, $agencyLogoPath, $w - $margin, $h - $margin, 'right', $wmH);
            }

            /* ④ ذخیره در همان فرمت ورودی */
            $dir = ROOT_PATH . '/uploads/requests/watermarked';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $ext = $info[2] === IMAGETYPE_PNG ? 'png' : ($info[2] === IMAGETYPE_WEBP ? 'webp' : 'jpg');
            $outPath = $dir . '/wm_' . uniqid('req_') . '.' . $ext;
            $ok = false;
            if ($info[2] === IMAGETYPE_PNG) {
                $ok = imagepng($out, $outPath, 6);
            } elseif ($info[2] === IMAGETYPE_WEBP && function_exists('imagewebp')) {
                $ok = imagewebp($out, $outPath, 90);
            } else {
                $ok = imagejpeg($out, $outPath, 92);
            }
            imagedestroy($out);
            return $ok ? $outPath : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * 🏷️ مهر واترمارک — کپسول شیشه‌ای شفاف + لوگو، بدون هیچ پس‌زمینه مات
     * $anchorX/$anchorY: لنگر گوشه (چپ یا راست، پایین) | $targetH: ارتفاع لوگو
     */
    private static function stampWatermark($canvas, string $logoPath, int $anchorX, int $anchorY, string $side, int $targetH): void
    {
        $abs = (strpos($logoPath, '/') === 0) ? $logoPath : ROOT_PATH . '/' . ltrim($logoPath, '/');
        if (!is_file($abs)) {
            return;
        }
        $logo = self::loadImage($abs);
        if ($logo === null) {
            return;
        }
        $lw = imagesx($logo);
        $lh = imagesy($logo);
        /* نسبت لوگو حفظ می‌شود؛ سقف عرض ۳ برابر ارتفاع (لوگوی افقی خیلی کشیده) */
        $scale = min($targetH / max(1, $lh), ($targetH * 3) / max(1, $lw));
        $dw = (int)max(12, round($lw * $scale));
        $dh = (int)max(10, round($lh * $scale));
        /* کپسول: لوگو + ۳۰٪ آستانه امن دو طرف + ۲۲٪ بالا/پایین */
        $padX = (int)max(5, round($dw * 0.15));
        $padY = (int)max(4, round($dh * 0.22));
        $chipW = min($dw + $padX * 2, 340);
        $chipH = $dh + $padY * 2;
        $chipR = (int)max(8, round($chipH / 2));

        /* کپسول شیشه‌ای — زمینه شفاف (ماتی ~۳۸٪): تصویر از پشت پیدا است */
        $chip = imagecreatetruecolor($chipW, $chipH);
        imagealphablending($chip, false);
        imagesavealpha($chip, true);
        $tr = imagecolorallocatealpha($chip, 0, 0, 0, 127);
        imagefill($chip, 0, 0, $tr);
        imagealphablending($chip, true);
        $glass = imagecolorallocatealpha($chip, 255, 255, 255, 79); /* 127−79 → ~۳۸٪ مات */
        self::imageRoundedRect($chip, 0, 0, $chipW - 1, $chipH - 1, $chipR, $glass);
        /* حاشیه خیلی ملایم شیشه */
        $rim = imagecolorallocatealpha($chip, 255, 255, 255, 96);
        self::roundedRectOutline($chip, 0, 0, $chipW - 1, $chipH - 1, $chipR, $rim);
        /* لوگو داخل کپسول (آلفای خودش حفظ می‌شود) */
        imagecopyresampled($chip, $logo, (int)(($chipW - $dw) / 2), (int)(($chipH - $dh) / 2), 0, 0, $dw, $dh, $lw, $lh);
        imagedestroy($logo);

        /* جایگذاری — لنگر = گوشه پایین سمت مربوطه */
        $x = $side === 'left' ? $anchorX : $anchorX - $chipW;
        $y = $anchorY - $chipH;
        imagecopyresampled($canvas, $chip, $x, $y, 0, 0, $chipW, $chipH, $chipW, $chipH);
        imagedestroy($chip);
    }

    /** مستطیل گرد خط‌دار (outline) — حاشیه کپسول شیشه‌ای */
    private static function roundedRectOutline($img, int $x1, int $y1, int $x2, int $y2, int $r, $color): void
    {
        imagerectangle($img, $x1 + $r, $y1, $x2 - $r, $y1, $color);
        imagerectangle($img, $x1 + $r, $y2, $x2 - $r, $y2, $color);
        imagerectangle($img, $x1, $y1 + $r, $x1, $y2 - $r, $color);
        imagerectangle($img, $x2, $y1 + $r, $x2, $y2 - $r, $color);
        imagearc($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, 180, 270, $color);
        imagearc($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, 270, 360, $color);
        imagearc($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, 90, 180, $color);
        imagearc($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, 0, 90, $color);
    }

    /* ═══════════════════════════════════════════════════════════
     * 🏗 ساخت کارت
     * ═══════════════════════════════════════════════════════════ */

    /**
     * 🖼 ساخت کارت تصویری درخواست
     *
     * @param array $request داده‌های درخواست (فیلدهای فرم)
     * @param array $brand   برند [name_fa, name_en, domain, logo]
     * @param string $agencyName نام نمایندگی
     * @param string $agencyLogoPath مسیر لوگوی نمایندگی
     * @param string|null $photoPath مسیر تصویر پیوست اصلی (نسبت به ROOT_PATH یا مطلق)
     * @return string|null مسیر فایل ساخته‌شده یا null در صورت خطا
     */
    public static function render(array $request, array $brand, string $agencyName = '', string $agencyLogoPath = '', ?string $photoPath = null): ?string
    {
        self::fonts();
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        try {
            /* 📐 محاسبه ارتفاع بخش‌ها */
            $photoH = 0;
            $photo = null;
            if ($photoPath !== null && $photoPath !== '') {
                $photoAbs = (strpos($photoPath, '/') === 0) ? $photoPath : ROOT_PATH . '/' . ltrim($photoPath, '/');
                if (is_file($photoAbs)) {
                    $info = @getimagesize($photoAbs);
                    if ($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                        $photoH = (int)min(560, max(220, round(self::W * $info[1] / max(1, $info[0]))));
                        $photo = ['abs' => $photoAbs, 'w' => $info[0], 'h' => $info[1]];
                    }
                }
            }

            /* فیلدهای متنی — [برچسب، مقدار، چندخطی؟] */
            $deviceLabel = trim((string)($request['device_name'] ?? ''));
            $fields = self::buildFields($request, $deviceLabel);

            $pad = 34;
            $headH = 96;
            $lineH = 40;
            $rowsH = 0;
            $maxValueW = self::W - $pad * 2 - 300; /* 300 = ستون برچسب */
            foreach ($fields as $f) {
                $lines = self::wrapText((string)$f[1], self::$fontRegular, 21, $maxValueW);
                $rowsH += 16 + count($lines) * ($f[2] ? 34 : $lineH);
            }
            /* 🆕 v2.32 — کارت بدون تصویر مشتری: لوگوها در فوتر نشان داده
               می‌شوند تا برندینگ پیام حفظ شود (درخواست «لوگوها در پیام») */
            $logoInFooter = ($photo === null) && (!empty($brand['logo']) || $agencyLogoPath !== '');
            $footH = $logoInFooter ? 112 : 74;
            $H = $headH + $photoH + 34 + $rowsH + $footH;

            /* 🎨 بوم */
            $img = imagecreatetruecolor(self::W, $H);
            $white = imagecolorallocate($img, 255, 255, 255);
            $dark = imagecolorallocate($img, 17, 26, 47);
            $gray = imagecolorallocate($img, 100, 116, 139);
            $accent = imagecolorallocate($img, 30, 64, 175);
            $lightBg = imagecolorallocate($img, 243, 246, 252);
            imagefill($img, 0, 0, $white);

            $y = 0;

            /* 📨 هدر سرمه‌ای */
            $header = imagecreatetruecolor(self::W, $headH);
            imagefill($header, 0, 0, $dark);
            imagecopy($img, $header, 0, 0, 0, 0, self::W, $headH);
            imagedestroy($header);
            self::drawText($img, self::shape('📨 درخواست خدمات جدید'), self::$fontBold, 27, $white, self::W - $pad, 34, 'right');
            $brandLine = trim(($brand['name_fa'] ?? '') . (($brand['domain'] ?? '') !== '' ? '  |  ' . $brand['domain'] : ''));
            if ($brandLine !== '') {
                self::drawText($img, self::shape($brandLine), self::$fontRegular, 17, imagecolorallocate($img, 148, 176, 255), self::W - $pad, 68, 'right');
            }
            $y = $headH;

            /* 🖼 تصویر پیوست */
            if ($photo !== null) {
                $photoImg = self::loadImage($photo['abs']);
                if ($photoImg !== null) {
                    /* جاگذاری با برش مرکزی تا نسبت حفظ شود */
                    $targetRatio = self::W / $photoH;
                    $srcRatio = $photo['w'] / $photo['h'];
                    if ($srcRatio > $targetRatio) {
                        $sw = (int)round($photo['h'] * $targetRatio);
                        $sx = (int)(($photo['w'] - $sw) / 2);
                        $sy = 0; $sh = $photo['h'];
                    } else {
                        $sh = (int)round($photo['w'] / $targetRatio);
                        $sx = 0; $sy = (int)(($photo['h'] - $sh) / 2);
                        $sw = $photo['w'];
                    }
                    imagecopyresampled($img, $photoImg, 0, $y, $sx, $sy, self::W, $photoH, $sw, $sh);
                    imagedestroy($photoImg);
                }

                /* 🏷 واترمارک لوگوها — گوشه‌های پایین تصویر */
                $wmY = $y + $photoH;
                /* نوار سایه ملایم پایین تصویر برای خوانایی */
                $shadeH = 92;
                for ($s = 0; $s < $shadeH; $s++) {
                    $alpha = (int)round(105 * ($shadeH - $s) / $shadeH * 0.75);
                    $lineCol = imagecolorallocatealpha($img, 8, 15, 30, 127 - (int)round($alpha / 2));
                    imageline($img, 0, $wmY - $shadeH + $s, self::W, $wmY - $shadeH + $s, $lineCol);
                }
                /* لوگوی برند — پایین چپ | لوگوی نمایندگی — پایین راست */
                if (!empty($brand['logo'])) {
                    self::drawLogoChip($img, $brand['logo'], 18, $wmY - 82, 'left');
                }
                if ($agencyLogoPath !== '') {
                    self::drawLogoChip($img, $agencyLogoPath, self::W - 18, $wmY - 82, 'right');
                }
                $y = $wmY;
                /* خط جداکننده */
                imagerectangle($img, 0, $y, self::W, $y, imagecolorallocate($img, 226, 232, 240));
                $y += 34;
            } else {
                $y = $headH + 28;
            }

            /* 📋 فیلدها */
            foreach ($fields as $f) {
                [$label, $value, $multiline] = $f;
                $lines = self::wrapText((string)$value, self::$fontRegular, 21, $maxValueW);
                /* پس‌زمینه یک‌درمیان */
                $rowH = 16 + count($lines) * ($multiline ? 34 : $lineH);
                if ($multiline || count($lines) > 1) {
                    imagefilledrectangle($img, 16, $y, self::W - 16, $y + $rowH, $lightBg);
                }
                self::drawText($img, self::shape($label), self::$fontBold, 18, $accent, self::W - $pad, $y + 14, 'right');
                $vy = $y + 12;
                foreach ($lines as $ln) {
                    self::drawText($img, $ln, self::$fontRegular, $multiline ? 26 : 21, $dark, self::W - $pad - 300, $vy, 'right');
                    $vy += $multiline ? 34 : $lineH;
                }
                $y += $rowH;
            }

            /* 🦶 فوتر — v2.32: بدون تصویر مشتری، دو لوگو اینجا قرار می‌گیرند */
            $foot = imagecreatetruecolor(self::W, $footH);
            imagefill($foot, 0, 0, $lightBg);
            imagecopy($img, $foot, 0, $H - $footH, 0, 0, self::W, $footH);
            imagedestroy($foot);
            if ($logoInFooter) {
                if (!empty($brand['logo'])) {
                    self::drawLogoChip($img, (string)$brand['logo'], 18, $H - $footH + 12, 'left');
                }
                if ($agencyLogoPath !== '') {
                    self::drawLogoChip($img, $agencyLogoPath, self::W - 18, $H - $footH + 12, 'right');
                }
                $footText = trim(($agencyName !== '' ? '🏢 ' . $agencyName . '   ' : '') . '⏰ ' . jdate(date('Y-m-d H:i'), true));
                self::drawText($img, self::shape($footText), self::$fontRegular, 17, $gray, self::W - $pad, $H - 40, 'right');
                if (!empty($request['request_id'])) {
                    self::drawText($img, self::shape('کد پیگیری: ' . $request['request_id']), self::$fontBold, 17, $accent, $pad, $H - 40, 'left');
                }
            } else {
                $footText = trim(($agencyName !== '' ? '🏢 ' . $agencyName . '   ' : '') . '⏰ ' . jdate(date('Y-m-d H:i'), true));
                self::drawText($img, self::shape($footText), self::$fontRegular, 17, $gray, self::W - $pad, $H - $footH + 24, 'right');
                if (!empty($request['request_id'])) {
                    self::drawText($img, self::shape('کد پیگیری: ' . $request['request_id']), self::$fontBold, 17, $accent, $pad, $H - $footH + 24, 'left');
                }
            }

            /* 💾 ذخیره */
            $dir = ROOT_PATH . '/uploads/requests/composed';
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
            $outPath = $dir . '/card_' . uniqid('req_') . '.jpg';
            $ok = imagejpeg($img, $outPath, 88);
            imagedestroy($img);
            return $ok ? $outPath : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** 📋 فیلدهای کارت — نام دستگاه فارسی از فرستنده */
    private static function buildFields(array $request, string $deviceLabel): array
    {
        $fields = [];
        $rows = [
            ['👤 نام و نام خانوادگی', $request['full_name'] ?? '', false],
            ['📞 شماره تماس', $request['phone'] ?? '', false],
            ['📞 تماس دوم', $request['phone2'] ?? '', false],
            ['📍 آدرس', $request['address'] ?? '', false],
            ['🔧 نوع دستگاه', $deviceLabel, false],
            ['📋 مدل دستگاه', $request['device_model'] ?? '', false],
            ['📝 شرح ایراد', $request['description'] ?? '', true],
            ['📅 زمان مراجعه ترجیحی', trim(($request['preferred_date'] ?? '') . ' ' . ($request['preferred_time'] ?? '')), false],
        ];
        foreach ($rows as $r) {
            if (trim((string)$r[1]) !== '') {
                $fields[] = $r;
            }
        }
        return $fields;
    }

    /** 🖼 بارگذاری تصویر (jpeg/png/webp) */
    private static function loadImage(string $path)
    {
        $info = @getimagesize($path);
        if (!$info) { return null; }
        switch ($info[2]) {
            case IMAGETYPE_JPEG: return @imagecreatefromjpeg($path);
            case IMAGETYPE_PNG: return @imagecreatefrompng($path);
            case IMAGETYPE_WEBP: return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null;
        }
        return null;
    }

    /**
     * 🏷 چیپ لوگو — پس‌زمینه سفید گرد + لوگو با حاشیه
     * $anchorX: لبه مرجع (چپ یا راست) | $topY: بالای چیپ
     */
    private static function drawLogoChip($img, string $logoPath, int $anchorX, int $topY, string $side): void
    {
        $abs = (strpos($logoPath, '/') === 0) ? $logoPath : ROOT_PATH . '/' . ltrim($logoPath, '/');
        if (!is_file($abs)) { return; }
        $logo = self::loadImage($abs);
        if ($logo === null) { return; }
        $lw = imagesx($logo);
        $lh = imagesy($logo);
        $chipH = 64;
        $chipW = 200;
        $scale = min(($chipH - 18) / $lh, ($chipW - 24) / $lw, 1.5);
        $dw = (int)max(20, round($lw * $scale));
        $dh = (int)max(14, round($lh * $scale));

        $cx = $side === 'left' ? $anchorX : $anchorX - $chipW;
        /* پس‌زمینه سفید نیمه‌شفاف گرد */
        $chip = imagecreatetruecolor($chipW, $chipH);
        imagealphablending($chip, false);
        imagesavealpha($chip, true);
        $transparent = imagecolorallocatealpha($chip, 0, 0, 0, 127);
        imagefill($chip, 0, 0, $transparent);
        imagealphablending($chip, true);
        $chipBg = imagecolorallocatealpha($chip, 255, 255, 255, 26); /* ~۹۰٪ مات */
        self::imageRoundedRect($chip, 0, 0, $chipW - 1, $chipH - 1, 14, $chipBg);
        /* لوگو داخل چیپ */
        imagecopyresampled($chip, $logo, (int)(($chipW - $dw) / 2), (int)(($chipH - $dh) / 2), 0, 0, $dw, $dh, $lw, $lh);
        imagealphablending($chip, true);
        imagecopymerge($img, $chip, $cx, $topY, 0, 0, $chipW, $chipH, 92);
        imagedestroy($chip);
        imagedestroy($logo);
    }

    /** مستطیل گرد (fill) */
    private static function imageRoundedRect($img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
    }

    /** ✂️ شکستن متن به خطوط با عرض مجاز */
    private static function wrapText(string $text, string $font, int $size, int $maxW): array
    {
        $text = self::shape($text);
        $words = preg_split('/ /u', $text) ?: [];
        $lines = [];
        $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : ($w . ' ' . $cur); /* RTL: کلمه جدید جلو می‌آید */
            $box = @imagettfbbox($size, 0, $font, $try);
            $wpx = $box ? max($box[2], $box[4]) - min($box[0], $box[6]) : 0;
            if ($wpx <= $maxW || $cur === '') {
                $cur = $try;
                if ($wpx > $maxW) { $lines[] = $cur; $cur = ''; } /* کلمه تک‌بسیار بلند */
            } else {
                $lines[] = $cur;
                $cur = $w;
            }
        }
        if ($cur !== '') { $lines[] = $cur; }
        return $lines ?: [''];
    }

    /** ✍️ رسم متن شکل‌دهی‌شده — align: right|left با مختصات لنگر */
    private static function drawText($img, string $shapedText, string $font, int $size, int $color, int $x, int $y, string $align): void
    {
        if (trim($shapedText) === '') { return; }
        $box = @imagettfbbox($size, 0, $font, $shapedText);
        if (!$box) { return; }
        $tw = max($box[2], $box[4]) - min($box[0], $box[6]);
        $drawX = $align === 'right' ? $x - $tw : $x;
        @imagettftext($img, $size, 0, $drawX, $y + $size, $color, $font, $shapedText);
    }
}
