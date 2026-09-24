<?php
/**
 * 🤖 مدیریت ربات تلگرام دستیار
 * ==============================
 * راه‌اندازی کامل ربات متصل به دستیار فارسی موتور سهند:
 *   • ذخیره توکن (@BotFather) و شناسه‌های مجاز
 *   • تست اتصال (getMe)
 *   • راه‌اندازی/حذف وب‌هوک با توکن مخفی خودکار
 *   • ارسال پیام آزمایشی
 *   • وضعیت اتصال و راهنمای گام‌به‌گام
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/telegram/TelegramBot.php';

$pageTitle = 'ربات تلگرام دستیار';
$activeMenu = 'telegram';
require __DIR__ . '/includes/header.php';

/* --------------------------------------------------
 * ⚙️ پردازش اکشن‌ها
 * -------------------------------------------------- */
$flashType = '';
$flashMsg = '';
$webhookInfo = null;
$me = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::enforceCsrf();
    $action = $_POST['action'] ?? '';
    $cfg = (array)(Config::get('telegram_bot_settings') ?: []);

    try {
        switch ($action) {

            /* 💾 ذخیره تنظیمات — v2.7: دو فرم مستقل با scope جدا
               (باگ قبلی: ذخیره فرم ربات، فیلدهای واسط گوگل را خالی می‌کرد و بالعکس) */
            case 'save_settings':
                $scope = (string)($_POST['scope'] ?? 'all');
                if ($scope === 'bot' || $scope === 'all') {
                    $cfg['enabled']           = !empty($_POST['tg_enabled']);
                    $cfg['bot_token']         = trim((string)($_POST['tg_token'] ?? $cfg['bot_token'] ?? ''));
                    $cfg['allowed_chat_ids']  = trim((string)($_POST['tg_chats'] ?? $cfg['allowed_chat_ids'] ?? ''));
                    $cfg['notify_new_request']= !empty($_POST['tg_notify']);
                    $cfg['welcome_text']      = trim((string)($_POST['tg_welcome'] ?? $cfg['welcome_text'] ?? ''));
                }
                if ($scope === 'relay' || $scope === 'all') {
                    // 🌉 تنظیمات واسط Google Apps Script
                    $cfg['relay_enabled']     = !empty($_POST['tg_relay_enabled']);
                    $cfg['relay_url']         = trim((string)($_POST['tg_relay_url'] ?? $cfg['relay_url'] ?? ''));
                    $cfg['relay_secret']      = trim((string)($_POST['tg_relay_secret'] ?? $cfg['relay_secret'] ?? ''));
                }
                // اعتبارسنجی ساده توکن
                if (($cfg['bot_token'] ?? '') !== '' && !preg_match('#^\d+:[\w-]{30,}$#', $cfg['bot_token'])) {
                    throw new RuntimeException('قالب توکن معتبر نیست — از @BotFather کپی کنید (مثال: 123456789:AAH...)');
                }
                // اعتبارسنجی شناسه‌ها (اعداد جدا با کاما یا *)
                if (($cfg['allowed_chat_ids'] ?? '') !== '' && $cfg['allowed_chat_ids'] !== '*') {
                    $ids = array_filter(array_map('trim', explode(',', $cfg['allowed_chat_ids'])));
                    foreach ($ids as $id) {
                        if (!preg_match('#^-?\d+$#', $id)) {
                            throw new RuntimeException("شناسه چت نامعتبر: {$id} — شناسه‌ها باید عددی باشند (با /id از ربات بگیرید)");
                        }
                    }
                }
                Config::set('telegram_bot_settings', $cfg);
                Logger::activity((int)$_SESSION['user_id'], 'تنظیمات ربات تلگرام', 'ذخیره تنظیمات ربات دستیار تلگرام (بخش: ' . $scope . ')');
                $flashType = 'success';
                $flashMsg = $scope === 'relay' ? '✅ تنظیمات واسط گوگل ذخیره شد (تنظیمات ربات دست‌نخورده ماند).' : '✅ تنظیمات ربات ذخیره شد.';
                break;

            /* 🔌 تست اتصال */
            case 'test_connection':
                $token = trim((string)($_POST['tg_token'] ?? $cfg['bot_token'] ?? ''));
                if ($token === '') {
                    throw new RuntimeException('ابتدا توکن ربات را وارد کنید.');
                }
                $bot = new TelegramBot($token, $cfg);
                $me = $bot->getMe();
                $flashType = 'success';
                $flashMsg = '✅ اتصال موفق — @' . ($me['username'] ?? '?') . ' («' . ($me['first_name'] ?? '') . '») شناسه: ' . ($me['id'] ?? '?');
                break;

            /* 🪝 راه‌اندازی وب‌هوک */
            case 'set_webhook':
                $token = trim((string)($_POST['tg_token'] ?? $cfg['bot_token'] ?? ''));
                if ($token === '') {
                    throw new RuntimeException('ابتدا توکن ربات را وارد و ذخیره کنید.');
                }
                $publicUrl = BASE_URL . '/api/telegram/webhook';
                if (strpos($publicUrl, 'https://') !== 0) {
                    throw new RuntimeException('وب‌هوک تلگرام به HTTPS نیاز دارد — آدرس سایت ساز: ' . $publicUrl . ' (برای تست محلی از telegram/poll.php استفاده کنید)');
                }
                $bot = new TelegramBot($token, $cfg);
                $secret = TelegramBot::ensureWebhookSecret();
                $bot->setWebhook($publicUrl, $secret);
                Logger::activity((int)$_SESSION['user_id'], 'راه‌اندازی وب‌هوک', 'وب‌هوک ربات تلگرام فعال شد: ' . $publicUrl);
                $flashType = 'success';
                $flashMsg = '✅ وب‌هوک فعال شد: ' . $publicUrl;
                break;

            /* 🌉 تست واسط گوگل (v2.6) */
            case 'test_relay':
                $relayUrl = trim((string)($_POST['tg_relay_url'] ?? $cfg['relay_url'] ?? ''));
                if ($relayUrl === '' || !preg_match('#^https://script\.google\.com/#', $relayUrl)) {
                    throw new RuntimeException('آدرس Web App واسط گوگل معتبر نیست — باید با https://script.google.com/ شروع شود');
                }
                $ch = curl_init($relayUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode([
                        'secret' => (string)($cfg['relay_secret'] ?? ''),
                        'method' => 'getMe',
                        'token'  => (string)($cfg['bot_token'] ?? ''),
                        'params' => new stdClass(),
                    ]),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 30,
                ]);
                $body = curl_exec($ch);
                $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $data = is_string($body) ? json_decode($body, true) : null;
                if (!is_array($data)) {
                    throw new RuntimeException('پاسخ نامعتبر از واسط (HTTP ' . $http . ') — اسکریپت را با دسترسی «Anyone» Deploy کرده‌اید؟');
                }
                if (!empty($data['relay_error'])) {
                    throw new RuntimeException('واسط پاسخ داد اما تلگرام خطا داد: ' . $data['relay_error']);
                }
                if (empty($data['ok'])) {
                    throw new RuntimeException('واسط کار می‌کند اما توکن رد شد: ' . ($data['description'] ?? '?'));
                }
                $me = $data['result'] ?? [];
                $flashType = 'success';
                $flashMsg = '🌉 واسط گوگل سالم است — ربات شناسایی شد: @' . ($me['username'] ?? '?');

                /* 🔎 v2.8: پروب پشتیبانی فایل — اگر اسکریپت قدیمی باشد، ارسال سند
                   مقاله با خطای «there is no document» شکست می‌خورد؛ همین‌جا به‌موقع
                   هشدار می‌دهیم تا کاربر اسکریپت را بروزرسانی کند. */
                try {
                    $ch2 = curl_init($relayUrl);
                    curl_setopt_array($ch2, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode([
                            'secret' => (string)($cfg['relay_secret'] ?? ''),
                            'method' => 'getRelayCapabilities',
                            'token'  => (string)($cfg['bot_token'] ?? ''),
                            'params' => new stdClass(),
                        ]),
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_TIMEOUT => 20,
                    ]);
                    $body2 = curl_exec($ch2);
                    curl_close($ch2);
                    $cap = is_string($body2) ? json_decode($body2, true) : null;
                    if (is_array($cap) && empty($cap['result']['supports_files'])) {
                        $flashType = 'warning';
                        $flashMsg .= ' — ⚠️ نسخه اسکریپت واسط «قدیمی» است و ارسال فایل (سند مقاله) را پشتیبانی نمی‌کند! کد جدید را از کادر زیر کپی و مجدداً Deploy کنید.';
                    } elseif (is_array($cap) && !empty($cap['result']['supports_files'])) {
                        $flashMsg .= ' — ✅ ارسال فایل پشتیبانی می‌شود (نسخه ' . (int)($cap['result']['relay_version'] ?? 2) . ').';
                    }
                } catch (Throwable $capE) {
                    // پروب اختیاری است — تست اصلی موفق بوده
                }
                break;

            /* 🧹 حذف وب‌هوک */
            case 'delete_webhook':
                $token = trim((string)($_POST['tg_token'] ?? $cfg['bot_token'] ?? ''));
                if ($token === '') {
                    throw new RuntimeException('ابتدا توکن ربات را وارد کنید.');
                }
                $bot = new TelegramBot($token, $cfg);
                $bot->deleteWebhook();
                $flashType = 'success';
                $flashMsg = '🧹 وب‌هوک حذف شد (حالت polling آزاد شد).';
                break;

            /* ✉️ پیام آزمایشی */
            case 'send_test':
                $token = trim((string)($_POST['tg_token'] ?? $cfg['bot_token'] ?? ''));
                $chatId = trim((string)($_POST['test_chat_id'] ?? ''));
                if ($token === '' || $chatId === '') {
                    throw new RuntimeException('توکن ربات و شناسه چت مقصد هر دو الزامی هستند.');
                }
                $bot = new TelegramBot($token, $cfg);
                $bot->sendMessage($chatId,
                    "🧪 <b>پیام آزمایشی ربات سهند</b>\n\n" .
                    'این یک پیام تست از سایت ساز است. ✅' . "\n\n" .
                    '🤖 موتور: ' . SahandAI::ENGINE_VERSION . ' | 🏗️ سیستم: ' . SAHAND_VERSION
                );
                $flashType = 'success';
                $flashMsg = '✅ پیام آزمایشی به ' . $chatId . ' ارسال شد.';
                break;
        }
    } catch (Throwable $e) {
        $flashType = 'danger';
        $flashMsg = '❌ ' . $e->getMessage();
    }
}

