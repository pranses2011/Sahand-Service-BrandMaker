<?php
/**
 * 🪧 ImageWatermark — مهر واترمارک آلفا-درست (v1.0)
 * ==================================================
 * ریشه‌یابی باگ «زمینه شفاف لوگو مشکی می‌شود»:
 *   imagecopymerge() آلفای تک‌پیکسل را نادیده می‌گیرد — پیکسل‌های کاملاً شفاف
 *   RGB=(0,0,0) دارند و با ضریب ادغام، «سیاهی» روی مقصد می‌نشینند.
 *
 * روش صحیح (این کلاس):
 *   ۱) لوگو در بوم truecolor با آلفا سالم نمونه‌برداری می‌شود
 *   ۲) آلفای هر پیکسل در ضریب شفافیت دلخواه ضرب می‌شود (پیکسل‌به‌پیکسل)
 *   ۳) با imagecopy() + imagealphablending(true) جا می‌شود — این ترکیب
 *      آلفای تک‌پیکسل را کاملاً رعایت می‌کند
 *
 * برای مقصد JPG هم بی‌نقص است (مقصد مات است و بلندینگ آلفا کار می‌کند).
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0
 */
class ImageWatermark
{
    /** 📏 حداکثر ضلع واترمارک پیش‌فرض */
    const DEFAULT_MAX = 150;

    /**
     * 🪧 مهر دو لوگو روی یک تصویر رستری:
     *   - لوگوی برند: گوشه پایین-راست
     *   - لوگوی نمایندگی: گوشه پایین-چپ
     * هر دو با زمینه شفاف حفظ‌شده (بدون جعبه مشکی).
     *
     * @param resource|GdImage $dst        تصویر مقصد (GD)
     * @param string|null      $brandLogo  مسیر مطلق لوگوی برند (رستر)
     * @param string|null      $agencyLogo مسیر مطلق لوگوی نمایندگی (رستر)
     * @param array{brand_max?:int, agency_max?:int, opacity?:float, margin?:int} $opts
     */
    public static function stampCorners($dst, ?string $brandLogo, ?string $agencyLogo, array $opts = []): void
    {
        $brandMax  = (int)($opts['brand_max'] ?? self::DEFAULT_MAX);
        $agencyMax = (int)($opts['agency_max'] ?? (int)round($brandMax * 0.8));
        $opacity   = (float)($opts['opacity'] ?? 0.72);
        $margin    = (int)($opts['margin'] ?? 22);

        if ($brandLogo !== null && is_file($brandLogo)) {
            $wm = self::loadResampledClean($brandLogo, $brandMax);
            if ($wm !== null) {
                $w = imagesx($dst); $h = imagesy($dst);
                $dw = imagesx($wm); $dh = imagesy($wm);
                self::drawAlpha($dst, $wm, $w - $dw - $margin, $h - $dh - $margin, $opacity);
                imagedestroy($wm);
            }
        }
        if ($agencyLogo !== null && is_file($agencyLogo)) {
            $wm = self::loadResampledClean($agencyLogo, $agencyMax);
            if ($wm !== null) {
                $h = imagesy($dst);
                $dw = imagesx($wm); $dh = imagesy($wm);
                self::drawAlpha($dst, $wm, $margin, $h - $dh - $margin, $opacity);
                imagedestroy($wm);
            }
        }
    }

