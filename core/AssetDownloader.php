<?php
/**
 * ⬇️ دانلودر مستقیم منابع — نصب فونت و آیکون روی سرور سایت‌ساز
 * ================================================================
 * به‌جای دانلود روی کامپیوتر کاربر و آپلود مجدد، این کلاس فایل‌ها را
 * مستقیم از منابع رسمی (jsDelivr / npm / GitHub) روی «همان سرور»
 * دانلود و در پوشه استاندارد خودش نصب می‌کند.
 *
 * 🔒 امنیت:
 *   - وایت‌لیست میزبان‌ها (هیچ URL دلخواهی پذیرفته نمی‌شود)
 *   - سقف حجم هر فایل و هر آرشیو
 *   - پاک‌سازی SVG (حذف script و رویدادهای on و javascript) — ضد XSS
 *   - نام فایل امن‌سازی‌شده (بدون پیمایش مسیر)
 *
 * @package SahandBrandMaker\Core
 * @version 1.0.0
 */
class AssetDownloader
{
    /** @var array میزبان‌های مجاز برای دانلود */
    private const ALLOWED_HOSTS = [
        'cdn.jsdelivr.net',
        'registry.npmjs.org',
        'codeload.github.com',
        'fonts.gstatic.com',
        'fonts.googleapis.com',
        'raw.githubusercontent.com',
    ];

    /** @var int سقف حجم هر فایل تکی (بایت) — ۱۲ مگابایت */
    private const MAX_FILE_BYTES = 12582912;

    /** @var int سقف حجم آرشیو آیکون (بایت) — ۶۴ مگابایت */
    private const MAX_ARCHIVE_BYTES = 67108864;

    /** @var int سقف تعداد آیکون نصب‌شده در هر پک */
    private const MAX_ICONS_PER_PACK = 6000;

    /** @var int مهلت دانلود (ثانیه) */
    private const TIMEOUT = 240;

    /** @var string User-Agent برای CDN ها */
    private const UA = 'Mozilla/5.0 (X11; Linux x86_64) SahandBrandMaker-AssetDownloader/1.0';

    /* ==================================================
     * 📚 بارگذاری نقشه منابع
     * ================================================== */

