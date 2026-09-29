/**
 * 🧠 اسکریپت اصلی پنل مدیریت سایت ساز برند سهند سرویس
 * تعاملات: تب‌ها، مودال، حذف با تأیید، AJAX، سایدبار موبایل
 */

/* 📱 باز/بسته کردن سایدبار در موبایل — همراه با پس‌زمینه و قفل اسکرول */
function toggleSidebar(force) {
    var sidebar = document.querySelector('.sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    var btn = document.getElementById('menuToggleBtn');
    if (!sidebar) { return; }
    var willOpen = typeof force === 'boolean' ? force : !sidebar.classList.contains('open');
    sidebar.classList.toggle('open', willOpen);
    if (backdrop) { backdrop.classList.toggle('show', willOpen); }
    document.body.classList.toggle('no-scroll', willOpen);
    if (btn) { btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false'); }
}

/* 📱 بستن خودکار سایدبار با کلیک روی لینک‌های منو (فقط موبایل) */
document.addEventListener('click', function (e) {
    var link = e.target.closest('.sidebar .nav-link');
    if (link && window.matchMedia('(max-width: 992px)').matches) {
        toggleSidebar(false);
    }
});

/* ⌨️ بستن سایدبار و مودال‌ها با Escape */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        var sidebar = document.querySelector('.sidebar.open');
        if (sidebar) { toggleSidebar(false); }
    }
});

/* 🗂️ سیستم تب‌ها */
function switchTab(btn, paneId) {
    document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
    document.querySelectorAll('.tab-pane').forEach(function (p) { p.classList.remove('active'); });
    btn.classList.add('active');
    document.getElementById(paneId).classList.add('active');
}

/* 🖼️ باز و بسته کردن مودال */
function openModal(id) {
    document.getElementById(id).classList.add('show');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}
/* بستن مودال با کلیک روی پس‌زمینه */
document.addEventListener('click', function (e) {
    if (e.target.classList && e.target.classList.contains('modal-backdrop')) {
        e.target.classList.remove('show');
    }
});
/* بستن با کلید Escape */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.show').forEach(function (m) { m.classList.remove('show'); });
    }
});

/* 🗑️ حذف با تأیید — برای همه فرم‌های دارای data-confirm (کادر زیبای SahandDialog) */
document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.hasAttribute('data-confirm') && !form.hasAttribute('data-confirmed')) {
        e.preventDefault();
        var message = form.getAttribute('data-confirm') || 'آیا از انجام این عملیات مطمئن هستید؟';
        if (window.sahandConfirm) {
            sahandConfirm({ message: message, title: 'تأیید عملیات', type: 'warning', danger: true, confirmText: 'بله، انجام بده', confirmIcon: '🗑️' })
                .then(function (ok) {
                    if (ok) {
                        form.setAttribute('data-confirmed', '1');
                        form.submit();
                    }
                });
        } else if (!window.confirm(message)) {
            return;
        } else {
            form.setAttribute('data-confirmed', '1');
            form.submit();
        }
    }
});

/* 🔗 لینک‌های حذف با تأیید (کادر زیبا) */
document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm-link]');
    if (el && !el.hasAttribute('data-confirmed')) {
        e.preventDefault();
        var message = el.getAttribute('data-confirm-link') || 'آیا مطمئن هستید؟';
        var go = function () { el.setAttribute('data-confirmed', '1'); window.location.href = el.getAttribute('href'); };
        if (window.sahandConfirm) {
            sahandConfirm({ message: message, title: 'تأیید عملیات', type: 'warning', danger: true, confirmText: 'بله، ادامه بده', confirmIcon: '✅' }).then(function (ok) { if (ok) { go(); } });
        } else if (window.confirm(message)) {
            go();
        }
    }
});

/* 🔁 رفرش کپچا */
function refreshCaptcha(img) {
    img.src = img.src.split('?')[0] + '?t=' + Date.now();
}

/* 🌐 هلپر AJAX — ارسال فرم بدون رفرش */
function sahandFetch(url, options) {
    options = options || {};
    options.headers = Object.assign({
        'X-Requested-With': 'XMLHttpRequest'
    }, options.headers || {});
    if (options.json) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(options.json);
        delete options.json;
    }
    return fetch(url, options).then(function (res) { return res.json(); });
}

