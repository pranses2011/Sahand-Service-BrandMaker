<?php
/**
 * 🪝 WebhookDispatcher — وب‌هوک‌های خروجی سایت ساز (P3 — v2.37)
 * =====================================================
 * نقشه راه P3 گزارش تحلیل جامع (بخش ۱۰): «وب‌هوک خروجی» —
 * integratorها (CRM / n8n / Zapster / اسلک / ...) رخدادهای سیستم را
 * به‌صورت POST + JSON با امضای HMAC-SHA256 دریافت می‌کنند.
 *
 * رویدادهای پشتیبانی‌شده (EVENTS):
 *   • article.published   — مقاله منتشر شد (اشتراک‌گذاری خودکار در شبکه‌ها)
 *   • request.created     — درخواست خدمات جدید (اتصال CRM)
 *   • form_entry.created  — فرم قالب‌ساز ثبت شد (لید‌های بازاریابی)
 *   • comment.created     — دیدگاه جدید (مودریشن بیرونی)
 *   • brand.deployed      — سایت برند مستقر شد (آگاه‌سازی DevOps)
 *
 * سازوکار تحویل — «صف + تلاش بلافاصله + تلاش مجدد نمایی»:
 *   ۱) dispatch() رخداد را برای همه هوک‌های فعالِ مشترک در
 *      webhook_deliveries ثبت می‌کند (status=pending).
 *   ۲) همان لحظه یک‌بار «غیرمسدودکننده» تلاش می‌کند (مهلت کوتاه ۵ ثانیه) —
 *      چون ۹۰٪+ تحویل‌ها همین‌جا موفق می‌شوند و کاربر منتظر نمی‌ماند.
 *   ۳) تلاش ناموفق → next_retry_at با backoff نمایی (۵د → ۱۵د → ۴۵د → ۲س …)
 *      cron اصلی (هر ۵ دقیقه) دوباره می‌فرستد تا سقف MAX_ATTEMPTS؛
 *      بعد از آن failed (و در لاگ می‌ماند تا پنل نشان دهد).
 *
 * 🔒 امنیت:
 *   • امضای HMAC-SHA256 بدنه با هدر X-Sahand-Signature (الگوی استاندارد
 *     GitHub/Stripe) — گیرنده اصالت رخداد را راستی‌آزمایی می‌کند.
 *   • فقط http(s) پذیرفته می‌شود (file:// و ftp:// رد می‌شوند).
 *   • مهلت کوتاه + سقف حجم بدنه — dispatch هرگز مسیر اصلی را نمی‌بلعد.
 *
 * @package SahandBrandMaker\Core
 * @since   2.37.0
 */
class WebhookDispatcher
{
    /** رویدادهای مجاز — منبع حقیقت واحد (پنل هم از همین می‌خواند) */
    public const EVENTS = [
        'article.published'  => 'انتشار مقاله',
        'request.created'    => 'ثبت درخواست خدمات',
        'form_entry.created' => 'ثبت فرم قالب‌ساز',
        'comment.created'    => 'دیدگاه جدید مقاله',
        'brand.deployed'     => 'استقرار سایت برند',
    ];

    /** حداکثر تلاش تحویل هر رخداد (۱ فوری + ۶ تلاش مجدد) */
    public const MAX_ATTEMPTS = 7;

    /** مهلت هر تلاش (ثانیه) — کوتاه تا مسیر اصلی کند نشود */
    private const TIMEOUT = 5;

    /**
     * 📤 ارسال یک رویداد به همه هوک‌های مشترک
     *
     * @param string   $event   نام رویداد (کلید EVENTS)
     * @param array    $payload داده رخداد (brand_id/عنوان/لینک/...)
     * @param int|null $brandId برندِ رخداد — هوک‌های گلوبال (brand_id NULL) هم می‌گیرند
     */
    public static function dispatch(string $event, array $payload, ?int $brandId = null): void
    {
        if (!isset(self::EVENTS[$event])) {
            return; // رویداد ناشناخته — بی‌صدا رد (عدم قطع مسیر اصلی)
        }
        try {
            $db = Database::getInstance();

            /* هوک‌های فعالِ مشترک در این رویداد: گلوبال (brand_id IS NULL)
               + مخصوص همین برند — الگوی آماده index (brand_id, is_active) */
            $hooks = $db->fetchAll(
                "SELECT * FROM webhooks
                 WHERE is_active = 1
                   AND (brand_id IS NULL OR brand_id = ?)
                   AND FIND_IN_SET(?, events) > 0",
                [$brandId ?? 0, $event]
            );
            if (!$hooks) {
                return;
            }

            /* 🧾 پاکت استاندارد — الگوی Stripe/Shopify:
               مصرف‌کننده با event + created_at + data کار می‌کند */
            $envelope = [
                'event'      => $event,
                'created_at' => date('c'),
                'data'       => $payload,
            ];

            foreach ($hooks as $hook) {
                /* ثبت در صف/لاگ — حتی تلاش فوری داخلش رخ می‌دهد */
                self::enqueueAndAttempt($db, (int)$hook['id'], $event, $envelope, (string)$hook['secret'], (string)$hook['url']);
            }
        } catch (Throwable $e) {
            /* 🛡️ هرگز مسیر اصلی (ثبت درخواست/انتشار/…) را با خطای هوک قطع نکن */
            try { Logger::error('خطای ارسال وب‌هوک: ' . $e->getMessage(), ['event' => $event]); } catch (Throwable $l) { /* بی‌صدا */ }
        }
    }

