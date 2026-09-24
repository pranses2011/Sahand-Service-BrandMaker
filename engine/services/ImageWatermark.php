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
            $wm = self::loadResampled($brandLogo, $brandMax);
            if ($wm !== null) {
                $w = imagesx($dst); $h = imagesy($dst);
                $dw = imagesx($wm); $dh = imagesy($wm);
                self::drawAlpha($dst, $wm, $w - $dw - $margin, $h - $dh - $margin, $opacity);
                imagedestroy($wm);
            }
        }
        if ($agencyLogo !== null && is_file($agencyLogo)) {
            $wm = self::loadResampled($agencyLogo, $agencyMax);
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

    /**
     * ✏️ رسم با حفظ آلفا — ضریب شفافیت روی آلفای تک‌پیکسل اعمال می‌شود
     * (جایگزین imagecopymerge که جعبه مشکی می‌سازد)
     *
     * @param resource|GdImage $dst
     * @param resource|GdImage $src بوم truecolor با آلفا
     */
    public static function drawAlpha($dst, $src, int $x, int $y, float $opacity = 1.0): void
    {
        $opacity = max(0.05, min(1.0, $opacity));
        $sw = imagesx($src);
        $sh = imagesy($src);

        /* نسخه با آلفای تعدیل‌شده — بدون دست‌زدن به RGB */
        $tmp = imagecreatetruecolor($sw, $sh);
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
        imagecopy($tmp, $src, 0, 0, 0, 0, $sw, $sh);
        if ($opacity < 0.999) {
            for ($yy = 0; $yy < $sh; $yy++) {
                for ($xx = 0; $xx < $sw; $xx++) {
                    $c = imagecolorat($tmp, $xx, $yy);
                    $a = ($c >> 24) & 0x7F; // 0=مات .. 127=کاملاً شفاف
                    $na = (int)round(127 - (127 - $a) * $opacity);
                    if ($na !== $a) {
                        imagesetpixel($tmp, $xx, $yy, ($na << 24) | ($c & 0xFFFFFF));
                    }
                }
            }
        }
        imagealphablending($dst, true);
        imagesavealpha($dst, true);
        imagecopy($dst, $tmp, $x, $y, 0, 0, $sw, $sh);
        imagedestroy($tmp);
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

        /* 🗃 کش: کلید = منبع + برند + زمان تغییر لوگوها */
        $sig = md5($srcRel . '|' . ($brand['id'] ?? 0) . '|' . filemtime($srcAbs) . '|' .
            ($brandLogo ? filemtime($brandLogo) : '-') . '|' . ($agencyLogo ? filemtime($agencyLogo) : '-'));
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
        /* نسبت تصویر مقاله — واترمارک متناسب با ابعاد واقعی */
        $shortSide = min(imagesx($img), imagesy($img));
        $max = max(80, (int)round($shortSide * 0.14));
        self::stampCorners($img, $brandLogo, $agencyLogo, [
            'brand_max'  => $max,
            'agency_max' => (int)round($max * 0.8),
            'opacity'    => 0.78,
            'margin'     => max(14, (int)round($shortSide * 0.025)),
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
