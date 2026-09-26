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
            $db->update('brand_pages', ['layout_json' => $layoutJson], 'id = ?', [$brandPageId]);
            Logger::activity((int)$_SESSION['user_id'], 'ویرایش چیدمان صفحه برند در قالب‌ساز', ($bp['page_type'] ?? '') . ' (صفحه #' . $brandPageId . ')');
            $bpName = TemplateLibrary::pageTypeLabels()[$bp['page_type']] ?? $bp['page_type'];
            flash('success', '✅ چیدمان صفحه «' . $bpName . '» ذخیره شد — تغییری در قالب‌های عمومی داده نشد.');
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

/* 🆕 v2.25: تنظیمات صفحه — گره مخفی «_page» در ابتدای چیدمان ذخیره می‌شود
   (فاصله‌ها، زمینه، عرض محتوا، گردی گوشه‌ها و ...). قدیمی‌ها بدون آن‌اند و
   همچنان کار می‌کنند؛ بوم فقط بلوک‌های واقعی را رندر می‌کند. */
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
        'article-highlight' => ['🌟', 'جعبه محتوای ویژه', ['title' => 'خدمات ویژه تعطیلات', 'subtitle' => 'پاسخگویی و اعزام امداد در تمام روزهای هفته', 'text' => 'خلاصه‌ای از مزیت ویژه این بخش — متن و تصویر قابل تنظیم است.']],
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
        'review-grid' => ['⭐', 'شبکه نظرات مشتریان', ['title' => 'مشتریان ما چه می‌گویند']],
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
        'request-form' => ['📝', 'فرم درخواست خدمات', []],
        'newsletter-form' => ['📧', 'فرم عضویت خبرنامه', []],
        'appointment-form' => ['📅', 'فرم رزرو نوبت (تاریخ + ساعت)', []],
        'quick-contact-form' => ['⚡', 'فرم سریع تک‌خطی (تلفن + دکمه)', ['title' => 'در چند ثانیه درخواست دهید', 'btnText' => 'درخواست تماس']],
        /* 🆕 v2.15 */
        'booking-calendar' => ['🗓', 'تقویم رزرو نوبت (تعاملی)', []],
        'warranty-check' => ['🛡', 'استعلام گارانتی (شماره سریال)', []],
        'price-estimate' => ['🧮', 'برآوردگر هزینه فوری (دستگاه + ایراد)', []],
        'device-error-lookup' => ['🔢', 'جستجوی کد خطای دستگاه', []],
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
        'testimonials' => ['💬', 'اسلایدر نظرات مشتریان', ['autoplay' => 1]],
        'faq-accordion' => ['❓', 'آکاردئون سوالات', []],
        'tabs' => ['🗂️', 'تب‌بندی محتوا', []],
        'timeline' => ['🕐', 'خط زمانی پیشرفت کار', []],
        'steps-process' => ['👣', 'مراحل کار (فرآیند)', []],
        'before-after' => ['🔀', 'مقایسه قبل/بعد تعمیر', []],
        'social-proof' => ['🌟', 'اثبات اجتماعی (آواتار + امتیاز)', ['text' => 'بیش از ۵۰ هزار مشتری به ما اعتماد کرده‌اند']],
        'warranty-steps' => ['🛡️', 'مراحل گارانتی (۳ گام)', ['title' => 'گارانتی ما چگونه کار می‌کند']],
        'feature-table' => ['🧾', 'جدول مقایسه ویژگی‌ها', ['title' => 'مقایسه پلن‌های سرویس']],
        /* 🆕 v2.15 */
        'faq-search' => ['🔎', 'جستجوی سوالات متداول', []],
        'faq-category' => ['🗂', 'سوالات متداول دسته‌بندی‌شده', []],
        'faq-mini' => ['❓', 'سوال و پاسخ تک‌آیتمی', ['title' => 'هزینه عیب‌یابی چقدر است؟', 'text' => 'عیب‌یابی تخصصی در صورت تعمیر نزد ما رایگان است.']],
        'steps-compact' => ['3️⃣', 'مراحل فشرده سرویس', ['title' => 'فقط ۳ قدم تا تعمیر']],
        /* 🆕 v2.25 */
        'quote-slider' => ['💬', 'اسلایدر نقل‌قول‌ها', ['title' => 'مشتریان چه می‌گویند', 'items' => [['icon' => 'علی محمدی', 'text' => 'سرویس سریع و منظم بود؛ راضی بودم.'], ['icon' => 'مریم احمدی', 'text' => 'قیمت شفاف و ضمانت واقعی.'], ['icon' => 'رضا کریمی', 'text' => 'تکنسین دقیق و حرفه‌ای اعزام شد.']]]],
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
        'reviews-carousel' => ['💬', 'اسلایدر نظرات مشتریان', ['title' => 'مشتریان چه می‌گویند']],
        /* 🆕 v2.25 */
        'video-grid' => ['🎞', 'شبکه ویدیوهای آموزشی', ['title' => 'آموزش‌های ویدیویی', 'columns' => 3]],
        'logo-marquee' => ['🏷', 'نوار لوگوی متحرک', ['title' => 'برندهای مورد خدمت', 'items' => [['icon' => '🏷️', 'text' => 'ال‌جی'], ['icon' => '🏷️', 'text' => 'سامسونگ'], ['icon' => '🏷️', 'text' => 'بوش'], ['icon' => '🏷️', 'text' => 'سونی'], ['icon' => '🏷️', 'text' => 'پاکس'], ['icon' => '🏷️', 'text' => 'اسنوا']]]],
        'tag-cloud' => ['#️⃣', 'ابر برچسب (کلمات کلیدی)', ['title' => 'جستجوهای پرتکرار', 'items' => [['text' => 'تعمیر ماشین لباس'], ['text' => 'کد خطا SE'], ['text' => 'شارژ گاز کولر'], ['text' => 'بک‌لایت تلویزیون'], ['text' => 'مگنترون'], ['text' => 'سرویس دوره‌ای'], ['text' => 'برد الکترونیک'], ['text' => 'نصب ظرفشویی']]]],
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
        'related-links' => ['🔗', 'لینک‌های مرتبط / مطالب پیشنهادی', ['title' => 'مطالب مرتبط']],
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
<style>
/* 🔤 بوم و کارت‌های پیش‌نمایش با فونت انتخابی (مثل سایت نهایی) */
.tb-live, .el-thumb-stage { font-family: var(--font-body, Vazirmatn, Tahoma, 'Segoe UI', sans-serif); }
.tb-live .blk-title, .el-thumb-stage .blk-title, .tb-live .hero-title, .el-thumb-stage .hero-title,
.tb-live .card-t, .el-thumb-stage .card-t, .tb-live .fake-cta, .el-thumb-stage .fake-cta,
.tb-live .hero-btn, .el-thumb-stage .hero-btn, .tb-live .stat-n, .el-thumb-stage .stat-n,
.tb-live .notif-bar, .el-thumb-stage .notif-bar, .tb-live .topbar-blk, .el-thumb-stage .topbar-blk,
.tb-live .price-row b, .el-thumb-stage .price-row b, .tb-live .cta-num, .el-thumb-stage .cta-num,
.tb-live .story-year, .el-thumb-stage .story-year, .tb-live .num-n, .el-thumb-stage .num-n { font-family: var(--font-heading, Vazirmatn, Tahoma, sans-serif); }
/* ⚡ v3.0: استایل بوم طراحی زنده */
.tb-block { position: relative; border-radius: 12px; }
.tb-block > .block-tools {
    position: absolute; top: 6px; left: 6px; z-index: 20; display: none; gap: 3px;
    background: rgba(15,23,42,.92); padding: 3px; border-radius: 8px; direction: ltr;
    box-shadow: 0 4px 14px rgba(0,0,0,.25);
}
.tb-block:hover > .block-tools, .tb-block.selected > .block-tools { display: flex; }
.tb-block > .block-tools button {
    background: none; border: none; color: #e2e8f0; cursor: pointer; font-size: 13px;
    padding: 4px 7px; border-radius: 6px; line-height: 1;
}
.tb-block > .block-tools button:hover { background: #334155; }
.tb-block.selected { outline: 2.5px solid #2563eb; outline-offset: 2px; }
.tb-label {
    position: absolute; top: -11px; right: 10px; z-index: 21; font-size: 10.5px; font-weight: 700;
    background: #2563eb; color: #fff; border-radius: 20px; padding: 2.5px 11px; white-space: nowrap;
    box-shadow: 0 2px 8px rgba(37,99,235,.4); pointer-events: none;
}
.tb-dropzone {
    min-height: 74px; border: 2px dashed #94a3b8; border-radius: 10px; padding: 10px;
    display: flex; flex-direction: column; gap: 10px; transition: all .15s; background: rgba(148,163,184,.06);
}
.tb-dropzone.drag-over { border-color: #2563eb; background: rgba(37,99,235,.1); transform: scale(1.008); }
.tb-dropzone .tb-empty-hint { color: #94a3b8; font-size: 11.5px; text-align: center; margin: auto; line-height: 1.9 }
.tb-col-wrap { display: grid; gap: 14px; }

/* 🎚️ v2.15: دکمه‌های نوع نمایش عناصر — ۵ حالت (فهرستی/کارتی/کاشی/فشرده/آیکون) */
.palette-view-toggle { display: flex; gap: 5px; padding: 8px 10px; background: var(--bg, #f8fafc); border-radius: 10px; margin: 0 10px 10px; border: 1px solid var(--border, #e2e8f0); flex-wrap: wrap; }
.pvt-btn { flex: 1 1 30%; border: 1.5px solid var(--border, #e2e8f0); background: #fff; border-radius: 8px; padding: 7px 4px; font-size: 11px; font-weight: 700; cursor: pointer; font-family: inherit; color: var(--text-light, #64748b); transition: all .15s; }
.pvt-btn:hover { border-color: #93c5fd; color: #1e40af; }
.pvt-btn.active { border-color: #2563eb; color: #1e40af; background: #eff6ff; box-shadow: 0 2px 8px rgba(37,99,235,.15); }

/* 🖼️ حالت «کارتی» — پیش‌نمایش واقعی بزرگ هر عنصر (v2.15: بزرگ‌تر و واضح‌تر) */
.builder.has-cards { grid-template-columns: 384px 1fr 260px; }
@media (max-width: 1360px) { .builder.has-cards { grid-template-columns: 340px 1fr 240px; } }
.block-library.view-cards { overflow-x: hidden; }
.block-library.view-cards .block-cat { position: sticky; top: 0; z-index: 5; background: inherit; backdrop-filter: blur(3px); }
.block-library.view-cards .block-item { flex-direction: column; align-items: stretch; gap: 6px; padding: 10px; position: relative; }
.block-library.view-cards .block-item > .icon { position: absolute; top: 8px; right: 8px; z-index: 3; background: rgba(255,255,255,.92); border-radius: 7px; width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; font-size: 14px; box-shadow: 0 1px 5px rgba(0,0,0,.12); }
.block-library.view-cards .bi-label { font-size: 11.5px; font-weight: 800; padding-inline-start: 32px; min-height: 26px; display: flex; align-items: center; }
.block-library.view-cards .block-eye { position: absolute; top: 8px; left: 8px; z-index: 3; }
.el-thumb { position: relative; border-radius: 10px; overflow: hidden; border: 1.5px solid #e2e8f0; background: #fff; height: 172px; }
.el-thumb-stage { width: 760px; transform: scale(0.485); transform-origin: top right; pointer-events: none; }
.el-thumb .el-thumb-veil { position: absolute; inset: 0; background: linear-gradient(to bottom, transparent 82%, rgba(255,255,255,.9)); pointer-events: none; }
.el-thumb:hover { border-color: #2563eb; box-shadow: 0 6px 18px rgba(37,99,235,.16); }

/* 🔳 حالت «کاشی» — کاشی دوتایی آیکون + برچسب (متوسط) */
.builder.has-tiles { grid-template-columns: 330px 1fr 260px; }
@media (max-width: 1360px) { .builder.has-tiles { grid-template-columns: 300px 1fr 240px; } }
.block-library.view-tiles { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; align-items: stretch; }
.block-library.view-tiles > *:not(.block-item) { grid-column: 1 / -1; }
.block-library.view-tiles .block-cat { position: sticky; top: 0; z-index: 5; background: inherit; backdrop-filter: blur(3px); margin: 4px 0 2px; }
.block-library.view-tiles .block-item { flex-direction: column; align-items: center; justify-content: flex-start; gap: 5px; padding: 13px 6px 9px; text-align: center; position: relative; min-height: 82px; margin-bottom: 0; }
.block-library.view-tiles .block-item > .icon { font-size: 24px; line-height: 1; }
.block-library.view-tiles .bi-label { font-size: 10.5px; font-weight: 700; line-height: 1.5; padding: 0; }
.block-library.view-tiles .block-eye { position: absolute; top: 4px; left: 4px; opacity: .55; }
.block-library.view-tiles .block-item:hover { border-style: solid; }

/* 🗜️ حالت «فشرده» — ردیفهای خیلی جمع‌وجور برای حرفه‌ای‌ها */
.builder.has-dense { grid-template-columns: 212px 1fr 260px; }
.block-library.view-dense .block-item { padding: 4px 8px; font-size: 11px; margin-bottom: 3px; gap: 6px; border-radius: 7px; }
.block-library.view-dense .block-item > .icon { font-size: 13px; }
.block-library.view-dense .bi-label { font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.block-library.view-dense .block-cat { margin: 8px 0 4px; font-size: 10px; }
.block-library.view-dense .block-eye { display: none; }

/* 🎯 حالت «فقط آیکون» — شبکه آیکونهای بزرگ سه‌تایی */
.builder.has-icons { grid-template-columns: 262px 1fr 260px; }
.block-library.view-icons { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; align-items: stretch; }
.block-library.view-icons > *:not(.block-item) { grid-column: 1 / -1; }
.block-library.view-icons .block-cat { position: sticky; top: 0; z-index: 5; background: inherit; backdrop-filter: blur(3px); margin: 4px 0 2px; }
.block-library.view-icons .block-item { flex-direction: column; align-items: center; justify-content: center; gap: 4px; padding: 12px 3px 8px; text-align: center; min-height: 78px; position: relative; margin-bottom: 0; }
.block-library.view-icons .block-item > .icon { font-size: 22px; line-height: 1; }
.block-library.view-icons .bi-label { font-size: 9.5px; font-weight: 600; line-height: 1.45; padding: 0; }
.block-library.view-icons .block-eye { display: none; }

/* ⚙️ v2.25: تنظیمات صفحه — متغیرها روی بوم اعمال می‌شوند */
#canvas-blocks { --pg-section-pad: 54px; --pg-gap: 26px; --pg-width: 1080px; --pg-radius: 14px; --pg-text: 14.5px; --pg-shadow: 0 5px 18px rgba(2,8,23,.08); --pg-title: inherit; }
#canvas-blocks .blk { border-radius: var(--pg-radius); box-shadow: var(--pg-shadow); margin-bottom: var(--pg-gap); font-size: var(--pg-text); }
#canvas-blocks .blk .blk-title { color: var(--pg-title); }
#canvas-blocks .blk .blk-pad-default { padding-block: var(--pg-section-pad); }
#canvas-blocks .blk-pad-default { padding-top: calc(var(--pg-section-pad) * .6); padding-bottom: calc(var(--pg-section-pad) * .6); }
#canvas-blocks .blk-pad-roomy { padding-top: var(--pg-section-pad); padding-bottom: var(--pg-section-pad); }
#canvas-blocks .blk-pad-compact { padding-top: calc(var(--pg-section-pad) * .38); padding-bottom: calc(var(--pg-section-pad) * .38); }
#canvas-blocks .blk-pad-none { padding-top: 0; padding-bottom: 0; }
#canvas-blocks .tb-live .blk { max-width: var(--pg-width); margin-inline: auto; }
#canvas-blocks.pv-page-dark { background: #0f172a; }
#canvas-blocks.pv-page-dark .blk:not(.blk-bg-gradient):not(.blk-bg-primary):not(.blk-bg-dark) { background: #1e293b; color: #e2e8f0; }
#canvas-blocks.pv-page-dark .blk .blk-title { color: #f1f5f9; }
#canvas-blocks.pv-page-dark .blk .fake-card { background: #273449; border-color: #334155; }
#canvas-blocks.pv-page-dark .blk .pv-text, #canvas-blocks.pv-page-dark .blk .feat-d { color: #cbd5e1; }

/* ═══════════════════════════════════════════════════════════════
   🎭 v2.26 — ظواهر متعدد عناصر (blk-var-* | blk-btn-* | blk-hover-*)
   آینه همان قوانین در template-preview.php — هر تغییر، دوجا اعمال شود
   ═══════════════════════════════════════════════════════════════ */
/* — ظاهر کلی بدنه — */
#canvas-blocks .blk.blk-var-glass {
    background: rgba(255,255,255,.55); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(255,255,255,.75); box-shadow: 0 8px 28px rgba(2,8,23,.10);
}
#canvas-blocks .blk.blk-var-card {
    background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 14px 38px rgba(2,8,23,.13);
}
#canvas-blocks .blk.blk-var-flat { background: transparent; box-shadow: none !important; border: none; }
#canvas-blocks .blk.blk-var-outline {
    background: transparent; border: 2px solid #2563eb; box-shadow: none !important;
}
#canvas-blocks .blk.blk-var-soft {
    background: linear-gradient(135deg, #eff6ff, #e0f2fe); border: 1px solid #bfdbfe;
}
#canvas-blocks .blk.blk-var-dark { background: #0f172a; color: #e2e8f0; }
#canvas-blocks .blk.blk-var-dark .blk-title, #canvas-blocks .blk.blk-var-dark .card-t { color: #f1f5f9; }
#canvas-blocks .blk.blk-var-dark .pv-text, #canvas-blocks .blk.blk-var-dark .feat-d { color: #cbd5e1; }
#canvas-blocks .blk.blk-var-dark .fake-card { background: #1e293b; border-color: #334155; }
#canvas-blocks .blk.blk-var-hardshadow {
    background: #fef9c3; border: 2.5px solid #1e293b; box-shadow: 7px 7px 0 #1e293b !important;
}
#canvas-blocks .blk.blk-var-dashed { background: rgba(255,255,255,.6); border: 2px dashed #94a3b8; box-shadow: none !important; }
#canvas-blocks .blk.blk-var-ribbon {
    border-inline-start: 6px solid #f59e0b; background: #fffbeb; box-shadow: 0 4px 16px rgba(245,158,11,.12);
}
#canvas-blocks .blk.blk-var-inset {
    background: #f1f5f9; box-shadow: inset 0 4px 14px rgba(2,8,23,.13) !important; border: 1px solid #e2e8f0;
}
#canvas-blocks .blk.blk-var-gradient {
    background: linear-gradient(135deg, #1e40af, #0ea5e9) !important; color: #fff;
}
#canvas-blocks .blk.blk-var-gradient .blk-title, #canvas-blocks .blk.blk-var-gradient .card-t { color: #fff; }
#canvas-blocks .blk.blk-var-gradient .fake-card { background: rgba(255,255,255,.13); border-color: rgba(255,255,255,.25); }

/* — استایل دکمه‌ها (داخل بلوک) — */
#canvas-blocks .blk-btn-glass .hero-btn, #canvas-blocks .blk-btn-glass .fake-cta, #canvas-blocks .blk-btn-glass .cta-btn {
    background: rgba(255,255,255,.22); backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,.45); color: inherit;
}
#canvas-blocks .blk-btn-pill .hero-btn, #canvas-blocks .blk-btn-pill .fake-cta, #canvas-blocks .blk-btn-pill .cta-btn { border-radius: 999px; }
#canvas-blocks .blk-btn-outline .hero-btn, #canvas-blocks .blk-btn-outline .fake-cta, #canvas-blocks .blk-btn-outline .cta-btn {
    background: transparent; border: 2px solid #1e40af; color: #1e40af;
}
#canvas-blocks .blk-btn-gradient .hero-btn, #canvas-blocks .blk-btn-gradient .fake-cta, #canvas-blocks .blk-btn-gradient .cta-btn {
    background: linear-gradient(135deg, #1e40af, #0ea5e9); color: #fff; border: none;
}
#canvas-blocks .blk-btn-square .hero-btn, #canvas-blocks .blk-btn-square .fake-cta, #canvas-blocks .blk-btn-square .cta-btn { border-radius: 0; }
@keyframes blkBtnPulse { 0%,100% { box-shadow: 0 0 0 0 rgba(37,99,235,.45); } 50% { box-shadow: 0 0 0 9px rgba(37,99,235,0); } }
#canvas-blocks .blk-btn-glow .hero-btn, #canvas-blocks .blk-btn-glow .fake-cta, #canvas-blocks .blk-btn-glow .cta-btn {
    animation: blkBtnPulse 2.1s infinite; background: #2563eb; color: #fff;
}
#canvas-blocks .blk-btn-shadow .hero-btn, #canvas-blocks .blk-btn-shadow .fake-cta, #canvas-blocks .blk-btn-shadow .cta-btn {
    box-shadow: 0 7px 18px rgba(30,64,175,.38); transition: transform .18s, box-shadow .18s;
}
#canvas-blocks .blk-btn-shadow .hero-btn:hover, #canvas-blocks .blk-btn-shadow .fake-cta:hover { transform: translateY(-2px); box-shadow: 0 11px 24px rgba(30,64,175,.44); }

/* — افکت‌های هاور (بدنه بلوک) — */
#canvas-blocks .blk-hover-lift, #canvas-blocks .blk-hover-zoom, #canvas-blocks .blk-hover-tilt { transition: transform .22s ease, box-shadow .22s ease; }
#canvas-blocks .blk-hover-lift:hover { transform: translateY(-6px); box-shadow: 0 18px 40px rgba(2,8,23,.17); }
#canvas-blocks .blk-hover-zoom:hover { transform: scale(1.022); }
#canvas-blocks .blk-hover-tilt:hover { transform: rotate(-.5deg) translateY(-3px); }
#canvas-blocks .blk-hover-glow { transition: box-shadow .24s ease; }
#canvas-blocks .blk-hover-glow:hover { box-shadow: 0 0 0 3px rgba(37,99,235,.35), 0 0 30px rgba(37,99,235,.30) !important; }
/* 🌙 سازگاری تیره: ظواهر شیشه‌ای/کارت/ملایم در پیش‌نمایش تیره */
#canvas-blocks.pv-page-dark .blk.blk-var-glass { background: rgba(30,41,59,.55); border-color: rgba(148,163,184,.35); }
#canvas-blocks.pv-page-dark .blk.blk-var-card { background: #1e293b; border-color: #334155; }
#canvas-blocks.pv-page-dark .blk.blk-var-soft { background: linear-gradient(135deg, #1e293b, #172554); border-color: #1e3a8a; }
#canvas-blocks.pv-page-dark .blk.blk-var-outline { border-color: #60a5fa; }
#canvas-blocks.pv-page-dark .blk.blk-var-ribbon { background: rgba(245,158,11,.09); }
#canvas-blocks.pv-page-dark .blk.blk-var-hardshadow { background: #33260a; box-shadow: 7px 7px 0 #000 !important; border-color: #fde047; }

/* 😀 v2.25: انتخابگر آیکون ایموجی */
.emoji-picker-pop {
    position: absolute; z-index: 9999; width: 316px; background: #fff; border: 1.5px solid #e2e8f0;
    border-radius: 14px; box-shadow: 0 18px 48px rgba(2,8,23,.22); overflow: hidden; direction: rtl;
    font-family: inherit;
}
.emoji-picker-pop .ep-head {
    display: flex; justify-content: space-between; align-items: center; padding: 10px 13px;
    font-weight: 800; font-size: 12.5px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;
}
.emoji-picker-pop .ep-close { border: none; background: none; cursor: pointer; font-size: 13px; color: #64748b; }
.emoji-picker-pop .ep-grid {
    display: grid; grid-template-columns: repeat(8, 1fr); gap: 2px; padding: 9px; max-height: 236px; overflow: auto;
}
.emoji-picker-pop .ep-grid button {
    border: none; background: none; font-size: 18px; cursor: pointer; padding: 5px 0; border-radius: 7px; line-height: 1.3;
    transition: background .12s;
}
.emoji-picker-pop .ep-grid button:hover { background: #e0f2fe; transform: scale(1.14); }
.emoji-picker-pop .ep-hint { padding: 7px 12px; font-size: 10px; color: #94a3b8; border-top: 1px solid #f1f5f9; }

/* 🧺 سبد انتخاب چند بلوکی (ترکیب گروهی) */
.basket-bar { position: sticky; top: 8px; z-index: 40; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; background: rgba(15,23,42,.94); color: #e2e8f0; border-radius: 12px; padding: 8px 14px; margin-bottom: 12px; box-shadow: 0 8px 24px rgba(0,0,0,.28); font-size: 12.5px; }
.basket-bar.hidden { display: none; }
.basket-bar .bb-count { background: #2563eb; color: #fff; border-radius: 20px; padding: 2px 11px; font-weight: 800; }
.basket-bar button { border: none; border-radius: 8px; padding: 6px 13px; font-weight: 700; cursor: pointer; font-size: 11.5px; font-family: inherit; }
.basket-bar .bb-save { background: #16a34a; color: #fff; }
.basket-bar .bb-clear { background: #334155; color: #e2e8f0; }
.tb-block.in-basket { outline: 2.5px solid #16a34a; outline-offset: 2px; }
.tb-block.in-basket::after { content: '🧺'; position: absolute; bottom: 8px; left: 10px; z-index: 22; font-size: 15px; }
</style>

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

<script>
/* 🎭 موتور قالب‌ساز v3.1 — طراحی زنده + ستون‌بندی تودرتو + بلوک‌های ترکیبی */
const BLOCK_META = <?= json_encode(array_map(function ($cats) {
    $flat = [];
    foreach ($cats as $key => $meta) { $flat[$key] = ['label' => $meta[1], 'defaults' => $meta[2]]; }
    return $flat;
}, $blockLibrary), JSON_UNESCAPED_UNICODE) ?>;

/* ==================================================
 * 🎭 v2.26 — ظواهر متعدد برای هر عنصر
 * هر بلوک (تمام ۱۵۰ عنصر) سه بعد ظاهری مستقل دارد:
 *   variant  : ظاهر کلی بدنه (شیشه‌ای/کارت/تخت/خط‌دار/...)
 *   btnStyle : استایل دکمه‌های داخل بلوک (شیشه‌ای/دایره‌ای/...)
 *   hoverFx  : افکت هاور بلوک (بالا‌آمدن/بزرگ‌شدن/درخشش)
 * کلاس‌ها: blk-var-* | blk-btn-* | blk-hover-*
 * (آینه PHP: renderPreviewBlock در template-preview.php)
 * ================================================== */
const BLOCK_VARIANTS = {
    variant: [
        ['default',      '◻️ پیش‌فرض'],
        ['glass',        '🧊 شیشه‌ای (بلور مات)'],
        ['card',         '🗂 کارت برجسته (سایه‌دار)'],
        ['flat',         '⬜ تخت (بدون سایه/حاشیه)'],
        ['outline',      '🔲 خط‌دار (قاب رنگی)'],
        ['soft',         '🎨 ملایم (رنگ کم‌رنگ برند)'],
        ['dark',         '🌙 تیره (سرمه‌ای)'],
        ['hardshadow',   '🧱 سایه سخت (بروتالیسم)'],
        ['dashed',       '✂️ خط‌چین'],
        ['ribbon',       '📎 نواری (خط رنگی کنار)'],
        ['inset',        '⬇️ فرو رفته (Inset)'],
        ['gradient',     '🌈 گرادیانت برند'],
    ],
    btnStyle: [
        ['default',  '🔘 پیش‌فرض (کلاسیک)'],
        ['glass',    '🧊 شیشه‌ای'],
        ['pill',     '💊 دایره‌ای (کپسولی)'],
        ['outline',  '⬜ خطی (Outline)'],
        ['gradient', '🌈 گرادیانت'],
        ['square',   '⬛ مربعی تیز'],
        ['glow',     '✨ درخشان (نبض)'],
        ['shadow',   '🕯 سایه معلق'],
    ],
    hoverFx: [
        ['none',  '🚫 بدون افکت'],
        ['lift',  '⬆️ بالا آمدن + سایه'],
        ['zoom',  '🔍 بزرگ‌شدن ملایم'],
        ['glow',  '✨ درخشش حاشیه'],
        ['tilt',  '📐 کج شدن ظریف'],
    ],
};
/* ساخت کلاس‌های ظاهر از props — مشترک بین B() و آینه PHP */
function variantClasses(props) {
    const v = String(props.variant || '').trim();
    const b = String(props.btnStyle || '').trim();
    const h = String(props.hoverFx || '').trim();
    return [
        v && v !== 'default' ? 'blk-var-' + v : '',
        b && b !== 'default' ? 'blk-btn-' + b : '',
        h && h !== 'none' && h !== '' ? 'blk-hover-' + h : '',
    ].filter(Boolean).join(' ');
}

let layout = JSON.parse(document.getElementById('layout-json').value || '[]');
let selected = null; // رشته مسیر مثل '3' یا '3.cols.1.0'

/* ==================================================
 * ⚙️ v2.25: تنظیمات صفحه — گره مخفی «_page»
 * در ابتدای layout_json ذخیره می‌شود (در PHP جدا شده)؛
 * fullLayout() هنگام ذخیره/پیش‌نمایش دوباره سرِ خودش می‌گذارد.
 * ================================================== */
let pageProps = <?= json_encode($pageProps, JSON_UNESCAPED_UNICODE) ?: '{}' ?>;
const PAGE_DEFAULTS = {
    pageBg: 'default',       /* زمینه صفحه: default | surface | light | dark | custom */
    pageBgColor: '#f8fafc',  /* رنگ دلخواه وقتی pageBg=custom */
    sectionSpacing: 'default', /* فاصله عمودی داخل بخش‌ها: compact | default | roomy | airy */
    sectionGap: 'default',   /* فاصله بین بخش‌ها: tight | default | roomy */
    containerWidth: 'default', /* عرض محتوا: narrow | default | wide | full */
    radius: 'default',       /* گردی گوشه‌ها: sharp | default | round | pill */
    titleColor: '',          /* رنگ پیش‌فرض همه عنوان‌ها */
    textSize: 'default',     /* اندازه متن: sm | default | lg */
    cardShadow: 'default',   /* سایه کارت‌ها: none | soft | default | strong */
    darkPreview: 0           /* پیش‌نمایش بوم در حالت تیره */
};
function pageProp(k) {
    return (pageProps && pageProps[k] !== undefined && pageProps[k] !== '') ? pageProps[k] : (PAGE_DEFAULTS[k] !== undefined ? PAGE_DEFAULTS[k] : '');
}
function fullLayout() {
    return (pageProps && Object.keys(pageProps).length) ? [{ block: '_page', props: pageProps }].concat(layout) : layout;
}
function setPageProp(key, value) {
    pageProps = pageProps || {};
    pageProps[key] = value;
    applyPageSettings();
    syncAndRender();
}
/* اعمال تنظیمات صفحه روی بوم — متغیرهای CSS روی #canvas-blocks */
function applyPageSettings() {
    const stage = document.getElementById('canvas-blocks');
    if (!stage) { return; }
    stage.classList.toggle('pv-page-dark', pageProp('darkPreview') == 1);
    const bg = pageProp('pageBg');
    let bgCss = '';
    if (bg === 'surface') { bgCss = '#f1f5f9'; }
    else if (bg === 'light') { bgCss = '#fafafa'; }
    else if (bg === 'dark') { bgCss = '#0f172a'; }
    else if (bg === 'custom' && /^#[0-9a-fA-F]{3,8}$/.test(String(pageProp('pageBgColor')))) { bgCss = pageProp('pageBgColor'); }
    const spacing = { compact: '30px', default: '54px', roomy: '74px', airy: '96px' }[pageProp('sectionSpacing')] || '54px';
    const gap = { tight: '14px', default: '26px', roomy: '44px' }[pageProp('sectionGap')] || '26px';
    const width = { narrow: '860px', default: '1080px', wide: '1240px', full: '100%' }[pageProp('containerWidth')] || '1080px';
    const radius = { sharp: '2px', default: '14px', round: '22px', pill: '34px' }[pageProp('radius')] || '14px';
    const tsize = { sm: '13px', default: '14.5px', lg: '16px' }[pageProp('textSize')] || '14.5px';
    const shadow = { none: 'none', soft: '0 2px 8px rgba(2,8,23,.05)', default: '0 5px 18px rgba(2,8,23,.08)', strong: '0 12px 32px rgba(2,8,23,.16)' }[pageProp('cardShadow')] || '0 5px 18px rgba(2,8,23,.08)';
    const tc = pageProp('titleColor');
    stage.style.cssText = '--pg-section-pad:' + spacing + ';--pg-gap:' + gap + ';--pg-width:' + width +
        ';--pg-radius:' + radius + ';--pg-text:' + tsize + ';--pg-shadow:' + shadow +
        ';--pg-title:' + (tc !== '' ? tc : 'inherit') + ';max-width:100%' +
        (bgCss !== '' ? ';background:' + bgCss + ';border-radius:12px;padding:6px' : '');
}
/* پنل تنظیمات صفحه — در ستون ویژگی‌ها */
function renderPageProps() {
    selected = null;
    document.querySelectorAll('.tb-block').forEach(b => b.classList.remove('selected'));
    const panel = document.getElementById('props-content');
    const opt = (key, opts) => opts.map(([v, l]) => '<option value="' + v + '" ' + (String(pageProp(key)) === String(v) ? 'selected' : '') + '>' + l + '</option>').join('');
    let html = '<div style="font-weight:800;margin-bottom:12px;font-size:13.5px">⚙️ تنظیمات صفحه</div>' +
        '<div class="hint" style="font-size:10.5px;margin-bottom:11px;line-height:1.8">این تنظیمات روی «کل صفحه» اعمال می‌شوند — زمینه، فاصله بخش‌ها، عرض محتوا و ظاهر عمومی. روی هر بلوک که کلیک کنید به تنظیمات همان بلوک برمی‌گردید.</div>' +
        '<div class="form-group"><label>🎨 زمینه صفحه</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'pageBg\',this.value)">' + opt('pageBg', [['default', 'پیش‌فرض (سفید)'], ['surface', 'کمرنگ خاکستری'], ['light', 'روشن'], ['dark', 'تیره'], ['custom', 'رنگ دلخواه']]) + '</select></div>' +
        '<div class="form-group" id="pg-bg-color-box" style="' + (pageProp('pageBg') === 'custom' ? '' : 'display:none') + '"><label>رنگ دلخواه زمینه</label><div style="display:flex;gap:7px;align-items:center"><input type="color" class="form-control" style="width:48px;height:33px;padding:2px;cursor:pointer" value="' + pageProp('pageBgColor') + '" oninput="setPageProp(\'pageBgColor\',this.value)"><code style="font-size:10.5px;direction:ltr">' + pageProp('pageBgColor') + '</code></div></div>' +
        '<div class="form-group"><label>↕️ فاصله داخلی بخش‌ها</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'sectionSpacing\',this.value)">' + opt('sectionSpacing', [['compact', 'فشرده (۳۰px)'], ['default', 'پیش‌فرض (۵۴px)'], ['roomy', 'جادار (۷۴px)'], ['airy', 'خیلی باز (۹۶px)']]) + '</select></div>' +
        '<div class="form-group"><label>📏 فاصله بین بخش‌ها</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'sectionGap\',this.value)">' + opt('sectionGap', [['tight', 'نزدیک (۱۴px)'], ['default', 'پیش‌فرض (۲۶px)'], ['roomy', 'باز (۴۴px)']]) + '</select></div>' +
        '<div class="form-group"><label>📐 عرض محتوای صفحه</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'containerWidth\',this.value)">' + opt('containerWidth', [['narrow', 'باریک (۸۶۰px)'], ['default', 'پیش‌فرض (۱۰۸۰px)'], ['wide', 'عریض (۱۲۴۰px)'], ['full', 'تمام‌عرض']]) + '</select></div>' +
        '<div class="form-group"><label>⬜ گردی گوشه‌ها</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'radius\',this.value)">' + opt('radius', [['sharp', 'تیز (۲px)'], ['default', 'پیش‌فرض (۱۴px)'], ['round', 'گرد (۲۲px)'], ['pill', 'خیلی گرد (۳۴px)']]) + '</select></div>' +
        '<div class="form-group"><label>🎨 رنگ پیش‌فرض عنوان‌ها</label><div style="display:flex;gap:7px;align-items:center"><input type="color" class="form-control" style="width:48px;height:33px;padding:2px;cursor:pointer" value="' + (pageProp('titleColor') || '#1e40af') + '" oninput="setPageProp(\'titleColor\',this.value)"><button type="button" class="btn btn-outline btn-sm" onclick="setPageProp(\'titleColor\',\'\');renderPageProps()" title="حذف رنگ">✕ پیش‌فرض</button></div></div>' +
        '<div class="form-group"><label>🔤 اندازه متن</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'textSize\',this.value)">' + opt('textSize', [['sm', 'کوچک'], ['default', 'پیش‌فرض'], ['lg', 'بزرگ']]) + '</select></div>' +
        '<div class="form-group"><label>🌫 سایه کارت‌ها</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'cardShadow\',this.value)">' + opt('cardShadow', [['none', 'بدون سایه'], ['soft', 'ملایم'], ['default', 'پیش‌فرض'], ['strong', 'قوی']]) + '</select></div>' +
        '<label class="form-check" style="font-size:12px"><input type="checkbox" ' + (pageProp('darkPreview') == 1 ? 'checked' : '') + ' onchange="setPageProp(\'darkPreview\',this.checked?1:0)"> 🌙 پیش‌نمایش بوم در حالت تیره</label>' +
        '<hr style="border:none;border-top:1px solid var(--border);margin:13px 0">' +
        '<button type="button" class="btn btn-outline btn-sm btn-block" onclick="resetPageProps()">↺ بازنشانی تنظیمات صفحه</button>';
    panel.innerHTML = html;
}
function resetPageProps() {
    pageProps = {};
    applyPageSettings();
    syncAndRender();
    renderPageProps();
}

/* 🧩 v2.12: بلوک‌های ترکیبی ذخیره‌شده — id → ساختار JSON کامل (با ستون‌های تودرتو)
   (بازکدگذاری با JSON_HEX_TAG تا محتوای کاربر نتواند تگ <script> را بشکند) */
const SAVED_BLOCKS = {};
<?php foreach ($savedBlocks as $sb): ?>
try { SAVED_BLOCKS[<?= (int)$sb['id'] ?>] = <?= json_encode(json_decode((string)$sb['block_json'], true), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>; } catch (e) {}
<?php endforeach; ?>

/* ⭐ v2.26: عناصر شخصی استخراج‌شده از سایت‌ها — id → {name, html, css}
   در بوم به‌صورت iframe ایزوله (استایل سایت مبدأ حفظ می‌شود) رندر می‌شوند */
const PERSONAL_ELEMENTS = {};
<?php foreach ($personalElements as $pe): ?>
try { PERSONAL_ELEMENTS[<?= (int)$pe['id'] ?>] = <?= json_encode(['name' => $pe['name'], 'element_type' => $pe['element_type'], 'html' => $pe['html'], 'css' => $pe['css']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>; } catch (e) {}
<?php endforeach; ?>

/* 🖼 سند مستقل عنصر شخصی (iframe srcdoc) — مشترک بین بوم و پیش‌نمایش */
function pelementDoc(el) {
    return '<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8">'
        + '<style>*{box-sizing:border-box}body{margin:0;padding:14px;background:transparent;font-family:Vazirmatn,Tahoma,sans-serif}img{max-width:100%;height:auto}a{text-decoration:none}'
        + String(el.css || '').replace(/</g, '\\3C ') + '</style></head><body>' + (el.html || '') + '</body></html>';
}

/* ==================================================
 * ⚡ رندر واقعی بلوک‌ها (طراحی زنده — همان HTML سایت)
 * ================================================== */
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

/* 🗂 v2.12: کلاس ستون شبکه‌های کارتی از تنظیمات بلوک (۲..۶ — پیش‌فرض درآوردنی) */
function gridCols(props, def) { return Math.max(2, Math.min(6, parseInt(props.columns || def, 10) || def)); }

/* 🔢 v2.15: تبدیل ارقام به فارسی (برای تایمر زنده) */
function faDigJS(s) { return String(s).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[+d]); }

/* 🎨 v2.15: استایل درون‌خطی بلوک — رنگ عنوان / رنگ گرادیانت انتخابی */
function blkStyleVars(props) {
    let s = '';
    if (String(props.titleColor || '').trim() !== '') { s += `--blk-tc:${esc(String(props.titleColor).trim())};`; }
    if ((props.background || '') === 'gradient') {
        const gf = /^#[0-9a-fA-F]{3,8}$/.test(String(props.gradientFrom || '')) ? String(props.gradientFrom).trim() : '';
        const gt = /^#[0-9a-fA-F]{3,8}$/.test(String(props.gradientTo || '')) ? String(props.gradientTo).trim() : '';
        if (gf || gt) { s += `background:linear-gradient(135deg,${gf || '#1e40af'},${gt || '#0ea5e9'});`; }
    }
    return s;
}

/* 🖼 v2.15: تصویر واقعی به‌جای ایموجی قالبی (وقتی آدرس عکس در تنظیمات داده شده) */
function fakeImgHtml(props, emoji, style) {
    const url = String(props.imageUrl || '').trim();
    if (/^(https?:\/\/|\/|uploads\/)/i.test(url)) {
        return `<div class="fake-img" style="${style || ''}"><img src="${esc(url)}" alt="" style="width:100%;height:100%;object-fit:cover;display:block"></div>`;
    }
    return `<div class="fake-img" style="${style || ''}">${emoji}</div>`;
}

/* ➕ v2.15: آیتم‌های لیست قابل ویرایش — همیشه فرمت شیء {icon,text,desc} برمی‌گرداند
   (fallbackهای آرایه‌ای قدیمی هم نرمال می‌شوند) */
function listItems(props, fallback) {
    const norm = arr => (Array.isArray(arr) ? arr : []).map(it => Array.isArray(it) ? { icon: it[0] || '', text: it[1] || '', desc: it[2] || '' } : (it || {}));
    const its = norm(Array.isArray(props.items) ? props.items.filter(it => it && String(it.text || '').trim() !== '') : null);
    return its.length ? its : norm(fallback);
}

/* 📊 v2.25: آمار از آیتم‌های ویرایشگر — icon=عدد، text=برچسب (خروجی جفت‌آرایه) */
function statItemsFromItems(props, fallback) {
    const its = listItems(props, fallback);
    return its.map(it => [it.icon !== '' ? it.icon : (it.text || '۰'), it.icon !== '' ? it.text : 'آمار']);
}

/* 🖼 v2.25: تصویر آیتم گالری — text=آدرس تصویر، icon=ایموجی جایگزین */
function itGalHtml(it, style) {
    const url = String(it.text || '').trim();
    if (/^(https?:\/\/|\/|uploads\/)/i.test(url)) {
        return `<div class="fake-img small" style="${style || 'min-height:90px'}"><img src="${esc(url)}" alt="" style="width:100%;height:100%;object-fit:cover;display:block"></div>`;
    }
    return `<div class="fake-img small" style="${style || 'min-height:90px'}">${esc(it.icon || '🖼️')}</div>`;
}

/* ⏱ v2.15: جعبه‌های شمارش معکوس — زنده روی بوم هر ثانیه آپدیت می‌شود */
function countdownHtml(props) {
    const target = String(props.countdownTo || '').trim();
    let ms = null;
    if (target) { const d = new Date(target); if (!isNaN(d.getTime())) { ms = d.getTime(); } }
    let days = '۰۲', hrs = '۱۴', min = '۳۰', sec = '۰۰';
    if (ms !== null && ms > Date.now()) {
        let diff = Math.floor((ms - Date.now()) / 1000);
        days = faDigJS(String(Math.floor(diff / 86400)).padStart(2, '0'));
        hrs = faDigJS(String(Math.floor((diff % 86400) / 3600)).padStart(2, '0'));
        min = faDigJS(String(Math.floor((diff % 3600) / 60)).padStart(2, '0'));
        sec = faDigJS(String(diff % 60).padStart(2, '0'));
    }
    return `<div class="count-row"${ms ? ` data-countdown="${ms}"` : ''}><span class="count-box"><b>${days}</b>روز</span><span class="count-box"><b>${hrs}</b>ساعت</span><span class="count-box"><b>${min}</b>دقیقه</span><span class="count-box"><b>${sec}</b>ثانیه</span></div>`;
}

/* 📊 v2.17: آمار از آیتم‌های ویرایشگر (IT) — icon=عدد/ایموجی، text=برچسب */
function statItemsHtml(props) {
    const items = Array.isArray(props.items) && props.items.length
        ? props.items.filter(i => i && (i.icon || i.text))
        : [{ icon: '۱۲+', text: 'سال تجربه' }, { icon: '۵۰هزار+', text: 'تعمیر موفق' }, { icon: '۹۸٪', text: 'رضایت مشتری' }];
    return items.slice(0, 6).map(i => `<div class="stat"><div class="stat-n">${esc(String(i.icon || '۰').trim() || '۰')}</div><div class="stat-l">${esc(String(i.text || '').trim() || 'آمار')}</div></div>`).join('');
}
function statStripHtml(props) {
    const items = Array.isArray(props.items) && props.items.length
        ? props.items.filter(i => i && (i.icon || i.text))
        : [{ icon: '۱۲+', text: 'سال تجربه' }, { icon: '۵۰k', text: 'تعمیر موفق' }, { icon: '۹۸٪', text: 'رضایت' }, { icon: '۲h', text: 'اعزام' }];
    return items.slice(0, 8).map(i => `<span class="ss-item"><b>${esc(String(i.icon || '').trim() || '۰')}</b> ${esc(String(i.text || '').trim() || 'آمار')}</span>`).join('<span class="ss-sep"></span>');
}
function blockHtml(block, props) {
    const t = props.title || '';
    const padCls = 'blk-pad-' + (props.padding || 'default');
    const bgCls = 'blk-bg-' + (props.background || 'default');
    /* 🎛 v3.3: تنظیمات پیشرفته — اندازه عنوان / تراز / عرض محتوا / کلاس سفارشی */
    const sizeCls = 'blk-ts-' + (props.titleSize || 'md');
    const alignCls = props.align && props.align !== 'start' ? 'blk-al-' + props.align : '';
    const widthCls = props.width && props.width !== 'full' ? 'blk-w-' + props.width : '';
    const customCls = String(props.customClass || '').trim().replace(/[^a-zA-Z0-9\-_\s]/g, '');
    /* 🎭 v2.26: کلاس‌های ظاهر — واریانت بدنه + استایل دکمه + افکت هاور */
    const varCls = variantClasses(props);
    /* 🎨 v2.15: رنگ عنوان + رنگ گرادیانت انتخابی (تنظیمات پیشرفته) */
    const blkStyle = blkStyleVars(props);
    const styleAttr = blkStyle ? ` style="${blkStyle}"` : '';
    const B = (inner, extra) => `<div class="blk ${bgCls} ${padCls} ${sizeCls} ${alignCls} ${widthCls} ${customCls} ${varCls} ${extra || ''}"${styleAttr}>${inner}</div>`;
    const TITLE = t ? `<div class="blk-title">${esc(t)}</div>` : '';

    switch (block) {
        /* ⭐ v2.26: عنصر شخصی استخراج‌شده — رندر ایزوله با استایل سایت مبدأ */
        case 'pelement': {
            const pe = PERSONAL_ELEMENTS[parseInt(props.element_id, 10) || 0];
            if (!pe) { return B('<div class="pv-text">⭐ این عنصر شخصی حذف شده است.</div>'); }
            const frameHtml = pelementDoc(pe)
                .replace(/&/g, '&amp;').replace(/"/g, '&quot;');
            return B(`${t ? `<div class="blk-title">${esc(t)}</div>` : ''}<iframe class="pelement-frame" sandbox="allow-same-origin" srcdoc="${frameHtml}" style="width:100%;min-height:210px;border:none;border-radius:11px;background:#fff" loading="lazy" title="${esc(pe.name || 'عنصر شخصی')}"></iframe>`, 'pelement-blk');
        }
        case 'top-bar': return B(`<div class="tb-row"><span>📞 ${esc(props.phone || '۰۲۱-۱۲۳۴۵۶۷۸')}</span><span>🕐 ${esc(props.hours || 'شنبه تا پنجشنبه ۹ تا ۲۰')}</span></div>`, 'topbar-blk');
        case 'header-v1': case 'header-v2': case 'header-v3':
            { const menu = listItems(props, [[null, 'خانه'], [null, 'خدمات'], [null, 'مقالات'], [null, 'تماس']]); return B(`${block === 'header-v2' ? `<div class="tb-row"><span>📞 ${esc(props.phone || '۰۲۱-۱۲۳۴۵۶۷۸')}</span><span>🕐 ${esc(props.hours || 'پاسخگویی آنلاین')}</span></div>` : ''}<div class="h-row"><div class="fake-logo">🏗️</div><nav class="fake-nav">${menu.map(m => `<span>${esc(m.text || '')}</span>`).join('')}</nav><div class="fake-cta">${esc(props.btnText || 'ثبت درخواست')}</div></div>`, 'header-blk' + (block === 'header-v3' ? ' glass' : '') + (props.sticky ? ' sticky-demo' : '')); }
        case 'hero': return B(`<div class="hero-title">${esc(t || 'تعمیرات تخصصی با قطعات اصلی')}</div><div class="hero-sub">${esc(props.subtitle || 'نمایندگی رسمی — پاسخگویی ۷ روز هفته')}</div><div class="hero-btns"><span class="hero-btn">📞 تماس فوری</span><span class="hero-btn ghost">ثبت درخواست آنلاین</span></div>`, 'hero-blk');
        case 'hero-slider': return B(`<div class="hero-title">${esc(t || 'اسلایدر تصویری')}</div>${fakeImgHtml(props, '🖼️', 'min-height:170px').replace('fake-img', 'fake-img wide')}<div class="slider-dots">● ○ ○</div>`, 'hero-blk slider');
        case 'hero-split': return B(`<div class="hero-split"><div><div class="hero-title">${esc(t || 'تعمیر لوازم خانگی در محل')}</div><div class="hero-sub">${esc(props.subtitle || 'متن معرفی + دکمه فراخوان')}</div><div class="hero-btns"><span class="hero-btn">شروع کنید</span></div></div>${fakeImgHtml(props, '🛠️')}</div>`, 'hero-blk split-hero');
        case 'hero-video': return B(`<div class="hero-title">${esc(t || 'هیرو با پس‌زمینه تصویر')}</div><div style="position:relative">${fakeImgHtml(props, '🎞️', 'min-height:160px').replace('fake-img', 'fake-img wide')}<div class="play">▶</div></div>`, 'hero-blk video');
        case 'hero-countdown': return B(`<div class="hero-title">${esc(t || 'کمپین سرویس دوره‌ای')}</div>${countdownHtml(props)}`, 'hero-blk');
        case 'text': return B(`${TITLE}<div class="pv-text">${esc(props.text || 'متن خود را اینجا بنویسید — این بخش در سایت به همین شکل نمایش داده می‌شود. می‌توانید از پنل ویژگی‌ها ویرایش کنید و نتیجه را همان لحظه ببینید.').replace(/\n/g, '<br>')}</div>`);
        case 'text-image': case 'intro': return B(`<div class="split"><div><div class="blk-title">${esc(t || 'درباره برند')}</div><div class="pv-text" style="font-size:12.5px">${esc(props.text || 'معرفی کوتاه برند و خدمات تخصصی — این متن از پنل ویژگی‌ها قابل ویرایش است.').replace(/\n/g, '<br>')}</div></div>${fakeImgHtml(props, '🖼️')}</div>`);
        case 'rich-text': {
            /* 🎛 v2.14: متن واردشده خط‌به‌خط آیتم لیست می‌شود (قبلاً فیلد متن
               بود ولی رندر آن را نادیده می‌گرفت!) */
            const lines = String(props.text || '').split('\n').map(s => s.trim()).filter(Boolean);
            const items = lines.length ? lines : ['نصب و راه‌اندازی تخصصی', 'تعمیر با قطعات اصلی', '۶ ماه ضمانت قطعه و خدمات'];
            return B(`${TITLE}<ul class="pv-list">${items.map(i => `<li>✅ ${esc(i)}</li>`).join('')}</ul>`);
        }
        case 'quote': return B(`<div class="quote">«${esc(props.text || 'کیفیت تعمیر، اعتبار ماست')}»</div>`, 'quote-blk');
        case 'two-col': { const its = listItems(props, [[null, 'ستون اول', 'توضیح کوتاه ستون اول'], [null, 'ستون دوم', 'توضیح کوتاه ستون دوم']]); return B(`${TITLE}<div class="cols c2">${its.slice(0, 2).map(it => `<div class="fake-card"><div class="card-t">${esc(it.text || '')}</div><div class="feat-d">${esc(it.desc || '')}</div></div>`).join('')}</div>`); }
        case 'three-col': { const its = listItems(props, [[null, 'موضوع اول', 'توضیح'], [null, 'موضوع دوم', 'توضیح'], [null, 'موضوع سوم', 'توضیح']]); return B(`${TITLE}<div class="cols c3">${its.slice(0, 3).map(it => `<div class="fake-card"><div class="card-t">${esc(it.text || '')}</div><div class="feat-d">${esc(it.desc || '')}</div></div>`).join('')}</div>`); }
        case 'section-columns': {
            /* 🏛 کانتینر چندستونی — ستون‌ها با ناحیه رهاسازی */
            const cols = Math.max(2, Math.min(4, parseInt(props.columns || 2, 10)));
            let inner = '';
            for (let c = 0; c < cols; c++) { inner += `<div class="tb-col" style="display:flex;flex-direction:column;gap:10px;min-width:0"></div>`; }
            return B(`${TITLE}<div class="tb-col-wrap" style="grid-template-columns:repeat(${cols},1fr)">${inner}</div>`, 'section-cols-blk');
        }
        case 'section-split': return B(`${TITLE}<div class="tb-col-wrap" style="grid-template-columns:2fr 1fr"><div style="display:flex;flex-direction:column;gap:10px"></div><div style="display:flex;flex-direction:column;gap:10px"></div></div>`, 'section-cols-blk');
        case 'feature-list': { const its = listItems(props, [['⚡', 'سرعت عمل', 'اعزام تکنسین در کمتر از ۲ ساعت'], ['🛡️', 'ضمانت کتبی', '۶ ماه ضمانت قطعه و خدمات']]); return B(`${TITLE}<div class="feat-list">${its.map(it => `<div class="feat-row"><span class="feat-ico">${esc(it.icon || '⚡')}</span><div><b>${esc(it.text || '')}</b>${it.desc ? `<div class="feat-d">${esc(it.desc)}</div>` : ''}</div></div>`).join('')}</div>`); }
        case 'services-grid': case 'features': { const its = listItems(props, [['🔧', block === 'features' ? 'تخصص واقعی' : 'تعمیر لباسشویی', 'با قطعات فابریک'], ['🧊', block === 'features' ? 'سرعت اعزام' : 'تعمیر یخچال', 'همان روز'], ['⚡', block === 'features' ? 'قطعات اصلی' : 'تعمیر ماکروویو', 'ضمانت‌دار'], ['🎓', block === 'features' ? 'ضمانت کتبی' : 'سرویس دوره‌ای', 'در محل شما']]); return B(`<div class="blk-title">${esc(t || (block === 'features' ? 'چرا ما را انتخاب کنید؟' : 'خدمات ما'))}</div><div class="cols c${gridCols(props, 3)}">${its.map(it => `<div class="fake-card"><div class="card-ico">${esc(it.icon || '🔧')}</div><div class="card-t">${esc(it.text || '')}</div>${it.desc ? `<div class="feat-d">${esc(it.desc)}</div>` : ''}</div>`).join('')}</div>`); }
        case 'devices-grid': return B(`<div class="blk-title">${esc(t || 'دستگاه‌های تحت پوشش')}</div><div class="cols c${gridCols(props, 4)}">${['🌀 لباسشویی', '🧊 یخچال', '🍽️ ظرفشویی', '❄️ کولر', '📺 تلویزیون', '♨️ پکیج', '📻 مایکروویو', '🔥 فر و اجاق'].map(d => `<div class="fake-card"><div class="card-ico">${d.split(' ')[0]}</div><div class="card-t">${d.split(' ')[1]}</div></div>`).join('')}</div>`);
        case 'articles-recent': case 'articles-grid': return B(`<div class="blk-title">${esc(t || 'مقالات اخیر')}</div><div class="cols c${gridCols(props, 3)}">${'<div class="fake-card"><div class="fake-img small">📰</div><div class="card-t">عنوان مقاله نمونه</div><div class="fl w100"></div></div>'.repeat(3)}</div>`);
        case 'team': { const its = listItems(props, [['👨‍🔧', 'مهندس کریمی', 'متخصص لباسشویی'], ['👩‍🔧', 'مهندس رضایی', 'متخصص یخچال و فریزر'], ['🧑‍🔧', 'مهندس موسوی', 'متخصص تلویزیون']]); return B(`<div class="blk-title">${esc(t || 'تیم ما')}</div><div class="cols c${gridCols(props, 4)}">${its.map(it => `<div class="fake-card"><div class="fake-ava">${esc(it.icon || '👨‍🔧')}</div><div class="card-t">${esc(it.text || '')}</div><div class="feat-d">${esc(it.desc || '')}</div></div>`).join('')}</div>`); }
        case 'pricing-table': { const its = listItems(props, [[null, 'دریافت و عیب‌یابی تخصصی', 'رایگان'], [null, 'سرویس دوره‌ای لباسشویی', 'از ۴۵۰ هزار تومان'], [null, 'شارژ گاز کولر گازی', 'از ۹۰۰ هزار تومان']]); return B(`<div class="blk-title">${esc(t || 'تعرفه خدمات')}</div><div class="price-table">${its.map(it => `<div class="price-row"><span>${esc(it.text || '')}</span><b>${esc(it.desc || '')}</b></div>`).join('')}</div>`); }
        case 'brands-links': { const its = listItems(props, [['🏷️', 'ال‌جی'], ['🏷️', 'سامسونگ'], ['🏷️', 'بوش'], ['🏷️', 'سونی'], ['🏷️', 'اسنوا'], ['🏷️', 'پاکس']]); return B(`<div class="blk-title">${esc(t || 'برندهای مورد خدمت')}</div><div class="cols c${Math.max(3, gridCols(props, 6))}">${its.map(it => `<div class="fake-logo-s" title="${esc(it.text || '')}">${esc(it.icon || '🏷️')}</div>`).join('')}</div>`); }
        case 'contact-form': case 'request-form': return B(`<div class="blk-title">${esc(t || (block === 'request-form' ? 'فرم درخواست خدمات' : 'فرم تماس'))}</div><div class="form-grid"><div class="fake-input">نام و نام خانوادگی</div><div class="fake-input">شماره تماس</div><div class="fake-input">شرح مشکل</div><div class="hero-btn full">${esc(props.btnText || 'ارسال درخواست')}</div></div>`);
        case 'newsletter-form': return B(`<div class="blk-title">${esc(t || 'عضویت در خبرنامه')}</div><div class="news-row"><div class="fake-input" style="flex:1">ایمیل شما</div><div class="hero-btn">${esc(props.btnText || 'عضویت')}</div></div>`);
        case 'counter-stats': case 'stats': return B(`${TITLE}<div class="cols c${gridCols(props, 3)}" style="gap:14px">${statItemsHtml(props)}</div>`, 'stats-blk');
        case 'progress-bars': { const its = listItems(props, [['سرعت تعمیر', '90'], ['کیفیت قطعات', '95'], ['رضایت مشتری', '98']]); return B(`${TITLE}${its.map(it => { const p = Math.max(3, Math.min(100, parseInt(String(it.desc || it.icon || '80').replace(/[^0-9]/g, ''), 10) || 80)); return `<div class="pbar"><span>${esc(it.text || '')}</span><div class="track"><div class="fill" style="width:${p}%"></div></div></div>`; }).join('')}`); }
        case 'skill-bars': { const its = listItems(props, [['تعمیر برد و الکترونیک', '88'], ['یخچال و فریزر', '92'], ['ماشین لباس', '95']]); return B(`${TITLE}${its.map(it => { const p = Math.max(3, Math.min(100, parseInt(String(it.desc || it.icon || '80').replace(/[^0-9]/g, ''), 10) || 80)); return `<div class="pbar"><span>${esc(it.text || '')}</span><div class="track"><div class="fill" style="width:${p}%"></div></div></div>`; }).join('')}`); }
        case 'testimonials': { const its = listItems(props, [['علی محمدی', 'سرویس سریع و منظم بود؛ راضی بودم.'], ['مریم احمدی', 'قیمت شفاف و ضمانت واقعی.']]); return B(`<div class="blk-title">${esc(t || 'نظرات مشتریان')}</div><div class="quote">«${esc(its[0] ? its[0].text : '')}»</div>${its[0] && its[0].icon ? `<div class="feat-d" style="text-align:center;font-weight:800">— ${esc(its[0].icon)}</div>` : ''}<div class="slider-dots">● ○ ○</div>`); }
        case 'faq-accordion': { const its = listItems(props, [[null, 'هزینه عیب‌یابی چقدر است؟', 'در صورت تعمیر نزد ما رایگان است.'], [null, 'چقدر طول می‌کشد؟', 'اکثر تعمیرها همان روز انجام می‌شود.'], [null, 'ضمانت دارید؟', 'بله — ۶ ماه ضمانت کتبی.']]); return B(`<div class="blk-title">${esc(t || 'سوالات متداول')}</div>${its.map(it => `<div class="acc">${esc(it.text || '')} <b>＋</b></div>`).join('')}`); }
        case 'tabs': { const its = listItems(props, [[null, 'تعمیر'], [null, 'سرویس'], [null, 'نصب']]); return B(`<div class="blk-title">${esc(t || 'تب‌بندی محتوا')}</div><div class="tabs-row">${its.map((it, i) => `<span class="tab${i === 0 ? ' cur' : ''}">${esc(it.text || '')}</span>`).join('')}</div><div class="fake-card" style="text-align:right"><div class="fl w100"></div><div class="fl w90"></div><div class="fl w60"></div></div>`); }
        case 'timeline': { const its = listItems(props, [['✓', 'ثبت درخواست', 'انجام شد'], ['✓', 'عیب‌یابی و پیش‌فاکتور', 'انجام شد'], ['۳', 'تعمیر در حال انجام', 'در جریان'], ['۴', 'تحویل و ضمانت', 'در انتظار']]); return B(`<div class="blk-title">${esc(t || 'مراحل پیشرفت کار')}</div><div class="tl">${its.map((it, i) => `<div class="tl-item${i < 2 ? ' done' : i === 2 ? ' cur' : ''}"><span class="tl-dot">${esc(it.icon || String(i + 1))}</span><div>${esc(it.text || '')}${it.desc ? `<div class="feat-d">${esc(it.desc)}</div>` : ''}</div></div>`).join('')}</div>`); }
        case 'steps-process': { const its = listItems(props, [[null, 'تماس/ثبت درخواست'], [null, 'اعزام تکنسین'], [null, 'تعمیر و تست']]); return B(`<div class="blk-title">${esc(t || 'فرآیند کار ما')}</div><div class="steps-row">${its.map((it, i) => `${i > 0 ? '<div class="step-arrow">←</div>' : ''}<div class="step"><span class="step-n">${faDigJS(String(i + 1))}</span><div class="step-t">${esc(it.text || '')}</div></div>`).join('')}</div>`); }
        case 'gallery': { const gcols = gridCols(props, 4); const its = listItems(props, []).filter(it => String(it.text || '').trim() !== ''); let gimgs = ''; if (its.length) { its.slice(0, gcols + 3).forEach(it => { gimgs += itGalHtml(it); }); } else { for (let gi = 0; gi < gcols + 2; gi++) { gimgs += fakeImgHtml(props, '🖼️', 'min-height:90px').replace('fake-img', 'fake-img small'); } } return B(`<div class="blk-title">${esc(t || 'گالری')}</div><div class="cols c${gcols}">${gimgs}</div>`); }
        case 'image-carousel': { const its = listItems(props, []).filter(it => String(it.text || '').trim() !== ''); const first = its.length ? its[0] : null; return B(`<div class="blk-title">${esc(t || 'کاروسل تصاویر')}</div><div style="position:relative">${first ? itGalHtml(first, 'min-height:170px') : fakeImgHtml(props, '🎠', 'min-height:170px').replace('fake-img', 'fake-img wide')}<span style="position:absolute;top:50%;inset-inline-start:8px;font-size:22px;text-shadow:0 1px 4px #fff">‹</span><span style="position:absolute;top:50%;inset-inline-end:8px;font-size:22px;text-shadow:0 1px 4px #fff">›</span></div><div class="slider-dots">${its.length ? its.map((_, i) => i === 0 ? '●' : '○').join(' ') : '● ○ ○'}</div>`); }
        case 'video-embed': { const vu = String(props.videoUrl || '').trim(); return B(`<div class="blk-title">${esc(t || 'ویدیوی آموزشی')}</div><div style="position:relative">${fakeImgHtml(props, '🎬', 'min-height:190px').replace('fake-img', 'fake-img wide')}<div class="play">▶</div>${vu ? `<a href="${esc(vu)}" target="_blank" rel="noopener" style="position:absolute;bottom:8px;inset-inline-start:8px;background:rgba(15,23,42,.82);color:#fff;border-radius:9px;padding:5px 12px;font-size:10.5px;text-decoration:none" dir="ltr">▶ پخش ویدیو</a>` : ''}</div>`); }
        case 'map': { const mu = String(props.mapUrl || '').trim(); return B(`<div class="blk-title">${esc(t || 'محدوده خدمات')}</div><div class="fake-map">${esc(props.text || '📍 نقشه محدوده خدمات')}${mu ? ` — <a href="${esc(mu)}" target="_blank" rel="noopener" style="color:#2563eb">مشاهده در نقشه ↗</a>` : ''}</div>`); }
        case 'cta-phone': return B(`<div class="hero-title">${esc(t || 'همین حالا تماس بگیرید')}</div><div class="cta-num" dir="ltr">${esc(props.phone || '۰۲۱-۱۲۳۴۵۶۷۸')}</div>`, 'cta-blk');
        case 'cta-request': case 'cta-banner': return B(`<div class="hero-title">${esc(t || 'درخواست تعمیر خود را ثبت کنید')}</div><span class="hero-btn">${esc(props.btnText || '📝 ثبت درخواست')}</span>`, 'cta-blk');
        case 'sticky-mobile-cta': return B(`<span>📞 ${esc(props.phone || '۰۲۱-۱۲۳۴۵۶۷۸')}</span><span class="hero-btn">${esc(props.btnText || 'ثبت درخواست')}</span>`, 'sticky-cta-demo');
        case 'breadcrumb': { const crumbs = listItems(props, [[null, 'خانه'], [null, 'خدمات']]); return B(`${crumbs.map(c => esc(c.text || '')).join(' / ')} / <b>صفحه فعلی</b>`, 'crumb'); }
        case 'alert-notice': return B(`<div class="alert-demo ${props.alertType || 'info'}">${props.alertType === 'warning' ? '⚠️' : props.alertType === 'success' ? '✅' : 'ℹ️'} ${esc(props.text || 'سرویس در تعطیلات نیز پاسخگوی شماست')}</div>`);
        case 'button-group': { const its = listItems(props, [[null, props.btnText || 'تماس فوری'], [null, 'مشاهده خدمات'], [null, 'مقالات']]); return B(`<div class="hero-btns" style="justify-content:flex-start">${its.map((it, i) => `<span class="hero-btn${i ? ' ghost' : ''}">${esc(it.text || '')}</span>`).join('')}</div>`); }
        case 'icon-list': return B(`${TITLE}<div class="feat-list">${listItems(props, [['📞', 'پاسخگویی تلفنی', '۷ روز هفته از ۹ تا ۲۰'], ['📍', 'اعزام در محل', 'کل تهران و کرج']]).map(it => `<div class="feat-row"><span class="feat-ico">${esc(it.icon || '📋')}</span><div><b>${esc(it.text || it[1] || '')}</b>${it.desc || it[2] ? `<div class="feat-d">${esc(it.desc || it[2] || '')}</div>` : ''}</div></div>`).join('')}</div>`);
        case 'separator': return `<hr class="blk-sep">`;
        case 'spacer': return `<div class="blk-spacer" style="height:${parseInt(props.height || 46, 10)}px" title="فاصله"></div>`;
        case 'footer-simple': { const fl = listItems(props, [[null, 'خدمات'], [null, 'مقالات'], [null, 'تماس']]); return B(`<div class="fake-logo">🏗️</div><nav class="fake-nav" style="justify-content:center">${fl.map(l => `<span>${esc(l.text || '')}</span>`).join('')}</nav><div class="soc-row"><span> Telegram </span><span> Instagram </span><span> WhatsApp </span></div>${props.phone ? `<div class="feat-d" style="text-align:center;margin-top:6px">📞 ${esc(props.phone)}</div>` : ''}`, 'footer-blk'); }
        case 'footer-contact': return B(`<div class="tb-col-wrap" style="grid-template-columns:repeat(3,1fr)"><div><div class="fake-logo">🏗️</div><div class="fl w80"></div></div><div><div class="card-t">تماس</div><div class="feat-d">📞 ${esc(props.phone || '۰۲۱-۱۲۳۴۵۶۷۸')}<br>📍 تهران، خیابان نمونه</div></div><div><div class="card-t">ساعات کاری</div><div class="feat-d">${esc(props.hours || 'شنبه تا پنجشنبه')}<br>${esc(props.hours ? '' : '۹ صبح تا ۸ شب')}</div></div></div>`, 'footer-blk');
        case 'copyright': return B(`${esc(props.text || '© تمامی حقوق برای نمایندگی محفوظ است — ساخته‌شده با ❤️')}`, 'crump-blk');

        /* ════════ 🆕 v2.12: عناصر جدید (طبق درخواست — کتابخانه کامل‌تر) ════════ */
        case 'notification-bar': return B(`<div class="notif-bar ${props.notifColor || 'info'}" style="padding:8px 14px">${esc(props.text || '🎉 سرویس ویژه تعطیلات — ۱۵٪ تخفیف سرویس دوره‌ای')}</div>`);
        case 'hero-form': return B(`<div class="hero-split"><div><div class="hero-title">${esc(t || 'درخواست تعمیر آنلاین')}</div><div class="hero-sub">${esc(props.subtitle || 'فرم را پر کنید — کارشناسان ما تماس می‌گیرند')}</div><div class="hero-btns"><span class="hero-btn">📞 تماس فوری</span></div></div><div class="fake-card" style="text-align:right;background:rgba(255,255,255,.14);border:none"><div class="fake-input">نام و شماره تماس</div><div class="fake-input">نوع دستگاه</div><div class="hero-btn full" style="margin-top:8px">${esc(props.btnText || 'ثبت درخواست')}</div></div></div>`, 'hero-blk split-hero');
        case 'hero-marquee': return B(`<div class="marquee-track"><span>${esc(props.text || '⚡ اعزام تکنسین در کمتر از ۲ ساعت — ⭐ بیش از ۵۰ هزار تعمیر موفق — 🛡️ ۶ ماه ضمانت کتبی')}</span></div>`, 'marquee-blk');
        case 'brand-story': { const its = listItems(props, [['۱۳۸۵', 'شروع فعالیت', 'با یک تعمیرگاه کوچک'], ['۱۳۹۲', 'نمایندگی رسمی', 'اخذ گواهی‌های تخصصی'], ['۱۴۰۲', '۵۰ هزارمین تعمیر', 'و بیش از ۳۰ همکار']]); return B(`${TITLE}<div class="story-wrap">${its.map(it => `<div class="story-sec"><span class="story-year">${esc(it.icon || '')}</span><div><b>${esc(it.text || '')}</b><div class="feat-d">${esc(it.desc || '')}</div></div></div>`).join('')}</div>`); }
        case 'area-list': { const its = listItems(props, [[null, 'سعادت‌آباد'], [null, 'پونک'], [null, 'ولنجک'], [null, 'تجریش'], [null, 'شهرک غرب'], [null, 'نیاوران']]); return B(`${TITLE}<div class="chip-row">${its.map(a => `<span class="chip">📍 ${esc(a.text || '')}</span>`).join('')}</div>`); }
        case 'checklist': return B(`${TITLE}<div class="feat-list">${listItems(props, [['☑️', 'دستگاه را روشن و خاموش کنید و دوباره امتحان کنید'], ['☑️', 'کد خطای نمایشگر را یادداشت کنید'], ['☑️', 'صداهای غیرعادی و بوی سوختگی را بررسی کنید'], ['☑️', 'فاکتور خرید و گارانتی را آماده داشته باشید']]).map(it => `<div class="feat-row"><span class="feat-ico" style="background:#f0fdf4">${esc(it.icon || '☑️')}</span><div>${esc(it.text || it[1] || '')}</div></div>`).join('')}</div>`);
        case 'search-bar': return B(`<div class="search-wrap"><span class="search-ico">🔎</span><div class="fake-input" style="flex:1;border:none">${esc(props.placeholder || 'جستجوی کد خطا، مقاله یا دستگاه...')}</div><span class="hero-btn">جستجو</span></div>`);
        case 'certificates': { const its = listItems(props, [['🎖️', 'نمایندگی رسمی', 'از سال ۱۳۸۵'], ['🏆', 'برند برتر خدمات', 'رأی مشتریان ۱۴۰۲'], ['📋', 'مجوز اتحادیه', 'کد ۱۲۳۴۵']]); return B(`<div class="blk-title">${esc(t || 'گواهینامه‌ها و افتخارات')}</div><div class="cols c${gridCols(props, 3)}">${its.map(it => `<div class="fake-card"><div class="card-ico">${esc(it.icon || '🎖️')}</div><div class="card-t">${esc(it.text || '')}</div><div class="feat-d">${esc(it.desc || '')}</div></div>`).join('')}</div>`); }
        case 'review-grid': { const its = listItems(props, [['علی محمدی', 'سرویس سریع و منظم بود؛ راضی بودم.'], ['مریم احمدی', 'قیمت شفاف و ضمانت واقعی.'], ['رضا کریمی', 'تکنسین دقیق و حرفه‌ای اعزام شد.']]); return B(`<div class="blk-title">${esc(t || 'مشتریان ما چه می‌گویند')}</div><div class="cols c${gridCols(props, 3)}">${its.map(it => `<div class="fake-card"><div class="feat-d" style="direction:ltr;text-align:left">⭐⭐⭐⭐⭐</div><div class="feat-d">«${esc(it.text || '')}»</div><b class="feat-d">${esc(it.icon || '')}</b></div>`).join('')}</div>`); }
        case 'contact-cards': { const its = listItems(props, [['📞', 'تلفن', '۰۲۱-۱۲۳۴۵۶۷۸'], ['💬', 'واتساپ', '۰۹۱۲-۰۰۰-۰۰۰۰'], ['📍', 'آدرس', 'تهران، خیابان نمونه']]); return B(`<div class="blk-title">${esc(t || 'راه‌های ارتباطی')}</div><div class="cols c3">${its.map(it => `<div class="fake-card"><div class="card-ico">${esc(it.icon || '📞')}</div><div class="card-t">${esc(it.text || '')}</div><div class="feat-d">${esc(it.desc || '')}</div></div>`).join('')}</div>`); }
        case 'appointment-form': return B(`<div class="blk-title">${esc(t || 'رزرو نوبت سرویس')}</div><div class="form-grid"><div class="fake-input">نام و شماره تماس</div><div class="fake-input">📅 تاریخ مورد نظر</div><div class="fake-input">🕐 بازه ساعتی (۹-۱۲ / ۱۲-۱۵ / ۱۵-۱۸)</div><div class="fake-input">نوع دستگاه و شرح مشکل</div><div class="hero-btn full">${esc(props.btnText || 'رزرو نوبت')}</div></div>`);
        case 'stats-grid': { const its = statItemsFromItems(props, [['۱۲+', 'سال تجربه'], ['۵۰k', 'تعمیر موفق'], ['۹۸٪', 'رضایت'], ['۴۲', 'نوع دستگاه'], ['۲۴/۷', 'پشتیبانی'], ['۶ ماه', 'ضمانت']]); return B(`<div class="blk-title">${esc(t || 'سهند سرویس در یک نگاه')}</div><div class="cols c${gridCols(props, 3)}">${its.map(it => `<div class="fake-card" style="text-align:center"><div class="stat-n">${esc(it[0])}</div><div class="feat-d">${esc(it[1])}</div></div>`).join('')}</div>`); }
        case 'before-after': return B(`<div class="blk-title">${esc(t || 'نتیجه تعمیر حرفه‌ای')}</div><div class="ba-wrap"><div class="ba-side"><div class="ba-tag bad">قبل</div><div class="fake-card" style="text-align:center">${esc(props.text || 'دستگاه روشن نمی‌شود — کد خطا فعال')}</div></div><div class="ba-arrow">←</div><div class="ba-side"><div class="ba-tag ok">بعد</div><div class="fake-card" style="text-align:center">${esc(props.textAfter || 'کارکرد کامل — تست‌شده و ضمانت‌دار')}</div></div></div>`);
        case 'cta-whatsapp': return B(`${t ? `<div class="blk-title" style="margin-bottom:9px">${esc(t)}</div>` : ''}<div class="hero-btns"><span class="hero-btn" style="background:#16a34a">💬 ${props.phone ? 'گفتگو در واتساپ — ' + esc(props.phone) : 'گفتگو در واتساپ'}</span><span class="hero-btn ghost">📞 تماس تلفنی</span></div>`, 'cta-blk');
        case 'warranty-banner': return B(`<div class="feat-row" style="align-items:center"><span class="feat-ico" style="font-size:30px">🛡️</span><div><b style="font-size:15px">${esc(props.title || 'ضمانت کتبی ۶ ماهه روی قطعه و خدمات')}</b><div class="feat-d">${esc(props.text || 'در صورت ایراد مجدد، تعمیر اصلاحی رایگان — بدون بهانه و کاغذبازی')}</div></div><span class="hero-btn" style="margin-inline-start:auto">مشاهده شرایط</span></div>`, '');
        case 'working-hours': { const its = listItems(props, [[null, 'شنبه تا چهارشنبه', '۹ صبح تا ۸ شب'], [null, 'پنجشنبه', '۹ صبح تا ۲ ظهر'], [null, 'جمعه', '⚠️ فقط امداد فوری']]); return B(`<div class="blk-title">${esc(t || 'ساعات کاری')}</div><div class="price-table">${its.map(it => `<div class="price-row"><span>${esc(it.text || '')}</span><b>${esc(it.desc || '')}</b></div>`).join('')}</div>`); }
        case 'social-follow': { const its = listItems(props, [['📡', 'تلگرام'], ['📷', 'اینستاگرام'], ['💬', 'واتساپ'], ['▶️', 'آپارات']]); return B(`<div class="blk-title">${esc(t || 'ما را دنبال کنید')}</div><div class="hero-btns">${its.map(it => `<span class="hero-btn">${esc(it.icon || '📣')} ${esc(it.text || '')}</span>`).join('')}</div>`); }
        case 'trust-badges': return B(`${TITLE}<div class="chip-row" style="justify-content:space-around">${listItems(props, [['🛡️', 'ضمانت کتبی'], ['💳', 'پرداخت اقساطی'], ['⚡', 'اعزام فوری'], ['🏆', 'نمایندگی رسمی'], ['🔧', 'قطعات اصلی']]).map(it => `<div style="text-align:center;min-width:86px"><div style="font-size:26px">${esc(it.icon || '🏅')}</div><div class="feat-d" style="font-size:11px">${esc(it.text || '')}</div></div>`).join('')}</div>`, '');
        case 'footer-links': { const its = listItems(props, [[null, 'خدمات ما'], [null, 'مقالات آموزشی'], [null, 'کدهای خطا'], [null, 'سوالات متداول'], [null, 'قوانین و مقررات'], [null, 'حریم خصوصی']]); return B(`${t ? `<div class="blk-title" style="margin-bottom:10px">${esc(t)}</div>` : ''}<div class="tb-col-wrap" style="grid-template-columns:repeat(2,1fr)">${its.map(it => `<div class="feat-d" style="padding:3px 0">${esc(it.text || '')}${it.desc ? ` — <span dir="ltr" style="opacity:.6;font-size:10px">${esc(it.desc)}</span>` : ''}</div>`).join('')}</div>`); }
        case 'payment-methods': return B(`${t ? `<div class="blk-title" style="margin-bottom:8px">${esc(t)}</div>` : ''}<div class="chip-row" style="justify-content:center">${listItems(props, [['💳', 'پرداخت کارتی'], ['💰', 'پرداخت نقدی'], ['🧾', 'کارت به کارت'], ['📟', 'درگاه آنلاین'], ['🤝', 'اقساطی']]).map(it => `<span class="chip">${esc(it.icon || '💳')} ${esc(it.text || '')}</span>`).join('')}</div>`, '');

        /* ════════ 🆕 v3.3: عناصر جدید (۱۳ عنصر — کتابخانه کامل‌تر) ════════ */
        case 'announcement-pill': return B(`<div class="pill-announce"><span class="pill-dot"></span>${esc(t || '📣 تیتر مهم امروز')}</span></div>`, '');
        case 'heading-center': return B(`<div style="text-align:center"><div class="blk-title" style="font-size:23px">${esc(t || 'عنوان بزرگ بخش')}</div><div class="feat-d" style="font-size:13.5px;margin-top:6px">${esc(props.subtitle || 'زیرعنوان توضیحی این بخش را اینجا بنویسید')}</div><div style="width:56px;height:4px;border-radius:4px;background:var(--p,#2563eb);margin:14px auto 0"></div></div>`, '');
        case 'numbered-list': return B(`${TITLE}<div class="num-list">${listItems(props, [[null, 'عیب‌یابی تخصصی رایگان', 'بررسی کامل با دستگاه تست'], [null, 'پیش‌فاکتور شفاف', 'تأیید قیمت قبل از شروع کار'], [null, 'تعمیر با قطعات اصلی', 'همراه با ۶ ماه ضمانت']]).map((it, idx) => `<div class="num-row"><span class="num-n">${faDigJS(String(idx + 1))}</span><div><b>${esc(it.text || it[1] || '')}</b>${it.desc || it[2] ? `<div class="feat-d">${esc(it.desc || it[2] || '')}</div>` : ''}</div></div>`).join('')}</div>`, '');
        case 'info-box': return B(`<div class="info-box-demo"><span class="feat-ico" style="font-size:22px">${esc(props.icon || '💡')}</span><div><b>${esc(t || 'نکته مهم')}</b><div class="feat-d">${esc(props.text || 'متن توضیح جعبه اطلاعات...')}</div></div></div>`);
        case 'price-cards': { const its = listItems(props, [['اقتصادی', 'سرویس پایه — ۴۵۰ هزار تومان', '✅ عیب‌یابی کامل'], ['استاندارد', 'سرویس کامل — ۹۵۰ هزار تومان', '✅ شست‌وشو + تنظیم'], ['ویژه', 'سرویس + قطعه — ۱٫۵ میلیون', '✅ قطعات فابریک']]); return B(`<div class="blk-title">${esc(t || 'پلن‌های سرویس')}</div><div class="cols c3">${its.map((it, i) => `<div class="fake-card" style="${i === 1 ? 'border:2px solid var(--p,#2563eb)' : ''}"><div class="card-t">${esc(it.text || '')}</div><div class="feat-d" style="font-weight:800;color:#1e40af">${esc(it.desc || '')}</div></div>`).join('')}</div>`); }
        case 'location-cards': { const its = listItems(props, [['🏬', 'شعبه مرکزی', 'تهران، ولیعصر'], ['🏬', 'شعبه غرب', 'تهران، سعادت‌آباد']]); return B(`<div class="blk-title">${esc(t || 'شعب ما')}</div><div class="cols c${gridCols(props, 3)}">${its.map(it => `<div class="fake-card"><div class="card-ico">${esc(it.icon || '🏬')}</div><div class="card-t">${esc(it.text || '')}</div><div class="feat-d">${esc(it.desc || '')}</div></div>`).join('')}</div>`); }
        case 'expert-cards': { const its = listItems(props, [['🔧', 'مهندس کریمی', 'برد و الکترونیک'], ['❄️', 'مهندس رضایی', 'سیستم سرمایش']]); return B(`<div class="blk-title">${esc(t || 'متخصصین ما')}</div><div class="cols c${gridCols(props, 4)}">${its.map(it => `<div class="fake-card"><div class="fake-ava">${esc(it.icon || '👨‍🔧')}</div><div class="card-t">${esc(it.text || '')}</div><div class="feat-d">${esc(it.desc || '')}</div></div>`).join('')}</div>`); }
        case 'logo-cloud': { const its = listItems(props, [['🏅', 'نشان سفیر خدمت'], ['📋', 'مجوز اتحادیه'], ['🎖️', 'نمایندگی رسمی'], ['✅', 'تاییدیه کیفیت']]); return B(`${TITLE}<div class="chip-row" style="justify-content:center">${its.map(it => `<div style="text-align:center;min-width:86px"><div style="font-size:26px">${esc(it.icon || '🏅')}</div><div class="feat-d" style="font-size:11px">${esc(it.text || '')}</div></div>`).join('')}</div>`); }

        /* ════════ 🆕 v2.14: ۱۲ عنصر جدید (کتابخانه کامل‌تر — ۸۶ عنصر) ════════ */
        /* ════════ 🆕 v2.25: بیست عنصر جدید ════════ */
        case 'pros-cons': { const its = listItems(props, [['✅', 'قطعات اصلی و ضمانت‌دار'], ['✅', 'اعزام سریع تکنسین'], ['⚠️', 'زمان تعمیر ۲ تا ۴ روز کاری']]); return B(`${TITLE}<div class="cols c2"><div class="fake-card" style="border-inline-start:4px solid #16a34a"><div class="card-t" style="color:#15803d">✅ مزایا</div><div class="feat-list">${its.filter(i => !String(i.icon).startsWith('⚠')).map(i => `<div class="feat-row"><span class="feat-ico" style="background:#f0fdf4">${esc(i.icon || '✅')}</span><div>${esc(i.text || '')}</div></div>`).join('')}</div></div><div class="fake-card" style="border-inline-start:4px solid #dc2626"><div class="card-t" style="color:#b91c1c">⚠️ نکات</div><div class="feat-list">${its.filter(i => String(i.icon).startsWith('⚠') || String(i.icon).startsWith('❌')).map(i => `<div class="feat-row"><span class="feat-ico" style="background:#fef2f2">${esc(i.icon || '⚠️')}</span><div>${esc(i.text || '')}</div></div>`).join('') || '<div class="feat-d">موردی ثبت نشده — آیتم با آیکون ⚠️ یا ❌ اضافه کنید</div>'}</div></div></div>`); }
        case 'text-accent-box': return B(`<div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-inline-start:5px solid #2563eb;border-radius:12px;padding:15px 17px">${t ? `<div class="card-t" style="margin-bottom:6px">${esc(t)}</div>` : ''}<div class="feat-d" style="color:#1e40af">${esc(props.text || 'متنی که باید توجه کاربر را جلب کند.')}</div></div>`);
        case 'definition-list': { const its = listItems(props, [['🔧', 'ایرادیابی', 'بررسی کامل دستگاه برای یافتن عیب'], ['🧲', 'مگنترون', 'قطعه تولید امواج مایکروویو']]); return B(`${TITLE}<div class="feat-list">${its.map(it => `<div class="feat-row"><span class="feat-ico">${esc(it.icon || '📖')}</span><div><b>${esc(it.text || '')}</b><div class="feat-d">${esc(it.desc || '')}</div></div></div>`).join('')}</div>`); }
        case 'article-highlight': return B(`<div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;background:linear-gradient(135deg,#fff7ed,#ffedd5);border:1.5px solid #fdba74;border-radius:15px;padding:19px 21px">${fakeImgHtml(props, '🌟', 'width:130px;height:110px;flex:0 0 130px')}<div style="flex:1;min-width:200px"><div class="card-t" style="font-size:15px">${esc(t || 'محتوای ویژه')}</div>${props.subtitle ? `<div class="feat-d" style="font-weight:700;color:#9a3412">${esc(props.subtitle)}</div>` : ''}<div class="feat-d">${esc(props.text || 'خلاصه‌ای از مزیت ویژه این بخش.')}</div></div></div>`);
        case 'page-header': return B(`<div style="text-align:center;padding:18px 10px 8px"><div class="hero-title" style="font-size:24px">${esc(t || 'عنوان صفحه')}</div>${props.subtitle ? `<div class="feat-d">${esc(props.subtitle)}</div>` : ''}<div class="feat-d" style="margin-top:8px;opacity:.65">خانه / ${esc(t || 'صفحه')}</div></div>`);
        case 'steps-vertical': { const its = listItems(props, [['۱', 'ثبت درخواست', 'آنلاین یا تلفنی'], ['۲', 'عیب‌یابی و اعلام هزینه', 'شفاف و پیش از شروع'], ['۳', 'تعمیر و تحویل', 'همراه با ضمانت کتبی']]); return B(`${TITLE}<div class="feat-list">${its.map(it => `<div class="feat-row"><span class="feat-ico" style="background:linear-gradient(135deg,#2563eb,#0ea5e9);color:#fff;font-weight:800">${esc(it.icon || '•')}</span><div><b>${esc(it.text || '')}</b><div class="feat-d">${esc(it.desc || '')}</div></div></div>`).join('')}</div>`); }
        case 'service-price-cards': { const its = listItems(props, [['🧺', 'شست‌وشوی کامل ماشین لباس', 'از ۹۵۰ هزار تومان'], ['❄️', 'شارژ گاز کولر', 'از ۱٫۲ میلیون تومان'], ['🔥', 'تعویض هیتر ماشین ظرفشویی', 'از ۱٫۵ میلیون تومان']]); return B(`<div class="blk-title">${esc(t || 'تعرفه خدمات پرتقاضا')}</div><div class="cols c${gridCols(props, 3)}">${its.map(it => `<div class="fake-card"><div class="card-ico">${esc(it.icon || '🔧')}</div><div class="card-t" style="font-size:12.5px">${esc(it.text || '')}</div><div class="feat-d" style="font-weight:800;color:#1e40af">${esc(it.desc || '')}</div></div>`).join('')}</div>`); }
        case 'feature-icons-grid': { const its = listItems(props, [['🧊', 'یخچال'], ['🧺', 'لباسشویی'], ['📺', 'تلویزیون'], ['🔥', 'فر و اجاق'], ['🍵', 'کتری برقی'], ['☕', 'قهوه‌ساز'], ['🌪', 'جاروبرقی'], ['💧', 'آبگرمکن']]); return B(`<div class="blk-title">${esc(t || 'خدمات ما در یک نگاه')}</div><div class="cols c${gridCols(props, 4)}">${its.map(it => `<div class="fake-card" style="text-align:center;padding:15px 8px"><div style="font-size:31px">${esc(it.icon || '🔧')}</div><div class="feat-d" style="font-weight:700;margin-top:6px">${esc(it.text || '')}</div></div>`).join('')}</div>`); }
        case 'callback-form': return B(`<div class="blk-title">${esc(t || 'درخواست تماس کارشناس')}</div><div class="news-row"><div class="fake-input" style="flex:1">شماره تماس شما</div><div class="hero-btn">${esc(props.btnText || 'با من تماس بگیرید')}</div></div><div class="feat-d" style="margin-top:7px">✅ کارشناسان ما در کمتر از ۱۵ دقیقه تماس می‌گیرند</div>`);
        case 'survey-form': { const its = listItems(props, [['⭐', 'بسیار راضی'], ['👍', 'راضی'], ['😐', 'معمولی']]); return B(`<div class="blk-title">${esc(t || 'میزان رضایت شما از سرویس؟')}</div><div class="cols c${Math.max(2, its.length)}" style="gap:9px">${its.map(it => `<div class="fake-card" style="text-align:center;padding:13px 8px;cursor:pointer"><div style="font-size:23px">${esc(it.icon || '⭐')}</div><div class="feat-d" style="font-weight:700">${esc(it.text || '')}</div></div>`).join('')}</div>`); }
        case 'chat-widget': return B(`<div style="display:flex;justify-content:flex-end"><div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:15px 15px 3px 15px;padding:11px 15px;max-width:290px;box-shadow:0 8px 22px rgba(2,8,23,.12)"><div style="font-size:12.5px"><b>💬 ${esc(t || 'پشتیبانی آنلاین')}</b></div><div class="feat-d">سلام! چطور می‌تونیم کمکتون کنیم؟</div><div style="display:flex;gap:6px;margin-top:8px"><span class="hero-btn" style="font-size:11px;padding:5px 12px">شروع گفتگو</span></div></div></div>`);
        case 'vote-poll': { const its = listItems(props, [['🧺', 'لباسشویی'], ['❄️', 'یخچال'], ['🔥', 'ماکروویو']]); const total = its.length * 12 + 30; return B(`<div class="blk-title">${esc(t || 'رای‌گیری')}</div><div class="cap-demo">${its.map((it, i) => { const p = Math.round((total - i * 11) / total * 100); return `<div class="cap-row"><span class="cap-h">${esc(it.icon || '')} ${esc(it.text || '')}</span><div class="track" style="flex:1"><div class="fill" style="width:${p}%"></div></div><span class="cap-l">${faDigJS(p)}٪</span></div>`; }).join('')}</div>`); }
        case 'video-grid': { const gcols = gridCols(props, 3); let gimgs = ''; for (let gi = 0; gi < gcols * 2; gi++) { gimgs += `<div style="position:relative">${fakeImgHtml(props, '🎬', 'min-height:96px').replace('fake-img', 'fake-img small')}<div class="play" style="width:30px;height:30px;font-size:12px">▶</div></div>`; } return B(`<div class="blk-title">${esc(t || 'ویدیوهای آموزشی')}</div><div class="cols c${gcols}">${gimgs}</div>`); }
        case 'logo-marquee': { const its = listItems(props, [['🏷️', 'ال‌جی'], ['🏷️', 'سامسونگ'], ['🏷️', 'بوش'], ['🏷️', 'سونی'], ['🏷️', 'پاکس'], ['🏷️', 'اسنوا']]); return B(`<div class="blk-title">${esc(t || 'برندهای مورد خدمت')}</div><div style="overflow:hidden;background:#f8fafc;border-radius:12px;padding:11px 0"><div class="chip-row" style="animation:tickMove 18s linear infinite;white-space:nowrap;width:max-content">${its.concat(its).map(it => `<span class="chip" style="margin-inline-end:9px">${esc(it.icon || '🏷️')} ${esc(it.text || '')}</span>`).join('')}</div></div>`); }
        case 'tag-cloud': { const its = listItems(props, [[null, 'تعمیر ماشین لباس'], [null, 'کد خطا SE'], [null, 'شارژ گاز کولر'], [null, 'بک‌لایت تلویزیون'], [null, 'مگنترون'], [null, 'سرویس دوره‌ای'], [null, 'برد الکترونیک'], [null, 'نصب ظرفشویی']]); const sizes = ['12px', '14px', '13px', '15px', '12.5px', '14.5px']; return B(`${TITLE}<div class="chip-row" style="justify-content:center;gap:8px">${its.map((it, i) => `<span class="chip" style="font-size:${sizes[i % sizes.length]};font-weight:${i % 3 === 0 ? 800 : 600};opacity:${0.72 + (i % 3) * 0.09}">${esc(it.text || '')}</span>`).join('')}</div>`); }
        case 'quote-slider': { const its = listItems(props, [['علی محمدی', 'سرویس سریع و منظم بود؛ راضی بودم.'], ['مریم احمدی', 'قیمت شفاف و ضمانت واقعی.'], ['رضا کریمی', 'تکنسین دقیق و حرفه‌ای اعزام شد.']]); const q = its[0] || { text: '', icon: '' }; return B(`<div class="blk-title">${esc(t || 'مشتریان چه می‌گویند')}</div><div class="quote" style="text-align:center;font-size:15px">«${esc(q.text)}»</div><div class="feat-d" style="text-align:center;font-weight:800;margin-top:6px">— ${esc(q.icon)}</div><div class="slider-dots" style="margin-top:8px">${its.map((_, i) => i === 0 ? '●' : '○').join(' ')}</div>`); }
        case 'stats-circles': { const its = listItems(props, [['۹۲٪', 'تعمیر در روز اول'], ['۸۷٪', 'رضایت کامل'], ['۹۶٪', 'حل قطعی ایراد']]); return B(`<div class="blk-title">${esc(t || 'عملکرد ما در آمار واقعی')}</div><div class="cols c${Math.max(2, Math.min(4, its.length))}" style="gap:14px">${its.map(it => { const num = String(it.icon || '80').replace(/[^0-9]/g, '') || '80'; const deg = Math.round(parseInt(num, 10) / 100 * 360); return `<div style="text-align:center"><div style="width:86px;height:86px;margin:0 auto;border-radius:50%;background:conic-gradient(#2563eb ${deg}deg, #e2e8f0 ${deg}deg);display:flex;align-items:center;justify-content:center"><div style="width:66px;height:66px;background:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:16.5px;color:#1e40af">${esc(it.icon || num)}</div></div><div class="feat-d" style="margin-top:8px;font-weight:700">${esc(it.text || '')}</div></div>`; }).join('')}</div>`); }
        case 'counter-big': { const its = listItems(props, [['۵۰,۰۰۰+', 'تعمیر تکمیل‌شده']]); const it0 = its[0] || { icon: '۵۰,۰۰۰+', text: 'تعمیر تکمیل‌شده' }; return B(`<div style="text-align:center;background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1.5px solid #93c5fd;border-radius:16px;padding:26px 18px"><div class="hero-title" style="font-size:37px;background:linear-gradient(135deg,#1e40af,#0ea5e9);-webkit-background-clip:text;background-clip:text;color:transparent">${esc(it0.icon || it0.text || '')}</div><div class="feat-d" style="font-weight:800;font-size:14px;margin-top:5px">${esc(it0.icon ? it0.text : 'شمارنده')}</div></div>`); }
        case 'brand-stats-bar': { const its = listItems(props, [['۱۵+', 'سال تجربه'], ['۴۲', 'نوع دستگاه تخصصی'], ['۲۴/۷', 'پشتیبانی'], ['۶ ماه', 'ضمانت کتبی']]); return B(`${TITLE}<div class="stats-strip" style="background:linear-gradient(135deg,#0f172a,#1e3a8a)">${its.map(it => `<span class="ss-item"><b>${esc(it.icon || '')}</b> ${esc(it.text || '')}</span>`).join('<span class="ss-sep"></span>')}</div>`); }
        case 'emergency-strip': return B(`<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border-radius:13px;padding:13px 19px"><b style="font-size:14px">${esc(props.text || '🚑 امداد تعمیر فوری — ۲۴ ساعته')}</b><span class="hero-btn" style="background:#fff;color:#b91c1c">📞 ${esc(props.phone || '۰۲۱-۱۲۳۴۵۶۷۸')}</span></div>`);
        case 'stats-strip': return B(`${TITLE}<div class="stats-strip">${statStripHtml(props)}</div>`, 'stats-strip-blk');
        case 'benefits-list': return B(`${TITLE}<div class="feat-list">${listItems(props, [[null, 'اعزام تکنسین در کمتر از ۲ ساعت'], [null, 'قطعات فابریک با فاکتور معتبر'], [null, '۶ ماه ضمانت کتبی قطعه و خدمات'], [null, 'پیش‌فاکتور شفاف قبل از شروع کار'], [null, 'پیگیری وضعیت درخواست آنلاین']]).map(it => `<div class="feat-row"><span class="feat-ico" style="background:#f0fdf4">${esc(it.icon || '✅')}</span><div><b>${esc(it.text || it[1] || '')}</b></div></div>`).join('')}</div>`);
        case 'warning-box': return B(`<div class="warning-box-demo"><span class="feat-ico" style="background:#fef2f2;font-size:22px">${esc(props.icon || '⚠️')}</span><div><b style="color:#b91c1c">${esc(t || 'هشدار ایمنی مهم')}</b><div class="feat-d">${esc(props.text || 'قبل از هرگونه باز کردن دستگاه، برق را کاملاً قطع کنید.')}</div></div></div>`);
        case 'brand-intro-card': return B(`<div class="brand-intro-demo"><div class="fake-logo" style="font-size:34px">🏗️</div><div style="flex:1"><div class="blk-title" style="margin-bottom:4px">${esc(t || 'نمایندگی رسمی خدمات')}</div><div class="feat-d">${esc(props.subtitle || 'بیش از یک دهه تجربه تخصصی')}</div><div class="stars" style="font-size:11px;margin-top:5px">⭐⭐⭐⭐⭐ <b>۴.۹ از ۵</b></div></div><span class="hero-btn" style="align-self:center">مشاهده خدمات</span></div>`, '');
        case 'author-box': return B(`<div class="author-box-demo"><div class="fake-ava" style="font-size:38px">${esc(props.icon || '👨‍🔧')}</div><div style="flex:1"><b style="font-size:14px">${esc(t || 'مهندس کریمی')}</b><div class="feat-d">کارشناس برد و الکترونیک — ۱۴ سال تجربه</div><div class="feat-d" style="margin-top:4px">${esc(props.text || 'متخصص تعمیر برد‌های اصلی لباسشویی، یخچال و کولر گازی با رویکرد تعمیر اصلاحی.')}</div></div></div>`, '');
        case 'download-card': return B(`<div class="download-card-demo"><span class="feat-ico" style="font-size:30px;background:#eff6ff">📄</span><div style="flex:1"><div class="blk-title" style="margin-bottom:3px;text-align:right">${esc(t || 'بروشور خدمات ما')}</div><div class="feat-d">${esc(props.subtitle || 'فهرست کامل خدمات و تعرفه‌ها در یک فایل PDF')}</div></div><span class="hero-btn">${esc(props.btnText || '⬇ دانلود بروشور')}</span></div>`, '');
        case 'schedule-table': { const its = listItems(props, [[null, 'شنبه', '۹ تا ۲۰'], [null, 'یکشنبه تا چهارشنبه', '۹ تا ۲۰'], [null, 'پنجشنبه', '۹ تا ۱۴'], [null, 'جمعه', 'فقط امداد فوری']]); return B(`<div class="blk-title">${esc(t || 'ساعات کاری ما')}</div><div class="price-table">${its.map(it => `<div class="price-row"><span>${esc(it.text || '')}</span><b>${esc(it.desc || '')}</b></div>`).join('')}</div>`); }
        case 'price-highlight': return B(`<div class="fake-card price-highlight-demo" style="text-align:right">${props.badge ? `<span class="badge badge-warning" style="font-size:10px">${esc(props.badge)}</span>` : ''}<div class="blk-title" style="text-align:right;margin:8px 0 3px">${esc(t || 'سرویس دوره‌ای کامل')}</div><div class="stat-n" style="font-size:31px;text-align:right">${esc(props.price || '۴۵۰ هزار تومان')}</div><div class="feat-d" style="margin:7px 0 11px">${esc(props.subtitle || 'شامل شست‌وشو، کالیبراسیون و تست ایمنی + ۶ ماه ضمانت')}</div><span class="hero-btn full">${esc(props.btnText || 'رزرو همین حالا')}</span></div>`, '');
        case 'feature-table': { const its = listItems(props, [[null, 'عیب‌یابی رایگان'], [null, 'ضمانت ۶ ماهه'], [null, 'قطعات فابریک']]); return B(`<div class="blk-title">${esc(t || 'مقایسه پلن‌های سرویس')}</div><div class="feature-table-demo"><div class="ft-row ft-head"><span>ویژگی</span><b>اقتصادی</b><b>استاندارد</b><b class="ft-hl">ویژه</b></div>${its.map(it => `<div class="ft-row"><span>${esc(it.text || '')}</span><b>—</b><b>✓</b><b class="ft-hl">✓</b></div>`).join('')}</div>`); }
        case 'quick-contact-form': return B(`<div class="quick-form-demo"><div class="fake-input" style="flex:1">📱 شماره تماس شما</div><span class="hero-btn">${esc(props.btnText || 'درخواست تماس')}</span></div><div class="feat-d" style="text-align:center;margin-top:7px">${esc(props.subtitle || t || 'کارشناسان ما در کمتر از ۱۵ دقیقه تماس می‌گیرند')}</div>`, '');
        case 'related-links': { const its = listItems(props, [[null, 'کد خطای LE لباسشویی ال‌جی — معنی و رفع'], [null, '۱۰ علامت خرابی کمپرسور یخچال'], [null, 'راهنمای نگهداری ماکروویو']]); return B(`${TITLE}<div class="feat-list">${its.map(it => `<div class="feat-row"><span class="feat-ico">🔗</span><div>${esc(it.text || '')}</div></div>`).join('')}</div>`); }
        case 'warranty-steps': { const its = listItems(props, [[null, 'ثبت سریال دستگاه'], [null, 'صدور برگه ضمانت'], [null, 'پشتیبانی ۶ ماهه']]); return B(`<div class="blk-title">${esc(t || 'گارانتی ما چگونه کار می‌کند')}</div><div class="steps-row">${its.map((it, i) => `${i > 0 ? '<div class="step-arrow">←</div>' : ''}<div class="step"><span class="step-n">${esc(it.icon || faDigJS(String(i + 1)))}</span><div class="step-t">${esc(it.text || '')}</div></div>`).join('')}</div>`); }
        case 'social-proof': return B(`<div class="soc-proof"><div class="ava-stack"><span class="fake-ava" style="width:34px;height:34px;font-size:13px">👩</span><span class="fake-ava" style="width:34px;height:34px;font-size:13px;margin-inline-start:-10px">🧑</span><span class="fake-ava" style="width:34px;height:34px;font-size:13px;margin-inline-start:-10px">👨</span><span class="fake-ava" style="width:34px;height:34px;font-size:11px;margin-inline-start:-10px">+۵۰k</span></div><div><div class="stars">⭐⭐⭐⭐⭐ <b>۴.۹ از ۵</b></div><div class="feat-d">${esc(props.text || 'بیش از ۵۰ هزار مشتری به ما اعتماد کرده‌اند')}</div></div></div>`);
        case 'link-buttons': return B(`${TITLE}<div class="hero-btns" style="justify-content:flex-start">${listItems(props, [[null, '📄 دانلود بروشور'], [null, '🔎 پیگیری درخواست'], [null, '🧾 فاکتور آنلاین']]).map((it, idx) => `<span class="hero-btn${idx ? ' ghost' : ''}">${esc(it.text || it[1] || '')}</span>`).join('')}</div>`, '');
        case 'promo-card': return B(`<div class="promo-card-demo"><div><span class="badge badge-warning" style="font-size:10.5px">🎁 پیشنهاد ویژه</span><div class="blk-title" style="font-size:19px;margin:9px 0 5px">${esc(t || 'کمپین سرویس بهاره')}</div><div class="feat-d">${esc(props.subtitle || 'تا ۲۵٪ تخفیف — تا پایان ماه')}</div></div><div style="text-align:center"><div class="stat-n" style="font-size:33px">۲۵٪</div><span class="hero-btn" style="margin-top:8px">همین حالا رزرو کنید</span></div></div>`, '');
        case 'divider-icon': return B(`<div class="divider-ico"><span class="divider-line"></span><span style="font-size:17px">${esc(props.icon || '🔧')}</span><span class="divider-line"></span></div>`, 'divider-blk');
        case 'contact-info-bar': return B(`<div class="chip-row" style="justify-content:space-between"><span class="chip">📞 <b dir="ltr">${esc(props.phone || '۰۲۱-۱۲۳۴۵۶۷۸')}</b></span><span class="chip">🕐 ${esc(props.hours || 'شنبه-پنجشنبه ۹-۲۰')}</span><span class="chip">📍 ${esc(props.location || 'تهران')}</span><span class="chip">💬 ${esc(props.whatsapp || 'واتساپ')}</span></div>`);
        /* ════════ 🆕 v2.15: ۱۴ عنصر جدید (کتابخانه تا ۱۱۴) ════════ */
        case 'ticker-bar': return B(`<div class="ticker-bar-demo"><span class="ticker-tag">🔴 زنده</span><div class="ticker-track"><span>${esc(props.text || '⚡ اعزام تکنسین فوری · 🧊 شارژ گاز کولر از ۹۰۰ هزار تومان · 🛡️ گارانتی ۶ ماهه · 📞 پاسخگویی ۷ روز هفته · 🔧 قطعات فابریک با فاکتور')}</span></div></div>`, '');
        case 'booking-calendar': return B(`<div class="blk-title">${esc(t || 'رزرو نوبت آنلاین')}</div><div class="cal-demo">${['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'].map(d => `<span class="cal-dow">${d}</span>`).join('')}${Array.from({ length: 28 }, (_, i) => `<span class="cal-day${[3, 8, 14, 19, 25].includes(i) ? ' busy' : ''}${[5, 11, 22].includes(i) ? ' sel' : ''}">${faDigJS(i + 1)}</span>`).join('')}</div><div class="feat-d" style="text-align:center;margin-top:8px">روزهای <span class="badge badge-danger" style="font-size:9.5px">پر</span> ظرفیت ندارند — روز سبز انتخابی شماست</div>`, '');
        case 'warranty-check': return B(`<div class="blk-title">${esc(t || 'استعلام گارانتی')}</div><div class="quick-form-demo" style="max-width:100%"><div class="fake-input" style="flex:1;direction:ltr">SN-XXXX-1234</div><span class="hero-btn">${esc(props.btnText || 'استعلام')}</span></div><div class="feat-d" style="text-align:center;margin-top:7px">شماره سریال دستگاه را وارد کنید — وضعیت گارانتی همان لحظه نمایش داده می‌شود</div>`, '');
        case 'price-estimate': return B(`<div class="blk-title">${esc(t || 'برآورد هزینه تعمیر')}</div><div class="form-grid"><div class="fake-input">🌀 نوع دستگاه (لباسشویی، یخچال...)</div><div class="fake-input">🔧 نوع ایراد (نمایش کد، صدا، نشتی...)</div><div class="fake-input">📍 منطقه</div><div class="hero-btn full">🧮 محاسبه فوری برآورد</div></div><div class="feat-d" style="text-align:center;margin-top:7px">برآورد تقریبی + زمان لازم برای تعمیر، همین لحظه</div>`, '');
        case 'device-error-lookup': return B(`<div class="blk-title">${esc(t || 'جستجوی کد خطای دستگاه')}</div><div class="search-wrap"><span class="search-ico">🔢</span><div class="fake-input" style="flex:1;border:none;direction:ltr">E4 / LE / CH-05 ...</div><span class="hero-btn">جستجو</span></div><div class="feat-d" style="text-align:center;margin-top:7px">کد روی نمایشگر دستگاه را وارد کنید — علت، راه‌حل فوری و هزینه تعمیر را ببینید</div>`, '');
        case 'live-queue': { const its = listItems(props, [['🟢', 'دریافت و عیب‌یابی', 'در حال انجام — ۲ دستگاه'], ['🟡', 'تعمیر برد', 'در صف — ۱ دستگاه'], ['🔴', 'آماده تحویل', '۳ دستگاه']]); return B(`<div class="blk-title">${esc(t || 'وضعیت صف تعمیرات — زنده')}</div><div class="queue-demo">${its.map(it => `<div class="queue-row"><span>${esc(it.icon || '🟢')} ${esc(it.text || '')}</span><b>${esc(it.desc || '')}</b></div>`).join('')}</div>`); }
        case 'hourly-capacity': { const its = listItems(props, [['۹–۱۲', '20', 'کم‌تقاضا'], ['۱۲–۱۵', '60', 'متوسط'], ['۱۵–۱۸', '85', 'پرتقاضا']]); return B(`<div class="blk-title">${esc(t || 'ظرفیت سرویس امروز')}</div><div class="cap-demo">${its.map(it => { const p = Math.max(5, Math.min(100, parseInt(String(it.text || '50').replace(/[^0-9]/g, ''), 10) || 50)); return `<div class="cap-row"><span class="cap-h">${esc(it.icon || '')}</span><div class="track" style="flex:1"><div class="fill" style="width:${p}%"></div></div><span class="cap-l">${esc(it.desc || '')}</span></div>`; }).join('')}</div>`); }
        case 'faq-search': return B(`<div class="blk-title">${esc(t || 'جستجو در سوالات متداول')}</div><div class="search-wrap"><span class="search-ico">🔎</span><div class="fake-input" style="flex:1;border:none">${esc(props.placeholder || 'سوال خود را بنویسید...')}</div><span class="hero-btn">پرسیدن</span></div><div class="chip-row" style="margin-top:10px;justify-content:center">${['لباسشویی آب تخلیه نمی‌کند', 'یخچال برق دارد ولی خنک نمی‌کند', 'کد E4 یعنی چه؟'].map(q => `<span class="chip">❓ ${q}</span>`).join('')}</div>`, '');
        case 'faq-category': { const its = listItems(props, [['🌀', 'لباسشویی و ظرفشویی', '۱۲ سوال'], ['❄️', 'یخچال و فریزر', '۹ سوال'], ['📺', 'تلویزیون', '۷ سوال']]); return B(`<div class="blk-title">${esc(t || 'سوالات متداول بر اساس موضوع')}</div><div class="cols c3">${its.map(it => `<div class="fake-card"><div class="card-ico">${esc(it.icon || '❓')}</div><div class="card-t">${esc(it.text || '')}</div><div class="feat-d">${esc(it.desc || '')}</div></div>`).join('')}</div>`); }
        case 'before-after-slider': return B(`<div class="blk-title">${esc(t || 'مقایسه تصویری قبل و بعد')}</div><div class="bas-demo"><div class="bas-before" style="${props.imageUrl ? '' : ''}">${props.imageUrl ? `<img src="${esc(props.imageUrl)}" alt="" style="width:100%;height:100%;object-fit:cover;filter:grayscale(1) contrast(1.1)">` : '🧺 فرسوده'}<span class="bas-tag">قبل</span></div><div class="bas-handle">⇔</div><div class="bas-after">✨ <b>مثل روز اول</b><span class="bas-tag ok">بعد</span></div></div>`, '');
        case 'social-wall': { const its = listItems(props, [['📷', 'نکته سرویس دوره‌ای', '۲ روز پیش'], ['🎥', 'ویدیوی عیب‌یابی زنده', '۵ روز پیش'], ['📝', 'معرفی تکنسین هفته', '۱ هفته پیش']]); return B(`<div class="blk-title">${esc(t || 'آخرین پست‌های ما')}</div><div class="cols c3">${its.map(it => `<div class="fake-card"><div style="font-size:24px">${esc(it.icon || '📷')}</div><div class="card-t" style="font-size:12px">${esc(it.text || '')}</div><div class="feat-d">${esc(it.desc || '')}</div></div>`).join('')}</div>`); }
        case 'newsletter-popup': return B(`<div class="np-demo-wrap"><div class="np-demo"><span class="feat-ico" style="font-size:30px;background:#eff6ff">📧</span><div><b style="font-size:14px">${esc(t || 'قبل از رفتن، پیشنهاد ویژه!')}</b><div class="feat-d">${esc(props.subtitle || 'عضویت در خبرنامه = ۱۰٪ تخفیف اولین سرویس')}</div></div><div class="news-row" style="margin-top:10px"><div class="fake-input" style="flex:1">ایمیل شما</div><span class="hero-btn">${esc(props.btnText || 'دریافت کد تخفیف')}</span></div></div><div class="feat-d" style="text-align:center;margin-top:6px">پیش‌نمایش پاپ‌آپ — پس از ۳۰ ثانیه معطلی کاربر نمایش داده می‌شود</div></div>`, '');
        case 'credit-trust': { const its = listItems(props, [['98', 'امتیاز اعتماد مشتریان']]); const sc = its[0] ? String(its[0].icon || its[0].text || '98').replace(/[^0-9]/g, '') || '98' : '98'; return B(`<div class="ct-demo"><div class="ct-score">${faDigJS(sc)}<small>/${faDigJS('100')}</small></div><div style="flex:1"><b style="font-size:14px">${esc(its[0] ? its[0].text : 'امتیاز اعتماد مشتریان')}</b><div class="feat-d" style="margin-top:4px">بر اساس نظرسنجی مستقل مشتریان در ۱۲ ماه گذشته</div></div></div>`); }
        case 'brand-badges-row': return B(`${TITLE}<div class="chip-row" style="justify-content:center;gap:10px">${listItems(props, [['🎖️', 'تعمیرکار رسمی سازمان فنی'], ['🛡️', 'بیمه مسئولیت حرفه‌ای'], ['📋', 'مجوز رسمی اتحادیه'], ['🔬', 'تخصص برد و الکترونیک']]).map(it => `<span class="chip" style="padding:8px 14px;font-size:11.5px">${esc(it.icon || '🏅')} ${esc(it.text || '')}</span>`).join('')}</div>`, '');

        /* ════════ 🆕 v2.17: ۱۶ عنصر جدید (کتابخانه ۱۳۰ عنصر) ════════ */
        case 'hero-minimal': return B(`<div class="hero-title" style="font-size:30px">${esc(t || 'تعمیر تخصصی، بدون معطلی')}</div>${props.subtitle ? `<div class="hero-sub">${esc(props.subtitle)}</div>` : ''}<div class="hero-btns"><span class="hero-btn">${esc(props.btnText || 'درخواست تعمیر')}</span></div>`, 'hero-blk hero-minimal-blk');
        case 'hero-glass': return B(`<div class="glass-hero-demo"><div class="hero-title">${esc(t || 'خدمات رسمی پس از فروش')}</div><div class="hero-sub">${esc(props.subtitle || 'شفافیت کامل در قیمت و فرآیند')}</div><div class="hero-btns"><span class="hero-btn ghost">مشاهده خدمات</span><span class="hero-btn">تماس</span></div></div>`, 'hero-blk');
        case 'logo-strip': return B(`${TITLE}<div class="logo-strip-demo">${'<div class="fake-logo-s">🏷️</div>'.repeat(6)}</div>`);
        case 'text-columns': { const ps = String(props.text || 'پاراگراف اول متن...').split('\n').filter(Boolean); const half = Math.ceil(ps.length / 2) || 1; const c1 = ps.slice(0, half), c2 = ps.slice(half); return B(`${TITLE}<div class="text-cols-demo"><div class="pv-text">${c1.map(p => `<p>${esc(p)}</p>`).join('')}</div><div class="pv-text">${(c2.length ? c2 : ['متن ستون دوم — پاراگراف‌ها با Enter جدا می‌شوند.']).map(p => `<p>${esc(p)}</p>`).join('')}</div></div>`); }
        case 'brand-values': return B(`${TITLE}<div class="cols c${gridCols(props, 3)}">${listItems(props).map(i => `<div class="fake-card"><div class="card-ico">${esc(i.icon || '💎')}</div><div class="card-t">${esc(i.text || 'ارزش')}</div></div>`).join('')}</div>`);
        case 'tech-tips': return B(`${TITLE}<div class="steps-compact-demo">${listItems(props).map((i, n) => `<div class="sc-row"><span class="sc-num">${esc(i.icon || String(n + 1))}</span><div class="feat-d" style="font-size:12.5px">${esc(i.text || 'نکته فنی')}</div></div>`).join('')}</div>`);
        case 'price-compare': return B(`${TITLE}<div class="cols c${gridCols(props, 3)}"><div class="fake-card"><div class="card-t">اقتصادی</div><div class="stat-n" style="font-size:22px">پایه</div><div class="fl w90"></div><div class="fl w70"></div></div><div class="fake-card" style="border:2px solid var(--primary)"><span class="badge badge-warning" style="font-size:9.5px">پرطرفدار</span><div class="card-t">استاندارد</div><div class="stat-n" style="font-size:22px">کامل</div><div class="fl w100"></div><div class="fl w80"></div></div><div class="fake-card"><div class="card-t">ویژه</div><div class="stat-n" style="font-size:22px">طلایی</div><div class="fl w90"></div><div class="fl w60"></div></div></div>`);
        case 'guarantee-card': return B(`<div class="guarantee-demo"><span style="font-size:42px">🛡️</span><div style="flex:1"><div class="blk-title" style="margin-bottom:4px">${esc(t || '۶ ماه ضمانت کتبی')}</div><div class="feat-d">${esc(props.subtitle || 'تمام تعمیرات با ضمانت کتبی و قابل پیگیری انجام می‌شود.')}</div></div><span class="hero-btn" style="align-self:center">${esc(props.btnText || 'مشاهده شرایط')}</span></div>`, 'guarantee-blk');
        case 'cta-timer': return B(`<div class="hero-title" style="font-size:24px">${esc(t || 'تخفیف سرویس دوره‌ای')}</div><div class="hero-sub">${esc(props.subtitle || 'فقط تا پایان هفته — بعد از پایان تایمر قیمت عادی است')}</div>${countdownHtml(props)}<div class="hero-btns"><span class="hero-btn">همین حالا رزرو کنید</span></div>`, 'hero-blk cta-timer-blk');
        case 'urgent-repair': return B(`<div class="urgent-demo"><span style="font-size:34px">🚨</span><div style="flex:1"><b style="font-size:15px">${esc(t || 'تعمیر فوری نیاز دارید؟')}</b><div class="feat-d">۲۴ ساعته — ۷ روز هفته اعزام تکنسین</div></div><div style="text-align:center"><div class="feat-d" style="font-size:10.5px">تماس فوری</div><div class="stat-n" style="font-size:19px" dir="ltr">📞 ${esc(props.phone || '۰۲۱-۱۲۳۴۵۶۷۸')}</div><span class="hero-btn" style="margin-top:5px">${esc(props.btnText || 'درخواست اعزام')}</span></div></div>`, 'urgent-blk');
        case 'faq-mini': return B(`<div class="faq-mini-demo"><div class="sc-row"><span class="sc-num">؟</span><b style="font-size:13.5px">${esc(t || 'سوال متداول')}</b></div><div class="feat-d" style="margin-top:7px;font-size:12.5px">${esc(props.text || 'پاسخ کارشناسان ما به سوال متداول...')}</div></div>`);
        case 'reviews-carousel': return B(`${TITLE}<div class="cols c${gridCols(props, 3)}">${['عالی بود، همان روز آمدند', 'قیمت منصفانه و کار تمیز', 'دستگاه ۵ ساله‌ام مثل نو شد'].map(r => `<div class="fake-card"><div class="stars">⭐⭐⭐⭐⭐</div><div class="feat-d">«${r}»</div><div class="fake-ava" style="width:26px;height:26px;font-size:11px">😊</div></div>`).join('')}</div><div class="slider-dots" style="margin-top:8px">● ○ ○</div>`, 'reviews-blk');
        case 'steps-compact': { const def = [['۱', 'ثبت درخواست آنلاین یا تلفنی'], ['۲', 'اعزام تکنسین در زمان شما'], ['۳', 'تعمیر، تست و تحویل با ضمانت']]; const its = (Array.isArray(props.items) && props.items.length ? props.items : def.map(i => ({ icon: i[0], text: i[1] }))); return B(`${TITLE}<div class="steps-compact-demo">${its.map(i => `<div class="sc-row"><span class="sc-num">${esc(i.icon || '•')}</span><div class="feat-d" style="font-size:12.5px">${esc(i.text || '')}</div></div>`).join('')}</div>`); }
        case 'appointment-compact': return B(`<div class="apt-compact-demo"><b style="font-size:14px">${esc(t || 'نوبت تعمیر رزرو کنید')}</b><div class="news-row" style="margin-top:9px"><div class="fake-input" style="flex:1">شماره تماس شما</div><div class="fake-input" style="flex:1">دستگاه + مشکل</div><div class="hero-btn">${esc(props.btnText || 'رزرو نوبت')}</div></div></div>`);
        case 'contact-map-split': return B(`${TITLE}<div class="split"><div><div class="chip-row" style="flex-direction:column;align-items:stretch;gap:7px"><span class="chip">📞 <b dir="ltr">${esc(props.phone || '۰۲۱-۱۲۳۴۵۶۷۸')}</b></span><span class="chip">📍 تهران، خیابان نمونه، پلاک ۱۲</span><span class="chip">🕐 شنبه تا پنجشنبه ۹ تا ۲۰</span></div></div><div class="fake-img" style="min-height:130px;background:linear-gradient(135deg,#e2e8f0,#cbd5e1)"><span style="font-size:30px">🗺️</span></div></div>`);
        case 'stats-inline': return B(`${TITLE}<div class="stats-strip">${statStripHtml(props)}</div>`, 'stats-strip-blk');
        default: return B(`${TITLE}<div class="fake-lines"><div class="fl w90"></div><div class="fl w70"></div></div>`);
    }
}

/* استایل‌های درون‌بوم رندر زنده (تزریق یک‌بار) */
(function injectLiveStyles() {
    const css = `
.tb-live { font-family: var(--font-body, Vazirmatn, Tahoma, 'Segoe UI', sans-serif); background: #f8fafc; border-radius: 12px; overflow: hidden; color:#1e293b; direction: rtl; text-align: right; }
.tb-live .blk-title, .tb-live .hero-title, .tb-live .card-t, .tb-live .fake-cta, .tb-live .hero-btn, .tb-live .stat-n, .tb-live .notif-bar, .tb-live .topbar-blk, .tb-live .price-row b, .tb-live .cta-num, .tb-live .story-year, .tb-live .num-n { font-family: var(--font-heading, Vazirmatn, Tahoma, sans-serif); }
.blk { background:#fff; padding:24px 20px; border-bottom:1px dashed #e2e8f0; position: relative; }
.blk:last-child { border-bottom: none; }
.blk-pad-compact { padding: 12px 14px; } .blk-pad-roomy { padding: 42px 26px; } .blk-pad-none { padding: 0; }
.blk-bg-surface { background:#f1f5f9; } .blk-bg-primary { background:linear-gradient(135deg,#1e40af,#0ea5e9); color:#fff; }
.blk-bg-gradient { background:linear-gradient(135deg,#1e40af 0%,#0ea5e9 60%,#f59e0b 100%); color:#fff; }
.blk-bg-dark { background:#0f172a; color:#e2e8f0; }
.blk-title { font-size:15px; font-weight:800; margin-bottom:14px; text-align:center; color:#1e293b; }
.blk-bg-primary .blk-title, .blk-bg-gradient .blk-title, .blk-bg-dark .blk-title { color:#fff; }
.topbar-blk .tb-row { display:flex; justify-content:space-between; font-size:11px; color:#64748b; flex-wrap:wrap; gap:6px; }
.header-blk { padding:12px 16px; } .header-blk .h-row { display:flex; align-items:center; gap:13px; }
.header-blk.glass { background:rgba(255,255,255,.85); backdrop-filter:blur(8px); }
.header-blk.sticky-demo { outline:1.5px dashed #2563eb; outline-offset:-6px; }
.fake-logo { font-size:20px; } .fake-nav { display:flex; gap:14px; font-size:12px; color:#64748b; flex:1; flex-wrap:wrap; }
.fake-cta { background:#1e40af; color:#fff; font-size:11.5px; padding:6px 14px; border-radius:8px; white-space:nowrap; }
.hero-blk { background:linear-gradient(135deg,#1e40af,#0ea5e9); color:#fff; text-align:center; }
.hero-blk.split-hero { text-align:right; }
.hero-title { font-size:19px; font-weight:800; margin-bottom:7px; } .hero-sub { font-size:12px; opacity:.9; margin-bottom:14px; }
.hero-btns { display:flex; gap:9px; justify-content:center; flex-wrap:wrap; }
.hero-blk.split-hero .hero-btns { justify-content:flex-start; }
.hero-btn { background:#f59e0b; border-radius:9px; padding:8px 20px; font-size:12.5px; font-weight:700; display:inline-block; color:#fff; }
.hero-btn.ghost { background:transparent; border:1.5px solid rgba(255,255,255,.6); }
.hero-btn.full { width:100%; text-align:center; }
.hero-img { background:rgba(255,255,255,.16); border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:30px; }
.hero-img.wide { width:100%; height:150px; margin-bottom:9px; }
.hero-split { display:flex; gap:16px; align-items:center; flex-wrap:wrap; } .hero-split > div:first-child { flex:1 1 220px; }
.hero-img:not(.wide) { flex:1 1 170px; height:120px; }
.play { width:48px; height:48px; border-radius:50%; background:rgba(255,255,255,.2); display:flex; align-items:center; justify-content:center; font-size:18px; margin:10px auto; }
.slider-dots { letter-spacing:5px; font-size:10px; opacity:.85; text-align:center; margin-top:6px; }
.count-row { display:flex; gap:10px; justify-content:center; }
.count-box { background:rgba(255,255,255,.15); border-radius:10px; padding:8px 14px; font-size:11px; }
.count-box b { display:block; font-size:20px; }
.pv-text { font-size:13px; line-height:2.05; color:#334155; }
.fake-lines .fl { height:9px; border-radius:5px; background:#e2e8f0; margin:8px 0; }
.w40{width:40%}.w60{width:60%}.w70{width:70%}.w80{width:80%}.w90{width:90%}.w100{width:100%}
.split { display:flex; gap:18px; align-items:center; flex-wrap:wrap; } .split > div:first-child { flex:1 1 240px; }
.fake-img { flex:1 1 170px; height:140px; background:#dbeafe; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:32px; }
.fake-img.small { height:84px; font-size:24px; width:100%; flex:none; }
.fake-img.wide { flex:none; }
.cols { display:grid; gap:12px; } .c2{grid-template-columns:repeat(2,1fr)}.c3{grid-template-columns:repeat(3,1fr)}.c4{grid-template-columns:repeat(4,1fr)}.c5{grid-template-columns:repeat(5,1fr)}.c6{grid-template-columns:repeat(6,1fr)}
.fake-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 12px; text-align:center; min-width:0; }
.blk-bg-primary .fake-card, .blk-bg-dark .fake-card, .blk-bg-gradient .fake-card { background:rgba(255,255,255,.1); border-color:rgba(255,255,255,.25); }
.card-ico { font-size:23px; margin-bottom:6px; } .card-t { font-size:12.5px; font-weight:700; margin-bottom:5px; }
.fake-ava { font-size:28px; }
.quote { background:#fff; border:1px solid #e2e8f0; border-inline-start:4px solid #1e40af; border-radius:10px; padding:15px 17px; font-size:13px; max-width:540px; margin:0 auto 8px; }
/* 🆕 v2.12: استایل عناصر جدید */
.notif-bar { text-align:center; font-size:12.5px; font-weight:700; }
.notif-bar.info { background:#eff6ff; color:#1e40af; } .notif-bar.success { background:#f0fdf4; color:#15803d; }
.notif-bar.warning { background:#fffbeb; color:#b45309; }
.marquee-blk { overflow:hidden; padding:10px 0; }
.marquee-track { white-space:nowrap; animation: tbmarquee 14s linear infinite; font-weight:700; font-size:12.5px; }
@keyframes tbmarquee { from { transform: translateX(-100%); } to { transform: translateX(100%); } }
.story-wrap { display:flex; flex-direction:column; gap:12px; }
.story-sec { display:flex; gap:14px; align-items:center; background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:12px 16px; }
.story-year { background:#1e40af; color:#fff; border-radius:9px; padding:5px 13px; font-weight:800; font-size:12.5px; white-space:nowrap; }
.chip-row { display:flex; flex-wrap:wrap; gap:8px; }
.chip { background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; border-radius:20px; padding:5px 13px; font-size:12px; font-weight:600; }
.search-wrap { display:flex; gap:10px; align-items:center; background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:10px 14px; }
.search-ico { font-size:17px; }
.stars { font-size:13px; letter-spacing:1px; margin-bottom:6px; }
.ba-wrap { display:flex; gap:14px; align-items:stretch; }
.ba-side { flex:1; min-width:0; }
.ba-tag { display:inline-block; border-radius:8px; padding:3px 12px; font-size:11px; font-weight:800; color:#fff; margin-bottom:6px; }
.ba-tag.bad { background:#dc2626; } .ba-tag.ok { background:#16a34a; }
.ba-arrow { font-size:26px; align-self:center; color:#64748b; }
.quote-blk .quote { margin:0 auto; max-width:620px; font-size:16px; font-weight:700; text-align:center; }
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; max-width:600px; margin:0 auto; }
.fake-input { background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:8px; padding:9px 12px; font-size:11.5px; color:#94a3b8; }
.news-row { display:flex; gap:9px; max-width:520px; margin:0 auto; }
.stats-blk { display:flex; justify-content:space-around; flex-wrap:wrap; gap:16px; background:linear-gradient(135deg,#0f172a,#1e3a8a); color:#fff; }
.stat { text-align:center; } .stat-n { font-size:24px; font-weight:800; color:#93c5fd; } .stat-l { font-size:11.5px; opacity:.85; }
.pbar { display:flex; align-items:center; gap:11px; margin-bottom:11px; font-size:12px; } .pbar span { flex:0 0 128px; }
.track { flex:1; height:8px; background:#e2e8f0; border-radius:8px; overflow:hidden; } .fill { height:100%; background:linear-gradient(90deg,#1e40af,#0ea5e9); border-radius:8px; }
.acc { background:#fff; border:1px solid #e2e8f0; border-radius:9px; padding:11px 14px; margin-bottom:8px; font-size:12.5px; display:flex; justify-content:space-between; align-items:center; max-width:600px; margin-inline:auto; }
.tabs-row { display:flex; gap:6px; justify-content:center; margin-bottom:12px; }
.tab { font-size:12px; padding:6px 16px; border-radius:8px; border:1px solid #e2e8f0; color:#64748b; }
.tab.cur { background:#1e40af; color:#fff; border-color:#1e40af; }
.tl { max-width:520px; margin:0 auto; }
.tl-item { display:flex; gap:11px; align-items:center; padding:8px 0; opacity:.45; font-size:12.5px; }
.tl-item.done, .tl-item.cur { opacity:1; }
.tl-dot { width:26px; height:26px; border-radius:50%; background:#e2e8f0; display:flex; align-items:center; justify-content:center; font-size:12px; color:#475569; flex:0 0 26px; }
.tl-item.done .tl-dot { background:#16a34a; color:#fff; }
.tl-item.cur .tl-dot { background:#2563eb; color:#fff; }
.steps-row { display:flex; gap:9px; align-items:center; justify-content:center; flex-wrap:wrap; }
.step { background:#fff; border:1px solid #e2e8f0; border-radius:11px; padding:12px 16px; text-align:center; }
.step-n { width:26px; height:26px; border-radius:50%; background:#1e40af; color:#fff; display:flex; align-items:center; justify-content:center; margin:0 auto 6px; font-size:13px; }
.step-t { font-size:11.5px; font-weight:700; } .step-arrow { color:#94a3b8; font-size:16px; }
.fake-map { height:160px; background:repeating-linear-gradient(45deg,#eef2ff,#eef2ff 12px,#e0e7ff 12px,#e0e7ff 24px); border-radius:12px; display:flex; align-items:center; justify-content:center; color:#1e40af; font-weight:700; }
.fake-logo-s { background:#fff; border:1px solid #e2e8f0; border-radius:9px; padding:12px; font-size:20px; text-align:center; }
.cta-blk { background:linear-gradient(135deg,#1e40af,#0ea5e9); color:#fff; text-align:center; }
.cta-num { font-size:23px; font-weight:800; margin-top:6px; letter-spacing:1px; }
.crumb { font-size:11.5px; color:#64748b; padding:10px 16px; background:#f8fafc; }
.blk-sep { border:none; border-top:1px solid #e2e8f0; margin:6px 0; }
.blk-spacer { background:repeating-linear-gradient(45deg,#f8fafc,#f8fafc 10px,#f1f5f9 10px,#f1f5f9 20px); }
.alert-demo { border-radius:10px; padding:11px 15px; font-size:12.5px; font-weight:600; }
.alert-demo.info { background:#eff6ff; color:#1d4ed8; } .alert-demo.warning { background:#fffbeb; color:#b45309; } .alert-demo.success { background:#f0fdf4; color:#15803d; }
.feat-list { display:flex; flex-direction:column; gap:11px; max-width:640px; margin:0 auto; }
.feat-row { display:flex; gap:12px; align-items:flex-start; }
.feat-ico { width:38px; height:38px; border-radius:10px; background:#eff6ff; display:flex; align-items:center; justify-content:center; font-size:18px; flex:0 0 38px; }
.feat-d { font-size:11.5px; color:#64748b; }
.price-table { max-width:600px; margin:0 auto; }
.price-row { display:flex; justify-content:space-between; padding:11px 16px; border-bottom:1px solid #e2e8f0; font-size:13px; background:#fff; }
.price-row:first-child { border-radius:11px 11px 0 0; } .price-row:last-child { border-radius:0 0 11px 11px; border-bottom:none; }
.price-row b { color:#1e40af; }
.sticky-cta-demo { display:flex; justify-content:space-between; align-items:center; position:relative; background:#0f172a; color:#fff; }
.footer-blk { background:#0f172a; color:#e2e8f0; }
.footer-blk .fake-nav { color:#94a3b8; justify-content:center; }
.soc-row { display:flex; gap:12px; justify-content:center; font-size:11px; color:#94a3b8; margin-top:9px; }
.crump-blk { text-align:center; font-size:11.5px; color:#64748b; background:#f8fafc; }
.pv-list { margin:0 20px 0 0; font-size:13px; line-height:2.2; color:#334155; }
/* 🆕 v3.3: عناصر جدید */
.pill-announce { display:flex; align-items:center; gap:10px; background:#eff6ff; border:1.5px solid #bfdbfe; color:#1e40af; border-radius:40px; padding:11px 20px; font-weight:700; font-size:13px; }
.pill-dot { width:9px; height:9px; border-radius:50%; background:#2563eb; box-shadow:0 0 0 4px rgba(37,99,235,.18); flex:0 0 9px; }
.num-list { display:flex; flex-direction:column; gap:12px; max-width:640px; margin:0 auto; }
.num-row { display:flex; gap:13px; align-items:flex-start; }
.num-n { width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg,#1e40af,#0ea5e9); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:14px; flex:0 0 34px; }
.info-box-demo { display:flex; gap:13px; align-items:flex-start; background:#fffbeb; border:1.5px solid #fde68a; border-radius:12px; padding:14px 16px; }
.soc-proof { display:flex; gap:15px; align-items:center; justify-content:center; flex-wrap:wrap; }
.ava-stack { display:flex; }
.ava-stack .fake-ava { border:2px solid #fff; box-shadow:0 2px 8px rgba(0,0,0,.14); }
.promo-card-demo { display:flex; gap:18px; align-items:center; justify-content:space-between; flex-wrap:wrap; background:linear-gradient(135deg,#fff7ed,#ffedd5); border:1.5px solid #fdba74; border-radius:14px; padding:20px 22px; }
.divider-ico { display:flex; align-items:center; gap:12px; }
.divider-line { flex:1; height:1.5px; background:linear-gradient(90deg,transparent,#cbd5e1,#cbd5e1,transparent); }
.stars { color:#f59e0b; letter-spacing:1px; }
/* 🎛 v3.3: تنظیمات پیشرفته */
.blk-ts-sm .blk-title { font-size:14px; }
.blk-ts-md .blk-title { font-size:17px; }
.blk-ts-lg .blk-title { font-size:21px; }
.blk-ts-xl .blk-title { font-size:26px; }
/* 🎛 v2.14: اندازه عنوان روی تیترهای هیرو و کارت هم اثر بگذارد (قبلاً فقط blk-title
   بود و برای هیروها «تنظیمات اثر نمی‌کرد») */
.blk-ts-sm .hero-title { font-size:15px; } .blk-ts-md .hero-title { font-size:19px; }
.blk-ts-lg .hero-title { font-size:24px; } .blk-ts-xl .hero-title { font-size:29px; }
.blk-al-center { text-align:center; }
.blk-al-center .feat-list, .blk-al-center .num-list, .blk-al-center .price-table, .blk-al-center .form-grid, .blk-al-center .story-wrap, .blk-al-center .author-box-demo, .blk-al-center .feature-table-demo, .blk-al-center .steps-row { margin:0 auto; }
.blk-al-center .hero-btns, .blk-al-center .chip-row { justify-content:center; }
.blk-al-end { text-align:left; }
.blk-w-wide { max-width:1200px; margin-inline:auto; }
.blk-w-boxed { max-width:960px; margin-inline:auto; }
.blk-w-narrow { max-width:720px; margin-inline:auto; }

/* ════════ 🆕 v2.14: استایل ۱۲ عنصر جدید ════════ */
.stats-strip { display:flex; align-items:center; justify-content:space-around; flex-wrap:wrap; gap:12px; background:linear-gradient(135deg,#0f172a,#1e3a8a); color:#fff; }
.stats-strip .ss-item { text-align:center; font-size:11.5px; opacity:.92; }
.stats-strip .ss-item b { display:block; font-size:23px; font-weight:800; color:#93c5fd; }
.stats-strip .ss-sep { width:1px; height:34px; background:rgba(255,255,255,.25); }
.warning-box-demo { display:flex; gap:13px; align-items:flex-start; background:#fef2f2; border:1.5px solid #fecaca; border-radius:12px; padding:14px 16px; }
.brand-intro-demo, .download-card-demo { display:flex; gap:16px; align-items:center; flex-wrap:wrap; background:#fff; border:1.5px solid var(--border,#e2e8f0); border-radius:14px; padding:18px 20px; box-shadow:0 4px 16px rgba(2,8,23,.06); }
.author-box-demo { display:flex; gap:14px; align-items:flex-start; background:#f8fafc; border:1.5px solid var(--border,#e2e8f0); border-radius:13px; padding:16px 18px; }

/* ════════ 🆕 v2.17: CSS عناصر جدید (۱۳۰ عنصر) ════════ */
.glass-hero-demo { background:rgba(255,255,255,.55); backdrop-filter:blur(9px); border:1px solid rgba(255,255,255,.75); border-radius:17px; padding:26px 24px; text-align:center; box-shadow:0 14px 34px rgba(2,6,23,.10); }
.logo-strip-demo { display:flex; gap:12px; justify-content:space-between; flex-wrap:wrap; opacity:.9; }
.text-cols-demo { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.text-cols-demo p { margin:0 0 9px; font-size:12.5px; line-height:2; }
.steps-compact-demo { display:flex; flex-direction:column; gap:9px; }
.sc-row { display:flex; gap:11px; align-items:center; background:#f8fafc; border:1.5px solid var(--border,#e2e8f0); border-radius:11px; padding:10px 13px; }
.sc-num { flex:none; width:30px; height:30px; border-radius:50%; background:var(--primary,#2563eb); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; }
.guarantee-demo { display:flex; gap:15px; align-items:center; background:linear-gradient(135deg,#ecfdf5,#f0fdfa); border:1.5px solid #a7f3d0; border-radius:14px; padding:17px 19px; }
.urgent-demo { display:flex; gap:14px; align-items:center; background:linear-gradient(135deg,#fef2f2,#fff7ed); border:1.5px solid #fecaca; border-radius:14px; padding:16px 18px; }
.faq-mini-demo { background:#f8fafc; border:1.5px solid var(--border,#e2e8f0); border-inline-start:4px solid var(--primary,#2563eb); border-radius:11px; padding:14px 16px; }
.apt-compact-demo { background:#f8fafc; border:1.5px solid var(--border,#e2e8f0); border-radius:13px; padding:16px 18px; }
.hero-minimal-blk { text-align:center; }
.stats-strip { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; background:#f1f5f9; border-radius:11px; padding:12px 16px; }
.stats-strip .ss-item { font-size:12px; color:#334155; }
.stats-strip .ss-item b { font-size:16px; color:var(--primary,#2563eb); margin-inline-end:3px; }
.stats-strip .ss-sep { width:1px; height:22px; background:#cbd5e1; }
@media (max-width:640px) { .text-cols-demo { grid-template-columns:1fr; } }
.price-highlight-demo { border:2px solid #2563eb; box-shadow:0 10px 28px rgba(37,99,235,.15); }
.feature-table-demo { max-width:640px; margin:0 auto; border:1.5px solid var(--border,#e2e8f0); border-radius:12px; overflow:hidden; }
.feature-table-demo .ft-row { display:grid; grid-template-columns:1.4fr 1fr 1fr 1fr; align-items:center; }
.feature-table-demo .ft-row > * { padding:9px 10px; font-size:12px; text-align:center; border-bottom:1px solid var(--border,#e2e8f0); }
.feature-table-demo .ft-row:last-child > * { border-bottom:none; }
.feature-table-demo .ft-head { background:#0f172a; color:#fff; font-weight:800; }
.feature-table-demo .ft-hl { background:#eff6ff; color:#1e40af; font-weight:800; }
.feature-table-demo .ft-row span { text-align:right; font-weight:700; }
.quick-form-demo { display:flex; gap:9px; align-items:center; flex-wrap:wrap; background:#fff; border:1.5px solid var(--border,#e2e8f0); border-radius:13px; padding:12px 14px; max-width:560px; margin:0 auto; }
@media (max-width:640px) { .c2,.c3,.c4,.c6,.form-grid { grid-template-columns:1fr 1fr; } .c6{grid-template-columns:repeat(3,1fr);} .stats-strip .ss-sep{display:none} .feature-table-demo .ft-row{grid-template-columns:1.2fr 1fr 1fr 1fr;font-size:11px} }
@media (max-width:420px) { .c2,.c3,.c4,.form-grid { grid-template-columns:1fr; } .fake-nav{display:none} }

/* ════════ 🆕 v2.15: تنظیمات رنگ + تایمر زنده + ۱۴ عنصر جدید ════════ */
/* 🎨 رنگ عنوان انتخابی — فقط وقتی --blk-tc ست شده اعمال می‌شود (غلوه‌ی امن) */
.blk[style*="--blk-tc"] .blk-title, .blk[style*="--blk-tc"] .hero-title, .blk[style*="--blk-tc"] .card-t { color: var(--blk-tc) !important; }
/* ⏱ شمارش معکوس — درشت و خوانا */
.count-box { min-width:74px; }
.count-box b { font-size:22px; display:block; }
/* 📣 تیکر متحرک */
.ticker-bar-demo { display:flex; align-items:center; gap:10px; background:#0f172a; border-radius:12px; padding:10px 14px; color:#e2e8f0; overflow:hidden; }
.ticker-bar-demo .ticker-tag { background:#dc2626; color:#fff; font-size:10.5px; font-weight:800; border-radius:20px; padding:3px 11px; white-space:nowrap; animation:tickPulse 1.6s infinite; }
@keyframes tickPulse { 0%,100%{opacity:1} 50%{opacity:.55} }
.ticker-bar-demo .ticker-track { flex:1; overflow:hidden; white-space:nowrap; font-size:12px; }
.ticker-bar-demo .ticker-track span { display:inline-block; animation:tickMove 22s linear infinite; padding-inline-start:100%; }
@keyframes tickMove { from{transform:translateX(-100%)} to{transform:translateX(0)} }
/* 🗓 تقویم رزرو */
.cal-demo { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; max-width:520px; margin:0 auto; }
.cal-demo .cal-dow { text-align:center; font-size:10.5px; font-weight:800; color:#64748b; padding:4px 0; }
.cal-demo .cal-day { text-align:center; font-size:11.5px; padding:8px 0; border-radius:8px; border:1.5px solid var(--border,#e2e8f0); background:#fff; }
.cal-demo .cal-day.busy { background:#fef2f2; border-color:#fecaca; color:#b91c1c; text-decoration:line-through; }
.cal-demo .cal-day.sel { background:#dcfce7; border-color:#16a34a; color:#14532d; font-weight:800; }
/* 🚦 صف زنده */
.queue-demo { max-width:560px; margin:0 auto; display:flex; flex-direction:column; gap:8px; }
.queue-demo .queue-row { display:flex; justify-content:space-between; align-items:center; background:#fff; border:1.5px solid var(--border,#e2e8f0); border-radius:10px; padding:10px 15px; font-size:13px; }
.queue-demo .queue-row b { font-weight:800; }
/* ⏰ ظرفیت ساعتی */
.cap-demo { max-width:600px; margin:0 auto; display:flex; flex-direction:column; gap:10px; }
.cap-demo .cap-row { display:flex; align-items:center; gap:10px; font-size:12px; }
.cap-demo .cap-h { min-width:52px; font-weight:800; }
.cap-demo .cap-l { min-width:66px; color:#64748b; text-align:left; }
/* 🎚 مقایسه قبل/بعد تصویری */
.bas-demo { position:relative; display:grid; grid-template-columns:1fr 1fr; gap:8px; align-items:stretch; }
.bas-demo .bas-before, .bas-demo .bas-after { position:relative; height:150px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:13px; overflow:hidden; }
.bas-demo .bas-before { background:#f1f5f9; border:1.5px dashed #94a3b8; }
.bas-demo .bas-after { background:linear-gradient(135deg,#dcfce7,#bbf7d0); border:1.5px solid #16a34a; }
.bas-demo .bas-tag { position:absolute; top:8px; right:8px; background:rgba(15,23,42,.85); color:#fff; font-size:10.5px; font-weight:800; border-radius:16px; padding:3px 11px; }
.bas-demo .bas-tag.ok { background:#16a34a; }
.bas-demo .bas-handle { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); z-index:3; width:38px; height:38px; border-radius:50%; background:#fff; border:3px solid #2563eb; display:flex; align-items:center; justify-content:center; font-weight:800; color:#2563eb; box-shadow:0 4px 14px rgba(37,99,235,.35); cursor:ew-resize; }
/* 🪟 پاپ‌آپ خبرنامه */
.np-demo-wrap { text-align:center; }
.np-demo { display:inline-flex; flex-direction:column; text-align:right; background:#fff; border:1.5px solid var(--border,#e2e8f0); border-radius:15px; padding:18px 20px; box-shadow:0 18px 44px rgba(2,8,23,.16); max-width:430px; }
/* 💎 امتیاز اعتماد */
.ct-demo { display:flex; gap:16px; align-items:center; background:linear-gradient(135deg,#eff6ff,#dbeafe); border:1.5px solid #93c5fd; border-radius:15px; padding:18px 22px; }
.ct-demo .ct-score { font-size:39px; font-weight:900; color:#1e40af; line-height:1; }
.ct-demo .ct-score small { font-size:15px; color:#3b82f6; }
/* ➕ ویرایشگر آیتم‌ها در پنل ویژگی‌ها */
.item-edit-row { display:flex; gap:5px; align-items:center; margin-bottom:5px; }
.item-edit-row .form-control { padding:5px 8px; }
.item-edit-row .btn-sm { padding:4px 8px; font-size:11px; }
`;
    const st = document.createElement('style');
    st.textContent = css;
    document.head.appendChild(st);
})();

/* ==================================================
 * 🧭 ناوبری مسیر تودرتو: '2' یا '2.cols.1.0'
 * ================================================== */
function resolveArray(path) {
    /* آرایه‌ای که فرزندهای path داخلش هستند */
    if (!path) { return layout; }
    const parts = path.split('.');
    /* اگر مسیر به .cols.N ختم شده → آن ستون */
    if (parts.length >= 2 && parts[parts.length - 2] === 'cols') {
        const node = resolveNode(parts.slice(0, parts.length - 2).join('.'));
        const colIdx = parseInt(parts[parts.length - 1], 10);
        return node && node.cols ? node.cols[colIdx] : null;
    }
    return layout;
}
function resolveNode(path) {
    if (!path) { return null; }
    const parts = path.split('.');
    let self = null, arr = layout;
    for (let i = 0; i < parts.length; i++) {
        if (parts[i] === 'cols') {
            const colIdx = parseInt(parts[i + 1], 10);
            if (!self || !Array.isArray(self.cols)) { return null; }
            arr = self.cols[colIdx];
            if (!Array.isArray(arr)) { return null; }
            self = null; /* داخل ستون؛ آیتم بعدی از arr */
            i++;
            continue;
        }
        const idx = parseInt(parts[i], 10);
        if (self !== null) { return null; }
        self = arr[idx];
        if (!self) { return null; }
    }
    return self;
}

/* ==================================================
 * 🖨 رندر بوم (سطح-بهدار — پشتیبانی ستون‌های تودرتو)
 * ================================================== */
function render() {
    const container = document.getElementById('canvas-blocks');
    container.innerHTML = '';
    document.getElementById('canvas-empty').style.display = layout.length ? 'none' : 'block';
    renderLevel(layout, container, '');
    document.getElementById('layout-json').value = JSON.stringify(layout);
}

function renderLevel(arr, container, prefix) {
    arr.forEach((item, i) => {
        if (item && item.block === '_page') { return; } /* 🛡️ گره تنظیمات صفحه — هرگز روی بوم رندر نمی‌شود */
        const path = prefix ? prefix + '.' + i : String(i);
        const el = document.createElement('div');
        el.className = 'tb-block' + (selected === path ? ' selected' : '');
        el.dataset.path = path;
        el.draggable = true;

        const meta = BLOCK_META[item.block] || { label: item.block };
        const tools = document.createElement('div');
        tools.className = 'block-tools';
        tools.innerHTML = `
            <button type="button" onclick="event.stopPropagation();moveBlock('${path}',-1)" title="بالا">↑</button>
            <button type="button" onclick="event.stopPropagation();moveBlock('${path}',1)" title="پایین">↓</button>
            <button type="button" onclick="event.stopPropagation();duplicateBlock('${path}')" title="کپی">⧉</button>
            <button type="button" onclick="event.stopPropagation();toggleBasket('${path}',this)" title="افزودن به سبد ترکیب (${basket.has(path) ? 'در سبد' : ''})" style="${basket.has(path) ? 'color:#4ade80' : ''}">🧺</button>
            <button type="button" onclick="event.stopPropagation();previewBlockAt('${path}')" title="پیش‌نمایش">👁</button>
            <button type="button" onclick="event.stopPropagation();removeBlock('${path}')" title="حذف">✕</button>`;
        el.appendChild(tools);
        if (basket.has(path)) { el.classList.add('in-basket'); }

        const label = document.createElement('div');
        label.className = 'tb-label';
        label.textContent = (item.props && item.props.title ? item.props.title + ' · ' : '') + meta.label;
        el.appendChild(label);

        if (item.block === 'section-columns' || item.block === 'section-split') {
            /* 🏛 کانتینر ستونی: رندر بلوک + مناطق رهاسازی ستون‌ها */
            const live = document.createElement('div');
            live.className = 'tb-live';
            live.innerHTML = blockHtml(item.block, item.props || {});
            el.appendChild(live);
            const colsWrap = live.querySelector('.tb-col-wrap');
            const colCount = item.block === 'section-split' ? 2 : Math.max(2, Math.min(4, parseInt((item.props || {}).columns || 2, 10)));
            if (!Array.isArray(item.cols) || item.cols.length !== colCount) {
                item.cols = Array.from({ length: colCount }, (_, c) => (item.cols && item.cols[c]) || []);
            }
            const colCells = colsWrap.querySelectorAll('.tb-col');
            item.cols.forEach((colArr, ci) => {
                const cell = colCells[ci];
                if (!cell) { return; }
                const dz = document.createElement('div');
                dz.className = 'tb-dropzone';
                dz.dataset.path = path + '.cols.' + ci;
                if (!colArr.length) {
                    dz.innerHTML = '<div class="tb-empty-hint">➕ بلوک را داخل ستون ' + (ci + 1) + ' رها کنید<br><small>یا دابل‌کلیک روی بلوک کتابخانه</small></div>';
                }
                attachDropzone(dz, path + '.cols.' + ci);
                renderLevel(colArr, dz, path + '.cols.' + ci);
                cell.appendChild(dz);
            });
        } else {
            const live = document.createElement('div');
            live.className = 'tb-live';
            live.innerHTML = blockHtml(item.block, item.props || {});
            el.appendChild(live);
        }

        /* درج بین بلوک‌ها */
        attachInsertDrop(el, path);

        el.addEventListener('click', e => { e.stopPropagation(); selectBlock(path); });
        el.addEventListener('dragstart', e => {
            e.dataTransfer.setData('text/plain', 'move:' + path);
            e.dataTransfer.effectAllowed = 'move';
            el.classList.add('dragging');
        });
        el.addEventListener('dragend', () => el.classList.remove('dragging'));

        container.appendChild(el);
    });
}

/* ناحیه رهاسازی ستون‌ها */
function attachDropzone(dz, colPath) {
    dz.addEventListener('dragover', e => { e.preventDefault(); e.stopPropagation(); dz.classList.add('drag-over'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('drag-over'));
    dz.addEventListener('drop', e => {
        e.preventDefault(); e.stopPropagation();
        dz.classList.remove('drag-over');
        const data = e.dataTransfer.getData('text/plain');
        const arr = resolveArray(colPath);
        if (!arr) { return; }
        if (data.startsWith('move:')) {
            movePathTo(data.slice(5), arr, arr.length);
        } else if (data.startsWith('new:')) {
            arr.push(...makeBlocks(data.slice(4)));
            selected = colPath + '.' + (arr.length - 1);
        }
        syncAndRender();
        renderProps();
    });
}

/* درج قبل از بلوک (بین بلوک‌های هم‌سطح) */
function attachInsertDrop(el, path) {
    el.addEventListener('dragover', e => {
        if (e.dataTransfer.types.includes('text/plain')) {
            e.preventDefault(); e.stopPropagation();
            el.style.outline = '2.5px dashed #2563eb';
        }
    });
    el.addEventListener('dragleave', () => { el.style.outline = ''; });
    el.addEventListener('drop', e => {
        e.preventDefault(); e.stopPropagation();
        el.style.outline = '';
        const rect = el.getBoundingClientRect();
        const after = (rect.top + rect.height / 2) < e.clientY;
        const data = e.dataTransfer.getData('text/plain');
        const parts = path.split('.');
        const idx = parseInt(parts[parts.length - 1], 10);
        const parentPath = parts.slice(0, parts.length - 1).join('.');
        const arr = resolveArray(parentPath);
        if (!arr) { return; }
        const target = after ? idx + 1 : idx;
        if (data.startsWith('move:')) {
            movePathTo(data.slice(5), arr, target);
        } else if (data.startsWith('new:')) {
            arr.splice(target, 0, ...makeBlocks(data.slice(4)));
            selected = (parentPath ? parentPath + '.' : '') + target;
        }
        syncAndRender();
        renderProps();
    });
}

/* جابه‌جایی مسیر به آرایه مقصد (با حذف از مبدأ) */
function movePathTo(fromPath, targetArr, targetIdx) {
    const parts = fromPath.split('.');
    const idx = parseInt(parts[parts.length - 1], 10);
    const parentPath = parts.slice(0, parts.length - 1).join('.');
    const fromArr = resolveArray(parentPath);
    if (!fromArr) { return; }
    /* جابه‌جایی در همان آرایه */
    if (fromArr === targetArr) {
        const [moved] = fromArr.splice(idx, 1);
        const adj = idx < targetIdx ? targetIdx - 1 : targetIdx;
        targetArr.splice(adj, 0, moved);
        return;
    }
    const [moved] = fromArr.splice(idx, 1);
    targetArr.splice(Math.min(targetIdx, targetArr.length), 0, moved);
}

/* ساخت بلوک جدید با پیش‌فرض‌های کتابخانه
   🧩 v2.12: کلیدهای «saved:{id}» بلوک ترکیبی ذخیره‌شده را کپی عمیق می‌کنند */
function makeBlock(key) {
    const many = makeBlocks(key);
    return many[0];
}

/* 🧩 v3.3: ساخت «چند بلوک» با یک کلید — ترکیب‌های گروهی همه بلوک‌هایشان
   را باهم برمی‌گردانند (متن + آکاردئون مثلاً)؛ عناصر معمولی تک‌عضوی‌اند */
function makeBlocks(key) {
    if (String(key).indexOf('saved:') === 0) {
        const savedId = parseInt(String(key).slice(6), 10);
        const savedNode = SAVED_BLOCKS[savedId];
        const normalize = n => {
            const copy = JSON.parse(JSON.stringify(n));
            copy.props = Object.assign({ padding: 'default', background: 'default', visible: true }, copy.props || {});
            return copy;
        };
        if (savedNode && typeof savedNode === 'object') {
            /* 🔀 ترکیب گروهی: آرایه یا {type:'group', blocks:[...]} */
            if (Array.isArray(savedNode)) {
                return savedNode.filter(n => n && n.block).map(normalize);
            }
            if (savedNode.type === 'group' && Array.isArray(savedNode.blocks)) {
                return savedNode.blocks.filter(n => n && n.block).map(normalize);
            }
            /* تک‌بلوک قدیمی */
            return [normalize(savedNode)];
        }
        return [{ block: 'text', props: { title: 'بلوک ترکیبی یافت نشد', text: 'این بلوک ترکیبی حذف شده است.' } }];
    }
    /* ⭐ v2.26: عنصر شخصی استخراج‌شده — pelement:<id> */
    if (String(key).indexOf('pelement:') === 0) {
        const peId = parseInt(String(key).slice(9), 10);
        const pe = PERSONAL_ELEMENTS[peId];
        if (pe) {
            return [{ block: 'pelement', props: { element_id: peId, title: pe.name || 'عنصر شخصی', padding: 'default', background: 'default', visible: true } }];
        }
        return [{ block: 'text', props: { title: 'عنصر یافت نشد', text: 'این عنصر شخصی حذف شده است.' } }];
    }
    const meta = BLOCK_META[key] || {};
    const props = Object.assign({ padding: 'default', background: 'default', visible: true }, (meta.defaults && typeof meta.defaults === 'object') ? JSON.parse(JSON.stringify(meta.defaults)) : {});
    const blk = { block: key, props };
    if (key === 'section-columns') { blk.cols = [[], []]; }
    if (key === 'section-split') { blk.cols = [[], []]; }
    return [blk];
}

/* ==================================================
 * 🧺 v3.3: سبد انتخاب چند بلوکی — ذخیره ترکیب گروهی
 * (منطق درست طبق درخواست: چند بلوک + تنظیماتشان باهم
 *  ذخیره و موقع استفاده، همه باهم روی صفحه قرار می‌گیرند)
 * ================================================== */
const basket = new Set();
const faNum = n => String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);

function toggleBasket(path, btn) {
    if (basket.has(path)) {
        basket.delete(path);
    } else {
        basket.add(path);
    }
    render();
    renderBasket();
}

function clearBasket() {
    basket.clear();
    render();
    renderBasket();
}

function renderBasket() {
    const bar = document.getElementById('basket-bar');
    const cnt = document.getElementById('basket-count');
    bar.classList.toggle('hidden', basket.size === 0);
    cnt.textContent = faNum(basket.size);
}

/* ذخیره ترکیب گروهی — بلوک‌های انتخابی (مرتب، بدون تو در تو) به‌عنوان یک ترکیب */
async function saveBasketAsComposite() {
    if (basket.size === 0) { return; }
    /* فقط مسیرهای سطح بالا (فرزندانِ بلوک انتخاب‌شده حذف می‌شوند) + مرتب‌سازی */
    const paths = [...basket].filter(p => !p.includes('.'))
        .sort((a, b) => parseInt(a, 10) - parseInt(b, 10));
    if (paths.length === 0) {
        await sahandAlert({ title: 'انتخاب نامعتبر', message: 'بلوک‌های داخل ستون را نمی‌توان مستقیم ترکیب کرد — بلوک‌های سطح اصلی صفحه را انتخاب کنید (یا از ذخیره تک‌بلوک با ستون‌ها در پنل ویژگی‌ها استفاده کنید).', type: 'warning', icon: '🧺' });
        return;
    }
    const nodes = paths.map(p => JSON.parse(JSON.stringify(resolveNode(p)))).filter(n => n && n.block);
    if (nodes.length === 0) { return; }

    const name = await sahandPrompt({
        title: '💾 ذخیره بلوک ترکیبی گروهی',
        message: nodes.length + ' بلوک انتخاب‌شده با همه تنظیمات و ستون‌هایشان ذخیره می‌شوند و دفعه بعد همه باهم درج می‌شوند:',
        label: 'نام ترکیب',
        value: 'ترکیب «' + (nodes[0].props?.title || BLOCK_META[nodes[0].block]?.label || 'بلوک') + '» + ' + faNum(nodes.length - 1) + ' مورد دیگر',
        confirmText: 'ذخیره ترکیب',
        type: 'question', icon: '🧩',
    });
    if (name === null) { return; }

    document.getElementById('save-block-name').value = name.trim() || 'ترکیب بدون نام';
    document.getElementById('save-block-json').value = JSON.stringify({ type: 'group', blocks: nodes });
    document.getElementById('save-block-form').submit();
}

/* 🧩 v2.12: ذخیره بلوک انتخابی (تک) به‌عنوان بلوک ترکیبی قابل استفاده مجدد */
async function saveCompositeBlock() {
    const node = selected ? resolveNode(selected) : null;
    if (!node || !node.block) {
        await sahandAlert({ title: 'انتخاب نشده', message: 'ابتدا یک بلوک را در بوم انتخاب کنید.', type: 'warning', icon: '🧩' });
        return;
    }
    const meta = BLOCK_META[node.block] || { label: node.block };
    const suggested = meta.label || '';
    const name = await sahandPrompt({
        title: '💾 ذخیره به‌عنوان بلوک ترکیبی',
        message: 'این بلوک با ستون‌ها و تنظیمات فعلی ذخیره می‌شود:',
        label: 'نام بلوک ترکیبی', value: suggested, confirmText: 'ذخیره', type: 'question', icon: '🧩',
    });
    if (name === null) { return; }
    document.getElementById('save-block-name').value = name.trim() || suggested;
    document.getElementById('save-block-json').value = JSON.stringify(node);
    document.getElementById('save-block-form').submit();
}

/* رها کردن بلوک جدید در سطح بوم */
const canvas = document.getElementById('canvas');
canvas.addEventListener('dragover', e => e.preventDefault());
canvas.addEventListener('drop', e => {
    e.preventDefault();
    const data = e.dataTransfer.getData('text/plain');
    if (data.startsWith('new:')) {
        layout.push(...makeBlocks(data.slice(4)));
        selected = String(layout.length - 1);
        syncAndRender();
        renderProps();
    }
});

/* کتابخانه: شروع درگ + دابل‌کلیک
   🌐 v2.26: bindBlockItem جدا شد تا عناصر شخصیِ افزوده‌شده بدون رفرش هم رفتار یکسان بگیرند */
function bindBlockItem(item) {
    item.addEventListener('dragstart', e => e.dataTransfer.setData('text/plain', 'new:' + item.dataset.block));
    item.addEventListener('dblclick', () => {
        /* اگر بخش ستونی انتخاب است → داخل ستون آخر اضافه کن */
        const node = selected ? resolveNode(selected) : null;
        if (node && Array.isArray(node.cols)) {
            const colArr = node.cols[0];
            colArr.push(...makeBlocks(item.dataset.block));
            selected = selected + '.cols.0.' + (colArr.length - 1);
        } else {
            layout.push(...makeBlocks(item.dataset.block));
            selected = String(layout.length - 1);
        }
        syncAndRender();
        renderProps();
    });
}
document.querySelectorAll('.block-item').forEach(bindBlockItem);

/* ==================================================
 * 🎚️ v2.15: نوع نمایش عناصر — ۵ حالت واقعاً متفاوت
 *   list  = فهرستی فشرده | cards = کارت بزرگ با پیش‌نمایش زنده
 *   tiles = کاشی دوتایی      | dense = ردیفهای خیلی جمع‌وجور
 *   icons = فقط آیکون سه‌تایی
 * ================================================== */
const PALETTE_VIEWS = ['list', 'cards', 'tiles', 'dense', 'icons'];
function setPaletteView(view) {
    if (PALETTE_VIEWS.indexOf(view) < 0) { view = 'list'; }
    const lib = document.getElementById('block-library');
    const builder = document.querySelector('.builder');
    /* پاک‌سازی همه حالت‌ها از کتابخانه و بیلدر */
    PALETTE_VIEWS.forEach(v => {
        if (v === 'list') { return; }
        lib.classList.remove('view-' + v);
        if (builder) { builder.classList.remove('has-' + v); }
    });
    /* اعمال حالت جدید — هر حالت هم کلاس کتابخانه و هم عرض ستون بیلدر را عوض می‌کند */
    if (view !== 'list') {
        lib.classList.add('view-' + view);
        if (builder) { builder.classList.add('has-' + view); }
    }
    document.querySelectorAll('.pvt-btn').forEach(b => b.classList.toggle('active', b.dataset.view === view));
    try { localStorage.setItem('tb_palette_view', view); } catch (e) {}
    if (view === 'cards') { renderThumbs(); }
}

/* 🖼️ پیش‌نمایش واقعی هر عنصر — رندر زنده با همان موتور بوم، مقیاس‌شده
   🛡 v2.15: try/catch جدا برای هر بلوک — خطای یک عنصر بقیه را نمی‌کشد */
function renderThumbs() {
    document.querySelectorAll('.block-library .block-item[data-block]').forEach(item => {
        if (item.querySelector('.el-thumb')) { return; }
        const key = item.dataset.block;
        if (String(key).indexOf('saved:') === 0) { return; } /* ترکیب‌ها در بوم دیده می‌شوند */
        const meta = BLOCK_META[key];
        if (!meta) { return; }
        try {
            const props = Object.assign({ padding: 'compact', background: 'default' }, meta.defaults || {});
            const thumb = document.createElement('div');
            thumb.className = 'el-thumb';
            thumb.innerHTML = '<div class="el-thumb-stage">' + blockHtml(key, props) + '</div><div class="el-thumb-veil"></div>';
            item.appendChild(thumb);
        } catch (e) {
            /* این عنصر پیش‌نمایش ندارد — بدون شکستن بقیه */
        }
    });
}

/* مقداردهی اولیه: نمایش ذخیره‌شده کاربر */
(function initPaletteView() {
    let v = 'list';
    try { v = localStorage.getItem('tb_palette_view') || 'list'; } catch (e) {}
    setPaletteView(PALETTE_VIEWS.indexOf(v) >= 0 ? v : 'list');
})();

/* انتخاب و ویژگی‌ها */
function selectBlock(path) {
    selected = path;
    render();
    renderProps();
}

/* ═══════════════════════════════════════════════════════════════
 * 🎛 v2.14: سیستم تنظیمات حرفه‌ای اعلانی — هر عنصر فیلدهای دقیق خودش
 * قبلاً فیلدها با if-chain های پراکنده تعیین می‌شدند: بعضی بلوک‌ها فیلد
 * نداشتند، بعضی فیلد داشتند ولی رندر اثر نمی‌داد. حالا یک جدول اعلانی
 * BLOCK_FIELDS منبع یکتای حقیقت است و هر فیلد مستقیماً در رندر مصرف می‌شود.
 * ═══════════════════════════════════════════════════════════════ */
const PROP_LABELS = {
    title: 'عنوان بخش', subtitle: 'زیرعنوان', text: 'متن', phone: 'شماره تماس',
    columns: 'تعداد ستون', height: 'ارتفاع فاصله (px)', alertType: 'نوع هشدار', sticky: 'چسبان',
    placeholder: 'متن جایگزین جستجو', notifColor: 'رنگ نوار اطلاعیه', autoplay: 'پخش خودکار',
    btnText: 'متن دکمه', badge: 'برچسب کوچک', price: 'متن قیمت', icon: '🔣 آیکون (ایموجی)',
    hours: 'ساعات کاری', countdownTo: '⏱ زمان پایان شمارش معکوس', imageUrl: '🖼 آدرس تصویر واقعی',
    titleColor: '🎨 رنگ عنوان', gradientFrom: 'رنگ شروع گرادیانت', gradientTo: 'رنگ پایان گرادیانت',
    /* 🆕 v2.25 */
    textAfter: 'متن دوم (بعد / پاسخ)', videoUrl: '🎬 آدرس ویدیو (embed)', mapUrl: '🗺 لینک نقشه',
};

/* 🧩 تعریف فیلدها — نوع + پیش‌فرض + گزینه‌ها */
const FIELD_DEFS = {
    text:   { type: 'text' },
    area:   { type: 'textarea', rows: 4 },
    phone:  { type: 'text', ltr: true },
    icon:   { type: 'text', ph: 'مثلاً 💡 یا 🔧', big: true },
    hours:  { type: 'text' },
    cols:   { type: 'select', num: true, options: [[2, '۲ ستون'], [3, '۳ ستون'], [4, '۴ ستون'], [5, '۵ ستون'], [6, '۶ ستون']] },
    cols4:  { type: 'select', num: true, options: [[2, '۲ ستون'], [3, '۳ ستون'], [4, '۴ ستون']] },
    auto:   { type: 'checkbox', label: 'پخش خودکار اسلایدها' },
    height: { type: 'number', min: 8, max: 240 },
    alertT: { type: 'select', options: [['info', 'اطلاعیه آبی'], ['warning', 'هشدار زرد'], ['success', 'موفقیت سبز']] },
    notifC: { type: 'select', options: [['info', 'آبی اطلاعیه'], ['success', 'سبز موفقیت'], ['warning', 'زرد هشدار']] },
};

/* 🗺️ نقشه کامل فیلدهای هر بلوک — منبع یکتای حقیقت (v2.17: ۱۳۰ عنصر + فیلدهای کامل)
   T = عنوان | S = زیرعنوان | X = متن | P = تلفن | I = آیکون | C = ستون کارت
   B = متن دکمه | G = برچسب | $ = قیمت | A = پخش خودکار | H = ارتفاع | W = ساعات
   CD = زمان شمارش معکوس | IMG = آدرس تصویر | IT = ویرایشگر آیتم‌ها */
const BLOCK_FIELDS = {
    /* هدر */
    'header-v1': ['IT'], 'header-v2': ['P', 'W', 'B', 'IT'], 'header-v3': ['B', 'IT'],
    'top-bar': ['P', 'W'],
    'notification-bar': ['X', 'notifC'],
    /* هیرو */
    'hero': ['T', 'S'], 'hero-slider': ['T', 'A', 'IMG'], 'hero-split': ['T', 'S', 'IMG'],
    'hero-video': ['T', 'IMG'], 'hero-countdown': ['T', 'CD'], 'hero-form': ['T', 'S', 'B'],
    'hero-marquee': ['X'], 'announcement-pill': ['T'],
    'hero-minimal': ['T', 'S', 'B'], 'hero-glass': ['T', 'S'], 'logo-strip': ['T'],
    /* محتوا */
    'text': ['T', 'X'], 'text-image': ['T', 'X', 'IMG'], 'intro': ['T', 'X', 'IMG'], 'rich-text': ['T', 'X'],
    'quote': ['X'], 'two-col': ['T', 'IT'], 'three-col': ['T', 'IT'], 'brand-story': ['T', 'IT'],
    'area-list': ['T', 'IT'], 'checklist': ['T', 'IT'], 'search-bar': ['placeholder'],
    'heading-center': ['T', 'S'], 'numbered-list': ['T', 'IT'], 'info-box': ['T', 'I', 'X'],
    'benefits-list': ['T', 'IT'], 'author-box': ['T', 'I', 'X'],
    'text-columns': ['T', 'X'], 'brand-values': ['T', 'IT'], 'tech-tips': ['T', 'IT'],
    'pros-cons': ['T', 'IT'], 'text-accent-box': ['T', 'X'], 'definition-list': ['T', 'IT'],
    'article-highlight': ['T', 'S', 'X', 'IMG'], 'page-header': ['T', 'S'], 'steps-vertical': ['T', 'IT'],
    /* ستون‌بندی */
    'section-columns': ['T', 'SC'], 'section-split': ['T'], 'feature-list': ['T', 'IT'],
    /* کارت‌ها */
    'services-grid': ['T', 'C', 'IT'], 'devices-grid': ['T', 'C'], 'articles-recent': ['T', 'C'],
    'articles-grid': ['T', 'C'], 'features': ['T', 'C', 'IT'], 'team': ['T', 'C', 'IT'],
    'pricing-table': ['T', 'IT'], 'brands-links': ['T', 'C', 'IT'], 'certificates': ['T', 'C', 'IT'],
    'review-grid': ['T', 'C', 'IT'], 'contact-cards': ['T', 'IT'], 'price-cards': ['T', 'IT'],
    'location-cards': ['T', 'C', 'IT'], 'expert-cards': ['T', 'C', 'IT'], 'logo-cloud': ['T', 'C', 'IT'],
    'brand-intro-card': ['T', 'S'], 'price-highlight': ['T', 'S', '$', 'G', 'B'], 'price-compare': ['T', 'C'],
    'service-price-cards': ['T', 'C', 'IT'], 'feature-icons-grid': ['T', 'C', 'IT'],
    /* فرم */
    'contact-form': ['T', 'B'], 'request-form': ['T', 'B'], 'newsletter-form': ['T', 'B'],
    'appointment-form': ['T', 'B'], 'quick-contact-form': ['T', 'B'],
    'booking-calendar': ['T'], 'warranty-check': ['T', 'B'], 'price-estimate': ['T'],
    'device-error-lookup': ['T'], 'appointment-compact': ['T', 'B'],
    'callback-form': ['T', 'B'], 'survey-form': ['T', 'IT'],
    /* آمار */
    'counter-stats': ['T', 'IT'], 'progress-bars': ['T', 'IT'], 'skill-bars': ['T', 'IT'],
    'stats-grid': ['T', 'C', 'IT'], 'stats-strip': ['T', 'IT'],
    'live-queue': ['T', 'IT'], 'hourly-capacity': ['T', 'IT'], 'stats-inline': ['T', 'IT'],
    'stats-circles': ['T', 'IT'], 'counter-big': ['T', 'IT'], 'brand-stats-bar': ['T', 'IT'],
    /* تعامل */
    'testimonials': ['T', 'A', 'IT'], 'faq-accordion': ['T', 'IT'], 'tabs': ['T', 'IT'], 'timeline': ['T', 'IT'],
    'steps-process': ['T', 'IT'], 'before-after': ['T', 'X', 'X2'], 'social-proof': ['X'],
    'warranty-steps': ['T', 'IT'], 'feature-table': ['T', 'IT'],
    'faq-search': ['T', 'placeholder'], 'faq-category': ['T', 'IT'],
    'faq-mini': ['T', 'X'], 'steps-compact': ['T', 'IT'],
    'quote-slider': ['T', 'IT'], 'vote-poll': ['T', 'IT'],
    /* رسانه */
    'gallery': ['T', 'C', 'IMG', 'IT'], 'image-carousel': ['T', 'A', 'IMG', 'IT'], 'video-embed': ['T', 'IMG', 'V'], 'map': ['T', 'X', 'MU'],
    'before-after-slider': ['T', 'IMG'], 'social-wall': ['T', 'IT'], 'reviews-carousel': ['T', 'A', 'IT'],
    'video-grid': ['T', 'C', 'IMG'], 'logo-marquee': ['T', 'IT'], 'tag-cloud': ['T', 'IT'],
    /* فراخوان */
    'cta-phone': ['T', 'P'], 'cta-request': ['T', 'B'], 'cta-banner': ['T', 'B'],
    'sticky-mobile-cta': ['P', 'B'], 'cta-whatsapp': ['T'], 'warranty-banner': ['T', 'X'],
    'link-buttons': ['T', 'IT'], 'promo-card': ['T', 'S'], 'download-card': ['T', 'S', 'B'],
    'guarantee-card': ['T', 'S', 'B'], 'cta-timer': ['T', 'S', 'CD'], 'urgent-repair': ['T', 'P', 'B'],
    'newsletter-popup': ['T', 'S', 'B'],
    'emergency-strip': ['X', 'P'],
    /* ساختار */
    'breadcrumb': ['IT'], 'alert-notice': ['X', 'alertT'], 'button-group': ['B', 'IT'],
    'icon-list': ['T', 'IT'], 'separator': [], 'divider-icon': ['I'], 'spacer': ['H'],
    'working-hours': ['T', 'IT'], 'social-follow': ['T', 'IT'], 'trust-badges': ['T', 'IT'],
    'contact-info-bar': ['P', 'W'], 'contact-map-split': ['T', 'P'], 'warning-box': ['T', 'I', 'X'],
    'related-links': ['T', 'IT'], 'schedule-table': ['T', 'IT'],
    'ticker-bar': ['X'], 'credit-trust': ['T', 'IT'], 'brand-badges-row': ['T', 'IT'],
    'chat-widget': ['T'],
    /* فوتر */
    'footer-simple': ['P', 'IT'], 'footer-contact': ['P', 'W'], 'footer-links': ['T', 'IT'],
    'payment-methods': ['T', 'IT'], 'copyright': ['X'],
};

/* 🔤 برچسب‌های فارسی کدهای فیلد */
const CODE_MAP = {
    'T': 'title', 'S': 'subtitle', 'X': 'text', 'P': 'phone', 'I': 'icon',
    'C': 'columns', 'B': 'btnText', 'G': 'badge', '$': 'price', 'A': 'autoplay',
    'H': 'height', 'W': 'hours', 'SC': 'sectionCols', 'placeholder': 'placeholder',
    'alertT': 'alertType', 'notifC': 'notifColor',
    'CD': 'countdownTo', 'IMG': 'imageUrl', 'IT': 'items',
    /* 🆕 v2.25 */
    'X2': 'textAfter', 'V': 'videoUrl', 'MU': 'mapUrl',
};

function renderProps() {
    const panel = document.getElementById('props-content');
    const node = selected ? resolveNode(selected) : null;
    if (!node) {
        panel.innerHTML = '<div style="text-align:center;margin-top:26px">یک بلوک را در بوم انتخاب کنید.<br><br>🏛 برای چندستونه: «بخش چندستونی» اضافه کنید و بلوک‌ها را داخل ستون‌ها بیندازید.</div>';
        return;
    }
    const item = node;
    const isSavedComposite = String(item.block).indexOf('saved:') === 0 || !(item.block in BLOCK_META);
    const isPersonalElement = String(item.block) === 'pelement';
    const meta = BLOCK_META[item.block] || { label: isPersonalElement ? '⭐ عنصر شخصی' : (isSavedComposite ? '🧩 بلوک ترکیبی' : item.block) };
    const props = item.props || {};
    let html = `<div style="font-weight:800;margin-bottom:12px;font-size:13px">${isPersonalElement ? '⭐' : (isSavedComposite ? '🧩' : '📦')} ${esc(meta.label)}</div>`;

    /* 🎯 فیلدهای اختصاصی این بلوک — از جدول اعلانی (v2.14: پوشش همه ۸۶ عنصر) */
    const fieldCodes = isSavedComposite ? [] : (BLOCK_FIELDS[item.block] || ['T']);
    if (!isSavedComposite && fieldCodes.length === 0) {
        html += `<div class="hint" style="font-size:11px;margin-bottom:9px">این عنصر محتوای ثابت دارد — از تنظیمات پیشرفته پایین برای شخصی‌سازی چیدمان استفاده کنید.</div>`;
    }
    fieldCodes.forEach(code => {
        const key = CODE_MAP[code];
        if (!key) { return; }
        const label = PROP_LABELS[key] || key;
        if (code === 'SC') {
            /* بخش چندستونی — با بازسازی ستون‌ها */
            html += `<div class="form-group"><label>🏛 تعداد ستون‌ها</label>
                <select class="form-control" style="font-size:12px" onchange="setProp('${selected}','columns',parseInt(this.value,10));rebuildCols('${selected}')">
                    ${FIELD_DEFS.cols4.options.map(([v, l]) => `<option value="${v}" ${parseInt(props.columns || 2, 10) === v ? 'selected' : ''}>${l}</option>`).join('')}
                </select></div>`;
        } else if (code === 'C') {
            const def = (item.block === 'devices-grid' || item.block === 'gallery' || item.block === 'team' || item.block === 'expert-cards') ? 4 : 3;
            html += `<div class="form-group"><label>🗂 تعداد ستون کارت‌ها</label>
                <select class="form-control" style="font-size:12px" onchange="setProp('${selected}','columns',parseInt(this.value,10))">
                    ${FIELD_DEFS.cols.options.map(([v, l]) => `<option value="${v}" ${parseInt(props.columns || def, 10) === v ? 'selected' : ''}>${l}</option>`).join('')}
                </select></div>`;
        } else if (code === 'X') {
            html += `<div class="form-group"><label>${esc(label)}${item.block === 'rich-text' ? ' <small style="color:#94a3b8">(هر خط = یک آیتم لیست)</small>' : ''}</label>
                <textarea class="form-control" rows="4" style="font-size:12px" oninput="setProp('${selected}','text',this.value)">${esc(props.text || '')}</textarea></div>`;
        } else if (code === 'A') {
            html += `<label class="form-check" style="font-size:12px"><input type="checkbox" ${props.autoplay !== false && props.autoplay !== 0 ? 'checked' : ''} onchange="setProp('${selected}','autoplay',this.checked ? 1 : 0)"> پخش خودکار اسلایدها</label>`;
        } else if (code === 'H') {
            html += `<div class="form-group"><label>${esc(label)}</label>
                <input type="number" class="form-control" style="font-size:12px" value="${parseInt(props.height || 46, 10)}" min="8" max="240" onchange="setProp('${selected}','height',parseInt(this.value,10))"></div>`;
        } else if (code === 'alertT' || code === 'notifC') {
            const def = code === 'notifC' ? 'info' : 'info';
            html += `<div class="form-group"><label>${esc(label)}</label>
                <select class="form-control" style="font-size:12px" onchange="setProp('${selected}','${key}',this.value)">
                    ${FIELD_DEFS[code].options.map(([v, l]) => `<option value="${v}" ${(props[key] || def) === v ? 'selected' : ''}>${l}</option>`).join('')}
                </select></div>`;
        } else if (code === 'I') {
            html += `<div class="form-group"><label>\${esc(label)}</label>
                <div style="display:flex;gap:6px">
                    <input type="text" class="form-control" style="font-size:15px;width:60px;text-align:center" value="\${esc(props.icon || '')}" oninput="setProp('\${selected}','icon',this.value)" placeholder="\${FIELD_DEFS.icon.ph}" title="آیکون (ایموجی)">
                    <button type="button" class="btn btn-outline btn-sm" style="flex:0 0 auto" onclick="openEmojiPicker(this.closest('.form-group').querySelector('input'))" title="انتخاب از کتابخانه آیکون‌ها">😀 انتخاب آیکون</button>
                </div></div>`;
        } else if (code === 'placeholder') {
            html += `<div class="form-group"><label>${esc(label)}</label>
                <input type="text" class="form-control" style="font-size:12px" value="${esc(props.placeholder || '')}" oninput="setProp('${selected}','placeholder',this.value)" placeholder="جستجوی کد خطا، مقاله یا دستگاه..."></div>`;
        } else if (code === 'CD') {
            /* ⏱ v2.15: زمان پایان شمارش معکوس — روی بوم زنده تیک می‌زند */
            html += `<div class="form-group"><label>${esc(label)}</label>
                <input type="datetime-local" class="form-control" style="font-size:12px;direction:ltr" value="${esc(props.countdownTo || '')}" onchange="setProp('${selected}','countdownTo',this.value)">
                <div class="hint" style="margin-top:4px">زمان پایان کمپین — شمارش معکوس روی بوم هر ثانیه زنده آپدیت می‌شود.</div></div>`;
        } else if (code === 'X2') {
            /* 🆕 v2.25: متن دوم — قبل/بعد یا سوال/پاسخ */
            html += `<div class="form-group"><label>${esc(label)}</label>
                <textarea class="form-control" rows="2" style="font-size:12px" oninput="setProp('${selected}','textAfter',this.value)">${esc(props.textAfter || '')}</textarea></div>`;
        } else if (code === 'V') {
            /* 🆕 v2.25: آدرس ویدیو (embed) */
            html += `<div class="form-group"><label>${esc(label)}</label>
                <input type="text" class="form-control" style="font-size:11.5px;direction:ltr;text-align:left" value="${esc(props.videoUrl || '')}" oninput="setProp('${selected}','videoUrl',this.value)" placeholder="https://www.aparat.com/v/xxxx">
                <div class="hint" style="margin-top:4px">آدرس صفحه ویدیو (آپارات/یوتیوب) — در سایت به‌صورت embed نمایش داده می‌شود.</div></div>`;
        } else if (code === 'MU') {
            /* 🆕 v2.25: لینک نقشه */
            html += `<div class="form-group"><label>${esc(label)}</label>
                <input type="text" class="form-control" style="font-size:11.5px;direction:ltr;text-align:left" value="${esc(props.mapUrl || '')}" oninput="setProp('${selected}','mapUrl',this.value)" placeholder="https://maps.google.com/...">
                <div class="hint" style="margin-top:4px">لینک نقشه گوگل — در سایت قابل کلیک می‌شود.</div></div>`;
        } else if (code === 'IMG') {
            /* 🖼 v2.15: تصویر واقعی به‌جای نمای قالبی */
            html += `<div class="form-group"><label>${esc(label)}</label>
                <input type="text" class="form-control" style="font-size:11.5px;direction:ltr;text-align:left" value="${esc(props.imageUrl || '')}" oninput="setProp('${selected}','imageUrl',this.value)" placeholder="https://example.com/photo.jpg">
                <div class="hint" style="margin-top:4px">آدرس تصویر واقعی این بخش — خالی = نمای پیش‌فرض قالبی.</div></div>`;
        } else if (code === 'IT') {
            /* ➕ v2.25: ویرایشگر آیتم‌ها — سه فیلد کامل (آیکون + متن + توضیح)
               با انتخابگر آیکون ایموجی — رفع «نمیشه آیکون عوض کرد یا آیتم اضافه کرد» */
            const items = Array.isArray(props.items) ? props.items : [];
            const descPh = { 'progress-bars': 'درصد — مثلاً ۸۰', 'skill-bars': 'درصد — مثلاً ۹۰', 'pricing-table': 'قیمت — مثلاً ۹۵۰ هزار تومان', 'price-cards': 'قیمت پلن', 'working-hours': 'ساعت — مثلاً ۹ تا ۲۰', 'schedule-table': 'ساعت — مثلاً ۹ تا ۲۰', 'faq-accordion': 'پاسخ سوال...', 'counter-stats': 'برچسب عدد', 'stats-inline': 'برچسب', 'stats-strip': 'برچسب', 'testimonials': 'نام مشتری', 'quote-slider': 'نام گوینده', 'timeline': 'وضعیت — مثلاً در حال انجام', 'gallery': 'آدرس تصویر (اختیاری)', 'image-carousel': 'آدرس تصویر (اختیاری)', 'social-follow': 'آدرس پروفایل (اختیاری)', 'related-links': 'آدرس لینک (اختیاری)', 'footer-links': 'آدرس لینک (اختیاری)', 'tag-cloud': '', 'vote-poll': 'آدرس گزینه (اختیاری)', 'survey-form': '' }[item.block] || 'توضیح / مقدار (اختیاری)...';
            html += `<div style="font-size:11px;font-weight:800;color:var(--primary);margin:11px 0 7px">➕ آیتم‌های لیست (${faDigJS(items.length)})</div>`;
            items.forEach((it, idx) => {
                html += `<div class="item-edit-row" style="flex-wrap:wrap">
                    <input type="text" class="form-control" style="width:42px;text-align:center;font-size:14px" value="${esc(it.icon || '')}" oninput="setItemProp('${selected}',${idx},'icon',this.value)" placeholder="⚡" onclick="openEmojiPicker(this)" title="کلیک: انتخابگر آیکون">
                    <input type="text" class="form-control" style="flex:1;min-width:110px;font-size:11.5px" value="${esc(it.text || '')}" oninput="setItemProp('${selected}',${idx},'text',this.value)" placeholder="متن آیتم...">
                    <input type="text" class="form-control" style="flex:1;min-width:110px;font-size:11px;color:var(--text-light)" value="${esc(it.desc || '')}" oninput="setItemProp('${selected}',${idx},'desc',this.value)" placeholder="${esc(descPh)}">
                    <button type="button" class="btn btn-outline btn-sm" onclick="moveListItem('${selected}',${idx},-1)" title="بالا">↑</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="moveListItem('${selected}',${idx},1)" title="پایین">↓</button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="removeListItem('${selected}',${idx})" title="حذف">✕</button>
                </div>`;
            });
            html += `<button type="button" class="btn btn-info btn-sm btn-block" style="margin-top:6px" onclick="addListItem('${selected}')">➕ افزودن آیتم جدید</button>
                <div class="hint" style="margin-top:5px;font-size:10px;line-height:1.7">💡 روی کادر آیکون کلیک کنید تا <b>انتخابگر آیکون</b> باز شود — ستون سوم برای توضیح/قیمت/درصد است.</div>`;
        } else {
            /* فیلدهای متنی ساده: عنوان/زیرعنوان/تلفن/دکمه/برچسب/قیمت/ساعات */
            const isLtr = key === 'phone';
            const ph = { title: 'عنوان این بخش...', subtitle: 'زیرعنوان توضیحی...', btnText: 'مثلاً ثبت درخواست', badge: 'مثلاً پیشنهاد ویژه', price: 'مثلاً ۴۵۰ هزار تومان', hours: 'مثلاً شنبه تا پنجشنبه ۹ تا ۲۰' }[key] || '';
            html += `<div class="form-group"><label>${esc(label)}</label>
                <input type="text" class="form-control" style="font-size:12px${isLtr ? ';direction:ltr;text-align:left' : ''}" value="${esc(props[key] || '')}" oninput="setProp('${selected}','${key}',this.value)" placeholder="${esc(ph)}"></div>`;
        }
    });

    /* 🎭 v2.26 — ظواهر متعدد عنصر: ظاهر کلی + استایل دکمه + افکت هاور
       برای «همه» عناصر (به‌جز ساختاری‌هایی که خط/فاصله‌اند) */
    const STRUCTURAL = ['separator', 'spacer', 'divider-icon'];
    if (!STRUCTURAL.includes(item.block)) {
        const varSel = (key, list) => `<select class="form-control" style="font-size:12px" onchange="setProp('${selected}','${key}',this.value)">
                ${list.map(([v, l]) => `<option value="${v}" ${(props[key] || (key === 'hoverFx' ? 'none' : 'default')) === v ? 'selected' : ''}>${l}</option>`).join('')}
            </select>`;
        html += `
        <div style="font-size:11px;font-weight:800;color:var(--primary);margin:12px 0 7px">🎭 ظاهر عنصر</div>
        <div class="form-group"><label>🎨 ظاهر کلی بدنه</label>
            ${varSel('variant', BLOCK_VARIANTS.variant)}
            <div class="hint" style="margin-top:4px">شیشه‌ای، کارت سایه‌دار، تخت، خط‌دار، تیره و ... — روی بدنه همین عنصر اعمال می‌شود.</div></div>
        <div class="form-group"><label>🔘 استایل دکمه‌های این بخش</label>
            ${varSel('btnStyle', BLOCK_VARIANTS.btnStyle)}
            <div class="hint" style="margin-top:4px">شیشه‌ای، دایره‌ای (کپسولی)، خطی، گرادیانت و ... — برای عناصری که دکمه دارند.</div></div>
        <div class="form-group"><label>✨ افکت هاور (رفت و برگشت ماوس)</label>
            ${varSel('hoverFx', BLOCK_VARIANTS.hoverFx)}</div>`;
    }

    /* 🎛 v3.3 + v2.15 + 🆕 v2.17: تنظیمات حرفه‌ای عمومی — رنگ عنوان/گرادیانت
       🆕 بلوک‌های ساختاری (فاصله/جداکننده/خط) و بدون‌عنوان (هدر/نوارها) تنظیمات
       نامربوط را نمی‌بینند — «هر تنظیمی دیده می‌شود، اثر دارد» (رفع شکایت کاربر) */
    const NO_TITLE_ADV = ['header-v1', 'header-v2', 'header-v3', 'top-bar', 'notification-bar', 'sticky-mobile-cta', 'copyright', 'breadcrumb', 'hero-marquee', 'contact-info-bar', 'stats-strip', 'separator', 'spacer', 'divider-icon', 'ticker-bar'];
    if (!STRUCTURAL.includes(item.block)) {
    html += `
        <div style="font-size:11px;font-weight:800;color:var(--primary);margin:11px 0 7px">🎛 تنظیمات پیشرفته</div>`;
    if (!NO_TITLE_ADV.includes(item.block)) {
    html += `
        <div class="form-group"><label>🎨 رنگ عنوان‌ها</label>
            <div style="display:flex;gap:7px;align-items:center">
                <input type="color" class="form-control" style="width:48px;height:33px;padding:2px;cursor:pointer" value="${esc(props.titleColor || '#1e40af')}" oninput="setProp('${selected}','titleColor',this.value)">
                <button type="button" class="btn btn-outline btn-sm" onclick="setProp('${selected}','titleColor','');renderProps()" title="حذف رنگ — برگشت به پیش‌فرض قالب">✕ پیش‌فرض</button>
            </div>
            <div class="hint" style="margin-top:4px">رنگ تیترها و عناوین این بخش — بلافاصله روی بوم اعمال می‌شود.</div></div>
        <div class="form-group"><label>اندازه عنوان</label>
            <select class="form-control" style="font-size:12px" onchange="setProp('${selected}','titleSize',this.value)">
                ${['sm', 'md', 'lg', 'xl'].map(v => `<option value="${v}" ${(props.titleSize || 'md') === v ? 'selected' : ''}>${{ sm: 'کوچک', md: 'متوسط (پیش‌فرض)', lg: 'بزرگ', xl: 'خیلی بزرگ' }[v]}</option>`).join('')}
            </select></div>
        <div class="form-group"><label>تراز افقی محتوا</label>
            <select class="form-control" style="font-size:12px" onchange="setProp('${selected}','align',this.value)">
                ${[['start', 'راست (پیش‌فرض)'], ['center', 'وسط‌چین'], ['end', 'چپ']].map(([v, l]) => `<option value="${v}" ${(props.align || 'start') === v ? 'selected' : ''}>${l}</option>`).join('')}
            </select></div>
        <div class="form-group"><label>عرض محتوا</label>
            <select class="form-control" style="font-size:12px" onchange="setProp('${selected}','width',this.value)">
                ${[['full', 'تمام‌عرض (پیش‌فرض)'], ['wide', 'عریض (۱۲۰۰px)'], ['boxed', 'جعبه‌ای (۹۶۰px)'], ['narrow', 'باریک (۷۲۰px)']].map(([v, l]) => `<option value="${v}" ${(props.width || 'full') === v ? 'selected' : ''}>${l}</option>`).join('')}
            </select></div>`;
    }
    html += `
        <div class="form-group"><label>کلاس CSS سفارشی (اختیاری)</label>
            <input type="text" class="form-control" style="font-size:11.5px;direction:ltr" value="${esc(props.customClass || '')}" oninput="setProp('${selected}','customClass',this.value)" placeholder="مثلاً my-special-block"></div>
        <hr style="border:none;border-top:1px dashed var(--border);margin:11px 0">`;
    }

    /* عمومی‌ها */
    html += `
        <div class="form-group"><label>فاصله داخلی</label>
            <select class="form-control" style="font-size:12px" onchange="setProp('${selected}','padding',this.value)">
                ${['default', 'compact', 'roomy', 'none'].map(v => `<option value="${v}" ${props.padding === v ? 'selected' : ''}>${{ default: 'پیش‌فرض', compact: 'فشرده', roomy: 'جادار', none: 'بدون فاصله' }[v]}</option>`).join('')}
            </select></div>
        <div class="form-group"><label>پس‌زمینه</label>
            <select class="form-control" style="font-size:12px" onchange="setProp('${selected}','background',this.value);renderProps()">
                ${['default', 'surface', 'primary', 'gradient', 'dark'].map(v => `<option value="${v}" ${props.background === v ? 'selected' : ''}>${{ default: 'معمولی', surface: 'کمرنگ', primary: 'رنگ اصلی', gradient: 'گرادیانت', dark: 'تیره' }[v]}</option>`).join('')}
            </select>
            ${props.background === 'gradient' ? `
            <div style="display:flex;gap:7px;align-items:center;margin-top:7px">
                <input type="color" class="form-control" style="width:44px;height:31px;padding:2px;cursor:pointer" value="${esc(props.gradientFrom || '#1e40af')}" oninput="setProp('${selected}','gradientFrom',this.value)" title="رنگ شروع گرادیانت">
                <span style="font-size:11px;color:var(--text-light)">تا</span>
                <input type="color" class="form-control" style="width:44px;height:31px;padding:2px;cursor:pointer" value="${esc(props.gradientTo || '#0ea5e9')}" oninput="setProp('${selected}','gradientTo',this.value)" title="رنگ پایان گرادیانت">
            </div>
            <div class="hint" style="margin-top:4px">🌈 دو سر رنگ گرادیانت را انتخاب کنید — ترکیب دلخواه شما روی بوم اعمال می‌شود.</div>` : ''}
        </div>
        <label class="form-check" style="font-size:12px"><input type="checkbox" ${props.visible !== false ? 'checked' : ''} onchange="setProp('${selected}','visible',this.checked)"> نمایش داده شود</label>
        ${['header-v1', 'header-v2', 'header-v3'].includes(item.block) ? `<label class="form-check" style="font-size:12px"><input type="checkbox" ${props.sticky ? 'checked' : ''} onchange="setProp('${selected}','sticky',this.checked)"> چسبان (Sticky)</label>` : ''}
        <hr style="border:none;border-top:1px solid var(--border);margin:13px 0">
        <button type="button" class="btn btn-info btn-sm btn-block" style="margin-bottom:7px" onclick="saveCompositeBlock()" title="این بلوک با ستون‌ها و تنظیمات فعلی ذخیره می‌شود تا در هر قالبی قابل استفاده مجدد باشد">🧩 ذخیره به‌عنوان بلوک ترکیبی</button>
        <button type="button" class="btn btn-danger btn-sm btn-block" onclick="removeBlock('${selected}')">🗑️ حذف بلوک</button>`;
    panel.innerHTML = html;
}

function textField(f, v) {
    return `<div class="form-group"><label>${PROP_LABELS[f] || f}</label>
        <input type="text" class="form-control" style="font-size:12px" value="${esc(v)}" oninput="setProp('${selected}','${f}',this.value)" placeholder="مثلاً خدمات برجسته"></div>`;
}

function setProp(path, key, value) {
    const node = resolveNode(path);
    if (!node) { return; }
    node.props = node.props || {};
    node.props[key] = value;
    syncAndRender();
}

/* ═══════════════════════════════════════════════════════════════
 * ➕ v2.15: ویرایشگر آیتم‌های لیست — افزودن/حذف/جابجایی/ویرایش زنده
 * ═══════════════════════════════════════════════════════════════ */
function ensureItems(node) {
    node.props = node.props || {};
    if (!Array.isArray(node.props.items)) { node.props.items = []; }
    return node.props.items;
}
function setItemProp(path, idx, key, value) {
    const node = resolveNode(path);
    if (!node) { return; }
    const items = ensureItems(node);
    if (!items[idx]) { return; }
    items[idx][key] = value;
    syncAndRender();
}
function addListItem(path) {
    const node = resolveNode(path);
    if (!node) { return; }
    const items = ensureItems(node);
    items.push({ icon: '', text: 'آیتم جدید' });
    syncAndRender();
    renderProps();
}
function removeListItem(path, idx) {
    const node = resolveNode(path);
    if (!node) { return; }
    const items = ensureItems(node);
    items.splice(idx, 1);
    syncAndRender();
    renderProps();
}
/* ==================================================
 * 😀 v2.25: انتخابگر آیکون (ایموجی) — کتابخانه ۱۲۶ آیکون موضوعی
 * رفع «نمیشه آیکون عوض کرد» — روی هر کادر آیکون (فیلد تکی یا آیتم
 * لیست) کلیک کنید؛ انتخاب، همان لحظه در بوم اعمال می‌شود.
 * ================================================== */
const EMOJI_LIBRARY = [
    /* تعمیرات و ابزار */
    '🔧','🔨','🛠','⚙️','🔩','🧰','🪛','🔧','⚡','🔋','🔌','💡','🧲','🧯','🛢',
    /* لوازم خانگی */
    '🧊','🧺','🫧','🍲','🚿','🚽','🚰','🔥','❄️','🌬','🌡','🧹','🌪','💧','🫗',
    /* الکترونیک */
    '📺','🖥','📱','💻','⌨️','🖱','🎮','📷','🎥','🔊','🎧','📻','⏰','⌚','🔋',
    /* پخت‌وپز */
    '🍳','🍳','🍞','🥘','♨️','🫕','🍜','☕','🍵','🧊','🥤','🍽','🔪','🧑‍🍳','📦',
    /* وضعیت و کیفیت */
    '✅','☑️','✔️','❌','⚠️','🚫','⭐','🌟','💯','🏆','🎖','🏅','🥇','👍','👎',
    /* ارتباط و خدمات */
    '📞','📱','💬','📨','📧','📮','🗺','📍','🚗','🚚','🛵','🚑','🆘','🔔','📣',
    /* زمان و سرعت */
    '⏱','⏳','⌛','🕐','📅','🗓','⚡','🚀','🏃','⏩','⏪','🔄','🔁','♻️','💫',
    /* امنیت و اعتماد */
    '🛡','🔒','🔓','🔑','🪪','📋','📝','📄','🗂','📁','🖇','✍️','🧾','💼','🎫',
    /* افراد و تیم */
    '👨‍🔧','👩‍🔧','🧑‍🔧','👷','🧑‍⚕️','👨‍💼','🙋','🤝','🙏','💪','🧠','👀','🗣','👥','🧑‍🎓',
    /* نمادین و برند */
    '🏷️','💠','💎','🎨','🌈','🎯','🔍','🔎','📊','📈','📉','💰','💳','🎁','🎉'
];
let emojiPickerEl = null;
function closeEmojiPicker() {
    if (emojiPickerEl && emojiPickerEl.parentNode) { emojiPickerEl.parentNode.removeChild(emojiPickerEl); }
    emojiPickerEl = null;
    document.removeEventListener('mousedown', emojiOutside, true);
}
function emojiOutside(e) {
    if (emojiPickerEl && !emojiPickerEl.contains(e.target)) { closeEmojiPicker(); }
}
function openEmojiPicker(inputEl) {
    if (!inputEl) { return; }
    if (emojiPickerEl) { closeEmojiPicker(); }
    emojiPickerEl = document.createElement('div');
    emojiPickerEl.className = 'emoji-picker-pop';
    let grid = '';
    const seen = new Set();
    EMOJI_LIBRARY.forEach(em => {
        if (seen.has(em)) { return; }
        seen.add(em);
        grid += '<button type="button" data-em="' + em.replace(/"/g, '&quot;') + '">' + em + '</button>';
    });
    emojiPickerEl.innerHTML = '<div class="ep-head">😀 انتخاب آیکون <button type="button" class="ep-close">✕</button></div>' +
        '<div class="ep-grid">' + grid + '</div>' +
        '<div class="ep-hint">روی آیکون کلیک کنید — انتخاب فوری</div>';
    document.body.appendChild(emojiPickerEl);
    /* جای‌گذاری کنار فیلد */
    const r = inputEl.getBoundingClientRect();
    const pw = 316, ph = 330;
    let left = Math.max(8, Math.min(window.innerWidth - pw - 8, r.left));
    let top = r.bottom + 6;
    if (top + ph > window.innerHeight - 8) { top = Math.max(8, r.top - ph - 6); }
    emojiPickerEl.style.left = left + 'px';
    emojiPickerEl.style.top = (top + window.scrollY) + 'px';
    emojiPickerEl.querySelector('.ep-close').onclick = closeEmojiPicker;
    emojiPickerEl.querySelectorAll('.ep-grid button').forEach(btn => {
        btn.onclick = function () {
            inputEl.value = this.dataset.em;
            inputEl.dispatchEvent(new Event('input', { bubbles: true }));
            closeEmojiPicker();
        };
    });
    document.addEventListener('mousedown', emojiOutside, true);
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeEmojiPicker(); }
});

function moveListItem(path, idx, dir) {
    const node = resolveNode(path);
    if (!node) { return; }
    const items = ensureItems(node);
    const j = idx + dir;
    if (j < 0 || j >= items.length) { return; }
    [items[idx], items[j]] = [items[j], items[idx]];
    syncAndRender();
    renderProps();
}

/* ⏱ v2.15: تایمر زنده — همه شمارش‌های معکوس بوم هر ثانیه آپدیت می‌شوند */
setInterval(function () {
    document.querySelectorAll('[data-countdown]').forEach(function (row) {
        const target = parseInt(row.getAttribute('data-countdown'), 10);
        if (!target) { return; }
        let diff = Math.max(0, Math.floor((target - Date.now()) / 1000));
        const d = Math.floor(diff / 86400); diff %= 86400;
        const h = Math.floor(diff / 3600); diff %= 3600;
        const m = Math.floor(diff / 60);
        const s = diff % 60;
        const boxes = row.querySelectorAll('b');
        if (boxes.length >= 4) {
            boxes[0].textContent = faDigJS(String(d).padStart(2, '0'));
            boxes[1].textContent = faDigJS(String(h).padStart(2, '0'));
            boxes[2].textContent = faDigJS(String(m).padStart(2, '0'));
            boxes[3].textContent = faDigJS(String(s).padStart(2, '0'));
        }
    });
}, 1000);
function rebuildCols(path) {
    const node = resolveNode(path);
    if (!node) { return; }
    const n = Math.max(2, Math.min(4, parseInt(node.props.columns || 2, 10)));
    node.cols = Array.from({ length: n }, (_, c) => (node.cols && node.cols[c]) || []);
    syncAndRender();
    renderProps();
}

/* عملیات بلوک (مسیر-محور) */
function moveBlock(path, dir) {
    const parts = path.split('.');
    const idx = parseInt(parts[parts.length - 1], 10);
    const parentPath = parts.slice(0, parts.length - 1).join('.');
    const arr = resolveArray(parentPath);
    if (!arr) { return; }
    const j = idx + dir;
    if (j < 0 || j >= arr.length) { return; }
    [arr[idx], arr[j]] = [arr[j], arr[idx]];
    selected = (parentPath ? parentPath + '.' : '') + j;
    syncAndRender();
    renderProps();
}
function duplicateBlock(path) {
    const parts = path.split('.');
    const idx = parseInt(parts[parts.length - 1], 10);
    const parentPath = parts.slice(0, parts.length - 1).join('.');
    const arr = resolveArray(parentPath);
    if (!arr) { return; }
    arr.splice(idx + 1, 0, JSON.parse(JSON.stringify(arr[idx])));
    selected = (parentPath ? parentPath + '.' : '') + (idx + 1);
    syncAndRender();
    renderProps();
}
function removeBlock(path) {
    const parts = path.split('.');
    const idx = parseInt(parts[parts.length - 1], 10);
    const parentPath = parts.slice(0, parts.length - 1).join('.');
    const arr = resolveArray(parentPath);
    if (!arr) { return; }
    arr.splice(idx, 1);
    selected = '';
    syncAndRender();
    renderProps();
}
function clearLayout() {
    if (!layout.length || confirm('همه بلوک‌های بوم پاک شوند؟')) {
        layout = [];
        selected = '';
        syncAndRender();
        renderProps();
    }
}
function syncAndRender() {
    document.getElementById('layout-json').value = JSON.stringify(fullLayout());
    render();
}

/* 🖥️ تغییر نمای دستگاه */
function setDevice(btn, device) {
    document.querySelectorAll('.builder .device-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const canvasEl = document.getElementById('canvas');
    canvasEl.style.maxWidth = device === 'desktop' ? '100%' : device === 'tablet' ? '768px' : '400px';
    canvasEl.style.margin = device === 'desktop' ? '0' : '0 auto';
}

/* 👁️ پیش‌نمایش زنده — رندر چیدمان فعلی در iframe با template-preview.php */
function openLivePreview() {
    const backdrop = document.getElementById('preview-backdrop');
    const frame = document.getElementById('preview-frame');
    frame.src = 'template-preview.php?json=' + encodeURIComponent(JSON.stringify(fullLayout()));
    backdrop.classList.add('show');
}
function closeLivePreview() {
    document.getElementById('preview-backdrop').classList.remove('show');
    document.getElementById('preview-frame').src = 'about:blank';
}
function previewSingleBlock(key) {
    const backdrop = document.getElementById('preview-backdrop');
    const frame = document.getElementById('preview-frame');
    frame.src = 'template-preview.php?block=' + encodeURIComponent(key);
    backdrop.classList.add('show');
}
function previewBlockAt(path) {
    const node = resolveNode(path);
    if (!node) { return; }
    const backdrop = document.getElementById('preview-backdrop');
    const frame = document.getElementById('preview-frame');
    frame.src = 'template-preview.php?json=' + encodeURIComponent(JSON.stringify([node]));
    backdrop.classList.add('show');
}
function setPreviewDevice(btn, width) {
    document.querySelectorAll('.preview-modal .device-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const frame = document.getElementById('preview-frame');
    frame.style.maxWidth = width > 0 ? width + 'px' : '100%';
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeLivePreview(); }
});

/* ==================================================
 * 🎨 اسکیل UI/UX Pro — طراحی خودکار + ممیزی UX
 * ================================================== */
const UIUX_CSRF = (document.querySelector('input[name="csrf_token"]') || {}).value || '';
const UIUX_SEVERITY_FA = { critical: '🔴 بحرانی', high: '🟠 مهم', medium: '🟡 متوسط', low: '🔵 جزئی' };

async function uiuxRequest(action, extra) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('page_type', (document.querySelector('select[name="page_type"]') || {}).value || 'home');
    fd.append('csrf_token', UIUX_CSRF);
    /* UIUX فقط بلوک‌های واقعی را می‌بیند — گره تنظیمات صفحه (_page) حذف می‌شود */
    fd.append('layout_json', JSON.stringify(layout));
    if (extra) { Object.keys(extra).forEach(k => fd.append(k, extra[k])); }
    const res = await fetch('template-builder.php', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    return res.json();
}

function uiuxScoreBadge(score) {
    const color = score >= 85 ? '#16a34a' : score >= 70 ? '#2563eb' : score >= 50 ? '#d97706' : '#dc2626';
    return '<span style="display:inline-block;min-width:92px;text-align:center;background:' + color + ';color:#fff;border-radius:10px;padding:5px 12px;font-weight:800;font-size:16px">' + score + '/۱۰۰</span>';
}

function showUiuxPanel(title, html) {
    document.getElementById('uiux-panel-title').textContent = title;
    document.getElementById('uiux-panel-body').innerHTML = html;
    document.getElementById('uiux-panel').style.display = 'block';
    document.getElementById('uiux-panel').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
function closeUiuxPanel() {
    document.getElementById('uiux-panel').style.display = 'none';
}

/* 🪄 طراحی خودکار صفحه با اسکیل */
async function uiuxDesign() {
    const btn = document.getElementById('btn-uiux-design');
    const old = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> در حال طراحی...';
    try {
        const json = await uiuxRequest('uiux_design');
        if (!json.success) { sahandError('خطا: ' + (json.error || 'نامشخص')); return; }
        const d = json.data;
        const applyLayout = function () {
            layout = d.layout;
            selected = '';
            syncAndRender();
            renderProps();
        };
        if (!layout.length) {
            applyLayout();
        } else if (window.sahandConfirm) {
            const ok = await sahandConfirm({ title: 'جایگزینی چیدمان', message: 'چیدمان حرفه‌ای «' + (d.page_name_fa || '') + '» جایگزین چیدمان فعلی شود؟', type: 'question', confirmText: 'بله، جایگزین کن', confirmIcon: '🪄' });
            if (ok) { applyLayout(); }
        } else if (window.confirm('چیدمان حرفه‌ای «' + (d.page_name_fa || '') + '» جایگزین چیدمان فعلی شود؟')) {
            applyLayout();
        }
        let html = '<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px">' +
            uiuxScoreBadge(d.ux_score) +
            '<b>' + (d.grade_fa || '') + '</b>' +
            '<span style="color:var(--text-light);font-size:12px">هدف صفحه: ' + (d.goal_fa || '') + '</span></div>' +
            '<div style="font-size:12.5px;margin-bottom:6px"><b>💡 منطق طراحی (قوانین UX اعمال‌شده):</b></div><ul style="font-size:12.5px;margin:0 18px 8px 0;padding:0">';
        (d.rationale || []).forEach(r => { html += '<li style="margin-bottom:4px">' + r + '</li>'; });
        html += '</ul><div class="alert alert-info" style="margin:10px 0 0">💾 برای ذخیره، دکمه «ذخیره قالب» را بزنید. با «🔍 بررسی UX» می‌توانید چیدمان را ممیزی کنید.</div>';
        showUiuxPanel('✨ طراحی UI/UX Pro — ' + (d.page_name_fa || ''), html);
    } catch (err) {
        sahandError('خطای ارتباط با سرور — دوباره تلاش کنید');
    } finally {
        btn.disabled = false;
        btn.innerHTML = old;
    }
}

/* 🔍 ممیزی UX چیدمان فعلی */
async function uiuxReview() {
    if (!layout.length) { sahandError('اول حداقل یک بلوک به صفحه اضافه کنید یا از «✨ طراحی با UI/UX Pro» استفاده کنید.'); return; }
    const btn = document.getElementById('btn-uiux-review');
    const old = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> در حال بررسی...';
    try {
        const json = await uiuxRequest('uiux_review');
        if (!json.success) { sahandError('خطا: ' + (json.error || 'نامشخص')); return; }
        const d = json.data;
        let html = '<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px">' +
            uiuxScoreBadge(d.score) + '<b>' + (d.grade_fa || '') + '</b>' +
            '<span style="color:var(--text-light);font-size:12px">' + (d.stats ? d.stats.blocks : 0) + ' بخش • ' + (d.stats ? d.stats.cta_count : 0) + ' دکمه اقدام • ' + (d.stats ? d.stats.trust_count : 0) + ' سیگنال اعتماد</span></div>';
        if (d.wins && d.wins.length) {
            html += '<div style="font-size:12.5px;margin-bottom:4px"><b>✅ نقاط قوت:</b></div><ul style="font-size:12.5px;color:var(--success);margin:0 18px 10px 0;padding:0">';
            d.wins.forEach(w => { html += '<li style="margin-bottom:3px">' + w + '</li>'; });
            html += '</ul>';
        }
        if (d.issues && d.issues.length) {
            html += '<div style="font-size:12.5px;margin-bottom:4px"><b>⚠️ موارد قابل بهبود (به اولویت):</b></div><ul style="font-size:12.5px;margin:0 18px 10px 0;padding:0">';
            d.issues.forEach(i => { html += '<li style="margin-bottom:5px"><span class="badge badge-secondary" style="font-size:10.5px">' + (UIUX_SEVERITY_FA[i.severity] || i.severity) + '</span> ' + i.fa + '</li>'; });
            html += '</ul>';
        } else {
            html += '<div class="alert alert-success">🎉 مشکلی یافت نشد — چیدمان استانداردهای UX را رعایت می‌کند.</div>';
        }
        html += '<div style="text-align:center;margin-top:10px"><button type="button" class="btn btn-primary" onclick="uiuxImprove()">🛠️ اصلاح خودکار مشکلات</button></div>';
        showUiuxPanel('🔍 ممیزی UX — ' + (d.skill || ''), html);
    } catch (err) {
        sahandError('خطای ارتباط با سرور — دوباره تلاش کنید');
    } finally {
        btn.disabled = false;
        btn.innerHTML = old;
    }
}

/* 🛠️ اصلاح خودکار چیدمان */
async function uiuxImprove() {
    try {
        const json = await uiuxRequest('uiux_improve');
        if (!json.success) { sahandError('خطا: ' + (json.error || 'نامشخص')); return; }
        const d = json.data;
        layout = d.layout;
        selected = '';
        syncAndRender();
        renderProps();
        let html = '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px">' +
            '<span style="font-weight:800;font-size:13px">' + d.score_before + '</span><span>→</span>' + uiuxScoreBadge(d.score_after) +
            '<b>' + (d.grade_fa || '') + '</b></div><div style="font-size:12.5px;margin-bottom:4px"><b>🔧 تغییرات اعمال‌شده:</b></div><ul style="font-size:12.5px;margin:0 18px 8px 0;padding:0">';
        (d.changes || []).forEach(c => { html += '<li style="margin-bottom:4px">' + c + '</li>'; });
        html += '</ul><div class="alert alert-info" style="margin:8px 0 0">💾 برای ذخیره، دکمه «ذخیره قالب» را بزنید.</div>';
        showUiuxPanel('🛠️ اصلاح خودکار UI/UX Pro', html);
    } catch (err) {
        sahandError('خطای ارتباط با سرور — دوباره تلاش کنید');
    }
}

/* ==================================================
 * 🌐 v2.26 — استخراج عناصر از سایت خارجی + عناصر شخصی
 * ================================================== */
let extractResults = [];      /* نتایج آخرین استخراج */
let extractSelected = -1;     /* ایندکس عنصر انتخاب‌شده برای پیش‌نمایش */
let extractSourceUrl = '';    /* مبدأ آخرین استخراج */

function toggleExtractPanel(show) {
    const panel = document.getElementById('extract-panel');
    const visible = show === undefined ? panel.style.display === 'none' : show;
    panel.style.display = visible ? '' : 'none';
    if (visible) {
        const input = document.getElementById('extract-url');
        if (input) { input.focus(); }
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function extractStatus(kind, msg) {
    const el = document.getElementById('extract-status');
    el.style.display = msg ? '' : 'none';
    el.className = 'alert alert-' + kind;
    el.innerHTML = msg;
}

async function extractElements() {
    const url = String((document.getElementById('extract-url') || {}).value || '').trim();
    if (!/^https?:\/\/.+/i.test(url)) {
        extractStatus('danger', '⚠️ آدرس معتبر وارد کنید — مثلاً <b dir="ltr">https://example.com</b>');
        return;
    }
    const btn = document.getElementById('btn-extract');
    btn.disabled = true;
    btn.innerHTML = '⏳ در حال دانلود و تحلیل...';
    extractStatus('info', '🌐 صفحه دانلود می‌شود و عناصر آن همراه با استایل تحلیل می‌شوند — چند لحظه...');
    document.getElementById('extract-results').style.display = 'none';
    try {
        const fd = new FormData();
        fd.append('action', 'extract_elements');
        fd.append('url', url);
        fd.append('csrf_token', UIUX_CSRF);
        const res = await fetch('template-builder.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const json = await res.json();
        if (!json.success) {
            extractStatus('danger', '❌ ' + esc(json.error || 'خطای نامشخص'));
            return;
        }
        extractResults = json.elements || [];
        extractSourceUrl = json.url || url;
        extractSelected = -1;
        extractStatus(extractResults.length ? 'success' : 'warning',
            extractResults.length
                ? '✅ <b>' + faDigJS(String(extractResults.length)) + '</b> عنصر از «' + esc(json.title || extractSourceUrl) + '» استخراج شد — روی نام هر عنصر کلیک کنید تا کنارش پیش‌نمایش شود.'
                : '⚠️ عنصری پیدا نشد — سایت ممکن است جاوااسکریپت‌محور باشد یا ساختار ساده‌ای داشته باشد.');
        renderExtractList();
        document.getElementById('extract-results').style.display = extractResults.length ? '' : 'none';
        document.getElementById('extract-preview-frame').srcdoc = '<!doctype html><html dir="rtl"><body style="font-family:Tahoma;padding:30px;color:#94a3b8;text-align:center">👁️ روی یک عنصر از فهرست کلیک کنید</body></html>';
        document.getElementById('btn-save-element').style.display = 'none';
    } catch (err) {
        extractStatus('danger', '❌ خطای ارتباط با سرور — دوباره تلاش کنید');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '🔎 استخراج عناصر';
    }
}

function renderExtractList() {
    const list = document.getElementById('extract-list');
    document.getElementById('extract-count').textContent = faDigJS(String(extractResults.length));
    const typeFa = { button: 'دکمه', card: 'کارت', nav: 'منو', header: 'هدر', footer: 'فوتر', form: 'فرم', input: 'فیلد', heading: 'تیتر', badge: 'نشان', alert: 'هشدار', quote: 'نقل‌قول', list: 'لیست', image: 'تصویر' };
    list.innerHTML = extractResults.map((el, i) => `
        <div class="ext-item" data-idx="${i}" onclick="showExtractPreview(${i})" style="padding:9px 13px;border-bottom:1px solid var(--border);cursor:pointer;display:flex;gap:9px;align-items:center;font-size:12px;${i === extractSelected ? 'background:rgba(37,99,235,.09)' : ''}">
            <span style="font-size:16px">${el.icon || '⭐'}</span>
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(el.name || 'بدون نام')}</div>
                <div style="font-size:10px;color:var(--text-light)">${esc(typeFa[el.type] || el.type)} · ${faDigJS(String(Math.round((el.size || 0) / 1024)))}KB</div>
            </div>
            <span title="افزودن به عناصر شخصی" onclick="event.stopPropagation();showExtractPreview(${i});saveCurrentElement()" style="color:#16a34a;font-size:15px;cursor:pointer">➕</span>
        </div>`).join('');
}

function showExtractPreview(idx) {
    if (!extractResults[idx]) { return; }
    extractSelected = idx;
    const el = extractResults[idx];
    document.querySelectorAll('#extract-list .ext-item').forEach((n, i) => {
        n.style.background = i === idx ? 'rgba(37,99,235,.09)' : '';
    });
    document.getElementById('extract-preview-name').textContent = '— ' + (el.name || 'بدون نام');
    /* سند مستقل: استایل تخت‌شده + HTML ایمن‌شده */
    const doc = '<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8">'
        + '<style>*{box-sizing:border-box}body{margin:0;padding:20px;background:#fff;font-family:Vazirmatn,Tahoma,sans-serif}img{max-width:100%;height:auto}a{text-decoration:none}'
        + String(el.css || '').replace(/</g, '\\3C ') + '</style></head><body>' + (el.html || '') + '</body></html>';
    document.getElementById('extract-preview-frame').srcdoc = doc;
    document.getElementById('btn-save-element').style.display = '';
}

async function saveCurrentElement() {
    if (extractSelected < 0 || !extractResults[extractSelected]) {
        sahandsAlert('اول یک عنصر را از فهرست انتخاب کنید.');
        return;
    }
    const el = extractResults[extractSelected];
    const btn = document.getElementById('btn-save-element');
    btn.disabled = true;
    btn.innerHTML = '⏳ در حال ذخیره...';
    try {
        const fd = new FormData();
        fd.append('action', 'save_personal_element');
        fd.append('name', el.name || 'عنصر بدون نام');
        fd.append('type', el.type || 'button');
        fd.append('source_url', extractSourceUrl);
        fd.append('html', el.html || '');
        fd.append('css', el.css || '');
        fd.append('csrf_token', UIUX_CSRF);
        const res = await fetch('template-builder.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const json = await res.json();
        if (!json.success) {
            sahandsAlert('ذخیره ناموفق: ' + (json.error || 'خطا'));
            return;
        }
        /* افزودن به کتابخانه شخصی بدون رفرش */
        PERSONAL_ELEMENTS[json.data.id] = { name: json.data.name, element_type: json.data.element_type, html: json.data.html, css: json.data.css };
        const lib = document.querySelector('#block-library .block-cat + .hint');
        const emptyHint = document.querySelector('#block-library .hint');
        const cat = Array.from(document.querySelectorAll('.block-cat')).find(c => c.textContent.includes('عناصر شخصی'));
        if (cat) {
            const badge = cat.querySelector('.badge');
            if (badge) { badge.textContent = faDigJS(String(Object.keys(PERSONAL_ELEMENTS).length)); }
            /* حذف hint خالی‌بودن */
            let sib = cat.nextElementSibling;
            if (sib && sib.classList.contains('hint') && sib.textContent.includes('استخراج و ذخیره کرده‌اید')) { sib.remove(); }
            const item = document.createElement('div');
            item.className = 'block-item';
            item.draggable = true;
            item.dataset.block = 'pelement:' + json.data.id;
            item.style.borderInlineStart = '3px solid #16a34a';
            item.title = 'عنصر شخصی استخراج‌شده — دابل‌کلیک یا درگ کنید';
            item.innerHTML = '<span class="icon">⭐</span><span>' + esc(json.data.name) + '</span>'
                + '<button type="button" class="block-eye" title="پیش‌نمایش عنصر" onclick="event.stopPropagation();previewPersonalElement(' + json.data.id + ')">👁</button>'
                + '<button type="button" class="block-eye" style="color:#dc2626" title="حذف" onclick="event.stopPropagation();deletePersonalElement(' + json.data.id + ', this)">🗑</button>';
            const firstCat = document.querySelector('#block-library .block-cat:not(:first-child)');
            cat.after(item);
            bindBlockItem(item);
        }
        extractStatus('success', '✅ عنصر «' + esc(json.data.name) + '» به کتابخانه «⭐ عناصر شخصی من» اضافه شد — از پنل بلوک‌ها قابل درج در صفحه است.');
    } catch (err) {
        sahandsAlert('خطای ارتباط با سرور');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '➕ افزودن به عناصر شخصی';
    }
}

async function deletePersonalElement(id, btn) {
    if (!confirm('این عنصر شخصی حذف شود؟')) { return; }
    try {
        const fd = new FormData();
        fd.append('action', 'delete_personal_element');
        fd.append('element_id', id);
        fd.append('csrf_token', UIUX_CSRF);
        const res = await fetch('template-builder.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const json = await res.json();
        if (json.success) {
            delete PERSONAL_ELEMENTS[id];
            const item = btn.closest('.block-item');
            if (item) { item.remove(); }
        }
    } catch (err) { /* بی‌صدا */ }
}

function previewPersonalElement(id) {
    const el = PERSONAL_ELEMENTS[id];
    if (!el) { return; }
    toggleExtractPanel(true);
    extractResults = [el];
    extractSelected = 0;
    renderExtractList();
    showExtractPreview(0);
}

function sahandsAlert(msg) {
    extractStatus('warning', '⚠️ ' + msg);
    toggleExtractPanel(true);
}

/* شروع */
applyPageSettings();
render();
renderProps();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