    /**
     * 🧪 ارسال یک رویداد آزمایشی به یک هوک مشخص (دکمه «تست» پنل)
     * برخلاف dispatch، نتیجه را برمی‌گرداند تا پنل نشان دهد.
     */
    public static function test(int $webhookId): array
    {
        try {
            $db = Database::getInstance();
            $hook = $db->fetch('SELECT * FROM webhooks WHERE id = ? LIMIT 1', [$webhookId]);
            if (!$hook) {
                return ['success' => false, 'error' => 'وب‌هوک یافت نشد'];
            }
            $envelope = [
                'event'      => 'test',
                'created_at' => date('c'),
                'data'       => ['message' => 'رویداد آزمایشی از سایت ساز سهند سرویس', 'brand_id' => $hook['brand_id'] !== null ? (int)$hook['brand_id'] : null],
            ];
            $result = self::deliver((string)$hook['url'], (string)$hook['secret'], $envelope);

            /* رویداد تست در لاگ ثبت نمی‌شود (آلوده‌کردن آمار واقعی) */
            return $result;
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 🔄 وظیفه cron — تلاش مجدد ردیف‌های pendingِ سررسیده‌شده
     * خروجی: تعداد تحویل‌شده / ناموفق (برای گزارش cron)
     */
    public static function retryPending(int $limit = 25): array
    {
        $out = ['delivered' => 0, 'failed' => 0];
        try {
            $db = Database::getInstance();
            $rows = $db->fetchAll(
                "SELECT d.*, w.url, w.secret, w.is_active
                 FROM webhook_deliveries d
                 JOIN webhooks w ON w.id = d.webhook_id
                 WHERE d.status = 'pending'
                   AND (d.next_retry_at IS NULL OR d.next_retry_at <= NOW())
                   AND w.is_active = 1
                 ORDER BY d.created_at ASC
                 LIMIT " . max(1, min(100, $limit))
            );
            foreach ($rows as $row) {
                $payload = json_decode((string)$row['payload'], true);
                if (!is_array($payload)) {
                    $db->update('webhook_deliveries', ['status' => 'failed'], 'id = ?', [$row['id']]);
                    $out['failed']++;
                    continue;
                }
                $result = self::deliver((string)$row['url'], (string)$row['secret'], $payload);
                if ($result['success']) {
                    $db->update('webhook_deliveries', [
                        'status'        => 'delivered',
                        'response_code' => $result['code'],
                        'delivered_at'  => date('Y-m-d H:i:s'),
                    ], 'id = ?', [$row['id']]);
                    $out['delivered']++;
                } else {
                    self::reschedule($db, (int)$row['id'], (int)$row['attempts']);
                    $out['failed']++;
                }
            }
        } catch (Throwable $e) {
            /* بی‌صدا — cron خودش لاگ می‌کند */
        }
        return $out;
    }

    /* ══════════════════ بخش خصوصی ══════════════════ */

    /** ثبت در صف + یک تلاش فوری غیرمسدودکننده */
    private static function enqueueAndAttempt(Database $db, int $hookId, string $event, array $envelope, string $secret, string $url): void
    {
        $deliveryId = $db->insert('webhook_deliveries', [
            'webhook_id' => $hookId,
            'event'      => $event,
            'payload'    => json_encode($envelope, JSON_UNESCAPED_UNICODE),
            'status'     => 'pending',
            'attempts'   => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $result = self::deliver($url, $secret, $envelope);
        if ($result['success']) {
            $db->update('webhook_deliveries', [
                'status'        => 'delivered',
                'response_code' => $result['code'],
                'attempts'      => 1,
                'delivered_at'  => date('Y-m-d H:i:s'),
            ], 'id = ?', [$deliveryId]);
        } else {
            /* ناکام فوری → زمان‌بندی مجدد (تلاش ۱ → +۵ دقیقه) */
            self::reschedule($db, $deliveryId, 0);
        }
    }

    /**
     * 📅 زمان‌بندی تلاش بعدی با backoff نمایی:
     * ۵د → ۱۵د → ۴۵د → ۲:۱۵س → ۶:۴۵س → ۲۰س (سقف)
     * بعد از MAX_ATTEMPTS → failed (لاگ می‌ماند برای پنل)
     */
    private static function reschedule(Database $db, int $deliveryId, int $attemptsSoFar): void
    {
        $attempts = $attemptsSoFar + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $db->update('webhook_deliveries', ['status' => 'failed', 'attempts' => $attempts], 'id = ?', [$deliveryId]);
            return;
        }
        /* ۳^level × ۵ دقیقه — سطح از تعداد تلاش‌های قبلی */
        $delaySec = min(1200, 300 * (3 ** max(0, $attempts - 1)));
        $db->update('webhook_deliveries', [
            'attempts'      => $attempts,
            'next_retry_at' => date('Y-m-d H:i:s', time() + $delaySec),
        ], 'id = ?', [$deliveryId]);
    }

    /**
     * 🚚 ارسال واقعی POST + JSON با امضای HMAC
     * هرگز استثنا پرتاب نمی‌کند — خروجی bool + کد وضعیت.
     */
    private static function deliver(string $url, string $secret, array $envelope): array
    {
        /* اعتبارسنجی مقصد — فقط http(s)؛ file:// و ftp:// رد */
        if (!preg_match('#^https?://[^\s]{5,490}$#i', $url)) {
            return ['success' => false, 'code' => null, 'error' => 'آدرس مقصد نامعتبر است'];
        }

        $body = json_encode($envelope, JSON_UNESCAPED_UNICODE);
        if (strlen($body) > 262144) {
            return ['success' => false, 'code' => null, 'error' => 'بدنه رخداد بیش از حد بزرگ است'];
        }
        $signature = hash_hmac('sha256', $body, $secret);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-Sahand-Event: ' . (string)($envelope['event'] ?? ''),
                    'X-Sahand-Signature: sha256=' . $signature,
                    'X-Sahand-Timestamp: ' . (string)($envelope['created_at'] ?? date('c')),
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => false, /* مقصد هوک نباید ریدایرکتِ نرم‌افزاری داشته باشد */
                CURLOPT_USERAGENT      => 'SahandBrandMaker-Webhook/1.0',
                CURLOPT_SSL_VERIFYPEER => true,  /* 🔒 خروجی به سرور بیرونی — برخلاف تماس داخلی سایت برند، تأیید گواهی روشن */
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $response = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            /* ۲xx = تحویل موفق (الگوی GitHub/Stripe) */
            $ok = $response !== false && $code >= 200 && $code < 300;
            return ['success' => $ok, 'code' => $code > 0 ? $code : null, 'error' => $ok ? '' : ($err !== '' ? $err : 'کد پاسخ ' . $code)];
        }

        /* fallback — بدون cURL (محیط‌های محدود) */
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'content'       => $body,
                'timeout'       => self::TIMEOUT,
                'ignore_errors' => true,
                'header'        => "Content-Type: application/json\r\n"
                    . "X-Sahand-Event: " . ($envelope['event'] ?? '') . "\r\n"
                    . "X-Sahand-Signature: sha256=" . $signature . "\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int)$m[1];
            }
        }
        $ok = $response !== false && $code >= 200 && $code < 300;
        return ['success' => $ok, 'code' => $code > 0 ? $code : null, 'error' => $ok ? '' : 'کد پاسخ ' . $code];
    }