/* --------------------------------------------------
 * 📥 بارگذاری وضعیت فعلی
 * -------------------------------------------------- */
$cfg = (array)(Config::get('telegram_bot_settings') ?: []);
$token = (string)($cfg['bot_token'] ?? '');
$chats = (string)($cfg['allowed_chat_ids'] ?? '');
$enabled = !empty($cfg['enabled']);
$notify = !empty($cfg['notify_new_request']) || !array_key_exists('notify_new_request', $cfg);
$welcome = (string)($cfg['welcome_text'] ?? '');
$hasSecret = !empty($cfg['webhook_secret']);
$webhookUrl = BASE_URL . '/api/telegram/webhook';

/* 📡 وضعیت وب‌هوک (در صورت وجود توکن) */
$webhookStatus = null;
if ($token !== '') {
    try {
        $bot = new TelegramBot($token, $cfg);
        $webhookInfo = $bot->getWebhookInfo();
        $webhookStatus = [
            'url' => (string)($webhookInfo['url'] ?? ''),
            'pending' => (int)($webhookInfo['pending_update_count'] ?? 0),
            'last_error' => (string)($webhookInfo['last_error_message'] ?? ''),
        ];
    } catch (Throwable $e) {
        $webhookStatus = ['url' => '', 'pending' => 0, 'last_error' => 'اتصال ناموفق: ' . $e->getMessage()];
    }
}