    /**
     * 📖 نقشه منابع فونت‌ها — assets/fonts/sources.json
     * ساختار: { "fa": { "vazirmatn": { "name": "...", "weights": { "bold": "URL" }, ... } }, "en": {...} }
     */
    public static function fontSources(): array
    {
        $file = ASSETS_PATH . '/fonts/sources.json';
        if (!file_exists($file)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /**
     * 📖 نقشه منابع پک‌های آیکون — assets/icons/sources.json
     * ساختار: هر پک شامل archive (آدرس زیپ)، include (الگوهای مسیر SVG) و prefix_map است.
     */
    public static function iconSources(): array
    {
        $file = ASSETS_PATH . '/icons/sources.json';
        if (!file_exists($file)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /**
     * 🔎 آیا فونت منبع دانلود خودکار دارد؟
     */
    public function fontHasSource(string $type, string $slug): bool
    {
        $sources = self::fontSources();
        return !empty($sources[$type][$slug]['weights']);
    }

    /**
     * 🔎 آیا پک آیکون منبع دانلود خودکار دارد؟
     */
    public function iconPackHasSource(string $slug): bool
    {
        $sources = self::iconSources();
        return !empty($sources[$slug]['archive']);
    }

    /* ==================================================
     * 🔤 نصب فونت — دانلود مستقیم وزن‌ها
     * ================================================== */

    /**
     * ⬇️ دانلود و نصب فونت روی سرور
     *
     * @param string $type نوع فونت (fa|en)
     * @param string $slug اسلاگ فونت (مثل vazirmatn)
     * @param bool   $onlyMissing فقط وزن‌های غایب دانلود شود
     * @return array ['success' => bool, 'message' => string, 'installed' => [], 'failed' => []]
     */
    public function downloadFont(string $type, string $slug, bool $onlyMissing = true): array
    {
        $type = $type === 'en' ? 'en' : 'fa';
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            return ['success' => false, 'message' => 'اسلاگ فونت نامعتبر است.', 'installed' => [], 'failed' => []];
        }

        $sources = self::fontSources();
        $entry = $sources[$type][$slug] ?? null;
        if (!$entry || empty($entry['weights'])) {
            return ['success' => false, 'message' => 'برای این فونت منبع دانلود خودکار ثبت نشده است؛ از آپلود دستی استفاده کنید.', 'installed' => [], 'failed' => []];
        }

        $fontDir = ASSETS_PATH . '/fonts/' . $type . '/' . $slug;
        if (!is_dir($fontDir) && !@mkdir($fontDir, 0755, true)) {
            return ['success' => false, 'message' => 'ساخت پوشه فونت روی سرور ناموفق بود (دسترسی نوشتن را بررسی کنید).', 'installed' => [], 'failed' => []];
        }

        // 🧹 حذف محدودیت زمان اجرا برای دانلود وزن‌های متعدد
        @set_time_limit(600);

        $installed = [];
        $failed = [];
        foreach ($entry['weights'] as $weight => $url) {
            $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
            if (!in_array($ext, ['woff2', 'woff', 'ttf', 'otf'], true)) {
                $ext = 'woff2';
            }
            $target = $fontDir . '/' . $slug . '-' . $weight . '.' . $ext;

            // 💾 اگر فایل موجود است و فقط وزن‌های غایب خواسته شده
            if ($onlyMissing) {
                $exists = false;
                foreach (['woff2', 'ttf', 'woff', 'otf'] as $e) {
                    if (file_exists($fontDir . '/' . $slug . '-' . $weight . '.' . $e)) {
                        $exists = true;
                        break;
                    }
                }
                if ($exists) {
                    continue;
                }
            }

            $res = $this->fetch($url);
            if (!$res['ok']) {
                $failed[$weight] = $res['error'];
                continue;
            }
            if (strlen($res['body']) < 1024) {
                $failed[$weight] = 'پاسخ سرور منبع معتبر نبود (فایل ناقص).';
                continue;
            }
            if (@file_put_contents($target, $res['body']) === false) {
                $failed[$weight] = 'نوشتن فایل روی دیسک ناموفق بود (دسترسی پوشه).';
                continue;
            }
            $installed[$weight] = strlen($res['body']);
        }

        $n = count($installed);
        $f = count($failed);
        if ($n > 0 && $f === 0) {
            $message = "✅ {$n} وزن فونت «{$entry['name']}» مستقیم روی سرور دانلود و نصب شد.";
        } elseif ($n > 0) {
            $message = "⚠️ {$n} وزن نصب شد؛ {$f} وزن ناموفق: " . implode('، ', array_keys($failed));
        } else {
            $message = '❌ هیچ وزنی دانلود نشد. ' . ($f > 0 ? 'خطا: ' . reset($failed) : 'همه وزن‌ها از قبل نصب شده‌اند.');
        }

        return [
            'success'  => $n > 0,
            'message'  => $message,
            'installed'=> $installed,
            'failed'   => $failed,
        ];
    }

    /**
     * ⬇️⬇️ دانلود گروهی «همه فونت‌های آزاد» روی سرور
     * ==================================================
     * همه فونت‌هایی که در sources.json منبع ثبت‌شده دارند را پشت‌سرهم
     * نصب می‌کند (وزن‌های موجود رد می‌شوند — فقط غایب‌ها دانلود می‌شوند).
     *
     * @param bool $onlyMissing اگر true باشد فقط وزن‌های غایب دانلود می‌شوند
     * @return array ['success'=>bool,'message'=>string,'installed'=>int,'failed'=>array]
     */
    public function downloadAllFreeFonts(bool $onlyMissing = true): array
    {
        @set_time_limit(0);          // 🧹 دانلود گروهی ممکن است طولانی شود
        @ignore_user_abort(true);     // ادامه حتی اگر تب مرورگر بسته شد

        $sources = self::fontSources();
        $totalInstalled = 0;
        $totalFailed = [];   // «فونت/وزن: خطا»
        $fontsDone = 0;

        foreach (['fa', 'en'] as $type) {
            foreach ($sources[$type] ?? [] as $slug => $entry) {
                if (empty($entry['weights'])) {
                    continue;
                }
                $res = $this->downloadFont($type, $slug, $onlyMissing);
                $fontsDone++;
                foreach ($res['installed'] ?? [] as $weight => $bytes) {
                    $totalInstalled++;
                }
                foreach ($res['failed'] ?? [] as $weight => $err) {
                    $totalFailed[] = $type . '/' . $slug . '-' . $weight . ': ' . $err;
                }
            }
        }

        $n = $totalInstalled;
        $f = count($totalFailed);
        if ($n > 0 && $f === 0) {
            $message = "✅ دانلود گروهی کامل شد: {$n} وزن فایل جدید از {$fontsDone} فونت آزاد، مستقیم روی سرور نصب شد.";
        } elseif ($n > 0) {
            $message = "⚠️ دانلود گروهی: {$n} وزن نصب شد؛ {$f} مورد ناموفق:\n" . implode("\n", array_slice($totalFailed, 0, 10));
        } else {
            $message = 'ℹ️ هیچ فایل جدیدی دانلود نشد — همه فونت‌های آزاد از قبل روی سرور نصب‌اند.' . ($f > 0 ? " ({$f} خطای جزئی)" : '');
        }

        return [
            'success'  => $f === 0,
            'message'  => $message,
            'installed'=> $totalInstalled,
            'failed'   => $totalFailed,
            'fonts'    => $fontsDone,
        ];
    }

    /* ==================================================
     * 🖼️ نصب پک آیکون — دانلود آرشیو و استخراج SVG
     * ================================================== */

    /**
     * ⬇️ دانلود و نصب کامل پک آیکون روی سرور
     *
     * @param string $slug اسلاگ پک (مثل lucide-icons)
     * @param bool   $replace جایگزینی کامل آیکون‌های قبلی
     * @return array ['success' => bool, 'message' => string, 'installed' => int]
     */
    public function installIconPack(string $slug, bool $replace = true): array
    {
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            return ['success' => false, 'message' => 'نام پک نامعتبر است.', 'installed' => 0];
        }

        $sources = self::iconSources();
        $cfg = $sources[$slug] ?? null;
        if (!$cfg || empty($cfg['archive'])) {
            return ['success' => false, 'message' => 'برای این پک منبع دانلود خودکار ثبت نشده است؛ از ایمپورت ZIP استفاده کنید.', 'installed' => 0];
        }

        $packDir = ASSETS_PATH . '/icons/' . $slug;
        if (!is_dir($packDir) && !@mkdir($packDir, 0755, true)) {
            return ['success' => false, 'message' => 'ساخت پوشه پک ناموفق بود (دسترسی نوشتن).', 'installed' => 0];
        }

        @set_time_limit(900);

        /* ---------- ۱) دانلود آرشیو در فایل موقت ---------- */
        $isTarGz = (bool)preg_match('/\.tgz$|\.tar\.gz$/i', $cfg['archive']);
        $res = $this->fetchToFile($cfg['archive'], self::MAX_ARCHIVE_BYTES, $isTarGz);
        if (!$res['ok']) {
            return ['success' => false, 'message' => 'دانلود آرشیو ناموفق: ' . $res['error'], 'installed' => 0];
        }
        $archivePath = $res['path'];

        /* ---------- ۲) باز کردن آرشیو ---------- */
        $entries = $isTarGz ? $this->tarEntries($archivePath) : $this->zipEntries($archivePath);
        if ($entries === null) {
            @unlink($archivePath);
            return ['success' => false, 'message' => 'آرشیو قابل بازکردن نیست (فرمت پشتیبانی نمی‌شود).', 'installed' => 0];
        }

        /* ---------- ۳) فیلتر SVG ها بر اساس الگوها ---------- */
        $include = $cfg['include'] ?? ['*.svg'];
        $exclude = $cfg['exclude'] ?? ['*/.github/*', '*/docs/*', '*/test*/*', '*/src/*', '*/js-packages/*'];
        $prefixMap = $cfg['prefix_map'] ?? [];   // مثل {"solid/": "solid-", "brands/": "brand-"}
        $categoryMap = $cfg['category_map'] ?? []; // مثل {"System": "system", "Media": "media"}

        $maxIcons = max(100, (int)($cfg['max_icons'] ?? self::MAX_ICONS_PER_PACK));

        $targets = [];
        foreach ($entries as $name => $content) {
            if (!preg_match('/\.svg$/i', $name)) {
                continue;
            }
            if (!$this->matchAny($name, $include)) {
                continue;
            }
            if ($this->matchAny($name, $exclude)) {
                continue;
            }
            $targets[$name] = $content;
        }
        if (empty($targets)) {
            @unlink($archivePath);
            return ['success' => false, 'message' => 'هیچ SVG منطبقی در آرشیو یافت نشد (الگوهای sources.json را بررسی کنید).', 'installed' => 0];
        }

        /* ---------- ۴) پاک‌سازی قبلی (در حالت جایگزینی) ---------- */
        if ($replace) {
            foreach (glob($packDir . '/*.svg') ?: [] as $old) {
                @unlink($old);
            }
        }
        $existingFiles = [];
        foreach (glob($packDir . '/*.svg') ?: [] as $f) {
            $existingFiles[basename($f)] = true;
        }

        /* ---------- ۵) استخراج + پاک‌سازی امن + نام‌گذاری ---------- */
        $installed = 0;
        $skipped = 0;
        $manifestIcons = [];
        foreach ($targets as $name => $content) {
            if ($installed >= $maxIcons) {
                break;
            }
            $base = basename($name);
            $safeBase = $this->safeFileName($base);
            if ($safeBase === '') {
                continue;
            }
            // پیشوند بر اساس پوشه داخل آرشیو (مثل solid-/brand- برای FA)
            $outName = $safeBase;
            foreach ($prefixMap as $dirNeedle => $outPrefix) {
                if (mb_strpos($name, $dirNeedle) !== false) {
                    $outName = $outPrefix . $safeBase;
                    break;
                }
            }
            if (isset($existingFiles[$outName]) && !$replace) {
                $skipped++;
                continue;
            }
            // 🔒 پاک‌سازی امن SVG
            $clean = $this->sanitizeSvg($content);
            if ($clean === null) {
                continue; // حاوی اسکریپت — رد شد
            }
            if (@file_put_contents($packDir . '/' . $outName, $clean) === false) {
                continue;
            }
            $iconName = pathinfo($outName, PATHINFO_FILENAME);
            $category = 'general';
            foreach ($categoryMap as $dirNeedle => $cat) {
                if (mb_strpos($name, $dirNeedle) !== false) {
                    $category = $cat;
                    break;
                }
            }
            $manifestIcons[$iconName] = [
                'name'      => $iconName,
                'label_fa'  => self::faLabel($iconName),
                'file'      => $outName,
                'category'  => $category,
            ];
            $installed++;
        }
        @unlink($archivePath);

        if ($installed === 0) {
            return ['success' => false, 'message' => 'هیچ آیکونی نصب نشد (همه رد یا ناامن بودند).', 'installed' => 0];
        }

        /* ---------- ۶) به‌روزرسانی مانیفست ---------- */
        $manifestFile = $packDir . '/manifest.json';
        $manifest = file_exists($manifestFile)
            ? (json_decode((string)file_get_contents($manifestFile), true) ?: [])
            : [];
        // حفظ متادیتای فارسی موجود
        $manifest['pack'] = $manifest['pack'] ?? $slug;
        $manifest['name_fa'] = $manifest['name_fa'] ?? ($cfg['name_fa'] ?? $slug);
        $manifest['description'] = $manifest['description'] ?? ($cfg['description'] ?? '');
        if (!empty($cfg['import_hint'])) {
            $manifest['import_hint'] = $cfg['import_hint'];
        }
        // ادغام با آیکون‌های قبلی (در حالت جایگزینی، لیست تازه کافی است)
        if (!$replace && !empty($manifest['icons'])) {
            foreach ($manifest['icons'] as $old) {
                if (is_array($old) && empty($manifestIcons[$old['name'] ?? ''])) {
                    $manifestIcons[$old['name']] = $old;
                }
            }
        }
        $manifest['icons'] = array_values($manifestIcons);
        $manifest['icon_count'] = count($manifestIcons);
        $manifest['installed_at'] = date('Y-m-d H:i:s');
        $manifest['installed_from'] = $cfg['archive'];
        @file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $message = "✅ «{$manifest['name_fa']}»: " . en_to_fa_digits((string)$installed) . ' آیکون مستقیم روی سرور دانلود و نصب شد'
            . ($skipped > 0 ? ' (' . en_to_fa_digits((string)$skipped) . ' مورد از قبل موجود بود)' : '') . '.';
        return ['success' => true, 'message' => $message, 'installed' => $installed];
    }

    /* ==================================================
     * 🌐 لایه دانلود امن
     * ================================================== */

    /**
     * 🌐 دانلود به حافظه با اعتبارسنجی میزبان
     * @return array ['ok' => bool, 'body' => string, 'error' => ?string]
     */
    private function fetch(string $url): array
    {
        if (!$this->isAllowed($url)) {
            return ['ok' => false, 'body' => '', 'error' => 'میزبان این URL در وایت‌لیست نیست.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_MAXFILESIZE    => self::MAX_FILE_BYTES,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch) ?: null;
        curl_close($ch);

        if (!is_string($body) || $body === '') {
            return ['ok' => false, 'body' => '', 'error' => $error ?: ("HTTP {$status}")];
        }
        if ($status >= 400) {
            return ['ok' => false, 'body' => '', 'error' => "سرور منبع با کد {$status} پاسخ داد."];
        }
        if (strlen($body) > self::MAX_FILE_BYTES) {
            return ['ok' => false, 'body' => '', 'error' => 'حجم فایل بیش از حد مجاز است.'];
        }
        return ['ok' => true, 'body' => $body, 'error' => null];
    }

    /**
     * 🌐 دانلود بزرگ به فایل موقت (آرشیوها)
     * @return array ['ok' => bool, 'path' => string, 'error' => ?string]
     */
    private function fetchToFile(string $url, int $maxBytes, bool $tarGz = false): array
    {
        if (!$this->isAllowed($url)) {
            return ['ok' => false, 'path' => '', 'error' => 'میزبان این URL در وایت‌لیست نیست.'];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'bmasset');
        if ($tmp === false) {
            return ['ok' => false, 'path' => '', 'error' => 'ساخت فایل موقت ناموفق بود.'];
        }
        // 📦 PharData به پسوند شناخته‌شده نیاز دارد — افزودن پسوند مناسب
        if ($tarGz) {
            $renamed = $tmp . '.tgz';
            if (!@rename($tmp, $renamed)) {
                @unlink($tmp);
                return ['ok' => false, 'path' => '', 'error' => 'آماده‌سازی فایل موقت ناموفق بود.'];
            }
            $tmp = $renamed;
        }
        $fp = fopen($tmp, 'wb');
        if (!$fp) {
            @unlink($tmp);
            return ['ok' => false, 'path' => '', 'error' => 'بازکردن فایل موقت ناموفق بود.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE            => $fp,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 4,
            CURLOPT_CONNECTTIMEOUT  => 20,
            CURLOPT_TIMEOUT         => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_USERAGENT       => self::UA,
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch) ?: null;
        $size = (int)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        curl_close($ch);
        fclose($fp);

        if ($status >= 400 || ($status === 0 && $error)) {
            @unlink($tmp);
            return ['ok' => false, 'path' => '', 'error' => $error ?: ("HTTP {$status}")];
        }
        if ($size < 1024) {
            @unlink($tmp);
            return ['ok' => false, 'path' => '', 'error' => 'پاسخ منبع معتبر نبود (حجم صفر).'];
        }
        if (filesize($tmp) > $maxBytes) {
            @unlink($tmp);
            return ['ok' => false, 'path' => '', 'error' => 'حجم آرشیو بیش از حد مجاز است.'];
        }
        return ['ok' => true, 'path' => $tmp, 'error' => null];
    }

    /**
     * 🔒 آیا URL مجاز است؟ (وایت‌لیست میزبان + https)
     */
    private function isAllowed(string $url): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return ($scheme === 'https' || $scheme === 'http') && in_array($host, self::ALLOWED_HOSTS, true);
    }

    /**
     * 📦 خواندن محتوای ZIP → [name => content]
     */
    private function zipEntries(string $path): ?array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!preg_match('/\.svg$/i', (string)$name)) {
                continue;
            }
            $content = $zip->getFromIndex($i);
            if (is_string($content)) {
                $out[$name] = $content;
            }
        }
        $zip->close();
        return $out;
    }

    /**
     * 📦 خواندن محتوای TAR.GZ (npm) → [name => content]
     */
    private function tarEntries(string $path): ?array
    {
        try {
            $phar = new PharData($path);
            $out = [];
            foreach (new RecursiveIteratorIterator($phar) as $file) {
                $name = $file->getPathname();
                // تبدیل مسیر phar به مسیر نسبی آرشیو
                $name = str_replace('phar://' . $path . '/', '', $name);
                if (!preg_match('/\.svg$/i', (string)$name)) {
                    continue;
                }
                $content = @file_get_contents($file->getPathname());
                if (is_string($content)) {
                    $out[$name] = $content;
                }
            }
            return $out;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * 🎯 تطبیق نام فایل با الگوهای glob ساده
     */
    private function matchAny(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $name) || fnmatch($pattern, basename($name))) {
                return true;
            }
        }
        return false;
    }

