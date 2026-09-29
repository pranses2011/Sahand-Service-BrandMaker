<?php
/**
 * 💬 پنل ربات بله (v1.0)
 * ========================
 * تنظیم توکن، شناسه‌های مجاز، وب‌هوک، تست اتصال — همانند ربات تلگرام
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/bale/BaleBot.php';

$db = Database::getInstance();

/* 💾 عملیات */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::enforceCsrf();
    $action = post('action');

    /* ذخیره تنظیمات */
    if ($action === 'save') {
        $cfg = (array)(Config::get('bale_bot_settings') ?: []);
        $newToken = trim((string)post('bot_token'));
        $cfg['enabled'] = post('enabled') === '1';
        $cfg['bot_token'] = $newToken;
        $cfg['allowed_chat_ids'] = trim((string)post('allowed_chat_ids'));
        if (empty($cfg['webhook_secret'])) {
            $cfg['webhook_secret'] = bin2hex(random_bytes(16));
        }
        Config::set('bale_bot_settings', $cfg);
        flash('success', '✅ تنظیمات ربات بله ذخیره شد.');
        redirect('bale.php');
    }

    /* راه‌اندازی وب‌هوک */
    if ($action === 'set_webhook') {
        try {
            $cfg = (array)(Config::get('bale_bot_settings') ?: []);
            if (empty($cfg['webhook_secret'])) {
                $cfg['webhook_secret'] = bin2hex(random_bytes(16));
                Config::set('bale_bot_settings', $cfg);
            }
            $bot = new BaleBot();
            $publicUrl = BASE_URL . '/api/bale/webhook';
            if (stripos($publicUrl, 'https://') !== 0 && stripos($publicUrl, 'http://localhost') === false) {
                throw new RuntimeException('وب‌هوک بله به HTTPS نیاز دارد — آدرس سایت‌ساز: ' . $publicUrl);
            }
            $result = $bot->setWebhook($publicUrl, (string)$cfg['webhook_secret']);
            flash('success', '🔗 وب‌هوک بله تنظیم شد: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            flash('danger', 'خطای تنظیم وب‌هوک: ' . $e->getMessage());
        }
        redirect('bale.php');
    }

    /* حذف وب‌هوک */
    if ($action === 'delete_webhook') {
        try {
            (new BaleBot())->deleteWebhook();
            flash('success', '🧹 وب‌هوک بله حذف شد (حالت polling آزاد شد).');
        } catch (Throwable $e) {
            flash('danger', 'خطا: ' . $e->getMessage());
        }
        redirect('bale.php');
    }

    /* تست اتصال و ارسال پیام آزمایشی */
    if ($action === 'test') {
        try {
            $bot = new BaleBot();
            $me = $bot->getMe();
            $chatId = trim((string)post('test_chat_id'));
            $sent = '';
            if ($chatId !== '') {
                $bot->sendMessage($chatId, "💬 پیام آزمایشی ربات بله سایت‌ساز — اتصال برقرار است ✅");
                $sent = ' + پیام آزمایشی ارسال شد';
            }
            flash('success', '✅ اتصال موفق: @' . ($me['username'] ?? '?') . ' (' . ($me['first_name'] ?? '') . ')' . $sent);
        } catch (Throwable $e) {
            flash('danger', '❌ اتصال ناموفق: ' . $e->getMessage());
        }
        redirect('bale.php');
    }

    /* 🆕 v2.41 — تنظیمات ارسال درخواست‌ها به بله (notify_bale_settings)
       ریشه «درخواست‌ها به ربات بله ارسال نمی‌شوند»: این تنظیمات در صفحه
       «تنظیمات ← بخش ارسال درخواست» پنهان مانده بود و کاربر فقط پنل ربات
       را پرمی‌کرد. اکنون همین‌جا هم قابل مدیریت + تست است. */
    if ($action === 'save_notify') {
        $botToken = trim((string)post('notify_token'));
        $chatId = trim((string)post('notify_chat'));
        Config::set(Config::KEY_NOTIFY_BALE, [
            'enabled'   => !empty($_POST['notify_enabled']) || ($botToken !== '' && $chatId !== ''),
            'bot_token' => $botToken,
            'chat_id'   => $chatId,
        ]);
        flash('success', '✅ تنظیمات ارسال درخواست‌ها به بله ذخیره شد — درخواست‌های جدید (تصویر واترمارک‌دار + متن کامل) به این چت ارسال می‌شوند.');
        redirect('bale.php');
    }
    if ($action === 'test_notify') {
        try {
            $cfg = (array)(Config::get(Config::KEY_NOTIFY_BALE) ?: []);
            $token = trim((string)post('notify_token')) ?: (string)($cfg['bot_token'] ?? '');
            $chat = trim((string)post('notify_chat')) ?: (string)($cfg['chat_id'] ?? '');
            if ($token === '' || $chat === '') {
                throw new RuntimeException('توکن یا شناسه چت خالی است.');
            }
            $testMsg = "📨 تست کانال ارسال درخواست‌ها به بله\n"
                . "🏷️ برند: آزمایشی\n👤 نام: کاربر آزمایشی\n📞 تماس: ۰۹۱۲۰۰۰۰۰۰۰\n"
                . "🔧 دستگاه: لباسشویی\n📅 زمان ترجیحی: " . jdate(date('Y-m-d')) . "\n"
                . "⏰ " . jdate(date('Y-m-d H:i'), true) . "\n✅ اگر این پیام را می‌بینید، ارسال درخواست‌ها به بله به‌درستی کار می‌کند.";
            $ok = (new NotificationService())->testBaleChannel($token, $chat, $testMsg);
            flash($ok ? 'success' : 'danger', $ok ? '✅ پیام آزمایشی کانال درخواست‌ها به بله ارسال شد — چت را بررسی کنید.' : '❌ ارسال ناموفق — توکن و شناسه چت را بررسی کنید (مقصد باید حداقل یک‌بار به ربات /start داده باشد).');
        } catch (Throwable $e) {
            flash('danger', '❌ خطا: ' . $e->getMessage());
        }
        redirect('bale.php');
    }
}

