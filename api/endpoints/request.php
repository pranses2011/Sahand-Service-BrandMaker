<?php
/**
 * 📨 اندپوینت ثبت درخواست خدمات
 * ================================
 * فرم سایت برند → اعتبارسنجی → ثبت → ارسال چندکاناله
 * احراز هویت: api_key در بدنه درخواست (برند مشخص می‌شود)
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

function api_submit_request(int $urlBrandId): void
{
    $db = Database::getInstance();
    $input = Router::jsonInput();

    // 🔑 احراز هویت با کلید API بدنه
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

    // 🛡️ ضد اسپم: حداکثر ۵ درخواست در ساعت از هر IP
    $ip = Logger::clientIp();
    $recent = $db->fetchValue(
        'SELECT COUNT(*) FROM service_requests WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
        [$ip]
    );
    if ((int)$recent >= 5) {
        json_response(['success' => false, 'error' => 'تعداد درخواست‌های شما در این ساعت به حداکثر رسیده است'], 429);
    }

    // ✅ اعتبارسنجی فیلدهای الزامی
    $fullName = clean_input((string)($input['full_name'] ?? ''));
    $phone = fa_to_en_digits(clean_input((string)($input['phone'] ?? '')));
    $address = clean_input((string)($input['address'] ?? ''));
    $deviceKey = clean_input((string)($input['device_type'] ?? ''));
    $description = clean_input((string)($input['description'] ?? ''));

    $errors = [];
    if (mb_strlen($fullName) < 3) { $errors[] = 'نام و نام خانوادگی الزامی است'; }
    if (!is_valid_iran_mobile($phone) && !is_valid_iran_phone($phone)) { $errors[] = 'شماره تماس معتبر نیست'; }
    if (mb_strlen($address) < 5) { $errors[] = 'آدرس الزامی است'; }
    if ($deviceKey === '') { $errors[] = 'نوع دستگاه را انتخاب کنید'; }
    if (mb_strlen($description) < 10) { $errors[] = 'شرح ایراد را کامل‌تر بنویسید (حداقل ۱۰ کاراکتر)'; }

    // ⏰ ساعات کاری — پیام خارج از ساعت
    $wh = (array)(Config::get(Config::KEY_WORK_HOURS) ?: []);
    $offHours = false;
    if (!empty($wh['start']) && !empty($wh['end'])) {
        $now = date('H:i');
        if ($now < $wh['start'] || $now > $wh['end']) {
            $offHours = true;
        }
    }

    if (!empty($errors)) {
        json_response(['success' => false, 'errors' => $errors], 422);
    }

    // 💾 ثبت درخواست
    $requestId = $db->insert('service_requests', [
        'brand_id'        => $brand['id'],
        'full_name'       => $fullName,
        'phone'           => $phone,
        'phone2'          => fa_to_en_digits(clean_input((string)($input['phone2'] ?? ''))) ?: null,
        'address'         => $address,
        'device_key'      => $deviceKey,
        'device_other'    => clean_input((string)($input['device_other'] ?? '')) ?: null,
        'device_model'    => clean_input((string)($input['device_model'] ?? '')) ?: null,
        'description'     => $description,
        'preferred_date'  => clean_input((string)($input['preferred_date'] ?? '')) ?: null,
        'preferred_time'  => clean_input((string)($input['preferred_time'] ?? '')) ?: null,
        'status'          => 'new',
        'ip_address'      => $ip,
    ]);

    /* 🖼️ تصاویر پیوست (حداکثر ۳) — v2.30:
       اکنون فرم ابتدا تصاویر را با اندپوینت upload-request-image آپلود
       می‌کند و URL آنها را می‌فرستد. URL مطلقِ هم‌دامنه به مسیر نسبی
       تبدیل می‌شود تا با asset_url پنل سازگار و مستقل از دامنه بماند. */
    $images = [];
    if (!empty($input['images']) && is_array($input['images'])) {
        foreach (array_slice($input['images'], 0, MAX_REQUEST_IMAGES) as $imgUrl) {
            $imgUrl = trim((string)$imgUrl);
            if ($imgUrl === '') { continue; }
            $relPath = null;
            if (preg_match('#^https?://[^\s"\'<>]{5,500}$#i', $imgUrl)) {
                /* URL مطلق — اگر هم‌دامنه سایت ساز است، نسبی‌اش کن */
                if (stripos($imgUrl, BASE_URL . '/uploads/requests/') === 0) {
                    $relPath = 'uploads/requests/' . substr($imgUrl, strlen(BASE_URL . '/uploads/requests/'));
                } else {
                    $relPath = $imgUrl; /* URL خارجی معتبر — همان‌طور ذخیره می‌شود */
                }
            } elseif (preg_match('#^uploads/requests/brand-\d+/req_[a-z0-9]+\.(jpe?g|png|webp|gif)$#i', $imgUrl)) {
                /* مسیر نسبی مستقیم از آپلودر — فقط الگوی امن مجاز است */
                $relPath = $imgUrl;
            }
            if ($relPath !== null) {
                $db->insert('request_attachments', ['request_id' => $requestId, 'file_path' => $relPath]);
                $images[] = $relPath;
            }
        }
    }

    // 📨 ارسال به کانال‌های فعال
    $notifier = new NotificationService();
    $requestData = array_merge($input, ['full_name' => $fullName, 'phone' => $phone, 'address' => $address, 'device_key' => $deviceKey, 'description' => $description, 'images' => $images, 'request_id' => $requestId]);
    $sendResult = $notifier->sendServiceRequest($requestData, $brand);

    // 🔔 اعلان داخلی پنل
    NotificationService::notify(0, 'request', 'درخواست جدید: ' . $fullName, 'برند ' . $brand['name_fa'] . ' — ' . $deviceKey, 'requests.php?view=' . $requestId);

    Logger::info('ثبت درخواست خدمات', ['request_id' => $requestId, 'brand' => $brand['name_fa']]);

    json_response([
        'success' => true,
        'data'    => [
            'request_id' => $requestId,
            'message'    => $offHours
                ? ($wh['off_message'] ?? 'درخواست شما ثبت شد و در ساعات کاری بررسی می‌شود.')
                : '✅ درخواست شما با موفقیت ثبت شد. کارشناسان ما به زودی با شما تماس خواهند گرفت.',
            'off_hours'  => $offHours,
            'channels'   => array_filter(array_intersect_key($sendResult, array_flip(['email', 'telegram', 'gscript', 'bale']))),
        ],
    ]);
}
