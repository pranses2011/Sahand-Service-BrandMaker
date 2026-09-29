<?php
/**
 * 🎭 قالب‌ساز درگ‌اند‌دراپ حرفه‌ای (v3.0 — المنتور-گونه)
 * =====================================================
 * ✨ v3.0 (طبق درخواست کاربر):
 *   🏛 ستون‌بندی: بلوک «بخش چندستونی» با ۲ تا ۴ ستون — بلوک‌ها را داخل
 *      هر ستون بکشید و رها کنید؛ چیدمان تودرتو (nested layout)
 *   🧩 ۱۵۰ عنصر: هدر/هیرو/محتوا/ستون/کارت/فرم/آمار/تعامل/رسانه/فراخوان/
 *      ساختار/فوتر — همه چیز برای ساخت هر صفحه‌ای
 *   ⚡ طراحی زنده: بوم «رندر واقعی» بلوک‌ها را همان‌طور که در سایت دیده
 *      می‌شوند نشان می‌دهد؛ تغییر ویژگی‌ها بلافاصله اعمال می‌شود؛ متن‌ها
 *      در پنل ویژگی‌ها قابل ویرایش و نتیجه همان لحظه دیده می‌شود
 *
 * بلوک‌ها را بکشید، رها کنید، مرتب کنید و ویژگی‌ها را تنظیم کنید.
 * خروجی: JSON چیدمان ذخیره در جدول templates
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();

/* 💾 ذخیره قالب */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_template') {
    (new Auth())->requireLogin(); // 🛡️ احراز هویت پیش از هدر
    Auth::enforceCsrf();
    $id = (int)post('template_id');
    $name = post('name') ?: 'قالب بدون نام';
    $pageType = post('page_type') ?: 'home';
    $layoutJson = (string)($_POST['layout_json'] ?? '[]');
    // اعتبارسنجی JSON — روی PHP 8.3 از تابع بومی و سریع json_validate استفاده می‌شود
    if (!json_validate($layoutJson)) {
        flash('danger', 'چیدمان نامعتبر است.');
        redirect('templates.php');
    }
    /* 🆕 v2.21: حالت ویرایش صفحه برند — ذخیره مستقیم روی brand_pages */
    $brandPageId = (int)post('brand_page_id');
    if ($brandPageId > 0) {
        $bp = $db->fetch('SELECT id, brand_id, page_type FROM brand_pages WHERE id = ?', [$brandPageId]);
        if ($bp) {
            /* 🕘 v2.34 — تاریخچه: قبل از ذخیره چیدمان، نسخه فعلی ثبت می‌شود */
            try {
                $rev = new Revision();
                $rev->save('page', $brandPageId, (int)$bp['brand_id'], 'چیدمان ' . ($bp['page_type'] ?? ''), $rev->snapshotPage($brandPageId));
            } catch (Throwable $revE) { /* تاریخچه نباید جریان اصلی را بشکند */ }
            /* 🆕 v2.41 — layout_custom=1: ویرایش دستی کاربر بر تم مقدم است
               (زنجیره حل قالب: ① ویرایش دستی → ② قالب صفحه → ③ تم برند) */
            $db->update('brand_pages', ['layout_json' => $layoutJson, 'layout_custom' => 1], 'id = ?', [$brandPageId]);
            /* 🎨 v2.27 — کش چیدمان همان برند پاک شود تا تغییر بلافاصله روی
               سایت برند دیده شود (چیدمان از کش ۱۲۰ث خوانده می‌شد). */
            (new Cache())->flush('api_brand_' . (int)$bp['brand_id']);
            Logger::activity((int)$_SESSION['user_id'], 'ویرایش چیدمان صفحه برند در قالب‌ساز', ($bp['page_type'] ?? '') . ' (صفحه #' . $brandPageId . ')');
            $bpName = TemplateLibrary::pageTypeLabels()[$bp['page_type']] ?? $bp['page_type'];
            flash('success', '✅ چیدمان صفحه «' . $bpName . '» ذخیره شد — روی سایت برند پس از چند لحظه اعمال می‌شود.');
            redirect('brand-edit.php?id=' . (int)$bp['brand_id'] . '&tab=pages');
        }
        flash('danger', 'صفحه برند یافت نشد.');
        redirect('templates.php');
    }
    if ($id > 0) {
        $db->update('templates', ['name' => $name, 'page_type' => $pageType, 'layout_json' => $layoutJson], 'id = ?', [$id]);
    } else {
        $id = $db->insert('templates', [
            'name' => $name, 'page_type' => $pageType, 'variant' => 'سفارشی',
            'layout_json' => $layoutJson, 'is_default' => 0,
        ]);
    }
    /* 🎨 v2.27 — قالب عمومی تغییر کرد؛ کش همه برندها پاک شود (هر تم/پیش‌فرضی
       ممکن است این قالب را ارجاع دهد). */
    (new Cache())->flush('api_brand_');
    Logger::activity((int)$_SESSION['user_id'], 'ذخیره قالب', $name);
    flash('success', '✅ قالب «' . $name . '» ذخیره شد.');
    redirect('template-builder.php?id=' . $id);
}

/* 🧩 v2.12: ذخیره بلوک ترکیبی (بلوک انتخابی + ستون‌های تودرتو) برای استفاده مجدد */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_builder_block') {
    (new Auth())->requireLogin();
    Auth::enforceCsrf();
    $name = trim(post('block_name')) ?: 'بلوک بدون نام';
    $blockJson = (string)($_POST['block_json'] ?? '');
    if (!json_validate($blockJson)) {
        flash('danger', 'ساختار بلوک ترکیبی نامعتبر است.');
        redirect('template-builder.php' . ((int)post('template_id') > 0 ? '?id=' . (int)post('template_id') : ''));
    }
    $decoded = json_decode($blockJson, true);
    if (!is_array($decoded) || empty($decoded)) {
        flash('danger', 'بلوک ترکیبی نمی‌تواند خالی باشد.');
        redirect('template-builder.php' . ((int)post('template_id') > 0 ? '?id=' . (int)post('template_id') : ''));
    }
    $db->insert('builder_blocks', [
        'name'       => mb_substr($name, 0, 180),
        'category'   => post('block_category') ?: 'سفارشی',
        'block_json' => $blockJson,
    ]);
    Logger::activity((int)$_SESSION['user_id'], 'ذخیره بلوک ترکیبی', $name);
    flash('success', '🧩 بلوک ترکیبی «' . $name . '» ذخیره شد — از کتابخانه بلوک‌ها قابل استفاده مجدد است.');
    redirect('template-builder.php' . ((int)post('template_id') > 0 ? '?id=' . (int)post('template_id') : ''));
}

/* 🗑️ v2.12: حذف بلوک ترکیبی ذخیره‌شده */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete_builder_block') {
    (new Auth())->requireLogin();
    Auth::enforceCsrf();
    $db->delete('builder_blocks', 'id = ?', [(int)post('block_id')]);
    flash('success', '🗑️ بلوک ترکیبی حذف شد.');
    redirect('template-builder.php' . ((int)post('template_id') > 0 ? '?id=' . (int)post('template_id') : ''));
}

