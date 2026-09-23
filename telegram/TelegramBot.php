<?php
/**
 * 🤖 ربات تلگرام متصل به دستیار فارسی سهند — TelegramBot v1.0
 * ==============================================================
 * پل ارتباطی بین تلگرام و موتور هوش مصنوعی سهند:
 * کاربر در تلگرام فارسی می‌نویسد → CommandAssistant پردازش
 * می‌کند → پاسخ قالب‌بندی‌شده HTML برگردانده می‌شود.
 *
 * قابلیت‌ها:
 *   💬 گفتگو با دستیار فارسی (زبان طبیعی — ۱۱ عملیات)
 *   🌐 جستجوی آنلاین وب و تحقیق ساختاریافته
 *   📰 تولید مقاله، کلیدواژه، امتیازدهی، عیب‌یابی و ...
 *   ⚙️ فرمان‌های مدیریتی: /start /help /status /id
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
 * @version 1.0.0
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
     * 🔗 فراخوانی متد Bot API تلگرام
     *
     * @return array پاسخ JSON — throw در صورت خطای شبکه/توکن
     */
    public function api(string $method, array $params = []): array
    {
        if ($this->token === '') {
            throw new RuntimeException('توکن ربات تنظیم نشده است — از پنل مدیریت وارد کنید.');
        }
        $ch = curl_init(TELEGRAM_API_BASE . $this->token . '/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 25,
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
        return $data['result'] ?? [];
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
     * ✉️ ارسال پیام
     * ================================================== */

    /**
     * ✉️ ارسال پیام HTML — تقسیم خودکار پیام‌های بلند
     *
     * @param int|string $chatId شناسه چت مقصد
     * @param string     $text   متن پیام (HTML مجاز)
     * @param array      $opts   [disable_preview, keyboard]
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
            }
            $msg = $this->api('sendMessage', $params);
            $lastId = (int)($msg['message_id'] ?? 0);
        }
        return $lastId;
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

            // 💬 هدایت به دستیار فارسی موتور سهند
            $assistant = new CommandAssistant();
            $result = $assistant->handle($text);
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

    /** ⚙️ فرمان‌های مدیریتی ربات */
    private function handleCommand(string $chatId, string $text, array $from): bool
    {
        $cmd = strtolower(trim(preg_split('/[@\s]/', $text)[0]));

        switch ($cmd) {
            case '/start':
                $welcome = (string)($this->cfg['welcome_text'] ?? '');
                if ($welcome === '') {
                    $welcome = 'سلام! من دستیار هوشمند سهند سرویس هستم. هر سؤال یا دستور فارسی بنویسید تا کمکتان کنم.';
                }
                $name = trim((string)($from['first_name'] ?? ''));
                $this->sendMessage($chatId,
                    ($name !== '' ? "{$name} عزیز، خوش آمدید! 👋\n\n" : '') . $welcome . "\n\n" .
                    "📖 برای دیدن امکانات، /help را بفرستید.",
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

            default:
                $this->sendMessage($chatId, "🤔 فرمان ناشناخته: <code>" . htmlspecialchars($cmd) . "</code>\n\n📖 /help را ببینید.");
                return true;
        }
    }

    /** ⌨️ کیبورد پیش‌فرض */
    private function defaultKeyboard(): array
    {
        return [
            ['راهنما', 'برندهای پایگاه دانش'],
            ['کلمات کلیدی یخچال', 'عنوان برای تعمیر کولر گازی بده'],
        ];
    }

    /** 📖 متن راهنمای ربات */
    private function helpText(): string
    {
        return "🤖 <b>دستیار هوشمند سهند سرویس</b>\n" .
            "موتور هوش مصنوعی داخلی نسخه " . SahandAI::ENGINE_VERSION . " — متصل به اینترنت 🌐\n\n" .
            "💬 <b>کافیست فارسی بنویسید:</b>\n" .
            "📰 «مقاله بنویس برای پاکشما درباره ماشین لباسشویی»\n" .
            "🔑 «کلمات کلیدی یخچال»\n" .
            "🏆 «امتیاز این متن: ...»\n" .
            "🔧 «این متن را بهبود بده: ...»\n" .
            "🏷️ «عنوان برای تعمیر ماشین ظرفشویی بده»\n" .
            "🧭 «نیت جستجوی خرید یخچال ساید بای ساید چیست؟»\n" .
            "🔍 «تشخیص عیب: یخچال سرد نمی‌کند»\n" .
            "⚠️ «کد خطا E24 ماشین ظرفشویی»\n" .
            "🌐 «جستجوی وب: قیمت موتور ماشین لباسشویی»\n" .
            "🧪 «تحقیق درباره: یخچال ساید بای ساید اسنوا»\n\n" .
            "⚙️ <b>فرمان‌ها:</b>\n" .
            "/start — شروع\n/help — راهنما\n/status — وضعیت سیستم\n/id — شناسه چت شما";
    }

    /** 📊 متن وضعیت سیستم */
    private function statusText(): string
    {
        try {
            $info = (new SahandAI())->engineInfo();
            $dbOk = '✅';
            $lines = [
                '📊 <b>وضعیت سیستم سهند</b>',
                '',
                '🤖 موتور AI: نسخه ' . $info['engine_version'],
                '🏗️ سیستم: نسخه ' . $info['system_version'],
                '📚 برندهای دانش: ' . ($info['knowledge_size']['brands'] ?? '؟'),
                '🔧 دستگاه‌ها: ' . ($info['knowledge_size']['devices'] ?? '؟'),
                '⚠️ کدهای خطا: ' . ($info['knowledge_size']['error_codes'] ?? '؟'),
            ];
            // وضعیت جستجوی وب
            try {
                $ws = (new WebSearchService())->status();
                $lines[] = '🌐 جستجوی وب: ' . ($ws['enabled'] ? '✅ فعال' : '❌ غیرفعال');
            } catch (Exception $e) {
                $lines[] = '🌐 جستجوی وب: ⚠️ ' . $e->getMessage();
            }
            $lines[] = '🗄️ دیتابیس: ' . $dbOk;
            return implode("\n", $lines);
        } catch (Throwable $e) {
            return '⚠️ خطا در دریافت وضعیت: ' . $e->getMessage();
        }
    }

    /* ==================================================
     * 🎨 قالب‌بندی پاسخ دستیار → HTML تلگرام
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
        if (!empty($result['suggestions']) && $action !== 'unknown' && $action !== 'web_search') {
            $lines[] = "\n🔎 فرمان بعدی پیشنهادی:";
            foreach (array_slice((array)$result['suggestions'], 0, 3) as $s) {
                $lines[] = '» ' . htmlspecialchars((string)$s);
            }
        }

        $lines[] = "\n🤖 موتور سهند v" . SahandAI::ENGINE_VERSION;
        return implode("\n", array_filter($lines, static fn($l) => trim($l) !== ''));
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
        if (!empty($r['research']['enabled'])) {
            $out[] = "🌐 تحقیق آنلاین: انجام‌شده ✅";
        }
        // بخشی از متن مقاله
        $content = strip_tags((string)($r['article']['content'] ?? ''));
        if ($content !== '') {
            $out[] = "\n📝 <b>پیش‌نمایش مقاله:</b>\n" . htmlspecialchars(mb_substr($content, 0, 2000)) .
                (mb_strlen($content) > 2000 ? "\n… (متن کامل از پنل یا API)" : '');
        }
        return implode("\n", $out);
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