    /**
     * 🧹 نام فایل امن (فقط حروف/عدد/خط تیره/نقطه)
     */
    private function safeFileName(string $name): string
    {
        $name = basename($name);
        $clean = preg_replace('/[^A-Za-z0-9._\-]/', '', $name) ?? '';
        // جلوگیری از پیمایش مسیر
        if (str_contains($clean, '..')) {
            return '';
        }
        return $clean;
    }

    /**
     * 🛡️ پاک‌سازی امن SVG — null یعنی فایل خطرناک است
     */
    private function sanitizeSvg(string $svg): ?string
    {
        // بررسی‌های سخت‌گیرانه امنیتی
        if (stripos($svg, '<script') !== false
            || stripos($svg, 'javascript:') !== false
            || stripos($svg, 'onload=') !== false
            || preg_match('/\son[a-z]+\s*=/i', $svg)
            || stripos($svg, '<foreignObject') !== false
            || stripos($svg, 'xlink:href="data:') !== false
        ) {
            return null;
        }
        return $svg;
    }

    /* ==================================================
     * 🏷️ دیکشنری برچسب فارسی آیکون‌ها
     * ================================================== */

    /**
     * 🏷️ برچسب فارسی نام آیکون — با حذف پیشوندها و تطبیق هوشمند
     */
    public static function faLabel(string $name): string
    {
        static $dict = null;
        if ($dict === null) {
            $dict = self::iconDictionary();
        }
        $name = strtolower($name);
        // نرمال‌سازی نام: زیرخط متریال → خط تیره + حذف پسوند -line/-fill رمیکس
        $normalized = str_replace('_', '-', $name);
        $normalized = preg_replace('/-(line|fill)$/', '', $normalized) ?? $normalized;
        // تطبیق کامل (با نام اصلی و نرمال‌شده)
        foreach ([$name, $normalized] as $candidate) {
            if (isset($dict[$candidate])) {
                return $dict[$candidate];
            }
        }
        // حذف پیشوندهای رایج پک‌ها و تلاش مجدد
        $stripped = preg_replace('/^(bx|bxs|bxl|ri|md|fa|hi|ph|tabler|icon)[-_]/', '', $normalized) ?? $normalized;
        $stripped = preg_replace('/-(line|fill)$/', '', $stripped) ?? $stripped;
        if (isset($dict[$stripped])) {
            return $dict[$stripped];
        }
        // 🏷️ قانون عمومی لوگوهای برند: brand-xxx → «لوگوی xxx»
        if (preg_match('/^brand-([a-z0-9\-]+)/', $normalized, $m)) {
            return 'لوگوی ' . $m[1];
        }
        // تطبیق جزئی روی کلیدهای مرکب (مثل arrow-right)
        foreach ($dict as $key => $label) {
            if (str_contains($key, '-') && str_contains($normalized, $key)) {
                return $label;
            }
        }
        return $stripped;
    }

