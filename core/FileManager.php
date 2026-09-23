<?php
/**
 * 📁 کلاس مدیریت فایل — آپلود و پردازش امن
 * ==========================================
 * آپلود فایل با اعتبارسنجی کامل نوع/حجم/محتوا،
 * مدیریت پوشه‌ها و حذف امن فایل‌ها.
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class FileManager
{
    /** @var array پسوندهای تصویری مجاز */
    const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

    /** @var array پسوندهای فونت مجاز */
    const FONT_EXTENSIONS = ['ttf', 'otf', 'woff', 'woff2', 'eot'];

    /** @var array پسوندهای دیتای مجاز */
    const DATA_EXTENSIONS = ['json', 'xlsx', 'csv'];

    /** @var array نوع‌های MIME معتبر تصاویر */
    const IMAGE_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
    ];

    /**
     * 📤 آپلود امن فایل تصویری
     *
     * @param array  $file      آرایه $_FILES['name']
     * @param string $subDir    زیرپوشه مقصد (نسبت به uploads)
     * @param int    $maxSize   حداکثر حجم (بایت)
     * @return array ['success' => bool, 'path' => '', 'error' => '']
     */
    public function uploadImage(array $file, string $subDir = 'temp', int $maxSize = 0): array
    {
        $maxSize = $maxSize > 0 ? $maxSize : UPLOAD_MAX_SIZE;

        // ✅ بررسی خطاهای آپلود PHP
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->fail($this->uploadErrorMessage($file['error'] ?? 4));
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return $this->fail('فایل آپلودشده معتبر نیست.');
        }
        if ($file['size'] > $maxSize) {
            return $this->fail('حجم فایل بیش از حد مجاز است (حداکثر ' . round($maxSize / 1048576, 1) . ' مگابایت).');
        }

        // ✅ بررسی پسوند
        $ext = mb_strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return $this->fail('فرمت فایل مجاز نیست. فرمت‌های مجاز: ' . implode('، ', self::IMAGE_EXTENSIONS));
        }

        // ✅ بررسی MIME واقعی محتوا (نه فقط هدر)
        $mime = $this->detectMime($file['tmp_name'], $ext);
        if (!in_array($mime, self::IMAGE_MIMES, true)) {
            return $this->fail('محتوای فایل تصویری معتبر نیست.');
        }

        // ✅ برای SVG بررسی محتوای خطرناک (جلوگیری از XSS)
        if ($ext === 'svg' && $this->svgHasThreats($file['tmp_name'])) {
            return $this->fail('فایل SVG حاوی کد خطرناک است و قابل قبول نیست.');
        }

        // 📁 ذخیره با نام یکتا
        $newName = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dir = UPLOADS_PATH . '/' . trim($subDir, '/');
        $this->ensureDir($dir);

        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $newName)) {
            return $this->fail('خطا در ذخیره فایل روی سرور.');
        }

        return [
            'success' => true,
            'path'    => 'uploads/' . trim($subDir, '/') . '/' . $newName,
            'name'    => $newName,
            'error'   => '',
        ];
    }

    /**
     * 🔤 آپلود فایل فونت
     */
    public function uploadFont(array $file, string $fontDir): array
    {
        if (($file['error'] ?? 4) !== UPLOAD_ERR_OK) {
            return $this->fail($this->uploadErrorMessage($file['error']));
        }
        $ext = mb_strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::FONT_EXTENSIONS, true)) {
            return $this->fail('فرمت فونت مجاز نیست. فرمت‌های مجاز: ' . implode('، ', self::FONT_EXTENSIONS));
        }
        // بررسی امضای فایل فونت
        $signature = $this->fileSignature($file['tmp_name']);
        $validSigs = [
            '00' . '01' . '00' . '00' => 'ttf/otf', // TTF/OTF magic
            '774F4646' => 'woff',                    // wOFF
            '774F4632' => 'woff2',                   // wOF2
        ];
        $hexSig = strtoupper(bin2hex(substr($signature, 0, 4)));
        if (!isset($validSigs[$hexSig])) {
            return $this->fail('محتوای فایل فونت معتبر نیست.');
        }

        $this->ensureDir($fontDir);
        $safeName = preg_replace('/[^a-zA-Z0-9\-_.]/', '', $file['name']) ?: ('font-' . bin2hex(random_bytes(4)));
        if (!move_uploaded_file($file['tmp_name'], $fontDir . '/' . $safeName)) {
            return $this->fail('خطا در ذخیره فونت.');
        }
        return ['success' => true, 'path' => $fontDir . '/' . $safeName, 'name' => $safeName, 'error' => ''];
    }

    /**
     * 📊 آپلود فایل دیتا (JSON / Excel / CSV)
     */
    public function uploadData(array $file): array
    {
        if (($file['error'] ?? 4) !== UPLOAD_ERR_OK) {
            return $this->fail($this->uploadErrorMessage($file['error']));
        }
        if ($file['size'] > UPLOAD_MAX_SIZE) {
            return $this->fail('حجم فایل بیش از حد مجاز است.');
        }
        $ext = mb_strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::DATA_EXTENSIONS, true)) {
            return $this->fail('فرمت مجاز نیست. فرمت‌های مجاز: ' . implode('، ', self::DATA_EXTENSIONS));
        }
        if ($ext === 'json') {
            // اعتبارسنجی JSON
            $content = file_get_contents($file['tmp_name']);
            if (json_decode($content) === null && json_last_error() !== JSON_ERROR_NONE) {
                return $this->fail('فایل JSON معتبر نیست.');
            }
        }
        $newName = 'import-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dir = UPLOADS_PATH . '/temp';
        $this->ensureDir($dir);
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $newName)) {
            return $this->fail('خطا در ذخیره فایل.');
        }
        return ['success' => true, 'path' => $dir . '/' . $newName, 'name' => $newName, 'error' => ''];
    }

    /**
     * 🗑️ حذف امن فایل (فقط داخل مسیر مجاز پروژه)
     */
    public function deleteFile(string $relativePath): bool
    {
        $path = ROOT_PATH . '/' . ltrim($relativePath, '/');
        // 🛡️ جلوگیری از Directory Traversal
        $realPath = realpath($path);
        $rootReal = realpath(ROOT_PATH);
        if ($realPath === false || strpos($realPath, $rootReal) !== 0) {
            return false;
        }
        return @unlink($realPath);
    }

    /**
     * 🗑️ حذف کامل یک پوشه به‌صورت بازگشتی (برای لغو نصب پک آیکون و...)
     *
     * @param string $relativePath مسیر نسبی از روت سایت‌ساز (مثل assets/icons/lucide)
     * @param string $allowedRoot پیشوند مجاز حذف (مثل assets/icons) — لایه امنیتی دوم
     * @return bool موفقیت عملیات
     */
    public function deleteDir(string $relativePath, string $allowedRoot = 'assets/'): bool
    {
        $relativePath = rtrim($relativePath, '/');
        $allowedRoot = rtrim($allowedRoot, '/') . '/';
        // 🛡️ مسیر باید داخل پیشوند مجاز باشد
        if (strpos($relativePath . '/', $allowedRoot) !== 0) {
            return false;
        }
        $path = ROOT_PATH . '/' . ltrim($relativePath, '/');
        $realPath = realpath($path);
        $rootReal = realpath(ROOT_PATH . '/' . rtrim($allowedRoot, '/'));
        if ($realPath === false || $rootReal === false || strpos($realPath, $rootReal) !== 0) {
            return false;
        }
        // 🛡️ هرگز خود روت مجاز حذف نشود
        if ($realPath === $rootReal) {
            return false;
        }
        return $this->rrmdir($realPath);
    }

    /** 🔁 حذف بازگشتی */
    private function rrmdir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . '/' . $item;
            if (is_dir($full) && !is_link($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        return @rmdir($dir);
    }

    /**
     * 📁 دریافت لیست فایل‌های یک پوشه
     */
    public function listFiles(string $dir, string $extension = ''): array
    {
        $path = ROOT_PATH . '/' . ltrim($dir, '/');
        if (!is_dir($path)) {
            return [];
        }
        $files = [];
        foreach (scandir($path) ?: [] as $f) {
            if ($f === '.' || $f === '..' || $f === '.gitkeep' || $f === '.htaccess') {
                continue;
            }
            if ($extension !== '' && mb_strtolower(pathinfo($f, PATHINFO_EXTENSION)) !== $extension) {
                continue;
            }
            $files[] = [
                'name' => $f,
                'size' => filesize($path . '/' . $f),
                'modified' => date('Y-m-d H:i:s', filemtime($path . '/' . $f)),
            ];
        }
        return $files;
    }

    /**
     * 📁 ایجاد پوشه در صورت نبود
     */
    public function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * 📏 حجم پوشه به صورت خوانا
     */
    public function dirSize(string $dir): int
    {
        $size = 0;
        foreach (glob(rtrim($dir, '/') . '/*', GLOB_NOSORT) ?: [] as $item) {
            $size += is_dir($item) ? $this->dirSize($item) : filesize($item);
        }
        return $size;
    }

    /* ==================================================
     * 🛡️ ابزارهای داخلی اعتبارسنجی
     * ================================================== */

    /**
     * 🕵️ تشخیص MIME واقعی فایل (بر اساس محتوا، نه نام)
     */
    private function detectMime(string $tmpPath, string $ext): string
    {
        if ($ext === 'svg') {
            $content = (string)@file_get_contents($tmpPath);
            return stripos($content, '<svg') !== false ? 'image/svg+xml' : 'invalid';
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
            return $mime ?: 'invalid';
        }
        // fallback به getimagesize برای تصاویر
        $info = @getimagesize($tmpPath);
        return $info['mime'] ?? 'invalid';
    }

    /**
     * 🔍 بررسی کدهای خطرناک در SVG (script، event handler، خارجی)
     */
    private function svgHasThreats(string $tmpPath): bool
    {
        $content = (string)@file_get_contents($tmpPath);
        $threats = [
            '<script', 'javascript:', 'onload=', 'onclick=', 'onerror=',
            'onmouseover=', '<foreignObject', 'xlink:href="http', 'href="http',
        ];
        foreach ($threats as $threat) {
            if (stripos($content, $threat) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 🔏 امضای باینری فایل
     */
    private function fileSignature(string $tmpPath): string
    {
        $fh = @fopen($tmpPath, 'rb');
        if (!$fh) {
            return '';
        }
        $sig = (string)fread($fh, 8);
        fclose($fh);
        return $sig;
    }

    /**
     * 📢 پیام خطای قابل فهم برای کدهای error آپلود PHP
     */
    private function uploadErrorMessage(int $code): string
    {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'حجم فایل بیش از حد مجاز سرور است.',
            UPLOAD_ERR_FORM_SIZE  => 'حجم فایل بیش از حد مجاز فرم است.',
            UPLOAD_ERR_PARTIAL    => 'فایل به صورت ناقص آپلود شد.',
            UPLOAD_ERR_NO_FILE    => 'فایلی انتخاب نشده است.',
            UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت سرور یافت نشد.',
            UPLOAD_ERR_CANT_WRITE => 'خطای نوشتن فایل روی دیسک.',
            UPLOAD_ERR_EXTENSION  => 'آپلود فایل توسط افزونه PHP متوقف شد.',
        ];
        return $messages[$code] ?? 'خطای نامشخص در آپلود فایل.';
    }

    /**
     * ⛔ پاسخ خطای استاندارد
     */
    private function fail(string $message): array
    {
        return ['success' => false, 'path' => '', 'name' => '', 'error' => $message];
    }
}
