/* ⚡ منطق قالب‌ساز — P2-23: از admin/template-builder.php جدا شد
 * (قبلاً ~۲۶۰۰ خط JS درون‌خطی داخل فایل PHP بود)
 * وابستگی: <script> بوت‌استرپ TB_SERVER_DATA باید قبل از این فایل لود شود
 * @package SahandBrandMaker
 */

/* 🎭 موتور قالب‌ساز v3.1 — طراحی زنده + ستون‌بندی تودرتو + بلوک‌های ترکیبی */
const BLOCK_META = TB_SERVER_DATA.blockMeta;

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
        /* 🆕 v2.31 — افکت‌های هاور بیشتر (درخواست کاربر: «تنظیمات انیمیشن زیادی بزار») */
        ['border', '🖌 روشن‌شدن قاب رنگی'],
        ['shadow', '🕯 سایه بزرگ‌تر'],
        ['slide', '↔️ سرانداختن ظریف'],
        ['rotate', '🔄 چرخش خیلی ظریف'],
        ['blur',  '🌫 شفافیت + وضوح'],
        ['pop',   '🎈 برجسته‌شدن فنری'],
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
let pageProps = TB_SERVER_DATA.pageProps;
const PAGE_DEFAULTS = {
    pageBg: 'default',       /* زمینه صفحه: default | surface | light | dark | custom | gradient | image */
    pageBgColor: '#f8fafc',  /* رنگ دلخواه وقتی pageBg=custom */
    sectionSpacing: 'default', /* فاصله عمودی داخل بخش‌ها: compact | default | roomy | airy */
    sectionGap: 'default',   /* فاصله بین بخش‌ها: tight | default | roomy */
    containerWidth: 'default', /* عرض محتوا: narrow | default | wide | full */
    radius: 'default',       /* گردی گوشه‌ها: sharp | default | round | pill */
    titleColor: '',          /* رنگ پیش‌فرض همه عنوان‌ها */
    textSize: 'default',     /* اندازه متن: sm | default | lg */
    cardShadow: 'default',   /* سایه کارت‌ها: none | soft | default | strong */
    darkPreview: 0,          /* پیش‌نمایش بوم در حالت تیره */
    /* 🆕 v2.29 — فاصله محتوای صفحه از لبه‌ها (px) */
    padTop: '',              /* فاصله از بالا */
    padBottom: '',           /* فاصله از پایین */
    padLeft: '',             /* فاصله از چپ */
    padRight: '',            /* فاصله از راست */
    /* 🆕 v2.31 — تنظیمات صفحه کامل‌تر (درخواست کاربر) */
    accentColor: '',         /* رنگ تاکیدی لینک‌ها و دکمه‌ها */
    pagePattern: 'none',     /* الگوی زمینه: none | dots | grid | stripes */
    patternColor: '#e2e8f0', /* رنگ الگو */
    titleAlign: 'start',     /* تراز پیش‌فرض عنوان‌ها */
    fontFamily: 'default',   /* خانواده فونت: default | vazir | system */
    letterSpacing: 'default',/* فاصله حروف عنوان‌ها */
    headingWeight: '800',    /* ضخامت عنوان‌ها */
    customCss: '',           /* CSS سفارشی صفحه */
    /* 🆕 v2.32 — همه تنظیمات صفحه (درخواست کاربر) */
    gradFrom: '#1e40af',     /* گرادیانت زمینه: رنگ شروع */
    gradTo: '#0ea5e9',       /* گرادیانت زمینه: رنگ پایان */
    gradAngle: '135',        /* زاویه گرادیانت (درجه) */
    bgImage: '',             /* تصویر زمینه صفحه (URL) */
    bgImageFixed: 1,         /* تصویر زمینه ثابت (پارالکس) */
    overlayColor: '#0f172a', /* رنگ پوشش روی تصویر زمینه */
    overlayOpacity: '35',    /* شفافیت پوشش (٪ — 0=بدون پوشش) */
    bodyColor: '',           /* رنگ متن بدنه کل صفحه */
    linkColor: '',           /* رنگ لینک‌های صفحه */
    lineHeight: 'default',   /* ارتفاع خط: compact | default | roomy */
    titleSize: 'md',         /* اندازه پیش‌فرض عنوان‌ها: sm | md | lg | xl */
    scrollProgress: 0,       /* نوار پیشرفت اسکرول بالای صفحه */
    backToTop: 0,            /* دکمه بازگشت به بالا (پیش‌فرض خاموش) */
    smoothScroll: 0          /* اسکرول نرم لینک‌های داخلی */
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

/* ═══════════════════════════════════════════════════════════════
 * ⏪ v2.34 — Undo / Redo قالب‌ساز (P1 #9)
 * تاریخچه = پشته snapshot از fullLayout (چیدمان + تنظیمات صفحه).
 *   • هر تغییر ساختاری (افزودن/حذف/جابجایی/کپی/خالی‌کردن) = گام جدید
 *   • ورودی‌های متنی/رنگی با «ادغام ۹۰۰ms» = یک گام (تایپ پیوسته یکجا واگرد می‌شود)
 *   • Ctrl+Z واگرد | Ctrl+Y یا Ctrl+Shift+Z بازانجام
 *   • سقف ۶۰ گام — قدیمی‌ها می‌ریزند
 * @package SahandBrandMaker
 * ═══════════════════════════════════════════════════════════════ */
const HIST_MAX = 60;
let histStack = [];      // پشته snapshotها (رشته JSON)
let histIndex = -1;      // اشاره‌گر گام فعلی
let histLastPushAt = 0;  // آخرین زمان push (برای ادغام تایپ)
let histSuppress = false;// خاموشی موقت (هنگام بازگردانی خودِ تاریخچه)

function histSnapshot() { return JSON.stringify(fullLayout()); }

/** ثبت گام جدید — coalesce=true ورودی‌های پیوسته را در یک گام ادغام می‌کند */
function pushHistory(coalesce) {
    if (histSuppress) { return; }
    const snap = histSnapshot();
    if (histIndex >= 0 && histStack[histIndex] === snap) { return; } // بدون تغییر
    const now = Date.now();
    const canMerge = coalesce === true
        && histIndex === histStack.length - 1   // گام فعلی آخرین است (redo معلق نیست)
        && histIndex >= 0
        && (now - histLastPushAt) < 900;        // تایپ/درگ پیوسته
    if (canMerge) {
        histStack[histIndex] = snap;            // جایگزینی گام جاری (تایپ = یک گام)
        histLastPushAt = now;
        updateUndoButtons();
        return;
    }
    histStack = histStack.slice(0, histIndex + 1); // حذف آینده redo
    histStack.push(snap);
    if (histStack.length > HIST_MAX) { histStack.shift(); }
    histIndex = histStack.length - 1;
    histLastPushAt = now;
    updateUndoButtons();
}

/** بازگردانی یک snapshot به بوم (بدون ثبت در تاریخچه) */
function restoreSnapshot(snap) {
    const obj = JSON.parse(snap);
    const pageNode = (obj || []).find(function (n) { return n && n.block === '_page'; });
    pageProps = (pageNode && pageNode.props) ? pageNode.props : {};
    layout = (obj || []).filter(function (n) { return !n || n.block !== '_page'; });
    selected = '';
    histSuppress = true;
    try {
        render();            // فیلد مخفی layout-json را هم بازنویسی می‌کند
        applyPageSettings();
        renderProps();
    } finally {
        histSuppress = false;
    }
    updateUndoButtons();
}

function undoLayout() {
    if (histIndex <= 0) { return; }
    histIndex--;
    restoreSnapshot(histStack[histIndex]);
    histLastPushAt = 0; // گام بعدی تایپ، گام جدید باشد نه ادغام
}

function redoLayout() {
    if (histIndex >= histStack.length - 1) { return; }
    histIndex++;
    restoreSnapshot(histStack[histIndex]);
    histLastPushAt = 0;
}

/** فعال/غیرفعال‌سازی دکمه‌ها + شمارنده گام */
function updateUndoButtons() {
    const ub = document.getElementById('btn-undo');
    const rb = document.getElementById('btn-redo');
    if (ub) {
        ub.disabled = histIndex <= 0;
        ub.title = 'واگرد آخرین تغییر (Ctrl+Z)' + (histIndex > 0 ? ' — ' + histIndex + ' گام قابل بازگشت' : '');
    }
    if (rb) {
        rb.disabled = histIndex >= histStack.length - 1;
        rb.title = 'بازانجام (Ctrl+Y)';
    }
    const badge = document.getElementById('hist-badge');
    if (badge) {
        const n = histStack.length - 1;
        badge.textContent = n > 0 ? String(n) : '';
        badge.style.display = n > 0 ? '' : 'none';
    }
}

/* ⌨️ میانبرهای کیبورد — فقط وقتی فوکوس روی ورودی/دیالوگ نیست */
document.addEventListener('keydown', function (e) {
    if (!(e.ctrlKey || e.metaKey)) { return; }
    const k = (e.key || '').toLowerCase();
    if (k !== 'z' && k !== 'y') { return; }
    const t = e.target || e.srcElement;
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) {
        /* در ورودی‌ها: Ctrl+Z رفتار بومی مرورگر (واگرد خود متن) بماند؛
           فقط Ctrl+Shift+Z / Ctrl+Y بوم را بازگرداند */
        if (!(k === 'z' && e.shiftKey)) { return; }
    }
    e.preventDefault();
    if (k === 'y' || (k === 'z' && e.shiftKey)) { redoLayout(); } else { undoLayout(); }
});
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
    /* 🆕 v2.32 — گرادیانت زمینه */
    else if (bg === 'gradient') {
        const gf = /^#[0-9a-fA-F]{3,8}$/.test(String(pageProp('gradFrom'))) ? pageProp('gradFrom') : '#1e40af';
        const gt = /^#[0-9a-fA-F]{3,8}$/.test(String(pageProp('gradTo'))) ? pageProp('gradTo') : '#0ea5e9';
        const ang = Math.max(0, Math.min(360, parseInt(pageProp('gradAngle'), 10) || 135));
        bgCss = 'linear-gradient(' + ang + 'deg,' + gf + ',' + gt + ')';
    }
    /* 🆕 v2.32 — تصویر زمینه + پوشش رنگ */
    else if (bg === 'image' && /^https?:\/\//i.test(String(pageProp('bgImage')))) {
        const oc = /^#[0-9a-fA-F]{6}$/.test(String(pageProp('overlayColor'))) ? pageProp('overlayColor') : '#0f172a';
        const op = Math.max(0, Math.min(100, parseInt(pageProp('overlayOpacity'), 10) || 0)) / 100;
        const r = parseInt(oc.slice(1, 3), 16), g2 = parseInt(oc.slice(3, 5), 16), b2 = parseInt(oc.slice(5, 7), 16);
        const ov = 'rgba(' + r + ',' + g2 + ',' + b2 + ',' + op + ')';
        bgCss = 'linear-gradient(' + ov + ',' + ov + '),url(\'' + pageProp('bgImage').replace(/[\''()\\]/g, '') + '\')';
        stage.style.backgroundSize = 'cover';
        stage.style.backgroundPosition = 'center';
        stage.style.backgroundAttachment = pageProp('bgImageFixed') == 1 ? 'fixed' : '';
    } else {
        stage.style.backgroundSize = ''; stage.style.backgroundPosition = ''; stage.style.backgroundAttachment = '';
    }
    const spacing = { compact: '30px', default: '54px', roomy: '74px', airy: '96px' }[pageProp('sectionSpacing')] || '54px';
    const gap = { tight: '14px', default: '26px', roomy: '44px' }[pageProp('sectionGap')] || '26px';
    const width = { narrow: '860px', default: '1080px', wide: '1240px', full: '100%' }[pageProp('containerWidth')] || '1080px';
    const radius = { sharp: '2px', default: '14px', round: '22px', pill: '34px' }[pageProp('radius')] || '14px';
    const tsize = { sm: '13px', default: '14.5px', lg: '16px' }[pageProp('textSize')] || '14.5px';
    const shadow = { none: 'none', soft: '0 2px 8px rgba(2,8,23,.05)', default: '0 5px 18px rgba(2,8,23,.08)', strong: '0 12px 32px rgba(2,8,23,.16)' }[pageProp('cardShadow')] || '0 5px 18px rgba(2,8,23,.08)';
    const tc = pageProp('titleColor');
    /* 🆕 v2.29 — فاصله‌های چهارجهته محتوا (px خالی = خودکار) */
    const px = v => { const n = parseInt(v, 10); return (isNaN(n) || n < 0 || n > 400) ? '' : (n + 'px'); };
    const mT = px(pageProp('padTop')), mB = px(pageProp('padBottom'));
    const mL = px(pageProp('padLeft')), mR = px(pageProp('padRight'));
    const padCss = (mT || mB || mL || mR)
        ? ';padding-top:' + (mT || '0') + ';padding-bottom:' + (mB || '0') + ';padding-inline-start:' + (mR || '0') + ';padding-inline-end:' + (mL || '0')
        : '';
    /* 🆕 v2.31 — تنظیمات صفحه جدید: رنگ تاکیدی + الگو + فونت + ضخامت + فاصله حروف + CSS دلخواه */
    const accent = /^#[0-9a-fA-F]{3,8}$/.test(String(pageProp('accentColor'))) ? pageProp('accentColor') : '#1e40af';
    const fontFam = { default: '', vazir: "'Vazir',Vazirmatn,Tahoma,sans-serif", system: 'Tahoma,Arial,sans-serif' }[pageProp('fontFamily')] || '';
    const hWeight = ['700', '800', '900'].includes(String(pageProp('headingWeight'))) ? pageProp('headingWeight') : '800';
    const lSpace = { tight: '-.5px', default: '0', wide: '1.2px' }[pageProp('letterSpacing')] || '0';
    const patCls = ['dots', 'grid', 'stripes'].includes(String(pageProp('pagePattern'))) ? ' pv-pat-' + pageProp('pagePattern') : '';
    const patColor = /^#[0-9a-fA-F]{3,8}$/.test(String(pageProp('patternColor'))) ? pageProp('patternColor') : '#e2e8f0';
    /* حذف کلاس الگوی قبلی */
    stage.classList.remove('pv-pat-dots', 'pv-pat-grid', 'pv-pat-stripes');
    if (patCls) { stage.classList.add(patCls.trim()); }
    /* CSS دلخواه صفحه — تگ style اختصاصی بوم */
    let customStyleEl = document.getElementById('pv-page-custom-css');
    const customCss = String(pageProp('customCss') || '').slice(0, 8000);
    if (customCss.trim() !== '') {
        if (!customStyleEl) { customStyleEl = document.createElement('style'); customStyleEl.id = 'pv-page-custom-css'; document.head.appendChild(customStyleEl); }
        customStyleEl.textContent = '#canvas-blocks{' + customCss.replace(/#canvas-blocks\s*\{?/g, '') + '}';
    } else if (customStyleEl) { customStyleEl.textContent = ''; }
    /* 🆕 v2.32 — تایپوگرافی و جلوه‌های جدید */
    const bodyC = /^#[0-9a-fA-F]{3,8}$/.test(String(pageProp('bodyColor'))) ? ';--pg-body:' + pageProp('bodyColor') : '';
    const linkC = /^#[0-9a-fA-F]{3,8}$/.test(String(pageProp('linkColor'))) ? ';--pg-link:' + pageProp('linkColor') : '';
    const lineH = { compact: '1.6', default: '', roomy: '2.1' }[pageProp('lineHeight')];
    const lhVar = lineH ? ';--pg-lh:' + lineH : '';
    const tsz = { sm: '15px', md: '', lg: '21px', xl: '26px' }[pageProp('titleSize')];
    const tsVar = tsz ? ';--pg-title-size:' + tsz : '';
    stage.style.cssText = '--pg-accent:' + accent + ';--pg-hw:' + hWeight + ';--pg-ls:' + lSpace +
        (fontFam ? ';--pg-font:' + fontFam : '') + (patCls ? ';--pg-pat:' + patColor : '') +
        ';--pg-section-pad:' + spacing + ';--pg-gap:' + gap + ';--pg-width:' + width +
        ';--pg-radius:' + radius + ';--pg-text:' + tsize + ';--pg-shadow:' + shadow +
        ';--pg-title:' + (tc !== '' ? tc : 'inherit') + bodyC + linkC + lhVar + tsVar +
        (mT ? ';--pg-mt:' + mT : '') + (mB ? ';--pg-mb:' + mB : '') + (mR ? ';--pg-mr:' + mR : '') + (mL ? ';--pg-ml:' + mL : '') +
        ';max-width:100%' + padCss +
        (bgCss !== '' ? ';background:' + bgCss + ';border-radius:12px' : '');
}
/* پنل تنظیمات صفحه — در ستون ویژگی‌ها */
function renderPageProps() {
    selected = null;
    document.querySelectorAll('.tb-block').forEach(b => b.classList.remove('selected'));
    const panel = document.getElementById('props-content');
    const opt = (key, opts) => opts.map(([v, l]) => '<option value="' + v + '" ' + (String(pageProp(key)) === String(v) ? 'selected' : '') + '>' + l + '</option>').join('');
    let html = '<div style="font-weight:800;margin-bottom:12px;font-size:13.5px">⚙️ تنظیمات صفحه</div>' +
        '<div class="hint" style="font-size:10.5px;margin-bottom:11px;line-height:1.8">این تنظیمات روی «کل صفحه» اعمال می‌شوند — زمینه، فاصله بخش‌ها، عرض محتوا و ظاهر عمومی. روی هر بلوک که کلیک کنید به تنظیمات همان بلوک برمی‌گردید.</div>' +
        '<div class="form-group"><label>🎨 زمینه صفحه</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'pageBg\',this.value)">' + opt('pageBg', [['default', 'پیش‌فرض (سفید)'], ['surface', 'کمرنگ خاکستری'], ['light', 'روشن'], ['dark', 'تیره'], ['custom', 'رنگ دلخواه'], ['gradient', 'گرادیانت 🆕'], ['image', 'تصویر زمینه 🆕']]) + '</select></div>' +
        '<div class="form-group" id="pg-bg-color-box" style="' + (pageProp('pageBg') === 'custom' ? '' : 'display:none') + '"><label>رنگ دلخواه زمینه</label><div style="display:flex;gap:7px;align-items:center"><input type="color" class="form-control" style="width:48px;height:33px;padding:2px;cursor:pointer" value="' + pageProp('pageBgColor') + '" oninput="setPageProp(\'pageBgColor\',this.value)"><code style="font-size:10.5px;direction:ltr">' + pageProp('pageBgColor') + '</code></div></div>' +
        '<div class="form-group" id="pg-grad-box" style="' + (pageProp('pageBg') === 'gradient' ? '' : 'display:none') + '"><label>🌈 گرادیانت زمینه</label><div style="display:flex;gap:7px;align-items:center"><input type="color" class="form-control" style="width:44px;height:31px;padding:2px;cursor:pointer" value="' + pageProp('gradFrom') + '" oninput="setPageProp(\'gradFrom\',this.value)" title="رنگ شروع"><span style="font-size:11px;color:var(--text-light)">تا</span><input type="color" class="form-control" style="width:44px;height:31px;padding:2px;cursor:pointer" value="' + pageProp('gradTo') + '" oninput="setPageProp(\'gradTo\',this.value)" title="رنگ پایان"><input type="number" class="form-control" style="width:64px;font-size:11px" min="0" max="360" value="' + pageProp('gradAngle') + '" onchange="setPageProp(\'gradAngle\',this.value)" title="زاویه (درجه)"></div><div class="hint" style="margin-top:4px">دو رنگ + زاویه گرادیانت کل صفحه.</div></div>' +
        '<div class="form-group" id="pg-img-box" style="' + (pageProp('pageBg') === 'image' ? '' : 'display:none') + '"><label>🖼 آدرس تصویر زمینه</label><input type="text" class="form-control" style="font-size:11px;direction:ltr;text-align:left" value="' + esc(pageProp('bgImage')) + '" oninput="setPageProp(\'bgImage\',this.value)" placeholder="https://example.com/bg.jpg"><label class="form-check" style="margin:8px 0;font-size:11.5px"><input type="checkbox" ' + (pageProp('bgImageFixed') == 1 ? 'checked' : '') + ' onchange="setPageProp(\'bgImageFixed\',this.checked?1:0)"> تصویر ثابت (پارالکس هنگام اسکرول)</label><label>🎨 پوشش رنگ روی تصویر (برای خوانایی متن)</label><div style="display:flex;gap:7px;align-items:center"><input type="color" class="form-control" style="width:44px;height:31px;padding:2px;cursor:pointer" value="' + pageProp('overlayColor') + '" oninput="setPageProp(\'overlayColor\',this.value)"><input type="range" min="0" max="90" value="' + pageProp('overlayOpacity') + '" oninput="setPageProp(\'overlayOpacity\',this.value)" style="flex:1" title="شفافیت پوشش ٪"><code style="font-size:10.5px">' + pageProp('overlayOpacity') + '٪</code></div></div>' +
        '<div class="form-group"><label>↕️ فاصله داخلی بخش‌ها</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'sectionSpacing\',this.value)">' + opt('sectionSpacing', [['compact', 'فشرده (۳۰px)'], ['default', 'پیش‌فرض (۵۴px)'], ['roomy', 'جادار (۷۴px)'], ['airy', 'خیلی باز (۹۶px)']]) + '</select></div>' +
        '<div class="form-group"><label>📏 فاصله بین بخش‌ها</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'sectionGap\',this.value)">' + opt('sectionGap', [['tight', 'نزدیک (۱۴px)'], ['default', 'پیش‌فرض (۲۶px)'], ['roomy', 'باز (۴۴px)']]) + '</select></div>' +
        '<div class="form-group"><label>📐 عرض محتوای صفحه</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'containerWidth\',this.value)">' + opt('containerWidth', [['narrow', 'باریک (۸۶۰px)'], ['default', 'پیش‌فرض (۱۰۸۰px)'], ['wide', 'عریض (۱۲۴۰px)'], ['full', 'تمام‌عرض']]) + '</select></div>' +
        '<div class="form-group"><label>⬜ گردی گوشه‌ها</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'radius\',this.value)">' + opt('radius', [['sharp', 'تیز (۲px)'], ['default', 'پیش‌فرض (۱۴px)'], ['round', 'گرد (۲۲px)'], ['pill', 'خیلی گرد (۳۴px)']]) + '</select></div>' +
        '<div class="form-group"><label>🎨 رنگ پیش‌فرض عنوان‌ها</label><div style="display:flex;gap:7px;align-items:center"><input type="color" class="form-control" style="width:48px;height:33px;padding:2px;cursor:pointer" value="' + (pageProp('titleColor') || '#1e40af') + '" oninput="setPageProp(\'titleColor\',this.value)"><button type="button" class="btn btn-outline btn-sm" onclick="setPageProp(\'titleColor\',\'\');renderPageProps()" title="حذف رنگ">✕ پیش‌فرض</button></div></div>' +
        '<div class="form-group"><label>🔤 اندازه متن</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'textSize\',this.value)">' + opt('textSize', [['sm', 'کوچک'], ['default', 'پیش‌فرض'], ['lg', 'بزرگ']]) + '</select></div>' +
        '<div class="form-group"><label>🌫 سایه کارت‌ها</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'cardShadow\',this.value)">' + opt('cardShadow', [['none', 'بدون سایه'], ['soft', 'ملایم'], ['default', 'پیش‌فرض'], ['strong', 'قوی']]) + '</select></div>' +
        '<label class="form-check" style="font-size:12px"><input type="checkbox" ' + (pageProp('darkPreview') == 1 ? 'checked' : '') + ' onchange="setPageProp(\'darkPreview\',this.checked?1:0)"> 🌙 پیش‌نمایش بوم در حالت تیره</label>' +
        '<hr style="border:none;border-top:1px dashed var(--border);margin:12px 0">' +
        '<div style="font-size:11px;font-weight:800;color:var(--primary);margin:0 0 7px">✍️ تایپوگرافی (🆕 v2.32)</div>' +
        '<div class="form-group"><label>✍️ رنگ متن بدنه صفحه</label><div style="display:flex;gap:7px;align-items:center"><input type="color" class="form-control" style="width:48px;height:33px;padding:2px;cursor:pointer" value="' + (pageProp('bodyColor') || '#334155') + '" oninput="setPageProp(\'bodyColor\',this.value)"><button type="button" class="btn btn-outline btn-sm" onclick="setPageProp(\'bodyColor\',\'\')">✕ پیش‌فرض</button></div></div>' +
        '<div class="form-group"><label>🔗 رنگ لینک‌های صفحه</label><div style="display:flex;gap:7px;align-items:center"><input type="color" class="form-control" style="width:48px;height:33px;padding:2px;cursor:pointer" value="' + (pageProp('linkColor') || '#1e40af') + '" oninput="setPageProp(\'linkColor\',this.value)"><button type="button" class="btn btn-outline btn-sm" onclick="setPageProp(\'linkColor\',\'\')">✕ پیش‌فرض</button></div></div>' +
        '<div class="form-group"><label>↕️ ارتفاع خط متن</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'lineHeight\',this.value)">' + opt('lineHeight', [['compact', 'فشرده (۱.۶)'], ['default', 'پیش‌فرض'], ['roomy', 'جادار (۲.۱)']]) + '</select></div>' +
        '<div class="form-group"><label>🔠 اندازه پیش‌فرض عنوان‌ها</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'titleSize\',this.value)">' + opt('titleSize', [['sm', 'کوچک'], ['md', 'پیش‌فرض'], ['lg', 'بزرگ'], ['xl', 'خیلی بزرگ']]) + '</select></div>' +
        '<hr style="border:none;border-top:1px dashed var(--border);margin:12px 0">' +
        '<div style="font-size:11px;font-weight:800;color:var(--primary);margin:0 0 7px">✨ جلوه‌های صفحه (🆕 v2.32)</div>' +
        '<label class="form-check" style="font-size:12px;margin-bottom:5px"><input type="checkbox" ' + (pageProp('scrollProgress') == 1 ? 'checked' : '') + ' onchange="setPageProp(\'scrollProgress\',this.checked?1:0)"> 📊 نوار پیشرفت اسکرول (بالای صفحه)</label>' +
        '<label class="form-check" style="font-size:12px;margin-bottom:5px"><input type="checkbox" ' + (pageProp('backToTop') == 1 ? 'checked' : '') + ' onchange="setPageProp(\'backToTop\',this.checked?1:0)"> ⬆️ دکمه بازگشت به بالا</label>' +
        '<label class="form-check" style="font-size:12px"><input type="checkbox" ' + (pageProp('smoothScroll') == 1 ? 'checked' : '') + ' onchange="setPageProp(\'smoothScroll\',this.checked?1:0)"> 🌊 اسکرول نرم لینک‌های داخلی</label>' +
        '<hr style="border:none;border-top:1px dashed var(--border);margin:12px 0">' +
        '<div style="font-size:11px;font-weight:800;color:var(--primary);margin:0 0 7px">🎯 ظاهر پیشرفته (🆕 v2.31)</div>' +
        '<div class="form-group"><label>🎨 رنگ تاکیدی (لینک‌ها و دکمه‌ها)</label><div style="display:flex;gap:7px;align-items:center"><input type="color" class="form-control" style="width:48px;height:33px;padding:2px;cursor:pointer" value="' + (pageProp('accentColor') || '#1e40af') + '" oninput="setPageProp(\'accentColor\',this.value)"><button type="button" class="btn btn-outline btn-sm" onclick="setPageProp(\'accentColor\',\'\');renderPageProps()">✕ پیش‌فرض</button></div></div>' +
        '<div class="form-group"><label>🔲 الگوی زمینه صفحه</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'pagePattern\',this.value)">' + opt('pagePattern', [['none', 'بدون الگو'], ['dots', 'نقطه‌چین'], ['grid', 'شطرنجی'], ['stripes', 'خطوط مورب ظریف']]) + '</select></div>' +
        '<div class="form-group" id="pg-pattern-color-box" style="' + (pageProp('pagePattern') !== 'none' ? '' : 'display:none') + '"><label>رنگ الگو</label><input type="color" class="form-control" style="width:48px;height:33px;padding:2px;cursor:pointer" value="' + pageProp('patternColor') + '" oninput="setPageProp(\'patternColor\',this.value)"></div>' +
        '<div class="form-group"><label>🔤 خانواده فونت</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'fontFamily\',this.value)">' + opt('fontFamily', [['default', 'پیش‌فرض (وزیرمتن)'], ['vazir', 'وزیر (Vazir)'], ['system', 'فونت سیستم (Tahoma)']]) + '</select></div>' +
        '<div class="form-group"><label>🔤 ضخامت عنوان‌ها</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'headingWeight\',this.value)">' + opt('headingWeight', [['700', 'معمولی بولد'], ['800', 'کلفت (پیش‌فرض)'], ['900', 'سیاه (Extra)']]) + '</select></div>' +
        '<div class="form-group"><label>↔️ فاصله حروف عنوان‌ها</label><select class="form-control" style="font-size:12px" onchange="setPageProp(\'letterSpacing\',this.value)">' + opt('letterSpacing', [['tight', 'فشرده'], ['default', 'پیش‌فرض'], ['wide', 'باز (شیک)']]) + '</select></div>' +
        '<div class="form-group"><label>📝 CSS سفارشی صفحه (پیشرفته)</label><textarea class="form-control" rows="4" style="font-size:11px;direction:ltr;text-align:left;font-family:monospace" oninput="setPageProp(\'customCss\',this.value)" placeholder="/* مثال */ .blk-title{color:#7c3aed!important}">' + esc(pageProp('customCss')) + '</textarea><div class="hint" style="margin-top:4px">کد CSS دلخواه — فقط روی همین صفحه اعمال می‌شود.</div></div>' +
        '<hr style="border:none;border-top:1px dashed var(--border);margin:12px 0">' +
        '<div style="font-size:11px;font-weight:800;color:var(--primary);margin:0 0 7px">📐 فاصله محتوای صفحه از لبه‌ها (🆕)</div>' +
        '<div class="form-group"><label>⬆️ فاصله از بالا (px — خالی = خودکار)</label><input type="number" class="form-control" style="font-size:12px" min="0" max="400" value="' + pageProp('padTop') + '" onchange="setPageProp(\'padTop\',this.value)"></div>' +
        '<div class="form-group"><label>⬇️ فاصله از پایین (px — خالی = خودکار)</label><input type="number" class="form-control" style="font-size:12px" min="0" max="400" value="' + pageProp('padBottom') + '" onchange="setPageProp(\'padBottom\',this.value)"></div>' +
        '<div class="form-group"><label>↔️ فاصله از چپ (px — خالی = خودکار)</label><input type="number" class="form-control" style="font-size:12px" min="0" max="400" value="' + pageProp('padLeft') + '" onchange="setPageProp(\'padLeft\',this.value)"></div>' +
        '<div class="form-group"><label>↔️ فاصله از راست (px — خالی = خودکار)</label><input type="number" class="form-control" style="font-size:12px" min="0" max="400" value="' + pageProp('padRight') + '" onchange="setPageProp(\'padRight\',this.value)"></div>' +
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
const SAVED_BLOCKS = TB_SERVER_DATA.savedBlocks;

/* ⭐ v2.26: عناصر شخصی استخراج‌شده از سایت‌ها — id → {name, html, css}
   در بوم به‌صورت iframe ایزوله (استایل سایت مبدأ حفظ می‌شود) رندر می‌شوند */
const PERSONAL_ELEMENTS = TB_SERVER_DATA.personalElements;

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
    /* 🆕 v2.32 — تکمیل تنظیمات عناصر (درخواست کاربر) */
    const txtC = String(props.textColor || '').trim();
    if (/^#[0-9a-fA-F]{3,8}$/.test(txtC)) { s += `--blk-txt:${txtC};`; }
    if ((props.background || '') === 'custom') {
        const bgc = /^#[0-9a-fA-F]{3,8}$/.test(String(props.bgColor || '')) ? String(props.bgColor).trim() : '';
        if (bgc) { s += `--blk-bg:${bgc};`; }
    }
    const pxv = (v) => { const n = parseInt(v, 10); return (isNaN(n) || n < -80 || n > 300) ? '' : n + 'px'; };
    const mt = pxv(props.mt), mb = pxv(props.mb);
    if (mt) { s += `--blk-mt:${mt};`; }
    if (mb) { s += `--blk-mb:${mb};`; }
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
   (fallbackهای آرایه‌ای قدیمی هم نرمال می‌شوند)
   🆕 v2.27 — ماده‌سازی آیتم‌ها (رفع «برای چک‌لیست نمیشه متن‌ها رو عوض کرد یا
   گزینه جدید اضافه کرد»): قبلاً وقتی props.items خالی بود، بوم آیتم‌های
   «پیش‌فرض قالبی» را نشان می‌داد اما پنل ویژگی‌ها «آیتم‌ها (۰)» + دکمه افزودن
   نشان می‌داد → آیتم‌های روی صفحه اصلاً قابل ویرایش نبودند! اکنون اولین رندر
   آیتم‌های fallback را داخل props.items می‌نویسد → همان‌ها در پنل ویژگی‌ها
   قابل ویرایش/حذف/جابجایی‌اند و «افزودن آیتم جدید» هم به همان لیست اضافه می‌کند. */
function listItems(props, fallback) {
    /* 🎨 v2.31 — color آیتم هم حفظ می‌شود (نوارهای چندرنگ) */
    const norm = arr => (Array.isArray(arr) ? arr : []).map(it => Array.isArray(it) ? { icon: it[0] || '', text: it[1] || '', desc: it[2] || '', link: it[3] || '', color: it[4] || '' } : Object.assign({ color: '' }, (it || {})));
    /* 🆕 v2.27 — نرمال‌سازی «قبل از» فیلتر: آیتم‌های آرایه‌ای قدیمی حذف نمی‌شوند */
    let its = norm(props.items).filter(it => it && String(it.text || '').trim() !== '');
    if (!its.length) {
        its = norm(fallback);
        if (its.length && props && typeof props === 'object') { props.items = its.map(it => ({ ...it })); }
    }
    return its;
}

/* 📊 v2.25: آمار از آیتم‌های ویرایشگر — icon=عدد، text=برچسب (خروجی جفت‌آرایه)
   🆕 v2.27: ماده‌سازی مثل listItems — آیتم‌های fallback قابل ویرایش می‌شوند */
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

/* 📊 v2.17: آمار از آیتم‌های ویرایشگر (IT) — icon=عدد/ایموجی، text=برچسب
   🆕 v2.27: ماده‌سازی fallback داخل props.items → قابل ویرایش در پنل */
function statItemsHtml(props) {
    const items = listItems(props, [{ icon: '۱۲+', text: 'سال تجربه' }, { icon: '۵۰هزار+', text: 'تعمیر موفق' }, { icon: '۹۸٪', text: 'رضایت مشتری' }]);
    return items.slice(0, 6).map(i => `<div class="stat"><div class="stat-n">${esc(String(i.icon || '۰').trim() || '۰')}</div><div class="stat-l">${esc(String(i.text || '').trim() || 'آمار')}</div></div>`).join('');
}
function statStripHtml(props) {
    const items = listItems(props, [{ icon: '۱۲+', text: 'سال تجربه' }, { icon: '۵۰k', text: 'تعمیر موفق' }, { icon: '۹۸٪', text: 'رضایت' }, { icon: '۲h', text: 'اعزام' }]);
    return items.slice(0, 8).map(i => `<span class="ss-item"><b>${esc(String(i.icon || '').trim() || '۰')}</b> ${esc(String(i.text || '').trim() || 'آمار')}</span>`).join('<span class="ss-sep"></span>');
}
/* 🎞 v2.29: اسلایدهای اسلایدر چندمقداری — هر تعداد آیتم (تصویر/متن/کارت)
   هر آیتم: text=عنوان، desc=آدرس تصویر یا متن، link=لینک اسلاید */
function sliderSlidesHtml(props, defType) {
    const type = props.slideType || defType || 'image';
    const items = listItems(props, []).filter(it => String(it.text || it.desc || '').trim() !== '');
    if (!items.length) {
        return `<div style="display:flex;flex-direction:column;gap:9px">${fakeImgHtml(props, '🖼️', 'min-height:150px').replace('fake-img', 'fake-img wide')}<div class="feat-d" style="text-align:center">از پنل ویژگی‌ها هر تعداد اسلاید می‌خواهید اضافه کنید</div></div>`;
    }
    const slideHtml = items.slice(0, 3).map((it, i) => {
        if (type === 'text') {
            return `<div class="fake-card" style="${i ? 'opacity:.75' : ''}"><div class="hero-title" style="font-size:16px">${esc(it.text || '')}</div>${it.desc ? `<div class="feat-d">${esc(it.desc)}</div>` : ''}${it.link ? `<div class="feat-d" style="color:#2563eb;font-size:10px">🔗 ${esc(it.link)}</div>` : ''}</div>`;
        }
        if (type === 'card' || type === 'article' || type === 'brand') {
            const ico = type === 'article' ? '📰' : (type === 'brand' ? '🏷️' : (it.icon || '🃏'));
            return `<div class="fake-card" style="${i ? 'opacity:.75' : ''}"><div class="card-ico">${esc(ico)}</div><div class="card-t">${esc(it.text || (type === 'article' ? 'عنوان مقاله' : 'عنوان'))}</div>${it.desc ? `<div class="feat-d">${esc(it.desc)}</div>` : ''}${it.link ? `<div class="feat-d" style="color:#2563eb;font-size:10px">🔗 ${esc(it.link)}</div>` : ''}</div>`;
        }
        /* image */
        const imgStyle = 'min-height:' + (items.length > 2 ? 110 : 150) + 'px';
        const url = String(it.desc || '').trim();
        const inner = url && /^(https?:\/\/|\/|uploads\/)/.test(url)
            ? `<img src="${esc(url)}" alt="" style="width:100%;height:100%;object-fit:cover">`
            : esc(it.icon || '🖼️');
        return `<div style="position:relative">${i ? `<div class="fake-img" style="${imgStyle};opacity:.8">${inner}</div>` : `<div class="fake-img" style="${imgStyle}">${inner}</div>`}${it.text ? `<div class="feat-d" style="text-align:center;margin-top:4px;font-weight:700">${esc(it.text)}</div>` : ''}${it.link ? `<span style="position:absolute;top:6px;left:6px;background:#2563eb;color:#fff;border-radius:8px;padding:2px 8px;font-size:9.5px">🔗 لینک‌دار</span>` : ''}</div>`;
    }).join('');
    return `<div style="display:flex;flex-direction:column;gap:9px">${slideHtml}</div><div class="slider-dots">${items.map((_, i) => i === 0 ? '●' : '○').join(' ')} <span style="font-size:9.5px;letter-spacing:0">(اسلاید ${faDigJS(1)} از ${faDigJS(items.length)})</span></div>`;
}


/* ═══════════════════════════════════════════════════════════════
 * 🧬 v2.29 — رندرگر عمومی عناصر جدید (۶۴ عنصر با renderType)
 * renderType در defaults تعریف می‌شود و makeBlocks آن را داخل props
 * کپی می‌کند → در بوم، پیش‌نمایش و سایت برند بدون کد اختصاصی رندر می‌شود.
 * cards | features | stats | chips | banner | steps | price | quote | divider
 * ═══════════════════════════════════════════════════════════════ */
function genericBlockHtml(block, props) {
    const t = props.title || '';
    const type = props.renderType || 'cards';
    const its = listItems(props, []);
    const cols = gridCols(props, 3);
    const TITLE = t ? `<div class="blk-title">${esc(t)}</div>` : '';
    const sub = props.subtitle ? `<div class="feat-d" style="text-align:center;max-width:560px;margin:0 auto 10px">${esc(props.subtitle)}</div>` : '';
    const btn = props.btnText ? `<div class="hero-btns" style="justify-content:center;margin-top:10px"><span class="hero-btn">${esc(props.btnText)}</span></div>` : '';
    if (type === 'features') {
        return `${TITLE}<div class="feat-list">${its.map(it => `<div class="feat-row"><span class="feat-ico">${esc(it.icon || '✨')}</span><div><b>${esc(it.text || '')}</b>${it.desc ? `<div class="feat-d">${esc(it.desc)}</div>` : ''}</div></div>`).join('')}</div>`;
    }
    if (type === 'stats') {
        return `${TITLE}<div class="cols c${Math.min(6, Math.max(2, its.length || 3))}" style="gap:14px">${its.map(i => `<div class="stat"><div class="stat-n">${esc(String(i.icon || '۰').trim() || '۰')}</div><div class="stat-l">${esc(String(i.text || '').trim() || 'آمار')}</div></div>`).join('')}</div>`;
    }
    if (type === 'chips') {
        return `${TITLE}<div class="chip-row">${its.map(i => `<span class="chip">${i.icon ? esc(i.icon) + ' ' : ''}${esc(i.text || '')}${i.desc ? ' — ' + esc(i.desc) : ''}</span>`).join('')}</div>`;
    }
    if (type === 'banner') {
        return `<div class="hero-title" style="font-size:22px">${esc(t || 'بنر ویژه')}</div>${sub}${its.length ? `<div class="cols c${Math.min(4, its.length)}" style="margin-top:11px">${its.map(i => `<div class="fake-card"><div class="card-ico">${esc(i.icon || '✨')}</div><div class="card-t">${esc(i.text || '')}</div>${i.desc ? `<div class="feat-d">${esc(i.desc)}</div>` : ''}</div>`).join('')}</div>` : ''}${btn}`;
    }
    if (type === 'steps') {
        return `${TITLE}<div class="steps-row" style="flex-wrap:wrap">${its.map((it, i) => `${i > 0 ? '<div class="step-arrow">←</div>' : ''}<div class="step"><span class="step-n">${esc(it.icon || faDigJS(String(i + 1)))}</span><div class="step-t">${esc(it.text || '')}${it.desc ? `<div class="feat-d">${esc(it.desc)}</div>` : ''}</div></div>`).join('')}</div>`;
    }
    if (type === 'price') {
        return `${TITLE}<div class="price-table">${its.map(it => `<div class="price-row"><span>${esc(it.text || '')}</span><b>${esc(it.desc || '')}</b></div>`).join('')}</div>`;
    }
    if (type === 'quote') {
        return `${TITLE}<div class="quote">«${esc(props.text || it0text(its))}»</div>`;
    }
    if (type === 'divider') {
        return `<div style="text-align:center;font-size:22px;letter-spacing:3px;opacity:.5">${esc(its.length ? its[0].text : '〰️〰️〰️')}</div>`;
    }
    /* 🆕 v2.31 — progress: نوارهای پیشرفت با رنگ هر آیتم */
    if (type === 'progress') {
        const striped = props.striped ? ' pb-stripes' : '';
        return `${TITLE}${its.map(it => {
            const p = Math.max(3, Math.min(100, parseInt(String(it.desc || it.icon || '80').replace(/[^0-9]/g, ''), 10) || 80));
            const c = /^#[0-9a-fA-F]{3,8}$/.test(String(it.color || '')) ? it.color : (/^#[0-9a-fA-F]{3,8}$/.test(String(props.barColor || '')) ? props.barColor : '#1e40af');
            return `<div class="pbar"><span>${esc(it.text || '')}</span><div class="track"><div class="fill${striped}" style="width:${p}%;background:${esc(c)}"></div></div></div>`;
        }).join('')}`;
    }
    /* 🆕 v2.31 — wheels: گردونه‌های درصدی با رنگ هر آیتم */
    if (type === 'wheels') {
        const semi = props.semi ? '半' : '';
        return `${TITLE}<div class="cols c${Math.min(4, Math.max(2, its.length || 3))}" style="gap:16px">${its.map(it => {
            const num = String(it.icon || it.desc || '80').replace(/[^0-9]/g, '') || '80';
            const deg = Math.round(parseInt(num, 10) / 100 * 360);
            const c = /^#[0-9a-fA-F]{3,8}$/.test(String(it.color || '')) ? it.color : (/^#[0-9a-fA-F]{3,8}$/.test(String(props.barColor || '')) ? props.barColor : '#2563eb');
            return `<div style="text-align:center"><div style="width:92px;height:92px;margin:0 auto;border-radius:50%;background:conic-gradient(${esc(c)} ${deg}deg,#e2e8f0 ${deg}deg);display:flex;align-items:center;justify-content:center"><div style="width:70px;height:70px;background:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:17px;color:${esc(c)}">${esc(faDigJS(num) + '٪')}</div></div><div class="feat-d" style="margin-top:8px;font-weight:700">${esc(it.text || '')}</div></div>`;
        }).join('')}</div>`;
    }
    /* 🆕 v2.31 — gauge: حلقه بزرگ تک‌مقدار */
    if (type === 'gauge') {
        const it = its[0] || { text: 'شاخص', desc: '80', color: '#16a34a' };
        const num = String(it.desc || it.icon || '80').replace(/[^0-9]/g, '') || '80';
        const deg = Math.round(parseInt(num, 10) / 100 * 360);
        const c = /^#[0-9a-fA-F]{3,8}$/.test(String(it.color || '')) ? it.color : '#16a34a';
        return `${TITLE}<div style="display:flex;justify-content:center"><div><div style="width:170px;height:170px;border-radius:50%;background:conic-gradient(${esc(c)} ${deg}deg,#e2e8f0 ${deg}deg);display:flex;align-items:center;justify-content:center"><div style="width:132px;height:132px;background:#fff;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center"><b style="font-size:34px;color:${esc(c)}">${esc(faDigJS(num))}</b><span style="font-size:11px;color:#64748b">${esc(it.text || '')}</span></div></div>${props.gaugeText ? `<div class="feat-d" style="text-align:center;margin-top:9px">${esc(props.gaugeText)}</div>` : ''}</div></div>`;
    }
    /* 🆕 v2.31 — buttons: مجموعه دکمه با استایل/رنگ/لینک هر آیتم */
    if (type === 'buttons') {
        const styleCls = { primary: '', ghost: ' ghost', outline: ' ghost', gradient: ' btn-grad' };
        return `${TITLE}${sub}<div class="hero-btns" style="justify-content:flex-start;flex-wrap:wrap;gap:10px">${its.map(it => {
            const st = String(it.desc || 'primary').trim();
            const c = /^#[0-9a-fA-F]{3,8}$/.test(String(it.color || '')) ? ` style="background:${esc(it.color)}"` : (st === 'gradient' ? ' style="background:linear-gradient(135deg,#1e40af,#0ea5e9)"' : '');
            const inner = `<span class="hero-btn${styleCls[st] !== undefined ? styleCls[st] : ''}"${st === 'outline' ? ' data-outline="1"' : ''}${c}>${it.icon ? esc(it.icon) + ' ' : ''}${esc(it.text || 'دکمه')}</span>`;
            const lk = String(it.link || '').trim();
            if (!lk) { return inner; }
            const ext = /^https?:\/\//i.test(lk) ? ' target="_blank" rel="noopener"' : '';
            return `<a href="${esc(lk)}"${ext} style="text-decoration:none;display:inline-block">${inner.replace('<span ', '<span data-in-a="1" ')}</a>`;
        }).join('')}</div>`;
    }
    /* پیش‌فرض: cards */
    return `${TITLE}<div class="cols c${cols}">${its.map(it => `<div class="fake-card">${it.icon ? `<div class="card-ico">${esc(it.icon)}</div>` : ''}<div class="card-t">${esc(it.text || '')}</div>${it.desc ? `<div class="feat-d">${esc(it.desc)}</div>` : ''}</div>`).join('')}</div>`;
}
function it0text(its) { return its.length && its[0].text ? its[0].text : 'متن نقل‌قول'; }

/* ════════════════════════════════════════════════════════════════
 * ⚡ P2-22 — رندر سرور-محور بوم: تنها منبع HTML بلوک‌ها «هسته رندرگر
 * واحد» است (block-renderer-core.php — همان کد PHP که سایت برند اجرا
 * می‌کند). رندرگر JS موازی (~۶۰۰ خط switch) حذف شد — هر باگ بصری از
 * این پس فقط یک‌جا رفع می‌شود.
 * الگو: اسکلت فوری (بدون تأخیر تعامل) → درخواست دسته‌ای AJAX (۴۰تایی)
 * → جایگزینی + کش در-حافظه (رندر تکراری = آنی).
 * ════════════════════════════════════════════════════════════════ */
const PV_CACHE = new Map();   /* hash → html */
const PV_QUEUE = new Map();   /* hash → {block, props} */
const PV_PENDING = new Map(); /* hash → [id, ...] اسکلت‌های در انتظار */
let PV_TIMER = null;
let PV_SEQ = 0;

function pvHash(block, props) {
    try { return block + '::' + JSON.stringify(props || {}); }
    catch (e) { return block + '::' + Math.random(); }
}
function pvHashId() { return 'pv' + (PV_SEQ++); }

/* قاب استاندارد بوم برای کانتینر ستونی (کروم بوم، نه رندر بصری بلوک) */
function pvWrapLocal(inner, props, extra) {
    const p = props || {};
    const cls = ['blk', 'blk-pad-' + (p.padding || 'default'), 'blk-bg-' + (p.background || 'default'),
        (p.titleSize && p.titleSize !== 'md') ? 'blk-ts-' + p.titleSize : '',
        (p.align && p.align !== 'start') ? 'blk-al-' + p.align : '',
        (p.width && p.width !== 'full') ? 'blk-w-' + p.width : '',
        String(p.customClass || '').trim().replace(/[^a-zA-Z0-9\-_\s]/g, ''),
        (p.hideMobile ? 'blk-hide-mobile ' : '') + (p.hideDesktop ? 'blk-hide-desktop ' : ''),
        extra || ''].filter(Boolean).join(' ');
    return `<div class="${cls}">${inner}</div>`;
}

function blockHtml(block, props) {
    props = props || {};
    /* 🏛 کانتینرهای ستونی: نواحی رهاسازی ستون‌ها را خود بوم می‌سازد —
       محتوای ستون‌ها آیتم‌های مستقل بوم‌اند و جداگانه رندر می‌شوند */
    if (block === 'section-columns' || block === 'section-split') {
        const cols = block === 'section-split' ? 2 : Math.max(2, Math.min(4, parseInt(props.columns || 2, 10)));
        const tmpl = block === 'section-split' ? '2fr 1fr' : `repeat(${cols},1fr)`;
        let inner = '';
        for (let c = 0; c < cols; c++) { inner += '<div class="tb-col" style="display:flex;flex-direction:column;gap:10px;min-width:0"></div>'; }
        const t = props.title || '';
        return pvWrapLocal((t ? `<div class="blk-title">${esc(t)}</div>` : '') + `<div class="tb-col-wrap" style="grid-template-columns:${tmpl}">${inner}</div>`, props, 'section-cols-blk');
    }
    /* ⚡ رندر سرور-محور: کش آنی → اسکلت → AJAX دسته‌ای */
    const h = pvHash(block, props);
    if (PV_CACHE.has(h)) { return PV_CACHE.get(h); }
    const id = pvHashId();
    if (!PV_QUEUE.has(h)) { PV_QUEUE.set(h, { block, props }); }
    if (!PV_PENDING.has(h)) { PV_PENDING.set(h, []); }
    PV_PENDING.get(h).push(id);
    if (PV_TIMER) { clearTimeout(PV_TIMER); }
    PV_TIMER = setTimeout(pvFlush, 70);
    const label = (window.BLOCK_META && BLOCK_META[block] && BLOCK_META[block].label) || block;
    return `<div class="blk tb-skeleton" id="${id}"><div class="blk-title" style="opacity:.75">${esc(label)}</div><div class="tb-skeleton-body"><span class="tb-spin"></span><span>رندر از سرور…</span></div></div>`;
}

async function pvFlush() {
    PV_TIMER = null;
    if (!PV_QUEUE.size) { return; }
    const batch = [];
    for (const [h, q] of PV_QUEUE) {
        if (batch.length >= 40) { break; }
        batch.push({ h, q });
    }
    batch.forEach(({ h }) => PV_QUEUE.delete(h));
    if (PV_QUEUE.size && !PV_TIMER) { PV_TIMER = setTimeout(pvFlush, 120); }
    try {
        const res = await fetch('block-render.ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': UIUX_CSRF },
            body: JSON.stringify({ items: batch.map(({ q }) => ({ block: q.block, props: q.props })) })
        });
        const j = await res.json();
        if (!j || !j.success) { throw new Error((j && j.error) || 'پاسخ نامعتبر'); }
        j.html.forEach((html, i) => {
            const { h, q } = batch[i];
            const final = (html && String(html).trim() !== '')
                ? String(html)
                : `<div class="blk"><div class="pv-text" style="text-align:center;color:#94a3b8">📦 ${esc(q.block)}</div></div>`;
            PV_CACHE.set(h, final);
            /* جایگزینی همه اسکلت‌های در انتظار این هش */
            const ids = PV_PENDING.get(h) || [];
            PV_PENDING.delete(h);
            ids.forEach(id => {
                const el = document.getElementById(id);
                if (!el) { return; }
                const tpl = document.createElement('template');
                tpl.innerHTML = final;
                el.replaceWith(tpl.content);
            });
        });
    } catch (e) {
        batch.forEach(({ q }) => {
            const ids = PV_PENDING.get(pvHash(q.block, q.props)) || [];
            ids.forEach(id => {
                const el = document.getElementById(id);
                if (el) { el.querySelector('.tb-skeleton-body').innerHTML = '<span>⚠ رندر سرور ناموفق — ' + esc(e.message) + '</span>'; }
            });
        });
    }
}

