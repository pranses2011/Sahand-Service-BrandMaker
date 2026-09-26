<?php
/**
 * 📨 سرویس ارسال اعلان چندکاناله
 * ================================
 * ارسال درخواست خدمات به ۴ کانال:
 *   📧 ایمیل (mail/SMTP) | 📱 تلگرام مستقیم | 🔄 واسط Google Script | 💬 بله
 *
 * 🆕 v2.30 — درخواست کاربر: «در ارسال درخواست به ایمیل و تلگرام و بله،
 * لوگوی برند و لوگوی نمایندگی هم باشه» + «ناقص ارسال میکنه»:
 *   ① لوگوی برند + لوگوی نمایندگی در همه کانال‌ها ارسال می‌شود
 *   ② تصاویر پیوست درخواست واقعاً ارسال می‌شوند (قبلاً فقط تعداد!)
 *   ③ تصاویر محلی با multipart آپلود می‌شوند (حتی اگر URL عمومی در
 *      دسترس تلگرام/بله نباشد) + fallback به URL
 *
 * @package SahandBrandMaker
 * @version 1.2.0
 */
class NotificationService
{
    /** @var array گزارش ارسال هر کانال */
    private $report = [];

    /**
     * 🔗 تبدیل مسیر نسبی به مطلق + مسیر دیسک برای فایل‌های محلی
     */
    private static function resolveAsset(string $path): array
    {
        $path = trim((string)$path);
        if ($path === '') {
            return ['', ''];
        }
        $absUrl = (strpos($path, 'http') === 0) ? $path : BASE_URL . '/' . ltrim($path, '/');
        $diskPath = (strpos($path, 'http') === 0) ? '' : ROOT_PATH . '/' . ltrim($path, '/');
        return [$absUrl, is_file($diskPath) ? $diskPath : ''];
    }

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

        /* 🏢 اطلاعات نمایندگی (v2.30 — لوگو و نام در همه کانال‌ها) */
        $agencyName = (string)(Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس');
        $agencyLogo = (string)(Config::get(Config::KEY_AGENCY_LOGO) ?: '');
        [$agencyLogoUrl, $agencyLogoDisk] = self::resolveAsset($agencyLogo);

        /* 🖼️ تصاویر پیوست درخواست (مسیرهای نسبی/مطلق) */
        $requestImages = [];
        if (!empty($request['images']) && is_array($request['images'])) {
            foreach (array_slice($request['images'], 0, MAX_REQUEST_IMAGES) as $img) {
                $img = trim((string)$img);
                if ($img !== '') {
                    $requestImages[] = $img;
                }
            }
        }

        /* ---------- 📧 کانال ایمیل ---------- */
        $emailCfg = (array)(Config::get(Config::KEY_NOTIFY_EMAIL) ?: []);
        if (!empty($emailCfg['enabled']) && !empty($emailCfg['to'])) {
            try {
                $mailer = new Mailer();
                $result['email'] = $mailer->sendServiceRequest($emailCfg['to'], $request, $brand, [
                    'agency_name' => $agencyName,
                    'agency_logo' => $agencyLogoUrl,
                ]);
                if (!$result['email']) {
                    $errors[] = 'ایمیل: ارسال ناموفق (تنظیمات mail/SMTP را بررسی کنید)';
                }
            } catch (Exception $e) {
                $errors[] = 'ایمیل: ' . $e->getMessage();
            }
        }

        /* ---------- 📱 پیام تلگرام (قالب مشترک مستقیم و واسط) ---------- */
        $tgCfg = (array)(Config::get(Config::KEY_NOTIFY_TELEGRAM) ?: []);
        $message = $this->formatTelegramMessage($request, $brand, $agencyName);
        $logoUrl = '';
        if (!empty($brand['logo'])) {
            [$logoUrl] = self::resolveAsset((string)$brand['logo']);
        }

        /* ---------- 🤖 اعلان به ربات دستیار تلگرام (نسخه ۲.۱) ---------- */
        try {
            if (is_file(ROOT_PATH . '/telegram/TelegramBot.php')) {
                require_once ROOT_PATH . '/telegram/TelegramBot.php';
                $result['bot'] = TelegramBot::notifyNewRequest($request, $brand);
            }
        } catch (Throwable $e) {
            $errors[] = 'ربات تلگرام: ' . $e->getMessage();
        }

        // 📱 تلگرام مستقیم — لوگوها + تصاویر + پیام کامل
        if (!empty($tgCfg['enabled']) && !empty($tgCfg['bot_token']) && !empty($tgCfg['chat_id'])) {
            $botToken = (string)$tgCfg['bot_token'];
            $chatId = (string)$tgCfg['chat_id'];
            /* 🖼️ لوگوی برند (در صورت وجود) */
            if ($logoUrl !== '') {
                $this->sendPhotoGeneric("https://api.telegram.org/bot{$botToken}/sendPhoto", $chatId, $brand['logo'], '🏷️ لوگوی برند: ' . ($brand['name_fa'] ?? ''));
            }
            /* 🏢 لوگوی نمایندگی (در صورت وجود — درخواست v2.30) */
            if ($agencyLogo !== '') {
                $this->sendPhotoGeneric("https://api.telegram.org/bot{$botToken}/sendPhoto", $chatId, $agencyLogo, '🏢 نمایندگی: ' . $agencyName);
            }
            /* 🖼️ تصاویر پیوست درخواست — واقعاً ارسال شوند (رفع «ناقص») */
            $imgIdx = 0;
            foreach ($requestImages as $img) {
                $imgIdx++;
                $this->sendPhotoGeneric("https://api.telegram.org/bot{$botToken}/sendPhoto", $chatId, $img, '🖼️ تصویر پیوست ' . self::faNum($imgIdx) . ' از ' . self::faNum(count($requestImages)));
            }
            $result['telegram'] = $this->sendMessageGeneric("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text'    => $message,
                'parse_mode' => 'HTML',
            ]);
            if (!$result['telegram']) {
                $errors[] = 'تلگرام مستقیم: ناموفق (در صورت تحریم، واسط گوگل را فعال کنید)';
            }
        }