/* 🔢 آمار گفتگو با ربات */
$botStats = ['updates' => 0];
try {
    $logs = glob(LOGS_PATH . '/telegram-*.log');
    $botStats['logs'] = count($logs ?: []);
} catch (Throwable $e) {
}
?>

<?php if ($flashMsg): ?>
    <div class="alert alert-<?= e($flashType) ?>" style="margin-bottom:16px"><?= e($flashMsg) ?></div>
<?php endif; ?>

<form method="post" id="tg-form">
    <?= Auth::csrfField() ?>

    <!-- ⚙️ بخش ۱: تنظیمات اصلی -->
    <div class="card" style="margin-bottom:18px">
        <div class="card-header"><h3>⚙️ تنظیمات ربات دستیار</h3></div>
        <div class="card-body">
            <input type="hidden" name="action" value="save_settings" id="tg-action">
            <input type="hidden" name="scope" value="bot">

            <div class="form-row">
                <div class="form-group">
                    <label>🤖 وضعیت ربات</label>
                    <label class="switch">
                        <input type="checkbox" name="tg_enabled" <?= $enabled ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    <small class="hint">وقتی خاموش است، ربات به پیام‌ها پاسخ «غیرفعال» می‌دهد.</small>
                </div>
                <div class="form-group">
                    <label>📨 اعلان درخواست جدید به چت‌های مجاز</label>
                    <label class="switch">
                        <input type="checkbox" name="tg_notify" <?= $notify ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                    <small class="hint">ثبت درخواست خدمات در سایت‌های برند → اعلان به ربات.</small>
                </div>
            </div>

            <div class="form-group">
                <label>🔑 توکن ربات (از @BotFather)</label>
                <input type="text" name="tg_token" class="form-control" style="direction:ltr;text-align:left"
                       placeholder="123456789:AAHcqT2bF..." value="<?= e($token) ?>">
                <small class="hint">در تلگرام به <b>@BotFather</b> بروید → /newbot → توکن را اینجا کپی کنید.</small>
            </div>

            <div class="form-group">
                <label>🆔 شناسه‌های چت مجاز (با کاما جدا کنید)</label>
                <input type="text" name="tg_chats" class="form-control" style="direction:ltr;text-align:left"
                       placeholder="123456789, 987654321 یا * برای همه"
                       value="<?= e($chats) ?>">
                <small class="hint">🔒 برای گرفتن شناسه، به ربات خود <b>/id</b> بفرستید. لیست خالی = هیچ‌کس مجاز نیست. <code>*</code> = عمومی (امنیت کمتر).</small>
            </div>

            <div class="form-group">
                <label>👋 متن خوش‌آمدگویی /start</label>
                <textarea name="tg_welcome" class="form-control" rows="2"
                          placeholder="سلام! من دستیار هوشمند سهند سرویس هستم..."><?= e($welcome) ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary" onclick="document.getElementById('tg-action').value='save_settings'">💾 ذخیره تنظیمات</button>
        </div>
    </div>
