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
