<?php
/**
 * 🖼️ کلاس پردازش تصویر — مبتنی بر PHP GD
 * ========================================
 * تغییر اندازه، فشرده‌سازی، برش و تبدیل فرمت تصاویر
 * آپلودشده (لوگوها، تصاویر مقالات و ...).
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class ImageProcessor
{
    /**
     * 📥 بارگذاری تصویر از مسیر (پشتیبانی JPG/PNG/WEBP/GIF)
     *
     * @return resource|GdImage|null
     */
    public function load(string $path)
    {
        if (!file_exists($path)) {
            return null;
        }
        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }
        switch ($info[2]) {
            case IMAGETYPE_JPEG:
                return @imagecreatefromjpeg($path);
            case IMAGETYPE_PNG:
                return @imagecreatefrompng($path);
            case IMAGETYPE_GIF:
                return @imagecreatefromgif($path);
            case IMAGETYPE_WEBP:
                return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null;
        }
        return null;
    }

    /**
     * 📏 تغییر اندازه تصویر با حفظ نسبت ابعاد
     *
     * @param string $srcPath   مسیر تصویر اصلی
     * @param string $destPath  مسیر ذخیره خروجی
     * @param int    $maxWidth  حداکثر عرض
     * @param int    $quality   کیفیت خروجی (۱ تا ۱۰۰)
     * @return bool موفقیت عملیات
     */
    public function resize(string $srcPath, string $destPath, int $maxWidth = 1200, int $quality = 82): bool
    {
        $img = $this->load($srcPath);
        if (!$img) {
            return false;
        }
        $w = imagesx($img);
        $h = imagesy($img);

        // اگر تصویر کوچکتر از حد مجاز است، فقط کپی شود
        if ($w <= $maxWidth) {
            imagedestroy($img);
            return copy($srcPath, $destPath);
        }

        $newW = $maxWidth;
        $newH = (int)round($h * ($maxWidth / $w));

        $newImg = imagecreatetruecolor($newW, $newH);

        // 🎨 حفظ شفافیت PNG و WEBP
        $ext = mb_strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'webp', 'gif'], true)) {
            imagealphablending($newImg, false);
            imagesavealpha($newImg, true);
            $transparent = imagecolorallocatealpha($newImg, 0, 0, 0, 127);
            imagefilledrectangle($newImg, 0, 0, $newW, $newH, $transparent);
        }

        imagecopyresampled($newImg, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);

        // 💾 ذخیره بر اساس فرمت مقصد
        $destExt = mb_strtolower(pathinfo($destPath, PATHINFO_EXTENSION));
        $result = false;
        switch ($destExt) {
            case 'jpg':
            case 'jpeg':
                $result = imagejpeg($newImg, $destPath, $quality);
                break;
            case 'png':
                $result = imagepng($newImg, $destPath, (int)max(1, min(9, $quality / 12)));
                break;
            case 'webp':
                $result = function_exists('imagewebp') ? imagewebp($newImg, $destPath, $quality) : false;
                break;
            case 'gif':
                $result = imagegif($newImg, $destPath);
                break;
        }

        imagedestroy($img);
        imagedestroy($newImg);
        return (bool)$result;
    }

    /**
     * 🎨 تولید نسخه فشرده و بهینه تصویر (جایگزین اصلی)
     */
    public function optimize(string $path, int $maxWidth = 1600, int $quality = 80): bool
    {
        $tmp = $path . '.opt.tmp';
        if (!$this->resize($path, $tmp, $maxWidth, $quality)) {
            @unlink($tmp);
            return false;
        }
        $ok = rename($tmp, $path);
        if (!$ok) {
            @unlink($tmp);
        }
        return $ok;
    }

    /**
     * 🔲 تولید تصویر مربعی با برش از مرکز (برای thumbnail)
     */
    public function squareThumb(string $srcPath, string $destPath, int $size = 300, int $quality = 82): bool
    {
        $img = $this->load($srcPath);
        if (!$img) {
            return false;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $side = min($w, $h);
        $x = (int)(($w - $side) / 2);
        $y = (int)(($h - $side) / 2);

        $thumb = imagecreatetruecolor($size, $size);
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
        imagefilledrectangle($thumb, 0, 0, $size, $size, $transparent);

        imagecopyresampled($thumb, $img, 0, 0, $x, $y, $size, $size, $side, $side);

        $destExt = mb_strtolower(pathinfo($destPath, PATHINFO_EXTENSION));
        $result = $destExt === 'png'
            ? imagepng($thumb, $destPath, 8)
            : (function_exists('imagewebp') && $destExt === 'webp' ? imagewebp($thumb, $destPath, $quality) : imagejpeg($thumb, $destPath, $quality));

        imagedestroy($img);
        imagedestroy($thumb);
        return (bool)$result;
    }

    /**
     * ℹ️ دریافت ابعاد و حجم تصویر
     */
    public function info(string $path): array
    {
        $data = @getimagesize($path);
        return [
            'width'  => $data[0] ?? 0,
            'height' => $data[1] ?? 0,
            'mime'   => $data['mime'] ?? '',
            'size'   => file_exists($path) ? filesize($path) : 0,
        ];
    }

    /**
     * 🔤 تولید متن روی تصویر (برای تصاویر placeholder فارسی)
     */
    public function textPlaceholder(string $destPath, string $text, int $width = 1200, int $height = 630, array $bg = [37, 99, 235], array $fg = [255, 255, 255]): bool
    {
        $img = imagecreatetruecolor($width, $height);
        $bgColor = imagecolorallocate($img, ...$bg);
        $fgColor = imagecolorallocate($img, ...$fg);
        imagefilledrectangle($img, 0, 0, $width, $height, $bgColor);

        // نوشتن متن (فونت داخلی — برای فارسی از تصاویر آماده استفاده می‌شود)
        imagestring($img, 5, 20, (int)($height / 2 - 10), $text, $fgColor);

        $ok = imagejpeg($img, $destPath, 88);
        imagedestroy($img);
        return $ok;
    }
}
