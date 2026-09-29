<?php
/**
 * 📨 سرویس ارسال اعلان چندکاناله
 * ================================
 * ارسال درخواست خدمات به ۴ کانال:
 *   📧 ایمیل (mail/SMTP) | 📱 تلگرام مستقیم | 🔄 واسط Google Script | 💬 بله
 *
 * 🆕 v2.31 — درخواست کاربر:
 *   ① «تصویر و متن در قالب یک پیام» → کارت تصویری واحد (RequestCard):
 *      تصویر مشتری + واترمارک لوگوی برند (پایین چپ) و لوگوی نمایندگی
 *      (پایین راست) روی تصویر + همه فیلدهای متنی فارسی زیر تصویر
 *   ② «نام دستگاه انگلیسی فرستاده می‌شود» → نام فارسی از brand_devices
 *   ③ «ایمیل ارسال نمیشود» → خطای دقیق هر کانال در گزارش + Mailer مقاوم
 * 🆕 v2.32 — تکمیل قطعی زنجیره:
 *   ④ کپشن کامل: کپشن پیام تصویری = همه فیلدهای متنی (نه خلاصه)
 *      («فیلدهای متنی درخواست رو بطور کامل زیرش پیوست بکنه»)
 *   ⑤ تک‌پیام تضمینی بدون GD: اگر کارت ساخته نشد و تصویر مشتری
 *      موجود بود → تصویر اول + متن کامل در یک sendPhoto (نه پیام جدا)
 *   ⑥ نام دستگاه فارسی در «همه» مسیرها: فرم عمومی قالب‌ساز قبلاً
 *      device_type خام مثل washing_machine می‌فرستاد (ریشه «نام
 *      دستگاه انگلیسی») — اکنون در sendFormEntry هم فارسی می‌شود
 *   ⑦ کارت بدون تصویر مشتری هم ساخته می‌شود (لوگوها در فوتر)
 *
 * @package SahandBrandMaker
 * @version 1.4.0
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
     * 🔧 نام فارسی دستگاه از brand_devices (v2.31 — ریشه «نام دستگاه
     * انگلیسی فرستاده می‌شود»: device_key خام مثل washing_machine به
     * جای نام فارسی می‌رفت)
     */
    public static function deviceNameFa(int $brandId, string $deviceKey, string $deviceOther = ''): string
    {
        $deviceKey = trim($deviceKey);
        if ($deviceKey === '' || $deviceKey === 'other') {
            return $deviceOther !== '' ? $deviceOther : 'سایر';
        }
        try {
            $name = Database::getInstance()->fetchValue(
                'SELECT name_fa FROM brand_devices WHERE brand_id = ? AND device_key = ? LIMIT 1',
                [$brandId, $deviceKey]
            );
            /* fetchValue در نبود ردیف false برمی‌گرداند */
            if (is_string($name) && $name !== '') {
                return $name;
            }
        } catch (Throwable $e) { /* جدول قدیمی */ }
        /* پشتیبان: نگاشت کلیدهای رایج */
        static $known = [
            'washing_machine' => 'ماشین لباسشویی', 'refrigerator' => 'یخچال', 'freezer' => 'فریزر',
            'dishwasher' => 'ماشین ظرفشویی', 'oven' => 'فر و اجاق', 'microwave' => 'ماکروویو',
            'tv' => 'تلویزیون', 'led_tv' => 'تلویزیون LED', 'cooler' => 'کولر آبی',
            'ac' => 'کولر گازی', 'split' => 'کولر گازی (اسپیلت)', 'package' => 'پکیج و رادیاتور',
            'water_heater' => 'آبگرمکن', 'vacuum' => 'جاروبرقی', 'range_hood' => 'هود',
            'steam_iron' => 'اتو بخار', 'other' => 'سایر',
        ];
        if (isset($known[$deviceKey])) {
            return $known[$deviceKey];
        }
        return $deviceOther !== '' ? $deviceOther : $deviceKey;
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

        /* 🏢 اطلاعات نمایندگی */
        $agencyName = (string)(Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس');
        $agencyLogo = (string)(Config::get(Config::KEY_AGENCY_LOGO) ?: '');
        [, $agencyLogoDisk] = self::resolveAsset($agencyLogo);

        /* 🔧 نام فارسی دستگاه (v2.31) */
        $deviceNameFa = self::deviceNameFa(
            (int)($brand['id'] ?? 0),
            (string)($request['device_key'] ?? ''),
            (string)($request['device_other'] ?? '')
        );
        $request['device_name'] = $deviceNameFa;

        /* 🖼️ تصاویر پیوست درخواست */
        $requestImages = [];
        if (!empty($request['images']) && is_array($request['images'])) {
            foreach (array_slice($request['images'], 0, MAX_REQUEST_IMAGES) as $img) {
                $img = trim((string)$img);
                if ($img !== '') {
                    $requestImages[] = $img;
                }
            }
        }

        /* ═══ 🖼️ کارت تصویری واحد — تصویر + واترمارک + همه فیلدها (v2.31) ═══
           🆕 v2.32: کارت حتی بدون تصویر مشتری هم ساخته می‌شود تا فرم
           درخواست بدون عکس هم کارت تک‌پیامی داشته باشد (لوگوها در فوتر) */
        $cardPath = null;
        try {
            if (class_exists('RequestCard')) {
                $cardPath = RequestCard::render(
                    $request,
                    $brand,
                    $agencyName,
                    $agencyLogoDisk ?: $agencyLogo,
                    !empty($requestImages) ? $requestImages[0] : null
                );
            }
        } catch (Throwable $cardE) {
            Logger::error('ساخت کارت درخواست ناموفق', ['error' => $cardE->getMessage()]);
        }

        /* 📝 v2.32 — کپشن کامل: همه فیلدهای متنی درخواست (نه خلاصه) —
           «تصویر و متن در قالب یک پیام» به معنای واقعی */
        $caption = $this->plainFieldsMessage($request, $brand, $agencyName);

        /* ---------- 📧 کانال ایمیل ---------- */
        $emailCfg = (array)(Config::get(Config::KEY_NOTIFY_EMAIL) ?: []);
        if (!empty($emailCfg['enabled']) && !empty($emailCfg['to'])) {
            try {
                $mailer = new Mailer();
                $result['email'] = $mailer->sendServiceRequest($emailCfg['to'], $request, $brand, [
                    'agency_name' => $agencyName,
                    'agency_logo' => self::resolveAsset($agencyLogo)[0],
                    'card_path'   => $cardPath,
                    'extra_images' => array_slice($requestImages, 1),
                ]);
                if (!$result['email']) {
                    $errors[] = 'ایمیل: ارسال ناموفق — ' . Mailer::lastError();
                }
            } catch (Throwable $e) {
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

        /* ---------- 🤖 اعلان به ربات دستیار تلگرام ---------- */
        try {
            if (is_file(ROOT_PATH . '/telegram/TelegramBot.php')) {
                require_once ROOT_PATH . '/telegram/TelegramBot.php';
                $result['bot'] = TelegramBot::notifyNewRequest($request, $brand);
            }
        } catch (Throwable $e) {
            $errors[] = 'ربات تلگرام: ' . $e->getMessage();
        }

        // 📱 تلگرام مستقیم — کارت واحد یا پیام متنی
        if (!empty($tgCfg['enabled']) && !empty($tgCfg['bot_token']) && !empty($tgCfg['chat_id'])) {
            $botToken = (string)$tgCfg['bot_token'];
            $chatId = (string)$tgCfg['chat_id'];
            $tgBase = "https://api.telegram.org/bot{$botToken}";
            if ($cardPath !== null && is_file($cardPath)) {
                /* 🖼️ یک پیام واحد: کارت (تصویر + متن داخلش) + کپشن کامل (v2.32) */
                $result['telegram'] = $this->sendPhotoGeneric($tgBase . '/sendPhoto', $chatId, $cardPath, $caption);
                /* تصاویر تکمیلی ۲ به بعد — به‌عنوان پیوست بعد از کارت */
                foreach (array_slice($requestImages, 1) as $ii => $img) {
                    $this->sendPhotoGeneric($tgBase . '/sendPhoto', $chatId, $img, '🖼️ تصویر پیوست ' . self::faNum($ii + 2) . ' از ' . self::faNum(count($requestImages)));
                }
            } elseif (!empty($requestImages)) {
                /* 🛡 v2.32 — تک‌پیام تضمینی بدون GD: کارت ساخته نشد اما تصویر
                   مشتری هست → تصویر اول + متن کامل در همان sendPhoto (نه جدا!) */
                $result['telegram'] = $this->sendPhotoGeneric($tgBase . '/sendPhoto', $chatId, $requestImages[0], $caption);
                foreach (array_slice($requestImages, 1) as $ii => $img) {
                    $this->sendPhotoGeneric($tgBase . '/sendPhoto', $chatId, $img, '🖼️ تصویر پیوست ' . self::faNum($ii + 2) . ' از ' . self::faNum(count($requestImages)));
                }
            } else {
                /* بدون تصویر → یک پیام متنی کامل */
                $result['telegram'] = $this->sendMessageGeneric($tgBase . '/sendMessage', [
                    'chat_id' => $chatId,
                    'text'    => $message,
                    'parse_mode' => 'HTML',
                ]);
            }
            if (!$result['telegram']) {
                $errors[] = 'تلگرام مستقیم: ناموفق (در صورت تحریم، واسط گوگل را فعال کنید)';
            }
        }

        // 🔄 واسط Google Apps Script
        $gsCfg = (array)(Config::get(Config::KEY_NOTIFY_GSCRIPT) ?: []);
        if (!empty($gsCfg['enabled']) && !empty($gsCfg['webapp_url']) && !empty($tgCfg['bot_token']) && !empty($tgCfg['chat_id'])) {
            $result['gscript'] = $this->sendViaGoogleScript($gsCfg['webapp_url'], [
                'bot_token'  => $tgCfg['bot_token'],
                'chat_id'    => $tgCfg['chat_id'],
                'message'    => $message,
                'photo_url'  => $logoUrl ?: self::resolveAsset($agencyLogo)[0],
                'photo_urls' => array_values(array_filter(array_merge(
                    $logoUrl ? [$logoUrl] : [],
                    self::resolveAsset($agencyLogo)[0] ? [self::resolveAsset($agencyLogo)[0]] : [],
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
            $baleBase = 'https://tapi.bale.ai/bot' . $baleToken;
            $plainMessage = trim(strip_tags(str_replace(['<b>', '</b>', '\n'], ['', '', "\n"], $message)));
            if ($cardPath !== null && is_file($cardPath)) {
                /* 🖼️ یک پیام واحد (v2.31) + کپشن کامل (v2.32) */
                $result['bale'] = $this->sendPhotoGeneric($baleBase . '/sendPhoto', $baleChat, $cardPath, $caption);
                foreach (array_slice($requestImages, 1) as $ii => $img) {
                    $this->sendPhotoGeneric($baleBase . '/sendPhoto', $baleChat, $img, '🖼️ تصویر پیوست ' . self::faNum($ii + 2) . ' از ' . self::faNum(count($requestImages)));
                }
            } elseif (!empty($requestImages)) {
                /* 🛡 v2.32 — تک‌پیام تضمینی بدون GD (تصویر اول + متن کامل) */
                $result['bale'] = $this->sendPhotoGeneric($baleBase . '/sendPhoto', $baleChat, $requestImages[0], $caption);
                foreach (array_slice($requestImages, 1) as $ii => $img) {
                    $this->sendPhotoGeneric($baleBase . '/sendPhoto', $baleChat, $img, '🖼️ تصویر پیوست ' . self::faNum($ii + 2) . ' از ' . self::faNum(count($requestImages)));
                }
            } else {
                $result['bale'] = $this->sendMessageGeneric($baleBase . '/sendMessage', [
                    'chat_id' => $baleChat,
                    'text'    => $plainMessage,
                ]);
            }
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
            'card'     => $cardPath !== null ? basename($cardPath) : 'بدون کارت',
            'device'   => $deviceNameFa,
        ]);

        $result['errors'] = $errors;
        $result['card'] = $cardPath;
        return $result;
    }

    /**
     * 📋 ارسال فرم عمومی (تماس/خبرنامه/نظرسنجی/...) به کانال‌های انتخابی
     * 🆕 v2.31 — درخواست کاربر: «تنظیم کنیم که اطلاعات فرم به کجا ارسال بشه»
     *
     * @param array $fields فیلدهای فرم (کلید => مقدار)
     * @param array $brand  اطلاعات برند
     * @param array $dests  مقصدها: ['email','telegram','bale']
     * @return array گزارش کانال‌ها
     */
    public function sendFormEntry(array $fields, array $brand, array $dests): array
    {
        $result = ['email' => false, 'telegram' => false, 'bale' => false];
        $errors = [];
        $agencyName = (string)(Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس');

        $formLabels = [
            'contact-form' => 'فرم تماس', 'newsletter-form' => 'عضویت خبرنامه', 'callback-form' => 'درخواست تماس',
            'quick-contact-form' => 'تماس سریع', 'appointment-form' => 'رزرو نوبت', 'appointment-compact' => 'رزرو سریع نوبت',
            'survey-form' => 'نظرسنجی', 'request-form' => 'درخواست خدمات', 'hero-form' => 'درخواست سریع',
        ];
        $label = $formLabels[(string)($fields['form_block'] ?? '')] ?? 'فرم';

        /* 🚨 v2.32 — نام دستگاه فارسی در «همه» مسیرها (ریشه «نام دستگاه
           انگلیسی فرستاده می‌شود»): فرم‌های قالب‌ساز فیلد خام device_type
           (مثل washing_machine) می‌فرستادند؛ اکنون همان‌جا فارسی می‌شود
           و در ذخیره پنل (form_entries) هم نام فارسی ثبت می‌شود. */
        $deviceFa = '';
        if (trim((string)($fields['device_type'] ?? '')) !== '') {
            $deviceFa = self::deviceNameFa((int)($brand['id'] ?? 0), (string)($fields['device_type'] ?? ''), (string)($fields['device_other'] ?? ''));
            $fields['device_name'] = $deviceFa;
        }

        /* برچسب‌های فارسی فیلدها */
        $labels = [
            'full_name' => '👤 نام', 'phone' => '📞 تماس', 'phone2' => '📞 تماس دوم', 'email' => '📧 ایمیل',
            'subject' => '📌 موضوع', 'address' => '📍 آدرس', 'description' => '📝 پیام',
            'device_name' => '🔧 دستگاه', 'device_model' => '📋 مدل', 'preferred_date' => '📅 تاریخ',
            'preferred_time' => '🕐 بازه', 'page' => '📄 صفحه',
        ];
        $skipKeys = ['form_block', 'request_id', 'images', 'device_type', 'device_other'];

        /* متن کامل (تلگرام/بله) — همه فیلدها + نام دستگاه فارسی */
        $lines = ["📋 <b>{$label} جدید</b>\n"];
        $lines[] = "🏷️ برند: <b>" . ($brand['name_fa'] ?? '') . "</b>\n";
        if ($agencyName !== '') { $lines[] = "🏢 نمایندگی: {$agencyName}\n"; }
        $lines[] = "─────────────────\n";
        foreach ($fields as $k => $v) {
            if (in_array($k, $skipKeys, true) || is_array($v)) { continue; }
            $lb = $labels[$k] ?? $k;
            $lines[] = $lb . ': ' . $v . "\n";
        }
        if (!empty($fields['request_id'])) { $lines[] = "🔢 کد پیگیری: " . $fields['request_id'] . "\n"; }
        $lines[] = "⏰ " . jdate(date('Y-m-d H:i'), true);
        $message = implode('', $lines);

        /* 📝 v2.32 — کپشن کامل (متن ساده — همه فیلدها) برای پیام تصویری */
        $plainCaption = trim(strip_tags(str_replace(['<b>', '</b>'], '', $message)));
        $plainCaption = mb_substr($plainCaption, 0, 900);

        /* تصاویر پیوست (فرم درخواست از قالب‌ساز) — کارت تصویری واحد
           🆕 v2.32: کارت برای فرم درخواست حتی «بدون تصویر» هم ساخته می‌شود
           (متن کامل + لوگوها در فوتر) تا تصویر و متن در یک پیام باشد */
        $cardPath = null;
        $images = array_values(array_filter(array_map('strval', (array)($fields['images'] ?? []))));
        $isRequestForm = in_array((string)($fields['form_block'] ?? ''), ['request-form', 'hero-form'], true);
        if (class_exists('RequestCard') && ($images || $isRequestForm)) {
            try {
                $requestForCard = $fields;
                if ($deviceFa !== '') { $requestForCard['device_name'] = $deviceFa; }
                $agencyLogoDisk = '';
                $agencyLogoCfg = (string)(Config::get(Config::KEY_AGENCY_LOGO) ?: '');
                if ($agencyLogoCfg !== '' && strpos($agencyLogoCfg, 'http') !== 0) {
                    $disk = ROOT_PATH . '/' . ltrim($agencyLogoCfg, '/');
                    $agencyLogoDisk = is_file($disk) ? $disk : '';
                }
                $cardPath = RequestCard::render(
                    $requestForCard,
                    $brand,
                    $agencyName,
                    $agencyLogoDisk ?: $agencyLogoCfg,
                    !empty($images) ? $images[0] : null
                );
            } catch (Throwable $cE) {
                Logger::error('ساخت کارت فرم ناموفق', ['error' => $cE->getMessage()]);
                $cardPath = null;
            }
        }
        $caption = $plainCaption;

        /* 📧 ایمیل */
        if (in_array('email', $dests, true)) {
            $emailCfg = (array)(Config::get(Config::KEY_NOTIFY_EMAIL) ?: []);
            if (!empty($emailCfg['enabled']) && !empty($emailCfg['to'])) {
                try {
                    $mailer = new Mailer();
                    $rowsHtml = '';
                    foreach ($fields as $k => $v) {
                        if (in_array($k, $skipKeys, true) || is_array($v)) { continue; }
                        $lb = $labels[$k] ?? $k;
                        $rowsHtml .= '<tr><td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:bold;width:140px">' . e($lb) . '</td><td style="padding:8px 12px;border:1px solid #e2e8f0">' . nl2br(e((string)$v)) . '</td></tr>';
                    }
                    /* 🖼️ v2.32 — کارت تصویری در ایمیل فرم‌ها هم embedded */
                    $cardHtml = '';
                    if ($cardPath !== null && is_file($cardPath)) {
                        $cardData = @file_get_contents($cardPath);
                        if ($cardData !== false && strlen($cardData) > 500) {
                            $cardHtml = '<p style="margin:0 0 14px"><img src="data:image/jpeg;base64,' . base64_encode($cardData) . '" alt="کارت فرم" style="width:100%;max-width:900px;border-radius:12px;border:1px solid #e2e8f0"></p>';
                        }
                    }
                    $result['email'] = $mailer->send(
                        $emailCfg['to'],
                        '📋 ' . $label . ' جدید — ' . ($brand['name_fa'] ?? ''),
                        '<div style="font-family:Tahoma;direction:rtl;text-align:right;max-width:640px;margin:auto;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">'
                        . '<div style="background:#1e3a8a;color:#fff;padding:14px 20px;font-weight:bold">' . e($label) . ' جدید — ' . e($brand['name_fa'] ?? '') . '</div>'
                        . '<div style="padding:18px">' . $cardHtml . '<table style="width:100%;border-collapse:collapse;font-size:13px">' . $rowsHtml . '</table>'
                        . '<p style="margin-top:14px;color:#64748b;font-size:12px">⏰ ' . e(jdate(date('Y-m-d H:i'), true)) . (!empty($fields['page']) ? ' — صفحه ' . e((string)$fields['page']) : '') . '</p></div></div>'
                    );
                    if (!$result['email']) { $errors[] = 'ایمیل: ' . Mailer::lastError(); }
                } catch (Throwable $e) { $errors[] = 'ایمیل: ' . $e->getMessage(); }
            } else {
                $errors[] = 'ایمیل: کانال ایمیل در تنظیمات فعال نیست';
            }
        }

        /* 📱 تلگرام — 🛡 v2.32: تک‌پیام تضمینی (کارت یا تصویر اول + متن کامل) */
        if (in_array('telegram', $dests, true)) {
            $tgCfg = (array)(Config::get(Config::KEY_NOTIFY_TELEGRAM) ?: []);
            if (!empty($tgCfg['enabled']) && !empty($tgCfg['bot_token']) && !empty($tgCfg['chat_id'])) {
                $base = 'https://api.telegram.org/bot' . (string)$tgCfg['bot_token'];
                if ($cardPath !== null && is_file($cardPath)) {
                    $result['telegram'] = $this->sendPhotoGeneric($base . '/sendPhoto', (string)$tgCfg['chat_id'], $cardPath, $caption);
                    foreach (array_slice($images, 1) as $ii => $img) {
                        $this->sendPhotoGeneric($base . '/sendPhoto', (string)$tgCfg['chat_id'], $img, '🖼️ تصویر پیوست ' . self::faNum($ii + 2) . ' از ' . self::faNum(count($images)));
                    }
                } elseif (!empty($images)) {
                    $result['telegram'] = $this->sendPhotoGeneric($base . '/sendPhoto', (string)$tgCfg['chat_id'], $images[0], $caption);
                    foreach (array_slice($images, 1) as $ii => $img) {
                        $this->sendPhotoGeneric($base . '/sendPhoto', (string)$tgCfg['chat_id'], $img, '🖼️ تصویر پیوست ' . self::faNum($ii + 2) . ' از ' . self::faNum(count($images)));
                    }
                } else {
                    $result['telegram'] = $this->sendMessageGeneric($base . '/sendMessage', [
                        'chat_id' => (string)$tgCfg['chat_id'],
                        'text' => $message,
                        'parse_mode' => 'HTML',
                    ]);
                }
            }
        }

        /* 💬 بله — 🛡 v2.32: تک‌پیام تضمینی */
        if (in_array('bale', $dests, true)) {
            $baleCfg = (array)(Config::get(Config::KEY_NOTIFY_BALE) ?: []);
            if (!empty($baleCfg['enabled']) && !empty($baleCfg['bot_token']) && !empty($baleCfg['chat_id'])) {
                $base = 'https://tapi.bale.ai/bot' . (string)$baleCfg['bot_token'];
                if ($cardPath !== null && is_file($cardPath)) {
                    $result['bale'] = $this->sendPhotoGeneric($base . '/sendPhoto', (string)$baleCfg['chat_id'], $cardPath, $caption);
                    foreach (array_slice($images, 1) as $ii => $img) {
                        $this->sendPhotoGeneric($base . '/sendPhoto', (string)$baleCfg['chat_id'], $img, '🖼️ تصویر پیوست ' . self::faNum($ii + 2) . ' از ' . self::faNum(count($images)));
                    }
                } elseif (!empty($images)) {
                    $result['bale'] = $this->sendPhotoGeneric($base . '/sendPhoto', (string)$baleCfg['chat_id'], $images[0], $caption);
                    foreach (array_slice($images, 1) as $ii => $img) {
                        $this->sendPhotoGeneric($base . '/sendPhoto', (string)$baleCfg['chat_id'], $img, '🖼️ تصویر پیوست ' . self::faNum($ii + 2) . ' از ' . self::faNum(count($images)));
                    }
                } else {
                    $result['bale'] = $this->sendMessageGeneric($base . '/sendMessage', [
                        'chat_id' => (string)$baleCfg['chat_id'],
                        'text' => trim(strip_tags(str_replace(['<b>', '</b>'], '', $message))),
                    ]);
                }
            }
        }

        $result['errors'] = $errors;
        return $result;
    }

    /**
     * 📮 اعلان دیدگاه جدید مقاله (P3 — v2.37)
     * ==========================================
     * نقشه راه P3: «دیدگاه مقالات» — مدیران با همان کانال‌های موجود
     * (ایمیل/تلگرام/بله) از دیدگاه در انتظار تأیید باخبر می‌شوند.
     * 🔒 اصل: اعلان «اختیاری» است — ثبت دیدگاه هرگز به آن وابسته نیست.
     */
    public function sendComment(array $comment, array $brand): array
    {
        $result = ['email' => false, 'telegram' => false, 'bale' => false];
        $errors = [];
        $agencyName = (string)(Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس');
        $articleTitle = (string)($comment['article_title'] ?? '');
        $author = (string)($comment['author_name'] ?? '');
        $body = mb_substr(trim(strip_tags((string)($comment['body'] ?? ''))), 0, 600);

        /* متن یکپارچه — تلگرام/بله (HTML) و ایمیل (ساده) */
        $lines = ["💬 <b>دیدگاه جدید در انتظار تأیید</b>\n"];
        $lines[] = "🏷️ برند: <b>" . ($brand['name_fa'] ?? '') . "</b>\n";
        if ($agencyName !== '') { $lines[] = "🏢 نمایندگی: {$agencyName}\n"; }
        $lines[] = "─────────────────\n";
        $lines[] = "📰 مقاله: {$articleTitle}\n";
        $lines[] = "👤 نویسنده: {$author}\n";
        $lines[] = "📝 متن: " . $body . "\n";
        $lines[] = "⏰ " . jdate(date('Y-m-d H:i'), true);
        $message = implode('', $lines);
        $plain = trim(strip_tags(str_replace(['<b>', '</b>'], '', $message)));

        /* 📧 ایمیل */
        $emailCfg = (array)(Config::get(Config::KEY_NOTIFY_EMAIL) ?: []);
        if (!empty($emailCfg['enabled']) && !empty($emailCfg['to'])) {
            try {
                $mailer = new Mailer();
                $result['email'] = $mailer->send(
                    $emailCfg['to'],
                    '💬 دیدگاه جدید — ' . ($brand['name_fa'] ?? ''),
                    '<div style="font-family:Tahoma;direction:rtl;text-align:right;max-width:640px;margin:auto;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">'
                    . '<div style="background:#7c3aed;color:#fff;padding:14px 20px;font-weight:bold">💬 دیدگاه جدید در انتظار تأیید — ' . e($brand['name_fa'] ?? '') . '</div>'
                    . '<div style="padding:18px;font-size:13px;line-height:2">'
                    . '<p>📰 مقاله: <b>' . e($articleTitle) . '</b></p>'
                    . '<p>👤 نویسنده: <b>' . e($author) . '</b></p>'
                    . '<p style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px">' . nl2br(e($body)) . '</p>'
                    . '<p style="margin-top:14px"><a href="' . e(BASE_URL . '/admin/comments.php?status=pending') . '" style="background:#7c3aed;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">بررسی و تأیید در پنل ←</a></p>'
                    . '<p style="color:#64748b;font-size:12px">⏰ ' . e(jdate(date('Y-m-d H:i'), true)) . '</p></div></div>'
                );
                if (!$result['email']) { $errors[] = 'ایمیل: ' . Mailer::lastError(); }
            } catch (Throwable $e) { $errors[] = 'ایمیل: ' . $e->getMessage(); }
        }

        /* 📱 تلگرام */
        $tgCfg = (array)(Config::get(Config::KEY_NOTIFY_TELEGRAM) ?: []);
        if (!empty($tgCfg['enabled']) && !empty($tgCfg['bot_token']) && !empty($tgCfg['chat_id'])) {
            $result['telegram'] = $this->sendMessageGeneric(
                'https://api.telegram.org/bot' . (string)$tgCfg['bot_token'] . '/sendMessage',
                ['chat_id' => (string)$tgCfg['chat_id'], 'text' => $message, 'parse_mode' => 'HTML']
            );
        }

        /* 💬 بله */
        $baleCfg = (array)(Config::get(Config::KEY_NOTIFY_BALE) ?: []);
        if (!empty($baleCfg['enabled']) && !empty($baleCfg['bot_token']) && !empty($baleCfg['chat_id'])) {
            $result['bale'] = $this->sendMessageGeneric(
                'https://tapi.bale.ai/bot' . (string)$baleCfg['bot_token'] . '/sendMessage',
                ['chat_id' => (string)$baleCfg['chat_id'], 'text' => $plain]
            );
        }

        $result['errors'] = $errors;
        return $result;
    }

    /**
     * 🔢 عدد فارسی
     */
    private static function faNum(int $n): string
    {
        return strtr((string)$n, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    /**
     * 📝 v2.32 — پیام متنی کامل و ساده (کپشن پیام تصویری)
     * «فیلدهای متنی درخواست رو بطور کامل زیرش پیوست بکنه» — همه فیلدها
     * + برند + نمایندگی + کد پیگیری + تاریخ، بدون HTML (سازگار با کپشن
     * تلگرام/بله). سقف ۹۰۰ کاراکتر (محدودیت تلگرام ۱۰۲۴).
     */
    private function plainFieldsMessage(array $request, array $brand, string $agencyName = ''): string
    {
        $device = (string)($request['device_name'] ?? '');
        if ($device === '') {
            $device = self::deviceNameFa((int)($brand['id'] ?? 0), (string)($request['device_key'] ?? (string)($request['device_type'] ?? '')), (string)($request['device_other'] ?? ''));
        }
        $rows = [
            '👤 نام'         => trim((string)($request['full_name'] ?? '')),
            '📞 تماس'        => trim((string)($request['phone'] ?? '')),
            '📞 تماس دوم'    => trim((string)($request['phone2'] ?? '')),
            '📍 آدرس'        => trim((string)($request['address'] ?? '')),
            '🔧 دستگاه'      => trim($device . (!empty($request['device_model']) ? ' — مدل: ' . $request['device_model'] : '')),
            '📝 شرح ایراد'   => trim((string)($request['description'] ?? '')),
            '📅 زمان ترجیحی' => trim(($request['preferred_date'] ?? '') . ' ' . ($request['preferred_time'] ?? '')),
        ];
        $text = "📨 درخواست خدمات جدید\n";
        $text .= "🏷️ برند: " . ($brand['name_fa'] ?? '') . "\n";
        if ($agencyName !== '') { $text .= "🏢 نمایندگی: {$agencyName}\n"; }
        $text .= "─────────────────\n";
        foreach ($rows as $lb => $v) {
            if ($v !== '') { $text .= $lb . ': ' . $v . "\n"; }
        }
        if (!empty($request['request_id'])) { $text .= "🔢 کد پیگیری: " . $request['request_id'] . "\n"; }
        $text .= '⏰ ' . jdate(date('Y-m-d H:i'), true);
        return mb_substr($text, 0, 900);
    }

    /**
     * 📝 ساخت پیام HTML تلگرام — نام دستگاه فارسی (v2.31)
     */
    private function formatTelegramMessage(array $request, array $brand, string $agencyName = ''): string
    {
        $rows = [
            '👤 نام'          => $request['full_name'] ?? '-',
            '📞 تماس'         => fa_to_en_digits($request['phone'] ?? '-'),
            '📞 تماس دوم'     => fa_to_en_digits($request['phone2'] ?? '-'),
            '📍 آدرس'         => $request['address'] ?? '-',
            '🔧 دستگاه'       => ($request['device_name'] ?? self::deviceNameFa((int)($brand['id'] ?? 0), (string)($request['device_key'] ?? ''), (string)($request['device_other'] ?? '')))
                . (!empty($request['device_model']) ? ' — مدل: ' . $request['device_model'] : ''),
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
            $text .= "─────────────────\n🖼️ تصاویر پیوست: " . count($request['images']) . ' مورد';
        }
        $text .= "\n⏰ " . jdate(date('Y-m-d H:i:s'), true);
        return $text;
    }

    /**
     * 🖼️ ارسال عکس به Bot API (تلگرام/بله)
     * فایل محلی → multipart آپلود | فایل خارجی → پارامتر photo با URL
     */
    private function sendPhotoGeneric(string $apiUrl, string $chatId, string $photoPathOrUrl, string $caption = ''): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        [$absUrl, $diskPath] = self::resolveAsset($photoPathOrUrl);
        if ($absUrl === '' && $diskPath === '') {
            return false;
        }

        $ch = curl_init($apiUrl);
        if ($diskPath !== '' && filesize($diskPath) > 0 && filesize($diskPath) < 9 * 1024 * 1024) {
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
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($response === false || $httpCode !== 200) {
            Logger::warning('ارسال عکس ناموفق', ['url' => substr($apiUrl, 0, 60), 'http' => $httpCode, 'curl' => $curlErr]);
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
        } catch (Throwable $e) {
            Logger::error('ثبت اعلان ناموفق', ['error' => $e->getMessage()]);
        }
    }
}
