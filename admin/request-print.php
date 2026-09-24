<?php
/**
 * 🖨️ فرمت چاپی درخواست خدمات — برگ درخواست قابل چاپ / ذخیره PDF
 * ================================================================
 * صفحه مستقل بدون قالب پنل (برای چاپ تمیز) — لوگوی برند + نمایندگی +
 * تمام فیلدها + پیوست‌ها + محل امضا. دکمه چاپ + چاپ خودکار اختیاری.
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

/* 🔐 احراز هویت — این صفحه مستقل است و header پنل را include نمی‌کند */
(new Auth())->requireLogin();

$db = Database::getInstance();

/* 📥 دریافت درخواست + برند */
$id = (int)get_param('id');
$req = $db->fetch(
    'SELECT r.*, b.name_fa AS brand_name, b.name_en AS brand_en, b.logo AS brand_logo, b.domain
     FROM service_requests r JOIN brands b ON b.id = r.brand_id
     WHERE r.id = ?',
    [$id]
);

/* ⚙️ اطلاعات نمایندگی (برای سربرگ) */
$agency = [
    'name_fa' => Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس',
    'name_en' => Config::get(Config::KEY_AGENCY_NAME_EN) ?: 'Sahand Service',
    'logo'    => Config::get(Config::KEY_AGENCY_LOGO) ?: '',
    'site'    => Config::get(Config::KEY_MAIN_SITE) ?: 'ea-fixer.ir',
];
/* ☎️ اولین تلفن ثبت‌شده نمایندگی */
$contacts = (array)(Config::get(Config::KEY_CONTACTS) ?: []);
$agencyPhone = '';
foreach (['phone', 'mobile'] as $grp) {
    if (!empty($contacts[$grp]) && is_array($contacts[$grp])) {
        $first = reset($contacts[$grp]);
        $agencyPhone = is_array($first) ? ($first['value'] ?? '') : (string)$first;
        if ($agencyPhone !== '') { break; }
    }
}

if (!$req) {
    http_response_code(404);
    exit('<div style="font-family:Tahoma;padding:40px;text-align:center">درخواست یافت نشد. <a href="requests.php" style="color:#1565c0">بازگشت</a></div>');
}

$attachments = $db->fetchAll('SELECT file_path FROM request_attachments WHERE request_id = ?', [$id]);
$statusMap = ['new' => 'جدید', 'reviewing' => 'در حال بررسی', 'assigned' => 'تخصیص یافته', 'done' => 'انجام شده', 'canceled' => 'لغو شده'];

