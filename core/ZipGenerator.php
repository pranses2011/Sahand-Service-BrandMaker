<?php
/**
 * 📦 کلاس تولید فایل ZIP — بسته‌بندی سایت برند
 * =============================================
 * هسته سایت برند را با جایگذاری متغیرها (نام برند، دامنه،
 * کلید API و پالت رنگ) بسته‌بندی و آماده دانلود می‌کند.
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class ZipGenerator
{
    /** @var array متغیرهایی که در فایل‌ها جایگذاری می‌شوند */
    private $replacements = [];

    /** @var string پسوندهای فایلی که جایگذاری در آن‌ها انجام می‌شود */
    const TEXT_EXTENSIONS = ['php', 'css', 'js', 'json', 'txt', 'xml', 'htaccess', 'html'];

    /**
     * 🎛️ تنظیم متغیرهای جایگذاری
     *
     * @param array $replacements آرایه کلید => مقدار (مثلاً {{BRAND_NAME_FA}})
     */
    public function setReplacements(array $replacements): void
    {
        $this->replacements = $replacements;
    }

    /**
     * 📦 ساخت فایل ZIP از یک پوشه قالب با جایگذاری متغیرها
     *
     * @param string $sourceDir مسیر پوشه قالب (مثلاً templates/brand-core)
     * @param string $outputDir مسیر خروجی (مثلاً uploads/temp)
     * @param string $zipName   نام فایل خروجی بدون پسوند
     * @return array ['success' => bool, 'path' => '', 'error' => '']
     */
    public function package(string $sourceDir, string $outputDir, string $zipName): array
    {
        // ✅ بررسی موجود بودن افزونه ZipArchive
        if (!class_exists('ZipArchive')) {
            return $this->fail('افزونه ZipArchive روی سرور فعال نیست. با پشتیبانی هاست تماس بگیرید.');
        }
        if (!is_dir($sourceDir)) {
            return $this->fail('پوشه قالب یافت نشد: ' . $sourceDir);
        }

        // 📁 آماده‌سازی پوشه خروجی
        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0755, true);
        }
        $zipPath = rtrim($outputDir, '/') . '/' . preg_replace('/[^\w\-]/', '', $zipName) . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return $this->fail('خطا در ایجاد فایل ZIP.');
        }

        // 🔄 پیمایش بازگشتی پوشه قالب
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $rootLen = strlen(rtrim($sourceDir, '/')) + 1;
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            $filePath = $file->getPathname();
            $relative = substr($filePath, $rootLen);

            // 🚫 رد کردن فایل‌های سیستسی
            $basename = basename($filePath);
            if ($basename === '.gitkeep' || strpos($basename, '.bak') !== false) {
                continue;
            }

            // فایل‌های htaccess خاص (نام فایل دقیق .htaccess)
            $ext = $this->extensionOf($relative);
            $inZipName = $relative;
            if ($basename === 'htaccess.template') {
                $inZipName = dirname($relative) === '.' ? '.htaccess' : dirname($relative) . '/.htaccess';
            }

            // 📝 در فایل‌های متنی، جایگذاری متغیرها انجام می‌شود
            if (in_array($ext, self::TEXT_EXTENSIONS, true) || $basename === 'htaccess.template') {
                $content = (string)file_get_contents($filePath);
                $content = strtr($content, $this->replacements);
                $zip->addFromString($inZipName, $content);
            } else {
                // فایل‌های باینری مستقیماً اضافه می‌شوند
                $zip->addFile($filePath, $inZipName);
            }
        }

        $zip->close();

        return [
            'success' => true,
            'path'    => $zipPath,
            'size'    => filesize($zipPath),
            'error'   => '',
        ];
    }

    /**
     * 🧹 حذف فایل‌های ZIP قدیمی‌تر از یک ساعت (پاکسازی دوره‌ای)
     */
    public function cleanupOld(string $dir, int $olderThanSeconds = 3600): void
    {
        foreach (glob(rtrim($dir, '/') . '/*.zip') ?: [] as $zip) {
            if (filemtime($zip) < time() - $olderThanSeconds) {
                @unlink($zip);
            }
        }
    }

    /**
     * 📤 ارسال فایل ZIP به مرورگر (دانلود)
     */
    public static function download(string $filePath, string $downloadName = ''): void
    {
        if (!file_exists($filePath)) {
            http_response_code(404);
            exit('فایل یافت نشد.');
        }
        $name = $downloadName !== '' ? $downloadName : basename($filePath);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-store');
        readfile($filePath);
        exit;
    }

    /**
     * 🔤 تشخیص پسوند فایل
     */
    private function extensionOf(string $filename): string
    {
        $ext = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        // فایل بدون پسوند ولی نامدار مثل htaccess
        return $ext;
    }

    /**
     * ⛔ پاسخ خطا
     */
    private function fail(string $message): array
    {
        return ['success' => false, 'path' => '', 'size' => 0, 'error' => $message];
    }
}
