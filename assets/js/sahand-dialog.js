/**
 * 💬 SahandDialog — سیستم کادرهای تعاملی زیبای سایت‌ساز
 * =====================================================
 * جایگزین کامل و زیبا برای alert / confirm / prompt پیش‌فرض مرورگر.
 *
 * API (همه Promise-محور — با async/await استفاده کنید):
 * ─────────────────────────────────────────────────────────
 * sahandAlert  ({ title, message, type, confirmText, icon })            → Promise<void>
 * sahandConfirm({ title, message, type, confirmText, cancelText, icon })→ Promise<boolean>
 * sahandPrompt ({ title, message, label, value, placeholder, ... })     → Promise<string|null>
 * sahandForm   ({ title, message, fields:[...], confirmText })          → Promise<object|null>
 * sahandToast  ({ message, type, duration, position })                  → void (غیرمسدودکننده)
 *
 * type: 'success' | 'info' | 'warning' | 'danger' | 'question'  (رنگ و آیکون خودکار)
 * fields: [{ type:'text'|'textarea'|'password'|'number'|'email'|'url'|'select'|'checkbox'|'radio',
 *            name, label, value, placeholder, hint, options:[{value,label}], required, checked }]
 *
 * 🎨 ویژگی‌ها: RTL کامل، آیکون و رنگ مرتبط با موضوع، دکمه‌های زیبای آیکون‌دار،
 *    ورودی متن/چک‌باکس/رادیو/سلکت، انیمیشن نرم، بستن با Escape/کلیک بیرون،
 *    بدون هیچ وابستگی (Vanilla JS)
 */