$pageTitle = 'ربات بله';
$activeMenu = 'bale';
require __DIR__ . '/includes/header.php';

$cfg = (array)(Config::get('bale_bot_settings') ?: []);
$hasSecret = !empty($cfg['webhook_secret']);
$webhookUrl = BASE_URL . '/api/bale/webhook' . ($hasSecret ? '?secret=…' : '');

/* وضعیت وب‌هوک */
$webhookStatus = null;
$me = null;
if (!empty($cfg['bot_token'])) {
    try {
        $bot = new BaleBot();
        $me = $bot->getMe();
        $info = $bot->getWebhookInfo();
        $webhookStatus = [
            'url' => (string)($info['url'] ?? ''),
            'pending' => (int)($info['pending_update_count'] ?? 0),
        ];
    } catch (Throwable $e) {
        $webhookStatus = ['url' => '', 'pending' => 0, 'error' => $e->getMessage()];
    }
}
?>
<div class="card">
    <div class="card-header"><h3>💬 ربات پیام‌رسان بله — دستیار فارسی</h3></div>
    <div class="card-body">
        <div class="alert alert-info" style="margin-bottom:16px">
            💬 این ربات همان دستیار فارسی ربات تلگرام است که روی <b>پیام‌رسان بله</b> اجرا می‌شود: تولید مقاله کامل، امتیازدهی متن، کلمات کلیدی، جستجوی وب، اخبار و دستیار فرمان فارسی.
            <br>۱️⃣ در بله به <b>@BotFather</b> پیام دهید و با <code>/newbot</code> ربات بسازید و توکن را بردارید
            ۲️⃣ توکن را همین‌جا ذخیره کنید ۳️⃣ به ربات خودتان پیام <code>/id</code> بدهید و شناسه دریافتی را در «شناسه‌های مجاز» ثبت کنید
            ۴️⃣ دکمه «راه‌اندازی وب‌هوک» را بزنید — یا برای تست محلی از <code>php bale/poll.php</code> استفاده کنید
        </div>

        <form method="post">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="save">
            <div class="form-row">
                <div class="form-group">
                    <label>🤖 Bot Token بله</label>
                    <input type="text" name="bot_token" class="form-control" style="direction:ltr;text-align:left" value="<?= e((string)($cfg['bot_token'] ?? '')) ?>" placeholder="توکنی که @BotFather بله داده">
                </div>
                <div class="form-group">
                    <label>🔐 شناسه‌های چت مجاز (با کاما جدا شوند)</label>
                    <input type="text" name="allowed_chat_ids" class="form-control" style="direction:ltr;text-align:left" value="<?= e((string)($cfg['allowed_chat_ids'] ?? '')) ?>" placeholder="123456789, 987654321">
                    <div class="hint">برای گرفتن شناسه، به ربات بفرستید: /id — امنیت: بدون لیست مجاز هیچ‌کس پاسخ نمی‌گیرد.</div>
                </div>
            </div>
            <label class="form-check" style="margin:10px 0">
                <input type="checkbox" name="enabled" value="1" <?= !empty($cfg['enabled']) ? 'checked' : '' ?>> فعال‌سازی ربات (پاسخ‌گویی به پیام‌ها)
            </label>
            <button type="submit" class="btn btn-primary">💾 ذخیره تنظیمات</button>
        </form>
    </div>