/* 🧰 نام فارسی دستگاه (در صورت ثبت) */
$deviceName = $db->fetchValue('SELECT name_fa FROM brand_devices WHERE brand_id = ? AND device_key = ?', [$req['brand_id'], $req['device_key']]);
$deviceLabel = $deviceName ?: $req['device_key'];
if ($req['device_other']) {
    $deviceLabel .= ' (' . $req['device_other'] . ')';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>برگ درخواست #<?= e(en_to_fa_digits((string)$req['id'])) ?> — <?= e($req['brand_name']) ?></title>
<style>
    /* 🔤 فونت و پایه — فقط استایل چاپی، بدون وابستگی */
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: Vazirmatn, Tahoma, Arial, sans-serif;
        background: #eceff3; color: #1f2937;
        font-size: 13px; line-height: 1.9;
        padding: 24px;
    }
    .sheet {
        max-width: 820px; margin: 0 auto; background: #fff;
        border-radius: 12px; padding: 34px 38px;
        box-shadow: 0 4px 24px rgba(16,42,80,.10);
    }

    /* 🔝 سربرگ */
    header { display: flex; align-items: center; gap: 16px; border-bottom: 3px solid #1d5ba6; padding-bottom: 16px; }
    header img { height: 62px; object-fit: contain; }
    .head-titles { flex: 1; }
    .head-titles h1 { font-size: 19px; color: #16437e; }
    .head-titles .sub { font-size: 11.5px; color: #64748b; }
    .head-meta { text-align: left; font-size: 11.5px; color: #475569; }
    .head-meta b { color: #16437e; }

    /* 🧾 بدنه فرم */
    .doc-title { text-align: center; margin: 22px 0 6px; font-size: 16.5px; font-weight: 800; color: #0f3a6d; }
    .doc-sub { text-align: center; font-size: 11.5px; color: #64748b; margin-bottom: 20px; }

    table.fields { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
    table.fields td { border: 1px solid #cbd5e1; padding: 9px 12px; vertical-align: top; }
    table.fields td.k { background: #f1f5f9; color: #334155; font-size: 11.5px; width: 158px; font-weight: 700; }
    table.fields td.v { font-weight: 600; }

    .box { border: 1.5px solid #cbd5e1; border-radius: 10px; padding: 14px 16px; margin-bottom: 18px; }
    .box h3 { font-size: 13px; color: #16437e; margin-bottom: 8px; }
    .box .desc { line-height: 2.15; white-space: pre-wrap; }

    .thumbs { display: flex; gap: 10px; flex-wrap: wrap; }
    .thumbs img { width: 110px; height: 110px; object-fit: cover; border-radius: 8px; border: 1px solid #cbd5e1; }

    /* ✍️ امضاها */
    .signs { display: flex; gap: 20px; margin-top: 26px; }
    .sign { flex: 1; border: 1.5px dashed #94a3b8; border-radius: 10px; padding: 12px 14px; min-height: 96px; }
    .sign b { display: block; font-size: 12px; color: #334155; margin-bottom: 34px; }

    footer.doc-footer { margin-top: 24px; padding-top: 12px; border-top: 1px solid #cbd5e1;
        display: flex; justify-content: space-between; font-size: 10.5px; color: #64748b; }

    /* 🖨️ نوار چاپ — در چاپ مخفی می‌شود */
    .print-bar { max-width: 820px; margin: 0 auto 14px; display: flex; gap: 10px; justify-content: flex-end; }
    .print-bar button, .print-bar a {
        font-family: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer;
        border: none; border-radius: 9px; padding: 9px 20px; text-decoration: none;
    }
    .btn-print { background: #1d5ba6; color: #fff; }
    .btn-back { background: #fff; color: #334155; border: 1.5px solid #cbd5e1 !important; }

    @media print {
        body { background: #fff; padding: 0; font-size: 12px; }
        .sheet { box-shadow: none; border-radius: 0; max-width: 100%; padding: 10px 6px; }
        .print-bar { display: none; }
        .thumbs img { width: 92px; height: 92px; }
    }
    @page { size: A4; margin: 12mm; }
</style>
</head>
<body>

<div class="print-bar">
    <a class="btn-back" href="requests.php?view=<?= (int)$req['id'] ?>">↩ بازگشت به پنل</a>
    <button class="btn-print" onclick="window.print()">🖨️ چاپ / ذخیره PDF</button>
</div>

<div class="sheet">
    <!-- 🔝 سربرگ: لوگوی برند + اطلاعات نمایندگی -->
    <header>
        <?php if ($req['brand_logo']): ?>
            <img src="<?= e(asset_url($req['brand_logo'])) ?>" alt="<?= e($req['brand_name']) ?>">
        <?php endif; ?>
        <div class="head-titles">
            <h1>نمایندگی خدمات پس از فروش <?= e($req['brand_name']) ?> (<?= e($req['brand_en']) ?>)</h1>
            <div class="sub"><?= e($agency['name_fa']) ?> — <?= e($agency['name_en']) ?><?= $agencyPhone ? ' | ☎ ' . e(fa_to_en_digits($agencyPhone)) : '' ?></div>
        </div>
        <div class="head-meta">
            شماره درخواست: <b>#<?= e(en_to_fa_digits((string)$req['id'])) ?></b><br>
            تاریخ ثبت: <b><?= e(jdate($req['created_at'], true)) ?></b><br>
            وضعیت: <b><?= e($statusMap[$req['status']] ?? $req['status']) ?></b>
        </div>
    </header>

    <div class="doc-title">📨 برگ درخواست خدمات</div>
    <div class="doc-sub">این برگ توسط سیستم سایت‌ساز صادر شده و جهت پیگیری تعمیرات به تکنسین تحویل داده می‌شود.</div>

    <!-- 🧾 اطلاعات متقاضی -->
    <table class="fields">
        <tr>
            <td class="k">👤 نام و نام خانوادگی</td>
            <td class="v"><?= e($req['full_name']) ?></td>
            <td class="k">📞 شماره تماس</td>
            <td class="v" style="direction:ltr;text-align:right"><?= e(fa_to_en_digits($req['phone'])) ?></td>
        </tr>
        <tr>
            <td class="k">📞 تماس دوم</td>
            <td class="v" style="direction:ltr;text-align:right"><?= $req['phone2'] ? e(fa_to_en_digits($req['phone2'])) : '—' ?></td>
            <td class="k">📅 زمان مراجعه ترجیحی</td>
            <td class="v"><?= trim(($req['preferred_date'] ? jdate($req['preferred_date']) : '') . ' ' . ($req['preferred_time'] ?: '')) ?: '—' ?></td>
        </tr>
        <tr>
            <td class="k">🏷️ برند دستگاه</td>
            <td class="v"><?= e($req['brand_name']) ?> (<?= e($req['brand_en']) ?>)</td>
            <td class="k">🔧 نوع دستگاه</td>
            <td class="v"><?= e($deviceLabel) ?></td>
        </tr>
        <tr>
            <td class="k">📋 مدل دستگاه</td>
            <td class="v"><?= $req['device_model'] ? e($req['device_model']) : '—' ?></td>
            <td class="k">🌐 سایت ثبت‌کننده</td>
            <td class="v" style="direction:ltr;text-align:right"><?= e($req['domain']) ?></td>
        </tr>
        <tr>
            <td class="k">📍 آدرس</td>
            <td class="v" colspan="3"><?= e($req['address']) ?></td>
        </tr>
    </table>

    <!-- 📝 شرح ایراد -->
    <div class="box">
        <h3>📝 شرح ایراد اعلامی مشتری</h3>
        <div class="desc"><?= e($req['description']) ?></div>
    </div>

    <!-- 🖼️ پیوست‌ها -->
    <?php if (!empty($attachments)): ?>
    <div class="box">
        <h3>🖼️ تصاویر پیوست مشتری (<?= e(en_to_fa_digits((string)count($attachments))) ?>)</h3>
        <div class="thumbs">
            <?php foreach ($attachments as $att): ?>
                <img src="<?= e(asset_url($att['file_path'])) ?>" alt="پیوست درخواست">
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 📝 گزارش فنی (خالی — تکمیل توسط تکنسین) -->
    <div class="box">
        <h3>🧰 گزارش فنی تکنسین (در بازدید تکمیل شود)</h3>
        <div class="desc" style="min-height:52px;color:#94a3b8">ایرادات مشاهده‌شده، قطعات تعویض‌شده و اقدامات انجام‌شده در این قسمت ثبت می‌شود.</div>
    </div>

    <!-- ✍️ امضاها -->
    <div class="signs">
        <div class="sign"><b>امضای مشتری</b></div>
        <div class="sign"><b>امضای تکنسین</b></div>
        <div class="sign"><b>مهر و امضای نمایندگی</b></div>
    </div>

    <footer class="doc-footer">
        <span>صادرشده توسط سایت ساز برند سهند سرویس — <?= e($agency['site']) ?></span>
        <span>شناسه چاپ: SR-<?= e(en_to_fa_digits((string)$req['id'])) ?>-<?= e(jdate($req['created_at'])) ?></span>
    </footer>
</div>

<?php if (get_param('auto') === '1'): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
<?php endif; ?>
</body>
</html>
