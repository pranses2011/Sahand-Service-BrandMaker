<?php
/**
 * 🖼️ Captcha — کپچای تصویری سه‌لایه (v2.5)
 * GD+TTF ← GD فونت داخلی ← SVG خالص؛ حالت درون‌خطی data-URI ضد ادبلاکر
 * 🧩 v2.43 (S13): از Auth.php تک‌عظیم تفکیک شد — Auth.php نقش Facade دارد.
 * @package SahandBrandMaker\Core\Auth
 */

class Captcha
{
    public static function renderCaptcha(): void
    {
        $code = self::generateCaptchaCode(5);

        // 💾 ذخیره در سشن (در صورت فعال بودن — security-code.php سشن را فعال می‌کند)
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['captcha_code'] = $code;
        }

        // 🧹 پاک‌سازی هر بافر خروجی احتمالی تا هدر Content-Type سالم ارسال شود
        // (خروجی ناخواسته = خرابی تصویر در مرورگر)
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $width = 250;
        $height = 84;

        // 🎯 لایه ۱ و ۲: رندر با GD (در صورت وجود)
        $gdAvailable = extension_loaded('gd') && function_exists('imagecreatetruecolor');
        if ($gdAvailable) {
            try {
                self::renderCaptchaGd($code, $width, $height);
                return; // renderCaptchaGd خودش exit می‌کند
            } catch (Throwable $e) {
                // 🧯 هر خطای GD → سقوط نرم به لایه SVG
                @error_log('[CAPTCHA] GD render failed, falling back to SVG: ' . $e->getMessage());
            }
        } elseif (self::isCaptchaDirectRequest()) {
            // ثبت در لاگ برای اطلاع مدیر (GD خاموش است — هر درخواست کپچا یک خط لاگ)
            @error_log('[CAPTCHA] GD extension is NOT available — using SVG fallback. Enable "gd" in PHP ' . PHP_VERSION . ' settings for raster rendering.');
        }

