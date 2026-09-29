<?php
/**
 * 🎨 انتخابگر آیکون مشترک (v2.38 — آیکون‌پک برای منو و قالب‌ساز)
 * =====================================================================
 * کامپوننت قابل‌استفاده‌ی مجدد برای همه‌ی صفحات پنل. هر صفحه یک‌بار
 * sahand_icon_picker_assets() را در انتهای محتوا (قبل از footer) صدا
 * می‌زند و سپس با JS زیر آیکون انتخاب می‌کند:
 *
 *   IconPicker.open({
 *       current: 'svg:remix-icons/home.svg',   // مقدار فعلی (یا ایموجی)
 *       onPick:  function (value) { ... }      // value = 'svg:pack/file.svg' | '🏠' | ''
 *   });
 *
 * ساختار مودال: دو زبانه — «😀 ایموجی» (پالت سریع + ورودی سفارشی) و
 * «📦 پک آیکون SVG» (فهرست پک‌های نصب‌شده + جستجو + دسته + صفحه‌بندی).
 * مقدار ذخیره‌شده با پیشوند svg: به‌صورت <img> رندر می‌شود (سازگار با
 * داده‌های ایموجی موجود — هیچ مهاجرتی لازم نیست).
 *
 * @package SahandBrandMaker
 * @since   2.38.0
 */

if (!defined('SAHAND_INIT')) {
    http_response_code(403);
    exit;
}

/**
 * 🖨 خروجی دارایی‌های انتخابگر آیکون (یک‌بار در هر صفحه)
 *
 * @param array $opts ['emoji_palette' => string[]] — پالت ایموجی سفارشی صفحه
 */
