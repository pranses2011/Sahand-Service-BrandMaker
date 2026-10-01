/**
 * ✍️ RichEditor — ویرایشگر متن حرفه‌ای بومی (S13 / v2.44)
 * ============================================================
 * درخواست کاربر: «برای محتوای مقالات و همچنین محتوای صفحات برندها یک
 * ویرایشگر متن حرفه‌ای کامل بگذار تا حرفه‌ای بشه.»
 *
 * 🚫 بدون CDN/وابستگی — وانیلا JS خالص، سازگار با RTL فارسی
 * 🧩 RichEditor.create(selectorOrTextarea, options)
 *    → textarea مخفی می‌شود، ولی مقدارش همیشه همگام می‌ماند (فرم ساده)
 *
 * امکانات:
 *   قالب‌بندی: Bold/Italic/Underline/Strike | H2/H3/P | فونت‌اندازه
 *   لیست‌ها: نقطه‌ای/شماره‌ای | نقل‌قول | کد درون‌خطی
 *   چیدمان: راست/وسط/چپ/تراز دوطرفه | تورفتگی
 *   درج: لینک | تصویر (آپلود/کتابخانه/URL) | جدول | خط جداکننده
 *   رنگ: متن | هایلایت
 *   ابزار: Undo/Redo | پاک‌سازی قالب | نمای کد HTML | شمارش کلمه/کاراکتر
 *   ایمنی: خروجی پاک‌سازی‌شده سمت سرور (Validator::sanitizeHtml موجود)
 *   میانبرها: Ctrl+B/I/U | Ctrl+Z/Y | Ctrl+K لینک | Ctrl+S ذخیره فرم
 */
