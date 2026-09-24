<?php
/**
 * 👁️ پیش‌نمایش زنده قالب — رندر چیدمان بلوک‌ها با محتوای نمونه (v3.0)
 * =====================================================================
 * درون iframe قالب‌ساز نمایش داده می‌شود؛ GET:
 *   id=    شناسه قالب (اختیاری — از دیتابیس)
 *   json=  چیدمان JSON خام (اختیاری — پیش‌نمایش ذخیره‌نشده)
 *   block= یک بلوک تکی برای پیش‌نمایش (اختیاری)
 *
 * 🆕 v3.0: پشتیبانی «بخش چندستونی» — چیدمان تودرتو (cols) + ۵۵+ بلوک
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$auth = new Auth();
$auth->requireLogin();

/* 📥 چیدمان از یکی از سه منبع */
$layout = [];
$templateId = (int)get_param('id');
$rawJson = (string)get_param('json', '');
$singleBlock = (string)get_param('block', '');

if ($templateId > 0) {
    $tpl = Database::getInstance()->fetch('SELECT layout_json FROM templates WHERE id = ?', [$templateId]);
    $layout = $tpl ? (json_decode($tpl['layout_json'] ?? '[]', true) ?: []) : [];
} elseif ($rawJson !== '') {
    $decoded = json_decode($rawJson, true);
    $layout = is_array($decoded) ? $decoded : [];
} elseif ($singleBlock !== '') {
    $layout = [['block' => $singleBlock, 'props' => [], 'order' => 0]];
}

/**
 * 🎨 رندر یک بلوک به HTML واقعی با محتوای نمونه فارسی (v3.0 — ۵۵+ بلوک)
 */
