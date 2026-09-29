<?php
/**
 * 📮 اندپوینت دیدگاه مقالات سایت برند (P3 — v2.37)
 * ================================================
 * نقشه راه P3 گزارش تحلیل جامع: «دیدگاه مقالات» —
 *
 * جریان: فرم دیدگاه صفحه مقاله → POST brand/{id}/comment (این فایل)
 *        → ثبت با status=pending (تأییدیه‌ای) → اعلان تلگرام/بله/ایمیل
 *        → پنل: admin/comments.php (تأیید/رد/اسپم/پاسخ)
 *        → سایت: فقط approved ها در پاسخ مقاله نمایش می‌یابند.
 *
 * 🔒 ضداسپم (چندلایه — الگوی اندپوینت فرم‌ها):
 *   • محدودیت نرخ: ۵ دیدگاه در ساعت از هر IP
 *   • honeypot (فیلد مخزی که ربات‌ها پر می‌کنند)
 *   • سقف طول نام/متن + اعتبارسنجی ایمیل
 *   • تأییدیه‌ای — هیچ دیدگاهی بدون بازبینی منتشر نمی‌شود
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

/**
 * 📝 ثبت دیدگاه جدید از سایت برند — POST brand/{brandId}/comment
 */
function api_submit_comment(int $urlBrandId): void
{
    $db = Database::getInstance();
    $input = Router::jsonInput();

    /* 🔑 احراز هویت برند — مثل اندپوینت فرم‌ها (کلید در بدنه) */
    $apiKey = (string)($input['api_key'] ?? '');
    $brand = null;
    if ($apiKey !== '') {
        $brand = $db->fetch(
            'SELECT b.* FROM brands b JOIN api_keys k ON k.brand_id = b.id WHERE k.api_key = ? AND k.is_active = 1',
            [$apiKey]
        );
    }
    if (!$brand && $urlBrandId > 0) {
        $brand = $db->fetch('SELECT * FROM brands WHERE id = ? AND is_active = 1', [$urlBrandId]);
    }
    if (!$brand) {
        json_response(['success' => false, 'error' => 'برند معتبر شناسایی نشد'], 401);
    }

    /* 🍯 honeypot — فیلد مخزی که فقط ربات‌ها پر می‌کنند */
    if (trim((string)($input['website'] ?? '')) !== '') {
        /* جعل پاسخ موفق — ربات فکر می‌کند ثبت شده و ادامه می‌دهد */
        json_response(['success' => true, 'data' => ['moderated' => true]]);
    }

    /* 🛡️ محدودیت نرخ: ۵ دیدگاه در ساعت از هر IP */
    $ip = Logger::clientIp();
    try {
        $recent = (int)$db->fetchValue(
            'SELECT COUNT(*) FROM article_comments WHERE ip = ? AND created_at > DATE_SUB(?, INTERVAL 1 HOUR)',
            [$ip, date('Y-m-d H:i:s')]
        );
    } catch (Throwable $rt) { $recent = 0; }
    if ($recent >= 5) {
        json_response(['success' => false, 'error' => 'تعداد دیدگاه‌های شما در این ساعت به حداکثر رسیده است'], 429);
    }

    /* 📄 مقاله — با slug (همان شناسه عمومی صفحه مقاله) */
    $slug = trim((string)($input['article_slug'] ?? ''));
    if ($slug === '') {
        json_response(['success' => false, 'error' => 'مقاله مشخص نشده است'], 422);
    }
    $article = $db->fetch(
        'SELECT id, title, slug FROM brand_articles WHERE brand_id = ? AND slug = ? AND status = ?',
        [(int)$brand['id'], $slug, 'published']
    );
    if (!$article) {
        json_response(['success' => false, 'error' => 'مقاله یافت نشد'], 404);
    }

    /* 🧼 اعتبارسنجی ورودی */
    $name = trim(strip_tags((string)($input['name'] ?? '')));
    $email = trim((string)($input['email'] ?? ''));
    $body = trim(strip_tags((string)($input['body'] ?? '')));

    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        json_response(['success' => false, 'error' => 'نام باید بین ۲ تا ۱۲۰ نویسه باشد'], 422);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['success' => false, 'error' => 'ایمیل معتبر نیست'], 422);
    }
    if (mb_strlen($body) < 5 || mb_strlen($body) > 3000) {
        json_response(['success' => false, 'error' => 'متن دیدگاه باید بین ۵ تا ۳۰۰۰ نویسه باشد'], 422);
    }
    /* لینک‌گذاری بی‌رویه = سیگنال اسپم — بیش از ۳ لینک رد می‌شود */
    if (preg_match_all('#https?://#i', $body) > 3) {
        json_response(['success' => false, 'error' => 'تعداد لینک‌ها در دیدگاه بیش از حد مجاز است'], 422);
    }

    /* 💾 ثبت — همیشه pending؛ انتشار فقط با تأیید پنل */
    $commentId = 0;
    try {
        $commentId = $db->insert('article_comments', [
            'brand_id'   => (int)$brand['id'],
            'article_id' => (int)$article['id'],
            'author_name'  => $name,
            'author_email' => $email !== '' ? mb_substr($email, 0, 190) : null,
            'body'         => $body,
            'status'       => 'pending',
            'ip'           => $ip,
            'user_agent'   => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $inE) {
        json_response(['success' => false, 'error' => 'خطا در ثبت دیدگاه — دوباره تلاش کنید'], 500);
    }

    /* 🪝 رویداد وب‌هوک — مصرف‌کننده بیرونی (CRM/اسلک/...) */
    WebhookDispatcher::dispatch('comment.created', [
        'comment_id' => $commentId,
        'brand_id'   => (int)$brand['id'],
        'brand_name' => (string)$brand['name_fa'],
        'article'    => ['id' => (int)$article['id'], 'title' => (string)$article['title'], 'slug' => (string)$article['slug']],
        'author'     => $name,
        'excerpt'    => mb_substr($body, 0, 200),
    ], (int)$brand['id']);

    /* 📨 اعلان به مدیران — همان مسیر نوتیفیکیشن فرم‌ها (ایمیل/تلگرام/بله) */
    try {
        $notifier = new NotificationService();
        if (method_exists($notifier, 'sendComment')) {
            $notifier->sendComment([
                'article_title' => (string)$article['title'],
                'author_name'   => $name,
                'body'          => $body,
            ], $brand);
        }
    } catch (Throwable $nE) { /* اعلان اختیاری است — ثبت دیدگاه وابسته به آن نیست */ }

    NotificationService::notify(
        0,
        'comment',
        'دیدگاه جدید در انتظار تأیید',
        'روی مقاله «' . $article['title'] . '» توسط ' . $name,
        'comments.php?status=pending'
    );

    json_response([
        'success' => true,
        'data'    => [
            'moderated' => true,
            'message'   => 'دیدگاه شما ثبت شد و پس از بازبینی منتشر خواهد شد. سپاس از همراهی شما!',
        ],
    ]);
}

/**
 * 📖 دیدگاه‌های تأییدشده یک مقاله — GET brand/{brandId}/article-comments/{slug}
 * فقط داده عمومی (بدون IP/ایمیل) — خروجی مستقیم روی سایت برند رندر می‌شود.
 */
function api_brand_article_comments(int $brandId, string $slug): void
{
    $db = Database::getInstance();
    $article = $db->fetch(
        'SELECT id FROM brand_articles WHERE brand_id = ? AND slug = ? AND status = ?',
        [$brandId, urldecode($slug), 'published']
    );
    if (!$article) {
        json_response(['success' => false, 'error' => 'مقاله یافت نشد'], 404);
    }

    $rows = $db->fetchAll(
        "SELECT id, parent_id, is_brand_reply, author_name, body, created_at
         FROM article_comments
         WHERE article_id = ? AND status = 'approved'
         ORDER BY created_at ASC
         LIMIT 100",
        [(int)$article['id']]
    );

    $out = array_map(static function (array $r): array {
        return [
            'id'             => (int)$r['id'],
            'parent_id'      => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
            'is_brand_reply' => (bool)$r['is_brand_reply'],
            'author'         => (string)$r['author_name'],
            'body'           => (string)$r['body'],
            'created_at'     => (string)$r['created_at'],
        ];
    }, $rows);

    json_response(['success' => true, 'data' => $out]);
}
