<?php
/**
 * 🤖 ربات تلگرام متصل به دستیار فارسی سهند — TelegramBot v2.0
 * ==================================================================
 * پل ارتباطی بین تلگرام و موتور هوش مصنوعی سهند:
 * کاربر در تلگرام فارسی می‌نویسد → CommandAssistant پردازش
 * می‌کند → پاسخ قالب‌بندی‌شده HTML برگردانده می‌شود.
 *
 * قابلیت‌های نسخه ۲:
 *   💬 گفتگو با دستیار فارسی (زبان طبیعی — ۱۲ عملیات)
 *   📰 تحویل «کامل» مقاله در تلگرام: فایل HTML مستقل آماده انتشار
 *       برای هر سایت دیگری + نسخه Markdown + آلبوم ۳ تصویر
 *   🌐 جستجوی آنلاین وب + تحقیق ساختاریافته + اخبار زنده
 *   📊 امتیازدهی، بهبود متن، عنوان‌ساز، عیب‌یابی و ...
 *   ⚙️ فرمان‌های مدیریتی: /start /help /status /id /article
 *      /keywords /search /research /news /titles /improve /score
 *   ⌨️ کیبورد شیشه‌ای + دکمه‌های شیشه‌ای (callback)
 *   ⏳ نمایش «در حال نوشتن...» هنگام تولید
 *   🔐 احراز هویت با لیست شناسه‌های مجاز + توکن مخفی وب‌هوک
 *   ✂️ تقسیم خودکار پیام‌های بلند (سقف ۴۰۹۶ کاراکتر تلگرام)
 *
 * راه‌اندازی:
 *   ۱) از @BotFather ربات بسازید و توکن را در پنل مدیریت وارد کنید
 *   ۲) شناسه چت خود را با ارسال /id به ربات بگیرید و در پنل مجاز کنید
 *   ۳) در پنل مدیریت دکمه «راه‌اندازی وب‌هوک» را بزنید
 *   (یا برای تست محلی: php telegram/poll.php)
 *
 * تنظیمات (کلید telegram_bot_settings):
 *   enabled, bot_token, allowed_chat_ids (با کاما),
 *   webhook_secret, notify_new_request, welcome_text
 *
 * @package SahandBrandMaker\Telegram
 * @version 2.0.0
 */

define('TELEGRAM_API_BASE', 'https://api.telegram.org/bot');

class TelegramBot
{
    /** @var string توکن ربات */
    private $token;

    /** @var array تنظیمات */
    private $cfg;

    /** @var array لاگ خطاهای آخرین درخواست */
    private $lastError = '';

    /** ✂️ سقف طول پیام تلگرام */
    const MAX_MESSAGE_LEN = 4000;

    public function __construct(?string $token = null, ?array $cfg = null)
    {
        $this->cfg = $cfg ?? (array)(Config::get('telegram_bot_settings') ?: []);
        $this->token = $token ?? (string)($this->cfg['bot_token'] ?? '');
    }

    /* ==================================================
     * 🔌 API تلگرام
     * ================================================== */

    /**
     * 🔗 فراخوانی متد Bot API تلگرام (JSON)
     * 🌉 v2.6: در صورت فعال بودن «واسط گوگل»، درخواست از طریق Google Apps Script
     *    (سرورهای گوگل — بدون تحریم) به تلگرام ارسال می‌شود.
     *
     * @return array پاسخ JSON — throw در صورت خطای شبکه/توکن
     */
    public function api(string $method, array $params = []): array
    {
        if ($this->token === '') {
            throw new RuntimeException('توکن ربات تنظیم نشده است — از پنل مدیریت وارد کنید.');
        }

        /* 🌉 واسط گوگل فعال است → از طریق سرورهای گوگل به تلگرام */
        if (!empty($this->cfg['relay_enabled']) && !empty($this->cfg['relay_url'])) {
            return $this->apiViaRelay($method, $params);
        }

        return $this->apiDirect($method, $params);
    }

    /**
     * 🔢 نرمال‌سازی نتیجه Bot API — تلگرام برای برخی متدها
     * (sendChatAction، setWebhook، answerCallbackQuery و ...) به‌جای آبجکت،
     * true برمی‌گرداند؛ چون نوع بازگشتی متدها array است، این مقدار باید
     * به آرایه تبدیل شود وگرنه TypeError رخ می‌دهد (باگ v2.7).
     */
    private function normalizeResult($result): array
    {
        if (is_array($result)) {
            return $result;
        }
        return ['success' => $result === true];
    }