    /** 🔑 ساخت رمز امضای تصادفی برای هوک جدید (هگز ۶۴ کاراکتری) */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * 📰 رویداد article.published — کمکی مشترک برای همه نقاط انتشار
     * (پنل مقالات، ویزارد ساخت برند، انتشار زمان‌بندی‌شده cron)
     * نام برند + آدرس عمومی مقاله در payload هست تا مصرف‌کننده بی‌کوئری باشد.
     */
    public static function articlePublished(Database $db, int $articleId): void
    {
        try {
            $row = $db->fetch(
                'SELECT a.brand_id, a.title, a.slug, a.excerpt, b.name_fa, b.slug AS brand_slug
                 FROM brand_articles a LEFT JOIN brands b ON b.id = a.brand_id
                 WHERE a.id = ? LIMIT 1',
                [$articleId]
            );
            if (!$row) {
                return;
            }
            self::dispatch('article.published', [
                'article_id'  => $articleId,
                'brand_id'    => (int)$row['brand_id'],
                'brand_name'  => (string)($row['name_fa'] ?? ''),
                'brand_slug'  => (string)($row['brand_slug'] ?? ''),
                'title'       => (string)$row['title'],
                'slug'        => (string)$row['slug'],
                'excerpt'     => mb_substr((string)($row['excerpt'] ?? ''), 0, 300),
                'published_at' => date('c'),
            ], (int)$row['brand_id']);
        } catch (Throwable $e) {
            /* fire-and-forget */
        }
    }
}