    /**
     * 📖 دیکشنری فارسی نام‌های رایج آیکون (حدود ۲۰۰ مورد)
     */
    private static function iconDictionary(): array
    {
        return [
            // خانه و عمومی
            'home' => 'خانه', 'house' => 'خانه', 'settings' => 'تنظیمات', 'user' => 'کاربر',
            'users' => 'کاربران', 'star' => 'ستاره', 'heart' => 'قلب', 'mail' => 'ایمیل',
            'phone' => 'تلفن', 'phone-call' => 'تماس تلفنی', 'calendar' => 'تقویم', 'clock' => 'ساعت',
            'search' => 'جستجو', 'cart' => 'سبد خرید', 'shopping-cart' => 'سبد خرید',
            'wrench' => 'آچار', 'tools' => 'ابزار', 'tool' => 'ابزار', 'toolbox' => 'جعبه ابزار',
            'map-pin' => 'موقعیت مکانی', 'pin' => 'پین', 'location' => 'موقعیت',
            'chevron-down' => 'فلش پایین', 'chevron-up' => 'فلش بالا', 'chevron-left' => 'فلش چپ',
            'chevron-right' => 'فلش راست', 'arrow-right' => 'فلش راست', 'arrow-left' => 'فلش چپ',
            'arrow-up' => 'فلش بالا', 'arrow-down' => 'فلش پایین', 'check' => 'تیک تأیید',
            'check-circle' => 'تأیید', 'x' => 'بستن', 'close' => 'بستن', 'plus' => 'افزودن',
            'minus' => 'کم کردن', 'menu' => 'منو', 'bell' => 'زنگ اعلان', 'camera' => 'دوربین',
            'image' => 'تصویر', 'video' => 'ویدیو', 'music' => 'موسیقی', 'mic' => 'میکروفون',
            'headphones' => 'هدفون', 'wifi' => 'وای‌فای', 'bluetooth' => 'بلوتوث',
            'battery' => 'باتری', 'battery-charging' => 'شارژ باتری', 'power' => 'روشن/خاموش',
            'lock' => 'قفل', 'unlock' => 'بازکردن قفل', 'key' => 'کلید', 'shield' => 'محافظ',
            'shield-check' => 'ضمانت', 'eye' => 'نمایش', 'eye-off' => 'مخفی کردن',
            'download' => 'دانلود', 'upload' => 'بارگذاری', 'trash' => 'سطل زباله',
            'edit' => 'ویرایش', 'pen' => 'قلم', 'save' => 'ذخیره', 'print' => 'چاپ',
            'copy' => 'کپی', 'clipboard' => 'کلیپ‌بورد', 'file' => 'فایل', 'folder' => 'پوشه',
            'book' => 'کتاب', 'tag' => 'برچسب', 'credit-card' => 'کارت بانکی',
            'wallet' => 'کیف پول', 'gift' => 'هدیه', 'box' => 'جعبه', 'package' => 'بسته',
            'truck' => 'کامین ارسال', 'building' => 'ساختمان', 'store' => 'فروشگاه',
            'coffee' => 'قهوه', 'award' => 'نشان افتخار', 'trophy' => 'جام',
            'thumbs-up' => 'پسندیدن', 'info' => 'اطلاعات', 'help' => 'راهنما',
            'alert' => 'هشدار', 'alert-triangle' => 'هشدار', 'refresh' => 'بروزرسانی',
            'rotate' => 'چرخش', 'repeat' => 'تکرار', 'play' => 'پخش', 'pause' => 'توقف',
            'stop' => 'ایست', 'forward' => 'جلو', 'rewind' => 'عقب', 'volume' => 'صدا',
            'muted' => 'بی‌صدا', 'globe' => 'وب', 'link' => 'لینک', 'share' => 'اشتراک‌گذاری',
            'chat' => 'گفتگو', 'message' => 'پیام', 'message-circle' => 'چت',
            'send' => 'ارسال', 'inbox' => 'صندوق ورودی', 'at-sign' => 'ایمیل',
            'smartphone' => 'موبایل', 'tablet' => 'تبلت', 'laptop' => 'لپ‌تاپ',
            'monitor' => 'مانیتور', 'keyboard' => 'کیبورد', 'mouse' => 'ماوس',
            'printer' => 'چاپگر', 'cpu' => 'پردازنده', 'hard-drive' => 'هارد دیسک',
            'memory' => 'حافظه', 'plug' => 'پریز برق', 'zap' => 'برق', 'bolt' => 'برق',
            'flash' => 'فلاش', 'cable' => 'کابل',
            // لوازم خانگی و تعمیرات
            'refrigerator' => 'یخچال', 'fridge' => 'یخچال', 'washing-machine' => 'ماشین لباسشویی',
            'washer' => 'ماشین لباسشویی', 'dishwasher' => 'ماشین ظرفشویی',
            'air-conditioner' => 'کولر گازی', 'ac-unit' => 'کولر گازی', 'fan' => 'پنکه',
            'snowflake' => 'برف/سرما', 'flame' => 'شعله', 'fire' => 'آتش', 'droplet' => 'قطره آب',
            'droplets' => 'آب', 'thermometer' => 'دماسنج', 'gauge' => 'فشارسنج',
            'tv' => 'تلویزیون', 'television' => 'تلویزیون', 'microwave' => 'مایکروویو',
            'oven' => 'فر', 'cooking-pot' => 'قابلمه', 'kettle' => 'کتری', 'blender' => 'مخلوط‌کن',
            'vacuum-cleaner' => 'جاروبرقی', 'iron' => 'اتو', 'heater' => 'بخاری',
            'radiator' => 'رادیاتور', 'shower' => 'دوش', 'bath' => 'حمام', 'bathroom' => 'سرویس بهداشتی',
            'kitchen' => 'آشپزخانه', 'cook' => 'آشپزی',
            // ابزار فنی
            'screwdriver' => 'پیچ‌گوشتی', 'hammer' => 'چکش', 'drill' => 'دریل',
            'saw' => 'اره', 'ruler' => 'خط‌کش', 'puzzle' => 'پازل/تطبیق',
            'git-branch' => 'شاخه', 'git-commit' => 'کامیت', 'git-merge' => 'ادغام',
            'terminal' => 'ترمینال', 'code' => 'کد', 'database' => 'پایگاه داده',
            'server' => 'سرور', 'cloud' => 'ابر', 'layers' => 'لایه‌ها',
            'sliders' => 'تنظیمات لغزنده', 'filter' => 'فیلتر', 'sort' => 'مرتب‌سازی',
            'grid' => 'شبکه', 'list' => 'لیست', 'columns' => 'ستون‌ها', 'layout' => 'چیدمان',
            'maximize' => 'بیشینه', 'minimize' => 'کمینه', 'expand' => 'بازکردن',
            'zoom-in' => 'بزرگ‌نمایی', 'zoom-out' => 'کوچک‌نمایی', 'focus' => 'تمرکز',
            'target' => 'هدف', 'crosshair' => 'نقطه هدف', 'compass' => 'قطب‌نما',
            'navigation' => 'ناوبری', 'route' => 'مسیر', 'map' => 'نقشه', 'flag' => 'پرچم',
            'bookmark' => 'نشان‌گذاری', 'paperclip' => 'گیره کاغذ', 'sticky-note' => 'یادداشت',
            'notebook' => 'دفترچه', 'newspaper' => 'روزنامه', 'megaphone' => 'بلندگو',
            'radio' => 'رادیو', 'antenna' => 'آنتن', 'signal' => 'سیگنال', 'rss' => 'فید',
            'wifi-off' => 'قطع وای‌فای', 'cloud-rain' => 'باران', 'cloud-snow' => 'برف',
            'sun' => 'خورشید', 'moon' => 'ماه', 'cloud-sun' => 'نیمه ابری', 'wind' => 'باد',
            'umbrella' => 'چتر', 'sunglasses' => 'عینک آفتابی',
            // مالی و کسب‌وکار
            'dollar' => 'دلار', 'dollar-sign' => 'دلار', 'coins' => 'سکه‌ها',
            'banknote' => 'اسکناس', 'receipt' => 'رسید', 'invoice' => 'صورت‌حساب',
            'briefcase' => 'کیف کار', 'handshake' => 'معامله', 'hand-coins' => 'پرداخت',
            'piggy-bank' => 'قوطی پس‌انداز', 'trending-up' => 'رشد', 'trending-down' => 'افت',
            'bar-chart' => 'نمودار ستونی', 'line-chart' => 'نمودار خطی', 'pie-chart' => 'نمودار دایره‌ای',
            'activity' => 'فعالیت', 'percent' => 'درصد', 'percent-sign' => 'درصد',
            'calendar-check' => 'قرار تأییدشده', 'calendar-clock' => 'زمان‌بندی',
            'timer' => 'کرنومتر', 'hourglass' => 'شنی', 'history' => 'تاریخچه',
            'milestone' => 'نقطه عطف', 'rocket' => 'موشک/شتاب', 'lightbulb' => 'ایده',
            'bulb' => 'لامپ', 'sparkles' => 'درخشش', 'magic' => 'جادو', 'wand' => 'چوب جادو',
            'scissors' => 'قیچی', 'bucket' => 'سطل', 'paint' => 'رنگ', 'palette' => 'پالت رنگ',
            'brush' => 'قلم‌موی', 'pen-tool' => 'طراحی', 'shapes' => 'شکل‌ها',
            'square' => 'مربع', 'circle' => 'دایره', 'triangle' => 'مثلث',
            'hexagon' => 'شش‌ضلعی', 'octagon' => 'هشت‌ضلعی', 'diamond' => 'لوزی',
            'anchor' => 'لنگر', 'life-buoy' => 'حلقه نجات', 'shield-alert' => 'خطر امنیتی',
            'fingerprint' => 'اثر انگشت', 'scan' => 'اسکن', 'qr-code' => 'کد QR',
            'barcode' => 'بارکد', 'id-card' => 'کارت شناسایی', 'badge' => 'نشان',
            'ticket' => 'بلیت', 'stamp' => 'مهر', 'signpost' => 'تابلو راهنما',
            // وضعیت‌ها
            'like' => 'پسند', 'dislike' => 'نپسندیدن', 'smile' => 'خندان', 'frown' => 'ناراضی',
            'meh' => 'بی‌تفاوت', 'laugh' => 'خنده', 'angry' => 'عصبانی', 'sleep' => 'خواب',
            'coffee-cup' => 'فنجان', 'cup' => 'فنجان', 'glass-water' => 'لیوان آب',
            'utensils' => 'غذاخوری', 'salad' => 'سالاد', 'sandwich' => 'ساندویچ',
            'cake' => 'کیک', 'ice-cream' => 'بستنی', 'candy' => 'شیرینی',
            'apple' => 'سیب', 'carrot' => 'هویج', 'leaf' => 'برگ', 'sprout' => 'جوانه',
            'tree' => 'درخت', 'flower' => 'گل', 'plant' => 'گیاه', 'seedling' => 'نهال',
            'recycle' => 'بازیافت', 'trash-2' => 'زباله', 'broom' => 'جارو',
            // خودرو و حمل‌ونقل
            'car' => 'خودرو', 'bike' => 'دوچرخه', 'bus' => 'اتوبوس', 'train' => 'قطار',
            'plane' => 'هواپیما', 'ship' => 'کشتی', 'boat' => 'قایق', 'helicopter' => 'هلیکوپتر',
            'fuel' => 'سوخت', 'gas-station' => 'پمپ بنزین', 'tire' => 'لاستیک',
            'steering-wheel' => 'فرمان', 'gauge-circle' => 'کیلومترشمار',
            // پشتیبانی و سرویس
            'support' => 'پشتیبانی', 'customer-service' => 'خدمات مشتریان',
            'headset' => 'هدست پشتیبانی', 'helpline' => 'خط کمک', 'operator' => 'اپراتور',
            'wrench-screwdriver' => 'تعمیرات', 'settings-2' => 'تنظیمات فنی',
            'machine' => 'دستگاه', 'motor' => 'موتور', 'engine' => 'موتور/دستگاه',
            'gear' => 'چرخ‌دنده', 'cog' => 'چرخ‌دنده', 'settings-gear' => 'تنظیمات',
            'maintenance' => 'نگهداری', 'repair' => 'تعمیر', 'service' => 'سرویس',
            'diagnostic' => 'عیب‌یابی', 'inspection' => 'بازرسی', 'test' => 'آزمون',
            'verified' => 'تأییدشده', 'certificate' => 'گواهی‌نامه', 'document' => 'سند',
            'contract' => 'قرارداد', 'file-text' => 'متن/سند', 'file-check' => 'سند تأییدشده',
            'clipboard-check' => 'چک‌لیست', 'clipboard-list' => 'فهرست کار',
            'schedule' => 'برنامه زمانی', 'timeline' => 'خط زمانی', 'workflow' => 'گردش کار',
            // 👇 مکمل‌های نسخه ۲.۱۰ — پوشش برچسب‌های باقی‌مانده پک‌های گسترشیافته
            'plus-circle' => 'افزودن', 'minus-circle' => 'کم کردن',
            'shopping-bag' => 'کیف خرید', 'battery-full' => 'باتری پر',
            'info-circle' => 'اطلاعات', 'help-circle' => 'راهنما',
            'circle-question' => 'سوال', 'question-circle' => 'سوال',
            'log-in' => 'ورود', 'log-out' => 'خروج',
            'thumbs-down' => 'نپسندیدن', 'paint-bucket' => 'سطل رنگ',
            'badge-check' => 'تأییدشده', 'message-square' => 'پیام',
            'external-link' => 'لینک خارجی', 'share-2' => 'اشتراک‌گذاری',
            'device-desktop' => 'دسکتاپ', 'device-mobile' => 'موبایل',
            'device-tablet' => 'تبلت', 'x-circle' => 'بستن', 'alert-circle' => 'هشدار',
            'exclamation-circle' => 'هشدار', 'exclamation-triangle' => 'هشدار',
            'calendar-days' => 'تقویم روزها', 'calendar-plus' => 'افزودن رویداد',
            'calendar-minus' => 'حذف رویداد', 'calendar-xmark' => 'لغو رویداد',
            'chart-bar' => 'نمودار ستونی', 'chart-line' => 'نمودار خطی',
            'chart-pie' => 'نمودار دایرهای', 'flashlight' => 'چراغقوه',
            'arrow-back' => 'فلش بازگشت', 'arrow-forward' => 'فلش جلو',
            'arrow-up-right' => 'فلش مورب', 'arrow-down-right' => 'فلش مورب پایین',
            'arrow-from-left' => 'از چپ', 'arrow-from-right' => 'از راست',
            'arrow-from-top' => 'از بالا', 'arrow-from-bottom' => 'از پایین',
            'arrow-to-bottom' => 'به پایین', 'more-horizontal' => 'بیشتر (افقی)',
            'more-vertical' => 'بیشتر (عمودی)', 'rotate-ccw' => 'چرخش پادساعت‌گرد',
            'rotate-cw' => 'چرخش ساعت‌گرد', 'circle-check' => 'تأیید',
            'circle-xmark' => 'بستن', 'circle-play' => 'پخش', 'circle-pause' => 'توقف',
            'circle-stop' => 'ایست', 'circle-user' => 'کاربر', 'circle-dot' => 'نقطه هدف',
            'circle-down' => 'فلش پایین', 'circle-up' => 'فلش بالا',
            'circle-left' => 'فلش چپ', 'circle-right' => 'فلش راست',
            'file-audio' => 'فایل صوتی', 'file-video' => 'فایل ویدیو',
            'file-image' => 'فایل تصویر', 'file-pdf' => 'فایل PDF',
            'file-zipper' => 'فایل فشرده', 'file-code' => 'فایل کد',
            'file-lines' => 'فایل متنی', 'file-word' => 'فایل Word',
            'file-excel' => 'فایل Excel', 'file-powerpoint' => 'فایل PowerPoint',
            'folder-open' => 'پوشه باز', 'folder-closed' => 'پوشه بسته',
            'square-caret-down' => 'انتخاب پایین', 'square-caret-left' => 'انتخاب چپ',
            'square-caret-right' => 'انتخاب راست', 'square-caret-up' => 'انتخاب بالا',
            'square-check' => 'مربع تأیید', 'square-plus' => 'مربع افزودن',
            'square-minus' => 'مربع کم کردن', 'square-xmark' => 'مربع بستن',
            'bell-ring' => 'زنگ فعال', 'bell-off' => 'زنگ خاموش',
            'bluetooth-on' => 'بلوتوث روشن', 'signal-bars' => 'قدرت سیگنال',
            'washing-machine-2' => 'ماشین لباسشویی', 'air-conditioner-2' => 'کولر گازی',
            // 👇 مکمل‌های دوم — پوشش الگوهای رمیکس/هیرو/متریال/فا
            'arrow-drop-down' => 'فلش پایین', 'arrow-drop-left' => 'فلش چپ',
            'arrow-drop-right' => 'فلش راست', 'arrow-drop-up' => 'فلش بالا',
            'arrow-go-back' => 'بازگشت', 'arrow-go-forward' => 'جلو',
            'arrow-turn-back' => 'بازگشت', 'arrow-turn-forward' => 'جلو',
            'arrow-long-down' => 'فلش بلند پایین', 'arrow-long-up' => 'فلش بلند بالا',
            'arrow-long-left' => 'فلش بلند چپ', 'arrow-long-right' => 'فلش بلند راست',
            'arrow-small-down' => 'فلش کوچک پایین', 'arrow-small-up' => 'فلش کوچک بالا',
            'arrow-small-left' => 'فلش کوچک چپ', 'arrow-small-right' => 'فلش کوچک راست',
            'arrow-path' => 'مسیر چرخشی', 'arrow-uturn' => 'چرخش ۱۸۰ درجه',
            'arrow-top-right-on-square' => 'باز کردن لینک',
            'battery-0' => 'باتری خالی', 'battery-50' => 'باتری نیمه',
            'battery-100' => 'باتری کامل', 'battery-2' => 'باتری',
            'battery-saver' => 'صرفه‌جویی باتری', 'battery-std' => 'باتری استاندارد',
            'battery-unknown' => 'باتری نامشخص', 'battery-low' => 'باتری ضعیف',
            'battery-alert' => 'هشدار باتری', 'battery-charge' => 'شارژ باتری',
            'calendar-date-range' => 'بازه تاریخ', 'calendar-close' => 'بستن تقویم',
            'calendar-event' => 'رویداد تقویم', 'calendar-schedule' => 'زمان‌بندی تقویم',
            'calendar-todo' => 'کارهای تقویم',
            'check-double' => 'تأیید دوبل', 'check-badge' => 'نشان تأیید',
            'cloud-off' => 'قطع ابر', 'cloud-windy' => 'باد و ابر',
            'device' => 'دستگاه', 'device-recover' => 'بازیابی دستگاه',
            'device-hub' => 'هاب دستگاه', 'device-thermostat' => 'ترموستات',
            'devices' => 'دستگاه‌ها', 'devices-other' => 'سایر دستگاه‌ها',
            'devices-fold' => 'دستگاه تاشو', 'android' => 'اندروید',
            'file-add' => 'افزودن فایل', 'file-chart' => 'نمودار فایل',
            'file-close' => 'بستن فایل', 'file-2' => 'فایل', 'file-3' => 'فایل', 'file-4' => 'فایل',
            'star-half' => 'نیم‌ستاره', 'star-half-stroke' => 'نیم‌ستاره',
            'square-full' => 'مربع پر', 'address-book' => 'دفترچه آدرس',
            'address-card' => 'کارت شناسایی', 'bell-slash' => 'زنگ خاموش',
            'chevron-double-down' => 'فلش دوبل پایین', 'chevron-double-up' => 'فلش دوبل بالا',
            'chevron-double-left' => 'فلش دوبل چپ', 'chevron-double-right' => 'فلش دوبل راست',
            'arrow-archery' => 'تیر و کمان', 'arrow-email-forward' => 'هدایت ایمیل',
            'arrow-separate' => 'جداسازی', 'arrow-up-left' => 'فلش مورب چپ',
        ];
    }
}
