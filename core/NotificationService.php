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
 * 🆕 v2.41 — بازطراحی کامل منطق ارسال (درخواست کاربر):
 *   ⑧ «هر تصویر به همان ابعاد اصلی + فقط واترمارک دو لوگو در گوشه‌های
 *      پایین با زمینه شفاف» → RequestCard::watermark برای همه تصاویر؛
 *      کارت مرکب (متن روی تصویر) حذف شد — متن کامل = کپشن همان پیام
 *   ⑨ «زمان ترجیحی شمسی» → preferredFa() در همه پیام‌ها/کارت‌ها/ایمیل
 *  ⑩ بله: فال‌بک به تنظیمات ربات بله (bale_bot_settings) — ریشه
 *      «درخواست‌ها به ربات بله ارسال نمی‌شوند": دو کلید تنظیمات جدا
 *      بودند و فعال‌سازی فقط ربات، کانال ارسال را خالی می‌گذاشت
 *
 * @package SahandBrandMaker
 * @version 1.5.0
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
     * 🗓️ زمان ترجیحی شمسی — v2.41 (درخواست کاربر)
     * مقدار میلادی ISO (ذخیره در DB) به شمسی فارسی تبدیل می‌شود؛
     * مقدار شمسی/متنی از قبل دست‌نخورده نمایش داده می‌شود.
     */
    public static function preferredFa(string $date, string $time = ''): string
    {
        $date = trim($date);
        $out = '';
        if ($date !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
                $out = jdate(substr($date, 0, 10)); /* 1405/07/15 با ارقام فارسی */
            } else {
                $out = $date; /* از قبل شمسی یا متن آزاد */
            }
        }
        return trim($out . ($time !== '' ? ' ' . $time : ''));
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

        /* 🖼️ v2.41 — واترمارک همه تصاویر درخواست (به همان ابعاد اصلی):
           هر تصویر فقط دو لوگوی گوشه‌های پایین با زمینه شفاف می‌گیرد؛
           متن کامل درخواست به‌صورت کپشن همان پیام ارسال می‌شود. */
        $wmImages = [];
        foreach ($requestImages as $img) {
            $wm = null;
            try {
                if (class_exists('RequestCard')) {
                    $wm = RequestCard::watermark($img, $brand, $agencyLogoDisk ?: $agencyLogo);
                }
            } catch (Throwable $wmE) {
                Logger::error('واترمارک تصویر ناموفق', ['error' => $wmE->getMessage()]);
            }
            $wmImages[] = $wm ?? $img; /* فال‌بک: تصویر اصلی بدون واترمارک */
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
                    /* 🆕 v2.41 — همه تصاویر واترمارک‌دار (به همان ابعاد اصلی) */
                    'card_path'   => $wmImages[0] ?? null,
                    'extra_images' => array_slice($wmImages, 1),
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

        /* ---------- 🤖 اعلان به ربات دستیار تلگرام ----------
           🆕 v2.41 — تصویر اول واترمارک‌دار + کپشن کامل پاس می‌شود تا
           تصویر و متن در «یک پیام» باشند و نام دستگاه فارسی بماند */
        try {
            if (is_file(ROOT_PATH . '/telegram/TelegramBot.php')) {
                require_once ROOT_PATH . '/telegram/TelegramBot.php';
                $botRequest = $request;
                $botRequest['images'] = $wmImages;
                $result['bot'] = TelegramBot::notifyNewRequest($botRequest, $brand, $wmImages[0] ?? null, $caption);
            }
        } catch (Throwable $e) {
            $errors[] = 'ربات تلگرام: ' . $e->getMessage();
        }

        // 📱 تلگرام مستقیم — تصویر اول واترمارک‌دار + کپشن کامل در یک پیام
        if (!empty($tgCfg['enabled']) && !empty($tgCfg['bot_token']) && !empty($tgCfg['chat_id'])) {
            $botToken = (string)$tgCfg['bot_token'];
            $chatId = (string)$tgCfg['chat_id'];
            $tgBase = "https://api.telegram.org/bot{$botToken}";
            if (!empty($wmImages)) {
                /* 🖼️ یک پیام واحد: تصویر اول (واترمارک‌دار، ابعاد اصلی) + کپشن کامل */
                $result['telegram'] = $this->sendPhotoGeneric($tgBase . '/sendPhoto', $chatId, $wmImages[0], $caption);
                /* تصاویر تکمیلی ۲ به بعد — همه واترمارک‌دار */
                foreach (array_slice($wmImages, 1) as $ii => $img) {
                    $this->sendPhotoGeneric($tgBase . '/sendPhoto', $chatId, $img, '🖼️ تصویر پیوست ' . self::faNum($ii + 2) . ' از ' . self::faNum(count($wmImages)));
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

        // 🔄 واسط Google Apps Script — 🩺 v2.39: بازنویسی کامل!
        // ریشه: این مسیر قبلاً پیام متنی + عکس‌ها را «جداگانه» و با فرمت
        // قدیمی (message/photo_urls) می‌فرستاد که اسکریپت گوگل v3 آن را
        // نمی‌فهمید → «unknown payload» و پیام‌های جدا. اکنون همان فرمت
        // استاندارد واسط (method/token/params/files_b64) با یک sendPhoto
        // ترکیبی (کارت + کپشن کامل) ارسال می‌شود — مثل تلگرام مستقیم.
        $gsCfg = (array)(Config::get(Config::KEY_NOTIFY_GSCRIPT) ?: []);
        if (!empty($gsCfg['enabled']) && !empty($gsCfg['webapp_url']) && !empty($tgCfg['bot_token']) && !empty($tgCfg['chat_id'])) {
            $relayFiles = static function (string $imgPath): array {
                /* فایل محلی → [b64, نام] | URL → رد می‌شود (به‌صورت پارامتر می‌رود) */
                [, $disk] = self::resolveAsset($imgPath);
                if ($disk !== '' && is_file($disk) && (int)filesize($disk) > 0 && (int)filesize($disk) < 6 * 1024 * 1024) {
                    $data = @file_get_contents($disk);
                    if ($data !== false && $data !== '') {
                        return ['b64' => base64_encode($data), 'name' => basename($disk)];
                    }
                }
                return [];
            };
            $relayPhoto = function (string $photoPath, string $photoCaption) use ($gsCfg, $tgCfg, $relayFiles): bool {
                $params = ['chat_id' => (string)$tgCfg['chat_id'], 'caption' => mb_substr($photoCaption, 0, 900)];
                $file = $relayFiles($photoPath);
                if ($file === []) {
                    /* URL عمومی → پارامتر photo (سرورهای گوگل دانلود می‌کنند) */
                    [$absUrl] = self::resolveAsset($photoPath);
                    if ($absUrl === '') { return false; }
                    $params['photo'] = $absUrl;
                    return $this->relayTelegramCall((string)$gsCfg['webapp_url'], (string)$tgCfg['bot_token'], 'sendPhoto', $params, []);
                }
                return $this->relayTelegramCall((string)$gsCfg['webapp_url'], (string)$tgCfg['bot_token'], 'sendPhoto', $params, ['photo' => $file]);
            };
            if (!empty($wmImages)) {
                /* 🖼️ یک پیام واحد: تصویر اول واترمارک‌دار + کپشن کامل */
                $result['gscript'] = $relayPhoto($wmImages[0], $caption);
                foreach (array_slice($wmImages, 1) as $ii => $img) {
                    $relayPhoto($img, '🖼️ تصویر پیوست ' . self::faNum($ii + 2) . ' از ' . self::faNum(count($wmImages)));
                }
            } else {
                /* بدون تصویر → یک پیام متنی کامل */
                $result['gscript'] = $this->relayTelegramCall(
                    (string)$gsCfg['webapp_url'],
                    (string)$tgCfg['bot_token'],
                    'sendMessage',
                    ['chat_id' => (string)$tgCfg['chat_id'], 'text' => $message],
                    []
                );
            }
            if (!$result['gscript']) {
                $errors[] = 'واسط گوگل: ناموفق (اسکریپت گوگل را با نسخه جدید پنل → تلگرام، دوباره Deploy کنید)';
            }
        }

        /* ---------- 💬 پیام‌رسان بله ----------
           🆕 v2.41 — فال‌بک هوشمند: ریشه «درخواست‌ها به ربات بله ارسال
           نمی‌شوند": کانال ارسال (notify_bale_settings در تنظیمات) و ربات
           دستیار بله (bale_bot_settings در پنل ربات) دو کلید جدا بودند.
           اگر کانال خالی بود، توکن ربات بله + اولین شناسه مجاز آن به‌کار
           می‌رود تا با فقط راه‌اندازی ربات، ارسال درخواست هم فعال شود.
           🆕 v2.43 (S06) — فال‌بک دو مرحله‌ای شد:
           ① notify_bale_settings کامل نبود → ارث‌بری کامل از ربات بله
              (توکن + اولین چت مجاز؛ حتی اگر ربات «enabled» صریح نباشد —
              کاربرِ «تست بله می‌رسد ولی درخواست نه» فقط ربات را پرمی‌کرد)
           ② بازهم چت مقصد نبود → getUpdates ربات برای کشف اولین
              گفتگوی فعال (/start خورده) — یک‌بار کش و در تنظیمات ثبت */
        $baleCfg = (array)(Config::get(Config::KEY_NOTIFY_BALE) ?: []);
        if (empty($baleCfg['bot_token']) || empty($baleCfg['chat_id'])) {
            $baleBot = (array)(Config::get('bale_bot_settings') ?: []);
            if (!empty($baleBot['bot_token'])) {
                $baleCfg['bot_token'] = (string)$baleBot['bot_token'];
                if (empty($baleCfg['chat_id'])) {
                    $firstAllowed = trim((string)(preg_split('/[\s,]+/', (string)($baleBot['allowed_chat_ids'] ?? ''))[0] ?? ''));
                    if ($firstAllowed !== '' && $firstAllowed !== '*') {
                        $baleCfg['chat_id'] = $firstAllowed;
                    }
                }
                /* 🆕 v2.43 — کاربر فقط ربات را راه‌اندازی کرده (enabled صریح نزده)
                   ولی «تست» موفق بوده → ارسال درخواست هم باید کار کند */
                if (empty($baleCfg['enabled'])) {
                    $baleCfg['enabled'] = true;
                }
                /* 🔍 هنوز چت مقصد نیست؟ — از گفتگوهای فعال ربات کشف کن */
                if (empty($baleCfg['chat_id'])) {
                    $baleCfg['chat_id'] = $this->discoverBaleChatId((string)$baleBot['bot_token']);
                }
                /* 💾 کشف موفق → ذخیره تا دفعه بعد شبکه نزند */
                if (!empty($baleCfg['chat_id'])) {
                    Config::set(Config::KEY_NOTIFY_BALE, [
                        'enabled'   => true,
                        'bot_token' => (string)$baleCfg['bot_token'],
                        'chat_id'   => (string)$baleCfg['chat_id'],
                    ]);
                }
            }
        }
        if (!empty($baleCfg['enabled']) && !empty($baleCfg['bot_token']) && !empty($baleCfg['chat_id'])) {
            $baleToken = (string)$baleCfg['bot_token'];
            $baleChat = (string)$baleCfg['chat_id'];
            $baleBase = 'https://tapi.bale.ai/bot' . $baleToken;
            $plainMessage = trim(strip_tags(str_replace(['<b>', '</b>', '\n'], ['', '', "\n"], $message)));
            if (!empty($wmImages)) {
                /* 🖼️ یک پیام واحد: تصویر اول واترمارک‌دار + کپشن کامل */
                $result['bale'] = $this->sendPhotoGeneric($baleBase . '/sendPhoto', $baleChat, $wmImages[0], $caption);
                foreach (array_slice($wmImages, 1) as $ii => $img) {
                    $this->sendPhotoGeneric($baleBase . '/sendPhoto', $baleChat, $img, '🖼️ تصویر پیوست ' . self::faNum($ii + 2) . ' از ' . self::faNum(count($wmImages)));
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
            'watermarked' => count(array_filter($wmImages, static fn($p) => strpos((string)$p, '/watermarked/') !== false)),
            'device'   => $deviceNameFa,
        ]);

        $result['errors'] = $errors;
        $result['card'] = $wmImages[0] ?? null; /* سازگاری با فراخوان‌های قدیمی */
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
        /* 🗓️ v2.41 — تاریخ ترجیحی شمسی در همه پیام‌های فرم */
        if (!empty($fields['preferred_date'])) {
            $fields['preferred_date'] = self::preferredFa((string)$fields['preferred_date'], (string)($fields['preferred_time'] ?? ''));
            unset($fields['preferred_time']); /* ادغام با تاریخ */
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

        /* 🖼️ v2.41 — واترمارک همه تصاویر فرم (به همان ابعاد اصلی، زمینه شفاف) */
        $cardPath = null;
        $images = array_values(array_filter(array_map('strval', (array)($fields['images'] ?? []))));
        $isRequestForm = in_array((string)($fields['form_block'] ?? ''), ['request-form', 'hero-form'], true);
        if ($images) {
            $agencyLogoCfg = (string)(Config::get(Config::KEY_AGENCY_LOGO) ?: '');
            $agencyLogoDisk = '';
            if ($agencyLogoCfg !== '' && strpos($agencyLogoCfg, 'http') !== 0) {
                $disk = ROOT_PATH . '/' . ltrim($agencyLogoCfg, '/');
                $agencyLogoDisk = is_file($disk) ? $disk : '';
            }
            $wmImages = [];
            foreach ($images as $img) {
                $wm = null;
                try {
                    if (class_exists('RequestCard')) {
                        $wm = RequestCard::watermark($img, $brand, $agencyLogoDisk ?: $agencyLogoCfg);
                    }
                } catch (Throwable $wmE) {
                    Logger::error('واترمارک تصویر فرم ناموفق', ['error' => $wmE->getMessage()]);
                }
                $wmImages[] = $wm ?? $img;
            }
            $images = $wmImages;
            $cardPath = $wmImages[0]; /* تصویر اول واترمارک‌دار — پیام اصلی */
            $fields['images'] = $wmImages;
        } elseif ($isRequestForm) {
            /* بدون تصویر — پیام متنی کامل ارسال می‌شود (متن روی تصویر نیست) */
            $cardPath = null;
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
                    /* 🖼️ v2.41 — تصاویر واترمارک‌دار در ایمیل فرم‌ها (embedded با MIME درست) */
                    $cardHtml = '';
                    if ($cardPath !== null && is_file($cardPath)) {
                        $cardData = @file_get_contents($cardPath);
                        if ($cardData !== false && strlen($cardData) > 500) {
                            $mime = preg_match('/\.png$/i', $cardPath) ? 'image/png' : (preg_match('/\.webp$/i', $cardPath) ? 'image/webp' : 'image/jpeg');
                            $cardHtml = '<p style="margin:0 0 14px"><img src="data:' . $mime . ';base64,' . base64_encode($cardData) . '" alt="تصویر پیوست فرم" style="width:100%;max-width:900px;border-radius:12px;border:1px solid #e2e8f0"></p>';
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

        /* 💬 بله — 🛡 v2.41: تک‌پیام تضمینی + فال‌بک به تنظیمات ربات بله
           🆕 v2.43 (S06): فال‌بک تقویت‌شده — enabled خودکار + کشف چت از getUpdates */
        if (in_array('bale', $dests, true)) {
            $baleCfg = (array)(Config::get(Config::KEY_NOTIFY_BALE) ?: []);
            if (empty($baleCfg['bot_token']) || empty($baleCfg['chat_id'])) {
                $baleBot = (array)(Config::get('bale_bot_settings') ?: []);
                if (!empty($baleBot['bot_token'])) {
                    $baleCfg['bot_token'] = (string)$baleBot['bot_token'];
                    if (empty($baleCfg['chat_id'])) {
                        $firstAllowed = trim((string)(preg_split('/[\s,]+/', (string)($baleBot['allowed_chat_ids'] ?? ''))[0] ?? ''));
                        if ($firstAllowed !== '' && $firstAllowed !== '*') {
                            $baleCfg['chat_id'] = $firstAllowed;
                        }
                    }
                    if (empty($baleCfg['enabled'])) {
                        $baleCfg['enabled'] = true;
                    }
                    if (empty($baleCfg['chat_id'])) {
                        $baleCfg['chat_id'] = $this->discoverBaleChatId((string)$baleBot['bot_token']);
                    }
                    if (!empty($baleCfg['chat_id'])) {
                        Config::set(Config::KEY_NOTIFY_BALE, [
                            'enabled'   => true,
                            'bot_token' => (string)$baleCfg['bot_token'],
                            'chat_id'   => (string)$baleCfg['chat_id'],
                        ]);
                    }
                }
            }
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
            /* 🗓️ v2.41 — زمان ترجیحی شمسی (درخواست کاربر) */
            '📅 زمان ترجیحی' => self::preferredFa((string)($request['preferred_date'] ?? ''), (string)($request['preferred_time'] ?? '')),
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
            /* 🗓️ v2.41 — زمان ترجیحی شمسی (درخواست کاربر) */
            '📅 زمان ترجیحی'  => self::preferredFa((string)($request['preferred_date'] ?? ''), (string)($request['preferred_time'] ?? '')) ?: '-',
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
     *
     * 🚨 v2.42 — ریشه‌یابی «تست پیام به بله می‌رسد ولی درخواست‌ها نه»:
     *   ① CURLFile بدون MIME صریح → بخش multipart بدون Content-Type می‌رفت؛
     *      تلگرام مدارا می‌کند اما API بله عکس را رد می‌کند (الگوی کارکردی
     *      BaleBot::sendImageAlbum از ابتدا MIME صریح داشت!) → اکنون MIME
     *      از پسوند فایل تعیین می‌شود
     *   ② تایم‌اوت ۲۵ ثانیه برای آپلود عکس‌های چند‌مگابایتی روی آپ‌لینک
     *      ایرانی کم بود → ۱۲۰ ثانیه (همتر کد کارکردی BaleBot::apiUpload)
     *   ③ پاسخ خطای سرور (description) در لاگ ثبت می‌شود تا ریشه پیدا شود
     */
    /**
     * 🔍 v2.43 (S06) — کشف چت مقصد بله از گفتگوهای فعال ربات
     * سناریو: کاربر ربات بله را راه‌اندازی و /start داده («تست می‌رسد») اما
     * شناسه چت را در تنظیمات «ارسال درخواست‌ها» ثبت نکرده. این متد با
     * getUpdates آخرین گفتگوی خصوصی فعال را پیدا می‌کند.
     * @return string شناسه چت یا '' در صورت نبود/خطا
     */
    private function discoverBaleChatId(string $botToken): string
    {
        $token = trim($botToken);
        if ($token === '' || !function_exists('curl_init')) {
            return '';
        }
        try {
            $ch = curl_init('https://tapi.bale.ai/bot' . $token . '/getUpdates?limit=25&timeout=0');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $body = (string)curl_exec($ch);
            curl_close($ch);
            $data = json_decode($body, true);
            if (!is_array($data) || empty($data['ok']) || !is_array($data['result'] ?? null)) {
                return '';
            }
            /* آخرین پیام خصوصی (به‌ترتیب update_id صعودی → از آخر بگردیم) */
            foreach (array_reverse($data['result']) as $upd) {
                $msg = $upd['message'] ?? ($upd['edited_message'] ?? null);
                $chat = $msg['chat'] ?? null;
                if (is_array($chat) && (string)($chat['type'] ?? '') === 'private' && !empty($chat['id'])) {
                    return (string)$chat['id'];
                }
                if (is_array($chat) && !empty($chat['id'])) {
                    return (string)$chat['id']; /* گروه/کانال هم مقصد معتبری است */
                }
            }
        } catch (Throwable $e) {
            /* بی‌صدا — فرستنده اصلی نتیجه را گزارش می‌کند */
        }
        return '';
    }

    private function sendPhotoGeneric(string $apiUrl, string $chatId, string $photoPathOrUrl, string $caption = ''): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        [$absUrl, $diskPath] = self::resolveAsset($photoPathOrUrl);
        if ($absUrl === '' && $diskPath === '') {
            return false;
        }

        /* 🏷️ MIME صریح از پسوند — الزام API بله */
        $mimeOf = static function (string $p): string {
            $ext = strtolower((string)preg_replace('/^.*\./', '', $p));
            if ($ext === 'png') { return 'image/png'; }
            if ($ext === 'webp') { return 'image/webp'; }
            if ($ext === 'gif') { return 'image/gif'; }
            return 'image/jpeg';
        };

        $ch = curl_init($apiUrl);
        if ($diskPath !== '' && (int)filesize($diskPath) > 0 && (int)filesize($diskPath) < 9 * 1024 * 1024) {
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => [
                    'chat_id' => $chatId,
                    'caption' => mb_substr($caption, 0, 900),
                    'photo'   => new CURLFile($diskPath, $mimeOf($diskPath), basename($diskPath)),
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 120,
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
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 30,
            ]);
        }
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($response === false || $httpCode !== 200) {
            Logger::warning('ارسال عکس ناموفق', [
                'url' => substr($apiUrl, 0, 60),
                'http' => $httpCode,
                'curl' => $curlErr,
                'body' => mb_substr((string)$response, 0, 300),
            ]);
            return false;
        }
        $data = json_decode((string)$response, true);
        if (!is_array($data) || empty($data['ok'])) {
            Logger::warning('پاسخ رد شده ارسال عکس', [
                'url' => substr($apiUrl, 0, 60),
                'body' => mb_substr((string)$response, 0, 300),
            ]);
        }
        return is_array($data) && !empty($data['ok']);
    }

    /**
     * 🌉 فراخوانی تلگرام از طریق واسط Google Apps Script (v2.39)
     * ==========================================================
     * همان فرمت استاندارد اسکریپت واسط v3 (مثل TelegramBot::apiViaRelay):
     *   {secret, method, token, params, files_b64?, files_name?, files_mime?}
     * فایل‌ها به‌صورت base64 از سرورهای گوگل عبور می‌کنند و به Blob
     * تبدیل و multipart به تلگرام ارسال می‌شوند — یعنی «کارت تصویری
     * واحد + کپشن کامل» حتی در زمان تحریم هم تک‌پیامی می‌ماند.
     *
     * @param string $webAppUrl آدرس /exec اسکریپت گوگل
     * @param string $botToken  توکن ربات تلگرام
     * @param string $method    متد Bot API (sendMessage/sendPhoto/…)
     * @param array  $params    پارامترهای متد (بدون chat_id فایل)
     * @param array  $files     ['photo' => ['b64'=>…, 'name'=>…]] فایل‌های base64
     */
    private function relayTelegramCall(string $webAppUrl, string $botToken, string $method, array $params, array $files = []): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        /* 🔐 راز مشترک از تنظیمات ربات تلگرام (جای استاندارد آن) */
        $tgBotCfg = (array)(Config::get('telegram_bot_settings') ?: []);
        $payload = [
            'secret' => (string)($tgBotCfg['relay_secret'] ?? ''),
            'method' => $method,
            'token'  => $botToken,
            'params' => $params,
        ];
        foreach ($files as $field => $f) {
            if (!is_array($f) || empty($f['b64'])) { continue; }
            $payload['files_b64'][$field]  = $f['b64'];
            $payload['files_name'][$field] = $f['name'] ?? ($field . '.jpg');
            $payload['files_mime'][$field] = 'image/jpeg';
        }
        $ch = curl_init($webAppUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 70,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        if ($response === false || $httpCode !== 200) {
            Logger::warning('واسط گوگل ناموفق', ['method' => $method, 'http' => $httpCode, 'curl' => $curlErr]);
            return false;
        }
        $data = json_decode((string)$response, true);
        if (is_array($data) && !empty($data['relay_error'])) {
            Logger::warning('خطای اسکریپت گوگل', ['error' => (string)$data['relay_error']]);
            return false;
        }
        return is_array($data) && !empty($data['ok']);
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
     * 🧪 تست کانال بله — v2.41 (پنل ربات بله ← بخش ارسال درخواست‌ها)
     */
    public function testBaleChannel(string $token, string $chatId, string $message): bool
    {
        return $this->sendMessageGeneric('https://tapi.bale.ai/bot' . $token . '/sendMessage', [
            'chat_id' => $chatId,
            'text'    => $message,
        ]);
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