</form>

<!-- 🔌 بخش ۲: ابزارهای اتصال -->
<div class="card" style="margin-bottom:18px">
    <div class="card-header"><h3>🔌 ابزارهای اتصال و راه‌اندازی</h3></div>
    <div class="card-body">
        <div class="alert alert-info" style="background:#eff6ff;border-color:#bfdbfe;color:#1e40af;font-size:13px;line-height:2">
            <b>📋 راهنمای گام‌به‌گام راه‌اندازی:</b><br>
            ۱️⃣ در تلگرام به <b>@BotFather</b> بروید و با <code>/newbot</code> ربات بسازید<br>
            ۲️⃣ توکن را در فرم بالا وارد کرده و <b>ذخیره</b> کنید<br>
            ۳️⃣ با دکمه <b>تست اتصال</b> صحت توکن را بررسی کنید<br>
            ۴️⃣ در تلگرام به ربات خود پیام <code>/start</code> و سپس <code>/id</code> بدهید و شناسه را در فرم بالا مجاز کنید<br>
            ۵️⃣ دکمه <b>راه‌اندازی وب‌هوک</b> را بزنید (به HTTPS نیاز دارد) — یا برای تست محلی از <code>php telegram/poll.php</code> استفاده کنید<br>
            ۶️⃣ تمام! حالا در تلگرام فارسی بنویسید: «کلمات کلیدی یخچال» 🎉
        </div>

        <form method="post" style="display:inline">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="test_connection">
            <input type="hidden" name="tg_token" value="<?= e($token) ?>">
            <button type="submit" class="btn btn-outline" <?= $token === '' ? 'disabled' : '' ?>>🔌 تست اتصال (getMe)</button>
        </form>

        <form method="post" style="display:inline">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="set_webhook">
            <input type="hidden" name="tg_token" value="<?= e($token) ?>">
            <button type="submit" class="btn btn-success" <?= $token === '' ? 'disabled' : '' ?>>🪝 راه‌اندازی وب‌هوک</button>
        </form>

        <form method="post" style="display:inline">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="delete_webhook">
            <input type="hidden" name="tg_token" value="<?= e($token) ?>">
            <button type="submit" class="btn btn-outline" <?= $token === '' ? 'disabled' : '' ?>>🧹 حذف وب‌هوک</button>
        </form>

        <?php if ($webhookStatus): ?>
            <div style="margin-top:16px;padding:12px 16px;border:1px solid var(--border);border-radius:9px;font-size:13px;background:#f8fafc">
                <b>📡 وضعیت وب‌هوک تلگرام:</b>
                <?php if ($webhookStatus['url'] !== ''): ?>
                    ✅ فعال روی <code style="direction:ltr;display:inline-block"><?= e($webhookStatus['url']) ?></code>
                    <?php if (strpos($webhookUrl, $webhookStatus['url']) === false): ?>
                        ⚠️ (با آدرس فعلی سایت ساز یکسان نیست!)
                    <?php endif; ?>
                    — در انتظار: <?= (int)$webhookStatus['pending'] ?> آپدیت
                    <?php if ($webhookStatus['last_error'] !== ''): ?>
                        <br><span style="color:#dc2626">آخرین خطا: <?= e($webhookStatus['last_error']) ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    ⬜ وب‌هوکی تنظیم نشده — حالت polling آزاد است
                <?php endif; ?>
                <br>
                🪙 توکن مخفی: <?= $hasSecret ? '✅ موجود' : '⬜ ساخته نشده (هنگام راه‌اندازی وب‌هوک ساخته می‌شود)' ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ✉️ بخش ۳: پیام آزمایشی -->