/* ➕ افزودن ردیف تکرارشونده (برای فرم‌های چندتایی مثل تلفن‌ها)
   v2.7: کلون تمیز از آخرین ردیف — دیگر هیچ رشته HTML تو-در-تویی داخل onclick
   گذاشته نمی‌شود (کوتیشن‌های تودرتو HTML را می‌شکستند و صفحه بهم‌ریخته می‌شد). */
var __repeatRowTemplates = {};
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[id^="rows-"]').forEach(function (container) {
        var first = container.querySelector('.repeat-row');
        if (first && !__repeatRowTemplates[container.id]) {
            __repeatRowTemplates[container.id] = first.outerHTML;
        }
    });
});
function addRepeatRow(containerId) {
    var container = document.getElementById(containerId);
    if (!container) { return; }
    var rows = container.querySelectorAll('.repeat-row');
    var clone;
    if (rows.length > 0) {
        clone = rows[rows.length - 1].cloneNode(true);
    } else if (__repeatRowTemplates[containerId]) {
        container.insertAdjacentHTML('beforeend', __repeatRowTemplates[containerId]);
        clone = container.querySelector('.repeat-row:last-child');
    } else {
        return;
    }
    clone.querySelectorAll('input, textarea').forEach(function (el) {
        if (el.type === 'checkbox' || el.type === 'radio') { el.checked = false; }
        else { el.value = ''; }
    });
    clone.querySelectorAll('select').forEach(function (el) { el.selectedIndex = 0; });
    container.appendChild(clone);
    var focusEl = clone.querySelector('input, textarea, select');
    if (focusEl) { try { focusEl.focus(); } catch (e) {} }
}

/* 🏢 افزودن شعبه جدید — نام مستعار سازگار با نسخه‌های قبلی */
function duplicateAddress() { addRepeatRow('rows-addresses'); }

/* 🌐 افزودن شبکه اجتماعی — نام مستعار سازگار با نسخه‌های قبلی */
function duplicateSocial() { addRepeatRow('rows-socials'); }

/* 🗑️ حذف ردیف تکرارشونده */
function removeRepeatRow(btn) {
    var row = btn.closest('.repeat-row');
    if (row) {
        row.remove();
    }
}

/* 📋 کپی متن در کلیپ‌بورد */
function copyText(text, btn) {
    var temp = document.createElement('input');
    document.body.appendChild(temp);
    temp.value = text;
    temp.select();
    document.execCommand('copy');
    document.body.removeChild(temp);
    if (btn) {
        var original = btn.innerHTML;
        btn.innerHTML = '✅ کپی شد';
        setTimeout(function () { btn.innerHTML = original; }, 1600);
    }
}

/* ⏳ دکمه در حال بارگذاری */
function setLoading(btn, loading) {
    if (loading) {
        btn.dataset.original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span> در حال پردازش...';
    } else {
        btn.disabled = false;
        if (btn.dataset.original) {
            btn.innerHTML = btn.dataset.original;
        }
    }
}

/* 🎨 پیش‌نمایش تصویر قبل از آپلود */
function previewImage(input, previewId) {
    var preview = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function (e) { preview.src = e.target.result; preview.style.display = 'block'; };
        reader.readAsDataURL(input.files[0]);
    }
}

/* 💬 نمایش پیام فلش خودکار مخفی‌شونده */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert-auto').forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity .5s';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 500);
        }, 4500);
    });
});

/* 📱 جدول‌های واکنش‌گرا (v2.7.2) — تبدیل خودکار جدول‌ها به کارت در موبایل
   ریشه مشکل: جدول چندستونه در عرض ۳۹۰px جا نمی‌شود؛ ستون‌ها به ۷۲px می‌رسند و
   متن هر سلول تا ۲۰ سطر می‌شکند. راه‌حل: در موبایل هر «ردیف» یک کارت می‌شود و
   هر سلول با برچسب ستونش (از thead) در یک خط افقی نمایش داده می‌شود.
   این تابع فقط data-label روی td ها می‌گذارد؛ چیدمان با CSS انجام می‌شود.
   برای محتوای AJAX هم global است: window.sahandLabelTables() */
