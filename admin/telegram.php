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

            /* 💾 ذخیره تنظیمات */
            case 'save_settings':
                $cfg['enabled']           = !empty($_POST['tg_enabled']);
                $cfg['bot_token']         = trim((string)($_POST['tg_token'] ?? ''));
                $cfg['allowed_chat_ids']  = trim((string)($_POST['tg_chats'] ?? ''));
                $cfg['notify_new_request']= !empty($_POST['tg_notify']);
                $cfg['welcome_text']      = trim((string)($_POST['tg_welcome'] ?? ''));
                // اعتبارسنجی ساده توکن
                if ($cfg['bot_token'] !== '' && !preg_match('#^\d+:[\w-]{30,}$#', $cfg['bot_token'])) {
                    throw new RuntimeException('قالب توکن معتبر نیست — از @BotFather کپی کنید (مثال: 123456789:AAH...)');
                }
                // اعتبارسنجی شناسه‌ها (اعداد جدا با کاما یا *)
                if ($cfg['allowed_chat_ids'] !== '' && $cfg['allowed_chat_ids'] !== '*') {
                    $ids = array_filter(array_map('trim', explode(',', $cfg['allowed_chat_ids'])));
                    foreach ($ids as $id) {
                        if (!preg_match('#^-?\d+$#', $id)) {
                            throw new RuntimeException("شناسه چت نامعتبر: {$id} — شناسه‌ها باید عددی باشند (با /id از ربات بگیرید)");
                        }
                    }
                }
                Config::set('telegram_bot_settings', $cfg);
                Logger::activity((int)$_SESSION['user_id'], 'تنظیمات ربات تلگرام', 'ذخیره تنظیمات ربات دستیار تلگرام');
                $flashType = 'success';
                $flashMsg = '✅ تنظیمات ربات ذخیره شد.';
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

<?php require __DIR__ . '/includes/footer.php'; ?>