</div>

<?php if (!empty($cfg['bot_token'])): ?>
<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>🔗 وب‌هوک بله</h3></div>
        <div class="card-body">
            <?php if ($me): ?>
                <div class="alert alert-success">🤖 متصل: <b>@<?= e($me['username'] ?? '?') ?></b> (<?= e($me['first_name'] ?? '') ?>)</div>
            <?php endif; ?>
            <?php if ($webhookStatus): ?>
                <?php if (!empty($webhookStatus['error'])): ?>
                    <div class="alert alert-danger">خطای اتصال: <?= e($webhookStatus['error']) ?></div>
                <?php elseif ($webhookStatus['url'] !== ''): ?>
                    <div class="alert alert-success">✅ فعال روی <code style="direction:ltr;display:inline-block"><?= e($webhookStatus['url']) ?></code> — در انتظار: <?= en_to_fa_digits((string)$webhookStatus['pending']) ?> آپدیت</div>
                <?php else: ?>
                    <div class="alert alert-warning">وب‌هوک تنظیم نشده — با polling محلی (<code>php bale/poll.php</code>) یا دکمه زیر فعال کنید.</div>
                <?php endif; ?>
            <?php endif; ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <form method="post">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="set_webhook">
                    <button class="btn btn-success">🔗 راه‌اندازی وب‌هوک</button>
                </form>
                <form method="post" data-confirm="وب‌هوک بله حذف شود؟ (حالت polling آزاد می‌شود)">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="action" value="delete_webhook">
                    <button class="btn btn-outline">🧹 حذف وب‌هوک</button>
                </form>
            </div>
            <div class="hint" style="margin-top:10px">آدرس وب‌هوک: <code style="direction:ltr;display:inline-block"><?= e($webhookUrl) ?></code></div>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>🧪 تست اتصال و ارسال</h3></div>
        <div class="card-body">
            <form method="post">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="test">
                <div class="form-group">
                    <label>شناسه چت مقصد (اختیاری — برای ارسال پیام آزمایشی)</label>
                    <input type="text" name="test_chat_id" class="form-control" style="direction:ltr;text-align:left" placeholder="123456789">
                </div>
                <button class="btn btn-primary btn-block">🧪 تست اتصال + ارسال پیام</button>
            </form>
            <div class="hint" style="margin-top:10px">💡 تست، اطلاعات ربات را می‌گیرد و در صورت وارد کردن شناسه، یک پیام آزمایشی می‌فرستد.</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 📨 v2.41 — کانال ارسال درخواست‌ها به بله -->
