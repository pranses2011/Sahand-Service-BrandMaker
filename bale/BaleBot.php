<?php
/**
 * 💬 BaleBot — ربات پیام‌رسان بله (v1.0)
 * ========================================
 * بله (Bale) Bot API سازگار با تلگرام است؛ این کلاس از TelegramBot ارث می‌برد و فقط
 * تفاوت‌ها را بازنویسی می‌کند:
 *
 *   🔗 آدرس API: https://tapi.bale.ai/bot{TOKEN}/{method}
 *   ⚙️ تنظیمات در کلید مستقل: bale_bot_settings (جدا از تلگرام)
 *   🖼 آلبوم عکس ندارد → ارسال تک‌به‌تک
 *   ⌨️ دکمه‌های اینلاین ندارد → کیبورد پاسخ (Reply)
 *   🔒 وب‌هوک: با پارامتر ?secret= راستی‌آزمایی می‌شود (بله هدر مخفی ندارد)
 *
 * @package SahandBrandMaker\Bale
 * @version 1.0
 */

if (!defined('SAHAND_INIT')) {
    http_response_code(403);
    exit('⛔ دسترسی مستقیم مجاز نیست.');
}

require_once dirname(__DIR__) . '/telegram/TelegramBot.php';

define('BALE_API_BASE', 'https://tapi.bale.ai/bot');

class BaleBot extends TelegramBot
{
    /** @var array تنظیمات بله (کلید مستقل) */
    private $baleCfg;

    /** @var string توکن (بازافشانی از private والد برای متدهای محلی) */
    protected $baleToken;

    public function __construct(?string $token = null, ?array $cfg = null)
    {
        $this->baleCfg = $cfg ?? (array)(Config::get('bale_bot_settings') ?: []);
        parent::__construct($token ?? (string)($this->baleCfg['bot_token'] ?? ''), $this->baleCfg);
    }

    /* ==================================================
     * 🔌 API بله — آدرس متفاوت + پیام‌های خطای بله
     * ================================================== */

    public function api(string $method, array $params = []): array
    {
        $token = (string)($this->baleCfg['bot_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('توکن ربات بله تنظیم نشده است — از پنل مدیریت ← ربات بله وارد کنید.');
        }
        $ch = curl_init(BALE_API_BASE . $token . '/' . $method);
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
            throw new RuntimeException('خطای شبکه بله: ' . ($err ?: "HTTP {$http}"));
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('پاسخ نامعتبر از بله.');
        }
        if (empty($data['ok'])) {
            throw new RuntimeException('خطای بله: ' . (string)($data['description'] ?? $data['message'] ?? 'نامشخص'));
        }
        return $data['result'] ?? [];
    }

    /* ==================================================
     * 🎨 سازگارسازی قابلیت‌های پشتیبانی‌نشده بله
     * ================================================== */

    /** 🖼 آلبوم در بله موجود نیست → ارسال تک‌به‌تک */
    public function sendImageAlbum($chatId, array $items): bool
    {
        $sent = 0;
        foreach (array_slice($items, 0, 3) as $item) {
            try {
                $this->api('sendPhoto', [
                    'chat_id' => $chatId,
                    'photo'   => $item['photo'] ?? '',
                    'caption' => mb_substr((string)($item['caption'] ?? ''), 0, 900),
                ]);
                $sent++;
            } catch (Throwable $e) {
                // عکس بعدی
            }
        }
        return $sent > 0;
    }

    /** 🎬 chat action در بله پشتیبانی نمی‌شود — بی‌اثر */
    public function sendChatAction($chatId, string $action = 'typing'): void
    {
        // بله: بدون عملیات
    }

    /** 🔐 هویت مجاز — با تنظیمات مستقل بله */
    public function isAllowed(string $chatId): bool
    {
        $allowed = trim((string)($this->baleCfg['allowed_chat_ids'] ?? ''));
        if ($allowed === '') {
            return false; // امنیت: بدون لیست مجاز، هیچ‌کس
        }
        foreach (preg_split('/[\s,،]+/u', $allowed) as $id) {
            if (trim($id) === (string)$chatId) {
                return true;
            }
        }
        return false;
    }

    /** ⌨️ کیبورد پیش‌فرض بله (فقط دکمه‌های پاسخ) */
    protected function defaultKeyboard(): array
    {
        return [
            ['📰 مقاله بنویس', '🔑 کلمات کلیدی'],
            ['📝 نگارش:', '🎯 پیشنهاد عنوان سئو:'],
            ['🌐 جستجوی وب:', '📰 اخبار:'],
            ['راهنما', 'برندهای پایگاه دانش'],
        ];
    }

    /** 📖 راهنمای مخصوص بله */
    protected function helpText(): string
    {
        return "💬 <b>دستیار هوشمند سهند سرویس در بله</b>\n" .
            "موتور هوش مصنوعی داخلی نسخه " . SahandAI::ENGINE_VERSION . "\n\n" .
            "کافیست فارسی بنویسید:\n" .
            "🔑 «کلمات کلیدی یخچال»\n" .
            "🏆 «امتیاز این متن: ...»\n" .
            "🌐 «جستجوی وب: کد خطای OE لباسشویی ال‌جی»\n" .
            "📰 «مقاله بنویس برای پاکشما درباره ماشین لباسشویی»\n\n" .
            "🛡️ پاسخ فقط به شناسه‌های مجاز (تنظیم از پنل سایت‌ساز).";
    }

    /* ==================================================
     * 📮 وب‌هوک بله
     * ================================================== */

    public function setWebhook(string $publicUrl, string $secret): array
    {
        // بله هدر مخفی نمی‌پذیرد — راز به‌صورت پارامتر به URL افزوده می‌شود
        $url = $secret !== '' ? $publicUrl . '?secret=' . urlencode($secret) : $publicUrl;
        return $this->api('setWebhook', ['url' => $url]);
    }

    public function deleteWebhook(): array
    {
        return $this->api('deleteWebhook');
    }

    public function getWebhookInfo(): array
    {
        return $this->api('getWebhookInfo');
    }
}

/**
 * 📮 هندلر وب‌هوک بله — POST /api/bale/webhook
 * راستی‌آزمایی: پارامتر مخفی ?secret=... (بله هدر اختصاصی مثل تلگرام نمی‌فرستد)
 */
function bale_webhook_handler(): void
{
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

    $cfg = (array)(Config::get('bale_bot_settings') ?: []);

    // 🔐 راستی‌آزمایی راز مشترک (پارامتر query — سازگار با محدودیت بله)
    $expected = (string)($cfg['webhook_secret'] ?? '');
    $received = (string)($_GET['secret'] ?? '');
    if ($expected !== '' && !hash_equals($expected, $received)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        return;
    }

    if (empty($cfg['enabled']) || empty($cfg['bot_token'])) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'skipped' => true]);
        return;
    }

    $bot = new BaleBot(null, $cfg);
    $handled = $bot->handleUpdate($update);

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'handled' => $handled]);
}
