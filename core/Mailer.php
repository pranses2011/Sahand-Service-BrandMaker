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

    public function __construct()
    {
        // خواندن تنظیمات SMTP از تنظیمات عمومی در صورت وجود
        $smtp = Config::get('smtp_settings');
        if (is_array($smtp) && !empty($smtp['enabled'])) {
            $this->smtpConfig = $smtp;
        }
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
        $fromEmail = $fromEmail ?: (Config::get('smtp_from_email') ?: 'no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $fromName  = $fromName ?: (Config::get(Config::KEY_AGENCY_NAME_FA) ?: SAHAND_NAME_FA);

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
            return $this->smtpSend($to, $subjectEncoded, $this->wrapHtml($body, $subject), $fromEmail, $fromName);
        }

        $ok = @mail($to, $subjectEncoded, $this->wrapHtml($body, $subject), implode("\r\n", $headers));
        if (!$ok) {
            Logger::warning('ارسال ایمیل ناموفق', ['to' => $to, 'subject' => $subject]);
        }
        return $ok;
    }

    /**
     * 📨 ارسال ایمیل درخواست خدمات (قالب زیبای فارسی)
     *
     * @param string $to      ایمیل مقصد
     * @param array  $request داده‌های درخواست (نام، تلفن، دستگاه و ...)
     * @param array  $brand   اطلاعات برند (نام + آدرس لوگو + دامنه)
     */
    public function sendServiceRequest(string $to, array $request, array $brand): bool
    {
        $logoHtml = '';
        if (!empty($brand['logo'])) {
            $logoUrl = (strpos($brand['logo'], 'http') === 0 ? $brand['logo'] : BASE_URL . '/' . $brand['logo']);
            $logoHtml = '<img src="' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($brand['name_fa']) . '" style="height:48px"> ';
        }

        // 🏗️ ساخت جدول اطلاعات درخواست
        $rows = [
            'نام و نام خانوادگی' => $request['full_name'] ?? '-',
            'شماره تماس'         => $request['phone'] ?? '-',
            'شماره تماس دوم'     => $request['phone2'] ?? '-',
            'آدرس'               => $request['address'] ?? '-',
            'نوع دستگاه'         => $request['device_type'] ?? '-',
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

        // 🔗 لینک تصاویر پیوست (در صورت وجود)
        $imagesHtml = '';
        if (!empty($request['images']) && is_array($request['images'])) {
            $imagesHtml .= '<p style="margin:14px 0 6px"><b>🖼️ تصاویر پیوست:</b></p><ul>';
            foreach ($request['images'] as $img) {
                $url = (strpos($img, 'http') === 0 ? $img : BASE_URL . '/' . $img);
                $imagesHtml .= '<li><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars(basename($img)) . '</a></li>';
            }
            $imagesHtml .= '</ul>';
        }

        $subject = '📨 درخواست خدمات جدید — ' . ($brand['name_fa'] ?? 'سایت برند');
        $body = '<div style="font-family:Tahoma,Arial,sans-serif;direction:rtl;text-align:right;max-width:640px;margin:auto;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">'
            . '<div style="background:#1e3a8a;color:#fff;padding:16px 20px;display:flex;align-items:center;gap:10px">' . $logoHtml
            . '<div><div style="font-size:16px;font-weight:bold">درخواست خدمات جدید</div>'
            . '<div style="font-size:12px;opacity:.85">از سایت ' . htmlspecialchars($brand['name_fa'] ?? '') . ' (' . htmlspecialchars($brand['domain'] ?? '') . ')</div></div></div>'
            . '<div style="padding:20px"><table style="width:100%;border-collapse:collapse;font-size:13px">' . $rowsHtml . '</table>'
            . $imagesHtml
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
        } catch (Exception $e) {
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