<div class="card" style="margin-bottom:18px">
    <div class="card-header"><h3>✉️ ارسال پیام آزمایشی</h3></div>
    <div class="card-body">
        <form method="post">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="send_test">
            <input type="hidden" name="tg_token" value="<?= e($token) ?>">
            <div class="form-row">
                <div class="form-group">
                    <label>🆔 شناسه چت مقصد</label>
                    <input type="text" name="test_chat_id" class="form-control" style="direction:ltr;text-align:left"
                           placeholder="123456789" value="<?= e(explode(',', $chats)[0] ?: '') ?>">
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end">
                    <button type="submit" class="btn btn-primary" <?= $token === '' ? 'disabled' : '' ?>>📤 ارسال تست</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- 💬 بخش ۴: قابلیت‌های ربات -->
<div class="card">
    <div class="card-header"><h3>💬 ربات چه کارهایی می‌فهمد؟</h3></div>
    <div class="card-body" style="font-size:13.5px;line-height:2.2">
        <table class="table">
            <tr><th>فرمان نمونه (فارسی بنویسید)</th><th>عملیات</th></tr>
            <tr><td>«مقاله بنویس برای پاکشما درباره ماشین لباسشویی»</td><td>📰 تولید مقاله کامل با خط تولید هوشمند</td></tr>
            <tr><td>«کلمات کلیدی یخچال»</td><td>🔑 تحلیل کلیدواژه + رقابت‌پذیری + long-tail</td></tr>
            <tr><td>«امتیاز این متن: ...»</td><td>🏆 امتیاز ۷ بُعدی کیفیت متن</td></tr>
            <tr><td>«این متن را بهبود بده: ...»</td><td>🔧 بهبود خودکار تا امتیاز هدف</td></tr>
            <tr><td>«عنوان برای تعمیر کولر گازی بده»</td><td>🏷️ عنوان‌سازی بهینه CTR-محور</td></tr>
            <tr><td>«نیت جستجوی خرید یخچال چیست؟»</td><td>🧭 تشخیص نیت جستجو</td></tr>
            <tr><td>«تشخیص عیب: یخچال سرد نمی‌کند»</td><td>🔍 تشخیص سه‌سطحی علامت → علت → قطعه</td></tr>
            <tr><td>«کد خطا E24 ماشین ظرفشویی»</td><td>⚠️ جستجوی کد خطا در پایگاه دانش</td></tr>
            <tr><td>«جستجوی وب: قیمت موتور ماشین لباسشویی»</td><td>🌐 جستجوی آنلاین اینترنت</td></tr>
            <tr><td>«تحقیق درباره: یخچال ساید بای ساید اسنوا»</td><td>🧪 تحقیق ساختاریافته آنلاین</td></tr>
            <tr><td>/start ، /help ، /status ، /id</td><td>⚙️ فرمان‌های مدیریتی ربات</td></tr>
        </table>
    </div>
