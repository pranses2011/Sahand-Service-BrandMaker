<?php
/**
 * 📨 سرویس ارسال اعلان چندکاناله
 * ================================
 * ارسال درخواست خدمات به ۴ کانال:
 *   📧 ایمیل (mail/SMTP) | 📱 تلگرام مستقیم | 🔄 واسط Google Script | 💬 بله
 * نام + لوگوی برند در همه کانال‌ها ارسال می‌شود (الزام سند).
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class NotificationService
{
    /** @var array گزارش ارسال هر کانال */
    private $report = [];

    /**
     * 🚀 ارسال درخواست خدمات به همه کانال‌های فعال
     *
     * @param array $request داده‌های درخواست
     * @param array $brand   اطلاعات برند (نام + لوگو + دامنه)
     * @return array گزارش ['email'=>bool, 'telegram'=>bool, 'gscript'=>bool, 'bale'=>bool, 'bot'=>bool, 'errors'=>[]]
     */
    public function sendServiceRequest(array $request, array $brand): array
    {
        $errors = [];
        $result = ['email' => false, 'telegram' => false, 'gscript' => false, 'bale' => false, 'bot' => false];

        /* ---------- 📧 کانال ایمیل ---------- */
        $emailCfg = (array)(Config::get(Config::KEY_NOTIFY_EMAIL) ?: []);
        if (!empty($emailCfg['enabled']) && !empty($emailCfg['to'])) {
            try {
                $mailer = new Mailer();
                $result['email'] = $mailer->sendServiceRequest($emailCfg['to'], $request, $brand);
                if (!$result['email']) {
                    $errors[] = 'ایمیل: ارسال ناموفق (تنظیمات mail/SMTP را بررسی کنید)';
                }
            } catch (Exception $e) {
                $errors[] = 'ایمیل: ' . $e->getMessage();
            }
        }

        /* ---------- 📱 پیام تلگرام (قالب مشترک مستقیم و واسط) ---------- */
        $tgCfg = (array)(Config::get(Config::KEY_NOTIFY_TELEGRAM) ?: []);
        $message = $this->formatTelegramMessage($request, $brand);
        $logoUrl = !empty($brand['logo']) ? (strpos($brand['logo'], 'http') === 0 ? $brand['logo'] : BASE_URL . '/' . $brand['logo']) : '';

        /* ---------- 🤖 اعلان به ربات دستیار تلگرام (نسخه ۲.۱) ---------- */
        try {
            if (is_file(ROOT_PATH . '/telegram/TelegramBot.php')) {
                require_once ROOT_PATH . '/telegram/TelegramBot.php';
                $result['bot'] = TelegramBot::notifyNewRequest($request, $brand);
            }
        } catch (Throwable $e) {
            $errors[] = 'ربات تلگرام: ' . $e->getMessage();
        }

        // 📱 تلگرام مستقیم
        if (!empty($tgCfg['enabled']) && !empty($tgCfg['bot_token']) && !empty($tgCfg['chat_id'])) {
            $result['telegram'] = $this->sendTelegram($tgCfg['bot_token'], $tgCfg['chat_id'], $message, $logoUrl);
            if (!$result['telegram']) {
                $errors[] = 'تلگرام مستقیم: ناموفق (در صورت تحریم، واسط گوگل را فعال کنید)';
            }
        }

        // 🔄 واسط Google Apps Script — توکن از تنظیمات تلگرام خوانده می‌شود (خارج از اسکریپت)
        $gsCfg = (array)(Config::get(Config::KEY_NOTIFY_GSCRIPT) ?: []);
        if (!empty($gsCfg['enabled']) && !empty($gsCfg['webapp_url']) && !empty($tgCfg['bot_token']) && !empty($tgCfg['chat_id'])) {
            $result['gscript'] = $this->sendViaGoogleScript($gsCfg['webapp_url'], [
                'bot_token' => $tgCfg['bot_token'],
                'chat_id'   => $tgCfg['chat_id'],
                'message'   => $message,
                'photo_url' => $logoUrl,
                'caption'   => '🏷️ ' . ($brand['name_fa'] ?? ''),
            ]);
            if (!$result['gscript']) {
                $errors[] = 'واسط گوگل: ناموفق';
            }
        }

        /* ---------- 💬 پیام‌رسان بله ---------- */
        $baleCfg = (array)(Config::get(Config::KEY_NOTIFY_BALE) ?: []);
        if (!empty($baleCfg['enabled']) && !empty($baleCfg['bot_token']) && !empty($baleCfg['chat_id'])) {
            // پیام بدون HTML (بله از فرمت محدودتری پشتیبانی می‌کند)
            $plainMessage = trim(strip_tags(str_replace(['<b>', '</b>', '\n'], ['', '', "\n"], $message)));
            $result['bale'] = $this->sendMessageGeneric('https://tapi.bale.ai/bot' . $baleCfg['bot_token'] . '/sendMessage', [
                'chat_id' => $baleCfg['chat_id'],
                'text'    => $plainMessage,
            ]);
            if (!$result['bale']) {
                $errors[] = 'بله: ناموفق';
            }
        }

        // 📝 ثبت لاگ نتیجه ارسال
        $channels = array_filter($result);
        Logger::info('ارسال درخواست خدمات', [
            'brand'    => $brand['name_fa'] ?? '',
            'channels' => implode(',', array_keys($channels)) ?: 'هیچ کانالی فعال نیست',
        ]);

        $result['errors'] = $errors;
        return $result;
    }

    /**
     * 📝 ساخت پیام HTML تلگرام — شامل نام و لوگوی برند (الزام سند)
     */
    private function formatTelegramMessage(array $request, array $brand): string
    {
        $rows = [
            '👤 نام'          => $request['full_name'] ?? '-',
            '📞 تماس'         => fa_to_en_digits($request['phone'] ?? '-'),
            '📞 تماس دوم'     => fa_to_en_digits($request['phone2'] ?? '-'),
            '📍 آدرس'         => $request['address'] ?? '-',
            '🔧 دستگاه'       => ($request['device_name'] ?? $request['device_key'] ?? '-') . ($request['device_other'] ? ' (' . $request['device_other'] . ')' : ''),
            '📋 مدل'          => $request['device_model'] ?? '-',
            '📝 شرح ایراد'    => $request['description'] ?? '-',
            '📅 زمان ترجیحی'  => trim(($request['preferred_date'] ?? '') . ' ' . ($request['preferred_time'] ?? '')) ?: '-',
        ];
        $text = "📨 <b>درخواست خدمات جدید</b>\n\n";
        $text .= "🏷️ برند: <b>" . ($brand['name_fa'] ?? '') . "</b> (" . ($brand['name_en'] ?? '') . ")\n";
        $text .= "🌐 سایت: " . ($brand['domain'] ?? '') . "\n";
        $text .= "─────────────────\n";
        foreach ($rows as $label => $value) {
            if ($value !== '-' && $value !== '') {
                $text .= $label . ": " . $value . "\n";
            }
        }
        if (!empty($request['images']) && is_array($request['images'])) {
            $text .= "─────────────────\n🖼️ تصاویر پیوست: " . count($request['images']) . ' مورد';
        }
        $text .= "\n⏰ " . jdate(date('Y-m-d H:i:s'), true);
        return $text;
    }

    /**
     * 📱 ارسال مستقیم به Bot API تلگرام
     */
    private function sendTelegram(string $botToken, string $chatId, string $message, string $photoUrl = ''): bool
    {
        // 🖼️ ارسال لوگو ابتدا (اختیاری)
        if ($photoUrl !== '') {
            $this->sendMessageGeneric("https://api.telegram.org/bot{$botToken}/sendPhoto", [
                'chat_id' => $chatId,
                'photo'   => $photoUrl,
                'caption' => '🏷️ لوگوی برند',
            ]);
        }
        return $this->sendMessageGeneric("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $chatId,
            'text'    => $message,
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * 🔄 ارسال از طریق واسط Google Apps Script
     */
    private function sendViaGoogleScript(string $webAppUrl, array $payload): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        $ch = curl_init($webAppUrl);
        // 🔄 در صورت تحریم، درخواست از طریق ریدایرکت ۳۰۲ گوگل دنبال می‌شود
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $httpCode !== 200) {
            return false;
        }
        $data = json_decode((string)$response, true);
        return is_array($data) && !empty($data['success']);
    }

    /**
     * 🌐 ارسال درخواست HTTP POST عمومی (تلگرام/بله)
     */
    private function sendMessageGeneric(string $url, array $data): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $httpCode !== 200) {
            return false;
        }
        $data = json_decode((string)$response, true);
        return is_array($data) && !empty($data['ok']);
    }

    /**
     * 🔔 ثبت اعلان در پنل (جدول notifications)
     */
    public static function notify(int $userId, string $type, string $title, string $message = '', string $link = ''): void
    {
        try {
            Database::getInstance()->insert('notifications', [
                'user_id' => $userId ?: null,
                'type'    => $type,
                'title'   => mb_substr($title, 0, 250),
                'message' => mb_substr($message, 0, 2000),
                'link'    => $link ?: null,
            ]);
        } catch (Exception $e) {
            Logger::error('ثبت اعلان ناموفق', ['error' => $e->getMessage()]);
        }
    }
}
