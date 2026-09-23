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

$pageTitle = 'تنظیمات عمومی';
$activeMenu = 'settings';
require __DIR__ . '/includes/header.php';

$db = Database::getInstance();
$fm = new FileManager();

/* 💾 ذخیره تنظیمات */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    Auth::enforceCsrf();

    // 📇 بخش ۱: اطلاعات پایه
    Config::setMany([
        Config::KEY_AGENCY_NAME_FA   => post('agency_name_fa'),
        Config::KEY_AGENCY_NAME_EN   => post('agency_name_en'),
        Config::KEY_AGENCY_SLOGAN_FA => post('agency_slogan_fa'),
        Config::KEY_AGENCY_SLOGAN_EN => post('agency_slogan_en'),
        Config::KEY_MAIN_SITE        => post('agency_main_site'),
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
    // ⚙️ SMTP اختیاری
    Config::set('smtp_settings', [
        'enabled'   => !empty($_POST['smtp_enabled']),
        'host'      => clean_input($_POST['smtp_host'] ?? ''),
        'port'      => (int)clean_input($_POST['smtp_port'] ?? 587),
        'username'  => clean_input($_POST['smtp_user'] ?? ''),
        'password'  => (string)($_POST['smtp_pass'] ?? ''),
        'secure'    => clean_input($_POST['smtp_secure'] ?? 'tls'),
    ]);

    // 🔗 بخش ۹: لینک‌دهی
    Config::set(Config::KEY_LINKING, [
        'main_site_footer'   => !empty($_POST['link_main_footer']),
        'other_brands_page'  => !empty($_POST['link_other_brands']),
        'default_nofollow'   => !empty($_POST['link_nofollow']),
    ]);

    Logger::activity((int)$_SESSION['user_id'], 'بروزرسانی تنظیمات', 'تنظیمات عمومی سایت ساز ذخیره شد');
    flash('success', '✅ تنظیمات با موفقیت ذخیره شد.');
    redirect('settings.php');
}

/* 📥 بارگذاری مقادیر فعلی */
$nameFa   = (string)Config::get(Config::KEY_AGENCY_NAME_FA);
$nameEn   = (string)Config::get(Config::KEY_AGENCY_NAME_EN);
$sloganFa = (string)Config::get(Config::KEY_AGENCY_SLOGAN_FA);
$sloganEn = (string)Config::get(Config::KEY_AGENCY_SLOGAN_EN);
$mainSite = (string)Config::get(Config::KEY_MAIN_SITE);
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
?>

<form method="post" enctype="multipart/form-data">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="save_settings">

    <!-- 🗂️ تب‌ها + دکمه ذخیره (ریسپانسیو: تب‌ها اسکرولی، دکمه در موبایل تمام‌عرض) -->
    <div class="tabs-bar">
        <div class="tabs scrollable">
            <button type="button" class="tab-btn active" onclick="switchTab(this,'tab-basic')">📇 پایه</button>
            <button type="button" class="tab-btn" onclick="switchTab(this,'tab-contact')">📞 تماس</button>
            <button type="button" class="tab-btn" onclick="switchTab(this,'tab-hours')">🕐 ساعات کاری</button>
            <button type="button" class="tab-btn" onclick="switchTab(this,'tab-warranty')">🛡️ ضمانت</button>
            <button type="button" class="tab-btn" onclick="switchTab(this,'tab-cost')">💰 هزینه و دامنه</button>
            <button type="button" class="tab-btn" onclick="switchTab(this,'tab-notify')">📨 ارسال درخواست</button>
            <button type="button" class="tab-btn" onclick="switchTab(this,'tab-links')">🔗 لینک‌دهی</button>
        </div>
        <div class="tabs-actions">
            <button type="submit" class="btn btn-primary">💾 ذخیره همه تنظیمات</button>
        </div>
    </div>

    <!-- 📇 تب اطلاعات پایه -->
    <div id="tab-basic" class="tab-pane active">
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
    <div id="tab-contact" class="tab-pane">
        <div class="card">
            <div class="card-header"><h3>📞 شماره‌های تماس (چندتایی — نامحدود)</h3></div>
            <div class="card-body">
                <?php
                $repeatTemplate = function (string $name, string $label, array $values, string $placeholder = '', string $dir = 'ltr') {
                    echo '<div class="form-group"><label>' . $label . '</label><div id="rows-' . $name . '">';
                    $values = $values ?: [''];
                    foreach ($values as $i => $val) {
                        echo '<div class="repeat-row" style="display:flex;gap:8px;margin-bottom:8px">';
                        echo '<input type="text" name="' . $name . '[]" class="form-control" value="' . e((string)$val) . '" placeholder="' . e($placeholder) . '" style="direction:' . $dir . ';text-align:' . ($dir === 'ltr' ? 'left' : 'right') . '">';
                        echo '<button type="button" class="btn btn-outline btn-sm" onclick="removeRepeatRow(this)">🗑️</button></div>';
                    }
                    echo '</div>';
                    echo '<button type="button" class="btn btn-outline btn-sm" onclick=\'addRepeatRow("rows-' . $name . '", "<div class=\\\'repeat-row\\\' style=\\\'display:flex;gap:8px;margin-bottom:8px\\\'><input type=\\\'text\\\' name=\\\'' . $name . '[]\\\' class=\\\'form-control\\\' placeholder=\\\'' . e($placeholder) . '\\\' style=\\\'direction:' . $dir . ';text-align:' . ($dir === 'ltr' ? 'left' : 'right') . '\\\'><button type=\\\'button\\\' class=\\\'btn btn-outline btn-sm\\\' onclick=\\\'removeRepeatRow(this)\\\'>🗑️</button></div>")\'>➕ افزودن</button></div>';
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
    <div id="tab-hours" class="tab-pane">
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
    <div id="tab-warranty" class="tab-pane">
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
                    <button type="button" class="btn btn-outline btn-sm" onclick='addRepeatRow("rows-warranty-void", "<div class=\\\'repeat-row\\\' style=\\\'display:flex;gap:8px;margin-bottom:8px\\\'><input type=\\\'text\\\' name=\\\'warranty_void[]\\\' class=\\\'form-control\\\'><button type=\\\'button\\\' class=\\\'btn btn-outline btn-sm\\\' onclick=\\\'removeRepeatRow(this)\\\'>🗑️</button></div>")'>➕ افزودن شرط</button>
                </div>
                <div class="form-group">
                    <label>توضیحات اضافی</label>
                    <textarea name="warranty_notes" class="form-control" rows="3"><?= e($warranty['extra_notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- 💰 تب هزینه و دامنه -->
    <div id="tab-cost" class="tab-pane">
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
    <div id="tab-notify" class="tab-pane">
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
    <div id="tab-links" class="tab-pane">
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

    <div style="text-align:center;padding:8px 0 20px">
        <button type="submit" class="btn btn-primary btn-lg">💾 ذخیره همه تنظیمات</button>
    </div>
</form>

<script>
/* 📍 افزودن شعبه جدید — کپی آخرین بلوک آدرس */
function duplicateAddress() {
    var container = document.getElementById('rows-addresses');
    var blocks = container.querySelectorAll('.repeat-row');
    var last = blocks[blocks.length - 1];
    var clone = last.cloneNode(true);
    clone.querySelectorAll('input, textarea').forEach(function (el) { el.value = ''; });
    last.parentNode.insertBefore(clone, container.querySelector('button'));
}

/* 🌐 افزودن شبکه اجتماعی */
function duplicateSocial() {
    var container = document.getElementById('rows-socials');
    var rows = container.querySelectorAll('.repeat-row');
    var clone = rows[rows.length - 1].cloneNode(true);
    clone.querySelector('input').value = '';
    container.insertBefore(clone, container.querySelector('button'));
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