<?php $nBale = (array)(Config::get(Config::KEY_NOTIFY_BALE) ?: []); ?>
<div class="card">
    <div class="card-header">
        <h3>📨 ارسال درخواست‌های خدمات به بله</h3>
        <span class="badge <?= !empty($nBale['enabled']) && !empty($nBale['bot_token']) && !empty($nBale['chat_id']) ? 'badge-success' : 'badge-warning' ?>">
            <?= !empty($nBale['enabled']) && !empty($nBale['bot_token']) && !empty($nBale['chat_id']) ? '✅ فعال' : 'غیرفعال' ?>
        </span>
    </div>
    <div class="card-body">
        <div class="alert alert-info" style="margin-bottom:14px">
            📨 با این کانال، <b>درخواست‌های خدمات سایت‌های برند</b> به بله ارسال می‌شوند: هر تصویر پیوست با همان ابعاد اصلی + واترمارک لوگوی برند (پایین چپ) و لوگوی نمایندگی (پایین راست) با زمینه شفاف + متن کامل درخواست به‌صورت کپشن همان پیام + زمان ترجیحی شمسی.
            <br>💡 اگر توکن و چت را خالی بگذارید، همان توکن ربات بالا و اولین شناسه مجاز آن به‌طور خودکار استفاده می‌شود.
        </div>
        <form method="post">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="save_notify">
            <div class="form-row">
                <div class="form-group">
                    <label>🤖 توکن ربات ارسال‌کننده درخواست‌ها</label>
                    <input type="text" name="notify_token" class="form-control" style="direction:ltr;text-align:left" value="<?= e((string)($nBale['bot_token'] ?? '')) ?>" placeholder="خالی = توکن ربات بالا">
                </div>
                <div class="form-group">
                    <label>💬 شناسه چت مقصد درخواست‌ها</label>
                    <input type="text" name="notify_chat" class="form-control" style="direction:ltr;text-align:left" value="<?= e((string)($nBale['chat_id'] ?? '')) ?>" placeholder="مثلاً 123456789">
                    <div class="hint">شناسه چت خودتان یا گروه/کانال مدیریتی — مقصد باید یک‌بار به ربات پیام داده باشد.</div>
                </div>
            </div>
            <label class="form-check" style="margin:8px 0">
                <input type="checkbox" name="notify_enabled" value="1" <?= !empty($nBale['enabled']) || (!empty($nBale['bot_token']) && !empty($nBale['chat_id'])) ? 'checked' : '' ?>> فعال‌سازی ارسال درخواست‌ها به بله
            </label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
                <button type="submit" class="btn btn-primary">💾 ذخیره کانال درخواست‌ها</button>
            </div>
        </form>
        <form method="post" style="margin-top:10px">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="test_notify">
            <button class="btn btn-outline btn-sm">🧪 تست ارسال درخواست آزمایشی</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>📖 فرمان‌های پشتیبانی‌شده</h3></div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>فرمان / الگو</th><th>کار</th></tr></thead>
                <tbody>
                <tr><td><code>مقاله بنویس برای [برند] درباره [موضوع]</code></td><td>📰 تولید مقاله کامل + فایل HTML آماده انتشار</td></tr>
                <tr><td><code>کلمات کلیدی [موضوع]</code></td><td>🔑 استخراج کلیدواژه + پیشنهاد long-tail</td></tr>
                <tr><td><code>امتیاز این متن: [متن]</code></td><td>🏆 امتیاز کیفیت ۸ بُعدی</td></tr>
                <tr><td><code>نگارش: [متن]</code></td><td>📝 اصلاح نگارش فارسی (نیم‌فاصله/سجاوندی/املاء)</td></tr>
                <tr><td><code>پیشنهاد عنوان سئو: [موضوع]</code></td><td>🎯 عنوان‌سازی CTR-محور</td></tr>
                <tr><td><code>جستجوی وب: [عبارت]</code></td><td>🌐 جستجوی آنلاین اینترنت</td></tr>
                <tr><td><code>اخبار: [موضوع]</code></td><td>📰 اخبار زنده مرتبط</td></tr>
                <tr><td><code>/id</code></td><td>🔢 نمایش شناسه چت شما (برای مجازسازی)</td></tr>
                <tr><td><code>راهنما</code></td><td>📖 راهنمای کامل</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