(function () {
    'use strict';

    var RichEditor = {};

    /* ── آیکون‌های SVG خطی (بدون وابستگی) ── */
    function svg(paths, size) {
        return '<svg width="' + (size || 17) + '" height="' + (size || 17) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + paths + '</svg>';
    }
    var IC = {
        undo: svg('<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/>'),
        redo: svg('<path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3L21 13"/>'),
        bold: svg('<path d="M6 4h8a4 4 0 0 1 0 8H6z"/><path d="M6 12h9a4 4 0 0 1 0 8H6z"/>'),
        italic: svg('<line x1="19" y1="4" x2="10" y2="4"/><line x1="14" y1="20" x2="5" y2="20"/><line x1="15" y1="4" x2="9" y2="20"/>'),
        underline: svg('<path d="M6 3v7a6 6 0 0 0 6 6 6 6 0 0 0 6-6V3"/><line x1="4" y1="21" x2="20" y2="21"/>'),
        strike: svg('<path d="M16 4H9a3 3 0 0 0-2.83 4"/><path d="M14 12a4 4 0 0 1 0 8H6"/><line x1="4" y1="12" x2="20" y2="12"/>'),
        h2: '<b style="font-size:12.5px">H2</b>',
        h3: '<b style="font-size:11px">H3</b>',
        ul: svg('<line x1="9" y1="6" x2="20" y2="6"/><line x1="9" y1="12" x2="20" y2="12"/><line x1="9" y1="18" x2="20" y2="18"/><circle cx="4.5" cy="6" r="1.4" fill="currentColor"/><circle cx="4.5" cy="12" r="1.4" fill="currentColor"/><circle cx="4.5" cy="18" r="1.4" fill="currentColor"/>'),
        ol: svg('<line x1="10" y1="6" x2="20" y2="6"/><line x1="10" y1="12" x2="20" y2="12"/><line x1="10" y1="18" x2="20" y2="18"/><text x="3" y="8" font-size="7" fill="currentColor" stroke="none" font-weight="700">۱</text><text x="3" y="14.5" font-size="7" fill="currentColor" stroke="none" font-weight="700">۲</text><text x="3" y="20.5" font-size="7" fill="currentColor" stroke="none" font-weight="700">۳</text>'),
        quote: svg('<path d="M3 21c3-1 5-3 5-8H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v5c0 5-2 8-6 9z" transform="translate(12,0)"/><path d="M3 21c3-1 5-3 5-8H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v5c0 5-2 8-6 9z"/>'),
        code: svg('<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>'),
        link: svg('<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>'),
        image: svg('<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>'),
        table: svg('<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="12" y1="3" x2="12" y2="21"/>'),
        hr: svg('<line x1="3" y1="12" x2="21" y2="12"/>'),
        alignRight: svg('<line x1="21" y1="6" x2="9" y2="6"/><line x1="21" y1="12" x2="3" y2="12"/><line x1="21" y1="18" x2="9" y2="18"/>'),
        alignCenter: svg('<line x1="18" y1="6" x2="6" y2="6"/><line x1="21" y1="12" x2="3" y2="12"/><line x1="18" y1="18" x2="6" y2="18"/>'),
        alignLeft: svg('<line x1="3" y1="6" x2="15" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="15" y2="18"/>'),
        alignJustify: svg('<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>'),
        clear: svg('<path d="M20 12a8 8 0 1 1-2.34-5.66"/><polyline points="20 4 20 8 16 8"/>'),
        source: svg('<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/><line x1="10" y1="4" x2="14" y2="20" opacity=".45"/>'),
        trash: svg('<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'),
        /* 🆕 v2.45 (S13) — ابزارهای حرفه‌ای جدید */
        sub: '<b style="font-size:13px">x<span style="font-size:9px;vertical-align:sub">2</span></b>',
        sup: '<b style="font-size:13px">x<span style="font-size:9px;vertical-align:super">2</span></b>',
        indent: svg('<line x1="21" y1="6" x2="9" y2="6"/><line x1="21" y1="12" x2="3" y2="12"/><line x1="21" y1="18" x2="9" y2="18"/><polyline points="5 9 2 12 5 15"/>'),
        outdent: svg('<line x1="21" y1="6" x2="9" y2="6"/><line x1="21" y1="12" x2="3" y2="12"/><line x1="21" y1="18" x2="9" y2="18"/><polyline points="7 9 4 12 7 15"/>'),
        search: svg('<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/>'),
        expand: svg('<path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M16 3h3a2 2 0 0 1 2 2v3"/><path d="M8 21H5a2 2 0 0 1-2-2v-3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/>'),
        emoji: svg('<circle cx="12" cy="12" r="9"/><path d="M8.5 14.5s1.2 1.8 3.5 1.8 3.5-1.8 3.5-1.8"/><circle cx="9" cy="9.5" r=".8" fill="currentColor"/><circle cx="15" cy="9.5" r=".8" fill="currentColor"/>'),
        plainPaste: svg('<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><line x1="8" y1="11" x2="16" y2="11" opacity=".5"/><line x1="8" y1="15" x2="13" y2="15" opacity=".5"/>'),
    };

    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

    /* ═══════════ ساخت ویرایشگر روی یک textarea ═══════════ */
    RichEditor.create = function (el, options) {
        options = options || {};
        if (typeof el === 'string') { el = document.querySelector(el); }
        if (!el || el.getAttribute('data-rich-editor') === '1') { return null; }
        el.setAttribute('data-rich-editor', '1');
        el.style.display = 'none';

        var wrap = document.createElement('div');
        wrap.className = 're-wrap';
        wrap.setAttribute('dir', 'rtl');

        /* ── نوار ابزار ── */
        var bar = document.createElement('div');
        bar.className = 're-toolbar';
        var groups = [
            [
                ['undo', 'واگرد (Ctrl+Z)', function () { exec('undo'); }],
                ['redo', 'ازنو (Ctrl+Y)', function () { exec('redo'); }],
            ],
            [
                ['bold', 'درشت (Ctrl+B)', function () { exec('bold'); }, 're-b'],
                ['italic', 'مورب (Ctrl+I)', function () { exec('italic'); }, 're-i'],
                ['underline', 'زیرخط (Ctrl+U)', function () { exec('underline'); }],
                ['strike', 'خط‌خورده', function () { exec('strikeThrough'); }],
                ['code', 'کد درون‌خطی', function () { execInlineCode(); }],
            ],
            [
                ['h2', 'تیتر بخش (H2)', function () { exec('formatBlock', '<h2>'); }],
                ['h3', 'تیتر فرعی (H3)', function () { exec('formatBlock', '<h3>'); }],
                ['quote', 'نقل‌قول', function () { exec('formatBlock', '<blockquote>'); }],
            ],
            [
                ['ul', 'لیست نقطه‌ای', function () { exec('insertUnorderedList'); }],
                ['ol', 'لیست شماره‌ای', function () { exec('insertOrderedList'); }],
            ],
            [
                ['alignRight', 'راست‌چین', function () { exec('justifyRight'); }],
                ['alignCenter', 'وسط‌چین', function () { exec('justifyCenter'); }],
                ['alignLeft', 'چپ‌چین', function () { exec('justifyLeft'); }],
                ['alignJustify', 'تراز دوطرفه', function () { exec('justifyFull'); }],
            ],
            [
                ['link', 'لینک (Ctrl+K)', function () { insertLink(); }],
                ['image', 'تصویر (آپلود/کتابخانه/لینک)', function () { insertImage(); }],
                ['table', 'جدول', function () { insertTable(); }],
                ['hr', 'خط جداکننده', function () { exec('insertHorizontalRule'); }],
            ],
            /* 🆕 v2.45 (S13) — بالا/پایین‌نویس + تورفتگی */
            [
                ['sub', 'پایین‌نویس (x₂)', function () { exec('subscript'); }],
                ['sup', 'بالانویس (x²)', function () { exec('superscript'); }],
                ['indent', 'افزایش تورفتگی', function () { exec('indent'); }],
                ['outdent', 'کاهش تورفتگی', function () { exec('outdent'); }],
            ],
            /* 🆕 v2.45 (S13) — جستجو/جایگزینی + نمادها + تمام‌صفحه */
            [
                ['search', 'جستجو و جایگزینی (Ctrl+F)', function () { findReplace(); }],
                ['emoji', 'نمادهای ویژه', function () { specialChars(); }],
                ['expand', 'حالت تمام‌صفحه (تمرکز)', function () { toggleFullscreen(); }],
            ],
            [
                ['clear', 'پاک‌سازی قالب', function () { exec('removeFormat'); exec('formatBlock', '<p>'); }],
                ['source', 'نمای کد HTML', function () { toggleSource(); }],
            ],
        ];
        groups.forEach(function (g, gi) {
            var gdiv = document.createElement('div');
            gdiv.className = 're-tgroup';
            g.forEach(function (b) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 're-btn' + (b[3] ? ' ' + b[3] : '');
                btn.title = b[1];
                btn.innerHTML = IC[b[0]];
                btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    b[2]();
                    sync();
                });
                gdiv.appendChild(btn);
            });
            bar.appendChild(gdiv);
            if (gi < groups.length - 1) {
                var sep = document.createElement('i');
                sep.className = 're-sep';
                bar.appendChild(sep);
            }
        });
        /* رنگ متن + هایلایت */
        var colorWrap = document.createElement('div');
        colorWrap.className = 're-tgroup';
        colorWrap.innerHTML =
            '<label class="re-btn re-color" title="رنگ متن">A<input type="color" class="re-color-in" value="#1e3a8a"></label>' +
            '<label class="re-btn re-hl" title="هایلایت (رنگ پس‌زمینه)">🖍<input type="color" class="re-color-in" value="#fef08a"></label>';
        bar.appendChild(colorWrap);
        colorWrap.querySelector('.re-color .re-color-in').addEventListener('input', function () {
            focusEd();
            document.execCommand('styleWithCSS', false, true);
            document.execCommand('foreColor', false, this.value);
            document.execCommand('styleWithCSS', false, false);
            sync();
        });
        colorWrap.querySelector('.re-hl .re-color-in').addEventListener('input', function () {
            focusEd();
            document.execCommand('styleWithCSS', false, true);
            document.execCommand('hiliteColor', false, this.value);
            document.execCommand('styleWithCSS', false, false);
            sync();
        });

        /* 🆕 v2.45 (S13) — اندازه فونت (بازشو) + چسباندن متنی خام */
        var fsWrap = document.createElement('div');
        fsWrap.className = 're-tgroup';
        var fsSel = document.createElement('select');
        fsSel.className = 're-select';
        fsSel.title = 'اندازه فونت متن انتخاب‌شده';
        fsSel.innerHTML = '<option value="">اندازه</option>' +
            ['12', '14', '16', '18', '20', '24', '30'].map(function (s) { return '<option value="' + s + '"' + '>' + s + 'px</option>'; }).join('');
        fsSel.addEventListener('change', function () {
            var v = fsSel.value;
            fsSel.selectedIndex = 0;
            if (!v) { return; }
            var sel = window.getSelection();
            var txt = sel ? sel.toString() : '';
            if (!txt) { return; }
            insertAtCursor('<span style="font-size:' + parseInt(v, 10) + 'px">' + esc(txt) + '</span>');
        });
        fsWrap.appendChild(fsSel);
        var plainBtn = document.createElement('button');
        plainBtn.type = 'button';
        plainBtn.className = 're-btn re-plain';
        plainBtn.title = 'چسباندن متنی خام (بدون قالب) — روشن/خاموش';
        plainBtn.innerHTML = IC.plainPaste;
        plainBtn.addEventListener('mousedown', function (e) { e.preventDefault(); });
        plainBtn.addEventListener('click', function () {
            plainPaste = !plainPaste;
            plainBtn.classList.toggle('re-on', plainPaste);
        });
        fsWrap.appendChild(plainBtn);
        bar.appendChild(fsWrap);

        wrap.appendChild(bar);

        /* ── بوم تایپ ── */
        var ed = document.createElement('div');
        ed.className = 're-canvas';
        ed.setAttribute('contenteditable', 'true');
        ed.setAttribute('dir', 'auto');
        ed.innerHTML = el.value && el.value.trim() !== '' ? el.value : '<p><br></p>';
        ed.style.minHeight = (options.minHeight || 320) + 'px';
        wrap.appendChild(ed);

        /* ── نوار وضعیت ── */
        var status = document.createElement('div');
        status.className = 're-status';
        status.innerHTML = '<span class="re-count"></span><span class="re-hint">💡 Ctrl+B درشت · Ctrl+K لینک · متن دومراه = جست‌وجوی تصویر</span>';
        wrap.appendChild(status);
        var countEl = status.querySelector('.re-count');

        /* ── نمای کد ── */
        var src = null;
        function toggleSource() {
            if (src === null) {
                src = document.createElement('textarea');
                src.className = 're-source';
                src.setAttribute('dir', 'ltr');
                wrap.insertBefore(src, status);
            }
            if (src.style.display === 'block') {
                ed.innerHTML = src.value;
                src.style.display = 'none';
                ed.style.display = '';
            } else {
                src.value = cleanHtml(ed.innerHTML);
                src.style.display = 'block';
                ed.style.display = 'none';
            }
            sync();
        }

        function focusEd() { ed.focus(); }
        function exec(cmd, val) { focusEd(); document.execCommand(cmd, false, val || null); }

        function execInlineCode() {
            var sel = window.getSelection();
            if (!sel || sel.rangeCount === 0) { return; }
            var node = sel.getRangeAt(0).startContainer;
            var codeEl = node.parentElement ? node.parentElement.closest('code') : null;
            if (codeEl) {
                /* خروج از حالت کد */
                exec('formatBlock', '<p>');
                var t = codeEl.textContent;
                codeEl.parentNode.replaceChild(document.createTextNode(t), codeEl);
            } else {
                focusEd();
                document.execCommand('insertHTML', false, '<code>' + esc(sel.toString()) + '</code>');
            }
        }

        function insertLink() {
            var sel = window.getSelection();
            var text = sel ? sel.toString() : '';
            var url = prompt('آدرس لینک را وارد کنید:', 'https://');
            if (!url) { return; }
            focusEd();
            if (text && text !== '') {
                document.execCommand('insertHTML', false, '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(text) + '</a>');
            } else {
                var label = prompt('متن لینک:', 'اینجا کلیک کنید');
                if (label === null) { label = url; }
                document.execCommand('insertHTML', false, '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(label) + '</a>');
            }
            sync();
        }

        /* ── 🖼 درج تصویر: آپلود / کتابخانه / URL ── */
        function csrfToken() {
            var inp = document.querySelector('input[name="csrf_token"]');
            return inp ? inp.value : '';
        }
        function uploadFile(f, cb) {
            var fd = new FormData();
            fd.append('image', f);
            fd.append('csrf_token', csrfToken());
            fetch('media.php?api=upload_editor', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(cb)
                .catch(function () { cb({ success: false, error: 'خطای ارتباط' }); });
        }
        function insertImage() {
            var modal = document.createElement('div');
            modal.className = 're-modal';
            modal.innerHTML =
                '<div class="re-modal-box">' +
                '<div class="re-modal-head"><b>🖼 درج تصویر</b><button type="button" class="re-btn" data-x>✕</button></div>' +
                '<div class="re-tabs"><button type="button" class="re-tab active" data-t="up">⬆️ آپلود</button><button type="button" class="re-tab" data-t="lib">🗂 کتابخانه رسانه</button><button type="button" class="re-tab" data-t="url">🔗 آدرس تصویر</button></div>' +
                '<div class="re-tabpane" data-p="up"><input type="file" accept="image/*" class="re-file" multiple><div class="re-upmsg">فایل را انتخاب کنید — آپلود خودکار انجام می‌شود</div></div>' +
                '<div class="re-tabpane" data-p="lib" style="display:none"><div class="re-lib-grid"></div><div class="re-upmsg">آخرین تصاویر کتابخانه — کلیک = درج</div></div>' +
                '<div class="re-tabpane" data-p="url" style="display:none"><input type="url" class="re-url" placeholder="https://example.com/photo.jpg" dir="ltr"><div style="margin-top:10px"><button type="button" class="re-btn re-ok" data-url>درج از آدرس</button></div></div>' +
                '</div>';
            document.body.appendChild(modal);
            modal.addEventListener('click', function (e) { if (e.target === modal) { modal.remove(); } });
            modal.querySelector('[data-x]').addEventListener('click', function () { modal.remove(); });

            /* تب‌ها */
            modal.querySelectorAll('.re-tab').forEach(function (t) {
                t.addEventListener('click', function () {
                    modal.querySelectorAll('.re-tab').forEach(function (x) { x.classList.remove('active'); });
                    t.classList.add('active');
                    var k = t.getAttribute('data-t');
                    modal.querySelectorAll('.re-tabpane').forEach(function (p) {
                        p.style.display = p.getAttribute('data-p') === k ? '' : 'none';
                    });
                    if (k === 'lib') { loadLibrary(modal); }
                });
            });

            /* آپلود */
            var fileIn = modal.querySelector('.re-file');
            var upMsg = modal.querySelector('.re-upmsg');
            fileIn.addEventListener('change', function () {
                Array.from(fileIn.files || []).slice(0, 10).forEach(function (f) {
                    if (!/^image\//.test(f.type)) { return; }
                    upMsg.textContent = '⏳ در حال آپلود ' + f.name + '...';
                    uploadFile(f, function (j) {
                        if (j && j.success) {
                            insertAtCursor('<figure class="article-figure"><img src="' + esc(j.data.url) + '" alt="" loading="lazy"><figcaption>توضیح تصویر (کلیک و ویرایش کنید)</figcaption></figure>');
                            upMsg.textContent = '✅ آپلود شد و درج گردید';
                        } else {
                            upMsg.textContent = '❌ ' + ((j && j.error) || 'آپلود ناموفق');
                        }
                    });
                });
            });

            /* URL */
            modal.querySelector('[data-url]').addEventListener('click', function () {
                var u = modal.querySelector('.re-url').value.trim();
                if (!/^https?:\/\//.test(u)) { alert('آدرس تصویر معتبر نیست'); return; }
                insertAtCursor('<figure class="article-figure"><img src="' + esc(u) + '" alt="" loading="lazy"><figcaption></figcaption></figure>');
                modal.remove();
            });
        }

        function loadLibrary(modal) {
            var grid = modal.querySelector('.re-lib-grid');
            grid.innerHTML = '<div class="re-upmsg">⏳ در حال بارگذاری...</div>';
            fetch('media.php?api=recent_images', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    grid.innerHTML = '';
                    if (!j || !j.success || !j.data.length) {
                        grid.innerHTML = '<div class="re-upmsg">کتابخانه خالی است — از تب آپلود شروع کنید.</div>';
                        return;
                    }
                    j.data.forEach(function (m) {
                        var d = document.createElement('button');
                        d.type = 'button';
                        d.className = 're-lib-item';
                        d.innerHTML = '<img src="' + esc(m.url) + '" loading="lazy" alt=""><small>' + esc(m.name || '') + '</small>';
                        d.addEventListener('click', function () {
                            insertAtCursor('<figure class="article-figure"><img src="' + esc(m.url) + '" alt="' + esc(m.alt || '') + '" loading="lazy"><figcaption>' + esc(m.alt || '') + '</figcaption></figure>');
                            modal.remove();
                        });
                        grid.appendChild(d);
                    });
                })
                .catch(function () { grid.innerHTML = '<div class="re-upmsg">❌ خطای بارگذاری کتابخانه</div>'; });
        }

        function insertAtCursor(html) {
            focusEd();
            document.execCommand('insertHTML', false, html);
            sync();
        }

        /* ═══ 🆕 v2.45 (S13) — جستجو و جایگزینی ═══ */
        function findReplace() {
            var modal = document.createElement('div');
            modal.className = 're-modal';
            modal.innerHTML =
                '<div class="re-modal-box re-find-box">' +
                '<div class="re-modal-head"><b>🔍 جستجو و جایگزینی</b><button type="button" class="re-btn" data-x>✕</button></div>' +
                '<div class="re-find-body">' +
                '<input type="text" class="re-find-what" placeholder="عبارت مورد جستجو..." dir="auto">' +
                '<input type="text" class="re-find-with" placeholder="جایگزین با..." dir="auto">' +
                '<label class="re-find-case"><input type="checkbox" class="re-find-ci"> مطابقت بدون بزرگی/کوچکی حروف</label>' +
                '<div class="re-find-actions">' +
                '<button type="button" class="re-btn" data-next>پیدا کردن بعدی</button>' +
                '<button type="button" class="re-btn" data-one>جایگزینی همین</button>' +
                '<button type="button" class="re-btn re-ok" data-all>جایگزینی همه</button>' +
                '</div><div class="re-find-msg"></div></div></div>';
            document.body.appendChild(modal);
            var what = modal.querySelector('.re-find-what');
            var with_ = modal.querySelector('.re-find-with');
            var msg = modal.querySelector('.re-find-msg');
            setTimeout(function () { what.focus(); }, 30);
            modal.addEventListener('click', function (e) { if (e.target === modal) { modal.remove(); } });
            modal.querySelector('[data-x]').addEventListener('click', function () { modal.remove(); });
            /* بستن با Esc */
            var escClose = function (ev) { if (ev.key === 'Escape') { modal.remove(); document.removeEventListener('keydown', escClose); } };
            document.addEventListener('keydown', escClose);

            function flags() { return modal.querySelector('.re-find-ci').checked ? 'gi' : 'g'; }
            function countMatches() {
                var w = what.value;
                if (!w) { return 0; }
                var text = ed.innerText || '';
                var m = text.match(new RegExp(w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), flags().replace('g', '')));
                return m ? m.length : 0;
            }
            function updateMsg() {
                var n = countMatches();
                msg.textContent = n ? ('✅ ' + n.toLocaleString('fa-IR') + ' مورد پیدا شد') : 'موردی پیدا نشد';
            }
            what.addEventListener('input', updateMsg);

            /* پیدا کردن بعدی — انتخاب در بوم */
            modal.querySelector('[data-next]').addEventListener('click', function () {
                var w = what.value;
                if (!w) { return; }
                focusEd();
                /* جستجو در متن بوم با window.find — پشتیبانی خوب کروم/فایرفاکس */
                try { window.find(w, !modal.querySelector('.re-find-ci').checked, false, true); } catch (err) { updateMsg(); }
            });
            /* جایگزینی همین */
            modal.querySelector('[data-one]').addEventListener('click', function () {
                var w = what.value;
                if (!w) { return; }
                var sel = window.getSelection();
                if (sel && sel.toString() === w) {
                    document.execCommand('insertText', false, with_.value);
                } else {
                    modal.querySelector('[data-next]').click();
                }
                sync(); updateMsg();
            });
            /* جایگزینی همه — روی متن ساده */
            modal.querySelector('[data-all]').addEventListener('click', function () {
                var w = what.value;
                if (!w) { msg.textContent = 'عبارت جستجو خالی است'; return; }
                var text = ed.innerText || '';
                var rx = new RegExp(w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), flags());
                var n = countMatches();
                if (!n) { msg.textContent = 'موردی پیدا نشد'; return; }
                /* حفظ ساختار: جایگزینی فقط داخل گره‌های متنی */
                var walker = document.createTreeWalker(ed, NodeFilter.SHOW_TEXT);
                var nodes = [];
                while (walker.nextNode()) { nodes.push(walker.currentNode); }
                nodes.forEach(function (nd) {
                    if (rx.test(nd.nodeValue)) {
                        nd.nodeValue = nd.nodeValue.replace(new RegExp(w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), flags()), with_.value);
                    }
                });
                sync();
                msg.textContent = '✅ ' + n.toLocaleString('fa-IR') + ' مورد جایگزین شد';
            });
        }

        /* ═══ 🆕 v2.45 (S13) — نمادهای ویژه ═══ */
        function specialChars() {
            var chars = ['©', '®', '™', '°', '±', '×', '÷', '≈', '≠', '≤', '≥', '→', '←', '↔', '↑', '↓', '«', '»', '…', '—', '–', '؛', '٫', '٬', '€', '£', '¥', '√', '∞', '∑', 'π', 'µ', '™', '✓', '✗', '★', '☆', '♦', '⚠', '℗'];
            var modal = document.createElement('div');
            modal.className = 're-modal';
            modal.innerHTML = '<div class="re-modal-box re-chars-box">' +
                '<div class="re-modal-head"><b>✨ نمادهای ویژه</b><button type="button" class="re-btn" data-x>✕</button></div>' +
                '<div class="re-chars-grid">' + chars.map(function (c) { return '<button type="button" class="re-char">' + c + '</button>'; }).join('') + '</div></div>';
            document.body.appendChild(modal);
            modal.addEventListener('click', function (e) { if (e.target === modal) { modal.remove(); } });
            modal.querySelector('[data-x]').addEventListener('click', function () { modal.remove(); });
            var escClose2 = function (ev) { if (ev.key === 'Escape') { modal.remove(); document.removeEventListener('keydown', escClose2); } };
            document.addEventListener('keydown', escClose2);
            modal.querySelectorAll('.re-char').forEach(function (b) {
                b.addEventListener('click', function () {
                    insertAtCursor(esc(b.textContent));
                    modal.remove();
                });
            });
        }

        /* ═══ 🆕 v2.45 (S13) — حالت تمام‌صفحه (تمرکز) ═══ */
        function toggleFullscreen() {
            var on = wrap.classList.toggle('re-fullscreen');
            if (on) {
                document.body.style.overflow = 'hidden';
                ed.style.minHeight = 'calc(100vh - 210px)';
                focusEd();
            } else {
                document.body.style.overflow = '';
                ed.style.minHeight = (options.minHeight || 320) + 'px';
            }
        }
        /* خروج با Esc */
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && wrap.classList.contains('re-fullscreen')) { toggleFullscreen(); }
        });

        function insertTable() {
            var rc = prompt('ابعاد جدول (سطر × ستون) — مثلاً 3x4:', '3x4');
            if (!rc) { return; }
            var m = rc.match(/(\d+)\s*[x×,]\s*(\d+)/);
            if (!m) { alert('قالب درست: 3x4'); return; }
            var rows = Math.max(1, Math.min(30, +m[1]));
            var cols = Math.max(1, Math.min(8, +m[2]));
            var html = '<table class="re-table"><thead><tr>';
            for (var c = 0; c < cols; c++) { html += '<th>ستون ' + (c + 1) + '</th>'; }
            html += '</tr></thead><tbody>';
            for (var r = 1; r < rows; r++) {
                html += '<tr>';
                for (var c2 = 0; c2 < cols; c2++) { html += '<td>—</td>'; }
                html += '</tr>';
            }
            html += '</tbody></table><p><br></p>';
            insertAtCursor(html);
        }

        /* ── پاک‌سازی HTML خروجی (تگ‌های خالی execCommand و spanهای بی‌صفت) ── */
        function cleanHtml(html) {
            return html
                .replace(/<span(?![^>]*\b(?:style|class)=)[^>]*>/gi, '')
                .replace(/<\/span>/gi, '')
                .replace(/<(p|div|h2|h3|li)[^>]*>\s*(<br\s*\/?>)?\s*<\/\1>/gi, '')
                .replace(/ style="([^"]*)"/gi, function (m, s) {
                    /* فقط استایل‌های مجاز حفظ شوند */
                    var keep = (s.match(/(?:text-align|color|background-color|width|height)[^;]*/gi) || []).join(';');
                    return keep ? ' style="' + keep + '"' : '';
                });
        }

        /* ── همگام‌سازی با textarea + شمارش + زمان مطالعه ── */
        var syncTimer = null;
        function sync() {
            clearTimeout(syncTimer);
            syncTimer = setTimeout(function () {
                var html = src && src.style.display === 'block' ? src.value : cleanHtml(ed.innerHTML);
                el.value = html;
                var text = ed.innerText || '';
                if (countEl) {
                    var words = text.trim() ? text.trim().split(/\s+/).length : 0;
                    /* 🆕 v2.45 (S13): زمان تقریبی مطالعه (۲۲۰ کلمه در دقیقه — فارسی) */
                    var mins = Math.max(1, Math.round(words / 220));
                    countEl.textContent = '📝 ' + words.toLocaleString('fa-IR') + ' کلمه · ' +
                        text.length.toLocaleString('fa-IR') + ' کاراکتر · ⏱ حدود ' +
                        mins.toLocaleString('fa-IR') + ' دقیقه مطالعه';
                }
                if (options.onChange) { options.onChange(html); }
            }, 120);
        }

        ed.addEventListener('input', sync);
        ed.addEventListener('blur', sync);
        if (src) { src.addEventListener('input', sync); }

        /* ── میانبرها ── */
        ed.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                insertLink();
            }
            /* 🆕 v2.45 (S13): Ctrl+F = جستجو و جایگزینی داخل ویرایشگر */
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
                e.preventDefault();
                findReplace();
            }
            if (e.key === 'Enter') {
                /* داخل blockquote/li/h* همیشه p جدید — رفتار طبیعی */
                if (e.shiftKey) { return; /* Shift+Enter = br پیش‌فرض */ }
            }
        });

        /* ── درج با کشیدن‌ورها کردن تصویر ── */
        wrap.addEventListener('dragover', function (e) { e.preventDefault(); wrap.classList.add('re-drag'); });
        wrap.addEventListener('dragleave', function () { wrap.classList.remove('re-drag'); });
        wrap.addEventListener('drop', function (e) {
            e.preventDefault();
            wrap.classList.remove('re-drag');
            var files = e.dataTransfer && e.dataTransfer.files ? Array.from(e.dataTransfer.files) : [];
            files.filter(function (f) { return /^image\//.test(f.type); }).slice(0, 6).forEach(function (f) {
                uploadFile(f, function (j) {
                    if (j && j.success) {
                        insertAtCursor('<figure class="article-figure"><img src="' + esc(j.data.url) + '" alt="" loading="lazy"><figcaption></figcaption></figure>');
                    }
                });
            });
        });

        /* ── چسباندن: متن ساده از Word/سایت‌ها (ضد تزریق استایل) ──
           🆕 v2.45 (S13): دکمه «متن خام» روشن = همه قالب حذف و فقط متن می‌چسبد */
        var plainPaste = false;
        ed.addEventListener('paste', function (e) {
            var html = e.clipboardData && e.clipboardData.getData ? e.clipboardData.getData('text/html') : '';
            if (plainPaste) {
                e.preventDefault();
                var txt = e.clipboardData ? e.clipboardData.getData('text/plain') : '';
                document.execCommand('insertText', false, txt);
                sync();
                return;
            }
            if (!html) { return; }
            e.preventDefault();
            var clean = cleanHtml(html)
                .replace(/<(script|style|iframe|object|embed)[^>]*>[\s\S]*?<\/\1>/gi, '')
                .replace(/<\/?(o:p|v:|w:)[^>]*>/gi, '');
            document.execCommand('insertHTML', false, clean);
            sync();
        });

        /* درج در DOM بعد از textarea */
        el.parentNode.insertBefore(wrap, el.nextSibling);
        sync();

        return {
            element: wrap,
            getHtml: function () { return cleanHtml(ed.innerHTML); },
            setHtml: function (h) { ed.innerHTML = h || '<p><br></p>'; sync(); },
        };
    };

    /* 🚀 خودکار: همه textarea با کلاس .rich-editor */
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('textarea.rich-editor:not([data-rich-editor])').forEach(function (t) {
            RichEditor.create(t);
        });
    });

    window.RichEditor = RichEditor;
})();
