<?php
/**
 * 🔄 حالت long-polling ربات تلگرام — برای تست محلی و هاست بدون SSL
 * ==================================================================
 * اجرا از خط فرمان:
 *   php telegram/poll.php            → حلقه بی‌نهایت (Ctrl+C برای توقف)
 *   php telegram/poll.php --once     → فقط یک بار getUpdates
 *
 * ⚠️ توجه: هنگام استفاده از وب‌هوک، این اسکریپت را اجرا نکنید
 * (تلگرام اجازه همزمانی getUpdates و webhook نمی‌دهد).
 *
 * @package SahandBrandMaker\Telegram
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('⛔ فقط از خط فرمان قابل اجراست.');
}

define('SAHAND_INIT', true);
define('SAHAND_NO_SESSION', true);
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/TelegramBot.php';

$once = in_array('--once', $argv ?? [], true);

$cfg = (array)(Config::get('telegram_bot_settings') ?: []);
if (empty($cfg['bot_token'])) {
    fwrite(STDERR, "❌ توکن ربات تنظیم نشده است.\n" .
        "ابتدا از پنل مدیریت → ربات تلگرام، توکن را از @BotFather وارد و ذخیره کنید.\n");
    exit(1);
}

$bot = new TelegramBot((string)$cfg['bot_token'], $cfg);

// 🤖 تست اتصال
try {
    $me = $bot->getMe();
    fwrite(STDOUT, "🤖 متصل شد: @{$me['username']} — {$me['first_name']}\n");
} catch (Exception $e) {
    fwrite(STDERR, '❌ اتصال به تلگرام ناموفق: ' . $e->getMessage() . "\n");
    exit(1);
}

$allowed = trim((string)($cfg['allowed_chat_ids'] ?? ''));
fwrite(STDOUT, $allowed === ''
    ? "⚠️ لیست شناسه‌های مجاز خالی است — هیچ پیامی پاسخ داده نمی‌شود.\n" .
      "   برای گرفتن شناسه، به ربات /id بفرستید و در پنل مدیریت مجاز کنید.\n"
    : "🔐 شناسه‌های مجاز: {$allowed}\n");

fwrite(STDOUT, $once ? "📡 دریافت یک‌باره آپدیت‌ها...\n" : "📡 شروع polling — Ctrl+C برای توقف\n");

$offset = 0;
$iterations = 0;

while (true) {
    try {
        $updates = $bot->api('getUpdates', [
            'offset'  => $offset,
            'timeout' => 25, // long poll
            'limit'   => 10,
            'allowed_updates' => ['message', 'callback_query'],
        ]);
        foreach ((array)$updates as $update) {
            $offset = (int)($update['update_id'] ?? $offset) + 1;
            $text = (string)($update['message']['text'] ?? '');
            $chatId = (string)($update['message']['chat']['id'] ?? '?');
            fwrite(STDOUT, "📩 [{$chatId}] {$text}\n");
            $handled = $bot->handleUpdate($update);
            fwrite(STDOUT, '   → ' . ($handled ? '✅ پردازش شد' : '⛔ رد شد') . "\n");
        }
    } catch (Exception $e) {
        fwrite(STDERR, '⚠️ ' . $e->getMessage() . "\n");
        sleep(3);
    }

    if ($once && ++$iterations >= 1) {
        break;
    }
    usleep(500000); // ۰.۵ ثانیه استراحت بین دورها
}