</div>

<?php
/* 🌉 مقادیر خودکار برای اسکریپت گوگل */
$relaySecret = (string)($cfg['relay_secret'] ?? '');
$siteWebhook = rtrim(BASE_URL, '/') . '/api/telegram/webhook';
$siteToken = (string)($cfg['bot_token'] ?? '');
$gsScript = <<<GAS
/**
 * 🌉 واسط تلگرام — Google Apps Script (سایت‌ساز برند سهند سرویس)
 * ================================================================
 * کاربرد: عبور از تحریم/فیلترینگ — سرورهای گوگل بدون محدودیت به تلگرام می‌رسند.
 *
 * دو حالت هم‌زمان:
 *   ۱) خروجی: POST از سایت‌ساز → این اسکریپت → api.telegram.org
 *   ۲) ورودی: وب‌هوک تلگرام → این اسکریپت → وب‌هوک سایت‌ساز
 *
 * راه‌اندازی (۳ دقیقه):
 *   ۱. روی script.google.com یک پروژه جدید بسازید
 *   ۲. همین کد را در Code.gs بچسبانید
 *   ۳. Deploy → New deployment → Web app → Execute as: Me → Who has access: Anyone
 *   ۴. آدرس /exec را در پنل سایت‌ساز (کارت «واسط گوگل») وارد کنید
 */
var SECRET = '{$relaySecret}';          // 🔐 راز مشترک با سایت‌ساز
var SITE_WEBHOOK = '{$siteWebhook}';    // 📮 وب‌هوک سایت‌ساز برای آپدیت‌های ورودی
var RELAY_VERSION = 3;                  // 🆕 v3 (سایت‌ساز v2.9): آپلود فایل files_b64 + پروب توانایی + وب‌هوک ورودی

function doPost(e) {
  try {
    var body = JSON.parse(e.postData.contents);

    /* --- ۱) مسیر خروجی: سایت‌ساز → تلگرام --- */
    if (body.method && body.token) {
      if (SECRET !== '' && body.secret !== SECRET) {
        return out({ ok: false, description: 'bad secret' });
      }
      /* 🔎 پروب نسخه/توانایی — پنل سایت‌ساز با این می‌فهمد اسکریپت به‌روز است یا قدیمی */
      if (body.method === 'getRelayCapabilities') {
        return out({ ok: true, result: { relay_version: RELAY_VERSION, supports_files: true } });
      }
      /* 📎 v2.7: آپلود فایل — فایل‌ها base64 می‌آیند و به Blob تبدیل می‌شوند */
      if (body.files_b64) {
        var params = body.params || {};
        for (var field in body.files_b64) {
          var bytes = Utilities.base64Decode(body.files_b64[field]);
          var name = (body.files_name && body.files_name[field]) ? body.files_name[field] : field;
          var mime = (body.files_mime && body.files_mime[field]) ? body.files_mime[field] : 'application/octet-stream';
          /* نام فایل باید پسوند داشته باشد تا تلگرام سند را درست بپذیرد */
          if (name.indexOf('.') === -1) {
            name = name + '.' + (mime === 'image/png' ? 'png' : mime === 'image/jpeg' ? 'jpg' : 'bin');
          }
          var blob = Utilities.newBlob(bytes, mime, name);
          /* Blob با نام معتبر → به‌صورت فایل multipart ارسال می‌شود */
          params[field] = blob;
        }
        var tgU = UrlFetchApp.fetch('https://api.telegram.org/bot' + body.token + '/' + body.method, {
          method: 'post',
          payload: params,
          muteHttpExceptions: true
        });
        return out(JSON.parse(tgU.getContentText()));
      }
      var tg = UrlFetchApp.fetch('https://api.telegram.org/bot' + body.token + '/' + body.method, {
        method: 'post',
        contentType: 'application/json',
        payload: JSON.stringify(body.params || {}),
        muteHttpExceptions: true
      });
      return out(JSON.parse(tg.getContentText()));
    }

    /* --- ۲) مسیر ورودی: تلگرام → سایت‌ساز --- */
    if (body.update_id !== undefined) {
      if (SITE_WEBHOOK === '') {
        return out({ ok: true });
      }
      var res = UrlFetchApp.fetch(SITE_WEBHOOK, {
        method: 'post',
        contentType: 'application/json',
        headers: { 'X-Telegram-Bot-Api-Secret-Token': SECRET },
        payload: JSON.stringify(body),
        muteHttpExceptions: true
      });
      return out(JSON.parse(res.getContentText() || '{"ok":true}'));
    }

    return out({ ok: false, description: 'unknown payload' });
  } catch (err) {
    return out({ ok: false, relay_error: String(err) });
  }
}

