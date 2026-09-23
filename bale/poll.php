<?php
/**
 * 🔄 حالت long-polling ربات بله — برای تست محلی و هاست بدون SSL
 * ==================================================================
 * اجرا از خط فرمان:
 *   php bale/poll.php            → حلقه بی‌نهایت (Ctrl+C برای توقف)
 *   php bale/poll.php --once     → فقط یک بار getUpdates
 *
 * @package SahandBrandMaker\Bale
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('⛔ فقط از خط فرمان قابل اجراست.');
}

define('SAHAND_INIT', true);
define('SAHAND_NO_SESSION', true);
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/BaleBot.php';

$once = in_array('--once', $argv ?? [], true);

$cfg = (array)(Config::get('bale_bot_settings') ?: []);
if (empty($cfg['bot_token'])) {
    fwrite(STDERR, "❌ توکن ربات بله تنظیم نشده است.\n" .
        "ابتدا از پنل مدیریت ← ربات بله، توکن را از @BotFather بله وارد و ذخیره کنید.\n");
    exit(1);
}

$bot = new BaleBot((string)$cfg['bot_token'], $cfg);

// 🤖 تست اتصال
try {
    $me = $bot->getMe();
    fwrite(STDOUT, '💬 متصل شد: @' . ($me['username'] ?? '') . ' — ' . ($me['first_name'] ?? 'ربات بله') . "\n");
} catch (Exception $e) {
    fwrite(STDERR, '❌ اتصال به بله ناموفق: ' . $e->getMessage() . "\n");
    exit(1);
}

$allowed = trim((string)($cfg['allowed_chat_ids'] ?? ''));
fwrite(STDOUT, $allowed === ''
    ? "⚠️ لیست شناسه‌های مجاز خالی است — هیچ پیامی پاسخ داده نمی‌شود.\n" .
      "   برای گرفتن شناسه، به ربات /id بفرستید و در پنل مدیریت مجاز کنید.\n"
    : "🔐 شناسه‌های مجاز: {$allowed}\n");

$offset = 0;
fwrite(STDOUT, "🔄 حالت polling فعال شد" . ($once ? ' (یک‌باره)' : ' — Ctrl+C برای توقف') . "\n\n");

do {
    try {
        $updates = $bot->api('getUpdates', [
            'offset'  => $offset,
            'timeout' => 25,
            'limit'   => 10,
        ]);
        foreach ($updates as $update) {
            $offset = (int)($update['update_id'] ?? 0) + 1;
            $chatId = (string)($update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? '');
            $text = (string)($update['message']['text'] ?? '');
            fwrite(STDOUT, '📩 [' . $chatId . '] ' . $text . "\n");
            $bot->handleUpdate($update);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '⚠️ ' . $e->getMessage() . "\n");
        sleep(3);
    }
} while (!$once);

fwrite(STDOUT, "\n✅ پایان.\n");