function renderPreviewBlock(string $block, array $props = []): string
{
    $title = $props['title'] ?? '';
    $bg = $props['background'] ?? 'default';
    $bgClass = 'blk-bg-' . ($bg ?: 'default');
    $pad = $props['padding'] ?? 'default';
    $padClass = 'blk-pad-' . ($pad ?: 'default');
    $hidden = isset($props['visible']) && $props['visible'] === false;
    if ($hidden) {
        return '<div class="blk-hidden">🙈 بخش پنهان: <b>' . e($block) . '</b></div>';
    }
    /* 🎛 v3.3: تنظیمات پیشرفته — اندازه عنوان / تراز / عرض محتوا / کلاس سفارشی */
    $sizeCls = 'blk-ts-' . ($props['titleSize'] ?? 'md');
    $alignCls = isset($props['align']) && $props['align'] !== 'start' && $props['align'] !== '' ? 'blk-al-' . $props['align'] : '';
    $widthCls = isset($props['width']) && $props['width'] !== 'full' && $props['width'] !== '' ? 'blk-w-' . $props['width'] : '';
    $customCls = preg_replace('/[^a-zA-Z0-9\-_\s]/', '', (string)($props['customClass'] ?? ''));
    $extraCls = $sizeCls . ' ' . $alignCls . ' ' . $widthCls . ' ' . $customCls;
    $head = $title ? '<div class="blk-title">' . e($title) . '</div>' : '';

    switch ($block) {
        case 'top-bar':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' topbar-blk"><span>📞 ۰۲۱-۱۲۳۴۵۶۷۸</span><span>🕐 شنبه تا پنجشنبه ۹ تا ۲۰</span></div>';
        case 'header-v1':
        case 'header-v2':
        case 'header-v3':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' header-blk' . ($block === 'header-v3' ? ' glass' : '') . (!empty($props['sticky']) ? ' sticky-demo' : '') . '">' . ($block === 'header-v2' ? '<div class="tb-row"><span>📞 ۰۲۱-۱۲۳۴۵۶۷۸</span><span>💬 پاسخگویی آنلاین</span></div>' : '') . '<div class="h-row"><div class="fake-logo">🏗️</div><nav class="fake-nav"><span>خانه</span><span>خدمات</span><span>مقالات</span><span>تماس</span></nav><div class="fake-cta">ثبت درخواست</div></div></div>';
        case 'hero':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk"><div class="hero-title">' . ($title ?: 'تعمیرات تخصصی با قطعات اصلی') . '</div><div class="hero-sub">' . e($props['subtitle'] ?? 'نمایندگی رسمی — پاسخگویی ۷ روز هفته') . '</div><div class="hero-btns"><span class="hero-btn">📞 تماس فوری</span><span class="hero-btn ghost">ثبت درخواست آنلاین</span></div></div>';
        case 'hero-slider':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk slider"><div class="hero-title">' . ($title ?: 'اسلایدر تصویری') . '</div><div class="hero-img wide">🖼️</div><div class="slider-dots">● ○ ○</div></div>';
        case 'hero-split':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk split-hero"><div class="hero-split"><div><div class="hero-title">' . ($title ?: 'تعمیر لوازم خانگی در محل') . '</div><div class="hero-sub">متن معرفی + دکمه فراخوان</div><div class="hero-btns"><span class="hero-btn">شروع کنید</span></div></div><div class="hero-img">🛠️</div></div></div>';
        case 'hero-video':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk video"><div class="hero-title">' . ($title ?: 'هیرو با پس‌زمینه تصویر') . '</div><div class="play">▶</div></div>';
        case 'hero-countdown':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk"><div class="hero-title">' . ($title ?: 'کمپین سرویس دوره‌ای') . '</div><div class="count-row"><span class="count-box"><b>۰۲</b>روز</span><span class="count-box"><b>۱۴</b>ساعت</span><span class="count-box"><b>۳۰</b>دقیقه</span></div></div>';
        case 'text':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="pv-text">' . nl2br(e($props['text'] ?? 'متن نمونه — این بخش در سایت به همین شکل نمایش داده می‌شود.')) . '</div></div>';
        case 'text-image':
        case 'intro':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' split"><div><div class="blk-title">' . ($title ?: 'درباره برند') . '</div><div class="fake-lines"><div class="fl w100"></div><div class="fl w90"></div><div class="fl w60"></div></div></div><div class="fake-img">🖼️</div></div>';
        case 'rich-text':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<ul class="pv-list"><li>✅ نصب و راه‌اندازی تخصصی</li><li>✅ تعمیر با قطعات اصلی</li><li>✅ ۶ ماه ضمانت قطعه و خدمات</li></ul></div>';
        case 'quote':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' quote-blk"><div class="quote">«' . e($props['text'] ?? 'کیفیت تعمیر، اعتبار ماست') . '»</div></div>';
        case 'two-col':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c2"><div class="fake-card"><div class="card-t">ستون اول</div><div class="fl w90"></div><div class="fl w70"></div></div><div class="fake-card"><div class="card-t">ستون دوم</div><div class="fl w90"></div><div class="fl w70"></div></div></div></div>';
        case 'three-col':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="cols c3"><div class="fake-card"><div class="card-t">موضوع ۱</div><div class="fl w80"></div></div><div class="fake-card"><div class="card-t">موضوع ۲</div><div class="fl w80"></div></div><div class="fake-card"><div class="card-t">موضوع ۳</div><div class="fl w80"></div></div></div></div>';
        case 'section-columns':
        case 'section-split':
            /* 🏛 خود بخش چندستونی — فقط قاب/عنوان؛ ستون‌ها توسط renderLayoutLevel رندر می‌شوند */
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . ($head ?: '<div class="blk-title" style="opacity:.55;margin-bottom:0">🏛 بخش چندستونی</div>') . '</div>';
        case 'feature-list':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="feat-list"><div class="feat-row"><span class="feat-ico">⚡</span><div><b>سرعت عمل</b><div class="feat-d">اعزام تکنسین در کمتر از ۲ ساعت</div></div></div><div class="feat-row"><span class="feat-ico">🛡️</span><div><b>ضمانت کتبی</b><div class="feat-d">۶ ماه ضمانت روی قطعه و خدمات</div></div></div><div class="feat-row"><span class="feat-ico">💰</span><div><b>قیمت شفاف</b><div class="feat-d">پیش‌فاکتور قبل از شروع کار</div></div></div></div></div>';
        case 'services-grid':
        case 'features':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: ($block === 'features' ? 'چرا ما را انتخاب کنید؟' : 'خدمات ما')) . '</div><div class="cols c3">' . str_repeat('<div class="fake-card"><div class="card-ico">🔧</div><div class="card-t">سرویس نمونه</div><div class="fl w80"></div></div>', 3) . '</div></div>';
        case 'devices-grid':
            $devs = ['🌀 لباسشویی', '🧊 یخچال', '🍽️ ظرفشویی', '❄️ کولر', '📺 تلویزیون', '♨️ پکیج', '📻 مایکروویو', '🔥 فر و اجاق'];
            $cards = '';
            foreach ($devs as $d) {
                [$ico, $name] = explode(' ', $d, 2);
                $cards .= '<div class="fake-card"><div class="card-ico">' . $ico . '</div><div class="card-t">' . $name . '</div></div>';
            }
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'دستگاه‌های تحت پوشش') . '</div><div class="cols c4">' . $cards . '</div></div>';
        case 'articles-recent':
        case 'articles-grid':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'مقالات اخیر') . '</div><div class="cols c3">' . str_repeat('<div class="fake-card"><div class="fake-img small">📰</div><div class="card-t">عنوان مقاله نمونه</div><div class="fl w100"></div></div>', 3) . '</div></div>';
        case 'team':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'تیم ما') . '</div><div class="cols c4">' . str_repeat('<div class="fake-card"><div class="fake-ava">👤</div><div class="card-t">عضو تیم</div></div>', 4) . '</div></div>';
        case 'pricing-table':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'تعرفه خدمات') . '</div><div class="price-table"><div class="price-row"><span>دریافت و عیب‌یابی تخصصی</span><b>رایگان</b></div><div class="price-row"><span>سرویس دوره‌ای لباسشویی</span><b>از ۴۵۰ هزار تومان</b></div><div class="price-row"><span>شارژ گاز کولر گازی</span><b>از ۹۰۰ هزار تومان</b></div></div></div>';
        case 'brands-links':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'برندهای مورد خدمت') . '</div><div class="cols c6">' . str_repeat('<div class="fake-logo-s">🏷️</div>', 6) . '</div></div>';
        case 'contact-form':
        case 'request-form':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: ($block === 'request-form' ? 'فرم درخواست خدمات' : 'فرم تماس')) . '</div><div class="form-grid"><div class="fake-input">نام و نام خانوادگی</div><div class="fake-input">شماره تماس</div><div class="fake-input">شرح مشکل</div><div class="hero-btn full">ارسال درخواست</div></div></div>';
        case 'newsletter-form':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'عضویت در خبرنامه') . '</div><div class="news-row"><div class="fake-input" style="flex:1">ایمیل شما</div><div class="hero-btn">عضویت</div></div></div>';
        case 'counter-stats':
        case 'stats':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' stats-blk"><div class="stat"><div class="stat-n">۱۲+</div><div class="stat-l">سال تجربه</div></div><div class="stat"><div class="stat-n">۵۰هزار+</div><div class="stat-l">تعمیر موفق</div></div><div class="stat"><div class="stat-n">۹۸٪</div><div class="stat-l">رضایت</div></div></div>';
        case 'progress-bars':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="pbar"><span>سرعت تعمیر</span><div class="track"><div class="fill" style="width:90%"></div></div></div><div class="pbar"><span>کیفیت قطعات</span><div class="track"><div class="fill" style="width:95%"></div></div></div></div>';
        case 'skill-bars':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="pbar"><span>تعمیر برد و الکترونیک</span><div class="track"><div class="fill" style="width:88%"></div></div></div><div class="pbar"><span>کمپرسور و مدار گاز</span><div class="track"><div class="fill" style="width:82%"></div></div></div></div>';
        case 'testimonials':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'نظرات مشتریان') . '</div><div class="quote">«سرویس سریع و منظم بود؛ راضی بودم.»</div><div class="slider-dots">● ○ ○</div></div>';
        case 'faq-accordion':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'سوالات متداول') . '</div><div class="acc">سوال نمونه اول؟ <b>＋</b></div><div class="acc">سوال نمونه دوم؟ <b>＋</b></div><div class="acc">سوال نمونه سوم؟ <b>＋</b></div></div>';
        case 'tabs':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'تب‌بندی محتوا') . '</div><div class="tabs-row"><span class="tab cur">تعمیر</span><span class="tab">سرویس</span><span class="tab">نصب</span></div><div class="fake-card" style="text-align:right"><div class="fl w100"></div><div class="fl w90"></div><div class="fl w60"></div></div></div>';
        case 'timeline':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'مراحل پیشرفت کار') . '</div><div class="tl"><div class="tl-item done"><span class="tl-dot">✓</span><div>ثبت درخواست</div></div><div class="tl-item done"><span class="tl-dot">✓</span><div>عیب‌یابی و پیش‌فاکتور</div></div><div class="tl-item cur"><span class="tl-dot">۳</span><div>تعمیر در حال انجام</div></div><div class="tl-item"><span class="tl-dot">۴</span><div>تحویل و ضمانت</div></div></div></div>';
        case 'steps-process':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'فرآیند کار ما') . '</div><div class="steps-row"><div class="step"><span class="step-n">۱</span><div class="step-t">تماس/ثبت درخواست</div></div><div class="step-arrow">←</div><div class="step"><span class="step-n">۲</span><div class="step-t">اعزام تکنسین</div></div><div class="step-arrow">←</div><div class="step"><span class="step-n">۳</span><div class="step-t">تعمیر و تست</div></div></div></div>';
        case 'gallery':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'گالری') . '</div><div class="cols c4">' . str_repeat('<div class="fake-img small">🖼️</div>', 4) . '</div></div>';
        case 'image-carousel':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'کاروسل تصاویر') . '</div><div class="fake-img wide" style="height:190px">🎠 ‹ ›</div><div class="slider-dots">● ○ ○</div></div>';
        case 'video-embed':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'ویدیوی آموزشی') . '</div><div class="fake-img wide" style="height:190px">▶ ویدیوی آموزشی</div></div>';
        case 'map':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">' . ($title ?: 'محدوده خدمات') . '</div><div class="fake-map">📍 نقشه محدوده خدمات</div></div>';
        case 'cta-phone':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' cta-blk"><div class="hero-title">همین حالا تماس بگیرید</div><div class="cta-num" dir="ltr">' . e($props['phone'] ?? '۰۲۱-۱۲۳۴۵۶۷۸') . '</div></div>';
        case 'cta-request':
        case 'cta-banner':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' cta-blk"><div class="hero-title">' . ($title ?: 'درخواست تعمیر خود را ثبت کنید') . '</div><span class="hero-btn">📝 ثبت درخواست</span></div>';
        case 'sticky-mobile-cta':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' sticky-cta-demo"><span>📞 ۰۲۱-۱۲۳۴۵۶۷۸</span><span class="hero-btn">ثبت درخواست</span></div>';
        case 'breadcrumb':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' crumb">خانه / خدمات / <b>صفحه فعلی</b></div>';
        case 'alert-notice': {
            $type = $props['alertType'] ?? 'info';
            $ico = ['info' => 'ℹ️', 'warning' => '⚠️', 'success' => '✅'][$type] ?? 'ℹ️';
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="alert-demo ' . e($type) . '">' . $ico . ' ' . e($props['text'] ?? 'سرویس در تعطیلات نیز پاسخگوی شماست') . '</div></div>';
        }
        case 'button-group':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="hero-btns" style="justify-content:flex-start"><span class="hero-btn">تماس فوری</span><span class="hero-btn ghost">مشاهده خدمات</span><span class="hero-btn ghost">مقالات</span></div></div>';
        case 'icon-list':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '">' . $head . '<div class="feat-list"><div class="feat-row"><span class="feat-ico">📞</span><div><b>پاسخگویی تلفنی</b><div class="feat-d">۷ روز هفته از ۹ تا ۲۰</div></div></div><div class="feat-row"><span class="feat-ico">📍</span><div><b>اعزام در محل</b><div class="feat-d">کل تهران و کرج</div></div></div></div></div>';
        case 'separator':
            return '<hr class="blk-sep">';
        case 'spacer':
            return '<div class="blk-spacer" style="height:' . (int)($props['height'] ?? 46) . 'px" title="فاصله"></div>';
        case 'footer-simple':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' footer-blk"><div class="fake-logo">🏗️</div><nav class="fake-nav" style="justify-content:center"><span>خدمات</span><span>مقالات</span><span>تماس</span></nav><div class="soc-row"><span>Telegram</span><span>Instagram</span><span>WhatsApp</span></div></div>';
        case 'footer-contact':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' footer-blk"><div class="cols c3"><div><div class="fake-logo">🏗️</div><div class="fl w80"></div></div><div><div class="card-t">تماس</div><div class="feat-d">📞 ۰۲۱-۱۲۳۴۵۶۷۸<br>📍 تهران، خیابان نمونه</div></div><div><div class="card-t">ساعات کاری</div><div class="feat-d">شنبه تا پنجشنبه<br>۹ صبح تا ۸ شب</div></div></div></div>';
        case 'copyright':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' crump-blk">© تمامی حقوق برای نمایندگی محفوظ است</div>';
        /* ════════ 🆕 v2.12: عناصر — پیش‌نمایش واقعی (قبلاً fallback بودند!) ════════ */
        case 'notification-bar':
            return '<div class="blk notif-bar ' . e($props['notifColor'] ?? 'info') . '" style="padding:8px 14px">' . e($props['text'] ?? '🎉 سرویس ویژه تعطیلات — ۱۵٪ تخفیف سرویس دوره‌ای') . '</div>';
        case 'hero-form':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' hero-blk split-hero"><div class="hero-split"><div><div class="hero-title">' . ($title ?: 'درخواست تعمیر آنلاین') . '</div><div class="hero-sub">فرم را پر کنید — کارشناسان ما تماس می‌گیرند</div><div class="hero-btns"><span class="hero-btn">📞 تماس فوری</span></div></div><div class="fake-card" style="text-align:right;background:rgba(255,255,255,.14);border:none"><div class="fake-input">نام و شماره تماس</div><div class="fake-input">نوع دستگاه</div><div class="hero-btn full" style="margin-top:8px">ثبت درخواست</div></div></div></div>';
        case 'hero-marquee':
            return '<div class="blk marquee-blk"><div class="marquee-track"><span>' . e($props['text'] ?? '⚡ اعزام تکنسین در کمتر از ۲ ساعت — ⭐ بیش از ۵۰ هزار تعمیر موفق') . '</span></div></div>';
        case 'brand-story':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<div class="story-wrap"><div class="story-sec"><span class="story-year">۱۳۸۵</span><div><b>شروع فعالیت</b><div class="feat-d">اولین مرکز تعمیرات با یک تعمیرکار</div></div></div><div class="story-sec"><span class="story-year">۱۳۹۲</span><div><b>گسترش خدمات</b><div class="feat-d">پوشش تمام لوازم خانگی</div></div></div><div class="story-sec"><span class="story-year">امروز</span><div><b>نمایندگی رسمی</b><div class="feat-d">تیم ۱۲ نفره و ۵۰ هزار تعمیر موفق</div></div></div></div></div>';
        case 'area-list':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<div class="chip-row">' . implode('', array_map(static fn($a) => '<span class="chip">📍 ' . $a . '</span>', ['سعادت‌آباد', 'پونک', 'ولنجک', 'تجریش', 'شهرک غرب', 'نیاوران', 'میرداماد', 'جردن'])) . '</div></div>';
        case 'checklist':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<div class="feat-list"><div class="feat-row"><span class="feat-ico">☑️</span><div>دستگاه را روشن و خاموش کنید و دوباره امتحان کنید</div></div><div class="feat-row"><span class="feat-ico">☑️</span><div>کد خطای نمایشگر را یادداشت کنید</div></div><div class="feat-row"><span class="feat-ico">☑️</span><div>صداهای غیرعادی و بوی سوختگی را بررسی کنید</div></div><div class="feat-row"><span class="feat-ico">☑️</span><div>فاکتور خرید و گارانتی را آماده داشته باشید</div></div></div></div>';
        case 'search-bar':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="search-wrap"><span class="search-ico">🔎</span><div class="fake-input" style="flex:1;border:none">' . e($props['placeholder'] ?? 'جستجوی کد خطا، مقاله یا دستگاه...') . '</div><span class="hero-btn">جستجو</span></div></div>';
        case 'certificates':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '"><div class="blk-title">' . ($title ?: 'گواهینامه‌ها و افتخارات') . '</div><div class="cols c3">' . implode('', array_map(static fn($p) => '<div class="fake-card"><div class="card-ico">' . $p[0] . '</div><div class="card-t">' . $p[1] . '</div><div class="fl w60"></div></div>', [['🎖️', 'نمایندگی رسمی'], ['📋', 'گواهی ایزو ۹۰۰۱'], ['🏆', 'برترین خدمات ۱۴۰۳']])) . '</div></div>';
        case 'review-grid':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '"><div class="blk-title">' . ($title ?: 'مشتریان ما چه می‌گویند') . '</div><div class="cols c3">' . str_repeat('<div class="fake-card"><div class="stars">⭐⭐⭐⭐⭐</div><div class="fl w90"></div><div class="fl w70"></div><div class="fake-ava" style="margin-top:8px">👤</div></div>', 3) . '</div></div>';
        case 'contact-cards':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '"><div class="blk-title">' . ($title ?: 'راه‌های ارتباطی') . '</div><div class="cols c3"><div class="fake-card"><div class="card-ico">📞</div><div class="card-t">تلفن</div><div class="feat-d" dir="ltr">۰۲۱-۱۲۳۴۵۶۷۸</div></div><div class="fake-card"><div class="card-ico">💬</div><div class="card-t">واتساپ</div><div class="feat-d" dir="ltr">۰۹۱۲-۰۰۰-۰۰۰۰</div></div><div class="fake-card"><div class="card-ico">📍</div><div class="card-t">آدرس</div><div class="feat-d">تهران، خیابان نمونه</div></div></div></div>';
        case 'appointment-form':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '"><div class="blk-title">' . ($title ?: 'رزرو نوبت سرویس') . '</div><div class="form-grid"><div class="fake-input">نام و شماره تماس</div><div class="fake-input">📅 تاریخ مورد نظر</div><div class="fake-input">🕐 بازه ساعتی (۹-۱۲ / ۱۲-۱۵ / ۱۵-۱۸)</div><div class="fake-input">نوع دستگاه و شرح مشکل</div><div class="hero-btn full">رزرو نوبت</div></div></div>';
        case 'stats-grid':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '"><div class="blk-title">' . ($title ?: 'سهند سرویس در یک نگاه') . '</div><div class="cols c3"><div class="fake-card"><div class="stat-n">۱۲+</div><div class="feat-d">سال تجربه</div></div><div class="fake-card"><div class="stat-n">۵۰k+</div><div class="feat-d">تعمیر موفق</div></div><div class="fake-card"><div class="stat-n">۹۸٪</div><div class="feat-d">رضایت مشتری</div></div><div class="fake-card"><div class="stat-n">۲h</div><div class="feat-d">اعزام تکنسین</div></div><div class="fake-card"><div class="stat-n">۴۲</div><div class="feat-d">نوع دستگاه</div></div><div class="fake-card"><div class="stat-n">۶ ماه</div><div class="feat-d">ضمانت کتبی</div></div></div></div>';
        case 'before-after':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '"><div class="blk-title">' . ($title ?: 'نتیجه تعمیر حرفه‌ای') . '</div><div class="ba-wrap"><div class="ba-side"><div class="ba-tag bad">قبل</div><div class="fake-img small" style="height:110px">🧺 فرسوده</div></div><div class="ba-arrow">⇐</div><div class="ba-side"><div class="ba-tag ok">بعد</div><div class="fake-img small" style="height:110px">✨ مثل روز اول</div></div></div></div>';
        case 'cta-whatsapp':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' cta-blk"><div class="hero-btns"><span class="hero-btn" style="background:#16a34a">💬 گفتگو در واتساپ</span><span class="hero-btn ghost">📞 تماس تلفنی</span></div></div>';
        case 'warranty-banner':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="feat-row" style="align-items:center"><span class="feat-ico" style="font-size:30px">🛡️</span><div><b style="font-size:15px">ضمانت کتبی ۶ ماهه روی قطعه و خدمات</b><div class="feat-d">در صورت ایراد مجدد، تعمیر اصلاحی رایگان</div></div><span class="hero-btn" style="margin-inline-start:auto">مشاهده شرایط</span></div></div>';
        case 'working-hours':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '"><div class="blk-title">' . ($title ?: 'ساعات کاری') . '</div><div class="price-table"><div class="price-row"><span>شنبه تا چهارشنبه</span><b>۹ صبح تا ۸ شب</b></div><div class="price-row"><span>پنجشنبه</span><b>۹ صبح تا ۲ ظهر</b></div><div class="price-row"><span>جمعه</span><b>⚠️ فقط امداد فوری</b></div></div></div>';
        case 'social-follow':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '"><div class="blk-title">' . ($title ?: 'ما را دنبال کنید') . '</div><div class="hero-btns"><span class="hero-btn" style="background:#229ED9"> Telegram</span><span class="hero-btn" style="background:linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888)"> Instagram</span><span class="hero-btn" style="background:#25D366"> WhatsApp</span><span class="hero-btn" style="background:#e11d48"> Aparat</span></div></div>';
        case 'trust-badges':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="chip-row" style="justify-content:space-around">' . implode('', array_map(static fn($p) => '<div style="text-align:center;min-width:86px"><div style="font-size:26px">' . $p[0] . '</div><div class="feat-d" style="font-size:11px">' . $p[1] . '</div></div>', [['🛡️', 'ضمانت کتبی'], ['💳', 'پرداخت اقساطی'], ['⚡', 'اعزام فوری'], ['🏆', 'نمایندگی رسمی'], ['🔧', 'قطعات اصلی']])) . '</div></div>';
        case 'footer-links':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' footer-blk"><div class="cols" style="grid-template-columns:2fr 1fr 1fr 1fr;gap:16px"><div><div class="fake-logo">🏗️</div><div class="fl w90"></div><div class="fl w60"></div><div class="soc-row"><span>Telegram</span><span>Instagram</span></div></div><div><div class="card-t">خدمات</div><div class="feat-d">تعمیر لباسشویی<br>تعمیر یخچال<br>سرویس کولر</div></div><div><div class="card-t">لینک‌ها</div><div class="feat-d">مقالات<br>کدهای خطا<br>سوالات متداول</div></div><div><div class="card-t">تماس</div><div class="feat-d">📞 ۰۲۱-۱۲۳۴۵۶۷۸<br>📍 تهران</div></div></div></div>';
        case 'payment-methods':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title" style="margin-bottom:8px">شیوه‌های پرداخت</div><div class="chip-row" style="justify-content:center">' . implode('', array_map(static fn($p) => '<span class="chip">' . $p . '</span>', ['💳 پرداخت کارتی', '💰 پرداخت نقدی', '🧾 کارت به کارت', '📟 درگاه آنلاین', '🤝 اقساطی'])) . '</div></div>';

        /* ════════ 🆕 v3.3: عناصر جدید — پیش‌نمایش واقعی ════════ */
        case 'announcement-pill':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="pill-announce"><span class="pill-dot"></span>' . ($title ?: '📣 تیتر مهم امروز') . '</div></div>';
        case 'heading-center':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div style="text-align:center"><div class="blk-title" style="font-size:23px">' . ($title ?: 'عنوان بزرگ بخش') . '</div><div class="feat-d" style="font-size:13.5px;margin-top:6px">' . e($props['subtitle'] ?? 'زیرعنوان توضیحی این بخش') . '</div><div style="width:56px;height:4px;border-radius:4px;background:var(--p);margin:14px auto 0"></div></div></div>';
        case 'numbered-list':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<div class="num-list"><div class="num-row"><span class="num-n">۱</span><div><b>عیب‌یابی تخصصی رایگان</b><div class="feat-d">بررسی کامل با دستگاه تست</div></div></div><div class="num-row"><span class="num-n">۲</span><div><b>پیش‌فاکتور شفاف</b><div class="feat-d">تأیید قیمت قبل از شروع کار</div></div></div><div class="num-row"><span class="num-n">۳</span><div><b>تعمیر با قطعات اصلی</b><div class="feat-d">همراه با ۶ ماه ضمانت</div></div></div></div></div>';
        case 'info-box':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="info-box-demo"><span class="feat-ico" style="font-size:22px">' . e($props['icon'] ?? '💡') . '</span><div><b>' . ($title ?: 'نکته مهم') . '</b><div class="feat-d">' . e($props['text'] ?? 'متن توضیح جعبه اطلاعات...') . '</div></div></div></div>';
        case 'price-cards':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '"><div class="blk-title">' . ($title ?: 'پلن‌های سرویس') . '</div><div class="cols c3"><div class="fake-card"><div class="card-t">اقتصادی</div><div class="stat-n">۴۵۰<span style="font-size:11px">هزار</span></div><div class="feat-d">سرویس پایه + تست</div></div><div class="fake-card" style="border-color:var(--p);box-shadow:0 6px 20px rgba(37,99,235,.16)"><span class="badge badge-info" style="font-size:9.5px">پیشنهاد ما</span><div class="card-t">استاندارد</div><div class="stat-n">۷۸۰<span style="font-size:11px">هزار</span></div><div class="feat-d">سرویس کامل + شست‌وشو</div></div><div class="fake-card"><div class="card-t">ویژه</div><div class="stat-n">۱۲۵۰<span style="font-size:11px">هزار</span></div><div class="feat-d">اورهال + ضمانت ۹ ماهه</div></div></div></div>';
        case 'location-cards':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '"><div class="blk-title">' . ($title ?: 'شعب ما') . '</div><div class="cols c3">' . implode('', array_map(static fn($p) => '<div class="fake-card"><div class="card-ico">' . $p[0] . '</div><div class="card-t">' . $p[1] . '</div><div class="feat-d">📍 ' . $p[2] . '</div></div>', [['🏬', 'شعبه مرکزی', 'تهران، ولیعصر'], ['🏬', 'شعبه غرب', 'شهرک غرب'], ['🏬', 'شعبه شمال', 'نیاوران']])) . '</div></div>';
        case 'expert-cards':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '"><div class="blk-title">' . ($title ?: 'متخصصین ما') . '</div><div class="cols c4">' . implode('', array_map(static fn($p) => '<div class="fake-card"><div class="fake-ava">' . $p[0] . '</div><div class="card-t">' . $p[1] . '</div><div class="feat-d">' . $p[2] . '</div><div class="stars" style="font-size:10px">⭐ ۴.۹</div></div>', [['🔧', 'مهندس کریمی', 'برد و الکترونیک'], ['❄️', 'مهندس رضایی', 'مدار برودت'], ['🌀', 'مهندس احمدی', 'سیستم شست‌وشو'], ['📺', 'مهندس موسوی', 'پنل و تاچ']])) . '</div></div>';
        case 'logo-cloud':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<div class="chip-row" style="justify-content:center">' . implode('', array_map(static fn($i) => '<div class="fake-logo-s" style="width:64px">' . $i . '</div>', ['🏅', '📋', '🎖️', '✅', '🏛️', '🛡️', '💳', '⭐'])) . '</div></div>';
        case 'social-proof':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="soc-proof"><div class="ava-stack"><span class="fake-ava" style="width:34px;height:34px;font-size:13px">👩</span><span class="fake-ava" style="width:34px;height:34px;font-size:13px;margin-inline-start:-10px">🧑</span><span class="fake-ava" style="width:34px;height:34px;font-size:13px;margin-inline-start:-10px">👨</span><span class="fake-ava" style="width:34px;height:34px;font-size:11px;margin-inline-start:-10px">+۵۰k</span></div><div><div class="stars">⭐⭐⭐⭐⭐ <b>۴.۹ از ۵</b></div><div class="feat-d">' . e($props['text'] ?? 'بیش از ۵۰ هزار مشتری به ما اعتماد کرده‌اند') . '</div></div></div></div>';
        case 'link-buttons':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . ' ' . $extraCls . '">' . $head . '<div class="hero-btns" style="justify-content:flex-start"><span class="hero-btn">📄 دانلود بروشور</span><span class="hero-btn ghost">🔎 پیگیری درخواست</span><span class="hero-btn ghost">🧾 فاکتور آنلاین</span></div></div>';
        case 'promo-card':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="promo-card-demo"><div><span class="badge badge-warning" style="font-size:10.5px">🎁 پیشنهاد ویژه</span><div class="blk-title" style="font-size:19px;margin:9px 0 5px">' . ($title ?: 'کمپین سرویس بهاره') . '</div><div class="feat-d">' . e($props['subtitle'] ?? 'تا ۲۵٪ تخفیف — تا پایان ماه') . '</div></div><div style="text-align:center"><div class="stat-n" style="font-size:33px">۲۵٪</div><span class="hero-btn" style="margin-top:8px">همین حالا رزرو کنید</span></div></div></div>';
        case 'divider-icon':
            return '<div class="blk ' . $bgClass . '" style="padding:10px 16px"><div class="divider-ico"><span class="divider-line"></span><span style="font-size:17px">' . e($props['icon'] ?? '🔧') . '</span><span class="divider-line"></span></div></div>';
        case 'contact-info-bar':
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="chip-row" style="justify-content:space-between"><span class="chip">📞 <b dir="ltr">' . e($props['phone'] ?? '۰۲۱-۱۲۳۴۵۶۷۸') . '</b></span><span class="chip">🕐 شنبه-پنجشنبه ۹-۲۰</span><span class="chip">📍 تهران</span><span class="chip">💬 واتساپ</span></div></div>';

        default:
            return '<div class="blk ' . $bgClass . ' ' . $padClass . '"><div class="blk-title">📦 ' . e($block) . '</div><div class="fake-lines"><div class="fl w90"></div><div class="fl w70"></div></div></div>';
    }
}