function sahand_icon_picker_assets(array $opts = []): void
{
    if (!empty($GLOBALS['SAHAND_ICON_PICKER_RENDERED'])) {
        return;
    }
    $GLOBALS['SAHAND_ICON_PICKER_RENDERED'] = true;

    $palette = isset($opts['emoji_palette']) && is_array($opts['emoji_palette']) && $opts['emoji_palette']
        ? array_values($opts['emoji_palette'])
        : ['🏠', '🔧', '🚨', '📰', '🏢', '📞', '📋', '🔒', '📜', '🗺️', '❓', '💬', '📍', '⭐', '⚡', '🛡️',
           '💰', '🏷️', '🧰', '🧊', '🌀', '❄️', '📺', '♨️', '📻', '🔥', '🍽️', '🧺', '💡', '🔌', '🔋', '⚙️',
           '🚀', '✅', '⚠️', '🎁', '🎓', '📈', '📊', '🕐', '📅', '✉️', '📌', '🔗', '🌐', '📱', '💻', '🛠️'];
    $assetBase = asset_url('assets/icons/');
    ?>
<!-- 🎨 v2.38 — انتخابگر آیکون مشترک (ایموجی + پک آیکون SVG) -->
<div class="modal-overlay" id="sahand-icon-picker" style="display:none">
    <div class="modal-box" style="max-width:680px">
        <div class="modal-header">
            <h3>🎨 انتخاب آیکون</h3>
            <button type="button" class="btn btn-outline btn-sm" id="sip-close">✕</button>
        </div>
        <div class="modal-body" style="padding:16px 20px">
            <div class="sip-tabs">
                <button type="button" class="sip-tab active" data-tab="emoji">😀 ایموجی</button>
                <button type="button" class="sip-tab" data-tab="pack">📦 پک آیکون SVG</button>
            </div>

            <!-- زبانه ایموجی -->
            <div class="sip-pane" id="sip-pane-emoji">
                <div id="sip-emoji-grid" class="sip-grid emoji"></div>
                <div class="form-group" style="margin:12px 0 0">
                    <label>آیکون سفارشی (ایموجی — خالی برای حذف)</label>
                    <div style="display:flex;gap:8px">
                        <input type="text" id="sip-emoji-custom" class="form-control" style="direction:ltr;font-size:18px" maxlength="8" placeholder="🏠">
                        <button type="button" class="btn btn-primary" id="sip-emoji-apply">تأیید</button>
                    </div>
                </div>
            </div>

            <!-- زبانه پک آیکون -->
            <div class="sip-pane" id="sip-pane-pack" style="display:none">
                <div id="sip-pack-chips" class="sip-pack-chips"></div>
                <div style="display:flex;gap:8px;margin:10px 0;flex-wrap:wrap">
                    <input type="text" id="sip-pack-q" class="form-control" style="flex:1;min-width:180px" placeholder="🔍 جستجوی نام آیکون (فارسی یا انگلیسی)...">
                    <select id="sip-pack-cat" class="form-control" style="max-width:170px"><option value="">همه دسته‌ها</option></select>
                </div>
                <div id="sip-pack-grid" class="sip-grid svg"></div>
                <div id="sip-pack-pager" class="sip-pager"></div>
                <div class="hint" style="margin-top:8px">💡 آیکون‌های SVG روی سایت برند با کیفیت کامل در هر اندازه رندر می‌شوند. پک‌های بیشتر را از صفحه «آیکون‌ها» نصب کنید.</div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="sip-remove">🗑 بدون آیکون</button>
            <button type="button" class="btn btn-outline" id="sip-cancel">انصراف</button>
        </div>
    </div>
</div>
<style>
#sahand-icon-picker.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.58);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;z-index:1100;padding:16px}
#sahand-icon-picker .modal-box{background:var(--card-bg,#fff);border-radius:15px;max-width:680px;width:100%;max-height:90vh;overflow:auto;box-shadow:0 22px 60px rgba(0,0,0,.28)}
#sahand-icon-picker .modal-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
#sahand-icon-picker .modal-header h3{margin:0;font-size:15.5px}
#sahand-icon-picker .modal-footer{padding:12px 20px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end}
.sip-tabs{display:flex;gap:8px;margin-bottom:12px}
.sip-tab{border:1px solid var(--border);background:var(--bg-secondary,#eef2f7);border-radius:22px;padding:7px 18px;font-family:inherit;font-size:12.5px;font-weight:700;cursor:pointer;transition:.15s}
.sip-tab.active{background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;border-color:transparent;box-shadow:0 3px 10px rgba(124,58,237,.3)}
.sip-grid{display:grid;gap:6px;max-height:330px;overflow:auto;border:1px dashed var(--border);border-radius:11px;padding:8px;background:rgba(0,0,0,.02)}
.sip-grid.emoji{grid-template-columns:repeat(8,1fr)}
.sip-grid.svg{grid-template-columns:repeat(7,1fr)}
.sip-cell{border:1px solid var(--border);border-radius:9px;background:var(--card,#fff);cursor:pointer;padding:8px 4px;text-align:center;transition:.13s;display:flex;align-items:center;justify-content:center;min-height:44px}
.sip-cell:hover{border-color:var(--primary);transform:translateY(-2px);box-shadow:0 5px 14px rgba(37,99,235,.14)}
.sip-cell.selected{border-color:var(--primary);background:rgba(124,58,237,.08);box-shadow:0 0 0 2px rgba(124,58,237,.25)}
.sip-cell .em{font-size:20px;line-height:1}
.sip-cell img{width:22px;height:22px;object-fit:contain;display:block}
.sip-cell .lbl{display:block;font-size:9.5px;color:var(--text-light,#64748b);margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sip-pack-chips{display:flex;gap:7px;flex-wrap:wrap}
.sip-chip{border:1px solid var(--border);background:var(--bg-secondary,#eef2f7);border-radius:20px;padding:5px 13px;font-family:inherit;font-size:11.5px;font-weight:700;cursor:pointer;transition:.13s}
.sip-chip.active{background:linear-gradient(90deg,#0ea5e9,#2563eb);color:#fff;border-color:transparent}
.sip-chip .cnt{opacity:.75;font-size:10px;margin-inline-start:4px}
.sip-pager{display:flex;gap:8px;align-items:center;justify-content:center;margin-top:10px;font-size:12px}
.sip-pager button{border:1px solid var(--border);background:var(--card,#fff);border-radius:8px;padding:4px 13px;font-family:inherit;font-size:12px;cursor:pointer}
.sip-pager button:disabled{opacity:.4;cursor:default}
.sip-loading{padding:26px;text-align:center;color:var(--text-light,#64748b);font-size:12.5px;grid-column:1/-1}
</style>
<script>
/* ============================================================
 * 🎨 v2.38 — IconPicker: انتخابگر مشترک آیکون (ایموجی + پک SVG)
 * API: IconPicker.open({ current, onPick })
 *      IconPicker.iconHtml(value, px) → رندر ایموجی یا <img> SVG
 * ============================================================ */
var IconPicker = (function () {
    var ASSET_BASE = <?= json_encode($assetBase) ?>;
    var EMOJI_PALETTE = <?= json_encode($palette, JSON_UNESCAPED_UNICODE) ?>;
    var csrfEl = document.querySelector('input[name="csrf_token"]');
    var csrfVal = csrfEl ? csrfEl.value : '';
    var state = { tab: 'emoji', pack: '', q: '', cat: '', page: 1, current: '', cb: null, packsLoaded: false, searchTimer: null };

    function el(id) { return document.getElementById(id); }
    function faDig(n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }

    /* 🖼 رندر مقدار آیکون (svg:pack/file.svg → img — بقیه ایموجی/متن) */
    function iconHtml(v, px) {
        px = px || 18;
        if (!v) { return ''; }
        if (String(v).indexOf('svg:') === 0) {
            return '<img src="' + ASSET_BASE + String(v).slice(4) + '" alt="" loading="lazy" style="width:' + px + 'px;height:' + px + 'px;object-fit:contain;display:inline-block;vertical-align:middle">';
        }
        return v;
    }

    function post(params) {
        var body = new URLSearchParams();
        Object.keys(params || {}).forEach(function (k) { body.append(k, params[k]); });
        if (csrfEl) { body.append('csrf_token', csrfVal); }
        return fetch('icon-picker.ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfVal, 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }

    function open(opts) {
        state.cb = (opts && typeof opts.onPick === 'function') ? opts.onPick : null;
        state.current = (opts && opts.current) || '';
        el('sahand-icon-picker').style.display = 'flex';
        switchTab(String(state.current).indexOf('svg:') === 0 ? 'pack' : 'emoji');
        el('sip-emoji-custom').value = /^svg:/.test(state.current) ? '' : (state.current || '');
        markSelected();
    }
    function close() { el('sahand-icon-picker').style.display = 'none'; state.cb = null; }
    function pick(v) {
        if (state.cb) { state.cb(v); }
        close();
    }
    function markSelected() {
        Array.prototype.forEach.call(document.querySelectorAll('#sahand-icon-picker .sip-cell'), function (c) {
            c.classList.toggle('selected', c.getAttribute('data-v') === state.current);
        });
    }

    function switchTab(tab) {
        state.tab = tab;
        Array.prototype.forEach.call(document.querySelectorAll('.sip-tab'), function (t) {
            t.classList.toggle('active', t.getAttribute('data-tab') === tab);
        });
        el('sip-pane-emoji').style.display = tab === 'emoji' ? '' : 'none';
        el('sip-pane-pack').style.display = tab === 'pack' ? '' : 'none';
        if (tab === 'pack' && !state.packsLoaded) { loadPacks(); }
    }

    function renderEmojiGrid() {
        var g = el('sip-emoji-grid');
        g.innerHTML = EMOJI_PALETTE.map(function (ic) {
            return '<button type="button" class="sip-cell" data-v="' + ic + '" title="' + ic + '"><span class="em">' + ic + '</span></button>';
        }).join('');
        Array.prototype.forEach.call(g.querySelectorAll('.sip-cell'), function (c) {
            c.addEventListener('click', function () { pick(c.getAttribute('data-v')); });
        });
        markSelected();
    }

    function loadPacks() {
        var chips = el('sip-pack-chips');
        chips.innerHTML = '<span class="sip-loading">⏳ در حال بارگذاری پک‌های آیکون...</span>';
        post({ action: 'packs' }).then(function (res) {
            state.packsLoaded = true;
            if (!res.success || !res.packs || !res.packs.length) {
                chips.innerHTML = '<span class="sip-loading">📦 هیچ پک آیکونی نصب نشده — از صفحه «آیکون‌ها» یک پک نصب کنید.</span>';
                return;
            }
            chips.innerHTML = res.packs.map(function (p) {
                return '<button type="button" class="sip-chip" data-pack="' + p.slug + '">' + p.name_fa + '<span class="cnt">' + faDig(p.count) + '</span></button>';
            }).join('');
            Array.prototype.forEach.call(chips.querySelectorAll('.sip-chip'), function (ch) {
                ch.addEventListener('click', function () {
                    state.pack = ch.getAttribute('data-pack');
                    state.page = 1; state.cat = ''; state.q = '';
                    el('sip-pack-q').value = ''; el('sip-pack-cat').innerHTML = '<option value="">همه دسته‌ها</option>';
                    loadBrowse();
                });
            });
            /* اولین پک به‌طور پیش‌فرض */
            state.pack = res.packs[0].slug;
            loadBrowse();
        }).catch(function () {
            chips.innerHTML = '<span class="sip-loading">❌ خطای بارگذاری پک‌ها — دوباره تلاش کنید.</span>';
        });
    }

    function loadBrowse() {
        Array.prototype.forEach.call(el('sip-pack-chips').querySelectorAll('.sip-chip'), function (ch) {
            ch.classList.toggle('active', ch.getAttribute('data-pack') === state.pack);
        });
        var grid = el('sip-pack-grid');
        grid.innerHTML = '<span class="sip-loading">⏳ در حال بارگذاری آیکون‌ها...</span>';
        el('sip-pack-pager').innerHTML = '';
        post({ action: 'browse', pack: state.pack, q: state.q, cat: state.cat, page: state.page }).then(function (res) {
            if (!res.success) {
                grid.innerHTML = '<span class="sip-loading">❌ ' + (res.error || 'خطا') + '</span>';
                return;
            }
            grid.innerHTML = (res.icons || []).map(function (ic) {
                return '<button type="button" class="sip-cell" data-v="' + ic.v + '" title="' + (ic.label || '') + '">'
                    + '<span style="display:flex;flex-direction:column;align-items:center;gap:3px;width:100%"><img src="' + ic.url + '" alt="" loading="lazy"><span class="lbl">' + (ic.label || '') + '</span></span></button>';
            }).join('') || '<span class="sip-loading">🔍 چیزی یافت نشد.</span>';
            Array.prototype.forEach.call(grid.querySelectorAll('.sip-cell'), function (c) {
                c.addEventListener('click', function () { pick(c.getAttribute('data-v')); });
            });
            markSelected();
            /* دسته‌ها */
            var catSel = el('sip-pack-cat');
            var cats = res.cats || {};
            var catHtml = '<option value="">همه دسته‌ها</option>';
            Object.keys(cats).forEach(function (k) { catHtml += '<option value="' + k + '"' + (k === state.cat ? ' selected' : '') + '>' + k + ' (' + faDig(cats[k]) + ')</option>'; });
            catSel.innerHTML = catHtml;
            /* صفحه‌بندی */
            var pg = el('sip-pack-pager');
            pg.innerHTML = '<button type="button" id="sip-pg-prev" ' + (res.page <= 1 ? 'disabled' : '') + '>→ قبلی</button>'
                + '<span>صفحه ' + faDig(res.page) + ' از ' + faDig(res.total_pages) + ' — ' + faDig(res.total) + ' آیکون</span>'
                + '<button type="button" id="sip-pg-next" ' + (res.page >= res.total_pages ? 'disabled' : '') + '>بعدی ←</button>';
            var prev = el('sip-pg-prev'), next = el('sip-pg-next');
            if (prev) { prev.addEventListener('click', function () { state.page = Math.max(1, state.page - 1); loadBrowse(); }); }
            if (next) { next.addEventListener('click', function () { state.page = Math.min(res.total_pages, state.page + 1); loadBrowse(); }); }
        }).catch(function () {
            grid.innerHTML = '<span class="sip-loading">❌ خطای ارتباط با سرور.</span>';
        });
    }

    /* 🔌 سیم‌کشی رویدادها (یک‌بار) */
    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.sip-tab'), function (t) {
            t.addEventListener('click', function () { switchTab(t.getAttribute('data-tab')); });
        });
        el('sip-close').addEventListener('click', close);
        el('sip-cancel').addEventListener('click', close);
        el('sip-remove').addEventListener('click', function () { pick(''); });
        el('sip-emoji-apply').addEventListener('click', function () { pick(el('sip-emoji-custom').value.trim()); });
        el('sahand-icon-picker').addEventListener('click', function (e) { if (e.target === this) { close(); } });
        el('sip-pack-q').addEventListener('input', function () {
            clearTimeout(state.searchTimer);
            state.searchTimer = setTimeout(function () { state.q = el('sip-pack-q').value.trim(); state.page = 1; if (state.pack) { loadBrowse(); } }, 420);
        });
        el('sip-pack-cat').addEventListener('change', function () { state.cat = el('sip-pack-cat').value; state.page = 1; if (state.pack) { loadBrowse(); } });
        renderEmojiGrid();
    });

    return { open: open, iconHtml: iconHtml, close: close };
})();
</script>
<?php
}