        // 🔄 واسط Google Apps Script — توکن از تنظیمات تلگرام خوانده می‌شود (خارج از اسکریپت)
        $gsCfg = (array)(Config::get(Config::KEY_NOTIFY_GSCRIPT) ?: []);
        if (!empty($gsCfg['enabled']) && !empty($gsCfg['webapp_url']) && !empty($tgCfg['bot_token']) && !empty($tgCfg['chat_id'])) {
            $result['gscript'] = $this->sendViaGoogleScript($gsCfg['webapp_url'], [
                'bot_token'  => $tgCfg['bot_token'],
                'chat_id'    => $tgCfg['chat_id'],
                'message'    => $message,
                'photo_url'  => $logoUrl ?: $agencyLogoUrl,
                'photo_urls' => array_values(array_filter(array_merge(
                    $logoUrl ? [$logoUrl] : [],
                    $agencyLogoUrl ? [$agencyLogoUrl] : [],
                    array_map(static fn($i) => self::resolveAsset($i)[0], $requestImages)
                ))),
                'caption'    => '🏷️ ' . ($brand['name_fa'] ?? '') . ($agencyName ? ' | 🏢 ' . $agencyName : ''),
            ]);
            if (!$result['gscript']) {
                $errors[] = 'واسط گوگل: ناموفق';
            }
        }

        /* ---------- 💬 پیام‌رسان بله ---------- */
        $baleCfg = (array)(Config::get(Config::KEY_NOTIFY_BALE) ?: []);
        if (!empty($baleCfg['enabled']) && !empty($baleCfg['bot_token']) && !empty($baleCfg['chat_id'])) {
            $baleToken = (string)$baleCfg['bot_token'];
            $baleChat = (string)$baleCfg['chat_id'];
            // پیام بدون HTML (بله از فرمت محدودتری پشتیبانی می‌کند)
            $plainMessage = trim(strip_tags(str_replace(['<b>', '</b>', '\n'], ['', '', "\n"], $message)));
            /* 🖼️ v2.30 — بله هم لوگوها و تصاویر را دریافت می‌کند (قبلاً فقط متن!) */
            if ($logoUrl !== '') {
                $this->sendPhotoGeneric('https://tapi.bale.ai/bot' . $baleToken . '/sendPhoto', $baleChat, $brand['logo'], '🏷️ لوگوی برند: ' . ($brand['name_fa'] ?? ''));
            }
            if ($agencyLogo !== '') {
                $this->sendPhotoGeneric('https://tapi.bale.ai/bot' . $baleToken . '/sendPhoto', $baleChat, $agencyLogo, '🏢 نمایندگی: ' . $agencyName);
            }
            $imgIdx = 0;
            foreach ($requestImages as $img) {
                $imgIdx++;
                $this->sendPhotoGeneric('https://tapi.bale.ai/bot' . $baleToken . '/sendPhoto', $baleChat, $img, '🖼️ تصویر پیوست ' . self::faNum($imgIdx) . ' از ' . self::faNum(count($requestImages)));
            }
            $result['bale'] = $this->sendMessageGeneric('https://tapi.bale.ai/bot' . $baleToken . '/sendMessage', [
                'chat_id' => $baleChat,
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
            'images'   => count($requestImages),
        ]);

        $result['errors'] = $errors;
        return $result;
    }