    /**
     * 🌉 ارسال از طریق واسط Google Apps Script (عبور از تحریم با سرورهای گوگل)
     */
    private function apiViaRelay(string $method, array $params): array
    {
        $relayUrl = (string)$this->cfg['relay_url'];
        $secret = (string)($this->cfg['relay_secret'] ?? '');
        $payload = json_encode([
            'secret' => $secret,
            'method' => $method,
            'token'  => $this->token,
            'params' => $params,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($relayUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 70,
            CURLOPT_FOLLOWLOCATION => true, // script.google.com ریدایرکت ۳۰۲ می‌دهد
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || $body === '') {
            throw new RuntimeException('خطای واسط گوگل: ' . ($err ?: "HTTP {$http}"));
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('پاسخ نامعتبر از واسط گوگل (احتمالاً اسکریپت درست Deploy نشده).');
        }
        if (!empty($data['relay_error'])) {
            throw new RuntimeException('خطای واسط گوگل → تلگرام: ' . ($data['relay_error'] ?? ''));
        }
        if (empty($data['ok'])) {
            $this->lastError = (string)($data['description'] ?? 'نامشخص');
            throw new RuntimeException('خطای تلگرام (از واسط): ' . $this->lastError);
        }
        return $this->normalizeResult($data['result'] ?? []);
    }

    /**
     * 🔗 ارسال مستقیم به تلگرام (مسیر پیش‌فرض)
     */
    private function apiDirect(string $method, array $params): array
    {
        $ch = curl_init($this->apiBaseUrl() . $this->token . '/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || $body === '') {
            throw new RuntimeException('خطای شبکه تلگرام: ' . ($err ?: "HTTP {$http}"));
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('پاسخ نامعتبر از تلگرام.');
        }
        if (empty($data['ok'])) {
            $this->lastError = (string)($data['description'] ?? 'نامشخص');
            throw new RuntimeException('خطای تلگرام: ' . $this->lastError);
        }
        return $this->normalizeResult($data['result'] ?? []);
    }

    /**
     * 🧭 آدرس پایه API — در subclasses قابل بازنویسی (بله: tapi.bale.ai)
     * v2.7: قبلاً apiUpload آدرس تلگرام را هاردکد کرده بود و ربات بله موقع
     * آپلود فایل به api.telegram.org می‌رفت → خطای اتصال. حالا همه مسیرها از همین متد می‌گذرند.
     */
    protected function apiBaseUrl(): string
    {
        return TELEGRAM_API_BASE;
    }

    /**
     * 📎 فراخوانی متد Bot API با آپلود فایل (multipart/form-data)
     *
     * @param array $params پارامترهای متد (بدون فایل‌ها)
     * @param array $files  نگاشت نام فایل مجازی → CURLFile یا مسیر
     */
    public function apiUpload(string $method, array $params, array $files): array
    {
        if ($this->token === '') {
            throw new RuntimeException('توکن ربات تنظیم نشده است.');
        }

        /* 🌉 v2.7: واسط گوگل فعال است → فایل به‌صورت base64 از سرورهای گوگل عبور می‌کند
           (قبلاً آپلود مستقیم به تلگرام می‌رفت و در زمان تحریم fail می‌شد) */
        if (!empty($this->cfg['relay_enabled']) && !empty($this->cfg['relay_url'])) {
            return $this->apiUploadViaRelay($method, $params, $files);
        }

        return $this->apiUploadDirect($method, $params, $files);
    }

    /**
     * 🌉 آپلود فایل از طریق واسط گوگل — فایل‌ها base64 می‌شوند و اسکریپت گوگل
     * آنها را به Blob تبدیل و multipart به تلگرام می‌فرستد (v2.7)
     */
    private function apiUploadViaRelay(string $method, array $params, array $files): array
    {
        $b64 = [];
        $names = [];
        $mimes = [];
        foreach ($files as $field => $path) {
            $realPath = $path instanceof CURLFile ? (string)$path->getFilename() : (string)$path;
            if (!is_file($realPath) || !is_readable($realPath)) {
                throw new RuntimeException('فایل آپلود یافت نشد: ' . $realPath);
            }
            $size = filesize($realPath);
            if ($size > 8 * 1024 * 1024) {
                throw new RuntimeException('حجم فایل برای واسط گوگل زیاد است (حداکثر ۸ مگابایت): ' . $realPath);
            }
            $b64[$field]    = base64_encode(file_get_contents($realPath));
            $names[$field]  = basename($realPath);
            // نام اصلی نمایشی از postname در صورت وجود (CURLFile نام دلخواه می‌پذیرد)
            if ($path instanceof CURLFile && $path->getPostFilename() !== '') {
                $names[$field] = (string)$path->getPostFilename();
            }
            $mimes[$field]  = $path instanceof CURLFile
                ? (($path->getMimeType() ?: 'application/octet-stream'))
                : ((function_exists('mime_content_type') ? (mime_content_type($realPath) ?: '') : '') ?: 'application/octet-stream');
        }
        $payload = [
            'secret'    => (string)($this->cfg['relay_secret'] ?? ''),
            'method'    => $method,
            'token'     => $this->token,
            'params'    => $params,
            'files_b64' => $b64,
            'files_name'=> $names,
            'files_mime'=> $mimes,
        ];
        $ch = curl_init((string)$this->cfg['relay_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if (!is_string($body) || $body === '') {
            throw new RuntimeException('خطای شبکه واسط گوگل (آپلود): ' . ($err ?: "HTTP {$http}"));
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('پاسخ نامعتبر از واسط گوگل (آپلود).');
        }
        if (!empty($data['relay_error'])) {
            throw new RuntimeException('خطای واسط گوگل → تلگرام (آپلود): ' . $data['relay_error']);
        }
        if (empty($data['ok'])) {
            $desc = (string)($data['description'] ?? 'نامشخص');
            /* 🩹 v2.8: «there is no document/photo/file in the request» یعنی اسکریپت
               واسط گوگلِ مستقرشده قدیمی است و بخش فایل (files_b64) را به تلگرام پاس
               نمی‌دهد — به‌جای شکست کامل، یک‌بار آپلود «مستقیم» امتحان می‌شود
               (اگر سرور در آن لحظه به تلگرام دسترسی داشته باشد) و اگر نشد،
               پیام راهنمای دقیق برای بروزرسانی اسکریپت نمایش داده می‌شود. */
            if (preg_match('/there is no (document|photo|file|sticker|video|audio)/i', $desc)) {
                try {
                    $direct = $this->apiUploadDirect($method, $params, $files);
                    $this->lastError = '';
                    return $direct;
                } catch (Throwable $e) {
                    throw new RuntimeException(
                        'آپلود از واسط گوگل انجام نشد (فایل به تلگرام نرسید: ' . $desc . '). ' .
                        '⬅️ راه‌حل: «اسکریپت واسط گوگل» را از پنل مدیریت → تلگرام → کارت واسط، ' .
                        'کپی کنید و در script.google.com دوباره Deploy کنید (نسخه جدید پشتیبانی فایل دارد)، ' .
                        'یا واسط را موقتاً غیرفعال کنید. (تلاش مستقیم هم ناموفق بود: ' . $e->getMessage() . ')'
                    );
                }
            }
            throw new RuntimeException('خطای تلگرام (آپلود از واسط): ' . $desc);
        }
        return $this->normalizeResult($data['result'] ?? []);
    }

    /**
     * 🔗 آپلود مستقیم به تلگرام — بدون واسط (v2.8: از مسیر واسط هم قابل فراخوانی
     * است تا اگر واسط فایل را گم کرد، تحویل با یک تلاش مستقیم نجات یابد)
     */
    private function apiUploadDirect(string $method, array $params, array $files): array
    {
        $post = $params;
        foreach ($files as $field => $path) {
            $post[$field] = $path instanceof CURLFile ? $path : new CURLFile((string)$path);
        }
        $ch = curl_init($this->apiBaseUrl() . $this->token . '/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if (!is_string($body) || $body === '') {
            throw new RuntimeException('خطای شبکه تلگرام (آپلود مستقیم): ' . ($err ?: "HTTP {$http}"));
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['ok'])) {
            throw new RuntimeException((string)($data['description'] ?? 'خطای تلگرام (آپلود مستقیم)'));
        }
        return $this->normalizeResult($data['result'] ?? []);
    }

    /** 🤖 اطلاعات ربات — تست اتصال */
    public function getMe(): array
    {
        return $this->api('getMe');
    }

    /** 📡 اطلاعات وضعیت وب‌هوک فعلی */
    public function getWebhookInfo(): array
    {
        return $this->api('getWebhookInfo');
    }

    /**
     * 🪝 راه‌اندازی وب‌هوک
     *
     * @param string $publicUrl آدرس عمومی (https الزامی تلگرام)
     * @param string $secret    توکن مخفی (از هدر X-Telegram-Bot-Api-Secret-Token راستی‌آزمایی می‌شود)
     */
    public function setWebhook(string $publicUrl, string $secret): array
    {
        return $this->api('setWebhook', [
            'url'             => $publicUrl,
            'secret_token'    => $secret,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => false,
        ]);
    }

    /** 🧹 حذف وب‌هوک */
    public function deleteWebhook(): array
    {
        return $this->api('deleteWebhook', ['drop_pending_updates' => false]);
    }

    /* ==================================================
     * ✉️ ارسال پیام و فایل
     * ================================================== */

    /**
     * ✉️ ارسال پیام HTML — تقسیم خودکار پیام‌های بلند
     *
     * @param int|string $chatId شناسه چت مقصد
     * @param string     $text   متن پیام (HTML مجاز)
     * @param array      $opts   [disable_preview, keyboard, inline_keyboard]
     * @return int شناسه آخرین پیام ارسال‌شده
     */
    public function sendMessage($chatId, string $text, array $opts = []): int
    {
        $text = trim($text);
        if ($text === '') { $text = '—'; }
        $lastId = 0;
        foreach ($this->chunk($text) as $i => $part) {
            $params = [
                'chat_id'                  => $chatId,
                'text'                     => $part,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => !empty($opts['disable_preview']),
            ];
            if (!empty($opts['keyboard'])) {
                $params['reply_markup'] = json_encode([
                    'keyboard'        => $opts['keyboard'],
                    'resize_keyboard' => true,
                    'is_persistent'   => false,
                ], JSON_UNESCAPED_UNICODE);
            } elseif (!empty($opts['inline_keyboard'])) {
                $params['reply_markup'] = json_encode([
                    'inline_keyboard' => $opts['inline_keyboard'],
                ], JSON_UNESCAPED_UNICODE);
            }
            $msg = $this->api('sendMessage', $params);
            $lastId = (int)($msg['message_id'] ?? 0);
        }
        return $lastId;
    }

    /** ⏳ نمایش وضعیت (typing / upload_document و ...) */
    public function sendChatAction($chatId, string $action = 'typing'): void
    {
        try {
            $this->api('sendChatAction', ['chat_id' => $chatId, 'action' => $action]);
        } catch (Exception $e) {
            // بی‌اهمیت — نادیده گرفته می‌شود
        }
    }

    /**
     * 📎 ارسال فایل (متن/سند) از محتوای رشته‌ای
     *
     * 🆕 v2.9 — استراتژی «URL-اول» (رفع قطعی خطای «there is no document in the request"):
     *   ۱) فایل در مسیر عمومی موقت (uploads/temp) ذخیره می‌شود و برای تلگرام
     *      «آدرس URL» ارسال می‌شود — تلگرام خودش فایل را دانلود می‌کند.
     *      این مسیر فقط JSON است و با «هر نسخه‌ای» از واسط گوگل کار می‌کند
     *      (حتی اسکریپت‌های قدیمی که آپلود multipart ندارند).
     *   ۲) اگر شکست خورد → آپلود multipart از طریق واسط (files_b64 — نیازمند GAS v2+)
     *   ۳) اگر آن هم شکست خورد → آپلود مستقیم به تلگرام
     *
     * @param string $filename نام فایل با پسوند
     * @param string $content  محتوای فایل
     * @param string $caption  کپشن (HTML)
     * @return int شناسه پیام
     */
    public function sendDocumentFromString($chatId, string $filename, string $content, string $caption = ''): int
    {
        $params = [
            'chat_id' => $chatId,
            'caption' => mb_substr($caption, 0, 1000),
            'parse_mode' => 'HTML',
        ];

        /* --- مسیر ۱: آدرس عمومی — فقط JSON، سازگار با هر واسط --- */
        $publicUrl = $this->stageTempFile($filename, $content);
        if ($publicUrl !== null) {
            try {
                $msg = $this->api('sendDocument', $params + ['document' => $publicUrl]);
                return (int)($msg['message_id'] ?? 0);
            } catch (Throwable $e) {
                @error_log('[TelegramBot] URL document failed → multipart: ' . $e->getMessage());
            }
        }

        /* --- مسیر ۲/۳: آپلود multipart (واسط v2+ یا مستقیم) --- */
        $tmp = tempnam(sys_get_temp_dir(), 'tg_');
        file_put_contents($tmp, $content);
        try {
            $msg = $this->apiUpload('sendDocument', $params, ['document' => new CURLFile($tmp, 'application/octet-stream', $filename)]);
            return (int)($msg['message_id'] ?? 0);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * 🗄 ذخیره فایل در مسیر عمومی موقت برای ارسال با URL — نام تصادفی و غیرقابل حدس
     * فایل‌های قدیمی‌تر از ۶ ساعت خودکار پاک می‌شوند.
     * @return string|null آدرس مطلق عمومی یا null (هاست عمومی نیست)
     */
    private function stageTempFile(string $filename, string $content): ?string
    {
        try {
            $base = rtrim((string)BASE_URL, '/');
            if ($base === '' || preg_match('#(localhost|127\.0\.0\.1|/var/www/html)$#', $base)) {
                return null; // آدرس عمومی معتبر نیست
            }
            $dir = ROOT_PATH . '/uploads/temp';
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                return null;
            }
            /* 🧹 پاک‌سازی فایل‌های قدیمی (>۶ ساعت) */
            $now = time();
            foreach (glob($dir . '/tg-*') ?: [] as $old) {
                if (is_file($old) && ($now - (int)filemtime($old)) > 21600) {
                    @unlink($old);
                }
            }
            $safeExt = preg_replace('/[^a-z0-9.]/i', '', pathinfo($filename, PATHINFO_EXTENSION));
            $name = 'tg-' . bin2hex(random_bytes(8)) . ($safeExt !== '' ? '.' . $safeExt : '.bin');
            if (@file_put_contents($dir . '/' . $name, $content) === false) {
                return null;
            }
            return $base . '/uploads/temp/' . $name;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * 🖼️ ارسال آلبوم تصاویر (sendMediaGroup) — URL-اول (v2.9)
     *
     * 🆕 v2.9: تصاویری که مسیر عمومی دارند با «آدرس URL» ارسال می‌شوند —
     * فقط JSON از واسط عبور می‌کند و با هر نسخه اسکریپت واسط کار می‌کند
     * (رفع خطای there is no photo in the request در اسکریپت‌های قدیمی).
     * تصاویر محلی/موقتی → آپلود multipart به‌عنوان جایگزین.
     *
     * @param array $items [['path' => '/abs/x.jpg', 'caption' => '...'], ...]
     * @return bool موفقیت
     */
    public function sendImageAlbum($chatId, array $items): bool
    {
        if (!$items) { return false; }

        /* --- مسیر ۱: URL عمومی — فقط JSON (سازگار با هر واسط) --- */
        $base = rtrim((string)BASE_URL, '/');
        $urlItems = [];
        foreach (array_values($items) as $i => $item) {
            $path = (string)($item['path'] ?? '');
            if ($path === '' || !is_file($path)) { continue; }
            /* فقط مسیرهای داخل ROOT_PATH قابل URL شدن‌اند */
            $rel = null;
            $normPath = str_replace('\\', '/', realpath($path) ?: $path);
            $normRoot = str_replace('\\', '/', ROOT_PATH);
            if (strpos($normPath, $normRoot) === 0) {
                $rel = ltrim(substr($normPath, strlen($normRoot)), '/');
            }
            if ($rel === null || $base === '' || preg_match('#(localhost|127\.0\.0\.1)$#', $base)) {
                continue;
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                continue; // SVG قابل ارسال photo نیست (زیر در multipart رستر می‌شود)
            }
            $entry = ['type' => 'photo', 'media' => $base . '/' . $rel];
            if ($i === 0 && !empty($item['caption'])) {
                $entry['caption'] = mb_substr((string)$item['caption'], 0, 1000);
                $entry['parse_mode'] = 'HTML';
            }
            $urlItems[] = $entry;
        }
        if (count($urlItems) >= 1) {
            try {
                $this->api('sendMediaGroup', [
                    'chat_id' => $chatId,
                    'media'   => json_encode($urlItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                return true;
            } catch (Throwable $e) {
                @error_log('[TelegramBot] URL album failed → multipart: ' . $e->getMessage());
            }
        }

        /* --- مسیر ۲: آپلود multipart (رفتار قبلی) --- */
        $media = [];
        $files = [];
        foreach (array_values($items) as $i => $item) {
            $path = (string)$item['path'];
            if (!is_file($path)) { continue; }
            /* 🖼 v2.8: تلگرام SVG را به‌عنوان photo قبول نمی‌کند — تصاویر یکتای AI
               که SVG هستند ابتدا با Imagick به PNG رستر می‌شوند (کش‌شده) و اگر
               رستر ممکن نبود، همان آیتم به‌جای حذف کامل، به‌صورت سند ارسال می‌شود. */
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext === 'svg') {
                $png = $this->rasterizeForSend($path);
                if ($png !== null) {
                    $path = $png;
                    $ext = 'png';
                } else {
                    try {
                        $this->sendDocumentFromString($chatId, basename($path), (string)@file_get_contents($path),
                            '🖼️ ' . (string)($item['caption'] ?? 'تصویر مقاله') . ' — فرمت SVG');
                    } catch (Throwable $e) {
                        // بدون شکستن بقیه آلبوم
                    }
                    continue;
                }
            }
            $ref = 'file' . $i;
            $entry = ['type' => 'photo', 'media' => 'attach://' . $ref];
            if (!empty($item['caption'])) {
                $entry['caption'] = mb_substr((string)$item['caption'], 0, 1000);
                $entry['parse_mode'] = 'HTML';
            }
            $media[] = $entry;
            $mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
            $files[$ref] = new CURLFile($path, $mime, 'image-' . ($i + 1) . '.' . $ext);
        }
        if (count($media) < 1) { return false; }
        $this->apiUpload('sendMediaGroup', [
            'chat_id' => $chatId,
            'media'   => json_encode($media, JSON_UNESCAPED_UNICODE),
        ], $files);
        return true;
    }

    /**
     * 🔄 رستر کردن SVG به PNG برای ارسال در تلگرام (کش‌شده — v2.8)
     * @return string|null مسیر PNG یا null
     */
    private function rasterizeForSend(string $svgPath): ?string
    {
        if (!class_exists('Imagick')) {
            return null;
        }
        $cacheDir = sys_get_temp_dir() . '/sahand-tg-raster';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        $out = $cacheDir . '/tg-' . md5($svgPath . '|' . @filemtime($svgPath)) . '.png';
        if (is_file($out) && filesize($out) > 100) {
            return $out;
        }
        try {
            $im = new Imagick();
            $im->setBackgroundColor(new ImagickPixel('transparent'));
            $im->readImageBlob((string)@file_get_contents($svgPath));
            $im->setImageFormat('png');
            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            if ($w > 0 && $h > 0) {
                $scale = min(1280 / $w, 1280 / $h, 2);
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

    /** ✂️ تقسیم متن به قطعات <= ۴۰۰۰ کاراکتر (مرز خط) */
    private function chunk(string $text): array
    {
        if (mb_strlen($text) <= self::MAX_MESSAGE_LEN) {
            return [$text];
        }
        $parts = [];
        $lines = explode("\n", $text);
        $current = '';
        foreach ($lines as $line) {
            if (mb_strlen($current) + mb_strlen($line) + 1 > self::MAX_MESSAGE_LEN) {
                if ($current !== '') { $parts[] = $current; }
                // خط خودش بیش از حد بلند — برش خشن
                if (mb_strlen($line) > self::MAX_MESSAGE_LEN) {
                    foreach (str_split($line, self::MAX_MESSAGE_LEN) as $hard) {
                        $parts[] = $hard;
                    }
                    $current = '';
                } else {
                    $current = $line;
                }
            } else {
                $current .= ($current === '' ? '' : "\n") . $line;
            }
        }
        if ($current !== '') { $parts[] = $current; }
        return $parts ?: ['—'];
    }

    /* ==================================================
     * 🧠 پردازش پیام (مغز ربات)
     * ================================================== */

    /**
     * 📨 پردازش یک آپدیت تلگرام (وب‌هوک یا polling)
     *
     * @param array $update آبجکت آپدیت تلگرام
     * @return bool آیا پردازش انجام شد
     */
    public function handleUpdate(array $update): bool
    {
        // ⌨️ پردازش دکمه‌های شیشه‌ای (callback)
        if (!empty($update['callback_query'])) {
            return $this->handleCallback($update['callback_query']);
        }

        $message = $update['message'] ?? null;
        if (!$message || empty($message['chat']['id'])) {
            return false;
        }
        $chatId = (string)$message['chat']['id'];
        $text = trim((string)($message['text'] ?? ''));
        $from = $message['from'] ?? [];

        try {
            // 🔐 احراز هویت
            if (!$this->isAllowed($chatId)) {
                $this->sendMessage($chatId,
                    "⛔ شما به این ربات دسترسی ندارید.\n\n" .
                    "🆔 شناسه چت شما: <code>{$chatId}</code>\n" .
                    'این شناسه را به مدیر سیستم بدهید تا دسترسی شما فعال شود.'
                );
                return false;
            }

            // 🤖 غیرفعال بودن
            if (empty($this->cfg['enabled'])) {
                $this->sendMessage($chatId, '⏸️ ربات موقتاً غیرفعال است. با مدیر سیستم تماس بگیرید.');
                return false;
            }

            // خالی
            if ($text === '') {
                $this->sendMessage($chatId, '📩 لطفاً پیام متنی بفرستید.');
                return true;
            }

            // ⚙️ فرمان‌های مدیریتی
            if ($text[0] === '/') {
                return $this->handleCommand($chatId, $text, $from);
            }

            // 📰 نیّت مقاله از زبان طبیعی → تحویل کامل پکیج مقاله
            $norm = TextProcessor::normalize(mb_strtolower($text));
            if (preg_match('/(مقاله|مطلب).*(بنویس|بنویسید|بساز|تولید|بده)|بنویس.*مقاله|مقاله جدید|مقاله کامل/u', $norm)) {
                return $this->deliverFullArticle($chatId, $text);
            }

            // 💬 هدایت به دستیار فارسی موتور سهند
            $this->sendChatAction($chatId, 'typing');
            $assistant = new CommandAssistant();
            $result = $assistant->handle($text);

            // 📰 پاسخ اخبار — قالب اختصاصی
            if (($result['action'] ?? '') === 'web_news') {
                $reply = $this->formatNewsReply($result);
                $this->sendMessage($chatId, $reply, ['disable_preview' => true]);
                return true;
            }

            $reply = $this->formatAssistantReply($result);
            $this->sendMessage($chatId, $reply, ['disable_preview' => true]);
            return true;
        } catch (Throwable $e) {
            @error_log('[TelegramBot] update error: ' . $e->getMessage());
            try {
                $this->sendMessage($chatId, '⚠️ خطا در پردازش پیام: ' . $e->getMessage());
            } catch (Exception $ignored) {
            }
            return false;
        }
    }

    /** ⌨️ پردازش دکمه شیشه‌ای (callback_query) */
    private function handleCallback(array $cb): bool
    {
        $chatId = (string)($cb['message']['chat']['id'] ?? '');
        $data = (string)($cb['data'] ?? '');
        $cbId = (string)($cb['id'] ?? '');
        try {
            // پاسخ فوری به تلگرام (رفع loading دکمه)
            if ($cbId !== '') {
                try { $this->api('answerCallbackQuery', ['callback_query_id' => $cbId]); } catch (Exception $e) {}
            }
            if ($chatId === '' || !$this->isAllowed($chatId)) {
                return false;
            }
            $payload = json_decode($data, true) ?: ['act' => $data];

            switch ((string)($payload['act'] ?? '')) {
                case 'article':
                    $subject = (string)($payload['topic'] ?? '');
                    return $this->deliverFullArticle($chatId, 'مقاله بنویس' . ($subject !== '' ? " درباره {$subject}" : ''));
                case 'research':
                    $topic = (string)($payload['topic'] ?? '');
                    if ($topic === '') { return false; }
                    $this->sendChatAction($chatId, 'typing');
                    $result = (new CommandAssistant())->handle("تحقیق درباره: {$topic}");
                    $this->sendMessage($chatId, $this->formatAssistantReply($result), ['disable_preview' => true]);
                    return true;
                case 'keywords':
                    $topic = (string)($payload['topic'] ?? '');
                    if ($topic === '') { return false; }
                    $this->sendChatAction($chatId, 'typing');
                    $result = (new CommandAssistant())->handle("کلمات کلیدی {$topic}");
                    $this->sendMessage($chatId, $this->formatAssistantReply($result), ['disable_preview' => true]);
                    return true;
                case 'news':
                    $topic = (string)($payload['topic'] ?? '');
                    $this->sendChatAction($chatId, 'typing');
                    $result = (new CommandAssistant())->handle("اخبار: {$topic}");
                    $this->sendMessage($chatId, $this->formatNewsReply($result), ['disable_preview' => true]);
                    return true;
                case 'help':
                    $this->sendMessage($chatId, $this->helpText(), ['disable_preview' => true]);
                    return true;
            }
            return false;
        } catch (Throwable $e) {
            @error_log('[TelegramBot] callback error: ' . $e->getMessage());
            return false;
        }
    }

    /** ⚙️ فرمان‌های مدیریتی ربات */
    private function handleCommand(string $chatId, string $text, array $from): bool
    {
        $parts = preg_split('/\s+/', trim($text), 2);
        $cmd = strtolower(trim(preg_split('/[@\s]/', $parts[0])[0]));
        $args = trim((string)($parts[1] ?? ''));

        switch ($cmd) {
            case '/start':
                $welcome = (string)($this->cfg['welcome_text'] ?? '');
                if ($welcome === '') {
                    $welcome = 'سلام! من دستیار هوشمند سهند سرویس هستم. هر سؤال یا دستور فارسی بنویسید تا کمکتان کنم.';
                }
                $name = trim((string)($from['first_name'] ?? ''));
                $this->sendMessage($chatId,
                    ($name !== '' ? "{$name} عزیز، خوش آمدید! 👋\n\n" : '') . $welcome . "\n\n" .
                    "📖 برای دیدن امکانات، /help را بفرستید.\n" .
                    "📰 مقاله کامل با ۳ عکس: <code>/article تعمیر یخچال اسنوا</code>",
                    ['keyboard' => $this->defaultKeyboard()]
                );
                return true;

            case '/help':
                $this->sendMessage($chatId, $this->helpText(), ['disable_preview' => true]);
                return true;

            case '/id':
                $this->sendMessage($chatId, "🆔 شناسه چت شما: <code>{$chatId}</code>");
                return true;

            case '/status':
                $this->sendMessage($chatId, $this->statusText());
                return true;

            case '/article':
            case '/مقاله':
                if ($args === '') {
                    $this->sendMessage($chatId,
                        "📰 <b>ساخت مقاله کامل</b>\n\n" .
                        "موضوع را جلوی فرمان بنویسید:\n" .
                        "<code>/article تعمیر ماشین لباسشویی پاکشما</code>\n" .
                        "<code>/article هزینه تعمیر یخچال</code>\n\n" .
                        "یا فارسی بنویسید: «مقاله بنویس درباره یخچال دوو»\n\n" .
                        "📦 تحویل: فایل HTML آماده انتشار + Markdown + ۳ تصویر"
                    );
                    return true;
                }
                return $this->deliverFullArticle($chatId, 'مقاله بنویس درباره ' . $args);

            case '/search':
            case '/جستجو':
                if ($args === '') {
                    $this->sendMessage($chatId, "🌐 عبارت را بنویسید: <code>/search قیمت موتور ماشین لباسشویی</code>");
                    return true;
                }
                $this->sendChatAction($chatId, 'typing');
                $result = (new CommandAssistant())->handle('جستجوی وب: ' . $args);
                $this->sendMessage($chatId, $this->formatAssistantReply($result), ['disable_preview' => true]);
                return true;

            case '/research':
            case '/تحقیق':
                if ($args === '') {
                    $this->sendMessage($chatId, "🧪 موضوع را بنویسید: <code>/research یخچال ساید بای ساید اسنوا</code>");
                    return true;
                }
                $this->sendChatAction($chatId, 'typing');
                $result = (new CommandAssistant())->handle('تحقیق درباره: ' . $args);
                $this->sendMessage($chatId, $this->formatAssistantReply($result), ['disable_preview' => true]);
                return true;

            case '/news':
            case '/اخبار':
                if ($args === '') {
                    $this->sendMessage($chatId, "📰 موضوع خبر را بنویسید: <code>/news لوازم خانگی</code>");
                    return true;
                }
                $this->sendChatAction($chatId, 'typing');
                $result = (new CommandAssistant())->handle('اخبار: ' . $args);
                $this->sendMessage($chatId, $this->formatNewsReply($result), ['disable_preview' => true]);
                return true;

            case '/keywords':
            case '/کلمات':
                if ($args === '') {
                    $this->sendMessage($chatId, "🔑 موضوع را بنویسید: <code>/keywords ماشین ظرفشویی</code>");
                    return true;
                }
                $this->sendChatAction($chatId, 'typing');
                $result = (new CommandAssistant())->handle('کلمات کلیدی ' . $args);
                $this->sendMessage($chatId, $this->formatAssistantReply($result), ['disable_preview' => true]);
                return true;

            case '/titles':
            case '/عنوان':
                if ($args === '') {
                    $this->sendMessage($chatId, "🏷️ موضوع را بنویسید: <code>/titles تعمیر کولر گازی</code>");
                    return true;
                }
                $this->sendChatAction($chatId, 'typing');
                $result = (new CommandAssistant())->handle('عنوان برای ' . $args . ' بده');
                $this->sendMessage($chatId, $this->formatAssistantReply($result));
                return true;

            case '/improve':
            case '/بهبود':
                if ($args === '') {
                    $this->sendMessage($chatId, "🔧 متن را بعد از فرمان بفرستید: <code>/improve متن شما...</code>");
                    return true;
                }
                $this->sendChatAction($chatId, 'typing');
                $result = (new CommandAssistant())->handle('این متن را بهبود بده: ' . $args);
                $this->sendMessage($chatId, $this->formatAssistantReply($result));
                return true;

            case '/score':
            case '/امتیاز':
                if ($args === '') {
                    $this->sendMessage($chatId, "🏆 متن را بعد از فرمان بفرستید: <code>/score متن شما...</code>");
                    return true;
                }
                $this->sendChatAction($chatId, 'typing');
                $result = (new CommandAssistant())->handle('امتیاز این متن: ' . $args);
                $this->sendMessage($chatId, $this->formatAssistantReply($result));
                return true;

            /* ---------- 🆕 v2.1 ---------- */

            case '/seo':
            case '/سئو':
                if ($args === '') {
                    $this->sendMessage($chatId,
                        "🏅 <b>پکیج سئوی پیشرفته</b> (موتور v3.3)\n\n" .
                        "موضوع یا متن را بفرستید تا تحلیل کامل بگیرید:\n" .
                        "<code>/seo تعمیر یخچال اسنوا</code>\n\n" .
                        "تحویل: عنوان و متا + کلیدواژه‌ها + خوشه معنایی +\n" .
                        "چک‌لیست E-E-A-T + موجودیت‌ها + پرس‌وجوهای رایج");
                    return true;
                }
                $this->sendChatAction($chatId, 'typing');
                try {
                    $result = (new SahandAI())->seoPackage([
                        'title'   => mb_substr($args, 0, 120),
                        'content' => $args,
                        'focus_keyword' => $args,
                    ]);
                    $this->sendMessage($chatId, $this->formatSeoReply($result), ['disable_preview' => true]);
                } catch (Throwable $e) {
                    $this->sendMessage($chatId, '❌ خطا در تولید پکیج سئو: ' . htmlspecialchars($e->getMessage()));
                }
                return true;

            case '/eeat':
            case '/اعتماد':
                if ($args === '') {
                    $this->sendMessage($chatId, "✅ متن را بفرستید تا چک‌لیست E-E-A-T بگیرید:\n<code>/eeat متن مقاله شما...</code>");
                    return true;
                }
                $this->sendChatAction($chatId, 'typing');
                try {
                    $result = (new SahandAI())->eeatCheck(['content' => $args]);
                    $this->sendMessage($chatId, $this->formatEeatReply($result));
                } catch (Throwable $e) {
                    $this->sendMessage($chatId, '❌ خطا: ' . htmlspecialchars($e->getMessage()));
                }
                return true;

            case '/plan':
            case '/برنامه':
                if ($args === '') {
                    $this->sendMessage($chatId, "🗓️ موضوع را بنویسید: <code>/plan یخچال پاکشما</code>\nبرنامه انتشار ۸ هفته‌ای مقالات + موضوعات پیشنهادی داده می‌شود.");
                    return true;
                }
                $this->sendChatAction($chatId, 'typing');
                $result = (new CommandAssistant())->handle('برنامه محتوا برای ' . $args);
                $this->sendMessage($chatId, $this->formatAssistantReply($result), ['disable_preview' => true]);
                return true;

            case '/faq':
            case '/سوال':
                if ($args === '') {
                    $this->sendMessage($chatId, "❓ موضوع را بنویسید: <code>/faq ماشین لباسشویی دوو</code>");
                    return true;
                }
                $this->sendChatAction($chatId, 'typing');
                $result = (new CommandAssistant())->handle('سوالات متداول درباره ' . $args);
                $this->sendMessage($chatId, $this->formatAssistantReply($result), ['disable_preview' => true]);
                return true;

            /* ---------- 🆕 فاز Q.9 ---------- */

            case '/grammar':
            case '/نگارش':
                if ($args === '') {
                    $this->sendMessage($chatId,
                        "📝 <b>اصلاح نگارش فارسی</b> (موتور PersianGrammar — فاز Q)\n\n" .
                        "متن را بعد از فرمان بفرستید:\n<code>/نگارش ما این کار را انجام می دهیم</code>\n\n" .
                        "تحویل: امتیاز نگارش ۰-۱۰۰ + فهرست ایرادها (نیم‌فاصله، سجاوندی، املای رایج، هم‌خوانی فعل و فاعل) + متن اصلاح‌شده + راهکارهای روان‌نویسی");
                    return true;
                }
                $this->sendChatAction($chatId, 'typing');
                try {
                    $analysis = PersianGrammar::analyze($args);
                    $fixed = PersianGrammar::fixText($args);
                    $fluency = PersianGrammar::fluency($args);

                    $msg = "📝 <b>گزارش نگارش</b>\n";
                    $msg .= '🏆 امتیاز: <b>' . $analysis['score'] . '/۱۰۰</b> (' . $analysis['grade'] . ")\n";
                    $m = $analysis['metrics'];
                    $msg .= "📏 کلمات: {$m['word_count']} · جملات: {$m['sentence_count']} · میانگین طول جمله: {$m['avg_sentence_words']}\n";
                    $msg .= '✂️ نیم‌فاصله جاافتاده: ' . $m['missing_zwnj'] . ' · ایراد سجاوندی: ' . $m['punctuation_issues'] . ' · هم‌خوانی فعل/فاعل: ' . $m['agreement_issues'] . "\n";

                    if (!empty($analysis['issues'])) {
                        $msg .= "\n🔎 <b>نمونه ایرادها:</b>\n";
                        foreach (array_slice($analysis['issues'], 0, 6) as $i => $issue) {
                            $msg .= ($i + 1) . '. ' . htmlspecialchars((string)$issue['hint']) . "\n";
                        }
                    }
                    if (!empty($fluency)) {
                        $msg .= "\n🌊 <b>روان‌نویسی:</b>\n";
                        foreach (array_slice($fluency, 0, 3) as $s) {
                            $msg .= '• ' . htmlspecialchars((string)$s['text']) . "\n";
                        }
                    }
                    if ($fixed !== $args) {
                        $msg .= "\n✅ <b>نسخه اصلاح‌شده:</b>\n" . htmlspecialchars(mb_substr($fixed, 0, 2500));
                    } else {
                        $msg .= "\n✅ متن شما از نظر نگارشی سالم است.";
                    }
                    $this->sendMessage($chatId, $msg, ['disable_preview' => true]);
                } catch (Throwable $e) {
                    $this->sendMessage($chatId, '❌ خطا در تحلیل نگارش: ' . htmlspecialchars($e->getMessage()));
                }
                return true;

            case '/suggest':
            case '/پیشنهاد':
                if ($args === '') {
                    $this->sendMessage($chatId,
                        "🎯 <b>پیشنهاد بهترین عنوان سئو</b> (فاز Q)\n\n" .
                        "عنوان دلخواه خود را بفرستید تا بهترین نسخه سئو را بگیرید:\n" .
                        "<code>/پیشنهاد یخچال سامسونگ سرد نمی‌کند</code>\n\n" .
                        "تحویل: امتیاز عنوان شما + ۸ پیشنهاد رتبه‌بندی‌شده (عدد/پرسش/واژه قدرت/سال) با میزان بهبود");
                    return true;
                }
                $this->sendChatAction($chatId, 'typing');
                try {
                    $result = (new TitleGenerator())->suggestForCustom($args, [], 8);
                    $msg = "🎯 <b>تحلیل عنوان شما</b>\n";
                    $msg .= '📏 ' . $result['original_analysis']['char_count'] . " کاراکتر — " . $result['original_analysis']['char_verdict'] . "\n";
                    $msg .= '🏆 امتیاز فعلی: <b>' . $result['original_score'] . "/۱۰۰</b>\n\n";
                    $msg .= "⭐ <b>بهترین پیشنهاد</b> (" . ($result['best_gain'] > 0 ? '+' . $result['best_gain'] : 'بدون تغییر') . " امتیاز):\n";
                    $msg .= '📰 <b>' . htmlspecialchars($result['best']) . "</b>\n\n";
                    $msg .= "📋 <b>سایر پیشنهادها:</b>\n";
                    foreach (array_slice($result['suggestions'], 1, 6) as $i => $s) {
                        $msg .= ($i + 2) . '. ' . htmlspecialchars($s['title']) . ' — ' . $s['score'] . "/۱۰۰";
                        if ($s['gain'] > 0) { $msg .= ' (+' . $s['gain'] . ')'; }
                        $msg .= "\n";
                    }
                    $this->sendMessage($chatId, $msg, ['disable_preview' => true]);
                } catch (Throwable $e) {
                    $this->sendMessage($chatId, '❌ خطا: ' . htmlspecialchars($e->getMessage()));
                }
                return true;

            case '/types':
            case '/انواع':
                $templates = TextProcessor::loadKnowledge('templates');
                $types = $templates['article_topics'] ?? [];
                $labels = [
                    'troubleshooting' => '🔧 رفع ایراد و مشکلات رایج', 'user_guide' => '📘 راهنمای استفاده',
                    'maintenance' => '🛡️ نگهداری و سرویس دوره‌ای', 'comparison' => '⚖️ مقایسه مدل‌ها',
                    'error_codes' => '🚨 کدهای خطا و ریست', 'buying_guide' => '💰 راهنمای خرید',
                    'energy_saving' => '⚡ صرفه‌جویی انرژی', 'seasonal_care' => '🌸 مراقبت فصلی',
                    'cost_guide' => '💵 راهنمای هزینه', 'safety_guide' => '⛑️ نکات ایمنی',
                    'installation_guide' => '🔩 نصب و راه‌اندازی', 'diy_vs_pro' => '🤔 تعمیر شخصی یا تخصصی',
                    'common_mistakes' => '❌ اشتباهات رایج', 'warranty_guide' => '📜 گارانتی و خدمات',
                    'tech_explainer' => '🔬 فناوری‌ها به زبان ساده', 'myths_facts' => '🎭 باور غلط و واقعیت',
                    'checklist' => '✅ چک‌لیست', 'case_study' => '📁 مطالعه موردی',
                    'glossary' => '📖 واژه‌نامه تخصصی', 'history_evolution' => '🕰️ تاریخچه و تکامل',
                    'expert_tips' => '🎓 نکات حرفه‌ای', 'symptom_focus' => '🩺 عیب‌یابی علامت‌محور',
                    'statistics' => '📊 آمار و ارقام', 'environment' => '🌿 محیط زیست',
                    'service_process' => '🏭 فرآیند تعمیر نمایندگی',
                ];
                $msg = "📰 <b>انواع مقاله — " . count($types) . " نوع</b> (فاز Q)\n\n";
                $i = 0;
                foreach (array_keys($types) as $key) {
                    $i++;
                    $msg .= $labels[$key] ?? htmlspecialchars($key);
                    $msg .= ($i % 3 === 0) ? "\n" : ' | ';
                }
                $msg .= "\n\n💡 در پنل سایت‌ساز هنگام تولید مقاله انتخاب می‌شوند؛ در تلگرام هم می‌توانید بنویسید: «مقاله آموزشی درباره یخچال بنویس»";
                $this->sendMessage($chatId, $msg, ['disable_preview' => true]);
                return true;

            default:
                $this->sendMessage($chatId, "🤔 فرمان ناشناخته: <code>" . htmlspecialchars($cmd) . "</code>\n\n📖 /help را ببینید.");
                return true;
        }
    }

    /* ==================================================
     * 📰 تحویل کامل مقاله (قلب نسخه ۲)
     * ================================================== */

    /**
     * 📦 ساخت مقاله کامل با خط تولید هوشمند و تحویل در تلگرام:
     *   ۱) پیام شروع + typing
     *   ۲) خلاصه (عنوان/امتیاز/کلیدواژه/تعداد کلمه)
     *   ۳) فایل HTML مستقل آماده انتشار برای هر سایت
     *   ۴) نسخه Markdown
     *   ۵) آلبوم ۳ تصویر
     *   ۶) دکمه‌های شیشه‌ای اقدام بعدی
     */
    public function deliverFullArticle(string $chatId, string $request): bool
    {
        $t0 = microtime(true);
        $this->sendChatAction($chatId, 'typing');
        $this->sendMessage($chatId,
            "⚙️ <b>خط تولید هوشمند فعال شد</b>\n" .
            "در حال ساخت مقاله کامل هستم: تحقیق آنلاین (در صورت فعال بودن) → تولید → بهبود کیفیت → سئو → ۳ تصویر\n" .
            "⏳ چند لحظه صبر کنید..."
        );

        try {
            $assistant = new CommandAssistant();
            $parsed = $assistant->handle($request);
            if (empty($parsed['success']) || ($parsed['action'] ?? '') !== 'article' || empty($parsed['result'])) {
                $this->sendMessage($chatId, '❌ ' . htmlspecialchars((string)($parsed['message'] ?? 'مقاله تولید نشد.')));
                return false;
            }
            $pkg = $parsed['result'];
            $took = (int)round((microtime(true) - $t0));

            /* ---------- ۱) خلاصه ---------- */
            $art = $pkg['article'] ?? [];
            $q = $pkg['quality'] ?? [];
            $kw = $pkg['keyword']['focus'] ?? '';
            $words = (int)($art['word_count'] ?? 0);
            $readMin = max(1, (int)ceil($words / 220));
            $summary =
                "✅ <b>مقاله آماده شد!</b> ({$took} ثانیه)\n\n" .
                '📰 <b>' . htmlspecialchars((string)($art['title'] ?? '')) . "</b>\n\n" .
                '🏆 کیفیت: <b>' . ($q['final_score'] ?? '؟') . '/100</b>' . (isset($q['grade']) ? ' (' . htmlspecialchars((string)$q['grade']) . ')' : '') . "\n" .
                "📝 حجم: <b>{$words}</b> کلمه (~{$readMin} دقیقه مطالعه)\n" .
                '🎯 کلیدواژه کانونی: <b>' . htmlspecialchars((string)$kw) . "</b>\n" .
                '🖼️ تصاویر: <b>' . count((array)($pkg['images'] ?? [])) . "</b>\n" .
                '❓ پرسش متداول: <b>' . count((array)($pkg['faq']['items'] ?? [])) . "</b>\n";
            if (!empty($pkg['research']['enabled'])) {
                $summary .= '🌐 تحقیق آنلاین: انجام‌شده (' . htmlspecialchars((string)($pkg['research']['provider'] ?? '')) . ")\n";
            }
            if (!empty($pkg['seo']['keyword_density'])) {
                $d = $pkg['seo']['keyword_density'];
                $summary .= '📊 تراکم کلیدواژه: <b>' . $d['percent'] . '٪</b> (' . htmlspecialchars((string)$d['status']) . ")\n";
            }
            $this->sendMessage($chatId, $summary);

            $warnings = [];

            /* ---------- ۲) فایل HTML مستقل (v2.8: شکست یک مرحله، بقیه تحویل را متوقف نمی‌کند) ---------- */
            $slug = SlugGenerator::generate((string)($art['title'] ?? 'article'));
            $htmlSent = false;
            try {
                $this->sendChatAction($chatId, 'upload_document');
                $html = $this->buildStandaloneHtml($pkg);
                $this->sendDocumentFromString($chatId, $slug . '.html', $html,
                    '📰 <b>نسخه HTML</b> — آماده انتشار برای هر سایت' .
                    "\nشامل: استایل داخلی + متا سئو + اسکیمای Article/FAQ + تصاویر با لینک مطلق"
                );
                $htmlSent = true;
            } catch (Throwable $e) {
                $warnings[] = 'فایل HTML: ' . $e->getMessage();
                @error_log('[TelegramBot] deliver article HTML: ' . $e->getMessage());
            }

            /* ---------- ۳) نسخه Markdown ---------- */
            $mdSent = false;
            try {
                $this->sendChatAction($chatId, 'upload_document');
                $md = $this->buildMarkdown($pkg);
                $this->sendDocumentFromString($chatId, $slug . '.md', $md,
                    '📝 <b>نسخه Markdown</b> — مناسب وردپرس/انجمن/ابزارهای محتوا'
                );
                $mdSent = true;
            } catch (Throwable $e) {
                $warnings[] = 'فایل Markdown: ' . $e->getMessage();
                @error_log('[TelegramBot] deliver article MD: ' . $e->getMessage());
            }

            /* ---------- ۳.۵) 🛟 نجات کامل: اگر هیچ فایلی نرسید، خود مقاله به‌صورت
                   پیام‌های متنی بخش‌بندی‌شده ارسال می‌شود تا محتوا هرگز از دست نرود ---------- */
            if (!$htmlSent && !$mdSent) {
                try {
                    $plain = "📄 <b>" . htmlspecialchars((string)($art['title'] ?? '')) . "</b>\n\n" .
                        strip_tags(preg_replace('#<br\s*/?>#i', "\n", (string)($art['content'] ?? '')));
                    foreach ($this->chunk($plain) as $partMsg) {
                        $this->sendMessage($chatId, $partMsg);
                    }
                    $warnings[] = 'فایل‌ها ارسال نشد — متن کامل مقاله به‌صورت پیام ارسال شد.';
                } catch (Throwable $e) {
                    $warnings[] = 'ارسال متنی جانشین هم ناموفق: ' . $e->getMessage();
                }
            }

            /* ---------- ۴) آلبوم ۳ تصویر ---------- */
            $images = (array)($pkg['images'] ?? []);
            if ($images) {
                try {
                    $this->sendChatAction($chatId, 'upload_photo');
                    $album = [];
                    foreach ($images as $img) {
                        $album[] = [
                            'path'    => dirname(__DIR__, 2) . '/' . ltrim((string)($img['path'] ?? ''), '/'),
                            'caption' => '🖼️ ' . htmlspecialchars((string)($img['alt'] ?? '')),
                        ];
                    }
                    $this->sendImageAlbum($chatId, $album);
                } catch (Throwable $e) {
                    $warnings[] = 'آلبوم تصاویر: ' . $e->getMessage();
                    @error_log('[TelegramBot] deliver album: ' . $e->getMessage());
                }
            }

            /* ---------- ۴.۵) هشدارهای تحویل (شفاف اما بدون قطع جریان) ---------- */
            if ($warnings) {
                $this->sendMessage($chatId, '⚠️ <b>نکته تحویل:</b>' . "\n" . '• ' . implode("\n• ", array_map('htmlspecialchars', $warnings)));
            }

            /* ---------- ۵) دکمه‌های اقدام بعدی ---------- */
            $topic = (string)($kw ?? '');
            $this->sendMessage($chatId, '🚀 <b>قدم بعدی؟</b>', [
                'inline_keyboard' => [
                    [
                        ['text' => '📰 مقاله دیگر', 'callback_data' => json_encode(['act' => 'article'], JSON_UNESCAPED_UNICODE)],
                        ['text' => '🔑 کلیدواژه‌ها', 'callback_data' => json_encode(['act' => 'keywords', 'topic' => $topic], JSON_UNESCAPED_UNICODE)],
                    ],
                    [
                        ['text' => '🧪 تحقیق آنلاین', 'callback_data' => json_encode(['act' => 'research', 'topic' => $topic], JSON_UNESCAPED_UNICODE)],
                        ['text' => '📰 اخبار مرتبط', 'callback_data' => json_encode(['act' => 'news', 'topic' => $topic], JSON_UNESCAPED_UNICODE)],
                    ],
                ],
            ]);
            return true;
        } catch (Throwable $e) {
            @error_log('[TelegramBot] deliverFullArticle error: ' . $e->getMessage());
            $this->sendMessage($chatId, '⚠️ خطا در تولید/تحویل مقاله: ' . htmlspecialchars($e->getMessage()));
            return false;
        }
    }

    /**
     * 🏗️ ساخت فایل HTML مستقل و کامل — قابل انتشار روی هر سایت دیگر
     * شامل: استایل inline RTL + متا + OG + Twitter + اسکیما + تصاویر مطلق
     */
    public function buildStandaloneHtml(array $pkg): string
    {
        $art = $pkg['article'] ?? [];
        $seo = $pkg['seo'] ?? [];
        $faq = $pkg['faq']['items'] ?? [];
        $title = (string)($art['title'] ?? 'مقاله');
        $content = (string)($art['content'] ?? '');
        $esc = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

        $ogTags = '';
        foreach ((array)($seo['og'] ?? []) as $p => $v) {
            $ogTags .= '  <meta property="' . $esc($p) . '" content="' . $esc($v) . '">' . "\n";
        }
        foreach ((array)($seo['twitter'] ?? []) as $p => $v) {
            $ogTags .= '  <meta name="' . $esc($p) . '" content="' . $esc($v) . '">' . "\n";
        }

        $schemas = [];
        if (!empty($seo['schema'])) {
            $schemas[] = $seo['schema'];
        }
        if (!empty($pkg['faq']['schema'])) {
            $schemas[] = $pkg['faq']['schema'];
        }
        if (!empty($seo['howto_schema'])) {
            $schemas[] = $seo['howto_schema'];
        }
        $schemaHtml = '';
        foreach ($schemas as $sc) {
            $schemaHtml .= '  <script type="application/ld+json">' .
                json_encode($sc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
        }

        $faqHtml = '';
        if ($faq) {
            $faqHtml = "<h2>❓ سوالات متداول</h2>\n";
            foreach ($faq as $item) {
                $faqHtml .= '<h3>' . $esc($item['question'] ?? '') . "</h3>\n" .
                    '<p>' . ($item['answer'] ?? '') . "</p>\n";
            }
        }

        $metaBlock = '';
        $keywords = (string)($seo['keywords'] ?? '');
        if ($keywords !== '') {
            $metaBlock .= "<!-- کلیدواژه‌ها: {$keywords} -->\n";
        }
        if (!empty($seo['reading_time'])) {
            $metaBlock .= '<!-- زمان مطالعه: ' . (int)$seo['reading_time'] . " دقیقه -->\n";
        }

        return '<!doctype html>' . "\n"
            . '<html lang="fa" dir="rtl">' . "\n"
            . "<head>\n"
            . '  <meta charset="utf-8">' . "\n"
            . '  <meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '  <title>' . $esc($seo['title'] ?? $title) . "</title>\n"
            . '  <meta name="description" content="' . $esc($seo['description'] ?? '') . "\">\n"
            . $ogTags
            . $schemaHtml
            . "  <style>\n"
            . "    body{font-family:Vazirmatn,Tahoma,'Segoe UI',Arial,sans-serif;line-height:2.1;color:#1e293b;background:#f8fafc;margin:0;padding:24px 14px;font-size:15px}\n"
            . "    .article-wrap{max-width:820px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:34px 30px;box-shadow:0 4px 20px rgba(0,0,0,.06)}\n"
            . "    h1{font-size:clamp(21px,4vw,30px);line-height:1.7;margin:0 0 14px}\n"
            . "    h2{font-size:19px;margin:30px 0 12px;border-inline-start:4px solid #3b82f6;padding-inline-start:12px}\n"
            . "    h3{font-size:16px;margin:20px 0 8px}\n"
            . "    p{margin:0 0 15px}\n"
            . "    ul,ol{margin:0 22px 15px 0}\n"
            . "    li{margin-bottom:7px}\n"
            . "    img{max-width:100%;height:auto;border-radius:12px;margin:18px 0}\n"
            . "    figcaption{text-align:center;font-size:12px;color:#64748b;margin-top:8px}\n"
            . "    table{width:100%;border-collapse:collapse;margin:18px 0;font-size:13.5px}\n"
            . "    th,td{border:1px solid #e2e8f0;padding:9px 13px;text-align:right}\n"
            . "    th{background:#f1f5f9}\n"
            . "    .article-toc{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin:20px 0}\n"
            . "    .article-eeat{background:#f8fafc;border:1px dashed #cbd5e1;border-radius:12px;padding:16px 20px;margin:24px 0}\n"
            . "    .meta-row{display:flex;gap:14px;flex-wrap:wrap;color:#64748b;font-size:12.5px;margin-bottom:20px}\n"
            . "    .generator-note{margin-top:26px;padding:12px 16px;background:#eff6ff;border-radius:10px;font-size:11.5px;color:#1e40af}\n"
            . "    @media(max-width:520px){.article-wrap{padding:20px 14px}}\n"
            . "  </style>\n"
            . "</head>\n"
            . "<body>\n"
            . '<article class="article-wrap">' . "\n"
            . '  <h1>' . $esc($title) . "</h1>\n"
            . '  <div class="meta-row"><span>🗓️ ' . $esc(jdate(date('Y-m-d'))) . '</span><span>✍️ تیم فنی سهند سرویس</span>'
            . '<span>⏱️ ' . ($seo['reading_time'] ?? 0) . ' دقیقه مطالعه</span></div>' . "\n"
            . $metaBlock
            . $content . "\n"
            . $faqHtml
            . '  <div class="generator-note">📦 این مقاله توسط موتور هوش مصنوعی سهند (نسخه ' . SahandAI::ENGINE_VERSION . ') تولید شده و آماده انتشار روی هر سایت است.</div>' . "\n"
            . "</article>\n"
            . "</body>\n"
            . "</html>";
    }

    /**
     * 📝 ساخت نسخه Markdown مقاله — مناسب وردپرس و ابزارهای محتوا
     */
    public function buildMarkdown(array $pkg): string
    {
        $art = $pkg['article'] ?? [];
        $seo = $pkg['seo'] ?? [];
        $faq = $pkg['faq']['items'] ?? [];
        $title = (string)($art['title'] ?? 'مقاله');

        $md = '# ' . $title . "\n\n";
        $md .= '> ' . ($art['tldr'] ?? ($seo['description'] ?? '')) . "\n\n";
        $md .= '**تاریخ تولید:** ' . jdate(date('Y-m-d')) . "  \n";
        $md .= '**کلیدواژه کانونی:** ' . ($pkg['keyword']['focus'] ?? '') . "  \n";
        $md .= '**امتیاز کیفیت:** ' . ($pkg['quality']['final_score'] ?? '؟') . '/100' . "  \n";
        $md .= '**تعداد کلمات:** ' . ($art['word_count'] ?? 0) . "\n\n";
        $md .= "---\n\n";

        // تبدیل HTML → Markdown سبک
        $c = (string)($art['content'] ?? '');
        $c = preg_replace('#<figure class="article-figure">\s*<img[^>]*src="([^"]+)"[^>]*alt="([^"]*)"[^>]*>\s*(?:<figcaption>(.*?)</figcaption>)?\s*</figure>#is',
            '![$2]($1)' . "\n\n*$3*\n", $c);
        $c = preg_replace('#<h2>(.*?)</h2>#is', "\n## $1\n\n", $c);
        $c = preg_replace('#<h3>(.*?)</h3>#is', "\n### $1\n\n", $c);
        $c = preg_replace('#<li>(.*?)</li>#is', '- $1 ', $c);
        $c = preg_replace('#<(strong|b)>(.*?)</\1>#is', '**$2**', $c);
        $c = preg_replace('#<(em|i)>(.*?)</\1>#is', '*$2*', $c);
        $c = preg_replace('#<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>#is', '[$2]($1)', $c);
        $c = preg_replace('#</(p|div|ul|ol|table|tr)>#i', "\n\n", $c);
        $c = preg_replace('#<br\s*/?>#i', "  \n", $c);
        $c = preg_replace('#<[^>]+>#', '', $c);
        $c = html_entity_decode($c, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $c = preg_replace("/\n{3,}/", "\n\n", $c);
        $md .= trim($c) . "\n\n---\n\n";

        if ($faq) {
            $md .= "## ❓ سوالات متداول\n\n";
            foreach ($faq as $item) {
                $md .= '### ' . ($item['question'] ?? '') . "\n\n" . strip_tags((string)($item['answer'] ?? '')) . "\n\n";
            }
        }
        $md .= '---' . "\n" . '📦 تولیدشده توسط موتور هوش مصنوعی سهند v' . SahandAI::ENGINE_VERSION . "\n";
        return $md;
    }

    /* ==================================================
     * 🎨 قالب‌بندی پاسخ‌ها
     * ================================================== */

    /**
     * 🎨 تبدیل خروجی CommandAssistant به پیام HTML تلگرام
     */
    public function formatAssistantReply(array $result): string
    {
        $action = (string)($result['action'] ?? 'unknown');
        $success = !empty($result['success']);
        $lines = [];

        // ✅/❌ پیام اصلی
        $icon = $success ? '✅' : '❌';
        $lines[] = "{$icon} " . htmlspecialchars((string)($result['message'] ?? ''));

        switch ($action) {
            case 'article':
                $lines[] = $this->formatArticle($result['result'] ?? []);
                break;

            case 'keywords':
                $r = $result['result'] ?? [];
                $comp = $r['competition'] ?? [];
                if (!empty($comp['level'])) {
                    $lines[] = "\n📊 رقابت: <b>" . htmlspecialchars((string)$comp['level']) . '</b>' .
                        (isset($comp['score']) ? ' (' . $comp['score'] . '/100)' : '');
                }
                foreach ((array)($r['longtail']['suggestions'] ?? []) as $i => $lt) {
                    if ($i >= 10) { break; }
                    $kw = is_array($lt) ? ($lt['keyword'] ?? '') : (string)$lt;
                    if ($kw !== '') { $lines[] = '• ' . htmlspecialchars($kw); }
                }
                break;

            case 'score':
                $r = $result['result'] ?? [];
                $lines[] = "\n🏆 امتیاز نهایی: <b>" . ($r['score'] ?? '؟') . '/100</b> — ' . htmlspecialchars((string)($r['grade'] ?? ''));
                if (!empty($r['dimensions']) && is_array($r['dimensions'])) {
                    $lines[] = "\n📈 ابعاد:";
                    foreach (array_slice($r['dimensions'], 0, 7, true) as $dim => $val) {
                        if (is_array($val) && isset($val['score'])) {
                            $lines[] = '• ' . htmlspecialchars((string)$dim) . ': ' . $val['score'] . '/100';
                        }
                    }
                }
                if (!empty($r['suggestions'])) {
                    $lines[] = "\n💡 پیشنهادها:";
                    foreach (array_slice((array)$r['suggestions'], 0, 5) as $s) {
                        $lines[] = '• ' . htmlspecialchars(is_array($s) ? (string)json_encode($s, JSON_UNESCAPED_UNICODE) : (string)$s);
                    }
                }
                break;

            case 'improve':
                $r = $result['result'] ?? [];
                $lines[] = "\n📈 قبل: <b>" . ($r['score_before'] ?? '؟') . "</b> → بعد: <b>" . ($r['score_after'] ?? '؟') . '</b>';
                // متن بهبودیافته (کوتاه‌شده)
                $content = strip_tags((string)($r['content'] ?? ''));
                if ($content !== '') {
                    $lines[] = "\n📝 <b>متن بهبودیافته:</b>\n" . htmlspecialchars(mb_substr($content, 0, 2500)) .
                        (mb_strlen($content) > 2500 ? "\n… (کامل در پاسخ API)" : '');
                }
                break;

            case 'titles':
                $r = $result['result'] ?? [];
                foreach ((array)($r['titles'] ?? []) as $i => $t) {
                    if ($i >= 8) { break; }
                    $title = is_array($t) ? ($t['title'] ?? '') : (string)$t;
                    $score = is_array($t) && isset($t['score']) ? ' — ' . $t['score'] : '';
                    if ($title !== '') { $lines[] = ($i + 1) . '. ' . htmlspecialchars($title) . $score; }
                }
                break;

            /* ---------- 🆕 v2.1: اکشن‌های موتور ۳.۳ ---------- */

            case 'seo':
                if (!empty($result['result'])) {
                    $lines[] = "\n" . $this->formatSeoReply($result['result']);
                }
                break;

            case 'eeat':
                if (!empty($result['result'])) {
                    $lines[] = "\n" . $this->formatEeatReply($result['result']);
                }
                break;

            case 'content_plan':
                $r = $result['result'] ?? [];
                if (!empty($r['weeks']) && is_array($r['weeks'])) {
                    foreach (array_slice($r['weeks'], 0, 8) as $i => $week) {
                        $lines[] = '🗓️ هفته ' . en_to_fa_digits((string)($i + 1)) . ': ' .
                            htmlspecialchars(mb_substr((string)($week['theme'] ?? $week['focus'] ?? ''), 0, 80));
                    }
                } elseif (!empty($r['topics'])) {
                    foreach (array_slice((array)$r['topics'], 0, 8) as $i => $topic) {
                        $t = is_array($topic) ? ($topic['title'] ?? '') : (string)$topic;
                        if ($t !== '') { $lines[] = ($i + 1) . '. ' . htmlspecialchars($t); }
                    }
                }
                break;

            case 'faq':
                $r = $result['result'] ?? [];
                foreach ((array)($r['faqs'] ?? []) as $i => $faq) {
                    if ($i >= 8) { break; }
                    $q = is_array($faq) ? ($faq['question'] ?? '') : (string)$faq;
                    if ($q !== '') {
                        $lines[] = '❓ ' . htmlspecialchars($q);
                        if (is_array($faq) && !empty($faq['answer'])) {
                            $lines[] = '   ' . htmlspecialchars(mb_substr((string)$faq['answer'], 0, 140)) . '…';
                        }
                    }
                }
                $lines[] = "\n💡 تولید کامل: از پنل مدیریت → برند → سوالات متداول";
                break;

            case 'intent':
                $r = $result['result'] ?? [];
                $lines[] = "\n🧭 نیت: <b>" . htmlspecialchars((string)($r['intent'] ?? '؟')) . '</b>' .
                    (isset($r['confidence']) ? ' (' . round((float)$r['confidence'] * 100) . '٪)' : '');
                if (!empty($r['reasons']) && is_array($r['reasons'])) {
                    foreach (array_slice($r['reasons'], 0, 3) as $reason) {
                        $lines[] = '• ' . htmlspecialchars((string)$reason);
                    }
                }
                break;

            case 'diagnose':
                $r = $result['result'] ?? [];
                foreach ((array)($r['levels'] ?? $r['diagnosis'] ?? []) as $lvl) {
                    if (is_array($lvl)) {
                        $label = (string)($lvl['level'] ?? ($lvl['symptom'] ?? ''));
                        $value = (string)($lvl['cause'] ?? ($lvl['part_name'] ?? ''));
                        if ($label !== '' || $value !== '') {
                            $lines[] = '• ' . htmlspecialchars($label . ($value !== '' ? ': ' . $value : ''));
                        }
                    }
                }
                if (!empty($r['advice'])) {
                    $lines[] = "\n💡 " . htmlspecialchars((string)$r['advice']);
                }
                break;

            case 'error-codes':
            case 'error_codes':
                $r = $result['result'] ?? [];
                $codes = $r['codes'] ?? ($r['matches'] ?? []);
                foreach ((array)$codes as $i => $c) {
                    if ($i >= 5) { break; }
                    if (is_array($c)) {
                        $lines[] = '⚠️ <b>' . htmlspecialchars((string)($c['code'] ?? '')) . '</b> — ' .
                            htmlspecialchars((string)($c['title_fa'] ?? ($c['description'] ?? '')));
                    }
                }
                break;

            case 'brands':
                $r = $result['result'] ?? [];
                $brands = $r['brands'] ?? ($r['list'] ?? []);
                $names = [];
                foreach ((array)$brands as $b) {
                    $names[] = is_array($b) ? (string)($b['name_fa'] ?? '') : (string)$b;
                }
                $names = array_filter($names);
                $lines[] = "\n📚 " . count($names) . ' برند: ' . htmlspecialchars(implode('، ', array_slice($names, 0, 30)));
                break;

            case 'web_search':
                $r = $result['result'] ?? [];
                foreach ((array)($r['results'] ?? []) as $i => $res) {
                    if ($i >= 6) { break; }
                    $lines[] = ($i + 1) . '. <a href="' . htmlspecialchars((string)($res['url'] ?? '#')) . '">' .
                        htmlspecialchars(mb_substr((string)($res['title'] ?? ''), 0, 80)) . '</a>';
                    if (!empty($res['snippet'])) {
                        $lines[] = '   <i>' . htmlspecialchars(mb_substr((string)$res['snippet'], 0, 120)) . '</i>';
                    }
                }
                break;

            case 'web_research':
                $r = $result['result'] ?? [];
                if (!empty($r['keywords'])) {
                    $lines[] = "\n🔑 کلیدواژه‌های ترند:";
                    foreach (array_slice((array)$r['keywords'], 0, 8) as $k) {
                        $kw = is_array($k) ? (string)($k['keyword'] ?? '') : (string)$k;
                        if ($kw !== '') { $lines[] = '• ' . htmlspecialchars($kw); }
                    }
                }
                if (!empty($r['questions'])) {
                    $lines[] = "\n❓ سؤالات واقعی کاربران:";
                    foreach (array_slice((array)$r['questions'], 0, 5) as $q) {
                        $lines[] = '• ' . htmlspecialchars(mb_substr((string)$q, 0, 100));
                    }
                }
                if (!empty($r['facts'])) {
                    $lines[] = "\n📊 داده‌های تازه:";
                    foreach (array_slice((array)$r['facts'], 0, 4) as $f) {
                        $lines[] = '• ' . htmlspecialchars(mb_substr((string)$f, 0, 130));
                    }
                }
                if (!empty($r['opportunities'])) {
                    $lines[] = "\n🎯 فرصت‌های کلیدواژه:";
                    foreach (array_slice((array)$r['opportunities'], 0, 5) as $op) {
                        $lines[] = '• ' . htmlspecialchars(mb_substr((string)$op, 0, 90));
                    }
                }
                if (!empty($r['sources'])) {
                    $lines[] = "\n🔗 منابع: " . count($r['sources']) . ' سایت';
                }
                break;

            case 'unknown':
            default:
                if (!empty($result['suggestions'])) {
                    $lines[] = "\n💡 نمونه فرمان‌ها:";
                    foreach (array_slice((array)$result['suggestions'], 0, 5) as $s) {
                        $lines[] = '• ' . htmlspecialchars((string)$s);
                    }
                }
                break;
        }

        // 💡 پیشنهادهای بعدی
        if (!empty($result['suggestions']) && $action !== 'unknown' && $action !== 'web_search' && $action !== 'article') {
            $lines[] = "\n🔎 فرمان بعدی پیشنهادی:";
            foreach (array_slice((array)$result['suggestions'], 0, 3) as $s) {
                $lines[] = '» ' . htmlspecialchars((string)$s);
            }
        }

        $lines[] = "\n🤖 موتور سهند v" . SahandAI::ENGINE_VERSION;
        return implode("\n", array_filter($lines, static fn($l) => trim($l) !== ''));
    }

    /** 📰 قالب اختصاصی پاسخ اخبار زنده */
    private function formatNewsReply(array $result): string
    {
        if (empty($result['success'])) {
            return '❌ ' . htmlspecialchars((string)($result['message'] ?? 'جستجوی اخبار ناموفق بود.'));
        }
        $r = $result['result'] ?? [];
        $lines = ['📰 <b>اخبار زنده</b> — ' . htmlspecialchars((string)($result['message'] ?? '')), ''];
        foreach ((array)($r['results'] ?? []) as $i => $news) {
            if ($i >= 8) { break; }
            $date = !empty($news['date']) ? ' <i>(' . htmlspecialchars((string)$news['date']) . ')</i>' : '';
            $lines[] = ($i + 1) . '. <a href="' . htmlspecialchars((string)($news['url'] ?? '#')) . '">' .
                htmlspecialchars(mb_substr((string)($news['title'] ?? ''), 0, 90)) . '</a>' . $date;
            if (!empty($news['source'])) {
                $lines[] = '   <i>' . htmlspecialchars((string)$news['source']) . '</i>';
            }
        }
        $lines[] = "\n🤖 موتور سهند v" . SahandAI::ENGINE_VERSION;
        return implode("\n", $lines);
    }

    /** 🏅 قالب‌بندی پکیج سئوی پیشرفته — v2.1 */
    private function formatSeoReply(array $seo): string
    {
        $lines = ['🏅 <b>پکیج سئوی پیشرفته</b> — موتور v' . SahandAI::ENGINE_VERSION, ''];

        $lines[] = '🏷️ <b>عنوان پیشنهادی:</b> ' . htmlspecialchars((string)($seo['title'] ?? ''));
        $lines[] = '📝 <b>متا دیسکریپشن:</b> ' . htmlspecialchars(mb_substr((string)($seo['description'] ?? ''), 0, 175));
        if (!empty($seo['keywords'])) {
            $lines[] = '🔑 <b>کلیدواژه‌ها:</b> ' . htmlspecialchars(mb_substr((string)$seo['keywords'], 0, 220));
        }

        // 🏅 E-E-A-T
        if (!empty($seo['eeat'])) {
            $lines[] = '';
            $lines[] = '✅ <b>E-E-A-T:</b> ' . (int)$seo['eeat']['passed'] . '/' . (int)$seo['eeat']['total'] .
                ' (' . (int)$seo['eeat']['score'] . '٪)';
        }

        // 🧠 موجودیت‌ها
        if (!empty($seo['entities'])) {
            $names = [];
            foreach ((array)$seo['entities'] as $ent) {
                $names[] = (string)($ent['name'] ?? '');
            }
            $names = array_filter($names);
            if ($names) {
                $lines[] = '🧠 <b>موجودیت‌ها:</b> ' . htmlspecialchars(implode('، ', array_slice($names, 0, 10)));
            }
        }

        // 🔗 خوشه کلیدواژه
        if (!empty($seo['secondary_keywords']['clusters'])) {
            $lines[] = '';
            $lines[] = '🔗 <b>خوشه‌های معنایی:</b>';
            $labels = ['informational' => 'اطلاعاتی', 'commercial' => 'تجاری', 'howto' => 'آموزشی'];
            foreach ($seo['secondary_keywords']['clusters'] as $type => $keywords) {
                $kw = array_slice((array)$keywords, 0, 2);
                $lines[] = '• ' . ($labels[$type] ?? $type) . ': ' . htmlspecialchars(implode(' | ', $kw));
            }
        }

        // ❓ People Also Ask
        if (!empty($seo['secondary_keywords']['people_also_ask'])) {
            $lines[] = '';
            $lines[] = '❓ <b>سوالات پرتکرار:</b>';
            foreach (array_slice((array)$seo['secondary_keywords']['people_also_ask'], 0, 3) as $q) {
                $lines[] = '• ' . htmlspecialchars((string)$q);
            }
        }

        $lines[] = '';
        $lines[] = '🤖 برای مقاله کامل با عکس: /article [موضوع]';
        return implode("\n", $lines);
    }

    /** ✅ قالب‌بندی چک‌لیست E-E-A-T — v2.1 */
    private function formatEeatReply(array $eeat): string
    {
        $lines = [
            '✅ <b>چک‌لیست E-E-A-T</b>',
            'امتیاز کلی: <b>' . (int)($eeat['score'] ?? 0) . '٪</b> (' .
            (int)($eeat['passed'] ?? 0) . ' از ' . (int)($eeat['total'] ?? 0) . ' معیار)',
            '',
        ];
        foreach ((array)($eeat['checks'] ?? []) as $check) {
            $icon = !empty($check['pass']) ? '✅' : '❌';
            $lines[] = $icon . ' ' . (string)($check['label'] ?? '');
            if (empty($check['pass']) && !empty($check['hint'])) {
                $lines[] = '   💡 ' . htmlspecialchars((string)$check['hint']);
            }
        }
        return implode("\n", $lines);
    }

    /** 📰 قالب‌بندی خروجی مقاله (خلاصه + آمار) */
    private function formatArticle(array $r): string
    {
        $out = [];
        if (!empty($r['article']['title'])) {
            $out[] = "\n📰 <b>" . htmlspecialchars((string)$r['article']['title']) . '</b>';
        }
        if (!empty($r['article']['tldr'])) {
            $out[] = "\n💡 " . htmlspecialchars(mb_substr(strip_tags((string)$r['article']['tldr']), 0, 300));
        }
        if (isset($r['quality']['final_score'])) {
            $out[] = "\n🏆 کیفیت: <b>" . $r['quality']['final_score'] . '/100</b>' .
                (isset($r['article']['word_count']) ? ' | 📝 ' . $r['article']['word_count'] . ' کلمه' : '');
        }
        if (!empty($r['keyword']['focus'])) {
            $out[] = "🎯 کلیدواژه: " . htmlspecialchars((string)$r['keyword']['focus']);
        }
        if (!empty($r['images'])) {
            $out[] = "🖼️ تصاویر: " . count($r['images']) . ' عدد';
        }
        if (!empty($r['research']['enabled'])) {
            $out[] = "🌐 تحقیق آنلاین: انجام‌شده ✅";
        }
        // بخشی از متن مقاله
        $content = strip_tags((string)($r['article']['content'] ?? ''));
        if ($content !== '') {
            $out[] = "\n📝 <b>پیش‌نمایش مقاله:</b>\n" . htmlspecialchars(mb_substr($content, 0, 1600)) .
                (mb_strlen($content) > 1600 ? "\n… (نسخه کامل همین‌جا تحویل داده می‌شود)" : '');
        }
        return implode("\n", $out);
    }

    /* ==================================================
     * ⌨️ کیبورد و متون ربات
     * ================================================== */

    /** ⌨️ کیبورد پیش‌فرض */
    protected function defaultKeyboard(): array
    {
        return [
            ['📰 مقاله بنویس', '🔑 کلمات کلیدی یخچال'],
            ['📝 نگارش: متن خود را اینجا بنویسید', '🎯 پیشنهاد عنوان سئو:'],
            ['🌐 جستجوی وب:', '📰 اخبار: لوازم خانگی'],
            ['📰 انواع مقاله', 'راهنما', 'برندهای پایگاه دانش'],
        ];
    }

    /** 📖 متن راهنمای ربات */
    protected function helpText(): string
    {
        return "🤖 <b>دستیار هوشمند سهند سرویس — v2</b>\n" .
            "موتور هوش مصنوعی داخلی نسخه " . SahandAI::ENGINE_VERSION . " — متصل به اینترنت 🌐\n\n" .
            "📰 <b>مقاله کامل + ۳ عکس (جدید!)</b>\n" .
            "«مقاله بنویس برای پاکشما درباره ماشین لباسشویی»\n" .
            "تحویل: فایل HTML آماده انتشار برای هر سایت + Markdown + آلبوم ۳ تصویر\n\n" .
            "💬 <b>کافیست فارسی بنویسید:</b>\n" .
            "🔑 «کلمات کلیدی یخچال»\n" .
            "🏆 «امتیاز این متن: ...»\n" .
            "🔧 «این متن را بهبود بده: ...»\n" .
            "📝 «نگارش: متن شما» — اصلاح نیم‌فاصله/سجاوندی/املای رایج/هم‌خوانی فعل و فاعل (جدید)\n" .
            "🎯 «پیشنهاد عنوان سئو: عنوان دلخواه» — بهترین نسخه سئو با امتیاز (جدید)\n" .
            "🏷️ «عنوان برای تعمیر ماشین ظرفشویی بده»\n" .
            "🧭 «نیت جستجوی خرید یخچال ساید بای ساید چیست؟»\n" .
            "🔍 «تشخیص عیب: یخچال سرد نمی‌کند»\n" .
            "⚠️ «کد خطا E24 ماشین ظرفشویی»\n" .
            "🌐 «جستجوی وب: قیمت موتور ماشین لباسشویی»\n" .
            "🧪 «تحقیق درباره: یخچال ساید بای ساید اسنوا»\n" .
            "📰 «اخبار: لوازم خانگی» (جدید)\n\n" .
            "⚙️ <b>فرمان‌ها:</b>\n" .
            "/article [موضوع] — مقاله کامل با فایل و عکس\n" .
            "/seo [موضوع/متن] — پکیج سئوی پیشرفته (جدید)\n" .
            "/grammar [متن] — اصلاح نگارش فارسی + امتیاز (جدید)\n" .
            "/suggest [عنوان] — پیشنهاد بهترین عنوان سئو (جدید)\n" .
            "/types — انواع مقاله (۲۵ نوع) (جدید)\n" .
            "/eeat [متن] — چک‌لیست اعتماد E-E-A-T (جدید)\n" .
            "/plan [موضوع] — برنامه انتشار محتوا (جدید)\n" .
            "/faq [موضوع] — سوالات متداول پیشنهادی (جدید)\n" .
            "/keywords [موضوع] — کلیدواژه و long-tail\n" .
            "/search [عبارت] — جستجوی آنلاین وب\n" .
            "/research [موضوع] — تحقیق ساختاریافته\n" .
            "/news [موضوع] — اخبار زنده\n" .
            "/titles [موضوع] — پیشنهاد عنوان\n" .
            "/improve [متن] — بهبود متن\n" .
            "/score [متن] — امتیازدهی متن\n" .
            "/start — شروع | /help — راهنما\n" .
            "/status — وضعیت سیستم | /id — شناسه چت شما";
    }

    /** 📊 متن وضعیت سیستم */
    private function statusText(): string
    {
        try {
            $info = (new SahandAI())->engineInfo();
            $lines = [
                '📊 <b>وضعیت سیستم سهند</b>',
                '',
                '🤖 موتور AI: نسخه ' . $info['engine_version'],
                '🏗️ سیستم: نسخه ' . $info['system_version'],
                '📚 برندهای دانش: ' . ($info['knowledge_size']['brands'] ?? '؟'),
                '🔧 دستگاه‌ها: ' . ($info['knowledge_size']['devices'] ?? '؟'),
                '⚠️ کدهای خطا: ' . ($info['knowledge_size']['error_codes'] ?? '؟'),
                '🖼️ تصاویر مقاله: ' . count(ArticleImageService::catalog()) . ' عدد',
            ];
            // وضعیت جستجوی وب
            try {
                $ws = (new WebSearchService())->status();
                $lines[] = '🌐 جستجوی وب: ' . ($ws['enabled'] ? '✅ فعال' : '❌ غیرفعال') .
                    ' — ' . count(array_filter($ws['providers'] ?? [])) . ' ارائه‌دهنده';
            } catch (Exception $e) {
                $lines[] = '🌐 جستجوی وب: ⚠️ ' . $e->getMessage();
            }
            $lines[] = '🗄️ دیتابیس: ✅';
            return implode("\n", $lines);
        } catch (Throwable $e) {
            return '⚠️ خطا در دریافت وضعیت: ' . $e->getMessage();
        }
    }

    /* ==================================================
     * 🔐 امنیت
     * ================================================== */

    /** آیا این چت مجاز است؟ (لیست خالی = فقط پیام راهنما) */
    public function isAllowed(string $chatId): bool
    {
        $allowed = array_filter(array_map('trim', explode(',', (string)($this->cfg['allowed_chat_ids'] ?? ''))));
        if (!$allowed) {
            return false; // لیست خالی → هیچ‌کس مجاز نیست (امنیتی)
        }
        if (in_array('*', $allowed, true)) {
            return true; // دسترسی همگانی (پیشنهاد نمی‌شود)
        }
        return in_array($chatId, $allowed, true);
    }

    /** 🔑 دریافت یا ساخت توکن مخفی وب‌هوک */
    public static function ensureWebhookSecret(): string
    {
        $cfg = (array)(Config::get('telegram_bot_settings') ?: []);
        $secret = (string)($cfg['webhook_secret'] ?? '');
        if ($secret === '') {
            $secret = 'tgk_' . bin2hex(random_bytes(20));
            $cfg['webhook_secret'] = $secret;
            Config::set('telegram_bot_settings', $cfg);
        }
        return $secret;
    }

    /* ==================================================
     * 📨 اعلان درخواست جدید به همه چت‌های مجاز
     * ================================================== */

    /**
     * 📨 ارسال اعلان درخواست خدمات جدید به چت‌های مجاز
     * (در صورت فعال بودن notify_new_request)
     */
    public static function notifyNewRequest(array $request, array $brand): bool
    {
        $cfg = (array)(Config::get('telegram_bot_settings') ?: []);
        if (empty($cfg['enabled']) || empty($cfg['notify_new_request']) || empty($cfg['bot_token'])) {
            return false;
        }
        try {
            $bot = new self((string)$cfg['bot_token'], $cfg);
            $name = (string)($request['name'] ?? '');
            $phone = (string)($request['phone'] ?? '');
            $device = (string)($request['device'] ?? '');
            $desc = mb_substr((string)($request['description'] ?? ''), 0, 300);
            $brandName = (string)($brand['name_fa'] ?? '');
            $text = "📨 <b>درخواست خدمات جدید</b>\n\n" .
                "🏷️ برند: " . htmlspecialchars($brandName) . "\n" .
                "👤 نام: " . htmlspecialchars($name) . "\n" .
                "📞 تلفن: <code>" . htmlspecialchars($phone) . "</code>\n" .
                ($device !== '' ? "🔧 دستگاه: " . htmlspecialchars($device) . "\n" : '') .
                ($desc !== '' ? "📝 توضیحات: " . htmlspecialchars($desc) . "\n" : '');
            $sent = false;
            foreach (array_filter(array_map('trim', explode(',', (string)($cfg['allowed_chat_ids'] ?? '')))) as $chatId) {
                if ($chatId === '*' || $chatId === '') { continue; }
                try {
                    $bot->sendMessage($chatId, $text, ['disable_preview' => true]);
                    $sent = true;
                } catch (Exception $e) {
                    @error_log('[TelegramBot] notify to ' . $chatId . ' failed: ' . $e->getMessage());
                }
            }
            return $sent;
        } catch (Throwable $e) {
            @error_log('[TelegramBot] notifyNewRequest error: ' . $e->getMessage());
            return false;
        }
    }
}

/* ==================================================
 * 📨 هندلر وب‌هوک — نقطه ورود تلگرام
 * ================================================== */

/**
 * 📨 پردازش درخواست وب‌هوک تلگرام
 * مسیر: POST /api/telegram/webhook
 * احراز هویت: هدر X-Telegram-Bot-Api-Secret-Token (توسط setWebhook تنظیم می‌شود)
 */
function telegram_webhook_handler(): void
{
    // فقط POST
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
        return;
    }

    $raw = (string)file_get_contents('php://input');
    $update = json_decode($raw, true);
    if (!is_array($update)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
        return;
    }

    $cfg = (array)(Config::get('telegram_bot_settings') ?: []);

    // 🔐 راستی‌آزمایی توکن مخفی (در صورت تنظیم)
    $expected = (string)($cfg['webhook_secret'] ?? '');
    $received = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    if ($expected !== '' && !hash_equals($expected, $received)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        return;
    }

    // 🚦 فعال بودن ربات
    if (empty($cfg['enabled']) || empty($cfg['bot_token'])) {
        http_response_code(200); // تلگرام روی غیر۲۰۰ دوباره می‌فرستد — رد بی‌صدا
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'skipped' => true]);
        return;
    }

    $bot = new TelegramBot((string)$cfg['bot_token'], $cfg);
    $handled = $bot->handleUpdate($update);

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'handled' => $handled]);
}