function doGet() {
  return out({ ok: true, service: 'Sahand Telegram Relay', version: RELAY_VERSION, supports_files: true });
}

function out(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
GAS;
?>
<div class="card" style="border-color:#34a853">
    <div class="card-header">
        <h3>🌉 واسط Google Apps Script (تلگرام در زمان تحریم)</h3>
    </div>
    <div class="card-body">
        <div class="alert alert-success" style="margin-bottom:14px">
            🌉 وقتی سرور سایت‌ساز به تلگرام دسترسی ندارد (تحریم/فیلترینگ)، این واسط درخواست‌ها را از <b>سرورهای گوگل</b> به تلگرام می‌رساند.
            فعال بودن واسط روی «تست اتصال»، «راه‌اندازی وب‌هوک» و پاسخ‌گویی ربات اثر می‌گذارد.
        </div>

        <form method="post">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="scope" value="relay">
            <label class="form-check" style="margin-bottom:12px">
                <input type="checkbox" name="tg_relay_enabled" <?= !empty($cfg['relay_enabled']) ? 'checked' : '' ?>>
                🌉 فعال‌سازی ارسال از طریق واسط گوگل
            </label>
            <div class="form-row">
                <div class="form-group">
                    <label>آدرس Web App واسط (…/exec)</label>
                    <input type="url" name="tg_relay_url" class="form-control" style="direction:ltr;text-align:left" value="<?= e((string)($cfg['relay_url'] ?? '')) ?>" placeholder="https://script.google.com/macros/s/…/exec">
                </div>
                <div class="form-group">
                    <label>🔐 راز مشترک (در اسکریپت زیر همین مقدار قرار گرفته)</label>
                    <input type="text" name="tg_relay_secret" class="form-control" style="direction:ltr;text-align:left" value="<?= e($relaySecret) ?>">
                </div>
            </div>
            <div style="display:flex;gap:9px;flex-wrap:wrap">
                <button type="submit" class="btn btn-primary">💾 ذخیره تنظیمات واسط</button>
            </div>
        </form>

        <form method="post" style="margin-top:10px">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="test_relay">
            <button class="btn btn-success">🧪 تست واسط (ارسال getMe از طریق گوگل)</button>
        </form>

        <details style="margin-top:16px">
            <summary style="cursor:pointer;font-weight:700;font-size:13.5px">📜 اسکریپت آماده گوگل — کافی است کپی و Deploy کنید (مقادیر شما جای‌گذاری شده)</summary>
            <div class="hint" style="margin:10px 0">
                ۱. به <b>script.google.com</b> بروید و پروژه جدید بسازید<br>
                ۲. کد زیر را در <code>Code.gs</code> بچسبانید (راز مشترک و آدرس وب‌هوک سایت‌ساز از قبل داخلش است)<br>
                ۳. <b>Deploy → New deployment → Web app</b> → Execute as: <b>Me</b> → Who has access: <b>Anyone</b><br>
                ۴. آدرس <code>…/exec</code> را کپی و در فیلد بالا وارد کنید + دکمه تست را بزنید
            </div>
            <div style="position:relative">
                <button type="button" class="btn btn-outline btn-sm" onclick="copyGsScript(this)" style="position:absolute;top:8px;left:8px;z-index:2">📋 کپی اسکریپت</button>
                <textarea id="gs-script" readonly rows="18" class="form-control" style="direction:ltr;text-align:left;font-family:monospace;font-size:11.5px;background:#0f172a;color:#e2e8f0"><?= htmlspecialchars($gsScript, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </details>
    </div>
</div>

<script>
function copyGsScript(btn) {
    var ta = document.getElementById('gs-script');
    ta.select();
    document.execCommand('copy');
    var old = btn.innerHTML;
    btn.innerHTML = '✅ کپی شد';
    setTimeout(function () { btn.innerHTML = old; }, 1800);
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