(function (global) {
    'use strict';

    /* ---------- 🎨 تنظیمات ظاهری هر نوع ---------- */
    var TYPES = {
        success:  { icon: '✅', color: '#16a34a', bg: '#f0fdf4', border: '#bbf7d0', title: 'انجام شد' },
        info:     { icon: 'ℹ️', color: '#2563eb', bg: '#eff6ff', border: '#bfdbfe', title: 'اطلاع' },
        warning:  { icon: '⚠️', color: '#d97706', bg: '#fffbeb', border: '#fde68a', title: 'توجه' },
        danger:   { icon: '⛔', color: '#dc2626', bg: '#fef2f2', border: '#fecaca', title: 'هشدار' },
        question: { icon: '❓', color: '#7c3aed', bg: '#f5f3ff', border: '#ddd6fe', title: 'تأیید' },
    };

    var zIndex = 10000;

    /* ---------- 🏗️ ساخت زیرساخت DOM یک‌باره ---------- */
    function ensureStyle() {
        if (document.getElementById('sahand-dialog-style')) { return; }
        var css = [
            '.sd-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(3px);z-index:' + zIndex + ';display:flex;align-items:center;justify-content:center;padding:18px;opacity:0;transition:opacity .22s ease;font-family:inherit}',
            '.sd-backdrop.sd-show{opacity:1}',
            '.sd-box{background:#fff;border-radius:16px;width:100%;max-width:440px;max-height:88vh;overflow-y:auto;box-shadow:0 24px 64px rgba(0,0,0,.28);transform:translateY(14px) scale(.97);transition:transform .22s cubic-bezier(.2,.9,.3,1.2);display:flex;flex-direction:column}',
            '.sd-backdrop.sd-show .sd-box{transform:none}',
            '.sd-head{display:flex;align-items:center;gap:12px;padding:18px 20px 10px}',
            '.sd-icon{width:48px;height:48px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0}',
            '.sd-title{font-size:15.5px;font-weight:800;flex:1;min-width:0}',
            '.sd-close{border:none;background:none;font-size:17px;cursor:pointer;color:#94a3b8;padding:4px 8px;border-radius:8px;line-height:1}',
            '.sd-close:hover{background:#f1f5f9;color:#334155}',
            '.sd-body{padding:4px 20px 8px;font-size:13.5px;line-height:2;color:#334155}',
            '.sd-msg{white-space:pre-line;overflow-wrap:anywhere}',
            '.sd-fields{margin-top:12px;display:flex;flex-direction:column;gap:13px}',
            '.sd-field label{display:block;font-size:12.5px;font-weight:700;margin-bottom:6px}',
            '.sd-field .sd-hint{font-size:11px;color:#94a3b8;margin-top:4px}',
            '.sd-input,.sd-select,.sd-textarea{width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-family:inherit;font-size:13.5px;background:#f8fafc;color:#0f172a;transition:border-color .15s,box-shadow .15s;box-sizing:border-box}',
            '.sd-input:focus,.sd-select:focus,.sd-textarea:focus{outline:none;border-color:#2563eb;background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.14)}',
            '.sd-textarea{min-height:84px;resize:vertical}',
            '.sd-check{display:flex;align-items:center;gap:9px;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;background:#f8fafc;cursor:pointer;font-size:13px}',
            '.sd-check input{width:17px;height:17px;accent-color:#2563eb;cursor:pointer;flex-shrink:0}',
            '.sd-check input:checked + span{font-weight:700}',
            '.sd-radio{display:flex;align-items:center;gap:8px;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:10px;background:#f8fafc;cursor:pointer;font-size:13px}',
            '.sd-radio input{accent-color:#2563eb}',
            '.sd-foot{display:flex;gap:10px;justify-content:flex-start;padding:14px 20px 18px;flex-wrap:wrap}',
            '.sd-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 20px;border-radius:11px;border:none;font-family:inherit;font-size:13.5px;font-weight:700;cursor:pointer;transition:transform .12s,box-shadow .15s,background .15s;min-width:110px}',
            '.sd-btn:active{transform:scale(.97)}',
            '.sd-btn:disabled{opacity:.55;cursor:not-allowed}',
            '.sd-btn-primary{background:#1e40af;color:#fff;box-shadow:0 4px 14px rgba(30,64,175,.3)}',
            '.sd-btn-primary:hover{background:#1d4ed8;box-shadow:0 6px 18px rgba(30,64,175,.38)}',
            '.sd-btn-danger{background:#dc2626;color:#fff;box-shadow:0 4px 14px rgba(220,38,38,.3)}',
            '.sd-btn-danger:hover{background:#b91c1c}',
            '.sd-btn-success{background:#16a34a;color:#fff;box-shadow:0 4px 14px rgba(22,163,74,.3)}',
            '.sd-btn-success:hover{background:#15803d}',
            '.sd-btn-outline{background:#fff;color:#334155;border:1.5px solid #e2e8f0}',
            '.sd-btn-outline:hover{background:#f8fafc;border-color:#cbd5e1}',
            '.sd-toast-wrap{position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:' + (zIndex + 50) + ';display:flex;flex-direction:column;gap:9px;align-items:center;pointer-events:none;width:min(92vw,460px)}',
            '.sd-toast{pointer-events:auto;display:flex;align-items:center;gap:10px;padding:11px 18px;border-radius:12px;background:#fff;box-shadow:0 10px 32px rgba(0,0,0,.18);font-size:13px;font-weight:600;border-inline-start:4px solid;animation:sdToastIn .28s cubic-bezier(.2,.9,.3,1.2);max-width:100%}',
            '.sd-toast.sd-hide{animation:sdToastOut .25s forwards}',
            '@keyframes sdToastIn{from{opacity:0;transform:translateY(-14px) scale(.94)}to{opacity:1;transform:none}}',
            '@keyframes sdToastOut{to{opacity:0;transform:translateY(-10px) scale(.95)}}',
            '@media (max-width:520px){.sd-box{max-width:100%}.sd-btn{flex:1;min-width:0}}',
        ].join('\n');
        var st = document.createElement('style');
        st.id = 'sahand-dialog-style';
        st.textContent = css;
        document.head.appendChild(st);
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /* ---------- 🔧 هسته ساخت دیالوگ ---------- */
    /**
     * ساخت دیالوگ عمومی
     * @param {object} opts {title, message, type, icon, fields, buttons:[{text,type,icon,value,close}], allowOutsideClose}
     * @returns {{close:Function, onClose:Function}} — onClose(cb) برای نتیجه
     */
    function dialog(opts) {
        ensureStyle();
        var type = TYPES[opts.type] ? opts.type : 'info';
        var t = TYPES[type];
        var iconHtml = '<div class="sd-icon" style="background:' + t.bg + ';border:1.5px solid ' + t.border + '">' + (opts.icon || t.icon) + '</div>';
        var id = 'sd' + (++dialog._seq);

        var box = document.createElement('div');
        box.className = 'sd-box';
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');

        var html = '<div class="sd-head">' + iconHtml +
            '<div class="sd-title" style="color:' + t.color + '">' + esc(opts.title || t.title) + '</div>' +
            '<button type="button" class="sd-close" aria-label="بستن">✕</button></div>';
        if (opts.message) {
            html += '<div class="sd-body"><div class="sd-msg">' + esc(opts.message) + '</div></div>';
        }

        /* فیلدهای فرم */
        var fields = opts.fields || [];
        if (fields.length) {
            html += '<div class="sd-body"><div class="sd-fields">';
            fields.forEach(function (f) {
                var fid = id + '-' + f.name;
                if (f.type === 'checkbox') {
                    html += '<div class="sd-field"><label class="sd-check" for="' + fid + '">' +
                        '<input type="checkbox" id="' + fid + '" data-sdfield="' + esc(f.name) + '"' + (f.checked || f.value ? ' checked' : '') + '>' +
                        '<span>' + esc(f.label) + (f.required ? ' <b style="color:#dc2626">*</b>' : '') + '</span></label>' +
                        (f.hint ? '<div class="sd-hint">' + esc(f.hint) + '</div>' : '') + '</div>';
                } else if (f.type === 'radio') {
                    html += '<div class="sd-field"><label style="font-size:12.5px;font-weight:700;display:block;margin-bottom:6px">' + esc(f.label) + '</label>';
                    (f.options || []).forEach(function (o, i) {
                        html += '<label class="sd-radio"><input type="radio" name="' + esc(fid) + '" data-sdradio="' + esc(f.name) + '" value="' + esc(o.value) + '"' +
                            ((String(f.value) === String(o.value)) || (!f.value && i === 0) ? ' checked' : '') + '> ' + esc(o.label) + '</label>';
                    });
                    html += (f.hint ? '<div class="sd-hint">' + esc(f.hint) + '</div>' : '') + '</div>';
                } else if (f.type === 'select') {
                    html += '<div class="sd-field"><label for="' + fid + '">' + esc(f.label) + (f.required ? ' <b style="color:#dc2626">*</b>' : '') + '</label>' +
                        '<select id="' + fid + '" class="sd-select" data-sdfield="' + esc(f.name) + '"' + (f.required ? ' data-sdrequired="1"' : '') + '>';
                    (f.options || []).forEach(function (o) {
                        html += '<option value="' + esc(o.value) + '"' + (String(f.value) === String(o.value) ? ' selected' : '') + '>' + esc(o.label) + '</option>';
                    });
                    html += '</select>' + (f.hint ? '<div class="sd-hint">' + esc(f.hint) + '</div>' : '') + '</div>';
                } else if (f.type === 'textarea') {
                    html += '<div class="sd-field"><label for="' + fid + '">' + esc(f.label) + (f.required ? ' <b style="color:#dc2626">*</b>' : '') + '</label>' +
                        '<textarea id="' + fid + '" class="sd-textarea" data-sdfield="' + esc(f.name) + '"' + (f.required ? ' data-sdrequired="1"' : '') +
                        ' placeholder="' + esc(f.placeholder || '') + '">' + esc(f.value || '') + '</textarea>' +
                        (f.hint ? '<div class="sd-hint">' + esc(f.hint) + '</div>' : '') + '</div>';
                } else {
                    html += '<div class="sd-field"><label for="' + fid + '">' + esc(f.label) + (f.required ? ' <b style="color:#dc2626">*</b>' : '') + '</label>' +
                        '<input type="' + esc(f.type || 'text') + '" id="' + fid + '" class="sd-input" data-sdfield="' + esc(f.name) + '"' +
                        (f.required ? ' data-sdrequired="1"' : '') + ' value="' + esc(f.value || '') + '" placeholder="' + esc(f.placeholder || '') + '"' +
                        (f.dir === 'ltr' ? ' style="direction:ltr;text-align:left"' : '') + '>' +
                        (f.hint ? '<div class="sd-hint">' + esc(f.hint) + '</div>' : '') + '</div>';
                }
            });
            html += '</div></div>';
        }

        /* دکمه‌ها */
        var buttons = opts.buttons || [];
        html += '<div class="sd-foot">';
        buttons.forEach(function (b, i) {
            html += '<button type="button" class="sd-btn sd-btn-' + esc(b.btn || 'outline') + '" data-sdbtn="' + i + '">' +
                (b.icon ? b.icon + ' ' : '') + esc(b.text) + '</button>';
        });
        html += '</div>';
        box.innerHTML = html;

        var backdrop = document.createElement('div');
        backdrop.className = 'sd-backdrop';
        backdrop.id = id;
        backdrop.appendChild(box);
        document.body.appendChild(backdrop);
        requestAnimationFrame(function () { backdrop.classList.add('sd-show'); });

        var done = false;
        function close(value) {
            if (done) { return; }
            done = true;
            /* جمع‌آوری مقادیر فرم */
            var data = null;
            if (fields.length) {
                data = {};
                box.querySelectorAll('[data-sdfield]').forEach(function (el) {
                    if (el.type === 'checkbox') {
                        data[el.getAttribute('data-sdfield')] = el.checked;
                    } else {
                        data[el.getAttribute('data-sdfield')] = el.value;
                    }
                });
                box.querySelectorAll('[data-sdradio]').forEach(function (el) {
                    if (el.checked) { data[el.getAttribute('data-sdradio')] = el.value; }
                });
            }
            var result = { value: value, data: data };
            backdrop.classList.remove('sd-show');
            setTimeout(function () { backdrop.remove(); }, 240);
            (callbacks || []).forEach(function (cb) { try { cb(result); } catch (e) {} });
        }

        var callbacks = [];
        box.querySelector('.sd-close').addEventListener('click', function () { close(null); });
        if (opts.allowOutsideClose !== false) {
            backdrop.addEventListener('click', function (e) { if (e.target === backdrop) { close(null); } });
        }
        var escHandler = function (e) {
            if (e.key === 'Escape' && !done) { close(null); document.removeEventListener('keydown', escHandler); }
        };
        document.addEventListener('keydown', escHandler);
        buttons.forEach(function (b, i) {
            var el = box.querySelector('[data-sdbtn="' + i + '"]');
            if (el) {
                el.addEventListener('click', function () {
                    /* اعتبارسنجی فیلدهای الزامی */
                    var invalid = null;
                    box.querySelectorAll('[data-sdrequired]').forEach(function (f) {
                        f.style.borderColor = '#e2e8f0';
                        if (!invalid && String(f.value || '').trim() === '') { invalid = f; }
                    });
                    if (invalid) {
                        invalid.style.borderColor = '#dc2626';
                        invalid.focus();
                        toast({ message: 'تکمیل این فیلد الزامی است: ' + (invalid.previousElementSibling ? invalid.previousElementSibling.textContent : ''), type: 'warning' });
                        return;
                    }
                    close(b.value !== undefined ? b.value : b.text);
                });
            }
        });
        /* فوکوس خودکار روی اولین ورودی یا دکمه اصلی */
        setTimeout(function () {
            var first = box.querySelector('.sd-input, .sd-select, .sd-textarea');
            if (first) { first.focus(); } else { var pb = box.querySelector('.sd-btn-primary, .sd-btn-danger, .sd-btn-success'); if (pb) { pb.focus(); } }
        }, 120);

        return {
            close: close,
            onClose: function (cb) { callbacks.push(cb); }
        };
    }
    dialog._seq = 0;

    /* ---------- 📣 API عمومی ---------- */

    /** اطلاع‌رسانی (جایگزین alert) */
    function sahandAlert(opts) {
        opts = typeof opts === 'string' ? { message: opts } : (opts || {});
        var type = opts.type || 'info';
        return new Promise(function (resolve) {
            dialog({
                title: opts.title,
                message: opts.message,
                type: type,
                icon: opts.icon,
                fields: opts.fields,
                buttons: [{
                    text: opts.confirmText || 'متوجه شدم',
                    btn: type === 'success' ? 'success' : (type === 'danger' ? 'danger' : 'primary'),
                    icon: opts.confirmIcon || '👌',
                    value: true,
                }],
            }).onClose(function () { resolve(true); });
        });
    }

    /** تأیید (جایگزین confirm) — resolve(true/false) */
    function sahandConfirm(opts) {
        opts = typeof opts === 'string' ? { message: opts } : (opts || {});
        var type = opts.type || 'question';
        return new Promise(function (resolve) {
            dialog({
                title: opts.title,
                message: opts.message,
                type: type,
                icon: opts.icon,
                fields: opts.fields,
                buttons: [
                    { text: opts.confirmText || 'بله، ادامه بده', btn: opts.danger ? 'danger' : 'primary', icon: opts.confirmIcon || '✅', value: true },
                    { text: opts.cancelText || 'انصراف', btn: 'outline', icon: '✖️', value: false },
                ],
            }).onClose(function (r) { resolve(r.value === true); });
        });
    }

    /** ورودی متن (جایگزین prompt) — resolve(string|null) */
    function sahandPrompt(opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            dialog({
                title: opts.title,
                message: opts.message,
                type: opts.type || 'info',
                fields: [{
                    type: opts.inputType || 'text',
                    name: 'value',
                    label: opts.label || 'مقدار',
                    value: opts.value || '',
                    placeholder: opts.placeholder || '',
                    required: opts.required !== false,
                    hint: opts.hint,
                    dir: opts.dir,
                }],
                buttons: [
                    { text: opts.confirmText || 'تأیید', btn: 'primary', icon: '✅', value: true },
                    { text: opts.cancelText || 'انصراف', btn: 'outline', icon: '✖️', value: false },
                ],
            }).onClose(function (r) { resolve(r.value === true && r.data ? String(r.data.value) : null); });
        });
    }

    /** فرم چندفیلدی — resolve(object|null) */
    function sahandForm(opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            dialog({
                title: opts.title,
                message: opts.message,
                type: opts.type || 'info',
                icon: opts.icon,
                fields: opts.fields || [],
                buttons: [
                    { text: opts.confirmText || 'ثبت', btn: 'primary', icon: opts.confirmIcon || '💾', value: true },
                    { text: opts.cancelText || 'انصراف', btn: 'outline', icon: '✖️', value: false },
                ],
            }).onClose(function (r) { resolve(r.value === true ? r.data : null); });
        });
    }

    /** توست (اعلان غیرمسدودکننده) */
    var toastWrap = null;
    function toast(opts) {
        ensureStyle();
        opts = typeof opts === 'string' ? { message: opts } : (opts || {});
        var type = TYPES[opts.type] ? opts.type : 'info';
        var t = TYPES[type];
        if (!toastWrap || !document.body.contains(toastWrap)) {
            toastWrap = document.createElement('div');
            toastWrap.className = 'sd-toast-wrap';
            document.body.appendChild(toastWrap);
        }
        var el = document.createElement('div');
        el.className = 'sd-toast';
        el.style.borderColor = t.color;
        el.style.background = t.bg;
        el.innerHTML = '<span style="font-size:17px">' + (opts.icon || t.icon) + '</span><span style="color:' + t.color + '">' + esc(opts.message) + '</span>';
        toastWrap.appendChild(el);
        var dur = opts.duration || 3800;
        setTimeout(function () {
            el.classList.add('sd-hide');
            setTimeout(function () { el.remove(); }, 260);
        }, dur);
        el.addEventListener('click', function () { el.remove(); });
    }

    /* میانبرهای نوع‌دار */
    var api = {
        dialog: dialog,
        alert: sahandAlert,
        confirm: sahandConfirm,
        prompt: sahandPrompt,
        form: sahandForm,
        toast: toast,
        success: function (m, t) { return sahandAlert({ message: m, title: t, type: 'success' }); },
        error: function (m, t) { return sahandAlert({ message: m, title: t || 'خطا', type: 'danger' }); },
        info: function (m, t) { return sahandAlert({ message: m, title: t, type: 'info' }); },
        warning: function (m, t) { return sahandAlert({ message: m, title: t, type: 'warning' }); },
        toastSuccess: function (m) { toast({ message: m, type: 'success' }); },
        toastError: function (m) { toast({ message: m, type: 'danger', duration: 5200 }); },
        toastInfo: function (m) { toast({ message: m, type: 'info' }); },
        toastWarning: function (m) { toast({ message: m, type: 'warning' }); },
    };

    global.SahandDialog = api;
    global.sahandAlert = sahandAlert;
    global.sahandConfirm = sahandConfirm;
    global.sahandPrompt = sahandPrompt;
    global.sahandForm = sahandForm;
    global.sahandToast = toast;
})(window);