/**
 * 🏛 رندگر سطح-بهدار — پشتیبانی بخش‌های چندستونی تودرتو (v3.0)
 */
function renderLayoutLevel(array $items): string
{
    $html = '';
    foreach ($items as $item) {
        $block = (string)($item['block'] ?? '');
        $props = (array)($item['props'] ?? []);
        if ($block === 'section-columns' || $block === 'section-split') {
            $colCount = $block === 'section-split' ? 2 : max(2, min(4, (int)($props['columns'] ?? 2)));
            $cols = $item['cols'] ?? [];
            if (!is_array($cols)) {
                $cols = [];
            }
            $inner = '';
            for ($c = 0; $c < $colCount; $c++) {
                $colItems = is_array($cols[$c] ?? null) ? $cols[$c] : [];
                $colHtml = renderLayoutLevel($colItems);
                $inner .= '<div class="pv-col">' . ($colHtml !== '' ? $colHtml : '<div class="pv-col-empty">ستون ' . ($c + 1) . ' خالی است</div>') . '</div>';
            }
            $html .= renderPreviewBlock($block, $props) . '<div class="pv-cols" style="--pv-n:' . $colCount . '">' . $inner . '</div>';
        } else {
            $html .= renderPreviewBlock($block, $props);
        }
    }
    return $html;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>پیش‌نمایش قالب</title>
<?= preview_font_html() /* 🔤 v2.14: فونت انتخابی سیستم — مثل سایت نهایی */ ?>
<style>
:root {
    --p: #1e40af; --p-light: #dbeafe; --s: #0ea5e9; --a: #f59e0b;
    --bg: #f8fafc; --card: #fff; --text: #1e293b; --muted: #64748b; --border: #e2e8f0;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--font-body, Vazirmatn, Tahoma, 'Segoe UI', sans-serif); background: var(--bg); color: var(--text); line-height: 1.95; font-size: 14px; }
/* 🔤 تیترها و عناصر تاکیدی با فونت تیتر انتخابی (مثل سایت واقعی) */
.blk-title, .hero-title, .card-t, .page-title, .topbar-blk, .notif-bar, .price-row b, .stat-n, .cta-num, .story-year, .num-n, .fake-cta, .hero-btn { font-family: var(--font-heading, Vazirmatn, Tahoma, sans-serif); }
.preview-wrap { max-width: 100%; margin: 0 auto; }

/* بلوک‌ها */
.blk { background: var(--card); padding: 26px 20px; border-bottom: 1px dashed var(--border); }
.blk:last-child { border-bottom: none; }
.blk-pad-compact { padding: 14px 16px; }
.blk-pad-roomy { padding: 44px 26px; }
.blk-pad-none { padding: 0; }
.blk-bg-surface { background: #f1f5f9; }
.blk-bg-primary { background: linear-gradient(135deg, var(--p), var(--s)); color: #fff; }
.blk-bg-primary .blk-title, .blk-bg-primary .card-t, .blk-bg-gradient .blk-title { color: #fff; }
.blk-bg-gradient { background: linear-gradient(135deg, var(--p) 0%, var(--s) 60%, var(--a) 100%); color: #fff; }
.blk-bg-dark { background: #0f172a; color: #e2e8f0; }
.blk-bg-dark .blk-title { color: #fff; }
.blk-title { font-size: 16px; font-weight: 800; margin-bottom: 16px; text-align: center; }
.blk-hidden { text-align: center; padding: 14px; color: var(--muted); font-size: 12px; background: repeating-linear-gradient(45deg, #f8fafc, #f8fafc 10px, #f1f5f9 10px, #f1f5f9 20px); }

/* هدر */
.header-blk { padding: 14px 18px; }
.header-blk .h-row { display: flex; align-items: center; gap: 14px; }
.header-blk.glass { background: rgba(255,255,255,.85); backdrop-filter: blur(9px); }
.header-blk.sticky-demo { outline: 1.5px dashed #2563eb; outline-offset: -6px; }
.fake-logo { font-size: 22px; }
.fake-nav { display: flex; gap: 16px; font-size: 13px; color: var(--muted); flex: 1; flex-wrap: wrap; }
.fake-cta { background: var(--p); color: #fff; font-size: 12px; padding: 7px 15px; border-radius: 9px; white-space: nowrap; }
.topbar-blk { display: flex; justify-content: space-between; font-size: 11.5px; color: var(--muted); padding: 7px 16px; background: #f1f5f9; flex-wrap: wrap; gap: 6px; }
.tb-row { display: flex; justify-content: space-between; font-size: 11px; color: var(--muted); flex-wrap: wrap; gap: 6px; }

/* هیرو */
.hero-blk { background: linear-gradient(135deg, var(--p), var(--s)); color: #fff; text-align: center; }
.hero-blk.split-hero { text-align: right; }
.hero-title { font-size: 21px; font-weight: 800; margin-bottom: 8px; }
.hero-sub { font-size: 13px; opacity: .88; margin-bottom: 18px; }
.hero-btns { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
.hero-blk.split-hero .hero-btns { justify-content: flex-start; }
.hero-btn { background: var(--a); border-radius: 10px; padding: 9px 22px; font-size: 13px; font-weight: 700; display: inline-block; }
.hero-btn.ghost { background: transparent; border: 1.5px solid rgba(255,255,255,.65); }
.hero-btn.full { width: 100%; text-align: center; }
.hero-img { flex: 1 1 200px; height: 130px; background: rgba(255,255,255,.14); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 34px; }
.hero-img.wide { width: 100%; flex: none; height: 150px; margin-bottom: 9px; }
.hero-split { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
.hero-split > div:first-child { flex: 1 1 240px; }
.slider-dots { letter-spacing: 5px; font-size: 11px; opacity: .8; text-align: center; margin-top: 6px; }
.play { width: 54px; height: 54px; border-radius: 50%; background: rgba(255,255,255,.2); display: flex; align-items: center; justify-content: center; font-size: 20px; margin: 12px auto; }
.count-row { display: flex; gap: 10px; justify-content: center; }
.count-box { background: rgba(255,255,255,.15); border-radius: 10px; padding: 9px 16px; font-size: 11px; }
.count-box b { display: block; font-size: 20px; }

/* متن و کارت */
.pv-text { font-size: 13.5px; line-height: 2.1; color: #334155; }
.pv-list { margin: 0 20px 0 0; font-size: 13px; line-height: 2.2; }
.fake-lines .fl { height: 10px; border-radius: 5px; background: #e2e8f0; margin: 8px 0; }
.w40 { width: 40%; } .w60 { width: 60%; } .w70 { width: 70%; } .w75 { width: 75%; } .w80 { width: 80%; } .w90 { width: 90%; } .w100 { width: 100%; }
.split { display: flex; gap: 22px; align-items: center; flex-wrap: wrap; }
.split > div:first-child { flex: 1 1 260px; }
.fake-img { flex: 1 1 180px; height: 150px; background: var(--p-light); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 36px; }
.fake-img.small { height: 90px; font-size: 26px; width: 100%; flex: none; }
.fake-img.wide { flex: none; }
.cols { display: grid; gap: 14px; }
.c2 { grid-template-columns: repeat(2, 1fr); }
.c3 { grid-template-columns: repeat(3, 1fr); }
.c4 { grid-template-columns: repeat(4, 1fr); }
.c6 { grid-template-columns: repeat(6, 1fr); }
.fake-card { background: var(--card); border: 1px solid var(--border); border-radius: 13px; padding: 16px 13px; text-align: center; min-width: 0; }
.blk-bg-primary .fake-card, .blk-bg-dark .fake-card, .blk-bg-gradient .fake-card { background: rgba(255,255,255,.1); border-color: rgba(255,255,255,.22); }
.card-ico { font-size: 25px; margin-bottom: 8px; }
.card-t { font-size: 13px; font-weight: 700; margin-bottom: 6px; }
.fake-card .fl { margin: 7px auto 0; }
.fake-ava { font-size: 30px; }

/* 🏛 ستون‌های بخش چندستونی */
.pv-cols { display: grid; grid-template-columns: repeat(var(--pv-n, 2), 1fr); gap: 14px; padding: 16px 20px 20px; background: #f8fafc; border-bottom: 1px dashed var(--border); }
.pv-col { display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.pv-col .blk { border: 1px solid var(--border); border-radius: 12px; }
.pv-col .blk:first-child:last-child { }
.pv-col-empty { border: 2px dashed #cbd5e1; border-radius: 10px; color: #94a3b8; font-size: 11.5px; text-align: center; padding: 18px 8px; }

/* فرم و آمار */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; max-width: 640px; margin: 0 auto; }
.fake-input { background: #f8fafc; border: 1.5px solid var(--border); border-radius: 9px; padding: 10px 13px; font-size: 12px; color: var(--muted); }
.news-row { display: flex; gap: 9px; max-width: 520px; margin: 0 auto; }
.stats-blk { display: flex; justify-content: space-around; flex-wrap: wrap; gap: 18px; background: linear-gradient(135deg, #0f172a, #1e3a8a); color: #fff; }
.stat { text-align: center; }
.stat-n { font-size: 26px; font-weight: 800; color: #93c5fd; }
.stat-l { font-size: 12px; opacity: .85; }
.pbar { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; font-size: 12.5px; }
.pbar span { flex: 0 0 128px; }
.track { flex: 1; height: 9px; background: #e2e8f0; border-radius: 9px; overflow: hidden; }
.fill { height: 100%; background: linear-gradient(90deg, var(--p), var(--s)); border-radius: 9px; }

/* تعامل */
.quote { background: var(--card); border: 1px solid var(--border); border-inline-start: 4px solid var(--p); border-radius: 11px; padding: 17px 19px; font-size: 13px; max-width: 560px; margin: 0 auto 10px; }
.quote-blk .quote { font-size: 16px; font-weight: 800; text-align: center; max-width: 620px; }
.acc { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 12px 16px; margin-bottom: 9px; font-size: 13px; display: flex; justify-content: space-between; align-items: center; max-width: 640px; margin-inline: auto; }
.tabs-row { display: flex; gap: 6px; justify-content: center; margin-bottom: 12px; }
.tab { font-size: 12px; padding: 6px 16px; border-radius: 8px; border: 1px solid var(--border); color: var(--muted); }
.tab.cur { background: var(--p); color: #fff; border-color: var(--p); }
.tl { max-width: 520px; margin: 0 auto; }
.tl-item { display: flex; gap: 11px; align-items: center; padding: 8px 0; opacity: .45; font-size: 12.5px; }
.tl-item.done, .tl-item.cur { opacity: 1; }
.tl-dot { width: 26px; height: 26px; border-radius: 50%; background: #e2e8f0; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #475569; flex: 0 0 26px; }
.tl-item.done .tl-dot { background: #16a34a; color: #fff; }
.tl-item.cur .tl-dot { background: #2563eb; color: #fff; }
.steps-row { display: flex; gap: 9px; align-items: center; justify-content: center; flex-wrap: wrap; }
.step { background: var(--card); border: 1px solid var(--border); border-radius: 11px; padding: 12px 16px; text-align: center; }
.step-n { width: 26px; height: 26px; border-radius: 50%; background: var(--p); color: #fff; display: flex; align-items: center; justify-content: center; margin: 0 auto 6px; font-size: 13px; }
.step-t { font-size: 11.5px; font-weight: 700; }
.step-arrow { color: #94a3b8; font-size: 16px; }
.feat-list { display: flex; flex-direction: column; gap: 11px; max-width: 640px; margin: 0 auto; }
.feat-row { display: flex; gap: 12px; align-items: flex-start; }
.feat-ico { width: 38px; height: 38px; border-radius: 10px; background: var(--p-light); display: flex; align-items: center; justify-content: center; font-size: 18px; flex: 0 0 38px; }
.feat-d { font-size: 11.5px; color: var(--muted); }
.price-table { max-width: 600px; margin: 0 auto; }
.price-row { display: flex; justify-content: space-between; padding: 11px 16px; border-bottom: 1px solid var(--border); font-size: 13px; background: var(--card); }
.price-row:first-child { border-radius: 11px 11px 0 0; }
.price-row:last-child { border-radius: 0 0 11px 11px; border-bottom: none; }
.price-row b { color: var(--p); }

/* متفرقه */
.fake-map { height: 170px; background: repeating-linear-gradient(45deg, #eef2ff, #eef2ff 12px, #e0e7ff 12px, #e0e7ff 24px); border-radius: 13px; display: flex; align-items: center; justify-content: center; color: var(--p); font-weight: 700; }
.fake-logo-s { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 13px; font-size: 21px; text-align: center; }
.cta-blk { background: linear-gradient(135deg, var(--p), var(--s)); color: #fff; text-align: center; }
.cta-num { font-size: 25px; font-weight: 800; margin-top: 6px; letter-spacing: 1px; }
.crumb { font-size: 12px; color: var(--muted); padding: 11px 18px; background: #f8fafc; }
.blk-sep { border: none; border-top: 1px solid var(--border); margin: 6px 0; }
.blk-spacer { background: repeating-linear-gradient(45deg, #f8fafc, #f8fafc 10px, #f1f5f9 10px, #f1f5f9 20px); }
.alert-demo { border-radius: 10px; padding: 11px 15px; font-size: 12.5px; font-weight: 600; }
.alert-demo.info { background: #eff6ff; color: #1d4ed8; }
.alert-demo.warning { background: #fffbeb; color: #b45309; }
.alert-demo.success { background: #f0fdf4; color: #15803d; }
.sticky-cta-demo { display: flex; justify-content: space-between; align-items: center; background: #0f172a; color: #fff; }
.footer-blk { background: #0f172a; color: #e2e8f0; }
.footer-blk .fake-nav { color: #94a3b8; }
.soc-row { display: flex; gap: 12px; justify-content: center; font-size: 11px; color: #94a3b8; margin-top: 9px; }
.crump-blk { text-align: center; font-size: 11.5px; color: var(--muted); background: #f8fafc; }

/* ════════ 🆕 v2.12 + v3.3: استایل عناصر جدید — پیش‌نمایش واقعی ════════ */
.chip-row { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
.chip { background: var(--card); border: 1px solid var(--border); border-radius: 20px; padding: 4px 13px; font-size: 11.5px; color: var(--text); }
.blk-bg-primary .chip, .blk-bg-dark .chip, .blk-bg-gradient .chip { background: rgba(255,255,255,.14); border-color: rgba(255,255,255,.25); color: #fff; }
.notif-bar { text-align: center; font-weight: 700; font-size: 13px; }
.notif-bar.info { background: #eff6ff; color: #1e40af; }
.notif-bar.success { background: #f0fdf4; color: #15803d; }
.notif-bar.warning { background: #fffbeb; color: #b45309; }
.marquee-blk { overflow: hidden; background: #0f172a; color: #fff; }
.marquee-track { white-space: nowrap; animation: pvMarquee 14s linear infinite; padding: 9px 0; font-size: 12.5px; font-weight: 600; }
@keyframes pvMarquee { from { transform: translateX(-100%); } to { transform: translateX(100%); } }
.search-wrap { display: flex; align-items: center; gap: 9px; background: #fff; border: 1.5px solid var(--border); border-radius: 13px; padding: 7px 12px; max-width: 560px; margin: 0 auto; }
.search-ico { font-size: 16px; }
.story-wrap { display: flex; flex-direction: column; gap: 15px; max-width: 620px; margin: 0 auto; }
.story-sec { display: flex; gap: 14px; align-items: center; }
.story-year { background: var(--p); color: #fff; border-radius: 10px; padding: 5px 13px; font-weight: 800; font-size: 13px; white-space: nowrap; }
.ba-wrap { display: flex; gap: 13px; align-items: center; justify-content: center; flex-wrap: wrap; }
.ba-side { flex: 1; min-width: 200px; max-width: 300px; }
.ba-tag { display: inline-block; border-radius: 8px; font-size: 11px; font-weight: 800; padding: 2.5px 11px; margin-bottom: 6px; }
.ba-tag.bad { background: #fef2f2; color: #b91c1c; }
.ba-tag.ok { background: #f0fdf4; color: #15803d; }
.ba-arrow { font-size: 26px; color: var(--p); }
.pill-announce { display: flex; align-items: center; gap: 10px; background: #eff6ff; border: 1.5px solid #bfdbfe; color: #1e40af; border-radius: 40px; padding: 11px 20px; font-weight: 700; font-size: 13px; max-width: 640px; margin: 0 auto; }
.pill-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--p); box-shadow: 0 0 0 4px rgba(37,99,235,.18); flex: 0 0 9px; }
.num-list { display: flex; flex-direction: column; gap: 12px; max-width: 640px; margin: 0 auto; }
.num-row { display: flex; gap: 13px; align-items: flex-start; }
.num-n { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, var(--p), var(--s)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; flex: 0 0 34px; }
.info-box-demo { display: flex; gap: 13px; align-items: flex-start; background: #fffbeb; border: 1.5px solid #fde68a; border-radius: 12px; padding: 14px 16px; }
.soc-proof { display: flex; gap: 15px; align-items: center; justify-content: center; flex-wrap: wrap; }
.ava-stack { display: flex; }
.ava-stack .fake-ava { border: 2px solid #fff; box-shadow: 0 2px 8px rgba(0,0,0,.14); border-radius: 50%; }
.promo-card-demo { display: flex; gap: 18px; align-items: center; justify-content: space-between; flex-wrap: wrap; background: linear-gradient(135deg, #fff7ed, #ffedd5); border: 1.5px solid #fdba74; border-radius: 14px; padding: 20px 22px; }
.divider-ico { display: flex; align-items: center; gap: 12px; }
.divider-line { flex: 1; height: 1.5px; background: linear-gradient(90deg, transparent, #cbd5e1, #cbd5e1, transparent); }
.stars { color: #f59e0b; letter-spacing: 1px; }
.badge-info { background: #dbeafe; color: #1e40af; }
.badge-warning { background: #fef3c7; color: #92400e; }
.badge { border-radius: 20px; padding: 2px 10px; font-size: 10.5px; font-weight: 700; }
/* 🎛 v3.3: تنظیمات پیشرفته */
.blk-ts-sm .blk-title { font-size: 14px; }
.blk-ts-md .blk-title { font-size: 17px; }
.blk-ts-lg .blk-title { font-size: 21px; }
.blk-ts-xl .blk-title { font-size: 26px; }
.blk-al-center { text-align: center; }
.blk-al-center .feat-list, .blk-al-center .num-list, .blk-al-center .price-table { margin: 0 auto; }
.blk-al-end { text-align: left; }
.blk-w-wide { max-width: 1200px; margin-inline: auto; }
.blk-w-boxed { max-width: 960px; margin-inline: auto; }
.blk-w-narrow { max-width: 720px; margin-inline: auto; }

@media (max-width: 640px) {
    .c2, .c3, .c4, .c6, .form-grid, .pv-cols { grid-template-columns: 1fr 1fr; }
    .c6 { grid-template-columns: repeat(3, 1fr); }
    .pv-cols { grid-template-columns: 1fr; }
    .hero-title { font-size: 17px; }
}
@media (max-width: 420px) {
    .c2, .c3, .c4, .c6, .form-grid { grid-template-columns: 1fr; }
    .fake-nav { display: none; }
}
</style>
</head>
<body>
<div class="preview-wrap">
    <?php if (empty($layout)): ?>
        <div class="blk" style="text-align:center;color:var(--muted)">چیدمانی برای پیش‌نمایش وجود ندارد.</div>
    <?php else: ?>
        <?= renderLayoutLevel($layout) ?>
    <?php endif; ?>
</div>
</body>
</html>