function sahandLabelTables(root) {
    'use strict';
    (root || document).querySelectorAll('table.table').forEach(function (table) {
        var thead = table.querySelector('thead');
        if (!thead) { return; }
        var labels = [];
        thead.querySelectorAll('th').forEach(function (th) {
            labels.push((th.textContent || '').trim());
        });
        if (!labels.length) { return; }
        table.querySelectorAll('tbody tr').forEach(function (tr) {
            if (tr.classList.contains('repeat-row')) { return; }
            tr.querySelectorAll(':scope > td').forEach(function (td, i) {
                if (i < labels.length && labels[i]) {
                    td.setAttribute('data-label', labels[i]);
                }
            });
        });
    });
}
window.sahandLabelTables = sahandLabelTables;
document.addEventListener('DOMContentLoaded', function () { sahandLabelTables(); });



/* ═══════════════════════════════════════════════════════════════
 * 🖼️ v2.40 — لایت‌باکس آواتار: کلیک روی آواتار = نمایش تصویر بزرگ
 * (درخواست کاربر: «برای پروفایل‌ها، با کلیک روی آواتار تصویر
 *  بزرگش را نشان بده») — مشترک بین پروفایل/کاربران/داشبورد.
 * استفاده: <img class="avatar-zoom" data-name="نام کاربر" src="...">
 * یا فراخوانی مستقیم: openAvatarLightbox(src, name)
 * ═══════════════════════════════════════════════════════════════ */
function openAvatarLightbox(src, name) {
    if (!src) { return; }
    var old = document.getElementById('avatarLightbox');
    if (old) { old.remove(); }
    var box = document.createElement('div');
    box.id = 'avatarLightbox';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', 'نمایش بزرگ آواتار');
    box.style.cssText = 'position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:14px;background:rgba(15,23,42,.88);backdrop-filter:blur(6px);cursor:zoom-out;padding:24px';
    var img = document.createElement('img');
    img.src = src;
    img.alt = name ? ('آواتار ' + name) : 'آواتار';
    img.style.cssText = 'max-width:min(560px,92vw);max-height:70vh;border-radius:24px;object-fit:contain;box-shadow:0 24px 70px rgba(0,0,0,.55);border:4px solid rgba(255,255,255,.92);animation:avatarZoomIn .28s cubic-bezier(.2,.9,.3,1.2)';
    var cap = document.createElement('div');
    cap.textContent = name || '';
    cap.style.cssText = (name ? '' : 'display:none;') + 'color:#f1f5f9;font-weight:800;font-size:15px;text-shadow:0 2px 8px rgba(0,0,0,.5);background:rgba(255,255,255,.12);padding:7px 20px;border-radius:99px;backdrop-filter:blur(4px)';
    var hint = document.createElement('div');
    hint.textContent = '🔓 برای بستن کلیک کنید یا Escape را بزنید';
    hint.style.cssText = 'color:#cbd5e1;font-size:11.5px;opacity:.85';
    box.appendChild(img);
    box.appendChild(cap);
    box.appendChild(hint);
    var style = document.createElement('style');
    style.textContent = '@keyframes avatarZoomIn{from{transform:scale(.55);opacity:0}to{transform:scale(1);opacity:1}}';
    box.appendChild(style);
    function close() { box.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    box.addEventListener('click', close);
    document.addEventListener('keydown', onKey);
    document.body.appendChild(box);
}
window.openAvatarLightbox = openAvatarLightbox;

/* 🖼️ هر IMG با کلاس avatar-zoom → کلیک = لایت‌باکس (title هم نام کاربر) */
document.addEventListener('click', function (e) {
    var av = e.target.closest ? e.target.closest('img.avatar-zoom') : null;
    if (av && av.src) {
        e.preventDefault();
        e.stopPropagation();
        openAvatarLightbox(av.src, av.getAttribute('data-name') || av.getAttribute('title') || '');
    }
});