/* 🎨 اسکیل UI/UX Pro — طراحی/ممیزی/اصلاح چیدمان (AJAX) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('action'), ['uiux_design', 'uiux_review', 'uiux_improve'], true)) {
    (new Auth())->requireLogin(); // 🛡️ احراز هویت پیش از هدر
    Auth::enforceCsrf();
    $action = post('action');
    $pageType = post('page_type') ?: 'home';
    $skill = new UIUXPro();
    try {
        if ($action === 'uiux_design') {
            $result = $skill->designPage($pageType, [
                'brand_fa' => post('brand_fa'),
                'devices_count' => (int)post('devices_count', '1'),
                'articles_count' => (int)post('articles_count', '1'),
                'has_testimonials' => post('has_testimonials', '1') === '1',
            ]);
            json_response(['success' => true, 'data' => $result]);
        }
        $layoutRaw = (string)($_POST['layout_json'] ?? '[]');
        if (!json_validate($layoutRaw)) {
            json_response(['success' => false, 'error' => 'چیدمان ارسالی نامعتبر است.'], 400);
        }
        $layout = json_decode($layoutRaw, true);
        if (!is_array($layout)) {
            json_response(['success' => false, 'error' => 'چیدمان ارسالی نامعتبر است.'], 400);
        }
        if ($action === 'uiux_review') {
            json_response(['success' => true, 'data' => $skill->reviewLayout($layout, $pageType)]);
        }
        json_response(['success' => true, 'data' => $skill->improveLayout($layout, $pageType)]);
    } catch (Throwable $e) {
        json_response(['success' => false, 'error' => 'خطای اسکیل UI/UX Pro: ' . $e->getMessage()], 500);
    }
}

/* ═══════════════════════════════════════════════════════════════
 * 🌐 v2.26 — استخراج عناصر از سایت خارجی + عناصر شخصی
 * ① extract_elements: واکشی URL → لیست عناصر با استایل
 * ② save_personal_element: ذخیره عنصر پسندیده در کتابخانه شخصی
 * ③ delete_personal_element: حذف از کتابخانه
 * ═══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'extract_elements') {
    (new Auth())->requireLogin();
    Auth::enforceCsrf();
    $url = trim((string)post('url'));
    if ($url === '' || mb_strlen($url) > 500) {
        json_response(['success' => false, 'error' => 'آدرس نامعتبر است'], 422);
    }
    /* 🚦 ضد سیل: حداکثر ۶ استخراج در ۲ دقیقه */
    $cache = new Cache();
    $key = 'elem_extract_' . md5(Logger::clientIp());
    $hits = (int)($cache->get($key) ?: 0);
    if ($hits >= 6) {
        json_response(['success' => false, 'error' => 'درخواست‌های زیادی ارسال شده — چند لحظه بعد دوباره تلاش کنید'], 429);
    }
    $cache->set($key, $hits + 1, 120);
    try {
        $result = (new ElementExtractor())->extract($url);
        json_response($result, $result['success'] ? 200 : 422);
    } catch (Throwable $e) {
        json_response(['success' => false, 'error' => 'خطای استخراج: ' . $e->getMessage()], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_personal_element') {
    (new Auth())->requireLogin();
    Auth::enforceCsrf();
    $name = trim((string)post('name')) ?: 'عنصر بدون نام';
    $type = preg_replace('/[^a-z]/', '', strtolower((string)post('type'))) ?: 'button';
    $sourceUrl = mb_substr(trim((string)post('source_url')), 0, 500);
    $html = (string)post('html');
    $css = mb_substr((string)post('css'), 0, 60000);
    /* 🧼 HTML یک‌بار دیگر سمت سرور ایمن می‌شود (اعتماد به کلاینت ممنوع) */
    $html = ElementExtractor::sanitize($html);
    if (mb_strlen($html) < 8) {
        json_response(['success' => false, 'error' => 'محتوای عنصر نامعتبر است'], 422);
    }
    try {
        $db->insert('personal_elements', [
            'name' => mb_substr($name, 0, 190),
            'element_type' => mb_substr($type, 0, 40),
            'source_url' => $sourceUrl,
            'html' => $html,
            'css' => $css,
        ]);
        $newId = (int)$db->lastInsertId();
        Logger::activity((int)$_SESSION['user_id'], 'ذخیره عنصر شخصی از سایت خارجی', $name . ' (' . $type . ')');
        json_response(['success' => true, 'data' => ['id' => $newId, 'name' => $name, 'element_type' => $type, 'html' => $html, 'css' => $css]]);
    } catch (Throwable $e) {
        json_response(['success' => false, 'error' => 'ذخیره ناموفق: ' . $e->getMessage()], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete_personal_element') {
    (new Auth())->requireLogin();
    Auth::enforceCsrf();
    try {
        $db->delete('personal_elements', 'id = ?', [(int)post('element_id')]);
        json_response(['success' => true]);
    } catch (Throwable $e) {
        json_response(['success' => false, 'error' => 'حذف ناموفق'], 500);
    }
}

/* 📥 بارگذاری قالب (موجود یا جدید) */
$templateId = (int)get_param('id');
$newPageType = get_param('page', 'home');
$template = null;
if ($templateId > 0) {
    $template = $db->fetch('SELECT * FROM templates WHERE id = ?', [$templateId]);
    if ($template) {
        $newPageType = $template['page_type'];
    }
}
$layout = $template ? (json_decode($template['layout_json'] ?? '[]', true) ?: []) : [];

/* 🆕 v2.25: تنظیمات صفحه — گره مخفی «_page» در ابتدای layout_json ذخیره می‌شود
   (فاصله‌ها، زمینه، عرض محتوا، گردی گوشه‌ها و ...). قدیمی‌ها بدون آن‌اند و
   همچنان کار می‌کنند؛ بوم فقط بلوک‌های واقعی را رندر می‌کند.
   🚨 v2.29 — فاصله‌های چهارجهته صفحه (بالا/پایین/چپ/راست) مطابق درخواست
   کاربر «فاصله محتوای صفحه از بالا و پایین و چپ و راست» اضافه شد. */
$pageProps = [];
if (!empty($layout) && is_array($layout[0]) && ($layout[0]['block'] ?? '') === '_page') {
    $pageProps = is_array($layout[0]['props'] ?? null) ? $layout[0]['props'] : [];
    array_shift($layout);
}

/* 🆕 v2.21: حالت ویرایش صفحه برند — چیدمان brand_pages در بوم قالب‌ساز
   ورودی: ?brand_page=<id> — ذخیره مستقیم روی همان صفحه (نه جدول templates) */
$brandPageId = (int)get_param('brand_page');
$brandPage = null;
if ($brandPageId > 0) {
    $brandPage = $db->fetch(
        'SELECT p.*, b.name_fa AS brand_name, b.id AS bid FROM brand_pages p JOIN brands b ON b.id = p.brand_id WHERE p.id = ?',
        [$brandPageId]
    );
    if ($brandPage) {
        $newPageType = $brandPage['page_type'];
        $layout = json_decode($brandPage['layout_json'] ?? '[]', true) ?: [];
        if (!empty($layout) && is_array($layout[0]) && ($layout[0]['block'] ?? '') === '_page') {
            $pageProps = is_array($layout[0]['props'] ?? null) ? $layout[0]['props'] : [];
            array_shift($layout);
        }
    } else {
        flash('danger', 'صفحه برند یافت نشد.');
        redirect('templates.php');
    }
}

$allPageTypes = TemplateLibrary::pageTypeLabels(); /* 🆕 v2.21: همه ۱۷ نوع صفحه */
$bpTypeName = $brandPage ? ($allPageTypes[$brandPage['page_type']] ?? $brandPage['page_type']) : '';
$pageTitle = $brandPage
    ? ('قالب‌ساز — صفحه «' . $bpTypeName . '» برند ' . $brandPage['brand_name'])
    : ($template ? 'ویرایش قالب: ' . $template['name'] : 'قالب جدید');
$activeMenu = 'template-builder';
require __DIR__ . '/includes/header.php';

/* 📚 کتابخانه بلوک‌ها — ~۱۵۰ عنصر در ۱۲ دسته (v3.0 + v2.25)
 * [آیکون، برچسب، پیش‌فرض‌های ویژگی] */
$blockLibrary = [
    'هدر' => [
        'header-v1' => ['📐', 'هدر ساده (لوگو + منو)', ['sticky' => 0]],
        'header-v2' => ['📐', 'هدر با نوار تماس', []],
        'header-v3' => ['📐', 'هدر شیشه‌ای چسبان', ['sticky' => 1]],
        'top-bar' => ['📏', 'نوار بالایی (تلفن + ساعات)', []],
        'notification-bar' => ['🔔', 'نوار اطلاعیه بالای صفحه', ['text' => '🎉 سرویس ویژه تعطیلات — ۱۵٪ تخفیف سرویس دوره‌ای']],
    ],
    'هیرو' => [
        'hero' => ['🦸', 'هیرو متن + دکمه', ['title' => 'تعمیرات تخصصی با قطعات اصلی', 'subtitle' => 'نمایندگی رسمی — پاسخگویی ۷ روز هفته']],
        'hero-slider' => ['🦸', 'اسلایدر تصویری', ['slides' => 3, 'autoplay' => 1]],
        'hero-split' => ['🦸', 'هیرو دو بخشی', ['title' => 'تعمیر لوازم خانگی در محل']],
        'hero-video' => ['🦸', 'هیرو با پس‌زمینه تصویر', []],
        'hero-countdown' => ['⏱️', 'هیرو با شمارش معکوس', []],
        'hero-form' => ['🦸', 'هیرو + فرم درخواست کنار هم', ['title' => 'درخواست تعمیر آنلاین']],
        'hero-marquee' => ['🏃', 'نوار خبر متحرک', ['text' => '⚡ اعزام تکنسین در کمتر از ۲ ساعت — ⭐ بیش از ۵۰ هزار تعمیر موفق — 🛡️ ۶ ماه ضمانت کتبی']],
        'announcement-pill' => ['📢', 'اعلان برجسته تک‌خطی (Pill)', ['title' => '📣 تیتر مهم امروز: پذیرش فوری درخواست‌های تعطیلات']],
        'hero-minimal' => ['🧼', 'هیرو مینیمال (عنوان + دکمه)', ['title' => 'تعمیر تخصصی، بدون معطلی', 'btnText' => 'درخواست تعمیر']],
        'hero-glass' => ['🧊', 'هیرو شیشه‌ای مدرن', ['title' => 'خدمات رسمی پس از فروش']],
        'logo-strip' => ['🏷️', 'نوار لوگوی برندها', ['title' => 'نمایندگی رسمی برندهای معتبر']],
    ],
    'محتوا' => [
        'text' => ['📝', 'متن آزاد', ['title' => 'درباره ما', 'text' => 'متن خود را اینجا بنویسید — این بخش در سایت به همین شکل نمایش داده می‌شود.']],
        'text-image' => ['📝', 'متن + تصویر', ['title' => 'درباره برند']],
        'rich-text' => ['📝', 'متن غنی (عنوان + لیست)', ['title' => 'خدمات ما شامل:']],
        'quote' => ['❝', 'نقل‌قول / شعار', ['text' => 'کیفیت تعمیر، اعتبار ماست']],
        'intro' => ['📋', 'معرفی کوتاه برند', []],
        'two-col' => ['📋', 'مقایسه دو ستونه', []],
        'three-col' => ['📋', 'سه ستونه متنی', []],
        'brand-story' => ['📖', 'داستان برند (خط زمانی متنی)', ['title' => 'داستان ما']],
        'area-list' => ['📍', 'فهرست محدوده خدمات (چیپ‌های شهر)', ['title' => 'مناطق تحت پوشش']],
        'checklist' => ['✅', 'چک‌لیست قبل از تماس', ['title' => 'قبل از تماس این‌ها را بررسی کنید']],
        'search-bar' => ['🔎', 'نوار جستجوی بزرگ', ['placeholder' => 'جستجوی کد خطا، مقاله یا دستگاه...']],
        'heading-center' => ['🎯', 'عنوان بزرگ وسط‌چین + زیرعنوان', ['title' => 'خدمات تخصصی ما', 'subtitle' => 'با بیش از یک دهه تجربه در تعمیر لوازم خانگی']],
        'numbered-list' => ['🔢', 'لیست شماره‌دار بزرگ', ['title' => 'مراحل سرویس دوره‌ای']],
        'info-box' => ['💡', 'جعبه اطلاعات مهم (آیکون + متن)', ['title' => 'نکته مهم', 'icon' => '💡', 'text' => 'قبل از تماس، مدل دستگاه و کد خطا را آماده کنید تا پاسخگویی سریع‌تر باشد.']],
        'benefits-list' => ['💚', 'فهرست مزایا (تیک سبز)', ['title' => 'مزایای انتخاب ما']],
        'author-box' => ['🧑‍🔧', 'جعبه کارشناس/نویسنده', ['title' => 'مهندس کریمی', 'icon' => '👨‍🔧']],
        'text-columns' => ['📰', 'متن دو ستونه روزنامه‌ای', ['title' => 'درباره خدمات ما', 'text' => "پاراگراف اول متن طولانی...
پاراگراف دوم..."]],
        'brand-values' => ['💎', 'ارزش‌های برند (آیکون + متن)', ['title' => 'ارزش‌های ما', 'items' => [['icon' => '🛡️', 'text' => 'صداقت در اعلام قیمت'], ['icon' => '⚡', 'text' => 'سرعت در اعزام'], ['icon' => '🔧', 'text' => 'تخصص واقعی']]]],
        'tech-tips' => ['💡', 'نکات طلایی تعمیرکار', ['title' => 'نکات فنی از تعمیرکاران ما', 'items' => [['icon' => '۱', 'text' => 'دستگاه را قبل از تماس ریست کنید'], ['icon' => '۲', 'text' => 'کد خطا را یادداشت کنید'], ['icon' => '۳', 'text' => 'قطعات فیک نخرید']]]],
        /* 🆕 v2.25 */
        'pros-cons' => ['🆚', 'مزایا و معایب (دو ستون)', ['title' => 'چرا تعمیر نزد ما؟', 'items' => [['icon' => '✅', 'text' => 'قطعات اصلی و ضمانت‌دار'], ['icon' => '✅', 'text' => 'اعزام سریع تکنسین'], ['icon' => '⚠️', 'text' => 'زمان تعمیر ۲ تا ۴ روز کاری']]]],
        'text-accent-box' => ['🟦', 'جعبه متن با نوار رنگی', ['title' => 'نکته مهم', 'text' => 'متنی که باید توجه کاربر را جلب کند — با نوار رنگی کنار جعبه.']],
        'definition-list' => ['📖', 'فهرست اصطلاحات فنی', ['title' => 'اصطلاحات پرکاربرد', 'items' => [['icon' => '🔧', 'text' => 'ایرادیابی', 'desc' => 'بررسی کامل دستگاه برای یافتن عیب'], ['icon' => '🧲', 'text' => 'مگنترون', 'desc' => 'قطعه تولید امواج مایکروویو']]]],
        'article-highlight' => ['🌟', 'جعبه محتوای ویژه (داینامیک: جدیدترین مقاله)', ['title' => 'خدمات ویژه تعطیلات', 'subtitle' => 'پاسخگویی و اعزام امداد در تمام روزهای هفته', 'text' => 'خلاصه‌ای از مزیت ویژه این بخش — متن و تصویر قابل تنظیم است.']],
        'page-header' => ['📄', 'سربرگ صفحه (عنوان + مسیر)', ['title' => 'عنوان صفحه', 'subtitle' => 'توضیح کوتاهی درباره این صفحه']],
        'steps-vertical' => ['🪜', 'مراحل عمودی (ریزش بخش‌ها)', ['title' => 'مسیر انجام کار', 'items' => [['icon' => '۱', 'text' => 'ثبت درخواست', 'desc' => 'آنلاین یا تلفنی'], ['icon' => '۲', 'text' => 'عیب‌یابی و اعلام هزینه', 'desc' => 'شفاف و پیش از شروع'], ['icon' => '۳', 'text' => 'تعمیر و تحویل', 'desc' => 'همراه با ضمانت کتبی']]]],
    ],
    '🏛 ستون‌بندی' => [
        'section-columns' => ['🏛', 'بخش چندستونی (۲-۴ ستون)', ['columns' => 2]],
        'section-split' => ['🏛', 'بخش دو بخشی نامتقارن', []],
        'feature-list' => ['✅', 'فهرست ویژگی با آیکون', []],
    ],
    'کارت‌ها' => [
        'services-grid' => ['🃏', 'کارت‌های خدمات', ['columns' => 3]],
        'devices-grid' => ['🃏', 'کارت دستگاه‌ها (داینامیک)', ['columns' => 4]],
        'articles-recent' => ['🃏', 'کارت مقالات اخیر (داینامیک)', []],
        'articles-grid' => ['🃏', 'شبکه مقالات', []],
        'features' => ['🃏', 'کارت‌های چرا ما', []],
        'team' => ['🃏', 'کارت تیم', []],
        'pricing-table' => ['💰', 'جدول تعرفه خدمات', []],
        'brands-links' => ['🏷️', 'لوگوی برندها', []],
        'certificates' => ['🎖️', 'کارت گواهینامه‌ها و افتخارات', ['title' => 'گواهینامه‌ها و افتخارات']],
        'review-grid' => ['⭐', 'شبکه نظرات مشتریان (داینامیک)', ['title' => 'مشتریان ما چه می‌گویند']],
        'contact-cards' => ['📇', 'کارت‌های اطلاعات تماس', []],
        'price-cards' => ['💠', 'کارت‌های پلن قیمت (۳ پلن)', ['title' => 'پلن‌های سرویس دوره‌ای']],
        'location-cards' => ['🏬', 'کارت شعب و آدرس‌ها', ['title' => 'شعب ما']],
        'expert-cards' => ['👨‍🔧', 'کارت متخصصین (آواتار + تخصص)', ['title' => 'تیم متخصصین ما']],
        'logo-cloud' => ['☁️', 'ابر لوگوها و نمادها', ['title' => 'مجوزها و نمادهای ما']],
        'brand-intro-card' => ['🏷️', 'کارت معرفی برند (لوگو + امتیاز)', ['title' => 'نمایندگی رسمی خدمات', 'subtitle' => 'بیش از یک دهه تجربه تخصصی']],
        'price-highlight' => ['🔆', 'کارت قیمت برجسته (تک‌پلن)', ['title' => 'سرویس دوره‌ای کامل', 'price' => '۴۵۰ هزار تومان', 'badge' => 'پیشنهاد ویژه']],
        'price-compare' => ['⚖️', 'مقایسه پکیج‌های خدمات', ['title' => 'تعرفه سرویس‌ها', 'columns' => 3]],
        /* 🆕 v2.25 */
        'service-price-cards' => ['🛠', 'کارت خدمات با قیمت', ['title' => 'تعرفه خدمات پرتقاضا', 'columns' => 3, 'items' => [['icon' => '🧺', 'text' => 'شست‌وشوی کامل ماشین لباس', 'desc' => 'از ۹۵۰ هزار تومان'], ['icon' => '❄️', 'text' => 'شارژ گاز کولر', 'desc' => 'از ۱٫۲ میلیون تومان'], ['icon' => '🔥', 'text' => 'تعویض هیتر ماشین ظرفشویی', 'desc' => 'از ۱٫۵ میلیون تومان']]]],
        'feature-icons-grid' => ['🔣', 'شبکه آیکون‌های بزرگ', ['title' => 'خدمات ما در یک نگاه', 'columns' => 4, 'items' => [['icon' => '🧊', 'text' => 'یخچال'], ['icon' => '🧺', 'text' => 'لباسشویی'], ['icon' => '📺', 'text' => 'تلویزیون'], ['icon' => '🔥', 'text' => 'فر و اجاق'], ['icon' => '🍵', 'text' => 'کتری برقی'], ['icon' => '☕', 'text' => 'قهوه‌ساز'], ['icon' => '🌪', 'text' => 'جاروبرقی'], ['icon' => '💧', 'text' => 'آبگرمکن']]]],
    ],
    'فرم' => [
        'contact-form' => ['📝', 'فرم تماس', []],
        'request-form' => ['📝', 'فرم درخواست خدمات (فیلدهای کامل + قابل تنظیم)', ['formFields' => ['fullName' => ['on' => 1, 'req' => 1], 'phone' => ['on' => 1, 'req' => 1], 'phone2' => ['on' => 1, 'req' => 0], 'address' => ['on' => 1, 'req' => 1], 'deviceType' => ['on' => 1, 'req' => 1], 'deviceModel' => ['on' => 1, 'req' => 0], 'preferredTime' => ['on' => 1, 'req' => 0], 'description' => ['on' => 1, 'req' => 1], 'images' => ['on' => 1, 'req' => 0]], 'formDest' => ['panel' => 1, 'email' => 1, 'telegram' => 0, 'bale' => 0]]],
        'newsletter-form' => ['📧', 'فرم عضویت خبرنامه', []],
        'appointment-form' => ['📅', 'فرم رزرو نوبت (تاریخ + ساعت)', []],
        'quick-contact-form' => ['⚡', 'فرم سریع تک‌خطی (تلفن + دکمه)', ['title' => 'در چند ثانیه درخواست دهید', 'btnText' => 'درخواست تماس']],
        /* 🆕 v2.15 */
        'booking-calendar' => ['🗓', 'تقویم رزرو نوبت (تعاملی)', []],
        'warranty-check' => ['🛡', 'استعلام گارانتی (شماره سریال)', []],
        'price-estimate' => ['🧮', 'برآوردگر هزینه فوری (دستگاه + ایراد)', []],
        'device-error-lookup' => ['🔢', 'جستجوی کد خطای دستگاه (داینامیک)', []],
        'appointment-compact' => ['📅', 'رزرو سریع نوبت (فشرده)', ['title' => 'نوبت تعمیر رزرو کنید']],
        /* 🆕 v2.25 */
        'callback-form' => ['☎️', 'فرم درخواست تماس', ['title' => 'درخواست تماس کارشناس', 'btnText' => 'با من تماس بگیرید']],
        'survey-form' => ['📊', 'نظرسنجی رضایت', ['title' => 'میزان رضایت شما از سرویس؟', 'items' => [['icon' => '⭐', 'text' => 'بسیار راضی'], ['icon' => '👍', 'text' => 'راضی'], ['icon' => '😐', 'text' => 'معمولی']]]],
    ],
    'آمار' => [
        'counter-stats' => ['📊', 'شمارنده‌ها', []],
        'progress-bars' => ['📊', 'نوارهای پیشرفت', []],
        'skill-bars' => ['📊', 'مهارت‌های تخصصی', []],
        'stats-grid' => ['🔢', 'شبکه اعداد کلیدی (۶ کارت)', []],
        'stats-strip' => ['📉', 'نوار آمار فشرده (۴ عدد در یک ردیف)', []],
        /* 🆕 v2.15 */
        'live-queue' => ['🚦', 'وضعیت صف تعمیر زنده', []],
        'hourly-capacity' => ['⏰', 'ظرفیت سرویس امروز (ساعتی)', []],
        'stats-inline' => ['📈', 'آمار درون‌خطی فشرده', ['items' => [['icon' => '۱۲+', 'text' => 'سال تجربه'], ['icon' => '۵۰k', 'text' => 'تعمیر'], ['icon' => '۹۸٪', 'text' => 'رضایت']]]],
        /* 🆕 v2.25 */
        'stats-circles' => ['⭕', 'حلقه‌های درصدی', ['title' => 'عملکرد ما در آمار واقعی', 'items' => [['icon' => '۹۲٪', 'text' => 'تعمیر در روز اول'], ['icon' => '۸۷٪', 'text' => 'رضایت کامل'], ['icon' => '۹۶٪', 'text' => 'حل قطعی ایراد']]]],
        'counter-big' => ['🔢', 'شمارنده بزرگ (تک‌عدد)', ['title' => 'تعمیر موفق از سال ۱۳۸۹', 'items' => [['icon' => '۵۰,۰۰۰+', 'text' => 'تعمیر تکمیل‌شده']]]],
        'brand-stats-bar' => ['📊', 'نوار آمار برند (ریبون)', ['title' => 'سهند سرویس در یک نگاه', 'items' => [['icon' => '۱۵+', 'text' => 'سال تجربه'], ['icon' => '۴۲', 'text' => 'نوع دستگاه تخصصی'], ['icon' => '۲۴/۷', 'text' => 'پشتیبانی'], ['icon' => '۶ ماه', 'text' => 'ضمانت کتبی']]]],
    ],
    'تعامل' => [
        'testimonials' => ['💬', 'اسلایدر نظرات مشتریان (داینامیک)', ['autoplay' => 1]],
        'faq-accordion' => ['❓', 'آکاردئون سوالات', []],
        'tabs' => ['🗂️', 'تب‌بندی محتوا', []],
        'timeline' => ['🕐', 'خط زمانی پیشرفت کار', []],
        'steps-process' => ['👣', 'مراحل کار (فرآیند)', []],
        'before-after' => ['🔀', 'مقایسه قبل/بعد تعمیر', []],
        'social-proof' => ['🌟', 'اثبات اجتماعی (آواتار + امتیاز)', ['text' => 'بیش از ۵۰ هزار مشتری به ما اعتماد کرده‌اند']],
        'warranty-steps' => ['🛡️', 'مراحل گارانتی (۳ گام)', ['title' => 'گارانتی ما چگونه کار می‌کند']],
        'feature-table' => ['🧾', 'جدول مقایسه ویژگی‌ها', ['title' => 'مقایسه پلن‌های سرویس']],
        /* 🆕 v2.15 */
        'faq-search' => ['🔎', 'جستجوی سوالات متداول (داینامیک)', []],
        'faq-category' => ['🗂', 'سوالات متداول دسته‌بندی‌شده (داینامیک)', []],
        'faq-mini' => ['❓', 'سوال و پاسخ تک‌آیتمی', ['title' => 'هزینه عیب‌یابی چقدر است؟', 'text' => 'عیب‌یابی تخصصی در صورت تعمیر نزد ما رایگان است.']],
        'steps-compact' => ['3️⃣', 'مراحل فشرده سرویس', ['title' => 'فقط ۳ قدم تا تعمیر']],
        /* 🆕 v2.25 */
        'quote-slider' => ['💬', 'اسلایدر نقل‌قول‌ها (داینامیک)', ['title' => 'مشتریان چه می‌گویند', 'items' => [['icon' => 'علی محمدی', 'text' => 'سرویس سریع و منظم بود؛ راضی بودم.'], ['icon' => 'مریم احمدی', 'text' => 'قیمت شفاف و ضمانت واقعی.'], ['icon' => 'رضا کریمی', 'text' => 'تکنسین دقیق و حرفه‌ای اعزام شد.']]]],
        'vote-poll' => ['🗳', 'رای‌گیری تعاملی', ['title' => 'کدام سرویس دوره‌ای را می‌خواهید؟', 'items' => [['icon' => '🧺', 'text' => 'لباسشویی'], ['icon' => '❄️', 'text' => 'یخچال'], ['icon' => '🔥', 'text' => 'ماکروویو']]]],
    ],
    'رسانه' => [
        'gallery' => ['🖼️', 'گالری تصاویر', []],
        'image-carousel' => ['🎠', 'کاروسل تصاویر', []],
        'video-embed' => ['🎬', 'ویدیو (نصب/آموزش)', []],
        'map' => ['📍', 'نقشه محدوده خدمات', []],
        /* 🆕 v2.15 */
        'before-after-slider' => ['🎚', 'اسلایدر مقایسه تصویری قبل/بعد', []],
        'social-wall' => ['📲', 'دیوار شبکه‌های اجتماعی (پست‌ها)', []],
        'reviews-carousel' => ['💬', 'اسلایدر نظرات مشتریان (داینامیک)', ['title' => 'مشتریان چه می‌گویند']],
        /* 🆕 v2.25 */
        'video-grid' => ['🎞', 'شبکه ویدیوهای آموزشی', ['title' => 'آموزش‌های ویدیویی', 'columns' => 3]],
        'logo-marquee' => ['🏷', 'نوار لوگوی متحرک (داینامیک)', ['title' => 'برندهای مورد خدمت', 'items' => [['icon' => '🏷️', 'text' => 'ال‌جی'], ['icon' => '🏷️', 'text' => 'سامسونگ'], ['icon' => '🏷️', 'text' => 'بوش'], ['icon' => '🏷️', 'text' => 'سونی'], ['icon' => '🏷️', 'text' => 'پاکس'], ['icon' => '🏷️', 'text' => 'اسنوا']]]],
        'tag-cloud' => ['#️⃣', 'ابر برچسب مقالات (داینامیک)', ['title' => 'جستجوهای پرتکرار', 'items' => [['text' => 'تعمیر ماشین لباس'], ['text' => 'کد خطا SE'], ['text' => 'شارژ گاز کولر'], ['text' => 'بک‌لایت تلویزیون'], ['text' => 'مگنترون'], ['text' => 'سرویس دوره‌ای'], ['text' => 'برد الکترونیک'], ['text' => 'نصب ظرفشویی']]]],
    ],
    'فراخوان' => [
        'cta-phone' => ['📞', 'CTA تماس بزرگ', ['phone' => '۰۲۱-۱۲۳۴۵۶۷۸']],
        'cta-request' => ['🔗', 'CTA ثبت درخواست', []],
        'cta-banner' => ['🔗', 'بنر فراخوان عریض', []],
        'sticky-mobile-cta' => ['📱', 'نوار فراخوان چسبان موبایل', []],
        'cta-whatsapp' => ['💚', 'CTA واتساپ', []],
        'warranty-banner' => ['🛡️', 'بنر ضمانت کتبی', []],
        'link-buttons' => ['🔗', 'دکمه‌های لینک (بروشور/پیگیری)', ['title' => 'دسترسی سریع']],
        'promo-card' => ['🎁', 'کارت کمپین و تخفیف ویژه', ['title' => 'کمپین سرویس بهاره', 'subtitle' => 'تا ۲۵٪ تخفیف سرویس دوره‌ای — تا پایان ماه']],
        'download-card' => ['📥', 'کارت دانلود فایل/بروشور', ['title' => 'بروشور خدمات ما', 'subtitle' => 'فهرست کامل خدمات و تعرفه‌ها در یک فایل PDF', 'btnText' => '⬇ دانلود بروشور']],
        /* 🆕 v2.15 */
        'newsletter-popup' => ['🪟', 'پیش‌نمایش پاپ‌آپ خبرنامه', []],
        'guarantee-card' => ['🛡️', 'کارت ضمانت کتبی', ['title' => '۶ ماه ضمانت کتبی', 'btnText' => 'مشاهده شرایط']],
        'cta-timer' => ['⏳', 'فراخوان با تایمر محدود', ['title' => 'تخفیف سرویس دوره‌ای']],
        'urgent-repair' => ['🚨', 'باکس تعمیر فوری ۲۴/۷', ['title' => 'تعمیر فوری نیاز دارید؟', 'phone' => '۰۲۱-۱۲۳۴۵۶۷۸']],
        /* 🆕 v2.25 */
        'emergency-strip' => ['🚑', 'نوار امداد فوری', ['text' => '🚑 امداد تعمیر فوری — ۲۴ ساعته، ۷ روز هفته', 'phone' => '۰۲۱-۱۲۳۴۵۶۷۸']],
    ],
    'ساختار' => [
        'breadcrumb' => ['🧭', 'مسیر راهنما (Breadcrumb)', []],
        'alert-notice' => ['⚠️', 'هشدار/اطلاعیه', ['text' => 'سرویس در تعطیلات نیز پاسخگوی شماست']],
        'button-group' => ['🔘', 'گروه دکمه', []],
        'icon-list' => ['📋', 'فهرست با آیکون', []],
        'separator' => ['⬜', 'جداکننده', []],
        'divider-icon' => ['➖', 'جداکننده با آیکون وسط', ['icon' => '🔧']],
        'spacer' => ['⬜', 'فاصله', ['height' => 46]],
        'working-hours' => ['🕐', 'کارت ساعات کاری', []],
        'social-follow' => ['📣', 'دنبال‌کردن شبکه‌های اجتماعی', []],
        'trust-badges' => ['🏅', 'نشان‌های اعتماد (ردیفی)', []],
        'contact-info-bar' => ['📇', 'نوار فشرده اطلاعات تماس', ['phone' => '۰۲۱-۱۲۳۴۵۶۷۸']],
        'contact-map-split' => ['🗺️', 'نقشه + اطلاعات کنار هم', ['title' => 'آدرس و راه‌های ارتباطی']],
        'warning-box' => ['🔴', 'جعبه هشدار فنی (قرمز)', ['title' => 'هشدار ایمنی مهم', 'icon' => '⚠️', 'text' => 'قبل از هرگونه باز کردن دستگاه، برق را کاملاً قطع کنید — تخلیه خازن‌ها توسط کارشناس انجام شود.']],
        'related-links' => ['🔗', 'لینک‌های مرتبط / مقالات (داینامیک)', ['title' => 'مطالب مرتبط']],
        'schedule-table' => ['🗓️', 'جدول ساعات کاری هفتگی', ['title' => 'ساعات کاری ما']],
        /* 🆕 v2.15 */
        'ticker-bar' => ['📣', 'نوار تیکر متحرک اطلاعات', []],
        'credit-trust' => ['💎', 'کارت امتیاز اعتماد (عدد درشت)', []],
        'brand-badges-row' => ['🎗', 'ردیف نشان‌های تخصصی', []],
        /* 🆕 v2.25 */
        'chat-widget' => ['💬', 'ویجت گفتگوی آنلاین', ['title' => 'پشتیبانی آنلاین']],
    ],
    'فوتر' => [
        'footer-simple' => ['🦶', 'فوتر ساده', []],
        'footer-contact' => ['🦶', 'فوتر با اطلاعات تماس', []],
        'footer-links' => ['🦶', 'فوتر چندستونه با لینک‌ها', []],
        'payment-methods' => ['💳', 'روش‌های پرداخت', []],
        'copyright' => ['©️', 'نوار کپی‌رایت', []],
    ],
    /* ═══════════════════════════════════════════════════════════════
     * 🆕 v2.29 — ۶۴ عنصر جدید در ۸ دسته تازه (درخواست کاربر: «تعداد عناصر
     * رو خیلی زیاد بکن تا هیچ محدودیتی از بابت طراحی نداشته باشیم»)
     * همه با renderType عمومی: cards | features | stats | chips | banner |
     * steps | price | quote — در هر سه صحنه (بوم/پیش‌نمایش/سایت برند) رندر می‌شوند
     * ═══════════════════════════════════════════════════════════════ */
    '🛒 فروش و تخفیف' => [
        'promo-banner' => ['🎁', 'بنر تخفیف بزرگ', ['renderType' => 'banner', 'title' => '🔥 جشنواره تخفیف بهاره', 'subtitle' => 'تا ۳۰٪ تخفیف سرویس دوره‌ای — فقط تا پایان هفته', 'btnText' => 'همین حالا رزرو کنید', 'items' => [['icon' => '⚡', 'text' => 'ثبت فوری', 'desc' => 'ظرفیت محدود'], ['icon' => '🛡️', 'text' => 'ضمانت کامل', 'desc' => 'کتبی و رسمی']]]],
        'discount-coupon' => ['🎫', 'کارت کد تخفیف', ['renderType' => 'banner', 'title' => 'کد تخفیف ویژه', 'subtitle' => 'SPRING25 را وارد کنید و ۲۵٪ تخفیف بگیرید', 'btnText' => 'کپی کد تخفیف', 'items' => [['icon' => '🎫', 'text' => 'SPRING25', 'desc' => '۲۵٪ سرویس دوره‌ای'], ['icon' => '⏳', 'text' => '۳ روز اعتبار', 'desc' => 'دیر نکنید']]]],
        'product-cards' => ['📦', 'کارت محصولات/قطعات', ['renderType' => 'cards', 'columns' => 3, 'title' => 'قطعات پرتقاضا', 'items' => [['icon' => '🌀', 'text' => 'بلبرینگ لباسشویی', 'desc' => 'از ۸۵۰ هزار تومان'], ['icon' => '❄️', 'text' => 'کمپرسور یخچال', 'desc' => 'از ۴٫۲ میلیون'], ['icon' => '🔥', 'text' => 'المنت فر', 'desc' => 'از ۶۵۰ هزار تومان'], ['icon' => '📺', 'text' => 'برد پاور تلویزیون', 'desc' => 'از ۱٫۸ میلیون']]]],
        'category-grid' => ['🗂', 'شبکه دسته‌بندی خدمات', ['renderType' => 'cards', 'columns' => 4, 'title' => 'دسته‌بندی خدمات', 'items' => [['icon' => '🌀', 'text' => 'لباسشویی'], ['icon' => '🧊', 'text' => 'یخچال'], ['icon' => '🍽️', 'text' => 'ظرفشویی'], ['icon' => '📺', 'text' => 'تلویزیون'], ['icon' => '❄️', 'text' => 'کولر'], ['icon' => '♨️', 'text' => 'پکیج'], ['icon' => '📻', 'text' => 'ماکروویو'], ['icon' => '🔥', 'text' => 'فر و اجاق']]]],
        'price-ticker' => ['💹', 'تیکر قیمت لحظه‌ای', ['renderType' => 'chips', 'title' => 'تعرفه امروز', 'items' => [['text' => 'سرویس لباسشویی ۹۵۰ هزار'], ['text' => 'شارژ گاز ۱٫۲ میلیون'], ['text' => 'عیب‌یابی رایگان*'], ['text' => 'تعمیر برد از ۹۰۰ هزار']]]],
        'installment-plans' => ['🧾', 'پلن‌های اقساطی', ['renderType' => 'price', 'title' => 'خرید اقساطی قطعات', 'items' => [['text' => 'پلن ۳ ماهه', 'desc' => 'بدون سود — چک صیادی'], ['text' => 'پلن ۶ ماهه', 'desc' => 'سود ۴٪ — تنها برای قطعات بالای ۳ میلیون'], ['text' => 'پلن ۹ ماهه', 'desc' => 'ویژه سرویس‌های سازمانی']]]],
        'payment-options' => ['💳', 'روش‌های پرداخت', ['renderType' => 'cards', 'columns' => 4, 'title' => 'پرداخت آسان و امن', 'items' => [['icon' => '💵', 'text' => 'نقدی در محل'], ['icon' => '💳', 'text' => 'کارت‌خوان سیار'], ['icon' => '📲', 'text' => 'انتقال کارت‌به‌کارت'], ['icon' => '🧾', 'text' => 'فاکتور رسمی']]]],
        'gift-card' => ['🎀', 'کارت هدیه سرویس', ['renderType' => 'banner', 'title' => '🎁 کارت هدیه تعمیر', 'subtitle' => 'هدیه‌ای کاربردی برای عزیزانتان — از ۵۰۰ هزار تومان', 'btnText' => 'سفارش کارت هدیه', 'items' => [['icon' => '🎨', 'text' => 'طرح دلخواه', 'desc' => 'چاپ اختصاصی'], ['icon' => '♻️', 'text' => 'اعتبار ۱ ساله', 'desc' => 'قابل تمدید']]]],
    ],
    '🏅 اعتماد و اعتبار' => [
        'trust-metrics' => ['📏', 'شاخص‌های اعتماد', ['renderType' => 'stats', 'title' => 'چرا به ما اعتماد کنند؟', 'items' => [['icon' => '۱۵+', 'text' => 'سال سابقه'], ['icon' => '۵۰k', 'text' => 'تعمیر موفق'], ['icon' => '۹۸٪', 'text' => 'رضایت'], ['icon' => '۰', 'text' => 'شکایت حل‌نشده']]]],
        'partners-grid' => ['🤝', 'همکاران و شرکا', ['renderType' => 'cards', 'columns' => 4, 'title' => 'همکاران تجاری ما', 'items' => [['icon' => '🏢', 'text' => 'شرکت‌های ساختمانی'], ['icon' => '🏨', 'text' => 'هتل‌ها و رستوران‌ها'], ['icon' => '🏬', 'text' => 'مراکز خرید'], ['icon' => '🏥', 'text' => 'درمانگاه‌ها']]]],
        'awards-row' => ['🏆', 'ردیف جوایز و افتخارات', ['renderType' => 'cards', 'columns' => 3, 'title' => 'افتخارات ما', 'items' => [['icon' => '🥇', 'text' => 'برند برتر سال', 'desc' => 'رأی مشتریان ۱۴۰۲'], ['icon' => '🏆', 'text' => 'بهترین خدمات پس از فروش', 'desc' => 'نمایندگی رسمی'], ['icon' => '🎖️', 'text' => 'گواهینامه فنی', 'desc' => 'سازمان فنی و حرفه‌ای']]]],
        'case-studies' => ['📈', 'مطالعات موردی (کیس)', ['renderType' => 'features', 'title' => 'پروژه‌های شاخص', 'items' => [['icon' => '🏨', 'text' => 'تعمیر ۴۰ دستگاه هتل ...', 'desc' => 'در ۵ روز کاری — با قرارداد رسمی'], ['icon' => '🏬', 'text' => 'سرویس دوره‌ای مرکز خرید', 'desc' => 'ماهانه ۲۵ دستگاه'], ['icon' => '🏥', 'text' => 'راه‌اندازی آشپزخانه درمانگاه', 'desc' => 'تحویل فوری و ضمانت‌دار']]]],
        'success-stories' => ['🌟', 'داستان‌های موفقیت', ['renderType' => 'features', 'title' => 'از زبان مشتریان', 'items' => [['icon' => '❄️', 'text' => 'یخچال ۱۵ ساله دوباره جوان شد', 'desc' => 'خانم احمدی — تهران'], ['icon' => '📺', 'text' => 'تلویزیون از مرجوعی نجات یافت', 'desc' => 'آقای کریمی — کرج']]]],
        'video-testimonials' => ['🎬', 'نظرات ویدیویی مشتریان', ['renderType' => 'cards', 'columns' => 3, 'title' => 'مشتریان ما چه می‌گویند', 'items' => [['icon' => '▶️', 'text' => 'رضایت از سرویس لباسشویی', 'desc' => '۱:۳۰ دقیقه'], ['icon' => '▶️', 'text' => 'تجربه تعمیر فوری', 'desc' => '۲:۱۰ دقیقه'], ['icon' => '▶️', 'text' => 'پشتیبانی عالی', 'desc' => '۱:۴۵ دقیقه']]]],
        'licenses-grid' => ['📜', 'مجوزها و گواهینامه‌ها', ['renderType' => 'cards', 'columns' => 3, 'title' => 'مجوزهای رسمی', 'items' => [['icon' => '📋', 'text' => 'پروانه کسب اتحادیه', 'desc' => 'شماره ثبت ۱۲۳۴۵'], ['icon' => '🛡️', 'text' => 'بیمه مسئولیت', 'desc' => 'پوشش کامل حوادث'], ['icon' => '🔬', 'text' => 'گواهی تخصص برد', 'desc' => 'مدرک بین‌المللی']]]],
        'satisfaction-score' => ['💯', 'امتیاز رضایت درشت', ['renderType' => 'stats', 'title' => 'امتیاز رضایت مشتریان', 'items' => [['icon' => '۴٫۸', 'text' => 'از ۵ — نظرسنجی مستقل'], ['icon' => '۲٬۱۴۰', 'text' => 'رأی ثبت‌شده']]]],
    ],
    '🎯 بازاریابی' => [
        'lead-magnet' => ['🧲', 'آهنربای مشتری (راهنمای رایگان)', ['renderType' => 'banner', 'title' => '📚 راهنمای رایگان نگهداری دستگاه', 'subtitle' => '۳۰ صفحه نکات طلایی + چک‌لیست سرویس دوره‌ای — ایمیلتان را وارد کنید', 'btnText' => 'دریافت رایگان', 'items' => [['icon' => '📚', 'text' => 'PDF 30 صفحه', 'desc' => 'دانلود فوری'], ['icon' => '🔒', 'text' => 'بدون اسپم', 'desc' => 'احترام کامل']]]],
        'webinar-card' => ['🖥', 'کارت وبینار/رویداد', ['renderType' => 'banner', 'title' => '🎥 وبینار رایگان: افزایش عمر لوازم خانگی', 'subtitle' => 'پنجشنبه ساعت ۱۸ — همراه با پرسش و پاسخ زنده', 'btnText' => 'ثبت‌نام وبینار', 'items' => [['icon' => '🎥', 'text' => 'آنلاین و زنده', 'desc' => 'لینک اختصاصی'], ['icon' => '📜', 'text' => 'گواهی حضور', 'desc' => 'قابل دانلود']]]],
        'free-audit' => ['🔍', 'پیشنهاد بررسی رایگان', ['renderType' => 'banner', 'title' => '🩺 چکاپ رایگان دستگاه شما', 'subtitle' => 'کارشناس ما وضعیت دستگاه را بررسی و صورت‌حساب شفاف می‌دهد — بدون تعهد', 'btnText' => 'رزرو چکاپ رایگان', 'items' => [['icon' => '🩺', 'text' => 'کاملاً رایگان', 'desc' => 'بدون تعهد خرید'], ['icon' => '🧾', 'text' => 'گزارش کتبی', 'desc' => 'با قیمت شفاف']]]],
        'trial-offer' => ['🆓', 'پیشنهاد تست/ضمانت بازگشت', ['renderType' => 'banner', 'title' => '💚 ۷ روز ضمانت بازگشت وجه', 'subtitle' => 'اگر از سرویس راضی نبودید، هزینه برمی‌گردد — بدون سوال', 'btnText' => 'اطمینان از خرید', 'items' => [['icon' => '💚', 'text' => '۷ روز مهلت', 'desc' => 'بازگشت کامل'], ['icon' => '🤝', 'text' => 'بدون قید و شرط', 'desc' => 'حرف ما سند ما']]]],
        'bundle-offer' => ['📦', 'پکیج ترکیبی خدمات', ['renderType' => 'price', 'title' => 'پکیج صرفه‌جویی خانواده', 'items' => [['text' => 'سرویس ۲ دستگاه', 'desc' => '۱۵٪ ارزان‌تر از تکی'], ['text' => 'سرویس ۳ دستگاه', 'desc' => '۲۵٪ ارزان‌تر + اولویت اعزام'], ['text' => 'سرویس ۵ دستگاه', 'desc' => '۳۵٪ ارزان‌تر + بازدید فصلی رایگان']]]],
        'membership-tiers' => ['👑', 'سطوح عضویت', ['renderType' => 'cards', 'columns' => 3, 'title' => 'باشگاه مشتریان', 'items' => [['icon' => '🥉', 'text' => 'برنزی', 'desc' => '۵٪ تخفیف دائمی'], ['icon' => '🥈', 'text' => 'نقره‌ای', 'desc' => '۱۰٪ تخفیف + سرویس رایگان سالانه'], ['icon' => '🥇', 'text' => 'طلایی', 'desc' => '۱۵٪ تخفیف + اعزام VIP اولویت‌دار']]]],
        'loyalty-program' => ['⭐', 'برنامه وفاداری', ['renderType' => 'steps', 'title' => 'هر تعمیر = امتیاز هدیه', 'items' => [['text' => 'ثبت سفارش', 'desc' => '۱۰ امتیاز'], ['text' => 'معرفی دوست', 'desc' => '۵۰ امتیاز'], ['text' => 'سرویس دوره‌ای', 'desc' => '۲۰۰ امتیاز'], ['text' => 'دریافت هدیه', 'desc' => 'از ۵۰۰ امتیاز']]]],
        'referral-program' => ['👥', 'برنامه معرفی دوستان', ['renderType' => 'banner', 'title' => '🤝 دوستتان را معرفی کنید — هر دو برنده شوید', 'subtitle' => 'شما ۲۰۰ هزار تومان اعتبار، دوستتان ۱۵٪ تخفیف اولین سرویس', 'btnText' => 'کد معرفی بگیرم', 'items' => [['icon' => '💰', 'text' => '۲۰۰ هزار', 'desc' => 'اعتبار شما'], ['icon' => '🎁', 'text' => '۱۵٪', 'desc' => 'تخفیف دوست']]]],
    ],
    '📞 پشتیبانی' => [
        'support-channels' => ['🛟', 'کانال‌های پشتیبانی', ['renderType' => 'cards', 'columns' => 4, 'title' => 'همیشه در دسترس', 'items' => [['icon' => '☎️', 'text' => 'تلفن', 'desc' => 'پاسخ فوری'], ['icon' => '💬', 'text' => 'چت آنلاین', 'desc' => 'در سایت'], ['icon' => '✉️', 'text' => 'ایمیل', 'desc' => 'زیر ۲۴ ساعت'], ['icon' => '📨', 'text' => 'پیام‌رسان', 'desc' => 'پاسخ سریع']]]],
        'ticket-status' => ['🎫', 'پیگیری تیکت/درخواست', ['renderType' => 'banner', 'title' => '🔎 وضعیت درخواست خود را ببینید', 'subtitle' => 'کد رهگیری را وارد کنید و آخرین وضعیت تعمیر را دنبال کنید', 'btnText' => 'پیگیری درخواست', 'items' => [['icon' => '🎫', 'text' => 'کد رهگیری', 'desc' => 'در پیامک'], ['icon' => '⏱', 'text' => 'به‌روز زنده', 'desc' => 'لحظه‌ای']]]],
        'knowledge-base' => ['📚', 'مرکز دانش و راهنما', ['renderType' => 'cards', 'columns' => 3, 'title' => 'خودتان عیب‌یابی کنید', 'items' => [['icon' => '🔢', 'text' => 'دیکشنری کد خطا', 'desc' => 'معنی هر کد + راه‌حل'], ['icon' => '🔧', 'text' => 'آموزش‌های تصویری', 'desc' => 'گام‌به‌گام'], ['icon' => '❓', 'text' => 'سوالات متداول', 'desc' => 'پاسخ کوتاه']]]],
        'downloads-center' => ['⬇️', 'مرکز دانلود', ['renderType' => 'features', 'title' => 'دانلود فایل‌های مفید', 'items' => [['icon' => '📖', 'text' => 'دفترچه راهنمای دستگاه‌ها', 'desc' => 'PDF — همه برندها'], ['icon' => '🧾', 'text' => 'چک‌لیست سرویس دوره‌ای', 'desc' => 'قابل چاپ'], ['icon' => '📅', 'text' => 'تقویم نگهداری سالانه', 'desc' => 'دانلود رایگان']]]],
        'live-chat-card' => ['💬', 'کارت گفتگوی زنده', ['renderType' => 'banner', 'title' => '💬 همین حالا با کارشناس چت کنید', 'subtitle' => 'میانگین زمان پاسخ: کمتر از ۲ دقیقه — بدون نیاز به ثبت‌نام', 'btnText' => 'شروع گفتگو', 'items' => [['icon' => '⚡', 'text' => 'پاسخ < ۲ دقیقه', 'desc' => 'کارشناس واقعی'], ['icon' => '🕐', 'text' => '۷ روز هفته', 'desc' => '۹ تا ۲۴']]]],
        'support-hours' => ['🕘', 'ساعات پشتیبانی', ['renderType' => 'features', 'title' => 'چه زمانی در دسترس هستیم؟', 'items' => [['icon' => '🌅', 'text' => 'شیفت صبح', 'desc' => '۹ تا ۱۴ — تعمیرات عادی'], ['icon' => '🌆', 'text' => 'شیفت عصر', 'desc' => '۱۴ تا ۲۰ — تعمیرات عادی'], ['icon' => '🌙', 'text' => 'امداد شبانه', 'desc' => '۲۰ تا ۹ فردا — موارد فوری']]]],
        'sla-guarantee' => ['⏱', 'تعهد سطح خدمات (SLA)', ['renderType' => 'stats', 'title' => 'تعهد ما در اعداد', 'items' => [['icon' => '۲ ساعت', 'text' => 'اعزام در تهران'], ['icon' => '۴ ساعت', 'desc' => 'حداکثر عیب‌یابی', 'text' => 'اعلام نتیجه'], ['icon' => '۹۸٪', 'text' => 'تعمیر همان روز'], ['icon' => '۲۴/۷', 'text' => 'خط امداد']]]],
        'remote-support' => ['📡', 'پشتیبانی راه دور (تلفنی)', ['renderType' => 'features', 'title' => 'بدون مراجعه هم حل می‌شود!', 'items' => [['icon' => '📞', 'text' => 'راهنمایی تلفنی', 'desc' => 'برای ایرادهای ساده — رایگان'], ['icon' => '🎥', 'text' => 'تماس تصویری', 'desc' => 'کارشناس دوربین را می‌بیند'], ['icon' => '🔢', 'text' => 'راهنمای کد خطا', 'desc' => 'پیامکی و آنلاین']]]],
    ],
    '📊 داده و وضعیت' => [
        'status-board' => ['🚦', 'تابلوی وضعیت خدمات', ['renderType' => 'stats', 'title' => 'وضعیت امروز سرویس‌ها', 'items' => [['icon' => '🟢', 'text' => 'لباسشویی — فعال'], ['icon' => '🟢', 'text' => 'یخچال — فعال'], ['icon' => '🟡', 'text' => 'ظرفشویی — ظرفیت محدود'], ['icon' => '🟢', 'text' => 'تلویزیون — فعال']]]],
        'inventory-status' => ['📦', 'وضعیت موجودی قطعات', ['renderType' => 'features', 'title' => 'قطعات موجود امروز', 'items' => [['icon' => '✅', 'text' => 'بلبرینگ و آب‌بندی', 'desc' => 'موجود — تحویل فوری'], ['icon' => '✅', 'text' => 'المنت و هیتر', 'desc' => 'موجود — همه برندها'], ['icon' => '⏳', 'text' => 'برد الکترونیک', 'desc' => 'سفارش ۴۸ ساعته']]]],
        'queue-display' => ['📋', 'نمایش صف فعلی', ['renderType' => 'stats', 'title' => 'صف تعمیر امروز', 'items' => [['icon' => '۷', 'text' => 'در نوبت'], ['icon' => '۲', 'text' => 'در حال تعمیر'], ['icon' => '۱۴', 'text' => 'تحویل‌شده امروز'], ['icon' => '۴۵ دقیقه', 'text' => 'میانگین انتظار']]]],
        'weather-info' => ['🌤', 'نکته آب‌وهوایی سرویس', ['renderType' => 'chips', 'title' => 'امروز چه خبر؟', 'items' => [['text' => '🌡 هوای گرم — فشار روی کولر‌ها زیاد است'], ['text' => '❄️ پیش‌فصل سرویس کولر را رزرو کنید'], ['text' => '🧺 روز عالی برای شست‌وشوی لباسشویی']]]],
        'capacity-meter' => ['📊', 'متر ظرفیت امروز', ['renderType' => 'stats', 'title' => 'ظرفیت اعزام تکنسین', 'items' => [['icon' => '۸۵٪', 'text' => 'ظرفیت امروز پر شده'], ['icon' => '۵', 'text' => 'نوبت باقی‌مانده'], ['icon' => '۲ دقیقه', 'text' => 'زمان ثبت']]]],
        'open-closed' => ['🟢', 'نشان باز/بسته بودن', ['renderType' => 'banner', 'title' => '🟢 همین حالا باز هستیم', 'subtitle' => 'پاسخگویی تلفنی و اعزام فوری — تا ۲۰ امشب', 'btnText' => 'تماس همین حالا', 'items' => [['icon' => '🟢', 'text' => 'باز', 'desc' => 'تا ۲۰:۰۰'], ['icon' => '🚑', 'text' => 'امداد ۲۴ ساعته', 'desc' => 'همیشه']]]],
        'service-coverage' => ['🗺', 'پوشش خدمات روی نقشه', ['renderType' => 'chips', 'title' => 'مناطق تحت پوشش امروز', 'items' => [['text' => 'تهران — همه مناطق'], ['text' => 'کرج — حصارک تا مهرشهر'], ['text' => 'شهریار — با هزینه ایاب‌وذهاب']]]],
        'stats-live' => ['🔴', 'آمار زنده خدمات', ['renderType' => 'stats', 'title' => 'لحظه به لحظه با ما', 'items' => [['icon' => '۱۲', 'text' => 'تعمیر در حال انجام'], ['icon' => '۳', 'text' => 'تکنسین در راه'], ['icon' => '۹۸٪', 'text' => 'رضایت امروز']]]],
    ],
    '🎨 دکوراتیو' => [
        'gradient-banner' => ['🌈', 'بنر گرادیانت تزئینی', ['renderType' => 'banner', 'background' => 'gradient', 'title' => 'زیبایی در سادگی', 'subtitle' => 'این بنر با رنگ‌های گرادیانت قابل تنظیم شما می‌درخشد', 'btnText' => 'اطمینان از کیفیت']],
        'icon-matrix' => ['🔢', 'ماتریس آیکون تزئینی', ['renderType' => 'cards', 'columns' => 6, 'title' => 'نمادهای خدمات', 'items' => [['icon' => '🌀'], ['icon' => '🧊'], ['icon' => '🍽️'], ['icon' => '📺'], ['icon' => '❄️'], ['icon' => '🔥'], ['icon' => '♨️'], ['icon' => '📻'], ['icon' => '☕'], ['icon' => '🌪'], ['icon' => '💧'], ['icon' => '🔌']]]],
        'big-number' => ['🔟', 'عدد درشت تزئینی', ['renderType' => 'stats', 'items' => [['icon' => '۵۰٬۰۰۰+', 'text' => 'تعمیر موفق از سال ۱۳۸۹']]]],
        'quote-typography' => ['✍️', 'نقل‌قول تایپوگرافیک', ['renderType' => 'quote', 'title' => 'فلسفه ما', 'text' => 'هر دستگاه، اعتماد یک خانواده است — و اعتماد، فقط با کیفیت پاسخ داده می‌شود.']],
        'pattern-strip' => ['♓', 'نوار الگودار تزئینی', ['renderType' => 'chips', 'items' => [['text' => '✦ ✦ ✦ ✦ ✦ ✦ ✦ ✦ ✦ ✦ ✦ ✦']]]],
        'shape-divider' => ['⛰', 'جداکننده موجی', ['renderType' => 'divider', 'items' => [['text' => '〰️〰️〰️〰️〰️〰️〰️〰️〰️〰️〰️〰️']]]],
        'decorative-frame' => ['🖼', 'قاب تزئینی محتوا', ['renderType' => 'features', 'title' => 'محتوای ویژه در قاب', 'items' => [['icon' => '✨', 'text' => 'این قاب دور محتوا', 'desc' => 'با تنظیمات ظاهر (شیشه‌ای/خط‌دار/...) شخصی شود']]]],
        'color-showcase' => ['🎨', 'نمایش پالت رنگ', ['renderType' => 'cards', 'columns' => 5, 'title' => 'رنگ‌های سازمانی ما', 'items' => [['icon' => '🔵', 'text' => 'آبی اعتماد'], ['icon' => '🟢', 'text' => 'سبز تازگی'], ['icon' => '🟠', 'text' => 'نارنجی انرژی'], ['icon' => '⚫', 'text' => 'مشکی شکوه'], ['icon' => '⚪', 'text' => 'سفید سادگی']]]],
    ],
    '👤 کسب‌وکار' => [
        'about-timeline' => ['📅', 'خط زمانی شرکت', ['renderType' => 'steps', 'title' => 'مسیر رشد ما', 'items' => [['text' => '۱۳۸۹', 'desc' => 'شروع با یک تعمیرگاه کوچک'], ['text' => '۱۳۹۵', 'desc' => 'اولین نمایندگی رسمی'], ['text' => '۱۴۰۰', 'desc' => 'گسترش به ۳۰ تکنسین'], ['text' => '۱۴۰۴', 'desc' => '۵۰ هزارمین تعمیر موفق']]]],
        'mission-vision' => ['🎯', 'مأموریت و چشم‌انداز', ['renderType' => 'features', 'title' => 'چرا وجود داریم؟', 'items' => [['icon' => '🎯', 'text' => 'مأموریت', 'desc' => 'تعمیر قابل‌اعتماد برای هر خانواده ایرانی'], ['icon' => '🔭', 'text' => 'چشم‌انداز', 'desc' => 'استاندارد طلایی خدمات پس از فروش کشور'], ['icon' => '💎', 'text' => 'ارزش‌ها', 'desc' => 'صداقت، تخصص، احترام']]]],
        'careers-jobs' => ['💼', 'فرصت‌های شغلی', ['renderType' => 'cards', 'columns' => 3, 'title' => 'به تیم ما بپیوندید', 'items' => [['icon' => '👨‍🔧', 'text' => 'تکنسین تعمیرکار', 'desc' => 'تمام وقت — تهران'], ['icon' => '☎️', 'text' => 'کارشناس پشتیبانی', 'desc' => 'شیفت چرخشی'], ['icon' => '🚗', 'text' => 'راننده ویدرو', 'desc' => 'پاره وقت']]]],
        'press-reviews' => ['📰', 'نگاه رسانه‌ها', ['renderType' => 'quote', 'title' => 'رسانه‌ها درباره ما', 'text' => '«این مجموعه نشان داد خدمات پس از فروش می‌تواند هم حرفه‌ای باشد و هم صادقانه.» — هفته‌نامه فنی کشور']],
        'company-size' => ['🏢', 'معرفی ابعاد شرکت', ['renderType' => 'stats', 'title' => 'سهند سرویس در یک نگاه', 'items' => [['icon' => '۳۲', 'text' => 'تکنسین متخصص'], ['icon' => '۴', 'text' => 'مرکز خدمات'], ['icon' => '۱۵', 'text' => 'سال تجربه'], ['icon' => '۲۴/۷', 'text' => 'پشتیبانی']]]],
        'csr-activities' => ['🌱', 'مسئولیت اجتماعی', ['renderType' => 'features', 'title' => 'دست‌دادن به جامعه', 'items' => [['icon' => '♻️', 'text' => 'بازیافت قطعات', 'desc' => '۹۰٪ قطعات فرسوده بازیافت می‌شود'], ['icon' => '🎓', 'text' => 'آموزش کارآموز', 'desc' => 'هر سال ۲۰ کارآموز آموزش‌دیده'], ['icon' => '🤲', 'text' => 'سرویس خیریه', 'desc' => 'ماهانه ۵ خانواده نیازمند']]]],
        'team-culture' => ['🌿', 'فرهنگ تیم', ['renderType' => 'chips', 'title' => 'چطور با هم کار می‌کنیم؟', 'items' => [['text' => '🎯 هدف مشترک: مشتری راضی'], ['text' => '📚 یادگیری هفتگی'], ['text' => '🤝 بازخورد صادقانه'], ['text' => '🎉 جشن موفقیت‌ها']]]],
        'history-quick' => ['⏪', 'خلاصه تاریخچه', ['renderType' => 'quote', 'title' => 'از ۱۳۸۹ تا امروز', 'text' => 'از یک میز کار کوچک در گوشه شهر تا بزرگ‌ترین تیم تخصصی تعمیر لوازم خانگی منطقه — همه با اعتماد شما.']],
    ],
    '🔧 خدمات فنی' => [
        'repair-process' => ['🔧', 'فرآیند تعمیر گام‌به‌گام', ['renderType' => 'steps', 'title' => 'دستگاه شما چه می‌گذرد؟', 'items' => [['text' => 'دریافت و ثبت', 'desc' => 'برچسب رهگیری'], ['text' => 'عیب‌یابی کامل', 'desc' => 'با دستگاه تست'], ['text' => 'تعمیر تخصصی', 'desc' => 'قطعه اصلی'], ['text' => 'کنترل کیفیت', 'desc' => 'تست ۲۴ ساعته'], ['text' => 'تحویل + ضمانت', 'desc' => 'سند رسمی']]]],
        'diagnostics-steps' => ['🩺', 'مراحل عیب‌یابی', ['renderType' => 'features', 'title' => 'چطور ایراد را پیدا می‌کنیم؟', 'items' => [['icon' => '🔌', 'text' => 'تست برق و اتصالات', 'desc' => 'اولویت ایمنی'], ['icon' => '💻', 'text' => 'دیاگ برد و سنسورها', 'desc' => 'با دستگاه دیاگ'], ['icon' => '🔊', 'text' => 'بررسی صدا و لرزش', 'desc' => 'تجربه ۱۵ ساله']]]],
        'spare-parts' => ['⚙️', 'قطعات یدکی اصلی', ['renderType' => 'cards', 'columns' => 4, 'title' => 'قطعاتی که استفاده می‌کنیم', 'items' => [['icon' => '✅', 'text' => 'اصلی کارخانه', 'desc' => 'با فاکتور'], ['icon' => '🛡️', 'text' => 'ضمانت ۶ ماهه', 'desc' => 'قطعه + نصب'], ['icon' => '📦', 'text' => 'موجودی انبار', 'desc' => 'تحویل فوری'], ['icon' => '🔍', 'text' => 'قابل استعلام', 'desc' => 'قیمت شفاف']]]],
        'tool-showcase' => ['🧰', 'ابزار و تجهیزات', ['renderType' => 'chips', 'title' => 'با بهترین ابزار کار می‌کنیم', 'items' => [['text' => '🔬 میکروسکوپ برد'], ['text' => '⚡ تستر عایقی'], ['text' => '🌡 مانیفولد گاز'], ['text' => '💻 دیاگ حرفه‌ای']]]],
        'technician-profile' => ['👨‍🔧', 'پروفایل تکنسین', ['renderType' => 'features', 'title' => 'تکنسین شما چه کسی است؟', 'items' => [['icon' => '🪪', 'text' => 'کارت شناسایی', 'desc' => 'با عکس و کد'], ['icon' => '🎓', 'text' => 'مدرک فنی', 'desc' => 'قابل استعلام'], ['icon' => '⭐', 'text' => 'امتیاز مشتریان', 'desc' => '۴٫۸ از ۵']]]],
        'service-packages' => ['📦', 'بسته‌های خدماتی', ['renderType' => 'price', 'title' => 'کدام بسته مناسب شماست؟', 'items' => [['text' => 'بسته امداد فوری', 'desc' => 'عیب‌یابی + تعمیر تا ۲ ساعت'], ['text' => 'بسته سرویس کامل', 'desc' => 'شست‌وشو + تنظیم + گارانتی ۶ ماهه'], ['text' => 'بسته سازمانی', 'desc' => 'قرارداد سالانه با اولویت']]]],
        'maintenance-plan' => ['🗓', 'برنامه نگهداری پیشگیرانه', ['renderType' => 'steps', 'title' => 'سرویس دوره‌ای = عمر بیشتر', 'items' => [['text' => 'هر ۶ ماه', 'desc' => 'لباسشویی و ظرفشویی'], ['text' => 'سالانه', 'desc' => 'یخچال و فریزر'], ['text' => 'فصلی', 'desc' => 'کولر و پکیج']]]],
        'emergency-protocol' => ['🚨', 'پروتکل اضطراری', ['renderType' => 'features', 'title' => 'اگر وضعیت اضطراری است', 'items' => [['icon' => '🔌', 'text' => 'برق را قطع کنید', 'desc' => 'اول ایمنی'], ['icon' => '💧', 'text' => 'شیر آب را ببندید', 'desc' => 'جلوگیری از سیل'], ['icon' => '🚱', 'text' => 'دست نزنید', 'desc' => 'منتظر تکنسین بمانید']]]],
    ],
    /* ═══ 🆕 v2.31 — عناصر پیشرفته: انواع دکمه، نوار پیشرفت چندرنگ، گردونه ═══ */
    '🔘 دکمه‌ها' => [
        'btn-duo' => ['🔘', 'جفت دکمه اصلی + ثانویه', ['renderType' => 'buttons', 'title' => '', 'items' => [['icon' => '📞', 'text' => 'تماس فوری', 'desc' => 'primary', 'link' => ''], ['icon' => '📝', 'text' => 'ثبت درخواست', 'desc' => 'ghost', 'link' => '/request']]]],
        'btn-gradient' => ['🌈', 'دکمه گرادیانت بزرگ', ['renderType' => 'buttons', 'items' => [['icon' => '🚀', 'text' => 'همین حالا سفارش دهید', 'desc' => 'gradient', 'link' => '/request']]]],
        'btn-outline-row' => ['🔲', 'ردیف دکمه‌های خطی', ['renderType' => 'buttons', 'items' => [['icon' => '🔧', 'text' => 'تعمیر', 'desc' => 'outline', 'link' => '/services'], ['icon' => '🛠', 'text' => 'سرویس دوره‌ای', 'desc' => 'outline', 'link' => ''], ['icon' => '📖', 'text' => 'مقالات', 'desc' => 'outline', 'link' => '/blog']]]],
        'btn-icon-row' => ['🔣', 'دکمه‌های آیکون‌دار', ['renderType' => 'buttons', 'items' => [['icon' => '📱', 'text' => 'شبکه‌های اجتماعی', 'desc' => 'primary', 'link' => ''], ['icon' => '💬', 'text' => 'چت آنلاین', 'desc' => 'ghost', 'link' => ''], ['icon' => '📞', 'text' => 'تماس', 'desc' => 'ghost', 'link' => 'tel:']]]],
        'btn-mega-cta' => ['🎯', 'دکمه CTA بزرگ + نشان', ['renderType' => 'buttons', 'title' => 'آماده سفارش هستید؟', 'subtitle' => 'پاسخگویی ۷ روز هفته — اعزام فوری تکنسین', 'items' => [['icon' => '⚡', 'text' => 'سفارش آنلاین تعمیر', 'desc' => 'primary', 'link' => '/request']]]],
        'btn-social' => ['📲', 'دکمه‌های شبکه‌های اجتماعی', ['renderType' => 'buttons', 'items' => [['icon' => '📸', 'text' => 'اینستاگرام', 'desc' => 'outline', 'link' => 'https://instagram.com/'], ['icon' => '✈️', 'text' => 'تلگرام', 'desc' => 'outline', 'link' => 'https://t.me/'], ['icon' => '💬', 'text' => 'واتساپ', 'desc' => 'outline', 'link' => 'https://wa.me/']]]],
    ],
    '📊 پیشرفت چندرنگ' => [
        'progress-multi' => ['📊', 'نوارهای پیشرفت چندرنگ (هر نوار رنگ خودش)', ['renderType' => 'progress', 'title' => 'عملکرد خدمات ما', 'items' => [['text' => 'سرعت تعمیر', 'desc' => '92', 'color' => '#1e40af'], ['text' => 'کیفیت قطعات', 'desc' => '96', 'color' => '#16a34a'], ['text' => 'رضایت مشتری', 'desc' => '98', 'color' => '#f59e0b'], ['text' => 'تحویل به‌موقع', 'desc' => '94', 'color' => '#7c3aed']]]],
        'progress-striped' => ['▰', 'نوارهای راه‌راه متحرک', ['renderType' => 'progress', 'title' => 'پیشرفت پروژه‌ها', 'items' => [['text' => 'نصب پکیج ساختمان A', 'desc' => '78', 'color' => '#0ea5e9'], ['text' => 'سرویس فروشگاه مرکزی', 'desc' => '55', 'color' => '#f97316'], ['text' => 'تعمیرات ست اداری', 'desc' => '90', 'color' => '#10b981']], 'striped' => 1]],
        'progress-thin' => ['▬', 'نوارهای باریک مینیمال', ['renderType' => 'progress', 'title' => 'شاخص‌های کیفیت', 'items' => [['text' => 'دقت عیب‌یابی', 'desc' => '97', 'color' => '#1e40af'], ['text' => 'قطعات اصلی', 'desc' => '100', 'color' => '#0d9488'], ['text' => 'گارانتی واقعی', 'desc' => '99', 'color' => '#7c3aed']]]],
        'progress-circles' => ['⭕', 'گردونه‌های درصدی چندرنگ', ['renderType' => 'wheels', 'title' => 'تخصص ما در یک نگاه', 'items' => [['text' => 'لباسشویی', 'desc' => '95', 'color' => '#1e40af'], ['text' => 'یخچال', 'desc' => '90', 'color' => '#16a34a'], ['text' => 'تلویزیون', 'desc' => '88', 'color' => '#f59e0b'], ['text' => 'ظرفشویی', 'desc' => '92', 'color' => '#db2777']]]],
        'progress-ring-big' => ['💠', 'حلقه بزرگ تک‌مقدار', ['renderType' => 'gauge', 'title' => 'رضایت کلی مشتریان', 'items' => [['text' => 'رضایت مشتریان', 'desc' => '96', 'color' => '#16a34a']], 'gaugeText' => 'از ۱۰,۰۰۰ نظر ثبت‌شده']],
        'progress-semi' => ['🌗', 'نیم‌گردونه‌های درصدی', ['renderType' => 'wheels', 'title' => 'سهم خدمات', 'semi' => 1, 'items' => [['text' => 'تعمیر در محل', 'desc' => '62', 'color' => '#1e40af'], ['text' => 'حمل به کارگاه', 'desc' => '28', 'color' => '#0ea5e9'], ['text' => 'مشاوره تلفنی', 'desc' => '10', 'color' => '#94a3b8']]]],
    ],
    '🆕 عناصر کاربردی' => [
        'rating-hero' => ['⭐', 'امتیاز ستاره‌ای بزرگ', ['renderType' => 'stats', 'title' => 'امتیاز مشتریان', 'items' => [['icon' => '۴.۹', 'text' => 'از ۵ — از ۲,۴۰۰ نظر'], ['icon' => '⭐⭐⭐⭐⭐', 'text' => 'کیفیت خدمات'], ['icon' => '۹۸٪', 'text' => 'توصیه به دوستان']]]],
        'info-tiles' => ['🧩', 'کاشی‌های اطلاعاتی رنگی', ['renderType' => 'cards', 'columns' => 4, 'title' => 'سهند سرویس در یک نگاه', 'items' => [['icon' => '🕐', 'text' => 'پاسخگویی', 'desc' => '۷ روز هفته'], ['icon' => '🚀', 'text' => 'اعزام', 'desc' => 'کمتر از ۲ ساعت'], ['icon' => '🛡️', 'text' => 'ضمانت', 'desc' => '۶ ماه کتبی'], ['icon' => '💰', 'text' => 'پرداخت', 'desc' => 'پس از رضایت']]]],
        'feature-split' => ['⚡', 'ویژگی‌های دو ستونه', ['renderType' => 'features', 'title' => 'چرا سهند سرویس؟', 'items' => [['icon' => '🎯', 'text' => 'عیب‌یابی دقیق', 'desc' => 'با دستگاه‌های تست تخصصی'], ['icon' => '🔩', 'text' => 'قطعات فابریک', 'desc' => 'با فاکتور رسمی'], ['icon' => '⏱', 'text' => 'تعمیر همان روز', 'desc' => 'در ۸۰٪ موارد'], ['icon' => '🧾', 'text' => 'پیش‌فاکتور شفاف', 'desc' => 'قبل از شروع کار']]]],
        'hover-cards' => ['🖱', 'کارت‌های هاوردار تعاملی', ['renderType' => 'cards', 'columns' => 3, 'title' => 'خدمات ویژه', 'items' => [['icon' => '🌀', 'text' => 'تعمیر لباسشویی', 'desc' => 'همه برندها — همان روز'], ['icon' => '🧊', 'text' => 'تعمیر یخچال', 'desc' => 'شارژ گاز و کمپرسور'], ['icon' => '♨️', 'text' => 'سرویس پکیج', 'desc' => 'آماده‌سازی فصل سرما']]]],
        'alert-gradient' => ['🚨', 'هشدار گرادیانت', ['renderType' => 'banner', 'title' => '⚠️ سرویس فوری تعطیلات', 'subtitle' => 'تکنسین‌های ما در تعطیلات هم پاسخگو هستند', 'btnText' => 'درخواست فوری', 'items' => []]],
        'gradient-quote' => ['💬', 'نقل‌قول گرادیانت', ['renderType' => 'quote', 'title' => 'پیام مدیرعامل', 'text' => 'هدف ما این است که هر مشتری، ما را به دوستانش توصیه کند — کیفیت تعمیر، اعتبار ماست.']],
        'chips-filter' => ['🏷', 'چیپ‌های فیلتر خدمات', ['renderType' => 'chips', 'title' => 'دسته‌بندی سریع', 'items' => [['icon' => '🌀', 'text' => 'لباسشویی'], ['icon' => '🧊', 'text' => 'یخچال'], ['icon' => '🍽️', 'text' => 'ظرفشویی'], ['icon' => '📺', 'text' => 'تلویزیون'], ['icon' => '❄️', 'text' => 'کولر'], ['icon' => '♨️', 'text' => 'پکیج']]]],
        'counter-cards' => ['🔢', 'کارت‌های شمارنده', ['renderType' => 'stats', 'title' => 'دستاوردها', 'items' => [['icon' => '۱۵+', 'text' => 'سال تجربه'], ['icon' => '۵۰k', 'text' => 'تعمیر موفق'], ['icon' => '۴۲', 'text' => 'تخصص دستگاه'], ['icon' => '۲۴/۷', 'text' => 'پشتیبانی']]]],
    ],
];

/* 🧩 v2.12: بلوک‌های ترکیبی ذخیره‌شده کاربر (از جدول builder_blocks)
   v3.3: پشتیبانی ترکیب‌های گروهی (چند بلوک باهم) — تعداد بلوک هر ترکیب محاسبه می‌شود */
$savedBlocks = [];
try {
    $savedBlocks = $db->fetchAll('SELECT id, name, category, block_json FROM builder_blocks ORDER BY updated_at DESC, id DESC LIMIT 60');
} catch (Throwable $sbE) {
    $savedBlocks = [];
}
$savedCounts = [];
foreach ($savedBlocks as $sb) {
    $j = json_decode((string)$sb['block_json'], true);
    if (is_array($j) && isset($j['type']) && $j['type'] === 'group' && is_array($j['blocks'] ?? null)) {
        $savedCounts[$sb['id']] = count($j['blocks']);
    } elseif (is_array($j) && array_is_list($j)) {
        $savedCounts[$sb['id']] = count($j);
    } else {
        $savedCounts[$sb['id']] = 1;
    }
}
$totalBlockCount = array_sum(array_map('count', $blockLibrary));

/* 🧬 v2.29 — کلیدهای عناصر عمومی (دارای renderType) برای فیلدهای خودکار JS */
$genericBlockKeys = [];
foreach ($blockLibrary as $gCat) {
    foreach ($gCat as $gKey => $gDef) {
        if (isset($gDef[2]['renderType'])) { $genericBlockKeys[$gKey] = true; }
    }
}

/* ⭐ v2.26: عناصر شخصی استخراج‌شده از سایت‌ها — کتابخانه قابل درج در چیدمان */
$personalElements = [];
try {
    $personalElements = $db->fetchAll('SELECT id, name, element_type, html, css, source_url FROM personal_elements ORDER BY id DESC LIMIT 80');
} catch (Throwable $peE) {
    $personalElements = [];
}
?>
<link rel="stylesheet" href="<?= asset_ver('assets/css/builder.css') ?>">
<?= preview_font_html() /* 🔤 v2.14: فونت انتخابی سیستم برای بوم و کارت‌های عناصر */ ?>
<link rel="stylesheet" href="../assets/css/template-builder.css?v=2.36">

<form method="post" id="builder-form">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="save_template">
    <input type="hidden" name="template_id" value="<?= (int)($template['id'] ?? 0) ?>">
    <?php if ($brandPage): /* 🆕 v2.21: ذخیره مستقیم روی صفحه برند */ ?>
    <input type="hidden" name="brand_page_id" value="<?= (int)$brandPage['id'] ?>">
    <?php endif; ?>
    <input type="hidden" name="layout_json" id="layout-json" value="<?= e(json_encode($layout, JSON_UNESCAPED_UNICODE)) ?>">

    <?php if ($brandPage): ?>
    <!-- 🆕 v2.21: نوار اطلاع حالت ویرایش صفحه برند -->
    <div class="alert alert-info" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
        <span style="font-size:20px">🎯</span>
        <div style="flex:1;min-width:200px">
            <b>حالت ویرایش صفحه برند</b> — چیدمان این قالب مستقیماً روی صفحه «<?= $bpTypeName ?>» برند <b><?= e($brandPage['brand_name']) ?></b> ذخیره می‌شود (نه در کتابخانه قالب‌های عمومی).
        </div>
        <a href="brand-edit.php?id=<?= (int)$brandPage['bid'] ?>&tab=pages" class="btn btn-outline btn-sm">↩ بازگشت به تب صفحه‌های برند</a>
    </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:16px">
        <div class="card-body" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:14px 18px">
            <?php if ($brandPage): ?>
                <div style="font-weight:800;font-size:14px;display:flex;align-items:center;gap:8px;flex-shrink:0"><?= $bpTypeName ?> <span class="badge badge-info"><?= e($brandPage['brand_name']) ?></span></div>
            <?php else: ?>
                <input type="text" name="name" class="form-control" style="max-width:240px" placeholder="نام قالب..." value="<?= e($template['name'] ?? '') ?>" required>
            <?php endif; ?>
            <select name="page_type" class="form-control" style="max-width:200px" <?= $brandPage ? 'disabled' : '' ?> title="نوع صفحه‌ای که این قالب برای آن طراحی می‌شود">
                <?php foreach ($allPageTypes as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $newPageType === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($brandPage): ?><input type="hidden" name="page_type" value="<?= e((string)$newPageType) ?>"><?php endif; ?>
            <div class="device-tabs">
                <button type="button" class="device-tab active" onclick="setDevice(this,'desktop')" title="دسکتاپ">🖥️</button>
                <button type="button" class="device-tab" onclick="setDevice(this,'tablet')" title="تبلت">📱</button>
                <button type="button" class="device-tab" onclick="setDevice(this,'mobile')" title="موبایل">📲</button>
            </div>
            <button type="button" class="btn btn-outline" id="btn-page-settings" onclick="renderPageProps()" title="تنظیمات کل صفحه: زمینه، فاصله‌ها، عرض محتوا، گردی گوشه‌ها و ...">⚙️ تنظیمات صفحه</button>
            <!-- ⏪ v2.34 — Undo/Redo قالب‌ساز -->
            <div style="display:flex;gap:0;align-items:center;border:1.5px solid var(--border);border-radius:10px;overflow:hidden">
                <button type="button" id="btn-undo" onclick="undoLayout()" disabled title="واگرد آخرین تغییر (Ctrl+Z)"
                        style="border:none;background:#fff;padding:7px 12px;cursor:pointer;font-size:15px;line-height:1">↩️</button>
                <button type="button" id="btn-redo" onclick="redoLayout()" disabled title="بازانجام (Ctrl+Y)"
                        style="border:none;border-inline-start:1.5px solid var(--border);background:#fff;padding:7px 12px;cursor:pointer;font-size:15px;line-height:1">↪️</button>
                <span id="hist-badge" style="display:none;background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:800;padding:2px 8px;min-width:24px;text-align:center" title="گام‌های قابل بازگشت">0</span>
            </div>
            <div style="margin-inline-start:auto;display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="btn btn-outline" onclick="toggleExtractPanel()" id="btn-extract-toggle" title="استخراج عناصر یک سایت دیگر همراه با استایل — پیش‌نمایش و ذخیره در عناصر شخصی">🌐 استخراج از سایت</button>
                <button type="button" class="btn btn-info" onclick="uiuxDesign()" id="btn-uiux-design" title="طراحی چیدمان حرفه‌ای با اسکیل UI/UX Pro">✨ طراحی با UI/UX Pro</button>
                <button type="button" class="btn btn-outline" onclick="uiuxReview()" id="btn-uiux-review" title="ممیزی UX چیدمان فعلی">🔍 بررسی UX</button>
                <button type="button" class="btn btn-info" onclick="openLivePreview()">👁️ پیش‌نمایش زنده</button>
                <button type="button" class="btn btn-outline" onclick="clearLayout()" title="خالی کردن بوم">🗑️ خالی‌کردن</button>
                <?php if ($brandPage): ?>
                    <a href="brand-edit.php?id=<?= (int)$brandPage['bid'] ?>&tab=pages" class="btn btn-outline">بازگشت</a>
                    <button type="submit" class="btn btn-primary" title="چیدمان روی صفحه برند ذخیره می‌شود">💾 ذخیره در صفحه برند</button>
                <?php else: ?>
                    <a href="templates.php" class="btn btn-outline">بازگشت</a>
                    <button type="submit" class="btn btn-primary">💾 ذخیره قالب</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 🎨 پنل نتایج اسکیل UI/UX Pro -->
    <div class="card" id="uiux-panel" style="display:none;margin-bottom:16px">
        <div class="card-header">
            <h3 id="uiux-panel-title">🎨 اسکیل UI/UX Pro</h3>
            <div class="tools"><button type="button" class="btn btn-outline btn-sm" onclick="closeUiuxPanel()">✕ بستن</button></div>
        </div>
        <div class="card-body" id="uiux-panel-body"></div>
    </div>

    <!-- 🌐 v2.26: پنل استخراج عناصر از سایت خارجی -->
    <div class="card" id="extract-panel" style="display:none;margin-bottom:16px">
        <div class="card-header">
            <h3>🌐 استخراج عناصر از سایت</h3>
            <div class="tools"><button type="button" class="btn btn-outline btn-sm" onclick="toggleExtractPanel(false)">✕ بستن</button></div>
        </div>
        <div class="card-body">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
                <input type="url" id="extract-url" class="form-control" style="flex:1;min-width:260px;direction:ltr;text-align:left" placeholder="https://example.com" dir="ltr">
                <button type="button" class="btn btn-primary" id="btn-extract" onclick="extractElements()">🔎 استخراج عناصر</button>
                <span class="hint" style="font-size:10.5px">دکمه‌ها، کارت‌ها، منوها، فرم‌ها و ... با استایل واقعی‌شان</span>
            </div>
            <div id="extract-status" style="display:none" class="alert" style="margin-bottom:10px"></div>
            <div id="extract-results" style="display:none">
                <div style="display:grid;grid-template-columns:minmax(280px,1fr) minmax(320px,1.2fr);gap:14px">
                    <!-- لیست عناصر -->
                    <div style="border:1px solid var(--border);border-radius:12px;overflow:hidden;max-height:520px;overflow-y:auto">
                        <div style="padding:9px 13px;background:var(--bg);font-weight:800;font-size:12px;border-bottom:1px solid var(--border)">
                            📋 عناصر کشف‌شده <span class="badge badge-info" id="extract-count" style="font-size:9.5px">۰</span>
                            <span id="extract-site-title" class="hint" style="font-weight:400;font-size:10.5px;margin-inline-start:6px"></span>
                        </div>
                        <div id="extract-list"></div>
                    </div>
                    <!-- پیش‌نمایش -->
                    <div style="border:1px solid var(--border);border-radius:12px;overflow:hidden;display:flex;flex-direction:column">
                        <div style="padding:9px 13px;background:var(--bg);font-weight:800;font-size:12px;border-bottom:1px solid var(--border)">
                            👁️ پیش‌نمایش <span id="extract-preview-name" class="hint" style="font-weight:400;font-size:10.5px">— روی نام عنصر کلیک کنید</span>
                        </div>
                        <iframe id="extract-preview-frame" sandbox="allow-same-origin" style="flex:1;min-height:440px;border:none;background:#fff" title="پیش‌نمایش عنصر"></iframe>
                        <div style="padding:9px 13px;border-top:1px solid var(--border);display:flex;gap:8px;align-items:center">
                            <button type="button" class="btn btn-success btn-sm" id="btn-save-element" onclick="saveCurrentElement()" style="display:none">➕ افزودن به عناصر شخصی</button>
                            <span class="hint" style="font-size:10px">بعد از افزودن، از دسته «⭐ عناصر شخصی من» در کتابخانه بلوک‌ها قابل استفاده است</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="builder">
        <!-- 📚 کتابخانه بلوک -->
        <aside class="block-library" id="block-library">
            <div class="block-lib-title">📚 بلوک‌ها <small style="font-weight:400;color:var(--text-light)">(دابل‌کلیک = افزودن)</small></div>

            <!-- 🎚️ v2.15: دکمه‌های نوع نمایش عناصر — ۵ حالت -->
            <div class="palette-view-toggle" id="palette-view-toggle">
                <button type="button" class="pvt-btn" data-view="list" onclick="setPaletteView('list')" title="نمایش فهرستی فشرده (پیش‌فرض)">📋 فهرستی</button>
                <button type="button" class="pvt-btn" data-view="cards" onclick="setPaletteView('cards')" title="کارت بزرگ با پیش‌نمایش واقعی زنده هر عنصر">🖼️ کارتی</button>
                <button type="button" class="pvt-btn" data-view="tiles" onclick="setPaletteView('tiles')" title="کاشی‌های دوتایی آیکون + برچسب">🔳 کاشی</button>
                <button type="button" class="pvt-btn" data-view="dense" onclick="setPaletteView('dense')" title="ردیفهای خیلی جمع‌وجور — بیشترین عنصر در کمترین فضا">🗜 فشرده</button>
                <button type="button" class="pvt-btn" data-view="icons" onclick="setPaletteView('icons')" title="فقط آیکونها در شبکه سه‌تایی">🎯 آیکون</button>
            </div>

            <!-- 🧩 v2.12: بلوک‌های ترکیبی ذخیره‌شده کاربر -->
            <div class="block-cat" style="background:rgba(37,99,235,.08);border-inline-start:3px solid var(--primary)">🧩 بلوک‌های ترکیبی من <span class="badge badge-info" style="font-size:9.5px"><?= count($savedBlocks) ?></span></div>
            <?php if (empty($savedBlocks)): ?>
                <div class="hint" style="padding:4px 12px 10px;font-size:10.5px;line-height:1.8">هنوز بلوک ترکیبی ذخیره نکرده‌اید — چند بلوک را با دکمه 🧺 در سبد انتخاب بگذارید و «ذخیره ترکیب» را بزنید تا همیشه باهم قابل استفاده مجدد باشند.</div>
            <?php else: ?>
                <?php foreach ($savedBlocks as $sb): ?>
                    <div class="block-item" draggable="true" data-block="saved:<?= (int)$sb['id'] ?>" style="border-inline-start:3px solid var(--primary)" title="بلوک ترکیبی <?= $savedCounts[$sb['id']] > 1 ? '(' . $savedCounts[$sb['id']] . ' بلوک باهم)' : '(تک‌بلوک)' ?> — دابل‌کلیک یا درگ کنید؛ همه بلوک‌ها با هم و با همان تنظیمات درج می‌شوند">
                        <span class="icon">🧩</span>
                        <span><?= e($sb['name']) ?><?= $savedCounts[$sb['id']] > 1 ? ' <span class="badge badge-info" style="font-size:9px">' . (int)$savedCounts[$sb['id']] . ' بلوک</span>' : '' ?></span>
                        <form method="post" style="display:inline" data-confirm="بلوک ترکیبی «<?= e((string)$sb['name']) ?>» حذف شود؟">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="action" value="delete_builder_block">
                            <input type="hidden" name="block_id" value="<?= (int)$sb['id'] ?>">
                            <input type="hidden" name="template_id" value="<?= (int)($template['id'] ?? 0) ?>">
                            <button type="submit" class="block-eye" style="color:#dc2626" title="حذف بلوک ترکیبی" onclick="event.stopPropagation()">🗑</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- ⭐ v2.26: عناصر شخصی استخراج‌شده از سایت‌ها -->
            <div class="block-cat" style="background:rgba(22,163,74,.08);border-inline-start:3px solid #16a34a">⭐ عناصر شخصی من <span class="badge badge-success" style="font-size:9.5px"><?= count($personalElements) ?></span></div>
            <?php if (empty($personalElements)): ?>
                <div class="hint" style="padding:4px 12px 10px;font-size:10.5px;line-height:1.8">عناصری که از سایت‌های دیگر استخراج و ذخیره کرده‌اید اینجا نمایش داده می‌شوند — از پنل «🌐 استخراج از سایت» بالای بوم شروع کنید.</div>
            <?php else: ?>
                <?php foreach ($personalElements as $pe): ?>
                    <div class="block-item" draggable="true" data-block="pelement:<?= (int)$pe['id'] ?>" style="border-inline-start:3px solid #16a34a" title="عنصر شخصی استخراج‌شده — دابل‌کلیک یا درگ کنید تا با همان استایل سایت مبدأ در صفحه قرار بگیرد">
                        <span class="icon"><?= e($pe['element_type'] === 'button' ? '🔘' : ($pe['element_type'] === 'card' ? '🗂' : ($pe['element_type'] === 'nav' ? '🧭' : '⭐'))) ?></span>
                        <span><?= e($pe['name']) ?></span>
                        <button type="button" class="block-eye" title="پیش‌نمایش عنصر" onclick="event.stopPropagation();previewPersonalElement(<?= (int)$pe['id'] ?>)">👁</button>
                        <button type="button" class="block-eye" style="color:#dc2626" title="حذف عنصر شخصی" onclick="event.stopPropagation();deletePersonalElement(<?= (int)$pe['id'] ?>, this)">🗑</button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php foreach ($blockLibrary as $category => $blocks): ?>
                <div class="block-cat"><?= e($category) ?> <span class="badge badge-secondary" style="font-size:9.5px"><?= count($blocks) ?></span></div>
                <?php foreach ($blocks as $key => [$icon, $label, $defaults]): ?>
                    <div class="block-item" draggable="true" data-block="<?= e($key) ?>" data-label="<?= e($label) ?>" title="دابل‌کلیک = افزودن سریع | دکمه 👁 = پیش‌نمایش تک‌بلوک">
                        <span class="icon"><?= $icon ?></span>
                        <span class="bi-label"><?= e($label) ?></span>
                        <button type="button" class="block-eye" title="پیش‌نمایش این بلوک" onclick="event.stopPropagation();previewSingleBlock('<?= e($key) ?>')">👁</button>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <div class="hint" style="padding:10px 12px;font-size:10.5px;line-height:1.8">📦 مجموعاً <?= (int)$totalBlockCount ?> عنصر در <?= count($blockLibrary) ?> دسته — با حالت «کارتی» پیش‌نمایش واقعی هر عنصر را ببینید.</div>
        </aside>

        <!-- 🎨 بوم طراحی زنده -->
        <section class="builder-canvas" id="canvas">
            <!-- 🧺 v3.3: سبد انتخاب چند بلوکی — برای ذخیره ترکیب گروهی -->
            <div class="basket-bar hidden" id="basket-bar">
                <span>🧺 انتخاب شما:</span>
                <span class="bb-count" id="basket-count">۰</span>
                <span>بلوک</span>
                <button type="button" class="bb-save" onclick="saveBasketAsComposite()">💾 ذخیره ترکیب (همه باهم)</button>
                <button type="button" class="bb-clear" onclick="clearBasket()">✖ پاک کردن انتخاب</button>
            </div>
            <div class="canvas-empty" id="canvas-empty" <?= $layout ? 'style="display:none"' : '' ?>>
                <div style="font-size:48px;margin-bottom:10px">🎭</div>
                بلوک‌ها را از پنل راست بکشید و اینجا رها کنید<br>
                <small>⚡ بوم، بلوک‌ها را «زنده و واقعی» رندر می‌کند — همان‌طور که در سایت دیده می‌شوند<br>
                🏛 برای چندستونه کردن، ابتدا «بخش چندستونی» اضافه کنید و بلوک‌ها را داخل ستون‌ها بیندازید</small>
            </div>
            <div id="canvas-blocks"></div>
        </section>

        <!-- 🎛️ ویژگی‌ها -->
        <aside class="block-props" id="props-panel">
            <div class="prop-title">🎛️ ویژگی‌های بلوک</div>
            <div id="props-content" style="font-size:12px;color:var(--text-light)">
                یک بلوک را در بوم انتخاب کنید تا ویژگی‌هایش اینجا نمایش داده شود.
            </div>
        </aside>
    </div>
</form>

<!-- 🧩 v2.12: فرم مستقل ذخیره بلوک ترکیبی (خارج از فرم قالب — ضد تودرتو) -->
<form method="post" id="save-block-form" style="display:none">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="save_builder_block">
    <input type="hidden" name="template_id" value="<?= (int)($template['id'] ?? 0) ?>">
    <input type="hidden" name="block_name" id="save-block-name" value="">
    <input type="hidden" name="block_json" id="save-block-json" value="">
</form>

<!-- 👁️ مودال پیش‌نمایش زنده -->
<div class="modal-backdrop" id="preview-backdrop">
    <div class="modal preview-modal">
        <div class="modal-header" style="justify-content:space-between;gap:10px">
            <span>👁️ پیش‌نمایش زنده قالب</span>
            <div class="device-tabs" style="margin:0">
                <button type="button" class="device-tab active" onclick="setPreviewDevice(this,375,'موبایل')" title="موبایل">📲</button>
                <button type="button" class="device-tab" onclick="setPreviewDevice(this,768,'تبلت')" title="تبلت">📱</button>
                <button type="button" class="device-tab" onclick="setPreviewDevice(this,0,'دسکتاپ')" title="دسکتاپ">🖥️</button>
            </div>
            <button type="button" class="btn btn-outline btn-sm" onclick="closeLivePreview()">✕ بستن</button>
        </div>
        <div class="modal-body preview-body">
            <iframe id="preview-frame" class="preview-frame" src="about:blank" title="پیش‌نمایش"></iframe>
        </div>
    </div>
</div>

<?php
/* 🧮 P2-23 — از داخل <script> به اینجا منتقل شد (فهرست تخت بلوک‌ها) */
/* 🚨 v2.28 — ریشه قطعی «تنظیمات عناصر ناقص»: array_map با یک آرایه،
   کلید دسته‌ها را حفظ می‌کند و خروجی «تودرتو» بود: {'هدر':{...},'محتوا':{...}}
   → BLOCK_META['checklist'] همیشه undefined → هر بلوکی «ترکیبی» فرض می‌شد →
   هیچ فیلد ویرایشی (متن/رنگ/تصویر/آیتم) رندر نمی‌شد و پیش‌فرض‌ها هم اعمال
   نمی‌شد. اکنون ساختار واقعاً تخت ساخته می‌شود. */
$flatBlockMeta = [];
foreach ($blockLibrary as $blockCats) {
    foreach ($blockCats as $blockKey => $blockMetaRow) {
        $flatBlockMeta[$blockKey] = ['label' => $blockMetaRow[1], 'defaults' => $blockMetaRow[2] ?? []];
    }
}
 ?>
<script>
/* 📦 داده‌های سرور → بوت‌استرپ JS خارجی (P2-23) */
const TB_SERVER_DATA = {
    blockMeta: <?= json_encode($flatBlockMeta, JSON_UNESCAPED_UNICODE) ?>,
    pageProps: <?= json_encode($pageProps, JSON_UNESCAPED_UNICODE) ?: '{}' ?>,
    savedBlocks: <?= json_encode(
        array_combine(
            array_map(static fn($sb) => (int)$sb['id'], $savedBlocks),
            array_map(static fn($sb) => json_decode((string)$sb['block_json'], true), $savedBlocks)
        ) ?: [],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    ) ?>,
    personalElements: <?= json_encode(
        array_combine(
            array_map(static fn($pe) => (int)$pe['id'], $personalElements),
            array_map(static fn($pe) => ['name' => $pe['name'], 'element_type' => $pe['element_type'], 'html' => $pe['html'], 'css' => $pe['css']], $personalElements)
        ) ?: [],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    ) ?>,
    genericFieldsV229: <?= json_encode(array_fill_keys(array_keys($genericBlockKeys), ['T', 'S', 'X', 'C', 'IMG', 'IT'])) ?>,
    genericFieldsV231: <?= json_encode(array_fill_keys(['btn-duo','btn-gradient','btn-outline-row','btn-icon-row','btn-mega-cta','btn-social','progress-multi','progress-striped','progress-thin','progress-circles','progress-ring-big','progress-semi','rating-hero','info-tiles','feature-split','hover-cards','alert-gradient','gradient-quote','chips-filter','counter-cards'], ['T', 'S', 'CLR', 'IT'])) ?>,
};
</script>
<script src="../assets/js/template-builder.js?v=2.38"></script>

<?php
/* 🎨 v2.38 — انتخابگر مشترک آیکون (ایموجی + پک SVG) برای فیلدهای آیکون عناصر */
require_once __DIR__ . '/includes/icon-picker.php';
sahand_icon_picker_assets();
?>

<?php require __DIR__ . '/includes/footer.php'; ?>