        // 🛟 لایه ۳: SVG — بدون هیچ وابستگی، همیشه کار می‌کند
        self::renderCaptchaSvg($code, $width, $height);
    }

    public static function renderCaptchaDataUri(): void
    {
        $code = self::generateCaptchaCode(5);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['captcha_code'] = $code;
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        try {
            $svg = self::buildCaptchaSvg($code, 250, 84);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            echo json_encode([
                'success'  => true,
                'data_uri' => 'data:image/svg+xml;base64,' . base64_encode($svg),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    public static function captchaInlineDataUri(): string
    {
        $code = self::generateCaptchaCode(5);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['captcha_code'] = $code;
        }
        try {
            $svg = self::buildCaptchaSvg($code, 250, 84);
            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        } catch (Throwable $e) {
            // 🛟 هرگز نباید رخ دهد (SVG خالص PHP است) — ولی تضمین: کد متنی
            @error_log('[CAPTCHA] inline build failed: ' . $e->getMessage());
            return '';
        }
    }

    private static function renderCaptchaGd(string $code, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);

        // 🎨 پس‌زمینه روشن
        $bg = imagecolorallocate($image, 243, 244, 246);
        imagefilledrectangle($image, 0, 0, $width, $height, $bg);

        // خطوط نویز برای جلوگیری از خواندن رباتیک
        for ($i = 0; $i < 7; $i++) {
            $color = imagecolorallocate($image, rand(150, 220), rand(150, 220), rand(150, 220));
            imageline($image, rand(0, $width), rand(0, $height), rand(0, $width), rand(0, $height), $color);
        }

        // نقاط نویز
        for ($i = 0; $i < 220; $i++) {
            $color = imagecolorallocate($image, rand(130, 210), rand(130, 210), rand(130, 210));
            imagesetpixel($image, rand(0, $width), rand(0, $height), $color);
        }

        $font = self::findCaptchaFont();
        $scale = $width / 220;

        if ($font !== null) {
            // ✍️ لایه ۱: فونت واقعی TTF — حروف درشت با چرخش واقعی هر حرف
            $x = (int)round(26 * $scale);
            $size = (int)round(30 * $scale);
            foreach (str_split($code) as $char) {
                $color = imagecolorallocate($image, rand(20, 90), rand(20, 90), rand(80, 160));
                imagettftext($image, $size, rand(-14, 14), $x, rand((int)round(46 * $scale), (int)round(56 * $scale)), $color, $font, $char);
                $x += (int)round(36 * $scale);
            }
        } else {
            // ✍️ لایه ۲: فونت داخلی روی بوم کوچک + بزرگ‌نمایی نرم ۲.۶× با Bicubic
            //    (حروف ~۲۳×۳۹ پیکسل — تقریباً ۲.۵ برابر بزرگ‌تر از رندر قبلی)
            $sw = (int)round($width / 2.6);
            $sh = (int)round($height / 2.6);
            $small = imagecreatetruecolor($sw, $sh);
            $sbg = imagecolorallocate($small, 243, 244, 246);
            imagefilledrectangle($small, 0, 0, $sw, $sh, $sbg);

            $x = 6;
            foreach (str_split($code) as $char) {
                $color = imagecolorallocate($small, rand(20, 90), rand(20, 90), rand(80, 160));
                imagestring($small, 5, $x + rand(-1, 1), rand(2, 9), $char, $color);
                $x += 16;
            }

            // 📐 بزرگ‌نمایی نرم روی بوم اصلی (نویزها روی بوم بزرگ‌اند؛ حروف از بوم کوچک می‌آیند)
            imagesetinterpolation($image, IMG_BICUBIC);
            imagecopyresampled($image, $small, 0, 0, 0, 0, $width, $height, $sw, $sh);
            imagedestroy($small);
        }

        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        imagepng($image, null, 6);
        imagedestroy($image);
        exit;
    }

    private static function renderCaptchaSvg(string $code, int $width, int $height): void
    {
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo self::buildCaptchaSvg($code, $width, $height);
        exit;
    }

    private static function buildCaptchaSvg(string $code, int $width, int $height): string
    {
        $parts = [];
        $parts[] = '<rect width="' . $width . '" height="' . $height . '" rx="10" fill="#f3f4f6"/>';

        // خطوط نویز
        for ($i = 0; $i < 7; $i++) {
            $color = 'rgb(' . rand(150, 220) . ',' . rand(150, 220) . ',' . rand(150, 220) . ')';
            $parts[] = sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1.5"/>',
                rand(0, $width), rand(0, $height), rand(0, $width), rand(0, $height), $color
            );
        }

        // نقاط نویز
        for ($i = 0; $i < 90; $i++) {
            $color = 'rgb(' . rand(130, 210) . ',' . rand(130, 210) . ',' . rand(130, 210) . ')';
            $parts[] = sprintf(
                '<circle cx="%d" cy="%d" r="1.1" fill="%s"/>',
                rand(0, $width), rand(0, $height), $color
            );
        }

        // ✍️ حروف درشت با چرخش — مقیاس‌پذیر با ابعاد بوم (v2.7.1: کپچای بزرگ ۲۵۰×۸۴)
        $scale = $width / 220;
        $x = (int)round(34 * $scale);
        $step = (int)round(38 * $scale);
        $yMin = (int)round(48 * $scale);
        $yMax = (int)round(57 * $scale);
        $sizeMin = (int)round(32 * $scale);
        $sizeMax = (int)round(38 * $scale);
        foreach (str_split($code) as $char) {
            $color = 'rgb(' . rand(20, 90) . ',' . rand(20, 90) . ',' . rand(80, 160) . ')';
            $angle = rand(-14, 14);
            $y = rand($yMin, $yMax);
            $size = rand($sizeMin, $sizeMax);
            $parts[] = sprintf(
                '<text x="%d" y="%d" font-family="Vazirmatn,Vazir,Tahoma,Arial,sans-serif" font-size="%d" font-weight="700" fill="%s" text-anchor="middle" transform="rotate(%d %d %d)">%s</text>',
                $x, $y, $size, $color, $angle, $x, $y, htmlspecialchars($char, ENT_QUOTES)
            );
            $x += $step;
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">'
            . implode('', $parts) . '</svg>';
    }

    private static function findCaptchaFont(): ?string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        if (!function_exists('imagettftext') || !function_exists('imagettfbbox')) {
            return $cached = null; // FreeType در دسترس نیست
        }

        // ۱) فونت‌های TTF نصب‌شده داخل سایت‌ساز (پوشه فونت‌های پنل)
        $candidates = array_merge(
            glob(ROOT_PATH . '/assets/fonts/fa/*/*.ttf') ?: [],
            glob(ROOT_PATH . '/assets/fonts/en/*/*.ttf') ?: [],
            glob(ROOT_PATH . '/assets/fonts/*/*.ttf') ?: []
        );

        // ۲) فونت‌های رایج سیستم‌عامل
        $systemFonts = PHP_OS_FAMILY === 'Windows'
            ? ['C:/Windows/Fonts/arial.ttf', 'C:/Windows/Fonts/tahoma.ttf', 'C:/Windows/Fonts/verdana.ttf', 'C:/Windows/Fonts/calibri.ttf']
            : [
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
                '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
            ];
        foreach ($systemFonts as $f) {
            if (is_file($f)) {
                $candidates[] = $f;
            }
        }

        // اعتبارسنجی هر فونت با یک bbox آزمایشی
        foreach ($candidates as $font) {
            if (is_file($font) && is_readable($font) && @imagettfbbox(20, 0, $font, 'A') !== false) {
                return $cached = $font;
            }
        }
        return $cached = null;
    }

    private static function isCaptchaDirectRequest(): bool
    {
        $uri = $_SERVER['SCRIPT_NAME'] ?? '';
        return substr($uri, -11) === 'captcha.php';
    }

    private static function generateCaptchaCode(int $length = 5): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }
}
