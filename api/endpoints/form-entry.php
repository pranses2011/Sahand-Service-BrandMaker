<?php
/**
 * 📋 اندپوینت ثبت فرم‌های عمومی سایت برند (v2.31)
 * =================================================
 * همه فرم‌های قالب‌ساز (تماس/خبرنامه/نظرسنجی/درخواست تماس/...)
 * به‌جز فرم اصلی درخواست خدمات که اندپوینت request.php را دارد.
 *
 * جریان: فرم واقعی .sahand-form → /js/form-submit.php → این اندپوینت
 * مقصد ارسال (dest): panel (جدول form_entries) | email | telegram | bale
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

function api_submit_form_entry(int $urlBrandId): void
{
    $db = Database::getInstance();
    $input = Router::jsonInput();

    /* 🔑 احراز هویت برند — مثل اندپوینت درخواست */
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

    /* 🛡️ ضد اسپم: حداکثر ۱۰ فرم در ساعت از هر IP */
    $ip = Logger::clientIp();
    try {
        $recent = (int)$db->fetchValue(
            'SELECT COUNT(*) FROM form_entries WHERE ip_address = ? AND created_at > DATE_SUB(?, INTERVAL 1 HOUR)',
            [$ip, date('Y-m-d H:i:s')]
        );
    } catch (Throwable $rt) { $recent = 0; }
    if ($recent >= 10) {
        json_response(['success' => false, 'error' => 'تعداد ارسال‌های شما در این ساعت به حداکثر رسیده است'], 429);
    }

    $formBlock = preg_replace('/[^a-z0-9_\-]/', '', (string)($input['form_block'] ?? 'custom')) ?: 'custom';
    /* 🧩 v2.44 (S12) — فرم سفارشی: شناسه + برچسب فارسی فیلدها از فرم‌ساز */
    $formSlug = preg_replace('/[^a-z0-9\-_]/', '', (string)($input['form_slug'] ?? ''));
    $customForm = null;
    if ($formSlug !== '') {
        $customForm = class_exists('CustomFormManager') ? CustomFormManager::bySlug($formSlug) : null;
        if ($customForm && $customForm['brand_id'] !== null && $customForm['brand_id'] !== (int)$brand['id']) {
            $customForm = null;
            $formSlug = '';
        }
        $formBlock = 'custom';
    }
    $fields = [];
    if (is_array($input['fields'] ?? null)) {
        foreach ($input['fields'] as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$k);
            if ($k === '' || strlen($k) > 40) { continue; }
            /* چندانتخابی checkbox[] آرایه است → پیوست با «،» */
            $fields[$k] = is_string($v) ? mb_substr($v, 0, 2000) : (is_array($v) ? mb_substr(implode('، ', array_map('strval', $v)), 0, 2000) : '');
        }
    }

    /* 🧼 اعتبارسنجی حداقلی: نام یا ایمیل یا پیام یا تلفن
       🩹 v2.44 (S03/S12) — فرم‌های سفارشی ممکن است هر نام فیلدی داشته باشند؛
       «هر فیلد غیرخالی» هم قبول می‌شود (فیلدهای فنی مثل rating/page حذف شدند) */
    $hasContent = false;
    foreach ($fields as $fk => $fv) {
        if (in_array($fk, ['images', 'rating'], true)) { continue; }
        if (trim((string)$fv) !== '') { $hasContent = true; break; }
    }
    if (!$hasContent) {
        json_response(['success' => false, 'error' => 'فرم خالی است — حداقل یک فیلد را پر کنید'], 422);
    }

    /* 📨 مقصد ارسال */
    $destRaw = (string)($input['dest'] ?? 'panel');
    $dests = array_values(array_intersect(['panel', 'email', 'telegram', 'bale'], array_filter(array_map('trim', explode(',', $destRaw)))));
    if (!$dests) { $dests = ['panel']; }

    $pageUrl = mb_substr((string)($input['page'] ?? ''), 0, 300) ?: null;

    /* 🖼️ تصاویر پیوست (فرم درخواست از قالب‌ساز) */
    $images = [];
    if (!empty($input['images']) && is_array($input['images'])) {
        foreach (array_slice($input['images'], 0, MAX_REQUEST_IMAGES) as $imgUrl) {
            $imgUrl = trim((string)$imgUrl);
            if ($imgUrl === '') { continue; }
            $rel = null;
            if (preg_match('#^https?://[^\s"\'<>]{5,500}$#i', $imgUrl)) {
                if (stripos($imgUrl, BASE_URL . '/uploads/requests/') === 0) {
                    $rel = 'uploads/requests/' . substr($imgUrl, strlen(BASE_URL . '/uploads/requests/'));
                } else {
                    $rel = $imgUrl;
                }
            } elseif (preg_match('#^uploads/requests/brand-\d+/req_[a-z0-9]+\.(jpe?g|png|webp|gif)$#i', $imgUrl)) {
                $rel = $imgUrl;
            }
            if ($rel !== null) { $images[] = $rel; }
        }
    }
    if ($images) { $fields['images'] = $images; }

    /* 🚨 v2.32 — نام دستگاه فارسی در ذخیره پنل هم بنشیند (ریشه «نام
       دستگاه انگلیسی»): مقدار خام device_type مثل washing_machine در
       JSON می‌نشست؛ اکنون device_name فارسی هم کنارش ذخیره می‌شود. */
    if (trim((string)($fields['device_type'] ?? '')) !== '') {
        try {
            $fields['device_name'] = NotificationService::deviceNameFa(
                (int)$brand['id'],
                (string)$fields['device_type'],
                (string)($fields['device_other'] ?? '')
            );
        } catch (Throwable $dvE) { /* بی‌صدا */ }
    }

    /* ═══ ثبت در پنل ═══ */
    $entryId = 0;
    if (in_array('panel', $dests, true) || true) {
        /* همیشه در پنل ثبت می‌شود تا چیزی گم نشود؛ مقصد فقط «ارسال» را کنترل می‌کند */
        try {
            $entryId = $db->insert('form_entries', [
                'brand_id'    => $brand['id'],
                'form_block'  => $formBlock,
                'form_slug'   => $formSlug !== '' ? $formSlug : null,
                'page_url'    => $pageUrl,
                'fields'      => json_encode($fields, JSON_UNESCAPED_UNICODE),
                'ip_address'  => $ip,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            /* 🧩 شمارنده سریع فرم سفارشی */
            if ($customForm) {
                try { $db->query('UPDATE custom_forms SET entries_count = entries_count + 1 WHERE id = ?', [(int)$customForm['id']]); } catch (Throwable $ec) {}
            }
        } catch (Throwable $ie) {
            Logger::error('ثبت فرم ناموفق', ['error' => $ie->getMessage()]);
        }
    }

    /* ═══ ارسال به کانال‌های انتخابی (email/telegram/bale) ═══ */
    $sentChannels = [];
    if (count(array_intersect(['email', 'telegram', 'bale'], $dests)) > 0) {
        try {
            $notifier = new NotificationService();
            $result = $notifier->sendFormEntry(
                array_merge($fields, ['form_block' => $formBlock, 'page' => $pageUrl, 'request_id' => $entryId ?: null, 'images' => $images]),
                $brand,
                $dests
            );
            foreach (['email', 'telegram', 'bale'] as $ch) {
                if (!empty($result[$ch])) { $sentChannels[] = $ch; }
            }
        } catch (Throwable $ne) {
            Logger::error('ارسال فرم به کانال‌ها ناموفق', ['error' => $ne->getMessage()]);
        }
    }

    /* 🔔 اعلان داخلی پنل */
    try {
        $formLabels = [
            'contact-form' => 'فرم تماس', 'newsletter-form' => 'عضویت خبرنامه', 'callback-form' => 'درخواست تماس',
            'quick-contact-form' => 'تماس سریع', 'appointment-form' => 'رزرو نوبت', 'appointment-compact' => 'رزرو سریع نوبت',
            'survey-form' => 'نظرسنجی', 'request-form' => 'درخواست خدمات', 'hero-form' => 'فرم درخواست سریع',
        ];
        $label = $customForm ? ('فرم سفارشی: ' . $customForm['title']) : ($formLabels[$formBlock] ?? 'فرم');
        NotificationService::notify(
            0, 'request',
            ($label !== 'فرم' ? $label : 'فرم جدید') . ': ' . (($fields['full_name'] ?? '') !== '' ? $fields['full_name'] : ($fields['phone'] ?? ($fields['email'] ?? 'بدون نام'))),
            'برند ' . $brand['name_fa'] . ($pageUrl ? ' — صفحه ' . $pageUrl : ''),
            'form-entries.php?view=' . $entryId . ($formSlug !== '' ? '&form=' . $formSlug : '')
        );
    } catch (Throwable $nE) { /* بی‌صدا */ }

    Logger::info('ثبت فرم سایت برند', ['form' => $formBlock, 'brand' => $brand['name_fa'], 'entry' => $entryId, 'channels' => implode(',', $sentChannels)]);

    /* 🪝 v2.37 — P3: رویداد وب‌هوک form_entry.created (لیدهای بازاریابی) */
    WebhookDispatcher::dispatch('form_entry.created', [
        'entry_id'   => (int)$entryId,
        'brand_id'   => (int)$brand['id'],
        'brand_name' => (string)$brand['name_fa'],
        'form_block' => $formBlock,
        'page'       => $pageUrl,
        'fields'     => $fields,
        'channels'   => $sentChannels,
    ], (int)$brand['id']);

    json_response([
        'success' => true,
        'data'    => [
            'entry_id' => $entryId,
            'message'  => '✅ ' . ($formBlock === 'newsletter-form' ? 'عضویت شما در خبرنامه ثبت شد.' : 'پیام شما با موفقیت ثبت شد — به‌زودی با شما تماس می‌گیریم.'),
            'channels' => $sentChannels,
        ],
    ]);
}