/* استایل‌های درون‌بوم رندر زنده (تزریق یک‌بار) */
(function injectLiveStyles() {
    const css = `
.tb-skeleton { min-height: 96px; display: flex; flex-direction: column; justify-content: center; }
.tb-skeleton-body { display: flex; align-items: center; justify-content: center; gap: 10px; color: #94a3b8; font-size: 12px; min-height: 54px; }
.tb-spin { width: 16px; height: 16px; border-radius: 50%; border: 2.5px solid #e2e8f0; border-top-color: #2563eb; animation: tbSpin .8s linear infinite; flex: none; }
@keyframes tbSpin { to { transform: rotate(360deg); } }
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

/* ═══════════════════════════════════════════════════════════════
   🎬 v2.29 — انیمیشن ورود عناصر (blk-anim-*)
   انتخاب از تنظیمات انیمیشن هر عنصر + سرعت + تأخیر (موجی)
   ═══════════════════════════════════════════════════════════════ */
.blk-anim { animation: blkAnimIn var(--anim-dur, .7s) cubic-bezier(.22,.9,.32,1.02) both; animation-delay: var(--anim-delay, 0ms); }
@keyframes blkAnimIn { from { opacity: 0; } to { opacity: 1; } }
.blk-anim-fade { animation-name: blkFade; }
@keyframes blkFade { from { opacity: 0; } to { opacity: 1; } }
.blk-anim-up { animation-name: blkUp; }
@keyframes blkUp { from { opacity: 0; transform: translateY(38px); } to { opacity: 1; transform: translateY(0); } }
.blk-anim-down { animation-name: blkDown; }
@keyframes blkDown { from { opacity: 0; transform: translateY(-38px); } to { opacity: 1; transform: translateY(0); } }
.blk-anim-right { animation-name: blkRight; }
@keyframes blkRight { from { opacity: 0; transform: translateX(46px); } to { opacity: 1; transform: translateX(0); } }
.blk-anim-left { animation-name: blkLeft; }
@keyframes blkLeft { from { opacity: 0; transform: translateX(-46px); } to { opacity: 1; transform: translateX(0); } }
.blk-anim-zoom { animation-name: blkZoom; }
@keyframes blkZoom { from { opacity: 0; transform: scale(.82); } to { opacity: 1; transform: scale(1); } }
.blk-anim-flip { animation-name: blkFlip; }
@keyframes blkFlip { from { opacity: 0; transform: perspective(700px) rotateX(-52deg); } to { opacity: 1; transform: perspective(700px) rotateX(0); } }
.blk-anim-bounce { animation-name: blkBounce; }
@keyframes blkBounce { 0% { opacity: 0; transform: translateY(-46px); } 55% { opacity: 1; transform: translateY(8px); } 75% { transform: translateY(-5px); } 100% { transform: translateY(0); } }
.blk-anim-rotate { animation-name: blkRotate; }
@keyframes blkRotate { from { opacity: 0; transform: rotate(-4.5deg) scale(.94); } to { opacity: 1; transform: rotate(0) scale(1); } }
@media (prefers-reduced-motion: reduce) { .blk-anim { animation: none !important; } }

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
    /* 🚨 v2.29 — ریشه «تنظیمات صفحه ذخیره نمی‌شود»: این خط قبلاً چیدمان را «بدون»
       گره _page در فیلد مخفی می‌نوشت و چون render() بعد از syncAndRender()
       صدا زده می‌شد، آخرین نوشتن همیشه تنظیمات صفحه را پاک می‌کرد! اکنون
       همیشه fullLayout (شامل _page) نوشته می‌شود. */
    document.getElementById('layout-json').value = JSON.stringify(fullLayout());
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
        structuralChange(); /* ⏪ v2.34 — رها کردن بلوک = گام تاریخچه */
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
        structuralChange(); /* ⏪ v2.34 — درج بین بلوک‌ها = گام تاریخچه */
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
    /* 🆕 v2.29 */
    barColor: '🎨 رنگ نوارها', slideType: 'نوع اسلایدها',
    /* 🆕 v2.31 */
    btnLink: '🔗 لینک دکمه', gaugeText: 'زیرنویس گیج',
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
/* 🧬 v2.29 — فیلدهای عناصر جدید: عنوان/زیرعنوان/متن/ستون/تصویر/آیتم‌ها */
const GENERIC_BLOCK_FIELDS_V229 = TB_SERVER_DATA.genericFieldsV229;
/* 🆕 v2.31 — عناصر جدید progress/wheels/gauge/buttons: فیلد رنگ نوارها + آیتم‌ها */
const GENERIC_BLOCK_FIELDS_V231 = TB_SERVER_DATA.genericFieldsV231;
Object.assign(GENERIC_BLOCK_FIELDS_V229, GENERIC_BLOCK_FIELDS_V231);
const BLOCK_FIELDS = {
    /* هدر */
    'header-v1': ['IT'], 'header-v2': ['P', 'W', 'B', 'IT'], 'header-v3': ['B', 'IT'],
    'top-bar': ['P', 'W'],
    'notification-bar': ['X', 'notifC'],
    /* هیرو — 🆕 v2.29: اسلایدر تصویری چندمقداری + 🆕 v2.32: BTN = ویرایشگر
       متن + لینک جداگانه هر دکمه (درخواست «چند دکمه با لینک جداگانه») */
    'hero': ['T', 'S', 'BTN'], 'hero-slider': ['T', 'A', 'SLT', 'IT'], 'hero-split': ['T', 'S', 'IMG', 'BTN'],
    'hero-video': ['T', 'IMG'], 'hero-countdown': ['T', 'CD'], 'hero-form': ['T', 'S', 'B', 'FRM', 'DST'],
    'hero-marquee': ['X'], 'announcement-pill': ['T'],
    'hero-minimal': ['T', 'S', 'B', 'BTN'], 'hero-glass': ['T', 'S', 'BTN'], 'logo-strip': ['T'],
    /* 🆕 v2.29 — اسلایدر همه‌کاره: هر تعداد و هر نوع (تصویر/متن/کارت/مقاله/برند) */
    'universal-slider': ['T', 'A', 'SLT', 'IT'],
    /* محتوا — 🆕 v2.29: rich-text لیست قابل ویرایش با آیتم‌ها (رفع «لیستش رو نمیشه تغییر داد») */
    'text': ['T', 'X'], 'text-image': ['T', 'X', 'IMG'], 'intro': ['T', 'X', 'IMG'], 'rich-text': ['T', 'X', 'IT'],
    'quote': ['X'], 'two-col': ['T', 'IT'], 'three-col': ['T', 'IT'], 'brand-story': ['T', 'IT'],
    'area-list': ['T', 'IT'], 'checklist': ['T', 'IT'], 'search-bar': ['placeholder', 'BTN'],
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
    'brand-intro-card': ['T', 'S'], 'price-highlight': ['T', 'S', '$', 'G', 'B', 'BTN'], 'price-compare': ['T', 'C', 'BTN'],
    'service-price-cards': ['T', 'C', 'IT'], 'feature-icons-grid': ['T', 'C', 'IT'],
    /* فرم — 🆕 v2.32: LNK بی‌اثر حذف شد (دکمه فرم عملکردی است و لینک نمی‌شود) */
    'contact-form': ['T', 'B', 'FRM', 'DST'], 'request-form': ['T', 'B', 'FRM', 'DST'], 'newsletter-form': ['T', 'B', 'FRM', 'DST'],
    'appointment-form': ['T', 'B', 'FRM', 'DST'], 'quick-contact-form': ['T', 'B', 'FRM', 'DST'],
    'booking-calendar': ['T', 'BTN'], 'warranty-check': ['T', 'B', 'BTN'], 'price-estimate': ['T', 'BTN'],
    'device-error-lookup': ['T', 'BTN'], 'appointment-compact': ['T', 'B', 'FRM', 'DST'],
    'callback-form': ['T', 'B', 'FRM', 'DST'], 'survey-form': ['T', 'IT', 'FRM', 'DST'],
    /* آمار — 🆕 v2.29: رنگ نوارهای پیشرفت (رفع «نوارهای پیشرفت رنگشون عوض نمیشه») */
    'counter-stats': ['T', 'IT'], 'progress-bars': ['T', 'CLR', 'IT'], 'skill-bars': ['T', 'CLR', 'IT'],
    'stats-grid': ['T', 'C', 'IT'], 'stats-strip': ['T', 'IT'],
    'live-queue': ['T', 'IT'], 'hourly-capacity': ['T', 'IT'], 'stats-inline': ['T', 'IT'],
    'stats-circles': ['T', 'CLR', 'IT'], 'counter-big': ['T', 'IT'], 'brand-stats-bar': ['T', 'IT'],
    /* تعامل */
    'testimonials': ['T', 'A', 'IT'], 'faq-accordion': ['T', 'IT'], 'tabs': ['T', 'IT'], 'timeline': ['T', 'IT'],
    'steps-process': ['T', 'IT'], 'before-after': ['T', 'X', 'X2'], 'social-proof': ['X'],
    'warranty-steps': ['T', 'IT'], 'feature-table': ['T', 'IT'],
    'faq-search': ['T', 'placeholder'], 'faq-category': ['T', 'IT'],
    'faq-mini': ['T', 'X'], 'steps-compact': ['T', 'IT'],
    'quote-slider': ['T', 'A', 'IT'], 'vote-poll': ['T', 'IT'],
    /* رسانه — 🆕 v2.29: کاروسل تصاویر چندمقداری */
    'gallery': ['T', 'C', 'IMG', 'IT'], 'image-carousel': ['T', 'A', 'IMG', 'IT'], 'video-embed': ['T', 'IMG', 'V'], 'map': ['T', 'X', 'MU'],
    'before-after-slider': ['T', 'IMG'], 'social-wall': ['T', 'IT'], 'reviews-carousel': ['T', 'A', 'IT'],
    'video-grid': ['T', 'C', 'IMG'], 'logo-marquee': ['T', 'IT'], 'tag-cloud': ['T', 'IT'],
    /* فراخوان — 🆕 v2.32: BTN = ویرایشگر متن + لینک هر دکمه */
    'cta-phone': ['T', 'P'], 'cta-request': ['T', 'B', 'BTN'], 'cta-banner': ['T', 'B', 'BTN'],
    'sticky-mobile-cta': ['P', 'B', 'BTN'], 'cta-whatsapp': ['T', 'X', 'BTN'], 'warranty-banner': ['T', 'X', 'BTN'],
    'link-buttons': ['T', 'IT'], 'promo-card': ['T', 'S', 'BTN'], 'download-card': ['T', 'S', 'B', 'BTN'],
    'guarantee-card': ['T', 'S', 'B', 'BTN'], 'cta-timer': ['T', 'S', 'CD', 'BTN'], 'urgent-repair': ['T', 'P', 'B', 'BTN'],
    'newsletter-popup': ['T', 'S', 'B', 'BTN'],
    'emergency-strip': ['X', 'P'],
    /* ساختار */
    'breadcrumb': ['IT'], 'alert-notice': ['X', 'alertT'], 'button-group': ['B', 'IT'],
    'icon-list': ['T', 'IT'], 'separator': [], 'divider-icon': ['I'], 'spacer': ['H'],
    'working-hours': ['T', 'IT'], 'social-follow': ['T', 'IT'], 'trust-badges': ['T', 'IT'],
    'contact-info-bar': ['P', 'W'], 'contact-map-split': ['T', 'P'], 'warning-box': ['T', 'I', 'X'],
    'related-links': ['T', 'IT'], 'schedule-table': ['T', 'IT'],
    'ticker-bar': ['X'], 'credit-trust': ['T', 'IT'], 'brand-badges-row': ['T', 'IT'],
    'chat-widget': ['T', 'BTN'],
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
    /* 🆕 v2.29 — رنگ نوارها + نوع اسلایدها */
    'CLR': 'barColor', 'SLT': 'slideType',
    /* 🆕 v2.31 — لینک دکمه + تنظیمات فرم */
    'LNK': 'btnLink', 'FRM': '__formFields', 'DST': '__formDest', 'GT': 'gaugeText',
    /* 🆕 v2.32 — ویرایشگر دکمه‌های عنصر (متن + لینک جداگانه هر دکمه) */
    'BTN': '__buttons',
};

/* ═══════════════════════════════════════════════════════════════
 * 🔘 v2.32 — دکمه‌های هر عنصر: تعداد + برچسب پیش‌فرض
 * (درخواست کاربر: «عناصری که داخلشان دکمه هست، بتوان برای آن دکمه
 *  لینک تنظیم کرد — چند دکمه باشد برای هر کدام لینک جداگانه»)
 * بلوک‌های دکمه‌دارِ آیتمی (btn-* / button-group / link-buttons) از
 * ستون لینک خود آیتم‌ها استفاده می‌کنند و اینجا نیستند.
 * ═══════════════════════════════════════════════════════════════ */
const BTN_INFO = {
    'hero':              [['۱ — تماس فوری', '📞 تماس فوری'], ['۲ — درخواست آنلاین', 'ثبت درخواست آنلاین']],
    'hero-split':        [['۱ — دکمه اصلی', 'شروع کنید']],
    'hero-form':         [['۱ — تماس فوری', '📞 تماس فوری'], ['۲ — دکمه فرم', 'ثبت درخواست']],
    'hero-minimal':      [['۱ — دکمه اصلی', 'شروع کنید']],
    'cta-request':       [['۱ — دکمه اصلی', '📝 ثبت درخواست']],
    'cta-banner':        [['۱ — دکمه اصلی', '📝 ثبت درخواست']],
    'sticky-mobile-cta': [['۱ — دکمه درخواست', 'ثبت درخواست']],
    'promo-card':        [['۱ — دکمه رزرو', 'همین حالا رزرو کنید']],
    'download-card':     [['۱ — دکمه دانلود', '⬇ دانلود بروشور']],
    'guarantee-card':    [['۱ — دکمه گارانتی', 'مشاهده شرایط']],
    'cta-timer':         [['۱ — دکمه رزرو', 'همین حالا رزرو کنید']],
    'urgent-repair':     [['۱ — دکمه اعزام', 'درخواست اعزام']],
    'newsletter-popup':  [['۱ — دکمه عضویت', 'عضویت']],
    'warranty-banner':   [['۱ — دکمه اصلی', 'مشاهده شرایط گارانتی']],
    'search-bar':        [['۱ — دکمه جستجو', 'جستجو']],
    'price-highlight':   [['۱ — دکمه سفارش', 'سفارش الآن']],
    'price-compare':     [['۱ — دکمه مقایسه', 'مقایسه پلن‌ها']],
    'universal-banner':  [['۱ — دکمه اصلی', 'ثبت درخواست']],
    'booking-calendar':  [['۱ — دکمه رزرو', 'رزرو نوبت']],
    'warranty-check':    [['۱ — دکمه استعلام', 'استعلام گارانتی']],
    'price-estimate':    [['۱ — دکمه محاسبه', 'محاسبه آنلاین']],
    'device-error-lookup': [['۱ — دکمه جستجو', 'جستجوی خطا']],
    'chat-widget':       [['۱ — دکمه چت', '💬 گفتگوی آنلاین']],
};
/* 🧬 v2.29 */
Object.assign(BLOCK_FIELDS, GENERIC_BLOCK_FIELDS_V229);

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
            /* 🐛 v2.27 — رشته قبلی با \${...} اِسکیپ‌شده بود (درون template
               literal) → خروجی HTML به‌جای مقدار، متن خام «${esc(label)}» را
               نشان می‌داد! اکنون interpolation واقعی.
               🆕 v2.38 — پیش‌نمایش زنده‌ی آیکون (ایموجی یا SVG از پک) + ورودی گسترده‌تر */
            html += `<div class="form-group"><label>${esc(label)}</label>
                <div style="display:flex;gap:6px;align-items:center">
                    <span class="tb-ico-prev" style="flex:0 0 auto;width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border);border-radius:7px;background:var(--card,#fff)">${iconPreviewHtml(props.icon, 15)}</span>
                    <input type="text" class="form-control" style="font-size:12px;flex:1;min-width:86px;direction:ltr;text-align:left" value="${esc(props.icon || '')}" oninput="setProp('${selected}','icon',this.value);refreshIconPreviews()" placeholder="${FIELD_DEFS.icon.ph}" title="آیکون (ایموجی یا svg:pack/file.svg)">
                    <button type="button" class="btn btn-outline btn-sm" style="flex:0 0 auto" onclick="openEmojiPicker(this.closest('.form-group').querySelector('input'))" title="انتخاب از کتابخانه آیکون‌ها + پک‌های SVG">🎨 انتخاب آیکون</button>
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
        } else if (code === 'CLR') {
            /* 🎨 v2.29 — رنگ نوارهای پیشرفت / حلقه‌های درصدی (رفع «نمیشه رنگشون رو تغییر داد») */
            html += `<div class="form-group"><label>${esc(label)}</label>
                <div style="display:flex;gap:7px;align-items:center">
                    <input type="color" class="form-control" style="width:48px;height:33px;padding:2px;cursor:pointer" value="${esc(props.barColor || '#1e40af')}" oninput="setProp('${selected}','barColor',this.value)">
                    <button type="button" class="btn btn-outline btn-sm" onclick="setProp('${selected}','barColor','');renderProps()" title="رنگ پیش‌فرض">✕ پیش‌فرض</button>
                </div>
                <div class="hint" style="margin-top:4px">رنگ پرشدن نوارها — بلافاصله روی بوم اعمال می‌شود.</div></div>`;
        } else if (code === 'LNK') {
            /* 🔗 v2.31 — لینک دکمه‌های این عنصر (یک لینک برای همه) */
            html += `<div class="form-group"><label>${esc(label)}</label>
                <input type="text" class="form-control" style="font-size:11.5px;direction:ltr;text-align:left" value="${esc(props.btnLink || '')}" oninput="setProp('${selected}','btnLink',this.value)" placeholder="https://... یا /request یا tel:021...">
                <div class="hint" style="margin-top:4px">💡 دکمه‌های این عنصر به این لینک وصل می‌شوند — در سایت برند قابل کلیک‌اند. آدرس کامل = تب جدید.</div></div>`;
        } else if (code === 'BTN') {
            /* 🔘 v2.32 — ویرایشگر دکمه‌های عنصر: متن + لینک جداگانه هر دکمه
               (درخواست «چند دکمه باشد برای هر کدام لینک جداگانه») */
            const btns = BTN_INFO[item.block] || [];
            const btnLinks = (props.btnLinks && typeof props.btnLinks === 'object' && !Array.isArray(props.btnLinks)) ? props.btnLinks : {};
            const btnTexts = (props.btnTexts && typeof props.btnTexts === 'object' && !Array.isArray(props.btnTexts)) ? props.btnTexts : {};
            html += `<div style="font-size:11px;font-weight:800;color:var(--primary);margin:11px 0 7px">🔘 دکمه‌های این عنصر (متن + لینک جداگانه)</div>`;
            btns.forEach(([bLabel, bDefault], bi) => {
                const idx = String(bi + 1);
                const curText = String(btnTexts[idx] ?? '').trim();
                const curLink = String(btnLinks[idx] ?? '').trim();
                html += `<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:8px 10px;margin-bottom:7px">
                    <div style="font-size:10.5px;font-weight:800;color:#475569;margin-bottom:5px">دکمه ${esc(bLabel)}</div>
                    <div style="display:flex;gap:6px;margin-bottom:5px">
                        <input type="text" class="form-control" style="flex:1;min-width:100px;font-size:11.5px" value="${esc(curText)}" oninput="setBtnProp('${selected}','btnTexts',${bi + 1},this.value)" placeholder="${esc(bDefault)} — متن پیش‌فرض">
                    </div>
                    <div style="display:flex;gap:6px">
                        <input type="text" class="form-control" style="flex:1;min-width:100px;font-size:11px;direction:ltr;text-align:left;color:#2563eb" value="${esc(curLink)}" oninput="setBtnProp('${selected}','btnLinks',${bi + 1},this.value)" placeholder="لینک این دکمه — https://... یا /request یا tel:...">
                    </div>
                </div>`;
            });
            if (btns.length > 1) {
                html += `<div class="hint" style="margin-top:5px;font-size:10px">💡 هر دکمه لینک «مستقل» خودش را می‌گیرد — مثلاً دکمه ۱ به tel: و دکمه ۲ به /request.</div>`;
            }
        } else if (code === 'GT') {
            html += `<div class="form-group"><label>${esc(label)}</label>
                <input type="text" class="form-control" style="font-size:12px" value="${esc(props.gaugeText || '')}" oninput="setProp('${selected}','gaugeText',this.value)" placeholder="مثلاً از ۱۰,۰۰۰ نظر مشتریان"></div>`;
        } else if (code === 'FRM') {
            /* 📋 v2.31 — تنظیم فیلدهای فرم: فعال/الزامی هر فیلد */
            const FF = node.props.formFields || {};
            const fieldsDef = formFieldsDef(item.block);
            html += `<div style="font-size:11px;font-weight:800;color:var(--primary);margin:11px 0 7px">📋 فیلدهای فرم (روی سایت واقعی فعال‌اند)</div>`;
            fieldsDef.forEach(f => {
                const cur = FF[f.key] || { on: f.def, req: f.defReq };
                html += `<div style="display:flex;align-items:center;gap:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:6px 10px;margin-bottom:5px">
                    <label class="form-check" style="margin:0;font-size:11.5px;flex:1"><input type="checkbox" ${(cur.on !== 0 && cur.on !== false) ? 'checked' : ''} onchange="setFormField('${selected}','${f.key}','on',this.checked?1:0)"> ${esc(f.label)}</label>
                    <label class="form-check" style="margin:0;font-size:10.5px;color:${(cur.on !== 0 && cur.on !== false) ? '#b45309' : '#cbd5e1'}"><input type="checkbox" ${(cur.req !== 0 && cur.req !== false) ? 'checked' : ''} onchange="setFormField('${selected}','${f.key}','req',this.checked?1:0)" ${(cur.on === 0 || cur.on === false) ? 'disabled' : ''}> الزامی</label>
                </div>`;
            });
            html += `<div class="hint" style="margin-top:5px;font-size:10px">فیلد غیرفعال روی سایت برند نمایش داده نمی‌شود. «الزامی» = بدون پر کردن، فرم ارسال نمی‌شود.</div>`;
        } else if (code === 'DST') {
            /* 📨 v2.31 — مقصد ارسال اطلاعات فرم */
            const FD = node.props.formDest || {};
            html += `<div style="font-size:11px;font-weight:800;color:var(--primary);margin:11px 0 7px">📨 اطلاعات فرم به کجا ارسال شود؟</div>`;
            [['panel', '🖥️ ثبت در پنل مدیریت (درخواست‌ها/فرم‌ها)', 'همیشه پیشنهاد می‌شود'], ['email', '📧 ایمیل', 'به ایمیل تنظیم‌شده در تنظیمات ارسال'], ['telegram', '📱 تلگرام', 'به چت تلگرام تنظیم‌شده'], ['bale', '💬 بله', 'به چت بله تنظیم‌شده']].forEach(([k, l, hint]) => {
                html += `<label class="form-check" style="font-size:11.5px;margin-bottom:5px"><input type="checkbox" ${(k === 'panel' ? (FD.panel !== 0 && FD.panel !== false) : !!FD[k]) ? 'checked' : ''} onchange="setFormDest('${selected}','${k}',this.checked?1:0)"> ${l} <small style="color:#94a3b8">— ${hint}</small></label>`;
            });
            html += `<div class="hint" style="margin-top:5px;font-size:10px">ترکیب دلخواه — مثلاً «پنل + تلگرام». کانال‌های ایمیل/تلگرام/بله باید در تنظیمات ارسال فعال باشند.</div>`;
        } else if (code === 'SLT') {
            /* 🎞 v2.29 — نوع اسلایدهای اسلایدر (تصویر/متن/کارت/مقاله/برند) */
            html += `<div class="form-group"><label>🎞 نوع اسلایدها</label>
                <select class="form-control" style="font-size:12px" onchange="setProp('${selected}','slideType',this.value)">
                    ${[['image', '🖼 تصویر (آدرس در ستون توضیح هر آیتم)'], ['text', '📝 متن / شعار'], ['card', '🃏 کارت (عنوان + متن)'], ['article', '📰 مقاله (داینامیک از سایت برند)'], ['brand', '🏷️ برند (داینامیک از سایت ساز)']].map(([v, l]) => `<option value="${v}" ${(props.slideType || 'image') === v ? 'selected' : ''}>${l}</option>`).join('')}
                </select>
                <div class="hint" style="margin-top:4px">هر تعداد اسلاید بخواهید از «آیتم‌های لیست» اضافه کنید — نوع مقاله/برند خودکار از محتوای سایت برند پر می‌شود.</div></div>`;
        } else if (code === 'IMG') {
            /* 🖼 v2.15: تصویر واقعی به‌جای نمای قالبی */
            html += `<div class="form-group"><label>${esc(label)}</label>
                <input type="text" class="form-control" style="font-size:11.5px;direction:ltr;text-align:left" value="${esc(props.imageUrl || '')}" oninput="setProp('${selected}','imageUrl',this.value)" placeholder="https://example.com/photo.jpg">
                <div class="hint" style="margin-top:4px">آدرس تصویر واقعی این بخش — خالی = نمای پیش‌فرض قالبی.</div></div>`;
        } else if (code === 'IT') {
            /* ➕ v2.25: ویرایشگر آیتم‌ها — چهار فیلد کامل (آیکون + متن + توضیح + 🔗 لینک)
               با انتخابگر آیکون ایموجی — 🆕 v2.29: ستون لینک = کلیک‌پذیری هر آیتم */
            const items = Array.isArray(props.items) ? props.items : [];
            const isSlider = ['hero-slider', 'universal-slider', 'image-carousel', 'quote-slider', 'testimonials', 'reviews-carousel'].includes(item.block);
            /* 🎨 v2.31 — ستون رنگ اختصاصی هر آیتم برای نوارها/گردونه‌ها و دکمه‌ها */
            const COLOR_ITEMS = ['progress-bars', 'skill-bars', 'stats-circles', 'progress-multi', 'progress-striped', 'progress-thin', 'progress-circles', 'progress-ring-big', 'progress-semi', 'btn-duo', 'btn-gradient', 'btn-outline-row', 'btn-icon-row', 'btn-mega-cta', 'btn-social'].includes(item.block);
            const descPh = { 'progress-bars': 'درصد — مثلاً ۸۰', 'skill-bars': 'درصد — مثلاً ۹۰', 'pricing-table': 'قیمت — مثلاً ۹۵۰ هزار تومان', 'price-cards': 'قیمت پلن', 'working-hours': 'ساعت — مثلاً ۹ تا ۲۰', 'schedule-table': 'ساعت — مثلاً ۹ تا ۲۰', 'faq-accordion': 'پاسخ سوال...', 'counter-stats': 'برچسب عدد', 'stats-inline': 'برچسب', 'stats-strip': 'برچسب', 'testimonials': 'نام مشتری', 'quote-slider': 'نام گوینده', 'timeline': 'وضعیت — مثلاً در حال انجام', 'gallery': 'آدرس تصویر (اختیاری)', 'image-carousel': 'آدرس تصویر (اختیاری)', 'social-follow': 'آدرس پروفایل (اختیاری)', 'related-links': 'آدرس لینک (اختیاری)', 'footer-links': 'آدرس لینک (اختیاری)', 'tag-cloud': '', 'vote-poll': 'آدرس گزینه (اختیاری)', 'survey-form': '', 'hero-slider': isSlider ? 'آدرس تصویر اسلاید' : '', 'universal-slider': 'آدرس تصویر اسلاید (نوع تصویر)' }[item.block] || 'توضیح / مقدار (اختیاری)...';
            const linkPh = isSlider ? 'لینک اسلاید (اختیاری)' : 'لینک آیتم (اختیاری — کلیک‌پذیر)';
            html += `<div style="font-size:11px;font-weight:800;color:var(--primary);margin:11px 0 7px">➕ آیتم‌های لیست (${faDigJS(items.length)})</div>`;
            items.forEach((it, idx) => {
                html += `<div class="item-edit-row" style="flex-wrap:wrap">
                    <span class="tb-ico-prev" style="flex:0 0 auto;width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border);border-radius:7px;background:var(--card,#fff);cursor:pointer" onclick="openEmojiPicker(this.parentNode.querySelector('input'))" title="کلیک: انتخابگر آیکون (ایموجی + پک SVG)">${iconPreviewHtml(it.icon, 15)}</span>
                    <input type="text" class="form-control" style="width:76px;text-align:center;font-size:10.5px;direction:ltr" value="${esc(it.icon || '')}" oninput="setItemProp('${selected}',${idx},'icon',this.value);refreshIconPreviews()" placeholder="⚡" onclick="openEmojiPicker(this)" title="کلیک: انتخابگر آیکون — ایموجی یا svg:pack/file.svg">
                    <input type="text" class="form-control" style="flex:1;min-width:110px;font-size:11.5px" value="${esc(it.text || '')}" oninput="setItemProp('${selected}',${idx},'text',this.value)" placeholder="متن آیتم...">
                    <input type="text" class="form-control" style="flex:1;min-width:110px;font-size:11px;color:var(--text-light)" value="${esc(it.desc || '')}" oninput="setItemProp('${selected}',${idx},'desc',this.value)" placeholder="${esc(descPh)}">
                    <input type="text" class="form-control" style="flex:1;min-width:110px;font-size:11px;direction:ltr;text-align:left;color:#2563eb" value="${esc(it.link || '')}" oninput="setItemProp('${selected}',${idx},'link',this.value)" placeholder="${esc(linkPh)}" title="🔗 لینک این آیتم — در سایت برند قابل کلیک می‌شود">
                    ${COLOR_ITEMS ? `<input type="color" class="form-control" style="width:38px;height:31px;padding:2px;cursor:pointer;flex:none" value="${esc(/^#[0-9a-fA-F]{3,8}$/.test(String(it.color || '')) ? it.color : '#1e40af')}" oninput="setItemProp('${selected}',${idx},'color',this.value)" title="🎨 رنگ اختصاصی این آیتم">` : ''}
                    <button type="button" class="btn btn-outline btn-sm" onclick="moveListItem('${selected}',${idx},-1)" title="بالا">↑</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="moveListItem('${selected}',${idx},1)" title="پایین">↓</button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="removeListItem('${selected}',${idx})" title="حذف">✕</button>
                </div>`;
            });
            html += `<button type="button" class="btn btn-info btn-sm btn-block" style="margin-top:6px" onclick="addListItem('${selected}')">➕ افزودن آیتم جدید (بدون محدودیت)</button>
                <div class="hint" style="margin-top:5px;font-size:10px;line-height:1.7">💡 روی کادر آیکون کلیک کنید تا <b>انتخابگر آیکون</b> (ایموجی + <b>پک آیکون SVG</b>) باز شود — ستون سوم برای توضیح/قیمت/درصد و ستون آبی <b>لینک</b> است${COLOR_ITEMS ? ' و ستون رنگ، <b>رنگ اختصاصی همین آیتم</b> (نوار/گردونه/دکمه)' : ''}.</div>`;
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

    /* 🖱 v2.29 — کلیک‌پذیری عنصر: لینک‌دار کردن کل بلوک/کارت (درخواست کاربر)
       روی سایت برند کل عنصر داخل <a> پیچیده می‌شود. */
    if (!STRUCTURAL.includes(item.block)) {
        html += `
        <div style="font-size:11px;font-weight:800;color:var(--primary);margin:12px 0 7px">🖱 کلیک‌پذیری (لینک‌دار)</div>
        <label class="form-check" style="font-size:12px"><input type="checkbox" ${props.clickable ? 'checked' : ''} onchange="setProp('${selected}','clickable',this.checked?1:0);renderProps()"> 🔗 این عنصر کلیک‌پذیر باشد</label>
        ${props.clickable ? `<div class="form-group" style="margin-top:7px"><label>لینک مقصد (URL)</label>
            <input type="text" class="form-control" style="font-size:11.5px;direction:ltr;text-align:left" value="${esc(props.link || '')}" oninput="setProp('${selected}','link',this.value)" placeholder="https://example.com/page یا /services">
            <div class="hint" style="margin-top:4px">در سایت برند، کلیک روی هر جای این عنصر به این لینک می‌رود (آیتم‌ها هم لینک اختصاصی خودشان را دارند).</div></div>
        <div class="form-group"><label>باز شدن لینک</label>
            <select class="form-control" style="font-size:12px" onchange="setProp('${selected}','linkTarget',this.value)">
                ${[['same', 'در همین تب'], ['new', 'تب جدید']].map(([v, l]) => `<option value="${v}" ${(props.linkTarget || 'same') === v ? 'selected' : ''}>${l}</option>`).join('')}
            </select></div>` : ''}`;
    }

    /* 🎬 v2.29 — تنظیمات انیمیشن ورود (درخواست کاربر: «برای عناصر تنظیمات انیمیشن هم بزار») */
    if (!STRUCTURAL.includes(item.block)) {
        const animSel = `<select class="form-control" style="font-size:12px" onchange="setProp('${selected}','anim',this.value);renderProps()">
            ${[['none', 'بدون انیمیشن'], ['fade', 'محوشدن (Fade)'], ['up', 'آمدن از پایین'], ['down', 'آمدن از بالا'], ['right', 'آمدن از راست'], ['left', 'آمدن از چپ'], ['zoom', 'بزرگ‌نمایی (Zoom)'], ['flip', 'چرخش سه‌بعدی'], ['bounce', 'پرش نرم'], ['rotate', 'چرخش ملایم'], ['swing', 'تاب خوردن آویزی'], ['drop', 'افتادن از بالا (سنگین)'], ['pop', 'پاپ کاذب (rubber)'], ['jelly', 'ژله‌ای']].map(([v, l]) => `<option value="${v}" ${(props.anim || 'none') === v ? 'selected' : ''}>${l}</option>`).join('')}
        </select>`;
        html += `
        <div style="font-size:11px;font-weight:800;color:var(--primary);margin:12px 0 7px">🎬 انیمیشن ورود</div>
        <div class="form-group"><label>نوع انیمیشن (هنگام دیده‌شدن با اسکرول)</label>${animSel}</div>
        ${(props.anim && props.anim !== 'none') ? `
        <div class="form-group"><label>سرعت انیمیشن</label>
            <select class="form-control" style="font-size:12px" onchange="setProp('${selected}','animSpeed',this.value)">
                ${[['xslow', 'خیلی آهسته (۱.۸s)'], ['slow', 'آهسته (۱.۲s)'], ['normal', 'معمولی (۰.۷s)'], ['fast', 'سریع (۰.۴s)'], ['xfast', 'فوری (۰.۲s)']].map(([v, l]) => `<option value="${v}" ${(props.animSpeed || 'normal') === v ? 'selected' : ''}>${l}</option>`).join('')}
            </select></div>
        <div class="form-group"><label>⏱ تأخیر شروع (میلی‌ثانیه — برای موجی‌شدن)</label>
            <input type="number" class="form-control" style="font-size:12px" min="0" max="3000" step="50" value="${parseInt(props.animDelay || 0, 10)}" onchange="setProp('${selected}','animDelay',parseInt(this.value,10) || 0)">
            <div class="hint" style="margin-top:4px">مثلاً برای کارت‌های پشت‌سرهم: ۰، ۱۵۰، ۳۰۰، ... تا با هم موجی ظاهر شوند.</div></div>` : ''}
        <div style="font-size:11px;font-weight:800;color:var(--primary);margin:13px 0 7px">♾️ انیمیشن پیوسته (لوپ)</div>
        <div class="form-group"><label>حرکت همیشگی عنصر (برای جلب توجه)</label>
            <select class="form-control" style="font-size:12px" onchange="setProp('${selected}','ambient',this.value)">
                ${[['none', 'بدون حرکت'], ['float', '🎈 شناور (بالا-پایین)'], ['pulse', '💗 نبض (بزرگ-کوچک)'], ['shine', '✨ برق (درخشش متناوب)'], ['sway', '🍂 تاب خوردن ظریف'], ['bobble', '🔕 تکان ملایم']].map(([v, l]) => `<option value="${v}" ${(props.ambient || 'none') === v ? 'selected' : ''}>${l}</option>`).join('')}
            </select>
            <div class="hint" style="margin-top:4px">برای بنرهای تبلیغاتی و دکمه‌های مهم — با سلیقه استفاده کنید تا سایت شلوغ نشود.</div></div>`;
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
        <div class="form-group"><label>✍️ رنگ متن بدنه (توضیحات)</label>
            <div style="display:flex;gap:7px;align-items:center">
                <input type="color" class="form-control" style="width:48px;height:33px;padding:2px;cursor:pointer" value="${esc(props.textColor || '#334155')}" oninput="setProp('${selected}','textColor',this.value)">
                <button type="button" class="btn btn-outline btn-sm" onclick="setProp('${selected}','textColor','');renderProps()" title="حذف رنگ — برگشت به پیش‌فرض قالب">✕ پیش‌فرض</button>
            </div>
            <div class="hint" style="margin-top:4px">🆕 رنگ متن‌ها و توضیحات همین بخش (جدا از رنگ عنوان).</div></div>
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
            </select></div>
        <div class="form-group"><label>📐 فاصله اختصاصی از بالا / پایین (px)</label>
            <div style="display:flex;gap:7px">
                <input type="number" class="form-control" style="font-size:12px" min="-80" max="300" value="${esc(props.mt ?? '')}" oninput="setProp('${selected}','mt',this.value)" placeholder="بالا — خالی=خودکار">
                <input type="number" class="form-control" style="font-size:12px" min="-80" max="300" value="${esc(props.mb ?? '')}" oninput="setProp('${selected}','mb',this.value)" placeholder="پایین — خالی=خودکار">
            </div>
            <div class="hint" style="margin-top:4px">🆕 جابه‌جایی دقیق همین بخش نسبت به بخش‌های قبل/بعد — عدد منفی = نزدیک‌تر.</div></div>`;
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
                ${['default', 'surface', 'primary', 'gradient', 'dark', 'custom'].map(v => `<option value="${v}" ${props.background === v ? 'selected' : ''}>${{ default: 'معمولی', surface: 'کمرنگ', primary: 'رنگ اصلی', gradient: 'گرادیانت', dark: 'تیره', custom: 'رنگ دلخواه 🆕' }[v]}</option>`).join('')}
            </select>
            ${props.background === 'gradient' ? `
            <div style="display:flex;gap:7px;align-items:center;margin-top:7px">
                <input type="color" class="form-control" style="width:44px;height:31px;padding:2px;cursor:pointer" value="${esc(props.gradientFrom || '#1e40af')}" oninput="setProp('${selected}','gradientFrom',this.value)" title="رنگ شروع گرادیانت">
                <span style="font-size:11px;color:var(--text-light)">تا</span>
                <input type="color" class="form-control" style="width:44px;height:31px;padding:2px;cursor:pointer" value="${esc(props.gradientTo || '#0ea5e9')}" oninput="setProp('${selected}','gradientTo',this.value)" title="رنگ پایان گرادیانت">
            </div>
            <div class="hint" style="margin-top:4px">🌈 دو سر رنگ گرادیانت را انتخاب کنید — ترکیب دلخواه شما روی بوم اعمال می‌شود.</div>` : ''}
            ${props.background === 'custom' ? `
            <div style="display:flex;gap:7px;align-items:center;margin-top:7px">
                <input type="color" class="form-control" style="width:44px;height:31px;padding:2px;cursor:pointer" value="${esc(props.bgColor || '#fff7ed')}" oninput="setProp('${selected}','bgColor',this.value)" title="رنگ زمینه دلخواه">
                <span style="font-size:11px;color:var(--text-light)">رنگ زمینه دلخواه این بخش</span>
            </div>` : ''}
        </div>
        <div class="form-group"><label>📐 گردی گوشه‌های همین بخش</label>
            <select class="form-control" style="font-size:12px" onchange="setProp('${selected}','radiusOverride',this.value)">
                ${[['default', 'پیش‌فرض قالب'], ['sharp', 'تیز (بدون گردی)'], ['round', 'گردتر'], ['pill', 'کپسولی خیلی گرد']].map(([v, l]) => `<option value="${v}" ${(props.radiusOverride || 'default') === v ? 'selected' : ''}>${l}</option>`).join('')}
            </select></div>
        <div class="form-group"><label>📱 نمایش در دستگاه‌ها</label>
            <div style="display:flex;gap:12px;flex-wrap:wrap">
                <label class="form-check" style="margin:0;font-size:11.5px"><input type="checkbox" ${props.hideMobile ? '' : 'checked'} onchange="setProp('${selected}','hideMobile',!this.checked)"> 📱 موبایل</label>
                <label class="form-check" style="margin:0;font-size:11.5px"><input type="checkbox" ${props.hideDesktop ? '' : 'checked'} onchange="setProp('${selected}','hideDesktop',!this.checked)"> 🖥️ دسکتاپ</label>
            </div>
            <div class="hint" style="margin-top:4px">🆕 عنصر را می‌توانید فقط برای موبایل یا فقط دسکتاپ نگه دارید (مثلاً نوار چسبان فقط موبایل).</div></div>
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

/* 🔘 v2.32 — مقداردهی ویژگی دکمه شماره‌دار (btnTexts/btnLinks[i]) */
function setBtnProp(path, key, oneBasedIdx, value) {
    const node = resolveNode(path);
    if (!node) { return; }
    node.props = node.props || {};
    if (!node.props[key] || typeof node.props[key] !== 'object' || Array.isArray(node.props[key])) { node.props[key] = {}; }
    if (String(value).trim() === '') { delete node.props[key][String(oneBasedIdx)]; }
    else { node.props[key][String(oneBasedIdx)] = value; }
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
/* 📋 v2.31 — تنظیم فیلد فرم (فعال/الزامی) */
function formFieldsDef(block) {
    const common = [
        { key: 'fullName', label: '👤 نام و نام خانوادگی', def: 1, defReq: 1 },
        { key: 'phone', label: '📞 شماره تماس', def: 1, defReq: 1 },
    ];
    const per = {
        'request-form': [
            { key: 'phone2', label: '📞 تماس دوم', def: 1, defReq: 0 },
            { key: 'address', label: '📍 آدرس', def: 1, defReq: 1 },
            { key: 'deviceType', label: '🔧 نوع دستگاه', def: 1, defReq: 1 },
            { key: 'deviceModel', label: '📋 مدل دستگاه', def: 1, defReq: 0 },
            { key: 'preferredTime', label: '📅 زمان مراجعه ترجیحی', def: 1, defReq: 0 },
            { key: 'description', label: '📝 شرح ایراد', def: 1, defReq: 1 },
            { key: 'images', label: '🖼️ تصویر دستگاه (آپلود)', def: 1, defReq: 0 },
        ],
        'contact-form': [
            { key: 'email', label: '📧 ایمیل', def: 1, defReq: 0 },
            { key: 'subject', label: '📌 موضوع', def: 1, defReq: 0 },
            { key: 'address', label: '📍 آدرس', def: 0, defReq: 0 },
            { key: 'description', label: '📝 پیام', def: 1, defReq: 1 },
        ],
        'newsletter-form': [
            { key: 'email', label: '📧 ایمیل', def: 1, defReq: 1 },
        ],
        'callback-form': [
            { key: 'phone2', label: '📞 شماره دوم', def: 0, defReq: 0 },
            { key: 'preferredTime', label: '📅 زمان مناسب تماس', def: 1, defReq: 0 },
            { key: 'description', label: '📝 موضوع تماس', def: 1, defReq: 0 },
        ],
        'quick-contact-form': [
            { key: 'description', label: '📝 توضیح کوتاه', def: 0, defReq: 0 },
        ],
        'appointment-form': [
            { key: 'deviceType', label: '🔧 نوع دستگاه', def: 1, defReq: 0 },
            { key: 'preferredTime', label: '📅 تاریخ و ساعت نوبت', def: 1, defReq: 1 },
            { key: 'address', label: '📍 آدرس', def: 1, defReq: 0 },
            { key: 'description', label: '📝 شرح کار', def: 1, defReq: 0 },
        ],
        'appointment-compact': [
            { key: 'preferredTime', label: '📅 زمان نوبت', def: 1, defReq: 0 },
        ],
        'survey-form': [
            { key: 'description', label: '📝 نظر تکمیلی', def: 1, defReq: 0 },
        ],
        'hero-form': [
            { key: 'deviceType', label: '🔧 نوع دستگاه', def: 1, defReq: 0 },
            { key: 'description', label: '📝 شرح ایراد', def: 1, defReq: 1 },
        ],
    };
    return common.concat(per[block] || [{ key: 'description', label: '📝 پیام', def: 1, defReq: 0 }]);
}
function setFormField(path, fkey, prop, value) {
    const node = resolveNode(path);
    if (!node) { return; }
    node.props = node.props || {};
    if (!node.props.formFields || typeof node.props.formFields !== 'object') { node.props.formFields = {}; }
    if (!node.props.formFields[fkey]) { node.props.formFields[fkey] = {}; }
    node.props.formFields[fkey][prop] = value;
    if (prop === 'on' && !value) { node.props.formFields[fkey].req = 0; }
    syncAndRender();
    renderProps();
}
function setFormDest(path, key, value) {
    const node = resolveNode(path);
    if (!node) { return; }
    node.props = node.props || {};
    if (!node.props.formDest || typeof node.props.formDest !== 'object') { node.props.formDest = { panel: 1 }; }
    node.props.formDest[key] = value;
    if (key === 'panel' && !value && !node.props.formDest.email && !node.props.formDest.telegram && !node.props.formDest.bale) {
        node.props.formDest.panel = 1; /* حداقل یک مقصد */
    }
    syncAndRender();
    renderProps();
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
 * 🎨 v2.38 — پیش‌نمایش آیکون در پنل ویژگی‌ها + انتخابگر کامل
 * مقادیر svg:pack/file.svg (از پک آیکون‌ها) در بوم و سایت برند
 * به‌صورت <img> رندر می‌شوند؛ ایموجی/عدد مثل قبل متن می‌مانند.
 * ================================================== */
function iconPreviewHtml(v, px) {
    v = String(v == null ? '' : v).trim();
    if (v === '') { return '<span style="opacity:.35;font-size:13px">⚡</span>'; }
    if (window.IconPicker) {
        const h = IconPicker.iconHtml(v, px || 16);
        if (h) { return h; }
    }
    if (v.indexOf('svg:') === 0) { return '<span style="font-size:9px;opacity:.6">SVG</span>'; }
    return esc(v);
}
function refreshIconPreviews() {
    document.querySelectorAll('.tb-ico-prev').forEach(function (sp) {
        const inp = sp.parentNode && sp.parentNode.querySelector('input');
        if (inp) { sp.innerHTML = iconPreviewHtml(inp.value, 15); }
    });
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
    /* 🆕 v2.38 — انتخابگر کامل مشترک (ایموجی + پک آیکون SVG): وقتی کامپوننت
     * IconPicker روی صفحه حاضر است، همان مودال دوزبانه باز می‌شود و مقدار
     * svg:pack/file.svg هم قابل انتخاب است (در بوم/سایت به‌صورت <img> رندر می‌شود).
     * اگر کامپوننت حاضر نبود، پاپ‌آور ایموجیِ قدیمی به‌عنوان fallback می‌ماند. */
    if (window.IconPicker) {
        IconPicker.open({
            current: inputEl.value || '',
            onPick: function (value) {
                inputEl.value = value;
                inputEl.dispatchEvent(new Event('input', { bubbles: true }));
                refreshIconPreviews();
            }
        });
        return;
    }
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
    structuralChange();
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
    structuralChange();
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
    structuralChange();
    syncAndRender();
    renderProps();
}
function clearLayout() {
    const doClear = () => { layout = []; selected = ''; structuralChange(); syncAndRender(); renderProps(); };
    if (!layout.length) { doClear(); return; }
    /* 🌉 کادر زیبا (v2.28) */
    sahandConfirm({ title: 'خالی‌کردن بوم', message: 'همه بلوک‌های بوم پاک شوند؟', type: 'warning', confirmText: 'بله، پاک کن' })
        .then(ok => { if (ok) { doClear(); } });
}
function syncAndRender() {
    document.getElementById('layout-json').value = JSON.stringify(fullLayout());
    pushHistory(true); /* ⏪ v2.34 — هر تغییر چیدمان (ساختاری با ادغام خاموش در فراخوانی‌های ساختاری) تاریخچه می‌شود */
    render();
}

/* 🧲 v2.34 — تغییر ساختاری: گام تاریخچه بدون ادغام (افزودن/حذف/جابجایی/کپی هرگز نباید با تایپ قبلی ادغام شوند) */
function structuralChange() {
    pushHistory(false);
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
        } else {
            const ok = await sahandConfirm({ title: 'جایگزینی چیدمان', message: 'چیدمان حرفه‌ای «' + (d.page_name_fa || '') + '» جایگزین چیدمان فعلی شود؟', type: 'question', confirmText: 'بله، جایگزین کن', confirmIcon: '🪄' });
            if (ok) { applyLayout(); }
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
    /* سند مستقل: قوانین کامل CSS زیردرخت (v2.27 — قبلاً اعلان خام بدون
       انتخابگر بود = CSS نامعتبر → فقط متن بی‌استایل دیده می‌شد!) */
    const doc = '<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8">'
        + '<meta name="viewport" content="width=device-width, initial-scale=1">'
        + '<style>*{box-sizing:border-box}body{margin:0;padding:20px;background:#fff;font-family:Vazirmatn,Tahoma,sans-serif}img{max-width:100%;height:auto}a{text-decoration:none}'
        + String(el.css || '').replace(/</g, '\\3C ') + '</style></head><body>' + (el.html || '') + '</body></html>';
    const frame = document.getElementById('extract-preview-frame');
    frame.srcdoc = doc;
    /* 📏 ارتفاع خودکار — عنصر بزرگ (هدر/فوتر) بریده نشود */
    frame.onload = function () {
        try {
            const d = this.contentDocument;
            if (d) { this.style.minHeight = Math.max(440, d.documentElement.scrollHeight + 26) + 'px'; }
        } catch (e) { /* دسترسی متقاطع — همان حداقل */ }
    };
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
    /* 🌉 کادر زیبا (v2.28) */
    const okDel = await sahandConfirm({ title: 'حذف عنصر شخصی', message: 'این عنصر شخصی حذف شود؟', type: 'danger', confirmText: 'بله، حذف کن' });
    if (!okDel) { return; }
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
/* ⏪ v2.34 — نقطه صفر تاریخچه: وضعیت بارگذاری‌شده از دیتابیس */
histStack = [histSnapshot()];
histIndex = 0;
updateUndoButtons();
