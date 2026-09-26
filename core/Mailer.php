<?php
/**
 * 📧 کلاس ارسال ایمیل — mail() و SMTP داخلی
 * ==========================================
 * ارسال ایمیل با دو روش: تابع mail بومی PHP (پیش‌فرض)
 * و کلاینت SMTP بدون وابستگی خارجی (اختیاری).
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class Mailer
{
    /** @var array تنظیمات SMTP (در صورت فعال بودن) */
    private $smtpConfig = [];

    /** @var string|null آخرین خطای ارسال (v2.31 — ریشه «ایمیل ارسال نمیشود»: خطا گزارش نمی‌شد) */
    private static $lastError = null;

    public function __construct()
    {
        // خواندن تنظیمات SMTP از تنظیمات عمومی در صورت وجود
        $smtp = Config::get('smtp_settings');
        if (is_array($smtp) && !empty($smtp['enabled'])) {
            $this->smtpConfig = $smtp;
        }
    }

    /** 🧨 آخرین خطای ارسال — برای نمایش دقیق در گزارش کانال‌ها */
    public static function lastError(): string
    {
        return self::$lastError ?? 'دلیل نامشخص — mail() روی سرور غیرفعال یا From رد شده است';
    }

    /**
     * 📨 ارسال ایمیل ساده (متن یا HTML)
     *
     * @param string $to      آدرس گیرنده
     * @param string $subject موضوع ایمیل
     * @param string $body    متن ایمیل (HTML)
     * @param string $fromEmail آدرس فرستنده
     * @param string $fromName  نام فرستنده
     * @return bool موفقیت ارسال
     */
    public function send(string $to, string $subject, string $body, string $fromEmail = '', string $fromName = ''): bool
    {
        self::$lastError = null;
        /* 🎯 v2.31 — آدرس فرستنده: اولویت با تنظیمات، بعد دامنه سرور؛
           دامنه‌های غریب (ساب‌دامین برند) توسط sendmail سی‌پنل رد می‌شوند! */
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $host = preg_replace('/^www\./', '', (string)$host);
        $fromEmail = $fromEmail ?: (Config::get('smtp_from_email') ?: ('no-reply@' . $host));
        $fromName  = $fromName ?: (Config::get('smtp_from_name') ?: Config::get(Config::KEY_AGENCY_NAME_FA) ?: SAHAND_NAME_FA);

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            self::$lastError = 'آدرس گیرنده نامعتبر است: ' . $to;
            return false;
        }
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = 'no-reply@' . ($host ?: 'localhost');
        }

        // ✉️ هدرهای استاندارد + پشتیبانی UTF-8 فارسی
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>',
            'Reply-To: <' . $fromEmail . '>',
            'X-Mailer: SahandBrandMaker/' . SAHAND_VERSION,
        ];

        $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        // 🔄 اگر SMTP فعال است از آن استفاده کن، وگرنه mail()
        if (!empty($this->smtpConfig['enabled'])) {
            $ok = $this->smtpSend($to, $subjectEncoded, $this->wrapHtml($body, $subject), $fromEmail, $fromName);
            if (!$ok) {
                self::$lastError = self::$lastError ?? 'اتصال/احراز SMTP ناموفق — سرور، پورت، رمز اپ را بررسی کنید';
                Logger::warning('ارسال ایمیل SMTP ناموفق', ['to' => $to, 'subject' => $subject, 'error' => self::$lastError]);
            }
            return $ok;
        }

        /* 📮 پارامتر پنجم mail() (-f) — سازگاری sendmail سی‌پنل:
           فرستنده envelope را هم تنظیم می‌کند و از رد شدن جلوگیری می‌کند */
        $extraParams = null;
        if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $safeFrom = substr($fromEmail, 0, 190);
            $extraParams = '-f' . $safeFrom;
        }
        if (function_exists('error_clear_last')) { error_clear_last(); }
        if ($extraParams !== null) {
            $ok = @mail($to, $subjectEncoded, $this->wrapHtml($body, $subject), implode("\r\n", $headers), $extraParams);
        } else {
            $ok = @mail($to, $subjectEncoded, $this->wrapHtml($body, $subject), implode("\r\n", $headers));
        }
        if (!$ok) {
            $mailErr = error_get_last();
            self::$lastError = 'mail() ناموفق'
                . (!empty($mailErr['message']) ? ' — ' . $mailErr['message'] : '')
                . ' (اگر روی هاست اشتراکی هستید، فرستنده باید روی دامنه میزبان باشد؛ از تنظیمات ← فیلد «ایمیل فرستنده» یا SMTP استفاده کنید)';
            Logger::warning('ارسال ایمیل ناموفق', ['to' => $to, 'subject' => $subject, 'error' => self::$lastError]);
        }
        return $ok;
    }

    /**
     * 📨 ارسال ایمیل درخواست خدمات (قالب زیبای فارسی)
     *
     * @param string $to      ایمیل مقصد
     * @param array  $request داده‌های درخواست (نام، تلفن، دستگاه و ...)
     * @param array  $brand   اطلاعات برند (نام + آدرس لوگو + دامنه)
     * @param array  $agency  🆕 v2.30 — اطلاعات نمایندگی (agency_name + agency_logo)
     */
    public function sendServiceRequest(string $to, array $request, array $brand, array $agency = []): bool
    {
        /* 🖼️ لوگوی برند */
        $logoHtml = '';
        if (!empty($brand['logo'])) {
            $logoUrl = (strpos($brand['logo'], 'http') === 0 ? $brand['logo'] : BASE_URL . '/' . $brand['logo']);
            $logoHtml = '<img src="' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($brand['name_fa'] ?? '') . '" style="height:48px;vertical-align:middle"> ';
        }
        /* 🏢 لوگوی نمایندگی (درخواست v2.30) */
        $agencyLogoHtml = '';
        if (!empty($agency['agency_logo'])) {
            $agencyLogoHtml = '<img src="' . htmlspecialchars($agency['agency_logo']) . '" alt="' . htmlspecialchars($agency['agency_name'] ?? 'نمایندگی') . '" style="height:40px;vertical-align:middle"> ';
        }
        $agencyName = (string)($agency['agency_name'] ?? '');

        // 🏗️ ساخت جدول اطلاعات درخواست
        $rows = [
            'نام و نام خانوادگی' => $request['full_name'] ?? '-',
            'شماره تماس'         => $request['phone'] ?? '-',
            'شماره تماس دوم'     => $request['phone2'] ?? '-',
            'آدرس'               => $request['address'] ?? '-',
            'نوع دستگاه'         => $request['device_name'] ?? ($request['device_type'] ?? '-'),
            'مدل دستگاه'         => $request['device_model'] ?? '-',
            'شرح ایراد'          => nl2br(htmlspecialchars($request['description'] ?? '-')),
            'زمان مراجعه ترجیحی' => ($request['preferred_date'] ?? '') . ' ' . ($request['preferred_time'] ?? ''),
        ];
        $rowsHtml = '';
        foreach ($rows as $label => $value) {
            if ($value === '' || $value === ' ') {
                continue;
            }
            $rowsHtml .= '<tr>'
                . '<td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:bold;width:140px">' . $label . '</td>'
                . '<td style="padding:8px 12px;border:1px solid #e2e8f0">' . $value . '</td>'
                . '</tr>';
        }

        /* 🖼️ v2.31 — کارت تصویری واحد (تصویر + واترمارک + فیلدها) در بالای ایمیل */
        $cardHtml = '';
        if (!empty($agency['card_path']) && is_file($agency['card_path'])) {
            $cardData = @file_get_contents($agency['card_path']);
            if ($cardData !== false && strlen($cardData) > 500) {
                $cardB64 = base64_encode($cardData);
                $cardHtml = '<p style="margin:0 0 14px"><img src="data:image/jpeg;base64,' . $cardB64 . '" alt="کارت درخواست" style="width:100%;max-width:900px;border-radius:12px;border:1px solid #e2e8f0"></p>';
            }
        }

        // 🖼️ تصاویر پیوست — نمایش بصری (v2.30: پیش‌تر فقط لینک متنی بود)
        $imagesHtml = '';
        if (!empty($agency['extra_images']) && is_array($agency['extra_images'])) {
            $request = array_merge($request, ['images' => array_values($agency['extra_images'])]);
        }
        if (!empty($request['images']) && is_array($request['images'])) {
            $thumbs = '';
            foreach ($request['images'] as $img) {
                $url = (strpos($img, 'http') === 0 ? $img : BASE_URL . '/' . $img);
                $thumbs .= '<a href="' . htmlspecialchars($url) . '" target="_blank" style="display:inline-block;margin:4px">'
                    . '<img src="' . htmlspecialchars($url) . '" alt="تصویر پیوست" style="width:150px;height:150px;object-fit:cover;border-radius:10px;border:1px solid #e2e8f0">'
                    . '</a>';
            }
            $imagesHtml = '<p style="margin:16px 0 6px"><b>🖼️ تصاویر پیوست مشتری:</b></p>'
                . '<div style="direction:rtl;text-align:right">' . $thumbs . '</div>'
                . '<p style="font-size:11px;color:#64748b;margin:4px 0 0">در صورت نمایش‌داده‌نشدن، روی تصاویر کلیک کنید یا «نمایش تصاویر» را در کلاینت ایمیل فعال کنید.</p>';
        }

        $subject = '📨 درخواست خدمات جدید — ' . ($brand['name_fa'] ?? 'سایت برند') . ($agencyName ? ' | ' . $agencyName : '');
        $body = '<div style="font-family:Tahoma,Arial,sans-serif;direction:rtl;text-align:right;max-width:640px;margin:auto;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">'
            . '<div style="background:#1e3a8a;color:#fff;padding:16px 20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">' . $logoHtml
            . '<div style="flex:1;min-width:180px"><div style="font-size:16px;font-weight:bold">درخواست خدمات جدید</div>'
            . '<div style="font-size:12px;opacity:.85">از سایت ' . htmlspecialchars($brand['name_fa'] ?? '') . ' (' . htmlspecialchars($brand['domain'] ?? '') . ')</div></div>'
            . ($agencyLogoHtml || $agencyName ? '<div style="display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.12);border-radius:10px;padding:7px 12px">' . $agencyLogoHtml
                . '<span style="font-size:12.5px;font-weight:bold">' . htmlspecialchars($agencyName) . '</span></div>' : '')
            . '</div>'
            . '<div style="padding:20px">' . $cardHtml . '<table style="width:100%;border-collapse:collapse;font-size:13px">' . $rowsHtml . '</table>'
            . $imagesHtml
            . '<div style="margin-top:18px;text-align:center"><a href="' . htmlspecialchars(BASE_URL . '/admin/requests.php') . '" style="display:inline-block;background:#1e40af;color:#fff;text-decoration:none;font-weight:bold;font-size:13px;border-radius:10px;padding:11px 26px">👁️ مشاهده در پنل مدیریت</a></div>'
            . '<p style="margin-top:16px;color:#64748b;font-size:12px">⏰ ' . jdate_words(date('Y-m-d H:i:s')) . ' — ارسال‌شده توسط سایت ساز برند سهند سرویس</p>'
            . '</div></div>';

        return $this->send($to, $subject, $body);
    }

    /**
     * 🎁 قالب‌بندی HTML استاندارد ایمیل
     */
    private function wrapHtml(string $body, string $subject): string
    {
        return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>' . htmlspecialchars($subject) . '</title></head>'
            . '<body style="margin:0;padding:20px;background:#f1f5f9">' . $body . '</body></html>';
    }

    /**
     * 🔌 ارسال با SMTP (کلاینت خام بدون وابستگی)
     */
    private function smtpSend(string $to, string $subject, string $html, string $fromEmail, string $fromName): bool
    {
        $cfg = $this->smtpConfig;
        $host = $cfg['host'] ?? '';
        $port = (int)($cfg['port'] ?? 587);
        $user = $cfg['username'] ?? '';
        $pass = $cfg['password'] ?? '';
        $secure = $cfg['secure'] ?? 'tls'; // tls | ssl | none

        if ($host === '' || $user === '') {
            return false;
        }

        try {
            // 🔗 اتصال به سرور SMTP
            $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
            $socket = @stream_socket_client($remote, $errno, $errstr, 15);
            if (!$socket) {
                self::$lastError = 'اتصال به SMTP ناموفق (' . $host . ':' . $port . '): ' . $errstr;
                Logger::error('اتصال SMTP ناموفق', ['error' => $errstr]);
                return false;
            }
            stream_set_timeout($socket, 15);

            $this->smtpRead($socket); // بنام خوش‌آمد سرور

            // 📢 EHLO و STARTTLS در صورت نیاز
            $this->smtpCommand($socket, 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            if ($secure === 'tls') {
                $this->smtpCommand($socket, 'STARTTLS');
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    fclose($socket);
                    return false;
                }
                $this->smtpCommand($socket, 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            }

            // 🔐 ورود
            $this->smtpCommand($socket, 'AUTH LOGIN');
            $this->smtpCommand($socket, base64_encode($user));
            $this->smtpCommand($socket, base64_encode($pass));

            // ✉️ ارسال پیام
            $this->smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>');
            $this->smtpCommand($socket, 'RCPT TO:<' . $to . '>');
            $this->smtpCommand($socket, 'DATA');

            $headers = 'From: =?UTF-8?B?' . base64_encode($fromName) . "?= <{$fromEmail}>\r\n"
                . "To: <{$to}>\r\n"
                . "Subject: {$subject}\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n";
            $this->smtpCommand($socket, $headers . chunk_split(base64_encode($html)) . "\r\n.");
            $this->smtpCommand($socket, 'QUIT');
            fclose($socket);
            return true;
        } catch (Throwable $e) {
            self::$lastError = 'خطای SMTP: ' . $e->getMessage();
            Logger::error('خطای SMTP', ['message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 📖 خواندن پاسخ سرور SMTP
     */
    private function smtpRead($socket): string
    {
        $data = '';
        while (($line = fgets($socket, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $data;
    }

    /**
     * ✅ اجرای دستور SMTP و بررسی موفقیت
     */
    private function smtpCommand($socket, string $command): string
    {
        fwrite($socket, $command . "\r\n");
        $response = $this->smtpRead($socket);
        $code = (int)substr($response, 0, 3);
        // کدهای موفق: 2xx و 3xx (برای DATA)
        if ($code >= 400) {
            throw new RuntimeException("خطای SMTP ({$code}): " . trim($response));
        }
        return $response;
    }
}

/**
 * 🗓️ تبدیل تاریخ میلادی به نمایش شمسی خوانا (سبک ساده)
 * نکته: تاریخ دقیق شمسی در جلالی کلاس JDate در includes/helpers.php پیاده‌سازی شده است.
 */
if (!function_exists('jdate_words')) {
    function jdate_words(string $gregorian): string
    {
        return $gregorian; // نمایش میلادی؛ در ایمیل نهایی از JDate استفاده می‌شود
    }
}