    /**
     * 🔢 عدد فارسی (برای کپشن تصاویر)
     */
    private static function faNum(int $n): string
    {
        return strtr((string)$n, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    /**
     * 📝 ساخت پیام HTML تلگرام — شامل نام برند و نمایندگی (v2.30)
     */
    private function formatTelegramMessage(array $request, array $brand, string $agencyName = ''): string
    {
        $rows = [
            '👤 نام'          => $request['full_name'] ?? '-',
            '📞 تماس'         => fa_to_en_digits($request['phone'] ?? '-'),
            '📞 تماس دوم'     => fa_to_en_digits($request['phone2'] ?? '-'),
            '📍 آدرس'         => $request['address'] ?? '-',
            '🔧 دستگاه'       => ($request['device_name'] ?? $request['device_key'] ?? '-') . (!empty($request['device_other']) ? ' (' . $request['device_other'] . ')' : ''),
            '📋 مدل'          => $request['device_model'] ?? '-',
            '📝 شرح ایراد'    => $request['description'] ?? '-',
            '📅 زمان ترجیحی'  => trim(($request['preferred_date'] ?? '') . ' ' . ($request['preferred_time'] ?? '')) ?: '-',
        ];
        $text = "📨 <b>درخواست خدمات جدید</b>\n\n";
        $text .= "🏷️ برند: <b>" . ($brand['name_fa'] ?? '') . "</b> (" . ($brand['name_en'] ?? '') . ")\n";
        $text .= "🌐 سایت: " . ($brand['domain'] ?? '') . "\n";
        if ($agencyName !== '') {
            $text .= "🏢 نمایندگی: <b>" . $agencyName . "</b>\n";
        }
        $text .= "─────────────────\n";
        foreach ($rows as $label => $value) {
            if ($value !== '-' && $value !== '') {
                $text .= $label . ": " . $value . "\n";
            }
        }
        if (!empty($request['images']) && is_array($request['images'])) {
            /* تصاویر بالاتر با sendPhoto ارسال شده‌اند — اینجا فقط ارجاع متنی */
            $text .= "─────────────────\n🖼️ تصاویر پیوست: " . count($request['images']) . ' مورد (بالای این پیام ارسال شد)';
        }
        $text .= "\n⏰ " . jdate(date('Y-m-d H:i:s'), true);
        return $text;
    }

    /**
     * 📱 ارسال مستقیم به Bot API تلگرام (قدیمی — برای سازگاری)
     */
    private function sendTelegram(string $botToken, string $chatId, string $message, string $photoUrl = ''): bool
    {
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
     * 🖼️ v2.30 — ارسال عکس به Bot API (تلگرام/بله)
     * فایل محلی → multipart آپلود (مطمئن‌ترین راه حتی بدون URL عمومی)
     * فایل خارجی → پارامتر photo با URL
     */
    private function sendPhotoGeneric(string $apiUrl, string $chatId, string $photoPathOrUrl, string $caption = ''): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        [$absUrl, $diskPath] = self::resolveAsset($photoPathOrUrl);
        if ($absUrl === '') {
            return false;
        }

        $ch = curl_init($apiUrl);
        if ($diskPath !== '' && filesize($diskPath) > 0 && filesize($diskPath) < 9 * 1024 * 1024) {
            /* 📤 آپلود مستقیم فایل — همیشه کار می‌کند */
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => [
                    'chat_id' => $chatId,
                    'caption' => mb_substr($caption, 0, 900),
                    'photo'   => new CURLFile($diskPath),
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 25,
            ]);
        } else {
            /* 🌐 ارسال با URL عمومی */
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'chat_id' => $chatId,
                    'caption' => mb_substr($caption, 0, 900),
                    'photo'   => $absUrl,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
            ]);
        }
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