    /**
     * 🖼 بارگذاری لوگو در بوم truecolor با آلفای سالم + مقیاس به حداکثر ضلع
     * @return resource|GdImage|null
     */
    public static function loadResampled(string $path, int $max)
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) > 4 * 1024 * 1024) {
            return null;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'gif', 'webp', 'jpg', 'jpeg'], true)) {
            return null; // SVG و غیره — مسیر رستر نیست
        }
        $data = @file_get_contents($path);
        if (!is_string($data) || $data === '') {
            return null;
        }
        $src = @imagecreatefromstring($data);
        if (!$src) {
            return null;
        }
        if (!imageistruecolor($src)) {
            @imagepalettetotruecolor($src);
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) {
            imagedestroy($src);
            return null;
        }
        $scale = min($max / $sw, $max / $sh, 1.5); // کمی بزرگ‌نمایی مجاز برای لوگوهای کوچک
        $dw = max(1, (int)round($sw * $scale));
        $dh = max(1, (int)round($sh * $scale));

        $tmp = imagecreatetruecolor($dw, $dh);
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
        $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
        imagefill($tmp, 0, 0, $transparent);
        imagealphablending($tmp, true);
        imagecopyresampled($tmp, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
        imagedestroy($src);
        return $tmp;
    }

    /* ==================================================
     * 🧽 v3.2: حذف هوشمند پس‌زمینه لوگوهای بدون شفافیت (JPG و...)
     * ================================================== */

    /** @var array کش درون-درخواست نسخه‌های پاک‌شده */
    private static $cleanCache = [];

    /**
     * 🧽 بارگذاری لوگو با پس‌زمینه پاک‌شده — نسخه «تمیز» loadResampled
     *
     * اگر تصویر آلفای واقعی دارد (PNG/WebP شفاف) دست‌نخورده برمی‌گردد؛
     * در غیر این صورت (JPG یا PNG مات) پس‌زمینه یکدستِ لبه‌ها (معمولاً سفید)
     * با flood-fill از مرزها حذف می‌شود — سفیدهای داخل لوگو (مثل متن سفید
     * داخل نشان) دست‌نخورده می‌مانند چون فقط نواحی متصل به مرز پاک می‌شوند.
     *
     * @return resource|GdImage|null
     */
    public static function loadResampledClean(string $path, int $max)
    {
        $res = self::loadResampled($path, $max);
        if ($res === null) {
            return null;
        }
        if (!self::hasRealAlpha($res)) {
            self::removeEdgeBackground($res);
        }
        return $res;
    }

    /**
     * 🔎 آیا تصویر پیکسل‌های شفاف/نیمه‌شفاف واقعی دارد؟
     * (نمونه‌گیری هر ۴ پیکسل برای سرعت)
     */
    public static function hasRealAlpha($img): bool
    {
        $w = imagesx($img);
        $h = imagesy($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        for ($y = 0; $y < $h; $y += 2) {
            for ($x = 0; $x < $w; $x += 2) {
                $c = imagecolorat($img, $x, $y);
                if ((($c >> 24) & 0x7F) > 8) {
                    imagealphablending($img, true);
                    return true;
                }
            }
        }
        imagealphablending($img, true);
        return false;
    }

    /**
     * 🧽 حذف پس‌زمینه لبه‌ها — flood-fill چهارهمبستگی از تمام پیکسل‌های مرزی
     * رنگ مرجع = میانگین گوشه‌ها؛ تلورانس رنگی فاصله اقلیدسی RGB
     */
    public static function removeEdgeBackground($img, int $tolerance = 34): void
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 2 || $h < 2) {
            return;
        }

        /* 🎯 رنگ مرجع از گوشه‌ها (میانگین) — اگر لبه‌ها یکدست نباشند، رها کن */
        $corners = [[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1], [(int)($w / 2), 0], [(int)($w / 2), $h - 1], [0, (int)($h / 2)], [$w - 1, (int)($h / 2)]];
        $br = $bg = $bb = 0;
        foreach ($corners as [$cx, $cy]) {
            $c = imagecolorat($img, $cx, $cy);
            $br += ($c >> 16) & 0xFF;
            $bg += ($c >> 8) & 0xFF;
            $bb += $c & 0xFF;
        }
        $br = (int)round($br / count($corners));
        $bg = (int)round($bg / count($corners));
        $bb = (int)round($bb / count($corners));

        /* فقط پس‌زمینه‌های روشن (سفید/کرم/خاکستری روشن) پاک می‌شوند —
           پس‌زمینه تیره یا رنگی دست‌نخورده می‌ماند (لوگوی تیره روی تیره منطقی نیست حذف شود) */
        if ($br < 205 || $bg < 205 || $bb < 205) {
            return;
        }

        $tol2 = $tolerance * $tolerance;
        imagealphablending($img, false);
        imagesavealpha($img, true);

        $transparent = 0x7F000000; // آلفای کامل
        $visited = [];
        $stack = [];
        /* همه پیکسل‌های مرزی همرنگ پس‌زمینه → نقطه شروع */
        for ($x = 0; $x < $w; $x++) {
            $stack[] = [$x, 0];
            $stack[] = [$x, $h - 1];
        }
        for ($y = 0; $y < $h; $y++) {
            $stack[] = [0, $y];
            $stack[] = [$w - 1, $y];
        }

        $toClear = [];
        while ($stack) {
            [$x, $y] = array_pop($stack);
            if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) {
                continue;
            }
            $idx = $y * $w + $x;
            if (isset($visited[$idx])) {
                continue;
            }
            $c = imagecolorat($img, $x, $y);
            if ((($c >> 24) & 0x7F) > 60) {
                $visited[$idx] = 1; // از قبل شفاف
                continue;
            }
            $dr = ((($c >> 16) & 0xFF) - $br);
            $dg = ((($c >> 8) & 0xFF) - $bg);
            $db = (($c & 0xFF) - $bb);
            if ($dr * $dr + $dg * $dg + $db * $db > $tol2) {
                $visited[$idx] = 1; // همرنگ نیست — مرز لوگو
                continue;
            }
            $visited[$idx] = 1;
            $toClear[] = $idx;
            $stack[] = [$x + 1, $y];
            $stack[] = [$x - 1, $y];
            $stack[] = [$x, $y + 1];
            $stack[] = [$x, $y - 1];
        }

        /* پاک‌سازی + لبه‌ی نرم (feather): همسایه‌های نیمه‌روشن پاک‌شده، نیمه‌شفاف */
        foreach ($toClear as $idx) {
            $x = $idx % $w;
            $y = (int)($idx / $w);
            imagesetpixel($img, $x, $y, $transparent | (imagecolorat($img, $x, $y) & 0xFFFFFF));
        }
        /* پرکردن حفره‌های antialias: پیکسل‌های همسایه پاک‌شده که هنوز مات و روشن‌اند → نیمه‌شفاف */
        $feather = [];
        foreach ($toClear as $idx) {
            $x = $idx % $w;
            $y = (int)($idx / $w);
            foreach ([[$x + 1, $y], [$x - 1, $y], [$x, $y + 1], [$x, $y - 1]] as [$nx, $ny]) {
                if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                    continue;
                }
                $nidx = $ny * $w + $nx;
                if (isset($visited[$nidx])) {
                    continue;
                }
                $c = imagecolorat($img, $nx, $ny);
                if ((($c >> 24) & 0x7F) > 60) {
                    continue;
                }
                $lum = ((( $c >> 16) & 0xFF) + (($c >> 8) & 0xFF) + ($c & 0xFF)) / 3;
                if ($lum > 215) {
                    $feather[$nidx] = 1;
                }
            }
        }
        foreach ($feather as $nidx => $_) {
            $x = $nidx % $w;
            $y = (int)($nidx / $w);
            $c = imagecolorat($img, $x, $y);
            imagesetpixel($img, $x, $y, 0x5F000000 | ($c & 0xFFFFFF)); // ~۵۰٪ شفاف
        }
        imagealphablending($img, true);
    }

    /**
     * 💾 مسیر PNG پاک‌شدهٔ کش‌شده — برای جاسازی در SVG/خروجی
     * نسخه شفاف‌شده لوگو در cache/ ذخیره و مسیرش برگردانده می‌شود.
     * @return string|null مسیر مطلق PNG تمیز یا null
     */
    public static function cleanedPngPath(string $absPath): ?string
    {
        if (!is_file($absPath) || !is_readable($absPath) || filesize($absPath) > 4 * 1024 * 1024) {
            return null;
        }
        $key = md5($absPath . '|' . filesize($absPath) . '|' . filemtime($absPath));
        if (isset(self::$cleanCache[$key])) {
            return self::$cleanCache[$key];
        }
        $res = self::loadResampledClean($absPath, 900);
        if ($res === null) {
            return null;
        }
        $cacheDir = defined('CACHE_PATH') ? CACHE_PATH : (dirname($absPath, 3) . '/cache');
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true)) {
            imagedestroy($res);
            return null;
        }
        $out = $cacheDir . '/logo-clean-' . $key . '.png';
        imagealphablending($res, false);
        imagesavealpha($res, true);
        $ok = imagepng($res, $out, 6);
        imagedestroy($res);
        if (!$ok) {
            return null;
        }
        self::$cleanCache[$key] = $out;
        return $out;
    }

    /**
     * ✏️ رسم با حفظ آلفا — ضریب شفافیت روی آلفای تک‌پیکسل اعمال می‌شود
     * (جایگزین imagecopymerge که جعبه مشکی می‌سازد)
     *
     * 🧪 v2.8: ترکیب نهایی به‌جای imagecopy (که رفتار آلفایش بین بیلدهای GD
     * متفاوت است) به‌صورت «پیکسل‌به‌پیکسل دستی» انجام می‌شود — خروجی در همه
     * محیط‌ها (هست‌های مختلف) یکسان و قطعی است.
     *
     * @param resource|GdImage $dst
     * @param resource|GdImage $src بوم truecolor با آلفا
     */
    public static function drawAlpha($dst, $src, int $x, int $y, float $opacity = 1.0): void
    {
        $opacity = max(0.05, min(1.0, $opacity));
        self::compositeAlpha($dst, $src, $x, $y, $opacity);
    }

    /**
     * 🧬 ترکیب آلفای پیکسل‌به‌پیکسل — قطعی و مستقل از بیلد GD (v2.8)
     *
     * فرمول استاندارد: out = src×(آلفای‌مؤثر) + dst×(۱−آلفای‌مؤثر)
     * آلفای مؤثر = آلفای پیکسل منبع × ضریب شفافیت کلی
     * نوشتن با imagesetpixel روی مقصدی که بلندینگ آن خاموش است = بدون ابهام.
     */
    public static function compositeAlpha($dst, $src, int $x, int $y, float $opacity = 1.0): void
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $dw = imagesx($dst);
        $dh = imagesy($dst);
        if ($sw < 1 || $sh < 1) {
            return;
        }
        /* محدوده برشخورده با مرزهای مقصد */
        $x0 = max(0, $x);
        $y0 = max(0, $y);
        $x1 = min($dw, $x + $sw);
        $y1 = min($dh, $y + $sh);
        if ($x1 <= $x0 || $y1 <= $y0) {
            return;
        }
        /* بلندینگ مقصد را خاموش می‌کنیم تا setpixel «دقیقاً» مقدار ما را بنویسد */
        $prevBlend = imagealphablending($dst, false);
        for ($yy = $y0; $yy < $y1; $yy++) {
            $sy = $yy - $y;
            for ($xx = $x0; $xx < $x1; $xx++) {
                $sx = $xx - $x;
                $sc = imagecolorat($src, $sx, $sy);
                $sa = (($sc >> 24) & 0x7F) / 127.0;      // 0=مات .. 1=کاملاً شفاف
                $eff = (1.0 - $sa) * $opacity;             // سهم رنگ منبع
                if ($eff <= 0.001) {
                    continue;                               // پیکسل کاملاً شفاف — دست نزن
                }
                if ($eff >= 0.999) {
                    /* منبع کاملاً مات — جایگزینی مستقیم */
                    imagesetpixel($dst, $xx, $yy, $sc & 0xFFFFFF);
                    continue;
                }
                $dc = imagecolorat($dst, $xx, $yy);
                $r = (int)round(((($sc >> 16) & 0xFF) * $eff) + ((($dc >> 16) & 0xFF) * (1 - $eff)));
                $g = (int)round(((($sc >> 8) & 0xFF) * $eff) + ((($dc >> 8) & 0xFF) * (1 - $eff)));
                $b = (int)round((($sc & 0xFF) * $eff) + (($dc & 0xFF) * (1 - $eff)));
                imagesetpixel($dst, $xx, $yy, ($r << 16) | ($g << 8) | $b);
            }
        }
        imagealphablending($dst, $prevBlend);
    }

    /**
     * 🧰 ساخت نسخه واترمارک‌شده یک تصویر فایل (کش‌شده)
     * تصویر منبع دست‌نخورده می‌ماند؛ خروجی در uploads/articles/wm/
     *
     * @param string      $srcRel مسیر نسبی منبع (assets/images/articles/...)
     * @param array|null  $brand  رکورد برند (برای لوگو)
     * @param string      $stampKey کلید کش (مثلاً b{brandId})
     * @return string|null مسیر نسبی جدید یا null (خطا)
     */
    public static function stampedCopy(string $srcRel, ?array $brand, string $stampKey = ''): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }
        $srcAbs = ROOT_PATH . '/' . ltrim($srcRel, '/');
        if (!is_file($srcAbs) || !is_readable($srcAbs)) {
            return null;
        }
        $ext = strtolower(pathinfo($srcAbs, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return null;
        }

        $brandLogo = self::brandLogoFile($brand);
        $agencyLogo = self::agencyLogoFile();
        if ($brandLogo === null && $agencyLogo === null) {
            return null; // چیزی برای مهر ندارد
        }

        /* 🗃 کش: کلید = منبع + برند + زمان تغییر لوگوها + نسخه چیدمان واترمارک
           (v3.2: نسخه «wm3» — واترمارک‌های بزرگ‌تر + پس‌زمینه پاک‌شده JPG مجدداً تولید شوند) */
        $sig = md5($srcRel . '|' . ($brand['id'] ?? 0) . '|' . filemtime($srcAbs) . '|' .
            ($brandLogo ? filemtime($brandLogo) : '-') . '|' . ($agencyLogo ? filemtime($agencyLogo) : '-') . '|wm3');
        $outRel = 'uploads/articles/wm/' . pathinfo($srcRel, PATHINFO_FILENAME) . '-' . $sig . '.' . $ext;
        $outAbs = ROOT_PATH . '/' . $outRel;
        if (is_file($outAbs) && filesize($outAbs) > 1024) {
            return $outRel;
        }
        $dir = dirname($outAbs);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return null;
        }

        $data = @file_get_contents($srcAbs);
        $img = $data ? @imagecreatefromstring($data) : false;
        if (!$img) {
            return null;
        }
        /* نسبت تصویر مقاله — واترمارک متناسب با ابعاد واقعی
           (v3.2: بسیار بزرگ‌تر و پررنگ‌تر طبق درخواست مجدد کاربر — لوگوی برند ≈۳۰٪ و نمایندگی ≈۲۵٪ ضلع کوتاه) */
        $shortSide = min(imagesx($img), imagesy($img));
        $max = max(120, (int)round($shortSide * 0.30));
        self::stampCorners($img, $brandLogo, $agencyLogo, [
            'brand_max'  => $max,
            'agency_max' => (int)round($max * 0.84),
            'opacity'    => 0.92,
            'margin'     => max(14, (int)round($shortSide * 0.022)),
        ]);
        $ok = $ext === 'png' ? imagepng($img, $outAbs, 8) : imagejpeg($img, $outAbs, 88);
        imagedestroy($img);
        return $ok ? $outRel : null;
    }

    /** 🖼 مسیر مطلق لوگوی برند (رستر؛ SVG فقط با Imagick) */
    public static function brandLogoFile(?array $brand): ?string
    {
        $rel = is_array($brand) ? trim((string)($brand['logo'] ?? '')) : '';
        return self::rasterPath($rel);
    }

    /** 🪧 مسیر مطلق لوگوی نمایندگی (رستر؛ SVG فقط با Imagick) */
    public static function agencyLogoFile(): ?string
    {
        try {
            $rel = trim((string)(Config::get(Config::KEY_AGENCY_LOGO) ?: ''));
        } catch (Throwable $e) {
            return null;
        }
        return self::rasterPath($rel);
    }

    /** 🔎 تبدیل مسیر نسبی لوگو به مسیر رستر قابل استفاده (SVG → رستر با Imagick) */
    private static function rasterPath(string $rel): ?string
    {
        if ($rel === '') {
            return null;
        }
        $abs = ROOT_PATH . '/' . ltrim($rel, '/');
        if (!is_file($abs) || !is_readable($abs) || filesize($abs) > 4 * 1024 * 1024) {
            return null;
        }
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
            return $abs;
        }
        if ($ext === 'svg' && class_exists('Imagick')) {
            return self::svgToPng($abs);
        }
        return null;
    }

    /** 🔄 رستر کردن SVG با Imagick (کش‌شده) */
    private static function svgToPng(string $svgPath): ?string
    {
        $cacheDir = ROOT_PATH . '/uploads/cache/logos';
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true)) {
            return null;
        }
        $out = $cacheDir . '/wm-svg-' . md5($svgPath . '|' . filemtime($svgPath)) . '.png';
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
                $scale = min(420 / $w, 420 / $h, 2);
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
}
