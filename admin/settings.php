<?php
/**
 * ⚙️ تنظیمات عمومی سایت ساز
 * ===========================
 * ۹ زیربخش مطابق بخش ۳ سند:
 * پایه، تماس، ساعات کاری، ضمانت، هزینه، دامنه، ارسال درخواست، لینک‌دهی
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

/* 📨 v2.31 — تست ارسال ایمیل (AJAX): علت دقیق خطا برمی‌گرداند */
if (!empty($_GET['test_email'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $auth = new Auth();
    $auth->requireLogin();
    $to = filter_var((string)($_GET['to'] ?? ''), FILTER_VALIDATE_EMAIL);
    if (!$to) {
        $to = (string)(Config::get(Config::KEY_NOTIFY_EMAIL)['to'] ?? '');
        $to = filter_var($to, FILTER_VALIDATE_EMAIL) ?: '';
    }
    if (!$to) {
        echo json_encode(['success' => true, 'sent' => false, 'error' => 'ابتدا «آدرس ایمیل مقصد» را در همین تب ذخیره کنید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $mailer = new Mailer();
        $sent = $mailer->send(
            $to,
            '📨 ایمیل آزمایشی سایت ساز ' . SAHAND_NAME_FA,
            '<div style="font-family:Tahoma;direction:rtl;text-align:center;padding:26px"><h2 style="color:#1e40af">✅ ایمیل آزمایشی موفق</h2><p>این ایمیل برای اطمینان از تنظیمات ارسال سایت ساز فرستاده شده است.</p><p style="color:#64748b;font-size:12px">' . e(SAHAND_NAME_FA) . ' — ' . e(jdate(date('Y-m-d H:i'), true)) . '</p></div>'
        );
        echo json_encode(['success' => true, 'sent' => $sent, 'error' => $sent ? '' : Mailer::lastError()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $te) {
        echo json_encode(['success' => true, 'sent' => false, 'error' => $te->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$pageTitle = 'تنظیمات عمومی';
$activeMenu = 'settings';

$db = Database::getInstance();
$fm = new FileManager();

/* 💾 ذخیره تنظیمات — ⚠️ حتماً قبل از هدر پردازش شود (الگوی PRG:
   قبلاً هدر قبل از این بلوک لود می‌شد → خروجی HTML ارسال شده بود و
   redirect() با «headers already sent» شکست می‌خورد → تنظیمات ظاهراً
   ذخیره نمی‌شد و صفحه اطلاعات قبلی را نشان می‌داد) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    Auth::enforceCsrf();

    // 📇 بخش ۱: اطلاعات پایه
    Config::setMany([
        Config::KEY_AGENCY_NAME_FA   => post('agency_name_fa'),
        Config::KEY_AGENCY_NAME_EN   => post('agency_name_en'),
        Config::KEY_AGENCY_SLOGAN_FA => post('agency_slogan_fa'),
        Config::KEY_AGENCY_SLOGAN_EN => post('agency_slogan_en'),
        Config::KEY_MAIN_SITE        => post('agency_main_site'),
        Config::KEY_AGENCY_FOUNDED   => (int)post('agency_founded_year') > 1300 ? (int)post('agency_founded_year') : '',
    ]);

    // 🖼️ آپلود لوگو و فاویکون
    foreach (['agency_logo' => 'logos', 'agency_favicon' => 'logos'] as $field => $dir) {
        if (!empty($_FILES[$field]['name'])) {
            $upload = $fm->uploadImage($_FILES[$field], $dir);
            if ($upload['success']) {
                Config::set($field === 'agency_logo' ? Config::KEY_AGENCY_LOGO : Config::KEY_AGENCY_FAVICON, $upload['path']);
            } else {
                flash('danger', 'خطای آپلود ' . ($field === 'agency_logo' ? 'لوگو' : 'فاویکون') . ': ' . $upload['error']);
            }
        }
    }

    // 📞 بخش ۲: اطلاعات تماس (آرایه‌های چندتایی)
    $phones = array_values(array_filter(array_map('clean_input', (array)($_POST['phones'] ?? []))));
    $mobiles = array_values(array_filter(array_map('clean_input', (array)($_POST['mobiles'] ?? []))));
    $whatsapp = array_values(array_filter(array_map('clean_input', (array)($_POST['whatsapp'] ?? []))));
    $telegram = array_values(array_filter(array_map('clean_input', (array)($_POST['telegram_ids'] ?? []))));
    Config::set(Config::KEY_CONTACTS, [
        'phone' => $phones, 'mobile' => $mobiles, 'whatsapp' => $whatsapp, 'telegram' => $telegram,
    ]);

    $emails = array_values(array_filter(array_map('clean_input', (array)($_POST['emails'] ?? [])), 'is_valid_email'));
    Config::set(Config::KEY_EMAILS, $emails);

    // 📍 آدرس‌ها با مختصات
    $addresses = [];
    $addrTitles = (array)($_POST['addr_title'] ?? []);
    $addrTexts = (array)($_POST['addr_text'] ?? []);
    $addrCities = (array)($_POST['addr_city'] ?? []);
    $addrPostalCodes = (array)($_POST['addr_postal'] ?? []);
    $addrLat = (array)($_POST['addr_lat'] ?? []);
    $addrLng = (array)($_POST['addr_lng'] ?? []);
    $addrMap = (array)($_POST['addr_map'] ?? []);
    foreach ($addrTexts as $i => $text) {
        if (trim($text) === '') {
            continue;
        }
        $addresses[] = [
            'title'      => clean_input($addrTitles[$i] ?? ''),
            'address'    => clean_input($text),
            'city'       => clean_input($addrCities[$i] ?? ''),
            'postal_code'=> clean_input($addrPostalCodes[$i] ?? ''),
            'lat'        => clean_input($addrLat[$i] ?? ''),
            'lng'        => clean_input($addrLng[$i] ?? ''),
            'map_url'    => clean_input($addrMap[$i] ?? ''),
        ];
    }
    Config::set(Config::KEY_ADDRESSES, $addresses);

    // 🌐 شبکه‌های اجتماعی
    $socials = [];
    $socialNames = (array)($_POST['social_name'] ?? []);
    $socialUrls = (array)($_POST['social_url'] ?? []);
    foreach ($socialUrls as $i => $url) {
        if (trim($url) !== '') {
            $socials[] = ['name' => clean_input($socialNames[$i] ?? ''), 'url' => clean_input($url)];
        }
    }
    Config::set(Config::KEY_SOCIALS, $socials);

    // 🕐 بخش ۳: ساعات کاری
    Config::set(Config::KEY_WORK_HOURS, [
        'start'       => clean_input($_POST['work_start'] ?? '09:00'),
        'end'         => clean_input($_POST['work_end'] ?? '20:00'),
        'days'        => array_values(array_map('clean_input', (array)($_POST['work_days'] ?? []))),
        'holidays'    => array_values(array_map('clean_input', (array)($_POST['holiday_days'] ?? []))),
        'special'     => clean_input($_POST['work_special'] ?? ''),
        'off_message' => clean_input($_POST['off_message'] ?? ''),
    ]);

    // 🛡️ بخش ۴: ضمانت
    Config::set(Config::KEY_WARRANTY, [
        'text'            => Validator::sanitizeHtml((string)($_POST['warranty_text'] ?? '')),
        'default_period'  => clean_input($_POST['warranty_period'] ?? '۶ ماه'),
        'void_conditions' => array_values(array_filter(array_map('clean_input', (array)($_POST['warranty_void'] ?? [])))),
        'extra_notes'     => clean_input($_POST['warranty_notes'] ?? ''),
    ]);

    // 💰 بخش ۵: هزینه
    Config::set(Config::KEY_COST, [
        'show_cost'         => !empty($_POST['show_cost']),
        'replacement_text'  => clean_input($_POST['cost_text'] ?? ''),
        'free_diagnosis'    => clean_input($_POST['free_diagnosis'] ?? ''),
    ]);

    // 🌐 بخش ۶: دامنه
    Config::set(Config::KEY_DOMAIN, [
        'format'          => clean_input($_POST['domain_format'] ?? '{brand}.ea-fixer.ir'),
        'allow_custom'    => !empty($_POST['allow_custom_domain']),
        'ssl'             => !empty($_POST['domain_ssl']),
    ]);

    // 📨 بخش ۸: تنظیمات ارسال درخواست (۴ کانال)
    Config::set(Config::KEY_NOTIFY_EMAIL, [
        'enabled' => !empty($_POST['email_enabled']),
        'to'      => clean_input($_POST['email_to'] ?? ''),
        'subject' => clean_input($_POST['email_subject'] ?? 'درخواست خدمات جدید'),
    ]);
    Config::set(Config::KEY_NOTIFY_TELEGRAM, [
        'enabled'   => !empty($_POST['tg_enabled']),
        'bot_token' => clean_input($_POST['tg_token'] ?? ''),
        'chat_id'   => clean_input($_POST['tg_chat'] ?? ''),
    ]);
    Config::set(Config::KEY_NOTIFY_GSCRIPT, [
        'enabled'     => !empty($_POST['gs_enabled']),
        'webapp_url'  => clean_input($_POST['gs_url'] ?? ''),
        // Bot Token و Chat ID از تنظیمات تلگرام بالا خوانده می‌شود — طبق الزام سند
    ]);
    Config::set(Config::KEY_NOTIFY_BALE, [
        'enabled'   => !empty($_POST['bale_enabled']),
        'bot_token' => clean_input($_POST['bale_token'] ?? ''),
        'chat_id'   => clean_input($_POST['bale_chat'] ?? ''),
    ]);
    // ⚙️ SMTP اختیاری + فرستنده (v2.31 — ریشه «ایمیل ارسال نمیشود»)
    Config::set('smtp_settings', [
        'enabled'   => !empty($_POST['smtp_enabled']),
        'host'      => clean_input($_POST['smtp_host'] ?? ''),
        'port'      => (int)clean_input($_POST['smtp_port'] ?? 587),
        'username'  => clean_input($_POST['smtp_user'] ?? ''),
        'password'  => (string)($_POST['smtp_pass'] ?? ''),
        'secure'    => clean_input($_POST['smtp_secure'] ?? 'tls'),
    ]);
    Config::set('smtp_from_email', filter_var(clean_input($_POST['smtp_from_email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '');
    Config::set('smtp_from_name', clean_input($_POST['smtp_from_name'] ?? ''));

    // 🔗 بخش ۹: لینک‌دهی
    Config::set(Config::KEY_LINKING, [
        'main_site_footer'   => !empty($_POST['link_main_footer']),
        'other_brands_page'  => !empty($_POST['link_other_brands']),
        'default_nofollow'   => !empty($_POST['link_nofollow']),
    ]);

    // 🔤 v2.12: فونت محیط سایت‌ساز (متن/عنوان/کد — از بین فونت‌های نصب‌شده)
    Config::set('admin_ui_fonts', [
        'body'    => clean_input($_POST['ui_font_body'] ?? ''),
        'heading' => clean_input($_POST['ui_font_heading'] ?? ''),
        'mono'    => clean_input($_POST['ui_font_mono'] ?? ''),
    ]);

    // 🖼️ v2.15: سرویس تولید تصویر مقاله (انتخاب از لیست سرویس‌های رایگان + کلیدها)
    $photoService = clean_input($_POST['photo_service'] ?? 'pollinations_flux');
    if (!array_key_exists($photoService, AiPhotoService::SERVICES)) {
        $photoService = 'pollinations_flux';
    }
    $photoKeys = [];
    foreach (array_keys(AiPhotoService::SERVICES) as $svcKey) {
        $savedKey = trim((string)($_POST['photo_key_' . $svcKey] ?? ''));
        if ($savedKey !== '') {
            $photoKeys[$svcKey] = $savedKey;
        }
    }
    Config::set('article_photo_settings', [
        'service' => $photoService,
        'keys'    => $photoKeys,
        'timeout' => max(20, min(120, (int)clean_input($_POST['photo_timeout'] ?? 45))),
    ]);

    /* 🤖 v2.43 (S09+S16) → v2.44 (S01) — سرویس مدل زبانی رایگان
       متد واحد سه‌گانه + ترتیب دلخواه فال‌بک + راهنمای کلید */
    $textMethod = clean_input($_POST['ai_text_method'] ?? 'internal');
    if (!in_array($textMethod, ['internal', 'llm', 'research'], true)) { $textMethod = 'internal'; }
    $textProvider = clean_input($_POST['ai_text_provider'] ?? 'pollinations');
    if (!array_key_exists($textProvider, AiTextService::PROVIDERS)) { $textProvider = 'pollinations'; }
    $textKeys = [];
    foreach (array_keys(AiTextService::PROVIDERS) as $pKey) {
        $savedKey = trim((string)($_POST['ai_text_key_' . $pKey] ?? ''));
        if ($savedKey !== '') { $textKeys[$pKey] = $savedKey; }
    }
    /* 🎛 ترتیب دلخواه فال‌بک — رشته JSON از ویرایشگر ترتیب */
    $fallbackOrder = [];
    $foRaw = (string)($_POST['ai_fallback_order'] ?? '[]');
    $foDecoded = json_decode($foRaw, true);
    if (is_array($foDecoded)) {
        foreach ($foDecoded as $foP) {
            $foP = clean_input((string)$foP);
            if (array_key_exists($foP, AiTextService::PROVIDERS)) { $fallbackOrder[] = $foP; }
        }
    }
    Config::set('article_text_settings', [
        'method'   => $textMethod,
        'provider' => $textProvider,
        'model'    => clean_input($_POST['ai_text_model'] ?? ''),
        'keys'     => $textKeys,
        'fallback' => !empty($_POST['ai_text_fallback']),
        'fallback_order' => $fallbackOrder,
        'fallback_to_internal' => !empty($_POST['ai_fallback_internal']),
        'timeout'  => max(15, min(180, (int)clean_input($_POST['ai_text_timeout'] ?? 45))),
        'cloudflare_account_id' => clean_input($_POST['ai_cf_account_id'] ?? ''),
    ]);

    Logger::activity((int)$_SESSION['user_id'], 'بروزرسانی تنظیمات', 'تنظیمات عمومی سایت ساز ذخیره شد');

    /* 🌍 v2.44 (S11) — سوییچ چندزبانه */
    Config::set('i18n_settings', [
        'enabled' => !empty($_POST['i18n_enabled']),
    ]);

    flash('success', '✅ تنظیمات با موفقیت ذخیره شد.');
    redirect('settings.php');
}

/* 💬 v2.44 (S10) — چت مستقیم با مدل زبانی (AJAX — زنجیره فال‌بک تنظیمات) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ai_chat') {
    Auth::enforceCsrf();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $message = trim((string)($_POST['message'] ?? ''));
        if (mb_strlen($message) < 2) {
            json_response(['success' => false, 'error' => 'پیام خیلی کوتاه است.'], 400);
        }
        $history = [];
        $hRaw = (string)($_POST['history'] ?? '[]');
        $hDec = json_decode($hRaw, true);
        if (is_array($hDec)) {
            foreach (array_slice($hDec, -6) as $h) { /* فقط ۶ پیام آخر برای زمینه */
                $role = ($h['role'] ?? '') === 'user' ? 'user' : 'assistant';
                $txt = mb_substr(trim((string)($h['content'] ?? '')), 0, 2000);
                if ($txt !== '') { $history[] = ['role' => $role, 'text' => $txt]; }
            }
        }
        if (function_exists('set_time_limit')) { @set_time_limit(150); }
        $svc = new AiTextService();
        $system = 'تو دستیار هوشمند مدیر «سایت ساز برند سهند سرویس» هستی — سایت‌ساز نمایندگی‌های تعمیرات لوازم خانگی. '
            . 'فارسی روان و کاربردی جواب بده؛ اگر مقاله/متن خواستی ساختار حرفه‌ای بده؛ از علائم و پاراگراف‌بندی تمیز استفاده کن.';
        /* زمینه گفتگو در پرامپت ادغام می‌شود (سرویس‌ها stateless اند) */
        $ctx = '';
        foreach ($history as $i => $h) {
            $ctx .= ($h['role'] === 'user' ? 'کاربر: ' : 'دستیار: ') . $h['text'] . "\n";
        }
        $prompt = ($ctx !== '' ? "گفتگوی قبلی:\n" . $ctx . "\n---\nپیام جدید کاربر: " . $message : $message);
        $res = $svc->chatWithFallback($prompt, $system);
        if ($res['text'] === '') {
            $failedNote = $res['failed'] ? (' — تلاش‌ها: ' . implode('، ', array_keys($res['failed']))) : '';
            json_response(['success' => false, 'error' => 'هیچ مدلی جواب نداد' . $failedNote . ' — کلید و اتصال را بررسی کنید یا متد داخلی را انتخاب کنید.'], 502);
        }
        json_response(['success' => true, 'data' => [
            'text' => $res['text'],
            'provider' => $res['provider'],
            'provider_label' => AiTextService::PROVIDERS[$res['provider']][0] ?? $res['provider'],
        ]]);
    } catch (Throwable $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

/* 🎨 v2.44 (S10) — چت مستقیم تولید تصویر (AJAX — سرویس عکس تنظیمات) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ai_image_chat') {
    Auth::enforceCsrf();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $prompt = trim((string)($_POST['prompt'] ?? ''));
        if (mb_strlen($prompt) < 3) {
            json_response(['success' => false, 'error' => 'پرامپت خیلی کوتاه است.'], 400);
        }
        if (function_exists('set_time_limit')) { @set_time_limit(180); }
        $photo = new AiPhotoService();
        $promptEn = $prompt;
        /* اگر فارسی بود، ترجمه ساده با مدل زبانی (سرویس‌های عکس انگلیسی بهتر می‌فهمند) */
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $prompt)) {
            try {
                $tr = (new AiTextService())->chatWithFallback(
                    'Translate this image-generation prompt to concise English (only the translation, nothing else): ' . $prompt,
                    'You are a translator. Output only the English translation.'
                );
                if (trim($tr['text']) !== '') {
                    $promptEn = trim(strip_tags($tr['text']));
                }
            } catch (Throwable $tE) { /* ترجمه نشد — همان فارسی می‌رود */ }
        }
        $img = $photo->generateFromPrompt($promptEn);
        if (empty($img)) {
            json_response(['success' => false, 'error' => 'سرویس تصویر جواب نداد — کلید/سرویس را در تب «تولید تصویر» بررسی کنید.'], 502);
        }
        json_response(['success' => true, 'data' => [
            'url' => $img['url'],
            'service' => $img['service'] ?? '',
            'service_label' => AiPhotoService::SERVICES[$img['service'] ?? ''][0] ?? '',
        ]]);
    } catch (Throwable $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

/* 🧪 v2.15 — تست سرویس تولید تصویر مقاله (AJAX — با مقدار فعلی فرم حتی قبل از ذخیره) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_photo_service') {
    Auth::enforceCsrf();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $svc = clean_input($_POST['service'] ?? '');
        if (!array_key_exists($svc, AiPhotoService::SERVICES)) {
            json_response(['success' => false, 'error' => 'سرویس ناشناخته است.'], 400);
        }
        $keyOverride = isset($_POST['key']) ? trim((string)$_POST['key']) : null; /* مقدار فرم — بدون ذخیره */
        if (function_exists('set_time_limit')) { @set_time_limit(90); }
        $health = (new AiPhotoService())->healthCheck($svc, $keyOverride);
        json_response(['success' => true, 'data' => $health]);
    } catch (Throwable $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

/* 🧪 v2.43 (S09) — تست ارائه‌دهنده مدل زبانی (AJAX) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_ai_text') {
    Auth::enforceCsrf();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $p = clean_input($_POST['provider'] ?? '');
        if (!array_key_exists($p, AiTextService::PROVIDERS)) {
            json_response(['success' => false, 'error' => 'ارائه‌دهنده ناشناخته است.'], 400);
        }
        $keyOverride = isset($_POST['key']) ? trim((string)$_POST['key']) : null;
        if (function_exists('set_time_limit')) { @set_time_limit(120); }
        $health = (new AiTextService())->healthCheck($p, $keyOverride);
        json_response(['success' => true, 'data' => $health]);
    } catch (Throwable $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

require __DIR__ . '/includes/header.php';

/* 📥 بارگذاری مقادیر فعلی */
$nameFa   = (string)Config::get(Config::KEY_AGENCY_NAME_FA);
$nameEn   = (string)Config::get(Config::KEY_AGENCY_NAME_EN);
$sloganFa = (string)Config::get(Config::KEY_AGENCY_SLOGAN_FA);
$sloganEn = (string)Config::get(Config::KEY_AGENCY_SLOGAN_EN);
$mainSite = (string)Config::get(Config::KEY_MAIN_SITE);
$foundedYear = (string)Config::get(Config::KEY_AGENCY_FOUNDED);
$logo     = (string)Config::get(Config::KEY_AGENCY_LOGO);
$favicon  = (string)Config::get(Config::KEY_AGENCY_FAVICON);

$contacts  = (array)(Config::get(Config::KEY_CONTACTS) ?: []);
$emails    = (array)(Config::get(Config::KEY_EMAILS) ?: []);
$addresses = (array)(Config::get(Config::KEY_ADDRESSES) ?: []);
$socials   = (array)(Config::get(Config::KEY_SOCIALS) ?: []);
$wh        = (array)(Config::get(Config::KEY_WORK_HOURS) ?: []);
$warranty  = (array)(Config::get(Config::KEY_WARRANTY) ?: []);
$cost      = (array)(Config::get(Config::KEY_COST) ?: []);
$domain    = (array)(Config::get(Config::KEY_DOMAIN) ?: []);
$nEmail    = (array)(Config::get(Config::KEY_NOTIFY_EMAIL) ?: []);
$nTg       = (array)(Config::get(Config::KEY_NOTIFY_TELEGRAM) ?: []);
$nGs       = (array)(Config::get(Config::KEY_NOTIFY_GSCRIPT) ?: []);
$nBale     = (array)(Config::get(Config::KEY_NOTIFY_BALE) ?: []);
$smtp      = (array)(Config::get('smtp_settings') ?: []);
$linking   = (array)(Config::get(Config::KEY_LINKING) ?: []);

$daysList = ['sat' => 'شنبه', 'sun' => 'یکشنبه', 'mon' => 'دوشنبه', 'tue' => 'سه‌شنبه', 'wed' => 'چهارشنبه', 'thu' => 'پنجشنبه', 'fri' => 'جمعه'];

/* 🔤 v2.12: فونت‌های فارسی نصب‌شده (فقط پوشه‌هایی که فایل فونت دارند) برای تنظیم محیط پنل */
$uiFonts = (array)(Config::get('admin_ui_fonts') ?: []);
$fontsManifest = is_file(ASSETS_PATH . '/fonts/manifest.json') ? (json_decode((string)file_get_contents(ASSETS_PATH . '/fonts/manifest.json'), true) ?: []) : [];
$installedFaFonts = [];
foreach (($fontsManifest['fonts']['fa'] ?? []) as $uiF) {
    $uiDir = ASSETS_PATH . '/fonts/fa/' . ($uiF['slug'] ?? '');
    if (is_dir($uiDir) && (glob($uiDir . '/*.woff2') || glob($uiDir . '/*.ttf') || glob($uiDir . '/*.woff'))) {
        $installedFaFonts[] = $uiF;
    }
}

/* 🖼️ v2.15: سرویس‌های تولید تصویر مقاله برای تب تنظیمات */
$photoSettings = AiPhotoService::settings();
$photoServices = AiPhotoService::servicesList();

/* 🤖 v2.43 (S09+S16) → v2.44 (S01): ارائه‌دهنده‌های مدل زبانی + راهنمای کلید */
$aiTextSettings = AiTextService::settings();
$aiTextProviders = AiTextService::providersList();
$aiTextChain = (new AiTextService())->fallbackChain();

/* 🌍 v2.44 (S11): تنظیمات چندزبانه */
$i18nSettings = (array)(Config::get('i18n_settings') ?: []);
?>

<form method="post" enctype="multipart/form-data">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="save_settings">

    <!-- 🗂️ تب‌های مقاوم (سیستم stab مستقل — با پشتیبانی hash و بازگشت به بالای صفحه) -->
    <div class="stab-bar" id="settings-tabs-bar">
        <div class="stab-tabs" id="settings-tabs">
            <button type="button" class="stab-btn active" data-tab="basic">📇 پایه</button>
            <button type="button" class="stab-btn" data-tab="contact">📞 تماس</button>
            <button type="button" class="stab-btn" data-tab="hours">🕐 ساعات کاری</button>
            <button type="button" class="stab-btn" data-tab="warranty">🛡️ ضمانت</button>
            <button type="button" class="stab-btn" data-tab="cost">💰 هزینه و دامنه</button>
            <button type="button" class="stab-btn" data-tab="notify">📨 ارسال درخواست</button>
            <button type="button" class="stab-btn" data-tab="links">🔗 لینک‌دهی</button>
            <button type="button" class="stab-btn" data-tab="uifonts">🔤 فونت محیط</button>
            <button type="button" class="stab-btn" data-tab="photosvc">🖼️ تولید تصویر</button>
            <button type="button" class="stab-btn" data-tab="aitext">🤖 هوش مصنوعی</button>
            <button type="button" class="stab-btn" data-tab="aichat-img">🎨 چت تصویرساز</button>
            <button type="button" class="stab-btn" data-tab="i18n">🌍 چندزبانه</button>
        </div>
        <div class="stab-actions">
            <button type="submit" class="btn btn-primary">💾 ذخیره همه تنظیمات</button>
        </div>
    </div>

    <!-- 📇 تب اطلاعات پایه -->
    <div id="pane-basic" class="stab-pane active">
        <div class="card">
            <div class="card-header"><h3>📇 اطلاعات پایه نمایندگی</h3></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>نام نمایندگی (فارسی) <span class="req">*</span></label>
                        <input type="text" name="agency_name_fa" class="form-control" required value="<?= e($nameFa) ?>">
                    </div>
                    <div class="form-group">
                        <label>نام نمایندگی (انگلیسی)</label>
                        <input type="text" name="agency_name_en" class="form-control" style="direction:ltr;text-align:left" value="<?= e($nameEn) ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>شعار (فارسی)</label>
                        <input type="text" name="agency_slogan_fa" class="form-control" value="<?= e($sloganFa) ?>">
                    </div>
                    <div class="form-group">
                        <label>شعار (انگلیسی)</label>
                        <input type="text" name="agency_slogan_en" class="form-control" style="direction:ltr;text-align:left" value="<?= e($sloganEn) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>📅 سال شروع فعالیت نمایندگی</label>
                    <input type="number" name="agency_founded_year" class="form-control" min="1350" max="1410" placeholder="مثلاً ۱۳۸۵" style="direction:ltr;text-align:center" value="<?= e((string)($foundedYear ?: '')) ?>">
                    <div class="hint">برای کارت‌های سایت‌ها («... سال سابقه خدمات‌رسانی») استفاده می‌شود و به‌صورت خودکار به‌روز محاسبه می‌شود.</div>
                </div>
                <div class="form-group">
                    <label>🌐 آدرس سایت اصلی نمایندگی</label>
                    <input type="url" name="agency_main_site" class="form-control" style="direction:ltr;text-align:left" value="<?= e($mainSite) ?>" placeholder="https://ea-fixer.ir">
                    <div class="hint">تمام سایت‌های برند به این آدرس لینک می‌دهند.</div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>🖼️ لوگوی نمایندگی</label>
                        <?php if ($logo): ?><img src="<?= asset_url($logo) ?>" alt="لوگو" style="max-height:60px;margin-bottom:8px;display:block"><?php endif; ?>
                        <input type="file" name="agency_logo" class="form-control" accept="image/png,image/svg+xml,image/jpeg,image/webp">
                        <div class="hint">PNG/SVG با پس‌زمینه شفاف پیشنهاد می‌شود.</div>
                    </div>
                    <div class="form-group">
                        <label>🔖 فاویکون</label>
                        <?php if ($favicon): ?><img src="<?= asset_url($favicon) ?>" alt="فاویکون" style="max-height:32px;margin-bottom:8px;display:block"><?php endif; ?>
                        <input type="file" name="agency_favicon" class="form-control" accept="image/png,image/x-icon,image/svg+xml">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 📞 تب تماس -->
    <div id="pane-contact" class="stab-pane">
        <div class="card">
            <div class="card-header"><h3>📞 شماره‌های تماس (چندتایی — نامحدود)</h3></div>
            <div class="card-body">
                <?php
                /* 🧩 v2.7: دکمه افزودن تمیز — فقط شناسه کانتینر پاس داده می‌شود؛
                   HTML داخل onclick ممنوع (کوتیشن تودرتو = شکستن پارس صفحه) */
                $repeatTemplate = function (string $name, string $label, array $values, string $placeholder = '', string $dir = 'ltr') {
                    echo '<div class="form-group"><label>' . $label . '</label><div id="rows-' . $name . '">';
                    $values = $values ?: [''];
                    foreach ($values as $i => $val) {
                        echo '<div class="repeat-row" style="display:flex;gap:8px;margin-bottom:8px">';
                        echo '<input type="text" name="' . $name . '[]" class="form-control" value="' . e((string)$val) . '" placeholder="' . e($placeholder) . '" style="direction:' . $dir . ';text-align:' . ($dir === 'ltr' ? 'left' : 'right') . '">';
                        echo '<button type="button" class="btn btn-outline btn-sm" onclick="removeRepeatRow(this)">🗑️</button></div>';
                    }
                    echo '</div>';
                    echo '<button type="button" class="btn btn-outline btn-sm" onclick="addRepeatRow(\'rows-' . $name . '\')">➕ افزودن</button></div>';
                };
                $repeatTemplate('phones', '☎️ شماره تلفن ثابت', (array)($contacts['phone'] ?? []), '021XXXXXXXX');
                $repeatTemplate('mobiles', '📱 شماره موبایل', (array)($contacts['mobile'] ?? []), '0912XXXXXXX');
                $repeatTemplate('whatsapp', '💬 شماره واتساپ', (array)($contacts['whatsapp'] ?? []), '0912XXXXXXX');
                $repeatTemplate('telegram_ids', '📨 شناسه تلگرام', (array)($contacts['telegram'] ?? []), '@username');
                $repeatTemplate('emails', '📧 آدرس ایمیل', $emails, 'info@example.com');
                ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3>📍 آدرس‌های فیزیکی (شعب)</h3></div>
            <div class="card-body" id="rows-addresses">
                <?php $addresses = $addresses ?: [[]]; foreach ($addresses as $i => $addr): ?>
                    <div class="repeat-row card" style="box-shadow:none;margin-bottom:14px">
                        <div class="card-body" style="padding:16px">
                            <div class="form-row-3">
                                <div class="form-group"><label>عنوان شعبه</label><input type="text" name="addr_title[]" class="form-control" value="<?= e($addr['title'] ?? '') ?>"></div>
                                <div class="form-group"><label>شهر</label><input type="text" name="addr_city[]" class="form-control" value="<?= e($addr['city'] ?? '') ?>"></div>
                                <div class="form-group"><label>کد پستی</label><input type="text" name="addr_postal[]" class="form-control" style="direction:ltr;text-align:left" value="<?= e($addr['postal_code'] ?? '') ?>"></div>
                            </div>
                            <div class="form-group"><label>آدرس کامل</label><textarea name="addr_text[]" class="form-control" rows="2"><?= e($addr['address'] ?? '') ?></textarea></div>
                            <div class="form-row-3">
                                <div class="form-group"><label>عرض جغرافیایی (lat)</label><input type="text" name="addr_lat[]" class="form-control" style="direction:ltr;text-align:left" value="<?= e($addr['lat'] ?? '') ?>"></div>
                                <div class="form-group"><label>طول جغرافیایی (lng)</label><input type="text" name="addr_lng[]" class="form-control" style="direction:ltr;text-align:left" value="<?= e($addr['lng'] ?? '') ?>"></div>
                                <div class="form-group"><label>لینک نقشه گوگل</label><input type="url" name="addr_map[]" class="form-control" style="direction:ltr;text-align:left" value="<?= e($addr['map_url'] ?? '') ?>"></div>
                            </div>
                            <button type="button" class="btn btn-outline btn-sm" onclick="removeRepeatRow(this)">🗑️ حذف این شعبه</button>
                        </div>
                    </div>
                <?php endforeach; ?>
                <button type="button" class="btn btn-outline" onclick="duplicateAddress()">➕ افزودن شعبه جدید</button>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3>🌐 شبکه‌های اجتماعی</h3></div>
            <div class="card-body" id="rows-socials">
                <?php $socials = $socials ?: [[]]; foreach ($socials as $i => $social): ?>
                    <div class="repeat-row" style="display:flex;gap:8px;margin-bottom:8px">
                        <select name="social_name[]" class="form-control" style="max-width:170px">
                            <?php foreach (['اینستاگرام' => 'instagram', 'تلگرام' => 'telegram', 'واتساپ' => 'whatsapp', 'آپارات' => 'aparat', 'یوتیوب' => 'youtube', 'لینکدین' => 'linkedin', 'توییتر/X' => 'x'] as $label => $key): ?>
                                <option value="<?= e($label) ?>" <?= ($social['name'] ?? '') === $label ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="url" name="social_url[]" class="form-control" style="direction:ltr;text-align:left" value="<?= e($social['url'] ?? '') ?>" placeholder="https://instagram.com/...">
                        <button type="button" class="btn btn-outline btn-sm" onclick="removeRepeatRow(this)">🗑️</button>
                    </div>
                <?php endforeach; ?>
                <button type="button" class="btn btn-outline btn-sm" onclick="duplicateSocial()">➕ افزودن</button>
            </div>
        </div>
    </div>

    <!-- 🕐 تب ساعات کاری -->
    <div id="pane-hours" class="stab-pane">
        <div class="card">
            <div class="card-header"><h3>🕐 ساعات و روزهای کاری</h3></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>ساعت شروع</label>
                        <input type="time" name="work_start" class="form-control" value="<?= e($wh['start'] ?? '09:00') ?>">
                    </div>
                    <div class="form-group">
                        <label>ساعت پایان</label>
                        <input type="time" name="work_end" class="form-control" value="<?= e($wh['end'] ?? '20:00') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>📅 روزهای کاری</label>
                        <?php $workDays = (array)($wh['days'] ?? []); foreach ($daysList as $key => $label): ?>
                            <label class="form-check" style="margin-bottom:6px">
                                <input type="checkbox" name="work_days[]" value="<?= $key ?>" <?= in_array($key, $workDays, true) ? 'checked' : '' ?>>
                                <?= $label ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-group">
                        <label>🏖️ روزهای تعطیل</label>
                        <?php $holidays = (array)($wh['holidays'] ?? []); foreach ($daysList as $key => $label): ?>
                            <label class="form-check" style="margin-bottom:6px">
                                <input type="checkbox" name="holiday_days[]" value="<?= $key ?>" <?= in_array($key, $holidays, true) ? 'checked' : '' ?>>
                                <?= $label ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label>ساعات کاری ویژه (تعطیلات رسمی و ...)</label>
                    <textarea name="work_special" class="form-control" rows="3"><?= e($wh['special'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>💬 پیام خارج از ساعت کاری</label>
                    <textarea name="off_message" class="form-control" rows="2"><?= e($wh['off_message'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- 🛡️ تب ضمانت -->
    <div id="pane-warranty" class="stab-pane">
        <div class="card">
            <div class="card-header"><h3>🛡️ شرایط ضمانت</h3></div>
            <div class="card-body">
                <div class="form-group">
                    <label>مدت ضمانت پیش‌فرض</label>
                    <input type="text" name="warranty_period" class="form-control" value="<?= e($warranty['default_period'] ?? '۶ ماه') ?>" placeholder="مثلاً ۶ ماه">
                </div>
                <div class="form-group">
                    <label>📝 متن کامل ضمانت‌نامه</label>
                    <textarea name="warranty_text" class="form-control" rows="8"><?= e($warranty['text'] ?? '') ?></textarea>
                    <div class="hint">متن کامل شامل عنوان، شرایط، استثنائات — در صفحه ضمانت سایت‌های برند نمایش داده می‌شود.</div>
                </div>
                <div class="form-group">
                    <label>⛔ شرایط ابطال ضمانت</label>
                    <div id="rows-warranty-void">
                        <?php $voids = (array)($warranty['void_conditions'] ?? []); $voids = $voids ?: ['']; foreach ($voids as $void): ?>
                            <div class="repeat-row" style="display:flex;gap:8px;margin-bottom:8px">
                                <input type="text" name="warranty_void[]" class="form-control" value="<?= e((string)$void) ?>" placeholder="مثلاً آسیب فیزیکی ناشی از ضربه">
                                <button type="button" class="btn btn-outline btn-sm" onclick="removeRepeatRow(this)">🗑️</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" onclick="addRepeatRow('rows-warranty-void')">➕ افزودن شرط</button>
                </div>
                <div class="form-group">
                    <label>توضیحات اضافی</label>
                    <textarea name="warranty_notes" class="form-control" rows="3"><?= e($warranty['extra_notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- 💰 تب هزینه و دامنه -->
    <div id="pane-cost" class="stab-pane">
        <div class="card">
            <div class="card-header"><h3>💰 تنظیمات هزینه</h3></div>
            <div class="card-body">
                <label class="form-check" style="margin-bottom:14px">
                    <input type="checkbox" name="show_cost" <?= !empty($cost['show_cost']) ? 'checked' : '' ?>>
                    نمایش هزینه در سایت‌های برند (⚠️ پیشنهاد: غیرفعال)
                </label>
                <div class="form-group">
                    <label>متن جایگزین هزینه</label>
                    <textarea name="cost_text" class="form-control" rows="2"><?= e($cost['replacement_text'] ?? 'هزینه پس از بررسی و ایرادیابی دستگاه اعلام می‌شود') ?></textarea>
                </div>
                <div class="form-group">
                    <label>عبارت رایگان ایرادیابی (اختیاری)</label>
                    <input type="text" name="free_diagnosis" class="form-control" value="<?= e($cost['free_diagnosis'] ?? '') ?>" placeholder="ایرادیابی رایگان">
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3>🌐 تنظیمات دامنه</h3></div>
            <div class="card-body">
                <div class="form-group">
                    <label>فرمت پیش‌فرض دامنه سایت‌های برند</label>
                    <input type="text" name="domain_format" class="form-control" style="direction:ltr;text-align:left" value="<?= e($domain['format'] ?? '{brand}.ea-fixer.ir') ?>" placeholder="{brand}.ea-fixer.ir">
                    <div class="hint">{brand} با نام انگلیسی برند جایگزین می‌شود (مثلاً samsung.ea-fixer.ir).</div>
                </div>
                <label class="form-check" style="margin-bottom:10px">
                    <input type="checkbox" name="allow_custom_domain" <?= !empty($domain['allow_custom']) ? 'checked' : '' ?>>
                    امکان استفاده از دامنه اختصاصی برای برندها
                </label>
                <label class="form-check">
                    <input type="checkbox" name="domain_ssl" <?= !isset($domain['ssl']) || !empty($domain['ssl']) ? 'checked' : '' ?>>
                    SSL / HTTPS فعال
                </label>
            </div>
        </div>
    </div>

    <!-- 📨 تب ارسال درخواست -->
    <div id="pane-notify" class="stab-pane">
        <div class="card">
            <div class="card-header"><h3>📧 کانال ایمیل</h3></div>
            <div class="card-body">
                <label class="form-check" style="margin-bottom:14px">
                    <input type="checkbox" name="email_enabled" <?= !empty($nEmail['enabled']) ? 'checked' : '' ?>>
                    فعال‌سازی ارسال درخواست‌ها به ایمیل
                </label>
                <div class="form-row">
                    <div class="form-group"><label>آدرس ایمیل مقصد</label><input type="email" name="email_to" class="form-control" style="direction:ltr;text-align:left" value="<?= e($nEmail['to'] ?? '') ?>"></div>
                    <div class="form-group"><label>عنوان ایمیل</label><input type="text" name="email_subject" class="form-control" value="<?= e($nEmail['subject'] ?? 'درخواست خدمات جدید') ?>"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>📧 ایمیل فرستنده (اختیاری)</label>
                        <input type="email" name="smtp_from_email" class="form-control" style="direction:ltr;text-align:left" value="<?= e((string)Config::get('smtp_from_email')) ?>" placeholder="no-reply@yourdomain.ir">
                        <div class="hint" style="margin-top:4px">🆕 روی هاست اشتراکی، فرستنده باید متعلق به دامنه میزبان باشد — یک ایمیل روی دامنه خود را وارد کنید تا ایمیل‌ها رد نشوند. خالی = دامنه فعلی سایت‌ساز.</div>
                    </div>
                    <div class="form-group"><label>نام فرستنده</label>
                        <input type="text" name="smtp_from_name" class="form-control" value="<?= e((string)Config::get('smtp_from_name')) ?>" placeholder="سهند سرویس">
                    </div>
                </div>
                <button type="button" class="btn btn-outline" style="margin-bottom:6px" onclick="sahandTestEmail(this)">📨 ارسال ایمیل آزمایشی و نمایش خطا</button>
                <details style="margin-top:10px">
                    <summary style="cursor:pointer;font-weight:700;font-size:13px">⚙️ تنظیمات پیشرفته SMTP (اختیاری)</summary>
                    <div class="card-body">
                        <label class="form-check" style="margin-bottom:12px"><input type="checkbox" name="smtp_enabled" <?= !empty($smtp['enabled']) ? 'checked' : '' ?>> استفاده از SMTP</label>
                        <div class="form-row-3">
                            <div class="form-group"><label>سرور SMTP</label><input type="text" name="smtp_host" class="form-control" style="direction:ltr;text-align:left" value="<?= e($smtp['host'] ?? '') ?>"></div>
                            <div class="form-group"><label>پورت</label><input type="number" name="smtp_port" class="form-control" value="<?= e((string)($smtp['port'] ?? 587)) ?>"></div>
                            <div class="form-group"><label>رمزگذاری</label>
                                <select name="smtp_secure" class="form-control">
                                    <option value="tls" <?= ($smtp['secure'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS</option>
                                    <option value="ssl" <?= ($smtp['secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>نام کاربری</label><input type="text" name="smtp_user" class="form-control" style="direction:ltr;text-align:left" value="<?= e($smtp['username'] ?? '') ?>"></div>
                            <div class="form-group"><label>رمز عبور</label><input type="password" name="smtp_pass" class="form-control" value="<?= e($smtp['password'] ?? '') ?>"></div>
                        </div>
                    </div>
                </details>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3>📱 کانال تلگرام (مستقیم)</h3></div>
            <div class="card-body">
                <label class="form-check" style="margin-bottom:14px">
                    <input type="checkbox" name="tg_enabled" <?= !empty($nTg['enabled']) ? 'checked' : '' ?>>
                    فعال‌سازی ارسال مستقیم به تلگرام
                </label>
                <div class="form-row">
                    <div class="form-group"><label>Bot Token</label><input type="text" name="tg_token" class="form-control" style="direction:ltr;text-align:left" value="<?= e($nTg['bot_token'] ?? '') ?>" placeholder="123456:ABC-DEF..."></div>
                    <div class="form-group"><label>Chat ID</label><input type="text" name="tg_chat" class="form-control" style="direction:ltr;text-align:left" value="<?= e($nTg['chat_id'] ?? '') ?>" placeholder="-1001234567890"></div>
                </div>
                <div class="hint">💡 در صورت تحریم سرور و خطای ارسال مستقیم، از واسط Google Apps Script (پایین) استفاده کنید.</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3>🔄 واسط Google Apps Script (تلگرام در زمان تحریم)</h3></div>
            <div class="card-body">
                <label class="form-check" style="margin-bottom:14px">
                    <input type="checkbox" name="gs_enabled" <?= !empty($nGs['enabled']) ? 'checked' : '' ?>>
                    فعال‌سازی ارسال از طریق واسط گوگل
                </label>
                <div class="form-group">
                    <label>آدرس Web App</label>
                    <input type="url" name="gs_url" class="form-control" style="direction:ltr;text-align:left" value="<?= e($nGs['webapp_url'] ?? '') ?>" placeholder="https://script.google.com/macros/s/.../exec">
                </div>
                <div class="alert alert-info">📌 <b>نکته مهم:</b> Bot Token و Chat ID از تنظیمات تلگرام بالا خوانده می‌شوند و داخل اسکریپت گوگل ذخیره نمی‌شوند. کد آماده اسکریپت در بخش «مستندات ← راهنمای Google Script» قابل کپی است.</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3>💬 کانال پیام‌رسان بله</h3></div>
            <div class="card-body">
                <label class="form-check" style="margin-bottom:14px">
                    <input type="checkbox" name="bale_enabled" <?= !empty($nBale['enabled']) ? 'checked' : '' ?>>
                    فعال‌سازی ارسال به بله
                </label>
                <div class="form-row">
                    <div class="form-group"><label>Bot Token بله</label><input type="text" name="bale_token" class="form-control" style="direction:ltr;text-align:left" value="<?= e($nBale['bot_token'] ?? '') ?>"></div>
                    <div class="form-group"><label>Chat ID بله</label><input type="text" name="bale_chat" class="form-control" style="direction:ltr;text-align:left" value="<?= e($nBale['chat_id'] ?? '') ?>"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 🔗 تب لینک‌دهی -->
    <div id="pane-links" class="stab-pane">
        <div class="card">
            <div class="card-header"><h3>🔗 تنظیمات لینک‌دهی</h3></div>
            <div class="card-body">
                <label class="form-check" style="margin-bottom:10px">
                    <input type="checkbox" name="link_main_footer" <?= !isset($linking['main_site_footer']) || !empty($linking['main_site_footer']) ? 'checked' : '' ?>>
                    لینک به سایت اصلی (<?= e($mainSite ?: 'ea-fixer.ir') ?>) در فوتر سایت‌های برند
                </label>
                <label class="form-check" style="margin-bottom:10px">
                    <input type="checkbox" name="link_other_brands" <?= !isset($linking['other_brands_page']) || !empty($linking['other_brands_page']) ? 'checked' : '' ?>>
                    نمایش سایر برندهای مورد خدمت در صفحه «سایر برندها» و فوتر
                </label>
                <label class="form-check">
                    <input type="checkbox" name="link_nofollow" <?= !empty($linking['default_nofollow']) ? 'checked' : '' ?>>
                    پیش‌فرض nofollow برای لینک‌های خارجی
                </label>
            </div>
        </div>
    </div>

    <!-- 🔤 تب فونت محیط سایت‌ساز (v2.12) -->
    <div id="pane-uifonts" class="stab-pane">
        <div class="card">
            <div class="card-header">
                <h3>🔤 فونت محیط سایت‌ساز</h3>
                <span class="badge badge-info">فقط فونت‌های نصب‌شده (<?= en_to_fa_digits((string)count($installedFaFonts)) ?> فونت)</span>
            </div>
            <div class="card-body">
                <div class="alert alert-info" style="font-size:12.5px">
                    فونت بخش‌های مختلف پنل مدیریت سایت‌ساز را از بین فونت‌های نصب‌شده انتخاب کنید — تغییرات پس از ذخیره روی همه صفحات پنل اعمال می‌شود و به سایت‌های برند سایز نمی‌زند.
                </div>
                <div class="form-row-3">
                    <div class="form-group">
                        <label>📝 فونت متن پنل (بدنه)</label>
                        <select name="ui_font_body" id="ui-font-body" class="form-control" onchange="previewUiFont()">
                            <option value="">پیش‌فرض (وزیرمتن)</option>
                            <?php foreach ($installedFaFonts as $uiF): ?>
                                <option value="<?= e($uiF['name']) ?>" <?= ($uiFonts['body'] ?? '') === $uiF['name'] ? 'selected' : '' ?>><?= e($uiF['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>🅰️ فونت عنوان‌ها و هدرها</label>
                        <select name="ui_font_heading" id="ui-font-heading" class="form-control" onchange="previewUiFont()">
                            <option value="">پیش‌فرض (همان فونت متن)</option>
                            <?php foreach ($installedFaFonts as $uiF): ?>
                                <option value="<?= e($uiF['name']) ?>" <?= ($uiFonts['heading'] ?? '') === $uiF['name'] ? 'selected' : '' ?>><?= e($uiF['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>💻 فونت اعداد و کدها</label>
                        <select name="ui_font_mono" id="ui-font-mono" class="form-control" onchange="previewUiFont()">
                            <option value="">پیش‌فرض (مونوسیستم)</option>
                            <?php foreach ($installedFaFonts as $uiF): ?>
                                <option value="<?= e($uiF['name']) ?>" <?= ($uiFonts['mono'] ?? '') === $uiF['name'] ? 'selected' : '' ?>><?= e($uiF['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <!-- 👁 پیش‌نمایش زنده -->
                <div class="card" style="margin-top:14px;border:1px dashed var(--border)">
                    <div class="card-header"><h3 style="font-family:var(--font-heading,var(--font))">👁️ پیش‌نمایش زنده — عنوان بخش</h3></div>
                    <div class="card-body">
                        <p style="font-size:13px;line-height:2.2">این متن با فونت بدنه پنل نمایش داده می‌شود — پس از انتخاب فونت از فهرست بالا، همین لحظه نتیجه را اینجا ببینید. تعمیرات تخصصی لوازم خانگی با قطعات اصلی و ضمانت ۶ ماهه، پاسخگویی ۷ روز هفته از ۹ صبح تا ۸ شب.</p>
                        <p style="direction:ltr;text-align:left;font-family:var(--font-mono,monospace);background:var(--bg-secondary,#f1f5f9);padding:8px 12px;border-radius:8px;font-size:12px">api_key = "sk-9f2c...e81a" | port: 2083 | /public_html/brands/lg</p>
                        <button type="button" class="btn btn-primary btn-sm">🔘 دکمه نمونه</button>
                        <span class="badge badge-success">✅ برچسب نمونه</span>
                    </div>
                </div>
                <div class="hint" style="margin-top:10px">💡 فونت جدید از بخش «طراحی ← فونت‌ها» قابل دانلود/نصب است — پس از نصب، در این فهرست ظاهر می‌شود. برای برگشت به حالت پیش‌فرض، «پیش‌فرض» را انتخاب و ذخیره کنید.</div>
            </div>
        </div>
    </div>

    <!-- 🖼️ تب تولید تصویر مقاله (v2.15 — انتخاب سرویس از لیست سرویس‌های رایگان) -->
    <div id="pane-photosvc" class="stab-pane">
        <div class="card">
            <div class="card-header">
                <h3>🖼️ سرویس تولید تصویر مقاله</h3>
                <span class="badge badge-info"><?= en_to_fa_digits((string)count($photoServices)) ?> سرویس</span>
            </div>
            <div class="card-body">
                <div class="alert alert-info" style="font-size:12.5px">
                    سرویسی که تصاویر واقعی مقالات (شاخص + درون‌متن) را می‌سازد از این‌جا انتخاب می‌شود — <b>سرویس انتخابی همیشه اول استفاده می‌شود</b> و اگر موقتاً قطع باشد، سرویس‌های جایگزین رایگان به‌صورت خودکار امتحان می‌شوند. دکمه «تولید مجدد تصاویر مقاله» در ویرایش مقاله با همین سرویس کار می‌کند و نوار پیشرفت زنده دارد.
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>🚀 سرویس تولید تصویر</label>
                        <select name="photo_service" id="photo-service" class="form-control" onchange="togglePhotoKeyFields()">
                            <?php foreach ($photoServices as $svcKey => $svc): ?>
                                <option value="<?= e($svcKey) ?>" <?= $photoSettings['service'] === $svcKey ? 'selected' : '' ?>>
                                    <?= e($svc['label']) ?><?= $svc['needs_key'] ? ($svc['has_key'] ? ' — کلید ثبت شده ✔' : ' — نیازمند کلید') : ' — بدون کلید 🆓' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="hint" id="photo-service-hint" style="margin-top:6px"></div>
                    </div>
                    <div class="form-group">
                        <label>⏱ مهلت هر تولید (ثانیه — ۲۰ تا ۱۲۰)</label>
                        <input type="number" name="photo_timeout" class="form-control" min="20" max="120" value="<?= (int)$photoSettings['timeout'] ?>">
                        <div class="hint" style="margin-top:6px">سرویس‌های رایگان گاهی ۲۰ تا ۴۰ ثانیه برای هر تصویر زمان می‌خواهند — مقدار پیش‌فرض ۴۵ ثانیه است.</div>
                    </div>
                </div>

                <?php foreach ($photoServices as $svcKey => $svc): if (!$svc['needs_key']) { continue; } ?>
                    <div class="form-group photo-key-field" data-service="<?= e($svcKey) ?>" style="display:none">
                        <label>🔑 کلید API «<?= e($svc['label']) ?>»</label>
                        <input type="text" name="photo_key_<?= e($svcKey) ?>" class="form-control" style="direction:ltr;text-align:left" value="<?= e((string)($photoSettings['keys'][$svcKey] ?? '')) ?>" placeholder="<?= e($svc['hint']) ?>">
                        <div class="hint" style="margin-top:6px"><?= e($svc['hint']) ?> — کلید فقط برای همین سرویس استفاده می‌شود؛ اگر خالی بماند سرویس در زنجیره تلاش قرار نمی‌گیرد.</div>
                    </div>
                <?php endforeach; ?>

                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px">
                    <button type="button" id="btn-test-photo-service" class="btn btn-success">🧪 تست سرویس انتخابی</button>
                    <span id="photo-test-result" style="font-size:12.5px"></span>
                </div>
                <div class="hint" style="margin-top:12px">
                    💡 ۱۴ سرویس پشتیبانی می‌شود — رایگان بدون کلید: «پولینیشنز» (Flux/Turbo) و «AI Horde»؛ کلید با پلن رایگان: Hugging Face / DeepAI / Together / fal.ai / Google Imagen (aistudio) / GetImg؛ اشتراکی: Stability / OpenAI (DALL·E 3 و gpt-image-1) / Ideogram / Replicate. سرویس انتخابی همیشه اول تلاش می‌شود و سرویس واقعاً استفاده‌شده در گزارش تولید تصاویر اعلام می‌شود. «تست سرویس» وضعیت اتصال و کلید را با یک درخواست واقعی بررسی می‌کند.
                </div>
            </div>
        </div>
    </div>

    <!-- 🤖 v2.43 → v2.44 (S01+S10) — تب هوش مصنوعی: متد واحد + راهنمای کلید + ترتیب فال‌بک + چت مستقیم -->
    <div id="pane-aitext" class="stab-pane">
        <div class="card">
            <div class="card-header">
                <h3>🤖 موتور هوش مصنوعی متن — تولید مقاله و خطایاب</h3>
                <span class="badge badge-info"><?= en_to_fa_digits((string)count($aiTextProviders)) ?> ارائه‌دهنده</span>
            </div>
            <div class="card-body">
                <div class="alert alert-info" style="font-size:12.5px">
                    <b>🎛 متد واحد (v2.44):</b> تولید مقاله و خطایاب هر دو از «یک» متد انتخابی شما پیروی می‌کنند —
                    <b>🤖 مدل‌های زبانی</b> (کیفیت بالا، رایگان) یا <b>🌐 جستجوی اینترنت</b> (تحقیق وب + نگارش داخلی) یا <b>🔧 دانش داخلی</b> (بدون اینترنت، همیشه موجود).
                    <b>🔄 فال‌بک زنجیره‌ای:</b> در شکست متد/مدل انتخابی، مدل‌های بعدی «به ترتیب دلخواه شما» امتحان می‌شوند و در انتها موتور داخلی پشتیبان است.
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>🚀 متد تولید مقاله و خطایاب (واحد)</label>
                        <select name="ai_text_method" id="ai-text-method" class="form-control" onchange="aiMethodChanged()">
                            <option value="internal" <?= $aiTextSettings['method'] === 'internal' ? 'selected' : '' ?>>🔧 دانش داخلی (پیش‌فرض — بدون نیاز به اینترنت)</option>
                            <option value="llm" <?= $aiTextSettings['method'] === 'llm' ? 'selected' : '' ?>>🤖 مدل‌های زبانی (رایگان — کیفیت بالا)</option>
                            <option value="research" <?= $aiTextSettings['method'] === 'research' ? 'selected' : '' ?>>🌐 جستجوی اینترنت (تحقیق وب + نگارش داخلی)</option>
                        </select>
                        <div class="hint" style="margin-top:5px" id="ai-method-hint"></div>
                    </div>
                    <div class="form-group" id="ai-provider-box">
                        <label>🌐 ارائه‌دهنده مدل زبانی (در متد 🤖)</label>
                        <select name="ai_text_provider" id="ai-text-provider" class="form-control" onchange="toggleAiKeyFields()">
                            <?php $pList = []; foreach ($aiTextProviders as $pr): $pList[$pr['key']] = $pr; endforeach; ?>
                            <?php foreach ($pList as $pk => $pr): ?>
                                <option value="<?= e($pk) ?>" <?= $aiTextSettings['provider'] === $pk ? 'selected' : '' ?>>
                                    <?= e($pr['label']) ?><?= $pr['needs_key'] ? (!empty($aiTextSettings['keys'][$pk]) ? ' — کلید ثبت شده ✔' : ' — نیازمند کلید') : ' — بدون کلید 🆓' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group" id="ai-model-box">
                        <label>🏷 نام مدل (اختیاری — خالی = پیش‌فرض ارائه‌دهنده)</label>
                        <input type="text" name="ai_text_model" class="form-control" style="direction:ltr;text-align:left" value="<?= e($aiTextSettings['model']) ?>" placeholder="مثلاً llama-3.3-70b-versatile">
                    </div>
                    <div class="form-group">
                        <label>⏱ مهلت هر درخواست (ثانیه — ۱۵ تا ۱۸۰)</label>
                        <input type="number" name="ai_text_timeout" class="form-control" min="15" max="180" value="<?= (int)$aiTextSettings['timeout'] ?>">
                    </div>
                </div>
                <label class="form-check" style="margin:10px 0">
                    <input type="checkbox" name="ai_text_fallback" id="ai-fallback-chk" <?= !empty($aiTextSettings['fallback']) ? 'checked' : '' ?> onchange="aiMethodChanged()">
                    🔄 فال‌بک زنجیره‌ای — در شکست هر مدل، مدل‌های بعدی خودکار امتحان شوند
                </label>
                <label class="form-check" style="margin:4px 0 10px">
                    <input type="checkbox" name="ai_fallback_internal" <?= !empty($aiTextSettings['fallback_to_internal']) ? 'checked' : '' ?>>
                    🛡 در پایان زنجیره، موتور داخلی همیشه پاسخ تضمینی بدهد (توصیه‌شده)
                </label>

                <?php foreach ($pList as $pk => $pr): if (!$pr['needs_key']) { continue; } ?>
                    <div class="form-group ai-text-key-field" data-provider="<?= e($pk) ?>" style="display:none">
                        <label>🔑 کلید API «<?= e($pr['label']) ?>»</label>
                        <div style="display:flex;gap:8px;align-items:stretch">
                            <input type="text" name="ai_text_key_<?= e($pk) ?>" class="form-control" style="direction:ltr;text-align:left;flex:1" value="<?= e((string)($aiTextSettings['keys'][$pk] ?? '')) ?>" placeholder="<?= e($pr['hint']) ?>">
                            <button type="button" class="btn btn-outline" style="flex:none;font-size:11.5px" onclick="aiShowGuide('<?= e($pk) ?>')">📖 راهنمای دریافت کلید</button>
                        </div>
                        <div class="hint" style="margin-top:6px"><?= e($pr['hint']) ?> — مدل پیش‌فرض: <code dir="ltr"><?= e($pr['model']) ?></code></div>
                    </div>
                <?php endforeach; ?>
                <div class="form-group ai-text-key-field" data-provider="cloudflare" style="display:none">
                    <label>🆔 Account ID کلادفلر (برای ارائه‌دهنده Cloudflare لازم است)</label>
                    <input type="text" name="ai_cf_account_id" class="form-control" style="direction:ltr;text-align:left" value="<?= e((string)($aiTextSettings['cloudflare_account_id'] ?? '')) ?>" placeholder="32 کاراکتر از داشبورد کلادفلر">
                </div>

                <!-- 🎛 v2.44 (S01) — ویرایشگر ترتیب فال‌بک (جابه‌جایی بالا/پایین) -->
                <div id="ai-fallback-order-box" style="margin-top:12px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px">
                    <b style="font-size:12.5px">🎚 ترتیب فال‌بک دلخواه شما <small style="font-weight:400;color:#64748b">(اولین مورد زودتر امتحان می‌شود — با دکمه‌های ⬆⬇ جابه‌جا کنید)</small></b>
                    <input type="hidden" name="ai_fallback_order" id="ai-fallback-order" value="<?= e(json_encode(array_values($aiTextSettings['fallback_order'] ?: []), JSON_UNESCAPED_UNICODE)) ?>">
                    <div id="ai-fallback-list" style="margin-top:8px;display:flex;flex-direction:column;gap:5px"></div>
                    <div class="hint" style="margin-top:7px">خالی = ترتیب پیش‌فرض (انتخابی ← بدون‌کلید ← بقیه دارای کلید). فقط ارائه‌دهنده‌هایی که کلیدشان ثبت شده یا بدون کلیدند عملاً امتحان می‌شوند.</div>
                </div>

                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px">
                    <button type="button" id="btn-test-ai-text" class="btn btn-success">🧪 تست ارائه‌دهنده انتخابی</button>
                    <span id="ai-text-test-result" style="font-size:12.5px"></span>
                </div>

                <div style="margin-top:14px;padding:11px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px">
                    <b style="font-size:12.5px">🔗 زنجیره فال‌بک فعلی (به‌ترتیب تلاش):</b>
                    <div style="margin-top:7px;font-size:12px;line-height:2.1">
                        <?php foreach ($aiTextChain as $ci => $cp): ?>
                            <span class="badge <?= $cp === $aiTextSettings['provider'] ? 'badge-success' : 'badge-secondary' ?>" style="margin-inline-end:5px;font-size:11px">
                                <?= (int)($ci + 1) ?>. <?= e($pList[$cp]['label'] ?? $cp) ?><?= $cp === $aiTextSettings['provider'] ? ' (انتخابی)' : '' ?>
                            </span>
                        <?php endforeach; ?>
                        <?php if (!empty($aiTextSettings['fallback_to_internal'])): ?>
                            <span class="badge badge-info" style="font-size:11px">آخر: 🔧 موتور داخلی (تضمینی)</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 📖 v2.44 (S01) — راهنمای گام‌به‌گام دریافت کلید همه ارائه‌دهنده‌ها -->
                <details style="margin-top:14px;border:1px solid #e2e8f0;border-radius:12px;padding:0;background:#fff">
                    <summary style="padding:11px 14px;cursor:pointer;font-weight:800;font-size:13px">📖 راهنمای کامل دریافت کلید API — همه <?= en_to_fa_digits((string)count($aiTextProviders)) ?> ارائه‌دهنده (کلیک کنید)</summary>
                    <div style="padding:4px 14px 14px">
                        <?php foreach ($pList as $pk => $pr): $g = $pr['guide'] ?? null; if (!$g) { continue; } ?>
                            <div style="border-right:3px solid <?= $pk === $aiTextSettings['provider'] ? '#16a34a' : '#cbd5e1' ?>;padding:9px 12px;margin-bottom:9px;background:#f8fafc;border-radius:0 10px 10px 0">
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                    <b style="font-size:13px"><?= e($pr['label']) ?></b>
                                    <?php if (!empty($g['free'])): ?><span class="badge badge-success" style="font-size:10px">🆓 رایگان</span><?php else: ?><span class="badge badge-secondary" style="font-size:10px">💵 ارزان</span><?php endif; ?>
                                    <?php if (!$pr['needs_key']): ?><span class="badge badge-info" style="font-size:10px">بدون کلید</span><?php endif; ?>
                                    <a href="<?= e($g['url']) ?>" target="_blank" rel="noopener" style="font-size:11.5px;color:#1d4ed8" dir="ltr"><?= e($g['url']) ?> ↗</a>
                                </div>
                                <ol style="margin:7px 18px 4px 0;padding:0;font-size:12px;line-height:2">
                                    <?php foreach ($g['steps'] as $st): ?><li><?= e($st) ?></li><?php endforeach; ?>
                                </ol>
                                <div style="font-size:11px;color:#64748b">📊 سقف مصرف: <?= e($g['limit']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
                <div class="hint" style="margin-top:12px">
                    💡 پولینیشنز بدون کلید و همیشه در زنجیره حاضر است؛ Z.ai (GLM) با کلید رایگان console.z.ai بهترین کیفیت فارسی را دارد. کلید هر ارائه‌دهنده فقط وقتی خالی نباشد در زنجیره قرار می‌گیرد.
                </div>
            </div>
        </div>

        <!-- 💬 v2.44 (S10) — کادر چت مستقیم با مدل زبانی -->
        <div class="card" style="margin-top:16px" id="ai-chat-card">
            <div class="card-header">
                <h3>💬 چت مستقیم با هوش مصنوعی متن</h3>
                <span class="badge badge-secondary" style="font-size:11px">پرامپت بدهید — مقاله، متن، ایده بگیرید</span>
            </div>
            <div class="card-body">
                <div id="ai-chat-log" style="max-height:340px;overflow-y:auto;display:flex;flex-direction:column;gap:9px;padding:4px;border:1px solid #e2e8f0;border-radius:12px;background:#fafcff;min-height:120px">
                    <div class="ai-chat-empty" style="text-align:center;color:#94a3b8;font-size:12px;padding:26px 10px">
                        🤖 یک پرامپت بنویسید و Enter بزنید — مثلاً «برای سایت تعمیرات یخچال، ۵ ایده مقاله سئوشده پیشنهاد بده»<br>
                        <small>پاسخ از زنجیره فال‌بک همین تنظیمات می‌آید؛ ارائه‌دهنده برنده زیر پیام اعلام می‌شود.</small>
                    </div>
                </div>
                <div style="display:flex;gap:8px;margin-top:10px">
                    <input type="text" id="ai-chat-input" class="form-control" placeholder="پیام یا پرامپت شما..." maxlength="4000" style="flex:1" autocomplete="off">
                    <button type="button" class="btn btn-primary" id="ai-chat-send">📨 ارسال</button>
                    <button type="button" class="btn btn-outline" id="ai-chat-clear" title="پاک کردن گفتگو">🗑</button>
                </div>
                <div class="hint" style="margin-top:8px">🔒 این چت فقط برای مدیر است و در سایت برند نمایش داده نمی‌شود. تاریخچه در همین مرورگر می‌ماند (بدون ذخیره سرور).</div>
            </div>
        </div>
    </div>

    <!-- 🖼 v2.44 (S10) — چت مستقیم تولید تصویر AI -->
    <div id="pane-aichat-img" class="stab-pane">
        <div class="card">
            <div class="card-header">
                <h3>🎨 چت مستقیم تولید تصویر با هوش مصنوعی</h3>
                <span class="badge badge-secondary" style="font-size:11px">پرامپت بدهید — تصویر بسازید و دانلود کنید</span>
            </div>
            <div class="card-body">
                <div class="alert alert-info" style="font-size:12.5px">
                    تصویر با «سرویس تولید تصویر انتخابی همین تنظیمات» ساخته می‌شود (تب تصویر مقاله) — کلید همان‌جا ثبت می‌شود و اینجا استفاده می‌شود.
                </div>
                <div class="form-group">
                    <label>✍️ پرامپت تصویر (فارسی یا انگلیسی)</label>
                    <textarea id="ai-img-prompt" class="form-control" rows="3" maxlength="900" placeholder="مثلاً: تعمیرکار حرفه‌ای در حال بررسی موتور ماشین لباسشویی، نور طبیعی کارگاه، سبک عکس واقعی"></textarea>
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px">
                    <button type="button" class="btn btn-primary" id="ai-img-gen">🎨 تولید تصویر</button>
                    <span id="ai-img-status" style="font-size:12.5px"></span>
                </div>
                <div id="ai-img-result" style="margin-top:14px;display:none;text-align:center">
                    <img id="ai-img-out" alt="تصویر تولیدشده" style="max-width:100%;border-radius:14px;border:1px solid #e2e8f0">
                    <div style="margin-top:9px"><a id="ai-img-dl" class="btn btn-outline btn-sm" download="ai-image.png" href="#">⬇️ دانلود تصویر</a></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 🌍 v2.44 (S11) — فعال/غیرفعال‌سازی چندزبانه -->
    <div id="pane-i18n" class="stab-pane">
        <div class="card">
            <div class="card-header">
                <h3>🌍 چندزبانه بودن سایت‌های برند</h3>
                <span class="badge badge-secondary" style="font-size:11px">فارسی + English</span>
            </div>
            <div class="card-body">
                <div class="alert alert-info" style="font-size:12.5px">
                    <b>پایه چندزبانگی (v2.37):</b> رابط کاربری سایت برند (منو، دکمه‌ها، برچسب‌ها) به انگلیسی هم از مسیر <code dir="ltr">/en/</code> سرو می‌شود + تگ‌های hreflang برای سئو.
                    با خاموش کردن این گزینه، مسیر <code dir="ltr">/en/</code>، سوییچر زبان و hreflang از همه سایت‌های برند حذف می‌شود — سایت تک‌زبانه فارسی می‌ماند.
                </div>
                <label class="form-check" style="margin:10px 0;font-size:13.5px">
                    <input type="checkbox" name="i18n_enabled" <?= empty($i18nSettings['enabled']) ? '' : 'checked' ?>>
                    🌐 فعال بودن چندزبانه (فارسی + انگلیسی) در سایت‌های برند
                </label>
                <div class="hint" style="margin-top:8px">
                    ℹ️ پس از تغییر این گزینه و ذخیره، برای هر برند مستقرشده «بروزرسانی استقرار» را اجرا کنید تا htaccess و فایل‌های جدید زبان به سایت برند منتقل شوند.
                    محتوای اصلی (مقالات و صفحات) فعلاً فارسی است و ترجمه محتوا به‌صورت تدریجی با کلیدهای ترجمه اضافه می‌شود.
                </div>
            </div>
        </div>
    </div>

    <div style="text-align:center;padding:8px 0 20px">
        <button type="submit" class="btn btn-primary btn-lg">💾 ذخیره همه تنظیمات</button>
    </div>
</form>

<!-- 📖 مودال راهنمای کلید (S01) — از AiTextService::KEY_GUIDES -->
<div id="ai-guide-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;align-items:center;justify-content:center;padding:18px" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;border-radius:16px;max-width:560px;width:100%;max-height:84vh;overflow-y:auto;padding:20px 22px;box-shadow:0 24px 70px rgba(0,0,0,.28)">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px">
            <b id="ai-guide-title" style="font-size:15px"></b>
            <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('ai-guide-modal').style.display='none'">✕ بستن</button>
        </div>
        <div id="ai-guide-body" style="font-size:13px;line-height:2.1"></div>
    </div>
</div>

<script>
/* 🔤 v2.12: پیش‌نمایش زنده فونت محیط پنل */
function previewUiFont() {
    var body = document.getElementById('ui-font-body').value;
    var heading = document.getElementById('ui-font-heading').value;
    var mono = document.getElementById('ui-font-mono').value;
    var bodyFont = body ? '"' + body + '", Vazirmatn, Tahoma, sans-serif' : 'Vazirmatn, Tahoma, "Segoe UI", Arial, sans-serif';
    var headFont = heading ? '"' + heading + '", Vazirmatn, Tahoma, sans-serif' : bodyFont;
    var monoFont = mono ? '"' + mono + '", monospace' : 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
    document.documentElement.style.setProperty('--font', bodyFont);
    document.documentElement.style.setProperty('--font-heading', headFont);
    document.documentElement.style.setProperty('--font-mono', monoFont);
}
</script>

<script>
/* 🖼️ v2.15: تب سرویس تولید تصویر مقاله — نمایش/مخفی‌سازی کلید سرویس انتخابی + تست زنده */
(function () {
    'use strict';
    var svcSelect = document.getElementById('photo-service');
    if (!svcSelect) { return; }

    var hints = <?= json_encode(array_combine(array_keys($photoServices), array_column($photoServices, 'hint')), JSON_UNESCAPED_UNICODE) ?: '{}' ?>;
    var needsKey = <?= json_encode(array_combine(array_keys($photoServices), array_column($photoServices, 'needs_key')), JSON_UNESCAPED_UNICODE) ?: '{}' ?>;

    window.togglePhotoKeyFields = function () {
        var svc = svcSelect.value;
        var hintEl = document.getElementById('photo-service-hint');
        if (hintEl && hints[svc]) { hintEl.textContent = 'ℹ️ ' + hints[svc]; }
        document.querySelectorAll('.photo-key-field').forEach(function (f) {
            f.style.display = (f.getAttribute('data-service') === svc && needsKey[svc]) ? '' : 'none';
        });
    };
    togglePhotoKeyFields();

    var testBtn = document.getElementById('btn-test-photo-service');
    var resultEl = document.getElementById('photo-test-result');
    if (testBtn) {
        testBtn.addEventListener('click', function () {
            var csrf = document.querySelector('input[name="csrf_token"]');
            var svc = svcSelect.value;
            var keyInput = document.querySelector('.photo-key-field[data-service="' + svc + '"] input');
            var faDig = function (n) { return String(n).replace(/[0-9]/g, function (x) { return '۰۱۲۳۴۵۶۷۸۹'[+x]; }); };
            testBtn.disabled = true;
            testBtn.textContent = '⏳ در حال تست...';
            resultEl.textContent = 'در حال ساخت یک تصویر نمونه کوچک با سرویس انتخابی...';
            resultEl.style.color = 'var(--text-light)';

            var body = new URLSearchParams();
            body.append('action', 'test_photo_service');
            body.append('service', svc);
            if (keyInput && keyInput.value.trim() !== '') { body.append('key', keyInput.value.trim()); }
            if (csrf) { body.append('csrf_token', csrf.value); }

            fetch('settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: body.toString(),
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).then(function (res) {
                testBtn.disabled = false;
                testBtn.textContent = '🧪 تست سرویس انتخابی';
                if (!res.success) {
                    resultEl.textContent = '❌ ' + (res.error || 'تست ناموفق بود');
                    resultEl.style.color = '#dc2626';
                    return;
                }
                var d = res.data;
                resultEl.style.color = d.ok ? '#16a34a' : '#dc2626';
                resultEl.innerHTML = (d.ok ? '✅ «' + d.label + '» پاسخ داد — ' : '❌ «' + d.label + '» پاسخ نداد — ')
                    + (d.message || '') + (d.latency ? ' (' + faDig(d.latency) + ' میلی‌ثانیه)' : '');
            }).catch(function (err) {
                testBtn.disabled = false;
                testBtn.textContent = '🧪 تست سرویس انتخابی';
                resultEl.textContent = '❌ خطای ارتباط: ' + err.message;
                resultEl.style.color = '#dc2626';
            });
        });
    }
})();
</script>

<script>
/* 🗂️ تب‌های مقاوم تنظیمات — خوداتکا (مستقل از admin.js)، پشتیبانی hash، بازگشت به بالا
   🔧 علت بازنویسی: سیستم قبلی (switchTab سراسری) با کش قدیمی مرورگر یا تداخل کلاس‌ها
   باعث می‌شد تب پایه باز بماند و تب جدید در پایین صفحه ظاهر شود. این سیستم:
   - کلاس‌های اختصاصی stab-* دارد (ضدتداخل)
   - inline است (حتی با کش قدیمی admin.js کار می‌کند)
   - با hash (#notify و...) لینک‌پذیر است و پس از تعویض تب به بالای صفحه برمی‌گردد */
(function () {
    'use strict';
    var bar = document.getElementById('settings-tabs');
    if (!bar) { return; }
    var panes = document.querySelectorAll('.stab-pane');
    function activate(id, scroll) {
        var found = false;
        bar.querySelectorAll('.stab-btn').forEach(function (b) {
            var on = b.getAttribute('data-tab') === id;
            b.classList.toggle('active', on);
            if (on) { found = true; }
        });
        if (!found) { id = 'basic'; activate(id, false); return; }
        panes.forEach(function (p) {
            p.classList.toggle('active', p.id === 'pane-' + id);
        });
        if (location.hash !== '#' + id) {
            try { history.replaceState(null, '', '#' + id); } catch (e) {}
        }
        if (scroll) {
            var top = bar.getBoundingClientRect().top + window.pageYOffset - 70;
            window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
        }
    }
    bar.addEventListener('click', function (e) {
        var b = e.target.closest('.stab-btn');
        if (b) { activate(b.getAttribute('data-tab'), true); }
    });
    var h = (location.hash || '').replace('#', '');
    activate(h && document.getElementById('pane-' + h) ? h : 'basic', false);
    window.addEventListener('hashchange', function () {
        var id = (location.hash || '').replace('#', '');
        if (id && document.getElementById('pane-' + id)) { activate(id, false); }
    });
})();
</script>

<script>
/* 🧩 v2.7: توابع افزودن شعبه/شبکه اجتماعی به admin.js منتقل شدند (addRepeatRow یکپارچه) */
</script>

<?php ?>
<script>
/* 📨 v2.31 — تست ارسال ایمیل با نمایش علت دقیق خطا */
function sahandTestEmail(btn) {
    btn.disabled = true;
    btn.textContent = '⏳ در حال ارسال...';
    fetch('settings.php?test_email=1&to=' + encodeURIComponent(document.querySelector('input[name=email_to]').value || ''), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            btn.disabled = false;
            btn.textContent = '📨 ارسال ایمیل آزمایشی و نمایش خطا';
            if (res && res.success && res.sent) {
                sahandAlert({ message: '✅ ایمیل آزمایشی ارسال شد! صندوق ورودی (و پوشه اسپم) مقصد را بررسی کنید.', title: 'تست ایمیل', type: 'success' });
            } else {
                sahandAlert({ message: '❌ ارسال ناموفق:\n' + ((res && res.error) || 'خطای نامشخص') + '\n\n💡 راهنما: فیلد «ایمیل فرستنده» را با یک ایمیل روی دامنه میزبان پر کنید یا SMTP را فعال کنید.', title: 'تست ایمیل', type: 'danger' });
            }
        })
        .catch(function () {
            btn.disabled = false;
            btn.textContent = '📨 ارسال ایمیل آزمایشی و نمایش خطا';
            sahandAlert({ message: 'خطای ارتباط با سرور', type: 'danger' });
        });
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>

<script>
/* 🤖 v2.43 (S09+S16) — تب هوش مصنوعی: نمایش کلید ارائه‌دهنده انتخابی + تست زنده */
(function () {
    'use strict';
    function toggleAiKeyFields() {
        var sel = document.getElementById('ai-text-provider');
        if (!sel) { return; }
        var p = sel.value;
        document.querySelectorAll('.ai-text-key-field').forEach(function (el) {
            el.style.display = (el.getAttribute('data-provider') === p) ? '' : 'none';
        });
    }
    window.toggleAiKeyFields = toggleAiKeyFields;
    toggleAiKeyFields();

    var btn = document.getElementById('btn-test-ai-text');
    if (!btn) { return; }
    btn.addEventListener('click', function () {
        var sel = document.getElementById('ai-text-provider');
        var p = sel ? sel.value : '';
        var keyField = document.querySelector('.ai-text-key-field[data-provider="' + p + '"] input');
        var key = keyField ? keyField.value.trim() : '';
        var csrf = document.querySelector('input[name="csrf_token"]');
        var out = document.getElementById('ai-text-test-result');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span> در حال تست...';
        out.textContent = '';
        var fd = new FormData();
        fd.append('action', 'test_ai_text');
        fd.append('provider', p);
        if (key !== '') { fd.append('key', key); }
        if (csrf) { fd.append('csrf_token', csrf.value); }
        fetch('settings.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                btn.disabled = false;
                btn.innerHTML = '🧪 تست ارائه‌دهنده انتخابی';
                var d = j && j.data ? j.data : { ok: false, message: j && j.error ? j.error : 'خطای نامشخص' };
                out.textContent = d.message || (d.ok ? '✅ موفق' : '❌ ناموفق');
                out.style.color = d.ok ? '#059669' : '#dc2626';
            })
            .catch(function () {
                btn.disabled = false;
                btn.innerHTML = '🧪 تست ارائه‌دهنده انتخابی';
                out.textContent = '❌ خطای ارتباط — دوباره تلاش کنید';
                out.style.color = '#dc2626';
            });
    });
})();
</script>
<script>
/* 🎛 v2.44 (S01) — متد واحد + ویرایشگر ترتیب فال‌بک + مودال راهنمای کلید + چت AI (S10) */
(function () {
    'use strict';

    /* داده ارائه‌دهنده‌ها از سرور */
    var AI_PROVIDERS = <?= json_encode($aiTextProviders, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
    var AI_SEL = <?= json_encode((string)$aiTextSettings['provider']) ?>;
    var pByKey = {};
    AI_PROVIDERS.forEach(function (p) { pByKey[p.key] = p; });

    /* ── ① نمایش/مخفی بر اساس متد ── */
    function aiMethodChanged() {
        var m = document.getElementById('ai-text-method');
        var hint = document.getElementById('ai-method-hint');
        var provBox = document.getElementById('ai-provider-box');
        var modelBox = document.getElementById('ai-model-box');
        var orderBox = document.getElementById('ai-fallback-order-box');
        var fbChk = document.getElementById('ai-fallback-chk');
        if (!m) { return; }
        var isLlm = m.value === 'llm';
        var hints = {
            internal: '🔧 تولید فقط از پایگاه دانش داخلی خود سایت‌ساز — بدون اینترنت، بدون کلید، همیشه موجود. (سریع‌ترین حالت)',
            llm: '🤖 مقاله و کد خطا با مدل زبانی رایگان نوشته می‌شود — کیفیت بالاتر. اگر مدل جواب ندهد، زنجیره فال‌بک فعال می‌شود.',
            research: '🌐 ابتدا از اینترنت تحقیق می‌شود و سپس مقاله/خطا با موتور داخلیِ تغذیه‌شده از نتایج واقعی وب نوشته می‌شود — دقیق‌ترین برای موضوعات تازه.'
        };
        if (hint) { hint.textContent = hints[m.value] || ''; }
        if (provBox) { provBox.style.opacity = isLlm ? '1' : '.45'; }
        if (modelBox) { modelBox.style.opacity = isLlm ? '1' : '.45'; }
        if (orderBox) { orderBox.style.display = (isLlm && fbChk && fbChk.checked) ? '' : 'none'; }
        if (fbChk) { fbChk.parentElement.style.opacity = isLlm ? '1' : '.5'; }
    }
    window.aiMethodChanged = aiMethodChanged;
    aiMethodChanged();

    /* ── ② ویرایشگر ترتیب فال‌بک ── */
    var orderInput = document.getElementById('ai-fallback-order');
    var orderList = document.getElementById('ai-fallback-list');
    function orderGet() {
        try { return JSON.parse(orderInput.value || '[]'); } catch (e) { return []; }
    }
    function orderSet(arr) {
        orderInput.value = JSON.stringify(arr);
        renderOrder();
    }
    function renderOrder() {
        if (!orderList) { return; }
        var arr = orderGet();
        orderList.innerHTML = '';
        if (!arr.length) {
            orderList.innerHTML = '<div style="font-size:11.5px;color:#94a3b8;padding:4px 2px">ترتیب دلخواهی ثبت نشده — ترتیب پیش‌فرض استفاده می‌شود. برای شخصی‌سازی، دکمه «+ افزودن» را بزنید.</div>';
            return;
        }
        arr.forEach(function (key, i) {
            var p = pByKey[key] || { label: key };
            var row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:7px;background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:6px 9px;font-size:12px';
            row.innerHTML =
                '<b style="min-width:18px;text-align:center;color:#64748b">' + (i + 1) + '.</b>' +
                '<span style="flex:1">' + (p.label || key) + (key === AI_SEL ? ' <span class="badge badge-success" style="font-size:9.5px">انتخابی</span>' : '') + '</span>' +
                '<button type="button" class="btn btn-outline" style="padding:2px 8px;font-size:11px" data-mv="up" title="بالا">⬆</button>' +
                '<button type="button" class="btn btn-outline" style="padding:2px 8px;font-size:11px" data-mv="down" title="پایین">⬇</button>' +
                '<button type="button" class="btn btn-outline" style="padding:2px 8px;font-size:11px;color:#dc2626" data-mv="del" title="حذف">✕</button>';
            row.querySelectorAll('button').forEach(function (b) {
                b.addEventListener('click', function () {
                    var mv = b.getAttribute('data-mv');
                    var a = orderGet();
                    if (mv === 'up' && i > 0) { var t = a[i - 1]; a[i - 1] = a[i]; a[i] = t; }
                    if (mv === 'down' && i < a.length - 1) { var t2 = a[i + 1]; a[i + 1] = a[i]; a[i] = t2; }
                    if (mv === 'del') { a.splice(i, 1); }
                    orderSet(a);
                });
            });
            orderList.appendChild(row);
        });
    }
    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'btn btn-outline';
    addBtn.style.cssText = 'margin-top:7px;font-size:11.5px;padding:4px 12px';
    addBtn.textContent = '+ افزودن ارائه‌دهنده به ترتیب';
    addBtn.addEventListener('click', function () {
        var opts = AI_PROVIDERS.filter(function (p) { return orderGet().indexOf(p.key) === -1; });
        if (!opts.length) { return; }
        var html = '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">';
        opts.forEach(function (p) {
            html += '<button type="button" class="btn btn-outline" style="font-size:11px;padding:4px 10px" data-add="' + p.key + '">' + p.label + (p.needs_key ? ' 🔑' : ' 🆓') + '</button>';
        });
        html += '</div>';
        var dv = document.createElement('div');
        dv.innerHTML = html;
        dv.querySelectorAll('button[data-add]').forEach(function (b) {
            b.addEventListener('click', function () {
                var a = orderGet();
                a.push(b.getAttribute('data-add'));
                orderSet(a);
            });
        });
        orderList.appendChild(dv);
    });
    if (orderList) {
        orderList.parentNode.appendChild(addBtn);
        renderOrder();
    }

    /* ── ③ مودال راهنمای کلید ── */
    window.aiShowGuide = function (key) {
        var p = pByKey[key];
        var g = p && p.guide;
        var modal = document.getElementById('ai-guide-modal');
        if (!g || !modal) { return; }
        document.getElementById('ai-guide-title').innerHTML = '📖 کلید API ' + (p.label || key) +
            (g.free ? ' <span class="badge badge-success" style="font-size:10px">🆓 رایگان</span>' : ' <span class="badge badge-secondary" style="font-size:10px">💵 ارزان</span>');
        var steps = g.steps.map(function (s, i) { return '<div style="display:flex;gap:9px;margin-bottom:8px"><span style="flex:none;width:22px;height:22px;border-radius:7px;background:#1e40af;color:#fff;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center">' + (i + 1) + '</span><span>' + s.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</span></div>'; }).join('');
        document.getElementById('ai-guide-body').innerHTML =
            '<a href="' + g.url + '" target="_blank" rel="noopener" style="display:inline-block;direction:ltr;font-size:12.5px;color:#1d4ed8;margin-bottom:12px">' + g.url + ' ↗</a>' +
            steps +
            '<div style="margin-top:10px;padding:8px 12px;background:#f1f5f9;border-radius:9px;font-size:12px">📊 سقف مصرف: ' + g.limit + '</div>' +
            '<div style="margin-top:9px;font-size:11.5px;color:#64748b">پس از ساخت کلید، آن را در فیلد همین صفحه بچسبانید و «ذخیره همه تنظیمات» را بزنید.</div>';
        modal.style.display = 'flex';
    };

    /* ── ④ چت مستقیم AI متن (S10) ── */
    var chatLog = document.getElementById('ai-chat-log');
    var chatInput = document.getElementById('ai-chat-input');
    var chatSend = document.getElementById('ai-chat-send');
    var chatClear = document.getElementById('ai-chat-clear');
    var chatHistory = [];
    function chatBubble(role, html, small) {
        var d = document.createElement('div');
        var isUser = role === 'user';
        d.style.cssText = 'max-width:86%;align-self:' + (isUser ? 'flex-start' : 'flex-end') + ';background:' + (isUser ? '#e0edff' : '#fff') + ';border:1px solid ' + (isUser ? '#bcd6ff' : '#e2e8f0') + ';border-radius:12px;padding:9px 13px;font-size:12.5px;line-height:1.9;white-space:pre-wrap;word-break:break-word';
        d.textContent = html;
        if (small) {
            var s = document.createElement('div');
            s.style.cssText = 'font-size:10px;color:#94a3b8;margin-top:5px';
            s.textContent = small;
            d.appendChild(s);
        }
        return d;
    }
    function chatSendMsg() {
        var txt = chatInput.value.trim();
        if (!txt || !chatSend) { return; }
        var empty = chatLog.querySelector('.ai-chat-empty');
        if (empty) { empty.remove(); }
        chatLog.appendChild(chatBubble('user', txt));
        chatInput.value = '';
        chatSend.disabled = true;
        var typing = chatBubble('assistant', '...');
        typing.style.opacity = '.55';
        chatLog.appendChild(typing);
        chatLog.scrollTop = chatLog.scrollHeight;
        var csrf = document.querySelector('input[name="csrf_token"]');
        var fd = new FormData();
        fd.append('action', 'ai_chat');
        fd.append('message', txt);
        fd.append('history', JSON.stringify(chatHistory.slice(-6)));
        if (csrf) { fd.append('csrf_token', csrf.value); }
        fetch('settings.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                typing.remove();
                chatSend.disabled = false;
                if (j && j.success) {
                    chatHistory.push({ role: 'user', content: txt });
                    chatHistory.push({ role: 'assistant', content: j.data.text });
                    chatLog.appendChild(chatBubble('assistant', j.data.text, '🤖 ' + (j.data.provider_label || j.data.provider)));
                } else {
                    chatLog.appendChild(chatBubble('assistant', '❌ ' + ((j && j.error) || 'خطای نامشخص')));
                }
                chatLog.scrollTop = chatLog.scrollHeight;
            })
            .catch(function () {
                typing.remove();
                chatSend.disabled = false;
                chatLog.appendChild(chatBubble('assistant', '❌ خطای ارتباط با سرور'));
                chatLog.scrollTop = chatLog.scrollHeight;
            });
    }
    if (chatSend && chatInput) {
        chatSend.addEventListener('click', chatSendMsg);
        chatInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); chatSendMsg(); } });
    }
    if (chatClear && chatLog) {
        chatClear.addEventListener('click', function () {
            chatHistory = [];
            chatLog.innerHTML = '<div class="ai-chat-empty" style="text-align:center;color:#94a3b8;font-size:12px;padding:26px 10px">🤖 گفتگو پاک شد — پرامپت جدیدی بنویسید.</div>';
        });
    }

    /* ── ⑤ چت تصویرساز (S10) ── */
    var imgGen = document.getElementById('ai-img-gen');
    var imgPrompt = document.getElementById('ai-img-prompt');
    var imgStatus = document.getElementById('ai-img-status');
    var imgResult = document.getElementById('ai-img-result');
    var imgOut = document.getElementById('ai-img-out');
    var imgDl = document.getElementById('ai-img-dl');
    if (imgGen) {
        imgGen.addEventListener('click', function () {
            var p = imgPrompt.value.trim();
            if (p.length < 3) { imgStatus.textContent = 'پرامپت را کامل‌تر بنویسید.'; imgStatus.style.color = '#dc2626'; return; }
            imgGen.disabled = true;
            imgGen.textContent = '⏳ در حال ساخت...';
            imgStatus.textContent = 'پرامپت به سرویس تصویر ارسال شد — رایگان‌ها ۲۰ تا ۴۵ ثانیه طول می‌کشند...';
            imgStatus.style.color = 'var(--text-light)';
            var csrf = document.querySelector('input[name="csrf_token"]');
            var fd = new FormData();
            fd.append('action', 'ai_image_chat');
            fd.append('prompt', p);
            if (csrf) { fd.append('csrf_token', csrf.value); }
            fetch('settings.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    imgGen.disabled = false;
                    imgGen.textContent = '🎨 تولید تصویر';
                    if (j && j.success) {
                        imgOut.src = j.data.url;
                        imgDl.href = j.data.url;
                        imgResult.style.display = 'block';
                        imgStatus.textContent = '✅ ساخته شد با «' + (j.data.service_label || j.data.service) + '»';
                        imgStatus.style.color = '#16a34a';
                    } else {
                        imgStatus.textContent = '❌ ' + ((j && j.error) || 'خطای نامشخص');
                        imgStatus.style.color = '#dc2626';
                    }
                })
                .catch(function () {
                    imgGen.disabled = false;
                    imgGen.textContent = '🎨 تولید تصویر';
                    imgStatus.textContent = '❌ خطای ارتباط با سرور';
                    imgStatus.style.color = '#dc2626';
                });
        });
    }
})();
</script>
